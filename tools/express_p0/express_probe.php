<?php
/**
 * express_probe.php — SONDA TEMPORAL del host para el spike S2 de Express
 * (limites del hosting, que pasa si el cliente se desconecta, zonas horarias).
 *
 * TEMPORAL: esta es la PLANTILLA y vive solo aqui, nunca en public/ del repo.
 * Se prepara una copia con tools/express_p0/preparar_probe.php (le pone el hash
 * del token y la hora de vencimiento), esa copia se sube a mano a public/ del
 * hosting (cPanel File Manager) y se BORRA al terminar el spike. Ademas caduca
 * sola: pasada la hora de vencimiento responde 403 aunque el archivo siga ahi.
 * Sin hash configurado (la plantilla tal cual esta en el repo) nadie entra.
 * Nada que editar en el .env del host: el hash y el vencimiento van en la copia.
 *
 * Modelo: el patron con token de public/readlog.php, pero con hash y vencimiento
 * en lugar de un token en claro en el .env.
 *
 * El token viaja en la cabecera X-Probe-Token (nunca en la URL: las URL quedan
 * en los logs del servidor). Pasos (?paso=...), siempre JSON:
 *   info                                        limites de PHP, funciones, zonas horarias, MySQL
 *   dormir&seg=N                                duerme N s (GET o POST, N de 1 a 130)
 *   limite&seg=N&id=<8 hex>                     set_time_limit(0) y espera N s con latido por segundo
 *   desconexion&seg=N&id=<8 hex>&ignorar=0|1    espera en silencio N s; ignore_user_abort(ignorar)
 *   leer&id=<8 hex>                             lee el latido que dejaron limite o desconexion
 *   latencia                                    5 GET a la semilla de la DGII (certificacion)
 *
 * Uso:
 *   curl -s -H "X-Probe-Token: $T" "https://<host>/api/public/express_probe.php?paso=info"
 *   (el handler HTTP solo corre fuera de la linea de comandos: incluir este
 *   archivo desde un script CLI solo define funciones)
 */

const PROBE_TOKEN_SHA256 = '';
const PROBE_VENCE_UNIX = 0;
const PROBE_MAX_SEG = 130;
const PROBE_URL_SEMILLA = 'https://ecf.dgii.gov.do/testecf/autenticacion/api/autenticacion/semilla';

/**
 * Quien puede entrar: hay hash configurado, la sonda no ha vencido y el sha256
 * del token recibido coincide (hash_equals). La plantilla del repo trae hash ''
 * y vencimiento 0, asi que cerrada no deja pasar a nadie.
 */
function probe_autorizado(string $hashConfigurado, int $venceUnix, string $tokenRecibido, int $ahora): bool
{
    if ($hashConfigurado === '' || $venceUnix <= $ahora) {
        return false;
    }
    return hash_equals($hashConfigurado, hash('sha256', $tokenRecibido));
}

/**
 * La copia que se sube al hosting: la plantilla con las dos constantes llenas.
 * Lleva el sha256 del token, jamas el token. Lanza si el token viene vacio o si
 * la plantilla no trae cada constante vacia exactamente una vez. Los patrones
 * van con ancla de linea para no chocar con estos mismos textos.
 */
function probe_preparar(string $plantilla, string $token, int $venceUnix): string
{
    if ($token === '') {
        throw new InvalidArgumentException('El token no puede ir vacio.');
    }
    $copia = preg_replace(
        '/^const PROBE_TOKEN_SHA256 = \'\';/m',
        "const PROBE_TOKEN_SHA256 = '" . hash('sha256', $token) . "';",
        $plantilla,
        -1,
        $nHash
    );
    if (!is_string($copia) || $nHash !== 1) {
        throw new InvalidArgumentException('La plantilla no trae la constante del hash vacia exactamente una vez.');
    }
    $copia = preg_replace(
        '/^const PROBE_VENCE_UNIX = 0;/m',
        'const PROBE_VENCE_UNIX = ' . $venceUnix . ';',
        $copia,
        -1,
        $nVence
    );
    if (!is_string($copia) || $nVence !== 1) {
        throw new InvalidArgumentException('La plantilla no trae la constante del vencimiento en 0 exactamente una vez.');
    }
    return $copia;
}

// ---------------------------------------------------------------------------
// Pasos de la sonda (solo los corre el handler HTTP del final)
// ---------------------------------------------------------------------------

/** ?seg=N como entero de 1 a PROBE_MAX_SEG; null si no sirve. */
function probe_segundos(array $q): ?int
{
    $v = $q['seg'] ?? null;
    if (!is_string($v) || !ctype_digit($v)) {
        return null;
    }
    $n = (int) $v;
    return ($n >= 1 && $n <= PROBE_MAX_SEG) ? $n : null;
}

/** ?id= de 8 hex en minusculas (nombra el archivo de latido: nada de rutas); null si no sirve. */
function probe_id(array $q): ?string
{
    $v = $q['id'] ?? null;
    return (is_string($v) && preg_match('/^[0-9a-f]{8}$/', $v) === 1) ? $v : null;
}

function probe_ruta_latido(string $id): string
{
    return rtrim(sys_get_temp_dir(), '/\\') . '/express_probe_' . $id . '.log';
}

function probe_latido(string $archivo, string $linea): void
{
    @file_put_contents($archivo, $linea . "\n", FILE_APPEND | LOCK_EX);
}

/**
 * Espera $seg segundos de reloj sin escribir nada al cliente (como una emision
 * que espera a la DGII) y deja una linea de latido por segundo. Cada espera
 * apunta al segundo exacto desde el inicio para que el desfase no se acumule.
 * Devuelve los segundos alcanzados.
 */
function probe_esperar(int $seg, string $archivo): int
{
    $inicio = microtime(true);
    $alcanzados = 0;
    for ($i = 1; $i <= $seg; $i++) {
        $falta = $inicio + $i - microtime(true);
        if ($falta > 0) {
            usleep((int) ($falta * 1000000));
        }
        $alcanzados = $i;
        probe_latido($archivo, sprintf('%s t=%d aborted=%d', date('c'), $i, connection_aborted()));
    }
    return $alcanzados;
}

/** NOW(), UTC_TIMESTAMP() y las zonas de MySQL por la conexion al master; el fallo se reporta solo con la clase de la excepcion. */
function probe_info_mysql(): array
{
    try {
        $ruta = __DIR__ . '/../src/MasterDatabase.php';
        if (!is_file($ruta)) {
            return ['error' => 'MasterDatabase.php no encontrado junto a public/'];
        }
        require_once $ruta;
        $fila = MasterDatabase::getInstance()->getConnection()->query(
            // utc_timestamp es palabra reservada de MySQL: el alias lleva otro nombre.
            'SELECT NOW() AS mysql_now, UTC_TIMESTAMP() AS mysql_utc, @@global.time_zone AS global_time_zone, '
            . '@@session.time_zone AS session_time_zone, @@system_time_zone AS system_time_zone'
        )->fetch(PDO::FETCH_ASSOC);
        return is_array($fila) ? $fila : ['error' => 'la consulta no devolvio fila'];
    } catch (Throwable $e) {
        return ['error' => get_class($e)];
    }
}

function probe_info(): array
{
    $funciones = [];
    foreach (['set_time_limit', 'ignore_user_abort', 'ini_set', 'curl_init', 'openssl_pkcs12_read'] as $f) {
        $funciones[$f] = function_exists($f);
    }
    return [
        'php_version' => PHP_VERSION,
        'sapi' => PHP_SAPI,
        'max_execution_time' => (int) ini_get('max_execution_time'),
        'disable_functions' => (string) ini_get('disable_functions'),
        'functions' => $funciones,
        'memory_limit' => (string) ini_get('memory_limit'),
        'php_default_timezone' => date_default_timezone_get(),
        'ini_date_timezone' => (string) ini_get('date.timezone'),
        'php_date_c' => date('c'),
        'mysql' => probe_info_mysql(),
    ];
}

/** Cinco GET a la semilla de la DGII: segundos de conexion, segundos totales y codigo HTTP de cada uno. */
function probe_latencia(): array
{
    if (!function_exists('curl_init')) {
        return ['error' => 'curl_init no disponible'];
    }
    $intentos = [];
    for ($i = 1; $i <= 5; $i++) {
        $ch = curl_init(PROBE_URL_SEMILLA);
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
        ]);
        curl_exec($ch);
        $intentos[] = [
            'n' => $i,
            'connect_seconds' => round((float) curl_getinfo($ch, CURLINFO_CONNECT_TIME), 3),
            'total_seconds' => round((float) curl_getinfo($ch, CURLINFO_TOTAL_TIME), 3),
            'http_code' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
            'curl_errno' => curl_errno($ch),
        ];
    }
    return ['url' => PROBE_URL_SEMILLA, 'intentos' => $intentos];
}

function probe_responder(int $codigo, array $datos): void
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n";
}

/** El handler HTTP: autoriza con la cabecera X-Probe-Token y despacha ?paso=. $q es GET y POST juntos. */
function probe_atender(array $q): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $token = (string) ($_SERVER['HTTP_X_PROBE_TOKEN'] ?? '');
    if (!probe_autorizado(PROBE_TOKEN_SHA256, PROBE_VENCE_UNIX, $token, time())) {
        // Un solo mensaje: no se distingue vencida de token malo.
        probe_responder(403, ['error' => 'no autorizado']);
        return;
    }

    $paso = (string) ($q['paso'] ?? '');
    if ($paso === 'info') {
        probe_responder(200, ['paso' => 'info'] + probe_info());
        return;
    }
    if ($paso === 'latencia') {
        probe_responder(200, ['paso' => 'latencia'] + probe_latencia());
        return;
    }
    if ($paso === 'leer') {
        $id = probe_id($q);
        if ($id === null) {
            probe_responder(400, ['error' => 'id debe ser de 8 hex en minusculas']);
            return;
        }
        $archivo = probe_ruta_latido($id);
        $lineas = is_file($archivo) ? (file($archivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
        probe_responder(200, ['paso' => 'leer', 'id' => $id, 'existe' => is_file($archivo), 'lineas' => array_slice($lineas, -300)]);
        return;
    }
    if (in_array($paso, ['dormir', 'limite', 'desconexion'], true)) {
        $seg = probe_segundos($q);
        if ($seg === null) {
            probe_responder(400, ['error' => 'seg debe ser un entero de 1 a ' . PROBE_MAX_SEG]);
            return;
        }
        if ($paso === 'dormir') {
            sleep($seg);
            probe_responder(200, ['paso' => 'dormir', 'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''), 'slept_seconds' => $seg]);
            return;
        }
        $id = probe_id($q);
        if ($id === null) {
            probe_responder(400, ['error' => 'id debe ser de 8 hex en minusculas']);
            return;
        }
        $archivo = probe_ruta_latido($id);
        if ($paso === 'limite') {
            $antes = (int) ini_get('max_execution_time');
            $ok = function_exists('set_time_limit') ? @set_time_limit(0) : false;
            $despues = (int) ini_get('max_execution_time');
            // Sin FILE_APPEND: reusar un id empieza el archivo de cero.
            @file_put_contents($archivo, sprintf("%s INICIO limite seg=%d set_time_limit_ok=%d max_execution_time=%d->%d\n", date('c'), $seg, $ok ? 1 : 0, $antes, $despues), LOCK_EX);
            $alcanzados = probe_esperar($seg, $archivo);
            probe_latido($archivo, sprintf('%s FIN t=%d', date('c'), $alcanzados));
            probe_responder(200, [
                'paso' => 'limite',
                'id' => $id,
                'set_time_limit_ok' => (bool) $ok,
                'max_execution_time_before' => $antes,
                'max_execution_time_after' => $despues,
                'reached_seconds' => $alcanzados,
            ]);
            return;
        }
        // desconexion: bucle en silencio (nada al cliente hasta el final) para que
        // PHP no se entere de que el cliente se fue por una escritura fallida.
        $ignorar = (string) ($q['ignorar'] ?? '');
        if ($ignorar !== '0' && $ignorar !== '1') {
            probe_responder(400, ['error' => 'ignorar debe ser 0 o 1']);
            return;
        }
        if (function_exists('ignore_user_abort')) {
            ignore_user_abort($ignorar === '1');
        }
        @file_put_contents($archivo, sprintf("%s INICIO desconexion seg=%d ignorar=%s\n", date('c'), $seg, $ignorar), LOCK_EX);
        $alcanzados = probe_esperar($seg, $archivo);
        probe_latido($archivo, sprintf('%s FIN t=%d aborted=%d', date('c'), $alcanzados, connection_aborted()));
        probe_responder(200, ['paso' => 'desconexion', 'id' => $id, 'ignorar' => (int) $ignorar, 'reached_seconds' => $alcanzados]);
        return;
    }
    probe_responder(400, ['error' => 'paso desconocido', 'pasos' => ['info', 'dormir', 'limite', 'desconexion', 'leer', 'latencia']]);
}

// Handler HTTP: solo fuera de la linea de comandos, para poder incluir este archivo desde un script de prueba.
if (PHP_SAPI !== 'cli') {
    probe_atender(array_merge($_POST, $_GET));
}
