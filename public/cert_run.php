<?php
/**
 * cert_run.php — Wrapper web de los runners de certificacion (fase 2/3/4).
 *
 * Sirve directo (bajo /api/public/). Reusa los runners CLI de tools/ sin
 * reescribir su logica: define STDOUT/STDERR hacia la salida HTTP, arma un
 * $argv desde la peticion y llama main($argv).
 *
 *   POST /api/public/cert_run.php
 *     token      = CERT_RUN_TOKEN (del .env)
 *     fase       = 2 | 3 | 4
 *     api_key    = token API del tenant (X-API-KEY con que se emite)
 *     client_id  = id del comprador de prueba (en la tabla clients del tenant)
 *     user_id    = id del usuario emisor (opcional)
 *     xlsx       = archivo del set de pruebas DGII (fase 2 y 3)
 *     [api, filter, case, exclude, counts, nota-wait-accepted, nota-poll, dry-run]
 *
 * La salida es texto plano en vivo (progreso del runner).
 */

header('Content-Type: text/plain; charset=utf-8');
@set_time_limit(0);
@ignore_user_abort(true);

// Streaming en vivo: vaciar buffers y autoflush para ver el progreso del runner.
@ob_implicit_flush(true);
while (ob_get_level() > 0) { @ob_end_flush(); }

// Los runners escriben con fwrite(STDOUT/STDERR); en web esas constantes no
// existen. Apuntarlas a la salida HTTP para reusar el runner sin tocarlo.
if (!defined('STDOUT')) { define('STDOUT', fopen('php://output', 'w')); }
if (!defined('STDERR')) { define('STDERR', fopen('php://output', 'w')); }

require_once __DIR__ . '/../src/Database.php';
Database::loadEnv();

$token = (string) ($_REQUEST['token'] ?? '');
$expected = getenv('CERT_RUN_TOKEN') ?: ($_ENV['CERT_RUN_TOKEN'] ?? '');
if ($expected === '') {
    http_response_code(403);
    exit("CERT_RUN_TOKEN no configurado en el .env del server.\n");
}
if (!hash_equals($expected, $token)) {
    http_response_code(403);
    exit("Token invalido.\n");
}

$fase = (string) ($_REQUEST['fase'] ?? '');
if (!in_array($fase, ['2', '3', '4'], true)) {
    http_response_code(400);
    exit("fase invalida (use 2, 3 o 4).\n");
}

// Default: ESTE deploy. Hardcodear gratex.net hacia que cualquier otro server
// corriera el set de pruebas contra el API de Gratex. OJO: con UseCanonicalName
// Off (el default de Apache) SERVER_NAME sale del header Host, asi que este
// valor NO es de fiar; la proteccion real de este script es CERT_RUN_TOKEN.
$defaultScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$defaultHost   = $_SERVER['SERVER_NAME'] ?? ($_SERVER['HTTP_HOST'] ?? 'gratex.net');
$apiBase = (string) ($_REQUEST['api'] ?? ($defaultScheme . '://' . $defaultHost . '/api'));
$apiKey  = (string) ($_REQUEST['api_key'] ?? '');
if ($apiKey === '') {
    http_response_code(422);
    exit("api_key (token del tenant) requerido.\n");
}

// Tenant tipo integracion: con api_secret los runners apuntan a /api/integracion/*
// en vez de /facturas. Cambia lo que hay que pasarles: no hay client_id/user_id
// (no hay DB donde vivan), el ambiente lo fuerza el servidor desde tenants.ambiente,
// y en fase 4 el e-NCF y el emisor los pone el runner.
$apiSecret = (string) ($_REQUEST['api_secret'] ?? '');
$esIntegracion = $apiSecret !== '';

$tmpFiles = [];
$tmpOut = tempnam(sys_get_temp_dir(), 'cert_out_');
$tmpFiles[] = $tmpOut;

$argv = ['cert_run'];

// Fase 2 y 3 necesitan el xlsx del set de pruebas.
if ($fase === '2' || $fase === '3') {
    if (!isset($_FILES['xlsx']) || ($_FILES['xlsx']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        http_response_code(422);
        exit("Falta el archivo xlsx del set de pruebas.\n");
    }
    $xlsxPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'set_' . bin2hex(random_bytes(6)) . '.xlsx';
    if (!move_uploaded_file($_FILES['xlsx']['tmp_name'], $xlsxPath)) {
        http_response_code(500);
        exit("No se pudo guardar el xlsx subido.\n");
    }
    $tmpFiles[] = $xlsxPath;
    $argv[] = $xlsxPath; // argumento posicional
}

$argv[] = '--api=' . $apiBase;
$argv[] = '--api-key=' . $apiKey;

if ($esIntegracion) {
    $argv[] = '--api-secret=' . $apiSecret;
    echo "== modo INTEGRACION: /api/integracion/* ==" . PHP_EOL;

    // Fase 4 no lee xlsx: el e-NCF lo asigna el runner y el emisor va en cada
    // payload (sin DB no hay ncf_sequences ni emisor_config que consultar).
    if ($fase === '4') {
        $faltan = array_values(array_filter(
            ['encf_start', 'emisor_rnc', 'emisor_razon_social', 'emisor_direccion'],
            fn($k) => (string) ($_REQUEST[$k] ?? '') === ''
        ));
        if ($faltan !== []) {
            http_response_code(422);
            exit('Faltan para fase 4 en integracion: ' . implode(', ', $faltan) . PHP_EOL);
        }
        foreach ([
            'encf-start' => 'encf_start',
            'emisor-rnc' => 'emisor_rnc',
            'emisor-razon-social' => 'emisor_razon_social',
            'emisor-direccion' => 'emisor_direccion',
            'emisor-nombre-comercial' => 'emisor_nombre_comercial',
            'emisor-municipio' => 'emisor_municipio',
            'emisor-provincia' => 'emisor_provincia',
            'emisor-telefono' => 'emisor_telefono',
            'emisor-correo' => 'emisor_correo',
        ] as $flag => $key) {
            if ((string) ($_REQUEST[$key] ?? '') !== '') {
                $argv[] = '--' . $flag . '=' . $_REQUEST[$key];
            }
        }
    }
} else {
    // Toda corrida del wizard es una corrida de CERTIFICACION: forzamos certecf
    // salvo override explicito. Evita que un tenant ya en 'ecf' emita el set de
    // pruebas contra produccion. Fase 3 (aprobaciones) no lo usa todavia.
    // En integracion NO aplica: el ambiente sale de tenants.ambiente y los
    // runners rechazan --ambiente para no dar falsa sensacion de seguridad.
    if (in_array($fase, ['2', '4'], true)) {
        $ambiente = (string) ($_REQUEST['ambiente'] ?? 'certecf');
        $argv[] = '--ambiente=' . $ambiente;
        echo "== ambiente: {$ambiente} ==" . PHP_EOL;
    }
    // client_id/user_id vacios hacen que el runner caiga a SUS defaults, que son los
    // de Gratex (3511/2 en fase 4, 1 en fase 2). En otro tenant eso da 'Cliente no
    // encontrado' en cada caso. Mejor fallar aqui con un mensaje claro.
    if (in_array($fase, ['2', '4'], true) && (string) ($_REQUEST['client_id'] ?? '') === '') {
        http_response_code(422);
        exit("client_id requerido: es el id del comprador de prueba (RNC 131880681) en la DB de ESTE tenant." . PHP_EOL);
    }

    foreach (['client-id' => 'client_id', 'user-id' => 'user_id'] as $flag => $key) {
        if (isset($_REQUEST[$key]) && $_REQUEST[$key] !== '') {
            $argv[] = '--' . $flag . '=' . $_REQUEST[$key];
        }
    }
}
// Passthrough de flags opcionales (filtros / control de notas fase 4).
foreach (['filter', 'case', 'exclude', 'counts', 'nota-wait-accepted', 'nota-poll', 'refs-e31', 'refs-date'] as $k) {
    if (isset($_REQUEST[$k]) && $_REQUEST[$k] !== '') {
        $argv[] = '--' . $k . '=' . $_REQUEST[$k];
    }
}
if (isset($_REQUEST['dry-run'])) {
    $argv[] = '--dry-run';
}
$argv[] = '--output=' . $tmpOut;

$runner = match ($fase) {
    '2' => __DIR__ . '/../tools/send_fase2.php',
    '3' => __DIR__ . '/../tools/send_fase3.php',
    '4' => __DIR__ . '/../tools/send_fase4_simulation.php',
};

echo "== cert_run fase {$fase} ==\n";
require_once $runner;

try {
    $code = main($argv);
    echo "\n== runner termino (code {$code}) ==\n";
} catch (Throwable $e) {
    echo "\n!! Excepcion: " . $e->getMessage() . "\n";
} finally {
    foreach ($tmpFiles as $f) {
        if (is_file($f)) { @unlink($f); }
    }
}
