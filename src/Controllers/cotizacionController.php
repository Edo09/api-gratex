<?php
header('Access-Control-Allow-Origin: *');
header("Access-Control-Allow-Headers: X-API-KEY, Authorization, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Allow: GET, POST, OPTIONS, PUT, DELETE");
header('content-type: application/json; charset=utf-8');
require_once(__DIR__ . '/../Models/cotizacionModel.php');
require_once(__DIR__ . '/../Middleware/AuthMiddleware.php');
require_once(__DIR__ . '/../Utils/Cotizacion/CotizacionFormatos.php');

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

/**
 * La cotizacion $id como la lee getCotizaciones(), con el mismo valor que
 * recibira el modelo (la misma fila que va a tocar), o null si no existe. Con
 * un $id == null (0, '', false) getCotizaciones() devuelve TODAS las filas, y
 * la primera seria otra cotizacion, con otro formato: ahi no hay fila.
 */
function cotFila(cotizacionModel $modelo, $id): ?array
{
    if (!is_scalar($id) || $id == null) {
        return null;
    }
    return $modelo->getCotizaciones($id)[0] ?? null;
}

/**
 * El formato que dice el cuerpo tiene que ser el que eligio el servidor. Si no,
 * la pantalla es de antes del cambio de formato (pestaña abierta, bundle viejo)
 * y guardarla con las reglas del otro formato cambiaria los montos: Gratex
 * manda precios con ITBIS y Ferreteria se lo suma encima. 409 y no se guarda
 * nada. Devuelve false si ya respondio.
 */
function cotFormatoCoincide(object $body, CotizacionFormato $formato): bool
{
    $delCuerpo = CotizacionFormatos::delCuerpo($body);
    if ($delCuerpo === $formato->nombre()) {
        return true;
    }
    // El formato del cuerpo lo escribe el usuario y cleanString deja pasar \n y
    // \t: json_encode los escapa (y las comillas), para que no pueda inventar
    // lineas en el log. Recortado: un texto de 1 MB no llena el log.
    error_log('[cotizaciones] el cuerpo dice formato ' . json_encode(mb_substr($delCuerpo, 0, 40)) . ' y toca "' . $formato->nombre() . '": 409');
    http_response_code(409);
    echo json_encode(['status' => false, 'error' => CotizacionFormatos::MSG_DESACTUALIZADA]);
    return false;
}

/**
 * Respuesta de un ['error', $mensaje, $http] de un formato. El codigo solo se
 * fija si no es 200: los errores de cabecera de Gratex responden 200 con
 * status:false, como siempre.
 */
function cotError(array $resultado): array
{
    if ($resultado[2] !== 200) {
        http_response_code($resultado[2]);
    }
    return ['status' => false, 'error' => $resultado[1]];
}

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

// El cuerpo se decodifica una sola vez. Vacio o que no es un objeto JSON queda
// como objeto vacio: los formatos reciben siempre un objeto, y cada isset()
// responde lo mismo que antes con null.
$body = InputSanitizer::jsonInput(false);
if (!is_object($body)) {
    $body = new stdClass();
}

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
            // El PDF lo dibuja el formato de la fila (sin formato = Gratex), no
            // el que tenga hoy la empresa.
            $resultado = CotizacionFormatos::para($cotizacionData['formato'] ?? null, $cotizacionModel)->pdf($cotizacionData);
            if ($resultado[0] !== 'success') {
                $respuesta = cotError($resultado);
                header('content-type: application/json; charset=utf-8');
                echo json_encode($respuesta);
                break;
            }
            $pdfContent = $resultado[1];
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
            // Con id (vista previa de una cotizacion guardada), el formato de
            // esa fila; sin id, o si ya no existe, el de la empresa.
            $row = isset($body->id) ? cotFila($cotizacionModel, $body->id) : null;
            $nombre = $row !== null ? ($row['formato'] ?? null) : CotizacionFormatos::delTenant();
            $formato = CotizacionFormatos::para($nombre, $cotizacionModel);
            if (!cotFormatoCoincide($body, $formato)) {
                return;
            }
            $resultado = $formato->preview($body, $row);
            if ($resultado[0] === 'success') {
                // Return as base64 JSON (same as open cotizacion)
                header('content-type: application/json; charset=utf-8');
                echo json_encode([
                    'status' => true,
                    'data' => [
                        'filename' => 'Cotizacion_Preview.pdf',
                        'content' => base64_encode($resultado[1]),
                        'mime_type' => 'application/pdf'
                    ]
                ]);
                return;
            }
            $respuesta = cotError($resultado);
            echo json_encode($respuesta);
            return;
        }

        // Standard Create Cotizacion: el formato de la empresa.
        $formato = CotizacionFormatos::para(CotizacionFormatos::delTenant(), $cotizacionModel);
        if (!cotFormatoCoincide($body, $formato)) {
            break;
        }
        $result = $formato->crear($body);
        if ($result[0] === 'success') {
            $respuesta = ['status' => true, 'data' => $result[1]];
            AuditLogger::log([
                'module' => 'cotizaciones', 'action' => 'CREATE',
                'entity_type' => 'cotizacion',
                'entity_id' => is_array($result[1]) ? ($result[1]['id'] ?? null) : null,
                'new_values' => $body, 'description' => 'Cotizacion creada.',
            ]);
        } else {
            $respuesta = cotError($result);
        }
        echo json_encode($respuesta);
        break;

    case 'PUT':
        if (!isset($body->id) || is_null($body->id)) {
            $respuesta = ['status' => false, 'error' => 'No se pudo identificar la cotización que quieres modificar. Ábrela de nuevo desde el listado.'];
            echo json_encode($respuesta);
            break;
        }
        // La fila primero: la valida y la guarda el formato con el que se
        // creo, no el que tenga hoy la empresa. Si ya no existe, el formato del
        // cuerpo (si se conoce), que responde su "ya no existe": Ferreteria 404,
        // Gratex el de siempre (deLaFila). Es tambien el old_values de la
        // auditoria.
        $oldCotizacion = cotFila($cotizacionModel, $body->id);
        $formato = CotizacionFormatos::para(CotizacionFormatos::deLaFila($oldCotizacion, $body), $cotizacionModel);
        if (!cotFormatoCoincide($body, $formato)) {
            break;
        }
        $result = $formato->actualizar($oldCotizacion ?? [], $body);
        if ($result[0] === 'success') {
            $respuesta = ['status' => true, 'data' => $result[1]];
            AuditLogger::log([
                'module' => 'cotizaciones', 'action' => 'UPDATE',
                'entity_type' => 'cotizacion', 'entity_id' => $body->id,
                'old_values' => $oldCotizacion, 'new_values' => $body,
                'description' => 'Cotizacion actualizada.',
            ]);
        } else {
            $respuesta = cotError($result);
        }
        echo json_encode($respuesta);
        break;

    case 'DELETE':
        if (!isset($body->id) || is_null($body->id)) {
            $respuesta = ['status' => false, 'error' => 'No se pudo identificar la cotización que quieres eliminar. Actualiza el listado e inténtalo de nuevo.'];
        } else {
            $oldCotizacion = $cotizacionModel->getCotizaciones($body->id)[0] ?? null;
            $result = $cotizacionModel->deleteCotizacion($body->id);
            if ($result[0] === 'success') {
                $respuesta = ['status' => true, 'data' => $result[1]];
                AuditLogger::log([
                    'module' => 'cotizaciones', 'action' => 'DELETE',
                    'entity_type' => 'cotizacion', 'entity_id' => $body->id,
                    'old_values' => $oldCotizacion, 'description' => 'Cotizacion eliminada.',
                ]);
            } else {
                $respuesta = ['status' => false, 'error' => $result[1]];
            }
        }
        echo json_encode($respuesta);
        break;
}
