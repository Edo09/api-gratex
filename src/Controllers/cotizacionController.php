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

/**
 * Valida las lineas de una cotizacion (crear y editar usan la misma regla).
 * Devuelve el mensaje para el usuario, o null si todas estan bien. Las lineas
 * se numeran desde 1, como las ve el usuario en el formulario.
 */
function cotValidarItems(array $items): ?string
{
    require_once __DIR__ . '/../Models/unidadMedidaModel.php';
    $unidades = new unidadMedidaModel();
    foreach (array_values($items) as $index => $item) {
        $linea = $index + 1;
        if (!isset($item->description) || empty(trim($item->description))) {
            return 'La línea ' . $linea . ' no tiene descripción. Escríbela o quita esa línea.';
        }
        if (!isset($item->amount) || !is_numeric($item->amount)) {
            return 'El precio de la línea ' . $linea . ' no es válido. Revísalo.';
        }
        // Mayor que 0 y hasta 2 decimales. Antes se exigia un entero de 1 o mas
        // (cotizacion_items.quantity era INT): 0.5 m no se podia cotizar y 1.5
        // se guardaba como 2. Dos decimales y no tres porque la cotizacion se
        // convierte en e-CF, cuyo CantidadItem admite 2. Las lineas de una
        // cotizacion no llevan unidad de medida: no hay regla de fracciones.
        // Sin cantidad, o una que no es numero, cuenta como 0 (mayor que 0).
        $cantidad = isset($item->quantity) && is_numeric($item->quantity) ? (float) $item->quantity : 0.0;
        $problema = $unidades->problemaCantidad($cantidad, null, 2);
        if ($problema !== null) {
            return 'Línea ' . $linea . ': ' . mb_strtolower(mb_substr($problema, 0, 1)) . mb_substr($problema, 1);
        }
    }
    return null;
}

const COT_SIN_CLIENTE = 'Elige un cliente para la cotización.';
const COT_SIN_LINEAS = 'Agrega al menos una línea a la cotización.';
const COT_TOTAL_INVALIDO = 'El total de la cotización no es válido. Revisa los precios y las cantidades.';

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

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        // Handle PDF generation request
        if ($isPdfRequest) {
            $cotizacionId = $pdfMatches[1];
            $cotizaciones = $cotizacionModel->getCotizaciones($cotizacionId);
            if (empty($cotizaciones)) {
                // El codigo antes del echo: despues de enviar el cuerpo ya no se
                // puede cambiar si no hay buffer de salida.
                http_response_code(404);
                header('content-type: application/json; charset=utf-8');
                echo json_encode(['status' => false, 'error' => 'No encontramos esta cotización. Puede que la hayan eliminado; actualiza el listado.']);
                break;
            }
            $cotizacionData = $cotizaciones[0];
            $cotizacionData['items'] = $cotizacionModel->getCotizacionItems($cotizacionId);
            // Include PDF generator
            require_once(__DIR__ . '/../Utils/CotizacionPdfGenerator.php');
            // Generate and output PDF
            $pdf = new CotizacionPdfGenerator('P', 'mm', 'Letter');
            $pdf->setCotizacion($cotizacionData);
            $pdfContent = $pdf->generatePdf();
            // Check if user wants base64 or download
            $format = isset($_GET['format']) ? $_GET['format'] : 'download';
            if ($format === 'base64') {
                header('content-type: application/json; charset=utf-8');
                echo json_encode([
                    'status' => true,
                    'data' => [
                        'filename' => 'Cotizacion_' . $cotizacionData['code'] . '.pdf',
                        'content' => base64_encode($pdfContent),
                        'mime_type' => 'application/pdf'
                    ]
                ]);
            } else {
                // Output as PDF download
                header('Content-Type: application/pdf');
                header('Content-Disposition: attachment; filename="Cotizacion_' . $cotizacionData['code'] . '.pdf"');
                header('Content-Length: ' . strlen($pdfContent));
                echo $pdfContent;
            }
            break;
        }
        if (isset($_GET['id'])) {
            $cotizaciones = $cotizacionModel->getCotizaciones($_GET['id']);
            $respuesta = [
                'status' => true,
                'data' => $cotizaciones
            ];
        } else {
            // Pagination logic
            $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int) $_GET['page'] : 1;
            $pageSize = isset($_GET['pageSize']) && is_numeric($_GET['pageSize']) && $_GET['pageSize'] > 0 ? (int) $_GET['pageSize'] : 10;
            $query = isset($_GET['query']) ? $_GET['query'] : null;
            $offset = ($page - 1) * $pageSize;
            $cotizaciones = $cotizacionModel->getCotizacionesPaginated($offset, $pageSize, $query);
            $total = $cotizacionModel->getCotizacionesCount($query);
            $respuesta = [
                'status' => true,
                'data' => $cotizaciones,
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
        // PDF preview endpoint
        if ($isPreviewRequest) {
            $_POST = InputSanitizer::jsonInput(false);
            // Validate required fields
            if (!isset($_POST->client_id) || is_null($_POST->client_id)) {
                $respuesta = ['status' => false, 'error' => COT_SIN_CLIENTE];
            } else if (!isset($_POST->items) || !is_array($_POST->items)) {
                $respuesta = ['status' => false, 'error' => COT_SIN_LINEAS];
            } else if (!isset($_POST->total) || !is_numeric($_POST->total)) {
                $respuesta = ['status' => false, 'error' => COT_TOTAL_INVALIDO];
            } else {
                // Convert items to associative arrays
                $items = array_map(function ($item) {
                    return (array)$item;
                }, $_POST->items);
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
        $_POST = InputSanitizer::jsonInput(false);
        if (!isset($_POST->client_id) || is_null($_POST->client_id)) {
            $respuesta = ['status' => false, 'error' => COT_SIN_CLIENTE];
        } else if (!isset($_POST->items) || !is_array($_POST->items) || count($_POST->items) == 0) {
            $respuesta = ['status' => false, 'error' => COT_SIN_LINEAS];
        } else if (!isset($_POST->total) || !is_numeric($_POST->total)) {
            $respuesta = ['status' => false, 'error' => COT_TOTAL_INVALIDO];
        } else {
            $itemError = cotValidarItems($_POST->items);
            if ($itemError !== null) {
                // 422 como las demas validaciones de lineas (facturas, gastos).
                http_response_code(422);
                $respuesta = ['status' => false, 'error' => $itemError];
            } else {
                $date = isset($_POST->date) ? $_POST->date : '';
                $user_id = isset($_POST->user_id) ? $_POST->user_id : null;
                $send_email = isset($_POST->sent_email) && $_POST->sent_email === true;
                $result = $cotizacionModel->saveCotizacion($_POST->client_id, $date, $_POST->items, $_POST->total, $user_id, $send_email);
                if ($result[0] === 'success') {
                    $respuesta = ['status' => true, 'data' => $result[1]];
                    AuditLogger::log([
                        'module' => 'cotizaciones', 'action' => 'CREATE',
                        'entity_type' => 'cotizacion',
                        'entity_id' => is_array($result[1]) ? ($result[1]['id'] ?? null) : null,
                        'new_values' => $_POST, 'description' => 'Cotizacion creada.',
                    ]);
                } else {
                    $respuesta = ['status' => false, 'error' => $result[1]];
                }
            }
        }
        echo json_encode($respuesta);
        break;

    case 'PUT':
        $_PUT = InputSanitizer::jsonInput(false);
        if (!isset($_PUT->id) || is_null($_PUT->id)) {
            $respuesta = ['status' => false, 'error' => 'No se pudo identificar la cotización que quieres modificar. Ábrela de nuevo desde el listado.'];
        } else if (!isset($_PUT->client_id) || is_null($_PUT->client_id)) {
            $respuesta = ['status' => false, 'error' => COT_SIN_CLIENTE];
        } else if (!isset($_PUT->items) || !is_array($_PUT->items) || count($_PUT->items) == 0) {
            $respuesta = ['status' => false, 'error' => COT_SIN_LINEAS];
        } else if (!isset($_PUT->total) || !is_numeric($_PUT->total)) {
            $respuesta = ['status' => false, 'error' => COT_TOTAL_INVALIDO];
        } else {
            $itemError = cotValidarItems($_PUT->items);
            if ($itemError !== null) {
                http_response_code(422);
                $respuesta = ['status' => false, 'error' => $itemError];
            } else {
                $date = isset($_PUT->date) ? $_PUT->date : '';
                $user_id = isset($_PUT->user_id) ? $_PUT->user_id : null;
                $send_email = isset($_PUT->sent_email) && $_PUT->sent_email === true;
                $oldCotizacion = $cotizacionModel->getCotizaciones($_PUT->id)[0] ?? null;
                $result = $cotizacionModel->updateCotizacion($_PUT->id, $_PUT->client_id, $date, $_PUT->items, $_PUT->total, $user_id, $send_email);
                if ($result[0] === 'success') {
                    $respuesta = ['status' => true, 'data' => $result[1]];
                    AuditLogger::log([
                        'module' => 'cotizaciones', 'action' => 'UPDATE',
                        'entity_type' => 'cotizacion', 'entity_id' => $_PUT->id,
                        'old_values' => $oldCotizacion, 'new_values' => $_PUT,
                        'description' => 'Cotizacion actualizada.',
                    ]);
                } else {
                    $respuesta = ['status' => false, 'error' => $result[1]];
                }
            }
        }
        echo json_encode($respuesta);
        break;

    case 'DELETE':
        $_DELETE = InputSanitizer::jsonInput(false);
        if (!isset($_DELETE->id) || is_null($_DELETE->id)) {
            $respuesta = ['status' => false, 'error' => 'No se pudo identificar la cotización que quieres eliminar. Actualiza el listado e inténtalo de nuevo.'];
        } else {
            $oldCotizacion = $cotizacionModel->getCotizaciones($_DELETE->id)[0] ?? null;
            $result = $cotizacionModel->deleteCotizacion($_DELETE->id);
            if ($result[0] === 'success') {
                $respuesta = ['status' => true, 'data' => $result[1]];
                AuditLogger::log([
                    'module' => 'cotizaciones', 'action' => 'DELETE',
                    'entity_type' => 'cotizacion', 'entity_id' => $_DELETE->id,
                    'old_values' => $oldCotizacion, 'description' => 'Cotizacion eliminada.',
                ]);
            } else {
                $respuesta = ['status' => false, 'error' => $result[1]];
            }
        }
        echo json_encode($respuesta);
        break;
}
