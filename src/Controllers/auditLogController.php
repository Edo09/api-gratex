<?php
header('Access-Control-Allow-Origin: *');
header("Access-Control-Allow-Headers: X-API-KEY, X-API-SECRET, Authorization, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header('content-type: application/json; charset=utf-8');

// Bitacora de auditoria (solo lectura), del tenant del solicitante:
//   GET /api/audit-logs            -> filas paginadas
//   GET /api/audit-logs/resumen    -> tarjetas: totales, fallidos, denegados, top usuarios, por modulo
//   GET /api/audit-logs/facetas    -> modulos, acciones y usuarios presentes (para los filtros)
// Filtros (lista y resumen): user_id, module, action, entity_type, entity_id,
// success (1|0), from, to (YYYY-MM-DD, el dia de `to` entra completo), q (texto
// libre sobre usuario, email, entidad, descripcion, endpoint e IP).

require_once(__DIR__ . '/../Models/AuditLogModel.php');
require_once(__DIR__ . '/../Middleware/AuthMiddleware.php');
require_once(__DIR__ . '/../Middleware/AuditMiddleware.php');

$auditModel = new AuditLogModel();
$auth       = new AuthMiddleware();

// SOLO el rol de sistema 'admin'. No alcanza con el permiso '*': RoleModel deja
// crear roles personalizados con '*', y la bitacora (quien hizo que, desde
// donde) es del administrador de la empresa. Tampoco con el modulo 'audit',
// que por API se le puede asignar a cualquier rol. Se exige aun en modo sombra.
const AUDIT_ROL_PERMITIDO = 'admin';

$me = null;
if ($_SERVER['REQUEST_METHOD'] !== 'OPTIONS') {
    $me = $auth->validateRequest();
    if (empty($me['valid'])) {
        $auth->sendUnauthorized($me['message'] ?? 'Unauthorized');
    }
    if (($me['user_id'] ?? null) === null) {
        $auth->sendForbidden('Esta ruta requiere una sesion de usuario.');
    }
    if (strtolower((string) ($me['role'] ?? '')) !== AUDIT_ROL_PERMITIDO) {
        AuditMiddleware::logAccessDenied(
            'audit',
            'rol distinto de admin',
            ['ruta' => 'audit-logs', 'metodo' => $_SERVER['REQUEST_METHOD'], 'rol' => (string) ($me['role'] ?? ''), 'bloqueado' => true],
            'Acceso denegado a la bitacora: solo el rol admin puede verla.'
        );
        $auth->sendForbidden('Solo el rol admin puede ver la bitacora.');
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => false, 'error' => 'Solo GET (la bitacora es inmutable).']);
    return;
}

// Aislamiento: SIEMPRE el tenant del solicitante (null en single-tenant).
$tenantId = isset($me['tenant_id']) && $me['tenant_id'] !== null ? (int) $me['tenant_id'] : null;

$endpoint = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$subruta = preg_match('#/audit-logs/(resumen|facetas)/?$#', $endpoint, $m) ? $m[1] : '';

/** Filtros del query string, con el tenant forzado. */
function auditFiltros(?int $tenantId): array
{
    $filters = ['tenant_id' => $tenantId];
    foreach (['user_id', 'module', 'action', 'entity_type', 'entity_id', 'q'] as $f) {
        if (isset($_GET[$f]) && is_string($_GET[$f]) && trim($_GET[$f]) !== '') {
            $filters[$f] = trim($_GET[$f]);
        }
    }
    if (isset($_GET['success']) && $_GET['success'] !== '') {
        $filters['success'] = (int) filter_var($_GET['success'], FILTER_VALIDATE_BOOLEAN);
    }
    foreach (['from', 'to'] as $f) {
        $v = isset($_GET[$f]) && is_string($_GET[$f]) ? trim($_GET[$f]) : '';
        if ($v === '') {
            continue;
        }
        // Una fecha sola en `to` significa "hasta el final de ese dia": con
        // `created_at <= '2026-09-21'` quedaba fuera todo lo del 21.
        if ($f === 'to' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            $v .= ' 23:59:59';
        }
        $filters[$f] = $v;
    }
    return $filters;
}

try {
    if ($subruta === 'facetas') {
        echo json_encode(['status' => true, 'data' => $auditModel->facets($tenantId)]);
        return;
    }

    $filters = auditFiltros($tenantId);

    if ($subruta === 'resumen') {
        echo json_encode(['status' => true, 'data' => $auditModel->summary($filters)]);
        return;
    }

    $page     = (isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0) ? (int) $_GET['page'] : 1;
    $pageSize = (isset($_GET['pageSize']) && is_numeric($_GET['pageSize']) && $_GET['pageSize'] > 0) ? min((int) $_GET['pageSize'], 200) : 25;
    $offset   = ($page - 1) * $pageSize;

    $rows  = $auditModel->search($filters, $offset, $pageSize);
    $total = $auditModel->count($filters);
} catch (Throwable $e) {
    error_log('[auditLog] consulta fallo: ' . $e->getMessage());
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
