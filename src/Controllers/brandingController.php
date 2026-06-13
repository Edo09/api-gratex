<?php
// Branding de la Representacion Impresa por tenant (plantilla, color, logo).
// Ruta: /api/branding (requiere token; el tenant sale SIEMPRE del token,
// nunca del body).
//   GET    /api/branding          -> branding actual + plantillas disponibles
//   PUT    /api/branding          -> {template?, accent_color?} (422 si invalido)
//   POST   /api/branding/logo     -> multipart campo "logo" (png/jpg, max 2 MB)
//   DELETE /api/branding/logo     -> elimina el logo (vuelve al global)
//   POST   /api/branding/preview  -> {template?, accent_color?, no_electronica?, grid?}
//                                    PDF de muestra base64 (?format=download),
//                                    SIN persistir nada. grid=true superpone una
//                                    rejilla de calibracion (disenar a la medida).
//
// Requiere modo multi-tenant: el branding vive en master.tenants
// (pdf_template, pdf_accent_color, logo_path). En single-tenant responde 409.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-API-KEY, X-API-SECRET, Authorization, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE');
header('Allow: GET, POST, OPTIONS, PUT, DELETE');
header('content-type: application/json; charset=utf-8');

require_once __DIR__ . '/../Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../MasterDatabase.php';
require_once __DIR__ . '/../Utils/Pdf/BrandingResolver.php';
require_once __DIR__ . '/../Utils/LogoStorage.php';

$auth = new AuthMiddleware();
$tenantId = null;
if ($_SERVER['REQUEST_METHOD'] !== 'OPTIONS') {
    $validation = $auth->validateRequest();
    if (!$validation['valid']) {
        $auth->sendUnauthorized($validation['message']);
    }
    $tenantId = isset($validation['tenant_id']) && $validation['tenant_id'] !== null
        ? (int) $validation['tenant_id']
        : null;
    if ($tenantId === null) {
        http_response_code(409);
        echo json_encode([
            'status' => false,
            'error' => 'El branding por tenant requiere modo multi-tenant.',
        ]);
        exit;
    }
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$isLogo = (bool) preg_match('#/branding/logo$#', $path);
$isPreview = (bool) preg_match('#/branding/preview$#', $path);

function brBody(): array
{
    $data = json_decode(file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

function brRespond(bool $ok, $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($ok ? ['status' => true, 'data' => $payload] : ['status' => false, 'error' => $payload]);
    exit;
}

/** Branding actual del tenant, leido fresco de master. */
function brCurrent(int $tenantId): array
{
    $tenant = MasterDatabase::getInstance()->getTenantById($tenantId);
    if (!$tenant) {
        brRespond(false, 'Tenant no encontrado (o inactivo).', 404);
    }
    return [
        'template'            => $tenant['pdf_template'] ?? BrandingResolver::DEFAULT_TEMPLATE,
        'accent_color'        => $tenant['pdf_accent_color'] ?? null,
        'logo_path'           => $tenant['logo_path'] ?? null,
        'has_custom_logo'     => !empty($tenant['logo_path']),
        'available_templates' => BrandingResolver::availableTemplates($tenantId),
    ];
}

/**
 * Valida template + accent_color del body. Devuelve solo los campos presentes
 * (PUT parcial) ya normalizados; corta con 422 si algo es invalido.
 * accent_color null/"" limpia el color.
 */
function brValidateBranding(array $body, int $tenantId): array
{
    $fields = [];
    if (array_key_exists('template', $body)) {
        $template = trim((string) $body['template']);
        if (!BrandingResolver::isValidTemplate($template)) {
            brRespond(false, 'template invalido. Disponibles: '
                . implode(', ', BrandingResolver::availableTemplates($tenantId)), 422);
        }
        // Una plantilla custom solo puede usarla su propio tenant
        // (custom:tenant<id>); las predefinidas son de todos.
        if (strpos($template, BrandingResolver::CUSTOM_PREFIX) === 0
            && $template !== BrandingResolver::CUSTOM_PREFIX . 'tenant' . $tenantId) {
            brRespond(false, 'Esa plantilla custom no pertenece a este tenant.', 422);
        }
        $fields['pdf_template'] = $template;
    }
    if (array_key_exists('accent_color', $body)) {
        $accent = $body['accent_color'];
        if ($accent === null || $accent === '') {
            $fields['pdf_accent_color'] = null;
        } else {
            $accent = trim((string) $accent);
            if (!BrandingResolver::isValidHex($accent)) {
                brRespond(false, 'accent_color invalido: debe ser hex #RRGGBB.', 422);
            }
            $fields['pdf_accent_color'] = strtoupper($accent);
        }
    }
    return $fields;
}

/**
 * POST /api/branding/preview — PDF de muestra con la plantilla/color del body
 * (o los persistidos del tenant si no vienen), sin guardar nada. La factura es
 * canned y sin e_ncf, asi el generador estampa "PREVIEW - Sin validez fiscal".
 */
function brHandlePreview(int $tenantId): void
{
    $body = brBody();

    $template = null;
    if (array_key_exists('template', $body)) {
        $template = trim((string) $body['template']);
        if (!BrandingResolver::isValidTemplate($template)) {
            brRespond(false, 'template invalido. Disponibles: '
                . implode(', ', BrandingResolver::availableTemplates($tenantId)), 422);
        }
        if (strpos($template, BrandingResolver::CUSTOM_PREFIX) === 0
            && $template !== BrandingResolver::CUSTOM_PREFIX . 'tenant' . $tenantId) {
            brRespond(false, 'Esa plantilla custom no pertenece a este tenant.', 422);
        }
    }
    $accent = null;
    if (!empty($body['accent_color'])) {
        $hex = trim((string) $body['accent_color']);
        if (!BrandingResolver::isValidHex($hex)) {
            brRespond(false, 'accent_color invalido: debe ser hex #RRGGBB.', 422);
        }
        $accent = BrandingResolver::hexToRgb(strtoupper($hex));
    }

    require_once __DIR__ . '/../Utils/FacturaPdfGenerator.php';

    // Factura de muestra: sin e_ncf/codigo_seguridad -> timbre PREVIEW.
    $factura = [
        'no_factura' => 'PREVIEW',
        'tipo_ecf'   => '31',
        'e_ncf'      => '',
        'date'       => date('Y-m-d'),
        'total'      => 2950.00,
        'items'      => [
            [
                'description'   => 'Producto de muestra',
                'quantity'      => 2,
                'amount'        => 1000.00,
                'subtotal'      => 2000.00,
                'itbis_amount'  => 360.00,
                'unidad_medida' => '43',
            ],
            [
                'description'   => 'Servicio de muestra',
                'quantity'      => 1,
                'amount'        => 500.00,
                'subtotal'      => 500.00,
                'itbis_amount'  => 90.00,
                'unidad_medida' => '43',
            ],
        ],
    ];

    $pdf = new FacturaPdfGenerator('P', 'mm', 'Letter');
    if (!empty($body['no_electronica'])) {
        $pdf->setNoElectronica(true);
    }
    // {"grid":true}: superpone una rejilla de calibracion (10 mm / cm) para
    // disenar plantillas a la medida replicando el formato de un cliente.
    // Ver docs/plantillas-factura.md ("Replicar el formato existente").
    if (!empty($body['grid'])) {
        $pdf->setDebugGrid(true);
    }
    $pdf->setFactura($factura);
    $pdf->setClientData([
        'client_name'  => 'Cliente de Muestra',
        'company_name' => 'Empresa de Muestra SRL',
        'rnc'          => '101000001',
        'phone_number' => '809-000-0000',
        'email'        => 'cliente@ejemplo.com',
    ]);
    // template/accent del body; si no vienen, los persistidos del tenant.
    $pdf->setTemplate(FacturaTemplateFactory::create($template, $accent));
    $pdfContent = $pdf->generatePdf();

    $format = $_GET['format'] ?? $body['format'] ?? 'base64';
    if ($format === 'download') {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="Preview_branding.pdf"');
        header('Content-Length: ' . strlen($pdfContent));
        echo $pdfContent;
        exit;
    }

    brRespond(true, [
        'filename'  => 'Preview_branding.pdf',
        'content'   => base64_encode($pdfContent),
        'mime_type' => 'application/pdf',
        'template'  => $template ?? BrandingResolver::resolve()['template'],
    ]);
}

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        brRespond(true, brCurrent($tenantId));
        break;

    case 'PUT':
        if ($isLogo || $isPreview) {
            brRespond(false, 'Metodo no soportado en esta sub-ruta.', 405);
        }
        $fields = brValidateBranding(brBody(), $tenantId);
        if (!$fields) {
            brRespond(false, 'Nada que actualizar: envia template y/o accent_color.', 422);
        }
        MasterDatabase::getInstance()->updateTenantBranding($tenantId, $fields);
        // Responder con lo recien escrito (TenantResolver::$current queda
        // obsoleto dentro de este request; no re-resolver).
        brRespond(true, brCurrent($tenantId));
        break;

    case 'POST':
        if ($isPreview) {
            brHandlePreview($tenantId);
            break;
        }
        if (!$isLogo) {
            brRespond(false, 'Sub-ruta no encontrada. Use /api/branding/logo o /api/branding/preview.', 404);
        }
        // POST (no PUT): PHP solo llena $_FILES en POST multipart.
        $result = LogoStorage::store($tenantId, $_FILES['logo'] ?? []);
        if (!$result['ok']) {
            brRespond(false, $result['error'], $result['code'] ?? 422);
        }
        MasterDatabase::getInstance()->updateTenantBranding($tenantId, ['logo_path' => $result['logo_path']]);
        brRespond(true, ['logo_path' => $result['logo_path']]);
        break;

    case 'DELETE':
        if (!$isLogo) {
            brRespond(false, 'DELETE solo aplica a /api/branding/logo.', 405);
        }
        LogoStorage::removeFiles($tenantId);
        MasterDatabase::getInstance()->updateTenantBranding($tenantId, ['logo_path' => null]);
        brRespond(true, ['logo_path' => null, 'message' => 'Logo eliminado; se usara el logo global.']);
        break;

    default:
        brRespond(false, 'Metodo no soportado', 405);
}
