<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-API-KEY, Authorization, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Allow: GET, OPTIONS');

require_once __DIR__ . '/../Models/Reporte606Model.php';
require_once __DIR__ . '/../Middleware/AuthMiddleware.php';

$auth = new AuthMiddleware();
if ($_SERVER['REQUEST_METHOD'] !== 'OPTIONS') {
    $validation = $auth->validateRequest();
    if (!$validation['valid']) {
        $auth->sendUnauthorized($validation['message']);
    }
}

// Solo GET.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => false, 'error' => 'Metodo no permitido. Use GET.']);
    return;
}

// Validar periodo AAAAMM.
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

$model  = new Reporte606Model();
$emisor = $model->getEmisor();
if (!$emisor || empty($emisor['rnc'])) {
    http_response_code(500);
    echo json_encode(['status' => false, 'error' => 'No hay RNC del emisor configurado (emisor_config).']);
    return;
}

$data         = $model->getCompras($periodo);
$registros    = $data['registros'];
$advertencias = $data['advertencias'];

$rncEmisor = preg_replace('/\D/', '', (string) $emisor['rnc']);
$cantidad  = count($registros);

// --- Helpers de formato 606 (decimales con punto, 2 dec) ---
$money = static function ($v): string {
    return number_format((float) $v, 2, '.', '');
};
// Campos opcionales: vacios cuando es 0/null (criterio DGII).
$moneyOpt = static function ($v) use ($money): string {
    return ((float) $v) != 0.0 ? $money($v) : '';
};

$EOL = "\r\n";

// Encabezado: 606|RNC|PERIODO|CANTIDAD
$lines = [];
$lines[] = implode('|', ['606', $rncEmisor, $periodo, (string) $cantidad]);

// Detalle: 23 campos por transaccion (orden OFICIAL DGII: servicios=8, bienes=9).
foreach ($registros as $r) {
    $lines[] = implode('|', [
        $r['rnc'],                               // 1  RNC/Cedula suplidor
        $r['tipo_id'],                           // 2  Tipo identificacion
        $r['tipo_bienes_serv'],                  // 3  Tipo bienes y servicios
        $r['ncf'],                               // 4  NCF
        $r['ncf_modificado'],                    // 5  NCF modificado
        $r['fecha_comprobante'],                 // 6  Fecha comprobante (AAAAMMDD)
        $r['fecha_pago'],                        // 7  Fecha pago (AAAAMMDD)
        $money($r['monto_servicios']),           // 8  Monto facturado en servicios
        $money($r['monto_bienes']),              // 9  Monto facturado en bienes
        $money($r['total_facturado']),           // 10 Total facturado
        $money($r['itbis_facturado']),           // 11 ITBIS facturado
        $moneyOpt($r['itbis_retenido']),         // 12 ITBIS retenido
        $moneyOpt($r['itbis_proporcionalidad']), // 13 ITBIS sujeto a proporcionalidad
        $moneyOpt($r['itbis_costo']),            // 14 ITBIS llevado al costo
        $moneyOpt($r['itbis_adelantar']),        // 15 ITBIS por adelantar
        $moneyOpt($r['itbis_percibido']),        // 16 ITBIS percibido en compras
        $r['tipo_retencion_isr'],                // 17 Tipo retencion ISR
        $moneyOpt($r['retencion_renta']),        // 18 Monto retencion renta
        $moneyOpt($r['isr_percibido']),          // 19 ISR percibido en compras
        $moneyOpt($r['isc']),                    // 20 Impuesto selectivo al consumo
        $moneyOpt($r['otros_impuestos']),        // 21 Otros impuestos y tasas
        $moneyOpt($r['propina_legal']),          // 22 Monto propina legal
        $r['forma_pago'],                        // 23 Forma de pago
    ]);
}
$txt = implode($EOL, $lines) . $EOL;

// Modo revision: ?formato=json devuelve advertencias + preview sin descargar.
if (($_GET['formato'] ?? 'txt') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => true,
        'data' => [
            'periodo'      => $periodo,
            'rnc_emisor'   => $rncEmisor,
            'cantidad'     => $cantidad,
            'advertencias' => $advertencias,
            'contenido'    => $txt,
        ],
    ], JSON_UNESCAPED_UNICODE);
    return;
}

// Descarga TXT (sobre-escribe el Content-Type JSON que fijo el Router).
header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="606_' . $periodo . '.txt"');
header('X-Advertencias-Count: ' . count($advertencias));
header('Content-Length: ' . strlen($txt));
echo $txt;
return;
