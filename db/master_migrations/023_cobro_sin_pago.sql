-- =============================================================================
-- Master Migration 023: Cobros de tenants: que empresas de un GRUPO no pagan la mensualidad.
-- Tabla cobro_sin_pago en la base MASTER.
--
-- Ejecutar contra la base MASTER, UNA vez. Idempotente: se puede correr dos
-- veces sin error, y la segunda no toca nada. Solo CREA una tabla.
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
-- POR QUE: un grupo de empresas (tenants.grupo_id, master 008) de un mismo cliente comparte UNA
-- mensualidad, pero no todas sus empresas la pagan: algunas solo certificaron (y despues pasaron
-- a produccion, asi que ni el tipo ni el ambiente dicen quien paga). Esta tabla MARCA las que no
-- pagan: nada se borra, y quitar la marca (una fila menos) la devuelve a la lista de las que
-- pagan. SIN FILA = paga, asi que no hay nada que rellenar y todo sigue igual hasta que alguien
-- marque una. La pone y la quita la cuenta de soporte desde la pantalla de Cobros de tenants.
-- No es lo mismo que cobro_excluidos ("no es un cliente"), que saca de la lista a una unidad entera.
--   client_id  tenants.id. PK: una fila por empresa. FK a tenants ON DELETE CASCADE (la marca no es
--              historial: si un dia se borra la empresa, se va con ella).
--   motivo     por que no paga (opcional, hasta 150 caracteres).
--   user_id    users.id de quien la marco (sin FK a proposito, como en las tablas de cobros).
--
-- QUE HACE (mira information_schema; solo CREA la tabla, no cambia ni borra nada de lo que ya
-- existe):
--   1) Guarda el motor de tenants (debe ser InnoDB: MyISAM ignora las FK en silencio) y
--      el tipo exacto de tenants.id, que se COPIA a cobro_sin_pago.client_id (la FK no
--      se crea si los tipos difieren).
--   2) Crea cobro_sin_pago si falta. Si tenants no es InnoDB o su id no se puede leer,
--      NO la crea y el resultado final lo muestra con todo_ok = NO.
--
-- RESULTADO (la ultima consulta, una sola fila): base = la master; motor_tenants =
--   InnoDB y tipo_tenants_id = el tipo de tenants.id; cobro_sin_pago = InnoDB; fks = 1;
--   todo_ok = SI. Si motor_tenants no sale InnoDB o el tipo sale NULL, no se creo la
--   tabla y todo_ok = NO: para y avisa.
--
-- ORDEN: ANTES de subir el codigo de la API de este cambio (cobroModel.php lee cobro_sin_pago en
-- cada consulta de Cobros de tenants: sin la tabla responde 500). Solo crea una tabla, asi que es
-- segura con el codigo de hoy. Toma un respaldo de la master antes.
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
SET @has_cobro_sin_pago := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'cobro_sin_pago'
);

-- 2) La tabla, solo si falta y si tenants es InnoDB con su id legible. Todo nombre de
--    tabla lleva la base (`', @db, '`.tabla), tambien en la REFERENCES.
SET @listo := (@motor_innodb = 1 AND @tipo_tenant_id IS NOT NULL);
SET @crear_cobro_sin_pago := (@listo = 1 AND @has_cobro_sin_pago = 0);
SET @sql_cobro_sin_pago := IF(@crear_cobro_sin_pago = 1,
  CONCAT(
    'CREATE TABLE IF NOT EXISTS `', @db, '`.cobro_sin_pago (',
      'client_id ', @tipo_tenant_id, ' NOT NULL COMMENT ''tenants.id: la empresa de un grupo que NO paga la mensualidad (solo certifico)'', ',
      'motivo VARCHAR(150) NULL COMMENT ''Por que no paga (ej. solo certificacion); lo escribe quien la marca'', ',
      'user_id INT(11) NULL COMMENT ''users.id de quien la marco (sin FK a proposito)'', ',
      'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, ',
      'PRIMARY KEY (client_id), ',
      'CONSTRAINT cobro_sin_pago_client_fk FOREIGN KEY (client_id) ',
        'REFERENCES `', @db, '`.tenants (id) ON DELETE CASCADE',
    ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'),
  'DO 0');
PREPARE s_023_sin_pago FROM @sql_cobro_sin_pago;
EXECUTE s_023_sin_pago;
DEALLOCATE PREPARE s_023_sin_pago;

-- Resultado (la ultima consulta, la que muestra phpMyAdmin): una sola fila.
SELECT r.*,
       IF(r.motor_tenants = 'InnoDB' AND r.cobro_sin_pago = 'InnoDB' AND r.fks = 1, 'SI', 'NO') AS todo_ok
FROM (
  SELECT @db AS base,
         (SELECT ENGINE FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'tenants') AS motor_tenants,
         @tipo_tenant_id AS tipo_tenants_id,
         (SELECT ENGINE FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'cobro_sin_pago') AS cobro_sin_pago,
         (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
          WHERE CONSTRAINT_SCHEMA = @db AND CONSTRAINT_NAME = 'cobro_sin_pago_client_fk') AS fks
) r;
