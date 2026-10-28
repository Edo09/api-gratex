<?php
/**
 * write_ahead_test_lib.php — ayudas comunes de las pruebas del write-ahead del
 * e-CF (spec docs/superpowers/specs/2026-10-05-ecf-write-ahead-design.md, 12).
 *
 * Incluirlo solo define funciones: no corre nada, no abre conexiones, no
 * arranca servidores.
 *
 * Como correr las pruebas que lo usan:
 *   php -d extension=curl -d extension=openssl -d extension=pdo_mysql tools/<prueba>.php
 *   - Pon el -d solo de las extensiones que tu php.ini no carga (php -m las
 *     lista); si ya estan cargadas PHP avisa "already loaded" y sigue.
 *   - Si otra aplicacion ya usa 8098/8099, pon WA_PUERTO_OFFSET=100 en el
 *     entorno: los puertos pasan a 8198/8199 (lo aplica wa_servidor).
 *   - Nunca se edita el php.ini global. Los servidores hijos (php -S) reciben
 *     los mismos -d, calculados por wa_php_flags().
 *
 * Salida, igual que tools/test_notas_vinculadas.php: una linea "[OK  ] ..." o
 * "[FALLO] ..." por prueba, al final "N de M pruebas OK", y codigo de salida 1
 * si alguna fallo.
 */

/** Una prueba: imprime [OK  ]/[FALLO] y, si falla, lo obtenido (recortado). */
function wa_chk(string $desc, bool $ok, mixed $obtenido = null): void
{
    $GLOBALS['wa_total'] = ($GLOBALS['wa_total'] ?? 0) + 1;
    if (!$ok) {
        $GLOBALS['wa_fallos'] = ($GLOBALS['wa_fallos'] ?? 0) + 1;
    }
    printf("  [%s] %s\n", $ok ? 'OK  ' : 'FALLO', $desc);
    if (!$ok && $obtenido !== null) {
        $txt = (string) json_encode($obtenido, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        echo '         obtenido: ' . (strlen($txt) > 2000 ? substr($txt, 0, 2000) . '...' : $txt) . "\n";
    }
}

/** Resumen "N de M pruebas OK"; sale con 1 si alguna fallo, 0 si no. */
function wa_fin(): never
{
    $total = $GLOBALS['wa_total'] ?? 0;
    $fallos = $GLOBALS['wa_fallos'] ?? 0;
    echo "\n" . ($total - $fallos) . " de {$total} pruebas OK\n";
    exit($fallos > 0 ? 1 : 0);
}

/** Lee --nombre=valor de la linea de comandos. "--nombre" solo devuelve ''. */
function wa_arg(string $nombre, ?string $defecto = null): ?string
{
    foreach (array_slice($_SERVER['argv'] ?? [], 1) as $a) {
        if ($a === '--' . $nombre) {
            return '';
        }
        if (str_starts_with($a, '--' . $nombre . '=')) {
            return substr($a, strlen($nombre) + 3);
        }
    }
    return $defecto;
}

/**
 * Corre $fn con error_log() a un archivo temporal (se restaura y se borra al
 * terminar): [resultado, excepcion o null, texto del log]. Atrapa Throwable, asi
 * que un Error (metodo no definido) tampoco corta la prueba.
 */
function wa_con_log(callable $fn): array
{
    $archivo = (string) tempnam(sys_get_temp_dir(), 'wa_log_');
    $previo = ini_set('error_log', $archivo);
    $resultado = null;
    $error = null;
    try {
        $resultado = $fn();
    } catch (Throwable $e) {
        $error = $e;
    } finally {
        ini_set('error_log', $previo === false ? '' : $previo);
    }
    $log = (string) file_get_contents($archivo);
    @unlink($archivo);
    return [$resultado, $error, $log];
}

/**
 * Flags -d para un php hijo: solo las extensiones que el php.ini NO carga.
 * Se le pregunta a un php sin flags (lo que vera el hijo), no a este proceso:
 * este pudo arrancar con -d extension=... y el hijo no lo hereda.
 *
 * $extensiones son las que el hijo necesita: por defecto curl, openssl y pdo_mysql
 * (servidores y bases de las pruebas). La linea base de suites (express_baseline.php)
 * pide ademas pdo_sqlite, para que sus conteos no dependan del php.ini de quien la
 * corre. Se le pregunta al php una sola vez por proceso, pida lo que pida cada llamada.
 */
function wa_php_flags(array $extensiones = ['curl', 'openssl', 'pdo_mysql']): array
{
    static $cargadas = null;
    if ($cargadas === null) {
        $cargadas = [];
        $proc = proc_open(
            [PHP_BINARY, '-r', 'echo implode(",", array_map("strtolower", get_loaded_extensions()));'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (is_resource($proc)) {
            $cargadas = explode(',', trim((string) stream_get_contents($pipes[1])));
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
        }
    }
    $flags = [];
    foreach ($extensiones as $ext) {
        if (!in_array($ext, $cargadas, true)) {
            array_push($flags, '-d', 'extension=' . $ext);
        }
    }
    return $flags;
}

/** true si algo escucha en 127.0.0.1:$puerto. */
function wa_puerto_abierto(int $puerto): bool
{
    $f = @fsockopen('127.0.0.1', $puerto, $errno, $errstr, 0.2);
    if ($f === false) {
        return false;
    }
    fclose($f);
    return true;
}

/**
 * Arranca (o reutiliza) un "php -S 127.0.0.1:<puerto> <router>" con la raiz del
 * API como directorio de trabajo. El entorno del hijo es el de este proceso
 * mas $env, y corre con variables_order=EGPCS: Database y MasterDatabase leen
 * solo $_ENV. Su salida va a sys_get_temp_dir()/wa_<nombre>_<puerto>.log.
 * Se detiene solo al terminar este proceso.
 *
 * Un puerto ocupado se reutiliza solo si lo arranco un servidor del mismo
 * $nombre (queda anotado en wa_servidor_<puerto>.txt): asi una API vieja que
 * apunta a otra base nunca se confunde con la que se pidio.
 *
 * Si el entorno trae WA_PUERTO_OFFSET (entero, por defecto 0), se suma al
 * puerto pedido antes de todo lo demas: sirve cuando otra aplicacion de la
 * maquina ya usa un puerto de las pruebas (WA_PUERTO_OFFSET=100 mueve 8099 a
 * 8199). Quien llama debe usar la URL que devuelve, nunca armar el puerto.
 *
 * $raiz es el directorio de trabajo del servidor y de donde se lee $router: por
 * defecto la raiz de este API; tools/test_pos_scratch.php --api-root pasa la de
 * otro checkout (la linea base de origin/master).
 *
 * $flagsPhp son argumentos extra para el php hijo, tal cual (p. ej.
 * ['-d', 'output_buffering=4096']). php -S no bufferiza la salida (output_buffering=0
 * si el php.ini no dice otra cosa): con eso, un "echo ...; http_response_code(405);"
 * deja el estado en 200 y agrega un Warning al cuerpo; con un php.ini de produccion
 * (4096) el estado es 405. tools/express_golden.php lo usa para ver los estados que
 * ve un cliente real.
 *
 * $salidaFallo es el codigo con el que se sale si el servidor no se puede dejar
 * listo (puerto de otro, no lanza, no arranca en 10 s): 1 por defecto, como todas las
 * pruebas. tools/express_golden.php pasa 2, porque alli 1 quiere decir "hay diferencias"
 * (contrato 4.5) y un puerto ocupado no es una regresion.
 */
function wa_servidor(string $nombre, string $router, int $puerto, array $env = [], string $raiz = '', array $flagsPhp = [], int $salidaFallo = 1): string
{
    $puerto += (int) getenv('WA_PUERTO_OFFSET');
    $url = 'http://127.0.0.1:' . $puerto;
    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR;
    $anotado = $tmp . "wa_servidor_{$puerto}.txt";
    if (wa_puerto_abierto($puerto)) {
        $dueno = is_file($anotado) ? trim((string) file_get_contents($anotado)) : '';
        if ($dueno !== $nombre) {
            echo "[FALLO] el puerto {$puerto} ya lo usa otro proceso ('{$dueno}'), no '{$nombre}'. Detenlo y vuelve a correr.\n";
            exit($salidaFallo);
        }
        return $url;
    }
    $log = $tmp . "wa_{$nombre}_{$puerto}.log";
    $cmd = array_merge([PHP_BINARY], wa_php_flags(), $flagsPhp, ['-d', 'variables_order=EGPCS', '-S', "127.0.0.1:{$puerto}", $router]);
    $entorno = array_merge(getenv(), array_map('strval', $env));
    $nulo = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $proc = proc_open($cmd, [0 => ['file', $nulo, 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $raiz !== '' ? $raiz : dirname(__DIR__), $entorno);
    if (!is_resource($proc)) {
        echo "[FALLO] no se pudo lanzar php -S para {$nombre}\n";
        exit($salidaFallo);
    }
    file_put_contents($anotado, $nombre);
    register_shutdown_function(static function () use ($proc, $anotado): void {
        if (is_resource($proc)) {
            proc_terminate($proc);
            proc_close($proc);
        }
        @unlink($anotado);
    });
    for ($i = 0; $i < 100; $i++) {
        if (wa_puerto_abierto($puerto)) {
            return $url;
        }
        usleep(100000);
    }
    echo "[FALLO] {$nombre} no arranco en 127.0.0.1:{$puerto} en 10 s (ver {$log})\n";
    exit($salidaFallo);
}

/**
 * Peticion HTTP con curl. Un $cuerpo array va como JSON. Un fallo de red no
 * lanza: vuelve status 0 y el error de curl en body, para que la prueba lo
 * marque FALLO en vez de cortar todo el script.
 * @return array{status:int, body:string, json:?array}
 */
function wa_http(string $metodo, string $url, mixed $cuerpo = null, array $headers = [], int $timeout = 60): array
{
    if (!function_exists('curl_init')) {
        echo "[FALLO] wa_http necesita curl: corre con -d extension=curl\n";
        exit(1);
    }
    $body = null;
    if (is_array($cuerpo)) {
        $body = json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers[] = 'Content-Type: application/json';
    } elseif ($cuerpo !== null) {
        $body = (string) $cuerpo;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => strtoupper($metodo),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $resp = curl_exec($ch);
    if ($resp === false) {
        return ['status' => 0, 'body' => 'curl: ' . curl_error($ch), 'json' => null];
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $json = json_decode((string) $resp, true);
    return ['status' => $status, 'body' => (string) $resp, 'json' => is_array($json) ? $json : null];
}

/**
 * Cambia el modo del mock DGII. Tambien sincroniza: php -S atiende de a una
 * peticion, asi que esto espera a que termine un "cuelga" en curso.
 */
function wa_mock_modo(string $mockUrl, string $modo): void
{
    $r = wa_http('GET', rtrim($mockUrl, '/') . '/__mock/modo?valor=' . rawurlencode($modo), null, [], 60);
    if ($r['status'] !== 200 || ($r['json']['modo'] ?? null) !== $modo) {
        echo "[FALLO] no se pudo poner el mock en modo {$modo}: HTTP {$r['status']} " . substr($r['body'], 0, 300) . "\n";
        exit(1);
    }
}

/**
 * Peticiones DGII que recibio el mock, en orden: [{hora, metodo, ruta, modo}].
 * Con $limpiar las devuelve y vacia el log.
 */
function wa_mock_log(string $mockUrl, bool $limpiar = false): array
{
    $r = wa_http('GET', rtrim($mockUrl, '/') . '/__mock/log' . ($limpiar ? '?limpiar=1' : ''), null, [], 60);
    return is_array($r['json']) ? $r['json'] : [];
}

// ===========================================================================
// Mitad con base de datos (Task 2). Decision 6 de la spec: las pruebas con
// base corren en una base de PRUEBA (MySQL/MariaDB local o de staging),
// nunca en produccion.
//
// Credenciales: entorno WA_DB_HOST, WA_DB_PORT, WA_DB_USER, WA_DB_PASS,
// WA_DB_PREFIX o el archivo tools/.env (KEY=VALUE), que la regla ".env" de
// .gitignore deja fuera del repo. Nunca se imprimen.
//
// Prefijo de cPanel: cPanel antepone el prefijo de la cuenta a todo nombre de
// base (p. ej. "cuenta_"), y las bases de prueba las crea el usuario ahi. Por
// eso WA_DB_PREFIX (por defecto vacio) se antepone al nombre corto que se usa
// en la linea de comandos y en lo que se imprime: wa_scratch en pantalla es
// <prefijo>wa_scratch en el servidor. REGLA: todo SQL crudo que nombre una base
// (DSN, TABLE_SCHEMA, CREATE DATABASE, ``base``.tabla) debe usar el nombre que
// devuelve wa_base_real(), que es idempotente (acepta el corto o el completo).
//
// Red de seguridad: toda funcion de esta mitad se niega a tocar una base sin
// la tabla marca zz_write_ahead_scratch, y --preparar solo crea o completa
// bases que empiezan con "wa_" (sin el prefijo) y que estan vacias o ya tienen
// la marca. wa_sin_marca es la excepcion: la autoprueba la necesita SIN marca
// (una base con tablas y sin la marca, en miniatura), asi que --preparar nunca
// la marca y la autoprueba se niega a correr si alguien se la puso.
//
// Errores: el mensaje de una excepcion de PDO trae usuario y host. La primera
// llamada a wa_credenciales() instala wa_manejar_excepcion, que imprime solo la
// clase y los codigos (SQLSTATE y errno); wa_conectar calla los warnings de
// mysqlnd (traen el host); wa_usar_base manda a un archivo el error_log de
// Database.php (trae usuario@host). Nada de eso se imprime jamas.
//
// Lo que la base de prueba necesita, ademas de db/tenant_schema.sql:
//   - users y api_tokens en version single-tenant (sin tenant_id ni FK a
//     tenants): con MULTI_TENANT_ENABLED=false el login los lee y escribe en
//     la base del tenant (authModel::loginUser / validateToken).
//   - dgii_provincia_municipio: provinciaMunicipioModel (cada emision) y
//     unidadMedidaModel conectan a la MASTER, y aqui la master es esta misma
//     base (MASTER_DB_*). Puede quedar vacia: resolve() falla abierto.
//   - Database y MasterDatabase leen solo $_ENV: en este proceso se llenan
//     $_ENV y putenv; los php -S hijos corren con variables_order=EGPCS.
//   - PERMISSIONS_ENFORCE=false: PermissionGate solo anota en el log.
//   - Con MULTI_TENANT_ENABLED=false AuditLogger escribe en audit_logs de
//     esta base (AuditLogModel), asi que las pruebas lo pueden leer.
// ===========================================================================

const WA_MARCA = 'zz_write_ahead_scratch';

/** Nombre corto de la base que la autoprueba usa SIN marca (reservado: --preparar no la toca). */
const WA_BASE_SIN_MARCA = 'wa_sin_marca';

/**
 * Manejador de excepciones de la mitad con base: imprime solo la clase, el
 * archivo:linea y los codigos (SQLSTATE y errno de MySQL) de la excepcion y de
 * sus previas. Nunca getMessage() de una \Exception (PDOException incluida) ni
 * la traza: el mensaje del driver trae usuario y host y la traza muestra los
 * primeros caracteres de los argumentos. Los \Error (funcion o metodo no
 * definido, TypeError, Class "PDO" not found) son errores del motor, sin texto
 * del driver: de esos si se imprime el mensaje, para poder diagnosticarlos.
 */
function wa_manejar_excepcion(Throwable $e): never
{
    echo '[FALLO] excepcion sin atrapar: ' . wa_describir_excepcion($e) . "\n";
    exit(1);
}

/**
 * El texto que imprime wa_manejar_excepcion, sin salir: la clase, archivo:linea y los
 * codigos de la excepcion y de sus previas (el mensaje solo de un \Error). Quien necesita
 * otro codigo de salida lo imprime con su propio formato (express_golden.php sale con 2).
 */
function wa_describir_excepcion(Throwable $e): string
{
    $partes = [];
    for ($x = $e; $x !== null; $x = $x->getPrevious()) {
        $txt = get_class($x) . ' (' . basename($x->getFile()) . ':' . $x->getLine() . ')';
        if ($x instanceof PDOException) {
            $info = (array) $x->errorInfo;
            $txt .= $info !== []
                ? ' SQLSTATE ' . ($info[0] ?? '?') . ' errno ' . ($info[1] ?? '?')
                : ' codigo ' . $x->getCode();
        } elseif ($x instanceof \Error) {
            $txt .= ': ' . $x->getMessage();
        }
        $partes[] = $txt;
    }
    return implode(' <- ', $partes);
}

/** Credenciales de la base de prueba (entorno o tools/.env). Nunca se imprimen. */
function wa_credenciales(): array
{
    static $c = null;
    if ($c !== null) {
        return $c;
    }
    set_exception_handler('wa_manejar_excepcion');
    // Sin el driver no hay conexion posible: se avisa antes de leer nada.
    if (!extension_loaded('pdo_mysql')) {
        echo "[FALLO] falta la extension pdo_mysql: corre con php -d extension=pdo_mysql tools/<prueba>.php\n";
        exit(1);
    }
    $archivo = [];
    $ruta = __DIR__ . DIRECTORY_SEPARATOR . '.env';
    if (is_file($ruta)) {
        foreach (file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
            $l = trim($l);
            if ($l === '' || $l[0] === '#' || !str_contains($l, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $l, 2);
            $archivo[trim($k)] = trim(trim($v), "\"'");
        }
    }
    $leer = static function (string $k, string $defecto = '') use ($archivo): string {
        $v = getenv($k);
        return $v !== false && $v !== '' ? $v : (string) ($archivo[$k] ?? $defecto);
    };
    $c = [
        'host' => $leer('WA_DB_HOST', '127.0.0.1'),
        'port' => $leer('WA_DB_PORT', '3306'),
        'user' => $leer('WA_DB_USER'),
        'pass' => $leer('WA_DB_PASS'),
        'prefix' => $leer('WA_DB_PREFIX'),
    ];
    if ($c['user'] === '') {
        echo "[FALLO] faltan las credenciales de la base de prueba: WA_DB_USER/WA_DB_PASS en el entorno o en tools/.env\n";
        exit(1);
    }
    return $c;
}

/** Nombre de base seguro para ir entre backticks. */
function wa_nombre_base(string $base): string
{
    if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $base)) {
        echo "[FALLO] nombre de base invalido: {$base}\n";
        exit(1);
    }
    return $base;
}

/** Nombre corto de la base: sin el prefijo de WA_DB_PREFIX. Es el que se imprime y el de la regla wa_. */
function wa_base_corta(string $base): string
{
    $prefijo = wa_credenciales()['prefix'];
    return $prefijo !== '' && str_starts_with($base, $prefijo) ? substr($base, strlen($prefijo)) : $base;
}

/**
 * Nombre de la base en el servidor: WA_DB_PREFIX + nombre corto, validado.
 * Idempotente: un nombre que ya empieza con el prefijo vuelve igual. Todo SQL
 * crudo que nombre una base pasa por aqui.
 */
function wa_base_real(string $base): string
{
    return wa_nombre_base(wa_credenciales()['prefix'] . wa_nombre_base(wa_base_corta($base)));
}

/** Conexion al servidor de prueba, con o sin base seleccionada (corta o completa). */
function wa_conectar(?string $base = null): PDO
{
    $c = wa_credenciales();
    $dsn = "mysql:host={$c['host']};port={$c['port']};charset=utf8mb4" . ($base !== null ? ';dbname=' . wa_base_real($base) : '');
    // Segun la version de PHP/mysqlnd, un fallo de conexion lanza antes de la
    // excepcion un E_WARNING con el host ("getaddrinfo for <host> failed"): se
    // calla mientras dura el new PDO y se restaura el manejador del llamador.
    set_error_handler(static fn (): bool => true);
    try {
        return new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        // El mensaje del driver puede traer usuario y host: solo el codigo.
        echo "[FALLO] no se pudo conectar a la base de prueba (codigo {$e->getCode()}). Revisa WA_DB_* y que corra con -d extension=pdo_mysql\n";
        exit(1);
    } finally {
        restore_error_handler();
    }
}

/** true si la tabla existe en la base ($base es el nombre real, con prefijo). */
function wa_tabla_existe(PDO $pdo, string $base, string $tabla): bool
{
    $st = $pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = :b AND TABLE_NAME = :t');
    $st->execute([':b' => $base, ':t' => $tabla]);
    return $st->fetchColumn() !== false;
}

/** true cuando $base (nombre real, con prefijo) tiene la tabla marca zz_write_ahead_scratch. */
function wa_es_base_de_prueba(PDO $pdo, string $base): bool
{
    return wa_tabla_existe($pdo, $base, WA_MARCA);
}

/** Corta el script si la conexion no esta sobre una base de prueba. */
function wa_exigir_marca(PDO $pdo): void
{
    $base = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($base === '' || !wa_es_base_de_prueba($pdo, $base)) {
        echo '[FALLO] ' . wa_base_corta($base) . " no es una base de prueba\n";
        exit(1);
    }
}

/** Conexion a la base de prueba ($base o --tenant-db). Sin la marca: sale con 1. */
function wa_pdo(?string $base = null): PDO
{
    $base = $base ?? (string) wa_arg('tenant-db', '');
    if ($base === '') {
        echo "[FALLO] falta --tenant-db=<base de prueba>\n";
        exit(1);
    }
    $real = wa_base_real($base);
    if (!wa_es_base_de_prueba(wa_conectar(), $real)) {
        echo '[FALLO] ' . wa_base_corta($real) . " no es una base de prueba\n";
        exit(1);
    }
    return wa_conectar($real);
}

/**
 * Entorno que apunta los modelos y la API a la base de prueba (nombre real):
 * un solo tenant, sin permisos, certecf, y la master es la misma base.
 */
function wa_env_base(string $baseReal): array
{
    $c = wa_credenciales();
    return [
        'MULTI_TENANT_ENABLED' => 'false', 'PERMISSIONS_ENFORCE' => 'false', 'DGII_ECF_ENVIRONMENT' => 'certecf',
        'DB_HOST' => $c['host'], 'DB_PORT' => $c['port'], 'DB_NAME' => $baseReal, 'DB_USER' => $c['user'], 'DB_PASS' => $c['pass'],
        'MASTER_DB_HOST' => $c['host'], 'MASTER_DB_PORT' => $c['port'], 'MASTER_DB_NAME' => $baseReal,
        'MASTER_DB_USER' => $c['user'], 'MASTER_DB_PASS' => $c['pass'],
    ];
}

/**
 * Apunta los modelos de ESTE proceso a la base de prueba: Database (tenant) y,
 * por $_ENV/putenv, MasterDatabase y la configuracion que leen los modelos.
 */
function wa_usar_base(string $base): PDO
{
    wa_pdo($base);
    $real = wa_base_real($base);
    $c = wa_credenciales();
    foreach (wa_env_base($real) as $k => $v) {
        $_ENV[$k] = $v;
        putenv("{$k}={$v}");
    }
    require_once dirname(__DIR__) . '/src/Database.php';
    Database::setCredentials(['host' => $c['host'], 'port' => $c['port'], 'name' => $real, 'user' => $c['user'], 'pass' => $c['pass']]);
    // Si no conecta, Database hace error_log("(usuario@host/base): mensaje del
    // driver"): en el CLI eso sale por stderr. Se manda a un archivo temporal que
    // se borra; la excepcion que sube la imprime wa_manejar_excepcion sin mensaje.
    $log = tempnam(sys_get_temp_dir(), 'wa_dblog_');
    if ($log === false) {
        echo "[FALLO] no se pudo crear el log temporal de Database\n";
        exit(1);
    }
    // Un hijo con ini_set en disable_functions (S1b de test_write_ahead_ecf.php)
    // tambien pasa por aqui: sin ini_set el error_log queda como estaba.
    $puedeIni = function_exists('ini_set');
    $antes = $puedeIni ? ini_set('error_log', $log) : false;
    try {
        return Database::getInstance()->getConnection();
    } finally {
        if ($puedeIni) {
            ini_set('error_log', $antes === false ? '' : $antes);
        }
        @unlink($log);
    }
}

/** CREATE DATABASE de una base de prueba (nombre real). Si el usuario no puede: solo el codigo y exit 1. */
function wa_crear_base(PDO $srv, string $real): void
{
    try {
        $srv->exec("CREATE DATABASE IF NOT EXISTS `{$real}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (PDOException $e) {
        // En cPanel el usuario de prueba puede no tener CREATE sobre bases nuevas: solo el codigo.
        echo "[FALLO] no se pudo crear {$real} (codigo {$e->getCode()}): creala en cPanel\n";
        exit(1);
    }
}

/**
 * Crea (o completa) una base de prueba: CREATE DATABASE, la marca, el esquema
 * de tenant y las tablas que la API necesita en modo single-tenant. Se niega a
 * tocar una base con tablas y sin la marca (protege una base real) y nunca
 * marca wa_sin_marca (la autoprueba la necesita sin marca). Acepta el nombre
 * corto o el completo; la regla "wa_" va sobre el corto.
 */
function wa_preparar(string $base): void
{
    $corta = wa_base_corta($base);
    wa_nombre_base($corta);
    if (!str_starts_with($corta, 'wa_')) {
        echo "[FALLO] --preparar solo crea bases que empiezan con wa_ (pediste {$corta})\n";
        exit(1);
    }
    $real = wa_base_real($corta);
    $srv = wa_conectar();
    $st = $srv->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = :b');
    $st->execute([':b' => $real]);
    $tablas = (int) $st->fetchColumn();
    if ($tablas > 0 && !wa_es_base_de_prueba($srv, $real)) {
        echo "[FALLO] {$corta} tiene {$tablas} tablas y no es una base de prueba: no se toca\n";
        exit(1);
    }
    // Despues de la guarda de arriba: una wa_sin_marca con tablas y sin marca se
    // rechaza por eso; las que llegan aqui son la vacia o la ya marcada.
    if ($corta === WA_BASE_SIN_MARCA) {
        echo '[FALLO] ' . WA_BASE_SIN_MARCA . " esta reservada para la autoprueba (debe quedar sin marca): --preparar no la toca\n";
        exit(1);
    }
    wa_crear_base($srv, $real);
    $pdo = wa_conectar($real);
    // La marca primero: si la carga del esquema falla a mitad, la base queda
    // marcada y --preparar la puede completar en la siguiente corrida.
    $pdo->exec('CREATE TABLE IF NOT EXISTS ' . WA_MARCA . " (
        creada DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        nota VARCHAR(200) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if ((int) $pdo->query('SELECT COUNT(*) FROM ' . WA_MARCA)->fetchColumn() === 0) {
        $pdo->exec('INSERT INTO ' . WA_MARCA . " (nota) VALUES ('Base de prueba del write-ahead del e-CF. Nunca produccion.')");
    }
    // audit_logs es la ultima tabla de tenant_schema.sql: si existe, el esquema ya cargo.
    if (!wa_tabla_existe($srv, $real, 'audit_logs')) {
        // PDO_MYSQL ejecuta varias sentencias en un exec() (como tools/create_tenant.php).
        $pdo->exec((string) file_get_contents(dirname(__DIR__) . '/db/tenant_schema.sql'));
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(70) NOT NULL,
        last_name VARCHAR(70) NOT NULL,
        email VARCHAR(255) NOT NULL,
        username VARCHAR(50) NOT NULL,
        password VARCHAR(255) NOT NULL,
        role VARCHAR(20) DEFAULT 'user',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_users_email (email),
        UNIQUE KEY uq_users_username (username)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS api_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token_hash VARCHAR(64) NOT NULL,
        created_at DATETIME NOT NULL,
        last_used DATETIME DEFAULT NULL,
        is_active TINYINT(1) DEFAULT 1,
        UNIQUE KEY uq_token_hash (token_hash),
        KEY idx_tokens_user (user_id),
        CONSTRAINT fk_wa_tokens_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS dgii_provincia_municipio (
        codigo CHAR(6) NOT NULL,
        provincia_codigo CHAR(2) NOT NULL,
        descripcion VARCHAR(120) NOT NULL,
        tipo ENUM('PROVINCIA','MUNICIPIO','DISTRITO') NOT NULL,
        PRIMARY KEY (codigo),
        KEY idx_provincia (provincia_codigo),
        KEY idx_tipo (tipo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $faltan = [];
    foreach (['clients', 'warehouses', 'products', 'facturas', 'factura_items', 'ncf_sequences', 'emisor_config',
        'inventory_movements', 'audit_logs', 'unidades_medida', 'users', 'api_tokens', 'dgii_provincia_municipio'] as $t) {
        if (!wa_tabla_existe($srv, $real, $t)) {
            $faltan[] = $t;
        }
    }
    if ($faltan !== []) {
        echo "[FALLO] {$corta} quedo sin estas tablas: " . implode(', ', $faltan) . "\n";
        exit(1);
    }
}

/**
 * Datos fijos de las pruebas (idempotente): emisor id=1, rangos certecf
 * E31-E34, un almacen, el producto 'WA producto' (bien, stock 1000) y el
 * cliente 'WA cliente' con RNC. Los RNC son de mentira (solo ceros y un 1).
 * @return array{client_id:int, product_id:int}
 */
function wa_sembrar(PDO $pdo): array
{
    wa_exigir_marca($pdo);
    $venc = date('Y-m-d', strtotime('+1 year'));
    $pdo->prepare(
        "INSERT INTO emisor_config (id, rnc, razon_social, nombre_comercial, direccion, telefono, correo, fecha_vencimiento_secuencia)
         VALUES (1, '000000000', 'WA EMISOR DE PRUEBA SRL', 'WA Emisor', 'Calle de prueba 1', '809-000-0000', 'wa-emisor@example.com', :venc)
         ON DUPLICATE KEY UPDATE rnc = VALUES(rnc), razon_social = VALUES(razon_social), nombre_comercial = VALUES(nombre_comercial),
             direccion = VALUES(direccion), telefono = VALUES(telefono), correo = VALUES(correo),
             fecha_vencimiento_secuencia = VALUES(fecha_vencimiento_secuencia)"
    )->execute([':venc' => $venc]);
    $rango = $pdo->prepare(
        "INSERT INTO ncf_sequences (type, prefix, current_value, numero_desde, numero_hasta, fecha_vencimiento, description, ambiente)
         VALUES (:t, :p, 0, 1, 99999999, :venc, 'WA prueba', 'certecf')
         ON DUPLICATE KEY UPDATE numero_hasta = VALUES(numero_hasta), fecha_vencimiento = VALUES(fecha_vencimiento)"
    );
    foreach (['E31', 'E32', 'E33', 'E34'] as $tipo) {
        $rango->execute([':t' => $tipo, ':p' => $tipo, ':venc' => $venc]);
    }
    $id = static function (string $sql, array $p) use ($pdo): int {
        $st = $pdo->prepare($sql);
        $st->execute($p);
        return (int) $st->fetchColumn();
    };
    $almacen = $id('SELECT id FROM warehouses WHERE nombre = :n', [':n' => 'WA almacen']);
    if ($almacen === 0) {
        $pdo->prepare("INSERT INTO warehouses (nombre, descripcion) VALUES ('WA almacen', 'Almacen de las pruebas del write-ahead')")->execute();
        $almacen = (int) $pdo->lastInsertId();
    }
    $producto = $id('SELECT id FROM products WHERE sku = :s', [':s' => 'WA-PROD']);
    if ($producto === 0) {
        $pdo->prepare(
            "INSERT INTO products (sku, nombre, warehouse_id, indicador_bien_servicio, indicador_facturacion, precio, costo, unidad_medida, stock)
             VALUES ('WA-PROD', 'WA producto', :w, 1, 1, 1000.00, 400.00, '43', 1000.000)"
        )->execute([':w' => $almacen]);
        $producto = (int) $pdo->lastInsertId();
    }
    $cliente = $id('SELECT id FROM clients WHERE email = :e', [':e' => 'wa-cliente@example.com']);
    if ($cliente === 0) {
        $pdo->prepare(
            "INSERT INTO clients (email, client_name, company_name, rnc, razon_social, direccion, phone_number)
             VALUES ('wa-cliente@example.com', 'WA cliente', 'WA cliente', '000000001', 'WA CLIENTE DE PRUEBA SRL', 'Calle de prueba 2', '')"
        )->execute();
        $cliente = (int) $pdo->lastInsertId();
    }
    return ['client_id' => $cliente, 'product_id' => $producto];
}

/** Deja la base como recien sembrada: sin facturas, movimientos ni auditoria; contadores al inicio. */
function wa_limpiar(PDO $pdo): void
{
    wa_exigir_marca($pdo);
    $pdo->exec('DELETE FROM inventory_movements');
    $pdo->exec('DELETE FROM factura_items');
    $pdo->exec('DELETE FROM facturas');
    $pdo->exec('DELETE FROM audit_logs');
    $pdo->exec('UPDATE ncf_sequences SET current_value = numero_desde - 1');
    $pdo->exec("UPDATE products SET stock = 1000 WHERE sku = 'WA-PROD'");
}

/** current_value del primer rango de $tipo en $ambiente (0 si no hay). */
function wa_contador(PDO $pdo, string $tipo, string $ambiente = 'certecf'): int
{
    $st = $pdo->prepare('SELECT current_value FROM ncf_sequences WHERE type = :t AND ambiente = :a ORDER BY numero_desde LIMIT 1');
    $st->execute([':t' => $tipo, ':a' => $ambiente]);
    return (int) $st->fetchColumn();
}

/** Filas de facturas de un e-NCF (las archivadas se encuentran por no_factura). */
function wa_filas(PDO $pdo, string $eNcf): array
{
    $st = $pdo->prepare(
        'SELECT id, e_ncf, no_factura, estado_dgii, track_id, secuencia_utilizada,
                xml_firmado IS NOT NULL AS tiene_xml, rfce_xml IS NOT NULL AS tiene_rfce,
                respuesta_dgii, ambiente_dgii
         FROM facturas WHERE e_ncf = :x OR no_factura = :y ORDER BY id'
    );
    $st->execute([':x' => $eNcf, ':y' => $eNcf]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Movimientos de inventario de una factura. */
function wa_movimientos(PDO $pdo, int $facturaId): int
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM inventory_movements WHERE referencia_tipo = 'factura' AND referencia_id = :id");
    $st->execute([':id' => $facturaId]);
    return (int) $st->fetchColumn();
}

/**
 * Certificado .p12 autofirmado para firmar en las pruebas, en el temp del
 * sistema (nunca en el repo) con una clave al azar que no se imprime. Se borra
 * al terminar el proceso. En Windows, si OPENSSL_CONF no esta, se usa el
 * extras/ssl/openssl.cnf del PHP (la misma perilla que OPENSSL_CONF de .env.example).
 * @return array{path:string, password:string}
 */
function wa_cert_prueba(): array
{
    static $cert = null;
    if ($cert !== null) {
        return $cert;
    }
    if (!extension_loaded('openssl')) {
        echo "[FALLO] wa_cert_prueba necesita openssl: corre con -d extension=openssl\n";
        exit(1);
    }
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
    $csr = $llave ? openssl_csr_new(['commonName' => 'WA PRUEBA WRITE-AHEAD', 'organizationName' => 'WA PRUEBA', 'countryName' => 'DO'], $llave, $cfg) : false;
    $x509 = ($csr && $llave) ? openssl_csr_sign($csr, null, $llave, 2, $cfg) : false;
    $p12 = '';
    if (!$x509 || !openssl_pkcs12_export($x509, $p12, $llave, $clave)) {
        echo '[FALLO] no se pudo crear el certificado de prueba: ' . (openssl_error_string() ?: 'sin detalle') . "\n";
        exit(1);
    }
    $ruta = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wa_cert_prueba_' . getmypid() . '.p12';
    file_put_contents($ruta, $p12);
    register_shutdown_function(static function () use ($ruta): void {
        @unlink($ruta);
    });
    return $cert = ['path' => $ruta, 'password' => $clave];
}

/**
 * La API real (php -S index.php) sobre la base de prueba, con la DGII falsa:
 * arranca (o reutiliza) el mock en 8099 y la API en $puertoApi (ambos mas
 * WA_PUERTO_OFFSET, ver wa_servidor; las URLs reales van en el resultado), crea el
 * usuario wa_prueba (admin, clave al azar que no se imprime) e inicia sesion.
 * Sale con 1 si no logra iniciar sesion. Si ya hay una API del mismo nombre en
 * el puerto, se reutiliza tal cual y $env no se vuelve a aplicar.
 * $env agrega o cambia variables del hijo, pero NUNCA DB_* ni MASTER_DB_*: esas
 * se aplican al final con la base que paso la guarda de la marca.
 * @return array{api:string, mock:string, token:string}
 */
function wa_api(string $base, array $env = [], int $puertoApi = 8098): array
{
    $pdo = wa_pdo($base);
    $real = wa_base_real($base);
    $cert = wa_cert_prueba();
    $mock = wa_servidor('mock_dgii', 'tools/mock_dgii.php', 8099, ['MOCK_DGII_CUELGA_SEG' => '5']);
    $varsBase = wa_env_base($real);
    $db = array_filter($varsBase, static fn (string $k): bool => preg_match('/^(MASTER_)?DB_/', $k) === 1, ARRAY_FILTER_USE_KEY);
    $entorno = array_merge($varsBase, [
        'DGII_ECF_BASE_URL' => $mock, 'DGII_FC_BASE_URL' => $mock, 'DGII_ECF_TIMEOUT' => '3',
        'DGII_ECF_CERT_PATH' => $cert['path'], 'DGII_ECF_CERT_PASSWORD' => $cert['password'],
    ], $env, $db);
    $api = wa_servidor('api_' . wa_base_corta($base), 'index.php', $puertoApi, $entorno);

    $clave = bin2hex(random_bytes(16));
    $pdo->prepare(
        "INSERT INTO users (name, last_name, email, username, password, role)
         VALUES ('WA', 'Prueba', 'wa_prueba@example.com', 'wa_prueba', :p, 'admin')
         ON DUPLICATE KEY UPDATE password = VALUES(password), role = 'admin'"
    )->execute([':p' => password_hash($clave, PASSWORD_DEFAULT)]);
    $r = wa_http('POST', $api . '/api/auth/login', ['emailOrUsername' => 'wa_prueba', 'password' => $clave]);
    $token = (string) ($r['json']['data']['token'] ?? '');
    if ($token === '') {
        echo "[FALLO] no se pudo iniciar sesion como wa_prueba en {$api}: HTTP {$r['status']} " . substr($r['body'], 0, 300) . "\n";
        exit(1);
    }
    return ['api' => $api, 'mock' => $mock, 'token' => $token];
}

/** Corre un comando (forma array, sin shell). @return array{codigo:int, salida:string} */
function wa_correr(array $cmd): array
{
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, dirname(__DIR__));
    if (!is_resource($proc)) {
        return ['codigo' => -1, 'salida' => 'no se pudo lanzar el proceso'];
    }
    $salida = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    return ['codigo' => proc_close($proc), 'salida' => $salida];
}

/** Autoprueba de la mitad con base: --autoprueba --tenant-db=<base>. */
function wa_autoprueba(string $base): never
{
    echo "Autoprueba de write_ahead_test_lib sobre {$base}\n";
    $pdo = wa_pdo($base);
    $srv = wa_conectar();
    wa_chk("guarda: 'mysql' no es una base de prueba", wa_es_base_de_prueba($srv, 'mysql') === false);

    // Una base con tablas y sin la marca (una base real, en miniatura) no se toca.
    $sinMarca = wa_base_real(WA_BASE_SIN_MARCA);
    // Si alguien la marco, el hijo de abajo pasaria wa_pdo y correria la autoprueba
    // completa, que lanzaria otro hijo igual: se corta antes de tocar nada.
    if (wa_es_base_de_prueba($srv, $sinMarca)) {
        echo '[FALLO] ' . WA_BASE_SIN_MARCA . ' tiene la marca ' . WA_MARCA . " y la autoprueba la necesita sin ella: borra esa tabla de la base (no con --preparar)\n";
        exit(1);
    }
    wa_crear_base($srv, $sinMarca);
    $srv->exec("CREATE TABLE IF NOT EXISTS `{$sinMarca}`.`t` (id INT PRIMARY KEY)");
    $r = wa_correr(array_merge([PHP_BINARY], wa_php_flags(), [__FILE__, '--preparar=' . WA_BASE_SIN_MARCA]));
    wa_chk('--preparar sobre una base con tablas y sin marca sale con 1',
        $r['codigo'] === 1 && str_contains($r['salida'], 'no es una base de prueba'), $r);
    $st = $srv->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '{$sinMarca}'");
    wa_chk('--preparar no le agrego tablas', (int) $st->fetchColumn() === 1);
    // wa_pdo es la guarda de todo lo demas: sobre una base sin marca sale con 1.
    $r = wa_correr(array_merge([PHP_BINARY], wa_php_flags(), [__FILE__, '--autoprueba', '--tenant-db=' . WA_BASE_SIN_MARCA]));
    wa_chk('guarda: wa_pdo se niega a abrir una base sin marca',
        $r['codigo'] === 1 && str_contains($r['salida'], 'no es una base de prueba'), $r);

    $a = wa_sembrar($pdo);
    $b = wa_sembrar($pdo);
    wa_chk('wa_sembrar dos veces da los mismos ids', $a === $b && $a['client_id'] > 0 && $a['product_id'] > 0, [$a, $b]);

    $pdo->exec("INSERT INTO facturas (no_factura, client_name, total, tipo_ecf, e_ncf, estado_dgii, ambiente_dgii)
                VALUES ('E319999999999', 'WA basura', 1.00, '31', 'E319999999999', 'ACEPTADO', 'certecf')");
    $pdo->exec("UPDATE ncf_sequences SET current_value = 5 WHERE type = 'E31' AND ambiente = 'certecf'");
    wa_limpiar($pdo);
    wa_chk('wa_contador E31 vuelve a 0 tras wa_limpiar', wa_contador($pdo, 'E31') === 0, wa_contador($pdo, 'E31'));
    wa_chk('facturas queda vacia tras wa_limpiar', (int) $pdo->query('SELECT COUNT(*) FROM facturas')->fetchColumn() === 0);

    $cert = wa_cert_prueba();
    $partes = [];
    $leido = openssl_pkcs12_read((string) file_get_contents($cert['path']), $partes, $cert['password']);
    wa_chk('wa_cert_prueba: el .p12 se lee con su clave', $leido && !empty($partes['cert']) && !empty($partes['pkey']));
    wa_chk('wa_cert_prueba: el .p12 vive fuera del repo',
        !str_starts_with(realpath($cert['path']) ?: '', (string) realpath(dirname(__DIR__))), $cert['path']);

    // wa_api sale con 1 si no logra iniciar sesion: llegar aqui ya prueba el login.
    $srvApi = wa_api($base);
    wa_mock_log($srvApi['mock'], true);
    $h = ['X-API-KEY: ' . $srvApi['token']];
    $r = wa_http('GET', $srvApi['api'] . '/api/facturas/stats', null, $h);
    wa_chk('GET /api/facturas/stats: 200 con status true', $r['status'] === 200 && ($r['json']['status'] ?? null) === true, $r);
    $r = wa_http('GET', $srvApi['api'] . '/api/facturas?estado=aprobado', null, $h);
    wa_chk('GET /api/facturas?estado=aprobado: 200', $r['status'] === 200, $r);
    wa_chk('nada de eso llamo a la DGII', wa_mock_log($srvApi['mock']) === []);
    wa_fin();
}

// CLI propio: solo cuando se ejecuta este archivo, nunca al incluirlo.
//   php <flags> tools/write_ahead_test_lib.php --preparar=<base>
//   php <flags> tools/write_ahead_test_lib.php --autoprueba --tenant-db=<base>
if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $preparar = (string) wa_arg('preparar', '');
    if ($preparar !== '') {
        wa_preparar($preparar);
        echo "Base de prueba {$preparar} lista.\n";
        exit(0);
    }
    if (wa_arg('autoprueba') !== null) {
        wa_autoprueba((string) wa_arg('tenant-db', ''));
    }
    echo "Uso: php tools/write_ahead_test_lib.php --preparar=<base> | --autoprueba --tenant-db=<base>\n";
    exit(2);
}
