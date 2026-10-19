<?php
// Modulo de Inventario — ajustes y libro de movimientos.
// Ruta: /api/inventario
//   GET  /api/inventario/ajustes                 -> lista paginada (?page,?pageSize,?motivo,?desde,?hasta)
//   GET  /api/inventario/ajustes/{id}            -> un ajuste con sus lineas
//   POST /api/inventario/ajustes                 -> crear ajuste
//   POST /api/inventario/ajustes/{id}/anular     -> anula creando el ajuste inverso
//   GET  /api/inventario/movimientos?product_id= -> kardex de un producto
//   GET  /api/inventario/valor                    -> valor del inventario por producto
//                                                    (?warehouse_id,?category_id,?estado,?hasta,?query)
//   GET  /api/inventario/configuracion           -> {bloquear_sin_existencia, productos_sin_existencia}
//                                                    (cualquier usuario autenticado)
//   PUT  /api/inventario/configuracion           -> {bloquear_sin_existencia: bool}; mismo cuerpo que
//                                                    el GET. Solo el rol admin; la empresa sale del token.
//
// El ajuste NUNCA se edita ni se borra: se anula con un ajuste inverso, y ambos
// quedan en el historial. Por eso no hay PUT ni DELETE sobre los ajustes: el
// unico PUT de este controlador es el de /configuracion.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-API-KEY, Authorization, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Allow: GET, POST, PUT, OPTIONS');
header('content-type: application/json; charset=utf-8');

// En el hosting compartido no siempre se puede leer el error_log, y un require
// fallido responde un 500 con el cuerpo vacio: imposible de diagnosticar desde
// el navegador. Se comprueba antes para devolver algo legible. El usuario ve un
// texto llano (la ruta del archivo no le dice nada y no debe salir del
// servidor); el diagnostico va al error_log y, para quien mira la respuesta en
// las herramientas del navegador, en `codigo`.
$rutaModelo = __DIR__ . '/../Models/inventoryModel.php';
if (!is_file($rutaModelo)) {
    error_log('[inventario] falta src/Models/inventoryModel.php: el despliegue del modulo de inventario quedo incompleto.');
    http_response_code(500);
    echo json_encode([
        'status' => false,
        'error' => 'El módulo de inventario no está disponible en este momento. Avisa a soporte.',
        'codigo' => 'INVENTARIO_MODELO_FALTANTE',
    ]);
    return;
}
require_once $rutaModelo;
require_once __DIR__ . '/../Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../Middleware/AuditMiddleware.php';
require_once __DIR__ . '/../MasterDatabase.php';

$inventoryModel = new inventoryModel();
$auth = new AuthMiddleware();

$authUserId = null;
if ($_SERVER['REQUEST_METHOD'] !== 'OPTIONS') {
    $validation = $auth->validateRequest();
    if (!$validation['valid']) {
        $auth->sendUnauthorized($validation['message']);
    }
    $authUserId = $validation['user_id'] ?? null;
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$esAjustes = (bool) preg_match('#/inventario/ajustes#', $path);
$esMovimientos = (bool) preg_match('#/inventario/movimientos#', $path);
$esValor = (bool) preg_match('#/inventario/valor#', $path);
$esConfiguracion = (bool) preg_match('#/inventario/configuracion/?$#', $path);
$ajusteId = preg_match('#/inventario/ajustes/(\d+)#', $path, $m) ? (int) $m[1] : null;
$esAnular = (bool) preg_match('#/inventario/ajustes/\d+/anular$#', $path);

function invBody(): array
{
    $data = InputSanitizer::jsonInput();
    return is_array($data) ? $data : [];
}

function invRespond(bool $ok, $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($ok ? ['status' => true, 'data' => $payload] : ['status' => false, 'error' => $payload]);
}

/**
 * HTTP de la respuesta de crearAjuste/anularAjuste: 201 si se creo; el que
 * traiga el modelo (422 = un dato mal escrito, p. ej. 1,5 en un producto por
 * unidades); si no, 400 como hasta ahora.
 */
function invCodigo(array $res): int
{
    if ($res[0] === 'success') {
        return 201;
    }
    return isset($res[2]) && is_int($res[2]) ? $res[2] : 400;
}

/** page/pageSize comunes, con los mismos topes que el resto del API. */
function invPaginacion(): array
{
    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int) $_GET['page'] : 1;
    $pageSize = isset($_GET['pageSize']) && is_numeric($_GET['pageSize']) && $_GET['pageSize'] > 0
        ? min(100, (int) $_GET['pageSize'])
        : 20;
    return [$page, $pageSize, ($page - 1) * $pageSize];
}

/**
 * Lo que devuelven GET y PUT /configuracion: el interruptor de la empresa y
 * cuantos productos rechazaria hoy (para que el admin vea el efecto antes de
 * encenderlo). Las cifras van como numero, no como texto.
 *
 * Si el conteo falla, la excepcion sale (un GET sin cifra es un error de verdad)
 * salvo con $tolerarFalloDelConteo: el PUT ya guardo y audito el cambio cuando
 * arma la respuesta, y un 500 por una cifra de apoyo le diria al admin que no se
 * aplico algo que si se aplico. Ahi el conteo cae a 0 (sigue siendo un numero) y
 * el fallo va al error_log.
 */
function invConfiguracionPayload(inventoryModel $modelo, bool $bloquear, bool $tolerarFalloDelConteo = false): array
{
    try {
        $sinExistencia = $modelo->contarSinExistencia();
    } catch (Throwable $e) {
        if (!$tolerarFalloDelConteo) {
            throw $e;
        }
        error_log('[inventario] no se pudo contar los productos sin existencia (el cambio de configuracion ya estaba guardado): ' . $e->getMessage());
        $sinExistencia = 0;
    }
    return [
        'bloquear_sin_existencia' => $bloquear,
        'productos_sin_existencia' => $sinExistencia,
    ];
}

/**
 * GET/PUT /api/inventario/configuracion: el interruptor «Bloquear ventas sin
 * existencia» de la empresa (master.tenants.inventario_estricto, migracion
 * master 013).
 *
 *  - GET: cualquier usuario autenticado.
 *  - PUT {bloquear_sin_existencia: bool}: solo el rol admin (por nombre de rol,
 *    como la bitacora), tambien con PERMISSIONS_ENFORCE apagado. El rol se revisa
 *    antes de tocar la base; un intento de otro rol queda anotado como acceso
 *    denegado. El cuerpo debe traer un booleano de verdad (true/false), no
 *    "true" ni 1.
 *  - 409 en una instalacion sin empresa en el token (el interruptor vive en
 *    master.tenants), igual que /api/branding. La empresa sale SIEMPRE del
 *    token: ni el cuerpo ni la URL pueden elegir otra.
 *
 * El PUT responde lo mismo que el GET, leyendo la fila de master DE NUEVO: la
 * empresa resuelta al validar el token queda vieja dentro de este request. Si
 * la base falla (p. ej. la migracion 013 sin correr) o la lectura de vuelta no
 * coincide con lo pedido, responde 500 en vez de decir que se guardo. Al reves,
 * si el cambio ya se guardo y se audito y solo falla el CONTEO de productos de la
 * respuesta, responde 200 con productos_sin_existencia en 0 (el fallo va al log):
 * no se le dice al admin que fallo algo que se aplico.
 */
function invConfiguracion(inventoryModel $modelo, AuthMiddleware $auth, array $validation): void
{
    $metodo = $_SERVER['REQUEST_METHOD'];
    if ($metodo !== 'GET' && $metodo !== 'PUT') {
        invRespond(false, 'Esta acción no está disponible.', 405);
        return;
    }
    if (($validation['user_id'] ?? null) === null) {
        $auth->sendForbidden('Para hacer esto necesitas iniciar sesión con tu usuario.');
        return;
    }
    $rol = (string) ($validation['role'] ?? '');
    if ($metodo === 'PUT' && !inventoryModel::rolPuedeConfigurar($rol)) {
        AuditMiddleware::logAccessDenied(
            'inventario',
            'rol distinto de admin',
            ['ruta' => 'inventario/configuracion', 'metodo' => $metodo, 'rol' => $rol, 'bloqueado' => true],
            'Acceso denegado a la configuración del inventario: solo el rol admin puede cambiarla.'
        );
        $auth->sendForbidden('Solo el administrador de la empresa puede cambiar esta opción.');
        return;
    }
    $tenantId = isset($validation['tenant_id']) && $validation['tenant_id'] !== null
        ? (int) $validation['tenant_id']
        : null;
    if ($tenantId === null) {
        invRespond(false, 'El bloqueo de ventas sin existencia no está disponible en esta instalación.', 409);
        return;
    }

    if ($metodo === 'GET') {
        invRespond(true, invConfiguracionPayload($modelo, TenantResolver::inventarioEstricto()));
        return;
    }

    $activo = invBody()['bloquear_sin_existencia'] ?? null;
    if (!is_bool($activo)) {
        invRespond(false, 'Indica si quieres bloquear las ventas sin existencia: sí o no.', 422);
        return;
    }
    $fallo = 'No se pudo guardar el cambio. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.';
    $antes = TenantResolver::inventarioEstricto();
    try {
        $master = MasterDatabase::getInstance();
        $master->setInventarioEstricto($tenantId, $activo);
        $tenant = $master->getTenantById($tenantId);
    } catch (Throwable $e) {
        error_log('[inventario] no se pudo guardar inventario_estricto de la empresa ' . $tenantId . ': ' . $e->getMessage());
        invRespond(false, $fallo, 500);
        return;
    }
    $despues = $tenant !== null
        && ($tenant['tipo'] ?? 'app') === 'app'
        && (int) ($tenant['inventario_estricto'] ?? 0) === 1;
    if ($despues !== $activo) {
        error_log('[inventario] inventario_estricto de la empresa ' . $tenantId . ' no quedo como se pidio (' . ($activo ? '1' : '0') . ')');
        invRespond(false, $fallo, 500);
        return;
    }
    AuditLogger::log([
        'module' => 'inventario', 'action' => 'INVENTARIO_CONFIG',
        'entity_type' => 'inventario_config', 'entity_id' => $tenantId,
        'old_values' => ['bloquear_sin_existencia' => $antes],
        'new_values' => ['bloquear_sin_existencia' => $despues],
        'description' => $despues
            ? 'Bloqueo de ventas sin existencia activado.'
            : 'Bloqueo de ventas sin existencia desactivado.',
    ]);
    // El cambio ya esta guardado y auditado: un conteo caido no lo deshace ni debe
    // responderse como un 500 (el tercer argumento lo tolera).
    invRespond(true, invConfiguracionPayload($modelo, $despues, true));
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    return;
}

// La configuracion tiene su propia ruta y su propio control de rol: no pasa por
// el switch de los ajustes, asi un PUT sobre cualquier otra ruta de este
// controlador sigue siendo un 405.
if ($esConfiguracion) {
    invConfiguracion($inventoryModel, $auth, $validation);
    return;
}

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        if ($esMovimientos) {
            $productId = isset($_GET['product_id']) ? (int) $_GET['product_id'] : 0;
            if ($productId <= 0) {
                invRespond(false, 'Elige un producto para ver sus movimientos.', 422);
                break;
            }
            [$page, $pageSize, $offset] = invPaginacion();
            $res = $inventoryModel->kardex($productId, $offset, $pageSize);
            echo json_encode([
                'status' => true,
                'data' => $res['items'],
                'pagination' => [
                    'page' => $page, 'pageSize' => $pageSize, 'total' => $res['total'],
                    'totalPages' => (int) ceil($res['total'] / $pageSize),
                ],
            ]);
            break;
        }

        if ($esValor) {
            $estado = strtolower(trim((string) ($_GET['estado'] ?? 'activos')));
            if (!in_array($estado, ['activos', 'inactivos', 'todos'], true)) {
                invRespond(false, 'El filtro de estado no es válido. Elige una opción de la lista.', 422);
                break;
            }
            $hasta = trim((string) ($_GET['hasta'] ?? ''));
            if ($hasta !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
                invRespond(false, 'La fecha «Hasta» no es válida. Elígela en el calendario.', 422);
                break;
            }
            [$page, $pageSize, $offset] = invPaginacion();
            $res = $inventoryModel->valorInventario($offset, $pageSize, [
                'query'        => trim((string) ($_GET['query'] ?? '')),
                'warehouse_id' => isset($_GET['warehouse_id']) ? (int) $_GET['warehouse_id'] : null,
                'category_id'  => isset($_GET['category_id']) ? (int) $_GET['category_id'] : null,
                'estado'       => $estado,
                'hasta'        => $hasta,
            ]);
            echo json_encode([
                'status' => true,
                'data' => $res['items'],
                'totales' => $res['totales'],
                'hasta' => $res['hasta'],
                'pagination' => [
                    'page' => $page, 'pageSize' => $pageSize, 'total' => $res['total'],
                    'totalPages' => (int) ceil($res['total'] / $pageSize),
                ],
            ]);
            break;
        }

        if (!$esAjustes) {
            invRespond(false, 'Esta opción del inventario no existe.', 404);
            break;
        }

        if ($ajusteId !== null) {
            $ajuste = $inventoryModel->getAjuste($ajusteId);
            if (!$ajuste) {
                invRespond(false, 'Ajuste no encontrado', 404);
                break;
            }
            invRespond(true, $ajuste);
            break;
        }

        [$page, $pageSize, $offset] = invPaginacion();
        $res = $inventoryModel->listarAjustes($offset, $pageSize, [
            'motivo' => $_GET['motivo'] ?? null,
            'desde' => $_GET['desde'] ?? null,
            'hasta' => $_GET['hasta'] ?? null,
        ]);
        echo json_encode([
            'status' => true,
            'data' => $res['items'],
            'pagination' => [
                'page' => $page, 'pageSize' => $pageSize, 'total' => $res['total'],
                'totalPages' => (int) ceil($res['total'] / $pageSize),
            ],
        ]);
        break;

    case 'POST':
        if (!$esAjustes) {
            invRespond(false, 'Esta opción del inventario no existe.', 404);
            break;
        }

        if ($esAnular && $ajusteId !== null) {
            $body = invBody();
            $res = $inventoryModel->anularAjuste($ajusteId, [
                'user_id' => $authUserId,
                'nota' => $body['nota'] ?? null,
            ]);
            if ($res[0] === 'success') {
                AuditLogger::log([
                    'module' => 'inventario', 'action' => 'AJUSTE_ANULAR',
                    'entity_type' => 'inventory_adjustment', 'entity_id' => $ajusteId,
                    'new_values' => ['anulado_por' => $res[1]['id'] ?? null],
                    'description' => 'Ajuste de inventario anulado.',
                ]);
            }
            invRespond($res[0] === 'success', $res[1], invCodigo($res));
            break;
        }

        // Campos explicitos, no el body crudo: 'anula_a_id' lo pone SOLO
        // anularAjuste. Si el cliente pudiera enviarlo se fabricaria un ajuste con
        // motivo ANULACION que no anula nada, y 'user_id' dejaria de ser el del
        // token, que es lo unico que hace fiable la auditoria.
        $body = invBody();
        $res = $inventoryModel->crearAjuste([
            'motivo' => $body['motivo'] ?? null,
            'nota' => $body['nota'] ?? null,
            'warehouse_id' => $body['warehouse_id'] ?? null,
            'lineas' => $body['lineas'] ?? [],
            'user_id' => $authUserId,
        ]);
        if ($res[0] === 'success') {
            AuditLogger::log([
                'module' => 'inventario', 'action' => 'AJUSTE_CREAR',
                'entity_type' => 'inventory_adjustment', 'entity_id' => $res[1]['id'] ?? null,
                'new_values' => [
                    'codigo' => $res[1]['codigo'] ?? null,
                    'motivo' => $res[1]['motivo'] ?? null,
                    'lineas' => $res[1]['total_lineas'] ?? 0,
                    'valor' => $res[1]['total_valor'] ?? 0,
                ],
                'description' => 'Ajuste de inventario creado.',
            ]);
        }
        invRespond($res[0] === 'success', $res[1], invCodigo($res));
        break;

    default:
        invRespond(false, 'Esta acción no está disponible.', 405);
        break;
}
