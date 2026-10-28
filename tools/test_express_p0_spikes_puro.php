<?php
/**
 * test_express_p0_spikes_puro.php — pruebas puras (sin red, sin base de datos,
 * sin servidor) de las herramientas de los spikes de la fase P0 de Express.
 * Cubre la sonda del host (S2): quien puede entrar, como se prepara la copia
 * que se sube a mano al hosting (y lo que se niega a preparar), que la sonda
 * avise cuando no pudo escribir su latido y lo que valida de ?seg= y ?id=; y los
 * armadores de payload y las guardas del script de los spikes contra la DGII
 * (S1, S2c, S3): las opciones, la regla de https para el token y --seco. Las
 * ramas de ese script que llaman a un API (sin token, ambiente rechazado) y los
 * CLI de la linea base y del golden las prueba, con procesos reales,
 * tools/test_express_p0_guardas.php. Las secciones de los demas spikes se
 * agregan arriba de wa_fin().
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

require_once __DIR__ . '/express_p0/spike_e34_certecf.php';
echo "\n== 2) spikes DGII (S1, S2c, S3) ==\n";
$o = spike_payload_original(7);
wa_chk('E31 a client_id 7, una linea libre de 1000', $o['tipo_ecf'] === '31' && $o['client_id'] === 7 && count($o['items']) === 1 && (float) $o['items'][0]['precio_unitario'] === 1000.0 && !isset($o['items'][0]['product_id']));
$n = spike_payload_nota(7, 'E310000000001', '27-10-2026');
wa_chk('E34 codigo 1, rnc_otro_contribuyente null, mismas lineas', $n['tipo_ecf'] === '34' && $n['items'] === $o['items'] && $n['informacion_referencia'] === ['ncf_modificado' => 'E310000000001', 'rnc_otro_contribuyente' => null, 'fecha_ncf_modificado' => '27-10-2026', 'codigo_modificacion' => '1', 'razon_modificacion' => 'Spike P0: anulacion']);
wa_chk('sin fecha forzada no va fecha_vencimiento_secuencia', !array_key_exists('fecha_vencimiento_secuencia', $n));
wa_chk('con fecha forzada va tal cual', spike_payload_nota(7, 'E310000000001', '27-10-2026', '31-12-2000')['fecha_vencimiento_secuencia'] === '31-12-2000');
wa_chk('solo certecf y testecf se emiten', spike_ambiente_permitido('certecf') && spike_ambiente_permitido('testecf') && !spike_ambiente_permitido('ecf') && !spike_ambiente_permitido(null));
wa_chk('rechazo forzado: solo RECHAZADO vale, un ACEPTADO deja el spike inconcluso', spike_rechazo_valido('RECHAZADO') && !spike_rechazo_valido('ACEPTADO') && !spike_rechazo_valido('ERROR') && !spike_rechazo_valido(null));
$sinAcept = spike_resultado_sin_rechazo('ACEPTADO');
wa_chk('forzada ACEPTADA: INCONCLUSO, la DGII acepto la secuencia de 2000', $sinAcept['veredicto'] === 'inconcluso' && $sinAcept['mensaje'] === 'INCONCLUSO: la DGII acepto la secuencia de 2000');
$sinOtros = true;
foreach (['ERROR', 'ACEPTADO_CONDICIONAL', 'EN_PROCESO', 'ENVIADO', 'NO_ENCONTRADO'] as $est) {
    $r = spike_resultado_sin_rechazo($est);
    $sinOtros = $sinOtros && $r['veredicto'] === 'sin_veredicto' && str_starts_with($r['mensaje'], 'SIN VEREDICTO') && str_contains($r['mensaje'], 'estado_dgii=' . $est)
        && !str_contains($r['mensaje'], 'INCONCLUSO') && !str_contains($r['mensaje'], 'acepto la secuencia');
}
wa_chk('forzada ERROR, en proceso o condicional: SIN VEREDICTO con el estado observado, sin decir que la DGII acepto', $sinOtros);
$sinNull = spike_resultado_sin_rechazo(null);
wa_chk('forzada sin estado conocido: SIN VEREDICTO, estado desconocido', $sinNull['veredicto'] === 'sin_veredicto' && str_contains($sinNull['mensaje'], 'estado_dgii=desconocido') && !str_contains($sinNull['mensaje'], 'INCONCLUSO'));
$lat = json_decode(str_replace('__CLIENT_ID__', '7', (string) file_get_contents(__DIR__ . '/express_p0/e31_minimo.json')), true);
wa_chk('payload de latencia valido tras sustituir client_id', ($lat['tipo_ecf'] ?? '') === '31' && ($lat['client_id'] ?? 0) === 7 && count($lat['items'] ?? []) === 1);

// ---------------------------------------------------------------------------
// 3) Las guardas del script de los spikes: lo que protege el token de la app y los numeros de
//    secuencia de la DGII antes de que algo salga. spike_opciones es pura; spike_cli se prueba aqui
//    solo con --seco y con opciones malas (nunca llega a la red); sus ramas con red van en
//    tools/test_express_p0_guardas.php, contra un servidor local.
// ---------------------------------------------------------------------------
echo "\n== 3) spike_opciones, https y --seco ==\n";
$api = '--api=https://spike.example.test/api';
$ok7 = ['--client-id=7', '--fase=original'];
$es = static fn (array|string $r, string $trozo): bool => is_string($r) && str_contains($r, $trozo);
$r = spike_opciones([$api, ...$ok7]);
wa_chk('opciones validas de la fase original', is_array($r) && $r['fase'] === 'original' && $r['client_id'] === 7 && $r['api'] === 'https://spike.example.test/api' && $r['original'] === null && $r['seco'] === false && $r['forzar_rechazo'] === false, $r);
$r = spike_opciones(['--api=https://spike.example.test/api/', '--client-id=7', '--fase=reintento', '--original=E310000000001', '--seco']);
wa_chk('la barra final de --api se quita y --seco se lee', is_array($r) && $r['api'] === 'https://spike.example.test/api' && $r['seco'] === true, $r);
$r = spike_opciones(['--token=TOKEN-QUE-NO-DEBE-SALIR', $api, ...$ok7]);
wa_chk('un argumento desconocido devuelve solo su nombre, nunca el valor (podria ser un token)', $r === 'Argumento desconocido: --token', $r);
$r = spike_opciones(['TOKEN-SUELTO-QUE-NO-DEBE-SALIR', $api, ...$ok7]);
wa_chk('un valor suelto tampoco se repite', $es($r, 'Argumento desconocido') && !str_contains((string) $r, 'TOKEN-SUELTO'), $r);
wa_chk('--api: obligatorio y debe terminar en /api',
    $es(spike_opciones($ok7), '--api es obligatorio') && $es(spike_opciones(['--api=https://h.example.test/otra', ...$ok7]), '--api es obligatorio')
    && $es(spike_opciones(['--api=https://h.example.test', ...$ok7]), '--api es obligatorio') && $es(spike_opciones(['--api=ftp://h.example.test/api', ...$ok7]), '--api es obligatorio')
    && $es(spike_opciones(["--api=https://h.example.test/api\n", ...$ok7]), '--api es obligatorio'));
$seguras = ['https://gratex.example.test/api', 'https://h.example.test:8443/sub/api', 'https://[2001:db8::1]/api', 'http://127.0.0.1/api', 'http://127.0.0.1:8787/api', 'http://localhost:8080/api', 'http://LOCALHOST/api', 'http://[::1]:8080/api'];
$aceptadas = array_filter($seguras, static fn (string $u): bool => is_array(spike_opciones(['--api=' . $u, ...$ok7])));
wa_chk('--api: https a cualquier host y http solo a 127.0.0.1, localhost o [::1]', array_values($aceptadas) === $seguras, array_values(array_diff($seguras, $aceptadas)));
$inseguras = ['http://gratex.example.test/api', 'http://10.0.0.5/api', 'http://127.0.0.2/api', 'http://[::2]/api', 'http://127.0.0.1.example.test/api', 'http://localhost.example.test/api',
    'http://127.0.0.1@example.test/api', 'http://localhost:80@example.test/api', 'https://usuario@gratex.example.test/api', 'http://127.0.0.1%2f@example.test/api'];
$rechazadas = array_filter($inseguras, static function (string $u) use ($ok7): bool {
    $r = spike_opciones(['--api=' . $u, ...$ok7]);
    return is_string($r) && str_contains($r, '--api debe ser https') && !str_contains($r, 'example.test') && !str_contains($r, '10.0.0.5');
});
wa_chk('--api: http hacia otro equipo (o con usuario@ delante, que lo disfraza de loopback) se rechaza sin repetir la URL', array_values($rechazadas) === $inseguras, array_values(array_diff($inseguras, $rechazadas)));
wa_chk('spike_url_segura mira solo el origen: con ruta, consulta o fragmento', spike_url_segura('http://127.0.0.1:8787/api/facturas/stats?x=1') && spike_url_segura('https://h.example.test/api#f')
    && !spike_url_segura('http://h.example.test/api') && !spike_url_segura('ftp://127.0.0.1/api') && !spike_url_segura('127.0.0.1/api') && !spike_url_segura(''));
$malosCliente = ['0', '-1', 'abc', '07', '1.5', '', "7\n"];
wa_chk('--client-id: entero positivo, sin ceros a la izquierda ni salto de linea',
    array_values(array_filter($malosCliente, static fn (string $v): bool => $es(spike_opciones([$api, "--client-id={$v}", '--fase=original']), '--client-id es obligatorio'))) === $malosCliente
    && $es(spike_opciones([$api, '--fase=original']), '--client-id es obligatorio'));
wa_chk('--fase: solo original, nota o reintento', $es(spike_opciones([$api, '--client-id=7']), '--fase es obligatoria') && $es(spike_opciones([$api, '--client-id=7', '--fase=emitir']), '--fase es obligatoria')
    && $es(spike_opciones([$api, '--client-id=7', '--fase=']), '--fase es obligatoria'));
wa_chk('la fase original no admite --original, --fecha-original ni --forzar-rechazo',
    $es(spike_opciones([$api, ...$ok7, '--original=E310000000001']), 'La fase original no usa') && $es(spike_opciones([$api, ...$ok7, '--fecha-original=27-10-2026']), 'La fase original no usa')
    && $es(spike_opciones([$api, ...$ok7, '--forzar-rechazo']), 'La fase original no usa'));
$malosEncf = ['e310000000001', 'E31000000000', 'E3100000000011', 'E31000000000x', '310000000001', 'E 310000000001', "E310000000001\n"];
wa_chk('nota y reintento exigen --original=E y 12 digitos (un e-NCF mal escrito emitiria una nota contra nada)',
    array_values(array_filter($malosEncf, static fn (string $v): bool => $es(spike_opciones([$api, '--client-id=7', '--fase=nota', "--original={$v}"]), 'necesita --original'))) === $malosEncf
    && $es(spike_opciones([$api, '--client-id=7', '--fase=reintento']), 'necesita --original') && is_array(spike_opciones([$api, '--client-id=7', '--fase=nota', '--original=E310000000001'])));
wa_chk('--fecha-original va como DD-MM-YYYY',
    $es(spike_opciones([$api, '--client-id=7', '--fase=nota', '--original=E310000000001', '--fecha-original=2026-10-27']), 'DD-MM-YYYY')
    && $es(spike_opciones([$api, '--client-id=7', '--fase=nota', '--original=E310000000001', '--fecha-original=27-10-26']), 'DD-MM-YYYY')
    && $es(spike_opciones([$api, '--client-id=7', '--fase=nota', '--original=E310000000001', "--fecha-original=27-10-2026\n"]), 'DD-MM-YYYY')
    && is_array(spike_opciones([$api, '--client-id=7', '--fase=nota', '--original=E310000000001', '--fecha-original=27-10-2026'])));
wa_chk('--forzar-rechazo vale en nota; reintento ya lo fuerza y lo rechaza',
    is_array(spike_opciones([$api, '--client-id=7', '--fase=nota', '--original=E310000000001', '--forzar-rechazo']))
    && $es(spike_opciones([$api, '--client-id=7', '--fase=reintento', '--original=E310000000001', '--forzar-rechazo']), 'ya fuerza el rechazo'));

// spike_cli en proceso: --seco y las opciones malas no llegan a la red. El host .invalid no resuelve nunca: si algo
// intentara llamar, saldria la linea "ambiente" (o el rechazo de spike_ambiente_ok) y el codigo no seria el esperado.
putenv('EXPRESS_P0_TOKEN');
$cli = static function (array $args): array {
    ob_start();
    $codigo = spike_cli($args);
    return ['codigo' => $codigo, 'salida' => (string) ob_get_clean()];
};
$seco = ['--api=https://spike.invalid/api', '--client-id=7', '--seco'];
$r = $cli([...$seco, '--fase=original']);
wa_chk('--seco original: sale con 0, imprime el payload y no toca la red ni pide token', $r['codigo'] === 0 && str_contains($r['salida'], '"evento":"payload"') && str_contains($r['salida'], '"rotulo":"original"') && !str_contains($r['salida'], '"evento":"ambiente"') && !str_contains($r['salida'], 'REHUSADO') && getenv('EXPRESS_P0_TOKEN') === false, $r);
$r = $cli([...$seco, '--fase=nota', '--original=E310000000001', '--fecha-original=27-10-2026']);
wa_chk('--seco nota: un payload sin fecha_vencimiento_secuencia', $r['codigo'] === 0 && str_contains($r['salida'], '"rotulo":"nota"') && !str_contains($r['salida'], 'fecha_vencimiento_secuencia'), $r);
$r = $cli([...$seco, '--fase=nota', '--original=E310000000001', '--forzar-rechazo']);
wa_chk('--seco nota con --forzar-rechazo: lleva la secuencia vencida de 2000', $r['codigo'] === 0 && str_contains($r['salida'], '"rotulo":"nota_rechazo_forzado"') && str_contains($r['salida'], '"fecha_vencimiento_secuencia":"31-12-2000"'), $r);
$r = $cli([...$seco, '--fase=reintento', '--original=E310000000001']);
wa_chk('--seco reintento: los dos payloads, el de secuencia vencida y el normal, y nada mas', $r['codigo'] === 0 && substr_count($r['salida'], '"evento":"payload"') === 2 && str_contains($r['salida'], '"rotulo":"nota_rechazo_forzado"') && str_contains($r['salida'], '"rotulo":"nota_normal"'), $r);
$r = $cli(['--api=http://spike.invalid/api', '--client-id=7', '--fase=original', '--seco']);
wa_chk('--seco no salta la regla de https: un http hacia otro equipo sale con 2 y no imprime ningun payload', $r['codigo'] === 2 && str_contains($r['salida'], '--api debe ser https') && !str_contains($r['salida'], '"evento"'), $r);
$r = $cli(['--token=TOKEN-QUE-NO-DEBE-SALIR', ...$seco, '--fase=original']);
wa_chk('opciones malas: sale con 2, imprime el uso y no repite el valor', $r['codigo'] === 2 && str_contains($r['salida'], 'Argumento desconocido: --token') && str_contains($r['salida'], 'Uso:') && !str_contains($r['salida'], 'TOKEN-QUE-NO-DEBE-SALIR'), $r);
$r = spike_http('GET', 'http://spike.invalid/api/facturas/stats', 'TOKEN-QUE-NO-DEBE-SALIR');
wa_chk('spike_http se niega a mandar el token por http hacia otro equipo, sin tocar curl ni la red', $r['status'] === 0 && $r['json'] === null && str_contains((string) $r['error'], 'URL rechazada') && !str_contains(json_encode($r), 'TOKEN-QUE-NO-DEBE-SALIR'), $r);

// ---------------------------------------------------------------------------
// 4) La sonda: lo que valida antes de dormir o de escribir un archivo, y lo que se niega a preparar.
// ---------------------------------------------------------------------------
echo "\n== 4) sonda: ?seg=, ?id= y probe_preparar ==\n";
$segOk = true;
foreach (['1', '45', '130'] as $texto) { // lista y no mapa: una clave numerica pasaria a entero
    $segOk = $segOk && probe_segundos(['seg' => $texto]) === (int) $texto;
}
$malosSeg = ['0', '131', '1000', '', '-1', '+5', '1.5', '1e2', ' 5', '5 ', "5\n", '٣', 'abc'];
$segNo = array_values(array_filter($malosSeg, static fn (string $v): bool => probe_segundos(['seg' => $v]) !== null));
wa_chk('probe_segundos: de 1 a 130 y solo digitos; nada mas pasa (ni un espacio, un signo, un decimal ni un arreglo)',
    $segOk && $segNo === [] && probe_segundos([]) === null && probe_segundos(['seg' => ['5']]) === null && probe_segundos(['seg' => null]) === null, $segNo);
$idsOk = ['0123abcd', 'aaaa0001', 'ffffffff'];
$idsMalos = ['AAAA0001', '0123abc', '0123abcde', '../../x1', 'aaaa/001', 'aaaa000g', '', ' aaaa0001', "aaaa0001\n"];
$idNo = array_values(array_filter($idsMalos, static fn (string $v): bool => probe_id(['id' => $v]) !== null));
wa_chk('probe_id: 8 hex en minusculas y nada mas (el id nombra el archivo del latido: sin rutas, sin mayusculas, sin salto de linea)',
    array_map(static fn (string $v): ?string => probe_id(['id' => $v]), $idsOk) === $idsOk && $idNo === [] && probe_id([]) === null && probe_id(['id' => ['aaaa0001']]) === null, $idNo);
$lanza = static function (callable $f): ?string {
    try {
        $f();
    } catch (InvalidArgumentException $e) {
        return $e->getMessage();
    }
    return null;
};
$tk = 'tk-4f9a-no-sale';
$m1 = $lanza(static fn () => probe_preparar($plantilla, '', 2000));
wa_chk('probe_preparar: un token vacio lanza (no se arma una sonda sin token)', is_string($m1) && str_contains($m1, 'token'), $m1);
$m2 = $lanza(static fn () => probe_preparar($plantilla . "\nconst PROBE_TOKEN_SHA256 = '';\n", $tk, 2000));
$m3 = $lanza(static fn () => probe_preparar(str_replace("const PROBE_TOKEN_SHA256 = '';", "const PROBE_TOKEN_SHA256 = 'ya';", $plantilla), $tk, 2000));
wa_chk('probe_preparar: la constante del hash repetida o ya llena lanza (una copia con dos hashes dejaria el token que no es)', is_string($m2) && str_contains($m2, 'hash') && is_string($m3) && str_contains($m3, 'hash'), [$m2, $m3]);
$m4 = $lanza(static fn () => probe_preparar($plantilla . "\nconst PROBE_VENCE_UNIX = 0;\n", $tk, 2000));
$m5 = $lanza(static fn () => probe_preparar(str_replace('const PROBE_VENCE_UNIX = 0;', 'const PROBE_VENCE_UNIX = 5;', $plantilla), $tk, 2000));
wa_chk('probe_preparar: el vencimiento repetido o ya puesto lanza (una sonda sin vencimiento seguiria abierta)', is_string($m4) && str_contains($m4, 'vencimiento') && is_string($m5) && str_contains($m5, 'vencimiento'), [$m4, $m5]);
wa_chk('probe_preparar: ningun mensaje de error trae el token', !str_contains(implode('|', [(string) $m1, (string) $m2, (string) $m3, (string) $m4, (string) $m5]), $tk));
wa_fin();
