<?php
/**
 * upload_logo.php — Subir/cambiar el logo de un tenant existente.
 *
 * Servido directo (bajo /api/public/). GET muestra el formulario; POST guarda
 * el logo en logos/<tenant_id>.<ext> (lo usa la Representacion Impresa).
 *
 *   https://gratex.net/api/public/upload_logo.php
 *
 * Edita UPLOAD_LOGO_TOKEN antes de usar.
 */

// === Token (editar antes de usar) ==========================================
const UPLOAD_LOGO_TOKEN = 'CAMBIA_ESTE_TOKEN_LOGO';
// ===========================================================================

require_once __DIR__ . '/../src/MasterDatabase.php';
require_once __DIR__ . '/../src/Utils/LogoStorage.php';

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

if (!$isPost) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Subir logo de tenant</title>'
        . '<style>body{font-family:system-ui,sans-serif;background:#0f172a;color:#e2e8f0;display:flex;'
        . 'justify-content:center;padding:32px}form{background:#1e293b;border:1px solid #334155;'
        . 'border-radius:12px;padding:24px;width:100%;max-width:420px}label{display:block;font-size:13px;'
        . 'color:#94a3b8;margin:12px 0 4px}input{width:100%;padding:10px;background:#0b1220;color:#e2e8f0;'
        . 'border:1px solid #334155;border-radius:8px;box-sizing:border-box}button{width:100%;margin-top:18px;'
        . 'padding:12px;background:#38bdf8;color:#06283d;border:none;border-radius:8px;font-weight:600;cursor:pointer}'
        . 'h1{font-size:18px}</style></head><body>'
        . '<form method="post" enctype="multipart/form-data">'
        . '<h1>Subir logo de tenant</h1>'
        . '<label>Token</label><input type="password" name="token" autocomplete="off" required>'
        . '<label>Tenant ID</label><input name="tenant_id" inputmode="numeric" required>'
        . '<label>Logo (PNG/JPG)</label><input type="file" name="logo" accept=".png,.jpg,.jpeg" required>'
        . '<button type="submit">Guardar logo</button>'
        . '</form></body></html>';
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

if (UPLOAD_LOGO_TOKEN === 'CAMBIA_ESTE_TOKEN_LOGO') {
    http_response_code(403);
    exit("Configura UPLOAD_LOGO_TOKEN en el archivo antes de usarlo.\n");
}
if (!hash_equals(UPLOAD_LOGO_TOKEN, (string) ($_POST['token'] ?? ''))) {
    http_response_code(403);
    exit("Token invalido.\n");
}

$tenantId = (int) ($_POST['tenant_id'] ?? 0);
if ($tenantId <= 0) {
    http_response_code(422);
    exit("tenant_id invalido.\n");
}

// Verificar que el tenant exista en master.
$tenant = MasterDatabase::getInstance()->getTenantById($tenantId);
if (!$tenant) {
    http_response_code(404);
    exit("Tenant {$tenantId} no encontrado (o inactivo).\n");
}

// Validacion + guardado compartidos con POST /api/branding/logo.
$result = LogoStorage::store($tenantId, $_FILES['logo'] ?? []);
if (!$result['ok']) {
    http_response_code($result['code'] ?? 422);
    exit($result['error'] . "\n");
}

// Registrar la ruta en DB (tenants.logo_path).
$rel = $result['logo_path'];
MasterDatabase::getInstance()->updateTenantBranding($tenantId, ['logo_path' => $rel]);

echo "OK: logo guardado en {$rel} para tenant '{$tenant['nombre']}' (RNC {$tenant['rnc']}).\n";
