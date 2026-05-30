<?php
// Visualiza las Notas E33/E34 reales emitidas en fase 4, replicando el flujo
// del API (handleFacturaPdf): datos reales de BD + items + cliente + QR.
// Las notas se emitieron ANTES de la migracion 006, por lo que ncf_modificado /
// razon_modificacion estan NULL en BD; se rellenan en memoria (solo para esta
// vista) con la InformacionReferencia del payload de fase 4.
require_once __DIR__ . '/../src/Models/facturaModel.php';
require_once __DIR__ . '/../src/Models/clientModel.php';
require_once __DIR__ . '/../src/Utils/FacturaPdfGenerator.php';

// Backfill de InformacionReferencia (tomado de tools/fase4_notes_only_dryrun.json)
$refMap = [
    'E330000000304' => [
        'ncf_modificado'       => 'E310000000331',
        'fecha_ncf_modificado' => '2026-05-27',
        'codigo_modificacion'  => '3',
        'razon_modificacion'   => 'Nota de debito por ajuste de monto',
    ],
    'E340000000313' => [
        'ncf_modificado'       => 'E310000000332',
        'fecha_ncf_modificado' => '2026-05-27',
        'codigo_modificacion'  => '3',
        'razon_modificacion'   => 'Nota de credito por ajuste de monto',
    ],
];

$facturaModel = new facturaModel();
$clientModel  = new clientModel();

foreach (['E330000000304' => 'e33', 'E340000000313' => 'e34'] as $eNcf => $slug) {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare('SELECT id FROM facturas WHERE e_ncf = :e LIMIT 1');
    $stmt->execute([':e' => $eNcf]);
    $id = $stmt->fetchColumn();
    if (!$id) { echo "No encontrada: {$eNcf}\n"; continue; }

    $facturas = $facturaModel->getFacturas($id);
    $factura = $facturas[0];
    $factura['items'] = $facturaModel->getFacturaItems($id);

    // Rellena la referencia si el registro es previo a la migracion 006.
    if (empty($factura['ncf_modificado']) && isset($refMap[$eNcf])) {
        $factura = array_merge($factura, $refMap[$eNcf]);
    }

    $pdf = new FacturaPdfGenerator('P', 'mm', 'Letter');
    $pdf->setFactura($factura);
    $cd = $clientModel->getClients($factura['client_id']);
    if (!empty($cd)) { $pdf->setClientData($cd[0]); }

    $out = __DIR__ . "/nota_fase4_{$slug}_{$eNcf}.pdf";
    file_put_contents($out, $pdf->generatePdf());
    echo "OK {$eNcf} (id={$id}, tipo={$factura['tipo_ecf']}, total={$factura['total']}) -> {$out}\n";
}
