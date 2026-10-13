<?php
/**
 * test_pos_write_ahead.php — la venta del POS sobre el write-ahead (spec 2026-10-12, 4.2 a 4.4 y 5.2)
 * de punta a punta: API multi-tenant real, DGII simulada y bases scratch.
 *   1 RF1 misma clave con la primera esperando a la DGII · 2 RF2 corte entre guardar y la caja ·
 *   3 RF3 el reenvio no toca una venta en curso · 4 RF4 el certificado falla y se recupera ·
 *   5 RF5 E32 agotada y su salida al registrar un rango · 6 Reenviar a la DGII · 7 el cierre.
 * Necesita DOS APIs contra la misma base (php -S atiende de a una peticion): POS_TEST_BASE y
 * POS_TEST_BASE_2 (esta con POS_DGII_TIMEOUT=30). Las arma tools/test_pos_scratch.php.
 */

require_once __DIR__ . '/write_ahead_test_lib.php';
require_once __DIR__ . '/pos_scratch_guard.php';

$base1 = rtrim((string) getenv('POS_TEST_BASE'), '/');
$base2 = rtrim((string) getenv('POS_TEST_BASE_2'), '/');
foreach (['POS_TEST_BASE', 'POS_TEST_BASE_2', 'POS_TEST_ADMIN_A', 'POS_TEST_PASS', 'MASTER_DB_HOST', 'POS_TEST_DGII_MOCK_DIR'] as $var) {
    if ((string) getenv($var) === '') {
        fwrite(STDERR, "Falta la variable {$var}. Esta prueba la corre tools/test_pos_scratch.php.\n");
        exit(2);
    }
}
foreach (['DGII_ECF_BASE_URL', 'DGII_FC_BASE_URL'] as $var) {
    if (!preg_match('#^http://(127\.0\.0\.1|localhost)(:\d+)?$#', (string) getenv($var))) {
        fwrite(STDERR, "{$var} no apunta a la DGII simulada local. Esta prueba EMITE: nunca contra la DGII real.\n");
        exit(2);
    }
}
if (preg_match('#gratex\.net|fiscalpoint\.com\.do#', $base1 . $base2)) {
    fwrite(STDERR, "POS_TEST_BASE apunta a produccion. Solo local.\n");
    exit(2);
}
$mock = rtrim((string) getenv('POS_TEST_DGII_MOCK_DIR'), '/\\');
$modo = fn(string $m) => file_put_contents($mock . '/modo.txt', $m);
$logMock = fn(): array => is_file($mock . '/log.txt') ? (file($mock . '/log.txt', FILE_IGNORE_NEW_LINES) ?: []) : [];
/** Cuantas veces el simulador RECIBIO el e-NCF (RFCE o e-CF completo) y cuantas se PREGUNTO por el. */
$recepciones = fn(string $encf): int => count(array_filter($logMock(), static fn($l) => str_contains($l, "rfce {$encf} ") || str_contains($l, "ecf {$encf} ")));
$consultas = fn(string $encf): int => count(array_filter($logMock(), static fn($l) => str_contains($l, "consulta {$encf} ") || str_contains($l, "trackids {$encf} ")));

$api = function (string $metodo, string $ruta, ?array $body = null, array $headers = []) use ($base1): array {
    $h = [];
    foreach ($headers as $k => $v) {
        $h[] = "{$k}: {$v}";
    }
    $r = wa_http($metodo, $base1 . $ruta, $body, $h, 90);
    return [$r['status'], $r['json'] ?? ['_crudo' => substr($r['body'], 0, 400)]];
};
$uuid = static function (): string {
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
pos_scratch_exigir($pdo, (string) getenv('MASTER_DB_HOST'), [$master, $dbA]);
$pdo->exec("UPDATE `{$master}`.tenants SET pos_enabled = 1 WHERE id = {$tenantA}");
$adminId = (int) $pdo->query("SELECT id FROM `{$master}`.users WHERE username = " . $pdo->quote((string) getenv('POS_TEST_ADMIN_A')))->fetchColumn();

/** Lo que una prueba cambia a proposito y se deja como estaba, aunque la corrida se corte. */
$restaurar = static function () use ($pdo, $master, $dbA, $tenantA, $modo): void {
    $modo('acepta');
    $pdo->exec("UPDATE `{$master}`.tenants SET cert_path = NULL WHERE id = {$tenantA}");
    $pdo->exec("DELETE FROM `{$dbA}`.ncf_sequences WHERE ambiente = 'testecf' AND type IN ('E31', 'E32') AND numero_desde > 1");
    $pdo->exec("UPDATE `{$dbA}`.ncf_sequences SET numero_hasta = NULL WHERE ambiente = 'testecf' AND type IN ('E31', 'E32') AND numero_desde = 1");
};
register_shutdown_function($restaurar);

// --- Estado limpio ---------------------------------------------------------------------------
$pdo->exec("DELETE FROM `{$dbA}`.pos_caja_movimientos");
$pdo->exec("DELETE FROM `{$dbA}`.facturas WHERE pos_empleado_id IS NOT NULL OR user_id = {$adminId}");
foreach (['pos_turnos', 'pos_sesiones', 'pos_empleados', 'pos_cajas'] as $tabla) {
    $pdo->exec("DELETE FROM `{$dbA}`.{$tabla}");
}
$pdo->exec("DELETE FROM `{$master}`.pos_equipos WHERE tenant_id = {$tenantA}");
$pdo->exec("DELETE FROM `{$dbA}`.inventory_movements WHERE product_id IN (SELECT id FROM `{$dbA}`.products WHERE sku LIKE 'WATEST-%')");
$pdo->exec("DELETE FROM `{$dbA}`.products WHERE sku LIKE 'WATEST-%'");
$pdo->exec("DELETE FROM `{$dbA}`.clients WHERE client_name = 'WATEST cliente'");
foreach (['log.txt', 'recibidos.txt', 'recibidos_ecf.json'] as $archivo) {
    @unlink($mock . '/' . $archivo);
}
$restaurar();
$pdo->exec("INSERT IGNORE INTO `{$master}`.unidades_medida (id, codigo, descripcion, permite_decimales) VALUES (43, 'UND', 'Unidad', 0)");
$almacen = (int) $pdo->query("SELECT id FROM `{$dbA}`.warehouses ORDER BY id LIMIT 1")->fetchColumn();
$pdo->prepare("INSERT INTO `{$dbA}`.products (sku, nombre, warehouse_id, indicador_facturacion, indicador_bien_servicio, precio, unidad_medida, stock, activo)
               VALUES ('WATEST-1', 'Agua 500 ml', ?, 1, 1, '21.1864', '43', '100', 1)")->execute([$almacen]);
$agua = (int) $pdo->lastInsertId(); // 25.00 con ITBIS
$pdo->prepare("INSERT INTO `{$dbA}`.clients (email, client_name, company_name, phone_number, rnc, razon_social, descuento)
               VALUES ('', 'WATEST cliente', 'WATEST cliente', '', '101010101', 'WATEST CLIENTE SRL', 0)")->execute();
$cliente = (int) $pdo->lastInsertId();

// --- Ayudas ---------------------------------------------------------------------------------------
$stock = fn(int $id) => (float) $pdo->query("SELECT stock FROM `{$dbA}`.products WHERE id = {$id}")->fetchColumn();
$filaPorClave = function (string $clave) use ($pdo, $dbA) {
    $st = $pdo->prepare("SELECT id, e_ncf, no_factura, tipo_ecf, estado_dgii, envio_pendiente, turno_id, pos_empleado_id, respuesta_dgii
                         FROM `{$dbA}`.facturas WHERE pos_idempotency_key = ?");
    $st->execute([$clave]);
    return $st->fetch();
};
$movCaja = fn(int $id) => (int) $pdo->query("SELECT COUNT(*) FROM `{$dbA}`.pos_caja_movimientos WHERE factura_id = {$id}")->fetchColumn();
$movInv = fn(int $id) => (int) $pdo->query("SELECT COUNT(*) FROM `{$dbA}`.inventory_movements WHERE referencia_tipo = 'factura' AND referencia_id = {$id}")->fetchColumn();
$contador = fn(string $tipo) => (int) $pdo->query("SELECT current_value FROM `{$dbA}`.ncf_sequences WHERE type = '{$tipo}' AND ambiente = 'testecf' AND numero_desde = 1")->fetchColumn();
$encfDe = fn(int $n) => 'E32' . str_pad((string) $n, 10, '0', STR_PAD_LEFT);
$venta = fn(array $lineas, int $total, int $forma = 1, ?int $recibido = null, ?string $clave = null, array $extra = []) => [
    'clave' => $clave ?? $uuid(), 'lineas' => $lineas, 'total_centavos' => $total, 'forma_pago' => $forma,
    'recibido_centavos' => $recibido, 'ancho' => 80, 'iniciada_ms' => (int) (microtime(true) * 1000) - 20000,
] + $extra;
$aguas = fn(int $n) => [['product_id' => $agua, 'cantidad' => $n]];

// --- Caja, empleado, equipo, sesion y turno ---------------------------------------------------------
[, $r] = $api('POST', '/auth/login', ['emailOrUsername' => getenv('POS_TEST_ADMIN_A'), 'password' => getenv('POS_TEST_PASS')]);
$admin = ['Authorization' => 'Bearer ' . ($r['data']['token'] ?? '')];
[, $r] = $api('POST', '/pos-admin/cajas', ['nombre' => 'Caja WA'], $admin);
$caja = $r['data']['caja']['id'];
[, $r] = $api('POST', '/pos-admin/empleados', ['nombre' => 'Ana WA', 'rol' => 'cajero'], $admin);
$pin = $r['data']['pin'];
[, $r] = $api('POST', '/pos-admin/equipos', ['caja_id' => $caja], $admin);
$eq = ['X-POS-EQUIPO' => $r['data']['token']];
[, $r] = $api('POST', '/pos/sesion', ['pin' => $pin], $eq);
$ses = $eq + ['X-POS-SESION' => (string) ($r['data']['token'] ?? '')];
[, $r] = $api('POST', '/pos/turno', ['fondo_centavos' => 0], $ses);
$turno = (int) ($r['data']['turno_caja']['id'] ?? 0);
wa_chk('preparacion: login del admin, caja, equipo, sesion y turno abierto', isset($admin['Authorization']) && $turno > 0 && $ses['X-POS-SESION'] !== '', $r);

// --- Peticiones en vuelo a la vez (curl_multi) -------------------------------------------------------
$mh = null;
$iniciar = function (string $baseUrl, array $headers, array $body) use (&$mh): CurlHandle {
    $mh ??= curl_multi_init();
    $h = ['Content-Type: application/json'];
    foreach ($headers as $k => $v) {
        $h[] = "{$k}: {$v}";
    }
    $ch = curl_init($baseUrl . '/pos/ventas');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_HTTPHEADER => $h,
        CURLOPT_POSTFIELDS => json_encode($body)]);
    curl_multi_add_handle($mh, $ch);
    return $ch;
};
/** Mueve las peticiones en vuelo hasta que $cond() sea cierta (o pasen $max segundos). */
$avanzar = function (callable $cond, int $max = 30) use (&$mh): bool {
    $corriendo = 0;
    $t0 = microtime(true);
    do {
        curl_multi_exec($mh, $corriendo);
        if ($cond()) {
            return true;
        }
        curl_multi_select($mh, 0.1);
    } while (microtime(true) - $t0 < $max);
    return (bool) $cond();
};
/** Espera a las que quedan y devuelve [http, json] de cada una. */
$terminar = function (array $handles) use (&$mh): array {
    $corriendo = 0;
    do {
        curl_multi_exec($mh, $corriendo);
        curl_multi_select($mh, 0.1);
    } while ($corriendo > 0);
    $out = [];
    foreach ($handles as $ch) {
        $out[] = [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), json_decode((string) curl_multi_getcontent($ch), true)];
        curl_multi_remove_handle($mh, $ch);
    }
    curl_multi_close($mh);
    $mh = null;
    return $out;
};

// ---------------------------------------------------------------------------------------------------
echo "1. RF1: la misma clave mientras la primera espera a la DGII\n";
$modo('lento'); // el simulador la recibe y contesta a los 8 s; la API 2 espera hasta 30 s
$clave1 = $uuid();
$cuerpo1 = $venta($aguas(3), 7500, 1, 10000, $clave1);
$stock1 = $stock($agua);
$a = $iniciar($base2, $ses, $cuerpo1);
$guardada = $avanzar(static fn() => $filaPorClave($clave1) !== false);
$enVuelo = $filaPorClave($clave1);
wa_chk('write-ahead: la fila ya esta guardada, PENDIENTE_ENVIO, mientras la DGII no ha contestado',
    $guardada && $enVuelo && $enVuelo['estado_dgii'] === 'PENDIENTE_ENVIO' && $enVuelo['tipo_ecf'] === '32', $enVuelo);
$b = $iniciar($base1, $ses, $cuerpo1); // misma clave, otra instancia de la API
[$ra, $rb] = $terminar([$a, $b]);
$encf1 = (string) ($ra[1]['data']['venta']['e_ncf'] ?? '');
$fila1 = $filaPorClave($clave1);
wa_chk('las dos responden 201 con la MISMA venta (la segunda, repetida); un solo e-NCF, recibido una vez, aceptado',
    $ra[0] === 201 && $rb[0] === 201 && ($ra[1]['data']['repetida'] ?? null) === false && ($rb[1]['data']['repetida'] ?? null) === true
    && $encf1 !== '' && $encf1 === ($rb[1]['data']['venta']['e_ncf'] ?? null) && $recepciones($encf1) === 1
    && $fila1['estado_dgii'] === 'RFCE_ACEPTADO' && (int) $fila1['envio_pendiente'] === 0, [$ra, $rb]);
wa_chk('un solo movimiento de caja (efectivo recibido 100.00)', $movCaja((int) $fila1['id']) === 1
    && (float) $pdo->query("SELECT monto_recibido FROM `{$dbA}`.pos_caja_movimientos WHERE factura_id = {$fila1['id']}")->fetchColumn() === 100.0);
wa_chk('una sola salida de inventario: 3 aguas', $movInv((int) $fila1['id']) === 1 && $stock($agua) === $stock1 - 3, [$stock1, $stock($agua)]);

// ---------------------------------------------------------------------------------------------------
echo "\n2. RF2: el servidor cae entre guardar la respuesta y la caja\n";
$modo('acepta');
$clave2 = $uuid();
$cuerpo2 = $venta($aguas(2), 5000, 1, 6000, $clave2);
[$h, $r] = $api('POST', '/pos/ventas', $cuerpo2, $ses);
$fila2 = $filaPorClave($clave2);
wa_chk('la venta se cobra normal: 201, caja e inventario', $h === 201 && $fila2 && $movCaja((int) $fila2['id']) === 1 && $movInv((int) $fila2['id']) === 1, [$h, $r]);
// El estado que deja un corte entre 'confirmar' y la entrega: veredicto en la fila, sin caja ni inventario.
$pdo->exec("DELETE FROM `{$dbA}`.pos_caja_movimientos WHERE factura_id = {$fila2['id']}");
$pdo->exec("DELETE FROM `{$dbA}`.inventory_movements WHERE referencia_tipo = 'factura' AND referencia_id = {$fila2['id']}");
$pdo->exec("UPDATE `{$dbA}`.products SET stock = stock + 2 WHERE id = {$agua}");
$stock2 = $stock($agua);
[$h, $r] = $api('POST', '/pos/ventas', $cuerpo2, $ses);
$mov = $pdo->query("SELECT monto, monto_recibido, devuelta FROM `{$dbA}`.pos_caja_movimientos WHERE factura_id = {$fila2['id']}")->fetchAll();
wa_chk('el reintento con la misma clave: 201 repetida, sin otro e-NCF ni otro envio',
    $h === 201 && ($r['data']['repetida'] ?? null) === true && ($r['data']['venta']['e_ncf'] ?? '') === $fila2['e_ncf'] && $recepciones((string) $fila2['e_ncf']) === 1, [$h, $r]);
wa_chk('escribio la caja que faltaba, una vez y con lo del reintento (recibido 60.00, devuelta 10.00), y devolvio la salida de inventario, una vez',
    count($mov) === 1 && (float) $mov[0]['monto_recibido'] === 60.0 && (float) $mov[0]['devuelta'] === 10.0
    && $movInv((int) $fila2['id']) === 1 && $stock($agua) === $stock2 - 2, [$mov, $stock2, $stock($agua)]);
[$h] = $api('POST', '/pos/ventas', $cuerpo2, $ses);
wa_chk('otro reintento no repite nada', $h === 201 && $movCaja((int) $fila2['id']) === 1 && $movInv((int) $fila2['id']) === 1 && $stock($agua) === $stock2 - 2, [$h]);

// ---------------------------------------------------------------------------------------------------
echo "\n3. RF3: el reenvio automatico no toca una venta que todavia se esta enviando\n";
$modo('lento');
$clave3 = $uuid();
$a = $iniciar($base2, $ses, $venta($aguas(1), 2500, 3, null, $clave3));
$avanzar(static fn() => $filaPorClave($clave3) !== false);
[$h, $r] = $api('POST', '/pos/pendientes/reenviar', [], $eq); // API 1, mientras la API 2 sigue esperando a la DGII
wa_chk('el reenvio la salta (PENDIENTE_ENVIO con menos de 2 minutos): no acepta ni rechaza nada y la fila sigue PENDIENTE_ENVIO',
    $h === 200 && ($r['data']['aceptadas'] ?? -1) === 0 && ($r['data']['rechazadas'] ?? null) === []
    && $filaPorClave($clave3)['estado_dgii'] === 'PENDIENTE_ENVIO', [$h, $r, $filaPorClave($clave3)]);
[$rc] = $terminar([$a]);
$encf3 = (string) ($rc[1]['data']['venta']['e_ncf'] ?? '');
wa_chk('la venta original termina aceptada; la DGII la recibio una sola vez y nadie la consulto',
    $rc[0] === 201 && $filaPorClave($clave3)['estado_dgii'] === 'RFCE_ACEPTADO' && $encf3 !== '' && $recepciones($encf3) === 1 && $consultas($encf3) === 0, [$rc, $recepciones($encf3)]);
$modo('acepta');

// ---------------------------------------------------------------------------------------------------
echo "\n4. RF4: el certificado falla a mitad del turno y se recupera\n";
$cont4 = $contador('E32');
$recibidos4 = count($logMock());
$pdo->exec("UPDATE `{$master}`.tenants SET cert_path = 'no_existe_wa_cert.p12' WHERE id = {$tenantA}");
$claveS1 = $uuid();
$cuerpoS1 = $venta($aguas(2), 5000, 1, 5000, $claveS1);
$stock4 = $stock($agua);
[$h, $r] = $api('POST', '/pos/ventas', $cuerpoS1, $ses);
$filaS1 = $filaPorClave($claveS1);
wa_chk('con el certificado roto la venta sale igual: 201 con recibo', $h === 201 && is_array($r['data']['recibo'] ?? null) && $filaS1, [$h, $r]);
wa_chk('quedo como factura simple (sin tipo de e-CF ni e-NCF) con su clave, turno y empleado, caja e inventario, sin gastar numero ni salir a la DGII',
    $filaS1 && $filaS1['tipo_ecf'] === null && $filaS1['e_ncf'] === null && (int) $filaS1['turno_id'] === $turno && (int) $filaS1['pos_empleado_id'] > 0
    && (int) $filaS1['envio_pendiente'] === 0 && $movCaja((int) $filaS1['id']) === 1 && $movInv((int) $filaS1['id']) === 1 && $stock($agua) === $stock4 - 2
    && $contador('E32') === $cont4 && count($logMock()) === $recibidos4, [$filaS1, $cont4, $contador('E32')]);
$recibo = $r['data']['recibo'] ?? [];
wa_chk('el recibo sale en formato de factura simple: titulo FACTURA y sin timbre', ($recibo['titulo'] ?? '') === 'FACTURA'
    && array_key_exists('timbre', $recibo) && $recibo['timbre'] === null, $recibo['titulo'] ?? $r);
[$h, $r] = $api('POST', '/pos/ventas', $cuerpoS1, $ses);
wa_chk('repetir la clave: la misma factura simple, sin otra ni otro movimiento', $h === 201 && ($r['data']['repetida'] ?? null) === true
    && (int) $pdo->query("SELECT COUNT(*) FROM `{$dbA}`.facturas WHERE pos_idempotency_key = " . $pdo->quote($claveS1))->fetchColumn() === 1
    && $movCaja((int) $filaS1['id']) === 1, [$h, $r]);
$claveS2 = $uuid();
[$h, $r] = $api('POST', '/pos/ventas', $venta($aguas(1), 2500, 2, null, $claveS2), $ses);
$filaS2 = $filaPorClave($claveS2);
$pdo->exec("UPDATE `{$dbA}`.facturas SET date = DATE_SUB(date, INTERVAL 1 DAY) WHERE id = {$filaS1['id']}"); // la primera "es de ayer"
[$ha, $ra] = $api('GET', '/facturas/avisos', null, $admin);
wa_chk('otra venta con el certificado roto tambien es factura simple, y el aviso al admin cuenta solo las de hoy (1 de 2)',
    $h === 201 && $filaS2 && $filaS2['tipo_ecf'] === null && $ha === 200 && ($ra['data']['certificado_fallo']['ventas_hoy'] ?? -1) === 1, [$h, $ha, $ra]);
$pdo->exec("UPDATE `{$master}`.tenants SET cert_path = NULL WHERE id = {$tenantA}"); // el certificado se recupera
$claveE = $uuid();
[$h, $r] = $api('POST', '/pos/ventas', $venta($aguas(1), 2500, 1, 2500, $claveE), $ses);
$filaE = $filaPorClave($claveE);
[$ha, $ra] = $api('GET', '/facturas/avisos', null, $admin);
wa_chk('recuperado el certificado la venta vuelve a ser e-CF (E32 aceptada) y el aviso del dia no se borra',
    $h === 201 && $filaE && preg_match('/^E32\d{10}$/', (string) $filaE['e_ncf']) === 1 && $filaE['estado_dgii'] === 'RFCE_ACEPTADO'
    && ($ra['data']['certificado_fallo']['ventas_hoy'] ?? -1) === 1, [$h, $filaE, $ra]);

// ---------------------------------------------------------------------------------------------------
echo "\n5. RF5: E32 agotada con E31 vigente, y su salida al registrar un rango que las cubre\n";
$ultimo = $contador('E32'); // H: el ultimo E32 que dio el rango
$pdo->exec("UPDATE `{$dbA}`.ncf_sequences SET numero_hasta = current_value WHERE type = 'E32' AND ambiente = 'testecf' AND numero_desde = 1");
$claveR1 = $uuid();
[$h, $r] = $api('POST', '/pos/ventas', $venta($aguas(2), 5000, 1, 5000, $claveR1), $ses);
$filaR1 = $filaPorClave($claveR1);
$encfR1 = (string) ($r['data']['venta']['e_ncf'] ?? '');
$metaR1 = json_decode((string) ($filaR1['respuesta_dgii'] ?? ''), true);
wa_chk('E32 agotada: la venta usa el numero siguiente (H+1), queda PENDIENTE_ENVIO con envio pendiente, marcada sin_rango, y no se envia',
    $h === 201 && $encfR1 === $encfDe($ultimo + 1) && $filaR1['estado_dgii'] === 'PENDIENTE_ENVIO' && (int) $filaR1['envio_pendiente'] === 1
    && ($metaR1['sin_rango'] ?? false) === true && $recepciones($encfR1) === 0, [$h, $encfR1, $filaR1]);
$claveR2 = $uuid();
[$h, $r] = $api('POST', '/pos/ventas', $venta($aguas(1), 2500, 1, 2500, $claveR2), $ses);
$encfR2 = (string) ($r['data']['venta']['e_ncf'] ?? '');
wa_chk('la segunda toma H+2 y tampoco se envia', $h === 201 && $encfR2 === $encfDe($ultimo + 2) && $recepciones($encfR2) === 0, [$h, $encfR2]);
$claveC = $uuid();
[$h, $r] = $api('POST', '/pos/ventas', $venta($aguas(1), 2500, 1, 2500, $claveC, ['tipo_ecf' => '31', 'client_id' => $cliente]), $ses);
$encfC = (string) ($r['data']['venta']['e_ncf'] ?? '');
wa_chk('E31 sigue con su rango: sale a la DGII como siempre y no es sin_rango', $h === 201 && preg_match('/^E31\d{10}$/', $encfC) === 1 && $recepciones($encfC) === 1
    && !isset((json_decode((string) ($filaPorClave($claveC)['respuesta_dgii'] ?? ''), true) ?? [])['sin_rango']), [$h, $encfC]);
[$h, $r] = $api('GET', '/facturas/avisos', null, $admin);
$sec32 = array_values(array_filter($r['data']['secuencias'] ?? [], static fn($s) => str_ends_with((string) ($s['tipo'] ?? ''), '32')));
wa_chk('aviso al admin: la secuencia E32 esta agotada y hay 2 ventas esperando un rango',
    $h === 200 && $sec32 !== [] && $sec32[0]['estado'] === 'agotada' && ($r['data']['sin_rango']['ventas'] ?? -1) === 2, [$h, $r['data'] ?? $r]);
[$h, $r] = $api('POST', '/pos/pendientes/reenviar', [], $eq);
wa_chk('mientras ningun rango registrado las cubra, el reenvio las salta: siguen sin enviarse ni consultarse',
    $h === 200 && $recepciones($encfR1) === 0 && $consultas($encfR1) === 0 && $filaPorClave($claveR1)['estado_dgii'] === 'PENDIENTE_ENVIO', [$h, $r]);
// La DGII autoriza un rango que incluye esos numeros y el admin lo registra por POST /api/ncf/rangos: un rango que
// empieza dentro de lo ya usado sin rango se acepta (ncfModel::registerRange).
[$hr, $rr] = $api('POST', '/ncf/rangos', ['type' => 'E32', 'numero_desde' => $ultimo + 1, 'numero_hasta' => $ultimo + 100,
    'fecha_vencimiento' => date('Y-m-d', strtotime('+1 year'))], $admin);
// Pasaron 10 minutos desde que se guardaron: ya no cuentan como "todavia se estan enviando" (RF3). La hora la pone PHP,
// como la puso la API (date('Y-m-d H:i:s')): no la de MySQL, que puede estar en otra zona horaria.
$pdo->prepare('UPDATE `' . $dbA . '`.facturas SET respuesta_dgii = JSON_SET(respuesta_dgii, \'$.desde\', ?) WHERE id IN (?, ?)')
    ->execute([date('Y-m-d H:i:s', time() - 600), (int) $filaR1['id'], (int) $filaPorClave($claveR2)['id']]);
[$h, $r] = $api('POST', '/pos/pendientes/reenviar', [], $eq);
$filaR1c = $filaPorClave($claveR1);
wa_chk('el admin registra el rango por POST /api/ncf/rangos (200) y con el el reenvio las manda: la DGII las recibe una vez y quedan aceptadas',
    $hr === 200 && ($rr['status'] ?? false) === true
    && $h === 200 && $recepciones($encfR1) === 1 && $recepciones($encfR2) === 1 && $filaR1c['estado_dgii'] === 'RFCE_ACEPTADO'
    && $filaPorClave($claveR2)['estado_dgii'] === 'RFCE_ACEPTADO' && (int) $filaR1c['envio_pendiente'] === 0, [$hr, $rr, $h, $r, $filaR1c]);
[$h, $r] = $api('GET', '/facturas/avisos', null, $admin);
[$h2, $r2] = $api('POST', '/pos/ventas', $venta($aguas(1), 2500, 1, 2500), $ses);
$encfSig = (string) ($r2['data']['venta']['e_ncf'] ?? '');
wa_chk('el aviso de ventas esperando un rango se apaga y la venta siguiente no repite un numero usado: salta H+1 y H+2 y toma H+3',
    $h === 200 && ($r['data']['sin_rango']['ventas'] ?? -1) === 0 && $h2 === 201 && $encfSig === $encfDe($ultimo + 3), [$r['data'] ?? $r, $encfSig]);
$restaurar();

// ---------------------------------------------------------------------------------------------------
echo "\n6. «Reenviar a la DGII» sobre una venta rechazada del POS (spec 4.4)\n";
$modo('rechaza');
$claveX = $uuid();
$cuerpoX = $venta($aguas(2), 5000, 1, 5000, $claveX);
[$h, $r] = $api('POST', '/pos/ventas', $cuerpoX, $ses);
$encfX = (string) ($r['data']['venta']['e_ncf'] ?? '');
$idX = (int) ($r['data']['venta']['factura_id'] ?? 0);
wa_chk('la venta rechazada se entrego: 201, RFCE_RECHAZADO', $h === 201 && $encfX !== '' && ($r['data']['venta']['estado_dgii'] ?? '') === 'RFCE_RECHAZADO', [$h, $r]);
$modo('acepta');
$contX = $contador('E32');
$stockX = $stock($agua);
[$h, $r] = $api('POST', "/facturas/{$idX}/reenviar", [], $admin);
$filasX = $pdo->query("SELECT id, estado_dgii FROM `{$dbA}`.facturas WHERE e_ncf = " . $pdo->quote($encfX) . ' OR no_factura = ' . $pdo->quote($encfX) . ' ORDER BY id')->fetchAll();
wa_chk('reenviar emite con el mismo numero (200/201); la rechazada queda archivada y la nueva aceptada',
    in_array($h, [200, 201], true) && ($r['data']['e_ncf'] ?? '') === $encfX && count($filasX) === 2
    && $filasX[0]['estado_dgii'] === 'RFCE_RECHAZADO_ARCHIVADO' && $filasX[1]['estado_dgii'] === 'RFCE_ACEPTADO', [$h, $r, $filasX]);
$contY = $contador('E32');
$recepY = $recepciones($encfX);
$stockY = $stock($agua);
[$hx, $rx] = $api('POST', '/pos/ventas', $cuerpoX, $ses); // el cajero reintenta la venta original (la misma clave)
wa_chk('el numero no toca el contador, la DGII lo recibio dos veces (rechazo y reemision) y el inventario sale una sola vez; repetir la clave original: 201 repetida, la misma venta, sin otro envio ni otro movimiento',
    $contY === $contX && $recepY === 2 && $stockY === $stockX
    && $hx === 201 && ($rx['data']['repetida'] ?? null) === true && (int) ($rx['data']['venta']['factura_id'] ?? 0) === $idX
    && ($rx['data']['venta']['e_ncf'] ?? '') === $encfX && $movCaja($idX) === 1
    && $contador('E32') === $contX && $recepciones($encfX) === 2 && $stock($agua) === $stockX
    && (int) $pdo->query("SELECT COUNT(*) FROM `{$dbA}`.facturas WHERE pos_idempotency_key = " . $pdo->quote($claveX))->fetchColumn() === 1,
    [$contX, $contY, $recepY, $stockX, $stockY, $hx, $rx, $contador('E32'), $recepciones($encfX), $stock($agua)]);

// ---------------------------------------------------------------------------------------------------
echo "\n7. El cierre cuenta las facturas simples y las lista con su numero\n";
// En el turno hay 11 ventas, todas con su movimiento de caja: E32 x8 (la rechazada y reenviada se cuenta una vez, por su fila
// archivada, que es la que tiene el cobro; la reemitida no tiene turno), E31 x1 y facturas simples x2. En efectivo 375.00.
[$h, $r] = $api('GET', '/pos/ventas', null, $ses);
$lista = $r['data']['ventas'] ?? [];
$simple = array_values(array_filter($lista, static fn($v) => (int) $v['factura_id'] === (int) $filaS2['id']))[0] ?? null;
$rechazadaX = array_values(array_filter($lista, static fn($v) => (int) $v['factura_id'] === $idX))[0] ?? null;
$noFactura = (string) $pdo->query("SELECT no_factura FROM `{$dbA}`.facturas WHERE id = {$filaS2['id']}")->fetchColumn();
wa_chk('ventas del turno: 11, ninguna sin numero; la factura simple sale con el de su factura y la rechazada ya reenviada con su e-NCF',
    $h === 200 && count($lista) === 11 && count(array_filter($lista, static fn($v) => trim((string) ($v['e_ncf'] ?? '')) === '')) === 0
    && $simple !== null && $noFactura !== '' && $simple['e_ncf'] === $noFactura
    && $rechazadaX !== null && $rechazadaX['e_ncf'] === $encfX, [$h, count($lista), $simple, $rechazadaX]);
$efectivo = (float) $pdo->query("SELECT COALESCE(SUM(monto), 0) FROM `{$dbA}`.pos_caja_movimientos WHERE turno_id = {$turno} AND tipo = 'VENTA' AND forma_pago = 1")->fetchColumn();
[$h, $r] = $api('POST', '/pos/turno/cerrar', ['turno_id' => $turno, 'conteo' => ['1000' => 1, 'otros_centavos' => 0]], $ses);
$rep = $r['data']['reporte'] ?? [];
wa_chk('el reporte cuenta 11 ventas (425.00): E31 1, E32 8, "Simple" 2 sin una clave "E" vacia; ninguna pendiente y ninguna rechazada, porque la reenviada quedo archivada',
    $h === 200 && ($rep['comprobantes'] ?? null) === ['E31' => 1, 'E32' => 8, 'Simple' => 2]
    && ($rep['ventas']['cantidad'] ?? 0) === 11 && ($rep['ventas']['total_centavos'] ?? 0) === 42500
    && ($rep['pendientes'] ?? null) === [] && ($rep['rechazadas'] ?? null) === [], [$h, $rep['comprobantes'] ?? $r, $rep['ventas'] ?? null, $rep['pendientes'] ?? null, $rep['rechazadas'] ?? null]);
wa_chk('el esperado suma el efectivo de todas las ventas (375.00), tambien el de las facturas simples y el de la rechazada reenviada',
    ($rep['esperado_centavos'] ?? -1) === 37500 && ($rep['esperado_centavos'] ?? -1) === (int) round($efectivo * 100), [$rep['esperado_centavos'] ?? null, $efectivo]);

$restaurar();
$api('POST', '/auth/signout', [], $admin);
wa_fin();
