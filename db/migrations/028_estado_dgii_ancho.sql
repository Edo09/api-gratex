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
--   Si la columna tiene menos de 40, un MODIFY armado con la definicion actual
--   (charset, collation, nulabilidad y default) que solo cambia el ancho y deja
--   el COMMENT con la lista real de estados. Si ya tiene 40 o mas, no hace nada.
--   El default se copia tal cual lo reporta el motor: MariaDB lo da entre
--   comillas ('PENDIENTE') y MySQL sin ellas (PENDIENTE); se aceptan las dos.
--
-- ANTES DE CORRER:
--   - Corre las consultas del paso 0 (solo lectura). Las filas truncadas que
--     muestre el 0b NO se arreglan solas: anotalas y avisa; esta migracion
--     solo evita que vuelva a pasar.
--   - Ampliar un VARCHAR chico suele ser un cambio de metadatos (sin copiar la
--     tabla); aun asi, mejor fuera de horario.
--
-- ORDEN: no depende de codigo nuevo; se puede correr ya. Debe estar en todos
-- los tenants ANTES de desplegar el guardado previo al envio (write-ahead),
-- que agrega estados nuevos.
--
-- Se puede correr dos veces: si el cambio ya esta, el paso ejecuta DO 0.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 0) Comprobaciones previas (solo lectura).
-- ----------------------------------------------------------------------------
-- 0a) Definicion actual de las dos columnas.
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT,
       CHARACTER_SET_NAME, COLLATION_NAME, COLUMN_COMMENT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('facturas', 'gastos')
  AND COLUMN_NAME = 'estado_dgii'
ORDER BY TABLE_NAME;

-- 0b) Estados ya truncados por el ancho viejo (sin modo estricto). Se espera
--     cero filas.
SELECT 'facturas' AS tabla, estado_dgii, COUNT(*) AS filas
FROM facturas
WHERE estado_dgii IN ('RFCE_ACEPTADO_CONDIC', 'RFCE_RECHAZADO_ARCHI', 'NO_ENCONTRADO_ARCHIV')
GROUP BY estado_dgii
UNION ALL
SELECT 'gastos', estado_dgii, COUNT(*)
FROM gastos
WHERE estado_dgii = 'NO_ENCONTRADO_ARCHIV'
GROUP BY estado_dgii;

-- ----------------------------------------------------------------------------
-- A) facturas.estado_dgii
-- ----------------------------------------------------------------------------
SET @f_arreglar := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
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
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'facturas'
    AND COLUMN_NAME = 'estado_dgii'
);
SET @sql_028_facturas := IF(@f_arreglar = 1,
  CONCAT('ALTER TABLE facturas MODIFY estado_dgii ', @f_definicion),
  'DO 0');
PREPARE s_028_facturas FROM @sql_028_facturas;
EXECUTE s_028_facturas;
DEALLOCATE PREPARE s_028_facturas;

-- ----------------------------------------------------------------------------
-- B) gastos.estado_dgii
-- ----------------------------------------------------------------------------
SET @g_arreglar := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
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
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'gastos'
    AND COLUMN_NAME = 'estado_dgii'
);
SET @sql_028_gastos := IF(@g_arreglar = 1,
  CONCAT('ALTER TABLE gastos MODIFY estado_dgii ', @g_definicion),
  'DO 0');
PREPARE s_028_gastos FROM @sql_028_gastos;
EXECUTE s_028_gastos;
DEALLOCATE PREPARE s_028_gastos;

-- Resultado: las dos columnas con 40 y el mismo default de antes.
SELECT DATABASE() AS base, TABLE_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT,
       IF(CHARACTER_MAXIMUM_LENGTH >= 40, 'SI', 'NO') AS ancho_ok
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('facturas', 'gastos')
  AND COLUMN_NAME = 'estado_dgii'
ORDER BY TABLE_NAME;
