-- =============================================================================
-- Master Migration 003: Roles y permisos (RBAC per-tenant).
--
-- Ejecutar contra la base MASTER (gratex_master), UNA sola vez.
-- Reflejado en db/master_schema.sql (instalaciones nuevas) y, para el fallback
-- single-tenant, en db/tenant_schema.sql.
--
-- Modelo: cada tenant tiene sus propios roles; users.role guarda el NOMBRE del
-- rol (string) y se resuelve a permisos por (tenant_id, name). El permiso es
-- ACCESO A MODULO: el nombre del modulo ('facturas','gastos',...) o '*' (todos).
-- El catalogo de modulos validos y el mapa ruta->modulo viven en codigo
-- (config/permissions.php). Aqui solo se guarda QUE permisos tiene cada rol.
--
-- Se siembran 2 roles de sistema por tenant (is_system=1, no borrables):
--   admin -> '*'          (acceso total)
--   user  -> operacion sin administracion (sin *.manage de admin)
-- Idempotente: re-ejecutar no duplica filas.
-- =============================================================================

USE gratex_master;

CREATE TABLE IF NOT EXISTS roles (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id   INT          NOT NULL,
  name        VARCHAR(40)  NOT NULL,
  description VARCHAR(150) NULL,
  is_system   TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'admin/user de sistema: no se pueden borrar',
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_role_tenant_name (tenant_id, name),
  KEY idx_roles_tenant (tenant_id),
  CONSTRAINT fk_roles_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  role_id    INT         NOT NULL,
  permission VARCHAR(60) NOT NULL COMMENT "modulo ('facturas','gastos',...) o '*' (todos)",
  UNIQUE KEY uq_role_perm (role_id, permission),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Seed: rol admin por tenant
-- ---------------------------------------------------------------------------
-- Solo tenants tipo "app": el RBAC aplica a usuarios de la app (login). Los
-- tenants "integracion" no tienen usuarios (auth por key+secret), no se siembran.
INSERT INTO roles (tenant_id, name, description, is_system)
SELECT t.id, 'admin', 'Acceso total dentro del tenant', 1
FROM tenants t
WHERE t.tipo = 'app'
  AND NOT EXISTS (SELECT 1 FROM roles r WHERE r.tenant_id = t.id AND r.name = 'admin');

INSERT INTO role_permissions (role_id, permission)
SELECT r.id, '*'
FROM roles r
WHERE r.name = 'admin'
  AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission = '*');

-- ---------------------------------------------------------------------------
-- Seed: rol user por tenant (operacion, sin administracion)
-- ---------------------------------------------------------------------------
INSERT INTO roles (tenant_id, name, description, is_system)
SELECT t.id, 'user', 'Operacion (sin gestion de usuarios, roles ni configuracion)', 1
FROM tenants t
WHERE t.tipo = 'app'
  AND NOT EXISTS (SELECT 1 FROM roles r WHERE r.tenant_id = t.id AND r.name = 'user');

INSERT INTO role_permissions (role_id, permission)
SELECT r.id, p.perm
FROM roles r
JOIN (
  SELECT 'facturas' AS perm
  UNION ALL SELECT 'facturas-simples'
  UNION ALL SELECT 'gastos'
  UNION ALL SELECT 'clients'
  UNION ALL SELECT 'products'
  UNION ALL SELECT 'proveedores'
  UNION ALL SELECT 'cotizaciones'
  UNION ALL SELECT 'aprobaciones'
  UNION ALL SELECT 'reportes'
  UNION ALL SELECT 'ncf'
  UNION ALL SELECT 'unidades'
) p
WHERE r.name = 'user'
  AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission = p.perm);
