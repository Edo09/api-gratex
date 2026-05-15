<?php
require __DIR__ . '/Fase2XlsxReader.php';
$file = $argv[1] ?? '';
if ($file === '') {
    fwrite(STDERR, "Uso: php tools/list_set.php <ruta_xlsx>\n");
    exit(2);
}
$r = new Fase2XlsxReader($file);
$rows = $r->readSheet('ECF');
echo 'ECF rows: ' . count($rows) . PHP_EOL . PHP_EOL;
echo "## ECF (encf | tipo | fecha_emision | fecha_venc | total):\n";
foreach ($rows as $row) {
    echo '  ' . ($row['ENCF'] ?? '?')
        . ' | E' . ($row['TipoeCF'] ?? '?')
        . ' | ' . ($row['FechaEmision'] ?? '?')
        . ' | ' . ($row['FechaVencimientoSecuencia'] ?? '?')
        . ' | ' . ($row['MontoTotal'] ?? '?')
        . PHP_EOL;
}
echo PHP_EOL;

$rfce = $r->readSheet('RFCE');
echo '## RFCE rows: ' . count($rfce) . PHP_EOL;
foreach ($rfce as $row) {
    echo '  ' . ($row['CasoPrueba'] ?? '?')
        . ' | ' . ($row['ENCF'] ?? '?')
        . ' | ' . ($row['MontoTotal'] ?? '?')
        . PHP_EOL;
}
