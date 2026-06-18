-- =============================================================================
-- Master Migration 004: permiso 'inventory' al rol 'user' (modulo Inventario).
--
-- Ejecutar contra la base MASTER (gratex_master), UNA vez. Idempotente.
--
-- El modulo nuevo `inventory` (Categorias + Almacenes) es operativo, asi que el
-- rol de sistema `user` de cada tenant tipo "app" lo recibe. `admin` ya tiene '*'.
-- (config/permissions.php ya lo incluye en defaults.user para tenants NUEVOS;
-- esta migracion cubre los EXISTENTES.)
-- =============================================================================

USE gratex_master;

INSERT INTO role_permissions (role_id, permission)
SELECT r.id, 'inventory'
FROM roles r
JOIN tenants t ON t.id = r.tenant_id
WHERE t.tipo = 'app'
  AND r.name = 'user'
  AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission = 'inventory'
  );
