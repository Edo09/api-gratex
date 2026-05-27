<?php

/**
 * Runner Fase 4 — simulacion datos reales.
 *
 * Genera 25 e-CF con data simulada (sin xlsx). Distribucion:
 *   4× E31, 2× E32 >=250k, 1× E33, 2× E34, 2× E41, 2× E43,
 *   2× E44, 2× E45, 2× E46, 2× E47, 4× E32 <250k (RFCE auto).
 *
 * E-NCFs autodispensados por API (no override).
 * E33/E34 referencian e-NCFs de E31 emitidas antes en este mismo run.
 *
 * Uso:
 *   php tools/send_fase4_simulation.php \
 *       --api=https://gratex.net/api \
 *       --api-key=7a775f6fb0d5ccab15cf149d2c60f15c \
 *       --client-id=3511 --user-id=2 \
 *       [--dry-run] [--output=tools/fase4_results.json]
 */

const DEFAULT_API = 'https://gratex.net/api';
const DEFAULT_CLIENT_ID = 3511;
const DEFAULT_USER_ID = 2;
const DEFAULT_OUTPUT = __DIR__ . '/fase4_results.json';
const DEFAULT_TIMEOUT_SECONDS = 60;

function main(array $argv): int
{
    if (!function_exists('curl_init')) {
        fwrite(STDERR, "ERROR: extension curl falta.\n");
        return 3;
    }
    $opts = parseArgs($argv);
    $apiBase = rtrim($opts['api'] ?? DEFAULT_API, '/');
    $apiKey = $opts['api-key'] ?? '';
    $clientId = (int) ($opts['client-id'] ?? DEFAULT_CLIENT_ID);
    $userId = (int) ($opts['user-id'] ?? DEFAULT_USER_ID);
    $output = $opts['output'] ?? DEFAULT_OUTPUT;
    $dryRun = isset($opts['dry-run']);

    if (!$dryRun && $apiKey === '') {
        fwrite(STDERR, "ERROR: --api-key requerido.\n");
        return 2;
    }

    $countsOverride = [];
    if (!empty($opts['counts'])) {
        foreach (explode(',', $opts['counts']) as $pair) {
            [$k, $v] = array_pad(explode(':', trim($pair)), 2, '0');
            $countsOverride[$k] = (int) $v;
        }
    }
    $notaDelay = isset($opts['nota-delay']) ? (int) $opts['nota-delay'] : 0;

    $cases = buildPlan($countsOverride);
    fwrite(STDOUT, "==> " . count($cases) . " casos planificados\n");

    $results = [];
    $eNcfsByType = [];
    $notaDelayApplied = false;
    foreach ($cases as $i => $case) {
        $label = "[" . ($i + 1) . "/" . count($cases) . "] {$case['etiqueta']} (E{$case['tipo_ecf']})";
        fwrite(STDOUT, "\n$label\n");

        // E33/E34 need an existing e-NCF reference
        if (in_array($case['tipo_ecf'], ['33', '34'], true) && !$notaDelayApplied) {
            $notaDelayApplied = true;
            if ($notaDelay > 0 && !$dryRun) {
                fwrite(STDOUT, "\n==> Esperando {$notaDelay}s para que DGII indexe los E31 antes de notas...\n");
                sleep($notaDelay);
            }
        }
        if (in_array($case['tipo_ecf'], ['33', '34'], true)) {
            $candidate = null;
            if (!empty($eNcfsByType['31'])) {
                $candidate = array_shift($eNcfsByType['31']);
            } elseif (!empty($eNcfsByType['31_fake'])) {
                // dry-run mode: no real e-NCF available, generate fake
                $candidate = array_shift($eNcfsByType['31_fake']);
            }
            if ($candidate === null) {
                fwrite(STDOUT, "    SKIP: no hay e-NCF previo E31 para referenciar\n");
                $results[] = ['etiqueta' => $case['etiqueta'], 'tipo_ecf' => $case['tipo_ecf'], 'ok' => false, 'error' => 'sin e-NCF previo para referenciar'];
                continue;
            }
            $case['payload']['informacion_referencia'] = [
                'ncf_modificado' => $candidate['e_ncf'],
                'rnc_otro_contribuyente' => $candidate['rnc_comprador'] ?? null,
                'fecha_ncf_modificado' => $candidate['fecha'] ?? date('d-m-Y'),
                'codigo_modificacion' => $case['tipo_ecf'] === '34' ? '1' : '2',
            ];
        }

        $payload = array_merge(
            [
                'client_id' => $clientId,
                'user_id' => $userId,
            ],
            $case['payload']
        );
        $payload = withDefaultIndicadorMontoGravado($payload);
        $payload = withE41Retencion($payload);
        $payload = withE47Retencion($payload);
        $payload = withDefaultItbisRates($payload);

        if ($dryRun) {
            fwrite(STDOUT, "    DRY-RUN " . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n");
            $results[] = ['etiqueta' => $case['etiqueta'], 'tipo_ecf' => $case['tipo_ecf'], 'dry_run' => true, 'payload' => $payload, 'ok' => true];
            // Stub e-NCF para que E33/E34 puedan referenciar en dry-run
            if ($case['tipo_ecf'] === '31') {
                $eNcfsByType['31'][] = [
                    'e_ncf' => 'E31000000000' . count($eNcfsByType['31'] ?? []),
                    'fecha' => $payload['fecha_emision'] ?? date('d-m-Y'),
                    'rnc_comprador' => $payload['comprador']['rnc'] ?? null,
                ];
            }
            continue;
        }

        $resp = postFactura($apiBase, $apiKey, $payload);
        $entry = [
            'etiqueta' => $case['etiqueta'],
            'tipo_ecf' => $case['tipo_ecf'],
            'http_status' => $resp['http_status'],
            'ok' => $resp['http_status'] >= 200 && $resp['http_status'] < 300 && (($resp['body']['status'] ?? false) === true),
            'response' => $resp['body'],
        ];
        if ($entry['ok']) {
            $data = $resp['body']['data'] ?? [];
            $entry['factura_id'] = $data['factura_id'] ?? null;
            $entry['e_ncf'] = $data['e_ncf'] ?? null;
            $entry['track_id'] = $data['track_id'] ?? null;
            $entry['rfce_track_id'] = $data['rfce_track_id'] ?? null;
            $entry['estado_dgii'] = $data['estado_dgii'] ?? $data['estado'] ?? null;
            $entry['flujo'] = $data['flujo'] ?? null;

            if ($entry['e_ncf']) {
                $eNcfsByType[$case['tipo_ecf']][] = [
                    'e_ncf' => $entry['e_ncf'],
                    'fecha' => $payload['fecha_emision'] ?? date('d-m-Y'),
                    'rnc_comprador' => $payload['comprador']['rnc'] ?? null,
                ];
            }
            fwrite(STDOUT, sprintf("    OK e_ncf=%s estado=%s track=%s\n",
                $entry['e_ncf'] ?? '?',
                $entry['estado_dgii'] ?? '?',
                $entry['track_id'] ?? '-'
            ));
        } else {
            $entry['error'] = $resp['body']['error'] ?? ('HTTP ' . $resp['http_status']);
            fwrite(STDOUT, "    FAIL " . substr($entry['error'], 0, 200) . "\n");
        }
        $results[] = $entry;
    }

    file_put_contents($output, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    printSummary($results);
    fwrite(STDOUT, "\n==> Reporte en: $output\n");
    return 0;
}

function buildPlan(array $countsOverride = []): array
{
    $today = date('d-m-Y');
    $cases = [];
    $counts = array_merge([
        'E31' => 4, 'E32_gte_250k' => 2, 'E32_lt_250k' => 4,
        'E33' => 1, 'E34' => 2, 'E41' => 2, 'E43' => 2,
        'E44' => 2, 'E45' => 2, 'E46' => 2, 'E47' => 2,
    ], $countsOverride);

    // === PRIMERO: E31, E32>=250k, E41, E43, E44, E45, E46, E47 ===

    for ($i = 1; $i <= $counts['E31']; $i++) {
        $cases[] = [
            'etiqueta' => "E31 #$i",
            'tipo_ecf' => '31',
            'payload' => [
                'tipo_ecf' => '31',
                'fecha_emision' => $today,
                'tipo_ingresos' => '01',
                'tipo_pago' => 1,
                'comprador' => ['rnc' => '131880681', 'nombre' => 'CLIENTE COMPROBANTE TEST SRL'],
                'items' => itemsGravados18(rand(1, 4), 1000, 5000),
            ],
        ];
    }

    for ($i = 1; $i <= $counts['E32_gte_250k']; $i++) {
        $cases[] = [
            'etiqueta' => "E32 >=250k #$i",
            'tipo_ecf' => '32',
            'payload' => [
                'tipo_ecf' => '32',
                'fecha_emision' => $today,
                'tipo_ingresos' => '01',
                'tipo_pago' => 1,
                'items' => itemsGravados18Fijo(2, 3, 80000, 150000),
            ],
        ];
    }

    for ($i = 1; $i <= $counts['E41']; $i++) {
        $cases[] = [
            'etiqueta' => "E41 #$i",
            'tipo_ecf' => '41',
            'payload' => [
                'tipo_ecf' => '41',
                'fecha_emision' => $today,
                'tipo_pago' => 1,
                'items' => itemsGravados18(rand(1, 3), 500, 5000),
            ],
        ];
    }

    for ($i = 1; $i <= $counts['E43']; $i++) {
        $cases[] = [
            'etiqueta' => "E43 #$i",
            'tipo_ecf' => '43',
            'payload' => [
                'tipo_ecf' => '43',
                'fecha_emision' => $today,
                'tipo_pago' => 1,
                'items' => itemsExentos(rand(1, 2), 100, 500),
            ],
        ];
    }

    for ($i = 1; $i <= $counts['E44']; $i++) {
        $cases[] = [
            'etiqueta' => "E44 #$i",
            'tipo_ecf' => '44',
            'payload' => [
                'tipo_ecf' => '44',
                'fecha_emision' => $today,
                'tipo_ingresos' => '01',
                'tipo_pago' => 1,
                'items' => itemsExentos(rand(1, 3), 1000, 10000),
            ],
        ];
    }

    for ($i = 1; $i <= $counts['E45']; $i++) {
        $cases[] = [
            'etiqueta' => "E45 #$i",
            'tipo_ecf' => '45',
            'payload' => [
                'tipo_ecf' => '45',
                'fecha_emision' => $today,
                'tipo_ingresos' => '01',
                'tipo_pago' => 1,
                'items' => itemsGravados18(rand(1, 3), 1000, 8000),
            ],
        ];
    }

    for ($i = 1; $i <= $counts['E46']; $i++) {
        $cases[] = [
            'etiqueta' => "E46 #$i",
            'tipo_ecf' => '46',
            'payload' => [
                'tipo_ecf' => '46',
                'fecha_emision' => $today,
                'tipo_ingresos' => '01',
                'tipo_pago' => 1,
                'items' => itemsCero(rand(1, 3), 1000, 15000),
            ],
        ];
    }

    for ($i = 1; $i <= $counts['E47']; $i++) {
        $cases[] = [
            'etiqueta' => "E47 #$i",
            'tipo_ecf' => '47',
            'payload' => [
                'tipo_ecf' => '47',
                'fecha_emision' => $today,
                'tipo_pago' => 1,
                'items' => itemsExentos(rand(1, 2), 5000, 20000, 2),
            ],
        ];
    }

    // === SEGUNDO: E33 (Nota Debito) + E34 (Nota Credito) — referencian E31 ===

    for ($i = 1; $i <= $counts['E33']; $i++) {
        $cases[] = [
            'etiqueta' => "E33 #$i",
            'tipo_ecf' => '33',
            'payload' => [
                'tipo_ecf' => '33',
                'fecha_emision' => $today,
                'tipo_ingresos' => '01',
                'tipo_pago' => 1,
                'items' => itemsGravados18(1, 500, 2000),
            ],
        ];
    }

    for ($i = 1; $i <= $counts['E34']; $i++) {
        $cases[] = [
            'etiqueta' => "E34 #$i",
            'tipo_ecf' => '34',
            'payload' => [
                'tipo_ecf' => '34',
                'fecha_emision' => $today,
                'tipo_ingresos' => '01',
                'tipo_pago' => 1,
                'indicador_nota_credito' => '0',
                'items' => itemsGravados18Fijo(1, 1, 50, 100),
            ],
        ];
    }

    // === TERCERO: E32 <250k RFCE ===

    for ($i = 1; $i <= $counts['E32_lt_250k']; $i++) {
        $cases[] = [
            'etiqueta' => "E32 <250k RFCE #$i",
            'tipo_ecf' => '32',
            'payload' => [
                'tipo_ecf' => '32',
                'fecha_emision' => $today,
                'tipo_ingresos' => '01',
                'tipo_pago' => 1,
                'items' => itemsGravados18Fijo(1, 2, 1000, 10000),
            ],
        ];
    }

    return $cases;
}

function itemsGravados18Fijo(int $count, int $fixedQty, float $minPrice, float $maxPrice): array
{
    $items = [];
    $nombres = ['Servicio profesional', 'Producto A', 'Producto B'];
    for ($i = 0; $i < $count; $i++) {
        $items[] = [
            'numero_linea' => $i + 1,
            'indicador_facturacion' => 1,
            'nombre_item' => $nombres[array_rand($nombres)],
            'indicador_bien_servicio' => 1,
            'cantidad' => $fixedQty,
            'unidad_medida' => '43',
            'precio_unitario' => round(rand((int) ($minPrice * 100), (int) ($maxPrice * 100)) / 100, 2),
        ];
    }
    return $items;
}

function itemsGravados18(int $count, float $minPrice, float $maxPrice): array
{
    $items = [];
    $nombres = ['Servicio profesional', 'Producto A', 'Producto B', 'Insumo', 'Consultoria', 'Mantenimiento', 'Licencia'];
    for ($i = 0; $i < $count; $i++) {
        $items[] = [
            'numero_linea' => $i + 1,
            'indicador_facturacion' => 1,
            'nombre_item' => $nombres[array_rand($nombres)],
            'indicador_bien_servicio' => rand(1, 2),
            'cantidad' => rand(1, 10),
            'unidad_medida' => '43',
            'precio_unitario' => round(rand((int) ($minPrice * 100), (int) ($maxPrice * 100)) / 100, 2),
        ];
    }
    return $items;
}

function itemsExentos(int $count, float $minPrice, float $maxPrice, int $bienServicio = 1): array
{
    $items = [];
    $nombres = ['Servicio exento', 'Producto basico', 'Insumo exento', 'Articulo'];
    for ($i = 0; $i < $count; $i++) {
        $items[] = [
            'numero_linea' => $i + 1,
            'indicador_facturacion' => 4,
            'nombre_item' => $nombres[array_rand($nombres)],
            'indicador_bien_servicio' => $bienServicio,
            'cantidad' => rand(1, 10),
            'unidad_medida' => '43',
            'precio_unitario' => round(rand((int) ($minPrice * 100), (int) ($maxPrice * 100)) / 100, 2),
        ];
    }
    return $items;
}

function itemsCero(int $count, float $minPrice, float $maxPrice): array
{
    $items = [];
    $nombres = ['Producto exportacion', 'Mercaderia', 'Articulo exportado'];
    for ($i = 0; $i < $count; $i++) {
        $items[] = [
            'numero_linea' => $i + 1,
            'indicador_facturacion' => 3,
            'nombre_item' => $nombres[array_rand($nombres)],
            'indicador_bien_servicio' => 1,
            'cantidad' => rand(1, 5),
            'unidad_medida' => '43',
            'precio_unitario' => round(rand((int) ($minPrice * 100), (int) ($maxPrice * 100)) / 100, 2),
        ];
    }
    return $items;
}

function withDefaultIndicadorMontoGravado(array $payload): array
{
    $tipoEcf = (string) ($payload['tipo_ecf'] ?? '');
    $supportsIndicador = in_array($tipoEcf, ['31', '32', '33', '34', '41', '45'], true);
    $needsDefault = !array_key_exists('indicador_monto_gravado', $payload)
        || $payload['indicador_monto_gravado'] === null
        || $payload['indicador_monto_gravado'] === '';
    if ($supportsIndicador && $needsDefault) {
        $payload['indicador_monto_gravado'] = '0';
    }
    return $payload;
}

function withE41Retencion(array $payload): array
{
    if ((string) ($payload['tipo_ecf'] ?? '') !== '41' || !is_array($payload['items'] ?? null)) {
        return $payload;
    }

    $totalItbisRetenido = 0.0;
    $totalIsrRetencion = 0.0;
    foreach ($payload['items'] as &$item) {
        if (!is_array($item)) {
            continue;
        }
        if (($item['indicador_agente_retencion_percepcion'] ?? null) === null || $item['indicador_agente_retencion_percepcion'] === '') {
            $item['indicador_agente_retencion_percepcion'] = '1';
        }

        $cantidad = (float) ($item['cantidad'] ?? 1);
        $precio = (float) ($item['precio_unitario'] ?? 0);
        $base = round($cantidad * $precio, 2);
        $indicadorFacturacion = (int) ($item['indicador_facturacion'] ?? 1);
        $itbisRate = $indicadorFacturacion === 2 ? 0.16 : ($indicadorFacturacion === 1 ? 0.18 : 0.0);
        $itbisRetenido = round($base * $itbisRate, 2);

        if (($item['monto_itbis_retenido'] ?? null) === null || $item['monto_itbis_retenido'] === '') {
            $item['monto_itbis_retenido'] = $itbisRetenido;
        }
        if (($item['monto_isr_retenido'] ?? null) === null || $item['monto_isr_retenido'] === '') {
            $item['monto_isr_retenido'] = 0.0;
        }

        $totalItbisRetenido += (float) $item['monto_itbis_retenido'];
        $totalIsrRetencion += (float) $item['monto_isr_retenido'];
    }
    unset($item);

    $totales = is_array($payload['totales'] ?? null) ? $payload['totales'] : [];
    $totales += [
        'total_itbis_retenido' => round($totalItbisRetenido, 2),
        'total_isr_retencion' => round($totalIsrRetencion, 2),
    ];
    $payload['totales'] = $totales;

    return $payload;
}

function withE47Retencion(array $payload): array
{
    if ((string) ($payload['tipo_ecf'] ?? '') !== '47' || !is_array($payload['items'] ?? null)) {
        return $payload;
    }

    $totalIsrRetencion = 0.0;
    foreach ($payload['items'] as &$item) {
        if (!is_array($item)) {
            continue;
        }
        $item['indicador_bien_servicio'] = 2;
        if (($item['indicador_agente_retencion_percepcion'] ?? null) === null || $item['indicador_agente_retencion_percepcion'] === '') {
            $item['indicador_agente_retencion_percepcion'] = '1';
        }

        $cantidad = (float) ($item['cantidad'] ?? 1);
        $precio = (float) ($item['precio_unitario'] ?? 0);
        $base = round($cantidad * $precio, 2);
        if (($item['monto_isr_retenido'] ?? null) === null || $item['monto_isr_retenido'] === '') {
            $item['monto_isr_retenido'] = round($base * 0.27, 2);
        }
        unset($item['monto_itbis_retenido']);
        $totalIsrRetencion += (float) $item['monto_isr_retenido'];
    }
    unset($item);

    $totales = is_array($payload['totales'] ?? null) ? $payload['totales'] : [];
    $totales['total_isr_retencion'] = round($totalIsrRetencion, 2);
    $payload['totales'] = $totales;

    return $payload;
}

function withDefaultItbisRates(array $payload): array
{
    $tipoEcf = (string) ($payload['tipo_ecf'] ?? '');
    $rateDefaults = [
        '31' => ['itbis1' => '18', 'itbis2' => '16', 'itbis3' => '0'],
        '32' => ['itbis1' => '18', 'itbis2' => '16', 'itbis3' => '0'],
        '33' => ['itbis1' => '18', 'itbis2' => '16', 'itbis3' => '0'],
        '34' => ['itbis1' => '18', 'itbis2' => '16', 'itbis3' => '0'],
        '41' => ['itbis1' => '18', 'itbis2' => '16', 'itbis3' => '0'],
        '45' => ['itbis1' => '18', 'itbis2' => '16', 'itbis3' => '0'],
        '46' => ['itbis3' => '0'],
    ];
    if (!isset($rateDefaults[$tipoEcf])) {
        return $payload;
    }
    $totales = is_array($payload['totales'] ?? null) ? $payload['totales'] : [];
    $payload['totales'] = array_merge($rateDefaults[$tipoEcf], $totales);
    return $payload;
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

function postFactura(string $apiBase, string $apiKey, array $payload): array
{
    $url = $apiBase . '/facturas';
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
    return ['http_status' => $status, 'body' => is_array($decoded) ? $decoded : ['status' => false, 'error' => 'no-json: ' . substr($raw, 0, 300)]];
}

function printSummary(array $results): void
{
    $ok = count(array_filter($results, fn($r) => $r['ok'] ?? false));
    fwrite(STDOUT, "\n========================================\n");
    fwrite(STDOUT, sprintf("Resumen: %d/%d OK\n", $ok, count($results)));
    fwrite(STDOUT, "========================================\n");
    foreach ($results as $r) {
        $marker = ($r['ok'] ?? false) ? '+' : '-';
        $detail = ($r['ok'] ?? false)
            ? ($r['e_ncf'] ?? '?') . ' track=' . ($r['track_id'] ?? '-') . (!empty($r['rfce_track_id']) ? ' rfce=' . $r['rfce_track_id'] : '')
            : ($r['error'] ?? '?');
        fwrite(STDOUT, sprintf("  %s %s | E%s | %s\n", $marker, $r['etiqueta'] ?? '?', $r['tipo_ecf'] ?? '?', substr($detail, 0, 150)));
    }
}

exit(main($argv));
