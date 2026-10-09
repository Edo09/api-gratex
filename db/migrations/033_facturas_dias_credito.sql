-- ============================================================================
-- 033_facturas_dias_credito.sql — facturas.dias_credito: plazo de la factura
-- simple a credito (30, 45 o 60 dias).
-- ============================================================================
-- Para DBs de tenant YA desplegados: correr en la base de CADA empresa. Los
-- tenants nuevos lo reciben via db/tenant_schema.sql (mismo tipo y COMMENT).
--
-- POR QUE:
--   A credito se puede vender a 30, 45 o 60 dias. En el e-CF el plazo viaja
--   como FechaLimitePago dentro del XML firmado, pero la factura simple no
--   tiene XML: sin esta columna no habia donde guardarlo y la representacion
--   impresa asumia siempre 30 (EcfDocumento::fechaLimitePago). Se guardan dias
--   y no una fecha: si se corrige la fecha de la factura, el vencimiento la
--   sigue.
--   NULL = contado, o credito sin plazo elegido (facturas viejas): 30 dias.
--
-- QUE HACE:
--   Agrega la columna si no esta. No toca ningun valor ni otra columna.
--
-- COMO CORRERLA (phpMyAdmin):
--   Entra a la base del tenant, pestana SQL, pega TODO el archivo y ejecuta.
--   La primera sentencia guarda la base seleccionada en @db y todo lo demas la
--   nombra explicitamente. Para no depender de la base seleccionada, cambia esa
--   linea por el nombre:
--     SET @db := 'smhynzte_new_gratexdb';
--   El resultado (la ultima consulta) es una fila: dias_credito, smallint, YES.
--
-- ORDEN: va ANTES de desplegar el API que guarda el plazo. Con el API nuevo y
-- sin la columna, guardar CUALQUIER factura simple falla (el INSERT la nombra).
--
-- Se puede correr dos veces: si la columna ya esta, el paso ejecuta DO 0.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 0) La base del tenant: la que esta seleccionada.
-- ----------------------------------------------------------------------------
SET @db := DATABASE();

-- Guardia: la base seleccionada tiene que ser la correcta. Si no lo es, esta
-- sentencia falla con un nombre de tabla que dice que hacer, ANTES de tocar nada.
SET @guardia := IF(@db IS NULL OR @db IN ('information_schema', 'mysql', 'performance_schema', 'sys'),
  'DO (SELECT 1 FROM `ALTO_elige_la_base_de_la_empresa_en_el_panel`.`x` LIMIT 1)',
  CONCAT('DO (SELECT 1 FROM `', @db, '`.facturas LIMIT 1)'));
PREPARE s_guardia FROM @guardia;
EXECUTE s_guardia;
DEALLOCATE PREPARE s_guardia;

-- ----------------------------------------------------------------------------
-- 1) La columna, solo si falta.
-- ----------------------------------------------------------------------------
SET @falta_033 := (
  SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'facturas' AND COLUMN_NAME = 'dias_credito'
);
SET @sql_033 := IF(@falta_033,
  CONCAT('ALTER TABLE `', @db, '`.facturas ',
    'ADD COLUMN dias_credito SMALLINT NULL DEFAULT NULL ',
    'COMMENT ''Plazo de credito en dias (factura simple). NULL = contado o sin plazo: 30'' ',
    'AFTER tipo_pago'),
  'DO 0');
PREPARE s_033 FROM @sql_033;
EXECUTE s_033;
DEALLOCATE PREPARE s_033;

-- ----------------------------------------------------------------------------
-- Resultado (la ultima consulta, la que muestra phpMyAdmin).
-- ----------------------------------------------------------------------------
SELECT @db AS base, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_COMMENT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'facturas' AND COLUMN_NAME = 'dias_credito';
