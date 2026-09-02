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
 *
 * Tenants tipo INTEGRACION (--api-secret): no hay factura persistida que
 * consultar, asi que va por GET /api/integracion/estado con e_ncf + track_id
 * (los del reporte de send_fase2.php). Para los RFCE (E32 <250k) no hay
 * track_id: se consulta por codigo de seguridad, tambien tomado del reporte.
 *   php tools/check_fase2_status.php --api=https://gratex.net/api \
 *     --api-key=<key> --api-secret=<secret> --input=tools/fase2_results.json
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
    $apiSecret = (string) ($opts['api-secret'] ?? '');
    $integracion = $apiSecret !== '';
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
        $caso = $r['caso'] ?? '?';
        $eNcf = (string) ($r['e_ncf'] ?? '');
        $facturaId = $r['factura_id'] ?? null;
        // App: se consulta por factura_id. Integracion: no hay factura, se
        // consulta por e-NCF (+ track_id, o codigo de seguridad si es RFCE).
        if ($integracion ? ($eNcf === '' || ($r['ok'] ?? false) !== true) : !$facturaId) {
            continue;
        }

        fwrite(STDOUT, sprintf("[%d] %s (%s) ... ", $i + 1, $caso, $eNcf !== '' ? $eNcf : '?'));
        $trackId = null;
        if ($integracion) {
            $trackId = $r['track_id'] ?? ($r['response']['data']['track_id'] ?? null);
            $resp = consultarEstadoIntegracion(
                $apiBase,
                $apiKey,
                $apiSecret,
                $eNcf,
                $trackId,
                $r['response']['data']['codigo_seguridad'] ?? null
            );
        } else {
            $resp = consultarEstado($apiBase, $apiKey, (int) $facturaId);
        }
        $estado = '?';
        $detalle = '';
        if ($resp['http_status'] === 200 && ($resp['body']['status'] ?? false)) {
            // En integracion el estado va plano en la respuesta; en app, bajo data.
            $data = $integracion ? $resp['body'] : ($resp['body']['data'] ?? []);
            $estado = ($integracion ? $data['estado'] : ($data['estado_dgii'] ?? null)) ?? '?';
            $consulta = $data['consulta'] ?? [];
            if (is_array($consulta)) {
                $msgs = $consulta['mensajes'] ?? [];
                if (is_array($msgs) && count($msgs) > 0) {
                    $detalle = trim(($msgs[0]['valor'] ?? '') . ' [' . ($msgs[0]['codigo'] ?? '') . ']');
                }
            }
            // RFCE (E32 <250k) responde 'RFCE_ACEPTADO': tambien es un caso OK.
            if ($estado === 'ACEPTADO' || $estado === 'RFCE_ACEPTADO') {
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
            'track_id' => $trackId,
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
            in_array($e['estado_dgii'], ['ACEPTADO', 'RFCE_ACEPTADO'], true) ? '+' : '-',
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

/**
 * Igual que consultarEstado(), pero contra GET /api/integracion/estado: sin
 * factura en DB, DGII se consulta por e-NCF + track_id. Los RFCE (E32 <250k) no
 * generan track_id, asi que van por codigo de seguridad.
 */
function consultarEstadoIntegracion(
    string $apiBase,
    string $apiKey,
    string $apiSecret,
    string $eNcf,
    ?string $trackId,
    ?string $codigoSeguridad
): array {
    $query = ['e_ncf' => $eNcf];
    if ($trackId !== null && $trackId !== '') {
        $query['track_id'] = $trackId;
    } elseif ($codigoSeguridad !== null && $codigoSeguridad !== '') {
        $query['codigo_seguridad'] = $codigoSeguridad;
    }
    $url = $apiBase . '/integracion/estado?' . http_build_query($query);

    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'X-API-KEY: ' . $apiKey,
            'X-API-SECRET: ' . $apiSecret,
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
