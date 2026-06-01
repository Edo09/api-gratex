<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Origin, X-Requested-With, Content-Type, Accept');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../Models/aprobacionComercialModel.php';
require_once __DIR__ . '/../Models/EmisorConfigModel.php';
require_once __DIR__ . '/../Models/authSeedModel.php';
require_once __DIR__ . '/../Utils/FacturacionElectronica/IncomingXmlValidator.php';
require_once __DIR__ . '/../Utils/FacturacionElectronica/IncomingXmlExtractor.php';
require_once __DIR__ . '/../Utils/FacturacionElectronica/DgiiXmlSigner.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => false, 'error' => 'Solo POST permitido en /api/ecf/aprobacion-comercial']);
    return;
}

handleAprobacionComercial();

function handleAprobacionComercial(): void
{
    $bearer = aprobacionRequireBearer();
    if (!$bearer['ok']) {
        return;
    }

    $extractor = new IncomingXmlExtractor();
    $xml = $extractor->extract();
    if ($xml === null) {
        respondAprobacion(false, 'No se recibio archivo XML en el campo "xml" ni en el cuerpo.', 400);
        return;
    }

    $validator = new IncomingXmlValidator();
    $validation = $validator->loadAndValidate($xml);
    if (!$validation['ok']) {
        respondAprobacion(false, $validation['firma_detalle'] ?? 'XML invalido.', 400);
        return;
    }

    $document = $validation['document'];
    $rootName = $validation['root_name'];
    if (!in_array($rootName, ['ACECF', 'AprobacionComercial'], true)) {
        respondAprobacion(false, 'El root del XML debe ser ACECF (root recibido: ' . $rootName . ').', 422);
        return;
    }

    $emisor = (new EmisorConfigModel())->get();
    if (!$emisor) {
        respondAprobacion(false, 'emisor_config no configurado en este sistema.', 500);
        return;
    }

    $rncEmisor = $validator->getText($document, 'RNCEmisor');
    $rncComprador = $validator->getText($document, 'RNCComprador');
    $eNcf = $validator->getText($document, 'eNCF') ?? $validator->getText($document, 'ENCF');
    $estado = strtoupper((string) ($validator->getText($document, 'Estado') ?? $validator->getText($document, 'EstadoComercial') ?? ''));
    $detalle = $validator->getText($document, 'DetalleMotivoRechazo') ?? $validator->getText($document, 'Detalle');

    if ($eNcf === null || $rncEmisor === null) {
        respondAprobacion(false, 'El XML no contiene eNCF o RNCEmisor.', 422);
        return;
    }

    if ($rncEmisor !== $emisor['rnc']) {
        respondAprobacion(
            false,
            'El RNCEmisor (' . $rncEmisor . ') del ACECF no coincide con el RNC de este sistema (' . $emisor['rnc'] . ').',
            422
        );
        return;
    }

    $estadoNormalizado = aprobacionMapEstado($estado);
    if ($estadoNormalizado === null) {
        respondAprobacion(false, 'Estado comercial desconocido: "' . $estado . '". Valores aceptados: 1/2/3 o ACEPTADO/ACEPTADO_CONDICIONAL/RECHAZADO.', 422);
        return;
    }

    $model = new aprobacionComercialModel();
    $facturaId = $model->findFacturaIdByENcf($eNcf);

    $id = $model->save([
        'factura_id' => $facturaId,
        'e_ncf' => $eNcf,
        'rnc_emisor' => $rncEmisor,
        'rnc_comprador' => $rncComprador ?? '',
        'estado_comercial' => $estadoNormalizado,
        'detalle_motivo' => $detalle,
        'xml_firmado' => $xml,
        'validacion_firma' => $validation['firma'],
    ]);

    http_response_code(200);
    header('Content-Type: text/xml; charset=utf-8');
    echo buildSignedARCF($rncEmisor, $emisor['rnc'], $eNcf);
}

function aprobacionMapEstado(string $estado): ?string
{
    if ($estado === '') {
        return null;
    }
    if (is_numeric($estado)) {
        $map = ['1' => 'ACEPTADO', '2' => 'RECHAZADO', '3' => 'ACEPTADO_CONDICIONAL'];
        return $map[$estado] ?? null;
    }
    $clean = str_replace([' ', '-'], '_', $estado);
    if (in_array($clean, ['ACEPTADO', 'ACEPTADO_CONDICIONAL', 'RECHAZADO'], true)) {
        return $clean;
    }
    return null;
}

function aprobacionRequireBearer(): array
{
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $auth = '';
    foreach ($headers as $name => $value) {
        if (strcasecmp($name, 'Authorization') === 0) {
            $auth = (string) $value;
            break;
        }
    }
    if ($auth === '' && isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth = (string) $_SERVER['HTTP_AUTHORIZATION'];
    }
    if (!preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
        respondAprobacion(false, 'Bearer token requerido en Authorization.', 401);
        return ['ok' => false];
    }
    $token = trim($m[1]);
    $valid = (new authSeedModel())->findValidToken($token);
    if (!$valid) {
        respondAprobacion(false, 'Bearer token invalido o expirado.', 401);
        return ['ok' => false];
    }
    return ['ok' => true, 'rnc' => $valid['rnc_consumidor']];
}

function respondAprobacion(bool $status, string $message, int $code = 200): void
{
    http_response_code($code);
    echo json_encode([
        'status' => $status,
        $status ? 'mensaje' : 'error' => $message,
    ]);
}

function buildSignedARCF(string $rncEmisor, string $rncComprador, string $eNcf): string
{
    $fecha = (new DateTime())->format('d-m-Y H:i:s');
    $unsigned = '<?xml version="1.0" encoding="UTF-8"?>' .
        '<ARECF>' .
            '<DetalleAcusedeRecibo>' .
                '<Version>1.0</Version>' .
                '<RNCEmisor>' . htmlspecialchars($rncEmisor) . '</RNCEmisor>' .
                '<RNCComprador>' . htmlspecialchars($rncComprador) . '</RNCComprador>' .
                '<eNCF>' . htmlspecialchars($eNcf) . '</eNCF>' .
                '<Estado>0</Estado>' .
                '<FechaHoraAcuseRecibo>' . htmlspecialchars($fecha) . '</FechaHoraAcuseRecibo>' .
            '</DetalleAcusedeRecibo>' .
        '</ARECF>';

    try {
        $certPath = getenv('DGII_ECF_CERT_PATH') ?: '';
        if ($certPath && !preg_match('/^[A-Za-z]:[\\\\\/]/', $certPath) && !str_starts_with($certPath, '/')) {
            $certPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $certPath);
        }
        $certContent = $certPath ? file_get_contents($certPath) : false;
        $certPassword = (string) (getenv('DGII_ECF_CERT_PASSWORD') ?: '');
        if ($certContent !== false && $certPassword !== '') {
            return (new DgiiXmlSigner())->sign($certContent, $certPassword, $unsigned);
        }
    } catch (Throwable $e) {
        error_log('[ecfAprobacion] ARCF sign error: ' . $e->getMessage());
    }

    return $unsigned;
}
