<?php
// Smoke test de la Representacion Impresa (RI) segun norma DGII.
// Genera un PDF de muestra sin tocar la base de datos (ruta preview => QR "PREVIEW").
require_once __DIR__ . '/../src/Utils/FacturaPdfGenerator.php';

$factura = [
    'no_factura'         => 'E310000000335',
    'e_ncf'              => 'E310000000335',
    'codigo_seguridad'   => '',          // vacio => ruta preview (renderiza QR de muestra)
    'ambiente_dgii'      => 'CerteCF',
    'date'               => '2026-05-27',
    'fecha_emision_dgii' => '2026-05-27 14:35:28',
    'total'              => 61753.90,
    'tipo_ecf'           => '31',
    'client_id'          => null,
    'client_name'        => 'DOCUMENTOS ELECTRONICOS DE 03',
    'company_name'       => 'DOCUMENTOS ELECTRONICOS DE 03',
    'items'              => [
        ['quantity' => 9, 'description' => 'Tarjetas de presentacion full color', 'amount' => 3813.94, 'itbis_amount' => 686.51, 'subtotal' => 34325.46],
        ['quantity' => 5, 'description' => 'Volantes 8.5x11 a una cara',          'amount' => 3601.67, 'itbis_amount' => 648.30, 'subtotal' => 18008.35],
    ],
];

$client = [
    'client_name'  => 'DOCUMENTOS ELECTRONICOS DE 03',
    'company_name' => 'DOCUMENTOS ELECTRONICOS DE 03',
    'rnc'          => '131880681',
    'phone_number' => '809-555-1234',
    'email'        => 'cliente@example.com',
];

$pdf = new FacturaPdfGenerator('P', 'mm', 'Letter');
$pdf->setFactura($factura);
$pdf->setClientData($client);
$content = $pdf->generatePdf();

$out = __DIR__ . '/test_ri_norma.pdf';
file_put_contents($out, $content);
echo "PDF generado (E31): {$out} (" . strlen($content) . " bytes)\n";

// --- Caso Nota de Credito (E34): debe mostrar NCF Modificado + Motivo ---
$nota = $factura;
$nota['no_factura']           = 'E340000000012';
$nota['e_ncf']                = 'E340000000012';
$nota['tipo_ecf']             = '34';
$nota['ncf_modificado']       = 'E310000000335';
$nota['fecha_ncf_modificado'] = '2026-05-27';
$nota['codigo_modificacion']  = '3';
$nota['razon_modificacion']   = 'Anulacion parcial por correccion de montos del NCF modificado.';

$pdf2 = new FacturaPdfGenerator('P', 'mm', 'Letter');
$pdf2->setFactura($nota);
$pdf2->setClientData($client);
$content2 = $pdf2->generatePdf();

$out2 = __DIR__ . '/test_ri_norma_e34.pdf';
file_put_contents($out2, $content2);
echo "PDF generado (E34): {$out2} (" . strlen($content2) . " bytes)\n";
