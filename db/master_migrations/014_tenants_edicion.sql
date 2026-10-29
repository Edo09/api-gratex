-- =============================================================================
-- Master Migration 014: edicion del plan por empresa (completa o Express).
--
-- Ejecutar contra la base MASTER, UNA vez. Idempotente: se puede correr dos
-- veces sin error, y la segunda no toca nada. Si una corrida anterior quedo a
-- medias (unas columnas si y otras no), correrla otra vez completa solo las que
-- faltan.
-- Reflejado en db/master_schema.sql (instalaciones nuevas).
--
-- NOTA: el nombre de la master DB cambia por entorno. Selecciona la base ANTES
-- de correr esto: clic en su nombre en el panel IZQUIERDO de phpMyAdmin.
--
-- SI SALE "#1049 Unknown database 'ALTO_elige_la_base_...'" (o "#1142 SELECT command
-- denied ... for table 'x'", segun los permisos del usuario): phpMyAdmin estaba
-- parado en otra base (pasa despues de correr otra migracion: cualquier consulta a
-- information_schema lo deja ahi). NO se cambio nada. Haz clic en el nombre de la
-- base correcta en el panel IZQUIERDO, abre la pestaña SQL de nuevo, pega todo y
-- ejecuta.
--
-- POR QUÉ ESTE ORDEN (ver db/migrations/README.md y la 028): la base va en @db
-- desde la primera sentencia y la guardia va segunda, ANTES de cualquier consulta
-- a information_schema (despues de una, phpMyAdmin cambia la base actual). Cada
-- ALTER nombra la base explicitamente y la consulta de verificacion es la ultima.
--
-- POR QUÉ: Fiscalpoint Express es una edicion reducida del sistema (facturar,
-- clientes, productos, usuarios y configuracion, para una empresa que solo vende).
-- La edicion se decide por empresa, en el master: la API la lee de la fila de la
-- empresa y, si es express, limita lo que esa empresa puede llamar, ver y emitir.
-- Una empresa completa no cambia en nada.
-- Spec: docs/superpowers/specs/2026-10-27-fiscalpoint-express-design.md (repo
-- fiscalo), seccion 7.
--
-- QUÉ HACE: agrega cuatro columnas a master.tenants, en este orden y justo despues
-- de inventario_estricto (la 013) cuando esa columna existe:
--   edicion                ENUM('completa','express') NOT NULL DEFAULT 'completa'.
--                          completa = el sistema entero, como hasta hoy.
--   modulos_habilitados    TEXT NULL, lista separada por comas. Solo puede QUITAR
--                          modulos del plan Express, nunca agregar. NULL = todos.
--   tipos_ecf_habilitados  VARCHAR(64) NULL, lista separada por comas de tipos de
--                          venta (31, 32, 45 y 33 solo si se lista, ejemplo 45 o
--                          31,32,45). Una empresa express sin tipos no puede emitir.
--   cert_vence             DATE NULL, vencimiento del certificado (solo informativa).
-- Cada columna tiene su propia sonda a information_schema, que se evalua justo antes
-- de su ALTER: un estado a medias se completa solo al correrla de nuevo.
--
-- NO ENCIENDE A NADIE: todas las empresas quedan en edicion = 'completa' (el DEFAULT
-- llena las filas que ya existen) y con las otras tres columnas en NULL, que es
-- exactamente el comportamiento de hoy. Pasar una empresa a express es un paso
-- aparte, a mano y con el visto bueno del dueño del sistema: lo hace
-- tools/tenant_edicion.php, que revisa antes de cambiar (POS, existencia estricta,
-- rangos de e-NCF, certificado) y se niega si algo no cuadra.
--
-- CÓMO CORRERLA: en phpMyAdmin entra a la base master, abre la pestaña SQL, pega
-- este archivo completo y ejecuta. Si sale un error de base (#1049 o #1142), lee el
-- aviso de arriba. La ultima fila (base, columnas_ok) confirma: columnas_ok = 4.
-- Para saber si ya esta aplicada sin tocar nada: tools/verificar_migraciones_master.sql.
--
-- ORDEN: esta migracion ANTES de subir el codigo. El codigo tolera que las columnas
-- no existan (sin ellas toda empresa se lee como completa), asi que subirlo primero
-- tampoco rompe nada, pero ninguna empresa puede pasar a express hasta que existan.
-- Esta migracion solo toca el master: no hay nada que correr en las bases de las
-- empresas.
-- =============================================================================

SET @db := DATABASE();

-- Guardia: la base seleccionada tiene que ser la correcta. Si no lo es, esta
-- sentencia falla con un nombre de tabla que dice que hacer, ANTES de tocar
-- nada. No consulta information_schema: phpMyAdmin no cambia de base aqui.
SET @guardia := IF(@db IS NULL OR @db IN ('information_schema', 'mysql', 'performance_schema', 'sys'),
  'DO (SELECT 1 FROM `ALTO_elige_la_base_master_en_el_panel`.`x` LIMIT 1)',
  CONCAT('DO (SELECT 1 FROM `', @db, '`.tenants LIMIT 1)'));
PREPARE s_guardia FROM @guardia;
EXECUTE s_guardia;
DEALLOCATE PREPARE s_guardia;

-- 1) tenants.edicion, solo si falta. Va justo despues de inventario_estricto (la
--    013) cuando esa columna existe; si el master aun no la tiene, queda al final
--    de la tabla (el orden de las columnas no cambia nada). Las dos sondas se
--    evaluan aqui, justo antes de este ALTER, no al principio del archivo: asi lo
--    que un paso anterior acaba de crear ya cuenta.
SET @has_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'edicion'
);
SET @has_prev := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'inventario_estricto'
);
SET @sql_col := IF(@has_col = 0,
  CONCAT('ALTER TABLE `', @db, '`.tenants ADD COLUMN edicion ENUM(''completa'',''express'') NOT NULL DEFAULT ''completa'' COMMENT ''Edicion del plan. completa = el sistema entero, como siempre. express = solo los modulos del plan Express (modulos_habilitados puede quitar alguno) y los tipos de e-CF de tipos_ecf_habilitados. Un valor distinto se trata como completa. Se cambia con tools/tenant_edicion.php, que revisa antes de cambiar. Ver master_migrations/014''',
    IF(@has_prev > 0, ' AFTER inventario_estricto', '')),
  'DO 0');
PREPARE s_edicion FROM @sql_col;
EXECUTE s_edicion;
DEALLOCATE PREPARE s_edicion;

-- 2) tenants.modulos_habilitados, solo si falta. Va despues de edicion.
SET @has_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'modulos_habilitados'
);
SET @has_prev := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'edicion'
);
SET @sql_col := IF(@has_col = 0,
  CONCAT('ALTER TABLE `', @db, '`.tenants ADD COLUMN modulos_habilitados TEXT NULL COMMENT ''Solo express: modulos permitidos, separados por coma (facturacion, clientes, productos, usuarios, configuracion). Solo puede quitar modulos del plan, nunca agregar. NULL o vacio = los cinco. Ver master_migrations/014''',
    IF(@has_prev > 0, ' AFTER edicion', '')),
  'DO 0');
PREPARE s_modulos FROM @sql_col;
EXECUTE s_modulos;
DEALLOCATE PREPARE s_modulos;

-- 3) tenants.tipos_ecf_habilitados, solo si falta. Va despues de modulos_habilitados.
SET @has_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'tipos_ecf_habilitados'
);
SET @has_prev := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'modulos_habilitados'
);
SET @sql_col := IF(@has_col = 0,
  CONCAT('ALTER TABLE `', @db, '`.tenants ADD COLUMN tipos_ecf_habilitados VARCHAR(64) NULL COMMENT ''Solo express: tipos de e-CF que la empresa puede vender, separados por coma. Validos 31, 32, 45 y 33 (el 33 solo si se lista), ejemplo 45 o 31,32,45. El 34 (nota de credito) se deriva de la venta. NULL o vacio = no puede emitir. Ver master_migrations/014''',
    IF(@has_prev > 0, ' AFTER modulos_habilitados', '')),
  'DO 0');
PREPARE s_tipos FROM @sql_col;
EXECUTE s_tipos;
DEALLOCATE PREPARE s_tipos;

-- 4) tenants.cert_vence, solo si falta. Va despues de tipos_ecf_habilitados.
SET @has_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'cert_vence'
);
SET @has_prev := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'tipos_ecf_habilitados'
);
SET @sql_col := IF(@has_col = 0,
  CONCAT('ALTER TABLE `', @db, '`.tenants ADD COLUMN cert_vence DATE NULL COMMENT ''Fecha en que vence el certificado digital de la empresa. Es solo informativa: GET /api/emisor devuelve con ella el estado del certificado y la opcion refrescar-cert de tools/tenant_edicion.php la actualiza al renovarlo. Una fecha vieja no bloquea nada. NULL = no se sabe. Ver master_migrations/014''',
    IF(@has_prev > 0, ' AFTER tipos_ecf_habilitados', '')),
  'DO 0');
PREPARE s_cert_vence FROM @sql_col;
EXECUTE s_cert_vence;
DEALLOCATE PREPARE s_cert_vence;

-- Verificacion (la ultima consulta, la que muestra phpMyAdmin).
SELECT
  @db AS base,
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'tenants'
      AND COLUMN_NAME IN ('edicion', 'modulos_habilitados', 'tipos_ecf_habilitados', 'cert_vence')) AS columnas_ok;
