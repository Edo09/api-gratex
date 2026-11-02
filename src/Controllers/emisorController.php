<?php
// Datos fiscales del emisor (Representacion Impresa / e-CF).
// Ruta: /api/emisor (requiere token)
//   GET /api/emisor -> emisor_config del tenant (fila unica id=1); una empresa de la edicion Express recibe ademas
//                      data.certificado (estado del certificado, solo informativo)
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
    // El texto de la excepcion (SQLSTATE, conexion al DB del tenant) no es para
    // la pantalla de Configuracion: queda en el log.
    error_log('[emisor] no se pudo leer emisor_config: ' . $e->getMessage());
    emisorRespond(false, 'No se pudieron cargar los datos de tu empresa. Inténtalo de nuevo en unos minutos.', 500);
}

if (!$emisor) {
    emisorRespond(false, 'Tu empresa todavía no tiene sus datos fiscales configurados. Comunícate con soporte para completarlos.', 404);
}

unset($emisor['id']); // fila unica, el id no aporta
// Ambiente del tenant (per-tenant, no el env global) cuando aplica.
if ($tenant && isset($tenant['ambiente'])) {
    $emisor['ambiente'] = $tenant['ambiente'];
}
$emisor['fuente'] = 'emisor_config';

// Fiscalpoint Express: el estado del certificado (vigente, por_vencer, vencido, no_configurado o sin_dato), solo informativo y
// solo para una empresa de esa edicion (una 'ambas' la recibe unicamente en las peticiones que se anuncian como Express). La prueba va
// en linea para que ninguna otra empresa cargue TenantEntitlements; si la clase no carga, la clave se omite: el aviso es un extra y
// Configuracion sigue leyendo los datos del emisor.
if (TenantResolver::esFilaExpress($tenant) && TenantResolver::cargarEntitlements()) {
    $emisor['certificado'] = TenantEntitlements::estadoCertificado($tenant);
}

emisorRespond(true, $emisor);
