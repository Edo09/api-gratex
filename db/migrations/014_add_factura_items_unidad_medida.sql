-- ============================================================================
-- 014_add_factura_items_unidad_medida.sql — Unidad de Medida por linea.
-- ============================================================================
-- Para DBs de tenant YA desplegados. Los tenants nuevos lo reciben vía
-- db/tenant_schema.sql (factura_items — refleja exactamente esta columna).
--
-- La norma DGII de Representacion Impresa exige la columna "Unidad de Medida"
-- en la tabla de items. Hasta ahora solo products.unidad_medida (migracion 012)
-- guardaba el codigo; las lineas de factura no lo persistian y el PDF no lo
-- mostraba. Misma convencion que products: codigo DGII (43 = unidad).
-- ECFXmlBuilder ya emite <UnidadMedida> cuando la linea lo trae.
--
-- Es ADITIVO. Si la columna ya existe, el ALTER falla (ejecutar UNA sola vez).
-- ============================================================================

ALTER TABLE `factura_items`
  ADD COLUMN `unidad_medida` VARCHAR(10) NOT NULL DEFAULT '43'
    COMMENT 'Codigo de unidad de medida DGII (43 = unidad)'
    AFTER `indicador_bien_servicio`;
