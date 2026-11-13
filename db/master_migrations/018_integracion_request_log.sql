-- =============================================================================
-- Master Migration 018: bitacora de peticiones de integracion (lo que el
-- cliente manda y lo que se le contesta).
--
-- Ejecutar contra la base MASTER, UNA vez. Idempotente: se puede correr dos
-- veces sin error, y la segunda no toca nada.
-- Reflejado en db/master_schema.sql (instalaciones nuevas).
--
-- NOTA: el nombre de la master DB cambia por entorno. Selecciona la base ANTES
-- de correr esto: clic en su nombre en el panel IZQUIERDO de phpMyAdmin.
--
-- SI SALE "#1049 Unknown database 'ALTO_elige_la_base_...'" (o "#1146 Table
-- '...tenants' doesn't exist"): phpMyAdmin estaba parado en otra base. NO se
-- cambio nada. Haz clic en el nombre de la base correcta en el panel IZQUIERDO,
-- abre la pestaña SQL de nuevo, pega todo y ejecuta.
--
-- POR QUÉ: los tenants 'integracion' no tienen base ni pantalla propias. Cuando
-- un cliente reporta un error, lo unico que habia era el error_log del server
-- (sin shell) y ecf_integracion_backup, que solo existe si el e-CF se emitio.
-- Caso real (2026-11-13, tenant 18): "Fallo consultando el estado a DGII: ...
-- HTTP 401" en GET /api/integracion/estado, sin rastro de que e-NCF, track_id o
-- codigo_seguridad mando el cliente.
--
-- QUÉ HACE: crea integracion_request_log, una fila por peticion a
-- /api/integracion/* (src/IntegracionRequestLog.php):
--   tenant_id / rnc     empresa por la que se actuo (NULL si la credencial fallo)
--   api_key_prefijo     primeros 8 del X-API-KEY (nunca el X-API-SECRET)
--   metodo, ruta, query_string, e_ncf, ip, user_agent
--   request_headers     JSON con cabeceras utiles, sin credenciales
--   request_body        lo que mando el cliente (tope 1 MB; claves sensibles redactadas)
--   http_status         el codigo HTTP que se le devolvio
--   response_body       lo que se le devolvio (el XML firmado va como marcador:
--                       ya esta en ecf_integracion_backup; tope 1 MB)
--   request_bytes / response_bytes   tamaños originales (si difieren del guardado, se recorto)
--   error_interno       el detalle que NO se le mostro (excepcion completa)
--   duracion_ms, created_at (hora de RD)
-- Sin FK a tenants a proposito: una bitacora nunca debe fallar por integridad.
-- Las filas de mas de INTEGRACION_LOG_DIAS dias (90 por defecto) se purgan solas.
--
-- ORDEN: esta migracion ANTES de subir el codigo. El codigo tolera que la tabla
-- no exista (la peticion responde igual y deja una linea en el error_log), asi
-- que subirlo primero no rompe nada, pero no guarda nada hasta correrla.
-- No hay migracion de tenant.
-- =============================================================================

SET @db := DATABASE();

-- Guardia: la base seleccionada tiene que ser la master (la que tiene tenants).
SET @guardia := IF(@db IS NULL OR @db IN ('information_schema', 'mysql', 'performance_schema', 'sys'),
  'DO (SELECT 1 FROM `ALTO_elige_la_base_master_en_el_panel`.`x` LIMIT 1)',
  CONCAT('DO (SELECT 1 FROM `', @db, '`.tenants LIMIT 1)'));
PREPARE s_guardia FROM @guardia;
EXECUTE s_guardia;
DEALLOCATE PREPARE s_guardia;

SET @sql := CONCAT('CREATE TABLE IF NOT EXISTS `', @db, '`.integracion_request_log (',
  'id               BIGINT        NOT NULL AUTO_INCREMENT, ',
  'tenant_id        INT           NULL COMMENT ''Empresa por la que se actuo (tras elegir hermana por RNC). NULL = credencial invalida'', ',
  'rnc              VARCHAR(11)   NULL, ',
  'api_key_prefijo  VARCHAR(8)    NULL COMMENT ''Primeros 8 caracteres del X-API-KEY; el X-API-SECRET nunca se guarda'', ',
  'metodo           VARCHAR(10)   NOT NULL, ',
  'ruta             VARCHAR(255)  NOT NULL COMMENT ''Path, p. ej. /api/integracion/estado'', ',
  'query_string     VARCHAR(1000) NULL, ',
  'e_ncf            VARCHAR(19)   NULL, ',
  'ip               VARCHAR(45)   NULL, ',
  'user_agent       VARCHAR(255)  NULL, ',
  'request_headers  TEXT          NULL COMMENT ''JSON: cabeceras utiles, sin credenciales'', ',
  'request_body     MEDIUMTEXT    NULL, ',
  'request_bytes    INT UNSIGNED  NULL COMMENT ''Bytes originales; si es mayor que lo guardado, se recorto'', ',
  'http_status      SMALLINT      NULL, ',
  'response_body    MEDIUMTEXT    NULL COMMENT ''Lo que se devolvio; el XML firmado va como marcador (esta en ecf_integracion_backup)'', ',
  'response_bytes   INT UNSIGNED  NULL COMMENT ''Bytes originales de la respuesta'', ',
  'error_interno    TEXT          NULL COMMENT ''Detalle del fallo que NO se le devolvio al cliente'', ',
  'duracion_ms      INT UNSIGNED  NULL, ',
  'created_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP, ',
  'PRIMARY KEY (id), ',
  'KEY idx_irl_tenant_fecha (tenant_id, created_at), ',
  'KEY idx_irl_encf (e_ncf), ',
  'KEY idx_irl_status_fecha (http_status, created_at), ',
  'KEY idx_irl_fecha (created_at)',
  ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
PREPARE s FROM @sql;
EXECUTE s;
DEALLOCATE PREPARE s;

-- Verificacion (la ultima consulta, la que muestra phpMyAdmin): tabla_ok = 1, columnas_ok = 19, indices_ok = 4.
SELECT
  @db AS base,
  (SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'integracion_request_log') AS tabla_ok,
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'integracion_request_log') AS columnas_ok,
  (SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'integracion_request_log'
      AND INDEX_NAME IN ('idx_irl_tenant_fecha', 'idx_irl_encf', 'idx_irl_status_fecha', 'idx_irl_fecha')) AS indices_ok;
