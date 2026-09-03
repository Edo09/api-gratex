<?php
/**
 * diagnostico_dgii.php — Wrapper web del diagnostico de comunicacion con DGII.
 *
 * El server de produccion es hosting compartido sin shell: los tools/ de CLI no
 * se pueden correr ahi. Esto sirve directo bajo /api/public/ (el .htaccess deja
 * pasar los archivos existentes de esa carpeta) y ejecuta el mismo diagnostico
 * DESDE EL SERVER, que es lo unico que responde "sale este server a DGII?".
 *
 *   GET /api/public/diagnostico_dgii.php
 *     token             = CERT_RUN_TOKEN (del .env)
 *     [rnc]             = RNC del tenant a probar (usa SU cert y SU ambiente)
 *     [tenant_id]       = alternativa a rnc
 *     [ambiente]        = fuerza certecf | ecf | testecf (default: el del tenant)
 *     [repeticiones]    = intentos del GET de semilla (default 1, para ver intermitencia)
 *     [timeout]         = segundos por llamada (default 20)
 *     [solo_conectividad] = 1 -> solo prueba alcance de endpoints, sin cert ni firma
 *
 * Solo lectura: no emite comprobantes ni consume secuencias e-NCF.
 */

header('Content-Type: text/plain; charset=utf-8');
@set_time_limit(0);
@ignore_user_abort(true);

// Salida en vivo: el diagnostico puede tardar (timeouts de DGII) y conviene ver
// el progreso en vez de esperar a que termine.
@ob_implicit_flush(true);
while (ob_get_level() > 0) { @ob_end_flush(); }

require_once __DIR__ . '/../src/Database.php';
Database::loadEnv();

$expected = (string) (getenv('CERT_RUN_TOKEN') ?: ($_ENV['CERT_RUN_TOKEN'] ?? ''));
if ($expected === '') {
    http_response_code(403);
    exit("CERT_RUN_TOKEN no configurado en el .env del server.\n");
}
if (!hash_equals($expected, (string) ($_REQUEST['token'] ?? ''))) {
    http_response_code(403);
    exit("Token invalido. Use ?token=...\n");
}

require_once __DIR__ . '/../tools/diagnostico_dgii.php';

echo "Diagnostico DGII — " . date('Y-m-d H:i:s') . " — host " . php_uname('n') . "\n";

ejecutarDiagnostico([
    'rnc'               => $_REQUEST['rnc'] ?? '',
    'tenant_id'         => $_REQUEST['tenant_id'] ?? 0,
    'ambiente'          => $_REQUEST['ambiente'] ?? '',
    'repeticiones'      => $_REQUEST['repeticiones'] ?? 1,
    'timeout'           => $_REQUEST['timeout'] ?? 20,
    'solo_conectividad' => !empty($_REQUEST['solo_conectividad']),
]);
