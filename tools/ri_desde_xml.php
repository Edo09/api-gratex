<?php
/**
 * Representacion Impresa (RI) a partir de un e-CF XML FIRMADO.
 *
 * Uso:
 *   php tools/ri_desde_xml.php <archivo.xml> [--out=ruta.pdf] [--formato=carta|pos]
 *                              [--ambiente=ecf|certecf|testecf]
 *
 * Para que sirve: reimprimir un comprobante teniendo solo su XML — respaldos,
 * documentos recibidos de otro contribuyente, soporte a un tenant de
 * integracion (JSON->XML, sin BD) o verificar que el timbre impreso resuelve en
 * la DGII. No toca la base de datos: TODO sale del XML.
 *
 * El dibujo es el de siempre (RepresentacionImpresa -> hoja carta o tirilla
 * POS) y el contenido lo decide EcfDocumento, que ya prefiere el XML firmado
 * para totales, razon social del comprador y descripcion de los items. Lo unico
 * que agrega este script es el mapeo XML -> arreglo de factura, mas el emisor
 * explicito ($factura['emisor']): sin el, el impreso mostraria el emisor del
 * tenant conectado en vez del que firmo el documento.
 *
 * El codigo de seguridad son los primeros 6 caracteres del SignatureValue,
 * igual que en la emision (ECFEmissionService::extractCodigoSeguridad); es lo
 * que la DGII espera en el QR de ConsultaTimbre.
 */

require_once __DIR__ . '/../src/Utils/Pdf/EcfDocumento.php';
require_once __DIR__ . '/../src/Utils/Pdf/BrandingResolver.php';
require_once __DIR__ . '/../src/Utils/Pdf/RepresentacionImpresa.php';

// ---------------------------------------------------------------------------
// Argumentos
// ---------------------------------------------------------------------------

$opciones = [];
$rutaXml = null;
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z_]+)=(.*)$/i', $a, $m)) {
        $opciones[strtolower($m[1])] = $m[2];
        continue;
    }
    if ($rutaXml === null) {
        $rutaXml = $a;
    }
}

if ($rutaXml === null || !is_file($rutaXml)) {
    fwrite(STDERR, "Uso: php tools/ri_desde_xml.php <archivo.xml> [--out=ruta.pdf] [--formato=carta|pos] [--ambiente=ecf|certecf|testecf]\n");
    exit(1);
}

$xmlRaw = (string) file_get_contents($rutaXml);
if (trim($xmlRaw) === '') {
    fwrite(STDERR, "El archivo esta vacio: {$rutaXml}\n");
    exit(1);
}

$pos = in_array(strtolower((string) ($opciones['formato'] ?? 'carta')), ['pos', '80mm', '80', 'tirilla', 'termica'], true);

// ---------------------------------------------------------------------------
// Lectura del XML
// ---------------------------------------------------------------------------

$previo = libxml_use_internal_errors(true);
$dom = new DOMDocument();
$ok = $dom->loadXML($xmlRaw, defined('LIBXML_NONET') ? LIBXML_NONET : 0);
$errores = libxml_get_errors();
libxml_clear_errors();
libxml_use_internal_errors($previo);
if (!$ok) {
    $detalle = $errores ? trim($errores[0]->message) : 'formato invalido';
    fwrite(STDERR, "No se pudo leer el XML: {$detalle}\n");
    exit(1);
}

/** Texto del primer nodo con ese nombre (dentro de $ctx si se pasa). */
$txt = static function (string $tag, ?DOMElement $ctx = null) use ($dom): string {
    $nodos = $ctx ? $ctx->getElementsByTagName($tag) : $dom->getElementsByTagName($tag);
    return $nodos->length === 0 ? '' : trim((string) $nodos->item(0)->textContent);
};

/** dd-mm-aaaa (formato DGII) -> aaaa-mm-dd, que es lo que espera EcfDocumento. */
$aIso = static function (string $fecha): string {
    $fecha = trim($fecha);
    if ($fecha === '') {
        return '';
    }
    $d = DateTime::createFromFormat('d-m-Y', $fecha);
    return $d ? $d->format('Y-m-d') : $fecha;
};

$tipoEcf = $txt('TipoeCF');
$eNcf    = $txt('eNCF');
if ($eNcf === '') {
    fwrite(STDERR, "El XML no trae eNCF: no parece un e-CF.\n");
    exit(1);
}

// Codigo de seguridad = primeros 6 del SignatureValue (igual que al emitir).
$codigoSeguridad = '';
if (preg_match('/<(?:[A-Za-z0-9]+:)?SignatureValue[^>]*>([^<]+)</i', $xmlRaw, $m)) {
    $codigoSeguridad = substr((string) preg_replace('/\s+/', '', $m[1]), 0, 6);
}
if ($codigoSeguridad === '') {
    fwrite(STDERR, "Aviso: el XML no tiene firma; el timbre sale como PREVIEW (sin validez fiscal).\n");
}

// Ambiente: decide la ruta del QR (https://ecf.dgii.gov.do/<ambiente>/
// ConsultaTimbre). Equivocarlo imprime un timbre que no resuelve, y el caso
// tipico es justo el peor: un tenant en certificacion cuyo comprobante sale
// apuntando a produccion.
//
// Prioridad:
//   1. --ambiente explicito.
//   2. El del EMISOR del documento (master.tenants.ambiente por RNCEmisor).
//      Es el unico correcto por definicion: el ambiente lo fija quien emitio,
//      no el servidor donde se reimprime. AmbienteResolver NO sirve para esto
//      en CLI — sin request no hay tenant resuelto y cae al global del .env,
//      que en el server de produccion es 'ecf' para todos.
//   3. El global del server (.env), por si el master no esta disponible.
//   4. 'ecf' avisando por stderr: mejor un aviso ruidoso que un QR callado y
//      equivocado.
$rncEmisor = $txt('RNCEmisor');
$ambiente = trim((string) ($opciones['ambiente'] ?? ''));
$origenAmbiente = $ambiente !== '' ? '--ambiente' : '';

if ($ambiente === '') {
    try {
        require_once __DIR__ . '/../src/MasterDatabase.php';
        $tenant = MasterDatabase::getInstance()->getTenantByRnc($rncEmisor);
        if ($tenant !== null && !empty($tenant['ambiente'])) {
            $ambiente = (string) $tenant['ambiente'];
            $origenAmbiente = 'tenant ' . $rncEmisor;
        }
    } catch (Throwable $e) {
        // Sin master (CLI fuera del server, single-tenant) siguen los fallbacks.
    }
}
if ($ambiente === '') {
    try {
        require_once __DIR__ . '/../src/AmbienteResolver.php';
        $ambiente = (string) (AmbienteResolver::active() ?? '');
        $origenAmbiente = $ambiente !== '' ? '.env del server' : '';
    } catch (Throwable $e) {
        $ambiente = '';
    }
}
if ($ambiente === '') {
    $ambiente = 'ecf';
    $origenAmbiente = 'supuesto';
    fwrite(STDERR, "Aviso: no se pudo resolver el ambiente del emisor {$rncEmisor}; se asume 'ecf' (produccion).\n");
    fwrite(STDERR, "       Si el comprobante es de certificacion, repetir con --ambiente=certecf.\n");
}

// El comprador no existe en E43 (Gastos Menores) y en E47 (Pagos al Exterior)
// llega como IdentificadorExtranjero: EcfDocumento ya sabe como rotularlo.
$rncComprador = $txt('RNCComprador');
if ($rncComprador === '') {
    $rncComprador = $txt('IdentificadorExtranjero');
}

$items = [];
foreach ($dom->getElementsByTagName('Item') as $itemEl) {
    /** @var DOMElement $itemEl */
    $cantidad = $txt('CantidadItem', $itemEl);
    $indicador = $txt('IndicadorFacturacion', $itemEl);
    $items[] = [
        'quantity'              => $cantidad !== '' ? $cantidad : '1',
        'nombre_item'           => $txt('NombreItem', $itemEl),
        'descripcion'           => $txt('DescripcionItem', $itemEl),
        'unidad_medida'         => $txt('UnidadMedida', $itemEl),
        'amount'                => (float) $txt('PrecioUnitarioItem', $itemEl),
        'subtotal'              => (float) $txt('MontoItem', $itemEl),
        'descuento_monto'       => (float) $txt('DescuentoMonto', $itemEl),
        // El e-CF no lleva ITBIS por linea: EcfDocumento lo deriva del
        // indicador (1=18%, 2=16%, exento/0% en cero). Los totales del pie
        // salen del XML, no de esta suma.
        'indicador_facturacion' => $indicador !== '' ? (int) $indicador : 1,
    ];
}
if ($items === []) {
    fwrite(STDERR, "Aviso: el XML no trae DetallesItems; la tabla sale vacia.\n");
}

$factura = [
    'no_factura'         => $eNcf,
    'e_ncf'              => $eNcf,
    'tipo_ecf'           => $tipoEcf,
    'date'               => $aIso($txt('FechaEmision')),
    'fecha_emision_dgii' => $txt('FechaHoraFirma'),
    'codigo_seguridad'   => $codigoSeguridad,
    'ambiente_dgii'      => $ambiente,
    'total'              => (float) $txt('MontoTotal'),
    // Con el XML delante, EcfDocumento toma de el los totales, la razon social
    // del comprador, el vencimiento de la secuencia y el nombre de cada item.
    'xml_firmado'        => $xmlRaw,
    'client_id'          => null,
    'client_name'        => '',
    'company_name'       => $txt('RazonSocialComprador'),
    'items'              => $items,
    // El emisor del documento, no el del tenant conectado.
    'emisor' => [
        'razon_social' => $txt('RazonSocialEmisor'),
        'direccion'    => $txt('DireccionEmisor'),
        'telefono'     => $txt('TelefonoEmisor'),
        'correo'       => $txt('CorreoEmisor'),
        'rnc'          => $rncEmisor,
    ],
    // Notas de debito/credito (E33/E34): la norma exige NCF modificado + motivo.
    'ncf_modificado'       => $txt('NCFModificado'),
    'fecha_ncf_modificado' => $aIso($txt('FechaNCFModificado')),
    'codigo_modificacion'  => $txt('CodigoModificacion'),
    'razon_modificacion'   => $txt('RazonModificacion'),
];

$cliente = [
    'client_name'  => '',
    'company_name' => $txt('RazonSocialComprador'),
    'rnc'          => $rncComprador,
    'phone_number' => '',
    'email'        => '',
];

// ---------------------------------------------------------------------------
// Salida
// ---------------------------------------------------------------------------

$out = trim((string) ($opciones['out'] ?? ''));
if ($out === '') {
    $out = dirname($rutaXml) . DIRECTORY_SEPARATOR . $eNcf . RepresentacionImpresa::sufijo($pos) . '.pdf';
}

$ecfDoc = new EcfDocumento($factura, $cliente);

// Sin marca: el logo2020.png y el sello.png del repo son los de Gratex y, sin
// tenant resuelto, serian el fallback de esta RI. Un e-CF que llega como XML se
// imprime con el NOMBRE del emisor que lo firmo (la plantilla escribe la razon
// social donde iria el logo), nunca con la marca de otra empresa.
BrandingResolver::sinMarcaGlobal();

$pdf = RepresentacionImpresa::generar($factura, $cliente, false, $pos);
if (file_put_contents($out, $pdf) === false) {
    fwrite(STDERR, "No se pudo escribir {$out}\n");
    exit(1);
}

// El timbre tambien en pantalla: pegar esa URL en el navegador verifica que la
// DGII reconoce el comprobante, sin tener que escanear el QR del papel.
$timbre = $ecfDoc->timbre();
echo "e-NCF:    {$eNcf} (tipo {$tipoEcf})\n";
echo "Ambiente: {$ambiente} ({$origenAmbiente})\n";
echo 'PDF:      ' . $out . ' (' . strlen($pdf) . ' bytes, ' . ($pos ? 'tirilla 80 mm' : 'hoja carta') . ")\n";
if ($timbre !== null) {
    echo "Timbre:   {$timbre['url']}\n";
}
if (!class_exists('QRcode')) {
    echo "Aviso:    sin vendor/phpqrcode (o sin GD) el timbre se imprime sin la imagen del QR.\n";
}
