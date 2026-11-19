<?php
/**
 * test_cobros_tenants.php — Cobros de la PLATAFORMA a sus tenants (docs/modules/cobros.md, "Cobros de tenants"),
 * sin servidor y sin MySQL.
 *
 * Es el mismo modulo Cobros de cada empresa (tools/test_cobros.php prueba a fondo las reglas y el modelo), con otro
 * ambito: el cliente es un TENANT de la base master y las cuatro tablas viven alli (migracion master 021, con FK a
 * tenants). Esta suite prueba lo que cambia con el ambito y que todo lo demas sigue funcionando sobre la master:
 *
 *   Parte 1 — La ruta /api/cobros-tenants: las mismas acciones que /api/cobros mas el buscador de tenants, y ningun
 *             ambito sirve el segmento del otro.
 *   Parte 2 — El cableado: el Router define el ambito, config/permissions.php lo deja pasar con token de usuario y el
 *             controlador exige el acceso global SIEMPRE (antes de la ruta y del modelo), falla cerrado y anota la
 *             bitacora de la plataforma con tenant_id 0 para que ninguna empresa la vea en la suya.
 *   Parte 3 — cobroModel REAL en AMBITO_TENANTS contra SQLite en memoria. Las cuatro tablas se traducen del DDL de
 *             db/master_schema.sql (UNIQUE, CHECK, FK a tenants y ENUM incluidos), asi que si el snapshot pierde una
 *             restriccion esta suite lo ve. tenants y users son minimas, a mano; la suite comprueba que el snapshot
 *             tiene las columnas que el modelo lee de ellas. Cubre el cliente (un tenant, con el correo de su primer
 *             admin), el buscador, la configuracion, los cobros, los pagos, la recurrencia, los filtros, el
 *             dashboard, los recordatorios y el panel, con valores calculados a mano.
 *
 * Uso:
 *   php tools/test_cobros_tenants.php
 *   php tools/test_cobros_tenants.php --fixtures=<carpeta>   ademas escribe ahi las respuestas REALES del modelo (JSON,
 *                                                            con el sobre {status, data}) para la prueba de contrato
 *                                                            de la web (fiscalo-react scripts/test-cobros-tenants.ts)
 *
 * La Parte 3 necesita pdo_sqlite; si no esta activo se salta con aviso (las Partes 1 y 2 corren siempre):
 *   php -d extension=php_pdo_sqlite.dll tools/test_cobros_tenants.php   (Windows)
 *   php -d extension=pdo_sqlite tools/test_cobros_tenants.php           (Linux)
 *
 * Los tenants de la Parte 3 son inventados (nombres y RNC de mentira): este repo es publico.
 */

require_once __DIR__ . '/write_ahead_test_lib.php';
require_once __DIR__ . '/cobros_test_lib.php';
// Lo que el modelo manda al error_log (un 500 provocado a proposito) no va a la salida.
ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');

require_once __DIR__ . '/../src/Utils/Cobros/CobroReglas.php';
require_once __DIR__ . '/../src/Models/cobroModel.php';

$raiz = dirname(__DIR__);
/** ¿Es un ['success', ...]? */
$exito = static fn(mixed $r): bool => is_array($r) && ($r[0] ?? '') === 'success';
/** ¿Es un ['error', texto, http, codigo?] con ese http (y ese codigo si se pide)? */
$falla = static fn(mixed $r, int $http, ?string $codigo = null): bool => is_array($r) && ($r[0] ?? '') === 'error'
    && ($r[2] ?? 0) === $http && ($codigo === null || ($r[3] ?? null) === $codigo);

// ===========================================================================
// Parte 1 — La ruta
// ===========================================================================
echo "== Parte 1: la ruta /api/cobros-tenants ==\n";
foreach ([
    // [metodo, ruta, accion, id, pago_id, client_id]
    ['GET', '/api/cobros-tenants', 'listar', null, null, null], ['GET', '/api/cobros-tenants/', 'listar', null, null, null],
    ['POST', '/api/cobros-tenants', 'crear', null, null, null], ['PUT', '/api/cobros-tenants', 'metodo', null, null, null],
    ['GET', '/api/cobros-tenants/resumen', 'resumen', null, null, null], ['POST', '/api/cobros-tenants/resumen', 'metodo', null, null, null],
    ['GET', '/api/cobros-tenants/recordatorios', 'recordatorios', null, null, null],
    ['GET', '/api/cobros-tenants/clientes', 'buscar', null, null, null], ['POST', '/api/cobros-tenants/clientes', 'metodo', null, null, null],
    ['GET', '/api/cobros-tenants/clientes/7', 'panel', null, null, 7], ['PUT', '/api/cobros-tenants/clientes/7/config', 'config', null, null, 7],
    ['GET', '/api/cobros-tenants/clientes/abc', 'desconocida', null, null, null], ['GET', '/api/cobros-tenants/clientes/0', 'desconocida', null, null, null],
    ['GET', '/api/cobros-tenants/12', 'obtener', 12, null, null], ['PUT', '/api/cobros-tenants/12', 'actualizar', 12, null, null],
    ['POST', '/api/cobros-tenants/12/pagos', 'pagar', 12, null, null], ['POST', '/api/cobros-tenants/12/cancelar', 'cancelar', 12, null, null],
    ['POST', '/api/cobros-tenants/12/reabrir', 'reabrir', 12, null, null], ['POST', '/api/cobros-tenants/12/recordatorios', 'recordar', 12, null, null],
    ['POST', '/api/cobros-tenants/pagos/9/anular', 'anular', null, 9, null], ['GET', '/api/cobros-tenants/pagos/9/anular', 'metodo', null, 9, null],
    ['GET', '/api/cobros-tenants/0', 'desconocida', null, null, null], ['GET', '/api/cobros-tenantsx', 'desconocida', null, null, null],
    // El segmento de las empresas, y cualquier otro, no es de la plataforma.
    ['GET', '/api/cobros', 'desconocida', null, null, null], ['GET', '/api/cobros/resumen', 'desconocida', null, null, null],
    ['GET', '/api/facturas', 'desconocida', null, null, null], ['get', '/index.php/api/cobros-tenants/clientes/', 'buscar', null, null, null],
    ['PUT', '/api/cobros-tenants/clientes/7/excluido', 'excluir', null, null, 7], ['GET', '/api/cobros-tenants/clientes/7/excluido', 'metodo', null, null, 7],
    ['PUT', '/api/cobros-tenants/clientes/abc/excluido', 'desconocida', null, null, null], ['PUT', '/api/cobros-tenants/clientes/0/excluido', 'desconocida', null, null, null],
] as [$m, $path, $accion, $id, $pagoId, $clientId]) {
    $ruta = CobroReglas::ruta($m, $path, 'cobros-tenants');
    wa_chk("ruta de la plataforma {$m} {$path} -> {$accion}", $ruta === ['accion' => $accion, 'id' => $id, 'pago_id' => $pagoId, 'client_id' => $clientId], $ruta);
}
wa_chk('al reves: con el segmento por defecto (una empresa) la ruta de la plataforma no existe, y la de la empresa sigue igual',
    CobroReglas::ruta('GET', '/api/cobros-tenants')['accion'] === 'desconocida' && CobroReglas::ruta('GET', '/api/cobros-tenants/resumen')['accion'] === 'desconocida'
    && CobroReglas::ruta('GET', '/api/cobros/resumen')['accion'] === 'resumen' && CobroReglas::ruta('GET', '/api/cobros')['accion'] === 'listar');
wa_chk('el 403 de la plataforma habla de soporte y no manda a "pedirselo a un administrador" (un admin de empresa no lo arregla)',
    CobroReglas::MSG_SIN_PERMISO_PLATAFORMA !== CobroReglas::MSG_SIN_PERMISO && str_contains(CobroReglas::MSG_SIN_PERMISO_PLATAFORMA, 'soporte')
    && !str_contains(CobroReglas::MSG_SIN_PERMISO_PLATAFORMA, 'administrador'));

// El cuerpo de PUT .../clientes/{id}/excluido: `excluido` es obligatorio y booleano; el motivo, opcional y de hasta 150 caracteres.
echo "\n== Parte 1: el cuerpo de excluir ==\n";
$cuerpo = static fn(array $a): object => (object) $a;
$ex = static fn(array $a): array => CobroReglas::validarExclusion($cuerpo($a));
wa_chk('excluir: true sin motivo; con motivo (recortado); y al VOLVER a la lista el motivo no se guarda',
    $ex(['excluido' => true]) === ['success', ['excluido' => true, 'motivo' => null]]
    && $ex(['excluido' => true, 'motivo' => '  Solo certificacion '])[1] === ['excluido' => true, 'motivo' => 'Solo certificacion']
    && $ex(['excluido' => false, 'motivo' => 'x'])[1] === ['excluido' => false, 'motivo' => null]
    && $ex(['excluido' => true, 'motivo' => ''])[1]['motivo'] === null);
wa_chk('excluir acepta el booleano como texto o numero (igual que "activo" de la configuracion): "si", 1 y true; "no", 0 y "0"',
    array_map(fn($v) => $ex(['excluido' => $v])[1]['excluido'] ?? 'error', [true, 'si', 1, '1', false, 'no', 0, '0'])
    === [true, true, true, true, false, false, false, false]);
wa_chk('sin "excluido", con null o con algo que no es un booleano: 422 con el texto que lo explica',
    $ex([])[0] === 'error' && $ex([])[1] === CobroReglas::MSG_EXCLUIDO && $ex([])[2] === 422
    && $ex(['excluido' => null])[1] === CobroReglas::MSG_EXCLUIDO && $ex(['excluido' => 'quizas'])[1] === CobroReglas::MSG_EXCLUIDO
    && $ex(['excluido' => [true]])[2] === 422);
wa_chk('el motivo de 151 caracteres es 422 con su texto; el de 150 pasa, tambien con tildes',
    $ex(['excluido' => true, 'motivo' => str_repeat('a', 151)])[1] === CobroReglas::MSG_MOTIVO_EXCLUSION && $ex(['excluido' => true, 'motivo' => str_repeat('a', 151)])[2] === 422
    && $ex(['excluido' => true, 'motivo' => str_repeat('a', 150)])[0] === 'success' && $ex(['excluido' => true, 'motivo' => str_repeat('á', 150)])[0] === 'success');

// ===========================================================================
// Parte 2 — El cableado
// ===========================================================================
echo "\n== Parte 2: el cableado de la plataforma ==\n";
$router = str_replace("\r\n", "\n", (string) file_get_contents($raiz . '/src/Router.php'));
wa_chk("el Router tiene el case 'cobros-tenants': define el ambito y recien despues carga cobroController.php",
    preg_match("/case 'cobros-tenants':.*?define\\('COBROS_AMBITO', 'tenants'\\);\\s*require_once 'src\\/Controllers\\/cobroController\\.php';\\s*break;/s", $router) === 1);
$iEmpresa = strpos($router, "case 'cobros':");
$iPlataforma = strpos($router, "case 'cobros-tenants':");
wa_chk("...y el case 'cobros' de las empresas no lo define: sin ese ambito el controlador es el de empresa",
    $iEmpresa !== false && $iPlataforma !== false && $iEmpresa < $iPlataforma && !str_contains(substr($router, $iEmpresa, $iPlataforma - $iEmpresa), 'COBROS_AMBITO'));
$defines = 0;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz . '/src', FilesystemIterator::SKIP_DOTS)) as $archivo) {
    if ($archivo->isFile() && $archivo->getExtension() === 'php') {
        $defines += substr_count((string) file_get_contents($archivo->getPathname()), "define('COBROS_AMBITO'");
    }
}
wa_chk("COBROS_AMBITO solo se define en el Router: nada mas puede poner un controlador de empresa en el ambito 'tenants'", $defines === 1, $defines);

$permisos = (static fn(): array => require __DIR__ . '/../config/permissions.php')();
wa_chk("config/permissions.php: 'cobros-tenants' es 'authenticated' (token de usuario; el controlador exige el acceso global) y NO es un modulo del catalogo ni de ningun rol",
    ($permisos['routes']['cobros-tenants'] ?? null) === 'authenticated' && !in_array('cobros-tenants', $permisos['catalog'], true)
    && array_filter($permisos['defaults'], static fn(array $mods): bool => in_array('cobros-tenants', $mods, true)) === []);
wa_chk("...y el modulo 'cobros' de las empresas sigue siendo el de siempre", ($permisos['routes']['cobros'] ?? null) === 'cobros');

$c = str_replace("\r\n", "\n", (string) file_get_contents($raiz . '/src/Controllers/cobroController.php'));
wa_chk('el controlador carga todo lo que usa: cada require_once __DIR__ apunta a un archivo que existe', (static function () use ($c, $raiz): bool {
    preg_match_all("/require_once\\(__DIR__ \\. '([^']+)'\\);/", $c, $m);
    foreach ($m[1] as $rel) {
        if (!is_file($raiz . '/src/Controllers' . $rel)) {
            return false;
        }
    }
    return count($m[1]) >= 8 && in_array('/../AccesoGlobal.php', $m[1], true) && in_array('/../MasterDatabase.php', $m[1], true);
})());
$iIf = strpos($c, 'if ($ambitoTenants) {');
$iElse = $iIf === false ? false : strpos($c, '} else {', $iIf);
$iFin = $iElse === false ? false : strpos($c, '$metodo = $_SERVER', $iElse);
$ramaPlat = ($iIf !== false && $iElse !== false) ? substr($c, $iIf, $iElse - $iIf) : '';
$ramaEmp = ($iElse !== false && $iFin !== false) ? substr($c, $iElse, $iFin - $iElse) : '';
$iRuta = strpos($c, 'CobroReglas::ruta(');
$iModelo = strpos($c, 'new cobroModel(null, null,');
wa_chk('el controlador exige el acceso global en la plataforma SIEMPRE, con la conexion master, antes de resolver la ruta y de abrir el modelo',
    $ramaPlat !== '' && str_contains($ramaPlat, 'AccesoGlobal::tieneAcceso(MasterDatabase::getInstance()->getConnection(),')
    && $iRuta !== false && $iModelo !== false && $iIf < $iRuta && $iIf < $iModelo);
wa_chk("...falla cerrado: sin usuario (un principal maquina) no hay acceso; el 403 deja rastro (modulo cobros-tenants) y lleva el mensaje de soporte",
    str_contains($ramaPlat, "\$validation['user_id'] ?? null") && preg_match("/logAccessDenied\\(\\s*'cobros-tenants'/", $ramaPlat) === 1
    && str_contains($ramaPlat, 'CobroReglas::MSG_SIN_PERMISO_PLATAFORMA, 403') && str_contains($ramaPlat, 'return;'));
wa_chk("en la plataforma no se mira el rol ni el modulo 'cobros' (un rol con ese modulo no abre los cobros de los tenants); en las empresas si, y no depende de PERMISSIONS_ENFORCE",
    $ramaPlat !== '' && !str_contains($ramaPlat, 'permMatches') && !str_contains($ramaPlat, 'getPermissionsForRole')
    && str_contains($ramaEmp, "PermissionGate::permMatches(\$permisos, 'cobros')") && !str_contains($c, 'PermissionGate::enforce('));
wa_chk('el modelo se abre con el ambito de la peticion y la ruta se lee con su segmento',
    str_contains($c, 'new cobroModel(null, null, $ambitoTenants ? cobroModel::AMBITO_TENANTS : cobroModel::AMBITO_EMPRESA)')
    && str_contains($c, "\$ambitoTenants ? 'cobros-tenants' : 'cobros'"));
$iBuscar = strpos($c, "case 'buscar':");
$trozoBuscar = $iBuscar === false ? '' : substr($c, $iBuscar, 600);
$iExcluir = strpos($c, "case 'excluir':");
$trozoExcluir = $iExcluir === false ? '' : substr($c, $iExcluir, 1800);
wa_chk('quitar de la lista de clientes (accion excluir) solo existe en la plataforma (en una empresa es 404), valida el cuerpo, llama al modelo y deja EXCLUDE/INCLUDE en la bitacora',
    $trozoExcluir !== '' && str_contains($trozoExcluir, 'if (!$ambitoTenants) {') && str_contains($trozoExcluir, 'CobroReglas::MSG_RUTA, 404')
    && str_contains($trozoExcluir, 'CobroReglas::validarExclusion($body)') && str_contains($trozoExcluir, '$modelo->excluirCliente(')
    && str_contains($trozoExcluir, "'EXCLUDE'") && str_contains($trozoExcluir, "'INCLUDE'") && str_contains($trozoExcluir, 'if (!$r[1][\'repetido\'])'));
wa_chk("el directorio acepta ?excluidos=1 para traer tambien a los que no son clientes (por defecto no salen)",
    str_contains($c, "in_array(\$_GET['excluidos'] ?? '', ['1', 'true'], true)"));
wa_chk('el buscador de tenants (accion buscar) solo responde en la plataforma: en una empresa es 404',
    $trozoBuscar !== '' && str_contains($trozoBuscar, 'if (!$ambitoTenants) {') && str_contains($trozoBuscar, 'CobroReglas::MSG_RUTA, 404')
    && str_contains($trozoBuscar, '$modelo->buscarClientes(') && str_contains($trozoBuscar, 'cobroModel::MAX_DIRECTORIO'));
wa_chk("la bitacora de la plataforma va con modulo 'cobros-tenants' y tenant_id 0 (ninguna empresa la ve en la suya); la de las empresas, con modulo 'cobros'; y toda anotacion pasa por ese unico punto",
    str_contains($c, "['module' => 'cobros-tenants', 'tenant_id' => 0]") && str_contains($c, "['module' => 'cobros']")
    && substr_count($c, 'AuditLogger::log(') === 1 && substr_count($c, 'cobrosAuditar([') >= 9);
$logger = (string) file_get_contents($raiz . '/src/AuditLogger.php');
$modeloAuditoria = (string) file_get_contents($raiz . '/src/Models/AuditLogModel.php');
wa_chk('AuditLogger respeta un tenant_id explicito (0 no cae en el de la sesion) y la bitacora de cada empresa lista solo SU tenant_id: las filas con 0 no las ve ninguna',
    preg_match('/\'tenant_id\'\s*=>\s*\$event\[\'tenant_id\'\]\s*\?\?\s*RequestContext::tenantId\(\)/', $logger) === 1
    && str_contains($modeloAuditoria, "\$clauses[] = 'tenant_id = :tenant_id';"));

// ===========================================================================
// Parte 3 — cobroModel real contra SQLite en memoria
// ===========================================================================
if (!extension_loaded('pdo_sqlite')) {
    echo "\n(SALTADA: la Parte 3 — pdo_sqlite no esta activo; corre con -d extension=pdo_sqlite)\n";
    wa_fin();
}

/** Una base master en miniatura: tenants y users a mano; las cuatro tablas de Cobros, del snapshot, con las FK activas. */
function cobros_tenants_base(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec("CREATE TABLE tenants (id INTEGER PRIMARY KEY AUTOINCREMENT, nombre TEXT NOT NULL, rnc TEXT NOT NULL UNIQUE,
        tipo TEXT NOT NULL DEFAULT 'app', grupo_id INTEGER NULL, ambiente TEXT NOT NULL DEFAULT 'ecf', activo INTEGER NOT NULL DEFAULT 1,
        edicion TEXT NOT NULL DEFAULT 'completa')");
    $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL REFERENCES tenants (id),
        email TEXT NOT NULL, role TEXT DEFAULT 'user')");
    foreach (cobros_ddl_sqlite(__DIR__ . '/../db/master_schema.sql', ['cobro_config', 'cobros', 'cobro_pagos', 'cobro_recordatorios', 'cobro_excluidos', 'cobro_sin_pago']) as $ddl) {
        $pdo->exec($ddl);
    }
    return $pdo;
}

/** Cinco tenants inventados. 1 tiene un usuario normal y dos admin; 3 (integracion) no tiene usuarios; 4 solo uno normal. */
function cobros_tenants_sembrar(PDO $pdo): void
{
    $pdo->exec("INSERT INTO tenants (id, nombre, rnc, tipo, ambiente, activo, edicion) VALUES
        (1, 'Alfa Comercial SRL', '101000011', 'app', 'ecf', 1, 'completa'),
        (2, 'Beta Servicios SA', '101000022', 'app', 'ecf', 1, 'express'),
        (3, 'Gamma Integraciones', '101000033', 'integracion', 'ecf', 1, 'completa'),
        (4, 'Delta 100% Pruebas', '101000044', 'app', 'certecf', 1, 'completa'),
        (5, 'Epsilon Inactiva SRL', '101000055', 'app', 'ecf', 0, 'completa')");
    $pdo->exec("INSERT INTO users (id, tenant_id, email, role) VALUES
        (1, 1, 'user@alfa.test', 'user'), (2, 1, 'admin@alfa.test', 'admin'), (3, 1, 'admin2@alfa.test', 'admin'),
        (4, 2, 'admin@beta.test', 'admin'), (5, 4, 'solo@delta.test', 'user'), (6, 5, 'admin@epsilon.test', 'admin')");
}

/**
 * Trece tenants para la Parte 4 (quien se cobra y los grupos): 10 es cabeza de un grupo con dos hermanas (11 en produccion, 12 en
 * certificacion); 13 solo certifico; 14 es la cabeza de un grupo que solo esta en certificacion (como Agrovisa) con la hermana 15;
 * 16 es de base propia; 17 certifica y tendra el cobro mensual apagado; 18 es interna (se marca); 19 y 20 son de un grupo cuya cabeza
 * (99) ya no existe; 22 es cabeza en certificacion con una hermana (23) en produccion.
 */
function cobros_tenants_sembrar_grupos(PDO $pdo): void
{
    $pdo->exec("INSERT INTO tenants (id, nombre, rnc, tipo, ambiente, grupo_id) VALUES
        (10, 'Holding Cabeza SRL', '102000010', 'integracion', 'ecf', 10),
        (11, 'Holding Hermana Uno', '102000011', 'integracion', 'ecf', 10),
        (12, 'Holding Hermana Dos', '102000012', 'integracion', 'certecf', 10),
        (13, 'Solo Certificacion SRL', '102000013', 'integracion', 'certecf', NULL),
        (14, 'Agro Cabeza', '102000014', 'integracion', 'certecf', 14),
        (15, 'Agro Hermana', '102000015', 'integracion', 'certecf', 14),
        (16, 'App Normal', '102000016', 'app', 'ecf', NULL),
        (17, 'Certecf Apagada', '102000017', 'integracion', 'certecf', NULL),
        (18, 'Marcada Interna', '102000018', 'app', 'ecf', NULL),
        (19, 'Sin Cabeza Uno', '102000019', 'integracion', 'ecf', 99),
        (20, 'Sin Cabeza Dos', '102000020', 'integracion', 'ecf', 99),
        (22, 'Zeta Cabeza Cert', '102000022', 'integracion', 'certecf', 22),
        (23, 'Zeta Hermana Prod', '102000023', 'integracion', 'ecf', 22)");
}

/**
 * Doce tenants para la Parte 5 (que empresas de un grupo pagan): 30 es la cabeza de un grupo con tres hermanas (31 y 32 en
 * produccion, 33 en certificacion); 40 es cabeza en certificacion con una hermana en produccion (41) y otra en certificacion (42);
 * 50 es una empresa sola; 60 y 61 son un grupo de dos; 70 es cabeza en produccion con una hermana en certificacion (71). Solo 30 y
 * 32 tienen un usuario admin (el correo del grupo 30 sale del de la cabeza, porque 31 no tiene).
 */
function cobros_tenants_sembrar_pagos(PDO $pdo): void
{
    $pdo->exec("INSERT INTO tenants (id, nombre, rnc, tipo, ambiente, grupo_id) VALUES
        (30, 'Pago Cabeza', '103000030', 'integracion', 'ecf', 30),
        (31, 'Pago Hermana Uno', '103000031', 'integracion', 'ecf', 30),
        (32, 'Pago Hermana Dos', '103000032', 'integracion', 'ecf', 30),
        (33, 'Pago Hermana Cert', '103000033', 'integracion', 'certecf', 30),
        (40, 'Prod Cabeza', '103000040', 'integracion', 'certecf', 40),
        (41, 'Prod Hermana', '103000041', 'integracion', 'ecf', 40),
        (42, 'Prod Otra', '103000042', 'integracion', 'certecf', 40),
        (50, 'Sola Marcada', '103000050', 'integracion', 'ecf', NULL),
        (60, 'Todas Marcadas A', '103000060', 'integracion', 'ecf', 60),
        (61, 'Todas Marcadas B', '103000061', 'integracion', 'ecf', 60),
        (70, 'Dist Cabeza', '103000070', 'integracion', 'ecf', 70),
        (71, 'Dist Pagadora', '103000071', 'integracion', 'certecf', 70)");
    $pdo->exec("INSERT INTO users (id, tenant_id, email, role) VALUES (1, 30, 'admin@cabeza.test', 'admin'), (2, 32, 'admin@dos.test', 'admin')");
}

echo "\n== Parte 3: el DDL real de la master traducido a SQLite ==\n";
$ddl = cobros_ddl_sqlite(__DIR__ . '/../db/master_schema.sql', ['cobro_config', 'cobros', 'cobro_pagos', 'cobro_recordatorios', 'cobro_excluidos', 'cobro_sin_pago']);
wa_chk('se traducen las seis tablas y sus indices del snapshot de la master (6 CREATE TABLE y 7 CREATE INDEX)',
    count(array_filter($ddl, fn(string $d): bool => str_starts_with($d, 'CREATE TABLE'))) === 6
    && count(array_filter($ddl, fn(string $d): bool => str_contains($d, 'INDEX'))) === 7, $ddl);
$snapshot = str_replace("\r\n", "\n", (string) file_get_contents($raiz . '/db/master_schema.sql'));
$columnasDe = static function (string $tabla) use ($snapshot): string {
    return preg_match('/CREATE TABLE IF NOT EXISTS ' . $tabla . ' \((.*?)\) ENGINE=/s', $snapshot, $mm) === 1 ? $mm[1] : '';
};
$faltan = [];
foreach (['tenants' => ['id', 'nombre', 'rnc', 'tipo', 'grupo_id', 'ambiente', 'activo', 'edicion'], 'users' => ['id', 'tenant_id', 'email', 'role']] as $tabla => $columnas) {
    $cuerpo = $columnasDe($tabla);
    foreach ($columnas as $col) {
        if ($cuerpo === '' || preg_match('/^\s*' . $col . '\s/m', $cuerpo) !== 1) {
            $faltan[] = "{$tabla}.{$col}";
        }
    }
}
wa_chk('el snapshot de la master tiene las columnas de tenants y users que el modelo lee para hacer de cliente a un tenant', $faltan === [], $faltan);

$pdoC = cobros_tenants_base();
cobros_tenants_sembrar($pdoC);
$pdoC->exec("INSERT INTO cobro_config (client_id, monto_mensual, dia_vencimiento, activo, created_at) VALUES (1, 1, 1, 1, '2026-01-01 00:00:00')");
$intentar = static function (PDO $pdo, string $sql): ?string {
    try {
        $pdo->exec($sql);
        return null;
    } catch (PDOException $e) {
        return $e->getMessage();
    }
};
$ins = "INSERT INTO cobros (client_id, periodo, monto, fecha_vencimiento, created_at) VALUES (1, '2026-05', 10, '2026-05-10', '2026-01-01 00:00:00')";
wa_chk('el UNIQUE (client_id, periodo) del snapshot rechaza un duplicado directo en la base', $intentar($pdoC, $ins) === null && stripos((string) $intentar($pdoC, $ins), 'UNIQUE') !== false);
wa_chk('el CHECK monto_pagado <= monto del snapshot rechaza un saldo mayor que el monto', stripos((string) $intentar($pdoC, "UPDATE cobros SET monto_pagado = 11 WHERE periodo = '2026-05'"), 'CHECK') !== false);
wa_chk('el CHECK monto > 0, el ENUM de metodo y el CHECK del dia (32) del snapshot rechazan un cobro de 0, un metodo inventado y un dia imposible',
    $intentar($pdoC, "INSERT INTO cobros (client_id, periodo, monto, fecha_vencimiento, created_at) VALUES (1, '2026-06', 0, '2026-06-10', 'x')") !== null
    && $intentar($pdoC, "INSERT INTO cobro_pagos (cobro_id, monto, fecha_pago, metodo, created_at) VALUES (1, 5, '2026-05-11', 'cheque', 'x')") !== null
    && $intentar($pdoC, "INSERT INTO cobro_config (client_id, monto_mensual, dia_vencimiento, created_at) VALUES (2, 100, 32, 'x')") !== null);
wa_chk('la FK cobros -> tenants rechaza un cobro de un tenant que no existe, y la de cobro_config tambien',
    stripos((string) $intentar($pdoC, "INSERT INTO cobros (client_id, periodo, monto, fecha_vencimiento, created_at) VALUES (999, '2026-07', 10, '2026-07-10', 'x')"), 'FOREIGN KEY') !== false
    && stripos((string) $intentar($pdoC, "INSERT INTO cobro_config (client_id, monto_mensual, dia_vencimiento, created_at) VALUES (999, 100, 5, 'x')"), 'FOREIGN KEY') !== false);
wa_chk('la FK cobros -> tenants es RESTRICT: no se borra un tenant que ya tiene cobros',
    stripos((string) $intentar($pdoC, 'DELETE FROM tenants WHERE id = 1'), 'FOREIGN KEY') !== false
    && (int) $pdoC->query('SELECT COUNT(*) FROM tenants WHERE id = 1')->fetchColumn() === 1);
$pdoC->exec("INSERT INTO tenants (id, nombre, rnc) VALUES (6, 'Zeta Sin Cobros', '101000066')");
$pdoC->exec("INSERT INTO cobro_config (client_id, monto_mensual, dia_vencimiento, created_at) VALUES (6, 100, 5, '2026-01-01 00:00:00')");
$borrado = $intentar($pdoC, 'DELETE FROM tenants WHERE id = 6');
wa_chk('la FK cobro_config -> tenants es CASCADE: al borrar un tenant sin cobros se va su configuracion, sin dejar filas huerfanas',
    $borrado === null && (int) $pdoC->query('SELECT COUNT(*) FROM cobro_config WHERE client_id = 6')->fetchColumn() === 0);
$pdoC->exec("INSERT INTO tenants (id, nombre, rnc) VALUES (7, 'Eta Sin Cobros', '101000077')");
$pdoC->exec("INSERT INTO cobro_excluidos (client_id, motivo, created_at) VALUES (7, 'Solo certificacion', '2026-01-01 00:00:00')");
wa_chk('cobro_excluidos: una sola marca por tenant (PK) y la FK rechaza marcar a un tenant que no existe',
    stripos((string) $intentar($pdoC, "INSERT INTO cobro_excluidos (client_id, created_at) VALUES (7, 'x')"), 'UNIQUE') !== false
    && stripos((string) $intentar($pdoC, "INSERT INTO cobro_excluidos (client_id, created_at) VALUES (999, 'x')"), 'FOREIGN KEY') !== false);
wa_chk('...y es CASCADE: al borrar un tenant se va su marca de "no es cliente", sin dejar filas huerfanas',
    $intentar($pdoC, 'DELETE FROM tenants WHERE id = 7') === null && (int) $pdoC->query('SELECT COUNT(*) FROM cobro_excluidos WHERE client_id = 7')->fetchColumn() === 0);
$pdoC = null;

// El reloj de las pruebas: se mueve con $ahora.
$fx = []; // respuestas reales que --fixtures vuelca al final
$ahora = '2026-11-16 10:00:00';
$reloj = function () use (&$ahora): string {
    return $ahora;
};
$pdo = cobros_tenants_base();
cobros_tenants_sembrar($pdo);
$m = new cobroModel($pdo, $reloj, cobroModel::AMBITO_TENANTS);
// ids de tenants: A=1 Alfa, B=2 Beta, G=3 Gamma (integracion), D=4 Delta, E=5 Epsilon (inactiva).
$cfg = static fn(int $monto, int $dia, ?string $desc = null, bool $activo = true): array => ['monto_c' => $monto, 'dia' => $dia, 'descripcion' => $desc, 'activo' => $activo];
$nuevo = static fn(int $cliente, ?string $periodo = null, ?string $fecha = null, ?int $monto = null, ?string $desc = null): array
    => ['client_id' => $cliente, 'periodo' => $periodo, 'fecha' => $fecha, 'monto_c' => $monto, 'descripcion' => $desc];
$pago = static fn(int $monto, ?string $clave = null, ?string $fecha = null, string $metodo = 'efectivo', ?string $obs = null): array
    => ['monto_c' => $monto, 'fecha_pago' => $fecha ?? substr($GLOBALS['ahora'], 0, 10), 'metodo' => $metodo, 'observaciones' => $obs, 'clave' => $clave];
$cuenta = static function (string $sql, array $p = []) use ($pdo): int {
    $s = $pdo->prepare($sql);
    $s->execute($p);
    return (int) $s->fetchColumn();
};

echo "\n== Parte 3: el cliente es un tenant ==\n";
wa_chk('un ambito desconocido no se acepta (InvalidArgumentException)', (function () use ($pdo, $reloj): bool {
    try {
        new cobroModel($pdo, $reloj, 'otro');
        return false;
    } catch (InvalidArgumentException $e) {
        return true;
    }
})());
$c1 = $m->cliente(1);
wa_chk('cliente(1): el nombre del tenant hace de nombre, contacto y empresa; con su RNC; el correo es el del PRIMER admin (no el del usuario normal ni el del segundo admin)',
    $c1 === ['id' => 1, 'nombre' => 'Alfa Comercial SRL', 'contacto' => 'Alfa Comercial SRL', 'empresa' => 'Alfa Comercial SRL', 'rnc' => '101000011', 'email' => 'admin@alfa.test', 'telefono' => ''], $c1);
wa_chk('un tenant sin usuarios (integracion) y uno con un solo usuario normal no tienen correo ("")', $m->cliente(3)['email'] === '' && $m->cliente(4)['email'] === '');
wa_chk('un tenant que no existe: null', $m->cliente(999) === null);

echo "\n== Parte 3: configuracion mensual ==\n";
$r = $m->guardarConfig(1, $cfg(500000, 31, 'Mensualidad Fiscalpoint'), 7);
wa_chk('configurar a Alfa: 5000.00 el dia 31, activa, sin configuracion anterior',
    $exito($r) && $r[1]['config']['client_id'] === 1 && $r[1]['config']['monto_mensual'] === 5000.0 && $r[1]['config']['dia_vencimiento'] === 31
    && $r[1]['config']['activo'] === true && $r[1]['config']['descripcion'] === 'Mensualidad Fiscalpoint' && $r[1]['anterior'] === null, $r);
wa_chk('guardarConfig de un tenant que no existe: 404 (la FK lo rechazaria despues, pero el modelo lo dice antes)', $falla($m->guardarConfig(999, $cfg(100, 5), 7), 404));
wa_chk('obtenerConfig: la de Alfa y null para uno sin configuracion', $m->obtenerConfig(1)['monto_mensual'] === 5000.0 && $m->obtenerConfig(3) === null);

echo "\n== Parte 3: el buscador de tenants ==\n";
$b = $m->buscarClientes('');
$fx['buscar'] = $b;
wa_chk('sin texto: los 5 tenants, los activos primero por nombre (Alfa, Beta, Delta, Gamma) y el inactivo al final', array_column($b, 'id') === [1, 2, 4, 3, 5], array_column($b, 'id'));
$porId = array_column($b, null, 'id');
wa_chk('cada uno trae lo de un cliente, que es (tipo, ambiente, activo), su cobro mensual (config), si esta excluido y lo que debe (en ceros: aun no hay cobros)',
    $porId[1] === ['id' => 1, 'nombre' => 'Alfa Comercial SRL', 'contacto' => 'Alfa Comercial SRL', 'empresa' => 'Alfa Comercial SRL', 'rnc' => '101000011',
        'email' => 'admin@alfa.test', 'telefono' => '', 'tipo' => 'app', 'ambiente' => 'ecf', 'activo' => true, 'con_config' => true,
        'config' => ['client_id' => 1, 'monto_mensual' => 5000.0, 'dia_vencimiento' => 31, 'descripcion' => 'Mensualidad Fiscalpoint', 'activo' => true, 'updated_at' => '2026-11-16 10:00:00'],
        'excluido' => false, 'motivo_exclusion' => null,
        'saldo_pendiente' => 0.0, 'saldo_vencido' => 0.0, 'cobros_abiertos' => 0, 'cobros_vencidos' => 0, 'dias_atraso' => 0, 'proximo_vencimiento' => null,
        'facturable' => true, 'motivo_oculto' => null, 'grupo' => null]
    && $porId[3]['tipo'] === 'integracion' && $porId[3]['con_config'] === false && $porId[3]['config'] === null && $porId[3]['email'] === ''
    && $porId[4]['ambiente'] === 'certecf' && $porId[5]['activo'] === false && $porId[2]['con_config'] === false, $porId);
wa_chk('busca por nombre (sin importar mayusculas) y por RNC; un texto parcial sirve y se recorta', array_column($m->buscarClientes('alfa'), 'id') === [1]
    && array_column($m->buscarClientes('GAMMA'), 'id') === [3] && array_column($m->buscarClientes('Servicios'), 'id') === [2]
    && array_column($m->buscarClientes('101000044'), 'id') === [4] && array_column($m->buscarClientes('0000'), 'id') === [1, 2, 4, 3, 5]
    && array_column($m->buscarClientes('  alfa  '), 'id') === [1]);
wa_chk('un % o un _ en la busqueda se busca como texto: "100%" encuentra a Delta 100% Pruebas, "%" solo tambien, y "_" no trae a nadie',
    array_column($m->buscarClientes('100%'), 'id') === [4] && array_column($m->buscarClientes('%'), 'id') === [4] && $m->buscarClientes('_') === []);
wa_chk("un texto con comillas no rompe la consulta ni trae de mas (va como parametro)", $m->buscarClientes("' OR 1=1 --") === [] && $m->buscarClientes("alfa' OR '1'='1") === []);
wa_chk('el limite: 2 trae 2; 0 se sube a 1; uno enorme se queda en los 50 que permite (aqui hay 5)',
    count($m->buscarClientes('', 2)) === 2 && count($m->buscarClientes('', 0)) === 1 && count($m->buscarClientes('', 500)) === 5);
wa_chk('en el ambito de una empresa no hay buscador de tenants: devuelve vacio sin tocar la base', (new cobroModel($pdo, $reloj))->buscarClientes('alfa') === []);

echo "\n== Parte 3: crear cobros, pagos y la cadena mensual (Alfa) ==\n";
$r = $m->crear($nuevo(1, '2026-10'), 7);
$A1 = $exito($r) ? $r[1] : [];
$fx['detalle'] = $A1;
wa_chk('cobro de Alfa para octubre con la configuracion: 5000, vence el 31 de octubre, y el cliente que viaja es el tenant',
    $exito($r) && $A1['periodo'] === '2026-10' && $A1['monto'] === 5000.0 && $A1['fecha_vencimiento'] === '2026-10-31' && $A1['descripcion'] === 'Mensualidad Fiscalpoint'
    && $A1['generado_de_cobro_id'] === null && $A1['cliente']['nombre'] === 'Alfa Comercial SRL' && $A1['cliente']['email'] === 'admin@alfa.test'
    && $A1['cliente']['rnc'] === '101000011', $r);
wa_chk('el estado se deriva del reloj: vencido hace 16 dias, sin abonos, saldo 5000', $A1['estado'] === 'Vencido' && $A1['dias_atraso'] === 16 && $A1['con_abonos'] === false && $A1['saldo'] === 5000.0);
wa_chk('crear para un tenant que no existe: 404', $falla($m->crear($nuevo(999, '2026-11', '2026-11-30', 100), 7), 404));
$m->guardarConfig(1, $cfg(600000, 31, 'Mensualidad Fiscalpoint'), 7);
wa_chk('cambiar el monto mensual a 6000 NO toca el cobro ya generado: sigue en 5000', $m->obtener($A1['id'])['monto'] === 5000.0);
$r = $m->registrarPago($A1['id'], $pago(200000, null, '2026-10-20', 'transferencia', 'Abono'), 7);
wa_chk('abono de 2000: sigue Vencido pero con abonos, saldo 3000, sin cobro siguiente',
    $exito($r) && $r[1]['cobro']['monto_pagado'] === 2000.0 && $r[1]['cobro']['saldo'] === 3000.0 && $r[1]['cobro']['estado'] === 'Vencido'
    && $r[1]['cobro']['con_abonos'] === true && $r[1]['siguiente'] === null && $r[1]['pago']['metodo'] === 'transferencia', $r);
wa_chk('un pago mayor que el saldo (4000 contra 3000): 422 EXCEDE_SALDO con el saldo, y no deja nada',
    $falla($m->registrarPago($A1['id'], $pago(400000), 7), 422, 'EXCEDE_SALDO') && $m->obtener($A1['id'])['monto_pagado'] === 2000.0);
$K1 = '3f2504e0-4f89-41d3-9a0c-0305e82c3301';
$r = $m->registrarPago($A1['id'], $pago(300000, $K1, '2026-11-10'), 7);
$A2 = $exito($r) ? ($r[1]['siguiente'] ?? []) : [];
$fx['pago'] = $r[1] ?? [];
wa_chk('el pago que completa el cobro lo deja Pagado y genera el de noviembre con el monto ACTUAL (6000), vence el 30 (el 31 no existe) y apunta a su origen',
    $exito($r) && $r[1]['cobro']['estado'] === 'Pagado' && $r[1]['cobro']['saldo'] === 0.0 && $r[1]['siguiente_nuevo'] === true && $r[1]['siguiente_motivo'] === 'generado'
    && $A2 !== [] && $A2['periodo'] === '2026-11' && $A2['monto'] === 6000.0 && $A2['fecha_vencimiento'] === '2026-11-30' && $A2['generado_de_cobro_id'] === $A1['id']
    && $A2['estado'] === 'Pendiente' && $A2['dias_para_vencer'] === 14 && $A2['cliente']['nombre'] === 'Alfa Comercial SRL', $r);
$r2 = $m->registrarPago($A1['id'], $pago(300000, $K1, '2026-11-10'), 7);
$fx['pago-repetido'] = $r2[1] ?? [];
wa_chk('REPETIR la peticion con la misma clave: devuelve el mismo pago, sin otro cobro ni otro pago',
    $exito($r2) && $r2[1]['repetido'] === true && $r2[1]['siguiente_nuevo'] === false && $r2[1]['pago']['id'] === $r[1]['pago']['id'] && $r2[1]['siguiente']['id'] === $A2['id']
    && count($m->obtener($A1['id'])['pagos']) === 2 && $cuenta('SELECT COUNT(*) FROM cobros WHERE client_id = 1') === 2, $r2);
wa_chk('la misma clave con OTRO monto: 409 CLAVE_CON_OTROS_DATOS; pagar un cobro ya pagado: 409 YA_PAGADO',
    $falla($m->registrarPago($A1['id'], $pago(100, $K1, '2026-11-10'), 7), 409, 'CLAVE_CON_OTROS_DATOS') && $falla($m->registrarPago($A1['id'], $pago(100), 7), 409, 'YA_PAGADO'));
wa_chk('crear otro cobro del mismo tenant y periodo: 409 COBRO_DUPLICADO con el id del que ya existe', ($r3 = $m->crear($nuevo(1, '2026-11'), 7)) && $falla($r3, 409, 'COBRO_DUPLICADO') && $r3[4]['cobro_id'] === $A2['id'], $r3);
wa_chk('cancelar un cobro con pagos: 409 TIENE_PAGOS', $falla($m->cancelar($A1['id'], 'x', 7), 409, 'TIENE_PAGOS'));
$A2 = $m->obtener($A2['id']);

echo "\n== Parte 3: Beta (configuracion inactiva), Gamma (integracion), Delta y Epsilon (inactiva) ==\n";
$m->guardarConfig(2, $cfg(100000, 10, null, false), 7);
$r = $m->crear($nuevo(2, null, '2026-11-20', 75050), 7);
$B1 = $exito($r) ? $r[1] : [];
wa_chk('cobro de Beta con monto y vencimiento escritos (750.50) aunque su configuracion diga 1000: lo escrito manda', $exito($r) && $B1['monto'] === 750.5 && $B1['fecha_vencimiento'] === '2026-11-20' && $B1['periodo'] === '2026-11', $r);
$m->registrarPago($B1['id'], $pago(25025), 7);
$r = $m->registrarPago($B1['id'], $pago(50025), 7);
$P2 = $exito($r) ? $r[1]['pago'] : [];
wa_chk('dos pagos (250.25 + 500.25) suman exactos 750.50 y lo dejan Pagado; con la configuracion INACTIVA no genera el siguiente (siguiente_motivo = inactivo)',
    $exito($r) && $r[1]['cobro']['monto_pagado'] === 750.5 && $r[1]['cobro']['estado'] === 'Pagado' && $r[1]['siguiente'] === null && $r[1]['siguiente_motivo'] === 'inactivo'
    && $cuenta('SELECT COUNT(*) FROM cobros WHERE client_id = 2') === 1, $r);
$r = $m->anularPago($P2['id'], 'Se digito mal', 7);
$fx['anulado'] = $r[1] ?? [];
wa_chk('anular el pago de 500.25: Beta vuelve a 250.25 pagado (Parcial, vence el 20) y el pago se queda a la vista como anulado, con su motivo',
    $exito($r) && $r[1]['cobro']['monto_pagado'] === 250.25 && $r[1]['cobro']['saldo'] === 500.25 && $r[1]['cobro']['estado'] === 'Parcial'
    && $r[1]['pago']['anulado'] === true && $r[1]['pago']['motivo_anulacion'] === 'Se digito mal' && count($r[1]['cobro']['pagos']) === 2, $r);
$r = $m->registrarPago($B1['id'], $pago(50025), 7);
wa_chk('volver a pagar lo que falta: Pagado otra vez (3 pagos, uno anulado que no suma)', $exito($r) && $r[1]['cobro']['estado'] === 'Pagado' && $r[1]['cobro']['monto_pagado'] === 750.5 && count($r[1]['cobro']['pagos']) === 3, $r);

$r = $m->crear($nuevo(3, null, '2026-11-01', 30000), 7);
$G1 = $exito($r) ? $r[1] : [];
wa_chk('cobro de Gamma (integracion, sin usuarios, sin configuracion): 300, vencio el 1 de noviembre (15 dias) y el cliente no tiene correo',
    $exito($r) && $G1['estado'] === 'Vencido' && $G1['dias_atraso'] === 15 && $G1['cliente']['nombre'] === 'Gamma Integraciones' && $G1['cliente']['email'] === '', $r);
wa_chk('sin configuracion y sin monto: 422 pide el monto (un tenant sin config no inventa un cobro)', ($r = $m->crear($nuevo(3), 7)) && $falla($r, 422) && $r[1] === CobroReglas::MSG_MONTO_REQUERIDO, $r);

$m->guardarConfig(4, $cfg(200000, 15), 7);
$D1 = $m->crear($nuevo(4, '2026-11'), 7)[1];
$D2 = $m->crear($nuevo(4, '2026-12'), 7)[1];
wa_chk('dos cobros de Delta (nov y dic) con la configuracion: 2000 cada uno, vencen el 15', $D1['fecha_vencimiento'] === '2026-11-15' && $D2['fecha_vencimiento'] === '2026-12-15' && $D2['monto'] === 2000.0
    && $D1['cliente']['nombre'] === 'Delta 100% Pruebas');
$r = $m->registrarPago($D2['id'], $pago(50000), 7);
wa_chk('abono de 500 a diciembre (antes de vencer): Parcial con saldo 1500, sin siguiente', $exito($r) && $r[1]['cobro']['estado'] === 'Parcial' && $r[1]['cobro']['saldo'] === 1500.0 && $r[1]['siguiente'] === null, $r);

$E1 = $m->crear($nuevo(5, '2026-11', '2026-11-16', 120000), 7)[1];
$E2 = $m->crear($nuevo(5, '2026-10', '2026-10-01', 80000), 7)[1];
wa_chk('un tenant INACTIVO tambien se cobra (la deuda no se va con la baja): el de hoy esta Pendiente con 0 dias para vencer',
    $E1['estado'] === 'Pendiente' && $E1['dias_para_vencer'] === 0 && $E1['cliente']['nombre'] === 'Epsilon Inactiva SRL' && $E1['cliente']['email'] === 'admin@epsilon.test');
$r = $m->cancelar($E2['id'], 'Se perdono la deuda', 7);
wa_chk('cancelar uno sin pagos: Cancelado y con su motivo; ya no recibe pagos (409 COBRO_CANCELADO)',
    $exito($r) && $r[1]['cobro']['estado'] === 'Cancelado' && $r[1]['cobro']['motivo_cancelacion'] === 'Se perdono la deuda'
    && $falla($m->registrarPago($E2['id'], $pago(100), 7), 409, 'COBRO_CANCELADO'), $r);
wa_chk('la base queda consistente: monto_pagado = suma de los pagos vigentes, dentro de 0..monto, sin duplicados', cobros_invariantes($pdo) === [], cobros_invariantes($pdo));

echo "\n== Parte 3: listar, contar y filtrar (con el nombre y el RNC del tenant) ==\n";
$todos = $m->listar(CobroReglas::validarFiltros([])[1], 0, 500);
$totalCobros = $m->contar(CobroReglas::validarFiltros([])[1]);
wa_chk("listar sin filtros trae los 8 cobros y contar coincide", count($todos) === 8 && $totalCobros === 8 && $cuenta('SELECT COUNT(*) FROM cobros') === 8, [count($todos), $totalCobros]);
wa_chk('orden por urgencia: primero lo abierto del mas atrasado al mas lejano (G1, D1, E1, A2, D2) y despues lo cerrado, lo mas reciente primero (B1, A1, E2)',
    array_column($todos, 'id') === [$G1['id'], $D1['id'], $E1['id'], $A2['id'], $D2['id'], $B1['id'], $A1['id'], $E2['id']], array_column($todos, 'id'));
wa_chk('cada fila trae su cliente: el tenant, con el nombre, el RNC y el correo de su admin', $todos[0]['cliente']['nombre'] === 'Gamma Integraciones' && $todos[0]['cliente']['rnc'] === '101000033'
    && $todos[3]['cliente']['email'] === 'admin@alfa.test', $todos[0]['cliente']);
$porEstado = [];
foreach (CobroReglas::ESTADOS as $est) {
    $porEstado[$est] = array_column($m->listar(CobroReglas::validarFiltros(['estado' => $est])[1], 0, 500), 'id');
    // Parcial = con abonos y todavia debe, VENZA O NO.
    $esperados = array_column(array_filter($todos, fn(array $c): bool => $est === CobroReglas::PARCIAL ? $c['con_abonos'] === true && $c['saldo'] > 0 : $c['estado'] === $est), 'id');
    sort($esperados);
    $obtenidos = $porEstado[$est];
    sort($obtenidos);
    wa_chk("el filtro estado={$est} (SQL) devuelve EXACTAMENTE lo que el estado derivado (PHP) dice: " . count($esperados) . ' cobros', $obtenidos === $esperados && $esperados !== [], [$obtenidos, $esperados]);
    wa_chk("contar con estado={$est} coincide", $m->contar(CobroReglas::validarFiltros(['estado' => $est])[1]) === count($esperados));
}
$ids = static fn(array $f): array => array_column($m->listar(CobroReglas::validarFiltros($f)[1], 0, 500), 'id');
wa_chk('filtro por tenant: los 2 cobros de Delta', $ids(['client_id' => 4]) === array_values(array_intersect(array_column($todos, 'id'), [$D1['id'], $D2['id']])) && count($ids(['client_id' => 4])) === 2);
wa_chk('busqueda por nombre del tenant (sin importar mayusculas): "alfa" y "ALFA COMERCIAL SRL" traen los 2 de Alfa', count($ids(['query' => 'alfa'])) === 2 && count($ids(['query' => 'ALFA COMERCIAL SRL'])) === 2);
wa_chk('busqueda por RNC del tenant: "101000044" trae los 2 de Delta', count($ids(['query' => '101000044'])) === 2);
wa_chk('un % en la busqueda se busca como texto: "100%" y "%" traen los 2 de Delta, y "_" ninguno; sin resultados: vacio y total 0',
    count($ids(['query' => '100%'])) === 2 && count($ids(['query' => '%'])) === 2 && $ids(['query' => '_']) === [] && $ids(['query' => 'zzzz']) === []
    && $m->contar(CobroReglas::validarFiltros(['query' => 'zzzz'])[1]) === 0);
wa_chk('filtro por periodo 2026-11: los de noviembre de Alfa, Beta, Gamma, Delta y Epsilon (5)', count($ids(['periodo' => '2026-11'])) === 5, $ids(['periodo' => '2026-11']));
wa_chk('filtro por vencimiento entre el 1 y el 20 de noviembre: G1 (1), D1 (15), E1 (16) y B1 (20)',
    count($ids(['vence_desde' => '2026-11-01', 'vence_hasta' => '2026-11-20'])) === 4, $ids(['vence_desde' => '2026-11-01', 'vence_hasta' => '2026-11-20']));
$pag1 = $m->listar(CobroReglas::validarFiltros([])[1], 0, 3);
$pag2 = $m->listar(CobroReglas::validarFiltros([])[1], 3, 3);
wa_chk('paginacion: dos paginas de 3 no se pisan y siguen el mismo orden', array_column(array_merge($pag1, $pag2), 'id') === array_slice(array_column($todos, 'id'), 0, 6));
$porSaldo = $m->listar(CobroReglas::validarFiltros(['orden' => 'saldo_desc'])[1], 0, 3);
wa_chk('orden por saldo: el mayor saldo primero (A2 6000, D1 2000, D2 1500)', array_column($porSaldo, 'saldo') === [6000.0, 2000.0, 1500.0] && $porSaldo[0]['id'] === $A2['id'], array_column($porSaldo, 'saldo'));
$fx['lista'] = ['data' => $todos, 'pagination' => ['page' => 1, 'pageSize' => 100, 'total' => $totalCobros, 'totalPages' => 1]];

echo "\n== Parte 3: el estado depende del reloj, no de lo guardado ==\n";
$otroReloj = static fn(string $f): cobroModel => new cobroModel($pdo, static fn(): string => $f, cobroModel::AMBITO_TENANTS);
$e = static fn(string $f): array => (static function (array $c): array {
    return [$c['estado'], $c['dias_atraso'], $c['dias_para_vencer']];
})($otroReloj($f)->obtener($A2['id']));
wa_chk('el cobro de Alfa que vence el 30 de noviembre: Pendiente el 29, Pendiente el mismo 30 (vence hoy), Vencido el 1 de diciembre con 1 dia de atraso',
    $e('2026-11-29 23:59:00') === ['Pendiente', 0, 1] && $e('2026-11-30 00:00:00') === ['Pendiente', 0, 0] && $e('2026-12-01 08:00:00') === ['Vencido', 1, null],
    [$e('2026-11-29 23:59:00'), $e('2026-11-30 00:00:00'), $e('2026-12-01 08:00:00')]);

echo "\n== Parte 3: dashboard (valores calculados a mano; hoy = 2026-11-16) ==\n";
$res = $m->resumen(7);
$fx['resumen'] = $res;
$t = $res['totales'];
wa_chk('pendiente 11,000 = A2 6000 + G1 300 + D1 2000 + D2 1500 (saldo) + E1 1200; vencido 2,300 = G1 300 + D1 2000', $t['pendiente'] === 11000.0 && $t['vencido'] === 2300.0, $t);
wa_chk('por vencer en 7 dias: 1,200 (E1, que vence hoy)', $t['por_vencer'] === 1200.0, $t);
wa_chk('por cobrar en el mes: 9,500 = lo abierto que vence en noviembre (A2 6000 + G1 300 + D1 2000 + E1 1200)', $t['por_cobrar_mes'] === 9500.0, $t);
wa_chk('facturado en el mes: 10,250.50 = todo lo no cancelado que vence en noviembre, pagado o no (A2 + B1 750.50 + G1 + D1 + E1)', $t['facturado_mes'] === 10250.5, $t);
wa_chk('cobrado en el mes: 4,250.50 = pagos vigentes con fecha de noviembre (3000 de Alfa + 750.50 de Beta + 500 de Delta; no cuenta el abono de octubre ni el pago anulado)',
    $t['cobrado_mes'] === 4250.5, $t);
wa_chk('conteos: 5 cobros abiertos, 2 vencidos, 4 tenants con saldo (Alfa, Gamma, Delta, Epsilon) y 2 con atraso (Gamma y Delta)',
    $t['cobros_abiertos'] === 5 && $t['cobros_vencidos'] === 2 && $t['clientes_con_saldo'] === 4 && $t['clientes_con_atraso'] === 2, $t);
$ant = array_column($res['antiguedad'], null, 'tramo');
wa_chk('antiguedad: vigente 8,700 en 3 cobros (A2, D2, E1); 1-30 dias 2,300 en 2 (G1, D1); lo demas en cero; y suma lo mismo que lo pendiente',
    $ant['vigente']['monto'] === 8700.0 && $ant['vigente']['cobros'] === 3 && $ant['1-30']['monto'] === 2300.0 && $ant['1-30']['cobros'] === 2
    && $ant['31-60']['monto'] === 0.0 && $ant['61-90']['monto'] === 0.0 && $ant['90+']['monto'] === 0.0 && array_sum(array_column($res['antiguedad'], 'monto')) === $t['pendiente'], $res['antiguedad']);
wa_chk('el dashboard dice la fecha y la hora de RD del servidor (hoy y ahora)', $res['hoy'] === '2026-11-16' && $res['ahora'] === $ahora && $res['mes'] === '2026-11');
wa_chk('proximos vencimientos (7 dias): solo el de hoy (E1), con su tenant; con 30 dias: E1, A2 y D2, en ese orden',
    array_column($res['proximos'], 'id') === [$E1['id']] && $res['proximos'][0]['cliente']['nombre'] === 'Epsilon Inactiva SRL' && $res['proximos'][0]['dias_para_vencer'] === 0
    && array_column($m->resumen(30)['proximos'], 'id') === [$E1['id'], $A2['id'], $D2['id']], array_column($m->resumen(30)['proximos'], 'id'));
wa_chk('tenants con atraso, el que mas debe primero: Delta (1 cobro, 2,000, desde el 15, 1 dia) y Gamma (1 cobro, 300, desde el 1, 15 dias)',
    count($res['atrasados']) === 2 && $res['atrasados'][0]['client_id'] === 4 && $res['atrasados'][0]['cliente']['nombre'] === 'Delta 100% Pruebas' && $res['atrasados'][0]['saldo_vencido'] === 2000.0
    && $res['atrasados'][0]['mas_antiguo'] === '2026-11-15' && $res['atrasados'][0]['dias_atraso'] === 1 && $res['atrasados'][0]['cobros_vencidos'] === 1
    && $res['atrasados'][1]['client_id'] === 3 && $res['atrasados'][1]['cliente']['nombre'] === 'Gamma Integraciones' && $res['atrasados'][1]['saldo_vencido'] === 300.0
    && $res['atrasados'][1]['dias_atraso'] === 15, $res['atrasados']);
wa_chk('el cobro cancelado no aparece en ningun total', $t['pendiente'] === 11000.0 && !in_array($E2['id'], array_column($res['proximos'], 'id'), true));
$vacio = new cobroModel(cobros_tenants_base(), $reloj, cobroModel::AMBITO_TENANTS);
$rv = $vacio->resumen();
wa_chk('el dashboard de una plataforma sin cobros: todo en cero y las listas vacias', $rv['totales']['pendiente'] === 0.0 && $rv['totales']['cobros_abiertos'] === 0 && $rv['proximos'] === []
    && $rv['atrasados'] === [] && count($rv['antiguedad']) === 5 && array_sum(array_column($rv['antiguedad'], 'cobros')) === 0);

echo "\n== Parte 3: recordatorios (informativos) ==\n";
$rec = $m->recordatorios(7, 7);
wa_chk('por recordar (7 dias): G1 y D1 (vencidos) y E1 (vence hoy), del mas atrasado al menos; nadie se ha recordado todavia',
    array_column($rec['items'], 'id') === [$G1['id'], $D1['id'], $E1['id']]
    && $rec['resumen'] === ['por_recordar' => 3, 'recordados' => 0, 'vencidos' => 2, 'por_vencer' => 1, 'clientes' => 3, 'clientes_por_recordar' => 3, 'clientes_recordados' => 0]
    && $rec['truncado'] === false, $rec['resumen']);
wa_chk('los TENANTS a recordar, el mas atrasado primero: Gamma, Delta y Epsilon, con su nombre y el correo de su admin (a quien escribirle)',
    array_column($rec['clientes'], 'client_id') === [3, 4, 5] && $rec['clientes'][0]['cliente']['nombre'] === 'Gamma Integraciones' && $rec['clientes'][1]['cliente']['email'] === ''
    && $rec['clientes'][2]['cliente']['email'] === 'admin@epsilon.test' && $rec['clientes'][0]['dias_atraso'] === 15 && $rec['clientes'][0]['saldo_vencido'] === 300.0
    && $rec['clientes'][2]['motivo'] === 'por_vencer' && $rec['clientes'][2]['saldo_por_vencer'] === 1200.0, $rec['clientes']);
$r = $m->registrarRecordatorio($G1['id'], ['fecha_hora' => '2026-11-16 09:00:00', 'canal' => 'llamada', 'nota' => 'No contesto'], 7, 'soporte');
$fx['recordatorio'] = $r[1] ?? [];
wa_chk('registrar un recordatorio: queda con su canal, su nota y quien lo hizo', $exito($r) && $r[1]['recordatorio']['canal'] === 'llamada' && $r[1]['recordatorio']['nota'] === 'No contesto'
    && $r[1]['recordatorio']['usuario'] === 'soporte' && $r[1]['cobro']['recordatorios'] === 1, $r);
$rec = $m->recordatorios(7, 7);
$fx['recordatorios'] = $rec;
wa_chk('la llamada a Gamma lo baja al final: 2 por recordar y 1 recordado (cobros); 2 y 1 (tenants); Delta y Epsilon siguen primero',
    $rec['resumen']['por_recordar'] === 2 && $rec['resumen']['recordados'] === 1 && $rec['resumen']['clientes_por_recordar'] === 2 && $rec['resumen']['clientes_recordados'] === 1
    && array_column($rec['clientes'], 'client_id') === [4, 5, 3] && array_column($rec['items'], 'id') === [$D1['id'], $E1['id'], $G1['id']], [$rec['resumen'], array_column($rec['clientes'], 'client_id')]);
wa_chk('no se recuerda un cobro pagado (409 YA_PAGADO) ni uno que no existe (404)', $falla($m->registrarRecordatorio($A1['id'], ['fecha_hora' => $ahora, 'canal' => 'otro', 'nota' => null], 7, 'u'), 409, 'YA_PAGADO')
    && $falla($m->registrarRecordatorio(9999, ['fecha_hora' => $ahora, 'canal' => 'otro', 'nota' => null], 7, 'u'), 404));

echo "\n== Parte 3: el directorio de tenants: cobro mensual y lo que deben ==\n";
$dir = $m->buscarClientes('', cobroModel::MAX_DIRECTORIO);
$fx['directorio'] = $dir;
$dirPorId = array_column($dir, null, 'id');
$resumenDir = static fn(array $t): array => [
    $t['con_config'], $t['saldo_pendiente'], $t['saldo_vencido'], $t['cobros_abiertos'], $t['cobros_vencidos'], $t['dias_atraso'], $t['proximo_vencimiento'],
];
wa_chk('el directorio trae a los 5 tenants, los activos primero por nombre (Alfa, Beta, Delta, Gamma) y la inactiva al final', array_column($dir, 'id') === [1, 2, 4, 3, 5], array_column($dir, 'id'));
wa_chk('Alfa: cobro mensual de 6,000 (dia 31) y 6,000 pendientes (el de noviembre, que vence el 30): nada vencido',
    $dirPorId[1]['config']['monto_mensual'] === 6000.0 && $dirPorId[1]['config']['dia_vencimiento'] === 31 && $dirPorId[1]['config']['activo'] === true
    && $dirPorId[1]['config']['descripcion'] === 'Mensualidad Fiscalpoint'
    && $resumenDir($dirPorId[1]) === [true, 6000.0, 0.0, 1, 0, 0, '2026-11-30'], $dirPorId[1]);
wa_chk('Beta: cobro mensual de 1,000 (dia 10) pero INACTIVO, y no debe nada (su cobro esta pagado)',
    $dirPorId[2]['config']['monto_mensual'] === 1000.0 && $dirPorId[2]['config']['dia_vencimiento'] === 10 && $dirPorId[2]['config']['activo'] === false
    && $resumenDir($dirPorId[2]) === [true, 0.0, 0.0, 0, 0, 0, null], $dirPorId[2]);
wa_chk('Delta: cobro mensual de 2,000 (dia 15); debe 3,500 (el de noviembre, 2,000 vencido hace 1 dia, y el de diciembre, 1,500 despues de un abono) y su proximo vencimiento es el 15 de diciembre',
    $dirPorId[4]['config']['monto_mensual'] === 2000.0 && $resumenDir($dirPorId[4]) === [true, 3500.0, 2000.0, 2, 1, 1, '2026-12-15'], $dirPorId[4]);
wa_chk('Gamma: sin cobro mensual (config null); debe 300, todo vencido hace 15 dias, y no tiene proximo vencimiento',
    $dirPorId[3]['config'] === null && $resumenDir($dirPorId[3]) === [false, 300.0, 300.0, 1, 1, 15, null], $dirPorId[3]);
wa_chk('Epsilon (inactiva): debe 1,200 que vencen HOY (no esta vencido: hoy es el proximo vencimiento); su cobro cancelado no suma',
    $dirPorId[5]['config'] === null && $dirPorId[5]['activo'] === false && $resumenDir($dirPorId[5]) === [false, 1200.0, 0.0, 1, 0, 0, '2026-11-16'], $dirPorId[5]);
wa_chk('lo del directorio suma lo mismo que el dashboard: pendiente 11,000, vencido 2,300, 5 cobros abiertos y 2 vencidos',
    array_sum(array_column($dir, 'saldo_pendiente')) === $m->resumen(7)['totales']['pendiente'] && array_sum(array_column($dir, 'saldo_vencido')) === $m->resumen(7)['totales']['vencido']
    && array_sum(array_column($dir, 'cobros_abiertos')) === $m->resumen(7)['totales']['cobros_abiertos']
    && array_sum(array_column($dir, 'cobros_vencidos')) === $m->resumen(7)['totales']['cobros_vencidos'] && $m->resumen(7)['totales']['pendiente'] === 11000.0);
wa_chk('buscar por nombre o RNC trae a ese tenant CON sus saldos (el buscador de los formularios es el mismo directorio)',
    $resumenDir($m->buscarClientes('delta')[0]) === [true, 3500.0, 2000.0, 2, 1, 1, '2026-12-15'] && array_column($m->buscarClientes('101000033'), 'id') === [3]);
$alfaDic = $otroReloj('2026-12-01 10:00:00')->buscarClientes('alfa')[0];
wa_chk('depende del reloj, no de lo guardado: el 1 de diciembre lo de Alfa (vencia el 30) ya esta vencido hace 1 dia y no le queda proximo vencimiento',
    $resumenDir($alfaDic) === [true, 6000.0, 6000.0, 1, 1, 1, null], $alfaDic);
wa_chk('el limite se corta en MAX_DIRECTORIO (500): uno enorme no rompe ni trae de mas, y el tope es el que usa el controlador',
    cobroModel::MAX_DIRECTORIO === 500 && count($m->buscarClientes('', 100000)) === 5 && count($m->buscarClientes('', 3)) === 3);

echo "\n== Parte 3: quitar a un tenant de la lista de clientes (y devolverlo) ==\n";
$sinCliente = static fn(array $r): string => (string) ($r[1] ?? '');
wa_chk('quitar a un tenant que no existe: 404',  $falla($m->excluirCliente(999, true, null, 7), 404));
$r = $m->excluirCliente(1, true, 'x', 7);
wa_chk('a Alfa, que tiene 1 cobro abierto, NO se le quita de la lista: 409 TIENE_COBROS_ABIERTOS, con el texto y la cuenta',
    $falla($r, 409, 'TIENE_COBROS_ABIERTOS') && str_contains($sinCliente($r), '1 cobro abierto') && ($r[4]['cobros_abiertos'] ?? null) === 1, $r);
wa_chk('...y el texto concuerda con la cuenta: con 1 cobro, "cóbralo o cancélalo" (no "cóbralos")', str_contains($sinCliente($r), 'cóbralo o cancélalo') && !str_contains($sinCliente($r), 'cóbralos'), $r);
$r = $m->excluirCliente(4, true, null, 7);
wa_chk('a Delta, con 2 cobros abiertos, tampoco: el texto dice "2 cobros abiertos: cóbralos o cancélalos"', $falla($r, 409, 'TIENE_COBROS_ABIERTOS') && str_contains($sinCliente($r), '2 cobros abiertos: cóbralos o cancélalos'), $r);
wa_chk('...ni a Gamma ni a Epsilon (tienen un cobro abierto cada una); cancelado o pagado no cuenta como abierto',
    $falla($m->excluirCliente(3, true, null, 7), 409, 'TIENE_COBROS_ABIERTOS') && $falla($m->excluirCliente(5, true, null, 7), 409, 'TIENE_COBROS_ABIERTOS'));
$m->guardarConfig(2, $cfg(100000, 10, null, true), 7);
$r = $m->excluirCliente(2, true, 'Solo certificacion', 7);
wa_chk('Beta no debe nada, pero con su cobro mensual ACTIVO tampoco se le quita (sigue generando cobros): 409 CONFIG_ACTIVA',
    $falla($r, 409, 'CONFIG_ACTIVA') && str_contains($sinCliente($r), 'desactívalo'), $r);
$m->guardarConfig(2, $cfg(100000, 10, null, false), 7);
$r = $m->excluirCliente(2, true, 'Solo certificacion', 7);
$fx['exclusion'] = $r[1] ?? [];
wa_chk('con el cobro mensual inactivo y sin cobros abiertos, a Beta SI: excluido, con su motivo, y lo de antes (anterior) para la bitacora',
    $exito($r) && $r[1] === ['client_id' => 2, 'excluido' => true, 'motivo' => 'Solo certificacion', 'repetido' => false, 'anterior' => ['excluido' => false, 'motivo' => null]], $r);
$r = $m->excluirCliente(2, true, 'otro motivo', 7);
wa_chk('repetirlo es lo mismo que una vez (repetido) y no cambia el motivo', $exito($r) && $r[1]['repetido'] === true && $r[1]['motivo'] === 'Solo certificacion', $r);
wa_chk('el directorio de siempre ya no la trae (ni el buscador de los formularios): quedan Alfa, Delta, Gamma y Epsilon',
    array_column($m->buscarClientes('', cobroModel::MAX_DIRECTORIO), 'id') === [1, 4, 3, 5] && $m->buscarClientes('beta') === []);
$conOcultas = $m->buscarClientes('', cobroModel::MAX_DIRECTORIO, true);
$fx['directorio-con-excluidos'] = $conOcultas;
wa_chk('con "excluidos" la trae, marcada y con su motivo; las demas dicen excluido false y sin motivo',
    array_column($conOcultas, 'id') === [1, 2, 4, 3, 5] && array_column($conOcultas, 'excluido') === [false, true, false, false, false]
    && $conOcultas[1]['motivo_exclusion'] === 'Solo certificacion' && $conOcultas[0]['motivo_exclusion'] === null
    && array_column($m->buscarClientes('beta', 20, true), 'id') === [2], $conOcultas);
wa_chk('no se borra nada: su cobro sigue en la lista y en los totales, y su panel se abre',
    in_array($B1['id'], array_column($m->listar(CobroReglas::validarFiltros([])[1], 0, 500), 'id'), true)
    && $m->panelCliente(2) !== null && $m->resumen(7)['totales']['pendiente'] === 11000.0 && $cuenta('SELECT COUNT(*) FROM tenants') === 5);
wa_chk('en el ambito de una empresa no existe quitar de la lista de clientes: 404', $falla((new cobroModel($pdo, $reloj))->excluirCliente(2, true, null, 7), 404));
$r = $m->excluirCliente(2, false, null, 7);
wa_chk('devolverla a la lista: excluido false, y lo de antes (el motivo) queda para la bitacora',
    $exito($r) && $r[1] === ['client_id' => 2, 'excluido' => false, 'motivo' => null, 'repetido' => false, 'anterior' => ['excluido' => true, 'motivo' => 'Solo certificacion']], $r);
$r = $m->excluirCliente(2, false, null, 7);
wa_chk('devolverla otra vez es lo mismo que una vez (repetido); el directorio vuelve a traer a los 5 y ninguno esta excluido',
    $exito($r) && $r[1]['repetido'] === true && array_column($m->buscarClientes('', cobroModel::MAX_DIRECTORIO), 'id') === [1, 2, 4, 3, 5]
    && !in_array(true, array_column($m->buscarClientes('', cobroModel::MAX_DIRECTORIO, true), 'excluido'), true));
$pdo->exec("INSERT INTO tenants (id, nombre, rnc) VALUES (6, 'Zeta Sin Nada', '101000066')");
$r = $m->excluirCliente(6, true, null, 7);
wa_chk('un tenant sin cobros ni cobro mensual se quita sin problema, y su marca se va sola si se borra el tenant (CASCADE)',
    $exito($r) && $r[1]['repetido'] === false && $cuenta('SELECT COUNT(*) FROM cobro_excluidos') === 1
    && $pdo->exec('DELETE FROM tenants WHERE id = 6') === 1 && $cuenta('SELECT COUNT(*) FROM cobro_excluidos') === 0 && $cuenta('SELECT COUNT(*) FROM tenants') === 5, $r);

echo "\n== Parte 3: panel del tenant ==\n";
$p = $m->panelCliente(1);
$fx['panel'] = $p;
$fx['config'] = $m->obtenerConfig(1);
wa_chk('panel de Alfa: su nombre, su configuracion actual (6000, dia 31) y sus 2 cobros del mas nuevo al mas viejo, el de octubre con sus 2 pagos',
    $p['cliente']['nombre'] === 'Alfa Comercial SRL' && $p['cliente']['email'] === 'admin@alfa.test' && $p['config']['monto_mensual'] === 6000.0 && $p['config']['dia_vencimiento'] === 31
    && array_column($p['cobros'], 'periodo') === ['2026-11', '2026-10'] && count($p['cobros'][1]['pagos']) === 2, array_column($p['cobros'], 'periodo'));
wa_chk('resumen de Alfa: pendiente 6,000 (noviembre), nada vencido, el proximo vencimiento es el del 30; cobrado 5,000 de 11,000 facturados',
    $p['resumen']['saldo_pendiente'] === 6000.0 && $p['resumen']['saldo_vencido'] === 0.0 && $p['resumen']['cobros_abiertos'] === 1 && $p['resumen']['cobros_vencidos'] === 0
    && $p['resumen']['proximo_vencimiento']['periodo'] === '2026-11' && $p['resumen']['proximo_vencimiento']['fecha_vencimiento'] === '2026-11-30' && $p['resumen']['vencido_desde'] === null
    && $p['resumen']['total_cobrado'] === 5000.0 && $p['resumen']['total_facturado'] === 11000.0, $p['resumen']);
wa_chk('lo que se le sugiere para el proximo cobro de Alfa: diciembre 2026, 6000 y vence el 31', $p['sugerido'] === ['periodo' => '2026-12', 'monto' => 6000.0, 'fecha_vencimiento' => '2026-12-31', 'descripcion' => 'Mensualidad Fiscalpoint'], $p['sugerido']);
$pg = $m->panelCliente(3);
$fx['panel-sin-config'] = $pg;
wa_chk('panel de Gamma (sin configuracion): config null, 300 vencidos desde el 1 de noviembre, sin proximo vencimiento y con el recordatorio a la vista; sugiere solo el periodo (diciembre)',
    $pg['config'] === null && $pg['resumen']['saldo_vencido'] === 300.0 && $pg['resumen']['vencido_desde'] === '2026-11-01' && $pg['resumen']['proximo_vencimiento'] === null
    && count($pg['recordatorios']) === 1 && $pg['recordatorios'][0]['canal'] === 'llamada' && $pg['sugerido']['periodo'] === '2026-12' && $pg['sugerido']['monto'] === null
    && $pg['sugerido']['fecha_vencimiento'] === null, $pg);
wa_chk('el panel de un tenant que no existe: null', $m->panelCliente(999) === null);
$r = $m->crear($nuevo(4, '2027-01'), 7);
wa_chk('crear con SOLO el tenant (Delta tiene configuracion): usa el periodo, el monto y el dia pedidos (enero 2027, 2000, el 15)', $exito($r) && $r[1]['periodo'] === '2027-01' && $r[1]['monto'] === 2000.0 && $r[1]['fecha_vencimiento'] === '2027-01-15', $r);

echo "\n== Parte 4: quien se cobra, y un grupo se cobra una sola vez ==\n";
$pdoG = cobros_tenants_base();
cobros_tenants_sembrar_grupos($pdoG);
$mg = new cobroModel($pdoG, $reloj, cobroModel::AMBITO_TENANTS);
$mg->guardarConfig(14, $cfg(150000, 15), 7);               // Agro: cabeza en certecf con su cobro mensual ACTIVO
$mg->guardarConfig(17, $cfg(100000, 15, null, false), 7);  // certecf con el cobro mensual APAGADO
// Sobra de una hermana (en produccion hay dos): no debe contar para nada ni mostrarse.
$pdoG->exec("INSERT INTO cobro_config (client_id, monto_mensual, dia_vencimiento, activo, created_at) VALUES (12, 500, 15, 1, '2026-11-19 00:00:00')");
$mg->excluirCliente(18, true, 'Interna', 7);
$ids = static fn(array $r): array => array_column($r, 'id');
$visibles = $mg->buscarClientes('', cobroModel::MAX_DIRECTORIO);
$todas = $mg->buscarClientes('', cobroModel::MAX_DIRECTORIO, true);
$fx['directorio-grupos'] = $todas;
wa_chk('por defecto solo salen las unidades que se cobran: Agro (14, certecf pero con cobro mensual activo), App Normal (16), Holding (10, cabeza en produccion), Sin Cabeza (19) y Zeta (22, su hermana 23 esta en produccion)',
    $ids($visibles) === [14, 16, 10, 19, 22], $ids($visibles));
wa_chk('con "excluidos" salen todas las unidades (cabezas), tambien la que solo certifico (13), la de cobro mensual apagado (17) y la marcada (18)',
    $ids($todas) === [14, 16, 17, 10, 18, 19, 13, 22], $ids($todas));
wa_chk('...y cada una dice si se cobra y por que no: la marca gana (excluido) y lo demas es "solo_certificacion"',
    array_column($todas, 'facturable') === [true, true, false, true, false, true, false, true]
    && array_column($todas, 'motivo_oculto') === [null, null, 'solo_certificacion', null, 'excluido', null, 'solo_certificacion', null]
    && array_column($todas, 'excluido') === [false, false, false, false, true, false, false, false], array_column($todas, 'motivo_oculto'));
wa_chk('una empresa de un grupo que no es la cabeza (11, 12, 15, 20, 23) nunca es una fila', array_intersect([11, 12, 15, 20, 23], $ids($todas)) === [], $ids($todas));
$g = array_column($todas, null, 'id');
$hermana = static fn(int $id, string $nombre, string $ambiente): array => ['id' => $id, 'nombre' => $nombre, 'rnc' => '1020000' . $id, 'tipo' => 'integracion', 'ambiente' => $ambiente, 'paga' => true, 'motivo_sin_pago' => null];
// Un grupo sin marcas: la cabeza paga y todas las hermanas pagan.
$grupoDe = static fn(int $cabeza, string $nombre, array $miembros): array => ['cabeza_id' => $cabeza, 'cabeza_nombre' => $nombre, 'cabeza_rnc' => '1020000' . $cabeza, 'cabeza_paga' => true, 'cabeza_motivo_sin_pago' => null, 'miembros' => $miembros];
wa_chk('el grupo 10 es UNA fila con los datos de su cabeza y sus dos hermanas, por id (11 en produccion, 12 en certificacion)',
    $g[10]['nombre'] === 'Holding Cabeza SRL' && $g[10]['tipo'] === 'integracion' && $g[10]['ambiente'] === 'ecf'
    && $g[10]['grupo'] === $grupoDe(10, 'Holding Cabeza SRL', [$hermana(11, 'Holding Hermana Uno', 'ecf'), $hermana(12, 'Holding Hermana Dos', 'certecf')]), $g[10]['grupo']);
wa_chk('una empresa sin grupo trae grupo null; el grupo 14 (todo en certificacion) trae su hermana 15; si la cabeza ya no existe (99) manda la de menor id (19) y su hermana es la 20',
    $g[16]['grupo'] === null && $g[14]['grupo'] === $grupoDe(14, 'Agro Cabeza', [$hermana(15, 'Agro Hermana', 'certecf')])
    && $g[19]['grupo'] === $grupoDe(19, 'Sin Cabeza Uno', [$hermana(20, 'Sin Cabeza Dos', 'ecf')]), [$g[16]['grupo'], $g[19]['grupo']]);
wa_chk('el cobro mensual de la cabeza (Agro: 1,500) es el del grupo; lo que sobro en una hermana (12) no cuenta: la cabeza 10 sigue sin cobro mensual',
    $g[14]['config']['monto_mensual'] === 1500.0 && $g[14]['config']['activo'] === true && $g[10]['config'] === null && $g[10]['con_config'] === false, $g[10]['config']);
$mg->guardarConfig(14, $cfg(150000, 15, null, false), 7);
$agroApagada = array_column($mg->buscarClientes('', cobroModel::MAX_DIRECTORIO, true), null, 'id')[14];
wa_chk('si se apaga el cobro mensual de Agro deja de cobrarse (sale de la lista y dice "solo_certificacion")...',
    !in_array(14, $ids($mg->buscarClientes('', cobroModel::MAX_DIRECTORIO)), true) && $agroApagada['facturable'] === false && $agroApagada['motivo_oculto'] === 'solo_certificacion', $agroApagada['motivo_oculto']);
$mg->guardarConfig(14, $cfg(150000, 15), 7);
wa_chk('...y al volver a activarlo regresa', in_array(14, $ids($mg->buscarClientes('', cobroModel::MAX_DIRECTORIO)), true));
wa_chk('buscar por el nombre o el RNC de una hermana trae la fila de su grupo, no la de ella; y la que solo certifico solo sale pidiendo las ocultas',
    $ids($mg->buscarClientes('Hermana Uno')) === [10] && $ids($mg->buscarClientes('102000012')) === [10] && $ids($mg->buscarClientes('102000023')) === [22]
    && $ids($mg->buscarClientes('Holding Cabeza')) === [10] && $mg->buscarClientes('Solo Certificacion') === []
    && $ids($mg->buscarClientes('Solo Certificacion', 20, true)) === [13]);
$mg->guardarConfig(10, $cfg(50000, 15), 7);
$cobroGrupo = $mg->crear($nuevo(10, '2027-02'), 7);
$conCobro = $mg->buscarClientes('', cobroModel::MAX_DIRECTORIO, true);
$fila10 = array_column($conCobro, null, 'id')[10];
wa_chk('el grupo se cobra UNA vez: el cobro es de la cabeza (500), la fila del grupo lo trae, lo del directorio cuadra con el dashboard y ninguna hermana tiene cobros',
    $exito($cobroGrupo) && $fila10['saldo_pendiente'] === 500.0 && $fila10['con_config'] === true && $fila10['cobros_abiertos'] === 1
    && array_sum(array_column($conCobro, 'saldo_pendiente')) === $mg->resumen(7)['totales']['pendiente']
    && (int) $pdoG->query('SELECT COUNT(*) FROM cobros WHERE client_id <> 10')->fetchColumn() === 0, $fila10);
$r = $mg->excluirCliente(22, true, 'Interna', 7);
wa_chk('marcar a la cabeza de un grupo (22) lo oculta entero: sale de la lista, su hermana (23) no aparece y dice "excluido"',
    $exito($r) && !in_array(22, $ids($mg->buscarClientes('', cobroModel::MAX_DIRECTORIO)), true)
    && !in_array(23, $ids($mg->buscarClientes('', cobroModel::MAX_DIRECTORIO, true)), true)
    && array_column($mg->buscarClientes('', cobroModel::MAX_DIRECTORIO, true), null, 'id')[22]['motivo_oculto'] === 'excluido', $r);
wa_chk('...y devolverla la regresa con su grupo', $exito($mg->excluirCliente(22, false, null, 7)) && in_array(22, $ids($mg->buscarClientes('', cobroModel::MAX_DIRECTORIO)), true));

echo "\n== Parte 4: una empresa de un grupo no tiene cobro propio ==\n";
$antesConfig = (int) $pdoG->query('SELECT COUNT(*) FROM cobro_config')->fetchColumn();
$antesCobros = (int) $pdoG->query('SELECT COUNT(*) FROM cobros')->fetchColumn();
$rc = $mg->crear($nuevo(12, '2027-03'), 7);
wa_chk('configurar el cobro mensual de una hermana (11; y 15, la hermana de Agro) se rechaza: 409 MIEMBRO_DE_GRUPO',
    $falla($mg->guardarConfig(11, $cfg(10000, 5), 7), 409, 'MIEMBRO_DE_GRUPO') && $falla($mg->guardarConfig(15, $cfg(10000, 5), 7), 409, 'MIEMBRO_DE_GRUPO'));
wa_chk('crear un cobro a nombre de una hermana (12) tambien: 409, con la cabeza (10, Holding Cabeza SRL) en data y en el texto',
    $falla($rc, 409, 'MIEMBRO_DE_GRUPO') && ($rc[4] ?? null) === ['cabeza_id' => 10, 'cabeza_nombre' => 'Holding Cabeza SRL'] && str_contains((string) $rc[1], 'Holding Cabeza SRL'), $rc);
wa_chk('...y marcar a una hermana (11) "no es un cliente" tambien: la marca va en la cabeza', $falla($mg->excluirCliente(11, true, null, 7), 409, 'MIEMBRO_DE_GRUPO'));
$rg = $mg->guardarConfig(20, $cfg(10000, 5), 7);
wa_chk('si la cabeza ya no existe, la que se nombra es la de menor id: la hermana 20 apunta a la 19', $falla($rg, 409, 'MIEMBRO_DE_GRUPO') && ($rg[4]['cabeza_id'] ?? null) === 19, $rg);
wa_chk('nada se escribio con esas cinco peticiones rechazadas: mismas configuraciones y mismos cobros',
    (int) $pdoG->query('SELECT COUNT(*) FROM cobro_config')->fetchColumn() === $antesConfig && (int) $pdoG->query('SELECT COUNT(*) FROM cobros')->fetchColumn() === $antesCobros);
wa_chk('la cabeza (10) si puede tener su cobro mensual y su cobro', $exito($mg->guardarConfig(10, $cfg(60000, 15), 7)) && $exito($mg->crear($nuevo(10, '2027-03'), 7)));

echo "\n== Parte 4: quien debe plata no desaparece de la lista ==\n";
$enLista = static fn(int $id): ?array => array_column($mg->buscarClientes('', cobroModel::MAX_DIRECTORIO), null, 'id')[$id] ?? null;
$c14 = $mg->crear($nuevo(14, '2027-04'), 7);
$mg->guardarConfig(14, $cfg(150000, 15, null, false), 7);
wa_chk('Agro (certecf) con un cobro abierto de 1,500 SIGUE en la lista aunque se apague su cobro mensual: facturable y sin motivo_oculto (alguien que debe no desaparece)',
    $exito($c14) && $enLista(14) !== null && $enLista(14)['facturable'] === true && $enLista(14)['motivo_oculto'] === null && $enLista(14)['saldo_pendiente'] === 1500.0, $enLista(14));
$mg->cancelar($c14[1]['id'], 'prueba', 7);
wa_chk('...y cuando ya no debe nada (cobro cancelado) y su cobro mensual sigue apagado, vuelve a "solo_certificacion" y sale de la lista', $enLista(14) === null
    && array_column($mg->buscarClientes('', cobroModel::MAX_DIRECTORIO, true), null, 'id')[14]['motivo_oculto'] === 'solo_certificacion');
$c17 = $mg->crear($nuevo(17, '2027-04', '2027-04-15', 20000), 7);
wa_chk('una de certificacion que nunca tuvo cobro mensual activo (17) a la que se le creo un cobro de 200 tambien se queda en la lista mientras lo deba',
    $exito($c17) && $enLista(17) !== null && $enLista(17)['facturable'] === true && $enLista(17)['saldo_pendiente'] === 200.0, $enLista(17));
$mg->cancelar($c17[1]['id'], 'prueba', 7);
wa_chk('...y al cancelarse sale otra vez', $enLista(17) === null);

echo "\n== Parte 4: la busqueda de la lista de cobros tambien encuentra el grupo por una hermana ==\n";
$cobrosDe = static fn(string $q): array => array_column($mg->listar(CobroReglas::validarFiltros(['query' => $q])[1], 0, 50), 'client_id');
$cuantos = static fn(string $q): int => $mg->contar(CobroReglas::validarFiltros(['query' => $q])[1]);
wa_chk('buscar en la lista el nombre (Hermana Uno) o el RNC (102000012) de una hermana del grupo 10 trae sus 2 cobros, que son los de la cabeza, y contar da lo mismo',
    $cobrosDe('Hermana Uno') === [10, 10] && $cobrosDe('102000012') === [10, 10] && $cuantos('Hermana Uno') === 2, [$cobrosDe('Hermana Uno'), $cuantos('Hermana Uno')]);
wa_chk('...tambien en el grupo de Agro (14): buscar a su hermana por RNC trae el cobro (cancelado) de la cabeza; y un texto que no es de nadie no trae nada',
    $cobrosDe('102000015') === [14] && $cobrosDe('Nadie Se Llama Asi') === [] && $cuantos('Nadie Se Llama Asi') === 0, $cobrosDe('102000015'));
wa_chk('buscar por el nombre de la cabeza sigue funcionando y no mezcla grupos: Holding Cabeza trae solo lo de 10', $cobrosDe('Holding Cabeza') === [10, 10]);

echo "\n== Parte 5: que empresas de un grupo pagan ==\n";
$pdoP = cobros_tenants_base();
cobros_tenants_sembrar_pagos($pdoP);
$mp = new cobroModel($pdoP, $reloj, cobroModel::AMBITO_TENANTS);
$r1 = $mp->marcarPago(30, false, 'Cabeza no paga', 7);  // la cabeza no paga
$mp->marcarPago(33, false, 'Solo certifico', 7);
$mp->marcarPago(41, false, null, 7);                    // la unica de produccion del grupo 40
$mp->marcarPago(70, false, null, 7);
// Marcas puestas a mano que NO deben aplicar: una empresa sola (50) y un grupo donde TODAS estan marcadas (60 y 61).
$pdoP->exec("INSERT INTO cobro_sin_pago (client_id, created_at) VALUES (50, '2026-11-19 00:00:00'), (60, '2026-11-19 00:00:00'), (61, '2026-11-19 00:00:00')");
$todasP = $mp->buscarClientes('', cobroModel::MAX_DIRECTORIO, true);
$fx['directorio-quien-paga'] = $todasP;
$fx['paga'] = $r1[1] ?? [];
$p = array_column($todasP, null, 'id');
wa_chk('la primera marca devuelve lo de antes (paga, sin motivo) para la bitacora',
    $exito($r1) && $r1[1] === ['client_id' => 30, 'paga' => false, 'motivo' => 'Cabeza no paga', 'repetido' => false, 'anterior' => ['paga' => true, 'motivo' => null]], $r1);
wa_chk('el grupo 30, con su cabeza sin pagar, se nombra por la que paga de menor id (31): su nombre y RNC; el correo sale del de la cabeza (31 no tiene admin)',
    $p[30]['nombre'] === 'Pago Hermana Uno' && $p[30]['rnc'] === '103000031' && $p[30]['email'] === 'admin@cabeza.test', [$p[30]['nombre'], $p[30]['rnc'], $p[30]['email']]);
wa_chk('...y trae quien es la cabeza y que no paga (con su motivo), y cada hermana con si paga: 31 y 32 si, 33 no',
    $p[30]['grupo']['cabeza_id'] === 30 && $p[30]['grupo']['cabeza_nombre'] === 'Pago Cabeza' && $p[30]['grupo']['cabeza_rnc'] === '103000030'
    && $p[30]['grupo']['cabeza_paga'] === false && $p[30]['grupo']['cabeza_motivo_sin_pago'] === 'Cabeza no paga'
    && array_column($p[30]['grupo']['miembros'], 'paga', 'id') === [31 => true, 32 => true, 33 => false]
    && array_column($p[30]['grupo']['miembros'], 'motivo_sin_pago', 'id') === [31 => null, 32 => null, 33 => 'Solo certifico'], $p[30]['grupo']);
wa_chk('el grupo 30 se cobra sin cobro mensual: las que pagan (31 y 32) estan en produccion',
    $p[30]['facturable'] === true && $p[30]['motivo_oculto'] === null && $p[30]['con_config'] === false);
wa_chk('el grupo 40, cuya unica empresa de produccion (41) no paga, NO se cobra (solo_certificacion) y la cabeza, que paga, sigue dando el nombre',
    $p[40]['facturable'] === false && $p[40]['motivo_oculto'] === 'solo_certificacion' && $p[40]['nombre'] === 'Prod Cabeza', [$p[40]['facturable'], $p[40]['motivo_oculto']]);
$mp->marcarPago(41, true, null, 7);
$p40 = array_column($mp->buscarClientes('', cobroModel::MAX_DIRECTORIO, true), null, 'id')[40];
wa_chk('...y al decir que 41 si paga, el grupo 40 vuelve a cobrarse', $p40['facturable'] === true && $p40['motivo_oculto'] === null);
wa_chk('una marca vieja en una empresa SOLA (50) no aplica: se nombra a si misma, no tiene grupo y se cobra',
    $p[50]['nombre'] === 'Sola Marcada' && $p[50]['grupo'] === null && $p[50]['facturable'] === true);
wa_chk('un grupo donde TODAS estan marcadas (60 y 61) no aplica las marcas: la cabeza da el nombre, todas cuentan como que pagan y se cobra',
    $p[60]['nombre'] === 'Todas Marcadas A' && $p[60]['grupo']['cabeza_paga'] === true && array_column($p[60]['grupo']['miembros'], 'paga') === [true] && $p[60]['facturable'] === true, $p[60]['grupo']);
wa_chk('tipo, ambiente y estado siguen a la empresa que se muestra: el grupo 70 (cabeza en produccion que no paga) se muestra como su pagadora de certificacion (71)',
    $p[70]['nombre'] === 'Dist Pagadora' && $p[70]['tipo'] === 'integracion' && $p[70]['ambiente'] === 'certecf' && $p[70]['grupo']['cabeza_paga'] === false, [$p[70]['nombre'], $p[70]['ambiente']]);
wa_chk('buscar por el nombre PROPIO o el RNC de la cabeza que no paga, de la que solo certifico o de una que paga encuentra la fila del grupo (30)',
    $ids($mp->buscarClientes('Pago Cabeza')) === [30] && $ids($mp->buscarClientes('103000033')) === [30] && $ids($mp->buscarClientes('Pago Hermana Uno')) === [30]);
$mp->guardarConfig(30, $cfg(50000, 15), 7);
$cobroP = $mp->crear($nuevo(30, '2027-02'), 7);
$listaP = $mp->listar(CobroReglas::validarFiltros([])[1], 0, 50);
$panelP = $mp->panelCliente(30);
wa_chk('el cobro vive en la cabeza (30) pero la lista y el panel lo muestran a nombre de quien paga (Pago Hermana Uno)',
    $exito($cobroP) && $cobroP[1]['client_id'] === 30 && $listaP[0]['client_id'] === 30 && $listaP[0]['cliente']['nombre'] === 'Pago Hermana Uno'
    && $panelP['cliente']['nombre'] === 'Pago Hermana Uno', $listaP[0]['cliente'] ?? null);
wa_chk('...y en la lista de cobros tambien se encuentra buscando el nombre propio de la cabeza (Pago Cabeza) o el RNC de la que solo certifico (103000033)',
    array_unique(array_column($mp->listar(CobroReglas::validarFiltros(['query' => 'Pago Cabeza'])[1], 0, 50), 'client_id')) === [30]
    && array_unique(array_column($mp->listar(CobroReglas::validarFiltros(['query' => '103000033'])[1], 0, 50), 'client_id')) === [30]);
$rm = $mp->guardarConfig(32, $cfg(10000, 5), 7);
wa_chk('MIEMBRO_DE_GRUPO nombra a la que se muestra (Pago Hermana Uno) y deja el id de la cabeza real (30)',
    $falla($rm, 409, 'MIEMBRO_DE_GRUPO') && ($rm[4] ?? null) === ['cabeza_id' => 30, 'cabeza_nombre' => 'Pago Hermana Uno'] && str_contains((string) $rm[1], 'Pago Hermana Uno'), $rm);
wa_chk('marcar a una empresa que no esta en un grupo de dos o mas (50): 409 NO_ES_DE_UN_GRUPO (se usa "No es cliente")',
    $falla($mp->marcarPago(50, false, null, 7), 409, 'NO_ES_DE_UN_GRUPO'));
wa_chk('marcar a la unica que queda pagando (71: la cabeza 70 ya esta marcada): 409 ULTIMA_QUE_PAGA', $falla($mp->marcarPago(71, false, null, 7), 409, 'ULTIMA_QUE_PAGA'));
$rr = $mp->marcarPago(30, false, 'otro motivo', 7);
wa_chk('repetir una marca no cambia nada (repetido) y deja el motivo de la primera',
    $exito($rr) && $rr[1]['repetido'] === true && $rr[1]['motivo'] === 'Cabeza no paga' && $rr[1]['anterior'] === ['paga' => false, 'motivo' => 'Cabeza no paga'], $rr);
$rq = $mp->marcarPago(50, true, null, 7);
wa_chk('quitar una marca vieja de una empresa sola (50) se puede: paga true, y lo de antes (anterior paga false) queda para la bitacora',
    $exito($rq) && $rq[1]['paga'] === true && $rq[1]['repetido'] === false && $rq[1]['anterior']['paga'] === false, $rq);
$rs = $mp->marcarPago(32, true, null, 7);
wa_chk('decir que paga a quien ya paga es repetido', $exito($rs) && $rs[1]['repetido'] === true && $rs[1]['anterior']['paga'] === true, $rs);
wa_chk('una empresa que no existe: 404; en el ambito de una empresa no existe marcar: 404',
    $falla($mp->marcarPago(999, false, null, 7), 404) && $falla((new cobroModel($pdoP, $reloj))->marcarPago(30, false, null, 7), 404));

$carpetaFixtures = wa_arg('fixtures');
if ($carpetaFixtures !== null && $carpetaFixtures !== '') {
    if (!is_dir($carpetaFixtures)) {
        mkdir($carpetaFixtures, 0777, true);
    }
    foreach ($fx as $nombre => $datos) {
        // El sobre que manda cobroController: {status, data} (la lista trae ademas su paginacion).
        $sobre = $nombre === 'lista' ? ['status' => true, 'data' => $datos['data'], 'pagination' => $datos['pagination']] : ['status' => true, 'data' => $datos];
        file_put_contents($carpetaFixtures . '/' . $nombre . '.json', json_encode($sobre, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    }
    echo "\n(fixtures escritos en {$carpetaFixtures}: " . implode(', ', array_keys($fx)) . ")\n";
}

echo "\n== Parte 3: la base queda consistente despues de todo ==\n";
wa_chk('invariantes finales: saldos = suma de pagos vigentes, sin sobrepagos, sin cancelados con pagos, sin duplicados', cobros_invariantes($pdo) === [], cobros_invariantes($pdo));
wa_chk('ningun pago se borro: el anulado sigue en la tabla', $cuenta('SELECT COUNT(*) FROM cobro_pagos WHERE anulado_at IS NOT NULL') === 1);
wa_chk('ningun cobro se borro: el cancelado sigue en la tabla', $cuenta('SELECT COUNT(*) FROM cobros WHERE cancelado_at IS NOT NULL') === 1);
wa_chk('ningun tenant cambio: el modelo nunca escribe en tenants ni en users', $cuenta('SELECT COUNT(*) FROM tenants') === 5 && $cuenta('SELECT COUNT(*) FROM users') === 6
    && $cuenta("SELECT COUNT(*) FROM tenants WHERE nombre = 'Alfa Comercial SRL' AND activo = 1") === 1);

wa_fin();
