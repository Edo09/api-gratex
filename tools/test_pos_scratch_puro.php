<?php
/**
 * test_pos_scratch_puro.php — lo del entorno de pruebas del POS que no necesita base de datos: la
 * guarda de las bases, los secretos derivados, el entorno de la API y las suites, la revision de la
 * raiz a probar y el filtro de lo que se imprime de una suite.   Uso:  php tools/test_pos_scratch_puro.php
 */

require_once __DIR__ . '/write_ahead_test_lib.php';
require_once __DIR__ . '/pos_scratch_guard.php';
require_once __DIR__ . '/pos_scratch_prepare.php';
require_once __DIR__ . '/test_pos_scratch.php';

echo "1) Guarda de las bases\n";
$remoto = static fn(array $bases): bool => pos_scratch_decidir('db.example.com', $bases)['ok'];
wa_chk('servidor local: pasa aunque la base no tenga marca ni el nombre', pos_scratch_decidir('127.0.0.1', ['gratex_master' => false])['ok'] === true);
wa_chk('local son localhost, LOCALHOST, ::1 y [::1]; no 127.0.0.2, localhost.example.com ni vacio',
    pos_scratch_es_loopback('localhost') && pos_scratch_es_loopback('LOCALHOST') && pos_scratch_es_loopback('::1') && pos_scratch_es_loopback('[::1]')
    && !pos_scratch_es_loopback('127.0.0.2') && !pos_scratch_es_loopback('localhost.example.com') && !pos_scratch_es_loopback(''));
wa_chk('remoto con la master y el tenant marcados y bien nombrados: pasa', $remoto(['smhynzte_wa_scratch' => true, 'smhynzte_wa_scratch_sin_gate' => true]));
$sinMarca = pos_scratch_decidir('db.example.com', ['smhynzte_wa_scratch' => true, 'smhynzte_wa_scratch_sin_gate' => false]);
wa_chk('remoto con una base sin la marca: se niega y nombra la base', $sinMarca['ok'] === false
    && str_contains($sinMarca['motivo'], 'smhynzte_wa_scratch_sin_gate') && str_contains($sinMarca['motivo'], POS_SCRATCH_MARCA), $sinMarca);
wa_chk('remoto con otro nombre se niega aunque tenga la marca: wa_sin_marca, la de produccion, zzwa_scratch, wa_scratch_sin_gate_viejo',
    !$remoto(['smhynzte_wa_sin_marca' => true]) && !$remoto(['smhynzte_new_gratexdb' => true]) && !$remoto(['zzwa_scratch' => true])
    && !$remoto(['smhynzte_wa_scratch_sin_gate_viejo' => true]) && $remoto(['wa_scratch' => true]));
wa_chk('remoto sin ninguna base que comprobar: se niega', !$remoto([]));

echo "2) Secretos derivados de la clave de la base\n";
$s = pos_scratch_secretos('clave-de-prueba');
wa_chk('clave maestra y pepper de 64 hex, clave de usuarios de 32 hex',
    preg_match('/^[0-9a-f]{64}$/', $s['clave_maestra']) === 1 && preg_match('/^[0-9a-f]{64}$/', $s['pepper']) === 1
    && preg_match('/^[0-9a-f]{32}$/', $s['clave_usuarios']) === 1);
wa_chk('estables, distintos entre si y de la clave de la base', $s === pos_scratch_secretos('clave-de-prueba') && count(array_unique($s)) === 3
    && !in_array('clave-de-prueba', $s, true) && $s !== pos_scratch_secretos('otra-clave'));

echo "3) Entorno de la API y de las suites\n";
$cred = ['host' => 'db.example.com', 'port' => '3306', 'user' => 'u_prueba', 'pass' => 'clave-de-prueba'];
$cert = ['path' => '/tmp/cert.p12', 'password' => 'clave-del-cert'];
$envApi = pos_scratch_env_api($cred, 'smhynzte_wa_scratch_sin_gate', $s, 'http://127.0.0.1:8298', $cert, false);
wa_chk('API: multi-tenant, master de prueba, DGII y RNC simulados, sin DB_*, con la clave maestra y el pepper de prueba',
    $envApi['MULTI_TENANT_ENABLED'] === 'true' && $envApi['MASTER_DB_NAME'] === 'smhynzte_wa_scratch_sin_gate'
    && $envApi['DGII_ECF_BASE_URL'] === 'http://127.0.0.1:8298' && $envApi['RNC_CONSULTA_URL'] === 'http://127.0.0.1:8298/rnc/consulta'
    && !array_key_exists('DB_NAME', $envApi) && $envApi['MASTER_ENCRYPTION_KEY'] === $s['clave_maestra'] && $envApi['POS_PIN_PEPPER'] === $s['pepper']
    && $envApi['PERMISSIONS_ENFORCE'] === 'false' && pos_scratch_env_api($cred, 'x', $s, 'http://127.0.0.1:8298', $cert, true)['PERMISSIONS_ENFORCE'] === 'true');
$envSuites = pos_scratch_env_suites($cred, 'smhynzte_wa_scratch_sin_gate', $s, 'http://127.0.0.1:8299', 'http://127.0.0.1:8297', 'http://127.0.0.1:8298', '/tmp/mock');
wa_chk('suites: las dos APIs con /api, los tres usuarios, la clave y la carpeta del mock; nada apunta a produccion',
    $envSuites['POS_TEST_BASE'] === 'http://127.0.0.1:8299/api' && $envSuites['POS_TEST_BASE_2'] === 'http://127.0.0.1:8297/api'
    && $envSuites['POS_TEST_ADMIN_A'] === 'admin_pos_a' && $envSuites['POS_TEST_USER_A'] === 'user_pos_a' && $envSuites['POS_TEST_PASS'] === $s['clave_usuarios']
    && $envSuites['POS_TEST_DGII_MOCK_DIR'] === '/tmp/mock'
    && array_filter(array_merge($envApi, $envSuites), static fn($v) => preg_match('#gratex\.net|fiscalpoint\.com\.do#', (string) $v) === 1) === []);
$raizTmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wa_pos_raiz_' . getmypid();
mkdir($raizTmp . DIRECTORY_SEPARATOR . 'tools', 0777, true);
$sinNada = pos_scratch_revisar_raiz($raizTmp);
file_put_contents($raizTmp . '/index.php', '<?php');
file_put_contents($raizTmp . '/tools/mock_dgii_local.php', '<?php');
$buena = pos_scratch_revisar_raiz($raizTmp);
file_put_contents($raizTmp . '/.env', 'DB_NAME=otra');
$conEnv = pos_scratch_revisar_raiz($raizTmp);
foreach (['/.env', '/index.php', '/tools/mock_dgii_local.php'] as $f) {
    @unlink($raizTmp . $f);
}
@rmdir($raizTmp . '/tools');
@rmdir($raizTmp);
wa_chk('raiz a probar: sin index.php o sin el simulador no sirve; con los dos si; con un .env tampoco (Database lo cargaria)',
    $sinNada !== '' && $buena === '' && str_contains($conEnv, '.env'), [$sinNada, $buena, $conEnv]);

echo "4) Resumen de una suite\n";
wa_chk('"TODO OK (48 verificaciones)", "3 de 48 FALLARON" y "12 de 14 pruebas OK", tambien con fin de linea de Windows',
    pos_scratch_resumen("...\nTODO OK (48 verificaciones)\n") === ['total' => 48, 'fallos' => 0]
    && pos_scratch_resumen("x\n3 de 48 FALLARON\n") === ['total' => 48, 'fallos' => 3]
    && pos_scratch_resumen("\n12 de 14 pruebas OK\n") === ['total' => 14, 'fallos' => 2]
    && pos_scratch_resumen("a\r\nTODO OK (5 verificaciones)\r\n") === ['total' => 5, 'fallos' => 0]);
wa_chk('sin resumen: null', pos_scratch_resumen("PHP Fatal error: algo\n") === null);

echo "5) Lo que se imprime de una suite\n";
$ocultos = ['clave-de-prueba', 'db.example.com', $s['clave_usuarios']];
$token = str_repeat('ab', 32);
$f = pos_scratch_filtrar("1. Turno\n  [OK  ] abre\n  [FALLO] el cobro de {$s['clave_usuarios']}\n         obtenido: {\"token\":\"{$token}\",\"h\":\"db.example.com\"}\n  [OK  ] otra\n\n2 de 9 FALLARON\n", $ocultos);
wa_chk('deja el FALLO, su "obtenido:" y el resumen; quita los OK y los titulos; tapa la clave, el host y el token de 64 hex',
    str_contains($f, '[FALLO] el cobro') && str_contains($f, 'obtenido:') && str_contains($f, '2 de 9 FALLARON') && !str_contains($f, '[OK  ]')
    && !str_contains($f, '1. Turno') && !str_contains($f, $s['clave_usuarios']) && !str_contains($f, 'db.example.com') && !str_contains($f, $token), $f);
$f = pos_scratch_filtrar("PHP Fatal error:  Uncaught PDOException: SQLSTATE[HY000] [2002] getaddrinfo for db.example.com failed in /x/y.php:12\nStack trace:\n#0 usuario@db.example.com\n", $ocultos);
wa_chk('una excepcion sin atrapar sale solo con su clase: ni host ni mensaje', $f === '[FALLO] excepcion sin atrapar: PDOException', $f);
$f = pos_scratch_filtrar("PHP Fatal error:  Call to undefined method Foo::bar() in /x/y.php:7\n", $ocultos);
wa_chk('un error fatal de motor conserva su texto, sin archivo ni linea', $f === '[FALLO] PHP Fatal error:  Call to undefined method Foo::bar()', $f);

echo "6) Lo que la preparacion lee del repo\n";
$ddl = array_map('pos_scratch_ddl_master', ['tenants', 'pos_handoff_codes', 'pos_equipos']);
wa_chk('las tres tablas del master salen de db/master_schema.sql, cada una con su CREATE TABLE IF NOT EXISTS',
    preg_match('/^CREATE TABLE IF NOT EXISTS tenants \(/', $ddl[0]) === 1 && str_contains($ddl[1], 'fk_pos_handoff_tenant')
    && str_contains($ddl[2], 'uq_pos_equipo_token') && substr_count(implode('', $ddl), 'CREATE TABLE') === 3);
$defaults = (require dirname(__DIR__) . '/config/permissions.php')['defaults'];
wa_chk('config/permissions.php: el admin tiene "*" y el user tiene facturas pero no pos',
    $defaults['admin'] === ['*'] && in_array('facturas', $defaults['user'], true) && !in_array('pos', $defaults['user'], true));

wa_fin();
