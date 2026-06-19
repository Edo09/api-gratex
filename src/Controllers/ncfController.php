<?php
header('Access-Control-Allow-Origin: *');
header("Access-Control-Allow-Headers: X-API-KEY, Authorization, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
header("Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS");
header("Allow: GET, POST, PUT, OPTIONS");
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
        // /api/ncf/rangos -> rangos e-NCF autorizados por DGII del ambiente
        // activo, con usados/restantes/vencimiento/estado por rango.
        // Filtro opcional: ?type=E31
        if (strpos($uri, '/api/ncf/rangos') !== false) {
            $type = isset($_GET['type']) ? strtoupper(trim((string) $_GET['type'])) : null;
            $rangos = $ncfModel->listRanges(null, $type !== '' ? $type : null);
            echo json_encode([
                'status' => true,
                'data' => $rangos,
                'ambiente' => $ncfModel->resolveActiveAmbiente(),
            ]);
            break;
        }

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

    case 'POST':
        // /api/ncf/rangos -> registrar un rango autorizado por DGII.
        // Body: { type, numero_desde, numero_hasta, fecha_vencimiento (YYYY-MM-DD),
        //         no_solicitud?, no_autorizacion? }
        if (strpos($uri, '/api/ncf/rangos') !== false) {
            $body = json_decode(file_get_contents('php://input', true));
            if (!is_object($body)) {
                http_response_code(400);
                echo json_encode(['status' => false, 'error' => 'JSON body invalido']);
                break;
            }
            $type = strtoupper(trim((string) ($body->type ?? '')));
            $desde = $body->numero_desde ?? null;
            $hasta = $body->numero_hasta ?? null;
            $venc = trim((string) ($body->fecha_vencimiento ?? ''));
            if ($type === '' || !is_numeric($desde) || !is_numeric($hasta) || $venc === '') {
                http_response_code(422);
                echo json_encode(['status' => false, 'error' => 'type, numero_desde, numero_hasta y fecha_vencimiento son requeridos']);
                break;
            }
            $result = $ncfModel->registerRange(
                $type,
                (int) $desde,
                (int) $hasta,
                $venc,
                isset($body->no_solicitud) ? trim((string) $body->no_solicitud) : null,
                isset($body->no_autorizacion) ? trim((string) $body->no_autorizacion) : null
            );
            if ($result[0] === 'success') {
                AuditLogger::log([
                    'module' => 'ncf', 'action' => 'NCF_RANGE_REGISTER',
                    'entity_type' => 'ncf_rango', 'entity_id' => $type,
                    'new_values' => [
                        'type' => $type, 'numero_desde' => (int) $desde,
                        'numero_hasta' => (int) $hasta, 'fecha_vencimiento' => $venc,
                    ],
                    'description' => 'Rango NCF autorizado por DGII registrado.',
                ]);
                echo json_encode(['status' => true, 'data' => $result[1]]);
            } else {
                http_response_code(422);
                echo json_encode(['status' => false, 'error' => $result[1]]);
            }
            break;
        }
        http_response_code(404);
        echo json_encode(['status' => false, 'error' => 'Ruta POST no soportada. Use /api/ncf/rangos']);
        break;

    case 'PUT':
        // Update NCF Sequence
        if (strpos($uri, '/api/ncf/sequence') !== false) {
            $_PUT = json_decode(file_get_contents('php://input', true));
            if (isset($_PUT->current_value)) {
                $result = $ncfModel->setSequence('B01', $_PUT->current_value);
                if ($result) {
                    AuditLogger::log([
                        'module' => 'ncf', 'action' => 'NCF_SEQUENCE_UPDATE',
                        'entity_type' => 'ncf_sequence', 'entity_id' => 'B01',
                        'new_values' => ['current_value' => $_PUT->current_value],
                        'description' => 'Secuencia NCF actualizada.',
                    ]);
                }
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
                AuditLogger::log([
                    'module' => 'ncf', 'action' => 'UPDATE',
                    'entity_type' => 'factura_ncf', 'entity_id' => $_PUT->id,
                    'new_values' => ['NCF' => $_PUT->NCF],
                    'description' => 'NCF de factura actualizado.',
                ]);
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
