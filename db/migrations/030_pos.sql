-- ============================================================================
-- 030_pos.sql — tablas del POS (pos.fiscalpoint.com.do) en la DB del tenant.
-- ============================================================================
-- Para DBs de tenant YA desplegados: correr en la base de CADA empresa que vaya
-- a usar el POS (no estorba en las demas). Los tenants nuevos lo reciben via
-- db/tenant_schema.sql (mismas tablas, indices y FKs).
-- Diseno: docs/specs/pos.md §9.3. Va junto con master_migrations/012.
--
-- QUE AGREGA (no toca datos):
--   pos_cajas             puestos fisicos de cobro ("Caja 1"), cada uno con su gaveta.
--   pos_empleados         cajeros y supervisores. NO son usuarios del master: sin
--                         correo ni usuario, solo nombre, rol y PIN. El PIN se
--                         guarda como HMAC-SHA256 con un secreto del server
--                         (POS_PIN_PEPPER): UNIQUE por tenant, se busca al
--                         empleado por PIN en una consulta y un volcado de la DB
--                         sin el secreto no permite probar los 10^4 PINs
--                         (PIN de 4 digitos; el bloqueo progresivo vive en
--                         master.pos_equipos).
--   pos_sesiones          empleado entrado con PIN en un equipo.
--   pos_turnos            apertura (fondo) y cierre (conteo a ciegas por
--                         denominacion, esperado, diferencia). `abierto` es 1 o
--                         NULL para que el UNIQUE garantice UN turno abierto por
--                         caja y UNO por empleado (MySQL admite varios NULL).
--   pos_caja_movimientos  dinero del turno por forma de pago: VENTA y DEVOLUCION
--                         en v1; el tipo es texto para sumar RETIRO, ENTRADA y
--                         ABONO sin migrar.
--   product_barcodes      varios codigos de barras por producto, unicos en el tenant.
--   facturas + turno_id, pos_empleado_id, pos_idempotency_key, envio_pendiente.
--     La clave de idempotencia es UNIQUE: reintentar una venta devuelve la misma
--     factura y nunca gasta otro e-NCF.
--
-- COMO CORRERLA (phpMyAdmin): base del tenant, pestana SQL, pegar TODO y ejecutar.
--   La primera sentencia guarda la base en @db (ver db/migrations/README.md y la
--   028): las tablas se crean ANTES de cualquier consulta a information_schema y
--   el ALTER de facturas nombra la base explicitamente. Para no depender de la
--   base seleccionada, cambia la primera linea por:  SET @db := 'smhynzte_002';
--   El resultado (la ultima consulta) son 6 tablas y 4 columnas, todas en SI.
--
-- SI SALE "#1049 Unknown database 'ALTO_elige_la_base_...'" (o "#1109 Unknown table
-- '...' in information_schema"): phpMyAdmin estaba parado en otra base (pasa
-- despues de correr otra migracion: cualquier consulta a information_schema lo
-- deja ahi). NO se cambio nada. Haz clic en el nombre de la base correcta en el
-- panel IZQUIERDO, abre la pestana SQL de nuevo, pega todo y ejecuta.
--
-- Se puede correr dos veces: CREATE TABLE IF NOT EXISTS y el ALTER solo agrega
-- lo que falta.
-- ============================================================================

SET @db := DATABASE();

-- Guardia: la base seleccionada tiene que ser la correcta. Si no lo es, esta
-- sentencia falla con un nombre de tabla que dice que hacer, ANTES de tocar
-- nada. No consulta information_schema: phpMyAdmin no cambia de base aqui.
SET @guardia := IF(@db IS NULL OR @db IN ('information_schema', 'mysql', 'performance_schema', 'sys'),
  'DO (SELECT 1 FROM `ALTO_elige_la_base_de_la_empresa_en_el_panel`.`x` LIMIT 1)',
  CONCAT('DO (SELECT 1 FROM `', @db, '`.facturas LIMIT 1)'));
PREPARE s_guardia FROM @guardia;
EXECUTE s_guardia;
DEALLOCATE PREPARE s_guardia;

-- ----------------------------------------------------------------------------
-- 1) Tablas nuevas (antes de tocar information_schema).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS pos_cajas (
  id          INT(11)      NOT NULL AUTO_INCREMENT,
  nombre      VARCHAR(60)  NOT NULL,
  activa      TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_pos_caja_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pos_empleados (
  id               INT(11)      NOT NULL AUTO_INCREMENT,
  nombre           VARCHAR(80)  NOT NULL,
  rol              VARCHAR(12)  NOT NULL DEFAULT 'cajero' COMMENT 'cajero | supervisor',
  pin_hmac         CHAR(64)     NOT NULL COMMENT 'HMAC-SHA256(pin, POS_PIN_PEPPER + tenant). El PIN lo genera el sistema',
  pin_generado_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  activo           TINYINT(1)   NOT NULL DEFAULT 1,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_pos_empleado_pin (pin_hmac)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pos_sesiones (
  id           INT(11)   NOT NULL AUTO_INCREMENT,
  empleado_id  INT(11)   NOT NULL,
  equipo_id    INT(11)   NOT NULL COMMENT 'pos_equipos.id del master (sin FK entre bases)',
  token_hash   CHAR(64)  NOT NULL,
  created_at   DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ultimo_uso   DATETIME  NULL,
  cerrada_at   DATETIME  NULL COMMENT 'Bloqueo, salida, cambio de empleado o equipo revocado',
  PRIMARY KEY (id),
  UNIQUE KEY uk_pos_sesion_token (token_hash),
  KEY idx_pos_sesion_empleado (empleado_id),
  CONSTRAINT fk_pos_sesion_empleado FOREIGN KEY (empleado_id) REFERENCES pos_empleados (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pos_turnos (
  id                 INT(11)        NOT NULL AUTO_INCREMENT,
  caja_id            INT(11)        NOT NULL,
  empleado_id        INT(11)        NOT NULL,
  abierto_at         DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  fondo_inicial      DECIMAL(18,2)  NOT NULL DEFAULT 0.00,
  abierto            TINYINT(1)     NULL DEFAULT 1
                       COMMENT '1 = abierto; NULL = cerrado. Con los UNIQUE: un abierto por caja y uno por empleado',
  cerrado_at         DATETIME       NULL,
  cerrado_por        INT(11)        NULL COMMENT 'Empleado que conto: el del turno o un supervisor (turno ajeno)',
  conteo_json        TEXT           NULL COMMENT 'Conteo a ciegas por denominacion: {"2000":1,"1000":3,...,"otros":12.50}',
  efectivo_contado   DECIMAL(18,2)  NULL,
  efectivo_esperado  DECIMAL(18,2)  NULL COMMENT 'fondo + ventas en efectivo - devoluciones en efectivo',
  diferencia         DECIMAL(18,2)  NULL COMMENT 'contado - esperado: + sobra, - falta',
  totales_json       TEXT           NULL COMMENT 'Foto del reporte de cierre (por forma de pago, devoluciones, autorizaciones)',
  nota               VARCHAR(255)   NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_pos_turno_caja_abierto (caja_id, abierto),
  UNIQUE KEY uk_pos_turno_empleado_abierto (empleado_id, abierto),
  KEY idx_pos_turno_abierto_at (abierto_at),
  CONSTRAINT fk_pos_turno_caja     FOREIGN KEY (caja_id)     REFERENCES pos_cajas (id),
  CONSTRAINT fk_pos_turno_empleado FOREIGN KEY (empleado_id) REFERENCES pos_empleados (id),
  CONSTRAINT fk_pos_turno_cerro    FOREIGN KEY (cerrado_por) REFERENCES pos_empleados (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pos_caja_movimientos (
  id              INT(11)        NOT NULL AUTO_INCREMENT,
  turno_id        INT(11)        NOT NULL,
  factura_id      INT(11)        NULL,
  tipo            VARCHAR(12)    NOT NULL COMMENT 'VENTA | DEVOLUCION (luego RETIRO | ENTRADA | ABONO)',
  forma_pago      TINYINT        NOT NULL COMMENT 'Codigo DGII: 1 efectivo, 2 cheque/transferencia/deposito, 3 tarjeta',
  monto           DECIMAL(18,2)  NOT NULL COMMENT 'Positivo; el tipo dice si entra o sale',
  monto_recibido  DECIMAL(18,2)  NULL COMMENT 'Efectivo: con cuanto pago el cliente',
  devuelta        DECIMAL(18,2)  NULL,
  iniciada_at     DATETIME       NULL COMMENT 'Primer articulo de la venta: para medir el tiempo por venta',
  created_at      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pos_mov_turno (turno_id),
  KEY idx_pos_mov_factura (factura_id),
  CONSTRAINT fk_pos_mov_turno   FOREIGN KEY (turno_id)   REFERENCES pos_turnos (id),
  CONSTRAINT fk_pos_mov_factura FOREIGN KEY (factura_id) REFERENCES facturas (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_barcodes (
  id          INT(11)      NOT NULL AUTO_INCREMENT,
  product_id  INT(11)      NOT NULL,
  codigo      VARCHAR(64)  NOT NULL,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_product_barcode (codigo),
  KEY idx_product_barcode_product (product_id),
  CONSTRAINT fk_product_barcode_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 2) facturas: las 4 columnas del POS, solo las que falten (un solo ALTER).
-- ----------------------------------------------------------------------------
SET @f_turno := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'facturas' AND COLUMN_NAME = 'turno_id');
SET @f_empleado := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'facturas' AND COLUMN_NAME = 'pos_empleado_id');
SET @f_idem := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'facturas' AND COLUMN_NAME = 'pos_idempotency_key');
SET @f_pend := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'facturas' AND COLUMN_NAME = 'envio_pendiente');

SET @partes := CONCAT_WS(', ',
  IF(@f_turno = 0, 'ADD COLUMN turno_id INT(11) NULL COMMENT ''pos_turnos.id: venta hecha en el POS'', ADD KEY idx_facturas_turno (turno_id)', NULL),
  IF(@f_empleado = 0, 'ADD COLUMN pos_empleado_id INT(11) NULL COMMENT ''pos_empleados.id que cobro (user_id es el usuario del master)''', NULL),
  IF(@f_idem = 0, 'ADD COLUMN pos_idempotency_key CHAR(36) NULL COMMENT ''Clave del intento de venta del POS: reintentar devuelve la misma factura'', ADD UNIQUE KEY uk_facturas_pos_idem (pos_idempotency_key)', NULL),
  IF(@f_pend = 0, 'ADD COLUMN envio_pendiente TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = firmada e impresa, la DGII no respondio a tiempo: se reintenta sola''', NULL)
);
SET @sql_030 := IF(@partes IS NULL OR @partes = '',
  'DO 0',
  CONCAT('ALTER TABLE `', @db, '`.facturas ', @partes));
PREPARE s_030 FROM @sql_030;
EXECUTE s_030;
DEALLOCATE PREPARE s_030;

-- ----------------------------------------------------------------------------
-- Resultado (la ultima consulta, la que muestra phpMyAdmin).
-- ----------------------------------------------------------------------------
SELECT @db AS base, 'tabla' AS que, t.n AS nombre,
       IF(EXISTS (SELECT 1 FROM information_schema.TABLES
                   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = t.n), 'SI', 'NO') AS ok
FROM (SELECT 'pos_cajas' AS n UNION ALL SELECT 'pos_empleados' UNION ALL SELECT 'pos_sesiones'
      UNION ALL SELECT 'pos_turnos' UNION ALL SELECT 'pos_caja_movimientos' UNION ALL SELECT 'product_barcodes') t
UNION ALL
SELECT @db, 'facturas.', c.n,
       IF(EXISTS (SELECT 1 FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'facturas' AND COLUMN_NAME = c.n), 'SI', 'NO')
FROM (SELECT 'turno_id' AS n UNION ALL SELECT 'pos_empleado_id' UNION ALL SELECT 'pos_idempotency_key'
      UNION ALL SELECT 'envio_pendiente') c;
