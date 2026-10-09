<?php
// POS desde el equipo de caja (docs/specs/pos.md A6, A7 y §9.4). Autentica con
// el token del equipo (X-POS-EQUIPO) y, para todo lo que hace un empleado, con
// su sesion (X-POS-SESION). Nunca con la sesion de un usuario de app.*.
//
//   GET    /api/pos/estado   -> equipo, caja, empresa; con X-POS-SESION valida,
//                               tambien el empleado. Siempre el turno abierto de
//                               la caja. Es lo primero que pide pos.* al cargar.
//   POST   /api/pos/sesion   -> {pin} -> {token, empleado, caja, turno_caja}
//   DELETE /api/pos/sesion   -> cierra la sesion (bloqueo de pantalla o salir)
//   GET    /api/pos/catalogo -> productos con su precio final, categorias (C1-C4)
//   POST   /api/pos/turno    -> {fondo_centavos} abre el turno del empleado (K2, K3)
//   POST   /api/pos/ventas   -> cobra y emite (§9.5); ver src/Pos/PosVenta.php
//   GET    /api/pos/ventas/{id}/recibo?ancho=80 -> datos del recibo (reimprimir)
//   POST   /api/pos/pendientes/reenviar -> reenvia las ventas sin respuesta DGII (F7)
//   POST   /api/pos/autorizar -> {pin, accion, turno_id} PIN de supervisor -> permiso (S1)
//   POST   /api/pos/turno/cerrar -> {turno_id, conteo, permiso?} cierre a ciegas (K6-K8)
//   POST   /api/pos/turno/nota -> {turno_id, nota} nota del cierre, una vez
//   GET    /api/pos/ventas  -> ventas cobradas del turno abierto de la caja (K9)
//   POST   /api/pos/eventos -> {tipo: cancelada|quitada, monto_centavos, lineas} (V4)
//   POST   /api/pos/clientes/rnc -> {rnc} cliente de credito fiscal; si no existe se crea (F2)
//
// Errores: {status:false, error, codigo}. Codigos que cambian de pantalla:
//   EQUIPO_NO_HABILITADO -> habilitar el equipo     SESION_REQUERIDA -> PIN
//   EQUIPO_BLOQUEADO     -> cuenta regresiva (bloqueo_segundos)
//   PIN_INCORRECTO       -> intentos_restantes      POS_INACTIVO / CAJA_INACTIVA

require_once __DIR__ . '/../Pos/PosError.php';
require_once __DIR__ . '/../Pos/PosPin.php';
require_once __DIR__ . '/../Pos/PosAuth.php';
require_once __DIR__ . '/../Models/posModel.php';
require_once __DIR__ . '/../Models/posMasterModel.php';
require_once __DIR__ . '/../Models/unidadMedidaModel.php';
require_once __DIR__ . '/../Pos/PosPrecio.php';
require_once __DIR__ . '/../Pos/PosVenta.php';
require_once __DIR__ . '/../Pos/PosCierre.php';
require_once __DIR__ . '/../Pos/PosAutorizacion.php';
require_once __DIR__ . '/../Pos/PosCliente.php';

/** Una linea de auditoria del POS. Nunca con el PIN ni tokens. */
function posAudit(string $action, $entityId, ?array $new, string $descripcion, bool $ok = true): void
{
    AuditLogger::log([
        'module' => 'pos', 'action' => $action,
        'entity_type' => 'pos_equipo', 'entity_id' => $entityId,
        'new_values' => $new, 'description' => $descripcion, 'success' => $ok,
    ]);
}

function posResponder(int $http, array $data): void
{
    http_response_code($http);
    echo json_encode(['status' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
}

/** Caja del equipo; si se desactivo despues de habilitarlo, no se vende en ella. */
function posCajaDelEquipo(posModel $pos, array $equipo): array
{
    $caja = $pos->cajaPorId($equipo['caja_id']);
    if ($caja === null || !$caja['activa']) {
        throw new PosError('La caja de este equipo está desactivada. Pide a un administrador que la active o que habilite el equipo en otra caja.', 403, 'CAJA_INACTIVA');
    }
    return $caja;
}

/**
 * Catalogo de la caja (docs/specs/pos.md C1-C4). El piloto tiene menos de 500
 * productos: va completo y el POS busca en memoria.
 *
 * - precio_centavos: precio final con ITBIS (PosPrecio, C2). Siempre precio 1.
 * - Una categoria inactiva no sale como chip; sus productos quedan en "Todos"
 *   (category_id null). Solo salen las categorias con algun producto.
 * - stock null = servicio (sin semaforo, C4).
 * - decimales: si la unidad admite cantidades con decimales (unidades_medida).
 */
function posCatalogo(posModel $pos): array
{
    $categorias = $pos->catalogoCategorias();
    $unidades = new unidadMedidaModel();
    $productos = [];
    $porCategoria = [];
    foreach ($pos->catalogoProductos() as $p) {
        $indicador = (int) $p['indicador_facturacion'];
        try {
            $centavos = PosPrecio::finalCentavos((string) $p['precio'], $indicador);
        } catch (InvalidArgumentException $e) {
            // Un producto mal cargado no tumba la caja: se omite y queda en el log.
            error_log('[pos] catalogo: producto ' . $p['id'] . ' omitido: ' . $e->getMessage());
            continue;
        }
        $categoria = $p['category_id'] !== null && isset($categorias[(int) $p['category_id']]) ? (int) $p['category_id'] : null;
        if ($categoria !== null) {
            $porCategoria[$categoria] = ($porCategoria[$categoria] ?? 0) + 1;
        }
        $productos[] = [
            'id' => (int) $p['id'],
            'nombre' => $p['nombre'],
            'sku' => $p['sku'],
            'category_id' => $categoria,
            'precio_centavos' => $centavos,
            'tasa' => PosPrecio::tasa($indicador),
            'indicador_facturacion' => $indicador,
            'stock' => $p['stock'] !== null ? (float) $p['stock'] : null,
            'stock_minimo' => $p['stock_minimo'] !== null ? (float) $p['stock_minimo'] : null,
            'unidad_medida' => (string) $p['unidad_medida'],
            'decimales' => $unidades->permiteDecimales($p['unidad_medida']),
        ];
    }
    $chips = [];
    foreach ($categorias as $id => $nombre) {
        if (isset($porCategoria[$id])) {
            $chips[] = ['id' => $id, 'nombre' => $nombre, 'productos' => $porCategoria[$id]];
        }
    }
    return ['productos' => $productos, 'categorias' => $chips, 'generado_at' => date('c')];
}

try {
    $equipo = PosAuth::requerirEquipo();
    $pos = new posModel();
    $master = new posMasterModel();

    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $resto = trim(substr($path, strpos($path, '/api/pos') + strlen('/api/pos')), '/');
    $metodo = $_SERVER['REQUEST_METHOD'];

    // Reimprimir el recibo de una venta del POS (K9, P5).
    if ($metodo === 'GET' && preg_match('#^ventas/(\d+)/recibo$#', $resto, $m) === 1) {
        PosAuth::requerirSesion($pos, $equipo);
        if ($pos->ventaPos((int) $m[1]) === null) {
            throw new PosError('No encontramos esa venta del POS.', 404, 'VENTA_NO_EXISTE');
        }
        $recibo = PosVenta::recibo((int) $m[1], PosVenta::ancho($_GET['ancho'] ?? null));
        if ($recibo === null) {
            throw new PosError('No se pudo preparar el recibo. Inténtalo de nuevo.', 500, 'RECIBO_FALLIDO');
        }
        posResponder(200, ['recibo' => $recibo]);
        return;
    }

    switch ("{$metodo} {$resto}") {

        case 'GET estado':
            $tenant = TenantResolver::current();
            $empleado = null;
            if (trim((string) ($_SERVER['HTTP_X_POS_SESION'] ?? '')) !== '') {
                try {
                    $empleado = PosAuth::requerirSesion($pos, $equipo)['empleado'];
                } catch (PosError $e) {
                    // Sesion vencida o cerrada: el estado responde igual, sin empleado.
                }
            }
            $caja = $pos->cajaPorId($equipo['caja_id']);
            posResponder(200, [
                'empresa' => ['nombre' => $tenant['nombre'] ?? null, 'rnc' => $tenant['rnc'] ?? null],
                'equipo' => [
                    'id' => $equipo['id'], 'nombre' => $equipo['nombre'],
                    'bloqueado' => $equipo['bloqueado'], 'bloqueo_segundos' => $equipo['bloqueo_segundos'],
                ],
                'caja' => $caja,
                'empleado' => $empleado,
                'turno_caja' => $caja !== null ? $pos->turnoAbiertoDeCaja($caja['id']) : null,
            ]);
            break;

        case 'POST sesion':
            $caja = posCajaDelEquipo($pos, $equipo);
            if ($equipo['bloqueado']) {
                throw new PosError('Demasiados PIN incorrectos. Espera unos minutos.', 423, 'EQUIPO_BLOQUEADO',
                    ['bloqueo_segundos' => $equipo['bloqueo_segundos']]);
            }
            $body = InputSanitizer::jsonInput() ?? [];
            $pin = is_array($body) ? ($body['pin'] ?? null) : null;
            if (is_int($pin)) {
                // Un PIN que empieza con 0 no puede llegar como numero JSON sin perderlo.
                throw new PosError('Manda el PIN como texto.', 422, 'PIN_FORMATO');
            }
            if (!PosPin::formatoValido($pin)) {
                throw new PosError('El PIN tiene ' . PosPin::DIGITOS . ' dígitos.', 422, 'PIN_FORMATO');
            }

            $empleado = $pos->empleadoPorPin(PosPin::hmac($pin, (int) TenantResolver::current()['id']));
            if ($empleado === null) {
                $fallo = $master->registrarFalloPin($equipo['id']);
                if ($fallo['bloqueado']) {
                    $minutos = (int) ceil($fallo['bloqueo_segundos'] / 60);
                    posAudit('POS_EQUIPO_BLOQUEADO', $equipo['id'], ['caja_id' => $caja['id'], 'minutos' => $minutos],
                        "Equipo bloqueado {$minutos} minutos por " . posMasterModel::MAX_INTENTOS . ' PIN incorrectos seguidos (el bloqueo se duplica en cada repetición).', false);
                    throw new PosError('Demasiados PIN incorrectos. El equipo queda bloqueado unos minutos.', 423, 'EQUIPO_BLOQUEADO',
                        ['bloqueo_segundos' => $fallo['bloqueo_segundos']]);
                }
                posAudit('POS_PIN_FALLIDO', $equipo['id'], ['caja_id' => $caja['id'], 'intentos_restantes' => $fallo['intentos_restantes']],
                    'PIN incorrecto en el POS.', false);
                throw new PosError('PIN incorrecto.', 401, 'PIN_INCORRECTO', ['intentos_restantes' => $fallo['intentos_restantes']]);
            }

            $master->reiniciarIntentos($equipo['id']);
            $token = $pos->abrirSesion($empleado['id'], $equipo['id']);
            RequestContext::set('username', 'POS · ' . $empleado['nombre']);
            posAudit('POS_SESION_ABIERTA', $equipo['id'], ['empleado_id' => $empleado['id'], 'empleado' => $empleado['nombre'], 'caja_id' => $caja['id']],
                'Empleado entró al POS con su PIN.');
            posResponder(200, [
                'token' => $token,
                'empleado' => $empleado,
                'caja' => $caja,
                'turno_caja' => $pos->turnoAbiertoDeCaja($caja['id']),
            ]);
            break;

        case 'DELETE sesion':
            $sesion = PosAuth::requerirSesion($pos, $equipo);
            $pos->cerrarSesion($sesion['sesion_id']);
            posAudit('POS_SESION_CERRADA', $equipo['id'], ['empleado_id' => $sesion['empleado']['id'], 'empleado' => $sesion['empleado']['nombre']],
                'Sesión del POS cerrada (bloqueo de pantalla o salida).');
            posResponder(200, ['cerrada' => true]);
            break;

        case 'POST turno':
            // Apertura del turno con su fondo inicial (K2). Solo el empleado de
            // la sesion, en la caja de este equipo.
            $sesion = PosAuth::requerirSesion($pos, $equipo);
            $caja = posCajaDelEquipo($pos, $equipo);
            $body = InputSanitizer::jsonInput() ?? [];
            $fondo = is_array($body) ? ($body['fondo_centavos'] ?? null) : null;
            if (!is_int($fondo)) {
                throw new PosError('Escribe el fondo inicial de la caja (0 si empieza vacía).', 422, 'FONDO_INVALIDO');
            }
            $turno = $pos->abrirTurno($caja['id'], $sesion['empleado']['id'], $fondo / 100);
            posAudit('POS_TURNO_ABIERTO', $equipo['id'], [
                'turno_id' => $turno['id'], 'caja_id' => $caja['id'], 'empleado_id' => $sesion['empleado']['id'],
                'empleado' => $sesion['empleado']['nombre'], 'fondo_inicial' => $turno['fondo_inicial'],
            ], 'Turno del POS abierto.');
            posResponder(201, ['turno_caja' => $turno]);
            break;

        case 'POST ventas':
            $sesion = PosAuth::requerirSesion($pos, $equipo);
            $caja = posCajaDelEquipo($pos, $equipo);
            $body = InputSanitizer::jsonInput() ?? [];
            if (!is_array($body)) {
                throw new PosError('No se pudieron leer los datos de la venta. Inténtalo de nuevo.', 400, 'CUERPO_INVALIDO');
            }
            RequestContext::set('username', 'POS · ' . $sesion['empleado']['nombre']);
            posResponder(201, PosVenta::cobrar($pos, $equipo, $sesion['empleado'], $caja, $body));
            break;

        case 'POST autorizar':
            // PIN de supervisor dentro de la sesion de otro empleado (S1). Los
            // fallos cuentan para el bloqueo del equipo, igual que en la entrada.
            $sesion = PosAuth::requerirSesion($pos, $equipo);
            $caja = posCajaDelEquipo($pos, $equipo);
            if ($equipo['bloqueado']) {
                throw new PosError('Demasiados PIN incorrectos. Espera unos minutos.', 423, 'EQUIPO_BLOQUEADO',
                    ['bloqueo_segundos' => $equipo['bloqueo_segundos']]);
            }
            $body = InputSanitizer::jsonInput() ?? [];
            $pin = is_array($body) ? ($body['pin'] ?? null) : null;
            $accion = is_array($body) ? (string) ($body['accion'] ?? '') : '';
            if (!in_array($accion, PosAutorizacion::ACCIONES, true)) {
                throw new PosError('Esa acción no se autoriza con PIN.', 422, 'ACCION_INVALIDA');
            }
            if (!PosPin::formatoValido($pin)) {
                throw new PosError('El PIN tiene ' . PosPin::DIGITOS . ' dígitos.', 422, 'PIN_FORMATO');
            }
            $turno = $pos->turnoAbiertoDeCaja($caja['id']);
            if ($turno === null || (int) ($body['turno_id'] ?? 0) !== $turno['id']) {
                throw new PosError('El turno de la caja cambió. Revisa y vuelve a intentarlo.', 409, 'TURNO_CAMBIO');
            }
            $tenantId = (int) TenantResolver::current()['id'];
            $supervisor = $pos->empleadoPorPin(PosPin::hmac($pin, $tenantId));
            if ($supervisor === null || $supervisor['rol'] !== 'supervisor') {
                $fallo = $master->registrarFalloPin($equipo['id']);
                posAudit('POS_AUTORIZACION_FALLIDA', $equipo['id'], ['caja_id' => $caja['id'], 'accion' => $accion,
                    'pedida_por' => $sesion['empleado']['nombre']], 'PIN de supervisor rechazado en el POS.', false);
                if ($fallo['bloqueado']) {
                    throw new PosError('Demasiados PIN incorrectos. El equipo queda bloqueado unos minutos.', 423, 'EQUIPO_BLOQUEADO',
                        ['bloqueo_segundos' => $fallo['bloqueo_segundos']]);
                }
                throw $supervisor === null
                    ? new PosError('PIN incorrecto.', 401, 'PIN_INCORRECTO', ['intentos_restantes' => $fallo['intentos_restantes']])
                    : new PosError('Ese PIN no puede autorizar: tiene que ser de un supervisor.', 403, 'PIN_SIN_PERMISO',
                        ['intentos_restantes' => $fallo['intentos_restantes']]);
            }
            $master->reiniciarIntentos($equipo['id']);
            posAudit('POS_AUTORIZACION', $equipo['id'], ['caja_id' => $caja['id'], 'accion' => $accion, 'turno_id' => $turno['id'],
                'supervisor_id' => $supervisor['id'], 'supervisor' => $supervisor['nombre'], 'pedida_por' => $sesion['empleado']['nombre']],
                'Supervisor autorizó una acción en el POS.');
            posResponder(200, [
                'permiso' => PosAutorizacion::emitir($supervisor, $accion, $turno['id'], $equipo['id'], $tenantId),
                'supervisor' => ['id' => $supervisor['id'], 'nombre' => $supervisor['nombre']],
                'vence_en_segundos' => PosAutorizacion::VIGENCIA_SEGUNDOS,
            ]);
            break;

        case 'POST turno/cerrar':
            // Se puede cerrar aunque la caja se haya desactivado: un turno no
            // tiene que quedar atrapado abierto.
            $sesion = PosAuth::requerirSesion($pos, $equipo);
            $caja = $pos->cajaPorId($equipo['caja_id']);
            $body = InputSanitizer::jsonInput() ?? [];
            if ($caja === null || !is_array($body)) {
                throw new PosError('No se pudieron leer los datos del cierre.', 400, 'CUERPO_INVALIDO');
            }
            RequestContext::set('username', 'POS · ' . $sesion['empleado']['nombre']);
            posResponder(200, PosCierre::cerrar($pos, $equipo, $caja, $sesion['empleado'], $body, (int) TenantResolver::current()['id']));
            break;

        case 'POST turno/nota':
            PosAuth::requerirSesion($pos, $equipo);
            $caja = $pos->cajaPorId($equipo['caja_id']);
            $body = InputSanitizer::jsonInput() ?? [];
            if ($caja === null || !is_array($body)) {
                throw new PosError('No se pudo leer la nota.', 400, 'CUERPO_INVALIDO');
            }
            posResponder(200, PosCierre::nota($pos, $caja, $body));
            break;

        case 'GET ventas':
            PosAuth::requerirSesion($pos, $equipo);
            $caja = $pos->cajaPorId($equipo['caja_id']);
            posResponder(200, PosCierre::ventasDelTurno($pos, $caja ?? ['id' => $equipo['caja_id']]));
            break;

        case 'POST eventos':
            $sesion = PosAuth::requerirSesion($pos, $equipo);
            $caja = $pos->cajaPorId($equipo['caja_id']);
            $body = InputSanitizer::jsonInput() ?? [];
            if ($caja === null || !is_array($body)) {
                throw new PosError('Evento no válido.', 422, 'EVENTO_INVALIDO');
            }
            posResponder(200, PosCierre::evento($pos, $caja, $sesion['empleado'], $equipo, $body));
            break;

        case 'POST clientes/rnc':
            PosAuth::requerirSesion($pos, $equipo);
            posCajaDelEquipo($pos, $equipo);
            $body = InputSanitizer::jsonInput() ?? [];
            posResponder(200, PosCliente::porRnc($pos, is_array($body) ? ($body['rnc'] ?? '') : ''));
            break;

        case 'POST pendientes/reenviar':
            // Sin sesion de empleado: lo llama el POS en segundo plano, tambien
            // con la pantalla bloqueada. Solo toca las ventas de esta empresa.
            posResponder(200, PosVenta::reenviarPendientes($pos));
            break;

        case 'GET catalogo':
            // Lo pide el empleado con su sesion; la caja tiene que estar activa.
            PosAuth::requerirSesion($pos, $equipo);
            posCajaDelEquipo($pos, $equipo);
            posResponder(200, posCatalogo($pos));
            break;

        default:
            throw new PosError('Ruta no encontrada.', 404, 'RUTA_NO_EXISTE');
    }
} catch (PosError $e) {
    $e->responder();
} catch (Throwable $e) {
    error_log('[pos] ' . get_class($e) . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => false, 'error' => 'No se pudo completar la operación. Inténtalo de nuevo; si sigue fallando, avisa a soporte.', 'codigo' => 'ERROR_INTERNO'], JSON_UNESCAPED_UNICODE);
}
