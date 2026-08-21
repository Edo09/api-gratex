<?php
// TEMPORARY DEBUG FILE — DELETE AFTER USE
//
// Expone lineas del error_log y rutas internas del server: NUNCA sin auth.
// Token-gated con READLOG_TOKEN del .env (como cert_run.php / los demas tools).
require_once __DIR__ . '/../src/Database.php';
Database::loadEnv();

$expectedToken = (string) (getenv('READLOG_TOKEN') ?: ($_ENV['READLOG_TOKEN'] ?? ''));
if ($expectedToken === '') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("READLOG_TOKEN no configurado en el .env del server.\n");
}
if (!hash_equals($expectedToken, (string) ($_GET['token'] ?? ''))) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Token invalido. Use ?token=...\n");
}

$base = dirname(__DIR__);

// El home del cPanel se deriva de la ruta real del script: hardcodearlo rompe
// el lector tras cada migracion de cuenta/servidor (p. ej. mtldtmte -> smhynzte).
$home = null;
if (preg_match('#^(/home\d*/[^/]+)/#', $base . '/', $m)) {
    $home = $m[1];
}

$candidates = array_values(array_unique(array_filter([
    $base . '/error_log',
    $base . '/logs/error.log',
    dirname($base) . '/error_log',
    dirname($base) . '/logs/error.log',
    $home ? $home . '/logs/error.log' : null,
    $home ? $home . '/public_html/error_log' : null,
    ini_get('error_log') ?: null,
    '/tmp/php_errors.log',
])));

// ?lines=N  cuantas lineas mostrar (default 40)
// ?grep=xxx filtrar por substring; sin el, muestra TODO el final del log.
//           Antes filtraba fijo por Router/ecf*, lo que escondia fatales de PHP
//           (memoria agotada, etc.) que son justo lo que se busca al depurar.
$lines = max(1, min(500, (int) ($_GET['lines'] ?? 40)));
$grep  = trim((string) ($_GET['grep'] ?? ''));

echo "<pre style='font-size:11px'>";
echo "Script base: " . htmlspecialchars($base) . "\n";
echo "Home detectado: " . htmlspecialchars($home ?? '(no detectado)') . "\n";
echo "error_log de php.ini: " . htmlspecialchars(ini_get('error_log') ?: '(no configurado)') . "\n";
echo "memory_limit: " . htmlspecialchars(ini_get('memory_limit')) . "\n\n";

foreach ($candidates as $path) {
    $exists = file_exists($path);
    $readable = $exists && is_readable($path);
    $size = $readable ? ' (' . number_format(filesize($path) / 1024, 1) . ' KB)' : '';
    echo ($readable ? "✓ READABLE" : ($exists ? "✗ EXISTS NOT READABLE" : "  not found"))
        . ": " . htmlspecialchars($path) . $size . "\n";
}

echo "\n--- Ultimas {$lines} lineas" . ($grep !== '' ? " que contienen '" . htmlspecialchars($grep) . "'" : '') . " ---\n";
foreach ($candidates as $path) {
    if (!is_readable($path)) {
        continue;
    }
    $all = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    if ($grep !== '') {
        $all = array_values(array_filter($all, fn($l) => stripos($l, $grep) !== false));
    }
    echo "(desde " . htmlspecialchars($path) . ")\n\n";
    foreach (array_slice($all, -$lines) as $l) {
        echo htmlspecialchars($l) . "\n";
    }
    break;
}
echo "</pre>";
