<?php
// Reporte de ventas (gestion, no fiscal).
//   GET /api/reportes/ventas?desde=AAAA-MM-DD&hasta=AAAA-MM-DD&agrupar=<a>&format=<f>
//     agrupar: documento (detalle, por defecto) | cliente | forma_pago | usuario
//     format:  json (por defecto) | pdf | xlsx
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

$formato = strtolower(trim((string) ($_GET['format'] ?? 'json')));
if (!in_array($formato, ['json', 'pdf', 'xlsx'], true)) {
    $rvError('Formato invalido. Use: json, pdf o xlsx.');
    return;
}

$reporte = (new ReporteVentasModel())->reporte($desdeOk, $hastaOk, $agrupar);

if ($formato === 'json') {
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
// Exportacion: PDF (para imprimir/archivar) y Excel (para seguir trabajando).
//
// Las dos salidas comparten esta definicion de columnas para que el papel y la
// hoja de calculo digan lo mismo, en el mismo orden. Los anchos van en mm (los
// del PDF); el xlsx los convierte a su unidad.
// ---------------------------------------------------------------------------
$etiquetaGrupo = [
    'cliente'    => 'Cliente',
    'forma_pago' => 'Forma de pago',
    'usuario'    => 'Usuario / vendedor',
];

if ($agrupar === 'documento') {
    // Diez columnas no caben a lo ancho de una carta: va apaisado, 263 mm utiles.
    $columnas = [
        ['ancho' => 19, 'titulo' => 'Fecha',         'campo' => 'fecha'],
        ['ancho' => 30, 'titulo' => 'Documento',     'campo' => 'documento'],
        ['ancho' => 12, 'titulo' => 'Tipo',          'campo' => 'tipo'],
        ['ancho' => 52, 'titulo' => 'Cliente',       'campo' => 'cliente'],
        ['ancho' => 23, 'titulo' => 'Forma de pago', 'campo' => 'forma_pago'],
        ['ancho' => 30, 'titulo' => 'Usuario',       'campo' => 'usuario'],
        ['ancho' => 26, 'titulo' => 'Estado',        'campo' => 'estado'],
        ['ancho' => 24, 'titulo' => 'Base',          'campo' => 'base',  'alin' => 'R', 'dinero' => true],
        ['ancho' => 22, 'titulo' => 'ITBIS',         'campo' => 'itbis', 'alin' => 'R', 'dinero' => true],
        ['ancho' => 25, 'titulo' => 'Total',         'campo' => 'total', 'alin' => 'R', 'dinero' => true],
    ];
    $orientacion = 'L';
} else {
    // Vertical: 195 mm utiles. El RNC solo aporta en la vista por cliente.
    $columnas = [[
        'ancho'  => $agrupar === 'cliente' ? 55 : 77,
        'titulo' => $etiquetaGrupo[$agrupar],
        'campo'  => 'etiqueta',
    ]];
    if ($agrupar === 'cliente') {
        $columnas[] = ['ancho' => 22, 'titulo' => 'RNC', 'campo' => 'cliente_rnc'];
    }
    $columnas[] = ['ancho' => 18, 'titulo' => 'Docs.',   'campo' => 'cantidad',   'alin' => 'R'];
    $columnas[] = ['ancho' => 28, 'titulo' => 'Base',    'campo' => 'base',       'alin' => 'R', 'dinero' => true];
    $columnas[] = ['ancho' => 26, 'titulo' => 'ITBIS',   'campo' => 'itbis',      'alin' => 'R', 'dinero' => true];
    $columnas[] = ['ancho' => 30, 'titulo' => 'Total',   'campo' => 'total',      'alin' => 'R', 'dinero' => true];
    $columnas[] = ['ancho' => 16, 'titulo' => '% total', 'campo' => 'porcentaje', 'alin' => 'R'];
    $orientacion = 'P';
}

$filas = $reporte['filas'];
$totalGeneral = (float) $reporte['totales']['total'];
if ($agrupar !== 'documento') {
    // El % se calcula al exportar y no en el modelo: es presentacion, no dato.
    foreach ($filas as &$fila) {
        $fila['porcentaje'] = $totalGeneral != 0.0
            ? number_format($fila['total'] / $totalGeneral * 100, 1) . '%'
            : '';
    }
    unset($fila);
}

$rotulo = $agrupar === 'documento' ? 'Ventas' : ('Ventas por ' . mb_strtolower($etiquetaGrupo[$agrupar], 'UTF-8'));
$rango = 'Del ' . date('d/m/Y', strtotime($desdeOk)) . ' al ' . date('d/m/Y', strtotime($hastaOk));
$nombre = 'ventas_' . $agrupar . '_' . $desdeOk . '_a_' . $hastaOk;
$nota = 'Incluye facturas de venta (E31, E32, E44, E45, E46) y facturas simples. Las notas de crédito (E34) '
    . 'restan y las de débito (E33) suman. No entran compras ni gastos (E41, E43, E47) ni los comprobantes '
    . 'rechazados por la DGII. No es un formato de envío a la DGII.';

if ($formato === 'pdf') {
    require_once __DIR__ . '/../Utils/Pdf/ReporteVentasPdf.php';
    $pdf = new ReporteVentasPdf($orientacion);
    $pdf->configurar($rotulo, $rango, $columnas);
    $pdf->AddPage();
    $pdf->tabla($filas, $reporte['totales']);
    foreach ($reporte['advertencias'] as $a) {
        $pdf->aviso($a);
    }
    $pdf->notaAlPie($nota);
    $contenido = $pdf->contenido();

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $nombre . '.pdf"');
    header('Content-Length: ' . strlen($contenido));
    echo $contenido;
    return;
}

// --- Excel -----------------------------------------------------------------
require_once __DIR__ . '/../Utils/XlsxWriter.php';
$x = new XlsxWriter($rotulo);
// mm -> "caracteres" de Excel: ~1.9 mm por caracter a 11pt Calibri.
$x->anchos(array_map(static fn(array $c): float => round($c['ancho'] / 1.9, 1), $columnas));

$x->fila([[$rotulo, XlsxWriter::TITULO]]);
$x->fila([$rango]);
$x->filaVacia();
$x->fila(array_map(static fn(array $c): array => [$c['titulo'], XlsxWriter::ENCABEZADO], $columnas));
$x->congelarFilas(4);

foreach ($filas as $f) {
    $celdas = [];
    foreach ($columnas as $c) {
        $v = $f[$c['campo']] ?? '';
        if (!empty($c['dinero'])) {
            // Numero de verdad, no texto: asi se puede sumar y filtrar en Excel.
            $celdas[] = [(float) $v, XlsxWriter::DINERO];
        } elseif ($c['campo'] === 'cantidad') {
            $celdas[] = (int) $v;
        } elseif ($c['campo'] === 'fecha') {
            $ts = strtotime((string) $v);
            $celdas[] = $ts ? date('d/m/Y', $ts) : (string) $v;
        } else {
            $celdas[] = (string) $v;
        }
    }
    $x->fila($celdas);
}

$x->filaVacia();
$pie = [];
foreach ($columnas as $i => $c) {
    if ($i === 0) {
        $pie[] = ['TOTAL', XlsxWriter::NEGRITA];
    } elseif ($c['campo'] === 'cantidad') {
        $pie[] = [(int) $reporte['totales']['cantidad'], XlsxWriter::NEGRITA];
    } elseif (in_array($c['campo'], ['base', 'itbis', 'total'], true)) {
        $pie[] = [(float) $reporte['totales'][$c['campo']], XlsxWriter::DINERO_TOT];
    } elseif ($c['campo'] === 'porcentaje') {
        $pie[] = ['100%', XlsxWriter::NEGRITA];
    } else {
        $pie[] = '';
    }
}
$x->fila($pie);

$x->filaVacia();
foreach ($reporte['advertencias'] as $a) {
    $x->fila([$a]);
}
$x->fila([$nota]);

$contenido = $x->generar();
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $nombre . '.xlsx"');
header('Content-Length: ' . strlen($contenido));
echo $contenido;
