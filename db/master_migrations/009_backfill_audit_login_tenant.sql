-- =============================================================================
-- Master Migration 009: asignar empresa a los logins de la bitacora que
-- quedaron sin tenant_id.
--
-- Ejecutar contra la base MASTER (gratex_master), UNA sola vez. Solo en
-- multi-tenant (MULTI_TENANT_ENABLED=true): en single-tenant no hay master y
-- tenant_id NULL es lo normal. Solo datos: no cambia ningun esquema.
--
-- POR QUE: authController tomaba el tenant de LOGIN_SUCCESS / LOGIN_FAILED del
-- cuerpo del POST (tenant_id), que el front dejo de mandar cuando email y
-- username pasaron a ser unicos globales (master 007). Desde entonces todos los
-- logins quedaron con tenant_id NULL y el admin de cada empresa no los veia en
-- su bitacora. Corregido en el codigo el 2026-09-21 (authModel::loginUser
-- devuelve la empresa del usuario); esto arregla las filas viejas.
--
-- QUE HACE:
--   A) auth con user_id (LOGIN_SUCCESS, LOGOUT...): tenant = empresa del usuario.
--   B) LOGIN_FAILED sin user_id: se busca el usuario escrito igual que el login
--      (email o username). Solo si coincide EXACTAMENTE un usuario y ese usuario
--      ya existia cuando fue el intento. Se completan tenant_id y user_id, como
--      hace hoy el codigo con un usuario existente y clave equivocada.
--   Quedan SIN empresa, a proposito: intentos con un usuario que no existe (no
--   se sabe a quien iban) y la autenticacion DGII entrante (module dgii-auth).
--
-- RESPALDO / DESHACER: antes de tocar nada, cada fila afectada queda en
-- audit_logs_backfill_009 con su valor anterior y el nuevo. Para deshacer:
--
--   UPDATE audit_logs a
--   JOIN audit_logs_backfill_009 m ON m.id = a.id
--   SET a.tenant_id = m.tenant_id_antes, a.user_id = m.user_id_antes;
--
-- (El UPDATE lee de esa tabla y no de audit_logs: MySQL no deja leer la tabla
-- que se esta actualizando en un subquery, error 1093.)
--
-- Idempotente: re-ejecutarla no cambia nada (solo toca filas con tenant NULL, y
-- el respaldo usa INSERT IGNORE sobre el id).
--
-- VISTA PREVIA (correr antes, no modifica nada):
--   SELECT action, COUNT(*) AS filas, SUM(user_id IS NOT NULL) AS con_usuario
--   FROM audit_logs WHERE module = 'auth' AND tenant_id IS NULL GROUP BY action;
-- =============================================================================

USE gratex_master;

-- 1) Tabla de respaldo + mapa (valor anterior -> nuevo).
CREATE TABLE IF NOT EXISTS audit_logs_backfill_009 (
  id              BIGINT      NOT NULL PRIMARY KEY COMMENT 'audit_logs.id',
  accion          VARCHAR(40) NOT NULL,
  tenant_id_antes INT         NULL,
  user_id_antes   INT         NULL,
  tenant_id_nuevo INT         NOT NULL,
  user_id_nuevo   INT         NOT NULL,
  motivo          VARCHAR(40) NOT NULL COMMENT 'por_user_id | por_identificador',
  respaldado_at   DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

START TRANSACTION;

-- 2A) Eventos de auth con usuario conocido: la empresa sale de users.
INSERT IGNORE INTO audit_logs_backfill_009
  (id, accion, tenant_id_antes, user_id_antes, tenant_id_nuevo, user_id_nuevo, motivo)
SELECT a.id, a.action, a.tenant_id, a.user_id, u.tenant_id, u.id, 'por_user_id'
FROM audit_logs a
JOIN users u ON u.id = a.user_id
WHERE a.module = 'auth'
  AND a.tenant_id IS NULL
  AND a.user_id IS NOT NULL;

-- 2B) Logins fallidos sin usuario: buscar lo que se escribio (username o email,
--     guardado en la columna que corresponda) contra email Y username, como
--     authModel::loginUser. Un solo usuario posible y que ya existiera.
INSERT IGNORE INTO audit_logs_backfill_009
  (id, accion, tenant_id_antes, user_id_antes, tenant_id_nuevo, user_id_nuevo, motivo)
SELECT a.id, a.action, a.tenant_id, a.user_id, MIN(u.tenant_id), MIN(u.id), 'por_identificador'
FROM audit_logs a
JOIN users u
  ON (u.email = COALESCE(a.username, a.email) OR u.username = COALESCE(a.username, a.email))
 AND u.created_at <= a.created_at
WHERE a.module = 'auth'
  AND a.action = 'LOGIN_FAILED'
  AND a.tenant_id IS NULL
  AND a.user_id IS NULL
  AND COALESCE(a.username, a.email) IS NOT NULL
GROUP BY a.id, a.action, a.tenant_id, a.user_id
HAVING COUNT(DISTINCT u.id) = 1;

-- 3) Aplicar desde el mapa. El WHERE hace que re-ejecutar no pise nada.
UPDATE audit_logs a
JOIN audit_logs_backfill_009 m ON m.id = a.id
SET a.tenant_id = m.tenant_id_nuevo,
    a.user_id   = m.user_id_nuevo
WHERE a.tenant_id IS NULL;

COMMIT;

-- 4) Verificacion.
--    Lo que se asigno, por empresa y accion:
SELECT m.tenant_id_nuevo AS tenant_id, m.accion, m.motivo, COUNT(*) AS filas,
       MIN(a.created_at) AS desde, MAX(a.created_at) AS hasta
FROM audit_logs_backfill_009 m
JOIN audit_logs a ON a.id = m.id
GROUP BY m.tenant_id_nuevo, m.accion, m.motivo
ORDER BY m.tenant_id_nuevo, m.accion;

--    Lo que sigue sin empresa (esperado: usuarios inexistentes y dgii-auth):
SELECT module, action, COUNT(*) AS filas
FROM audit_logs
WHERE tenant_id IS NULL
GROUP BY module, action
ORDER BY module, action;
