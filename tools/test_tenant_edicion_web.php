<?php
/**
 * test_tenant_edicion_web.php — la version web de tools/tenant_edicion.php (src/Admin/TenantEdicionWeb.php, public/tenant_edicion.php y
 * public/tenant_edicion.html), sin MySQL ni red: manejar() recibe todo lo que lee (servidor, formulario, entorno, reloj, directorio de estado y la conexion a
 * la master) y devuelve [codigo HTTP, cuerpo], asi que cada puerta de seguridad se prueba con datos. La mitad con MySQL y un servidor de verdad es
 * tools/test_tenant_edicion_web_scratch.php.
 *
 * Que dice cada seccion:
 *   1) las puertas, en orden y antes de tocar la base: solo POST, la lista de IPs, HTTPS (o loopback, o el permiso explicito), el token configurado y largo, el
 *      token correcto con hash_equals; ninguna respuesta de error lleva el token y la conexion a la master NO se pide hasta autenticar.
 *   2) el freno al tanteo: 5 fallos de una IP (o 30 de cualquiera) en 15 minutos bloquean incluso al token correcto, con su Retry-After, hasta que pase la ventana;
 *      un acierto limpia a esa IP; un archivo de estado roto o que no se puede abrir no tumba nada.
 *   3) el analisis de los campos (parsear): como el analizador de la linea de comandos.
 *   4) la confirmacion del ensayo (HMAC) y el hash del plan: cualquier cambio de lo que cubren la invalida.
 *   5) ensayo y aplicar con una funcion que ejecuta la orden de mentira: la confirmacion solo se da si hay algo que aplicar y nada se rechaza; aplicar se rechaza
 *      (409) con una confirmacion falsificada, vencida, de otros datos o de otro plan, y repetirlo no aplica dos veces; lo aplicado deja la fila de auditoria
 *      de la web y un fallo de la auditoria no cambia la respuesta; un error de la orden sale con su descripcion y sin el token.
 *   6) lo que se lee del codigo: la entrada solo lee $_POST, la clase no toca superglobales ni pide la master antes de autenticar, la pagina no escribe HTML
 *      dinamico ni guarda el token, y el .env.example, la guia y el CLI (que sigue siendo solo CLI) estan como deben.
 *
 * Uso: php -d extension=pdo_sqlite -d extension=sqlite3 tools/test_tenant_edicion_web.php   (sale con 1 si algo falla; la extension solo si tu php.ini no la carga)
 */

require_once __DIR__ . '/write_ahead_test_lib.php';
require_once __DIR__ . '/../src/Admin/TenantEdicionWeb.php';

$raiz = dirname(__DIR__);
$token = 'tok-' . bin2hex(random_bytes(20));   // 44 caracteres
$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ted_web_' . bin2hex(random_bytes(4));
mkdir($base);
register_shutdown_function(static function () use ($base): void {
    foreach (glob($base . DIRECTORY_SEPARATOR . '*') ?: [] as $d) {
        foreach (glob($d . DIRECTORY_SEPARATOR . '*') ?: [] as $archivo) {
            @unlink($archivo);
        }
        @rmdir($d);
    }
    @rmdir($base);
});
/** Un directorio de estado nuevo (el freno al tanteo de una prueba no se mezcla con el de otra). */
$dirNuevo = static function () use ($base): string {
    $d = $base . DIRECTORY_SEPARATOR . bin2hex(random_bytes(3));
    mkdir($d);
    return $d;
};

$ahora0 = 1_800_000_000;
$llamadasMaster = 0;
$tieneSqlite = extension_loaded('pdo_sqlite');
$pdoPrueba = $tieneSqlite ? new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]) : null;
$crearAuditoria = static function () use ($pdoPrueba): void {
    $pdoPrueba->exec('DROP TABLE IF EXISTS audit_logs');
    $pdoPrueba->exec('CREATE TABLE audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INT, username TEXT, module TEXT, entity_type TEXT, entity_id TEXT, action TEXT,
        http_method TEXT, endpoint TEXT, ip_address TEXT, user_agent TEXT, new_values TEXT, description TEXT, success INT)');
};
if ($tieneSqlite) {
    $crearAuditoria();
}
$master = static function () use (&$llamadasMaster, $pdoPrueba): PDO {
    $llamadasMaster++;
    if ($pdoPrueba === null) {
        throw new RuntimeException('falta la extension pdo_sqlite: corre con -d extension=pdo_sqlite -d extension=sqlite3');
    }
    return $pdoPrueba;
};
$servidor = static fn (array $o = []): array => $o + ['REQUEST_METHOD' => 'POST', 'REMOTE_ADDR' => '203.0.113.7', 'HTTPS' => 'on', 'HTTP_USER_AGENT' => 'prueba/1.0'];
$entorno = static fn (array $o = []): array => $o + ['TENANT_EDICION_TOKEN' => $token];
$dormido = [];
$dormir = static function (int $micro) use (&$dormido): void {
    $dormido[] = $micro;
};
/** manejar() con los valores de siempre; cada argumento se cambia por nombre. @return array{0:int,1:array} */
$llamar = static function (array $post, array $o = []) use ($servidor, $entorno, $master, $dormir, $ahora0, $dirNuevo): array {
    return TenantEdicionWeb::manejar(
        $servidor($o['srv'] ?? []),
        $post,
        $entorno($o['env'] ?? []),
        $master,
        $o['ahora'] ?? $ahora0,
        $o['dir'] ?? $dirNuevo(),
        $dormir,
        $o['correr'] ?? null
    );
};
/** El texto de una respuesta de error. */
$error = static fn (array $r): string => (string) ($r[1]['error'] ?? '');

// ---------------------------------------------------------------------------
// 1) Las puertas
// ---------------------------------------------------------------------------
echo "\n== 1) Las puertas ==\n";
$respuestasDeError = [];
$r = TenantEdicionWeb::manejar($servidor(['REQUEST_METHOD' => 'GET']), [], $entorno(), $master, $ahora0, $dirNuevo(), $dormir);
$respuestasDeError[] = $r;
wa_chk('un GET es 405 y la conexion a la master no se pide', $r[0] === 405 && $r[1]['status'] === false && $llamadasMaster === 0, $r);
$r = $llamar(['token' => $token, 'action' => 'meta'], ['srv' => ['HTTPS' => '', 'REMOTE_ADDR' => '203.0.113.7']]);
$respuestasDeError[] = $r;
wa_chk('por HTTP desde fuera del servidor, aun con el token correcto: 403 "Usa HTTPS" y nada de base de datos', $r[0] === 403 && str_contains($error($r), 'HTTPS') && $llamadasMaster === 0, $r);
foreach ([
    'HTTPS=on' => ['HTTPS' => 'on'],
    'puerto 443' => ['HTTPS' => '', 'SERVER_PORT' => '443'],
    'X-Forwarded-Proto: https (detras de un proxy)' => ['HTTPS' => '', 'HTTP_X_FORWARDED_PROTO' => 'HTTPS'],
    'loopback 127.0.0.1 por http (para probar en local)' => ['HTTPS' => '', 'REMOTE_ADDR' => '127.0.0.1'],
    'loopback ::1 por http' => ['HTTPS' => '', 'REMOTE_ADDR' => '::1'],
] as $desc => $srv) {
    $r = $llamar(['token' => 'x', 'action' => 'meta'], ['srv' => $srv]);
    wa_chk("la puerta de HTTPS deja pasar con {$desc}: llega a la del token (403 Token invalido)", $r[0] === 403 && $error($r) === 'Token invalido.', $r);
}
$r = $llamar(['token' => 'x'], ['srv' => ['HTTPS' => 'off'], 'env' => ['TENANT_EDICION_PERMITIR_HTTP' => '1']]);
wa_chk('TENANT_EDICION_PERMITIR_HTTP=1 saca la puerta de HTTPS (y HTTPS=off cuenta como HTTP sin el)', $r[0] === 403 && $error($r) === 'Token invalido.', $r);
$r = $llamar(['token' => 'x'], ['srv' => ['HTTPS' => 'off']]);
wa_chk('HTTPS=off y puerto de siempre: HTTP', $r[0] === 403 && str_contains($error($r), 'HTTPS'), $r);
wa_chk('esHttps y esLoopback, directo',
    TenantEdicionWeb::esHttps(['HTTPS' => 'on']) && !TenantEdicionWeb::esHttps(['HTTPS' => 'off']) && !TenantEdicionWeb::esHttps([]) && TenantEdicionWeb::esHttps(['SERVER_PORT' => 443])
    && TenantEdicionWeb::esLoopback('127.0.0.1') && TenantEdicionWeb::esLoopback('::1') && !TenantEdicionWeb::esLoopback('10.0.0.1') && !TenantEdicionWeb::esLoopback(''));

// La lista de IPs: las demas reciben 403 sin que se mire el token.
$dormidoAntes = count($dormido);
$r = $llamar(['token' => 'malo'], ['env' => ['TENANT_EDICION_IPS' => '198.51.100.1, 203.0.113.9']]);
$respuestasDeError[] = $r;
wa_chk('con TENANT_EDICION_IPS, una IP que no esta recibe 403 "No autorizado." (el token ni se mira: no espera ni cuenta como fallo)', $r[0] === 403 && $error($r) === 'No autorizado.' && count($dormido) === $dormidoAntes, [$r, $dormido]);
$r = $llamar(['token' => 'malo'], ['env' => ['TENANT_EDICION_IPS' => '198.51.100.1, 203.0.113.7,']]);
wa_chk('...y una IP de la lista (con espacios y coma al final) llega a la puerta del token', $r[0] === 403 && $error($r) === 'Token invalido.', $r);

// El token configurado.
foreach (['sin la variable' => [], 'vacio' => ['TENANT_EDICION_TOKEN' => ''], 'el CAMBIAR del .env.example' => ['TENANT_EDICION_TOKEN' => 'CAMBIAR'], '31 caracteres' => ['TENANT_EDICION_TOKEN' => str_repeat('a', 31)]] as $desc => $env) {
    $r = TenantEdicionWeb::manejar($servidor(), ['token' => $env['TENANT_EDICION_TOKEN'] ?? '', 'action' => 'meta'], $env, $master, $ahora0, $dirNuevo(), $dormir);
    $respuestasDeError[] = $r;
    wa_chk("un token {$desc} en el server: 403 que dice que TENANT_EDICION_TOKEN no esta bien configurado (la herramienta esta apagada, tambien para quien manda ese mismo texto)",
        $r[0] === 403 && str_contains($error($r), 'TENANT_EDICION_TOKEN') && $llamadasMaster === 0, $r);
}
$corto32 = str_repeat('b', 32);
$r = TenantEdicionWeb::manejar($servidor(), ['token' => $corto32, 'action' => 'nada'], ['TENANT_EDICION_TOKEN' => $corto32], $master, $ahora0, $dirNuevo(), $dormir);
wa_chk('un token de 32 caracteres si vale: llega a la accion (400, accion desconocida)', $r[0] === 400 && str_contains($error($r), 'Accion desconocida'), $r);

// El token de la peticion.
$dormido = [];
$r = $llamar(['token' => $token . 'x', 'action' => 'meta']);
$respuestasDeError[] = $r;
wa_chk('un token malo: 403 "Token invalido." despues de esperar medio segundo (frena el tanteo) y sin tocar la base', $r[0] === 403 && $error($r) === 'Token invalido.' && $dormido === [TenantEdicionWeb::DEMORA_TOKEN_MALO] && $llamadasMaster === 0, [$r, $dormido]);
foreach (['vacio' => '', 'ausente' => null, 'una lista (token[]=x)' => ['x'], 'un prefijo del bueno' => substr($token, 0, 20)] as $desc => $malo) {
    $post = ['action' => 'meta'] + ($malo === null ? [] : ['token' => $malo]);
    [$resultado, $err, $log] = wa_con_log(static fn (): array => $llamar($post));
    wa_chk("un token {$desc}: 403 Token invalido, sin avisos de PHP (un arreglo no se convierte en texto)", $err === null && $resultado[0] === 403 && $error($resultado) === 'Token invalido.' && $log === '' || str_contains($log, '[TenantEdicionWeb] token invalido') && !str_contains($log, 'Array to string'), [$resultado, $err?->getMessage(), $log]);
}
$r = $llamar(['token' => $token]);
wa_chk('con el token correcto y sin accion: 400 "Accion desconocida"', $r[0] === 400 && str_contains($error($r), 'Accion desconocida'), $r);
$r = $llamar(['token' => $token, 'action' => ['meta']]);
wa_chk('...y una accion que es una lista tampoco pasa', $r[0] === 400, $r);
wa_chk('la conexion a la master no se pidio en ninguna de las puertas de arriba', $llamadasMaster === 0, $llamadasMaster);
$fugas = array_values(array_filter($respuestasDeError, static fn (array $x): bool => str_contains((string) json_encode($x), $token)));
wa_chk('ninguna respuesta de error lleva el token (ni completo ni escapado)', $fugas === [] && !str_contains((string) json_encode($respuestasDeError), substr($token, 4, 20)), count($respuestasDeError));

// ---------------------------------------------------------------------------
// 2) El freno al tanteo
// ---------------------------------------------------------------------------
echo "\n== 2) El freno al tanteo ==\n";
$dir = $dirNuevo();
$ipA = ['REMOTE_ADDR' => '203.0.113.50'];
$ipB = ['REMOTE_ADDR' => '203.0.113.51'];
for ($i = 0; $i < TenantEdicionWeb::MAX_FALLOS_IP; $i++) {
    $r = $llamar(['token' => 'malo'], ['srv' => $ipA, 'dir' => $dir, 'ahora' => $ahora0 + $i]);
}
wa_chk('cinco tokens malos de una IP dan 403 los cinco (el quinto todavia contesta Token invalido)', $r[0] === 403 && $error($r) === 'Token invalido.', $r);
$r = $llamar(['token' => $token, 'action' => 'nada'], ['srv' => $ipA, 'dir' => $dir, 'ahora' => $ahora0 + 5]);
wa_chk('el sexto intento de esa IP, aun con el token CORRECTO, es 429 con su retry_after', $r[0] === 429 && is_int($r[1]['retry_after'] ?? null) && $r[1]['retry_after'] > 0 && $r[1]['retry_after'] <= TenantEdicionWeb::VENTANA, $r);
wa_chk('...y el retry_after es lo que falta para que el fallo mas viejo salga de la ventana (900 s despues del primero)', ($r[1]['retry_after'] ?? null) === TenantEdicionWeb::VENTANA - 5, $r[1]['retry_after'] ?? null);
$r = $llamar(['token' => $token, 'action' => 'nada'], ['srv' => $ipB, 'dir' => $dir, 'ahora' => $ahora0 + 5]);
wa_chk('otra IP no se bloquea por las de la primera: llega a la accion', $r[0] === 400 && str_contains($error($r), 'Accion desconocida'), $r);
$r = $llamar(['token' => $token, 'action' => 'nada'], ['srv' => $ipA, 'dir' => $dir, 'ahora' => $ahora0 + TenantEdicionWeb::VENTANA + 1]);
wa_chk('pasada la ventana (15 minutos y un segundo) la IP vuelve a entrar con el token correcto', $r[0] === 400, $r);
$dir = $dirNuevo();
for ($i = 0; $i < 4; $i++) {
    $llamar(['token' => 'malo'], ['srv' => $ipA, 'dir' => $dir, 'ahora' => $ahora0 + $i]);
}
$llamar(['token' => $token, 'action' => 'nada'], ['srv' => $ipA, 'dir' => $dir, 'ahora' => $ahora0 + 10]);
for ($i = 0; $i < 4; $i++) {
    $r = $llamar(['token' => 'malo'], ['srv' => $ipA, 'dir' => $dir, 'ahora' => $ahora0 + 20 + $i]);
}
$r = $llamar(['token' => $token, 'action' => 'nada'], ['srv' => $ipA, 'dir' => $dir, 'ahora' => $ahora0 + 30]);
wa_chk('un acierto limpia los fallos de esa IP: cuatro, un acierto y otros cuatro no bloquean', $r[0] === 400, $r);
$dir = $dirNuevo();
for ($i = 0; $i < TenantEdicionWeb::MAX_FALLOS_TOTAL; $i++) {
    $llamar(['token' => 'malo'], ['srv' => ['REMOTE_ADDR' => '198.51.100.' . ($i + 1)], 'dir' => $dir, 'ahora' => $ahora0 + $i]);
}
$r = $llamar(['token' => $token, 'action' => 'nada'], ['srv' => ['REMOTE_ADDR' => '192.0.2.99'], 'dir' => $dir, 'ahora' => $ahora0 + 31]);
wa_chk('treinta fallos de treinta IPs distintas bloquean a todas (una IP nueva con el token correcto recibe 429)', $r[0] === 429 && $r[1]['retry_after'] === TenantEdicionWeb::VENTANA - 31, $r);
$dir = $dirNuevo();
for ($i = 0; $i < 5; $i++) {
    $llamar(['token' => 'malo'], ['srv' => $ipA, 'dir' => $dir, 'ahora' => 1000 + $i]);
}
$r = $llamar(['token' => $token], ['srv' => $ipA, 'dir' => $dir, 'ahora' => 1004]);
wa_chk('cinco fallos en 1000..1004 y ahora = 1004: faltan 896 segundos (1000 + 900 - 1004)', $r[0] === 429 && $r[1]['retry_after'] === 896, $r);
// el archivo de estado: roto, o en un directorio que no existe
$dir = $dirNuevo();
$archivoEstado = $dir . DIRECTORY_SEPARATOR . 'tenant_edicion_web_' . substr(hash('sha256', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Admin'), 0, 12) . '.json';
file_put_contents($archivoEstado, '{esto no es json');
[$r, $err, $log] = wa_con_log(static fn (): array => $llamar(['token' => $token, 'action' => 'nada'], ['dir' => $dir]));
wa_chk('un archivo de estado roto se toma como vacio: no tumba la peticion', $err === null && $r[0] === 400, [$r, $err?->getMessage()]);
[$r, $err, $log] = wa_con_log(static fn (): array => $llamar(['token' => $token, 'action' => 'nada'], ['dir' => $base . DIRECTORY_SEPARATOR . 'no-existe']));
wa_chk('un directorio de estado que no existe: la herramienta sigue (sin freno) y lo deja en el log una vez por llamada', $err === null && $r[0] === 400 && str_contains($log, 'freno al tanteo'), [$r, $err?->getMessage(), $log]);

// ---------------------------------------------------------------------------
// 3) El analisis de los campos
// ---------------------------------------------------------------------------
echo "\n== 3) El analisis de los campos ==\n";
$validos = [
    'express con tipos y modulos a mano (espacios, coma al final)' => [['tenant' => '12', 'modo' => 'express', 'tipos' => '45, 31,', 'modulos' => 'facturacion, clientes'], ['modo' => 'express', 'tenant' => 12, 'tipos' => ['45', '31'], 'modulos' => ['facturacion', 'clientes']]],
    'ambas sin modulos (null: todo el preset)' => [['tenant' => '0012', 'modo' => 'ambas', 'tipos' => '45,45,31'], ['modo' => 'ambas', 'tenant' => 12, 'tipos' => ['45', '31'], 'modulos' => null]],
    'ambas con modulos vacio (tambien null)' => [['tenant' => '7', 'modo' => 'ambas', 'tipos' => '32', 'modulos' => '  '], ['modo' => 'ambas', 'tenant' => 7, 'tipos' => ['32'], 'modulos' => null]],
    'un tipo mal escrito llega tal cual (lo rechaza el preflight, no el analizador)' => [['tenant' => '1', 'modo' => 'express', 'tipos' => 'e45'], ['modo' => 'express', 'tenant' => 1, 'tipos' => ['e45'], 'modulos' => null]],
    'completa' => [['tenant' => '5', 'modo' => 'completa'], ['modo' => 'completa', 'tenant' => 5, 'tipos' => [], 'modulos' => null]],
    'refrescar-cert' => [['tenant' => '5', 'modo' => 'refrescar-cert', 'tipos' => '', 'modulos' => ''], ['modo' => 'refrescar-cert', 'tenant' => 5, 'tipos' => [], 'modulos' => null]],
];
foreach ($validos as $desc => [$post, $esperado]) {
    wa_chk("parsear, {$desc}", TenantEdicionWeb::parsear($post) === $esperado, TenantEdicionWeb::parsear($post));
}
foreach ([
    'sin tenant' => [['modo' => 'completa'], 'tenant'],
    'tenant vacio' => [['tenant' => '', 'modo' => 'completa'], 'tenant'],
    'tenant que no es un numero' => [['tenant' => 'abc', 'modo' => 'completa'], 'tenant'],
    'tenant 0' => [['tenant' => '0', 'modo' => 'completa'], 'tenant'],
    'tenant negativo' => [['tenant' => '-3', 'modo' => 'completa'], 'tenant'],
    'tenant con decimales' => [['tenant' => '1.5', 'modo' => 'completa'], 'tenant'],
    'tenant con basura detras' => [['tenant' => '12abc', 'modo' => 'completa'], 'tenant'],
    'tenant de once cifras' => [['tenant' => '99999999999', 'modo' => 'completa'], 'tenant'],
    'tenant que es una lista' => [['tenant' => ['12'], 'modo' => 'completa'], 'tenant'],
    'modo en mayusculas' => [['tenant' => '1', 'modo' => 'Express', 'tipos' => '45'], 'modo'],
    'modo que no existe' => [['tenant' => '1', 'modo' => 'basica'], 'modo'],
    'sin modo' => [['tenant' => '1'], 'modo'],
    'express sin tipos' => [['tenant' => '1', 'modo' => 'express'], 'al menos un tipo'],
    'ambas con tipos solo comas' => [['tenant' => '1', 'modo' => 'ambas', 'tipos' => ' , ,'], 'al menos un tipo'],
    'tipos que es una lista' => [['tenant' => '1', 'modo' => 'express', 'tipos' => ['45']], 'texto'],
    'completa con tipos' => [['tenant' => '1', 'modo' => 'completa', 'tipos' => '45'], 'solo valen con express o ambas'],
    'refrescar-cert con modulos' => [['tenant' => '1', 'modo' => 'refrescar-cert', 'modulos' => 'clientes'], 'solo valen con express o ambas'],
] as $desc => [$post, $aguja]) {
    $p = TenantEdicionWeb::parsear($post);
    wa_chk("parsear, {$desc}: un error que dice '{$aguja}'", array_keys($p) === ['error'] && str_contains($p['error'], $aguja), $p);
}

// ---------------------------------------------------------------------------
// 4) La confirmacion del ensayo y el hash del plan
// ---------------------------------------------------------------------------
echo "\n== 4) La confirmacion del ensayo y el hash del plan ==\n";
$p0 = ['modo' => 'ambas', 'tenant' => 12, 'tipos' => ['45', '31'], 'modulos' => null];
$h0 = TenantEdicionWeb::hashDelPlan([['donde' => 'master', 'que' => 'texto', 'sql' => 'UPDATE tenants SET edicion = :e WHERE id = :id', 'params' => [':e' => 'ambas', ':id' => 12]]]);
$c0 = TenantEdicionWeb::confirmacion($token, $p0, $h0, $ahora0);
wa_chk('la confirmacion es "<emitido>.<64 hex>", determinista, y arranca con el momento', preg_match('/^[0-9]{9,12}\.[0-9a-f]{64}$/D', $c0) === 1 && $c0 === TenantEdicionWeb::confirmacion($token, $p0, $h0, $ahora0) && str_starts_with($c0, $ahora0 . '.'), $c0);
$variantes = [
    'otra empresa' => TenantEdicionWeb::confirmacion($token, ['tenant' => 13] + $p0, $h0, $ahora0),
    'otro modo' => TenantEdicionWeb::confirmacion($token, ['modo' => 'express'] + $p0, $h0, $ahora0),
    'otros tipos' => TenantEdicionWeb::confirmacion($token, ['tipos' => ['45']] + $p0, $h0, $ahora0),
    'los mismos tipos en otro orden' => TenantEdicionWeb::confirmacion($token, ['tipos' => ['31', '45']] + $p0, $h0, $ahora0),
    'otros modulos' => TenantEdicionWeb::confirmacion($token, ['modulos' => ['clientes']] + $p0, $h0, $ahora0),
    'otro plan' => TenantEdicionWeb::confirmacion($token, $p0, TenantEdicionWeb::hashDelPlan([]), $ahora0),
    'otro momento' => TenantEdicionWeb::confirmacion($token, $p0, $h0, $ahora0 + 1),
    'otra clave' => TenantEdicionWeb::confirmacion($token . 'x', $p0, $h0, $ahora0),
];
foreach ($variantes as $desc => $c) {
    wa_chk("la confirmacion cambia con {$desc}", $c !== $c0 && preg_match('/^[0-9]{9,12}\.[0-9a-f]{64}$/D', $c) === 1, $c);
}
wa_chk('modulos null y lista vacia firman igual (los dos son "todo el preset")', TenantEdicionWeb::confirmacion($token, $p0, $h0, $ahora0) === TenantEdicionWeb::confirmacion($token, ['modulos' => []] + $p0, $h0, $ahora0));
$plan1 = [['donde' => 'master', 'que' => 'uno', 'sql' => 'UPDATE a', 'params' => [':x' => 1]], ['donde' => 'tenant', 'que' => 'dos', 'sql' => 'DELETE b', 'params' => []]];
wa_chk('hashDelPlan: estable, sensible al orden, al sql, a los parametros y a donde corre, e ignora el texto de que',
    TenantEdicionWeb::hashDelPlan($plan1) === TenantEdicionWeb::hashDelPlan($plan1)
    && TenantEdicionWeb::hashDelPlan($plan1) === TenantEdicionWeb::hashDelPlan([['que' => 'otro texto'] + $plan1[0], $plan1[1]])
    && TenantEdicionWeb::hashDelPlan($plan1) !== TenantEdicionWeb::hashDelPlan(array_reverse($plan1))
    && TenantEdicionWeb::hashDelPlan($plan1) !== TenantEdicionWeb::hashDelPlan([['sql' => 'UPDATE z'] + $plan1[0], $plan1[1]])
    && TenantEdicionWeb::hashDelPlan($plan1) !== TenantEdicionWeb::hashDelPlan([['params' => [':x' => 2]] + $plan1[0], $plan1[1]])
    && TenantEdicionWeb::hashDelPlan($plan1) !== TenantEdicionWeb::hashDelPlan([$plan1[0], ['donde' => 'master'] + $plan1[1]])
    && preg_match('/^[0-9a-f]{64}$/D', TenantEdicionWeb::hashDelPlan([])) === 1);

// ---------------------------------------------------------------------------
// 5) Ensayo y aplicar, con una orden de mentira
// ---------------------------------------------------------------------------
echo "\n== 5) Ensayo y aplicar ==\n";
$planFalso = [['donde' => 'master', 'que' => 'Pasar la empresa a ambas', 'sql' => "UPDATE tenants SET edicion = 'ambas' WHERE id = :id", 'params' => [':id' => 12]]];
$llamadasOrden = [];
/** Una orden de mentira: anota (apply, modo, tenant) y devuelve lo que se le diga; $plan() da el plan de cada llamada. */
$orden = static function (callable $plan, bool $ok = true) use (&$llamadasOrden): callable {
    return static function (PDO $pdo, array $p, bool $apply) use ($plan, $ok, &$llamadasOrden): array {
        $llamadasOrden[] = [$apply, $p['modo'], $p['tenant']];
        return ['ok' => $ok, 'aplicado' => $ok && $apply, 'plan' => $plan(count($llamadasOrden)),
            'lineas' => ['[CORR] edicion: pasa de completa a ambas.', $apply ? 'APLICADO: el tenant #' . $p['tenant'] . ' ahora es ambas.' : 'DRY-RUN: no se escribio nada. Agrega --apply para aplicar.']];
    };
};
$postEnsayo = ['token' => $token, 'action' => 'ensayo', 'tenant' => '12', 'modo' => 'ambas', 'tipos' => '45,31', 'modulos' => ''];
$postAplicar = static fn (string $confirmacion, array $o = []): array => $o + ['token' => $token, 'action' => 'aplicar', 'tenant' => '12', 'modo' => 'ambas', 'tipos' => '45,31', 'modulos' => '', 'confirmacion' => $confirmacion];

$llamadasOrden = [];
$r = $llamar($postEnsayo, ['correr' => $orden(static fn (int $n): array => $planFalso)]);
$d = $r[1]['data'] ?? [];
wa_chk('ensayo: 200, ok, nada aplicado, las lineas de la orden, un paso y la confirmacion; la orden corrio una vez y sin escribir',
    $r[0] === 200 && ($d['ok'] ?? null) === true && ($d['aplicado'] ?? null) === false && ($d['pasos'] ?? null) === 1 && count($d['lineas'] ?? []) === 2
    && preg_match('/^[0-9]{9,12}\.[0-9a-f]{64}$/D', (string) ($d['confirmacion'] ?? '')) === 1 && $llamadasOrden === [[false, 'ambas', 12]], [$r, $llamadasOrden]);
wa_chk('...y el cuerpo no trae el plan ni su sql (solo las lineas y la cuenta de pasos)', !str_contains((string) json_encode($r[1]), 'UPDATE tenants') && !array_key_exists('plan', $d), $r[1]);
$confirmacionBuena = (string) ($d['confirmacion'] ?? '');

$r = $llamar($postEnsayo, ['correr' => $orden(static fn (int $n): array => $planFalso, false)]);
wa_chk('ensayo que la biblioteca rechaza (ok false): 200 pero sin confirmacion', $r[0] === 200 && ($r[1]['data']['ok'] ?? null) === false && array_key_exists('confirmacion', $r[1]['data']) && $r[1]['data']['confirmacion'] === null, $r);
$r = $llamar($postEnsayo, ['correr' => $orden(static fn (int $n): array => [])]);
wa_chk('ensayo sin nada que aplicar (plan vacio, un SIN CAMBIOS): ok pero sin confirmacion', $r[0] === 200 && ($r[1]['data']['ok'] ?? null) === true && array_key_exists('confirmacion', $r[1]['data']) && $r[1]['data']['confirmacion'] === null && $r[1]['data']['pasos'] === 0, $r);
$r = $llamar(['modo' => 'ambas'] + $postEnsayo, ['correr' => $orden(static fn (int $n): array => $planFalso)]);
$r2 = $llamar(['tipos' => ''] + $postEnsayo, ['correr' => $orden(static fn (int $n): array => $planFalso)]);
wa_chk('ensayo con campos mal puestos: 400 antes de correr nada (un ambas sin tipos)', $r2[0] === 400 && str_contains($error($r2), 'al menos un tipo'), $r2);

// aplicar, camino feliz
if ($tieneSqlite) {
    $crearAuditoria();
}
$llamadasOrden = [];
$r = $llamar($postAplicar($confirmacionBuena), ['correr' => $orden(static fn (int $n): array => $planFalso), 'srv' => ['HTTP_X_FORWARDED_FOR' => '198.51.100.20, 10.0.0.1']]);
$d = $r[1]['data'] ?? [];
wa_chk('aplicar con la confirmacion del ensayo: 200, aplicado, las lineas con APLICADO; la orden corrio dos veces (el ensayo de comparacion y el aplicar)',
    $r[0] === 200 && ($d['ok'] ?? null) === true && ($d['aplicado'] ?? null) === true && str_starts_with((string) ($d['lineas'][1] ?? ''), 'APLICADO:') && $llamadasOrden === [[false, 'ambas', 12], [true, 'ambas', 12]], [$r, $llamadasOrden]);
$fila = $tieneSqlite ? $pdoPrueba->query('SELECT * FROM audit_logs')->fetchAll() : [];
wa_chk('...y deja UNA fila de auditoria de la web: modulo edicion_web, accion WEB_APLICAR, la empresa, la IP del primer X-Forwarded-For, el navegador y los parametros (sin el token)',
    count($fila) === 1 && $fila[0]['module'] === 'edicion_web' && $fila[0]['action'] === 'WEB_APLICAR' && (int) $fila[0]['tenant_id'] === 12 && $fila[0]['username'] === 'web:tenant_edicion'
    && $fila[0]['ip_address'] === '198.51.100.20' && $fila[0]['user_agent'] === 'prueba/1.0' && (int) $fila[0]['success'] === 1 && str_contains((string) $fila[0]['new_values'], '"modo":"ambas"')
    && !str_contains((string) json_encode($fila), $token), $fila);

// aplicar, lo que se rechaza (409) y no corre el aplicar
$casos = [
    'una confirmacion con una letra cambiada' => [$postAplicar(substr($confirmacionBuena, 0, -1) . (str_ends_with($confirmacionBuena, '0') ? '1' : '0')), static fn (int $n): array => $planFalso],
    'otros tipos que los del ensayo' => [$postAplicar($confirmacionBuena, ['tipos' => '45']), static fn (int $n): array => $planFalso],
    'otra empresa que la del ensayo' => [$postAplicar($confirmacionBuena, ['tenant' => '13']), static fn (int $n): array => $planFalso],
    'otro modo que el del ensayo' => [$postAplicar($confirmacionBuena, ['modo' => 'express']), static fn (int $n): array => $planFalso],
    'otros modulos que los del ensayo' => [$postAplicar($confirmacionBuena, ['modulos' => 'clientes']), static fn (int $n): array => $planFalso],
    'un plan distinto al del ensayo (la empresa cambio en medio)' => [$postAplicar($confirmacionBuena), static fn (int $n): array => [['sql' => 'OTRO'] + $planFalso[0]]],
    'una confirmacion hecha con otra clave' => [$postAplicar(TenantEdicionWeb::confirmacion('otra-clave-' . $token, ['modo' => 'ambas', 'tenant' => 12, 'tipos' => ['45', '31'], 'modulos' => null], TenantEdicionWeb::hashDelPlan($planFalso), $ahora0)), static fn (int $n): array => $planFalso],
];
foreach ($casos as $desc => [$post, $plan]) {
    $llamadasOrden = [];
    if ($tieneSqlite) {
        $crearAuditoria();
    }
    $r = $llamar($post, ['correr' => $orden($plan)]);
    $filas = $tieneSqlite ? (int) $pdoPrueba->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn() : 0;
    wa_chk("aplicar con {$desc}: 409, la orden no se corre con apply y no hay fila de auditoria",
        $r[0] === 409 && $r[1]['status'] === false && !in_array(true, array_column($llamadasOrden, 0), true) && $filas === 0, [$r, $llamadasOrden]);
}
foreach ([
    'sin confirmacion' => '',
    'una confirmacion mal formada' => 'esto-no-es-una-confirmacion',
    'una confirmacion sin la firma' => (string) $ahora0 . '.',
] as $desc => $c) {
    $llamadasOrden = [];
    $r = $llamar($postAplicar($c), ['correr' => $orden(static fn (int $n): array => $planFalso)]);
    wa_chk("aplicar {$desc}: 400 y la orden ni se corre", $r[0] === 400 && $llamadasOrden === [], [$r, $llamadasOrden]);
}
$llamadasOrden = [];
$r = $llamar($postAplicar($confirmacionBuena), ['correr' => $orden(static fn (int $n): array => $planFalso), 'ahora' => $ahora0 + TenantEdicionWeb::CONFIRMACION_VIGENCIA + 1]);
wa_chk('aplicar con un ensayo de hace 601 segundos: 409 "ya vencio" y la orden ni se corre', $r[0] === 409 && str_contains($error($r), 'vencio') && $llamadasOrden === [], [$r, $llamadasOrden]);
$llamadasOrden = [];
$r = $llamar($postAplicar($confirmacionBuena), ['correr' => $orden(static fn (int $n): array => $planFalso), 'ahora' => $ahora0 + TenantEdicionWeb::CONFIRMACION_VIGENCIA]);
wa_chk('...y con uno de hace exactamente 600 segundos todavia vale', $r[0] === 200 && ($r[1]['data']['aplicado'] ?? null) === true, $r);
$llamadasOrden = [];
$r = $llamar($postAplicar($confirmacionBuena), ['correr' => $orden(static fn (int $n): array => $planFalso), 'ahora' => $ahora0 - TenantEdicionWeb::CONFIRMACION_ADELANTO - 1]);
wa_chk('una confirmacion del futuro (mas de 60 segundos de adelanto) tampoco vale', $r[0] === 409 && $llamadasOrden === [], [$r, $llamadasOrden]);
$llamadasOrden = [];
$r = $llamar($postAplicar($confirmacionBuena), ['correr' => $orden(static fn (int $n): array => [])]);
wa_chk('aplicar cuando ya no hay nada que aplicar (el plan de ahora esta vacio: se repitio la peticion): 409 y no se aplica dos veces', $r[0] === 409 && str_contains($error($r), 'nada que aplicar') && !in_array(true, array_column($llamadasOrden, 0), true), [$r, $llamadasOrden]);
$llamadasOrden = [];
$r = $llamar($postAplicar($confirmacionBuena), ['correr' => $orden(static fn (int $n): array => $planFalso, false)]);
wa_chk('...y cuando el ensayo de ahora rechaza (ok false): tambien 409', $r[0] === 409 && !in_array(true, array_column($llamadasOrden, 0), true), [$r, $llamadasOrden]);

// un error de la orden y un fallo de la auditoria
$r = $llamar($postEnsayo, ['correr' => static function (PDO $pdo, array $p, bool $apply): array {
    throw new RuntimeException('no se pudo leer la fila de la empresa');
}]);
wa_chk('un error de la orden: 500 con la descripcion de la excepcion (clase y mensaje de una linea) y sin el token', $r[0] === 500 && str_contains($error($r), 'RuntimeException') && !str_contains((string) json_encode($r), $token), $r);
if ($tieneSqlite) {
    $pdoPrueba->exec('DROP TABLE audit_logs');   // sin la tabla, la fila de auditoria de la web falla
}
$llamadasOrden = [];
[$r, $err, $log] = wa_con_log(static fn (): array => $llamar($postAplicar($confirmacionBuena), ['correr' => $orden(static fn (int $n): array => $planFalso)]));
wa_chk('si la fila de auditoria de la web falla, la respuesta es la misma (200, aplicado) y el fallo queda en el log', $err === null && $r[0] === 200 && ($r[1]['data']['aplicado'] ?? null) === true && str_contains($log, 'no se pudo escribir la fila de auditoria de la web'), [$r, $err?->getMessage(), $log]);

// ---------------------------------------------------------------------------
// 6) Lo que se lee del codigo
// ---------------------------------------------------------------------------
echo "\n== 6) Lo que se lee del codigo ==\n";
$leer = static fn (string $ruta): string => is_file($raiz . '/' . $ruta) ? str_replace("\r\n", "\n", (string) file_get_contents($raiz . '/' . $ruta)) : '';
/** El codigo PHP de un archivo sin comentarios (un comentario puede nombrar $_POST sin usarlo). */
$sinComentarios = static function (string $fuente): string {
    $salida = '';
    foreach (token_get_all($fuente) as $t) {
        $salida .= is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t;
    }
    return $salida;
};
$entrada = $sinComentarios($leer('public/tenant_edicion.php'));
$clase = $sinComentarios($leer('src/Admin/TenantEdicionWeb.php'));
wa_chk('public/tenant_edicion.php: lee el formulario de $_POST (nunca $_GET ni $_REQUEST: el token no va en la URL), carga el .env, y manda no-store, nosniff y noindex',
    str_contains($entrada, '$_POST') && !preg_match('/\$_(GET|REQUEST|COOKIE|SESSION)/', $entrada) && str_contains($entrada, 'Database::loadEnv()')
    && str_contains($entrada, "'Cache-Control: no-store'") && str_contains($entrada, "'X-Content-Type-Options: nosniff'") && str_contains($entrada, 'noindex'), $entrada);
wa_chk('...sin ninguna cabecera CORS (otro origen no puede leer las respuestas) y pide la conexion a la master con express_conectar_master dentro de una funcion (perezosa)',
    !str_contains($entrada, 'Access-Control') && preg_match('/static fn \(\): PDO => express_conectar_master\(/', $entrada) === 1, null);
wa_chk('src/Admin/TenantEdicionWeb.php: no toca ninguna superglobal (todo entra por argumentos), no hace exit ni imprime',
    !preg_match('/\$_(GET|POST|REQUEST|SERVER|ENV|COOKIE|SESSION|FILES)\b/', $clase) && !preg_match('/\b(exit|die)\s*[;(]/', $clase) && !preg_match('/\b(echo|print|printf|var_dump|print_r)\b/', $clase), null);
$posToken = strpos($clase, 'hash_equals($esperado, self::texto($post');
$primeraMaster = strpos($clase, '$master()');
wa_chk('la primera vez que se pide la master ($master()) esta DESPUES de la comparacion del token: ninguna peticion sin token valido toca la base',
    $posToken !== false && $primeraMaster !== false && $posToken < $primeraMaster && substr_count($clase, '$master()') >= 4, [$posToken, $primeraMaster]);
wa_chk('la clase compara el token y la confirmacion con hash_equals, y nunca con == ni === contra lo que manda el cliente',
    str_contains($clase, 'hash_equals($esperado, self::texto($post') && str_contains($clase, 'hash_equals(self::confirmacion(')
    && !preg_match('/(\$post|self::texto\([^)]*\)|\$recibida)\s*[=!]==?/', $clase) && !preg_match('/[=!]==?\s*(\$post|self::texto\(|\$recibida)/', $clase), null);
$html = $leer('public/tenant_edicion.html');
wa_chk('public/tenant_edicion.html: ningun HTML dinamico (innerHTML, outerHTML, insertAdjacentHTML, document.write, eval, new Function) y todo texto entra con textContent',
    $html !== '' && !preg_match('/innerHTML|outerHTML|insertAdjacentHTML|document\.write|\beval\s*\(|new Function/', $html) && str_contains($html, 'textContent'), null);
wa_chk('...no guarda el token (ni localStorage, ni sessionStorage, ni cookies), manda las peticiones sin credenciales y solo a tenant_edicion.php',
    !preg_match('/localStorage|sessionStorage|document\.cookie/', $html) && str_contains($html, "credentials: 'omit'") && substr_count($html, 'fetch(') === 1 && str_contains($html, "fetch('tenant_edicion.php'"), null);
wa_chk("...y trae su CSP (default-src 'none', solo connect-src 'self'), no carga nada de afuera y se niega a mostrarse dentro de un marco",
    str_contains($html, "default-src 'none'") && str_contains($html, "connect-src 'self'") && !preg_match('/(src|href)\s*=\s*"https?:/i', $html) && !preg_match('/<(script|link|img|iframe)[^>]*\s(src|href)=/i', $html)
    && str_contains($html, 'window.top !== window.self'), null);
wa_chk('el .env.example lista TENANT_EDICION_TOKEN=CAMBIAR (la herramienta nace apagada) y las dos variables opcionales',
    str_contains($leer('.env.example'), "TENANT_EDICION_TOKEN=CAMBIAR") && str_contains($leer('.env.example'), 'TENANT_EDICION_IPS') && str_contains($leer('.env.example'), 'TENANT_EDICION_PERMITIR_HTTP'), null);
wa_chk('tools/tenant_edicion.php sigue siendo solo CLI (el 403 de PHP_SAPI) y nombra a su version web',
    str_contains($leer('tools/tenant_edicion.php'), "PHP_SAPI !== 'cli'") && str_contains($leer('tools/tenant_edicion.php'), 'public/tenant_edicion.php'), null);
wa_chk('la guia docs/integrations/tenant-edicion-web.md existe y explica el token, HTTPS, el freno, la confirmacion y como apagarla',
    ($guia = $leer('docs/integrations/tenant-edicion-web.md')) !== '' && str_contains($guia, 'TENANT_EDICION_TOKEN') && str_contains($guia, 'HTTPS') && str_contains($guia, '429')
    && str_contains($guia, 'confirmaci') && str_contains($guia, 'Basic Auth') && stripos($guia, 'apagar') !== false, null);

wa_fin();
