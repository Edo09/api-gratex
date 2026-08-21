-- ============================================================================
-- 018_drop_clients_rnc_company_unique.sql — Permitir RNC / company_name repetidos.
-- ============================================================================
-- Varios clientes pueden compartir el mismo RNC o la misma razón social /
-- company_name (p. ej. múltiples contactos de una misma empresa). En db/
-- tenant_schema.sql la tabla `clients` NO tiene UNIQUE sobre rnc ni company_name,
-- pero algunas DBs de tenant ya desplegadas tienen un índice UNIQUE sobre `rnc`
-- (creado fuera del repo). Ese índice hace fallar el INSERT al crear un segundo
-- cliente con el mismo RNC ("Failed to save client").
--
-- Esta migración elimina cualquier índice UNIQUE sobre clients.rnc o
-- clients.company_name si existe. Es idempotente: si no hay índice, no hace nada.
--
-- Para DBs de tenant YA desplegados. Tenants nuevos ya nacen sin estos índices.
-- ============================================================================

-- Drop UNIQUE index on clients.rnc (if any)
SET @idx_rnc := (
  SELECT INDEX_NAME FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'clients'
    AND COLUMN_NAME = 'rnc'
    AND NON_UNIQUE = 0
  LIMIT 1
);
SET @sql_rnc := IF(@idx_rnc IS NOT NULL,
  CONCAT('ALTER TABLE clients DROP INDEX `', @idx_rnc, '`'),
  'DO 0');
PREPARE s_rnc FROM @sql_rnc;
EXECUTE s_rnc;
DEALLOCATE PREPARE s_rnc;

-- Drop UNIQUE index on clients.company_name (if any)
SET @idx_company := (
  SELECT INDEX_NAME FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'clients'
    AND COLUMN_NAME = 'company_name'
    AND NON_UNIQUE = 0
  LIMIT 1
);
SET @sql_company := IF(@idx_company IS NOT NULL,
  CONCAT('ALTER TABLE clients DROP INDEX `', @idx_company, '`'),
  'DO 0');
PREPARE s_company FROM @sql_company;
EXECUTE s_company;
DEALLOCATE PREPARE s_company;
