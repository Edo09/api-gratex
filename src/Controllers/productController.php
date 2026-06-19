<?php
header('Access-Control-Allow-Origin: *');
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Headers: X-API-KEY, Authorization, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Allow: GET, POST, OPTIONS, PUT, DELETE");
header('content-type: application/json; charset=utf-8');

require_once(__DIR__ . '/../Models/productModel.php');
require_once(__DIR__ . '/../Middleware/AuthMiddleware.php');

$productModel = new productModel();
$auth = new AuthMiddleware();

// Token requerido para todo menos OPTIONS (en multi-tenant resuelve el tenant DB).
if ($_SERVER['REQUEST_METHOD'] !== 'OPTIONS') {
    $validation = $auth->validateRequest();
    if (!$validation['valid']) {
        $auth->sendUnauthorized($validation['message']);
    }
}

/** Valida los campos comunes de un producto en POST/PUT. Devuelve string de error o null. */
function validateProduct($p): ?string
{
    if (!isset($p->nombre) || is_null($p->nombre) || empty(trim($p->nombre)) || strlen($p->nombre) > 150) {
        return 'Name must not be empty and no more than 150 characters';
    }
    if (isset($p->precio) && (!is_numeric($p->precio) || (float) $p->precio < 0)) {
        return 'Price must be a non-negative number';
    }
    if (isset($p->costo) && (!is_numeric($p->costo) || (float) $p->costo < 0)) {
        return 'Cost must be a non-negative number';
    }
    if (isset($p->indicador_facturacion) && !in_array((int) $p->indicador_facturacion, [0, 1, 2, 3, 4], true)) {
        return 'indicador_facturacion must be 0, 1, 2, 3 or 4';
    }
    if (isset($p->indicador_bien_servicio) && !in_array((int) $p->indicador_bien_servicio, [1, 2], true)) {
        return 'indicador_bien_servicio must be 1 (Bien) or 2 (Servicio)';
    }
    // Inventario: category_id opcional (nullable), warehouse_id opcional (el modelo
    // asigna "Almacén Principal" si no se envia). Si vienen, deben ser enteros > 0.
    if (isset($p->category_id) && $p->category_id !== null && $p->category_id !== ''
        && (!is_numeric($p->category_id) || (int) $p->category_id <= 0)) {
        return 'category_id must be a positive integer';
    }
    if (isset($p->warehouse_id) && $p->warehouse_id !== null && $p->warehouse_id !== ''
        && (!is_numeric($p->warehouse_id) || (int) $p->warehouse_id <= 0)) {
        return 'warehouse_id must be a positive integer';
    }
    return null;
}

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        if (isset($_GET['id'])) {
            $products = $productModel->getProducts($_GET['id']);
            if (empty($products)) {
                http_response_code(404);
                $respuesta = ['status' => false, 'error' => 'Product not found'];
            } else {
                $respuesta = ['status' => true, 'data' => $products[0]];
            }
        } else if (isset($_GET['page']) || isset($_GET['pageSize']) || isset($_GET['query'])) {
            $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int) $_GET['page'] : 1;
            $pageSize = isset($_GET['pageSize']) && is_numeric($_GET['pageSize']) && $_GET['pageSize'] > 0 ? (int) $_GET['pageSize'] : 10;
            $query = isset($_GET['query']) ? $_GET['query'] : null;
            $offset = ($page - 1) * $pageSize;
            $products = $productModel->getProductsPaginated($offset, $pageSize, $query);
            $total = $productModel->getProductsCount($query);
            $respuesta = [
                'status' => true,
                'data' => $products,
                'pagination' => [
                    'page' => $page,
                    'pageSize' => $pageSize,
                    'total' => $total,
                    'totalPages' => ceil($total / $pageSize)
                ]
            ];
        } else {
            $respuesta = ['status' => true, 'data' => $productModel->getProducts()];
        }
        echo json_encode($respuesta);
        break;

    case 'POST':
        $_POST = json_decode(file_get_contents('php://input', true));
        $error = validateProduct($_POST);
        if ($error !== null) {
            $respuesta = ['status' => false, 'error' => $error];
        } else {
            $result = $productModel->saveProduct($_POST);
            if ($result[0] === 'success') {
                $respuesta = ['status' => true, 'data' => ['id' => $result[2] ?? null, 'message' => $result[1]]];
                AuditLogger::log([
                    'module' => 'products', 'action' => 'CREATE',
                    'entity_type' => 'product', 'entity_id' => $result[2] ?? null,
                    'new_values' => $_POST, 'description' => 'Producto creado.',
                ]);
            } else {
                $respuesta = ['status' => false, 'error' => $result[1]];
            }
        }
        echo json_encode($respuesta);
        break;

    case 'PUT':
        $_PUT = json_decode(file_get_contents('php://input', true));
        if (!isset($_PUT->id) || is_null($_PUT->id) || empty(trim((string) $_PUT->id))) {
            $respuesta = ['status' => false, 'error' => 'Product ID is empty'];
        } else if (($error = validateProduct($_PUT)) !== null) {
            $respuesta = ['status' => false, 'error' => $error];
        } else {
            $oldProduct = $productModel->getProducts($_PUT->id)[0] ?? null;
            $result = $productModel->updateProduct($_PUT->id, $_PUT);
            $respuesta = $result[0] === 'success'
                ? ['status' => true, 'data' => $result[1]]
                : ['status' => false, 'error' => $result[1]];
            if ($result[0] === 'success') {
                AuditLogger::log([
                    'module' => 'products', 'action' => 'UPDATE',
                    'entity_type' => 'product', 'entity_id' => $_PUT->id,
                    'old_values' => $oldProduct, 'new_values' => $_PUT,
                    'description' => 'Producto actualizado.',
                ]);
            }
        }
        echo json_encode($respuesta);
        break;

    case 'DELETE':
        $_DELETE = json_decode(file_get_contents('php://input', true));
        if (!isset($_DELETE->id) || is_null($_DELETE->id) || empty(trim((string) $_DELETE->id))) {
            $respuesta = ['status' => false, 'error' => 'Product ID is empty'];
        } else {
            $oldProduct = $productModel->getProducts($_DELETE->id)[0] ?? null;
            $result = $productModel->deleteProduct($_DELETE->id);
            $respuesta = $result[0] === 'success'
                ? ['status' => true, 'data' => $result[1]]
                : ['status' => false, 'error' => $result[1]];
            if ($result[0] === 'success') {
                AuditLogger::log([
                    'module' => 'products', 'action' => 'DELETE',
                    'entity_type' => 'product', 'entity_id' => $_DELETE->id,
                    'old_values' => $oldProduct, 'description' => 'Producto eliminado.',
                ]);
            }
        }
        echo json_encode($respuesta);
        break;
}
