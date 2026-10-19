-- =============================================================================
-- Master Migration 013: bloqueo de ventas sin existencia, por empresa.
--
-- Ejecutar contra la base MASTER, UNA vez. Idempotente: se puede correr dos
-- veces sin error, y la segunda no toca nada.
-- Reflejado en db/master_schema.sql (instalaciones nuevas).
--
-- NOTA: el nombre de la master DB cambia por entorno. Selecciona la base ANTES
-- de correr esto: clic en su nombre en el panel IZQUIERDO de phpMyAdmin.
--
-- SI SALE "#1049 Unknown database 'ALTO_elige_la_base_...'" (o "#1109 Unknown table
-- '...' in information_schema"): phpMyAdmin estaba parado en otra base (pasa
-- despues de correr otra migracion: cualquier consulta a information_schema lo
-- deja ahi). NO se cambio nada. Haz clic en el nombre de la base correcta en el
-- panel IZQUIERDO, abre la pestaña SQL de nuevo, pega todo y ejecuta.
--
-- POR QUÉ ESTE ORDEN (ver db/migrations/README.md y la 028): la base va en @db
-- desde la primera sentencia y la guardia va segunda, ANTES de cualquier consulta
-- a information_schema (despues de una, phpMyAdmin cambia la base actual). El ALTER
-- nombra la base explicitamente y la consulta de verificacion es la ultima.
--
-- POR QUÉ: hoy el sistema deja vender sin existencia: nada compara la cantidad
-- de la factura con el stock, y el stock simplemente queda negativo. Una empresa
-- puede querer lo contrario: que el numero de stock del sistema sea la verdad y
-- que una factura (e-CF) o factura simple que pida mas de lo que hay se rechace.
-- Es una decision por empresa, no global.
-- Spec: docs/superpowers/specs/2026-10-19-control-existencias-ventas-design.md
-- (repo fiscalo), seccion 4.1.
--
-- QUÉ HACE: agrega master.tenants.inventario_estricto TINYINT(1) NOT NULL
-- DEFAULT 0 (0 = vender sin existencia como hasta hoy; 1 = bloquear). La API la
-- lee de TenantResolver::current() (TenantResolver::inventarioEstricto(), solo
-- para empresas tipo 'app') y la escribe un admin desde Configuracion >
-- Inventario (PUT /api/inventario/configuracion). GET /api/branding la devuelve
-- para que la app sepa si la regla esta activa.
--
-- NO ENCIENDE A NADIE: todas las empresas quedan en 0, que es exactamente el
-- comportamiento de hoy. Encender una empresa es un paso aparte, a mano y con el
-- visto bueno del dueño del sistema (spec, seccion 9): antes se revisa, solo
-- lectura, que productos de esa empresa estan en 0 o sin existencia, porque en
-- cuanto la regla se enciende esos productos no se pueden facturar hasta que un
-- Ajuste de inventario les agregue existencia.
--
-- CÓMO CORRERLA: en phpMyAdmin entra a la base master, abre la pestaña SQL, pega
-- este archivo completo y ejecuta. Si sale un error de base (#1049 o #1109), lee
-- el aviso de arriba. La ultima fila (base, columna_ok) confirma: columna_ok = 1.
-- Para saber si ya esta aplicada sin tocar nada: tools/verificar_migraciones_master.sql.
--
-- ORDEN: esta migracion ANTES de subir el codigo. El codigo tolera que la columna
-- no exista (sin ella toda empresa lee la regla como apagada), asi que subirlo
-- primero tampoco rompe nada, pero el interruptor de Configuracion no funciona
-- hasta que la columna exista. No hay migracion de tenant: la regla no toca las
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

-- 1) tenants.inventario_estricto, solo si falta. Va justo despues de pos_enabled
--    (la 012) cuando esa columna existe; si el master aun no la tiene, queda al
--    final de la tabla (el orden de las columnas no cambia nada).
SET @has_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'inventario_estricto'
);
SET @has_pos := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'pos_enabled'
);
SET @sql_col := IF(@has_col = 0,
  CONCAT('ALTER TABLE `', @db, '`.tenants ADD COLUMN inventario_estricto TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = la empresa bloquea facturas (e-CF y simples) que piden mas de la existencia del sistema. 0 = vende sin existencia como siempre. Lo cambia un admin en Configuracion. Ver master_migrations/013''',
    IF(@has_pos > 0, ' AFTER pos_enabled', '')),
  'DO 0');
PREPARE s_col FROM @sql_col;
EXECUTE s_col;
DEALLOCATE PREPARE s_col;

-- Verificacion (la ultima consulta, la que muestra phpMyAdmin).
SELECT
  @db AS base,
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'inventario_estricto') AS columna_ok;
