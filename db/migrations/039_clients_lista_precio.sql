-- ============================================================================
-- 039_clients_lista_precio.sql — clients.lista_precio: la lista de precio por
-- defecto del cliente (1 a 4), para no preguntar cual precio usar cada vez.
-- ============================================================================
-- Para DBs de tenant YA desplegados: correr en la base de CADA empresa. Los
-- tenants nuevos lo reciben via db/tenant_schema.sql (mismo tipo y COMMENT).
--
-- POR QUE:
--   Cada producto tiene hasta cuatro precios, las listas 1 a 4 (precio y
--   precio_2 a precio_4: 019). Quien factura, cotiza o vende en el POS elige la
--   lista cada vez que agrega un producto que ofrece mas de una. Un cliente que
--   siempre compra a la misma lista (el mayorista, por ejemplo) la lleva guardada:
--   asi la pantalla ya sabe con cual precio agregarle el producto.
--   NULL = el cliente no tiene lista por defecto y se sigue preguntando cada vez.
--   La lista del cliente es solo un valor por defecto de la pantalla, nunca una
--   restriccion: el precio de cada linea sigue dependiendo de la lista de esa
--   linea y de los precios del producto.
--
-- QUE HACE:
--   Agrega la columna lista_precio TINYINT NULL, despues de permitir_credito,
--   solo si falta. No toca ningun valor: todos los clientes quedan en NULL. Que
--   sea 1, 2, 3 o 4 lo valida el API; la columna no lleva CHECK.
--
-- COMO CORRERLA (phpMyAdmin):
--   Entra a la base del tenant, pestana SQL, pega TODO el archivo y ejecuta.
--   La primera sentencia guarda la base seleccionada en @db. Para no depender
--   de la base seleccionada, cambia esa linea por el nombre:
--     SET @db := 'smhynzte_new_gratexdb';
--   El resultado (la ultima consulta) es una fila: base, lista_precio, tinyint
--   (o tinyint(4), segun la version del servidor) y YES.
--
-- ORDEN: va ANTES de desplegar el API que guarda la lista. Un API nuevo sin la
-- columna sigue funcionando (lee y edita clientes sin tocar la lista, y el
-- cliente del POS sale con lista_precio null) hasta que alguien le pone una
-- lista a un cliente: solo entonces falla, porque el API escribe lista_precio
-- unicamente cuando la peticion trae esa clave.
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
  CONCAT('DO (SELECT 1 FROM `', @db, '`.clients LIMIT 1)'));
PREPARE s_guardia FROM @guardia;
EXECUTE s_guardia;
DEALLOCATE PREPARE s_guardia;

-- ----------------------------------------------------------------------------
-- 1) La columna, solo si falta.
-- ----------------------------------------------------------------------------
SET @falta_039 := (
  SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clients' AND COLUMN_NAME = 'lista_precio'
);
SET @sql_039 := IF(@falta_039,
  CONCAT('ALTER TABLE `', @db, '`.clients ',
    'ADD COLUMN lista_precio TINYINT NULL DEFAULT NULL ',
    'COMMENT ''Lista de precio por defecto del cliente (1-4); NULL = preguntar cada vez'' ',
    'AFTER permitir_credito'),
  'DO 0');
PREPARE s_039 FROM @sql_039;
EXECUTE s_039;
DEALLOCATE PREPARE s_039;

-- ----------------------------------------------------------------------------
-- Resultado (la ultima consulta, la que muestra phpMyAdmin).
-- ----------------------------------------------------------------------------
SELECT @db AS base, c.COLUMN_NAME, c.COLUMN_TYPE, c.IS_NULLABLE
FROM information_schema.COLUMNS c
WHERE c.TABLE_SCHEMA = @db AND c.TABLE_NAME = 'clients' AND c.COLUMN_NAME = 'lista_precio';
