-- =============================================================================
-- Master Migration 001: Origen de e-CF recibidos (tabla espejo de integracion).
--
-- Ejecutar contra la base MASTER (gratex_master), UNA sola vez.
-- Contraparte de db/migrations/011_add_origen_recepcion.sql (que corre en las
-- DBs de tenant tipo app; create_tenant la replica automaticamente).
--
-- Las migraciones del master viven en db/master_migrations/ porque el
-- master_schema.sql solo sirve para instalaciones nuevas: el master ya
-- desplegado se actualiza con estos ALTER incrementales.
--
-- Es ADITIVO. Solo agrega columnas. NULL = dato no disponible (filas previas).
-- Si la columna ya existe, el ALTER falla completo (ejecutar solo una vez).
-- =============================================================================

ALTER TABLE `ecf_recibidos`
  ADD COLUMN `origen_ip` VARCHAR(45) NULL
    COMMENT 'IP del POST (X-Forwarded-For o REMOTE_ADDR)',
  ADD COLUMN `origen_user_agent` VARCHAR(255) NULL
    COMMENT 'User-Agent del software del emisor',
  ADD COLUMN `origen_auth` VARCHAR(10) NULL
    COMMENT 'bearer = handshake completo | firma = receptor abierto | manual = import_recibido.php',
  ADD COLUMN `origen_rnc_bearer` VARCHAR(20) NULL
    COMMENT 'RNC del Bearer token (si autentico)',
  ADD COLUMN `firma_rnc` VARCHAR(20) NULL
    COMMENT 'RNC/cedula del certificado firmante (X509 de la Signature)',
  ADD COLUMN `firma_subject` VARCHAR(255) NULL
    COMMENT 'CN del certificado firmante';
