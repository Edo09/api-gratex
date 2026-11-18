<?php
header('Access-Control-Allow-Origin: *');
header("Access-Control-Allow-Headers: X-API-KEY, Authorization, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Allow: GET, POST, OPTIONS, PUT, DELETE");
header('content-type: application/json; charset=utf-8');
require_once(__DIR__ . '/../Models/cobroModel.php');
require_once(__DIR__ . '/../Middleware/AuthMiddleware.php');
require_once(__DIR__ . '/../Utils/InputSanitizer.php');
require_once(__DIR__ . '/../RequestContext.php');
require_once(__DIR__ . '/../Models/RoleModel.php');
require_once(__DIR__ . '/../PermissionGate.php');
require_once(__DIR__ . '/../MasterDatabase.php');
require_once(__DIR__ . '/../AccesoGlobal.php');

/*
 * /api/cobros: cuentas por cobrar por cliente (modulo RBAC 'cobros'; docs/modules/cobros.md).
 *
 *   GET  /api/cobros?page&pageSize&query&client_id&estado&periodo&vence_desde&vence_hasta&orden
 *   POST /api/cobros                              crear un cobro a mano
 *   GET  /api/cobros/resumen?dias=7               el dashboard
 *   GET  /api/cobros/recordatorios?dias=7&cada=7  a quien recordarle (informativo)
 *   GET  /api/cobros/clientes/{id}                panel del cliente: configuracion, resumen, cobros, pagos
 *   PUT  /api/cobros/clientes/{id}/config         monto mensual y dia de vencimiento del cliente
 *   GET  /api/cobros/{id}                         un cobro con sus pagos y recordatorios
 *   PUT  /api/cobros/{id}                         cambiar monto, vencimiento o descripcion
 *   POST /api/cobros/{id}/pagos                   registrar un pago (completo o parcial)
 *   POST /api/cobros/{id}/cancelar                cancelar (nunca se borra)
 *   POST /api/cobros/{id}/reabrir                 reabrir uno cancelado
 *   POST /api/cobros/{id}/recordatorios           anotar un recordatorio hecho a mano
 *   POST /api/cobros/pagos/{id}/anular            anular un pago (nunca se borra)
 *
 * Las reglas (estado, vencimiento, validacion de cuerpos, ruta) viven en CobroReglas y el
 * SQL en cobroModel. Aqui se lee la peticion, se elige la accion, se anota la bitacora
 * (AuditLogger, que incluye el Router para todos) y se envuelve la respuesta
 * ({status, data|error, codigo?}), como conduceController.
 *
 * Orden de las revisiones: el token primero (401; de paso resuelve el tenant); despues el
 * modulo 'cobros' del rol (403); despues la ruta (404 / 405); recien entonces se abre el
 * modelo (la DB del tenant).
 *
 * DOS ambitos con este mismo controlador (el Router define COBROS_AMBITO antes de incluirlo):
 *   - /api/cobros          (por defecto) cada empresa cobra a SUS clientes, en su base; exige el modulo 'cobros'.
 *   - /api/cobros-tenants  la PLATAFORMA cobra a sus tenants, en la base master; exige que el usuario tenga acceso
 *                          global (master.users.acceso_global = 1: soporte). Mismas rutas, mismas reglas, mismas
 *                          respuestas, mas GET /api/cobros-tenants/clientes?query=&limite= (el directorio de tenants, con su
 *                          cobro mensual y lo que deben).
 *
 * El modulo se exige SIEMPRE, aunque PERMISSIONS_ENFORCE este en modo sombra (como
 * posAdminController): son cuentas por cobrar de la empresa, con datos de contacto de sus
 * clientes, y el gate solo avisa en sombra y, ademas, el Router sigue hacia el controller si el
 * gate falla. Un modulo NUEVO no le quita nada a nadie: quien ya tenia acceso a "todo" (admin, '*')
 * lo conserva, y el rol 'user' de las empresas existentes lo recibe con la master 020.
 */

/** Responde un ['error', texto, http, codigo?, data?] con su codigo HTTP (500 si no trae). */
function cobrosResponderError(array $resultado): void
{
    http_response_code((int) ($resultado[2] ?? 500));
    $sobre = ['status' => false, 'error' => $resultado[1]];
    if (!empty($resultado[3])) {
        $sobre['codigo'] = $resultado[3];
    }
    if (isset($resultado[4])) {
        $sobre['data'] = $resultado[4];
    }
    echo json_encode($sobre);
}

/** Responde ['success', datos]: el sobre con data. */
function cobrosResponder(mixed $datos, array $extra = []): void
{
    echo json_encode(['status' => true, 'data' => $datos] + $extra);
}

/** Lo que se guarda del cobro en la bitacora (sin pagos ni recordatorios: eso tiene sus propias filas). */
function cobrosResumenAuditoria(?array $c): ?array
{
    if ($c === null) {
        return null;
    }
    return array_intersect_key($c, array_flip([
        'id', 'client_id', 'periodo', 'descripcion', 'monto', 'monto_pagado', 'saldo', 'fecha_vencimiento', 'estado',
        'cancelado_at', 'motivo_cancelacion', 'generado_de_cobro_id',
    ])) + ['cliente' => $c['cliente']['nombre'] ?? null];
}

/** ¿Esta peticion es del ambito de la plataforma (/api/cobros-tenants)? */
function cobrosEsPlataforma(): bool
{
    return defined('COBROS_AMBITO') && COBROS_AMBITO === 'tenants';
}

/**
 * Anota en la bitacora. En la plataforma el modulo es 'cobros-tenants' y la fila va con tenant_id = 0: la bitacora de
 * cada empresa filtra por su tenant_id, asi que ningun tenant ve en la suya lo que la plataforma le cobra a otro (la
 * sesion de soporte puede estar dentro de CUALQUIER empresa cuando cobra).
 */
function cobrosAuditar(array $evento): void
{
    AuditLogger::log($evento + (cobrosEsPlataforma() ? ['module' => 'cobros-tenants', 'tenant_id' => 0] : ['module' => 'cobros']));
}

/** Anota en la bitacora el cobro que se genero solo al quedar pagado el anterior. */
function cobrosAuditarGenerado(array $nuevo, array $origen): void
{
    cobrosAuditar([
        'action' => 'CREATE',
        'entity_type' => 'cobro', 'entity_id' => $nuevo['id'],
        'new_values' => cobrosResumenAuditoria($nuevo),
        'description' => 'Cobro del periodo ' . $nuevo['periodo'] . ' generado al quedar pagado el del periodo ' . $origen['periodo'] . '.',
    ]);
}

$auth = new AuthMiddleware();

// El Router responde el preflight (OPTIONS) antes de incluir un controller. Si llegara uno
// hasta aqui, no lleva token ni datos: no hay nada que revisar.
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    return;
}

$validation = $auth->validateRequest();
if (!$validation['valid']) {
    $auth->sendUnauthorized($validation['message']);
}

$ambitoTenants = cobrosEsPlataforma();
if ($ambitoTenants) {
    // Cobros de la PLATAFORMA: solo un usuario de soporte (master.users.acceso_global = 1). Un principal maquina no
    // tiene usuario, y si la columna no se puede leer nadie tiene el acceso: falla cerrado.
    $permitido = ($validation['user_id'] ?? null) !== null
        && AccesoGlobal::tieneAcceso(MasterDatabase::getInstance()->getConnection(), (int) $validation['user_id']);
    if (!$permitido) {
        AuditMiddleware::logAccessDenied(
            'cobros-tenants',
            'sin acceso global',
            ['permiso_requerido' => 'acceso_global', 'metodo' => $_SERVER['REQUEST_METHOD']],
            'Acceso denegado: los cobros a los tenants son de soporte (acceso global).'
        );
        cobrosResponderError(['error', CobroReglas::MSG_SIN_PERMISO_PLATAFORMA, 403]);
        return;
    }
} else {
    // Solo un USUARIO de la app con el modulo 'cobros' (un principal maquina no tiene rol; sin permisos
    // legibles, el RoleModel devuelve [] y se niega: falla cerrado).
    $permisos = ($validation['user_id'] ?? null) === null
        ? []
        : (new RoleModel())->getPermissionsForRole($validation['tenant_id'] ?? null, (string) ($validation['role'] ?? ''));
    if (!PermissionGate::permMatches($permisos, 'cobros')) {
        AuditMiddleware::logAccessDenied(
            'cobros',
            'rol sin permiso',
            ['permiso_requerido' => 'cobros', 'metodo' => $_SERVER['REQUEST_METHOD']],
            'Acceso denegado: el rol no tiene el modulo cobros.'
        );
        cobrosResponderError(['error', CobroReglas::MSG_SIN_PERMISO, 403]);
        return;
    }
}

$metodo = $_SERVER['REQUEST_METHOD'];
$ruta = CobroReglas::ruta($metodo, (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), $ambitoTenants ? 'cobros-tenants' : 'cobros');
if ($ruta['accion'] === 'desconocida') {
    cobrosResponderError(['error', CobroReglas::MSG_RUTA, 404]);
    return;
}
if ($ruta['accion'] === 'metodo') {
    cobrosResponderError(['error', CobroReglas::MSG_METODO_HTTP, 405]);
    return;
}

// El cuerpo se decodifica una sola vez. Vacio, invalido o que no es un objeto JSON queda
// como objeto vacio: los validadores reciben siempre un objeto y piden lo que falta.
$body = InputSanitizer::jsonInput(false);
if (!is_object($body)) {
    $body = new stdClass();
}

try {
    $modelo = new cobroModel(null, null, $ambitoTenants ? cobroModel::AMBITO_TENANTS : cobroModel::AMBITO_EMPRESA);
    $usuarioId = RequestContext::userId();
    $usuarioNombre = RequestContext::username() ?? RequestContext::email();

    switch ($ruta['accion']) {
        case 'listar':
            $f = CobroReglas::validarFiltros($_GET);
            if ($f[0] !== 'success') {
                cobrosResponderError($f);
                break;
            }
            $pag = CobroReglas::paginacion($_GET);
            $filas = $modelo->listar($f[1], $pag['offset'], $pag['pageSize']);
            $total = $modelo->contar($f[1]);
            echo json_encode([
                'status' => true,
                'data' => $filas,
                'pagination' => [
                    'page' => $pag['page'], 'pageSize' => $pag['pageSize'], 'total' => $total,
                    'totalPages' => (int) ceil($total / $pag['pageSize']),
                ],
            ]);
            break;

        case 'resumen':
            cobrosResponder($modelo->resumen(CobroReglas::enteroDe($_GET, 'dias', CobroReglas::DIAS_PROXIMOS)));
            break;

        case 'recordatorios':
            cobrosResponder($modelo->recordatorios(
                CobroReglas::enteroDe($_GET, 'dias', CobroReglas::DIAS_PROXIMOS),
                CobroReglas::enteroDe($_GET, 'cada', CobroReglas::DIAS_RECORDAR)
            ));
            break;

        case 'buscar':
            // El directorio de tenants (buscador de los formularios y pestana Clientes): solo existe en el ambito de la
            // plataforma (en una empresa el buscador es el de Clientes). Hasta cobroModel::MAX_DIRECTORIO por respuesta.
            if (!$ambitoTenants) {
                cobrosResponderError(['error', CobroReglas::MSG_RUTA, 404]);
                break;
            }
            cobrosResponder($modelo->buscarClientes(
                is_string($_GET['query'] ?? null) ? $_GET['query'] : '',
                CobroReglas::enteroDe($_GET, 'limite', 20, cobroModel::MAX_DIRECTORIO)
            ));
            break;

        case 'panel':
            $panel = $modelo->panelCliente($ruta['client_id']);
            if ($panel === null) {
                cobrosResponderError(['error', CobroReglas::MSG_CLIENTE_NO_EXISTE, 404]);
                break;
            }
            cobrosResponder($panel);
            break;

        case 'obtener':
            $cobro = $modelo->obtener($ruta['id']);
            if ($cobro === null) {
                cobrosResponderError(['error', CobroReglas::MSG_NO_EXISTE, 404]);
                break;
            }
            cobrosResponder($cobro);
            break;

        case 'config':
            $v = CobroReglas::validarConfig($body);
            if ($v[0] !== 'success') {
                cobrosResponderError($v);
                break;
            }
            $r = $modelo->guardarConfig($ruta['client_id'], $v[1], $usuarioId);
            if ($r[0] !== 'success') {
                cobrosResponderError($r);
                break;
            }
            cobrosAuditar([
                'action' => 'CONFIG',
                'entity_type' => 'cobro_config', 'entity_id' => $ruta['client_id'],
                'old_values' => $r[1]['anterior'], 'new_values' => $r[1]['config'],
                'description' => $r[1]['anterior'] === null
                    ? 'Cobro mensual configurado para el cliente ' . $ruta['client_id'] . '.'
                    : 'Cobro mensual del cliente ' . $ruta['client_id'] . ' modificado (no cambia los cobros ya generados).',
            ]);
            cobrosResponder($r[1]['config']);
            break;

        case 'crear':
            $v = CobroReglas::validarNuevo($body);
            if ($v[0] !== 'success') {
                cobrosResponderError($v);
                break;
            }
            $r = $modelo->crear($v[1], $usuarioId);
            if ($r[0] !== 'success') {
                cobrosResponderError($r);
                break;
            }
            cobrosAuditar([
                'action' => 'CREATE',
                'entity_type' => 'cobro', 'entity_id' => $r[1]['id'],
                'new_values' => cobrosResumenAuditoria($r[1]),
                'description' => 'Cobro del periodo ' . $r[1]['periodo'] . ' creado para ' . ($r[1]['cliente']['nombre'] ?? 'el cliente') . '.',
            ]);
            cobrosResponder($r[1]);
            break;

        case 'actualizar':
            $v = CobroReglas::validarCambios($body);
            if ($v[0] !== 'success') {
                cobrosResponderError($v);
                break;
            }
            $r = $modelo->actualizar($ruta['id'], $v[1], $usuarioId);
            if ($r[0] !== 'success') {
                cobrosResponderError($r);
                break;
            }
            cobrosAuditar([
                'action' => 'UPDATE',
                'entity_type' => 'cobro', 'entity_id' => $ruta['id'],
                'old_values' => cobrosResumenAuditoria($r[1]['anterior']), 'new_values' => cobrosResumenAuditoria($r[1]['cobro']),
                'description' => 'Cobro del periodo ' . $r[1]['cobro']['periodo'] . ' modificado.',
            ]);
            if ($r[1]['siguiente'] !== null) {
                cobrosAuditarGenerado($r[1]['siguiente'], $r[1]['cobro']);
            }
            // data = {cobro, siguiente, siguiente_motivo}: el cobro ya modificado y, si el cambio lo dejo
            // pagado completo, el del mes siguiente que se genero (o por que no se genero).
            cobrosResponder([
                'cobro' => $r[1]['cobro'], 'siguiente' => $r[1]['siguiente'], 'siguiente_motivo' => $r[1]['siguiente_motivo'],
            ]);
            break;

        case 'pagar':
            $v = CobroReglas::validarPago($body, $modelo->hoy());
            if ($v[0] !== 'success') {
                cobrosResponderError($v);
                break;
            }
            $r = $modelo->registrarPago($ruta['id'], $v[1], $usuarioId);
            if ($r[0] !== 'success') {
                cobrosResponderError($r);
                break;
            }
            $x = $r[1];
            // Una repeticion (misma clave) devuelve el pago de la primera vez: no se anota otra vez.
            if (!$x['repetido']) {
                cobrosAuditar([
                    'action' => 'PAYMENT',
                    'entity_type' => 'cobro_pago', 'entity_id' => $x['pago']['id'],
                    'new_values' => array_intersect_key($x['pago'], array_flip(['cobro_id', 'monto', 'fecha_pago', 'metodo', 'observaciones']))
                        + ['saldo_restante' => $x['cobro']['saldo'], 'estado' => $x['cobro']['estado']],
                    'description' => 'Pago de ' . number_format((float) $x['pago']['monto'], 2) . ' registrado al cobro del periodo '
                        . $x['cobro']['periodo'] . ' (' . $x['cobro']['estado'] . ').',
                ]);
                if ($x['siguiente_nuevo'] && $x['siguiente'] !== null) {
                    cobrosAuditarGenerado($x['siguiente'], $x['cobro']);
                }
            }
            cobrosResponder($x);
            break;

        case 'cancelar':
            $m = CobroReglas::validarMotivo($body);
            if ($m[0] !== 'success') {
                cobrosResponderError($m);
                break;
            }
            $antes = $modelo->obtener($ruta['id']);
            $r = $modelo->cancelar($ruta['id'], $m[1], $usuarioId);
            if ($r[0] !== 'success') {
                cobrosResponderError($r);
                break;
            }
            if (!$r[1]['repetido']) {
                cobrosAuditar([
                    'action' => 'CANCEL',
                    'entity_type' => 'cobro', 'entity_id' => $ruta['id'],
                    'old_values' => cobrosResumenAuditoria($antes), 'new_values' => cobrosResumenAuditoria($r[1]['cobro']),
                    'description' => 'Cobro del periodo ' . $r[1]['cobro']['periodo'] . ' cancelado.',
                ]);
            }
            cobrosResponder($r[1]['cobro'], ['repetido' => $r[1]['repetido']]);
            break;

        case 'reabrir':
            $antes = $modelo->obtener($ruta['id']);
            $r = $modelo->reabrir($ruta['id'], $usuarioId);
            if ($r[0] !== 'success') {
                cobrosResponderError($r);
                break;
            }
            if (!$r[1]['repetido']) {
                cobrosAuditar([
                    'action' => 'REOPEN',
                    'entity_type' => 'cobro', 'entity_id' => $ruta['id'],
                    'old_values' => cobrosResumenAuditoria($antes), 'new_values' => cobrosResumenAuditoria($r[1]['cobro']),
                    'description' => 'Cobro del periodo ' . $r[1]['cobro']['periodo'] . ' reabierto.',
                ]);
            }
            cobrosResponder($r[1]['cobro'], ['repetido' => $r[1]['repetido']]);
            break;

        case 'recordar':
            $v = CobroReglas::validarRecordatorio($body, $modelo->ahora());
            if ($v[0] !== 'success') {
                cobrosResponderError($v);
                break;
            }
            $r = $modelo->registrarRecordatorio($ruta['id'], $v[1], $usuarioId, $usuarioNombre);
            if ($r[0] !== 'success') {
                cobrosResponderError($r);
                break;
            }
            cobrosAuditar([
                'action' => 'REMINDER',
                'entity_type' => 'cobro', 'entity_id' => $ruta['id'],
                'new_values' => $r[1]['recordatorio'],
                'description' => 'Recordatorio (' . $v[1]['canal'] . ') anotado al cobro del periodo ' . $r[1]['cobro']['periodo'] . '.',
            ]);
            cobrosResponder($r[1]);
            break;

        case 'anular':
            $m = CobroReglas::validarMotivo($body);
            if ($m[0] !== 'success') {
                cobrosResponderError($m);
                break;
            }
            $r = $modelo->anularPago($ruta['pago_id'], $m[1], $usuarioId);
            if ($r[0] !== 'success') {
                cobrosResponderError($r);
                break;
            }
            if (!$r[1]['repetido']) {
                cobrosAuditar([
                    'action' => 'VOID_PAYMENT',
                    'entity_type' => 'cobro_pago', 'entity_id' => $ruta['pago_id'],
                    'old_values' => $r[1]['anterior'], 'new_values' => $r[1]['pago'],
                    'description' => 'Pago de ' . number_format((float) $r[1]['pago']['monto'], 2) . ' anulado en el cobro del periodo '
                        . $r[1]['cobro']['periodo'] . '.',
                ]);
            }
            cobrosResponder($r[1]);
            break;

        default:
            cobrosResponderError(['error', CobroReglas::MSG_RUTA, 404]);
    }
} catch (Throwable $e) {
    // Las lecturas de cobroModel no atrapan, a proposito: un fallo de la DB no es "no hay
    // cobros" ni "ya no existe". Aqui se responde 500 con un texto neutro; el detalle va al log.
    error_log('[cobros] ' . $metodo . ' ' . $ruta['accion'] . ': ' . $e->getMessage());
    cobrosResponderError(['error', CobroReglas::MSG_ERROR_GENERICO, 500]);
}
