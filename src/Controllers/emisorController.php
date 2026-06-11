<?php
// Datos fiscales del emisor (Representacion Impresa / e-CF).
// Ruta: /api/emisor (requiere token)
//   GET /api/emisor -> emisor_config del tenant (fila unica id=1)
//
// Tenants tipo "integracion" no tienen DB propia (no hay emisor_config):
// se responde con los datos del registro del tenant en master (nombre, rnc),
// igual que hace la recepcion DGII (ver ecfRecepcionController).
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-API-KEY, X-API-SECRET, Authorization, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Allow: GET, OPTIONS');
header('content-type: application/json; charset=utf-8');

require_once __DIR__ . '/../Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../Models/EmisorConfigModel.php';

$auth = new AuthMiddleware();
if ($_SERVER['REQUEST_METHOD'] !== 'OPTIONS') {
    $validation = $auth->validateRequest();
    if (!$validation['valid']) {
        $auth->sendUnauthorized($validation['message']);
    }
}

function emisorRespond(bool $ok, $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($ok ? ['status' => true, 'data' => $payload] : ['status' => false, 'error' => $payload]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    emisorRespond(false, 'Metodo no soportado', 405);
}

$tenant = class_exists('TenantResolver') ? TenantResolver::current() : null;

// Integracion: sin DB propia -> datos del registro del tenant en master.
if ($tenant && ($tenant['tipo'] ?? 'app') === 'integracion') {
    emisorRespond(true, [
        'rnc'          => $tenant['rnc'] ?? null,
        'razon_social' => $tenant['nombre'] ?? null,
        'ambiente'     => $tenant['ambiente'] ?? null,
        'fuente'       => 'tenant',
    ]);
}

try {
    $emisor = (new EmisorConfigModel())->get();
} catch (Throwable $e) {
    emisorRespond(false, 'No se pudo leer emisor_config: ' . $e->getMessage(), 500);
}

if (!$emisor) {
    emisorRespond(false, 'emisor_config no configurado en este sistema.', 404);
}

unset($emisor['id']); // fila unica, el id no aporta
// Ambiente del tenant (per-tenant, no el env global) cuando aplica.
if ($tenant && isset($tenant['ambiente'])) {
    $emisor['ambiente'] = $tenant['ambiente'];
}
$emisor['fuente'] = 'emisor_config';

emisorRespond(true, $emisor);
