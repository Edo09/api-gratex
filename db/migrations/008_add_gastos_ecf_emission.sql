-- =============================================================================
-- Migration 008: Emision e-CF para el modulo de Gastos.
-- Los gastos que EMITE la empresa (E41 Compras, E43 Gastos Menores, E47 Pagos al
-- Exterior) se envian a la DGII como un e-CF (firmar + enviar), igual que las
-- facturas. Esta migracion agrega a `gastos` las columnas de tracking DGII y a
-- `gasto_items` los indicadores fiscales que el XML e-CF exige.
--
-- Los gastos RECIBIDOS (E31/B01 Credito Fiscal, E33/E34 notas del proveedor) NO
-- se emiten: ya los emitio el proveedor. Quedan con estado_dgii = 'REGISTRADO'.
--
-- Es ADITIVO. Optimizado para MySQL 8: ALTER independiente, DDL auto-commit.
-- Si una columna ya existe, solo esa linea falla; el resto sigue.
-- =============================================================================

ALTER TABLE `gastos`
  ADD COLUMN `estado_dgii` VARCHAR(20) NOT NULL DEFAULT 'REGISTRADO'
    COMMENT 'REGISTRADO (recibido) | PENDIENTE_EMISION | ENVIADO | ACEPTADO | ACEPTADO_CONDICIONAL | RECHAZADO | ERROR',
  ADD COLUMN `track_id` VARCHAR(60) NULL
    COMMENT 'TrackId que devuelve DGII al recibir el e-CF emitido',
  ADD COLUMN `codigo_seguridad` VARCHAR(10) NULL
    COMMENT 'Codigo de seguridad para representacion impresa y QR',
  ADD COLUMN `fecha_emision_dgii` DATETIME NULL,
  ADD COLUMN `xml_firmado` MEDIUMTEXT NULL
    COMMENT 'XML firmado enviado a DGII',
  ADD COLUMN `respuesta_dgii` TEXT NULL
    COMMENT 'Ultima respuesta de DGII (JSON serializado)',
  ADD COLUMN `secuencia_utilizada` TINYINT(1) NULL
    COMMENT 'DGII: false => el e-NCF puede reutilizarse en un nuevo envio',
  ADD INDEX `idx_estado_dgii` (`estado_dgii`),
  ADD INDEX `idx_track_id` (`track_id`);

ALTER TABLE `gasto_items`
  ADD COLUMN `indicador_facturacion` TINYINT NOT NULL DEFAULT 1
    COMMENT '1=ITBIS 18% | 2=ITBIS 16% | 3=ITBIS 0% | 4=Exento | 0=No facturable',
  ADD COLUMN `indicador_bien_servicio` TINYINT NOT NULL DEFAULT 2
    COMMENT '1=Bien | 2=Servicio';
