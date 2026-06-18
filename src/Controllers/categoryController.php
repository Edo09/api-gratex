<?php
header('Access-Control-Allow-Origin: *');
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: X-API-KEY, Authorization, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Allow: GET, POST, OPTIONS, PUT, DELETE");
header('content-type: application/json; charset=utf-8');

require_once(__DIR__ . '/../Models/categoryModel.php');
require_once(__DIR__ . '/../Middleware/AuthMiddleware.php');

$categoryModel = new categoryModel();
$auth = new AuthMiddleware();

// Token requerido (en multi-tenant resuelve el tenant DB). El acceso al modulo
// 'inventory' lo aplica el PermissionGate central (Router).
if ($_SERVER['REQUEST_METHOD'] !== 'OPTIONS') {
    $validation = $auth->validateRequest();
    if (!$validation['valid']) {
        $auth->sendUnauthorized($validation['message']);
    }
}

/** Valida los campos de una categoria en POST/PUT. Devuelve string de error o null. */
function validateCategory($c): ?string
{
    if (!isset($c->nombre) || is_null($c->nombre) || empty(trim($c->nombre)) || strlen($c->nombre) > 100) {
        return 'El nombre es obligatorio y no debe superar 100 caracteres.';
    }
    if (isset($c->descripcion) && strlen((string) $c->descripcion) > 255) {
        return 'La descripcion no debe superar 255 caracteres.';
    }
    return null;
}

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        if (isset($_GET['id'])) {
            $rows = $categoryModel->getAll($_GET['id']);
            if (empty($rows)) {
                http_response_code(404);
                $respuesta = ['status' => false, 'error' => 'Categoria no encontrada'];
            } else {
                $respuesta = ['status' => true, 'data' => $rows[0]];
            }
        } else if (isset($_GET['page']) || isset($_GET['pageSize']) || isset($_GET['query'])) {
            $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int) $_GET['page'] : 1;
            $pageSize = isset($_GET['pageSize']) && is_numeric($_GET['pageSize']) && $_GET['pageSize'] > 0 ? (int) $_GET['pageSize'] : 10;
            $query = isset($_GET['query']) ? $_GET['query'] : null;
            $offset = ($page - 1) * $pageSize;
            $rows = $categoryModel->getPaginated($offset, $pageSize, $query);
            $total = $categoryModel->getCount($query);
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
            $respuesta = ['status' => true, 'data' => $categoryModel->getAll()];
        }
        echo json_encode($respuesta);
        break;

    case 'POST':
        $_POST = json_decode(file_get_contents('php://input', true));
        $error = validateCategory($_POST);
        if ($error !== null) {
            http_response_code(422);
            $respuesta = ['status' => false, 'error' => $error];
        } else {
            $result = $categoryModel->save($_POST);
            if ($result[0] === 'success') {
                http_response_code(201);
                $respuesta = ['status' => true, 'data' => ['id' => $result[2] ?? null, 'message' => $result[1]]];
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
            $respuesta = ['status' => false, 'error' => 'Falta el id de la categoria'];
        } else if (($error = validateCategory($_PUT)) !== null) {
            http_response_code(422);
            $respuesta = ['status' => false, 'error' => $error];
        } else {
            $result = $categoryModel->update($_PUT->id, $_PUT);
            if ($result[0] === 'success') {
                $respuesta = ['status' => true, 'data' => $result[1]];
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
            $respuesta = ['status' => false, 'error' => 'Falta el id de la categoria'];
        } else {
            $result = $categoryModel->delete($_DELETE->id);
            if ($result[0] === 'success') {
                $respuesta = ['status' => true, 'data' => $result[1]];
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
