-- ============================================================================
-- 029_precios_4_decimales.sql — products.precio .. precio_4 pasan a DECIMAL(18,4).
-- ============================================================================
-- Para DBs de tenant YA desplegados: correr en la base de CADA empresa. Los
-- tenants nuevos lo reciben via db/tenant_schema.sql (mismos tipos y COMMENT).
--
-- POR QUE:
--   El precio se guarda SIN ITBIS. Con 2 decimales, el precio de gondola que
--   escribio el dueno no se puede recuperar en el 15 % de los casos: RD$10 se
--   guarda 8.47 y vuelve como 9.99; RD$100 -> 84.75 -> 100.01; RD$250 -> 211.86
--   -> 249.99 (simulacion de RD$1 a RD$10,000). Con 4 decimales vuelve exacto
--   en todos (21.1864 x 1.18 = 25.00). El POS muestra el precio con ITBIS, asi
--   que lo necesita exacto (docs/specs/pos.md, C7).
--
--   Las lineas que copian el precio del producto ya guardan 4 decimales desde la
--   025 (factura_items, cotizacion_items y gasto_items: amount DECIMAL(18,4)), y
--   la emision redondea PrecioUnitarioItem a 4 (EcfItemMapper). Nada que lea el
--   precio lo recorta a 2: el cambio es solo de esta tabla.
--
-- QUE HACE (no reescribe valores):
--   Si alguna de las cuatro columnas tiene menos de 4 decimales, un solo ALTER
--   las pasa a DECIMAL(18,4) con la misma nulabilidad, default y COMMENT de
--   tenant_schema.sql. Los valores no cambian: 21.19 queda 21.1900. Si ya
--   tienen 4, no hace nada.
--   DECIMAL(18,4) deja 14 digitos enteros (antes 16): el resultado cuenta los
--   precios que no cabrian. Si sale algo distinto de 0 NO se cambia nada.
--
-- COMO CORRERLA (phpMyAdmin):
--   Entra a la base del tenant, pestana SQL, pega TODO el archivo y ejecuta.
--   La primera sentencia guarda la base seleccionada en @db y todo lo demas la
--   nombra explicitamente (ver db/migrations/README.md y la 028). Para no
--   depender de la base seleccionada, cambia esa linea por el nombre:
--     SET @db := 'smhynzte_002';
--   El resultado (la ultima consulta) son cuatro filas, precio .. precio_4, con
--   COLUMN_TYPE decimal(18,4), decimales_ok = SI y fuera_de_rango = 0.
--   "Table '...products' doesn't exist" = no era la base del tenant: no se
--   cambio nada; selecciona la correcta y vuelve a correrla.
--
-- SI SALE "#1049 Unknown database 'ALTO_elige_la_base_...'" (o "#1109 Unknown table
-- '...' in information_schema"): phpMyAdmin estaba parado en otra base (pasa
-- despues de correr otra migracion: cualquier consulta a information_schema lo
-- deja ahi). NO se cambio nada. Haz clic en el nombre de la base correcta en el
-- panel IZQUIERDO, abre la pestana SQL de nuevo, pega todo y ejecuta.
--
-- ANTES DE CORRER:
--   Cambiar la escala de un DECIMAL copia la tabla; products es chica, pero
--   mejor fuera de horario.
--
-- ORDEN: no depende de codigo nuevo; se puede correr ya. Va ANTES de desplegar
-- la casilla "Este precio incluye ITBIS" de la ficha del producto: con la
-- columna en 2 decimales, el neto que guardaria (21.1864) se recortaria a 21.19.
--
-- Se puede correr dos veces: si el cambio ya esta, el paso ejecuta DO 0.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 0) La base del tenant: la que esta seleccionada en phpMyAdmin.
-- ----------------------------------------------------------------------------
SET @db := DATABASE();

-- Guardia: la base seleccionada tiene que ser la correcta. Si no lo es, esta
-- sentencia falla con un nombre de tabla que dice que hacer, ANTES de tocar
-- nada. No consulta information_schema: phpMyAdmin no cambia de base aqui.
SET @guardia := IF(@db IS NULL OR @db IN ('information_schema', 'mysql', 'performance_schema', 'sys'),
  'DO (SELECT 1 FROM `ALTO_elige_la_base_de_la_empresa_en_el_panel`.`x` LIMIT 1)',
  CONCAT('DO (SELECT 1 FROM `', @db, '`.products LIMIT 1)'));
PREPARE s_guardia FROM @guardia;
EXECUTE s_guardia;
DEALLOCATE PREPARE s_guardia;

-- ----------------------------------------------------------------------------
-- 1) Precios que no caben en 14 digitos enteros. Si @db no es la base del
--    tenant, esto falla aqui y no se toca nada.
-- ----------------------------------------------------------------------------
SET @sql_029_rango := CONCAT(
  'SELECT COUNT(*) INTO @fuera_rango FROM `', @db, '`.products ',
  'WHERE ABS(precio) >= 1e14 OR ABS(COALESCE(precio_2, 0)) >= 1e14 ',
  'OR ABS(COALESCE(precio_3, 0)) >= 1e14 OR ABS(COALESCE(precio_4, 0)) >= 1e14');
PREPARE s_029_rango FROM @sql_029_rango;
EXECUTE s_029_rango;
DEALLOCATE PREPARE s_029_rango;

-- ----------------------------------------------------------------------------
-- 2) Las cuatro columnas en un solo ALTER, solo si falta y si todo cabe.
-- ----------------------------------------------------------------------------
SET @arreglar := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'products'
    AND COLUMN_NAME IN ('precio', 'precio_2', 'precio_3', 'precio_4')
    AND NUMERIC_SCALE < 4
);
SET @sql_029 := IF(@arreglar > 0 AND @fuera_rango = 0,
  CONCAT('ALTER TABLE `', @db, '`.products ',
    'MODIFY precio DECIMAL(18,4) NOT NULL DEFAULT 0.0000 ',
      'COMMENT ''Lista de precio 1, SIN ITBIS (la que usan facturas y cotizaciones). 4 decimales: el precio con ITBIS se recupera exacto'', ',
    'MODIFY precio_2 DECIMAL(18,4) NULL DEFAULT NULL COMMENT ''Lista de precio 2 (NULL = no aplica)'', ',
    'MODIFY precio_3 DECIMAL(18,4) NULL DEFAULT NULL COMMENT ''Lista de precio 3 (NULL = no aplica)'', ',
    'MODIFY precio_4 DECIMAL(18,4) NULL DEFAULT NULL COMMENT ''Lista de precio 4 (NULL = no aplica)'''),
  'DO 0');
PREPARE s_029 FROM @sql_029;
EXECUTE s_029;
DEALLOCATE PREPARE s_029;

-- ----------------------------------------------------------------------------
-- Resultado (la ultima consulta, la que muestra phpMyAdmin).
-- ----------------------------------------------------------------------------
SELECT @db AS base, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT,
       IF(NUMERIC_SCALE >= 4, 'SI', 'NO') AS decimales_ok,
       @fuera_rango AS fuera_de_rango
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'products'
  AND COLUMN_NAME IN ('precio', 'precio_2', 'precio_3', 'precio_4')
ORDER BY ORDINAL_POSITION;
