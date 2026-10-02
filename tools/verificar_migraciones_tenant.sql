-- =============================================================================
-- verificar_migraciones_tenant.sql — SOLO LECTURA. No cambia nada.
--
-- Dice que migraciones de db/migrations/ (012 a 026) ya estan aplicadas en la
-- base de UN tenant. No existe una tabla que lleve ese registro (se corren a
-- mano), asi que cada fila busca en information_schema lo que su migracion deja
-- creado: una columna, una tabla, un indice o el tipo nuevo de una columna.
--
-- Uso: en phpMyAdmin entra a la base de la empresa (la lista de bases sale de
-- la ultima consulta de tools/verificar_migraciones_master.sql, columna
-- db_name) y ejecuta todo el archivo. Repite en la base de CADA empresa.
--
-- Cada fila dice APLICADA o FALTA. Corre SOLO las que dicen FALTA, en orden de
-- numero: varias (014, 016, 019, 020, 021, 023) fallan si se corren dos veces.
-- La 025 y la 026 si se pueden repetir sin dano.
--
-- Las de db/migrations/deprecated/ no hacen falta: son anteriores al snapshot.
-- Solo mira information_schema: funciona aunque falte una tabla entera.
-- =============================================================================

SELECT m.migracion, m.archivo, m.que_revisa,
       IF(m.ok, 'APLICADA', 'FALTA') AS estado
FROM (
  SELECT '012' AS migracion, '012_add_products.sql' AS archivo,
         'tabla products' AS que_revisa,
         EXISTS (SELECT 1 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products') AS ok
  UNION ALL
  SELECT '013', '013_add_proveedores.sql',
         'tabla proveedores',
         EXISTS (SELECT 1 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'proveedores')
  UNION ALL
  SELECT '014a', '014_add_factura_items_unidad_medida.sql',
         'columna factura_items.unidad_medida',
         EXISTS (SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'factura_items'
                   AND COLUMN_NAME = 'unidad_medida')
  UNION ALL
  SELECT '014b', '014_ncf_rangos_autorizados.sql',
         'columna ncf_sequences.no_autorizacion + indice uq_type_amb_desde',
         EXISTS (SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ncf_sequences'
                   AND COLUMN_NAME = 'no_autorizacion')
         AND EXISTS (SELECT 1 FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ncf_sequences'
                   AND INDEX_NAME = 'uq_type_amb_desde')
  UNION ALL
  SELECT '016', '016_add_gasto_items_unidad_medida.sql',
         'columna gasto_items.unidad_medida',
         EXISTS (SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gasto_items'
                   AND COLUMN_NAME = 'unidad_medida')
  UNION ALL
  SELECT '017', '017_add_inventory.sql',
         'tablas categories y warehouses + indice products.idx_products_warehouse',
         (SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('categories', 'warehouses')) = 2
         AND EXISTS (SELECT 1 FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'
                   AND INDEX_NAME = 'idx_products_warehouse')
  UNION ALL
  SELECT '018', '018_drop_clients_rnc_company_unique.sql',
         'clients sin indice UNIQUE en rnc ni en company_name',
         EXISTS (SELECT 1 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clients')
         AND NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clients'
                   AND COLUMN_NAME IN ('rnc', 'company_name') AND NON_UNIQUE = 0)
  UNION ALL
  SELECT '019', '019_add_product_precios.sql',
         'columna products.precio_4',
         EXISTS (SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'
                   AND COLUMN_NAME = 'precio_4')
  UNION ALL
  SELECT '020', '020_add_clients_descuento_credito.sql',
         'columna clients.permitir_credito',
         EXISTS (SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clients'
                   AND COLUMN_NAME = 'permitir_credito')
  UNION ALL
  SELECT '021', '021_add_tipo_pago_descuento_factura.sql',
         'columnas facturas.tipo_pago y factura_items.descuento_monto',
         EXISTS (SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'facturas'
                   AND COLUMN_NAME = 'tipo_pago')
         AND EXISTS (SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'factura_items'
                   AND COLUMN_NAME = 'descuento_monto')
  UNION ALL
  SELECT '022', '022_add_inventory_movements.sql',
         'tablas inventory_adjustments e inventory_movements',
         (SELECT COUNT(*) FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME IN ('inventory_adjustments', 'inventory_movements')) = 2
  UNION ALL
  SELECT '023', '023_add_factura_items_product_id.sql',
         'columna factura_items.product_id',
         EXISTS (SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'factura_items'
                   AND COLUMN_NAME = 'product_id')
  UNION ALL
  SELECT '024', '024_add_gasto_items_product_id.sql',
         'columna gasto_items.product_id',
         EXISTS (SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gasto_items'
                   AND COLUMN_NAME = 'product_id')
  UNION ALL
  SELECT '025', '025_decimales_cantidades_precios.sql',
         'factura_items.quantity es decimal(12,3) y products.stock decimal(15,3)',
         EXISTS (SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'factura_items'
                   AND COLUMN_NAME = 'quantity' AND COLUMN_TYPE LIKE 'decimal(12,3)%')
         AND EXISTS (SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'
                   AND COLUMN_NAME = 'stock' AND COLUMN_TYPE LIKE 'decimal(15,3)%')
  UNION ALL
  SELECT '026', '026_cotizaciones_formatos.sql',
         'tabla cotizacion_ajustes + columnas cotizaciones.numero y cotizacion_items.product_id',
         EXISTS (SELECT 1 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cotizacion_ajustes')
         AND EXISTS (SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cotizaciones'
                   AND COLUMN_NAME = 'numero')
         AND EXISTS (SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cotizacion_items'
                   AND COLUMN_NAME = 'product_id')
) m
ORDER BY m.migracion;
