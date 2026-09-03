<?php
/**
 * diagnostico_dgii.php — Aisla en que paso falla la comunicacion con DGII.
 *
 * El error de emision es siempre el mismo mensaje ("HTTP request failed: ...")
 * porque las tres llamadas comparten la capa HTTP de DgiiAuthService. Esto las
 * corre por separado, con tiempos, para saber CUAL se cae:
 *
 *   0. Conectividad : alcanza el server los endpoints de DGII? (por ambiente)
 *   1. Entorno      : PHP, curl, OpenSSL, extensiones
 *   2. Tenant       : id, rnc, tipo, ambiente efectivo
 *   3. Certificado  : lectura del .p12 (detecta legacy y clave incorrecta)
 *   4. GET  semilla : /{ambiente}/autenticacion/api/autenticacion/semilla
 *   5. Firma        : XMLDSig de la semilla con el cert del tenant
 *   6. POST validarsemilla : devuelve el token Bearer
 *
 * Es de solo lectura: NO emite comprobantes ni consume secuencias e-NCF.
 *
 * En el SERVER (hosting compartido, sin shell) esto se corre por navegador:
 *   https://gratex.net/api/public/diagnostico_dgii.php?token=<CERT_RUN_TOKEN>&rnc=<rnc>
 * Ese wrapper incluye este archivo y llama a ejecutarDiagnostico().
 *
 * Por CLI (desde tu maquina, mide TU salida a internet, no la del server):
 *   php tools/diagnostico_dgii.php --rnc=131111111 --repeticiones=5
 *   php tools/diagnostico_dgii.php --solo-conectividad
 */

require_once __DIR__ . '/../src/MasterDatabase.php';
require_once __DIR__ . '/../src/TenantResolver.php';
require_once __DIR__ . '/../src/CertResolver.php';
require_once __DIR__ . '/../src/Utils/FacturacionElectronica/DgiiAuthService.php';

const DIAG_BASE_URL = 'https://ecf.dgii.gov.do';
const DIAG_FC_BASE_URL = 'https://fc.dgii.gov.do';

/**
 * @param array $o rnc, tenant_id, ambiente, repeticiones, timeout, solo_conectividad
 * @return int 0 si no hubo fallos, 1 si los hubo
 */
function ejecutarDiagnostico(array $o): int
{
    $rnc         = isset($o['rnc']) ? preg_replace('/\D/', '', (string) $o['rnc']) : '';
    $tenantId    = (int) ($o['tenant_id'] ?? 0);
    $ambienteArg = strtolower(trim((string) ($o['ambiente'] ?? '')));
    $repeticiones = max(1, (int) ($o['repeticiones'] ?? 1));
    $timeout     = max(1, (int) ($o['timeout'] ?? 20));
    $soloConect  = !empty($o['solo_conectividad']);

    $fallos = [];

    // --- 1. Entorno ----------------------------------------------------------
    diagLinea('1. ENTORNO');
    $curl = function_exists('curl_version') ? curl_version() : null;
    diagInfo('PHP', PHP_VERSION);
    diagInfo('curl', $curl ? ($curl['version'] . ' / ' . $curl['ssl_version']) : 'NO DISPONIBLE');
    diagInfo('OpenSSL', defined('OPENSSL_VERSION_TEXT') ? OPENSSL_VERSION_TEXT : 'sin extension openssl');
    diagInfo('Extensiones', implode(', ', array_map(
        fn($e) => $e . (extension_loaded($e) ? '=si' : '=NO'),
        ['curl', 'openssl', 'zip', 'dom']
    )));
    if (!extension_loaded('curl')) {
        diagAviso('Sin extension curl: las llamadas irian por stream/allow_url_fopen (otro stack TLS).');
    }

    // --- 2. Tenant -----------------------------------------------------------
    diagLinea('2. TENANT');
    $tenant = null;
    if ($tenantId > 0 || $rnc !== '') {
        $ok = $tenantId > 0
            ? TenantResolver::resolveById($tenantId)
            : TenantResolver::resolveByRnc($rnc);
        if (!$ok) {
            diagFallo($fallos, 'No se resolvio el tenant (' . ($tenantId > 0 ? "id={$tenantId}" : "rnc={$rnc}") . ').');
        } else {
            $tenant = TenantResolver::current();
            diagInfo('tenant', '#' . $tenant['id'] . ' ' . $tenant['nombre']);
            diagInfo('rnc / tipo', $tenant['rnc'] . ' / ' . ($tenant['tipo'] ?? '?'));
            diagInfo('tenants.ambiente', (string) ($tenant['ambiente'] ?? '(vacio)'));
            diagInfo('activo', !empty($tenant['activo']) ? 'si' : 'NO');
        }
    } else {
        diagAviso('Sin rnc/tenant_id: se usa el certificado global del .env.');
    }

    // Ambiente efectivo. Sin tenant resuelto se cae al global del .env, que es
    // justo el modo en que una emision "de certecf" termina saliendo a produccion.
    $envGlobal = (string) (getenv('DGII_ECF_ENVIRONMENT') ?: ($_ENV['DGII_ECF_ENVIRONMENT'] ?? ''));
    diagInfo('DGII_ECF_ENVIRONMENT', $envGlobal !== '' ? $envGlobal : '(sin definir)');
    $ambiente = $ambienteArg !== ''
        ? $ambienteArg
        : ((string) ($tenant['ambiente'] ?? '') ?: ($envGlobal ?: 'certecf'));
    diagInfo('ambiente efectivo', $ambiente
        . ($ambienteArg !== '' ? ' (forzado)' : ($tenant !== null && !empty($tenant['ambiente']) ? ' (del tenant)' : ' (del .env global)')));

    // --- 0. Conectividad -----------------------------------------------------
    // Lo primero que hay que saber en un hosting compartido: sale este server a
    // DGII? Un 405/404 cuenta como ALCANZABLE (el endpoint existe y contesta;
    // solo rechaza el metodo GET). El 000 es el corte de conexion.
    diagLinea('0. CONECTIVIDAD DESDE ESTE SERVER');
    $rutas = [
        ['Autenticacion (semilla)', DIAG_BASE_URL . "/{$ambiente}/autenticacion/api/autenticacion/semilla", [200]],
        ['Recepcion e-CF',          DIAG_BASE_URL . "/{$ambiente}/Recepcion/api/FacturasElectronicas", [405, 401, 400]],
        ['Aprobacion Comercial',    DIAG_BASE_URL . "/{$ambiente}/AprobacionComercial/api/AprobacionComercial", [405, 401, 400]],
        ['RecepcionFC (E32 <250k)', DIAG_FC_BASE_URL . "/{$ambiente}/RecepcionFC/api/recepcion/ecf", [405, 404, 401, 400]],
    ];
    foreach ($rutas as [$nombre, $url, $esperados]) {
        $r = diagProbe($url, $timeout);
        $alcanzable = $r['code'] > 0;
        diagInfo($nombre, $alcanzable
            ? ('HTTP ' . $r['code'] . (in_array($r['code'], $esperados, true) ? '  (alcanzable)' : '  (respuesta inesperada)'))
            : ('SIN RESPUESTA — ' . $r['error']));
        if (!$alcanzable) {
            $fallos[] = $nombre . ': ' . $r['error'];
        }
    }
    // Los otros ambientes, solo autenticacion: distingue "DGII caido" de "este
    // server no sale a internet".
    foreach (['certecf', 'ecf', 'testecf'] as $otro) {
        if ($otro === $ambiente) {
            continue;
        }
        $r = diagProbe(DIAG_BASE_URL . "/{$otro}/autenticacion/api/autenticacion/semilla", $timeout);
        diagInfo("(ref) auth {$otro}", $r['code'] > 0 ? ('HTTP ' . $r['code']) : ('SIN RESPUESTA — ' . $r['error']));
    }

    if ($soloConect) {
        return diagResumen($fallos);
    }

    // --- 3. Certificado ------------------------------------------------------
    diagLinea('3. CERTIFICADO');
    $cert = null;
    try {
        $cert = CertResolver::resolve();
        diagInfo('ruta', $cert['path']);
        diagInfo('tamano', number_format(strlen($cert['content'])) . ' bytes');
        diagInfo('clave', $cert['password'] !== '' ? 'presente (' . strlen($cert['password']) . ' chars)' : 'VACIA');

        $bag = [];
        if (openssl_pkcs12_read($cert['content'], $bag, $cert['password'])) {
            $info = openssl_x509_parse($bag['cert'] ?? '');
            diagInfo('lectura .p12', 'OK');
            diagInfo('sujeto', $info['subject']['CN'] ?? '?');
            $vence = isset($info['validTo_time_t']) ? date('Y-m-d', $info['validTo_time_t']) : '?';
            diagInfo('vence', $vence . (isset($info['validTo_time_t']) && $info['validTo_time_t'] < time() ? '  <-- VENCIDO' : ''));
        } else {
            $errores = [];
            while ($e = openssl_error_string()) {
                $errores[] = $e;
            }
            $texto = implode(' | ', $errores);
            diagFallo($fallos, 'No se pudo leer el .p12: ' . ($texto !== '' ? $texto : 'sin detalle'));
            if (stripos($texto, 'unsupported') !== false || stripos($texto, 'digital envelope') !== false) {
                diagAviso('Cifrado legacy (RC2-40/3DES): hay que reconvertir el .p12 a AES-256.');
            } elseif (stripos($texto, 'mac verify') !== false) {
                diagAviso('La contrasena no corresponde: re-cifrarla con public/encrypt_credential.php.');
            }
        }
    } catch (Throwable $e) {
        diagFallo($fallos, 'CertResolver: ' . $e->getMessage());
    }

    // --- 4. GET semilla ------------------------------------------------------
    diagLinea('4. GET SEMILLA' . ($repeticiones > 1 ? " (x{$repeticiones})" : ''));
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
            diagInfo("intento $i", sprintf('OK  %.2fs  fecha=%s', microtime(true) - $t0, $r['fecha'] ?? '?'));
        } catch (Throwable $e) {
            diagInfo("intento $i", sprintf('FALLO  %.2fs  %s', microtime(true) - $t0, $e->getMessage()));
            $fallos[] = 'semilla: ' . $e->getMessage();
        }
    }
    if ($semilla !== null) {
        diagInfo('endpoint', $semilla['endpoint']);
    }
    if ($okSemilla === 0) {
        return diagResumen($fallos);
    }
    if ($okSemilla < $repeticiones) {
        diagAviso("Intermitente: {$okSemilla}/{$repeticiones} OK. Sintoma de reset de DGII, no de configuracion.");
    }

    // --- 5. Firma ------------------------------------------------------------
    diagLinea('5. FIRMA DE LA SEMILLA');
    $t0 = microtime(true);
    try {
        $firmada = $auth->firmarSemilla($semilla['xml'], $optsDgii);
        diagInfo('firma', sprintf('OK  %.2fs  %s bytes', microtime(true) - $t0, number_format(strlen($firmada))));
    } catch (Throwable $e) {
        diagFallo($fallos, 'firma: ' . $e->getMessage());
        return diagResumen($fallos);
    }

    // --- 6. POST validarsemilla ---------------------------------------------
    diagLinea('6. POST VALIDARSEMILLA (token)');
    $t0 = microtime(true);
    try {
        $token = $auth->validarSemillaFirmada($firmada, $optsDgii);
        diagInfo('token', sprintf('OK  %.2fs', microtime(true) - $t0));
        diagInfo('expira', (string) ($token['expira'] ?? $token['expedido'] ?? '?'));
    } catch (Throwable $e) {
        diagFallo($fallos, 'validarsemilla: ' . $e->getMessage());
    }

    return diagResumen($fallos);
}

/** GET simple. code=0 => no hubo respuesta (reset/timeout); error trae el motivo. */
function diagProbe(string $url, int $timeout): array
{
    if (!function_exists('curl_init')) {
        $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => $timeout, 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            $e = error_get_last();
            return ['code' => 0, 'error' => trim((string) ($e['message'] ?? 'sin respuesta'))];
        }
        $code = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $code = (int) $m[1];
            }
        }
        return ['code' => $code, 'error' => ''];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_NOBODY => false,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => ['Accept: */*'],
    ]);
    if (defined('CURLOPT_SSL_OPTIONS') && defined('CURLSSLOPT_NATIVE_CA')) {
        curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
    }
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = $raw === false ? curl_error($ch) : '';
    if (PHP_VERSION_ID < 80500) {
        curl_close($ch);
    }
    return ['code' => $code, 'error' => $err !== '' ? $err : ($code === 0 ? 'sin respuesta' : '')];
}

function diagLinea(string $titulo): void
{
    diagOut("\n== {$titulo} " . str_repeat('=', max(0, 58 - strlen($titulo))) . "\n");
}

function diagInfo(string $k, string $v): void
{
    diagOut(sprintf("   %-24s %s\n", $k . ':', $v));
}

function diagAviso(string $msg): void
{
    diagOut("   !  {$msg}\n");
}

function diagFallo(array &$fallos, string $msg): void
{
    $fallos[] = $msg;
    diagOut("   X  {$msg}\n");
}

function diagResumen(array $fallos): int
{
    diagOut("\n" . str_repeat('=', 62) . "\n");
    if ($fallos === []) {
        diagOut("Todo OK: este server alcanza DGII, el cert se lee, y semilla/firma/token\n"
            . "funcionan. Si la emision igual falla, el corte esta en el POST del e-CF\n"
            . "(payload grande), no en el handshake.\n");
        return 0;
    }
    diagOut(count($fallos) . " fallo(s):\n");
    foreach ($fallos as $f) {
        diagOut("  - {$f}\n");
    }
    diagOut("\nComo leerlo:\n"
        . "  - Falla UN ambiente y los (ref) de otros responden -> es DGII, no el server.\n"
        . "  - Fallan TODOS los ambientes -> este server no sale a DGII (IP/firewall del hosting).\n"
        . "  - Conectividad OK pero falla semilla/validarsemilla -> corte intermitente: reintentar.\n");
    return 1;
}

function diagOut(string $s): void
{
    if (PHP_SAPI === 'cli') {
        fwrite(STDOUT, $s);
        return;
    }
    echo $s;
    @flush();
}

// Entrada CLI. Por web entra por public/diagnostico_dgii.php, que incluye este
// archivo y llama a ejecutarDiagnostico() con los parametros de la peticion.
if (PHP_SAPI === 'cli' && isset($argv) && realpath($argv[0]) === realpath(__FILE__)) {
    $o = getopt('', ['rnc::', 'tenant-id::', 'ambiente::', 'repeticiones::', 'timeout::', 'solo-conectividad']);
    loadEnvFileDiag(__DIR__ . '/../.env');
    exit(ejecutarDiagnostico([
        'rnc' => $o['rnc'] ?? '',
        'tenant_id' => $o['tenant-id'] ?? 0,
        'ambiente' => $o['ambiente'] ?? '',
        'repeticiones' => $o['repeticiones'] ?? 1,
        'timeout' => $o['timeout'] ?? 20,
        'solo_conectividad' => isset($o['solo-conectividad']),
    ]));
}

function loadEnvFileDiag(string $envFile): void
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
