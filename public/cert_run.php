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

$apiBase = (string) ($_REQUEST['api'] ?? 'https://gratex.net/api');
$apiKey  = (string) ($_REQUEST['api_key'] ?? '');
if ($apiKey === '') {
    http_response_code(422);
    exit("api_key (token del tenant) requerido.\n");
}

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
foreach (['client-id' => 'client_id', 'user-id' => 'user_id'] as $flag => $key) {
    if (isset($_REQUEST[$key]) && $_REQUEST[$key] !== '') {
        $argv[] = '--' . $flag . '=' . $_REQUEST[$key];
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
