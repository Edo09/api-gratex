-- ============================================================================
-- 025_decimales_cantidades_precios.sql — Cantidades con decimales y precio
-- unitario con 4 decimales en facturas, cotizaciones, compras e inventario.
-- ============================================================================
-- Para DBs de tenant YA desplegados: correr en la base de CADA empresa. Los
-- tenants nuevos lo reciben via db/tenant_schema.sql.
--
-- POR QUE:
--   1) La cantidad era INT(11): 1,5 metros de cable se guardaba como 2 y 0,4 kg
--      como 0. MySQL redondea en silencio (sin error, ni en modo estricto), asi
--      que la factura guardada e impresa no era la que se escribio, y el
--      inventario descontaba de mas o de menos.
--   2) El precio unitario era DECIMAL(10,2): con "precios incluyen ITBIS" el
--      e-CF firma el precio sin ITBIS con 4 decimales (RD$100 -> 84.7458,
--      PrecioUnitarioItem), pero se guardaba 84.75 y en papel precio x cantidad
--      no cuadraba con el valor de la linea.
--   3) subtotal y total DECIMAL(10,2) topan en 99,999,999.99: una factura mayor
--      fallaria al guardarse DESPUES de que la DGII ya la acepto.
--
-- QUE UNIDADES ADMITEN DECIMALES lo decide la master (migracion master 010,
-- unidades_medida.permite_decimales). Aqui solo se ensanchan las columnas: una
-- cantidad entera sigue guardandose igual (3 -> 3.000).
--
-- SEGURO CON LOS DATOS: ensanchar no pierde nada (3 -> 3.000, 84.75 ->
-- 84.7500). Lo que ya se redondeo antes no se recupera con esto.
--
-- ANTES DE CORRER:
--   - Hazlo fuera de horario: cada ALTER copia la tabla y bloquea escrituras
--     mientras corre (segundos en tablas chicas).
--   - Corre las consultas del paso 0 y revisa que den lo esperado.
--   - Corre la migracion master 010 antes o junto con esta, y sube el codigo
--     nuevo DESPUES de migrar todos los tenants.
--
-- Se puede correr dos veces: MODIFY con el mismo tipo no cambia nada.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 0) Comprobaciones previas (solo lectura).
-- ----------------------------------------------------------------------------
-- 0a) Rango: las cantidades de las LINEAS pasan a DECIMAL(12,3) (hasta
--     999,999,999.999): las cuatro primeras filas deben salir por debajo de
--     1,000,000,000. El stock y los movimientos pasan a DECIMAL(15,3), que cubre
--     todo lo que cabia en el INT de antes.
SELECT 'factura_items.quantity' AS columna, MAX(ABS(quantity)) AS maximo FROM factura_items
UNION ALL SELECT 'cotizacion_items.quantity', MAX(ABS(quantity)) FROM cotizacion_items
UNION ALL SELECT 'gasto_items.quantity', MAX(ABS(quantity)) FROM gasto_items
UNION ALL SELECT 'products.stock', MAX(ABS(stock)) FROM products
UNION ALL SELECT 'products.stock_minimo', MAX(ABS(stock_minimo)) FROM products
UNION ALL SELECT 'inventory_movements.cantidad', MAX(ABS(cantidad)) FROM inventory_movements
UNION ALL SELECT 'inventory_movements.cantidad_anterior', MAX(ABS(cantidad_anterior)) FROM inventory_movements
UNION ALL SELECT 'inventory_movements.cantidad_nueva', MAX(ABS(cantidad_nueva)) FROM inventory_movements;

-- 0b) Columnas que quedan NOT NULL: no debe haber filas en NULL (debe salir 0
--     en todas). Si alguna sale > 0, avisa antes de seguir.
SELECT 'facturas.total NULL' AS chequeo, COUNT(*) AS filas FROM facturas WHERE total IS NULL
UNION ALL SELECT 'cotizaciones.total NULL', COUNT(*) FROM cotizaciones WHERE total IS NULL
UNION ALL SELECT 'cotizacion_items NULL', COUNT(*) FROM cotizacion_items WHERE amount IS NULL OR quantity IS NULL OR subtotal IS NULL
UNION ALL SELECT 'factura_items NULL', COUNT(*) FROM factura_items WHERE amount IS NULL OR quantity IS NULL OR subtotal IS NULL
UNION ALL SELECT 'gasto_items NULL', COUNT(*) FROM gasto_items WHERE amount IS NULL OR quantity IS NULL;

-- 0c) Opcional: ver la definicion real de las tablas (la de produccion puede
--     tener columnas que el esquema del repo no lista; esta migracion solo toca
--     las columnas nombradas abajo, el resto queda igual).
--   SHOW CREATE TABLE cotizaciones;
--   SHOW CREATE TABLE cotizacion_items;

-- ----------------------------------------------------------------------------
-- 1) Lineas de factura (simples y e-CF) y total de la factura.
--    El e-CF admite 2 decimales en la cantidad (CantidadItem) y 4 en el precio
--    (PrecioUnitarioItem); la factura simple, hasta 3 en la cantidad.
-- ----------------------------------------------------------------------------
ALTER TABLE factura_items
  MODIFY amount   DECIMAL(18,4) NOT NULL
    COMMENT 'Precio unitario; hasta 4 decimales (PrecioUnitarioItem del e-CF)',
  MODIFY quantity DECIMAL(12,3) NOT NULL DEFAULT 1.000
    COMMENT 'Hasta 3 decimales si la unidad lo admite (el e-CF lleva 2)',
  MODIFY subtotal DECIMAL(18,2) NOT NULL;

ALTER TABLE facturas
  MODIFY total DECIMAL(18,2) NOT NULL DEFAULT 0.00;

-- ----------------------------------------------------------------------------
-- 2) Cotizaciones (se convierten en factura: mismas precisiones).
-- ----------------------------------------------------------------------------
ALTER TABLE cotizacion_items
  MODIFY amount   DECIMAL(18,4) NOT NULL,
  MODIFY quantity DECIMAL(12,3) NOT NULL DEFAULT 1.000,
  MODIFY subtotal DECIMAL(18,2) NOT NULL;

ALTER TABLE cotizaciones
  MODIFY total DECIMAL(18,2) NOT NULL DEFAULT 0.00;

-- ----------------------------------------------------------------------------
-- 3) Compras y gastos (alimentan el inventario).
-- ----------------------------------------------------------------------------
ALTER TABLE gasto_items
  MODIFY amount   DECIMAL(18,4) NOT NULL,
  MODIFY quantity DECIMAL(12,3) NOT NULL DEFAULT 1.000;

-- ----------------------------------------------------------------------------
-- 4) Inventario: existencia y libro de movimientos.
-- ----------------------------------------------------------------------------
-- DECIMAL(15,3) y no (12,3): el INT de antes llegaba a 2,147,483,647 y un
-- (12,3) topa en 999,999,999.999, asi que una existencia grande que hoy es
-- valida (hay un producto de pruebas con 1,000,000,000) haria fallar el ALTER.
ALTER TABLE products
  MODIFY stock        DECIMAL(15,3) NULL COMMENT 'NULL para servicios (sin inventario)',
  MODIFY stock_minimo DECIMAL(15,3) NULL;

ALTER TABLE inventory_movements
  MODIFY cantidad          DECIMAL(15,3) NOT NULL
    COMMENT 'Con signo: positivo suma al stock, negativo resta',
  MODIFY cantidad_anterior DECIMAL(15,3) NOT NULL,
  MODIFY cantidad_nueva    DECIMAL(15,3) NOT NULL;

-- ----------------------------------------------------------------------------
-- 5) Verificacion: todas deben salir con el tipo nuevo.
-- ----------------------------------------------------------------------------
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND (
       (TABLE_NAME = 'factura_items'       AND COLUMN_NAME IN ('amount', 'quantity', 'subtotal'))
    OR (TABLE_NAME = 'facturas'            AND COLUMN_NAME = 'total')
    OR (TABLE_NAME = 'cotizacion_items'    AND COLUMN_NAME IN ('amount', 'quantity', 'subtotal'))
    OR (TABLE_NAME = 'cotizaciones'        AND COLUMN_NAME = 'total')
    OR (TABLE_NAME = 'gasto_items'         AND COLUMN_NAME IN ('amount', 'quantity'))
    OR (TABLE_NAME = 'products'            AND COLUMN_NAME IN ('stock', 'stock_minimo'))
    OR (TABLE_NAME = 'inventory_movements' AND COLUMN_NAME IN ('cantidad', 'cantidad_anterior', 'cantidad_nueva'))
  )
ORDER BY TABLE_NAME, COLUMN_NAME;
