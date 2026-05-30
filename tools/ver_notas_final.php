<?php
// Genera los PDF de las notas del run final DIRECTO desde la BD (flujo real del
// API), para confirmar que el NCF Modificado/Motivo ya viene de la base.
require_once __DIR__ . '/../src/Models/facturaModel.php';
require_once __DIR__ . '/../src/Models/clientModel.php';
require_once __DIR__ . '/../src/Utils/FacturaPdfGenerator.php';

$facturaModel = new facturaModel();
$clientModel  = new clientModel();

foreach ([1277 => 'e33', 1278 => 'e34a', 1279 => 'e34b'] as $id => $slug) {
    $facturas = $facturaModel->getFacturas($id);
    if (empty($facturas)) { echo "ID {$id}: no encontrada\n"; continue; }
    $factura = $facturas[0];
    $factura['items'] = $facturaModel->getFacturaItems($id);

    $pdf = new FacturaPdfGenerator('P', 'mm', 'Letter');
    $pdf->setFactura($factura);
    $cd = $clientModel->getClients($factura['client_id']);
    if (!empty($cd)) { $pdf->setClientData($cd[0]); }

    $out = __DIR__ . "/nota_final_{$slug}_{$factura['e_ncf']}.pdf";
    file_put_contents($out, $pdf->generatePdf());
    echo "OK {$factura['e_ncf']} (id={$id}) NCFmod={$factura['ncf_modificado']} -> {$out}\n";
}
