-- =============================================================================
-- Master Migration 017: la edicion 'ambas' (la app completa Y Fiscalpoint Express).
--
-- Ejecutar contra la base MASTER, UNA vez. Idempotente: se puede correr dos
-- veces sin error, y la segunda no toca nada. Requiere la 015 (la columna
-- tenants.edicion): sin ella se detiene con un aviso, antes de tocar nada.
-- Reflejado en db/master_schema.sql (instalaciones nuevas).
--
-- El numero: la 016 sigue reservada para el plan ITBIS de facturas simples
-- (ver db/migrations/README.md), asi que esta toma la 017 y nadie se renumera.
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
-- SI SALE "#1049 Unknown database 'ALTO_aplica_primero_la_015_tenants_edicion'": la
-- master no tiene la 015 (no existe tenants.edicion). NO se cambio nada. Aplica
-- db/master_migrations/015_tenants_edicion.sql y vuelve a correr esta.
--
-- POR QUÉ: una empresa con edicion 'express' queda limitada a Express en TODAS sus
-- peticiones (la app completa deja de servirle). El dueño pidio que una empresa pueda
-- usar las dos versiones, la normal y Express. 'ambas' es eso: la API trata las
-- peticiones de la app completa igual que las de una empresa completa (sin ningun
-- limite) y aplica los limites de Express solo a las que se anuncian como Express
-- (cabecera X-Fiscalpoint-Edicion: express). Las dos usan el mismo login, la misma
-- base y los mismos datos. Spec: docs/superpowers/specs/2026-10-27-fiscalpoint-express-design.md
-- (repo fiscalo), seccion 7, y docs/integrations/express-release-checklist.md.
--
-- QUÉ HACE: cambia el tipo de tenants.edicion de ENUM('completa','express') a
-- ENUM('completa','express','ambas'), con el mismo NOT NULL y el mismo DEFAULT
-- 'completa'; ningun valor existente cambia. Agregar un valor al final de un ENUM
-- no reescribe la tabla.
--
-- NO ENCIENDE A NADIE: ninguna fila cambia, todas las empresas siguen como estaban.
-- Pasar una empresa a 'ambas' es un paso aparte, a mano y con el visto bueno del
-- dueño del sistema: lo hace tools/tenant_edicion.php --edicion=ambas, que revisa
-- antes de cambiar y NO toca el POS, el control de existencias, la credencial de
-- integracion ni los permisos de los roles (la app completa sigue como estaba).
--
-- CÓMO CORRERLA: en phpMyAdmin entra a la base master, abre la pestaña SQL, pega
-- este archivo completo y ejecuta. La ultima fila (base, ambas_ok) confirma:
-- ambas_ok = 1. Para saber si ya esta aplicada sin tocar nada:
-- tools/verificar_migraciones_master.sql.
--
-- ORDEN: esta migracion ANTES de pasar una empresa a 'ambas'. Subir el codigo antes
-- no rompe nada: sin ella ninguna empresa puede ser 'ambas'. Si alguien escribiera
-- 'ambas' en una master sin esta migracion, MySQL sin modo estricto guarda un valor
-- vacio, que la API trata como completa (y avisa una vez en el log); con modo estricto
-- rechaza el UPDATE. Esta migracion solo toca el master: no hay nada que correr en las
-- bases de las empresas.
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

-- 1) Requiere la 015: sin tenants.edicion no hay ENUM que ampliar. La sonda se
--    evalua aqui, justo antes de la sentencia que la usa.
SET @has_edicion := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'edicion'
);
SET @guardia_015 := IF(@has_edicion = 0,
  'DO (SELECT 1 FROM `ALTO_aplica_primero_la_015_tenants_edicion`.`x` LIMIT 1)',
  'DO 0');
PREPARE s_guardia_015 FROM @guardia_015;
EXECUTE s_guardia_015;
DEALLOCATE PREPARE s_guardia_015;

-- 2) El valor 'ambas' en el ENUM, solo si falta. MODIFY COLUMN sin AFTER deja la
--    columna donde esta. La sonda mira el tipo de la columna tal como esta ahora.
SET @tipo_edicion := (
  SELECT COLUMN_TYPE FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'edicion'
);
SET @sql_ambas := IF(@tipo_edicion IS NOT NULL AND LOCATE('''ambas''', @tipo_edicion) = 0,
  CONCAT('ALTER TABLE `', @db, '`.tenants MODIFY COLUMN edicion ENUM(''completa'',''express'',''ambas'') NOT NULL DEFAULT ''completa'' COMMENT ''Edicion del plan. completa = el sistema entero, como siempre. express = la empresa solo usa Express: todas sus peticiones tienen los limites de Express (modulos_habilitados puede quitar alguno y tipos_ecf_habilitados dice los tipos de e-CF). ambas = la app completa Y Express: solo las peticiones que se anuncian como Express (cabecera X-Fiscalpoint-Edicion) tienen esos limites. Un valor distinto se trata como completa. Se cambia con tools/tenant_edicion.php, que revisa antes de cambiar. Ver master_migrations/015 y 017'''),
  'DO 0');
PREPARE s_ambas FROM @sql_ambas;
EXECUTE s_ambas;
DEALLOCATE PREPARE s_ambas;

-- Verificacion (la ultima consulta, la que muestra phpMyAdmin).
SELECT
  @db AS base,
  (SELECT LOCATE('''ambas''', COLUMN_TYPE) > 0 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'edicion') AS ambas_ok;
