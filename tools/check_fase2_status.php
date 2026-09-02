<?php

/**
 * Consulta el estado real en DGII de cada e-CF enviado en Fase 2.
 *
 * Lee tools/fase2_results.json (generado por send_fase2.php), toma el
 * factura_id de cada caso OK, y llama GET /api/facturas/{id}/estado para
 * que el server consulte ConsultaResultado de DGII y devuelva el detalle.
 *
 * Uso:
 *   php tools/check_fase2_status.php \
 *     --api=https://gratex.net/api \
 *     --api-key=7a775f6fb0d5ccab15cf149d2c60f15c \
 *     [--input=tools/fase2_results.json] \
 *     [--output=tools/fase2_estados.json]
 */

const DEFAULT_API = 'https://gratex.net/api';
const DEFAULT_INPUT = __DIR__ . '/fase2_results.json';
const DEFAULT_OUTPUT = __DIR__ . '/fase2_estados.json';

function main(array $argv): int
{
    if (!function_exists('curl_init')) {
        fwrite(STDERR, "ERROR: Falta extension curl.\n");
        return 3;
    }
    $opts = parseArgs($argv);
    $apiBase = rtrim($opts['api'] ?? DEFAULT_API, '/');
    $apiKey = $opts['api-key'] ?? '';
    $input = $opts['input'] ?? DEFAULT_INPUT;
    $output = $opts['output'] ?? DEFAULT_OUTPUT;

    if ($apiKey === '') {
        fwrite(STDERR, "ERROR: --api-key es requerido.\n");
        return 2;
    }
    if (!is_file($input)) {
        fwrite(STDERR, "ERROR: archivo de resultados no encontrado: $input\n");
        return 2;
    }

    $results = json_decode(file_get_contents($input), true);
    if (!is_array($results)) {
        fwrite(STDERR, "ERROR: input no es JSON valido.\n");
        return 2;
    }

    $estados = [];
    $okCount = 0;
    foreach ($results as $i => $r) {
        $facturaId = $r['factura_id'] ?? null;
        if (!$facturaId) {
            continue;
        }
        $caso = $r['caso'] ?? '?';
        $eNcf = $r['e_ncf'] ?? '?';

        fwrite(STDOUT, sprintf("[%d] %s (%s) ... ", $i + 1, $caso, $eNcf));
        $resp = consultarEstado($apiBase, $apiKey, $facturaId);
        $estado = '?';
        $detalle = '';
        if ($resp['http_status'] === 200 && ($resp['body']['status'] ?? false)) {
            $data = $resp['body']['data'] ?? [];
            $estado = $data['estado_dgii'] ?? '?';
            $consulta = $data['consulta'] ?? [];
            if (is_array($consulta)) {
                $msgs = $consulta['mensajes'] ?? [];
                if (is_array($msgs) && count($msgs) > 0) {
                    $detalle = trim(($msgs[0]['valor'] ?? '') . ' [' . ($msgs[0]['codigo'] ?? '') . ']');
                }
            }
            if ($estado === 'ACEPTADO') {
                $okCount++;
            }
        } else {
            $detalle = $resp['body']['error'] ?? ('HTTP ' . $resp['http_status']);
        }

        fwrite(STDOUT, sprintf("estado=%s%s\n", $estado, $detalle !== '' ? ' :: ' . substr($detalle, 0, 200) : ''));
        $estados[] = [
            'caso' => $caso,
            'e_ncf' => $eNcf,
            'factura_id' => $facturaId,
            'estado_dgii' => $estado,
            'detalle' => $detalle,
            'response' => $resp['body'],
        ];
    }

    file_put_contents($output, json_encode($estados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    fwrite(STDOUT, "\n========================================\n");
    fwrite(STDOUT, sprintf("Resumen: %d/%d ACEPTADOS\n", $okCount, count($estados)));
    fwrite(STDOUT, "========================================\n");
    foreach ($estados as $e) {
        fwrite(STDOUT, sprintf("  %s | %s | %s | %s\n",
            $e['estado_dgii'] === 'ACEPTADO' ? '+' : '-',
            $e['e_ncf'],
            $e['estado_dgii'],
            substr($e['detalle'], 0, 150)
        ));
    }
    fwrite(STDOUT, "\n==> Detalle completo en: $output\n");
    return 0;
}

function parseArgs(array $argv): array
{
    $opts = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--')) {
            $eq = strpos($arg, '=');
            $opts[substr($arg, 2, $eq === false ? null : $eq - 2)] = $eq === false ? true : substr($arg, $eq + 1);
        }
    }
    return $opts;
}

function consultarEstado(string $apiBase, string $apiKey, int $facturaId): array
{
    $url = $apiBase . '/facturas/' . $facturaId . '/estado';
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
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

exit(main($argv));
