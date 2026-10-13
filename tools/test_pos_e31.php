<?php
/**
 * test_pos_e31.php — prueba de integracion del credito fiscal (E31) en el POS
 * (docs/specs/pos.md F2, V5, F6, F7): cliente por RNC (existente, nuevo desde
 * el registro de contribuyentes, no inscrito, servicio caido), descuento del
 * cliente, e-CF completo con trackId, rechazo al recibir y despues, y la DGII
 * lenta o caida (ConsultaTrackIds antes de reenviar).
 *
 * Mismo entorno que tools/test_pos_venta.php, mas RNC_CONSULTA_URL apuntando
 * al simulador (tools/mock_dgii_local.php). Emite: se niega a correr si la
 * DGII no es local.
 *
 * Uso:  php tools/test_pos_e31.php      (sale con 1 si algo falla)
 */

$base = rtrim(getenv('POS_TEST_BASE') ?: 'http://127.0.0.1:8099/api', '/');
foreach (['POS_TEST_ADMIN_A', 'POS_TEST_PASS', 'MASTER_DB_HOST', 'POS_TEST_DGII_MOCK_DIR'] as $var) {
    if ((string) getenv($var) === '') {
        fwrite(STDERR, "Falta la variable {$var}. Ver la cabecera de tools/test_pos_venta.php.\n");
        exit(2);
    }
}
foreach (['DGII_ECF_BASE_URL', 'DGII_FC_BASE_URL'] as $var) {
    if (!preg_match('#^http://(127\.0\.0\.1|localhost)(:\d+)?$#', (string) getenv($var))) {
        fwrite(STDERR, "{$var} no apunta a la DGII simulada local. Esta prueba EMITE: nunca contra la DGII real.\n");
        exit(2);
    }
}
if (!preg_match('#^http://(127\.0\.0\.1|localhost)(:\d+)?/#', (string) getenv('RNC_CONSULTA_URL'))) {
    fwrite(STDERR, "RNC_CONSULTA_URL tiene que apuntar al simulador local (crea clientes de prueba).\n");
    exit(2);
}
if (preg_match('#gratex\.net|fiscalpoint\.com\.do#', $base)) {
    fwrite(STDERR, "POS_TEST_BASE apunta a produccion. Solo local.\n");
    exit(2);
}
$mock = rtrim((string) getenv('POS_TEST_DGII_MOCK_DIR'), '/\\');
$modo = fn(string $m) => file_put_contents($mock . '/modo.txt', $m);
$recepciones = function (string $encf) use ($mock): int {
    $log = is_file($mock . '/log.txt') ? file($mock . '/log.txt', FILE_IGNORE_NEW_LINES) : [];
    return count(array_filter($log, fn($l) => str_contains($l, "ecf {$encf} modo=acepta") || str_contains($l, "ecf {$encf} modo=lento")));
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
        echo '         obtenido: ' . substr(json_encode($obtenido, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 900) . "\n";
    }
};
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
$rncEmisor = (string) $pdo->query("SELECT rnc FROM `{$dbA}`.emisor_config ORDER BY id LIMIT 1")->fetchColumn();

// --- Estado limpio ------------------------------------------------------------
$pdo->exec("DELETE FROM `{$dbA}`.pos_caja_movimientos");
$pdo->exec("DELETE FROM `{$dbA}`.facturas WHERE pos_empleado_id IS NOT NULL");
$pdo->exec("DELETE FROM `{$dbA}`.pos_turnos");
$pdo->exec("DELETE FROM `{$dbA}`.pos_sesiones");
$pdo->exec("DELETE FROM `{$dbA}`.pos_empleados");
$pdo->exec("DELETE FROM `{$dbA}`.pos_cajas");
$pdo->exec("DELETE FROM `{$master}`.pos_equipos WHERE tenant_id = {$tenantA}");
$pdo->exec("DELETE FROM `{$dbA}`.inventory_movements WHERE product_id IN (SELECT id FROM `{$dbA}`.products WHERE sku LIKE 'E31TEST-%')");
$pdo->exec("DELETE FROM `{$dbA}`.products WHERE sku LIKE 'E31TEST-%'");
$pdo->exec("DELETE FROM `{$dbA}`.clients WHERE rnc IN ('131000001', '101010101') OR client_name = 'E31TEST sin RNC'");
@unlink($mock . '/log.txt');
@unlink($mock . '/recibidos.txt');
@unlink($mock . '/recibidos_ecf.json');
$modo('acepta');
$inicioAudit = $pdo->query("SELECT COALESCE(MAX(id), 0) FROM `{$master}`.audit_logs")->fetchColumn();

$pdo->exec("INSERT IGNORE INTO `{$master}`.unidades_medida (id, codigo, descripcion, permite_decimales)
            VALUES (43, 'UND', 'Unidad', 0), (21, 'KG', 'Kilogramo', 1)");
$almacen = (int) $pdo->query("SELECT id FROM `{$dbA}`.warehouses ORDER BY id LIMIT 1")->fetchColumn();
$producto = function (string $sku, string $nombre, int $ind, string $precio, string $unidad) use ($pdo, $dbA, $almacen): int {
    $st = $pdo->prepare("INSERT INTO `{$dbA}`.products (sku, nombre, warehouse_id, indicador_facturacion, indicador_bien_servicio, precio, unidad_medida, stock, activo)
                         VALUES (?, ?, ?, ?, 1, ?, ?, 100, 1)");
    $st->execute([$sku, $nombre, $almacen, $ind, $precio, $unidad]);
    return (int) $pdo->lastInsertId();
};
$agua = $producto('E31TEST-1', 'Agua 500 ml', 1, '21.1864', '43');      // 25.00
$arroz = $producto('E31TEST-2', 'Arroz (libra)', 4, '38.0000', '21');    // 38.00 exento
$planta = $producto('E31TEST-3', 'Planta electrica', 4, '300000.0000', '43');
$cliente = function (string $nombre, ?string $rnc, float $descuento) use ($pdo, $dbA): int {
    $st = $pdo->prepare("INSERT INTO `{$dbA}`.clients (email, client_name, company_name, phone_number, rnc, razon_social, descuento)
                         VALUES ('', ?, ?, '', ?, ?, ?)");
    $st->execute([$nombre, $nombre, $rnc, $nombre, $descuento]);
    return (int) $pdo->lastInsertId();
};
$conDescuento = $cliente('CLIENTE CON DESCUENTO SRL', '101010101', 10.0);
$sinRnc = $cliente('E31TEST sin RNC', null, 0.0);
$mismaEmpresa = $cliente('E31TEST sin RNC', $rncEmisor, 0.0);

[, $r] = $api('POST', '/auth/login', ['emailOrUsername' => getenv('POS_TEST_ADMIN_A'), 'password' => getenv('POS_TEST_PASS')]);
$admin = ['Authorization' => 'Bearer ' . ($r['data']['token'] ?? '')];
[, $r] = $api('POST', '/pos-admin/cajas', ['nombre' => 'Caja 1'], $admin);
$caja1 = $r['data']['caja']['id'];
[, $r] = $api('POST', '/pos-admin/empleados', ['nombre' => 'Ana', 'rol' => 'cajero'], $admin);
$pinAna = $r['data']['pin'];
[, $r] = $api('POST', '/pos-admin/equipos', ['caja_id' => $caja1], $admin);
$eq = ['X-POS-EQUIPO' => $r['data']['token']];
[, $r] = $api('POST', '/pos/sesion', ['pin' => $pinAna], $eq);
$ana = $eq + ['X-POS-SESION' => (string) ($r['data']['token'] ?? '')];
$api('POST', '/pos/turno', ['fondo_centavos' => 0], $ana);
$vender = function (array $lineas, int $total, ?int $clientId, string $tipo = '31', int $forma = 1, ?string $clave = null) use ($api, $ana, $uuid): array {
    return $api('POST', '/pos/ventas', ['clave' => $clave ?? $uuid(), 'lineas' => $lineas, 'total_centavos' => $total,
        'forma_pago' => $forma, 'recibido_centavos' => $forma === 1 ? $total : null, 'ancho' => 80, 'iniciada_ms' => null,
        'tipo_ecf' => $tipo, 'client_id' => $clientId], $ana);
};
$factura = fn(string $encf) => $pdo->query("SELECT * FROM `{$dbA}`.facturas WHERE e_ncf = " . $pdo->quote($encf))->fetch();

// ---------------------------------------------------------------------------
echo "1. Cliente por RNC (F2)\n";
[$h, $r] = $api('POST', '/pos/clientes/rnc', ['rnc' => '12345'], $ana);
$chk('RNC de 5 digitos: 422 RNC_FORMATO', $h === 422 && ($r['codigo'] ?? '') === 'RNC_FORMATO', [$h, $r]);
[$h, $r] = $api('POST', '/pos/clientes/rnc', ['rnc' => '101-01010-1'], $ana);
$chk('cliente que ya existe (con guiones): se usa, con su descuento del 10%', $h === 200 && ($r['data']['nuevo'] ?? true) === false
    && ($r['data']['cliente']['id'] ?? 0) === $conDescuento && $r['data']['cliente']['descuento'] === 10 , [$h, $r]);
$antes = (int) $pdo->query("SELECT COUNT(*) FROM `{$dbA}`.clients")->fetchColumn();
[$h, $r] = $api('POST', '/pos/clientes/rnc', ['rnc' => '131000001'], $ana);
$nuevo = (int) ($r['data']['cliente']['id'] ?? 0);
$chk('RNC nuevo e inscrito: se crea el cliente con la razon social del registro', $h === 200 && ($r['data']['nuevo'] ?? false) === true
    && ($r['data']['cliente']['nombre'] ?? '') === 'COMERCIAL PRUEBA SRL' && ($r['data']['estado_dgii'] ?? '') === 'ACTIVO', [$h, $r]);
$fila = $pdo->query("SELECT client_name, company_name, razon_social, rnc, email, phone_number FROM `{$dbA}`.clients WHERE id = {$nuevo}")->fetch();
$chk('en la base: contacto = nombre comercial, sin correo ni telefono', $fila && $fila['client_name'] === 'COMERCIAL PRUEBA'
    && $fila['razon_social'] === 'COMERCIAL PRUEBA SRL' && $fila['rnc'] === '131000001' && $fila['email'] === '' && $fila['phone_number'] === ''
    && (int) $pdo->query("SELECT COUNT(*) FROM `{$dbA}`.clients")->fetchColumn() === $antes + 1, $fila);
[$h, $r] = $api('POST', '/pos/clientes/rnc', ['rnc' => '131000001'], $ana);
$chk('la segunda vez ya existe: el mismo cliente, sin duplicarlo', $h === 200 && ($r['data']['nuevo'] ?? true) === false
    && ($r['data']['cliente']['id'] ?? 0) === $nuevo, [$h, $r]);
[$h, $r] = $api('POST', '/pos/clientes/rnc', ['rnc' => '131000009'], $ana);
$chk('no inscrito: 404 RNC_NO_ENCONTRADO (sin credito fiscal)', $h === 404 && ($r['codigo'] ?? '') === 'RNC_NO_ENCONTRADO', [$h, $r]);
[$h, $r] = $api('POST', '/pos/clientes/rnc', ['rnc' => '131000002'], $ana);
$chk('servicio de RNC caido: 502 RNC_NO_DISPONIBLE, no se escribe a mano', $h === 502 && ($r['codigo'] ?? '') === 'RNC_NO_DISPONIBLE', [$h, $r]);
[$h, $r] = $api('POST', '/pos/clientes/rnc', ['rnc' => '00112345678'], $ana);
$chk('cedula no inscrita: 404', $h === 404, [$h, $r]);

// ---------------------------------------------------------------------------
echo "\n2. Validaciones del credito fiscal\n";
$una = [['product_id' => $agua, 'cantidad' => 2]];
foreach ([
    ['tipo 33: 422 TIPO_INVALIDO', [$una, 5000, $nuevo, '33'], 422, 'TIPO_INVALIDO'],
    ['E31 sin cliente: 422 CLIENTE_REQUERIDO', [$una, 5000, null], 422, 'CLIENTE_REQUERIDO'],
    ['cliente que no existe: 404', [$una, 5000, 999999], 404, 'CLIENTE_NO_EXISTE'],
    ['cliente sin RNC: 422 CLIENTE_SIN_RNC', [$una, 5000, $sinRnc], 422, 'CLIENTE_SIN_RNC'],
    ['el RNC de la propia empresa: 422 AUTOFACTURA', [$una, 5000, $mismaEmpresa], 422, 'AUTOFACTURA'],
] as [$desc, $args, $http, $codigo]) {
    [$h, $r] = $vender(...$args);
    $chk($desc, $h === $http && ($r['codigo'] ?? '') === $codigo, [$h, $r]);
}

// ---------------------------------------------------------------------------
echo "\n3. Credito fiscal aceptado: e-CF completo con trackId\n";
$clave = $uuid();
[$h, $r] = $vender($una, 5000, $nuevo, '31', 1, $clave);
$v = $r['data']['venta'] ?? [];
$encf1 = (string) ($v['e_ncf'] ?? '');
$chk('201: E31, enviado a la DGII (espera su veredicto: ENVIADO y pendiente)', $h === 201 && preg_match('/^E31\d{10}$/', $encf1) === 1
    && $v['tipo_ecf'] === '31' && $v['estado_dgii'] === 'ENVIADO' && $v['envio_pendiente'] === true, [$h, $r]);
$chk('la respuesta trae el cliente y el recibo con su RNC', ($v['cliente']['rnc'] ?? '') === '131000001'
    && str_contains(json_encode($r['data']['recibo'] ?? null), '131000001'), $r['data']['recibo'] ?? $r);
$fac = $factura($encf1);
$xml = (string) ($fac['xml_firmado'] ?? '');
$chk('factura: con el cliente, su trackId y el XML con RNCComprador, IndicadorMontoGravado 1 y TablaFormasPago',
    $fac && (int) $fac['client_id'] === $nuevo && $fac['tipo_ecf'] === '31' && str_starts_with((string) $fac['track_id'], 'tk-')
    && str_contains($xml, '<RNCComprador>131000001</RNCComprador>') && str_contains($xml, '<IndicadorMontoGravado>1</IndicadorMontoGravado>')
    && preg_match('#<FormaPago>1</FormaPago>\s*<MontoPago>50\.00</MontoPago>#', $xml) === 1, $fac ? array_diff_key($fac, ['xml_firmado' => 1, 'rfce_xml' => 1]) : null);
[$h, $r] = $api('POST', '/pos/pendientes/reenviar', [], $eq);
$chk('reenviar: ConsultaResultado por su trackId -> ACEPTADO, sin reenviarlo', $h === 200 && ($r['data']['aceptadas'] ?? 0) === 1
    && $factura($encf1)['estado_dgii'] === 'ACEPTADO' && (int) $factura($encf1)['envio_pendiente'] === 0 && $recepciones($encf1) === 1, [$h, $r]);
[$h, $r] = $vender($una, 5000, $nuevo, '31', 1, $clave);
$chk('la misma clave: la misma venta (repetida) con su cliente', $h === 201 && ($r['data']['repetida'] ?? false) === true
    && ($r['data']['venta']['e_ncf'] ?? '') === $encf1 && ($r['data']['venta']['cliente']['id'] ?? 0) === $nuevo, [$h, $r]);
[$h, $r] = $api('GET', '/pos/ventas/' . $fac['id'] . '/recibo?ancho=80', null, $ana);
$chk('reimprimir: el recibo lleva al comprador', $h === 200 && str_contains(json_encode($r['data']['recibo'] ?? null), 'COMERCIAL PRUEBA SRL'), [$h, $r]);

// ---------------------------------------------------------------------------
echo "\n4. Descuento del cliente (V5)\n";
$dos = [['product_id' => $agua, 'cantidad' => 3], ['product_id' => $arroz, 'cantidad' => 1]]; // 75.00 + 38.00
[$h, $r] = $vender($dos, 11300, $conDescuento);
$chk('sin restar el 10%: 409 TOTAL_DISTINTO con el total correcto (101.70) y la ficha del cliente (su descuento de ahora)',
    $h === 409 && ($r['codigo'] ?? '') === 'TOTAL_DISTINTO' && ($r['total_centavos'] ?? 0) === 10170
    && ($r['cliente']['id'] ?? 0) === $conDescuento && ($r['cliente']['descuento'] ?? null) === 10, [$h, $r]);
[$h, $r] = $vender($dos, 10170, $conDescuento);
$encfDesc = (string) ($r['data']['venta']['e_ncf'] ?? '');
$chk('con el 10%: 201, total 101.70', $h === 201 && ($r['data']['venta']['total_centavos'] ?? 0) === 10170, [$h, $r]);
$items = $encfDesc !== '' ? $pdo->query("SELECT descuento_monto, subtotal, itbis_amount FROM `{$dbA}`.factura_items WHERE factura_id = "
    . (int) $factura($encfDesc)['id'] . ' ORDER BY id')->fetchAll() : [];
$chk('lineas: agua 75.00 - 7.50 = 67.50 (57.20 + 10.30 ITBIS); arroz 38.00 - 3.80 = 34.20 exento',
    count($items) === 2 && (float) $items[0]['descuento_monto'] === 7.5 && (float) $items[0]['subtotal'] === 57.2
    && (float) $items[0]['itbis_amount'] === 10.3 && (float) $items[1]['descuento_monto'] === 3.8 && (float) $items[1]['subtotal'] === 34.2, $items);

// ---------------------------------------------------------------------------
echo "\n5. Rechazos\n";
$modo('rechaza');
[$h, $r] = $vender($una, 5000, $nuevo);
$chk('rechazado al recibir: 422 DGII_RECHAZO con el motivo, sin cobrar', $h === 422 && ($r['codigo'] ?? '') === 'DGII_RECHAZO'
    && str_contains((string) ($r['error'] ?? ''), 'RNCComprador no valido'), [$h, $r]);
$modo('rechaza_luego');
[$h, $r] = $vender($una, 5000, $nuevo);
$encfLuego = (string) ($r['data']['venta']['e_ncf'] ?? '');
$modo('acepta');
[$h2, $r2] = $api('POST', '/pos/pendientes/reenviar', [], $eq);
$chk('rechazado despues (ConsultaResultado): el reenvio lo reporta para la alerta y queda RECHAZADO',
    $h === 201 && $h2 === 200 && array_column($r2['data']['rechazadas'] ?? [], 'e_ncf') === [$encfLuego]
    && str_contains($r2['data']['rechazadas'][0]['motivo'] ?? '', 'RNCComprador no existe') && $factura($encfLuego)['estado_dgii'] === 'RECHAZADO', [$h, $h2, $r2]);

// ---------------------------------------------------------------------------
echo "\n6. DGII caida o lenta con un e-CF completo (F6, F7)\n";
$modo('caido');
[$h, $r] = $vender($una, 5000, $nuevo);
$encfCaido = (string) ($r['data']['venta']['e_ncf'] ?? '');
$fac = $encfCaido !== '' ? $factura($encfCaido) : null;
$chk('caida: 201, ENVIO_PENDIENTE, sin trackId, con el XML firmado guardado', $h === 201 && ($r['data']['venta']['estado_dgii'] ?? '') === 'ENVIO_PENDIENTE'
    && $fac && $fac['track_id'] === null && (int) $fac['envio_pendiente'] === 1 && !empty($fac['xml_firmado']), [$h, $r]);
[$h, $r] = $api('POST', '/pos/pendientes/reenviar', [], $eq);
$chk('sigue caida: ConsultaTrackIds dice que no lo tiene, el reenvio falla y sigue pendiente', $h === 200 && ($r['data']['pendientes'] ?? 0) === 1
    && $factura($encfCaido)['estado_dgii'] === 'ENVIO_PENDIENTE', [$h, $r]);
$modo('acepta');
[$h, $r] = $api('POST', '/pos/pendientes/reenviar', [], $eq);
$chk('volvio: se reenvia, recibe trackId y en la misma vuelta queda ACEPTADO', $h === 200 && ($r['data']['aceptadas'] ?? 0) === 1
    && $factura($encfCaido)['estado_dgii'] === 'ACEPTADO' && str_starts_with((string) $factura($encfCaido)['track_id'], 'tk-'), [$h, $r]);

$modo('lento');
[$h, $r] = $vender($una, 5000, $nuevo);
$encfLento = (string) ($r['data']['venta']['e_ncf'] ?? '');
$modo('acepta');
$chk('lenta: 201 pendiente sin trackId', $h === 201 && ($r['data']['venta']['estado_dgii'] ?? '') === 'ENVIO_PENDIENTE', [$h, $r]);
sleep(4);
[$h, $r] = $api('POST', '/pos/pendientes/reenviar', [], $eq);
$chk('ConsultaTrackIds la encuentra (la DGII la tenia): toma su trackId y queda ACEPTADA SIN reenviarla',
    $h === 200 && $factura($encfLento)['estado_dgii'] === 'ACEPTADO' && str_starts_with((string) $factura($encfLento)['track_id'], 'tk-')
    && $recepciones($encfLento) === 1, [$h, $r, $recepciones($encfLento)]);

// ---------------------------------------------------------------------------
echo "\n7. RD\$250,000 o mas\n";
[$h, $r] = $vender([['product_id' => $planta, 'cantidad' => 1]], 30000000, null, '32', 3);
$chk('como consumo: 422 COMPRADOR_REQUERIDO, que indica hacerla como credito fiscal', $h === 422 && ($r['codigo'] ?? '') === 'COMPRADOR_REQUERIDO'
    && str_contains((string) ($r['error'] ?? ''), 'crédito fiscal'), [$h, $r]);
[$h, $r] = $vender([['product_id' => $planta, 'cantidad' => 1]], 30000000, $nuevo, '31', 3);
$chk('como credito fiscal con el cliente: 201', $h === 201 && ($r['data']['venta']['tipo_ecf'] ?? '') === '31', [$h, $r]);

// ---------------------------------------------------------------------------
echo "\n8. Bitacora\n";
$acciones = array_count_values(array_column($pdo->query("SELECT action FROM `{$master}`.audit_logs WHERE id > {$inicioAudit} AND module = 'pos'")->fetchAll(), 'action'));
foreach (['POS_CLIENTE_CREADO', 'POS_VENTA', 'POS_VENTA_RECHAZADA', 'POS_VENTA_ENVIADA'] as $a) {
    $chk("registra {$a}", ($acciones[$a] ?? 0) > 0, $acciones);
}

$api('POST', '/auth/signout', [], $admin);
echo "\n" . ($fallos === 0 ? "TODO OK ({$total} verificaciones)" : "{$fallos} de {$total} FALLARON") . "\n";
exit($fallos === 0 ? 0 : 1);
