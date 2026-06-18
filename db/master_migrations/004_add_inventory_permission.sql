-- =============================================================================
-- Master Migration 004: permisos de Inventario al rol 'user'.
--
-- Ejecutar contra la base MASTER (gratex_master), UNA vez. Idempotente.
--
-- Inventario son DOS modulos separados: `categories` y `warehouses` (un rol
-- puede tener uno sin el otro). El rol de sistema `user` de cada tenant tipo
-- "app" recibe ambos (operativos). `admin` ya tiene '*'. config/permissions.php
-- ya los incluye en defaults.user para tenants NUEVOS; esto cubre los EXISTENTES.
-- =============================================================================

USE gratex_master;

INSERT INTO role_permissions (role_id, permission)
SELECT r.id, p.perm
FROM roles r
JOIN tenants t ON t.id = r.tenant_id
JOIN (SELECT 'categories' AS perm UNION ALL SELECT 'warehouses') p
WHERE t.tipo = 'app'
  AND r.name = 'user'
  AND NOT EXISTS (
    SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission = p.perm
  );
