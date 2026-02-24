<?php
header('Access-Control-Allow-Origin: *');
header("Access-Control-Allow-Headers: X-API-KEY, Authorization, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Allow: GET, POST, OPTIONS, PUT, DELETE");
header('content-type: application/json; charset=utf-8');
require_once(__DIR__ . '/../Models/cotizacionModel.php');
require_once(__DIR__ . '/../Middleware/AuthMiddleware.php');

$cotizacionModel = new cotizacionModel();
$auth = new AuthMiddleware();

// Validate token for all requests except OPTIONS
if ($_SERVER['REQUEST_METHOD'] !== 'OPTIONS') {
    $validation = $auth->validateRequest();
    if (!$validation['valid']) {
        $auth->sendUnauthorized($validation['message']);
    }
}

// Check if this is a PDF request
$endpoint = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$isPdfRequest = preg_match('/\/api\/cotizaciones\/(\d+)\/pdf/', $endpoint, $pdfMatches);
// Preview PDF endpoint
$isPreviewRequest = preg_match('/\/api\/cotizaciones\/preview$/', $endpoint);

switch($_SERVER['REQUEST_METHOD']){
    case 'GET':
        // Handle PDF generation request
        if ($isPdfRequest) {
            $cotizacionId = $pdfMatches[1];
            $cotizacion = $cotizacionModel->getCotizaciones($cotizacionId);
            if (empty($cotizacion)) {
                header('content-type: application/json; charset=utf-8');
                echo json_encode(['status' => false, 'error' => 'Cotizacion not found']);
                http_response_code(404);
                break;
            }
            // Include PDF generator
            require_once(__DIR__ . '/../Utils/CotizacionPdfGenerator.php');
            // Generate and output PDF
            $pdf = new CotizacionPdfGenerator('P', 'mm', 'Letter');
            $pdf->setCotizacion($cotizacion[0]);
            $pdfContent = $pdf->generatePdf();
            // Check if user wants base64 or download
            $format = isset($_GET['format']) ? $_GET['format'] : 'download';
            if ($format === 'base64') {
                header('content-type: application/json; charset=utf-8');
                echo json_encode([
                    'status' => true,
                    'data' => [
                        'filename' => 'Cotizacion_' . $cotizacion[0]['code'] . '.pdf',
                        'content' => base64_encode($pdfContent),
                        'mime_type' => 'application/pdf'
                    ]
                ]);
            } else {
                // Output as PDF download
                header('Content-Type: application/pdf');
                header('Content-Disposition: attachment; filename="Cotizacion_' . $cotizacion[0]['code'] . '.pdf"');
                header('Content-Length: ' . strlen($pdfContent));
                echo $pdfContent;
            }
            break;
        }
        // Standard GET request for cotizaciones list or single item
        $id = isset($_GET['id']) ? $_GET['id'] : null;
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $pageSize = isset($_GET['pageSize']) ? (int)$_GET['pageSize'] : 10;
        $query = isset($_GET['query']) ? $_GET['query'] : null;
        $cotizaciones = $cotizacionModel->getCotizaciones($id, $page, $pageSize, $query);
        $respuesta = [
            'status' => true,
            'data' => $cotizaciones
        ];
        echo json_encode($respuesta);
        break;

            case 'POST':
        // PDF preview endpoint
        if ($isPreviewRequest) {
            $_POST = json_decode(file_get_contents('php://input'));
            // Validate required fields
            if (!isset($_POST->client_id) || is_null($_POST->client_id)) {
                $respuesta = ['status' => false, 'error' => 'Client ID is required'];
            } else if (!isset($_POST->items) || !is_array($_POST->items)) {
                $respuesta = ['status' => false, 'error' => 'Items must be an array'];
            } else if (!isset($_POST->total) || !is_numeric($_POST->total)) {
                $respuesta = ['status' => false, 'error' => 'Total must be a valid number'];
            } else {
                // Convert items to associative arrays
                $items = array_map(function($item) { return (array)$item; }, $_POST->items);
                // Look up client_name from clients table
                require_once(__DIR__ . '/../Models/clientModel.php');
                $clientModelInstance = new clientModel();
                $clientData = $clientModelInstance->getClients($_POST->client_id);
                $client_name = (!empty($clientData) && isset($clientData[0]['client_name'])) ? $clientData[0]['client_name'] : '';
                // Prepare a fake cotizacion array (as in getCotizaciones)
                $cotizacion = [[
                    'id' => null,
                    'code' => 'PREVIEW',
                    'date' => isset($_POST->date) ? $_POST->date : '',
                    'client_id' => $_POST->client_id,
                    'client_name' => $client_name,
                    'total' => $_POST->total,
                    'items' => $items,
                    'description' => '',
                ]];
                // Generate PDF
                require_once(__DIR__ . '/../Utils/CotizacionPdfGenerator.php');
                $pdf = new CotizacionPdfGenerator('P', 'mm', 'Letter');
                $pdf->setCotizacion($cotizacion[0]);
                $pdfContent = $pdf->generatePdf();

                // Return as base64 JSON (same as open cotizacion)
                header('content-type: application/json; charset=utf-8');
                echo json_encode([
                    'status' => true,
                    'data' => [
                        'filename' => 'Cotizacion_Preview.pdf',
                        'content' => base64_encode($pdfContent),
                        'mime_type' => 'application/pdf'
                    ]
                ]);
                return;
            }
            echo json_encode($respuesta);
            return;
        }

        // Standard Create Cotizacion
        $_POST = json_decode(file_get_contents('php://input'));
        if(!isset($_POST->client_id) || is_null($_POST->client_id)){
            $respuesta= ['status' => false, 'error' => 'Client ID is required'];
        }
        else if(!isset($_POST->items) || !is_array($_POST->items) || count($_POST->items) == 0){
            $respuesta= ['status' => false, 'error' => 'At least one item is required'];
        }
        else if(!isset($_POST->total) || !is_numeric($_POST->total)){
            $respuesta= ['status' => false, 'error' => 'Total must be a valid number'];
        }
        else{
            // Validate each item
            $itemsValid = true;
            $itemError = '';
            foreach($_POST->items as $index => $item) {
                if(!isset($item->description) || empty(trim($item->description))) {
                    $itemsValid = false;
                    $itemError = 'Item ' . ($index + 1) . ': Description is required';
                    break;
                }
                if(!isset($item->amount) || !is_numeric($item->amount)) {
                    $itemsValid = false;
                    $itemError = 'Item ' . ($index + 1) . ': Amount must be a valid number';
                    break;
                }
                if(!isset($item->quantity) || !is_numeric($item->quantity) || $item->quantity < 1) {
                    $itemsValid = false;
                    $itemError = 'Item ' . ($index + 1) . ': Quantity must be at least 1';
                    break;
                }
            }
            
            if(!$itemsValid) {
                $respuesta = ['status' => false, 'error' => $itemError];
            } else {
                $date = isset($_POST->date) ? $_POST->date : '';
                $result = $cotizacionModel->saveCotizacion($_POST->client_id, $date, $_POST->items, $_POST->total);
                if($result[0] === 'success'){
                    $respuesta = ['status' => true, 'data' => $result[1]];
                } else {
                    $respuesta = ['status' => false, 'error' => $result[1]];
                }
            }
        }
        echo json_encode($respuesta);
    break;

    case 'PUT':
        $_PUT= json_decode(file_get_contents('php://input'));
        if(!isset($_PUT->id) || is_null($_PUT->id)){
            $respuesta= ['status' => false, 'error' => 'Cotization ID is required'];
        }
        else if(!isset($_PUT->client_id) || is_null($_PUT->client_id)){
            $respuesta= ['status' => false, 'error' => 'Client ID is required'];
        }
        else if(!isset($_PUT->items) || !is_array($_PUT->items) || count($_PUT->items) == 0){
            $respuesta= ['status' => false, 'error' => 'At least one item is required'];
        }
        else if(!isset($_PUT->total) || !is_numeric($_PUT->total)){
            $respuesta= ['status' => false, 'error' => 'Total must be a valid number'];
        }
        else{
            // Validate each item
            $itemsValid = true;
            $itemError = '';
            foreach($_PUT->items as $index => $item) {
                if(!isset($item->description) || empty(trim($item->description))) {
                    $itemsValid = false;
                    $itemError = 'Item ' . ($index + 1) . ': Description is required';
                    break;
                }
                if(!isset($item->amount) || !is_numeric($item->amount)) {
                    $itemsValid = false;
                    $itemError = 'Item ' . ($index + 1) . ': Amount must be a valid number';
                    break;
                }
                if(!isset($item->quantity) || !is_numeric($item->quantity) || $item->quantity < 1) {
                    $itemsValid = false;
                    $itemError = 'Item ' . ($index + 1) . ': Quantity must be at least 1';
                    break;
                }
            }
            
            if(!$itemsValid) {
                $respuesta = ['status' => false, 'error' => $itemError];
            } else {
                $date = isset($_PUT->date) ? $_PUT->date : '';
                $result = $cotizacionModel->updateCotizacion($_PUT->id, $_PUT->client_id, $date, $_PUT->items, $_PUT->total);
                if($result[0] === 'success'){
                    $respuesta = ['status' => true, 'data' => $result[1]];
                } else {
                    $respuesta = ['status' => false, 'error' => $result[1]];
                }
            }
        }
        echo json_encode($respuesta);
    break;

    case 'DELETE':
        $_DELETE= json_decode(file_get_contents('php://input'));
        if(!isset($_DELETE->id) || is_null($_DELETE->id)){
            $respuesta= ['status' => false, 'error' => 'Cotization ID is required'];
        }
        else{
            $result = $cotizacionModel->deleteCotizacion($_DELETE->id);
            if($result[0] === 'success'){
                $respuesta = ['status' => true, 'data' => $result[1]];
            } else {
                $respuesta = ['status' => false, 'error' => $result[1]];
            }
        }
        echo json_encode($respuesta);
    break;
}