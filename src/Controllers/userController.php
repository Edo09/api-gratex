<?php
header('Access-Control-Allow-Origin: *');
header("Access-Control-Allow-Headers: X-API-KEY, X-API-SECRET, Authorization, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header('content-type: application/json; charset=utf-8');

require_once(__DIR__ . '/../Models/userModel.php');
require_once(__DIR__ . '/../Models/authModel.php');
require_once(__DIR__ . '/../Models/RoleModel.php');
require_once(__DIR__ . '/../Middleware/AuthMiddleware.php');
require_once(__DIR__ . '/../PermissionGate.php');

$userModel = new userModel();
$authModel = new authModel();
$roleModel = new RoleModel();
$auth = new AuthMiddleware();

// Gestion de usuarios = vector de escalada de privilegios (crear usuarios,
// asignar roles). Se exige el modulo 'users' (admin via '*') SIEMPRE, aun en
// modo sombra (no se deja sin proteger durante el rollout).
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
    if (!PermissionGate::permMatches($myPerms, 'users')) {
        $auth->sendForbidden('No tiene permiso para gestionar usuarios.');
    }
}

$tenantId = $me['tenant_id'] ?? null;

// Sub-ruta despues de /api/users/ -> {id}
$uriPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$apiPos = strpos($uriPath, '/api/');
$rest = $apiPos !== false ? substr($uriPath, $apiPos + 5) : ltrim($uriPath, '/');
$segs = explode('/', trim($rest, '/'));
$sub = $segs[1] ?? null;

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    $body = [];
}

function users_respond($payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

/** Nombres de rol validos para este tenant. */
function tenant_role_names(RoleModel $roleModel, ?int $tenantId): array
{
    return array_map(fn($r) => $r['name'], $roleModel->listRoles($tenantId));
}

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        if ($sub !== null && ctype_digit((string) $sub)) {
            $u = $userModel->getUser($tenantId, (int) $sub);
            if (!$u) {
                users_respond(['status' => false, 'error' => 'Usuario no encontrado'], 404);
            }
            users_respond(['status' => true, 'data' => $u]);
        }
        users_respond(['status' => true, 'data' => $userModel->listUsers($tenantId)]);
        break;

    case 'POST':
        $email    = trim((string) ($body['email'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $name     = trim((string) ($body['name'] ?? ''));
        $username = trim((string) ($body['username'] ?? ''));
        $role     = isset($body['role']) ? trim((string) $body['role']) : 'user';

        if ($email === '' || $password === '' || $name === '' || $username === '') {
            users_respond(['status' => false, 'error' => 'Se requieren email, password, name y username'], 422);
        }
        if (strlen($password) < 4) {
            users_respond(['status' => false, 'error' => 'El password debe tener al menos 4 caracteres'], 422);
        }
        if (!in_array($role, tenant_role_names($roleModel, $tenantId), true)) {
            users_respond(['status' => false, 'error' => "El rol '{$role}' no existe en este tenant."], 422);
        }

        // tenant_id viene del TOKEN (no del body): no se crean usuarios en otro tenant.
        $res = $authModel->registerUser($email, $password, $name, $username, $tenantId, $role);
        if ($res[0] === 'success') {
            AuditLogger::log([
                'module' => 'users', 'action' => 'CREATE',
                'entity_type' => 'user', 'entity_id' => $res[1]['id'] ?? null,
                'new_values' => ['email' => $email, 'name' => $name, 'username' => $username, 'role' => $role],
                'description' => 'Usuario creado.',
            ]);
            users_respond(['status' => true, 'data' => $res[1]], 201);
        }
        users_respond(['status' => false, 'error' => $res[1]], 400);
        break;

    case 'PUT':
        $id = $sub !== null && ctype_digit((string) $sub) ? (int) $sub : (int) ($body['id'] ?? 0);
        if ($id <= 0) {
            users_respond(['status' => false, 'error' => 'Falta el id del usuario'], 422);
        }
        // Si se cambia el rol, validar que exista en el tenant.
        if (isset($body['role']) && !in_array(trim((string) $body['role']), tenant_role_names($roleModel, $tenantId), true)) {
            users_respond(['status' => false, 'error' => "El rol '{$body['role']}' no existe en este tenant."], 422);
        }
        $fields = [];
        foreach (['name', 'last_name', 'email', 'username', 'role', 'password'] as $f) {
            if (array_key_exists($f, $body)) {
                $fields[$f] = $body[$f];
            }
        }
        $oldUser = $userModel->getUser($tenantId, $id);
        $res = $userModel->updateUser($tenantId, $id, $fields);
        if ($res[0] === 'success') {
            $newUser = $userModel->getUser($tenantId, $id);
            AuditLogger::log([
                'module' => 'users', 'action' => 'UPDATE',
                'entity_type' => 'user', 'entity_id' => $id,
                'old_values' => $oldUser, 'new_values' => $newUser,
                'description' => 'Usuario actualizado.',
            ]);
            users_respond(['status' => true, 'data' => $newUser]);
        }
        users_respond(['status' => false, 'error' => $res[1]], 400);
        break;

    case 'DELETE':
        $id = $sub !== null && ctype_digit((string) $sub) ? (int) $sub : (int) ($body['id'] ?? 0);
        if ($id <= 0) {
            users_respond(['status' => false, 'error' => 'Falta el id del usuario'], 422);
        }
        if ($id === (int) ($me['user_id'] ?? 0)) {
            users_respond(['status' => false, 'error' => 'No puedes borrar tu propio usuario.'], 400);
        }
        $oldUser = $userModel->getUser($tenantId, $id);
        $res = $userModel->deleteUser($tenantId, $id);
        if ($res[0] === 'success') {
            AuditLogger::log([
                'module' => 'users', 'action' => 'DELETE',
                'entity_type' => 'user', 'entity_id' => $id,
                'old_values' => $oldUser, 'description' => 'Usuario eliminado.',
            ]);
            users_respond(['status' => true, 'data' => 'Usuario eliminado']);
        }
        users_respond(['status' => false, 'error' => $res[1]], 400);
        break;

    default:
        users_respond(['status' => false, 'error' => 'Metodo no soportado'], 405);
}
