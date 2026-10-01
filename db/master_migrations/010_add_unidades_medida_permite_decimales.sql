-- =============================================================================
-- Master Migration 010: que unidades de medida admiten cantidades con decimales.
--
-- Ejecutar contra la base MASTER, UNA vez. Idempotente: se puede correr dos
-- veces sin error, y la segunda no toca nada (el marcado del paso 2 solo corre
-- cuando la columna se acaba de crear, asi que no deshace ajustes a mano).
-- Reflejado en db/master_schema.sql (instalaciones nuevas).
--
-- NOTA: el nombre de la master DB cambia por entorno (gratex_master en local,
-- mtldtmte_master_gratex en el server). Selecciona la base ANTES de correr esto
-- (en phpMyAdmin basta con entrar a la base; por CLI usa `USE <tu_master>;`).
--
-- PARA QUE: las lineas de factura, cotizacion, compra y el inventario pasan a
-- aceptar cantidades con decimales (migracion de tenant 025). Pero no toda
-- unidad lo admite: 1,5 metros de cable o 0,75 kg de clavos si; 1,5 "Unidad"
-- o 2,3 "Pieza" no. Esta columna es la que decide, y la leen la API
-- (GET /api/unidades-medida) y los formularios.
--
-- ORDEN DE DESPLIEGUE: correr ESTA migracion y la 025 de cada tenant ANTES de
-- subir el codigo nuevo. El codigo tolera que la columna no exista todavia
-- (en ese caso no bloquea decimales), pero asi no hay una ventana rara.
-- =============================================================================

-- 1) Columna (0 = solo cantidades enteras, que es lo que se hacia hasta hoy).
SET @has_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'unidades_medida'
    AND COLUMN_NAME = 'permite_decimales'
);
SET @sql_col := IF(@has_col = 0,
  'ALTER TABLE unidades_medida ADD COLUMN permite_decimales TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = la cantidad puede llevar decimales (metro, kg, litro, hora...); 0 = solo enteros (unidad, pieza, caja...)'' AFTER descripcion',
  'DO 0');
PREPARE s_col FROM @sql_col;
EXECUTE s_col;
DEALLOCATE PREPARE s_col;

-- 2) Marcar las unidades de MAGNITUD (longitud, peso, volumen, area, tiempo,
--    energia, granel). Se marcan por nombre porque el seed de las 62 filas DGII
--    no esta en el repo; la columna descripcion usa utf8mb4_unicode_ci, asi que
--    LIKE no distingue mayusculas ni tildes ('%galon%' encuentra "Galón").
--    Las de CONTEO (Unidad, Pieza, Caja, Docena, Par, Paquete, Bolsa...) quedan
--    en 0. OJO con "Pie": se compara exacto para no marcar "Pieza".
UPDATE unidades_medida
SET permite_decimales = 1
WHERE @has_col = 0          -- solo en la corrida que creo la columna
  AND codigo <> 'UND'
  AND (
       descripcion LIKE '%metro%'        -- metro, centimetro, milimetro, kilometro, metro cuadrado/cubico
    OR descripcion LIKE '%gramo%'        -- gramo, kilogramo, miligramo
    OR descripcion LIKE '%litro%'        -- litro, mililitro
    OR descripcion LIKE '%galon%'
    OR descripcion LIKE '%libra%'
    OR descripcion LIKE '%onza%'
    OR descripcion LIKE '%tonelada%'
    OR descripcion LIKE '%quintal%'
    OR descripcion LIKE '%yarda%'
    OR descripcion LIKE '%pulgada%'
    OR descripcion IN ('Pie', 'Pies')
    OR descripcion LIKE 'Pie %'          -- pie cuadrado, pie cubico (no "Pieza")
    OR descripcion LIKE 'Pies %'
    OR descripcion LIKE '%hectarea%'
    OR descripcion LIKE '%hora%'         -- hora, kilovatio hora
    OR descripcion LIKE '%minuto%'
    OR descripcion LIKE '%segundo%'
    OR descripcion IN ('Dia', 'Dias')
    OR descripcion LIKE '%granel%'
    OR descripcion LIKE '%termica%'      -- millones de unidades termicas (MMBTU)
  );

-- 3) REVISAR el resultado antes de dar por buena la migracion: arriba deben
--    salir solo unidades que de verdad se fraccionan.
SELECT id, codigo, descripcion, permite_decimales
FROM unidades_medida
ORDER BY permite_decimales DESC, id;

-- Para corregir una sola unidad (sustituye el id):
--   UPDATE unidades_medida SET permite_decimales = 1 WHERE id = <id>;   -- admite decimales
--   UPDATE unidades_medida SET permite_decimales = 0 WHERE id = <id>;   -- solo enteros
-- =============================================================================
