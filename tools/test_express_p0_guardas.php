<?php
/**
 * test_express_p0_guardas.php — Las guardas de las herramientas de P0 de Express corridas COMO PROGRAMA.
 * Son lo que impide que la linea base y la captura dorada describan otra cosa que un commit limpio, y lo
 * que protege el token de la app y los numeros de secuencia de la DGII del dueno; hasta aqui solo las
 * probaban a mano las notas de las tareas, asi que un refactor que moviera una guarda despues de abrir
 * una base o un servidor habria pasado el CI.
 *
 *   1) p0_git sobre un repositorio git temporal (la primera columna del porcelain puede ser un espacio).
 *   2) express_baseline.php, de punta a punta con suites de mentira en un repositorio temporal: sus
 *      rechazos (arbol sucio, sin repositorio, sin base, base rota) y sus salidas 0, 1 y 2; --comparar
 *      espera lo que la base guarda aunque la constante lo haya perdido; una suite muda, caida o ausente
 *      no es verde.
 *   3) express_golden.php: cada rechazo sale con 2 ANTES de pedir una base o un servidor (no hay
 *      credenciales a mano: si una guarda se moviera despues, la salida seria otra); un error a media
 *      corrida sale con 2 y enmascarado; wa_servidor sale con el codigo que se le pide.
 *   4) spike_e34_certecf.php contra un servidor local: sin token sale con 2 y sin llamar; un ambiente que
 *      no es certecf ni testecf, ilegible o con redireccion sale con 2 sin emitir; con certecf pasa la
 *      guarda y llega a emitir; el token solo viaja en la cabecera X-API-KEY.
 *
 * Cada herramienta se copia a una raiz temporal (su CLI usa dirname(__DIR__) como raiz) y corre en un
 * proceso aparte; nada toca una base de datos, la red ni el repositorio real. Los servidores son
 * "php -S" en 127.0.0.1 con un puerto libre. Hace falta git en el PATH, como a las herramientas.
 *
 * Uso:
 *   php tools/test_express_p0_guardas.php      (sale con 1 si algo falla)
 */

require_once __DIR__ . '/write_ahead_test_lib.php';
require_once __DIR__ . '/express_p0_lib.php';
require_once __DIR__ . '/express_baseline.php';

// ---------------------------------------------------------------------------
// Ayudas
// ---------------------------------------------------------------------------

/** Borra un directorio con todo lo que tenga (git deja archivos de solo lectura, que en Windows hay que soltar antes). */
function guardas_borrar(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $ruta = $f->getPathname();
        if ($f->isDir() && !$f->isLink()) {
            @rmdir($ruta);
        } else {
            @chmod($ruta, 0666);
            @unlink($ruta);
        }
    }
    @chmod($dir, 0777);
    @rmdir($dir);
}

/** Carpeta temporal propia de esta corrida: se borra al terminar, tambien con exit. */
function guardas_dir(string $etiqueta): string
{
    static $dirs = [];
    static $registrado = false;
    if (!$registrado) {
        $registrado = true;
        // Un shutdown registrado dentro de otro va al final de la cola: despues de los que detienen los
        // php -S de wa_servidor, que (en Windows) sostienen su carpeta como directorio de trabajo.
        register_shutdown_function(static function () use (&$dirs): void {
            register_shutdown_function(static function () use (&$dirs): void {
                foreach ($dirs as $d) {
                    guardas_borrar($d);
                }
            });
        });
    }
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'express_guardas_' . $etiqueta . '_' . bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);
    $dirs[] = $dir;
    return $dir;
}

/**
 * Corre un comando (forma array, sin shell) con $env sobre el entorno actual. Salida y errores van juntos a un
 * archivo temporal (sin pipes: en Windows no hay lecturas con tiempo limite) y se mata el proceso pasados
 * $limiteS segundos (codigo -2).
 *
 * @return array{codigo: int, salida: string}
 */
function guardas_correr(array $cmd, string $cwd, array $env = [], int $limiteS = 180): array
{
    $tmp = (string) tempnam(sys_get_temp_dir(), 'gp_');
    $fh = fopen($tmp, 'w');
    $nulo = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $codigo = -1;
    $proc = $fh === false ? false : proc_open($cmd, [0 => ['file', $nulo, 'r'], 1 => $fh, 2 => $fh], $pipes, $cwd, array_merge(getenv(), array_map('strval', $env)));
    if (is_resource($proc)) {
        $limite = microtime(true) + $limiteS;
        while (true) {
            $st = proc_get_status($proc);
            if (!$st['running']) {
                $codigo = (int) $st['exitcode'];
                break;
            }
            if (microtime(true) > $limite) {
                proc_terminate($proc);
                $codigo = -2;
                break;
            }
            usleep(20000);
        }
        proc_close($proc);
    }
    if (is_resource($fh)) {
        fclose($fh);
    }
    $salida = (string) file_get_contents($tmp);
    @unlink($tmp);
    return ['codigo' => $codigo, 'salida' => $salida];
}

/** Un comando de git en $cwd con identidad y sin firma; devuelve su codigo de salida. */
function guardas_git(string $cwd, string ...$args): int
{
    $base = ['git', '-c', 'user.name=Prueba', '-c', 'user.email=prueba@example.test', '-c', 'commit.gpgsign=false', '-c', 'core.autocrlf=false'];
    return guardas_correr(array_merge($base, $args), $cwd, ['GIT_CEILING_DIRECTORIES' => dirname($cwd)], 60)['codigo'];
}

/** Un puerto de loopback libre (se abre y se cierra: la carrera es de milisegundos). */
function guardas_puerto_libre(): int
{
    $s = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($s === false) {
        echo "[FALLO] no se pudo abrir un puerto de loopback: {$errstr}\n";
        exit(1);
    }
    $nombre = (string) stream_socket_get_name($s, false);
    fclose($s);
    return (int) substr($nombre, (int) strrpos($nombre, ':') + 1);
}

/**
 * Una raiz de API de mentira en una carpeta temporal: copia $archivos de tools/ a <raiz>/tools/ (el CLI de cada
 * herramienta usa dirname(__DIR__) como raiz), escribe $extras (ruta relativa => contenido) y, con $git, la deja
 * como repositorio con un commit (arbol limpio).
 *
 * @param list<string> $archivos
 * @param array<string, string> $extras
 */
function guardas_raiz(string $etiqueta, array $archivos, array $extras = [], bool $git = true): string
{
    $raiz = guardas_dir($etiqueta);
    mkdir($raiz . '/tools', 0777, true);
    foreach ($archivos as $f) {
        if (!copy(__DIR__ . '/' . $f, $raiz . '/tools/' . $f)) {
            echo "[FALLO] no se pudo copiar tools/{$f} a la raiz temporal\n";
            exit(1);
        }
    }
    foreach ($extras as $ruta => $contenido) {
        if (!is_dir(dirname($raiz . '/' . $ruta))) {
            mkdir(dirname($raiz . '/' . $ruta), 0777, true);
        }
        file_put_contents($raiz . '/' . $ruta, $contenido);
    }
    if ($git && (guardas_git($raiz, 'init', '-q') !== 0 || guardas_git($raiz, 'add', '.') !== 0 || guardas_git($raiz, 'commit', '-q', '-m', 'raiz de prueba') !== 0)) {
        echo "[FALLO] no se pudo crear el repositorio git temporal: ¿git esta en el PATH?\n";
        exit(1);
    }
    return $raiz;
}

/** Entorno de un hijo sin credenciales de scratch: ni las de la maquina que corre esto ni un tools/.env se cuelan. */
const GUARDAS_SIN_CREDENCIALES = ['WA_DB_HOST' => '', 'WA_DB_PORT' => '', 'WA_DB_USER' => '', 'WA_DB_PASS' => '', 'WA_DB_PREFIX' => ''];

// ---------------------------------------------------------------------------
// 1) p0_git
// ---------------------------------------------------------------------------
echo "\n== 1) p0_git sobre un repositorio temporal ==\n";
putenv('GIT_CEILING_DIRECTORIES=' . sys_get_temp_dir()); // que una carpeta temporal sin repo no suba hasta uno que la contenga
$repo = guardas_raiz('git', [], ['a.txt' => "uno\n"]);
$sha = p0_git(['rev-parse', 'HEAD'], $repo);
wa_chk('rev-parse HEAD: los 40 hex del commit, recortado a la derecha', preg_match('/^[0-9a-f]{40}$/', $sha) === 1, $sha);
wa_chk('status --porcelain de un arbol limpio: vacio', p0_git(['status', '--porcelain'], $repo) === '' && p0_arbol_limpio(p0_git(['status', '--porcelain'], $repo)));
file_put_contents($repo . '/a.txt', "uno\ndos\n");
wa_chk('un archivo modificado: la PRIMERA columna es un espacio y p0_git no se la come (nunca ltrim)', p0_git(['status', '--porcelain'], $repo) === ' M a.txt', p0_git(['status', '--porcelain'], $repo));
file_put_contents($repo . '/b.txt', "nuevo\n");
wa_chk('modificado y sin seguimiento: dos lineas, la primera con su espacio', p0_git(['status', '--porcelain'], $repo) === " M a.txt\n?? b.txt", p0_git(['status', '--porcelain'], $repo));
wa_chk('p0_arbol_limpio sobre ese texto real: sucio', !p0_arbol_limpio(p0_git(['status', '--porcelain'], $repo), ['tools/express_golden/']));
$sinRepo = guardas_dir('sinrepo');
wa_chk('una carpeta que no es un repositorio: cadena vacia (git sale con 128)', p0_git(['rev-parse', 'HEAD'], $sinRepo) === '');
$sinCommits = guardas_dir('sincommits');
guardas_git($sinCommits, 'init', '-q');
wa_chk('un repositorio sin commits: cadena vacia', p0_git(['rev-parse', 'HEAD'], $sinCommits) === '');
wa_chk('un directorio de trabajo que no existe: cadena vacia, sin avisos ni excepciones', p0_git(['status'], $sinRepo . DIRECTORY_SEPARATOR . 'no_existe') === '');
wa_chk('los argumentos no pasan por un interprete de comandos', p0_git(['log', '-1', '--format=%H; echo no-pasa-por-shell'], $repo) === $sha . '; echo no-pasa-por-shell', p0_git(['log', '-1', '--format=%H; echo no-pasa-por-shell'], $repo));

// ---------------------------------------------------------------------------
// 2) express_baseline.php de punta a punta, con suites de mentira
// ---------------------------------------------------------------------------
echo "\n== 2) express_baseline.php: guardas, salidas 0, 1 y 2 y lo que la base guarda (Review Focus 1 y 3) ==\n";
$falsas = [];
foreach (BASELINE_SUITES as $i => $s) {
    $n = 3 + $i;
    $falsas['tools/' . $s['archivo']] = "<?php\necho \"  [OK  ] un caso\\n\\n{$n}/{$n} OK\\n\";\n";
}
$rb = guardas_raiz('baseline', ['express_baseline.php', 'express_p0_lib.php', 'write_ahead_test_lib.php'], $falsas);
$rbEnv = ['GIT_CEILING_DIRECTORIES' => dirname($rb)];
$bl = static fn (string ...$args): array => guardas_correr(array_merge([PHP_BINARY], wa_php_flags(BASELINE_EXTENSIONES), [$rb . '/tools/express_baseline.php'], $args), $rb, $rbEnv);
$archivoBase = $rb . '/' . BASELINE_ARCHIVO;

$r = $bl('--comparar');
wa_chk('--comparar sin base: 2 y dice como capturar, sin correr ninguna suite', $r['codigo'] === 2 && str_contains($r['salida'], 'No hay linea base') && !str_contains($r['salida'], 'total'), $r);
mkdir(dirname($archivoBase), 0777, true);
$rotas = ['no es json' => 'esto no es json', 'otra version' => '{"version":2,"suites":{}}', 'sin suites' => '{"version":1}'];
$malas = [];
foreach ($rotas as $que => $contenido) {
    file_put_contents($archivoBase, $contenido);
    $r = $bl('--comparar');
    if ($r['codigo'] !== 2 || !str_contains($r['salida'], 'no tiene la forma esperada') || str_contains($r['salida'], 'total')) {
        $malas[$que] = $r;
    }
}
wa_chk('--comparar con una base que no es JSON, de otra version o sin suites: 2, sin correr nada', $malas === [], $malas);
unlink($archivoBase);

file_put_contents($rb . '/sucio.txt', "sin confirmar\n");
$r = $bl('--capturar');
wa_chk('--capturar con el arbol sucio: 2, nombra lo que ensucia y no corre ninguna suite ni escribe la base',
    $r['codigo'] === 2 && str_contains($r['salida'], 'cambios sin confirmar') && str_contains($r['salida'], '?? sucio.txt') && !str_contains($r['salida'], 'total') && !is_file($archivoBase), $r);
unlink($rb . '/sucio.txt');
$rbSinGit = guardas_raiz('baseline_sin_git', ['express_baseline.php', 'express_p0_lib.php', 'write_ahead_test_lib.php'], $falsas, false);
$r = guardas_correr(array_merge([PHP_BINARY], wa_php_flags(BASELINE_EXTENSIONES), [$rbSinGit . '/tools/express_baseline.php', '--capturar']), $rbSinGit, ['GIT_CEILING_DIRECTORIES' => dirname($rbSinGit)]);
wa_chk('--capturar fuera de un repositorio git: 2 y no escribe nada', $r['codigo'] === 2 && str_contains($r['salida'], 'no es un repositorio git') && !is_file($rbSinGit . '/' . BASELINE_ARCHIVO), $r);

// Lo que ya hay en tools/express_golden/ no cuenta como arbol sucio (la base y el golden viven ahi).
file_put_contents(dirname($archivoBase) . '/golden.json', "{}\n");
$r = $bl('--capturar');
$doc = is_file($archivoBase) ? json_decode((string) file_get_contents($archivoBase), true) : null;
$shaRaiz = p0_git(['rev-parse', 'HEAD'], $rb);
wa_chk('--capturar sobre un arbol limpio (con algo sin confirmar solo bajo tools/express_golden/): 0 y escribe la base completa',
    $r['codigo'] === 0 && str_contains($r['salida'], 'Línea base escrita') && is_array($doc) && ($doc['version'] ?? null) === 1
    && array_keys($doc['suites'] ?? []) === array_column(BASELINE_SUITES, 'archivo') && ($doc['git_sha'] ?? null) === $shaRaiz && ($doc['php'] ?? null) === baseline_php(), $r);
$primera = $doc['suites']['test_write_ahead_puro.php'] ?? [];
wa_chk('la base guarda el total, los fallos, las saltadas, si esta fijada y OK de cada suite',
    $primera === ['total' => 3, 'fallos' => 0, 'saltadas' => 0, 'pinned' => true, 'estado' => 'OK'] && ($doc['suites']['test_grupo_multiempresa.php']['total'] ?? null) === 17 && ($doc['suites']['test_grupo_multiempresa.php']['pinned'] ?? null) === false, $primera);
wa_chk('despues de capturar el arbol sigue limpio para la herramienta (la base se commitea aparte)', p0_arbol_limpio(p0_git(['status', '--porcelain'], $rb), ['tools/express_golden/']));

$r = $bl('--comparar');
wa_chk('--comparar sobre lo recien capturado: 0, "Sin diferencias" y ningun aviso', $r['codigo'] === 0 && str_contains($r['salida'], 'Sin diferencias con la linea base') && !str_contains($r['salida'], 'AVISO') && !str_contains($r['salida'], 'DIFERENCIA'), $r);

// La base guarda una suite que la constante ya no lista y otra con mas pruebas de las que hay ahora.
$base = $doc;
$base['suites']['test_quitada_de_la_constante.php'] = ['total' => 9, 'fallos' => 0, 'saltadas' => 0, 'pinned' => true, 'estado' => 'OK'];
$base['suites']['test_cotizacion_ferreteria.php']['total'] += 5;
file_put_contents($archivoBase, json_encode($base, JSON_PRETTY_PRINT) . "\n");
$r = $bl('--comparar');
$perdidas = $base['suites']['test_cotizacion_ferreteria.php']['total'] . ' -> ' . ($base['suites']['test_cotizacion_ferreteria.php']['total'] - 5);
wa_chk('--comparar completo espera TODO lo que la base guarda: la suite quitada de la constante da "no corrio" y 1; las pruebas perdidas, "total A -> B"',
    $r['codigo'] === 1 && str_contains($r['salida'], 'DIFERENCIA test_quitada_de_la_constante.php: no corrio') && str_contains($r['salida'], "DIFERENCIA test_cotizacion_ferreteria.php: total {$perdidas}") && str_contains($r['salida'], '2 diferencia(s) con la linea base'), $r);
$r = $bl('--comparar', '--suites=test_write_ahead_puro');
wa_chk('--comparar --suites=<una>: solo se espera lo pedido, el resto de la base no estorba', $r['codigo'] === 0 && !str_contains($r['salida'], 'test_quitada') && !str_contains($r['salida'], 'DIFERENCIA'), $r);
file_put_contents($archivoBase, json_encode($doc, JSON_PRETTY_PRINT) . "\n");

// Una suite que no prueba nada no es verde: muda, caida con resumen o ausente.
file_put_contents($rb . '/tools/test_tenant_mail.php', "<?php\n");
$nConduces = 3 + (int) array_search('test_conduces.php', array_column(BASELINE_SUITES, 'archivo'), true); // el total de la base: solo cambia el codigo de salida
file_put_contents($rb . '/tools/test_conduces.php', "<?php\necho \"{$nConduces}/{$nConduces} OK\\n\";\nexit(1);\n");
unlink($rb . '/tools/test_inventario_stock.php');
$r = $bl('--comparar', '--suites=test_tenant_mail,test_conduces,test_inventario_stock');
wa_chk('una suite muda (exit 0 sin resumen), una que sale con error aunque diga OK y una que ya no existe: cada una es diferencia, nunca verde',
    $r['codigo'] === 1 && str_contains($r['salida'], 'DIFERENCIA test_tenant_mail.php: sin resumen') && str_contains($r['salida'], 'DIFERENCIA test_conduces.php: salio con error')
    && str_contains($r['salida'], 'DIFERENCIA test_inventario_stock.php: sin resumen') && str_contains($r['salida'], '3 diferencia(s)'), $r);
$antes = md5_file($archivoBase);
$r = $bl('--capturar');
wa_chk('con suites cambiadas sin commitear, --capturar se niega (2) y la base no se toca', $r['codigo'] === 2 && str_contains($r['salida'], 'cambios sin confirmar') && md5_file($archivoBase) === $antes, $r);

// ---------------------------------------------------------------------------
// 3) express_golden.php: rechazos antes de tocar nada, error inesperado y wa_servidor
// ---------------------------------------------------------------------------
echo "\n== 3) express_golden.php: cada rechazo sale con 2 antes de abrir una base o un servidor (Review Focus 1) ==\n";
$archivosGolden = ['express_golden.php', 'express_golden_lib.php', 'express_p0_lib.php', 'test_pos_scratch.php', 'pos_scratch_prepare.php', 'pos_scratch_guard.php', 'write_ahead_test_lib.php'];
$raizApi = ['index.php' => "<?php\n", 'tools/mock_dgii_local.php' => "<?php\n"]; // lo unico que pos_scratch_revisar_raiz mira
$rg = guardas_raiz('golden', $archivosGolden, $raizApi);
$golden = static function (string $raiz, array $flags, array $env, string ...$args): array {
    return guardas_correr(array_merge([PHP_BINARY], $flags, [$raiz . '/tools/express_golden.php'], $args), $raiz, $env + GUARDAS_SIN_CREDENCIALES + ['GIT_CEILING_DIRECTORIES' => dirname($raiz)]);
};
$g = static fn (string ...$args): array => $golden($rg, wa_php_flags(), [], ...$args);
$gDorada = $rg . '/' . 'tools/express_golden/golden.json';
$rechazo = static fn (array $r, string $texto): bool => $r['codigo'] === 2 && str_contains($r['salida'], $texto)
    && !str_contains($r['salida'], 'faltan las credenciales') && !str_contains($r['salida'], 'no se pudo conectar') && !str_contains($r['salida'], 'Fatal error');
$r = $g();
wa_chk('sin --capturar ni --comparar: 2 y el uso', $rechazo($r, 'Uso: php'), $r);
$r = $g('--capturar', '--comparar');
wa_chk('las dos a la vez: 2 y el uso', $rechazo($r, 'Uso: php'), $r);
file_put_contents($rg . '/.env', "X=1\n");
$r = $g('--comparar');
wa_chk('una raiz con .env: 2 (Database lo cargaria y una peticion sin tenant podria caer en otra base)', $rechazo($r, 'tiene un .env'), $r);
unlink($rg . '/.env');
rename($rg . '/tools/mock_dgii_local.php', $rg . '/tools/mock_dgii_local.off');
$r = $g('--comparar');
wa_chk('una raiz que no es la de un API (sin el simulador de la DGII): 2', $rechazo($r, 'no es la raiz de un API'), $r);
rename($rg . '/tools/mock_dgii_local.off', $rg . '/tools/mock_dgii_local.php');
$r = $golden($rg, ['-n'], [], '--comparar');
wa_chk('sin las extensiones curl, openssl o pdo_mysql (php -n): 2 y dice con que flags correr', $rechazo($r, 'faltan extensiones de PHP') && str_contains($r['salida'], '-d extension=curl'), $r);
$r = $g('--comparar');
wa_chk('--comparar sin golden.json: 2 y dice como capturar', $rechazo($r, 'no hay captura dorada') && str_contains($r['salida'], '--capturar'), $r);
mkdir(dirname($gDorada), 0777, true);
$rotas = ['no es json' => 'esto no es json', 'otra version' => '{"version":999,"modos":{}}', 'sin modos' => '{"version":1}'];
$malas = [];
foreach ($rotas as $que => $contenido) {
    file_put_contents($gDorada, $contenido);
    $r = $g('--comparar');
    if (!$rechazo($r, 'no tiene la forma esperada')) {
        $malas[$que] = $r;
    }
}
wa_chk('--comparar con un golden.json que no es JSON, de otra version o sin modos: 2', $malas === [], $malas);
unlink($gDorada);
file_put_contents($rg . '/sucio.txt', "sin confirmar\n");
$r = $g('--capturar');
wa_chk('--capturar con el arbol sucio: 2, nombra lo que ensucia y no escribe golden.json', $rechazo($r, 'cambios sin confirmar') && str_contains($r['salida'], '?? sucio.txt') && !is_file($gDorada), $r);
unlink($rg . '/sucio.txt');
$rgSinGit = guardas_raiz('golden_sin_git', $archivosGolden, $raizApi, false);
$r = $golden($rgSinGit, wa_php_flags(), [], '--capturar');
wa_chk('--capturar fuera de un repositorio git: 2', $rechazo($r, 'no es un repositorio git') && !is_file($rgSinGit . '/tools/express_golden/golden.json'), $r);

// Un error a media corrida: la preparacion de la base se sustituye por una que lanza, en una copia de la raiz.
$stubPrepare = <<<'PHP'
<?php
// Sustituto de pos_scratch_prepare.php solo para esta prueba: la preparacion de la base falla a media corrida.
require_once __DIR__ . '/write_ahead_test_lib.php';
function pos_scratch_secretos(string $base): array
{
    return ['clave_maestra' => 'a', 'pepper' => 'b', 'clave_usuarios' => 'c'];
}
function pos_scratch_preparar(): array
{
    if (getenv('GUARDAS_FALLA') === 'error') {
        throw new Error('fallo del motor con clave_falsa_123 dentro');
    }
    $e = new PDOException("SQLSTATE[HY000] [1045] Access denied for user 'usuario_falso'@'host_falso' (using password: YES) clave_falsa_123");
    $e->errorInfo = ['HY000', 1045, 'Access denied'];
    throw $e;
}
PHP;
$rf = guardas_raiz('golden_falla', $archivosGolden, $raizApi + ['tools/express_golden/golden.json' => '{"version":1,"modos":{}}', 'tools/pos_scratch_prepare.php' => $stubPrepare]);
$secretos = ['WA_DB_USER' => 'usuario_falso', 'WA_DB_HOST' => 'host_falso', 'WA_DB_PASS' => 'clave_falsa_123'];
$r = $golden($rf, wa_php_flags(), $secretos, '--comparar');
$sinSecretos = static fn (string $s): bool => !str_contains($s, 'Access denied') && !str_contains($s, 'usuario_falso') && !str_contains($s, 'host_falso') && !str_contains($s, 'clave_falsa_123');
wa_chk('una PDOException a media corrida: sale con 2 (no 255 ni 1), UNA linea enmascarada con la clase y los codigos, sin el mensaje del driver ni sus datos',
    $r['codigo'] === 2 && str_contains($r['salida'], 'Rechazado: error inesperado') && str_contains($r['salida'], 'PDOException') && str_contains($r['salida'], 'SQLSTATE HY000 errno 1045')
    && $sinSecretos($r['salida']) && substr_count(trim($r['salida']), "\n") === 0 && !str_contains($r['salida'], 'Fatal error') && !str_contains($r['salida'], 'Stack trace'), $r);
$r = $golden($rf, wa_php_flags(), $secretos + ['GUARDAS_FALLA' => 'error'], '--comparar');
wa_chk('un \Error cuyo mensaje trae un secreto conocido: sale con 2 y el secreto sale tapado', $r['codigo'] === 2 && str_contains($r['salida'], 'Error') && str_contains($r['salida'], '***') && $sinSecretos($r['salida']), $r);

// wa_servidor: el codigo con el que sale cuando el puerto es de otro proceso.
$ocupado = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
$puertoOcupado = (int) substr((string) stream_socket_get_name($ocupado, false), (int) strrpos((string) stream_socket_get_name($ocupado, false), ':') + 1);
$hijo = guardas_dir('wa_servidor') . DIRECTORY_SEPARATOR . 'hijo.php';
file_put_contents($hijo, "<?php\nrequire_once " . var_export(__DIR__ . '/write_ahead_test_lib.php', true) . ";\n"
    . "\$p = (int) \$argv[1];\nif (\$argv[2] === 'defecto') { wa_servidor('guardas_x', 'index.php', \$p, [], sys_get_temp_dir()); }\n"
    . "wa_servidor('guardas_x', 'index.php', \$p, [], sys_get_temp_dir(), [], (int) \$argv[2]);\necho \"NO DEBIO LLEGAR\\n\";\n");
$conPuerto = static fn (string $salida): array => guardas_correr([PHP_BINARY, $hijo, (string) $puertoOcupado, $salida], dirname($hijo), ['WA_PUERTO_OFFSET' => '0']);
$def = $conPuerto('defecto');
$dos = $conPuerto('2');
fclose($ocupado);
wa_chk('wa_servidor con el puerto de otro proceso: sale con 1 por defecto (como todas las pruebas) y con el codigo que se le pide (2)',
    $def['codigo'] === 1 && $dos['codigo'] === 2 && str_contains($dos['salida'], 'ya lo usa otro proceso') && !str_contains($dos['salida'], 'NO DEBIO LLEGAR'), [$def, $dos]);

// ---------------------------------------------------------------------------
// 4) spike_e34_certecf.php contra un servidor local
// ---------------------------------------------------------------------------
echo "\n== 4) spike_e34_certecf.php: sin token, ambiente rechazado o ilegible, redireccion y cabecera (S3, owner-run) ==\n";
$dirSpike = guardas_dir('spike');
file_put_contents($dirSpike . '/router.php', <<<'PHP'
<?php
// API de mentira: anota cada peticion (metodo, ruta, X-API-KEY, cuerpo) y responde segun modo.txt.
$dir = getenv('GUARDAS_DIR');
file_put_contents($dir . '/peticiones.log', json_encode(['m' => $_SERVER['REQUEST_METHOD'], 'u' => $_SERVER['REQUEST_URI'], 'k' => $_SERVER['HTTP_X_API_KEY'] ?? null, 'b' => file_get_contents('php://input')]) . "\n", FILE_APPEND);
$modo = trim((string) @file_get_contents($dir . '/modo.txt'));
$ruta = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
if ($ruta === '/api/facturas/stats') {
    if ($modo === 'redirige') {
        http_response_code(301);
        header('Location: http://127.0.0.1:' . $_SERVER['SERVER_PORT'] . '/otra/ruta');
        return;
    }
    if ($modo === 'error500') {
        http_response_code(500);
        echo '<html>boom</html>';
        return;
    }
    if ($modo === 'sinambiente') {
        echo json_encode(['status' => false, 'error' => 'sin ambiente']);
        return;
    }
    echo json_encode(['status' => true, 'data' => ['ambiente_activo' => $modo]]);
    return;
}
if ($ruta === '/api/facturas' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    http_response_code(422);
    echo json_encode(['status' => false, 'error' => 'rechazo de prueba']);
    return;
}
http_response_code(404);
echo '{}';
PHP);
$libre = guardas_puerto_libre();
$urlSpike = wa_servidor('guardas_spike_' . bin2hex(random_bytes(3)), $dirSpike . '/router.php', $libre - (int) getenv('WA_PUERTO_OFFSET'), ['GUARDAS_DIR' => $dirSpike], $dirSpike);
$token = 'tk-guardas-4f9a-no-sale';
$spike = static function (string $modo, string $tokenEnv, string ...$args) use ($dirSpike, $urlSpike): array {
    file_put_contents($dirSpike . '/modo.txt', $modo);
    @unlink($dirSpike . '/peticiones.log');
    $r = guardas_correr(array_merge([PHP_BINARY], wa_php_flags(), [__DIR__ . '/express_p0/spike_e34_certecf.php', '--api=' . $urlSpike . '/api', '--client-id=7'], $args), dirname(__DIR__), ['EXPRESS_P0_TOKEN' => $tokenEnv]);
    $log = is_file($dirSpike . '/peticiones.log') ? array_map(static fn (string $l): array => json_decode($l, true) ?: [], file($dirSpike . '/peticiones.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
    return $r + ['peticiones' => $log];
};
$metodos = static fn (array $r): array => array_map(static fn (array $p): string => ($p['m'] ?? '?') . ' ' . parse_url($p['u'] ?? '', PHP_URL_PATH), $r['peticiones']);
$soloEnCabecera = static fn (array $r): bool => !str_contains($r['salida'], 'tk-guardas')
    && array_reduce($r['peticiones'], static fn (bool $ok, array $p): bool => $ok && ($p['k'] ?? null) === 'tk-guardas-4f9a-no-sale' && !str_contains($p['u'] ?? '', 'tk-guardas') && !str_contains($p['b'] ?? '', 'tk-guardas'), true);

$r = $spike('ecf', '', '--fase=original');
wa_chk('sin EXPRESS_P0_TOKEN: 2, lo dice y no llama a nadie', $r['codigo'] === 2 && str_contains($r['salida'], 'EXPRESS_P0_TOKEN') && $r['peticiones'] === [], $r);
$r = $spike('ecf', $token, '--fase=original');
wa_chk('ambiente ecf (produccion): 2, REHUSADO, UNA sola llamada (leer el ambiente) y ninguna emision; el token solo viaja en la cabecera',
    $r['codigo'] === 2 && str_contains($r['salida'], 'REHUSADO: el ambiente activo es ecf') && $metodos($r) === ['GET /api/facturas/stats'] && $soloEnCabecera($r), $r);
$r = $spike('sinambiente', $token, '--fase=original');
wa_chk('ambiente que no se puede leer (el API no lo dice): 2 y no emite, aunque nadie diga que sea produccion', $r['codigo'] === 2 && str_contains($r['salida'], 'REHUSADO: no pude leer el ambiente') && $metodos($r) === ['GET /api/facturas/stats'], $r);
$r = $spike('error500', $token, '--fase=nota', '--original=E310000000001', '--fecha-original=27-10-2026');
wa_chk('el API contesta 500 al leer el ambiente: 2 y no emite', $r['codigo'] === 2 && str_contains($r['salida'], 'REHUSADO: no pude leer el ambiente') && $metodos($r) === ['GET /api/facturas/stats'], $r);
$r = $spike('redirige', $token, '--fase=original');
wa_chk('una redireccion no se sigue (reenviaria X-API-KEY al host nuevo): 2, una sola peticion y ninguna a /otra/ruta', $r['codigo'] === 2 && str_contains($r['salida'], 'REHUSADO') && $metodos($r) === ['GET /api/facturas/stats'], $r);
$r = $spike('certecf', $token, '--fase=original');
wa_chk('ambiente certecf: pasa la guarda, lee el ambiente y emite UNA E31 al cliente pedido; si el API la rechaza sale con 1 y no sigue',
    $r['codigo'] === 1 && $metodos($r) === ['GET /api/facturas/stats', 'POST /api/facturas'] && str_contains($r['peticiones'][1]['b'] ?? '', '"tipo_ecf":"31"') && str_contains($r['peticiones'][1]['b'] ?? '', '"client_id":7')
    && str_contains($r['salida'], '"evento":"emision"') && $soloEnCabecera($r), $r);
$r = $spike('testecf', $token, '--fase=reintento', '--original=E310000000001', '--fecha-original=27-10-2026');
wa_chk('ambiente testecf: tambien pasa; la E34 de secuencia vencida rechazada por el API corta el reintento sin mandar la segunda',
    $r['codigo'] === 1 && count(array_filter($metodos($r), static fn (string $m): bool => $m === 'POST /api/facturas')) === 1 && str_contains($r['peticiones'][2]['b'] ?? '', '31-12-2000'), $r);
wa_fin();
