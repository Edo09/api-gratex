-- ============================================================================
-- 020_add_clients_descuento_credito.sql — Descuento y credito por cliente.
-- ============================================================================
-- Para DBs de tenant YA desplegados. Los tenants nuevos lo reciben via
-- db/tenant_schema.sql (clients — refleja exactamente estas columnas).
--
-- Los catalogos de clientes que llegan de sistemas anteriores traen dos reglas
-- comerciales por cliente que hasta ahora no teniamos donde guardar:
--
--   descuento         % fijo que se le concede a ese cliente (0 = ninguno).
--                     DECIMAL(5,2): admite 0.00 a 100.00 con dos decimales.
--   permitir_credito  si se le puede facturar a credito (0 = solo contado).
--
-- Ambas con default 0: un cliente nuevo no tiene descuento ni credito hasta que
-- alguien se lo asigne explicitamente, que es el lado seguro del default.
--
-- Es ADITIVO. Si las columnas ya existen, el ALTER falla (ejecutar UNA sola vez).
-- ============================================================================

ALTER TABLE `clients`
  ADD COLUMN `descuento` DECIMAL(5,2) NOT NULL DEFAULT 0.00
    COMMENT 'Descuento fijo del cliente en % (0 = sin descuento)'
    AFTER `provincia`,
  ADD COLUMN `permitir_credito` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1 = se le puede facturar a credito; 0 = solo contado'
    AFTER `descuento`;
