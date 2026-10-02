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
// Las tareas siguientes agregan sus secciones AQUÍ, encima del resumen.
// ---------------------------------------------------------------------------

printf("\n%d/%d OK\n", $total - $fallos, $total);
exit($fallos === 0 ? 0 : 1);
