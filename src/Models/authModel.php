<?php
require_once(__DIR__ . '/../Utils/TokenGenerator.php');
require_once(__DIR__ . '/../Database.php');

class authModel
{
    private $conexion;

    public function __construct()
    {
        $this->conexion = Database::getInstance()->getConnection();
    }

    /**
     * Generate a new API token for a user
     * @param int $user_id User ID
     * @return array ['success', token] or ['error', message]
     */
    public function createToken($user_id)
    {
        try {
            $token = TokenGenerator::generateApiToken();
            $token_hash = TokenGenerator::hashToken($token);
            $created_at = date('Y-m-d H:i:s');

            $sql = "INSERT INTO api_tokens(user_id, token_hash, created_at) VALUES(:user_id, :token_hash, :created_at)";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([
                ':user_id' => $user_id,
                ':token_hash' => $token_hash,
                ':created_at' => $created_at
            ]);
            
            return ['success', $token];
        } catch (PDOException $e) {
            return ['error', 'Failed to create token'];
        }
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
            $sql = "SELECT user_id, is_active FROM api_tokens WHERE token_hash = :token_hash LIMIT 1";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([':token_hash' => $token_hash]);
            $row = $stmt->fetch();

            if ($row && $row['is_active'] == 1) {
                return ['valid' => true, 'user_id' => $row['user_id']];
            }
            return ['valid' => false, 'user_id' => null];
        } catch (PDOException $e) {
            return ['valid' => false, 'user_id' => null];
        }
    }

    /**
     * List all tokens for a user
     * @param int $user_id User ID
     * @return array Array of token records
     */
    public function getUserTokens($user_id)
    {
        try {
            $sql = "SELECT id, created_at, last_used, is_active FROM api_tokens WHERE user_id = :user_id ORDER BY created_at DESC";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([':user_id' => $user_id]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Revoke (deactivate) a token
     * @param int $token_id Token ID
     * @return array ['success'/'error', message]
     */
    public function revokeToken($token_id)
    {
        try {
            $sql = "UPDATE api_tokens SET is_active = 0 WHERE id = :token_id";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([':token_id' => $token_id]);
            return ['success', 'Token revoked'];
        } catch (PDOException $e) {
            return ['error', 'Failed to revoke token'];
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
     * Login user with email or username and password
     * @param string $email_or_username User email or username
     * @param string $password User password
     * @return array ['success', ['token' => token, 'user' => user_data]] or ['error', message]
     */
    public function loginUser($email_or_username, $password)
    {
        try {
            // Find user by email or username
            $sql = "SELECT id, email, username, name, last_name, password, role FROM users WHERE email = :email_or_username OR username = :email_or_username LIMIT 1";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([':email_or_username' => $email_or_username]);
            $user = $stmt->fetch();

            // Verify user exists and password is correct
            if (!$user || !password_verify($password, $user['password'])) {
                return ['error', 'Invalid email or password'];
            }

            // Generate new token for this login
            $token = TokenGenerator::generateApiToken();
            $token_hash = TokenGenerator::hashToken($token);
            $created_at = date('Y-m-d H:i:s');

            $sql = "INSERT INTO api_tokens(user_id, token_hash, created_at) VALUES(:user_id, :token_hash, :created_at)";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([
                ':user_id' => $user['id'],
                ':token_hash' => $token_hash,
                ':created_at' => $created_at
            ]);

            // Prepare user data response (without password)
            $user_data = [
                'id' => (int)$user['id'],
                'email' => $user['email'],
                'username' => $user['username'],
                'name' => $user['name'] . ' ' . $user['last_name'],
                'role' => $user['role']
            ];

            return ['success', ['token' => $token, 'user' => $user_data]];
        } catch (PDOException $e) {
            return ['error', 'Database error: ' . $e->getMessage()];
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
    public function registerUser($email, $password, $name, $username)
    {
        try {
            // Check if email already exists
            $sql = "SELECT id FROM users WHERE email = :email LIMIT 1";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([':email' => $email]);
            if ($stmt->fetch()) {
                return ['error', 'Email already registered'];
            }

            // Check if username already exists
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
            $sql = "INSERT INTO users (name, last_name, email, username, password, role) VALUES (:name, :last_name, :email, :username, :password, :role)";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([
                ':name' => $first_name,
                ':last_name' => $last_name,
                ':email' => $email,
                ':username' => $username,
                ':password' => $password_hash,
                ':role' => 'user'
            ]);

            // Get the newly created user
            $user_id = $this->conexion->lastInsertId();
            $user_data = [
                'id' => (int)$user_id,
                'email' => $email,
                'username' => $username,
                'name' => trim($first_name . ' ' . $last_name),
                'role' => 'user'
            ];

            return ['success', $user_data];
        } catch (PDOException $e) {
            return ['error', 'Database error: ' . $e->getMessage()];
        }
    }
}
