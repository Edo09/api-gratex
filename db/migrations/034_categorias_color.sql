-- ============================================================================
-- 034_categorias_color.sql — categories.color: el color de la categoria en el
-- POS (chips y tarjetas), elegido desde app.* con un selector HEX.
-- ============================================================================
-- Para DBs de tenant YA desplegados: correr en la base de CADA empresa. Los
-- tenants nuevos lo reciben via db/tenant_schema.sql (mismo tipo, COMMENT e
-- indice).
--
-- POR QUE:
--   El color salia de un calculo sobre el nombre (8 colores para todas las
--   categorias): dos categorias podian salir iguales y el dueno no podia
--   cambiarlo. Ahora se elige en app.* (Categorias) y cada HEX es de una sola
--   categoria: uk_cat_color lo garantiza en la base, ademas del aviso del API.
--   NULL = sin elegir: el POS sigue con el color calculado.
--
-- QUE HACE:
--   1) Agrega la columna color CHAR(7) NULL ('#RRGGBB', en mayusculas).
--   2) Agrega el indice UNICO uk_cat_color (los NULL no chocan entre si).
--   Cada paso solo si falta. No toca ningun valor.
--
-- COMO CORRERLA (phpMyAdmin):
--   Entra a la base del tenant, pestana SQL, pega TODO el archivo y ejecuta.
--   La primera sentencia guarda la base seleccionada en @db. Para no depender
--   de la base seleccionada, cambia esa linea por el nombre:
--     SET @db := 'smhynzte_new_gratexdb';
--   El resultado (la ultima consulta) es una fila: color, char(7), YES, uk_cat_color.
--
-- ORDEN: va ANTES de desplegar el API que guarda el color. Con el API nuevo y
-- sin la columna, guardar una categoria falla; el catalogo del POS sigue
-- funcionando (lee la categoria con SELECT * y sin color usa el calculado).
--
-- Se puede correr dos veces: si la columna o el indice ya estan, el paso ejecuta DO 0.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 0) La base del tenant: la que esta seleccionada.
-- ----------------------------------------------------------------------------
SET @db := DATABASE();

-- Guardia: la base seleccionada tiene que ser la correcta. Si no lo es, esta
-- sentencia falla con un nombre de tabla que dice que hacer, ANTES de tocar nada.
SET @guardia := IF(@db IS NULL OR @db IN ('information_schema', 'mysql', 'performance_schema', 'sys'),
  'DO (SELECT 1 FROM `ALTO_elige_la_base_de_la_empresa_en_el_panel`.`x` LIMIT 1)',
  CONCAT('DO (SELECT 1 FROM `', @db, '`.categories LIMIT 1)'));
PREPARE s_guardia FROM @guardia;
EXECUTE s_guardia;
DEALLOCATE PREPARE s_guardia;

-- ----------------------------------------------------------------------------
-- 1) La columna, solo si falta.
-- ----------------------------------------------------------------------------
SET @falta_034a := (
  SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'categories' AND COLUMN_NAME = 'color'
);
SET @sql_034a := IF(@falta_034a,
  CONCAT('ALTER TABLE `', @db, '`.categories ',
    'ADD COLUMN color CHAR(7) NULL DEFAULT NULL ',
    'COMMENT ''Color en el POS, #RRGGBB en mayusculas; unico por categoria. NULL = calculado del nombre'' ',
    'AFTER descripcion'),
  'DO 0');
PREPARE s_034a FROM @sql_034a;
EXECUTE s_034a;
DEALLOCATE PREPARE s_034a;

-- ----------------------------------------------------------------------------
-- 2) El indice unico, solo si falta.
-- ----------------------------------------------------------------------------
SET @falta_034b := (
  SELECT COUNT(*) = 0 FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'categories' AND INDEX_NAME = 'uk_cat_color'
);
SET @sql_034b := IF(@falta_034b,
  CONCAT('ALTER TABLE `', @db, '`.categories ADD UNIQUE KEY uk_cat_color (color)'),
  'DO 0');
PREPARE s_034b FROM @sql_034b;
EXECUTE s_034b;
DEALLOCATE PREPARE s_034b;

-- ----------------------------------------------------------------------------
-- Resultado (la ultima consulta, la que muestra phpMyAdmin).
-- ----------------------------------------------------------------------------
SELECT @db AS base, c.COLUMN_NAME, c.COLUMN_TYPE, c.IS_NULLABLE,
       (SELECT MAX(s.INDEX_NAME) FROM information_schema.STATISTICS s
        WHERE s.TABLE_SCHEMA = @db AND s.TABLE_NAME = 'categories' AND s.INDEX_NAME = 'uk_cat_color') AS indice
FROM information_schema.COLUMNS c
WHERE c.TABLE_SCHEMA = @db AND c.TABLE_NAME = 'categories' AND c.COLUMN_NAME = 'color';
