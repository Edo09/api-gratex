<?php
/**
 * plantillas.php — Gestionar la plantilla PDF (branding) de cualquier tenant.
 *
 * Servido directo (bajo /api/public/). Herramienta de operaciones para el
 * onboarding: ver el branding actual, previsualizar una plantilla (con rejilla
 * de calibracion para disenos a la medida) y activarla — sin necesidad del
 * token del tenant (usa un token de operaciones propio, como upload_logo.php).
 *
 *   https://gratex.net/api/public/plantillas.php
 *
 * Acciones (POST, campo "action"):
 *   info    -> branding actual + plantillas disponibles del tenant
 *   preview -> PDF de muestra inline en el navegador (plantilla/acento del
 *              formulario; "grid" superpone la rejilla de calibracion de 10 mm)
 *   apply   -> persiste pdf_template / pdf_accent_color en master.tenants
 *
 * El logo se sube con upload_logo.php (herramienta hermana). Guia completa:
 * docs/modules/branding-plantillas.md ("Diseños a la medida" y "Replicar el
 * formato existente de un cliente").
 *
 * El token se lee de PLANTILLAS_TOKEN en el .env del server (nunca hardcodeado
 * en el repo).
 */

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/MasterDatabase.php';
require_once __DIR__ . '/../src/TenantResolver.php';
require_once __DIR__ . '/../src/Utils/Pdf/BrandingResolver.php';

// .env al inicio: el token de operaciones vive en el entorno, no en el codigo.
Database::loadEnv();

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

if (!$isPost) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Plantilla PDF de tenant</title>'
        . '<style>body{font-family:system-ui,sans-serif;background:#0f172a;color:#e2e8f0;display:flex;'
        . 'justify-content:center;padding:32px}form{background:#1e293b;border:1px solid #334155;'
        . 'border-radius:12px;padding:24px;width:100%;max-width:460px}label{display:block;font-size:13px;'
        . 'color:#94a3b8;margin:12px 0 4px}input,select{width:100%;padding:10px;background:#0b1220;color:#e2e8f0;'
        . 'border:1px solid #334155;border-radius:8px;box-sizing:border-box}'
        . '.chk{display:flex;align-items:center;gap:8px;margin-top:12px;font-size:13px;color:#94a3b8}'
        . '.chk input{width:auto;padding:0}'
        . '.btns{display:flex;gap:8px;margin-top:18px}'
        . 'button{flex:1;padding:12px;border:none;border-radius:8px;font-weight:600;cursor:pointer}'
        . '.b-info{background:#334155;color:#e2e8f0}.b-prev{background:#38bdf8;color:#06283d}'
        . '.b-apply{background:#34d399;color:#052e21}'
        . 'h1{font-size:18px}p.hint{font-size:12px;color:#64748b;margin:4px 0 0}</style></head><body>'
        . '<form method="post">'
        . '<h1>Plantilla PDF de tenant</h1>'
        . '<label>Token</label><input type="password" name="token" autocomplete="off" required>'
        . '<label>Tenant ID</label><input name="tenant_id" inputmode="numeric" required>'
        . '<label>Plantilla</label><select name="template">'
        . '<option value="">(la persistida del tenant)</option>'
        . '<option value="clasico">clasico</option>'
        . '<option value="moderno">moderno</option>'
        . '<option value="compacto">compacto</option>'
        . '<option value="custom">custom:tenant&lt;id&gt; (a la medida)</option>'
        . '</select>'
        . '<label>Color de acento (hex #RRGGBB)</label><input name="accent_color" placeholder="#1F6E43">'
        . '<p class="hint">Vacio: sin acento al aplicar; el persistido al previsualizar sin plantilla.</p>'
        . '<div class="chk"><input type="checkbox" name="grid" value="1" id="grid">'
        . '<label for="grid" style="margin:0">Rejilla de calibracion (10 mm) en el preview</label></div>'
        . '<div class="chk"><input type="checkbox" name="no_electronica" value="1" id="noe">'
        . '<label for="noe" style="margin:0">Factura NO electronica (sin timbre)</label></div>'
        . '<div class="btns">'
        . '<button class="b-info" name="action" value="info">Ver branding</button>'
        . '<button class="b-prev" name="action" value="preview" formtarget="_blank">Previsualizar</button>'
        . '<button class="b-apply" name="action" value="apply">Aplicar</button>'
        . '</div></form></body></html>';
    exit;
}

// ---- POST ------------------------------------------------------------------

function plFail(int $code, string $msg): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    exit($msg . "\n");
}

$expectedToken = (string) (getenv('PLANTILLAS_TOKEN') ?: ($_ENV['PLANTILLAS_TOKEN'] ?? ''));
if ($expectedToken === '') {
    plFail(403, 'PLANTILLAS_TOKEN no configurado en el .env del server.');
}
if (!hash_equals($expectedToken, (string) ($_POST['token'] ?? ''))) {
    plFail(403, 'Token invalido.');
}

$tenantId = (int) ($_POST['tenant_id'] ?? 0);
if ($tenantId <= 0) {
    plFail(422, 'tenant_id invalido.');
}
$tenant = MasterDatabase::getInstance()->getTenantById($tenantId);
if (!$tenant) {
    plFail(404, "Tenant {$tenantId} no encontrado (o inactivo).");
}

// Plantilla del formulario: '' = la persistida; 'custom' = la propia del tenant
// (custom:tenant<id> — la unica custom que puede usar, igual que /api/branding).
$template = trim((string) ($_POST['template'] ?? ''));
if ($template === 'custom') {
    $template = BrandingResolver::CUSTOM_PREFIX . 'tenant' . $tenantId;
}
if ($template === '') {
    $template = null;
} elseif (!BrandingResolver::isValidTemplate($template)) {
    plFail(422, "Plantilla '{$template}' invalida o su archivo no existe.\n"
        . 'Para una custom, genera primero: php tools/new_custom_template.php ' . $tenantId);
}

$accentHex = trim((string) ($_POST['accent_color'] ?? ''));
if ($accentHex !== '' && !BrandingResolver::isValidHex($accentHex)) {
    plFail(422, 'accent_color invalido: debe ser hex #RRGGBB.');
}
$accentHex = $accentHex !== '' ? strtoupper($accentHex) : null;

$action = (string) ($_POST['action'] ?? '');

switch ($action) {
    case 'info': {
        $available = BrandingResolver::availableTemplates($tenantId);
        $custom = BrandingResolver::CUSTOM_PREFIX . 'tenant' . $tenantId;
        // json=1: respuesta para la UI plantillas.html.
        if (!empty($_POST['json'])) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => true, 'data' => [
                'tenant_id'           => $tenantId,
                'nombre'              => $tenant['nombre'],
                'rnc'                 => $tenant['rnc'],
                'template'            => $tenant['pdf_template'] ?? BrandingResolver::DEFAULT_TEMPLATE,
                'accent_color'        => $tenant['pdf_accent_color'] ?? null,
                'logo_path'           => $tenant['logo_path'] ?? null,
                'available_templates' => $available,
                'has_custom'          => in_array($custom, $available, true),
                'custom_template'     => $custom,
            ]]);
            exit;
        }
        header('Content-Type: text/plain; charset=utf-8');
        echo "Tenant     : {$tenant['nombre']} (RNC {$tenant['rnc']}, id {$tenantId})\n";
        echo 'Plantilla  : ' . ($tenant['pdf_template'] ?? BrandingResolver::DEFAULT_TEMPLATE) . "\n";
        echo 'Acento     : ' . ($tenant['pdf_accent_color'] ?? '(ninguno)') . "\n";
        echo 'Logo       : ' . ($tenant['logo_path'] ?? '(global)') . "\n";
        echo 'Disponibles: ' . implode(', ', $available) . "\n";
        if (!in_array(BrandingResolver::CUSTOM_PREFIX . 'tenant' . $tenantId, $available, true)) {
            echo "\nSin plantilla a la medida. Para crearla:\n";
            echo '  php tools/new_custom_template.php ' . $tenantId . "\n";
            echo "  (ver docs/modules/branding-plantillas.md)\n";
        }
        exit;
    }

    case 'preview': {
        // Misma factura canned que POST /api/branding/preview: sin e_ncf ->
        // el generador estampa "PREVIEW - Sin validez fiscal".
        if (!TenantResolver::resolveById($tenantId)) {
            plFail(404, "No se pudo resolver el tenant {$tenantId}.");
        }
        BrandingResolver::reset(); // branding fresco del tenant recien resuelto

        require_once __DIR__ . '/../src/Utils/FacturaPdfGenerator.php';
        require_once __DIR__ . '/../src/Utils/Pdf/FacturaTemplateFactory.php';

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
        if (!empty($_POST['no_electronica'])) {
            $pdf->setNoElectronica(true);
        }
        if (!empty($_POST['grid'])) {
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
        $accentRgb = $accentHex !== null ? BrandingResolver::hexToRgb($accentHex) : null;
        $pdf->setTemplate(FacturaTemplateFactory::create($template, $accentRgb));
        $pdfContent = $pdf->generatePdf();

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="Preview_tenant' . $tenantId . '.pdf"');
        header('Content-Length: ' . strlen($pdfContent));
        echo $pdfContent;
        exit;
    }

    case 'apply': {
        if ($template === null) {
            plFail(422, 'Elige una plantilla para aplicar.');
        }
        // El acento se escribe siempre: vacio limpia (NULL), como
        // PUT /api/branding {"accent_color": null}.
        MasterDatabase::getInstance()->updateTenantBranding($tenantId, [
            'pdf_template'     => $template,
            'pdf_accent_color' => $accentHex,
        ]);
        header('Content-Type: text/plain; charset=utf-8');
        echo "OK: tenant '{$tenant['nombre']}' (id {$tenantId}) ahora usa la plantilla '{$template}'"
            . ($accentHex !== null ? " con acento {$accentHex}" : ' sin acento') . ".\n";
        exit;
    }

    default:
        plFail(422, "Accion desconocida: '{$action}'. Usa info, preview o apply.");
}
