<?php
require __DIR__ . '/Fase2XlsxReader.php';
$file = $argv[1] ?? '';
$r = new Fase2XlsxReader($file);

// Detectar hojas disponibles via reflection (la clase tiene $sheetNameToFile privado)
$ref = new ReflectionClass($r);
$prop = $ref->getProperty('sheetNameToFile');
$prop->setAccessible(true);
$sheets = $prop->getValue($r);
echo 'Hojas disponibles: ' . implode(', ', array_keys($sheets)) . PHP_EOL . PHP_EOL;

foreach (array_keys($sheets) as $sheetName) {
    $rows = $r->readSheet($sheetName);
    echo "=== $sheetName (" . count($rows) . " filas) ===" . PHP_EOL;
    foreach ($rows as $i => $row) {
        echo PHP_EOL . "  --- fila " . ($i + 2) . " ---" . PHP_EOL;
        foreach ($row as $k => $v) {
            if ($v !== '' && $v !== null) {
                echo "    $k = $v" . PHP_EOL;
            }
        }
    }
    echo PHP_EOL;
}
