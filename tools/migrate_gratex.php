<?php
/**
 * migrate_gratex.php — Registra Gratex como tenant #1 (tipo app) y mueve su
 * auth (users / api_tokens / landing_*) desde la DB de negocio a gratex_master.
 *
 * NO toca la DB de negocio de Gratex (facturas, ncf, ecf, etc. quedan intactos).
 * Idempotente: re-ejecutar no duplica (INSERT IGNORE + guarda el tenant).
 *
 * ----------------------------------------------------------------------------
 * EJECUCION POR NAVEGADOR (no requiere CLI):
 *   1. Edita MIGRATION_TOKEN abajo y pon un valor secreto propio.
 *   2. Abre (via el entrypoint en public/, que el .htaccess sirve directo):
 *        https://gratex.net/api/public/migrate_gratex.php?token=TU_TOKEN
 *   3. Lee la salida. Si todo OK, pon MULTI_TENANT_ENABLED=true en .env.
 *   4. BORRA public/migrate_gratex.php y tools/migrate_gratex.php del server.
 *
 * Tambien corre por CLI:  php tools/migrate_gratex.php
 *
 * Requisitos en .env:
 *   - DB_* (conexion actual a mtldtmte_new_gratexdb)  -> origen
 *   - MASTER_DB_* + MASTER_ENCRYPTION_KEY             -> destino
 * ----------------------------------------------------------------------------
 */

// === CONFIG: editar antes de usar por navegador =============================
const MIGRATION_TOKEN  = 'CAMBIA_ESTE_TOKEN_Y_LUEGO_BORRA_EL_ARCHIVO';
const DEFAULT_RNC      = '00109122788';
const DEFAULT_NOMBRE   = 'Gratex SRL';
const DEFAULT_AMBIENTE = 'ecf';
// ===========================================================================

require_once __DIR__ . '/../src/MasterDatabase.php';
require_once __DIR__ . '/../src/TenantResolver.php';
require_once __DIR__ . '/../src/Utils/TokenGenerator.php';

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    $token = (string) ($_GET['token'] ?? '');
    if (MIGRATION_TOKEN === 'CAMBIA_ESTE_TOKEN_Y_LUEGO_BORRA_EL_ARCHIVO') {
        http_response_code(403);
        exit("Configura MIGRATION_TOKEN en el archivo antes de usarlo.\n");
    }
    if (!hash_equals(MIGRATION_TOKEN, $token)) {
        http_response_code(403);
        exit("Token invalido. Use ?token=...\n");
    }
}

loadEnvFile(__DIR__ . '/../.env');

// Parametros: CLI (--flag) o navegador (?param), con defaults baked.
$cliOpts  = $isCli ? getopt('', ['rnc::', 'nombre::', 'ambiente::']) : [];
$rnc      = preg_replace('/\D/', '', (string) ($cliOpts['rnc'] ?? $_GET['rnc'] ?? DEFAULT_RNC));
$nombre   = (string) ($cliOpts['nombre'] ?? $_GET['nombre'] ?? DEFAULT_NOMBRE);
$ambiente = (string) ($cliOpts['ambiente'] ?? $_GET['ambiente'] ?? DEFAULT_AMBIENTE);

// --- Conexiones --------------------------------------------------------------
$master = MasterDatabase::getInstance()->getConnection();

$srcHost = getenv('DB_HOST') ?: 'localhost';
$srcPort = getenv('DB_PORT') ?: '3306';
$srcName = getenv('DB_NAME') ?: 'mtldtmte_new_gratexdb';
$srcUser = getenv('DB_USER') ?: '';
$srcPass = getenv('DB_PASS') ?: '';

$src = new PDO(
    "mysql:host={$srcHost}:{$srcPort};dbname={$srcName};charset=utf8mb4",
    $srcUser, $srcPass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

echo "== Migracion Gratex -> tenant #1 ==\n";
echo "   origen : {$srcName}\n";
echo "   destino: gratex_master\n\n";

// --- 1) Registrar tenant #1 --------------------------------------------------
$exists = $master->query('SELECT id FROM tenants WHERE id = 1')->fetchColumn();
if ($exists) {
    echo "-> tenant id=1 ya existe; no se re-inserta.\n";
} else {
    $dbPassEnc = TenantResolver::encrypt($srcPass);
    // app no usa integracion, pero api_key/api_secret_hash son NOT NULL: random.
    $apiKey = TokenGenerator::generateApiToken(32);
    $apiSecretHash = TenantResolver::hashSecret(TokenGenerator::generateApiToken(64));

    $ins = $master->prepare(
        'INSERT INTO tenants
            (id, nombre, rnc, api_key, api_secret_hash, tipo, db_host, db_port, db_name,
             db_user, db_pass_encrypted, ambiente, activo)
         VALUES
            (1, :nombre, :rnc, :api_key, :api_secret_hash, :tipo, :db_host, :db_port, :db_name,
             :db_user, :db_pass_enc, :ambiente, 1)'
    );
    $ins->bindValue(':nombre', $nombre);
    $ins->bindValue(':rnc', $rnc);
    $ins->bindValue(':api_key', $apiKey);
    $ins->bindValue(':api_secret_hash', $apiSecretHash);
    $ins->bindValue(':tipo', 'app');
    $ins->bindValue(':db_host', $srcHost);
    $ins->bindValue(':db_port', $srcPort);
    $ins->bindValue(':db_name', $srcName);
    $ins->bindValue(':db_user', $srcUser);
    $ins->bindValue(':db_pass_enc', $dbPassEnc, PDO::PARAM_LOB);
    $ins->bindValue(':ambiente', $ambiente);
    $ins->execute();
    echo "-> tenant #1 (Gratex, tipo=app, {$srcName}) registrado.\n";
}

// --- 2) Migrar users ---------------------------------------------------------
$copiados = copyTable(
    $src, $master,
    'SELECT id, name, last_name, email, username, password, role FROM users',
    'INSERT IGNORE INTO users (id, tenant_id, name, last_name, email, username, password, role)
     VALUES (:id, 1, :name, :last_name, :email, :username, :password, :role)',
    fn($r) => [
        ':id' => $r['id'], ':name' => $r['name'], ':last_name' => $r['last_name'],
        ':email' => $r['email'], ':username' => $r['username'],
        ':password' => $r['password'], ':role' => $r['role'],
    ]
);
echo "-> users migrados: {$copiados}\n";

// --- 3) Migrar api_tokens ----------------------------------------------------
$copiados = copyTable(
    $src, $master,
    'SELECT id, user_id, token_hash, created_at, last_used, is_active FROM api_tokens',
    'INSERT IGNORE INTO api_tokens (id, user_id, tenant_id, token_hash, created_at, last_used, is_active)
     VALUES (:id, :user_id, 1, :token_hash, :created_at, :last_used, :is_active)',
    fn($r) => [
        ':id' => $r['id'], ':user_id' => $r['user_id'], ':token_hash' => $r['token_hash'],
        ':created_at' => $r['created_at'], ':last_used' => $r['last_used'],
        ':is_active' => $r['is_active'],
    ]
);
echo "-> api_tokens migrados: {$copiados}\n";

// --- 4) Migrar landing_* (best-effort: pueden no existir en origen) -----------
foreach (['landing_carousel', 'landing_services'] as $tabla) {
    try {
        $rows = $src->query("SELECT * FROM {$tabla}")->fetchAll();
        $n = 0;
        foreach ($rows as $r) {
            $cols = array_keys($r);
            $ph = array_map(fn($c) => ':' . $c, $cols);
            $sql = "INSERT IGNORE INTO {$tabla} (" . implode(',', $cols) . ') VALUES (' . implode(',', $ph) . ')';
            $stmt = $master->prepare($sql);
            foreach ($r as $c => $v) {
                $stmt->bindValue(':' . $c, $v);
            }
            $stmt->execute();
            $n += $stmt->rowCount();
        }
        echo "-> {$tabla} migrados: {$n}\n";
    } catch (Throwable $e) {
        echo "-> {$tabla}: omitido ({$e->getMessage()})\n";
    }
}

// --- 5) Verificar emails unicos globales -------------------------------------
$dups = $master->query(
    'SELECT email, COUNT(*) c FROM users GROUP BY email HAVING c > 1'
)->fetchAll();
if ($dups) {
    echo "\n!! ADVERTENCIA: emails duplicados en master.users (rompe login multi-tenant):\n";
    foreach ($dups as $d) {
        echo "   {$d['email']} x{$d['c']}\n";
    }
} else {
    echo "\n-> emails unicos OK.\n";
}

echo "\n== Migracion completa ==\n";
echo "   1) Verifica login y emision en staging.\n";
echo "   2) Pon MULTI_TENANT_ENABLED=true en .env.\n";
echo "   3) BORRA este archivo del server.\n";
exit(0);

// =============================================================================
// Helpers
// =============================================================================

function copyTable(PDO $src, PDO $dst, string $selectSql, string $insertSql, callable $map): int
{
    $rows = $src->query($selectSql)->fetchAll();
    $stmt = $dst->prepare($insertSql);
    $n = 0;
    foreach ($rows as $r) {
        $stmt->execute($map($r));
        $n += $stmt->rowCount();
    }
    return $n;
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
