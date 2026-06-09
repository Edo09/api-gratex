<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Origin, X-Requested-With, Content-Type, Accept');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../Models/aprobacionComercialModel.php';
require_once __DIR__ . '/../Models/EmisorConfigModel.php';
require_once __DIR__ . '/../Models/authSeedModel.php';
require_once __DIR__ . '/../Models/IntegracionStoreModel.php';
require_once __DIR__ . '/../TenantResolver.php';
require_once __DIR__ . '/../CertResolver.php';
require_once __DIR__ . '/../Utils/WebhookDispatcher.php';
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
    // Auth relajada (igual que recepcion): aceptamos el ACECF si trae Bearer
    // valido O si su firma digital XMLDSig es valida. La firma es el gate de
    // autenticidad/integridad (cert embebido, no cadena de CAs de la DGII); el
    // Bearer queda opcional para quienes no completan el handshake semilla->token.
    $bearer = aprobacionRequireBearer(true); // soft: no corta si falta el token
    $hasValidBearer = $bearer['ok'];

    $extractor = new IncomingXmlExtractor();
    $xml = $extractor->extract();
    if ($xml === null) {
        respondAprobacion(false, 'No se recibio archivo XML en el campo "xml" ni en el cuerpo.', 400);
        return;
    }

    $validator = new IncomingXmlValidator();
    $validation = $validator->loadAndValidate($xml);
    if (!$validation['ok']) {
        respondAprobacion(
            false,
            $validation['firma_detalle'] ?? 'XML invalido o firma digital no verificable.',
            $hasValidBearer ? 400 : 401
        );
        return;
    }

    $document = $validation['document'];
    $rootName = $validation['root_name'];
    if (!in_array($rootName, ['ACECF', 'AprobacionComercial'], true)) {
        respondAprobacion(false, 'El root del XML debe ser ACECF (root recibido: ' . $rootName . ').', 422);
        return;
    }

    $rncEmisor = $validator->getText($document, 'RNCEmisor');
    $rncComprador = $validator->getText($document, 'RNCComprador');

    // Multi-tenant: somos el emisor que recibe la aprobacion -> resolver por RNCEmisor.
    if (aprobacionMultiTenant()) {
        $rncDestino = (string) ($rncEmisor ?? '');
        if ($rncDestino === '' || !TenantResolver::resolveByRnc($rncDestino)) {
            respondAprobacion(false, 'RNC emisor (' . $rncDestino . ') no registrado en este sistema.', 404);
            return;
        }
    }

    // Receptor de la aprobacion (nosotros = emisor del e-CF). En integracion no
    // hay emisor_config: el RNC es el del tenant resuelto por RNCEmisor.
    $isIntegration = aprobacionMultiTenant() && TenantResolver::isIntegration();
    $tenant = $isIntegration ? TenantResolver::current() : null;
    $tenantId = $isIntegration ? (int) $tenant['id'] : null;

    if ($isIntegration) {
        $emisor = ['rnc' => (string) $tenant['rnc']];
    } else {
        $emisor = (new EmisorConfigModel())->get();
        if (!$emisor) {
            respondAprobacion(false, 'emisor_config no configurado en este sistema.', 500);
            return;
        }
    }
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

    $aprData = [
        'e_ncf' => $eNcf,
        'rnc_emisor' => $rncEmisor,
        'rnc_comprador' => $rncComprador ?? '',
        'estado_comercial' => $estadoNormalizado,
        'detalle_motivo' => $detalle,
        'xml_firmado' => $xml,
        'validacion_firma' => $validation['firma'],
        // Integracion: ambiente per-tenant (tenants.ambiente: certecf en certificacion,
        // ecf en produccion). App: el env global del servidor.
        'ambiente' => $isIntegration
            ? ($tenant['ambiente'] ?? null)
            : (getenv('DGII_ECF_ENVIRONMENT') ?: null),
    ];
    if ($isIntegration) {
        (new IntegracionStoreModel())->saveAprobacion($tenantId, $aprData);
    } else {
        $model = new aprobacionComercialModel();
        $aprData['factura_id'] = $model->findFacturaIdByENcf($eNcf);
        $model->save($aprData);
    }

    http_response_code(200);
    header('Content-Type: text/xml; charset=utf-8');
    echo buildSignedARCF($rncEmisor, $emisor['rnc'], $eNcf);

    // Integracion: notificar al cliente por webhook (tras responder a DGII).
    if ($isIntegration && !empty($tenant['webhook_url'])) {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        WebhookDispatcher::dispatch($tenant, 'aprobacion.recibida', [
            'e_ncf' => $eNcf,
            'rnc_emisor' => $rncEmisor,
            'rnc_comprador' => $rncComprador,
            'estado_comercial' => $estadoNormalizado,
            'detalle_motivo' => $detalle,
        ]);
    }
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

/**
 * Valida el Bearer token del flujo semilla DGII.
 * @param bool $soft Si true, no responde 401 cuando falta/expira el token; solo
 *        devuelve ['ok' => false]. Usado por la recepcion de ACECF (auth relajada:
 *        la firma digital es el gate). El modo estricto (default) responde 401.
 */
function aprobacionRequireBearer(bool $soft = false): array
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
            respondAprobacion(false, 'Bearer token requerido en Authorization.', 401);
        }
        return ['ok' => false];
    }
    $token = trim($m[1]);
    $valid = (new authSeedModel())->findValidToken($token);
    if (!$valid) {
        if (!$soft) {
            respondAprobacion(false, 'Bearer token invalido o expirado.', 401);
        }
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

function aprobacionMultiTenant(): bool
{
    return filter_var(
        getenv('MULTI_TENANT_ENABLED') ?: ($_ENV['MULTI_TENANT_ENABLED'] ?? false),
        FILTER_VALIDATE_BOOLEAN
    );
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
        // Cert del tenant resuelto (integracion/app) o el global del .env.
        $cert = CertResolver::resolve();
        if ($cert['password'] !== '') {
            return (new DgiiXmlSigner())->sign($cert['content'], $cert['password'], $unsigned);
        }
    } catch (Throwable $e) {
        error_log('[ecfAprobacion] ARCF sign error: ' . $e->getMessage());
    }

    return $unsigned;
}
