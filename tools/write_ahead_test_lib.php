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
 * Flags -d para un php hijo: solo las extensiones que el php.ini NO carga.
 * Se le pregunta a un php sin flags (lo que vera el hijo), no a este proceso:
 * este pudo arrancar con -d extension=... y el hijo no lo hereda.
 */
function wa_php_flags(): array
{
    static $flags = null;
    if ($flags !== null) {
        return $flags;
    }
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
    $flags = [];
    foreach (['curl', 'openssl', 'pdo_mysql'] as $ext) {
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
 */
function wa_servidor(string $nombre, string $router, int $puerto, array $env = []): string
{
    $url = 'http://127.0.0.1:' . $puerto;
    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR;
    $anotado = $tmp . "wa_servidor_{$puerto}.txt";
    if (wa_puerto_abierto($puerto)) {
        $dueno = is_file($anotado) ? trim((string) file_get_contents($anotado)) : '';
        if ($dueno !== $nombre) {
            echo "[FALLO] el puerto {$puerto} ya lo usa otro proceso ('{$dueno}'), no '{$nombre}'. Detenlo y vuelve a correr.\n";
            exit(1);
        }
        return $url;
    }
    $log = $tmp . "wa_{$nombre}_{$puerto}.log";
    $cmd = array_merge([PHP_BINARY], wa_php_flags(), ['-d', 'variables_order=EGPCS', '-S', "127.0.0.1:{$puerto}", $router]);
    $entorno = array_merge(getenv(), array_map('strval', $env));
    $nulo = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $proc = proc_open($cmd, [0 => ['file', $nulo, 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, dirname(__DIR__), $entorno);
    if (!is_resource($proc)) {
        echo "[FALLO] no se pudo lanzar php -S para {$nombre}\n";
        exit(1);
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
    exit(1);
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
