<?php
require_once(__DIR__ . '/../Utils/TokenGenerator.php');
require_once(__DIR__ . '/../Database.php');
require_once(__DIR__ . '/../MasterDatabase.php');

class authModel
{
    private $conexion;

    public function __construct()
    {
        // In multi-tenant mode, auth (users + api_tokens) lives in the master DB
        // so a token can be resolved to its tenant before any business query.
        if (self::multiTenant()) {
            $this->conexion = MasterDatabase::getInstance()->getConnection();
        } else {
            $this->conexion = Database::getInstance()->getConnection();
        }
    }

    private static function multiTenant(): bool
    {
        return filter_var(
            getenv('MULTI_TENANT_ENABLED') ?: ($_ENV['MULTI_TENANT_ENABLED'] ?? false),
            FILTER_VALIDATE_BOOLEAN
        );
    }

    /**
     * Validate an API token
     * @param string $token Raw token from request
     * @return array [user_id] if valid or [null] if invalid
     */
    public function validateToken($token)
    {
        try {
            $token_hash = TokenGenerator::hashToken($token);
            // JOIN a users para traer el rol (RBAC). users vive en la misma
            // conexion que api_tokens (master en MT, tenant DB en single).
            $sql = self::multiTenant()
                ? "SELECT t.user_id, t.tenant_id, t.is_active, u.role
                     FROM api_tokens t JOIN users u ON u.id = t.user_id
                    WHERE t.token_hash = :token_hash LIMIT 1"
                : "SELECT t.user_id, t.is_active, u.role
                     FROM api_tokens t JOIN users u ON u.id = t.user_id
                    WHERE t.token_hash = :token_hash LIMIT 1";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([':token_hash' => $token_hash]);
            $row = $stmt->fetch();

            if ($row && $row['is_active'] == 1) {
                return [
                    'valid' => true,
                    'user_id' => (int)$row['user_id'],
                    'tenant_id' => isset($row['tenant_id']) ? (int)$row['tenant_id'] : null,
                    'role' => $row['role'] ?? null,
                ];
            }
            return ['valid' => false, 'user_id' => null, 'tenant_id' => null, 'role' => null];
        } catch (PDOException $e) {
            return ['valid' => false, 'user_id' => null];
        }
    }

    /**
     * Revoke (deactivate) a token by its hash
     * @param string $token_hash Hashed token
     * @return array ['success'/'error', message]
     */
    public function revokeTokenByHash($token_hash)
    {
        try {
            $sql = "UPDATE api_tokens SET is_active = 0 WHERE token_hash = :token_hash";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([':token_hash' => $token_hash]);
            return ['success', 'Signed out successfully'];
        } catch (PDOException $e) {
            return ['error', 'Failed to sign out'];
        }
    }

    /**
     * Update last_used timestamp for a token
     * @param string $token_hash Hashed token
     */
    public function updateLastUsed($token_hash)
    {
        try {
            $now = date('Y-m-d H:i:s');
            $sql = "UPDATE api_tokens SET last_used = :last_used WHERE token_hash = :token_hash";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([
                ':last_used' => $now,
                ':token_hash' => $token_hash
            ]);
        } catch (PDOException $e) {
            // Silently fail on update
        }
    }

    /**
     * Login user with email or username and password.
     * @param string $email_or_username User email or username
     * @param string $password User password
     * @param int|string|null $tenant_id Vestigial: email y username son unicos
     *        globales, asi que el tenant se resuelve sin el. Se acepta por
     *        compatibilidad con clientes viejos, pero ya no se usa.
     * @return array ['success', ['token' => token, 'user' => user_data]] or ['error', message]
     */
    public function loginUser($email_or_username, $password, $tenant_id = null)
    {
        try {
            // email y username son ambos UNIQUE global -> un solo match posible,
            // sin ambiguedad y sin necesidad de tenant_id (ver migracion master 007).
            if (self::multiTenant()) {
                $sql = "SELECT id, tenant_id, email, username, name, last_name, password, role
                        FROM users WHERE email = :eu OR username = :eu LIMIT 1";
            } else {
                $sql = "SELECT id, email, username, name, last_name, password, role
                        FROM users WHERE email = :eu OR username = :eu LIMIT 1";
            }
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([':eu' => $email_or_username]);
            $user = $stmt->fetch();

            // Verify user exists and password is correct
            if (!$user || !password_verify($password, $user['password'])) {
                return ['error', 'Invalid email or password'];
            }

            // Generate new token for this login
            $token = TokenGenerator::generateApiToken();
            $token_hash = TokenGenerator::hashToken($token);
            $created_at = date('Y-m-d H:i:s');

            if (self::multiTenant()) {
                $sql = "INSERT INTO api_tokens(user_id, tenant_id, token_hash, created_at) VALUES(:user_id, :tenant_id, :token_hash, :created_at)";
                $stmt = $this->conexion->prepare($sql);
                $stmt->execute([
                    ':user_id' => $user['id'],
                    ':tenant_id' => $user['tenant_id'],
                    ':token_hash' => $token_hash,
                    ':created_at' => $created_at
                ]);
            } else {
                $sql = "INSERT INTO api_tokens(user_id, token_hash, created_at) VALUES(:user_id, :token_hash, :created_at)";
                $stmt = $this->conexion->prepare($sql);
                $stmt->execute([
                    ':user_id' => $user['id'],
                    ':token_hash' => $token_hash,
                    ':created_at' => $created_at
                ]);
            }

            // Prepare user data response (without password). Incluye los modulos
            // a los que el rol da acceso, para que el front muestre/oculte paginas.
            $user_data = [
                'id' => (int)$user['id'],
                'email' => $user['email'],
                'username' => $user['username'],
                'name' => $user['name'] . ' ' . $user['last_name'],
                'role' => $user['role'],
                'permissions' => $this->permissionsForUser($user['tenant_id'] ?? null, $user['role']),
            ];

            return ['success', ['token' => $token, 'user' => $user_data]];
        } catch (PDOException $e) {
            return ['error', 'Database error: ' . $e->getMessage()];
        }
    }

    /**
     * Modulos (permisos) a los que da acceso un rol. Vacio si no resuelve.
     */
    private function permissionsForUser($tenant_id, $role): array
    {
        require_once __DIR__ . '/RoleModel.php';
        return (new RoleModel())->getPermissionsForRole(
            $tenant_id !== null ? (int) $tenant_id : null,
            (string) $role
        );
    }

    /**
     * Perfil del usuario actual + sus modulos (para GET /api/auth/me).
     * @return array|null
     */
    public function getUserProfile($user_id)
    {
        try {
            $cols = 'id, email, username, name, last_name, role' . (self::multiTenant() ? ', tenant_id' : '');
            $stmt = $this->conexion->prepare("SELECT {$cols} FROM users WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $user_id]);
            $u = $stmt->fetch();
            if (!$u) {
                return null;
            }
            return [
                'id' => (int) $u['id'],
                'email' => $u['email'],
                'username' => $u['username'],
                'name' => trim(($u['name'] ?? '') . ' ' . ($u['last_name'] ?? '')),
                'role' => $u['role'],
                'permissions' => $this->permissionsForUser($u['tenant_id'] ?? null, $u['role']),
            ];
        } catch (PDOException $e) {
            return null;
        }
    }

    /**
     * Register a new user
     * @param string $email User email
     * @param string $password User password
     * @param string $name Full name (will be split into name and last_name)
     * @param string $username Username
     * @return array ['success', user_data] or ['error', message]
     */
    public function registerUser($email, $password, $name, $username, $tenant_id = null, $role = 'user')
    {
        try {
            if (self::multiTenant() && $tenant_id === null) {
                return ['error', 'tenant_id es requerido en modo multi-tenant'];
            }
            // El rol es server-side; default 'user'. El llamador (admin) puede
            // pasar otro nombre de rol existente del tenant.
            $role = (is_string($role) && trim($role) !== '') ? trim($role) : 'user';

            // Check if email already exists (email is globally unique across tenants)
            $sql = "SELECT id FROM users WHERE email = :email LIMIT 1";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([':email' => $email]);
            if ($stmt->fetch()) {
                return ['error', 'Email already registered'];
            }

            // Check if username already exists (username is globally unique across
            // tenants, like email — so login by username needs no tenant_id).
            $sql = "SELECT id FROM users WHERE username = :username LIMIT 1";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([':username' => $username]);
            if ($stmt->fetch()) {
                return ['error', 'Username already taken'];
            }

            // Split full name into first name and last name
            $name_parts = explode(' ', trim($name), 2);
            $first_name = $name_parts[0];
            $last_name = isset($name_parts[1]) ? $name_parts[1] : '';

            // Hash password
            $password_hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);

            // Insert new user
            if (self::multiTenant()) {
                $sql = "INSERT INTO users (tenant_id, name, last_name, email, username, password, role) VALUES (:tenant_id, :name, :last_name, :email, :username, :password, :role)";
                $stmt = $this->conexion->prepare($sql);
                $stmt->execute([
                    ':tenant_id' => $tenant_id,
                    ':name' => $first_name,
                    ':last_name' => $last_name,
                    ':email' => $email,
                    ':username' => $username,
                    ':password' => $password_hash,
                    ':role' => $role
                ]);
            } else {
                $sql = "INSERT INTO users (name, last_name, email, username, password, role) VALUES (:name, :last_name, :email, :username, :password, :role)";
                $stmt = $this->conexion->prepare($sql);
                $stmt->execute([
                    ':name' => $first_name,
                    ':last_name' => $last_name,
                    ':email' => $email,
                    ':username' => $username,
                    ':password' => $password_hash,
                    ':role' => $role
                ]);
            }

            // Get the newly created user
            $user_id = $this->conexion->lastInsertId();
            $user_data = [
                'id' => (int)$user_id,
                'email' => $email,
                'username' => $username,
                'name' => trim($first_name . ' ' . $last_name),
                'role' => $role
            ];

            return ['success', $user_data];
        } catch (PDOException $e) {
            return ['error', 'Database error: ' . $e->getMessage()];
        }
    }
}
