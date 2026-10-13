<?php
/**
 * test_pos_scratch.php — corre las suites del POS (tools/test_pos_*.php) sobre las bases scratch:
 * prepara las bases, levanta la DGII simulada (puerto 8298) y dos APIs multi-tenant (8299 y 8297, la
 * segunda con POS_DGII_TIMEOUT=30 para las pruebas de concurrencia; WA_PUERTO_OFFSET se suma) y corre
 * cada suite en serie como proceso aparte. Solo muestra el resumen y lo que fallo, con host, usuario,
 * claves y todo hex de 32+ caracteres tapados.
 *
 * Uso:  php -d extension=curl -d extension=openssl -d extension=pdo_mysql tools/test_pos_scratch.php
 *   [--api-root=<raiz>]   codigo y suites de OTRA raiz (la linea base: un git archive de origin/master)
 *   [--suites=a,b,...]    [--enforce] PERMISSIONS_ENFORCE=true (por defecto, sombra)   [--solo-preparar]
 * Sale con 1 si alguna suite falla.
 */

require_once __DIR__ . '/pos_scratch_prepare.php';

/** Suites por defecto, en orden. Las que no existan en la raiz probada se omiten. */
const POS_SCRATCH_SUITES = ['test_pos_backend', 'test_pos_venta', 'test_pos_cierre', 'test_pos_e31', 'test_pos_write_ahead'];

/**
 * Entorno de la API (multi-tenant). La master es la base de prueba; los tenants
 * traen sus credenciales cifradas en la tabla tenants. Sin DB_*: con varios
 * tenants la base sale de la tabla, y una API sin tenant debe fallar, no caer
 * en otra base.
 */
function pos_scratch_env_api(array $cred, string $master, array $secretos, string $mockUrl, array $cert, bool $enforce): array
{
    return [
        'MULTI_TENANT_ENABLED' => 'true',
        'PERMISSIONS_ENFORCE' => $enforce ? 'true' : 'false',
        'AUDIT_LOG_ENABLED' => 'true',
        'MASTER_DB_HOST' => $cred['host'], 'MASTER_DB_PORT' => $cred['port'], 'MASTER_DB_NAME' => $master,
        'MASTER_DB_USER' => $cred['user'], 'MASTER_DB_PASS' => $cred['pass'],
        'MASTER_ENCRYPTION_KEY' => $secretos['clave_maestra'],
        'POS_PIN_PEPPER' => $secretos['pepper'],
        'POS_DGII_TIMEOUT' => '5',
        'DGII_ECF_ENVIRONMENT' => 'testecf',
        'DGII_ECF_BASE_URL' => $mockUrl, 'DGII_FC_BASE_URL' => $mockUrl,
        'DGII_ECF_CERT_PATH' => $cert['path'], 'DGII_ECF_CERT_PASSWORD' => $cert['password'],
        'RNC_CONSULTA_URL' => $mockUrl . '/rnc/consulta',
    ];
}

/** Entorno de las suites: a quien llaman y como entran (los nombres de la cabecera de test_pos_venta.php). */
function pos_scratch_env_suites(array $cred, string $master, array $secretos, string $api, string $api2, string $mockUrl, string $mockDir): array
{
    return [
        'POS_TEST_BASE' => $api . '/api', 'POS_TEST_BASE_2' => $api2 . '/api',
        'POS_TEST_ADMIN_A' => 'admin_pos_a', 'POS_TEST_ADMIN_B' => 'admin_pos_b', 'POS_TEST_USER_A' => 'user_pos_a',
        'POS_TEST_PASS' => $secretos['clave_usuarios'],
        'MASTER_DB_HOST' => $cred['host'], 'MASTER_DB_PORT' => $cred['port'], 'MASTER_DB_NAME' => $master,
        'MASTER_DB_USER' => $cred['user'], 'MASTER_DB_PASS' => $cred['pass'],
        'POS_TEST_DGII_MOCK_DIR' => $mockDir,
        'DGII_ECF_BASE_URL' => $mockUrl, 'DGII_FC_BASE_URL' => $mockUrl,
        'RNC_CONSULTA_URL' => $mockUrl . '/rnc/consulta',
        'POS_DGII_TIMEOUT' => '5',
    ];
}

/**
 * '' si $raiz sirve para probar (index.php, el simulador y NINGUN .env); si no, el motivo. Un .env
 * en la raiz lo carga Database al arrancar: una peticion sin tenant podria caer en la base que
 * diga ese archivo.
 */
function pos_scratch_revisar_raiz(string $raiz): string
{
    if (!is_file($raiz . '/index.php') || !is_file($raiz . '/tools/mock_dgii_local.php')) {
        return 'no es la raiz de un API con index.php y tools/mock_dgii_local.php';
    }
    if (is_file($raiz . '/.env')) {
        return 'tiene un .env: Database lo cargaria y una peticion sin tenant podria caer en la base de ese archivo';
    }
    return '';
}

/**
 * Resumen que imprime una suite: "TODO OK (N verificaciones)", "F de N FALLARON"
 * (suites de master) o "N de M pruebas OK" (arnes del write-ahead).
 * @return array{total:int, fallos:int}|null null si no hay resumen
 */
function pos_scratch_resumen(string $salida): ?array
{
    if (preg_match('/^(\d+) de (\d+) FALLARON/m', $salida, $m) === 1) {
        return ['total' => (int) $m[2], 'fallos' => (int) $m[1]];
    }
    if (preg_match('/^(\d+) de (\d+) pruebas OK/m', $salida, $m) === 1) {
        return ['total' => (int) $m[2], 'fallos' => (int) $m[2] - (int) $m[1]];
    }
    if (preg_match('/^TODO OK \((\d+) verificaciones\)/m', $salida, $m) === 1) {
        return ['total' => (int) $m[1], 'fallos' => 0];
    }
    return null;
}

/** Tapa los secretos conocidos y todo hex de 32 o mas caracteres (tokens, claves, hashes). */
function pos_scratch_enmascarar(string $texto, array $secretos): string
{
    foreach ($secretos as $s) {
        if ((string) $s !== '') {
            $texto = str_replace((string) $s, '***', $texto);
        }
    }
    return (string) preg_replace('/\b[0-9a-fA-F]{32,}\b/', '<hex>', $texto);
}

/**
 * De lo que imprimio una suite, solo: cada [FALLO] con su linea "obtenido:", el
 * resumen y, si murio, la clase del error (nunca su mensaje: el de una
 * PDOException trae usuario y host). Todo enmascarado y cortado a 400 caracteres.
 */
function pos_scratch_filtrar(string $salida, array $secretos): string
{
    $out = [];
    $anteriorFallo = false;
    foreach (preg_split('/\R/', $salida) ?: [] as $l) {
        $esFallo = str_contains($l, '[FALLO]');
        $esDetalle = $anteriorFallo && preg_match('/^\s+obtenido:/', $l) === 1;
        $esResumen = preg_match('/^(TODO OK \(|\d+ de \d+ (FALLARON|pruebas OK))/', $l) === 1;
        if ($esFallo || $esDetalle || $esResumen) {
            $out[] = pos_scratch_enmascarar(mb_substr($l, 0, 400), $secretos);
        } elseif (preg_match('/(Fatal error|Parse error|Uncaught)/', $l) === 1) {
            $out[] = preg_match('/Uncaught\s+([A-Za-z_\\\\]+)/', $l, $m) === 1
                ? '[FALLO] excepcion sin atrapar: ' . $m[1]
                : '[FALLO] ' . pos_scratch_enmascarar(mb_substr((string) preg_replace('/ in \S+:\d+.*$/', '', $l), 0, 160), $secretos);
        }
        $anteriorFallo = $esFallo;
    }
    return implode("\n", $out);
}

/** Corre una suite (proceso aparte, cwd = raiz del API probada). @return array{codigo:int, salida:string} */
function pos_scratch_correr_suite(string $ruta, string $raiz, array $env): array
{
    $nulo = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $proc = proc_open(array_merge([PHP_BINARY], wa_php_flags(), [$ruta]), [0 => ['file', $nulo, 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]],
        $pipes, $raiz, array_merge(getenv(), array_map('strval', $env)));
    if (!is_resource($proc)) {
        return ['codigo' => -1, 'salida' => ''];
    }
    $salida = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    return ['codigo' => proc_close($proc), 'salida' => $salida];
}

function pos_scratch_main(): never
{
    $info = pos_scratch_preparar();
    if (wa_arg('solo-preparar') !== null) {
        echo "Bases del POS listas.\n";
        exit(0);
    }
    $cred = $info['cred'];
    $secretos = pos_scratch_secretos($cred['pass']);
    $pedida = (string) wa_arg('api-root', '');
    $raiz = $pedida !== '' ? (string) realpath($pedida) : dirname(__DIR__);
    $problema = $raiz === '' ? 'la ruta de --api-root no existe' : pos_scratch_revisar_raiz($raiz);
    if ($problema !== '') {
        echo "[FALLO] la raiz del API a probar no sirve: {$problema}\n";
        exit(1);
    }
    // El nombre del servidor lleva la raiz: un servidor viejo de otra raiz en el
    // mismo puerto se rechaza en vez de reutilizarse con el codigo equivocado.
    $etiqueta = substr(md5($raiz), 0, 6);
    $cert = wa_cert_prueba();
    $dirMock = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wa_pos_mock_state';
    if (!is_dir($dirMock)) {
        mkdir($dirMock);
    }
    foreach (['log.txt', 'recibidos.txt', 'recibidos_ecf.json'] as $f) {
        @unlink($dirMock . DIRECTORY_SEPARATOR . $f);
    }
    file_put_contents($dirMock . DIRECTORY_SEPARATOR . 'modo.txt', 'acepta');

    $mock = wa_servidor('pos_mock_' . $etiqueta, 'tools/mock_dgii_local.php', 8298, ['MOCK_DGII_STATE' => $dirMock], $raiz);
    $envApi = pos_scratch_env_api($cred, $info['master'], $secretos, $mock, $cert, wa_arg('enforce') !== null);
    $api = wa_servidor('pos_api_' . $etiqueta, 'index.php', 8299, $envApi, $raiz);
    $api2 = wa_servidor('pos_api2_' . $etiqueta, 'index.php', 8297, array_merge($envApi, ['POS_DGII_TIMEOUT' => '30']), $raiz);
    $envSuites = pos_scratch_env_suites($cred, $info['master'], $secretos, $api, $api2, $mock, $dirMock);
    $ocultos = [$cred['pass'], $cred['user'], $cred['host'], $secretos['clave_usuarios'], $secretos['clave_maestra'], $secretos['pepper'], $cert['password']];

    $suites = array_values(array_filter(array_map('trim', explode(',', (string) wa_arg('suites', implode(',', POS_SCRATCH_SUITES))))));
    echo 'API ' . ($pedida !== '' ? 'de ' . basename($raiz) : 'de esta rama') . " en {$api}; DGII simulada en {$mock}\n";
    $corridas = 0;
    $verdes = 0;
    foreach ($suites as $suite) {
        $ruta = $raiz . '/tools/' . $suite . '.php';
        if (!is_file($ruta)) {
            echo "== {$suite}: no existe en esta raiz, se omite\n";
            continue;
        }
        $corridas++;
        $r = pos_scratch_correr_suite($ruta, $raiz, $envSuites);
        $resumen = pos_scratch_resumen($r['salida']);
        $ok = $r['codigo'] === 0 && $resumen !== null && $resumen['fallos'] === 0;
        $verdes += $ok ? 1 : 0;
        echo '== ' . $suite . ': ' . ($ok ? "OK ({$resumen['total']} verificaciones)" : 'FALLO (codigo ' . $r['codigo'] . ($resumen === null ? ', sin resumen' : '') . ')') . "\n";
        if (!$ok) {
            $detalle = pos_scratch_filtrar($r['salida'], $ocultos);
            echo ($detalle !== '' ? $detalle : '(la suite no imprimio nada util)') . "\n";
        }
    }
    echo "\nsuites OK: {$verdes} de {$corridas}\n";
    exit($corridas > 0 && $verdes === $corridas ? 0 : 1);
}

// CLI propio: solo cuando se ejecuta este archivo, nunca al incluirlo.
if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    pos_scratch_main();
}
