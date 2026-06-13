-- ============================================================================
-- 016_add_gasto_items_unidad_medida.sql — Unidad de Medida por línea de gasto.
-- ============================================================================
-- Espejo de la columna en factura_items (migración del usuario). Los gastos de
-- auto-emisión (E41/E43/E47) ahora llevan el código DGII de unidad de medida en
-- cada línea (id del catálogo `unidades_medida` del DB master; 43 = Unidad) y lo
-- emiten en <UnidadMedida>. Reflejado en db/tenant_schema.sql (gasto_items).
--
-- ADITIVO. Ejecutar UNA sola vez (si la columna ya existe, el ALTER falla).
-- ============================================================================

ALTER TABLE `gasto_items`
  ADD COLUMN `unidad_medida` VARCHAR(10) NOT NULL DEFAULT '43'
    COMMENT 'Codigo DGII de unidad de medida (id del catalogo; 43 = Unidad)'
    AFTER `indicador_bien_servicio`;
