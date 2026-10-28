<?php
/**
 * express_baseline.php — Linea base de conteos exactos de las suites de pruebas
 * que no necesitan base de datos ni servidores. Express no puede quitar,
 * saltar ni dejar en silencio una prueba existente: cada suite imprime su
 * resumen ("N de M pruebas OK", "N/M OK", "TODO OK (N verificaciones)" o
 * "TODO OK: N comprobaciones") y esta herramienta lo guarda y lo compara.
 *
 * Las suites "fijadas" (pinned) son las que protegen lineas de produccion: su
 * total y sus fallos deben coincidir exactamente. Las demas no pueden perder
 * pruebas ni saltar mas secciones; si mejoran, solo se avisa (recapturar).
 *
 * Quedan fuera a proposito las suites que levantan servidores, necesitan una
 * base de datos o solo escriben PDFs: test_mock_dgii, test_facturas_simples,
 * test_motivo_descripcion, test_ri_norma, todo test_pos_* salvo el puro de
 * scratch, test_write_ahead_{api,ecf,gate}, test_reservar_confirmar,
 * test_*_mysql, test_*_db y test_producto_imagen (pide un API del POS y la
 * base maestra; sin ellas sale con 2).
 *
 * Archivo guardado: tools/express_golden/baseline_suites.json
 *   {version, git_sha, php, capturado, suites:{<archivo>:{total, fallos, saltadas, pinned, estado}}}
 *   estado: OK | falla_en_base | sin_resumen. php es "mayor.menor" (produccion y CI
 *   corren 8.3, el local puede ser otro: una diferencia solo avisa).
 *
 * Mismo entorno en cualquier maquina: cada suite corre con pdo_sqlite cargado
 * (BASELINE_EXTENSIONES, por -d si el php.ini no la trae). test_grupo_multiempresa salta
 * sus Partes 2 y 3 sin ella; una base capturada sin la extension (10 pruebas, 2 saltadas)
 * dejaba pasar en el CI, que si la carga, que se borraran pruebas de esas partes: el total
 * solo tenia que quedar en 10 o mas. Ahora la base y el CI miden lo mismo (28, 0 saltadas) y,
 * si la extension no se puede cargar, la suite salta y sale la diferencia, no un aviso.
 *
 * Uso (desde la raiz del API):
 *   php tools/express_baseline.php --capturar                  escribe la base completa (exige arbol limpio)
 *   php tools/express_baseline.php --comparar [--suites=a,b]   compara contra la base
 *   Salida: 0 igual, 1 diferencias, 2 rechazado (arbol sucio al capturar, sin base, uso).
 *   --suites (nombres con o sin ".php") solo sirve con --comparar: capturar siempre
 *   reescribe todas las suites, asi una base nunca queda a medias; con --capturar
 *   se rechaza (salida 2).
 *   --comparar sin --suites espera TODO lo que la base guarda, no solo lo que BASELINE_SUITES
 *   lista hoy: una suite que se quita (o se renombra) en la constante sigue esperandose y sale
 *   como "<suite>: no corrio" (salida 1) hasta que alguien recapture a proposito. Con --suites
 *   solo se espera lo pedido.
 */

require_once __DIR__ . '/write_ahead_test_lib.php';
require_once __DIR__ . '/express_p0_lib.php';

const BASELINE_SUITES = [
    ['archivo' => 'test_write_ahead_puro.php', 'pinned' => true],
    ['archivo' => 'test_integracion_alcance.php', 'pinned' => true],
    ['archivo' => 'test_pos_scratch_puro.php', 'pinned' => true],
    ['archivo' => 'test_consultar_ecf_dgii.php', 'pinned' => false],
    ['archivo' => 'test_ecf_montos_descuento.php', 'pinned' => false],
    ['archivo' => 'test_ecf_tipo_ingresos.php', 'pinned' => false],
    ['archivo' => 'test_integracion_xml.php', 'pinned' => false],
    ['archivo' => 'test_notas_vinculadas.php', 'pinned' => false],
    ['archivo' => 'test_precios_itbis_incluido.php', 'pinned' => false],
    ['archivo' => 'test_send_fase2_espera.php', 'pinned' => false],
    ['archivo' => 'test_tenant_mail.php', 'pinned' => false],
    ['archivo' => 'test_conduces.php', 'pinned' => false],
    ['archivo' => 'test_cotizacion_ferreteria.php', 'pinned' => false],
    ['archivo' => 'test_inventario_stock.php', 'pinned' => false],
    ['archivo' => 'test_grupo_multiempresa.php', 'pinned' => false],
];

/**
 * Extensiones con las que corre cada suite (wa_php_flags agrega un -d solo a las que el
 * php.ini no carga). Las tres de siempre mas pdo_sqlite, de la que depende
 * test_grupo_multiempresa: ver la cabecera.
 */
const BASELINE_EXTENSIONES = ['curl', 'openssl', 'pdo_mysql', 'pdo_sqlite'];

/** Segundos que se le da a una suite antes de matarla. */
const BASELINE_TIMEOUT = 300;

/** Ruta de la base guardada, relativa a la raiz del API. */
const BASELINE_ARCHIVO = 'tools/express_golden/baseline_suites.json';

/**
 * Resumen de la salida de una suite: ['total', 'fallos', 'saltadas'] o null si
 * no hay linea de resumen (un exit 0 mudo no es verde). Cada patron va anclado
 * al inicio de la linea, con un "== " opcional delante, para que un "3/3 OK"
 * dentro de la descripcion de una prueba no cuente; gana la ULTIMA linea que
 * coincida. Las secciones "(SALTADA: ...)" se cuentan aparte.
 *
 * @return array{total: int, fallos: int, saltadas: int}|null
 */
function baseline_resumen(string $salida): ?array
{
    $resumen = null;
    $saltadas = 0;
    foreach (preg_split('/\R/', $salida) ?: [] as $linea) {
        if (preg_match('/^\s*\(SALTADA\b/', $linea) === 1) {
            $saltadas++;
            continue;
        }
        if (preg_match('/^(?:== )?(\d+) de (\d+) FALLARON\b/', $linea, $m) === 1) {
            $resumen = ['total' => (int) $m[2], 'fallos' => (int) $m[1]];
        } elseif (preg_match('/^(?:== )?(\d+) de (\d+) pruebas OK\b/', $linea, $m) === 1
            || preg_match('/^(?:== )?(\d+)\/(\d+) OK\b/', $linea, $m) === 1) {
            $resumen = ['total' => (int) $m[2], 'fallos' => (int) $m[2] - (int) $m[1]];
        } elseif (preg_match('/^(?:== )?TODO OK \((\d+) verificaciones\)/', $linea, $m) === 1
            || preg_match('/^(?:== )?TODO OK: (\d+) comprobaciones\b/', $linea, $m) === 1) {
            $resumen = ['total' => (int) $m[1], 'fallos' => 0];
        }
    }
    return $resumen === null ? null : $resumen + ['saltadas' => $saltadas];
}

/**
 * Compara los conteos de la base ($esperado) con los de ahora ($actual); los dos
 * son mapas "<archivo>" => {total, fallos, saltadas, pinned, estado}. La clave
 * "pinned" que vale es la de la base.
 *
 *   - Una suite que no esta en $actual no corrio: diferencia.
 *   - Sin resumen, o con fallos, o con salida distinta de 0: diferencia.
 *   - Fijada: el total debe ser exactamente el de la base.
 *   - No fijada: no puede perder pruebas; si sube, solo se avisa (recapturar).
 *   - Ninguna puede saltar mas secciones; si salta menos, se avisa.
 *   - Una suite que ya no estaba en OK en la base solo da un aviso.
 *   - Otro PHP que en la base: aviso con las dos versiones (nunca diferencia).
 *
 * @param array<string, array<string, mixed>> $esperado
 * @param array<string, array<string, mixed>> $actual
 * @return array{ok: bool, diferencias: list<string>, avisos: list<string>}
 */
function baseline_comparar(array $esperado, array $actual, string $phpEsperado, string $phpActual): array
{
    $dif = [];
    $avisos = [];
    if ($phpEsperado !== $phpActual) {
        $avisos[] = "PHP {$phpEsperado} en la base, {$phpActual} ahora";
    }
    foreach ($esperado as $suite => $base) {
        $ahora = $actual[$suite] ?? null;
        if ($ahora === null) {
            $dif[] = "{$suite}: no corrio";
            continue;
        }
        $estadoBase = (string) ($base['estado'] ?? 'OK');
        if ($estadoBase !== 'OK') {
            $avisos[] = ($ahora['estado'] ?? '') === 'OK'
                ? "{$suite}: mejoro (recapturar)"
                : "{$suite}: ya estaba en {$estadoBase} en la base (solo aviso)";
            continue;
        }
        if (($ahora['estado'] ?? '') === 'sin_resumen') {
            $dif[] = "{$suite}: sin resumen";
            continue;
        }
        $fallos = (int) ($ahora['fallos'] ?? 0);
        if ($fallos > 0) {
            $dif[] = "{$suite}: fallos {$fallos}";
        } elseif (($ahora['estado'] ?? '') !== 'OK') {
            $dif[] = "{$suite}: salio con error";
        }
        $mejoro = false;
        $totalBase = (int) ($base['total'] ?? 0);
        $totalAhora = (int) ($ahora['total'] ?? 0);
        if ($totalAhora < $totalBase || (!empty($base['pinned']) && $totalAhora !== $totalBase)) {
            $dif[] = "{$suite}: total {$totalBase} -> {$totalAhora}";
        } elseif ($totalAhora > $totalBase) {
            $mejoro = true;
        }
        $saltBase = (int) ($base['saltadas'] ?? 0);
        $saltAhora = (int) ($ahora['saltadas'] ?? 0);
        if ($saltAhora > $saltBase) {
            $dif[] = "{$suite}: saltadas {$saltBase} -> {$saltAhora}";
        } elseif ($saltAhora < $saltBase) {
            $mejoro = true;
        }
        if ($mejoro) {
            $avisos[] = "{$suite}: mejoro (recapturar)";
        }
    }
    foreach (array_diff_key($actual, $esperado) as $suite => $_) {
        $avisos[] = "{$suite}: sin base (recapturar)";
    }
    return ['ok' => $dif === [], 'diferencias' => $dif, 'avisos' => $avisos];
}

/**
 * Corre una suite como PHP_BINARY + wa_php_flags(BASELINE_EXTENSIONES) + archivo, con
 * $raiz de directorio de trabajo, y devuelve su registro {total, fallos, saltadas,
 * pinned, estado}. Salida y errores van a un archivo temporal (sin pipes: en
 * Windows no hay lecturas con tiempo limite) y se mata la suite pasado
 * BASELINE_TIMEOUT segundos. estado: OK solo con codigo 0, resumen y sin fallos.
 *
 * @param array{archivo: string, pinned: bool} $suite
 * @return array{total: int, fallos: int, saltadas: int, pinned: bool, estado: string}
 */
function baseline_correr(array $suite, string $raiz): array
{
    $tmp = (string) tempnam(sys_get_temp_dir(), 'bl_');
    $fh = fopen($tmp, 'w');
    $codigo = -1;
    if ($fh !== false) {
        $proc = proc_open(
            array_merge([PHP_BINARY], wa_php_flags(BASELINE_EXTENSIONES), [$raiz . '/tools/' . $suite['archivo']]),
            [0 => ['pipe', 'r'], 1 => $fh, 2 => $fh],
            $pipes,
            $raiz
        );
        if (is_resource($proc)) {
            fclose($pipes[0]);
            $limite = microtime(true) + BASELINE_TIMEOUT;
            while (true) {
                $st = proc_get_status($proc);
                if (!$st['running']) {
                    $codigo = (int) $st['exitcode'];
                    break;
                }
                if (microtime(true) > $limite) {
                    proc_terminate($proc);
                    break;
                }
                usleep(100000);
            }
            proc_close($proc);
        }
        fclose($fh);
    }
    $salida = (string) file_get_contents($tmp);
    @unlink($tmp);
    $r = baseline_resumen($salida);
    $estado = $r === null ? 'sin_resumen' : ($codigo === 0 && $r['fallos'] === 0 ? 'OK' : 'falla_en_base');
    return [
        'total' => $r['total'] ?? 0,
        'fallos' => $r['fallos'] ?? 0,
        'saltadas' => $r['saltadas'] ?? 0,
        'pinned' => $suite['pinned'],
        'estado' => $estado,
    ];
}

/** "mayor.menor" del PHP que corre esto: lo que se guarda y se compara. */
function baseline_php(): string
{
    return PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
}

/** Una fila legible por suite. */
function baseline_fila(string $archivo, array $r): string
{
    return sprintf(
        "  %-36s total %4d  fallos %d  saltadas %d  %s%s",
        $archivo, $r['total'], $r['fallos'], $r['saltadas'], $r['estado'], $r['pinned'] ? '  (fijada)' : ''
    );
}

/** Suites pedidas con --suites (o todas); null si alguna no existe. */
function baseline_elegir(?string $pedidas): ?array
{
    if ($pedidas === null || $pedidas === '') {
        return BASELINE_SUITES;
    }
    $elegidas = [];
    foreach (explode(',', $pedidas) as $nombre) {
        $nombre = trim($nombre);
        $nombre .= str_ends_with($nombre, '.php') ? '' : '.php';
        $hallada = null;
        foreach (BASELINE_SUITES as $s) {
            if ($s['archivo'] === $nombre) {
                $hallada = $s;
            }
        }
        if ($hallada === null) {
            fwrite(STDERR, "Suite desconocida: {$nombre}\n");
            return null;
        }
        $elegidas[$nombre] = $hallada;
    }
    return array_values($elegidas);
}

/** Lee la base guardada; null (con el motivo en stderr) si falta o no sirve. */
function baseline_leer(string $ruta): ?array
{
    if (!is_file($ruta)) {
        fwrite(STDERR, "No hay linea base en " . BASELINE_ARCHIVO . ". Corre primero: php tools/express_baseline.php --capturar\n");
        return null;
    }
    $json = json_decode((string) file_get_contents($ruta), true);
    if (!is_array($json) || ($json['version'] ?? null) !== 1 || !is_array($json['suites'] ?? null)) {
        fwrite(STDERR, "La linea base " . BASELINE_ARCHIVO . " no tiene la forma esperada (version 1).\n");
        return null;
    }
    return $json;
}

/** Captura TODAS las suites de BASELINE_SUITES y escribe la base completa. */
function baseline_capturar(string $raiz): never
{
    $sha = p0_git(['rev-parse', 'HEAD'], $raiz);
    if ($sha === '') {
        fwrite(STDERR, "Rechazado: {$raiz} no es un repositorio git (o git no responde).\n");
        exit(2);
    }
    $sucio = p0_git(['status', '--porcelain'], $raiz);
    if (!p0_arbol_limpio($sucio, ['tools/express_golden/'])) {
        fwrite(STDERR, "Rechazado: el arbol tiene cambios sin confirmar, y la base debe describir un commit.\n{$sucio}\n");
        exit(2);
    }
    $ruta = $raiz . '/' . BASELINE_ARCHIVO;
    echo "PHP " . PHP_VERSION . " | " . substr($sha, 0, 7) . "\n";
    $ordenadas = [];
    foreach (BASELINE_SUITES as $s) {
        $ordenadas[$s['archivo']] = baseline_correr($s, $raiz);
        echo baseline_fila($s['archivo'], $ordenadas[$s['archivo']]) . "\n";
    }
    $doc = ['version' => 1, 'git_sha' => $sha, 'php' => baseline_php(), 'capturado' => date('c'), 'suites' => $ordenadas];
    if (!is_dir(dirname($ruta)) && !mkdir(dirname($ruta), 0777, true)) {
        fwrite(STDERR, "No se pudo crear " . dirname($ruta) . "\n");
        exit(2);
    }
    file_put_contents($ruta, json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    foreach ($ordenadas as $archivo => $r) {
        if ($r['estado'] !== 'OK') {
            echo "AVISO {$archivo}: estado {$r['estado']} en la base (no se arregla aqui)\n";
        }
    }
    echo "Línea base escrita: " . BASELINE_ARCHIVO . "\n";
    exit(0);
}

/**
 * Lo que la base guardada espera de esta corrida: sus entradas ($guardadas, el
 * "suites" del JSON) cruzadas con las suites que se corrieron.
 *
 *   - $completa (--comparar sin --suites): TODAS las entradas guardadas, no solo las que
 *     BASELINE_SUITES lista hoy. Una suite que se quito de la constante (o se renombro)
 *     sigue esperandose y baseline_comparar la da como "<suite>: no corrio": una prueba
 *     no puede desaparecer en silencio (Review Focus 3).
 *   - Con --suites: solo las pedidas que la base conoce. Una pedida que la base no conoce
 *     se corre igual y baseline_comparar la avisa como "sin base".
 *
 * @param array<string, array<string, mixed>> $guardadas
 * @param list<array{archivo: string, pinned: bool}> $suites las que se corren
 * @return array<string, array<string, mixed>>
 */
function baseline_esperado(array $guardadas, array $suites, bool $completa): array
{
    if ($completa) {
        return $guardadas;
    }
    $esperado = [];
    foreach ($suites as $s) {
        if (isset($guardadas[$s['archivo']])) {
            $esperado[$s['archivo']] = $guardadas[$s['archivo']];
        }
    }
    return $esperado;
}

/**
 * @param list<array{archivo: string, pinned: bool}> $suites las que se corren
 * @param bool $completa true sin --suites: ver baseline_esperado
 */
function baseline_comparar_cli(string $raiz, array $suites, bool $completa = false): never
{
    $base = baseline_leer($raiz . '/' . BASELINE_ARCHIVO);
    if ($base === null) {
        exit(2);
    }
    $actual = [];
    echo "PHP " . PHP_VERSION . "\n";
    foreach ($suites as $s) {
        $actual[$s['archivo']] = baseline_correr($s, $raiz);
        echo baseline_fila($s['archivo'], $actual[$s['archivo']]) . "\n";
    }
    $esperado = baseline_esperado($base['suites'], $suites, $completa);
    $cmp = baseline_comparar($esperado, $actual, (string) ($base['php'] ?? ''), baseline_php());
    foreach ($cmp['avisos'] as $a) {
        echo "AVISO {$a}\n";
    }
    foreach ($cmp['diferencias'] as $d) {
        echo "DIFERENCIA {$d}\n";
    }
    echo $cmp['ok'] ? "Sin diferencias con la linea base.\n" : count($cmp['diferencias']) . " diferencia(s) con la linea base.\n";
    exit($cmp['ok'] ? 0 : 1);
}

// CLI propio: solo cuando se ejecuta este archivo, nunca al incluirlo.
if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $raiz = dirname(__DIR__);
    $pedidas = wa_arg('suites');
    if (wa_arg('capturar') !== null) {
        if ($pedidas !== null) {
            fwrite(STDERR, "Rechazado: --capturar siempre escribe la base completa; --suites solo sirve con --comparar.\n");
            exit(2);
        }
        baseline_capturar($raiz);
    }
    $suites = baseline_elegir($pedidas);
    if ($suites === null) {
        exit(2);
    }
    if (wa_arg('comparar') !== null) {
        baseline_comparar_cli($raiz, $suites, $pedidas === null || $pedidas === '');
    }
    echo "Uso: php tools/express_baseline.php --capturar | --comparar [--suites=a,b]\n";
    exit(2);
}
