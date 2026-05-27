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
 *
 *   # Enviar E34 creando los E31 en la misma corrida:
 *   php tools/send_fase4_simulation.php ... \
 *       --counts=E31:2,E32_gte_250k:0,E32_lt_250k:0,E33:0,E34:2,E41:0,E43:0,E44:0,E45:0,E46:0,E47:0 \
 *       --nota-wait-accepted=240 --nota-poll=15
 *
 * Los E32 <250k se manejan en dos pasos:
 *   1) se envia el RFCE a DGII;
 *   2) se devuelve la URL para descargar el XML integro: GET /facturas/{id}/xml.
 *
 * Para reenviar solo E33/E34 contra E31 ya aceptados:
 *   --refs-e31-file=tools/fase4_no_notes_estados.json --refs-date=27-05-2026
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
    $notaWaitAccepted = isset($opts['nota-wait-accepted']) ? max(0, (int) $opts['nota-wait-accepted']) : 0;
    $notaPoll = isset($opts['nota-poll']) ? max(1, (int) $opts['nota-poll']) : 10;
    $preloadedE31Refs = loadPreloadedE31References($opts);

    $cases = buildPlan($countsOverride);
    fwrite(STDOUT, "==> " . count($cases) . " casos planificados\n");
    if ($preloadedE31Refs !== []) {
        fwrite(STDOUT, "==> " . count($preloadedE31Refs) . " referencias E31 precargadas\n");
    }

    $results = [];
    $eNcfsByType = [];
    if ($preloadedE31Refs !== []) {
        $eNcfsByType['31'] = $preloadedE31Refs;
    }
    $notaDelayApplied = false;
    $notaPrereqOk = true;
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
            if ($notaWaitAccepted > 0 && !$dryRun) {
                $notaPrereqOk = waitForAcceptedReferences($apiBase, $apiKey, $eNcfsByType['31'] ?? [], $notaWaitAccepted, $notaPoll);
                if (!$notaPrereqOk) {
                    fwrite(STDOUT, "==> Se omiten las notas para no consumir secuencias mientras los E31 no esten aceptados.\n");
                }
            }
        }
        if (in_array($case['tipo_ecf'], ['33', '34'], true)) {
            if (!$notaPrereqOk) {
                $results[] = [
                    'etiqueta' => $case['etiqueta'],
                    'tipo_ecf' => $case['tipo_ecf'],
                    'ok' => false,
                    'error' => 'E31 de referencia no aceptado antes de enviar nota',
                ];
                fwrite(STDOUT, "    SKIP: E31 de referencia no aceptado\n");
                continue;
            }
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
                'rnc_otro_contribuyente' => null,
                'fecha_ncf_modificado' => $candidate['fecha'] ?? date('d-m-Y'),
                'codigo_modificacion' => '3',
                'razon_modificacion' => $case['tipo_ecf'] === '34'
                    ? 'Nota de credito por ajuste de monto'
                    : 'Nota de debito por ajuste de monto',
            ];
            if (!isset($case['payload']['comprador']) && !empty($candidate['rnc_comprador'])) {
                $case['payload']['comprador'] = [
                    'rnc' => $candidate['rnc_comprador'],
                    'razon_social' => $candidate['nombre_comprador'] ?? 'CLIENTE COMPROBANTE TEST SRL',
                ];
            }
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
                    'nombre_comprador' => $payload['comprador']['razon_social'] ?? $payload['comprador']['nombre'] ?? null,
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
            if (!empty($case['full_xml_url']) && $entry['factura_id']) {
                $entry['xml_url'] = buildFacturaXmlUrl($apiBase, (int) $entry['factura_id']);
            }

            if ($entry['e_ncf']) {
                $eNcfsByType[$case['tipo_ecf']][] = [
                    'e_ncf' => $entry['e_ncf'],
                    'factura_id' => $entry['factura_id'],
                    'fecha' => $payload['fecha_emision'] ?? date('d-m-Y'),
                    'rnc_comprador' => $payload['comprador']['rnc'] ?? null,
                    'nombre_comprador' => $payload['comprador']['razon_social'] ?? $payload['comprador']['nombre'] ?? null,
                ];
            }
            fwrite(STDOUT, sprintf("    OK e_ncf=%s estado=%s track=%s\n",
                $entry['e_ncf'] ?? '?',
                $entry['estado_dgii'] ?? '?',
                $entry['track_id'] ?? '-'
            ));
            if (!empty($entry['xml_url'])) {
                fwrite(STDOUT, "    XML " . $entry['xml_url'] . "\n");
            }
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
            'full_xml_url' => true,
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

function loadPreloadedE31References(array $opts): array
{
    $refs = [];

    if (!empty($opts['refs-e31'])) {
        foreach (preg_split('/[\s,;]+/', trim((string) $opts['refs-e31'])) ?: [] as $eNcf) {
            if ($eNcf !== '') {
                $refs[] = makeE31Reference($eNcf, null, $opts);
            }
        }
    }

    $refsFile = $opts['refs-e31-file'] ?? null;
    if ($refsFile !== null && $refsFile !== '') {
        $refs = array_merge($refs, readAcceptedE31ReferencesFile((string) $refsFile, $opts));
    }

    $deduped = [];
    $seen = [];
    foreach ($refs as $ref) {
        $key = (string) ($ref['e_ncf'] ?? '');
        if ($key === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $deduped[] = $ref;
    }
    return $deduped;
}

function readAcceptedE31ReferencesFile(string $path, array $opts): array
{
    if (!is_file($path)) {
        fwrite(STDERR, "WARN: archivo de referencias no encontrado: {$path}\n");
        return [];
    }

    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        fwrite(STDERR, "WARN: archivo de referencias no es JSON valido: {$path}\n");
        return [];
    }

    $refs = [];
    foreach ($decoded as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $eNcf = $entry['e_ncf'] ?? $entry['response']['data']['e_ncf'] ?? null;
        if (!is_string($eNcf) || !str_starts_with($eNcf, 'E31')) {
            continue;
        }

        $estado = strtoupper(trim((string) (
            $entry['estado_dgii']
            ?? $entry['response']['data']['estado_dgii']
            ?? $entry['response']['data']['consulta']['estado']
            ?? ''
        )));
        if ($estado !== 'ACEPTADO' && $estado !== 'ACEPTADO CONDICIONAL') {
            continue;
        }

        $facturaId = $entry['factura_id'] ?? $entry['response']['data']['factura_id'] ?? null;
        $refs[] = makeE31Reference($eNcf, $facturaId ? (int) $facturaId : null, $opts);
    }

    if ($refs === []) {
        fwrite(STDERR, "WARN: no se encontraron E31 aceptados en {$path}\n");
    }

    return $refs;
}

function makeE31Reference(string $eNcf, ?int $facturaId, array $opts): array
{
    $ref = [
        'e_ncf' => trim($eNcf),
        'fecha' => normalizeReferenceDate((string) ($opts['refs-date'] ?? date('d-m-Y'))),
        'rnc_comprador' => trim((string) ($opts['refs-rnc'] ?? '131880681')),
        'nombre_comprador' => trim((string) ($opts['refs-name'] ?? 'CLIENTE COMPROBANTE TEST SRL')),
    ];
    if ($facturaId !== null) {
        $ref['factura_id'] = $facturaId;
    }
    return $ref;
}

function normalizeReferenceDate(string $date): string
{
    $date = trim($date);
    if ($date === '') {
        return date('d-m-Y');
    }
    if (preg_match('/^\d{2}-\d{2}-\d{4}$/', $date)) {
        return $date;
    }
    $ts = strtotime($date);
    return $ts === false ? $date : date('d-m-Y', $ts);
}

function waitForAcceptedReferences(string $apiBase, string $apiKey, array $refs, int $timeoutSeconds, int $pollSeconds): bool
{
    $tracked = [];
    foreach ($refs as $ref) {
        if (!is_array($ref) || empty($ref['factura_id'])) {
            continue;
        }
        $tracked[(int) $ref['factura_id']] = $ref;
    }

    if ($tracked === []) {
        fwrite(STDOUT, "==> No hay E31 con factura_id para consultar antes de notas.\n");
        return true;
    }

    $deadline = time() + $timeoutSeconds;
    $pending = $tracked;
    fwrite(STDOUT, "==> Consultando E31 hasta ACEPTADO antes de enviar notas (timeout {$timeoutSeconds}s)...\n");

    while ($pending !== []) {
        $nextPending = [];
        foreach ($pending as $facturaId => $ref) {
            $resp = consultarEstadoFactura($apiBase, $apiKey, (int) $facturaId);
            $estado = extractEstadoDgii($resp);
            $detalle = extractEstadoDetalle($resp);
            fwrite(STDOUT, sprintf(
                "    E31 %s factura_id=%d estado=%s%s\n",
                $ref['e_ncf'] ?? '?',
                $facturaId,
                $estado !== '' ? $estado : '?',
                $detalle !== '' ? ' :: ' . substr($detalle, 0, 120) : ''
            ));

            if ($estado === 'ACEPTADO' || $estado === 'ACEPTADO CONDICIONAL') {
                continue;
            }
            if ($estado === 'RECHAZADO') {
                return false;
            }
            $nextPending[$facturaId] = $ref;
        }

        if ($nextPending === []) {
            return true;
        }
        if (time() >= $deadline) {
            return false;
        }

        $sleep = min($pollSeconds, max(1, $deadline - time()));
        sleep($sleep);
        $pending = $nextPending;
    }

    return true;
}

function consultarEstadoFactura(string $apiBase, string $apiKey, int $facturaId): array
{
    $url = $apiBase . '/facturas/' . $facturaId . '/estado';
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => DEFAULT_TIMEOUT_SECONDS,
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

function extractEstadoDgii(array $resp): string
{
    if (($resp['http_status'] ?? 0) !== 200 || !(bool) ($resp['body']['status'] ?? false)) {
        return '';
    }

    $data = $resp['body']['data'] ?? [];
    if (!is_array($data)) {
        return '';
    }
    $consulta = is_array($data['consulta'] ?? null) ? $data['consulta'] : [];
    return strtoupper(trim((string) ($data['estado_dgii'] ?? $consulta['estado'] ?? '')));
}

function extractEstadoDetalle(array $resp): string
{
    if (($resp['http_status'] ?? 0) !== 200 || !(bool) ($resp['body']['status'] ?? false)) {
        return (string) ($resp['body']['error'] ?? ('HTTP ' . ($resp['http_status'] ?? 0)));
    }

    $data = $resp['body']['data'] ?? [];
    $consulta = is_array($data['consulta'] ?? null) ? $data['consulta'] : [];
    $mensajes = is_array($consulta['mensajes'] ?? null) ? $consulta['mensajes'] : [];
    if ($mensajes === []) {
        return '';
    }

    return trim((string) ($mensajes[0]['valor'] ?? '') . ' [' . (string) ($mensajes[0]['codigo'] ?? '') . ']');
}

function buildFacturaXmlUrl(string $apiBase, int $facturaId): string
{
    return rtrim($apiBase, '/') . '/facturas/' . $facturaId . '/xml';
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
