-- ============================================================================
-- 040_pos_empleados_usuario.sql — pos_empleados.user_id: el empleado del POS es
-- un usuario de FiscalPoint, y su PIN puede estar pendiente (lo crea el propio
-- usuario la primera vez que entra al POS).
-- ============================================================================
-- Para DBs de tenant YA desplegados: correr en la base de CADA empresa. Los
-- tenants nuevos lo reciben via db/tenant_schema.sql (mismas columnas e indice).
--
-- POR QUE:
--   Hasta ahora un empleado del POS solo existia si un administrador lo creaba a
--   mano en Punto de venta, y el sistema le generaba el PIN. Los usuarios de
--   FiscalPoint (master.users) tienen que poder usar el POS sin ese paso: su
--   empleado se crea solo (api-gratex src/Pos/PosUsuarios.php) y el propio
--   usuario elige su PIN la primera vez que entra desde la web o con su usuario.
--   Hace falta:
--     * user_id: el id del usuario en el master (sin FK: son bases distintas).
--       NULL = empleado creado a mano, sin usuario. Un usuario, un empleado.
--     * pin_hmac y pin_generado_at nulos: un empleado de FiscalPoint existe antes
--       de tener PIN. NULL = PIN pendiente; con NULL nadie puede entrar como el.
--       El indice UNIQUE de pin_hmac admite varios NULL, asi que sigue
--       garantizando que dos empleados no compartan PIN.
--     * pin_choques / pin_choque_at: cuantas veces, en los ultimos minutos, el
--       usuario eligio un PIN que ya usa otro empleado. Como el PIN lo elige la
--       persona, "ese PIN ya esta en uso" le dice que otro empleado lo tiene; el
--       API frena a quien lo pruebe una y otra vez (PosUsuarios::MAX_CHOQUES).
--
-- QUE HACE (no reescribe ningun valor):
--   1) Agrega user_id (INT NULL), despues de rol, si falta.
--   2) Agrega el indice UNIQUE uk_pos_empleado_user (user_id) si falta.
--   3) Pasa pin_hmac a CHAR(64) NULL si hoy es NOT NULL.
--   4) Pasa pin_generado_at a DATETIME NULL (con el mismo DEFAULT CURRENT_TIMESTAMP:
--      un empleado creado a mano sigue naciendo con su PIN de hoy) si hoy es NOT NULL.
--   5) Agrega pin_choques (TINYINT UNSIGNED, 0) y pin_choque_at (DATETIME NULL)
--      si faltan.
--   Los empleados que ya existen quedan igual: sin usuario, con su PIN.
--
-- COMO CORRERLA (phpMyAdmin):
--   Entra a la base del tenant, pestana SQL, pega TODO el archivo y ejecuta.
--   La primera sentencia guarda la base seleccionada en @db. Para no depender
--   de la base seleccionada, cambia esa linea por el nombre:
--     SET @db := 'smhynzte_new_gratexdb';
--   El resultado (la ultima consulta) son cinco filas: user_id, pin_hmac,
--   pin_generado_at, pin_choques y pin_choque_at. IS_NULLABLE: YES en user_id,
--   pin_hmac, pin_generado_at y pin_choque_at; NO en pin_choques.
--
-- ORDEN: va ANTES de desplegar el API. El API nuevo lee user_id y pin_hmac IS NULL
-- en CADA consulta de empleados (incluida la de entrar con el PIN): sin la columna,
-- el POS de esa empresa no entra. Con la migracion y el API viejo todo sigue igual:
-- las columnas nuevas admiten NULL y el API viejo no las nombra.
--
-- Se puede correr dos veces: cada paso que ya esta hecho ejecuta DO 0.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 0) La base del tenant: la que esta seleccionada.
-- ----------------------------------------------------------------------------
SET @db := DATABASE();

-- Guardia: la base seleccionada tiene que ser la correcta. Si no lo es, esta
-- sentencia falla con un nombre de tabla que dice que hacer, ANTES de tocar nada.
SET @guardia := IF(@db IS NULL OR @db IN ('information_schema', 'mysql', 'performance_schema', 'sys'),
  'DO (SELECT 1 FROM `ALTO_elige_la_base_de_la_empresa_en_el_panel`.`x` LIMIT 1)',
  CONCAT('DO (SELECT 1 FROM `', @db, '`.pos_empleados LIMIT 1)'));
PREPARE s_guardia FROM @guardia;
EXECUTE s_guardia;
DEALLOCATE PREPARE s_guardia;

-- ----------------------------------------------------------------------------
-- 1) user_id, solo si falta.
-- ----------------------------------------------------------------------------
SET @falta_user := (
  SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'pos_empleados' AND COLUMN_NAME = 'user_id'
);
SET @sql_040_user := IF(@falta_user,
  CONCAT('ALTER TABLE `', @db, '`.pos_empleados ',
    'ADD COLUMN user_id INT(11) NULL DEFAULT NULL ',
    'COMMENT ''users.id del master: el empleado es un usuario de FiscalPoint (sin FK, otra base). NULL = creado a mano'' ',
    'AFTER rol'),
  'DO 0');
PREPARE s_040_user FROM @sql_040_user;
EXECUTE s_040_user;
DEALLOCATE PREPARE s_040_user;

-- ----------------------------------------------------------------------------
-- 2) Un usuario, un empleado: indice UNIQUE sobre user_id, solo si falta.
--    (Varios NULL no chocan: los empleados creados a mano no tienen usuario.)
-- ----------------------------------------------------------------------------
SET @falta_idx := (
  SELECT COUNT(*) = 0 FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'pos_empleados' AND INDEX_NAME = 'uk_pos_empleado_user'
);
SET @sql_040_idx := IF(@falta_idx,
  CONCAT('ALTER TABLE `', @db, '`.pos_empleados ADD UNIQUE KEY uk_pos_empleado_user (user_id)'),
  'DO 0');
PREPARE s_040_idx FROM @sql_040_idx;
EXECUTE s_040_idx;
DEALLOCATE PREPARE s_040_idx;

-- ----------------------------------------------------------------------------
-- 3) pin_hmac admite NULL (PIN pendiente), solo si hoy es NOT NULL. El indice
--    UNIQUE uk_pos_empleado_pin no se toca.
-- ----------------------------------------------------------------------------
SET @pin_no_nulo := (
  SELECT COUNT(*) > 0 FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'pos_empleados' AND COLUMN_NAME = 'pin_hmac' AND IS_NULLABLE = 'NO'
);
SET @sql_040_pin := IF(@pin_no_nulo,
  CONCAT('ALTER TABLE `', @db, '`.pos_empleados ',
    'MODIFY pin_hmac CHAR(64) NULL DEFAULT NULL ',
    'COMMENT ''HMAC-SHA256(pin, POS_PIN_PEPPER + tenant). NULL = el usuario de FiscalPoint todavia no creo su PIN'''),
  'DO 0');
PREPARE s_040_pin FROM @sql_040_pin;
EXECUTE s_040_pin;
DEALLOCATE PREPARE s_040_pin;

-- ----------------------------------------------------------------------------
-- 4) pin_generado_at admite NULL (sin PIN no hay fecha), solo si hoy es NOT NULL.
--    Conserva DEFAULT CURRENT_TIMESTAMP.
-- ----------------------------------------------------------------------------
SET @fecha_no_nula := (
  SELECT COUNT(*) > 0 FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'pos_empleados' AND COLUMN_NAME = 'pin_generado_at' AND IS_NULLABLE = 'NO'
);
SET @sql_040_fecha := IF(@fecha_no_nula,
  CONCAT('ALTER TABLE `', @db, '`.pos_empleados ',
    'MODIFY pin_generado_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP ',
    'COMMENT ''Cuando se fijo el PIN actual. NULL = PIN pendiente'''),
  'DO 0');
PREPARE s_040_fecha FROM @sql_040_fecha;
EXECUTE s_040_fecha;
DEALLOCATE PREPARE s_040_fecha;

-- ----------------------------------------------------------------------------
-- 5) pin_choques y pin_choque_at, solo si faltan (las dos van juntas).
-- ----------------------------------------------------------------------------
SET @faltan_choques := (
  SELECT COUNT(*) < 2 FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'pos_empleados' AND COLUMN_NAME IN ('pin_choques', 'pin_choque_at')
);
SET @sql_040_choques := IF(@faltan_choques,
  CONCAT('ALTER TABLE `', @db, '`.pos_empleados ',
    'ADD COLUMN pin_choques TINYINT UNSIGNED NOT NULL DEFAULT 0 ',
    'COMMENT ''PIN elegidos que ya usaba otro empleado, dentro de la ventana de pin_choque_at'' AFTER pin_generado_at, ',
    'ADD COLUMN pin_choque_at DATETIME NULL DEFAULT NULL ',
    'COMMENT ''Ultimo PIN elegido que ya estaba en uso; inicia la ventana del limite'' AFTER pin_choques'),
  'DO 0');
PREPARE s_040_choques FROM @sql_040_choques;
EXECUTE s_040_choques;
DEALLOCATE PREPARE s_040_choques;

-- ----------------------------------------------------------------------------
-- Resultado (la ultima consulta, la que muestra phpMyAdmin).
-- ----------------------------------------------------------------------------
SELECT @db AS base, c.COLUMN_NAME, c.COLUMN_TYPE, c.IS_NULLABLE
FROM information_schema.COLUMNS c
WHERE c.TABLE_SCHEMA = @db AND c.TABLE_NAME = 'pos_empleados'
  AND c.COLUMN_NAME IN ('user_id', 'pin_hmac', 'pin_generado_at', 'pin_choques', 'pin_choque_at')
ORDER BY c.ORDINAL_POSITION;
