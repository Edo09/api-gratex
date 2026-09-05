-- ============================================================================
-- 021_add_tipo_pago_descuento_factura.sql — Tipo de pago y descuento por linea.
-- ============================================================================
-- Para DBs de tenant YA desplegados. Los tenants nuevos lo reciben via
-- db/tenant_schema.sql (facturas / factura_items reflejan estas columnas).
--
-- Dos huecos que salieron al darle descuento y credito a los clientes:
--
--   facturas.tipo_pago
--     El e-CF manda TipoPago en el XML pero no se guardaba, asi que la factura
--     en la app no sabia si fue de contado o a credito. Las facturas simples
--     directamente no tenian el concepto. Mismos codigos que DGII:
--     1=Contado, 2=Credito, 3=Gratuito, 4=Permuta, 5=Otros.
--
--   factura_items.descuento_monto
--     El descuento por linea viajaba al XML (<DescuentoMonto>) pero no se
--     persistia: la Representacion Impresa y el detalle de la factura mostraban
--     el precio completo. Se guarda en MONTO, no en %, igual que lo pide DGII;
--     `subtotal` sigue siendo el neto (cantidad x precio - descuento).
--
-- Es ADITIVO. Si las columnas ya existen, el ALTER falla (ejecutar UNA sola vez).
-- ============================================================================

ALTER TABLE `facturas`
  ADD COLUMN `tipo_pago` TINYINT NOT NULL DEFAULT 1
    COMMENT '1=Contado 2=Credito 3=Gratuito 4=Permuta 5=Otros (codigos DGII)'
    AFTER `total`;

ALTER TABLE `factura_items`
  ADD COLUMN `descuento_monto` DECIMAL(18,2) NOT NULL DEFAULT 0.00
    COMMENT 'Descuento de la linea en monto; subtotal ya va neto de el'
    AFTER `subtotal`;
