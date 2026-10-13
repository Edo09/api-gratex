<?php

/**
 * Pre-flight de empresa para los runners de certificacion en modo INTEGRACION
 * (send_fase2.php, send_fase3.php, send_fase4_simulation.php con --api-secret).
 *
 * Por que: con una credencial de grupo, la misma api_key+api_secret emite por
 * N empresas y el RNC del payload elige cual (TenantResolver::switchToSibling).
 * Cada empresa tiene su propio ambiente: la cabeza puede estar ya en 'ecf'
 * (produccion) mientras una hermana certifica en 'certecf'. El servidor fuerza
 * el ambiente desde tenants.ambiente, asi que un xlsx de la empresa equivocada,
 * o una empresa ya promovida, sacaria el set de pruebas como e-CF REALES.
 *
 * Antes de enviar NADA:
 *   1. Lo que se va a enviar es de UNA sola empresa (un set no mezcla RNC).
 *   2. --empresa-rnc (la empresa elegida en integracion.html): tiene que ser
 *      esa misma. Offline: corre tambien en dry-run.
 *   3. Solo enviando de verdad: GET /integracion/empresas con la misma
 *      credencial. El RNC tiene que estar en la lista y su ambiente ser
 *      'certecf', salvo --permitir-produccion (solo por CLI: cert_run.php
 *      nunca lo pasa). Si la consulta falla, no se envia (falla cerrado).
 *
 * En modo app (sin --api-secret) no se llama: ahi el ambiente lo fuerza
 * --ambiente y estos flags se ignoran, igual que --xml-dir o --encf-start.
 */

const CERT_EMPRESA_TIMEOUT_SECONDS = 30;

/** Solo digitos: '131-25643-2' y '131256432' son el mismo RNC. */
function certEmpresaNormalizarRnc($rnc): string
{
    return preg_replace('/\D/', '', trim((string) $rnc));
}

/**
 * RNC distintos de la columna $columna en las filas que se van a enviar.
 * sin_rnc: filas con algun dato pero sin RNC (las filas vacias del xlsx no cuentan).
 *
 * @return array{rncs: string[], sin_rnc: int}
 */
function certEmpresaRncsDeFilas(array $rows, string $columna): array
{
    $rncs = [];
    $sinRnc = 0;
    foreach ($rows as $row) {
        $rnc = certEmpresaNormalizarRnc($row[$columna] ?? '');
        if ($rnc !== '') {
            $rncs[$rnc] = true;
            continue;
        }
        $conDatos = array_filter((array) $row, fn($v) => trim((string) $v) !== '');
        if ($conDatos !== []) {
            $sinRnc++;
        }
    }
    // Las claves numericas de PHP vuelven como int: de nuevo a string.
    return ['rncs' => array_map('strval', array_keys($rncs)), 'sin_rnc' => $sinRnc];
}

/**
 * --empresa-rnc y --permitir-produccion de los argumentos del runner.
 * empresa_rnc: null = no vino; '' = vino sin valor (invalido).
 *
 * @return array{empresa_rnc: ?string, permitir_produccion: bool}
 */
function certEmpresaOpciones(array $opts): array
{
    $empresaRnc = null;
    if (array_key_exists('empresa-rnc', $opts)) {
        $empresaRnc = is_string($opts['empresa-rnc']) ? trim($opts['empresa-rnc']) : '';
    }
    return [
        'empresa_rnc' => $empresaRnc,
        'permitir_produccion' => isset($opts['permitir-produccion']),
    ];
}

/**
 * Verifica la empresa de la corrida. No escribe nada: devuelve que decir.
 *
 * $o:
 *   api, api_key, api_secret  la credencial de la corrida
 *   rncs                      RNC distintos de lo que se va a enviar
 *   origen                    de donde salen, para los mensajes ('RNCEmisor de la hoja ECF')
 *   distinto                  mensaje si no es la elegida: sprintf(%1$s = RNC de la
 *                             corrida, %2$s = empresa elegida). Default: el del xlsx.
 *   empresa_rnc, permitir_produccion   de certEmpresaOpciones()
 *   dry_run                   true = no consulta /integracion/empresas
 *   consultar                 solo pruebas: fn(api, key, secret) => ['http_status', 'body']
 *
 * @return array{ok: bool, error: string, lineas: string[], empresa: ?array}
 */
function certEmpresaPreflight(array $o): array
{
    $fallo = fn(string $error) => ['ok' => false, 'error' => $error, 'lineas' => [], 'empresa' => null];
    $origen = (string) ($o['origen'] ?? 'RNC');

    $rncs = array_values(array_unique(array_filter(array_map('certEmpresaNormalizarRnc', (array) ($o['rncs'] ?? [])))));
    if (count($rncs) > 1) {
        return $fallo('El set mezcla varias empresas (' . $origen . ': ' . implode(', ', $rncs) . ').'
            . ' Un set de pruebas es de UNA empresa: separalo o filtralo. No se envio nada.');
    }
    if ($rncs === []) {
        return $fallo('No se pudo determinar la empresa de la corrida (' . $origen . ' vacio). No se envio nada.');
    }
    $rnc = $rncs[0];

    // La empresa elegida en integracion.html. Offline: tambien en dry-run.
    $elegida = $o['empresa_rnc'] ?? null;
    if ($elegida !== null) {
        $elegidaNorm = certEmpresaNormalizarRnc($elegida);
        if (!preg_match('/^\d{9,11}$/', $elegidaNorm)) {
            return $fallo('--empresa-rnc invalido (' . ($elegida !== '' ? $elegida : 'vacio') . '): son 9 a 11 digitos.');
        }
        if ($elegidaNorm !== $rnc) {
            $plantilla = (string) ($o['distinto'] ?? 'El xlsx es de RNC %1$s, la empresa elegida es %2$s.'
                . ' Elige esa empresa en integracion.html o sube el xlsx de la empresa elegida');
            return $fallo(sprintf($plantilla, $rnc, $elegidaNorm) . '. No se envio nada.');
        }
    }

    if (!empty($o['dry_run'])) {
        return ['ok' => true, 'error' => '', 'empresa' => null,
            'lineas' => ['Empresa: (sin verificar en dry-run) — RNC ' . $rnc . ' — ambiente (sin verificar en dry-run)']];
    }

    // Falla cerrado: sin la lista no se sabe en que ambiente saldria el set.
    $consultar = $o['consultar'] ?? 'certEmpresaConsultarLista';
    $resp = $consultar((string) ($o['api'] ?? ''), (string) ($o['api_key'] ?? ''), (string) ($o['api_secret'] ?? ''));
    $http = (int) ($resp['http_status'] ?? 0);
    $body = is_array($resp['body'] ?? null) ? $resp['body'] : [];
    if ($http !== 200 || ($body['status'] ?? false) !== true || !is_array($body['data'] ?? null)) {
        $detalle = trim((string) ($body['error'] ?? ''));
        return $fallo('No se pudo verificar la empresa: GET /integracion/empresas respondio HTTP ' . $http
            . ($detalle !== '' ? ' (' . $detalle . ')' : '')
            . '. Sin esa verificacion no se sabe en que ambiente saldria el set. No se envio nada.');
    }

    $empresa = null;
    $cubre = [];
    foreach ($body['data'] as $e) {
        if (!is_array($e)) {
            continue;
        }
        $eRnc = certEmpresaNormalizarRnc($e['rnc'] ?? '');
        $cubre[] = trim((string) ($e['nombre'] ?? '')) . ' ' . $eRnc;
        if ($eRnc === $rnc && $empresa === null) {
            $empresa = $e;
        }
    }
    if ($empresa === null) {
        return $fallo('La credencial no cubre el RNC ' . $rnc . ' (' . $origen . '). Cubre: '
            . ($cubre !== [] ? implode(', ', $cubre) : '(ninguna)') . '. No se envio nada.');
    }

    $nombre = trim((string) ($empresa['nombre'] ?? ''));
    $ambiente = trim((string) ($empresa['ambiente'] ?? ''));
    $lineas = [];
    if ($ambiente !== 'certecf') {
        $estado = "'" . ($ambiente !== '' ? $ambiente : '(vacio)') . "'";
        // Solo ecf (o vacio, que el server trata como ecf) es produccion; testecf es otro ambiente de pruebas.
        $efecto = ($ambiente === 'ecf' || $ambiente === '')
            ? 'el set de pruebas saldria como e-CF REALES (comprobantes fiscales y secuencias de verdad)'
            : 'el set de pruebas iria a ese ambiente de la DGII, no al de certificacion';
        if (empty($o['permitir_produccion'])) {
            return $fallo('La empresa ' . $nombre . ' (RNC ' . $rnc . ') esta en ambiente ' . $estado . ', no en certecf: '
                . $efecto . '. No se envio nada. Si es una empresa que todavia certifica, revisa tenants.ambiente;'
                . ' si de verdad quieres enviarlo a ese ambiente, corre el runner por CLI con --permitir-produccion.');
        }
        $lineas[] = 'AVISO: --permitir-produccion: la empresa ' . $nombre . ' (RNC ' . $rnc . ') esta en ambiente '
            . $estado . ': ' . $efecto . '.';
    }
    $lineas[] = 'Empresa: ' . $nombre . ' — RNC ' . $rnc . ' — ambiente ' . ($ambiente !== '' ? $ambiente : '(vacio)');
    return ['ok' => true, 'error' => '', 'lineas' => $lineas, 'empresa' => $empresa];
}

/** certEmpresaPreflight() para los runners: escribe lo que haya que decir y devuelve si se sigue. */
function certEmpresaPreflightCli(array $o): bool
{
    $r = certEmpresaPreflight($o);
    if (!$r['ok']) {
        fwrite(STDERR, 'ERROR: ' . $r['error'] . "\n");
        return false;
    }
    foreach ($r['lineas'] as $linea) {
        fwrite(STDOUT, '==> ' . $linea . "\n");
    }
    return true;
}

/** GET /integracion/empresas con la credencial de la corrida. */
function certEmpresaConsultarLista(string $apiBase, string $apiKey, string $apiSecret): array
{
    $ch = curl_init(rtrim($apiBase, '/') . '/integracion/empresas');
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => CERT_EMPRESA_TIMEOUT_SECONDS,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'X-API-KEY: ' . $apiKey,
            'X-API-SECRET: ' . $apiSecret,
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
        return ['http_status' => 0, 'body' => ['status' => false, 'error' => 'curl: ' . curl_error($ch)]];
    }
    $decoded = json_decode($raw, true);
    return [
        'http_status' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'body' => is_array($decoded) ? $decoded : ['status' => false, 'error' => 'respuesta no JSON: ' . substr($raw, 0, 300)],
    ];
}
