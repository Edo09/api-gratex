<?php
/**
 * create_tenant.php — Onboarding CLI para un cliente (tenant) nuevo.
 *
 * Dos tipos (--tipo):
 *  - app          : DB-per-tenant. Crea DB, corre schema + migrations, configura
 *                   emisor_config, opcionalmente crea usuario admin. (default)
 *  - integracion  : SIN DB propia. Solo registra key+secret + cert para firmar.
 *                   Los e-CF generados se guardan en gratex_master.ecf_integracion_backup.
 *                   El cliente manda el eNCF en el JSON y maneja sus facturas en su sistema.
 *
 * Imprime API KEY + API SECRET (el secret se muestra UNA sola vez).
 * Ver: docs/multi-emisor-master-db-prd.md
 *
 * Uso integracion (PowerShell):
 *   php tools/create_tenant.php --tipo=integracion `
 *     --nombre="Cliente A SRL" --rnc=131111111 `
 *     --cert-path=ruta/al/cert.p12 --cert-pass=claveCert [--ambiente=ecf]
 *
 * Uso app:
 *   php tools/create_tenant.php --tipo=app `
 *     --nombre="Cliente B SRL" --rnc=132222222 `
 *     --db-name=tenant_clienteB --db-user=clienteB_user --db-pass=secreto `
 *     --razon-social="CLIENTE B SRL" --direccion="Av. X #1" `
 *     [--admin-email=admin@b.com --admin-pass=clave1234 --admin-name="Admin B" --admin-username=admin_b] `
 *     [--cert-path=... --cert-pass=...] [--db-host=localhost] [--skip-create-db]
 *
 * MySQL admin (privilegio CREATE DATABASE, solo tipo app): ADMIN_DB_USER/PASS del
 * .env, o si faltan MASTER_DB_USER/PASS. En hosting compartido usar --skip-create-db.
 */

// === Token para ejecucion por navegador (editar antes de usar) =============
const ONBOARD_TOKEN = 'gratextoken.';
// ===========================================================================

require_once __DIR__ . '/../src/MasterDatabase.php';
require_once __DIR__ . '/../src/TenantResolver.php';
require_once __DIR__ . '/../src/Utils/TokenGenerator.php';

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    if (ONBOARD_TOKEN === 'CAMBIA_ESTE_TOKEN_DE_ONBOARDING') {
        http_response_code(403);
        exit("Configura ONBOARD_TOKEN en el archivo antes de usarlo.\n");
    }
    if (!hash_equals(ONBOARD_TOKEN, (string) ($_REQUEST['token'] ?? ''))) {
        http_response_code(403);
        exit("Token invalido. Use ?token=...\n");
    }
}

loadEnvFile(__DIR__ . '/../.env');

// --- Parametros: CLI (--flag) o navegador (?param) ---------------------------
if ($isCli) {
    $opts = getopt('', [
        'tipo::',
        'nombre:', 'rnc:',
        'db-name::', 'db-user::', 'db-pass::', 'db-host::', 'db-port::',
        'razon-social::', 'direccion::', 'ambiente::',
        'cert-path::', 'cert-pass::',
        'webhook-url::', 'webhook-secret::',
        'admin-email::', 'admin-pass::', 'admin-name::', 'admin-username::',
        'skip-create-db',
    ]);
} else {
    $opts = $_REQUEST; // GET o POST (usa POST para cert-pass y no exponerlo en logs)
    unset($opts['token']); // no es parametro de tenant
}

$tipo = strtolower((string) ($opts['tipo'] ?? 'app'));
if (!in_array($tipo, ['app', 'integracion'], true)) {
    fail("--tipo invalido: '{$tipo}'. Use 'app' o 'integracion'.");
}

// Requeridos comunes + por tipo
$required = ['nombre', 'rnc'];
if ($tipo === 'app') {
    $required = array_merge($required, ['db-name', 'db-user', 'db-pass']);
} else { // integracion: cert obligatorio para poder firmar
    $required = array_merge($required, ['cert-pass']);
}
$missing = array_filter($required, fn($k) => !isset($opts[$k]) || $opts[$k] === '');
if ($missing) {
    fail('Faltan argumentos requeridos para tipo ' . $tipo . ': --' . implode(', --', $missing));
}

$nombre       = (string) $opts['nombre'];
$rnc          = preg_replace('/\D/', '', (string) $opts['rnc']); // solo digitos
$ambiente     = (string) ($opts['ambiente'] ?? 'ecf');
$certPass     = isset($opts['cert-pass']) ? (string) $opts['cert-pass'] : null;

// Cert: archivo subido (web, $_FILES['cert']) o ruta en el server (cert-path).
$certUploadTmp = (!$isCli && isset($_FILES['cert']) && is_array($_FILES['cert'])
    && ($_FILES['cert']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK)
    ? $_FILES['cert']['tmp_name'] : null;
$certIsUpload = $certUploadTmp !== null;
$certPathArg  = $certIsUpload ? $certUploadTmp : (isset($opts['cert-path']) ? (string) $opts['cert-path'] : null);
if ($tipo === 'integracion' && ($certPathArg === null || $certPathArg === '')) {
    fail('Integracion requiere el certificado .p12 (sube el archivo o pasa cert-path).');
}

// Webhook (opcional, integracion): si se da URL sin secret, generamos uno.
$webhookUrl    = isset($opts['webhook-url']) ? (string) $opts['webhook-url'] : null;
$webhookSecret = isset($opts['webhook-secret']) ? (string) $opts['webhook-secret'] : null;
if ($webhookUrl !== null && $webhookUrl !== '' && ($webhookSecret === null || $webhookSecret === '')) {
    $webhookSecret = TokenGenerator::generateApiToken(48);
}

// Solo aplican a tipo app
$dbName       = isset($opts['db-name']) ? (string) $opts['db-name'] : null;
$dbUser       = isset($opts['db-user']) ? (string) $opts['db-user'] : null;
$dbPass       = isset($opts['db-pass']) ? (string) $opts['db-pass'] : null;
$dbHost       = (string) ($opts['db-host'] ?? 'localhost');
$dbPort       = (string) ($opts['db-port'] ?? '3306');
$razonSocial  = (string) ($opts['razon-social'] ?? $nombre);
$direccion    = (string) ($opts['direccion'] ?? 'N/D');
$skipCreateDb = isset($opts['skip-create-db']);

// Usuario admin (solo app, opcional). Si se pasa uno, se exigen los cuatro.
$adminEmail = isset($opts['admin-email']) ? (string) $opts['admin-email'] : null;
$adminPass  = isset($opts['admin-pass']) ? (string) $opts['admin-pass'] : null;
$adminName  = isset($opts['admin-name']) ? (string) $opts['admin-name'] : null;
$adminUser  = isset($opts['admin-username']) ? (string) $opts['admin-username'] : null;
$createAdmin = $tipo === 'app'
    && ($adminEmail !== null || $adminPass !== null || $adminName !== null || $adminUser !== null);

if (!preg_match('/^\d{9,11}$/', $rnc)) {
    fail("RNC invalido (esperado 9-11 digitos): {$rnc}");
}
if ($createAdmin) {
    if (in_array(null, [$adminEmail, $adminPass, $adminName, $adminUser], true)) {
        fail('Para crear usuario admin se requieren los 4: --admin-email --admin-pass --admin-name --admin-username');
    }
    if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        fail("admin-email invalido: {$adminEmail}");
    }
}

echo "== Onboarding tenant ({$tipo}): {$nombre} (RNC {$rnc}) ==\n";

// =============================================================================
// Tipo APP: crear DB + schema + migrations + emisor_config
// =============================================================================
if ($tipo === 'app') {
    $adminUserDb = getenv('ADMIN_DB_USER') ?: (getenv('MASTER_DB_USER') ?: '');
    $adminPassDb = getenv('ADMIN_DB_PASS') ?: (getenv('MASTER_DB_PASS') ?: '');

    if (!$skipCreateDb) {
        echo "-> Creando base de datos {$dbName} ...\n";
        $adminPdo = new PDO(
            "mysql:host={$dbHost}:{$dbPort};charset=utf8mb4",
            $adminUserDb, $adminPassDb,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $adminPdo->exec(
            "CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        );
        $adminPdo = null;
    } else {
        echo "-> --skip-create-db: se asume que {$dbName} ya existe.\n";
    }

    $tenantPdo = new PDO(
        "mysql:host={$dbHost}:{$dbPort};dbname={$dbName};charset=utf8mb4",
        $adminUserDb, $adminPassDb,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    echo "-> Aplicando tenant_schema.sql ...\n";
    runSqlFile($tenantPdo, __DIR__ . '/../db/tenant_schema.sql');

    $migrations = glob(__DIR__ . '/../db/migrations/*.sql');
    sort($migrations); // 001..010 (zero-padded)
    foreach ($migrations as $file) {
        echo '   migration ' . basename($file) . "\n";
        runSqlFile($tenantPdo, $file);
    }

    echo "-> Configurando emisor_config ...\n";
    $stmt = $tenantPdo->prepare(
        'UPDATE emisor_config
            SET rnc = :rnc, razon_social = :rs, nombre_comercial = :nc, direccion = :dir
          WHERE id = 1'
    );
    $stmt->execute([':rnc' => $rnc, ':rs' => $razonSocial, ':nc' => $nombre, ':dir' => $direccion]);
}

// =============================================================================
// Certificado (.p12): requerido en integracion, opcional en app
// =============================================================================
$certPathRel = null;
if ($certPathArg !== null && $certPathArg !== '') {
    if (!is_file($certPathArg)) {
        fail("cert-path no existe: {$certPathArg}");
    }
    $destDir = __DIR__ . '/../certificado_dgii/' . $rnc;
    if (!is_dir($destDir) && !mkdir($destDir, 0700, true) && !is_dir($destDir)) {
        fail("No se pudo crear directorio de certificado: {$destDir}");
    }
    $dest = $destDir . '/cert.p12';
    $ok = $certIsUpload ? move_uploaded_file($certPathArg, $dest) : copy($certPathArg, $dest);
    if (!$ok) {
        fail("No se pudo guardar el certificado en {$dest}");
    }
    $certPathRel = 'certificado_dgii/' . $rnc . '/cert.p12';
    echo "   certificado guardado en {$certPathRel}\n";
}

// =============================================================================
// Cifrado de credenciales
// =============================================================================
$dbPassEnc      = ($tipo === 'app' && $dbPass !== null) ? TenantResolver::encrypt($dbPass) : null;
$certPassEnc    = ($certPass !== null && $certPass !== '') ? TenantResolver::encrypt($certPass) : null;
$webhookSecEnc  = ($webhookSecret !== null && $webhookSecret !== '') ? TenantResolver::encrypt($webhookSecret) : null;

// =============================================================================
// Registrar tenant en gratex_master
// =============================================================================
echo "-> Registrando tenant en gratex_master ...\n";
$master = MasterDatabase::getInstance()->getConnection();
$apiKey        = TokenGenerator::generateApiToken(32); // identificador publico
$apiSecret     = TokenGenerator::generateApiToken(64); // secret — se muestra 1 vez
$apiSecretHash = TenantResolver::hashSecret($apiSecret);

$ins = $master->prepare(
    'INSERT INTO tenants
        (nombre, rnc, api_key, api_secret_hash, tipo, db_host, db_port, db_name, db_user,
         db_pass_encrypted, cert_path, cert_pass_encrypted, webhook_url, webhook_secret_encrypted,
         ambiente, activo)
     VALUES
        (:nombre, :rnc, :api_key, :api_secret_hash, :tipo, :db_host, :db_port, :db_name, :db_user,
         :db_pass_enc, :cert_path, :cert_pass_enc, :webhook_url, :webhook_sec_enc,
         :ambiente, 1)'
);
$ins->bindValue(':nombre', $nombre);
$ins->bindValue(':rnc', $rnc);
$ins->bindValue(':api_key', $apiKey);
$ins->bindValue(':api_secret_hash', $apiSecretHash);
$ins->bindValue(':tipo', $tipo);
$ins->bindValue(':db_host', $tipo === 'app' ? $dbHost : null, $tipo === 'app' ? PDO::PARAM_STR : PDO::PARAM_NULL);
$ins->bindValue(':db_port', $tipo === 'app' ? $dbPort : null, $tipo === 'app' ? PDO::PARAM_STR : PDO::PARAM_NULL);
$ins->bindValue(':db_name', $dbName, $dbName === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
$ins->bindValue(':db_user', $dbUser, $dbUser === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
$ins->bindValue(':db_pass_enc', $dbPassEnc, $dbPassEnc === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
$ins->bindValue(':cert_path', $certPathRel, $certPathRel === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
$ins->bindValue(':cert_pass_enc', $certPassEnc, $certPassEnc === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
$ins->bindValue(':webhook_url', $webhookUrl, $webhookUrl === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
$ins->bindValue(':webhook_sec_enc', $webhookSecEnc, $webhookSecEnc === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
$ins->bindValue(':ambiente', $ambiente);
$ins->execute();
$tenantId = (int) $master->lastInsertId();

// =============================================================================
// Usuario admin (solo app, opcional)
// =============================================================================
if ($createAdmin) {
    echo "-> Creando usuario admin ...\n";
    $nameParts = explode(' ', trim($adminName), 2);
    $firstName = $nameParts[0];
    $lastName  = $nameParts[1] ?? '';
    $pwdHash   = password_hash($adminPass, PASSWORD_BCRYPT, ['cost' => 10]);

    $uins = $master->prepare(
        'INSERT INTO users (tenant_id, name, last_name, email, username, password, role)
         VALUES (:tenant_id, :name, :last_name, :email, :username, :password, :role)'
    );
    $uins->execute([
        ':tenant_id' => $tenantId, ':name' => $firstName, ':last_name' => $lastName,
        ':email' => $adminEmail, ':username' => $adminUser, ':password' => $pwdHash, ':role' => 'admin',
    ]);
}

// =============================================================================
// Logo (opcional, web): se guarda en logos/<tenant_id>.<ext> para la Representacion Impresa
// =============================================================================
if (!$isCli && isset($_FILES['logo']) && is_array($_FILES['logo'])
    && ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
    $logoRes = saveTenantLogo($tenantId, $_FILES['logo']);
    echo "-> {$logoRes['msg']}\n";
    if ($logoRes['path'] !== null) {
        $master->prepare('UPDATE tenants SET logo_path = :p WHERE id = :id')
               ->execute([':p' => $logoRes['path'], ':id' => $tenantId]);
    }
}

// =============================================================================
// Listo
// =============================================================================
echo "\n== Tenant creado OK ==\n";
echo "  tenant_id  : {$tenantId}\n";
echo "  tipo       : {$tipo}\n";
echo "  db_name    : " . ($tipo === 'app' ? $dbName : '(integracion: sin DB)') . "\n";
if ($tipo === 'app') {
    echo "  admin      : " . ($createAdmin ? $adminEmail : '(sin usuario admin)') . "\n";
}
echo "  API KEY    : {$apiKey}\n";
echo "  API SECRET : {$apiSecret}\n";
if ($webhookUrl !== null && $webhookUrl !== '') {
    echo "  WEBHOOK URL    : {$webhookUrl}\n";
    echo "  WEBHOOK SECRET : {$webhookSecret}\n";
    echo "  >> El cliente verifica el header X-Gratex-Signature: sha256=HMAC(body, WEBHOOK SECRET).\n";
}
echo "  >> Entregar API KEY + API SECRET al cliente.\n";
echo "  >> El API SECRET se muestra UNA sola vez (se guarda solo su hash).\n";
echo "     Headers: X-API-KEY: <key>  /  X-API-SECRET: <secret>\n";
exit(0);

// =============================================================================
// Helpers
// =============================================================================

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

function runSqlFile(PDO $pdo, string $path): void
{
    $sql = file_get_contents($path);
    if ($sql === false) {
        fail("No se pudo leer SQL: {$path}");
    }
    // PDO_MYSQL ejecuta multiples sentencias separadas por ';' en un exec().
    $pdo->exec($sql);
}

/**
 * Guarda el logo del tenant en logos/<tenant_id>.<ext> (png|jpg). El PDF lo usa
 * en la Representacion Impresa (FacturaPdfGenerator::logoPath()).
 * @return array{path: ?string, msg: string} path relativo (logos/<id>.<ext>) o null.
 */
function saveTenantLogo(int $tenantId, array $file): array
{
    $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, ['png', 'jpg', 'jpeg'], true)) {
        return ['path' => null, 'msg' => "logo ignorado: extension '{$ext}' no permitida (png/jpg)."];
    }
    $ext = $ext === 'jpeg' ? 'jpg' : $ext;

    $logosDir = __DIR__ . '/../logos';
    if (!is_dir($logosDir) && !mkdir($logosDir, 0755, true) && !is_dir($logosDir)) {
        return ['path' => null, 'msg' => 'logo NO guardado: no se pudo crear la carpeta logos/.'];
    }
    // Quitar logos previos del tenant (cualquier extension) para evitar stale.
    foreach (['png', 'jpg', 'jpeg'] as $old) {
        $p = $logosDir . '/' . $tenantId . '.' . $old;
        if (is_file($p)) {
            @unlink($p);
        }
    }
    $dest = $logosDir . '/' . $tenantId . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return ['path' => null, 'msg' => 'logo NO guardado: fallo move_uploaded_file (revisa permisos de logos/).'];
    }
    $rel = 'logos/' . $tenantId . '.' . $ext;
    return ['path' => $rel, 'msg' => "logo guardado en {$rel}"];
}

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
