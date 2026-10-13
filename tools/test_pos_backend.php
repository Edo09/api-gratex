<?php
/**
 * test_pos_backend.php — prueba de integracion del backend del POS: traspaso de
 * sesion, administracion (cajas, empleados, equipos), PIN con bloqueo, sesiones
 * de empleado y aislamiento entre empresas. docs/specs/pos.md A1–A8, K1, S3.
 *
 * NO es una prueba sin base: corre contra un API vivo con dos tenants de prueba
 * con el POS activo, y lee la base para comprobar lo que el API no muestra
 * (que el PIN no se guarde en claro ni llegue a la bitacora). Nunca contra
 * produccion: crea, desactiva y revoca a proposito.
 *
 * Preparacion (MySQL 8 en Docker, ver docs/specs/pos.md §11):
 *   1. db/master_schema.sql en gratex_master; tools/create_tenant.php para dos
 *      tenants app (admin_pos_a, admin_pos_b) con la misma clave; un usuario
 *      rol 'user' (sin modulo pos) en el tenant A; pos_enabled = 1 en los dos.
 *   2. API local: php -d variables_order=EGPCS -S 127.0.0.1:8099 index.php
 *      con MULTI_TENANT_ENABLED, MASTER_DB_*, MASTER_ENCRYPTION_KEY y
 *      POS_PIN_PEPPER en el entorno.
 *
 * Variables: POS_TEST_BASE (default http://127.0.0.1:8099/api), POS_TEST_ADMIN_A,
 * POS_TEST_ADMIN_B, POS_TEST_USER_A, POS_TEST_PASS y MASTER_DB_* (la base).
 *
 * Uso:  php tools/test_pos_backend.php      (sale con 1 si algo falla)
 */

$base = rtrim(getenv('POS_TEST_BASE') ?: 'http://127.0.0.1:8099/api', '/');
foreach (['POS_TEST_ADMIN_A', 'POS_TEST_ADMIN_B', 'POS_TEST_USER_A', 'POS_TEST_PASS', 'MASTER_DB_HOST'] as $var) {
    if ((string) getenv($var) === '') {
        fwrite(STDERR, "Falta la variable {$var}. Ver la cabecera de este archivo.\n");
        exit(2);
    }
}
if (preg_match('#gratex\.net|fiscalpoint\.com\.do#', $base)) {
    fwrite(STDERR, "POS_TEST_BASE apunta a produccion. Esta prueba crea y revoca datos: solo local.\n");
    exit(2);
}

$fallos = 0;
$total = 0;
$chk = function (string $desc, bool $ok, $obtenido = null) use (&$fallos, &$total) {
    $total++;
    if (!$ok) {
        $fallos++;
    }
    printf("  [%s] %s\n", $ok ? 'OK  ' : 'FALLO', $desc);
    if (!$ok && $obtenido !== null) {
        echo '         obtenido: ' . substr(json_encode($obtenido, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 600) . "\n";
    }
};

/** @return array{0:int,1:array} [http, cuerpo JSON] */
$api = function (string $metodo, string $ruta, ?array $body = null, array $headers = []) use ($base): array {
    $ch = curl_init($base . $ruta);
    $h = ['Content-Type: application/json'];
    foreach ($headers as $k => $v) {
        $h[] = "{$k}: {$v}";
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $metodo,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $h,
        CURLOPT_TIMEOUT => 30,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode((string) $raw, true);
    return [$http, is_array($json) ? $json : ['_crudo' => substr((string) $raw, 0, 300)]];
};

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;charset=utf8mb4', getenv('MASTER_DB_HOST'), getenv('MASTER_DB_PORT') ?: '3306'),
    getenv('MASTER_DB_USER'), getenv('MASTER_DB_PASS'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$master = getenv('MASTER_DB_NAME') ?: 'gratex_master';
$dbDe = function (string $usuario) use ($pdo, $master): array {
    $f = $pdo->query("SELECT t.id, t.db_name FROM `{$master}`.users u JOIN `{$master}`.tenants t ON t.id = u.tenant_id WHERE u.username = " . $pdo->quote($usuario))->fetch();
    return [(int) $f['id'], $f['db_name']];
};
[$tenantA, $dbA] = $dbDe(getenv('POS_TEST_ADMIN_A'));
[$tenantB, $dbB] = $dbDe(getenv('POS_TEST_ADMIN_B'));
$posActivo = fn(int $t, int $v) => $pdo->exec("UPDATE `{$master}`.tenants SET pos_enabled = {$v} WHERE id = {$t}");
$posActivo($tenantA, 1);
$posActivo($tenantB, 1);

// Datos de corridas anteriores: cada corrida empieza limpia.
foreach ([$dbA, $dbB] as $db) {
    // Ventas del POS (tools/test_pos_venta.php): sus movimientos apuntan a los turnos.
    $pdo->exec("DELETE FROM `{$db}`.pos_caja_movimientos");
    $pdo->exec("DELETE FROM `{$db}`.facturas WHERE pos_empleado_id IS NOT NULL");
    $pdo->exec("DELETE FROM `{$db}`.pos_sesiones");
    $pdo->exec("DELETE FROM `{$db}`.pos_turnos");
    $pdo->exec("DELETE FROM `{$db}`.pos_empleados");
    $pdo->exec("DELETE FROM `{$db}`.pos_cajas");
}
$pdo->exec("DELETE FROM `{$dbA}`.products WHERE sku LIKE 'POSTEST-%'");
$pdo->exec("DELETE FROM `{$dbA}`.categories WHERE nombre LIKE 'POS Test %'");
$pdo->exec("DELETE FROM `{$master}`.pos_equipos WHERE tenant_id IN ({$tenantA}, {$tenantB})");
$pdo->exec("DELETE FROM `{$master}`.pos_handoff_codes WHERE tenant_id IN ({$tenantA}, {$tenantB})");
$inicioAudit = $pdo->query("SELECT COALESCE(MAX(id), 0) FROM `{$master}`.audit_logs")->fetchColumn();

// ---------------------------------------------------------------------------
echo "0. PosPin (sin red)\n";
require_once __DIR__ . '/../src/Pos/PosPin.php';
$chk('PIN de 4 digitos (decision 2026-10-08)', PosPin::DIGITOS === 4);
$generados = [];
for ($i = 0; $i < 3000; $i++) {
    $generados[] = PosPin::generar();
}
$chk('3,000 PIN generados: 4 digitos y ninguno obvio',
    !array_filter($generados, fn($p) => preg_match('/^\d{4}$/', $p) !== 1 || PosPin::esObvio($p)));
$chk('obvios: 0000, 7777, 1234, 4321, 6789', PosPin::esObvio('0000') && PosPin::esObvio('7777') && PosPin::esObvio('1234')
    && PosPin::esObvio('4321') && PosPin::esObvio('6789'));
$chk('no obvios: 1235, 0102, 9080', !PosPin::esObvio('1235') && !PosPin::esObvio('0102') && !PosPin::esObvio('9080'));
$chk('formato: "0123" si; 123, "123", "12345", "12a4" no', PosPin::formatoValido('0123') && !PosPin::formatoValido(123)
    && !PosPin::formatoValido('123') && !PosPin::formatoValido('12345') && !PosPin::formatoValido('12a4'));

$login = function (string $usuario) use ($api): string {
    [, $r] = $api('POST', '/auth/login', ['emailOrUsername' => $usuario, 'password' => getenv('POS_TEST_PASS')]);
    return (string) ($r['data']['token'] ?? '');
};
$bearer = fn(string $t) => ['Authorization' => "Bearer {$t}"];
$adminA = $login(getenv('POS_TEST_ADMIN_A'));
$adminB = $login(getenv('POS_TEST_ADMIN_B'));
$userA = $login(getenv('POS_TEST_USER_A'));
$chk('login de los tres usuarios de prueba', $adminA !== '' && $adminB !== '' && $userA !== '');

// ---------------------------------------------------------------------------
echo "\n1. Codigo de traspaso app.* -> pos.* (A2)\n";

[$h, $r] = $api('POST', '/auth/pos-handoff', [], $bearer($userA));
$chk('usuario sin modulo pos: 403 SIN_PERMISO', $h === 403 && ($r['codigo'] ?? '') === 'SIN_PERMISO', [$h, $r]);
[$h, $r] = $api('POST', '/auth/pos-handoff', []);
$chk('sin sesion: 401', $h === 401, [$h, $r]);

[$h, $r] = $api('POST', '/auth/pos-handoff', [], $bearer($adminA));
$codigo = (string) ($r['data']['code'] ?? '');
$chk('admin: codigo de 48 hex, URL con #code= y 60 s',
    $h === 200 && preg_match('/^[0-9a-f]{48}$/', $codigo) === 1
    && str_ends_with((string) ($r['data']['url'] ?? ''), '/#code=' . $codigo) && ($r['data']['expira_en'] ?? 0) === 60, [$h, $r]);
$chk('el codigo no se guarda en claro', (int) $pdo->query("SELECT COUNT(*) FROM `{$master}`.pos_handoff_codes WHERE code_hash = " . $pdo->quote($codigo))->fetchColumn() === 0
    && (int) $pdo->query("SELECT COUNT(*) FROM `{$master}`.pos_handoff_codes WHERE code_hash = " . $pdo->quote(hash('sha256', $codigo)))->fetchColumn() === 1);

[$h, $r] = $api('POST', '/auth/pos-handoff/canje', ['code' => $codigo]);
$adminPos = (string) ($r['data']['token'] ?? '');
$chk('canje: misma respuesta que el login (token + usuario con permisos)',
    $h === 200 && ($r['success'] ?? false) === true && $adminPos !== '' && in_array('*', $r['data']['user']['permissions'] ?? [], true), [$h, $r]);
[$h, $r] = $api('POST', '/auth/pos-handoff/canje', ['code' => $codigo]);
$chk('el mismo codigo otra vez: 401 CODIGO_INVALIDO (un solo uso)', $h === 401 && ($r['codigo'] ?? '') === 'CODIGO_INVALIDO', [$h, $r]);
[$h, $r] = $api('POST', '/auth/pos-handoff/canje', ['code' => 'nada']);
$chk('codigo basura: 401', $h === 401, [$h, $r]);

[, $r] = $api('POST', '/auth/pos-handoff', [], $bearer($adminA));
$vencido = (string) $r['data']['code'];
$pdo->exec("UPDATE `{$master}`.pos_handoff_codes SET expira_at = NOW() - INTERVAL 1 SECOND WHERE code_hash = " . $pdo->quote(hash('sha256', $vencido)));
[$h, $r] = $api('POST', '/auth/pos-handoff/canje', ['code' => $vencido]);
$chk('codigo vencido: 401', $h === 401 && ($r['codigo'] ?? '') === 'CODIGO_INVALIDO', [$h, $r]);

$posActivo($tenantA, 0);
[$h, $r] = $api('POST', '/auth/pos-handoff', [], $bearer($adminA));
$chk('empresa sin POS: 403 POS_INACTIVO', $h === 403 && ($r['codigo'] ?? '') === 'POS_INACTIVO', [$h, $r]);
$posActivo($tenantA, 1);

[$h, $r] = $api('GET', '/branding', null, $bearer($adminA));
$chk('GET /branding avisa pos_enabled (boton POS de app.*)', $h === 200 && ($r['data']['pos_enabled'] ?? null) === true, [$h, $r['data'] ?? $r]);

// ---------------------------------------------------------------------------
echo "\n2. Administracion: cajas y empleados (K1, A5)\n";

// Con PERMISSIONS_ENFORCE=true corta el gate central (su texto, sin codigo); en
// sombra el gate deja pasar y corta el controller (codigo SIN_PERMISO). Correr
// esta prueba con los dos valores prueba las dos capas.
[$h, $r] = $api('GET', '/pos-admin/cajas', null, $bearer($userA));
$chk('usuario sin modulo pos: 403 (gate en enforce, o el controller si el gate esta en sombra)',
    $h === 403 && (($r['codigo'] ?? '') === 'SIN_PERMISO' || !isset($r['data'])), [$h, $r]);
[$h, $r] = $api('POST', '/pos-admin/empleados', ['nombre' => 'Intruso'], $bearer($userA));
$chk('ni puede crear empleados (y PIN)', $h === 403 && !isset($r['data']['pin']), [$h, $r]);
[$h, $r] = $api('GET', '/pos-admin/cajas');
$chk('sin sesion: 401', $h === 401, [$h, $r]);

[$h, $r] = $api('POST', '/pos-admin/cajas', ['nombre' => '  Caja   1 '], $bearer($adminPos));
$caja1 = $r['data']['caja'] ?? [];
$chk('crear caja (con la sesion del canje); el nombre se limpia', $h === 201 && ($caja1['nombre'] ?? '') === 'Caja 1' && $caja1['activa'] === true, [$h, $r]);
[$h, $r] = $api('POST', '/pos-admin/cajas', ['nombre' => 'Caja 1'], $bearer($adminA));
$chk('caja con nombre repetido: 409 DUPLICADO', $h === 409 && ($r['codigo'] ?? '') === 'DUPLICADO', [$h, $r]);
[$h, $r] = $api('POST', '/pos-admin/cajas', ['nombre' => ''], $bearer($adminA));
$chk('caja sin nombre: 422', $h === 422, [$h, $r]);
[, $r] = $api('POST', '/pos-admin/cajas', ['nombre' => 'Caja 2'], $bearer($adminA));
$caja2 = $r['data']['caja'];
[$h, $r] = $api('PUT', '/pos-admin/cajas/' . $caja2['id'], ['nombre' => 'Caja 2 (fondo)', 'activa' => false], $bearer($adminA));
$chk('renombrar y desactivar caja', $h === 200 && $r['data']['caja']['nombre'] === 'Caja 2 (fondo)' && $r['data']['caja']['activa'] === false, [$h, $r]);
[$h, $r] = $api('PUT', '/pos-admin/cajas/' . $caja2['id'], ['activa' => 'si'], $bearer($adminA));
$chk('activa que no es bool: 422', $h === 422, [$h, $r]);
[$h, $r] = $api('PUT', '/pos-admin/cajas/99999', ['nombre' => 'X'], $bearer($adminA));
$chk('caja que no existe: 404', $h === 404, [$h, $r]);

[$h, $r] = $api('POST', '/pos-admin/empleados', ['nombre' => 'Ana', 'rol' => 'cajero'], $bearer($adminA));
$ana = $r['data']['empleado'] ?? [];
$pinAna = (string) ($r['data']['pin'] ?? '');
$chk('crear cajera: PIN de 4 digitos generado por el sistema', $h === 201 && preg_match('/^\d{4}$/', $pinAna) === 1 && $ana['rol'] === 'cajero', [$h, $r]);
[, $r] = $api('POST', '/pos-admin/empleados', ['nombre' => 'Luis', 'rol' => 'supervisor'], $bearer($adminA));
$luis = $r['data']['empleado'];
$pinLuis = (string) $r['data']['pin'];
$chk('crear supervisor con otro PIN', $pinLuis !== $pinAna && $luis['rol'] === 'supervisor');
[$h, $r] = $api('POST', '/pos-admin/empleados', ['nombre' => 'Pedro', 'rol' => 'jefe'], $bearer($adminA));
$chk('rol invalido: 422 ROL_INVALIDO', $h === 422 && ($r['codigo'] ?? '') === 'ROL_INVALIDO', [$h, $r]);
[$h, $r] = $api('GET', '/pos-admin/empleados', null, $bearer($adminA));
$json = json_encode($r);
$chk('la lista de empleados no trae el PIN ni su hash',
    $h === 200 && count($r['data']['empleados']) === 2 && !str_contains($json, 'pin_hmac') && !str_contains($json, $pinAna), [$h, $r]);
$fila = $pdo->query("SELECT pin_hmac FROM `{$dbA}`.pos_empleados WHERE id = {$ana['id']}")->fetch();
$chk('en la base el PIN es un HMAC de 64 hex, no el PIN', strlen($fila['pin_hmac']) === 64 && $fila['pin_hmac'] !== $pinAna
    && $fila['pin_hmac'] !== hash('sha256', $pinAna));

// ---------------------------------------------------------------------------
echo "\n3. Habilitar equipos (A4)\n";

[$h, $r] = $api('POST', '/pos-admin/equipos', ['caja_id' => $caja1['id'], 'nombre' => 'PC mostrador'], $bearer($adminPos));
$equipo1 = (string) ($r['data']['token'] ?? '');
$equipo1Id = (int) ($r['data']['equipo']['id'] ?? 0);
$chk('habilitar equipo para Caja 1: token de 64 hex', $h === 201 && preg_match('/^[0-9a-f]{64}$/', $equipo1) === 1, [$h, $r]);
[$h, $r] = $api('POST', '/pos-admin/equipos', ['caja_id' => $caja1['id']], $bearer($adminA));
$chk('otro equipo para la misma caja sin reemplazar: 409 CAJA_OCUPADA con el equipo actual',
    $h === 409 && ($r['codigo'] ?? '') === 'CAJA_OCUPADA' && ($r['equipo_actual']['id'] ?? 0) === $equipo1Id, [$h, $r]);
[$h, $r] = $api('POST', '/pos-admin/equipos', ['caja_id' => $caja1['id'], 'reemplazar' => true], $bearer($adminA));
$equipo2 = (string) ($r['data']['token'] ?? '');
$equipo2Id = (int) ($r['data']['equipo']['id'] ?? 0);
$chk('con reemplazar: equipo nuevo', $h === 201 && $equipo2 !== '' && $equipo2 !== $equipo1, [$h, $r]);
[$h, $r] = $api('GET', '/pos/estado', null, ['X-POS-EQUIPO' => $equipo1]);
$chk('el equipo reemplazado ya no entra: 401 EQUIPO_NO_HABILITADO', $h === 401 && ($r['codigo'] ?? '') === 'EQUIPO_NO_HABILITADO', [$h, $r]);
[$h, $r] = $api('POST', '/pos-admin/equipos', ['caja_id' => $caja2['id']], $bearer($adminA));
$chk('caja desactivada: 409 CAJA_INACTIVA', $h === 409 && ($r['codigo'] ?? '') === 'CAJA_INACTIVA', [$h, $r]);
[$h, $r] = $api('POST', '/pos-admin/equipos', ['caja_id' => 99999], $bearer($adminA));
$chk('caja que no existe: 404', $h === 404, [$h, $r]);
[$h, $r] = $api('GET', '/pos-admin/equipos', null, $bearer($adminA));
$chk('lista: un equipo vigente con su caja', $h === 200 && count($r['data']['equipos']) === 1
    && ($r['data']['equipos'][0]['caja']['nombre'] ?? '') === 'Caja 1', [$h, $r]);

// pos.* descarta la sesion del admin despues de habilitar
[$h, $r] = $api('POST', '/auth/signout', [], $bearer($adminPos));
[$h2, ] = $api('GET', '/pos-admin/cajas', null, $bearer($adminPos));
$chk('la sesion del canje se cierra con signout y ya no sirve', $h === 200 && $h2 === 401, [$h, $h2]);

// ---------------------------------------------------------------------------
echo "\n4. Entrar con PIN, bloqueo y sesiones (A6, A7)\n";

$eq = ['X-POS-EQUIPO' => $equipo2];
[$h, $r] = $api('GET', '/pos/estado', null, $eq);
$chk('estado del equipo: empresa, caja, sin empleado ni turno', $h === 200 && ($r['data']['empresa']['nombre'] ?? '') === 'POS Prueba A'
    && ($r['data']['caja']['id'] ?? 0) === $caja1['id'] && $r['data']['empleado'] === null && $r['data']['turno_caja'] === null, [$h, $r]);

$malo = '';
foreach (['0102', '0204', '0306'] as $candidato) {
    if ($candidato !== $pinAna && $candidato !== $pinLuis) {
        $malo = $candidato;
        break;
    }
}
$vencerBloqueo = fn() => $pdo->exec("UPDATE `{$master}`.pos_equipos SET bloqueado_hasta = NOW() - INTERVAL 1 SECOND WHERE id = {$equipo2Id}");
$bloqueosSeguidos = fn() => (int) $pdo->query("SELECT bloqueos_seguidos FROM `{$master}`.pos_equipos WHERE id = {$equipo2Id}")->fetchColumn();
foreach ([4, 3, 2, 1] as $quedan) {
    [$h, $r] = $api('POST', '/pos/sesion', ['pin' => $malo], $eq);
    $chk("PIN incorrecto: 401, quedan {$quedan}", $h === 401 && ($r['codigo'] ?? '') === 'PIN_INCORRECTO' && ($r['intentos_restantes'] ?? -1) === $quedan, [$h, $r]);
}
[$h, $r] = $api('POST', '/pos/sesion', ['pin' => $malo], $eq);
$chk('quinto PIN incorrecto: 423 EQUIPO_BLOQUEADO, primer bloqueo de 5 min', $h === 423 && ($r['codigo'] ?? '') === 'EQUIPO_BLOQUEADO'
    && ($r['bloqueo_segundos'] ?? 0) > 240 && ($r['bloqueo_segundos'] ?? 0) <= 300, [$h, $r]);
[$h, $r] = $api('POST', '/pos/sesion', ['pin' => $pinAna], $eq);
$chk('bloqueado: ni el PIN correcto entra', $h === 423, [$h, $r]);
[$h, $r] = $api('GET', '/pos/estado', null, $eq);
$chk('el estado dice que esta bloqueado', ($r['data']['equipo']['bloqueado'] ?? false) === true, [$h, $r]);

// Bloqueo progresivo (PIN de 4 digitos): el segundo seguido dura el doble.
$vencerBloqueo();
foreach ([4, 3, 2, 1] as $quedan) {
    $api('POST', '/pos/sesion', ['pin' => $malo], $eq);
}
[$h, $r] = $api('POST', '/pos/sesion', ['pin' => $malo], $eq);
$chk('segundo bloqueo seguido: 10 min (el doble)', $h === 423 && ($r['bloqueo_segundos'] ?? 0) > 540 && ($r['bloqueo_segundos'] ?? 0) <= 600, [$h, $r]);
$chk('la base cuenta 2 bloqueos seguidos', $bloqueosSeguidos() === 2, $bloqueosSeguidos());
$pdo->exec("UPDATE `{$master}`.pos_equipos SET bloqueos_seguidos = 20 WHERE id = {$equipo2Id}");
$vencerBloqueo();
foreach ([4, 3, 2, 1] as $quedan) {
    $api('POST', '/pos/sesion', ['pin' => $malo], $eq);
}
[$h, $r] = $api('POST', '/pos/sesion', ['pin' => $malo], $eq);
$chk('tope: nunca mas de un dia', $h === 423 && ($r['bloqueo_segundos'] ?? 0) > 86000 && ($r['bloqueo_segundos'] ?? 0) <= 86400, [$h, $r]);

$vencerBloqueo();
[$h, $r] = $api('POST', '/pos/sesion', ['pin' => $pinAna], $eq);
$sesAna = (string) ($r['data']['token'] ?? '');
$chk('vencido el bloqueo, Ana entra: token, empleado y caja', $h === 200 && $sesAna !== '' && ($r['data']['empleado']['nombre'] ?? '') === 'Ana'
    && ($r['data']['caja']['id'] ?? 0) === $caja1['id'], [$h, $r]);
$chk('entrar con un PIN valido devuelve los bloqueos seguidos a cero', $bloqueosSeguidos() === 0, $bloqueosSeguidos());
[$h, $r] = $api('POST', '/pos/sesion', ['pin' => $malo], $eq);
$chk('entrar bien reinicia la cuenta: quedan 4 otra vez', ($r['intentos_restantes'] ?? -1) === 4, [$h, $r]);
$api('POST', '/pos/sesion', ['pin' => $pinAna], $eq); // deja la cuenta en 0 y abre otra sesion de Ana
[, $r] = $api('POST', '/pos/sesion', ['pin' => $pinAna], $eq);
$sesAna = (string) $r['data']['token'];

[$h, $r] = $api('GET', '/pos/estado', null, $eq + ['X-POS-SESION' => $sesAna]);
$chk('estado con sesion: el empleado es Ana', ($r['data']['empleado']['nombre'] ?? '') === 'Ana', [$h, $r]);

[$h, $r] = $api('POST', '/pos/sesion', ['pin' => (int) $pinLuis], $eq);
$chk('PIN como numero JSON: 422 PIN_FORMATO (perderia los ceros)', $h === 422 && ($r['codigo'] ?? '') === 'PIN_FORMATO', [$h, $r]);
[$h, $r] = $api('POST', '/pos/sesion', ['pin' => '123'], $eq);
[$h2, $r2] = $api('POST', '/pos/sesion', ['pin' => '12345'], $eq);
$chk('PIN de 3 o de 5 digitos: 422 y no cuenta como intento', $h === 422 && $h2 === 422 && ($r['codigo'] ?? '') === 'PIN_FORMATO', [$h, $r, $h2]);

[$h, $r] = $api('POST', '/pos/sesion', ['pin' => $pinLuis], $eq);
$sesLuis = (string) ($r['data']['token'] ?? '');
$chk('Luis entra en el mismo equipo', $h === 200 && ($r['data']['empleado']['rol'] ?? '') === 'supervisor', [$h, $r]);
[$h, $r] = $api('DELETE', '/pos/sesion', null, $eq + ['X-POS-SESION' => $sesAna]);
$chk('la sesion de Ana se cerro al entrar Luis (un empleado por equipo)', $h === 401 && ($r['codigo'] ?? '') === 'SESION_REQUERIDA', [$h, $r]);
[$h, $r] = $api('DELETE', '/pos/sesion', null, $eq + ['X-POS-SESION' => $sesLuis]);
$chk('Luis bloquea la pantalla: sesion cerrada', $h === 200 && ($r['data']['cerrada'] ?? false) === true, [$h, $r]);
[$h, $r] = $api('GET', '/pos/estado', null, $eq + ['X-POS-SESION' => $sesLuis]);
$chk('despues del bloqueo el estado ya no tiene empleado', $h === 200 && $r['data']['empleado'] === null, [$h, $r]);

// ---------------------------------------------------------------------------
echo "\n5. PIN regenerado, empleado desactivado, caja desactivada\n";

[, $r] = $api('POST', '/pos/sesion', ['pin' => $pinAna], $eq);
$sesAna = (string) $r['data']['token'];
[$h, $r] = $api('POST', '/pos-admin/empleados/' . $ana['id'] . '/pin', [], $bearer($adminA));
$pinAna2 = (string) ($r['data']['pin'] ?? '');
$chk('regenerar PIN: uno nuevo y distinto del anterior', $h === 200 && preg_match('/^\d{4}$/', $pinAna2) === 1 && $pinAna2 !== $pinAna, [$h, $r]);
[$h, $r] = $api('GET', '/pos/estado', null, $eq + ['X-POS-SESION' => $sesAna]);
$chk('la sesion que tenia Ana se cerro', $r['data']['empleado'] === null, [$h, $r]);
[$h, $r] = $api('POST', '/pos/sesion', ['pin' => $pinAna], $eq);
$chk('el PIN viejo ya no entra', $h === 401 && ($r['codigo'] ?? '') === 'PIN_INCORRECTO', [$h, $r]);
[$h, $r] = $api('POST', '/pos/sesion', ['pin' => $pinAna2], $eq);
$chk('el PIN nuevo si', $h === 200, [$h, $r]);
$sesAna = (string) $r['data']['token'];

[$h, $r] = $api('PUT', '/pos-admin/empleados/' . $ana['id'], ['activo' => false], $bearer($adminA));
$chk('desactivar a Ana', $h === 200 && $r['data']['empleado']['activo'] === false, [$h, $r]);
[$h, $r] = $api('GET', '/pos/estado', null, $eq + ['X-POS-SESION' => $sesAna]);
$chk('desactivada: su sesion se cerro en el acto', $r['data']['empleado'] === null, [$h, $r]);
[$h, $r] = $api('POST', '/pos/sesion', ['pin' => $pinAna2], $eq);
$chk('desactivada: su PIN no entra', $h === 401, [$h, $r]);

$api('PUT', '/pos-admin/cajas/' . $caja1['id'], ['activa' => false], $bearer($adminA));
[$h, $r] = $api('POST', '/pos/sesion', ['pin' => $pinLuis], $eq);
$chk('caja desactivada despues de habilitar: 403 CAJA_INACTIVA', $h === 403 && ($r['codigo'] ?? '') === 'CAJA_INACTIVA', [$h, $r]);
$api('PUT', '/pos-admin/cajas/' . $caja1['id'], ['activa' => true], $bearer($adminA));

// ---------------------------------------------------------------------------
echo "\n6. Aislamiento: tokens que no se cruzan\n";

[, $r] = $api('POST', '/pos-admin/cajas', ['nombre' => 'Caja 1'], $bearer($adminB));
$chk('la empresa B puede tener su propia "Caja 1"', isset($r['data']['caja']['id']), $r);
[, $r] = $api('POST', '/pos-admin/empleados', ['nombre' => 'Bea', 'rol' => 'cajero'], $bearer($adminB));
$bea = $r['data']['empleado'];
$pinBea = (string) $r['data']['pin'];
// Con 10^4 PINs, el de Bea podria ser el de Luis (activo en A) por azar: eso
// no prueba nada del aislamiento, asi que se le cambia hasta que difiera.
while ($pinBea === $pinLuis) {
    [, $r] = $api('POST', '/pos-admin/empleados/' . $bea['id'] . '/pin', [], $bearer($adminB));
    $pinBea = (string) $r['data']['pin'];
}
[$h, $r] = $api('POST', '/pos/sesion', ['pin' => $pinBea], $eq);
$chk('el PIN de un empleado de B no entra en un equipo de A', $h === 401 && ($r['codigo'] ?? '') === 'PIN_INCORRECTO', [$h, $r]);
[$h, $r] = $api('DELETE', '/pos-admin/equipos/' . $equipo2Id, null, $bearer($adminB));
$chk('el admin de B no puede revocar un equipo de A: 404', $h === 404, [$h, $r]);
[$h, $r] = $api('GET', '/pos-admin/equipos', null, $bearer($adminB));
$chk('ni lo ve en su lista', $h === 200 && $r['data']['equipos'] === [], [$h, $r]);
[$h, $r] = $api('GET', '/facturas', null, ['Authorization' => "Bearer {$equipo2}"]);
$chk('el token del equipo no abre rutas de app.*', $h === 401, [$h, $r]);
[$h, $r] = $api('GET', '/pos/estado', null, ['X-POS-EQUIPO' => $adminA]);
$chk('un token de usuario no sirve como equipo', $h === 401 && ($r['codigo'] ?? '') === 'EQUIPO_NO_HABILITADO', [$h, $r]);

$posActivo($tenantA, 0);
[$h, $r] = $api('GET', '/pos/estado', null, $eq);
$chk('empresa sin POS: el equipo recibe 403 POS_INACTIVO', $h === 403 && ($r['codigo'] ?? '') === 'POS_INACTIVO', [$h, $r]);
$posActivo($tenantA, 1);

[, $r] = $api('POST', '/pos/sesion', ['pin' => $pinLuis], $eq);
$sesLuis = (string) $r['data']['token'];

// ---------------------------------------------------------------------------
echo "
6b. Catalogo de la caja (C1-C4)
";
require_once __DIR__ . '/../src/Pos/PosPrecio.php';
$chk('precio final: 21.1864 al 18% = 25.00 (el de gondola)', PosPrecio::finalCentavos('21.1864', 1) === 2500);
$chk('precio final: 8.4746 al 18% = 10.00 y 100 al 16% = 116.00',
    PosPrecio::finalCentavos('8.4746', 1) === 1000 && PosPrecio::finalCentavos('100.0000', 2) === 11600);
$chk('tasa cero y exento: sin ITBIS', PosPrecio::finalCentavos('10.5', 3) === 1050 && PosPrecio::finalCentavos('10.0000', 4) === 1000);
$chk('justo en la mitad redondea hacia arriba: 1.25 al 18% = 1.475 -> 1.48', PosPrecio::finalCentavos('1.2500', 1) === 148);
$noCalcula = function (string $precio, int $ind): bool {
    try {
        PosPrecio::finalCentavos($precio, $ind);
        return false;
    } catch (InvalidArgumentException $e) {
        return true;
    }
};
$chk('no facturable, indicador raro, precio negativo o con texto: no se calcula',
    $noCalcula('10', 0) && $noCalcula('10', 9) && $noCalcula('-1.0000', 1) && $noCalcula('abc', 1));

// Datos de prueba. La master local no siempre trae el catalogo de unidades.
$pdo->exec("INSERT IGNORE INTO `{$master}`.unidades_medida (id, codigo, descripcion, permite_decimales)
            VALUES (43, 'UND', 'Unidad', 0), (21, 'KG', 'Kilogramo', 1)");
$almacen = (int) $pdo->query("SELECT id FROM `{$dbA}`.warehouses ORDER BY id LIMIT 1")->fetchColumn();
$pdo->exec("INSERT INTO `{$dbA}`.categories (nombre, estado) VALUES ('POS Test Bebidas', 1), ('POS Test Viejos', 0)");
$catBebidas = (int) $pdo->query("SELECT id FROM `{$dbA}`.categories WHERE nombre = 'POS Test Bebidas'")->fetchColumn();
$catViejos = (int) $pdo->query("SELECT id FROM `{$dbA}`.categories WHERE nombre = 'POS Test Viejos'")->fetchColumn();
$producto = function (string $sku, string $nombre, ?int $cat, int $ind, string $precio, string $unidad, ?string $stock, ?string $min, int $activo = 1)
    use ($pdo, $dbA, $almacen): void {
    $st = $pdo->prepare("INSERT INTO `{$dbA}`.products (sku, nombre, category_id, warehouse_id, indicador_facturacion, precio, unidad_medida, stock, stock_minimo, activo)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $st->execute([$sku, $nombre, $cat, $almacen, $ind, $precio, $unidad, $stock, $min, $activo]);
};
$producto('POSTEST-1', 'Agua 500 ml', $catBebidas, 1, '21.1864', '43', '10', '3');
$producto('POSTEST-2', 'Queso de freir', null, 4, '150.0000', '21', '0', null);
$producto('POSTEST-3', 'Recarga', $catViejos, 1, '8.4746', '43', null, null);
$producto('POSTEST-4', 'Bolsa (no facturable)', $catBebidas, 0, '5.0000', '43', '100', null);
$producto('POSTEST-5', 'Refresco descontinuado', $catBebidas, 1, '50.0000', '43', '4', null, 0);
$producto('POSTEST-6', 'Precio mal cargado', $catBebidas, 1, '-1.0000', '43', '1', null);

$eqLuis = $eq + ['X-POS-SESION' => $sesLuis];
[$h, $r] = $api('GET', '/pos/catalogo', null, $eq);
$chk('catalogo sin sesion de empleado: 401 SESION_REQUERIDA', $h === 401 && ($r['codigo'] ?? '') === 'SESION_REQUERIDA', [$h, $r]);
[$h, $r] = $api('GET', '/pos/catalogo', null, $eqLuis);
$dePrueba = [];
foreach ($r['data']['productos'] ?? [] as $p) {
    if (str_starts_with((string) $p['sku'], 'POSTEST-')) {
        $dePrueba[$p['sku']] = $p;
    }
}
$chk('catalogo: solo activos y facturables; el de precio mal cargado se omite sin tumbar la caja',
    $h === 200 && array_keys($dePrueba) === ['POSTEST-1', 'POSTEST-2', 'POSTEST-3'], [$h, array_keys($dePrueba)]);
// JSON manda 10.0 como 10: se compara el numero, no el tipo (null sigue siendo null).
$num = fn($v) => is_int($v) || is_float($v) ? (float) $v : $v;
$agua = $dePrueba['POSTEST-1'] ?? [];
$chk('agua: 25.00 con ITBIS 18%, existencia y minimo, en su categoria, cantidades enteras',
    ($agua['precio_centavos'] ?? 0) === 2500 && $agua['tasa'] === 18 && $num($agua['stock']) === 10.0 && $num($agua['stock_minimo']) === 3.0
    && $agua['category_id'] === $catBebidas && $agua['decimales'] === false && !isset($agua['precio']), $agua);
$queso = $dePrueba['POSTEST-2'] ?? [];
$chk('queso: exento (150.00), por kilo admite decimales, agotado',
    ($queso['precio_centavos'] ?? 0) === 15000 && $queso['tasa'] === 0 && $queso['decimales'] === true && $num($queso['stock']) === 0.0, $queso);
$recarga = $dePrueba['POSTEST-3'] ?? [];
$chk('recarga: 10.00, servicio (stock null) y su categoria inactiva queda en "Todos"',
    ($recarga['precio_centavos'] ?? 0) === 1000 && $recarga['stock'] === null && $recarga['category_id'] === null, $recarga);
$chips = array_column($r['data']['categorias'] ?? [], 'productos', 'nombre');
$chk('categorias: solo las activas con productos, con su conteo', ($chips['POS Test Bebidas'] ?? 0) === 1
    && !isset($chips['POS Test Viejos']), $chips);
[$h, $r] = $api('DELETE', '/pos-admin/equipos/' . $equipo2Id, null, $bearer($adminA));
$chk('revocar el equipo', $h === 200, [$h, $r]);
[$h, $r] = $api('GET', '/pos/estado', null, $eq + ['X-POS-SESION' => $sesLuis]);
$chk('revocado: el equipo ya no entra', $h === 401 && ($r['codigo'] ?? '') === 'EQUIPO_NO_HABILITADO', [$h, $r]);
$abiertas = (int) $pdo->query("SELECT COUNT(*) FROM `{$dbA}`.pos_sesiones WHERE equipo_id = {$equipo2Id} AND cerrada_at IS NULL")->fetchColumn();
$chk('y sus sesiones quedaron cerradas', $abiertas === 0, $abiertas);

// ---------------------------------------------------------------------------
echo "\n7. Bitacora\n";

$filas = $pdo->query("SELECT action, success, new_values, old_values, tenant_id FROM `{$master}`.audit_logs WHERE id > {$inicioAudit} AND module = 'pos'")->fetchAll();
$acciones = array_count_values(array_column($filas, 'action'));
foreach (['POS_TRASPASO_CREADO', 'POS_CAJA_CREADA', 'POS_EMPLEADO_CREADO', 'POS_PIN_REGENERADO', 'POS_EQUIPO_HABILITADO',
          'POS_EQUIPO_REVOCADO', 'POS_PIN_FALLIDO', 'POS_EQUIPO_BLOQUEADO', 'POS_SESION_ABIERTA', 'POS_SESION_CERRADA'] as $a) {
    $chk("registra {$a}", ($acciones[$a] ?? 0) > 0, $acciones);
}
$todo = json_encode($filas);
$chk('ningun PIN ni token en la bitacora',
    !str_contains($todo, $pinAna) && !str_contains($todo, $pinAna2) && !str_contains($todo, $pinLuis)
    && !str_contains($todo, $equipo2) && !str_contains($todo, $codigo));
$chk('cada evento queda en su empresa', !array_filter($filas, fn($f) => !in_array((int) $f['tenant_id'], [$tenantA, $tenantB], true)),
    array_column($filas, 'tenant_id'));

// Las sesiones de login de esta corrida no quedan abiertas en la base de prueba.
foreach ([$adminA, $adminB, $userA] as $t) {
    $api('POST', '/auth/signout', [], $bearer($t));
}

echo "\n" . ($fallos === 0 ? "TODO OK ({$total} verificaciones)" : "{$fallos} de {$total} FALLARON") . "\n";
exit($fallos === 0 ? 0 : 1);
