-- =============================================================================
-- Master Migration 008: grupo_id en tenants — una credencial para varios RNC.
--
-- Ejecutar contra la base MASTER, UNA sola vez.
-- Reflejado en db/master_schema.sql (instalaciones nuevas).
--
-- NOTA: el nombre de la master DB cambia por entorno (gratex_master en local,
-- mtldtmte_master_gratex en el server). Selecciona la base ANTES de correr esto
-- (en phpMyAdmin basta con entrar a la base; por CLI usa `USE <tu_master>;`).
--
-- PARA QUE: un cliente que administra varias empresas (varios RNC) en su propio
-- ERP no quiere manejar N pares de credenciales. Con grupo_id, la credencial de
-- CUALQUIER tenant del grupo puede emitir/aprobar/consultar en nombre de sus
-- hermanos: el RNC del payload elige la empresa.
--
-- Lo que NO cambia:
--   - Cada RNC sigue siendo un tenant con su propio certificado .p12, su propio
--     ambiente (certecf/ecf) y su propia certificacion ante la DGII.
--   - grupo_id NULL (el default, y lo que tienen todos los tenants actuales)
--     = aislamiento total. Sin grupo NO hay salto posible: TenantResolver
--     compara con `grupo_id = :g`, que nunca hace match contra NULL.
--   - El flujo entrante de DGII resuelve por RNC del XML y no mira credenciales,
--     asi que no se ve afectado.
--
-- SEGURIDAD: agrupar dos tenants significa que una credencial filtrada expone a
-- ambos. Agrupa SOLO empresas del mismo cliente/operador, nunca clientes
-- distintos. La agrupacion es deliberada: no hay forma de que ocurra sola.
--
-- Idempotente: se puede correr dos veces sin error.
-- =============================================================================

-- 1) Columna grupo_id (NULL = tenant aislado, comportamiento actual).
SET @has_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tenants'
    AND COLUMN_NAME = 'grupo_id'
);
SET @sql_col := IF(@has_col = 0,
  'ALTER TABLE tenants ADD COLUMN grupo_id INT NULL COMMENT ''Grupo de empresas de un mismo cliente: la credencial de cualquier tenant del grupo puede actuar por sus hermanos. NULL = aislado.'' AFTER tipo',
  'DO 0');
PREPARE s_col FROM @sql_col;
EXECUTE s_col;
DEALLOCATE PREPARE s_col;

-- 2) Indice para el lookup (rnc, grupo_id) de TenantResolver::switchToSibling.
SET @has_idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tenants'
    AND INDEX_NAME = 'idx_tenants_grupo'
);
SET @sql_idx := IF(@has_idx = 0,
  'ALTER TABLE tenants ADD INDEX `idx_tenants_grupo` (`grupo_id`)',
  'DO 0');
PREPARE s_idx FROM @sql_idx;
EXECUTE s_idx;
DEALLOCATE PREPARE s_idx;

-- =============================================================================
-- Como agrupar (ejemplo: las 5 empresas de un cliente).
--
-- La convencion es usar el id del tenant "cabeza" del grupo como grupo_id, asi
-- no hace falta una tabla de grupos ni una secuencia aparte:
--
--   -- 1. ver los tenants a agrupar
--   SELECT id, nombre, rnc, tipo, ambiente FROM tenants WHERE rnc IN (...);
--
--   -- 2. agrupar (sustituye <id_cabeza> y la lista de RNC)
--   UPDATE tenants SET grupo_id = <id_cabeza>
--   WHERE rnc IN ('131111111','131111112','131111113','131111114','131111115');
--
--   -- 3. verificar: deben salir las 5 con el mismo grupo_id
--   SELECT id, nombre, rnc, grupo_id, ambiente FROM tenants WHERE grupo_id = <id_cabeza>;
--
-- Para desagrupar una empresa:  UPDATE tenants SET grupo_id = NULL WHERE id = <id>;
-- =============================================================================
