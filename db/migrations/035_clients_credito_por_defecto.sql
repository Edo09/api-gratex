-- ============================================================================
-- 035_clients_credito_por_defecto.sql — clients.permitir_credito: facturar a
-- credito queda habilitado por defecto, para los clientes nuevos y para todos
-- los que ya existen.
-- ============================================================================
-- Para DBs de tenant YA desplegados: correr en la base de CADA empresa. Los
-- tenants nuevos lo reciben via db/tenant_schema.sql (mismo DEFAULT 1).
--
-- POR QUE:
--   Decision del dueno (2026-10-13): todos los clientes se pueden facturar a
--   credito salvo que alguien se lo quite. Quitarselo a uno se hace editando el
--   cliente (Clientes > Credito: "Permitir facturar a credito"). Antes era al
--   reves (020): DEFAULT 0 y habia que habilitarlo cliente por cliente.
--
-- QUE HACE (todo en un solo paso logico, solo si el DEFAULT todavia no es 1):
--   1) Respaldo: crea clients_credito_respaldo_035 y guarda ahi el id de cada
--      cliente que tenia permitir_credito = 0. Hoy no se puede saber a cuales se
--      les quito el credito a proposito y cuales lo tenian en 0 por defecto: el
--      respaldo permite revertir (abajo).
--   2) Pone permitir_credito = 1 a todos los clientes.
--   3) Cambia el DEFAULT de la columna a 1. Va al final: mientras no se aplique,
--      volver a correr el archivo repite 1) y 2) sin dano.
--   Con el DEFAULT ya en 1 no hace nada: correrla de nuevo NO vuelve a dar
--   credito a un cliente al que despues se le quito a mano.
--
-- REVERTIR (si hiciera falta), en la base del tenant:
--   UPDATE clients c JOIN clients_credito_respaldo_035 r ON r.client_id = c.id
--     SET c.permitir_credito = 0;
--   ALTER TABLE clients ALTER COLUMN permitir_credito SET DEFAULT 0;
--   Cuando ya no haga falta: DROP TABLE clients_credito_respaldo_035;
--
-- COMO CORRERLA (phpMyAdmin):
--   Entra a la base del tenant, pestana SQL, pega TODO el archivo y ejecuta.
--   La primera sentencia guarda la base seleccionada en @db. Para no depender
--   de la base seleccionada, cambia esa linea por el nombre:
--     SET @db := 'smhynzte_new_gratexdb';
--   El resultado (la ultima consulta) es una fila: base, default_credito = 1,
--   clientes, con_credito (= clientes) y respaldados (los que tenian 0).
--
-- ORDEN: independiente del despliegue. El API nuevo solo cambia el valor que
-- pone a un cliente creado sin el campo (0 -> 1), y el front nuevo marca la
-- casilla por defecto; ninguno depende de esta migracion.
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

-- ¿Falta aplicarla? Si: el DEFAULT de la columna todavia no es 1.
SET @pendiente_035 := (
  SELECT COUNT(*) > 0 FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clients' AND COLUMN_NAME = 'permitir_credito'
    AND (COLUMN_DEFAULT IS NULL OR TRIM(BOTH '''' FROM COLUMN_DEFAULT) <> '1')
);

-- ----------------------------------------------------------------------------
-- 1) Respaldo de los clientes sin credito.
-- ----------------------------------------------------------------------------
SET @sql_035a := IF(@pendiente_035,
  CONCAT('CREATE TABLE IF NOT EXISTS `', @db, '`.clients_credito_respaldo_035 (',
    'client_id INT(11) NOT NULL, ',
    'respaldado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, ',
    'PRIMARY KEY (client_id)',
    ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ',
    'COMMENT=''Clientes con permitir_credito = 0 antes de la migracion 035 (para revertir)'''),
  'DO 0');
PREPARE s_035a FROM @sql_035a;
EXECUTE s_035a;
DEALLOCATE PREPARE s_035a;

SET @sql_035b := IF(@pendiente_035,
  CONCAT('INSERT IGNORE INTO `', @db, '`.clients_credito_respaldo_035 (client_id) ',
    'SELECT id FROM `', @db, '`.clients WHERE permitir_credito <> 1'),
  'DO 0');
PREPARE s_035b FROM @sql_035b;
EXECUTE s_035b;
DEALLOCATE PREPARE s_035b;

-- ----------------------------------------------------------------------------
-- 2) Todos los clientes, con credito.
-- ----------------------------------------------------------------------------
SET @sql_035c := IF(@pendiente_035,
  CONCAT('UPDATE `', @db, '`.clients SET permitir_credito = 1 WHERE permitir_credito <> 1'),
  'DO 0');
PREPARE s_035c FROM @sql_035c;
EXECUTE s_035c;
DEALLOCATE PREPARE s_035c;

-- ----------------------------------------------------------------------------
-- 3) El DEFAULT de la columna, al final (ver arriba).
-- ----------------------------------------------------------------------------
SET @sql_035d := IF(@pendiente_035,
  CONCAT('ALTER TABLE `', @db, '`.clients ALTER COLUMN permitir_credito SET DEFAULT 1'),
  'DO 0');
PREPARE s_035d FROM @sql_035d;
EXECUTE s_035d;
DEALLOCATE PREPARE s_035d;

-- ----------------------------------------------------------------------------
-- Resultado (la ultima consulta, la que muestra phpMyAdmin). El respaldo puede
-- no existir (una base que ya nacio con DEFAULT 1): entonces sale NULL.
-- ----------------------------------------------------------------------------
SET @tiene_respaldo_035 := (
  SELECT COUNT(*) > 0 FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clients_credito_respaldo_035'
);
SET @sql_035r := CONCAT(
  'SELECT ''', @db, ''' AS base, ',
  '(SELECT TRIM(BOTH '''''''' FROM COLUMN_DEFAULT) FROM information_schema.COLUMNS ',
  ' WHERE TABLE_SCHEMA = ''', @db, ''' AND TABLE_NAME = ''clients'' AND COLUMN_NAME = ''permitir_credito'') AS default_credito, ',
  '(SELECT COUNT(*) FROM `', @db, '`.clients) AS clientes, ',
  '(SELECT COUNT(*) FROM `', @db, '`.clients WHERE permitir_credito = 1) AS con_credito, ',
  IF(@tiene_respaldo_035, CONCAT('(SELECT COUNT(*) FROM `', @db, '`.clients_credito_respaldo_035)'), 'NULL'),
  ' AS respaldados');
PREPARE s_035r FROM @sql_035r;
EXECUTE s_035r;
DEALLOCATE PREPARE s_035r;
