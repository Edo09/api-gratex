<?php
// Simulador LOCAL de la DGII para probar la emision sin tocar la DGII real
// (lo usa tools/test_pos_venta.php). Nunca en el servidor: no se sube.
//
//   MOCK_DGII_STATE=/ruta/estado php -S 127.0.0.1:8098 tools/mock_dgii_local.php
//   API local con DGII_ECF_BASE_URL y DGII_FC_BASE_URL = http://127.0.0.1:8098 y
//   un certificado de prueba (autofirmado) en DGII_ECF_CERT_PATH/PASSWORD.
//
// Implementa: semilla, validar semilla (token), RecepcionFC (RFCE) y ConsultaRFCE.
// Modo en <estado>/modo.txt:
//   acepta  -> RFCE aceptado (codigo 1)
//   rechaza -> RFCE rechazado (codigo 2, secuenciaUtilizada false)
//   lento   -> lo RECIBE (queda registrado) pero responde a los 8 s
//   caido   -> 503 sin JSON y NO lo recibe
// La consulta RFCE responde Aceptado si el e-NCF se recibio; si no, codigo 0.
$dir = rtrim((string) (getenv('MOCK_DGII_STATE') ?: sys_get_temp_dir() . '/mock_dgii_state'), '/\\');
@mkdir($dir);
$modo = trim((string) @file_get_contents($dir . '/modo.txt')) ?: 'acepta';
$uri = $_SERVER['REQUEST_URI'];
$path = strtolower(parse_url($uri, PHP_URL_PATH));
$log = fn(string $t) => file_put_contents($dir . '/log.txt', date('H:i:s') . " {$_SERVER['REQUEST_METHOD']} {$path} {$t}\n", FILE_APPEND);
$recibidos = $dir . '/recibidos.txt';

if (str_ends_with($path, '/autenticacion/api/autenticacion/semilla')) {
    $log('semilla');
    header('Content-Type: application/xml');
    echo '<?xml version="1.0" encoding="utf-8"?><SemillaModel><valor>' . bin2hex(random_bytes(16)) . '</valor><fecha>' . date('c') . '</fecha></SemillaModel>';
    return;
}
if (str_ends_with($path, '/autenticacion/api/autenticacion/validarsemilla')) {
    $log('token');
    header('Content-Type: application/json');
    echo json_encode(['token' => 'tok-' . bin2hex(random_bytes(8)), 'expira' => date('c', time() + 3600), 'expedido' => date('c')]);
    return;
}
if (str_ends_with($path, '/recepcionfc/api/recepcion/ecf')) {
    // multipart/form-data: PHP lo deja en $_FILES (php://input llega vacio).
    $body = isset($_FILES['xml']['tmp_name']) ? (string) file_get_contents($_FILES['xml']['tmp_name']) : (string) file_get_contents('php://input');
    preg_match('/<eNCF>\s*([A-Za-z0-9]+)\s*<\/eNCF>/i', $body, $m);
    $encf = $m[1] ?? '?';
    $formas = preg_match_all('/<FormaPago>(\d)<\/FormaPago>\s*<MontoPago>([\d.]+)<\/MontoPago>/', $body, $fm) ? json_encode(array_map(null, $fm[1], $fm[2])) : 'sin TablaFormasPago';
    $log("rfce {$encf} modo={$modo} formas={$formas}");
    file_put_contents($dir . '/ultimo_rfce.xml', $body);
    if ($modo === 'caido') {
        http_response_code(503);
        echo 'Service Unavailable';
        return;
    }
    file_put_contents($recibidos, $encf . "\n", FILE_APPEND);
    if ($modo === 'lento') {
        sleep(8);
    }
    header('Content-Type: application/json');
    if ($modo === 'rechaza') {
        http_response_code(400);
        echo json_encode(['codigo' => 2, 'estado' => 'Rechazado', 'encf' => $encf, 'secuenciaUtilizada' => false,
            'mensajes' => [['valor' => 'El MontoTotal no corresponde (simulado)', 'codigo' => 999]]]);
        return;
    }
    echo json_encode(['codigo' => 1, 'estado' => 'Aceptado', 'encf' => $encf, 'secuenciaUtilizada' => true, 'mensajes' => []]);
    return;
}
if (str_contains($path, '/consultarfce/api/consultas/consulta')) {
    parse_str((string) parse_url($uri, PHP_URL_QUERY), $q);
    $encf = $q['ENCF'] ?? '?';
    $lista = is_file($recibidos) ? file($recibidos, FILE_IGNORE_NEW_LINES) : [];
    $ok = in_array($encf, $lista, true);
    $log("consulta {$encf} -> " . ($ok ? 'aceptado' : 'no encontrado'));
    header('Content-Type: application/json');
    echo json_encode($ok
        ? ['codigo' => 1, 'estado' => 'Aceptado', 'encf' => $encf, 'secuenciaUtilizada' => true, 'mensajes' => []]
        : ['codigo' => 0, 'estado' => 'No Encontrado', 'encf' => $encf, 'mensajes' => []]);
    return;
}
$log('ruta desconocida');
http_response_code(404);
echo 'not found';
