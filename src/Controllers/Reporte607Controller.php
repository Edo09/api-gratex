<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-API-KEY, Authorization, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Allow: GET, OPTIONS');

require_once __DIR__ . '/../Models/Reporte607Model.php';
require_once __DIR__ . '/../Middleware/AuthMiddleware.php';

$auth = new AuthMiddleware();
if ($_SERVER['REQUEST_METHOD'] !== 'OPTIONS') {
    $validation = $auth->validateRequest();
    if (!$validation['valid']) {
        $auth->sendUnauthorized($validation['message']);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => false, 'error' => 'Metodo no permitido. Use GET.']);
    return;
}

$periodo = isset($_GET['periodo']) ? trim((string) $_GET['periodo']) : '';
if (!preg_match('/^\d{6}$/', $periodo)) {
    http_response_code(400);
    echo json_encode(['status' => false, 'error' => 'Parametro periodo invalido. Formato: AAAAMM (ej: 202606).']);
    return;
}
$mes = (int) substr($periodo, 4, 2);
if ($mes < 1 || $mes > 12) {
    http_response_code(400);
    echo json_encode(['status' => false, 'error' => 'Mes invalido en periodo. Use 01-12.']);
    return;
}

$model  = new Reporte607Model();
$emisor = $model->getEmisor();
if (!$emisor || empty($emisor['rnc'])) {
    http_response_code(500);
    echo json_encode(['status' => false, 'error' => 'No hay RNC del emisor configurado (emisor_config).']);
    return;
}

$data         = $model->getVentas($periodo);
$registros    = $data['registros'];
$advertencias = $data['advertencias'];

$rncEmisor = preg_replace('/\D/', '', (string) $emisor['rnc']);
$cantidad  = count($registros);

// Preview estructurado para el front: GET /api/reportes/607/preview
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('#/reportes/607/preview#i', (string) $path)) {
    $totales = ['monto_facturado'=>0.0,'itbis_facturado'=>0.0,'itbis_retenido'=>0.0,'retencion_renta'=>0.0];
    foreach ($registros as $r) {
        foreach ($totales as $k => $_) {
            $totales[$k] += (float) ($r[$k] ?? 0);
        }
    }
    $totales = array_map(static fn ($v) => round($v, 2), $totales);

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => true,
        'data' => [
            'periodo' => $periodo, 'rnc_emisor' => $rncEmisor, 'cantidad' => $cantidad,
            'totales' => $totales, 'advertencias' => $advertencias, 'registros' => $registros,
        ],
    ], JSON_UNESCAPED_UNICODE);
    return;
}

// Helpers de formato (decimales con punto, 2 dec).
$money = static function ($v): string {
    return number_format((float) $v, 2, '.', '');
};
$moneyOpt = static function ($v) use ($money): string {
    return ((float) $v) != 0.0 ? $money($v) : '';
};

$EOL = "\r\n";

// Encabezado: 607|RNC|PERIODO|CANTIDAD
$lines = [];
$lines[] = implode('|', ['607', $rncEmisor, $periodo, (string) $cantidad]);

// Detalle: 23 campos por venta (orden del Formato 607).
foreach ($registros as $r) {
    $lines[] = implode('|', [
        $r['rnc'],                          // 1  RNC/Cedula cliente
        $r['tipo_id'],                      // 2  Tipo identificacion
        $r['ncf'],                          // 3  NCF / e-NCF
        $r['ncf_modificado'],               // 4  NCF modificado
        $r['tipo_ingreso'],                 // 5  Tipo ingreso
        $r['fecha_comprobante'],            // 6  Fecha comprobante (AAAAMMDD)
        $r['fecha_retencion'],              // 7  Fecha retencion (AAAAMMDD)
        $money($r['monto_facturado']),      // 8  Monto facturado
        $money($r['itbis_facturado']),      // 9  ITBIS facturado
        $moneyOpt($r['itbis_retenido']),    // 10 ITBIS retenido
        $moneyOpt($r['itbis_percibido']),   // 11 ITBIS percibido
        $moneyOpt($r['retencion_renta']),   // 12 Retencion renta
        $moneyOpt($r['isr_percibido']),     // 13 ISR percibido
        $moneyOpt($r['isc']),               // 14 ISC
        $moneyOpt($r['otros_impuestos']),   // 15 Otros impuestos
        $moneyOpt($r['propina_legal']),     // 16 Propina legal
        $moneyOpt($r['efectivo']),          // 17 Efectivo
        $moneyOpt($r['cheque_transf']),     // 18 Cheques/transferencias
        $moneyOpt($r['tarjeta']),           // 19 Tarjeta debito/credito
        $moneyOpt($r['credito']),           // 20 Venta a credito
        $moneyOpt($r['bonos']),             // 21 Bonos o certificados
        $moneyOpt($r['permuta']),           // 22 Permuta
        $moneyOpt($r['otras']),             // 23 Otras formas de venta
    ]);
}
$txt = implode($EOL, $lines) . $EOL;

// Preview del TXT crudo: ?formato=json
if (($_GET['formato'] ?? 'txt') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => true,
        'data' => [
            'periodo' => $periodo, 'rnc_emisor' => $rncEmisor, 'cantidad' => $cantidad,
            'advertencias' => $advertencias, 'contenido' => $txt,
        ],
    ], JSON_UNESCAPED_UNICODE);
    return;
}

// Descarga TXT. Nombre oficial: DGII_F_607_<RNC>_<PERIODO>.TXT
$filename = 'DGII_F_607_' . $rncEmisor . '_' . $periodo . '.TXT';
header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('X-Advertencias-Count: ' . count($advertencias));
header('Content-Length: ' . strlen($txt));
echo $txt;
return;
