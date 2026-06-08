<?php
/**
 * integracionConsultaController — Polling de documentos entrantes (integracion).
 *
 *   GET /api/integracion/recibidos     -> e-CF que le facturaron al tenant
 *   GET /api/integracion/aprobaciones  -> aprobaciones comerciales recibidas
 *   Headers: X-API-KEY + X-API-SECRET (tenant tipo integracion)
 *   Query: ?page=1&pageSize=20
 *
 * Lee del master DB filtrando por tenant_id (aislamiento por tenant).
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-API-KEY, X-API-SECRET, Authorization, Origin, X-Requested-With, Content-Type, Accept');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../TenantResolver.php';
require_once __DIR__ . '/../Models/IntegracionStoreModel.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    return;
}

$auth = new AuthMiddleware();
$validation = $auth->validateRequest();
if (!$validation['valid']) {
    $auth->sendUnauthorized($validation['message']);
}
if (!TenantResolver::isIntegration()) {
    http_response_code(403);
    echo json_encode(['status' => false, 'error' => 'Endpoint solo para tenants tipo integracion.']);
    return;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => false, 'error' => 'Solo GET permitido en este endpoint.']);
    return;
}

handleIntegracionConsulta();

function handleIntegracionConsulta(): void
{
    $tenant = TenantResolver::current();
    $tenantId = (int) $tenant['id'];

    $endpoint = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '';
    $recurso = str_contains($endpoint, 'aprobaciones') ? 'aprobaciones' : 'recibidos';

    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int) $_GET['page'] : 1;
    $pageSize = isset($_GET['pageSize']) && is_numeric($_GET['pageSize']) && $_GET['pageSize'] > 0
        ? min((int) $_GET['pageSize'], 100)
        : 20;
    $offset = ($page - 1) * $pageSize;

    $store = new IntegracionStoreModel();
    if ($recurso === 'aprobaciones') {
        $rows = $store->listAprobaciones($tenantId, $offset, $pageSize);
        $total = $store->countAprobaciones($tenantId);
    } else {
        $rows = $store->listRecibidos($tenantId, $offset, $pageSize);
        $total = $store->countRecibidos($tenantId);
    }

    echo json_encode([
        'status' => true,
        'recurso' => $recurso,
        'data' => $rows,
        'pagination' => [
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $total,
            'totalPages' => $pageSize > 0 ? (int) ceil($total / $pageSize) : 0,
        ],
    ]);
}
