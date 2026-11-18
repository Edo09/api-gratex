-- =============================================================================
-- Master Migration 021: Cobros de la PLATAFORMA a sus tenants (las empresas que
-- usan Fiscalpoint): tablas cobro_config, cobros, cobro_pagos y
-- cobro_recordatorios en la base MASTER.
--
-- Ejecutar contra la base MASTER, UNA vez. Idempotente: se puede correr dos
-- veces sin error, y la segunda no toca nada. Solo CREA tablas.
-- Reflejado en db/master_schema.sql (instalaciones nuevas).
--
-- NOTA: el nombre de la master DB cambia por entorno (en el server:
-- smhynzte_master_gratex). Selecciona la base ANTES de correr esto: clic en su
-- nombre en el panel IZQUIERDO de phpMyAdmin (o pasa el nombre a mysql.exe).
--
-- SI SALE "#1049 Unknown database 'ALTO_elige_la_base_...'" (o "#1146 Table
-- '<base>.tenants' doesn't exist"): estabas parado en otra base. NO se cambio
-- nada. Elige la master, abre la pestana SQL de nuevo, pega todo y ejecuta.
--
-- POR QUE: el modulo Cobros de cada empresa (migracion de tenant 038,
-- docs/modules/cobros.md) lleva lo que SUS clientes le deben. La plataforma
-- tambien necesita cobrarle su mensualidad a SUS clientes, que son los tenants,
-- y los tenants no son clientes de ninguna empresa: viven en master.tenants. Son
-- las MISMAS cuatro tablas y el mismo modelo (cobroModel, ambito 'tenants'), pero
-- en la master y con tenants como clientes. Nada de esto vive en la base de una
-- empresa: ningun tenant ve lo que la plataforma le cobra a otro.
--   Nada del historial se sobrescribe: cambiar la mensualidad de un tenant no toca
--   los cobros ya generados (cada cobro guarda su monto), un pago nunca se edita ni
--   se borra (se ANULA) y un cobro nunca se borra (se CANCELA). El estado
--   (Pendiente, Pagado, Vencido, Parcial, Cancelado) NO se guarda: se deriva.
--
-- QUE HACE (mira information_schema; solo CREA tablas, no cambia ni borra nada de
-- lo que ya existe):
--   1) Guarda el motor de tenants (debe ser InnoDB: MyISAM ignora las FK en
--      silencio) y el tipo exacto de tenants.id, que se COPIA a
--      cobro_config.client_id y cobros.client_id (la FK no se crea si los tipos
--      difieren).
--   2) Crea, si faltan, las cuatro tablas. Si tenants no es InnoDB o su id no se
--      puede leer, NO crea ninguna y el resultado final lo muestra con todo_ok = NO.
--   Reglas ON DELETE (las mismas de la 038): cobro_config -> tenants CASCADE (la
--   configuracion no es historial); cobros -> tenants RESTRICT (un tenant con
--   cobros NO se borra: el historial se conserva); cobro_pagos y
--   cobro_recordatorios -> cobros RESTRICT; cobros.generado_de_cobro_id -> cobros
--   SET NULL. UNIQUE uk_cobros_cliente_periodo (client_id, periodo): ni una
--   repeticion de la peticion puede crear dos cobros del mismo tenant y mes;
--   uk_cobro_pagos_idem: repetir un pago no lo registra dos veces. Montos
--   DECIMAL(12,2); CHECK monto > 0 y 0 <= monto_pagado <= monto (MySQL 8.0.16+).
--   user_id guarda users.id de la master (sin FK a proposito, como en las bases de
--   las empresas: es informativo).
--
-- RESULTADO (la ultima consulta, una sola fila): base = la master;
--   motor_tenants = InnoDB y tipo_tenants_id = el tipo de tenants.id; las cuatro
--   tablas = InnoDB; indices = 8, fks = 5 y checks = 5 en MySQL 8.0.16+ (0 en uno
--   mas viejo); todo_ok = SI. Si motor_tenants no sale InnoDB o un tipo sale NULL,
--   no se creo ninguna tabla y todo_ok = NO: para y avisa.
--
-- ORDEN: ANTES de subir el codigo de la API que trae /api/cobros-tenants. Solo crea
-- tablas, asi que es segura con el codigo de hoy. No hay migracion de tenant: nada
-- de esto va en la base de ninguna empresa. Toma un respaldo de la master antes.
-- =============================================================================

SET @db := DATABASE();

-- Guardia: la base seleccionada tiene que ser la master (la que tiene tenants). Si
-- no lo es, esta sentencia falla con un nombre de tabla que dice que hacer, ANTES
-- de tocar nada. No consulta information_schema: phpMyAdmin no cambia de base aqui.
SET @guardia := IF(@db IS NULL OR @db IN ('information_schema', 'mysql', 'performance_schema', 'sys'),
  'DO (SELECT 1 FROM `ALTO_elige_la_base_master_en_el_panel`.`x` LIMIT 1)',
  CONCAT('DO (SELECT 1 FROM `', @db, '`.tenants LIMIT 1)'));
PREPARE s_guardia FROM @guardia;
EXECUTE s_guardia;
DEALLOCATE PREPARE s_guardia;

-- 1) Lo que hay hoy (solo lectura).
SET @tipo_tenant_id := (
  SELECT COLUMN_TYPE FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'tenants'
    AND COLUMN_NAME = 'id'
);
SET @motor_innodb := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'tenants'
    AND ENGINE = 'InnoDB'
);
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

-- 2) Las cuatro tablas. Cada una se crea solo si falta y si tenants es InnoDB con su
--    id legible. Todo nombre de tabla lleva la base (`', @db, '`.tabla), tambien en
--    las REFERENCES.
SET @listo := (@motor_innodb = 1 AND @tipo_tenant_id IS NOT NULL);

-- 2a) cobro_config: la mensualidad de cada tenant. PK = client_id (el id del tenant).
SET @crear_cobro_config := (@listo = 1 AND @has_cobro_config = 0);
SET @sql_cobro_config := IF(@crear_cobro_config = 1,
  CONCAT(
    'CREATE TABLE IF NOT EXISTS `', @db, '`.cobro_config (',
      'client_id ', @tipo_tenant_id, ' NOT NULL COMMENT ''tenants.id: el cliente es un tenant'', ',
      'monto_mensual DECIMAL(12,2) NOT NULL COMMENT ''Monto del cobro de cada mes (DOP)'', ',
      'dia_vencimiento TINYINT UNSIGNED NOT NULL COMMENT ''Dia del mes en que vence (1-31); en un mes mas corto, el ultimo dia'', ',
      'descripcion VARCHAR(150) NULL COMMENT ''Concepto de cada cobro mensual'', ',
      'activo TINYINT(1) NOT NULL DEFAULT 1 COMMENT ''1 = al pagarse un cobro se genera el del mes siguiente'', ',
      'user_id INT(11) NULL COMMENT ''users.id del ultimo cambio (sin FK a proposito)'', ',
      'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, ',
      'updated_at DATETIME NULL DEFAULT NULL, ',
      'PRIMARY KEY (client_id), ',
      'CONSTRAINT cobro_config_client_fk FOREIGN KEY (client_id) ',
        'REFERENCES `', @db, '`.tenants (id) ON DELETE CASCADE, ',
      'CONSTRAINT cobro_config_monto_ck CHECK (monto_mensual > 0), ',
      'CONSTRAINT cobro_config_dia_ck CHECK (dia_vencimiento BETWEEN 1 AND 31)',
    ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'),
  'DO 0');
PREPARE s_021_config FROM @sql_cobro_config;
EXECUTE s_021_config;
DEALLOCATE PREPARE s_021_config;

-- 2b) cobros: un cobro por tenant y mes. monto es del propio cobro; monto_pagado es una
--     cache que el modelo recalcula siempre desde los pagos vigentes.
SET @crear_cobros := (@listo = 1 AND @has_cobros = 0);
SET @sql_cobros := IF(@crear_cobros = 1,
  CONCAT(
    'CREATE TABLE IF NOT EXISTS `', @db, '`.cobros (',
      'id INT(11) NOT NULL AUTO_INCREMENT, ',
      'client_id ', @tipo_tenant_id, ' NOT NULL COMMENT ''tenants.id: el cliente es un tenant'', ',
      'periodo CHAR(7) NOT NULL COMMENT ''Mes del cobro, AAAA-MM'', ',
      'descripcion VARCHAR(150) NULL, ',
      'monto DECIMAL(12,2) NOT NULL COMMENT ''Monto original de ESTE cobro (DOP)'', ',
      'monto_pagado DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT ''Suma de los pagos vigentes (cache recalculada en cada pago)'', ',
      'fecha_vencimiento DATE NOT NULL, ',
      'cancelado_at DATETIME NULL DEFAULT NULL COMMENT ''NULL = activo; un cobro nunca se borra'', ',
      'motivo_cancelacion VARCHAR(255) NULL, ',
      'generado_de_cobro_id INT(11) NULL COMMENT ''Cobro cuyo pago genero este (NULL = creado a mano)'', ',
      'user_id INT(11) NULL COMMENT ''users.id que lo creo o lo genero con su pago (sin FK a proposito)'', ',
      'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, ',
      'updated_at DATETIME NULL DEFAULT NULL, ',
      'PRIMARY KEY (id), ',
      'UNIQUE KEY uk_cobros_cliente_periodo (client_id, periodo), ',
      'KEY idx_cobros_vencimiento (fecha_vencimiento), ',
      'KEY idx_cobros_origen (generado_de_cobro_id), ',
      'CONSTRAINT cobros_client_fk FOREIGN KEY (client_id) ',
        'REFERENCES `', @db, '`.tenants (id) ON DELETE RESTRICT, ',
      'CONSTRAINT cobros_origen_fk FOREIGN KEY (generado_de_cobro_id) ',
        'REFERENCES `', @db, '`.cobros (id) ON DELETE SET NULL, ',
      'CONSTRAINT cobros_monto_ck CHECK (monto > 0), ',
      'CONSTRAINT cobros_pagado_ck CHECK (monto_pagado >= 0 AND monto_pagado <= monto)',
    ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'),
  'DO 0');
PREPARE s_021_cobros FROM @sql_cobros;
EXECUTE s_021_cobros;
DEALLOCATE PREPARE s_021_cobros;

-- 2c) cobro_pagos: los pagos de un cobro (varios, completos o parciales). Un pago no se
--     edita ni se borra: se ANULA y sigue a la vista. idempotency_key (UUID del
--     formulario) evita registrar dos veces el mismo pago.
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
      'user_id INT(11) NULL COMMENT ''users.id que lo registro (sin FK a proposito)'', ',
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
PREPARE s_021_pagos FROM @sql_cobro_pagos;
EXECUTE s_021_pagos;
DEALLOCATE PREPARE s_021_pagos;

-- 2d) cobro_recordatorios: los recordatorios que alguien hizo a mano. Solo un registro: la
--     app no envia nada.
SET @crear_cobro_recordatorios := (@listo = 1 AND @hay_cobros = 1 AND @has_cobro_recordatorios = 0);
SET @sql_cobro_recordatorios := IF(@crear_cobro_recordatorios = 1,
  CONCAT(
    'CREATE TABLE IF NOT EXISTS `', @db, '`.cobro_recordatorios (',
      'id INT(11) NOT NULL AUTO_INCREMENT, ',
      'cobro_id INT(11) NOT NULL, ',
      'fecha_hora DATETIME NOT NULL COMMENT ''Cuando se hizo el recordatorio'', ',
      'canal VARCHAR(15) NOT NULL DEFAULT ''otro'' COMMENT ''llamada | mensaje | correo | visita | otro'', ',
      'nota VARCHAR(255) NULL, ',
      'user_id INT(11) NULL COMMENT ''users.id que lo registro (sin FK a proposito)'', ',
      'user_name VARCHAR(100) NULL COMMENT ''Nombre guardado de quien lo registro'', ',
      'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, ',
      'PRIMARY KEY (id), ',
      'KEY idx_cobro_recordatorios_cobro (cobro_id, fecha_hora), ',
      'CONSTRAINT cobro_recordatorios_cobro_fk FOREIGN KEY (cobro_id) ',
        'REFERENCES `', @db, '`.cobros (id) ON DELETE RESTRICT',
    ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'),
  'DO 0');
PREPARE s_021_recordatorios FROM @sql_cobro_recordatorios;
EXECUTE s_021_recordatorios;
DEALLOCATE PREPARE s_021_recordatorios;

-- Resultado (la ultima consulta, la que muestra phpMyAdmin): una sola fila. 'checks' cuenta
-- los CHECK que la base conoce: 5 en MySQL 8.0.16+, 0 en uno mas viejo (que los ignora).
SELECT r.*,
       IF(r.motor_tenants = 'InnoDB'
          AND r.cobro_config = 'InnoDB' AND r.cobros = 'InnoDB'
          AND r.cobro_pagos = 'InnoDB' AND r.cobro_recordatorios = 'InnoDB'
          AND r.indices = 8 AND r.fks = 5 AND r.checks IN (0, 5), 'SI', 'NO') AS todo_ok
FROM (
  SELECT @db AS base,
         (SELECT ENGINE FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'tenants') AS motor_tenants,
         @tipo_tenant_id AS tipo_tenants_id,
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
