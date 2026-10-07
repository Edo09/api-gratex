<?php
/**
 * mock_dgii.php — DGII falsa para probar el write-ahead del e-CF sin salir de
 * la maquina (spec 12.2). Es un router de "php -S":
 *
 *   php -S 127.0.0.1:8099 tools/mock_dgii.php
 *
 * y la API apunta a ella con DGII_ECF_BASE_URL = DGII_FC_BASE_URL =
 * http://127.0.0.1:8099 y DGII_ECF_TIMEOUT=3. Nunca habla con la DGII real.
 *
 * Rutas DGII (despues de /testecf, /certecf o /ecf):
 *   GET  /autenticacion/api/autenticacion/semilla         siempre 200 (XML valor/fecha)
 *   POST /autenticacion/api/autenticacion/validarsemilla  siempre 200 (token tok-mock)
 *   POST /Recepcion/api/FacturasElectronicas              segun el modo
 *   POST /RecepcionFC/api/recepcion/ecf                   segun el modo
 *   GET  /ConsultaResultado/api/Consultas/Estado          Aceptado (salvo 3 modos trackids_*)
 *   GET  /consultarfce/api/Consultas/Consulta             Aceptado (salvo 3 modos trackids_*)
 *   GET  /consultatrackids/api/TrackIds/Consulta          un trackId Aceptado (salvo 3 modos trackids_*)
 *
 * Modos (MOCK_DGII_MODE al arrancar, o GET /__mock/modo?valor=<modo>):
 *   acepta, acepta_condicional, rechaza, rechaza_usada, cuelga, html500,
 *   raw200, trackids_vacio, trackids_uno, trackids_404, trackids_rechazado.
 * Solo trackids_vacio, trackids_404 y trackids_rechazado cambian las tres
 * consultas; trackids_uno contesta como acepta (un trackId Aceptado). En los
 * cuatro trackids_* la recepcion acepta.
 * En "cuelga" la recepcion duerme MOCK_DGII_CUELGA_SEG segundos (10 por
 * defecto) y despues responde {}. php -S atiende de a una peticion: la
 * siguiente espera a que termine, por eso wa_mock_modo() sincroniza.
 *
 * El trackId es 'track-' . <eNCF del XML recibido>, para que las pruebas lo
 * puedan predecir. Toda ruta DGII queda en el log ANTES de responder.
 *
 * Control: GET /__mock/modo[?valor=<modo>], GET /__mock/log[?limpiar=1].
 * Estado en sys_get_temp_dir(): mock_dgii_<puerto>_modo.txt ("<pid> <modo>")
 * y mock_dgii_<puerto>_log.jsonl. Un servidor nuevo (otro pid) arranca con el
 * modo de MOCK_DGII_MODE y el log vacio.
 */

const MD_MODOS = [
    'acepta', 'acepta_condicional', 'rechaza', 'rechaza_usada', 'cuelga', 'html500',
    'raw200', 'trackids_vacio', 'trackids_uno', 'trackids_404', 'trackids_rechazado',
];

$puerto = (int) ($_SERVER['SERVER_PORT'] ?? 0);
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mock_dgii_' . $puerto;
$archivoModo = $tmp . '_modo.txt';
$archivoLog = $tmp . '_log.jsonl';

// Mismo pid = mismo servidor (php -S es un solo proceso). Otro pid: el estado
// en disco es de una corrida anterior y se reinicia.
$guardado = is_file($archivoModo) ? explode(' ', trim((string) file_get_contents($archivoModo)), 2) : [];
if ((int) ($guardado[0] ?? 0) !== getmypid()) {
    $inicial = getenv('MOCK_DGII_MODE') ?: 'acepta';
    $guardado = [(string) getmypid(), in_array($inicial, MD_MODOS, true) ? $inicial : 'acepta'];
    file_put_contents($archivoModo, implode(' ', $guardado), LOCK_EX);
    file_put_contents($archivoLog, '', LOCK_EX);
}
$modo = $guardado[1] ?? 'acepta';

$metodo = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$ruta = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

/** Responde JSON y termina. */
function md_json(int $status, mixed $cuerpo, string $tipo = 'application/json'): never
{
    http_response_code($status);
    header('Content-Type: ' . $tipo . '; charset=utf-8');
    echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Cuerpo recibido. Un multipart llega en $_FILES: php://input viene vacio. */
function md_cuerpo(): string
{
    $partes = [(string) file_get_contents('php://input')];
    foreach ($_FILES as $f) {
        foreach ((array) ($f['tmp_name'] ?? []) as $tmp) {
            if (is_string($tmp) && $tmp !== '' && is_file($tmp)) {
                $partes[] = (string) file_get_contents($tmp);
            }
        }
    }
    foreach ($_POST as $v) {
        if (is_string($v)) {
            $partes[] = $v;
        }
    }
    return implode("\n", $partes);
}

/** e-NCF del XML recibido, o '' si no viene. */
function md_encf(string $cuerpo): string
{
    return preg_match('/<eNCF>\s*(E[0-9]{12})\s*<\/eNCF>/i', $cuerpo, $m) ? $m[1] : '';
}

// --- Control ---------------------------------------------------------------
if ($ruta === '/__mock/modo') {
    if (isset($_GET['valor'])) {
        $nuevo = (string) $_GET['valor'];
        if (!in_array($nuevo, MD_MODOS, true)) {
            md_json(400, ['error' => 'modo desconocido: ' . $nuevo, 'modos' => MD_MODOS]);
        }
        file_put_contents($archivoModo, getmypid() . ' ' . $nuevo, LOCK_EX);
        $modo = $nuevo;
    }
    md_json(200, ['modo' => $modo]);
}
if ($ruta === '/__mock/log') {
    $filas = [];
    foreach (file($archivoLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $l) {
        $fila = json_decode($l, true);
        if (is_array($fila)) {
            $filas[] = $fila;
        }
    }
    if (isset($_GET['limpiar'])) {
        file_put_contents($archivoLog, '', LOCK_EX);
    }
    md_json(200, $filas);
}

// --- Rutas DGII -------------------------------------------------------------
if (!preg_match('#^/(testecf|certecf|ecf)(/.*)$#i', $ruta, $m)) {
    md_json(404, ['error' => 'ruta desconocida del mock', 'ruta' => $ruta]);
}
$resto = strtolower($m[2]);
$conocidas = [
    '/autenticacion/api/autenticacion/semilla' => 'GET',
    '/autenticacion/api/autenticacion/validarsemilla' => 'POST',
    '/recepcion/api/facturaselectronicas' => 'POST',
    '/recepcionfc/api/recepcion/ecf' => 'POST',
    '/consultaresultado/api/consultas/estado' => 'GET',
    '/consultarfce/api/consultas/consulta' => 'GET',
    '/consultatrackids/api/trackids/consulta' => 'GET',
];
if (($conocidas[$resto] ?? null) !== $metodo) {
    md_json(404, ['error' => 'ruta desconocida del mock', 'ruta' => $ruta, 'metodo' => $metodo]);
}

// El log va ANTES de responder: si la peticion cuelga o el cliente corta, la
// prueba igual ve que la DGII "recibio" algo.
file_put_contents($archivoLog, json_encode([
    'hora' => date('Y-m-d H:i:s'), 'metodo' => $metodo, 'ruta' => $ruta, 'modo' => $modo,
], JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);

$ahora = new DateTimeImmutable();
$fechaRecepcion = $ahora->format('n/j/Y g:i:s A');   // ConsultaResultado: M/d/yyyy h:mm:ss tt
$mensajesOk = [['valor' => '', 'codigo' => 0]];
$rechazo = [['valor' => 'Rechazo de prueba del mock DGII', 'codigo' => 999]];

switch ($resto) {
    case '/autenticacion/api/autenticacion/semilla':
        http_response_code(200);
        header('Content-Type: text/xml; charset=utf-8');
        echo '<?xml version="1.0" encoding="utf-8"?>'
            . '<SemillaModel xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<valor>' . bin2hex(random_bytes(16)) . '</valor>'
            . '<fecha>' . $ahora->format('Y-m-d\TH:i:s.vP') . '</fecha></SemillaModel>';
        exit;

    case '/autenticacion/api/autenticacion/validarsemilla':
        md_json(200, [
            'token' => 'tok-mock',
            'expira' => $ahora->modify('+1 hour')->format(DATE_ATOM),
            'expedido' => $ahora->format(DATE_ATOM),
        ]);

    case '/recepcion/api/facturaselectronicas':
    case '/recepcionfc/api/recepcion/ecf':
        $eNcf = md_encf(md_cuerpo());
        $esFc = $resto === '/recepcionfc/api/recepcion/ecf';
        // RecepcionFC (RFCE) no devuelve trackId; Recepcion si.
        $base = ($esFc ? [] : ['trackId' => 'track-' . $eNcf]) + ['encf' => $eNcf];
        switch ($modo) {
            case 'acepta_condicional':
                md_json(200, $base + ['codigo' => 4, 'estado' => 'Aceptado Condicional', 'secuenciaUtilizada' => true, 'mensajes' => $mensajesOk]);
            case 'rechaza':
                md_json(400, $base + ['codigo' => 2, 'estado' => 'Rechazado', 'secuenciaUtilizada' => false, 'mensajes' => $rechazo]);
            case 'rechaza_usada':
                md_json(400, $base + ['codigo' => 2, 'estado' => 'Rechazado', 'secuenciaUtilizada' => true, 'mensajes' => $rechazo]);
            case 'cuelga':
                sleep(max(1, (int) (getenv('MOCK_DGII_CUELGA_SEG') ?: 10)));
                md_json(200, new stdClass());
            case 'html500':
                http_response_code(500);
                header('Content-Type: text/html; charset=utf-8');
                echo '<html><head><title>500</title></head><body>'
                    . str_repeat('<p>Servicio de la DGII no disponible (mock).</p>', 4600)
                    . '</body></html>';
                exit;
            case 'raw200':
                http_response_code(200);
                header('Content-Type: text/plain; charset=utf-8');
                echo 'Recibido';
                exit;
            default:   // acepta y los trackids_*: la recepcion acepta
                md_json(200, $base + ['codigo' => 1, 'estado' => 'Aceptado', 'secuenciaUtilizada' => true, 'mensajes' => $mensajesOk]);
        }

    case '/consultaresultado/api/consultas/estado':
    case '/consultarfce/api/consultas/consulta':
        $eNcf = (string) ($_GET['encf'] ?? $_GET['ENCF'] ?? '');
        $base = ['trackId' => (string) ($_GET['trackId'] ?? ('track-' . $eNcf)), 'rnc' => (string) ($_GET['rnc'] ?? $_GET['RNC_Emisor'] ?? ''),
            'encf' => $eNcf, 'fechaRecepcion' => $fechaRecepcion];
        if ($modo === 'trackids_rechazado') {
            md_json(200, $base + ['codigo' => '2', 'estado' => 'Rechazado', 'secuenciaUtilizada' => false, 'mensajes' => $rechazo]);
        }
        if ($modo === 'trackids_vacio' || $modo === 'trackids_404') {
            md_json(200, $base + ['codigo' => '0', 'estado' => 'No Encontrado', 'secuenciaUtilizada' => false, 'mensajes' => $mensajesOk]);
        }
        md_json(200, $base + ['codigo' => '1', 'estado' => 'Aceptado', 'secuenciaUtilizada' => true, 'mensajes' => $mensajesOk]);

    case '/consultatrackids/api/trackids/consulta':
        $eNcf = (string) ($_GET['Encf'] ?? $_GET['encf'] ?? '');
        // Misma forma que la respuesta real guardada en tools/test_consultar_ecf_dgii.php:
        // lista de {trackId, estado, fechaRecepcion dd/MM/yyyy}.
        $uno = ['trackId' => 'track-' . $eNcf, 'estado' => 'Aceptado', 'fechaRecepcion' => $ahora->format('d/m/Y')];
        if ($modo === 'trackids_vacio') {
            md_json(200, []);
        }
        if ($modo === 'trackids_404') {
            md_json(404, ['type' => 'https://tools.ietf.org/html/rfc7231#section-6.5.4', 'title' => 'Not Found',
                'status' => 404, 'traceId' => '00-mock-00'], 'application/problem+json');
        }
        if ($modo === 'trackids_rechazado') {
            md_json(200, [['estado' => 'Rechazado'] + $uno]);
        }
        md_json(200, [$uno]);
}
md_json(404, ['error' => 'ruta desconocida del mock', 'ruta' => $ruta]);
