<?php
/**
 * consultar_ecf_dgii.php — Pregunta a la DGII lo que sabe de un e-NCF YA
 * emitido, sin tocar la base ni la secuencia. Para rescatar un comprobante que
 * la DGII recibio pero que no quedo guardado en facturas (caso E340000000001,
 * 2026-10-05: la DGII lo acepto y el INSERT fallo por la clave uk_e_ncf vieja).
 *
 * Tres servicios, con el certificado del propio emisor (la DGII exige un token
 * "delegado para el emisor"):
 *
 *   1. ConsultaTrackIds  {amb}/consultatrackids/api/TrackIds/Consulta?RncEmisor&Encf
 *        -> trackId(s), estado, fechaRecepcion. Varios si el e-NCF se envio mas de una vez.
 *   2. ConsultaEstado    {amb}/consultaestado/api/Consultas/Estado?RncEmisor&NcfElectronico
 *        -> codigo, estado, montoTotal, totalITBIS, fechaEmision, fechaFirma,
 *           rncComprador, codigoSeguridad, idExtranjero ("extraidos del e-CF recibido").
 *   3. ConsultaResultado {amb}/ConsultaResultado/api/Consultas/Estado?trackId&rnc&encf
 *        -> codigo, estado, secuenciaUtilizada, fechaRecepcion, mensajes (el motivo de
 *           un rechazo). Es el que ya usa ECFEmissionService::consultarEstado.
 *
 * Con codigoSeguridad + fechaFirma + montoTotal el timbre/QR de la Representacion
 * Impresa vuelve a ser valido (EcfDocumento::timbre solo necesita eso, los RNC y
 * la fecha). Lo que NINGUN servicio devuelve: items, NCFModificado, razon, XML.
 *
 * Los servicios 1 y 2 existen solo en testecf y ecf: la DGII los retiro de
 * certificacion (bitacora 28-12-2020 de la Descripcion Tecnica); en certecf
 * responden 404.
 *
 * Se corre en el SERVER (ahi vive el certificado del tenant):
 *   php tools/consultar_ecf_dgii.php --rnc=131111111 --encf=E340000000001,E340000000002
 *   php tools/consultar_ecf_dgii.php --tenant-id=2 --encf=E340000000001 --json=/tmp/e34.json
 *
 * Opciones: --ambiente=ecf|testecf (por defecto tenants.ambiente), --rnc-comprador=
 * y --codigo-seguridad= (ConsultaEstado los llama "condicionales a la vigencia";
 * solo si la DGII los exige), --timeout=30, --json=ruta (crudo + resumen),
 * --solo-json (JSON por STDOUT, texto por STDERR).
 *
 * Salida 0 si todas las consultas respondieron, 1 si alguna fallo, 2 si faltan
 * argumentos. Las funciones cecf* son puras: tools/test_consultar_ecf_dgii.php.
 */

require_once __DIR__ . '/../src/MasterDatabase.php';
require_once __DIR__ . '/../src/TenantResolver.php';
require_once __DIR__ . '/../src/CertResolver.php';
require_once __DIR__ . '/../src/AmbienteResolver.php';
require_once __DIR__ . '/../src/Utils/FacturacionElectronica/DgiiAuthService.php';
require_once __DIR__ . '/../src/Utils/FacturacionElectronica/DgiiReceptionService.php';

const CECF_BASE_URL = 'https://ecf.dgii.gov.do';

// ---------------------------------------------------------------------------
// Funciones puras (sin red, sin base): probadas en test_consultar_ecf_dgii.php
// ---------------------------------------------------------------------------

/** Ruta relativa al ambiente; DgiiAuthService antepone base/{ambiente}/. */
function cecfRutaTrackIds(string $rnc, string $encf): string
{
    return 'consultatrackids/api/TrackIds/Consulta?' . http_build_query(['RncEmisor' => $rnc, 'Encf' => $encf]);
}

/** RncComprador y CodigoSeguridad solo cuando se tienen: la DGII los pide a veces. */
function cecfRutaEstado(string $rnc, string $encf, string $rncComprador = '', string $codigo = ''): string
{
    $q = ['RncEmisor' => $rnc, 'NcfElectronico' => $encf];
    if ($rncComprador !== '') {
        $q['RncComprador'] = $rncComprador;
    }
    if ($codigo !== '') {
        $q['CodigoSeguridad'] = $codigo;
    }
    return 'consultaestado/api/Consultas/Estado?' . http_build_query($q);
}

/**
 * TrackingDetalle viene como objeto, o como lista cuando el mismo e-NCF se
 * remitio varias veces. Un 404 con cuerpo ProblemDetails, un texto o null es
 * "sin trackIds". Siempre devuelve las tres claves.
 * @return array<int,array{trackId:string,estado:?string,fechaRecepcion:?string}>
 */
function cecfNormalizarTrackIds($data): array
{
    if (!is_array($data)) {
        return [];
    }
    $lista = array_is_list($data) ? $data : [$data];
    $out = [];
    foreach ($lista as $item) {
        if (!is_array($item) || !isset($item['trackId']) || trim((string) $item['trackId']) === '') {
            continue;
        }
        $out[] = [
            'trackId' => trim((string) $item['trackId']),
            'estado' => isset($item['estado']) ? (string) $item['estado'] : null,
            'fechaRecepcion' => isset($item['fechaRecepcion']) ? (string) $item['fechaRecepcion'] : null,
        ];
    }
    return $out;
}

/**
 * Fechas de la DGII a 'Y-m-d H:i:s' (con hora) o 'Y-m-d'. Mezclan tres formatos:
 * el .NET en-US "M/d/yyyy h:mm:ss tt" (fechaRecepcion real: "5/27/2026 3:31:10 PM"),
 * el "dd-MM-yyyy HH:mm:ss" del XML (fechaFirma, fechaEmision) e ISO 8601. Con
 * barras manda el mes primero; con guiones, el dia primero: un dia <= 12 no
 * avisa si se adivina mal, por eso no se usa strtotime. Si se exige hora y el
 * valor no la trae, null: para fecha_emision_dgii un "00:00:00" inventado
 * rompe el QR (ConsultaTimbre compara FechaFirma al segundo).
 */
function cecfNormalizarFecha(?string $valor, bool $conHora): ?string
{
    $v = trim((string) $valor);
    if ($v === '') {
        return null;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) {
        // ISO: fuera fraccion de segundo y zona; la hora se toma tal cual (local).
        $v = (string) preg_replace('/^(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2})(\.\d+)?(Z|[+-]\d{2}:?\d{2})?$/', '$1', $v);
        $formatos = ['Y-m-d\TH:i:s', 'Y-m-d H:i:s', 'Y-m-d'];
    } elseif (str_contains($v, '/')) {
        $formatos = ['n/j/Y g:i:s A', 'n/j/Y H:i:s', 'n/j/Y g:i A', 'n/j/Y'];
    } else {
        $formatos = ['d-m-Y H:i:s', 'd-m-Y H:i', 'd-m-Y'];
    }
    foreach ($formatos as $f) {
        $dt = DateTime::createFromFormat('!' . $f, $v);
        if ($dt === false) {
            continue;
        }
        $err = DateTime::getLastErrors();
        if (is_array($err) && (($err['warning_count'] ?? 0) > 0 || ($err['error_count'] ?? 0) > 0)) {
            continue; // p.ej. 31-02: PHP lo "arregla" corriendolo a marzo y avisa
        }
        $tieneHora = str_contains($f, 'H') || str_contains($f, 'g');
        if ($conHora && !$tieneHora) {
            return null;
        }
        return $dt->format($conHora ? 'Y-m-d H:i:s' : 'Y-m-d');
    }
    return null;
}

/**
 * Texto/codigo de la DGII al vocabulario de facturas.estado_dgii. Mismo orden
 * que ECFEmissionService::mapEstado (rechaz, condicion, acept, proceso, no
 * encontrado) y la tabla de codigos 0..4 de la Descripcion Tecnica.
 */
function cecfMapEstado(?string $texto, $codigo): ?string
{
    $t = strtolower(trim((string) $texto));
    if ($t !== '') {
        if (str_contains($t, 'rechaz')) {
            return 'RECHAZADO';
        }
        if (str_contains($t, 'condicion')) {
            return 'ACEPTADO_CONDICIONAL';
        }
        if (str_contains($t, 'acept')) {
            return 'ACEPTADO';
        }
        if (str_contains($t, 'proceso')) {
            return 'EN_PROCESO';
        }
        if (str_contains($t, 'no encontrado')) {
            return 'NO_ENCONTRADO';
        }
    }
    if ($codigo !== null && $codigo !== '' && is_numeric($codigo)) {
        return [0 => 'NO_ENCONTRADO', 1 => 'ACEPTADO', 2 => 'RECHAZADO', 3 => 'EN_PROCESO', 4 => 'ACEPTADO_CONDICIONAL'][(int) $codigo] ?? null;
    }
    return null;
}

/**
 * Lo que hace falta para reconstruir la fila de facturas, con los nombres de
 * sus columnas. $estado es la respuesta de ConsultaEstado (null si fallo o no
 * existe en el ambiente); $resultados, una entrada por trackId con 'data' de
 * ConsultaResultado. Sin ConsultaEstado el estado sale de TrackIds o de
 * ConsultaResultado, y codigo/fechas/montos quedan en null.
 */
function cecfResumen(string $encf, array $trackIds, ?array $estado, array $resultados): array
{
    $primerResultado = null;
    foreach ($resultados as $r) {
        if (is_array($r['data'] ?? null)) {
            $primerResultado = $r['data'];
            break;
        }
    }

    $estadoDgii = null;
    if ($estado !== null) {
        $estadoDgii = cecfMapEstado($estado['estado'] ?? null, $estado['codigo'] ?? null);
    }
    if ($estadoDgii === null && $trackIds !== []) {
        $estadoDgii = cecfMapEstado($trackIds[0]['estado'], null);
    }
    if ($estadoDgii === null && $primerResultado !== null) {
        $estadoDgii = cecfMapEstado($primerResultado['estado'] ?? null, $primerResultado['codigo'] ?? null);
    }

    $secuencia = null;
    foreach ($resultados as $r) {
        if (is_array($r['data'] ?? null) && array_key_exists('secuenciaUtilizada', $r['data'])) {
            $secuencia = filter_var($r['data']['secuenciaUtilizada'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            break;
        }
    }

    $mensajes = [];
    foreach ($resultados as $r) {
        foreach ((array) ($r['data']['mensajes'] ?? []) as $m) {
            $valor = trim((string) ($m['valor'] ?? ''));
            if ($valor === '') {
                continue;
            }
            $mensajes[] = ['codigo' => (int) ($m['codigo'] ?? 0), 'valor' => $valor];
        }
    }

    $monto = static fn($v): ?string => ($v !== null && $v !== '' && is_numeric($v)) ? number_format((float) $v, 2, '.', '') : null;
    $texto = static fn($v): ?string => ($v !== null && trim((string) $v) !== '') ? trim((string) $v) : null;

    return [
        'e_ncf' => $encf,
        'tipo_ecf' => substr($encf, 1, 2),
        'estado_dgii' => $estadoDgii,
        'estado_texto' => $texto($estado['estado'] ?? ($trackIds[0]['estado'] ?? ($primerResultado['estado'] ?? null))),
        'codigo_estado' => $estado['codigo'] ?? ($primerResultado['codigo'] ?? null),
        'track_id' => $trackIds[0]['trackId'] ?? ($resultados[0]['trackId'] ?? null),
        'track_ids' => array_column($trackIds, 'trackId'),
        'codigo_seguridad' => $texto($estado['codigoSeguridad'] ?? null),
        'fecha_emision_dgii' => cecfNormalizarFecha($estado['fechaFirma'] ?? null, true),
        'fecha_emision' => cecfNormalizarFecha($estado['fechaEmision'] ?? null, false),
        'total' => $monto($estado['montoTotal'] ?? null),
        'total_itbis' => $monto($estado['totalITBIS'] ?? null),
        'rnc_comprador' => $texto($estado['rncComprador'] ?? null),
        'id_extranjero' => $texto($estado['idExtranjero'] ?? null),
        'secuencia_utilizada' => $secuencia,
        'fecha_recepcion' => cecfNormalizarFecha($trackIds[0]['fechaRecepcion'] ?? ($primerResultado['fechaRecepcion'] ?? null), true),
        'mensajes' => $mensajes,
    ];
}

/**
 * URL de ConsultaTimbre, armada igual que EcfDocumento::timbre(): FechaEmision
 * d-m-Y, MontoTotal a 2 decimales, FechaFirma d-m-Y H:i:s, RncComprador salvo
 * E43/E47, ConsultaTimbreFC para E32 < 250k. Null si falta codigo, firma,
 * fecha o monto: abrirla en el navegador confirma que el QR resolvera.
 */
function cecfUrlTimbre(array $r, string $rncEmisor, string $ambiente): ?string
{
    $codigo = (string) ($r['codigo_seguridad'] ?? '');
    $firma = cecfNormalizarFecha($r['fecha_emision_dgii'] ?? null, true);
    $emision = cecfNormalizarFecha($r['fecha_emision'] ?? null, false);
    $total = $r['total'] ?? null;
    $encf = (string) ($r['e_ncf'] ?? '');
    if ($codigo === '' || $firma === null || $emision === null || $total === null || $encf === '' || $rncEmisor === '') {
        return null;
    }
    $tipo = (string) ($r['tipo_ecf'] ?? substr($encf, 1, 2));
    $amb = match (strtolower(trim($ambiente))) {
        'certecf' => 'CerteCF',
        'testecf' => 'TesteCF',
        'ecf' => 'ecf',
        default => $ambiente,
    };
    $endpoint = ($tipo === '32' && (float) $total < 250000) ? 'ConsultaTimbreFC' : 'ConsultaTimbre';
    $rncComprador = (string) ($r['rnc_comprador'] ?? '');
    $paramComprador = ($rncComprador !== '' && !in_array($tipo, ['43', '47'], true))
        ? '&RncComprador=' . rawurlencode($rncComprador)
        : '';
    return sprintf(
        '%s/%s/%s?RncEmisor=%s%s&ENCF=%s&FechaEmision=%s&MontoTotal=%s&FechaFirma=%s&CodigoSeguridad=%s',
        CECF_BASE_URL,
        rawurlencode($amb),
        $endpoint,
        rawurlencode($rncEmisor),
        $paramComprador,
        rawurlencode($encf),
        rawurlencode(DateTime::createFromFormat('!Y-m-d', $emision)->format('d-m-Y')),
        rawurlencode($total),
        rawurlencode(DateTime::createFromFormat('Y-m-d H:i:s', $firma)->format('d-m-Y H:i:s')),
        rawurlencode($codigo)
    );
}

function cecfAvisoAmbiente(string $ambiente): ?string
{
    if (strtolower(trim($ambiente)) !== 'certecf') {
        return null;
    }
    return 'ConsultaTrackIds y ConsultaEstado no existen en certecf (la DGII los retiro de certificacion); '
        . 'responderan 404. Solo sirven en ecf y testecf. Si el comprobante se emitio en produccion, usa --ambiente=ecf.';
}

/** "E340000000001, e340000000002" -> lista unica de e-NCF validos (E + 12 digitos). */
function cecfParsearEncfs(string $lista): array
{
    $out = [];
    foreach (explode(',', $lista) as $e) {
        $e = strtoupper(trim($e));
        if ($e !== '' && preg_match('/^E\d{12}$/', $e) && !in_array($e, $out, true)) {
            $out[] = $e;
        }
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Ejecucion (red + certificado): solo por CLI
// ---------------------------------------------------------------------------

/**
 * @param array $o rnc, tenant_id, encf, ambiente, rnc_comprador, codigo_seguridad, timeout, json, solo_json
 * @return int 0 todo respondio, 1 alguna consulta fallo, 2 argumentos invalidos
 */
function cecfEjecutar(array $o): int
{
    $soloJson = !empty($o['solo_json']);
    $cli = PHP_SAPI === 'cli';
    // Texto: por CLI a STDOUT (o STDERR cuando el JSON va por STDOUT); por web
    // (public/consultar_ecf_dgii.php) se imprime en vivo, salvo en formato json,
    // donde solo sale el JSON.
    $out = static function (string $s) use ($soloJson, $cli): void {
        if ($cli) {
            fwrite($soloJson ? STDERR : STDOUT, $s);
            return;
        }
        if ($soloJson) {
            return;
        }
        echo $s;
        @flush();
    };
    $linea = static fn(string $t) => $out("\n== {$t} " . str_repeat('=', max(0, 58 - strlen($t))) . "\n");
    $info = static fn(string $k, ?string $v) => $out(sprintf("   %-22s %s\n", $k . ':', $v ?? '(sin dato)'));
    // Corte temprano: en texto sale la X; en formato json, un {"error":...} para
    // que la respuesta nunca quede vacia.
    $abortar = static function (string $msg, int $codigo) use ($out, $soloJson, $cli): int {
        $out("   X  {$msg}\n");
        if ($soloJson) {
            $j = json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            $cli ? fwrite(STDOUT, $j) : print($j);
        }
        return $codigo;
    };

    $encfs = cecfParsearEncfs((string) ($o['encf'] ?? ''));
    if ($encfs === []) {
        return $abortar('Falta --encf=E340000000001[,E340000000002] (E + 12 digitos).', 2);
    }
    $rnc = preg_replace('/\D/', '', (string) ($o['rnc'] ?? ''));
    $tenantId = (int) ($o['tenant_id'] ?? 0);
    if ($rnc === '' && $tenantId <= 0) {
        return $abortar('Falta --rnc=<rnc del emisor> o --tenant-id=<id>.', 2);
    }
    $timeout = max(1, (int) ($o['timeout'] ?? 30));
    $rncComprador = preg_replace('/\D/', '', (string) ($o['rnc_comprador'] ?? ''));
    $codigoArg = trim((string) ($o['codigo_seguridad'] ?? ''));

    // --- Tenant ---------------------------------------------------------------
    $linea('TENANT');
    $tenant = null;
    try {
        $resuelto = $tenantId > 0 ? TenantResolver::resolveById($tenantId) : TenantResolver::resolveByRnc($rnc);
    } catch (Throwable $e) {
        // Sin master DB (p.ej. corriendo fuera del server) no hay tenant ni certificado.
        return $abortar('No se pudo consultar la base master: ' . $e->getMessage()
            . ' Este script se corre en el server, donde estan el .env y el certificado del tenant.', 1);
    }
    if ($resuelto) {
        $tenant = TenantResolver::current();
        $rnc = preg_replace('/\D/', '', (string) $tenant['rnc']);
        $info('tenant', '#' . $tenant['id'] . ' ' . $tenant['nombre']);
        $info('rnc / tipo', $rnc . ' / ' . ($tenant['tipo'] ?? '?'));
        $info('tenants.ambiente', (string) ($tenant['ambiente'] ?? '') ?: '(vacio)');
    } elseif ($rnc !== '') {
        $out("   !  No se resolvio el tenant: se usa el certificado global del .env y RncEmisor={$rnc}.\n");
    } else {
        return $abortar("No se resolvio el tenant id={$tenantId}.", 1);
    }

    $ambiente = AmbienteResolver::normalize((string) ($o['ambiente'] ?? ''))
        ?? AmbienteResolver::normalize((string) ($tenant['ambiente'] ?? ''))
        ?? AmbienteResolver::normalize((string) (getenv('DGII_ECF_ENVIRONMENT') ?: ($_ENV['DGII_ECF_ENVIRONMENT'] ?? '')));
    if ($ambiente === null) {
        return $abortar('Sin ambiente: el tenant no lo tiene y no hay DGII_ECF_ENVIRONMENT. Indica --ambiente=ecf|testecf.', 2);
    }
    $info('ambiente', $ambiente . (!empty($o['ambiente']) ? ' (forzado)' : ''));
    if (($aviso = cecfAvisoAmbiente($ambiente)) !== null) {
        $out("   !  {$aviso}\n");
    }

    // --- Token ---------------------------------------------------------------
    $linea('TOKEN DGII');
    $auth = new DgiiAuthService();
    try {
        $cert = CertResolver::resolve();
        $info('certificado', $cert['path']);
        $tok = $auth->autenticar([
            'environment' => $ambiente,
            'certificate_content' => $cert['content'],
            'certificate_password' => $cert['password'],
            'timeout' => $timeout,
        ]);
        $info('token', 'OK, expira ' . (string) ($tok['expira'] ?? $tok['expedido'] ?? '?'));
    } catch (Throwable $e) {
        return $abortar('No hubo token: ' . $e->getMessage(), 1);
    }
    $opts = ['environment' => $ambiente, 'timeout' => $timeout, 'tolerate_http_errors' => true];
    $reception = new DgiiReceptionService($auth);

    // --- Consultas -----------------------------------------------------------
    $fallos = 0;
    $crudo = [];
    $resumenes = [];
    foreach ($encfs as $encf) {
        $linea($encf);
        $crudo[$encf] = ['trackids' => null, 'estado' => null, 'resultados' => []];

        $trackIds = [];
        try {
            $r = $auth->consultarEndpointAutenticado('GET', cecfRutaTrackIds($rnc, $encf), $tok['token'], null, $opts);
            $crudo[$encf]['trackids'] = ['status_code' => $r['status_code'], 'endpoint' => $r['endpoint'], 'data' => $r['data']];
            $trackIds = cecfNormalizarTrackIds($r['data']);
            $info('ConsultaTrackIds', 'HTTP ' . $r['status_code'] . ', ' . count($trackIds) . ' trackId(s)');
        } catch (Throwable $e) {
            $fallos++;
            $crudo[$encf]['trackids'] = ['error' => $e->getMessage()];
            $info('ConsultaTrackIds', 'FALLO ' . $e->getMessage());
        }

        $estado = null;
        try {
            $r = $auth->consultarEndpointAutenticado('GET', cecfRutaEstado($rnc, $encf, $rncComprador, $codigoArg), $tok['token'], null, $opts);
            $crudo[$encf]['estado'] = ['status_code' => $r['status_code'], 'endpoint' => $r['endpoint'], 'data' => $r['data']];
            // Un 404 "ProblemDetails" o un cuerpo no JSON no es un estado.
            $estado = (is_array($r['data']) && (isset($r['data']['ncfElectronico']) || isset($r['data']['codigo']))) ? $r['data'] : null;
            $info('ConsultaEstado', 'HTTP ' . $r['status_code'] . ($estado === null ? ', sin datos del e-CF' : ', ' . (string) ($estado['estado'] ?? '?')));
        } catch (Throwable $e) {
            $fallos++;
            $crudo[$encf]['estado'] = ['error' => $e->getMessage()];
            $info('ConsultaEstado', 'FALLO ' . $e->getMessage());
        }

        $resultados = [];
        foreach ($trackIds as $t) {
            try {
                $r = $reception->consultarEstado($t['trackId'], $rnc, $encf, $tok['token'], $opts);
                $resultados[] = ['trackId' => $t['trackId'], 'status_code' => $r['status_code'], 'endpoint' => $r['endpoint'], 'data' => $r['data']];
                $info('ConsultaResultado', $t['trackId'] . ' -> HTTP ' . $r['status_code'] . ', ' . (string) ($r['data']['estado'] ?? '?'));
            } catch (Throwable $e) {
                $fallos++;
                $resultados[] = ['trackId' => $t['trackId'], 'error' => $e->getMessage()];
                $info('ConsultaResultado', $t['trackId'] . ' -> FALLO ' . $e->getMessage());
            }
        }
        $crudo[$encf]['resultados'] = $resultados;

        $res = cecfResumen($encf, $trackIds, $estado, $resultados);
        $res['url_timbre'] = cecfUrlTimbre($res, $rnc, $ambiente);
        $resumenes[$encf] = $res;

        $out("   -- para la fila de facturas --\n");
        $info('estado_dgii', $res['estado_dgii'] . ($res['estado_texto'] !== null ? ' (' . $res['estado_texto'] . ')' : ''));
        $info('track_id', $res['track_id'] . (count($res['track_ids']) > 1 ? ' (+' . (count($res['track_ids']) - 1) . ' mas)' : ''));
        $info('codigo_seguridad', $res['codigo_seguridad']);
        $info('fecha_emision_dgii', $res['fecha_emision_dgii']);
        $info('date (fecha emision)', $res['fecha_emision']);
        $info('total', $res['total']);
        $info('total ITBIS', $res['total_itbis']);
        $info('rnc comprador', $res['rnc_comprador']);
        $info('secuencia_utilizada', $res['secuencia_utilizada'] === null ? null : ($res['secuencia_utilizada'] ? 'true' : 'false'));
        $info('fecha recepcion', $res['fecha_recepcion']);
        foreach ($res['mensajes'] as $m) {
            $info('mensaje DGII', '[' . $m['codigo'] . '] ' . $m['valor']);
        }
        $info('URL ConsultaTimbre', $res['url_timbre'] ?? '(no se puede armar: falta codigo, firma, fecha o monto)');
    }

    $salida = [
        'generado' => date('c'),
        'rnc_emisor' => $rnc,
        'ambiente' => $ambiente,
        'tenant' => $tenant !== null ? ['id' => (int) $tenant['id'], 'nombre' => $tenant['nombre']] : null,
        'resumen' => $resumenes,
        'crudo' => $crudo,
    ];
    $json = json_encode($salida, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    // Solo por CLI: por web nunca se escribe en una ruta que venga del request.
    if ($cli && !empty($o['json'])) {
        file_put_contents((string) $o['json'], $json . "\n");
        $out("\nJSON (crudo + resumen) guardado en " . $o['json'] . "\n");
    }
    if ($soloJson) {
        if ($cli) {
            fwrite(STDOUT, $json . "\n");
        } else {
            echo $json, "\n";
        }
    }
    $out("\n" . ($fallos === 0 ? 'Todas las consultas respondieron.' : "{$fallos} consulta(s) fallaron (ver arriba).") . "\n");
    return $fallos === 0 ? 0 : 1;
}

if (PHP_SAPI === 'cli' && isset($argv) && realpath($argv[0]) === realpath(__FILE__)) {
    $o = getopt('', ['rnc::', 'tenant-id::', 'encf::', 'ambiente::', 'rnc-comprador::', 'codigo-seguridad::', 'timeout::', 'json::', 'solo-json', 'ayuda', 'help']);
    if (isset($o['ayuda']) || isset($o['help'])) {
        fwrite(STDOUT, "Uso: php tools/consultar_ecf_dgii.php --rnc=<rnc> --encf=E340000000001[,E340000000002]\n"
            . "     [--tenant-id=N] [--ambiente=ecf|testecf] [--rnc-comprador=] [--codigo-seguridad=]\n"
            . "     [--timeout=30] [--json=ruta] [--solo-json]\n");
        exit(0);
    }
    Database::loadEnv();
    exit(cecfEjecutar([
        'rnc' => $o['rnc'] ?? '',
        'tenant_id' => $o['tenant-id'] ?? 0,
        'encf' => $o['encf'] ?? '',
        'ambiente' => $o['ambiente'] ?? '',
        'rnc_comprador' => $o['rnc-comprador'] ?? '',
        'codigo_seguridad' => $o['codigo-seguridad'] ?? '',
        'timeout' => $o['timeout'] ?? 30,
        'json' => $o['json'] ?? '',
        'solo_json' => isset($o['solo-json']),
    ]));
}
