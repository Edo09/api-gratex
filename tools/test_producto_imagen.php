<?php
/**
 * test_producto_imagen.php — prueba de integracion de la foto del producto
 * (migracion 032, POST/DELETE /api/products/imagen, catalogo del POS).
 *
 * Contra el API LOCAL (mismo entorno que tools/test_pos_backend.php: MySQL 8 en
 * Docker, master + tenant de prueba con la 032 aplicada). No emite nada.
 *
 * Uso:  php tools/test_producto_imagen.php      (sale con 1 si algo falla)
 *   POS_TEST_BASE (por defecto http://127.0.0.1:8099/api), POS_TEST_ADMIN_A,
 *   POS_TEST_PASS y MASTER_DB_HOST/PORT/USER/PASS/NAME.
 * Nunca contra produccion: escribe y borra archivos en public/uploads/productos/.
 */

$base = rtrim(getenv('POS_TEST_BASE') ?: 'http://127.0.0.1:8099/api', '/');
foreach (['POS_TEST_ADMIN_A', 'POS_TEST_PASS', 'MASTER_DB_HOST'] as $var) {
    if ((string) getenv($var) === '') {
        fwrite(STDERR, "Falta la variable {$var}. Ver la cabecera de tools/test_pos_venta.php.\n");
        exit(2);
    }
}
if (!preg_match('#^http://(127\.0\.0\.1|localhost)(:\d+)?/#', $base . '/')) {
    fwrite(STDERR, "POS_TEST_BASE no es local. Esta prueba escribe y borra archivos: solo local.\n");
    exit(2);
}
$raiz = dirname(__DIR__);

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
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 30]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode((string) $raw, true);
    return [$http, is_array($json) ? $json : ['_crudo' => substr((string) $raw, 0, 400)]];
};
/** POST multipart: campos + (opcional) el archivo "imagen" con ese contenido. */
$subir = function (array $campos, ?string $contenido, string $nombre, array $headers) use ($base): array {
    $tmp = null;
    if ($contenido !== null) {
        $tmp = tempnam(sys_get_temp_dir(), 'img');
        file_put_contents($tmp, $contenido);
        $campos['imagen'] = new CURLFile($tmp, 'image/png', $nombre);
    }
    $ch = curl_init($base . '/products/imagen');
    $h = [];
    foreach ($headers as $k => $v) {
        $h[] = "{$k}: {$v}";
    }
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $campos, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 30]);
    $raw = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($tmp !== null) {
        @unlink($tmp);
    }
    $json = json_decode((string) $raw, true);
    return [$http, is_array($json) ? $json : ['_crudo' => substr((string) $raw, 0, 400)]];
};

// PNG valido de W x H (solo cabecera + IEND: getimagesize y finfo lo leen igual).
$png = function (int $w, int $h): string {
    $chunk = fn(string $tipo, string $datos) => pack('N', strlen($datos)) . $tipo . $datos . pack('N', crc32($tipo . $datos));
    return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0)) . $chunk('IDAT', gzcompress(str_repeat("\0" . str_repeat("\xff\x00\x00", min($w, 4)), min($h, 4)))) . $chunk('IEND', '');
};
$jpg = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==');

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
$viejas = $pdo->query("SELECT imagen_path FROM `{$dbA}`.products WHERE sku LIKE 'IMGTEST-%' AND imagen_path IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
foreach ($viejas as $v) {
    @unlink($raiz . '/' . $v);
}
$pdo->exec("DELETE FROM `{$dbA}`.products WHERE sku LIKE 'IMGTEST-%'");
$almacen = (int) $pdo->query("SELECT id FROM `{$dbA}`.warehouses ORDER BY id LIMIT 1")->fetchColumn();
$producto = function (string $sku, string $nombre) use ($pdo, $dbA, $almacen): int {
    $pdo->prepare("INSERT INTO `{$dbA}`.products (sku, nombre, warehouse_id, indicador_facturacion, indicador_bien_servicio, precio, unidad_medida, stock, activo)
                   VALUES (?, ?, ?, 1, 1, 21.1864, '43', 10, 1)")->execute([$sku, $nombre, $almacen]);
    return (int) $pdo->lastInsertId();
};
$imagenDe = fn(int $id) => $pdo->query("SELECT imagen_path FROM `{$dbA}`.products WHERE id = {$id}")->fetchColumn();
$existe = fn(?string $ruta) => $ruta !== null && $ruta !== '' && is_file($raiz . '/' . $ruta);

$inicioAudit = (int) $pdo->query("SELECT COALESCE(MAX(id), 0) FROM `{$master}`.audit_logs")->fetchColumn();
[, $r] = $api('POST', '/auth/login', ['emailOrUsername' => getenv('POS_TEST_ADMIN_A'), 'password' => getenv('POS_TEST_PASS')]);
$admin = ['Authorization' => 'Bearer ' . ($r['data']['token'] ?? '')];
$agua = $producto('IMGTEST-1', 'Agua foto');
$otro = $producto('IMGTEST-2', 'Arroz foto');

// ---------------------------------------------------------------------------
echo "\n1. Subir la foto\n";
[$h, $r] = $subir(['id' => (string) $agua], $png(800, 600), 'agua.png', $admin);
$ruta1 = (string) ($r['data']['imagen_path'] ?? '');
$chk('PNG 800x600: 200 con la ruta en public/uploads/productos/<tenant>/<aleatorio>.png', $h === 200
    && preg_match('#^public/uploads/productos/' . $tenantA . '/[0-9a-f]{32}\.png$#', $ruta1) === 1, [$h, $r]);
$chk('el archivo esta en disco y la fila lo apunta', $existe($ruta1) && $imagenDe($agua) === $ruta1, [$ruta1, $imagenDe($agua)]);
[$h, $r] = $api('GET', '/products?id=' . $agua, null, $admin);
$chk('GET del producto trae imagen_path', $h === 200 && ($r['data']['imagen_path'] ?? null) === $ruta1, [$h, $r]);

[$h, $r] = $subir(['id' => (string) $agua], $jpg, 'cambio.jpg', $admin);
$ruta2 = (string) ($r['data']['imagen_path'] ?? '');
$chk('cambiarla por un JPG: ruta nueva (.jpg), la vieja se borra del disco', $h === 200 && str_ends_with($ruta2, '.jpg')
    && $existe($ruta2) && !$existe($ruta1) && $imagenDe($agua) === $ruta2, [$h, $r]);

// ---------------------------------------------------------------------------
echo "\n2. Lo que no se acepta (y no cambia nada)\n";
foreach ([
    ['texto con nombre .png: 422', 'hola, no soy una imagen', 'foto.png', 422],
    ['SVG (puede llevar scripts): 422', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>', 'foto.svg', 422],
    ['GIF: 422', base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'), 'foto.gif', 422],
    ['PHP disfrazado de imagen: 422', "<?php echo 'x'; ?>", 'foto.php', 422],
    ['PNG de 5000 px de lado: 422', $png(5000, 100), 'grande.png', 422],
] as [$desc, $contenido, $nombre, $http]) {
    [$h, $r] = $subir(['id' => (string) $agua], $contenido, $nombre, $admin);
    $chk($desc, $h === $http && ($r['status'] ?? true) === false && $imagenDe($agua) === $ruta2 && $existe($ruta2), [$h, $r]);
}
[$h, $r] = $subir(['id' => (string) $agua], null, '', $admin);
$chk('sin archivo: 400 "Elige una imagen"', $h === 400 && str_contains((string) ($r['error'] ?? ''), 'Elige una imagen'), [$h, $r]);
[$h, $r] = $subir([], $png(10, 10), 'x.png', $admin);
$chk('sin id: 422', $h === 422, [$h, $r]);
$archivos = fn() => count(glob($raiz . '/public/uploads/productos/' . $tenantA . '/*') ?: []);
$antes = $archivos();
[$h, $r] = $subir(['id' => '999999'], $png(10, 10), 'x.png', $admin);
$chk('producto que no existe: 404, sin archivo huerfano', $h === 404 && $archivos() === $antes, [$h, $r, $antes, $archivos()]);
[$h, $r] = $subir(['id' => (string) $agua], $png(10, 10), 'x.png', []);
$chk('sin sesion: 401', $h === 401, [$h, $r]);
[$h, $r] = $api('GET', '/products/imagen', null, $admin);
$chk('GET a /products/imagen: 405', $h === 405, [$h, $r]);

// ---------------------------------------------------------------------------
echo "\n3. El formulario no la pisa, el POS la ve\n";
$fila = $pdo->query("SELECT * FROM `{$dbA}`.products WHERE id = {$agua}")->fetch();
[$h, $r] = $api('PUT', '/products', ['id' => $agua, 'nombre' => 'Agua foto (editada)', 'sku' => 'IMGTEST-1', 'precio' => 21.1864,
    'indicador_facturacion' => 1, 'indicador_bien_servicio' => 1, 'unidad_medida' => '43', 'stock' => $fila['stock'],
    'warehouse_id' => $almacen, 'activo' => 1], $admin);
$chk('guardar el producto (PUT del formulario) conserva la foto', $h === 200 && $imagenDe($agua) === $ruta2, [$h, $r, $imagenDe($agua)]);

[, $r] = $api('POST', '/pos-admin/cajas', ['nombre' => 'Caja fotos ' . bin2hex(random_bytes(2))], $admin);
$caja = (int) ($r['data']['caja']['id'] ?? 0);
[, $r] = $api('POST', '/pos-admin/equipos', ['caja_id' => $caja, 'reemplazar' => true], $admin);
$eq = ['X-POS-EQUIPO' => (string) ($r['data']['token'] ?? '')];
[, $r] = $api('POST', '/pos-admin/empleados', ['nombre' => 'Cajero fotos ' . bin2hex(random_bytes(2)), 'rol' => 'cajero'], $admin);
$empleado = (int) ($r['data']['empleado']['id'] ?? 0);
[, $r] = $api('POST', '/pos/sesion', ['pin' => (string) ($r['data']['pin'] ?? '')], $eq);
$sesion = $eq + ['X-POS-SESION' => (string) ($r['data']['token'] ?? '')];
[$h, $r] = $api('GET', '/pos/catalogo', null, $sesion);
$porId = array_column($r['data']['productos'] ?? [], null, 'id');
$chk('catalogo del POS: la foto en "imagen"; sin foto, null', $h === 200 && ($porId[$agua]['imagen'] ?? '') === $ruta2
    && array_key_exists('imagen', $porId[$otro] ?? []) && $porId[$otro]['imagen'] === null, [$h, $porId[$agua] ?? null, $porId[$otro] ?? null]);

// ---------------------------------------------------------------------------
echo "\n4. Quitarla y borrar el producto\n";
[$h, $r] = $api('DELETE', '/products/imagen', ['id' => $agua], $admin);
$chk('DELETE: imagen_path null y el archivo fuera del disco', $h === 200 && array_key_exists('imagen_path', $r['data'] ?? [])
    && $r['data']['imagen_path'] === null && $imagenDe($agua) === null && !$existe($ruta2), [$h, $r]);
[$h, $r] = $api('DELETE', '/products/imagen', ['id' => $agua], $admin);
$chk('quitarla otra vez: 200, sin error', $h === 200 && $imagenDe($agua) === null, [$h, $r]);

[, $r] = $subir(['id' => (string) $otro], $png(64, 64), 'arroz.png', $admin);
$ruta3 = (string) ($r['data']['imagen_path'] ?? '');
[$h, $r] = $api('DELETE', '/products', ['id' => $otro], $admin);
$chk('borrar un producto con foto (sin movimientos) borra tambien el archivo', $h === 200 && $ruta3 !== '' && !$existe($ruta3), [$h, $r, $ruta3]);

// La bitacora vive en el master, con el tenant.
$acciones = $pdo->query("SELECT action FROM `{$master}`.audit_logs WHERE tenant_id = {$tenantA} AND module = 'products'
    AND entity_id = {$agua} AND action LIKE 'IMAGEN_%' AND id > {$inicioAudit} ORDER BY id")
    ->fetchAll(PDO::FETCH_COLUMN);
$chk('bitacora: IMAGEN_CAMBIADA x2 e IMAGEN_QUITADA x2', $acciones === ['IMAGEN_CAMBIADA', 'IMAGEN_CAMBIADA', 'IMAGEN_QUITADA', 'IMAGEN_QUITADA'], $acciones);

// --- Limpieza -----------------------------------------------------------------
$pdo->exec("DELETE FROM `{$dbA}`.products WHERE sku LIKE 'IMGTEST-%'");
$pdo->exec("DELETE FROM `{$dbA}`.pos_sesiones WHERE empleado_id = {$empleado}");
$pdo->exec("DELETE FROM `{$dbA}`.pos_empleados WHERE id = {$empleado}");
$pdo->exec("DELETE FROM `{$master}`.pos_equipos WHERE tenant_id = {$tenantA} AND caja_id = {$caja}");
$pdo->exec("DELETE FROM `{$dbA}`.pos_cajas WHERE id = {$caja}");

echo "\n" . ($fallos === 0 ? "TODO OK ({$total} verificaciones)" : "{$fallos} de {$total} FALLARON") . "\n";
exit($fallos === 0 ? 0 : 1);
