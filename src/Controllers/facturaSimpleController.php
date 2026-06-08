<?php
// CRUD de facturas NO electronicas (no e-CF).
// Ruta: /api/facturas-simples
//   GET    /api/facturas-simples              -> lista paginada (?page,?pageSize,?query)
//   GET    /api/facturas-simples/{id}          -> una factura con sus lineas
//   GET    /api/facturas-simples?id={id}       -> idem
//   GET    /api/facturas-simples/{id}/pdf      -> PDF de la factura guardada (?format=download|base64)
//   POST   /api/facturas-simples              -> crear
//   POST   /api/facturas-simples/preview      -> PDF previo sin guardar (?format=download|base64)
//   PUT    /api/facturas-simples/{id}          -> actualizar (id tambien valido en el body)
//   DELETE /api/facturas-simples/{id}          -> eliminar (id tambien valido en el body)
//
// Una "factura simple" es una factura interna que NO se emitio a la DGII
// (tipo_ecf IS NULL). El modelo nunca toca un e-CF emitido por esta via.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-API-KEY, Authorization, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE');
header('Allow: GET, POST, OPTIONS, PUT, DELETE');
header('content-type: application/json; charset=utf-8');

require_once __DIR__ . '/../Models/facturaModel.php';
require_once __DIR__ . '/../Models/clientModel.php';
require_once __DIR__ . '/../Middleware/AuthMiddleware.php';

$facturaModel = new facturaModel();
$clientModel = new clientModel();
$auth = new AuthMiddleware();

$authUserId = null;
if ($_SERVER['REQUEST_METHOD'] !== 'OPTIONS') {
    $validation = $auth->validateRequest();
    if (!$validation['valid']) {
        $auth->sendUnauthorized($validation['message']);
    }
    $authUserId = $validation['user_id'] ?? null;
}

// Id opcional desde la ruta /facturas-simples/{id}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$pathId = preg_match('#/facturas-simples/(\d+)#', $path, $m) ? (int) $m[1] : null;
// POST /api/facturas-simples/preview -> PDF previo (sin guardar). "preview" no
// es numerico, asi que nunca colisiona con la ruta /{id}.
$isPreview = (bool) preg_match('#/facturas-simples/preview$#', $path);
// GET /api/facturas-simples/{id}/pdf -> PDF de la factura guardada.
$isPdf = (bool) preg_match('#/facturas-simples/\d+/pdf$#', $path);

function fsBody(): array
{
    $data = json_decode(file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

function fsRespond(bool $ok, $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($ok ? ['status' => true, 'data' => $payload] : ['status' => false, 'error' => $payload]);
}

/**
 * Completa client_name desde el cliente cuando se envia client_id sin nombre.
 */
function fsResolveClientName(array $body, clientModel $clientModel): array
{
    if (!empty($body['client_id']) && empty($body['client_name'])) {
        $clients = $clientModel->getClients($body['client_id']);
        if (!empty($clients)) {
            $body['client_name'] = $clients[0]['company_name'] ?? $clients[0]['client_name'] ?? '';
        }
    }
    return $body;
}

/**
 * Normaliza las lineas del body al formato que entiende FacturaPdfGenerator
 * (description/amount/quantity/subtotal/itbis_amount). Misma forma que persiste
 * facturaModel::createFacturaSimple, para que el preview se vea igual al guardado.
 */
function fsMapPreviewItems(array $items): array
{
    $mapped = [];
    foreach ($items as $raw) {
        $raw = (array) $raw;
        $quantity = (float) ($raw['quantity'] ?? $raw['cantidad'] ?? 1);
        $amount   = (float) ($raw['amount'] ?? $raw['precio_unitario'] ?? 0);
        $subtotal = isset($raw['subtotal']) && $raw['subtotal'] !== ''
            ? (float) $raw['subtotal']
            : round($quantity * $amount, 2);
        $itbis = isset($raw['itbis_amount']) && $raw['itbis_amount'] !== ''
            ? (float) $raw['itbis_amount']
            : 0.0;
        $mapped[] = [
            'description'  => (string) ($raw['description'] ?? $raw['descripcion'] ?? ''),
            'amount'       => $amount,
            'quantity'     => $quantity,
            'subtotal'     => $subtotal,
            'itbis_amount' => $itbis,
        ];
    }
    return $mapped;
}

/**
 * POST /api/facturas-simples/preview
 * Genera el PDF de una factura simple desde el body, SIN guardarla. Como no es
 * un e-CF (tipo_ecf null, sin e_ncf) el generador estampa el timbre "PREVIEW -
 * Sin validez fiscal". Devuelve base64 por defecto, o el PDF crudo con
 * ?format=download.
 */
function fsHandlePreview(clientModel $clientModel): void
{
    $body = fsBody();

    if (empty($body['client_id']) && empty($body['client_name'])) {
        fsRespond(false, 'client_id o client_name requerido', 422);
        return;
    }
    if (!isset($body['items']) || !is_array($body['items']) || count($body['items']) === 0) {
        fsRespond(false, 'items debe ser un arreglo con al menos un elemento', 422);
        return;
    }

    // Si llega client_id se completan los datos desde la BD; si no, se arma uno
    // minimo con client_name (igual que el create de simples acepta ambos).
    $client = [];
    if (!empty($body['client_id'])) {
        $clients = $clientModel->getClients($body['client_id']);
        if (!empty($clients)) {
            $client = $clients[0];
        }
    }

    $items = fsMapPreviewItems($body['items']);
    $total = isset($body['total']) && $body['total'] !== ''
        ? (float) $body['total']
        : array_reduce($items, static fn($c, $it) => $c + $it['subtotal'] + $it['itbis_amount'], 0.0);

    $factura = [
        'no_factura'   => $body['no_factura'] ?? 'PREVIEW',
        'NCF'          => $body['NCF'] ?? null,  // NCF tradicional (no e-CF), opcional
        'tipo_ecf'     => null,                  // factura simple: nunca e-CF
        'date'         => $body['date'] ?? date('Y-m-d'),
        'total'        => round($total, 2),
        'client_id'    => $body['client_id'] ?? null,
        'client_name'  => $body['client_name'] ?? ($client['client_name'] ?? ''),
        'company_name' => $client['company_name'] ?? ($body['client_name'] ?? null),
        'items'        => $items,
    ];

    require_once __DIR__ . '/../Utils/FacturaPdfGenerator.php';
    $pdf = new FacturaPdfGenerator('P', 'mm', 'Letter');
    $pdf->setNoElectronica(true);  // diseño NCF, sin timbre/etiquetas de e-CF
    $pdf->setFactura($factura);
    if (!empty($client)) {
        $pdf->setClientData($client);
    }
    $pdfContent = $pdf->generatePdf();

    $format = $_GET['format'] ?? $body['format'] ?? 'base64';
    if ($format === 'download') {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="Preview_factura_simple.pdf"');
        header('Content-Length: ' . strlen($pdfContent));
        echo $pdfContent;
        return;
    }

    fsRespond(true, [
        'filename'  => 'Preview_factura_simple.pdf',
        'content'   => base64_encode($pdfContent),
        'mime_type' => 'application/pdf',
    ]);
}

/**
 * GET /api/facturas-simples/{id}/pdf
 * Genera el PDF de una factura simple YA guardada. Misma factura que persiste
 * el create (tipo_ecf null), con diseño NCF y sin etiquetas de e-CF. Devuelve el
 * PDF crudo con ?format=download, o base64 por defecto.
 */
function fsHandlePdf(int $id, facturaModel $facturaModel, clientModel $clientModel): void
{
    $factura = $facturaModel->getFacturaSimple($id);
    if ($factura === null) {
        fsRespond(false, 'Factura no encontrada', 404);
        return;
    }

    // getFacturaSimple ya trae los items en la forma que entiende el generador
    // (description/amount/quantity/subtotal). Se completan los datos del cliente
    // desde la BD para el encabezado (rnc/telefono/email/direccion), igual que el
    // preview cuando llega client_id.
    $client = [];
    if (!empty($factura['client_id'])) {
        $clients = $clientModel->getClients($factura['client_id']);
        if (!empty($clients)) {
            $client = $clients[0];
        }
    }

    require_once __DIR__ . '/../Utils/FacturaPdfGenerator.php';
    $pdf = new FacturaPdfGenerator('P', 'mm', 'Letter');
    $pdf->setNoElectronica(true);  // diseño NCF, sin timbre/etiquetas de e-CF
    $pdf->setFactura($factura);
    if (!empty($client)) {
        $pdf->setClientData($client);
    }
    $pdfContent = $pdf->generatePdf();

    $filename = 'Factura_' . ($factura['no_factura'] ?? $id) . '.pdf';
    $format = $_GET['format'] ?? 'base64';
    if ($format === 'download') {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdfContent));
        echo $pdfContent;
        return;
    }

    fsRespond(true, [
        'filename'  => $filename,
        'content'   => base64_encode($pdfContent),
        'mime_type' => 'application/pdf',
    ]);
}

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        if ($isPdf && $pathId !== null) {
            fsHandlePdf($pathId, $facturaModel, $clientModel);
            break;
        }
        $id = $pathId ?? (isset($_GET['id']) && is_numeric($_GET['id']) ? (int) $_GET['id'] : null);
        if ($id !== null) {
            $factura = $facturaModel->getFacturaSimple($id);
            if ($factura === null) {
                fsRespond(false, 'Factura no encontrada', 404);
                break;
            }
            fsRespond(true, $factura);
            break;
        }
        $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int) $_GET['page'] : 1;
        $pageSize = isset($_GET['pageSize']) && is_numeric($_GET['pageSize']) && $_GET['pageSize'] > 0 ? (int) $_GET['pageSize'] : 10;
        $query = $_GET['query'] ?? null;
        $offset = ($page - 1) * $pageSize;
        $facturas = $facturaModel->getFacturasSimplesPaginated($offset, $pageSize, $query);
        $total = $facturaModel->getFacturasSimplesCount($query);
        echo json_encode([
            'status' => true,
            'data' => $facturas,
            'pagination' => [
                'page' => $page,
                'pageSize' => $pageSize,
                'total' => $total,
                'totalPages' => (int) ceil($total / $pageSize),
            ],
        ]);
        break;

    case 'POST':
        if ($isPreview) {
            fsHandlePreview($clientModel);
            break;
        }
        $body = fsBody();
        // no_factura NO se espera del front: el backend lo genera (ver
        // facturaModel::createFacturaSimple -> nextSimpleFacturaNumber).
        if (empty($body['client_id']) && empty($body['client_name'])) {
            fsRespond(false, 'client_id o client_name requerido', 422);
            break;
        }
        if (!isset($body['items']) || !is_array($body['items']) || count($body['items']) === 0) {
            fsRespond(false, 'items debe ser un arreglo con al menos un elemento', 422);
            break;
        }
        $userId = $authUserId ?? ($body['user_id'] ?? null);
        if (!$userId) {
            fsRespond(false, 'No se pudo determinar el usuario del token', 401);
            break;
        }
        $body = fsResolveClientName($body, $clientModel);
        $body['user_id'] = $userId;

        $result = $facturaModel->createFacturaSimple($body);
        fsRespond($result[0] === 'success', $result[1], $result[0] === 'success' ? 201 : 400);
        break;

    case 'PUT':
        $body = fsBody();
        $id = $pathId ?? (isset($body['id']) && is_numeric($body['id']) ? (int) $body['id'] : null);
        if (!$id) {
            fsRespond(false, 'id requerido (en la ruta o el body)', 422);
            break;
        }
        if (isset($body['items']) && (!is_array($body['items']) || count($body['items']) === 0)) {
            fsRespond(false, 'items, si se envia, debe ser un arreglo con al menos un elemento', 422);
            break;
        }
        $body = fsResolveClientName($body, $clientModel);

        $result = $facturaModel->updateFacturaSimple($id, $body);
        $code = $result[0] === 'success' ? 200 : ($result[1] === 'Factura no encontrada' ? 404 : 400);
        fsRespond($result[0] === 'success', $result[1], $code);
        break;

    case 'DELETE':
        $body = fsBody();
        $id = $pathId ?? (isset($body['id']) && is_numeric($body['id']) ? (int) $body['id'] : null);
        if (!$id) {
            fsRespond(false, 'id requerido (en la ruta o el body)', 422);
            break;
        }
        $result = $facturaModel->deleteFacturaSimple($id);
        $code = $result[0] === 'success' ? 200 : ($result[1] === 'Factura no encontrada' ? 404 : 400);
        fsRespond($result[0] === 'success', $result[1], $code);
        break;

    default:
        fsRespond(false, 'Metodo no soportado', 405);
}
