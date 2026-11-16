<?php
/**
 * test_acceso_global.php — Usuarios de soporte que entran a todas las empresas
 * (master.users.acceso_global, migracion master 019; docs/modules/acceso-global.md).
 *
 * El limite de seguridad: un token vale en la empresa de su usuario y, en OTRA, solo
 * si el usuario tiene acceso global y esa empresa es tipo app, activa y no solo
 * Express. Este script lo prueba en dos partes:
 *
 *   Parte 1 — Reglas puras de src/AccesoGlobal.php (sin base): empresaDisponible,
 *             sesionPermitida, empresaPublica (nada de la fila de tenants que no se
 *             deba mostrar) y leerEmpresaId (el cuerpo de POST /api/auth/empresa).
 *
 *   Parte 2 — El authModel REAL contra un master SQLite en memoria (MasterDatabase
 *             inyectado por reflexion): validateToken, loginUser, getUserProfile,
 *             iniciarSesionPorId (el canje del POS), empresasAccesoGlobal y
 *             cambiarEmpresaSesion. Incluye un master SIN la columna (antes de la
 *             019): el login y las sesiones de todos siguen iguales y nadie tiene el
 *             acceso, asi que subir el codigo antes que la migracion no rompe nada.
 *
 * Uso:
 *   php tools/test_acceso_global.php
 *
 * La Parte 2 necesita pdo_sqlite; si no esta activo se salta con aviso (la Parte 1
 * corre siempre). Para forzarlo:
 *   php -d extension=php_pdo_sqlite.dll tools/test_acceso_global.php  (Windows)
 *   php -d extension=pdo_sqlite tools/test_acceso_global.php          (Linux)
 */

// Las lineas de log de lo que se prueba a proposito (la columna ausente) no van a la
// salida: el resumen de la suite tiene que quedar limpio.
ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');

putenv('MULTI_TENANT_ENABLED=true');
$_ENV['MULTI_TENANT_ENABLED'] = 'true';

require_once __DIR__ . '/../src/AccesoGlobal.php';
require_once __DIR__ . '/../src/Models/authModel.php';
require_once __DIR__ . '/../src/Models/RoleModel.php';

$fallos = 0;
$total = 0;
$chk = function (string $desc, bool $ok) use (&$fallos, &$total) {
    $total++;
    if (!$ok) {
        $fallos++;
    }
    printf("  [%s] %s\n", $ok ? 'OK  ' : 'FALLO', $desc);
};

// =============================================================================
// Parte 1 — Reglas puras (sin base)
// =============================================================================
echo "Parte 1 — AccesoGlobal::empresaDisponible\n";
$app = ['id' => 2, 'tipo' => 'app', 'activo' => 1, 'edicion' => 'completa'];
$chk('app, activa, completa -> disponible', AccesoGlobal::empresaDisponible($app));
$chk('app, activa, ambas -> disponible (la web corre como completa)', AccesoGlobal::empresaDisponible(['edicion' => 'ambas'] + $app));
$chk('app, activa, express -> NO (no usa la web)', !AccesoGlobal::empresaDisponible(['edicion' => 'express'] + $app));
$chk("edicion ' Express ' (espacios, mayusculas) -> NO", !AccesoGlobal::empresaDisponible(['edicion' => ' Express '] + $app));
$chk('tipo integracion -> NO (sin base ni web)', !AccesoGlobal::empresaDisponible(['tipo' => 'integracion'] + $app));
$chk('inactiva -> NO', !AccesoGlobal::empresaDisponible(['activo' => 0] + $app));
$chk("activo '1' (texto, como lo da PDO) -> disponible", AccesoGlobal::empresaDisponible(['activo' => '1'] + $app));
$chk('sin columna edicion (master anterior a la 015) -> disponible', AccesoGlobal::empresaDisponible(['id' => 2, 'tipo' => 'app', 'activo' => 1]));
$chk('fila null (inexistente o inactiva) -> NO', !AccesoGlobal::empresaDisponible(null));
$chk('fila vacia -> NO', !AccesoGlobal::empresaDisponible([]));

echo "\nParte 1 — AccesoGlobal::sesionPermitida\n";
$chk('su propia empresa, sin acceso global -> vale', AccesoGlobal::sesionPermitida(1, 1, false, null));
$chk('otra empresa, con acceso y disponible -> vale', AccesoGlobal::sesionPermitida(1, 2, true, $app));
$chk('otra empresa, SIN acceso global -> NO', !AccesoGlobal::sesionPermitida(1, 2, false, $app));
$chk('otra empresa, con acceso pero inexistente -> NO', !AccesoGlobal::sesionPermitida(1, 2, true, null));
$chk('otra empresa, con acceso pero solo Express -> NO', !AccesoGlobal::sesionPermitida(1, 2, true, ['edicion' => 'express'] + $app));

echo "\nParte 1 — AccesoGlobal::sesionVencida (" . AccesoGlobal::HORAS_INACTIVIDAD . " h sin uso, en otra empresa)\n";
$ahora = strtotime('2026-11-16 12:00:00');
$chk('usada hace 1 h -> vigente', !AccesoGlobal::sesionVencida('2026-11-16 11:00:00', '2026-11-10 08:00:00', $ahora));
$chk('usada hace exactamente 12 h -> vigente (vence solo si es estrictamente mas vieja)',
    !AccesoGlobal::sesionVencida('2026-11-16 00:00:00', '2026-11-10 08:00:00', $ahora));
$chk('usada hace 12 h y 1 s -> vencida', AccesoGlobal::sesionVencida('2026-11-15 23:59:59', '2026-11-10 08:00:00', $ahora));
$chk('nunca usada, creada hace 2 h -> vigente (cuenta desde la creacion)', !AccesoGlobal::sesionVencida(null, '2026-11-16 10:00:00', $ahora));
$chk('nunca usada, creada hace 13 h -> vencida', AccesoGlobal::sesionVencida(null, '2026-11-15 23:00:00', $ahora));
$chk('last_used con la fecha cero de MySQL -> cuenta desde la creacion', !AccesoGlobal::sesionVencida('0000-00-00 00:00:00', '2026-11-16 10:00:00', $ahora));
$chk('sin ninguna fecha legible -> vencida (falla cerrado)', AccesoGlobal::sesionVencida('', 'no es fecha', $ahora));

echo "\nParte 1 — AccesoGlobal::empresaPublica\n";
$filaCompleta = [
    'id' => '2', 'nombre' => 'Ferre SRL', 'rnc' => '132615123', 'ambiente' => 'ecf', 'tipo' => 'app', 'activo' => 1,
    'api_key' => 'k', 'api_secret_hash' => 'h', 'db_name' => 'b', 'db_user' => 'u', 'db_pass_encrypted' => 'x',
    'cert_pass_encrypted' => 'y', 'webhook_secret_encrypted' => 'z',
];
$pub = AccesoGlobal::empresaPublica($filaCompleta);
$chk('solo id, nombre, rnc y ambiente', array_keys($pub) === ['id', 'nombre', 'rnc', 'ambiente']);
$chk('id como entero', $pub['id'] === 2);
$chk('ningun secreto de la fila', !array_intersect_key($pub, array_flip(['api_key', 'api_secret_hash', 'db_name', 'db_user', 'db_pass_encrypted', 'cert_pass_encrypted', 'webhook_secret_encrypted'])));

echo "\nParte 1 — AccesoGlobal::leerEmpresaId (cuerpo de POST /api/auth/empresa)\n";
$o = fn($v): object => (object) ['tenant_id' => $v];
$casos = [
    ['entero 2', $o(2), 2],
    ["texto '17'", $o('17'), 17],
    ["texto ' 7 ' con espacios", $o(' 7 '), 7],
    ['arreglo con tenant_id 3', ['tenant_id' => 3], 3],
    ['cero', $o(0), null],
    ['negativo', $o(-1), null],
    ["texto '2a'", $o('2a'), null],
    ["texto '02' (cero a la izquierda)", $o('02'), null],
    ['decimal 2.5', $o(2.5), null],
    ['true', $o(true), null],
    ['null', $o(null), null],
    ['sin tenant_id', (object) [], null],
    ['cuerpo null', null, null],
    ['11 digitos', $o('12345678901'), null],
];
foreach ($casos as [$desc, $body, $esperado]) {
    $chk(sprintf('%-36s -> %s', $desc, $esperado === null ? 'null' : (string) $esperado), AccesoGlobal::leerEmpresaId($body) === $esperado);
}

// =============================================================================
// Parte 2 — authModel real contra un master SQLite en memoria
// =============================================================================
if (!extension_loaded('pdo_sqlite')) {
    echo "\nParte 2 — authModel contra un master SQLite\n";
    echo "  (SALTADA: pdo_sqlite no esta activo. Ver la cabecera de este archivo.)\n";
} else {
    /** Master SQLite con lo que el login lee; $conColumna = false es un master sin la 019. */
    $crearMaster = function (bool $conColumna): PDO {
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec("CREATE TABLE tenants (id INTEGER PRIMARY KEY, nombre TEXT NOT NULL, rnc TEXT NOT NULL,
            tipo TEXT NOT NULL DEFAULT 'app', edicion TEXT NOT NULL DEFAULT 'completa', ambiente TEXT NOT NULL DEFAULT 'ecf',
            activo INTEGER NOT NULL DEFAULT 1, api_key TEXT, db_name TEXT, db_pass_encrypted BLOB)");
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT NOT NULL,
            last_name TEXT NOT NULL, email TEXT NOT NULL, username TEXT NOT NULL, password TEXT NOT NULL,
            role TEXT DEFAULT \'user\'' . ($conColumna ? ', acceso_global INTEGER NOT NULL DEFAULT 0' : '')
            . ', created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        $pdo->exec('CREATE TABLE api_tokens (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, tenant_id INTEGER NOT NULL,
            token_hash TEXT NOT NULL UNIQUE, created_at TEXT NOT NULL, last_used TEXT, is_active INTEGER DEFAULT 1)');
        $pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT NOT NULL,
            description TEXT, is_system INTEGER DEFAULT 0)');
        $pdo->exec('CREATE TABLE role_permissions (role_id INTEGER NOT NULL, permission TEXT NOT NULL)');

        $t = $pdo->prepare('INSERT INTO tenants (id, nombre, rnc, tipo, edicion, ambiente, activo, api_key, db_name, db_pass_encrypted)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ([
            [1, 'Gratex EIRL', '131256432', 'app', 'completa', 'ecf', 1],
            [2, 'Ferre SRL', '132615123', 'app', 'completa', 'ecf', 1],
            [3, 'Integra SRL', '130968837', 'integracion', 'completa', 'certecf', 1],
            [4, 'Express SRL', '131111111', 'app', 'express', 'ecf', 1],
            [5, 'Inactiva SRL', '131222222', 'app', 'completa', 'ecf', 0],
            [6, 'Ambas SRL', '131333333', 'app', 'ambas', 'testecf', 1],
        ] as [$id, $nombre, $rnc, $tipo, $edicion, $ambiente, $activo]) {
            $t->execute([$id, $nombre, $rnc, $tipo, $edicion, $ambiente, $activo, 'key' . $id, 'db_' . $id, 'secreto']);
        }

        // Roles por empresa. El admin de Ferre tiene una lista propia (no '*') para
        // ver que los permisos salen de la empresa de la SESION, no de la del usuario.
        $r = $pdo->prepare('INSERT INTO roles (id, tenant_id, name, is_system) VALUES (?, ?, ?, 1)');
        $p = $pdo->prepare('INSERT INTO role_permissions (role_id, permission) VALUES (?, ?)');
        $rid = 0;
        foreach ([1 => ['admin' => ['*'], 'user' => ['facturas']], 2 => ['admin' => ['clients', 'facturas'], 'user' => ['facturas']],
                  6 => ['admin' => ['*'], 'user' => ['facturas']]] as $tid => $rolesTenant) {
            foreach ($rolesTenant as $nombreRol => $permisos) {
                $r->execute([++$rid, $tid, $nombreRol]);
                foreach ($permisos as $perm) {
                    $p->execute([$rid, $perm]);
                }
            }
        }

        $clave = password_hash('clave1234', PASSWORD_BCRYPT, ['cost' => 4]);
        $u = $pdo->prepare('INSERT INTO users (id, tenant_id, name, last_name, email, username, password, role'
            . ($conColumna ? ', acceso_global' : '') . ') VALUES (?, ?, ?, ?, ?, ?, ?, ?' . ($conColumna ? ', ?' : '') . ')');
        foreach ([
            [10, 1, 'Sopo', 'Rte', 'soporte@x.test', 'soporte', 'admin', 1],
            [11, 1, 'Nor', 'Mal', 'normal@x.test', 'normal', 'user', 0],
            [12, 2, 'Fe', 'Rre', 'ferre@x.test', 'ferre', 'admin', 0],
        ] as [$id, $tid, $n, $ln, $mail, $un, $rol, $acceso]) {
            $fila = [$id, $tid, $n, $ln, $mail, $un, $clave, $rol];
            if ($conColumna) {
                $fila[] = $acceso;
            }
            $u->execute($fila);
        }
        return $pdo;
    };

    /** Pone $pdo como la conexion del singleton MasterDatabase (sin .env ni MySQL). */
    $usarMaster = function (PDO $pdo): void {
        $ref = new ReflectionClass('MasterDatabase');
        $m = $ref->newInstanceWithoutConstructor();
        $c = $ref->getProperty('conexion');
        $c->setAccessible(true);
        $c->setValue($m, $pdo);
        $i = $ref->getProperty('instance');
        $i->setAccessible(true);
        $i->setValue(null, $m);
    };

    /**
     * Crea un token activo de $userId en la empresa $tenantId y devuelve el token en claro. $creado y $usado
     * son segundos hacia atras desde ahora (null = nunca usado), para probar el vencimiento por inactividad.
     */
    $token = function (PDO $pdo, int $userId, int $tenantId, int $creado = 0, ?int $usado = null): string {
        $tok = TokenGenerator::generateApiToken();
        $pdo->prepare('INSERT INTO api_tokens (user_id, tenant_id, token_hash, created_at, last_used) VALUES (?, ?, ?, ?, ?)')
            ->execute([$userId, $tenantId, TokenGenerator::hashToken($tok), date('Y-m-d H:i:s', time() - $creado),
                $usado === null ? null : date('Y-m-d H:i:s', time() - $usado)]);
        return $tok;
    };
    $activo = function (PDO $pdo, string $tok): ?int {
        $st = $pdo->prepare('SELECT is_active FROM api_tokens WHERE token_hash = ?');
        $st->execute([TokenGenerator::hashToken($tok)]);
        $v = $st->fetchColumn();
        return $v === false ? null : (int) $v;
    };
    $empresaDelToken = function (PDO $pdo, string $tok): ?int {
        $st = $pdo->prepare('SELECT tenant_id FROM api_tokens WHERE token_hash = ?');
        $st->execute([TokenGenerator::hashToken($tok)]);
        $v = $st->fetchColumn();
        return $v === false ? null : (int) $v;
    };
    $ordenado = function (array $a): array {
        sort($a);
        return $a;
    };

    $pdo = $crearMaster(true);
    $usarMaster($pdo);
    $auth = new authModel();

    echo "\nParte 2 — validateToken: la empresa del token\n";
    $tN1 = $token($pdo, 11, 1);
    $tS1 = $token($pdo, 10, 1);
    $tS2 = $token($pdo, 10, 2);
    $tN2 = $token($pdo, 11, 2);
    $tS3 = $token($pdo, 10, 3);
    $tS4 = $token($pdo, 10, 4);
    $tS5 = $token($pdo, 10, 5);
    $tS6 = $token($pdo, 10, 6);
    $v = $auth->validateToken($tN1);
    $chk('usuario normal en su empresa -> vale, empresa 1', $v['valid'] === true && $v['tenant_id'] === 1);
    $v = $auth->validateToken($tS1);
    $chk('soporte en su empresa -> vale', $v['valid'] === true && $v['tenant_id'] === 1);
    $v = $auth->validateToken($tS2);
    $chk('soporte en otra empresa app activa -> vale, empresa 2', $v['valid'] === true && $v['tenant_id'] === 2 && $v['role'] === 'admin');
    $chk('usuario normal con un token de OTRA empresa -> NO vale', $auth->validateToken($tN2)['valid'] === false);
    $chk('soporte en una empresa de integracion -> NO vale', $auth->validateToken($tS3)['valid'] === false);
    $chk('soporte en una empresa solo Express -> NO vale', $auth->validateToken($tS4)['valid'] === false);
    $chk('soporte en una empresa inactiva -> NO vale', $auth->validateToken($tS5)['valid'] === false);
    $chk('...y esas tres sesiones quedan REVOCADAS (no reviven si la empresa vuelve a estar disponible)',
        $activo($pdo, $tS3) === 0 && $activo($pdo, $tS4) === 0 && $activo($pdo, $tS5) === 0);
    $chk('el token del usuario normal en otra empresa queda revocado tambien', $activo($pdo, $tN2) === 0);
    $chk("soporte en una empresa 'ambas' -> vale", $auth->validateToken($tS6)['valid'] === true);

    $pdo->exec('UPDATE users SET acceso_global = 0 WHERE id = 10');
    $chk('al quitarle el acceso: su sesion en otra empresa deja de valer', $auth->validateToken($tS2)['valid'] === false);
    $chk('...y queda revocada', $activo($pdo, $tS2) === 0);
    $chk('al quitarle el acceso: su sesion en su empresa sigue', $auth->validateToken($tS1)['valid'] === true);
    $pdo->exec('UPDATE users SET acceso_global = 1 WHERE id = 10');
    $chk('al devolverselo, la sesion vieja NO revive (hay que entrar de nuevo)', $auth->validateToken($tS2)['valid'] === false);

    echo "\nParte 2 — vencimiento por inactividad de una sesion en otra empresa\n";
    $horas = AccesoGlobal::HORAS_INACTIVIDAD * 3600;
    $tUsada = $token($pdo, 10, 2, 3 * 86400, $horas - 3600);
    $chk('en otra empresa, usada hace ' . (AccesoGlobal::HORAS_INACTIVIDAD - 1) . ' h -> vale', $auth->validateToken($tUsada)['valid'] === true);
    $tOciosa = $token($pdo, 10, 2, 3 * 86400, $horas + 60);
    $chk('en otra empresa, sin uso hace ' . AccesoGlobal::HORAS_INACTIVIDAD . ' h y 1 min -> NO vale', $auth->validateToken($tOciosa)['valid'] === false);
    $chk('...y queda revocada', $activo($pdo, $tOciosa) === 0);
    $tNunca = $token($pdo, 10, 2, $horas + 60);
    $chk('en otra empresa, nunca usada y abierta hace mas de ' . AccesoGlobal::HORAS_INACTIVIDAD . ' h -> NO vale', $auth->validateToken($tNunca)['valid'] === false);
    $tPropiaOciosa = $token($pdo, 10, 1, 30 * 86400, 20 * 86400);
    $chk('en SU empresa no vence (como cualquier usuario), aunque lleve dias sin uso', $auth->validateToken($tPropiaOciosa)['valid'] === true);

    echo "\nParte 2 — loginUser: la respuesta solo cambia para soporte\n";
    $lN = $auth->loginUser('normal', 'clave1234');
    $chk('login normal: exito, token en su empresa', $lN[0] === 'success' && $empresaDelToken($pdo, $lN[1]['token']) === 1);
    $chk('login normal: sin acceso_global ni empresa (respuesta de siempre)',
        $lN[0] === 'success' && !array_key_exists('acceso_global', $lN[1]['user']) && !array_key_exists('empresa', $lN[1]['user']));
    $chk('login normal: mismas claves que antes',
        $lN[0] === 'success' && array_keys($lN[1]['user']) === ['id', 'email', 'username', 'name', 'role', 'permissions']);
    $lS = $auth->loginUser('soporte@x.test', 'clave1234');
    $chk('login soporte: token en SU empresa', $lS[0] === 'success' && $empresaDelToken($pdo, $lS[1]['token']) === 1);
    $chk('login soporte: acceso_global y empresa (la suya)',
        $lS[0] === 'success' && ($lS[1]['user']['acceso_global'] ?? null) === true && ($lS[1]['user']['empresa']['id'] ?? null) === 1);
    $chk('login soporte: empresa sin secretos (id, nombre, rnc, ambiente y propia)',
        $lS[0] === 'success' && array_keys($lS[1]['user']['empresa']) === ['id', 'nombre', 'rnc', 'ambiente', 'propia']);
    $chk('login soporte: en su empresa, propia = true', $lS[0] === 'success' && $lS[1]['user']['empresa']['propia'] === true);

    echo "\nParte 2 — empresasAccesoGlobal (GET /api/auth/empresas)\n";
    $lista = $auth->empresasAccesoGlobal(10);
    $chk('soporte: las app activas no Express, por nombre (Ambas, Ferre, Gratex)',
        is_array($lista) && array_column($lista, 'id') === [6, 2, 1]);
    $chk('soporte: marca su empresa como propia',
        is_array($lista) && array_column($lista, 'propia', 'id') === [6 => false, 2 => false, 1 => true]);
    $chk('soporte: cada empresa solo con id, nombre, rnc, ambiente y propia',
        is_array($lista) && array_keys($lista[0]) === ['id', 'nombre', 'rnc', 'ambiente', 'propia']);
    $chk('usuario normal -> null (403 en el controller)', $auth->empresasAccesoGlobal(11) === null);

    echo "\nParte 2 — cambiarEmpresaSesion (POST /api/auth/empresa)\n";
    $contarTokens = fn(PDO $db): int => (int) $db->query('SELECT COUNT(*) FROM api_tokens')->fetchColumn();
    $antes = $contarTokens($pdo);
    $r = $auth->cambiarEmpresaSesion(11, 2);
    $chk('usuario normal -> 403 SIN_ACCESO_GLOBAL', $r[0] === 'error' && $r[2] === 403 && $r[3] === AccesoGlobal::CODIGO_SIN_ACCESO);
    foreach ([3 => 'integracion', 4 => 'solo Express', 5 => 'inactiva', 999 => 'inexistente'] as $destino => $que) {
        $r = $auth->cambiarEmpresaSesion(10, $destino);
        $chk("soporte a una empresa {$que} -> 404 EMPRESA_NO_DISPONIBLE",
            $r[0] === 'error' && $r[2] === 404 && $r[3] === AccesoGlobal::CODIGO_EMPRESA_NO_DISPONIBLE);
    }
    $chk('los rechazos no abren ninguna sesion', $contarTokens($pdo) === $antes);

    $r = $auth->cambiarEmpresaSesion(10, 2);
    $nuevo = $r[0] === 'success' ? (string) ($r[1]['token'] ?? '') : '';
    $chk('soporte a Ferre -> exito, con la forma del login (token y user)', $r[0] === 'success' && $nuevo !== '' && isset($r[1]['user']));
    $chk('es un token NUEVO, en la empresa 2', $nuevo !== $tS1 && $empresaDelToken($pdo, $nuevo) === 2);
    $v = $auth->validateToken($tS1);
    $chk('la sesion de la que sale sigue valida EN SU EMPRESA (otra pestaña no cambia sola)',
        $v['valid'] === true && $v['tenant_id'] === 1);
    $v = $auth->validateToken($nuevo);
    $chk('con el token nuevo, la peticion siguiente corre en la empresa 2', $v['valid'] === true && $v['tenant_id'] === 2);
    $chk('el user trae la empresa 2, con propia = false (es la de un cliente)',
        $r[0] === 'success' && ($r[1]['user']['empresa']['id'] ?? null) === 2 && ($r[1]['user']['empresa']['propia'] ?? null) === false);
    $chk('los permisos son los del admin DE FERRE, no los de su empresa',
        $r[0] === 'success' && $ordenado($r[1]['user']['permissions']) === ['clients', 'facturas']);
    $chk('la bitacora recibe la empresa 2', $r[0] === 'success' && ($r[2]['tenant_id'] ?? null) === 2);
    $chk('...y la empresa del usuario (para no nombrar en la bitacora a otro cliente)',
        $r[0] === 'success' && ($r[2]['empresa_usuario'] ?? null) === 1);
    $antes = $contarTokens($pdo);
    $r = $auth->cambiarEmpresaSesion(10, 2, 2);
    $chk('a la empresa en la que ya esta la sesion -> 409 MISMA_EMPRESA, sin abrir otra',
        $r[0] === 'error' && $r[2] === 409 && $r[3] === AccesoGlobal::CODIGO_MISMA_EMPRESA && $contarTokens($pdo) === $antes);
    $r = $auth->cambiarEmpresaSesion(11, 1, 1);
    $chk('un usuario normal a su misma empresa -> 403 (primero se mira el acceso)', $r[0] === 'error' && $r[2] === 403);
    $r = $auth->cambiarEmpresaSesion(10, 1);
    $chk('de vuelta a su empresa -> token en la empresa 1, propia = true',
        $r[0] === 'success' && $empresaDelToken($pdo, $r[1]['token']) === 1 && ($r[1]['user']['empresa']['propia'] ?? null) === true);

    echo "\nParte 2 — getUserProfile (GET /api/auth/me) con la empresa de la sesion\n";
    $p = $auth->getUserProfile(11, 1);
    $chk('usuario normal: permisos de su rol en su empresa, sin claves nuevas',
        $p !== null && $p['permissions'] === ['facturas'] && !array_key_exists('acceso_global', $p));
    $p = $auth->getUserProfile(10, 2);
    $chk('soporte en Ferre: permisos del admin de Ferre y empresa 2',
        $p !== null && $ordenado($p['permissions']) === ['clients', 'facturas'] && ($p['empresa']['id'] ?? null) === 2);
    $p = $auth->getUserProfile(10);
    $chk('sin empresa de sesion (como antes): la del usuario', $p !== null && $p['permissions'] === ['*'] && ($p['empresa']['id'] ?? null) === 1);

    echo "\nParte 2 — iniciarSesionPorId (canje del POS) con la empresa del codigo\n";
    $s = $auth->iniciarSesionPorId(11, 1);
    $chk('usuario normal en su empresa -> token en su empresa', $s[0] === 'success' && $empresaDelToken($pdo, $s[1]['token']) === 1);
    $s = $auth->iniciarSesionPorId(11, 2);
    $chk('usuario normal en OTRA empresa -> error SIN_ACCESO_GLOBAL', $s[0] === 'error' && ($s[2] ?? null) === AccesoGlobal::CODIGO_SIN_ACCESO);
    $s = $auth->iniciarSesionPorId(10, 2);
    $chk('soporte con un codigo de Ferre -> token EN FERRE', $s[0] === 'success' && $empresaDelToken($pdo, $s[1]['token']) === 2);
    $chk('soporte con un codigo de Ferre -> user con la empresa 2', $s[0] === 'success' && ($s[1]['user']['empresa']['id'] ?? null) === 2);
    $s = $auth->iniciarSesionPorId(10, 4);
    $chk('soporte con una empresa solo Express -> error', $s[0] === 'error');
    $s = $auth->iniciarSesionPorId(11);
    $chk('sin empresa (como antes): token en la del usuario', $s[0] === 'success' && $empresaDelToken($pdo, $s[1]['token']) === 1);

    echo "\nParte 2 — la cuenta de soporte no se toca desde Usuarios ni Roles\n";
    require_once __DIR__ . '/../src/Models/userModel.php';
    $usuarios = new userModel();
    $roles = new RoleModel();
    $claveSoporte = (string) $pdo->query('SELECT password FROM users WHERE id = 10')->fetchColumn();
    $r = $usuarios->updateUser(1, 10, ['password' => 'robada123'], 11);
    $chk('otro usuario de su empresa (aun admin) NO le cambia la clave -> 403',
        $r[0] === 'error' && ($r[2] ?? null) === 403 && $pdo->query('SELECT password FROM users WHERE id = 10')->fetchColumn() === $claveSoporte);
    $r = $usuarios->updateUser(1, 10, ['email' => 'otro@x.test'], 12);
    $chk('ni el correo -> 403', $r[0] === 'error' && ($r[2] ?? null) === 403);
    $r = $usuarios->deleteUser(1, 10);
    $chk('ni lo borra -> 403, y sigue ahi', $r[0] === 'error' && ($r[2] ?? null) === 403
        && (int) $pdo->query('SELECT COUNT(*) FROM users WHERE id = 10')->fetchColumn() === 1);
    $r = $roles->assignUserRole(1, 10, 'user');
    $chk('ni le cambia el rol por /api/roles/assign -> 403', $r[0] === 'error' && ($r[2] ?? null) === 403
        && $pdo->query('SELECT role FROM users WHERE id = 10')->fetchColumn() === 'admin');
    $r = $usuarios->updateUser(1, 10, ['role' => 'user'], 10);
    $chk('ni el mismo se cambia el rol -> 403', $r[0] === 'error' && ($r[2] ?? null) === 403);
    $r = $usuarios->updateUser(1, 10, ['name' => 'Soporte', 'role' => 'admin'], 10);
    $chk('el mismo si cambia su nombre (el rol que manda el formulario es el mismo)', $r[0] === 'success'
        && $pdo->query('SELECT name FROM users WHERE id = 10')->fetchColumn() === 'Soporte');
    $r = $usuarios->updateUser(1, 11, ['name' => 'Normal'], 10);
    $chk('un usuario normal se edita como siempre', $r[0] === 'success');
    $r = $roles->assignUserRole(1, 11, 'admin');
    $chk('...y se le cambia el rol como siempre', $r[0] === 'success');
    $roles->assignUserRole(1, 11, 'user');

    echo "\nParte 2 — Cerrar sesion de un usuario de soporte cierra todas las suyas\n";
    $chk('tieneAccesoGlobal: soporte si, normal no', $auth->tieneAccesoGlobal(10) && !$auth->tieneAccesoGlobal(11));
    $tOtraA = $token($pdo, 10, 2);
    $tOtraB = $token($pdo, 10, 6);
    $tNormal = $token($pdo, 11, 1);
    $abiertas = (int) $pdo->query('SELECT COUNT(*) FROM api_tokens WHERE user_id = 10 AND is_active = 1')->fetchColumn();
    $cerradas = $auth->cerrarTodasLasSesiones(10);
    $chk("cierra todas las del usuario ({$abiertas}) y dice cuantas", $abiertas >= 3 && $cerradas === $abiertas
        && (int) $pdo->query('SELECT COUNT(*) FROM api_tokens WHERE user_id = 10 AND is_active = 1')->fetchColumn() === 0);
    $chk('ninguna de otro usuario', $activo($pdo, $tNormal) === 1);
    $chk('...y ya no valen', $auth->validateToken($tOtraA)['valid'] === false && $auth->validateToken($tOtraB)['valid'] === false);

    echo "\nParte 2 — master SIN la columna (codigo subido antes que la 019)\n";
    $viejo = $crearMaster(false);
    $usarMaster($viejo);
    $authViejo = new authModel();
    $vN = $token($viejo, 11, 1);
    $vS1 = $token($viejo, 10, 1);
    $vS2 = $token($viejo, 10, 2);
    $chk('usuario normal en su empresa -> vale', $authViejo->validateToken($vN)['valid'] === true);
    $chk('soporte en su empresa -> vale', $authViejo->validateToken($vS1)['valid'] === true);
    $chk('un token en otra empresa -> NO vale (nadie tiene el acceso)', $authViejo->validateToken($vS2)['valid'] === false);
    $chk('...pero no se revoca: sin la columna no se sabe, y un fallo no debe cerrar sesiones', $activo($viejo, $vS2) === 1);
    $l = $authViejo->loginUser('soporte', 'clave1234');
    $chk('el login sigue funcionando, sin las claves nuevas',
        $l[0] === 'success' && !array_key_exists('acceso_global', $l[1]['user']) && !array_key_exists('empresa', $l[1]['user']));
    $chk('empresasAccesoGlobal -> null', $authViejo->empresasAccesoGlobal(10) === null);
    $antesViejo = (int) $viejo->query('SELECT COUNT(*) FROM api_tokens')->fetchColumn();
    $r = $authViejo->cambiarEmpresaSesion(10, 2);
    $chk('cambiarEmpresaSesion -> 403 y no abre ninguna sesion',
        $r[0] === 'error' && $r[2] === 403 && (int) $viejo->query('SELECT COUNT(*) FROM api_tokens')->fetchColumn() === $antesViejo);
}

// =============================================================================
// Parte 3 — El cableado de los controllers (leyendo el fuente, sin base)
// =============================================================================
// Las partes 1 y 2 prueban los modelos. Esto fija que los controllers les pasen la
// empresa de la SESION (no la del usuario), el actor y los codigos: si alguien quita
// uno de esos argumentos, los modelos siguen en verde y /me o el canje del POS vuelven
// a la empresa del usuario, o la cuenta de soporte vuelve a ser editable.
echo "\nParte 3 — cableado de authController, userController y roleController (fuente)\n";
$fuenteAuth = (string) file_get_contents(__DIR__ . '/../src/Controllers/authController.php');
$fuenteUsers = (string) file_get_contents(__DIR__ . '/../src/Controllers/userController.php');
$fuenteRoles = (string) file_get_contents(__DIR__ . '/../src/Controllers/roleController.php');
$funcion = static function (string $fuente, string $nombre): string {
    // \R: los controllers pueden tener fin de linea CRLF.
    return preg_match('/function ' . $nombre . '\(.*?\R}\R/s', $fuente, $m) === 1 ? $m[0] : '';
};
$cambio = $funcion($fuenteAuth, 'authCambiarEmpresa');
$canje = $funcion($fuenteAuth, 'authPosCanje');
$chk('se encontraron authCambiarEmpresa y authPosCanje', $cambio !== '' && $canje !== '');
$chk('/me calcula el perfil con la empresa del token',
    str_contains($fuenteAuth, 'getUserProfile($v[\'user_id\'], isset($v[\'tenant_id\']) ? (int) $v[\'tenant_id\'] : null)'));
$chk('el canje del POS usa la empresa del codigo para el perfil y para la sesion',
    str_contains($canje, 'getUserProfile($canje[\'user_id\'], (int) $canje[\'tenant_id\'])')
    && str_contains($canje, 'iniciarSesionPorId($canje[\'user_id\'], (int) $canje[\'tenant_id\'])'));
$chk('el cambio pasa la empresa actual (409 a la misma) y responde {token, user}',
    str_contains($cambio, 'cambiarEmpresaSesion($userId, $destino, $desde)')
    && str_contains($cambio, '[\'token\' => $r[1][\'token\'], \'user\' => $perfil]'));
$chk('el cambio deja CAMBIO_EMPRESA en la bitacora de la empresa DESTINO',
    preg_match('/\'action\'\s*=> \'CAMBIO_EMPRESA\',\s*\'tenant_id\'\s*=> \$destino,/', $cambio) === 1);
$chk('...y nombra el origen solo si es la empresa del propio usuario (nunca otro cliente)',
    str_contains($cambio, '$desde === (int) ($r[2][\'empresa_usuario\'] ?? -1)')
    && preg_match('/\'old_values\'\s*=> \$desdePropia \? \[\'tenant_id\' => \$desde\] : null/', $cambio) === 1);
$chk('un tenant_id que no sirve responde 400 EMPRESA_REQUERIDA',
    preg_match('/http_response_code\(400\);\s*echo json_encode\(\[[^\]]*CODIGO_EMPRESA_REQUERIDA/', $cambio) === 1);
$chk('Cerrar sesion con {todas:true} cierra todas las sesiones, solo de un usuario de soporte',
    preg_match('/!empty\(\$_POST->todas\)[^;]*tieneAccesoGlobal\(\$userIdSalida\)\)\s*\{\s*\$otras = \$authModel->cerrarTodasLasSesiones\(\$userIdSalida\);/s', $fuenteAuth) === 1);
$chk('PUT /api/users pasa quien hace el cambio (a soporte solo lo cambia el mismo)',
    str_contains($fuenteUsers, 'updateUser($tenantId, $id, $fields, (int) ($me[\'user_id\'] ?? 0))'));
$chk('users y roles responden con el codigo que da el modelo (403 para soporte)',
    substr_count($fuenteUsers, '$res[2] ?? 400') === 2 && str_contains($fuenteRoles, '$res[2] ?? 400'));

echo "\n" . ($fallos === 0
    ? "== TODO OK: {$total} comprobaciones ==\n"
    : "== {$fallos} de {$total} FALLARON ==\n");
exit($fallos === 0 ? 0 : 1);
