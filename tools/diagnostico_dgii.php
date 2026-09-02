<?php
/**
 * diagnostico_dgii.php — Aisla en que paso falla la comunicacion con DGII.
 *
 * El error de emision es siempre el mismo mensaje ("HTTP request failed: ...")
 * porque las tres llamadas comparten la capa HTTP de DgiiAuthService. Este
 * script las corre por separado, con tiempos, para saber CUAL se cae:
 *
 *   1. Entorno      : PHP, curl, OpenSSL, extensiones
 *   2. Tenant       : id, rnc, tipo, ambiente (el ambiente lo manda el tenant)
 *   3. Certificado  : lectura del .p12 (detecta legacy y clave incorrecta)
 *   4. GET  semilla : /{ambiente}/autenticacion/api/autenticacion/semilla
 *   5. Firma        : XMLDSig de la semilla con el cert del tenant
 *   6. POST validarsemilla : devuelve el token Bearer
 *
 * Es de solo lectura: NO emite comprobantes ni consume secuencias e-NCF.
 *
 * Uso:
 *   php tools/diagnostico_dgii.php --rnc=131111111
 *   php tools/diagnostico_dgii.php --tenant-id=7 --repeticiones=5
 *   php tools/diagnostico_dgii.php --ambiente=certecf      # cert global del .env
 *
 * --repeticiones=N repite solo el GET de semilla (la llamada mas barata) para
 * distinguir un corte intermitente de una falla sistematica.
 */

require_once __DIR__ . '/../src/MasterDatabase.php';
require_once __DIR__ . '/../src/TenantResolver.php';
require_once __DIR__ . '/../src/CertResolver.php';
require_once __DIR__ . '/../src/Utils/FacturacionElectronica/DgiiAuthService.php';

loadEnvFile(__DIR__ . '/../.env');

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Solo por CLI.\n");
}

$opts = getopt('', ['rnc::', 'tenant-id::', 'ambiente::', 'repeticiones::', 'timeout::']);
$rnc         = isset($opts['rnc']) ? preg_replace('/\D/', '', (string) $opts['rnc']) : '';
$tenantId    = isset($opts['tenant-id']) ? (int) $opts['tenant-id'] : 0;
$ambienteArg = isset($opts['ambiente']) ? strtolower(trim((string) $opts['ambiente'])) : '';
$repeticiones = max(1, (int) ($opts['repeticiones'] ?? 1));
$timeout     = max(1, (int) ($opts['timeout'] ?? 30));

$fallos = [];

// --- 1. Entorno --------------------------------------------------------------
linea('1. ENTORNO');
$curl = function_exists('curl_version') ? curl_version() : null;
info('PHP', PHP_VERSION);
info('curl', $curl ? ($curl['version'] . ' / ' . $curl['ssl_version']) : 'NO DISPONIBLE');
info('OpenSSL', defined('OPENSSL_VERSION_TEXT') ? OPENSSL_VERSION_TEXT : 'sin extension openssl');
info('Extensiones', implode(', ', array_map(
    fn($e) => $e . (extension_loaded($e) ? '=si' : '=NO'),
    ['curl', 'openssl', 'zip', 'dom']
)));
if (!extension_loaded('curl')) {
    // Sin curl, DgiiAuthService cae a streamRequest (allow_url_fopen): otro
    // stack TLS, otros sintomas. Vale la pena saberlo antes de seguir.
    aviso('Sin extension curl: las llamadas irian por stream/allow_url_fopen.');
}

// --- 2. Tenant ---------------------------------------------------------------
linea('2. TENANT');
$tenant = null;
if ($tenantId > 0 || $rnc !== '') {
    $ok = $tenantId > 0
        ? TenantResolver::resolveById($tenantId)
        : TenantResolver::resolveByRnc($rnc);
    if (!$ok) {
        fallo('No se resolvio el tenant (' . ($tenantId > 0 ? "id={$tenantId}" : "rnc={$rnc}") . ').');
        resumen($fallos);
        exit(1);
    }
    $tenant = TenantResolver::current();
    info('tenant', '#' . $tenant['id'] . ' ' . $tenant['nombre']);
    info('rnc / tipo', $tenant['rnc'] . ' / ' . ($tenant['tipo'] ?? '?'));
    info('ambiente', (string) ($tenant['ambiente'] ?? '(sin ambiente)'));
    info('activo', !empty($tenant['activo']) ? 'si' : 'NO');
} else {
    aviso('Sin --rnc/--tenant-id: se usa el certificado global del .env (single-tenant).');
}

// El ambiente efectivo: override del flag > el del tenant > el global del .env.
$ambiente = $ambienteArg !== ''
    ? $ambienteArg
    : (string) ($tenant['ambiente'] ?? getenv('DGII_ECF_ENVIRONMENT') ?: 'certecf');
info('ambiente a probar', $ambiente . ($ambienteArg !== '' ? ' (forzado por --ambiente)' : ''));

// --- 3. Certificado ----------------------------------------------------------
linea('3. CERTIFICADO');
$cert = null;
try {
    $cert = CertResolver::resolve();
    info('ruta', $cert['path']);
    info('tamano', number_format(strlen($cert['content'])) . ' bytes');
    info('clave', $cert['password'] !== '' ? 'presente (' . strlen($cert['password']) . ' chars)' : 'VACIA');

    $bag = [];
    if (openssl_pkcs12_read($cert['content'], $bag, $cert['password'])) {
        $info = openssl_x509_parse($bag['cert'] ?? '');
        info('lectura .p12', 'OK');
        info('sujeto', $info['subject']['CN'] ?? '?');
        $vence = isset($info['validTo_time_t']) ? date('Y-m-d', $info['validTo_time_t']) : '?';
        info('vence', $vence . (isset($info['validTo_time_t']) && $info['validTo_time_t'] < time() ? '  <-- VENCIDO' : ''));
    } else {
        $errores = [];
        while ($e = openssl_error_string()) {
            $errores[] = $e;
        }
        $texto = implode(' | ', $errores);
        fallo('No se pudo leer el .p12: ' . ($texto !== '' ? $texto : 'sin detalle'));
        // Los dos motivos tipicos, con su arreglo, para no tener que buscarlos.
        if (stripos($texto, 'unsupported') !== false || stripos($texto, 'digital envelope') !== false) {
            aviso('Cifrado legacy (RC2-40/3DES): hay que reconvertir el .p12 a AES-256.');
        } elseif (stripos($texto, 'mac verify') !== false) {
            aviso('La contrasena no corresponde: re-cifrarla con public/encrypt_credential.php.');
        }
    }
} catch (Throwable $e) {
    fallo('CertResolver: ' . $e->getMessage());
}

// --- 4. GET semilla ----------------------------------------------------------
linea('4. GET SEMILLA' . ($repeticiones > 1 ? " (x{$repeticiones})" : ''));
$auth = new DgiiAuthService();
$optsDgii = ['environment' => $ambiente, 'timeout' => $timeout];
if ($cert !== null) {
    $optsDgii['certificate_content'] = $cert['content'];
    $optsDgii['certificate_password'] = $cert['password'];
}

$semilla = null;
$okSemilla = 0;
for ($i = 1; $i <= $repeticiones; $i++) {
    $t0 = microtime(true);
    try {
        $r = $auth->obtenerSemilla($optsDgii);
        $semilla = $semilla ?? $r;
        $okSemilla++;
        info("intento $i", sprintf('OK  %.2fs  fecha=%s', microtime(true) - $t0, $r['fecha'] ?? '?'));
    } catch (Throwable $e) {
        info("intento $i", sprintf('FALLO  %.2fs  %s', microtime(true) - $t0, $e->getMessage()));
        $fallos[] = 'semilla: ' . $e->getMessage();
    }
}
if ($semilla !== null) {
    info('endpoint', $semilla['endpoint']);
}
if ($okSemilla === 0) {
    // Sin semilla no hay nada mas que probar: la firma y el token dependen de ella.
    resumen($fallos);
    exit(1);
}
if ($okSemilla < $repeticiones) {
    aviso("Intermitente: {$okSemilla}/{$repeticiones} intentos OK. Sintoma tipico de reset de DGII, no de configuracion.");
}

// --- 5. Firma de la semilla --------------------------------------------------
linea('5. FIRMA DE LA SEMILLA');
$firmada = null;
$t0 = microtime(true);
try {
    $firmada = $auth->firmarSemilla($semilla['xml'], $optsDgii);
    info('firma', sprintf('OK  %.2fs  %s bytes', microtime(true) - $t0, number_format(strlen($firmada))));
} catch (Throwable $e) {
    fallo('firma: ' . $e->getMessage());
    resumen($fallos);
    exit(1);
}

// --- 6. POST validarsemilla --------------------------------------------------
linea('6. POST VALIDARSEMILLA (token)');
$t0 = microtime(true);
try {
    $token = $auth->validarSemillaFirmada($firmada, $optsDgii);
    info('token', sprintf('OK  %.2fs', microtime(true) - $t0));
    info('expira', (string) ($token['expira'] ?? $token['expedido'] ?? '?'));
    info('ambiente', (string) ($token['ambiente'] ?? $ambiente));
} catch (Throwable $e) {
    fallo('validarsemilla: ' . $e->getMessage());
}

resumen($fallos);
exit($fallos === [] ? 0 : 1);

// -----------------------------------------------------------------------------

function linea(string $titulo): void
{
    fwrite(STDOUT, "\n== {$titulo} " . str_repeat('=', max(0, 60 - strlen($titulo))) . "\n");
}

function info(string $k, string $v): void
{
    fwrite(STDOUT, sprintf("   %-18s %s\n", $k . ':', $v));
}

function aviso(string $msg): void
{
    fwrite(STDOUT, "   !  {$msg}\n");
}

function fallo(string $msg): void
{
    global $fallos;
    $fallos[] = $msg;
    fwrite(STDOUT, "   X  {$msg}\n");
}

function resumen(array $fallos): void
{
    fwrite(STDOUT, "\n" . str_repeat('=', 64) . "\n");
    if ($fallos === []) {
        fwrite(STDOUT, "Todo OK: cert legible, semilla, firma y token. Si la emision igual\n"
            . "falla, el corte esta en el POST de recepcion del e-CF (payload grande),\n"
            . "no en el handshake.\n");
        return;
    }
    fwrite(STDOUT, count($fallos) . " fallo(s):\n");
    foreach ($fallos as $f) {
        fwrite(STDOUT, "  - {$f}\n");
    }
    fwrite(STDOUT, "\n'Connection reset by peer' en semilla/validarsemilla = corte de red\n"
        . "con DGII (no es firma ni token). Si es intermitente, reintentar; si es\n"
        . "sistematico, revisar salida TLS del server (curl/OpenSSL de arriba).\n");
}

function loadEnvFile(string $envFile): void
{
    if (!is_file($envFile)) {
        return;
    }
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);
        if (!array_key_exists($k, $_ENV)) {
            $_ENV[$k] = $v;
            putenv("{$k}={$v}");
        }
    }
}
