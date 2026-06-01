<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Origin, X-Requested-With, Content-Type, Accept');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

require_once __DIR__ . '/../Models/authSeedModel.php';
require_once __DIR__ . '/../Utils/FacturacionElectronica/IncomingXmlValidator.php';
require_once __DIR__ . '/../Utils/FacturacionElectronica/IncomingXmlExtractor.php';

$endpoint = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$isSemillaRequest = preg_match('#/semilla/?$#i', $endpoint);
$isValidarRequest = preg_match('#/(validarsemilla|ValidacionCertificado)/?$#i', $endpoint);

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        header('Content-Type: text/xml; charset=utf-8');
        if ($isSemillaRequest || str_ends_with(rtrim($endpoint, '/'), '/autenticacion')) {
            handleSemilla();
            break;
        }
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(404);
        echo json_encode(['status' => false, 'error' => 'Ruta no encontrada en /api/ecf/autenticacion']);
        break;

    case 'POST':
        header('Content-Type: application/json; charset=utf-8');
        if ($isValidarRequest) {
            handleValidarSemilla();
            break;
        }
        http_response_code(404);
        echo json_encode(['status' => false, 'error' => 'Ruta no encontrada. Use POST /api/ecf/autenticacion/validarsemilla.']);
        break;

    default:
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(405);
        echo json_encode(['status' => false, 'error' => 'Metodo no permitido en /api/ecf/autenticacion']);
}

function handleSemilla(): void
{
    $seedValue = autenticacionGenerarSeedValue();
    $fecha = (new DateTime())->format('Y-m-d\TH:i:s');
    $xml = autenticacionConstruirXmlSemilla($seedValue, $fecha);

    try {
        (new authSeedModel())->create($seedValue, $xml, 300);
    } catch (Throwable $e) {
        error_log('[ecfAutenticacion] Semilla DB error: ' . $e->getMessage());
    }

    // Discard any accidental output (PHP warnings, notices) before sending XML
    if (ob_get_level() > 0) {
        ob_clean();
    }
    header('Content-Type: text/xml; charset=utf-8');
    echo $xml;
}

function handleValidarSemilla(): void
{
    try {
        handleValidarSemillaInternal();
    } catch (Throwable $e) {
        error_log('[ecfAutenticacion] ValidarSemilla fatal: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        http_response_code(500);
        echo json_encode(['status' => false, 'error' => 'Error interno: ' . $e->getMessage()]);
    }
}

function handleValidarSemillaInternal(): void
{
    $extractor = new IncomingXmlExtractor();
    $xml = $extractor->extract();
    if ($xml === null) {
        autenticacionResponderError('No se recibio archivo XML en el campo "xml" ni en el cuerpo.', 400);
        return;
    }

    $validator = new IncomingXmlValidator();
    $validation = $validator->loadAndValidate($xml);
    if (!$validation['ok']) {
        $det = $validation['firma_detalle'] ?? 'XML invalido o firma no verificable.';
        error_log('[ecfAutenticacion] ValidarSemilla 401 firma: ' . $det);
        autenticacionResponderError($det, 401);
        return;
    }

    $document = $validation['document'];
    $valor = $validator->getText($document, 'valor') ?? $validator->getText($document, 'Valor');
    if ($valor === null) {
        error_log('[ecfAutenticacion] ValidarSemilla 422: no valor en XML');
        autenticacionResponderError('No se pudo extraer el valor de la semilla del XML.', 422);
        return;
    }

    $seedModel = new authSeedModel();
    $seed = $seedModel->getBySeedValue($valor);
    if (!$seed) {
        error_log('[ecfAutenticacion] ValidarSemilla 401: semilla no reconocida, valor=' . $valor);
        autenticacionResponderError('Semilla no reconocida.', 401);
        return;
    }
    if ($seed['consumida_at'] !== null) {
        error_log('[ecfAutenticacion] ValidarSemilla 401: semilla ya consumida, id=' . $seed['id']);
        autenticacionResponderError('La semilla ya fue consumida.', 401);
        return;
    }
    if (strtotime($seed['expira_at']) < time()) {
        error_log('[ecfAutenticacion] ValidarSemilla 401: semilla expirada, id=' . $seed['id']);
        autenticacionResponderError('La semilla expiro.', 401);
        return;
    }

    $rnc = $validation['firma_rnc'] ?? '';
    if ($rnc === '') {
        error_log('[ecfAutenticacion] ValidarSemilla 401: RNC vacio, subject=' . json_encode($validation['firma_subject'] ?? []));
        autenticacionResponderError('No se pudo extraer RNC del certificado firmante.', 401);
        return;
    }

    $token = autenticacionGenerarToken();
    $expedido = (new DateTime())->format('Y-m-d\TH:i:s');
    $expira = (new DateTime())->modify('+1 hour')->format('Y-m-d\TH:i:s');

    $seedModel->markConsumed((int) $seed['id'], $rnc, $token);
    $seedModel->saveToken($token, $rnc, 3600);

    echo json_encode([
        'status' => true,
        'data' => [
            'token' => $token,
            'expedido' => $expedido,
            'expira' => $expira,
            'rnc' => $rnc,
        ],
    ]);
}

function autenticacionConstruirXmlSemilla(string $seedValue, string $fecha): string
{
    $doc = new DOMDocument('1.0', 'UTF-8');
    $doc->preserveWhiteSpace = false;
    $root = $doc->createElement('SemillaModel');
    $doc->appendChild($root);
    $root->appendChild($doc->createElement('valor', $seedValue));
    $root->appendChild($doc->createElement('fecha', $fecha));
    return $doc->saveXML();
}

function autenticacionGenerarSeedValue(): string
{
    return strtoupper(bin2hex(random_bytes(16)));
}

function autenticacionGenerarToken(): string
{
    $payload = base64_encode(json_encode([
        'iat' => time(),
        'exp' => time() + 3600,
        'jti' => bin2hex(random_bytes(16)),
    ]));
    $signature = hash_hmac('sha256', $payload, autenticacionTokenSecret());
    return $payload . '.' . $signature;
}

function autenticacionTokenSecret(): string
{
    $env = getenv('ECF_AUTH_TOKEN_SECRET');
    if (is_string($env) && $env !== '') {
        return $env;
    }
    return 'gratex-default-token-secret-change-me';
}

function autenticacionResponderError(string $mensaje, int $code): void
{
    http_response_code($code);
    echo json_encode(['status' => false, 'error' => $mensaje]);
}
