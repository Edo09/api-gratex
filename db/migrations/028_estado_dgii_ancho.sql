-- ============================================================================
-- 028_estado_dgii_ancho.sql — estado_dgii de facturas y gastos pasa a VARCHAR(40).
-- ============================================================================
-- Para DBs de tenant YA desplegados: correr en la base de CADA empresa. Los
-- tenants nuevos lo reciben via db/tenant_schema.sql (mismo ancho y COMMENT).
--
-- POR QUE:
--   La columna era VARCHAR(20), pero el codigo escribe estados mas largos, y
--   los escribe DESPUES de que la DGII ya recibio el e-CF:
--     - Flujo RFCE (E32 < 250k): 'RFCE_' . estado -> RFCE_ACEPTADO_CONDICIONAL
--       (25) al guardar la factura (ECFEmissionService, facturaModel).
--     - Reutilizar un numero rechazado: el intento anterior se archiva con
--       CONCAT(estado_dgii, '_ARCHIVADO') -> RFCE_RECHAZADO_ARCHIVADO (24),
--       NO_ENCONTRADO_ARCHIVADO (23), en facturas y en gastos.
--   En modo estricto (STRICT_TRANS_TABLES, el default de MySQL y MariaDB) eso
--   es "Data too long": la transaccion se revierte y el comprobante que la DGII
--   ya acepto no queda guardado ("se envio a la DGII, pero no se pudo
--   guardar"), igual que el E34 del 2026-10-05 con la clave vieja de la 027.
--   Sin modo estricto el estado se trunca en silencio (RFCE_ACEPTADO_CONDIC) y
--   las consultas que comparan el valor exacto no lo reconocen.
--   40 cubre el peor caso que el codigo puede armar hoy (35) y deja margen.
--   tools/check_estado_dgii_ancho.php lo revisa sin MySQL.
--
-- QUE HACE (mira information_schema; no borra ni reescribe datos):
--   Cuenta cuantas filas ya quedaron truncadas por el ancho viejo. Despues,
--   si la columna tiene menos de 40, un MODIFY armado con la definicion actual
--   (charset, collation, nulabilidad y default) que solo cambia el ancho y deja
--   el COMMENT con la lista real de estados. Si ya tiene 40 o mas, no hace nada.
--   El default se copia tal cual lo reporta el motor: MariaDB lo da entre
--   comillas ('PENDIENTE') y MySQL sin ellas (PENDIENTE); se aceptan las dos.
--
-- COMO CORRERLA (phpMyAdmin):
--   Entra a la base del tenant, pestana SQL, pega TODO el archivo y ejecuta.
--   La primera sentencia guarda la base seleccionada en @db y todo lo demas la
--   nombra explicitamente. Si prefieres no depender de la base seleccionada,
--   cambia esa primera linea por el nombre:  SET @db := 'smhynzte_002';
--   El resultado es una sola tabla (la ultima consulta):
--     - dos filas, facturas y gastos, con la base correcta en "base" y
--       ancho_ok = SI;
--     - filas_truncadas_antes distinto de 0: ese tenant ya tenia estados
--       recortados; anotalo y avisa (esta migracion solo evita que siga pasando).
--   Si sale el error "Table '...facturas' doesn't exist" o "Unknown table
--   'FACTURAS' in information_schema", la base seleccionada no era la del
--   tenant: no se cambio nada; selecciona la base correcta y vuelve a correrla.
--
-- POR QUE @db Y NO DATABASE() EN CADA PASO:
--   phpMyAdmin, despues de ejecutar un SELECT sobre information_schema, cambia
--   la base actual a information_schema para las sentencias que siguen. La
--   primera version de este archivo fallo asi el 2026-10-05 (Percona 8.0.46,
--   phpMyAdmin 5.2.3: "#1109 - Unknown table 'FACTURAS' in information_schema").
--   Con la base guardada en @db antes de cualquier consulta a
--   information_schema, ese cambio ya no afecta.
--
-- ANTES DE CORRER:
--   Ampliar un VARCHAR chico suele ser un cambio de metadatos (sin copiar la
--   tabla); aun asi, mejor fuera de horario.
--
-- ORDEN: no depende de codigo nuevo; se puede correr ya. Debe estar en todos
-- los tenants ANTES de desplegar el guardado previo al envio (write-ahead),
-- que agrega estados nuevos.
--
-- Se puede correr dos veces: si el cambio ya esta, el paso ejecuta DO 0.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 0) La base del tenant: la que esta seleccionada en phpMyAdmin.
-- ----------------------------------------------------------------------------
SET @db := DATABASE();

-- ----------------------------------------------------------------------------
-- 1) Filas ya truncadas por el ancho viejo (sin modo estricto). Se muestran en
--    el resultado final. Si @db no es la base del tenant, esto falla aqui y no
--    se toca nada.
-- ----------------------------------------------------------------------------
SET @sql_028_tf := CONCAT(
  'SELECT COUNT(*) INTO @f_truncadas FROM `', @db, '`.facturas ',
  'WHERE estado_dgii IN (''RFCE_ACEPTADO_CONDIC'', ''RFCE_RECHAZADO_ARCHI'', ''NO_ENCONTRADO_ARCHIV'')');
PREPARE s_028_tf FROM @sql_028_tf;
EXECUTE s_028_tf;
DEALLOCATE PREPARE s_028_tf;

SET @sql_028_tg := CONCAT(
  'SELECT COUNT(*) INTO @g_truncadas FROM `', @db, '`.gastos ',
  'WHERE estado_dgii = ''NO_ENCONTRADO_ARCHIV''');
PREPARE s_028_tg FROM @sql_028_tg;
EXECUTE s_028_tg;
DEALLOCATE PREPARE s_028_tg;

-- ----------------------------------------------------------------------------
-- A) facturas.estado_dgii
-- ----------------------------------------------------------------------------
SET @f_arreglar := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'facturas'
    AND COLUMN_NAME = 'estado_dgii'
    AND CHARACTER_MAXIMUM_LENGTH < 40
);
SET @f_definicion := (
  SELECT CONCAT(
           'VARCHAR(40)',
           IF(CHARACTER_SET_NAME IS NULL, '', CONCAT(' CHARACTER SET ', CHARACTER_SET_NAME)),
           IF(COLLATION_NAME IS NULL, '', CONCAT(' COLLATE ', COLLATION_NAME)),
           IF(IS_NULLABLE = 'NO', ' NOT NULL', ' NULL'),
           IF(COLUMN_DEFAULT IS NULL OR COLUMN_DEFAULT = 'NULL', '',
              CONCAT(' DEFAULT ', IF(LEFT(COLUMN_DEFAULT, 1) = '''', COLUMN_DEFAULT, QUOTE(COLUMN_DEFAULT)))),
           ' COMMENT ''PENDIENTE | ENVIADO | EN_PROCESO | ACEPTADO | ACEPTADO_CONDICIONAL | RECHAZADO | NO_ENCONTRADO | ERROR; RFCE_<estado> en E32 < 250k; <estado>_ARCHIVADO al reutilizar el numero'''
         )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'facturas'
    AND COLUMN_NAME = 'estado_dgii'
);
SET @sql_028_facturas := IF(@f_arreglar = 1,
  CONCAT('ALTER TABLE `', @db, '`.facturas MODIFY estado_dgii ', @f_definicion),
  'DO 0');
PREPARE s_028_facturas FROM @sql_028_facturas;
EXECUTE s_028_facturas;
DEALLOCATE PREPARE s_028_facturas;

-- ----------------------------------------------------------------------------
-- B) gastos.estado_dgii
-- ----------------------------------------------------------------------------
SET @g_arreglar := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'gastos'
    AND COLUMN_NAME = 'estado_dgii'
    AND CHARACTER_MAXIMUM_LENGTH < 40
);
SET @g_definicion := (
  SELECT CONCAT(
           'VARCHAR(40)',
           IF(CHARACTER_SET_NAME IS NULL, '', CONCAT(' CHARACTER SET ', CHARACTER_SET_NAME)),
           IF(COLLATION_NAME IS NULL, '', CONCAT(' COLLATE ', COLLATION_NAME)),
           IF(IS_NULLABLE = 'NO', ' NOT NULL', ' NULL'),
           IF(COLUMN_DEFAULT IS NULL OR COLUMN_DEFAULT = 'NULL', '',
              CONCAT(' DEFAULT ', IF(LEFT(COLUMN_DEFAULT, 1) = '''', COLUMN_DEFAULT, QUOTE(COLUMN_DEFAULT)))),
           ' COMMENT ''REGISTRADO (recibido) | PENDIENTE_EMISION | ENVIADO | EN_PROCESO | ACEPTADO | ACEPTADO_CONDICIONAL | RECHAZADO | NO_ENCONTRADO | ERROR; <estado>_ARCHIVADO al reutilizar el numero'''
         )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db
    AND TABLE_NAME = 'gastos'
    AND COLUMN_NAME = 'estado_dgii'
);
SET @sql_028_gastos := IF(@g_arreglar = 1,
  CONCAT('ALTER TABLE `', @db, '`.gastos MODIFY estado_dgii ', @g_definicion),
  'DO 0');
PREPARE s_028_gastos FROM @sql_028_gastos;
EXECUTE s_028_gastos;
DEALLOCATE PREPARE s_028_gastos;

-- ----------------------------------------------------------------------------
-- Resultado (la ultima consulta, la que muestra phpMyAdmin): las dos columnas
-- con 40, el mismo default de antes y las filas que ya venian truncadas.
-- ----------------------------------------------------------------------------
SELECT @db AS base, TABLE_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT,
       IF(CHARACTER_MAXIMUM_LENGTH >= 40, 'SI', 'NO') AS ancho_ok,
       IF(TABLE_NAME = 'facturas', @f_truncadas, @g_truncadas) AS filas_truncadas_antes
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME IN ('facturas', 'gastos')
  AND COLUMN_NAME = 'estado_dgii'
ORDER BY TABLE_NAME;
