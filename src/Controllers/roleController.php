<?php
header('Access-Control-Allow-Origin: *');
header("Access-Control-Allow-Headers: X-API-KEY, X-API-SECRET, Authorization, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header('content-type: application/json; charset=utf-8');

require_once(__DIR__ . '/../Middleware/AuthMiddleware.php');
require_once(__DIR__ . '/../Models/RoleModel.php');
require_once(__DIR__ . '/../PermissionGate.php');

$auth = new AuthMiddleware();
$roleModel = new RoleModel();

// Gestion de roles = vector de escalada de privilegios. Se exige el modulo
// 'roles' (lo tiene admin via '*'; o un rol custom al que se le otorgue) SIEMPRE,
// independiente de PERMISSIONS_ENFORCE (no se deja en sombra).
if ($_SERVER['REQUEST_METHOD'] !== 'OPTIONS') {
    $v = $auth->validateRequest();
    if (empty($v['valid'])) {
        $auth->sendUnauthorized($v['message'] ?? 'Unauthorized');
    }
    if (($v['user_id'] ?? null) === null) {
        $auth->sendForbidden('Esta ruta requiere una sesion de usuario.');
    }
    $myPerms = $roleModel->getPermissionsForRole($v['tenant_id'] ?? null, (string) ($v['role'] ?? ''));
    if (!PermissionGate::permMatches($myPerms, 'roles')) {
        $auth->sendForbidden('No tiene permiso para gestionar roles.');
    }
}

$tenantId = $v['tenant_id'] ?? null;

// Sub-ruta despues de /api/roles/  -> {id} | 'assign'
$uriPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$apiPos = strpos($uriPath, '/api/');
$rest = $apiPos !== false ? substr($uriPath, $apiPos + 5) : ltrim($uriPath, '/');
$segs = explode('/', trim($rest, '/'));
$sub = $segs[1] ?? null;

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    $body = [];
}

function roles_respond($payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        if ($sub !== null && ctype_digit((string) $sub)) {
            $role = $roleModel->getRoleById($tenantId, (int) $sub);
            if (!$role) {
                roles_respond(['status' => false, 'error' => 'Rol no encontrado'], 404);
            }
            roles_respond(['status' => true, 'data' => $role]);
        }
        roles_respond(['status' => true, 'data' => $roleModel->listRoles($tenantId)]);
        break;

    case 'POST':
        $name = isset($body['name']) ? (string) $body['name'] : '';
        $desc = isset($body['description']) ? (string) $body['description'] : null;
        $perms = isset($body['permissions']) && is_array($body['permissions']) ? $body['permissions'] : [];
        if ($name === '' || empty($perms)) {
            roles_respond(['status' => false, 'error' => 'Se requieren name y permissions[]'], 422);
        }
        $res = $roleModel->createRole($tenantId, $name, $desc, $perms);
        if ($res[0] === 'success') {
            roles_respond(['status' => true, 'data' => $roleModel->getRoleById($tenantId, (int) $res[1])], 201);
        }
        roles_respond(['status' => false, 'error' => $res[1]], 400);
        break;

    case 'PUT':
        // Asignar rol a un usuario:  PUT /api/roles/assign {user_id, role}
        if ($sub === 'assign') {
            $userId = isset($body['user_id']) ? (int) $body['user_id'] : 0;
            $roleName = isset($body['role']) ? (string) $body['role'] : '';
            if ($userId <= 0 || $roleName === '') {
                roles_respond(['status' => false, 'error' => 'Se requieren user_id y role'], 422);
            }
            $res = $roleModel->assignUserRole($tenantId, $userId, $roleName);
            if ($res[0] === 'success') {
                roles_respond(['status' => true, 'data' => ['user_id' => $userId, 'role' => $roleName]]);
            }
            roles_respond(['status' => false, 'error' => $res[1]], 400);
        }
        // Actualizar un rol:  PUT /api/roles/{id} {description?, permissions?}
        if ($sub === null || !ctype_digit((string) $sub)) {
            roles_respond(['status' => false, 'error' => 'Falta el id del rol'], 422);
        }
        $desc = array_key_exists('description', $body) ? (string) $body['description'] : null;
        $perms = isset($body['permissions']) && is_array($body['permissions']) ? $body['permissions'] : null;
        $res = $roleModel->updateRole($tenantId, (int) $sub, $desc, $perms);
        if ($res[0] === 'success') {
            roles_respond(['status' => true, 'data' => $roleModel->getRoleById($tenantId, (int) $sub)]);
        }
        roles_respond(['status' => false, 'error' => $res[1]], 400);
        break;

    case 'DELETE':
        if ($sub === null || !ctype_digit((string) $sub)) {
            roles_respond(['status' => false, 'error' => 'Falta el id del rol'], 422);
        }
        $res = $roleModel->deleteRole($tenantId, (int) $sub);
        if ($res[0] === 'success') {
            roles_respond(['status' => true, 'data' => 'Rol eliminado']);
        }
        roles_respond(['status' => false, 'error' => $res[1]], 400);
        break;

    default:
        roles_respond(['status' => false, 'error' => 'Metodo no soportado'], 405);
}
