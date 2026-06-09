-- =============================================================================
-- Migration 002: Modulo de Recepcion de e-CF (rol receptor electronico)
-- Crea tablas para:
--   - e-CF recibidos de otros emisores (URL Recepcion)
--   - Aprobaciones / Rechazos comerciales recibidos (URL Aprobacion)
--   - Semillas de autenticacion emitidas (URL Autenticacion)
-- Es ADITIVO. Solo agrega tablas, no modifica nada existente.
-- =============================================================================

START TRANSACTION;

-- -----------------------------------------------------------------------------
-- 1) e-CF recibidos (cuando otros emisores nos facturan a nosotros)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ecf_recibidos` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `track_id` VARCHAR(60) NOT NULL,
  `tipo_ecf` VARCHAR(2) NULL,
  `e_ncf` VARCHAR(13) NULL,
  `rnc_emisor` VARCHAR(11) NOT NULL,
  `razon_social_emisor` VARCHAR(150) NULL,
  `rnc_comprador` VARCHAR(11) NULL
    COMMENT 'Debe coincidir con el RNC en emisor_config',
  `monto_total` DECIMAL(18,2) NULL,
  `fecha_emision` DATE NULL,
  `fecha_recepcion` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `estado` VARCHAR(30) NOT NULL DEFAULT 'RECIBIDO'
    COMMENT 'RECIBIDO | EN_PROCESO | ACEPTADO | RECHAZADO | ERROR_FIRMA | ERROR_XSD',
  `codigo_resultado` INT(11) NULL
    COMMENT 'Codigo numerico devuelto al emisor (1=Aceptado, 2=Rechazado)',
  `mensaje_resultado` VARCHAR(500) NULL,
  `xml_firmado` MEDIUMTEXT NULL,
  `validacion_firma` VARCHAR(20) NULL
    COMMENT 'OK | INVALIDA | NO_VERIFICADA',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_track_id` (`track_id`),
  UNIQUE KEY `uk_e_ncf_emisor` (`rnc_emisor`, `e_ncf`),
  INDEX `idx_estado` (`estado`),
  INDEX `idx_fecha_recepcion` (`fecha_recepcion`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 2) Aprobaciones comerciales (respuestas de compradores a e-CF que emitimos)
--    Cuando un comprador acepta o rechaza comercialmente una factura nuestra,
--    nos envia un XML de Aprobacion Comercial (ACECF).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `aprobaciones_comerciales` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `factura_id` INT(11) NULL
    COMMENT 'FK a facturas si encontramos coincidencia por e-NCF',
  `e_ncf` VARCHAR(13) NOT NULL
    COMMENT 'e-NCF que el comprador esta aceptando o rechazando',
  `rnc_emisor` VARCHAR(11) NOT NULL
    COMMENT 'Nuestro RNC; debe coincidir con emisor_config',
  `rnc_comprador` VARCHAR(11) NOT NULL
    COMMENT 'RNC del comprador que envia la aprobacion',
  `estado_comercial` VARCHAR(30) NOT NULL
    COMMENT 'ACEPTADO | ACEPTADO_CONDICIONAL | RECHAZADO',
  `detalle_motivo` VARCHAR(500) NULL,
  `xml_firmado` MEDIUMTEXT NULL,
  `validacion_firma` VARCHAR(20) NULL,
  `fecha_recepcion` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_e_ncf` (`e_ncf`),
  INDEX `idx_factura_id` (`factura_id`),
  INDEX `idx_rnc_comprador` (`rnc_comprador`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 3) Semillas emitidas por nuestra URL Autenticacion
--    Cuando un consumidor (DGII u otro emisor) llama GET /autenticacion/semilla
--    le devolvemos un XML semilla. Cuando luego envia POST /validarsemilla
--    con el XML firmado, validamos contra esta tabla y emitimos token.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `auth_seeds` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `seed_value` VARCHAR(64) NOT NULL
    COMMENT 'Valor unico (random) que aparece dentro del XML semilla',
  `xml_emitido` TEXT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expira_at` DATETIME NOT NULL
    COMMENT 'Por defecto 5 minutos despues de creada',
  `consumida_at` DATETIME NULL
    COMMENT 'NULL = no consumida; fecha = ya se emitio token con esta semilla',
  `rnc_consumidor` VARCHAR(11) NULL
    COMMENT 'RNC extraido del certificado al validar',
  `token_emitido` VARCHAR(2048) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_seed_value` (`seed_value`),
  INDEX `idx_expira_at` (`expira_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4) Tokens emitidos para consumidores autenticados
--    Cuando alguien consume nuestra URL Recepcion / Aprobacion deberan presentar
--    un Bearer token obtenido contra nuestra URL Autenticacion.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `auth_tokens_emitidos` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `token` VARCHAR(2048) NOT NULL,
  `rnc_consumidor` VARCHAR(11) NOT NULL,
  `expedido_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expira_at` DATETIME NOT NULL,
  `revocado_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_token_prefix` (`token`(64)),
  INDEX `idx_rnc_consumidor` (`rnc_consumidor`),
  INDEX `idx_expira_at` (`expira_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;
