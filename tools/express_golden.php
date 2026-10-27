<?php
/**
 * express_golden.php — Captura dorada de Express (P0): lo que el API responde HOY a
 * un tenant `completa` (el tenant A de las bases scratch del POS, ambiente testecf),
 * guardado para que los planes 02 a 05 prueben que no cambiaron nada de lo existente.
 * Compara estados HTTP y la FORMA de los JSON (claves y tipos de valor, ver
 * tools/express_golden_lib.php): los valores son volatiles (tokens, ids, fechas,
 * e-NCF), asi que "identico para los tenants existentes" se prueba por estructura.
 *
 * Por cada modo (sombra = PERMISSIONS_ENFORCE=false en el 8399, enforce = true en el
 * 8397; el simulador de la DGII, tools/mock_dgii.php, en el 8398; todos mas
 * WA_PUERTO_OFFSET) la herramienta:
 *   1. deja el tenant en un estado fijo (golden_normalizar_tenant: wa_limpiar, vacia
 *      clients, products, ncf_sequences y emisor_config y los vuelve a sembrar con
 *      pos_scratch_preparar) y corre el flujo: login de
 *      admin y user, me, traspaso al POS y su canje, emision E31 aceptada y rechazada,
 *      detalle del rechazo, reenvio, lista, stats, avisos, estado, catalogos por
 *      defecto, usuarios, rangos de NCF y signout (ids de paso: pasos[].id);
 *   2. arma la matriz de la compuerta: GET a la clave de cada ruta de
 *      config/permissions.php como admin, user y anonimo; en enforce ademas las
 *      sondas de escritura de golden_matriz_escrituras() (cuerpo vacio: se rechazan
 *      antes de tocar nada; si cambia el conteo de facturas, clients o products la
 *      herramienta sale con 2 nombrando la tabla).
 *
 * Los dos API corren con output_buffering=4096 (GOLDEN_FLAGS_API), como un php.ini de
 * produccion: sin eso php -S deja en 200 las respuestas que hacen echo antes de
 * http_response_code (PUT y DELETE de /api/facturas dan 405 en un cliente real).
 *
 * Archivo guardado: tools/express_golden/golden.json
 *   {version, meta:{git_sha, php, capturado}, rutas:[claves de routes],
 *    modos:{sombra:{pasos:[golden_paso...], gate:[{metodo, ruta, rol, http}]}, enforce:{...}}}
 * Solo lleva estados y formas: ningun token, clave ni valor de la base.
 *
 * Estado fijo: la forma de una lista es la union de las formas de sus filas, y las otras
 * suites de las bases scratch dejan filas (clientes sin direccion, productos con categoria,
 * rangos sin vencimiento) que cambian esa union. Por eso cada flujo parte de un tenant A con
 * EXACTAMENTE la siembra de wa_sembrar y de pos_scratch_preparar (un cliente, un producto,
 * rangos certecf E31-E34 y testecf E31/E32, el emisor id=1) y nada mas: la captura no
 * depende de lo que otra suite haya dejado, ni de si corrio antes. Lo que no se normaliza,
 * a proposito: los usuarios y roles del master (ninguna columna que el API devuelve de
 * ellos admite NULL, asi que la forma de /api/users no cambia con las filas).
 *
 * Las dos bases scratch (usuario limitado a *_scratch) son las que devuelve
 * pos_scratch_preparar(); la herramienta no toca ninguna otra. Antes de nada se
 * rechaza (salida 2) una raiz con un .env (Database lo cargaria: una peticion sin
 * tenant podria caer en otra base), un arbol sucio al capturar (la base debe
 * describir un commit; tools/express_golden/ no cuenta) y --comparar sin
 * golden.json. Un fallo al arrancar los servidores lo informa wa_servidor con su
 * propia linea [FALLO] y salida 1 (puerto ocupado, otra raiz en el mismo puerto).
 *
 * Uso (desde la raiz del API):
 *   php -d extension=curl -d extension=openssl -d extension=pdo_mysql tools/express_golden.php --capturar
 *   php -d extension=curl -d extension=openssl -d extension=pdo_mysql tools/express_golden.php --comparar
 *   Salida: 0 igual o capturado, 1 diferencias (hasta 40 lineas), 2 rechazado.
 *   --comparar avisa con "NOTA: ..." si la base se capturo con otro PHP (round()
 *   cambia entre menores; produccion corre 8.3): el codigo de salida no cambia.
 */

require_once __DIR__ . '/test_pos_scratch.php';
require_once __DIR__ . '/express_p0_lib.php';
require_once __DIR__ . '/express_golden_lib.php';

/** Ruta de la base guardada, relativa a la raiz del API. */
const GOLDEN_ARCHIVO = 'tools/express_golden/golden.json';

/** Puerto del simulador de la DGII (mas WA_PUERTO_OFFSET). */
const GOLDEN_PUERTO_MOCK = 8398;

/** Modo => puerto del API (mas WA_PUERTO_OFFSET). sombra: PERMISSIONS_ENFORCE=false; enforce: true. */
const GOLDEN_MODOS = ['sombra' => 8399, 'enforce' => 8397];

/**
 * Flags del php de los API: output_buffering=4096 es el valor de un php.ini de produccion.
 * Sin el, php -S manda las cabeceras con el primer echo y un "echo ...; http_response_code(405);"
 * (PUT y DELETE de /api/facturas) quedaria en 200 con un Warning pegado al cuerpo: una
 * captura que ningun cliente real ve.
 */
const GOLDEN_FLAGS_API = ['-d', 'output_buffering=4096'];

const GOLDEN_ROLES = ['admin', 'user', 'anonimo'];

/** Usuarios de pos_scratch_preparar() y su rol; la clave sale de pos_scratch_secretos(). */
const GOLDEN_USUARIO_ADMIN = 'admin_pos_a';
const GOLDEN_USUARIO_USER = 'user_pos_a';

/** Tablas cuyo conteo no debe cambiar con las sondas de escritura. */
const GOLDEN_TABLAS_VIGILADAS = ['facturas', 'clients', 'products'];

/**
 * Estado HTTP que cada paso DEBE dar para que la captura valga la pena: una
 * captura con el simulador caido (emitir en 500) no prueba nada. Solo se exige al
 * capturar; --comparar deja que la diferencia hable.
 */
const GOLDEN_ESPERADO = [
    'login_admin' => 200, 'login_user' => 200, 'me_admin' => 200, 'handoff' => 200, 'canje' => 200,
    'emitir_e31' => 200, 'emitir_rechazada' => 422, 'detalle_rechazada' => 200, 'reenviar' => 200,
    'lista' => 200, 'stats' => 200, 'avisos' => 200, 'estado' => 200,
    'clientes_lista' => 200, 'productos_lista' => 200, 'modificables' => 200, 'emisor' => 200,
    'usuarios' => 200, 'rangos_get' => 200, 'signout' => 200,
];

/** Linea a stdout, siempre enmascarada (secretos conocidos y todo hex largo). */
function golden_decir(array $c, string $linea): void
{
    echo pos_scratch_enmascarar($linea, $c['ocultos']) . "\n";
}

/** Corta con 2 y un motivo enmascarado en stderr. */
function golden_abortar(array $c, string $motivo): never
{
    fwrite(STDERR, pos_scratch_enmascarar('Rechazado: ' . $motivo, $c['ocultos']) . "\n");
    exit(2);
}

/**
 * Peticion al API con X-API-KEY (sin cabecera si no hay token). Un cuerpo string
 * va como JSON ('{}' es un objeto vacio; wa_http solo pone el Content-Type a los
 * arrays). Un fallo de red corta: un status 0 no es una respuesta que guardar.
 * @return array{status:int, body:string, json:?array}
 */
function golden_pedir(array $c, string $metodo, string $url, mixed $cuerpo = null, ?string $token = null): array
{
    $cabeceras = ($token !== null && $token !== '') ? ['X-API-KEY: ' . $token] : [];
    if (is_string($cuerpo)) {
        $cabeceras[] = 'Content-Type: application/json';
    }
    $r = wa_http($metodo, $url, $cuerpo, $cabeceras, 60);
    if ($r['status'] === 0) {
        golden_abortar($c, "sin respuesta de {$metodo} {$url}: " . substr($r['body'], 0, 200));
    }
    return $r;
}

/** Login de un usuario de prueba; '' si no hay token (el llamador decide). */
function golden_login(array $c, string $api, string $usuario): string
{
    $r = golden_pedir($c, 'POST', $api . '/api/auth/login', ['emailOrUsername' => $usuario, 'password' => $c['clave']]);
    return (string) ($r['json']['data']['token'] ?? '');
}

function golden_cerrar_sesion(array $c, string $api, string $token): void
{
    if ($token !== '') {
        golden_pedir($c, 'POST', $api . '/api/auth/signout', '{}', $token);
    }
}

/**
 * El flujo de un modo: estado limpio y los pasos de la tabla del plan en orden.
 * Los ids que dependen de una respuesta (la rechazada, la aceptada) salen de la
 * respuesta anterior; si falta, se sigue con 0 y la diferencia se ve en el paso.
 *
 * @return list<array{id: string, metodo: string, ruta: string, http: int, forma: mixed}>
 */
function golden_flujo(array $c, string $modo): array
{
    $api = $c['api'][$modo];
    wa_mock_modo($c['mock'], 'acepta');
    golden_normalizar_tenant($c);
    wa_mock_log($c['mock'], true);
    $clienteId = wa_sembrar(golden_pdo($c))['client_id'];

    $pasos = [];
    // $ruta es la plantilla que se guarda ({id} en vez del id: cambia en cada corrida);
    // $destino es lo que se pide de verdad.
    $paso = function (string $id, string $metodo, string $ruta, string $destino, mixed $cuerpo = null, ?string $token = null)
        use (&$pasos, $c, $modo, $api): array {
        $r = golden_pedir($c, $metodo, $api . $destino, $cuerpo, $token);
        $pasos[] = golden_paso($id, $metodo, $ruta, $r['status'], $r['body']);
        golden_decir($c, sprintf('  [%s] %-20s %-6s %s -> %d', $modo, $id, $metodo, $ruta, $r['status']));
        return $r;
    };

    $r = $paso('login_admin', 'POST', '/api/auth/login', '/api/auth/login',
        ['emailOrUsername' => GOLDEN_USUARIO_ADMIN, 'password' => $c['clave']]);
    $admin = (string) ($r['json']['data']['token'] ?? '');
    $r = $paso('login_user', 'POST', '/api/auth/login', '/api/auth/login',
        ['emailOrUsername' => GOLDEN_USUARIO_USER, 'password' => $c['clave']]);
    $user = (string) ($r['json']['data']['token'] ?? '');
    $paso('me_admin', 'GET', '/api/auth/me', '/api/auth/me', null, $admin);

    $r = $paso('handoff', 'POST', '/api/auth/pos-handoff', '/api/auth/pos-handoff', '{}', $admin);
    $r = $paso('canje', 'POST', '/api/auth/pos-handoff/canje', '/api/auth/pos-handoff/canje',
        ['code' => (string) ($r['json']['data']['code'] ?? '')]);
    $canje = (string) ($r['json']['data']['token'] ?? '');

    $factura = [
        'tipo_ecf' => '31', 'client_id' => $clienteId, 'tipo_pago' => 1,
        'items' => [[
            'nombre_item' => 'Golden linea libre', 'indicador_facturacion' => 1, 'indicador_bien_servicio' => 1,
            'cantidad' => 1, 'precio_unitario' => 1000,
        ]],
    ];
    wa_mock_modo($c['mock'], 'acepta');
    $r = $paso('emitir_e31', 'POST', '/api/facturas', '/api/facturas', $factura, $admin);
    $idAceptada = (int) ($r['json']['data']['factura_id'] ?? 0);

    wa_mock_modo($c['mock'], 'rechaza');
    $r = $paso('emitir_rechazada', 'POST', '/api/facturas', '/api/facturas', $factura, $admin);
    $idRechazada = (int) ($r['json']['data']['factura_id'] ?? 0);
    $paso('detalle_rechazada', 'GET', '/api/facturas?id={id}', "/api/facturas?id={$idRechazada}", null, $admin);

    wa_mock_modo($c['mock'], 'acepta');
    $paso('reenviar', 'POST', '/api/facturas/{id}/reenviar', "/api/facturas/{$idRechazada}/reenviar", '{}', $admin);

    $paso('lista', 'GET', '/api/facturas?pageSize=15', '/api/facturas?pageSize=15', null, $admin);
    $paso('stats', 'GET', '/api/facturas/stats', '/api/facturas/stats', null, $admin);
    $paso('avisos', 'GET', '/api/facturas/avisos', '/api/facturas/avisos', null, $admin);
    $paso('estado', 'GET', '/api/facturas/{id}/estado', "/api/facturas/{$idAceptada}/estado", null, $admin);

    $paso('clientes_lista', 'GET', '/api/clients', '/api/clients', null, $admin);
    $paso('productos_lista', 'GET', '/api/products', '/api/products', null, $admin);
    $paso('modificables', 'GET', '/api/facturas/modificables?client_id={client_id}',
        "/api/facturas/modificables?client_id={$clienteId}", null, $admin);
    $paso('emisor', 'GET', '/api/emisor', '/api/emisor', null, $admin);

    $paso('usuarios', 'GET', '/api/users', '/api/users', null, $admin);
    $paso('rangos_get', 'GET', '/api/ncf/rangos', '/api/ncf/rangos', null, $admin);
    $paso('rangos_post_invalido', 'POST', '/api/ncf/rangos', '/api/ncf/rangos', '{}', $admin);

    $paso('signout', 'POST', '/api/auth/signout', '/api/auth/signout', '{}', $admin);

    // Las otras dos sesiones (login_user y el canje) no son pasos dorados: se cierran para
    // no dejar tokens vivos en el master de prueba.
    golden_cerrar_sesion($c, $api, $user);
    golden_cerrar_sesion($c, $api, $canje);
    return $pasos;
}

/**
 * Conexion NUEVA a la base del tenant A. No se guarda una abierta: el flujo y la matriz
 * tardan minutos con la conexion ociosa y el MySQL remoto la cierra (errno 2006).
 */
function golden_pdo(array $c): PDO
{
    return wa_pdo($c['dbA']);
}

/**
 * Deja el tenant A en el estado fijo del que parte cada flujo (solo bases scratch:
 * wa_pdo y wa_limpiar exigen la marca zz_write_ahead_scratch, y pos_scratch_preparar la
 * guarda de pos_scratch_exigir):
 *   1. wa_limpiar: sin facturas, items, movimientos ni auditoria; contadores al inicio;
 *   2. vacia GOLDEN_TABLAS_NORMALIZADAS (clients, products, ncf_sequences, emisor_config) en
 *      una transaccion: lo que dejaron otras suites, incluidas las filas que trae el esquema;
 *   3. pos_scratch_preparar() (idempotente, unos segundos): vuelve a sembrar el tenant A con
 *      wa_sembrar y los rangos testecf E31/E32 que el ambiente testecf del tenant necesita
 *      para emitir; el golden queda definido por esa siembra y no repite su SQL.
 * Los ids de las filas sembradas cambian en cada corrida; no importan (las formas no los miran).
 * La transaccion termina antes de preparar(): este abre sus propias conexiones y esperaria
 * los candados de las filas borradas.
 */
function golden_normalizar_tenant(array $c): void
{
    $pdo = golden_pdo($c);
    wa_limpiar($pdo);
    $pdo->beginTransaction();
    try {
        foreach (GOLDEN_TABLAS_NORMALIZADAS as $tabla) {
            $pdo->exec("DELETE FROM {$tabla}");
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    pos_scratch_preparar();
}

/** @return array<string,int> filas de las tablas vigiladas del tenant A */
function golden_conteos(array $c): array
{
    $pdo = golden_pdo($c);
    $n = [];
    foreach (GOLDEN_TABLAS_VIGILADAS as $tabla) {
        $n[$tabla] = (int) $pdo->query("SELECT COUNT(*) FROM {$tabla}")->fetchColumn();
    }
    return $n;
}

/**
 * La matriz de la compuerta de un modo: GET a cada clave de routes como admin, user y
 * anonimo; en enforce, ademas las sondas de escritura. Los logins del admin y el user
 * son propios de la matriz (el signout del flujo ya revoco los suyos) y se cierran al
 * terminar; anonimo no manda cabecera.
 *
 * @param list<string> $claves
 * @return list<array{metodo: string, ruta: string, rol: string, http: int}>
 */
function golden_compuerta(array $c, string $modo, array $claves): array
{
    $api = $c['api'][$modo];
    $tokens = [
        'admin' => golden_login($c, $api, GOLDEN_USUARIO_ADMIN),
        'user' => golden_login($c, $api, GOLDEN_USUARIO_USER),
        'anonimo' => '',
    ];
    foreach (['admin', 'user'] as $rol) {
        if ($tokens[$rol] === '') {
            golden_abortar($c, "no se pudo iniciar sesion como {$rol} para la matriz de la compuerta ({$modo})");
        }
    }
    $filas = [];
    foreach (golden_matriz($claves, GOLDEN_ROLES) as $f) {
        $r = golden_pedir($c, 'GET', $api . $f['ruta'], null, $tokens[$f['rol']]);
        $filas[] = ['metodo' => 'GET', 'ruta' => $f['ruta'], 'rol' => $f['rol'], 'http' => $r['status']];
    }
    $lecturas = count($filas);
    if ($modo === 'enforce') {
        $antes = golden_conteos($c);
        foreach (golden_matriz_escrituras() as $f) {
            $r = golden_pedir($c, $f['metodo'], $api . $f['ruta'], $f['cuerpo'] === '' ? null : $f['cuerpo'], $tokens[$f['rol']]);
            $filas[] = ['metodo' => $f['metodo'], 'ruta' => $f['ruta'], 'rol' => $f['rol'], 'http' => $r['status']];
        }
        $despues = golden_conteos($c);
        foreach ($antes as $tabla => $n) {
            if ($despues[$tabla] !== $n) {
                golden_abortar($c, "una sonda de escritura cambio el conteo de {$tabla} ({$n} -> {$despues[$tabla]}): una sonda debe rechazarse antes de tocar una fila");
            }
        }
    }
    foreach (['admin', 'user'] as $rol) {
        golden_cerrar_sesion($c, $api, $tokens[$rol]);
    }
    $codigos = array_count_values(array_column($filas, 'http'));
    ksort($codigos);
    $resumen = implode(' ', array_map(static fn (int $h, int $n): string => "{$h}:{$n}", array_keys($codigos), $codigos));
    golden_decir($c, sprintf('  [%s] compuerta: %d GET y %d escrituras (http %s)', $modo, $lecturas, count($filas) - $lecturas, $resumen));
    return $filas;
}

/** Los dos modos completos: {sombra:{pasos, gate}, enforce:{pasos, gate}}. */
function golden_construir(array $c, array $claves): array
{
    $modos = [];
    foreach (array_keys(GOLDEN_MODOS) as $modo) {
        $pasos = golden_flujo($c, $modo);
        $modos[$modo] = ['pasos' => $pasos, 'gate' => golden_compuerta($c, $modo, $claves)];
    }
    return $modos;
}

/**
 * La base (o una captura nueva) con los pasos por id y las filas de la compuerta por
 * "METODO ruta rol", y las rutas por nombre, SOLO para diferenciarlas: la diferencia
 * nombra "modos.sombra.pasos.lista: falta" en vez de un indice que se corre al quitar
 * una fila. El archivo guardado conserva las listas del contrato.
 */
function golden_vista(array $doc): array
{
    $vista = ['rutas' => [], 'modos' => []];
    foreach ((array) ($doc['rutas'] ?? []) as $clave) {
        $vista['rutas'][(string) $clave] = true;
    }
    foreach ((array) ($doc['modos'] ?? []) as $modo => $m) {
        $pasos = [];
        foreach ((array) ($m['pasos'] ?? []) as $p) {
            $pasos[(string) ($p['id'] ?? '?')] = $p;
        }
        $gate = [];
        foreach ((array) ($m['gate'] ?? []) as $f) {
            $gate[($f['metodo'] ?? '?') . ' ' . ($f['ruta'] ?? '?') . ' ' . ($f['rol'] ?? '?')] = $f['http'] ?? null;
        }
        $vista['modos'][$modo] = ['pasos' => $pasos, 'gate' => $gate];
    }
    return $vista;
}

/** Mayor.menor de este PHP, el que se guarda y se compara (como baseline_php). */
function golden_php(): string
{
    return PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
}

/** Arranca el simulador, los dos API y deja listo el contexto del flujo. */
function golden_contexto(string $raiz): array
{
    $info = pos_scratch_preparar();
    $cred = $info['cred'];
    $secretos = pos_scratch_secretos($cred['pass']);
    $cert = wa_cert_prueba();
    // El nombre del servidor lleva la raiz (como test_pos_scratch.php): uno viejo de otra
    // raiz en el mismo puerto se rechaza en vez de reutilizarse con el codigo equivocado.
    $e = substr(md5($raiz), 0, 6);
    $mock = wa_servidor('golden_mock_' . $e, 'tools/mock_dgii.php', GOLDEN_PUERTO_MOCK, [], $raiz);
    $api = [];
    foreach (GOLDEN_MODOS as $modo => $puerto) {
        $env = array_merge(
            pos_scratch_env_api($cred, $info['master'], $secretos, $mock, $cert, $modo === 'enforce'),
            pos_scratch_env_base_por_defecto($cred, $info['master'])
        );
        $api[$modo] = wa_servidor('golden_' . $modo . '_' . $e, 'index.php', $puerto, $env, $raiz, GOLDEN_FLAGS_API);
    }
    return [
        'api' => $api,
        'mock' => $mock,
        'dbA' => $info['dbA'],
        'clave' => $secretos['clave_usuarios'],
        'ocultos' => [$cred['pass'], $cred['user'], $cred['host'], $secretos['clave_usuarios'],
            $secretos['clave_maestra'], $secretos['pepper'], $cert['password']],
    ];
}

/** Claves de config/permissions.php routes, en su orden. @return list<string> */
function golden_claves(string $raiz): array
{
    $permisos = require $raiz . '/config/permissions.php';
    return array_map('strval', array_keys($permisos['routes']));
}

function golden_capturar(string $raiz): never
{
    $sha = p0_git(['rev-parse', 'HEAD'], $raiz);
    if ($sha === '') {
        fwrite(STDERR, "Rechazado: {$raiz} no es un repositorio git (o git no responde).\n");
        exit(2);
    }
    $sucio = p0_git(['status', '--porcelain'], $raiz);
    if (!p0_arbol_limpio($sucio, ['tools/express_golden/'])) {
        fwrite(STDERR, "Rechazado: el arbol tiene cambios sin confirmar, y la captura dorada debe describir un commit.\n{$sucio}\n");
        exit(2);
    }
    $c = golden_contexto($raiz);
    $claves = golden_claves($raiz);
    echo 'PHP ' . PHP_VERSION . ' | ' . substr($sha, 0, 7) . " | captura dorada\n";
    $modos = golden_construir($c, $claves);
    foreach ($modos as $modo => $m) {
        foreach ($m['pasos'] as $p) {
            if (isset(GOLDEN_ESPERADO[$p['id']]) && GOLDEN_ESPERADO[$p['id']] !== $p['http']) {
                golden_abortar($c, "{$modo}/{$p['id']} dio HTTP {$p['http']} y se esperaba " . GOLDEN_ESPERADO[$p['id']]
                    . ': una captura con el entorno roto no prueba nada. golden.json no se toco.');
            }
        }
    }
    $doc = [
        'version' => GOLDEN_VERSION,
        'meta' => ['git_sha' => $sha, 'php' => golden_php(), 'capturado' => date('c')],
        'rutas' => $claves,
        'modos' => $modos,
    ];
    $ruta = $raiz . '/' . GOLDEN_ARCHIVO;
    if (!is_dir(dirname($ruta)) && !mkdir(dirname($ruta), 0777, true)) {
        golden_abortar($c, 'no se pudo crear ' . dirname($ruta));
    }
    file_put_contents($ruta, json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    echo 'Captura dorada escrita: ' . GOLDEN_ARCHIVO . "\n";
    exit(0);
}

function golden_comparar(string $raiz): never
{
    $ruta = $raiz . '/' . GOLDEN_ARCHIVO;
    if (!is_file($ruta)) {
        fwrite(STDERR, 'Rechazado: no hay captura dorada en ' . GOLDEN_ARCHIVO . ". Corre primero: php tools/express_golden.php --capturar\n");
        exit(2);
    }
    $base = json_decode((string) file_get_contents($ruta), true);
    if (!is_array($base) || ($base['version'] ?? null) !== GOLDEN_VERSION || !is_array($base['modos'] ?? null)) {
        fwrite(STDERR, 'Rechazado: ' . GOLDEN_ARCHIVO . ' no tiene la forma esperada (version ' . GOLDEN_VERSION . ").\n");
        exit(2);
    }
    $c = golden_contexto($raiz);
    $claves = golden_claves($raiz);
    echo 'PHP ' . PHP_VERSION . " | comparando con la captura dorada\n";
    $nuevo = ['rutas' => $claves, 'modos' => golden_construir($c, $claves)];
    // Por JSON ida y vuelta: las dos partes quedan normalizadas igual (tipos y claves).
    $nuevo = json_decode((string) json_encode($nuevo), true);
    $dif = golden_diff(golden_vista($base), golden_vista($nuevo));

    $aviso = golden_aviso_php((string) ($base['meta']['php'] ?? ''), golden_php());
    if ($aviso !== null) {
        golden_decir($c, "NOTA: {$aviso}");
    }
    if ($dif === []) {
        echo "Sin diferencias\n";
        exit(0);
    }
    foreach (array_slice($dif, 0, 40) as $linea) {
        golden_decir($c, $linea);
    }
    $resto = count($dif) - 40;
    echo count($dif) . ' diferencia(s) con la captura dorada' . ($resto > 0 ? ' (se muestran las primeras 40)' : '') . "\n";
    exit(1);
}

function golden_main(): never
{
    $raiz = dirname(__DIR__);
    $capturar = wa_arg('capturar') !== null;
    $comparar = wa_arg('comparar') !== null;
    if ($capturar === $comparar) {
        fwrite(STDERR, "Uso: php -d extension=curl -d extension=openssl -d extension=pdo_mysql tools/express_golden.php --capturar | --comparar\n");
        exit(2);
    }
    $problema = pos_scratch_revisar_raiz($raiz);
    if ($problema !== '') {
        fwrite(STDERR, "Rechazado: la raiz del API no sirve para la captura dorada: {$problema}\n");
        exit(2);
    }
    if ($capturar) {
        golden_capturar($raiz);
    }
    golden_comparar($raiz);
}

// CLI propio: solo cuando se ejecuta este archivo, nunca al incluirlo.
if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    golden_main();
}
