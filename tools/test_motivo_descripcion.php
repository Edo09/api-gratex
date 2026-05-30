<?php
// Preview local (sin BD) de las dos variantes del Motivo en la columna Descripcion:
//  A) item SIN descripcion  -> el Motivo llena la columna Descripcion de la linea
//  B) item CON descripcion  -> el Motivo va en su propia fila al final
require_once __DIR__ . '/../src/Utils/FacturaPdfGenerator.php';

$client = [
    'client_name'  => 'CLIENTE COMPROBANTE TEST SRL',
    'company_name' => 'CLIENTE COMPROBANTE TEST SRL',
    'rnc'          => '131880681',
    'phone_number' => '809-555-1234',
];

$base = [
    'codigo_seguridad'   => '',
    'ambiente_dgii'      => 'CerteCF',
    'date'               => '2026-05-28',
    'fecha_emision_dgii' => '2026-05-28 11:58:43',
    'ncf_modificado'       => 'E310000000358',
    'fecha_ncf_modificado' => '2026-05-28',
    'codigo_modificacion'  => '3',
];

// Caso A: E33, item sin descripcion -> motivo como descripcion de la linea
$a = $base + [
    'no_factura' => 'E330000000310', 'e_ncf' => 'E330000000310', 'tipo_ecf' => '33',
    'total' => 1046.88,
    'razon_modificacion' => 'Nota de debito por ajuste de monto',
    'items' => [
        ['quantity' => 1, 'description' => '', 'amount' => 887.19, 'itbis_amount' => 159.69, 'subtotal' => 887.19],
    ],
];

// Caso B: E34, items CON descripcion -> motivo en fila propia
$b = $base + [
    'no_factura' => 'E340000000329', 'e_ncf' => 'E340000000329', 'tipo_ecf' => '34',
    'total' => 66.28,
    'razon_modificacion' => 'Nota de credito por ajuste de monto',
    'items' => [
        ['quantity' => 2, 'description' => 'Tarjetas de presentacion full color', 'amount' => 25.00, 'itbis_amount' => 9.00, 'subtotal' => 50.00],
        ['quantity' => 1, 'description' => 'Volante 8.5x11', 'amount' => 6.20, 'itbis_amount' => 1.12, 'subtotal' => 6.20],
    ],
];

foreach (['A_sin_desc' => $a, 'B_con_desc' => $b] as $slug => $factura) {
    $pdf = new FacturaPdfGenerator('P', 'mm', 'Letter');
    $pdf->setFactura($factura);
    $pdf->setClientData($client);
    $out = __DIR__ . "/test_motivo_{$slug}.pdf";
    file_put_contents($out, $pdf->generatePdf());
    echo "OK {$slug} -> {$out}\n";
}
