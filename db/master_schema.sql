-- ============================================================================
-- gratex_master — Master DB para arquitectura multi-emisor (DB-per-tenant)
-- ============================================================================
-- Enruta cada peticion al DB del tenant correcto y centraliza auth (users +
-- api_tokens) para resolver el tenant sin problema huevo-gallina en el login.
--
-- Ver: docs/multi-emisor-master-db-prd.md
--
-- Ejecutar UNA vez para crear la master DB. La migracion de datos de Gratex
-- (tenant #1) esta al final, comentada (ajustar nombres de DB segun entorno).
-- ============================================================================

CREATE DATABASE IF NOT EXISTS gratex_master
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE gratex_master;

-- ----------------------------------------------------------------------------
-- tenants — registro de clientes + credenciales cifradas + api_key integracion
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tenants (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  nombre              VARCHAR(100)   NOT NULL,
  rnc                 VARCHAR(11)    NOT NULL UNIQUE,
  api_key             VARCHAR(64)    NOT NULL UNIQUE
                        COMMENT 'API key de integracion (identificador publico, modo JSON->XML)',
  api_secret_hash     VARCHAR(64)    NOT NULL
                        COMMENT 'sha256 hex del api_secret (el secret en claro nunca se guarda)',
  tipo                VARCHAR(12)    NOT NULL DEFAULT 'app'
                        COMMENT 'app = DB-per-tenant propia | integracion = sin DB, solo backup de e-CF',
  -- Campos db_* solo aplican a tenants tipo "app". En "integracion" son NULL.
  db_host             VARCHAR(100)   NULL DEFAULT 'localhost',
  db_port             VARCHAR(10)    NULL DEFAULT '3306',
  db_name             VARCHAR(64)    NULL UNIQUE,
  db_user             VARCHAR(64)    NULL,
  db_pass_encrypted   VARBINARY(512) NULL
                        COMMENT 'AES-256-GCM: iv(12) || tag(16) || ciphertext (solo tipo app)',
  cert_path           VARCHAR(255)   NULL
                        COMMENT 'Ruta relativa al project root del .p12 (requerido en integracion para firmar)',
  cert_pass_encrypted VARBINARY(512) NULL
                        COMMENT 'AES-256-GCM igual que db_pass_encrypted',
  webhook_url         VARCHAR(255)   NULL
                        COMMENT 'Integracion: URL del cliente para push de documentos entrantes',
  webhook_secret_encrypted VARBINARY(512) NULL
                        COMMENT 'AES-256-GCM; secret para firmar (HMAC) el payload del webhook',
  logo_path           VARCHAR(255)   NULL
                        COMMENT 'Ruta relativa al project root del logo (Representacion Impresa), ej. logos/5.png',
  pdf_template        VARCHAR(40)    NOT NULL DEFAULT 'clasico'
                        COMMENT 'Plantilla Representacion Impresa: clasico | moderno | compacto | custom:<nombre>',
  pdf_accent_color    CHAR(7)        NULL
                        COMMENT 'Color de acento hex #RRGGBB (NULL = colores por defecto de la plantilla)',
  ambiente            VARCHAR(20)    NOT NULL DEFAULT 'ecf',
  activo              TINYINT(1)     NOT NULL DEFAULT 1,
  created_at          DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- users — auth centralizada. email UNIQUE global (login resuelve tenant por
-- email sin pedir codigo de empresa). username UNIQUE por tenant.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id  INT          NOT NULL,
  name       VARCHAR(70)  NOT NULL,
  last_name  VARCHAR(70)  NOT NULL,
  email      VARCHAR(255) NOT NULL,
  username   VARCHAR(50)  NOT NULL,
  password   VARCHAR(255) NOT NULL,
  role       VARCHAR(20)  DEFAULT 'user',
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_tenant_username (tenant_id, username),
  KEY idx_users_tenant (tenant_id),
  CONSTRAINT fk_users_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- api_tokens — tokens de sesion (modo App). tenant_id permite token -> tenant
-- sin huevo-gallina. Conserva FK a users (ON DELETE CASCADE).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS api_tokens (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT         NOT NULL,
  tenant_id  INT         NOT NULL,
  token_hash VARCHAR(64) NOT NULL,
  created_at DATETIME    NOT NULL,
  last_used  DATETIME    DEFAULT NULL,
  is_active  TINYINT(1)  DEFAULT 1,
  UNIQUE KEY uq_token_hash (token_hash),
  KEY idx_tokens_user (user_id),
  KEY idx_tokens_tenant (tenant_id),
  CONSTRAINT fk_tokens_user   FOREIGN KEY (user_id)   REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_tokens_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- landing_carousel / landing_services — contenido de marketing global (no per-cliente)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS landing_carousel (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  title      VARCHAR(255) NOT NULL,
  subtitle   VARCHAR(255) DEFAULT NULL,
  image_path VARCHAR(500) NOT NULL,
  created_at DATETIME     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS landing_services (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  title      VARCHAR(255) NOT NULL,
  description TEXT        DEFAULT NULL,
  image_path VARCHAR(500) NOT NULL,
  created_at DATETIME     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- auth_seeds / auth_tokens_emitidos — estado de auth DGII entrante (GLOBAL).
-- El emisor que nos factura se autentica ANTES de que sepamos el tenant
-- receptor (este se resuelve luego por RNCComprador del e-CF), por eso este
-- store es compartido y vive en master. Los e-CF recibidos en si
-- (ecf_recibidos, aprobaciones_comerciales) siguen en cada tenant DB.
-- DDL identico a db/migrations/002_add_ecf_reception.sql.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS auth_seeds (
  id             INT(11)      NOT NULL AUTO_INCREMENT,
  seed_value     VARCHAR(64)  NOT NULL,
  xml_emitido    TEXT         NOT NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expira_at      DATETIME     NOT NULL,
  consumida_at   DATETIME     NULL,
  rnc_consumidor VARCHAR(11)  NULL,
  token_emitido  VARCHAR(2048) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_seed_value (seed_value),
  INDEX idx_expira_at (expira_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_tokens_emitidos (
  id             INT(11)       NOT NULL AUTO_INCREMENT,
  token          VARCHAR(2048) NOT NULL,
  rnc_consumidor VARCHAR(11)   NOT NULL,
  expedido_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expira_at      DATETIME      NOT NULL,
  revocado_at    DATETIME      NULL,
  PRIMARY KEY (id),
  INDEX idx_token_prefix (token(64)),
  INDEX idx_rnc_consumidor (rnc_consumidor),
  INDEX idx_expira_at (expira_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- ecf_integracion_backup — respaldo de e-CF generados via integracion (JSON->XML).
-- Los tenants tipo "integracion" no tienen DB propia; aqui solo guardamos el
-- e-CF firmado para respaldo. El cliente maneja sus facturas en su sistema.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ecf_integracion_backup (
  id            BIGINT       NOT NULL AUTO_INCREMENT,
  tenant_id     INT          NOT NULL,
  rnc_emisor    VARCHAR(11)  NOT NULL,
  tipo_ecf      VARCHAR(3)   NULL,
  e_ncf         VARCHAR(19)  NULL,
  rnc_comprador VARCHAR(11)  NULL,
  monto_total   DECIMAL(18,2) NULL,
  track_id      VARCHAR(64)  NULL,
  xml_firmado   MEDIUMTEXT   NOT NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_bk_tenant (tenant_id),
  KEY idx_bk_encf (rnc_emisor, e_ncf),
  CONSTRAINT fk_bk_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Tablas ESPEJO para tenants "integracion" (sin DB propia). Misma estructura
-- que en cada tenant "app" (db/migrations/002), + tenant_id para aislar.
-- Los tenants "app" siguen usando estas tablas dentro de SU propia DB.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ecf_recibidos (
  id                  INT(11)      NOT NULL AUTO_INCREMENT,
  tenant_id           INT          NOT NULL,
  track_id            VARCHAR(60)  NOT NULL,
  tipo_ecf            VARCHAR(2)   NULL,
  e_ncf               VARCHAR(13)  NULL,
  rnc_emisor          VARCHAR(11)  NOT NULL,
  razon_social_emisor VARCHAR(150) NULL,
  rnc_comprador       VARCHAR(11)  NULL,
  monto_total         DECIMAL(18,2) NULL,
  fecha_emision       DATE         NULL,
  fecha_recepcion     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  estado              VARCHAR(30)  NOT NULL DEFAULT 'RECIBIDO',
  codigo_resultado    INT(11)      NULL,
  mensaje_resultado   VARCHAR(500) NULL,
  xml_firmado         MEDIUMTEXT   NULL,
  validacion_firma    VARCHAR(20)  NULL,
  ambiente            VARCHAR(20)  NULL,
  origen_ip           VARCHAR(45)  NULL
                        COMMENT 'IP del POST (X-Forwarded-For o REMOTE_ADDR)',
  origen_user_agent   VARCHAR(255) NULL
                        COMMENT 'User-Agent del software del emisor',
  origen_auth         VARCHAR(10)  NULL
                        COMMENT 'bearer = handshake completo | firma = receptor abierto',
  origen_rnc_bearer   VARCHAR(20)  NULL
                        COMMENT 'RNC del Bearer token (si autentico)',
  firma_rnc           VARCHAR(20)  NULL
                        COMMENT 'RNC/cedula del certificado firmante (X509 de la Signature)',
  firma_subject       VARCHAR(255) NULL
                        COMMENT 'CN del certificado firmante',
  aprobacion_comercial             VARCHAR(20)  NULL,
  aprobacion_comercial_detalle     VARCHAR(500) NULL,
  aprobacion_comercial_codigo_dgii VARCHAR(5)   NULL,
  aprobacion_comercial_estado_dgii VARCHAR(120) NULL,
  aprobacion_comercial_mensaje_dgii VARCHAR(500) NULL,
  aprobacion_comercial_procesada   TINYINT(1)   NULL,
  aprobacion_comercial_fecha       DATETIME     NULL,
  created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_track_id (track_id),
  UNIQUE KEY uk_tenant_encf_emisor (tenant_id, rnc_emisor, e_ncf),
  KEY idx_rec_tenant (tenant_id),
  KEY idx_rec_estado (estado),
  CONSTRAINT fk_rec_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS aprobaciones_comerciales (
  id               INT(11)      NOT NULL AUTO_INCREMENT,
  tenant_id        INT          NOT NULL,
  factura_id       INT(11)      NULL,
  e_ncf            VARCHAR(13)  NOT NULL,
  rnc_emisor       VARCHAR(11)  NOT NULL,
  rnc_comprador    VARCHAR(11)  NOT NULL,
  estado_comercial VARCHAR(30)  NOT NULL,
  detalle_motivo   VARCHAR(500) NULL,
  xml_firmado      MEDIUMTEXT   NULL,
  validacion_firma VARCHAR(20)  NULL,
  ambiente         VARCHAR(20)  NULL,
  fecha_recepcion  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_apc_tenant (tenant_id),
  KEY idx_apc_e_ncf (e_ncf),
  CONSTRAINT fk_apc_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- MIGRACION GRATEX = TENANT #1  (ejecutar tras crear el schema)
-- ----------------------------------------------------------------------------
-- 1) Insertar el tenant Gratex apuntando a su DB de negocio actual.
--    db_pass_encrypted / cert_pass_encrypted se generan con
--    tools/create_tenant.php o encryptCredential() (NO poner texto plano aqui).
--    Por eso esta linea queda como referencia: usar el script de onboarding.
--
-- INSERT INTO tenants (id, nombre, rnc, api_key, db_host, db_port, db_name,
--                      db_user, db_pass_encrypted, ambiente, activo)
-- VALUES (1, 'Gratex', '<RNC>', '<api_key>', 'sh00032.hostgator.com', '3306',
--         'mtldtmte_new_gratexdb', 'mtldtmte_edwin', <blob>, 'ecf', 1);
--
-- 2) Mover users / api_tokens / landing_* del DB actual a master con tenant_id=1.
--    Ajustar el nombre del DB origen segun entorno (local vs server).
--
-- INSERT INTO gratex_master.users (id, tenant_id, name, last_name, email, username, password, role)
--   SELECT id, 1, name, last_name, email, username, password, role
--   FROM mtldtmte_new_gratexdb.users;
--
-- INSERT INTO gratex_master.api_tokens (id, user_id, tenant_id, token_hash, created_at, last_used, is_active)
--   SELECT id, user_id, 1, token_hash, created_at, last_used, is_active
--   FROM mtldtmte_new_gratexdb.api_tokens;
--
-- INSERT INTO gratex_master.landing_carousel SELECT * FROM mtldtmte_new_gratexdb.landing_carousel;
-- INSERT INTO gratex_master.landing_services SELECT * FROM mtldtmte_new_gratexdb.landing_services;
--
-- 3) Verificar emails unicos globales antes de habilitar MULTI_TENANT_ENABLED:
--    SELECT email, COUNT(*) c FROM gratex_master.users GROUP BY email HAVING c > 1;
--
-- 4) (Opcional, tras validar) eliminar users/api_tokens/landing_* del DB de negocio.
-- ============================================================================
