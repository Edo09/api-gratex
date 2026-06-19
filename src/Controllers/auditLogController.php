<?php
header('Access-Control-Allow-Origin: *');
header("Access-Control-Allow-Headers: X-API-KEY, X-API-SECRET, Authorization, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header('content-type: application/json; charset=utf-8');

require_once(__DIR__ . '/../Models/AuditLogModel.php');
require_once(__DIR__ . '/../Models/RoleModel.php');
require_once(__DIR__ . '/../Middleware/AuthMiddleware.php');
require_once(__DIR__ . '/../PermissionGate.php');

$auditModel = new AuditLogModel();
$roleModel  = new RoleModel();
$auth       = new AuthMiddleware();

// La bitacora es informacion sensible (quien hizo que): se exige el modulo
// 'audit' (admin via '*') SIEMPRE, aun en modo sombra. Solo lectura.
$me = null;
if ($_SERVER['REQUEST_METHOD'] !== 'OPTIONS') {
    $me = $auth->validateRequest();
    if (empty($me['valid'])) {
        $auth->sendUnauthorized($me['message'] ?? 'Unauthorized');
    }
    if (($me['user_id'] ?? null) === null) {
        $auth->sendForbidden('Esta ruta requiere una sesion de usuario.');
    }
    $myPerms = $roleModel->getPermissionsForRole($me['tenant_id'] ?? null, (string) ($me['role'] ?? ''));
    if (!PermissionGate::permMatches($myPerms, 'audit')) {
        $auth->sendForbidden('No tiene permiso para ver la bitacora de auditoria.');
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => false, 'error' => 'Solo GET (la bitacora es inmutable).']);
    return;
}

// Aislamiento: SIEMPRE el tenant del solicitante (null en single-tenant).
$tenantId = $me['tenant_id'] ?? null;

$filters = ['tenant_id' => $tenantId];
foreach (['user_id', 'module', 'action', 'entity_type', 'entity_id'] as $f) {
    if (isset($_GET[$f]) && $_GET[$f] !== '') {
        $filters[$f] = $_GET[$f];
    }
}
if (isset($_GET['success']) && $_GET['success'] !== '') {
    $filters['success'] = (int) filter_var($_GET['success'], FILTER_VALIDATE_BOOLEAN);
}
if (!empty($_GET['from'])) { $filters['from'] = $_GET['from']; } // 'YYYY-MM-DD' o datetime
if (!empty($_GET['to']))   { $filters['to']   = $_GET['to']; }

$page     = (isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0) ? (int) $_GET['page'] : 1;
$pageSize = (isset($_GET['pageSize']) && is_numeric($_GET['pageSize']) && $_GET['pageSize'] > 0) ? min((int) $_GET['pageSize'], 200) : 25;
$offset   = ($page - 1) * $pageSize;

try {
    $rows  = $auditModel->search($filters, $offset, $pageSize);
    $total = $auditModel->count($filters);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => false, 'error' => 'No se pudo consultar la bitacora.']);
    return;
}

// Decodificar old/new_values a objeto para el front (reconstruccion de cambios).
foreach ($rows as &$r) {
    foreach (['old_values', 'new_values'] as $col) {
        if (!empty($r[$col])) {
            $decoded = json_decode($r[$col], true);
            $r[$col] = (json_last_error() === JSON_ERROR_NONE) ? $decoded : $r[$col];
        }
    }
    $r['success'] = (int) $r['success'] === 1;
}
unset($r);

echo json_encode([
    'status' => true,
    'data'   => $rows,
    'pagination' => [
        'page'       => $page,
        'pageSize'   => $pageSize,
        'total'      => $total,
        'totalPages' => (int) ceil($total / $pageSize),
    ],
]);
