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
 *
 * Las ayudas de las secciones (una sola familia, que los planes 03 y 04 reutilizan): express_scratch_preparar,
 * express_edicion (deja a A o B con la edicion y las listas dadas), express_api (levanta la API de la rama o de otra
 * raiz), express_login, express_h (la cabecera de sesion) y express_usar_master (apunta MasterDatabase y
 * TenantResolver de ESTE proceso a la master scratch).
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
 * los dos modos. Un php -S que quedo de una corrida interrumpida (Ctrl+C en Windows puede saltarse el cierre) en
 * el modo contrario, o de otro arbol, hace que wa_servidor pare con "[FALLO] el puerto ... ya lo usa otro
 * proceso": detenlo y vuelve a correr.
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
 * El nombre con el que se registra el servidor lleva el modo y el arbol ("{$nombre}_enf|som_<hash de la raiz>"): un php -S que
 * quedo de otra corrida en ese puerto, del otro modo o de otro arbol, no se reutiliza con la configuracion equivocada;
 * wa_servidor se detiene con "[FALLO] el puerto ... ya lo usa otro proceso". La salida del servidor queda en el temp del
 * sistema (wa_<nombre>_<puerto>.log).
 */
function express_api(string $nombre, int $puerto, string $raiz = ''): string
{
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
    $servidor = $nombre . '_' . ($enforce ? 'enf' : 'som') . '_' . substr(md5($arbol), 0, 6);
    return wa_servidor($servidor, 'index.php', $puerto, $env, $raiz);
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
// Cierre. Cada tarea agrega su seccion justo encima de este bloque.
// ---------------------------------------------------------------------------
wa_fin();
