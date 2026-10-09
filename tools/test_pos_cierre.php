<?php
/**
 * test_pos_cierre.php — prueba de integracion del cierre de turno del POS
 * (docs/specs/pos.md K4, K6-K9, S1, V4): conteo a ciegas, esperado y
 * diferencia, reporte, cierre de un turno ajeno con PIN de supervisor, nota,
 * ventas del turno, ventas canceladas y lineas quitadas, y el candado que no
 * deja cerrar mientras una venta se emite.
 *
 * Mismo entorno que tools/test_pos_venta.php (API local + DGII simulada
 * tools/mock_dgii_local.php): emite ventas para tener que cuadrar, asi que se
 * niega a correr si la DGII no es local.
 *
 * Uso:  php tools/test_pos_cierre.php      (sale con 1 si algo falla)
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
if (preg_match('#gratex\.net|fiscalpoint\.com\.do#', $base)) {
    fwrite(STDERR, "POS_TEST_BASE apunta a produccion. Solo local.\n");
    exit(2);
}
$mock = rtrim((string) getenv('POS_TEST_DGII_MOCK_DIR'), '/\\');
$modo = fn(string $m) => file_put_contents($mock . '/modo.txt', $m);

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

// --- Estado limpio ------------------------------------------------------------
$pdo->exec("DELETE FROM `{$dbA}`.pos_caja_movimientos");
$pdo->exec("DELETE FROM `{$dbA}`.facturas WHERE pos_empleado_id IS NOT NULL");
$pdo->exec("DELETE FROM `{$dbA}`.pos_turnos");
$pdo->exec("DELETE FROM `{$dbA}`.pos_sesiones");
$pdo->exec("DELETE FROM `{$dbA}`.pos_empleados");
$pdo->exec("DELETE FROM `{$dbA}`.pos_cajas");
$pdo->exec("DELETE FROM `{$master}`.pos_equipos WHERE tenant_id = {$tenantA}");
$pdo->exec("DELETE FROM `{$dbA}`.inventory_movements WHERE product_id IN (SELECT id FROM `{$dbA}`.products WHERE sku LIKE 'CIETEST-%')");
$pdo->exec("DELETE FROM `{$dbA}`.products WHERE sku LIKE 'CIETEST-%'");
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
$agua = $producto('CIETEST-1', 'Agua 500 ml', 1, '21.1864', '43');   // 25.00
$arroz = $producto('CIETEST-2', 'Arroz (libra)', 4, '38.0000', '21'); // 38.00 exento

[, $r] = $api('POST', '/auth/login', ['emailOrUsername' => getenv('POS_TEST_ADMIN_A'), 'password' => getenv('POS_TEST_PASS')]);
$admin = ['Authorization' => 'Bearer ' . ($r['data']['token'] ?? '')];
[, $r] = $api('POST', '/pos-admin/cajas', ['nombre' => 'Caja 1'], $admin);
$caja1 = $r['data']['caja']['id'];
[, $r] = $api('POST', '/pos-admin/cajas', ['nombre' => 'Caja 2'], $admin);
$caja2 = $r['data']['caja']['id'];
$pines = [];
foreach ([['Ana', 'cajero'], ['Bea', 'cajero'], ['Luis', 'supervisor']] as [$nombre, $rol]) {
    [, $r] = $api('POST', '/pos-admin/empleados', ['nombre' => $nombre, 'rol' => $rol], $admin);
    $pines[$nombre] = $r['data']['pin'];
}
[, $r] = $api('POST', '/pos-admin/equipos', ['caja_id' => $caja1], $admin);
$eq1 = ['X-POS-EQUIPO' => $r['data']['token']];
[, $r] = $api('POST', '/pos-admin/equipos', ['caja_id' => $caja2], $admin);
$eq2 = ['X-POS-EQUIPO' => $r['data']['token']];
$entrar = function (string $quien, array $eq) use ($api, $pines): array {
    [, $r] = $api('POST', '/pos/sesion', ['pin' => $pines[$quien]], $eq);
    return $eq + ['X-POS-SESION' => (string) ($r['data']['token'] ?? '')];
};
$vender = function (array $ses, array $lineas, int $total, int $forma, ?int $recibido = null) use ($api, $uuid): array {
    return $api('POST', '/pos/ventas', ['clave' => $uuid(), 'lineas' => $lineas, 'total_centavos' => $total,
        'forma_pago' => $forma, 'recibido_centavos' => $recibido, 'ancho' => 80, 'iniciada_ms' => null], $ses);
};
$conteo = fn(array $c, int $otros = 0) => array_map('intval', $c) + ['otros_centavos' => $otros];

// ---------------------------------------------------------------------------
echo "1. Un turno con ventas de todo tipo\n";
$ana = $entrar('Ana', $eq1);
[$h, $r] = $api('POST', '/pos/turno', ['fondo_centavos' => 150000], $ana);
$turnoAna = (int) ($r['data']['turno_caja']['id'] ?? 0);
$chk('Ana abre su turno con RD$1,500.00', $h === 201 && $turnoAna > 0, [$h, $r]);
[$h1] = $vender($ana, [['product_id' => $agua, 'cantidad' => 3], ['product_id' => $arroz, 'cantidad' => 2.75]], 17950, 1, 20000);
[$h2] = $vender($ana, [['product_id' => $agua, 'cantidad' => 1]], 2500, 3);
[$h3] = $vender($ana, [['product_id' => $arroz, 'cantidad' => 1]], 3800, 2);
$modo('lento');
[$h4, $r4] = $vender($ana, [['product_id' => $agua, 'cantidad' => 2]], 5000, 1, 5000);
$encfPendiente = (string) ($r4['data']['venta']['e_ncf'] ?? '');
$modo('rechaza');
[$h5, $r5] = $vender($ana, [['product_id' => $agua, 'cantidad' => 4]], 10000, 1, 10000);
$modo('acepta');
$chk('ventas: efectivo 179.50, tarjeta 25.00, transferencia 38.00, efectivo 50.00 pendiente; una rechazada',
    [$h1, $h2, $h3, $h4, $h5] === [201, 201, 201, 201, 422] && ($r4['data']['venta']['envio_pendiente'] ?? false) === true
    && ($r5['codigo'] ?? '') === 'DGII_RECHAZO', [$h1, $h2, $h3, $h4, $h5, $r5]);

[$h, $r] = $api('POST', '/pos/eventos', ['tipo' => 'cancelada', 'monto_centavos' => 4500,
    'lineas' => [['product_id' => $arroz, 'nombre' => 'Arroz (libra)', 'cantidad' => 1]]], $ana);
$chk('venta cancelada (45.00): queda en el turno', $h === 200 && ($r['data']['registrado'] ?? false) === true, [$h, $r]);
$api('POST', '/pos/eventos', ['tipo' => 'quitada', 'monto_centavos' => 2500, 'lineas' => [['product_id' => $agua, 'nombre' => 'Agua', 'cantidad' => 1]]], $ana);
$api('POST', '/pos/eventos', ['tipo' => 'quitada', 'monto_centavos' => 2500, 'lineas' => []], $ana);
[$h, $r] = $api('POST', '/pos/eventos', ['tipo' => 'robada', 'monto_centavos' => 1], $ana);
$chk('evento desconocido: 422', $h === 422 && ($r['codigo'] ?? '') === 'EVENTO_INVALIDO', [$h, $r]);

[$h, $r] = $api('GET', '/pos/ventas', null, $ana);
$ventas = $r['data']['ventas'] ?? [];
$chk('ventas del turno (K9): las 4 cobradas, sin la rechazada, la mas nueva primero y la pendiente marcada',
    $h === 200 && count($ventas) === 4 && $ventas[0]['e_ncf'] === $encfPendiente && $ventas[0]['envio_pendiente'] === true
    && array_column($ventas, 'forma_pago') === [1, 2, 3, 1], [$h, $ventas]);
[$h, $r] = $api('GET', '/pos/estado', null, $ana);
$chk('a ciegas: el estado de la caja no dice cuanto deberia haber', $h === 200
    && !array_key_exists('efectivo_esperado', $r['data']['turno_caja'] ?? []) && !str_contains(json_encode($r), 'esperado'), $r);

// ---------------------------------------------------------------------------
echo "\n2. Otro cajero no puede cerrar el turno de Ana sin supervisor (K4, S1)\n";
$bea = $entrar('Bea', $eq1);
[$h, $r] = $api('POST', '/pos/eventos', ['tipo' => 'quitada', 'monto_centavos' => 999], $bea);
$chk('evento de quien no tiene turno: solo bitacora, no suma al de Ana', $h === 200 && ($r['data']['registrado'] ?? true) === false, [$h, $r]);
$cuentaAna = $conteo(['1000' => 1, '500' => 1, '200' => 1, '20' => 1, '5' => 1, '1' => 4], 25); // 1,729.25
[$h, $r] = $api('POST', '/pos/turno/cerrar', ['turno_id' => $turnoAna, 'conteo' => $cuentaAna], $bea);
$chk('Bea (cajera) sin permiso: 403 PERMISO_INVALIDO', $h === 403 && ($r['codigo'] ?? '') === 'PERMISO_INVALIDO', [$h, $r]);
[$h, $r] = $api('POST', '/pos/autorizar', ['pin' => $pines['Bea'], 'accion' => 'cerrar_turno', 'turno_id' => $turnoAna], $bea);
$chk('PIN de una cajera: 403 PIN_SIN_PERMISO y cuenta como intento', $h === 403 && ($r['codigo'] ?? '') === 'PIN_SIN_PERMISO'
    && ($r['intentos_restantes'] ?? 0) === 4, [$h, $r]);
$malo = '0000';
foreach (['0102', '0204', '0306', '0408'] as $c) {
    if (!in_array($c, $pines, true)) { $malo = $c; break; }
}
[$h, $r] = $api('POST', '/pos/autorizar', ['pin' => $malo, 'accion' => 'cerrar_turno', 'turno_id' => $turnoAna], $bea);
$chk('PIN que no existe: 401 PIN_INCORRECTO, quedan 3', $h === 401 && ($r['codigo'] ?? '') === 'PIN_INCORRECTO' && ($r['intentos_restantes'] ?? 0) === 3, [$h, $r]);
[$h, $r] = $api('POST', '/pos/autorizar', ['pin' => $pines['Luis'], 'accion' => 'abrir_gaveta', 'turno_id' => $turnoAna], $bea);
$chk('accion que no se autoriza: 422', $h === 422 && ($r['codigo'] ?? '') === 'ACCION_INVALIDA', [$h, $r]);
[$h, $r] = $api('POST', '/pos/autorizar', ['pin' => $pines['Luis'], 'accion' => 'cerrar_turno', 'turno_id' => $turnoAna], $bea);
$permiso = (string) ($r['data']['permiso'] ?? '');
$chk('PIN de Luis (supervisor): permiso para cerrar ESE turno', $h === 200 && $permiso !== '' && ($r['data']['supervisor']['nombre'] ?? '') === 'Luis', [$h, $r]);
$fallidos = (int) $pdo->query("SELECT intentos_fallidos FROM `{$master}`.pos_equipos WHERE token_hash = " . $pdo->quote(hash('sha256', $eq1['X-POS-EQUIPO'])))->fetchColumn();
$chk('un PIN de supervisor valido devuelve la cuenta de intentos a cero', $fallidos === 0, $fallidos);
$alterado = substr($permiso, 0, -1) . (substr($permiso, -1) === 'a' ? 'b' : 'a');
[$h, $r] = $api('POST', '/pos/turno/cerrar', ['turno_id' => $turnoAna, 'conteo' => $cuentaAna, 'permiso' => $alterado], $bea);
$chk('permiso alterado: 403', $h === 403 && ($r['codigo'] ?? '') === 'PERMISO_INVALIDO', [$h, $r]);
[$h, $r] = $api('POST', '/pos/turno/cerrar', ['turno_id' => $turnoAna, 'conteo' => ['3' => 1] + $cuentaAna, 'permiso' => $permiso], $bea);
$chk('denominacion que no existe (RD$3): 422', $h === 422 && ($r['codigo'] ?? '') === 'CONTEO_INVALIDO', [$h, $r]);
[$h, $r] = $api('POST', '/pos/turno/cerrar', ['turno_id' => $turnoAna, 'conteo' => ['100' => -1] + $cuentaAna, 'permiso' => $permiso], $bea);
$chk('cantidad negativa: 422', $h === 422 && ($r['codigo'] ?? '') === 'CONTEO_INVALIDO', [$h, $r]);
[$h, $r] = $api('POST', '/pos/turno/cerrar', ['turno_id' => $turnoAna + 999, 'conteo' => $cuentaAna, 'permiso' => $permiso], $bea);
$chk('conteo de otro turno: 409 TURNO_CAMBIO', $h === 409 && ($r['codigo'] ?? '') === 'TURNO_CAMBIO', [$h, $r]);

// ---------------------------------------------------------------------------
echo "\n3. Cierre a ciegas con el permiso: esperado, contado y diferencia (K6, K7, K8)\n";
[$h, $r] = $api('POST', '/pos/turno/cerrar', ['turno_id' => $turnoAna, 'conteo' => $cuentaAna, 'permiso' => $permiso], $bea);
$rep = $r['data']['reporte'] ?? [];
$chk('esperado = 1,500 de fondo + 179.50 + 50.00 en efectivo (la pendiente cuenta) = 1,729.50; contado 1,729.25; faltan 0.25',
    $h === 200 && ($rep['esperado_centavos'] ?? 0) === 172950 && $rep['contado_centavos'] === 172925 && $rep['diferencia_centavos'] === -25, [$h, $r]);
$porForma = array_column($rep['ventas']['por_forma'] ?? [], null, 'forma_pago');
$chk('ventas por forma: efectivo 2 / 229.50, transferencia 1 / 38.00, tarjeta 1 / 25.00; 4 en total',
    ($rep['ventas']['cantidad'] ?? 0) === 4 && ($porForma[1]['cantidad'] ?? 0) === 2 && $porForma[1]['monto_centavos'] === 22950
    && ($porForma[2]['monto_centavos'] ?? 0) === 3800 && ($porForma[3]['monto_centavos'] ?? 0) === 2500, $rep['ventas'] ?? $rep);
$chk('4 facturas E32; 1 cancelada (45.00) y 2 lineas quitadas (50.00); la pendiente listada, ninguna rechazada cobrada',
    ($rep['comprobantes'] ?? []) === ['E32' => 4] && $rep['canceladas'] === ['cantidad' => 1, 'monto_centavos' => 4500]
    && $rep['lineas_quitadas'] === ['cantidad' => 2, 'monto_centavos' => 5000]
    && array_column($rep['pendientes'], 'e_ncf') === [$encfPendiente] && $rep['rechazadas'] === [], $rep);
$chk('reporte: Ana es la del turno, Luis quien cerro, con el conteo por denominacion',
    ($rep['empleado']['nombre'] ?? '') === 'Ana' && ($rep['cerrado_por']['nombre'] ?? '') === 'Luis' && $rep['cerrado_por']['rol'] === 'supervisor'
    && ($rep['conteo']['1000'] ?? 0) === 1 && $rep['conteo']['otros_centavos'] === 25 && $rep['fondo_centavos'] === 150000, $rep);
$fila = $pdo->query("SELECT abierto, cerrado_por, efectivo_contado, efectivo_esperado, diferencia, conteo_json IS NOT NULL AS c, totales_json IS NOT NULL AS t
                     FROM `{$dbA}`.pos_turnos WHERE id = {$turnoAna}")->fetch();
$luisId = (int) $pdo->query("SELECT id FROM `{$dbA}`.pos_empleados WHERE nombre = 'Luis'")->fetchColumn();
$chk('en la base: cerrado (abierto NULL) por Luis, con contado, esperado, diferencia, conteo y la foto del reporte',
    $fila['abierto'] === null && (int) $fila['cerrado_por'] === $luisId && (float) $fila['efectivo_contado'] === 1729.25
    && (float) $fila['efectivo_esperado'] === 1729.5 && (float) $fila['diferencia'] === -0.25 && (int) $fila['c'] === 1 && (int) $fila['t'] === 1, $fila);
[$h, $r] = $api('POST', '/pos/turno/cerrar', ['turno_id' => $turnoAna, 'conteo' => $cuentaAna, 'permiso' => $permiso], $bea);
$chk('el conteo no se rehace: cerrar otra vez con el mismo permiso da 409 SIN_TURNO', $h === 409 && ($r['codigo'] ?? '') === 'SIN_TURNO', [$h, $r]);

[$h, $r] = $api('POST', '/pos/turno/nota', ['turno_id' => $turnoAna, 'nota' => '  Faltan 25 centavos:   no hay monedas  '], $bea);
$chk('nota despues de ver la diferencia: se guarda limpia y sale en el reporte', $h === 200
    && ($r['data']['reporte']['nota'] ?? '') === 'Faltan 25 centavos: no hay monedas', [$h, $r]);
[$h, $r] = $api('POST', '/pos/turno/nota', ['turno_id' => $turnoAna, 'nota' => 'otra'], $bea);
$chk('una sola nota: 409 NOTA_NO_PERMITIDA', $h === 409 && ($r['codigo'] ?? '') === 'NOTA_NO_PERMITIDA', [$h, $r]);
$beaCaja2 = $entrar('Bea', $eq2);
[$h, $r] = $api('POST', '/pos/turno/nota', ['turno_id' => $turnoAna, 'nota' => 'desde otra caja'], $beaCaja2);
$chk('desde otra caja no: 409', $h === 409, [$h, $r]);
$ana = $entrar('Ana', $eq1); // Bea entro despues en el mismo equipo: la sesion de Ana se habia cerrado
[$h, $r] = $vender($ana, [['product_id' => $agua, 'cantidad' => 1]], 2500, 3);
$chk('con el turno cerrado no se vende: 409 TURNO_REQUERIDO', $h === 409 && ($r['codigo'] ?? '') === 'TURNO_REQUERIDO', [$h, $r]);

// ---------------------------------------------------------------------------
echo "\n4. Cierre propio, cuadrado (K6)\n";
$bea = $entrar('Bea', $eq1);
[$h, $r] = $api('POST', '/pos/turno', ['fondo_centavos' => 0], $bea);
$turnoBea = (int) ($r['data']['turno_caja']['id'] ?? 0);
$chk('Bea abre su turno con la caja vacia (despues del cierre de Ana)', $h === 201 && $turnoBea > 0, [$h, $r]);
$vender($bea, [['product_id' => $agua, 'cantidad' => 1]], 2500, 1, 2500);
[$h, $r] = $api('POST', '/pos/turno/cerrar', ['turno_id' => $turnoBea, 'conteo' => $conteo(['20' => 1, '5' => 1])], $bea);
$rep = $r['data']['reporte'] ?? [];
$chk('Bea cierra su turno sin supervisor: esperado 25.00, contado 25.00, diferencia 0', $h === 200
    && $rep['esperado_centavos'] === 2500 && $rep['contado_centavos'] === 2500 && $rep['diferencia_centavos'] === 0
    && ($rep['cerrado_por']['nombre'] ?? '') === 'Bea', [$h, $r]);
$chk('sin ventas canceladas ni lineas quitadas en su turno (las de antes eran de Ana)',
    ($rep['canceladas'] ?? []) === ['cantidad' => 0, 'monto_centavos' => 0] && $rep['lineas_quitadas'] === ['cantidad' => 0, 'monto_centavos' => 0], $rep);

// ---------------------------------------------------------------------------
echo "\n5. Supervisor en su propia sesion y el candado del turno\n";
$ana = $entrar('Ana', $eq1);
[, $r] = $api('POST', '/pos/turno', ['fondo_centavos' => 10000], $ana);
$turnoAna2 = (int) ($r['data']['turno_caja']['id'] ?? 0);
$luis = $entrar('Luis', $eq1);
$otra = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s', getenv('MASTER_DB_HOST'), getenv('MASTER_DB_PORT') ?: '3306', $dbA),
    getenv('MASTER_DB_USER'), getenv('MASTER_DB_PASS'));
$candado = "CONCAT('post_', SHA1(CONCAT(DATABASE(), ':', {$turnoAna2})))";
$otra->query("SELECT GET_LOCK({$candado}, 5)")->fetchColumn();
$mh = curl_multi_init();
$ch = curl_init($base . '/pos/turno/cerrar');
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-POS-EQUIPO: ' . $luis['X-POS-EQUIPO'], 'X-POS-SESION: ' . $luis['X-POS-SESION']],
    CURLOPT_POSTFIELDS => json_encode(['turno_id' => $turnoAna2, 'conteo' => $conteo(['100' => 1])])]);
curl_multi_add_handle($mh, $ch);
$t0 = microtime(true);
$liberado = false;
do {
    curl_multi_exec($mh, $corriendo);
    if (!$liberado && microtime(true) - $t0 > 2) {
        $otra->query("SELECT RELEASE_LOCK({$candado})")->fetchColumn();
        $liberado = true;
    }
    curl_multi_select($mh, 0.1);
} while ($corriendo > 0);
$espera = microtime(true) - $t0;
$resp = json_decode((string) curl_multi_getcontent($ch), true);
$chk('con una venta emitiendose (candado tomado) el cierre espera y despues cierra', $espera >= 2
    && ($resp['data']['reporte']['diferencia_centavos'] ?? null) === 0, [round($espera, 1), $resp]);
$chk('Luis cerro el turno de Ana desde su propia sesion de supervisor, sin permiso aparte',
    ($resp['data']['reporte']['cerrado_por']['nombre'] ?? '') === 'Luis' && ($resp['data']['reporte']['empleado']['nombre'] ?? '') === 'Ana', $resp);

// ---------------------------------------------------------------------------
echo "\n6. app.*: Punto de venta -> Turnos\n";
[$h, $r] = $api('GET', '/pos-admin/turnos', null, $admin);
$lista = $r['data']['turnos'] ?? [];
$chk('lista: los 3 turnos, el mas reciente primero, sin el reporte completo', $h === 200 && count($lista) === 3
    && $lista[0]['id'] === $turnoAna2 && !array_key_exists('reporte', $lista[0]), [$h, $lista]);
$deAna = array_values(array_filter($lista, fn($t) => $t['id'] === $turnoAna))[0] ?? [];
$chk('el de Ana: diferencia -0.25, cerrado por Luis, con su nota', ($deAna['diferencia'] ?? 0) === -0.25
    && $deAna['cerrado_por_nombre'] === 'Luis' && $deAna['nota'] === 'Faltan 25 centavos: no hay monedas', $deAna);
[$h, $r] = $api('GET', '/pos-admin/turnos?caja_id=' . $caja1 . '&empleado_id=' . $pdo->query("SELECT id FROM `{$dbA}`.pos_empleados WHERE nombre = 'Bea'")->fetchColumn(), null, $admin);
$chk('filtro por caja y empleado: solo el de Bea', $h === 200 && array_column($r['data']['turnos'] ?? [], 'id') === [$turnoBea], [$h, $r]);
[$h, $r] = $api('GET', '/pos-admin/turnos?desde=2026-13-01', null, $admin);
$chk('fecha mal escrita: 422', $h === 422, [$h, $r]);
[$h, $r] = $api('GET', "/pos-admin/turnos/{$turnoAna}", null, $admin);
$chk('detalle: el mismo reporte que se imprimio al cerrar, con la nota', $h === 200 && ($r['data']['reporte']['contado_centavos'] ?? 0) === 172925
    && ($r['data']['reporte']['nota'] ?? '') === 'Faltan 25 centavos: no hay monedas', [$h, $r]);
[$h, $r] = $api('GET', '/pos-admin/turnos/999999', null, $admin);
$chk('turno que no existe: 404', $h === 404, [$h, $r]);

// ---------------------------------------------------------------------------
echo "\n7. Bitacora\n";
$filas = $pdo->query("SELECT action, new_values FROM `{$master}`.audit_logs WHERE id > {$inicioAudit} AND module = 'pos'")->fetchAll();
$acciones = array_count_values(array_column($filas, 'action'));
foreach (['POS_TURNO_CERRADO', 'POS_AUTORIZACION', 'POS_AUTORIZACION_FALLIDA', 'POS_VENTA_CANCELADA', 'POS_LINEA_QUITADA'] as $a) {
    $chk("registra {$a}", ($acciones[$a] ?? 0) > 0, $acciones);
}
$todo = json_encode($filas);
$chk('ningun PIN, permiso ni token en la bitacora', !array_filter($pines, fn($p) => str_contains($todo, '"' . $p . '"'))
    && !str_contains($todo, $permiso) && !str_contains($todo, $eq1['X-POS-EQUIPO']));

$api('POST', '/auth/signout', [], $admin);
echo "\n" . ($fallos === 0 ? "TODO OK ({$total} verificaciones)" : "{$fallos} de {$total} FALLARON") . "\n";
exit($fallos === 0 ? 0 : 1);
