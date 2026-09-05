<?php
// Reporte de ventas (gestion, no fiscal).
//   GET /api/reportes/ventas?desde=AAAA-MM-DD&hasta=AAAA-MM-DD&agrupar=<a>&format=<f>
//     agrupar: documento (detalle, por defecto) | cliente | forma_pago | usuario
//     format:  json (por defecto) | csv
//
// Reemplaza los cinco reportes del sistema anterior del cliente: "Ventas" es el
// detalle, y "por cliente" / "por forma de pago" / "por vendedor" / "por usuario"
// son agrupaciones del mismo dato. Vendedor y usuario son la misma agrupacion:
// hoy no se guarda a quien se le acredita la venta, solo quien la digito.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-API-KEY, Authorization, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Allow: GET, OPTIONS');

require_once __DIR__ . '/../Models/ReporteVentasModel.php';
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
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => false, 'error' => 'Metodo no permitido. Use GET.']);
    return;
}

/** Error JSON y corte. */
$rvError = static function (string $msg, int $code = 400): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
};

/** Valida AAAA-MM-DD y que la fecha exista de verdad (no 2026-02-31). */
$rvFecha = static function (string $v): ?string {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
        return null;
    }
    [$a, $m, $d] = array_map('intval', explode('-', $v));
    return checkdate($m, $d, $a) ? $v : null;
};

// Rango por defecto: el mes en curso, que es lo que se mira el 90% de las veces.
$desde = trim((string) ($_GET['desde'] ?? date('Y-m-01')));
$hasta = trim((string) ($_GET['hasta'] ?? date('Y-m-d')));

$desdeOk = $rvFecha($desde);
$hastaOk = $rvFecha($hasta);
if ($desdeOk === null || $hastaOk === null) {
    $rvError('Fechas invalidas. Formato: AAAA-MM-DD (ej: 2026-09-01).');
    return;
}
if ($desdeOk > $hastaOk) {
    $rvError('El rango esta invertido: "desde" es posterior a "hasta".');
    return;
}
// Tope de rango: el server es compartido y un rango abierto puede tumbar la
// consulta. Cinco anos cubre cualquier consulta real de un negocio.
if ((strtotime($hastaOk) - strtotime($desdeOk)) > 5 * 366 * 86400) {
    $rvError('El rango no puede pasar de 5 anos. Divide la consulta por periodos.');
    return;
}

$agrupar = strtolower(trim((string) ($_GET['agrupar'] ?? 'documento')));
if (!in_array($agrupar, ReporteVentasModel::AGRUPACIONES, true)) {
    $rvError('Agrupacion invalida. Use: ' . implode(', ', ReporteVentasModel::AGRUPACIONES) . '.');
    return;
}

$reporte = (new ReporteVentasModel())->reporte($desdeOk, $hastaOk, $agrupar);

$formato = strtolower(trim((string) ($_GET['format'] ?? 'json')));

if ($formato !== 'csv') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status' => true,
        'data' => [
            'desde'        => $desdeOk,
            'hasta'        => $hastaOk,
            'agrupar'      => $agrupar,
            'totales'      => $reporte['totales'],
            'advertencias' => $reporte['advertencias'],
            'filas'        => $reporte['filas'],
        ],
    ], JSON_UNESCAPED_UNICODE);
    return;
}

// ---------------------------------------------------------------------------
// CSV
// ---------------------------------------------------------------------------
$columnas = $agrupar === 'documento'
    ? [
        'fecha'       => 'Fecha',
        'documento'   => 'Documento',
        'tipo'        => 'Tipo',
        'cliente'     => 'Cliente',
        'cliente_rnc' => 'RNC',
        'forma_pago'  => 'Forma de pago',
        'usuario'     => 'Usuario',
        'estado'      => 'Estado',
        'base'        => 'Base',
        'itbis'       => 'ITBIS',
        'total'       => 'Total',
    ]
    : [
        'etiqueta' => match ($agrupar) {
            'cliente'    => 'Cliente',
            'forma_pago' => 'Forma de pago',
            'usuario'    => 'Usuario',
        },
        'cantidad' => 'Documentos',
        'base'     => 'Base',
        'itbis'    => 'ITBIS',
        'total'    => 'Total',
    ];

$nombre = 'ventas_' . $agrupar . '_' . $desdeOk . '_a_' . $hastaOk . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $nombre . '"');

$salida = fopen('php://output', 'w');
// BOM: sin el, Excel en Windows abre el UTF-8 como ANSI y parte los acentos.
fwrite($salida, "\xEF\xBB\xBF");
fputcsv($salida, array_values($columnas));
foreach ($reporte['filas'] as $fila) {
    $linea = [];
    foreach (array_keys($columnas) as $k) {
        $v = $fila[$k] ?? '';
        // Los montos van con punto decimal y sin separador de miles: es lo que
        // Excel reconoce como numero, y con miles lo leeria como texto.
        $linea[] = in_array($k, ['base', 'itbis', 'total'], true)
            ? number_format((float) $v, 2, '.', '')
            : $v;
    }
    fputcsv($salida, $linea);
}
// Fila de totales al pie, para que el archivo cuadre solo.
$t = $reporte['totales'];
fputcsv($salida, []);
$pie = $agrupar === 'documento'
    ? ['TOTAL', '', '', '', '', '', '', '']
    : ['TOTAL', $t['cantidad']];
fputcsv($salida, array_merge($pie, [
    number_format($t['base'], 2, '.', ''),
    number_format($t['itbis'], 2, '.', ''),
    number_format($t['total'], 2, '.', ''),
]));
fclose($salida);
