-- ============================================================================
-- 012_add_products.sql — Catálogo de productos/servicios del tenant.
-- ============================================================================
-- Para DBs de tenant YA desplegados. Los tenants nuevos lo reciben vía
-- db/tenant_schema.sql (sección 11 — refleja exactamente este CREATE TABLE).
--
-- `indicador_facturacion` alinea el producto con DGII y con factura_items:
--   1=ITBIS 18% (gravado) · 4=Exento · 2=16% · 3=Tasa cero · 0=No facturable.
-- `indicador_bien_servicio`: 1=Bien, 2=Servicio.
--
-- Aplicado en mtldtmte_new_gratexdb el 2026-06-09.
-- ============================================================================

CREATE TABLE IF NOT EXISTS products (
  id INT(11) NOT NULL AUTO_INCREMENT,
  sku VARCHAR(50) NULL,
  nombre VARCHAR(150) NOT NULL,
  descripcion VARCHAR(255) NULL,
  categoria VARCHAR(50) NULL,
  indicador_bien_servicio TINYINT NOT NULL DEFAULT 1
    COMMENT '1=Bien | 2=Servicio',
  indicador_facturacion TINYINT NOT NULL DEFAULT 1
    COMMENT '0=No facturable | 1=ITBIS 18% | 2=ITBIS 16% | 3=Tasa cero | 4=Exento (gravado=1, exento=4)',
  precio DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  costo DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  unidad_medida VARCHAR(10) NOT NULL DEFAULT '43'
    COMMENT 'Codigo de unidad de medida DGII (43 = unidad)',
  stock INT(11) NULL COMMENT 'NULL para servicios (sin inventario)',
  stock_minimo INT(11) NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_sku (sku),
  KEY idx_categoria (categoria),
  KEY idx_activo (activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
