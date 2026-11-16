-- =============================================================================
-- Master Migration 019: usuarios con acceso a todas las empresas (soporte).
--
-- Ejecutar contra la base MASTER, UNA vez. Idempotente: se puede correr dos
-- veces sin error, y la segunda no toca nada. Reflejado en db/master_schema.sql
-- (instalaciones nuevas). Explicacion completa: docs/modules/acceso-global.md.
--
-- NOTA: el nombre de la master DB cambia por entorno (en el server:
-- smhynzte_master_gratex). Selecciona la base ANTES de correr esto: clic en su
-- nombre en el panel IZQUIERDO de phpMyAdmin.
--
-- SI SALE "#1049 Unknown database 'ALTO_elige_la_base_...'" (o "#1142 SELECT command
-- denied ... for table 'x'", segun los permisos del usuario): phpMyAdmin estaba
-- parado en otra base (pasa despues de correr otra migracion: cualquier consulta a
-- information_schema lo deja ahi). NO se cambio nada. Haz clic en el nombre de la
-- base correcta en el panel IZQUIERDO, abre la pestaña SQL de nuevo, pega todo y
-- ejecuta.
--
-- SI SALE "#1146 Table '<base>.tenants' doesn't exist": esa base no es la master
-- (p. ej. la base de negocio de Gratex, que tiene una tabla users heredada). NO se
-- cambio nada. Elige la master y vuelve a correrla.
--
-- POR QUÉ: cada usuario pertenece a UNA empresa (users.tenant_id) y su sesion
-- queda atada a ella (api_tokens.tenant_id). El dueño del sistema pidio un usuario
-- que pueda entrar a todas las empresas para darles soporte, sin tener una cuenta
-- en cada una.
--
-- QUÉ HACE: agrega users.acceso_global TINYINT(1) NOT NULL DEFAULT 0, despues de
-- role. Con 1, el usuario puede pasar su sesion a cualquier empresa tipo app,
-- activa y que no sea solo Express (GET /api/auth/empresas y POST
-- /api/auth/empresa). Dentro de la empresa elegida actua con el rol de su usuario
-- (users.role) buscado en ESA empresa: un 'admin' es el admin de la empresa.
--
-- NO ENCIENDE A NADIE: todos los usuarios quedan en 0. Dar el acceso es un paso
-- aparte, a mano y solo por SQL:
--     UPDATE users SET acceso_global = 1 WHERE username = '<usuario>';
-- Quitarlo:
--     UPDATE users SET acceso_global = 0 WHERE username = '<usuario>';
-- Al quitarlo, la sesion que ese usuario tenga abierta en otra empresa deja de
-- valer en su siguiente peticion (vuelve al login); la de su propia empresa sigue.
--
-- CÓMO CORRERLA: en phpMyAdmin entra a la base master, abre la pestaña SQL, pega
-- este archivo completo y ejecuta. La ultima fila (base, columna_ok) confirma:
-- columna_ok = 1 y base = la master (smhynzte_master_gratex en el server). Para
-- saber si ya esta aplicada sin tocar nada: tools/verificar_migraciones_master.sql.
-- Aplicada en produccion el 2026-11-15.
--
-- ORDEN: esta migracion ANTES de subir el codigo que la usa. Subir el codigo antes
-- no rompe el login de nadie (la API trata la columna ausente como 0: nadie tiene
-- el acceso), pero el acceso global no funciona hasta correrla. Solo toca el
-- master: no hay nada que correr en las bases de las empresas.
-- =============================================================================

SET @db := DATABASE();

-- Guardia: la base seleccionada tiene que ser la master. Si no lo es, esta
-- sentencia falla con un nombre de tabla que dice que hacer, ANTES de tocar
-- nada. No consulta information_schema: phpMyAdmin no cambia de base aqui. Pide
-- tenants y no users: la base de negocio de Gratex tambien tiene una tabla users
-- (heredada), y con users la guardia dejaria pasar esa base.
SET @guardia := IF(@db IS NULL OR @db IN ('information_schema', 'mysql', 'performance_schema', 'sys'),
  'DO (SELECT 1 FROM `ALTO_elige_la_base_master_en_el_panel`.`x` LIMIT 1)',
  CONCAT('DO (SELECT 1 FROM `', @db, '`.tenants LIMIT 1)'));
PREPARE s_guardia FROM @guardia;
EXECUTE s_guardia;
DEALLOCATE PREPARE s_guardia;

-- 1) users.acceso_global, solo si falta. Va justo despues de role.
SET @has_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'acceso_global'
);
SET @sql_col := IF(@has_col = 0,
  CONCAT('ALTER TABLE `', @db, '`.users ADD COLUMN acceso_global TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''Soporte: 1 = el usuario puede pasar su sesion a cualquier empresa tipo app, activa y que no sea solo Express (GET /api/auth/empresas, POST /api/auth/empresa). 0 = solo la suya. Se cambia solo por SQL. Ver master_migrations/019'' AFTER role'),
  'DO 0');
PREPARE s_col FROM @sql_col;
EXECUTE s_col;
DEALLOCATE PREPARE s_col;

-- Verificacion (la ultima consulta, la que muestra phpMyAdmin).
SELECT
  @db AS base,
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'users' AND COLUMN_NAME = 'acceso_global') AS columna_ok;
