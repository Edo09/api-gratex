<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Origin, X-Requested-With, Content-Type, Accept');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../Models/ecfRecibidoModel.php';
require_once __DIR__ . '/../Models/EmisorConfigModel.php';
require_once __DIR__ . '/../Models/authSeedModel.php';
require_once __DIR__ . '/../Utils/FacturacionElectronica/IncomingXmlValidator.php';
require_once __DIR__ . '/../Utils/FacturacionElectronica/IncomingXmlExtractor.php';

$endpoint = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$isTrackIdRequest = preg_match('#/recepcion(?:/ecf)?/([A-Za-z0-9_-]+)$#', $endpoint, $trackMatches);

switch ($_SERVER['REQUEST_METHOD']) {
    case 'POST':
        try {
            handleRecepcionEcf();
        } catch (Throwable $e) {
            error_log('[ecfRecepcion] fatal: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            http_response_code(500);
            echo json_encode(['status' => false, 'error' => 'Error interno: ' . $e->getMessage()]);
        }
        break;

    case 'GET':
        if ($isTrackIdRequest) {
            handleConsultarRecibido($trackMatches[1]);
            break;
        }
        handleListarRecibidos();
        break;

    default:
        http_response_code(405);
        echo json_encode(['status' => false, 'error' => 'Metodo no permitido en /api/ecf/recepcion']);
}

function handleRecepcionEcf(): void
{
    $bearerCheck = ecfRecepcionRequireBearer();
    if (!$bearerCheck['ok']) {
        return;
    }

    $extractor = new IncomingXmlExtractor();
    $xml = $extractor->extract();
    if ($xml === null) {
        respondRecepcion(false, 'No se recibio archivo XML en el campo "xml" ni en el cuerpo.', 400);
        return;
    }

    $validator = new IncomingXmlValidator();
    $validation = $validator->loadAndValidate($xml);

    if (!$validation['ok']) {
        respondRecepcion(false, $validation['firma_detalle'] ?? 'XML invalido.', 400, [
            'validacion' => $validation,
        ]);
        return;
    }

    $document = $validation['document'];
    $rootName = $validation['root_name'];
    if ($rootName !== 'ECF') {
        respondRecepcion(false, 'El XML enviado no es un ECF (root: ' . $rootName . ').', 422);
        return;
    }

    $rncEmisor = $validator->getText($document, 'RNCEmisor');
    $rncComprador = $validator->getText($document, 'RNCComprador');
    $tipoEcf = $validator->getText($document, 'TipoeCF');
    $eNcf = $validator->getText($document, 'eNCF');
    $razonSocial = $validator->getText($document, 'RazonSocialEmisor');
    $montoTotal = $validator->getFloat($document, 'MontoTotal');
    $fechaEmision = $validator->getDate($document, 'FechaEmision');

    if ($rncEmisor === null || $eNcf === null) {
        respondRecepcion(false, 'El XML no contiene RNCEmisor o eNCF.', 422);
        return;
    }

    $emisor = (new EmisorConfigModel())->get();
    if (!$emisor) {
        respondRecepcion(false, 'emisor_config no configurado en este sistema.', 500);
        return;
    }
    if ($rncComprador !== null && $rncComprador !== $emisor['rnc']) {
        respondRecepcion(
            false,
            'El RNCComprador (' . $rncComprador . ') no coincide con el RNC de este receptor (' . $emisor['rnc'] . ').',
            422
        );
        return;
    }

    $model = new ecfRecibidoModel();
    if ($model->exists($rncEmisor, $eNcf)) {
        respondRecepcion(false, 'Este e-NCF ya fue recibido previamente del mismo emisor.', 409);
        return;
    }

    $trackId = ecfRecepcionGenerarTrackId();
    $estado = $validation['firma'] === 'OK' ? 'ACEPTADO' : 'RECHAZADO';
    $codigoResultado = $estado === 'ACEPTADO' ? 1 : 2;
    $mensaje = $estado === 'ACEPTADO'
        ? 'e-CF recibido y firma verificada.'
        : 'e-CF recibido pero la firma digital no es valida: ' . ($validation['firma_detalle'] ?? '');

    $id = $model->save([
        'track_id' => $trackId,
        'tipo_ecf' => $tipoEcf,
        'e_ncf' => $eNcf,
        'rnc_emisor' => $rncEmisor,
        'razon_social_emisor' => $razonSocial,
        'rnc_comprador' => $rncComprador,
        'monto_total' => $montoTotal,
        'fecha_emision' => $fechaEmision,
        'estado' => $estado,
        'codigo_resultado' => $codigoResultado,
        'mensaje_resultado' => $mensaje,
        'xml_firmado' => $xml,
        'validacion_firma' => $validation['firma'],
    ]);

    http_response_code(200);
    echo json_encode([
        'trackId' => $trackId,
        'codigo' => $codigoResultado,
        'estado' => $estado,
        'mensaje' => $mensaje,
        'rncEmisor' => $rncEmisor,
        'eNCF' => $eNcf,
        'fechaRecepcion' => date('d-m-Y H:i:s'),
        'id' => $id,
    ]);
}

function handleConsultarRecibido(string $trackId): void
{
    $check = ecfRecepcionRequireBearer();
    if (!$check['ok']) {
        return;
    }

    $row = (new ecfRecibidoModel())->getByTrackId($trackId);
    if (!$row) {
        respondRecepcion(false, 'trackId no encontrado.', 404);
        return;
    }
    unset($row['xml_firmado']);
    echo json_encode(['status' => true, 'data' => $row]);
}

function handleListarRecibidos(): void
{
    $check = ecfRecepcionRequireBearer();
    if (!$check['ok']) {
        return;
    }

    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int) $_GET['page'] : 1;
    $pageSize = isset($_GET['pageSize']) && is_numeric($_GET['pageSize']) && $_GET['pageSize'] > 0 ? (int) $_GET['pageSize'] : 20;
    $offset = ($page - 1) * $pageSize;

    $model = new ecfRecibidoModel();
    $rows = $model->listPaginated($offset, $pageSize);
    $total = $model->count();
    echo json_encode([
        'status' => true,
        'data' => $rows,
        'pagination' => [
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $total,
            'totalPages' => $pageSize > 0 ? (int) ceil($total / $pageSize) : 0,
        ],
    ]);
}

function ecfRecepcionRequireBearer(): array
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
        error_log('[ecfRecepcion] 401: no Bearer token. auth_header=' . substr($auth, 0, 80));
        respondRecepcion(false, 'Bearer token requerido en Authorization.', 401);
        return ['ok' => false];
    }
    $token = trim($m[1]);
    $valid = (new authSeedModel())->findValidToken($token);
    if (!$valid) {
        error_log('[ecfRecepcion] 401: token invalido o expirado. token_prefix=' . substr($token, 0, 40));
        respondRecepcion(false, 'Bearer token invalido o expirado.', 401);
        return ['ok' => false];
    }
    return ['ok' => true, 'rnc' => $valid['rnc_consumidor']];
}

function ecfRecepcionGenerarTrackId(): string
{
    return strtoupper(bin2hex(random_bytes(16)));
}

function respondRecepcion(bool $status, string $message, int $code = 200, array $extra = []): void
{
    http_response_code($code);
    echo json_encode(array_merge([
        'status' => $status,
        $status ? 'mensaje' : 'error' => $message,
    ], $extra));
}
