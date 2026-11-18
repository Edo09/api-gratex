-- =============================================================================
-- Master Migration 020: permiso del modulo Cobros al rol 'user'.
--
-- Ejecutar contra la base MASTER, UNA vez. Idempotente: se puede correr dos
-- veces sin error, y la segunda no toca nada. Es solo DATOS (filas de
-- role_permissions): no cambia el esquema, asi que no tiene fila en
-- tools/verificar_migraciones_master.sql (el resultado de abajo dice cuantos
-- roles 'user' tienen el permiso y cuantos faltan).
--
-- NOTA: el nombre de la master DB cambia por entorno (en el server:
-- smhynzte_master_gratex). Selecciona la base ANTES de correr esto: clic en su
-- nombre en el panel IZQUIERDO de phpMyAdmin (o pasa el nombre a mysql.exe).
--
-- SI SALE "#1049 Unknown database 'ALTO_elige_la_base_...'" (o "#1142 SELECT command
-- denied ... for table 'x'", segun los permisos del usuario): estabas parado en otra
-- base. NO se cambio nada. Elige la master, abre la pestana SQL de nuevo, pega todo
-- y ejecuta. SI SALE "#1146 Table '<base>.tenants' doesn't exist": esa base no es la
-- master. Tampoco se cambio nada; elige la master y vuelve a correrla.
--
-- POR QUE: el modulo Cobros (docs/modules/cobros.md) usa el permiso 'cobros'
-- (config/permissions.php: ruta /api/cobros). Es un modulo OPERATIVO, como
-- facturas o gastos: lo usa quien registra pagos. Las empresas NUEVAS lo reciben
-- en el rol 'user' al crearse (defaults.user de config/permissions.php, que lee
-- tools/create_tenant.php). El rol 'admin' ya lo tiene por el comodin '*'. Esta
-- migracion cubre los roles 'user' de las empresas YA creadas, como la 004 hizo
-- con Inventario.
--
-- QUE HACE: inserta (role_id, 'cobros') en role_permissions para el rol de
-- sistema 'user' de cada empresa tipo app, salvo donde ya este. No toca roles
-- personalizados (cada empresa decide cual de ellos usa Cobros, en Usuarios y
-- roles), ni a 'admin', ni a las empresas de integracion.
--
-- SI NO SE CORRE: nadie pierde nada de lo que ya tenia (Cobros es un modulo nuevo),
-- pero los usuarios con rol 'user' reciben 403 en /api/cobros (y la web les oculta
-- Cobros) hasta que un admin les de el modulo desde Usuarios y roles, o hasta que
-- se corra esto. El controlador (cobroController.php) exige el modulo SIEMPRE, aunque
-- PERMISSIONS_ENFORCE este en modo sombra: son cuentas por cobrar, y asi un rol sin el
-- modulo no puede leerlas ni cambiarlas por una llamada directa a la API.
--
-- QUITAR EL PERMISO (si hiciera falta, en la master):
--   DELETE rp FROM role_permissions rp
--     JOIN roles r ON r.id = rp.role_id
--    WHERE rp.permission = 'cobros' AND r.name = 'user' AND r.is_system = 1;
--
-- ORDEN: independiente de la 038 (tenant). Conviene correrla despues de la 038 y
-- antes de que los usuarios abran Cobros por primera vez. Solo toca el master:
-- no hay nada que correr en las bases de las empresas.
-- =============================================================================

SET @db := DATABASE();

-- Guardia: la base seleccionada tiene que ser la master. Si no lo es, esta
-- sentencia falla con un nombre de tabla que dice que hacer, ANTES de tocar
-- nada. No consulta information_schema: phpMyAdmin no cambia de base aqui. Pide
-- tenants (la base de negocio de Gratex tambien tiene tablas heredadas).
SET @guardia := IF(@db IS NULL OR @db IN ('information_schema', 'mysql', 'performance_schema', 'sys'),
  'DO (SELECT 1 FROM `ALTO_elige_la_base_master_en_el_panel`.`x` LIMIT 1)',
  CONCAT('DO (SELECT 1 FROM `', @db, '`.tenants LIMIT 1)'));
PREPARE s_guardia FROM @guardia;
EXECUTE s_guardia;
DEALLOCATE PREPARE s_guardia;

-- 1) El permiso, para el rol 'user' de cada empresa tipo app (idempotente). Con
--    SQL armado y la base nombrada, como el resto: nada depende de cual sea la
--    base "actual" despues de una consulta a information_schema.
SET @sql_permiso := CONCAT(
  'INSERT INTO `', @db, '`.role_permissions (role_id, permission) ',
  'SELECT r.id, ''cobros'' ',
  'FROM `', @db, '`.roles r ',
  'JOIN `', @db, '`.tenants t ON t.id = r.tenant_id ',
  'WHERE t.tipo = ''app'' AND r.name = ''user'' ',
  'AND NOT EXISTS (SELECT 1 FROM `', @db, '`.role_permissions rp ',
  'WHERE rp.role_id = r.id AND rp.permission = ''cobros'')');
PREPARE s_permiso FROM @sql_permiso;
EXECUTE s_permiso;
DEALLOCATE PREPARE s_permiso;

-- Verificacion (la ultima consulta, la que muestra phpMyAdmin): roles 'user' de
-- empresas app, cuantos tienen 'cobros' y cuantos faltan (faltan debe ser 0).
SET @sql_resultado := CONCAT(
  'SELECT ''', @db, ''' AS base, ',
  'COUNT(*) AS roles_user_app, ',
  'SUM(EXISTS (SELECT 1 FROM `', @db, '`.role_permissions rp ',
  'WHERE rp.role_id = r.id AND rp.permission = ''cobros'')) AS con_cobros, ',
  'SUM(NOT EXISTS (SELECT 1 FROM `', @db, '`.role_permissions rp ',
  'WHERE rp.role_id = r.id AND rp.permission = ''cobros'')) AS faltan ',
  'FROM `', @db, '`.roles r ',
  'JOIN `', @db, '`.tenants t ON t.id = r.tenant_id ',
  'WHERE t.tipo = ''app'' AND r.name = ''user''');
PREPARE s_resultado FROM @sql_resultado;
EXECUTE s_resultado;
DEALLOCATE PREPARE s_resultado;
