-- =============================================================================
-- Migration 003: Tracking de RFCE (Resumen de Factura de Consumo Electronica)
-- Para E32 con monto < 250,000 DOP, el contribuyente debe enviar primero un
-- RFCE al servicio de recepcion de resumenes (https://fc.dgii.gov.do/...).
-- La factura integra se carga manualmente al portal DGII despues.
-- Es ADITIVO. Solo agrega columnas, no modifica nada existente.
--
-- Optimizado para MySQL 8 (mysqlnd 8.3.x):
--   - Cada ALTER es independiente (si una falla, las otras siguen).
--   - Sin START TRANSACTION (el DDL hace auto-commit).
--   - Sin AFTER (el orden fisico de columnas es cosmetico).
-- Si alguna columna ya existe, solo esa linea fallara, no las demas.
-- =============================================================================

ALTER TABLE `facturas`
  ADD COLUMN `rfce_xml` MEDIUMTEXT NULL;

ALTER TABLE `facturas`
  ADD COLUMN `rfce_track_id` VARCHAR(60) NULL;

ALTER TABLE `facturas`
  ADD COLUMN `rfce_estado` VARCHAR(30) NULL;

ALTER TABLE `facturas`
  ADD COLUMN `rfce_respuesta` TEXT NULL;

ALTER TABLE `facturas`
  ADD INDEX `idx_rfce_track_id` (`rfce_track_id`);
