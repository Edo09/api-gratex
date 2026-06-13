<?php
/**
 * Backfill de ITBIS para facturas SIMPLES (no electronicas, tipo_ecf IS NULL).
 *
 * Contexto: el flujo de factura simple guardo itbis_amount=0 en lineas gravadas
 * (indicador_facturacion 1=18%, 2=16%). Este script recalcula el ITBIS por linea
 * (subtotal * tasa) y recompone facturas.total = SUM(subtotal)+SUM(itbis) de esa
 * factura. NO toca e-CF (tipo_ecf NOT NULL): su fuente de verdad es el XML firmado.
 *
 * Uso:
 *   php tools/backfill_itbis_simples.php                 (dry-run, TODO el historico)
 *   php tools/backfill_itbis_simples.php --year=2026      (dry-run, solo ese anio)
 *   php tools/backfill_itbis_simples.php --year=2026 --apply  (escribe en transaccion)
 *
 * Antes de --apply genera un snapshot JSON de reversion en tools/backups/.
 */
require __DIR__ . '/../src/Database.php';

$apply = in_array('--apply', $argv, true);
$year = null;
foreach ($argv as $a) { if (preg_match('/^--year=(\d{4})$/', $a, $m)) { $year = (int)$m[1]; } }
$conn = Database::getInstance()->getConnection();

function rateFor(int $ind): float { return $ind === 1 ? 0.18 : ($ind === 2 ? 0.16 : 0.0); }

// 1) Lineas afectadas: factura simple, gravada (1/2), itbis en 0, subtotal > 0.
$yearWhere = $year !== null ? " AND f.date >= :y0 AND f.date < :y1" : "";
$sql = "SELECT fi.id, fi.factura_id, fi.subtotal, fi.itbis_amount, fi.indicador_facturacion
        FROM factura_items fi
        JOIN facturas f ON f.id = fi.factura_id
        WHERE f.tipo_ecf IS NULL
          AND fi.indicador_facturacion IN (1,2)
          AND fi.itbis_amount = 0
          AND fi.subtotal > 0$yearWhere
        ORDER BY fi.factura_id, fi.id";
$stmt = $conn->prepare($sql);
if ($year !== null) {
    $stmt->bindValue(':y0', sprintf('%04d-01-01 00:00:00', $year));
    $stmt->bindValue(':y1', sprintf('%04d-01-01 00:00:00', $year + 1));
}
$stmt->execute();
$items = $stmt->fetchAll();
echo "Filtro anio: " . ($year !== null ? $year : 'TODO el historico') . "\n";

$itemUpdates = [];           // [id => newItbis]
$facturasAfectadas = [];     // set de factura_id
foreach ($items as $it) {
    $newItbis = round((float)$it['subtotal'] * rateFor((int)$it['indicador_facturacion']), 2);
    if ($newItbis <= 0) continue;
    $itemUpdates[(int)$it['id']] = $newItbis;
    $facturasAfectadas[(int)$it['factura_id']] = true;
}
$facturaIds = array_keys($facturasAfectadas);

// 2) Para cada factura afectada: total actual y total recompuesto (con itbis ya backfilled).
$totalUpdates = [];   // [factura_id => ['no_factura','old'=>,'new'=>,'subtotal'=>,'itbis'=>]]
foreach ($facturaIds as $fid) {
    $h = $conn->prepare("SELECT no_factura, total, NCF FROM facturas WHERE id = :id");
    $h->execute([':id' => $fid]);
    $head = $h->fetch();

    $li = $conn->prepare("SELECT id, subtotal, itbis_amount, indicador_facturacion FROM factura_items WHERE factura_id = :id");
    $li->execute([':id' => $fid]);
    $sumSub = 0.0; $sumItbis = 0.0;
    foreach ($li->fetchAll() as $row) {
        $sumSub += (float)$row['subtotal'];
        $effItbis = $itemUpdates[(int)$row['id']] ?? (float)$row['itbis_amount']; // valor post-backfill
        $sumItbis += $effItbis;
    }
    $newTotal = round($sumSub + $sumItbis, 2);
    $totalUpdates[$fid] = [
        'no_factura' => $head['no_factura'],
        'ncf'        => $head['NCF'],
        'old'        => (float)$head['total'],
        'new'        => $newTotal,
        'subtotal'   => round($sumSub, 2),
        'itbis'      => round($sumItbis, 2),
    ];
}

// 3) Resumen.
$totItbisAdd = array_sum($itemUpdates);
echo "==== INVENTARIO (dry-run) ====\n";
echo "Items a actualizar : " . count($itemUpdates) . "\n";
echo "Facturas afectadas : " . count($facturaIds) . "\n";
echo "ITBIS total a agregar: " . number_format($totItbisAdd, 2) . "\n\n";

// Muestra primeras 25 facturas con before/after total.
echo sprintf("%-8s %-16s %-14s %12s %12s %12s\n", 'fact_id', 'no_factura', 'NCF', 'total_old', 'total_new', 'itbis');
$shown = 0;
foreach ($totalUpdates as $fid => $u) {
    if ($shown++ >= 25) { echo "... (" . (count($totalUpdates) - 25) . " mas)\n"; break; }
    $flag = ($u['old'] != $u['new']) ? '' : '  <= sin cambio total';
    printf("%-8d %-16s %-14s %12s %12s %12s%s\n",
        $fid, substr($u['no_factura'],0,16), substr((string)$u['ncf'],0,14),
        number_format($u['old'],2), number_format($u['new'],2), number_format($u['itbis'],2), $flag);
}

// Anomalias: facturas cuyo total actual YA es >= subtotal+itbis (ya tenian itbis en el header?)
$anom = array_filter($totalUpdates, fn($u) => $u['old'] >= $u['new'] && $u['old'] > $u['subtotal']);
if ($anom) {
    echo "\n[!] " . count($anom) . " facturas ya tienen total > subtotal (revisar antes de aplicar):\n";
    foreach (array_slice($anom, 0, 10, true) as $fid => $u) {
        printf("    %d  %s  old=%.2f new=%.2f sub=%.2f\n", $fid, $u['no_factura'], $u['old'], $u['new'], $u['subtotal']);
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
foreach (array_keys($itemUpdates) as $iid) {
    $r = $conn->prepare("SELECT id, factura_id, subtotal, itbis_amount FROM factura_items WHERE id = :id");
    $r->execute([':id' => $iid]);
    $snap['items'][] = $r->fetch();
}
foreach ($totalUpdates as $fid => $u) {
    $snap['facturas'][] = ['id' => $fid, 'total' => $u['old']];
}
$snapFile = "$backupDir/itbis_backfill_snapshot_$stamp.json";
file_put_contents($snapFile, json_encode($snap, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "\nSnapshot de reversion: $snapFile\n";

// 5) Aplicar en transaccion.
try {
    $conn->beginTransaction();
    $updItem = $conn->prepare("UPDATE factura_items SET itbis_amount = :v WHERE id = :id");
    foreach ($itemUpdates as $iid => $v) { $updItem->execute([':v' => $v, ':id' => $iid]); }
    $updTot = $conn->prepare("UPDATE facturas SET total = :t WHERE id = :id AND tipo_ecf IS NULL");
    foreach ($totalUpdates as $fid => $u) { $updTot->execute([':t' => $u['new'], ':id' => $fid]); }
    $conn->commit();
    echo "APLICADO: " . count($itemUpdates) . " items y " . count($totalUpdates) . " totales actualizados.\n";
} catch (Throwable $e) {
    if ($conn->inTransaction()) { $conn->rollBack(); }
    echo "ERROR (rollback): " . $e->getMessage() . "\n";
    exit(1);
}
