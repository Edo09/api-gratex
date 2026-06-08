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

if (!isset($_FILES['logo']) || ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    http_response_code(422);
    exit("No se recibio el archivo de logo.\n");
}

$ext = strtolower(pathinfo((string) ($_FILES['logo']['name'] ?? ''), PATHINFO_EXTENSION));
if (!in_array($ext, ['png', 'jpg', 'jpeg'], true)) {
    http_response_code(422);
    exit("Extension '{$ext}' no permitida (png/jpg).\n");
}
$ext = $ext === 'jpeg' ? 'jpg' : $ext;

$logosDir = __DIR__ . '/../logos';
if (!is_dir($logosDir) && !mkdir($logosDir, 0755, true) && !is_dir($logosDir)) {
    http_response_code(500);
    exit("No se pudo crear la carpeta logos/.\n");
}
// Quitar logos previos del tenant (cualquier extension).
foreach (['png', 'jpg', 'jpeg'] as $old) {
    $p = $logosDir . '/' . $tenantId . '.' . $old;
    if (is_file($p)) {
        @unlink($p);
    }
}
$dest = $logosDir . '/' . $tenantId . '.' . $ext;
if (!move_uploaded_file($_FILES['logo']['tmp_name'], $dest)) {
    http_response_code(500);
    exit("No se pudo guardar el logo (revisa permisos de logos/).\n");
}

// Registrar la ruta en DB (tenants.logo_path).
$rel = 'logos/' . $tenantId . '.' . $ext;
MasterDatabase::getInstance()->getConnection()
    ->prepare('UPDATE tenants SET logo_path = :p WHERE id = :id')
    ->execute([':p' => $rel, ':id' => $tenantId]);

echo "OK: logo guardado en {$rel} para tenant '{$tenant['nombre']}' (RNC {$tenant['rnc']}).\n";
