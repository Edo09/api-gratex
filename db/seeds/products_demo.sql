-- ============================================================================
-- products_demo.sql — Catálogo de ejemplo (OPCIONAL).
-- ============================================================================
-- Datos de demostración para la tabla `products`. NO se aplica automáticamente:
-- córrelo a mano si quieres poblar el catálogo (phpMyAdmin / cPanel / MySQL).
-- Requiere el esquema de inventario (categories/warehouses + FKs en products,
-- migración 017). Crea las categorías del set y asigna todo al "Almacén Principal".
--
-- indicador_bien_servicio: 1=Bien, 2=Servicio
-- indicador_facturacion:   1=ITBIS 18% (gravado), 4=Exento
-- Idempotente por `sku` (UNIQUE): reejecutar no duplica.
-- ============================================================================

-- Categorías del set demo (uk_cat_nombre evita duplicados)
INSERT IGNORE INTO categories (nombre) VALUES
  ('Alimentos'), ('Limpieza'), ('Bebidas'), ('Servicios'), ('Hogar');

-- Productos: category_id por nombre, warehouse_id = Almacén Principal.
INSERT INTO products
  (sku, nombre, category_id, warehouse_id, indicador_bien_servicio, indicador_facturacion, precio, costo, stock, stock_minimo)
SELECT v.sku, v.nombre,
       (SELECT id FROM categories WHERE nombre = v.categoria),
       (SELECT id FROM warehouses WHERE nombre = 'Almacén Principal'),
       v.ibs, v.ifact, v.precio, v.costo, v.stock, v.stock_minimo
FROM (
  SELECT 'ALM-0451' AS sku, 'Aceite Vegetal 1 Gal' AS nombre, 'Alimentos' AS categoria, 1 AS ibs, 1 AS ifact, 485.00 AS precio, 360.00 AS costo, 240 AS stock, 50 AS stock_minimo
  UNION ALL SELECT 'ALM-0892', 'Arroz Selecto 25 lb',             'Alimentos', 1, 4, 1250.00,  980.00,   18,   40
  UNION ALL SELECT 'LIM-1120', 'Detergente Industrial 5 Gal',     'Limpieza',  1, 1, 1890.00, 1420.00,   76,   20
  UNION ALL SELECT 'BEB-0310', 'Agua Purificada 5 Gal',           'Bebidas',   1, 1,   95.00,   55.00,    0,  100
  UNION ALL SELECT 'SRV-2001', 'Servicio de Entrega a Domicilio', 'Servicios', 2, 1,  350.00,    0.00, NULL, NULL
  UNION ALL SELECT 'PAP-0540', 'Papel Higienico x12',             'Hogar',     1, 1,  420.00,  310.00,  156,   60
  UNION ALL SELECT 'BEB-0455', 'Cafe Molido 1 lb',                'Bebidas',   1, 1,  580.00,  430.00,   34,   50
  UNION ALL SELECT 'SRV-2014', 'Mantenimiento de Equipos',        'Servicios', 2, 1, 2500.00,    0.00, NULL, NULL
) AS v
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);
