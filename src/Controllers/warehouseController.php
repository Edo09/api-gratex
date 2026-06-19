<?php
header('Access-Control-Allow-Origin: *');
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: X-API-KEY, Authorization, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Allow: GET, POST, OPTIONS, PUT, DELETE");
header('content-type: application/json; charset=utf-8');

require_once(__DIR__ . '/../Models/warehouseModel.php');
require_once(__DIR__ . '/../Middleware/AuthMiddleware.php');

$warehouseModel = new warehouseModel();
$auth = new AuthMiddleware();

// Token requerido (en multi-tenant resuelve el tenant DB). El acceso al modulo
// 'warehouses' lo aplica el PermissionGate central (Router).
if ($_SERVER['REQUEST_METHOD'] !== 'OPTIONS') {
    $validation = $auth->validateRequest();
    if (!$validation['valid']) {
        $auth->sendUnauthorized($validation['message']);
    }
}

/** Valida los campos de un almacen en POST/PUT. Devuelve string de error o null. */
function validateWarehouse($w): ?string
{
    if (!isset($w->nombre) || is_null($w->nombre) || empty(trim($w->nombre)) || strlen($w->nombre) > 100) {
        return 'El nombre es obligatorio y no debe superar 100 caracteres.';
    }
    if (isset($w->descripcion) && strlen((string) $w->descripcion) > 255) {
        return 'La descripcion no debe superar 255 caracteres.';
    }
    return null;
}

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        if (isset($_GET['id'])) {
            $rows = $warehouseModel->getAll($_GET['id']);
            if (empty($rows)) {
                http_response_code(404);
                $respuesta = ['status' => false, 'error' => 'Almacen no encontrado'];
            } else {
                $respuesta = ['status' => true, 'data' => $rows[0]];
            }
        } else if (isset($_GET['page']) || isset($_GET['pageSize']) || isset($_GET['query'])) {
            $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int) $_GET['page'] : 1;
            $pageSize = isset($_GET['pageSize']) && is_numeric($_GET['pageSize']) && $_GET['pageSize'] > 0 ? (int) $_GET['pageSize'] : 10;
            $query = isset($_GET['query']) ? $_GET['query'] : null;
            $offset = ($page - 1) * $pageSize;
            $rows = $warehouseModel->getPaginated($offset, $pageSize, $query);
            $total = $warehouseModel->getCount($query);
            $respuesta = [
                'status' => true,
                'data' => $rows,
                'pagination' => [
                    'page' => $page,
                    'pageSize' => $pageSize,
                    'total' => $total,
                    'totalPages' => ceil($total / $pageSize)
                ]
            ];
        } else {
            $respuesta = ['status' => true, 'data' => $warehouseModel->getAll()];
        }
        echo json_encode($respuesta);
        break;

    case 'POST':
        $_POST = json_decode(file_get_contents('php://input', true));
        $error = validateWarehouse($_POST);
        if ($error !== null) {
            http_response_code(422);
            $respuesta = ['status' => false, 'error' => $error];
        } else {
            $result = $warehouseModel->save($_POST);
            if ($result[0] === 'success') {
                http_response_code(201);
                $respuesta = ['status' => true, 'data' => ['id' => $result[2] ?? null, 'message' => $result[1]]];
                AuditLogger::log([
                    'module' => 'warehouses', 'action' => 'CREATE',
                    'entity_type' => 'warehouse', 'entity_id' => $result[2] ?? null,
                    'new_values' => $_POST, 'description' => 'Almacen creado.',
                ]);
            } else {
                http_response_code(400);
                $respuesta = ['status' => false, 'error' => $result[1]];
            }
        }
        echo json_encode($respuesta);
        break;

    case 'PUT':
        $_PUT = json_decode(file_get_contents('php://input', true));
        if (!isset($_PUT->id) || is_null($_PUT->id) || empty(trim((string) $_PUT->id))) {
            http_response_code(422);
            $respuesta = ['status' => false, 'error' => 'Falta el id del almacen'];
        } else if (($error = validateWarehouse($_PUT)) !== null) {
            http_response_code(422);
            $respuesta = ['status' => false, 'error' => $error];
        } else {
            $oldWarehouse = $warehouseModel->getAll($_PUT->id)[0] ?? null;
            $result = $warehouseModel->update($_PUT->id, $_PUT);
            if ($result[0] === 'success') {
                $respuesta = ['status' => true, 'data' => $result[1]];
                AuditLogger::log([
                    'module' => 'warehouses', 'action' => 'UPDATE',
                    'entity_type' => 'warehouse', 'entity_id' => $_PUT->id,
                    'old_values' => $oldWarehouse, 'new_values' => $_PUT,
                    'description' => 'Almacen actualizado.',
                ]);
            } else {
                http_response_code(400);
                $respuesta = ['status' => false, 'error' => $result[1]];
            }
        }
        echo json_encode($respuesta);
        break;

    case 'DELETE':
        $_DELETE = json_decode(file_get_contents('php://input', true));
        if (!isset($_DELETE->id) || is_null($_DELETE->id) || empty(trim((string) $_DELETE->id))) {
            http_response_code(422);
            $respuesta = ['status' => false, 'error' => 'Falta el id del almacen'];
        } else {
            $oldWarehouse = $warehouseModel->getAll($_DELETE->id)[0] ?? null;
            $result = $warehouseModel->delete($_DELETE->id);
            if ($result[0] === 'success') {
                $respuesta = ['status' => true, 'data' => $result[1]];
                AuditLogger::log([
                    'module' => 'warehouses', 'action' => 'DELETE',
                    'entity_type' => 'warehouse', 'entity_id' => $_DELETE->id,
                    'old_values' => $oldWarehouse, 'description' => 'Almacen eliminado.',
                ]);
            } else {
                http_response_code(400);
                $respuesta = ['status' => false, 'error' => $result[1]];
            }
        }
        echo json_encode($respuesta);
        break;

    default:
        http_response_code(405);
        echo json_encode(['status' => false, 'error' => 'Metodo no soportado']);
}
