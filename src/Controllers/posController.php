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

try {
    $equipo = PosAuth::requerirEquipo();
    $pos = new posModel();
    $master = new posMasterModel();

    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $resto = trim(substr($path, strpos($path, '/api/pos') + strlen('/api/pos')), '/');
    $metodo = $_SERVER['REQUEST_METHOD'];

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
