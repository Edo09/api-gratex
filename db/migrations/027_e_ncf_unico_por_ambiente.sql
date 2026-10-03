-- ============================================================================
-- 027_e_ncf_unico_por_ambiente.sql — El e-NCF es unico POR AMBIENTE.
-- ============================================================================
-- Para DBs de tenant YA desplegados: correr en la base de CADA empresa, ANTES
-- de desplegar el codigo que busca el e-NCF por ambiente (ECFEmissionService::
-- eNcfYaUsado, facturaModel::saveFacturaConECF). Los tenants nuevos lo reciben
-- via db/tenant_schema.sql (mismo nombre de indice).
--
-- POR QUE:
--   facturas.e_ncf era UNIQUE sin importar ambiente_dgii. Una empresa que
--   certifica desde la app deja E31..E47 1..N en certecf, y la DGII le autoriza
--   produccion (ecf) desde el 1: esos numeros chocaban con los de prueba. El
--   emisor los saltaba (la secuencia "daba saltos") y un rango corto quedaba
--   agotado sin emitir nada. Caso real: Ferreventura, E44 1-5 (2026-10-03).
--   Para la DGII son secuencias distintas: certificacion y produccion no
--   comparten numeros.
--
-- QUE HACE (mira information_schema; no borra ni reescribe datos):
--   Cambia uk_e_ncf (e_ncf) por uk_e_ncf_amb (e_ncf, ambiente_dgii) en UN solo
--   ALTER: nunca queda la tabla sin clave unica. Si ya esta aplicada, no hace
--   nada. Ninguna fila puede chocar con la clave nueva: la vieja era mas estricta.
--
-- ORDEN: primero esta migracion, despues el codigo. Al reves, el codigo nuevo
-- dejaria emitir un numero de produccion que la clave vieja todavia rechaza al
-- guardar, DESPUES de que la DGII ya recibio el e-CF.
-- ============================================================================

SET @viejo := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'facturas' AND INDEX_NAME = 'uk_e_ncf'
);
SET @nuevo := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'facturas' AND INDEX_NAME = 'uk_e_ncf_amb'
);
SET @sql_027 := CASE
  WHEN @viejo > 0 AND @nuevo > 0 THEN 'ALTER TABLE facturas DROP INDEX uk_e_ncf'
  WHEN @viejo > 0 THEN 'ALTER TABLE facturas DROP INDEX uk_e_ncf, ADD UNIQUE KEY uk_e_ncf_amb (e_ncf, ambiente_dgii)'
  WHEN @nuevo > 0 THEN 'DO 0'
  ELSE 'ALTER TABLE facturas ADD UNIQUE KEY uk_e_ncf_amb (e_ncf, ambiente_dgii)'
END;
PREPARE s_027 FROM @sql_027;
EXECUTE s_027;
DEALLOCATE PREPARE s_027;

-- Resultado: la clave nueva presente y la vieja ausente.
SELECT DATABASE() AS base,
       IF(SUM(INDEX_NAME = 'uk_e_ncf_amb') > 0, 'SI', 'NO') AS tiene_uk_e_ncf_amb,
       IF(SUM(INDEX_NAME = 'uk_e_ncf') > 0, 'SI', 'NO') AS tiene_uk_e_ncf_viejo
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'facturas';
