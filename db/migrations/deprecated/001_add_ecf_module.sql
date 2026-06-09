-- =============================================================================
-- Migration 001: Modulo de Facturacion Electronica (e-CF)
-- Para ejecutar en phpMyAdmin sobre la base de datos existente.
-- Es ADITIVO: solo agrega columnas/tablas/datos, no borra nada.
-- =============================================================================

START TRANSACTION;

-- -----------------------------------------------------------------------------
-- 1) Datos fiscales del cliente (necesarios para e-CF)
--    RNC + razon social + direccion son requeridos para E31 (Credito Fiscal).
--    Para E32 (Consumo) RNC es opcional bajo cierto monto.
-- -----------------------------------------------------------------------------
ALTER TABLE `clients`
  ADD COLUMN `rnc` VARCHAR(11) NULL AFTER `company_name`,
  ADD COLUMN `razon_social` VARCHAR(150) NULL AFTER `rnc`,
  ADD COLUMN `direccion` VARCHAR(100) NULL AFTER `razon_social`,
  ADD COLUMN `municipio` VARCHAR(50) NULL AFTER `direccion`,
  ADD COLUMN `provincia` VARCHAR(50) NULL AFTER `municipio`;

-- -----------------------------------------------------------------------------
-- 2) Tracking de e-CF en facturas
-- -----------------------------------------------------------------------------
ALTER TABLE `facturas`
  MODIFY COLUMN `NCF` VARCHAR(50) NULL,
  ADD COLUMN `tipo_ecf` VARCHAR(2) NULL AFTER `NCF`
    COMMENT '31, 32, 33, 34, 41, 43, 44, 45, 46, 47',
  ADD COLUMN `e_ncf` VARCHAR(13) NULL AFTER `tipo_ecf`,
  ADD COLUMN `track_id` VARCHAR(60) NULL AFTER `e_ncf`
    COMMENT 'TrackId que devuelve DGII al recibir el e-CF',
  ADD COLUMN `estado_dgii` VARCHAR(20) NOT NULL DEFAULT 'PENDIENTE' AFTER `track_id`
    COMMENT 'PENDIENTE | ENVIADO | ACEPTADO | ACEPTADO_CONDICIONAL | RECHAZADO | ERROR',
  ADD COLUMN `codigo_seguridad` VARCHAR(10) NULL AFTER `estado_dgii`
    COMMENT 'Codigo de seguridad para representacion impresa y QR',
  ADD COLUMN `fecha_emision_dgii` DATETIME NULL AFTER `codigo_seguridad`,
  ADD COLUMN `ambiente_dgii` VARCHAR(20) NULL AFTER `fecha_emision_dgii`
    COMMENT 'testecf | certecf | ecf',
  ADD COLUMN `xml_firmado` MEDIUMTEXT NULL AFTER `ambiente_dgii`
    COMMENT 'XML firmado enviado a DGII',
  ADD COLUMN `respuesta_dgii` TEXT NULL AFTER `xml_firmado`
    COMMENT 'Ultima respuesta de DGII (JSON serializado)',
  ADD UNIQUE KEY `uk_e_ncf` (`e_ncf`),
  ADD INDEX `idx_track_id` (`track_id`),
  ADD INDEX `idx_estado_dgii` (`estado_dgii`);

-- -----------------------------------------------------------------------------
-- 3) Datos fiscales por linea (DGII requiere clasificacion de cada item)
-- -----------------------------------------------------------------------------
ALTER TABLE `factura_items`
  ADD COLUMN `indicador_facturacion` TINYINT NOT NULL DEFAULT 1 AFTER `subtotal`
    COMMENT '0=No facturable | 1=ITBIS 18% | 2=ITBIS 16% | 3=ITBIS 0% | 4=Exento',
  ADD COLUMN `indicador_bien_servicio` TINYINT NOT NULL DEFAULT 1 AFTER `indicador_facturacion`
    COMMENT '1=Bien | 2=Servicio',
  ADD COLUMN `itbis_amount` DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER `indicador_bien_servicio`;

-- -----------------------------------------------------------------------------
-- 4) Secuencias e-NCF (DGII tipos electronicos)
--    current_value=0 significa que la proxima sera 1 (E310000000001)
-- -----------------------------------------------------------------------------
INSERT IGNORE INTO `ncf_sequences` (`type`, `prefix`, `current_value`, `description`) VALUES
  ('E31', 'E31', 0, 'Credito Fiscal Electronico'),
  ('E32', 'E32', 0, 'Consumo Electronico'),
  ('E33', 'E33', 0, 'Nota de Debito Electronica'),
  ('E34', 'E34', 0, 'Nota de Credito Electronica'),
  ('E41', 'E41', 0, 'Compras Electronico'),
  ('E43', 'E43', 0, 'Gastos Menores Electronico'),
  ('E44', 'E44', 0, 'Regimenes Especiales Electronico'),
  ('E45', 'E45', 0, 'Gubernamental Electronico'),
  ('E46', 'E46', 0, 'Comprobante de Exportaciones'),
  ('E47', 'E47', 0, 'Comprobante para Pagos al Exterior');

-- -----------------------------------------------------------------------------
-- 5) Configuracion del emisor (datos fiscales de la empresa)
--    Tabla con un solo registro (id=1). Modificar valores con UPDATE.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `emisor_config` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `rnc` VARCHAR(11) NOT NULL,
  `razon_social` VARCHAR(150) NOT NULL,
  `nombre_comercial` VARCHAR(150) NULL,
  `sucursal` VARCHAR(20) NULL,
  `direccion` VARCHAR(100) NOT NULL,
  `municipio` VARCHAR(50) NULL,
  `provincia` VARCHAR(50) NULL,
  `telefono` VARCHAR(12) NULL COMMENT 'Formato: 999-999-9999',
  `correo` VARCHAR(80) NULL,
  `website` VARCHAR(50) NULL,
  `actividad_economica` VARCHAR(100) NULL,
  `fecha_vencimiento_secuencia` DATE NOT NULL DEFAULT '2030-12-31'
    COMMENT 'Fecha de vencimiento de la secuencia autorizada por DGII',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `emisor_config` (
  `id`, `rnc`, `razon_social`, `nombre_comercial`,
  `direccion`, `municipio`, `provincia`,
  `telefono`, `correo`, `website`
) VALUES (
  1,
  '00109122788',
  'GRATEX SRL',
  'Gratex',
  'Direccion del emisor',
  'Santo Domingo',
  'Distrito Nacional',
  '809-000-0000',
  'info@gratex.net',
  'https://gratex.net'
) ON DUPLICATE KEY UPDATE `id` = `id`;

COMMIT;

-- =============================================================================
-- Recordatorio: ajustar emisor_config con los datos reales:
--   UPDATE emisor_config SET
--     direccion = 'Tu direccion real',
--     telefono  = '809-XXX-XXXX',
--     fecha_vencimiento_secuencia = '2030-12-31'
--   WHERE id = 1;
-- =============================================================================
