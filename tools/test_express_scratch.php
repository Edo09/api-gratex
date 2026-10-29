<?php
/**
 * test_express_scratch.php — Fiscalpoint Express, plan 02 contra un MySQL de verdad: las bases scratch del
 * write-ahead y del POS (la master es wa_scratch_sin_gate; la empresa A vive en wa_scratch). Su mitad sin base
 * de datos ni red es tools/test_express_entitlements.php.
 *
 * Cada tarea del plan agrega su seccion justo encima del bloque de cierre (wa_fin), en orden. Las variables de
 * cada seccion llevan su numero (s1, s2...); $ctx es el entorno que arma express_scratch_preparar() y que las
 * secciones comparten.
 *
 * Que dice cada seccion:
 *   1) la migracion master 014 sobre la master scratch: aplica de cero, es idempotente, completa un estado a
 *      medias, su guardia corta antes de tocar nada, el ENUM rechaza una edicion desconocida, el verificador de
 *      migraciones la ve, y express_scratch_preparar() deja las dos empresas de prueba como completas.
 *   2) user.entitlements en POST /api/auth/login y GET /api/auth/me, por HTTP: la empresa A Express recibe el bloque
 *      y los permisos cruzados con su edicion (administrador y Facturador); la B, completa, el user de siempre.
 *   3) el bloque certificado de GET /api/emisor, por HTTP: la empresa A Express lo recibe (por vencer a 45 dias, sin la ruta
 *      del certificado ni ninguna clave cert_), lo toma de la fila viva en cada peticion y, aun vencido, no bloquea nada; la
 *      B, completa, no lo recibe.
 *   4) la biblioteca de preflight (tools/express_edicion_lib.php) sobre la empresa A: express_conectar_tenant abre su base,
 *      express_leer_certificado abre el .p12 desechable (bueno, con otra clave, sin archivo, sin clave y vencido) y vence igual que la
 *      emision (ECFEmissionService::exigirCertificadoVigente), express_preflight_hechos lee los hechos de A y cada uno coincide con una
 *      lectura independiente de la base, el predicado de rangos coincide con ncfModel (dispenseNextECF y rangoCubre) sobre filas reales,
 *      las sentencias del plan que borran en la base de la empresa corren contra su esquema real (sin llaves foraneas hacia clients, en
 *      una transaccion que se deshace), y D4: lo que certificacion dejo en certecf (una factura, un contador) no cambia ningun hecho de
 *      ecf ni suma ningun rechazo.
 *   5) tools/tenant_edicion.php sobre la empresa A, en un hijo (express_cli): el ensayo no escribe nada, los rechazos (un tipo que Express
 *      no vende, un tipo mal escrito, un modulo que no existe, la migracion de tenant 036 ausente, una empresa que no existe) salen con 3 sin
 *      tocar ninguna base, aplicar convierte a A (edicion, tipos, vencimiento del certificado, POS, inventario, credencial de integracion,
 *      rol user, rangos ecf sin limite y clientes de prueba sin facturas) con UNA fila de auditoria, lo de certecf nunca bloquea (D4), la
 *      credencial de integracion de A funciona antes y no despues (y de nuevo tras revertir), --refrescar-cert deja a cert_vence en la fecha
 *      del certificado y GET /api/emisor la informa, revertir deja a A en completa con su hash de integracion y su token ya no recibe
 *      entitlements, y express_aplicar hace rollback de verdad (InnoDB) cuando falla un paso de la master.
 *   5b) tools/create_tenant.php --edicion=express --tipos-ecf=45 sobre la master scratch, en un hijo (express_cli): sin una columna de la 014
 *      (edicion, y por separado cert_vence) sale con 1 antes de crear nada y nombra db/master_migrations/014_tenants_edicion.sql y la columna
 *      que falta; con las cuatro puestas la misma orden ya no dice nada de la 014. La base de la empresa de la prueba no existe, asi que ni una
 *      corrida sin la comprobacion puede dejar una empresa.
 *
 * Las ayudas de las secciones (una sola familia, que los planes 03 y 04 reutilizan): express_scratch_preparar,
 * express_edicion (deja a A o B con la edicion y las listas dadas), express_api (levanta la API de la rama o de otra
 * raiz), express_login, express_h (la cabecera de sesion), express_usar_master (apunta MasterDatabase y
 * TenantResolver de ESTE proceso a la master scratch) y express_cli (corre tools/tenant_edicion.php, o la herramienta
 * que se pida, en un php hijo contra esa master).
 *
 * Nunca produccion: wa_pdo() se niega a usar una base sin la tabla marca zz_write_ahead_scratch y
 * pos_scratch_exigir() una que no se llame wa_scratch o wa_scratch_sin_gate. Las credenciales salen de
 * tools/.env (o del entorno) y no se imprimen.
 *
 * La seccion 1 quita y vuelve a crear columnas de tenants en la master scratch (y deja a las dos empresas de
 * prueba como completas al terminar): no corras a la vez otra suite que use esas bases.
 *
 * Las APIs que levanta express_api() (php -S, un puerto cada una; este plan usa del 8291 al 8294) corren con
 * PERMISSIONS_ENFORCE=false (modo sombra) o, con --enforce, en true: lo que prueba la suite tiene que valer en
 * los dos modos, y con la zona horaria por defecto de este proceso (la del php.ini o la de un -d date.timezone= al
 * correr la suite: el hijo no hereda ese -d, asi que express_api() se la pasa). Un php -S que quedo de una corrida
 * interrumpida (Ctrl+C en Windows puede saltarse el cierre) en el modo contrario, de otro arbol o de otra zona, hace que
 * wa_servidor pare con "[FALLO] el puerto ... ya lo usa otro proceso": detenlo y vuelve a correr.
 *
 * Uso:
 *   php -d extension=curl -d extension=openssl -d extension=pdo_mysql tools/test_express_scratch.php [--enforce]
 *   (un -d extension=... solo para lo que tu php.ini no carga; ver write_ahead_test_lib.php)
 * Sale con 1 si algo falla.
 */

require_once __DIR__ . '/pos_scratch_prepare.php';
// pos_scratch_env_api() y pos_scratch_env_base_por_defecto(): el entorno de la API de las suites del POS. Incluirlo solo
// define funciones; su programa principal corre unicamente cuando es ese archivo el que se ejecuta.
require_once __DIR__ . '/test_pos_scratch.php';

/** La migracion master 014, tal como se pega en phpMyAdmin. */
const EXPRESS_MIGRACION_014 = __DIR__ . '/../db/master_migrations/014_tenants_edicion.sql';

/** Puerto de loopback donde no escucha nadie: las APIs de esta suite no tienen DGII, y lo que intente llamarla falla al instante. */
const EXPRESS_DGII_SIN_SERVICIO = 'http://127.0.0.1:1';

/**
 * Corre un archivo SQL entero en una sola llamada, como cuando se pega en la pestaña SQL de phpMyAdmin
 * (PDO_MYSQL acepta varias sentencias con los prepares emulados). nextRowset() pasa por cada sentencia, asi que
 * un error en la sentencia N salta como excepcion y no se pierde.
 * @return array las filas del ultimo resultado con columnas (el SELECT final de una migracion)
 */
function correrSql(PDO $pdo, string $sql): array
{
    $stmt = $pdo->query($sql);
    $filas = [];
    do {
        if ($stmt->columnCount() > 0) {
            $filas = $stmt->fetchAll();
        }
    } while ($stmt->nextRowset());
    return $filas;
}

/**
 * Corre un SQL que tiene que FALLAR y devuelve el error de MySQL que lo detuvo, [codigo, mensaje]; null si termino
 * sin error. Al mensaje se le tapa el 'usuario'@'host' que el driver suele traer: nada de eso se imprime.
 * @return ?array{0:int,1:string}
 */
function errorDeSql(PDO $pdo, string $sql): ?array
{
    try {
        correrSql($pdo, $sql);
        return null;
    } catch (PDOException $e) {
        $info = (array) $e->errorInfo;
        return [(int) ($info[1] ?? 0), (string) preg_replace("/'[^']*'@'[^']*'/", "'***'@'***'", (string) ($info[2] ?? ''))];
    }
}

/**
 * El entorno scratch de las pruebas de Express: el de las suites del POS (pos_scratch_preparar: master
 * wa_scratch_sin_gate, empresa A en wa_scratch, empresa B en wa_scratch_sin_gate, con sus roles y usuarios) mas la
 * migracion master 014 aplicada a esa master. Deja las dos empresas de prueba como completas: edicion = 'completa',
 * las otras tres columnas de la 014 en NULL y sin certificado. Lo mismo se repite al terminar este proceso (lo que
 * una prueba cambie en las empresas se restaura solo, tambien si la prueba falla): primero pos_scratch_preparar()
 * devuelve roles, usuarios y campos del POS, que nunca toca las columnas de la 014, y despues se reponen ellas. Si
 * una corrida anterior murio sin llegar a esa restauracion (kill -9), el reinicio de arriba lo arregla en la
 * siguiente: no se filtra a la comparacion dorada del plan 01 ni a las suites del POS.
 * @return array{master:string, dbA:string, dbB:string, tenantA:int, tenantB:int, cred:array, pdo:PDO}
 *         lo de pos_scratch_preparar() mas 'pdo' sobre la master scratch (wa_scratch_sin_gate)
 */
function express_scratch_preparar(): array
{
    static $restauracionPuesta = false;
    // La 014 (idempotente: tambien repone lo que una prueba haya quitado) y el reinicio de las dos empresas de
    // prueba, por RNC porque los id cambian si alguien limpia y vuelve a preparar.
    $reiniciar = static function (PDO $pdo): void {
        correrSql($pdo, (string) file_get_contents(EXPRESS_MIGRACION_014));
        $rncs = array_column(POS_SCRATCH_TENANTS, 'rnc');
        $pdo->prepare(
            "UPDATE tenants SET edicion = 'completa', modulos_habilitados = NULL, tipos_ecf_habilitados = NULL, cert_vence = NULL,
                    cert_path = NULL, cert_pass_encrypted = NULL
             WHERE rnc IN (" . implode(',', array_fill(0, count($rncs), '?')) . ')'
        )->execute($rncs);
    };
    $info = pos_scratch_preparar();
    $pdo = wa_pdo(POS_SCRATCH_BASE_MASTER);
    $reiniciar($pdo);
    if (!$restauracionPuesta) {
        $restauracionPuesta = true;
        register_shutdown_function(static function () use ($reiniciar): void {
            try {
                pos_scratch_preparar();
                $reiniciar(wa_pdo(POS_SCRATCH_BASE_MASTER));
            } catch (Throwable $e) {
                // wa_describir_excepcion: clase, archivo:linea y codigos; nunca el mensaje del driver (trae usuario y host).
                echo '[FALLO] no se pudo dejar limpia la master scratch al terminar: ' . wa_describir_excepcion($e) . "\n";
                exit(1);
            }
        });
    }
    return $info + ['pdo' => $pdo];
}

/**
 * Deja a la empresa de prueba $tenant ('A' o 'B') con la edicion y las listas dadas: un UPDATE sobre la master scratch. Lo que
 * se pasa es lo que queda, tal cual: $tipos, $modulos y $certVence en null son NULL en la fila (no "sin tocar"), y $edicion en
 * null es la edicion por defecto de la columna, 'completa'. No toca nada mas de la fila (ni cert_path, ni cert_pass_encrypted,
 * ni pos_enabled). Lo que cambia aqui no hay que restaurarlo a mano: express_scratch_preparar() ya dejo registrado el
 * reinicio de las dos empresas para cuando termine el proceso, y $ctx solo sale de esa funcion.
 *
 * @param array $ctx lo que devuelve express_scratch_preparar()
 */
function express_edicion(array $ctx, string $tenant, ?string $edicion, ?string $tipos, ?string $modulos, ?string $certVence = null): void
{
    $ids = ['A' => (int) ($ctx['tenantA'] ?? 0), 'B' => (int) ($ctx['tenantB'] ?? 0)];
    if (($ids[$tenant] ?? 0) <= 0) {
        throw new InvalidArgumentException("las empresas de prueba son 'A' y 'B', no '{$tenant}'");
    }
    wa_exigir_marca($ctx['pdo']);
    $ctx['pdo']->prepare(
        'UPDATE tenants SET edicion = :e, tipos_ecf_habilitados = :t, modulos_habilitados = :m, cert_vence = :c WHERE id = :id'
    )->execute([':e' => $edicion ?? 'completa', ':t' => $tipos, ':m' => $modulos, ':c' => $certVence, ':id' => $ids[$tenant]]);
}

/**
 * Levanta (o reutiliza) una API multi-tenant sobre las bases scratch y devuelve su URL: el mismo entorno que la API de las suites
 * del POS (pos_scratch_env_api y pos_scratch_env_base_por_defecto), PERMISSIONS_ENFORCE en true solo con --enforce, y una DGII
 * en un puerto de loopback sin nadie (EXPRESS_DGII_SIN_SERVICIO). $raiz es el arbol del API; '' es el de esta rama. Se niega
 * (salida 1) si el arbol trae un .env: lo cargaria el servidor y una peticion sin empresa podria caer en la base que diga.
 *
 * La zona horaria por defecto del servidor es la de ESTE proceso, fijada con -d date.timezone=: el hijo no hereda un -d de quien corre la
 * suite (solo el php.ini), y una prueba que cuenta dias contra el reloj de la API (cert_vence y "hoy", en la zona de PHP) tiene que
 * contar el mismo "hoy" que ella sin suponerlo. La zona se toma una sola vez por proceso, en la primera llamada: todos los servidores
 * de la corrida comparten la misma aunque una seccion cambie la zona de la suite despues.
 *
 * El nombre con el que se registra el servidor lleva el modo, el arbol y la zona ("{$nombre}_enf|som_<hash de la raiz y la zona>"): un
 * php -S que quedo de otra corrida en ese puerto, del otro modo, de otro arbol o de otra zona, no se reutiliza con la configuracion
 * equivocada; wa_servidor se detiene con "[FALLO] el puerto ... ya lo usa otro proceso". La salida del servidor queda en el temp del
 * sistema (wa_<nombre>_<puerto>.log).
 */
function express_api(string $nombre, int $puerto, string $raiz = ''): string
{
    static $zona = null;
    $zona ??= date_default_timezone_get();
    $arbol = $raiz !== '' ? $raiz : dirname(__DIR__);
    if (is_file($arbol . '/.env')) {
        echo "[FALLO] el arbol del API a probar tiene un .env: el servidor lo cargaria y una peticion sin empresa podria caer en la base de ese archivo\n";
        exit(1);
    }
    $cred = wa_credenciales();
    $master = wa_base_real(POS_SCRATCH_BASE_MASTER);
    $enforce = wa_arg('enforce') !== null;
    $env = array_merge(
        pos_scratch_env_api($cred, $master, pos_scratch_secretos($cred['pass']), EXPRESS_DGII_SIN_SERVICIO, wa_cert_prueba(), $enforce),
        pos_scratch_env_base_por_defecto($cred, $master)
    );
    $servidor = $nombre . '_' . ($enforce ? 'enf' : 'som') . '_' . substr(md5($arbol . '|' . $zona), 0, 6);
    return wa_servidor($servidor, 'index.php', $puerto, $env, $raiz, ['-d', 'date.timezone=' . $zona]);
}

/**
 * Inicia sesion como $usuario (uno de los usuarios de prueba: admin_pos_a, user_pos_a, admin_pos_b) en la API $api y devuelve
 * ['token' => la sesion, 'user' => el user de la respuesta]. Sale con 1, con la linea [FALLO] y lo que contesto la API
 * (enmascarado), si no hay sesion: una prueba que necesita una sesion no tiene nada que decir sin ella.
 *
 * @return array{token:string, user:array}
 */
function express_login(string $api, string $usuario): array
{
    $cred = wa_credenciales();
    $secretos = pos_scratch_secretos($cred['pass']);
    $r = wa_http('POST', $api . '/api/auth/login', ['emailOrUsername' => $usuario, 'password' => $secretos['clave_usuarios']]);
    $token = (string) ($r['json']['data']['token'] ?? '');
    if ($token === '') {
        echo "[FALLO] no se pudo iniciar sesion como {$usuario} en {$api}: HTTP {$r['status']} "
            . pos_scratch_enmascarar(substr($r['body'], 0, 300), array_merge([$cred['pass'], $cred['user'], $cred['host']], array_values($secretos))) . "\n";
        exit(1);
    }
    return ['token' => $token, 'user' => (array) ($r['json']['data']['user'] ?? [])];
}

/** La cabecera de una sesion de usuario, lista para wa_http (X-API-KEY, como la mandan las otras suites). @return string[] */
function express_h(string $token): array
{
    return ['X-API-KEY: ' . $token];
}

/**
 * Apunta MasterDatabase y TenantResolver de ESTE proceso a la master scratch, como wa_usar_base lo hace con la base de una
 * empresa: MasterDatabase lee solo $_ENV, asi que llena $_ENV y putenv con MASTER_DB_* y deja la clave maestra de prueba para
 * que TenantResolver descifre las claves de las bases. Hay que llamarla antes de la primera vez que algo pida
 * MasterDatabase::getInstance() en este proceso (la instancia se crea una vez y ya no cambia de base).
 *
 * @param array $ctx lo que devuelve express_scratch_preparar()
 */
function express_usar_master(array $ctx): void
{
    $c = $ctx['cred'];
    foreach ([
        'MASTER_DB_HOST' => $c['host'], 'MASTER_DB_PORT' => $c['port'], 'MASTER_DB_NAME' => $ctx['master'],
        'MASTER_DB_USER' => $c['user'], 'MASTER_DB_PASS' => $c['pass'],
        'MASTER_ENCRYPTION_KEY' => pos_scratch_secretos($c['pass'])['clave_maestra'],
    ] as $clave => $valor) {
        $_ENV[$clave] = $valor;
        putenv("{$clave}={$valor}");
    }
}

/**
 * Corre una herramienta de linea de comandos de tools/ (tenant_edicion.php) contra la master scratch: un php hijo con la raiz del API de
 * directorio de trabajo, las extensiones que el php.ini no carga (wa_php_flags), -d variables_order=EGPCS (MasterDatabase lee su configuracion de
 * $_ENV y no de getenv) y la zona horaria por defecto de ESTE proceso (-d date.timezone=, que el hijo no hereda por si solo: lo que escribe una
 * herramienta con fechas, como el cert_vence de tenant_edicion.php, se cuenta en esa zona y la prueba lo compara con su propio calendario).
 * $args son los argumentos despues de php: el script, relativo a la raiz del API, y los suyos.
 *
 * La master y la clave maestra de prueba le llegan por el entorno de ESTE proceso, donde las puso express_usar_master($ctx): hay que haberla
 * llamado antes. Se niega (salida 1) si falta alguna de esas variables, si MASTER_DB_NAME no es la master scratch o si la raiz del API trae un
 * .env (MasterDatabase lo cargaria). No imprime nada por si misma.
 *
 * @param string[] $args
 * @return array{codigo:int, salida:string} lo de wa_correr: stdout y stderr juntos
 */
function express_cli(array $args): array
{
    if (is_file(dirname(__DIR__) . '/.env')) {
        echo "[FALLO] la raiz del API tiene un .env: la herramienta lo cargaria y podria caer en la base que diga\n";
        exit(1);
    }
    $faltan = array_values(array_filter(
        ['MASTER_DB_HOST', 'MASTER_DB_PORT', 'MASTER_DB_NAME', 'MASTER_DB_USER', 'MASTER_DB_PASS', 'MASTER_ENCRYPTION_KEY'],
        static fn (string $nombre): bool => (string) getenv($nombre) === ''
    ));
    if ($faltan !== []) {
        echo '[FALLO] express_cli necesita que express_usar_master($ctx) se haya llamado antes: falta ' . implode(', ', $faltan) . "\n";
        exit(1);
    }
    if (getenv('MASTER_DB_NAME') !== wa_base_real(POS_SCRATCH_BASE_MASTER)) {
        echo '[FALLO] express_cli solo corre contra la master scratch (' . wa_base_corta(wa_base_real(POS_SCRATCH_BASE_MASTER)) . "): MASTER_DB_NAME es otra\n";
        exit(1);
    }
    return wa_correr(array_merge([PHP_BINARY], wa_php_flags(), ['-d', 'variables_order=EGPCS', '-d', 'date.timezone=' . date_default_timezone_get()], $args));
}

// Modo hijo: lo lanza la seccion 1 para ver la restauracion de al terminar. Ensucia las dos empresas (columnas
// de la 014, campo del POS, un usuario y los permisos de un rol) y sale sin limpiar nada. No se corre a mano.
//   php <flags> tools/test_express_scratch.php --hijo=restauracion
if (wa_arg('hijo') === 'restauracion') {
    $hijo = express_scratch_preparar();
    $ids = [(int) $hijo['tenantA'], (int) $hijo['tenantB']];
    $hijo['pdo']->exec("UPDATE tenants SET edicion = 'express', modulos_habilitados = 'facturacion', tipos_ecf_habilitados = '45',
                               cert_vence = '2027-01-31', cert_path = 'certificado_dgii/prueba/cert.p12', pos_enabled = 0
                        WHERE id IN (" . implode(',', $ids) . ')');
    $hijo['pdo']->exec("UPDATE users SET role = 'user' WHERE username = 'admin_pos_a'");
    $hijo['pdo']->exec("DELETE FROM role_permissions WHERE role_id IN (SELECT id FROM roles WHERE tenant_id = {$ids[0]} AND name = 'user')");
    exit(0);
}

$ctx = express_scratch_preparar();

// ---------------------------------------------------------------------------
// 1) Migracion master 014 sobre la master scratch
// ---------------------------------------------------------------------------
echo "\n== 1) Migracion master 014 sobre la master scratch ==\n";
$s1Pdo = $ctx['pdo'];
$s1Sql = (string) file_get_contents(EXPRESS_MIGRACION_014);
$s1Cols = ['edicion', 'modulos_habilitados', 'tipos_ecf_habilitados', 'cert_vence'];
$s1Ids = [(int) $ctx['tenantA'], (int) $ctx['tenantB']];

/** Posicion de cada columna de tenants en la master scratch (nombre => ordinal). */
$s1Posiciones = static function () use ($s1Pdo): array {
    $st = $s1Pdo->query("SELECT COLUMN_NAME, ORDINAL_POSITION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenants'");
    return array_map('intval', array_column($st->fetchAll(), 'ORDINAL_POSITION', 'COLUMN_NAME'));
};
/** Quita de tenants las columnas de la 014 que se pidan (solo en la master scratch: wa_exigir_marca corta si la conexion no lo es). */
$s1Soltar = static function (array $columnas) use ($s1Pdo, $s1Cols, $s1Posiciones): void {
    wa_exigir_marca($s1Pdo);
    $hay = $s1Posiciones();
    foreach ($columnas as $columna) {
        if (!in_array($columna, $s1Cols, true)) {
            throw new InvalidArgumentException("no es una columna de la 014: {$columna}");
        }
        if (isset($hay[$columna])) {
            $s1Pdo->exec("ALTER TABLE tenants DROP COLUMN {$columna}");
        }
    }
};
/** Cuantas de las cuatro columnas de la 014 tiene tenants ahora. */
$s1Cuantas = static fn (): int => count(array_intersect($s1Cols, array_keys($s1Posiciones())));
/** La fila de una empresa de prueba (sus columnas de la 014, su certificado y pos_enabled), con cada valor en texto o NULL. */
$s1Fila = static function (int $id) use ($s1Pdo): array {
    $st = $s1Pdo->prepare('SELECT edicion, modulos_habilitados, tipos_ecf_habilitados, cert_vence, cert_path, cert_pass_encrypted, pos_enabled FROM tenants WHERE id = :id');
    $st->execute([':id' => $id]);
    return array_map(static fn ($valor): ?string => $valor === null ? null : (string) $valor, (array) $st->fetch());
};
/** Conexion al mismo servidor sin base seleccionada (null) o parada en una base de sistema: lo que ve la 014 si phpMyAdmin no tiene la master elegida. */
$s1SinBase = static function (?string $sistema): PDO {
    $c = wa_credenciales();
    set_error_handler(static fn (): bool => true); // mysqlnd avisa con el host: se calla mientras conecta
    try {
        $otra = new PDO("mysql:host={$c['host']};port={$c['port']};" . ($sistema === null ? '' : "dbname={$sistema};") . 'charset=utf8mb4',
            $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    } catch (PDOException $e) {
        echo '[FALLO] no se pudo conectar ' . ($sistema === null ? 'sin base' : "a {$sistema}") . " (codigo {$e->getCode()})\n";
        exit(1);
    } finally {
        restore_error_handler();
    }
    if (($otra->query('SELECT DATABASE()')->fetchColumn() ?: null) !== $sistema) {
        echo "[FALLO] la conexion de la guardia quedo en otra base: no se prueba nada\n";
        exit(1);
    }
    return $otra;
};
/**
 * ¿Es el error de la guardia de la 014? Con la base equivocada la guardia consulta `ALTO_elige_la_base_master_en_el_panel`.`x`:
 * MySQL contesta #1049 (la base no existe, o #1146 si existiera sin esa tabla) a quien tiene permisos globales, como el
 * usuario de phpMyAdmin, y #1142 (SELECT denegado en la tabla 'x') al usuario de pruebas, que solo llega a sus bases
 * scratch: revisa permisos antes que existencia (lo mismo que ve tools/test_conduces_mysql.php con la guardia de la 031).
 * Los tres nombran lo que puso la guardia.
 */
$s1EsGuardia = static fn (?array $e): bool => $e !== null
    && ((in_array($e[0], [1049, 1146], true) && str_contains($e[1], 'ALTO_elige_la_base_master'))
        || ($e[0] === 1142 && str_contains($e[1], "'x'")));

wa_chk('express_scratch_preparar(): lo del POS mas un PDO sobre la master scratch, con master, dbA, dbB, tenantA, tenantB y cred',
    array_diff(['master', 'dbA', 'dbB', 'tenantA', 'tenantB', 'cred', 'pdo'], array_keys($ctx)) === []
    && $s1Pdo instanceof PDO && $s1Pdo->query('SELECT DATABASE()')->fetchColumn() === $ctx['master']
    && $s1Ids[0] > 0 && $s1Ids[1] > 0 && $s1Ids[0] !== $s1Ids[1], array_keys($ctx));
wa_chk('precondicion: la master scratch tiene inventario_estricto (la 013), de donde cuelga la 014',
    isset($s1Posiciones()['inventario_estricto']));

// Sin las cuatro columnas: la 014 las crea.
$s1Soltar($s1Cols);
wa_chk('sin las cuatro columnas, tenants no tiene ninguna', $s1Cuantas() === 0);
$s1Resultado = correrSql($s1Pdo, $s1Sql);
wa_chk('014 sobre una master sin las cuatro columnas: el resultado dice columnas_ok = 4 en la master scratch',
    count($s1Resultado) === 1 && (int) ($s1Resultado[0]['columnas_ok'] ?? 0) === 4 && ($s1Resultado[0]['base'] ?? null) === $ctx['master'],
    ['filas' => count($s1Resultado), 'columnas_ok' => $s1Resultado[0]['columnas_ok'] ?? null]);
$s1Definiciones = [];
foreach ($s1Pdo->query(
    "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenants' AND COLUMN_NAME IN ('" . implode("','", $s1Cols) . "')"
)->fetchAll() as $s1F) {
    $s1Definiciones[$s1F['COLUMN_NAME']] = [$s1F['COLUMN_TYPE'], $s1F['IS_NULLABLE'], $s1F['COLUMN_DEFAULT']];
}
ksort($s1Definiciones);
wa_chk('014: edicion ENUM NOT NULL DEFAULT completa, modulos TEXT NULL, tipos VARCHAR(64) NULL y cert_vence DATE NULL, como queda en MySQL',
    $s1Definiciones === [
        'cert_vence' => ['date', 'YES', null],
        'edicion' => ["enum('completa','express')", 'NO', 'completa'],
        'modulos_habilitados' => ['text', 'YES', null],
        'tipos_ecf_habilitados' => ['varchar(64)', 'YES', null],
    ], $s1Definiciones);
$s1P = $s1Posiciones();
wa_chk('014: las cuatro quedan en orden detras de inventario_estricto y antes de ambiente',
    isset($s1P['inventario_estricto'], $s1P['edicion'], $s1P['modulos_habilitados'], $s1P['tipos_ecf_habilitados'], $s1P['cert_vence'], $s1P['ambiente'])
    && $s1P['inventario_estricto'] < $s1P['edicion'] && $s1P['edicion'] < $s1P['modulos_habilitados']
    && $s1P['modulos_habilitados'] < $s1P['tipos_ecf_habilitados'] && $s1P['tipos_ecf_habilitados'] < $s1P['cert_vence']
    && $s1P['cert_vence'] < $s1P['ambiente'], $s1P);
$s1Filas = $s1Pdo->query('SELECT edicion, modulos_habilitados, tipos_ecf_habilitados, cert_vence FROM tenants')->fetchAll();
wa_chk('014 no enciende a nadie: toda empresa que ya existia queda completa y con las otras tres columnas en NULL',
    count($s1Filas) >= 2 && $s1Filas === array_fill(0, count($s1Filas),
        ['edicion' => 'completa', 'modulos_habilitados' => null, 'tipos_ecf_habilitados' => null, 'cert_vence' => null]), $s1Filas);

// Aplicada otra vez: no cambia nada, ni las posiciones de las columnas ni los datos de las empresas.
$s1Datos = static fn (): array => $s1Pdo->query('SELECT id, edicion, modulos_habilitados, tipos_ecf_habilitados, cert_vence FROM tenants ORDER BY id')->fetchAll();
$s1Antes = [$s1Posiciones(), $s1Datos()];
$s1Resultado = correrSql($s1Pdo, $s1Sql);
wa_chk('014 otra vez: no cambia nada (mismas posiciones y mismos datos) y columnas_ok sigue en 4',
    [$s1Posiciones(), $s1Datos()] === $s1Antes && (int) ($s1Resultado[0]['columnas_ok'] ?? 0) === 4, ['columnas_ok' => $s1Resultado[0]['columnas_ok'] ?? null]);

// La guardia: sin base seleccionada y parada en information_schema corta ANTES de la primera sonda, y no cambia nada.
$s1Soltar($s1Cols);
$s1Guardias = [];
foreach ([null, 'information_schema'] as $s1Sistema) {
    $s1Otra = $s1SinBase($s1Sistema);
    $s1Error = errorDeSql($s1Otra, $s1Sql);
    $s1Guardias[$s1Sistema ?? '(sin base)'] = [
        'guardia' => $s1EsGuardia($s1Error),
        // @has_col lo fija la primera sonda: si sigue NULL, el lote se detuvo en la guardia y no llego a ninguna.
        'sin_sondas' => $s1Otra->query('SELECT @has_col')->fetchColumn() === null,
        'error' => $s1Error,
    ];
}
wa_chk('014 sin base de empresa seleccionada: falla en la guardia (no en un ALTER) antes de la primera sonda',
    $s1Guardias['(sin base)']['guardia'] && $s1Guardias['(sin base)']['sin_sondas'], $s1Guardias['(sin base)']);
wa_chk('014 parada en information_schema: falla en la guardia (no en un ALTER) antes de la primera sonda',
    $s1Guardias['information_schema']['guardia'] && $s1Guardias['information_schema']['sin_sondas'], $s1Guardias['information_schema']);
wa_chk('014 con la base equivocada no cambia nada: la master scratch sigue sin las cuatro columnas', $s1Cuantas() === 0);
unset($s1Otra);
correrSql($s1Pdo, $s1Sql);
wa_chk('014 en la base correcta, justo despues, vuelve a dejar las cuatro', $s1Cuantas() === 4);

// Un estado a medias: sin modulos_habilitados ni cert_vence. Completa las cuatro en orden y no toca lo que habia.
$s1Pdo->prepare("UPDATE tenants SET edicion = 'express', tipos_ecf_habilitados = '45' WHERE id = :id")->execute([':id' => $s1Ids[0]]);
$s1Soltar(['cert_vence', 'modulos_habilitados']);
wa_chk('estado a medias: solo quedan edicion y tipos_ecf_habilitados', $s1Cuantas() === 2);
$s1Resultado = correrSql($s1Pdo, $s1Sql);
$s1P = $s1Posiciones();
$s1A = $s1Fila($s1Ids[0]);
wa_chk('014 a medias: completa las cuatro en orden edicion < modulos_habilitados < tipos_ecf_habilitados < cert_vence y respeta lo que ya habia',
    (int) ($s1Resultado[0]['columnas_ok'] ?? 0) === 4
    && isset($s1P['edicion'], $s1P['modulos_habilitados'], $s1P['tipos_ecf_habilitados'], $s1P['cert_vence'])
    && $s1P['edicion'] < $s1P['modulos_habilitados'] && $s1P['modulos_habilitados'] < $s1P['tipos_ecf_habilitados'] && $s1P['tipos_ecf_habilitados'] < $s1P['cert_vence']
    && $s1A['edicion'] === 'express' && $s1A['tipos_ecf_habilitados'] === '45' && $s1A['modulos_habilitados'] === null && $s1A['cert_vence'] === null,
    ['posiciones' => $s1P, 'A' => array_intersect_key($s1A, array_flip($s1Cols))]);
$s1Pdo->prepare("UPDATE tenants SET edicion = 'completa', tipos_ecf_habilitados = NULL WHERE id = :id")->execute([':id' => $s1Ids[0]]);

// El ENUM rechaza una edicion desconocida cuando el servidor es estricto (como el de produccion). Conexion propia: el sql_mode no se queda en $s1Pdo.
$s1Estricto = wa_pdo(POS_SCRATCH_BASE_MASTER);
$s1Estricto->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES'");
$s1Estricto->beginTransaction(); // si el UPDATE se aceptara por error, no queda nada guardado
$s1Error = errorDeSql($s1Estricto, "UPDATE tenants SET edicion = 'basico'");
$s1Estricto->rollBack();
unset($s1Estricto);
wa_chk("con sql_mode STRICT_ALL_TABLES, UPDATE tenants SET edicion = 'basico' falla con 1265", ($s1Error[0] ?? 0) === 1265, $s1Error);
wa_chk('...y ninguna empresa quedo con una edicion distinta de completa',
    (int) $s1Pdo->query("SELECT COUNT(*) FROM tenants WHERE edicion <> 'completa'")->fetchColumn() === 0);

// El verificador de migraciones del master: su primera consulta (sin las lineas "--", cortada en el primer ";").
$s1Verificador = implode("\n", array_filter(
    preg_split('/\R/', (string) file_get_contents(__DIR__ . '/verificar_migraciones_master.sql')) ?: [],
    static fn (string $l): bool => !str_starts_with(ltrim($l), '--')
));
$s1Consulta = substr($s1Verificador, 0, (int) strpos($s1Verificador, ';'));
/** Lo que dice la fila 014 del verificador (APLICADA, FALTA...), o null si no hay fila. */
$s1Estado = static function () use ($s1Pdo, $s1Consulta): ?string {
    foreach ($s1Pdo->query($s1Consulta)->fetchAll() as $s1F) {
        if ((string) $s1F['migracion'] === '014') {
            return (string) $s1F['estado'];
        }
    }
    return null;
};
$s1Dice = [$s1Estado()];
$s1Soltar(['cert_vence']);
$s1Dice[] = $s1Estado();
correrSql($s1Pdo, $s1Sql);
$s1Dice[] = $s1Estado();
wa_chk('verificador del master: la 014 dice APLICADA con las cuatro, FALTA sin cert_vence y APLICADA al aplicarla de nuevo',
    $s1Dice === ['APLICADA', 'FALTA', 'APLICADA'], $s1Dice);

// express_scratch_preparar() deja a las dos empresas limpias aunque una corrida anterior las haya dejado sucias.
$s1Sucio = $s1Pdo->prepare(
    "UPDATE tenants SET edicion = 'express', modulos_habilitados = 'facturacion,clientes', tipos_ecf_habilitados = :tipos, cert_vence = '2027-01-31',
            cert_path = 'certificado_dgii/prueba/cert.p12', cert_pass_encrypted = :clave WHERE id = :id"
);
foreach ([[$s1Ids[0], '45'], [$s1Ids[1], '31,32']] as [$s1Id, $s1Tipos]) {
    $s1Sucio->bindValue(':tipos', $s1Tipos);
    $s1Sucio->bindValue(':clave', 'no-es-una-clave', PDO::PARAM_LOB);
    $s1Sucio->bindValue(':id', $s1Id, PDO::PARAM_INT);
    $s1Sucio->execute();
}
wa_chk('precondicion: las dos empresas de prueba quedaron sucias (express, tipos, certificado)',
    $s1Fila($s1Ids[0])['edicion'] === 'express' && $s1Fila($s1Ids[1])['tipos_ecf_habilitados'] === '31,32' && $s1Fila($s1Ids[1])['cert_path'] !== null);
$s1Otra = express_scratch_preparar();
$s1Limpia = ['edicion' => 'completa', 'modulos_habilitados' => null, 'tipos_ecf_habilitados' => null, 'cert_vence' => null, 'cert_path' => null, 'cert_pass_encrypted' => null];
$s1Esperado = [];
foreach ($s1Ids as $s1Id) {
    $s1Esperado[$s1Id] = array_intersect_key($s1Fila($s1Id), $s1Limpia);
}
wa_chk('express_scratch_preparar() otra vez: A y B quedan edicion = completa, las otras tres de la 014 en NULL y sin cert_path ni cert_pass_encrypted',
    $s1Esperado === [$s1Ids[0] => $s1Limpia, $s1Ids[1] => $s1Limpia]
    && $s1Otra['tenantA'] === $ctx['tenantA'] && $s1Otra['tenantB'] === $ctx['tenantB'], $s1Esperado);
unset($s1Otra);

// La restauracion de al terminar el proceso: un hijo ensucia todo y sale sin limpiar. Aqui se ve lo que dejo.
$s1Hijo = wa_correr(array_merge([PHP_BINARY], wa_php_flags(), [__FILE__, '--hijo=restauracion']));
$s1Defaults = (require dirname(__DIR__) . '/config/permissions.php')['defaults'];
$s1Permisos = $s1Pdo->prepare("SELECT COUNT(*) FROM role_permissions rp JOIN roles r ON r.id = rp.role_id WHERE r.tenant_id = :t AND r.name = 'user'");
$s1Permisos->execute([':t' => $s1Ids[0]]);
$s1Rol = $s1Pdo->query("SELECT role FROM users WHERE username = 'admin_pos_a'")->fetchColumn();
$s1Despues = ['hijo' => ['codigo' => $s1Hijo['codigo'], 'bytes_impresos' => strlen($s1Hijo['salida'])]];
$s1LimpiaConPos = $s1Limpia + ['pos_enabled' => '1'];
foreach ($s1Ids as $s1Id) {
    $s1Despues[$s1Id] = array_intersect_key($s1Fila($s1Id), $s1LimpiaConPos);
}
wa_chk('al terminar un proceso se restaura todo: las dos empresas completas (columnas de la 014) y con el POS, admin_pos_a admin y los permisos del rol user',
    $s1Hijo['codigo'] === 0 && $s1Hijo['salida'] === ''
    && $s1Despues[$s1Ids[0]] === $s1LimpiaConPos && $s1Despues[$s1Ids[1]] === $s1LimpiaConPos
    && $s1Rol === 'admin' && (int) $s1Permisos->fetchColumn() === count($s1Defaults['user']), [$s1Despues, $s1Rol]);

// ---------------------------------------------------------------------------
// 2) user.entitlements en el login y en /me
// ---------------------------------------------------------------------------
echo "\n== 2) user.entitlements en el login y en /me ==\n";
$s2Pdo = $ctx['pdo'];
$s2IdA = (int) $ctx['tenantA'];
$s2IdB = (int) $ctx['tenantB'];
/** Las columnas de edicion de una empresa de prueba, cada valor en texto o NULL. */
$s2Fila = static function (int $id) use ($s2Pdo): array {
    $st = $s2Pdo->prepare('SELECT edicion, tipos_ecf_habilitados, modulos_habilitados, cert_vence FROM tenants WHERE id = :id');
    $st->execute([':id' => $id]);
    return array_map(static fn ($valor): ?string => $valor === null ? null : (string) $valor, (array) $st->fetch());
};

// A es la empresa Express (solo E45); B, la de control, sigue completa.
express_edicion($ctx, 'A', 'express', '45', null);
wa_chk('express_edicion(A, express, 45, null): A queda express con tipos 45 y modulos y cert_vence en NULL, y B sigue completa',
    $s2Fila($s2IdA) === ['edicion' => 'express', 'tipos_ecf_habilitados' => '45', 'modulos_habilitados' => null, 'cert_vence' => null]
    && $s2Fila($s2IdB)['edicion'] === 'completa', [$s2Fila($s2IdA), $s2Fila($s2IdB)]);
$s2Api = express_api('express_api', 8294);

// Login del administrador de A.
$s2LoginA = express_login($s2Api, 'admin_pos_a');
$s2UserA = $s2LoginA['user'];
$s2Bloque = $s2UserA['entitlements'] ?? null;
/** El "*" del administrador cruzado con los cinco modulos del preset: un token por modulo (la configuracion es ncf) y el compartido unidades. */
$s2PermisosAdminA = ['facturas', 'clients', 'products', 'users', 'ncf', 'unidades'];
wa_chk('login admin_pos_a (A, Express): el user trae entitlements, despues de permissions y como ultima clave',
    json_encode(array_keys($s2UserA)) === '["id","email","username","name","role","permissions","entitlements"]', array_keys($s2UserA));
wa_chk('login admin_pos_a: entitlements trae exactamente edicion, tenant, modulos, tipos_ecf y capacidades',
    is_array($s2Bloque) && array_keys($s2Bloque) === ['edicion', 'tenant', 'modulos', 'tipos_ecf', 'capacidades'], is_array($s2Bloque) ? array_keys($s2Bloque) : $s2Bloque);
wa_chk('login admin_pos_a: entitlements.tenant trae exactamente id, nombre, rnc y ambiente, los de A',
    is_array($s2Bloque['tenant'] ?? null) && array_keys($s2Bloque['tenant']) === ['id', 'nombre', 'rnc', 'ambiente']
    && $s2Bloque['tenant'] === ['id' => $s2IdA, 'nombre' => POS_SCRATCH_TENANTS['A']['nombre'], 'rnc' => POS_SCRATCH_TENANTS['A']['rnc'], 'ambiente' => 'testecf'],
    $s2Bloque['tenant'] ?? null);
wa_chk('login admin_pos_a: edicion express, los cinco modulos del preset, tipos_ecf ["45","34"] (la venta y la nota de credito derivada) y las capacidades de hoy',
    ($s2Bloque['edicion'] ?? null) === 'express'
    && ($s2Bloque['modulos'] ?? null) === ['facturacion', 'clientes', 'productos', 'usuarios', 'configuracion']
    && ($s2Bloque['tipos_ecf'] ?? null) === ['45', '34']
    && ($s2Bloque['capacidades'] ?? null) === ['idempotencia' => false, 'reconciliador' => false, 'certificado' => true], $s2Bloque);
wa_chk('login admin_pos_a: permissions es el "*" del administrador cruzado con los modulos de la edicion (facturas, clients, products, users, ncf, unidades)',
    ($s2UserA['permissions'] ?? null) === $s2PermisosAdminA, $s2UserA['permissions'] ?? null);

// GET /api/auth/me del mismo administrador: el mismo bloque y los mismos permisos.
$s2Me = wa_http('GET', $s2Api . '/api/auth/me', null, express_h($s2LoginA['token']));
$s2MeUser = $s2Me['json']['data']['user'] ?? null;
wa_chk('GET /api/auth/me (A, Express): 200 con success y el user con las mismas claves que el login, bloque incluido',
    $s2Me['status'] === 200 && ($s2Me['json']['success'] ?? null) === true && is_array($s2MeUser)
    && json_encode(array_keys($s2MeUser)) === '["id","email","username","name","role","permissions","entitlements"]', [$s2Me['status'], $s2Me['body']]);
wa_chk('GET /api/auth/me (A, Express): entitlements (un bloque de verdad, no null) y permissions son los del login',
    is_array($s2MeUser) && is_array($s2Bloque) && ($s2MeUser['entitlements'] ?? null) === $s2Bloque
    && ($s2MeUser['permissions'] ?? null) === $s2PermisosAdminA && $s2PermisosAdminA === ($s2UserA['permissions'] ?? null), $s2MeUser);

// El Facturador (rol user) de A: el bloque es de la empresa, no del rol; los permisos son los de SU rol cruzados con la edicion.
$s2LoginU = express_login($s2Api, 'user_pos_a');
$s2Rol = $s2Pdo->prepare("SELECT rp.permission FROM role_permissions rp JOIN roles r ON r.id = rp.role_id WHERE r.tenant_id = :t AND r.name = 'user'");
$s2Rol->execute([':t' => $s2IdA]);
$s2PermisosRol = array_column($s2Rol->fetchAll(), 'permission');
// Lo esperado sale de la tabla de permisos de la base, no de la funcion que se prueba: los tokens de la edicion, en su orden, que el rol tiene.
$s2EsperadoU = array_values(array_intersect(['facturas', 'clients', 'products', 'users', 'ncf', 'unidades'], $s2PermisosRol));
wa_chk('precondicion: el rol user de A tiene permisos y no tiene el modulo users (el cruce con la edicion tiene algo que mostrar)',
    $s2EsperadoU !== [] && !in_array('users', $s2PermisosRol, true) && !in_array('*', $s2PermisosRol, true), $s2PermisosRol);
wa_chk('login user_pos_a (el Facturador de A): el mismo bloque que el administrador y permissions = los tokens de la edicion que su rol tiene, en el orden de los tokens y sin users',
    ($s2LoginU['user']['entitlements'] ?? null) === $s2Bloque && ($s2LoginU['user']['permissions'] ?? null) === $s2EsperadoU
    && !in_array('users', (array) ($s2LoginU['user']['permissions'] ?? []), true), $s2LoginU['user']);

// B (completa): el login y /me de siempre.
$s2LoginB = express_login($s2Api, 'admin_pos_b');
$s2MeB = wa_http('GET', $s2Api . '/api/auth/me', null, express_h($s2LoginB['token']));
$s2MeUserB = $s2MeB['json']['data']['user'] ?? null;
wa_chk('login admin_pos_b (B, completa): data.user trae exactamente id, email, username, name, role y permissions, sin entitlements, y permissions es el "*" del rol',
    array_keys($s2LoginB['user']) === ['id', 'email', 'username', 'name', 'role', 'permissions'] && !array_key_exists('entitlements', $s2LoginB['user'])
    && $s2LoginB['user']['permissions'] === ['*'], $s2LoginB['user']);
wa_chk('GET /api/auth/me (B, completa): 200 con las mismas seis claves, sin entitlements, y permissions "*"',
    $s2MeB['status'] === 200 && is_array($s2MeUserB) && array_keys($s2MeUserB) === ['id', 'email', 'username', 'name', 'role', 'permissions']
    && !array_key_exists('entitlements', $s2MeUserB) && $s2MeUserB['permissions'] === ['*'], [$s2MeB['status'], $s2MeB['body']]);

// express_usar_master: MasterDatabase y TenantResolver de este proceso leen la master scratch.
express_usar_master($ctx);
$s2Maestro = MasterDatabase::getInstance();
$s2FilaA = $s2Maestro->getTenantById($s2IdA);
wa_chk('express_usar_master: MasterDatabase lee la master scratch (la empresa A, express) y TenantResolver descifra la clave de su base con la clave maestra de prueba',
    $s2Maestro->getConnection()->query('SELECT DATABASE()')->fetchColumn() === $ctx['master']
    && is_array($s2FilaA) && $s2FilaA['edicion'] === 'express' && (int) $s2FilaA['id'] === $s2IdA
    && TenantResolver::decrypt((string) $s2FilaA['db_pass_encrypted']) === $ctx['cred']['pass'], is_array($s2FilaA) ? $s2FilaA['edicion'] : $s2FilaA);

// ---------------------------------------------------------------------------
// 3) El bloque certificado de GET /api/emisor
// ---------------------------------------------------------------------------
echo "\n== 3) El bloque certificado de GET /api/emisor ==\n";
$s3Pdo = $ctx['pdo'];
$s3IdA = (int) $ctx['tenantA'];
$s3IdB = (int) $ctx['tenantB'];
// El .p12 desechable de este proceso (en el temp del sistema; la API de la suite ya lo trae en su entorno): es lo que va en cert_path.
$s3Ruta = wa_cert_prueba()['path'];
/** Edicion, certificado y fecha de una empresa de prueba tal como estan en su fila, cada valor en texto o NULL. */
$s3Fila = static function (int $id) use ($s3Pdo): array {
    $st = $s3Pdo->prepare('SELECT edicion, cert_path, cert_vence FROM tenants WHERE id = :id');
    $st->execute([':id' => $id]);
    return array_map(static fn ($valor): ?string => $valor === null ? null : (string) $valor, (array) $st->fetch());
};
/** Deja el certificado de las dos empresas de prueba como se pide (la ruta y la fecha, o NULL): el resto de la fila queda como esta. */
$s3Poner = static function (?string $ruta, ?string $vence) use ($s3Pdo, $s3IdA, $s3IdB): void {
    $s3Pdo->prepare('UPDATE tenants SET cert_path = :p, cert_vence = :c WHERE id IN (:a, :b)')
        ->execute([':p' => $ruta, ':c' => $vence, ':a' => $s3IdA, ':b' => $s3IdB]);
};

// A es la empresa Express y B, la de control, sigue completa; las dos con certificado y a 45 dias de vencer. El dia se calcula en la
// zona por defecto de este proceso, y la API de la suite corre en esa misma zona porque express_api() se la fija con -d date.timezone=
// (no se supone: un -d date.timezone= al correr la suite no llega al hijo por si solo, y el dia del servidor saldria uno de mas o de menos).
$s3Vence = date('Y-m-d', strtotime('+45 days'));
express_edicion($ctx, 'A', 'express', '45', null, $s3Vence);
express_edicion($ctx, 'B', null, null, null, $s3Vence);
$s3Poner($s3Ruta, $s3Vence);
wa_chk('precondicion: A es Express y B completa, las dos con cert_path (el .p12 desechable) y cert_vence a 45 dias',
    $s3Fila($s3IdA) === ['edicion' => 'express', 'cert_path' => $s3Ruta, 'cert_vence' => $s3Vence]
    && $s3Fila($s3IdB) === ['edicion' => 'completa', 'cert_path' => $s3Ruta, 'cert_vence' => $s3Vence], [$s3Fila($s3IdA), $s3Fila($s3IdB)]);
$s3Api = express_api('express_api', 8294);
$s3TokenA = express_login($s3Api, 'admin_pos_a')['token'];
/** GET /api/emisor con la sesion de $token. */
$s3Pedir = static fn (string $token): array => wa_http('GET', $s3Api . '/api/emisor', null, express_h($token));

// La empresa Express recibe el bloque, a 45 dias de vencer.
$s3A = $s3Pedir($s3TokenA);
$s3DataA = $s3A['json']['data'] ?? null;
wa_chk('GET /api/emisor (A, Express, a 45 dias): 200 y data.certificado es por_vencer, con la fecha de la fila y 45 dias',
    $s3A['status'] === 200 && ($s3A['json']['status'] ?? null) === true
    && ($s3DataA['certificado'] ?? null) === ['estado' => 'por_vencer', 'vence' => $s3Vence, 'dias' => 45], [$s3A['status'], $s3A['body']]);
wa_chk('GET /api/emisor (A, Express): certificado va al final, despues de fuente, y trae exactamente estado, vence y dias',
    is_array($s3DataA) && array_slice(array_keys($s3DataA), -2) === ['fuente', 'certificado']
    && is_array($s3DataA['certificado'] ?? null) && array_keys($s3DataA['certificado']) === ['estado', 'vence', 'dias'], is_array($s3DataA) ? array_keys($s3DataA) : $s3DataA);
// Ni la ruta del certificado (escrita como la escribe json_encode, con o sin barras escapadas, ni su nombre de archivo) ni una clave cert_*.
$s3Prohibido = array_values(array_unique([
    $s3Ruta,
    trim((string) json_encode($s3Ruta), '"'),
    trim((string) json_encode($s3Ruta, JSON_UNESCAPED_SLASHES), '"'),
    basename($s3Ruta),
    'cert_',
]));
$s3Filtrado = array_values(array_filter($s3Prohibido, static fn (string $texto): bool => str_contains($s3A['body'], $texto)));
wa_chk('GET /api/emisor (A, Express): el texto de la respuesta no contiene la ruta del certificado (ni escapada ni su nombre de archivo) ni "cert_"',
    $s3A['status'] === 200 && $s3Filtrado === [], $s3Filtrado);

// La empresa completa no recibe nada nuevo, aunque su fila traiga la ruta y la fecha: B, y A misma cuando se la deja completa (la
// respuesta es entonces la de Express menos el bloque, dato por dato).
$s3B = $s3Pedir(express_login($s3Api, 'admin_pos_b')['token']);
$s3DataB = $s3B['json']['data'] ?? null;
wa_chk('GET /api/emisor (B, completa, con cert_path y cert_vence en su fila): 200 y data sin la clave certificado',
    $s3B['status'] === 200 && is_array($s3DataB) && ($s3DataB['fuente'] ?? null) === 'emisor_config' && !array_key_exists('certificado', $s3DataB),
    [$s3B['status'], $s3B['body']]);
express_edicion($ctx, 'A', null, null, null, $s3Vence);
$s3AComoCompleta = $s3Pedir($s3TokenA);
$s3SinBloque = is_array($s3DataA) ? array_diff_key($s3DataA, ['certificado' => true]) : null;
wa_chk('GET /api/emisor (A dejada completa, con el mismo certificado en su fila): data sin certificado y igual, dato por dato, a la de Express sin ese bloque',
    $s3AComoCompleta['status'] === 200 && $s3SinBloque !== null && ($s3AComoCompleta['json']['data'] ?? null) === $s3SinBloque,
    [$s3AComoCompleta['status'], $s3AComoCompleta['body']]);
express_edicion($ctx, 'A', 'express', '45', null, $s3Vence);   // de vuelta a Express para lo que sigue

// RF4: el bloque sale de la fila viva. Renovar el certificado (cert_vence nuevo en la fila) se ve en la peticion siguiente, y una fecha
// que quedo vieja informa pero no bloquea nada: la respuesta sigue siendo 200 con los datos del emisor de siempre.
$s3Renovado = date('Y-m-d', strtotime('+100 days'));
$s3Poner($s3Ruta, $s3Renovado);
$s3R = $s3Pedir($s3TokenA);
wa_chk('cert_vence cambia en la fila entre dos peticiones: la segunda ya lo refleja (vigente, la fecha nueva y 100 dias)',
    $s3R['status'] === 200 && ($s3R['json']['data']['certificado'] ?? null) === ['estado' => 'vigente', 'vence' => $s3Renovado, 'dias' => 100],
    [$s3R['status'], $s3R['body']]);
$s3Viejo = date('Y-m-d', strtotime('-3 days'));
$s3Poner($s3Ruta, $s3Viejo);
$s3R = $s3Pedir($s3TokenA);
wa_chk('una fecha ya vencida no bloquea nada: 200, certificado vencido a -3 dias y el resto de los datos del emisor intacto',
    $s3R['status'] === 200 && ($s3R['json']['data']['certificado'] ?? null) === ['estado' => 'vencido', 'vence' => $s3Viejo, 'dias' => -3]
    && is_array($s3R['json']['data'] ?? null) && $s3SinBloque !== null && array_diff_key($s3R['json']['data'], ['certificado' => true]) === $s3SinBloque,
    [$s3R['status'], $s3R['body']]);
// Los dos estados sin nada que contar: con ruta y sin fecha, y sin ruta (aunque la fila traiga fecha).
$s3Poner($s3Ruta, null);
$s3R = $s3Pedir($s3TokenA);
wa_chk('cert_vence NULL en la fila (con cert_path): certificado sin_dato, con vence y dias en null',
    $s3R['status'] === 200 && ($s3R['json']['data']['certificado'] ?? null) === ['estado' => 'sin_dato', 'vence' => null, 'dias' => null],
    [$s3R['status'], $s3R['body']]);
$s3Poner(null, $s3Vence);
$s3R = $s3Pedir($s3TokenA);
wa_chk('sin cert_path en la fila (con cert_vence): certificado no_configurado, con vence y dias en null',
    $s3R['status'] === 200 && ($s3R['json']['data']['certificado'] ?? null) === ['estado' => 'no_configurado', 'vence' => null, 'dias' => null],
    [$s3R['status'], $s3R['body']]);
$s3Poner(null, null);   // la ruta de prueba no pasa a las secciones que siguen

// ---------------------------------------------------------------------------
// 4) La biblioteca de preflight (tools/express_edicion_lib.php) sobre la empresa A
// ---------------------------------------------------------------------------
echo "\n== 4) La biblioteca de preflight sobre la empresa A ==\n";
require_once __DIR__ . '/express_edicion_lib.php';
$s4Master = $ctx['pdo'];
$s4IdA = (int) $ctx['tenantA'];
// La clave maestra de prueba: express_conectar_tenant descifra con ella la clave de la base de A (como la API), y encrypt() cifra con ella.
express_usar_master($ctx);
// A como empresa completa y sin certificado (la seccion 3 la dejo Express): lo que el preflight convierte es una empresa completa.
express_edicion($ctx, 'A', null, null, null);
/** La fila de A en master.tenants, completa (SELECT *, como la lee MasterDatabase). */
$s4FilaA = static function () use ($s4Master, $s4IdA): array {
    $st = $s4Master->prepare('SELECT * FROM tenants WHERE id = :id');
    $st->execute([':id' => $s4IdA]);
    return (array) $st->fetch();
};
$s4Fila = $s4FilaA();

// --- express_conectar_tenant -----------------------------------------------------------------------------------------------------
$s4Pdo = express_conectar_tenant($s4Fila);
// Lo que escribe esta seccion va a la base de A: si no es de prueba (sin la marca), se corta antes de tocar nada.
wa_exigir_marca($s4Pdo);
wa_chk('express_conectar_tenant(A): un PDO sobre la base de A (wa_scratch, con la marca de las bases de prueba), con excepciones y FETCH_ASSOC',
    $s4Pdo instanceof PDO && $s4Pdo->query('SELECT DATABASE()')->fetchColumn() === $ctx['dbA']
    && $s4Pdo->getAttribute(PDO::ATTR_ERRMODE) === PDO::ERRMODE_EXCEPTION && $s4Pdo->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE) === PDO::FETCH_ASSOC);
// Sin servidor (el puerto 1 de loopback no tiene a nadie) y con una clave que no se descifra: el error dice la empresa y nada mas.
$s4Mala = ['id' => $s4IdA, 'tipo' => 'app', 'db_host' => '127.0.0.1', 'db_port' => '1', 'db_name' => 'zz_marca_base', 'db_user' => 'zz_marca_usuario',
    'db_pass_encrypted' => TenantResolver::encrypt('zz-marca-clave')];
ob_start();
try {
    express_conectar_tenant($s4Mala);
    $s4Error = null;
} catch (Throwable $s4Error) {
}
$s4Salida = (string) ob_get_clean();
wa_chk('express_conectar_tenant: sin servidor lanza RuntimeException con el id de la empresa, sin imprimir y sin nombrar el servidor, el usuario, la base ni la clave',
    $s4Error instanceof RuntimeException && str_contains($s4Error->getMessage(), "#{$s4IdA}") && $s4Salida === ''
    && !preg_match('/zz_marca|zz-marca|127\.0\.0\.1/', $s4Error->getMessage() . $s4Salida), [$s4Error === null ? null : get_class($s4Error) . ': ' . $s4Error->getMessage(), $s4Salida]);
try {
    express_conectar_tenant(['db_pass_encrypted' => 'zz-marca-basura-que-no-se-descifra'] + $s4Mala);
    $s4Error = null;
} catch (Throwable $s4Error) {
}
wa_chk('express_conectar_tenant: una clave de base que no se descifra lanza RuntimeException (no abre nada) y el texto no lleva la clave',
    $s4Error instanceof RuntimeException && !str_contains($s4Error->getMessage(), 'zz-marca'), $s4Error === null ? null : get_class($s4Error) . ': ' . $s4Error->getMessage());

// --- express_leer_certificado ---------------------------------------------------------------------------------------------------
$s4Cert = wa_cert_prueba();   // un .p12 desechable en el temp del sistema, valido dos dias, con una clave al azar
/** La fila de una empresa con el certificado de prueba y su clave cifrada por TenantResolver::encrypt; $o pisa lo que cada caso cambia. */
$s4FilaCert = static fn (array $o = []): array => $o + ['cert_path' => $s4Cert['path'], 'cert_pass_encrypted' => TenantResolver::encrypt($s4Cert['password'])];
$s4Bien = express_leer_certificado($s4FilaCert());
wa_chk('express_leer_certificado: el .p12 de wa_cert_prueba() con su clave cifrada da ok, sin codigo, y vence dentro de tres dias (el certificado dura dos)',
    array_keys($s4Bien) === ['ok', 'codigo', 'vence'] && $s4Bien['ok'] === true && $s4Bien['codigo'] === null
    && is_int($s4Bien['vence']) && $s4Bien['vence'] > time() && $s4Bien['vence'] < time() + 3 * 86400, $s4Bien);
wa_chk('express_leer_certificado: el resultado no lleva la ruta del archivo ni la clave del certificado',
    !str_contains((string) json_encode($s4Bien), basename($s4Cert['path'])) && !str_contains((string) json_encode($s4Bien), $s4Cert['password']), $s4Bien);
$s4Falta = ['ok' => false, 'codigo' => 'CERT_FALTA', 'vence' => null];
wa_chk('express_leer_certificado: con otra clave (el .p12 no se abre) da CERT_FALTA',
    express_leer_certificado($s4FilaCert(['cert_pass_encrypted' => TenantResolver::encrypt('otra-clave-que-no-es')])) === $s4Falta);
wa_chk('express_leer_certificado: con un archivo que no existe da CERT_FALTA',
    express_leer_certificado($s4FilaCert(['cert_path' => $s4Cert['path'] . '.no-existe'])) === $s4Falta);
wa_chk('express_leer_certificado: con cert_pass_encrypted vacio (o NULL) da CERT_FALTA',
    express_leer_certificado($s4FilaCert(['cert_pass_encrypted' => ''])) === $s4Falta && express_leer_certificado($s4FilaCert(['cert_pass_encrypted' => null])) === $s4Falta);
wa_chk('express_leer_certificado: el mismo certificado bueno con $ahora diez dias despues da CERT_VENCIDO, con ok false y su notAfter en vence',
    express_leer_certificado($s4FilaCert(), time() + 10 * 86400) === ['ok' => false, 'codigo' => 'CERT_VENCIDO', 'vence' => $s4Bien['vence']]);
// El limite sigue a la emision: ECFEmissionService::exigirCertificadoVigente vence un certificado solo con notAfter < time(), asi que a la marca exacta del
// notAfter todavia vale (RFC 5280: la vigencia va de notBefore a notAfter, ambos incluidos) y un segundo despues ya no.
wa_chk('express_leer_certificado: el certificado vale hasta su notAfter inclusive (a esa marca exacta y un segundo antes, ok) y un segundo despues es CERT_VENCIDO con su notAfter en vence',
    express_leer_certificado($s4FilaCert(), $s4Bien['vence'])['ok'] === true && express_leer_certificado($s4FilaCert(), $s4Bien['vence'] - 1)['ok'] === true
    && express_leer_certificado($s4FilaCert(), $s4Bien['vence'] + 1) === ['ok' => false, 'codigo' => 'CERT_VENCIDO', 'vence' => $s4Bien['vence']]);
// Sin cert_path la empresa no firma con el certificado global del .env: la biblioteca tampoco lo cuenta (CertResolver si caeria en el).
$s4EnvAntes = getenv('DGII_ECF_CERT_PATH');
putenv('DGII_ECF_CERT_PATH=' . $s4Cert['path']);
try {
    $s4SinFallback = express_leer_certificado($s4FilaCert(['cert_path' => '']));
} finally {
    putenv($s4EnvAntes === false ? 'DGII_ECF_CERT_PATH' : 'DGII_ECF_CERT_PATH=' . $s4EnvAntes);
}
wa_chk('express_leer_certificado: con cert_path vacio da CERT_FALTA aunque DGII_ECF_CERT_PATH apunte a un certificado bueno (no hay respaldo con el del .env)', $s4SinFallback === $s4Falta, $s4SinFallback);

// --- el mismo juicio que la emision: ECFEmissionService::exigirCertificadoVigente (privada) y express_leer_certificado vencen el mismo certificado ---
// El preflight y la emision no pueden diferir en lo que las dos juzgan. La emision solo juzga el vencimiento (un .p12 que no abre no lo juzga: lo dice el
// firmador) y no recibe la hora (usa time()), asi que se comparan el certificado bueno y el vencido. El limite exacto (notAfter == ahora) no se puede
// forzar con la hora de la emision: lo fija la prueba de arriba con $ahora y la suite pura, que lee la comparacion de la emision. PHP no firma con dias
// negativos (openssl_csr_sign pide de 0 a 24855): un certificado de 0 dias vence en el segundo en que se crea, y se espera a que ese segundo pase.
require_once __DIR__ . '/../src/Utils/FacturacionElectronica/ECFEmissionService.php';
$s4Emision = new ReflectionMethod('ECFEmissionService', 'exigirCertificadoVigente');
/** Lo que la emision dice de un .p12: 'vigente' si no lanza, o la clase de lo que lanza (EcfUsuarioException cuando esta vencido). */
$s4EmisionDice = static function (array $p12) use ($s4Emision): string {
    try {
        $s4Emision->invoke(null, (string) file_get_contents($p12['path']), $p12['password']);
        return 'vigente';
    } catch (Throwable $e) {
        return get_class($e);
    }
};
/** Un .p12 desechable de $dias dias (0 a 24855) en el temp del sistema, con clave al azar que no se imprime; se borra al terminar el proceso. */
$s4P12Dias = static function (int $dias): array {
    $cfg = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha256'];
    $cnf = getenv('OPENSSL_CONF') ?: '';
    $cnfPhp = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf';
    if ($cnf === '' && is_file($cnfPhp)) {
        $cnf = $cnfPhp;
    }
    if ($cnf !== '') {
        $cfg['config'] = $cnf;
    }
    $clave = bin2hex(random_bytes(12));
    $llave = openssl_pkey_new($cfg);
    $csr = $llave ? openssl_csr_new(['commonName' => 'EXPRESS PRUEBA VENCIDO', 'organizationName' => 'EXPRESS PRUEBA', 'countryName' => 'DO'], $llave, $cfg) : false;
    $x509 = ($csr && $llave) ? openssl_csr_sign($csr, null, $llave, $dias, $cfg) : false;
    $p12 = '';
    if (!$x509 || !openssl_pkcs12_export($x509, $p12, $llave, $clave)) {
        echo "[FALLO] no se pudo crear el certificado de prueba de {$dias} dias\n";
        exit(1);
    }
    $ruta = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'express_cert_' . $dias . 'd_' . getmypid() . '.p12';
    file_put_contents($ruta, $p12);
    register_shutdown_function(static function () use ($ruta): void {
        @unlink($ruta);
    });
    return ['path' => $ruta, 'password' => $clave];
};
$s4Corto = $s4P12Dias(0);
$s4FilaCorto = $s4FilaCert(['cert_path' => $s4Corto['path'], 'cert_pass_encrypted' => TenantResolver::encrypt($s4Corto['password'])]);
$s4NotAfterCorto = express_leer_certificado($s4FilaCorto, 0)['vence'];   // con $ahora en 0 todo certificado que abre vale: devuelve su notAfter
for ($s4I = 0; $s4I < 30 && is_int($s4NotAfterCorto) && time() <= $s4NotAfterCorto; $s4I++) {
    usleep(100000);   // a lo mas un segundo: el notAfter es el segundo en que se creo
}
$s4Vencido = express_leer_certificado($s4FilaCorto);
wa_chk('express_leer_certificado y la emision vencen el mismo certificado: uno de 0 dias, pasado su segundo, es CERT_VENCIDO con su notAfter en vence y exigirCertificadoVigente lanza EcfUsuarioException',
    is_int($s4NotAfterCorto) && $s4Vencido === ['ok' => false, 'codigo' => 'CERT_VENCIDO', 'vence' => $s4NotAfterCorto] && $s4EmisionDice($s4Corto) === 'EcfUsuarioException',
    [$s4Vencido, $s4EmisionDice($s4Corto)]);
wa_chk('express_leer_certificado y la emision dan por bueno el mismo certificado (el de wa_cert_prueba): ok, y exigirCertificadoVigente no lanza',
    $s4Bien['ok'] === true && $s4EmisionDice($s4Cert) === 'vigente', [$s4Bien, $s4EmisionDice($s4Cert)]);

// --- express_preflight_hechos sobre A: cada hecho se compara con una lectura independiente de la base --------------------------------
$s4Pedido = ['edicion' => 'express', 'tipos' => ['45'], 'modulos' => null];
/** Un escalar de una consulta sobre la base de A (lectura independiente: no pasa por la biblioteca). */
$s4Uno = static function (string $sql, array $p = []) use ($s4Pdo): mixed {
    $st = $s4Pdo->prepare($sql);
    $st->execute($p);
    return $st->fetchColumn();
};
/** ¿Tiene la tabla de A esa columna? (information_schema, sin pasar por la biblioteca). */
$s4Columna = static fn (string $tabla, string $columna): bool => (int) $s4Uno(
    'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c', [':t' => $tabla, ':c' => $columna]
) === 1;
$s4Hechos = express_preflight_hechos($s4Master, $s4Pdo, $s4Fila, $s4Pedido);
$s4RolIndependiente = $s4Master->prepare("SELECT rp.permission FROM role_permissions rp JOIN roles r ON r.id = rp.role_id WHERE r.tenant_id = :t AND r.name = 'user'");
$s4RolIndependiente->execute([':t' => $s4IdA]);
$s4RolA = array_column($s4RolIndependiente->fetchAll(), 'permission');
$s4Ordenada = static function (?array $lista): ?array {
    if ($lista !== null) {
        sort($lista);
    }
    return $lista;
};
wa_chk('express_preflight_hechos(A): las claves del contrato en su orden; tenant y pedido son los recibidos; hoy es la fecha de hoy',
    array_keys($s4Hechos) === ['tenant', 'columnas_014', 'm036', 'cert', 'emisor', 'almacen', 'pedido', 'rangos_ecf', 'ecf_facturas', 'clientes_prueba', 'rol_user', 'hoy']
    && $s4Hechos['tenant'] === $s4Fila && $s4Hechos['pedido'] === $s4Pedido && in_array($s4Hechos['hoy'], [date('Y-m-d'), date('Y-m-d', time() - 5)], true), array_keys($s4Hechos));
wa_chk('express_preflight_hechos(A): columnas_014 es true (la master scratch trae las cuatro columnas de la 014)',
    $s4Hechos['columnas_014'] === true && (int) $s4Master->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenants'
         AND COLUMN_NAME IN ('edicion', 'modulos_habilitados', 'tipos_ecf_habilitados', 'cert_vence')"
    )->fetchColumn() === 4, $s4Hechos['columnas_014']);
wa_chk('express_preflight_hechos(A): m036 es lo que dice information_schema de facturas.idempotency_key (el plan 04 la agrega a la base scratch mas adelante: no se supone que falte)',
    $s4Hechos['m036'] === $s4Columna('facturas', 'idempotency_key'), [$s4Hechos['m036'], $s4Columna('facturas', 'idempotency_key')]);
wa_chk('express_preflight_hechos(A): rol_user son los permisos del rol user de A en la master (sin importar el orden)',
    is_array($s4Hechos['rol_user']) && $s4Hechos['rol_user'] !== [] && $s4Ordenada($s4Hechos['rol_user']) === $s4Ordenada($s4RolA), [$s4Hechos['rol_user'], $s4RolA]);
$s4SinRol = express_preflight_hechos($s4Master, $s4Pdo, ['id' => 2147483000] + $s4Fila, $s4Pedido);
wa_chk('express_preflight_hechos: una empresa sin rol user en la master (id que no existe) da rol_user null',
    $s4SinRol['rol_user'] === null, $s4SinRol['rol_user']);
$s4EmisorA = $s4Pdo->query('SELECT rnc, razon_social, direccion, telefono FROM emisor_config WHERE id = 1')->fetch();
wa_chk('express_preflight_hechos(A): emisor es la fila 1 de emisor_config (texto), almacen es "Almacen Principal" y el certificado de A (sin cert_path) es CERT_FALTA',
    $s4Hechos['emisor'] === array_map(static fn ($v): string => (string) $v, $s4EmisorA)
    && $s4Hechos['almacen'] === ((int) $s4Uno('SELECT COUNT(*) FROM warehouses WHERE nombre = :n', [':n' => 'Almacén Principal']) > 0)
    && $s4Hechos['cert'] === $s4Falta, [$s4Hechos['emisor'], $s4Hechos['almacen'], $s4Hechos['cert']]);
$s4RangosEcfA = $s4Pdo->query("SELECT type, numero_hasta, current_value, fecha_vencimiento FROM ncf_sequences WHERE ambiente = 'ecf' ORDER BY type, numero_desde")->fetchAll();
$s4EnteroONull = static fn ($v): ?int => $v === null ? null : (int) $v;
wa_chk('express_preflight_hechos(A): rangos_ecf son las filas ecf de ncf_sequences (solo ecf, nunca certecf ni testecf), ecf_facturas cuenta las facturas ecf y clientes_prueba los dos clientes de prueba de la DGII',
    $s4Hechos['rangos_ecf'] === array_map(static fn (array $r): array => [
        'type' => (string) $r['type'], 'numero_hasta' => $s4EnteroONull($r['numero_hasta']), 'current_value' => (int) $r['current_value'],
        'fecha_vencimiento' => $r['fecha_vencimiento'] === null ? null : (string) $r['fecha_vencimiento'],
    ], $s4RangosEcfA)
    && $s4Hechos['ecf_facturas'] === (int) $s4Uno("SELECT COUNT(*) FROM facturas WHERE ambiente_dgii = 'ecf'")
    && $s4Hechos['clientes_prueba']['ids'] === array_map('intval', $s4Pdo->query("SELECT id FROM clients WHERE rnc IN ('131880681', '533445861') ORDER BY id")->fetchAll(PDO::FETCH_COLUMN)),
    [$s4Hechos['rangos_ecf'], $s4Hechos['ecf_facturas'], $s4Hechos['clientes_prueba']]);

// --- los hechos de A se evaluan: lo que tiene A hoy (sin certificado, emisor con otro RNC, POS prendido, hash de integracion) ---------------
$s4Ev = express_preflight_evaluar($s4Hechos);
wa_chk('evaluar(hechos de A): tenant, columnas, tipos, modulos, clientes_modulo y almacen pasan; cert (sin certificado) y emisor (otro RNC) se rechazan; m036 sigue a la columna',
    $s4Ev['items']['tenant']['estado'] === 'ok' && $s4Ev['items']['columnas']['estado'] === 'ok' && $s4Ev['items']['tipos']['estado'] === 'ok'
    && $s4Ev['items']['modulos']['estado'] === 'ok' && $s4Ev['items']['clientes_modulo']['estado'] === 'ok' && $s4Ev['items']['almacen']['estado'] === 'ok'
    && $s4Ev['items']['cert']['estado'] === 'rechazar' && $s4Ev['items']['emisor']['estado'] === 'rechazar'
    && $s4Ev['items']['m036']['estado'] === ($s4Hechos['m036'] ? 'ok' : 'rechazar')
    && in_array('cert', $s4Ev['rechazos'], true) && in_array('emisor', $s4Ev['rechazos'], true) && express_plan($s4Hechos, $s4Ev) === [],
    array_map(static fn (array $i): string => $i['estado'], $s4Ev['items']));
wa_chk('evaluar(hechos de A): A tiene el POS prendido (pos_enabled 1), el hash de integracion y un rol user que no es el del Facturador: pos y inventario siguen a "nueva", integracion y roles se corrigen, y A es testecf (rangos no se evalua)',
    $s4Ev['items']['pos']['estado'] === ($s4Ev['nuevo'] ? 'corregir' : 'rechazar') && $s4Ev['items']['integracion']['estado'] === 'corregir'
    && $s4Ev['items']['roles']['estado'] === 'corregir' && $s4Ev['items']['rangos']['estado'] === 'ok', array_map(static fn (array $i): string => $i['estado'], $s4Ev['items']));

// --- rangos: el predicado de la biblioteca contra el dispensador real (ncfModel), sobre filas reales ----------------------------------------
// express_preflight_rango_vigente dice si hay un rango acotado, con capacidad y sin vencer. Se compara con ncfModel de verdad: dispenseNextECF (de donde saca el
// numero el dispensador: capacidad y vigencia) y rangoCubre (si un numero cae en un rango registrado y vigente; no mira current_value y cuenta un rango sin limite).
// Las filas van a un ambiente descartable de la base de A (ncf_sequences es unica por tipo, ambiente y numero_desde) y se borran al terminar. "Hoy" lo pone la
// prueba con el CURDATE() de MySQL, el reloj con el que comparan los dos metodos del modelo: asi la prueba no depende de la zona horaria de este php.
require_once __DIR__ . '/../src/Models/ncfModel.php';
$s4Amb = 'wa_eg_t8';
$s4HoyDb = (string) $s4Uno('SELECT CURDATE()');
$s4Dia = static fn (string $mod): string => (new DateTimeImmutable($s4HoyDb))->modify($mod)->format('Y-m-d');
[$s4Ayer, $s4Manana] = [$s4Dia('-1 day'), $s4Dia('+1 day')];
// Cada caso: [descripcion, filas (desde, hasta, current_value, vence, tipo opcional), numero que pregunta rangoCubre, esperado: [biblioteca, dispenseNextECF da numero, rangoCubre]].
$s4Casos = [
    ['un rango 1-100 con 40 usados que vence manana', [[1, 100, 40, $s4Manana]], 41, [true, true, true]],
    ['el mismo que vence hoy (el dia del vencimiento todavia vale)', [[1, 100, 40, $s4HoyDb]], 41, [true, true, true]],
    ['el mismo sin fecha de vencimiento', [[1, 100, 40, null]], 41, [true, true, true]],
    ['el mismo vencido ayer', [[1, 100, 40, $s4Ayer]], 41, [false, false, false]],
    ['agotado (100 de 100): sin capacidad, pero rangoCubre aun da por cubierto su ultimo numero', [[1, 100, 100, $s4Manana]], 100, [false, false, true]],
    ['un numero fuera del rango (150): rangoCubre no lo cubre, pero el rango sigue teniendo capacidad', [[1, 100, 40, $s4Manana]], 150, [true, true, false]],
    ['dos rangos, el primero agotado y el segundo con capacidad', [[1, 10, 10, $s4Manana], [11, 20, 10, $s4Manana]], 11, [true, true, true]],
    ['dos rangos, el primero con capacidad pero vencido y el segundo agotado', [[1, 10, 5, $s4Ayer], [11, 20, 20, $s4Manana]], 7, [false, false, false]],
    ['sin filas', [], 5, [false, false, false]],
    ['solo filas de otro tipo (E32)', [[1, 100, 40, $s4Manana, 'E32']], 41, [false, false, false]],
    ['solo un rango sin limite: no es un rango autorizado para la biblioteca, el dispensador si lo usa y rangoCubre lo cubre', [[1, null, 0, null]], 5, [false, true, true]],
    ['un rango sin limite tras uno acotado agotado: igual', [[1, 10, 10, $s4Manana], [11, null, 10, null]], 15, [false, true, true]],
    ['un rango acotado con capacidad junto a uno sin limite: los tres lo dan por bueno', [[1, 10, 3, $s4Manana], [11, null, 10, null]], 4, [true, true, true]],
];
wa_chk('precondicion: este proceso aun no tiene credenciales de Database (la prueba de rangos las pone y las quita)', !Database::hasCredentials());
if (!Database::hasCredentials()) {
    $s4Inserta = $s4Pdo->prepare(
        'INSERT INTO ncf_sequences (type, prefix, current_value, numero_desde, numero_hasta, fecha_vencimiento, description, ambiente)
         VALUES (:t, :t2, :c, :d, :h, :v, :descr, :a)'
    );
    $s4Resultados = [];
    try {
        $s4Cred = $ctx['cred'];
        Database::setCredentials(['host' => $s4Cred['host'], 'port' => $s4Cred['port'], 'name' => $ctx['dbA'], 'user' => $s4Cred['user'], 'pass' => $s4Cred['pass']]);
        $s4Ncf = new ncfModel();   // el modelo real, sobre la base de A
        foreach ($s4Casos as [$s4Desc, $s4Filas, $s4Numero, $s4Esperado]) {
            $s4Pdo->prepare('DELETE FROM ncf_sequences WHERE ambiente = :a')->execute([':a' => $s4Amb]);
            foreach ($s4Filas as $s4F) {
                $s4Tipo = $s4F[4] ?? 'E31';
                $s4Inserta->execute([':t' => $s4Tipo, ':t2' => $s4Tipo, ':c' => $s4F[2], ':d' => $s4F[0], ':h' => $s4F[1], ':v' => $s4F[3], ':descr' => 'Express T8', ':a' => $s4Amb]);
            }
            // Las filas como las pasa el recolector a la evaluacion (lectura independiente de la base, con las mismas conversiones).
            $s4Leidas = array_map(static fn (array $r): array => [
                'type' => (string) $r['type'], 'numero_hasta' => $s4EnteroONull($r['numero_hasta']), 'current_value' => (int) $r['current_value'],
                'fecha_vencimiento' => $r['fecha_vencimiento'] === null ? null : (string) $r['fecha_vencimiento'],
            ], $s4Pdo->query("SELECT type, numero_hasta, current_value, fecha_vencimiento FROM ncf_sequences WHERE ambiente = '{$s4Amb}' ORDER BY type, numero_desde")->fetchAll());
            $s4Biblioteca = express_preflight_rango_vigente($s4Leidas, 'E31', $s4HoyDb);
            $s4Cubre = $s4Ncf->rangoCubre('E31', $s4Amb, $s4Numero);   // no escribe
            $s4Dispensa = $s4Ncf->dispenseNextECF('E31', $s4Amb) !== null;   // escribe (suma uno al rango que elige): va al final
            $s4Resultados[$s4Desc] = [$s4Biblioteca, $s4Dispensa, $s4Cubre];
            wa_chk("rangos, {$s4Desc} -> biblioteca " . json_encode($s4Esperado[0]) . ', dispenseNextECF da numero ' . json_encode($s4Esperado[1]) . ', rangoCubre(' . $s4Numero . ') ' . json_encode($s4Esperado[2]),
                $s4Resultados[$s4Desc] === $s4Esperado, $s4Resultados[$s4Desc]);
        }
    } finally {
        $s4Pdo->prepare('DELETE FROM ncf_sequences WHERE ambiente = :a')->execute([':a' => $s4Amb]);
        Database::clearCredentials();
    }
    // La biblioteca decide como el dispensador en todo lo que es un rango acotado; solo se aparta en los rangos sin limite (que el dispensador usa y la DGII no autorizo).
    $s4Aparta = [];
    foreach ($s4Casos as [$s4Desc, $s4Filas]) {
        $s4SinRangoSinLimite = array_filter($s4Filas, static fn (array $f): bool => $f[1] === null) === [];
        if ($s4SinRangoSinLimite && $s4Resultados[$s4Desc][0] !== $s4Resultados[$s4Desc][1]) {
            $s4Aparta[] = $s4Desc;
        }
    }
    wa_chk('rangos: sin rangos sin limite de por medio, la biblioteca y dispenseNextECF dicen lo mismo en todos los casos (capacidad y vigencia)', count($s4Resultados) === count($s4Casos) && $s4Aparta === [], $s4Aparta);
    wa_chk('rangos: al terminar no queda ninguna fila del ambiente descartable en A y la prueba quito las credenciales de Database',
        (int) $s4Uno('SELECT COUNT(*) FROM ncf_sequences WHERE ambiente = :a', [':a' => $s4Amb]) === 0 && !Database::hasCredentials());
}

// --- express_plan: la baja de clientes de prueba contra el esquema real de A (sin llaves foraneas hacia clients), en una transaccion que se deshace ----
// Ninguna tabla declara una llave foranea hacia clients (cotizaciones, conduces y facturas guardan client_id sin FK: borrar un cliente desde la app tampoco
// mira esas tablas), asi que el DELETE del plan no puede chocar con una. Aqui se corre el SQL exacto del plan, con sus parametros, y se ve que borra y que no.
$s4Fk = $s4Pdo->query("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'clients'")->fetchAll();
$s4ConClientId = array_column($s4Pdo->query("SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'client_id' ORDER BY TABLE_NAME")->fetchAll(), 'TABLE_NAME');
wa_chk('esquema real de A: ninguna llave foranea apunta a clients, y client_id solo esta en cotizaciones, conduces, facturas y el respaldo de la migracion 035 (una tabla nueva con client_id obliga a revisar el DELETE del plan)',
    $s4Fk === [] && array_diff($s4ConClientId, ['clients_credito_respaldo_035', 'conduces', 'cotizaciones', 'facturas']) === [], [$s4Fk, $s4ConClientId]);
$s4Motores = (int) $s4Uno("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND ENGINE = 'InnoDB'
                           AND TABLE_NAME IN ('clients', 'facturas', 'cotizaciones', 'conduces', 'ncf_sequences')");
wa_chk('precondicion: clients, facturas, cotizaciones, conduces y ncf_sequences son InnoDB en A (la prueba corre en una transaccion que se deshace)', $s4Motores === 5, $s4Motores);
$s4MarcaFk = 'ZZ-EXPRESS-FK';
if ($s4Motores === 5) {
    $s4ErrorFk = null;
    $s4Pdo->beginTransaction();
    try {
        $s4NuevoCliente = static function (string $rnc, string $nombre) use ($s4Pdo): int {
            $s4Pdo->prepare(
                "INSERT INTO clients (email, client_name, company_name, rnc, razon_social, direccion, phone_number)
                 VALUES ('fk-prueba@example.com', :n1, :n2, :r, :n3, 'Direccion de prueba', '')"
            )->execute([':n1' => $nombre, ':n2' => $nombre, ':n3' => $nombre, ':r' => $rnc]);
            return (int) $s4Pdo->lastInsertId();
        };
        $s4Libre = $s4NuevoCliente('131880681', "{$s4MarcaFk} sin documentos");
        $s4ConDocs = $s4NuevoCliente('131880681', "{$s4MarcaFk} con cotizacion y conduce");
        $s4EnCertecf = $s4NuevoCliente('533445861', "{$s4MarcaFk} con factura certecf");
        $s4Pdo->prepare('INSERT INTO cotizaciones (code, client_id, client_name, total) VALUES (:c, :id, :n, 0)')
            ->execute([':c' => $s4MarcaFk, ':id' => $s4ConDocs, ':n' => "{$s4MarcaFk} con cotizacion y conduce"]);
        $s4NumeroConduce = 1 + (int) $s4Uno('SELECT COALESCE(MAX(numero), 0) FROM conduces');   // numero es UNIQUE: el siguiente libre
        $s4Pdo->prepare('INSERT INTO conduces (numero, code, date, client_id, client_name) VALUES (:num, :c, NOW(), :id, :n)')
            ->execute([':num' => $s4NumeroConduce, ':c' => $s4MarcaFk, ':id' => $s4ConDocs, ':n' => "{$s4MarcaFk} con cotizacion y conduce"]);
        $s4Pdo->prepare(
            "INSERT INTO facturas (no_factura, client_id, client_name, tipo_ecf, e_ncf, ambiente_dgii, estado_dgii)
             VALUES (:m, :c, :n, '31', 'E319999999998', 'certecf', 'ACEPTADO')"
        )->execute([':m' => $s4MarcaFk, ':c' => $s4EnCertecf, ':n' => "{$s4MarcaFk} con factura certecf"]);
        $s4Pdo->exec("INSERT INTO ncf_sequences (type, prefix, current_value, numero_desde, numero_hasta, description, ambiente)
                      VALUES ('E99', 'E99', 0, 1, NULL, 'Express FK prueba', 'ecf')");
        $s4FilasCert = static fn (): int => (int) $s4Uno("SELECT COUNT(*) FROM ncf_sequences WHERE ambiente IN ('certecf', 'testecf')");
        $s4CertAntes = $s4FilasCert();

        // El plan de una empresa nueva (la evaluacion armada a mano: lo que se prueba es el SQL de los pasos de la base de la empresa).
        $s4PasosFk = array_values(array_filter(
            express_plan($s4Hechos, ['items' => [], 'nuevo' => true, 'rechazos' => [], 'acciones' => []]),
            static fn (array $p): bool => $p['donde'] === 'tenant'
        ));
        wa_chk('precondicion: el plan de una empresa nueva trae dos pasos en la base de la empresa (los rangos ecf sin limite y los clientes de prueba sin facturas)',
            count($s4PasosFk) === 2 && str_contains($s4PasosFk[0]['sql'], 'ncf_sequences') && str_contains($s4PasosFk[1]['sql'], 'clients'), array_column($s4PasosFk, 'sql'));
        try {
            foreach ($s4PasosFk as $s4Paso) {
                $s4Pdo->prepare($s4Paso['sql'])->execute($s4Paso['params']);
            }
        } catch (Throwable $s4ErrorFk) {
        }
        $s4Esta = static fn (int $id): bool => (int) $s4Uno('SELECT COUNT(*) FROM clients WHERE id = :id', [':id' => $id]) === 1;
        wa_chk('los dos DELETE del plan corren sin error contra el esquema real de A, con una cotizacion y un conduce que apuntan a un cliente de prueba (no hay llave foranea que lo impida)',
            $s4ErrorFk === null, $s4ErrorFk === null ? null : wa_describir_excepcion($s4ErrorFk));
        wa_chk('el DELETE de clientes borra los de prueba que ninguna factura usa (aunque una cotizacion y un conduce los citen) y deja el que usa una factura certecf',
            !$s4Esta($s4Libre) && !$s4Esta($s4ConDocs) && $s4Esta($s4EnCertecf), [$s4Esta($s4Libre), $s4Esta($s4ConDocs), $s4Esta($s4EnCertecf)]);
        wa_chk('no se pierde ningun documento: la factura sigue apuntando a su cliente y la cotizacion y el conduce siguen ahi con su client_name, ahora con un client_id que ya no existe (como al borrar un cliente desde la app)',
            (int) $s4Uno('SELECT client_id FROM facturas WHERE no_factura = :m', [':m' => $s4MarcaFk]) === $s4EnCertecf
            && (string) $s4Uno('SELECT client_name FROM cotizaciones WHERE code = :m', [':m' => $s4MarcaFk]) === "{$s4MarcaFk} con cotizacion y conduce"
            && (string) $s4Uno('SELECT client_name FROM conduces WHERE code = :m', [':m' => $s4MarcaFk]) === "{$s4MarcaFk} con cotizacion y conduce"
            && (int) $s4Uno('SELECT client_id FROM cotizaciones WHERE code = :m', [':m' => $s4MarcaFk]) === $s4ConDocs);
        wa_chk("el DELETE de rangos borra el rango ecf sin limite (el E99 de la prueba) y no toca ni una fila de certecf ni de testecf",
            (int) $s4Uno("SELECT COUNT(*) FROM ncf_sequences WHERE ambiente = 'ecf' AND type = 'E99'") === 0 && $s4FilasCert() === $s4CertAntes, [$s4CertAntes, $s4FilasCert()]);
    } finally {
        if ($s4Pdo->inTransaction()) {
            $s4Pdo->rollBack();
        }
    }
    wa_chk('la transaccion se deshizo: no queda ninguno de los clientes, documentos ni rangos de la prueba',
        !$s4Pdo->inTransaction()
        && (int) $s4Uno('SELECT COUNT(*) FROM clients WHERE client_name LIKE :m', [':m' => "{$s4MarcaFk}%"]) === 0
        && (int) $s4Uno('SELECT COUNT(*) FROM cotizaciones WHERE code = :m', [':m' => $s4MarcaFk]) === 0
        && (int) $s4Uno('SELECT COUNT(*) FROM conduces WHERE code = :m', [':m' => $s4MarcaFk]) === 0
        && (int) $s4Uno('SELECT COUNT(*) FROM facturas WHERE no_factura = :m', [':m' => $s4MarcaFk]) === 0
        && (int) $s4Uno("SELECT COUNT(*) FROM ncf_sequences WHERE type = 'E99'") === 0);
}

// --- D4: lo que certificacion dejo en certecf nunca bloquea la conversion (solo se leen filas ecf) -----------------------------------
// Una factura certecf que usa un cliente de prueba de la DGII y un rango certecf con current_value 9: ecf_facturas no cambia, los rangos ecf
// tampoco, y la evaluacion no gana ningun rechazo. Todo lo que se crea o se cambia aqui se deshace en el finally.
$s4Marca = 'ZZ-EXPRESS-D4';
$s4NcfD4 = 'E319999999999';
$s4ClienteNuevo = null;
$s4Rango = null;
try {
    $s4Pdo->prepare('DELETE FROM facturas WHERE no_factura = :m')->execute([':m' => $s4Marca]);   // por si una corrida anterior murio a medias
    $s4Rango = $s4Pdo->query("SELECT id, current_value FROM ncf_sequences WHERE ambiente = 'certecf' AND type = 'E31' ORDER BY numero_desde LIMIT 1")->fetch();
    wa_chk('precondicion D4: A tiene un rango certecf E31 al que subirle el contador', is_array($s4Rango), $s4Rango);
    $s4Antes = express_preflight_hechos($s4Master, $s4Pdo, $s4Fila, $s4Pedido);
    $s4EvAntes = express_preflight_evaluar($s4Antes);

    $s4Cliente = (int) $s4Uno("SELECT id FROM clients WHERE rnc = '131880681' ORDER BY id LIMIT 1");
    if ($s4Cliente === 0) {   // wa_scratch no trae los clientes que siembra tenant_schema.sql: se crea uno y se borra al terminar
        $s4Pdo->prepare(
            "INSERT INTO clients (email, client_name, company_name, rnc, razon_social, direccion, phone_number)
             VALUES ('d4-prueba@example.com', 'D4 cliente de prueba DGII', 'D4 cliente de prueba DGII', '131880681', 'D4 CLIENTE DE PRUEBA DGII', 'Direccion de prueba', '')"
        )->execute();
        $s4Cliente = $s4ClienteNuevo = (int) $s4Pdo->lastInsertId();
    }
    $s4Pdo->prepare(
        "INSERT INTO facturas (no_factura, client_id, client_name, tipo_ecf, e_ncf, ambiente_dgii, estado_dgii)
         VALUES (:m, :c, 'D4 cliente de prueba DGII', '31', :n, 'certecf', 'ACEPTADO')"
    )->execute([':m' => $s4Marca, ':c' => $s4Cliente, ':n' => $s4NcfD4]);
    if (is_array($s4Rango)) {
        $s4Pdo->prepare('UPDATE ncf_sequences SET current_value = 9 WHERE id = :id')->execute([':id' => $s4Rango['id']]);
    }

    $s4Despues = express_preflight_hechos($s4Master, $s4Pdo, $s4Fila, $s4Pedido);
    $s4EvDespues = express_preflight_evaluar($s4Despues);
    $s4Estados = static fn (array $ev): array => array_map(static fn (array $i): string => $i['estado'], $ev['items']);
    wa_chk('D4: con la factura certecf y el contador certecf en 9, la factura se ve como referencia del cliente de prueba (refs_total) pero no como referencia ecf',
        $s4Despues['clientes_prueba']['refs_total'] >= 1 && $s4Despues['clientes_prueba']['refs_ecf'] === $s4Antes['clientes_prueba']['refs_ecf']
        && in_array($s4Cliente, $s4Despues['clientes_prueba']['ids'], true), [$s4Antes['clientes_prueba'], $s4Despues['clientes_prueba']]);
    wa_chk('D4: ecf_facturas y los rangos ecf quedan como estaban (certecf no se lee), y A sigue siendo nueva o no igual que antes',
        $s4Despues['ecf_facturas'] === $s4Antes['ecf_facturas'] && $s4Despues['rangos_ecf'] === $s4Antes['rangos_ecf']
        && $s4EvDespues['nuevo'] === $s4EvAntes['nuevo'], [$s4Antes['ecf_facturas'], $s4Despues['ecf_facturas']]);
    wa_chk('D4: la evaluacion no gana ningun rechazo ni cambia el estado de ningun item por lo que hay en certecf',
        $s4EvDespues['rechazos'] === $s4EvAntes['rechazos'] && $s4Estados($s4EvDespues) === $s4Estados($s4EvAntes)
        && $s4EvDespues['items']['clientes_prueba']['estado'] !== 'rechazar', [$s4EvAntes['rechazos'], $s4EvDespues['rechazos']]);
} finally {
    $s4Pdo->prepare('DELETE FROM facturas WHERE no_factura = :m')->execute([':m' => $s4Marca]);
    if (is_array($s4Rango)) {
        $s4Pdo->prepare('UPDATE ncf_sequences SET current_value = :v WHERE id = :id')->execute([':v' => $s4Rango['current_value'], ':id' => $s4Rango['id']]);
    }
    if ($s4ClienteNuevo !== null) {
        $s4Pdo->prepare('DELETE FROM clients WHERE id = :id')->execute([':id' => $s4ClienteNuevo]);
    }
}
wa_chk('D4: al terminar no queda la factura de prueba, el contador certecf vuelve a su valor y el cliente que creo la prueba se borro',
    (int) $s4Uno('SELECT COUNT(*) FROM facturas WHERE no_factura = :m', [':m' => $s4Marca]) === 0
    && (!is_array($s4Rango) || (int) $s4Uno('SELECT current_value FROM ncf_sequences WHERE id = :id', [':id' => $s4Rango['id']]) === (int) $s4Rango['current_value'])
    && ($s4ClienteNuevo === null || (int) $s4Uno('SELECT COUNT(*) FROM clients WHERE id = :id', [':id' => $s4ClienteNuevo]) === 0));

// ---------------------------------------------------------------------------
// 5) tools/tenant_edicion.php sobre la empresa A: ensayo, rechazos, aplicar, auditar, refrescar el certificado y revertir
// ---------------------------------------------------------------------------
echo "\n== 5) tenant_edicion.php sobre la empresa A ==\n";
$s5Master = $ctx['pdo'];
$s5IdA = (int) $ctx['tenantA'];
$s5IdB = (int) $ctx['tenantB'];
$s5Cred = $ctx['cred'];
$s5Secretos = pos_scratch_secretos($s5Cred['pass']);
$s5Cert = wa_cert_prueba();   // el .p12 desechable de este proceso: vale dos dias y su clave es al azar
express_usar_master($ctx);    // la master de prueba y su clave maestra: para TenantResolver::encrypt aqui y, por el entorno, para la herramienta
$s5Pdo = wa_pdo(POS_SCRATCH_BASE_A);   // la base de A, con la guarda de la marca de las bases de prueba

/** Un escalar de una consulta sobre la master o sobre la base de A. */
$s5Uno = static function (PDO $pdo, string $sql, array $params = []): mixed {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchColumn();
};
/** La fila completa de una empresa en master.tenants (SELECT *, como la leen MasterDatabase y la herramienta). */
$s5Fila = static function (int $id) use ($s5Master): array {
    $st = $s5Master->prepare('SELECT * FROM tenants WHERE id = :id');
    $st->execute([':id' => $id]);
    return (array) $st->fetch();
};
/** Los nombres de las columnas en que difieren dos filas, en orden alfabetico (nunca sus valores: algunas son secretos). */
$s5Difieren = static function (array $a, array $b): array {
    $nombres = [];
    foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $columna) {
        if (($a[$columna] ?? null) !== ($b[$columna] ?? null)) {
            $nombres[] = $columna;
        }
    }
    sort($nombres);
    return $nombres;
};
/** Los permisos del rol user de una empresa, en orden alfabetico. */
$s5Rol = static function (int $id) use ($s5Master): array {
    $st = $s5Master->prepare("SELECT rp.permission FROM role_permissions rp JOIN roles r ON r.id = rp.role_id WHERE r.tenant_id = :t AND r.name = 'user' ORDER BY rp.permission");
    $st->execute([':t' => $id]);
    return array_column($st->fetchAll(), 'permission');
};
/** Las filas de auditoria de la edicion de A (module edicion), de la mas vieja a la mas nueva. */
$s5Auditoria = static function () use ($s5Master, $s5IdA): array {
    $st = $s5Master->prepare("SELECT * FROM audit_logs WHERE tenant_id = :t AND module = 'edicion' ORDER BY id");
    $st->execute([':t' => $s5IdA]);
    return $st->fetchAll();
};
/** La fila 1 de emisor_config de la base de A: rnc, razon_social, direccion y telefono (lo que lee el item emisor del preflight); [] si no hay fila. */
$s5EmisorCfg = static fn (): array => $s5Pdo->query('SELECT rnc, razon_social, direccion, telefono FROM emisor_config WHERE id = 1')->fetch() ?: [];

// Lo que nunca debe salir en lo que imprime la herramienta: las credenciales de las bases, los secretos derivados, el certificado y su clave, la
// credencial de integracion de A. Se llena mas abajo con lo de A; cada corrida de la herramienta guarda su salida para revisarla al final.
$s5Prohibidos = [
    'la clave de la base' => $s5Cred['pass'], 'el usuario de la base' => $s5Cred['user'], 'el servidor' => $s5Cred['host'],
    'la clave maestra' => $s5Secretos['clave_maestra'], 'el pepper' => $s5Secretos['pepper'], 'la clave de los usuarios' => $s5Secretos['clave_usuarios'],
    'la clave del certificado' => $s5Cert['password'], 'la ruta del certificado' => $s5Cert['path'], 'el archivo del certificado' => basename($s5Cert['path']),
];
$s5Salidas = [];
/** tenant_edicion.php sobre A con los argumentos dados; guarda la salida para la revision de secretos del final. */
$s5Corre = static function (string ...$args) use ($s5IdA, &$s5Salidas): array {
    $r = express_cli(array_merge(['tools/tenant_edicion.php', "--tenant={$s5IdA}"], $args));
    $s5Salidas[] = $r['salida'];
    return $r;
};
/** Lo que se muestra de una corrida cuando una prueba falla: el codigo y la salida enmascarada y acotada. */
$s5Ver = static fn (array $r): array => ['codigo' => $r['codigo'], 'salida' => pos_scratch_enmascarar(substr($r['salida'], 0, 1800), array_values($s5Prohibidos))];
/** ¿Tiene la salida una linea que empieza con $prefijo ("[RECH] m036")? */
$s5Tiene = static fn (array $r, string $prefijo): bool => preg_match('/^' . preg_quote($prefijo, '/') . '/m', $r['salida']) === 1;

// La columna facturas.idempotency_key (migracion de tenant 036, del plan 04): la herramienta rechaza sin ella. Se guarda como esta (la definicion,
// su indice uk_facturas_idem y la columna que la antecede, tal como las muestra SHOW CREATE TABLE) para dejarla igual al terminar.
$s5Columna = static fn (): bool => (int) $s5Uno($s5Pdo, "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'facturas' AND COLUMN_NAME = 'idempotency_key'") === 1;
$s5Indice = static fn (): bool => (int) $s5Uno($s5Pdo, "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'facturas' AND INDEX_NAME = 'uk_facturas_idem'") > 0;
/** @return ?array{columna:?string,previa:?string,indice:?string} null si la columna no existe */
$s5Definicion = static function () use ($s5Pdo, $s5Columna): ?array {
    if (!$s5Columna()) {
        return null;
    }
    $ddl = (string) ($s5Pdo->query('SHOW CREATE TABLE facturas')->fetch(PDO::FETCH_NUM)[1] ?? '');
    $lineas = array_map('trim', explode("\n", $ddl));
    $definicion = ['columna' => null, 'previa' => null, 'indice' => null];
    foreach ($lineas as $i => $linea) {
        if (str_starts_with($linea, '`idempotency_key` ')) {
            $definicion['columna'] = rtrim($linea, ',');
            $definicion['previa'] = preg_match('/^`([^`]+)`/', $lineas[$i - 1] ?? '', $m) === 1 ? $m[1] : null;
        } elseif (preg_match('/^(UNIQUE KEY|KEY) `uk_facturas_idem` /', $linea) === 1) {
            $definicion['indice'] = rtrim($linea, ',');
        }
    }
    return $definicion;
};
/** Deja la columna como la definicion dada ("como se encontro") o, con null, sin ella (y sin su indice). */
$s5Restaurar = static function (?array $definicion) use ($s5Pdo, $s5Columna, $s5Indice): void {
    if ($definicion === null) {
        if ($s5Indice()) {
            $s5Pdo->exec('ALTER TABLE facturas DROP INDEX uk_facturas_idem');
        }
        if ($s5Columna()) {
            $s5Pdo->exec('ALTER TABLE facturas DROP COLUMN idempotency_key');
        }
        return;
    }
    if (!$s5Columna()) {
        $s5Pdo->exec('ALTER TABLE facturas ADD COLUMN ' . $definicion['columna'] . ($definicion['previa'] !== null ? ' AFTER `' . $definicion['previa'] . '`' : ''));
    }
    if ($definicion['indice'] !== null && !$s5Indice()) {
        $s5Pdo->exec('ALTER TABLE facturas ADD ' . $definicion['indice']);
    }
};
$s5Def0 = $s5Definicion();
if ($s5Def0 !== null && $s5Def0['indice'] === null) {
    // Una columna sin su indice uk_facturas_idem no es la de la migracion 036 (esa siempre lo trae): es la de prueba de una corrida de esta seccion que murio
    // a medias, y no cuenta como "encontrada": se quita para que no se quede para siempre al "restaurarla".
    $s5Restaurar(null);
    $s5Def0 = null;
}
wa_chk('precondicion: la definicion de facturas.idempotency_key se pudo leer tal como esta (o la columna no existe todavia), para dejarla igual al terminar',
    $s5Def0 === null || ($s5Def0['columna'] !== null && $s5Def0['previa'] !== null), $s5Def0);

// Lo que esta seccion toca fuera de lo que restaura express_scratch_preparar(): inventario_estricto de A, las filas de prueba de la base de A
// (facturas, clientes, rangos), la columna de arriba y el RNC de la fila 1 de emisor_config de A. Todo vuelve a su sitio en el finally. El emisor
// lo repone tambien la siembra de express_scratch_preparar() (pos_scratch_preparar llama a wa_sembrar), pero esta seccion no depende de ella: lo
// restaura el finally y la prueba del final lo mide antes de que esa siembra corra.
$s5Inventario0 = (int) $s5Uno($s5Master, 'SELECT inventario_estricto FROM tenants WHERE id = :id', [':id' => $s5IdA]);
$s5Marca = 'ZZ-EXPRESS-T9';
$s5Fila0 = $s5Fila($s5IdA);
$s5FilaB0 = $s5Fila($s5IdB);
$s5RolB0 = $s5Rol($s5IdB);
$s5EmisorCfg0 = $s5EmisorCfg();   // como lo dejo la siembra al arrancar la suite: abajo se le pone el RNC de la empresa y el finally se lo quita
wa_chk('precondicion: la fila 1 de emisor_config de A existe y su RNC todavia no es el de la empresa (la seccion se lo pone: asi restaurarlo se nota)',
    $s5EmisorCfg0 !== [] && $s5EmisorCfg0['rnc'] !== (string) $s5Fila0['rnc'],
    ['hay_fila' => $s5EmisorCfg0 !== [], 'rnc_ya_es_el_de_la_empresa' => ($s5EmisorCfg0['rnc'] ?? null) === (string) $s5Fila0['rnc']]);
$s5Hash0 = (string) $s5Fila0['api_secret_hash'];
$s5Prohibidos['el hash de la credencial de integracion'] = $s5Hash0;
$s5Prohibidos['la api key de A'] = (string) $s5Fila0['api_key'];
// La credencial de integracion de A, derivada como lo hace pos_scratch_preparar (la clave tal cual; el secreto, la cadena cuyo sha256 es el hash guardado).
$s5ApiKey = hash('sha256', 'wa_pos_scratch|api_key|A|' . $s5Cred['pass']);
$s5ApiSecret = 'wa_pos_scratch|api_secret|A|' . $s5Cred['pass'];
$s5Prohibidos['el secreto de integracion de A'] = $s5ApiSecret;
$s5TokenViejo = null;
$s5Libre = $s5EnUso = null;
$s5Rango = null;
$s5Api = null;
/** ¿Funciona la credencial de integracion de A? (TenantResolver::resolveByCredentials, que la resolucion de una peticion de integracion usa.) Limpia lo que la resolucion deja puesto. */
$s5Credencial = static function () use ($s5ApiKey, $s5ApiSecret): bool {
    try {
        return TenantResolver::resolveByCredentials($s5ApiKey, $s5ApiSecret);
    } finally {
        Database::clearCredentials();
        (new ReflectionProperty('TenantResolver', 'current'))->setValue(null, null);
    }
};
$s5Final = null;
$s5EmisorCfgRestaurado = [];   // la fila de emisor_config tal como la deja la restauracion del finally (se lee ahi, antes de la siembra de express_scratch_preparar())

try {
    // --- A queda lista para convertir: nueva, con certificado, con emisor completo y con algo que corregir en cada item que corrige ------------------
    wa_limpiar($s5Pdo);   // sin facturas y con los contadores al inicio (la guarda de la marca ya paso): A es nueva sea lo que sea que otras suites dejaron en ecf
    $s5Master->prepare("DELETE FROM audit_logs WHERE tenant_id = :t AND module = 'edicion'")->execute([':t' => $s5IdA]);   // por si una corrida anterior murio a medias
    $s5Pdo->prepare('DELETE FROM facturas WHERE no_factura = :m')->execute([':m' => $s5Marca]);
    $s5Pdo->prepare('DELETE FROM clients WHERE client_name LIKE :m')->execute([':m' => "{$s5Marca}%"]);
    if ($s5Def0 === null) {
        // La columna presente para el ensayo (la de la migracion 036 si el plan 04 ya la agrego a la base scratch; si no, la de prueba de esta tarea).
        $s5Pdo->exec('ALTER TABLE facturas ADD COLUMN idempotency_key CHAR(36) NULL');
    }
    $s5Cambio = $s5Master->prepare('UPDATE tenants SET cert_path = :p, cert_pass_encrypted = :c, pos_enabled = 1, inventario_estricto = 1 WHERE id = :id');
    $s5Cambio->bindValue(':p', $s5Cert['path']);
    $s5Cambio->bindValue(':c', TenantResolver::encrypt($s5Cert['password']), PDO::PARAM_LOB);   // binario, como create_tenant.php y set_tenant_cert.php
    $s5Cambio->bindValue(':id', $s5IdA, PDO::PARAM_INT);
    $s5Cambio->execute();
    $s5Pdo->prepare('UPDATE emisor_config SET rnc = :r WHERE id = 1')->execute([':r' => (string) $s5Fila0['rnc']]);
    // Un rango ecf sin limite (lo que siembra el esquema y el apply borra) y dos clientes de prueba de la DGII: uno que ninguna factura usa y otro que usa
    // una factura de certecf. Y D4: el contador de un rango certecf en 9 y esa factura. Nada de eso puede producir un [RECH].
    $s5Pdo->exec("INSERT INTO ncf_sequences (type, prefix, current_value, numero_desde, numero_hasta, description, ambiente)
                  VALUES ('E99', 'E99', 0, 1, NULL, 'Express T9', 'ecf')
                  ON DUPLICATE KEY UPDATE numero_hasta = NULL, current_value = 0");
    $s5NuevoCliente = static function (string $rnc, string $nombre) use ($s5Pdo): int {
        $s5Pdo->prepare(
            "INSERT INTO clients (email, client_name, company_name, rnc, razon_social, direccion, phone_number)
             VALUES ('t9-prueba@example.com', :n1, :n2, :r, :n3, 'Direccion de prueba', '')"
        )->execute([':n1' => $nombre, ':n2' => $nombre, ':n3' => $nombre, ':r' => $rnc]);
        return (int) $s5Pdo->lastInsertId();
    };
    $s5Libre = $s5NuevoCliente('131880681', "{$s5Marca} sin facturas");
    $s5EnUso = $s5NuevoCliente('533445861', "{$s5Marca} con factura de certecf");
    $s5Pdo->prepare(
        "INSERT INTO facturas (no_factura, client_id, client_name, tipo_ecf, e_ncf, ambiente_dgii, estado_dgii)
         VALUES (:m, :c, :n, '31', 'E319999999997', 'certecf', 'ACEPTADO')"
    )->execute([':m' => $s5Marca, ':c' => $s5EnUso, ':n' => "{$s5Marca} con factura de certecf"]);
    $s5Rango = $s5Pdo->query("SELECT id, current_value FROM ncf_sequences WHERE ambiente = 'certecf' AND type = 'E31' ORDER BY numero_desde LIMIT 1")->fetch();
    if (is_array($s5Rango)) {
        $s5Pdo->prepare('UPDATE ncf_sequences SET current_value = 9 WHERE id = :id')->execute([':id' => $s5Rango['id']]);
    }
    $s5Vence = null;
    $s5Partes = [];
    if (@openssl_pkcs12_read((string) file_get_contents($s5Cert['path']), $s5Partes, $s5Cert['password'])) {
        $s5Datos = openssl_x509_parse((string) $s5Partes['cert']);
        $s5Vence = is_array($s5Datos) && is_int($s5Datos['validTo_time_t'] ?? null) ? date('Y-m-d', $s5Datos['validTo_time_t']) : null;   // el dia del notAfter, en la zona de este proceso
    }
    unset($s5Partes);
    $s5Ilimitados = static fn (): int => (int) $s5Uno($s5Pdo, "SELECT COUNT(*) FROM ncf_sequences WHERE ambiente = 'ecf' AND numero_hasta IS NULL");
    $s5Existe = static fn (?int $clienteId): bool => $clienteId !== null && (int) $s5Uno($s5Pdo, 'SELECT COUNT(*) FROM clients WHERE id = :id', [':id' => $clienteId]) === 1;
    $s5Prueba = static fn (): int => (int) $s5Uno($s5Pdo, 'SELECT COUNT(*) FROM facturas WHERE no_factura = :m', [':m' => $s5Marca]);
    $s5Rol0 = $s5Rol($s5IdA);
    $s5Antes = $s5Fila($s5IdA);
    wa_chk('precondicion: A es nueva (ni una factura ni un contador ecf), tiene el POS y el control de existencias prendidos, rangos ecf sin limite, un cliente de prueba libre y otro usado por una factura de certecf, y el certificado de prueba abre',
        (int) $s5Uno($s5Pdo, "SELECT COUNT(*) FROM facturas WHERE ambiente_dgii = 'ecf'") === 0
        && (int) $s5Uno($s5Pdo, "SELECT COUNT(*) FROM ncf_sequences WHERE ambiente = 'ecf' AND current_value > 0") === 0
        && (int) $s5Antes['pos_enabled'] === 1 && (int) $s5Antes['inventario_estricto'] === 1 && $s5Antes['edicion'] === 'completa' && $s5Antes['api_secret_hash'] !== ''
        && $s5Ilimitados() >= 1 && $s5Existe($s5Libre) && $s5Existe($s5EnUso) && $s5Prueba() === 1 && is_string($s5Vence) && $s5Rol0 !== [] && $s5Auditoria() === [],
        ['ilimitados' => $s5Ilimitados(), 'libre' => $s5Existe($s5Libre), 'en_uso' => $s5Existe($s5EnUso), 'vence' => $s5Vence, 'rol' => $s5Rol0]);
    wa_chk('(4) la credencial de integracion de A funciona antes de convertirla (TenantResolver::resolveByCredentials con su api key y su secreto)', $s5Credencial() === true);

    // --- (1) ensayo: ni una escritura ---------------------------------------------------------------------------------------------------------
    $r = $s5Corre('--edicion=express', '--tipos=45');
    wa_chk('(1) ensayo (--tipos=45 sin --apply): sale con 0 y dice DRY-RUN: no se escribio nada, sin APLICADO ni RECHAZADO',
        $r['codigo'] === 0 && str_contains($r['salida'], 'DRY-RUN: no se escribio nada. Agrega --apply para aplicar.')
        && !str_contains($r['salida'], 'APLICADO') && !str_contains($r['salida'], 'RECHAZADO'), $s5Ver($r));
    wa_chk('(1) el ensayo marca [CORR] lo que la conversion corregiria (pos, inventario, ilimitados, clientes_prueba, integracion y roles) y no hay ningun [RECH]: lo de certecf (D4) no bloquea',
        array_filter(['pos', 'inventario', 'ilimitados', 'clientes_prueba', 'integracion', 'roles'], static fn (string $id): bool => !$s5Tiene($r, "[CORR] {$id}:")) === []
        && preg_match('/^\[RECH\]/m', $r['salida']) === 0 && $s5Tiene($r, '[OK  ] cert:') && $s5Tiene($r, '[OK  ] emisor:') && $s5Tiene($r, '[OK  ] m036:'), $s5Ver($r));
    wa_chk('(1) el ensayo no escribio nada: la fila de A en tenants es identica, el rol user igual, los rangos ecf sin limite, los clientes de prueba y la factura de certecf siguen, y no hay fila de auditoria',
        $s5Difieren($s5Antes, $s5Fila($s5IdA)) === [] && $s5Rol($s5IdA) === $s5Rol0 && $s5Ilimitados() >= 1 && $s5Existe($s5Libre) && $s5Existe($s5EnUso) && $s5Prueba() === 1
        && $s5Auditoria() === [], $s5Difieren($s5Antes, $s5Fila($s5IdA)));
    $r = $s5Corre('--edicion=express', '--tipos=45, 31,');
    wa_chk('(1) con los tipos escritos a mano con espacios y coma al final ("45, 31,") el ensayo los entiende: sale con 0 y el encabezado nombra el 45 y el 31',
        $r['codigo'] === 0 && str_contains($r['salida'], "'45'") && str_contains($r['salida'], "'31'") && str_contains($r['salida'], 'DRY-RUN'), $s5Ver($r));

    // --- (2) lo que se rechaza: sale con 3, no se cambia nada ---------------------------------------------------------------------------------------
    foreach ([
        '--tipos=44 (un tipo que Express no vende)' => [['--edicion=express', '--tipos=44', '--apply'], 'tipos', "'44'"],
        '--tipos=e45 (un tipo mal escrito: RF2)' => [['--edicion=express', '--tipos=e45', '--apply'], 'tipos', "'e45'"],
        '--modulos=facturacion,cliente (un modulo mal escrito: RF2)' => [['--edicion=express', '--tipos=45', '--modulos=facturacion,cliente', '--apply'], 'modulos', "'cliente'"],
        '--tipos=45 con --modulos=facturacion (sin clientes no se puede el 45)' => [['--edicion=express', '--tipos=45', '--modulos=facturacion', '--apply'], 'clientes_modulo', 'clientes'],
    ] as $s5Desc => [$s5Args, $s5Item, $s5Aguja]) {
        $r = $s5Corre(...$s5Args);
        wa_chk("(2) {$s5Desc} con --apply: sale con 3, RECHAZADO, el item [RECH] {$s5Item} nombra {$s5Aguja}, y no cambia nada (la fila de A identica, nada borrado, sin fila de auditoria)",
            $r['codigo'] === 3 && str_contains($r['salida'], 'RECHAZADO:') && str_contains($r['salida'], 'No se cambio nada.') && $s5Tiene($r, "[RECH] {$s5Item}:") && str_contains($r['salida'], $s5Aguja)
            && !str_contains($r['salida'], 'APLICADO') && $s5Difieren($s5Antes, $s5Fila($s5IdA)) === [] && $s5Rol($s5IdA) === $s5Rol0 && $s5Ilimitados() >= 1
            && $s5Existe($s5Libre) && $s5Auditoria() === [], $s5Ver($r));
    }
    $r = express_cli(['tools/tenant_edicion.php', '--tenant=2147483000', '--edicion=completa', '--apply']);
    $s5Salidas[] = $r['salida'];
    wa_chk('(2) una empresa que no existe (id 2147483000): sale con 3 con [RECH] tenant y RECHAZADO, sin escribir nada',
        $r['codigo'] === 3 && $s5Tiene($r, '[RECH] tenant:') && str_contains($r['salida'], 'RECHAZADO: 1 item(s). No se cambio nada.'), $s5Ver($r));

    // --- (3) sin la migracion de tenant 036 se rechaza; con ella, se aplica -----------------------------------------------------------------------
    $s5Restaurar(null);   // facturas.idempotency_key ausente (con su indice, si lo tenia): se restaura como se encontro en el finally
    $r = $s5Corre('--edicion=express', '--tipos=45', '--apply');
    wa_chk('(3) sin facturas.idempotency_key (la columna se quito de la base de A): --tipos=45 --apply sale con 3 y m036 es la linea [RECH]; la fila de A sigue igual y no hay auditoria',
        $r['codigo'] === 3 && $s5Tiene($r, '[RECH] m036:') && preg_match_all('/^\[RECH\]/m', $r['salida']) === 1 && str_contains($r['salida'], 'RECHAZADO: 1 item(s). No se cambio nada.')
        && $s5Difieren($s5Antes, $s5Fila($s5IdA)) === [] && $s5Rol($s5IdA) === $s5Rol0 && $s5Ilimitados() >= 1 && $s5Auditoria() === [], $s5Ver($r));
    // La columna de prueba de esta tarea (contratos 4.1: el plan 04 quita este ALTER cuando agregue la migracion 036 al esquema scratch).
    $s5Pdo->exec('ALTER TABLE facturas ADD COLUMN idempotency_key CHAR(36) NULL');
    $r = $s5Corre('--edicion=express', '--tipos=45', '--apply');
    wa_chk("(3) con facturas.idempotency_key: --tipos=45 --apply sale con 0 e imprime APLICADO: el tenant #{$s5IdA} ahora es express., sin ningun [RECH] (el D4 de nuevo: la factura y el contador de certecf no bloquean)",
        $r['codigo'] === 0 && str_contains($r['salida'], "APLICADO: el tenant #{$s5IdA} ahora es express.") && preg_match('/^\[RECH\]/m', $r['salida']) === 0
        && !str_contains($r['salida'], 'DRY-RUN') && !str_contains($r['salida'], 'RECHAZADO'), $s5Ver($r));
    $s5Despues = $s5Fila($s5IdA);
    wa_chk('(3) en la master: edicion express, tipos_ecf_habilitados 45, sin modulos (el preset), cert_vence el dia del notAfter del certificado, POS e inventario en 0 y la credencial de integracion en blanco',
        $s5Despues['edicion'] === 'express' && $s5Despues['tipos_ecf_habilitados'] === '45' && $s5Despues['modulos_habilitados'] === null && $s5Despues['cert_vence'] === $s5Vence
        && (int) $s5Despues['pos_enabled'] === 0 && (int) $s5Despues['inventario_estricto'] === 0 && $s5Despues['api_secret_hash'] === '',
        array_intersect_key($s5Despues, array_flip(['edicion', 'tipos_ecf_habilitados', 'modulos_habilitados', 'cert_vence', 'pos_enabled', 'inventario_estricto'])) + ['vence_esperado' => $s5Vence]);
    wa_chk('(3) solo cambian en A las columnas que la conversion toca: edicion, tipos, vencimiento del certificado, POS, inventario y hash de integracion (lo demas de la fila, incluidos cert_path y las claves, queda igual)',
        array_diff($s5Difieren($s5Antes, $s5Despues), ['edicion', 'tipos_ecf_habilitados', 'cert_vence', 'pos_enabled', 'inventario_estricto', 'api_secret_hash']) === []
        && array_diff(['edicion', 'tipos_ecf_habilitados', 'cert_vence', 'pos_enabled', 'inventario_estricto', 'api_secret_hash'], $s5Difieren($s5Antes, $s5Despues)) === [], $s5Difieren($s5Antes, $s5Despues));
    $s5Facturador = ['facturas', 'clients', 'products', 'unidades'];
    sort($s5Facturador);
    wa_chk('(3) el rol user de A tiene exactamente los permisos del Facturador (facturas, clients, products y unidades) y antes tenia otros',
        $s5Rol($s5IdA) === $s5Facturador && $s5Rol0 !== $s5Facturador, [$s5Rol($s5IdA), $s5Rol0]);
    wa_chk('(3) en la base de A: ya no hay ningun rango ecf con numero_hasta NULL (antes habia), el cliente de prueba sin facturas se borro y el que usa una factura de certecf sigue',
        $s5Ilimitados() === 0 && !$s5Existe($s5Libre) && $s5Existe($s5EnUso), [$s5Ilimitados(), $s5Existe($s5Libre), $s5Existe($s5EnUso)]);
    wa_chk('(3) D4: lo de certecf quedo como estaba: la factura de certecf sigue ahi y el contador certecf del rango sigue en 9',
        $s5Prueba() === 1 && (!is_array($s5Rango) || (int) $s5Uno($s5Pdo, 'SELECT current_value FROM ncf_sequences WHERE id = :id', [':id' => $s5Rango['id']]) === 9), $s5Prueba());
    $s5Filas = $s5Auditoria();
    $s5AuditoriaAntes = json_decode((string) ($s5Filas[0]['old_values'] ?? ''), true);
    $s5AuditoriaDespues = json_decode((string) ($s5Filas[0]['new_values'] ?? ''), true);
    wa_chk('(3) hay UNA fila de auditoria, con module edicion, action EDICION_CAMBIO, entity_type tenant, el id de A, username tools/tenant_edicion.php y success 1',
        count($s5Filas) === 1 && $s5Filas[0]['module'] === 'edicion' && $s5Filas[0]['action'] === 'EDICION_CAMBIO' && $s5Filas[0]['entity_type'] === 'tenant'
        && (int) $s5Filas[0]['tenant_id'] === $s5IdA && (string) $s5Filas[0]['entity_id'] === (string) $s5IdA && $s5Filas[0]['username'] === 'tools/tenant_edicion.php' && (int) $s5Filas[0]['success'] === 1,
        array_diff_key($s5Filas[0] ?? [], ['old_values' => 1, 'new_values' => 1]));
    wa_chk('(3) su old_values trae integracion_hash_anterior (el hash que se dejo en blanco) y el estado de antes (completa, POS e inventario en 1); su new_values, el de despues, sin ese hash',
        is_array($s5AuditoriaAntes) && is_array($s5AuditoriaDespues) && ($s5AuditoriaAntes['integracion_hash_anterior'] ?? null) === $s5Hash0 && $s5Hash0 !== ''
        && $s5AuditoriaAntes['edicion'] === 'completa' && $s5AuditoriaAntes['pos_enabled'] === 1 && $s5AuditoriaAntes['inventario_estricto'] === 1
        && $s5AuditoriaDespues['edicion'] === 'express' && $s5AuditoriaDespues['tipos_ecf_habilitados'] === '45' && $s5AuditoriaDespues['cert_vence'] === $s5Vence
        && $s5AuditoriaDespues['pos_enabled'] === 0 && $s5AuditoriaDespues['inventario_estricto'] === 0 && !array_key_exists('integracion_hash_anterior', $s5AuditoriaDespues),
        ['antes' => array_diff_key((array) $s5AuditoriaAntes, ['integracion_hash_anterior' => 1]), 'despues' => $s5AuditoriaDespues]);
    wa_chk('(3) la fila de auditoria no lleva la ruta ni la clave del certificado ni las claves de las bases',
        array_filter(array_diff_key($s5Prohibidos, ['el hash de la credencial de integracion' => 1]), static fn (string $v): bool => str_contains((string) ($s5Filas[0]['old_values'] ?? '') . ($s5Filas[0]['new_values'] ?? '') . ($s5Filas[0]['description'] ?? ''), $v)) === []);
    wa_chk('(4) la credencial de integracion de A ya no funciona despues de convertirla: el resolvedor rechaza un hash vacio', $s5Credencial() === false);
    wa_chk('la conversion solo toco a A: la fila de B en tenants y los permisos de su rol user siguen identicos',
        $s5Difieren($s5FilaB0, $s5Fila($s5IdB)) === [] && $s5Rol($s5IdB) === $s5RolB0, $s5Difieren($s5FilaB0, $s5Fila($s5IdB)));

    // --- (5) --refrescar-cert: cert_vence sigue al archivo, y GET /api/emisor lo informa (RF4) ---------------------------------------------------
    $s5Api = express_api('express_api', 8294);
    $s5TokenViejo = express_login($s5Api, 'admin_pos_a')['token'];   // la sesion de A mientras es Express: la de (6) ya no recibira entitlements
    $s5Emisor = static fn (): array => wa_http('GET', $s5Api . '/api/emisor', null, express_h((string) $s5TokenViejo));
    $s5Master->prepare('UPDATE tenants SET cert_vence = :v WHERE id = :id')->execute([':v' => '2020-01-01', ':id' => $s5IdA]);
    $s5Mal = $s5Emisor();
    wa_chk('(5) con cert_vence en una fecha equivocada (2020-01-01), GET /api/emisor la informa tal cual: certificado vencido',
        $s5Mal['status'] === 200 && ($s5Mal['json']['data']['certificado']['vence'] ?? null) === '2020-01-01' && ($s5Mal['json']['data']['certificado']['estado'] ?? null) === 'vencido', [$s5Mal['status'], $s5Mal['json']['data']['certificado'] ?? null]);
    $s5AntesRefresco = $s5Fila($s5IdA);
    $r = $s5Corre('--refrescar-cert');
    wa_chk('(5) --refrescar-cert en ensayo: sale con 0, dice DRY-RUN y no escribe (cert_vence sigue en 2020-01-01)',
        $r['codigo'] === 0 && str_contains($r['salida'], 'DRY-RUN: no se escribio nada. Agrega --apply para aplicar.') && $s5Difieren($s5AntesRefresco, $s5Fila($s5IdA)) === []
        && $s5Fila($s5IdA)['cert_vence'] === '2020-01-01', $s5Ver($r));
    $r = $s5Corre('--refrescar-cert', '--apply');
    wa_chk('(5) --refrescar-cert --apply: sale con 0 y cert_vence es otra vez la fecha del notAfter del certificado; solo esa columna cambia',
        $r['codigo'] === 0 && str_contains($r['salida'], 'APLICADO:') && $s5Fila($s5IdA)['cert_vence'] === $s5Vence && $s5Difieren($s5AntesRefresco, $s5Fila($s5IdA)) === ['cert_vence'], $s5Ver($r));
    $s5Bien = $s5Emisor();
    $s5Hoy = new DateTimeImmutable(date('Y-m-d') . ' 00:00:00', new DateTimeZone('UTC'));
    $s5Dias = is_string($s5Vence) ? (int) $s5Hoy->diff(new DateTimeImmutable($s5Vence . ' 00:00:00', new DateTimeZone('UTC')))->format('%r%a') : null;
    wa_chk('(5) GET /api/emisor (la misma sesion, la peticion siguiente) informa ya la fecha del certificado: por_vencer, con su vence y los dias que faltan',
        $s5Bien['status'] === 200 && ($s5Bien['json']['data']['certificado'] ?? null) === ['estado' => 'por_vencer', 'vence' => $s5Vence, 'dias' => $s5Dias], [$s5Bien['status'], $s5Bien['json']['data']['certificado'] ?? null, $s5Dias]);
    $r = $s5Corre('--refrescar-cert', '--apply');
    wa_chk('(5) --refrescar-cert cuando cert_vence ya es la fecha del certificado: sale con 0 sin escribir nada (SIN CAMBIOS)',
        $r['codigo'] === 0 && str_contains($r['salida'], 'SIN CAMBIOS:') && !str_contains($r['salida'], 'APLICADO') && $s5Difieren($s5AntesRefresco, $s5Fila($s5IdA)) === ['cert_vence'] && count($s5Auditoria()) === 1, $s5Ver($r));
    wa_chk('(5) refrescar el certificado no escribe fila de auditoria (no cambia la edicion): sigue la de la conversion',
        count($s5Auditoria()) === 1 && $s5Auditoria()[0]['action'] === 'EDICION_CAMBIO');
    $r = express_cli(['tools/tenant_edicion.php', "--tenant={$s5IdB}", '--refrescar-cert', '--apply']);
    $s5Salidas[] = $r['salida'];
    wa_chk('(5) --refrescar-cert sobre B (completa): sale con 3 con [RECH] edicion y no cambia la fila de B',
        $r['codigo'] === 3 && $s5Tiene($r, '[RECH] edicion:') && $s5Difieren($s5FilaB0, $s5Fila($s5IdB)) === [], $s5Ver($r));

    // --- (6) --edicion=completa: solo la edicion, el hash de integracion de vuelta, y la sesion de antes ya no recibe entitlements -------------------
    $s5MeAntes = wa_http('GET', $s5Api . '/api/auth/me', null, express_h((string) $s5TokenViejo));
    wa_chk('(6) precondicion: mientras A es Express, GET /api/auth/me con su sesion trae user.entitlements',
        $s5MeAntes['status'] === 200 && is_array($s5MeAntes['json']['data']['user']['entitlements'] ?? null), [$s5MeAntes['status']]);
    $s5AntesRevertir = $s5Fila($s5IdA);
    $s5RolAntesRevertir = $s5Rol($s5IdA);
    $r = $s5Corre('--edicion=completa');
    wa_chk('(6) revertir en ensayo: sale con 0, avisa que levanta toda restriccion y que no es una parada segura, dice DRY-RUN y no escribe nada',
        $r['codigo'] === 0 && str_contains($r['salida'], 'DRY-RUN: no se escribio nada.') && str_contains($r['salida'], 'no es una parada segura') && str_contains($r['salida'], 'tipos_ecf_habilitados') && str_contains($r['salida'], 'tokens')
        && $s5Difieren($s5AntesRevertir, $s5Fila($s5IdA)) === [] && count($s5Auditoria()) === 1, $s5Ver($r));
    $r = $s5Corre('--edicion=completa', '--apply');
    $s5Revertida = $s5Fila($s5IdA);
    wa_chk("(6) --edicion=completa --apply: sale con 0 e imprime APLICADO: el tenant #{$s5IdA} ahora es completa. y avisa que no es una parada segura",
        $r['codigo'] === 0 && str_contains($r['salida'], "APLICADO: el tenant #{$s5IdA} ahora es completa.") && str_contains($r['salida'], 'no es una parada segura'), $s5Ver($r));
    wa_chk('(6) en la master: edicion completa y api_secret_hash restaurado al original; no cambia nada mas de la fila (tipos, vencimiento, POS, inventario siguen como la conversion los dejo) ni los permisos del rol user',
        $s5Revertida['edicion'] === 'completa' && $s5Revertida['api_secret_hash'] === $s5Hash0 && $s5Difieren($s5AntesRevertir, $s5Revertida) === ['api_secret_hash', 'edicion'] && $s5Rol($s5IdA) === $s5RolAntesRevertir,
        $s5Difieren($s5AntesRevertir, $s5Revertida));
    $s5MeDespues = wa_http('GET', $s5Api . '/api/auth/me', null, express_h((string) $s5TokenViejo));
    wa_chk('(6) la sesion de antes (el mismo token) ya no recibe entitlements en GET /api/auth/me: el cambio de edicion se aplica en la peticion siguiente',
        $s5MeDespues['status'] === 200 && is_array($s5MeDespues['json']['data']['user'] ?? null) && !array_key_exists('entitlements', $s5MeDespues['json']['data']['user']), [$s5MeDespues['status'], array_keys((array) ($s5MeDespues['json']['data']['user'] ?? []))]);
    wa_chk('(6) la credencial de integracion de A vuelve a funcionar tras revertir (su hash se restauro de la bitacora)', $s5Credencial() === true);
    $s5Filas = $s5Auditoria();
    wa_chk('(6) hay dos filas de auditoria EDICION_CAMBIO: la conversion y la reversion (la de ahora no trae hash anterior: ya estaba en blanco y pasa a completa)',
        count($s5Filas) === 2 && array_unique(array_column($s5Filas, 'action')) === ['EDICION_CAMBIO']
        && (json_decode((string) ($s5Filas[1]['old_values'] ?? ''), true)['integracion_hash_anterior'] ?? null) === ''
        && (json_decode((string) ($s5Filas[1]['new_values'] ?? ''), true)['edicion'] ?? null) === 'completa'
        && (json_decode((string) ($s5Filas[1]['old_values'] ?? ''), true)['edicion'] ?? null) === 'express', array_column($s5Filas, 'action'));
    $r = $s5Corre('--edicion=completa', '--apply');
    wa_chk('(6) revertir otra vez a una empresa que ya es completa y tiene su hash: sale con 0, dice SIN CAMBIOS y no escribe (ni una tercera fila de auditoria)',
        $r['codigo'] === 0 && str_contains($r['salida'], 'SIN CAMBIOS:') && !str_contains($r['salida'], 'APLICADO') && $s5Difieren($s5Revertida, $s5Fila($s5IdA)) === [] && count($s5Auditoria()) === 2, $s5Ver($r));

    // --- el ciclo que pasa de verdad: convertir, volver a convertir con otros tipos (el hash ya esta en blanco) y revertir: el hash vuelve de la fila que lo trae ---
    $r = $s5Corre('--edicion=express', '--tipos=45,31', '--apply');
    $s5Ciclo1 = $r['codigo'] === 0 && $s5Fila($s5IdA)['edicion'] === 'express' && $s5Fila($s5IdA)['tipos_ecf_habilitados'] === '45,31' && $s5Fila($s5IdA)['api_secret_hash'] === '';
    wa_chk('ciclo: convertir otra vez a A con --tipos=45,31 sale con 0 y deja su credencial de integracion en blanco de nuevo (la fila de auditoria nueva guarda el hash que habia)',
        $s5Ciclo1 && (json_decode((string) ($s5Auditoria()[2]['old_values'] ?? ''), true)['integracion_hash_anterior'] ?? null) === $s5Hash0, $s5Ver($r));
    $r = $s5Corre('--edicion=express', '--tipos=45', '--apply');
    wa_chk('ciclo: volver a convertir con --tipos=45 (A ya es Express, sin nada que borrar) sale con 0 y su fila de auditoria trae el hash anterior en blanco: ya estaba en blanco',
        $r['codigo'] === 0 && $s5Fila($s5IdA)['tipos_ecf_habilitados'] === '45' && count($s5Auditoria()) === 4
        && (json_decode((string) ($s5Auditoria()[3]['old_values'] ?? ''), true)['integracion_hash_anterior'] ?? null) === '', $s5Ver($r));
    $r = $s5Corre('--edicion=completa', '--apply');
    wa_chk('ciclo: revertir restaura el hash de integracion de la fila mas nueva que lo trae (salta la ultima, que lo tiene en blanco) y la credencial de A vuelve a funcionar',
        $r['codigo'] === 0 && $s5Fila($s5IdA)['edicion'] === 'completa' && $s5Fila($s5IdA)['api_secret_hash'] === $s5Hash0 && $s5Credencial() === true && count($s5Auditoria()) === 5, $s5Ver($r));

    // --- express_aplicar contra InnoDB de verdad: si falla un paso de la master, no queda ni el cambio ni su fila de auditoria -----------------------
    $s5Motores = (int) $s5Uno($s5Master, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND ENGINE = 'InnoDB' AND TABLE_NAME IN ('tenants', 'audit_logs', 'roles', 'role_permissions')");
    wa_chk('precondicion: tenants, audit_logs, roles y role_permissions son InnoDB en la master scratch (el rollback de la prueba que sigue es de verdad)', $s5Motores === 4, $s5Motores);
    if ($s5Motores === 4) {
        $s5Estado = ['edicion' => 'express', 'tipos_ecf_habilitados' => '31', 'modulos_habilitados' => null, 'cert_vence' => null, 'pos_enabled' => 0, 'inventario_estricto' => 0];
        $s5AuditoriaPrueba = ['tenant_id' => $s5IdA, 'old_values' => ['edicion' => 'completa', 'integracion_hash_anterior' => ''], 'new_values' => $s5Estado, 'description' => 'Prueba T9 del rollback'];
        $s5PasoUpdate = ['donde' => 'master', 'que' => 'UPDATE valido', 'sql' => "UPDATE tenants SET edicion = 'express', tipos_ecf_habilitados = '31', modulos_habilitados = NULL, cert_vence = NULL WHERE id = :id", 'params' => [':id' => $s5IdA]];
        $s5PasoMalo = ['donde' => 'master', 'que' => 'UPDATE de una columna que no existe', 'sql' => 'UPDATE tenants SET zz_columna_que_no_existe = 1 WHERE id = :id', 'params' => [':id' => $s5IdA]];
        $s5BorraLibre = ['donde' => 'tenant', 'que' => 'Borrar un cliente de prueba', 'sql' => 'DELETE FROM clients WHERE id = :id', 'params' => [':id' => $s5EnUso]];
        $s5FilaAntes = $s5Fila($s5IdA);
        $s5NAuditoria = count($s5Auditoria());
        try {
            express_aplicar($s5Master, $s5Pdo, [$s5BorraLibre, $s5PasoUpdate, $s5PasoMalo], $s5AuditoriaPrueba);
            $s5Error = null;
        } catch (Throwable $s5Error) {
        }
        wa_chk('express_aplicar sobre la master scratch: si falla el segundo paso (una columna que no existe) lanza PDOException y el UPDATE de tenants que ya habia corrido se deshizo: la fila de A es identica y no hay fila de auditoria nueva',
            $s5Error instanceof PDOException && $s5Difieren($s5FilaAntes, $s5Fila($s5IdA)) === [] && count($s5Auditoria()) === $s5NAuditoria && !$s5Master->inTransaction(),
            [$s5Error === null ? null : wa_describir_excepcion($s5Error), $s5Difieren($s5FilaAntes, $s5Fila($s5IdA)), count($s5Auditoria()) - $s5NAuditoria]);
        wa_chk('...y el paso de la base de la empresa que corrio antes (la baja de un cliente de prueba) SI quedo: sus pasos son idempotentes y no se deshacen con la transaccion de la master',
            !$s5Existe($s5EnUso));
        $s5EnUso = null;   // ya se borro: el finally no lo busca
        try {
            // El UPDATE deja la empresa en express/31 pero la auditoria afirma otra cosa (tipos 45): la lectura posterior lo ve y lanza, con el cambio ya confirmado.
            express_aplicar($s5Master, null, [$s5PasoUpdate], ['new_values' => ['tipos_ecf_habilitados' => '45'] + $s5Estado] + $s5AuditoriaPrueba);
            $s5Error = null;
        } catch (Throwable $s5Error) {
        }
        wa_chk('express_aplicar sobre la master scratch: si la lectura de despues del commit no coincide con el plan (la auditoria dice tipos 45 y quedo 31) lanza RuntimeException que nombra la columna, con el cambio ya confirmado y su fila de auditoria escrita',
            $s5Error instanceof RuntimeException && str_contains($s5Error->getMessage(), 'tipos_ecf_habilitados') && $s5Fila($s5IdA)['tipos_ecf_habilitados'] === '31' && count($s5Auditoria()) === $s5NAuditoria + 1,
            [$s5Error === null ? null : get_class($s5Error) . ': ' . $s5Error->getMessage(), $s5Fila($s5IdA)['tipos_ecf_habilitados'], count($s5Auditoria()) - $s5NAuditoria]);
        try {
            express_aplicar($s5Master, null, [$s5PasoUpdate], $s5AuditoriaPrueba);   // y la misma, con la auditoria que dice lo mismo que el UPDATE: pasa limpia
            $s5Error = null;
        } catch (Throwable $s5Error) {
        }
        wa_chk('express_aplicar sobre la master scratch: con la auditoria igual al plan la lectura posterior coincide y no lanza', $s5Error === null, $s5Error === null ? null : wa_describir_excepcion($s5Error));
    }
    $s5Final = ['b' => $s5Difieren($s5FilaB0, $s5Fila($s5IdB)), 'rol_b' => $s5Rol($s5IdB) === $s5RolB0];
} finally {
    // Todo vuelve a su sitio: la columna como se encontro, las filas de prueba de la base de A, la fila 1 de emisor_config de A, la auditoria de A en la
    // master, A y B como completas y con su credencial, POS y roles (express_scratch_preparar() llama a pos_scratch_preparar()), inventario_estricto de A
    // y lo que dejo puesto el resolvedor.
    $s5Restaurar($s5Def0);
    $s5Pdo->prepare('DELETE FROM facturas WHERE no_factura = :m')->execute([':m' => $s5Marca]);
    if (is_array($s5Rango)) {
        $s5Pdo->prepare('UPDATE ncf_sequences SET current_value = :v WHERE id = :id')->execute([':v' => $s5Rango['current_value'], ':id' => $s5Rango['id']]);   // el valor que dejo wa_limpiar, leido antes de subirlo a 9
    }
    $s5Pdo->prepare('DELETE FROM clients WHERE client_name LIKE :m')->execute([':m' => "{$s5Marca}%"]);
    $s5Pdo->exec("DELETE FROM ncf_sequences WHERE ambiente = 'ecf' AND type = 'E99'");
    if ($s5EmisorCfg0 !== []) {   // el RNC de la empresa que la seccion le puso a emisor_config (y los otros tres datos que lee el preflight): como estaban
        $s5Pdo->prepare('UPDATE emisor_config SET rnc = :r, razon_social = :rs, direccion = :d, telefono = :t WHERE id = 1')
            ->execute([':r' => $s5EmisorCfg0['rnc'], ':rs' => $s5EmisorCfg0['razon_social'], ':d' => $s5EmisorCfg0['direccion'], ':t' => $s5EmisorCfg0['telefono']]);
    }
    $s5EmisorCfgRestaurado = $s5EmisorCfg();   // se lee ANTES de que express_scratch_preparar() reponga la siembra (wa_sembrar): la prueba del final mide esta restauracion y no esa
    $s5Master->prepare("DELETE FROM audit_logs WHERE tenant_id = :t AND module = 'edicion'")->execute([':t' => $s5IdA]);
    express_scratch_preparar();
    $s5Master->prepare('UPDATE tenants SET inventario_estricto = :v WHERE id = :id')->execute([':v' => $s5Inventario0, ':id' => $s5IdA]);
    Database::clearCredentials();
    (new ReflectionProperty('TenantResolver', 'current'))->setValue(null, null);
}
wa_chk('la conversion y la reversion solo tocaron a A: la fila de B en tenants y los permisos de su rol user quedaron identicos hasta el final',
    is_array($s5Final) && $s5Final['b'] === [] && $s5Final['rol_b'] === true, $s5Final);
// Ninguna corrida imprimio un secreto ni una ruta: la clave de las bases, los secretos derivados, el certificado y su clave, el hash y la clave de integracion de A.
$s5Fugas = [];
foreach ($s5Salidas as $s5I => $s5Salida) {
    foreach ($s5Prohibidos as $s5Que => $s5Valor) {
        if ($s5Valor !== '' && str_contains($s5Salida, $s5Valor)) {
            $s5Fugas[] = "corrida {$s5I}: {$s5Que}";
        }
    }
}
wa_chk('ninguna de las corridas de tenant_edicion.php imprimio una credencial (de la base, de integracion ni derivada), la ruta o la clave del certificado, el servidor ni el usuario de la base',
    count($s5Salidas) >= 15 && $s5Fugas === [], $s5Fugas);
$s5Limpia = $s5Fila($s5IdA);
$s5EmisorCfgFinal = $s5EmisorCfg();
wa_chk('al terminar A vuelve a ser completa, sin certificado ni listas, con el POS prendido, su inventario, su hash de integracion de siempre y los permisos del rol user de antes; sin auditoria de edicion; la columna idempotency_key como se encontro; y la fila 1 de su emisor_config (rnc, razon social, direccion y telefono) como estaba, justo despues de la restauracion del finally y al final',
    $s5Limpia['edicion'] === 'completa' && $s5Limpia['tipos_ecf_habilitados'] === null && $s5Limpia['modulos_habilitados'] === null && $s5Limpia['cert_vence'] === null
    && $s5Limpia['cert_path'] === null && $s5Limpia['cert_pass_encrypted'] === null && (int) $s5Limpia['pos_enabled'] === 1 && (int) $s5Limpia['inventario_estricto'] === $s5Inventario0
    && $s5Limpia['api_secret_hash'] === $s5Hash0 && $s5Rol($s5IdA) === $s5Rol0 && $s5Auditoria() === [] && $s5Definicion() === $s5Def0
    && (int) $s5Uno($s5Pdo, 'SELECT COUNT(*) FROM facturas WHERE no_factura = :m', [':m' => $s5Marca]) === 0
    && (int) $s5Uno($s5Pdo, 'SELECT COUNT(*) FROM clients WHERE client_name LIKE :m', [':m' => "{$s5Marca}%"]) === 0
    && $s5EmisorCfg0 !== [] && $s5EmisorCfgRestaurado === $s5EmisorCfg0 && $s5EmisorCfgFinal === $s5EmisorCfg0,
    array_intersect_key($s5Limpia, array_flip(['edicion', 'tipos_ecf_habilitados', 'cert_vence', 'pos_enabled', 'inventario_estricto'])) + ['columna' => $s5Definicion() === $s5Def0]
    + ['emisor_cfg_columnas_distintas' => ['tras_restaurar' => $s5Difieren($s5EmisorCfg0, $s5EmisorCfgRestaurado), 'al_final' => $s5Difieren($s5EmisorCfg0, $s5EmisorCfgFinal)]]);

// ---------------------------------------------------------------------------
// 5b) tools/create_tenant.php --edicion=express sin las columnas de la migracion master 014: se detiene antes de crear nada
// ---------------------------------------------------------------------------
echo "\n== 5b) create_tenant.php --edicion=express sin la migracion master 014 ==\n";
$s5bMaster = $ctx['pdo'];
$s5bCred = $ctx['cred'];
$s5bSecretos = pos_scratch_secretos($s5bCred['pass']);
$s5bColumnas = ['edicion', 'modulos_habilitados', 'tipos_ecf_habilitados', 'cert_vence'];
$s5bRnc = '131000009';
express_usar_master($ctx);   // la master de prueba y su clave maestra, por el entorno, para el hijo de express_cli

// Lo que nunca debe salir en lo que imprime la herramienta, y con lo que se enmascara lo que una prueba muestre al fallar. Una traza de excepcion de PHP corta cada
// argumento a 15 caracteres ('usuario_largo_de...'): ese principio de un valor largo tambien cuenta como fuga, y tambien se enmascara.
$s5bProhibidos = [
    'la clave de la base' => $s5bCred['pass'], 'el usuario de la base' => $s5bCred['user'], 'el servidor' => $s5bCred['host'],
    'la clave maestra' => $s5bSecretos['clave_maestra'], 'el pepper' => $s5bSecretos['pepper'], 'la clave de los usuarios' => $s5bSecretos['clave_usuarios'],
];
foreach ($s5bProhibidos as $s5bQue => $s5bValor) {
    if (strlen((string) $s5bValor) > 15) {
        $s5bProhibidos["{$s5bQue}, cortada a 15 caracteres como la muestra una traza"] = substr((string) $s5bValor, 0, 15);
    }
}
/** Lo que se muestra de una corrida cuando una prueba falla: el codigo y la salida enmascarada y acotada. */
$s5bVer = static fn (array $r): array => ['codigo' => $r['codigo'], 'salida' => pos_scratch_enmascarar(substr($r['salida'], 0, 1800), array_values($s5bProhibidos))];
/** ¿Tiene tenants esa columna ahora? (siempre un nombre de la lista de arriba). */
$s5bTiene = static fn (string $columna): bool => (int) $s5bMaster->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenants' AND COLUMN_NAME = '{$columna}'"
)->fetchColumn() === 1;
/** Cuantas empresas hay con el RNC de la prueba: ninguna, pase lo que pase (la base de la empresa de la prueba no existe). */
$s5bConRnc = static function () use ($s5bMaster, $s5bRnc): int {
    $st = $s5bMaster->prepare('SELECT COUNT(*) FROM tenants WHERE rnc = :r');
    $st->execute([':r' => $s5bRnc]);
    return (int) $st->fetchColumn();
};
/** Repone lo que se quito: la 014 es idempotente y solo agrega las columnas que faltan (en su sitio y con su definicion). */
$s5bRestaurar = static function () use ($s5bMaster): void {
    correrSql($s5bMaster, (string) file_get_contents(EXPRESS_MIGRACION_014));
};
// El alta de la tarea con una base de empresa que no existe (y --skip-create-db): ni siquiera una corrida que no hiciera la comprobacion podria dejar una
// empresa, se detendria al abrir esa base. --db-host=127.0.0.1 --db-port=1 (un puerto de loopback sin nadie) le quita ademas a esa corrida la posibilidad
// de intentar entrar a un MySQL local con las credenciales de la master de prueba.
$s5bArgs = ['tools/create_tenant.php', '--tipo=app', '--nombre=X', "--rnc={$s5bRnc}", '--db-name=zz_no_existe_scratch', '--db-user=u', '--db-pass=p',
    '--skip-create-db', '--db-host=127.0.0.1', '--db-port=1', '--edicion=express', '--tipos-ecf=45'];
$s5bSalidas = [];

try {
    wa_exigir_marca($s5bMaster);   // solo la master scratch
    $s5bRestaurar();               // las cuatro columnas puestas, como las deja express_scratch_preparar()
    wa_chk('precondicion: tenants tiene las cuatro columnas de la 014 y no hay ninguna empresa con el RNC de la prueba',
        array_filter($s5bColumnas, static fn (string $columna): bool => !$s5bTiene($columna)) === [] && $s5bConRnc() === 0, $s5bConRnc());

    // edicion es la de la tarea; cert_vence, la ultima de la 014, es un estado a medias (una migracion que quedo a la mitad): la comprobacion mira las cuatro.
    foreach (['edicion', 'cert_vence'] as $s5bFalta) {
        $s5bMaster->exec("ALTER TABLE tenants DROP COLUMN {$s5bFalta}");
        $r = express_cli($s5bArgs);
        $s5bSalidas[] = $r['salida'];
        wa_chk("(sin tenants.{$s5bFalta}) --edicion=express --tipos-ecf=45 sale con 1, nombra db/master_migrations/014_tenants_edicion.sql y la columna que falta ({$s5bFalta}), no crea ninguna empresa y no deja avisos de PHP",
            !$s5bTiene($s5bFalta) && $r['codigo'] === 1 && str_contains($r['salida'], 'db/master_migrations/014_tenants_edicion.sql')
            && str_contains($r['salida'], "Faltan en tenants: {$s5bFalta}.") && $s5bConRnc() === 0 && !str_contains($r['salida'], 'Tenant creado OK')
            && preg_match('/Fatal error|Stack trace|Warning|Notice|Deprecated/', $r['salida']) !== 1, $s5bVer($r));
        $s5bRestaurar();
    }

    // El control: con las cuatro columnas la misma orden ya no dice nada de la 014 (la comprobacion no se dispara de mas). Se le agrega un --grupo que no
    // existe para que termine limpio, con un mensaje, justo despues de esa comprobacion y sin tocar la base de la empresa.
    $r = express_cli(array_merge($s5bArgs, ['--grupo=2147483647']));
    $s5bSalidas[] = $r['salida'];
    wa_chk('(control) con las cuatro columnas de la 014 la misma orden ya no menciona la migracion: pasa esa comprobacion y se detiene en el grupo que no existe (sale con 1), sin crear ninguna empresa',
        $r['codigo'] === 1 && !str_contains($r['salida'], '014_tenants_edicion.sql') && str_contains($r['salida'], 'no existe un tenant activo con ese id') && $s5bConRnc() === 0, $s5bVer($r));
} finally {
    $s5bRestaurar();   // las cuatro columnas, pase lo que pase (express_scratch_preparar() la repone tambien al terminar el proceso)
}
$s5bDefiniciones = [];
foreach ($s5bMaster->query(
    "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenants' AND COLUMN_NAME IN ('" . implode("','", $s5bColumnas) . "')"
)->fetchAll() as $s5bF) {
    $s5bDefiniciones[$s5bF['COLUMN_NAME']] = [$s5bF['COLUMN_TYPE'], $s5bF['IS_NULLABLE'], $s5bF['COLUMN_DEFAULT']];
}
ksort($s5bDefiniciones);
$s5bEstado = $s5bMaster->query('SELECT id, edicion FROM tenants WHERE id IN (' . (int) $ctx['tenantA'] . ', ' . (int) $ctx['tenantB'] . ') ORDER BY id')->fetchAll();
wa_chk('al terminar tenants tiene otra vez las cuatro columnas de la 014 con su definicion (edicion ENUM NOT NULL DEFAULT completa, cert_vence DATE NULL), A y B siguen completas y no hay ninguna empresa con el RNC de la prueba',
    $s5bDefiniciones === [
        'cert_vence' => ['date', 'YES', null],
        'edicion' => ["enum('completa','express')", 'NO', 'completa'],
        'modulos_habilitados' => ['text', 'YES', null],
        'tipos_ecf_habilitados' => ['varchar(64)', 'YES', null],
    ] && count($s5bEstado) === 2 && array_unique(array_column($s5bEstado, 'edicion')) === ['completa'] && $s5bConRnc() === 0, [$s5bDefiniciones, $s5bEstado]);
$s5bFugas = [];
foreach ($s5bSalidas as $s5bI => $s5bSalida) {
    foreach ($s5bProhibidos as $s5bQue => $s5bValor) {
        if ($s5bValor !== '' && str_contains($s5bSalida, $s5bValor)) {
            $s5bFugas[] = "corrida {$s5bI}: {$s5bQue}";
        }
    }
}
wa_chk('ninguna de las corridas de create_tenant.php imprimio una credencial (la clave ni el usuario de la base, el servidor ni los secretos derivados)',
    count($s5bSalidas) === 3 && $s5bFugas === [], $s5bFugas);

// ---------------------------------------------------------------------------
// Cierre. Cada tarea agrega su seccion justo encima de este bloque.
// ---------------------------------------------------------------------------
wa_fin();
