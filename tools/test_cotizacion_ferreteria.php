<?php
/**
 * test_cotizacion_ferreteria.php — Formato de cotización de Ferretería, sin DB.
 *
 * Ferretería cotiza con su hoja de Excel "COTIZACION MERCANCIAS". El servidor
 * calcula los totales (FerreteriaFormato) y la pantalla los repite antes de
 * guardar (fiscalo totalesFerreteria()): los dos tienen que dar lo mismo al
 * centavo, y lo mismo que las hojas. Este script lo prueba por CLI, sin base
 * de datos ni servidor, sección por sección (cada tarea agrega la suya encima
 * del resumen).
 *
 * Los casos viven en tools/fixtures/cotizacion_ferreteria.json: las 3 hojas
 * del Excel línea por línea, más casos de borde. El front corre una copia de
 * ese archivo (fiscalo scripts/fixtures/) con su script de paridad, así que
 * un cambio aquí se copia allá.
 *
 * Uso:
 *   php tools/test_cotizacion_ferreteria.php
 */

require_once __DIR__ . '/../src/Utils/Pdf/BrandingResolver.php';
require_once __DIR__ . '/../src/Utils/Cotizacion/Redondeo.php';

// Sin marca global, como tools/ri_desde_xml.php: sin tenant resuelto, el logo
// y el sello del repo (los de Gratex) son el fallback de las plantillas, y no
// pueden salir en nada que este script dibuje para Ferretería.
BrandingResolver::sinMarcaGlobal();

$fallos = 0;
$total = 0;
$chk = function (string $desc, bool $ok) use (&$fallos, &$total) {
    $total++;
    if (!$ok) {
        $fallos++;
    }
    printf("  [%s] %s\n", $ok ? 'OK  ' : 'FALLA', $desc);
};

// Los montos salen de Redondeo y se comparan EXACTOS: r2 da el double más
// cercano al decimal, el mismo que lee json_decode. (float) porque el JSON
// trae 41860 como entero.
$igual = static fn($a, $b): bool => is_numeric($a) && is_numeric($b) && (float) $a === (float) $b;

$fixture = json_decode((string) file_get_contents(__DIR__ . '/fixtures/cotizacion_ferreteria.json'), true);
if (!is_array($fixture) || !isset($fixture['casos'], $fixture['emisor'])) {
    fwrite(STDERR, "No se pudo leer tools/fixtures/cotizacion_ferreteria.json\n");
    exit(1);
}
/** @var array<string,array> $casos id => caso */
$casos = array_column($fixture['casos'], null, 'id');

echo "== Fixtures ==\n";
$chk('el fixture trae los 10 casos', array_keys($casos) === [
    'pintura', 'pintura_retencion_abono', 'pintura_mano_obra', 'b150000049', 'ceramicas',
    'redondeo_8475', 'flotante', 'mixto', 'exento', 'largo_60',
]);
// D = A × C y Sub-total = SUM(D): la cuenta de la hoja, sin Redondeo, para
// que un error al copiar una línea no se esconda detrás del código probado.
$sumaHoja = static fn(array $caso): float => array_sum(array_map(
    static fn(array $l): float => (float) $l['quantity'] * (float) $l['amount'],
    $caso['lineas']
));
foreach ([['pintura', 7, 41860], ['b150000049', 26, 27278], ['ceramicas', 5, 8260]] as [$id, $n, $subtotal]) {
    $chk("{$id}: {$n} líneas, como la hoja", count($casos[$id]['lineas']) === $n);
    $chk("{$id}: Σ cantidad × valor unitario = {$subtotal}, el Sub-total de la hoja", $sumaHoja($casos[$id]) === (float) $subtotal);
}
$chk('pintura_retencion_abono y pintura_mano_obra usan las líneas de pintura',
    $casos['pintura_retencion_abono']['lineas'] === $casos['pintura']['lineas']
    && $casos['pintura_mano_obra']['lineas'] === $casos['pintura']['lineas']);
$chk('largo_60: 60 líneas de 1 × (100 + i)', count($casos['largo_60']['lineas']) === 60
    && $casos['largo_60']['lineas'][59]['amount'] === 160
    && str_starts_with($casos['largo_60']['lineas'][59]['description'], 'ARTICULO DE PRUEBA NUMERO 60 '));
$completo = true;
foreach ($casos as $caso) {
    $completo = $completo && isset($caso['code'], $caso['date'], $caso['cliente'], $caso['lineas'], $caso['ajustes'], $caso['esperado'])
        && array_key_exists('hoja', $caso);
}
$chk('cada caso trae hoja, code, date, cliente, lineas, ajustes y esperado', $completo);
$chk('emisor de Ferretería con las 6 claves de emisor_config', array_keys($fixture['emisor']) === [
    'rnc', 'razon_social', 'nombre_comercial', 'direccion', 'telefono', 'correo',
]);
$logo = __DIR__ . '/fixtures/ferreteria_logo.jpeg';
$infoLogo = is_file($logo) ? getimagesize($logo) : false;
$chk('ferreteria_logo.jpeg: el JPEG de 330×360 del Excel (xl/media/image1.jpeg)',
    $infoLogo !== false && $infoLogo['mime'] === 'image/jpeg' && $infoLogo[0] === 330 && $infoLogo[1] === 360);

echo "\n== Redondeo (copia de montosLinea.redondear) ==\n";
// Esperados de redondear() en fiscalo (corrido con node), que es lo que da
// PHP 8.3 en producción. Los de x.xx5 son los que el binario deja justo por
// debajo de la mitad.
$fijos = [
    ['84.75 × 0.18', 84.75 * 0.18, 2, 15.26],
    ['-84.75 × 0.18', -84.75 * 0.18, 2, -15.26],
    ['1.005', 1.005, 2, 1.01],
    ['-1.005', -1.005, 2, -1.01],
    ['2.675', 2.675, 2, 2.68],
    ['1.255', 1.255, 2, 1.26],
    ['0.285', 0.285, 2, 0.29],
    ['8.345', 8.345, 2, 8.35],
    ['5.015', 5.015, 2, 5.02],
    ['1.125', 1.125, 2, 1.13],
    ['13.70 × 0.18', 13.70 * 0.18, 2, 2.47],
    ['13.70 × 0.05', 13.70 * 0.05, 2, 0.69],
    ['41860 × 0.05', 41860 * 0.05, 2, 2093.0],
    ['16.17 - 0.69', 16.17 - 0.69, 2, 15.48],
    ['0.1 + 0.2', 0.1 + 0.2, 2, 0.3],
    ['1234567.125', 1234567.125, 2, 1234567.13],
    ['0', 0.0, 2, 0.0],
    ['84.74575', 84.74575, 4, 84.7458],
    ['1.00005', 1.00005, 4, 1.0001],
    ['100 / 1.18', 100 / 1.18, 4, 84.7458],
    ['2360 / 1.18', 2360 / 1.18, 4, 2000.0],
    ['2.5', 2.5, 0, 3.0],
    ['-2.5', -2.5, 0, -3.0],
];
foreach ($fijos as [$desc, $x, $dec, $esperado]) {
    $r = Redondeo::r($x, $dec);
    $chk(sprintf('r(%s, %d) = %s (dio %s)', $desc, $dec, var_export($esperado, true), var_export($r, true)), $r === $esperado);
}
$chk('r2(x) = r(x, 2)', Redondeo::r2(84.75 * 0.18) === 15.26);
$chk('r4(x) = r(x, 4)', Redondeo::r4(100 / 1.18) === 84.7458);
$chk('devuelve float también cuando el resultado es entero', is_float(Redondeo::r2(41860 * 0.05)));
printf("     (PHP %s: round(84.75 * 0.18, 2) a secas da %s; Redondeo::r2 da %s)\n",
    PHP_VERSION, var_export(round(84.75 * 0.18, 2), true), var_export(Redondeo::r2(84.75 * 0.18), true));

// Barrido contra montosLinea.redondear portado operación por operación, con
// Math.round como floor(v + 0.5) (v >= 0) en vez del round() de PHP: si un
// round() de otra versión se cuela en Redondeo, aquí no cuadra.
$redondearJs = static function (float $x, int $dec): float {
    $f = 10 ** $dec;                                // const f = 10 ** dec
    $v = (float) sprintf('%.14e', abs($x) * $f);    // Number((Math.abs(x) * f).toPrecision(15))
    $signo = $x > 0 ? 1 : ($x < 0 ? -1 : 0);        // Math.sign(x)
    return ($signo * floor($v + 0.5)) / $f;         // (Math.sign(x) * Math.round(v)) / f
};
$barridos = 0;
$distintos = [];
$roundDistinto = 0;
$comparar = function (float $x, int $dec) use ($redondearJs, &$barridos, &$distintos, &$roundDistinto) {
    $barridos++;
    $r = Redondeo::r($x, $dec);
    if ($r !== $redondearJs($x, $dec) && count($distintos) < 5) {
        $distintos[] = sprintf('r(%.17g, %d) = %.17g, montosLinea = %.17g', $x, $dec, $r, $redondearJs($x, $dec));
    }
    if (round($x, $dec) !== $r) {
        $roundDistinto++;
    }
};
for ($c = 1; $c <= 100000; $c++) {           // cada centavo de 0.01 a 1,000.00…
    $monto = $c / 100;
    $comparar($monto * 0.18, 2);             // …por ITBIS 18%
    $comparar($monto * 0.16, 2);             // …por ITBIS 16%
    $comparar($monto * 0.05, 2);             // …por la retención del 5%
    $comparar(-$monto * 0.18, 2);            // …y en negativo
}
for ($k = 1; $k <= 100000; $k++) {           // precios de 4 decimales con el ITBIS sumado (factura simple)
    $comparar($k / 10000 * 1.18, 4);
}
foreach ([84.7458, 2.675, 1.005, 13.7, 999.9999, 0.0001] as $precio) {
    for ($q = 1; $q <= 1000; $q++) {         // cantidades 0.01..10.00 × precios con medio centavo
        $comparar($q / 100 * $precio, 2);
    }
}
$chk("barrido: {$barridos} valores, Redondeo = montosLinea.redondear en todos", $distintos === []);
foreach ($distintos as $d) {
    echo "     {$d}\n";
}
printf("     (en %d de esos valores round() a secas de este PHP %s da otro resultado)\n", $roundDistinto, PHP_VERSION);

// ---------------------------------------------------------------------------
// FerreteriaFormato: reglas puras (Task 4)
// ---------------------------------------------------------------------------

require_once __DIR__ . '/../src/Utils/Cotizacion/FerreteriaFormato.php';

echo "\n== FerreteriaFormato::tasa ==\n";
$chk('1 = 18%, 2 = 16%, 3 = 0%, 4 = exento',
    FerreteriaFormato::tasa(1) === 0.18 && FerreteriaFormato::tasa(2) === 0.16
    && FerreteriaFormato::tasa(3) === 0.0 && FerreteriaFormato::tasa(4) === 0.0);

echo "\n== FerreteriaFormato::totales (fixtures) ==\n";
// Las líneas como las arma el formato tras validarForma: quantity, amount, indicador.
$lineasDe = static fn(array $caso): array => array_map(static fn(array $l): array => [
    'quantity' => (float) $l['quantity'],
    'amount' => (float) $l['amount'],
    'indicador_facturacion' => (int) $l['indicador_facturacion'],
], $caso['lineas']);
$totalesDe = static fn(array $caso): array => FerreteriaFormato::totales($lineasDe($caso), $caso['ajustes']);

foreach ($casos as $id => $caso) {
    $t = $totalesDe($caso);
    $chk("{$id}: una base y un ITBIS por línea (" . count($caso['lineas']) . ')', count($t['lineas']) === count($caso['lineas']));
    foreach ($caso['esperado'] as $clave => $esperado) {
        if ($clave === 'lineas') {
            $ok = count($t['lineas']) === count($esperado);
            foreach ($esperado as $i => $e) {
                $ok = $ok && $igual($t['lineas'][$i]['base'] ?? null, $e['base']) && $igual($t['lineas'][$i]['itbis'] ?? null, $e['itbis']);
            }
            $chk("{$id}: base e ITBIS de cada línea", $ok);
        } elseif ($clave === 'error_abono') {
            $msg = FerreteriaFormato::errorAbono($t);
            $chk("{$id}: errorAbono " . ($esperado === null ? 'null' : 'con mensaje') . ' (dio ' . var_export($msg, true) . ')',
                $esperado === null ? $msg === null : $msg !== null);
        } else {
            $obtenido = $t[$clave] ?? '(falta)';
            $ok = (is_bool($esperado) || is_string($esperado)) ? $obtenido === $esperado : $igual($obtenido, $esperado);
            $chk(sprintf('%s: %s = %s (dio %s)', $id, $clave, json_encode($esperado, JSON_UNESCAPED_UNICODE),
                json_encode($obtenido, JSON_UNESCAPED_UNICODE)), $ok);
        }
    }
}

$t = $totalesDe($casos['pintura']);
$chk('las claves de totales(), en el orden del contrato', array_keys($t) === [
    'lineas', 'subtotal', 'itbis', 'cargos_bancarios', 'manejo_bancario', 'mano_obra', 'total',
    'retencion_isr', 'adeudado', 'abono', 'restante', 'etiqueta_itbis', 'mostrar_restante',
]);
$soloMontos = array_diff_key($t, array_flip(['lineas', 'etiqueta_itbis', 'mostrar_restante']));
$chk('cada monto es float', array_filter($soloMontos, 'is_float') === $soloMontos);
$t = FerreteriaFormato::totales($lineasDe($casos['pintura']), []);
$chk('ajustes vacíos: cargos 0, sin retención, sin abono, sin Restante',
    $t['total'] === 49394.8 && $t['retencion_isr'] === 0.0 && $t['abono'] === 0.0 && $t['mostrar_restante'] === false);
$t = FerreteriaFormato::totales($lineasDe($casos['pintura']), ['retencion_isr' => true] + $casos['pintura']['ajustes']);
$chk('solo retención (sin abono): Restante 47,301.80 y se muestra', $t['restante'] === 47301.8 && $t['mostrar_restante'] === true);
$t = FerreteriaFormato::totales([
    ['quantity' => 1.0, 'amount' => 100.0, 'indicador_facturacion' => 1],
    ['quantity' => 1.0, 'amount' => 0.02, 'indicador_facturacion' => 2],   // 16% de 0.02 = 0.0032 -> 0.00
], []);
$chk('una línea al 16% cuyo ITBIS redondea a 0 no quita el "ITBIS 18%"', $t['etiqueta_itbis'] === 'ITBIS 18%' && $t['itbis'] === 18.0);
$t = FerreteriaFormato::totales([['quantity' => 3.0, 'amount' => 84.7458, 'indicador_facturacion' => 1]], []);
$chk('3 × 84.7458 = 254.24 (precio de 4 decimales, base a 2)', $t['subtotal'] === 254.24 && $t['itbis'] === 45.76);
$t = FerreteriaFormato::totales([['quantity' => 1.005, 'amount' => 100.0, 'indicador_facturacion' => 1]], []);
$chk('la cantidad se lleva a 2 decimales antes de multiplicar (1.005 -> 1.01)', $t['subtotal'] === 101.0);

echo "\n== FerreteriaFormato::errorAbono ==\n";
$conAbono = static fn(float $abono, bool $retencion = false): array => FerreteriaFormato::totales(
    $lineasDe($casos['pintura']),
    ['abono' => $abono, 'retencion_isr' => $retencion] + $casos['pintura']['ajustes']
);
$chk('abono igual a lo adeudado (49,394.80): sin error', FerreteriaFormato::errorAbono($conAbono(49394.8)) === null);
$msg = FerreteriaFormato::errorAbono($conAbono(50000));
$chk('abono 50,000.00 sobre 49,394.80: "' . $msg . '"',
    $msg === 'El abono (RD$ 50,000.00) no puede ser mayor que lo adeudado (RD$ 49,394.80).');
$chk('un centavo de más ya es error (49,394.81)', FerreteriaFormato::errorAbono($conAbono(49394.81)) !== null);
$msg = FerreteriaFormato::errorAbono($conAbono(47301.81, true));
$chk('con retención lo adeudado es TOTAL − retención (47,301.80)',
    $msg === 'El abono (RD$ 47,301.81) no puede ser mayor que lo adeudado (RD$ 47,301.80).'
    && FerreteriaFormato::errorAbono($conAbono(47301.8, true)) === null);

echo "\n== FerreteriaFormato::codigo / formatearRnc / fechaLarga ==\n";
$chk('codigo(1) = COT-000001', FerreteriaFormato::codigo(1) === 'COT-000001');
$chk('codigo(123) = COT-000123', FerreteriaFormato::codigo(123) === 'COT-000123');
$chk('codigo(999999) = COT-999999', FerreteriaFormato::codigo(999999) === 'COT-999999');
$chk('codigo(1234567) = COT-1234567 (no se corta)', FerreteriaFormato::codigo(1234567) === 'COT-1234567');
foreach ([
    ['401515131', '401-51513-1'],
    ['132615123', '132-61512-3'],
    ['401-51513-1', '401-51513-1'],
    [' 401515131 ', '401-51513-1'],
    ['00112345678', '001-1234567-8'],
    ['001-1234567-8', '001-1234567-8'],
    ['AB123456', 'AB123456'],
    ['12345', '12345'],
    ['', ''],
    [null, ''],
] as [$rnc, $esperado]) {
    $chk(sprintf('formatearRnc(%s) = %s', var_export($rnc, true), var_export($esperado, true)), FerreteriaFormato::formatearRnc($rnc) === $esperado);
}
foreach ([
    ['2026-09-02 10:15:00', 'SEPTIEMBRE 2/2026.-'],
    ['2026-05-14 09:00:00', 'MAYO 14/2026.-'],
    ['2026-01-31', 'ENERO 31/2026.-'],
    ['2026-12-01 00:00:00', 'DICIEMBRE 1/2026.-'],
] as [$fecha, $esperado]) {
    $chk("fechaLarga('{$fecha}') = '{$esperado}'", FerreteriaFormato::fechaLarga($fecha) === $esperado);
}
$hoyRd = static function (): string {
    $d = new DateTimeImmutable('now', new DateTimeZone('America/Santo_Domingo'));
    $meses = ['ENERO', 'FEBRERO', 'MARZO', 'ABRIL', 'MAYO', 'JUNIO', 'JULIO', 'AGOSTO', 'SEPTIEMBRE', 'OCTUBRE', 'NOVIEMBRE', 'DICIEMBRE'];
    return $meses[(int) $d->format('n') - 1] . ' ' . $d->format('j') . '/' . $d->format('Y') . '.-';
};
foreach (['', '0000-00-00 00:00:00', 'basura', '2026-02-30'] as $fecha) {
    $chk("fechaLarga('{$fecha}') = hoy en RD, nunca 1969", FerreteriaFormato::fechaLarga($fecha) === $hoyRd());
}

echo "\n== FerreteriaFormato::validarForma ==\n";
require_once __DIR__ . '/../src/Models/unidadMedidaModel.php';   // solo decimalesDe (estático, sin DB)
// Catálogo de unidades de prueba: [código DGII => [descripción, admite decimales]].
$unidadesFx = ['43' => ['Unidad', false], '26' => ['Metro', true], '21' => ['Kilogramo', true]];
$llamadasCantidad = [];
// Mismas reglas y textos que unidadMedidaModel::problemaCantidad, con el catálogo de arriba en vez de master.
$problemaCantidadFx = function (float $cantidad, string $unidad, int $maxDec) use ($unidadesFx, &$llamadasCantidad): ?string {
    $llamadasCantidad[] = [$cantidad, $unidad, $maxDec];
    if (!($cantidad > 0) || !(round($cantidad, $maxDec) > 0)) {
        return 'La cantidad debe ser mayor que 0.';
    }
    $dec = unidadMedidaModel::decimalesDe($cantidad);
    if ($dec === 0) {
        return null;
    }
    [$nombre, $permite] = $unidadesFx[$unidad] ?? ['', true];
    if (!$permite) {
        return 'La unidad «' . ($nombre !== '' ? $nombre : 'Unidad') . '» no admite fracciones: usa una cantidad entera o cambia la unidad.';
    }
    if ($dec > $maxDec) {
        return 'La cantidad admite hasta ' . $maxDec . ' decimales.';
    }
    return null;
};
$unidadValidaFx = static fn(string $unidad): bool => isset($unidadesFx[$unidad]);

// El ejemplo de la spec (6.5): una línea de producto y una libre sin unidad ni indicadores.
$ejemplo = <<<'JSON'
{ "formato": "ferreteria", "client_id": 123, "date": "2026-09-02 10:15:00",
  "items": [
    { "product_id": 55, "description": "FUNDAS CEMENTO GRIS", "quantity": 2, "amount": 935,
      "unidad_medida": "43", "indicador_facturacion": 1, "indicador_bien_servicio": 1 },
    { "product_id": null, "description": "  CORTE DE TUBO ", "quantity": 1, "amount": 150 }
  ],
  "ajustes": { "cargos_bancarios": 0, "manejo_bancario": 0, "mano_obra": 1500, "abono": 0, "retencion_isr": false } }
JSON;
// Cada prueba parte de una copia nueva del ejemplo, decodificada como en el
// controller (objetos, no arreglos), y le cambia una sola cosa.
$cuerpo = static function (?callable $cambiar = null) use ($ejemplo): object {
    $b = json_decode($ejemplo);
    if ($cambiar !== null) {
        $cambiar($b);
    }
    return $b;
};
$validar = static fn(object $b): array => FerreteriaFormato::validarForma($b, $problemaCantidadFx, $unidadValidaFx);
$rechaza = function (string $desc, object $b, string $mensaje) use ($validar, $chk) {
    $r = $validar($b);
    $ok = ($r['ok'] ?? null) === false && ($r['error'] ?? null) === $mensaje;
    $chk($desc . ' -> "' . $mensaje . '"' . ($ok ? '' : ' (dio ' . json_encode($r, JSON_UNESCAPED_UNICODE) . ')'), $ok);
};

$llamadasCantidad = [];
$r = $validar($cuerpo());
$chk('el ejemplo de la spec pasa', ($r['ok'] ?? null) === true);
$cot = $r['cot'] ?? [];
$chk('cot trae date, client_id, items y ajustes, en ese orden', array_keys($cot) === ['date', 'client_id', 'items', 'ajustes']);
$chk('client_id 123 y la fecha tal cual', ($cot['client_id'] ?? null) === 123 && ($cot['date'] ?? null) === '2026-09-02 10:15:00');
$chk('línea de producto normalizada', ($cot['items'][0] ?? null) === [
    'product_id' => 55, 'description' => 'FUNDAS CEMENTO GRIS', 'quantity' => 2.0, 'amount' => 935.0,
    'unidad_medida' => '43', 'indicador_facturacion' => 1, 'indicador_bien_servicio' => 1,
]);
$chk("línea libre: sin producto, descripción recortada, unidad '43', indicador 1, bien", ($cot['items'][1] ?? null) === [
    'product_id' => null, 'description' => 'CORTE DE TUBO', 'quantity' => 1.0, 'amount' => 150.0,
    'unidad_medida' => '43', 'indicador_facturacion' => 1, 'indicador_bien_servicio' => 1,
]);
$chk('ajustes: los 4 montos como float y la casilla como bool', ($cot['ajustes'] ?? null) === [
    'cargos_bancarios' => 0.0, 'manejo_bancario' => 0.0, 'mano_obra' => 1500.0, 'abono' => 0.0, 'retencion_isr' => false,
]);
$chk("problemaCantidad recibe la unidad normalizada ('43', nunca null) y 2 decimales",
    $llamadasCantidad === [[2.0, '43', 2], [1.0, '43', 2]]);
$t = FerreteriaFormato::totales($cot['items'] ?? [], $cot['ajustes'] ?? []);
$chk('lo validado entra directo a totales(): 2,020.00 + 363.60 + mano de obra 1,500.00 = 3,883.60',
    $t['subtotal'] === 2020.0 && $t['itbis'] === 363.6 && $t['total'] === 3883.6);

$r = $validar($cuerpo(function (object $b) { unset($b->ajustes); }));
$chk('sin ajustes = ninguno (montos 0, sin retención)', ($r['cot']['ajustes'] ?? null) === [
    'cargos_bancarios' => 0.0, 'manejo_bancario' => 0.0, 'mano_obra' => 0.0, 'abono' => 0.0, 'retencion_isr' => false,
]);
$r = $validar($cuerpo(function (object $b) { $b->ajustes = (object) ['retencion_isr' => true, 'abono' => 10.5, 'mano_obra' => null]; }));
$chk('retención marcada, abono 10.50 y un monto vacío (null = 0)', ($r['cot']['ajustes'] ?? null) === [
    'cargos_bancarios' => 0.0, 'manejo_bancario' => 0.0, 'mano_obra' => 0.0, 'abono' => 10.5, 'retencion_isr' => true,
]);
$r = $validar($cuerpo(function (object $b) { unset($b->date); }));
$chk('sin fecha: date null (POST = ahora, PUT = la guardada)', ($r['ok'] ?? null) === true && $r['cot']['date'] === null);
$r = $validar($cuerpo(function (object $b) { $b->date = ''; }));
$chk('fecha vacía: date null', ($r['ok'] ?? null) === true && $r['cot']['date'] === null);
$antes = (new DateTimeImmutable('now', new DateTimeZone('America/Santo_Domingo')))->format('H:i');
$r = $validar($cuerpo(function (object $b) { $b->date = '2026-09-02'; }));
$despues = (new DateTimeImmutable('now', new DateTimeZone('America/Santo_Domingo')))->format('H:i');
$fecha = (string) ($r['cot']['date'] ?? '');
$chk("solo el día: se le pone la hora de ahora en RD ({$fecha})",
    preg_match('/^2026-09-02 \d{2}:\d{2}:\d{2}$/', $fecha) === 1 && in_array(substr($fecha, 11, 5), [$antes, $despues], true));
$r = $validar($cuerpo(function (object $b) { $b->items[0]->unidad_medida = 43; $b->items[1]->unidad_medida = '026'; $b->items[1]->quantity = 1.5; }));
$chk("unidad 43 (número) -> '43'; '026' -> '26' y 1.5 m pasa",
    ($r['ok'] ?? null) === true && $r['cot']['items'][0]['unidad_medida'] === '43'
    && $r['cot']['items'][1]['unidad_medida'] === '26' && $r['cot']['items'][1]['quantity'] === 1.5);
$r = $validar($cuerpo(function (object $b) { $b->items[0]->unidad_medida = ''; $b->items[0]->indicador_facturacion = '2'; }));
$chk("unidad '' -> '43'; indicador \"2\" -> 2", ($r['ok'] ?? null) === true
    && $r['cot']['items'][0]['unidad_medida'] === '43' && $r['cot']['items'][0]['indicador_facturacion'] === 2);
$r = $validar($cuerpo(function (object $b) { $b->items[1]->description = str_repeat('Ñ', 1000); $b->items[1]->amount = 84.7458; }));
$chk('descripción de 1000 caracteres (multibyte) y precio de 4 decimales pasan', ($r['ok'] ?? null) === true);
$r = $validar($cuerpo(function (object $b) { $b->formato = 'gratex'; $b->total = 1; $b->sent_email = true; $b->user_id = 9; }));
$chk('formato, total, sent_email y user_id del cuerpo no son asunto de validarForma', ($r['ok'] ?? null) === true);

// --- cada regla 422 ---
$rechaza('sin client_id', $cuerpo(function (object $b) { unset($b->client_id); }), 'Elige un cliente para la cotización.');
$rechaza('client_id 0', $cuerpo(function (object $b) { $b->client_id = 0; }), 'Elige un cliente para la cotización.');
$rechaza('client_id "abc"', $cuerpo(function (object $b) { $b->client_id = 'abc'; }), 'Elige un cliente para la cotización.');
$rechaza('fecha 2026-02-30', $cuerpo(function (object $b) { $b->date = '2026-02-30'; }), 'La fecha no es válida.');
$rechaza('fecha 2026-13-01 10:00:00', $cuerpo(function (object $b) { $b->date = '2026-13-01 10:00:00'; }), 'La fecha no es válida.');
$rechaza('fecha 2026-09-02 25:00:00', $cuerpo(function (object $b) { $b->date = '2026-09-02 25:00:00'; }), 'La fecha no es válida.');
$rechaza('fecha 02/09/2026', $cuerpo(function (object $b) { $b->date = '02/09/2026'; }), 'La fecha no es válida.');
$rechaza('fecha numérica', $cuerpo(function (object $b) { $b->date = 20260902; }), 'La fecha no es válida.');
$rechaza('items vacío', $cuerpo(function (object $b) { $b->items = []; }), 'Agrega al menos una línea a la cotización.');
$rechaza('sin items', $cuerpo(function (object $b) { unset($b->items); }), 'Agrega al menos una línea a la cotización.');
$rechaza('una línea que no es objeto', $cuerpo(function (object $b) { $b->items[0] = 'FUNDAS'; }),
    'La línea 1 no es válida. Quítala y vuelve a agregarla.');
$rechaza('descripción en blanco (línea 2)', $cuerpo(function (object $b) { $b->items[1]->description = '   '; }),
    'La línea 2 no tiene descripción. Escríbela o quita esa línea.');
$rechaza('sin descripción', $cuerpo(function (object $b) { unset($b->items[0]->description); }),
    'La línea 1 no tiene descripción. Escríbela o quita esa línea.');
$rechaza('descripción de 1001 caracteres', $cuerpo(function (object $b) { $b->items[0]->description = str_repeat('Ñ', 1001); }),
    'La descripción de la línea 1 es muy larga: admite hasta 1000 caracteres.');
$rechaza('unidad que no está en el catálogo', $cuerpo(function (object $b) { $b->items[0]->unidad_medida = '999'; }),
    'La unidad de medida de la línea 1 no es válida. Elige otra unidad en esa línea.');
$rechaza('unidad "abc"', $cuerpo(function (object $b) { $b->items[0]->unidad_medida = 'abc'; }),
    'La unidad de medida de la línea 1 no es válida. Elige otra unidad en esa línea.');
$rechaza('cantidad 0', $cuerpo(function (object $b) { $b->items[0]->quantity = 0; }), 'Línea 1: la cantidad debe ser mayor que 0.');
$rechaza('cantidad negativa', $cuerpo(function (object $b) { $b->items[0]->quantity = -2; }), 'Línea 1: la cantidad debe ser mayor que 0.');
$rechaza('sin cantidad', $cuerpo(function (object $b) { unset($b->items[1]->quantity); }), 'Línea 2: la cantidad debe ser mayor que 0.');
$rechaza('cantidad "dos"', $cuerpo(function (object $b) { $b->items[0]->quantity = 'dos'; }), 'Línea 1: la cantidad debe ser mayor que 0.');
$rechaza('1.5 en Unidad (problemaCantidad inyectado)', $cuerpo(function (object $b) { $b->items[0]->quantity = 1.5; }),
    'Línea 1: la unidad «Unidad» no admite fracciones: usa una cantidad entera o cambia la unidad.');
$rechaza('1.5 sin unidad: se juzga como Unidad (43)', $cuerpo(function (object $b) { $b->items[1]->quantity = 1.5; }),
    'Línea 2: la unidad «Unidad» no admite fracciones: usa una cantidad entera o cambia la unidad.');
$rechaza('1.125 m (3 decimales)', $cuerpo(function (object $b) { $b->items[0]->unidad_medida = '26'; $b->items[0]->quantity = 1.125; }),
    'Línea 1: la cantidad admite hasta 2 decimales.');
$rechaza('precio 0', $cuerpo(function (object $b) { $b->items[0]->amount = 0; }), 'Línea 1: el precio debe ser mayor que 0.');
$rechaza('precio negativo', $cuerpo(function (object $b) { $b->items[0]->amount = -935; }), 'Línea 1: el precio debe ser mayor que 0.');
$rechaza('precio con 5 decimales', $cuerpo(function (object $b) { $b->items[0]->amount = 84.74581; }), 'Línea 1: el precio admite hasta 4 decimales.');
$rechaza('precio "caro"', $cuerpo(function (object $b) { $b->items[0]->amount = 'caro'; }), 'El precio de la línea 1 no es válido. Revísalo.');
$rechaza('sin precio', $cuerpo(function (object $b) { unset($b->items[1]->amount); }), 'El precio de la línea 2 no es válido. Revísalo.');
foreach ([5, 0, 1.5, 'x', true] as $malo) {
    $rechaza('indicador_facturacion ' . var_export($malo, true), $cuerpo(function (object $b) use ($malo) { $b->items[0]->indicador_facturacion = $malo; }),
        'Línea 1: el tipo de ITBIS no es válido. Elige 18%, 16%, 0% o exento.');
}
$rechaza('indicador_bien_servicio 3', $cuerpo(function (object $b) { $b->items[0]->indicador_bien_servicio = 3; }),
    'Línea 1: elige si es un bien o un servicio.');
$rechaza('product_id "x"', $cuerpo(function (object $b) { $b->items[0]->product_id = 'x'; }),
    'Línea 1: el producto no es válido. Búscalo de nuevo o déjala como línea libre.');
$rechaza('product_id -3', $cuerpo(function (object $b) { $b->items[0]->product_id = -3; }),
    'Línea 1: el producto no es válido. Búscalo de nuevo o déjala como línea libre.');
$rechaza('ajustes que no son objeto', $cuerpo(function (object $b) { $b->ajustes = 'mucho'; }),
    'Los cargos y abonos de la cotización no son válidos. Revísalos.');
$rechaza('concepto desconocido', $cuerpo(function (object $b) { $b->ajustes->descuento = 10; }),
    'Los cargos y abonos traen un concepto que este formato no conoce («descuento»).');
$rechaza('mano de obra negativa', $cuerpo(function (object $b) { $b->ajustes->mano_obra = -1; }),
    '«Costo mano de obra» no puede ser negativo.');
$rechaza('abono con 3 decimales', $cuerpo(function (object $b) { $b->ajustes->abono = 10.555; }),
    '«Abono realizado» admite hasta 2 decimales.');
$rechaza('cargos bancarios "mucho"', $cuerpo(function (object $b) { $b->ajustes->cargos_bancarios = 'mucho'; }),
    '«Cargos bancarios» no es un monto válido. Revísalo.');
$rechaza('manejo bancario con 3 decimales', $cuerpo(function (object $b) { $b->ajustes->manejo_bancario = 0.001; }),
    '«Manejos de operaciones bancarias» admite hasta 2 decimales.');
foreach ([1, 'true', null, 0] as $malo) {
    $rechaza('retencion_isr ' . var_export($malo, true), $cuerpo(function (object $b) use ($malo) { $b->ajustes->retencion_isr = $malo; }),
        'La casilla «Retención Renta por Tercero 5%» no es válida: tiene que ser sí o no.');
}

// ===========================================================================
// T5 — PDF de Ferreteria (FerreteriaCotizacionPdf)
// ===========================================================================
// Las aserciones corren siempre (sin BD, ~0.5 s). Con --pdf ademas escribe los
// PDF en tools/out/ para compararlos a ojo con la hoja de Excel; --grid (que
// implica --pdf) les superpone la rejilla de calibracion de 10 mm.
//
// FPDF comprime el contenido de cada pagina (FlateDecode). No se apaga la
// compresion ni se agrega un modo "de prueba" al renderizador: el script infla
// los streams con gzuncompress y busca el texto ahi, asi se prueba el mismo
// PDF que recibe el usuario.

require_once __DIR__ . '/../src/Utils/Cotizacion/FerreteriaCotizacionPdf.php';

echo "\n== T5: PDF de Ferreteria (FerreteriaCotizacionPdf) ==\n";

$argsPdf = array_slice($argv ?? [], 1);
$conGrillaPdf = in_array('--grid', $argsPdf, true);
$escribirPdf = $conGrillaPdf || in_array('--pdf', $argsPdf, true);

$fxPdf = json_decode((string) file_get_contents(__DIR__ . '/fixtures/cotizacion_ferreteria.json'), true);
$casosPdf = [];
foreach ($fxPdf['casos'] as $casoFx) {
    $casosPdf[$casoFx['id']] = $casoFx;
}
$logoPdf = is_file(__DIR__ . '/fixtures/ferreteria_logo.jpeg') ? __DIR__ . '/fixtures/ferreteria_logo.jpeg' : null;
$chk('fixture: tools/fixtures/ferreteria_logo.jpeg existe', $logoPdf !== null);

// Lo mismo que le pasara FerreteriaFormato::pdf()/preview(): items + totales ya
// calculados por totales(). Los casos sin fecha, cliente o ajustes propios
// (largo_60, mixto) usan los de pintura.
$armarPdf = static function (array $caso, ?string $code, ?string $logo, bool $grilla = false) use ($fxPdf, $casosPdf): FerreteriaCotizacionPdf {
    $sinAjustes = ['cargos_bancarios' => 0, 'manejo_bancario' => 0, 'mano_obra' => 0, 'abono' => 0, 'retencion_isr' => false];
    $lineas = array_map(static fn(array $l): array => [
        'quantity' => (float) $l['quantity'],
        'amount' => (float) $l['amount'],
        'indicador_facturacion' => (int) $l['indicador_facturacion'],
    ], $caso['lineas']);
    $cotizacion = [
        'code' => $code,
        'date' => $caso['date'] ?? $casosPdf['pintura']['date'],
        'items' => array_map(static fn(array $l): array => [
            'description' => (string) $l['description'],
            'quantity' => (float) $l['quantity'],
            'amount' => (float) $l['amount'],
        ], $caso['lineas']),
        'totales' => FerreteriaFormato::totales($lineas, $caso['ajustes'] ?? $sinAjustes),
    ];
    $pdf = new FerreteriaCotizacionPdf($cotizacion, $fxPdf['emisor'], $caso['cliente'] ?? $casosPdf['pintura']['cliente'], $logo);
    $pdf->setDebugGrid($grilla);
    return $pdf;
};

// Paginas = objetos "/Type /Page" (el \b deja fuera el "/Type /Pages" del arbol).
$contarPaginas = static fn(string $bytes): int => (int) preg_match_all('#/Type /Page\b#', $bytes);
// FPDF escribe primero el contenido de las paginas, en orden, y despues los
// recursos (el logo): los N primeros streams son las N paginas.
$paginasPdf = static function (string $bytes) use ($contarPaginas): array {
    preg_match_all('/stream\n(.*?)\nendstream/s', $bytes, $m);
    $paginas = [];
    foreach (array_slice($m[1], 0, $contarPaginas($bytes)) as $s) {
        $plano = @gzuncompress($s);
        $paginas[] = $plano === false ? $s : $plano;
    }
    return $paginas;
};
// El texto va en ISO-8859-1 dentro de "(...) Tj": se busca igual.
$iso = static fn(string $s): string => mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8');

// --- el renderizador es puro (spec 7): ni BD ni tenant ni branding ---
$codigoPdf = '';
foreach (token_get_all((string) file_get_contents(__DIR__ . '/../src/Utils/Cotizacion/FerreteriaCotizacionPdf.php')) as $tok) {
    if (is_array($tok) && in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) {
        continue;
    }
    $codigoPdf .= is_array($tok) ? $tok[1] : $tok;
}
$chk('puro: no usa Database, TenantResolver, BrandingResolver ni EmisorConfigModel',
    !preg_match('/\b(Database|TenantResolver|BrandingResolver|EmisorConfigModel)\b/', $codigoPdf));

// --- pintura: 1 pagina, numero, totales de su hoja ---
$bytesPintura = $armarPdf($casosPdf['pintura'], $casosPdf['pintura']['code'], $logoPdf)->render();
$txtPintura = implode("\n", $paginasPdf($bytesPintura));
$chk('pintura: render() devuelve un PDF (%PDF)', str_starts_with($bytesPintura, '%PDF'));
$chk('pintura: exactamente 1 pagina', $contarPaginas($bytesPintura) === 1);
$chk('pintura: una sola pagina no lleva "Pagina X de Y"', !str_contains($txtPintura, $iso('Página 1 de')));
$chk('pintura: imprime su numero COT-000001', str_contains($txtPintura, '(COT-000001)'));
$chk('pintura: titulo, fecha larga y rotulo del cliente',
    str_contains($txtPintura, $iso('(COTIZACIÓN MERCANCÍAS)'))
    && str_contains($txtPintura, '(MAYO 14/2026.-)')
    && str_contains($txtPintura, $iso('(NOMBRE O RAZÓN SOCIAL)')));
$chk('pintura: RNC del emisor y del cliente formateados',
    str_contains($txtPintura, '(RNC 132-61512-3)') && str_contains($txtPintura, '(401-51513-1)'));
$chk('pintura: cabecera de las 4 columnas',
    str_contains($txtPintura, '(Cantidad)') && str_contains($txtPintura, $iso('(Descripción mercancías)'))
    && str_contains($txtPintura, '(Valor Unitario)') && str_contains($txtPintura, '(Valor Total RD$)'));
$chk('pintura: linea 7.00 x 2,000.00 = 14,000.00',
    str_contains($txtPintura, '(7.00)') && str_contains($txtPintura, '(2,000.00)') && str_contains($txtPintura, '(14,000.00)'));
$chk('pintura: marca "No hay mas productos"', str_contains($txtPintura, $iso('No hay más productos debajo de la línea')));
$chk('pintura: Sub-total 41,860.00 / ITBIS 18% 7,534.80 / TOTAL 49,394.80',
    str_contains($txtPintura, '(41,860.00)') && str_contains($txtPintura, '(ITBIS 18%)')
    && str_contains($txtPintura, '(7,534.80)') && str_contains($txtPintura, '(49,394.80)'));
$chk('pintura: sin cargos, retencion, abono ni restante (no tienen valor)',
    !str_contains($txtPintura, 'Cargos bancarios') && !str_contains($txtPintura, 'Costo mano de obra')
    && !str_contains($txtPintura, 'Tercero 5%') && !str_contains($txtPintura, 'Abono') && !str_contains($txtPintura, 'Restante'));
$chk('pintura: Recibido por + pie (razon social, correo, telefono)',
    str_contains($txtPintura, '(Recibido por:)') && str_contains($txtPintura, '(FERREHERRAMIENTAS VENTURA, SRL)')
    && str_contains($txtPintura, '(yaironventura0201@hotmail.com)') && str_contains($txtPintura, $iso('(Teléfono 829-898-7798)')));
$chk('pintura: el correo del pie es un enlace mailto', str_contains($bytesPintura, '/URI (mailto:yaironventura0201@hotmail.com)'));
$chk('pintura: no imprime cuenta bancaria ni sello de Gratex', !str_contains($txtPintura, '790371603') && !str_contains($txtPintura, 'Cuenta'));

// --- vista previa: sin numero ---
$txtPrevia = implode("\n", $paginasPdf($armarPdf($casosPdf['pintura'], null, $logoPdf)->render()));
$chk('vista previa (code null): imprime VISTA PREVIA', str_contains($txtPrevia, '(VISTA PREVIA)'));
$chk('vista previa: no inventa un numero COT-', !str_contains($txtPrevia, 'COT-'));

// --- filas de ajustes: solo las que tienen valor ---
$txtRet = implode("\n", $paginasPdf($armarPdf($casosPdf['pintura_retencion_abono'], 'COT-000001', $logoPdf)->render()));
$chk('retencion+abono: Retencion 2,093.00, Abono 10,000.00, Restante (Adeudado) 37,301.80',
    str_contains($txtRet, $iso('(Retención Renta por Tercero 5%)')) && str_contains($txtRet, '(2,093.00)')
    && str_contains($txtRet, '(Abono realizado)') && str_contains($txtRet, '(10,000.00)')
    // FPDF escapa los parentesis del texto: "(Restante \(Adeudado\))".
    && str_contains($txtRet, '(Restante \(Adeudado\))') && str_contains($txtRet, '(37,301.80)'));
$txtMano = implode("\n", $paginasPdf($armarPdf($casosPdf['pintura_mano_obra'], 'COT-000001', $logoPdf)->render()));
$chk('mano de obra: Cargos 100.00, Manejos 50.00, Mano de obra 1,500.00, TOTAL 51,044.80',
    str_contains($txtMano, '(Cargos bancarios)') && str_contains($txtMano, '(100.00)')
    && str_contains($txtMano, '(Manejos de operaciones bancarias)') && str_contains($txtMano, '(50.00)')
    && str_contains($txtMano, '(Costo mano de obra)') && str_contains($txtMano, '(1,500.00)')
    && str_contains($txtMano, '(51,044.80)'));
$chk('mano de obra: sin Restante (no hay retencion ni abono)', !str_contains($txtMano, 'Restante'));

// --- etiqueta ITBIS y precio con 4 decimales ---
$txtMixto = implode("\n", $paginasPdf($armarPdf($casosPdf['mixto'], 'COT-000009', $logoPdf)->render()));
$chk('mixto: rotulo "ITBIS" a secas (no todo es 18%)', str_contains($txtMixto, '(ITBIS)') && !str_contains($txtMixto, 'ITBIS 18%'));
$caso4Dec = ['lineas' => [['quantity' => 3, 'amount' => 84.7458, 'indicador_facturacion' => 1, 'description' => 'PRUEBA PRECIO 4 DECIMALES']]]
    + $casosPdf['pintura'];
$txt4Dec = implode("\n", $paginasPdf($armarPdf($caso4Dec, 'COT-000010', $logoPdf)->render()));
$chk('precio 84.7458: Valor Unitario 84.7458 y Valor Total 254.24 (textoPrecio)',
    str_contains($txt4Dec, '(3.00)') && str_contains($txt4Dec, '(84.7458)') && str_contains($txt4Dec, '(254.24)'));

// --- logo: presente, ausente, inexistente, ilegible ---
if ($logoPdf !== null) {
    $chk('con logo: incrusta la imagen', str_contains($bytesPintura, '/Subtype /Image'));
    $chk('con logo: la razon social sale solo en el pie', substr_count($txtPintura, '(FERREHERRAMIENTAS VENTURA, SRL)') === 1);
}
$bytesSinLogo = $armarPdf($casosPdf['pintura'], 'COT-000001', null)->render();
$chk('sin logo: no incrusta imagen', !str_contains($bytesSinLogo, '/Subtype /Image'));
$chk('sin logo: razon social arriba (en su lugar) y en el pie',
    substr_count(implode("\n", $paginasPdf($bytesSinLogo)), '(FERREHERRAMIENTAS VENTURA, SRL)') === 2);
$bytesNoExiste = $armarPdf($casosPdf['pintura'], 'COT-000001', __DIR__ . '/fixtures/no_existe.png')->render();
$chk('logo inexistente: el PDF sale igual, sin imagen', str_starts_with($bytesNoExiste, '%PDF') && !str_contains($bytesNoExiste, '/Subtype /Image'));
$bytesNoImagen = $armarPdf($casosPdf['pintura'], 'COT-000001', __DIR__ . '/fixtures/cotizacion_ferreteria.json')->render();
$chk('logo que no es imagen: el PDF sale igual, sin imagen', str_starts_with($bytesNoImagen, '%PDF') && !str_contains($bytesNoImagen, '/Subtype /Image'));
if (function_exists('imagecreatetruecolor')) {
    // PNG entrelazado: getimagesize lo acepta pero FPDF lanza al leerlo.
    $pngEntrelazado = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cotizacion_ferreteria_' . getmypid() . '.png';
    $imgPdf = imagecreatetruecolor(40, 20);
    imageinterlace($imgPdf, true);
    imagepng($imgPdf, $pngEntrelazado);
    echo "     (se espera un aviso \"logo ilegible\" en stderr)\n";
    $bytesEntrelazado = $armarPdf($casosPdf['pintura'], 'COT-000001', $pngEntrelazado)->render();
    @unlink($pngEntrelazado);
    $chk('logo que FPDF no soporta (PNG entrelazado): sale con la razon social',
        str_starts_with($bytesEntrelazado, '%PDF') && !str_contains($bytesEntrelazado, '/Subtype /Image')
        && substr_count(implode("\n", $paginasPdf($bytesEntrelazado)), '(FERREHERRAMIENTAS VENTURA, SRL)') === 2);
}

// --- largo_60: saltos de pagina ---
$largoPdf = $casosPdf['largo_60'];
$bytesLargo = $armarPdf($largoPdf, 'COT-000060', $logoPdf)->render();
$nLargo = $contarPaginas($bytesLargo);
$pagsLargo = $paginasPdf($bytesLargo);
$chk("largo_60: mas de una pagina ({$nLargo})", $nLargo > 1);
$chk('largo_60: un stream de contenido por pagina', count($pagsLargo) === $nLargo);
$numeradas = true;
foreach ($pagsLargo as $kPag => $pPag) {
    $numeradas = $numeradas && str_contains($pPag, $iso('(Página ' . ($kPag + 1) . ' de ' . $nLargo . ')'));
}
$chk("largo_60: cada pagina dice \"Pagina X de {$nLargo}\"", $numeradas);
$chk('largo_60: las 60 filas impresas', str_contains(implode("\n", $pagsLargo), 'NUMERO 60 CON') && str_contains($pagsLargo[0], 'NUMERO 1 CON'));

// Las reglas de corte de la spec 7 para cada largo de 1 a 60 lineas: asi el
// corte cae en todos los puntos posibles de la pagina, no solo en uno.
$marcaSuelta = [];
$cierrePartido = [];
$cabeceraMal = [];
$cierreSolo = 0;
for ($nFilas = 1; $nFilas <= count($largoPdf['lineas']); $nFilas++) {
    $subPdf = ['lineas' => array_slice($largoPdf['lineas'], 0, $nFilas)] + $largoPdf;
    $pags = $paginasPdf($armarPdf($subPdf, 'COT-000060', $logoPdf)->render());
    $ultimaPag = count($pags) - 1;
    $donde = static function (string $aguja) use ($pags): array {
        return array_keys(array_filter($pags, static fn(string $p): bool => str_contains($p, $aguja)));
    };
    // 1) la marca en la misma pagina que la ultima fila
    $pagFila = $donde('NUMERO ' . $nFilas . ' CON');
    if ($pagFila === [] || $pagFila !== $donde('No hay m')) {
        $marcaSuelta[] = $nFilas;
    }
    // 2) totales + Recibido por + pie, todos en la ultima pagina
    foreach (['(Sub-total RD$)', '(TOTAL RD$)', '(Recibido por:)', $iso('(Teléfono 829-898-7798)')] as $aguja) {
        if ($donde($aguja) !== [$ultimaPag]) {
            $cierrePartido[] = $nFilas;
            break;
        }
    }
    // 3) cabecera de tabla en cada pagina con filas, y en ninguna otra
    foreach ($pags as $kPag => $pPag) {
        if (str_contains($pPag, 'ARTICULO DE PRUEBA') !== str_contains($pPag, '(Valor Unitario)')) {
            $cabeceraMal[] = $nFilas . '/p' . ($kPag + 1);
        }
    }
    if (!str_contains($pags[$ultimaPag], 'ARTICULO DE PRUEBA')) {
        $cierreSolo++;
    }
}
$chk('cortes 1..60: la marca siempre en la pagina de la ultima fila'
    . ($marcaSuelta ? ' (fallan n=' . implode(',', $marcaSuelta) . ')' : ''), $marcaSuelta === []);
$chk('cortes 1..60: el bloque de cierre nunca se parte y va en la ultima pagina'
    . ($cierrePartido ? ' (fallan n=' . implode(',', $cierrePartido) . ')' : ''), $cierrePartido === []);
$chk('cortes 1..60: cabecera de tabla solo en paginas con filas'
    . ($cabeceraMal ? ' (fallan ' . implode(',', $cabeceraMal) . ')' : ''), $cabeceraMal === []);
$chk("cortes 1..60: algun largo empuja el cierre solo a una pagina nueva ({$cierreSolo} casos)", $cierreSolo > 0);

// --- --pdf [--grid]: archivos para la comparacion visual con el Excel ---
if ($escribirPdf) {
    $dirOut = __DIR__ . '/out';
    if (!is_dir($dirOut)) {
        mkdir($dirOut, 0775, true);
    }
    $salidasPdf = [
        'pintura' => [$casosPdf['pintura'], $casosPdf['pintura']['code'] ?? 'COT-000001'],
        'b150000049' => [$casosPdf['b150000049'], $casosPdf['b150000049']['code'] ?? 'COT-000002'],
        'ceramicas' => [$casosPdf['ceramicas'], $casosPdf['ceramicas']['code'] ?? 'COT-000003'],
        'largo_60' => [$largoPdf, $largoPdf['code'] ?? 'COT-000060'],
        // Extras: las filas opcionales de totales y la vista previa sin numero.
        'pintura_retencion_abono' => [$casosPdf['pintura_retencion_abono'], $casosPdf['pintura_retencion_abono']['code'] ?? 'COT-000001'],
        'vista_previa' => [$casosPdf['pintura'], null],
    ];
    foreach ($salidasPdf as $idPdf => [$casoPdf, $codePdf]) {
        $rutaPdf = $dirOut . '/cotizacion_ferreteria_' . $idPdf . '.pdf';
        $okPdf = file_put_contents($rutaPdf, $armarPdf($casoPdf, $codePdf, $logoPdf, $conGrillaPdf)->render()) !== false;
        $chk('--pdf: tools/out/' . basename($rutaPdf) . ($conGrillaPdf ? ' (con rejilla)' : ''), $okPdf);
    }
}

// ---------------------------------------------------------------------------
// Registro de formatos y contrato CotizacionFormato (Task 6)
// ---------------------------------------------------------------------------

require_once __DIR__ . '/../src/Utils/Cotizacion/CotizacionFormatos.php';

echo "\n== Registro de formatos (CotizacionFormatos) ==\n";

// Un cotizacionModel sin pasar por el constructor, que es el que abre la
// conexión: aquí nada llega a la DB (para() solo construye el formato).
$modeloSinDb = (new ReflectionClass('cotizacionModel'))->newInstanceWithoutConstructor();

// para() y delTenant() avisan en el error_log de un formato desconocido. Mientras
// corre la sección el log va a un archivo temporal: se comprueba el aviso y la
// salida queda limpia.
$logFormatos = (string) tempnam(sys_get_temp_dir(), 'cotfmt');
$logAnteriorFormatos = ini_set('error_log', $logFormatos);
$leerLogFormatos = function () use ($logFormatos): string {
    clearstatcache();
    $txt = (string) file_get_contents($logFormatos);
    file_put_contents($logFormatos, '');
    return $txt;
};

// La firma de cada método, para fijar el contrato que implementa cada formato.
$firmaFormato = static function (string $clase, string $metodo): string {
    $m = new ReflectionMethod($clase, $metodo);
    $params = array_map(static fn(ReflectionParameter $p) => $p->getType() . ' $' . $p->getName(), $m->getParameters());
    return $metodo . '(' . implode(', ', $params) . '): ' . $m->getReturnType();
};
$contratoFormato = [
    'nombre(): string',
    'crear(object $body): array',
    'actualizar(array $row, object $body): array',
    'preview(object $body, ?array $row): array',
    'pdf(array $cotizacion): array',
    'permiteCorreo(): bool',
];
$firmasDe = static fn(string $clase): array => array_map(
    static fn(string $f) => $firmaFormato($clase, strstr($f, '(', true)),
    $contratoFormato
);
$chk('CotizacionFormato: abstracta, con los 6 métodos del contrato',
    (new ReflectionClass('CotizacionFormato'))->isAbstract() && $firmasDe('CotizacionFormato') === $contratoFormato);
$formatoMinimo = new class extends CotizacionFormato {
    public function nombre(): string { return 'minimo'; }
    public function crear(object $body): array { return ['error', 'no', 422]; }
    public function actualizar(array $row, object $body): array { return ['error', 'no', 422]; }
    public function preview(object $body, ?array $row): array { return ['error', 'no', 422]; }
    public function pdf(array $cotizacion): array { return ['error', 'no', 422]; }
};
$chk('un formato nuevo no ofrece correo salvo que lo diga (permiteCorreo() = false)', $formatoMinimo->permiteCorreo() === false);

$chk("DEFAULT = 'gratex'", CotizacionFormatos::DEFAULT === 'gratex');
$chk("existe('gratex')", CotizacionFormatos::existe('gratex'));
$chk('existe(null), existe(\'\') y existe(\'x\') = false',
    !CotizacionFormatos::existe(null) && !CotizacionFormatos::existe('') && !CotizacionFormatos::existe('x'));
$chk("existe('GRATEX') = false: la clave es exacta", !CotizacionFormatos::existe('GRATEX'));

$formatoGratex = CotizacionFormatos::para('gratex', $modeloSinDb);
$chk("para('gratex') = GratexFormato", get_class($formatoGratex) === 'GratexFormato' && $formatoGratex instanceof CotizacionFormato);
$chk("GratexFormato: nombre() = 'gratex', permiteCorreo() = true", $formatoGratex->nombre() === 'gratex' && $formatoGratex->permiteCorreo() === true);
$chk('GratexFormato cumple el contrato (mismas firmas)', $firmasDe('GratexFormato') === $contratoFormato);
$chk('el modelo que recibe para() es el que usa el formato',
    (fn() => $this->modelo)->call($formatoGratex) === $modeloSinDb);
$chk("para('gratex') no avisa nada", $leerLogFormatos() === '');
$chk('para(null) = GratexFormato, sin aviso (NULL = cotización de antes de los formatos)',
    get_class(CotizacionFormatos::para(null, $modeloSinDb)) === 'GratexFormato' && $leerLogFormatos() === '');
$chk("para('x') = GratexFormato y lo avisa en el error_log",
    get_class(CotizacionFormatos::para('x', $modeloSinDb)) === 'GratexFormato'
    && str_contains($leerLogFormatos(), 'formato desconocido "x"'));

// delTenant() lee TenantResolver::current(): se le pone el tenant a mano (es
// privado; Reflection basta para un CLI) y se deja como estaba, sin tenant.
$tenantResuelto = new ReflectionProperty('TenantResolver', 'current');
$conTenant = function (?array $tenant) use ($tenantResuelto): string {
    $tenantResuelto->setValue(null, $tenant);
    return CotizacionFormatos::delTenant();
};
$chk('delTenant() sin tenant resuelto = gratex', $conTenant(null) === 'gratex' && $leerLogFormatos() === '');
$chk('delTenant(): tenant sin la columna (master sin la 011) = gratex, sin aviso',
    $conTenant(['id' => 1, 'rnc' => '101000000', 'tipo' => 'app']) === 'gratex' && $leerLogFormatos() === '');
$chk("delTenant(): cotizacion_formato 'gratex' = gratex",
    $conTenant(['id' => 1, 'cotizacion_formato' => 'gratex']) === 'gratex' && $leerLogFormatos() === '');
$chk("delTenant(): 'Ferreteria' (mal escrito en el UPDATE) = gratex, y lo avisa",
    $conTenant(['id' => 5, 'cotizacion_formato' => 'Ferreteria']) === 'gratex'
    && str_contains($leerLogFormatos(), '"Ferreteria" no es un formato conocido'));
$tenantResuelto->setValue(null, null);

$chk('delCuerpo(cuerpo vacío) = gratex', CotizacionFormatos::delCuerpo(new stdClass()) === 'gratex');
$chk('delCuerpo(): el cuerpo de CotizacionFormView (sin "formato") = gratex', CotizacionFormatos::delCuerpo((object) [
    'client_id' => 5, 'date' => '2026-09-02 10:15:00', 'total' => 236, 'user_id' => 3, 'sent_email' => false,
    'items' => [(object) ['description' => 'TUBO', 'amount' => 200, 'quantity' => 1, 'subtotal' => 200]],
]) === 'gratex');
$chk("delCuerpo(): formato 'ferreteria' y 'gratex' se devuelven tal cual",
    CotizacionFormatos::delCuerpo((object) ['formato' => 'ferreteria']) === 'ferreteria'
    && CotizacionFormatos::delCuerpo((object) ['formato' => 'gratex']) === 'gratex');
$chk('delCuerpo(): formato null o que no es texto (5, true, []) = gratex',
    CotizacionFormatos::delCuerpo((object) ['formato' => null]) === 'gratex'
    && CotizacionFormatos::delCuerpo((object) ['formato' => 5]) === 'gratex'
    && CotizacionFormatos::delCuerpo((object) ['formato' => true]) === 'gratex'
    && CotizacionFormatos::delCuerpo((object) ['formato' => []]) === 'gratex');
$chk("delCuerpo(): formato '' es texto y se devuelve tal cual (409 en cualquier tenant)",
    CotizacionFormatos::delCuerpo((object) ['formato' => '']) === '');

$chk('MSG_DESACTUALIZADA (el 409) = el mensaje de la spec', CotizacionFormatos::MSG_DESACTUALIZADA
    === 'La pantalla de cotizaciones está desactualizada (cambió el formato de tu empresa). Recarga la página.');

ini_set('error_log', $logAnteriorFormatos === false ? '' : $logAnteriorFormatos);
@unlink($logFormatos);

// ===========================================================================
// T7 — cotizacionModel y FerreteriaFormato sin MySQL (Task 7)
// ===========================================================================
// El modelo se crea sin constructor (no conecta) y con una conexion falsa que
// registra cada SQL y contesta lo minimo que el modelo lee: $conexion no tiene
// tipo, asi que basta con los mismos metodos que PDO. Nada de esta seccion
// abre una base de datos ni instancia Database/MasterDatabase: crear(),
// actualizar(), preview() y pdf() de FerreteriaFormato si lo harian, y se
// prueban en el servidor (tests/test_cotizaciones_ferreteria.http).

require_once __DIR__ . '/../src/Models/cotizacionModel.php';
require_once __DIR__ . '/../src/Utils/Cotizacion/CotizacionFormatos.php';

/** Conexion falsa: registra SQL y parametros y contesta lo minimo que lee el modelo. */
final class ConexionFalsaT7
{
    /** @var array<int,array{0:string,1:array}> cada execute(), en orden */
    public array $ejecutados = [];
    /** @var string[] cada SQL preparado o consultado, en orden */
    public array $consultas = [];
    /** Filas de "SELECT c.* ..." (getCotizaciones / getCotizacionesPaginated). */
    public array $cabeceras = [];
    /** Filas de "SELECT * FROM cotizacion_items". */
    public array $lineas = [];
    /** [concepto => monto] de cotizacion_ajustes (lo que da FETCH_KEY_PAIR). */
    public array $ajustes = [];
    /** Fila de "SELECT * FROM clients" (false = no existe). */
    public array|false $cliente = false;
    public int $maxNumero = 6;
    /** Cuantos INSERT de cabecera chocan con uk_cotizaciones_numero antes de pasar. */
    public int $choquesNumero = 0;
    /** Lo que devuelve el SELECT ... FOR UPDATE de actualizarConFormato (false = la borraron). */
    public array|false $filaActual = ['code' => 'COT-000004', 'numero' => 4];
    public bool $lockLibre = true;
    /** true = todo prepare() lanza (DB caida o tabla inexistente). */
    public bool $fallarTodo = false;
    public int $commits = 0;
    public int $rollbacks = 0;
    public int $locksTomados = 0;
    public int $locksSoltados = 0;
    private bool $enTransaccion = false;

    public static function error(int $codigo, string $detalle): PDOException
    {
        $e = new PDOException("SQLSTATE[23000]: {$codigo} {$detalle}");
        $e->errorInfo = ['23000', $codigo, $detalle];
        return $e;
    }

    public function query(string $sql): SentenciaFalsaT7
    {
        $this->consultas[] = $sql;
        if (str_contains($sql, 'GET_LOCK')) {
            $this->locksTomados++;
            return new SentenciaFalsaT7($this, $sql, $this->lockLibre ? 1 : 0);
        }
        if (str_contains($sql, 'RELEASE_LOCK')) {
            $this->locksSoltados++;
            return new SentenciaFalsaT7($this, $sql, 1);
        }
        if (str_contains($sql, 'MAX(numero)')) {
            return new SentenciaFalsaT7($this, $sql, $this->maxNumero + 1);
        }
        return new SentenciaFalsaT7($this, $sql);
    }

    public function prepare(string $sql): SentenciaFalsaT7
    {
        $this->consultas[] = $sql;
        if ($this->fallarTodo) {
            throw self::error(1146, "Table 'tenant.cotizacion_ajustes' doesn't exist");
        }
        return new SentenciaFalsaT7($this, $sql);
    }

    public function beginTransaction(): bool { $this->enTransaccion = true; return true; }
    public function commit(): bool { $this->enTransaccion = false; $this->commits++; return true; }
    public function rollBack(): bool { $this->enTransaccion = false; $this->rollbacks++; return true; }
    public function inTransaction(): bool { return $this->enTransaccion; }
    public function lastInsertId(): string { return '77'; }

    /** Parametros de cada execute() cuyo SQL empieza con $inicio. */
    public function paramsDe(string $inicio): array
    {
        $out = [];
        foreach ($this->ejecutados as [$sql, $params]) {
            if (str_starts_with(ltrim($sql), $inicio)) {
                $out[] = $params;
            }
        }
        return $out;
    }

    /** ¿Algun SQL registrado contiene $texto? */
    public function huboSql(string $texto): bool
    {
        foreach ($this->consultas as $sql) {
            if (str_contains($sql, $texto)) {
                return true;
            }
        }
        return false;
    }
}

final class SentenciaFalsaT7
{
    public function __construct(private ConexionFalsaT7 $c, private string $sql, private mixed $columna = null) {}

    public function bindValue($clave, $valor, $tipo = null): bool { return true; }

    public function execute(?array $params = null): bool
    {
        $this->c->ejecutados[] = [$this->sql, $params ?? []];
        if (str_starts_with(ltrim($this->sql), 'INSERT INTO cotizaciones') && $this->c->choquesNumero > 0) {
            $this->c->choquesNumero--;
            $this->c->maxNumero++; // otra caja grabo ese numero mientras tanto
            throw ConexionFalsaT7::error(1062, "Duplicate entry '" . $this->c->maxNumero . "' for key 'cotizaciones.uk_cotizaciones_numero'");
        }
        return true;
    }

    public function fetchColumn() { return $this->columna; }

    public function fetch()
    {
        if (str_contains($this->sql, 'FOR UPDATE')) {
            return $this->c->filaActual;
        }
        if (str_contains($this->sql, 'FROM clients')) {
            return $this->c->cliente;
        }
        return false;
    }

    public function fetchAll($modo = null): array
    {
        if (str_starts_with($this->sql, 'SELECT c.*')) {
            return $this->c->cabeceras;
        }
        if (str_starts_with($this->sql, 'SELECT * FROM cotizacion_items')) {
            return $this->c->lineas;
        }
        if (str_contains($this->sql, 'FROM cotizacion_ajustes')) {
            return $modo === PDO::FETCH_KEY_PAIR ? $this->c->ajustes : [];
        }
        return [];
    }
}

/** Modelo sin constructor (no conecta) con la conexion falsa puesta. */
$modeloT7 = static function (ConexionFalsaT7 $c): cotizacionModel {
    $m = (new ReflectionClass('cotizacionModel'))->newInstanceWithoutConstructor();
    (new ReflectionProperty('cotizacionModel', 'conexion'))->setValue($m, $c);
    return $m;
};
/** Llama un metodo privado del modelo (PHP >= 8.1 no pide setAccessible). */
$privadoT7 = static fn(?cotizacionModel $m, string $metodo, ...$args) => (new ReflectionMethod('cotizacionModel', $metodo))->invoke($m, ...$args);

// Filas como las devuelve PDO: los DECIMAL como texto con los ceros de la columna.
$comoFilasT7 = static fn(array $lineas): array => array_map(static fn(array $l): array => [
    'id' => 1, 'cotizacion_id' => 1, 'product_id' => null,
    'description' => $l['description'],
    'amount' => number_format((float) $l['amount'], 4, '.', ''),
    'quantity' => number_format((float) $l['quantity'], 3, '.', ''),
    'subtotal' => '0.00', 'unidad_medida' => '43',
    'indicador_facturacion' => (int) $l['indicador_facturacion'],
    'indicador_bien_servicio' => 1, 'itbis_amount' => '0.00',
], $lineas);

// Los error_log del modelo (esperados en estos casos) van a un archivo y no
// ensucian la salida; se restaura al final de este bloque.
$logPrevioT7 = ini_set('error_log', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_cotizacion_ferreteria_t7.log');

echo "\n== T7: lecturas de cotizacionModel ==\n";
$c = new ConexionFalsaT7();
$modeloT7($c)->getCotizacionItems(5);
$sqlItemsT7 = (string) end($c->consultas);
$chk('lineas: SELECT * ... ORDER BY id ASC', $sqlItemsT7 === 'SELECT * FROM cotizacion_items WHERE cotizacion_id = :cotizacion_id ORDER BY id ASC');
$chk('lineas: no nombra columnas de la 026 (sin 026 no vaciaria las lineas)',
    stripos($sqlItemsT7, 'product_id') === false && stripos($sqlItemsT7, 'itbis_amount') === false);

$c = new ConexionFalsaT7();
$c->cabeceras = [
    ['id' => 3, 'code' => 'ABC123', 'formato' => null, 'client_id' => 1, 'total' => '100.00'],
    ['id' => 9, 'code' => 'COT-000004', 'formato' => 'ferreteria', 'numero' => 4, 'client_id' => 15, 'total' => '49394.80'],
];
$c->lineas = $comoFilasT7($casos['pintura']['lineas']);
$c->ajustes = ['abono' => '10000.00', 'retencion_isr' => '2093.00'];
$filasT7 = $modeloT7($c)->getCotizaciones(9);
$jsonT7 = json_decode((string) json_encode($filasT7), true);
$chk('getCotizaciones: fila Gratex con "ajustes": {} (objeto, no [])', str_contains((string) json_encode($filasT7[0]), '"ajustes":{}'));
$chk('getCotizaciones: fila Ferreteria con sus ajustes como objeto',
    ($jsonT7[1]['ajustes'] ?? null) === ['abono' => '10000.00', 'retencion_isr' => '2093.00']
    && str_contains((string) json_encode($filasT7[1]), '"ajustes":{"abono"'));
$idsAjustesT7 = array_column($c->paramsDe('SELECT concepto, monto FROM cotizacion_ajustes'), ':id');
$chk('getCotizaciones: cotizacion_ajustes solo se consulta para la fila Ferreteria', $idsAjustesT7 === [9]);
$chk('getCotizaciones: items de SELECT * (traen las columnas de la 026)', count($filasT7[1]['items']) === 7
    && array_key_exists('indicador_facturacion', $filasT7[1]['items'][0]));

$c = new ConexionFalsaT7();
$c->cabeceras = [['id' => 3, 'code' => 'ABC123', 'client_id' => 1, 'total' => '100.00']];   // base sin la 026: no hay columna formato
$filasT7 = $modeloT7($c)->getCotizacionesPaginated(0, 10);
$chk('listado: empate de fecha ordenado por id', str_contains($c->consultas[0] ?? '', 'ORDER BY c.date DESC, c.id DESC LIMIT'));
$chk('listado: fila sin columna formato (antes de la 026) = {} sin consultar cotizacion_ajustes',
    json_encode($filasT7[0]['ajustes'] ?? null) === '{}' && !$c->huboSql('cotizacion_ajustes'));
$c = new ConexionFalsaT7();
$aj = $privadoT7($modeloT7($c), 'ajustesDeFila', ['id' => 9, 'formato' => 'gratex']);
$chk("ajustesDeFila: formato 'gratex' = {} sin consultar", json_encode($aj) === '{}' && $c->consultas === []);
$c = new ConexionFalsaT7();
$c->fallarTodo = true;
$chk('getAjustes: tabla inexistente => [] sin lanzar', $modeloT7($c)->getAjustes(9) === []);

$c = new ConexionFalsaT7();
$chk('getProductosInfo: sin ids validos => [] sin consultar', $modeloT7($c)->getProductosInfo([null, 0, '', '0']) === [] && $c->consultas === []);
$chk('getCliente: id inexistente => null', $modeloT7($c)->getCliente(123456) === null);
$c->cliente = ['id' => 15, 'email' => 'a@b.do', 'client_name' => 'Juan', 'company_name' => 'HOSPITAL', 'rnc' => '401515131',
    'razon_social' => 'HOSPITAL DOCENTE', 'direccion' => 'X', 'descuento' => '0.00', 'phone_number' => '809'];
$chk('getCliente: las 5 claves del contrato', $modeloT7($c)->getCliente(15) === [
    'client_name' => 'Juan', 'company_name' => 'HOSPITAL', 'razon_social' => 'HOSPITAL DOCENTE', 'rnc' => '401515131', 'email' => 'a@b.do',
]);

ini_set('error_log', (string) $logPrevioT7);

// --- T7, ronda B: escrituras con formato (crearConFormato / actualizarConFormato) ---
// Los error_log del modelo (esperados en estos casos) van a un archivo y no
// ensucian la salida; se restaura al final de este bloque.
$logPrevioT7 = ini_set('error_log', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_cotizacion_ferreteria_t7.log');

/** 'cot' como lo dejan validarForma + aplicarCatalogo, armado desde un caso del fixture. */
$cotT7 = static fn(array $caso, ?string $fecha): array => [
    'date' => $fecha,
    'client_id' => 15,
    'items' => array_map(static fn(array $l): array => [
        'product_id' => null,
        'description' => $l['description'],
        'quantity' => (float) $l['quantity'],
        'amount' => (float) $l['amount'],
        'unidad_medida' => '43',
        'indicador_facturacion' => (int) $l['indicador_facturacion'],
        'indicador_bien_servicio' => 1,
    ], $caso['lineas']),
    'ajustes' => [
        'cargos_bancarios' => (float) $caso['ajustes']['cargos_bancarios'],
        'manejo_bancario' => (float) $caso['ajustes']['manejo_bancario'],
        'mano_obra' => (float) $caso['ajustes']['mano_obra'],
        'abono' => (float) $caso['ajustes']['abono'],
        'retencion_isr' => (bool) $caso['ajustes']['retencion_isr'],
    ],
];
// Totales como los deja aplicarCatalogo (mismas lineas, mismas reglas).
$totDeT7 = static fn(array $cot): array => FerreteriaFormato::totales(array_map(static fn(array $it): array => [
    'quantity' => $it['quantity'], 'amount' => $it['amount'], 'indicador_facturacion' => $it['indicador_facturacion'],
], $cot['items']), $cot['ajustes']);

echo "\n== T7: crearConFormato / actualizarConFormato (conexion falsa) ==\n";
$cot = $cotT7($casos['pintura_retencion_abono'], '2026-05-14 09:00:00');
$tot = $totDeT7($cot);

$c = new ConexionFalsaT7();
$r = $modeloT7($c)->crearConFormato($cot, $tot, 'ferreteria', 5, 'HOSPITAL DOCENTE DR. FRANCISCO E. MOSCOSO PUELLO');
$chk('crear: success con id, code, numero y total', $r === ['success', ['id' => 77, 'code' => 'COT-000007', 'numero' => 7, 'total' => 49394.8]]);
$cab = $c->paramsDe('INSERT INTO cotizaciones')[0] ?? [];
$chk('crear: cabecera con formato, numero, code y client_name', ($cab[':formato'] ?? null) === 'ferreteria' && ($cab[':numero'] ?? null) === 7
    && ($cab[':code'] ?? null) === 'COT-000007' && ($cab[':client_name'] ?? null) === 'HOSPITAL DOCENTE DR. FRANCISCO E. MOSCOSO PUELLO');
$chk('crear: subtotal, itbis y total de totales()', ($cab[':subtotal'] ?? null) === 41860.0 && ($cab[':itbis'] ?? null) === 7534.8
    && ($cab[':total'] ?? null) === 49394.8);
$chk('crear: user_id del parametro y fecha del cuerpo', ($cab[':user_id'] ?? null) === 5 && ($cab[':date'] ?? null) === '2026-05-14 09:00:00');
$lin = $c->paramsDe('INSERT INTO cotizacion_items');
$chk('crear: 7 lineas', count($lin) === 7);
$chk('crear: linea 1 con base e ITBIS de totales()', ($lin[0][':subtotal'] ?? null) === 14000.0 && ($lin[0][':itbis_amount'] ?? null) === 2520.0
    && array_key_exists(':product_id', $lin[0]) && $lin[0][':product_id'] === null
    && ($lin[0][':unidad_medida'] ?? null) === '43' && ($lin[0][':indicador_facturacion'] ?? null) === 1);
$aju = $c->paramsDe('INSERT INTO cotizacion_ajustes');
$chk('crear: solo ajustes no cero (abono y la retencion como monto)', array_column($aju, ':monto', ':concepto') === ['abono' => 10000.0, 'retencion_isr' => 2093.0]);
$chk('crear: 1 commit, 0 rollback, candado tomado y soltado', $c->commits === 1 && $c->rollbacks === 0 && $c->locksTomados === 1 && $c->locksSoltados === 1);

$c = new ConexionFalsaT7();
$cotSinFecha = $cotT7($casos['pintura'], null);
$modeloT7($c)->crearConFormato($cotSinFecha, $totDeT7($cotSinFecha), 'ferreteria', null, 'X');
$cab = $c->paramsDe('INSERT INTO cotizaciones')[0] ?? [];
$chk('crear: sin fecha => ahora (Y-m-d H:i:s)', preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) ($cab[':date'] ?? '')) === 1);
$chk('crear: sin ajustes => ningun INSERT de ajustes', $c->paramsDe('INSERT INTO cotizacion_ajustes') === []);

$c = new ConexionFalsaT7();
$c->choquesNumero = 1;
$r = $modeloT7($c)->crearConFormato($cot, $tot, 'ferreteria', 5, 'X');
$chk('1062 una vez: reintenta en otra transaccion y toma el siguiente numero', ($r[0] ?? '') === 'success'
    && ($r[1]['numero'] ?? null) === 8 && ($r[1]['code'] ?? null) === 'COT-000008');
$chk('1062 una vez: 1 rollback, 1 commit, 2 lecturas del MAX, candado soltado una vez', $c->rollbacks === 1 && $c->commits === 1
    && count(array_filter($c->consultas, static fn(string $s): bool => str_contains($s, 'MAX(numero)'))) === 2 && $c->locksSoltados === 1);

$c = new ConexionFalsaT7();
$c->choquesNumero = 2;
$r = $modeloT7($c)->crearConFormato($cot, $tot, 'ferreteria', 5, 'X');
$chk('1062 dos veces: no hay tercer intento', count($c->paramsDe('INSERT INTO cotizaciones')) === 2);
$chk('1062 dos veces: mensaje claro, sin commit, candado soltado', $r === ['error', 'Otra cotización se guardó al mismo tiempo. Vuelve a guardar.']
    && $c->commits === 0 && $c->rollbacks === 2 && $c->locksSoltados === 1);

$c = new ConexionFalsaT7();
$c->lockLibre = false;
$r = $modeloT7($c)->crearConFormato($cot, $tot, 'ferreteria', 5, 'X');
$chk('candado ocupado: guarda igual y no suelta lo que no tomo', ($r[0] ?? '') === 'success' && $c->locksSoltados === 0);

$fkT7 = ConexionFalsaT7::error(1452, 'Cannot add or update a child row: a foreign key constraint fails (`t`.`cotizacion_items`, CONSTRAINT `cotizacion_items_product_fk` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL)');
$r = $privadoT7(null, 'errorConFormato', $fkT7, 'GENERICO');
$chk('1452 del producto (lo borraron) => 422 que se entiende, sin reintento', ($r[0] ?? '') === 'error' && ($r[2] ?? null) === 422
    && str_contains($r[1] ?? '', 'ya no existe en el catálogo'));
$chk('otro error => el generico, sin HTTP propio', $privadoT7(null, 'errorConFormato', ConexionFalsaT7::error(1205, 'Lock wait timeout exceeded'), 'GENERICO') === ['error', 'GENERICO']);
$chk('esNumeroRepetido: solo 1062 en uk_cotizaciones_numero',
    $privadoT7(null, 'esNumeroRepetido', ConexionFalsaT7::error(1062, "Duplicate entry '7' for key 'uk_cotizaciones_numero'"))
    && !$privadoT7(null, 'esNumeroRepetido', ConexionFalsaT7::error(1062, "Duplicate entry '1-abono' for key 'uk_cotizacion_ajuste'")));

$c = new ConexionFalsaT7();
$c->filaActual = false;
$r = $modeloT7($c)->actualizarConFormato(4, $cot, $tot, 5, 'X');
$chk('actualizar: fila borrada => 404 y nada escrito', ($r[0] ?? '') === 'error' && ($r[2] ?? null) === 404
    && $c->paramsDe('UPDATE cotizaciones') === [] && $c->rollbacks === 1);

$c = new ConexionFalsaT7();
$r = $modeloT7($c)->actualizarConFormato(4, $cotSinFecha, $totDeT7($cotSinFecha), 5, 'NUEVO NOMBRE');
$chk('actualizar: numero y code de la fila, nunca nuevos', $r === ['success', ['id' => 4, 'code' => 'COT-000004', 'numero' => 4, 'total' => 49394.8]]);
$upd = $c->paramsDe('UPDATE cotizaciones')[0] ?? [];
$chk('actualizar: date null => conserva la guardada (COALESCE)', array_key_exists(':date', $upd) && $upd[':date'] === null
    && $c->huboSql('date = COALESCE(:date, date)'));
$chk('actualizar: client_name nuevo', ($upd[':client_name'] ?? null) === 'NUEVO NOMBRE');
$chk('actualizar: reemplaza lineas y ajustes', count($c->paramsDe('DELETE FROM cotizacion_items')) === 1
    && count($c->paramsDe('DELETE FROM cotizacion_ajustes')) === 1 && count($c->paramsDe('INSERT INTO cotizacion_items')) === 7
    && $c->paramsDe('INSERT INTO cotizacion_ajustes') === []);
$chk('actualizar: no toca la secuencia', $c->locksTomados === 0 && !$c->huboSql('MAX(numero)'));
$chk('actualizar: 1 commit', $c->commits === 1 && $c->rollbacks === 0);

ini_set('error_log', (string) $logPrevioT7);

// ---------------------------------------------------------------------------
// Las tareas siguientes agregan sus secciones AQUÍ, encima del resumen.
// ---------------------------------------------------------------------------

printf("\n%d/%d OK\n", $total - $fallos, $total);
exit($fallos === 0 ? 0 : 1);
