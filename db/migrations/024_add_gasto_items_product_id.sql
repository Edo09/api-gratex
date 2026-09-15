-- ============================================================================
-- 024_add_gasto_items_product_id.sql — La linea de una compra sabe que producto es.
-- ============================================================================
-- Para DBs de tenant YA desplegados. Mismo patron que la 023 (factura_items).
--
-- Hasta hoy una linea de gasto/compra era solo texto: al comprar mercancia el
-- inventario no se enteraba. Con el vinculo, las compras (E31/E41/E47) suman
-- existencias y la nota de credito del proveedor (E34) las devuelve. Ver
-- inventoryModel::registrarCompra.
--
-- NULLABLE a proposito: una linea puede ser texto libre ("flete", "mano de
-- obra") sin producto del catalogo, y esas no mueven inventario. ON DELETE SET
-- NULL: borrar un producto no rompe compras ya registradas; la linea conserva
-- su `description`, que es lo que vale para el 606.
--
-- ORDEN DE DESPLIEGUE: correr esta migracion ANTES de subir el PHP. Si el PHP
-- llega primero, los gastos SIN productos se siguen registrando (el INSERT solo
-- nombra product_id cuando alguna linea lo trae), pero una compra CON productos
-- falla hasta que exista la columna.
--
-- Es ADITIVO. Si la columna ya existe, el ALTER falla (ejecutar UNA sola vez).
-- ============================================================================

ALTER TABLE `gasto_items`
  ADD COLUMN `product_id` INT(11) NULL DEFAULT NULL
    COMMENT 'FK al catalogo; NULL = linea libre (no mueve inventario)'
    AFTER `gasto_id`,
  ADD KEY `idx_gasto_items_product` (`product_id`),
  ADD CONSTRAINT `gasto_items_product_fk`
    FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL;
