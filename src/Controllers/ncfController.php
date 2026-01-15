<?php
header('Access-Control-Allow-Origin: *');
header("Access-Control-Allow-Headers: X-API-KEY, Authorization, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
header("Access-Control-Allow-Methods: GET, PUT, OPTIONS");
header("Allow: GET, PUT, OPTIONS");
header('content-type: application/json; charset=utf-8');

require_once(__DIR__ . '/../Models/facturaModel.php');
require_once(__DIR__ . '/../Models/ncfModel.php'); // Include new model
require_once(__DIR__ . '/../Middleware/AuthMiddleware.php');

$facturaModel = new facturaModel();
$ncfModel = new ncfModel(); // Instantiate
$auth = new AuthMiddleware();

// Validate token for all requests except OPTIONS
if ($_SERVER['REQUEST_METHOD'] !== 'OPTIONS') {
    $validation = $auth->validateRequest();
    if (!$validation['valid']) {
        $auth->sendUnauthorized($validation['message']);
    }
}

// Simple router for NCF
$uri = $_SERVER['REQUEST_URI'];

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        // /api/ncf/sequence -> Get stats
        if (strpos($uri, '/api/ncf/sequence') !== false) {
            $data = $ncfModel->getCurrentSequence('B01');
            echo json_encode([
                'status' => true,
                'data' => $data
            ]);
            break;
        }

        // /api/ncf/next -> Get next NCF
        if (strpos($uri, '/api/ncf/next') !== false) {
            $next = $ncfModel->getNextNCF('B01');
            echo json_encode([
                'status' => true,
                'data' => $next
            ]);
            break;
        }

        // Legacy / Default: Get NCF by factura ID
        // Get NCF by factura ID
        if (!isset($_GET['id']) || empty(trim($_GET['id']))) {
            $respuesta = [
                'status' => false,
                'error' => 'Factura ID is required for this endpoint.'
            ];
        } else {
            $ncfData = $facturaModel->getNCF($_GET['id']);

            if ($ncfData === null) {
                $respuesta = [
                    'status' => false,
                    'error' => 'Factura not found'
                ];
                http_response_code(404);
            } else {
                $respuesta = [
                    'status' => true,
                    'data' => $ncfData
                ];
            }
        }
        echo json_encode($respuesta);
        break;

    case 'PUT':
        // Update NCF Sequence
        if (strpos($uri, '/api/ncf/sequence') !== false) {
            $_PUT = json_decode(file_get_contents('php://input', true));
            if (isset($_PUT->current_value)) {
                $result = $ncfModel->setSequence('B01', $_PUT->current_value);
                echo json_encode(['status' => $result]);
            } else {
                echo json_encode(['status' => false, 'error' => 'Missing current_value']);
            }
            break;
        }

        // Update Invoice NCF (Legacy)
        $_PUT = json_decode(file_get_contents('php://input', true));

        if (!isset($_PUT->id) || is_null($_PUT->id) || empty(trim($_PUT->id))) {
            $respuesta = [
                'status' => false,
                'error' => 'Factura ID is required'
            ];
        } else if (!isset($_PUT->NCF) || is_null($_PUT->NCF) || empty(trim($_PUT->NCF))) {
            $respuesta = [
                'status' => false,
                'error' => 'NCF value is required'
            ];
        } else {
            $result = $facturaModel->updateNCF($_PUT->id, $_PUT->NCF);

            if ($result[0] === 'success') {
                $respuesta = [
                    'status' => true,
                    'message' => 'NCF updated successfully',
                    'data' => $result[1]
                ];
            } else {
                $respuesta = [
                    'status' => false,
                    'error' => $result[1]
                ];
                http_response_code(404);
            }
        }
        echo json_encode($respuesta);
        break;

    case 'OPTIONS':
        // Handle preflight requests
        http_response_code(200);
        break;

    default:
        $respuesta = [
            'status' => false,
            'error' => 'Method not allowed. Use GET or PUT'
        ];
        http_response_code(405);
        echo json_encode($respuesta);
        break;
}
