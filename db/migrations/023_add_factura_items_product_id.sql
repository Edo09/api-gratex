-- ============================================================================
-- 023_add_factura_items_product_id.sql — La linea de factura sabe que producto es.
-- ============================================================================
-- Para DBs de tenant YA desplegados. Los tenants nuevos lo reciben via
-- db/tenant_schema.sql.
--
-- Hasta hoy una linea de factura era solo texto (`description`): aunque el
-- usuario la eligiera del catalogo, el vinculo con el producto se perdia al
-- guardar. Por eso no se podia descontar inventario al vender.
--
-- NULLABLE a proposito: una linea puede ser texto libre ("mano de obra",
-- "flete") sin producto del catalogo, y esas no mueven inventario. El ON DELETE
-- SET NULL evita que borrar un producto rompa facturas ya emitidas — la factura
-- conserva su `description`, que es lo que vale fiscalmente.
--
-- Es ADITIVO. Si la columna ya existe, el ALTER falla (ejecutar UNA sola vez).
-- ============================================================================

ALTER TABLE `factura_items`
  ADD COLUMN `product_id` INT(11) NULL DEFAULT NULL
    COMMENT 'FK al catalogo; NULL = linea libre (no mueve inventario)'
    AFTER `factura_id`,
  ADD KEY `idx_factura_items_product` (`product_id`),
  ADD CONSTRAINT `factura_items_product_fk`
    FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL;
