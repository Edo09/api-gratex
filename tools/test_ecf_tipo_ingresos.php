<?php
/**
 * test_ecf_tipo_ingresos.php — TipoIngresos del IdDoc segun el XSD de cada tipo
 * y lo que trae el set, sin base de datos ni red.
 *
 * Origen: set de pruebas del RNC 00104315429 (tenant app, 2026-10-26). La DGII
 * reinicio la fase 2 con "El formato del XML no es valido.. La propiedad
 * TipoIngresos no es valida debido a que el valor enviado () no coincide con el
 * valor () del conjunto de datos entregados". El caso es E340000000015: el xlsx
 * trae TipoIngresos vacio, el lector lo entrega como '' (no null), el `?? '01'`
 * de runner, controlador y servicio lo deja pasar y el builder firmaba
 * <TipoIngresos/>, que no es ningun valor del XSD (01-06).
 *
 * Regla (XSD de samples/): obligatorio en 31, 32, 44, 45, 46; opcional
 * (minOccurs=0) en 33 y 34; no existe en 41, 43, 47. En el set (strict_input) una
 * nota sin TipoIngresos se firma sin el; en lo demas, vacio = '01'.
 *
 * Uso:
 *   php tools/test_ecf_tipo_ingresos.php      (sale con 1 si algo falla)
 */

require_once __DIR__ . '/../src/Utils/FacturacionElectronica/EcfItemMapper.php';
require_once __DIR__ . '/../src/Utils/FacturacionElectronica/ECFXmlBuilder.php';
require_once __DIR__ . '/../src/Utils/FacturacionElectronica/RFCEXmlBuilder.php';
require_once __DIR__ . '/validar_xml_dgii.php';

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

/** XML sin firmar de un e-CF; $tipoIngresos = false no manda la clave. */
$xmlDe = static function (string $tipo, $tipoIngresos, bool $strict): string {
    $itemsCrudos = [['numero_linea' => 1, 'indicador_facturacion' => 1, 'nombre_item' => 'Producto',
        'indicador_bien_servicio' => 1, 'cantidad' => '1', 'precio_unitario' => '100.00']];
    if (in_array($tipo, ['41', '47'], true)) {
        $itemsCrudos[0] += ['indicador_agente_retencion_percepcion' => '1', 'monto_itbis_retenido' => '0.00', 'monto_isr_retenido' => '0.00'];
    }
    $data = [
        'tipo_ecf' => $tipo,
        'e_ncf' => 'E' . $tipo . '0000000015',
        'fecha_emision' => '26-10-2026',
        'fecha_hora_firma' => '26-10-2026 09:00:00',
        'tipo_pago' => 1,
        'emisor' => ['rnc' => '00104315429', 'razon_social' => 'EMISOR DE PRUEBA', 'direccion' => 'N/D'],
        'comprador' => ['rnc' => '131880681', 'razon_social' => 'DOCUMENTOS ELECTRONICOS DE 03'],
        'items' => EcfItemMapper::map($itemsCrudos, $strict),
        'totales' => EcfItemMapper::totales($itemsCrudos),
        'strict_input' => $strict,
    ];
    if ($tipoIngresos !== false) {
        $data['tipo_ingresos'] = $tipoIngresos;
    }
    if ($tipo === '33' || $tipo === '34') {
        $data['informacion_referencia'] = [
            'ncf_modificado' => 'E310000000034',
            'fecha_ncf_modificado' => '01-10-2026',
            'codigo_modificacion' => '3',
            'razon_modificacion' => 'Error en monto',
        ];
    }
    return (new ECFXmlBuilder())->build($data);
};

/** null = no hay <TipoIngresos>; si no, su texto. */
$tipoIngresosDe = static function (string $xml): ?string {
    $doc = new DOMDocument();
    $doc->loadXML($xml);
    $n = (new DOMXPath($doc))->query('/ECF/Encabezado/IdDoc/TipoIngresos');
    return $n->length > 0 ? $n->item(0)->textContent : null;
};

/** Errores del XSD oficial que nombran a TipoIngresos (el resto del XML minimo no se juzga aqui). */
$erroresXsd = static function (string $tipo, string $xml): array {
    $xsd = vxdXsdParaTipo($tipo, __DIR__ . '/../samples');
    $doc = new DOMDocument();
    // El XSD exige un elemento final cualquiera (la Signature, xs:any skip).
    $doc->loadXML(str_replace('</ECF>', '<Signature/></ECF>', $xml));
    libxml_use_internal_errors(true);
    libxml_clear_errors();
    $doc->schemaValidateSource($xsd);
    $errores = [];
    foreach (libxml_get_errors() as $e) {
        if (str_contains($e->message, 'TipoIngresos')) {
            $errores[] = trim($e->message);
        }
    }
    libxml_clear_errors();
    return $errores;
};

echo "Set de pruebas (strict): el texto del xlsx; vacio solo donde el XSD lo deja\n";

$xml = $xmlDe('34', '', true);
$chk('E340000000015: TipoIngresos vacio en el set -> no se emite (antes <TipoIngresos/>)', $tipoIngresosDe($xml) === null, $tipoIngresosDe($xml));
$chk('E34 sin TipoIngresos valida contra el XSD 34', $erroresXsd('34', $xml) === [], $erroresXsd('34', $xml));

$xml = $xmlDe('33', '', true);
$chk('E33 vacio en el set -> no se emite (minOccurs=0)', $tipoIngresosDe($xml) === null, $tipoIngresosDe($xml));
$chk('E33 sin TipoIngresos valida contra el XSD 33', $erroresXsd('33', $xml) === [], $erroresXsd('33', $xml));

$xml = $xmlDe('34', '01', true);
$chk('E340000000002: el set trae "01" -> "01"', $tipoIngresosDe($xml) === '01', $tipoIngresosDe($xml));

$xml = $xmlDe('34', '03', true);
$chk('E34 con "03" en el set -> "03"', $tipoIngresosDe($xml) === '03', $tipoIngresosDe($xml));

foreach (['31', '32', '44', '45', '46'] as $tipo) {
    $xml = $xmlDe($tipo, '', true);
    $chk("E$tipo (obligatorio) vacio en el set -> '01', nunca <TipoIngresos/>", $tipoIngresosDe($xml) === '01', $tipoIngresosDe($xml));
    $chk("E$tipo vacio en el set valida contra su XSD", $erroresXsd($tipo, $xml) === [], $erroresXsd($tipo, $xml));
    $xml = $xmlDe($tipo, '02', true);
    $chk("E$tipo con '02' en el set -> '02'", $tipoIngresosDe($xml) === '02', $tipoIngresosDe($xml));
}

foreach (['41', '43', '47'] as $tipo) {
    $xml = $xmlDe($tipo, '', true);
    $chk("E$tipo no lleva TipoIngresos (su XSD no lo tiene)", $tipoIngresosDe($xml) === null, $tipoIngresosDe($xml));
}

echo "\nEmision normal (no strict): el default '01' de siempre\n";

foreach (['31', '32', '33', '34', '44', '45', '46'] as $tipo) {
    $xml = $xmlDe($tipo, false, false);
    $chk("E$tipo sin tipo_ingresos -> '01'", $tipoIngresosDe($xml) === '01', $tipoIngresosDe($xml));
}
$xml = $xmlDe('34', '', false);
$chk("E34 con tipo_ingresos '' -> '01' (fuera del set no se omite)", $tipoIngresosDe($xml) === '01', $tipoIngresosDe($xml));
$xml = $xmlDe('34', null, false);
$chk('E34 con tipo_ingresos null -> "01"', $tipoIngresosDe($xml) === '01', $tipoIngresosDe($xml));
$xml = $xmlDe('31', '02', false);
$chk('E31 con "02" -> "02"', $tipoIngresosDe($xml) === '02', $tipoIngresosDe($xml));

echo "\nRFCE (resumen del E32 < 250k): TipoIngresos obligatorio\n";

$rfce = static function ($tipoIngresos): ?string {
    $xml = (new RFCEXmlBuilder())->build([
        'tipo_ecf' => '32',
        'e_ncf' => 'E320000000015',
        'tipo_ingresos' => $tipoIngresos,
        'tipo_pago' => 1,
        'emisor' => ['rnc' => '00104315429', 'razon_social' => 'EMISOR DE PRUEBA'],
        'fecha_emision' => '26-10-2026',
        'comprador' => [],
        'totales' => ['monto_total' => '100.00'],
        'codigo_seguridad_ecf' => 'ABC123',
    ]);
    $doc = new DOMDocument();
    $doc->loadXML($xml);
    $n = (new DOMXPath($doc))->query('//IdDoc/TipoIngresos');
    return $n->length > 0 ? $n->item(0)->textContent : null;
};
$chk("RFCE con '' -> '01' (antes <TipoIngresos/>)", $rfce('') === '01', $rfce(''));
$chk("RFCE con '01' -> '01'", $rfce('01') === '01', $rfce('01'));
$chk("RFCE con '05' -> '05'", $rfce('05') === '05', $rfce('05'));

printf("\n%d de %d pruebas OK\n", $total - $fallos, $total);
exit($fallos > 0 ? 1 : 0);
