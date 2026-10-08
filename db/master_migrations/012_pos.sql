-- =============================================================================
-- Master Migration 012: POS (pos.fiscalpoint.com.do).
--
-- Ejecutar contra la base MASTER, UNA vez. Idempotente: se puede correr dos
-- veces sin error, y la segunda no toca nada.
-- Reflejado en db/master_schema.sql (instalaciones nuevas).
--
-- NOTA: el nombre de la master DB cambia por entorno. Selecciona la base ANTES
-- de correr esto: clic en su nombre en el panel IZQUIERDO de phpMyAdmin.
--
-- SI SALE "#1049 Unknown database 'ALTO_elige_la_base_...'" (o "#1109 Unknown table
-- '...' in information_schema"): phpMyAdmin estaba parado en otra base (pasa
-- despues de correr otra migracion: cualquier consulta a information_schema lo
-- deja ahi). NO se cambio nada. Haz clic en el nombre de la base correcta en el
-- panel IZQUIERDO, abre la pestana SQL de nuevo, pega todo y ejecuta.
--
-- POR QUE ESTE ORDEN (ver db/migrations/README.md y la 028): la base va en @db
-- desde la primera sentencia; las tablas se crean ANTES de cualquier consulta a
-- information_schema (despues de una, phpMyAdmin cambia la base actual), y los
-- ALTER posteriores nombran la base explicitamente.
--
-- QUE AGREGA (ver docs/specs/pos.md §9.2 y §9.3):
--   1. tenants.pos_enabled — el POS se activa por tenant, por SQL (como
--      cotizacion_formato). Default 0: nadie lo ve hasta que se active.
--   2. pos_handoff_codes — codigo de un solo uso (60 s) con el que el boton POS
--      de app.* abre pos.* ya autenticado. Va en el master porque el canje es
--      publico: hay que resolver el tenant ANTES de tocar su DB. Solo se guarda
--      el sha256 del codigo.
--   3. pos_equipos — PCs de caja habilitadas por un admin. El token de equipo
--      resuelve tenant + caja (como api_tokens resuelve tenant + usuario) y solo
--      abre /api/pos/*. El contador de PIN fallidos vive aqui, en el server: un
--      bloqueo en el navegador se borra recargando. El bloqueo es progresivo
--      (5 fallos -> 5 min, luego 10, 20... hasta un dia): el PIN es de 4 digitos.
--
-- No hay permiso que sembrar: el modulo RBAC `pos` es de administracion
-- (config/permissions.php) y el rol admin ya tiene '*'. Para darselo a otro rol:
--   INSERT INTO role_permissions (role_id, permission) VALUES (<role_id>, 'pos');
--
-- Activar el POS a un tenant (solo tipo 'app'; los de integracion no tienen DB):
--   UPDATE tenants SET pos_enabled = 1 WHERE id = <id> AND tipo = 'app';
--
-- ORDEN: esta migracion y la 030 del tenant ANTES de subir el codigo del POS.
-- =============================================================================

SET @db := DATABASE();

-- Guardia: la base seleccionada tiene que ser la correcta. Si no lo es, esta
-- sentencia falla con un nombre de tabla que dice que hacer, ANTES de tocar
-- nada. No consulta information_schema: phpMyAdmin no cambia de base aqui.
SET @guardia := IF(@db IS NULL OR @db IN ('information_schema', 'mysql', 'performance_schema', 'sys'),
  'DO (SELECT 1 FROM `ALTO_elige_la_base_master_en_el_panel`.`x` LIMIT 1)',
  CONCAT('DO (SELECT 1 FROM `', @db, '`.tenants LIMIT 1)'));
PREPARE s_guardia FROM @guardia;
EXECUTE s_guardia;
DEALLOCATE PREPARE s_guardia;

-- 1) Codigos de traspaso app.* -> pos.*
CREATE TABLE IF NOT EXISTS pos_handoff_codes (
  id          INT          NOT NULL AUTO_INCREMENT,
  code_hash   CHAR(64)     NOT NULL COMMENT 'sha256 del codigo; el codigo en claro nunca se guarda',
  user_id     INT          NOT NULL COMMENT 'Admin que pulso el boton POS',
  tenant_id   INT          NOT NULL,
  expira_at   DATETIME     NOT NULL COMMENT '60 s despues de creado',
  usado_at    DATETIME     NULL     COMMENT 'Un solo uso: lleno = ya se canjeo',
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pos_handoff_code (code_hash),
  KEY idx_pos_handoff_expira (expira_at),
  CONSTRAINT fk_pos_handoff_user   FOREIGN KEY (user_id)   REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_pos_handoff_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2) Equipos de caja habilitados
CREATE TABLE IF NOT EXISTS pos_equipos (
  id                 INT          NOT NULL AUTO_INCREMENT,
  tenant_id          INT          NOT NULL,
  caja_id            INT          NOT NULL COMMENT 'pos_cajas.id en la DB del tenant (sin FK entre bases)',
  token_hash         CHAR(64)     NOT NULL COMMENT 'sha256 del token de equipo',
  nombre             VARCHAR(80)  NULL     COMMENT 'Etiqueta: navegador y sistema del equipo al habilitarlo',
  habilitado_por     INT          NULL     COMMENT 'users.id del admin que lo habilito',
  created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used          DATETIME     NULL,
  revocado_at        DATETIME     NULL     COMMENT 'Lleno = el token ya no abre nada',
  intentos_fallidos  INT          NOT NULL DEFAULT 0 COMMENT 'PIN fallidos seguidos en este equipo',
  bloqueado_hasta    DATETIME     NULL     COMMENT '5 fallos seguidos -> sin aceptar PIN hasta esta hora',
  bloqueos_seguidos  INT          NOT NULL DEFAULT 0 COMMENT 'Bloqueos sin un PIN valido en medio: cada uno dura el doble (5, 10, 20... min, tope 1 dia)',
  PRIMARY KEY (id),
  UNIQUE KEY uq_pos_equipo_token (token_hash),
  KEY idx_pos_equipo_tenant_caja (tenant_id, caja_id),
  CONSTRAINT fk_pos_equipo_tenant FOREIGN KEY (tenant_id)      REFERENCES tenants (id),
  CONSTRAINT fk_pos_equipo_user   FOREIGN KEY (habilitado_por) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3) tenants.pos_enabled y pos_equipos.bloqueos_seguidos (bloqueo PROGRESIVO por
--    PIN fallidos, PIN de 4 digitos, decision del 2026-10-08). Solo si faltan:
--    bloqueos_seguidos va aparte del CREATE para las bases donde pos_equipos ya
--    existia sin ella. Desde aqui todo nombra la base con @db.
SET @has_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'pos_enabled'
);
SET @has_bloq := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'pos_equipos' AND COLUMN_NAME = 'bloqueos_seguidos'
);
SET @sql_col := IF(@has_col = 0,
  CONCAT('ALTER TABLE `', @db, '`.tenants ADD COLUMN pos_enabled TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''1 = el tenant tiene el POS (pos.fiscalpoint.com.do). Se activa solo por SQL. Ver master_migrations/012'' AFTER cotizacion_formato'),
  'DO 0');
PREPARE s_col FROM @sql_col;
EXECUTE s_col;
DEALLOCATE PREPARE s_col;

SET @sql_bloq := IF(@has_bloq = 0,
  CONCAT('ALTER TABLE `', @db, '`.pos_equipos ADD COLUMN bloqueos_seguidos INT NOT NULL DEFAULT 0 COMMENT ''Bloqueos sin un PIN valido en medio: cada uno dura el doble (5, 10, 20... min, tope 1 dia)'' AFTER bloqueado_hasta'),
  'DO 0');
PREPARE s_bloq FROM @sql_bloq;
EXECUTE s_bloq;
DEALLOCATE PREPARE s_bloq;

-- Verificacion (la ultima consulta, la que muestra phpMyAdmin).
SELECT
  @db AS base,
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'pos_enabled') AS pos_enabled_ok,
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'pos_equipos' AND COLUMN_NAME = 'bloqueos_seguidos') AS bloqueo_progresivo_ok,
  (SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN ('pos_handoff_codes', 'pos_equipos')) AS tablas_pos;
