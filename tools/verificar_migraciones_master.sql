-- =============================================================================
-- verificar_migraciones_master.sql — SOLO LECTURA. No cambia nada.
--
-- Dice que migraciones de db/master_migrations/ ya estan aplicadas en la base
-- MASTER. No existe una tabla que lleve ese registro (las migraciones se corren
-- a mano), asi que cada fila busca en information_schema lo que su migracion
-- deja creado: una columna, una tabla o un indice.
--
-- Uso: en phpMyAdmin entra a la base master (en el server:
-- mtldtmte_master_gratex; en local: gratex_master) y ejecuta todo el archivo.
-- Cada fila dice APLICADA o FALTA. Corre SOLO las que dicen FALTA, en orden:
-- varias de las viejas (001 a 006) estan hechas para correr UNA sola vez y
-- fallan, o duplican filas, si se repiten. Las nuevas (007 en adelante) se
-- pueden repetir sin dano.
--
-- Solo mira information_schema: funciona aunque falte una tabla entera.
-- =============================================================================

SELECT m.migracion, m.archivo, m.que_revisa,
       CASE WHEN m.ok IS NULL THEN 'VER CONSULTA 2'
            WHEN m.ok THEN 'APLICADA'
            ELSE 'FALTA' END AS estado
FROM (
  SELECT '001' AS migracion, '001_add_origen_recepcion.sql' AS archivo,
         'columna ecf_recibidos.firma_subject' AS que_revisa,
         EXISTS (SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ecf_recibidos'
                   AND COLUMN_NAME = 'firma_subject') AS ok
  UNION ALL
  SELECT '002', '002_add_tenant_branding.sql',
         'columna tenants.pdf_accent_color',
         EXISTS (SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenants'
                   AND COLUMN_NAME = 'pdf_accent_color')
  UNION ALL
  SELECT '003', '003_add_roles_permissions.sql',
         'tablas roles y role_permissions',
         (SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('roles', 'role_permissions')) = 2
  UNION ALL
  SELECT '004+005', '004_add_inventory_permission.sql + 005_split_inventory_modules.sql',
         'son datos (permisos): ver la consulta 2 al final',
         NULL
  UNION ALL
  SELECT '006', '006_add_audit_logs.sql',
         'tabla audit_logs',
         EXISTS (SELECT 1 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_logs')
  UNION ALL
  SELECT '007', '007_username_global_unique.sql',
         'indice unico users.uq_users_username (y sin uq_users_tenant_username)',
         EXISTS (SELECT 1 FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
                   AND INDEX_NAME = 'uq_users_username')
         AND NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
                   AND INDEX_NAME = 'uq_users_tenant_username')
  UNION ALL
  SELECT '008', '008_add_tenant_grupo.sql',
         'columna tenants.grupo_id',
         EXISTS (SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenants'
                   AND COLUMN_NAME = 'grupo_id')
  UNION ALL
  SELECT '009', '009_backfill_audit_login_tenant.sql',
         'tabla de respaldo audit_logs_backfill_009',
         EXISTS (SELECT 1 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_logs_backfill_009')
  UNION ALL
  SELECT '010', '010_add_unidades_medida_permite_decimales.sql',
         'columna unidades_medida.permite_decimales',
         EXISTS (SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'unidades_medida'
                   AND COLUMN_NAME = 'permite_decimales')
  UNION ALL
  SELECT '011', '011_add_tenant_cotizacion_formato.sql',
         'columna tenants.cotizacion_formato',
         EXISTS (SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenants'
                   AND COLUMN_NAME = 'cotizacion_formato')
  UNION ALL
  SELECT '012', '012_pos.sql',
         'columnas tenants.pos_enabled y pos_equipos.bloqueos_seguidos + tablas pos_handoff_codes y pos_equipos',
         EXISTS (SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenants'
                   AND COLUMN_NAME = 'pos_enabled')
         AND EXISTS (SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pos_equipos'
                   AND COLUMN_NAME = 'bloqueos_seguidos')
         AND (SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME IN ('pos_handoff_codes', 'pos_equipos')) = 2
) m
ORDER BY m.migracion;

-- -----------------------------------------------------------------------------
-- Consulta 2 (004 + 005): correrla SOLO si la 003 salio APLICADA (si no existe
-- role_permissions esta consulta da error, y eso ya dice que falta la 003).
-- Esperado si 004 y 005 estan aplicadas: permiso_inventory_legacy = 0 y
-- roles_user_sin_categories = 0 y roles_user_sin_warehouses = 0.
-- -----------------------------------------------------------------------------
-- SELECT
--   (SELECT COUNT(*) FROM role_permissions WHERE permission = 'inventory') AS permiso_inventory_legacy,
--   (SELECT COUNT(*) FROM roles r JOIN tenants t ON t.id = r.tenant_id
--     WHERE t.tipo = 'app' AND r.name = 'user'
--       AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission = 'categories')) AS roles_user_sin_categories,
--   (SELECT COUNT(*) FROM roles r JOIN tenants t ON t.id = r.tenant_id
--     WHERE t.tipo = 'app' AND r.name = 'user'
--       AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission = 'warehouses')) AS roles_user_sin_warehouses;

-- -----------------------------------------------------------------------------
-- Consulta 3: que base de datos usa cada tenant, para correr luego
-- tools/verificar_migraciones_tenant.sql dentro de cada una (solo tipo 'app';
-- los de tipo 'integracion' no tienen base propia).
-- -----------------------------------------------------------------------------
SELECT id, nombre, rnc, tipo, db_name FROM tenants ORDER BY id;
