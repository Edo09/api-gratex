<?php

/**
 * Descarga los XML firmados (ECF o RFCE) de todas las facturas en fase2_results.json
 * y los guarda en tools/xmls/
 *
 * Uso:
 *   php tools/download_xmls.php --api=https://gratex.net/api --api-key=KEY
 */

$resultsFile = __DIR__ . '/fase2_results.json';
$outputDir   = __DIR__ . '/xmls';

$opts = parseArgs($argv);
$apiBase = rtrim($opts['api'] ?? 'https://gratex.net/api', '/');
$apiKey  = $opts['api-key'] ?? '';

if ($apiKey === '') {
    fwrite(STDERR, "ERROR: --api-key es requerido.\n");
    exit(2);
}

if (!file_exists($resultsFile)) {
    fwrite(STDERR, "ERROR: No se encontro {$resultsFile}. Ejecuta primero send_fase2.php.\n");
    exit(2);
}

$results = json_decode(file_get_contents($resultsFile), true);
if (!is_array($results)) {
    fwrite(STDERR, "ERROR: {$resultsFile} no es JSON valido.\n");
    exit(2);
}

if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

$ok   = 0;
$fail = 0;

foreach ($results as $entry) {
    if (!($entry['ok'] ?? false)) {
        fwrite(STDOUT, "  SKIP {$entry['e_ncf']} (no fue emitido exitosamente)\n");
        continue;
    }

    $facturaId = $entry['factura_id'] ?? null;
    $eNcf      = $entry['e_ncf'] ?? "factura_{$facturaId}";
    $estado    = $entry['estado_dgii'] ?? '';

    // Los E32 < 250k van por RFCE; los demas por ECF integro
    $isRfce = ($estado === 'RFCE_ACEPTADO');
    $type   = $isRfce ? 'rfce' : 'ecf';
    $suffix = $isRfce ? '_RFCE' : '';

    $url      = "{$apiBase}/facturas/{$facturaId}/xml" . ($isRfce ? '?type=rfce' : '');
    $filename = "{$outputDir}/{$eNcf}{$suffix}.xml";

    fwrite(STDOUT, "  Descargando {$eNcf}{$suffix}.xml ... ");

    $xml = curlGet($url, $apiKey);

    if ($xml === false) {
        fwrite(STDOUT, "FALLO (curl error)\n");
        $fail++;
        continue;
    }

    if (str_starts_with(trim($xml), '{')) {
        $json = json_decode($xml, true);
        $error = $json['error'] ?? $json['message'] ?? 'respuesta JSON inesperada';
        fwrite(STDOUT, "FALLO ({$error})\n");
        $fail++;
        continue;
    }

    file_put_contents($filename, $xml);
    fwrite(STDOUT, "OK\n");
    $ok++;
}

fwrite(STDOUT, "\n========================================\n");
fwrite(STDOUT, "Descargados: {$ok} OK, {$fail} fallaron\n");
fwrite(STDOUT, "Carpeta: {$outputDir}\n");
fwrite(STDOUT, "========================================\n");

exit($fail > 0 ? 1 : 0);

// ---------------------------------------------------------------------------

function curlGet(string $url, string $apiKey): string|false
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/xml, application/json',
            'X-API-KEY: ' . $apiKey,
        ],
    ]);
    if (defined('CURLOPT_SSL_OPTIONS') && defined('CURLSSLOPT_NATIVE_CA')) {
        curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
    }
    $cainfo = getenv('CURL_CA_BUNDLE');
    if ($cainfo && is_file($cainfo)) {
        curl_setopt($ch, CURLOPT_CAINFO, $cainfo);
    }
    $body = curl_exec($ch);
    if ($body === false) {
        fwrite(STDERR, "curl: " . curl_error($ch) . "\n");
    }
    curl_close($ch);
    return $body;
}

function parseArgs(array $argv): array
{
    $opts = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--')) {
            $eq = strpos($arg, '=');
            if ($eq !== false) {
                $opts[substr($arg, 2, $eq - 2)] = substr($arg, $eq + 1);
            } else {
                $opts[substr($arg, 2)] = true;
            }
        }
    }
    return $opts;
}
