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
        $_POST= json_decode(file_get_contents('php://input',true));
        if(!isset($_POST->code) || is_null($_POST->code) || empty(trim($_POST->code)) || strlen($_POST->code) > 50){
            $respuesta= ['status' => false, 'error','Code must not be empty and no more than 50 characters'];
        }
        else if(!isset($_POST->amount) || is_null($_POST->amount) || empty(trim($_POST->amount)) || !is_numeric($_POST->amount)){
            $respuesta= ['status' => false, 'error','Amount must not be empty and must be a valid number'];
        }
        else if(!isset($_POST->client) || is_null($_POST->client) || empty(trim($_POST->client)) || strlen($_POST->client) > 100){
            $respuesta= ['status' => false, 'error','Client must not be empty and no more than 100 characters'];
        }
        else if(!isset($_POST->description) || is_null($_POST->description) || empty(trim($_POST->description))){
            $respuesta= ['status' => false, 'error','Description must not be empty'];
        }
        else{
            $result = $cotizacionModel->saveCotizacion($_POST->code,$_POST->amount,$_POST->client,$_POST->description);
            if($result[0] === 'success'){
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
            $respuesta= ['status' => false, 'error','Cotization ID is empty'];
        }
        else if(!isset($_PUT->code) || is_null($_PUT->code) || empty(trim($_PUT->code)) || strlen($_PUT->code) > 50){
            $respuesta= ['status' => false, 'error','Code must not be empty and no more than 50 characters'];
        }
        else if(!isset($_PUT->amount) || is_null($_PUT->amount) || empty(trim($_PUT->amount)) || !is_numeric($_PUT->amount)){
            $respuesta= ['status' => false, 'error','Amount must not be empty and must be a valid number'];
        }
        else if(!isset($_PUT->client) || is_null($_PUT->client) || empty(trim($_PUT->client)) || strlen($_PUT->client) > 100){
            $respuesta= ['status' => false, 'error','Client must not be empty and no more than 100 characters'];
        }
        else if(!isset($_PUT->description) || is_null($_PUT->description) || empty(trim($_PUT->description))){
            $respuesta= ['status' => false, 'error','Description must not be empty'];
        }
        else{
            $result = $cotizacionModel->updateCotizacion($_PUT->id,$_PUT->code,$_PUT->amount,$_PUT->client,$_PUT->description);
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
            $respuesta= ['status' => false, 'error','Cotization ID is empty'];
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