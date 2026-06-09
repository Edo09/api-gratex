-- =============================================================================
-- Migration 005: Persistir `secuenciaUtilizada` de la consulta de estado DGII.
-- DGII devuelve este flag al consultar el resultado de un e-CF:
--   secuenciaUtilizada = false -> la secuencia (e-NCF) puede REUTILIZARSE en un
--     nuevo envio (rechazos por cert/firma invalida o estructura XML invalida).
--   secuenciaUtilizada = true  -> la secuencia quedo consumida; usar una nueva.
-- Guardarlo como columna evita escarbar el JSON de `respuesta_dgii` y permite
-- al front decidir reuso sin re-consultar DGII.
--
-- Es ADITIVO. Solo agrega una columna. NULL = aun no consultado / no aplica.
-- Optimizado para MySQL 8: ALTER independiente, sin transaccion (DDL auto-commit),
-- sin AFTER (orden fisico cosmetico). Si la columna ya existe, solo esta falla.
-- =============================================================================

ALTER TABLE `facturas`
  ADD COLUMN `secuencia_utilizada` TINYINT(1) NULL;
