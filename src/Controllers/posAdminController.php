<?php
// Administracion del POS (docs/specs/pos.md M1, A4, A5, K1 y §9.4). La usan
// app.* (pantallas de configuracion) y pos.* con la sesion del admin, solo para
// habilitar el equipo. Requiere usuario con el modulo RBAC 'pos' y el POS activo
// en la empresa (master.tenants.pos_enabled).
//
//   GET    /api/pos-admin/cajas               -> cajas
//   POST   /api/pos-admin/cajas               -> {nombre}
//   PUT    /api/pos-admin/cajas/{id}          -> {nombre?, activa?}
//   GET    /api/pos-admin/empleados           -> empleados (sin PIN: no se puede volver a leer)
//   POST   /api/pos-admin/empleados           -> {nombre, rol} -> {empleado, pin}   el PIN se ve UNA vez
//   PUT    /api/pos-admin/empleados/{id}      -> {nombre?, rol?, activo?}
//   POST   /api/pos-admin/empleados/{id}/pin  -> {pin} nuevo; el anterior deja de servir
//   GET    /api/pos-admin/equipos             -> equipos habilitados
//   POST   /api/pos-admin/equipos             -> {caja_id, nombre?, reemplazar?} -> {equipo, token}  el token se ve UNA vez
//   DELETE /api/pos-admin/equipos/{id}        -> revocar
//
// Errores: {status:false, error, codigo}. El codigo es estable (CAJA_OCUPADA,
// POS_INACTIVO...); el texto es para la persona.

require_once __DIR__ . '/../Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../Models/RoleModel.php';
require_once __DIR__ . '/../PermissionGate.php';
require_once __DIR__ . '/../Pos/PosError.php';
require_once __DIR__ . '/../Pos/PosPin.php';
require_once __DIR__ . '/../Pos/PosAuth.php';
require_once __DIR__ . '/../Models/posModel.php';
require_once __DIR__ . '/../Models/posMasterModel.php';

/** Una linea de auditoria del POS. Nunca con PIN ni tokens. */
function posAdminAudit(string $action, string $entityType, $entityId, ?array $new, string $descripcion, ?array $old = null): void
{
    AuditLogger::log([
        'module' => 'pos', 'action' => $action,
        'entity_type' => $entityType, 'entity_id' => $entityId,
        'old_values' => $old, 'new_values' => $new, 'description' => $descripcion,
    ]);
}

function posAdminResponder(int $http, array $data): void
{
    http_response_code($http);
    echo json_encode(['status' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
}

/** Valida que $v sea un bool de JSON (true/false) o lo corta con 422. */
function posAdminBool($v, string $campo): bool
{
    if (!is_bool($v)) {
        throw new PosError("El campo {$campo} tiene que ser true o false.", 422, 'CAMPO_INVALIDO');
    }
    return $v;
}

try {
    // --- Quien pide: usuario con el modulo 'pos' -----------------------------
    $auth = new AuthMiddleware();
    $v = $auth->validateRequest();
    if (empty($v['valid']) || ($v['user_id'] ?? null) === null) {
        throw new PosError('Inicia sesión para administrar el POS.', 401, 'SESION_REQUERIDA');
    }
    // El modulo se exige SIEMPRE, aunque PERMISSIONS_ENFORCE este en modo
    // sombra: aqui se generan PINs y se habilitan cajas.
    $perms = (new RoleModel())->getPermissionsForRole($v['tenant_id'] ?? null, (string) ($v['role'] ?? ''));
    if (!PermissionGate::permMatches($perms, 'pos')) {
        throw new PosError('No tienes permiso para administrar el POS. Pídeselo a un administrador.', 403, 'SIN_PERMISO');
    }
    PosAuth::exigirPosActivo(TenantResolver::current());
    $tenantId = (int) $v['tenant_id'];
    $userId = (int) $v['user_id'];

    $pos = new posModel();
    $master = new posMasterModel();

    // --- Ruta: /api/pos-admin/{recurso}[/{id}[/{accion}]] ----------------------
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $resto = substr($path, strpos($path, '/api/pos-admin') + strlen('/api/pos-admin'));
    $partes = array_values(array_filter(explode('/', trim($resto, '/')), fn($p) => $p !== ''));
    $recurso = $partes[0] ?? '';
    $id = isset($partes[1]) && ctype_digit($partes[1]) ? (int) $partes[1] : null;
    $accion = $partes[2] ?? null;
    if (isset($partes[1]) && $id === null) {
        throw new PosError('Ruta no encontrada.', 404, 'RUTA_NO_EXISTE');
    }
    $metodo = $_SERVER['REQUEST_METHOD'];
    $body = in_array($metodo, ['POST', 'PUT'], true) ? (InputSanitizer::jsonInput() ?? []) : [];
    if (!is_array($body)) {
        throw new PosError('No se pudieron leer los datos. Recarga la página e inténtalo de nuevo.', 400, 'CUERPO_INVALIDO');
    }

    switch ("{$metodo} {$recurso}" . ($id !== null ? ' :id' : '') . ($accion !== null ? " {$accion}" : '')) {

        // ---------------------------------------------------------------- cajas
        case 'GET cajas':
            posAdminResponder(200, ['cajas' => $pos->listarCajas()]);
            break;

        case 'POST cajas':
            $caja = $pos->crearCaja((string) ($body['nombre'] ?? ''));
            posAdminAudit('POS_CAJA_CREADA', 'pos_caja', $caja['id'], $caja, 'Caja del POS creada.');
            posAdminResponder(201, ['caja' => $caja]);
            break;

        case 'PUT cajas :id':
            $campos = [];
            if (array_key_exists('nombre', $body)) {
                $campos['nombre'] = (string) $body['nombre'];
            }
            if (array_key_exists('activa', $body)) {
                $campos['activa'] = posAdminBool($body['activa'], 'activa');
            }
            $antes = $pos->cajaPorId($id);
            $caja = $pos->actualizarCaja($id, $campos);
            posAdminAudit('POS_CAJA_ACTUALIZADA', 'pos_caja', $id, $caja, 'Caja del POS actualizada.', $antes);
            posAdminResponder(200, ['caja' => $caja]);
            break;

        // ------------------------------------------------------------ empleados
        case 'GET empleados':
            posAdminResponder(200, ['empleados' => $pos->listarEmpleados()]);
            break;

        case 'POST empleados':
            $generar = static function () use ($tenantId): array {
                $pin = PosPin::generar();
                return [$pin, PosPin::hmac($pin, $tenantId)];
            };
            $creado = $pos->crearEmpleado((string) ($body['nombre'] ?? ''), (string) ($body['rol'] ?? 'cajero'), $generar);
            posAdminAudit('POS_EMPLEADO_CREADO', 'pos_empleado', $creado['empleado']['id'], $creado['empleado'],
                'Empleado del POS creado con PIN generado por el sistema.');
            posAdminResponder(201, $creado);
            break;

        case 'PUT empleados :id':
            $campos = array_intersect_key($body, array_flip(['nombre', 'rol', 'activo']));
            if (array_key_exists('activo', $campos)) {
                $campos['activo'] = posAdminBool($campos['activo'], 'activo');
            }
            $antes = $pos->empleadoPorId($id);
            $empleado = $pos->actualizarEmpleado($id, $campos);
            posAdminAudit('POS_EMPLEADO_ACTUALIZADO', 'pos_empleado', $id, $empleado, 'Empleado del POS actualizado.', $antes);
            posAdminResponder(200, ['empleado' => $empleado]);
            break;

        case 'POST empleados :id pin':
            $generar = static function () use ($tenantId): array {
                $pin = PosPin::generar();
                return [$pin, PosPin::hmac($pin, $tenantId)];
            };
            $pin = $pos->regenerarPin($id, $generar);
            posAdminAudit('POS_PIN_REGENERADO', 'pos_empleado', $id, null,
                'PIN regenerado: el anterior dejó de servir y sus sesiones se cerraron.');
            posAdminResponder(200, ['empleado' => $pos->empleadoPorId($id), 'pin' => $pin]);
            break;

        // -------------------------------------------------------------- equipos
        case 'GET equipos':
            $cajas = [];
            foreach ($pos->listarCajas() as $c) {
                $cajas[$c['id']] = $c;
            }
            $equipos = array_map(static function (array $e) use ($cajas): array {
                $e['caja'] = $cajas[$e['caja_id']] ?? null;
                return $e;
            }, $master->listarEquipos($tenantId));
            posAdminResponder(200, ['equipos' => $equipos]);
            break;

        case 'POST equipos':
            $cajaId = $body['caja_id'] ?? null;
            if (!is_int($cajaId) && !(is_string($cajaId) && ctype_digit($cajaId))) {
                throw new PosError('Elige la caja que va a ser este equipo.', 422, 'CAJA_REQUERIDA');
            }
            $caja = $pos->cajaPorId((int) $cajaId);
            if ($caja === null) {
                throw new PosError('Esa caja no existe.', 404, 'CAJA_NO_EXISTE');
            }
            if (!$caja['activa']) {
                throw new PosError("La caja «{$caja['nombre']}» está desactivada. Actívala antes de habilitar un equipo.", 409, 'CAJA_INACTIVA');
            }
            $nombre = trim((string) ($body['nombre'] ?? ''));
            if ($nombre === '') {
                $nombre = trim((RequestContext::browser() ?? 'Navegador') . ' · ' . (RequestContext::os() ?? ''), ' ·');
            }
            $reemplazar = array_key_exists('reemplazar', $body) ? posAdminBool($body['reemplazar'], 'reemplazar') : false;

            [$resultado, $datos] = $master->habilitarEquipo($tenantId, $caja['id'], $nombre, $userId, $reemplazar);
            if ($resultado === 'ocupada') {
                throw new PosError(
                    "La caja «{$caja['nombre']}» ya tiene un equipo habilitado. Si este lo reemplaza, el otro deja de funcionar.",
                    409,
                    'CAJA_OCUPADA',
                    ['equipo_actual' => $datos]
                );
            }
            // Los equipos reemplazados se quedan sin sesiones abiertas.
            foreach ($datos['revocados'] as $revocado) {
                $pos->cerrarSesionesDeEquipo($revocado);
                posAdminAudit('POS_EQUIPO_REVOCADO', 'pos_equipo', $revocado, ['caja_id' => $caja['id'], 'reemplazado_por' => $datos['equipo_id']],
                    'Equipo de caja revocado al habilitar otro en la misma caja.');
            }
            posAdminAudit('POS_EQUIPO_HABILITADO', 'pos_equipo', $datos['equipo_id'], ['caja_id' => $caja['id'], 'caja' => $caja['nombre'], 'nombre' => $nombre],
                'Equipo habilitado como caja del POS.');
            posAdminResponder(201, [
                'equipo' => ['id' => $datos['equipo_id'], 'nombre' => $nombre, 'caja' => $caja],
                'token' => $datos['token'],
            ]);
            break;

        case 'DELETE equipos :id':
            if (!$master->revocarEquipo($tenantId, $id)) {
                throw new PosError('Ese equipo no existe o ya estaba revocado.', 404, 'EQUIPO_NO_EXISTE');
            }
            $pos->cerrarSesionesDeEquipo($id);
            posAdminAudit('POS_EQUIPO_REVOCADO', 'pos_equipo', $id, null, 'Equipo de caja revocado por un administrador.');
            posAdminResponder(200, ['revocado' => $id]);
            break;

        default:
            throw new PosError('Ruta no encontrada.', 404, 'RUTA_NO_EXISTE');
    }
} catch (PosError $e) {
    $e->responder();
} catch (Throwable $e) {
    error_log('[posAdmin] ' . get_class($e) . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => false, 'error' => 'No se pudo completar la operación. Inténtalo de nuevo; si sigue fallando, avisa a soporte.', 'codigo' => 'ERROR_INTERNO'], JSON_UNESCAPED_UNICODE);
}
