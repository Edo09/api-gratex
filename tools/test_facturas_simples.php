<?php
// Prueba end-to-end del CRUD de facturas no e-CF (a nivel de modelo).
// Crea, lee, lista, actualiza y ELIMINA un registro de prueba (se limpia solo).
// Tambien verifica que la guarda no permita borrar un e-CF emitido.
require_once __DIR__ . '/../src/Models/facturaModel.php';

$m = new facturaModel();
$ok = true;
function check($cond, $msg) { global $ok; echo ($cond ? "[PASS] " : "[FAIL] ") . $msg . "\n"; if (!$cond) $ok = false; }

$noFactura = 'TEST-CRUD-' . substr(bin2hex(random_bytes(3)), 0, 6);

echo "== CREATE ==\n";
$res = $m->createFacturaSimple([
    'no_factura'  => $noFactura,
    'client_name' => 'Cliente Prueba CRUD',
    'user_id'     => 2,
    'items'       => [
        ['description' => 'Servicio de diseno', 'quantity' => 2, 'amount' => 100, 'itbis_amount' => 36],
        ['description' => 'Impresion', 'quantity' => 1, 'amount' => 50, 'itbis_amount' => 9],
    ],
]);
check($res[0] === 'success', 'createFacturaSimple');
$factura = $res[1];
$id = $factura['factura_id'] ?? $factura['id'] ?? null;
check($id !== null, "id generado ({$id})");
check(($factura['tipo_ecf'] ?? 'x') === null, 'tipo_ecf NULL (no e-CF)');
check((float) $factura['total'] === 295.00, 'total computado = 295.00 (250 + 45 ITBIS), got ' . $factura['total']);
check(count($factura['items']) === 2, 'guardo 2 lineas');

echo "\n== GET ==\n";
$got = $m->getFacturaSimple($id);
check($got !== null, 'getFacturaSimple devuelve la factura');
check($got['no_factura'] === $noFactura, 'no_factura coincide');

echo "\n== LIST ==\n";
$list = $m->getFacturasSimplesPaginated(0, 5, $noFactura);
$count = $m->getFacturasSimplesCount($noFactura);
check($count >= 1, "count por query = {$count}");
check(!empty($list) && $list[0]['id'] == $id, 'aparece en el listado filtrado');

echo "\n== UPDATE ==\n";
$res = $m->updateFacturaSimple($id, [
    'no_factura' => $noFactura . '-MOD',
    'items' => [
        ['description' => 'Servicio unico', 'quantity' => 3, 'amount' => 100, 'itbis_amount' => 54],
    ],
]);
check($res[0] === 'success', 'updateFacturaSimple');
$upd = $res[1];
check($upd['no_factura'] === $noFactura . '-MOD', 'no_factura actualizado');
check((float) $upd['total'] === 354.00, 'total recomputado = 354.00 (300 + 54), got ' . $upd['total']);
check(count($upd['items']) === 1, 'lineas reemplazadas (1)');

echo "\n== GUARD (no tocar e-CF) ==\n";
$guard = $m->deleteFacturaSimple(1277); // 1277 es una nota E33 emitida
check($guard[0] === 'error', 'deleteFacturaSimple rechaza un e-CF: ' . $guard[1]);
$still = (new facturaModel())->getECFData(1277);
check($still !== null && $still['e_ncf'] === 'E330000000310', 'la nota 1277 sigue intacta');

echo "\n== DELETE (limpieza) ==\n";
$del = $m->deleteFacturaSimple($id);
check($del[0] === 'success', 'deleteFacturaSimple');
check($m->getFacturaSimple($id) === null, 'ya no existe');

echo "\n" . ($ok ? '>>> TODO OK' : '>>> HUBO FALLOS') . "\n";
