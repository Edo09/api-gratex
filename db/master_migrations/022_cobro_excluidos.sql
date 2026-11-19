-- =============================================================================
-- Master Migration 022: Cobros de tenants: los tenants que NO son clientes.
-- Tabla cobro_excluidos en la base MASTER.
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
-- POR QUE: la pestana Clientes de Cobros de tenants (docs/modules/cobros.md) lista
-- TODOS los tenants, y varios no son clientes: se crearon solo para certificar a una
-- empresa (tipo integracion, en certificacion) o son de prueba. Borrarlos no se puede
-- ni se debe: guardan sus e-CF (ecf_integracion_backup) y su historial, y las FK lo
-- impiden. Esta tabla solo MARCA a los tenants a los que la plataforma no les cobra:
-- no se borra nada, y quitar la marca (una fila menos) los devuelve a la lista. La
-- pone y la quita la cuenta de soporte desde la propia pantalla.
--   client_id  tenants.id. PK: una fila por tenant. FK a tenants ON DELETE CASCADE (la
--              marca no es historial: si un dia se borra el tenant, se va con el).
--   motivo     por que no es un cliente (opcional, hasta 150 caracteres).
--   user_id    users.id de quien lo quito de la lista (sin FK a proposito, como en las
--              tablas de cobros: es informativo).
--
-- QUE HACE (mira information_schema; solo CREA la tabla, no cambia ni borra nada de lo
-- que ya existe):
--   1) Guarda el motor de tenants (debe ser InnoDB: MyISAM ignora las FK en silencio) y
--      el tipo exacto de tenants.id, que se COPIA a cobro_excluidos.client_id (la FK no
--      se crea si los tipos difieren).
--   2) Crea cobro_excluidos si falta. Si tenants no es InnoDB o su id no se puede leer,
--      NO la crea y el resultado final lo muestra con todo_ok = NO.
--
-- RESULTADO (la ultima consulta, una sola fila): base = la master; motor_tenants =
--   InnoDB y tipo_tenants_id = el tipo de tenants.id; cobro_excluidos = InnoDB; fks = 1;
--   todo_ok = SI. Si motor_tenants no sale InnoDB o el tipo sale NULL, no se creo la
--   tabla y todo_ok = NO: para y avisa.
--
-- ORDEN: ANTES de subir el codigo de la API que trae el directorio con esta tabla
-- (GET /api/cobros-tenants/clientes la consulta) y PUT .../clientes/{id}/excluido. Solo
-- crea una tabla, asi que es segura con el codigo de hoy. Toma un respaldo de la
-- master antes.
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
SET @has_cobro_excluidos := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'cobro_excluidos'
);

-- 2) La tabla, solo si falta y si tenants es InnoDB con su id legible. Todo nombre de
--    tabla lleva la base (`', @db, '`.tabla), tambien en la REFERENCES.
SET @listo := (@motor_innodb = 1 AND @tipo_tenant_id IS NOT NULL);
SET @crear_cobro_excluidos := (@listo = 1 AND @has_cobro_excluidos = 0);
SET @sql_cobro_excluidos := IF(@crear_cobro_excluidos = 1,
  CONCAT(
    'CREATE TABLE IF NOT EXISTS `', @db, '`.cobro_excluidos (',
      'client_id ', @tipo_tenant_id, ' NOT NULL COMMENT ''tenants.id: el tenant que NO es un cliente al que se le cobra'', ',
      'motivo VARCHAR(150) NULL COMMENT ''Por que no es un cliente (ej. solo certificacion); lo escribe quien lo quita de la lista'', ',
      'user_id INT(11) NULL COMMENT ''users.id de quien lo quito de la lista (sin FK a proposito)'', ',
      'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, ',
      'PRIMARY KEY (client_id), ',
      'CONSTRAINT cobro_excluidos_client_fk FOREIGN KEY (client_id) ',
        'REFERENCES `', @db, '`.tenants (id) ON DELETE CASCADE',
    ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'),
  'DO 0');
PREPARE s_022_excluidos FROM @sql_cobro_excluidos;
EXECUTE s_022_excluidos;
DEALLOCATE PREPARE s_022_excluidos;

-- Resultado (la ultima consulta, la que muestra phpMyAdmin): una sola fila.
SELECT r.*,
       IF(r.motor_tenants = 'InnoDB' AND r.cobro_excluidos = 'InnoDB' AND r.fks = 1, 'SI', 'NO') AS todo_ok
FROM (
  SELECT @db AS base,
         (SELECT ENGINE FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'tenants') AS motor_tenants,
         @tipo_tenant_id AS tipo_tenants_id,
         (SELECT ENGINE FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'cobro_excluidos') AS cobro_excluidos,
         (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
          WHERE CONSTRAINT_SCHEMA = @db AND CONSTRAINT_NAME = 'cobro_excluidos_client_fk') AS fks
) r;
