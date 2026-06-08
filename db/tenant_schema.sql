-- ============================================================================
-- tenant_schema.sql — Esquema base de negocio para un DB de tenant nuevo.
-- ============================================================================
-- Tablas de negocio que vivian en db/database.sql, SIN users / api_tokens /
-- landing_* (esas viven en gratex_master). NO incluye CREATE DATABASE / USE:
-- el onboarding (tools/create_tenant.php) crea el DB y selecciona su conexion.
--
-- Orden de aplicacion en un tenant nuevo:
--   1) tenant_schema.sql  (este archivo)
--   2) db/migrations/001..010  (agregan e-CF, gastos, etc.)
--
-- Ver: docs/multi-emisor-master-db-prd.md
-- ============================================================================

CREATE TABLE IF NOT EXISTS clients (
  id            INT(11)      NOT NULL AUTO_INCREMENT,
  email         VARCHAR(100) NOT NULL,
  client_name   VARCHAR(100) NOT NULL,
  company_name  VARCHAR(100) NOT NULL,
  phone_number  VARCHAR(20)  NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cotizaciones (
  id           INT(11)        NOT NULL AUTO_INCREMENT,
  code         VARCHAR(50)    NOT NULL,
  date         DATETIME       DEFAULT CURRENT_TIMESTAMP,
  client_id    INT(11)        DEFAULT NULL,
  client_name  VARCHAR(100)   NOT NULL,
  total        DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cotizacion_items (
  id             INT(11)        NOT NULL AUTO_INCREMENT,
  cotizacion_id  INT(11)        NOT NULL,
  description    TEXT           NOT NULL,
  amount         DECIMAL(10,2)  NOT NULL,
  quantity       INT(11)        NOT NULL DEFAULT 1,
  subtotal       DECIMAL(10,2)  NOT NULL,
  PRIMARY KEY (id),
  KEY cotizacion_id (cotizacion_id),
  CONSTRAINT cotizacion_items_ibfk_1 FOREIGN KEY (cotizacion_id) REFERENCES cotizaciones (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS facturas (
  id           INT(11)        NOT NULL AUTO_INCREMENT,
  no_factura   VARCHAR(50)    NOT NULL,
  date         DATETIME       DEFAULT CURRENT_TIMESTAMP,
  client_id    INT(11)        DEFAULT NULL,
  client_name  VARCHAR(100)   NOT NULL,
  total        DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
  NCF          VARCHAR(50)    NOT NULL,
  user_id      INT(11)        DEFAULT NULL
                 COMMENT 'Referencia a gratex_master.users.id (sin FK cross-DB)',
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS factura_items (
  id          INT(11)        NOT NULL AUTO_INCREMENT,
  factura_id  INT(11)        NOT NULL,
  description TEXT           NOT NULL,
  amount      DECIMAL(10,2)  NOT NULL,
  quantity    INT(11)        NOT NULL DEFAULT 1,
  subtotal    DECIMAL(10,2)  NOT NULL,
  PRIMARY KEY (id),
  KEY factura_id (factura_id),
  CONSTRAINT factura_items_ibfk_1 FOREIGN KEY (factura_id) REFERENCES facturas (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ncf_sequences (
  id            INT(11)      NOT NULL AUTO_INCREMENT,
  type          VARCHAR(10)  NOT NULL,
  prefix        VARCHAR(10)  NOT NULL,
  current_value INT(11)      NOT NULL DEFAULT 0,
  description   VARCHAR(100) DEFAULT NULL,
  created_at    DATETIME     DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tipos NCF base (valores en 0; las secuencias e-CF por ambiente las maneja
-- tools/migration_ncf_ambiente.sql segun corresponda).
INSERT INTO ncf_sequences (type, prefix, current_value, description) VALUES
  ('B01', 'B01', 0, 'Facturas de Credito Fiscal'),
  ('B02', 'B02', 0, 'Facturas de Consumidor Final'),
  ('B14', 'B14', 0, 'Regimenes Especiales'),
  ('B15', 'B15', 0, 'Gubernamental')
ON DUPLICATE KEY UPDATE type = type;
