-- ============================================================================
-- products_demo.sql — Catálogo de ejemplo (OPCIONAL).
-- ============================================================================
-- Datos de demostración para la tabla `products` (mismo set que tenía el mock
-- del front fiscalo). NO se aplica automáticamente: córrelo a mano si quieres
-- poblar el catálogo (phpMyAdmin / cPanel / cliente MySQL).
--
-- indicador_bien_servicio: 1=Bien, 2=Servicio
-- indicador_facturacion:   1=ITBIS 18% (gravado), 4=Exento
-- Idempotente por `sku` (UNIQUE): reejecutar no duplica.
-- ============================================================================

INSERT INTO products
  (sku, nombre, categoria, indicador_bien_servicio, indicador_facturacion, precio, costo, stock, stock_minimo)
VALUES
  ('ALM-0451', 'Aceite Vegetal 1 Gal',            'Alimentos', 1, 1,  485.00,  360.00,  240,   50),
  ('ALM-0892', 'Arroz Selecto 25 lb',             'Alimentos', 1, 4, 1250.00,  980.00,   18,   40),
  ('LIM-1120', 'Detergente Industrial 5 Gal',     'Limpieza',  1, 1, 1890.00, 1420.00,   76,   20),
  ('BEB-0310', 'Agua Purificada 5 Gal',           'Bebidas',   1, 1,   95.00,   55.00,    0,  100),
  ('SRV-2001', 'Servicio de Entrega a Domicilio', 'Servicios', 2, 1,  350.00,    0.00, NULL, NULL),
  ('PAP-0540', 'Papel Higienico x12',             'Hogar',     1, 1,  420.00,  310.00,  156,   60),
  ('BEB-0455', 'Cafe Molido 1 lb',                'Bebidas',   1, 1,  580.00,  430.00,   34,   50),
  ('SRV-2014', 'Mantenimiento de Equipos',        'Servicios', 2, 1, 2500.00,    0.00, NULL, NULL)
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);
