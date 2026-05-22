<?php

/**
 * Runner de Fase 3 (DGII e-CF certification) - Aprobaciones Comerciales.
 *
 * Lee la hoja "ACEECF_Generadas" del xlsx del set de pruebas y envia cada
 * Aprobacion Comercial al endpoint POST /api/aprobaciones-comerciales de
 * gratex.net. El sistema construye el ACECF XML, lo firma con el cert del
 * comprador (nosotros) y lo POSTea a la URL de DGII:
 *
 *   https://ecf.dgii.gov.do/CerteCF/AprobacionComercial/api/AprobacionComercial
 *
 * Uso:
 *   php tools/send_fase3.php samples/131256432-22052026125156.xlsx \
 *       --api=https://gratex.net/api \
 *       --api-key=7a775f6fb0d5ccab15cf149d2c60f15c \
 *       [--output=tools/fase3_results.json] \
 *       [--case=E310000000001] \
 *       [--dry-run]
 */

require_once __DIR__ . '/Fase2XlsxReader.php';

const DEFAULT_API = 'https://gratex.net/api';
const DEFAULT_OUTPUT = __DIR__ . '/fase3_results.json';
const DEFAULT_TIMEOUT_SECONDS = 60;

function main(array $argv): int
{
    if (!class_exists('ZipArchive')) {
        fwrite(STDERR, "ERROR: falta extension 'zip'.\n");
        return 3;
    }
    if (!function_exists('curl_init')) {
        fwrite(STDERR, "ERROR: falta extension 'curl'.\n");
        return 3;
    }

    $opts = parseArgs($argv);
    if (!isset($opts['xlsx'])) {
        fwrite(STDERR, "Uso: php tools/send_fase3.php <ruta_xlsx> [--api=URL] [--api-key=KEY] [--case=...] [--output=...] [--dry-run]\n");
        return 2;
    }
    $apiBase = rtrim($opts['api'] ?? DEFAULT_API, '/');
    $apiKey = $opts['api-key'] ?? '';
    $output = $opts['output'] ?? DEFAULT_OUTPUT;
    $caseFilter = $opts['case'] ?? '';
    $dryRun = isset($opts['dry-run']);

    if (!$dryRun && $apiKey === '') {
        fwrite(STDERR, "ERROR: --api-key requerido (o usa --dry-run).\n");
        return 2;
    }

    fwrite(STDOUT, "==> Leyendo xlsx: {$opts['xlsx']}\n");
    $reader = new Fase2XlsxReader($opts['xlsx']);
    $sheetName = detectACECFSheet($reader);
    $rows = $reader->readSheet($sheetName);
    fwrite(STDOUT, "==> " . count($rows) . " ACECF en hoja $sheetName\n");

    if ($caseFilter !== '') {
        $rows = array_values(array_filter($rows, fn($r) => ($r['eNCF'] ?? '') === $caseFilter));
        fwrite(STDOUT, "==> Filtro caso: $caseFilter -> " . count($rows) . " casos\n");
    }

    $results = [];
    foreach ($rows as $i => $row) {
        $caso = $row['CasoPrueba'] ?? ('row' . ($i + 2));
        $eNcf = (string) ($row['eNCF'] ?? '');
        fwrite(STDOUT, sprintf("\n[%d/%d] %s\n", $i + 1, count($rows), $caso !== '' ? $caso : $eNcf));

        try {
            $payload = mapRowToPayload($row);
        } catch (Throwable $e) {
            fwrite(STDOUT, "    ! Error mapeando: " . $e->getMessage() . "\n");
            $results[] = ['e_ncf' => $eNcf, 'ok' => false, 'error' => $e->getMessage()];
            continue;
        }

        if ($dryRun) {
            fwrite(STDOUT, "    DRY-RUN payload:\n" . json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
            $results[] = ['e_ncf' => $eNcf, 'ok' => true, 'dry_run' => true, 'payload' => $payload];
            continue;
        }

        $response = postAprobacion($apiBase, $apiKey, $payload);
        $entry = [
            'e_ncf' => $eNcf,
            'rnc_emisor' => $payload['rnc_emisor'] ?? null,
            'http_status' => $response['http_status'],
            'ok' => $response['http_status'] >= 200 && $response['http_status'] < 300 && (($response['body']['status'] ?? false) === true),
            'response' => $response['body'],
        ];
        if ($entry['ok']) {
            $data = $response['body']['data'] ?? [];
            $entry['track_id'] = $data['track_id'] ?? null;
            $entry['estado_dgii'] = $data['estado_dgii'] ?? null;
            fwrite(STDOUT, sprintf("    OK estado=%s track=%s\n", $entry['estado_dgii'] ?? '?', $entry['track_id'] ?? '-'));
        } else {
            $entry['error'] = $response['body']['error'] ?? ('HTTP ' . $response['http_status']);
            fwrite(STDOUT, "    FAIL " . $entry['error'] . "\n");
        }
        $results[] = $entry;
    }

    file_put_contents($output, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    printSummary($results);
    fwrite(STDOUT, "\n==> Reporte guardado en: $output\n");
    return 0;
}

function parseArgs(array $argv): array
{
    $opts = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--')) {
            $eq = strpos($arg, '=');
            $opts[substr($arg, 2, $eq === false ? null : $eq - 2)] = $eq === false ? true : substr($arg, $eq + 1);
        } elseif (!isset($opts['xlsx'])) {
            $opts['xlsx'] = $arg;
        }
    }
    return $opts;
}

function detectACECFSheet(Fase2XlsxReader $reader): string
{
    $ref = new ReflectionClass($reader);
    $prop = $ref->getProperty('sheetNameToFile');
    $prop->setAccessible(true);
    $sheets = array_keys($prop->getValue($reader));
    foreach ($sheets as $name) {
        if (stripos($name, 'ACECF') !== false || stripos($name, 'ACEECF') !== false || stripos($name, 'Aprobacion') !== false) {
            return $name;
        }
    }
    return $sheets[0] ?? 'Sheet1';
}

function mapRowToPayload(array $row): array
{
    return [
        'rnc_emisor' => (string) ($row['RNCEmisor'] ?? ''),
        'e_ncf' => (string) ($row['eNCF'] ?? $row['ENCF'] ?? ''),
        'fecha_emision' => (string) ($row['FechaEmision'] ?? ''),
        'monto_total' => (float) ($row['MontoTotal'] ?? 0),
        'rnc_comprador' => (string) ($row['RNCComprador'] ?? ''),
        'estado' => (string) ($row['Estado'] ?? '1'),
        'detalle_motivo' => $row['DetalleMotivoRechazo'] ?? null,
        'fecha_hora' => $row['FechaHoraAprobacionComercial'] ?? null,
    ];
}

function postAprobacion(string $apiBase, string $apiKey, array $payload): array
{
    $url = $apiBase . '/aprobaciones-comerciales';
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    $opts = [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => DEFAULT_TIMEOUT_SECONDS,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-API-KEY: ' . $apiKey,
        ],
    ];
    if (defined('CURLOPT_SSL_OPTIONS') && defined('CURLSSLOPT_NATIVE_CA')) {
        $opts[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    if ($raw === false) {
        return ['http_status' => 0, 'body' => ['status' => false, 'error' => 'curl: ' . curl_error($ch)]];
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $decoded = json_decode($raw, true);
    return [
        'http_status' => $status,
        'body' => is_array($decoded) ? $decoded : ['status' => false, 'error' => 'no-json: ' . substr($raw, 0, 300)],
    ];
}

function printSummary(array $results): void
{
    $ok = count(array_filter($results, fn($r) => $r['ok'] ?? false));
    fwrite(STDOUT, "\n========================================\n");
    fwrite(STDOUT, sprintf("Resumen: %d/%d ENVIADAS\n", $ok, count($results)));
    fwrite(STDOUT, "========================================\n");
    foreach ($results as $r) {
        $marker = ($r['ok'] ?? false) ? '+' : '-';
        $detail = ($r['ok'] ?? false)
            ? ($r['estado_dgii'] ?? '') . ' track=' . ($r['track_id'] ?? '-')
            : ($r['error'] ?? '?');
        fwrite(STDOUT, sprintf("  %s %s | %s\n", $marker, $r['e_ncf'] ?? '?', $detail));
    }
}

exit(main($argv));
