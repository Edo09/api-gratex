<?php
/**
 * test_pos_venta.php — prueba de integracion del cobro del POS
 * (docs/specs/pos.md K2, K3, §9.5, F5, F6, F7, P1-P3): turno, venta E32 con
 * precio con ITBIS, idempotencia, rechazo, DGII lenta o caida y reenvio.
 *
 * Corre contra un API LOCAL y una DGII SIMULADA (tools/mock_dgii_local.php):
 * emite comprobantes de verdad contra lo que este configurado, asi que se niega
 * a correr si la DGII no es local.
 *
 * Entorno (ademas del de test_pos_backend.php):
 *   DGII_ECF_BASE_URL y DGII_FC_BASE_URL -> http://127.0.0.1:<puerto del mock>
 *   (los mismos que tiene el API), DGII_ECF_CERT_PATH/PASSWORD con un
 *   certificado de prueba (autofirmado: openssl req -x509 ... y pkcs12 -export),
 *   POS_DGII_TIMEOUT=5 y POS_TEST_DGII_MOCK_DIR = el MOCK_DGII_STATE del mock
 *   (aqui se cambia su modo y se leen sus registros).
 *   Mock: MOCK_DGII_STATE=<dir> php -S 127.0.0.1:8098 tools/mock_dgii_local.php
 *
 * Uso:  php tools/test_pos_venta.php      (sale con 1 si algo falla)
 */

$base = rtrim(getenv('POS_TEST_BASE') ?: 'http://127.0.0.1:8099/api', '/');
foreach (['POS_TEST_ADMIN_A', 'POS_TEST_PASS', 'MASTER_DB_HOST', 'POS_TEST_DGII_MOCK_DIR'] as $var) {
    if ((string) getenv($var) === '') {
        fwrite(STDERR, "Falta la variable {$var}. Ver la cabecera de este archivo.\n");
        exit(2);
    }
}
foreach (['DGII_ECF_BASE_URL', 'DGII_FC_BASE_URL'] as $var) {
    if (!preg_match('#^http://(127\.0\.0\.1|localhost)(:\d+)?$#', (string) getenv($var))) {
        fwrite(STDERR, "{$var} no apunta a la DGII simulada local. Esta prueba EMITE: nunca contra la DGII real.\n");
        exit(2);
    }
}
if (preg_match('#gratex\.net|fiscalpoint\.com\.do#', $base)) {
    fwrite(STDERR, "POS_TEST_BASE apunta a produccion. Solo local.\n");
    exit(2);
}
$mock = rtrim((string) getenv('POS_TEST_DGII_MOCK_DIR'), '/\\');
$modo = fn(string $m) => file_put_contents($mock . '/modo.txt', $m);
$rfceRecibidos = function (string $encf) use ($mock): int {
    $log = is_file($mock . '/log.txt') ? file($mock . '/log.txt', FILE_IGNORE_NEW_LINES) : [];
    return count(array_filter($log, fn($l) => str_contains($l, "rfce {$encf} ")));
};

$fallos = 0;
$total = 0;
$chk = function (string $desc, bool $ok, $obtenido = null) use (&$fallos, &$total) {
    $total++;
    if (!$ok) {
        $fallos++;
    }
    printf("  [%s] %s\n", $ok ? 'OK  ' : 'FALLO', $desc);
    if (!$ok && $obtenido !== null) {
        echo '         obtenido: ' . substr(json_encode($obtenido, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 700) . "\n";
    }
};

/** @return array{0:int,1:array} */
$api = function (string $metodo, string $ruta, ?array $body = null, array $headers = []) use ($base): array {
    $ch = curl_init($base . $ruta);
    $h = ['Content-Type: application/json'];
    foreach ($headers as $k => $v) {
        $h[] = "{$k}: {$v}";
    }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 60]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode((string) $raw, true);
    return [$http, is_array($json) ? $json : ['_crudo' => substr((string) $raw, 0, 400)]];
};
$uuid = function (): string {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
};

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;charset=utf8mb4', getenv('MASTER_DB_HOST'), getenv('MASTER_DB_PORT') ?: '3306'),
    getenv('MASTER_DB_USER'), getenv('MASTER_DB_PASS'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$master = getenv('MASTER_DB_NAME') ?: 'gratex_master';
$f = $pdo->query("SELECT t.id, t.db_name FROM `{$master}`.users u JOIN `{$master}`.tenants t ON t.id = u.tenant_id WHERE u.username = "
    . $pdo->quote((string) getenv('POS_TEST_ADMIN_A')))->fetch();
[$tenantA, $dbA] = [(int) $f['id'], $f['db_name']];
$pdo->exec("UPDATE `{$master}`.tenants SET pos_enabled = 1 WHERE id = {$tenantA}");

// --- Estado limpio --------------------------------------------------------
$pdo->exec("DELETE FROM `{$dbA}`.pos_caja_movimientos");
$pdo->exec("DELETE FROM `{$dbA}`.facturas WHERE pos_empleado_id IS NOT NULL");
$pdo->exec("DELETE FROM `{$dbA}`.pos_turnos");
$pdo->exec("DELETE FROM `{$dbA}`.pos_sesiones");
$pdo->exec("DELETE FROM `{$dbA}`.pos_empleados");
$pdo->exec("DELETE FROM `{$dbA}`.pos_cajas");
$pdo->exec("DELETE FROM `{$master}`.pos_equipos WHERE tenant_id = {$tenantA}");
$pdo->exec("DELETE FROM `{$dbA}`.inventory_movements WHERE product_id IN (SELECT id FROM `{$dbA}`.products WHERE sku LIKE 'VTATEST-%')");
$pdo->exec("DELETE FROM `{$dbA}`.products WHERE sku LIKE 'VTATEST-%'");
@unlink($mock . '/log.txt');
@unlink($mock . '/recibidos.txt');
$modo('acepta');
$inicioAudit = $pdo->query("SELECT COALESCE(MAX(id), 0) FROM `{$master}`.audit_logs")->fetchColumn();

$pdo->exec("INSERT IGNORE INTO `{$master}`.unidades_medida (id, codigo, descripcion, permite_decimales)
            VALUES (43, 'UND', 'Unidad', 0), (21, 'KG', 'Kilogramo', 1)");
$almacen = (int) $pdo->query("SELECT id FROM `{$dbA}`.warehouses ORDER BY id LIMIT 1")->fetchColumn();
$producto = function (string $sku, string $nombre, int $ind, string $precio, string $unidad, ?string $stock, int $activo = 1) use ($pdo, $dbA, $almacen): int {
    $st = $pdo->prepare("INSERT INTO `{$dbA}`.products (sku, nombre, warehouse_id, indicador_facturacion, indicador_bien_servicio, precio, unidad_medida, stock, activo)
                         VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?)");
    $st->execute([$sku, $nombre, $almacen, $ind, $precio, $unidad, $stock, $activo]);
    return (int) $pdo->lastInsertId();
};
$agua = $producto('VTATEST-1', 'Agua 500 ml', 1, '21.1864', '43', '50');      // 25.00 con ITBIS
$arroz = $producto('VTATEST-2', 'Arroz (libra)', 4, '38.0000', '21', '100');  // 38.00 exento, por libra
$viejo = $producto('VTATEST-3', 'Descontinuado', 1, '10.0000', '43', '5', 0);
$caro = $producto('VTATEST-4', 'Planta electrica', 4, '300000.0000', '43', '2');
$stock = fn(int $id) => (float) $pdo->query("SELECT stock FROM `{$dbA}`.products WHERE id = {$id}")->fetchColumn();

// --- Caja, empleados, equipo y sesion ----------------------------------------
[, $r] = $api('POST', '/auth/login', ['emailOrUsername' => getenv('POS_TEST_ADMIN_A'), 'password' => getenv('POS_TEST_PASS')]);
$admin = ['Authorization' => 'Bearer ' . ($r['data']['token'] ?? '')];
[, $r] = $api('POST', '/pos-admin/cajas', ['nombre' => 'Caja 1'], $admin);
$caja1 = $r['data']['caja']['id'];
[, $r] = $api('POST', '/pos-admin/cajas', ['nombre' => 'Caja 2'], $admin);
$caja2 = $r['data']['caja']['id'];
[, $r] = $api('POST', '/pos-admin/empleados', ['nombre' => 'Ana', 'rol' => 'cajero'], $admin);
$pinAna = $r['data']['pin'];
[, $r] = $api('POST', '/pos-admin/empleados', ['nombre' => 'Luis', 'rol' => 'supervisor'], $admin);
$pinLuis = $r['data']['pin'];
[, $r] = $api('POST', '/pos-admin/equipos', ['caja_id' => $caja1], $admin);
$eq1 = ['X-POS-EQUIPO' => $r['data']['token']];
[, $r] = $api('POST', '/pos-admin/equipos', ['caja_id' => $caja2], $admin);
$eq2 = ['X-POS-EQUIPO' => $r['data']['token']];
[, $r] = $api('POST', '/pos/sesion', ['pin' => $pinAna], $eq1);
$ana = $eq1 + ['X-POS-SESION' => $r['data']['token']];
$adminId = (int) $pdo->query("SELECT id FROM `{$master}`.users WHERE username = " . $pdo->quote((string) getenv('POS_TEST_ADMIN_A')))->fetchColumn();

$venta = fn(array $lineas, int $totalCentavos, int $forma = 1, ?int $recibido = null, ?string $clave = null) => [
    'clave' => $clave ?? $uuid(),
    'lineas' => $lineas,
    'total_centavos' => $totalCentavos,
    'forma_pago' => $forma,
    'recibido_centavos' => $recibido,
    'ancho' => 80,
    'iniciada_ms' => (int) (microtime(true) * 1000) - 20000,
];
$basica = [['product_id' => $agua, 'cantidad' => 3], ['product_id' => $arroz, 'cantidad' => 2.75]]; // 75.00 + 104.50

// ---------------------------------------------------------------------------
echo "1. Turno (K2, K3)\n";
[$h, $r] = $api('POST', '/pos/ventas', $venta($basica, 17950, 1, 20000), $ana);
$chk('sin turno no se cobra: 409 TURNO_REQUERIDO', $h === 409 && ($r['codigo'] ?? '') === 'TURNO_REQUERIDO', [$h, $r]);
[$h, $r] = $api('POST', '/pos/turno', ['fondo_centavos' => '100'], $ana);
$chk('fondo que no es entero en centavos: 422', $h === 422 && ($r['codigo'] ?? '') === 'FONDO_INVALIDO', [$h, $r]);
[$h, $r] = $api('POST', '/pos/turno', ['fondo_centavos' => 150000], $ana);
$chk('Ana abre su turno con RD$1,500.00 de fondo', $h === 201 && ($r['data']['turno_caja']['fondo_inicial'] ?? 0) == 1500
    && ($r['data']['turno_caja']['empleado_nombre'] ?? '') === 'Ana', [$h, $r]);
[$h, $r] = $api('POST', '/pos/turno', ['fondo_centavos' => 0], $ana);
$chk('abrirlo otra vez: 409 TURNO_CAJA_OCUPADA', $h === 409 && ($r['codigo'] ?? '') === 'TURNO_CAJA_OCUPADA', [$h, $r]);
[, $r] = $api('POST', '/pos/sesion', ['pin' => $pinAna], $eq2);
$anaEnCaja2 = $eq2 + ['X-POS-SESION' => $r['data']['token']];
[$h, $r] = $api('POST', '/pos/turno', ['fondo_centavos' => 0], $anaEnCaja2);
$chk('Ana no puede abrir otro turno en la Caja 2: 409 TURNO_EN_OTRA_CAJA', $h === 409 && ($r['codigo'] ?? '') === 'TURNO_EN_OTRA_CAJA', [$h, $r]);
[, $r] = $api('POST', '/pos/sesion', ['pin' => $pinAna], $eq1);
$ana = $eq1 + ['X-POS-SESION' => $r['data']['token']];

// ---------------------------------------------------------------------------
echo "\n2. Validaciones antes de emitir (nada sale a la DGII)\n";
$casos = [
    ['clave invalida: 422 CLAVE_INVALIDA', ['clave' => 'abc'] + $venta($basica, 17950, 1, 20000), 422, 'CLAVE_INVALIDA'],
    ['sin lineas: 422 VENTA_VACIA', $venta([], 0, 1, 0), 422, 'VENTA_VACIA'],
    ['precio en el cuerpo no se acepta: la linea solo lleva product_id y cantidad', $venta([['product_id' => $agua, 'cantidad' => '3']], 7500, 1, 7500), 422, 'LINEA_INVALIDA'],
    ['producto inactivo: 409 PRODUCTO_NO_DISPONIBLE', $venta([['product_id' => $viejo, 'cantidad' => 1]], 1180, 1, 1180), 409, 'PRODUCTO_NO_DISPONIBLE'],
    ['1.5 unidades de algo que se vende por unidad: 422 CANTIDAD_INVALIDA', $venta([['product_id' => $agua, 'cantidad' => 1.5]], 3750, 1, 3750), 422, 'CANTIDAD_INVALIDA'],
    ['3 decimales en la libra: 422 CANTIDAD_INVALIDA', $venta([['product_id' => $arroz, 'cantidad' => 1.255]], 4769, 1, 4769), 422, 'CANTIDAD_INVALIDA'],
    ['total distinto del que vio el cajero: 409 TOTAL_DISTINTO con el correcto', $venta($basica, 17900, 1, 20000), 409, 'TOTAL_DISTINTO'],
    ['efectivo que no alcanza: 422 RECIBIDO_INSUFICIENTE', $venta($basica, 17950, 1, 17000), 422, 'RECIBIDO_INSUFICIENTE'],
    ['forma de pago desconocida: 422 FORMA_PAGO_INVALIDA', $venta($basica, 17950, 9), 422, 'FORMA_PAGO_INVALIDA'],
    ['RD$250,000 o mas sin comprador: 422 COMPRADOR_REQUERIDO', $venta([['product_id' => $caro, 'cantidad' => 1]], 30000000, 3), 422, 'COMPRADOR_REQUERIDO'],
];
$encfAntes = (int) $pdo->query("SELECT current_value FROM `{$dbA}`.ncf_sequences WHERE type = 'E32' AND ambiente = 'testecf'")->fetchColumn();
foreach ($casos as [$desc, $body, $http, $codigo]) {
    [$h, $r] = $api('POST', '/pos/ventas', $body, $ana);
    $ok = $h === $http && ($r['codigo'] ?? '') === $codigo;
    if ($codigo === 'TOTAL_DISTINTO') {
        $ok = $ok && ($r['total_centavos'] ?? 0) === 17950;
    }
    $chk($desc, $ok, [$h, $r]);
}
$encfDespues = (int) $pdo->query("SELECT current_value FROM `{$dbA}`.ncf_sequences WHERE type = 'E32' AND ambiente = 'testecf'")->fetchColumn();
$chk('ninguna validacion gasto un e-NCF', $encfAntes === $encfDespues, [$encfAntes, $encfDespues]);

// ---------------------------------------------------------------------------
echo "\n3. Venta en efectivo aceptada (F1, F4, P2)\n";
$stockAgua = $stock($agua);
$cuerpo = $venta($basica, 17950, 1, 20000);
[$h, $r] = $api('POST', '/pos/ventas', $cuerpo, $ana);
$v = $r['data']['venta'] ?? [];
$encf1 = (string) ($v['e_ncf'] ?? '');
$chk('201: E32 aceptado, sin envio pendiente, total 179.50', $h === 201 && preg_match('/^E32\d{10}$/', $encf1) === 1
    && $v['estado_dgii'] === 'RFCE_ACEPTADO' && $v['envio_pendiente'] === false && $v['total_centavos'] === 17950, [$h, $r]);
$c = $r['data']['cobro'] ?? [];
$chk('cobro: efectivo, recibido 200.00, devuelta 20.50', ($c['forma_pago'] ?? 0) === 1 && $c['recibido_centavos'] === 20000
    && $c['devuelta_centavos'] === 2050, $c);
$rec = $r['data']['recibo'] ?? null;
$chk('trae el recibo de 80 mm con las dos lineas y el timbre', is_array($rec) && ($rec['papel']['opcion'] ?? 0) === 80
    && count($rec['lineas'] ?? []) === 2 && !empty($rec['timbre']['codigo_seguridad']), $rec ? array_keys($rec) : $r);
$fila = $pdo->query("SELECT * FROM `{$dbA}`.facturas WHERE e_ncf = " . $pdo->quote($encf1))->fetch();
$chk('factura: consumidor final, a nombre del admin que habilito la caja, con turno, empleado y clave',
    $fila && $fila['client_id'] === null && (int) $fila['user_id'] === $adminId && (int) $fila['turno_id'] > 0
    && (int) $fila['pos_empleado_id'] > 0 && $fila['pos_idempotency_key'] === $cuerpo['clave'] && (float) $fila['total'] === 179.5, $fila);
$xml = (string) ($fila['xml_firmado'] ?? '');
$chk('e-CF firmado: IndicadorMontoGravado 1 y TablaFormasPago (efectivo, 179.50)', str_contains($xml, '<IndicadorMontoGravado>1</IndicadorMontoGravado>')
    && preg_match('#<FormaDePago>\s*<FormaPago>1</FormaPago>\s*<MontoPago>179\.50</MontoPago>#', $xml) === 1, substr($xml, 0, 200));
$rfce = (string) ($fila['rfce_xml'] ?? '');
$chk('RFCE: la misma TablaFormasPago y MontoTotal 179.50', preg_match('#<FormaPago>1</FormaPago>\s*<MontoPago>179\.50</MontoPago>#', $rfce) === 1
    && str_contains($rfce, '<MontoTotal>179.50</MontoTotal>'), substr($rfce, 0, 200));
$items = $pdo->query("SELECT product_id, quantity, amount, subtotal, itbis_amount FROM `{$dbA}`.factura_items WHERE factura_id = {$fila['id']} ORDER BY id")->fetchAll();
$chk('lineas: agua 3 x 25.00 = 63.56 + 11.44 ITBIS; arroz 2.75 x 38.00 = 104.50 exento',
    count($items) === 2 && (float) $items[0]['subtotal'] === 63.56 && (float) $items[0]['itbis_amount'] === 11.44
    && (float) $items[1]['quantity'] === 2.75 && (float) $items[1]['subtotal'] === 104.5 && (float) $items[1]['itbis_amount'] === 0.0, $items);
$mov = $pdo->query("SELECT * FROM `{$dbA}`.pos_caja_movimientos WHERE factura_id = {$fila['id']}")->fetch();
$chk('movimiento de caja: VENTA en efectivo de 179.50, recibido 200, devuelta 20.50 y la hora del primer articulo',
    $mov && $mov['tipo'] === 'VENTA' && (int) $mov['forma_pago'] === 1 && (float) $mov['monto'] === 179.5
    && (float) $mov['monto_recibido'] === 200.0 && (float) $mov['devuelta'] === 20.5 && $mov['iniciada_at'] !== null, $mov);
$chk('inventario: salieron 3 aguas', $stock($agua) === $stockAgua - 3, [$stockAgua, $stock($agua)]);

// ---------------------------------------------------------------------------
echo "\n4. Idempotencia (F5)\n";
$filas = fn() => (int) $pdo->query("SELECT COUNT(*) FROM `{$dbA}`.facturas WHERE pos_empleado_id IS NOT NULL")->fetchColumn();
$antes = $filas();
[$h, $r] = $api('POST', '/pos/ventas', $cuerpo, $ana);
$chk('la misma clave otra vez: la MISMA venta (repetida), con su cobro y su recibo', $h === 201 && ($r['data']['repetida'] ?? false) === true
    && ($r['data']['venta']['e_ncf'] ?? '') === $encf1 && ($r['data']['cobro']['devuelta_centavos'] ?? 0) === 2050
    && is_array($r['data']['recibo'] ?? null), [$h, $r]);
$chk('ni otra factura ni otro envio a la DGII', $filas() === $antes && $rfceRecibidos($encf1) === 1, [$filas(), $rfceRecibidos($encf1)]);

// Doble toque: mientras la primera peticion tiene la clave, la segunda espera.
$clave2 = $uuid();
$otra = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s', getenv('MASTER_DB_HOST'), getenv('MASTER_DB_PORT') ?: '3306', $dbA),
    getenv('MASTER_DB_USER'), getenv('MASTER_DB_PASS'));
$otra->query("SELECT GET_LOCK(CONCAT('posv_', SHA1(CONCAT(DATABASE(), ':', " . $otra->quote($clave2) . "))), 5)")->fetchColumn();
$mh = curl_multi_init();
$ch = curl_init($base . '/pos/ventas');
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-POS-EQUIPO: ' . $ana['X-POS-EQUIPO'], 'X-POS-SESION: ' . $ana['X-POS-SESION']],
    CURLOPT_POSTFIELDS => json_encode($venta([['product_id' => $agua, 'cantidad' => 1]], 2500, 3, null, $clave2))]);
curl_multi_add_handle($mh, $ch);
$t0 = microtime(true);
$liberado = false;
do {
    curl_multi_exec($mh, $corriendo);
    if (!$liberado && microtime(true) - $t0 > 2) {
        $otra->query("SELECT RELEASE_LOCK(CONCAT('posv_', SHA1(CONCAT(DATABASE(), ':', " . $otra->quote($clave2) . "))))")->fetchColumn();
        $liberado = true;
    }
    curl_multi_select($mh, 0.1);
} while ($corriendo > 0);
$espera = microtime(true) - $t0;
$resp = json_decode((string) curl_multi_getcontent($ch), true);
$chk('con la clave tomada por otra peticion, espera a que se suelte y emite una sola vez',
    $espera >= 2 && ($resp['data']['venta']['estado_dgii'] ?? '') === 'RFCE_ACEPTADO'
    && (int) $pdo->query("SELECT COUNT(*) FROM `{$dbA}`.facturas WHERE pos_idempotency_key = " . $pdo->quote($clave2))->fetchColumn() === 1,
    [round($espera, 1), $resp]);
$chk('tarjeta: sin recibido ni devuelta', ($resp['data']['cobro']['forma_pago'] ?? 0) === 3 && $resp['data']['cobro']['recibido_centavos'] === null
    && $resp['data']['cobro']['devuelta_centavos'] === null, $resp['data']['cobro'] ?? $resp);

// ---------------------------------------------------------------------------
echo "\n5. Rechazo de la DGII (F6)\n";
$modo('rechaza');
$stockAgua = $stock($agua);
$secAntes = (int) $pdo->query("SELECT current_value FROM `{$dbA}`.ncf_sequences WHERE type = 'E32' AND ambiente = 'testecf'")->fetchColumn();
[$h, $r] = $api('POST', '/pos/ventas', $venta([['product_id' => $agua, 'cantidad' => 2]], 5000, 1, 5000), $ana);
$encfRech = (string) ($r['e_ncf'] ?? '');
$chk('422 DGII_RECHAZO con el motivo de la DGII', $h === 422 && ($r['codigo'] ?? '') === 'DGII_RECHAZO'
    && str_contains((string) ($r['error'] ?? ''), 'El MontoTotal no corresponde'), [$h, $r]);
$filaR = $pdo->query("SELECT id, estado_dgii FROM `{$dbA}`.facturas WHERE e_ncf = " . $pdo->quote($encfRech))->fetch();
$chk('queda como historial (RFCE_RECHAZADO), sin dinero en caja ni salida de inventario', $filaR && $filaR['estado_dgii'] === 'RFCE_RECHAZADO'
    && (int) $pdo->query("SELECT COUNT(*) FROM `{$dbA}`.pos_caja_movimientos WHERE factura_id = {$filaR['id']}")->fetchColumn() === 0
    && $stock($agua) === $stockAgua, [$filaR, $stock($agua)]);
$secDespues = (int) $pdo->query("SELECT current_value FROM `{$dbA}`.ncf_sequences WHERE type = 'E32' AND ambiente = 'testecf'")->fetchColumn();
$chk('el e-NCF rechazado sin usar vuelve a la secuencia', $secDespues === $secAntes, [$secAntes, $secDespues]);
$modo('acepta');
[$h, $r] = $api('POST', '/pos/ventas', $venta([['product_id' => $agua, 'cantidad' => 2]], 5000, 1, 5000), $ana);
$chk('la venta siguiente reutiliza ese e-NCF y la rechazada queda archivada', $h === 201 && ($r['data']['venta']['e_ncf'] ?? '') === $encfRech
    && $pdo->query("SELECT estado_dgii FROM `{$dbA}`.facturas WHERE id = {$filaR['id']}")->fetchColumn() === 'RFCE_RECHAZADO_ARCHIVADO', [$h, $r]);

// ---------------------------------------------------------------------------
echo "\n6. DGII lenta: se imprime y se reenvia sola (F6, F7)\n";
$modo('lento');
$t0 = microtime(true);
[$h, $r] = $api('POST', '/pos/ventas', $venta([['product_id' => $arroz, 'cantidad' => 1]], 3800, 1, 5000), $ana);
$dur = microtime(true) - $t0;
$encfLento = (string) ($r['data']['venta']['e_ncf'] ?? '');
$chk('a los ~5 s responde 201 con envio pendiente (RFCE_PENDIENTE), recibo y devuelta', $h === 201 && $dur < 7.5
    && ($r['data']['venta']['estado_dgii'] ?? '') === 'RFCE_PENDIENTE' && $r['data']['venta']['envio_pendiente'] === true
    && is_array($r['data']['recibo'] ?? null) && $r['data']['cobro']['devuelta_centavos'] === 1200, [round($dur, 1), $h, $r]);
$filaL = $pdo->query("SELECT id, envio_pendiente, rfce_xml IS NOT NULL AS tiene_rfce FROM `{$dbA}`.facturas WHERE e_ncf = " . $pdo->quote($encfLento))->fetch();
$chk('guardada con envio_pendiente = 1, el RFCE firmado y su movimiento de caja', $filaL && (int) $filaL['envio_pendiente'] === 1
    && (int) $filaL['tiene_rfce'] === 1
    && (int) $pdo->query("SELECT COUNT(*) FROM `{$dbA}`.pos_caja_movimientos WHERE factura_id = {$filaL['id']}")->fetchColumn() === 1, $filaL);
$modo('acepta');
sleep(4); // que el mock termine la respuesta lenta (ya lo habia recibido)
[$h, $r] = $api('POST', '/pos/pendientes/reenviar', [], $eq1);
$chk('reenviar: la DGII ya la tenia (la consulta la encuentra) y queda aceptada SIN reenviarla',
    $h === 200 && ($r['data']['aceptadas'] ?? 0) === 1 && ($r['data']['pendientes'] ?? -1) === 0 && $rfceRecibidos($encfLento) === 1, [$h, $r, $rfceRecibidos($encfLento)]);
$chk('la factura pasa a RFCE_ACEPTADO', $pdo->query("SELECT estado_dgii FROM `{$dbA}`.facturas WHERE id = {$filaL['id']}")->fetchColumn() === 'RFCE_ACEPTADO');

echo "\n7. DGII caida: se reenvia cuando vuelve (F7)\n";
$modo('caido');
[$h, $r] = $api('POST', '/pos/ventas', $venta([['product_id' => $agua, 'cantidad' => 1]], 2500, 2), $ana);
$encfCaido = (string) ($r['data']['venta']['e_ncf'] ?? '');
$chk('caida (503 sin respuesta util): 201 con envio pendiente', $h === 201 && ($r['data']['venta']['envio_pendiente'] ?? false) === true, [$h, $r]);
[$h, $r] = $api('POST', '/pos/pendientes/reenviar', [], $eq1);
$chk('sigue caida: el reenvio la deja pendiente', $h === 200 && ($r['data']['pendientes'] ?? 0) === 1 && ($r['data']['aceptadas'] ?? -1) === 0, [$h, $r]);
$modo('acepta');
[$h, $r] = $api('POST', '/pos/pendientes/reenviar', [], $eq1);
$chk('volvio: la consulta no la encuentra, se reenvia el RFCE guardado y queda aceptada', $h === 200 && ($r['data']['aceptadas'] ?? 0) === 1
    && ($r['data']['pendientes'] ?? -1) === 0
    && $pdo->query("SELECT estado_dgii FROM `{$dbA}`.facturas WHERE e_ncf = " . $pdo->quote($encfCaido))->fetchColumn() === 'RFCE_ACEPTADO', [$h, $r]);

// ---------------------------------------------------------------------------
echo "\n8. Recibo, turno ajeno y bitacora\n";
$idVenta = (int) $fila['id'];
[$h, $r] = $api('GET', "/pos/ventas/{$idVenta}/recibo?ancho=76", null, $ana);
$chk('reimprimir el recibo en 76 mm', $h === 200 && ($r['data']['recibo']['papel']['opcion'] ?? 0) === 76, [$h, $r]);
[$h, $r] = $api('GET', '/pos/ventas/999999/recibo', null, $ana);
$chk('recibo de algo que no es una venta del POS: 404', $h === 404, [$h, $r]);
[, $r] = $api('POST', '/pos/sesion', ['pin' => $pinLuis], $eq1);
$luis = $eq1 + ['X-POS-SESION' => $r['data']['token']];
[$h, $r] = $api('POST', '/pos/ventas', $venta([['product_id' => $agua, 'cantidad' => 1]], 2500, 3), $luis);
$chk('otro empleado en la caja con el turno de Ana: 409 TURNO_AJENO', $h === 409 && ($r['codigo'] ?? '') === 'TURNO_AJENO', [$h, $r]);

$filasAudit = $pdo->query("SELECT action, new_values FROM `{$master}`.audit_logs WHERE id > {$inicioAudit} AND module = 'pos'")->fetchAll();
$acciones = array_count_values(array_column($filasAudit, 'action'));
foreach (['POS_TURNO_ABIERTO', 'POS_VENTA', 'POS_VENTA_RECHAZADA', 'POS_VENTA_ENVIADA'] as $a) {
    $chk("bitacora: {$a}", ($acciones[$a] ?? 0) > 0, $acciones);
}
$todo = json_encode($filasAudit);
$chk('ningun PIN ni token en la bitacora', !str_contains($todo, $pinAna) && !str_contains($todo, $pinLuis)
    && !str_contains($todo, $ana['X-POS-SESION']) && !str_contains($todo, $ana['X-POS-EQUIPO']));

$api('POST', '/auth/signout', [], $admin);
echo "\n" . ($fallos === 0 ? "TODO OK ({$total} verificaciones)" : "{$fallos} de {$total} FALLARON") . "\n";
exit($fallos === 0 ? 0 : 1);
