<?php
/**
 * test_emisor_edicion_api.php — PUT /api/emisor de punta a punta: la API real (php -S index.php) sobre la base de
 * prueba, con usuarios y roles de verdad. Nunca produccion: wa_pdo() se niega a usar una base sin la tabla marca.
 *
 * Dos instancias de la API sobre la misma base:
 *   - SOMBRA (PERMISSIONS_ENFORCE=false, como esta produccion hoy): el gate solo anota lo que negaria, asi que quien
 *     bloquea es el controller. Es el caso que importa: sin esa guarda, cualquier rol cambiaria los datos de la empresa.
 *   - GATE (PERMISSIONS_ENFORCE=true): el gate aplica el modulo 'emisor' que config/permissions.php pide para todo lo
 *     que no sea GET, y el controller lo vuelve a exigir.
 *
 * Roles: admin (por nombre), user (de sistema, sin el modulo), un rol propio CON el modulo emisor y otro SIN el.
 * Al terminar (o si el script muere) deja la base como la encontro: el emisor de wa_sembrar, sin los usuarios, los roles
 * ni las filas del catalogo que sembro.
 *
 * Uso:
 *   php -d extension=curl -d extension=openssl -d extension=pdo_mysql tools/test_emisor_edicion_api.php --tenant-db=wa_scratch
 */

require_once __DIR__ . '/write_ahead_test_lib.php';
require_once __DIR__ . '/../src/Utils/EmisorEdicion.php';

const EMI_PUERTO_SOMBRA = 8096;
const EMI_PUERTO_GATE = 8095;
const EMI_ROL_CON = 'wa_contador';   // rol propio CON el modulo emisor
const EMI_ROL_SIN = 'wa_solofact';   // rol propio SIN el (solo facturas)
const EMI_USUARIOS = ['wa_emi_admin' => 'admin', 'wa_emi_user' => 'user', 'wa_emi_contador' => EMI_ROL_CON, 'wa_emi_solofact' => EMI_ROL_SIN];
const EMI_MSG_GATE = 'No tienes permiso para hacer esto. Pídeselo a un administrador.';
const EMI_MSG_CONTROLLER = 'Solo el administrador de la empresa puede cambiar estos datos.';

$base = (string) wa_arg('tenant-db', '');
$pdo = wa_pdo($base);
wa_limpiar($pdo);
wa_sembrar($pdo);

/** Deja la fila 1 de emisor_config como la siembra wa_sembrar (que no toca sucursal, municipio, provincia, sitio web ni actividad). */
$restaurarEmisor = static function () use ($pdo): void {
    $pdo->exec('UPDATE emisor_config SET nombre_comercial = NULL, sucursal = NULL, municipio = NULL, provincia = NULL, correo = NULL, website = NULL, actividad_economica = NULL WHERE id = 1');
    wa_sembrar($pdo);
};

// --- usuarios, roles y catalogo de la prueba (todo se retira al terminar) ---
$clave = bin2hex(random_bytes(16));
$hash = password_hash($clave, PASSWORD_DEFAULT);
foreach ([EMI_ROL_CON => 'emisor', EMI_ROL_SIN => 'facturas'] as $rol => $permiso) {
    $pdo->prepare("INSERT INTO roles (tenant_id, name, description, is_system) VALUES (0, :n, 'Prueba de PUT /api/emisor', 0)
                   ON DUPLICATE KEY UPDATE description = VALUES(description)")->execute([':n' => $rol]);
    $id = (int) $pdo->query('SELECT id FROM roles WHERE tenant_id = 0 AND name = ' . $pdo->quote($rol))->fetchColumn();
    $pdo->prepare('DELETE FROM role_permissions WHERE role_id = :r')->execute([':r' => $id]);
    $pdo->prepare('INSERT INTO role_permissions (role_id, permission) VALUES (:r, :p)')->execute([':r' => $id, ':p' => $permiso]);
}
foreach (EMI_USUARIOS as $usuario => $rol) {
    $pdo->prepare("INSERT INTO users (name, last_name, email, username, password, role) VALUES ('WA', 'Emisor', :e, :u, :p, :r)
                   ON DUPLICATE KEY UPDATE password = VALUES(password), role = VALUES(role)")
        ->execute([':e' => $usuario . '@example.com', ':u' => $usuario, ':p' => $hash, ':r' => $rol]);
}
$sembradas = [];   // codigos del catalogo que puso esta prueba (INSERT IGNORE: lo que ya estaba no se toca)
foreach ([['010000', '01', 'DISTRITO NACIONAL', 'PROVINCIA'], ['010100', '01', 'SANTO DOMINGO DE GUZMAN', 'MUNICIPIO'],
          ['020000', '02', 'AZUA', 'PROVINCIA'], ['020100', '02', 'AZUA', 'MUNICIPIO']] as $fila) {
    $st = $pdo->prepare('INSERT IGNORE INTO dgii_provincia_municipio (codigo, provincia_codigo, descripcion, tipo) VALUES (?, ?, ?, ?)');
    $st->execute($fila);
    if ($st->rowCount() === 1) {
        $sembradas[] = $fila[0];
    }
}
register_shutdown_function(static function () use ($pdo, &$sembradas, $restaurarEmisor): void {
    try {
        foreach ($sembradas as $codigo) {
            $pdo->prepare('DELETE FROM dgii_provincia_municipio WHERE codigo = ?')->execute([$codigo]);
        }
        $sembradas = [];
        foreach ([EMI_ROL_CON, EMI_ROL_SIN] as $rol) {
            $pdo->prepare('DELETE FROM role_permissions WHERE role_id IN (SELECT id FROM roles WHERE tenant_id = 0 AND name = ?)')->execute([$rol]);
            $pdo->prepare('DELETE FROM roles WHERE tenant_id = 0 AND name = ?')->execute([$rol]);
        }
        $pdo->exec("DELETE FROM users WHERE username IN ('" . implode("','", array_keys(EMI_USUARIOS)) . "')");
        $restaurarEmisor();
    } catch (Throwable $e) {
        echo "[AVISO] no se pudo dejar la base como estaba: " . get_class($e) . "\n";
    }
});
$restaurarEmisor();

$sombra = wa_api($base, [], EMI_PUERTO_SOMBRA)['api'];
$gate = wa_api($base, ['PERMISSIONS_ENFORCE' => 'true'], EMI_PUERTO_GATE)['api'];

/** Token de $usuario en la API $api (un login nuevo cada vez: no se asume que los anteriores sigan vivos). */
$token = static function (string $api, string $usuario) use ($clave): string {
    $r = wa_http('POST', $api . '/api/auth/login', ['emailOrUsername' => $usuario, 'password' => $clave]);
    $t = (string) ($r['json']['data']['token'] ?? '');
    if ($t === '') {
        echo "[FALLO] no se pudo iniciar sesion como {$usuario} en {$api}: HTTP {$r['status']} " . substr($r['body'], 0, 200) . "\n";
        exit(1);
    }
    return $t;
};
$put = static fn (string $api, string $tk, $cuerpo): array => wa_http('PUT', $api . '/api/emisor', $cuerpo, ['X-API-KEY: ' . $tk]);
$get = static fn (string $api, string $tk): array => wa_http('GET', $api . '/api/emisor', null, ['X-API-KEY: ' . $tk]);
$fila = static fn (): array => $pdo->query('SELECT * FROM emisor_config WHERE id = 1')->fetch();
$bitacora = static function (string $accion) use ($pdo): array {
    return $pdo->query('SELECT * FROM audit_logs WHERE module = ' . $pdo->quote('emisor') . ' AND action = ' . $pdo->quote($accion) . ' ORDER BY id')->fetchAll();
};
$data = static fn (array $r): array => is_array($r['json']['data'] ?? null) ? $r['json']['data'] : [];

$admin = $token($sombra, 'wa_emi_admin');
$base0 = $fila();

// ---------------------------------------------------------------------------
echo "== GET: sigue abierto y sin cambios ==\n";
$r = $get($sombra, $admin);
$d = $data($r);
wa_chk('GET /api/emisor del admin: 200 con status true', $r['status'] === 200 && ($r['json']['status'] ?? null) === true, $r);
wa_chk('...trae RNC, razon social, ambiente-fuente y NO el id', ($d['rnc'] ?? null) === '000000000' && ($d['razon_social'] ?? null) === 'WA EMISOR DE PRUEBA SRL'
    && ($d['fuente'] ?? null) === 'emisor_config' && !array_key_exists('id', $d), $d);
$r = wa_http('GET', $sombra . '/api/emisor');
wa_chk('GET sin credenciales: 401', $r['status'] === 401, $r);

// ---------------------------------------------------------------------------
echo "\n== Admin: edita los datos de su empresa ==\n";
$cambio = [
    'nombre_comercial' => 'WA Emisor Central', 'sucursal' => 'Zona Norte', 'direccion' => 'Av. 27 de Febrero 100',
    'telefono' => '(829) 555-1234', 'correo' => 'ventas@wa-emisor.com.do', 'website' => 'www.wa-emisor.com.do',
    'actividad_economica' => 'Venta al por menor',
];
$r = $put($sombra, $admin, $cambio);
$d = $data($r);
wa_chk('PUT con los datos nuevos: 200', $r['status'] === 200 && ($r['json']['status'] ?? null) === true, $r);
wa_chk('...responde como el GET, ya con los datos nuevos (telefono normalizado a 829-555-1234)',
    ($d['nombre_comercial'] ?? null) === 'WA Emisor Central' && ($d['sucursal'] ?? null) === 'Zona Norte' && ($d['direccion'] ?? null) === 'Av. 27 de Febrero 100'
    && ($d['telefono'] ?? null) === '829-555-1234' && ($d['correo'] ?? null) === 'ventas@wa-emisor.com.do' && ($d['website'] ?? null) === 'www.wa-emisor.com.do'
    && ($d['actividad_economica'] ?? null) === 'Venta al por menor' && ($d['fuente'] ?? null) === 'emisor_config' && !array_key_exists('id', $d), $d);
wa_chk('...y el RNC y la razon social siguen siendo los de siempre', ($d['rnc'] ?? null) === '000000000' && ($d['razon_social'] ?? null) === 'WA EMISOR DE PRUEBA SRL', $d);
$f = $fila();
wa_chk('en la base quedo todo, y rnc, razon social y vencimiento de secuencia no se tocaron',
    $f['direccion'] === 'Av. 27 de Febrero 100' && $f['telefono'] === '829-555-1234' && $f['website'] === 'www.wa-emisor.com.do'
    && $f['rnc'] === $base0['rnc'] && $f['razon_social'] === $base0['razon_social'] && $f['fecha_vencimiento_secuencia'] === $base0['fecha_vencimiento_secuencia'], $f);
$g = $data($get($sombra, $admin));
wa_chk('un GET despues lee los datos nuevos', ($g['direccion'] ?? null) === 'Av. 27 de Febrero 100' && ($g['telefono'] ?? null) === '829-555-1234', $g);
$b = $bitacora('UPDATE');
$ult = $b ? end($b) : [];
$antes = json_decode((string) ($ult['old_values'] ?? ''), true) ?: [];
$despues = json_decode((string) ($ult['new_values'] ?? ''), true) ?: [];
wa_chk('la bitacora tiene UN UPDATE del modulo emisor, hecho por quien lo pidio', count($b) === 1 && ($ult['username'] ?? null) === 'wa_emi_admin' && ($ult['entity_type'] ?? null) === 'emisor_config', $b);
wa_chk('...con lo de antes y lo de despues, solo de lo que cambio (nada de rnc ni razon social)',
    ($antes['direccion'] ?? null) === 'Calle de prueba 1' && ($despues['direccion'] ?? null) === 'Av. 27 de Febrero 100'
    && ($despues['telefono'] ?? null) === '829-555-1234' && !isset($antes['rnc'], $despues['rnc'], $despues['razon_social']), [$antes, $despues]);
wa_chk('...y una descripcion con palabras de la pantalla', str_contains((string) ($ult['description'] ?? ''), 'la dirección') && str_contains((string) ($ult['description'] ?? ''), 'el teléfono'), $ult['description'] ?? null);

$r = $put($sombra, $admin, ['direccion' => 'Calle Ñandú #5, Santiago']);
$f = $fila();
wa_chk('tildes, eñes y # se guardan tal cual', $r['status'] === 200 && $f['direccion'] === 'Calle Ñandú #5, Santiago' && ($data($r)['direccion'] ?? null) === 'Calle Ñandú #5, Santiago', [$r['body'], $f['direccion']]);

$r = $put($sombra, $admin, ['website' => '', 'sucursal' => '']);
$f = $fila();
$d = $data($r);
wa_chk('en blanco deja la columna en NULL (y la respuesta trae esas claves con null, no las omite)',
    $r['status'] === 200 && $f['website'] === null && $f['sucursal'] === null
    && array_key_exists('website', $d) && $d['website'] === null && array_key_exists('sucursal', $d) && $d['sucursal'] === null, [$r['body'], $f]);

$d = $data($get($sombra, $admin));
$r = $put($sombra, $admin, array_merge($d, ['telefono' => '809-555-9999']));
$f = $fila();
wa_chk('devolver tal cual lo que dio el GET (con rnc, razon social, ambiente y fuente) y cambiar un dato: 200 y solo cambia ese dato',
    $r['status'] === 200 && $f['telefono'] === '809-555-9999' && $f['rnc'] === $base0['rnc'] && $f['razon_social'] === $base0['razon_social'], [$r['body'], $f]);

// ---------------------------------------------------------------------------
echo "\n== RNC y razon social: no se editan ==\n";
$antesFila = $fila();
$nBitacora = count($bitacora('UPDATE'));
$r = $put($sombra, $admin, ['rnc' => '131000009']);
wa_chk('un RNC distinto: 422 con el mensaje de soporte y el detalle por campo', $r['status'] === 422 && ($r['json']['error'] ?? null) === EmisorEdicion::MSG_BLOQUEADO
    && ($r['json']['campos']['rnc'] ?? null) === EmisorEdicion::MSG_BLOQUEADO, $r);
$r = $put($sombra, $admin, ['razon_social' => 'Otra Empresa SRL']);
wa_chk('una razon social distinta: 422', $r['status'] === 422 && ($r['json']['error'] ?? null) === EmisorEdicion::MSG_BLOQUEADO, $r);
$r = $put($sombra, $admin, ['razon_social' => 'Otra Empresa SRL', 'rnc' => '131000009', 'direccion' => 'Calle que no debe guardarse 1']);
wa_chk('RNC y razon social distintos junto con un cambio valido: 422 y NO se guarda ni el cambio valido (todo o nada)',
    $r['status'] === 422 && $fila() === $antesFila, [$r['body'], $fila()]);
wa_chk('...y nada de eso dejo un UPDATE en la bitacora', count($bitacora('UPDATE')) === $nBitacora);

// ---------------------------------------------------------------------------
echo "\n== Validacion: lo que el XML de la DGII no acepta, no se guarda ==\n";
foreach ([
    ['telefono', '555-1234', EmisorEdicion::MSG_TELEFONO],
    ['correo', 'sin-arroba', EmisorEdicion::MSG_CORREO],
    ['direccion', '   ', EmisorEdicion::MSG_SIN_DIRECCION],
    ['direccion', str_repeat('a', 101), 'La dirección no puede pasar de 100 caracteres.'],
    ['nombre_comercial', str_repeat('a', 151), 'El nombre comercial no puede pasar de 150 caracteres.'],
    ['sucursal', str_repeat('a', 21), 'La sucursal no puede pasar de 20 caracteres.'],
    ['website', 'www empresa.com', EmisorEdicion::MSG_WEBSITE_ESPACIOS],
    ['actividad_economica', str_repeat('a', 101), 'La actividad económica no puede pasar de 100 caracteres.'],
] as [$campo, $malo, $mensaje]) {
    $r = $put($sombra, $admin, [$campo => $malo]);
    wa_chk("{$campo} " . json_encode(strlen($malo) > 25 ? strlen($malo) . ' caracteres' : $malo, JSON_UNESCAPED_UNICODE) . ': 422 con su mensaje, y la base no cambia',
        $r['status'] === 422 && ($r['json']['error'] ?? null) === $mensaje && ($r['json']['campos'][$campo] ?? null) === $mensaje && $fila() === $antesFila, [$r['body']]);
}
$r = $put($sombra, $admin, ['telefono' => '1', 'correo' => 'x', 'direccion' => 'Calle valida 3']);
wa_chk('varios errores a la vez: 422, el primero en error y todos en campos; ni siquiera lo valido se guarda',
    $r['status'] === 422 && isset($r['json']['campos']['telefono'], $r['json']['campos']['correo']) && !isset($r['json']['campos']['direccion']) && $fila() === $antesFila, $r);
$r = $put($sombra, $admin, []);
wa_chk('cuerpo {} : 422 "No hay cambios para guardar."', $r['status'] === 422 && ($r['json']['error'] ?? null) === EmisorEdicion::MSG_SIN_CAMBIOS, $r);
$r = $put($sombra, $admin, ['telefono' => '8095559999', 'direccion' => $antesFila['direccion']]);
wa_chk('los mismos datos que ya estan (el telefono sin guiones tambien): 422 sin cambios', $r['status'] === 422 && ($r['json']['error'] ?? null) === EmisorEdicion::MSG_SIN_CAMBIOS, $r);
$r = $put($sombra, $admin, 'esto no es json');
wa_chk('un cuerpo que no es JSON: 400', $r['status'] === 400, $r);
$r = $put($sombra, $admin, '"texto"');
wa_chk('un JSON que no es un objeto: 400', $r['status'] === 400, $r);
$r = wa_http('PUT', $sombra . '/api/emisor', null, ['X-API-KEY: ' . $admin]);
wa_chk('sin cuerpo: 400', $r['status'] === 400, $r);
$r = $put($sombra, $admin, ['sucursal' => ['x']]);
wa_chk('un dato que no es texto: 422', $r['status'] === 422 && isset($r['json']['campos']['sucursal']), $r);
wa_chk('nada de lo anterior toco la base ni dejo UPDATE en la bitacora', $fila() === $antesFila && count($bitacora('UPDATE')) === $nBitacora);

// ---------------------------------------------------------------------------
echo "\n== Provincia y municipio (catalogo DGII) ==\n";
$r = $put($sombra, $admin, ['provincia' => '010000', 'municipio' => '010100']);
$f = $fila();
wa_chk('provincia y municipio del catalogo, coherentes: 200 y se guardan los codigos', $r['status'] === 200 && $f['provincia'] === '010000' && $f['municipio'] === '010100', [$r['body'], $f]);
$r = $put($sombra, $admin, ['provincia' => '020000']);
wa_chk('cambiar solo la provincia dejando el municipio de la anterior: 422 (hay que elegir el municipio de nuevo)',
    $r['status'] === 422 && ($r['json']['campos']['municipio'] ?? null) === EmisorEdicion::MSG_MUNICIPIO_AJENO && $fila()['provincia'] === '010000', $r);
$r = $put($sombra, $admin, ['provincia' => '999999']);
wa_chk('una provincia que no esta en el catalogo: 422', $r['status'] === 422 && ($r['json']['campos']['provincia'] ?? null) === EmisorEdicion::MSG_PROVINCIA, $r);
$r = $put($sombra, $admin, ['municipio' => '010000']);
wa_chk('un codigo de provincia no vale como municipio: 422', $r['status'] === 422 && ($r['json']['campos']['municipio'] ?? null) === EmisorEdicion::MSG_MUNICIPIO, $r);
$r = $put($sombra, $admin, ['provincia' => '020000', 'municipio' => '']);
$f = $fila();
wa_chk('cambiar la provincia y dejar el municipio en blanco: 200', $r['status'] === 200 && $f['provincia'] === '020000' && $f['municipio'] === null, [$r['body'], $f]);
$g = $data($get($sombra, $admin));
wa_chk('el GET devuelve los codigos (la pantalla los traduce con el catalogo) y el municipio en blanco como null',
    ($g['provincia'] ?? null) === '020000' && array_key_exists('municipio', $g) && $g['municipio'] === null, $g);

// ---------------------------------------------------------------------------
echo "\n== Quien puede: modo sombra (el gate solo anota; bloquea el controller) ==\n";
$antesFila = $fila();
$nDenegados = count($bitacora('ACCESS_DENIED'));
foreach ([['wa_emi_user', 'user (rol de sistema)'], ['wa_emi_solofact', 'rol propio sin el modulo emisor']] as [$usuario, $desc]) {
    $tk = $token($sombra, $usuario);
    $r = $get($sombra, $tk);
    wa_chk("{$desc}: GET sigue abierto (200)", $r['status'] === 200 && !empty($data($r)['rnc']), $r);
    $r = $put($sombra, $tk, ['direccion' => 'Calle de un intruso 1']);
    wa_chk("{$desc}: PUT 403 con el texto del administrador y la base no cambia", $r['status'] === 403 && ($r['json']['error'] ?? null) === EMI_MSG_CONTROLLER && $fila() === $antesFila, [$r['status'], $r['body']]);
}
$denegados = $bitacora('ACCESS_DENIED');
wa_chk('los dos intentos quedaron en la bitacora como acceso denegado del modulo emisor, sin exito', count($denegados) > $nDenegados
    && ($denegados ? (int) end($denegados)['success'] : 1) === 0, array_slice($denegados, -2));
$tk = $token($sombra, 'wa_emi_contador');
$r = $put($sombra, $tk, ['sucursal' => 'Delegada']);
$f = $fila();
$ult = ($b = $bitacora('UPDATE')) ? end($b) : [];
wa_chk('un rol propio CON el modulo emisor si puede: 200 y el cambio queda a su nombre en la bitacora',
    $r['status'] === 200 && $f['sucursal'] === 'Delegada' && ($ult['username'] ?? null) === 'wa_emi_contador', [$r['body'], $ult['username'] ?? null]);
$r = $put($sombra, $token($sombra, 'wa_emi_admin'), ['sucursal' => 'Admin']);
wa_chk('el admin sigue pudiendo', $r['status'] === 200 && $fila()['sucursal'] === 'Admin', $r);

// ---------------------------------------------------------------------------
echo "\n== Quien puede: con el gate aplicado (PERMISSIONS_ENFORCE=true) ==\n";
$antesFila = $fila();
$tk = $token($gate, 'wa_emi_user');
$r = $get($gate, $tk);
wa_chk('user: GET 200 (ruta abierta a todo usuario)', $r['status'] === 200, $r);
$r = $put($gate, $tk, ['direccion' => 'Calle de un intruso 2']);
wa_chk('user: PUT 403 del gate y la base no cambia', $r['status'] === 403 && ($r['json']['error'] ?? null) === EMI_MSG_GATE && $fila() === $antesFila, [$r['status'], $r['body']]);
$r = $put($gate, $token($gate, 'wa_emi_solofact'), ['direccion' => 'Calle de un intruso 3']);
wa_chk('rol propio sin el modulo: PUT 403 y la base no cambia', $r['status'] === 403 && $fila() === $antesFila, [$r['status'], $r['body']]);
$r = $put($gate, $token($gate, 'wa_emi_contador'), ['sucursal' => 'Delegada 2']);
wa_chk('rol propio con el modulo: PUT 200', $r['status'] === 200 && $fila()['sucursal'] === 'Delegada 2', [$r['status'], $r['body']]);
$r = $put($gate, $token($gate, 'wa_emi_admin'), ['sucursal' => 'Admin 2', 'telefono' => '8495550000']);
$f = $fila();
wa_chk('admin: PUT 200 (el gate lo deja pasar con *)', $r['status'] === 200 && $f['sucursal'] === 'Admin 2' && $f['telefono'] === '849-555-0000', [$r['status'], $r['body']]);
$r = $put($gate, $token($gate, 'wa_emi_admin'), ['rnc' => '131000009']);
wa_chk('admin con el gate: el RNC sigue bloqueado (422)', $r['status'] === 422 && $fila()['rnc'] === $base0['rnc'], $r);

// ---------------------------------------------------------------------------
echo "\n== Otros metodos y sin credenciales ==\n";
$r = wa_http('PUT', $sombra . '/api/emisor', ['direccion' => 'Sin token 1']);
wa_chk('PUT sin credenciales: 401 y la base no cambia', $r['status'] === 401 && $fila()['direccion'] !== 'Sin token 1', $r);
foreach (['POST', 'DELETE'] as $metodo) {
    $r = wa_http($metodo, $sombra . '/api/emisor', $metodo === 'POST' ? ['direccion' => 'x'] : null, ['X-API-KEY: ' . $admin]);
    wa_chk("{$metodo} /api/emisor sigue siendo 405", $r['status'] === 405, $r);
}

wa_fin();
