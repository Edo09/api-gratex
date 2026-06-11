<?php
header('Access-Control-Allow-Origin: *');
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: X-API-KEY, Authorization, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Allow: GET, POST, OPTIONS, PUT, DELETE");
header('content-type: application/json; charset=utf-8');

require_once(__DIR__ . '/../Models/proveedorModel.php');
require_once(__DIR__ . '/../Middleware/AuthMiddleware.php');

$proveedorModel = new proveedorModel();
$auth = new AuthMiddleware();

// Token requerido para todo menos OPTIONS (en multi-tenant resuelve el tenant DB).
if ($_SERVER['REQUEST_METHOD'] !== 'OPTIONS') {
    $validation = $auth->validateRequest();
    if (!$validation['valid']) {
        $auth->sendUnauthorized($validation['message']);
    }
}

/** Valida los campos comunes de un proveedor en POST/PUT. Devuelve string de error o null. */
function validateProveedor($p): ?string
{
    if (!isset($p->nombre) || is_null($p->nombre) || empty(trim($p->nombre)) || strlen($p->nombre) > 150) {
        return 'Nombre must not be empty and no more than 150 characters';
    }
    if (isset($p->rnc) && $p->rnc !== null && $p->rnc !== '') {
        $digits = preg_replace('/\D/', '', (string) $p->rnc);
        if (strlen($digits) !== 9 && strlen($digits) !== 11) {
            return 'RNC must have 9 digits (RNC) or 11 (Cedula)';
        }
    }
    if (isset($p->correo) && $p->correo !== null && trim((string) $p->correo) !== ''
        && !filter_var(trim((string) $p->correo), FILTER_VALIDATE_EMAIL)) {
        return 'Correo must be a valid email';
    }
    if (isset($p->telefono) && strlen((string) $p->telefono) > 20) {
        return 'Telefono must be no more than 20 characters';
    }
    return null;
}

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        if (isset($_GET['id'])) {
            $proveedores = $proveedorModel->getProveedores($_GET['id']);
            if (empty($proveedores)) {
                http_response_code(404);
                $respuesta = ['status' => false, 'error' => 'Proveedor not found'];
            } else {
                $respuesta = ['status' => true, 'data' => $proveedores[0]];
            }
        } else if (isset($_GET['page']) || isset($_GET['pageSize']) || isset($_GET['query'])) {
            $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int) $_GET['page'] : 1;
            $pageSize = isset($_GET['pageSize']) && is_numeric($_GET['pageSize']) && $_GET['pageSize'] > 0 ? (int) $_GET['pageSize'] : 10;
            $query = isset($_GET['query']) ? $_GET['query'] : null;
            $offset = ($page - 1) * $pageSize;
            $proveedores = $proveedorModel->getProveedoresPaginated($offset, $pageSize, $query);
            $total = $proveedorModel->getProveedoresCount($query);
            $respuesta = [
                'status' => true,
                'data' => $proveedores,
                'pagination' => [
                    'page' => $page,
                    'pageSize' => $pageSize,
                    'total' => $total,
                    'totalPages' => ceil($total / $pageSize)
                ]
            ];
        } else {
            $respuesta = ['status' => true, 'data' => $proveedorModel->getProveedores()];
        }
        echo json_encode($respuesta);
        break;

    case 'POST':
        $_POST = json_decode(file_get_contents('php://input', true));
        $error = validateProveedor($_POST);
        if ($error !== null) {
            $respuesta = ['status' => false, 'error' => $error];
        } else {
            $result = $proveedorModel->saveProveedor($_POST);
            if ($result[0] === 'success') {
                $respuesta = ['status' => true, 'data' => ['id' => $result[2] ?? null, 'message' => $result[1]]];
            } else {
                $respuesta = ['status' => false, 'error' => $result[1]];
            }
        }
        echo json_encode($respuesta);
        break;

    case 'PUT':
        $_PUT = json_decode(file_get_contents('php://input', true));
        if (!isset($_PUT->id) || is_null($_PUT->id) || empty(trim((string) $_PUT->id))) {
            $respuesta = ['status' => false, 'error' => 'Proveedor ID is empty'];
        } else if (($error = validateProveedor($_PUT)) !== null) {
            $respuesta = ['status' => false, 'error' => $error];
        } else {
            $result = $proveedorModel->updateProveedor($_PUT->id, $_PUT);
            $respuesta = $result[0] === 'success'
                ? ['status' => true, 'data' => $result[1]]
                : ['status' => false, 'error' => $result[1]];
        }
        echo json_encode($respuesta);
        break;

    case 'DELETE':
        $_DELETE = json_decode(file_get_contents('php://input', true));
        if (!isset($_DELETE->id) || is_null($_DELETE->id) || empty(trim((string) $_DELETE->id))) {
            $respuesta = ['status' => false, 'error' => 'Proveedor ID is empty'];
        } else {
            $result = $proveedorModel->deleteProveedor($_DELETE->id);
            $respuesta = $result[0] === 'success'
                ? ['status' => true, 'data' => $result[1]]
                : ['status' => false, 'error' => $result[1]];
        }
        echo json_encode($respuesta);
        break;
}
