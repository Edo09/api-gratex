-- ============================================================================
-- 041_pos_caja_por_defecto.sql - "Caja 1" por defecto en el POS de cada empresa.
-- ============================================================================
-- Para DBs de tenant YA desplegados: correr en la base de CADA empresa, use o no el
-- POS. Los tenants nuevos la reciben via db/tenant_schema.sql (la misma sentencia,
-- justo despues de CREATE TABLE pos_cajas).
--
-- POR QUE:
--   Hasta ahora el primer administrador que abria Punto de venta > Cajas y equipos
--   veia "Todavia no hay cajas", y la pantalla de habilitar un equipo en pos.* decia
--   "Crea la primera". Con esto cada empresa ya tiene su "Caja 1" y habilitar la
--   primera PC es elegir una caja que existe. Pedido del dueno el 2026-11-24.
--   Diseno: fiscalo/docs/superpowers/specs/2026-11-24-caja-1-por-defecto-design.md
--
-- QUE HACE (solo agrega una fila; no cambia ni borra nada):
--   Inserta la caja "Caja 1" (activa) SOLO si pos_cajas esta vacia. Una caja se
--   puede renombrar y desactivar pero no borrar, asi que "vacia" quiere decir que
--   nunca se creo ninguna. Por eso es segura:
--     - una empresa que ya tiene cajas (con cualquier nombre, activas o no) no
--       recibe otra;
--     - una empresa que renombro o desactivo su "Caja 1" no la ve reaparecer;
--     - correrla dos veces deja una sola fila.
--
-- ES SOLO DATOS: no tiene fila en tools/verificar_migraciones_tenant.sql. Ese
-- verificador solo lee information_schema y funciona aunque falte una tabla;
-- contar filas de pos_cajas lo romperia en una base sin la 030. Mismo caso que la
-- master 020. El resultado es la ultima consulta de este archivo.
--
-- COMO CORRERLA (phpMyAdmin): base del tenant, pestana SQL, pegar TODO y ejecutar.
--   La primera sentencia guarda la base en @db (ver db/migrations/README.md y la
--   028). Para no depender de la base seleccionada, cambia la primera linea por:
--     SET @db := 'smhynzte_002';
--   El resultado (la ultima consulta) es una fila: base, cajas (total de cajas de
--   la empresa) y caja_1 (cuantas se llaman "Caja 1"). Despues de correrla,
--   cajas es al menos 1; caja_1 es 1 si la empresa no tenia ninguna caja.
--
-- SI SALE "#1049 Unknown database 'ALTO_elige_la_base_...'" (o "#1146 Table
-- '<base>.pos_cajas' doesn't exist"): phpMyAdmin estaba parado en otra base, o la
-- empresa no tiene la migracion 030. NO se cambio nada. Haz clic en el nombre de la
-- base correcta en el panel IZQUIERDO, abre la pestana SQL de nuevo, pega todo y
-- ejecuta. Si dice que falta pos_cajas, corre antes la 030 en esa base.
--
-- PARA DESHACERLA (solo si hiciera falta): borra por id la fila que esta corrida
-- creo (la de id mas alto de pos_cajas, si el resultado dijo caja_1 = 1 y la
-- empresa no tenia cajas), siempre que ningun turno la use:
--   DELETE FROM pos_cajas WHERE id = <id de esa fila>;
-- No borres por nombre: una empresa puede haber creado su propia "Caja 1" antes.
-- Si la caja ya tiene turnos, desactivala desde Punto de venta > Cajas y equipos.
--
-- ORDEN: independiente del codigo del API (no hay nada que subir antes ni despues).
-- Necesita la 030 en la base.
-- ============================================================================

SET @db := DATABASE();

-- Guardia: la base seleccionada tiene que ser la correcta. Si no lo es, esta
-- sentencia falla con un nombre de tabla que dice que hacer, ANTES de tocar
-- nada. No consulta information_schema: phpMyAdmin no cambia de base aqui.
SET @guardia := IF(@db IS NULL OR @db IN ('information_schema', 'mysql', 'performance_schema', 'sys'),
  'DO (SELECT 1 FROM `ALTO_elige_la_base_de_la_empresa_en_el_panel`.`x` LIMIT 1)',
  CONCAT('DO (SELECT 1 FROM `', @db, '`.pos_cajas LIMIT 1)'));
PREPARE s_guardia FROM @guardia;
EXECUTE s_guardia;
DEALLOCATE PREPARE s_guardia;

-- La caja por defecto: solo si la empresa no tiene ninguna.
INSERT INTO pos_cajas (nombre)
SELECT 'Caja 1' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM pos_cajas);

-- Resultado (la ultima consulta, la que muestra phpMyAdmin).
SELECT @db AS base,
       (SELECT COUNT(*) FROM pos_cajas) AS cajas,
       (SELECT COUNT(*) FROM pos_cajas WHERE nombre = 'Caja 1') AS caja_1;
