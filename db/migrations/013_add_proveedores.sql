-- ============================================================================
-- 013_add_proveedores.sql — Directorio de proveedores del tenant.
-- ============================================================================
-- Para DBs de tenant YA desplegados. Los tenants nuevos lo reciben vía
-- db/tenant_schema.sql (sección 12 — refleja exactamente este CREATE TABLE).
--
-- Los gastos/compras siguen guardando rnc_proveedor/nombre_proveedor inline
-- (desnormalizado); esta tabla es el directorio para autocompletar y gestionar
-- proveedores. El conteo de compras se deriva uniendo gastos por RNC.
--
-- Aplicado en mtldtmte_new_gratexdb el 2026-06-10.
-- ============================================================================

CREATE TABLE IF NOT EXISTS proveedores (
  id INT(11) NOT NULL AUTO_INCREMENT,
  rnc VARCHAR(11) NULL,
  nombre VARCHAR(150) NOT NULL,
  contacto VARCHAR(100) NULL,
  telefono VARCHAR(20) NULL,
  correo VARCHAR(100) NULL,
  direccion VARCHAR(150) NULL,
  notas VARCHAR(255) NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_rnc (rnc),
  KEY idx_activo (activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
