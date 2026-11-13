<?php
/**
 * test_integracion_request_log.php — La bitacora de peticiones de integracion
 * (src/IntegracionRequestLog.php, master_migrations/018), el veredicto RFCE guardado
 * que GET /integracion/estado devuelve cuando la consulta a la DGII falla
 * (IntegracionStoreModel::veredictoRfceGuardado) y la cabecera WWW-Authenticate en
 * los 401 de la DGII (DgiiAuthService::headerValue). Sin servidores ni MySQL: las
 * partes con base usan SQLite (se saltan si no hay pdo_sqlite).
 *
 * Origen (2026-11-13, tenant 18 AGROVISA, certecf): su sistema mostraba "Fallo
 * consultando el estado a DGII: DGII authenticated request failed: HTTP 401" para dos
 * E32 de consumo rechazados. La emision RFCE no devolvia el motivo (quedaba en
 * rfce_response), y en certecf la DGII rechaza el token en consultarfce ("The
 * signature is invalid"). No habia rastro de lo que el cliente mando ni de lo que se
 * le contesto.
 *
 * Uso:
 *   php tools/test_integracion_request_log.php      (sale con 1 si algo falla)
 */

require_once __DIR__ . '/../src/IntegracionRequestLog.php';
require_once __DIR__ . '/../src/Models/IntegracionStoreModel.php';
require_once __DIR__ . '/../src/Utils/FacturacionElectronica/DgiiAuthService.php';
require_once __DIR__ . '/write_ahead_test_lib.php';

$fallos = 0;
$total = 0;
$chk = function (string $desc, bool $ok, $obtenido = null) use (&$fallos, &$total) {
    $total++;
    if (!$ok) {
        $fallos++;
    }
    printf("  [%s] %s\n", $ok ? 'OK  ' : 'FALLO', $desc);
    if (!$ok && $obtenido !== null) {
        echo '         obtenido: ' . json_encode($obtenido, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
    }
};

/** El $_SERVER de una peticion de integracion tipica. */
$server = static function (string $metodo, string $uri, array $extra = []): array {
    return $extra + [
        'REQUEST_METHOD' => $metodo,
        'REQUEST_URI' => $uri,
        'HTTP_X_API_KEY' => 'ak_1234567890abcdef',
        'HTTP_X_API_SECRET' => 'secreto-que-nunca-se-guarda',
        'HTTP_AUTHORIZATION' => 'Bearer token-que-nunca-se-guarda',
        'HTTP_COOKIE' => 'sesion=cookie-que-nunca-se-guarda',
        'HTTP_USER_AGENT' => 'SistemaCliente/2.1',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_HOST' => 'gratex.net',
        'CONTENT_TYPE' => 'application/json',
        'REMOTE_ADDR' => '10.0.0.5',
    ];
};
$sinSecretos = static function (array $fila): bool {
    $todo = json_encode($fila, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    foreach (['secreto-que-nunca-se-guarda', 'token-que-nunca-se-guarda', 'cookie-que-nunca-se-guarda', 'ak_1234567890abcdef'] as $s) {
        if (str_contains($todo, $s)) {
            return false;
        }
    }
    return true;
};

// ---------------------------------------------------------------------------
echo "1. Que peticiones se registran\n";
$chk('/api/integracion/ecf', IntegracionRequestLog::aplica(['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/integracion/ecf']));
$chk('/api/integracion/estado?e_ncf=...', IntegracionRequestLog::aplica(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/integracion/estado?e_ncf=E320000000001']));
$chk('/api/integracion (sin sub-ruta)', IntegracionRequestLog::aplica(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/integracion']));
$chk('/API/Integracion/ecf (mayusculas)', IntegracionRequestLog::aplica(['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/API/Integracion/ecf']));
$chk('credencial de integracion en otra ruta (/api/facturas)', IntegracionRequestLog::aplica(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/facturas', 'HTTP_X_API_SECRET' => 'x']));
$chk('ruta de la app sin credencial de integracion: no', !IntegracionRequestLog::aplica(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/facturas']));
$chk('/api/integracionX/ecf: no', !IntegracionRequestLog::aplica(['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/integracionX/ecf']));
$chk('preflight OPTIONS: nunca', !IntegracionRequestLog::aplica(['REQUEST_METHOD' => 'OPTIONS', 'REQUEST_URI' => '/api/integracion/ecf', 'HTTP_X_API_SECRET' => 'x']));

// ---------------------------------------------------------------------------
echo "\n2. La fila: identificacion, cabeceras y credenciales\n";
$pedido = '{"e_ncf":"E320000000002","tipo_ecf":"32","emisor":{"rnc":"03900240189"},"totales":{"monto_total":1180.00}}';
$respuesta = json_encode(['status' => true, 'data' => ['e_ncf' => 'E320000000002', 'estado' => 'RFCE_RECHAZADO']]);
$tenant = ['id' => 18, 'rnc' => '03900240189', 'nombre' => 'AGROVISA FERRETERIA'];
$fila = IntegracionRequestLog::armarFila(
    $server('POST', '/api/integracion/ecf', ['HTTP_X_FORWARDED_FOR' => '200.1.2.3, 10.0.0.1']),
    $pedido, 200, $respuesta, $tenant, [], 812
);
$chk('tenant_id y rnc de la empresa', $fila['tenant_id'] === 18 && $fila['rnc'] === '03900240189', $fila);
$chk('metodo, ruta, sin query', $fila['metodo'] === 'POST' && $fila['ruta'] === '/api/integracion/ecf' && $fila['query_string'] === null, $fila);
$chk('e_ncf sacado del cuerpo', $fila['e_ncf'] === 'E320000000002', $fila['e_ncf']);
$chk('ip: la primera de X-Forwarded-For', $fila['ip'] === '200.1.2.3', $fila['ip']);
$chk('user agent en su columna', $fila['user_agent'] === 'SistemaCliente/2.1', $fila['user_agent']);
$chk('X-API-KEY: solo los primeros 8', $fila['api_key_prefijo'] === 'ak_12345', $fila['api_key_prefijo']);
$cab = json_decode((string) $fila['request_headers'], true);
$chk('cabeceras utiles guardadas (accept, host, content-type)', is_array($cab)
    && ($cab['accept'] ?? null) === 'application/json' && ($cab['host'] ?? null) === 'gratex.net'
    && ($cab['content-type'] ?? null) === 'application/json', $cab);
$chk('sin x-api-key, x-api-secret, authorization, cookie ni user-agent en cabeceras', is_array($cab)
    && !array_intersect(array_keys($cab), ['x-api-key', 'x-api-secret', 'authorization', 'cookie', 'user-agent']), $cab);
$chk('ningun secreto en ninguna columna', $sinSecretos($fila), $fila);
$chk('http_status y duracion', $fila['http_status'] === 200 && $fila['duracion_ms'] === 812, $fila);
$chk('error_interno vacio si no se anoto nada', $fila['error_interno'] === null, $fila['error_interno']);

$fila = IntegracionRequestLog::armarFila(
    $server('GET', '/api/integracion/estado?e_ncf=E320000000001&codigo_seguridad=V7NQsm&rnc=03900240189'),
    '', 502, '{"status":false,"error":"Fallo consultando el estado a DGII: DGII authenticated request failed: HTTP 401"}', null,
    ['RuntimeException: DGII authenticated request failed: HTTP 401 [WWW-Authenticate: Bearer error="invalid_token"]'], 3
);
$chk('GET: query tal cual y e_ncf del query', $fila['query_string'] === 'e_ncf=E320000000001&codigo_seguridad=V7NQsm&rnc=03900240189'
    && $fila['e_ncf'] === 'E320000000001', $fila);
$chk('sin tenant (credencial invalida): tenant_id null, rnc del query', $fila['tenant_id'] === null && $fila['rnc'] === '03900240189', $fila);
$chk('sin cuerpo: request_body null y 0 bytes', $fila['request_body'] === null && $fila['request_bytes'] === 0, $fila);
$chk('error_interno con el detalle anotado', str_contains((string) $fila['error_interno'], 'invalid_token'), $fila['error_interno']);
$chk('respuesta de error guardada tal cual', str_contains((string) $fila['response_body'], 'HTTP 401') && $fila['http_status'] === 502, $fila);

$fila = IntegracionRequestLog::armarFila($server('GET', '/api/integracion/estado?e_ncf=E31&token=abc123&api_secret=zzz'), '', 200, '{}', null, [], 0);
$chk('query con parametros sensibles: redactados', !str_contains((string) $fila['query_string'], 'abc123')
    && !str_contains((string) $fila['query_string'], 'zzz') && str_contains((string) $fila['query_string'], 'e_ncf=E31'), $fila['query_string']);

$fila = IntegracionRequestLog::armarFila($server('POST', '/api/integracion/aprobacion-comercial'),
    '{"rnc_emisor":"131880681","e_ncf":"e310000000007","rnc_comprador":"03900240189","estado":1}', 200, '{}', null, [], 0);
$chk('aprobacion: rnc de rnc_comprador y e_ncf en mayusculas', $fila['rnc'] === '03900240189' && $fila['e_ncf'] === 'E310000000007', $fila);

// ---------------------------------------------------------------------------
echo "\n3. Cuerpo del pedido: tal cual, o redactado si trae secretos\n";
$crudo = "{\n  \"e_ncf\": \"E310000000501\",\n  \"totales\": {\"monto_total\": 100.50}\n}";
$fila = IntegracionRequestLog::armarFila($server('POST', '/api/integracion/ecf'), $crudo, 200, '{}', $tenant, [], 0);
$chk('sin campos sensibles: byte a byte (espacios y 100.50 incluidos)', $fila['request_body'] === $crudo, $fila['request_body']);
$chk('request_bytes = tamaño original', $fila['request_bytes'] === strlen($crudo), $fila['request_bytes']);
$conSecreto = '{"e_ncf":"E310000000501","emisor":{"rnc":"03900240189","certificate_password":"clave-del-p12"},"api_secret":"s3"}';
$fila = IntegracionRequestLog::armarFila($server('POST', '/api/integracion/ecf'), $conSecreto, 200, '{}', $tenant, [], 0);
$guardado = json_decode((string) $fila['request_body'], true);
$chk('con campos sensibles (anidados): redactados', is_array($guardado)
    && !str_contains((string) $fila['request_body'], 'clave-del-p12') && !str_contains((string) $fila['request_body'], '"s3"')
    && ($guardado['emisor']['rnc'] ?? null) === '03900240189', $fila['request_body']);
$chk('... y el resto del pedido intacto', ($guardado['e_ncf'] ?? null) === 'E310000000501', $guardado);

$latin1 = "{\"comprador\":{\"razon_social\":\"Jos\xE9 Pe\xF1a\"}}"; // Windows-1252, no UTF-8
$fila = IntegracionRequestLog::armarFila($server('POST', '/api/integracion/ecf'), $latin1, 400, '{"status":false,"error":"JSON body invalido."}', $tenant, [], 0);
$chk('pedido en Latin-1: se guarda legible, en UTF-8 y avisado', preg_match('//u', (string) $fila['request_body']) === 1
    && str_contains((string) $fila['request_body'], 'José Peña') && str_contains((string) $fila['request_body'], 'no venia en UTF-8'), $fila['request_body']);

$grande = '{"items":"' . str_repeat('ñ', IntegracionRequestLog::TOPE_CUERPO) . '"}';
$fila = IntegracionRequestLog::armarFila($server('POST', '/api/integracion/ecf'), $grande, 413, '{}', $tenant, [], 0);
$chk('pedido de mas de 1 MB: recortado, avisado y UTF-8 valido', strlen((string) $fila['request_body']) < IntegracionRequestLog::TOPE_CUERPO + 200
    && str_contains((string) $fila['request_body'], '[recortado: se guardaron') && preg_match('//u', (string) $fila['request_body']) === 1);
$chk('... request_bytes guarda el tamaño real', $fila['request_bytes'] === strlen($grande), $fila['request_bytes']);

// ---------------------------------------------------------------------------
echo "\n4. Cuerpo de la respuesta: el XML firmado va como marcador\n";
$xml = '<?xml version="1.0" encoding="utf-8"?><ECF><Encabezado><Version>1.0</Version></Encabezado>' . str_repeat('<Item><Linea>1</Linea></Item>', 40) . '<Signature>abc</Signature></ECF>';
$resp = json_encode(['status' => true, 'data' => [
    'e_ncf' => 'E320000000002', 'estado' => 'RFCE_RECHAZADO', 'codigo_seguridad' => 'HF/NTG',
    'xml_firmado' => $xml,
    'dgii_response' => ['codigo' => 2, 'estado' => 'Rechazado', 'mensajes' => [['codigo' => 145, 'valor' => 'No se encuentra en una fase valida']]],
]]);
$fila = IntegracionRequestLog::armarFila($server('POST', '/api/integracion/ecf'), '{}', 200, $resp, $tenant, [], 0);
$guardada = json_decode((string) $fila['response_body'], true);
$chk('xml_firmado reemplazado por un marcador con su tamaño', ($guardada['data']['xml_firmado'] ?? '') === '[XML omitido de la bitacora: ' . strlen($xml) . ' bytes]', $guardada['data']['xml_firmado'] ?? null);
$chk('el motivo de la DGII y el resto quedan intactos', ($guardada['data']['dgii_response']['mensajes'][0]['codigo'] ?? null) === 145
    && ($guardada['data']['codigo_seguridad'] ?? null) === 'HF/NTG', $guardada);
$chk('response_bytes = lo que de verdad se envio', $fila['response_bytes'] === strlen($resp), $fila['response_bytes']);
$sinXml = '{"status":true,"recurso":"estado","estado":"ACEPTADO","consulta":{"codigo":1}}';
$fila = IntegracionRequestLog::armarFila($server('GET', '/api/integracion/estado?e_ncf=E31'), '', 200, $sinXml, $tenant, [], 0);
$chk('respuesta sin XML: tal cual, byte a byte', $fila['response_body'] === $sinXml, $fila['response_body']);
$html = '<html><body>' . str_repeat('docs ', 10000) . '</body></html>';
$fila = IntegracionRequestLog::armarFila($server('GET', '/api/integracion/ecf/'), '', 200, $html, $tenant, [], 0);
$chk('respuesta que no es JSON (pagina de docs): solo el principio', strlen((string) $fila['response_body']) < IntegracionRequestLog::TOPE_NO_JSON + 200
    && str_starts_with((string) $fila['response_body'], '<html>') && str_contains((string) $fila['response_body'], '[recortado'), strlen((string) $fila['response_body']));
$fila = IntegracionRequestLog::armarFila($server('GET', '/api/integracion/recibidos'), '', 200, '', $tenant, [], 0);
$chk('respuesta vacia: null y 0 bytes', $fila['response_body'] === null && $fila['response_bytes'] === 0, $fila);

$fila = IntegracionRequestLog::armarFila($server('GET', '/api/integracion/estado?e_ncf=E32'), '', 502, '{}', $tenant, [str_repeat('x', 70000)], 0);
$chk('error_interno con tope', strlen((string) $fila['error_interno']) < 61000 && str_contains((string) $fila['error_interno'], '[recortado'), strlen((string) $fila['error_interno']));

// ---------------------------------------------------------------------------
echo "\n5. Veredicto RFCE guardado (GET /estado cuando la consulta a la DGII falla)\n";
$motivo = ['codigo' => '2', 'estado' => 'Rechazado', 'mensajes' => [['codigo' => 145, 'valor' => 'No se encuentra en una fase valida']], 'secuenciaUtilizada' => false];
$base = ['estado_dgii' => 'RFCE_RECHAZADO', 'codigo_seguridad' => 'HF/NTG', 'dgii_respuesta' => $motivo, 'created_at' => '2026-11-13 15:16:31'];
$v = IntegracionStoreModel::veredictoRfceGuardado($base, 'HF/NTG');
$chk('RFCE rechazado con su respuesta: estado, consulta y motivo', $v !== null && $v['estado'] === 'RFCE_RECHAZADO'
    && ($v['consulta']['mensajes'][0]['codigo'] ?? null) === 145 && $v['con_motivo'] === true && $v['fecha'] === '2026-11-13 15:16:31', $v);
$v = IntegracionStoreModel::veredictoRfceGuardado(['dgii_respuesta' => null] + $base, 'HF/NTG');
$chk('envio viejo sin respuesta guardada: estado, sin motivo (con_motivo=false)', $v !== null && $v['estado'] === 'RFCE_RECHAZADO'
    && $v['consulta'] === null && $v['con_motivo'] === false, $v);
$chk('otro codigo de seguridad (otro envio del mismo e-NCF): no', IntegracionStoreModel::veredictoRfceGuardado($base, 'V7NQsm') === null);
$chk('e-CF con track_id (ENVIADO no es veredicto): no', IntegracionStoreModel::veredictoRfceGuardado(['estado_dgii' => 'ENVIADO'] + $base, 'HF/NTG') === null);
$chk('RFCE_ENVIADO: no', IntegracionStoreModel::veredictoRfceGuardado(['estado_dgii' => 'RFCE_ENVIADO'] + $base, 'HF/NTG') === null);
$chk('sin codigo guardado: no', IntegracionStoreModel::veredictoRfceGuardado(['codigo_seguridad' => null] + $base, '') === null);
$chk('sin respaldo: no', IntegracionStoreModel::veredictoRfceGuardado(null, 'HF/NTG') === null);

// ---------------------------------------------------------------------------
echo "\n6. WWW-Authenticate de un 401 de la DGII\n";
$cabeceras = "HTTP/1.1 100 Continue\r\n\r\nHTTP/1.1 401 Unauthorized\r\nContent-Length: 0\r\n"
    . "www-authenticate: Bearer error=\"invalid_token\", error_description=\"The signature is invalid\"\r\n\r\n";
$chk('se lee sin importar mayusculas', DgiiAuthService::headerValue($cabeceras, 'WWW-Authenticate') === 'Bearer error="invalid_token", error_description="The signature is invalid"',
    DgiiAuthService::headerValue($cabeceras, 'WWW-Authenticate'));
$chk('sin la cabecera: null', DgiiAuthService::headerValue("HTTP/1.1 401 Unauthorized\r\n\r\n", 'WWW-Authenticate') === null);
$chk('cabecera vacia: null', DgiiAuthService::headerValue("HTTP/1.1 401 Unauthorized\r\nWWW-Authenticate: \r\n", 'WWW-Authenticate') === null);
$resumen = (new ReflectionMethod(DgiiAuthService::class, 'responseSummary'));
$auth = (new ReflectionClass(DgiiAuthService::class))->newInstanceWithoutConstructor();
$s401 = $resumen->invoke($auth, ['status_code' => 401, 'headers' => $cabeceras, 'body' => ''], 'https://fc.dgii.gov.do/certecf/consultarfce/api/Consultas/Consulta?RNC_Emisor=1&ENCF=E32&Cod_Seguridad_eCF=x');
$chk('el 401 dice el motivo y el servicio (sin el query)', $s401 === 'HTTP 401 [WWW-Authenticate: Bearer error="invalid_token", error_description="The signature is invalid"] [https://fc.dgii.gov.do/certecf/consultarfce/api/Consultas/Consulta]', $s401);
$s400 = $resumen->invoke($auth, ['status_code' => 400, 'headers' => $cabeceras, 'body' => '"Para consultar el estado de esta factura, es necesario completar el campo Cod_Seguridad."'], 'https://ecf.dgii.gov.do/x?a=1');
$chk('un 400 sigue igual que antes (sin cabecera ni URL)', $s400 === 'HTTP 400 - "Para consultar el estado de esta factura, es necesario completar el campo Cod_Seguridad."', $s400);

// ---------------------------------------------------------------------------
echo "\n7. SQLite: guardar y purgar\n";
$conSqlite = extension_loaded('pdo_sqlite');
$crearTabla = static function (PDO $pdo): void {
    $pdo->exec('CREATE TABLE integracion_request_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NULL, rnc TEXT NULL, api_key_prefijo TEXT NULL,
        metodo TEXT NOT NULL, ruta TEXT NOT NULL, query_string TEXT NULL, e_ncf TEXT NULL, ip TEXT NULL,
        user_agent TEXT NULL, request_headers TEXT NULL, request_body TEXT NULL, request_bytes INTEGER NULL,
        http_status INTEGER NULL, response_body TEXT NULL, response_bytes INTEGER NULL, error_interno TEXT NULL,
        duracion_ms INTEGER NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
};
if (!$conSqlite) {
    echo "  (SALTADA: sin pdo_sqlite)\n";
} else {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $crearTabla($pdo);
    $fila = IntegracionRequestLog::armarFila($server('POST', '/api/integracion/ecf'), $pedido, 200, $resp, $tenant, ['nota'], 15);
    IntegracionRequestLog::guardar($pdo, $fila);
    $leida = $pdo->query('SELECT * FROM integracion_request_log')->fetch();
    $chk('guardar(): la fila entra con todas sus columnas', $leida !== false && (int) $leida['tenant_id'] === 18
        && $leida['e_ncf'] === 'E320000000002' && (int) $leida['http_status'] === 200 && $leida['error_interno'] === 'nota', $leida);
    $pdo->exec("INSERT INTO integracion_request_log (metodo, ruta, created_at) VALUES ('GET', '/vieja', '2020-01-01 00:00:00')");
    $borradas = IntegracionRequestLog::purgar($pdo, 90);
    $quedan = $pdo->query('SELECT ruta FROM integracion_request_log ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    $chk('purgar(90): borra la vieja y deja la de hoy', $borradas === 1 && $quedan === ['/api/integracion/ecf'], [$borradas, $quedan]);
    $chk('purgar(0): no borra nada', IntegracionRequestLog::purgar($pdo, 0) === 0);
}

// ---------------------------------------------------------------------------
echo "\n8. De punta a punta en un proceso aparte (buffer + funcion de apagado)\n";
if (!$conSqlite) {
    echo "  (SALTADA: sin pdo_sqlite)\n";
} else {
    $raiz = realpath(__DIR__ . '/..');
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'irl_' . bin2hex(random_bytes(4));
    mkdir($dir);
    $base = $dir . DIRECTORY_SEPARATOR . 'log.sqlite';
    $pdo = new PDO('sqlite:' . $base, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $crearTabla($pdo);
    $pdo = null;

    /** Corre un hijo que simula una peticion; devuelve [salida, codigo]. */
    $hijo = static function (string $nombre, string $cuerpo) use ($raiz, $dir, $base): array {
        $script = $dir . DIRECTORY_SEPARATOR . $nombre . '.php';
        $cabeza = '<?php' . "\n"
            . 'putenv("INTEGRACION_LOG_ENABLED=true");' . "\n"
            . '$_SERVER["REQUEST_METHOD"] = "GET";' . "\n"
            . '$_SERVER["REQUEST_URI"] = "/api/integracion/estado?e_ncf=E320000000001&codigo_seguridad=V7NQsm";' . "\n"
            . '$_SERVER["HTTP_X_API_KEY"] = "ak_1234567890abcdef";' . "\n"
            . '$_SERVER["HTTP_X_API_SECRET"] = "secreto-que-nunca-se-guarda";' . "\n"
            . 'require ' . var_export($raiz . '/src/ErrorHandler.php', true) . ';' . "\n"
            . 'ErrorHandler::register();' . "\n"
            . 'require ' . var_export($raiz . '/src/IntegracionRequestLog.php', true) . ';' . "\n"
            . '$pdo = new PDO(' . var_export('sqlite:' . $base, true) . ', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);' . "\n"
            . 'if (!IntegracionRequestLog::iniciarSiAplica($pdo)) { fwrite(STDERR, "no inicio"); exit(9); }' . "\n";
        file_put_contents($script, $cabeza . $cuerpo);
        $cmd = array_merge([PHP_BINARY], wa_php_flags(['pdo_sqlite']), ['-d', 'display_errors=0', '-d', 'log_errors=0', $script]);
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $raiz);
        $salida = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $codigo = proc_close($proc);
        return [$salida, $codigo];
    };
    $filas = static function () use ($base): array {
        $pdo = new PDO('sqlite:' . $base, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $r = $pdo->query('SELECT * FROM integracion_request_log ORDER BY id')->fetchAll();
        $pdo = null;
        return $r;
    };

    // a) Un 502 con exit() a mitad (como sendUnauthorized) y un detalle anotado.
    [$salida] = $hijo('a_502', 'http_response_code(502);' . "\n"
        . 'echo json_encode(["status" => false, "error" => "Fallo consultando el estado a DGII: HTTP 401"]);' . "\n"
        . 'IntegracionRequestLog::anotar(new RuntimeException("DGII authenticated request failed: HTTP 401", 0, new LogicException("causa")));' . "\n"
        . 'exit;' . "\n" . 'echo "nunca";' . "\n");
    $r = $filas();
    $f = $r[0] ?? [];
    $chk('el cliente recibe su respuesta intacta', $salida === '{"status":false,"error":"Fallo consultando el estado a DGII: HTTP 401"}', $salida);
    $chk('queda una fila con el HTTP 502 y la misma respuesta', count($r) === 1 && (int) ($f['http_status'] ?? 0) === 502 && ($f['response_body'] ?? null) === $salida, $f);
    $chk('... con e_ncf, query y prefijo de la llave', ($f['e_ncf'] ?? null) === 'E320000000001'
        && ($f['query_string'] ?? null) === 'e_ncf=E320000000001&codigo_seguridad=V7NQsm' && ($f['api_key_prefijo'] ?? null) === 'ak_12345', $f);
    $chk('... y el detalle interno con su causa', str_contains((string) ($f['error_interno'] ?? ''), 'HTTP 401') && str_contains((string) ($f['error_interno'] ?? ''), 'causa: LogicException: causa'), $f['error_interno'] ?? null);
    $chk('... sin el secreto', $sinSecretos($f), $f);

    // b) Una excepcion sin capturar: ErrorHandler responde 500 y su detalle llega a la fila.
    [$salida] = $hijo('b_500', 'throw new DomainException("se rompio algo");' . "\n");
    $r = $filas();
    $f = $r[1] ?? [];
    $chk('excepcion sin capturar: el cliente recibe el 500 JSON de ErrorHandler', str_contains($salida, '"error_id"'), $salida);
    $chk('... y la fila guarda el 500, esa respuesta y el detalle', (int) ($f['http_status'] ?? 0) === 500 && ($f['response_body'] ?? null) === $salida
        && str_contains((string) ($f['error_interno'] ?? ''), 'DomainException: se rompio algo'), $f);

    // c) Un buffer abierto por el controller y sin cerrar: su contenido tambien es la respuesta.
    [$salida] = $hijo('c_buffer', 'echo "{\"a\":";' . "\n" . 'ob_start();' . "\n" . 'echo "1}";' . "\n");
    $r = $filas();
    $f = $r[2] ?? [];
    $chk('buffer interno sin cerrar: la fila tiene la respuesta completa', $salida === '{"a":1}' && ($f['response_body'] ?? null) === '{"a":1}' && (int) ($f['http_status'] ?? 0) === 200, [$salida, $f['response_body'] ?? null]);

    // d) Sin tabla (master sin la 018): la respuesta sale igual y no truena.
    $pdoD = new PDO('sqlite:' . $base);
    $pdoD->exec('DROP TABLE integracion_request_log');
    $pdoD = null;
    [$salida, $codigo] = $hijo('d_sin_tabla', 'echo "{\"ok\":true}";' . "\n");
    $chk('sin la tabla: la respuesta sale igual y el proceso termina bien', $salida === '{"ok":true}' && $codigo === 0, [$salida, $codigo]);

    foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $archivo) {
        @unlink($archivo);
    }
    @rmdir($dir);
}

printf("\n%d de %d pruebas OK\n", $total - $fallos, $total);
exit($fallos > 0 ? 1 : 0);
