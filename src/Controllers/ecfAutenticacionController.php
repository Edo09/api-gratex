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
        // 1 hora, no 5 minutos: DGII firma la semilla y la reenvia bastante
        // despues (y la reusa entre intentos). Con la ventana corta el segundo
        // fallo era "semilla expirada", que aborta la fase igual que el 401 de
        // semilla consumida. La semilla sigue exigiendo firma valida.
        $seedId = (new authSeedModel())->create($seedValue, $xml, 3600);
        // Sin esta linea el log solo muestra los fallos del POST y no se puede
        // saber si DGII llego a pedir una semilla nueva antes de reenviar la
        // vieja. Con ella la secuencia GET->POST queda visible.
        error_log('[ecfAutenticacion] Semilla emitida id=' . $seedId . ' valor=' . substr($seedValue, 0, 8) . '...');
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
    // El RNC del firmante se necesita antes que los chequeos de la semilla: es
    // lo que decide si un reintento es del mismo consumidor o de un tercero.
    $rnc = $validation['firma_rnc'] ?? '';
    if ($rnc === '') {
        error_log('[ecfAutenticacion] ValidarSemilla 401: RNC vacio, subject=' . json_encode($validation['firma_subject'] ?? []));
        autenticacionResponderError('No se pudo extraer RNC del certificado firmante.', 401);
        return;
    }

    if ($seed['consumida_at'] !== null) {
        autenticacionReemitirToken($seedModel, $seed, $rnc);
        return;
    }
    if (strtotime($seed['expira_at']) < time()) {
        error_log('[ecfAutenticacion] ValidarSemilla 401: semilla expirada, id=' . $seed['id']);
        autenticacionResponderError('La semilla expiro.', 401);
        return;
    }

    $token = autenticacionGenerarToken();
    $seedModel->markConsumed((int) $seed['id'], $rnc, $token);
    $seedModel->saveToken($token, $rnc, 3600);

    autenticacionResponderToken(
        $token,
        (new DateTime())->format('Y-m-d\TH:i:s'),
        (new DateTime())->modify('+1 hour')->format('Y-m-d\TH:i:s')
    );
}

/**
 * Reintento del handshake sobre una semilla que YA se canjeo.
 *
 * DGII no pide una semilla nueva en cada intento: reenvia la que ya tiene
 * firmada. Tambien reintenta el mismo POST cuando nuestra respuesta no le llego
 * a tiempo — de este lado el canje si se completo y la semilla quedo quemada.
 * En los dos casos, responder 401 aborta la fase completa con "fallo en la
 * comunicacion con su servicio de autenticacion, Error: Unauthorized" y DGII
 * reinicia el set de pruebas.
 *
 * No debilita nada: para llegar hasta aqui el XML ya paso la verificacion de
 * firma, y solo se atiende al MISMO RNC que canjeo la semilla. Quien pueda
 * firmar con ese certificado puede pedir una semilla nueva y obtener el mismo
 * token por la via normal. Se reenvia el token vigente que ya se le habia
 * emitido (idempotente de verdad); solo si ese token vencio se emite otro.
 */
function autenticacionReemitirToken(authSeedModel $seedModel, array $seed, string $rnc): void
{
    // Otro RNC reenviando una semilla ajena si es un replay: se rechaza.
    $consumidor = (string) ($seed['rnc_consumidor'] ?? '');
    if ($consumidor !== '' && $consumidor !== $rnc) {
        error_log('[ecfAutenticacion] ValidarSemilla 401: semilla id=' . $seed['id']
            . ' fue consumida por ' . $consumidor . ' y la reenvia ' . $rnc);
        autenticacionResponderError('La semilla ya fue consumida.', 401);
        return;
    }

    $token = (string) ($seed['token_emitido'] ?? '');
    $vigente = $token !== '' ? $seedModel->findValidToken($token) : null;

    if ($vigente !== null) {
        error_log('[ecfAutenticacion] ValidarSemilla: reintento sobre semilla id=' . $seed['id']
            . '; se reenvia el token vigente de ' . $rnc);
        autenticacionResponderToken(
            $token,
            (new DateTime((string) $vigente['expedido_at']))->format('Y-m-d\TH:i:s'),
            (new DateTime((string) $vigente['expira_at']))->format('Y-m-d\TH:i:s')
        );
        return;
    }

    $token = autenticacionGenerarToken();
    $seedModel->markConsumed((int) $seed['id'], $rnc, $token);
    $seedModel->saveToken($token, $rnc, 3600);
    error_log('[ecfAutenticacion] ValidarSemilla: reintento sobre semilla id=' . $seed['id']
        . '; el token anterior ya vencio, se emite uno nuevo para ' . $rnc);

    autenticacionResponderToken(
        $token,
        (new DateTime())->format('Y-m-d\TH:i:s'),
        (new DateTime())->modify('+1 hour')->format('Y-m-d\TH:i:s')
    );
}

/**
 * Respuesta del handshake. Formato PLANO: DGII espera el token en la raiz, no
 * envuelto en {"status":true,"data":{...}} como el resto de la API.
 */
function autenticacionResponderToken(string $token, string $expedido, string $expira): void
{
    echo json_encode([
        'token'    => $token,
        'expira'   => $expira,
        'expedido' => $expedido,
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
    $env = getenv('ECF_AUTH_TOKEN_SECRET') ?: ($_ENV['ECF_AUTH_TOKEN_SECRET'] ?? '');
    if (is_string($env) && $env !== '') {
        return $env;
    }
    // Sin secreto configurado NO usar un valor conocido/committeado (seria
    // forjable): un secreto aleatorio por proceso. El token emitido se valida
    // por lookup en auth_tokens_emitidos, asi que no necesita ser estable entre
    // requests. Configura ECF_AUTH_TOKEN_SECRET en el .env para fijarlo.
    static $ephemeral = null;
    if ($ephemeral === null) {
        $ephemeral = bin2hex(random_bytes(32));
        error_log('[ecfAutenticacion] ECF_AUTH_TOKEN_SECRET no configurado; usando secreto efimero aleatorio.');
    }
    return $ephemeral;
}

function autenticacionResponderError(string $mensaje, int $code): void
{
    http_response_code($code);
    echo json_encode(['status' => false, 'error' => $mensaje]);
}
