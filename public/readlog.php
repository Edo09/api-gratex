<?php
// TEMPORARY DEBUG FILE — DELETE AFTER USE
$base = dirname(__DIR__);
$candidates = [
    $base . '/error_log',
    $base . '/logs/error.log',
    dirname($base) . '/error_log',
    dirname($base) . '/logs/error.log',
    '/home1/mtldtmte/logs/error.log',
    '/home1/mtldtmte/public_html/error_log',
    '/tmp/php_errors.log',
];

echo "<pre style='font-size:11px'>";
echo "Script base: " . htmlspecialchars($base) . "\n\n";

foreach ($candidates as $path) {
    $exists = file_exists($path);
    $readable = $exists && is_readable($path);
    echo ($readable ? "✓ READABLE" : ($exists ? "✗ EXISTS NOT READABLE" : "  not found")) . ": " . htmlspecialchars($path) . "\n";
}

echo "\n--- Searching for error_log files ---\n";
$found = glob($base . '/../error_log') ?: [];
foreach ($found as $f) { echo "found: " . htmlspecialchars($f) . "\n"; }

echo "\n--- Last 30 relevant log lines ---\n";
foreach ($candidates as $path) {
    if (is_readable($path)) {
        $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
        $filtered = array_filter($lines, fn($l) =>
            str_contains($l, 'Router') ||
            str_contains($l, 'ecfRecepcion') ||
            str_contains($l, 'ecfAutenticacion') ||
            str_contains($l, '[ecf')
        );
        foreach (array_slice(array_values($filtered), -30) as $l) {
            echo htmlspecialchars($l) . "\n";
        }
        break;
    }
}
echo "</pre>";
