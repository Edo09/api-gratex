<?php
// Modulo de Inventario — ajustes y libro de movimientos.
// Ruta: /api/inventario
//   GET  /api/inventario/ajustes                 -> lista paginada (?page,?pageSize,?motivo,?desde,?hasta)
//   GET  /api/inventario/ajustes/{id}            -> un ajuste con sus lineas
//   POST /api/inventario/ajustes                 -> crear ajuste
//   POST /api/inventario/ajustes/{id}/anular     -> anula creando el ajuste inverso
//   GET  /api/inventario/movimientos?product_id= -> kardex de un producto
//
// El ajuste NUNCA se edita ni se borra: se anula con un ajuste inverso, y ambos
// quedan en el historial. Por eso no hay PUT ni DELETE aqui.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-API-KEY, Authorization, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Allow: GET, POST, OPTIONS');
header('content-type: application/json; charset=utf-8');

// En el hosting compartido no siempre se puede leer el error_log, y un require
// fallido responde un 500 con el cuerpo vacio: imposible de diagnosticar desde
// el navegador. Se comprueba antes para devolver algo legible.
$rutaModelo = __DIR__ . '/../Models/inventoryModel.php';
if (!is_file($rutaModelo)) {
    http_response_code(500);
    echo json_encode([
        'status' => false,
        'error' => 'Falta src/Models/inventoryModel.php en el servidor: el despliegue del modulo de inventario quedo incompleto.',
    ]);
    return;
}
require_once $rutaModelo;
require_once __DIR__ . '/../Middleware/AuthMiddleware.php';

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

/** page/pageSize comunes, con los mismos topes que el resto del API. */
function invPaginacion(): array
{
    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int) $_GET['page'] : 1;
    $pageSize = isset($_GET['pageSize']) && is_numeric($_GET['pageSize']) && $_GET['pageSize'] > 0
        ? min(100, (int) $_GET['pageSize'])
        : 20;
    return [$page, $pageSize, ($page - 1) * $pageSize];
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    return;
}

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        if ($esMovimientos) {
            $productId = isset($_GET['product_id']) ? (int) $_GET['product_id'] : 0;
            if ($productId <= 0) {
                invRespond(false, 'product_id requerido', 422);
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

        if (!$esAjustes) {
            invRespond(false, 'Sub-ruta no encontrada. Use /inventario/ajustes o /inventario/movimientos.', 404);
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
            invRespond(false, 'Sub-ruta no encontrada. Use /inventario/ajustes.', 404);
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
            invRespond($res[0] === 'success', $res[1], $res[0] === 'success' ? 201 : 400);
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
        invRespond($res[0] === 'success', $res[1], $res[0] === 'success' ? 201 : 400);
        break;

    default:
        invRespond(false, 'Metodo no soportado', 405);
        break;
}
