<?php
/**
 * test_express_p0_spikes_puro.php — pruebas puras (sin red, sin base de datos,
 * sin servidor) de las herramientas de los spikes de la fase P0 de Express.
 * Hoy cubre la sonda del host (S2): quien puede entrar, como se prepara la
 * copia que se sube a mano al hosting y que la sonda avise cuando no pudo
 * escribir su latido. Las secciones de los demas spikes se agregan arriba de
 * wa_fin().
 *
 * Uso:
 *   php tools/test_express_p0_spikes_puro.php
 */
require_once __DIR__ . '/write_ahead_test_lib.php';
require_once __DIR__ . '/express_p0/express_probe.php';
echo "\n== 1) sonda del host (S2) ==\n";
$h = hash('sha256', 'tok');   // Review Focus 5: la sonda olvidada en el host
wa_chk('sin hash configurado nadie entra', !probe_autorizado('', 4102444800, 'tok', 1000));
wa_chk('token correcto y vigente entra', probe_autorizado($h, 2000, 'tok', 1000));
wa_chk('token incorrecto no entra', !probe_autorizado($h, 2000, 'otro', 1000));
wa_chk('vencida no entra aunque el token sea el bueno', !probe_autorizado($h, 999, 'tok', 1000));
wa_chk('plantilla sin vencimiento (0) no entra', !probe_autorizado($h, 0, 'tok', 1000));
$plantilla = (string) file_get_contents(__DIR__ . '/express_p0/express_probe.php');
$copia = probe_preparar($plantilla, 'tok', 2000);
wa_chk('la copia lleva el hash y el vencimiento, nunca el token', str_contains($copia, $h) && str_contains($copia, '2000') && !str_contains($copia, "'tok'"));
wa_chk('la plantilla del repo sigue vacia', str_contains($plantilla, "const PROBE_TOKEN_SHA256 = '';") && str_contains($plantilla, 'const PROBE_VENCE_UNIX = 0;'));
wa_chk('la sonda no esta en public/ (se sube a mano y se borra)', !is_file(dirname(__DIR__) . '/public/express_probe.php'));

// Ronda de revision 1: un latido que no se pudo escribir no debe leerse como "el
// proceso murio cuando el cliente se fue" (la conclusion que el spike no puede errar).
echo "\n== 1b) la sonda dice cuando no pudo escribir el latido ==\n";
$dirOk = rtrim(sys_get_temp_dir(), '/\\');
$dirNo = $dirOk . '/express_probe_no_existe_' . bin2hex(random_bytes(4));   // no existe: no se puede escribir
$arch = $dirOk . '/express_probe_prueba_' . bin2hex(random_bytes(4)) . '.log';
$primero = probe_latido($arch, 'a', true);
$segundo = probe_latido($arch, 'b');
$escrito = is_file($arch) ? file($arch, FILE_IGNORE_NEW_LINES) : [];
@unlink($arch);
wa_chk('probe_latido: true si escribio (reinicia o anexa), false si el directorio no deja', $primero === true && $segundo === true && $escrito === ['a', 'b'] && probe_latido($dirNo . '/x.log', 'a') === false, $escrito);
$espBuena = probe_esperar(1, $arch);
@unlink($arch);
$espMala = probe_esperar(1, $dirNo . '/x.log');
wa_chk('probe_esperar cuenta los latidos que no pudo escribir', $espBuena === ['alcanzados' => 1, 'latidos_fallidos' => 0] && $espMala === ['alcanzados' => 1, 'latidos_fallidos' => 1], [$espBuena, $espMala]);
$infoPhp = probe_info_php();
$estadoNo = probe_temp_estado($dirNo);
wa_chk('info trae temp_dir, temp_writable y open_basedir; un directorio que no existe no es escribible', ($infoPhp['temp_dir'] ?? null) === sys_get_temp_dir() && is_string($infoPhp['open_basedir'] ?? null) && is_bool($infoPhp['temp_writable'] ?? null) && $estadoNo === ['temp_dir' => $dirNo, 'temp_writable' => false], [$infoPhp['temp_dir'] ?? null, $estadoNo]);
$idLeer = bin2hex(random_bytes(4));
$leerNo = probe_leer($idLeer, $dirNo);
wa_chk('leer sin latido y sin directorio escribible: inconclusivo, con la ruta resuelta', $leerNo['existe'] === false && $leerNo['temp_writable'] === false && $leerNo['inconclusivo'] === true && $leerNo['archivo'] === $dirNo . '/express_probe_' . $idLeer . '.log', $leerNo);
$leerAusente = probe_leer($idLeer);
probe_latido(probe_ruta_latido($idLeer), 'linea', true);
$leerPresente = probe_leer($idLeer);
@unlink(probe_ruta_latido($idLeer));
wa_chk('leer con directorio escribible: ausente no es inconclusivo; presente trae las lineas', $leerAusente['existe'] === false && $leerAusente['temp_writable'] === true && $leerAusente['inconclusivo'] === false && $leerPresente['existe'] === true && $leerPresente['lineas'] === ['linea'] && $leerPresente['inconclusivo'] === false, [$leerAusente, $leerPresente]);
wa_chk(
    'token: de $_SERVER, si no de las cabeceras sin importar mayusculas, si no vacio',
    probe_token_recibido(['HTTP_X_PROBE_TOKEN' => 'a'], ['X-Probe-Token' => 'b']) === 'a'
        && probe_token_recibido([], ['x-probe-token' => 'b']) === 'b'
        && probe_token_recibido([], ['Accept' => 'x']) === ''
        && probe_token_recibido([], []) === ''
);
// latencia con curl cargado, sin tocar la red: una URL malformada hace que curl falle al instante.
$lat = function_exists('curl_init') ? probe_latencia('http://', 2) : null;
wa_chk(
    'latencia: dos intentos con su forma completa (errno de curl y codigo 0 si falla)',
    $lat === null
        ? probe_latencia() === ['error' => 'curl_init no disponible']
        : (count($lat['intentos'] ?? []) === 2
            && array_keys($lat['intentos'][0]) === ['n', 'connect_seconds', 'total_seconds', 'http_code', 'curl_errno', 'curl_error']
            && $lat['intentos'][1]['n'] === 2 && $lat['intentos'][0]['http_code'] === 0 && $lat['intentos'][0]['curl_errno'] !== 0),
    $lat
);

// preparar_probe.php: incluirlo solo define funciones; su CLI corre unicamente como archivo de entrada.
ob_start();
require_once __DIR__ . '/express_p0/preparar_probe.php';
$salidaInclude = (string) ob_get_clean();
wa_chk('incluir preparar_probe.php no corre su CLI (ni imprime ni sale)', $salidaInclude === '' && function_exists('probe_preparar_cli'), $salidaInclude);
wa_chk('probe_horas: 3 por defecto, de 1 a 24; todo lo demas null', probe_horas([]) === 3 && probe_horas(['--horas=1']) === 1 && probe_horas(['--horas=24']) === 24 && probe_horas(['--horas=0']) === null && probe_horas(['--horas=25']) === null && probe_horas(['--basura']) === null);
wa_fin();
