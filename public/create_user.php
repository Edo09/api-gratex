<?php
/**
 * create_user.php — Alta de usuarios para un tenant (login de la app).
 *
 * Sirve directo (bajo /api/public/). Inserta en master.users con tenant_id.
 * GET muestra el formulario; POST crea el usuario.
 *
 *   https://gratex.net/api/public/create_user.php
 *
 * Edita CREATE_USER_TOKEN antes de usar. email y username son UNICOS globales,
 * asi que el login (por email o username) no necesita tenant_id (ver multi-tenant).
 */

const CREATE_USER_TOKEN = 'gratextoken.';

require_once __DIR__ . '/../src/MasterDatabase.php';

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

if (!$isPost) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Alta de usuario</title>'
        . '<style>body{font-family:system-ui,sans-serif;background:#0f172a;color:#e2e8f0;display:flex;'
        . 'justify-content:center;padding:32px}form{background:#1e293b;border:1px solid #334155;'
        . 'border-radius:12px;padding:24px;width:100%;max-width:440px}label{display:block;font-size:13px;'
        . 'color:#94a3b8;margin:12px 0 4px}input,select{width:100%;padding:10px;background:#0b1220;color:#e2e8f0;'
        . 'border:1px solid #334155;border-radius:8px;box-sizing:border-box}button{width:100%;margin-top:18px;'
        . 'padding:12px;background:#38bdf8;color:#06283d;border:none;border-radius:8px;font-weight:600;cursor:pointer}'
        . 'h1{font-size:18px}</style></head><body>'
        . '<form method="post">'
        . '<h1>Alta de usuario (tenant)</h1>'
        . '<label>Token</label><input type="password" name="token" autocomplete="off" required>'
        . '<label>Tenant ID</label><input name="tenant_id" inputmode="numeric" required>'
        . '<label>Nombre</label><input name="name" required>'
        . '<label>Apellido</label><input name="last_name">'
        . '<label>Email (login, único global)</label><input name="email" type="email" required>'
        . '<label>Username (único por tenant)</label><input name="username" required>'
        . '<label>Password</label><input name="password" type="password" autocomplete="off" required>'
        . '<label>Rol</label><select name="role"><option value="user">user</option><option value="admin">admin</option></select>'
        . '<button type="submit">Crear usuario</button>'
        . '</form></body></html>';
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

if (CREATE_USER_TOKEN === 'CAMBIA_ESTE_TOKEN_USUARIO') {
    http_response_code(403);
    exit("Configura CREATE_USER_TOKEN en el archivo antes de usarlo.\n");
}
if (!hash_equals(CREATE_USER_TOKEN, (string) ($_POST['token'] ?? ''))) {
    http_response_code(403);
    exit("Token invalido.\n");
}

$tenantId = (int) ($_POST['tenant_id'] ?? 0);
$name     = trim((string) ($_POST['name'] ?? ''));
$lastName = trim((string) ($_POST['last_name'] ?? ''));
$email    = trim((string) ($_POST['email'] ?? ''));
$username = trim((string) ($_POST['username'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
$role     = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';

if ($tenantId <= 0 || $name === '' || $email === '' || $username === '' || $password === '') {
    http_response_code(422);
    exit("Faltan campos requeridos (tenant_id, name, email, username, password).\n");
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    exit("Email invalido.\n");
}

$master = MasterDatabase::getInstance();
$tenant = $master->getTenantById($tenantId);
if (!$tenant) {
    http_response_code(404);
    exit("Tenant {$tenantId} no encontrado (o inactivo).\n");
}

$conn = $master->getConnection();

// Email unico global.
$stmt = $conn->prepare('SELECT id FROM users WHERE email = :e LIMIT 1');
$stmt->execute([':e' => $email]);
if ($stmt->fetch()) {
    http_response_code(409);
    exit("Ese email ya esta registrado (debe ser unico global).\n");
}
// Username unico por tenant.
$stmt = $conn->prepare('SELECT id FROM users WHERE username = :u AND tenant_id = :t LIMIT 1');
$stmt->execute([':u' => $username, ':t' => $tenantId]);
if ($stmt->fetch()) {
    http_response_code(409);
    exit("Ese username ya existe en este tenant.\n");
}

$ins = $conn->prepare(
    'INSERT INTO users (tenant_id, name, last_name, email, username, password, role)
     VALUES (:tenant_id, :name, :last_name, :email, :username, :password, :role)'
);
$ins->execute([
    ':tenant_id' => $tenantId,
    ':name'      => $name,
    ':last_name' => $lastName,
    ':email'     => $email,
    ':username'  => $username,
    ':password'  => password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]),
    ':role'      => $role,
]);

$uid = (int) $conn->lastInsertId();
echo "OK: usuario #{$uid} ({$email}, {$role}) creado para tenant '{$tenant['nombre']}' (id {$tenantId}).\n";
echo "Puede loguear con: POST /api/auth/login {\"emailOrUsername\":\"{$email}\",\"password\":\"...\"}\n";
