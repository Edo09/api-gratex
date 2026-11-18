-- ============================================================================
-- 038_cobros.sql — Cobros (cuentas por cobrar por cliente): tablas cobro_config,
-- cobros, cobro_pagos y cobro_recordatorios.
-- ============================================================================
-- Para DBs de tenant YA desplegados: correr en la base de CADA empresa (la de
-- Gratex tambien, aunque no use Cobros: asi todas las bases quedan iguales).
-- Los tenants nuevos lo reciben via db/tenant_schema.sql (mismas columnas y
-- mismos nombres de indices, FKs y CHECK).
--
-- POR QUE EL 038 (y no el 036 ni el 037): el 036 esta reservado para
-- Fiscalpoint Express (036_facturas_idempotency.sql) y el 037 para el plan ITBIS
-- de facturas simples (ver db/migrations/README.md). Esta es la siguiente libre.
--
-- POR QUE:
--   Fiscalpoint necesita llevar lo que cada cliente debe: un cobro mensual por
--   cliente (monto y dia de vencimiento propios), pagos completos o parciales
--   (efectivo o transferencia) y los recordatorios que se hicieron a mano.
--   Los clientes son los de siempre (tabla clients): no hay modulo de clientes
--   nuevo, solo cobro_config, que cuelga de clients.
--   Nada del historial se sobrescribe: cambiar el monto mensual de un cliente
--   no toca los cobros ya generados (cada cobro guarda su propio monto), un pago
--   nunca se edita ni se borra (se ANULA y queda a la vista) y un cobro nunca se
--   borra (se CANCELA). El estado (Pendiente, Pagado, Vencido, Parcial,
--   Cancelado) NO se guarda: se deriva del saldo, el vencimiento y la fecha de
--   hoy, porque un estado guardado caducaria solo.
--
-- QUE HACE (mira information_schema; solo CREA tablas, no cambia ni borra nada
-- de lo que ya existe):
--   1) Guarda en variables el motor de clients (debe ser InnoDB: MyISAM ignora
--      las FK en silencio) y el tipo exacto de clients.id. Ese tipo se COPIA a
--      cobro_config.client_id y cobros.client_id, signo incluido: la FK no se
--      crea si los tipos difieren, y la DDL de produccion puede no ser la del repo.
--   2) Crea, si faltan, las cuatro tablas. Si clients no es InnoDB o su id no se
--      puede leer, NO crea ninguna y el resultado final lo muestra con
--      todo_ok = NO. Si falta clients, la guardia de la segunda sentencia se
--      detiene antes, con el #1146 de abajo, y no se hace nada.
--   Reglas ON DELETE: cobro_config -> clients es CASCADE (la configuracion no es
--   historial: sin cobros, borrar el cliente la borra); cobros -> clients es
--   RESTRICT (un cliente con cobros NO se puede borrar: el historial se
--   conserva; clientModel lo explica en pantalla); cobro_pagos y
--   cobro_recordatorios -> cobros son RESTRICT; cobros.generado_de_cobro_id ->
--   cobros es SET NULL (solo una traza de que cobro genero a cual).
--   Restriccion unica uk_cobros_cliente_periodo (client_id, periodo): ni el
--   usuario ni una repeticion de la peticion pueden crear dos cobros del mismo
--   cliente para el mismo mes. uk_cobro_pagos_idem (idempotency_key): repetir la
--   misma peticion de pago no registra dos pagos.
--   Montos DECIMAL(12,2). CHECK (MySQL 8.0.16 o mas nuevo, como produccion; uno
--   mas viejo los ignora sin error): monto > 0 y 0 <= monto_pagado <= monto.
--
-- COMO CORRERLA:
--   phpMyAdmin: entra a la base del tenant, pestana SQL, pega TODO el archivo y
--   ejecuta. mysql.exe: mysql.exe --defaults-extra-file=<credenciales> <base> < este_archivo
--   La primera sentencia guarda la base seleccionada en @db y todo lo demas la
--   nombra explicitamente (la regla de db/migrations/README.md: despues de un
--   SELECT sobre information_schema, phpMyAdmin cambia la base actual; ver la
--   028). Si prefieres no depender de la base seleccionada, cambia esa primera
--   linea por el nombre:  SET @db := 'smhynzte_003';
--   El resultado es una sola fila (la ultima consulta):
--     - base = la base del tenant;
--     - motor_clients = InnoDB y tipo_clients_id = el tipo de clients.id (int en
--       MySQL 8.0.19 o mas nuevo, como produccion; int(11) en uno mas viejo);
--     - cobro_config, cobros, cobro_pagos y cobro_recordatorios = InnoDB (NULL =
--       la tabla no existe);
--     - indices = 8, fks = 5 y checks = 5 en MySQL 8.0.16+ (checks = 0 en uno mas viejo);
--     - todo_ok = SI.
--   Si motor_clients no sale InnoDB, o un tipo sale NULL, no se creo ninguna
--   tabla y todo_ok = NO: para y avisa. Si base no es la del tenant (por ejemplo
--   information_schema, o NULL si no habia una base seleccionada), no se creo
--   nada: selecciona la base correcta y vuelve a correrla. (La guardia de la
--   segunda sentencia ya detiene esos dos casos con el #1049 de abajo; este
--   resultado queda como la segunda red.)
--
-- SI SALE "#1049 Unknown database 'ALTO_elige_la_base_...'" (o "#1109 Unknown table
-- '...' in information_schema"): phpMyAdmin estaba parado en otra base (pasa
-- despues de correr otra migracion: cualquier consulta a information_schema lo
-- deja ahi). NO se cambio nada. Haz clic en el nombre de la base correcta en el
-- panel IZQUIERDO, abre la pestana SQL de nuevo, pega todo y ejecuta.
-- "#1146 Table '...clients' doesn't exist": la base seleccionada no es la de una
-- empresa (no tiene clientes). Tampoco se cambio nada; elige la correcta.
--
-- ANTES DE CORRER:
--   - Toma un respaldo de la base.
--   - Hazlo fuera de horario: crear la FK a clients toma por un instante un
--     bloqueo de metadatos sobre clients. Las tablas nuevas estan vacias y es
--     cuestion de segundos, pero una consulta larga sobre clients la haria
--     esperar (y las escrituras que lleguen detras, tambien).
--
-- ORDEN: los verificadores primero (tools/verificar_migraciones_tenant.sql en
-- cada base de tenant); ESTA migracion en las bases de TODAS las empresas y
-- ANTES de desplegar el codigo de Cobros (api-gratex y despues fiscalo), con el
-- verificador otra vez en APLICADA. Si el permiso 'cobros' ha de llegar a los
-- usuarios que ya existen: db/master_migrations/020_cobros_permiso.sql en la
-- base MASTER (aparte, una sola vez). Solo crea tablas, asi que es segura con el
-- codigo que corre hoy en produccion. Se puede correr dos veces: si las tablas ya
-- estan, cada paso ejecuta DO 0 y no cambia nada.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 0) La base del tenant: la que esta seleccionada.
-- ----------------------------------------------------------------------------
SET @db := DATABASE();

-- Guardia: la base seleccionada tiene que ser la correcta. Si no lo es, esta
-- sentencia falla con un nombre de tabla que dice que hacer, ANTES de tocar
-- nada. No consulta information_schema: phpMyAdmin no cambia de base aqui.
SET @guardia := IF(@db IS NULL OR @db IN ('information_schema', 'mysql', 'performance_schema', 'sys'),
  'DO (SELECT 1 FROM `ALTO_elige_la_base_de_la_empresa_en_el_panel`.`x` LIMIT 1)',
  CONCAT('DO (SELECT 1 FROM `', @db, '`.clients LIMIT 1)'));
PREPARE s_guardia FROM @guardia;
EXECUTE s_guardia;
DEALLOCATE PREPARE s_guardia;

-- ----------------------------------------------------------------------------
-- 1) Lo que hay hoy (solo lectura).
-- ----------------------------------------------------------------------------
-- 1a) Tipo exacto de clients.id (signo incluido). NULL = falta: no se crea nada.
SET @tipo_client_id := (
  SELECT COLUMN_TYPE FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'clients'
    AND COLUMN_NAME = 'id'
);

-- 1b) Motor: clients debe ser InnoDB (1 de 1).
SET @motor_innodb := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'clients'
    AND ENGINE = 'InnoDB'
);

-- 1c) Tablas nuevas que ya existen (1) o faltan (0).
SET @has_cobro_config := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'cobro_config'
);
SET @has_cobros := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'cobros'
);
SET @has_cobro_pagos := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'cobro_pagos'
);
SET @has_cobro_recordatorios := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'cobro_recordatorios'
);

-- ----------------------------------------------------------------------------
-- 2) Las cuatro tablas. Cada una se crea solo si falta y si clients es InnoDB
--    con su id legible. Todo nombre de tabla lleva la base (`', @db, '`.tabla),
--    tambien en las REFERENCES.
-- ----------------------------------------------------------------------------
SET @listo := (@motor_innodb = 1 AND @tipo_client_id IS NOT NULL);

-- 2a) cobro_config: la configuracion de cobro mensual de cada cliente. PK =
--     client_id: un solo registro por cliente. Con activo = 1, al pagarse un
--     cobro del cliente se genera el del mes siguiente con este monto.
SET @crear_cobro_config := (@listo = 1 AND @has_cobro_config = 0);
SET @sql_cobro_config := IF(@crear_cobro_config = 1,
  CONCAT(
    'CREATE TABLE IF NOT EXISTS `', @db, '`.cobro_config (',
      'client_id ', @tipo_client_id, ' NOT NULL, ',
      'monto_mensual DECIMAL(12,2) NOT NULL COMMENT ''Monto del cobro de cada mes (DOP)'', ',
      'dia_vencimiento TINYINT UNSIGNED NOT NULL COMMENT ''Dia del mes en que vence (1-31); en un mes mas corto, el ultimo dia'', ',
      'descripcion VARCHAR(150) NULL COMMENT ''Concepto de cada cobro mensual'', ',
      'activo TINYINT(1) NOT NULL DEFAULT 1 COMMENT ''1 = al pagarse un cobro se genera el del mes siguiente'', ',
      'user_id INT(11) NULL COMMENT ''master users.id del ultimo cambio (sin FK cross-DB)'', ',
      'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, ',
      'updated_at DATETIME NULL DEFAULT NULL, ',
      'PRIMARY KEY (client_id), ',
      'CONSTRAINT cobro_config_client_fk FOREIGN KEY (client_id) ',
        'REFERENCES `', @db, '`.clients (id) ON DELETE CASCADE, ',
      'CONSTRAINT cobro_config_monto_ck CHECK (monto_mensual > 0), ',
      'CONSTRAINT cobro_config_dia_ck CHECK (dia_vencimiento BETWEEN 1 AND 31)',
    ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'),
  'DO 0');
PREPARE s_038_config FROM @sql_cobro_config;
EXECUTE s_038_config;
DEALLOCATE PREPARE s_038_config;

-- 2b) cobros: un cobro por cliente y mes. monto es del propio cobro (cambiar el
--     monto mensual del cliente no lo toca). monto_pagado es una cache: el
--     modelo la recalcula SIEMPRE desde los pagos vigentes, en la misma
--     transaccion que cada pago y con la fila bloqueada. cancelado_at NULL =
--     activo. El indice (fecha_vencimiento) sirve a los listados por fecha y al
--     calculo de lo vencido; client_id lo cubre uk_cobros_cliente_periodo.
SET @crear_cobros := (@listo = 1 AND @has_cobros = 0);
SET @sql_cobros := IF(@crear_cobros = 1,
  CONCAT(
    'CREATE TABLE IF NOT EXISTS `', @db, '`.cobros (',
      'id INT(11) NOT NULL AUTO_INCREMENT, ',
      'client_id ', @tipo_client_id, ' NOT NULL, ',
      'periodo CHAR(7) NOT NULL COMMENT ''Mes del cobro, AAAA-MM'', ',
      'descripcion VARCHAR(150) NULL, ',
      'monto DECIMAL(12,2) NOT NULL COMMENT ''Monto original de ESTE cobro (DOP)'', ',
      'monto_pagado DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT ''Suma de los pagos vigentes (cache recalculada en cada pago)'', ',
      'fecha_vencimiento DATE NOT NULL, ',
      'cancelado_at DATETIME NULL DEFAULT NULL COMMENT ''NULL = activo; un cobro nunca se borra'', ',
      'motivo_cancelacion VARCHAR(255) NULL, ',
      'generado_de_cobro_id INT(11) NULL COMMENT ''Cobro cuyo pago genero este (NULL = creado a mano)'', ',
      'user_id INT(11) NULL COMMENT ''master users.id que lo creo o lo genero con su pago (sin FK cross-DB)'', ',
      'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, ',
      'updated_at DATETIME NULL DEFAULT NULL, ',
      'PRIMARY KEY (id), ',
      'UNIQUE KEY uk_cobros_cliente_periodo (client_id, periodo), ',
      'KEY idx_cobros_vencimiento (fecha_vencimiento), ',
      'KEY idx_cobros_origen (generado_de_cobro_id), ',
      'CONSTRAINT cobros_client_fk FOREIGN KEY (client_id) ',
        'REFERENCES `', @db, '`.clients (id) ON DELETE RESTRICT, ',
      'CONSTRAINT cobros_origen_fk FOREIGN KEY (generado_de_cobro_id) ',
        'REFERENCES `', @db, '`.cobros (id) ON DELETE SET NULL, ',
      'CONSTRAINT cobros_monto_ck CHECK (monto > 0), ',
      'CONSTRAINT cobros_pagado_ck CHECK (monto_pagado >= 0 AND monto_pagado <= monto)',
    ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'),
  'DO 0');
PREPARE s_038_cobros FROM @sql_cobros;
EXECUTE s_038_cobros;
DEALLOCATE PREPARE s_038_cobros;

-- 2c) cobro_pagos: los pagos de un cobro (varios, completos o parciales). Un
--     pago no se edita ni se borra: se ANULA (anulado_at) y sigue a la vista. La
--     clave idempotency_key (un UUID que genera el formulario) hace que repetir
--     la misma peticion no registre dos pagos. Solo se crea si cobros existe
--     (existia o se acaba de crear).
SET @hay_cobros := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'cobros'
);
SET @crear_cobro_pagos := (@listo = 1 AND @hay_cobros = 1 AND @has_cobro_pagos = 0);
SET @sql_cobro_pagos := IF(@crear_cobro_pagos = 1,
  CONCAT(
    'CREATE TABLE IF NOT EXISTS `', @db, '`.cobro_pagos (',
      'id INT(11) NOT NULL AUTO_INCREMENT, ',
      'cobro_id INT(11) NOT NULL, ',
      'monto DECIMAL(12,2) NOT NULL COMMENT ''Monto del pago (DOP)'', ',
      'fecha_pago DATE NOT NULL, ',
      'metodo ENUM(''efectivo'',''transferencia'') NOT NULL, ',
      'observaciones VARCHAR(255) NULL, ',
      'idempotency_key CHAR(36) NULL COMMENT ''UUID del formulario: repetir la peticion no duplica el pago'', ',
      'anulado_at DATETIME NULL DEFAULT NULL COMMENT ''NULL = vigente; un pago nunca se borra'', ',
      'motivo_anulacion VARCHAR(255) NULL, ',
      'user_id INT(11) NULL COMMENT ''master users.id que lo registro (sin FK cross-DB)'', ',
      'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, ',
      'PRIMARY KEY (id), ',
      'UNIQUE KEY uk_cobro_pagos_idem (idempotency_key), ',
      'KEY idx_cobro_pagos_cobro (cobro_id, anulado_at), ',
      'KEY idx_cobro_pagos_fecha (fecha_pago), ',
      'CONSTRAINT cobro_pagos_cobro_fk FOREIGN KEY (cobro_id) ',
        'REFERENCES `', @db, '`.cobros (id) ON DELETE RESTRICT, ',
      'CONSTRAINT cobro_pagos_monto_ck CHECK (monto > 0)',
    ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'),
  'DO 0');
PREPARE s_038_pagos FROM @sql_cobro_pagos;
EXECUTE s_038_pagos;
DEALLOCATE PREPARE s_038_pagos;

-- 2d) cobro_recordatorios: los recordatorios que alguien hizo a mano (llamada,
--     mensaje, visita...). Son solo un registro: la app no envia nada. fecha_hora
--     es cuando se hizo (lo escribe la persona, hora de RD).
SET @crear_cobro_recordatorios := (@listo = 1 AND @hay_cobros = 1 AND @has_cobro_recordatorios = 0);
SET @sql_cobro_recordatorios := IF(@crear_cobro_recordatorios = 1,
  CONCAT(
    'CREATE TABLE IF NOT EXISTS `', @db, '`.cobro_recordatorios (',
      'id INT(11) NOT NULL AUTO_INCREMENT, ',
      'cobro_id INT(11) NOT NULL, ',
      'fecha_hora DATETIME NOT NULL COMMENT ''Cuando se hizo el recordatorio'', ',
      'canal VARCHAR(15) NOT NULL DEFAULT ''otro'' COMMENT ''llamada | mensaje | correo | visita | otro'', ',
      'nota VARCHAR(255) NULL, ',
      'user_id INT(11) NULL COMMENT ''master users.id que lo registro (sin FK cross-DB)'', ',
      'user_name VARCHAR(100) NULL COMMENT ''Nombre guardado de quien lo registro'', ',
      'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, ',
      'PRIMARY KEY (id), ',
      'KEY idx_cobro_recordatorios_cobro (cobro_id, fecha_hora), ',
      'CONSTRAINT cobro_recordatorios_cobro_fk FOREIGN KEY (cobro_id) ',
        'REFERENCES `', @db, '`.cobros (id) ON DELETE RESTRICT',
    ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'),
  'DO 0');
PREPARE s_038_recordatorios FROM @sql_cobro_recordatorios;
EXECUTE s_038_recordatorios;
DEALLOCATE PREPARE s_038_recordatorios;

-- ----------------------------------------------------------------------------
-- Resultado (la ultima consulta, la que muestra phpMyAdmin): una sola fila.
-- Que debe decir cada columna: ver COMO CORRERLA arriba. 'checks' cuenta los
-- CHECK que la base conoce: 5 en MySQL 8.0.16+, 0 en uno mas viejo (que los
-- ignora); por eso todo_ok acepta las dos cifras.
-- ----------------------------------------------------------------------------
SELECT r.*,
       IF(r.motor_clients = 'InnoDB'
          AND r.cobro_config = 'InnoDB' AND r.cobros = 'InnoDB'
          AND r.cobro_pagos = 'InnoDB' AND r.cobro_recordatorios = 'InnoDB'
          AND r.indices = 8 AND r.fks = 5 AND r.checks IN (0, 5), 'SI', 'NO') AS todo_ok
FROM (
  SELECT @db AS base,
         (SELECT ENGINE FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clients') AS motor_clients,
         @tipo_client_id AS tipo_clients_id,
         (SELECT ENGINE FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'cobro_config') AS cobro_config,
         (SELECT ENGINE FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'cobros') AS cobros,
         (SELECT ENGINE FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'cobro_pagos') AS cobro_pagos,
         (SELECT ENGINE FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'cobro_recordatorios') AS cobro_recordatorios,
         (SELECT COUNT(DISTINCT TABLE_NAME, INDEX_NAME) FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = @db
            AND ((TABLE_NAME = 'cobros' AND INDEX_NAME IN ('uk_cobros_cliente_periodo', 'idx_cobros_vencimiento', 'idx_cobros_origen'))
              OR (TABLE_NAME = 'cobro_pagos' AND INDEX_NAME IN ('uk_cobro_pagos_idem', 'idx_cobro_pagos_cobro', 'idx_cobro_pagos_fecha'))
              OR (TABLE_NAME = 'cobro_recordatorios' AND INDEX_NAME = 'idx_cobro_recordatorios_cobro')
              OR (TABLE_NAME = 'cobro_config' AND INDEX_NAME = 'PRIMARY'))) AS indices,
         (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
          WHERE CONSTRAINT_SCHEMA = @db
            AND CONSTRAINT_NAME IN ('cobro_config_client_fk', 'cobros_client_fk', 'cobros_origen_fk',
                                    'cobro_pagos_cobro_fk', 'cobro_recordatorios_cobro_fk')) AS fks,
         (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
          WHERE CONSTRAINT_SCHEMA = @db AND CONSTRAINT_TYPE = 'CHECK'
            AND CONSTRAINT_NAME IN ('cobro_config_monto_ck', 'cobro_config_dia_ck', 'cobros_monto_ck',
                                    'cobros_pagado_ck', 'cobro_pagos_monto_ck')) AS checks
) r;
