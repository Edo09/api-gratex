<?php

/**
 * Runner de Fase 2 (DGII e-CF certification).
 *
 * Lee el set-de-pruebas xlsx y envia cada caso al endpoint POST /api/facturas
 * de gratex.net. Los E32 < 250,000 se enrutan automaticamente al servicio
 * de RecepcionFC (resumen) por el ECFEmissionService.
 *
 * Uso (tenant tipo app):
 *   php tools/send_fase2.php samples/131256432-05052026110320.xlsx \
 *       --api=https://gratex.net/api \
 *       --api-key=7a775f6fb0d5ccab15cf149d2c60f15c \
 *       --client-id=1
 *
 * Uso (tenant tipo INTEGRACION): basta con pasar --api-secret. El runner cambia
 * a POST /api/integracion/ecf, manda X-API-KEY + X-API-SECRET y omite
 * client_id/user_id (ese tenant no tiene DB ni clientes). El e-NCF sale del
 * xlsx, que es justo lo que ese modo exige (el cliente asigna su secuencia):
 *   php tools/send_fase2.php samples/131111111-set.xlsx \
 *       --api=https://gratex.net/api \
 *       --api-key=<api_key> --api-secret=<api_secret>
 *
 * Modo app, antes de enviar nada (certEmpresaPreflightApp, GET /api/emisor): el
 * RNCEmisor del xlsx tiene que ser el de la empresa del token, y el set tiene
 * que salir a certecf (--ambiente o, sin el, el ambiente de la empresa). Con el
 * xlsx de otra empresa, o un token vencido, el runner aborta sin mandar nada.
 *
 * Modos:
 *   --dry-run               No envia nada; imprime los payloads que se mandarian
 *   --filter=E31,E32        Solo procesa los tipos indicados
 *   --case=E310000000005    Solo procesa este caso; acepta varios separados por coma
 *                           (--case=E320000000011,E320000000013): sirve para mandar
 *                           SOLO lo que falto de una corrida, sin reenviar lo que la
 *                           DGII ya acepto (un duplicado sale rechazado y reinicia el set)
 *   --reintentos=N          Solo app: veces que se vuelve sobre un caso que no quedo
 *                           ni emitido ni rechazado (default 2; 0 = no reintentar).
 *                           Ver f2ReintentarPendientes().
 *   --reintento-pausa=N     Segundos de pausa antes de cada ronda de reintentos (default 10)
 *   --output=results.json   Donde guardar el reporte (default: tools/fase2_results.json)
 *   --xml-dir=DIR           Solo integracion: guarda el XML firmado de cada caso
 *                           (default tools/xml_integracion). Sin DB propia esa
 *                           respuesta es la unica copia a mano del comprobante,
 *                           y para los E32 <250k es el XML integro que hay que
 *                           subir al portal DGII.
 *   --nota-wait-accepted=N  Antes de las notas (E33/E34) espera hasta N segundos
 *                           a que la DGII termine de procesar todo lo enviado
 *                           (default 300; 0 = no esperar). Si la DGII rechazo
 *                           algo, las notas NO se mandan: ese rechazo ya
 *                           reinicio el set, y una nota enviada despues no
 *                           encuentra su original ("El eNCF modificado no ha
 *                           sido emitido"). Si se agota el tiempo, salen con aviso.
 *   --nota-poll=N           Segundos entre consultas de esa espera (default 15)
 *   --empresa-rnc=RNC       Solo integracion: la empresa elegida (integracion.html la
 *                           manda). Si el RNCEmisor de lo que se va a enviar es otro,
 *                           aborta antes de enviar nada (tambien en dry-run).
 *   --permitir-produccion   Solo por CLI: deja enviar el set fuera de 'certecf'
 *                           (integracion: el ambiente de la empresa; app: --ambiente
 *                           o el de la empresa). Sin esto el runner aborta: el set
 *                           saldria como e-CF REALES. Ver cert_empresa_preflight.php.
 *
 * Salida:
 *   - JSON con resultado por caso (caso, e_ncf, http_status, track_id, estado, error)
 *   - Tabla resumen en stdout
 */

require_once __DIR__ . '/Fase2XlsxReader.php';
require_once __DIR__ . '/cert_empresa_preflight.php';

const DEFAULT_API = 'https://gratex.net/api';
const DEFAULT_CLIENT_ID = 1;
const DEFAULT_OUTPUT = __DIR__ . '/fase2_results.json';
const DEFAULT_XML_DIR = __DIR__ . '/xml_integracion';
const DEFAULT_TIMEOUT_SECONDS = 60;
// El POST de emision espera en el servidor hasta tres llamadas a la DGII (semilla,
// token y envio), cada una con su propio timeout (DGII_ECF_TIMEOUT, 30 s). Con 60 s
// el runner podia cortar antes que el servidor y quedarse sin saber que paso.
const F2_POST_TIMEOUT_SECONDS = 120;
const DEFAULT_REINTENTOS = 2;
const DEFAULT_REINTENTO_PAUSA_SECONDS = 10;
// f2ClaseEnvio(): lo que f2ReintentarPendientes() vuelve a intentar (solo app).
const F2_CLASES_REINTENTABLES = ['incierto', 'no_enviado', 'no_recibido'];
const DEFAULT_NOTA_WAIT_SECONDS = 300;
// Cada consulta de estado hace el login completo contra la DGII (semilla + firma):
// no bajar mucho de aqui con 20+ comprobantes pendientes.
const DEFAULT_NOTA_POLL_SECONDS = 15;

/**
 * @param array $red Solo pruebas (tools/test_send_fase2_app.php): reemplazos de la
 *   red de la corrida, para ejercitar main() entero sin servidor.
 *   'enviar' fn(array $payload): array (el POST de emision), 'consultar'
 *   fn($api, $key, $secret, $eNcf, array $s): array (la consulta de estado),
 *   'emisor' fn($api, $key): array (GET /emisor del pre-flight de app) y
 *   'dormir' fn(int $segundos). Lo que no venga usa la red de verdad.
 */
function main(array $argv, array $red = []): int
{
    $enviar = $red['enviar'] ?? null;
    $consultar = $red['consultar'] ?? null;
    $dormir = $red['dormir'] ?? null;

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
    // Con --api-secret el destino es el tenant de integracion: otro endpoint,
    // otra auth y sin client_id/user_id (no hay DB donde vivan).
    $apiSecret = (string) ($opts['api-secret'] ?? '');
    $integracion = $apiSecret !== '';
    $clientId = (int) ($opts['client-id'] ?? DEFAULT_CLIENT_ID);
    $userId = isset($opts['user-id']) ? (int) $opts['user-id'] : null;
    $output = $opts['output'] ?? DEFAULT_OUTPUT;
    $xmlDir = $integracion ? ($opts['xml-dir'] ?? DEFAULT_XML_DIR) : null;
    // El xlsx trae TipoeCF como '32'. Aceptamos tambien 'E32' porque es como se
    // nombra el tipo en todos lados; sin esto el filtro no matchea y la corrida
    // sale con 0 casos sin decir por que.
    $filter = isset($opts['filter'])
        ? array_map(fn($t) => ltrim(trim($t), 'eE'), explode(',', $opts['filter']))
        : [];
    $casos = f2ListaCasos($opts['case'] ?? '');
    $exclude = isset($opts['exclude']) ? array_map('trim', explode(',', $opts['exclude'])) : [];
    $dryRun = isset($opts['dry-run']);
    $notaWait = isset($opts['nota-wait-accepted']) ? max(0, (int) $opts['nota-wait-accepted']) : DEFAULT_NOTA_WAIT_SECONDS;
    $notaPoll = isset($opts['nota-poll']) ? max(1, (int) $opts['nota-poll']) : DEFAULT_NOTA_POLL_SECONDS;
    // Solo app: ver f2ReintentarPendientes().
    $reintentos = isset($opts['reintentos']) ? max(0, (int) $opts['reintentos']) : DEFAULT_REINTENTOS;
    $reintentoPausa = isset($opts['reintento-pausa']) ? max(0, (int) $opts['reintento-pausa']) : DEFAULT_REINTENTO_PAUSA_SECONDS;
    // Override explicito del ambiente DGII. Sin esto el ambiente sale de
    // tenants.ambiente, y un tenant ya promovido a 'ecf' emitiria el set de
    // pruebas como facturas fiscales REALES en produccion.
    $ambiente = isset($opts['ambiente']) ? trim((string) $opts['ambiente']) : null;

    if (!$dryRun && $apiKey === '') {
        fwrite(STDERR, "ERROR: --api-key es requerido (o usa --dry-run para inspeccionar payloads).\n");
        return 2;
    }
    // En integracion el servidor FUERZA el ambiente desde tenants.ambiente y
    // ningun campo del body lo cambia. Aceptar --ambiente aqui daria una falsa
    // sensacion de seguridad: el set saldria en produccion sin avisar.
    if ($integracion && $ambiente !== null && $ambiente !== '') {
        fwrite(STDERR, "ERROR: --ambiente no aplica en modo integracion (el servidor lo toma de tenants.ambiente).\n"
            . "   Verifica antes: SELECT ambiente FROM tenants WHERE id=<id>;  -> tiene que decir 'certecf'.\n");
        return 2;
    }
    if ($integracion) {
        fwrite(STDOUT, "==> Modo INTEGRACION: POST {$apiBase}/integracion/ecf (e-NCF del xlsx, ambiente del tenant)\n");
    }

    fwrite(STDOUT, "==> Leyendo xlsx: {$opts['xlsx']}\n");
    $reader = new Fase2XlsxReader($opts['xlsx']);
    $rows = $reader->readSheet('ECF');
    fwrite(STDOUT, "==> " . count($rows) . " casos en hoja ECF\n");
    // Lo que no son notas en TODO el set (antes de --filter/--case/--exclude):
    // en una corrida parcial la espera los consulta igual, porque un rechazo de
    // una corrida anterior pudo haber reiniciado el set.
    $baseDelSet = array_values(array_filter(array_map(
        fn($r) => empty($r['NCFModificado']) ? (string) ($r['ENCF'] ?? '') : '',
        $rows
    )));

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
    if ($casos !== []) {
        $rows = array_values(array_filter($rows, fn($r) => in_array(strtoupper(trim((string) ($r['ENCF'] ?? ''))), $casos, true)));
        fwrite(STDOUT, "==> Filtro caso: " . implode(',', $casos) . " -> " . count($rows) . " casos\n");
        $noEstan = array_values(array_diff($casos, array_map(fn($r) => strtoupper(trim((string) ($r['ENCF'] ?? ''))), $rows)));
        if ($noEstan !== []) {
            fwrite(STDOUT, "    ! No estan en el set (o los quito otro filtro): " . implode(',', $noEstan) . "\n");
        }
    }
    if ($exclude) {
        $rows = array_values(array_filter($rows, fn($r) => !in_array((string)($r['ENCF'] ?? ''), $exclude, true)));
        fwrite(STDOUT, "==> Excluye: " . implode(',', $exclude) . " -> " . count($rows) . " casos\n");
    }
    $rows = sortRowsByReference($rows);
    $notas = array_values(array_filter($rows, fn($r) => !empty($r['NCFModificado'])));

    // Integracion: antes de enviar nada, el set tiene que ser de la empresa
    // elegida y esa empresa estar en certecf. Con credencial de grupo el
    // RNCEmisor elige la empresa, y cada una tiene su propio ambiente.
    if ($integracion && $rows !== [] && !certEmpresaPreflightCli([
        'api' => $apiBase,
        'api_key' => $apiKey,
        'api_secret' => $apiSecret,
        'rncs' => certEmpresaRncsDeFilas($rows, 'RNCEmisor')['rncs'],
        'origen' => 'RNCEmisor de la hoja ECF',
        'dry_run' => $dryRun,
    ] + certEmpresaOpciones($opts))) {
        return 2;
    }
    // App: la misma idea contra la empresa del token (GET /emisor). El emisor del
    // xlsx viaja en cada payload, asi que el set de otra empresa saldria con un
    // RNCEmisor ajeno firmado con el certificado de esta.
    if (!$integracion && $rows !== [] && !certEmpresaPreflightAppCli([
        'api' => $apiBase,
        'api_key' => $apiKey,
        'rncs' => certEmpresaRncsDeFilas($rows, 'RNCEmisor')['rncs'],
        'origen' => 'RNCEmisor de la hoja ECF',
        'ambiente' => $ambiente,
        'dry_run' => $dryRun,
        'permitir_produccion' => certEmpresaOpciones($opts)['permitir_produccion'],
        'consultar' => $red['emisor'] ?? null,
    ])) {
        return 2;
    }

    $results = [];
    // Solo app: payload de cada envio (indice de $results => payload), para que
    // f2ReintentarPendientes() pueda mandarlo otra vez.
    $payloads = [];
    $reintentar = static function () use ($integracion, $dryRun, $reintentos, $reintentoPausa, $apiBase, $apiKey, &$results, &$payloads, $enviar, $consultar, $dormir): void {
        if (!$integracion && !$dryRun && $reintentos > 0) {
            f2ReintentarPendientes($apiBase, $apiKey, $results, $payloads, $reintentos, $reintentoPausa, $enviar, $consultar, $dormir);
        }
    };
    // Las notas van al final (sortRowsByReference). Antes de la primera se
    // espera a la DGII: ver esperarDgiiAntesDeNotas().
    $esperaHecha = false;
    $espera = ['general' => null, 'originales' => [], 'rechazos' => 0];
    foreach ($rows as $idx => $row) {
        $caso = $row['CasoPrueba'] ?? ('row' . ($idx + 2));
        $tipo = (string) ($row['TipoeCF'] ?? '');
        $encf = (string) ($row['ENCF'] ?? '');
        $esNota = !empty($row['NCFModificado']);

        if ($esNota && !$esperaHecha && !$dryRun) {
            $esperaHecha = true;
            // App: lo que quedo sin confirmar o sin enviar se resuelve antes de
            // consultar a la DGII y de mandar las notas.
            $reintentar();
            if ($notaWait > 0) {
                $espera = esperarDgiiAntesDeNotas($apiBase, $apiKey, $apiSecret, $results, $notas, $notaWait, $notaPoll, $consultar, $dormir, $baseDelSet);
            } else {
                fwrite(STDOUT, "\n==> --nota-wait-accepted=0: las notas salen sin esperar a la DGII.\n");
            }
        }

        fwrite(STDOUT, sprintf("\n[%d/%d] %s (E%s, %s)\n", $idx + 1, count($rows), $caso, $tipo, $encf));

        $bloqueo = $esNota
            ? ($espera['general'] ?? $espera['originales'][(string) $row['NCFModificado']] ?? null)
            : null;
        if ($bloqueo !== null) {
            fwrite(STDOUT, "    SKIP nota no enviada: $bloqueo\n");
            $results[] = [
                'caso' => $caso,
                'tipo_ecf' => $tipo,
                'e_ncf' => $encf,
                'ok' => false,
                'skipped' => true,
                'error' => 'nota no enviada: ' . $bloqueo,
            ];
            continue;
        }

        try {
            $rfceRow = $rfceMap[$row['ENCF'] ?? ''] ?? null;
            $payload = mapRowToPayload($row, $clientId, $userId, $rfceRow, $integracion);
            if ($ambiente !== null && $ambiente !== '') {
                $payload['ambiente'] = $ambiente;
            }
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

        $response = $enviar !== null ? $enviar($payload) : postFactura($apiBase, $apiKey, $payload, $apiSecret);
        $entry = f2ArmarEntrada([
            'caso' => $caso,
            'tipo_ecf' => $tipo,
            'e_ncf' => $encf,
            // Con credencial de grupo, la consulta de estado tiene que ir con el RNC
            // de la empresa que emitio (?rnc=), no con el de la duena de la credencial.
            'rnc_emisor' => (string) ($payload['emisor']['rnc'] ?? ''),
        ], $response, $integracion, $xmlDir);
        f2ImprimirEntrada($entry, !$integracion && $reintentos > 0);
        $results[] = $entry;
        if (!$integracion) {
            $payloads[array_key_last($results)] = $payload;
        }
    }
    // Lo que siga pendiente despues del ultimo envio: las notas, o toda la
    // corrida si no llevaba notas (--filter=32, --case=...).
    $reintentar();

    file_put_contents($output, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    printSummary($results);
    if (!$integracion && !$dryRun) {
        fwrite(STDOUT, f2QueFalta($results, (int) ($espera['rechazos'] ?? 0)));
    }
    fwrite(STDOUT, "\n==> Reporte guardado en: $output\n");
    // 1 = algo no paso: nuestro API no lo emitio, la DGII lo rechazo o no lo
    // recibio, o quedaron notas sin enviar. El mismo criterio que el resumen.
    return count(array_filter($results, fn($r) => f2FilaFallo($r))) > 0 ? 1 : 0;
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

/** --case: uno o varios e-NCF separados por coma, espacio o punto y coma. En mayusculas y sin repetir. */
function f2ListaCasos($valor): array
{
    if (!is_string($valor)) {
        return [];
    }
    $partes = preg_split('/[\s,;]+/', strtoupper(trim($valor)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return array_values(array_unique($partes));
}

/**
 * Fila del reporte para un envio: lo que mando el runner ($base: caso, tipo_ecf,
 * e_ncf, rnc_emisor) mas lo que contesto el API. Integracion: el XML firmado
 * solo viene en esta respuesta; se guarda aparte y se saca del reporte, que si
 * no queda inmanejable.
 *
 * App: dos fallos del 502 se dicen con sus palabras (f2ClaseEnvio), porque el
 * texto del API esta escrito para la pantalla de facturacion ("No la emitas de
 * nuevo") y aqui es al reves: el set tiene que volver a mandar ese e-NCF. El
 * texto original queda en response.error.
 */
function f2ArmarEntrada(array $base, array $response, bool $integracion, ?string $xmlDir): array
{
    $http = (int) ($response['http_status'] ?? 0);
    $body = is_array($response['body'] ?? null) ? $response['body'] : [];
    $entry = $base + [
        'http_status' => $http,
        'ok' => $http >= 200 && $http < 300 && (($body['status'] ?? false) === true),
        'response' => $body,
    ];
    if (!empty($response['sin_json'])) {
        $entry['sin_json'] = true;
    }

    if (!$entry['ok']) {
        $entry['error'] = $body['error'] ?? ('HTTP ' . $http);
        if (!$integracion) {
            $clase = f2ClaseEnvio($entry);
            if ($clase === 'incierto') {
                $entry['error'] = 'sin confirmar: la DGII no respondio a tiempo y no se sabe si lo recibio (factura_id '
                    . (int) ($body['data']['factura_id'] ?? 0) . ')';
            } elseif ($clase === 'no_enviado') {
                $entry['error'] = 'no enviado (nada salio hacia la DGII): ' . $entry['error'];
            }
        }
        return $entry;
    }

    $data = is_array($body['data'] ?? null) ? $body['data'] : [];
    if ($integracion) {
        $entry['xml_file'] = guardarXmlFirmado($xmlDir, (string) ($base['e_ncf'] ?? ''), $data['xml_firmado'] ?? null);
        unset($body['data']['xml_firmado']);
        $entry['response'] = $body;
    }
    $entry['factura_id'] = $data['factura_id'] ?? null;
    $entry['track_id'] = $data['track_id'] ?? null;
    // Los RFCE (E32 <250k) no traen track_id: su estado se consulta con esto.
    $entry['codigo_seguridad'] = $data['codigo_seguridad'] ?? null;
    $entry['rfce_track_id'] = $data['rfce_track_id'] ?? null;
    $entry['estado_dgii'] = $data['estado_dgii'] ?? $data['estado'] ?? null;
    $entry['flujo'] = $data['flujo'] ?? null;
    return $entry;
}

/** Lo que dice el runner de un envio. $seReintenta (solo app): avisa que el caso vuelve a intentarse. */
function f2ImprimirEntrada(array $entry, bool $seReintenta = false): void
{
    if ($entry['ok'] ?? false) {
        fwrite(STDOUT, sprintf(
            "    OK  estado=%s track=%s rfce=%s flujo=%s\n",
            $entry['estado_dgii'] ?? '?',
            $entry['track_id'] ?? '-',
            $entry['rfce_track_id'] ?? '-',
            $entry['flujo'] ?? '-'
        ));
        if (!empty($entry['xml_file'])) {
            fwrite(STDOUT, "    XML " . $entry['xml_file'] . "\n");
        }
    } else {
        fwrite(STDOUT, "    FAIL " . ($entry['error'] ?? '?') . "\n");
    }
    if ($seReintenta && in_array(f2ClaseEnvio($entry), F2_CLASES_REINTENTABLES, true)) {
        fwrite(STDOUT, "         (no quedo ni emitido ni rechazado: se vuelve a intentar mas adelante en esta corrida)\n");
    }
}

/**
 * Que paso con un envio en modo APP (POST /api/facturas), segun lo que ese
 * endpoint garantiza (facturaController::emitirFacturaEcf):
 *
 *   ok           2xx: nuestro API lo emitio (estado_dgii dice que contesto la DGII)
 *   no_recibido  la DGII contesto sin veredicto (ERROR, RFCE_ERROR, NO_ENCONTRADO): no lo tomo
 *   rechazado    422 con estado RECHAZADO: la DGII lo rechazo en el acto (eso reinicia el set)
 *   incierto     502 con factura_id: la fila quedo guardada (ENVIO_INCIERTO) y no se
 *                sabe si la DGII lo recibio: no contesto a tiempo
 *   no_enviado   502 sin fila: fallo antes de guardar nada (token de la DGII, numero
 *                ocupado, firma...); el API garantiza que nada salio hacia la DGII
 *   validacion   otro 4xx: nuestro API no lo acepto (cliente, datos, token). Mandarlo
 *                igual otra vez no cambia nada
 *   sin_enviar   la fila nunca se mando (fallo el mapeo del xlsx, o es una nota frenada)
 *   desconocido  red caida, otro 5xx o una respuesta que no es JSON: no se sabe que paso
 *
 * No vale para integracion: /api/integracion/ecf responde 502 a cualquier fallo,
 * sin decir si hubo envio.
 */
function f2ClaseEnvio(array $r): string
{
    $estadoDe = static fn(array $data): string
        => (string) preg_replace('/^RFCE_/', '', f2NormalizarEstado((string) ($data['estado_dgii'] ?? $data['estado'] ?? '')));

    if ($r['ok'] ?? false) {
        return in_array($estadoDe($r), ['ERROR', 'NO_ENCONTRADO'], true) ? 'no_recibido' : 'ok';
    }
    if (!array_key_exists('http_status', $r)) {
        return 'sin_enviar';
    }
    $http = (int) $r['http_status'];
    if ($http === 0 || !empty($r['sin_json'])) {
        return 'desconocido';
    }
    $data = is_array($r['response']['data'] ?? null) ? $r['response']['data'] : [];
    if ($http === 502) {
        return (int) ($data['factura_id'] ?? 0) > 0 ? 'incierto' : 'no_enviado';
    }
    if ($http >= 400 && $http < 500) {
        $estado = $estadoDe($data);
        if ($estado === 'RECHAZADO') {
            return 'rechazado';
        }
        return in_array($estado, ['ERROR', 'NO_ENCONTRADO'], true) ? 'no_recibido' : 'validacion';
    }
    return 'desconocido';
}

/**
 * Solo modo APP. Vuelve sobre lo que no quedo ni emitido ni rechazado, antes de
 * las notas y al final de la corrida. Un timeout de la DGII ya no deja el set a
 * medias: el 2026-11-12 tres RFCE sin respuesta de RecepcionFC frenaron las
 * notas, y cada corrida completa posterior reenvio lo ya aceptado (duplicados
 * que la DGII rechaza, y un rechazo reinicia el set).
 *
 *  - incierto: primero se pregunta su estado (GET /facturas/{id}/estado). Para
 *    el RFCE de un E32 < 250k el API le pregunta a la DGII por e-NCF + codigo de
 *    seguridad (facturaController::esRfceInciertoDeCertificacion):
 *      . la DGII ya lo tiene (aceptado o en proceso): se toma ese estado y NO se
 *        reenvia (seria un duplicado);
 *      . lo rechazo: queda como rechazo del envio (reinicia el set);
 *      . no lo tiene, o no se pudo saber (un e-CF completo no se puede consultar
 *        en certecf sin trackId; la consulta fallo): se manda de nuevo. La
 *        reserva del set archiva el intento anterior (facturaModel::reservarFacturaECF).
 *  - no_enviado y no_recibido: se manda de nuevo.
 *
 * Cada caso se manda como mucho 1 + $reintentos veces en toda la corrida
 * ('intentos' en su fila del reporte). Un no_enviado que repite el mismo error no
 * es pasajero (certificado, numero ocupado por otra corrida): no se insiste.
 *
 * @param array<int,array> $payloads indice de $results => payload enviado
 * $enviar / $consultar / $dormir solo los cambia tools/test_send_fase2_espera.php (sin red).
 * @return int casos que quedaron resueltos (emitidos, o que la DGII ya tenia)
 */
function f2ReintentarPendientes(string $apiBase, string $apiKey, array &$results, array $payloads, int $reintentos, int $pausa, ?callable $enviar = null, ?callable $consultar = null, ?callable $dormir = null): int
{
    $enviar = $enviar ?? static fn(array $payload): array => postFactura($apiBase, $apiKey, $payload);
    $consultar = $consultar ?? 'f2ConsultarEstado';
    $dormir = $dormir ?? 'sleep';
    $identidad = array_flip(['caso', 'tipo_ecf', 'e_ncf', 'rnc_emisor']);
    $resueltos = 0;

    for ($ronda = 1; $ronda <= $reintentos; $ronda++) {
        $pendientes = [];
        foreach ($results as $i => $r) {
            if (!isset($payloads[$i]) || !empty($r['sin_reintento']) || (int) ($r['intentos'] ?? 1) > $reintentos) {
                continue;
            }
            $clase = f2ClaseEnvio($r);
            if (in_array($clase, F2_CLASES_REINTENTABLES, true)) {
                $pendientes[$i] = $clase;
            }
        }
        if ($pendientes === []) {
            break;
        }
        fwrite(STDOUT, sprintf(
            "\n==> Reintento %d: %d comprobante(s) sin confirmar o sin enviar%s\n",
            $ronda, count($pendientes), $pausa > 0 ? " (pausa de {$pausa}s para darle tiempo a la DGII)" : ''
        ));
        if ($pausa > 0) {
            $dormir($pausa);
        }

        foreach ($pendientes as $i => $clase) {
            $r = $results[$i];
            $encf = (string) ($r['e_ncf'] ?? '');
            fwrite(STDOUT, "  $encf\n");

            if ($clase === 'incierto') {
                $facturaId = (int) ($r['response']['data']['factura_id'] ?? 0);
                $resp = $consultar($apiBase, $apiKey, '', $encf, ['factura_id' => $facturaId, 'track_id' => null, 'codigo_seguridad' => null]);
                $estado = f2EstadoDeRespuesta($resp);
                $sinPrefijo = (string) preg_replace('/^RFCE_/', '', $estado);
                $data = is_array($resp['body']['data'] ?? null) ? $resp['body']['data'] : [];

                if (f2ClaseEstado($estado) === 'aceptado' || in_array($sinPrefijo, ['EN_PROCESO', 'ENVIADO'], true)) {
                    // La DGII ya lo tiene: reenviarlo seria un duplicado.
                    $results[$i] = array_intersect_key($r, $identidad) + [
                        'http_status' => 200,
                        'ok' => true,
                        'response' => $resp['body'],
                        'factura_id' => $facturaId,
                        'track_id' => $data['track_id'] ?? null,
                        'codigo_seguridad' => null,
                        'rfce_track_id' => null,
                        'estado_dgii' => $estado,
                        'flujo' => $data['flujo'] ?? null,
                        'intentos' => (int) ($r['intentos'] ?? 1),
                        'resuelto' => 'consulta',
                    ];
                    fwrite(STDOUT, "    OK  la DGII ya lo tenia (estado=$estado): no se reenvia\n");
                    $resueltos++;
                    continue;
                }
                if (f2ClaseEstado($estado) === 'rechazado') {
                    // El envio si llego y la DGII lo rechazo: es un rechazo como
                    // cualquier otro (esperarDgiiAntesDeNotas lo lee de response.data).
                    $motivo = f2MensajesDeRespuesta($resp);
                    $results[$i] = array_merge($r, [
                        'http_status' => 422,
                        'response' => ['status' => false, 'data' => ['factura_id' => $facturaId, 'estado_dgii' => $estado, 'consulta' => $data['consulta'] ?? null]],
                        'error' => 'la DGII rechazo el envio que habia quedado sin confirmar' . ($motivo !== '' ? ': ' . $motivo : ''),
                        'sin_reintento' => true,
                    ]);
                    fwrite(STDOUT, "    FAIL " . $results[$i]['error'] . "\n");
                    continue;
                }
                fwrite(STDOUT, '    la DGII no lo tiene o no se pudo saber ('
                    . ($estado !== '' ? $estado : f2ErrorDeConsulta($resp)) . "): se envia de nuevo\n");
            }

            $nuevo = f2ArmarEntrada(array_intersect_key($r, $identidad), $enviar($payloads[$i]), false, null);
            $nuevo['intentos'] = (int) ($r['intentos'] ?? 1) + 1;
            $claseNueva = f2ClaseEnvio($nuevo);
            if ($clase === 'no_enviado' && $claseNueva === 'no_enviado'
                && (string) ($nuevo['response']['error'] ?? '') === (string) ($r['response']['error'] ?? '')) {
                // El mismo fallo dos veces no es pasajero: no se insiste.
                $nuevo['sin_reintento'] = true;
            }
            $results[$i] = $nuevo;
            f2ImprimirEntrada($nuevo);
            if ($claseNueva === 'ok') {
                $resueltos++;
            }
        }
    }
    return $resueltos;
}

/**
 * Solo modo APP, al final de la corrida: que hacer con lo que no paso. Sin
 * rechazos, lo que falta se manda solo (--case): reenviar el set entero mandaria
 * otra vez lo que la DGII ya acepto, y ese duplicado sale rechazado. Con un
 * rechazo el set ya esta reiniciado y hay que correrlo completo.
 *
 * @return string el texto a imprimir ('' si no falta nada). Pura: no escribe.
 */
function f2QueFalta(array $results, int $rechazosEnLaEspera = 0): string
{
    $faltan = [];
    $huboRechazo = $rechazosEnLaEspera > 0;
    foreach ($results as $r) {
        // Tambien lo que la DGII no tomo (RFCE_ERROR...) aunque nuestro API haya
        // respondido 200: sin notas en la corrida nadie mas lo marca como fallo.
        if (!empty($r['dry_run']) || !(f2FilaFallo($r) || in_array(f2ClaseEnvio($r), F2_CLASES_REINTENTABLES, true))) {
            continue;
        }
        $encf = strtoupper(trim((string) ($r['e_ncf'] ?? '')));
        if ($encf !== '') {
            $faltan[$encf] = true;
        }
        if (f2ClaseEnvio($r) === 'rechazado'
            || f2ClaseEstado((string) ($r['estado_dgii_final'] ?? $r['estado_dgii'] ?? '')) === 'rechazado') {
            $huboRechazo = true;
        }
    }
    if ($faltan === []) {
        return '';
    }
    if ($huboRechazo) {
        return "\n==> La DGII rechazo algo y eso reinicia el set: corrige el motivo del rechazo y corre la fase COMPLETA"
            . " de nuevo, con el mismo xlsx.\n"
            . "    Si el motivo es que la DGII ya habia recibido ese comprobante (un duplicado de una corrida anterior), no hay"
            . " nada que corregir: solo vuelve a correrla.\n";
    }
    return "\n==> Faltan " . count($faltan) . " comprobante(s) y la DGII no rechazo nada. Para completar el set SIN reenviar"
        . " lo que ya tiene,\n    corre de nuevo solo estos (campo \"Solo estos e-NCF\" de cert.html, o --case=...):\n    "
        . implode(',', array_keys($faltan)) . "\n";
}

/**
 * Mapea una fila del xlsx a un payload del POST /api/facturas.
 * Toma la mayoria de campos del comprador y emisor del xlsx (DGII te dice
 * que valores usar). El client_id solo se usa para satisfacer la API actual
 * que requiere un cliente existente; los datos reales vienen del row.
 *
 * En modo integracion (POST /api/integracion/ecf) no hay clientes ni usuarios
 * que referenciar, y el e-NCF es obligatorio en el body.
 */
function mapRowToPayload(array $row, int $clientId, ?int $userId, ?array $rfceRow = null, bool $integracion = false): array
{
    $tipoEcf = (string) ($row['TipoeCF'] ?? '');
    if (!preg_match('/^(31|32|33|34|41|43|44|45|46|47)$/', $tipoEcf)) {
        throw new RuntimeException('TipoeCF invalido: ' . $tipoEcf);
    }
    if ($integracion && empty($row['ENCF'])) {
        throw new RuntimeException('La fila no trae ENCF y en integracion el e-NCF lo asigna el cliente (el servidor no dispensa).');
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
        // La del set manda (DGII la compara); vacia -> default del server (31-12 del ano siguiente).
        'fecha_vencimiento_secuencia' => blankToNull($row['FechaVencimientoSecuencia'] ?? null),
        // Sin blankToNull: el '' del set tiene que llegar al builder (lo omite en
        // las notas 33/34); un null lo vuelve '01' en el controlador y el servicio.
        'tipo_ingresos' => $row['TipoIngresos'] ?? '01',
        'tipo_pago' => blankToNull($row['TipoPago'] ?? null),
        'fecha_limite_pago' => blankToNull($row['FechaLimitePago'] ?? null),
        'termino_pago' => blankToNull($row['TerminoPago'] ?? null),
        'tipo_cuenta_pago' => blankToNull($row['TipoCuentaPago'] ?? null),
        'numero_cuenta_pago' => blankToNull($row['NumeroCuentaPago'] ?? null),
        'banco_pago' => blankToNull($row['BancoPago'] ?? null),
        'fecha_desde' => blankToNull($row['FechaDesde'] ?? null),
        'fecha_hasta' => blankToNull($row['FechaHasta'] ?? null),
        'total_paginas' => blankToNull($row['TotalPaginas'] ?? null),
        'indicador_monto_gravado' => blankToNull($row['IndicadorMontoGravado'] ?? null),
        'indicador_nota_credito' => blankToNull($row['IndicadorNotaCredito'] ?? null),
        'strict_input' => true,
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
            'identificador_extranjero' => $row['IdentificadorExtranjero'] ?? null,
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
        'totales' => extractTotales($row),
    ];
    $descuentosORecargos = extractDescuentosORecargos($row);
    if ($descuentosORecargos !== []) {
        $payload['descuentos_o_recargos'] = $descuentosORecargos;
    }
    if ($userId !== null) {
        $payload['user_id'] = $userId;
    }
    if ($integracion) {
        unset($payload['client_id'], $payload['user_id']);
    }

    if (!empty($row['NCFModificado'])) {
        $payload['informacion_referencia'] = [
            'ncf_modificado' => $row['NCFModificado'],
            'rnc_otro_contribuyente' => blankToNull($row['RNCOtroContribuyente'] ?? null),
            'fecha_ncf_modificado' => $row['FechaNCFModificado'] ?? null,
            'codigo_modificacion' => $row['CodigoModificacion'] ?? null,
            'razon_modificacion' => blankToNull($row['RazonModificacion'] ?? null),
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
            'indicador_agente_retencion_percepcion' => $row["IndicadorAgenteRetencionoPercepcion[$i]"] ?? null,
            'monto_itbis_retenido' => $row["MontoITBISRetenido[$i]"] ?? null,
            'monto_isr_retenido' => $row["MontoISRRetenido[$i]"] ?? null,
            'nombre_item' => $nombre,
            'indicador_bien_servicio' => (int) ($row["IndicadorBienoServicio[$i]"] ?? 2),
            'descripcion' => $row["DescripcionItem[$i]"] ?? '',
            'cantidad' => intIfWhole($row["CantidadItem[$i]"] ?? 1),
            'cantidad_raw' => $row["CantidadItem[$i]"] ?? null,
            'unidad_medida' => $row["UnidadMedida[$i]"] ?? '',
            'cantidad_referencia' => blankToNull($row["CantidadReferencia[$i]"] ?? null),
            'unidad_referencia' => blankToNull($row["UnidadReferencia[$i]"] ?? null),
            'subcantidades' => extractNestedRows($row, 'Subcantidad', $i, [
                'Subcantidad' => 'subcantidad',
                'CodigoSubcantidad' => 'codigo_subcantidad',
            ]),
            'grados_alcohol' => blankToNull($row["GradosAlcohol[$i]"] ?? null),
            'precio_unitario_referencia' => blankToNull($row["PrecioUnitarioReferencia[$i]"] ?? null),
            'fecha_elaboracion' => blankToNull($row["FechaElaboracion[$i]"] ?? null),
            'fecha_vencimiento_item' => blankToNull($row["FechaVencimientoItem[$i]"] ?? null),
            'precio_unitario' => (float) ($row["PrecioUnitarioItem[$i]"] ?? 0),
            'precio_unitario_raw' => $row["PrecioUnitarioItem[$i]"] ?? null,
            'descuento_monto' => blankToNull($row["DescuentoMonto[$i]"] ?? null),
            'subdescuentos' => extractNestedRows($row, 'SubDescuento', $i, [
                'TipoSubDescuento' => 'tipo_sub_descuento',
                'SubDescuentoPorcentaje' => 'sub_descuento_porcentaje',
                'MontoSubDescuento' => 'monto_sub_descuento',
            ]),
            'recargo_monto' => blankToNull($row["RecargoMonto[$i]"] ?? null),
            'subrecargos' => extractNestedRows($row, 'SubRecargo', $i, [
                'TipoSubRecargo' => 'tipo_sub_recargo',
                'SubRecargoPorcentaje' => 'sub_recargo_porcentaje',
                'MontosubRecargo' => 'monto_sub_recargo',
                'MontoSubRecargo' => 'monto_sub_recargo',
            ]),
            'impuestos_adicionales' => extractNestedRows($row, 'ImpuestoAdicional', $i, [
                'TipoImpuesto' => 'tipo_impuesto',
            ]),
            'monto_item' => isset($row["MontoItem[$i]"]) && $row["MontoItem[$i]"] !== '' ? (float) $row["MontoItem[$i]"] : null,
            'monto_item_raw' => $row["MontoItem[$i]"] ?? null,
        ];
    }
    return $items;
}

function sortRowsByReference(array $rows): array
{
    $indexed = [];
    foreach ($rows as $idx => $row) {
        $indexed[] = ['idx' => $idx, 'row' => $row];
    }

    usort($indexed, function ($a, $b) {
        $aHasRef = !empty($a['row']['NCFModificado']);
        $bHasRef = !empty($b['row']['NCFModificado']);
        if ($aHasRef !== $bHasRef) {
            return $aHasRef <=> $bHasRef;
        }
        return $a['idx'] <=> $b['idx'];
    });

    return array_map(fn($entry) => $entry['row'], $indexed);
}

/**
 * Seccion DescuentosORecargos del e-CF: descuentos o recargos GLOBALES del
 * documento (no por linea). Columnas NumeroLineaDoR[i], TipoAjuste[i]... del set
 * (CAGLIARI 2026-10-06: E310000000004 y E320000000004; los sets de Gratex no la
 * traian). Texto crudo: la DGII lo compara contra su set. Los totales del set ya
 * la incluyen. Hasta 20 (XSD).
 */
function extractDescuentosORecargos(array $row): array
{
    $map = [
        'NumeroLineaDoR' => 'numero_linea',
        'TipoAjuste' => 'tipo_ajuste',
        'IndicadorNorma1007' => 'indicador_norma_1007',
        'DescripcionDescuentooRecargo' => 'descripcion',
        'TipoValor' => 'tipo_valor',
        'ValorDescuentooRecargo' => 'valor',
        'MontoDescuentooRecargo' => 'monto',
        'MontoDescuentooRecargoOtraMoneda' => 'monto_otra_moneda',
        'IndicadorFacturacionDescuentooRecargo' => 'indicador_facturacion',
    ];
    $entradas = [];
    for ($i = 1; $i <= 20; $i++) {
        if (blankToNull($row["NumeroLineaDoR[$i]"] ?? null) === null) {
            break;
        }
        $entrada = [];
        foreach ($map as $columna => $clave) {
            $valor = blankToNull($row[$columna . '[' . $i . ']'] ?? null);
            if ($valor !== null) {
                $entrada[$clave] = $valor;
            }
        }
        $entradas[] = $entrada;
    }
    return $entradas;
}

function extractNestedRows(array $row, string $group, int $line, array $fieldMap): array
{
    $items = [];
    for ($j = 1; $j <= 12; $j++) {
        $entry = [];
        foreach ($fieldMap as $xlsxBase => $payloadKey) {
            $value = blankToNull($row[$xlsxBase . '[' . $line . '][' . $j . ']'] ?? null);
            if ($value !== null) {
                $entry[$payloadKey] = $value;
            }
        }
        if ($entry === []) {
            if ($j === 1) {
                continue;
            }
            break;
        }
        $items[] = $entry;
        if ($group === 'ImpuestoAdicional' && $j >= 2) {
            break;
        }
    }
    return $items;
}

function extractTotales(array $row): array
{
    $map = [
        'MontoGravadoTotal' => 'monto_gravado_total',
        'MontoGravadoI1' => 'monto_gravado_i1',
        'MontoGravadoI2' => 'monto_gravado_i2',
        'MontoGravadoI3' => 'monto_gravado_i3',
        'MontoExento' => 'monto_exento',
        'ITBIS1' => 'itbis1',
        'ITBIS2' => 'itbis2',
        'ITBIS3' => 'itbis3',
        'TotalITBIS' => 'total_itbis',
        'TotalITBIS1' => 'total_itbis1',
        'TotalITBIS2' => 'total_itbis2',
        'TotalITBIS3' => 'total_itbis3',
        'MontoTotal' => 'monto_total',
        'MontoNoFacturable' => 'monto_no_facturable',
        'MontoPeriodo' => 'monto_periodo',
        'SaldoAnterior' => 'saldo_anterior',
        'MontoAvancePago' => 'monto_avance_pago',
        'ValorPagar' => 'valor_pagar',
        'TotalITBISRetenido' => 'total_itbis_retenido',
        'TotalISRRetencion' => 'total_isr_retencion',
        'TotalITBISPercepcion' => 'total_itbis_percepcion',
        'TotalISRPercepcion' => 'total_isr_percepcion',
        'MontoImpuestoAdicional' => 'monto_impuesto_adicional',
    ];

    $totales = [];
    foreach ($map as $xlsxKey => $payloadKey) {
        if (isset($row[$xlsxKey]) && $row[$xlsxKey] !== '') {
            $totales[$payloadKey] = (float) $row[$xlsxKey];
        }
    }
    $impuestosAdicionales = [];
    for ($i = 1; $i <= 20; $i++) {
        $tipo = blankToNull($row["TipoImpuesto[$i]"] ?? null);
        if ($tipo === null) {
            if ($i === 1) {
                continue;
            }
            break;
        }
        $impuestosAdicionales[] = [
            'tipo_impuesto' => $tipo,
            'tasa_impuesto_adicional' => intIfWhole($row["TasaImpuestoAdicional[$i]"] ?? null),
            'monto_impuesto_selectivo_consumo_especifico' => blankToNull($row["MontoImpuestoSelectivoConsumoEspecifico[$i]"] ?? null),
            'monto_impuesto_selectivo_consumo_advalorem' => blankToNull($row["MontoImpuestoSelectivoConsumoAdvalorem[$i]"] ?? null),
            'otros_impuestos_adicionales' => blankToNull($row["OtrosImpuestosAdicionales[$i]"] ?? null),
        ];
    }
    if ($impuestosAdicionales !== []) {
        $totales['impuestos_adicionales'] = $impuestosAdicionales;
    }
    return $totales;
}

function blankToNull($value)
{
    return $value === '' ? null : $value;
}

function intIfWhole($value)
{
    if ($value === null || $value === '') {
        return null;
    }
    $f = (float) $value;
    return (floor($f) == $f) ? (int) $f : $f;
}

/**
 * Guarda el XML firmado que devuelve /api/integracion/ecf. Ese tenant no tiene
 * DB ni endpoint para volver a bajarlo, asi que si no se guarda aqui la unica
 * copia queda en master.ecf_integracion_backup (lado servidor).
 * Devuelve la ruta escrita, o null si no vino XML / no se pudo escribir.
 */
function guardarXmlFirmado(?string $dir, string $eNcf, ?string $xml): ?string
{
    if ($dir === null || $xml === null || $xml === '') {
        return null;
    }
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        fwrite(STDOUT, "    ! No se pudo crear el directorio de XML: $dir\n");
        return null;
    }
    $safe = preg_replace('/[^A-Za-z0-9_-]/', '_', $eNcf !== '' ? $eNcf : ('ecf_' . date('His')));
    $path = rtrim($dir, "/\\") . DIRECTORY_SEPARATOR . $safe . '.xml';
    if (file_put_contents($path, $xml) === false) {
        fwrite(STDOUT, "    ! No se pudo escribir el XML: $path\n");
        return null;
    }
    return $path;
}

function postFactura(string $apiBase, string $apiKey, array $payload, string $apiSecret = ''): array
{
    $integracion = $apiSecret !== '';
    $url = $apiBase . ($integracion ? '/integracion/ecf' : '/facturas');
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'X-API-KEY: ' . $apiKey,
    ];
    if ($integracion) {
        $headers[] = 'X-API-SECRET: ' . $apiSecret;
    }

    $ch = curl_init($url);
    $opts = [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => F2_POST_TIMEOUT_SECONDS,
        CURLOPT_HTTPHEADER => $headers,
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
        // sin_json: lo que contesto no fue nuestro API (un 502 del proxy, una pagina
        // de error). f2ClaseEnvio no lo confunde con un 502 propio, que si dice que paso.
        return [
            'http_status' => $status,
            'body' => ['status' => false, 'error' => 'respuesta no JSON: ' . substr($raw, 0, 300)],
            'sin_json' => true,
        ];
    }
    return ['http_status' => $status, 'body' => $decoded];
}

function printSummary(array $results): void
{
    // OK = enviado y sin rechazo conocido de la DGII. 'ok' del reporte sigue
    // siendo "nuestro API lo emitio" (check_fase2_status.php lo usa asi).
    $ok = count(array_filter($results, fn($r) => !f2FilaFallo($r)));
    $fail = count($results) - $ok;
    fwrite(STDOUT, "\n========================================\n");
    fwrite(STDOUT, sprintf("Resumen: %d/%d OK, %d fallaron o no se enviaron\n", $ok, count($results), $fail));
    fwrite(STDOUT, "========================================\n");
    foreach ($results as $r) {
        // estado_dgii_final lo llena esperarDgiiAntesDeNotas(): es el estado que
        // la DGII termino dando, no el acuse del envio (casi siempre "En Proceso").
        $estado = $r['estado_dgii_final'] ?? $r['estado_dgii'] ?? '';
        $marker = f2FilaFallo($r) ? '-' : '+';
        $detail = ($r['ok'] ?? false)
            ? $estado . ' track=' . ($r['track_id'] ?? '-') . (!empty($r['rfce_track_id']) ? ' rfce=' . $r['rfce_track_id'] : '')
              . (!empty($r['no_recibido']) ? ' (la DGII no lo recibio)' : '')
              . (($r['resuelto'] ?? '') === 'consulta' ? ' (quedo sin confirmar y la DGII ya lo tenia)' : '')
              . (!empty($r['dgii_mensajes']) ? ' :: ' . $r['dgii_mensajes'] : '')
            : ($r['error'] ?? '?');
        // Solo app: cuantas veces se mando (f2ReintentarPendientes).
        if ((int) ($r['intentos'] ?? 1) > 1) {
            $detail .= ' [' . (int) $r['intentos'] . ' envios]';
        }
        fwrite(STDOUT, sprintf("  %s %s | E%s | %s | %s\n", $marker, $r['caso'], $r['tipo_ecf'], $r['e_ncf'], $detail));
    }
}

/** Una fila del reporte no paso: no se emitio, la DGII la rechazo o no la recibio, o no se envio. */
function f2FilaFallo(array $r): bool
{
    return !($r['ok'] ?? false)
        || !empty($r['no_recibido'])
        || f2ClaseEstado((string) ($r['estado_dgii_final'] ?? $r['estado_dgii'] ?? '')) === 'rechazado';
}

/**
 * Espera a que la DGII termine de procesar lo enviado antes de mandar las notas.
 *
 * Por que: la DGII procesa en diferido (el envio solo devuelve un trackId "En
 * Proceso") y cualquier rechazo REINICIA el set completo, con lo que los
 * originales ya aceptados dejan de contar. Una nota que llega despues de ese
 * reinicio, o antes de que su original termine, la DGII la rechaza con "El
 * eNCF modificado no ha sido emitido" (codigo 614) — y ese rechazo vuelve a
 * reiniciar el set. CAGLIARI, 2026-10-06: E410000000010 se rechazo a las
 * 9:00:54 y las 3 notas, mandadas segundos despues, cayeron por eso.
 *
 * Consulta TODO lo enviado en esta corrida (no solo los originales de las
 * notas): tras un reinicio, ConsultaResultado sigue diciendo ACEPTADO para los
 * originales, asi que solo el estado de todo lo demas delata el reinicio. Si la
 * DGII rechazo cualquier cosa el set ya esta reiniciado y las notas no se
 * mandan. En integracion tambien consulta los originales que no se mandaron en
 * esta corrida (p.ej. --filter=E33,E34): /integracion/estado los busca por
 * e-NCF en el respaldo del master.
 *
 * Si se agota el tiempo con algo aun en proceso, las notas SI salen (con
 * aviso): Gratex paso su fase 2 en una sola corrida, con las notas 25-27 s
 * despues de sus originales y sin esperar (abb25b4, tools/fase2_run.log).
 *
 * Actualiza $results con estado_dgii_final y dgii_mensajes.
 *
 * $consultar / $dormir solo los cambia tools/test_send_fase2_espera.php (sin red).
 * $baseDelSet: e-NCF de todo lo que no es nota en el set. En una corrida
 * parcial (integracion) los que no se mandaron ahora se consultan por e-NCF.
 *
 * @return array{general: ?string, originales: array<string,string>, rechazos: int}
 *   general    motivo para no mandar NINGUNA nota (la DGII rechazo algo), o null
 *   originales e-NCF original => motivo, para notas cuyo original no llego a la DGII
 */
function esperarDgiiAntesDeNotas(string $apiBase, string $apiKey, string $apiSecret, array &$results, array $notas, int $timeoutSeconds, int $pollSeconds, ?callable $consultar = null, ?callable $dormir = null, array $baseDelSet = []): array
{
    $consultar = $consultar ?? 'f2ConsultarEstado';
    $dormir = $dormir ?? 'sleep';
    $integracion = $apiSecret !== '';
    $out = ['general' => null, 'originales' => [], 'rechazos' => 0];

    // Ultimo resultado por e-NCF (un --case repetido no debe contar doble).
    $porEncf = [];
    foreach ($results as $i => $r) {
        if (($r['e_ncf'] ?? '') !== '') {
            $porEncf[$r['e_ncf']] = $i;
        }
    }

    // Empresa de la corrida, para lo que no se envio ahora (originales de otra
    // corrida, resto del set): el RNCEmisor del set. Con credencial de grupo sin
    // esto la consulta saldria a nombre de la duena de la credencial.
    $rncCorrida = '';
    foreach ($results as $r) {
        if (($r['rnc_emisor'] ?? '') !== '') {
            $rncCorrida = (string) $r['rnc_emisor'];
            break;
        }
    }
    if ($rncCorrida === '') {
        foreach ($notas as $n) {
            if (trim((string) ($n['RNCEmisor'] ?? '')) !== '') {
                $rncCorrida = trim((string) $n['RNCEmisor']);
                break;
            }
        }
    }

    $seguir = [];
    $rechazadosEnvio = [];
    $sinConfirmar = [];
    foreach ($porEncf as $encf => $i) {
        $r = $results[$i];
        if (!($r['ok'] ?? false)) {
            // App: si la DGII lo rechazo en el acto, el API responde 422 con
            // data.estado_dgii. Ese rechazo ya reinicio el set.
            $data = is_array($r['response']['data'] ?? null) ? $r['response']['data'] : [];
            $estado = f2NormalizarEstado((string) ($data['estado_dgii'] ?? $data['estado'] ?? ''));
            if (f2ClaseEstado($estado) === 'rechazado') {
                $rechazadosEnvio[$encf] = (string) ($r['error'] ?? '');
                $results[$i]['estado_dgii_final'] = $estado;
                continue;
            }
            // Red caida o 5xx: el server pudo haberlo mandado igual a la DGII y no
            // se sabe en que quedo. No se consulta por e-NCF: si este intento no
            // llego a guardar respaldo, el server responderia con el track_id de
            // una corrida ANTERIOR del mismo e-NCF. Sin http_status = fallo el
            // mapeo del xlsx: nunca se mando, solo frena la nota de ese original.
            $http = (int) ($r['http_status'] ?? 0);
            if (array_key_exists('http_status', $r) && ($http === 0 || $http >= 500)) {
                // App: un 502 SIN fila es un fallo anterior a la reserva y el API
                // garantiza que nada salio hacia la DGII (f2ClaseEnvio 'no_enviado'):
                // no es "no se sabe si llego"; solo frena la nota de ese original,
                // como un 4xx. Integracion no distingue: todo 5xx es incierto.
                if ($integracion || f2ClaseEnvio($r) !== 'no_enviado') {
                    $sinConfirmar[] = "$encf (HTTP $http: " . substr((string) ($r['error'] ?? '?'), 0, 120) . ')';
                }
            }
            continue;
        }
        $estado = f2NormalizarEstado((string) ($r['estado_dgii'] ?? ''));
        $clase = f2ClaseEstado($estado);
        if ($clase === 'rechazado') {
            // Integracion: la recepcion ya dijo RECHAZADO (el API responde 200 igual).
            $motivo = f2MensajesDeEnvio($r);
            if ($motivo === '' && str_starts_with($estado, 'RFCE_') && !empty($r['codigo_seguridad'])) {
                // /integracion/ecf no devuelve el cuerpo de RecepcionFC: se le
                // pregunta una vez a la DGII para tener el motivo.
                $motivo = f2MensajesDeRespuesta($consultar($apiBase, $apiKey, $apiSecret, (string) $encf, [
                    'track_id' => null, 'codigo_seguridad' => $r['codigo_seguridad'], 'factura_id' => $r['factura_id'] ?? null,
                    'rnc' => ($r['rnc_emisor'] ?? '') !== '' ? (string) $r['rnc_emisor'] : $rncCorrida,
                ]));
            }
            $rechazadosEnvio[$encf] = $motivo;
            $results[$i]['estado_dgii_final'] = $estado;
            continue;
        }
        $noRecibido = in_array($estado, ['RFCE_ERROR', 'RFCE_NO_ENCONTRADO'], true)
            || (empty($r['track_id']) && !str_starts_with($estado, 'RFCE_') && $clase !== 'aceptado');
        if ($noRecibido) {
            // ERROR / ENVIADO sin trackId fuera del flujo RFCE, o un RFCE que
            // RecepcionFC no tomo: la DGII no lo recibio y no hay nada que
            // consultar (se quedaria "en proceso" hasta agotar el tiempo). No
            // reinicia el set, pero queda incompleto y la nota de este original
            // no puede pasar.
            fwrite(STDOUT, "    ! $encf: la DGII no lo recibio (estado " . ($estado !== '' ? $estado : '?') . ', sin track_id). El set queda incompleto.' . "\n");
            $out['originales'][$encf] = "la DGII no recibio el original $encf (estado " . ($estado !== '' ? $estado : '?') . ')';
            $results[$i]['no_recibido'] = true;
            continue;
        }
        if (!$integracion && empty($r['factura_id'])) {
            fwrite(STDOUT, "    ! $encf sin factura_id: no se puede consultar su estado.\n");
            continue;
        }
        $seguir[$encf] = [
            'i' => $i,
            'rnc' => ($r['rnc_emisor'] ?? '') !== '' ? (string) $r['rnc_emisor'] : $rncCorrida,
            'track_id' => $r['track_id'] ?? null,
            'codigo_seguridad' => $r['codigo_seguridad'] ?? null,
            'factura_id' => $r['factura_id'] ?? null,
            'estado' => $estado,
            'clase' => $clase,
            'mensajes' => '',
        ];
    }

    $originales = [];
    foreach ($notas as $nota) {
        $ref = trim((string) ($nota['NCFModificado'] ?? ''));
        if ($ref !== '') {
            $originales[$ref] = true;
        }
        if ($ref === '' || isset($seguir[$ref]) || isset($out['originales'][$ref]) || isset($rechazadosEnvio[$ref])) {
            continue;
        }
        if (isset($porEncf[$ref])) {
            // Se intento en esta corrida y nuestro API no lo emitio: la nota no puede pasar.
            $out['originales'][$ref] = "el original $ref no se emitio: " . ($results[$porEncf[$ref]]['error'] ?? '?');
            continue;
        }
        if ($integracion) {
            $seguir[$ref] = ['i' => null, 'rnc' => $rncCorrida, 'track_id' => null, 'codigo_seguridad' => null, 'factura_id' => null,
                'estado' => '', 'clase' => 'pendiente', 'mensajes' => ''];
        } else {
            fwrite(STDOUT, "    ! El original $ref no esta en esta corrida: no se verifica su estado.\n");
        }
    }

    // Corrida parcial: lo que no se mando ahora tambien cuenta. Tras un reinicio
    // los originales siguen diciendo ACEPTADO; solo el rechazo de OTRA pieza lo
    // delata. Integracion lo busca por e-NCF (su ultimo envio); app no puede.
    $fuera = array_values(array_filter(array_unique($baseDelSet), fn($e) => $e !== ''
        && !isset($porEncf[$e]) && !isset($seguir[$e]) && !isset($out['originales'][$e])));
    if ($fuera !== []) {
        if ($integracion) {
            fwrite(STDOUT, "    Corrida parcial: se consulta tambien el ultimo envio de " . count($fuera)
                . " comprobantes del set que no van en esta corrida (un rechazo previo pudo reiniciarlo).\n");
            foreach ($fuera as $e) {
                $seguir[$e] = ['i' => null, 'rnc' => $rncCorrida, 'track_id' => null, 'codigo_seguridad' => null, 'factura_id' => null,
                    'estado' => '', 'clase' => 'pendiente', 'mensajes' => ''];
            }
        } else {
            fwrite(STDOUT, "    ! Corrida parcial: " . count($fuera) . " comprobantes del set no van en esta corrida y en"
                . " modo app no se pueden consultar por e-NCF. Si alguno se rechazo antes, el set ya esta reiniciado:"
                . " revisa su estado antes.\n");
        }
    }

    if ($sinConfirmar !== [] && $rechazadosEnvio === []) {
        // La "consola, paso 3" es de integracion.html; cert.html (app) no la tiene:
        // ahi lo que falta se manda con --case (main() imprime la lista al final).
        $out['general'] = 'no se sabe si llegaron a la DGII ' . implode(', ', $sinConfirmar)
            . ($integracion
                ? ': revisa su estado (consola, paso 3) y, si nada quedo rechazado, manda lo que falte y despues las notas'
                : ': cuando la DGII vuelva a responder, corre de nuevo solo lo que falta (la lista sale al final de esta corrida)');
        fwrite(STDOUT, "==> Las notas NO se envian: " . $out['general'] . ".\n");
        return $out;
    }
    if ($seguir === [] && $rechazadosEnvio === []) {
        return $out;
    }

    // Un rechazo en el propio envio ya reinicio el set: consultar el resto solo
    // gastaria logins contra la DGII.
    if ($rechazadosEnvio === []) {
        fwrite(STDOUT, sprintf(
            "\n==> Esperando a la DGII antes de las notas: %d comprobantes, hasta %ds (consulta cada %ds)\n",
            count($seguir), $timeoutSeconds, $pollSeconds
        ));
    }
    $deadline = time() + $timeoutSeconds;
    $ronda = 0;
    while ($rechazadosEnvio === [] && $seguir !== []) {
        $ronda++;
        foreach ($seguir as $encf => &$s) {
            if ($s['clase'] !== 'pendiente') {
                continue;
            }
            $resp = $consultar($apiBase, $apiKey, $apiSecret, (string) $encf, $s);
            if ($s['i'] === null && ($resp['http_status'] ?? 0) === 404) {
                $error = (string) ($resp['body']['error'] ?? 'HTTP 404');
                if (stripos($error, 'track_id') !== false && stripos($error, 'no tiene') !== false) {
                    // Hay respaldo pero sin track_id: un RFCE (E32 <250k). Sin su
                    // codigo de seguridad no se puede consultar; no frena nada.
                    $s['clase'] = 'no_verificable';
                    fwrite(STDOUT, "      $encf no verificable (RFCE sin track_id en el respaldo)\n");
                    continue;
                }
                // Sin respaldo: este tenant nunca lo emitio.
                $s['clase'] = 'sin_registro';
                if (isset($originales[$encf])) {
                    $out['originales'][$encf] = "no hay registro de que el original $encf se haya emitido: " . $error;
                } else {
                    fwrite(STDOUT, "      $encf nunca enviado: el set queda incompleto\n");
                }
                continue;
            }
            $estado = f2EstadoDeRespuesta($resp);
            // Un fallo del propio endpoint de estado (403/5xx/red) no es "en
            // proceso": se guarda para decirlo, y se reintenta en la ronda siguiente.
            $s['error'] = $estado === '' ? f2ErrorDeConsulta($resp) : '';
            if ($estado !== '') {
                $s['estado'] = $estado;
                $s['clase'] = f2ClaseEstado($estado);
            }
            if ($s['clase'] === 'rechazado') {
                $s['mensajes'] = f2MensajesDeRespuesta($resp);
            }
            // Una linea por consulta: cada una hace 3 llamadas a la DGII y por web
            // (cert_run.php) un silencio largo puede cortar la salida en vivo.
            fwrite(STDOUT, "      $encf " . ($estado !== '' ? $estado : 'sin respuesta: ' . $s['error']) . "\n");
        }
        unset($s);

        $cuenta = ['aceptado' => 0, 'pendiente' => 0, 'rechazado' => 0, 'sin_registro' => 0, 'no_verificable' => 0];
        $sinRespuesta = 0;
        foreach ($seguir as $s) {
            $cuenta[$s['clase']]++;
            if ($s['clase'] === 'pendiente' && ($s['error'] ?? '') !== '') {
                $sinRespuesta++;
            }
        }
        fwrite(STDOUT, sprintf(
            "    ronda %d: %d aceptados, %d en proceso%s, %d rechazados%s (de %d)\n",
            $ronda, $cuenta['aceptado'], $cuenta['pendiente'] - $sinRespuesta,
            $sinRespuesta > 0 ? ', ' . $sinRespuesta . ' sin respuesta de la consulta' : '',
            $cuenta['rechazado'],
            ($cuenta['sin_registro'] > 0 ? ', ' . $cuenta['sin_registro'] . ' sin registro' : '')
                . ($cuenta['no_verificable'] > 0 ? ', ' . $cuenta['no_verificable'] . ' no verificables' : ''),
            count($seguir)
        ));

        if ($cuenta['rechazado'] > 0 || $cuenta['pendiente'] === 0 || time() >= $deadline) {
            break;
        }
        $dormir(max(1, min($pollSeconds, $deadline - time())));
    }

    $rechazados = [];
    $pendientes = [];
    foreach ($rechazadosEnvio as $encf => $motivo) {
        $rechazados[] = $encf;
        if ($motivo !== '' && isset($porEncf[$encf])) {
            $results[$porEncf[$encf]]['dgii_mensajes'] = $motivo;
        }
        fwrite(STDOUT, "    RECHAZADO $encf (en el envio) :: " . ($motivo !== '' ? $motivo : '(sin mensajes)') . "\n");
    }
    foreach ($seguir as $encf => $s) {
        if ($s['i'] !== null) {
            $results[$s['i']]['estado_dgii_final'] = $s['estado'] !== '' ? $s['estado'] : null;
            if ($s['mensajes'] !== '') {
                $results[$s['i']]['dgii_mensajes'] = $s['mensajes'];
            }
            if (($s['error'] ?? '') !== '') {
                $results[$s['i']]['consulta_error'] = $s['error'];
            }
        }
        if ($s['clase'] === 'rechazado') {
            $rechazados[] = $encf;
            fwrite(STDOUT, "    RECHAZADO $encf :: " . ($s['mensajes'] !== '' ? $s['mensajes'] : '(sin mensajes)') . "\n");
        } elseif ($s['clase'] === 'pendiente') {
            $pendientes[] = $encf . (($s['error'] ?? '') !== ''
                ? ' (sin respuesta de la consulta: ' . substr($s['error'], 0, 100) . ')'
                : ($s['estado'] !== '' ? ' (' . $s['estado'] . ')' : ''));
        }
    }
    $out['rechazos'] = count($rechazados);

    if ($rechazados !== []) {
        $out['general'] = 'la DGII rechazo ' . implode(', ', $rechazados)
            . ' y eso reinicia el set: corrige el rechazo y vuelve a correr el set completo';
    } elseif ($pendientes !== []) {
        // Las notas salen igual: nada indica que la DGII exija los originales
        // terminados (ver docblock). Si algo de esto termina rechazado, el set se
        // reinicia y hay que correrlo completo de todos modos.
        fwrite(STDOUT, '==> AVISO: se agotaron ' . $timeoutSeconds . 's y la DGII sigue procesando '
            . implode(', ', $pendientes) . '. Se mandan las notas; al terminar, revisa el estado de TODO el'
            . " set (consola, paso 3 > Consultar varios).\n");
    } elseif ($out['originales'] !== []) {
        fwrite(STDOUT, "==> Lo demas esta aceptado: salen las notas cuyo original se emitio ("
            . count($out['originales']) . " frenadas).\n");
    } else {
        fwrite(STDOUT, "==> Todo lo consultado esta aceptado: se mandan las notas.\n");
    }
    if ($out['general'] !== null) {
        fwrite(STDOUT, "==> Las notas NO se envian: " . $out['general'] . ".\n");
    }
    return $out;
}

/**
 * Estado en la DGII de un comprobante ya enviado. Integracion: por e-NCF en
 * /integracion/estado (+ track_id, o codigo_seguridad si es RFCE; sin ninguno
 * el server toma el track_id del respaldo). App: /facturas/{id}/estado.
 */
function f2ConsultarEstado(string $apiBase, string $apiKey, string $apiSecret, string $eNcf, array $s): array
{
    $headers = ['Accept: application/json', 'X-API-KEY: ' . $apiKey];
    if ($apiSecret !== '') {
        $query = ['e_ncf' => $eNcf];
        if (!empty($s['track_id'])) {
            $query['track_id'] = $s['track_id'];
        } elseif (!empty($s['codigo_seguridad'])) {
            $query['codigo_seguridad'] = $s['codigo_seguridad'];
        }
        // Empresa que emitio: con credencial de grupo el server consulta a la
        // DGII con el RNC y el certificado de ESA empresa (switchToSibling).
        if (!empty($s['rnc'])) {
            $query['rnc'] = $s['rnc'];
        }
        $url = $apiBase . '/integracion/estado?' . http_build_query($query);
        $headers[] = 'X-API-SECRET: ' . $apiSecret;
    } else {
        $url = $apiBase . '/facturas/' . (int) $s['factura_id'] . '/estado';
    }

    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => DEFAULT_TIMEOUT_SECONDS,
        CURLOPT_HTTPHEADER => $headers,
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
        return ['http_status' => 0, 'body' => ['status' => false, 'error' => 'curl: ' . curl_error($ch)]];
    }
    $decoded = json_decode($raw, true);
    return [
        'http_status' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'body' => is_array($decoded) ? $decoded : ['status' => false, 'error' => 'respuesta no JSON: ' . substr($raw, 0, 300)],
    ];
}

/** Por que una consulta de estado no dio estado: "HTTP 502: <error del API>". */
function f2ErrorDeConsulta(array $resp): string
{
    $http = (int) ($resp['http_status'] ?? 0);
    $error = trim((string) ($resp['body']['error'] ?? ''));
    if ($http === 200 && (bool) ($resp['body']['status'] ?? false)) {
        return 'la DGII no devolvio un estado reconocible';
    }
    return 'HTTP ' . $http . ($error !== '' ? ': ' . $error : '');
}

/** Motivo de un rechazo que llego en la misma respuesta del envio (dgii_response). */
function f2MensajesDeEnvio(array $r): string
{
    $data = is_array($r['response']['data'] ?? null) ? $r['response']['data'] : [];
    $dgii = is_array($data['dgii_response'] ?? null) ? $data['dgii_response'] : [];
    $texto = f2MensajesDeRespuesta(['body' => ['consulta' => $dgii]]);
    return $texto !== '' ? $texto : (string) ($r['error'] ?? '');
}

/** Integracion responde plano ({estado, consulta}); app, bajo data ({estado_dgii, consulta}). */
function f2EstadoDeRespuesta(array $resp): string
{
    if (($resp['http_status'] ?? 0) !== 200 || !(bool) ($resp['body']['status'] ?? false)) {
        return '';
    }
    $data = is_array($resp['body']['data'] ?? null) ? $resp['body']['data'] : $resp['body'];
    $consulta = is_array($data['consulta'] ?? null) ? $data['consulta'] : [];
    return f2NormalizarEstado((string) ($data['estado_dgii'] ?? $data['estado'] ?? $consulta['estado'] ?? ''));
}

function f2MensajesDeRespuesta(array $resp): string
{
    $data = is_array($resp['body']['data'] ?? null) ? $resp['body']['data'] : ($resp['body'] ?? []);
    $consulta = is_array($data['consulta'] ?? null) ? $data['consulta'] : [];
    $mensajes = $consulta['mensajes'] ?? $data['mensajes'] ?? [];
    if (!is_array($mensajes)) {
        return trim((string) $mensajes);
    }
    $partes = [];
    foreach ($mensajes as $m) {
        $valor = is_array($m) ? trim((string) ($m['valor'] ?? '')) : trim((string) $m);
        if ($valor === '') {
            continue;
        }
        $codigo = is_array($m) && isset($m['codigo']) ? ' (' . $m['codigo'] . ')' : '';
        $partes[] = $valor . $codigo;
    }
    return implode(' | ', $partes);
}

/** 'Aceptado Condicional', 'RFCE_ACEPTADO', 'En Proceso'... -> MAYUSCULAS_CON_GUION. */
function f2NormalizarEstado(string $estado): string
{
    return str_replace(' ', '_', strtoupper(trim($estado)));
}

/** aceptado | rechazado | pendiente (en proceso, no encontrado todavia, sin respuesta). */
function f2ClaseEstado(string $estado): string
{
    $e = preg_replace('/^RFCE_/', '', f2NormalizarEstado($estado));
    if ($e === 'ACEPTADO' || $e === 'ACEPTADO_CONDICIONAL') {
        return 'aceptado';
    }
    if ($e === 'RECHAZADO') {
        return 'rechazado';
    }
    return 'pendiente';
}

// Auto-run solo por CLI y cuando este es el script de entrada. Por web (wrapper
// en public/) se incluye este archivo y se llama main($argv) con un $argv
// construido desde la peticion; las pruebas lo incluyen para usar sus funciones.
if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(main($argv));
}
