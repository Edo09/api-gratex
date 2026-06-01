<?php
// Corrige facturas.total para id 1259 usando el <MontoTotal> del XML firmado
// (valor que la DGII valida contra el timbre). Idempotente: solo actualiza si
// hay diferencia. Ejecutar en el servidor (donde hay acceso a la BD).
//   php tools/fix_1259_monto.php          -> solo muestra el diagnostico
//   php tools/fix_1259_monto.php --apply  -> aplica el UPDATE si hay mismatch
require_once __DIR__ . '/../src/Models/facturaModel.php';

$apply = in_array('--apply', $argv, true);
$db = Database::getInstance()->getConnection();

$stmt = $db->prepare('SELECT id, e_ncf, total, xml_firmado FROM facturas WHERE id = :id');
$stmt->execute([':id' => 1259]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) { echo "1259 no encontrada\n"; exit(1); }

if (empty($row['xml_firmado']) ||
    !preg_match('/<MontoTotal>\s*([0-9.]+)\s*<\/MontoTotal>/i', $row['xml_firmado'], $m)) {
    echo "No se pudo leer <MontoTotal> del xml_firmado de 1259\n"; exit(1);
}
$xmlMonto = round((float) $m[1], 2);
$colTotal = round((float) $row['total'], 2);

echo "id={$row['id']} e_ncf={$row['e_ncf']}\n";
echo "total (col): {$colTotal}\n";
echo "MontoTotal : {$xmlMonto}\n";

if ($xmlMonto === $colTotal) { echo "Ya coinciden. Nada que hacer.\n"; exit(0); }

if (!$apply) { echo "Mismatch. Re-ejecuta con --apply para corregir.\n"; exit(0); }

$upd = $db->prepare('UPDATE facturas SET total = :t WHERE id = 1259');
$upd->execute([':t' => $xmlMonto]);
echo "Actualizado total -> {$xmlMonto}\n";
