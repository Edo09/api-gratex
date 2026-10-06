<?php
/**
 * test_ecf_montos_descuento.php — DescuentoMonto, RecargoMonto y sus
 * subtablas salen del XML con 2 decimales, sin base de datos ni red.
 *
 * Origen: set de pruebas de CAGLIARI GROUP SRL (RNC 131599729, 2026-10-06). La
 * DGII rechazo E410000000010 y E340000000002 con "La propiedad DescuentoMonto
 * no es valida debido a que el valor enviado (385) no coincide con el valor
 * (385.00) del conjunto de datos entregados", y ese primer rechazo reinicio el
 * set completo. EcfItemMapper::map() deja el descuento como float (200.0) y
 * ECFXmlBuilder lo escribia con (string), que da "200". El XSD acepta "200",
 * pero la DGII compara el TEXTO contra su set. Regla: en el set (strict_input)
 * se firma el texto del xlsx tal cual — tambien "3752" (MontoItem[3] de
 * E310000000004), no "3752.00" —; en la emision normal, 2 decimales.
 *
 * Uso:
 *   php tools/test_ecf_montos_descuento.php      (sale con 1 si algo falla)
 */

require_once __DIR__ . '/../src/Utils/FacturacionElectronica/EcfItemMapper.php';
require_once __DIR__ . '/../src/Utils/FacturacionElectronica/ECFXmlBuilder.php';

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

/** Arma el XML de un e-CF con los items ya pasados por EcfItemMapper, como la emision. */
$xmlDe = static function (string $tipo, array $itemsCrudos, bool $strict, array $extra = []): DOMXPath {
    $items = EcfItemMapper::map($itemsCrudos, $strict);
    $data = [
        'tipo_ecf' => $tipo,
        'e_ncf' => 'E' . $tipo . '0000000002',
        'fecha_emision' => '06-10-2026',
        'fecha_hora_firma' => '06-10-2026 09:00:00',
        'tipo_ingresos' => '01',
        'tipo_pago' => 1,
        'emisor' => [
            'rnc' => '131599729',
            'razon_social' => 'CAGLIARI GROUP SRL',
            'direccion' => 'N/D',
        ],
        'comprador' => [
            'rnc' => '131880681',
            'razon_social' => 'DOCUMENTOS ELECTRONICOS DE 03',
        ],
        'items' => $items,
        'totales' => EcfItemMapper::totales($itemsCrudos),
        'strict_input' => $strict,
    ];
    $data = array_merge($data, $extra);
    if ($tipo === '33' || $tipo === '34') {
        $data['informacion_referencia'] = [
            'ncf_modificado' => 'E310000000034',
            'fecha_ncf_modificado' => '01-04-2020',
            'codigo_modificacion' => '3',
            'razon_modificacion' => 'Error en monto',
        ];
    }
    $doc = new DOMDocument();
    $doc->loadXML((new ECFXmlBuilder())->build($data));
    return new DOMXPath($doc);
};

$texto = static function (DOMXPath $xp, string $ruta): ?string {
    $n = $xp->query($ruta);
    return $n !== false && $n->length > 0 ? $n->item(0)->textContent : null;
};

echo "Set de pruebas (strict): los valores llegan del xlsx como texto\n";

// E340000000002 del set: 10 lineas con DescuentoMonto '200.00' y RecargoMonto '350.00'.
$linea = [
    'numero_linea' => 1,
    'indicador_facturacion' => 1,
    'nombre_item' => 'Producto',
    'indicador_bien_servicio' => 1,
    'cantidad' => '10.00',
    'cantidad_raw' => '10.00',
    'precio_unitario' => '5000.00',
    'precio_unitario_raw' => '5000.00',
    'descuento_monto' => '200.00',
    'subdescuentos' => [['tipo_sub_descuento' => '$', 'monto_sub_descuento' => '200.00']],
    'recargo_monto' => '350.00',
    'subrecargos' => [['tipo_sub_recargo' => '$', 'monto_sub_recargo' => '350.00']],
];
$xp = $xmlDe('34', [$linea], true);
$chk('E34: DescuentoMonto "200.00" (la DGII rechazo "200")', $texto($xp, '//Item[1]/DescuentoMonto') === '200.00', $texto($xp, '//Item[1]/DescuentoMonto'));
$chk('E34: MontoSubDescuento "200.00"', $texto($xp, '//Item[1]/TablaSubDescuento/SubDescuento/MontoSubDescuento') === '200.00', $texto($xp, '//Item[1]/TablaSubDescuento/SubDescuento/MontoSubDescuento'));
$chk('E34: RecargoMonto "350.00"', $texto($xp, '//Item[1]/RecargoMonto') === '350.00', $texto($xp, '//Item[1]/RecargoMonto'));
$chk('E34: MontoSubRecargo "350.00"', $texto($xp, '//Item[1]/TablaSubRecargo/SubRecargo/MontoSubRecargo') === '350.00', $texto($xp, '//Item[1]/TablaSubRecargo/SubRecargo/MontoSubRecargo'));
$chk('E34: MontoItem neto del descuento (50000 - 200 = 49800.00)', $texto($xp, '//Item[1]/MontoItem') === '49800.00', $texto($xp, '//Item[1]/MontoItem'));

// E410000000010 del set: descuentos de 385, 275, 150, 200 y 8.75.
$e41 = [];
foreach (['385.00', '275.00', '150.00', '200.00', '8.75'] as $i => $d) {
    $e41[] = ['numero_linea' => $i + 1, 'indicador_facturacion' => 1, 'nombre_item' => 'Item ' . ($i + 1),
        'indicador_bien_servicio' => 1, 'cantidad' => '1', 'precio_unitario' => '5000.00', 'descuento_monto' => $d,
        'indicador_agente_retencion_percepcion' => '1', 'monto_itbis_retenido' => '0.00', 'monto_isr_retenido' => '0.00'];
}
$xp = $xmlDe('41', $e41, true);
$obtenidos = [];
foreach ($xp->query('//Item/DescuentoMonto') as $n) {
    $obtenidos[] = $n->textContent;
}
$chk('E41: DescuentoMonto de las 5 lineas = 385.00, 275.00, 150.00, 200.00, 8.75',
    $obtenidos === ['385.00', '275.00', '150.00', '200.00', '8.75'], $obtenidos);

// El set trae enteros como texto en algunas celdas: se firman igual, sin ".00".
$enteros = $linea;
$enteros['recargo_monto'] = '350';
$enteros['subrecargos'] = [['tipo_sub_recargo' => '$', 'monto_sub_recargo' => '350']];
$enteros['subdescuentos'] = [['tipo_sub_descuento' => '$', 'monto_sub_descuento' => '200']];
$enteros['descuento_monto'] = '200';
$xp = $xmlDe('34', [$enteros], true);
$chk('Texto entero del set: RecargoMonto "350" tal cual', $texto($xp, '//Item[1]/RecargoMonto') === '350', $texto($xp, '//Item[1]/RecargoMonto'));
$chk('Texto entero del set: MontoSubRecargo "350" tal cual', $texto($xp, '//Item[1]/TablaSubRecargo/SubRecargo/MontoSubRecargo') === '350', $texto($xp, '//Item[1]/TablaSubRecargo/SubRecargo/MontoSubRecargo'));
$chk('Texto entero del set: MontoSubDescuento "200" tal cual', $texto($xp, '//Item[1]/TablaSubDescuento/SubDescuento/MontoSubDescuento') === '200', $texto($xp, '//Item[1]/TablaSubDescuento/SubDescuento/MontoSubDescuento'));
$chk('Texto entero del set: DescuentoMonto "200" tal cual', $texto($xp, '//Item[1]/DescuentoMonto') === '200', $texto($xp, '//Item[1]/DescuentoMonto'));

// E310000000004 del set: MontoItem[3] = '3752' (sus vecinas dicen '11000.00', '3825.00').
$e31 = [
    ['numero_linea' => 1, 'nombre_item' => 'A', 'cantidad' => '1.00', 'precio_unitario' => '11000.00', 'monto_item' => '11000.00', 'monto_item_raw' => '11000.00'],
    ['numero_linea' => 2, 'nombre_item' => 'B', 'cantidad' => '56.00', 'precio_unitario' => '67.00', 'monto_item' => 3752.0, 'monto_item_raw' => '3752'],
];
$xp = $xmlDe('31', $e31, true);
$chk('MontoItem del set "3752" tal cual (no "3752.00")', $texto($xp, '//Item[2]/MontoItem') === '3752', $texto($xp, '//Item[2]/MontoItem'));
$chk('MontoItem del set "11000.00" tal cual', $texto($xp, '//Item[1]/MontoItem') === '11000.00', $texto($xp, '//Item[1]/MontoItem'));

// Un texto fuera del patron del XSD no se firma crudo: cae a 2 decimales.
$raro = $linea;
$raro['recargo_monto'] = ' 350.5 ';
$raro['subrecargos'] = [['tipo_sub_recargo' => '$', 'monto_sub_recargo' => '350.125']];
$xp = $xmlDe('34', [$raro], true);
$chk('Set: " 350.5 " se recorta a "350.5"', $texto($xp, '//Item[1]/RecargoMonto') === '350.5', $texto($xp, '//Item[1]/RecargoMonto'));
$chk('Set: "350.125" (3 decimales, fuera del XSD) sale "350.13"', $texto($xp, '//Item[1]/TablaSubRecargo/SubRecargo/MontoSubRecargo') === '350.13', $texto($xp, '//Item[1]/TablaSubRecargo/SubRecargo/MontoSubRecargo'));

echo "\nEmision normal (no strict): montos numericos del front o del cliente\n";

// Descuento del cliente: aplicarDescuentoPorcentaje deja un float con hasta 2 decimales.
$conPct = EcfItemMapper::aplicarDescuentoPorcentaje([
    ['numero_linea' => 1, 'indicador_facturacion' => 1, 'nombre_item' => 'Servicio', 'cantidad' => 3, 'precio_unitario' => 100],
], 10);
$xp = $xmlDe('31', $conPct, false);
$chk('Descuento 10% de 300 = DescuentoMonto "30.00"', $texto($xp, '//Item[1]/DescuentoMonto') === '30.00', $texto($xp, '//Item[1]/DescuentoMonto'));
$chk('MontoItem 270.00', $texto($xp, '//Item[1]/MontoItem') === '270.00', $texto($xp, '//Item[1]/MontoItem'));

$xp = $xmlDe('31', [['numero_linea' => 1, 'nombre_item' => 'A', 'cantidad' => 1, 'precio_unitario' => 100, 'descuento_monto' => 10.5]], false);
$chk('descuento_monto 10.5 sale "10.50"', $texto($xp, '//Item[1]/DescuentoMonto') === '10.50', $texto($xp, '//Item[1]/DescuentoMonto'));

$conTexto = [['numero_linea' => 1, 'nombre_item' => 'A', 'cantidad' => 1, 'precio_unitario' => 3752,
    'descuento_monto' => '200', 'recargo_monto' => '350', 'monto_item_raw' => '3752']];
$xp = $xmlDe('31', $conTexto, false);
$chk('Normal: el texto "200" del cliente sale "200.00" (el texto crudo es solo para el set)', $texto($xp, '//Item[1]/DescuentoMonto') === '200.00', $texto($xp, '//Item[1]/DescuentoMonto'));
$chk('Normal: RecargoMonto "350" sale "350.00"', $texto($xp, '//Item[1]/RecargoMonto') === '350.00', $texto($xp, '//Item[1]/RecargoMonto'));
$chk('Normal: MontoItem "3752" sale "3752.00"', $texto($xp, '//Item[1]/MontoItem') === '3752.00', $texto($xp, '//Item[1]/MontoItem'));

$xp = $xmlDe('31', [['numero_linea' => 1, 'nombre_item' => 'A', 'cantidad' => 1, 'precio_unitario' => 100]], false);
$chk('Sin descuento ni recargo: no se emiten DescuentoMonto ni RecargoMonto',
    $xp->query('//Item[1]/DescuentoMonto')->length === 0 && $xp->query('//Item[1]/RecargoMonto')->length === 0);

$xp = $xmlDe('31', [['numero_linea' => 1, 'nombre_item' => 'A', 'cantidad' => 1, 'precio_unitario' => 100, 'descuento_monto' => 0]], false);
$chk('descuento_monto 0: no se emite DescuentoMonto', $xp->query('//Item[1]/DescuentoMonto')->length === 0);

$falla = static function (callable $fn): ?string {
    try {
        $fn();
        return null;
    } catch (EcfUsuarioException $e) {
        return $e->getMessage();
    }
};
$msg = $falla(fn() => $xmlDe('31', [['numero_linea' => 1, 'nombre_item' => 'A', 'cantidad' => 1, 'precio_unitario' => 100, 'recargo_monto' => '1,000.00']], false));
$chk('Normal: recargo "1,000.00" falla claro (antes se habria firmado 1.00)', $msg !== null && str_contains($msg, '1,000.00'), $msg);
$msg = $falla(fn() => $xmlDe('34', [$linea + ['recargo_monto' => '350.00']], true));
$chk('Set: un monto valido no lanza nada', $msg === null, $msg);

echo "\nDescuentosORecargos globales (E310000000004 / E320000000004 del set de CAGLIARI)\n";

$dor = ['descuentos_o_recargos' => [
    ['numero_linea' => '1', 'tipo_ajuste' => 'D', 'descripcion' => 'N', 'tipo_valor' => '$', 'monto' => '200.00', 'indicador_facturacion' => '1'],
    ['numero_linea' => '2', 'tipo_ajuste' => 'D', 'descripcion' => 'D', 'tipo_valor' => '$', 'monto' => '50.00', 'indicador_facturacion' => '2'],
]];
$xp = $xmlDe('31', $e31, true, $dor);
$hijos = [];
foreach ($xp->query('/ECF/*') as $n) {
    $hijos[] = $n->nodeName;
}
$chk('Orden del XSD: DetallesItems, DescuentosORecargos, FechaHoraFirma', $hijos === ['Encabezado', 'DetallesItems', 'DescuentosORecargos', 'FechaHoraFirma'], $hijos);
$campos = [];
foreach ($xp->query('/ECF/DescuentosORecargos/DescuentoORecargo[1]/*') as $n) {
    $campos[$n->nodeName] = $n->textContent;
}
$chk('Linea 1 en el orden del XSD y con el texto del set', $campos === [
    'NumeroLinea' => '1', 'TipoAjuste' => 'D', 'DescripcionDescuentooRecargo' => 'N', 'TipoValor' => '$',
    'MontoDescuentooRecargo' => '200.00', 'IndicadorFacturacionDescuentooRecargo' => '1',
], $campos);
$chk('Dos DescuentoORecargo', $xp->query('/ECF/DescuentosORecargos/DescuentoORecargo')->length === 2);

$xp = $xmlDe('34', [$linea], true, $dor);
$hijos = [];
foreach ($xp->query('/ECF/*') as $n) {
    $hijos[] = $n->nodeName;
}
$chk('Nota: DescuentosORecargos va antes de InformacionReferencia', $hijos === ['Encabezado', 'DetallesItems', 'DescuentosORecargos', 'InformacionReferencia', 'FechaHoraFirma'], $hijos);

$e43 = [['numero_linea' => 1, 'indicador_facturacion' => 4, 'nombre_item' => 'Gasto', 'cantidad' => 1, 'precio_unitario' => 700]];
$xp = $xmlDe('43', $e43, true, $dor);
$chk('E43 no lleva la seccion (su XSD no la tiene)', $xp->query('/ECF/DescuentosORecargos')->length === 0);

$xp = $xmlDe('31', $e31, true, ['descuentos_o_recargos' => [['numero_linea' => '', 'tipo_ajuste' => 'D', 'monto' => '1.00']]]);
$chk('Sin NumeroLinea (obligatorio) no se emite la seccion', $xp->query('/ECF/DescuentosORecargos')->length === 0);

$xp = $xmlDe('31', $e31, true);
$chk('Sin descuentos_o_recargos: no hay seccion (lo de siempre)', $xp->query('/ECF/DescuentosORecargos')->length === 0);

$conNorma = ['descuentos_o_recargos' => [['numero_linea' => '1', 'tipo_ajuste' => 'D', 'indicador_norma_1007' => '1', 'monto' => '10.00']]];
$xp = $xmlDe('31', $e31, true, $conNorma);
$chk('E31 lleva IndicadorNorma1007 (su XSD lo tiene)', $xp->query('//DescuentoORecargo/IndicadorNorma1007')->length === 1);
$e41dr = [['numero_linea' => 1, 'nombre_item' => 'A', 'cantidad' => '1', 'precio_unitario' => '100.00',
    'indicador_agente_retencion_percepcion' => '1', 'monto_itbis_retenido' => '0.00', 'monto_isr_retenido' => '0.00']];
$xp = $xmlDe('41', $e41dr, true, $conNorma);
$chk('E41 no lleva IndicadorNorma1007 (su XSD no lo tiene) pero si la seccion', $xp->query('//DescuentoORecargo/IndicadorNorma1007')->length === 0
    && $xp->query('//DescuentoORecargo/MontoDescuentooRecargo')->length === 1);

printf("\n%d de %d pruebas OK\n", $total - $fallos, $total);
exit($fallos > 0 ? 1 : 0);
