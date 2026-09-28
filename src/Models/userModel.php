<?php
require_once(__DIR__ . '/../Database.php');
require_once(__DIR__ . '/../MasterDatabase.php');

/**
 * userModel — gestion de usuarios de un tenant (RBAC).
 *
 * En multi-tenant los usuarios viven en el MASTER (gratex_master) y se filtran
 * por tenant_id; en single-tenant viven en la DB del tenant (sin filtro). Mismo
 * patron de conexion que authModel.
 *
 * La CREACION se hace con authModel::registerUser (valida email global / username
 * por tenant + hashea). Este modelo cubre list/get/update/delete.
 */
class userModel
{
    private $conexion;

    public function __construct()
    {
        $this->conexion = self::multiTenant()
            ? MasterDatabase::getInstance()->getConnection()
            : Database::getInstance()->getConnection();
    }

    private static function multiTenant(): bool
    {
        return filter_var(
            getenv('MULTI_TENANT_ENABLED') ?: ($_ENV['MULTI_TENANT_ENABLED'] ?? false),
            FILTER_VALIDATE_BOOLEAN
        );
    }

    private const COLS = 'id, email, username, name, last_name, role, created_at';

    /** Lista los usuarios del tenant (sin password). */
    public function listUsers(?int $tenantId): array
    {
        if (self::multiTenant()) {
            $stmt = $this->conexion->prepare('SELECT ' . self::COLS . ' FROM users WHERE tenant_id = :tid ORDER BY id');
            $stmt->execute([':tid' => (int) $tenantId]);
        } else {
            $stmt = $this->conexion->query('SELECT ' . self::COLS . ' FROM users ORDER BY id');
        }
        return $stmt->fetchAll();
    }

    /** Un usuario del tenant (sin password) o null. */
    public function getUser(?int $tenantId, int $id): ?array
    {
        if (self::multiTenant()) {
            $stmt = $this->conexion->prepare('SELECT ' . self::COLS . ' FROM users WHERE id = :id AND tenant_id = :tid LIMIT 1');
            $stmt->execute([':id' => $id, ':tid' => (int) $tenantId]);
        } else {
            $stmt = $this->conexion->prepare('SELECT ' . self::COLS . ' FROM users WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $id]);
        }
        return $stmt->fetch() ?: null;
    }

    /**
     * Actualiza campos de un usuario del tenant. Solo campos enviados.
     * $fields: name, last_name, email, username, role, password (cualquier subconjunto).
     * @return array ['success', null] | ['error', mensaje]
     */
    public function updateUser(?int $tenantId, int $id, array $fields): array
    {
        $current = $this->getUser($tenantId, $id);
        if (!$current) {
            // Otro administrador lo borro mientras se editaba (o no es de esta empresa).
            return ['error', 'Ese usuario ya no existe. Recarga la lista.'];
        }

        // Unicidad: email y username globales (excluyendo al propio usuario).
        if (isset($fields['email']) && $fields['email'] !== $current['email']) {
            if (!filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) {
                return ['error', 'El correo no es válido. Revísalo (por ejemplo: nombre@empresa.com).'];
            }
            $chk = $this->conexion->prepare('SELECT id FROM users WHERE email = :e AND id <> :id LIMIT 1');
            $chk->execute([':e' => $fields['email'], ':id' => $id]);
            if ($chk->fetch()) {
                return ['error', 'Ya existe otro usuario con ese correo. Usa otro correo.'];
            }
        }
        if (isset($fields['username']) && $fields['username'] !== $current['username']) {
            $chk = $this->conexion->prepare('SELECT id FROM users WHERE username = :u AND id <> :id LIMIT 1');
            $chk->execute([':u' => $fields['username'], ':id' => $id]);
            if ($chk->fetch()) {
                return ['error', 'Ese nombre de usuario ya está en uso. Elige otro.'];
            }
        }

        $allowed = ['name', 'last_name', 'email', 'username', 'role'];
        $sets = [];
        $params = [':id' => $id];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $fields) && $fields[$col] !== null) {
                $sets[] = "{$col} = :{$col}";
                $params[":{$col}"] = $fields[$col];
            }
        }
        if (isset($fields['password']) && trim((string) $fields['password']) !== '') {
            $sets[] = 'password = :password';
            $params[':password'] = password_hash($fields['password'], PASSWORD_BCRYPT, ['cost' => 10]);
        }
        if (!$sets) {
            return ['success', null];
        }

        try {
            $sql = 'UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = :id';
            $this->conexion->prepare($sql)->execute($params);
            return ['success', null];
        } catch (PDOException $e) {
            error_log('[userModel] updateUser ' . $id . ': ' . $e->getMessage());
            return ['error', 'No se pudieron guardar los cambios del usuario. Inténtalo de nuevo.'];
        }
    }

    /** Borra un usuario del tenant. */
    public function deleteUser(?int $tenantId, int $id): array
    {
        if (!$this->getUser($tenantId, $id)) {
            return ['error', 'Ese usuario ya no existe. Recarga la lista.'];
        }
        try {
            $this->conexion->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $id]);
            return ['success', null];
        } catch (PDOException $e) {
            error_log('[userModel] deleteUser ' . $id . ': ' . $e->getMessage());
            return ['error', 'No se pudo eliminar el usuario. Inténtalo de nuevo.'];
        }
    }
}
