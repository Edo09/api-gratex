<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Origin, X-Requested-With, Content-Type, Accept');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../Models/ecfRecibidoModel.php';
require_once __DIR__ . '/../Models/EmisorConfigModel.php';
require_once __DIR__ . '/../Models/authSeedModel.php';
require_once __DIR__ . '/../Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../TenantResolver.php';
require_once __DIR__ . '/../CertResolver.php';
require_once __DIR__ . '/../Models/IntegracionStoreModel.php';
require_once __DIR__ . '/../Utils/WebhookDispatcher.php';
require_once __DIR__ . '/../Utils/FacturacionElectronica/IncomingXmlValidator.php';
require_once __DIR__ . '/../Utils/FacturacionElectronica/IncomingXmlExtractor.php';
require_once __DIR__ . '/../Utils/FacturacionElectronica/DgiiXmlSigner.php';

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
    // Auth relajada (receptor "abierto"): aceptamos el e-CF si trae Bearer valido
    // O si su firma digital XMLDSig es valida (se exige mas abajo). Permite recibir
    // de emisores cuyo software no completa el handshake semilla->token. La firma
    // es el gate de autenticidad/integridad; el Bearer queda opcional.
    // NOTA: la firma se valida contra el certificado EMBEBIDO, no contra la cadena
    // de CAs de la DGII (ver IncomingXmlValidator) -> garantiza integridad y posesion
    // de llave, no la confianza del emisor. El e-CF entra como pendiente y se revisa
    // antes de aprobar/rechazar, y el RNCComprador debe ser un tenant registrado.
    $bearerCheck = ecfRecepcionRequireBearer(true); // soft: no corta si falta el token
    $hasValidBearer = $bearerCheck['ok'];

    $extractor = new IncomingXmlExtractor();
    $xml = $extractor->extract();
    if ($xml === null) {
        respondRecepcion(false, 'No se recibio archivo XML en el campo "xml" ni en el cuerpo.', 400);
        return;
    }

    $validator = new IncomingXmlValidator();
    $validation = $validator->loadAndValidate($xml);

    // Gate de autenticidad: sin Bearer valido, la firma DEBE ser valida. Con Bearer
    // valido igual exigimos un XML bien formado y firmado.
    if (!$validation['ok']) {
        respondRecepcion(
            false,
            $validation['firma_detalle'] ?? 'XML invalido o firma digital no verificable.',
            $hasValidBearer ? 400 : 401,
            ['validacion' => $validation]
        );
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

    // Multi-tenant: el RNCComprador identifica al tenant receptor (nosotros).
    // Resolver su DB antes de tocar emisor_config / ecf_recibidos.
    if (ecfRecepcionMultiTenant()) {
        $rncDestino = (string) ($rncComprador ?? '');
        if ($rncDestino === '' || !TenantResolver::resolveByRnc($rncDestino)) {
            respondRecepcion(false, 'RNC comprador (' . $rncDestino . ') no registrado en este sistema.', 404);
            return;
        }
    }

    // Receptor (nosotros). En integracion no hay emisor_config: el RNC es el del tenant.
    $isIntegration = ecfRecepcionMultiTenant() && TenantResolver::isIntegration();
    $tenant = $isIntegration ? TenantResolver::current() : null;
    $tenantId = $isIntegration ? (int) $tenant['id'] : null;

    if ($isIntegration) {
        $emisor = ['rnc' => (string) $tenant['rnc']];
    } else {
        $emisor = (new EmisorConfigModel())->get();
        if (!$emisor) {
            respondRecepcion(false, 'emisor_config no configurado en este sistema.', 500);
            return;
        }
    }
    if ($rncComprador !== null && $rncComprador !== $emisor['rnc']) {
        respondRecepcion(
            false,
            'El RNCComprador (' . $rncComprador . ') no coincide con el RNC de este receptor (' . $emisor['rnc'] . ').',
            422
        );
        return;
    }

    // Store: master (integracion, por tenant_id) o DB del tenant (app).
    $store = $isIntegration ? new IntegracionStoreModel() : new ecfRecibidoModel();

    $yaExiste = $isIntegration
        ? $store->existsRecibido($tenantId, $rncEmisor, $eNcf)
        : $store->exists($rncEmisor, $eNcf);
    if ($yaExiste) {
        http_response_code(200);
        header('Content-Type: text/xml; charset=utf-8');
        echo buildSignedAECF($rncEmisor, $emisor['rnc'] ?? '', $eNcf, 0, null);
        return;
    }

    $trackId = ecfRecepcionGenerarTrackId();
    $estado = $validation['firma'] === 'OK' ? 'ACEPTADO' : 'RECHAZADO';
    $codigoResultado = $estado === 'ACEPTADO' ? 1 : 2;
    $mensaje = $estado === 'ACEPTADO'
        ? 'e-CF recibido y firma verificada.'
        : 'e-CF recibido pero la firma digital no es valida: ' . ($validation['firma_detalle'] ?? '');
    if (!$hasValidBearer) {
        $mensaje .= ' (recibido sin autenticacion previa; aceptado por firma digital valida).';
    }

    $recData = [
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
        'ambiente' => getenv('DGII_ECF_ENVIRONMENT') ?: null,
    ];
    if ($isIntegration) {
        $store->saveRecibido($tenantId, $recData);
    } else {
        $store->save($recData);
    }

    $nuestroRnc = $emisor['rnc'] ?? '';
    $estadoAecf = $validation['firma'] === 'OK' ? 0 : 1;
    $motivoAecf = $estadoAecf === 1 ? 2 : null; // 2 = Error de Firma Digital
    http_response_code(200);
    header('Content-Type: text/xml; charset=utf-8');
    echo buildSignedAECF($rncEmisor, $nuestroRnc, $eNcf, $estadoAecf, $motivoAecf);

    // Integracion: notificar al cliente por webhook (tras responder a DGII).
    if ($isIntegration && !empty($tenant['webhook_url'])) {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        WebhookDispatcher::dispatch($tenant, 'ecf.recibido', [
            'track_id' => $trackId,
            'tipo_ecf' => $tipoEcf,
            'e_ncf' => $eNcf,
            'rnc_emisor' => $rncEmisor,
            'razon_social_emisor' => $razonSocial,
            'rnc_comprador' => $rncComprador,
            'monto_total' => $montoTotal,
            'fecha_emision' => $fechaEmision,
            'estado' => $estado,
        ]);
    }
}

function handleConsultarRecibido(string $trackId): void
{
    if (!ecfRecepcionRequireReadAuth()) {
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
    if (!ecfRecepcionRequireReadAuth()) {
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

/**
 * Auth para lectura (listar/consultar recibidos).
 * Acepta X-API-KEY de cliente propio (AuthMiddleware) o, como fallback,
 * el Bearer token del flujo semilla DGII. Solo lectura de nuestra DB.
 */
function ecfRecepcionRequireReadAuth(): bool
{
    if (isset($_SERVER['HTTP_X_API_KEY']) && trim($_SERVER['HTTP_X_API_KEY']) !== '') {
        $validation = (new AuthMiddleware())->validateRequest();
        if ($validation['valid']) {
            return true;
        }
        respondRecepcion(false, 'X-API-KEY invalido o inactivo.', 401);
        return false;
    }

    return ecfRecepcionRequireBearer()['ok'];
}

/**
 * Valida el Bearer token del flujo semilla DGII.
 * @param bool $soft Si true, no responde ni corta cuando falta/expira el token;
 *        solo devuelve ['ok' => false]. Usado por recepcion (auth relajada: la
 *        firma digital es el gate). El modo estricto (default) responde 401.
 */
function ecfRecepcionRequireBearer(bool $soft = false): array
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
        if (!$soft) {
            error_log('[ecfRecepcion] 401: no Bearer token. auth_header=' . substr($auth, 0, 80));
            respondRecepcion(false, 'Bearer token requerido en Authorization.', 401);
        }
        return ['ok' => false];
    }
    $token = trim($m[1]);
    $valid = (new authSeedModel())->findValidToken($token);
    if (!$valid) {
        if (!$soft) {
            error_log('[ecfRecepcion] 401: token invalido o expirado. token_prefix=' . substr($token, 0, 40));
            respondRecepcion(false, 'Bearer token invalido o expirado.', 401);
        }
        return ['ok' => false];
    }
    return ['ok' => true, 'rnc' => $valid['rnc_consumidor']];
}

function buildSignedAECF(string $rncEmisor, string $rncComprador, string $eNcf, int $estado, ?int $motivo): string
{
    $fecha = (new DateTime())->format('d-m-Y H:i:s');
    $motivoXml = ($estado === 1 && $motivo !== null)
        ? '<CodigoMotivoNoRecibido>' . $motivo . '</CodigoMotivoNoRecibido>'
        : '';

    $unsigned = '<?xml version="1.0" encoding="UTF-8"?>' .
        '<ARECF>' .
            '<DetalleAcusedeRecibo>' .
                '<Version>1.0</Version>' .
                '<RNCEmisor>' . htmlspecialchars($rncEmisor) . '</RNCEmisor>' .
                '<RNCComprador>' . htmlspecialchars($rncComprador) . '</RNCComprador>' .
                '<eNCF>' . htmlspecialchars($eNcf) . '</eNCF>' .
                '<Estado>' . $estado . '</Estado>' .
                $motivoXml .
                '<FechaHoraAcuseRecibo>' . htmlspecialchars($fecha) . '</FechaHoraAcuseRecibo>' .
            '</DetalleAcusedeRecibo>' .
        '</ARECF>';

    try {
        // Cert del tenant resuelto (integracion/app) o el global del .env.
        $cert = CertResolver::resolve();
        if ($cert['password'] !== '') {
            $signer = new DgiiXmlSigner();
            return $signer->sign($cert['content'], $cert['password'], $unsigned);
        }
    } catch (Throwable $e) {
        error_log('[ecfRecepcion] AECF sign error: ' . $e->getMessage());
    }

    return $unsigned;
}

function ecfRecepcionGenerarTrackId(): string
{
    return strtoupper(bin2hex(random_bytes(16)));
}

function ecfRecepcionMultiTenant(): bool
{
    return filter_var(
        getenv('MULTI_TENANT_ENABLED') ?: ($_ENV['MULTI_TENANT_ENABLED'] ?? false),
        FILTER_VALIDATE_BOOLEAN
    );
}

function respondRecepcion(bool $status, string $message, int $code = 200, array $extra = []): void
{
    http_response_code($code);
    echo json_encode(array_merge([
        'status' => $status,
        $status ? 'mensaje' : 'error' => $message,
    ], $extra));
}
