-- =============================================================================
-- Master Migration 006: Bitacora de auditoria (audit_logs).
--
-- Ejecutar contra la base MASTER (gratex_master), UNA sola vez.
-- Reflejado en db/master_schema.sql (instalaciones nuevas) y, para el fallback
-- single-tenant (MULTI_TENANT_ENABLED=false), en db/tenant_schema.sql.
--
-- Registro centralizado de "quien hizo que, cuando" en todo el sistema:
--   - Mutaciones de datos maestros (CREATE/UPDATE/DELETE).
--   - Ciclo de vida e-CF (EMIT, RESEND, transiciones de estado, ACECF, notas).
--   - Eventos de autenticacion (login ok/fallido, logout, expiracion de sesion).
--
-- Aislamiento por tenant via columna tenant_id. user_id/tenant_id pueden ser
-- NULL (login fallido sin tenant aun, principal DGII entrante, integracion).
-- old_values/new_values guardan JSON (MEDIUMTEXT, version-safe vs tipo JSON) ya
-- redactado: NUNCA passwords, secrets, claves de certificado ni tokens en claro.
-- Escritura siempre via src/AuditLogger.php (tolerante a fallos, no rompe nada).
-- Idempotente: CREATE TABLE IF NOT EXISTS.
-- =============================================================================

USE gratex_master;

CREATE TABLE IF NOT EXISTS audit_logs (
  id                 BIGINT       NOT NULL AUTO_INCREMENT,
  tenant_id          INT          NULL COMMENT 'NULL en login fallido (tenant aun no resuelto)',
  user_id            INT          NULL COMMENT 'NULL en DGII entrante / integracion / login fallido',
  username           VARCHAR(100) NULL,
  email              VARCHAR(150) NULL,
  module             VARCHAR(40)  NOT NULL COMMENT "modulo/ruta: 'facturas','clients','auth'...",
  entity_type        VARCHAR(60)  NULL COMMENT "tipo de entidad: 'factura','client'...",
  entity_id          VARCHAR(64)  NULL COMMENT 'id int o string (track_id / e_ncf)',
  action             VARCHAR(40)  NOT NULL COMMENT 'CREATE/UPDATE/DELETE/EMIT/LOGIN_SUCCESS...',
  http_method        VARCHAR(10)  NULL,
  endpoint           VARCHAR(255) NULL,
  ip_address         VARCHAR(45)  NULL,
  user_agent         VARCHAR(255) NULL,
  browser            VARCHAR(60)  NULL,
  os                 VARCHAR(60)  NULL,
  device_type        VARCHAR(20)  NULL COMMENT 'desktop|mobile|tablet|bot',
  session_token_hash VARCHAR(64)  NULL COMMENT 'sha256 del token de sesion (nunca el token en claro)',
  old_values         MEDIUMTEXT   NULL COMMENT 'JSON del estado previo (redactado)',
  new_values         MEDIUMTEXT   NULL COMMENT 'JSON del estado nuevo (redactado)',
  description        VARCHAR(255) NULL,
  success            TINYINT(1)   NOT NULL DEFAULT 1,
  error_message      VARCHAR(500) NULL COMMENT 'tambien guarda el motivo de fallo de auth',
  created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_tenant_created (tenant_id, created_at),
  KEY idx_audit_tenant_module  (tenant_id, module),
  KEY idx_audit_tenant_user    (tenant_id, user_id),
  KEY idx_audit_entity         (entity_type, entity_id),
  KEY idx_audit_action         (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
