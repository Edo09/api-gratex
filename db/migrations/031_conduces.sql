-- ============================================================================
-- 031_conduces.sql — Conduces de mercancia (formato ferreteria): tablas
-- conduces, conduce_items y conduce_secuencia.
-- ============================================================================
-- Para DBs de tenant YA desplegados: correr en la base de CADA empresa (la de
-- Gratex tambien, aunque no use conduces: asi todas las bases quedan iguales).
-- Los tenants nuevos lo reciben via db/tenant_schema.sql (mismas columnas y
-- mismos nombres de indices y FKs).
--
-- POR QUE:
--   Ferreteria necesita un conduce de mercancia: la nota de entrega que va con
--   la mercancia y que el cliente firma ("Recibido por"). Sale de una
--   cotizacion, se puede editar, lleva su propio numero (CON-000001) y no
--   imprime precios; cada linea guarda por dentro su precio y su ITBIS para
--   facturar despues. Las tablas de cotizaciones no se tocan.
--   Ninguna fila se borra: Eliminar pone conduces.activo = 0 y editar pone
--   activo = 0 a las lineas anteriores. Asi un numero nunca se vuelve a usar:
--   conduce_secuencia guarda el ultimo numero dado y el UNIQUE de
--   conduces.numero es la red de seguridad.
--
-- QUE HACE (mira information_schema; solo CREA tablas y una fila, no cambia
-- ni borra nada de lo que ya existe):
--   1) Guarda en variables el motor de cotizaciones y products (las dos deben
--      ser InnoDB: MyISAM ignora las FK en silencio) y el tipo exacto de
--      cotizaciones.id y products.id. Ese tipo se COPIA a
--      conduces.cotizacion_id y conduce_items.product_id, signo incluido: la
--      FK no se crea si los tipos difieren, y la DDL de produccion puede no
--      ser la del repo.
--   2) Crea conduces, conduce_items y conduce_secuencia si faltan (las tres o
--      ninguna). Si falta cotizaciones, la guardia de la segunda sentencia se
--      detiene antes, con el #1146 de abajo, y no se hace nada. Si falta
--      products, o cotizaciones o products no son InnoDB, NO crea ninguna y
--      el resultado final lo muestra con todo_ok = NO.
--   3) Siembra la fila unica de conduce_secuencia (id 1, ultimo 0) con
--      INSERT IGNORE: si ya existe, no la toca.
--   Reglas ON DELETE: conduce_items -> conduces es RESTRICT (un conduce nunca
--   se borra y la base lo hace cumplir); conduces -> cotizaciones es SET NULL
--   (las cotizaciones se siguen borrando y su conduce queda como "cotizacion
--   eliminada"); conduce_items -> products es SET NULL, como factura_items.
--
-- COMO CORRERLA (phpMyAdmin):
--   Entra a la base del tenant, pestana SQL, pega TODO el archivo y ejecuta.
--   La primera sentencia guarda la base seleccionada en @db y todo lo demas la
--   nombra explicitamente (la regla de db/migrations/README.md: despues de un
--   SELECT sobre information_schema, phpMyAdmin cambia la base actual; ver la
--   028). Si prefieres no depender de la base seleccionada, cambia esa primera
--   linea por el nombre:  SET @db := 'smhynzte_002';
--   El resultado es una sola fila (la ultima consulta):
--     - base = la base del tenant;
--     - motor_cotizaciones y motor_products = InnoDB; tipo_cotizaciones_id y
--       tipo_products_id = el tipo de cada id (int en MySQL 8.0.19 o mas
--       nuevo, como produccion; int(11) en uno mas viejo);
--     - conduces, conduce_items y conduce_secuencia = InnoDB (NULL = la tabla
--       no existe);
--     - indices = 6;
--     - fk_conduces_cotizacion = SET NULL, fk_conduce_items_conduce = RESTRICT
--       y fk_conduce_items_product = SET NULL;
--     - fila_secuencia = (1, 0), o (1, N) si ya se crearon N conduces;
--     - todo_ok = SI.
--   Si motor_cotizaciones o motor_products no sale InnoDB, o un tipo sale
--   NULL, no se creo ninguna tabla y todo_ok = NO: para y avisa. Si base no
--   es la del tenant (por ejemplo information_schema, o NULL si no habia una
--   base seleccionada), no se creo nada: selecciona la base correcta y vuelve
--   a correrla. (Desde la guardia de la segunda sentencia, esos dos casos ya
--   se detienen antes con el #1049 de abajo; este resultado queda como la
--   segunda red.)
--
-- SI SALE "#1049 Unknown database 'ALTO_elige_la_base_...'" (o "#1109 Unknown table
-- '...' in information_schema"): phpMyAdmin estaba parado en otra base (pasa
-- despues de correr otra migracion: cualquier consulta a information_schema lo
-- deja ahi). NO se cambio nada. Haz clic en el nombre de la base correcta en el
-- panel IZQUIERDO, abre la pestana SQL de nuevo, pega todo y ejecuta.
-- "#1146 Table '...cotizaciones' doesn't exist": la base seleccionada no es la
-- de una empresa (no tiene cotizaciones). Tampoco se cambio nada; elige la
-- correcta.
--
-- ANTES DE CORRER:
--   - Toma un respaldo de la base.
--   - Hazlo fuera de horario: crear las FK toma por un instante bloqueos de
--     metadatos sobre cotizaciones y products. Las tablas nuevas estan vacias
--     y es cuestion de segundos, pero una consulta larga sobre cotizaciones o
--     products la haria esperar (y las escrituras que lleguen detras, tambien).
--
-- ORDEN: los verificadores primero (tools/verificar_migraciones_master.sql en
-- la base master y tools/verificar_migraciones_tenant.sql en el tenant);
-- db/master_migrations/012_pos.sql en la base MASTER (no en las de tenant)
-- donde su fila diga FALTA, antes de desplegar el codigo del POS, como pide su
-- encabezado; en el tenant, la 027, la 028, la 029 (precios_4_decimales) y la
-- 030 (pos) donde digan FALTA; DESPUES esta 031, y ANTES de desplegar el codigo
-- de conduces (api-gratex y despues fiscalo), con los verificadores otra vez,
-- la 012 del master y la 027 a la 031 del tenant en APLICADA. No depende de la
-- 027, la 028, la 029 ni la 030: 031 es solo el siguiente numero libre. (La 030
-- es la del POS: su encabezado la pide solo en las empresas que vayan a usar el
-- POS y dice que no estorba en las demas. Se corre igual en las dos DBs de
-- tenant: es inofensiva donde no se usa el POS, deja el verificador entero en
-- APLICADA y los esquemas iguales, y es obligatoria donde se vaya a usar; el
-- codigo del POS viaja en este mismo despliegue de api-gratex.)
-- Solo crea tablas, asi que es segura con el codigo que corre hoy en
-- produccion.
--
-- Se puede correr dos veces: si las tablas ya estan, cada paso ejecuta DO 0 y
-- la fila de conduce_secuencia no cambia.
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
  CONCAT('DO (SELECT 1 FROM `', @db, '`.cotizaciones LIMIT 1)'));
PREPARE s_guardia FROM @guardia;
EXECUTE s_guardia;
DEALLOCATE PREPARE s_guardia;

-- ----------------------------------------------------------------------------
-- 1) Lo que hay hoy (solo lectura).
-- ----------------------------------------------------------------------------
-- 1a) Tipo exacto de los id a los que apuntan las FK nuevas (signo incluido).
--     NULL = falta la tabla: entonces no se crea nada.
SET @tipo_cot_id := (
  SELECT COLUMN_TYPE FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'cotizaciones'
    AND COLUMN_NAME = 'id'
);
SET @tipo_product_id := (
  SELECT COLUMN_TYPE FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'products'
    AND COLUMN_NAME = 'id'
);

-- 1b) Motor: cotizaciones y products deben ser InnoDB (2 de 2).
SET @motores_innodb := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME IN ('cotizaciones', 'products')
    AND ENGINE = 'InnoDB'
);

-- 1c) Tablas nuevas que ya existen (1) o faltan (0).
SET @has_conduces := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'conduces'
);
SET @has_conduce_items := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'conduce_items'
);
SET @has_conduce_secuencia := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'conduce_secuencia'
);

-- ----------------------------------------------------------------------------
-- 2) Las tres tablas. Cada una se crea solo si falta y si cotizaciones y
--    products son InnoDB con su id legible: o se crean las tres, o ninguna.
--    Todo nombre de tabla lleva la base (`', @db, '`.tabla), tambien en las
--    REFERENCES.
-- ----------------------------------------------------------------------------
-- 2a) conduces: la cabecera. numero es el consecutivo (CON-000001) y su UNIQUE
--     es la red de seguridad de conduce_secuencia. cotizacion_id copia el tipo
--     de cotizaciones.id; client_id no lleva FK (el cliente se puede borrar y
--     client_name guarda su nombre).
SET @crear_conduces := (@has_conduces = 0 AND @motores_innodb = 2
                        AND @tipo_cot_id IS NOT NULL AND @tipo_product_id IS NOT NULL);
SET @sql_conduces := IF(@crear_conduces = 1,
  CONCAT(
    'CREATE TABLE IF NOT EXISTS `', @db, '`.conduces (',
      'id INT(11) NOT NULL AUTO_INCREMENT, ',
      'numero INT UNSIGNED NOT NULL, ',
      'code VARCHAR(20) NOT NULL COMMENT ''CON-000001'', ',
      'date DATETIME NOT NULL, ',
      'cotizacion_id ', @tipo_cot_id, ' NULL COMMENT ''Cotizacion de origen; NULL si se elimino la cotizacion'', ',
      'client_id INT(11) NULL, ',
      'client_name VARCHAR(100) NULL COMMENT ''Nombre guardado (el cliente se puede borrar)'', ',
      'user_id INT(11) NULL COMMENT ''master users.id (sin FK cross-DB)'', ',
      'activo TINYINT(1) NOT NULL DEFAULT 1 COMMENT ''0 = eliminado (nunca se borra la fila)'', ',
      'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, ',
      'updated_at DATETIME NULL DEFAULT NULL, ',
      'PRIMARY KEY (id), ',
      'UNIQUE KEY uk_conduces_numero (numero), ',
      'KEY idx_conduces_cotizacion (cotizacion_id), ',
      'KEY idx_conduces_date (date), ',
      'KEY idx_conduces_activo (activo), ',
      'CONSTRAINT conduces_cotizacion_fk FOREIGN KEY (cotizacion_id) ',
        'REFERENCES `', @db, '`.cotizaciones (id) ON DELETE SET NULL',
    ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'),
  'DO 0');
PREPARE s_031_conduces FROM @sql_conduces;
EXECUTE s_031_conduces;
DEALLOCATE PREPARE s_031_conduces;

-- 2b) conduce_items: las lineas. amount y los dos indicadores son internos
--     (para Facturar; el conduce nunca los imprime). activo = 0: linea
--     reemplazada por una edicion. product_id copia el tipo de products.id.
--     El indice (conduce_id, activo) sirve tambien a la FK de conduce_id, asi
--     que MySQL no crea otro.
SET @crear_conduce_items := (@has_conduce_items = 0 AND @motores_innodb = 2
                             AND @tipo_cot_id IS NOT NULL AND @tipo_product_id IS NOT NULL);
SET @sql_conduce_items := IF(@crear_conduce_items = 1,
  CONCAT(
    'CREATE TABLE IF NOT EXISTS `', @db, '`.conduce_items (',
      'id INT(11) NOT NULL AUTO_INCREMENT, ',
      'conduce_id INT(11) NOT NULL, ',
      'product_id ', @tipo_product_id, ' NULL COMMENT ''NULL = linea libre'', ',
      'description TEXT NOT NULL, ',
      'quantity DECIMAL(12,3) NOT NULL DEFAULT 1.000, ',
      'unidad_medida VARCHAR(10) NOT NULL DEFAULT ''43'', ',
      'amount DECIMAL(18,4) NOT NULL DEFAULT 0.0000 COMMENT ''Precio interno (sin ITBIS) para facturar; nunca se imprime'', ',
      'indicador_facturacion TINYINT NOT NULL DEFAULT 1, ',
      'indicador_bien_servicio TINYINT NOT NULL DEFAULT 1, ',
      'activo TINYINT(1) NOT NULL DEFAULT 1 COMMENT ''0 = linea reemplazada por una edicion'', ',
      'PRIMARY KEY (id), ',
      'KEY idx_conduce_items_conduce (conduce_id, activo), ',
      'KEY idx_conduce_items_product (product_id), ',
      'CONSTRAINT conduce_items_conduce_fk FOREIGN KEY (conduce_id) ',
        'REFERENCES `', @db, '`.conduces (id) ON DELETE RESTRICT, ',
      'CONSTRAINT conduce_items_product_fk FOREIGN KEY (product_id) ',
        'REFERENCES `', @db, '`.products (id) ON DELETE SET NULL',
    ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'),
  'DO 0');
PREPARE s_031_items FROM @sql_conduce_items;
EXECUTE s_031_items;
DEALLOCATE PREPARE s_031_items;

-- 2c) conduce_secuencia: una sola fila (id = 1) con el ultimo numero dado. Al
--     crear un conduce, conduceModel la bloquea (SELECT ... FOR UPDATE): dos
--     conduces simultaneos nunca reciben el mismo numero.
SET @crear_conduce_secuencia := (@has_conduce_secuencia = 0 AND @motores_innodb = 2
                                 AND @tipo_cot_id IS NOT NULL AND @tipo_product_id IS NOT NULL);
SET @sql_conduce_secuencia := IF(@crear_conduce_secuencia = 1,
  CONCAT(
    'CREATE TABLE IF NOT EXISTS `', @db, '`.conduce_secuencia (',
      'id TINYINT NOT NULL COMMENT ''Siempre 1: una sola fila'', ',
      'ultimo INT UNSIGNED NOT NULL DEFAULT 0 COMMENT ''Ultimo numero de conduce asignado'', ',
      'PRIMARY KEY (id)',
    ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'),
  'DO 0');
PREPARE s_031_secuencia FROM @sql_conduce_secuencia;
EXECUTE s_031_secuencia;
DEALLOCATE PREPARE s_031_secuencia;

-- ----------------------------------------------------------------------------
-- 3) La fila de la secuencia, solo si la tabla existe ahora. INSERT IGNORE: si
--    ya esta (otra corrida, o ya se crearon conduces), no la toca.
-- ----------------------------------------------------------------------------
SET @hay_secuencia := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'conduce_secuencia'
);
SET @sql_semilla := IF(@hay_secuencia = 1,
  CONCAT('INSERT IGNORE INTO `', @db, '`.conduce_secuencia (id, ultimo) VALUES (1, 0)'),
  'DO 0');
PREPARE s_031_semilla FROM @sql_semilla;
EXECUTE s_031_semilla;
DEALLOCATE PREPARE s_031_semilla;

-- ----------------------------------------------------------------------------
-- 4) La fila de la secuencia para el resultado. Se lee con SQL armado porque la
--    tabla puede no existir (si no se creo nada); entonces queda NULL.
-- ----------------------------------------------------------------------------
SET @fila_secuencia := NULL;
SET @sql_fila_secuencia := IF(@hay_secuencia = 1,
  CONCAT('SELECT CONCAT(''('', id, '', '', ultimo, '')'') INTO @fila_secuencia FROM `', @db, '`.conduce_secuencia WHERE id = 1'),
  'DO 0');
PREPARE s_031_fila FROM @sql_fila_secuencia;
EXECUTE s_031_fila;
DEALLOCATE PREPARE s_031_fila;

-- ----------------------------------------------------------------------------
-- Resultado (la ultima consulta, la que muestra phpMyAdmin): una sola fila.
-- Que debe decir cada columna: ver COMO CORRERLA arriba.
-- ----------------------------------------------------------------------------
SELECT r.*,
       IF(r.motor_cotizaciones = 'InnoDB' AND r.motor_products = 'InnoDB'
          AND r.conduces = 'InnoDB' AND r.conduce_items = 'InnoDB'
          AND r.conduce_secuencia = 'InnoDB' AND r.indices = 6
          AND r.fk_conduces_cotizacion = 'SET NULL'
          AND r.fk_conduce_items_conduce = 'RESTRICT'
          AND r.fk_conduce_items_product = 'SET NULL'
          AND r.fila_secuencia IS NOT NULL, 'SI', 'NO') AS todo_ok
FROM (
  SELECT @db AS base,
         (SELECT ENGINE FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'cotizaciones') AS motor_cotizaciones,
         (SELECT ENGINE FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'products') AS motor_products,
         @tipo_cot_id AS tipo_cotizaciones_id,
         @tipo_product_id AS tipo_products_id,
         (SELECT ENGINE FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'conduces') AS conduces,
         (SELECT ENGINE FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'conduce_items') AS conduce_items,
         (SELECT ENGINE FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'conduce_secuencia') AS conduce_secuencia,
         (SELECT COUNT(DISTINCT TABLE_NAME, INDEX_NAME) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = @db
            AND TABLE_NAME IN ('conduces', 'conduce_items')
            AND INDEX_NAME IN ('uk_conduces_numero', 'idx_conduces_cotizacion', 'idx_conduces_date',
                               'idx_conduces_activo', 'idx_conduce_items_conduce', 'idx_conduce_items_product')) AS indices,
         (SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
          WHERE CONSTRAINT_SCHEMA = @db AND TABLE_NAME = 'conduces'
            AND CONSTRAINT_NAME = 'conduces_cotizacion_fk') AS fk_conduces_cotizacion,
         (SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
          WHERE CONSTRAINT_SCHEMA = @db AND TABLE_NAME = 'conduce_items'
            AND CONSTRAINT_NAME = 'conduce_items_conduce_fk') AS fk_conduce_items_conduce,
         (SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
          WHERE CONSTRAINT_SCHEMA = @db AND TABLE_NAME = 'conduce_items'
            AND CONSTRAINT_NAME = 'conduce_items_product_fk') AS fk_conduce_items_product,
         @fila_secuencia AS fila_secuencia
) r;
