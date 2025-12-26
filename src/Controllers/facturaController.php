<?php
header('Access-Control-Allow-Origin: *');
header("Access-Control-Allow-Headers: X-API-KEY, Authorization, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Allow: GET, POST, OPTIONS, PUT, DELETE");
header('content-type: application/json; charset=utf-8');
require_once(__DIR__ . '/../Models/facturaModel.php');
require_once(__DIR__ . '/../Middleware/AuthMiddleware.php');

$facturaModel = new facturaModel();
$auth = new AuthMiddleware();

// Validate token for all requests except OPTIONS
if ($_SERVER['REQUEST_METHOD'] !== 'OPTIONS') {
    $validation = $auth->validateRequest();
    if (!$validation['valid']) {
        $auth->sendUnauthorized($validation['message']);
    }
}

switch($_SERVER['REQUEST_METHOD']){
    case 'GET':
            if (isset($_GET['id'])) {
                $facturas = $facturaModel->getFacturas($_GET['id']);
                $respuesta = [
                    'status' => true,
                    'data' => $facturas
                ];
            } else {
                // Pagination logic
                $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
                $pageSize = isset($_GET['pageSize']) && is_numeric($_GET['pageSize']) && $_GET['pageSize'] > 0 ? (int)$_GET['pageSize'] : 10;
                $offset = ($page - 1) * $pageSize;
                $facturas = $facturaModel->getFacturasPaginated($offset, $pageSize);
                $total = $facturaModel->getFacturasCount();
                $respuesta = [
                    'status' => true,
                    'data' => $facturas,
                    'pagination' => [
                        'page' => $page,
                        'pageSize' => $pageSize,
                        'total' => $total,
                        'totalPages' => ceil($total / $pageSize)
                    ]
                ];
            }
            echo json_encode($respuesta);
        break;

    case 'POST':
        $_POST = json_decode(file_get_contents('php://input', true));
        // Validate required fields for new structure
        if (!isset($_POST->date) || is_null($_POST->date) || empty(trim($_POST->date))) {
            $respuesta = ['status' => false, 'error' => 'Date must not be empty'];
        } else if (!isset($_POST->client_id) || is_null($_POST->client_id) || empty(trim($_POST->client_id))) {
            $respuesta = ['status' => false, 'error' => 'Client ID must not be empty'];
        } else if (!isset($_POST->client) || is_null($_POST->client) || empty(trim($_POST->client))) {
            $respuesta = ['status' => false, 'error' => 'Client name must not be empty'];
        } else if (!isset($_POST->items) || !is_array($_POST->items) || count($_POST->items) === 0) {
            $respuesta = ['status' => false, 'error' => 'At least one item is required'];
        } else if (!isset($_POST->ncf) || is_null($_POST->ncf) || empty(trim($_POST->ncf))) {
            $respuesta = ['status' => false, 'error' => 'NCF  must not be empty'];
        } else {
            // Calculate total
            $total = 0;
            foreach ($_POST->items as $item) {
                if (!isset($item->amount) || !isset($item->quantity) || !is_numeric($item->amount) || !is_numeric($item->quantity)) {
                    $respuesta = ['status' => false, 'error' => 'Each item must have a numeric amount and quantity'];
                    echo json_encode($respuesta);
                    return;
                }
                $total += $item->amount * $item->quantity;
            }
            // Generate no_factura (simple example, should be improved for production)
            $no_factura = 'FAC_' . date('Ymd_His');
            $result = $facturaModel->saveFacturaWithItems($no_factura, $_POST->date, $_POST->client_id, $_POST->client, $total, $_POST->ncf, $_POST->items);
            if ($result[0] === 'success') {
                $respuesta = ['status' => true, 'data' => $result[1]];
            } else {
                $respuesta = ['status' => false, 'error' => $result[1]];
            }
        }
        echo json_encode($respuesta);
        break;

    case 'PUT':
        $_PUT= json_decode(file_get_contents('php://input',true));
        if(!isset($_PUT->id) || is_null($_PUT->id) || empty(trim($_PUT->id))){
            $respuesta= ['status' => false, 'error','Factura ID is empty'];
        }
        else if(!isset($_PUT->no_factura) || is_null($_PUT->no_factura) || empty(trim($_PUT->no_factura))){
            $respuesta= ['status' => false, 'error','No Factura must not be empty'];
        }
        else if(!isset($_PUT->date) || is_null($_PUT->date) || empty(trim($_PUT->date))){
            $respuesta= ['status' => false, 'error','Date must not be empty'];
        }
        else if(!isset($_PUT->client_id) || is_null($_PUT->client_id) || empty(trim($_PUT->client_id))){
            $respuesta= ['status' => false, 'error','Client ID must not be empty'];
        }
        else if(!isset($_PUT->client_name) || is_null($_PUT->client_name) || empty(trim($_PUT->client_name))){
            $respuesta= ['status' => false, 'error','Client Name must not be empty'];
        }
        else if(!isset($_PUT->total) || is_null($_PUT->total) || !is_numeric($_PUT->total)){
            $respuesta= ['status' => false, 'error','Total must be a number'];
        }
        else if(!isset($_PUT->NCF) || is_null($_PUT->NCF) || empty(trim($_PUT->NCF))){
            $respuesta= ['status' => false, 'error','NCF must not be empty'];
        }
        else{
            $result = $facturaModel->updateFactura($_PUT->id,$_PUT->no_factura,$_PUT->date,$_PUT->client_id,$_PUT->client_name,$_PUT->total,$_PUT->NCF);
            if($result[0] === 'success'){
                $respuesta = ['status' => true, 'data' => $result[1]];
            } else {
                $respuesta = ['status' => false, 'error' => $result[1]];
            }
        }
        echo json_encode($respuesta);
    break;

    case 'DELETE':
        $_DELETE= json_decode(file_get_contents('php://input',true));
        if(!isset($_DELETE->id) || is_null($_DELETE->id) || empty(trim($_DELETE->id))){
            $respuesta= ['status' => false, 'error','Factura ID is empty'];
        }
        else{
            $result = $facturaModel->deleteFactura($_DELETE->id);
            if($result[0] === 'success'){
                $respuesta = ['status' => true, 'data' => $result[1]];
            } else {
                $respuesta = ['status' => false, 'error' => $result[1]];
            }
        }
        echo json_encode($respuesta);
    break;
}
