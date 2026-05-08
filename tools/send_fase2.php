<?php

/**
 * Runner de Fase 2 (DGII e-CF certification).
 *
 * Lee el set-de-pruebas xlsx y envia cada caso al endpoint POST /api/facturas
 * de gratex.net. Los E32 < 250,000 se enrutan automaticamente al servicio
 * de RecepcionFC (resumen) por el ECFEmissionService.
 *
 * Uso:
 *   php tools/send_fase2.php samples/131256432-05052026110320.xlsx \
 *       --api=https://gratex.net/api \
 *       --api-key=7a775f6fb0d5ccab15cf149d2c60f15c \
 *       --client-id=1
 *
 * Modos:
 *   --dry-run               No envia nada; imprime los payloads que se mandarian
 *   --filter=E31,E32        Solo procesa los tipos indicados
 *   --case=E310000000005    Solo procesa este caso
 *   --output=results.json   Donde guardar el reporte (default: tools/fase2_results.json)
 *
 * Salida:
 *   - JSON con resultado por caso (caso, e_ncf, http_status, track_id, estado, error)
 *   - Tabla resumen en stdout
 */

require_once __DIR__ . '/Fase2XlsxReader.php';

const DEFAULT_API = 'https://gratex.net/api';
const DEFAULT_CLIENT_ID = 1;
const DEFAULT_OUTPUT = __DIR__ . '/fase2_results.json';
const DEFAULT_TIMEOUT_SECONDS = 60;

function main(array $argv): int
{
    if (!class_exists('ZipArchive')) {
        fwrite(STDERR, "ERROR: Falta la extension 'zip' en PHP CLI. Habilitala en php.ini (extension=zip) y reintenta.\n");
        return 3;
    }
    if (!function_exists('curl_init')) {
        fwrite(STDERR, "ERROR: Falta la extension 'curl' en PHP CLI. Habilitala en php.ini (extension=curl) y reintenta.\n");
        return 3;
    }

    $opts = parseArgs($argv);
    if (!isset($opts['xlsx'])) {
        fwrite(STDERR, "Uso: php tools/send_fase2.php <ruta_xlsx> [--api=URL] [--api-key=KEY] [--client-id=N] [--filter=...] [--case=...] [--output=...] [--dry-run]\n");
        return 2;
    }

    $apiBase = rtrim($opts['api'] ?? DEFAULT_API, '/');
    $apiKey = $opts['api-key'] ?? '';
    $clientId = (int) ($opts['client-id'] ?? DEFAULT_CLIENT_ID);
    $userId = isset($opts['user-id']) ? (int) $opts['user-id'] : null;
    $output = $opts['output'] ?? DEFAULT_OUTPUT;
    $filter = isset($opts['filter']) ? array_map('trim', explode(',', $opts['filter'])) : [];
    $caseFilter = $opts['case'] ?? '';
    $exclude = isset($opts['exclude']) ? array_map('trim', explode(',', $opts['exclude'])) : [];
    $dryRun = isset($opts['dry-run']);

    if (!$dryRun && $apiKey === '') {
        fwrite(STDERR, "ERROR: --api-key es requerido (o usa --dry-run para inspeccionar payloads).\n");
        return 2;
    }

    fwrite(STDOUT, "==> Leyendo xlsx: {$opts['xlsx']}\n");
    $reader = new Fase2XlsxReader($opts['xlsx']);
    $rows = $reader->readSheet('ECF');
    fwrite(STDOUT, "==> " . count($rows) . " casos en hoja ECF\n");

    $rfceMap = [];
    try {
        foreach ($reader->readSheet('RFCE') as $rfceRow) {
            if (!empty($rfceRow['ENCF'])) {
                $rfceMap[$rfceRow['ENCF']] = $rfceRow;
            }
        }
        fwrite(STDOUT, "==> " . count($rfceMap) . " casos en hoja RFCE\n");
    } catch (Throwable $e) {
        fwrite(STDOUT, "==> Sin hoja RFCE: " . $e->getMessage() . "\n");
    }

    if ($filter) {
        $rows = array_values(array_filter($rows, fn($r) => in_array((string)($r['TipoeCF'] ?? ''), $filter, true)));
        fwrite(STDOUT, "==> Filtro tipos: " . implode(',', $filter) . " -> " . count($rows) . " casos\n");
    }
    if ($caseFilter !== '') {
        $rows = array_values(array_filter($rows, fn($r) => ($r['ENCF'] ?? '') === $caseFilter));
        fwrite(STDOUT, "==> Filtro caso: $caseFilter -> " . count($rows) . " casos\n");
    }
    if ($exclude) {
        $rows = array_values(array_filter($rows, fn($r) => !in_array((string)($r['ENCF'] ?? ''), $exclude, true)));
        fwrite(STDOUT, "==> Excluye: " . implode(',', $exclude) . " -> " . count($rows) . " casos\n");
    }

    $results = [];
    foreach ($rows as $idx => $row) {
        $caso = $row['CasoPrueba'] ?? ('row' . ($idx + 2));
        $tipo = (string) ($row['TipoeCF'] ?? '');
        $encf = (string) ($row['ENCF'] ?? '');
        fwrite(STDOUT, sprintf("\n[%d/%d] %s (E%s, %s)\n", $idx + 1, count($rows), $caso, $tipo, $encf));

        try {
            $rfceRow = $rfceMap[$row['ENCF'] ?? ''] ?? null;
            $payload = mapRowToPayload($row, $clientId, $userId, $rfceRow);
        } catch (Throwable $e) {
            fwrite(STDOUT, "    ! Error mapeando payload: " . $e->getMessage() . "\n");
            $results[] = [
                'caso' => $caso,
                'tipo_ecf' => $tipo,
                'e_ncf' => $encf,
                'ok' => false,
                'error' => 'mapeo: ' . $e->getMessage(),
            ];
            continue;
        }

        if ($dryRun) {
            fwrite(STDOUT, "    DRY-RUN payload:\n" . json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
            $results[] = [
                'caso' => $caso,
                'tipo_ecf' => $tipo,
                'e_ncf' => $encf,
                'ok' => true,
                'dry_run' => true,
                'payload' => $payload,
            ];
            continue;
        }

        $response = postFactura($apiBase, $apiKey, $payload);
        $entry = [
            'caso' => $caso,
            'tipo_ecf' => $tipo,
            'e_ncf' => $encf,
            'http_status' => $response['http_status'],
            'ok' => $response['http_status'] >= 200 && $response['http_status'] < 300 && (($response['body']['status'] ?? false) === true),
            'response' => $response['body'],
        ];

        if ($entry['ok']) {
            $data = $response['body']['data'] ?? [];
            $entry['factura_id'] = $data['factura_id'] ?? null;
            $entry['track_id'] = $data['track_id'] ?? null;
            $entry['rfce_track_id'] = $data['rfce_track_id'] ?? null;
            $entry['estado_dgii'] = $data['estado_dgii'] ?? $data['estado'] ?? null;
            $entry['flujo'] = $data['flujo'] ?? null;
            fwrite(STDOUT, sprintf(
                "    OK  estado=%s track=%s rfce=%s flujo=%s\n",
                $entry['estado_dgii'] ?? '?',
                $entry['track_id'] ?? '-',
                $entry['rfce_track_id'] ?? '-',
                $entry['flujo'] ?? '-'
            ));
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
            if ($eq === false) {
                $opts[substr($arg, 2)] = true;
            } else {
                $opts[substr($arg, 2, $eq - 2)] = substr($arg, $eq + 1);
            }
        } elseif (!isset($opts['xlsx'])) {
            $opts['xlsx'] = $arg;
        }
    }
    return $opts;
}

/**
 * Mapea una fila del xlsx a un payload del POST /api/facturas.
 * Toma la mayoria de campos del comprador y emisor del xlsx (DGII te dice
 * que valores usar). El client_id solo se usa para satisfacer la API actual
 * que requiere un cliente existente; los datos reales vienen del row.
 */
function mapRowToPayload(array $row, int $clientId, ?int $userId, ?array $rfceRow = null): array
{
    $tipoEcf = (string) ($row['TipoeCF'] ?? '');
    if (!preg_match('/^(31|32|33|34|41|43|44|45|46|47)$/', $tipoEcf)) {
        throw new RuntimeException('TipoeCF invalido: ' . $tipoEcf);
    }

    $items = extractItems($row);
    if (empty($items)) {
        throw new RuntimeException('La fila no tiene items.');
    }

    $payload = [
        'tipo_ecf' => $tipoEcf,
        'e_ncf' => $row['ENCF'] ?? null,
        'client_id' => $clientId,
        'fecha_emision' => $row['FechaEmision'] ?? date('d-m-Y'),
        'fecha_vencimiento_secuencia' => $row['FechaVencimientoSecuencia'] ?? null,
        'tipo_ingresos' => $row['TipoIngresos'] ?? '01',
        'tipo_pago' => (int) ($row['TipoPago'] ?? 1),
        'indicador_monto_gravado' => $row['IndicadorMontoGravado'] ?? null,
        'indicador_nota_credito' => $row['IndicadorNotaCredito'] ?? null,
        'emisor' => [
            'rnc' => $row['RNCEmisor'] ?? null,
            'razon_social' => $row['RazonSocialEmisor'] ?? null,
            'nombre_comercial' => $row['NombreComercial'] ?? null,
            'sucursal' => $row['Sucursal'] ?? null,
            'direccion' => $row['DireccionEmisor'] ?? null,
            'municipio' => $row['Municipio'] ?? null,
            'provincia' => $row['Provincia'] ?? null,
            'telefono' => $row['TelefonoEmisor[1]'] ?? null,
            'correo' => $row['CorreoEmisor'] ?? null,
            'website' => $row['WebSite'] ?? null,
            'actividad_economica' => $row['ActividadEconomica'] ?? null,
            'codigo_vendedor' => $row['CodigoVendedor'] ?? null,
            'numero_factura_interna' => $row['NumeroFacturaInterna'] ?? null,
            'numero_pedido_interno' => $row['NumeroPedidoInterno'] ?? null,
            'zona_venta' => $row['ZonaVenta'] ?? null,
            'ruta_venta' => $row['RutaVenta'] ?? null,
            'informacion_adicional' => $row['InformacionAdicionalEmisor'] ?? null,
        ],
        'comprador' => [
            'rnc' => $row['RNCComprador'] ?? null,
            'razon_social' => $row['RazonSocialComprador'] ?? null,
            'contacto' => $row['ContactoComprador'] ?? null,
            'correo' => $row['CorreoComprador'] ?? null,
            'direccion' => $row['DireccionComprador'] ?? null,
            'municipio' => $row['MunicipioComprador'] ?? null,
            'provincia' => $row['ProvinciaComprador'] ?? null,
            'fecha_entrega' => $row['FechaEntrega'] ?? null,
            'contacto_entrega' => $row['ContactoEntrega'] ?? null,
            'direccion_entrega' => $row['DireccionEntrega'] ?? null,
            'telefono_adicional' => $row['TelefonoAdicional'] ?? null,
            'fecha_orden_compra' => $row['FechaOrdenCompra'] ?? null,
            'numero_orden_compra' => $row['NumeroOrdenCompra'] ?? null,
            'codigo_interno' => $row['CodigoInternoComprador'] ?? null,
            'responsable_pago' => $row['ResponsablePago'] ?? null,
            'informacion_adicional' => $row['InformacionAdicionalComprador'] ?? null,
        ],
        'items' => $items,
    ];
    if ($userId !== null) {
        $payload['user_id'] = $userId;
    }

    if (!empty($row['NCFModificado'])) {
        $payload['informacion_referencia'] = [
            'ncf_modificado' => $row['NCFModificado'],
            'rnc_otro_contribuyente' => $row['RNCComprador'] ?? null,
            'fecha_ncf_modificado' => $row['FechaNCFModificado'] ?? null,
            'codigo_modificacion' => $row['CodigoModificacion'] ?? null,
        ];
    }

    if ($rfceRow !== null) {
        $payload['rfce_emisor'] = [
            'rnc' => $rfceRow['RNCEmisor'] ?? null,
            'razon_social' => $rfceRow['RazonSocialEmisor'] ?? null,
        ];
        $payload['rfce_comprador'] = [
            'rnc' => $rfceRow['RNCComprador'] ?? null,
            'identificador_extranjero' => $rfceRow['IdentificadorExtranjero'] ?? null,
            'razon_social' => $rfceRow['RazonSocialComprador'] ?? null,
        ];
    }

    return $payload;
}

function extractItems(array $row): array
{
    $items = [];
    for ($i = 1; $i <= 1000; $i++) {
        $nombre = $row["NombreItem[$i]"] ?? '';
        if ($nombre === '') {
            break;
        }
        $items[] = [
            'numero_linea' => (int) ($row["NumeroLinea[$i]"] ?? $i),
            'indicador_facturacion' => (int) ($row["IndicadorFacturacion[$i]"] ?? 1),
            'nombre_item' => $nombre,
            'indicador_bien_servicio' => (int) ($row["IndicadorBienoServicio[$i]"] ?? 2),
            'descripcion' => $row["DescripcionItem[$i]"] ?? '',
            'cantidad' => (float) ($row["CantidadItem[$i]"] ?? 1),
            'unidad_medida' => $row["UnidadMedida[$i]"] ?? '',
            'precio_unitario' => (float) ($row["PrecioUnitarioItem[$i]"] ?? 0),
        ];
    }
    return $items;
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
    $cainfo = getenv('CURL_CA_BUNDLE');
    if ($cainfo && is_file($cainfo)) {
        $opts[CURLOPT_CAINFO] = $cainfo;
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        return [
            'http_status' => 0,
            'body' => ['status' => false, 'error' => 'curl: ' . $err],
        ];
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        $decoded = ['status' => false, 'error' => 'respuesta no JSON: ' . substr($raw, 0, 300)];
    }
    return ['http_status' => $status, 'body' => $decoded];
}

function printSummary(array $results): void
{
    $ok = count(array_filter($results, fn($r) => $r['ok'] ?? false));
    $fail = count($results) - $ok;
    fwrite(STDOUT, "\n========================================\n");
    fwrite(STDOUT, sprintf("Resumen: %d/%d OK, %d fallaron\n", $ok, count($results), $fail));
    fwrite(STDOUT, "========================================\n");
    foreach ($results as $r) {
        $marker = ($r['ok'] ?? false) ? '+' : '-';
        $detail = ($r['ok'] ?? false)
            ? ($r['estado_dgii'] ?? '') . ' track=' . ($r['track_id'] ?? '-') . (!empty($r['rfce_track_id']) ? ' rfce=' . $r['rfce_track_id'] : '')
            : ($r['error'] ?? '?');
        fwrite(STDOUT, sprintf("  %s %s | E%s | %s | %s\n", $marker, $r['caso'], $r['tipo_ecf'], $r['e_ncf'], $detail));
    }
}

exit(main($argv));
