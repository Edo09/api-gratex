<?php
/**
 * audit_logs.php — Bitacora de auditoria (audit_logs) de TODOS los tenants, para
 * consultarla sin entrar a la base de datos.
 *
 * Servido directo (bajo /api/public/). La interfaz es audit_logs.html:
 *
 *   https://gratex.net/api/public/audit_logs.html
 *
 * Herramienta de operaciones: a diferencia de GET /api/audit-logs (admin del
 * tenant, acotado a SU tenant), esta ve la bitacora de todos los tenants con un
 * token de operaciones propio. Solo lectura: no hay accion que escriba.
 *
 * Acciones (POST, campo "action"; responde JSON):
 *   meta   -> tenants + modulos y acciones distintos (para poblar los filtros)
 *   search -> filas paginadas. Filtros: tenant ('' todos | 'none' sin tenant |
 *             id), module, log_action (el filtro por accion; se llama asi porque
 *             `action` ya nombra la operacion de arriba), success ('' | 1 | 0),
 *             from / to (YYYY-MM-DD, dias completos), q (texto: usuario, email,
 *             entidad, descripcion, endpoint, IP), page, page_size (25 | 50 | 100)
 *
 * El token se lee de AUDIT_LOGS_TOKEN en el .env del server (nunca hardcodeado en
 * el repo). La bitacora trae emails, IPs y valores antes/despues de todos los
 * tenants: token largo y aleatorio, HTTPS, y considerar Basic Auth de cPanel.
 * Guia: docs/modules/auditoria.md ("Vista web de operaciones").
 */

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/MasterDatabase.php';
require_once __DIR__ . '/../src/Models/AuditLogModel.php';

// .env al inicio: el token de operaciones y MULTI_TENANT_ENABLED viven en el entorno.
Database::loadEnv();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function alRespond(int $code, array $body): void
{
    http_response_code($code);
    // User-Agents y valores libres pueden traer UTF-8 invalido: sin SUBSTITUTE,
    // json_encode devuelve false y la respuesta sale vacia.
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function alFail(int $code, string $msg): void
{
    alRespond($code, ['status' => false, 'error' => $msg]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    alFail(405, 'Usa POST. La interfaz esta en audit_logs.html.');
}

$expectedToken = (string) (getenv('AUDIT_LOGS_TOKEN') ?: ($_ENV['AUDIT_LOGS_TOKEN'] ?? ''));
if ($expectedToken === '' || $expectedToken === 'CAMBIAR') {
    alFail(403, 'AUDIT_LOGS_TOKEN no configurado en el .env del server.');
}
if (!hash_equals($expectedToken, (string) ($_POST['token'] ?? ''))) {
    usleep(500000); // frena el tanteo del token: la bitacora es informacion sensible
    alFail(403, 'Token invalido.');
}

$multiTenant = filter_var(
    getenv('MULTI_TENANT_ENABLED') ?: ($_ENV['MULTI_TENANT_ENABLED'] ?? false),
    FILTER_VALIDATE_BOOLEAN
);

try {
    $model = new AuditLogModel();

    // id => tenant. En single-tenant no hay registro de tenants (todo va sin tenant_id).
    $tenants = [];
    if ($multiTenant) {
        foreach (MasterDatabase::getInstance()->listTenants() as $t) {
            $tenants[(int) $t['id']] = $t;
        }
    }

    $action = (string) ($_POST['action'] ?? '');

    switch ($action) {
        case 'meta':
            alRespond(200, ['status' => true, 'data' => [
                'multi_tenant' => $multiTenant,
                'tenants'      => array_values(array_map(fn($t) => [
                    'id'     => (int) $t['id'],
                    'nombre' => $t['nombre'],
                    'rnc'    => $t['rnc'],
                    'tipo'   => $t['tipo'],
                    'activo' => (int) $t['activo'] === 1,
                ], $tenants)),
                'modules'      => $model->distinctValues('module'),
                'actions'      => $model->distinctValues('action'),
            ]]);
            break;

        case 'search':
            $filters = [];

            $tenant = trim((string) ($_POST['tenant'] ?? ''));
            if ($tenant === 'none') {
                $filters['tenant_id'] = null;
            } elseif ($tenant !== '') {
                if (!ctype_digit($tenant)) {
                    alFail(422, 'tenant invalido.');
                }
                $filters['tenant_id'] = (int) $tenant;
            }

            // El filtro de accion llega como log_action: "action" ya identifica la
            // operacion (meta | search), y con el mismo nombre el segundo valor del
            // formulario pisa al primero y el handler se queda sin operacion.
            foreach (['module' => 'module', 'log_action' => 'action'] as $campo => $filtro) {
                $v = trim((string) ($_POST[$campo] ?? ''));
                if (strlen($v) > 60) {
                    alFail(422, "{$campo} demasiado largo.");
                }
                if ($v !== '') {
                    $filters[$filtro] = $v;
                }
            }

            $success = (string) ($_POST['success'] ?? '');
            if ($success === '1' || $success === '0') {
                $filters['success'] = (int) $success;
            }

            // Dias completos: "hasta 2026-09-15" incluye todo el 15, no solo las 00:00.
            foreach (['from' => ' 00:00:00', 'to' => ' 23:59:59'] as $f => $time) {
                $v = trim((string) ($_POST[$f] ?? ''));
                if ($v === '') {
                    continue;
                }
                if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                    alFail(422, "{$f} invalido: usa YYYY-MM-DD.");
                }
                $filters[$f] = $v . $time;
            }

            $q = trim((string) ($_POST['q'] ?? ''));
            if (strlen($q) > 100) {
                alFail(422, 'El texto de busqueda admite hasta 100 caracteres.');
            }
            if ($q !== '') {
                $filters['q'] = $q;
            }

            $page = min(max(1, (int) ($_POST['page'] ?? 1)), 100000);
            $pageSize = (int) ($_POST['page_size'] ?? 50);
            if (!in_array($pageSize, [25, 50, 100], true)) {
                $pageSize = 50;
            }

            $total = $model->countAllTenants($filters);
            $rows = $model->searchAllTenants($filters, ($page - 1) * $pageSize, $pageSize);

            foreach ($rows as &$r) {
                // old/new_values se guardan como JSON: se devuelven como objeto para el diff.
                foreach (['old_values', 'new_values'] as $col) {
                    if ($r[$col] !== null && $r[$col] !== '') {
                        $decoded = json_decode($r[$col], true);
                        $r[$col] = json_last_error() === JSON_ERROR_NONE ? $decoded : $r[$col];
                    }
                }
                $r['success'] = (int) $r['success'] === 1;
                $tid = $r['tenant_id'] !== null ? (int) $r['tenant_id'] : null;
                $r['tenant_id'] = $tid;
                $r['tenant_nombre'] = $tid !== null ? ($tenants[$tid]['nombre'] ?? null) : null;
            }
            unset($r);

            alRespond(200, [
                'status'     => true,
                'data'       => $rows,
                'pagination' => [
                    'page'       => $page,
                    'pageSize'   => $pageSize,
                    'total'      => $total,
                    'totalPages' => (int) ceil($total / $pageSize),
                ],
            ]);
            break;

        default:
            alFail(422, "Accion desconocida: '{$action}'. Usa meta o search.");
    }
} catch (Throwable $e) {
    error_log('[audit_logs.php] ' . $e->getMessage());
    alFail(500, 'No se pudo consultar la bitacora (detalle en el error_log del server).');
}
