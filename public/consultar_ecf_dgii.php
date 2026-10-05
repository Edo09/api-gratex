<?php
/**
 * consultar_ecf_dgii.php — Wrapper web de tools/consultar_ecf_dgii.php.
 *
 * El server de produccion es hosting compartido: cuando no hay shell, los
 * tools/ de CLI se corren desde aqui, bajo /api/public/ (el .htaccess deja
 * pasar los archivos existentes de esa carpeta). Pregunta a la DGII, con el
 * certificado del tenant, lo que sabe de un e-NCF ya emitido (trackId, estado,
 * codigo de seguridad, fecha de firma, montos, mensajes de rechazo). Para
 * rescatar un comprobante que la DGII recibio pero que no quedo en facturas.
 *
 *   GET /api/public/consultar_ecf_dgii.php
 *     token             = CERT_RUN_TOKEN (del .env)
 *     encf              = E340000000001[,E340000000002]
 *     rnc | tenant_id   = emisor (usa SU certificado y SU ambiente)
 *     [ambiente]        = fuerza ecf | testecf (los servicios no existen en certecf)
 *     [rnc_comprador]   = solo si la DGII lo exige en ConsultaEstado
 *     [codigo_seguridad]= idem
 *     [timeout]         = segundos por llamada (default 30)
 *     [formato]         = json -> responde el JSON (crudo + resumen) en vez de texto
 *
 * Solo lectura: no toca la base ni la secuencia e-NCF. Mismo token y mismo
 * patron que diagnostico_dgii.php.
 */

@set_time_limit(0);
@ignore_user_abort(true);

require_once __DIR__ . '/../src/Database.php';
Database::loadEnv();

$formatoJson = (($_REQUEST['formato'] ?? '') === 'json');
header('Content-Type: ' . ($formatoJson ? 'application/json' : 'text/plain') . '; charset=utf-8');

$expected = (string) (getenv('CERT_RUN_TOKEN') ?: ($_ENV['CERT_RUN_TOKEN'] ?? ''));
if ($expected === '') {
    http_response_code(403);
    exit("CERT_RUN_TOKEN no configurado en el .env del server.\n");
}
if (!hash_equals($expected, (string) ($_REQUEST['token'] ?? ''))) {
    http_response_code(403);
    exit("Token invalido. Use ?token=...\n");
}

require_once __DIR__ . '/../tools/consultar_ecf_dgii.php';

$encfs = cecfParsearEncfs((string) ($_REQUEST['encf'] ?? ''));
$rnc = preg_replace('/\D/', '', (string) ($_REQUEST['rnc'] ?? ''));
$tenantId = (int) ($_REQUEST['tenant_id'] ?? 0);
if ($encfs === [] || ($rnc === '' && $tenantId <= 0)) {
    http_response_code(400);
    $msg = 'Parametros: encf=E340000000001[,E340000000002] (E + 12 digitos) y rnc=<emisor> o tenant_id=<id>.';
    exit($formatoJson ? json_encode(['error' => $msg]) . "\n" : $msg . "\n");
}

if (!$formatoJson) {
    // Salida en vivo: tres llamadas a DGII por e-NCF pueden tardar.
    @ob_implicit_flush(true);
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    echo 'Consulta e-CF DGII — ' . date('Y-m-d H:i:s') . ' — host ' . php_uname('n') . "\n";
}

cecfEjecutar([
    'rnc'              => $rnc,
    'tenant_id'        => $tenantId,
    'encf'             => implode(',', $encfs),
    'ambiente'         => $_REQUEST['ambiente'] ?? '',
    'rnc_comprador'    => $_REQUEST['rnc_comprador'] ?? '',
    'codigo_seguridad' => $_REQUEST['codigo_seguridad'] ?? '',
    'timeout'          => $_REQUEST['timeout'] ?? 30,
    'json'             => '', // nunca una ruta de archivo que venga del request
    'solo_json'        => $formatoJson,
]);
