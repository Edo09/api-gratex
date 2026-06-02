<?php
// CRUD de facturas NO electronicas (no e-CF).
// Ruta: /api/facturas-simples
//   GET    /api/facturas-simples              -> lista paginada (?page,?pageSize,?query)
//   GET    /api/facturas-simples/{id}          -> una factura con sus lineas
//   GET    /api/facturas-simples?id={id}       -> idem
//   POST   /api/facturas-simples              -> crear
//   PUT    /api/facturas-simples/{id}          -> actualizar (id tambien valido en el body)
//   DELETE /api/facturas-simples/{id}          -> eliminar (id tambien valido en el body)
//
// Una "factura simple" es una factura interna que NO se emitio a la DGII
// (tipo_ecf IS NULL). El modelo nunca toca un e-CF emitido por esta via.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-API-KEY, Authorization, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE');
header('Allow: GET, POST, OPTIONS, PUT, DELETE');
header('content-type: application/json; charset=utf-8');

require_once __DIR__ . '/../Models/facturaModel.php';
require_once __DIR__ . '/../Models/clientModel.php';
require_once __DIR__ . '/../Middleware/AuthMiddleware.php';

$facturaModel = new facturaModel();
$clientModel = new clientModel();
$auth = new AuthMiddleware();

$authUserId = null;
if ($_SERVER['REQUEST_METHOD'] !== 'OPTIONS') {
    $validation = $auth->validateRequest();
    if (!$validation['valid']) {
        $auth->sendUnauthorized($validation['message']);
    }
    $authUserId = $validation['user_id'] ?? null;
}

// Id opcional desde la ruta /facturas-simples/{id}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$pathId = preg_match('#/facturas-simples/(\d+)#', $path, $m) ? (int) $m[1] : null;

function fsBody(): array
{
    $data = json_decode(file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

function fsRespond(bool $ok, $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($ok ? ['status' => true, 'data' => $payload] : ['status' => false, 'error' => $payload]);
}

/**
 * Completa client_name desde el cliente cuando se envia client_id sin nombre.
 */
function fsResolveClientName(array $body, clientModel $clientModel): array
{
    if (!empty($body['client_id']) && empty($body['client_name'])) {
        $clients = $clientModel->getClients($body['client_id']);
        if (!empty($clients)) {
            $body['client_name'] = $clients[0]['company_name'] ?? $clients[0]['client_name'] ?? '';
        }
    }
    return $body;
}

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        $id = $pathId ?? (isset($_GET['id']) && is_numeric($_GET['id']) ? (int) $_GET['id'] : null);
        if ($id !== null) {
            $factura = $facturaModel->getFacturaSimple($id);
            if ($factura === null) {
                fsRespond(false, 'Factura no encontrada', 404);
                break;
            }
            fsRespond(true, $factura);
            break;
        }
        $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int) $_GET['page'] : 1;
        $pageSize = isset($_GET['pageSize']) && is_numeric($_GET['pageSize']) && $_GET['pageSize'] > 0 ? (int) $_GET['pageSize'] : 10;
        $query = $_GET['query'] ?? null;
        $offset = ($page - 1) * $pageSize;
        $facturas = $facturaModel->getFacturasSimplesPaginated($offset, $pageSize, $query);
        $total = $facturaModel->getFacturasSimplesCount($query);
        echo json_encode([
            'status' => true,
            'data' => $facturas,
            'pagination' => [
                'page' => $page,
                'pageSize' => $pageSize,
                'total' => $total,
                'totalPages' => (int) ceil($total / $pageSize),
            ],
        ]);
        break;

    case 'POST':
        $body = fsBody();
        if (empty($body['no_factura'])) {
            fsRespond(false, 'no_factura requerido', 422);
            break;
        }
        if (empty($body['client_id']) && empty($body['client_name'])) {
            fsRespond(false, 'client_id o client_name requerido', 422);
            break;
        }
        if (!isset($body['items']) || !is_array($body['items']) || count($body['items']) === 0) {
            fsRespond(false, 'items debe ser un arreglo con al menos un elemento', 422);
            break;
        }
        $userId = $authUserId ?? ($body['user_id'] ?? null);
        if (!$userId) {
            fsRespond(false, 'No se pudo determinar el usuario del token', 401);
            break;
        }
        $body = fsResolveClientName($body, $clientModel);
        $body['user_id'] = $userId;

        $result = $facturaModel->createFacturaSimple($body);
        fsRespond($result[0] === 'success', $result[1], $result[0] === 'success' ? 201 : 400);
        break;

    case 'PUT':
        $body = fsBody();
        $id = $pathId ?? (isset($body['id']) && is_numeric($body['id']) ? (int) $body['id'] : null);
        if (!$id) {
            fsRespond(false, 'id requerido (en la ruta o el body)', 422);
            break;
        }
        if (isset($body['items']) && (!is_array($body['items']) || count($body['items']) === 0)) {
            fsRespond(false, 'items, si se envia, debe ser un arreglo con al menos un elemento', 422);
            break;
        }
        $body = fsResolveClientName($body, $clientModel);

        $result = $facturaModel->updateFacturaSimple($id, $body);
        $code = $result[0] === 'success' ? 200 : ($result[1] === 'Factura no encontrada' ? 404 : 400);
        fsRespond($result[0] === 'success', $result[1], $code);
        break;

    case 'DELETE':
        $body = fsBody();
        $id = $pathId ?? (isset($body['id']) && is_numeric($body['id']) ? (int) $body['id'] : null);
        if (!$id) {
            fsRespond(false, 'id requerido (en la ruta o el body)', 422);
            break;
        }
        $result = $facturaModel->deleteFacturaSimple($id);
        $code = $result[0] === 'success' ? 200 : ($result[1] === 'Factura no encontrada' ? 404 : 400);
        fsRespond($result[0] === 'success', $result[1], $code);
        break;

    default:
        fsRespond(false, 'Metodo no soportado', 405);
}
