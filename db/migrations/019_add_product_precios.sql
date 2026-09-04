-- ============================================================================
-- 019_add_product_precios.sql — Listas de precio 2, 3 y 4 por producto.
-- ============================================================================
-- Para DBs de tenant YA desplegados. Los tenants nuevos lo reciben via
-- db/tenant_schema.sql (products — refleja exactamente estas columnas).
--
-- Los catalogos que llegan de sistemas anteriores traen varias listas de precio
-- (mayorista, distribuidor, especial...). Hasta ahora products solo guardaba
-- una, asi que al migrar se perdian.
--
-- `precio` NO se renombra: es la lista 1 y la usan facturas, cotizaciones, PDF
-- y el front. Renombrarla romperia todo eso sin ganar nada.
--
-- Las nuevas son NULL = "esta lista no aplica a este producto", distinto de 0.00
-- (precio cero). La emision sigue usando `precio` salvo que la linea mande otro.
--
-- Es ADITIVO. Si las columnas ya existen, el ALTER falla (ejecutar UNA sola vez).
-- ============================================================================

ALTER TABLE `products`
  ADD COLUMN `precio_2` DECIMAL(18,2) NULL DEFAULT NULL
    COMMENT 'Lista de precio 2 (NULL = no aplica)'
    AFTER `precio`,
  ADD COLUMN `precio_3` DECIMAL(18,2) NULL DEFAULT NULL
    COMMENT 'Lista de precio 3 (NULL = no aplica)'
    AFTER `precio_2`,
  ADD COLUMN `precio_4` DECIMAL(18,2) NULL DEFAULT NULL
    COMMENT 'Lista de precio 4 (NULL = no aplica)'
    AFTER `precio_3`;
