-- =============================================================================
-- Master Migration 014: lo que la DGII dijo de cada e-CF de integracion.
--
-- Ejecutar contra la base MASTER, UNA vez. Idempotente: se puede correr dos
-- veces sin error, y la segunda no toca nada.
-- Reflejado en db/master_schema.sql (instalaciones nuevas).
--
-- NOTA: el nombre de la master DB cambia por entorno. Selecciona la base ANTES
-- de correr esto: clic en su nombre en el panel IZQUIERDO de phpMyAdmin.
--
-- SI SALE "#1049 Unknown database 'ALTO_elige_la_base_...'" (o "#1109 Unknown table
-- '...' in information_schema"): phpMyAdmin estaba parado en otra base. NO se
-- cambio nada. Haz clic en el nombre de la base correcta en el panel IZQUIERDO,
-- abre la pestaña SQL de nuevo, pega todo y ejecuta.
--
-- POR QUÉ: los tenants 'integracion' no tienen base propia; su unico registro es
-- ecf_integracion_backup, que guardaba el XML y el track_id pero NO el veredicto
-- de la DGII. GET /api/integracion/estado consultaba y tiraba la respuesta. Caso
-- real (2026-10-30, PUBLIC SOUND SRL, E310000000027 en certecf): rechazado con
-- codigo 145 "No se encuentra en una fase valida dentro del proceso de
-- certificacion" y no quedo rastro en ningun lado.
--
-- QUÉ HACE: agrega a ecf_integracion_backup
--   ambiente              ambiente al que se envio (ecf | certecf | testecf)
--   codigo_seguridad      primeros 6 de SignatureValue (va en el QR)
--   estado_dgii           ENVIADO al emitir; ACEPTADO / RECHAZADO / ... al consultar
--   dgii_mensajes         JSON: consulta.mensajes de la DGII (motivo del rechazo)
--   dgii_respuesta        JSON: ultima respuesta de la DGII tal cual
--   estado_consultado_at  cuando se guardo el ultimo estado
-- y un indice por estado para listar los rechazos.
--
-- ORDEN: esta migracion ANTES de subir el codigo. El codigo tolera que las
-- columnas no existan (el respaldo se guarda igual, sin el estado), asi que
-- subirlo primero no rompe la emision, pero no guarda nada nuevo hasta correrla.
-- No hay migracion de tenant.
-- =============================================================================

SET @db := DATABASE();

-- Guardia: la base seleccionada tiene que ser la correcta (no consulta
-- information_schema: phpMyAdmin no cambia de base aqui).
SET @guardia := IF(@db IS NULL OR @db IN ('information_schema', 'mysql', 'performance_schema', 'sys'),
  'DO (SELECT 1 FROM `ALTO_elige_la_base_master_en_el_panel`.`x` LIMIT 1)',
  CONCAT('DO (SELECT 1 FROM `', @db, '`.ecf_integracion_backup LIMIT 1)'));
PREPARE s_guardia FROM @guardia;
EXECUTE s_guardia;
DEALLOCATE PREPARE s_guardia;

SET @has_amb := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'ecf_integracion_backup' AND COLUMN_NAME = 'ambiente');
SET @sql := IF(@has_amb = 0,
  CONCAT('ALTER TABLE `', @db, '`.ecf_integracion_backup ',
    'ADD COLUMN ambiente VARCHAR(20) NULL AFTER track_id, ',
    'ADD COLUMN codigo_seguridad VARCHAR(10) NULL AFTER ambiente, ',
    'ADD COLUMN estado_dgii VARCHAR(40) NULL AFTER codigo_seguridad, ',
    'ADD COLUMN dgii_mensajes TEXT NULL AFTER estado_dgii, ',
    'ADD COLUMN dgii_respuesta MEDIUMTEXT NULL AFTER dgii_mensajes, ',
    'ADD COLUMN estado_consultado_at DATETIME NULL AFTER dgii_respuesta'),
  'DO 0');
PREPARE s FROM @sql;
EXECUTE s;
DEALLOCATE PREPARE s;

SET @has_idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'ecf_integracion_backup' AND INDEX_NAME = 'idx_bk_estado');
SET @sql := IF(@has_idx = 0,
  CONCAT('ALTER TABLE `', @db, '`.ecf_integracion_backup ADD KEY idx_bk_estado (tenant_id, estado_dgii)'),
  'DO 0');
PREPARE s FROM @sql;
EXECUTE s;
DEALLOCATE PREPARE s;

-- Verificacion (la ultima consulta, la que muestra phpMyAdmin): columnas_ok = 6, indice_ok = 1.
SELECT
  @db AS base,
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'ecf_integracion_backup'
      AND COLUMN_NAME IN ('ambiente', 'codigo_seguridad', 'estado_dgii', 'dgii_mensajes',
                          'dgii_respuesta', 'estado_consultado_at')) AS columnas_ok,
  (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'ecf_integracion_backup'
      AND INDEX_NAME = 'idx_bk_estado' AND SEQ_IN_INDEX = 1) AS indice_ok;
