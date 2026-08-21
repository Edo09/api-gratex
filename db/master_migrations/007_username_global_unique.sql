-- =============================================================================
-- Master Migration 007: username UNIQUE global (ya no por tenant).
--
-- Ejecutar contra la base MASTER (gratex_master), UNA sola vez.
-- Reflejado en db/master_schema.sql (instalaciones nuevas).
--
-- ANTES: UNIQUE (tenant_id, username) -> cada tenant podia repetir un username.
--        El login por username requeria tenant_id para desambiguar.
-- AHORA: UNIQUE (username) global -> el login por username ya NO necesita
--        tenant_id (igual que el email). Consecuencia: un username solo puede
--        existir en UN tenant en todo el sistema.
--
-- GUARDA: si ya hay usernames repetidos entre tenants, la migracion ABORTA con
-- un mensaje claro ANTES de tocar el indice. Resolver los duplicados primero
-- (renombrar uno de los usernames en conflicto) y re-ejecutar. Idempotente.
-- =============================================================================

USE gratex_master;

-- 1) Aborta si existen usernames duplicados (entre cualquier tenant).
--    Listar los conflictos antes de correr (para diagnostico):
--      SELECT username, COUNT(*) c, GROUP_CONCAT(tenant_id) tenants
--      FROM users GROUP BY username HAVING c > 1;
SET @dups := (
  SELECT COUNT(*) FROM (
    SELECT username FROM users GROUP BY username HAVING COUNT(*) > 1
  ) d
);
SET @msg := CONCAT('ABORT 007: existen ', @dups,
  ' username(s) duplicados entre tenants. Renombralos antes de aplicar UNIQUE global.');
-- SIGNAL solo si hay duplicados; si no, no hace nada.
DROP PROCEDURE IF EXISTS _mig007_guard;
DELIMITER //
CREATE PROCEDURE _mig007_guard()
BEGIN
  IF @dups > 0 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = @msg;
  END IF;
END //
DELIMITER ;
CALL _mig007_guard();
DROP PROCEDURE _mig007_guard;

-- 2) Drop el UNIQUE compuesto (tenant_id, username) si existe.
SET @has_old := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND INDEX_NAME = 'uq_users_tenant_username'
);
SET @sql_drop := IF(@has_old > 0,
  'ALTER TABLE users DROP INDEX `uq_users_tenant_username`',
  'DO 0');
PREPARE s_drop FROM @sql_drop;
EXECUTE s_drop;
DEALLOCATE PREPARE s_drop;

-- 3) Crear el UNIQUE global sobre username si no existe.
SET @has_new := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'users'
    AND INDEX_NAME = 'uq_users_username'
);
SET @sql_add := IF(@has_new = 0,
  'ALTER TABLE users ADD UNIQUE KEY `uq_users_username` (`username`)',
  'DO 0');
PREPARE s_add FROM @sql_add;
EXECUTE s_add;
DEALLOCATE PREPARE s_add;

-- 4) El indice no-unico idx_users_tenant (tenant_id) se conserva para los
--    listados por tenant; no se toca.
