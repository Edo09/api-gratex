-- =============================================================================
-- Master Migration 005: separar Inventario en dos modulos (categories + warehouses).
--
-- Ejecutar contra la base MASTER (gratex_master), UNA vez. Idempotente.
--
-- CORRECTIVO: la migracion 004 original otorgaba un unico modulo 'inventory'.
-- Inventario se separo en dos modulos ('categories' y 'warehouses'). Esta
-- migracion:
--   1) elimina el permiso legacy 'inventory' (ya no lo usa ninguna ruta);
--   2) otorga 'categories' + 'warehouses' a los roles 'user' de tenants app.
--
-- Seguro en entornos donde 004 ya quedo correcto (el DELETE no borra nada y el
-- INSERT no duplica) y en los que corrieron la 004 vieja (limpia + completa).
-- =============================================================================

USE gratex_master;

-- 1) Quitar el permiso legacy 'inventory' (reemplazado por categories/warehouses).
DELETE FROM role_permissions WHERE permission = 'inventory';

-- 2) Otorgar categories + warehouses a los roles user de tenants app (idempotente).
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
