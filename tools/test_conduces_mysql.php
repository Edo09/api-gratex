<?php
/**
 * test_conduces_mysql.php — Conduces contra un MySQL de verdad (la base scratch).
 *
 * tools/test_conduces.php prueba conduceModel con una conexión falsa. Lo que
 * solo un MySQL de verdad puede decir se prueba aquí, en una base de pruebas del
 * mismo servidor que producción (MySQL 8.0, modo estricto, InnoDB):
 *   a) un tenant "viejo" (el snapshot de antes de la 031, con la 029 de precios y la
 *      030 del POS) recibe la 031 dos veces: la primera crea las tres tablas y su
 *      resultado dice todo_ok = SI; la segunda no cambia nada. Antes, la guardia de
 *      su segunda sentencia: sin base de empresa seleccionada (sin base, o
 *      information_schema) o sin cotizaciones, la 031 falla y no crea nada;
 *   b) un tenant nuevo (db/tenant_schema.sql de hoy) nace con las mismas tres
 *      tablas que deja la 031;
 *   c) conduceModel de punta a punta: numeración, eliminar (activo = 0), editar
 *      (las líneas de antes a activo = 0), los 1452 reales y las lecturas;
 *   d) guardados a la vez: un 1062 de verdad que se reintenta, y cinco procesos
 *      que crean un conduce cada uno al mismo tiempo;
 *   e) los topes de cantidad y precio de conduce_items: los de validarForma son los
 *      de las columnas, en el tope se guarda tal cual y lo que pasa de ahí es un
 *      1264 real que conduceModel vuelve un 422;
 *   f) las reglas ON DELETE de las FK, la 031 otra vez con conduces guardados,
 *      y la base queda vacía.
 *
 * SEGURIDAD. Solo usa la base de CONDUCES_DB_NAME, y se niega si el nombre no
 * termina en _scratch o no es de conduces: las otras bases scratch del mismo
 * usuario son de otro trabajo. Borra TODAS las tablas de esa base al empezar y
 * al terminar, también si algo falla en medio. Nunca imprime la contraseña.
 *
 * Configuración: tools/.env (ignorado por git; no es el .env de la app):
 *   WA_DB_HOST, WA_DB_PORT, WA_DB_USER, WA_DB_PASS   el usuario de pruebas
 *   CONDUCES_DB_NAME=smhynzte_conduces_scratch
 *
 * Uso (el PHP local no carga el driver de MySQL por defecto):
 *   php -d extension=pdo_mysql tools/test_conduces_mysql.php
 *   php -d extension=pdo_mysql tools/test_conduces_mysql.php --antes=<rev>
 *     El tenant viejo sale de <rev>:db/tenant_schema.sql. Por defecto es el del
 *     commit que agregó la 030 (030_pos.sql): lo que master tenía antes de los
 *     conduces, con los precios en DECIMAL(18,4) (029) y las tablas del POS (030),
 *     que sigue siendo el de antes aunque la rama ya esté en master.
 * El propio script se lanza con --worker para los guardados simultáneos.
 */

if (!extension_loaded('pdo_mysql')) {
    fwrite(STDERR, "Falta el driver de MySQL. Corre: php -d extension=pdo_mysql tools/test_conduces_mysql.php\n");
    exit(2);
}

$raiz = dirname(__DIR__);
const TABLAS_CONDUCES = ['conduces', 'conduce_items', 'conduce_secuencia'];

/** tools/.env: una clave=valor por línea; # comenta. No toca $_ENV ni putenv: los hijos leen el archivo. */
function envScratch(string $ruta): array
{
    $env = [];
    foreach (is_file($ruta) ? file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $linea) {
        $linea = trim($linea);
        if ($linea === '' || $linea[0] === '#' || !str_contains($linea, '=')) {
            continue;
        }
        [$clave, $valor] = explode('=', $linea, 2);
        $env[trim($clave)] = trim($valor);
    }
    return $env;
}

/**
 * La base de pruebas, o null si no pasa la guarda: tiene que terminar en
 * _scratch (el usuario de pruebas solo llega a esas) y ser de conduces, para no
 * vaciar nunca la scratch de otro trabajo por un .env mal copiado.
 */
function baseScratch(array $env): ?string
{
    $db = (string) ($env['CONDUCES_DB_NAME'] ?? '');
    return preg_match('/^[A-Za-z0-9_]*conduces[A-Za-z0-9_]*_scratch$/', $db) === 1 ? $db : null;
}

/**
 * Conexión con los atributos de src/Database.php (excepciones, FETCH_ASSOC, sin
 * persistentes). Los prepares quedan emulados, el default de PDO_MYSQL, como en
 * la app: el mismo :query repetido de la búsqueda solo funciona así.
 */
function conectarScratch(array $env, string $db): PDO
{
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $env['WA_DB_HOST'] ?? '', $env['WA_DB_PORT'] ?? '3306', $db),
        $env['WA_DB_USER'] ?? '',
        $env['WA_DB_PASS'] ?? '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_PERSISTENT => false]
    );
    // Segunda guarda: la conexión quedó en la base que pasó la primera.
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $db) {
        throw new RuntimeException('La conexión no quedó en la base scratch; no se toca nada.');
    }
    return $pdo;
}

/**
 * Conexión al mismo servidor SIN base por defecto (DATABASE() = NULL) o con una base
 * de sistema (information_schema): lo que ve la 031 cuando phpMyAdmin no tiene
 * seleccionada la base de la empresa. Solo sirve para probar la guardia de la 031,
 * que tiene que detenerse antes de tocar nada.
 */
function conectarSinBaseDeEmpresa(array $env, ?string $baseDeSistema = null): PDO
{
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s;%scharset=utf8mb4', $env['WA_DB_HOST'] ?? '', $env['WA_DB_PORT'] ?? '3306',
            $baseDeSistema === null ? '' : 'dbname=' . $baseDeSistema . ';'),
        $env['WA_DB_USER'] ?? '',
        $env['WA_DB_PASS'] ?? '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_PERSISTENT => false]
    );
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $baseDeSistema) {
        throw new RuntimeException('La conexión sin base de empresa quedó en otra base; no se toca nada.');
    }
    return $pdo;
}

/** conduceModel sin constructor (no abre Database) con la conexión puesta, como $modeloT5 en tools/test_conduces.php. */
function modeloScratch(PDO $pdo): conduceModel
{
    $m = (new ReflectionClass('conduceModel'))->newInstanceWithoutConstructor();
    (new ReflectionProperty('conduceModel', 'conexion'))->setValue($m, $pdo);
    return $m;
}

/** Una línea como la deja FerreteriaConduce (validarForma + catálogo). */
function lineaScratch(?int $productoId, string $descripcion, float $cantidad, float $precio): array
{
    return ['product_id' => $productoId, 'description' => $descripcion, 'quantity' => $cantidad, 'amount' => $precio,
        'unidad_medida' => '43', 'indicador_facturacion' => 1, 'indicador_bien_servicio' => 1];
}

/** 'cot' de un conduce: por defecto, una línea del producto y una libre sin precio. */
function cotScratch(int $clienteId, int $cotizacionId, ?int $productoId, ?string $fecha, ?array $items = null): array
{
    return ['date' => $fecha, 'client_id' => $clienteId, 'cotizacion_id' => $cotizacionId, 'items' => $items ?? [
        lineaScratch($productoId, 'FUNDAS CEMENTO GRIS', 2.0, 935.0),
        lineaScratch(null, 'CORTE DE TUBO', 1.5, 0.0),
    ]];
}

$env = envScratch(__DIR__ . '/.env');
$db = baseScratch($env);
if ($db === null) {
    fwrite(STDERR, "CONDUCES_DB_NAME (tools/.env) tiene que ser una base de conduces terminada en _scratch, "
        . "por ejemplo smhynzte_conduces_scratch. No se toca nada.\n");
    exit(2);
}

require_once __DIR__ . '/../src/Models/conduceModel.php';

// ---------------------------------------------------------------------------
// Modo trabajador: un proceso aparte que crea UN conduce y dice qué le contestó
// conduceModel. Uso interno:
//   --worker <hora de salida (0 = ya)> <client_id> <cotizacion_id> <product_id> <archivo de log>
// ---------------------------------------------------------------------------
if (($argv[1] ?? '') === '--worker') {
    [$salida, $clienteId, $cotizacionId, $productoId, $log] = array_slice($argv, 2, 5) + ['0', '0', '0', '0', ''];
    ini_set('error_log', $log);
    $pdo = conectarScratch($env, $db);
    $salida = (float) $salida;
    $tarde = $salida > 0 && microtime(true) > $salida;
    if ($salida > 0 && !$tarde) {
        time_sleep_until($salida);
    }
    $t0 = microtime(true);
    $r = modeloScratch($pdo)->crear(cotScratch((int) $clienteId, (int) $cotizacionId, (int) $productoId, null), null, 'TRABAJADOR');
    echo json_encode(['r' => $r, 't0' => $t0, 't1' => microtime(true), 'tarde' => $tarde]), "\n";
    exit(0);
}

// ---------------------------------------------------------------------------
// Ayudas
// ---------------------------------------------------------------------------
$fallos = 0;
$total = 0;
$chk = function (string $desc, bool $ok) use (&$fallos, &$total) {
    $total++;
    if (!$ok) {
        $fallos++;
    }
    printf("  [%s] %s\n", $ok ? 'OK  ' : 'FALLA', $desc);
};

/** Lo que imprime git (null si falla), sin pasar por una shell. */
function salidaGit(string $raiz, array $args): ?string
{
    $p = proc_open(array_merge(['git', '-C', $raiz], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) {
        return null;
    }
    $out = (string) stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return proc_close($p) === 0 ? $out : null;
}

/**
 * Corre un archivo SQL entero en una sola llamada, como cuando se pega en la
 * pestaña SQL de phpMyAdmin (PDO_MYSQL acepta varias sentencias con los
 * prepares emulados). nextRowset() pasa por cada sentencia, así que un error en
 * la sentencia N salta como excepción y no se pierde.
 * @return array las filas del último resultado con columnas (el SELECT final de una migración)
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
 * Corre un archivo SQL que tiene que FALLAR y devuelve el error de MySQL que lo
 * detuvo, [código, mensaje]; null si terminó sin error (la guardia no se activó).
 * @return ?array{0:int,1:string}
 */
function errorDeSql(PDO $pdo, string $sql): ?array
{
    try {
        correrSql($pdo, $sql);
        return null;
    } catch (PDOException $e) {
        return [(int) ($e->errorInfo[1] ?? 0), (string) ($e->errorInfo[2] ?? $e->getMessage())];
    }
}

/**
 * ¿Es el error de la guardia de la 031? Sin base de empresa, la guardia consulta
 * `ALTO_elige_la_base_de_la_empresa_en_el_panel`.`x`: MySQL contesta #1049 (la base
 * no existe) a quien tiene permisos globales, como el usuario de phpMyAdmin, y #1142
 * (SELECT denegado en la tabla 'x') al usuario de pruebas, que solo llega a sus bases
 * scratch: revisa permisos antes que existencia. Los dos nombran lo que puso la guardia.
 */
function esErrorDeGuardia(?array $error): bool
{
    return $error !== null
        && (($error[0] === 1049 && str_contains($error[1], 'ALTO_elige_la_base_de_la_empresa_en_el_panel'))
            || ($error[0] === 1142 && str_contains($error[1], "'x'")));
}

function filasDe(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function valorDe(PDO $pdo, string $sql, array $params = []): mixed
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

/** @return string[] tablas y vistas de la base, en orden alfabético */
function tablasDe(PDO $pdo, string $db): array
{
    return array_column(filasDe($pdo,
        'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME', [$db]), 'TABLE_NAME');
}

/** SHOW CREATE TABLE sin el AUTO_INCREMENT=N (depende de las filas) y con los espacios en uno. */
function ddlDe(PDO $pdo, string $tabla): string
{
    $fila = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '``', $tabla) . '`')->fetch(PDO::FETCH_NUM);
    return trim((string) preg_replace(['/ AUTO_INCREMENT=\d+/', '/\s+/'], ['', ' '], (string) ($fila[1] ?? '')));
}

/** @return array<string,string> tabla => ddlDe() */
function ddlsDe(PDO $pdo, array $tablas): array
{
    $out = [];
    foreach ($tablas as $t) {
        $out[$t] = ddlDe($pdo, $t);
    }
    return $out;
}

/** Tablas, vistas, rutinas y eventos que quedan en la base. */
function objetosEn(PDO $pdo, string $db): int
{
    return (int) valorDe($pdo, 'SELECT (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?)
        + (SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ?)
        + (SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA = ?)', [$db, $db, $db]);
}

/** Borra TODAS las tablas y vistas de $db (ya pasó la guarda), sin mirar las FK. */
function vaciarBase(PDO $pdo, string $db): void
{
    $filas = filasDe($pdo, 'SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?', [$db]);
    $nombre = static fn(array $f): string => '`' . str_replace('`', '``', $db) . '`.`' . str_replace('`', '``', $f['TABLE_NAME']) . '`';
    $vistas = array_map($nombre, array_filter($filas, static fn(array $f): bool => $f['TABLE_TYPE'] === 'VIEW'));
    $tablas = array_map($nombre, array_filter($filas, static fn(array $f): bool => $f['TABLE_TYPE'] !== 'VIEW'));
    if ($vistas) {
        $pdo->exec('DROP VIEW ' . implode(', ', $vistas));
    }
    if ($tablas) {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $pdo->exec('DROP TABLE ' . implode(', ', $tablas));
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}

/** El PHP de los trabajadores: este mismo, con el driver de MySQL si no lo trae su php.ini. */
function phpTrabajador(): array
{
    $p = proc_open([PHP_BINARY, '-r', "exit(extension_loaded('pdo_mysql') ? 0 : 3);"], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return proc_close($p) === 0 ? [PHP_BINARY] : [PHP_BINARY, '-d', 'extension=pdo_mysql'];
}

/** Lanza un trabajador (no espera). */
function lanzarTrabajador(array $php, array $args): array
{
    $proc = proc_open(array_merge($php, [__FILE__, '--worker'], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    return [$proc, $pipes];
}

/** Espera a un trabajador: su código de salida, su JSON y su stderr. */
function esperarTrabajador(array $t): array
{
    [$proc, $pipes] = $t;
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $codigo = proc_close($proc);
    $lineas = array_values(array_filter(array_map('trim', explode("\n", $out)), static fn(string $l): bool => $l !== ''));
    $json = $lineas ? json_decode((string) end($lineas), true) : null;
    if ($codigo !== 0 || !is_array($json) || trim($err) !== '') {
        echo '         trabajador: salida ' . $codigo . ', ' . substr(trim($out . ' ' . $err), 0, 400) . "\n";
    }
    return ['codigo' => $codigo, 'json' => is_array($json) ? $json : null, 'err' => $err];
}

/** ¿La respuesta de crear() es un éxito con ese número? */
function esCreado(mixed $r, int $numero): bool
{
    return is_array($r) && ($r[0] ?? null) === 'success' && is_int($r[1]['id'] ?? null) && $r[1]['id'] > 0
        && ($r[1]['numero'] ?? null) === $numero && ($r[1]['code'] ?? null) === FerreteriaConduce::codigo($numero);
}

// ---------------------------------------------------------------------------
// La guarda (sin conectar), la conexión y la base vacía
// ---------------------------------------------------------------------------
echo "== Guarda de la base ==\n";
$pasa = static fn(string $nombre): bool => baseScratch(['CONDUCES_DB_NAME' => $nombre]) === $nombre;
$chk('acepta smhynzte_conduces_scratch', $pasa('smhynzte_conduces_scratch'));
$chk('rechaza las scratch de otro trabajo (smhynzte_wa_scratch, smhynzte_wa_scratch_sin_gate, smhynzte_wa_sin_marca)',
    !$pasa('smhynzte_wa_scratch') && !$pasa('smhynzte_wa_scratch_sin_gate') && !$pasa('smhynzte_wa_sin_marca'));
$chk('rechaza producción y lo que no termina en _scratch (smhynzte_002, smhynzte_new_gratexdb, smhynzte_master_gratex, smhynzte_conduces, vacío)',
    !$pasa('smhynzte_002') && !$pasa('smhynzte_new_gratexdb') && !$pasa('smhynzte_master_gratex') && !$pasa('smhynzte_conduces')
    && baseScratch([]) === null);
$chk('rechaza nombres con otros caracteres (comillas, punto, espacio)', !$pasa('x`.conduces_scratch') && !$pasa('a.conduces_scratch')
    && !$pasa('smhynzte_conduces_scratch; DROP') && !$pasa(' smhynzte_conduces_scratch'));

try {
    $pdo = conectarScratch($env, $db);
} catch (Throwable $e) {
    fwrite(STDERR, "No se pudo conectar a la base scratch {$db}: " . $e->getMessage() . "\n");
    exit(1);
}
$version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
echo "\nBase: {$db} (MySQL {$version})\n";

$logModelo = (string) tempnam(sys_get_temp_dir(), 'conduces_mysql');
$logsTrabajadores = [];
$otra = null;   // la segunda conexión de d)

try {
    echo "\n== Base vacía ==\n";
    vaciarBase($pdo, $db);
    $chk("{$db}: sin tablas al empezar (borradas las que hubiera)", objetosEn($pdo, $db) === 0);

    // La guardia de la 031 (segunda sentencia). En una base sin cotizaciones, como esta
    // vacía, falla con #1146 y no crea nada: antes la 031 seguía y decía todo_ok = NO.
    $mig031 = (string) file_get_contents($raiz . '/db/migrations/031_conduces.sql');
    $errorVacia = errorDeSql($pdo, $mig031);
    $chk('031 en una base sin cotizaciones (esta, vacía): la guardia falla con #1146 y la base sigue sin nada',
        $errorVacia !== null && $errorVacia[0] === 1146 && str_contains($errorVacia[1], "{$db}.cotizaciones")
        && objetosEn($pdo, $db) === 0);

    // -----------------------------------------------------------------------
    // a) Tenant viejo + la 031 dos veces
    // -----------------------------------------------------------------------
    echo "\n== a) Tenant viejo (snapshot de antes de la 031, con la 029 de precios y la 030 del POS) y la 031 dos veces ==\n";
    $revAntes = null;
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--antes=')) {
            $revAntes = substr($arg, strlen('--antes='));
        }
    }
    if ($revAntes === null) {
        // Un tenant "de antes de la 031" es uno al día hasta la 030 (POS) y sin
        // conduces: el snapshot del commit que agregó 030_pos.sql, que ya está en
        // master y trae también la 029 (precios). El padre del commit de conduces
        // ya no sirve: es anterior a la 029 y a la 030.
        $commitPos = trim((string) salidaGit($raiz, ['log', '--diff-filter=A', '--format=%H', '-1', '--', 'db/migrations/030_pos.sql']));
        $revAntes = $commitPos !== '' ? substr($commitPos, 0, 12) : 'master';
    }
    $snapshotAntes = salidaGit($raiz, ['show', $revAntes . ':db/tenant_schema.sql']);
    $chk("el snapshot de {$revAntes} se lee y no trae las tablas de conduces",
        $snapshotAntes !== null && $snapshotAntes !== '' && !str_contains($snapshotAntes, 'CREATE TABLE IF NOT EXISTS conduce'));
    correrSql($pdo, (string) $snapshotAntes);
    $tablasViejo = tablasDe($pdo, $db);
    // La 029 de precios y la 030 del POS ya están en ese tenant: products.precio .. precio_4 en
    // decimal(18,4) y las seis tablas del POS (la 031 no depende de ninguna de las dos).
    $preciosViejo = (int) valorDe($pdo,
        "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'products'"
        . " AND COLUMN_NAME IN ('precio', 'precio_2', 'precio_3', 'precio_4') AND COLUMN_TYPE LIKE 'decimal(18,4)%'", [$db]);
    $tablasPos = ['pos_cajas', 'pos_empleados', 'pos_sesiones', 'pos_turnos', 'pos_caja_movimientos', 'product_barcodes'];
    $chk('tenant viejo: ' . count($tablasViejo) . ' tablas, con cotizaciones y products, la 029 de precios (decimal(18,4)), la 030 del POS (6 tablas) y sin las de conduces',
        in_array('cotizaciones', $tablasViejo, true) && in_array('products', $tablasViejo, true)
        && $preciosViejo === 4 && array_diff($tablasPos, $tablasViejo) === []
        && array_intersect(TABLAS_CONDUCES, $tablasViejo) === []);
    $ddlViejo = ddlsDe($pdo, $tablasViejo);

    // La guardia con el tenant viejo ya cargado: sin base de empresa seleccionada (NULL, o
    // information_schema, donde deja phpMyAdmin después de otra migración) la 031 se
    // detiene en su segunda sentencia, antes de crear nada: las mismas tablas, el mismo
    // SHOW CREATE TABLE y sin las de conduces. Sin la guardia llegaba al final con todo_ok = NO.
    foreach ([[null, 'sin base seleccionada (DATABASE() = NULL)'], ['information_schema', 'con information_schema seleccionada']] as [$sistema, $cual]) {
        $sinBase = conectarSinBaseDeEmpresa($env, $sistema);
        $errorGuardia = errorDeSql($sinBase, $mig031);
        $sinBase = null;
        $chk("031 {$cual}: la guardia falla (#" . ($errorGuardia[0] ?? '?') . ') y el tenant viejo queda igual, sin tablas de conduces',
            esErrorDeGuardia($errorGuardia) && tablasDe($pdo, $db) === $tablasViejo && ddlsDe($pdo, $tablasViejo) === $ddlViejo);
    }

    $r1 = correrSql($pdo, $mig031);
    $f1 = $r1[0] ?? [];
    echo '         resultado: ' . json_encode($f1, JSON_UNESCAPED_UNICODE) . "\n";
    $chk('031, 1.ª corrida: el resultado es una fila con las columnas de COMO CORRERLA', count($r1) === 1 && array_keys($f1) === [
        'base', 'motor_cotizaciones', 'motor_products', 'tipo_cotizaciones_id', 'tipo_products_id', 'conduces',
        'conduce_items', 'conduce_secuencia', 'indices', 'fk_conduces_cotizacion', 'fk_conduce_items_conduce',
        'fk_conduce_items_product', 'fila_secuencia', 'todo_ok',
    ]);
    $chk("031: base = {$db}", ($f1['base'] ?? null) === $db);
    $chk('031: cotizaciones, products, conduces, conduce_items y conduce_secuencia son InnoDB',
        [$f1['motor_cotizaciones'] ?? null, $f1['motor_products'] ?? null, $f1['conduces'] ?? null,
            $f1['conduce_items'] ?? null, $f1['conduce_secuencia'] ?? null] === array_fill(0, 5, 'InnoDB'));
    $tipo = static fn(string $tabla, string $columna): ?string => ($v = valorDe($pdo,
        'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [$db, $tabla, $columna])) === false ? null : (string) $v;
    $chk('031: cotizacion_id y product_id copian el tipo de cotizaciones.id y products.id ('
        . ($f1['tipo_cotizaciones_id'] ?? '?') . ' / ' . ($f1['tipo_products_id'] ?? '?') . ')',
        $tipo('conduces', 'cotizacion_id') === $tipo('cotizaciones', 'id') && $tipo('cotizaciones', 'id') === ($f1['tipo_cotizaciones_id'] ?? null)
        && $tipo('conduce_items', 'product_id') === $tipo('products', 'id') && $tipo('products', 'id') === ($f1['tipo_products_id'] ?? null));
    $chk('031: indices = 6', (int) ($f1['indices'] ?? 0) === 6);
    $chk('031: ON DELETE de las FK: SET NULL (cotizacion), RESTRICT (conduce), SET NULL (producto)',
        [$f1['fk_conduces_cotizacion'] ?? null, $f1['fk_conduce_items_conduce'] ?? null, $f1['fk_conduce_items_product'] ?? null]
        === ['SET NULL', 'RESTRICT', 'SET NULL']);
    $chk('031: fila_secuencia = (1, 0) y todo_ok = SI', ($f1['fila_secuencia'] ?? null) === '(1, 0)' && ($f1['todo_ok'] ?? null) === 'SI');
    $chk('031: las ' . count($ddlViejo) . ' tablas que ya estaban siguen iguales (SHOW CREATE TABLE)', ddlsDe($pdo, $tablasViejo) === $ddlViejo);
    $tablasMigrado = tablasDe($pdo, $db);
    $ddlMigrado = ddlsDe($pdo, TABLAS_CONDUCES);
    $secuencia = static fn(): array => filasDe($pdo, 'SELECT id, ultimo FROM conduce_secuencia ORDER BY id');
    $chk('031: conduce_secuencia tiene una sola fila, (1, 0)', $secuencia() === [['id' => 1, 'ultimo' => 0]]);

    $r2 = correrSql($pdo, $mig031);
    $chk('031, 2.ª corrida: el mismo resultado', $r2 === $r1);
    $chk('031, 2.ª corrida: no cambia nada (las mismas tablas, el mismo SHOW CREATE TABLE de las tres, la secuencia igual)',
        tablasDe($pdo, $db) === $tablasMigrado && ddlsDe($pdo, TABLAS_CONDUCES) === $ddlMigrado
        && $secuencia() === [['id' => 1, 'ultimo' => 0]]);

    // -----------------------------------------------------------------------
    // b) Tenant nuevo desde el snapshot de hoy
    // -----------------------------------------------------------------------
    echo "\n== b) Tenant nuevo (db/tenant_schema.sql de esta rama) ==\n";
    vaciarBase($pdo, $db);
    $chk('la base queda vacía entre un tenant y otro', objetosEn($pdo, $db) === 0);
    correrSql($pdo, (string) file_get_contents($raiz . '/db/tenant_schema.sql'));
    $tablasNuevo = tablasDe($pdo, $db);
    $chk('tenant nuevo: ' . count($tablasNuevo) . ' tablas, con conduces, conduce_items y conduce_secuencia',
        array_diff(TABLAS_CONDUCES, $tablasNuevo) === []);
    foreach (TABLAS_CONDUCES as $t) {
        $chk("tenant nuevo: {$t} es igual a la que deja la 031 (SHOW CREATE TABLE)", ddlDe($pdo, $t) === ($ddlMigrado[$t] ?? null));
    }
    $chk('tenant nuevo: la secuencia nace con (1, 0)', $secuencia() === [['id' => 1, 'ultimo' => 0]]);
    $r3 = correrSql($pdo, $mig031);
    $chk('la 031 sobre el tenant nuevo: todo_ok = SI y no cambia nada',
        ($r3[0]['todo_ok'] ?? null) === 'SI' && tablasDe($pdo, $db) === $tablasNuevo
        && ddlsDe($pdo, TABLAS_CONDUCES) === $ddlMigrado && $secuencia() === [['id' => 1, 'ultimo' => 0]]);

    // -----------------------------------------------------------------------
    // c) conduceModel contra MySQL (sobre el tenant nuevo)
    // -----------------------------------------------------------------------
    echo "\n== c) conduceModel contra MySQL ==\n";
    $logPrevio = ini_set('error_log', $logModelo);   // los error_log esperados (1452) van aquí
    $almacenId = (int) valorDe($pdo, 'SELECT id FROM warehouses ORDER BY id LIMIT 1');
    $pdo->prepare('INSERT INTO clients (email, client_name, company_name, rnc, phone_number) VALUES (?, ?, ?, ?, ?)')
        ->execute(['conduces@example.invalid', 'JUAN PEREZ', 'HOSPITAL DOCENTE', '401515131', '']);
    $clienteId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO products (nombre, warehouse_id, indicador_bien_servicio, indicador_facturacion, precio, unidad_medida, stock)
                   VALUES (?, ?, 1, 1, 935.00, ?, 100)')->execute(['FUNDAS CEMENTO GRIS', $almacenId, '43']);
    $productoId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO cotizaciones (code, formato, numero, date, client_id, client_name, subtotal, itbis, total)
                   VALUES ('COT-000012', 'ferreteria', 12, '2026-10-05 09:00:00', ?, 'HOSPITAL DOCENTE', 1870.00, 336.60, 2206.60)")
        ->execute([$clienteId]);
    $cotId = (int) $pdo->lastInsertId();
    $chk('semillas: un cliente, un producto y una cotización de Ferretería (COT-000012)',
        $almacenId > 0 && $clienteId > 0 && $productoId > 0 && $cotId > 0);

    $m = modeloScratch($pdo);
    $r = $m->crear(cotScratch($clienteId, $cotId, $productoId, '2026-10-05 09:30:00'), 5, 'HOSPITAL DOCENTE');
    $id1 = (int) ($r[1]['id'] ?? 0);
    $chk('crear: CON-000001, número 1', esCreado($r, 1));
    $chk('crear: la cabecera guardada (fecha del cuerpo, cotización, cliente, nombre guardado, user_id, activo = 1)',
        filasDe($pdo, 'SELECT numero, code, date, cotizacion_id, client_id, client_name, user_id, activo, updated_at FROM conduces WHERE id = ?', [$id1])
        === [['numero' => 1, 'code' => 'CON-000001', 'date' => '2026-10-05 09:30:00', 'cotizacion_id' => $cotId,
            'client_id' => $clienteId, 'client_name' => 'HOSPITAL DOCENTE', 'user_id' => 5, 'activo' => 1, 'updated_at' => null]]);
    $lineasDe = static fn(int $id): array => filasDe($pdo,
        'SELECT product_id, description, quantity, unidad_medida, amount, indicador_facturacion, indicador_bien_servicio, activo
           FROM conduce_items WHERE conduce_id = ? ORDER BY id', [$id]);
    $chk('crear: las dos líneas, la libre con product_id NULL y precio 0 (los DECIMAL vuelven como texto)', $lineasDe($id1) === [
        ['product_id' => $productoId, 'description' => 'FUNDAS CEMENTO GRIS', 'quantity' => '2.000', 'unidad_medida' => '43',
            'amount' => '935.0000', 'indicador_facturacion' => 1, 'indicador_bien_servicio' => 1, 'activo' => 1],
        ['product_id' => null, 'description' => 'CORTE DE TUBO', 'quantity' => '1.500', 'unidad_medida' => '43',
            'amount' => '0.0000', 'indicador_facturacion' => 1, 'indicador_bien_servicio' => 1, 'activo' => 1],
    ]);
    $ultimo = static fn(): int => (int) valorDe($pdo, 'SELECT ultimo FROM conduce_secuencia WHERE id = 1');
    $chk('crear: conduce_secuencia queda en 1', $ultimo() === 1);

    $chk('desactivar: "Conduce eliminado"', $m->desactivar($id1) === ['success', 'Conduce eliminado']);
    $chk('desactivar: la fila sigue, con activo = 0 y updated_at; sus dos líneas no cambian',
        filasDe($pdo, 'SELECT activo, updated_at IS NOT NULL AS tocado FROM conduces WHERE id = ?', [$id1]) === [['activo' => 0, 'tocado' => 1]]
        && array_column($lineasDe($id1), 'activo') === [1, 1]);
    $chk('desactivar otra vez => 404 MSG_NO_EXISTE', $m->desactivar($id1) === ['error', FerreteriaConduce::MSG_NO_EXISTE, 404]);
    $chk('desactivar un id que no existe => 404 MSG_NO_EXISTE', $m->desactivar(999999) === ['error', FerreteriaConduce::MSG_NO_EXISTE, 404]);

    $r = $m->crear(cotScratch($clienteId, $cotId, $productoId, null), 7, 'HOSPITAL DOCENTE');
    $id2 = (int) ($r[1]['id'] ?? 0);
    $chk('crear después de eliminar: CON-000002 (el 1 no se vuelve a usar)', esCreado($r, 2) && $ultimo() === 2);
    $fecha2 = (string) valorDe($pdo, 'SELECT date FROM conduces WHERE id = ?', [$id2]);
    $chk("crear sin fecha: la hora de Santo Domingo de ahora ({$fecha2})",
        preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $fecha2) === 1
        && abs(strtotime($fecha2) - strtotime(FerreteriaFormato::ahoraRd())) < 120);

    $lineasNuevas = [lineaScratch($productoId, 'FUNDAS CEMENTO GRIS', 3.0, 935.0), lineaScratch(null, 'ARENA', 4.0, 0.0)];
    $idsAntes = array_column(filasDe($pdo, 'SELECT id FROM conduce_items WHERE conduce_id = ? ORDER BY id', [$id2]), 'id');
    $r = $m->actualizar($id2, cotScratch($clienteId, $cotId, $productoId, null, $lineasNuevas), 9, 'NOMBRE NUEVO');
    $chk('actualizar: el mismo id, número y code', $r === ['success', ['id' => $id2, 'code' => 'CON-000002', 'numero' => 2]]);
    $cab = filasDe($pdo, 'SELECT numero, code, date, cotizacion_id, client_name, user_id, updated_at IS NOT NULL AS tocado FROM conduces WHERE id = ?', [$id2])[0] ?? [];
    $chk('actualizar sin fecha: conserva la fecha y la hora guardadas (COALESCE)', ($cab['date'] ?? null) === $fecha2);
    $chk('actualizar: nombre guardado, user_id y updated_at nuevos; numero, code y cotizacion_id no cambian',
        $cab === ['numero' => 2, 'code' => 'CON-000002', 'date' => $fecha2, 'cotizacion_id' => $cotId,
            'client_name' => 'NOMBRE NUEVO', 'user_id' => 9, 'tocado' => 1]);
    $todas = filasDe($pdo, 'SELECT id, description, quantity, activo FROM conduce_items WHERE conduce_id = ? ORDER BY id', [$id2]);
    $chk('actualizar: las 2 líneas de antes quedan con activo = 0 y las 2 nuevas con activo = 1 (nada se borra)',
        count($idsAntes) === 2 && count($todas) === 4
        && array_column(array_slice($todas, 0, 2), 'id') === $idsAntes && array_column($todas, 'activo') === [0, 0, 1, 1]
        && array_column(array_slice($todas, 2), 'description') === ['FUNDAS CEMENTO GRIS', 'ARENA']
        && array_column(array_slice($todas, 2), 'quantity') === ['3.000', '4.000']);
    $r = $m->actualizar($id2, cotScratch($clienteId, $cotId, $productoId, '2026-10-06 08:00:00', $lineasNuevas), 9, 'NOMBRE NUEVO');
    $chk('actualizar con fecha: guarda la del cuerpo (y otra vez las líneas: 6 filas, 2 activas)', ($r[0] ?? null) === 'success'
        && valorDe($pdo, 'SELECT date FROM conduces WHERE id = ?', [$id2]) === '2026-10-06 08:00:00'
        && (int) valorDe($pdo, 'SELECT COUNT(*) FROM conduce_items WHERE conduce_id = ?', [$id2]) === 6
        && (int) valorDe($pdo, 'SELECT COUNT(*) FROM conduce_items WHERE conduce_id = ? AND activo = 1', [$id2]) === 2);

    $cuentas = static fn(): array => [(int) valorDe($pdo, 'SELECT COUNT(*) FROM conduces'),
        (int) valorDe($pdo, 'SELECT COUNT(*) FROM conduce_items'), $ultimo()];
    $antes = $cuentas();
    $r = $m->crear(cotScratch($clienteId, $cotId, null, null, [lineaScratch(999999, 'NO EXISTE', 1.0, 10.0)]), 5, 'X');
    $chk('crear con un producto que no existe: el 1452 real (conduce_items_product_fk) => 422 MSG_PRODUCTO_FK',
        $r === ['error', FerreteriaConduce::MSG_PRODUCTO_FK, 422]);
    $chk('... y el rollback no deja nada: ni cabecera, ni líneas, ni la secuencia adelantada', $cuentas() === $antes);
    $activas = filasDe($pdo, 'SELECT id FROM conduce_items WHERE conduce_id = ? AND activo = 1 ORDER BY id', [$id2]);
    $r = $m->actualizar($id2, cotScratch($clienteId, $cotId, null, '2026-10-01 07:00:00', [lineaScratch(999999, 'NO EXISTE', 1.0, 10.0)]), 9, 'OTRO');
    $chk('actualizar con un producto que no existe => 422 MSG_PRODUCTO_FK; el rollback deja las líneas activas, la fecha y el nombre como estaban',
        $r === ['error', FerreteriaConduce::MSG_PRODUCTO_FK, 422]
        && filasDe($pdo, 'SELECT id FROM conduce_items WHERE conduce_id = ? AND activo = 1 ORDER BY id', [$id2]) === $activas
        && filasDe($pdo, 'SELECT date, client_name FROM conduces WHERE id = ?', [$id2]) === [['date' => '2026-10-06 08:00:00', 'client_name' => 'NOMBRE NUEVO']]);
    $r = $m->crear(cotScratch($clienteId, 999999, $productoId, null), 5, 'X');
    $chk('crear con una cotización que no existe: el 1452 real (conduces_cotizacion_fk) => 422 MSG_COTIZACION_FK, sin dejar nada',
        $r === ['error', FerreteriaConduce::MSG_COTIZACION_FK, 422] && $cuentas() === $antes);
    clearstatcache();
    $logTxt = (string) file_get_contents($logModelo);
    $chk('el error_log trae el detalle de MySQL: tres 1452 con el nombre de su FK',
        substr_count($logTxt, ' 1452 ') === 3 && substr_count($logTxt, 'conduce_items_product_fk') === 2
        && substr_count($logTxt, 'conduces_cotizacion_fk') === 1);

    // Una fila que la secuencia no conoce (puesta a mano, inactiva): MAX(numero)
    // cuenta todas las filas, así que su número tampoco se repite.
    $pdo->exec("INSERT INTO conduces (numero, code, date, activo) VALUES (50, 'CON-000050', '2026-10-01 10:00:00', 0)");
    $r = $m->crear(cotScratch($clienteId, $cotId, $productoId, '2026-10-04 10:00:00'), 5, 'HOSPITAL DOCENTE');
    $id51 = (int) ($r[1]['id'] ?? 0);
    $chk('con un CON-000050 inactivo puesto a mano (la secuencia en 2): sale CON-000051 y la secuencia queda en 51',
        esCreado($r, 51) && $ultimo() === 51);

    $columnasFila = ['id', 'numero', 'code', 'date', 'cotizacion_id', 'cotizacion_code', 'client_id', 'client_name', 'company_name',
        'rnc', 'client_name_guardado', 'user_id', 'activo', 'created_at', 'updated_at', 'items'];
    $columnasLinea = ['id', 'conduce_id', 'product_id', 'description', 'quantity', 'unidad_medida', 'amount',
        'indicador_facturacion', 'indicador_bien_servicio', 'activo'];
    $fila = $m->obtener($id2) ?? [];
    $chk('obtener: las columnas de la spec 4.1 en orden, y las de cada línea', array_keys($fila) === $columnasFila
        && array_keys($fila['items'][0] ?? []) === $columnasLinea);
    $chk('obtener: cotizacion_code, el cliente de hoy (JOIN) y el nombre guardado aparte',
        ($fila['cotizacion_code'] ?? null) === 'COT-000012' && ($fila['client_name'] ?? null) === 'JUAN PEREZ'
        && ($fila['company_name'] ?? null) === 'HOSPITAL DOCENTE' && ($fila['rnc'] ?? null) === '401515131'
        && ($fila['client_name_guardado'] ?? null) === 'NOMBRE NUEVO' && ($fila['id'] ?? null) === $id2);
    $itemsFila = $fila['items'] ?? [];
    $chk('obtener: solo las 2 líneas activas (de 6), en orden de id, con los DECIMAL como texto',
        array_column($itemsFila, 'description') === ['FUNDAS CEMENTO GRIS', 'ARENA']
        && array_column($itemsFila, 'quantity') === ['3.000', '4.000'] && array_column($itemsFila, 'amount') === ['935.0000', '0.0000']
        && array_column($itemsFila, 'activo') === [1, 1] && ($itemsFila[0]['id'] ?? 0) < ($itemsFila[1]['id'] ?? 0));
    $chk('obtener de un conduce eliminado, o de uno que no existe => null', $m->obtener($id1) === null && $m->obtener(999999) === null);

    $codigos = static fn(array $filas): array => array_column($filas, 'code');
    $lista = $m->listar(0, 10, null);
    $chk('listar: solo los activos, la fecha más nueva primero (CON-000002 del 06/10, CON-000051 del 04/10)',
        $codigos($lista) === ['CON-000002', 'CON-000051']);
    $chk('listar: cada uno con sus 2 líneas activas', array_map(static fn(array $f): int => count($f['items']), $lista) === [2, 2]);
    $chk('contar: 2 activos (de 4 filas)', $m->contar(null) === 2 && (int) valorDe($pdo, 'SELECT COUNT(*) FROM conduces') === 4);
    $chk('listar de a uno: LIMIT y OFFSET enteros dan la página 1 y la 2',
        $codigos($m->listar(0, 1, null)) === ['CON-000002'] && $codigos($m->listar(1, 1, null)) === ['CON-000051']);
    $chk('buscar por número: el mismo :query cinco veces funciona con los prepares de la app',
        $codigos($m->listar(0, 10, 'CON-000002')) === ['CON-000002'] && $m->contar('CON-000002') === 1);
    $chk('buscar por el nombre guardado, por el nombre del cliente (sin mayúsculas) y por el RNC',
        $codigos($m->listar(0, 10, 'NOMBRE NUEVO')) === ['CON-000002']
        && $codigos($m->listar(0, 10, 'juan')) === ['CON-000002', 'CON-000051'] && $m->contar('4015151') === 2);
    $chk('buscar un conduce eliminado (CON-000001) no lo encuentra', $m->listar(0, 10, 'CON-000001') === [] && $m->contar('CON-000001') === 0);
    $chk('cotizacionDeOrigen: id, code y formato; null si no existe',
        $m->cotizacionDeOrigen($cotId) === ['id' => $cotId, 'code' => 'COT-000012', 'formato' => 'ferreteria']
        && $m->cotizacionDeOrigen(999999) === null);
    ini_set('error_log', $logPrevio === false ? '' : $logPrevio);

    // -----------------------------------------------------------------------
    // d) Guardados a la vez (procesos aparte)
    // -----------------------------------------------------------------------
    echo "\n== d) Guardados a la vez ==\n";
    $php = phpTrabajador();
    $nuevoLog = static function () use (&$logsTrabajadores): string {
        $ruta = (string) tempnam(sys_get_temp_dir(), 'conduces_worker');
        $logsTrabajadores[] = $ruta;
        return $ruta;
    };

    // Otra caja tiene abierta una transacción con el número siguiente (sin pasar
    // por la secuencia). El trabajador lee la secuencia y el MAX (no ve esa fila
    // sin confirmar), toma el mismo número y su INSERT espera. Al confirmarse la
    // otra, MySQL le da el 1062 de uk_conduces_numero: rollBack y un reintento.
    $siguiente = max($ultimo(), (int) valorDe($pdo, 'SELECT MAX(numero) FROM conduces')) + 1;
    $otra = conectarScratch($env, $db);
    $otra->beginTransaction();
    $otra->prepare('INSERT INTO conduces (numero, code, date) VALUES (?, ?, ?)')
        ->execute([$siguiente, FerreteriaConduce::codigo($siguiente), '2026-10-04 11:00:00']);
    $logReintento = $nuevoLog();
    $w = lanzarTrabajador($php, ['0', (string) $clienteId, (string) $cotId, (string) $productoId, $logReintento]);
    $esperando = false;
    for ($i = 0; $i < 150 && !$esperando; $i++) {
        usleep(100000);
        foreach ($pdo->query('SHOW FULL PROCESSLIST')->fetchAll() as $hilo) {
            $esperando = $esperando || str_starts_with(ltrim((string) ($hilo['Info'] ?? '')), 'INSERT INTO conduces ');
        }
    }
    $otra->commit();
    $otra = null;
    $res = esperarTrabajador($w);
    clearstatcache();
    $chk("el trabajador queda esperando en su INSERT el número {$siguiente}, que tiene otra transacción abierta (SHOW PROCESSLIST)", $esperando);
    $chk('al confirmarse la otra: el 1062 real de uk_conduces_numero se reintenta una vez y sale ' . FerreteriaConduce::codigo($siguiente + 1),
        $res['codigo'] === 0 && esCreado($res['json']['r'] ?? null, $siguiente + 1));
    $chk('... el reintento quedó en su error_log y la secuencia queda en ' . ($siguiente + 1),
        str_contains((string) file_get_contents($logReintento), "el numero {$siguiente} ya estaba tomado (uk_conduces_numero): se reintenta una vez")
        && $ultimo() === $siguiente + 1);

    // Cinco procesos con la misma hora de salida: el FOR UPDATE de
    // conduce_secuencia los pone en fila, sin deadlock (la semilla va fuera de
    // la transacción) y sin choque.
    $maxAntes = (int) valorDe($pdo, 'SELECT MAX(numero) FROM conduces');
    $filasAntes = (int) valorDe($pdo, 'SELECT COUNT(*) FROM conduces');
    $salida = sprintf('%.6F', microtime(true) + 4.0);
    $trabajadores = [];
    for ($i = 0; $i < 5; $i++) {
        $trabajadores[] = lanzarTrabajador($php, [$salida, (string) $clienteId, (string) $cotId, (string) $productoId, $nuevoLog()]);
    }
    $resultados = array_map('esperarTrabajador', $trabajadores);
    $respuestas = array_map(static fn(array $x) => $x['json']['r'] ?? null, $resultados);
    foreach ($respuestas as $r) {
        if (($r[0] ?? null) !== 'success') {
            echo '         respuesta: ' . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
        }
    }
    $numeros = array_map(static fn($r) => $r[1]['numero'] ?? null, $respuestas);
    sort($numeros);
    $t0 = array_map(static fn(array $x): float => (float) ($x['json']['t0'] ?? 0), $resultados);
    $t1 = array_map(static fn(array $x): float => (float) ($x['json']['t1'] ?? 0), $resultados);
    $chk('cinco procesos: los cinco terminan bien (sin choque, sin deadlock, sin nada en stderr)',
        array_column($resultados, 'codigo') === [0, 0, 0, 0, 0] && array_column($resultados, 'err') === ['', '', '', '', '']
        && array_map(static fn($r) => $r[0] ?? null, $respuestas) === array_fill(0, 5, 'success'));
    $chk(sprintf('cinco procesos: salieron juntos y se pisaron (del primero al último en salir: %.0f ms; el primero en terminar tardó %.0f ms)',
            (max($t0) - min($t0)) * 1000, (min($t1) - min($t0)) * 1000),
        !in_array(true, array_map(static fn(array $x) => $x['json']['tarde'] ?? true, $resultados), true) && max($t0) < min($t1));
    $chk('cinco procesos: cinco números distintos y seguidos (' . ($maxAntes + 1) . '..' . ($maxAntes + 5) . ')',
        $numeros === range($maxAntes + 1, $maxAntes + 5));
    $nuevos = array_map(static fn($r) => (int) ($r[1]['id'] ?? 0), $respuestas);
    $chk('cinco procesos: la secuencia queda en el mayor, 5 filas nuevas y 2 líneas activas en cada una',
        $ultimo() === $maxAntes + 5 && (int) valorDe($pdo, 'SELECT COUNT(*) FROM conduces') === $filasAntes + 5
        && array_map(static fn(int $id): int => (int) valorDe($pdo,
            'SELECT COUNT(*) FROM conduce_items WHERE conduce_id = ? AND activo = 1', [$id]), $nuevos) === [2, 2, 2, 2, 2]);
    $chk('ningún número repetido en toda la tabla',
        (int) valorDe($pdo, 'SELECT COUNT(*) FROM conduces') === (int) valorDe($pdo, 'SELECT COUNT(DISTINCT numero) FROM conduces'));

    // -----------------------------------------------------------------------
    // e) Topes de cantidad y precio (conduce_items.quantity y .amount)
    // -----------------------------------------------------------------------
    echo "\n== e) Topes de cantidad y precio ==\n";
    // FerreteriaConduce::validarForma rechaza lo que no cabe en estas columnas con un
    // "Línea N: ... demasiado grande" (tools/test_conduces.php); aquí se comprueba contra el
    // servidor que sus topes son los de las columnas, que en el tope se guarda tal cual y que
    // lo que se salta validarForma llega al 1264 real, que conduceModel vuelve un 422.
    $logPrevioTopes = ini_set('error_log', $logModelo);
    $topeDeColumna = static fn(string $columna): float => (float) (10 ** (int) valorDe($pdo,
        'SELECT NUMERIC_PRECISION - NUMERIC_SCALE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$db, 'conduce_items', $columna]));
    $chk('los topes de validarForma son los de las columnas: quantity ' . $tipo('conduce_items', 'quantity') . ' (1e9) y amount '
        . $tipo('conduce_items', 'amount') . ' (1e14)',
        FerreteriaConduce::LIMITE_CANTIDAD === $topeDeColumna('quantity') && FerreteriaConduce::LIMITE_PRECIO === $topeDeColumna('amount'));
    $r = $m->crear(cotScratch($clienteId, $cotId, null, null, [
        lineaScratch(null, 'TOPE DE CANTIDAD', 999999999.99, 0.0),
        lineaScratch(null, 'TOPE DE PRECIO', 1.0, 99999999999999.0),
    ]), 5, 'HOSPITAL DOCENTE');
    $idTope = (int) ($r[1]['id'] ?? 0);
    $chk('en el tope se guarda tal cual: cantidad 999999999.99 y precio 99999999999999 (los DECIMAL vuelven como texto)',
        ($r[0] ?? null) === 'success' && array_column($lineasDe($idTope), 'quantity') === ['999999999.990', '1.000']
        && array_column($lineasDe($idTope), 'amount') === ['0.0000', '99999999999999.0000']);
    $antesTopes = $cuentas();
    foreach ([['cantidad 1e9', lineaScratch(null, 'X', 1e9, 1.0)], ['precio 1e14', lineaScratch(null, 'X', 1.0, 1e14)]] as [$que, $linea]) {
        $r = $m->crear(cotScratch($clienteId, $cotId, null, null, [$linea]), 5, 'X');
        $chk("crear con {$que} (sin pasar por validarForma): el 1264 real => 422 MSG_FUERA_DE_RANGO, y el rollback no deja nada",
            $r === ['error', FerreteriaConduce::MSG_FUERA_DE_RANGO, 422] && $cuentas() === $antesTopes);
    }
    $activasTope = filasDe($pdo, 'SELECT id FROM conduce_items WHERE conduce_id = ? AND activo = 1 ORDER BY id', [$idTope]);
    $r = $m->actualizar($idTope, cotScratch($clienteId, $cotId, null, '2026-10-01 07:00:00', [lineaScratch(null, 'X', 1e9, 1.0)]), 9, 'OTRO');
    $chk('actualizar con una cantidad fuera de rango => 422 MSG_FUERA_DE_RANGO; el rollback deja las líneas activas y el nombre como estaban',
        $r === ['error', FerreteriaConduce::MSG_FUERA_DE_RANGO, 422]
        && filasDe($pdo, 'SELECT id FROM conduce_items WHERE conduce_id = ? AND activo = 1 ORDER BY id', [$idTope]) === $activasTope
        && valorDe($pdo, 'SELECT client_name FROM conduces WHERE id = ?', [$idTope]) === 'HOSPITAL DOCENTE');
    clearstatcache();
    $logTopes = (string) file_get_contents($logModelo);
    $chk("el error_log trae el detalle de MySQL: el 1264 de 'quantity' (dos veces) y el de 'amount' (una)",
        substr_count($logTopes, " 1264 Out of range value for column 'quantity'") === 2
        && substr_count($logTopes, " 1264 Out of range value for column 'amount'") === 1);
    ini_set('error_log', $logPrevioTopes === false ? '' : $logPrevioTopes);

    // -----------------------------------------------------------------------
    // f) Las reglas ON DELETE y la 031 con conduces guardados
    // -----------------------------------------------------------------------
    echo "\n== f) Reglas ON DELETE y la 031 otra vez ==\n";
    $codigoBorrar = null;
    $detalleBorrar = '';
    try {
        $pdo->prepare('DELETE FROM conduces WHERE id = ?')->execute([$id2]);
    } catch (PDOException $e) {
        $codigoBorrar = (int) ($e->errorInfo[1] ?? 0);
        $detalleBorrar = (string) ($e->errorInfo[2] ?? '');
    }
    $chk('un conduce con líneas no se puede borrar: 1451 de conduce_items_conduce_fk (RESTRICT)',
        $codigoBorrar === 1451 && str_contains($detalleBorrar, 'conduce_items_conduce_fk'));
    $lineasProducto = (int) valorDe($pdo, 'SELECT COUNT(*) FROM conduce_items WHERE product_id = ?', [$productoId]);
    $libresAntes = (int) valorDe($pdo, 'SELECT COUNT(*) FROM conduce_items WHERE product_id IS NULL');
    $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$productoId]);
    $chk("borrar el producto deja sus {$lineasProducto} líneas como libres (product_id NULL: SET NULL)", $lineasProducto > 0
        && (int) valorDe($pdo, 'SELECT COUNT(*) FROM conduce_items WHERE product_id IS NULL') === $libresAntes + $lineasProducto);
    $pdo->prepare('DELETE FROM cotizaciones WHERE id = ?')->execute([$cotId]);
    $fila = $m->obtener($id2) ?? [];
    $chk('borrar la cotización deja cotizacion_id NULL en sus conduces (SET NULL) y obtener da cotizacion_code null',
        (int) valorDe($pdo, 'SELECT COUNT(*) FROM conduces WHERE cotizacion_id IS NOT NULL') === 0
        && array_key_exists('cotizacion_code', $fila) && $fila['cotizacion_id'] === null && $fila['cotizacion_code'] === null);
    $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$clienteId]);
    $fila = $m->obtener($id2) ?? [];
    $chk('borrar el cliente: el JOIN da client_name, company_name y rnc NULL y queda el nombre guardado',
        array_key_exists('client_name', $fila) && $fila['client_name'] === null && $fila['company_name'] === null
        && $fila['rnc'] === null && $fila['client_name_guardado'] === 'NOMBRE NUEVO' && $fila['client_id'] === $clienteId);

    $n = $ultimo();
    $cuentasAntes = $cuentas();
    $ddlAntes = ddlsDe($pdo, TABLAS_CONDUCES);
    $r4 = correrSql($pdo, $mig031);
    $chk("la 031 otra vez, con conduces guardados: todo_ok = SI, fila_secuencia = (1, {$n}) y no cambia nada",
        ($r4[0]['todo_ok'] ?? null) === 'SI' && ($r4[0]['fila_secuencia'] ?? null) === "(1, {$n})"
        && $cuentas() === $cuentasAntes && ddlsDe($pdo, TABLAS_CONDUCES) === $ddlAntes);
} catch (Throwable $e) {
    $chk('sin excepciones: ' . get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')', false);
} finally {
    echo "\n== Limpieza ==\n";
    // Si algo falló con la transacción de $otra abierta, su candado de
    // metadatos frenaría el DROP TABLE: cerrar la conexión la deshace.
    $otra = null;
    try {
        vaciarBase($pdo, $db);
        $chk("{$db} queda vacía (sin tablas, vistas, rutinas ni eventos)", objetosEn($pdo, $db) === 0);
    } catch (Throwable $e) {
        $chk('limpieza: ' . $e->getMessage(), false);
    }
    foreach (array_merge([$logModelo], $logsTrabajadores) as $ruta) {
        @unlink($ruta);
    }
}

printf("\n%d/%d OK\n", $total - $fallos, $total);
exit($fallos === 0 ? 0 : 1);
