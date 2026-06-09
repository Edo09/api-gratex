-- =============================================================================
-- Migration 011: Guardar el ORIGEN de cada e-CF recibido en /api/ecf/recepcion.
--
-- Hasta ahora solo se guardaba el origen DECLARADO (RNCEmisor del XML, que dice
-- lo que el emisor quiera). Esta migracion agrega dos capas mas, para auditoria:
--   - Criptografica: quien FIRMO el documento (RNC/cedula + CN del certificado
--     X509 embebido en la Signature; el validador ya los extrae).
--   - Red/autenticacion: desde que IP y User-Agent llego el POST, por cual gate
--     entro (bearer = handshake semilla->token completado; firma = receptor
--     abierto, solo firma XMLDSig valida) y el RNC del Bearer si lo hubo.
--
-- Es ADITIVO. Solo agrega columnas. NULL = dato no disponible (filas previas).
-- Si la columna ya existe, el ALTER falla completo (ejecutar solo una vez).
--
-- NOTA master (tenants integracion): la tabla espejo gratex_master.ecf_recibidos
-- necesita las MISMAS columnas. Este archivo corre contra DBs de tenant (lo
-- replica create_tenant); para el master ya desplegado ejecutar UNA VEZ:
--   db/master_migrations/001_add_origen_recepcion.sql
-- =============================================================================

ALTER TABLE `ecf_recibidos`
  ADD COLUMN `origen_ip` VARCHAR(45) NULL
    COMMENT 'IP del POST (X-Forwarded-For o REMOTE_ADDR)',
  ADD COLUMN `origen_user_agent` VARCHAR(255) NULL
    COMMENT 'User-Agent del software del emisor',
  ADD COLUMN `origen_auth` VARCHAR(10) NULL
    COMMENT 'bearer = handshake completo | firma = receptor abierto',
  ADD COLUMN `origen_rnc_bearer` VARCHAR(20) NULL
    COMMENT 'RNC del Bearer token (si autentico)',
  ADD COLUMN `firma_rnc` VARCHAR(20) NULL
    COMMENT 'RNC/cedula del certificado firmante (X509 de la Signature)',
  ADD COLUMN `firma_subject` VARCHAR(255) NULL
    COMMENT 'CN del certificado firmante';
