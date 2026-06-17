<?php
require_once(__DIR__ . '/../Database.php');
require_once(__DIR__ . '/../MasterDatabase.php');

/**
 * RoleModel — CRUD de roles + permisos (RBAC per-tenant).
 *
 * En modo multi-tenant los roles viven en el MASTER (gratex_master); en
 * single-tenant viven en la DB del tenant con tenant_id=0. Mismo patron de
 * conexion que authModel.
 *
 * users.role guarda el NOMBRE del rol; se resuelve a permisos por (tenant_id,name).
 * El catalogo de permisos validos y el mapa ruta->permiso estan en
 * config/permissions.php (estatico, igual para todos los tenants).
 */
class RoleModel
{
    private $conexion;

    public function __construct()
    {
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

    /** En single-tenant el unico tenant logico es 0; en MT se usa el real. */
    private function tid(?int $tenantId): int
    {
        return self::multiTenant() ? (int) $tenantId : 0;
    }

    /** Carga el catalogo estatico de permisos (config/permissions.php). */
    public static function catalog(): array
    {
        static $cfg = null;
        if ($cfg === null) {
            $cfg = require __DIR__ . '/../../config/permissions.php';
        }
        return $cfg['catalog'] ?? [];
    }

    /** Roles de sistema por defecto (admin/user) -> permisos. */
    public static function defaultRoles(): array
    {
        static $cfg = null;
        if ($cfg === null) {
            $cfg = require __DIR__ . '/../../config/permissions.php';
        }
        return $cfg['defaults'] ?? [];
    }

    /**
     * Valida un permiso: comodin '*' (todos los modulos) o un modulo del catalogo.
     */
    public static function isValidPermission(string $perm): bool
    {
        return $perm === '*' || in_array($perm, self::catalog(), true);
    }

    /**
     * Permisos de un rol por nombre. Devuelve [] si no existe o ante error
     * (el gate trata [] como "denegar"; en modo shadow igual deja pasar).
     */
    public function getPermissionsForRole(?int $tenantId, string $name): array
    {
        try {
            $stmt = $this->conexion->prepare(
                'SELECT rp.permission
                   FROM roles r
                   JOIN role_permissions rp ON rp.role_id = r.id
                  WHERE r.tenant_id = :tid AND r.name = :name'
            );
            $stmt->execute([':tid' => $this->tid($tenantId), ':name' => $name]);
            return array_map(fn($r) => $r['permission'], $stmt->fetchAll());
        } catch (PDOException $e) {
            error_log('[RoleModel] getPermissionsForRole: ' . $e->getMessage());
            return [];
        }
    }

    /** Lista los roles del tenant con sus permisos. */
    public function listRoles(?int $tenantId): array
    {
        $stmt = $this->conexion->prepare(
            'SELECT id, name, description, is_system FROM roles WHERE tenant_id = :tid ORDER BY name'
        );
        $stmt->execute([':tid' => $this->tid($tenantId)]);
        $roles = $stmt->fetchAll();
        foreach ($roles as &$r) {
            $ps = $this->conexion->prepare('SELECT permission FROM role_permissions WHERE role_id = :id');
            $ps->execute([':id' => $r['id']]);
            $r['permissions'] = array_map(fn($x) => $x['permission'], $ps->fetchAll());
            $r['is_system'] = (int) $r['is_system'];
        }
        return $roles;
    }

    public function getRoleById(?int $tenantId, int $id): ?array
    {
        $stmt = $this->conexion->prepare(
            'SELECT id, name, description, is_system FROM roles WHERE id = :id AND tenant_id = :tid LIMIT 1'
        );
        $stmt->execute([':id' => $id, ':tid' => $this->tid($tenantId)]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $ps = $this->conexion->prepare('SELECT permission FROM role_permissions WHERE role_id = :id');
        $ps->execute([':id' => $id]);
        $row['permissions'] = array_map(fn($x) => $x['permission'], $ps->fetchAll());
        $row['is_system'] = (int) $row['is_system'];
        return $row;
    }

    /**
     * Crea un rol (no de sistema) con sus permisos.
     * @return array ['success', role_id] | ['error', mensaje]
     */
    public function createRole(?int $tenantId, string $name, ?string $description, array $permissions): array
    {
        $name = trim($name);
        if ($name === '' || !preg_match('/^[a-z0-9_-]{2,40}$/i', $name)) {
            return ['error', 'Nombre de rol invalido (2-40, alfanumerico/_/-).'];
        }
        foreach ($permissions as $p) {
            if (!self::isValidPermission((string) $p)) {
                return ['error', "Permiso no valido: {$p}"];
            }
        }
        try {
            $this->conexion->beginTransaction();
            $stmt = $this->conexion->prepare(
                'INSERT INTO roles (tenant_id, name, description, is_system) VALUES (:tid, :name, :desc, 0)'
            );
            $stmt->execute([':tid' => $this->tid($tenantId), ':name' => $name, ':desc' => $description]);
            $roleId = (int) $this->conexion->lastInsertId();
            $this->replacePermissions($roleId, $permissions);
            $this->conexion->commit();
            return ['success', $roleId];
        } catch (PDOException $e) {
            if ($this->conexion->inTransaction()) {
                $this->conexion->rollBack();
            }
            if ((int) $e->getCode() === 23000) {
                return ['error', 'Ya existe un rol con ese nombre en este tenant.'];
            }
            return ['error', 'Error al crear el rol.'];
        }
    }

    /**
     * Actualiza descripcion y/o permisos de un rol. Bloquea roles de sistema.
     * @return array ['success', null] | ['error', mensaje]
     */
    public function updateRole(?int $tenantId, int $id, ?string $description, ?array $permissions): array
    {
        $role = $this->getRoleById($tenantId, $id);
        if (!$role) {
            return ['error', 'Rol no encontrado.'];
        }
        if ((int) $role['is_system'] === 1) {
            return ['error', 'Los roles de sistema (admin/user) no se pueden modificar.'];
        }
        if ($permissions !== null) {
            foreach ($permissions as $p) {
                if (!self::isValidPermission((string) $p)) {
                    return ['error', "Permiso no valido: {$p}"];
                }
            }
        }
        try {
            $this->conexion->beginTransaction();
            if ($description !== null) {
                $this->conexion->prepare('UPDATE roles SET description = :d WHERE id = :id')
                    ->execute([':d' => $description, ':id' => $id]);
            }
            if ($permissions !== null) {
                $this->replacePermissions($id, $permissions);
            }
            $this->conexion->commit();
            return ['success', null];
        } catch (PDOException $e) {
            if ($this->conexion->inTransaction()) {
                $this->conexion->rollBack();
            }
            return ['error', 'Error al actualizar el rol.'];
        }
    }

    /** Borra un rol no-sistema. */
    public function deleteRole(?int $tenantId, int $id): array
    {
        $role = $this->getRoleById($tenantId, $id);
        if (!$role) {
            return ['error', 'Rol no encontrado.'];
        }
        if ((int) $role['is_system'] === 1) {
            return ['error', 'Los roles de sistema (admin/user) no se pueden borrar.'];
        }
        // Evitar dejar usuarios con un rol inexistente.
        $chk = $this->conexion->prepare('SELECT COUNT(*) c FROM users WHERE tenant_id = :tid AND role = :name');
        $chk->execute([':tid' => $this->tid($tenantId), ':name' => $role['name']]);
        if ((int) $chk->fetch()['c'] > 0) {
            return ['error', 'Hay usuarios con ese rol; reasignalos antes de borrarlo.'];
        }
        $this->conexion->prepare('DELETE FROM roles WHERE id = :id')->execute([':id' => $id]);
        return ['success', null];
    }

    /**
     * Asigna un rol (por nombre) a un usuario del mismo tenant.
     * Valida que el rol exista para ese tenant.
     */
    public function assignUserRole(?int $tenantId, int $userId, string $roleName): array
    {
        $tid = $this->tid($tenantId);
        // El usuario debe pertenecer al tenant.
        $u = $this->conexion->prepare(
            self::multiTenant()
                ? 'SELECT id FROM users WHERE id = :uid AND tenant_id = :tid LIMIT 1'
                : 'SELECT id FROM users WHERE id = :uid LIMIT 1'
        );
        $params = self::multiTenant() ? [':uid' => $userId, ':tid' => $tid] : [':uid' => $userId];
        $u->execute($params);
        if (!$u->fetch()) {
            return ['error', 'Usuario no encontrado en este tenant.'];
        }
        // El rol debe existir para el tenant.
        $r = $this->conexion->prepare('SELECT id FROM roles WHERE tenant_id = :tid AND name = :name LIMIT 1');
        $r->execute([':tid' => $tid, ':name' => $roleName]);
        if (!$r->fetch()) {
            return ['error', "El rol '{$roleName}' no existe en este tenant."];
        }
        $upd = $this->conexion->prepare('UPDATE users SET role = :role WHERE id = :uid');
        $upd->execute([':role' => $roleName, ':uid' => $userId]);
        return ['success', null];
    }

    /** Reemplaza el set de permisos de un rol (borra e inserta). */
    private function replacePermissions(int $roleId, array $permissions): void
    {
        $this->conexion->prepare('DELETE FROM role_permissions WHERE role_id = :id')
            ->execute([':id' => $roleId]);
        $ins = $this->conexion->prepare(
            'INSERT INTO role_permissions (role_id, permission) VALUES (:id, :perm)'
        );
        foreach (array_unique($permissions) as $perm) {
            $ins->execute([':id' => $roleId, ':perm' => $perm]);
        }
    }
}
