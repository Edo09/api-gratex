<?php
/**
 * Quita el ITBIS del historico de facturas SIMPLES (tipo_ecf IS NULL).
 *
 * Contexto: una factura simple es un documento interno — no se emite a la DGII,
 * no lleva e-NCF y no entra en el 606/607 — asi que no tiene por que declarar
 * impuestos. El codigo dejo de calcularlos y el PDF dejo de imprimirlos, pero
 * las filas viejas siguen con itbis_amount > 0 y con facturas.total = subtotal +
 * itbis. Mientras no se corran, el listado muestra un total y el PDF (que
 * recalcula desde las lineas) muestra otro mas bajo.
 *
 * Este script pone itbis_amount = 0 en las lineas y recompone
 * facturas.total = SUM(subtotal). Es el inverso exacto de
 * tools/backfill_itbis_simples.php.
 *
 * NO toca e-CF (tipo_ecf NOT NULL): su fuente de verdad es el XML firmado y su
 * ITBIS lo exige la DGII.
 *
 * Uso:
 *   php tools/quitar_itbis_simples.php                    (dry-run, TODO el historico)
 *   php tools/quitar_itbis_simples.php --year=2026        (dry-run, solo ese anio)
 *   php tools/quitar_itbis_simples.php --apply            (escribe en transaccion)
 *
 * Antes de --apply genera un snapshot JSON de reversion en tools/backups/.
 */
require __DIR__ . '/../src/Database.php';

$apply = in_array('--apply', $argv, true);
$year = null;
foreach ($argv as $a) { if (preg_match('/^--year=(\d{4})$/', $a, $m)) { $year = (int)$m[1]; } }
$conn = Database::getInstance()->getConnection();

// 1) Lineas afectadas: factura simple con ITBIS guardado.
$yearWhere = $year !== null ? " AND f.date >= :y0 AND f.date < :y1" : "";
$sql = "SELECT fi.id, fi.factura_id, fi.subtotal, fi.itbis_amount
        FROM factura_items fi
        JOIN facturas f ON f.id = fi.factura_id
        WHERE f.tipo_ecf IS NULL
          AND fi.itbis_amount <> 0$yearWhere
        ORDER BY fi.factura_id, fi.id";
$stmt = $conn->prepare($sql);
if ($year !== null) {
    $stmt->bindValue(':y0', sprintf('%04d-01-01 00:00:00', $year));
    $stmt->bindValue(':y1', sprintf('%04d-01-01 00:00:00', $year + 1));
}
$stmt->execute();
$items = $stmt->fetchAll();
echo "Filtro anio: " . ($year !== null ? $year : 'TODO el historico') . "\n";

$itemIds = [];
$facturasAfectadas = [];
$itbisQuitado = 0.0;
foreach ($items as $it) {
    $itemIds[] = (int)$it['id'];
    $facturasAfectadas[(int)$it['factura_id']] = true;
    $itbisQuitado += (float)$it['itbis_amount'];
}
$facturaIds = array_keys($facturasAfectadas);

// 2) Total recompuesto por factura: la suma de subtotales de TODAS sus lineas
//    (no solo las que tenian ITBIS), que es lo que hara sumSimpleTotal.
$totalUpdates = [];
foreach ($facturaIds as $fid) {
    $h = $conn->prepare("SELECT no_factura, total, NCF FROM facturas WHERE id = :id");
    $h->execute([':id' => $fid]);
    $head = $h->fetch();

    $li = $conn->prepare("SELECT subtotal FROM factura_items WHERE factura_id = :id");
    $li->execute([':id' => $fid]);
    $sumSub = 0.0;
    foreach ($li->fetchAll() as $row) {
        $sumSub += (float)$row['subtotal'];
    }
    $totalUpdates[$fid] = [
        'no_factura' => $head['no_factura'],
        'ncf'        => $head['NCF'],
        'old'        => (float)$head['total'],
        'new'        => round($sumSub, 2),
    ];
}

// 3) Resumen.
echo "==== QUITAR ITBIS DE SIMPLES (dry-run) ====\n";
echo "Items a poner en 0 : " . count($itemIds) . "\n";
echo "Facturas afectadas : " . count($facturaIds) . "\n";
echo "ITBIS total a quitar: " . number_format($itbisQuitado, 2) . "\n\n";

echo sprintf("%-8s %-16s %-14s %12s %12s\n", 'fact_id', 'no_factura', 'NCF', 'total_old', 'total_new');
$shown = 0;
foreach ($totalUpdates as $fid => $u) {
    if ($shown++ >= 25) { echo "... (" . (count($totalUpdates) - 25) . " mas)\n"; break; }
    printf("%-8d %-16s %-14s %12s %12s\n",
        $fid, substr($u['no_factura'],0,16), substr((string)$u['ncf'],0,14),
        number_format($u['old'],2), number_format($u['new'],2));
}

// Anomalias: el total nuevo deberia ser MENOR que el viejo (se quita impuesto).
// Si sube, esa factura tenia el header descuadrado respecto a sus lineas.
$anom = array_filter($totalUpdates, fn($u) => $u['new'] > $u['old'] + 0.01);
if ($anom) {
    echo "\n[!] " . count($anom) . " facturas donde el total SUBE (header descuadrado, revisar antes de aplicar):\n";
    foreach (array_slice($anom, 0, 10, true) as $fid => $u) {
        printf("    %d  %s  old=%.2f new=%.2f\n", $fid, $u['no_factura'], $u['old'], $u['new']);
    }
}

if (!$apply) {
    echo "\n(dry-run: no se escribio nada. Re-ejecuta con --apply para aplicar)\n";
    return;
}

// 4) Snapshot de reversion.
$backupDir = __DIR__ . '/backups';
if (!is_dir($backupDir)) { mkdir($backupDir, 0775, true); }
$stamp = date('Ymd_His');
$snap = ['generated_at' => date('c'), 'items' => [], 'facturas' => []];
foreach ($itemIds as $iid) {
    $r = $conn->prepare("SELECT id, factura_id, subtotal, itbis_amount FROM factura_items WHERE id = :id");
    $r->execute([':id' => $iid]);
    $snap['items'][] = $r->fetch();
}
foreach ($totalUpdates as $fid => $u) {
    $snap['facturas'][] = ['id' => $fid, 'total' => $u['old']];
}
$snapFile = "$backupDir/quitar_itbis_snapshot_$stamp.json";
file_put_contents($snapFile, json_encode($snap, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "\nSnapshot de reversion: $snapFile\n";

// 5) Aplicar en transaccion.
try {
    $conn->beginTransaction();

    $updItem = $conn->prepare("UPDATE factura_items SET itbis_amount = 0 WHERE id = :id");
    foreach ($itemIds as $iid) {
        $updItem->execute([':id' => $iid]);
    }

    // El WHERE repite tipo_ecf IS NULL: si una factura se convirtio en e-CF
    // entre el dry-run y el apply, no se le toca el total.
    $updTotal = $conn->prepare(
        "UPDATE facturas SET total = :total WHERE id = :id AND tipo_ecf IS NULL"
    );
    foreach ($totalUpdates as $fid => $u) {
        $updTotal->execute([':total' => $u['new'], ':id' => $fid]);
    }

    $conn->commit();
    echo "\nAplicado: " . count($itemIds) . " lineas en 0, "
        . count($totalUpdates) . " totales recompuestos.\n";
} catch (Throwable $e) {
    if ($conn->inTransaction()) { $conn->rollBack(); }
    echo "\n[ERROR] No se aplico nada: " . $e->getMessage() . "\n";
    exit(1);
}
