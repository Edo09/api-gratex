<?php
/**
 * test_precios_itbis_incluido.php — e-CF con precios que ya traen el ITBIS
 * (IndicadorMontoGravado = 1), sin base de datos ni red.
 *
 * Origen: el POS (docs/specs/pos.md, F4). En un colmado el precio de gondola
 * trae el ITBIS. Con el calculo de siempre (precio neto + 18% encima) hay
 * totales imposibles: 7 x RD$25 da 174.99 o 175.01, nunca 175.00. Con el
 * indicador en 1 el precio del XML es el de gondola y el total sale exacto.
 *
 * La regla de la DGII sale de su set de pruebas (tenant 130968837, 2026-09-02):
 * los montos gravados se calculan por TASA, no por linea, y el ITBIS es la
 * diferencia. E450000000003 lo distingue: por linea daria 404,872.90.
 *
 * Valida:
 *   1. Totales contra los dos casos del set de la DGII, al centavo.
 *   2. 7 x RD$25 = 175.00 con su desglose.
 *   3. 3,000 ventas al azar: lineas y encabezado cuadran siempre.
 *   4. Sin el indicador, todo sigue igual que antes.
 *   5. XML E32/E31/E34 y RFCE: valores y XSD de la DGII.
 *   6. La Representacion Impresa imprime lo firmado, con y sin XML.
 *
 * Uso:
 *   php tools/test_precios_itbis_incluido.php      (sale con 1 si algo falla)
 */

require_once __DIR__ . '/../src/Utils/FacturacionElectronica/EcfItemMapper.php';
require_once __DIR__ . '/../src/Utils/FacturacionElectronica/ECFXmlBuilder.php';
require_once __DIR__ . '/../src/Utils/FacturacionElectronica/RFCEXmlBuilder.php';
require_once __DIR__ . '/../src/Utils/Pdf/EcfDocumento.php';
require_once __DIR__ . '/../src/Utils/FacturacionElectronica/ECFEmissionService.php';

$fallos = 0;
$total = 0;
$chk = function (string $desc, bool $ok, $obtenido = null) use (&$fallos, &$total) {
    $total++;
    if (!$ok) {
        $fallos++;
    }
    printf("  [%s] %s\n", $ok ? 'OK  ' : 'FALLO', $desc);
    if (!$ok && $obtenido !== null) {
        echo '         obtenido: ' . json_encode($obtenido, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }
};
$igual = static fn($a, $b): bool => abs((float) $a - (float) $b) < 0.005;

$linea = static function (int $ind, float $cant, float $precio, ?float $desc = null): array {
    $l = [
        'nombre_item' => 'Item',
        'indicador_facturacion' => $ind,
        'indicador_bien_servicio' => 1,
        'cantidad' => $cant,
        'precio_unitario' => $precio,
        'unidad_medida' => '43',
    ];
    if ($desc !== null) {
        $l['descuento_monto'] = $desc;
    }
    return $l;
};

// ---------------------------------------------------------------------------
echo "1. Set de pruebas DGII (IndicadorMontoGravado = 1)\n";

// E310000000005: las cuatro tasas en el mismo documento.
$e31 = [
    $linea(1, 21, 35),
    $linea(2, 547, 145),
    $linea(3, 14, 55),
    $linea(4, 25, 65),
    $linea(2, 35, 25),
];
$t = EcfItemMapper::totales($e31, true);
$esperado = [
    'monto_gravado_total' => 70522.19, 'monto_gravado_i1' => 622.88, 'monto_gravado_i2' => 69129.31,
    'monto_gravado_i3' => 770.00, 'monto_exento' => 1625.00, 'total_itbis' => 11172.81,
    'total_itbis1' => 112.12, 'total_itbis2' => 11060.69, 'monto_total' => 83320.00,
];
foreach ($esperado as $k => $v) {
    $chk("E310000000005 $k = $v", $igual($t[$k], $v), $t[$k]);
}

// E450000000003: 10 lineas al 18% con descuento; distingue tasa vs linea.
$e45 = [];
foreach ([[100, 450], [300, 350], [200, 425], [50, 550], [50, 325], [60, 250], [250, 250], [400, 250], [30, 250], [60, 250]] as [$q, $p]) {
    $e45[] = $linea(1, $q, $p, 100);
}
$t = EcfItemMapper::totales($e45, true);
$chk('E450000000003 MontoGravadoI1 = 404872.88 (por linea daria .90)', $igual($t['monto_gravado_i1'], 404872.88), $t['monto_gravado_i1']);
$chk('E450000000003 TotalITBIS1 = 72877.12', $igual($t['total_itbis1'], 72877.12), $t['total_itbis1']);
$chk('E450000000003 MontoTotal = 477750.00', $igual($t['monto_total'], 477750.00), $t['monto_total']);
$m = EcfItemMapper::map($e45, false, true);
$chk('E450000000003 MontoItem[1] = 44900.00 (100 x 450 - 100)', $igual($m[0]['monto_item'], 44900), $m[0]['monto_item']);

// ---------------------------------------------------------------------------
echo "\n2. 7 x RD\$25 (el caso que motivo el cambio)\n";

$agua = [$linea(1, 7, 25)];
$t = EcfItemMapper::totales($agua, true);
$m = EcfItemMapper::map($agua, false, true);
$chk('MontoTotal = 175.00', $igual($t['monto_total'], 175.00), $t['monto_total']);
$chk('MontoGravadoI1 = 148.31', $igual($t['monto_gravado_i1'], 148.31), $t['monto_gravado_i1']);
$chk('TotalITBIS1 = 26.69', $igual($t['total_itbis1'], 26.69), $t['total_itbis1']);
$chk('Linea: MontoItem 175.00, ITBIS 26.69, base 148.31',
    $igual($m[0]['monto_item'], 175) && $igual($m[0]['itbis_amount'], 26.69) && $igual($m[0]['monto_neto'], 148.31), $m[0]);
$tViejo = EcfItemMapper::totales([$linea(1, 7, 21.19)]);
$chk('Con el calculo de siempre sobre el neto 21.19 da 175.03 (por eso el cambio)', $igual($tViejo['monto_total'], 175.03), $tViejo['monto_total']);

// ---------------------------------------------------------------------------
echo "\n3. 3,000 ventas al azar: lineas y encabezado cuadran\n";

mt_srand(20261008);
$problemas = [];
for ($n = 0; $n < 3000 && count($problemas) < 5; $n++) {
    $items = [];
    $lineas = mt_rand(1, 15);
    for ($j = 0; $j < $lineas; $j++) {
        $ind = [1, 1, 1, 2, 3, 4][mt_rand(0, 5)];
        $cant = mt_rand(0, 3) === 0 ? mt_rand(1, 999) / 100 : mt_rand(1, 40);
        $precio = mt_rand(100, 500000) / 100;
        $bruto = round($cant * $precio, 2);
        $desc = mt_rand(0, 4) === 0 ? round($bruto * mt_rand(1, 30) / 100, 2) : null;
        $items[] = $linea($ind, $cant, $precio, $desc);
    }
    $t = EcfItemMapper::totales($items, true);
    $m = EcfItemMapper::map($items, false, true);

    $sumaMonto = 0.0;
    $porInd = [1 => ['neto' => 0.0, 'itbis' => 0.0], 2 => ['neto' => 0.0, 'itbis' => 0.0]];
    foreach ($m as $l) {
        $sumaMonto += $l['monto_item'];
        $ind = $l['indicador_facturacion'];
        if (isset($porInd[$ind])) {
            $porInd[$ind]['neto'] += $l['monto_neto'];
            $porInd[$ind]['itbis'] += $l['itbis_amount'];
            $exacto = $l['monto_item'] / ($ind === 1 ? 1.18 : 1.16);
            if (abs($l['monto_neto'] - $exacto) >= 0.01) {
                $problemas[] = "venta $n: base de linea a mas de 1 centavo";
            }
        } elseif (!$igual($l['itbis_amount'], 0) || !$igual($l['monto_neto'], $l['monto_item'])) {
            $problemas[] = "venta $n: linea exenta/0% con ITBIS";
        }
    }
    if (!$igual($sumaMonto, $t['monto_total'])) {
        $problemas[] = "venta $n: MontoTotal {$t['monto_total']} != suma de MontoItem $sumaMonto";
    }
    if (!$igual($porInd[1]['neto'], $t['monto_gravado_i1']) || !$igual($porInd[1]['itbis'], $t['total_itbis1'])) {
        $problemas[] = "venta $n: lineas al 18% no suman el encabezado";
    }
    if (!$igual($porInd[2]['neto'], $t['monto_gravado_i2']) || !$igual($porInd[2]['itbis'], $t['total_itbis2'])) {
        $problemas[] = "venta $n: lineas al 16% no suman el encabezado";
    }
    $cuadre = $t['monto_gravado_total'] + $t['monto_exento'] + $t['total_itbis'];
    if (!$igual($cuadre, $t['monto_total'])) {
        $problemas[] = "venta $n: gravado + exento + ITBIS != MontoTotal";
    }
}
$chk('MontoTotal = suma de MontoItem, en todas', !array_filter($problemas, fn($p) => str_contains($p, 'MontoTotal')), $problemas);
$chk('Lineas de cada tasa suman su MontoGravado y su TotalITBIS', !array_filter($problemas, fn($p) => str_contains($p, 'no suman')), $problemas);
$chk('Base de cada linea a menos de 1 centavo de MontoItem / (1 + tasa)', !array_filter($problemas, fn($p) => str_contains($p, 'centavo')), $problemas);
$chk('Gravado + exento + ITBIS = MontoTotal', !array_filter($problemas, fn($p) => str_contains($p, 'gravado +')), $problemas);
$chk('Exento y 0% sin ITBIS', !array_filter($problemas, fn($p) => str_contains($p, 'exenta')), $problemas);

// ---------------------------------------------------------------------------
echo "\n4. Sin el indicador nada cambia\n";

$neto = [$linea(1, 3, 84.7458), $linea(2, 2, 100, 10), $linea(4, 1, 50)];
$t = EcfItemMapper::totales($neto);
$m = EcfItemMapper::map($neto);
$chk('Totales de siempre (254.24 + 190.00 al 16% + 50 exento)',
    $igual($t['monto_gravado_i1'], 254.24) && $igual($t['total_itbis1'], 45.76)
    && $igual($t['monto_gravado_i2'], 190.00) && $igual($t['total_itbis2'], 30.40)
    && $igual($t['monto_exento'], 50) && $igual($t['monto_total'], 570.40), $t);
$chk('ITBIS encima de la linea y base = MontoItem',
    $igual($m[0]['itbis_amount'], 45.76) && $igual($m[0]['monto_neto'], $m[0]['monto_item']), $m[0]);
$chk('map() devuelve una lista aunque las claves vengan salteadas',
    array_keys(EcfItemMapper::map([3 => $linea(1, 1, 10), 7 => $linea(1, 1, 20)], false, true)) === [0, 1]);

// ---------------------------------------------------------------------------
echo "\n5. XML y XSD de la DGII\n";

$xsdDir = __DIR__ . '/../samples';
$validarXsd = static function (string $xml, string $xsd): array {
    // El XSD exige la firma al final (xs:any, processContents skip): un
    // elemento vacio basta para validar lo demas sin firmar.
    $xml = preg_replace('#</(ECF|RFCE)>\s*$#', '<Signature xmlns="http://www.w3.org/2000/09/xmldsig#"/></$1>', $xml);
    // Dos defectos de los XSD de la DGII que libxml no carga, corregidos en
    // memoria (samples/ no se toca): el typo name=" IndicadorServicio..." del
    // e-CF 31 (igual que tools/validar_xml_dgii.php) y los grupos (?:...) del
    // RFCE, que libxml no soporta y valen lo mismo que (...).
    $esquema = (string) file_get_contents($xsd);
    $esquema = preg_replace('/\b(name|type)="\s+([^"]*?)\s*"/', '$1="$2"', $esquema);
    $esquema = str_replace('(?:', '(', $esquema);
    $doc = new DOMDocument();
    $doc->loadXML($xml);
    libxml_use_internal_errors(true);
    libxml_clear_errors();
    $ok = $doc->schemaValidateSource($esquema);
    $errores = array_map(fn($e) => trim($e->message), libxml_get_errors());
    libxml_clear_errors();
    return [$ok, $errores];
};
$xmlEcf = static function (string $tipo, array $items, array $extra = []): string {
    $data = array_merge([
        'tipo_ecf' => $tipo,
        'e_ncf' => 'E' . $tipo . '0000000001',
        'fecha_emision' => '08-10-2026',
        'fecha_hora_firma' => '08-10-2026 09:00:00',
        'fecha_vencimiento_secuencia' => '31-12-2027',
        'tipo_ingresos' => '01',
        'tipo_pago' => 1,
        'indicador_monto_gravado' => '1',
        'emisor' => [
            'rnc' => '131256432',
            'razon_social' => 'GRATEX SRL',
            'direccion' => 'Santo Domingo',
        ],
        'comprador' => $tipo === '32' ? [] : ['rnc' => '131880681', 'razon_social' => 'DOCUMENTOS ELECTRONICOS DE 03'],
        'items' => EcfItemMapper::map($items, false, true),
        'totales' => EcfItemMapper::totales($items, true),
    ], $extra);
    return (new ECFXmlBuilder())->build($data);
};
$campo = static function (string $xml, string $tag): ?string {
    return preg_match('#<' . $tag . '>([^<]*)</' . $tag . '>#', $xml, $mm) ? $mm[1] : null;
};

$xml32 = $xmlEcf('32', $agua);
$chk('E32 IndicadorMontoGravado = 1', $campo($xml32, 'IndicadorMontoGravado') === '1', $campo($xml32, 'IndicadorMontoGravado'));
$chk('E32 PrecioUnitarioItem es el de gondola (25)', $igual($campo($xml32, 'PrecioUnitarioItem'), 25), $campo($xml32, 'PrecioUnitarioItem'));
$chk('E32 MontoItem 175.00 / MontoGravadoI1 148.31 / TotalITBIS1 26.69 / MontoTotal 175.00',
    $campo($xml32, 'MontoItem') === '175.00' && $campo($xml32, 'MontoGravadoI1') === '148.31'
    && $campo($xml32, 'TotalITBIS1') === '26.69' && $campo($xml32, 'MontoTotal') === '175.00',
    [$campo($xml32, 'MontoItem'), $campo($xml32, 'MontoGravadoI1'), $campo($xml32, 'TotalITBIS1'), $campo($xml32, 'MontoTotal')]);
// El XSD de samples/ exige <Comprador> en el E32 y el builder lo omite a
// consumidor final. No afecta al E32 < 250k (a la DGII solo va el RFCE); el de
// 250k o mas tiene que identificar al comprador (docs/specs/pos.md, F3). Por
// eso el E32 se valida con comprador.
$xml32c = $xmlEcf('32', $agua, ['comprador' => ['rnc' => '00113918205', 'razon_social' => 'JUAN PEREZ']]);
[$ok, $err] = $validarXsd($xml32c, "$xsdDir/e-CF 32 v.1.0.xsd");
$chk('E32 (con comprador) valida contra el XSD de la DGII', $ok, $err);

$xml31 = $xmlEcf('31', [$linea(1, 3, 10), $linea(4, 2, 15)]);
[$ok, $err] = $validarXsd($xml31, "$xsdDir/e-CF 31 v.1.0.xsd");
$chk('E31 (gravado + exento) valida contra el XSD', $ok, $err);
$chk('E31 MontoTotal 60.00 = 3 x 10 + 2 x 15', $campo($xml31, 'MontoTotal') === '60.00', $campo($xml31, 'MontoTotal'));

$xml34 = $xmlEcf('34', [$linea(1, 2, 25)], [
    'indicador_nota_credito' => '0',
    'informacion_referencia' => [
        'ncf_modificado' => 'E320000000001',
        'fecha_ncf_modificado' => '08-10-2026',
        'codigo_modificacion' => '3',
        'razon_modificacion' => 'Devolucion parcial',
    ],
]);
[$ok, $err] = $validarXsd($xml34, "$xsdDir/e-CF 34 v.1.0.xsd");
$chk('E34 de devolucion parcial valida contra el XSD', $ok, $err);
$chk('E34 MontoTotal 50.00', $campo($xml34, 'MontoTotal') === '50.00', $campo($xml34, 'MontoTotal'));
$chk('E34 con cliente lleva <Comprador> con su RNC', str_contains($xml34, '<RNCComprador>131880681</RNCComprador>'));

// Devolucion de un E32 a consumidor final: no hay comprador. El XSD del E34 lo
// admite (Comprador minOccurs=0); antes salia <RazonSocialComprador/> vacio.
$xml34cf = $xmlEcf('34', [$linea(1, 2, 25)], [
    'comprador' => [],
    'indicador_nota_credito' => '0',
    'informacion_referencia' => [
        'ncf_modificado' => 'E320000000001',
        'fecha_ncf_modificado' => '08-10-2026',
        'codigo_modificacion' => '3',
        'razon_modificacion' => 'Devolucion parcial',
    ],
]);
$chk('E34 de consumidor final sale sin <Comprador>', !str_contains($xml34cf, '<Comprador>'));
[$ok, $err] = $validarXsd($xml34cf, "$xsdDir/e-CF 34 v.1.0.xsd");
$chk('E34 de consumidor final valida contra el XSD', $ok, $err);

$rfce = (new RFCEXmlBuilder())->build([
    'tipo_ecf' => '32',
    'e_ncf' => 'E320000000001',
    'tipo_ingresos' => '01',
    'tipo_pago' => 1,
    'emisor' => ['rnc' => '131256432', 'razon_social' => 'GRATEX SRL'],
    'fecha_emision' => '08-10-2026',
    'comprador' => [],
    'totales' => EcfItemMapper::totales($agua, true),
    'codigo_seguridad_ecf' => 'ABC123',
]);
$chk('RFCE lleva los mismos totales (148.31 / 26.69 / 175.00)',
    $campo($rfce, 'MontoGravadoI1') === '148.31' && $campo($rfce, 'TotalITBIS1') === '26.69' && $campo($rfce, 'MontoTotal') === '175.00',
    [$campo($rfce, 'MontoGravadoI1'), $campo($rfce, 'TotalITBIS1'), $campo($rfce, 'MontoTotal')]);
[$ok, $err] = $validarXsd($rfce, "$xsdDir/RFCE 32 v.1.0.xsd");
$chk('RFCE valida contra el XSD', $ok, $err);

// ---------------------------------------------------------------------------
echo "\n6. Representacion Impresa\n";

// Fila de factura_items tal como la guarda la emision: subtotal = base sin
// ITBIS, itbis_amount aparte (ver facturaController::handleEmisionECF).
$mAgua = EcfItemMapper::map($agua, false, true)[0];
$fila = [
    'description' => 'Agua Planeta Azul',
    'amount' => $mAgua['precio_unitario'],
    'quantity' => $mAgua['cantidad'],
    'subtotal' => $mAgua['monto_neto'],
    'descuento_monto' => 0,
    'itbis_amount' => $mAgua['itbis_amount'],
    'indicador_facturacion' => 1,
    'unidad_medida' => '',   // sin codigo: unidadSigla no consulta el catalogo
];
$emitida = new EcfDocumento([
    'tipo_ecf' => '32', 'e_ncf' => 'E320000000001', 'total' => 175.00,
    'xml_firmado' => $xml32, 'items' => [$fila],
]);
$l = $emitida->lineas()[0];
$chk('Emitida: se detecta el indicador en el XML', $emitida->preciosIncluyenItbis());
$chk('Emitida: la linea imprime 7 x 25.00 = 175.00 (lo firmado)',
    $igual($l['precio'], 25) && $igual($l['valor'], 175) && $l['cantidad'] === '7', $l);
$chk('Emitida: ITBIS de la linea 26.69', $igual($l['itbis'], 26.69), $l['itbis']);
$tt = $emitida->totales();
$chk('Emitida: pie 148.31 + 26.69 = 175.00',
    $igual($tt['subtotal'], 148.31) && $igual($tt['itbis'], 26.69) && $igual($tt['total'], 175), $tt);

$previa = new EcfDocumento([
    'tipo_ecf' => '32', 'total' => 175.00, 'indicador_monto_gravado' => 1,
    'items' => [array_merge($mAgua, ['unidad_medida' => ''])],
]);
$l = $previa->lineas()[0];
$tt = $previa->totales();
$chk('Vista previa: 7 x 25.00 = 175.00 y el pie no suma el ITBIS dos veces',
    $igual($l['valor'], 175) && $igual($tt['total'], 175) && $igual($tt['subtotal'], 148.31) && $igual($tt['itbis'], 26.69),
    ['linea' => $l, 'pie' => $tt]);

$sinIndicador = new EcfDocumento([
    'tipo_ecf' => '31', 'total' => 35.40,
    'items' => [['description' => 'X', 'amount' => 10, 'quantity' => 3, 'subtotal' => 30, 'itbis_amount' => 5.40,
        'indicador_facturacion' => 1, 'unidad_medida' => '']],
]);
$tt = $sinIndicador->totales();
$chk('Sin indicador: 30.00 + 5.40 = 35.40, como siempre',
    !$sinIndicador->preciosIncluyenItbis() && $igual($tt['subtotal'], 30) && $igual($tt['total'], 35.40), $tt);

// ---------------------------------------------------------------------------
echo "\n7. Integracion: la rama de emitir() sin el indicador\n";

// ECFEmissionService::prepararItemsIntegracion es lo que emitir() hace con los items y los
// totales de un payload de integracion (sin DB ni red): cuando no llega el indicador en 1, lo
// de siempre. Linea del cliente de integracion: precio SIN ITBIS (13.9831 x 200 = 2796.62) y un
// descuento de redondeo de 0.01 para llegar a la base real 2796.61.
$prep = static fn(array $p): array => ECFEmissionService::prepararItemsIntegracion($p);
$base = ['tipo_ecf' => '31', 'items' => [$linea(1, 200, 13.9831, 0.01)]];

$r = $prep($base);
$chk('Sin indicador: totales = map + totales sobre el neto, como siempre',
    $r['totales'] === EcfItemMapper::totales(EcfItemMapper::map($base['items'], false)), $r['totales']);
$chk('Sin indicador: MontoItem 2796.61 y MontoTotal 3300.00 (la linea con descuento de redondeo)',
    $igual($r['items'][0]['monto_item'], 2796.61) && $igual($r['totales']['monto_total'], 3300.00), $r);
$chk('Sin indicador: sale "0", lo mismo que el builder ya escribia (el XML no cambia)', ($r['indicador_monto_gravado'] ?? null) === '0', $r);
$chk('Indicador "0": queda tal cual', $prep($base + ['indicador_monto_gravado' => '0'])['indicador_monto_gravado'] === '0');
$r = $prep($base + ['totales' => ['monto_total' => 999.99, 'total_itbis' => null]]);
$chk('Override del cliente gana clave por clave; null no cuenta',
    $r['totales']['monto_total'] === 999.99 && $igual($r['totales']['total_itbis'], 503.39), $r['totales']);
$r = $prep($base + ['strict_input' => true, 'totales' => ['monto_total' => 999.99]]);
$chk('Estricto con override: solo el override', $r['totales'] === ['monto_total' => 999.99], $r['totales']);
$r = $prep(['tipo_ecf' => '31', 'items' => []]);
$chk('Sin items: totales en cero y sin error', $r['items'] === [] && $r['totales']['monto_total'] === 0.0, $r);

// ---------------------------------------------------------------------------
echo "\n8. Integracion con indicador_monto_gravado = 1 (precios con ITBIS)\n";

// El ERP del cliente guarda el precio pactado CON ITBIS (200 x 16.50 = 3300.00 = base 2796.61 +
// ITBIS 503.39). Con el indicador en 1 el precio y el descuento van con ITBIS y el ITBIS se desglosa
// por tasa, como en el POS. Antes el indicador se copiaba al XML y el ITBIS se sumaba encima (3894.00):
// el 1934 de la empresa 002. Solo emision normal y solo E31-E34; el set de pruebas queda como estaba.
$exacto = static fn($a, $b): bool => abs((float) $a - (float) $b) < 1e-9;
$con = static fn(string $tipo, array $items, $flag = 1, array $extra = []): array =>
    ECFEmissionService::prepararItemsIntegracion(['tipo_ecf' => $tipo, 'indicador_monto_gravado' => $flag, 'items' => $items] + $extra);
$tipoFalla = static function (string $tipo) use ($con, $linea): ?string {
    try {
        $con($tipo, [$linea(1, 200, 16.50)]);
        return null;
    } catch (EcfUsuarioException $e) {
        return $e->getMessage();
    }
};

$r = $con('31', [$linea(1, 200, 16.50)]);
$chk('Caso del proveedor: 200 x 16.50 = MontoItem 3300.00 = base 2796.61 + ITBIS 503.39',
    $igual($r['items'][0]['monto_item'], 3300.00) && $igual($r['totales']['monto_gravado_i1'], 2796.61)
    && $igual($r['totales']['total_itbis1'], 503.39) && $igual($r['totales']['monto_total'], 3300.00), $r['totales']);
$chk('Caso del proveedor: el indicador sale en "1"', $r['indicador_monto_gravado'] === '1', $r['indicador_monto_gravado']);

$catorce = [];
foreach ([[200, 16.50], [200, 16.50], [10, 50], [5, 50], [5, 125], [1, 120], [5, 125], [25, 7], [40, 3], [1, 580], [20, 2], [1, 150], [1, 550], [1, 350]] as [$q, $p]) {
    $catorce[] = $linea(1, $q, $p);
}
$r = $con('31', $catorce);
$suma = 0.0;
$conDescuento = 0;
foreach ($r['items'] as $it) {
    $suma += $it['monto_item'];
    $conDescuento += empty($it['descuento_monto']) ? 0 : 1;
}
$chk('Factura de 14 lineas: gravado 9055.08 + ITBIS 1629.92 = 10685.00',
    $igual($r['totales']['monto_gravado_i1'], 9055.08) && $igual($r['totales']['total_itbis1'], 1629.92)
    && $igual($r['totales']['monto_total'], 10685.00), $r['totales']);
$chk('Factura de 14 lineas: MontoTotal es la suma de los MontoItem y ninguna linea lleva descuento',
    $igual($r['totales']['monto_total'], $suma) && $conDescuento === 0, [$suma, $conDescuento]);

$r = $con('31', [$linea(1, 200, 16.50, 100.00)]);
$chk('Descuento real de 100.00 (con ITBIS): MontoItem 3200.00 = base 2711.86 + ITBIS 488.14',
    $igual($r['items'][0]['monto_item'], 3200.00) && $igual($r['totales']['monto_gravado_i1'], 2711.86)
    && $igual($r['totales']['total_itbis1'], 488.14) && $igual($r['totales']['monto_total'], 3200.00), $r['totales']);

// Toda forma que el XSD lee como 1 (xs:integer con enumeracion 0/1: "01", " 1", "+1"...) activa el modo y
// sale como "1": si no, el XML diria 1 con el calculo neto (la trampa del 1934). "01" es plausible: el
// mismo JSON manda "tipo_ingresos": "01".
foreach ([['"1"', '1'], ['true', true], ['1.0', 1.0], ['" 1"', ' 1'], ['"1 "', '1 '], ['"01"', '01'],
    ['"+1"', '+1'], ['"1\n"', "1\n"], ['"1.0"', '1.0']] as [$nombre, $flag]) {
    $r = $con('31', [$linea(1, 200, 16.50)], $flag);
    $chk("Indicador $nombre se trata como 1: nunca un 1 en el XML con calculo neto",
        $igual($r['totales']['monto_total'], 3300.00) && $r['indicador_monto_gravado'] === '1', $r);
}
foreach ([['0', 0], ['"0"', '0'], ['""', ''], ['null', null], ['false', false], ['"00"', '00'], ['" 0 "', ' 0 '],
    ['0.0', 0.0]] as [$nombre, $flag]) {
    $r = $con('31', [$linea(1, 200, 16.50)], $flag);
    $chk("Indicador $nombre: calculo neto de siempre (3894.00) y sale \"0\", lo que el builder ya escribia",
        $igual($r['totales']['monto_total'], 3894.00) && $r['indicador_monto_gravado'] === '0', $r);
}
// Un valor que no es 0 ni 1 en ninguna grafia: error claro antes de firmar (el XSD de la DGII lo
// rechazaria despues, con el e-NCF ya enviado).
$flagFalla = static function ($flag) use ($con, $linea): ?string {
    try {
        $con('31', [$linea(1, 200, 16.50)], $flag);
        return null;
    } catch (EcfUsuarioException $e) {
        return $e->getMessage();
    }
};
foreach ([['2', 2], ['"2"', '2'], ['"abc"', 'abc'], ['"true"', 'true'], ['[1]', [1]], ['-1', -1], ['1.5', 1.5]] as [$nombre, $flag]) {
    $msg = $flagFalla($flag);
    $chk("Indicador $nombre: error claro (solo 0 o 1), sin barras que se enmascaren como ruta",
        $msg !== null && str_contains($msg, 'indicador_monto_gravado') && str_contains($msg, '0 o 1') && !str_contains($msg, '/'), $msg);
}

// E310000000005 del set de la DGII (las cuatro tasas), por la ruta de integracion.
$setE31 = [$linea(1, 21, 35), $linea(2, 547, 145), $linea(3, 14, 55), $linea(4, 25, 65), $linea(2, 35, 25)];
$r = $con('31', $setE31);
foreach (['monto_gravado_i1' => 622.88, 'monto_gravado_i2' => 69129.31, 'monto_gravado_i3' => 770.00,
    'monto_exento' => 1625.00, 'total_itbis' => 11172.81, 'monto_total' => 83320.00] as $k => $v) {
    $chk("Tasas mezcladas por integracion: $k = $v", $igual($r['totales'][$k], $v), $r['totales'][$k]);
}

foreach (['32', '33', '34'] as $tipo) {
    $r = $con($tipo, [$linea(1, 200, 16.50)]);
    $chk("E$tipo admite el modo (MontoTotal 3300.00)", $igual($r['totales']['monto_total'], 3300.00), $r['totales']);
}
foreach (['41', '43', '44', '45', '46', '47'] as $tipo) {
    $msg = $tipoFalla($tipo);
    $chk("E$tipo con indicador 1: error claro antes de firmar, sin barras que se enmascaren como ruta",
        $msg !== null && str_contains($msg, 'indicador_monto_gravado') && str_contains($msg, 'E31, E32, E33 y E34')
        && !str_contains($msg, '/'), $msg);
}

$r = $con('45', [$linea(1, 200, 16.50)], 1, ['strict_input' => true]);
$chk('Set de pruebas (strict_input): no lanza, el indicador queda como llego y el calculo es el neto (3894.00)',
    $r['indicador_monto_gravado'] === 1 && $igual($r['totales']['monto_total'], 3894.00), $r);
$r = $con('31', [$linea(1, 200, 16.50)], '01', ['strict_input' => true]);
$chk('Set de pruebas: el indicador "01" queda como llego y el calculo es el neto (el set trae sus montos)',
    $r['indicador_monto_gravado'] === '01' && $igual($r['totales']['monto_total'], 3894.00), $r);
$r = $con('31', [$linea(1, 200, 16.50)], 'abc', ['strict_input' => true]);
$chk('Set de pruebas: un indicador que no es 0 ni 1 no lanza ni se toca (lo valida la DGII contra su set)',
    $r['indicador_monto_gravado'] === 'abc', $r);

$r = $con('31', [$linea(1, 200, 16.50)], 1, ['totales' => ['total_itbis1' => 500.00]]);
$chk('Override del cliente en el modo: gana su clave y el resto se calcula',
    $r['totales']['total_itbis1'] === 500.00 && $igual($r['totales']['monto_total'], 3300.00), $r['totales']);

// Con precios con ITBIS, MontoItem sale de cantidad x precio como los imprime el XML (2 y 4 decimales).
// Mas decimales es un error, no un redondeo en silencio: 1.125 x 1000.00 se firmaria como 1.13 x 1000.00 =
// 1130.00 contra los 1125.00 del cliente. Igual que la app, que valida antes de normalizar.
$decimalesFalla = static function (array $items, array $extra = []) use ($con): ?string {
    try {
        $con('31', $items, 1, $extra);
        return null;
    } catch (EcfUsuarioException $e) {
        return $e->getMessage();
    }
};
$msg = $decimalesFalla([$linea(1, 1.125, 1000.00)]);
$chk('Cantidad con 3 decimales (1.125): error claro con la linea, no un 1.13 en silencio',
    $msg !== null && str_contains($msg, 'Línea 1') && str_contains($msg, 'decimales') && !str_contains($msg, '/'), $msg);
$msg = $decimalesFalla([$linea(1, 2, 16.50), $linea(1, 3, 16.12345)]);
$chk('Precio con 5 decimales en la linea 2: el error nombra la linea 2',
    $msg !== null && str_contains($msg, 'Línea 2') && !str_contains($msg, 'Línea 1'), $msg);
$r = $con('31', [$linea(1, 0.1 + 0.2, 16.50), $linea(1, 2.5, 16.1235)]);
$chk('El ruido binario (0.1 + 0.2) no es un decimal de mas: cantidad 0.3 y MontoItem 4.95; 2.5 x 16.1235 = 40.31',
    $exacto($r['items'][0]['cantidad'], 0.3) && $exacto($r['items'][0]['monto_item'], 4.95)
    && $exacto($r['items'][1]['monto_item'], 40.31), $r['items']);
$sinModo = null;
try {
    $prep(['tipo_ecf' => '31', 'items' => [$linea(1, 1.126, 16.123456)]]);
} catch (Throwable $e) {
    $sinModo = $e->getMessage();
}
$chk('Sin el indicador, los decimales de mas siguen sin error (como siempre)', $sinModo === null, $sinModo);
$sinModo = $decimalesFalla([$linea(1, 1.126, 16.123456)], ['strict_input' => true]);
$chk('Set de pruebas con el indicador: los decimales de mas no se validan (el set trae sus montos)', $sinModo === null, $sinModo);

$r = $con('31', []);
$chk('Sin items con el indicador: totales en cero, sin error y el indicador en "1"',
    $r['items'] === [] && $r['totales']['monto_total'] === 0.0 && $r['indicador_monto_gravado'] === '1', $r);

// Por el builder del XML y contra el XSD de la DGII (los helpers de la seccion 5).
$r = $con('31', [$linea(1, 200, 16.50)]);
$xml = $xmlEcf('31', [], ['items' => $r['items'], 'totales' => $r['totales'], 'indicador_monto_gravado' => $r['indicador_monto_gravado']]);
$chk('XML del caso del proveedor: IndicadorMontoGravado 1, MontoItem 3300.00, MontoGravadoI1 2796.61, TotalITBIS1 503.39, MontoTotal 3300.00',
    $campo($xml, 'IndicadorMontoGravado') === '1' && $campo($xml, 'MontoItem') === '3300.00'
    && $campo($xml, 'MontoGravadoI1') === '2796.61' && $campo($xml, 'TotalITBIS1') === '503.39' && $campo($xml, 'MontoTotal') === '3300.00',
    [$campo($xml, 'IndicadorMontoGravado'), $campo($xml, 'MontoItem'), $campo($xml, 'MontoGravadoI1'), $campo($xml, 'TotalITBIS1'), $campo($xml, 'MontoTotal')]);
[$ok, $err] = $validarXsd($xml, "$xsdDir/e-CF 31 v.1.0.xsd");
$chk('XML del caso del proveedor valida contra el XSD de la DGII (E31)', $ok, $err);

$r = $con('31', [$linea(1, 200, 16.50, 100.00)]);
$xml = $xmlEcf('31', [], ['items' => $r['items'], 'totales' => $r['totales'], 'indicador_monto_gravado' => $r['indicador_monto_gravado']]);
$chk('XML con descuento real: DescuentoMonto y MontoSubDescuento 100.00, MontoTotal 3200.00',
    $campo($xml, 'DescuentoMonto') === '100.00' && $campo($xml, 'MontoSubDescuento') === '100.00' && $campo($xml, 'MontoTotal') === '3200.00',
    [$campo($xml, 'DescuentoMonto'), $campo($xml, 'MontoSubDescuento'), $campo($xml, 'MontoTotal')]);
[$ok, $err] = $validarXsd($xml, "$xsdDir/e-CF 31 v.1.0.xsd");
$chk('XML con descuento real valida contra el XSD de la DGII (E31)', $ok, $err);

// Sin el indicador el XML es el de siempre: IndicadorMontoGravado 0, lo que el builder escribia con el campo ausente.
$r = $prep($base);
$xmlNuevo = $xmlEcf('31', [], ['items' => $r['items'], 'totales' => $r['totales'], 'indicador_monto_gravado' => $r['indicador_monto_gravado']]);
$xmlViejo = $xmlEcf('31', [], ['items' => $r['items'], 'totales' => $r['totales'], 'indicador_monto_gravado' => null]);
$chk('Sin indicador: el XML trae IndicadorMontoGravado 0, igual que antes del cambio',
    $campo($xmlNuevo, 'IndicadorMontoGravado') === '0' && $campo($xmlViejo, 'IndicadorMontoGravado') === '0',
    [$campo($xmlNuevo, 'IndicadorMontoGravado'), $campo($xmlViejo, 'IndicadorMontoGravado')]);

// ---------------------------------------------------------------------------
echo "\n" . ($fallos === 0 ? "TODO OK ($total verificaciones)" : "$fallos de $total FALLARON") . "\n";
exit($fallos === 0 ? 0 : 1);
