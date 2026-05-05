<?php

header('Access-Control-Allow-Origin: *');
header("Access-Control-Allow-Headers: X-API-KEY, Authorization, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Allow: GET, POST, OPTIONS");
header('content-type: application/json; charset=utf-8');

require_once(__DIR__ . '/../Middleware/AuthMiddleware.php');
require_once(__DIR__ . '/../Utils/FacturacionElectronica/DgiiAuthService.php');

$auth = new AuthMiddleware();
$dgiiAuthService = new DgiiAuthService();

if ($_SERVER['REQUEST_METHOD'] !== 'OPTIONS') {
    $validation = $auth->validateRequest();
    if (!$validation['valid']) {
        $auth->sendUnauthorized($validation['message']);
    }
}

$endpoint = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$isSemillaRequest = preg_match('/\/api\/facturacion-electronica\/autenticacion\/semilla$/i', $endpoint);
$isTokenRequest = preg_match('/\/api\/facturacion-electronica\/autenticacion\/token$/i', $endpoint)
    || preg_match('/\/api\/facturacion-electronica\/autenticacion$/i', $endpoint);
$isValidarSemillaRequest = preg_match('/\/api\/facturacion-electronica\/autenticacion\/validar-semilla$/i', $endpoint);

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        if (!$isSemillaRequest) {
            sendFacturacionElectronicaJson(['status' => false, 'error' => 'Endpoint not found'], 404);
            break;
        }

        try {
            $options = facturacionElectronicaRequestOptions();
            $semilla = $dgiiAuthService->obtenerSemilla($options);

            header('Content-Type: application/xml; charset=utf-8');
            http_response_code(200);
            echo $semilla['xml'];
        } catch (Throwable $e) {
            sendFacturacionElectronicaJson(['status' => false, 'error' => $e->getMessage()], 502);
        }
        break;

    case 'POST':
        try {
            $options = facturacionElectronicaRequestOptions();

            if ($isTokenRequest) {
                $token = $dgiiAuthService->autenticar($options);
                sendFacturacionElectronicaJson(['status' => true, 'data' => $token]);
                break;
            }

            if ($isValidarSemillaRequest) {
                $xmlInput = facturacionElectronicaXmlInput($options);
                if ($xmlInput === '') {
                    sendFacturacionElectronicaJson([
                        'status' => false,
                        'error' => 'Send semilla_xml, signed_xml, raw XML body, or multipart field xml.'
                    ], 400);
                    break;
                }

                $signedXml = facturacionElectronicaLooksSigned($xmlInput)
                    ? $xmlInput
                    : $dgiiAuthService->firmarSemilla($xmlInput, $options);

                $token = $dgiiAuthService->validarSemillaFirmada($signedXml, $options);
                sendFacturacionElectronicaJson(['status' => true, 'data' => $token]);
                break;
            }

            sendFacturacionElectronicaJson(['status' => false, 'error' => 'Endpoint not found'], 404);
        } catch (Throwable $e) {
            sendFacturacionElectronicaJson(['status' => false, 'error' => $e->getMessage()], 502);
        }
        break;

    case 'OPTIONS':
        http_response_code(200);
        break;

    default:
        sendFacturacionElectronicaJson([
            'status' => false,
            'error' => 'Method not allowed. Use GET or POST'
        ], 405);
        break;
}

function facturacionElectronicaRequestOptions(): array
{
    $payload = facturacionElectronicaJsonPayload();
    $options = is_array($payload) ? $payload : [];

    foreach ($_POST as $key => $value) {
        if ($value !== '') {
            $options[$key] = $value;
        }
    }

    foreach (['environment', 'ambiente', 'base_url', 'timeout'] as $key) {
        if (isset($_GET[$key]) && $_GET[$key] !== '') {
            $options[$key] = $_GET[$key];
        }
    }

    return $options;
}

function facturacionElectronicaJsonPayload(): array
{
    static $payload = null;

    if ($payload !== null) {
        return $payload;
    }

    $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') === false) {
        $payload = [];
        return $payload;
    }

    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        $payload = [];
        return $payload;
    }

    $decoded = json_decode($raw, true);
    $payload = is_array($decoded) ? $decoded : [];

    return $payload;
}

function facturacionElectronicaXmlInput(array $options): string
{
    if (!empty($options['signed_xml'])) {
        return (string)$options['signed_xml'];
    }

    if (!empty($options['semilla_xml'])) {
        return (string)$options['semilla_xml'];
    }

    if (!empty($_POST['signed_xml'])) {
        return (string)$_POST['signed_xml'];
    }

    if (!empty($_POST['semilla_xml'])) {
        return (string)$_POST['semilla_xml'];
    }

    if (isset($_FILES['xml']) && is_uploaded_file($_FILES['xml']['tmp_name'])) {
        $content = file_get_contents($_FILES['xml']['tmp_name']);
        return $content === false ? '' : $content;
    }

    $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'xml') !== false || stripos($contentType, 'text/plain') !== false) {
        $raw = file_get_contents('php://input');
        return $raw === false ? '' : $raw;
    }

    return '';
}

function facturacionElectronicaLooksSigned(string $xml): bool
{
    return stripos($xml, '<Signature') !== false
        && stripos($xml, '<SignatureValue') !== false
        && stripos($xml, '<X509Certificate') !== false;
}

function sendFacturacionElectronicaJson(array $payload, int $statusCode = 200): void
{
    header('content-type: application/json; charset=utf-8');
    http_response_code($statusCode);
    echo json_encode($payload);
}
