-- ============================================================================
-- 017_add_inventory.sql — Modulo de Inventario: Categorias + Almacenes.
-- ============================================================================
-- Para DBs de tenant YA desplegados. Los tenants nuevos lo reciben via
-- db/tenant_schema.sql (refleja estas tablas + los FK en products).
--
-- Multi-tenant: cada tenant = una empresa = su propia DB, asi que estas tablas
-- NO llevan company_id; el aislamiento es por DB.
--
-- Integra products: migra el texto libre `products.categoria` a la tabla
-- `categories` (category_id FK), y agrega `warehouse_id` (FK, obligatorio) con
-- un almacen por defecto "Almacen Principal".
--
-- DDL auto-commit en MySQL 8: sin transaccion. Migracion de UNA sola corrida
-- (los ALTER ADD COLUMN no son re-ejecutables).
-- ============================================================================

-- 1) Categorias
CREATE TABLE IF NOT EXISTS categories (
  id          INT(11)      NOT NULL AUTO_INCREMENT,
  nombre      VARCHAR(100) NOT NULL,
  descripcion VARCHAR(255) NULL,
  estado      TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '1=activo | 0=inactivo',
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_cat_nombre (nombre),
  KEY idx_cat_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Almacenes
CREATE TABLE IF NOT EXISTS warehouses (
  id          INT(11)      NOT NULL AUTO_INCREMENT,
  nombre      VARCHAR(100) NOT NULL,
  descripcion VARCHAR(255) NULL,
  estado      TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '1=activo | 0=inactivo',
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_wh_nombre (nombre),
  KEY idx_wh_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) Almacen por defecto (idempotente)
INSERT INTO warehouses (nombre, descripcion)
SELECT 'Almacén Principal', 'Almacén por defecto de la empresa'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM warehouses WHERE nombre = 'Almacén Principal');

-- 4) Migrar el texto libre products.categoria -> categories (uk_cat_nombre evita dups)
INSERT IGNORE INTO categories (nombre)
SELECT DISTINCT TRIM(categoria)
FROM products
WHERE categoria IS NOT NULL AND TRIM(categoria) <> '';

-- 5) Nuevas columnas FK en products (nullable de momento, para backfill)
ALTER TABLE products
  ADD COLUMN category_id  INT(11) NULL AFTER descripcion,
  ADD COLUMN warehouse_id INT(11) NULL AFTER category_id;

-- 6) Backfill
UPDATE products p
  JOIN categories c ON c.nombre = TRIM(p.categoria)
   SET p.category_id = c.id
 WHERE p.categoria IS NOT NULL AND TRIM(p.categoria) <> '';

UPDATE products
   SET warehouse_id = (SELECT id FROM warehouses WHERE nombre = 'Almacén Principal' LIMIT 1)
 WHERE warehouse_id IS NULL;

-- 7) Quitar el texto libre legacy
ALTER TABLE products
  DROP INDEX idx_categoria,
  DROP COLUMN categoria;

-- 8) Restricciones: warehouse_id obligatorio + FKs + indices
ALTER TABLE products
  MODIFY warehouse_id INT(11) NOT NULL,
  ADD KEY idx_products_category (category_id),
  ADD KEY idx_products_warehouse (warehouse_id),
  ADD CONSTRAINT fk_products_category
    FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_products_warehouse
    FOREIGN KEY (warehouse_id) REFERENCES warehouses (id) ON DELETE RESTRICT;
