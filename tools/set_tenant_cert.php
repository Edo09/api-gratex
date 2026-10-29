<?php
/**
 * set_tenant_cert.php — Carga (o reserva) el certificado .p12 de un tenant que YA existe.
 *
 * Para el alta sin certificado: el tenant se crea con create_tenant.php / onboard.html sin
 * .p12, el cliente va llenando el sistema (productos, clientes...) y el certificado se
 * agrega aqui cuando llegue. Los dos modos:
 *
 *  - reservar : fija tenants.cert_path a <CERT_DIR>/<rnc>/cert.p12 SIN subir el archivo.
 *               create_tenant.php ya lo hace solo en el alta de un app sin .p12; esto es
 *               para los que se crearon antes de eso. Un tenant app con cert_path vacio
 *               firma con el certificado GLOBAL del .env (el de Gratex) en vez de fallar
 *               (CertResolver). Con la ruta reservada, emitir responde "No se encontro el
 *               certificado digital de tu empresa" antes de firmar o enviar nada.
 *               No usarlo en un tenant que firma A PROPOSITO con el cert del .env (Gratex,
 *               #1, o una empresa DEMO): quedaria sin poder emitir.
 *  - cargar   : valida el .p12 con su clave, lo guarda en <CERT_DIR>/<rnc>/cert.p12 y
 *               cifra la clave en tenants.cert_pass_encrypted (AES-256-GCM con la
 *               MASTER_ENCRYPTION_KEY del .env). Sirve igual para reemplazar el de un
 *               tenant que ya tenia uno. No toca tenants.ambiente: eso es un UPDATE aparte.
 *
 * El .p12 se abre con la clave ANTES de escribir nada: si este PHP no puede leerlo (clave
 * mala o cifrado legacy RC2-40) aborta sin tocar el archivo ni la base. Para convertir un
 * legacy: convertir_cert.ps1 (misma clave). Ver docs/integrations/alta-tenant-runbook.md.
 *
 * Identifica el tenant con --id o --rnc. --dry-run valida todo y no escribe.
 *
 * CLI (solo sirve donde el .env apunta a la MISMA master y MASTER_ENCRYPTION_KEY que el
 * server; la clave y el archivo tienen que quedar en el server):
 *   php tools/set_tenant_cert.php --id=6 --reservar
 *   php tools/set_tenant_cert.php --id=6 --cert-path=ruta/cert.p12 --cert-pass=CLAVE
 *
 * Web (hosting compartido sin shell): pantalla public/set_tenant_cert.html, que es lo que se
 * usa normalmente (abre el .p12 y convierte el legacy en el navegador antes de subirlo). Por
 * debajo llama al wrapper public/set_tenant_cert.php, solo POST, con
 * ONBOARD_TOKEN. Sin pantalla, el mismo POST con curl: el archivo sube en el campo `cert`; --form-string evita que curl
 * interprete @ , < o ; dentro del token o la clave. Desde PowerShell:
 *   curl.exe -A "Mozilla/5.0" https://gratex.net/api/public/set_tenant_cert.php `
 *     --form-string "token=ONBOARD_TOKEN" --form-string "id=6" --form-string "reservar=1"
 *   curl.exe -A "Mozilla/5.0" https://gratex.net/api/public/set_tenant_cert.php `
 *     --form-string "token=ONBOARD_TOKEN" --form-string "id=6" `
 *     --form-string "cert-pass=CLAVE" -F "cert=@C:\ruta\cert.p12"
 * Si el .p12 ya esta en <CERT_DIR>/<rnc>/cert.p12 (subido por el File Manager), basta
 * mandar la clave sin `cert`. Con cert-path el archivo se copia desde esa ruta del server.
 */

require_once __DIR__ . '/../src/MasterDatabase.php';
require_once __DIR__ . '/../src/TenantResolver.php';

loadEnvFile(__DIR__ . '/../.env');

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    // La clave del .p12 y el token viajan por POST: nunca en la URL ni en los access logs.
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        exit("Solo POST (el token y la clave del certificado no pueden ir en la URL).\n");
    }
    $onboardToken = (string) (getenv('ONBOARD_TOKEN') ?: ($_ENV['ONBOARD_TOKEN'] ?? ''));
    if ($onboardToken === '') {
        http_response_code(403);
        exit("ONBOARD_TOKEN no configurado en el .env del server.\n");
    }
    if (!hash_equals($onboardToken, (string) ($_POST['token'] ?? ''))) {
        http_response_code(403);
        exit("Token invalido.\n");
    }
}

if ($isCli) {
    $opts = getopt('', ['id::', 'rnc::', 'cert-path::', 'cert-pass::', 'reservar', 'dry-run']);
    if ($opts === false) {
        $opts = [];
    }
} else {
    $opts = $_POST;
    unset($opts['token']); // no es parametro del tenant
}

$reservar = flagActivo($opts, 'reservar');
$dryRun   = flagActivo($opts, 'dry-run');
$certPass = isset($opts['cert-pass']) ? (string) $opts['cert-pass'] : '';

// =============================================================================
// Tenant
// =============================================================================
$idArg  = isset($opts['id']) ? trim((string) $opts['id']) : '';
$rncArg = isset($opts['rnc']) ? preg_replace('/\D/', '', (string) $opts['rnc']) : '';
if ($idArg === '' && $rncArg === '') {
    fail('Falta --id=<tenant_id> o --rnc=<rnc>.');
}
if ($idArg !== '' && !ctype_digit($idArg)) {
    fail("--id invalido: '{$idArg}'.");
}

$master = MasterDatabase::getInstance()->getConnection();
$where  = [];
$params = [];
if ($idArg !== '') {
    $where[] = 'id = :id';
    $params[':id'] = (int) $idArg;
}
if ($rncArg !== '') {
    $where[] = 'rnc = :rnc';
    $params[':rnc'] = $rncArg;
}
$sel = $master->prepare(
    'SELECT id, nombre, rnc, tipo, ambiente, activo, cert_path
       FROM tenants WHERE ' . implode(' AND ', $where) . ' LIMIT 1'
);
$sel->execute($params);
$tenant = $sel->fetch();
if (!$tenant) {
    fail('No existe un tenant con ' . implode(' y ', array_map(
        fn($k) => ltrim($k, ':') . ' = ' . $params[$k],
        array_keys($params)
    )) . '.');
}

$tenantId   = (int) $tenant['id'];
$rnc        = (string) $tenant['rnc'];
$certActual = trim((string) ($tenant['cert_path'] ?? ''));
if (!preg_match('/^\d{9,11}$/', $rnc)) {
    // el RNC arma la ruta en disco: no se usa uno raro
    fail("El RNC del tenant #{$tenantId} no son 9-11 digitos ('{$rnc}'): corrijalo antes.");
}

// Misma carpeta que create_tenant.php; lo que importa es que coincida con tenants.cert_path.
$certDir = trim((string) (getenv('CERT_DIR') ?: 'certificado_dgii'), '/ ');
if ($certDir === '') {
    $certDir = 'certificado_dgii';
}
$certRel = $certDir . '/' . $rnc . '/cert.p12';
$certAbs = rutaAbsoluta($certRel);

echo "== Certificado del tenant #{$tenantId} '{$tenant['nombre']}' (RNC {$rnc}) ==\n";
echo '   tipo: ' . $tenant['tipo'] . ' | ambiente: ' . $tenant['ambiente']
    . ((int) $tenant['activo'] === 1 ? '' : ' | INACTIVO') . "\n";
echo '   cert_path actual: ' . ($certActual !== '' ? $certActual : '(ninguno: firmaria con el cert global del .env)') . "\n";
echo "   destino: {$certRel}" . ($dryRun ? '   [DRY-RUN: no se escribe nada]' : '') . "\n";

// =============================================================================
// Modo reservar: ruta fijada, archivo ausente
// =============================================================================
if ($reservar) {
    if ($certPass !== '' || isset($opts['cert-path']) || (!$isCli && isset($_FILES['cert']))) {
        fail('--reservar no lleva .p12 ni clave: es para fijar la ruta mientras llega el certificado.');
    }
    if ($certActual !== '') {
        fail("El tenant ya tiene cert_path = '{$certActual}'; reservar solo aplica a un tenant sin certificado.");
    }
    if ($dryRun) {
        echo "-> (dry-run) pondria tenants.cert_path = '{$certRel}'.\n";
        exit(0);
    }
    $upd = $master->prepare(
        "UPDATE tenants SET cert_path = :p WHERE id = :id AND (cert_path IS NULL OR cert_path = '')"
    );
    $upd->execute([':p' => $certRel, ':id' => $tenantId]);
    echo "-> tenants.cert_path = '{$certRel}' (sin archivo).\n";
    if (is_file($certAbs)) {
        echo "   OJO: ya hay un archivo en esa ruta; falta la clave: vuelva a correr con --cert-pass.\n";
    } else {
        echo "   Emitir un e-CF responde 'No se encontro el certificado digital de tu empresa' hasta que se cargue el .p12.\n";
    }
    echo "   Cuando llegue el certificado: set_tenant_cert con --id={$tenantId} y la clave (ver cabecera del script).\n";
    exit(0);
}

// =============================================================================
// Modo cargar: validar ANTES de escribir
// =============================================================================
if ($certPass === '') {
    fail('Falta --cert-pass (la clave del .p12).');
}

$upload = null; // web: archivo subido en el campo `cert`
if (!$isCli && isset($_FILES['cert']) && is_array($_FILES['cert'])) {
    $err = $_FILES['cert']['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_OK) {
        $upload = (string) $_FILES['cert']['tmp_name'];
    } elseif ($err !== UPLOAD_ERR_NO_FILE) {
        fail("No se pudo subir el .p12 (codigo de error de PHP: {$err}).");
    }
}
$origen = $upload ?? (isset($opts['cert-path']) && $opts['cert-path'] !== '' ? (string) $opts['cert-path'] : null);
if ($origen === null) {
    // Sin archivo nuevo: sirve el que ya se dejo en el destino (File Manager).
    if (!is_file($certAbs)) {
        fail("No hay .p12: suba el archivo (campo cert), pase --cert-path, o dejelo en {$certRel}.");
    }
    $origen = $certAbs;
    echo "-> Sin archivo nuevo: se usa el que ya esta en {$certRel}\n";
}
if (!is_file($origen) || !is_readable($origen)) {
    fail("cert-path no existe o no se puede leer: {$origen}");
}

$raw = (string) file_get_contents($origen);
$p12 = leerP12($raw, $certPass);
if ($p12 === null) {
    if (p12EsLegacy($raw)) {
        fail("El .p12 usa cifrado legacy (RC2-40) y este PHP/OpenSSL 3 no lo abre."
            . PHP_EOL . '   Convierta el archivo con convertir_cert.ps1 (misma clave) y vuelva a cargarlo.'
            . PHP_EOL . '   No se escribio nada.');
    }
    fail('La clave no abre este .p12 (o el archivo esta danado, o no trae la llave privada).'
        . PHP_EOL . '   No se escribio nada.');
}

$info = openssl_x509_parse($p12['cert']) ?: [];
$cn   = $info['subject']['CN'] ?? '?';
$cn   = is_array($cn) ? implode(' / ', $cn) : (string) $cn;
$desde = (int) ($info['validFrom_time_t'] ?? 0);
$hasta = (int) ($info['validTo_time_t'] ?? 0);
echo "-> .p12 abierto con la clave: {$cn}\n";
echo '   vigencia: ' . ($desde ? date('Y-m-d', $desde) : '?') . ' a ' . ($hasta ? date('Y-m-d', $hasta) : '?') . "\n";
if ($hasta > 0 && $hasta < time()) {
    echo "!! WARNING: el certificado esta VENCIDO. La DGII va a rechazar lo que se firme con el.\n";
}
if ($desde > time()) {
    echo "!! WARNING: el certificado todavia no esta vigente.\n";
}

// Mismo certificado en otro tenant o el global del .env: solo avisa, hay casos validos
// (la misma persona delegada para ambos RNC). Es el error tipico de arrastrar el .p12 de otro.
$fp = openssl_x509_fingerprint($p12['cert'], 'sha256') ?: null;
foreach (certsIguales($master, $tenantId, $fp) as $quien) {
    echo "!! WARNING: este .p12 es el mismo certificado que usa {$quien}.\n";
    echo "   Solo es valido si la misma persona esta delegada para ambos RNC en la DGII.\n";
}

// La clave tiene que poder cifrarse y descifrarse con la key de ESTE .env.
try {
    $passEnc = TenantResolver::encrypt($certPass);
    if (TenantResolver::decrypt($passEnc) !== $certPass) {
        throw new RuntimeException('el cifrado no devuelve la misma clave');
    }
} catch (Throwable $e) {
    fail('No se pudo cifrar la clave con MASTER_ENCRYPTION_KEY: ' . $e->getMessage());
}

if ($dryRun) {
    echo "-> (dry-run) guardaria el .p12 en {$certRel} y cifraria la clave en tenants.cert_pass_encrypted.\n";
    exit(0);
}

// =============================================================================
// Escritura: archivo (atomico) y luego la base; si la base falla se deshace el archivo
// =============================================================================
$mismoArchivo = is_file($certAbs) && realpath($origen) === realpath($certAbs);
$teniaPrevio  = is_file($certAbs);
$previo       = null;
if (!$mismoArchivo) {
    $destDir = dirname($certAbs);
    if (!is_dir($destDir) && !mkdir($destDir, 0700, true) && !is_dir($destDir)) {
        fail("No se pudo crear el directorio {$destDir}" . PHP_EOL
            . "   Cree la carpeta '{$certDir}/' en la raiz del API (o ajuste CERT_DIR en el .env)"
            . ' y dele permiso de escritura al usuario de PHP.');
    }
    if ($teniaPrevio) {
        $previo = file_get_contents($certAbs);
    }
    $tmp = $certAbs . '.nuevo';
    $ok  = $upload !== null ? move_uploaded_file($origen, $tmp) : copy($origen, $tmp);
    if (!$ok) {
        fail("No se pudo escribir {$tmp}.");
    }
    @chmod($tmp, 0600);
    if (!rename($tmp, $certAbs)) {
        @unlink($tmp);
        fail("No se pudo dejar el certificado en {$certAbs}.");
    }
    echo "-> certificado guardado en {$certRel}" . ($teniaPrevio ? " (reemplazo al anterior)" : '') . "\n";
}

try {
    $upd = $master->prepare('UPDATE tenants SET cert_path = :p, cert_pass_encrypted = :c WHERE id = :id');
    $upd->bindValue(':p', $certRel);
    $upd->bindValue(':c', $passEnc, PDO::PARAM_LOB);
    $upd->bindValue(':id', $tenantId, PDO::PARAM_INT);
    $upd->execute();
} catch (Throwable $e) {
    if (!$mismoArchivo) {
        if ($previo !== null) {
            file_put_contents($certAbs, $previo);
        } else {
            @unlink($certAbs);
        }
    }
    fail('No se pudo actualizar tenants (' . $e->getMessage() . '); el archivo quedo como estaba.');
}
echo "-> tenants.cert_path y cert_pass_encrypted actualizados.\n";

// Lo que va a hacer CertResolver en la proxima emision: leer la fila, descifrar, abrir el .p12.
$chk = $master->prepare('SELECT cert_path, cert_pass_encrypted FROM tenants WHERE id = :id');
$chk->execute([':id' => $tenantId]);
$fila = $chk->fetch();
$verificado = false;
try {
    $verificado = $fila
        && leerP12((string) @file_get_contents(rutaAbsoluta((string) $fila['cert_path'])),
            TenantResolver::decrypt((string) $fila['cert_pass_encrypted'])) !== null;
} catch (Throwable $e) {
    $verificado = false;
}
if (!$verificado) {
    fail("Quedo guardado pero la verificacion de lectura fallo: revise {$certRel} y cert_pass_encrypted del tenant #{$tenantId}.");
}
echo "-> verificado: el servidor abre el .p12 con la clave guardada (lo mismo que hace la firma).\n";

echo "\n== Listo ==\n";
if ((string) $tenant['ambiente'] === 'sandbox') {
    echo "  >> El tenant sigue en sandbox (firma con un cert descartable): ver docs/api/integracion.md#sandbox\n";
} else {
    echo "  >> Ambiente del tenant: {$tenant['ambiente']} (no se toco).\n";
    if ((string) $tenant['ambiente'] === 'certecf') {
        echo "     Al aprobar la DGII: UPDATE tenants SET ambiente = 'ecf' WHERE id = {$tenantId};\n";
    }
}
echo "  >> Probar la firma contra la DGII (no emite ni gasta e-NCF):\n";
echo "     https://<server>/api/public/diagnostico_dgii.html (token CERT_RUN_TOKEN, RNC {$rnc})\n";
echo "  >> Si el .p12 es de una persona fisica, la empresa debe delegarla en la Oficina Virtual de la DGII.\n";
echo "  >> Borre public/set_tenant_cert.php y public/set_tenant_cert.html del server cuando termine.\n";
exit(0);

// =============================================================================
// Helpers
// =============================================================================

function fail(string $msg): void
{
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "ERROR: {$msg}\n");
    } else {
        http_response_code(400);
        echo "ERROR: {$msg}\n";
    }
    exit(1);
}

/** Flag de CLI (--x, que getopt devuelve como false) o de formulario (x=1). */
function flagActivo(array $opts, string $key): bool
{
    if (!array_key_exists($key, $opts)) {
        return false;
    }
    $v = $opts[$key];
    return $v === false || !in_array(strtolower(trim((string) $v)), ['0', 'false', 'no'], true);
}

/** Igual que CertResolver::toAbsolute: absoluta tal cual, relativa desde la raiz del proyecto. */
function rutaAbsoluta(string $path): string
{
    if (preg_match('/^[A-Za-z]:[\\\\\/]/', $path) || str_starts_with($path, '/')) {
        return $path;
    }
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
}

/**
 * Abre el .p12 como lo hace DgiiXmlSigner. Null si la clave no lo abre, si OpenSSL 3 no
 * soporta su cifrado, o si no trae la llave privada que corresponde al certificado.
 * @return array{cert: string, pkey: string}|null
 */
function leerP12(string $raw, string $pass): ?array
{
    $ok = $raw !== '' && @openssl_pkcs12_read($raw, $certs, $pass);
    while (openssl_error_string() !== false) {
        // vaciar la cola de errores de OpenSSL: no tiene que contaminar la proxima llamada
    }
    if (!$ok || empty($certs['cert']) || empty($certs['pkey'])) {
        return null;
    }
    if (!@openssl_x509_check_private_key($certs['cert'], $certs['pkey'])) {
        return null;
    }
    return $certs;
}

/** RC2-40 (OID 1.2.840.113549.1.12.1.6) es lo unico que OpenSSL 3 no abre sin el provider legacy. */
function p12EsLegacy(string $raw): bool
{
    return strpos($raw, hex2bin('060a2a864886f70d010c0106')) !== false;
}

/**
 * Quienes ya usan este mismo certificado (huella sha256 del X509): otros tenants con
 * cert_path legible y el cert global del .env.
 * @return string[]
 */
function certsIguales(PDO $pdo, int $selfId, ?string $fingerprint): array
{
    if ($fingerprint === null) {
        return [];
    }
    $iguales = [];
    $rows = $pdo->prepare(
        "SELECT id, nombre, rnc, cert_path, cert_pass_encrypted FROM tenants
          WHERE id <> :id AND cert_path IS NOT NULL AND cert_path <> '' AND cert_pass_encrypted IS NOT NULL
          ORDER BY id"
    );
    $rows->execute([':id' => $selfId]);
    foreach ($rows as $t) {
        $path = rutaAbsoluta((string) $t['cert_path']);
        if (!is_file($path)) {
            continue; // ruta reservada sin archivo, o archivo movido
        }
        try {
            $otro = leerP12((string) file_get_contents($path), TenantResolver::decrypt((string) $t['cert_pass_encrypted']));
        } catch (Throwable $e) {
            continue;
        }
        if ($otro !== null && hash_equals($fingerprint, (string) openssl_x509_fingerprint($otro['cert'], 'sha256'))) {
            $iguales[] = "el tenant #{$t['id']} '{$t['nombre']}' (RNC {$t['rnc']})";
        }
    }

    $envPath = (string) (getenv('DGII_ECF_CERT_PATH') ?: ($_ENV['DGII_ECF_CERT_PATH'] ?? ''));
    $envPass = (string) (getenv('DGII_ECF_CERT_PASSWORD') ?: ($_ENV['DGII_ECF_CERT_PASSWORD'] ?? ''));
    if ($envPath !== '' && is_file(rutaAbsoluta($envPath))) {
        $global = leerP12((string) file_get_contents(rutaAbsoluta($envPath)), $envPass);
        if ($global !== null && hash_equals($fingerprint, (string) openssl_x509_fingerprint($global['cert'], 'sha256'))) {
            $iguales[] = 'el certificado global del .env (DGII_ECF_CERT_PATH, el de Gratex)';
        }
    }
    return $iguales;
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
