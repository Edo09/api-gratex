<?php
/**
 * pos_scratch_prepare.php — deja listas las bases scratch para las suites del POS, que necesitan un
 * master multi-tenant. Idempotente. Rol master: wa_scratch_sin_gate (tenants, pos_equipos,
 * pos_handoff_codes, tenant_id NULL-able en users y api_tokens porque las pruebas de un solo tenant
 * tambien la usan, permite_decimales). Tenant A: wa_scratch (solo filas). Tenant B: la misma
 * wa_scratch_sin_gate, que sigue SIN 027/028. Los secretos se derivan de WA_DB_PASS y no se imprimen.
 *
 * La tabla tenants guarda la clave de la base cifrada: --limpiar borra esas filas y lo que cuelga de
 * ellas (usuarios, roles, equipos, tokens) y deja las tablas; la siguiente preparacion las recrea.
 *
 * Uso:  php -d extension=curl -d extension=openssl -d extension=pdo_mysql tools/pos_scratch_prepare.php [--limpiar]
 */

require_once __DIR__ . '/write_ahead_test_lib.php';
require_once __DIR__ . '/pos_scratch_guard.php';

const POS_SCRATCH_BASE_MASTER = 'wa_scratch_sin_gate';
const POS_SCRATCH_BASE_A = 'wa_scratch';
const POS_SCRATCH_BASE_B = 'wa_scratch_sin_gate';
const POS_SCRATCH_TENANTS = [
    'A' => ['nombre' => 'POS Prueba A', 'rnc' => '000000010', 'base' => POS_SCRATCH_BASE_A],
    'B' => ['nombre' => 'POS Prueba B', 'rnc' => '000000011', 'base' => POS_SCRATCH_BASE_B],
];
const POS_SCRATCH_USUARIOS = [
    ['username' => 'admin_pos_a', 'tenant' => 'A', 'rol' => 'admin'],
    ['username' => 'admin_pos_b', 'tenant' => 'B', 'rol' => 'admin'],
    ['username' => 'user_pos_a', 'tenant' => 'A', 'rol' => 'user'],
];

/** @return array{clave_maestra:string, pepper:string, clave_usuarios:string} derivados de $base (WA_DB_PASS) */
function pos_scratch_secretos(string $base): array
{
    $d = static fn(string $uso, int $largo): string => substr(hash('sha256', 'wa_pos_scratch|' . $uso . '|' . $base), 0, $largo);
    return ['clave_maestra' => $d('clave-maestra', 64), 'pepper' => $d('pin-pepper', 64), 'clave_usuarios' => $d('usuarios', 32)];
}

function pos_scratch_tiene_columna(PDO $pdo, string $real, string $tabla, string $columna): bool
{
    $st = $pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :b AND TABLE_NAME = :t AND COLUMN_NAME = :c');
    $st->execute([':b' => $real, ':t' => $tabla, ':c' => $columna]);
    return $st->fetchColumn() !== false;
}

/** El CREATE TABLE IF NOT EXISTS de una tabla del master, tal como lo trae db/master_schema.sql. */
function pos_scratch_ddl_master(string $tabla): string
{
    $sql = (string) file_get_contents(dirname(__DIR__) . '/db/master_schema.sql');
    if (preg_match('/CREATE TABLE IF NOT EXISTS ' . preg_quote($tabla, '/') . ' \(.*?\) ENGINE=InnoDB[^;]*;/s', $sql, $m) !== 1) {
        echo "[FALLO] db/master_schema.sql no trae la tabla {$tabla}\n";
        exit(1);
    }
    return $m[0];
}

/** Prepara las bases; se niega (pos_scratch_exigir) si alguna no es de prueba. */
function pos_scratch_preparar(): array
{
    $cred = wa_credenciales();
    $secretos = pos_scratch_secretos($cred['pass']);
    $master = wa_pdo(POS_SCRATCH_BASE_MASTER);
    $realMaster = wa_base_real(POS_SCRATCH_BASE_MASTER);
    $realA = wa_base_real(POS_SCRATCH_BASE_A);
    $realB = wa_base_real(POS_SCRATCH_BASE_B);
    pos_scratch_exigir($master, $cred['host'], [$realMaster, $realA, $realB]);

    // Master: tablas y columnas que el codigo del POS lee.
    foreach (['tenants', 'pos_handoff_codes', 'pos_equipos'] as $tabla) {
        $master->exec(pos_scratch_ddl_master($tabla));
    }
    foreach (['users', 'api_tokens'] as $tabla) {
        if (!pos_scratch_tiene_columna($master, $realMaster, $tabla, 'tenant_id')) {
            $master->exec("ALTER TABLE {$tabla} ADD COLUMN tenant_id INT NULL");
        }
    }
    if (!pos_scratch_tiene_columna($master, $realMaster, 'unidades_medida', 'permite_decimales')) {
        $master->exec('ALTER TABLE unidades_medida ADD COLUMN permite_decimales TINYINT(1) NOT NULL DEFAULT 0 AFTER descripcion');
    }
    $master->exec('UPDATE unidades_medida SET permite_decimales = (id IN (21, 24))'); // KG y LITRO; UND (43) no

    // Master: tenants, con la clave de su base cifrada con la clave maestra de prueba.
    putenv('MASTER_ENCRYPTION_KEY=' . $secretos['clave_maestra']);
    $_ENV['MASTER_ENCRYPTION_KEY'] = $secretos['clave_maestra'];
    require_once dirname(__DIR__) . '/src/TenantResolver.php';
    $ids = [];
    $upsert = $master->prepare(
        "INSERT INTO tenants (nombre, rnc, api_key, api_secret_hash, tipo, db_host, db_port, db_name, db_user, db_pass_encrypted,
                              cert_path, cert_pass_encrypted, ambiente, pos_enabled, activo)
         VALUES (:n, :r, :k, :s, 'app', :h, :p, :d, :u, :e, NULL, NULL, 'testecf', 1, 1)
         ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), api_key = VALUES(api_key), api_secret_hash = VALUES(api_secret_hash),
             tipo = 'app', db_host = VALUES(db_host), db_port = VALUES(db_port), db_name = VALUES(db_name), db_user = VALUES(db_user),
             db_pass_encrypted = VALUES(db_pass_encrypted), cert_path = NULL, cert_pass_encrypted = NULL,
             ambiente = 'testecf', pos_enabled = 1, activo = 1"
    );
    foreach (POS_SCRATCH_TENANTS as $clave => $t) {
        foreach ([
            ':n' => $t['nombre'], ':r' => $t['rnc'],
            ':k' => hash('sha256', 'wa_pos_scratch|api_key|' . $clave . '|' . $cred['pass']),
            ':s' => hash('sha256', 'wa_pos_scratch|api_secret|' . $clave . '|' . $cred['pass']),
            ':h' => $cred['host'], ':p' => $cred['port'], ':d' => wa_base_real($t['base']), ':u' => $cred['user'],
        ] as $marca => $valor) {
            $upsert->bindValue($marca, $valor);
        }
        $upsert->bindValue(':e', TenantResolver::encrypt($cred['pass']), PDO::PARAM_LOB); // binario, como create_tenant.php
        $upsert->execute();
        $st = $master->prepare('SELECT id FROM tenants WHERE rnc = :r');
        $st->execute([':r' => $t['rnc']]);
        $ids[$clave] = (int) $st->fetchColumn();
    }

    // Master: roles de cada tenant (admin con "*", user sin el modulo pos) y usuarios de app.*.
    $defaults = (require dirname(__DIR__) . '/config/permissions.php')['defaults'];
    foreach ($ids as $tenantId) {
        foreach ($defaults as $rol => $permisos) {
            $master->prepare("INSERT INTO roles (tenant_id, name, description, is_system) VALUES (:t, :n, 'Rol de prueba del POS', 1)
                              ON DUPLICATE KEY UPDATE is_system = 1")->execute([':t' => $tenantId, ':n' => $rol]);
            $st = $master->prepare('SELECT id FROM roles WHERE tenant_id = :t AND name = :n');
            $st->execute([':t' => $tenantId, ':n' => $rol]);
            $rolId = (int) $st->fetchColumn();
            $master->prepare('DELETE FROM role_permissions WHERE role_id = :r')->execute([':r' => $rolId]);
            foreach ($permisos as $permiso) {
                $master->prepare('INSERT INTO role_permissions (role_id, permission) VALUES (:r, :p)')->execute([':r' => $rolId, ':p' => $permiso]);
            }
        }
    }
    $hash = password_hash($secretos['clave_usuarios'], PASSWORD_DEFAULT);
    $usuario = $master->prepare(
        "INSERT INTO users (tenant_id, name, last_name, email, username, password, role) VALUES (:t, 'POS', 'Prueba', :e, :u, :p, :r)
         ON DUPLICATE KEY UPDATE tenant_id = VALUES(tenant_id), password = VALUES(password), role = VALUES(role)"
    );
    foreach (POS_SCRATCH_USUARIOS as $u) {
        $usuario->execute([':t' => $ids[$u['tenant']], ':e' => $u['username'] . '@example.com', ':u' => $u['username'], ':p' => $hash, ':r' => $u['rol']]);
    }

    // Tenant A (solo filas): emisor, almacen y rangos testecf E31/E32 sin limite y vigentes.
    $pdoA = wa_pdo(POS_SCRATCH_BASE_A);
    wa_sembrar($pdoA);
    $pdoA->exec("DELETE FROM ncf_sequences WHERE ambiente = 'testecf' AND type IN ('E31', 'E32') AND numero_desde > 1");
    $rango = $pdoA->prepare(
        "INSERT INTO ncf_sequences (type, prefix, current_value, numero_desde, numero_hasta, fecha_vencimiento, description, ambiente)
         VALUES (:t, :t2, 0, 1, NULL, :v, 'POS prueba', 'testecf')
         ON DUPLICATE KEY UPDATE numero_hasta = NULL, fecha_vencimiento = VALUES(fecha_vencimiento)"
    );
    foreach (['E31', 'E32'] as $tipo) {
        $rango->execute([':t' => $tipo, ':t2' => $tipo, ':v' => date('Y-m-d', strtotime('+1 year'))]);
    }

    return ['master' => $realMaster, 'dbA' => $realA, 'dbB' => $realB, 'tenantA' => $ids['A'], 'tenantB' => $ids['B'], 'cred' => $cred];
}

/**
 * Quita del master lo que preparar() puso en el: los dos tenants (con la clave de su base cifrada), los
 * usuarios admin_pos_a/admin_pos_b/user_pos_a, sus tokens, roles, permisos, equipos y codigos de
 * traspaso. Deja las tablas y las columnas. Se niega (pos_scratch_exigir) si la base no es de prueba.
 * @return int tenants borrados
 */
function pos_scratch_limpiar(): int
{
    $cred = wa_credenciales();
    $master = wa_pdo(POS_SCRATCH_BASE_MASTER);
    $realMaster = wa_base_real(POS_SCRATCH_BASE_MASTER);
    pos_scratch_exigir($master, $cred['host'], [$realMaster, wa_base_real(POS_SCRATCH_BASE_A), wa_base_real(POS_SCRATCH_BASE_B)]);
    if (!wa_tabla_existe($master, $realMaster, 'tenants')) {
        return 0;
    }
    $rncs = array_column(POS_SCRATCH_TENANTS, 'rnc');
    $enRncs = implode(',', array_fill(0, count($rncs), '?'));
    $st = $master->prepare("SELECT id FROM tenants WHERE rnc IN ({$enRncs})");
    $st->execute($rncs);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $nombres = array_column(POS_SCRATCH_USUARIOS, 'username');
    $enNombres = implode(',', array_fill(0, count($nombres), '?'));
    $st = $master->prepare("SELECT id FROM users WHERE username IN ({$enNombres})");
    $st->execute($nombres);
    $usuarios = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    $en = static fn(array $v): string => $v === [] ? 'NULL' : implode(',', $v);

    // De lo que cuelga a lo colgado: hijos de los usuarios y de los tenants, usuarios, roles, tenants.
    $master->exec('DELETE FROM pos_handoff_codes WHERE tenant_id IN (' . $en($ids) . ') OR user_id IN (' . $en($usuarios) . ')');
    $master->exec('DELETE FROM pos_equipos WHERE tenant_id IN (' . $en($ids) . ')');
    $master->exec('DELETE FROM api_tokens WHERE tenant_id IN (' . $en($ids) . ') OR user_id IN (' . $en($usuarios) . ')');
    $master->exec('DELETE FROM users WHERE id IN (' . $en($usuarios) . ')');
    $master->exec('DELETE FROM role_permissions WHERE role_id IN (SELECT id FROM roles WHERE tenant_id IN (' . $en($ids) . '))');
    $master->exec('DELETE FROM roles WHERE tenant_id IN (' . $en($ids) . ')');
    $master->exec('DELETE FROM tenants WHERE id IN (' . $en($ids) . ')');
    return count($ids);
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    if (wa_arg('limpiar') !== null) {
        $n = pos_scratch_limpiar();
        echo "Entorno del POS limpiado: {$n} tenants y lo que colgaba de ellos.\n";
        exit(0);
    }
    $info = pos_scratch_preparar();
    echo "Entorno del POS listo: tenant A = {$info['tenantA']}, tenant B = {$info['tenantB']}.\n";
    exit(0);
}
