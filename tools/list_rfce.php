<?php
require __DIR__ . '/Fase2XlsxReader.php';
$file = $argv[1] ?? '';
$r = new Fase2XlsxReader($file);
$rfce = $r->readSheet('RFCE');
echo 'RFCE rows: ' . count($rfce) . PHP_EOL;
foreach ($rfce as $row) {
    echo PHP_EOL . '--- ' . ($row['CasoPrueba'] ?? '?') . ' ---' . PHP_EOL;
    foreach ($row as $k => $v) {
        if ($v !== '' && $v !== null) {
            echo "  $k = $v" . PHP_EOL;
        }
    }
}
