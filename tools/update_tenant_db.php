<?php
/**
 * update_tenant_db.php — Inspecciona / actualiza las credenciales de DB de un
 * tenant en el registro maestro (gratex_master.tenants).
 *
 * Necesario tras una migracion de servidor/cPanel: el .env se actualiza a mano,
 * pero db_host/db_name/db_user/db_pass_encrypted del tenant viven en la tabla
 * `tenants` y quedan apuntando al servidor viejo. Sintoma tipico: el login
 * funciona (master DB con credenciales nuevas) pero ningun endpoint de negocio
 * responde (tenant DB con credenciales viejas).
 *
 * db_pass se guarda cifrado AES-256-GCM con MASTER_ENCRYPTION_KEY; este script
 * lo cifra por ti (nunca escribas el blob a mano).
 *
 * Solo CLI: maneja credenciales, no se expone por HTTP.
 *
 * Inspeccionar (no escribe nada):
 *   php tools/update_tenant_db.php
 *
 * Actualizar (PowerShell):
 *   php tools/update_tenant_db.php --id=1 --apply `
 *     --db-host=localhost --db-name=smhynzte_new_gratexdb `
 *     --db-user=smhynzte_edwin --db-pass=laClave
 *
 * Solo los flags que pases se modifican; el resto queda igual. Con --apply se
 * verifica la conexion ANTES de escribir: si no conecta, aborta sin tocar nada.
 */

require_once __DIR__ . '/../src/MasterDatabase.php';
require_once __DIR__ . '/../src/TenantResolver.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo CLI.');
}

loadEnvFile(__DIR__ . '/../.env');

$args = parseArgs($argv);
$apply = isset($args['apply']);
$id    = isset($args['id']) ? (int) $args['id'] : null;

$master = MasterDatabase::getInstance()->getConnection();

// ---------------------------------------------------------------- inspeccion
if (!$apply) {
    $rows = $master->query(
        "SELECT id, nombre, tipo, activo, db_host, db_port, db_name, db_user,
                db_pass_encrypted IS NOT NULL AS tiene_pass
           FROM tenants ORDER BY id"
    )->fetchAll();

    echo "Tenants registrados en " . ($_ENV['MASTER_DB_NAME'] ?? '?') . ":\n\n";
    foreach ($rows as $r) {
        printf(
            "  #%d %s [%s]%s\n    db: %s@%s:%s/%s  pass_cifrado=%s\n",
            $r['id'],
            $r['nombre'],
            $r['tipo'],
            (int) $r['activo'] === 1 ? '' : ' (INACTIVO)',
            $r['db_user'] ?? '-',
            $r['db_host'] ?? '-',
            $r['db_port'] ?? '-',
            $r['db_name'] ?? '-',
            $r['tiene_pass'] ? 'si' : 'no'
        );

        if (($r['tipo'] ?? '') === 'app') {
            [$ok, $msg] = probeTenantDb((int) $r['id'], $master);
            echo '    conexion: ' . ($ok ? 'OK' : "FALLA -> {$msg}") . "\n";
        }
        echo "\n";
    }
    echo "Para actualizar: php tools/update_tenant_db.php --id=<N> --apply --db-host=... --db-user=...\n";
    exit(0);
}

// ---------------------------------------------------------------- validacion
if ($id === null) {
    exit("Falta --id=<tenant_id>. Corre sin --apply para ver la lista.\n");
}

$stmt = $master->prepare('SELECT * FROM tenants WHERE id = :id');
$stmt->execute([':id' => $id]);
$tenant = $stmt->fetch();
if (!$tenant) {
    exit("Tenant #{$id} no existe.\n");
}
if (($tenant['tipo'] ?? 'app') !== 'app') {
    exit("Tenant #{$id} es tipo '{$tenant['tipo']}': no tiene DB propia, nada que actualizar.\n");
}

$host = $args['db-host'] ?? $tenant['db_host'];
$port = $args['db-port'] ?? ($tenant['db_port'] ?: '3306');
$name = $args['db-name'] ?? $tenant['db_name'];
$user = $args['db-user'] ?? $tenant['db_user'];
$pass = $args['db-pass'] ?? null;

if ($pass === null) {
    if (empty($tenant['db_pass_encrypted'])) {
        exit("El tenant no tiene password guardada y no pasaste --db-pass.\n");
    }
    $pass = TenantResolver::decrypt($tenant['db_pass_encrypted']);
    echo "Reusando la password ya guardada (no pasaste --db-pass).\n";
}

// Verificar ANTES de escribir: no dejar el registro peor de como estaba.
echo "Probando {$user}@{$host}:{$port}/{$name} ... ";
try {
    new PDO(
        "mysql:host={$host}:{$port};dbname={$name};charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    echo "OK\n";
} catch (PDOException $e) {
    echo "FALLA\n  " . $e->getMessage() . "\n\nNo se escribio nada.\n";
    exit(1);
}

// ---------------------------------------------------------------- escritura
$upd = $master->prepare(
    'UPDATE tenants
        SET db_host = :host, db_port = :port, db_name = :name,
            db_user = :user, db_pass_encrypted = :pass
      WHERE id = :id'
);
$upd->bindValue(':host', $host);
$upd->bindValue(':port', $port);
$upd->bindValue(':name', $name);
$upd->bindValue(':user', $user);
$upd->bindValue(':pass', TenantResolver::encrypt($pass), PDO::PARAM_LOB);
$upd->bindValue(':id', $id, PDO::PARAM_INT);
$upd->execute();

echo "Tenant #{$id} ({$tenant['nombre']}) actualizado -> {$user}@{$host}:{$port}/{$name}\n";

// ---------------------------------------------------------------- helpers

/** Intenta conectar a la DB de un tenant con sus credenciales guardadas. */
function probeTenantDb(int $tenantId, PDO $master): array
{
    $stmt = $master->prepare('SELECT * FROM tenants WHERE id = :id');
    $stmt->execute([':id' => $tenantId]);
    $t = $stmt->fetch();
    if (!$t || empty($t['db_pass_encrypted'])) {
        return [false, 'sin credenciales guardadas'];
    }
    try {
        new PDO(
            "mysql:host={$t['db_host']}:{$t['db_port']};dbname={$t['db_name']};charset=utf8mb4",
            $t['db_user'],
            TenantResolver::decrypt($t['db_pass_encrypted']),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        return [true, ''];
    } catch (Throwable $e) {
        return [false, $e->getMessage()];
    }
}

/** --clave=valor y --flag desde $argv. */
function parseArgs(array $argv): array
{
    $out = [];
    foreach (array_slice($argv, 1) as $a) {
        if (strpos($a, '--') !== 0) {
            continue;
        }
        $a = substr($a, 2);
        if (strpos($a, '=') !== false) {
            [$k, $v] = explode('=', $a, 2);
            $out[$k] = $v;
        } else {
            $out[$a] = true;
        }
    }
    return $out;
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
