-- =============================================================================
-- Master Migration 011: formato de cotizacion por tenant.
--
-- Ejecutar contra la base MASTER, UNA vez. Idempotente: se puede correr dos
-- veces sin error, y la segunda no toca nada.
-- Reflejado en db/master_schema.sql (instalaciones nuevas).
--
-- NOTA: el nombre de la master DB cambia por entorno (gratex_master en local,
-- mtldtmte_master_gratex en el server). Selecciona la base ANTES de correr esto
-- (en phpMyAdmin basta con entrar a la base; por CLI usa `USE <tu_master>;`).
--
-- PARA QUE: la cotizacion (pantalla, reglas, numeracion y PDF) era la de Gratex
-- para todos los tenants. Ahora cada tenant tiene un formato de cotizacion: un
-- modulo de codigo en src/Utils/Cotizacion/ (api) y otro en
-- src/features/cotizaciones/formatos/ (fiscalo). Esta columna dice cual usa: la
-- API la lee de TenantResolver::current() para elegir el formato al guardar, y
-- GET /api/branding la devuelve para que el front elija la pantalla.
--
-- Default 'gratex': todos los tenants actuales quedan exactamente como hoy.
-- Se cambia SOLO por SQL (no hay pantalla ni PUT /api/branding para esto): un
-- formato es codigo hecho para un cliente concreto.
--
-- ORDEN DE DESPLIEGUE: correr ESTA migracion y la 026 de cada tenant ANTES de
-- subir el codigo nuevo. El codigo tolera que la columna no exista (sin ella
-- todo tenant es 'gratex'), pero un tenant solo se pasa a otro formato cuando
-- su DB ya tiene la 026.
-- =============================================================================

-- 1) Columna ('gratex' = el formato de siempre).
SET @has_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tenants'
    AND COLUMN_NAME = 'cotizacion_formato'
);
SET @sql_col := IF(@has_col = 0,
  'ALTER TABLE tenants ADD COLUMN cotizacion_formato VARCHAR(40) NOT NULL DEFAULT ''gratex'' COMMENT ''Formato de cotizacion: gratex | ferreteria (src/Utils/Cotizacion/)'' AFTER pdf_accent_color',
  'DO 0');
PREPARE s_col FROM @sql_col;
EXECUTE s_col;
DEALLOCATE PREPARE s_col;

-- 2) Verificacion: en la primera corrida todos los tenants salen en 'gratex'.
SELECT id, nombre, rnc, cotizacion_formato FROM tenants ORDER BY id;

-- =============================================================================
-- Como pasar un tenant a otro formato (ej. Ferreteria).
--
--   0. Antes: su DB de tenant ya tiene la migracion 026, su emisor_config tiene
--      telefono y correo (salen en el pie del PDF), y sus roles incluyen el
--      modulo `cotizaciones`.
--
--   1. UPDATE tenants SET cotizacion_formato = 'ferreteria' WHERE id = <id>;
--
--   2. Verificar:
--      SELECT id, nombre, cotizacion_formato FROM tenants WHERE id = <id>;
--
-- Volver al de Gratex: UPDATE tenants SET cotizacion_formato = 'gratex' WHERE id = <id>;
--   OJO: las cotizaciones NUEVAS de ese tenant saldrian con el PDF de Gratex
--   (con la cuenta de banco de Gratex). Quita el modulo `cotizaciones` de sus
--   roles hasta corregir el formato. Las ya guardadas conservan su formato.
--   Ver docs/modules/cotizaciones-formatos.md.
-- =============================================================================
