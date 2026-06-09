-- =============================================================================
-- Migration 007: Modulo de Gastos
-- Gestiona:
--   - Gastos EMITIDOS por la empresa (auto-emision, secuencia interna):
--       * NCF 11 / E41  Comprobante de Compras (proveedor informal)
--       * NCF 13 / E43  Comprobante para Gastos Menores (peajes, suministros)
--       * NCF 17 / E47  Comprobante para Pagos al Exterior
--   - Gastos RECIBIDOS de proveedores (el usuario digita el NCF que le dieron):
--       * NCF 01 / E31  Credito Fiscal
--       * NCF 03 / E33  Nota de Debito (proveedor cobra costo adicional)
--       * NCF 04 / E34  Nota de Credito (proveedor reduce una compra previa)
--
-- Es ADITIVO. Solo agrega tablas, no modifica nada existente.
-- =============================================================================

START TRANSACTION;

-- -----------------------------------------------------------------------------
-- 1) Cabecera del gasto
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `gastos` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `categoria` VARCHAR(30) NOT NULL
    COMMENT 'gastos_menores (E43) | facturas_proveedores (E41, E47, E31, B01, E33, E34)',
  `tipo_gasto` VARCHAR(3) NOT NULL
    COMMENT 'E41, E43, E47 (auto-emision) | B01, E31, E33, E34 (recibido)',
  `ncf` VARCHAR(19) NULL
    COMMENT 'Auto-emision: secuencia interna generada. Recibido: NCF del proveedor',
  `rnc_proveedor` VARCHAR(11) NOT NULL
    COMMENT 'RNC/Cedula del proveedor (informal en compras 11/41)',
  `nombre_proveedor` VARCHAR(150) NOT NULL,
  `fecha` DATE NOT NULL,
  `subtotal` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `itbis` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `total` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  `es_auto_emision` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1 = la empresa emite y genera la secuencia | 0 = recibido del proveedor',
  `ambiente` VARCHAR(20) NULL COMMENT 'testecf | certecf | ecf',
  `user_id` INT(11) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_proveedor_ncf` (`rnc_proveedor`, `ncf`),
  INDEX `idx_categoria` (`categoria`),
  INDEX `idx_tipo_gasto` (`tipo_gasto`),
  INDEX `idx_rnc_proveedor` (`rnc_proveedor`),
  INDEX `idx_ambiente` (`ambiente`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 2) Lineas del gasto
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `gasto_items` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `gasto_id` INT(11) NOT NULL,
  `description` TEXT NOT NULL,
  `amount` DECIMAL(18,2) NOT NULL,
  `quantity` INT(11) NOT NULL DEFAULT 1,
  `subtotal` DECIMAL(18,2) NOT NULL,
  `itbis_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  INDEX `idx_gasto_id` (`gasto_id`),
  CONSTRAINT `gasto_items_ibfk_1` FOREIGN KEY (`gasto_id`) REFERENCES `gastos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;
