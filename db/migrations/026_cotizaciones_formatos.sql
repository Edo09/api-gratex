-- ============================================================================
-- 026_cotizaciones_formatos.sql — Formatos de cotizacion por tenant (lineas del
-- catalogo, numeracion, cargos y abonos) + desfase entre snapshot y codigo.
-- ============================================================================
-- Para DBs de tenant YA desplegados: correr en la base de CADA empresa,
-- DESPUES de la 025. Los tenants nuevos lo reciben via db/tenant_schema.sql
-- (mismas columnas y mismos nombres de indices y FKs).
--
-- POR QUE:
--   1) La cotizacion era la de Gratex para todos los tenants: lineas de texto
--      libre, codigo aleatorio y el PDF de Gratex (con su cuenta de banco).
--      Ferreteria cotiza distinto ("COTIZACION MERCANCIAS"): lineas del
--      catalogo que al facturar mueven inventario, ITBIS por linea, numero
--      COT-000001 y cargos/abonos que no llevan ITBIS. Cada tenant elige su
--      formato en master.tenants.cotizacion_formato (migracion master 011) y
--      cada formato es codigo en src/Utils/Cotizacion/.
--   2) El snapshot se habia quedado atras del codigo: el modelo escribe
--      cotizaciones.user_id y updated_at, que el snapshot no tenia, y
--      client_name era NOT NULL sin default aunque el codigo nunca lo llena.
--      Una DB creada desde el snapshot (lo mas probable, la de Ferreteria) no
--      podia guardar ni una cotizacion.
--
-- QUE HACE (cada paso mira information_schema; no borra ni reescribe datos):
--   A) Desfase: agrega user_id y updated_at si faltan; agrega client_name si
--      falta y, si es NOT NULL sin default, lo vuelve NULL con un MODIFY que
--      copia tipo, charset, collation y comentario actuales (solo cambia la
--      nulabilidad).
--   B) cotizaciones: formato (NULL = gratex), numero (+ UNIQUE; los NULL no
--      chocan entre si), subtotal e itbis.
--   C) cotizacion_items: product_id (+ indice + FK a products ON DELETE SET
--      NULL, en UN solo ALTER como la 023, para que MySQL no cree un indice
--      duplicado), unidad_medida, indicador_facturacion,
--      indicador_bien_servicio e itbis_amount. NULL = linea al estilo Gratex.
--   D) Tabla nueva cotizacion_ajustes: cargos, mano de obra, abono y retencion
--      de cada cotizacion, una fila por concepto (solo los que no son cero).
--   Los tipos de cotizacion_items.product_id y cotizacion_ajustes.cotizacion_id
--   se COPIAN de products.id y cotizaciones.id (signo incluido): la FK no se
--   crea si los tipos difieren, y la DDL de produccion puede no ser la del repo.
--
-- ANTES DE CORRER:
--   - Hazlo fuera de horario: agregar la FK reconstruye cotizacion_items y
--     bloquea escrituras mientras corre (segundos en tablas chicas); los demas
--     ALTER son igual de cortos.
--   - Corre las consultas del paso 0 y revisa que den lo esperado. Si alguna
--     tabla NO sale InnoDB, para y avisa: MyISAM ignora las FK en silencio.
--   - Quita el modulo `cotizaciones` de los roles de Ferreteria hasta activar
--     su formato: con esta migracion su DB ya puede guardar cotizaciones, y
--     mientras el tenant siga en 'gratex' saldrian con el formato de Gratex.
--
-- ORDEN DE DESPLIEGUE: correr la master 011 y esta 026 en CADA tenant, completas,
-- ANTES de subir el codigo nuevo (api-gratex y despues fiscalo). Las
-- cotizaciones de Gratex no dependen de estas columnas (el codigo las lee con
-- SELECT * y solo el formato ferreteria las nombra), pero un tenant solo se pasa
-- a 'ferreteria' cuando su DB ya tiene la 026.
--
-- Se puede correr dos veces: si el cambio ya esta, el paso ejecuta DO 0 y no
-- toca nada.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 0) Comprobaciones previas (solo lectura).
-- ----------------------------------------------------------------------------
-- 0a) Motor: las tres deben salir InnoDB.
SELECT TABLE_NAME, ENGINE
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('cotizaciones', 'cotizacion_items', 'products')
ORDER BY TABLE_NAME;

-- 0b) Tipos exactos que copian las FK nuevas (cotizaciones.id, products.id) y
--     como estan hoy las columnas del desfase. En una DB creada desde el repo:
--     los dos id = int(11), client_name varchar(100) IS_NULLABLE = NO, y quiza
--     sin fila para user_id / updated_at (se agregan en A).
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT,
       CHARACTER_SET_NAME, COLLATION_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND (
       (TABLE_NAME = 'cotizaciones' AND COLUMN_NAME IN ('id', 'client_name', 'user_id', 'updated_at'))
    OR (TABLE_NAME = 'products'     AND COLUMN_NAME = 'id')
  )
ORDER BY TABLE_NAME, COLUMN_NAME;

-- 0c) Cuantas cotizaciones hay (en la DB de Ferreteria se espera 0).
SELECT COUNT(*) AS cotizaciones FROM cotizaciones;

-- 0d) Opcional: ver la definicion real (produccion puede tener columnas que el
--     esquema del repo no lista; esta migracion solo toca las nombradas abajo).
--   SHOW CREATE TABLE cotizaciones;
--   SHOW CREATE TABLE cotizacion_items;
--   SHOW CREATE TABLE products;

-- ----------------------------------------------------------------------------
-- A) Desfase entre el snapshot y el codigo.
-- ----------------------------------------------------------------------------
-- A1) user_id: el modelo lo escribe al crear y al editar. Referencia a
--     gratex_master.users.id, sin FK cross-DB (igual que facturas.user_id).
SET @has_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'cotizaciones'
    AND COLUMN_NAME = 'user_id'
);
SET @sql_user_id := IF(@has_col = 0,
  'ALTER TABLE cotizaciones ADD COLUMN user_id INT(11) NULL DEFAULT NULL COMMENT ''Referencia a gratex_master.users.id (sin FK cross-DB)'' AFTER total',
  'DO 0');
PREPARE s_user_id FROM @sql_user_id;
EXECUTE s_user_id;
DEALLOCATE PREPARE s_user_id;

-- A2) updated_at: lo escribe la edicion.
SET @has_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'cotizaciones'
    AND COLUMN_NAME = 'updated_at'
);
SET @sql_updated_at := IF(@has_col = 0,
  'ALTER TABLE cotizaciones ADD COLUMN updated_at DATETIME NULL DEFAULT NULL AFTER user_id',
  'DO 0');
PREPARE s_updated_at FROM @sql_updated_at;
EXECUTE s_updated_at;
DEALLOCATE PREPARE s_updated_at;

-- A3) client_name, si no existe (las lecturas la toman de clients por JOIN; el
--     formato ferreteria la llena con el nombre del cliente).
SET @has_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'cotizaciones'
    AND COLUMN_NAME = 'client_name'
);
SET @sql_client_name_add := IF(@has_col = 0,
  'ALTER TABLE cotizaciones ADD COLUMN client_name VARCHAR(100) NULL DEFAULT NULL AFTER client_id',
  'DO 0');
PREPARE s_client_name_add FROM @sql_client_name_add;
EXECUTE s_client_name_add;
DEALLOCATE PREPARE s_client_name_add;

-- A4) client_name NOT NULL sin default: el INSERT de Gratex no la nombra, asi
--     que en modo estricto la cotizacion no se guarda. El MODIFY se arma con la
--     definicion actual (tipo, charset, collation, comentario) para que SOLO
--     cambie la nulabilidad; el ancho y la collation de produccion se respetan.
SET @cn_arreglar := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'cotizaciones'
    AND COLUMN_NAME = 'client_name'
    AND IS_NULLABLE = 'NO'
    AND COLUMN_DEFAULT IS NULL
);
SET @cn_definicion := (
  SELECT CONCAT(
           COLUMN_TYPE,
           IF(CHARACTER_SET_NAME IS NULL, '', CONCAT(' CHARACTER SET ', CHARACTER_SET_NAME)),
           IF(COLLATION_NAME IS NULL, '', CONCAT(' COLLATE ', COLLATION_NAME)),
           ' NULL DEFAULT NULL',
           IF(COLUMN_COMMENT = '', '', CONCAT(' COMMENT ', QUOTE(COLUMN_COMMENT)))
         )
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'cotizaciones'
    AND COLUMN_NAME = 'client_name'
);
SET @sql_client_name_null := IF(@cn_arreglar = 1,
  CONCAT('ALTER TABLE cotizaciones MODIFY client_name ', @cn_definicion),
  'DO 0');
PREPARE s_client_name_null FROM @sql_client_name_null;
EXECUTE s_client_name_null;
DEALLOCATE PREPARE s_client_name_null;

-- ----------------------------------------------------------------------------
-- B) cotizaciones: columnas del formato.
-- ----------------------------------------------------------------------------
-- B1) formato: NULL = gratex (todas las cotizaciones que ya existen).
SET @has_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'cotizaciones'
    AND COLUMN_NAME = 'formato'
);
SET @sql_formato := IF(@has_col = 0,
  'ALTER TABLE cotizaciones ADD COLUMN formato VARCHAR(40) NULL DEFAULT NULL COMMENT ''Formato de cotizacion (src/Utils/Cotizacion/), NULL = gratex'' AFTER code',
  'DO 0');
PREPARE s_formato FROM @sql_formato;
EXECUTE s_formato;
DEALLOCATE PREPARE s_formato;

-- B2) numero: consecutivo del tenant (COT-000001). Columna + UNIQUE en el mismo
--     ALTER; el UNIQUE admite cualquier cantidad de NULL (las de Gratex).
SET @has_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'cotizaciones'
    AND COLUMN_NAME = 'numero'
);
SET @sql_numero := IF(@has_col = 0,
  'ALTER TABLE cotizaciones ADD COLUMN numero INT UNSIGNED NULL DEFAULT NULL COMMENT ''Consecutivo del tenant (COT-000001), NULL = codigo aleatorio de Gratex'' AFTER formato, ADD UNIQUE KEY uk_cotizaciones_numero (numero)',
  'DO 0');
PREPARE s_numero FROM @sql_numero;
EXECUTE s_numero;
DEALLOCATE PREPARE s_numero;

-- B2b) Si numero ya existia sin su UNIQUE (cambio hecho a mano), completarlo.
SET @has_idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'cotizaciones'
    AND INDEX_NAME = 'uk_cotizaciones_numero'
);
SET @sql_numero_uk := IF(@has_idx = 0,
  'ALTER TABLE cotizaciones ADD UNIQUE KEY uk_cotizaciones_numero (numero)',
  'DO 0');
PREPARE s_numero_uk FROM @sql_numero_uk;
EXECUTE s_numero_uk;
DEALLOCATE PREPARE s_numero_uk;

-- B3) subtotal (antes de ITBIS) e itbis: los guarda el formato; Gratex no.
SET @has_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'cotizaciones'
    AND COLUMN_NAME = 'subtotal'
);
SET @sql_subtotal := IF(@has_col = 0,
  'ALTER TABLE cotizaciones ADD COLUMN subtotal DECIMAL(18,2) NULL DEFAULT NULL COMMENT ''Antes de ITBIS, NULL en cotizaciones de Gratex'' AFTER client_name',
  'DO 0');
PREPARE s_subtotal FROM @sql_subtotal;
EXECUTE s_subtotal;
DEALLOCATE PREPARE s_subtotal;

SET @has_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'cotizaciones'
    AND COLUMN_NAME = 'itbis'
);
SET @sql_itbis := IF(@has_col = 0,
  'ALTER TABLE cotizaciones ADD COLUMN itbis DECIMAL(18,2) NULL DEFAULT NULL COMMENT ''Suma del ITBIS de las lineas, NULL en cotizaciones de Gratex'' AFTER subtotal',
  'DO 0');
PREPARE s_itbis FROM @sql_itbis;
EXECUTE s_itbis;
DEALLOCATE PREPARE s_itbis;

-- ----------------------------------------------------------------------------
-- C) cotizacion_items: la linea sabe que producto es (como factura_items, 023).
--    NULL = linea libre o linea de Gratex. ON DELETE SET NULL: borrar un
--    producto no rompe cotizaciones guardadas; la linea conserva su texto.
-- ----------------------------------------------------------------------------
-- C1) product_id + indice + FK en UN ALTER. El tipo se copia de products.id.
--     Si products no existe (falta la 012) este paso falla al preparar: es lo
--     correcto, no hay a que apuntar.
SET @tipo_product_id := (
  SELECT COLUMN_TYPE FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'products'
    AND COLUMN_NAME = 'id'
);
SET @has_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'cotizacion_items'
    AND COLUMN_NAME = 'product_id'
);
SET @sql_product := IF(@has_col = 0,
  CONCAT(
    'ALTER TABLE cotizacion_items ',
    'ADD COLUMN product_id ', @tipo_product_id, ' NULL DEFAULT NULL ',
      'COMMENT ''FK al catalogo, NULL = linea libre o de Gratex'' AFTER cotizacion_id, ',
    'ADD KEY idx_cotizacion_items_product (product_id), ',
    'ADD CONSTRAINT cotizacion_items_product_fk ',
      'FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE SET NULL'),
  'DO 0');
PREPARE s_product FROM @sql_product;
EXECUTE s_product;
DEALLOCATE PREPARE s_product;

-- C1b) Si product_id ya existia sin su indice o sin su FK (cambio hecho a
--      mano), completarlos. Primero el indice: asi la FK lo usa y MySQL no
--      crea otro con el nombre de la FK.
SET @has_idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'cotizacion_items'
    AND INDEX_NAME = 'idx_cotizacion_items_product'
);
SET @sql_product_idx := IF(@has_idx = 0,
  'ALTER TABLE cotizacion_items ADD KEY idx_cotizacion_items_product (product_id)',
  'DO 0');
PREPARE s_product_idx FROM @sql_product_idx;
EXECUTE s_product_idx;
DEALLOCATE PREPARE s_product_idx;

SET @has_fk := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'cotizacion_items'
    AND CONSTRAINT_NAME = 'cotizacion_items_product_fk'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @sql_product_fk := IF(@has_fk = 0,
  'ALTER TABLE cotizacion_items ADD CONSTRAINT cotizacion_items_product_fk FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE SET NULL',
  'DO 0');
PREPARE s_product_fk FROM @sql_product_fk;
EXECUTE s_product_fk;
DEALLOCATE PREPARE s_product_fk;

-- C2) unidad_medida: codigo DGII de la unidad (= master unidades_medida.id,
--     ej. '43'), igual que factura_items; nunca el `codigo` abreviado.
SET @has_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'cotizacion_items'
    AND COLUMN_NAME = 'unidad_medida'
);
SET @sql_unidad := IF(@has_col = 0,
  'ALTER TABLE cotizacion_items ADD COLUMN unidad_medida VARCHAR(10) NULL DEFAULT NULL COMMENT ''Codigo de unidad DGII (ej. 43 = Unidad), NULL = linea de Gratex'' AFTER subtotal',
  'DO 0');
PREPARE s_unidad FROM @sql_unidad;
EXECUTE s_unidad;
DEALLOCATE PREPARE s_unidad;

-- C3) indicador_facturacion: tasa de ITBIS de la linea.
SET @has_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'cotizacion_items'
    AND COLUMN_NAME = 'indicador_facturacion'
);
SET @sql_ind_fact := IF(@has_col = 0,
  'ALTER TABLE cotizacion_items ADD COLUMN indicador_facturacion TINYINT NULL DEFAULT NULL COMMENT ''1=ITBIS 18% | 2=ITBIS 16% | 3=ITBIS 0% | 4=Exento, NULL = linea de Gratex'' AFTER unidad_medida',
  'DO 0');
PREPARE s_ind_fact FROM @sql_ind_fact;
EXECUTE s_ind_fact;
DEALLOCATE PREPARE s_ind_fact;

-- C4) indicador_bien_servicio: lo pide el e-CF al convertir en factura.
SET @has_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'cotizacion_items'
    AND COLUMN_NAME = 'indicador_bien_servicio'
);
SET @sql_ind_bs := IF(@has_col = 0,
  'ALTER TABLE cotizacion_items ADD COLUMN indicador_bien_servicio TINYINT NULL DEFAULT NULL COMMENT ''1=Bien | 2=Servicio, NULL = linea de Gratex'' AFTER indicador_facturacion',
  'DO 0');
PREPARE s_ind_bs FROM @sql_ind_bs;
EXECUTE s_ind_bs;
DEALLOCATE PREPARE s_ind_bs;

-- C5) itbis_amount: ITBIS calculado de la linea.
SET @has_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'cotizacion_items'
    AND COLUMN_NAME = 'itbis_amount'
);
SET @sql_itbis_linea := IF(@has_col = 0,
  'ALTER TABLE cotizacion_items ADD COLUMN itbis_amount DECIMAL(18,2) NULL DEFAULT NULL COMMENT ''ITBIS de la linea, NULL = linea de Gratex'' AFTER indicador_bien_servicio',
  'DO 0');
PREPARE s_itbis_linea FROM @sql_itbis_linea;
EXECUTE s_itbis_linea;
DEALLOCATE PREPARE s_itbis_linea;

-- ----------------------------------------------------------------------------
-- D) cotizacion_ajustes: montos fuera de las lineas (cargos bancarios, mano de
--    obra, abono, retencion...). Cada formato declara que conceptos acepta y
--    solo guarda los que no son cero. El tipo de cotizacion_id se copia de
--    cotizaciones.id. El UNIQUE (cotizacion_id, concepto) sirve de indice a la
--    FK, asi que no hace falta otro.
-- ----------------------------------------------------------------------------
SET @tipo_cot_id := (
  SELECT COLUMN_TYPE FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'cotizaciones'
    AND COLUMN_NAME = 'id'
);
SET @has_tabla := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'cotizacion_ajustes'
);
SET @sql_ajustes := IF(@has_tabla = 0,
  CONCAT(
    'CREATE TABLE IF NOT EXISTS cotizacion_ajustes (',
      'id INT(11) NOT NULL AUTO_INCREMENT, ',
      'cotizacion_id ', @tipo_cot_id, ' NOT NULL, ',
      'concepto VARCHAR(30) NOT NULL COMMENT ''Clave definida por el formato (ej. mano_obra, abono)'', ',
      'monto DECIMAL(18,2) NOT NULL DEFAULT 0.00, ',
      'PRIMARY KEY (id), ',
      'UNIQUE KEY uk_cotizacion_ajuste (cotizacion_id, concepto), ',
      'CONSTRAINT cotizacion_ajustes_cot_fk FOREIGN KEY (cotizacion_id) ',
        'REFERENCES cotizaciones (id) ON DELETE CASCADE',
    ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'),
  'DO 0');
PREPARE s_ajustes FROM @sql_ajustes;
EXECUTE s_ajustes;
DEALLOCATE PREPARE s_ajustes;

-- ----------------------------------------------------------------------------
-- E) Verificacion.
-- ----------------------------------------------------------------------------
-- E1) 16 filas: las 7 de cotizaciones, las 5 de cotizacion_items y las 4 de
--     cotizacion_ajustes. client_name y todas las nuevas de cotizaciones y
--     cotizacion_items con IS_NULLABLE = YES; product_id y
--     cotizacion_ajustes.cotizacion_id con el mismo COLUMN_TYPE que salio en 0b.
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND (
       (TABLE_NAME = 'cotizaciones'       AND COLUMN_NAME IN ('client_name', 'user_id', 'updated_at', 'formato', 'numero', 'subtotal', 'itbis'))
    OR (TABLE_NAME = 'cotizacion_items'   AND COLUMN_NAME IN ('product_id', 'unidad_medida', 'indicador_facturacion', 'indicador_bien_servicio', 'itbis_amount'))
    OR (TABLE_NAME = 'cotizacion_ajustes' AND COLUMN_NAME IN ('id', 'cotizacion_id', 'concepto', 'monto'))
  )
ORDER BY TABLE_NAME, ORDINAL_POSITION;

-- E2) 3 indices: uk_cotizaciones_numero (NON_UNIQUE 0),
--     idx_cotizacion_items_product (1) y uk_cotizacion_ajuste (0).
SELECT DISTINCT TABLE_NAME, INDEX_NAME, NON_UNIQUE
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND INDEX_NAME IN ('uk_cotizaciones_numero', 'idx_cotizacion_items_product', 'uk_cotizacion_ajuste')
ORDER BY TABLE_NAME, INDEX_NAME;

-- E3) 2 FKs: cotizacion_items_product_fk -> products (SET NULL) y
--     cotizacion_ajustes_cot_fk -> cotizaciones (CASCADE).
SELECT CONSTRAINT_NAME, TABLE_NAME, REFERENCED_TABLE_NAME, DELETE_RULE
FROM information_schema.REFERENTIAL_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = DATABASE()
  AND CONSTRAINT_NAME IN ('cotizacion_items_product_fk', 'cotizacion_ajustes_cot_fk')
ORDER BY CONSTRAINT_NAME;
