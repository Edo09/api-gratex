-- ============================================================================
-- 032_producto_imagen.sql — products.imagen_path: la foto del producto.
-- ============================================================================
-- Para DBs de tenant YA desplegados: correr en la base de CADA empresa. Los
-- tenants nuevos lo reciben via db/tenant_schema.sql (mismo tipo y COMMENT).
--
-- POR QUE:
--   Una foto por producto, que se sube desde app.* (Productos y servicios) y se
--   ve en la lista de productos y en las tarjetas del POS. El archivo vive en el
--   servidor del API (public/uploads/productos/<tenant>/<aleatorio>.<ext>,
--   src/Utils/ProductImageStorage.php); la columna guarda esa ruta relativa.
--   NULL = sin foto (el POS muestra las iniciales).
--
-- QUE HACE:
--   Agrega la columna si no esta. No toca ningun valor ni otra columna.
--
-- COMO CORRERLA (phpMyAdmin):
--   Entra a la base del tenant, pestana SQL, pega TODO el archivo y ejecuta.
--   La primera sentencia guarda la base seleccionada en @db y todo lo demas la
--   nombra explicitamente (ver db/migrations/README.md). Para no depender de la
--   base seleccionada, cambia esa linea por el nombre:
--     SET @db := 'smhynzte_new_gratexdb';
--   El resultado (la ultima consulta) es una fila: imagen_path, varchar(255), YES.
--   "Table '...products' doesn't exist" = no era la base del tenant: no se
--   cambio nada; selecciona la correcta y vuelve a correrla.
--
-- SI SALE "#1049 Unknown database 'ALTO_elige_la_base_...'" (o "#1109 Unknown table
-- '...' in information_schema"): phpMyAdmin estaba parado en otra base (pasa
-- despues de correr otra migracion). NO se cambio nada. Haz clic en el nombre de
-- la base correcta en el panel IZQUIERDO, abre la pestana SQL de nuevo, pega
-- todo y ejecuta.
--
-- ORDEN: va ANTES de desplegar el API que sube fotos. Con el API nuevo y sin la
-- columna, subir una foto responde "no se pudo guardar" y el resto de productos
-- sigue funcionando (SELECT p.* no la exige). El front nuevo sin el API nuevo
-- solo no muestra fotos.
--
-- Se puede correr dos veces: si la columna ya esta, el paso ejecuta DO 0.
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
-- 1) La columna, solo si falta.
-- ----------------------------------------------------------------------------
SET @falta_032 := (
  SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'products' AND COLUMN_NAME = 'imagen_path'
);
SET @sql_032 := IF(@falta_032,
  CONCAT('ALTER TABLE `', @db, '`.products ',
    'ADD COLUMN imagen_path VARCHAR(255) NULL DEFAULT NULL ',
    'COMMENT ''Foto del producto: ruta relativa al API (public/uploads/productos/...). NULL = sin foto'' ',
    'AFTER stock_minimo'),
  'DO 0');
PREPARE s_032 FROM @sql_032;
EXECUTE s_032;
DEALLOCATE PREPARE s_032;

-- ----------------------------------------------------------------------------
-- Resultado (la ultima consulta, la que muestra phpMyAdmin).
-- ----------------------------------------------------------------------------
SELECT @db AS base, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_COMMENT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'products' AND COLUMN_NAME = 'imagen_path';
