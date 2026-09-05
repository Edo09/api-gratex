-- ============================================================================
-- 022_add_inventory_movements.sql — Modulo de Ajuste de inventario.
-- ============================================================================
-- Para DBs de tenant YA desplegados. Los tenants nuevos lo reciben via
-- db/tenant_schema.sql (refleja exactamente estas tablas).
--
-- CONTEXTO — por que un LEDGER y no una tabla de "ajustes":
-- Hasta hoy `products.stock` solo se escribia desde el formulario de producto:
-- ni la factura descontaba ni la compra sumaba. Este modulo introduce el
-- registro de movimientos que faltaba. El ajuste es el PRIMER tipo de
-- movimiento; el dia que la venta descuente y la compra sume, solo se insertan
-- filas con otro `tipo_movimiento` — sin migrar nada.
--
-- MODELO:
--   inventory_adjustments  = el documento (cabecera): motivo, nota, totales.
--   inventory_movements    = el libro de movimientos, Y a la vez las LINEAS del
--                            ajuste. No hay tabla de items aparte: cada linea
--                            del ajuste ES un movimiento, con su saldo antes y
--                            despues. Una tabla menos y un solo lugar donde
--                            vive la verdad del inventario.
--
-- DECISIONES tomadas y por que:
--   - Fecha = siempre ahora. No hay ajustes retroactivos, asi que el saldo
--     nunca hay que recalcular hacia atras.
--   - `warehouse_id` se guarda en el movimiento, pero el SALDO sigue siendo
--     `products.stock` (uno solo). Con varios almacenes, el saldo por almacen
--     se deriva sumando el ledger: es una consulta, no una migracion.
--   - El ajuste NO repisa `products.costo`. `costo_unitario` aqui solo VALORIZA
--     el movimiento (cuanto vale en pesos lo que entro o salio). Recalcular el
--     costo promedio es trabajo del modulo de compras.
--   - Un ajuste NO se edita ni se borra: se anula con un ajuste inverso
--     (`anula_a_id`). Un historial editable no es un historial.
--
-- Multi-tenant: cada tenant = su propia DB, asi que no hay company_id.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- Cabecera del ajuste (el documento que ve el usuario)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS inventory_adjustments (
  id            INT(11)      NOT NULL AUTO_INCREMENT,
  codigo        VARCHAR(20)  NOT NULL
                  COMMENT 'Consecutivo visible: AJ-000001',
  fecha         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                  COMMENT 'Siempre el momento de captura (no hay retroactivos)',
  motivo        VARCHAR(20)  NOT NULL
                  COMMENT 'CONTEO_FISICO | MERMA | DANO | ROBO | DEVOLUCION | ERROR_CAPTURA | ANULACION | OTRO',
  nota          VARCHAR(500) NULL
                  COMMENT 'Detalle libre: "conteo del 03/09, pasillo 4"',
  warehouse_id  INT(11)      NOT NULL,
  user_id       INT(11)      NULL
                  COMMENT 'Referencia a gratex_master.users.id (sin FK cross-DB)',
  -- Totales del documento, para no recalcularlos en cada listado.
  total_lineas  INT(11)         NOT NULL DEFAULT 0,
  total_valor   DECIMAL(18,2)   NOT NULL DEFAULT 0.00
                  COMMENT 'Suma con signo de valor_movimiento: el "Total RD$" de la pantalla',
  -- Anulacion: en vez de borrar, un ajuste inverso apunta al original.
  anula_a_id    INT(11)      NULL
                  COMMENT 'Este ajuste es la anulacion de aquel',
  anulado_por_id INT(11)     NULL
                  COMMENT 'Ajuste que anulo a este (se llena al anular)',
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_codigo (codigo),
  KEY idx_fecha (fecha),
  KEY idx_motivo (motivo),
  KEY idx_warehouse (warehouse_id),
  CONSTRAINT inv_adj_warehouse_fk FOREIGN KEY (warehouse_id) REFERENCES warehouses (id),
  CONSTRAINT inv_adj_anula_fk FOREIGN KEY (anula_a_id) REFERENCES inventory_adjustments (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Libro de movimientos de inventario (y lineas del ajuste)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS inventory_movements (
  id            INT(11)      NOT NULL AUTO_INCREMENT,
  product_id    INT(11)      NOT NULL,
  warehouse_id  INT(11)      NOT NULL,
  tipo_movimiento VARCHAR(20) NOT NULL DEFAULT 'AJUSTE'
                  COMMENT 'AJUSTE hoy. Manana: VENTA | COMPRA | DEVOLUCION | TRASLADO',
  -- Que documento lo origino. Con AJUSTE apunta a inventory_adjustments; el dia
  -- que la factura descuente stock, apuntara a facturas con el mismo esquema.
  referencia_tipo VARCHAR(20) NULL
                  COMMENT 'ajuste | factura | gasto',
  referencia_id INT(11)      NULL,
  -- Cantidad CON SIGNO: + entrada, - salida. Asi el saldo es un SUM() y no hay
  -- que interpretar un campo "tipo" para sumar o restar.
  cantidad      INT(11)      NOT NULL
                  COMMENT 'Con signo: positivo suma al stock, negativo resta',
  -- Foto del saldo antes y despues: hace el historial auditable por si solo,
  -- sin tener que reconstruirlo sumando todo el ledger.
  cantidad_anterior INT(11)  NOT NULL,
  cantidad_nueva    INT(11)  NOT NULL,
  costo_unitario DECIMAL(18,2) NOT NULL DEFAULT 0.00
                  COMMENT 'Costo con que se valoriza el movimiento (NO cambia products.costo)',
  valor_movimiento DECIMAL(18,2) NOT NULL DEFAULT 0.00
                  COMMENT 'cantidad * costo_unitario, con signo',
  user_id       INT(11)      NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_producto_fecha (product_id, created_at)
                  COMMENT 'Kardex de un producto en orden cronologico',
  KEY idx_referencia (referencia_tipo, referencia_id),
  KEY idx_tipo (tipo_movimiento),
  CONSTRAINT inv_mov_product_fk FOREIGN KEY (product_id) REFERENCES products (id),
  CONSTRAINT inv_mov_warehouse_fk FOREIGN KEY (warehouse_id) REFERENCES warehouses (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
