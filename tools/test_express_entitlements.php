<?php
/**
 * test_express_entitlements.php — Fiscalpoint Express, plan 02 (la API decide que
 * puede llamar, ver y emitir cada empresa segun su edicion): lo que se prueba sin
 * base de datos ni red. La mitad con MySQL es tools/test_express_scratch.php.
 *
 * Cada tarea del plan agrega su seccion justo encima del bloque de cierre
 * (wa_fin), en orden. Las variables de cada seccion llevan su numero (s1, s2...)
 * para que una seccion nueva no pise las de otra.
 *
 * Uso:
 *   php tools/test_express_entitlements.php      (sale con 1 si algo falla)
 *   (--hijo=validar y --hijo=emisor, cada uno con --escenario=<base64 de un JSON>, son los modos hijo de las secciones 4 y 7: los usa la
 *   propia suite, no se llaman a mano)
 *
 * express_hijo_aislado() corre un php hijo (las herramientas de linea de comandos: tenant_edicion.php en la seccion 9, create_tenant.php
 * en la 10) con la master apuntando a un puerto donde no escucha nadie: ver su comentario.
 */

require_once __DIR__ . '/write_ahead_test_lib.php';
// pos_scratch_revisar_raiz() (la raiz del API no puede traer un .env) y lo que trae consigo. Incluirlos solo define funciones y constantes:
// ni abren una conexion ni corren su programa principal (el de cada uno solo corre cuando es ese archivo el que se ejecuta).
require_once __DIR__ . '/pos_scratch_guard.php';
require_once __DIR__ . '/pos_scratch_prepare.php';
require_once __DIR__ . '/test_pos_scratch.php';

/**
 * Modo hijo de la seccion 4: corre AuthMiddleware::validateRequest en un proceso aparte (la guarda de rutas termina la
 * peticion con exit, y eso no se puede probar dentro del proceso de la suite) y, al terminar, tambien por ese exit,
 * imprime UNA linea JSON:
 *   {salida, http, llamadas: {actualizar, revocar}, hashes: {actualizar, revocar}, clase_cargada, pasos, retorno, contexto, log}
 * donde salida es lo que la peticion imprimio, http el codigo que dejo puesto (false si nadie lo puso), llamadas y hashes lo que
 * recibieron updateLastUsed y revokeTokenByHash, clase_cargada si TenantEntitlements llego a cargarse, pasos cuantas
 * llamadas a validateRequest volvieron, retorno el resultado de la ultima, contexto la identidad que quedo en
 * RequestContext (user_id, tenant_id y hash del token) y log lo que se escribio con error_log.
 *
 * Escenario (JSON): tenant (la fila de master.tenants que queda resuelta, o null), validacion (lo que devuelve
 * validateToken), metodo, uri, token, peticiones (cuantas veces se llama validateRequest en el mismo proceso; por omision
 * 1), entre (fila que reemplaza a tenant entre una llamada y la siguiente; opcional) y raiz (copia del arbol del API de la
 * que se cargan las clases; por omision, este).
 *
 * Ninguna parte toca una base de datos: la bitacora va apagada, authModel es un doble sin conexion, el middleware se crea
 * sin constructor y MULTI_TENANT_ENABLED no esta puesta (la empresa no se resuelve aqui: la fija el escenario).
 */
function ent_hijo_validar(array $escenario): never
{
    error_reporting(E_ALL);
    ini_set('display_errors', '1');   // un aviso suelto cae en "salida" y rompe sus pruebas, sea cual sea el php.ini
    putenv('AUDIT_LOG_ENABLED=false');
    putenv('MULTI_TENANT_ENABLED');   // sin valor: la quita del entorno que heredo del proceso padre
    unset($_ENV['MULTI_TENANT_ENABLED'], $_SERVER['HTTP_X_API_SECRET']);
    $logArchivo = (string) tempnam(sys_get_temp_dir(), 'ent_val_');
    ini_set('error_log', $logArchivo);
    $raiz = (string) ($escenario['raiz'] ?? dirname(__DIR__));
    require_once $raiz . '/src/Middleware/AuthMiddleware.php';

    $modelo = new class ($escenario['validacion'] ?? []) extends authModel {
        /** @var array{actualizar:int,revocar:int} */
        public array $contadores = ['actualizar' => 0, 'revocar' => 0];
        /** @var array{actualizar:list<string>,revocar:list<string>} */
        public array $hashes = ['actualizar' => [], 'revocar' => []];

        // Sin parent::__construct: el constructor de authModel abre la base.
        public function __construct(private array $validacion)
        {
        }

        public function validateToken($token)
        {
            return $this->validacion;
        }

        public function updateLastUsed($token_hash)
        {
            $this->contadores['actualizar']++;
            $this->hashes['actualizar'][] = $token_hash;
        }

        public function revokeTokenByHash($token_hash)
        {
            $this->contadores['revocar']++;
            $this->hashes['revocar'][] = $token_hash;
            return ['success', 'revocado por la prueba'];
        }
    };
    $middleware = (new ReflectionClass('AuthMiddleware'))->newInstanceWithoutConstructor();
    (new ReflectionProperty('AuthMiddleware', 'authModel'))->setValue($middleware, $modelo);
    $actual = new ReflectionProperty('TenantResolver', 'current');
    $actual->setValue(null, $escenario['tenant'] ?? null);

    $_SERVER['REQUEST_METHOD'] = (string) ($escenario['metodo'] ?? 'GET');
    $_SERVER['REQUEST_URI'] = (string) ($escenario['uri'] ?? '/api/facturas');
    $_SERVER['HTTP_X_API_KEY'] = (string) ($escenario['token'] ?? '');

    $estado = ['pasos' => 0, 'retorno' => null];
    ob_start();
    register_shutdown_function(static function () use (&$estado, $modelo, $logArchivo): void {
        $salida = ob_get_level() > 0 ? (string) ob_get_clean() : '';
        $http = http_response_code();
        $log = (string) @file_get_contents($logArchivo);
        @unlink($logArchivo);
        echo "\n", json_encode([
            'salida' => $salida,
            'http' => $http,
            'llamadas' => $modelo->contadores,
            'hashes' => $modelo->hashes,
            'clase_cargada' => class_exists('TenantEntitlements', false),
            'pasos' => $estado['pasos'],
            'retorno' => $estado['retorno'],
            'contexto' => [
                'user_id' => RequestContext::userId(),
                'tenant_id' => RequestContext::tenantId(),
                'token_hash' => RequestContext::sessionTokenHash(),
            ],
            'log' => $log,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR), "\n";
    });
    for ($i = 0, $n = max(1, (int) ($escenario['peticiones'] ?? 1)); $i < $n; $i++) {
        if ($i > 0 && array_key_exists('entre', $escenario)) {
            $actual->setValue(null, $escenario['entre']);
        }
        $estado['retorno'] = $middleware->validateRequest();
        $estado['pasos']++;
    }
    exit(0);
}

/**
 * Modo hijo de la seccion 7: corre src/Controllers/emisorController.php de una copia del arbol del API en un proceso aparte (el
 * controller termina la peticion con exit) y, por ese exit, imprime UNA linea JSON:
 *   {salida, http, clase_cargada, log}
 * donde salida es el cuerpo que contesto, http el codigo que dejo puesto (false si nadie lo puso), clase_cargada si TenantEntitlements
 * llego a cargarse y log lo que se escribio con error_log.
 *
 * Escenario (JSON): tenant (la fila de master.tenants que queda resuelta, o null) y raiz (la copia del arbol; obligatoria). El
 * controller, TenantResolver y TenantEntitlements son los de esa copia, sin tocar; AuthMiddleware y EmisorConfigModel de la copia
 * son dobles que arma la seccion 7 (los reales abren una base en el constructor), y AuthMiddleware deja pasar la sesion siempre: el
 * real contesta 403 antes de que corra el controller cuando la clase de una empresa Express no carga (seccion 4).
 */
function ent_hijo_emisor(array $escenario): never
{
    error_reporting(E_ALL);
    ini_set('display_errors', '1');   // un aviso suelto cae en "salida" y rompe sus pruebas, sea cual sea el php.ini
    $raiz = (string) ($escenario['raiz'] ?? '');
    if ($raiz === '' || !is_file($raiz . '/src/Controllers/emisorController.php')) {
        echo "\n", json_encode(['error' => 'el escenario no trae una raiz con src/Controllers/emisorController.php']), "\n";
        exit(2);
    }
    $logArchivo = (string) tempnam(sys_get_temp_dir(), 'ent_emi_');
    ini_set('error_log', $logArchivo);
    require_once $raiz . '/src/TenantResolver.php';
    (new ReflectionProperty('TenantResolver', 'current'))->setValue(null, $escenario['tenant'] ?? null);
    $_SERVER['REQUEST_METHOD'] = 'GET';

    ob_start();
    register_shutdown_function(static function () use ($logArchivo): void {
        $salida = ob_get_level() > 0 ? (string) ob_get_clean() : '';
        $http = http_response_code();
        $log = (string) @file_get_contents($logArchivo);
        @unlink($logArchivo);
        echo "\n", json_encode([
            'salida' => $salida,
            'http' => $http,
            'clase_cargada' => class_exists('TenantEntitlements', false),
            'log' => $log,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR), "\n";
    });
    require $raiz . '/src/Controllers/emisorController.php';
    exit(0);
}

/**
 * Corre un php hijo, pensado para las herramientas de linea de comandos que validan sus argumentos antes de abrir ninguna conexion
 * (tools/tenant_edicion.php, tools/create_tenant.php). $args son los argumentos despues de php: el script, relativo a la raiz del API, y los
 * suyos. Devuelve lo de wa_correr: ['codigo' => salida del proceso, 'salida' => stdout y stderr juntos].
 *
 * Por que hace falta aislar al hijo: getopt ignora las opciones que todavia no existen, asi que una herramienta a medio escribir (el paso
 * rojo de una prueba) correria su camino completo contra lo que diga el .env de la raiz o el entorno. Aqui el hijo nunca puede alcanzar una
 * base de verdad:
 *   - se niega (imprime [ALTO] y sale con 2) si la raiz del API trae un .env (pos_scratch_revisar_raiz): MasterDatabase lo cargaria;
 *   - corre con -d variables_order=EGPCS, que es como MasterDatabase lee su configuracion ($_ENV), y con MASTER_DB_HOST=127.0.0.1,
 *     MASTER_DB_PORT=1 (un puerto de loopback donde no escucha nadie) y usuarios y claves de mentira para la master (MASTER_DB_*) y para el
 *     administrador de MySQL de create_tenant.php (ADMIN_DB_*). Quien lo llame con create_tenant.php agrega --db-host=127.0.0.1 --db-port=1
 *     a $args: esos dos salen de la linea de comandos y no del entorno.
 * El entorno de este proceso se restaura al terminar, tambien si el hijo falla.
 *
 * @param string[] $args
 * @return array{codigo:int, salida:string}
 */
function express_hijo_aislado(array $args): array
{
    $motivo = pos_scratch_revisar_raiz(dirname(__DIR__));
    if ($motivo !== '') {
        echo "[ALTO] {$motivo}\n";
        exit(2);
    }
    $aislado = [
        'MASTER_DB_HOST' => '127.0.0.1', 'MASTER_DB_PORT' => '1', 'MASTER_DB_USER' => 'aislado_usuario', 'MASTER_DB_PASS' => 'aislado_clave',
        'ADMIN_DB_USER' => 'aislado_admin', 'ADMIN_DB_PASS' => 'aislado_clave_admin',
    ];
    $previo = [];
    foreach (array_keys($aislado) as $nombre) {
        $previo[$nombre] = getenv($nombre);   // false si no estaba puesta
    }
    try {
        foreach ($aislado as $nombre => $valor) {
            putenv("{$nombre}={$valor}");
        }
        return wa_correr(array_merge([PHP_BINARY, '-d', 'variables_order=EGPCS'], $args));
    } finally {
        foreach ($previo as $nombre => $valor) {
            putenv($valor === false ? $nombre : "{$nombre}={$valor}");   // sin valor, putenv la quita del entorno
        }
    }
}

if (wa_arg('hijo') === 'validar') {
    ent_hijo_validar((array) json_decode((string) base64_decode((string) wa_arg('escenario')), true));
}
if (wa_arg('hijo') === 'emisor') {
    ent_hijo_emisor((array) json_decode((string) base64_decode((string) wa_arg('escenario')), true));
}

// ---------------------------------------------------------------------------
// 1) Migracion master 014 y sus espejos (esquema, verificador, README, schema.md)
// ---------------------------------------------------------------------------
echo "\n== 1) Migracion master 014 y sus espejos ==\n";
$s1Raiz = dirname(__DIR__);
/** Texto de un archivo del repo con \r\n normalizado a \n ('' si no existe: las pruebas fallan, no avisan). */
$s1Leer = static fn (string $ruta): string => is_file("{$s1Raiz}/{$ruta}")
    ? str_replace("\r\n", "\n", (string) file_get_contents("{$s1Raiz}/{$ruta}")) : '';
$s1Columnas = ['edicion', 'modulos_habilitados', 'tipos_ecf_habilitados', 'cert_vence'];

$s1M = $s1Leer('db/master_migrations/014_tenants_edicion.sql');
// El codigo de la migracion: sin las lineas "--", para que un comentario no cuente como sentencia.
$s1Codigo = (string) preg_replace('/^\s*--.*$/m', '', $s1M);
// Sus sentencias, cortadas en cada ";" (por eso ningun literal de la 014 lleva ";").
$s1Sent = array_values(array_filter(array_map('trim', explode(';', $s1Codigo)), static fn (string $s): bool => $s !== ''));

wa_chk('014: primera SET @db, segunda la guardia',
    ($s1Sent[0] ?? '') === 'SET @db := DATABASE()' && str_starts_with($s1Sent[1] ?? '', 'SET @guardia :='), array_slice($s1Sent, 0, 2));
wa_chk('014: la guardia cubre NULL y las bases de sistema y prueba tenants',
    str_contains($s1Codigo, "IN ('information_schema', 'mysql', 'performance_schema', 'sys')") && str_contains($s1Codigo, '.tenants LIMIT 1'));
wa_chk('014: el unico SELECT de nivel superior es el ultimo',
    array_keys(array_filter($s1Sent, static fn (string $s): bool => stripos($s, 'SELECT') === 0)) === [count($s1Sent) - 1]);
wa_chk('014: una sonda por columna y un PREPARE por columna mas el de la guardia',
    count(array_filter($s1Columnas, static fn (string $c): bool => str_contains($s1Codigo, "COLUMN_NAME = '{$c}'"))) === 4
    && preg_match_all('/^PREPARE /m', $s1Codigo) === 5);
// Sobre el texto entero, cabecera incluida: ni siquiera un comentario puede prometer un UPDATE.
wa_chk('014: no enciende a nadie', $s1M !== '' && stripos($s1M, 'UPDATE tenants') === false);
wa_chk('014: ENUM, TEXT, VARCHAR(64) y DATE como en el contrato',
    str_contains($s1Codigo, "ENUM(''completa'',''express'') NOT NULL DEFAULT ''completa''")
    && str_contains($s1Codigo, 'modulos_habilitados TEXT NULL')
    && str_contains($s1Codigo, 'tipos_ecf_habilitados VARCHAR(64) NULL')
    && str_contains($s1Codigo, 'cert_vence DATE NULL'));
// Regla de phpMyAdmin (db/migrations/README.md): la base se fija en @db en la primera sentencia y todo lo demas
// la nombra con ella, porque despues de un SELECT sobre information_schema phpMyAdmin cambia la base actual.
$s1Usos = preg_match_all('/\binformation_schema\./i', $s1Codigo);
$s1Filtros = preg_match_all('/\bTABLE_SCHEMA = @db\b/', $s1Codigo);
wa_chk('014: DATABASE() solo en la primera sentencia y cada consulta a information_schema filtra por @db',
    substr_count($s1Codigo, 'DATABASE()') === 1 && $s1Usos > 0 && $s1Usos === $s1Filtros, [substr_count($s1Codigo, 'DATABASE()'), $s1Usos, $s1Filtros]);
preg_match_all('/^\s*PREPARE\s+(\w+)\s+FROM\s+(@\w+)\s*;/mi', $s1Codigo, $s1Prep);
preg_match_all('/^\s*EXECUTE\s+(\w+)\s*;/mi', $s1Codigo, $s1Exec);
preg_match_all('/^\s*DEALLOCATE\s+PREPARE\s+(\w+)\s*;/mi', $s1Codigo, $s1Deal);
wa_chk('014: cada PREPARE tiene su EXECUTE y su DEALLOCATE, y ningun nombre se repite',
    $s1Prep[1] !== [] && $s1Prep[1] === $s1Exec[1] && $s1Prep[1] === $s1Deal[1] && count($s1Prep[1]) === count(array_unique($s1Prep[1])), $s1Prep[1]);

// master_schema.sql: las cuatro columnas, en ese orden, entre inventario_estricto y ambiente (como
// tools/test_inventario_stock.php, que sigue pidiendo pos_enabled < inventario_estricto < ambiente).
$s1Esquema = $s1Leer('db/master_schema.sql');
$s1Pos = static function (string $patron) use ($s1Esquema): ?int {
    return preg_match($patron, $s1Esquema, $encontrado, PREG_OFFSET_CAPTURE) === 1 ? $encontrado[0][1] : null;
};
$s1Orden = [
    $s1Pos('/^\s*inventario_estricto\s+TINYINT/m'),
    $s1Pos('/^\s*edicion\s+ENUM\(/m'),
    $s1Pos('/^\s*modulos_habilitados\s+TEXT/m'),
    $s1Pos('/^\s*tipos_ecf_habilitados\s+VARCHAR\(64\)/m'),
    $s1Pos('/^\s*cert_vence\s+DATE/m'),
    $s1Pos('/^\s*ambiente\s+VARCHAR/m'),
];
$s1Ordenado = !in_array(null, $s1Orden, true);
for ($s1I = 1; $s1Ordenado && $s1I < count($s1Orden); $s1I++) {
    $s1Ordenado = $s1Orden[$s1I - 1] < $s1Orden[$s1I];
}
wa_chk('master_schema.sql: edicion, modulos_habilitados, tipos_ecf_habilitados y cert_vence, en ese orden, entre inventario_estricto y ambiente',
    $s1Ordenado, $s1Orden);
// Un tenant nuevo (master_schema.sql) y uno migrado (014) tienen que quedar iguales: misma definicion y mismo COMMENT.
$s1Distintas = [];
foreach ($s1Columnas as $s1Col) {
    $s1EnMigracion = preg_match("/ADD COLUMN {$s1Col} (.+?) COMMENT ''(.*?)'''\s*,/s", $s1Codigo, $s1Hallado) === 1
        ? [trim(str_replace("''", "'", $s1Hallado[1])), $s1Hallado[2]] : null;
    $s1EnEsquema = preg_match("/^\s*{$s1Col}\s+(.+?)\s+COMMENT\s+'([^']*)'/ms", $s1Esquema, $s1Hallado) === 1
        ? [trim((string) preg_replace('/\s+/', ' ', $s1Hallado[1])), $s1Hallado[2]] : null;
    if ($s1EnMigracion === null || $s1EnMigracion !== $s1EnEsquema) {
        $s1Distintas[$s1Col] = ['014' => $s1EnMigracion, 'master_schema' => $s1EnEsquema];
    }
}
wa_chk('master_schema.sql repite la definicion y el COMMENT de cada columna de la 014', $s1Distintas === [], $s1Distintas);

// Verificador de migraciones del master: la fila 014 pide las cuatro columnas, y la cabecera la nombra.
$s1Verificador = $s1Leer('tools/verificar_migraciones_master.sql');
wa_chk('verificador del master: la fila 014 cuenta las cuatro columnas de tenants (= 4)',
    preg_match("/SELECT '014', '014_tenants_edicion\\.sql',\\s*'[^']*',\\s*"
        . "\\(SELECT COUNT\\(\\*\\) FROM information_schema\\.COLUMNS\\s+WHERE TABLE_SCHEMA = DATABASE\\(\\) AND TABLE_NAME = 'tenants'\\s+"
        . "AND COLUMN_NAME IN \\('edicion', 'modulos_habilitados', 'tipos_ecf_habilitados', 'cert_vence'\\)\\) = 4/", $s1Verificador) === 1);
wa_chk('verificador del master: la cabecera menciona la 014',
    preg_match('/\A(?:--[^\n]*\n)+/', $s1Verificador, $s1Cabecera) === 1 && str_contains($s1Cabecera[0], '014'));

// README de db/migrations y docs/database/schema.md (este estaba atrasado).
// La lista del README son TODAS las migraciones del master que condicionan cada cambio a information_schema y
// lo ejecutan con PREPARE/EXECUTE (007, 008, 010, 011, 012, 013 y 014; la lista del tenant, 018 y 026 a 035, ya
// era completa): quien agregue otra del mismo estilo la suma al README y a este texto en el mismo cambio.
wa_chk('README de migraciones: en el master, 007, 008, 010, 011, 012, 013 y 014 son las idempotentes con PREPARE/EXECUTE',
    str_contains($s1Leer('db/migrations/README.md'), 'en el master, 007, 008, 010, 011, 012, 013 y 014'));
$s1Doc = $s1Leer('docs/database/schema.md');
$s1Faltan = array_values(array_filter(
    ['edicion', 'modulos_habilitados', 'tipos_ecf_habilitados', 'cert_vence', 'inventario_estricto', 'pos_enabled', 'grupo_id',
     'audit_logs', 'pos_handoff_codes', 'pos_equipos'],
    static fn (string $t): bool => !str_contains($s1Doc, $t)
));
wa_chk('schema.md: la master lista audit_logs, pos_handoff_codes y pos_equipos, y tenants trae grupo_id, pos_enabled, inventario_estricto y las cuatro de la 014',
    $s1Doc !== '' && $s1Faltan === [], $s1Faltan);
wa_chk('schema.md: el snapshot del tenant llega hasta la 035 (001–035 y 012–035) y no queda 001–031 ni 012–031',
    substr_count($s1Doc, '001–035') >= 2 && str_contains($s1Doc, '012–035')
    && !str_contains($s1Doc, '001–031') && !str_contains($s1Doc, '012–031'));

// ---------------------------------------------------------------------------
// 2) config/editions.php y el nucleo de TenantEntitlements
// ---------------------------------------------------------------------------
echo "\n== 2) config/editions.php y TenantEntitlements (nucleo) ==\n";

// Incluir la clase y leer la config no debe imprimir nada (ni un BOM ni un salto despues de la etiqueta de cierre de
// PHP): la clase se carga en medio de una peticion que contesta JSON.
$s2Antes = get_included_files();
ob_start();
require_once __DIR__ . '/../src/TenantEntitlements.php';
$s2Config = TenantEntitlements::config();
$s2Salida = (string) ob_get_clean();
wa_chk('incluir la clase y cargar config/editions.php no imprime nada', $s2Salida === '', $s2Salida);
// La clase incluye src/TenantResolver.php y este, src/MasterDatabase.php y src/Database.php (codigo que este plan no
// toca). El "no imprime nada" de arriba solo habla de ellos si entraron dentro de esa captura, es decir, si ninguna
// seccion anterior los habia cargado. Y "no abre conexion" se mide aqui: los dos son singletons perezosos (la conexion
// nace en getInstance(), no al incluir el archivo), asi que tras incluir ninguno tiene instancia y Database no tiene
// credenciales.
$s2Nuevos = array_map('basename', array_values(array_diff(get_included_files(), $s2Antes)));
wa_chk('la captura cubre la cadena de includes: TenantEntitlements, TenantResolver, MasterDatabase y Database entraron dentro de ella',
    array_diff(['TenantEntitlements.php', 'TenantResolver.php', 'MasterDatabase.php', 'Database.php'], $s2Nuevos) === [], $s2Nuevos);
$s2Instancia = static fn (string $clase): mixed => (new ReflectionProperty($clase, 'instance'))->getValue();
$s2Conexion = [
    'Database tiene instancia' => $s2Instancia('Database') !== null,
    'MasterDatabase tiene instancia' => $s2Instancia('MasterDatabase') !== null,
    'Database tiene credenciales' => Database::hasCredentials(),
];
wa_chk('incluir la clase no abre conexion: Database y MasterDatabase sin instancia, Database sin credenciales',
    !in_array(true, $s2Conexion, true), $s2Conexion);

$s2Preset = ['facturacion', 'clientes', 'productos', 'usuarios', 'configuracion'];
wa_chk('config(): preset, tipos de venta, permisos, rol Facturador y plazos como en el contrato',
    $s2Config['modulos'] === $s2Preset
    && $s2Config['tipos_venta'] === ['31', '32', '45']
    && $s2Config['permisos'] === ['facturacion' => 'facturas', 'clientes' => 'clients', 'productos' => 'products', 'usuarios' => 'users', 'configuracion' => 'ncf']
    && $s2Config['rol_user'] === ['facturas', 'clients', 'products', 'unidades']
    && $s2Config['TOKEN_INACTIVIDAD_DIAS'] === 30 && $s2Config['CERT_POR_VENCER_DIAS'] === 60 && $s2Config['CERT_CRITICO_DIAS'] === 15
    && is_array($s2Config['rutas']) && is_array($s2Config['CAPACIDADES']), $s2Config);
// Un typo en un token (client por clients) negaria una pantalla entera sin avisar: los tokens que la config le da al
// gate tienen que ser los del catalogo de permisos.
$s2Catalogo = (static fn (): array => (require __DIR__ . '/../config/permissions.php')['catalog'])();
$s2Fuera = array_values(array_diff(array_merge(array_values($s2Config['permisos']), $s2Config['rol_user'], ['unidades']), $s2Catalogo));
wa_chk('los tokens de permisos y de rol_user existen en el catalogo de config/permissions.php', $s2Fuera === [], $s2Fuera);

$s2Ref = new ReflectionClass('TenantEntitlements');
wa_chk('TenantEntitlements es final, sin namespace y todos sus metodos son estaticos',
    $s2Ref->isFinal() && !$s2Ref->inNamespace()
    && array_filter($s2Ref->getMethods(), static fn (ReflectionMethod $m): bool => !$m->isStatic()) === []);

/** Una fila de master.tenants de una empresa Express; $o pisa los campos que cada caso cambia. */
$s2Fila = static fn (array $o = []): array => $o + [
    'id' => 12, 'nombre' => 'Constructora X', 'rnc' => '131000001', 'tipo' => 'app', 'ambiente' => 'ecf',
    'edicion' => 'express', 'tipos_ecf_habilitados' => '45', 'modulos_habilitados' => null,
];

// --- csv y la edicion -------------------------------------------------------
wa_chk('csv: trim, vacios fuera, sin repetidos, orden de aparicion',
    TenantEntitlements::csv(' 45, ,31,45,') === ['45', '31'] && TenantEntitlements::csv(null) === []
    && TenantEntitlements::csv('') === [] && TenantEntitlements::csv(' , ') === []);
foreach (['express', ' Express ', 'EXPRESS'] as $s2V) {
    wa_chk("esRestringido('{$s2V}')", TenantEntitlements::esRestringido($s2Fila(['edicion' => $s2V])) === true);
}
// wa_con_log (tools/write_ahead_test_lib.php) no es de este plan y todas las pruebas de log de abajo lo desarman como
// [resultado, excepcion o null, texto del log]: se fija esa forma aqui, para que un cambio del ayudante falle con su nombre.
$s2SinFallo = wa_con_log(static function (): int {
    error_log('marca-wa-con-log');
    return 7;
});
$s2ConFallo = wa_con_log(static function (): void {
    throw new RuntimeException('marca-excepcion');
});
wa_chk('wa_con_log devuelve [resultado, excepcion o null, texto del log]: sin fallo, [7, null, la linea]; si lanza, [null, la excepcion, vacio]',
    count($s2SinFallo) === 3 && $s2SinFallo[0] === 7 && $s2SinFallo[1] === null
    && substr_count((string) $s2SinFallo[2], 'marca-wa-con-log') === 1 && substr_count((string) $s2SinFallo[2], "\n") === 1
    && count($s2ConFallo) === 3 && $s2ConFallo[0] === null && $s2ConFallo[1] instanceof RuntimeException
    && $s2ConFallo[1]->getMessage() === 'marca-excepcion' && $s2ConFallo[2] === '',
    [$s2SinFallo, $s2ConFallo]);
// 'completa', en cualquier forma, es un valor conocido: no escribe nada en el log.
[, , $s2Log] = wa_con_log(function () use ($s2Fila) {
    foreach (['completa', 'Completa', ' completa '] as $s2V) {
        TenantEntitlements::normalizarEdicion($s2Fila(['edicion' => $s2V]));
    }
});
wa_chk("'completa' (con mayusculas o espacios) no escribe en el log", $s2Log === '', $s2Log);
// Dentro de wa_con_log: las lineas de los valores desconocidos ('' y 'basico') no deben llegar a stderr.
[, $s2Error, $s2Log] = wa_con_log(function () use ($s2Fila) {
    foreach (['completa', 'Completa', ' completa ', '', 'basico'] as $s2V) {
        wa_chk("esRestringido('{$s2V}') es false", TenantEntitlements::esRestringido($s2Fila(['edicion' => $s2V])) === false);
    }
});
wa_chk('esRestringido con una edicion que no es express no lanza', $s2Error === null, $s2Error?->getMessage());
wa_chk("valores desconocidos: el vacio y 'basico' escriben una linea cada uno (completa, ninguna)",
    substr_count($s2Log, "\n") === 2 && substr_count($s2Log, 'basico') === 1, $s2Log);
wa_chk('integracion con edicion express: false', !TenantEntitlements::esRestringido($s2Fila(['tipo' => 'integracion'])));
wa_chk('columna ausente, sin fila: false', !TenantEntitlements::esRestringido(['tipo' => 'app']) && !TenantEntitlements::esRestringido(null));
// La linea de log se memoriza una vez por valor distinto y por proceso: este caso usa un valor que ningun caso anterior uso.
[, , $s2Log] = wa_con_log(function () use ($s2Fila) {
    TenantEntitlements::esRestringido($s2Fila(['edicion' => 'desconocido-x']));
    TenantEntitlements::esRestringido($s2Fila(['edicion' => 'desconocido-x']));
    TenantEntitlements::esRestringido(['tipo' => 'app']);
});
wa_chk('valor desconocido: una sola linea de log; ausente: silencio', substr_count($s2Log, 'desconocido-x') === 1 && substr_count($s2Log, "\n") === 1, $s2Log);
// Otro valor distinto escribe la suya; y un salto de linea dentro del valor no parte la linea (quien edite la columna
// no puede inventar lineas de log).
[, , $s2Log] = wa_con_log(function () use ($s2Fila) {
    TenantEntitlements::normalizarEdicion($s2Fila(['edicion' => "desconocido-y\nlinea falsa"]));
    TenantEntitlements::normalizarEdicion($s2Fila(['edicion' => 'desconocido-z']));
});
wa_chk('otro valor desconocido escribe su propia linea y el salto dentro del valor no parte la linea',
    substr_count($s2Log, "\n") === 2 && substr_count($s2Log, 'desconocido-z') === 1, $s2Log);
[$s2Norm] = wa_con_log(static fn (): array => [
    TenantEntitlements::normalizarEdicion($s2Fila(['edicion' => ' Express '])),
    TenantEntitlements::normalizarEdicion($s2Fila(['edicion' => 'Completa'])),
    TenantEntitlements::normalizarEdicion($s2Fila(['edicion' => 'basico'])),
    TenantEntitlements::normalizarEdicion(['tipo' => 'app']),
    TenantEntitlements::normalizarEdicion(null),
]);
wa_chk('normalizarEdicion: express (con trim y minusculas) o completa', $s2Norm === ['express', 'completa', 'completa', 'completa', 'completa'], $s2Norm);

// --- modulos, tipos y permisos efectivos (RF2: un typo angosta, nunca ensancha; el orden es el canonico) --------------
foreach ([
    [null, $s2Preset], ['', $s2Preset], [' , ', $s2Preset],
    ['facturacion,configuracion', ['facturacion', 'configuracion']],
    ['configuracion,facturacion', ['facturacion', 'configuracion']],
    ['facturacion,cliente', ['facturacion']],
    ['foo', []],
] as [$s2Entrada, $s2Esperado]) {
    $s2Obtenido = TenantEntitlements::modulosEfectivos($s2Fila(['modulos_habilitados' => $s2Entrada]));
    wa_chk('modulosEfectivos(' . var_export($s2Entrada, true) . ')', $s2Obtenido === $s2Esperado, $s2Obtenido);
}
foreach ([
    ['45', ['45']], ['45, 31,', ['31', '45']],
    ['e45', []], ['44,46,41,43,47', []], ['33', []], [null, []], ['', []],
] as [$s2Entrada, $s2Esperado]) {
    $s2Obtenido = TenantEntitlements::tiposVenta($s2Fila(['tipos_ecf_habilitados' => $s2Entrada]));
    wa_chk('tiposVenta(' . var_export($s2Entrada, true) . ')', $s2Obtenido === $s2Esperado, $s2Obtenido);
}
// El 34 se deriva de cualquier tipo de venta; el 33 solo si esta en la lista, y va siempre al final.
foreach ([
    ['45', ['45', '34']], ['31,32,45', ['31', '32', '45', '34']], ['45,33', ['45', '34', '33']], ['33,45', ['45', '34', '33']],
    ['33', ['33']], [null, []], ['', []],
] as [$s2Entrada, $s2Esperado]) {
    $s2Obtenido = TenantEntitlements::tiposEfectivos($s2Fila(['tipos_ecf_habilitados' => $s2Entrada]));
    wa_chk('tiposEfectivos(' . var_export($s2Entrada, true) . ')', $s2Obtenido === $s2Esperado, $s2Obtenido);
}
// Permisos: el rol cruzado con los tokens habilitados; '*' se expande; 'unidades' (catalogos compartidos) va
// siempre que facturacion, clientes o productos este habilitado.
$s2Todos = ['facturas', 'clients', 'products', 'users', 'ncf', 'unidades'];
$s2Facturador = ['facturas', 'clients', 'products', 'unidades'];
foreach ([
    ['admin (*), todo habilitado', ['*'], [], $s2Todos],
    ['rol user con gastos de mas: gastos no esta entre los habilitados', [...$s2Facturador, 'gastos'], [], $s2Facturador],
    ['admin angostado a facturacion,configuracion', ['*'], ['modulos_habilitados' => 'facturacion,configuracion'], ['facturas', 'ncf', 'unidades']],
    ['solo usuarios habilitado: sin unidades', ['*'], ['modulos_habilitados' => 'usuarios'], ['users']],
    ['solo configuracion habilitado: sin unidades', ['*'], ['modulos_habilitados' => 'configuracion'], ['ncf']],
    ['solo clientes habilitado: con unidades', ['*'], ['modulos_habilitados' => 'clientes'], ['clients', 'unidades']],
    ['solo productos habilitado: con unidades', ['*'], ['modulos_habilitados' => 'productos'], ['products', 'unidades']],
    ['rol user angostado a facturacion: ni clients ni products', $s2Facturador, ['modulos_habilitados' => 'facturacion'], ['facturas', 'unidades']],
    ['rol sin permisos', [], [], []],
] as [$s2Desc, $s2Rol, $s2Cambios, $s2Esperado]) {
    $s2Obtenido = TenantEntitlements::permisosEfectivos($s2Rol, $s2Fila($s2Cambios));
    wa_chk("permisosEfectivos: {$s2Desc}", $s2Obtenido === $s2Esperado, $s2Obtenido);
}

// --- bloque (contrato 4.3(a)): solo campos de la lista blanca ----------------------------------------------------------
$s2Bloque = TenantEntitlements::bloque($s2Fila());
wa_chk('bloque: la forma exacta del contrato 4.3(a)',
    $s2Bloque === [
        'edicion' => 'express',
        'tenant' => ['id' => 12, 'nombre' => 'Constructora X', 'rnc' => '131000001', 'ambiente' => 'ecf'],
        'modulos' => $s2Preset,
        'tipos_ecf' => ['45', '34'],
        'capacidades' => ['idempotencia' => false, 'reconciliador' => false, 'certificado' => true],
    ], $s2Bloque);
$s2Secretos = [
    'cert_path' => 'MARCA-cert-path', 'cert_pass_encrypted' => 'MARCA-cert-pass', 'api_secret_hash' => 'MARCA-api-secret',
    'db_pass_encrypted' => 'MARCA-db-pass', 'webhook_secret_encrypted' => 'MARCA-webhook',
];
$s2ConSecretos = TenantEntitlements::bloque($s2Fila($s2Secretos));
$s2Json = (string) json_encode($s2ConSecretos);
$s2Filtrados = array_keys(array_filter($s2Secretos, static fn (string $marca): bool => str_contains($s2Json, $marca)));
wa_chk('bloque: ningun campo secreto de la fila llega al JSON, y el bloque no depende de ellos',
    $s2Json !== '' && $s2Filtrados === [] && $s2ConSecretos === $s2Bloque, $s2Filtrados);
$s2Angosto = TenantEntitlements::bloque($s2Fila(['id' => '12', 'modulos_habilitados' => 'facturacion,configuracion', 'tipos_ecf_habilitados' => '31,45,33']));
wa_chk('bloque: usa lo efectivo (no el preset) y el id sale entero aunque la fila lo traiga como texto, como lo da PDO',
    $s2Angosto['tenant']['id'] === 12 && $s2Angosto['modulos'] === ['facturacion', 'configuracion']
    && $s2Angosto['tipos_ecf'] === ['31', '45', '34', '33'], $s2Angosto);

// --- null = la empresa resuelta (TenantResolver::current()) -------------------------------------------------------
require_once __DIR__ . '/../src/TenantResolver.php';
$s2Resuelta = static function (?array $tenant): void {
    (new ReflectionProperty(TenantResolver::class, 'current'))->setValue(null, $tenant);
};
$s2Resuelta(null);
wa_chk('sin empresa resuelta (el caso de este proceso): restringido() es false', TenantEntitlements::restringido() === false);
$s2Resuelta($s2Fila(['modulos_habilitados' => 'facturacion', 'tipos_ecf_habilitados' => '31']));
try {
    wa_chk('con una empresa express resuelta: restringido() es true y, sin argumento, las funciones leen su fila',
        TenantEntitlements::restringido() === true
        && TenantEntitlements::modulosEfectivos() === ['facturacion']
        && TenantEntitlements::tiposVenta() === ['31']
        && TenantEntitlements::tiposEfectivos() === ['31', '34']
        && TenantEntitlements::permisosEfectivos(['*']) === ['facturas', 'unidades']);
    wa_chk('una fila que se pasa de forma explicita gana sobre la empresa resuelta',
        TenantEntitlements::modulosEfectivos($s2Fila(['modulos_habilitados' => 'usuarios'])) === ['usuarios']);
    $s2Resuelta($s2Fila(['edicion' => 'completa']));
    wa_chk('con una empresa completa resuelta: restringido() es false', TenantEntitlements::restringido() === false);
} finally {
    $s2Resuelta(null);   // no dejar una empresa resuelta a las secciones que siguen
}

// ---------------------------------------------------------------------------
// 3) evaluarRuta, guardaRuta y responderError (lista de rutas, por defecto denegado)
// ---------------------------------------------------------------------------
echo "\n== 3) evaluarRuta, guardaRuta y responderError ==\n";

/** Una fila de master.tenants de una empresa Express; $o pisa los campos que cada caso cambia. */
$s3Fila = static fn (array $o = []): array => $o + [
    'id' => 12, 'nombre' => 'Constructora X', 'rnc' => '131000001', 'tipo' => 'app', 'ambiente' => 'ecf',
    'edicion' => 'express', 'tipos_ecf_habilitados' => '45', 'modulos_habilitados' => null,
];
$s3Preset = TenantEntitlements::config()['modulos'];
/** evaluarRuta de una peticion escrita 'METODO uri' (por defecto, el administrador de la empresa Express base). */
$s3Ruta = static function (string $peticion, ?string $rol = 'admin', ?array $tenant = null) use ($s3Fila): array {
    [$metodo, $uri] = explode(' ', $peticion, 2);
    return TenantEntitlements::evaluarRuta($metodo, $uri, $rol, $tenant ?? $s3Fila());
};

$s3Permitidas = [   // -> 200
    'GET /api/facturas', 'GET /api/facturas?lean=1&archivadas=0&estado=accion&client_id=5', 'GET /api/facturas/stats?stats=light',
    'GET /api/facturas/avisos', 'GET /api/facturas/modificables?e_ncf=E450000000001', 'GET /api/facturas/12/pdf?format=datos&formato=pos',
    'GET /api/facturas/12/xml', 'GET /api/facturas/12/estado', 'POST /api/facturas', 'POST /api/facturas/12/reenviar',
    'GET /api/clients?orden=uso', 'POST /api/clients', 'PUT /api/clients', 'GET /api/clients/7', 'GET /api/clients?query=Constr...',
    'GET /api/products?activo=1&facturable=1', 'POST /api/products', 'PUT /api/products/3',
    'GET /api/rnc/consulta?rnc=131000001', 'GET /api/unidades-medida', 'GET /api/emisor', 'GET /api/ncf/rangos', 'POST /api/ncf/rangos',
    'GET /api/users', 'POST /api/users', 'PUT /api/users/5', 'DELETE /api/users/5',
    'GET /api/auth/me', 'POST /api/auth/signout', 'GET //api/facturas',
];
$s3Denegadas = [   // -> 403 EDICION_NO_INCLUYE
    'GET /api/gastos', 'GET /api/inventario/movimientos', 'GET /api/facturacion-electronica/estado', 'GET /api/cotizaciones', 'GET /api/conduces',
    'GET /api/pos-admin/cajas', 'GET /api/pos/estado', 'GET /api/reportes/607', 'GET /api/roles', 'PUT /api/ncf', 'GET /api/ncf/sequence',
    'GET /api/ecf', 'GET /api/audit-logs', 'GET /api/branding', 'GET /api/categories', 'GET /api/integracion/ecf',
    'POST /api/auth/pos-handoff', 'POST /api/auth/pos-handoff/canje', 'POST /api/auth/login',
    'POST /api/facturas/preview', 'PUT /api/facturas', 'DELETE /api/facturas', 'DELETE /api/clients',
    'GET /api/products/imagen', 'POST /api/products/imagen', 'GET /api/facturas/12/pdf/extra', 'GET /api/facturas/stats/x',
    'GET /api/facturas/12/estado%0A', 'GET /api/facturas/', 'GET /api/auth/me/', 'GET /api/FACTURAS', 'GET /api/facturas/x/api/gastos',
    'GET //api/gastos', 'HEAD /api/facturas', 'GET /api/facturas/12/reenviar',
];
$s3Invalidas = [   // -> 400 SOLICITUD_INVALIDA
    'GET /api/facturas/../gastos', 'GET /api/facturas/%2e%2e/gastos', 'GET /api/facturas/%2E%2e/gastos', 'GET /api/facturas/.%2e/gastos',
    'GET /api/facturas?x=/api/ncf/sequence', 'GET /api/clients?query=/api/gastos',
];
foreach ($s3Permitidas as $s3P) {
    $s3R = $s3Ruta($s3P);
    wa_chk("permite {$s3P}", $s3R['permitido'] === true && $s3R['http'] === 200 && $s3R['codigo'] === null, $s3R);
}
foreach ($s3Denegadas as $s3P) {
    $s3R = $s3Ruta($s3P);
    wa_chk("niega con 403 EDICION_NO_INCLUYE {$s3P}",
        $s3R['permitido'] === false && $s3R['http'] === 403 && $s3R['codigo'] === 'EDICION_NO_INCLUYE', $s3R);
}
foreach ($s3Invalidas as $s3P) {
    $s3R = $s3Ruta($s3P);
    wa_chk("rechaza con 400 SOLICITUD_INVALIDA {$s3P}",
        $s3R['permitido'] === false && $s3R['http'] === 400 && $s3R['codigo'] === 'SOLICITUD_INVALIDA', $s3R);
}

// Mas alla de las tres listas del plan. Casi-coincidencias que tienen que seguir negadas (cada path es exacto: ni un id que
// no es numero, ni una barra de mas, ni otro metodo) y la ruta sacada como la saca el Router (src/Router.php): despues del
// PRIMER '/api/', sin las barras iniciales del resto, y desde el principio del path cuando no hay '/api/'.
$s3Cercanas = [   // -> 403 EDICION_NO_INCLUYE
    'GET /api/facturas/12', 'GET /api/facturas/preview', 'GET /api/facturas/12/pdf/', 'GET /api/facturas/12/PDF', 'POST /api/facturas/12',
    'POST /api/facturas/12/reenviar/x', 'GET /api/facturas//stats',
    'GET /api/clients/abc', 'GET /api/clients/-1', 'GET /api/clients/7.5', 'GET /api/clients/7/x', 'POST /api/clients/7/x',
    'GET /api/products/3/imagen', 'PUT /api/products/abc',
    'GET /api/rnc', 'GET /api/rnc/consulta/x', 'POST /api/rnc/consulta', 'GET /api/unidades-medida/1', 'GET /api/emisor/x', 'PUT /api/emisor',
    'GET /api/auth/signout', 'POST /api/auth/me', 'POST /api/auth/signout/x',
    'PUT /api/ncf/rangos', 'DELETE /api/ncf/rangos', 'GET /api/ncf/rangos/1',
    'GET /api/users/abc', 'GET /api/users/5/x', 'PATCH /api/users/5',
    'GET /api/gastos/x/api/facturas', 'GET /api//gastos', 'GET /gratex/api/gastos', 'GET /gastos',
];
$s3DelRouter = [   // -> 200: el Router los manda a la ruta permitida
    'GET /api//facturas', 'GET /gratex/api/facturas', 'GET /facturas',
];
foreach ($s3Cercanas as $s3P) {
    $s3R = $s3Ruta($s3P);
    wa_chk("niega con 403 EDICION_NO_INCLUYE (casi coincide) {$s3P}",
        $s3R['permitido'] === false && $s3R['http'] === 403 && $s3R['codigo'] === 'EDICION_NO_INCLUYE', $s3R);
}
foreach ($s3DelRouter as $s3P) {
    $s3R = $s3Ruta($s3P);
    wa_chk("permite {$s3P} (el Router lo manda a la ruta permitida)",
        $s3R['permitido'] === true && $s3R['http'] === 200 && $s3R['codigo'] === null, $s3R);
}
$s3Todas = array_merge($s3Permitidas, $s3Denegadas, $s3Invalidas, $s3Cercanas, $s3DelRouter);

// evaluarRuta es pura: recorrer todas las listas con una empresa Express no imprime nada ni escribe en el log.
ob_start();
[, $s3Error, $s3Log] = wa_con_log(function () use ($s3Todas, $s3Ruta): void {
    foreach ($s3Todas as $s3P) {
        $s3Ruta($s3P);
    }
});
$s3Salida = (string) ob_get_clean();
wa_chk('evaluarRuta es pura: recorrer todas las listas con una empresa Express no imprime nada ni escribe en el log',
    $s3Error === null && $s3Salida === '' && $s3Log === '', [$s3Error?->getMessage(), $s3Salida, $s3Log]);

// --- el veredicto: su forma, el modulo, el metodo en minusculas, la URI que parse_url no puede leer ----------------------
$s3R = $s3Ruta('GET /api/facturas');
wa_chk('evaluarRuta devuelve exactamente permitido, http, codigo y modulo, en ese orden',
    array_keys($s3R) === ['permitido', 'http', 'codigo', 'modulo'], $s3R);
$s3Modulos = [];
foreach ([
    // [peticion, rol, cambios de la fila, modulo esperado]
    ['GET /api/facturas', 'admin', [], 'facturacion'],
    ['GET /api/unidades-medida', 'admin', [], 'facturacion'],                      // dos modulos: el primero
    ['GET /api/auth/me', 'admin', [], null],                                       // la fila no pide modulo
    ['GET /api/gastos', 'admin', [], null],                                        // ninguna fila coincide
    ['GET /api/users', 'admin', [], 'usuarios'],
    ['POST /api/ncf/rangos', 'user', [], 'configuracion'],                         // negada por el rol
    ['GET /api/clients', 'admin', ['modulos_habilitados' => 'usuarios'], 'clientes'], // negada por el modulo
] as [$s3P, $s3Rol, $s3Cambios, $s3Esperado]) {
    $s3Obtenido = $s3Ruta($s3P, $s3Rol, $s3Fila($s3Cambios))['modulo'];
    if ($s3Obtenido !== $s3Esperado) {
        $s3Modulos[$s3P] = $s3Obtenido;
    }
}
wa_chk("evaluarRuta: 'modulo' es el primer modulo de la fila que coincide, o null", $s3Modulos === [], $s3Modulos);
$s3R = TenantEntitlements::evaluarRuta('get', '/api/facturas', 'admin', $s3Fila());
wa_chk('el metodo se compara en mayusculas: get lleva a la fila de GET', $s3R['permitido'] === true && $s3R['http'] === 200, $s3R);
$s3R = $s3Ruta('GET http:///x');
wa_chk('una URI que parse_url no puede leer es 400 SOLICITUD_INVALIDA',
    $s3R['permitido'] === false && $s3R['http'] === 400 && $s3R['codigo'] === 'SOLICITUD_INVALIDA', $s3R);

// --- null = la empresa resuelta (TenantResolver::current()) ------------------------------------------------------------
$s3Resuelta = static function (?array $tenant): void {
    (new ReflectionProperty(TenantResolver::class, 'current'))->setValue(null, $tenant);
};
$s3Resuelta(null);
$s3SinEmpresa = TenantEntitlements::evaluarRuta('GET', '/api/gastos', 'admin');
$s3Resuelta($s3Fila());
try {
    $s3ConEmpresa = [
        TenantEntitlements::evaluarRuta('GET', '/api/gastos', 'admin'),
        TenantEntitlements::evaluarRuta('GET', '/api/facturas', 'admin'),
    ];
} finally {
    $s3Resuelta(null);   // no dejar una empresa resuelta a las secciones que siguen
}
wa_chk('sin empresa resuelta, evaluarRuta deja pasar (200); con una Express resuelta aplica su lista',
    $s3SinEmpresa['permitido'] === true && $s3SinEmpresa['http'] === 200
    && $s3ConEmpresa[0]['http'] === 403 && $s3ConEmpresa[0]['codigo'] === 'EDICION_NO_INCLUYE' && $s3ConEmpresa[1]['permitido'] === true,
    [$s3SinEmpresa, $s3ConEmpresa]);

// --- modulos habilitados: el modulo lo da la empresa, no el rol (tambien niega a un administrador con '*') ---------------
$s3Angosto = $s3Fila(['modulos_habilitados' => 'facturacion,configuracion']);
$s3Fallan = array_values(array_filter(
    ['GET /api/clients', 'POST /api/clients', 'GET /api/products', 'GET /api/rnc/consulta', 'GET /api/users'],
    static fn (string $p): bool => ($s3R = $s3Ruta($p, 'admin', $s3Angosto))['http'] !== 403 || $s3R['codigo'] !== 'EDICION_NO_INCLUYE'
));
wa_chk('modulos_habilitados facturacion,configuracion: clients, products, rnc y users dan 403 EDICION_NO_INCLUYE aun para un administrador',
    $s3Fallan === [], $s3Fallan);
$s3Fallan = array_values(array_filter(
    ['GET /api/facturas', 'GET /api/emisor', 'GET /api/ncf/rangos', 'GET /api/unidades-medida', 'GET /api/auth/me'],
    static fn (string $p): bool => ($s3R = $s3Ruta($p, 'admin', $s3Angosto))['permitido'] !== true || $s3R['http'] !== 200
));
wa_chk('modulos_habilitados facturacion,configuracion: facturas, emisor, ncf/rangos, unidades-medida y auth/me siguen en 200',
    $s3Fallan === [], $s3Fallan);
$s3R = $s3Ruta('GET /api/ncf/rangos', 'admin', $s3Fila(['modulos_habilitados' => 'facturacion']));
wa_chk('modulos_habilitados facturacion: GET /api/ncf/rangos da 403 EDICION_NO_INCLUYE',
    $s3R['http'] === 403 && $s3R['codigo'] === 'EDICION_NO_INCLUYE', $s3R);

// --- RF1: el Facturador en una ruta de administrador: 403 sin codigo (nunca EDICION_NO_INCLUYE) --------------------------
$s3Admin = ['POST /api/ncf/rangos', 'GET /api/users', 'PUT /api/users/5'];
$s3Fallan = array_values(array_filter(
    $s3Admin,
    static fn (string $p): bool => ($s3R = $s3Ruta($p, 'user'))['permitido'] !== false || $s3R['http'] !== 403 || $s3R['codigo'] !== null
));
wa_chk('RF1: el rol user en POST /api/ncf/rangos, GET /api/users y PUT /api/users/5 recibe 403 con codigo null', $s3Fallan === [], $s3Fallan);
$s3Fallan = array_values(array_filter(
    $s3Admin,
    static fn (string $p): bool => ($s3R = $s3Ruta($p, null))['permitido'] !== false || $s3R['http'] !== 403 || $s3R['codigo'] !== null
));
wa_chk('RF1: sin rol (null) en esas tres rutas recibe 403 con codigo null', $s3Fallan === [], $s3Fallan);
$s3Fallan = [];
foreach (['ADMIN', ' admin '] as $s3Rol) {
    foreach ($s3Admin as $s3P) {
        $s3R = $s3Ruta($s3P, $s3Rol);
        if ($s3R['permitido'] !== true || $s3R['http'] !== 200) {
            $s3Fallan["{$s3Rol} {$s3P}"] = $s3R;
        }
    }
}
wa_chk("RF1: el nombre del rol admin se compara sin mayusculas ni espacios ('ADMIN', ' admin ' pasan con 200)", $s3Fallan === [], $s3Fallan);

// --- fila por fila, con la tabla del plan escrita a mano (no se deriva de la config que se prueba) -------------------------
// Para cada peticion: los modulos que la dejan pasar (null = la sesion, sin modulo) y si es de administrador.
$s3Tabla = [
    'GET /api/facturas' => [['facturacion'], false], 'POST /api/facturas' => [['facturacion'], false],
    'GET /api/facturas/12/estado' => [['facturacion'], false], 'POST /api/facturas/12/reenviar' => [['facturacion'], false],
    'GET /api/clients' => [['clientes'], false], 'POST /api/clients' => [['clientes'], false], 'PUT /api/clients/7' => [['clientes'], false],
    'GET /api/products' => [['productos'], false], 'POST /api/products' => [['productos'], false], 'PUT /api/products/3' => [['productos'], false],
    'GET /api/rnc/consulta' => [['clientes'], false],
    'GET /api/unidades-medida' => [['facturacion', 'productos'], false],
    'GET /api/emisor' => [['facturacion', 'configuracion'], false],
    'GET /api/ncf/rangos' => [['configuracion'], false], 'POST /api/ncf/rangos' => [['configuracion'], true],
    'GET /api/users' => [['usuarios'], true], 'POST /api/users' => [['usuarios'], true],
    'PUT /api/users/5' => [['usuarios'], true], 'DELETE /api/users/5' => [['usuarios'], true],
    'GET /api/auth/me' => [null, false], 'POST /api/auth/signout' => [null, false],
];
$s3Desacuerdos = [];
foreach ($s3Tabla as $s3P => [$s3Quienes]) {
    foreach ([...$s3Preset, 'ninguno'] as $s3M) {
        // Un solo modulo habilitado; 'ninguno' es un valor que no esta en el preset (modulosEfectivos queda vacio).
        $s3R = $s3Ruta($s3P, 'admin', $s3Fila(['modulos_habilitados' => $s3M === 'ninguno' ? 'foo' : $s3M]));
        $s3Debe = $s3Quienes === null || in_array($s3M, $s3Quienes, true);
        if ($s3R['permitido'] !== $s3Debe) {
            $s3Desacuerdos[] = "{$s3P} con solo {$s3M}: " . ($s3R['permitido'] ? 'pasa' : 'se niega');
        }
    }
}
wa_chk('cada fila con un solo modulo habilitado pasa exactamente con los modulos de la tabla del plan (sin ninguno, solo la sesion)',
    $s3Desacuerdos === [], $s3Desacuerdos);
$s3Desacuerdos = [];
foreach ($s3Tabla as $s3P => [, $s3EsAdmin]) {
    $s3R = $s3Ruta($s3P, 'user');
    if ($s3R['permitido'] === $s3EsAdmin || ($s3EsAdmin && $s3R['codigo'] !== null)) {
        $s3Desacuerdos[] = "{$s3P}: " . json_encode($s3R);
    }
}
wa_chk('el rol user pasa en toda fila que no es de administrador (tabla del plan) y recibe 403 sin codigo en las demas',
    $s3Desacuerdos === [], $s3Desacuerdos);
// El modulo se mira antes que el rol: en una ruta de administrador de un modulo que la empresa no tiene, el Facturador
// recibe la negativa de la edicion (la ruta no existe para esa empresa), no la del rol.
$s3R = $s3Ruta('GET /api/users', 'user', $s3Fila(['modulos_habilitados' => 'facturacion']));
wa_chk('el modulo se mira antes que el rol: un Facturador en GET /api/users de una empresa sin el modulo usuarios recibe EDICION_NO_INCLUYE',
    $s3R['permitido'] === false && $s3R['http'] === 403 && $s3R['codigo'] === 'EDICION_NO_INCLUYE', $s3R);

// --- la lista contra los permisos (anexo IP2): lo que el Facturador abre por la lista, el RBAC se lo tiene que dar --------
// El gate resuelve el modulo de cada ruta con config/permissions.php. Toda fila que no es de administrador tiene que
// caer en un permiso que el rol user de Express (config 'rol_user') tiene; si no, el Facturador entraria por la lista y
// el RBAC lo echaria con 403. 'public' y 'authenticated' no piden modulo. La unica excepcion conocida: el Facturador
// preset no tiene 'ncf', asi que con enforcing() forzado recibe el 403 de RF1 en GET /api/ncf/rangos. Plan 05 o el dueno
// decide si el Facturador necesita esa lectura (entonces rol_user gana 'ncf') o si la fila pasa a ser de administrador;
// en cualquiera de los dos casos hay que sacarla de esta lista, y esta prueba falla hasta que se haga.
require_once __DIR__ . '/../src/PermissionGate.php';
$s3Rutas = TenantEntitlements::config()['rutas'];
$s3Permisos = (static fn (): array => require __DIR__ . '/../config/permissions.php')();
$s3Resolver = new ReflectionMethod('PermissionGate', 'resolveRequirement');
$s3Conocidas = ['GET ncf/rangos'];
$s3Inconsistentes = [];
foreach ($s3Rutas as $s3F) {
    // '#^rangos$#D' -> 'rangos': el identificador de la fila en la lista de excepciones es "METODO ruta/path".
    $s3Literal = (string) preg_replace('/^#\^(.*)\$#D$/', '$1', $s3F['path']);
    $s3Id = $s3F['method'] . ' ' . $s3F['route'] . ($s3Literal !== '' ? '/' . $s3Literal : '');
    $s3Requerido = $s3Resolver->invoke(null, $s3Permisos['routes'], $s3F['route'], $s3F['method'], '');
    if ($s3Requerido === null) {
        $s3Inconsistentes[] = "{$s3Id} (la ruta no esta en config/permissions.php)";
    } elseif (!in_array($s3Requerido, ['public', 'authenticated'], true) && !$s3F['admin']
        && !PermissionGate::permMatches(TenantEntitlements::config()['rol_user'], $s3Requerido)) {
        $s3Inconsistentes[] = $s3Id;
    }
}
wa_chk('lista contra permisos: toda fila que no es de administrador cae en un permiso de rol_user, salvo la excepcion conocida GET ncf/rangos (y esa sigue siendo una)',
    $s3Rutas !== [] && $s3Inconsistentes === $s3Conocidas, $s3Inconsistentes);

// --- una empresa que no es Express pasa tal cual: la lista no la toca ------------------------------------------------------
foreach ([
    "edicion 'completa'" => ['edicion' => 'completa'],
    "edicion 'Completa'" => ['edicion' => 'Completa'],
    "edicion ' completa '" => ['edicion' => ' completa '],
    "edicion ''" => ['edicion' => ''],
    "edicion 'basico'" => ['edicion' => 'basico'],
    "tipo 'integracion' con edicion 'express'" => ['tipo' => 'integracion'],
] as $s3Desc => $s3Cambios) {
    // Dentro de wa_con_log: las lineas de log de los valores de edicion desconocidos no deben llegar a stderr.
    [$s3Fallan, $s3Error] = wa_con_log(function () use ($s3Todas, $s3Ruta, $s3Fila, $s3Cambios): array {
        return array_values(array_filter(
            $s3Todas,
            static fn (string $p): bool => ($s3R = $s3Ruta($p, 'admin', $s3Fila($s3Cambios)))['permitido'] !== true || $s3R['http'] !== 200
        ));
    });
    wa_chk("{$s3Desc}: cada URI de las listas (permitidas, negadas, invalidas, casi-coincidencias y del Router) pasa con 200",
        $s3Error === null && $s3Fallan === [], $s3Error?->getMessage() ?? $s3Fallan);
}

// --- la lista misma: forma y consistencia ---------------------------------------------------------------------------------
$s3Claves = ['admin', 'method', 'modulos', 'path', 'route'];
$s3MalFormadas = [];
$s3MalAncladas = [];
$s3ModuloAjeno = [];
$s3Vistas = [];
$s3Repetidas = [];
foreach ($s3Rutas as $s3I => $s3F) {
    $s3Llaves = array_keys($s3F);
    sort($s3Llaves);
    if ($s3Llaves !== $s3Claves || !is_string($s3F['route']) || $s3F['route'] === '' || !is_string($s3F['path'])
        || !in_array($s3F['method'], ['GET', 'POST', 'PUT', 'DELETE'], true) || !is_array($s3F['modulos']) || !is_bool($s3F['admin'])) {
        $s3MalFormadas[] = $s3I;
        continue;
    }
    // Compila (preg_match devuelve false si no) y esta anclada al principio y al final: '$' solo cuenta con la D, o '\z'.
    if (@preg_match($s3F['path'], '') === false || !str_starts_with($s3F['path'], '#^')
        || !(str_ends_with($s3F['path'], '$#D') || str_ends_with($s3F['path'], '\z#'))) {
        $s3MalAncladas[] = $s3F['route'] . ' ' . $s3F['method'] . ' ' . $s3F['path'];
    }
    if (array_diff($s3F['modulos'], $s3Preset) !== []) {
        $s3ModuloAjeno[] = $s3F['route'] . ' ' . $s3F['method'];
    }
    $s3Llave = $s3F['route'] . ' ' . $s3F['method'] . ' ' . $s3F['path'];
    if (isset($s3Vistas[$s3Llave])) {
        $s3Repetidas[] = $s3Llave;
    }
    $s3Vistas[$s3Llave] = true;
}
wa_chk('rutas: hay filas y cada una trae las cinco claves (route, method, path, modulos, admin) con su tipo',
    $s3Rutas !== [] && $s3MalFormadas === [], $s3MalFormadas);
wa_chk('rutas: cada path compila, empieza con #^ y termina en $#D o \z# (nada de $ sin D)', $s3Rutas !== [] && $s3MalAncladas === [], $s3MalAncladas);
wa_chk('rutas: ninguna fila repite (route, method, path)', $s3Repetidas === [], $s3Repetidas);
wa_chk('rutas: los modulos de cada fila existen en el preset de config(); un typo ahi negaria la ruta a todos', $s3ModuloAjeno === [], $s3ModuloAjeno);

// --- procesos hijos: responderError y guardaRuta terminan la peticion con exit, asi que no se pueden probar aqui ------------
$s3Clase = dirname(__DIR__) . '/src/TenantEntitlements.php';
$s3Temporales = [];
register_shutdown_function(static function () use (&$s3Temporales): void {
    foreach ($s3Temporales as $s3Archivo) {
        @unlink($s3Archivo);
    }
});
/** Archivo temporal con $contenido; se borra al terminar el proceso, aunque la suite caiga a medias. */
$s3Temp = static function (string $prefijo, string $contenido = '') use (&$s3Temporales): string {
    $archivo = (string) tempnam(sys_get_temp_dir(), $prefijo);
    $s3Temporales[] = $archivo;
    file_put_contents($archivo, $contenido);
    return $archivo;
};

// responderError: argv[1] = archivo donde deja el codigo HTTP que quedo puesto, argv[2] = codigo ('-' es vacio),
// argv[3] = 'mal-utf8' para que $extra lleve un byte que no es UTF-8.
$s3HijoResponder = $s3Temp('ent_resp_', str_replace('__CLASE__', var_export($s3Clase, true), <<<'PHP'
<?php
error_reporting(E_ALL);
require_once __CLASE__;
$lado = $argv[1];
register_shutdown_function(static function () use ($lado): void {
    file_put_contents($lado, (string) http_response_code());
});
TenantEntitlements::responderError(403, $argv[2] === '-' ? '' : $argv[2], 'm', $argv[3] === 'mal-utf8' ? ['x' => "a\xFFb"] : ['x' => 1]);
echo 'NO';
PHP));
/** @return array{salida:string, http:string} lo que imprimio responderError y el codigo HTTP que dejo puesto */
$s3Responder = static function (string $codigo, string $extra = 'normal') use ($s3Temp, $s3HijoResponder): array {
    $lado = $s3Temp('ent_lado_');
    $r = wa_correr([PHP_BINARY, $s3HijoResponder, $lado, $codigo === '' ? '-' : $codigo, $extra]);
    return ['salida' => $r['salida'], 'http' => (string) file_get_contents($lado)];
};
$s3G = $s3Responder('EDICION_NO_INCLUYE');
wa_chk('responderError: pone el codigo HTTP, imprime {status, error, codigo, ...extra} y corta (nunca llega al echo siguiente)',
    $s3G === ['salida' => '{"status":false,"error":"m","codigo":"EDICION_NO_INCLUYE","x":1}', 'http' => '403'], $s3G);
$s3G = $s3Responder('');
wa_chk("responderError: con codigo '' no imprime la clave codigo",
    $s3G === ['salida' => '{"status":false,"error":"m","x":1}', 'http' => '403'], $s3G);
$s3G = $s3Responder('EDICION_NO_INCLUYE', 'mal-utf8');
wa_chk('responderError: un byte que no es UTF-8 en $extra se sustituye y el cuerpo sale (nunca vacio)',
    $s3G === ['salida' => json_encode(['status' => false, 'error' => 'm', 'codigo' => 'EDICION_NO_INCLUYE', 'x' => "a\xEF\xBF\xBDb"]), 'http' => '403'], $s3G);

// guardaRuta: argv[1] = escenario en base64 (JSON: tenant, llamadas [{metodo, uri, rol, tenant?}], config?),
// argv[2] = archivo de error_log, argv[3] = archivo donde deja {http, pasos, log, auditoria} al salir.
// La bitacora va encendida, pero escribe en una conexion de mentira que anota los parametros de cada INSERT: ninguna de
// estas pruebas toca una base de datos (la instancia de Database se fija antes de que nada la pida).
$s3HijoGuarda = $s3Temp('ent_guarda_', str_replace('__CLASE__', var_export($s3Clase, true), <<<'PHP'
<?php
error_reporting(E_ALL);
putenv('AUDIT_LOG_ENABLED=true');
putenv('MULTI_TENANT_ENABLED=false');
$escenario = json_decode((string) base64_decode($argv[1]), true);
[$logArchivo, $ladoArchivo] = [$argv[2], $argv[3]];
ini_set('error_log', $logArchivo);
require_once __CLASE__;
$auditoria = [];
final class FalsaSentencia
{
    public function execute(array $params): bool
    {
        $GLOBALS['auditoria'][] = $params;
        return true;
    }
}
final class FalsaConexion
{
    public function prepare(string $sql): FalsaSentencia
    {
        return new FalsaSentencia();
    }
}
$bd = (new ReflectionClass('Database'))->newInstanceWithoutConstructor();
(new ReflectionProperty('Database', 'conexion'))->setValue($bd, new FalsaConexion());
(new ReflectionProperty('Database', 'instance'))->setValue(null, $bd);
$actual = new ReflectionProperty('TenantResolver', 'current');
$actual->setValue(null, $escenario['tenant']);
if (isset($escenario['config'])) {
    (new ReflectionProperty('TenantEntitlements', 'config'))->setValue(null, $escenario['config']);
}
$pasos = 0;
register_shutdown_function(static function () use (&$pasos, $logArchivo, $ladoArchivo): void {
    file_put_contents($ladoArchivo, json_encode([
        'http' => http_response_code(),
        'pasos' => $pasos,
        'log' => (string) @file_get_contents($logArchivo),
        'auditoria' => $GLOBALS['auditoria'],
    ]));
});
foreach ($escenario['llamadas'] as $llamada) {
    if (array_key_exists('tenant', $llamada)) {
        $actual->setValue(null, $llamada['tenant']);
    }
    $_SERVER['REQUEST_METHOD'] = $llamada['metodo'];
    $_SERVER['REQUEST_URI'] = $llamada['uri'];
    TenantEntitlements::guardaRuta($llamada['rol']);
    $pasos++;
}
PHP));
/**
 * Corre las llamadas de $llamadas contra guardaRuta, una tras otra, en un proceso hijo con $tenant como empresa resuelta.
 * Devuelve lo que el hijo imprimio, el codigo HTTP que dejo puesto (false si nadie lo puso), cuantas llamadas volvieron
 * sin cortar, lo que se escribio al log y los parametros de cada fila de auditoria que se intento insertar.
 *
 * @return array{salida:string, http:int|false|null, pasos:int, log:string, auditoria:list<array<string,mixed>>}
 */
$s3Guarda = static function (array $tenant, array $llamadas, ?array $config = null) use ($s3Temp, $s3HijoGuarda): array {
    $log = $s3Temp('ent_log_');
    $lado = $s3Temp('ent_lado_');
    $escenario = ['tenant' => $tenant, 'llamadas' => $llamadas] + ($config !== null ? ['config' => $config] : []);
    $r = wa_correr([PHP_BINARY, $s3HijoGuarda, base64_encode((string) json_encode($escenario)), $log, $lado]);
    $dejo = json_decode((string) file_get_contents($lado), true);
    return [
        'salida' => $r['salida'],
        'http' => is_array($dejo) ? $dejo['http'] : null,
        'pasos' => is_array($dejo) ? (int) $dejo['pasos'] : -1,
        'log' => is_array($dejo) ? (string) $dejo['log'] : '',
        'auditoria' => is_array($dejo) ? $dejo['auditoria'] : [],
    ];
};
/**
 * Lo esencial de las filas de auditoria de un hijo: cuantas, y de la primera el modulo, la accion, el exito, el motivo
 * (error_message), el metodo y el endpoint que la bitacora tomo de la peticion, y el detalle (new_values) decodificado.
 */
$s3Auditado = static function (array $guarda): array {
    $fila = $guarda['auditoria'][0] ?? [];
    return [
        count($guarda['auditoria']), $fila[':module'] ?? null, $fila[':action'] ?? null, $fila[':success'] ?? null,
        $fila[':error_message'] ?? null, $fila[':http_method'] ?? null, $fila[':endpoint'] ?? null,
        json_decode((string) ($fila[':new_values'] ?? ''), true),
    ];
};
/** Una llamada del escenario: metodo, uri y rol; $tenant (opcional) cambia la empresa resuelta antes de llamar. */
$s3Llamada = static fn (string $metodo, string $uri, ?string $rol, ?array $tenant = null): array
    => ['metodo' => $metodo, 'uri' => $uri, 'rol' => $rol] + ($tenant !== null ? ['tenant' => $tenant] : []);
/** El cuerpo JSON de una negativa del guarda, decodificado. */
$s3Cuerpo = static fn (array $guarda): mixed => json_decode($guarda['salida'], true);

$s3G = $s3Guarda($s3Fila(), [$s3Llamada('GET', '/api/facturas?lean=1', 'admin')]);
wa_chk('guardaRuta: una ruta de la lista vuelve sin imprimir nada, sin tocar el codigo HTTP, sin escribir al log y sin auditar',
    $s3G === ['salida' => '', 'http' => false, 'pasos' => 1, 'log' => '', 'auditoria' => []], $s3G);
$s3G = $s3Guarda($s3Fila(), [$s3Llamada('GET', '/api/gastos', 'admin')]);
wa_chk('guardaRuta: una ruta fuera de la lista contesta 403 con el codigo y el texto de EDICION_NO_INCLUYE y no vuelve',
    $s3Cuerpo($s3G) === ['status' => false, 'error' => TenantEntitlements::MSG_EDICION, 'codigo' => 'EDICION_NO_INCLUYE']
    && $s3G['http'] === 403 && $s3G['pasos'] === 0 && $s3G['log'] === '', $s3G);
wa_chk('guardaRuta: esa negativa deja UNA fila de auditoria (modulo edicion, ACCESS_DENIED, sin exito) con el motivo y el detalle',
    $s3Auditado($s3G) === [1, 'edicion', 'ACCESS_DENIED', 0, 'ruta fuera de la edicion', 'GET', '/api/gastos',
        ['metodo' => 'GET', 'http' => 403, 'codigo' => 'EDICION_NO_INCLUYE', 'modulo' => null]], $s3Auditado($s3G));
wa_chk('el texto de EDICION_NO_INCLUYE no termina en punto (un solo texto: la constante de la clase)',
    TenantEntitlements::MSG_EDICION !== '' && !str_ends_with(TenantEntitlements::MSG_EDICION, '.'), TenantEntitlements::MSG_EDICION);
wa_chk('los textos de la solicitud invalida y de la ruta de administrador son los del contrato (este ultimo, el mismo del gate de permisos)',
    TenantEntitlements::MSG_SOLICITUD === 'La solicitud no es válida.'
    && TenantEntitlements::MSG_SOLO_ADMIN === 'No tienes permiso para hacer esto. Pídeselo a un administrador.',
    [TenantEntitlements::MSG_SOLICITUD, TenantEntitlements::MSG_SOLO_ADMIN]);
$s3G = $s3Guarda($s3Fila(['modulos_habilitados' => 'usuarios']), [$s3Llamada('GET', '/api/clients?query=x', 'admin')]);
wa_chk('guardaRuta: un modulo que la empresa no tiene contesta la misma negativa EDICION_NO_INCLUYE, y la auditoria dice cual',
    $s3Cuerpo($s3G) === ['status' => false, 'error' => TenantEntitlements::MSG_EDICION, 'codigo' => 'EDICION_NO_INCLUYE']
    && $s3G['http'] === 403 && $s3G['pasos'] === 0
    && $s3Auditado($s3G) === [1, 'edicion', 'ACCESS_DENIED', 0, 'modulo no habilitado', 'GET', '/api/clients',
        ['metodo' => 'GET', 'http' => 403, 'codigo' => 'EDICION_NO_INCLUYE', 'modulo' => 'clientes']], [$s3G, $s3Auditado($s3G)]);
$s3G = $s3Guarda($s3Fila(), [$s3Llamada('POST', '/api/ncf/rangos', 'user')]);
wa_chk('guardaRuta: una fila de administrador con un rol que no lo es contesta 403 con el texto de administrador y SIN la clave codigo',
    $s3Cuerpo($s3G) === ['status' => false, 'error' => TenantEntitlements::MSG_SOLO_ADMIN]
    && $s3G['http'] === 403 && $s3G['pasos'] === 0
    && $s3Auditado($s3G) === [1, 'edicion', 'ACCESS_DENIED', 0, 'ruta de administrador', 'POST', '/api/ncf/rangos',
        ['metodo' => 'POST', 'http' => 403, 'codigo' => null, 'modulo' => 'configuracion']], [$s3G, $s3Auditado($s3G)]);
$s3G = $s3Guarda($s3Fila(), [$s3Llamada('GET', '/api/facturas?x=/api/ncf/sequence', 'admin')]);
wa_chk('guardaRuta: una solicitud invalida contesta 400 con SOLICITUD_INVALIDA y el texto de solicitud, y tambien se audita',
    $s3Cuerpo($s3G) === ['status' => false, 'error' => TenantEntitlements::MSG_SOLICITUD, 'codigo' => 'SOLICITUD_INVALIDA']
    && $s3G['http'] === 400 && $s3G['pasos'] === 0
    && $s3Auditado($s3G) === [1, 'edicion', 'ACCESS_DENIED', 0, 'solicitud no valida', 'GET', '/api/facturas',
        ['metodo' => 'GET', 'http' => 400, 'codigo' => 'SOLICITUD_INVALIDA', 'modulo' => null]], [$s3G, $s3Auditado($s3G)]);
// Un fallo al evaluar (aqui, una fila de la lista con un path que no es texto: TypeError) es una negativa, nunca un 500.
$s3Config = ['modulos' => $s3Preset, 'rutas' => [['route' => 'facturas', 'method' => 'GET', 'path' => [], 'modulos' => [], 'admin' => false]]];
$s3G = $s3Guarda($s3Fila(), [$s3Llamada('GET', '/api/facturas', 'admin')], $s3Config);
wa_chk('guardaRuta: si evaluarRuta lanza algo, niega con 403 EDICION_NO_INCLUYE (nunca un 500), deja UNA linea en el log y lo audita',
    $s3Cuerpo($s3G) === ['status' => false, 'error' => TenantEntitlements::MSG_EDICION, 'codigo' => 'EDICION_NO_INCLUYE']
    && $s3G['http'] === 403 && $s3G['pasos'] === 0
    && substr_count($s3G['log'], "\n") === 1 && str_contains($s3G['log'], 'guardaRuta')
    && $s3Auditado($s3G) === [1, 'edicion', 'ACCESS_DENIED', 0, 'fallo al evaluar la ruta', 'GET', '/api/facturas',
        ['metodo' => 'GET', 'http' => 403, 'codigo' => 'EDICION_NO_INCLUYE', 'modulo' => null]], [$s3G, $s3Auditado($s3G)]);
$s3G = $s3Guarda($s3Fila(['edicion' => 'completa']), [$s3Llamada('GET', '/api/gastos', 'user')]);
wa_chk('guardaRuta: una empresa completa pasa en cualquier ruta (identidad), sin auditar',
    $s3G === ['salida' => '', 'http' => false, 'pasos' => 1, 'log' => '', 'auditoria' => []], $s3G);

// Memoria por peticion (RF5): la decision de permitir se recuerda por metodo, URI, empresa y rol. La misma llamada repetida
// sale por la memoria aunque la fila de la empresa ya no la dejaria pasar (la peticion siguiente lee la fila nueva en un
// proceso nuevo); cualquier dato distinto se evalua de nuevo, y solo se recuerda lo permitido.
$s3SoloUsuarios = $s3Fila(['modulos_habilitados' => 'usuarios']);
$s3G = $s3Guarda($s3Fila(), [
    $s3Llamada('GET', '/api/facturas', 'admin'),
    $s3Llamada('GET', '/api/facturas', 'admin', $s3SoloUsuarios),   // misma peticion, fila angostada: sale por la memoria
    $s3Llamada('GET', '/api/clients', 'admin'),                     // otra URI, ya sin clientes: se evalua y se niega
]);
wa_chk('guardaRuta: la misma peticion repetida sale por la memoria aunque la fila se angoste; otra URI se evalua de nuevo',
    $s3G['pasos'] === 2 && $s3G['http'] === 403 && ($s3Cuerpo($s3G)['codigo'] ?? null) === 'EDICION_NO_INCLUYE', $s3G);
$s3G = $s3Guarda($s3Fila(), [
    $s3Llamada('POST', '/api/ncf/rangos', 'admin'),
    $s3Llamada('POST', '/api/ncf/rangos', 'user'),                  // mismo metodo, URI y empresa; otro rol
]);
wa_chk('guardaRuta: el rol es parte de la clave de la memoria (lo que paso el administrador no pasa al Facturador)',
    $s3G['pasos'] === 1 && $s3G['http'] === 403 && $s3Cuerpo($s3G) === ['status' => false, 'error' => TenantEntitlements::MSG_SOLO_ADMIN], $s3G);
$s3G = $s3Guarda($s3Fila(), [
    $s3Llamada('GET', '/api/facturas', 'admin'),
    $s3Llamada('GET', '/api/facturas', 'admin', $s3Fila(['id' => 13, 'modulos_habilitados' => 'usuarios'])),   // misma peticion y rol; otra empresa
]);
wa_chk('guardaRuta: la empresa es parte de la clave de la memoria (lo que paso una empresa no pasa a otra)',
    $s3G['pasos'] === 1 && $s3G['http'] === 403 && ($s3Cuerpo($s3G)['codigo'] ?? null) === 'EDICION_NO_INCLUYE', $s3G);
$s3G = $s3Guarda($s3Fila(), [
    $s3Llamada('GET', '/api/ncf/rangos', 'user'),
    $s3Llamada('POST', '/api/ncf/rangos', 'user'),                  // misma URI, empresa y rol; otro metodo
]);
wa_chk('guardaRuta: el metodo es parte de la clave de la memoria (el GET permitido no permite el POST)',
    $s3G['pasos'] === 1 && $s3G['http'] === 403 && $s3Cuerpo($s3G) === ['status' => false, 'error' => TenantEntitlements::MSG_SOLO_ADMIN], $s3G);

// ---------------------------------------------------------------------------
// 4) AuthMiddleware::validateRequest: columnas del token, vencimiento por inactividad y guarda de rutas
// ---------------------------------------------------------------------------
echo "\n== 4) validateRequest: vencimiento por inactividad y guarda de rutas ==\n";

// --- tokenCaducado (RF3: ni un token recien creado, ni un reloj adelantado, ni una fecha cero dejan a nadie fuera) -----------
// La tabla cuenta dias exactos con strtotime: mientras corre se fija UTC, porque en una zona con cambio de hora entre el
// 27-09 y el 27-10 "exactamente 30 dias" mediria una hora de mas. La zona vuelve a la de antes al terminar.
$s4Zona = date_default_timezone_get();
date_default_timezone_set('UTC');
try {
    $s4Ahora = strtotime('2026-10-27 12:00:00');
    foreach ([
        ['2026-09-27 12:00:00', null, false],   // exactamente 30 dias: aun vale
        ['2026-09-27 11:59:59', null, true],    // un segundo mas: vence
        [null, '2026-10-20 00:00:00', false],   // nunca se uso: cuenta desde que se creo
        [null, '2026-09-01 00:00:00', true],
        [null, null, false],                    // sin ningun dato no vence
        ['', '', false],
        ['2026-10-28 12:00:00', null, false],   // ultimo uso en el futuro (reloj corrido)
        ['0000-00-00 00:00:00', '2026-10-26 00:00:00', false],   // la fecha cero cuenta como ausente
    ] as [$s4Uso, $s4Crea, $s4Esperado]) {
        $s4Obtenido = TenantEntitlements::tokenCaducado($s4Uso, $s4Crea, $s4Ahora);
        wa_chk('tokenCaducado(' . json_encode([$s4Uso, $s4Crea]) . ')', $s4Obtenido === $s4Esperado, $s4Obtenido);
    }
    // Mas alla de la tabla: el plazo explicito, COALESCE (el ultimo uso gana sobre un created_at viejo) y los datos ausentes.
    wa_chk('tokenCaducado: el plazo se puede pasar (con 1 dia, 24 h exactas valen y un segundo mas vence)',
        TenantEntitlements::tokenCaducado('2026-10-26 12:00:00', null, $s4Ahora, 1) === false
        && TenantEntitlements::tokenCaducado('2026-10-26 11:59:59', null, $s4Ahora, 1) === true);
    // Sin plazo explicito el plazo sale de config(): se prueba con otro valor en la config, no con el 30 que hoy trae.
    $s4Config = TenantEntitlements::config();
    $s4Cache = new ReflectionProperty('TenantEntitlements', 'config');
    $s4Cache->setValue(null, ['TOKEN_INACTIVIDAD_DIAS' => 1] + $s4Config);
    try {
        $s4PorConfig = [
            TenantEntitlements::tokenCaducado('2026-10-26 12:00:00', null, $s4Ahora),
            TenantEntitlements::tokenCaducado('2026-10-26 11:59:59', null, $s4Ahora),
        ];
    } finally {
        $s4Cache->setValue(null, $s4Config);
    }
    wa_chk('tokenCaducado: sin plazo explicito lo toma de config()[TOKEN_INACTIVIDAD_DIAS] (con 1 dia: false y true)',
        $s4PorConfig === [false, true] && TenantEntitlements::config() === $s4Config, $s4PorConfig);
    // Un config que compila pero trae mal ese dato (revision final): contar con 0 dias (un (int) de null) vencia y revocaba TODAS las sesiones de las empresas Express. Ahora lanza, y el
    // gancho de AuthMiddleware niega (seccion 4 mas abajo). Un numero en texto ("30") si vale, y un plazo explicito no mira la config.
    $s4SinDias = array_diff_key($s4Config, ['TOKEN_INACTIVIDAD_DIAS' => 1]);
    $s4Rechazados = [];
    foreach (['la clave ausente' => $s4SinDias, 'NULL' => ['TOKEN_INACTIVIDAD_DIAS' => null] + $s4SinDias, '0' => ['TOKEN_INACTIVIDAD_DIAS' => 0] + $s4SinDias,
        'un negativo' => ['TOKEN_INACTIVIDAD_DIAS' => -5] + $s4SinDias, 'un texto que no es un numero' => ['TOKEN_INACTIVIDAD_DIAS' => 'treinta'] + $s4SinDias] as $s4Desc => $s4Mala) {
        $s4Cache->setValue(null, $s4Mala);
        try {
            $s4Resultado = TenantEntitlements::tokenCaducado('2026-10-26 11:59:59', null, $s4Ahora);
            $s4Rechazados[$s4Desc] = 'no lanzo y devolvio ' . var_export($s4Resultado, true);
        } catch (RuntimeException $s4E) {
            if (!str_contains($s4E->getMessage(), 'TOKEN_INACTIVIDAD_DIAS') || !str_contains($s4E->getMessage(), 'config/editions.php')) {
                $s4Rechazados[$s4Desc] = 'lanzo, pero su mensaje no nombra la clave ni el archivo: ' . $s4E->getMessage();
            }
        } finally {
            $s4Cache->setValue(null, $s4Config);
        }
    }
    wa_chk('tokenCaducado: con TOKEN_INACTIVIDAD_DIAS ausente, NULL, 0, negativo o un texto que no es un numero lanza RuntimeException (nombra la clave y config/editions.php) en lugar de contar con 0 dias',
        $s4Rechazados === [], $s4Rechazados);
    $s4Cache->setValue(null, ['TOKEN_INACTIVIDAD_DIAS' => '1'] + $s4SinDias);
    try {
        $s4EnTexto = [TenantEntitlements::tokenCaducado('2026-10-26 12:00:00', null, $s4Ahora), TenantEntitlements::tokenCaducado('2026-10-26 11:59:59', null, $s4Ahora)];
    } finally {
        $s4Cache->setValue(null, $s4Config);
    }
    $s4Cache->setValue(null, $s4SinDias);
    try {
        $s4Explicito = TenantEntitlements::tokenCaducado('2026-10-26 11:59:59', null, $s4Ahora, 1);   // con el plazo en la mano, la config ni se mira
        $s4SinMirar = TenantEntitlements::tokenCaducado(null, null, $s4Ahora);                          // y sin ningun dato de uso no vence, tampoco se mira
    } finally {
        $s4Cache->setValue(null, $s4Config);
    }
    wa_chk('tokenCaducado: TOKEN_INACTIVIDAD_DIAS "1" (un numero en texto) vale (false y true); con el plazo explicito, o sin ningun dato de uso, la config no se mira y no lanza; la config queda como estaba',
        $s4EnTexto === [false, true] && $s4Explicito === true && $s4SinMirar === false && TenantEntitlements::config() === $s4Config, [$s4EnTexto, $s4Explicito, $s4SinMirar]);
    wa_chk('tokenCaducado: un ultimo uso un ano en el futuro (reloj muy corrido) tampoco vence: la edad negativa no se mide en valor absoluto',
        TenantEntitlements::tokenCaducado('2027-10-27 12:00:00', null, $s4Ahora) === false
        && TenantEntitlements::tokenCaducado(null, '2027-10-27 12:00:00', $s4Ahora) === false);
    wa_chk('tokenCaducado: el ultimo uso gana sobre un created_at mas viejo, y created_at solo cuenta si no hay ultimo uso',
        TenantEntitlements::tokenCaducado('2026-10-26 00:00:00', '2026-01-01 00:00:00', $s4Ahora) === false
        && TenantEntitlements::tokenCaducado(null, '2026-01-01 00:00:00', $s4Ahora) === true);
    wa_chk('tokenCaducado: un ultimo uso vacio, ilegible o con la fecha cero pasa a created_at; sin otro dato no vence',
        TenantEntitlements::tokenCaducado('', '2026-01-01 00:00:00', $s4Ahora) === true
        && TenantEntitlements::tokenCaducado('0000-00-00 00:00:00', '2026-01-01 00:00:00', $s4Ahora) === true
        && TenantEntitlements::tokenCaducado('basura', '2026-01-01 00:00:00', $s4Ahora) === true
        && TenantEntitlements::tokenCaducado('basura', null, $s4Ahora) === false
        && TenantEntitlements::tokenCaducado('0000-00-00 00:00:00', null, $s4Ahora) === false
        && TenantEntitlements::tokenCaducado(null, '0000-00-00 00:00:00', $s4Ahora) === false);
} finally {
    date_default_timezone_set($s4Zona);
}

// --- cargarEntitlements y el texto de EDICION_NO_INCLUYE -----------------------------------------------------------------------
// La seccion 2 ya cargo la clase en este proceso: aqui solo se prueba que, con el archivo en su sitio, es true y no hace ruido.
ob_start();
[$s4Carga, $s4Error, $s4Log] = wa_con_log(static fn (): bool => TenantResolver::cargarEntitlements());
$s4Salida = (string) ob_get_clean();
wa_chk('cargarEntitlements(): con src/TenantEntitlements.php en su sitio devuelve true, sin imprimir ni escribir al log',
    $s4Carga === true && $s4Error === null && $s4Salida === '' && $s4Log === '', [$s4Carga, $s4Error?->getMessage(), $s4Salida, $s4Log]);
// Una peticion con la clase rota o ausente contesta el 403 desde AuthMiddleware con el codigo y el texto de TenantResolver
// (la clase siempre cargada): TenantEntitlements los repite como alias, no como una segunda copia.
wa_chk('EDICION_NO_INCLUYE: el codigo y el texto de TenantEntitlements son los de TenantResolver, y el texto no termina en punto',
    TenantResolver::CODIGO_EDICION_NO_INCLUYE === 'EDICION_NO_INCLUYE'
    && TenantEntitlements::CODIGO_EDICION_NO_INCLUYE === TenantResolver::CODIGO_EDICION_NO_INCLUYE
    && TenantEntitlements::MSG_EDICION === TenantResolver::MSG_EDICION
    && TenantResolver::MSG_EDICION !== '' && !str_ends_with(TenantResolver::MSG_EDICION, '.'),
    [TenantResolver::MSG_EDICION, TenantEntitlements::MSG_EDICION]);
// Un solo archivo de src/ lleva el texto, y una sola vez: una segunda copia (en AuthMiddleware, en una clase nueva) se desvia
// del mock del front sin que nada lo note. Se busca el prefijo, sin la o acentuada.
$s4Raiz = dirname(__DIR__);
$s4Texto = 'Tu plan Express no incluye esta funci';
$s4Archivos = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($s4Raiz . '/src', FilesystemIterator::SKIP_DOTS)) as $s4Item) {
    if ($s4Item->isFile() && str_ends_with($s4Item->getFilename(), '.php')) {
        $s4Veces = substr_count((string) file_get_contents($s4Item->getPathname()), $s4Texto);
        if ($s4Veces > 0) {
            $s4Archivos[str_replace('\\', '/', substr($s4Item->getPathname(), strlen($s4Raiz) + 1))] = $s4Veces;
        }
    }
}
wa_chk('el texto de EDICION_NO_INCLUYE esta una sola vez en src/ y en TenantResolver.php (la constante compartida)',
    $s4Archivos === ['src/TenantResolver.php' => 1], $s4Archivos);

// --- validateToken: last_used y created_at en el SELECT y en el resultado, en los dos modos -------------------------------------
require_once __DIR__ . '/../src/Models/authModel.php';

/** Sentencia de mentira de authModel: anota el SQL y los parametros del execute y devuelve la fila que lleva la conexion. */
final class ExpSentenciaFalsa
{
    public function __construct(private ExpConexionFalsa $conexion, private string $sql)
    {
    }

    public function execute(?array $params = null): bool
    {
        $this->conexion->consultas[] = ['sql' => $this->sql, 'params' => $params ?? []];
        return true;
    }

    public function fetch(): array|false
    {
        return $this->conexion->fila;
    }
}

/** Conexion de mentira: la propiedad conexion de authModel no tiene tipo, asi que no hace falta un PDO. */
final class ExpConexionFalsa
{
    /** @var list<array{sql:string,params:array<string,mixed>}> */
    public array $consultas = [];

    public function __construct(public array|false $fila)
    {
    }

    public function prepare(string $sql): ExpSentenciaFalsa
    {
        return new ExpSentenciaFalsa($this, $sql);
    }
}

/** authModel sin constructor (el constructor abre la base) y con la conexion de mentira. */
$s4Modelo = static function (ExpConexionFalsa $conexion): authModel {
    $modelo = (new ReflectionClass('authModel'))->newInstanceWithoutConstructor();
    (new ReflectionProperty('authModel', 'conexion'))->setValue($modelo, $conexion);
    return $modelo;
};
$s4MtAntes = getenv('MULTI_TENANT_ENABLED');
try {
    foreach (['multi-tenant' => true, 'un solo tenant' => false] as $s4Modo => $s4Mt) {
        putenv('MULTI_TENANT_ENABLED=' . ($s4Mt ? 'true' : 'false'));
        $s4Fila = ['user_id' => '7', 'is_active' => '1', 'role' => 'admin', 'last_used' => '2026-10-20 10:00:00', 'created_at' => '2026-10-01 08:00:00']
            + ($s4Mt ? ['tenant_id' => '12'] : []);
        $s4Con = new ExpConexionFalsa($s4Fila);
        $s4R = $s4Modelo($s4Con)->validateToken('token-de-prueba');
        wa_chk("validateToken ({$s4Modo}): el SELECT pide t.last_used, t.created_at y sigue buscando por el hash del token",
            count($s4Con->consultas) === 1 && str_contains($s4Con->consultas[0]['sql'], 't.last_used, t.created_at')
            && $s4Con->consultas[0]['params'] === [':token_hash' => hash('sha256', 'token-de-prueba')], $s4Con->consultas);
        wa_chk("validateToken ({$s4Modo}): el resultado lleva last_used y created_at como texto, y lo de siempre en el mismo orden",
            $s4R === ['valid' => true, 'user_id' => 7, 'tenant_id' => $s4Mt ? 12 : null, 'role' => 'admin',
                'last_used' => '2026-10-20 10:00:00', 'created_at' => '2026-10-01 08:00:00'], $s4R);
        $s4R = $s4Modelo(new ExpConexionFalsa(['last_used' => null] + $s4Fila))->validateToken('token-de-prueba');
        wa_chk("validateToken ({$s4Modo}): un last_used NULL sale como null (la clave esta) y created_at sigue",
            array_key_exists('last_used', $s4R) && $s4R['last_used'] === null && $s4R['created_at'] === '2026-10-01 08:00:00', $s4R);
        $s4R = $s4Modelo(new ExpConexionFalsa(['is_active' => '0'] + $s4Fila))->validateToken('token-de-prueba');
        wa_chk("validateToken ({$s4Modo}): un token inactivo sigue siendo valid false, con la forma de siempre",
            $s4R === ['valid' => false, 'user_id' => null, 'tenant_id' => null, 'role' => null], $s4R);
    }
} finally {
    putenv($s4MtAntes === false ? 'MULTI_TENANT_ENABLED' : 'MULTI_TENANT_ENABLED=' . $s4MtAntes);
}

// --- validateRequest en un hijo (ent_hijo_validar, arriba): la guarda termina la peticion con exit -------------------------
$s4Token = 'tok-' . str_repeat('1a2b3c4d', 4);
/** Una fila de master.tenants de una empresa Express; $o pisa los campos que cada caso cambia. */
$s4Empresa = static fn (array $o = []): array => $o + [
    'id' => 12, 'nombre' => 'Constructora X', 'rnc' => '131000001', 'tipo' => 'app', 'ambiente' => 'ecf',
    'edicion' => 'express', 'tipos_ecf_habilitados' => '45', 'modulos_habilitados' => null,
];
/** Hace $dias dias, con el formato de api_tokens. */
$s4Hace = static fn (int $dias): string => date('Y-m-d H:i:s', time() - $dias * 86400);
/** Lo que devolveria validateToken para el administrador 7 de la empresa 12 con un token recien creado; $o pisa campos. */
$s4Valida = static fn (array $o = []): array => $o + [
    'valid' => true, 'user_id' => 7, 'tenant_id' => 12, 'role' => 'admin', 'last_used' => null, 'created_at' => date('Y-m-d H:i:s'),
];
/**
 * Corre validateRequest de una peticion (metodo y uri) con la empresa $tenant ya resuelta y $validacion sobre lo que devuelve
 * validateToken; $extra suma claves al escenario del hijo (peticiones, entre, raiz). Devuelve la linea JSON del hijo,
 * decodificada, o ['hijo_sin_linea_json' => lo que imprimio] si no hubo.
 */
$s4Validar = static function (?array $tenant, string $metodo, string $uri, array $validacion = [], array $extra = []) use ($s4Token, $s4Valida): array {
    $escenario = ['tenant' => $tenant, 'metodo' => $metodo, 'uri' => $uri, 'token' => $s4Token, 'validacion' => $s4Valida($validacion)] + $extra;
    $r = wa_correr([PHP_BINARY, __FILE__, '--hijo=validar', '--escenario=' . base64_encode((string) json_encode($escenario))]);
    $lineas = array_values(array_filter(array_map('trim', explode("\n", $r['salida'])), static fn (string $linea): bool => $linea !== ''));
    $dejo = json_decode((string) end($lineas), true);
    return is_array($dejo) ? $dejo : ['hijo_sin_linea_json' => $r];
};
// El resultado de siempre de una validacion buena: ni last_used ni created_at se filtran a lo que ven los llamadores.
$s4Valido = ['valid' => true, 'user_id' => 7, 'tenant_id' => 12, 'role' => 'admin', 'message' => 'Token validated'];
$s4SinCambios = ['actualizar' => 0, 'revocar' => 0];

$s4R = $s4Validar($s4Empresa(['edicion' => 'completa']), 'GET', '/api/gastos');
wa_chk('(a) empresa completa, GET /api/gastos: valida con el resultado de siempre, last_used una vez y TenantEntitlements no se carga',
    $s4R['retorno'] === $s4Valido && $s4R['llamadas'] === ['actualizar' => 1, 'revocar' => 0] && $s4R['clase_cargada'] === false
    && $s4R['salida'] === '' && $s4R['http'] === false && $s4R['log'] === '', $s4R);
$s4R = $s4Validar($s4Empresa(), 'GET', '/api/facturas?lean=1');
wa_chk('(b) empresa Express, GET /api/facturas: valida con el mismo resultado, last_used una vez, sin revocar ni cortar',
    $s4R['retorno'] === $s4Valido && $s4R['llamadas'] === ['actualizar' => 1, 'revocar' => 0] && $s4R['clase_cargada'] === true
    && $s4R['salida'] === '' && $s4R['http'] === false && $s4R['log'] === '', $s4R);
$s4R = $s4Validar($s4Empresa(), 'GET', '/api/gastos');
$s4Cuerpo = json_decode($s4R['salida'], true);
wa_chk('(c) empresa Express, GET /api/gastos: 403 EDICION_NO_INCLUYE con el texto de la constante, validateRequest no vuelve y last_used no se toca',
    $s4R['http'] === 403 && $s4Cuerpo === ['status' => false, 'error' => TenantEntitlements::MSG_EDICION, 'codigo' => 'EDICION_NO_INCLUYE']
    && $s4R['pasos'] === 0 && $s4R['retorno'] === null && $s4R['llamadas'] === $s4SinCambios, $s4R);
wa_chk('(c) la negativa llega con la identidad ya puesta en RequestContext (usuario, empresa y hash del token): la fila de auditoria nombra al usuario',
    $s4R['contexto'] === ['user_id' => 7, 'tenant_id' => 12, 'token_hash' => hash('sha256', $s4Token)], $s4R['contexto'] ?? null);
// El rol que devuelve validateToken llega a la guarda (RF1): una fila de administrador es del admin y no del Facturador, y
// esa negativa no lleva codigo (nunca EDICION_NO_INCLUYE, nunca un 401).
$s4R = $s4Validar($s4Empresa(), 'POST', '/api/ncf/rangos');
wa_chk('el rol admin de validateToken llega a la guarda: POST /api/ncf/rangos (fila de administrador) valida',
    $s4R['retorno'] === $s4Valido && $s4R['llamadas'] === ['actualizar' => 1, 'revocar' => 0] && $s4R['http'] === false, $s4R);
$s4R = $s4Validar($s4Empresa(), 'POST', '/api/ncf/rangos', ['role' => 'user']);
wa_chk('RF1: el rol user (Facturador) en POST /api/ncf/rangos recibe 403 con el texto de administrador y SIN codigo; last_used no se toca',
    $s4R['http'] === 403 && json_decode($s4R['salida'], true) === ['status' => false, 'error' => TenantEntitlements::MSG_SOLO_ADMIN]
    && $s4R['pasos'] === 0 && $s4R['llamadas'] === $s4SinCambios, $s4R);
foreach (['/api/facturas' => 'una ruta permitida', '/api/gastos' => 'una ruta negada'] as $s4Uri => $s4Cual) {
    $s4R = $s4Validar($s4Empresa(), 'GET', $s4Uri, ['last_used' => $s4Hace(31)]);
    wa_chk("(d) empresa Express, last_used de hace 31 dias en {$s4Cual}: valid false con el texto de inactividad, token revocado con su hash, last_used sin tocar y ningun 403",
        $s4R['retorno'] === ['valid' => false, 'user_id' => null, 'tenant_id' => null, 'message' => 'Tu sesión venció por inactividad']
        && $s4R['llamadas'] === ['actualizar' => 0, 'revocar' => 1] && $s4R['hashes']['revocar'] === [hash('sha256', $s4Token)]
        && $s4R['http'] === false && $s4R['salida'] === '', $s4R);
}
$s4R = $s4Validar($s4Empresa(), 'GET', '/api/facturas', ['last_used' => null, 'created_at' => date('Y-m-d H:i:s')]);
wa_chk('(e) empresa Express, last_used NULL y created_at de ahora: valida',
    $s4R['retorno'] === $s4Valido && $s4R['llamadas'] === ['actualizar' => 1, 'revocar' => 0] && $s4R['http'] === false, $s4R);
$s4R = $s4Validar($s4Empresa(), 'GET', '/api/facturas', ['last_used' => $s4Hace(29)]);
wa_chk('(e) empresa Express, last_used de hace 29 dias: valida (el plazo es de 30)',
    $s4R['retorno'] === $s4Valido && $s4R['llamadas'] === ['actualizar' => 1, 'revocar' => 0], $s4R);
$s4R = $s4Validar($s4Empresa(), 'GET', '/api/facturas', ['last_used' => null, 'created_at' => $s4Hace(31)]);
wa_chk('(e) empresa Express, last_used NULL y created_at de hace 31 dias: vence (cuenta desde que se creo)',
    ($s4R['retorno']['valid'] ?? null) === false && $s4R['llamadas'] === ['actualizar' => 0, 'revocar' => 1], $s4R);
$s4R = $s4Validar($s4Empresa(), 'GET', '/api/facturas', ['last_used' => null, 'created_at' => null]);
wa_chk('(e) empresa Express, sin ningun dato de uso (last_used y created_at NULL): valida, nunca vence',
    $s4R['retorno'] === $s4Valido && $s4R['llamadas'] === ['actualizar' => 1, 'revocar' => 0], $s4R);
$s4R = $s4Validar($s4Empresa(['edicion' => 'completa']), 'GET', '/api/gastos', ['last_used' => $s4Hace(400)]);
wa_chk('una empresa completa con un token de hace 400 dias sigue valida: el vencimiento es solo de las Express',
    $s4R['retorno'] === $s4Valido && $s4R['llamadas'] === ['actualizar' => 1, 'revocar' => 0] && $s4R['clase_cargada'] === false, $s4R);
$s4R = $s4Validar($s4Empresa(['tipo' => 'integracion']), 'GET', '/api/gastos');
wa_chk("(f) tipo 'integracion' con edicion 'express', GET /api/gastos: valida, la clase no se carga y no hay ruido en el log",
    $s4R['retorno'] === $s4Valido && $s4R['llamadas'] === ['actualizar' => 1, 'revocar' => 0] && $s4R['clase_cargada'] === false
    && $s4R['salida'] === '' && $s4R['http'] === false && $s4R['log'] === '', $s4R);
// (g) RF5: una decision por peticion. Dos validateRequest en el mismo proceso (el gate y el controller las hacen) con el
// modulo angostado entre una y otra: las dos vuelven. Un proceso nuevo (la peticion siguiente) lee la fila nueva.
$s4R = $s4Validar($s4Empresa(), 'GET', '/api/facturas', [], ['peticiones' => 2, 'entre' => $s4Empresa(['modulos_habilitados' => 'usuarios'])]);
wa_chk('(g) RF5: dos validateRequest de la misma peticion con el modulo facturacion quitado entre una y otra: las dos vuelven sin cortar',
    $s4R['pasos'] === 2 && $s4R['retorno'] === $s4Valido && $s4R['http'] === false && $s4R['salida'] === ''
    && $s4R['llamadas'] === ['actualizar' => 2, 'revocar' => 0], $s4R);
$s4R = $s4Validar($s4Empresa(['modulos_habilitados' => 'usuarios']), 'GET', '/api/facturas');
$s4Cuerpo = json_decode($s4R['salida'], true);
wa_chk('(g) RF5: un proceso nuevo con la fila angostada niega esa misma ruta con 403 EDICION_NO_INCLUYE (nada se recuerda de una peticion a otra)',
    $s4R['http'] === 403 && ($s4Cuerpo['codigo'] ?? null) === 'EDICION_NO_INCLUYE' && $s4R['llamadas'] === $s4SinCambios, $s4R);
// (h) una edicion que no es completa ni express se trata como completa y deja UNA linea de log, sin cargar la clase.
foreach (['basico', ''] as $s4Valor) {
    $s4R = $s4Validar($s4Empresa(['edicion' => $s4Valor]), 'GET', '/api/gastos');
    wa_chk("(h) edicion '{$s4Valor}', GET /api/gastos: se trata como completa (valida, last_used una vez, sin cargar la clase) y deja UNA linea de log con el tenant y el valor",
        $s4R['retorno'] === $s4Valido && $s4R['llamadas'] === ['actualizar' => 1, 'revocar' => 0] && $s4R['clase_cargada'] === false
        && $s4R['http'] === false && $s4R['salida'] === ''
        && substr_count($s4R['log'], 'valor desconocido') === 1 && substr_count($s4R['log'], "\n") === 1
        && str_contains($s4R['log'], "[Edicion] valor desconocido en el tenant #12: {$s4Valor}"), $s4R);
}
$s4SinEdicion = $s4Empresa();
unset($s4SinEdicion['edicion']);
$s4SinTipo = $s4Empresa();
unset($s4SinTipo['tipo']);
// Los valores conocidos no escriben nada, y se prueban en una ruta que Express niega: asi se ve que 'completa' (en cualquier
// forma) deja pasar y que 'express' (con mayusculas o espacios) es Express de verdad.
foreach (['completa', 'Completa', ' completa '] as $s4Valor) {
    $s4R = $s4Validar($s4Empresa(['edicion' => $s4Valor]), 'GET', '/api/gastos');
    wa_chk("(h) edicion '{$s4Valor}' es completa: valida en una ruta que Express niega, sin cargar la clase y sin linea de log",
        $s4R['retorno'] === $s4Valido && $s4R['clase_cargada'] === false && $s4R['log'] === '', $s4R);
}
foreach (['Express', ' EXPRESS '] as $s4Valor) {
    $s4R = $s4Validar($s4Empresa(['edicion' => $s4Valor]), 'GET', '/api/gastos');
    $s4Cuerpo = json_decode($s4R['salida'], true);
    wa_chk("(h) edicion '{$s4Valor}' es express (las mayusculas y los espacios no la cambian): GET /api/gastos da 403 EDICION_NO_INCLUYE, sin linea de log",
        $s4R['http'] === 403 && ($s4Cuerpo['codigo'] ?? null) === 'EDICION_NO_INCLUYE' && $s4R['log'] === '', $s4R);
}
$s4R = $s4Validar($s4SinTipo, 'GET', '/api/gastos');
$s4Cuerpo = json_decode($s4R['salida'], true);
wa_chk("una fila sin la clave tipo cuenta como app (igual que en TenantResolver): con edicion express, GET /api/gastos da 403 EDICION_NO_INCLUYE",
    $s4R['http'] === 403 && ($s4Cuerpo['codigo'] ?? null) === 'EDICION_NO_INCLUYE' && $s4R['clase_cargada'] === true, $s4R);
foreach (['una fila sin la clave edicion (master sin migrar)' => $s4SinEdicion, 'edicion NULL' => $s4Empresa(['edicion' => null]), 'ninguna empresa resuelta' => null] as $s4Desc => $s4Fila) {
    $s4R = $s4Validar($s4Fila, 'GET', '/api/gastos');
    wa_chk("(h) {$s4Desc}: valida como completa, sin cargar la clase y sin linea de log",
        $s4R['retorno'] === $s4Valido && $s4R['llamadas'] === ['actualizar' => 1, 'revocar' => 0] && $s4R['clase_cargada'] === false
        && $s4R['log'] === '', $s4R);
}
$s4R = $s4Validar($s4Empresa(['edicion' => str_repeat('x', 40)]), 'GET', '/api/gastos');
wa_chk('(h) el valor sale cortado a 32 caracteres en la linea de log',
    str_contains($s4R['log'], ': ' . str_repeat('x', 32)) && !str_contains($s4R['log'], str_repeat('x', 33)), $s4R['log']);
$s4R = $s4Validar($s4Empresa(['edicion' => "basico\nlinea falsa\x01"]), 'GET', '/api/gastos');
wa_chk('(h) un salto de linea o un caracter de control dentro del valor no parte la linea de log: sigue siendo UNA linea',
    substr_count($s4R['log'], "\n") === 1 && substr_count($s4R['log'], 'valor desconocido') === 1 && ($s4R['retorno']['valid'] ?? null) === true, $s4R['log']);
$s4R = $s4Validar($s4Empresa(['edicion' => 'basico']), 'GET', '/api/gastos', [], ['peticiones' => 2]);
wa_chk('(h) validateRequest corre dos veces por peticion (el gate y el controller): la linea de log sale una sola vez',
    $s4R['pasos'] === 2 && substr_count($s4R['log'], 'valor desconocido') === 1 && substr_count($s4R['log'], "\n") === 1, $s4R);

// --- una clase que no carga: la empresa Express recibe 403 (nunca 500) y las demas ni se enteran ---------------------------------
// Se prueba en una COPIA del arbol (src/ y config/, sin .env) con TenantEntitlements.php borrado o roto: el repo no se toca y
// el codigo de produccion no lleva ningun interruptor de prueba. La suite scratch repite esto por HTTP (plan 02, tarea 12).
$s4Copia = null;
register_shutdown_function(static function () use (&$s4Copia): void {
    if ($s4Copia === null || !is_dir($s4Copia)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($s4Copia, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $s4Item) {
        $s4Item->isDir() ? @rmdir($s4Item->getPathname()) : @unlink($s4Item->getPathname());
    }
    @rmdir($s4Copia);
});
$s4Copia = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ent_arbol_' . bin2hex(random_bytes(4));
foreach (['src', 'config'] as $s4Carpeta) {
    @mkdir($s4Copia . DIRECTORY_SEPARATOR . $s4Carpeta, 0777, true);
    $s4Recorrido = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator("{$s4Raiz}/{$s4Carpeta}", FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($s4Recorrido as $s4Item) {
        $s4Destino = $s4Copia . DIRECTORY_SEPARATOR . $s4Carpeta . DIRECTORY_SEPARATOR . $s4Recorrido->getSubPathname();
        $s4Item->isDir() ? @mkdir($s4Destino, 0777, true) : copy($s4Item->getPathname(), $s4Destino);
    }
}
$s4ClaseEnCopia = $s4Copia . '/src/TenantEntitlements.php';
wa_chk('la copia del arbol existe con TenantEntitlements.php y sin .env (de ahi se fabrican la clase ausente y la rota)',
    is_file($s4ClaseEnCopia) && is_file($s4Copia . '/src/Middleware/AuthMiddleware.php') && !is_file($s4Copia . '/.env'), $s4Copia);
foreach ([
    'ausente' => null,
    'rota (error de sintaxis)' => "<?php\nesto no es php {{{\n",
    'sin la clase (el archivo carga pero no la declara)' => "<?php\n// archivo vacio\n",
] as $s4Modo => $s4Contenido) {
    if ($s4Contenido === null) {
        @unlink($s4ClaseEnCopia);
    } else {
        file_put_contents($s4ClaseEnCopia, $s4Contenido);
    }
    $s4R = $s4Validar($s4Empresa(), 'GET', '/api/facturas', [], ['raiz' => $s4Copia]);
    $s4Cuerpo = json_decode($s4R['salida'], true);
    wa_chk("clase {$s4Modo}, empresa Express (GET /api/facturas, una ruta permitida): 403 con el codigo y el texto de TenantResolver, ni un valid false ni un 500, y last_used sin tocar",
        $s4R['http'] === 403 && $s4Cuerpo === ['status' => false, 'error' => TenantEntitlements::MSG_EDICION, 'codigo' => 'EDICION_NO_INCLUYE']
        && $s4R['pasos'] === 0 && $s4R['retorno'] === null && $s4R['llamadas'] === $s4SinCambios && $s4R['clase_cargada'] === false, $s4R);
    wa_chk("clase {$s4Modo}: deja UNA linea de log que nombra la clase",
        substr_count($s4R['log'], "\n") === 1 && str_contains($s4R['log'], 'TenantEntitlements'), $s4R['log']);
    $s4R = $s4Validar($s4Empresa(['edicion' => 'completa']), 'GET', '/api/gastos', ['last_used' => $s4Hace(400)], ['raiz' => $s4Copia]);
    wa_chk("clase {$s4Modo}, empresa completa: valida como siempre (la clase no se pide jamas), sin log",
        $s4R['retorno'] === $s4Valido && $s4R['llamadas'] === ['actualizar' => 1, 'revocar' => 0] && $s4R['clase_cargada'] === false
        && $s4R['log'] === '' && $s4R['http'] === false, $s4R);
}
// El resultado se recuerda: varias partes de una misma peticion piden la clase (la guarda, el gate de permisos, el login), y un
// archivo roto no se recompila ni se avisa de nuevo en cada llamada.
file_put_contents($s4ClaseEnCopia, "<?php\nesto no es php {{{\n");
$s4HijoCarga = (string) tempnam(sys_get_temp_dir(), 'ent_carga_');
$s4LogCarga = (string) tempnam(sys_get_temp_dir(), 'ent_log_');
file_put_contents($s4HijoCarga, <<<'PHP'
<?php
error_reporting(E_ALL);
ini_set('error_log', $argv[2]);
require $argv[1] . '/src/TenantResolver.php';
$primera = TenantResolver::cargarEntitlements();
$segunda = TenantResolver::cargarEntitlements();
echo json_encode([$primera, $segunda, class_exists('TenantEntitlements', false), (string) file_get_contents($argv[2])]);
PHP);
$s4R = wa_correr([PHP_BINARY, $s4HijoCarga, $s4Copia, $s4LogCarga]);
@unlink($s4HijoCarga);
@unlink($s4LogCarga);
$s4Dejo = json_decode(trim($s4R['salida']), true);
wa_chk('cargarEntitlements con la clase rota: false las dos veces, la clase sigue sin cargarse y el intento deja UNA sola linea de log',
    is_array($s4Dejo) && array_slice($s4Dejo, 0, 3) === [false, false, false]
    && substr_count((string) $s4Dejo[3], "\n") === 1 && str_contains((string) $s4Dejo[3], 'TenantEntitlements'), $s4R);

// --- config/editions.php que no se puede leer: la empresa Express recibe 403 (nunca un 500) y las demas ni se enteran --------------------------------------------
// Revision final. tokenCaducado, permisosEfectivos y bloque leen config() sin protegerse, y el gancho llama a tokenCaducado: un archivo de config con un error de sintaxis, que
// lanza al cargarse, que no devuelve un arreglo o que falta (los planes siguientes le agregan filas a mano) volvia fatal cada peticion, login y /me de una empresa Express
// (un 500 de ErrorHandler). Es lo mismo que la clase ausente o rota: sin su config no se puede decidir y una empresa Express no pasa, con el 403 EDICION_NO_INCLUYE.
// La copia de arriba, con la clase repuesta (aqui lo que falla es la config) y la config rota de cada manera; al final se repone la sana. La suite scratch lo repite por HTTP.
$s4ConfigEnCopia = $s4Copia . '/config/editions.php';
$s4ConfigSana = (string) file_get_contents("{$s4Raiz}/config/editions.php");
copy("{$s4Raiz}/src/TenantEntitlements.php", $s4ClaseEnCopia);
foreach ([
    'rota (error de sintaxis)' => "<?php\nreturn [\n    'modulos' => [\n",
    'que lanza al cargarse' => "<?php\nthrow new RuntimeException('config roto por la prueba');\n",
    'que no devuelve un arreglo' => "<?php\n\$sin_return = 1;\n",
    'que compila pero sin TOKEN_INACTIVIDAD_DIAS (contar con 0 dias vencia y revocaba todas las sesiones)' => "<?php\nreturn ['modulos' => ['facturacion'], 'rutas' => []];\n",
    'ausente' => null,
] as $s4Modo => $s4Contenido) {
    if ($s4Contenido === null) {
        @unlink($s4ConfigEnCopia);
    } else {
        file_put_contents($s4ConfigEnCopia, $s4Contenido);
    }
    // El token se uso hace un dia: tokenCaducado tiene que leer la config para decidir (con un config sin su plazo, contar con 0 dias lo daria por vencido y lo revocaria).
    $s4R = $s4Validar($s4Empresa(), 'GET', '/api/facturas', ['last_used' => $s4Hace(1)], ['raiz' => $s4Copia]);
    $s4Cuerpo = json_decode($s4R['salida'], true);
    wa_chk("config {$s4Modo}, empresa Express (GET /api/facturas, una ruta permitida): 403 con el codigo y el texto de TenantResolver, ni un valid false ni un 500; last_used sin tocar y el token sin revocar",
        $s4R['http'] === 403 && $s4Cuerpo === ['status' => false, 'error' => TenantEntitlements::MSG_EDICION, 'codigo' => 'EDICION_NO_INCLUYE']
        && $s4R['pasos'] === 0 && $s4R['retorno'] === null && $s4R['llamadas'] === $s4SinCambios && $s4R['clase_cargada'] === true, $s4R);
    wa_chk("config {$s4Modo}: deja UNA linea de log que nombra config/editions.php",
        substr_count($s4R['log'], "\n") === 1 && str_contains($s4R['log'], 'config/editions.php'), $s4R['log']);
    // Un token que nunca se uso (sin last_used ni created_at): tokenCaducado no llega a mirar la config, y la guarda de rutas, que ya se protegia sola, niega igual.
    $s4R = $s4Validar($s4Empresa(), 'GET', '/api/facturas', ['last_used' => null, 'created_at' => null], ['raiz' => $s4Copia]);
    $s4Cuerpo = json_decode($s4R['salida'], true);
    wa_chk("config {$s4Modo}, token sin uso (last_used y created_at NULL): el mismo 403 EDICION_NO_INCLUYE, desde la guarda de rutas",
        $s4R['http'] === 403 && $s4Cuerpo === ['status' => false, 'error' => TenantEntitlements::MSG_EDICION, 'codigo' => 'EDICION_NO_INCLUYE']
        && $s4R['pasos'] === 0 && $s4R['llamadas'] === $s4SinCambios, $s4R);
    $s4R = $s4Validar($s4Empresa(['edicion' => 'completa']), 'GET', '/api/gastos', ['last_used' => $s4Hace(400)], ['raiz' => $s4Copia]);
    wa_chk("config {$s4Modo}, empresa completa: valida como siempre (ni la clase ni la config se piden jamas), sin log",
        $s4R['retorno'] === $s4Valido && $s4R['llamadas'] === ['actualizar' => 1, 'revocar' => 0] && $s4R['clase_cargada'] === false
        && $s4R['log'] === '' && $s4R['http'] === false, $s4R);
}
file_put_contents($s4ConfigEnCopia, $s4ConfigSana);
$s4R = $s4Validar($s4Empresa(), 'GET', '/api/facturas', [], ['raiz' => $s4Copia]);
wa_chk('con la config sana otra vez en la copia, la misma empresa Express y la misma ruta validan: la copia de las pruebas de arriba no es lo que niega',
    $s4R['retorno'] === $s4Valido && $s4R['llamadas'] === ['actualizar' => 1, 'revocar' => 0] && $s4R['http'] === false && $s4R['log'] === '', $s4R);

// ---------------------------------------------------------------------------
// 5) PermissionGate::enforcing (forzado para Express) y PosAuth::exigirPosActivo
// ---------------------------------------------------------------------------
echo "\n== 5) enforcing() forzado para Express y POS_INACTIVO ==\n";

require_once __DIR__ . '/../src/PermissionGate.php';
require_once __DIR__ . '/../src/Pos/PosAuth.php';

/** Una fila de master.tenants de una empresa Express con POS; $o pisa los campos que cada caso cambia. */
$s5Fila = static fn (array $o = []): array => $o + [
    'id' => 12, 'nombre' => 'Constructora X', 'rnc' => '131000001', 'tipo' => 'app', 'ambiente' => 'ecf',
    'edicion' => 'express', 'tipos_ecf_habilitados' => '45', 'modulos_habilitados' => null, 'pos_enabled' => 1,
];
$s5SinEdicion = $s5Fila();
unset($s5SinEdicion['edicion']);
$s5SinTipo = $s5Fila();
unset($s5SinTipo['tipo']);
$s5Resuelta = static function (?array $tenant): void {
    (new ReflectionProperty(TenantResolver::class, 'current'))->setValue(null, $tenant);
};
/** PERMISSIONS_ENFORCE: null lo quita del entorno y de $_ENV (el gate lee los dos). */
$s5Flag = static function (?string $valor): void {
    putenv($valor === null ? 'PERMISSIONS_ENFORCE' : 'PERMISSIONS_ENFORCE=' . $valor);
    unset($_ENV['PERMISSIONS_ENFORCE']);
};
/** enforcing() es privado: se llama por reflexion, con $tenant como la empresa resuelta. */
$s5Enforcing = static function (?array $tenant) use ($s5Resuelta): bool {
    $s5Resuelta($tenant);
    return (new ReflectionMethod(PermissionGate::class, 'enforcing'))->invoke(null);
};

// --- enforcing(): con PERMISSIONS_ENFORCE sin definir (modo sombra), solo una empresa Express se aplica ----------------------
$s5EnvAntes = [getenv('PERMISSIONS_ENFORCE'), array_key_exists('PERMISSIONS_ENFORCE', $_ENV), $_ENV['PERMISSIONS_ENFORCE'] ?? null];
try {
    $s5Flag(null);
    $s5Casos = [   // descripcion => [empresa resuelta, esperado]
        "edicion 'express'" => [$s5Fila(), true],
        "edicion 'Express'" => [$s5Fila(['edicion' => 'Express']), true],
        "edicion ' EXPRESS ' (las mayusculas y los espacios no la cambian)" => [$s5Fila(['edicion' => ' EXPRESS ']), true],
        'una fila express sin la clave tipo (cuenta como app)' => [$s5SinTipo, true],
        "edicion 'completa'" => [$s5Fila(['edicion' => 'completa']), false],
        "edicion ' completa '" => [$s5Fila(['edicion' => ' completa ']), false],
        "edicion '' (el vacio se trata como completa)" => [$s5Fila(['edicion' => '']), false],
        "edicion 'basico' (desconocida: completa)" => [$s5Fila(['edicion' => 'basico']), false],
        "tipo 'integracion' con edicion 'express'" => [$s5Fila(['tipo' => 'integracion']), false],
        'edicion NULL' => [$s5Fila(['edicion' => null]), false],
        'una fila sin la clave edicion (master sin migrar)' => [$s5SinEdicion, false],
        'ninguna empresa resuelta' => [null, false],
    ];
    ob_start();
    [$s5Obtenido, $s5Error, $s5Log] = wa_con_log(static fn (): array => array_map(
        static fn (array $caso): bool => $s5Enforcing($caso[0]),
        $s5Casos
    ));
    $s5Salida = (string) ob_get_clean();
    foreach ($s5Casos as $s5Desc => [, $s5Esperado]) {
        wa_chk("enforcing() sin PERMISSIONS_ENFORCE, {$s5Desc}: " . ($s5Esperado ? 'true (forzado)' : 'false (modo sombra)'),
            ($s5Obtenido[$s5Desc] ?? null) === $s5Esperado, [$s5Obtenido[$s5Desc] ?? null, $s5Error?->getMessage()]);
    }
    wa_chk('enforcing() no imprime ni escribe en el log con ninguna de esas filas (la clase ya cargada, nada que avisar)',
        $s5Salida === '' && $s5Log === '', [$s5Salida, $s5Log]);

    // El interruptor global sigue valiendo como siempre, y apagado de forma explicita no desarma a una empresa Express.
    foreach ([
        ['true', 'una empresa completa', $s5Fila(['edicion' => 'completa']), true],
        ['true', 'ninguna empresa', null, true],
        ['true', 'una empresa Express', $s5Fila(), true],
        ['false', 'una empresa completa', $s5Fila(['edicion' => 'completa']), false],
        ['false', 'una empresa Express (se aplica con el interruptor apagado)', $s5Fila(), true],
    ] as [$s5Valor, $s5Desc, $s5Tenant, $s5Esperado]) {
        $s5Flag($s5Valor);
        wa_chk("enforcing() con PERMISSIONS_ENFORCE={$s5Valor}, {$s5Desc}: " . ($s5Esperado ? 'true' : 'false'),
            $s5Enforcing($s5Tenant) === $s5Esperado);
    }
    $s5Flag(null);
    $_ENV['PERMISSIONS_ENFORCE'] = 'true';   // el gate tambien lee $_ENV cuando el entorno del proceso no trae la variable
    wa_chk('enforcing() con PERMISSIONS_ENFORCE=true solo en $_ENV, empresa completa: true',
        $s5Enforcing($s5Fila(['edicion' => 'completa'])) === true);
} finally {
    putenv($s5EnvAntes[0] === false ? 'PERMISSIONS_ENFORCE' : 'PERMISSIONS_ENFORCE=' . $s5EnvAntes[0]);
    unset($_ENV['PERMISSIONS_ENFORCE']);
    if ($s5EnvAntes[1]) {
        $_ENV['PERMISSIONS_ENFORCE'] = $s5EnvAntes[2];
    }
    $s5Resuelta(null);   // no dejar una empresa resuelta
}

// --- PosAuth::exigirPosActivo: una empresa Express no tiene POS, ni con pos_enabled en 1 ---------------------------------------
/** Lo que lanzo exigirPosActivo ([codigo, http, mensaje]) o null si volvio sin lanzar. */
$s5Pos = static function (?array $tenant): ?array {
    try {
        PosAuth::exigirPosActivo($tenant);
        return null;
    } catch (PosError $e) {
        return [$e->codigo(), $e->http(), $e->getMessage()];
    }
};
$s5Inactivo = ['POS_INACTIVO', 403, 'El POS no está activo para esta empresa.'];
foreach ([
    "edicion 'express', tipo app, pos_enabled 1" => [['tipo' => 'app', 'pos_enabled' => 1, 'edicion' => 'express'], $s5Inactivo],
    "edicion ' EXPRESS ' (las mayusculas y los espacios no la cambian)" => [['tipo' => 'app', 'pos_enabled' => 1, 'edicion' => ' EXPRESS '], $s5Inactivo],
    "edicion 'completa', pos_enabled 1" => [['tipo' => 'app', 'pos_enabled' => 1, 'edicion' => 'completa'], null],
    "edicion ' completa ', pos_enabled 1" => [['tipo' => 'app', 'pos_enabled' => 1, 'edicion' => ' completa '], null],
    "edicion '' (el vacio se trata como completa), pos_enabled 1" => [['tipo' => 'app', 'pos_enabled' => 1, 'edicion' => ''], null],
    "edicion 'basico' (desconocida: completa), pos_enabled 1" => [['tipo' => 'app', 'pos_enabled' => 1, 'edicion' => 'basico'], null],
    'una fila sin la clave edicion (master sin migrar), pos_enabled 1' => [['tipo' => 'app', 'pos_enabled' => 1], null],
    "edicion 'completa', pos_enabled 0 (la regla de siempre)" => [['tipo' => 'app', 'pos_enabled' => 0, 'edicion' => 'completa'], $s5Inactivo],
    "tipo 'integracion', pos_enabled 1 (la regla de siempre)" => [['tipo' => 'integracion', 'pos_enabled' => 1, 'edicion' => 'completa'], $s5Inactivo],
    'ninguna empresa (la regla de siempre)' => [null, $s5Inactivo],
] as $s5Desc => [$s5Empresa, $s5Esperado]) {
    $s5Lanzo = $s5Pos($s5Empresa);
    wa_chk("exigirPosActivo, {$s5Desc}: " . ($s5Esperado === null ? 'vuelve sin lanzar' : 'PosError POS_INACTIVO, 403 y el texto de siempre'),
        $s5Lanzo === $s5Esperado, $s5Lanzo);
}

// --- lo que test_integracion_alcance.php pinea en PermissionGate.php sigue en pie: de enforcing() solo cambia el cuerpo ------
$s5Fuente = (string) file_get_contents(__DIR__ . '/../src/PermissionGate.php');
wa_chk('PermissionGate.php: deny() sigue con el parametro bool $siempre = false, una sola vez',
    substr_count($s5Fuente, 'bool $siempre = false') === 1);
wa_chk('PermissionGate.php: deny() sigue respetando $siempre antes del modo sombra (if (!$siempre && !self::enforcing())), una sola vez',
    substr_count($s5Fuente, 'if (!$siempre && !self::enforcing())') === 1);
wa_chk("PermissionGate.php: la rama 'principal no-usuario en ruta de app' sigue llamando deny(..., true), una sola vez",
    substr_count($s5Fuente, 'principal no-usuario en ruta de app') === 1
    && preg_match("/principal no-usuario en ruta de app', 403,\s*'[^']*', true\)/", $s5Fuente) === 1);

// --- enforcing() y la clase: solo carga detras de la prueba en linea, y sin ella una empresa Express se niega -----------------
// En un proceso nuevo, porque el de la suite ya tiene la clase cargada (seccion 2). Las copias del arbol con la clase ausente,
// rota o sin declarar son la de la seccion 4 ($s4Copia): una empresa Express sin su clase no se puede decidir y no pasa, y
// jamas es un 500 (el Router se traga lo que lance el gate y el controller seguiria sin que nadie lo detuviera).
$s5Raiz = dirname(__DIR__);
$s5Hijo = (string) tempnam(sys_get_temp_dir(), 'ent_gate_');
$s5HijoLog = (string) tempnam(sys_get_temp_dir(), 'ent_gatelog_');
file_put_contents($s5Hijo, <<<'PHP'
<?php
// argv[1] = raiz del arbol, argv[2] = archivo de error_log, argv[3] = la empresa resuelta (JSON en base64).
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('error_log', $argv[2]);
putenv('PERMISSIONS_ENFORCE');
unset($_ENV['PERMISSIONS_ENFORCE']);
require $argv[1] . '/src/PermissionGate.php';
(new ReflectionProperty('TenantResolver', 'current'))->setValue(null, json_decode((string) base64_decode($argv[3]), true));
$metodo = new ReflectionMethod('PermissionGate', 'enforcing');
// deny() la llama dos veces por cada 403 (la bitacora y la decision): aqui tambien dos.
echo json_encode([$metodo->invoke(null), $metodo->invoke(null), class_exists('TenantEntitlements', false), (string) file_get_contents($argv[2])]);
PHP);
/** enforcing() dos veces en un proceso nuevo sobre el arbol $raiz: [decision, decision, clase_cargada, log] o ['hijo_sin_json' => lo que imprimio]. */
$s5EnHijo = static function (string $raiz, ?array $empresa) use ($s5Hijo, $s5HijoLog): array {
    file_put_contents($s5HijoLog, '');
    $r = wa_correr([PHP_BINARY, $s5Hijo, $raiz, $s5HijoLog, base64_encode((string) json_encode($empresa))]);
    $dejo = json_decode(trim($r['salida']), true);
    return is_array($dejo) ? $dejo : ['hijo_sin_json' => $r];
};
$s5R = $s5EnHijo($s5Raiz, $s5Fila());
wa_chk('enforcing() en un proceso nuevo, empresa Express: true las dos veces, la clase se carga (detras de la prueba en linea) y sin log',
    $s5R === [true, true, true, ''], $s5R);
foreach ([
    "edicion 'completa'" => $s5Fila(['edicion' => 'completa']),
    "edicion '' (el vacio se trata como completa)" => $s5Fila(['edicion' => '']),
    "tipo 'integracion' con edicion 'express'" => $s5Fila(['tipo' => 'integracion']),
    'ninguna empresa resuelta' => null,
] as $s5Desc => $s5Empresa) {
    $s5R = $s5EnHijo($s5Raiz, $s5Empresa);
    wa_chk("enforcing() en un proceso nuevo, {$s5Desc}: false, TenantEntitlements no se carga jamas y sin log",
        $s5R === [false, false, false, ''], $s5R);
}
$s5ClaseEnCopia = $s4Copia . '/src/TenantEntitlements.php';
wa_chk('la copia del arbol de la seccion 4 sigue ahi, con el gate y la clase (de ahi salen la clase ausente, rota y sin declarar)',
    is_file($s4Copia . '/src/PermissionGate.php') && is_file($s5ClaseEnCopia), $s4Copia);
foreach ([
    'ausente' => null,
    'rota (error de sintaxis)' => "<?php\nesto no es php {{{\n",
    'sin la clase (el archivo carga pero no la declara)' => "<?php\n// archivo vacio\n",
] as $s5Modo => $s5Contenido) {
    if ($s5Contenido === null) {
        @unlink($s5ClaseEnCopia);
    } else {
        file_put_contents($s5ClaseEnCopia, $s5Contenido);
    }
    $s5R = $s5EnHijo($s4Copia, $s5Fila());
    wa_chk("clase {$s5Modo}, empresa Express: enforcing() es true las dos veces (sin la clase no se puede decidir y no pasa), la clase no carga y el intento deja UNA linea de log que la nombra",
        array_slice($s5R, 0, 3) === [true, true, false] && substr_count((string) ($s5R[3] ?? ''), "\n") === 1
        && str_contains((string) ($s5R[3] ?? ''), 'TenantEntitlements'), $s5R);
    $s5R = $s5EnHijo($s4Copia, $s5Fila(['edicion' => 'completa']));
    wa_chk("clase {$s5Modo}, empresa completa: enforcing() es false, la clase no se pide jamas y no hay log",
        $s5R === [false, false, false, ''], $s5R);
}
@unlink($s5Hijo);
@unlink($s5HijoLog);

// ---------------------------------------------------------------------------
// 6) user.entitlements en el login y en /me (authModel: conEntitlements, filaTenant, permissionsForUser)
// ---------------------------------------------------------------------------
echo "\n== 6) user.entitlements en el login y en /me ==\n";

/**
 * PDO de mentira para MasterDatabase (su propiedad conexion esta tipada PDO: no vale cualquier objeto). No llama al constructor
 * del padre, asi que no abre nada. Con $respuesta en null, prepare() lanza una PDOException (la base no responde); con una
 * funcion, cada consulta que llega a execute() se anota en $consultas y $respuesta(sql, parametros) devuelve sus filas (o lanza).
 */
final class ExpPdoMaster extends PDO
{
    /** @var list<array{sql:string,params:array<string,mixed>}> */
    public array $consultas = [];
    public ?Closure $respuesta = null;

    public function __construct()
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if ($this->respuesta === null) {
            throw new PDOException('prepare de mentira: la base no responde');
        }
        // Un PDOStatement no se construye a mano: se crea sin constructor y se le dice de que conexion viene.
        $sentencia = (new ReflectionClass(ExpSentenciaMaster::class))->newInstanceWithoutConstructor();
        $sentencia->origen = $this;
        $sentencia->sql = $query;
        return $sentencia;
    }
}

/** La sentencia de ExpPdoMaster: execute() anota la consulta y pide sus filas; fetch() y fetchAll() las entregan. */
final class ExpSentenciaMaster extends PDOStatement
{
    public ExpPdoMaster $origen;
    public string $sql = '';
    /** @var list<array<string,mixed>> */
    private array $filas = [];

    public function execute(?array $params = null): bool
    {
        $this->origen->consultas[] = ['sql' => $this->sql, 'params' => $params ?? []];
        $this->filas = ($this->origen->respuesta)($this->sql, $params ?? []);
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return $this->filas[0] ?? false;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->filas;
    }
}

// --- los dobles valen en PHP 8.3 (produccion y CI), no solo en el PHP que corre la suite -------------------------------------------
// Las dos clases de arriba heredan de clases internas cuyas firmas cambian de una version de PHP a otra, y esta suite se escribe en un
// PHP mas nuevo que el de produccion. Aqui se declaran OTRA VEZ, con su texto de arriba, en un proceso aparte y heredando de COPIAS DE
// USUARIO de las firmas que PDO y PDOStatement tienen en 8.3 (ext/pdo/pdo_dbh.stub.php y pdo_stmt.stub.php, rama PHP-8.3 de php-src):
// el motor les aplica las reglas de herencia con esas firmas sea cual sea la version que corre, y con mas rigor que con la clase
// interna (de un tipo de retorno distinto esa solo avisa). Si el motor las acepta, la clase interna de 8.3 tambien. Cada control cambia
// UNA firma y tiene que ser rechazado: sin ellos, un cambio de nombres que no se aplicara dejaria la prueba aceptando todo.
$s6Firmas83 = <<<'PHP'
class Firma83Pdo
{
    public function __construct(string $dsn, ?string $username = null, #[\SensitiveParameter] ?string $password = null, ?array $options = null) {}
    public function prepare(string $query, array $options = []): PDOStatement|false {}
}
class Firma83PdoStatement
{
    public function execute(?array $params = null): bool {}
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed {}
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array {}
}
PHP;
/** El texto de una clase de este archivo, de su linea de declaracion a su llave de cierre. */
$s6TextoClase = static function (string $clase): string {
    $ref = new ReflectionClass($clase);
    $lineas = file((string) $ref->getFileName(), FILE_IGNORE_NEW_LINES) ?: [];
    return implode("\n", array_slice($lineas, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1));
};
$s6Copias = strtr($s6TextoClase('ExpPdoMaster') . "\n" . $s6TextoClase('ExpSentenciaMaster'), [
    'ExpPdoMaster' => 'Firma83PdoMaster', 'ExpSentenciaMaster' => 'Firma83SentenciaMaster',
    'extends PDOStatement' => 'extends Firma83PdoStatement', 'extends PDO' => 'extends Firma83Pdo',
]);
/** Declara las copias de los dobles (con $cambio, un 'texto' => 'reemplazo' que altera una firma) en un php nuevo: [codigo de salida, lo que imprimio]. */
$s6Declarar = static function (array $cambio = []) use ($s6Firmas83, $s6Copias): array {
    $archivo = (string) tempnam(sys_get_temp_dir(), 'ent_firmas_');
    file_put_contents($archivo, "<?php\n{$s6Firmas83}\n" . strtr($s6Copias, $cambio) . "\necho 'ACEPTADO';\n");
    $r = wa_correr([PHP_BINARY, '-d', 'display_errors=1', '-d', 'error_reporting=-1', $archivo]);
    @unlink($archivo);
    return [$r['codigo'], $r['salida']];
};
wa_chk('dobles de PDO: las copias heredan de las firmas de 8.3 de arriba y de ninguna clase del PHP que corre la suite (el cambio de nombres se aplico)',
    preg_match_all('/extends Firma83Pdo\b/', $s6Copias) === 1 && preg_match_all('/extends Firma83PdoStatement\b/', $s6Copias) === 1
    && !preg_match('/extends\s+PDO/', $s6Copias), $s6Copias);
[$s6Cod, $s6Sal] = $s6Declarar();
wa_chk('dobles de PDO: sobre las firmas de PDO y PDOStatement de PHP 8.3 (prepare, execute, fetch y fetchAll) el motor los acepta',
    $s6Cod === 0 && str_contains($s6Sal, 'ACEPTADO') && !str_contains($s6Sal, 'Fatal error'), [$s6Cod, $s6Sal]);
foreach ([
    'execute con un parametro mas estrecho (array en lugar de ?array)' => ['execute(?array $params = null): bool' => 'execute(array $params = []): bool'],
    'fetch con un parametro menos' => [', int $cursorOffset = 0): mixed' => '): mixed'],
    'fetchAll con un retorno mas ancho (iterable en lugar de array)' => ['mixed ...$args): array' => 'mixed ...$args): iterable'],
    'prepare con otro tipo de parametro (int en lugar de string)' => ['prepare(string $query,' => 'prepare(int $query,'],
] as $s6Desc => $s6Cambio) {
    [$s6Cod, $s6Sal] = $s6Declarar($s6Cambio);
    wa_chk("control, {$s6Desc}: el motor lo rechaza (Declaration ... must be compatible with la firma de 8.3)",
        $s6Cod !== 0 && str_contains($s6Sal, 'must be compatible with Firma83'), [$s6Cod, $s6Sal]);
}
/** Los metodos que una clase declara ella misma (sin el constructor y sin los heredados), ordenados. */
$s6Propios = static function (string $clase): array {
    $nombres = array_map(
        static fn (ReflectionMethod $m): string => $m->getName(),
        array_filter((new ReflectionClass($clase))->getMethods(), static fn (ReflectionMethod $m): bool => $m->class === $clase && !$m->isConstructor())
    );
    sort($nombres);
    return array_values($nombres);
};
wa_chk('...y los dobles solo sobreescriben metodos cuya firma de 8.3 esta en la copia de arriba (agregar otro obliga a sumar su firma)',
    $s6Propios('ExpPdoMaster') === ['prepare'] && $s6Propios('ExpSentenciaMaster') === ['execute', 'fetch', 'fetchAll'],
    [$s6Propios('ExpPdoMaster'), $s6Propios('ExpSentenciaMaster')]);

$s6Raiz = dirname(__DIR__);
/** Una fila de master.tenants de una empresa Express, con las columnas que jamas deben llegar al cliente; $o pisa los campos que cada caso cambia. */
$s6Fila = static fn (array $o = []): array => $o + [
    'id' => 12, 'nombre' => 'Constructora X', 'rnc' => '131000001', 'tipo' => 'app', 'ambiente' => 'ecf',
    'edicion' => 'express', 'tipos_ecf_habilitados' => '45', 'modulos_habilitados' => null, 'pos_enabled' => 0,
    'api_key' => 'api-key-secreta', 'api_secret_hash' => 'hash-secreto', 'db_pass_encrypted' => 'clave-db-secreta',
    'cert_path' => 'certificado_dgii/x/cert.p12', 'cert_pass_encrypted' => 'clave-cert-secreta', 'cert_vence' => '2027-01-31',
];
/** El user del login antes de la edicion: lo que emitirSesion arma para un administrador (permissions '*'). */
$s6Usuario = ['id' => 7, 'email' => 'ana@example.com', 'username' => 'ana', 'name' => 'Ana Perez', 'role' => 'admin', 'permissions' => ['*']];
/** El mismo user como JSON, escrito a mano: la forma que una empresa completa recibe hoy y que no puede cambiar. */
$s6UsuarioJson = '{"id":7,"email":"ana@example.com","username":"ana","name":"Ana Perez","role":"admin","permissions":["*"]}';
/** Los permisos de un administrador Express: el '*' cruzado con los modulos del preset, en el orden de sus tokens. */
$s6PermisosAdminExpress = ['facturas', 'clients', 'products', 'users', 'ncf', 'unidades'];
/** Llama a un metodo privado de authModel por reflexion. */
$s6Llamar = static fn (authModel $modelo, string $metodo, array $args): mixed => (new ReflectionMethod('authModel', $metodo))->invoke($modelo, ...$args);

// --- conEntitlements: el bloque solo para una fila Express, y despues de permissions ---------------------------------------
// authModel sin constructor y con una conexion de mentira: $s4Modelo y ExpConexionFalsa son de la seccion 4, que ya los armo.
// La primera llamada va sin envolver en wa_con_log a proposito: si el metodo no existe, la ReflectionException corta la suite (exit 255).
$s6Modelo = $s4Modelo(new ExpConexionFalsa(false));
$s6Express = $s6Fila();
$s6R = $s6Llamar($s6Modelo, 'conEntitlements', [$s6Usuario, $s6Express]);
wa_chk('conEntitlements: una fila express agrega entitlements DESPUES de permissions (el orden de las claves del user es el del contrato)',
    json_encode(array_keys($s6R)) === '["id","email","username","name","role","permissions","entitlements"]', array_keys($s6R));
wa_chk('conEntitlements: el bloque es el de TenantEntitlements::bloque(fila) y lo demas del user queda como estaba',
    array_slice($s6R, 0, 6, true) === $s6Usuario && $s6R['entitlements'] === TenantEntitlements::bloque($s6Express), $s6R);
$s6Json = (string) json_encode($s6R);
$s6Fugas = array_values(array_filter(
    ['api-key-secreta', 'hash-secreto', 'clave-db-secreta', 'certificado_dgii', 'clave-cert-secreta', 'cert_vence', '2027-01-31'],
    static fn (string $secreto): bool => str_contains($s6Json, $secreto)
));
wa_chk('conEntitlements: solo sale la lista blanca del bloque (cinco claves, y tenant con id, nombre, rnc y ambiente): ningun dato de la fila se filtra',
    array_keys($s6R['entitlements']) === ['edicion', 'tenant', 'modulos', 'tipos_ecf', 'capacidades']
    && array_keys($s6R['entitlements']['tenant']) === ['id', 'nombre', 'rnc', 'ambiente'] && $s6Fugas === [], [$s6Fugas, array_keys($s6R['entitlements'])]);

$s6SinEdicion = $s6Fila();
unset($s6SinEdicion['edicion']);
$s6SinTipo = $s6Fila();
unset($s6SinTipo['tipo']);
$s6Casos = [   // descripcion => [fila, ¿lleva el bloque?]
    "edicion 'express'" => [$s6Fila(), true],
    "edicion 'Express'" => [$s6Fila(['edicion' => 'Express']), true],
    "edicion ' EXPRESS ' (las mayusculas y los espacios no la cambian)" => [$s6Fila(['edicion' => ' EXPRESS ']), true],
    'una fila express sin la clave tipo (cuenta como app)' => [$s6SinTipo, true],
    "edicion 'completa'" => [$s6Fila(['edicion' => 'completa']), false],
    "edicion ' completa '" => [$s6Fila(['edicion' => ' completa ']), false],
    "edicion '' (el vacio se trata como completa)" => [$s6Fila(['edicion' => '']), false],
    "edicion 'basico' (desconocida: completa)" => [$s6Fila(['edicion' => 'basico']), false],
    "tipo 'integracion' con edicion 'express'" => [$s6Fila(['tipo' => 'integracion']), false],
    'edicion NULL' => [$s6Fila(['edicion' => null]), false],
    'una fila sin la clave edicion (master sin migrar)' => [$s6SinEdicion, false],
    'ninguna fila (null)' => [null, false],
];
ob_start();
[$s6Obtenido, $s6Error, $s6Log] = wa_con_log(static fn (): array => array_map(
    static fn (array $caso): array => $s6Llamar($s6Modelo, 'conEntitlements', [$s6Usuario, $caso[0]]),
    $s6Casos
));
$s6Salida = (string) ob_get_clean();
foreach ($s6Casos as $s6Desc => [, $s6Lleva]) {
    $s6R = $s6Obtenido[$s6Desc] ?? null;
    wa_chk("conEntitlements, {$s6Desc}: " . ($s6Lleva ? 'agrega el bloque' : 'devuelve el user igual, sin la clave entitlements (ni como null)'),
        $s6Lleva
            ? is_array($s6R) && is_array($s6R['entitlements'] ?? null)
            : $s6R === $s6Usuario && !array_key_exists('entitlements', (array) $s6R),
        [$s6R, $s6Error?->getMessage()]);
}
wa_chk('conEntitlements no imprime ni escribe en el log con ninguna de esas filas (una edicion desconocida la avisa AuthMiddleware, no el login)',
    $s6Salida === '' && $s6Log === '', [$s6Salida, $s6Log]);

// --- filaTenant, y el login y /me enteros contra un master de mentira ------------------------------------------------------
// Todo lo que cambia estado del proceso (la instancia de MasterDatabase, la empresa resuelta, el modo multi-tenant) se repone en el finally.
$s6Instancia = new ReflectionProperty('MasterDatabase', 'instance');
$s6InstanciaAntes = $s6Instancia->getValue();
$s6Actual = new ReflectionProperty('TenantResolver', 'current');
$s6MtAntes = getenv('MULTI_TENANT_ENABLED');
/** Un MasterDatabase sin constructor, con $pdo de conexion, como la instancia que devuelve getInstance(). */
$s6PonerMaestro = static function (ExpPdoMaster $pdo) use ($s6Instancia): void {
    $maestro = (new ReflectionClass('MasterDatabase'))->newInstanceWithoutConstructor();
    (new ReflectionProperty('MasterDatabase', 'conexion'))->setValue($maestro, $pdo);
    $s6Instancia->setValue(null, $maestro);
};
/**
 * Un master de mentira que contesta la fila de cada empresa ($tenants: id => fila) y los permisos de cada rol ($permisos: rol =>
 * lista); con $tenantsCae, leer tenants lanza una PDOException. Cualquier otra consulta hace fallar la prueba (LogicException).
 */
$s6NuevoMaestro = static function (array $tenants, array $permisos = ['admin' => ['*']], bool $tenantsCae = false) use ($s6PonerMaestro): ExpPdoMaster {
    $pdo = new ExpPdoMaster();
    $pdo->respuesta = static function (string $sql, array $params) use ($tenants, $permisos, $tenantsCae): array {
        if (str_contains($sql, 'FROM tenants')) {
            if ($tenantsCae) {
                throw new PDOException('la lectura de tenants fallo (prueba)');
            }
            $id = (int) ($params[':id'] ?? 0);
            return isset($tenants[$id]) ? [$tenants[$id]] : [];
        }
        if (str_contains($sql, 'role_permissions')) {
            return array_map(static fn (string $p): array => ['permission' => $p], $permisos[(string) ($params[':name'] ?? '')] ?? []);
        }
        throw new LogicException('consulta inesperada al master de mentira: ' . $sql);
    };
    $s6PonerMaestro($pdo);
    return $pdo;
};
/** Los parametros de cada lectura a tenants que llego al master de mentira. */
$s6Lecturas = static fn (ExpPdoMaster $pdo): array => array_values(array_map(
    static fn (array $consulta): array => $consulta['params'],
    array_filter($pdo->consultas, static fn (array $consulta): bool => str_contains($consulta['sql'], 'FROM tenants'))
));
$s6Clave = 'clave-de-prueba';
$s6Hash = password_hash($s6Clave, PASSWORD_BCRYPT, ['cost' => 4]);
/** La fila de users (modo multi-tenant) que leen loginUser y getUserProfile; $o pisa campos. */
$s6FilaUsuario = static fn (array $o = []): array => $o + [
    'id' => '7', 'tenant_id' => '12', 'email' => 'ana@example.com', 'username' => 'ana', 'name' => 'Ana', 'last_name' => 'Perez',
    'password' => $s6Hash, 'role' => 'admin',
];
/** loginUser de la usuaria 'ana' (con $usuario pisando su fila) contra un master que tiene $tenants y $permisos: lo que devolvio, lo que lanzo, el log y las lecturas a tenants. */
$s6Login = static function (array $tenants, array $permisos = ['admin' => ['*']], bool $tenantsCae = false, array $usuario = [])
    use ($s6NuevoMaestro, $s6Lecturas, $s4Modelo, $s6FilaUsuario, $s6Clave): array {
    $pdo = $s6NuevoMaestro($tenants, $permisos, $tenantsCae);
    [$r, $e, $log] = wa_con_log(static fn (): array => $s4Modelo(new ExpConexionFalsa($s6FilaUsuario($usuario)))->loginUser('ana', $s6Clave));
    return ['r' => $r, 'e' => $e, 'log' => $log, 'lecturas' => $s6Lecturas($pdo)];
};
/** getUserProfile de la usuaria 7 con $actual como empresa resuelta de la peticion y $tenants en el master: igual que $s6Login. */
$s6Perfil = static function (?array $actual, array $tenants, array $permisos = ['admin' => ['*']], array $usuario = [])
    use ($s6NuevoMaestro, $s6Lecturas, $s4Modelo, $s6FilaUsuario, $s6Actual): array {
    $pdo = $s6NuevoMaestro($tenants, $permisos);
    $s6Actual->setValue(null, $actual);
    [$r, $e, $log] = wa_con_log(static fn (): mixed => $s4Modelo(new ExpConexionFalsa($s6FilaUsuario($usuario)))->getUserProfile(7));
    return ['r' => $r, 'e' => $e, 'log' => $log, 'lecturas' => $s6Lecturas($pdo)];
};
try {
    putenv('MULTI_TENANT_ENABLED=true');

    // filaTenant: con el master caido devuelve null y no lanza.
    $s6Caido = new ExpPdoMaster();   // sin respuesta: prepare() lanza una PDOException
    $s6PonerMaestro($s6Caido);
    [$s6R, $s6E, $s6Log] = wa_con_log(static fn (): mixed => $s6Llamar($s6Modelo, 'filaTenant', ['12']));
    wa_chk('filaTenant con el master caido (la conexion lanza al preparar): devuelve null y no lanza',
        $s6R === null && $s6E === null, [$s6R, $s6E === null ? null : get_class($s6E) . ': ' . $s6E->getMessage()]);
    wa_chk('...y deja UNA linea de log que nombra filaTenant (un login sin el bloque no pasa en silencio)',
        substr_count($s6Log, "\n") === 1 && str_contains($s6Log, 'filaTenant'), $s6Log);

    // filaTenant: una lectura con el id como entero; null si no hay tenant o no hay master.
    $s6Pdo = $s6NuevoMaestro([12 => $s6Fila()]);
    [$s6R, $s6E, $s6Log] = wa_con_log(static fn (): mixed => $s6Llamar($s6Modelo, 'filaTenant', ['12']));
    wa_chk('filaTenant (multi-tenant): UNA lectura a tenants con el id como entero (getTenantById) y devuelve la fila de la empresa, sin log',
        $s6E === null && $s6R === $s6Fila() && $s6Lecturas($s6Pdo) === [[':id' => 12]] && count($s6Pdo->consultas) === 1 && $s6Log === '',
        [$s6R, $s6Pdo->consultas]);
    $s6Pdo = $s6NuevoMaestro([]);
    [$s6R, $s6E] = wa_con_log(static fn (): mixed => $s6Llamar($s6Modelo, 'filaTenant', [12]));
    wa_chk('filaTenant de una empresa inexistente o inactiva: null, una lectura y nada mas (el login sigue como siempre)',
        $s6E === null && $s6R === null && $s6Lecturas($s6Pdo) === [[':id' => 12]], [$s6R, $s6Pdo->consultas]);
    $s6Pdo = $s6NuevoMaestro([12 => $s6Fila()]);
    [$s6R, $s6E] = wa_con_log(static fn (): mixed => $s6Llamar($s6Modelo, 'filaTenant', [null]));
    wa_chk('filaTenant sin tenant_id (null): null y ninguna lectura', $s6E === null && $s6R === null && $s6Pdo->consultas === [], [$s6R, $s6Pdo->consultas]);
    putenv('MULTI_TENANT_ENABLED=false');
    [$s6R, $s6E] = wa_con_log(static fn (): mixed => $s6Llamar($s6Modelo, 'filaTenant', ['12']));
    wa_chk('filaTenant en modo de un solo tenant: null y ninguna lectura (no hay master al que preguntar)',
        $s6E === null && $s6R === null && $s6Pdo->consultas === [], [$s6R, $s6Pdo->consultas]);
    putenv('MULTI_TENANT_ENABLED=true');

    // loginUser entero: el user, el bloque, los permisos y las lecturas.
    $s6L = $s6Login([12 => $s6Fila()]);
    $s6U = $s6L['r'][1]['user'] ?? [];
    wa_chk('loginUser (Express): success con un token y un user con id, email, username, name, role, permissions y entitlements, en ese orden',
        $s6L['e'] === null && ($s6L['r'][0] ?? null) === 'success' && is_string($s6L['r'][1]['token'] ?? null) && $s6L['r'][1]['token'] !== ''
        && json_encode(array_keys($s6U)) === '["id","email","username","name","role","permissions","entitlements"]',
        [$s6L['e']?->getMessage(), array_keys($s6U)]);
    wa_chk('loginUser (Express): permissions es el "*" del administrador cruzado con los modulos de la edicion, y entitlements el bloque de su fila',
        ($s6U['permissions'] ?? null) === $s6PermisosAdminExpress && ($s6U['entitlements'] ?? null) === TenantEntitlements::bloque($s6Fila()), $s6U);
    wa_chk('loginUser (Express): UNA sola lectura a tenants (con el tenant_id del usuario) y el tercer elemento, el de la bitacora, sigue siendo la empresa',
        $s6L['lecturas'] === [[':id' => 12]] && ($s6L['r'][2] ?? null) === ['tenant_id' => 12] && $s6L['log'] === '', [$s6L['lecturas'], $s6L['r'][2] ?? null, $s6L['log']]);
    $s6L = $s6Login([12 => $s6Fila()], ['user' => ['gastos', 'ncf', 'facturas', 'clients', 'reportes']], false, ['role' => 'user']);
    wa_chk('loginUser (Express, rol user): permissions son los del rol cruzados con los modulos de la edicion, en el orden de los tokens y no en el del rol, sin lo que la edicion no incluye',
        ($s6L['r'][1]['user']['permissions'] ?? null) === ['facturas', 'clients', 'ncf'], $s6L['r'][1]['user'] ?? null);
    $s6L = $s6Login([12 => $s6Fila(['edicion' => 'completa'])]);
    wa_chk('loginUser (completa): el user es EXACTAMENTE el de siempre (mismas claves, mismo orden, permissions crudas) y tambien lee la empresa una vez',
        $s6L['e'] === null && json_encode($s6L['r'][1]['user'] ?? null) === $s6UsuarioJson && $s6L['lecturas'] === [[':id' => 12]],
        [$s6L['r'][1]['user'] ?? null, $s6L['lecturas']]);
    $s6L = $s6Login([12 => $s6Fila()], ['admin' => ['*']], true);
    wa_chk('loginUser: si la lectura de la empresa falla, el login sigue (success y token) como si fuera completa: sin el bloque y con los permisos del rol tal cual',
        $s6L['e'] === null && ($s6L['r'][0] ?? null) === 'success' && ($s6L['r'][1]['token'] ?? '') !== ''
        && json_encode($s6L['r'][1]['user'] ?? null) === $s6UsuarioJson, [$s6L['e']?->getMessage(), $s6L['r'][1]['user'] ?? null]);
    wa_chk('...hizo UN intento de lectura y dejo UNA linea de log que nombra filaTenant',
        count($s6L['lecturas']) === 1 && substr_count($s6L['log'], "\n") === 1 && str_contains($s6L['log'], 'filaTenant'), [$s6L['lecturas'], $s6L['log']]);

    // getUserProfile (/me): la empresa que ya resolvio la peticion se reutiliza si es la del usuario; si no, una lectura.
    $s6P = $s6Perfil($s6Fila(['tipos_ecf_habilitados' => '31']), [12 => $s6Fila()]);
    wa_chk('getUserProfile con TenantResolver::current() de la empresa del usuario: arma el bloque con ESA fila (tipos 31, no los 45 del master) y NO lee tenants',
        ($s6P['r']['entitlements']['tipos_ecf'] ?? null) === ['31', '34'] && $s6P['lecturas'] === [] && $s6P['e'] === null,
        [$s6P['r']['entitlements'] ?? null, $s6P['lecturas']]);
    wa_chk('getUserProfile (Express): las claves son las del login (id, email, username, name, role, permissions y entitlements) y permissions va cruzada con la edicion',
        json_encode(array_keys($s6P['r'] ?? [])) === '["id","email","username","name","role","permissions","entitlements"]'
        && ($s6P['r']['permissions'] ?? null) === $s6PermisosAdminExpress, $s6P['r']);
    $s6P = $s6Perfil(null, [12 => $s6Fila()]);
    wa_chk('getUserProfile sin empresa resuelta (current() null): UNA lectura a tenants y el bloque sale de la fila del master (tipos 45)',
        ($s6P['r']['entitlements']['tipos_ecf'] ?? null) === ['45', '34'] && $s6P['lecturas'] === [[':id' => 12]], [$s6P['r']['entitlements'] ?? null, $s6P['lecturas']]);
    $s6P = $s6Perfil($s6Fila(['id' => 99, 'tipos_ecf_habilitados' => '31']), [12 => $s6Fila(['edicion' => 'completa'])]);
    wa_chk('getUserProfile con current() de OTRA empresa (id 99, Express): no se usa su fila; se lee la del usuario (completa) y el perfil sale sin el bloque',
        !array_key_exists('entitlements', (array) $s6P['r']) && $s6P['lecturas'] === [[':id' => 12]] && json_encode($s6P['r']) === $s6UsuarioJson,
        [$s6P['r'], $s6P['lecturas']]);
    $s6P = $s6Perfil($s6Fila(['edicion' => 'completa']), []);
    wa_chk('getUserProfile (completa, current() de su empresa): el perfil es EXACTAMENTE el de siempre y no lee tenants',
        json_encode($s6P['r']) === $s6UsuarioJson && $s6P['lecturas'] === [] && $s6P['log'] === '', [$s6P['r'], $s6P['lecturas'], $s6P['log']]);
} finally {
    $s6Instancia->setValue(null, $s6InstanciaAntes);
    $s6Actual->setValue(null, null);
    putenv($s6MtAntes === false ? 'MULTI_TENANT_ENABLED' : 'MULTI_TENANT_ENABLED=' . $s6MtAntes);
}

// --- la clase de la edicion: una empresa que no es Express no la carga jamas, y sin ella una Express recibe el login sin el bloque ---
// En un proceso nuevo por caso (la clase ya esta cargada en este), sin multi-tenant: RoleModel lee de Database, que aqui es una
// conexion de mentira con los permisos '*' de un administrador. Las copias del arbol con la clase ausente, rota o sin declarar
// son la de la seccion 4 ($s4Copia).
// La otra mitad de esa decision (el login sale sin el bloque y el cliente falla cerrado ante su falta) es la peticion SIGUIENTE de esa
// empresa: la guarda de AuthMiddleware le contesta 403 EDICION_NO_INCLUYE, ni un valid false (el SPA lo cerraria como un 401) ni un 500.
// Eso lo fija la seccion 4 sobre estas mismas tres copias (las pruebas "clase <modo>, empresa Express (GET /api/facturas, una ruta
// permitida)") y, por HTTP, la suite scratch (plan 02, tarea 12, seccion 11).
$s6Hijo = (string) tempnam(sys_get_temp_dir(), 'ent_sesion_');
$s6HijoLog = (string) tempnam(sys_get_temp_dir(), 'ent_seslog_');
file_put_contents($s6Hijo, <<<'PHP'
<?php
// argv[1] = raiz del arbol, argv[2] = archivo de error_log, argv[3] = la fila de la empresa (JSON en base64).
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('error_log', $argv[2]);
putenv('MULTI_TENANT_ENABLED=false');
unset($_ENV['MULTI_TENANT_ENABLED']);
$fila = json_decode((string) base64_decode($argv[3]), true);
require $argv[1] . '/src/Models/authModel.php';
final class HijoSentencia
{
    public function execute(?array $params = null): bool
    {
        return true;
    }

    public function fetchAll(): array
    {
        return [['permission' => '*']];
    }
}
final class HijoConexion
{
    public function prepare(string $sql): HijoSentencia
    {
        return new HijoSentencia();
    }
}
$bd = (new ReflectionClass('Database'))->newInstanceWithoutConstructor();
(new ReflectionProperty('Database', 'conexion'))->setValue($bd, new HijoConexion());
(new ReflectionProperty('Database', 'instance'))->setValue(null, $bd);
$modelo = (new ReflectionClass('authModel'))->newInstanceWithoutConstructor();
$usuario = ['id' => 7, 'email' => 'ana@example.com', 'username' => 'ana', 'name' => 'Ana Perez', 'role' => 'admin', 'permissions' => ['*']];
$permisos = (new ReflectionMethod('authModel', 'permissionsForUser'))->invoke($modelo, 12, 'admin', $fila);
$conBloque = (new ReflectionMethod('authModel', 'conEntitlements'))->invoke($modelo, $usuario, $fila);
echo json_encode([$permisos, $conBloque, class_exists('TenantEntitlements', false), (string) file_get_contents($argv[2])]);
PHP);
/** permissionsForUser y conEntitlements en un proceso nuevo sobre el arbol $raiz: [permisos, user, clase_cargada, log] o ['hijo_sin_json' => lo que imprimio]. */
$s6EnHijo = static function (string $raiz, ?array $fila) use ($s6Hijo, $s6HijoLog): array {
    file_put_contents($s6HijoLog, '');
    $r = wa_correr([PHP_BINARY, $s6Hijo, $raiz, $s6HijoLog, base64_encode((string) json_encode($fila))]);
    $dejo = json_decode(trim($r['salida']), true);
    return is_array($dejo) ? $dejo : ['hijo_sin_json' => $r];
};
foreach ([
    "edicion 'completa'" => $s6Fila(['edicion' => 'completa']),
    "edicion '' (el vacio se trata como completa)" => $s6Fila(['edicion' => '']),
    "tipo 'integracion' con edicion 'express'" => $s6Fila(['tipo' => 'integracion']),
    'ninguna empresa' => null,
] as $s6Desc => $s6Empresa) {
    $s6R = $s6EnHijo($s6Raiz, $s6Empresa);
    wa_chk("empresa que no es Express ({$s6Desc}): permissions y user como siempre, TenantEntitlements no se carga jamas y no hay log",
        $s6R === [['*'], $s6Usuario, false, ''], $s6R);
}
$s6R = $s6EnHijo($s6Raiz, $s6Express);
$s6Esperado = [
    $s6PermisosAdminExpress,
    $s6Usuario + ['entitlements' => json_decode((string) json_encode(TenantEntitlements::bloque($s6Express)), true)],
    true,
    '',
];
wa_chk('empresa Express con la clase en su sitio: permissions cruzada con la edicion, el user con el bloque, la clase se carga (detras de la prueba en linea) y no hay log',
    $s6R === $s6Esperado, $s6R);
$s6ClaseEnCopia = $s4Copia . '/src/TenantEntitlements.php';
wa_chk('la copia del arbol de la seccion 4 sigue ahi, con authModel y la clase (de ahi salen la clase ausente, rota y sin declarar)',
    is_file($s4Copia . '/src/Models/authModel.php') && is_file($s6ClaseEnCopia), $s4Copia);
foreach ([
    'ausente' => null,
    'rota (error de sintaxis)' => "<?php\nesto no es php {{{\n",
    'sin la clase (el archivo carga pero no la declara)' => "<?php\n// archivo vacio\n",
] as $s6Modo => $s6Contenido) {
    if ($s6Contenido === null) {
        @unlink($s6ClaseEnCopia);
    } else {
        file_put_contents($s6ClaseEnCopia, $s6Contenido);
    }
    $s6R = $s6EnHijo($s4Copia, $s6Express);
    wa_chk("clase {$s6Modo}, empresa Express: el login contesta SIN el bloque y con los permisos del rol tal cual (ni lanza ni niega), la clase no carga y el intento deja UNA linea de log que la nombra",
        array_slice($s6R, 0, 3) === [['*'], $s6Usuario, false] && substr_count((string) ($s6R[3] ?? ''), "\n") === 1
        && str_contains((string) ($s6R[3] ?? ''), 'TenantEntitlements'), $s6R);
    $s6R = $s6EnHijo($s4Copia, $s6Fila(['edicion' => 'completa']));
    wa_chk("clase {$s6Modo}, empresa completa: el login sale como siempre, la clase no se pide jamas y no hay log",
        $s6R === [['*'], $s6Usuario, false, ''], $s6R);
}
// La config de la edicion que no se puede leer (revision final): permisosEfectivos y bloque la leen. Con la clase en su sitio y la config rota de cada manera (la copia de la
// seccion 4), el login sale igual que con la clase ausente: SIN el bloque y con los permisos del rol tal cual, sin lanzar (un 500) y con UNA linea de log; la peticion
// siguiente de esa empresa la niega el gancho (403, seccion 4). Una empresa que no es Express no pide la clase ni la config.
copy($s6Raiz . '/src/TenantEntitlements.php', $s6ClaseEnCopia);
foreach ([
    'rota (error de sintaxis)' => "<?php\nreturn [\n    'modulos' => [\n",
    'que lanza al cargarse' => "<?php\nthrow new RuntimeException('config roto por la prueba');\n",
    'que no devuelve un arreglo' => "<?php\n\$sin_return = 1;\n",
    'ausente' => null,
] as $s6Modo => $s6Contenido) {
    if ($s6Contenido === null) {
        @unlink($s4ConfigEnCopia);
    } else {
        file_put_contents($s4ConfigEnCopia, $s6Contenido);
    }
    $s6R = $s6EnHijo($s4Copia, $s6Express);
    wa_chk("config {$s6Modo}, empresa Express: el login contesta SIN el bloque y con los permisos del rol tal cual (ni lanza ni niega) y el intento deja UNA linea de log que nombra config/editions.php",
        array_slice($s6R, 0, 3) === [['*'], $s6Usuario, true] && substr_count((string) ($s6R[3] ?? ''), "\n") === 1
        && str_contains((string) ($s6R[3] ?? ''), 'config/editions.php'), $s6R);
    $s6R = $s6EnHijo($s4Copia, $s6Fila(['edicion' => 'completa']));
    wa_chk("config {$s6Modo}, empresa completa: el login sale como siempre, ni la clase ni la config se piden jamas y no hay log",
        $s6R === [['*'], $s6Usuario, false, ''], $s6R);
}
file_put_contents($s4ConfigEnCopia, $s4ConfigSana);
$s6R = $s6EnHijo($s4Copia, $s6Express);
wa_chk('con la config sana otra vez en la copia, el login de la empresa Express vuelve a traer el bloque y los permisos cruzados: lo que niega arriba es la config rota',
    $s6R === $s6Esperado, $s6R);
@unlink($s6Hijo);
@unlink($s6HijoLog);

// ---------------------------------------------------------------------------
// 7) estadoCertificado: el bloque certificado de GET /api/emisor (de cert_path y cert_vence, sin abrir nada)
// ---------------------------------------------------------------------------
echo "\n== 7) estadoCertificado ==\n";

/** Una fila de master.tenants de una empresa Express con certificado; $o pisa los campos que cada caso cambia. */
$s7Fila = static fn (array $o = []): array => $o + [
    'id' => 12, 'nombre' => 'Constructora X', 'rnc' => '131000001', 'tipo' => 'app', 'ambiente' => 'ecf', 'edicion' => 'express',
    'cert_path' => 'certificado_dgii/131000001/MARCA-ruta-del-p12.p12', 'cert_pass_encrypted' => 'MARCA-clave-cifrada',
    'cert_vence' => '2026-12-27',
];
$s7Claves = ['estado', 'vence', 'dias'];
$s7Hoy = strtotime('2026-10-27 12:00:00');   // en la zona por defecto de este proceso: date('Y-m-d') de esta marca es 2026-10-27 en ella

// --- la tabla del contrato: la ventana de aviso (60 dias, el limite entra), el dia del vencimiento y el dia despues ---------------
foreach ([
    ['2026-12-27', 'vigente', 61],      // 61 dias: aun fuera de la ventana de aviso
    ['2026-12-26', 'por_vencer', 60],   // 60 dias: el limite ya entra
    ['2026-10-28', 'por_vencer', 1],
    ['2026-10-27', 'por_vencer', 0],    // vence hoy: todavia no esta vencido
    ['2026-10-26', 'vencido', -1],      // vencio ayer
    ['2026-01-01', 'vencido', -299],
    ['2028-02-29', 'vigente', 490],     // un 29 de febrero valido, pasando por el cambio de ano
] as [$s7Vence, $s7Estado, $s7Dias]) {
    $s7Obtenido = TenantEntitlements::estadoCertificado($s7Fila(['cert_vence' => $s7Vence]), $s7Hoy);
    wa_chk("estadoCertificado: cert_vence {$s7Vence} con hoy 2026-10-27 es {$s7Estado}, {$s7Dias} dias",
        $s7Obtenido === ['estado' => $s7Estado, 'vence' => $s7Vence, 'dias' => $s7Dias], $s7Obtenido);
}

// --- sin certificado y sin fecha: no hay de donde contar, y la ruta vacia gana aunque haya fecha -------------------------------
$s7SinCertificado = ['estado' => 'no_configurado', 'vence' => null, 'dias' => null];
foreach ([
    "cert_path ''" => ['cert_path' => ''],
    'cert_path NULL' => ['cert_path' => null],
    'cert_path de solo espacios' => ['cert_path' => "  \t "],
] as $s7Desc => $s7Cambio) {
    $s7R = TenantEntitlements::estadoCertificado($s7Fila($s7Cambio), $s7Hoy);
    wa_chk("estadoCertificado: {$s7Desc} es no_configurado con vence y dias en null, aunque cert_vence traiga una fecha",
        $s7R === $s7SinCertificado, $s7R);
}
$s7SinColumnas = ['tipo' => 'app', 'edicion' => 'express'];   // un master sin migrar: ni cert_path ni cert_vence
wa_chk('estadoCertificado: una fila sin las columnas cert_path y cert_vence (master sin migrar) es no_configurado',
    TenantEntitlements::estadoCertificado($s7SinColumnas, $s7Hoy) === $s7SinCertificado);
$s7SinFecha = ['estado' => 'sin_dato', 'vence' => null, 'dias' => null];
foreach ([
    'cert_vence NULL' => null, "cert_vence ''" => '', 'la fecha cero de MySQL' => '0000-00-00', 'texto que no es fecha' => 'basura',
    'un 30 de febrero' => '2026-02-30', 'un 29 de febrero que no existe' => '2027-02-29', 'un mes 13' => '2026-13-01',
    'una fecha con hora' => '2026-12-27 00:00:00',
] as $s7Desc => $s7Valor) {
    $s7R = TenantEntitlements::estadoCertificado($s7Fila(['cert_vence' => $s7Valor]), $s7Hoy);
    wa_chk("estadoCertificado: con cert_path y {$s7Desc} es sin_dato, con vence y dias en null", $s7R === $s7SinFecha, $s7R);
}
wa_chk('estadoCertificado: con cert_path y sin la clave cert_vence en la fila es sin_dato',
    TenantEntitlements::estadoCertificado($s7SinColumnas + ['cert_path' => 'certificado_dgii/x.p12'], $s7Hoy) === $s7SinFecha);

// --- la forma: siempre las mismas tres claves y nunca la ruta ni la clave --------------------------------------------------------
foreach ([
    'vigente' => $s7Fila(), 'por_vencer' => $s7Fila(['cert_vence' => '2026-11-01']), 'vencido' => $s7Fila(['cert_vence' => '2026-10-01']),
    'no_configurado' => $s7Fila(['cert_path' => '']), 'sin_dato' => $s7Fila(['cert_vence' => null]),
] as $s7Esperado => $s7Empresa) {
    $s7R = TenantEntitlements::estadoCertificado($s7Empresa, $s7Hoy);
    wa_chk("estadoCertificado ({$s7Esperado}): sale con exactamente las claves estado, vence y dias, en ese orden",
        ($s7R['estado'] ?? null) === $s7Esperado && array_keys($s7R) === $s7Claves, $s7R);
}
$s7Json = (string) json_encode(TenantEntitlements::estadoCertificado($s7Fila(), $s7Hoy));
$s7Filtradas = array_values(array_filter(
    ['cert_path', 'cert_pass', 'MARCA-ruta-del-p12', 'MARCA-clave-cifrada', 'certificado_dgii', '.p12'],
    static fn (string $marca): bool => str_contains($s7Json, $marca)
));
wa_chk('estadoCertificado: el JSON no lleva el valor de cert_path ni el de cert_pass_encrypted (ni sus nombres)',
    $s7Json === '{"estado":"vigente","vence":"2026-12-27","dias":61}' && $s7Filtradas === [], [$s7Json, $s7Filtradas]);
// Pura de la fila: no abre el archivo ni descifra la clave (esta no se puede descifrar aqui) y no pregunta la edicion: ni una completa
// ni un valor desconocido cambian la respuesta, y no se escribe nada en el log (normalizarEdicion avisa de lo desconocido).
[$s7Resultado, $s7Error, $s7Log] = wa_con_log(static fn (): array => [
    TenantEntitlements::estadoCertificado($s7Fila(), $s7Hoy),
    TenantEntitlements::estadoCertificado($s7Fila(['edicion' => 'completa']), $s7Hoy),
    TenantEntitlements::estadoCertificado($s7Fila(['edicion' => 'valor-s7-desconocido']), $s7Hoy),
]);
wa_chk('estadoCertificado: no lanza con un archivo que no existe y una clave que no se descifra, y da lo mismo para una fila completa o de edicion desconocida, sin escribir al log',
    $s7Error === null && is_array($s7Resultado) && $s7Resultado[1] === $s7Resultado[0] && $s7Resultado[2] === $s7Resultado[0]
    && $s7Resultado[0] === ['estado' => 'vigente', 'vence' => '2026-12-27', 'dias' => 61] && $s7Log === '', [$s7Error?->getMessage(), $s7Resultado, $s7Log]);

// --- la ventana de aviso sale de la config -----------------------------------------------------------------------------------------
// Con otro valor en la config (no el 60 de hoy) el limite se mueve: asi se ve que estadoCertificado la lee y no la repite.
$s7Config = TenantEntitlements::config();
$s7Cache = new ReflectionProperty('TenantEntitlements', 'config');
$s7Cache->setValue(null, ['CERT_POR_VENCER_DIAS' => 10] + $s7Config);
try {
    $s7PorConfig = [
        TenantEntitlements::estadoCertificado($s7Fila(['cert_vence' => '2026-11-06']), $s7Hoy),   // a 10 dias
        TenantEntitlements::estadoCertificado($s7Fila(['cert_vence' => '2026-11-07']), $s7Hoy),   // a 11 dias
    ];
} finally {
    $s7Cache->setValue(null, $s7Config);
}
wa_chk('estadoCertificado: la ventana de aviso sale de config()[CERT_POR_VENCER_DIAS] (con 10: a 10 dias por_vencer, a 11 vigente) y la config queda como estaba',
    $s7PorConfig === [
        ['estado' => 'por_vencer', 'vence' => '2026-11-06', 'dias' => 10],
        ['estado' => 'vigente', 'vence' => '2026-11-07', 'dias' => 11],
    ] && TenantEntitlements::config() === $s7Config, $s7PorConfig);

// --- null = lo de siempre: la empresa resuelta y el reloj -------------------------------------------------------------------------
$s7Resuelta = static function (?array $tenant): void {
    (new ReflectionProperty(TenantResolver::class, 'current'))->setValue(null, $tenant);
};
try {
    $s7Resuelta($s7Fila(['cert_vence' => '2026-12-26']));
    $s7ConEmpresa = TenantEntitlements::estadoCertificado(null, $s7Hoy);
    $s7Explicita = TenantEntitlements::estadoCertificado($s7Fila(['cert_path' => '']), $s7Hoy);
    $s7Resuelta(null);
    $s7SinEmpresa = TenantEntitlements::estadoCertificado(null, $s7Hoy);
} finally {
    $s7Resuelta(null);   // no dejar una empresa resuelta a las secciones que siguen
}
wa_chk('estadoCertificado: sin fila lee la empresa resuelta (por_vencer, 60 dias), una fila explicita gana sobre ella y sin ninguna empresa es no_configurado',
    $s7ConEmpresa === ['estado' => 'por_vencer', 'vence' => '2026-12-26', 'dias' => 60] && $s7Explicita === $s7SinCertificado && $s7SinEmpresa === $s7SinCertificado,
    [$s7ConEmpresa, $s7Explicita, $s7SinEmpresa]);
// Sin $hoy cuenta desde el reloj: fechas lejos del dia de hoy, para que la medianoche no mueva el resultado.
$s7Lejos = TenantEntitlements::estadoCertificado($s7Fila(['cert_vence' => date('Y-m-d', strtotime('+400 days'))]));
$s7Pasado = TenantEntitlements::estadoCertificado($s7Fila(['cert_vence' => date('Y-m-d', strtotime('-5 days'))]));
wa_chk('estadoCertificado: sin $hoy usa la hora actual (dentro de 400 dias es vigente; hace 5 dias, vencido)',
    $s7Lejos['estado'] === 'vigente' && $s7Lejos['dias'] >= 399 && $s7Lejos['dias'] <= 401
    && $s7Pasado['estado'] === 'vencido' && $s7Pasado['dias'] >= -6 && $s7Pasado['dias'] <= -4, [$s7Lejos, $s7Pasado]);

// --- el calendario: el dia es el del reloj en la zona de PHP, no el de UTC, y un cambio de hora no mueve un dia ---------------------
// cert_vence se guarda como la fecha del notAfter del .p12 en la zona por defecto de PHP: "hoy" sale de date('Y-m-d', $hoy) en ella.
// Con gmdate($hoy), a las 23:30 de un reloj UTC-4 (Santo Domingo) saldria el dia siguiente, y a las 00:30 de uno UTC+13 (Auckland
// en octubre) el anterior: por eso cada zona se prueba a las dos horas. La zona vuelve a la de antes al terminar.
$s7Zona = date_default_timezone_get();
try {
    foreach (['UTC', 'America/Santo_Domingo', 'Pacific/Auckland'] as $s7Tz) {
        date_default_timezone_set($s7Tz);
        foreach (['23:30:00', '00:30:00'] as $s7Hora) {
            $s7Marca = strtotime("2026-10-27 {$s7Hora}");   // la hora de pared de esta zona, que es la que fijo la linea de arriba
            $s7Obtenido = [
                TenantEntitlements::estadoCertificado($s7Fila(['cert_vence' => '2026-12-26']), $s7Marca),
                TenantEntitlements::estadoCertificado($s7Fila(['cert_vence' => '2026-10-27']), $s7Marca),
            ];
            wa_chk("estadoCertificado: con la zona {$s7Tz} y hoy 2026-10-27 {$s7Hora}, el 2026-12-26 sigue siendo por_vencer, 60 y el 2026-10-27, 0",
                $s7Obtenido === [
                    ['estado' => 'por_vencer', 'vence' => '2026-12-26', 'dias' => 60],
                    ['estado' => 'por_vencer', 'vence' => '2026-10-27', 'dias' => 0],
                ], $s7Obtenido);
        }
    }
    // America/Chicago es la zona de produccion y cambia de hora el 2026-03-08 y el 2026-11-01: restar medianoches locales daria un
    // dia de menos o de mas segun el sentido. Entre las dos fechas de cada caso cae uno de los cambios.
    date_default_timezone_set('America/Chicago');
    foreach ([
        ['2026-03-01 12:00:00', '2026-03-15', 14],   // cambio de primavera (se pierde una hora) en medio
        ['2026-03-15 12:00:00', '2026-03-01', -14],
        ['2026-10-25 12:00:00', '2026-11-08', 14],   // cambio de otono (se gana una hora) en medio
        ['2026-11-08 12:00:00', '2026-10-25', -14],
    ] as [$s7Ahora, $s7Vence, $s7Dias]) {
        $s7Obtenido = TenantEntitlements::estadoCertificado($s7Fila(['cert_vence' => $s7Vence]), strtotime($s7Ahora));
        wa_chk("estadoCertificado: America/Chicago, de {$s7Ahora} al {$s7Vence} (con un cambio de hora en medio) son {$s7Dias} dias",
            ($s7Obtenido['dias'] ?? null) === $s7Dias && ($s7Obtenido['estado'] ?? null) === ($s7Dias < 0 ? 'vencido' : 'por_vencer'), $s7Obtenido);
    }
} finally {
    date_default_timezone_set($s7Zona);
}
wa_chk('la zona horaria de este proceso quedo como estaba', date_default_timezone_get() === $s7Zona, [date_default_timezone_get(), $s7Zona]);

// --- emisorController: con la clase que no carga, la clave certificado se omite (el controller real, en un hijo) ------------------
// Por HTTP esa rama no se alcanza: para una empresa Express cuya clase no carga, AuthMiddleware ya contesto 403 (seccion 4) antes de
// que corra el controller. Por eso se corre el controller de verdad, sin tocar, en un proceso aparte (ent_hijo_emisor) sobre una COPIA
// del arbol (src/ y config/, sin .env) con TenantEntitlements.php ausente o roto, y con dos dobles en la copia: AuthMiddleware (la
// sesion pasa; el real abre una base en el constructor) y EmisorConfigModel (el emisor sale de una fila fija). El repo no se toca y el
// codigo de produccion no lleva ningun interruptor de prueba. TenantResolver, TenantEntitlements y la config son los de src/.
$s7DobleAuth = <<<'PHP'
<?php
// Doble de AuthMiddleware para la prueba de emisorController: la sesion siempre es valida y no abre ninguna base.
require_once(__DIR__ . '/../TenantResolver.php');

class AuthMiddleware
{
    public function validateRequest()
    {
        return ['valid' => true, 'user_id' => 7, 'tenant_id' => 12, 'role' => 'admin', 'message' => 'Token validated'];
    }

    public function sendUnauthorized($mensaje)
    {
        http_response_code(401);
        echo json_encode(['status' => false, 'error' => $mensaje]);
        exit;
    }
}
PHP;
$s7DobleEmisor = <<<'PHP'
<?php
// Doble de EmisorConfigModel para la prueba de emisorController: el emisor sale de una fila fija, sin base de datos.
class EmisorConfigModel
{
    public function get(): ?array
    {
        return ['id' => 1, 'rnc' => '131000001', 'razon_social' => 'Constructora X', 'ambiente' => 'testecf', 'telefono' => '809-000-0000'];
    }
}
PHP;
$s7Raiz = dirname(__DIR__);
$s7Arbol = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ent_emisor_' . bin2hex(random_bytes(4));
$s7Clase = $s7Arbol . '/src/TenantEntitlements.php';
try {
    foreach (['src', 'config'] as $s7Carpeta) {
        @mkdir($s7Arbol . DIRECTORY_SEPARATOR . $s7Carpeta, 0777, true);
        $s7Recorrido = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator("{$s7Raiz}/{$s7Carpeta}", FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($s7Recorrido as $s7Item) {
            $s7Destino = $s7Arbol . DIRECTORY_SEPARATOR . $s7Carpeta . DIRECTORY_SEPARATOR . $s7Recorrido->getSubPathname();
            $s7Item->isDir() ? @mkdir($s7Destino, 0777, true) : copy($s7Item->getPathname(), $s7Destino);
        }
    }
    file_put_contents($s7Arbol . '/src/Middleware/AuthMiddleware.php', $s7DobleAuth);
    file_put_contents($s7Arbol . '/src/Models/EmisorConfigModel.php', $s7DobleEmisor);
    $s7ClaseReal = (string) file_get_contents($s7Raiz . '/src/TenantEntitlements.php');
    /** Deja en la copia el contenido dado para TenantEntitlements.php; null lo borra. */
    $s7PonerClase = static function (?string $contenido) use ($s7Clase): void {
        if ($contenido === null) {
            @unlink($s7Clase);
        } else {
            file_put_contents($s7Clase, $contenido);
        }
    };
    /**
     * GET /api/emisor contra el controller de la copia con la empresa $empresa ya resuelta (o ninguna). Devuelve la linea JSON del
     * hijo, decodificada, con 'cuerpo' (la salida decodificada), o ['hijo_sin_linea_json' => lo que imprimio] si no hubo linea.
     */
    $s7Emisor = static function (?array $empresa) use ($s7Arbol): array {
        $r = wa_correr([PHP_BINARY, __FILE__, '--hijo=emisor', '--escenario=' . base64_encode((string) json_encode(['tenant' => $empresa, 'raiz' => $s7Arbol]))]);
        $lineas = array_values(array_filter(array_map('trim', explode("\n", $r['salida'])), static fn (string $linea): bool => $linea !== ''));
        $dejo = json_decode((string) end($lineas), true);
        if (!is_array($dejo) || !array_key_exists('salida', $dejo)) {
            return ['hijo_sin_linea_json' => $r];
        }
        $dejo['cuerpo'] = json_decode((string) $dejo['salida'], true);
        return $dejo;
    };
    /** El data de una respuesta del hijo, o null si no trae. */
    $s7Datos = static fn (array $r): ?array => is_array($r['cuerpo']['data'] ?? null) ? $r['cuerpo']['data'] : null;

    wa_chk('la copia del arbol trae emisorController.php y TenantResolver.php identicos a los de src/, los dos dobles en su sitio y ningun .env',
        md5_file($s7Arbol . '/src/Controllers/emisorController.php') === md5_file($s7Raiz . '/src/Controllers/emisorController.php')
        && md5_file($s7Arbol . '/src/TenantResolver.php') === md5_file($s7Raiz . '/src/TenantResolver.php')
        && str_contains((string) file_get_contents($s7Arbol . '/src/Middleware/AuthMiddleware.php'), 'Doble de AuthMiddleware')
        && str_contains((string) file_get_contents($s7Arbol . '/src/Models/EmisorConfigModel.php'), 'Doble de EmisorConfigModel')
        && is_file($s7Clase) && is_file($s7Arbol . '/config/editions.php') && !is_file($s7Arbol . '/.env'), $s7Arbol);

    // Control: con la clase cargable, el mismo hijo SI llega al bloque. Sin esto, lo que sigue podria pasar sin haber llegado a el.
    $s7R = $s7Emisor($s7Fila(['cert_vence' => '2099-12-31']));
    $s7DatosA = $s7Datos($s7R);
    $s7CertA = is_array($s7DatosA) ? ($s7DatosA['certificado'] ?? null) : null;
    wa_chk('emisorController (control), empresa Express con la clase cargable: 200 y data.certificado al final, despues de fuente, vigente, con la fecha de la fila y dias entero',
        ($s7R['http'] ?? null) === 200 && ($s7R['cuerpo']['status'] ?? null) === true && is_array($s7DatosA)
        && array_slice(array_keys($s7DatosA), -2) === ['fuente', 'certificado'] && is_array($s7CertA) && array_keys($s7CertA) === $s7Claves
        && $s7CertA['estado'] === 'vigente' && $s7CertA['vence'] === '2099-12-31' && is_int($s7CertA['dias']) && $s7CertA['dias'] > 0
        && ($s7R['clase_cargada'] ?? null) === true && ($s7R['log'] ?? null) === '', $s7R);
    $s7SinBloque = is_array($s7DatosA) ? array_diff_key($s7DatosA, ['certificado' => true]) : null;
    wa_chk('emisorController (control): sin el bloque, data es la fila del emisor sin id, con el ambiente de la empresa (no el de la fila) y fuente emisor_config',
        $s7SinBloque === ['rnc' => '131000001', 'razon_social' => 'Constructora X', 'ambiente' => 'ecf', 'telefono' => '809-000-0000', 'fuente' => 'emisor_config'], $s7SinBloque);

    // Una empresa Express y una clase que no carga: la clave se omite y todo lo demas sale igual, con 200 y UNA linea de log.
    foreach ([
        'ausente' => null,
        'rota (error de sintaxis)' => "<?php\nesto no es php {{{\n",
        'sin declarar (el archivo carga pero no declara la clase)' => "<?php\n// archivo vacio\n",
    ] as $s7Modo => $s7Contenido) {
        $s7PonerClase($s7Contenido);
        $s7R = $s7Emisor($s7Fila(['cert_vence' => '2099-12-31']));
        wa_chk("emisorController, empresa Express con la clase {$s7Modo}: 200 (nunca un 500) con los datos del emisor de siempre, sin la clave certificado, y la clase sin cargar",
            ($s7R['http'] ?? null) === 200 && ($s7R['cuerpo']['status'] ?? null) === true && $s7SinBloque !== null
            && $s7Datos($s7R) === $s7SinBloque && ($s7R['clase_cargada'] ?? null) === false, $s7R);
        wa_chk("emisorController, la clase {$s7Modo}: deja UNA linea de log que nombra la clase",
            substr_count((string) ($s7R['log'] ?? ''), "\n") === 1 && str_contains((string) ($s7R['log'] ?? ''), 'TenantEntitlements'), $s7R['log'] ?? null);
    }

    // Todo lo que no es una empresa Express: la respuesta de siempre, y la clase (que aqui SI esta disponible) ni se carga ni deja log.
    $s7PonerClase($s7ClaseReal);
    $s7SinMigrar = ['id' => 12, 'nombre' => 'Constructora X', 'rnc' => '131000001', 'tipo' => 'app', 'ambiente' => 'ecf'];   // master sin migrar
    foreach ([
        "edicion 'completa' (con cert_path y cert_vence en su fila)" => $s7Fila(['edicion' => 'completa', 'cert_vence' => '2099-12-31']),
        "edicion 'basico' (desconocida: completa)" => $s7Fila(['edicion' => 'basico']),
        "edicion '' (vacia: completa)" => $s7Fila(['edicion' => '']),
        'edicion NULL' => $s7Fila(['edicion' => null]),
        'una fila sin la clave edicion (master sin migrar)' => $s7SinMigrar,
    ] as $s7Desc => $s7Empresa) {
        $s7R = $s7Emisor($s7Empresa);
        wa_chk("emisorController, {$s7Desc}, con la clase disponible: la respuesta de siempre sin certificado, y TenantEntitlements ni se carga",
            ($s7R['http'] ?? null) === 200 && $s7SinBloque !== null && $s7Datos($s7R) === $s7SinBloque
            && ($s7R['clase_cargada'] ?? null) === false && ($s7R['log'] ?? null) === '', $s7R);
    }
    $s7R = $s7Emisor($s7Fila(['tipo' => 'integracion']));   // la fila dice express, pero una empresa de integracion no tiene emisor_config
    wa_chk("emisorController, tipo 'integracion' (aunque la fila diga express): los datos del registro de la empresa, sin certificado y sin cargar la clase",
        ($s7R['http'] ?? null) === 200 && $s7Datos($s7R) === ['rnc' => '131000001', 'razon_social' => 'Constructora X', 'ambiente' => 'ecf', 'fuente' => 'tenant']
        && ($s7R['clase_cargada'] ?? null) === false && ($s7R['log'] ?? null) === '', $s7R);
    $s7R = $s7Emisor(null);
    wa_chk('emisorController, sin empresa resuelta: los datos del emisor tal cual (con su propio ambiente), sin certificado y sin cargar la clase',
        ($s7R['http'] ?? null) === 200
        && $s7Datos($s7R) === ['rnc' => '131000001', 'razon_social' => 'Constructora X', 'ambiente' => 'testecf', 'telefono' => '809-000-0000', 'fuente' => 'emisor_config']
        && ($s7R['clase_cargada'] ?? null) === false && ($s7R['log'] ?? null) === '', $s7R);
} finally {
    if (is_dir($s7Arbol)) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($s7Arbol, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $s7Item) {
            $s7Item->isDir() ? @rmdir($s7Item->getPathname()) : @unlink($s7Item->getPathname());
        }
        @rmdir($s7Arbol);
    }
}
wa_chk('la copia del arbol de la prueba del controller se borro al terminar', !is_dir($s7Arbol), $s7Arbol);

// ---------------------------------------------------------------------------
// 8) Biblioteca de preflight de la edicion (tools/express_edicion_lib.php): hechos, evaluacion y plan
// ---------------------------------------------------------------------------
echo "\n== 8) Biblioteca de preflight de la edicion (hechos, evaluacion y plan) ==\n";
require_once __DIR__ . '/express_edicion_lib.php';
require_once __DIR__ . '/../src/CertResolver.php';

$s8Lib = __DIR__ . '/express_edicion_lib.php';
$s8Contrato = ['express_conectar_tenant', 'express_leer_certificado', 'express_preflight_hechos', 'express_preflight_evaluar', 'express_plan'];

// --- el contrato: incluirla no imprime ni abre conexion, las cinco firmas y la fuente (sin salida, sin sintaxis de PHP 8.4) --------
// Un php limpio, para que lo que ya cargaron las secciones de arriba no tape un echo suelto ni una conexion al incluir.
$s8Hijo = (string) tempnam(sys_get_temp_dir(), 'ent_lib_');
file_put_contents($s8Hijo, <<<'PHP'
<?php
// Hijo de la seccion 8: incluye la biblioteca dentro de un buffer y cuenta lo que dejo.
error_reporting(E_ALL);
ini_set('display_errors', '1');
ob_start();
require $argv[1];
$salida = (string) ob_get_clean();
$instancia = static fn (string $clase): bool => class_exists($clase, false) && (new ReflectionProperty($clase, 'instance'))->getValue() !== null;
echo json_encode([
    'salida' => $salida,
    'database' => $instancia('Database'),
    'master' => $instancia('MasterDatabase'),
    'credenciales' => class_exists('Database', false) && Database::hasCredentials(),
    'funciones' => array_values(array_filter(get_defined_functions()['user'], static fn (string $f): bool => str_starts_with($f, 'express_'))),
]);
PHP);
$s8R = wa_correr([PHP_BINARY, $s8Hijo, $s8Lib]);
@unlink($s8Hijo);
$s8J = json_decode(trim($s8R['salida']), true);
wa_chk('incluir la biblioteca en un php limpio no imprime nada y no abre conexion (Database y MasterDatabase sin instancia, Database sin credenciales)',
    is_array($s8J) && $s8J['salida'] === '' && $s8J['database'] === false && $s8J['master'] === false && $s8J['credenciales'] === false, $s8R);
wa_chk('la biblioteca declara las cinco funciones del contrato (conectar, leer certificado, hechos, evaluar y plan)',
    is_array($s8J) && array_diff($s8Contrato, $s8J['funciones']) === [], $s8J['funciones'] ?? $s8R);

/** La firma de una funcion como texto: "nombre(tipo $param, ?tipo $otro = NULL): retorno". */
$s8Firma = static function (string $funcion): string {
    $ref = new ReflectionFunction($funcion);
    $partes = array_map(
        static fn (ReflectionParameter $p): string => ($p->hasType() ? $p->getType() . ' ' : '') . '$' . $p->getName()
            . ($p->isOptional() ? ' = ' . var_export($p->getDefaultValue(), true) : ''),
        $ref->getParameters()
    );
    return $funcion . '(' . implode(', ', $partes) . '): ' . $ref->getReturnType();
};
wa_chk('las firmas son las del contrato que consumen tenant_edicion.php y create_tenant.php',
    array_map($s8Firma, $s8Contrato) === [
        'express_conectar_tenant(array $tenant): PDO',
        'express_leer_certificado(array $tenant, ?int $ahora = NULL): array',
        'express_preflight_hechos(PDO $master, ?PDO $tenantDb, array $tenant, array $pedido): array',
        'express_preflight_evaluar(array $hechos): array',
        'express_plan(array $hechos, array $evaluacion): array',
    ], array_map($s8Firma, $s8Contrato));

$s8Fuente = (string) file_get_contents($s8Lib);
$s8Imprime = [];
foreach (token_get_all($s8Fuente) as $s8T) {
    if (is_array($s8T) && (in_array($s8T[0], [T_ECHO, T_PRINT, T_EXIT, T_INLINE_HTML, T_OPEN_TAG_WITH_ECHO], true)
        || ($s8T[0] === T_STRING && in_array(strtolower($s8T[1]), ['printf', 'vprintf', 'print_r', 'var_dump', 'var_export', 'error_log', 'fwrite', 'fputs', 'file_put_contents', 'header'], true)))) {
        $s8Imprime[] = token_name($s8T[0]) . ' ' . trim($s8T[1]) . ' (linea ' . $s8T[2] . ')';
    }
}
wa_chk('la fuente de la biblioteca no imprime ni escribe en ninguna parte (ni echo, ni exit, ni HTML suelto, ni error_log) y empieza en <?php sin BOM',
    $s8Imprime === [] && str_starts_with($s8Fuente, '<?php'), $s8Imprime);

// --- PHP 8.3: produccion y CI corren 8.3 y esta maquina 8.5, asi que un nombre o una sintaxis mas nueva pasaria aqui sin que nadie lo note ----------------
// No hay un php 8.3 en esta maquina: la guarda es estatica (el CI de 8.3 corre php -l sobre tools/ en cada push; la suite pura entra al CI en la tarea 13). Se lee el
// codigo de cada archivo sin comentarios ni textos (un nombre dentro de una cadena, como los de esta tabla, no cuenta) y se buscan las funciones y la sintaxis que
// llegaron con PHP 8.4 y 8.5. La tabla se prueba a si misma con una muestra que las trae todas y otra que las nombra solo en comentarios y cadenas.
/** El codigo de un archivo PHP sin comentarios ni textos (cadenas y heredocs): lo que queda es lo que PHP ejecuta. */
$s8SinTextos = static function (string $fuente): string {
    $codigo = '';
    foreach (token_get_all($fuente) as $s8T) {
        $codigo .= !is_array($s8T) ? $s8T : (in_array($s8T[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' '
            : (in_array($s8T[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true) ? "''" : $s8T[1]));
    }
    return $codigo;
};
$s8Patrones83 = [
    '/\b(array_find|array_find_key|array_any|array_all|mb_trim|mb_ltrim|mb_rtrim|mb_ucfirst|mb_lcfirst|bcceil|bcfloor|bcround|bcdivmod|fpow|grapheme_str_split|request_parse_body|http_get_last_response_headers|http_clear_last_response_headers)\s*\(/i' => 'funcion nueva de PHP 8.4',
    '/\b(array_first|array_last|get_error_handler|get_exception_handler)\s*\(/i' => 'funcion nueva de PHP 8.5',
    '/\bPDO::connect\s*\(|\bPdo\\\\[A-Z]\w*/' => 'PDO::connect o una subclase Pdo (PHP 8.4)',
    '/\bnew\s+[\w\\\\]+\s*\([^()]*\)\s*->/' => 'new X()->m() sin parentesis (PHP 8.4)',
    '/\b(public|protected|private)\(set\)/' => 'visibilidad asimetrica (PHP 8.4)',
    '/\{\s*(get|set)\s*(=>|\{|\()/' => 'ganchos de propiedad (PHP 8.4)',
    '/#\[\s*\\\\?(NoDiscard|Deprecated)\b/' => 'atributo NoDiscard o Deprecated (PHP 8.4 y 8.5)',
    '/\|>/' => 'operador pipe (PHP 8.5)',
    '/\(\s*void\s*\)/i' => 'cast (void) (PHP 8.5)',
];
/** Que de PHP 8.4 y 8.5 usa un codigo (ya sin comentarios ni textos): las descripciones de la tabla que encuentra. */
$s8Ocho3 = static function (string $codigo) use ($s8Patrones83): array {
    return array_values(array_filter($s8Patrones83, static fn (string $que, string $patron): bool => preg_match($patron, $codigo) === 1, ARRAY_FILTER_USE_BOTH));
};
$s8Muestra = <<<'PHP'
<?php
$a = array_find($x, fn ($v) => $v) ?? mb_trim($y);
$b = array_first($x) . array_last($x);
$c = PDO::connect('mysql:') ?? Pdo\Mysql::class;
$d = new Foo()->bar();
class K { public private(set) int $z = 1; public string $w { get => 'x'; } }
#[\NoDiscard]
function f() { return $x |> trim(...); }
$e = (void) g();
PHP;
$s8Inocente = <<<'PHP'
<?php
// array_find( array_first( PDO::connect( new Foo()->bar() |> (void) private(set) #[\NoDiscard]
/* mb_trim( get_error_handler( */
$a = 'array_find( array_last( PDO::connect( |> (void)';
$b = "mb_trim( new Foo()->bar()";
$c = str_contains($a, 'x') ? array_map('trim', [$b]) : [];
PHP;
wa_chk('la guarda de PHP 8.3 tiene dientes: encuentra cada construccion de PHP 8.4 y 8.5 de una muestra que las trae todas, y nada en una que las nombra solo en comentarios y cadenas',
    $s8Ocho3($s8SinTextos($s8Muestra)) === array_values($s8Patrones83) && $s8Ocho3($s8SinTextos($s8Inocente)) === [], [$s8Ocho3($s8SinTextos($s8Muestra)), $s8Ocho3($s8SinTextos($s8Inocente))]);
$s8Escaneados = ['la biblioteca' => $s8Lib, 'esta suite' => __FILE__, 'la suite scratch' => __DIR__ . '/test_express_scratch.php'];
foreach ($s8Escaneados as $s8Quien => $s8Archivo) {
    $s8Hallado = $s8Ocho3($s8SinTextos((string) file_get_contents($s8Archivo)));
    wa_chk("PHP 8.3 (produccion y CI): {$s8Quien} no usa funciones ni sintaxis de PHP 8.4 o 8.5 (array_find y compania, new X()->m(), visibilidad asimetrica, ganchos, atributos, |>, (void), PDO::connect)",
        $s8Hallado === [], $s8Hallado);
}
// php -l con deprecaciones al maximo: un parametro con null implicito (Tipo $x = null) avisa "Deprecated" desde PHP 8.4. Tambien las dos suites: el CI las revisa con php 8.3.
$s8Lint = wa_correr([PHP_BINARY, '-d', 'error_reporting=-1', '-d', 'display_errors=1', '-l', $s8Lib]);
wa_chk('php -l de la biblioteca: sin errores de sintaxis ni deprecaciones', $s8Lint['codigo'] === 0 && str_starts_with(trim($s8Lint['salida']), 'No syntax errors detected')
    && !str_contains($s8Lint['salida'], 'Deprecated'), $s8Lint);
foreach (['esta suite' => __FILE__, 'la suite scratch' => __DIR__ . '/test_express_scratch.php'] as $s8Quien => $s8Archivo) {
    $s8Lint = wa_correr([PHP_BINARY, '-d', 'error_reporting=-1', '-d', 'display_errors=1', '-l', $s8Archivo]);
    wa_chk("php -l de {$s8Quien}: sin errores de sintaxis ni deprecaciones", $s8Lint['codigo'] === 0 && str_starts_with(trim($s8Lint['salida']), 'No syntax errors detected')
        && !str_contains($s8Lint['salida'], 'Deprecated'), $s8Lint);
}

// --- express_ruta_absoluta: la ruta del certificado se resuelve como CertResolver::toAbsolute (que es privada) ------------------------
$s8Resuelve = new ReflectionMethod('CertResolver', 'toAbsolute');
$s8Distintas = [];
foreach (['certificado_dgii/131000001/cert.p12', 'certificado_dgii\\131000001\\cert.p12', 'cert.p12', './cert.p12', '../fuera/cert.p12',
          '/var/certs/cert.p12', 'C:\\certs\\cert.p12', 'D:/certs/cert.p12'] as $s8Ruta) {
    if (express_ruta_absoluta($s8Ruta) !== $s8Resuelve->invoke(null, $s8Ruta)) {
        $s8Distintas[$s8Ruta] = [express_ruta_absoluta($s8Ruta), $s8Resuelve->invoke(null, $s8Ruta)];
    }
}
wa_chk('express_ruta_absoluta resuelve igual que CertResolver::toAbsolute: relativa a la raiz del proyecto, y absoluta (posix o con unidad) tal cual', $s8Distintas === [], $s8Distintas);

// --- express_leer_certificado, lo que no necesita openssl: sin ruta, sin archivo, sin clave o con una clave que no descifra ---------------
$s8P12 = (string) tempnam(sys_get_temp_dir(), 'ent_p12_');
file_put_contents($s8P12, 'esto no es un p12');
$s8Falta = ['ok' => false, 'codigo' => 'CERT_FALTA', 'vence' => null];
ob_start();
[$s8Obtenido, $s8Error, $s8Log] = wa_con_log(static fn (): array => [
    'cert_path vacio' => express_leer_certificado(['cert_path' => '', 'cert_pass_encrypted' => 'MARCA-clave-cifrada']),
    'cert_path NULL' => express_leer_certificado(['cert_path' => null, 'cert_pass_encrypted' => 'MARCA-clave-cifrada']),
    'sin la clave cert_path (master sin certificado)' => express_leer_certificado(['cert_pass_encrypted' => 'MARCA-clave-cifrada']),
    'cert_path de solo espacios' => express_leer_certificado(['cert_path' => "  \t ", 'cert_pass_encrypted' => 'MARCA-clave-cifrada']),
    'un archivo que no existe' => express_leer_certificado(['cert_path' => $s8P12 . '.no-existe', 'cert_pass_encrypted' => 'MARCA-clave-cifrada']),
    'una carpeta en lugar de un archivo' => express_leer_certificado(['cert_path' => sys_get_temp_dir(), 'cert_pass_encrypted' => 'MARCA-clave-cifrada']),
    'un archivo legible sin cert_pass_encrypted' => express_leer_certificado(['cert_path' => $s8P12, 'cert_pass_encrypted' => '']),
    'un archivo legible con cert_pass_encrypted NULL' => express_leer_certificado(['cert_path' => $s8P12, 'cert_pass_encrypted' => null]),
    'un archivo legible con una clave que no se descifra' => express_leer_certificado(['cert_path' => $s8P12, 'cert_pass_encrypted' => 'MARCA-clave-cifrada']),
    'una fila vacia' => express_leer_certificado([]),
]);
$s8Salida = (string) ob_get_clean();
@unlink($s8P12);
foreach (is_array($s8Obtenido) ? $s8Obtenido : [] as $s8Desc => $s8R) {
    wa_chk("express_leer_certificado, {$s8Desc}: CERT_FALTA con ok false y vence null (sin cert_path, sin archivo legible o sin clave que lo abra)",
        $s8R === $s8Falta, $s8R);
}
wa_chk('express_leer_certificado no lanza, no imprime y no escribe en el log con ninguna de esas filas (ni el warning de un archivo que no existe)',
    $s8Error === null && is_array($s8Obtenido) && count($s8Obtenido) === 10 && $s8Salida === '' && $s8Log === '', [$s8Error?->getMessage(), $s8Salida, $s8Log]);

// --- el limite del vencimiento: la emision (ECFEmissionService::exigirCertificadoVigente) vence un certificado solo con notAfter < time() -----------------------
// express_leer_certificado sigue esa regla: a la marca exacta del notAfter todavia vale y un segundo despues no (eso se prueba con un certificado real y $ahora en la
// suite scratch, que ademas compara las dos lecturas). Aqui se lee la comparacion de la emision, porque su hora es time() y no se puede fijar: si alguien la cambia
// (<=, o un margen), esta prueba obliga a revisar con ella el preflight y TenantEntitlements::certificadoReal del plan 03.
$s8Emision = (string) file_get_contents(__DIR__ . '/../src/Utils/FacturacionElectronica/ECFEmissionService.php');
$s8Metodo = preg_match('/function exigirCertificadoVigente\b.*?\r?\n    \}\r?\n/s', $s8Emision, $s8M) === 1 ? $s8M[0] : '';
wa_chk('la emision vence un certificado solo con notAfter < time() (a la hora exacta del notAfter todavia vale), que es el limite de express_leer_certificado: si esa comparacion cambia hay que revisar las dos lecturas',
    $s8Metodo !== '' && preg_match('/is_int\(\$hasta\)\s*&&\s*\$hasta\s*<\s*time\(\)/', $s8Metodo) === 1 && preg_match('/\$hasta\s*<=|time\(\)\s*[-+]/', $s8Metodo) === 0,
    $s8Metodo === '' ? 'no se encontro exigirCertificadoVigente en ECFEmissionService' : trim(substr($s8Metodo, 0, 200)));

// --- express_conectar_tenant: lo que se niega antes de abrir nada (la conexion real se prueba en la suite scratch) -----------------
foreach ([
    "tipo 'integracion' (no tiene base propia)" => ['id' => 5, 'tipo' => 'integracion', 'db_host' => 'host-marca', 'db_name' => 'base_marca', 'db_user' => 'usuario_marca', 'db_pass_encrypted' => 'MARCA-clave-de-la-base'],
    'una fila app sin db_name' => ['id' => 5, 'tipo' => 'app', 'db_host' => 'host-marca', 'db_user' => 'usuario_marca', 'db_pass_encrypted' => 'MARCA-clave-de-la-base'],
    'una fila app sin db_host' => ['id' => 5, 'tipo' => 'app', 'db_name' => 'base_marca', 'db_user' => 'usuario_marca', 'db_pass_encrypted' => 'MARCA-clave-de-la-base'],
    'una fila app sin db_user' => ['id' => 5, 'tipo' => 'app', 'db_host' => 'host-marca', 'db_name' => 'base_marca', 'db_pass_encrypted' => 'MARCA-clave-de-la-base'],
] as $s8Desc => $s8Fila) {
    try {
        express_conectar_tenant($s8Fila);
        $s8Error = null;
    } catch (Throwable $s8Error) {
    }
    wa_chk("express_conectar_tenant, {$s8Desc}: lanza InvalidArgumentException con el id de la empresa y sin la clave ni el host ni el usuario ni la base en el texto",
        $s8Error instanceof InvalidArgumentException && str_contains($s8Error->getMessage(), '#5')
        && !preg_match('/MARCA|host-marca|base_marca|usuario_marca/', $s8Error->getMessage()), $s8Error === null ? null : get_class($s8Error) . ': ' . $s8Error->getMessage());
}

// --- evaluar: la base y la tabla de mutaciones del plan ---------------------------------------------------------------------------
// Una empresa NUEVA (sin facturas ni contadores en ecf) en testecf que lo cumple todo salvo la credencial de integracion, que el plan
// deja en blanco. Los permisos del Facturador se escriben a mano: la tabla no se deriva de la config que se prueba.
$s8Hoy = '2026-10-29';
$s8Rol = ['facturas', 'clients', 'products', 'unidades'];
// La lista de arriba es una copia a mano a proposito (la tabla no se deriva de la config que prueba), asi que aqui se ata a la config real: mismo contenido y
// mismo orden (el plan inserta un permiso por token, en el orden de config()['rol_user']). Si la config cambia, falla esta prueba y no una docena de abajo.
wa_chk('el rol Facturador escrito a mano en esta seccion es exactamente config()[rol_user], en el mismo orden (de ahi salen la base de la evaluacion y el plan escrito a mano)',
    $s8Rol === TenantEntitlements::config()['rol_user'], TenantEntitlements::config()['rol_user']);
$s8Vence = strtotime('2027-03-15 12:00:00');
$s8Base = [
    'tenant' => [
        'id' => 12, 'nombre' => 'Constructora X', 'rnc' => '131000001', 'tipo' => 'app', 'activo' => 1, 'ambiente' => 'testecf',
        'pos_enabled' => 0, 'inventario_estricto' => 0, 'api_secret_hash' => 'MARCA-hash-de-integracion',
        'cert_path' => 'certificado_dgii/131000001/MARCA-ruta-del-p12.p12', 'cert_pass_encrypted' => 'MARCA-clave-cifrada',
        'db_pass_encrypted' => 'MARCA-clave-de-la-base',
    ],
    'columnas_014' => true,
    'm036' => true,
    'cert' => ['ok' => true, 'codigo' => null, 'vence' => $s8Vence],
    'emisor' => ['rnc' => '131000001', 'razon_social' => 'CONSTRUCTORA X SRL', 'direccion' => 'Av. Winston Churchill 10', 'telefono' => '809-555-1234'],
    'almacen' => true,
    'pedido' => ['edicion' => 'express', 'tipos' => ['45'], 'modulos' => null],
    'rangos_ecf' => [],
    'ecf_facturas' => 0,
    'clientes_prueba' => ['ids' => [31, 32], 'refs_ecf' => 0, 'refs_total' => 2],
    'rol_user' => $s8Rol,
    'hoy' => $s8Hoy,
];
/** Los hechos base con los cambios dados, 'ruta.con.puntos' => valor: el valor reemplaza lo que haya (una lista entera no se mezcla con la de la base). */
$s8Con = static function (array $cambios) use ($s8Base): array {
    $h = $s8Base;
    foreach ($cambios as $ruta => $valor) {
        $ref = &$h;
        foreach (explode('.', (string) $ruta) as $clave) {
            $ref = &$ref[$clave];
        }
        $ref = $valor;
        unset($ref);
    }
    return $h;
};
$s8Ev = static fn (array $cambios = []): array => express_preflight_evaluar($s8Con($cambios));
/** El estado de un item de la evaluacion de la base con los cambios dados. */
$s8Estado = static fn (string $id, array $cambios = []): ?string => $s8Ev($cambios)['items'][$id]['estado'] ?? null;

$s8Ids = ['tenant', 'grupo', 'columnas', 'm036', 'tipos', 'modulos', 'clientes_modulo', 'cert', 'emisor', 'almacen', 'pos', 'inventario', 'rangos', 'ilimitados',
          'clientes_prueba', 'integracion', 'roles'];
$s8E0 = express_preflight_evaluar($s8Base);
wa_chk('evaluar (base): devuelve exactamente items, nuevo, rechazos y acciones, y los 17 items (los 16 de la tabla del plan y grupo, de la revision final) en ese orden, cada uno con solo estado y detalle',
    array_keys($s8E0) === ['items', 'nuevo', 'rechazos', 'acciones'] && array_keys($s8E0['items']) === $s8Ids
    && array_filter($s8E0['items'], static fn (array $i): bool => array_keys($i) !== ['estado', 'detalle']
        || !in_array($i['estado'], ['ok', 'corregir', 'rechazar'], true) || !is_string($i['detalle']) || $i['detalle'] === '') === [], $s8E0);
wa_chk('evaluar (base): una empresa nueva que lo cumple todo; solo integracion es corregir y todo lo demas ok, sin rechazos',
    $s8E0['nuevo'] === true && $s8E0['rechazos'] === [] && $s8E0['acciones'] === ['integracion']
    && array_keys(array_filter($s8E0['items'], static fn (array $i): bool => $i['estado'] !== 'ok')) === ['integracion']
    && $s8E0['items']['integracion']['estado'] === 'corregir', $s8E0);

// Cada linea es [mutacion, cambios a la base, id del item, estado esperado]; cada una es una prueba.
$s8Rangos45 = [['type' => 'E45', 'numero_hasta' => 500, 'current_value' => 0, 'fecha_vencimiento' => '2027-01-31']];
$s8Ilimitada = ['type' => 'E31', 'numero_hasta' => null, 'current_value' => 0, 'fecha_vencimiento' => null];
$s8Tabla = [
    ["tenant.tipo 'integracion'", ['tenant.tipo' => 'integracion'], 'tenant', 'rechazar'],
    ['tenant.activo 0', ['tenant.activo' => 0], 'tenant', 'rechazar'],
    // grupo (revision final): la credencial de integracion de cualquier empresa de un grupo actua por las demas (TenantResolver::switchToSibling) sin pasar por la
    // lista de rutas, los modulos ni los tipos de Express, asi que una empresa de un grupo no se convierte. Ni siquiera una nueva: sacarla del grupo es decision del dueño.
    ['grupo_id 7 (una empresa de un grupo)', ['tenant.grupo_id' => 7], 'grupo', 'rechazar'],
    ['grupo_id igual al id de la empresa (la cabeza de un grupo: create_tenant.php --grupo=self)', ['tenant.grupo_id' => 12], 'grupo', 'rechazar'],
    ["grupo_id '7' (como lo da PDO sin tipos nativos)", ['tenant.grupo_id' => '7'], 'grupo', 'rechazar'],
    ['grupo_id 0 (TenantResolver::grupoId() lo cuenta como un grupo)', ['tenant.grupo_id' => 0], 'grupo', 'rechazar'],
    ['grupo_id NULL (sin grupo: lo de siempre)', ['tenant.grupo_id' => null], 'grupo', 'ok'],
    ["grupo_id '' (sin grupo)", ['tenant.grupo_id' => ''], 'grupo', 'ok'],
    ['columnas_014 false', ['columnas_014' => false], 'columnas', 'rechazar'],
    ['m036 false', ['m036' => false], 'm036', 'rechazar'],
    ["pedido.tipos ['44']", ['pedido.tipos' => ['44']], 'tipos', 'rechazar'],
    ['pedido.tipos []', ['pedido.tipos' => []], 'tipos', 'rechazar'],
    ["pedido.tipos ['e45']", ['pedido.tipos' => ['e45']], 'tipos', 'rechazar'],
    ["pedido.modulos ['facturacion','cliente']", ['pedido.modulos' => ['facturacion', 'cliente']], 'modulos', 'rechazar'],
    ["pedido.tipos ['31'] con pedido.modulos ['facturacion']", ['pedido.tipos' => ['31'], 'pedido.modulos' => ['facturacion']], 'clientes_modulo', 'rechazar'],
    ['cert.codigo CERT_VENCIDO', ['cert' => ['ok' => false, 'codigo' => 'CERT_VENCIDO', 'vence' => $s8Vence - 86400 * 400]], 'cert', 'rechazar'],
    ['cert.codigo CERT_FALTA', ['cert' => ['ok' => false, 'codigo' => 'CERT_FALTA', 'vence' => null]], 'cert', 'rechazar'],
    ['emisor.rnc distinto del de la empresa', ['emisor.rnc' => '000000000'], 'emisor', 'rechazar'],
    ["emisor.razon_social 'PENDIENTE ONBOARDING'", ['emisor.razon_social' => 'PENDIENTE ONBOARDING'], 'emisor', 'rechazar'],
    ["emisor.direccion 'Pendiente'", ['emisor.direccion' => 'Pendiente'], 'emisor', 'rechazar'],
    ["emisor.direccion 'N/D'", ['emisor.direccion' => 'N/D'], 'emisor', 'rechazar'],
    ["emisor.telefono ''", ['emisor.telefono' => ''], 'emisor', 'rechazar'],
    ['un emisor completo (lo que create_tenant.php escribe con --razon-social, --direccion y --telefono)',
        ['emisor' => ['rnc' => '131000001', 'razon_social' => 'Constructora X SRL', 'direccion' => 'Av. X #1', 'telefono' => '809-555-1234']], 'emisor', 'ok'],
    ['almacen false', ['almacen' => false], 'almacen', 'rechazar'],
    ['pos_enabled 1 con ecf_facturas 0', ['tenant.pos_enabled' => 1], 'pos', 'corregir'],
    ['pos_enabled 1 con ecf_facturas 3', ['tenant.pos_enabled' => 1, 'ecf_facturas' => 3], 'pos', 'rechazar'],
    ['inventario_estricto 1 con ecf_facturas 0', ['tenant.inventario_estricto' => 1], 'inventario', 'corregir'],
    ['inventario_estricto 1 con ecf_facturas 3', ['tenant.inventario_estricto' => 1, 'ecf_facturas' => 3], 'inventario', 'rechazar'],
    ["ambiente 'ecf' sin un rango E34 (solo hay el E45)", ['tenant.ambiente' => 'ecf', 'rangos_ecf' => $s8Rangos45], 'rangos', 'rechazar'],
    ["ambiente 'testecf' con esos mismos rangos", ['tenant.ambiente' => 'testecf', 'rangos_ecf' => $s8Rangos45], 'rangos', 'ok'],
    ['un rango ecf sin limite con current_value 0 en una empresa nueva', ['rangos_ecf' => [$s8Ilimitada]], 'ilimitados', 'corregir'],
    ['ese mismo rango con ecf_facturas 3', ['rangos_ecf' => [$s8Ilimitada], 'ecf_facturas' => 3], 'ilimitados', 'rechazar'],
    ['ese mismo rango con current_value 5', ['rangos_ecf' => [['current_value' => 5] + $s8Ilimitada]], 'ilimitados', 'rechazar'],
    ['clientes_prueba.refs_ecf 1', ['clientes_prueba.refs_ecf' => 1], 'clientes_prueba', 'rechazar'],
    ['clientes_prueba.refs_total 0 en una empresa nueva', ['clientes_prueba.refs_total' => 0], 'clientes_prueba', 'corregir'],
    ['clientes_prueba.refs_total 2 con refs_ecf 0 en una empresa nueva', ['clientes_prueba.refs_total' => 2, 'clientes_prueba.refs_ecf' => 0], 'clientes_prueba', 'ok'],
    ['clientes_prueba.refs_total 0 con ecf_facturas 3', ['clientes_prueba.refs_total' => 0, 'ecf_facturas' => 3], 'clientes_prueba', 'ok'],
    ['rol_user null (el rol user no existe)', ['rol_user' => null], 'roles', 'rechazar'],
    ["rol_user con un permiso de mas ('users')", ['rol_user' => array_merge($s8Rol, ['users'])], 'roles', 'corregir'],
];
foreach ($s8Tabla as [$s8Mut, $s8Cambios, $s8Id, $s8Esperado]) {
    $s8H = $s8Con($s8Cambios);
    $s8E = express_preflight_evaluar($s8H);
    $s8Obtenido = $s8E['items'][$s8Id]['estado'] ?? null;
    // El estado, y que rechazos y acciones lo cuenten igual: un rechazo frena el plan, una correccion es una accion y un ok no es ninguna.
    $s8Coherente = match ($s8Esperado) {
        'rechazar' => in_array($s8Id, $s8E['rechazos'], true) && express_plan($s8H, $s8E) === [],
        'corregir' => in_array($s8Id, $s8E['acciones'], true) && !in_array($s8Id, $s8E['rechazos'], true),
        default => !in_array($s8Id, $s8E['rechazos'], true) && !in_array($s8Id, $s8E['acciones'], true),
    };
    wa_chk("{$s8Mut} -> {$s8Id}: {$s8Esperado}", $s8Obtenido === $s8Esperado && $s8Coherente, [$s8Obtenido, $s8E['rechazos'], $s8E['acciones']]);
}

// --- nuevo: ecf_facturas 0 y ningun rango ecf con current_value mayor que 0 (lo que certecf y testecf hayan hecho no cuenta) -------------
foreach ([
    'ecf_facturas 0 y ningun rango ecf' => [[], true],
    'ecf_facturas 0 y un rango ecf en 0' => [['rangos_ecf' => $s8Rangos45], true],
    'ecf_facturas 3' => [['ecf_facturas' => 3], false],
    'ecf_facturas 0 pero un rango ecf con current_value 1' => [['rangos_ecf' => [['current_value' => 1] + $s8Rangos45[0]]], false],
    'ecf_facturas null (no se pudo leer la base de la empresa)' => [['ecf_facturas' => null], false],
] as $s8Desc => [$s8Cambios, $s8Nuevo]) {
    wa_chk("nuevo: {$s8Desc} es " . ($s8Nuevo ? 'true' : 'false'), $s8Ev($s8Cambios)['nuevo'] === $s8Nuevo, $s8Ev($s8Cambios)['nuevo']);
}

// --- RF2: los typos se nombran en el detalle, y los tipos y modulos validos pasan --------------------------------------------------
$s8Tipos = $s8Ev(['pedido.tipos' => ['31', '44', '46', '41', '43', '47', 'e45', '34']])['items']['tipos'];
wa_chk('tipos: el detalle nombra cada token que no es de venta (44, 46, 41, 43, 47, e45 y 34) y no el 31, que si lo es',
    $s8Tipos['estado'] === 'rechazar' && array_filter(['44', '46', '41', '43', '47', 'e45', '34'], static fn (string $t): bool => !str_contains($s8Tipos['detalle'], "'{$t}'")) === []
    && !str_contains($s8Tipos['detalle'], "'31'"), $s8Tipos);
// Lo que el operador teclea entra a un detalle que se imprime: solo ASCII imprimible (el resto, "?") y a lo mas 24 caracteres por token.
$s8Raro = $s8Ev(['pedido.tipos' => ["4\x005\n" . str_repeat('x', 40)]])['items']['tipos']['detalle'];
wa_chk('tipos: un token con un NUL, un salto de linea y 40 letras sale en el detalle con "?" en su lugar y cortado a 24 caracteres, sin saltos de linea ni NUL',
    str_contains($s8Raro, "'4?5?" . str_repeat('x', 20) . "'") && !str_contains($s8Raro, "\n") && !str_contains($s8Raro, "\0"), $s8Raro);
wa_chk('tipos: 31, 32, 45 y 33 (el opcional) pasan, solos y todos juntos',
    $s8Estado('tipos', ['pedido.tipos' => ['31']]) === 'ok' && $s8Estado('tipos', ['pedido.tipos' => ['32']]) === 'ok'
    && $s8Estado('tipos', ['pedido.tipos' => ['45', '33']]) === 'ok' && $s8Estado('tipos', ['pedido.tipos' => ['31', '32', '45', '33']]) === 'ok');
wa_chk('tipos: el 34 solo (se deriva, no se pide) y un token con espacios (" 45") se rechazan: la lista llega ya limpia de quien la parsea',
    $s8Estado('tipos', ['pedido.tipos' => ['34']]) === 'rechazar' && $s8Estado('tipos', ['pedido.tipos' => [' 45']]) === 'rechazar');
$s8Modulos = $s8Ev(['pedido.modulos' => ['facturacion', 'cliente', 'Productos']])['items']['modulos'];
wa_chk('modulos: el detalle nombra los tokens que no estan en el preset (cliente y Productos) y no facturacion',
    $s8Modulos['estado'] === 'rechazar' && str_contains($s8Modulos['detalle'], "'cliente'") && str_contains($s8Modulos['detalle'], "'Productos'")
    && !str_contains($s8Modulos['detalle'], "'facturacion'"), $s8Modulos);
wa_chk('modulos: ausente (null), una lista vacia, un subconjunto y el preset entero en otro orden pasan',
    $s8Estado('modulos', ['pedido.modulos' => null]) === 'ok' && $s8Estado('modulos', ['pedido.modulos' => []]) === 'ok'
    && $s8Estado('modulos', ['pedido.modulos' => ['facturacion', 'clientes']]) === 'ok'
    && $s8Estado('modulos', ['pedido.modulos' => ['configuracion', 'usuarios', 'productos', 'clientes', 'facturacion']]) === 'ok');
wa_chk('clientes_modulo: el 32 no pide clientes; el 45 con clientes, y el 31 sin lista de modulos (el preset trae clientes), pasan; el 45 con solo facturacion no',
    $s8Estado('clientes_modulo', ['pedido.tipos' => ['32'], 'pedido.modulos' => ['facturacion']]) === 'ok'
    && $s8Estado('clientes_modulo', ['pedido.tipos' => ['45'], 'pedido.modulos' => ['facturacion', 'clientes']]) === 'ok'
    && $s8Estado('clientes_modulo', ['pedido.tipos' => ['31'], 'pedido.modulos' => null]) === 'ok'
    && $s8Estado('clientes_modulo', ['pedido.tipos' => ['45'], 'pedido.modulos' => ['facturacion']]) === 'rechazar');
wa_chk("clientes_modulo: un modulo mal escrito (cliente) no suma: con el 31 pedido, ['facturacion','cliente'] se rechaza",
    $s8Estado('clientes_modulo', ['pedido.tipos' => ['31'], 'pedido.modulos' => ['facturacion', 'cliente']]) === 'rechazar');

// --- cert: solo ok pasa (y un codigo con ok en true tampoco: se falla cerrado) --------------------------------------------------------
$s8CertVencido = $s8Ev(['cert' => ['ok' => false, 'codigo' => 'CERT_VENCIDO', 'vence' => strtotime('2026-10-01 12:00:00')]])['items']['cert'];
$s8CertFalta = $s8Ev(['cert' => ['ok' => false, 'codigo' => 'CERT_FALTA', 'vence' => null]])['items']['cert'];
wa_chk('cert: el detalle dice el codigo (CERT_VENCIDO con la fecha en que vencio; CERT_FALTA) y nunca la ruta del certificado',
    str_contains($s8CertVencido['detalle'], 'CERT_VENCIDO') && str_contains($s8CertVencido['detalle'], '2026-10-01')
    && str_contains($s8CertFalta['detalle'], 'CERT_FALTA') && !str_contains($s8CertVencido['detalle'] . $s8CertFalta['detalle'], 'MARCA'), [$s8CertVencido, $s8CertFalta]);
wa_chk('cert: ok en true con un codigo (dato incoherente) y una clave cert ausente o vacia se rechazan',
    $s8Estado('cert', ['cert' => ['ok' => true, 'codigo' => 'CERT_VENCIDO', 'vence' => $s8Vence]]) === 'rechazar'
    && $s8Estado('cert', ['cert' => []]) === 'rechazar' && $s8Estado('cert', ['cert' => null]) === 'rechazar');

// --- emisor: cada campo pendiente se nombra; la razon social y la direccion se comparan sin mayusculas ni espacios -----------------------
$s8Emisor = $s8Ev(['emisor' => ['rnc' => '000000000', 'razon_social' => 'PENDIENTE ONBOARDING', 'direccion' => 'Pendiente', 'telefono' => '']])['items']['emisor'];
wa_chk('emisor: con todo pendiente el detalle nombra rnc, razon_social, direccion y telefono',
    $s8Emisor['estado'] === 'rechazar' && array_filter(['rnc', 'razon_social', 'direccion', 'telefono'], static fn (string $c): bool => !str_contains($s8Emisor['detalle'], $c)) === [],
    $s8Emisor);
wa_chk('emisor: " pendiente onboarding ", "pendiente", " n/d " y "" tambien son placeholders; sin fila de emisor_config (null) y telefono NULL se rechazan',
    $s8Estado('emisor', ['emisor.razon_social' => ' pendiente onboarding ']) === 'rechazar' && $s8Estado('emisor', ['emisor.direccion' => 'pendiente']) === 'rechazar'
    && $s8Estado('emisor', ['emisor.direccion' => ' n/d ']) === 'rechazar' && $s8Estado('emisor', ['emisor.direccion' => '']) === 'rechazar'
    && $s8Estado('emisor', ['emisor' => null]) === 'rechazar' && $s8Estado('emisor', ['emisor.telefono' => null]) === 'rechazar');

// --- rangos en ecf: un rango acotado, con capacidad y sin vencer para cada tipo efectivo (el pedido mas el 34, y el 33 si se pide) ------
$s8Rango = static fn (string $tipo, array $o = []): array => $o + ['type' => $tipo, 'numero_hasta' => 500, 'current_value' => 0, 'fecha_vencimiento' => '2027-01-31'];
$s8Ecf = static fn (array $rangos, array $o = []): array => ['tenant.ambiente' => 'ecf', 'rangos_ecf' => $rangos] + $o;
foreach ([
    'E45 y E34 acotados, con capacidad y sin vencer' => [$s8Ecf([$s8Rango('E45'), $s8Rango('E34')]), 'ok'],
    'solo el E45 (falta el E34 derivado)' => [$s8Ecf([$s8Rango('E45')]), 'rechazar'],
    'solo el E34 (falta el E45 pedido)' => [$s8Ecf([$s8Rango('E34')]), 'rechazar'],
    'el E45 agotado (current_value igual a numero_hasta)' => [$s8Ecf([$s8Rango('E45', ['current_value' => 500, 'numero_hasta' => 500]), $s8Rango('E34')]), 'rechazar'],
    'el E34 vencido ayer' => [$s8Ecf([$s8Rango('E45'), $s8Rango('E34', ['fecha_vencimiento' => '2026-10-28'])]), 'rechazar'],
    'el E34 que vence hoy (no esta vencido antes de hoy)' => [$s8Ecf([$s8Rango('E45'), $s8Rango('E34', ['fecha_vencimiento' => $s8Hoy])]), 'ok'],
    'el E34 sin fecha de vencimiento' => [$s8Ecf([$s8Rango('E45'), $s8Rango('E34', ['fecha_vencimiento' => null])]), 'ok'],
    'el E45 sin limite (numero_hasta NULL no cuenta como autorizado)' => [$s8Ecf([$s8Rango('E45', ['numero_hasta' => null]), $s8Rango('E34')]), 'rechazar'],
    'un E45 agotado y otro con capacidad' => [$s8Ecf([$s8Rango('E45', ['current_value' => 500]), $s8Rango('E45', ['current_value' => 10]), $s8Rango('E34')]), 'ok'],
    'E45 y E34 pero se pide tambien el 33 sin su rango' => [$s8Ecf([$s8Rango('E45'), $s8Rango('E34')], ['pedido.tipos' => ['45', '33']]), 'rechazar'],
    'E45, E34 y E33 acotados con el 33 pedido' => [$s8Ecf([$s8Rango('E45'), $s8Rango('E34'), $s8Rango('E33')], ['pedido.tipos' => ['45', '33']]), 'ok'],
    'ningun rango ecf (el esquema solo siembra rangos sin limite)' => [$s8Ecf([]), 'rechazar'],
] as $s8Desc => [$s8Cambios, $s8Esperado]) {
    wa_chk("rangos en ecf, {$s8Desc} -> {$s8Esperado}", $s8Estado('rangos', $s8Cambios) === $s8Esperado, $s8Ev($s8Cambios)['items']['rangos']);
}
$s8Detalle = $s8Ev($s8Ecf([$s8Rango('E45')]))['items']['rangos']['detalle'];
wa_chk('rangos en ecf: el detalle nombra el tipo que falta (E34) y no el que tiene (E45)', str_contains($s8Detalle, 'E34') && !str_contains($s8Detalle, 'E45'), $s8Detalle);

// --- grupo: lo que el item entiende por "estar en un grupo" es lo que TenantResolver::grupoId() entiende, y el rechazo dice que hacer ---------------
// Si las dos se separaran, una empresa que el acceso por grupo si alcanza (switchToSibling) pasaria el preflight: solo NULL y el vacio son "sin grupo", y 0 y "0" son un grupo.
$s8Actual = new ReflectionProperty('TenantResolver', 'current');
$s8Separadas = [];
foreach ([null, '', 0, '0', 7, '7', 12, '12'] as $s8Valor) {
    $s8Actual->setValue(null, ['id' => 12, 'tipo' => 'app', 'grupo_id' => $s8Valor]);
    $s8ElResolverLoCuenta = TenantResolver::grupoId() !== null;
    $s8Actual->setValue(null, null);
    if (($s8Estado('grupo', ['tenant.grupo_id' => $s8Valor]) === 'rechazar') !== $s8ElResolverLoCuenta) {
        $s8Separadas[] = var_export($s8Valor, true);
    }
}
wa_chk('grupo: el preflight rechaza justo lo que TenantResolver::grupoId() cuenta como grupo (NULL y el vacio son sin grupo; 0, "0", 7 y 12 son un grupo)', $s8Separadas === [], $s8Separadas);
$s8Grupo = $s8Ev(['tenant.grupo_id' => 7]);
wa_chk('grupo: una empresa de un grupo se rechaza (nunca se ofrece corregirla: sacarla del grupo es del dueño), el detalle nombra el grupo #7 y dice que una empresa Express no puede estar en uno, y el informe trae [RECH] grupo:',
    $s8Grupo['items']['grupo']['estado'] === 'rechazar' && in_array('grupo', $s8Grupo['rechazos'], true) && !in_array('grupo', $s8Grupo['acciones'], true)
    && str_contains($s8Grupo['items']['grupo']['detalle'], '#7') && str_contains($s8Grupo['items']['grupo']['detalle'], 'Express') && str_contains($s8Grupo['items']['grupo']['detalle'], 'grupo')
    && preg_match('/^[\x20-\x7E]+$/D', $s8Grupo['items']['grupo']['detalle']) === 1
    && count(array_filter(express_lineas_evaluacion($s8Grupo), static fn (string $l): bool => str_starts_with($l, '[RECH] grupo: '))) === 1, $s8Grupo['items']['grupo']);
$s8DosRechazos = $s8Ev(['tenant.tipo' => 'integracion', 'tenant.grupo_id' => 7])['rechazos'];
wa_chk('grupo: sin grupo el detalle lo dice (ok), y una empresa de un grupo que ademas falla otra cosa suma sus dos rechazos, en el orden de los items (tenant y despues grupo)',
    str_contains($s8Ev()['items']['grupo']['detalle'], 'ningun grupo') && $s8DosRechazos === ['tenant', 'grupo'], $s8DosRechazos);

// --- rechazos y acciones: los ids, en el orden de los items --------------------------------------------------------------------------
$s8Varias = $s8Ev(['tenant.tipo' => 'integracion', 'm036' => false, 'cert' => ['ok' => false, 'codigo' => 'CERT_FALTA', 'vence' => null],
    'tenant.pos_enabled' => 1, 'rol_user' => array_merge($s8Rol, ['users'])]);
wa_chk('rechazos son los ids de los items rechazar y acciones los de los corregir, cada lista en el orden de los items (todos se evaluan, no se corta en el primero)',
    $s8Varias['rechazos'] === ['tenant', 'm036', 'cert'] && $s8Varias['acciones'] === ['pos', 'integracion', 'roles'], [$s8Varias['rechazos'], $s8Varias['acciones']]);

// --- evaluar es pura: no imprime, no escribe en el log, no toca los hechos y da lo mismo cada vez; y no copia nada de la fila ----------
$s8Antes = serialize($s8Base);
ob_start();
[$s8Dos, $s8Error, $s8Log] = wa_con_log(static fn (): array => [express_preflight_evaluar($s8Base), express_preflight_evaluar($s8Base)]);
$s8Salida = (string) ob_get_clean();
wa_chk('evaluar: no lanza, no imprime ni escribe en el log, no modifica los hechos y evaluar dos veces da lo mismo',
    $s8Error === null && $s8Salida === '' && $s8Log === '' && serialize($s8Base) === $s8Antes && is_array($s8Dos) && $s8Dos[0] === $s8Dos[1], [$s8Error?->getMessage(), $s8Salida, $s8Log]);
// Una clave que falte en los hechos cuenta como el peor caso: nada de avisos ni excepciones, rechaza lo que no se puede dar por bueno y el plan sale vacio.
ob_start();
[$s8Vacio, $s8Error, $s8Log] = wa_con_log(static fn (): array => [express_preflight_evaluar([]), express_plan([], express_preflight_evaluar([]))]);
$s8Salida = (string) ob_get_clean();
wa_chk('evaluar([]): sin ninguna clave rechaza tenant, columnas, m036, tipos, cert, emisor, almacen y roles, sin avisos ni excepciones, y express_plan([]) es []',
    $s8Error === null && $s8Salida === '' && $s8Log === '' && is_array($s8Vacio)
    && array_diff(['tenant', 'columnas', 'm036', 'tipos', 'cert', 'emisor', 'almacen', 'roles'], $s8Vacio[0]['rechazos']) === []
    && $s8Vacio[0]['nuevo'] === false && $s8Vacio[1] === [], [$s8Error?->getMessage(), $s8Salida, $s8Log]);
$s8Json = (string) json_encode([$s8E0, $s8Varias, $s8Emisor, $s8CertVencido, $s8CertFalta]);
// Los VALORES de la fila (la ruta del certificado, el hash y las claves cifradas) no salen; los nombres de las columnas si pueden salir en un detalle.
$s8Fugas = array_values(array_filter(
    ['MARCA-ruta-del-p12', 'certificado_dgii', 'MARCA-hash-de-integracion', 'MARCA-clave-cifrada', 'MARCA-clave-de-la-base'],
    static fn (string $marca): bool => str_contains($s8Json, $marca)
));
wa_chk('el JSON de la evaluacion no lleva el valor de cert_path ni el de api_secret_hash (ni la clave de la base ni la del certificado)', $s8Fugas === [], $s8Fugas);

// --- express_plan -------------------------------------------------------------------------------------------------------------------
// El plan de la empresa nueva de la base, escrito a mano: las dos bajas de la base de la empresa, el UPDATE de la master, y el reseteo del rol user.
$s8Insert = "INSERT INTO role_permissions (role_id, permission) SELECT id, :p FROM roles WHERE tenant_id = :id AND name = 'user'";
$s8PlanBase = [
    ['tenant', "DELETE FROM ncf_sequences WHERE ambiente = 'ecf' AND numero_hasta IS NULL", []],
    ['tenant', 'DELETE FROM clients WHERE rnc IN (:r1, :r2) AND NOT EXISTS (SELECT 1 FROM facturas f WHERE f.client_id = clients.id)', [':r1' => '131880681', ':r2' => '533445861']],
    ['master', "UPDATE tenants SET edicion = 'express', tipos_ecf_habilitados = :t, modulos_habilitados = :m, cert_vence = :v, pos_enabled = 0, inventario_estricto = 0, api_secret_hash = '' WHERE id = :id AND tipo = 'app'",
        [':t' => '45', ':m' => null, ':v' => date('Y-m-d', $s8Vence), ':id' => 12]],
    ['master', "DELETE FROM role_permissions WHERE role_id IN (SELECT id FROM roles WHERE tenant_id = :id AND name = 'user')", [':id' => 12]],
    ['master', $s8Insert, [':p' => 'facturas', ':id' => 12]],
    ['master', $s8Insert, [':p' => 'clients', ':id' => 12]],
    ['master', $s8Insert, [':p' => 'products', ':id' => 12]],
    ['master', $s8Insert, [':p' => 'unidades', ':id' => 12]],
];
/** Un plan como lista de [donde, sql, params]: lo que se compara con la tabla escrita a mano (la descripcion `que` se prueba aparte). */
$s8Resumen = static fn (array $plan): array => array_map(static fn (array $p): array => [$p['donde'], $p['sql'], $p['params']], $plan);
$s8Plan = express_plan($s8Base, $s8E0);
wa_chk('express_plan (empresa nueva): en orden, baja de los rangos ecf sin limite y de los clientes de prueba sin facturas (en la base de la empresa), el UPDATE de tenants y el reseteo del rol user (en la master)',
    $s8Resumen($s8Plan) === $s8PlanBase, $s8Resumen($s8Plan));
wa_chk('express_plan: cada paso trae exactamente donde, que, sql y params, con la descripcion en texto, y los de la base de la empresa van antes que los de la master',
    array_filter($s8Plan, static fn (array $p): bool => array_keys($p) !== ['donde', 'que', 'sql', 'params'] || !is_string($p['que']) || trim($p['que']) === ''
        || !in_array($p['donde'], ['tenant', 'master'], true)) === []
    && array_column($s8Plan, 'donde') === ['tenant', 'tenant', 'master', 'master', 'master', 'master', 'master', 'master'], array_column($s8Plan, 'donde'));
$s8Param = static fn (array $plan, int $i, string $k): mixed => array_key_exists($k, $plan[$i]['params'] ?? []) ? $plan[$i]['params'][$k] : '(sin ese parametro)';
wa_chk('express_plan: un INSERT por cada token del rol Facturador (cuatro) y todo SQL con sus valores en parametros, ninguno pegado al texto',
    count(array_filter($s8Plan, static fn (array $p): bool => str_starts_with($p['sql'], 'INSERT INTO role_permissions'))) === count($s8Rol)
    && array_filter($s8Plan, static fn (array $p): bool => preg_match('/(131880681|533445861|\b12\b|MARCA|2027)/', $p['sql']) === 1) === [], array_column($s8Plan, 'sql'));
/** El plan de la base con los cambios dados, armado con la evaluacion de esos mismos hechos. */
$s8PlanDe = static function (array $cambios) use ($s8Con): array {
    $hechos = $s8Con($cambios);
    return express_plan($hechos, express_preflight_evaluar($hechos));
};
$s8Modulos2 = $s8PlanDe(['pedido.tipos' => ['31', '45'], 'pedido.modulos' => ['facturacion', 'clientes']]);
wa_chk('express_plan: tipos_ecf_habilitados y modulos_habilitados salen del pedido, separados por coma (y NULL cuando no se angosta el preset: sin lista o con una vacia)',
    $s8Param($s8Modulos2, 2, ':t') === '31,45' && $s8Param($s8Modulos2, 2, ':m') === 'facturacion,clientes' && $s8Param($s8Plan, 2, ':m') === null
    && $s8Param($s8PlanDe(['pedido.modulos' => []]), 2, ':m') === null, [$s8Modulos2[2]['params'] ?? null]);
wa_chk('express_plan: un token repetido en el pedido se escribe una sola vez y se respeta el orden en que se pidio (45,31,45 queda 45,31)',
    $s8Param($s8PlanDe(['pedido.tipos' => ['45', '31', '45']]), 2, ':t') === '45,31');
// --- cert_vence: la fecha que escribe el plan es la que lee estadoCertificado (el dia del notAfter, en la zona horaria por defecto de PHP) --------------------
// La columna es DATE (migracion master 014, solo informativa). El lector (TenantEntitlements::estadoCertificado, el bloque certificado de GET /api/emisor) la toma como
// "el dia del notAfter en la zona por defecto de PHP" y cuenta los dias contra date('Y-m-d') de esa misma zona; el plan escribe date('Y-m-d', notAfter) en esa zona.
// Se prueba en zonas lejos de UTC y con un notAfter cuyo dia local NO es el de UTC (un gmdate, o una zona fija, lo delataria): lo que escribe el plan, leido a la hora
// del notAfter, da 0 dias (el dia del vencimiento todavia vale, como en la emision), 10 dias diez dias antes y vencido un dia despues. La zona de la suite se restaura.
$s8Zona0 = date_default_timezone_get();
$s8Ciclo = [];
$s8Contrastes = 0;
try {
    foreach (['UTC', 'America/Chicago', 'America/Santo_Domingo', 'Pacific/Honolulu', 'Pacific/Kiritimati'] as $s8Zona) {
        date_default_timezone_set($s8Zona);
        foreach (['23:30:00', '00:30:00'] as $s8Hora) {
            $s8Fin = new DateTimeImmutable("2027-03-15 {$s8Hora}", new DateTimeZone($s8Zona));
            $s8V = $s8Param($s8PlanDe(['cert' => ['ok' => true, 'codigo' => null, 'vence' => $s8Fin->getTimestamp()]]), 2, ':v');
            $s8Lee = static fn (DateTimeImmutable $hoy): array => TenantEntitlements::estadoCertificado(
                ['cert_path' => 'certificado_dgii/131000001/cert.p12', 'cert_vence' => $s8V],
                $hoy->getTimestamp()
            );
            $s8Contrastes += (gmdate('Y-m-d', $s8Fin->getTimestamp()) !== '2027-03-15') ? 1 : 0;   // en cuantas la fecha de UTC y la local difieren
            foreach ([
                'a la hora del notAfter' => [$s8Lee($s8Fin), ['estado' => 'por_vencer', 'vence' => '2027-03-15', 'dias' => 0]],
                'diez dias antes' => [$s8Lee($s8Fin->modify('-10 days')), ['estado' => 'por_vencer', 'vence' => '2027-03-15', 'dias' => 10]],
                'un dia despues' => [$s8Lee($s8Fin->modify('+1 day')), ['estado' => 'vencido', 'vence' => '2027-03-15', 'dias' => -1]],
            ] as $s8Cuando => [$s8Leido, $s8Debe]) {
                if ($s8V !== '2027-03-15' || $s8Leido !== $s8Debe) {
                    $s8Ciclo[] = "{$s8Zona} {$s8Hora}, {$s8Cuando}: " . json_encode([$s8V, $s8Leido]);
                }
            }
        }
    }
} finally {
    date_default_timezone_set($s8Zona0);
}
wa_chk('cert_vence: el dia que escribe el plan (el del notAfter en la zona de PHP) es el que lee estadoCertificado en cinco zonas, con un notAfter a las 23:30 y a las 00:30 locales: 0 dias a la hora del notAfter, 10 diez dias antes y vencido un dia despues',
    $s8Ciclo === [] && date_default_timezone_get() === $s8Zona0, $s8Ciclo);
wa_chk('cert_vence: la prueba de las zonas tiene dientes (en cuatro de las diez horas elegidas el dia de UTC no es el local) y el plan escribe una fecha DATE (Y-m-d) o NULL, nunca una hora',
    $s8Contrastes >= 4 && preg_match('/^\d{4}-\d{2}-\d{2}$/D', (string) $s8Param($s8Plan, 2, ':v')) === 1
    && $s8Param($s8PlanDe(['cert' => ['ok' => true, 'codigo' => null, 'vence' => null]]), 2, ':v') === null, $s8Contrastes);

// --- la baja de clientes de prueba no puede chocar con una llave foranea: ninguna tabla de la base de la empresa apunta a clients --------------------------------
// Se lee del esquema y de las migraciones de tenant (db/tenant_schema.sql y db/migrations/*.sql), con las dos formas de escribir una FK: directa y armada con CONCAT dentro
// de un PREPARE (REFERENCES `', @db, '`.tabla). La suite scratch lo comprueba ademas en information_schema de la base de A y corre las sentencias del plan contra ella.
$s8ApuntaAClients = static function (string $sql): bool {
    $sql = (string) preg_replace(['~/\*.*?\*/~s', '~^\s*--.*$~m'], ' ', $sql);   // sin comentarios
    $sql = (string) preg_replace('~[`\'",]|@db\b~', ' ', $sql);   // sin comillas, comas ni @db: REFERENCES `', @db, '`.clients (id) queda como REFERENCES   .clients (id)
    return preg_match('~\bREFERENCES[\s.]*clients\b~i', $sql) === 1;
};
wa_chk('el detector de llaves foraneas hacia clients ve la forma directa, la que lleva comillas y la armada con CONCAT, y no se confunde con otras tablas, con comentarios ni con client_id sin FK',
    $s8ApuntaAClients('FOREIGN KEY (client_id) REFERENCES clients (id)')
    && $s8ApuntaAClients('FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE SET NULL')
    && $s8ApuntaAClients("'FOREIGN KEY (client_id) ', 'REFERENCES `', @db, '`.clients (id) ON DELETE SET NULL'")
    && !$s8ApuntaAClients('FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE SET NULL')
    && !$s8ApuntaAClients("-- REFERENCES clients (id)\nFOREIGN KEY (a) REFERENCES conduces (id)")
    && !$s8ApuntaAClients('/* REFERENCES clients */ FOREIGN KEY (a) REFERENCES cotizaciones (id)')
    && !$s8ApuntaAClients("client_id INT(11) NULL COMMENT 'el cliente se puede borrar y client_name guarda su nombre'"));
$s8SqlTenant = array_merge([__DIR__ . '/../db/tenant_schema.sql'], glob(__DIR__ . '/../db/migrations/*.sql') ?: []);
$s8ConFk = array_values(array_map('basename', array_filter($s8SqlTenant, static fn (string $f): bool => $s8ApuntaAClients((string) file_get_contents($f)))));
wa_chk('ninguna tabla de la base de la empresa (esquema y migraciones de tenant) declara una llave foranea hacia clients: el DELETE de clientes de prueba del plan no puede chocar con una (cotizaciones, conduces y facturas guardan client_id sin FK)',
    count($s8SqlTenant) > 20 && $s8ConFk === [], $s8ConFk);

$s8Rechazo = $s8Con(['m036' => false]);
wa_chk('express_plan: es [] cuando la evaluacion trae algun rechazo, aunque lo demas se pueda corregir',
    express_plan($s8Rechazo, express_preflight_evaluar($s8Rechazo)) === [] && express_plan($s8Base, ['items' => [], 'nuevo' => true, 'rechazos' => ['tipos'], 'acciones' => []]) === []);
// Una empresa que ya emitio en ecf (no nueva) y que no trae nada que rechazar: el plan no borra rangos ni clientes (eso es de la tarea 14).
$s8Vieja = $s8Con(['ecf_facturas' => 3]);
$s8PlanVieja = express_plan($s8Vieja, express_preflight_evaluar($s8Vieja));
wa_chk('express_plan (empresa que ya emitio en ecf, sin rechazos): ni baja de rangos ni baja de clientes; solo el UPDATE y el reseteo del rol, en la master',
    $s8PlanVieja !== [] && $s8Resumen($s8PlanVieja) === array_slice($s8PlanBase, 2) && array_unique(array_column($s8PlanVieja, 'donde')) === ['master'], $s8Resumen($s8PlanVieja));
$s8Fugas = array_values(array_filter(
    ['MARCA-ruta-del-p12', 'certificado_dgii', 'MARCA-hash-de-integracion', 'MARCA-clave-cifrada', 'MARCA-clave-de-la-base'],
    static fn (string $marca): bool => str_contains((string) json_encode($s8Plan), $marca)
));
wa_chk('express_plan no lleva el valor de cert_path ni el de api_secret_hash (ni las claves cifradas) en ningun paso', $s8Fugas === [], $s8Fugas);
ob_start();
[$s8Dos, $s8Error, $s8Log] = wa_con_log(static fn (): array => [express_plan($s8Base, $s8E0), express_plan($s8Base, $s8E0)]);
$s8Salida = (string) ob_get_clean();
wa_chk('express_plan: no lanza, no imprime ni escribe en el log y dos llamadas dan el mismo plan', $s8Error === null && $s8Salida === '' && $s8Log === '' && is_array($s8Dos) && $s8Dos[0] === $s8Dos[1]);

// --- express_preflight_hechos contra dobles de PDO: solo SELECT, la forma de los hechos y que sin base de la empresa todo lo suyo falla -----
// ExpPdoMaster (seccion 6) anota cada consulta que llega a execute() y entrega las filas que le da la funcion de respuesta; una consulta que
// la prueba no espera lanza, asi que la biblioteca no puede pedir nada que esta tabla no conozca.
/** Reune los hechos de la empresa de la base contra dobles; $o cambia lo que contestan. @return array{0:array,1:list<array>,2:list<array>} hechos, consultas de la master y de la base */
$s8Reunir = static function (array $o = [], bool $conBase = true) use ($s8Base): array {
    $master = new ExpPdoMaster();
    $master->respuesta = static function (string $sql, array $params) use ($o): array {
        if (str_contains($sql, 'information_schema.COLUMNS')) {
            return [['n' => (string) ($o['columnas'] ?? 4)]];
        }
        if (str_contains($sql, 'FROM roles')) {
            return ($o['rol'] ?? true) === false ? [] : [['id' => '5']];
        }
        if (str_contains($sql, 'FROM role_permissions')) {
            return array_map(static fn (string $p): array => ['permission' => $p], $o['permisos'] ?? ['clients', 'facturas', 'products', 'unidades']);
        }
        throw new RuntimeException('consulta inesperada en la master: ' . $sql);
    };
    $base = null;
    if ($conBase) {
        $base = new ExpPdoMaster();
        $base->respuesta = static function (string $sql, array $params) use ($o): array {
            if (str_contains($sql, 'information_schema.COLUMNS')) {
                return [['n' => (string) ($o['m036'] ?? 1)]];
            }
            if (str_contains($sql, 'FROM emisor_config')) {
                return [['rnc' => '131000001', 'razon_social' => 'CONSTRUCTORA X SRL', 'direccion' => 'Av. X #1', 'telefono' => null]];
            }
            if (str_contains($sql, 'FROM warehouses')) {
                return ($o['almacen'] ?? true) === false ? [] : [['id' => '1']];
            }
            if (str_contains($sql, 'FROM ncf_sequences')) {
                return $o['rangos'] ?? [
                    ['type' => 'E31', 'numero_hasta' => null, 'current_value' => '0', 'fecha_vencimiento' => null],
                    ['type' => 'E45', 'numero_hasta' => '500', 'current_value' => '7', 'fecha_vencimiento' => '2027-01-31'],
                ];
            }
            if (str_contains($sql, 'COUNT(DISTINCT')) {
                return [['n' => (string) (str_contains($sql, "ambiente_dgii = 'ecf'") ? ($o['refs_ecf'] ?? 0) : ($o['refs_total'] ?? 2))]];
            }
            if (str_contains($sql, 'FROM facturas')) {
                return [['n' => (string) ($o['ecf_facturas'] ?? 0)]];
            }
            if (str_contains($sql, 'FROM clients')) {
                return array_map(static fn ($id): array => ['id' => (string) $id], $o['clientes'] ?? [31, 32]);
            }
            throw new RuntimeException('consulta inesperada en la base de la empresa: ' . $sql);
        };
    }
    $hechos = express_preflight_hechos($master, $base, $s8Base['tenant'], $s8Base['pedido']);
    return [$hechos, $master->consultas, $base?->consultas ?? []];
};
$s8Antes = date('Y-m-d');
ob_start();
[$s8Resultado, $s8Error, $s8Log] = wa_con_log(static fn (): array => $s8Reunir());
$s8Salida = (string) ob_get_clean();
[$s8Hechos, $s8CMaster, $s8CBase] = is_array($s8Resultado) ? $s8Resultado : [[], [], []];
$s8Despues = date('Y-m-d');
wa_chk('hechos (dobles): no lanza, no imprime ni escribe en el log; las claves son las del contrato y en ese orden',
    $s8Error === null && $s8Salida === '' && $s8Log === '' && array_keys($s8Hechos) === ['tenant', 'columnas_014', 'm036', 'cert', 'emisor', 'almacen', 'pedido',
        'rangos_ecf', 'ecf_facturas', 'clientes_prueba', 'rol_user', 'hoy'], [$s8Error?->getMessage(), $s8Salida, $s8Log, array_keys($s8Hechos)]);
$s8Todas = array_merge($s8CMaster, $s8CBase);
wa_chk('hechos (dobles): todo lo que pide es SELECT (ni una sentencia de escritura), y la master y la base de la empresa reciben cada una las suyas',
    $s8Todas !== [] && array_filter($s8Todas, static fn (array $c): bool => preg_match('/^\s*SELECT\b/i', $c['sql']) !== 1) === []
    && array_filter($s8CMaster, static fn (array $c): bool => preg_match('/FROM (emisor_config|warehouses|ncf_sequences|facturas|clients)\b/', $c['sql']) === 1) === []
    && array_filter($s8CBase, static fn (array $c): bool => preg_match('/FROM (roles|role_permissions)\b|TABLE_NAME = \'tenants\'/', $c['sql']) === 1) === [], array_column($s8Todas, 'sql'));
wa_chk('hechos (dobles): tenant y pedido son los recibidos, columnas_014 sale de contar cuatro columnas, m036 de que exista la columna, hoy es la fecha de hoy',
    ($s8Hechos['tenant'] ?? null) === $s8Base['tenant'] && ($s8Hechos['pedido'] ?? null) === $s8Base['pedido'] && ($s8Hechos['columnas_014'] ?? null) === true
    && ($s8Hechos['m036'] ?? null) === true && in_array($s8Hechos['hoy'] ?? null, [$s8Antes, $s8Despues], true), $s8Hechos);
wa_chk('hechos (dobles): el emisor sale con sus cuatro campos como texto (NULL es ""), el almacen como bool, y el certificado de la fila (un archivo que no existe) es CERT_FALTA',
    ($s8Hechos['emisor'] ?? null) === ['rnc' => '131000001', 'razon_social' => 'CONSTRUCTORA X SRL', 'direccion' => 'Av. X #1', 'telefono' => '']
    && ($s8Hechos['almacen'] ?? null) === true && ($s8Hechos['cert'] ?? null) === ['ok' => false, 'codigo' => 'CERT_FALTA', 'vence' => null],
    [$s8Hechos['emisor'] ?? null, $s8Hechos['almacen'] ?? null, $s8Hechos['cert'] ?? null]);
wa_chk('hechos (dobles): los rangos ecf salen con numero_hasta y current_value enteros (o null) y la fecha como texto, y ecf_facturas es un entero',
    ($s8Hechos['rangos_ecf'] ?? null) === [
        ['type' => 'E31', 'numero_hasta' => null, 'current_value' => 0, 'fecha_vencimiento' => null],
        ['type' => 'E45', 'numero_hasta' => 500, 'current_value' => 7, 'fecha_vencimiento' => '2027-01-31'],
    ] && ($s8Hechos['ecf_facturas'] ?? null) === 0, [$s8Hechos['rangos_ecf'] ?? null, $s8Hechos['ecf_facturas'] ?? null]);
wa_chk('hechos (dobles): clientes_prueba trae los ids de los clientes de prueba, y cuantos estan en una factura ecf y en una de cualquier ambiente (enteros)',
    ($s8Hechos['clientes_prueba'] ?? null) === ['ids' => [31, 32], 'refs_ecf' => 0, 'refs_total' => 2], $s8Hechos['clientes_prueba'] ?? null);
wa_chk('hechos (dobles): rol_user es la lista de permisos del rol user de ESA empresa (la consulta lleva su id)',
    ($s8Hechos['rol_user'] ?? null) === ['clients', 'facturas', 'products', 'unidades']
    && array_filter($s8CMaster, static fn (array $c): bool => str_contains($c['sql'], 'FROM roles') && in_array(12, array_map('intval', array_values($c['params'])), true)) !== [], $s8Hechos['rol_user'] ?? null);
[$s8H2] = $s8Reunir(['columnas' => 3, 'm036' => 0, 'almacen' => false, 'rol' => false, 'ecf_facturas' => 3, 'refs_ecf' => 1, 'refs_total' => 1, 'clientes' => [31]]);
wa_chk('hechos (dobles): con tres columnas es columnas_014 false, sin la columna es m036 false, sin almacen false, sin rol user es null, y los conteos salen tal cual',
    $s8H2['columnas_014'] === false && $s8H2['m036'] === false && $s8H2['almacen'] === false && $s8H2['rol_user'] === null && $s8H2['ecf_facturas'] === 3
    && $s8H2['clientes_prueba'] === ['ids' => [31], 'refs_ecf' => 1, 'refs_total' => 1], $s8H2);
[$s8H3, , $s8C3] = $s8Reunir(['clientes' => []]);
wa_chk('hechos (dobles): sin clientes de prueba sembrados no pregunta por sus facturas y trae ids [] con cero referencias',
    $s8H3['clientes_prueba'] === ['ids' => [], 'refs_ecf' => 0, 'refs_total' => 0]
    && array_filter($s8C3, static fn (array $c): bool => str_contains($c['sql'], 'COUNT(DISTINCT')) === [], $s8H3['clientes_prueba']);
[$s8H4, $s8C4m, $s8C4b] = $s8Reunir([], false);
$s8E4 = express_preflight_evaluar($s8H4);
wa_chk('hechos (sin base de la empresa, null): nada se pregunta a la base; m036, almacen y emisor fallan, ecf_facturas es null (nunca "nueva") y la evaluacion rechaza con plan []',
    $s8C4b === [] && $s8C4m !== [] && $s8H4['m036'] === false && $s8H4['almacen'] === false && $s8H4['emisor'] === null && $s8H4['rangos_ecf'] === []
    && $s8H4['ecf_facturas'] === null && $s8E4['nuevo'] === false
    && array_diff(['m036', 'almacen', 'emisor'], $s8E4['rechazos']) === [] && express_plan($s8H4, $s8E4) === [], [$s8H4, $s8E4['rechazos']]);
// Los hechos que salen de la base se evaluan igual que los de mano: la empresa de los dobles (telefono NULL, rango E31 sin limite) tiene que salir rechazada por el emisor.
$s8E2 = express_preflight_evaluar($s8Hechos);
wa_chk('hechos (dobles) -> evaluar: el telefono NULL rechaza el emisor y, con el rango E45 en current_value 7, la empresa ya no es nueva, asi que el rango E31 sin limite se rechaza (no se borra)',
    $s8E2['items']['emisor']['estado'] === 'rechazar' && $s8E2['nuevo'] === false && $s8E2['items']['ilimitados']['estado'] === 'rechazar'
    && $s8E2['items']['pos']['estado'] === 'ok' && $s8E2['items']['inventario']['estado'] === 'ok', $s8E2['items']);

// ---------------------------------------------------------------------------
// 9) tools/tenant_edicion.php: aplicar, auditar, convertir, revertir y refrescar (la biblioteca y la herramienta de linea de comandos)
// ---------------------------------------------------------------------------
echo "\n== 9) tenant_edicion.php: aplicar, auditar, convertir, revertir y refrescar ==\n";

/**
 * El registro que comparten los dos PDO de mentira de un guion (el de la base de la empresa y el de la master): cada llamada que les llega,
 * en el orden en que ocurre, como [quien, tipo, sql, parametros]. tipo es begin, commit, rollback o execute; sql y parametros solo van en execute.
 */
final class ExpGuionLog
{
    /** @var list<array{0:string,1:string,2:string,3:array<string,mixed>}> */
    public array $eventos = [];
}

/**
 * PDO de mentira de la seccion 9 para express_aplicar y las funciones de la biblioteca que escriben. Sabe de transacciones (anota begin, commit y
 * rollback en el registro y se acuerda de si hay una abierta), anota cada execute y entrega las filas que le da `respuesta`. Lo que no implementa
 * (query, exec, quote...) lanza "PDO object is uninitialized": la biblioteca solo puede usar prepare, execute, las tres llamadas de la
 * transaccion, inTransaction y lastInsertId. No llama al constructor del padre, asi que no abre nada.
 */
final class ExpPdoGuion extends PDO
{
    public bool $enTransaccion = false;
    /** @var ?Closure(string,array<string,mixed>,ExpPdoGuion):list<array<string,mixed>> las filas de cada consulta: recibe el sql, los parametros y este PDO */
    public ?Closure $respuesta = null;
    /** @var list<array{0:string,1:Throwable}> errores por consumir: el primero cuyo fragmento de SQL calce lanza en su execute */
    public array $errores = [];
    /** @var list<array{sql:string,params:array<string,mixed>}> las sentencias que no son SELECT, en orden */
    public array $escritas = [];
    public string|false $idInsertado = '41';
    /** Un commit que devuelve false (PDO en modo silencioso) y deja la transaccion abierta. */
    public bool $commitOk = true;
    public ?Throwable $commitLanza = null;
    public ?Throwable $rollBackLanza = null;

    public function __construct(public string $quien, public ExpGuionLog $log)
    {
    }

    public function beginTransaction(): bool
    {
        $this->log->eventos[] = [$this->quien, 'begin', '', []];
        $this->enTransaccion = true;
        return true;
    }

    public function commit(): bool
    {
        $this->log->eventos[] = [$this->quien, 'commit', '', []];
        if ($this->commitLanza !== null) {
            throw $this->commitLanza;
        }
        $this->enTransaccion = !$this->commitOk;
        return $this->commitOk;
    }

    public function rollBack(): bool
    {
        $this->log->eventos[] = [$this->quien, 'rollback', '', []];
        if ($this->rollBackLanza !== null) {
            throw $this->rollBackLanza;
        }
        $this->enTransaccion = false;
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->enTransaccion;
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return $this->idInsertado;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $sentencia = (new ReflectionClass(ExpSentenciaGuion::class))->newInstanceWithoutConstructor();
        $sentencia->origen = $this;
        $sentencia->sql = $query;
        return $sentencia;
    }
}

/** La sentencia de ExpPdoGuion: execute() anota la llamada, lanza el error del guion que calce y pide las filas; fetch() y fetchAll() las entregan. */
final class ExpSentenciaGuion extends PDOStatement
{
    public ExpPdoGuion $origen;
    public string $sql = '';
    /** @var list<array<string,mixed>> */
    private array $filas = [];

    public function execute(?array $params = null): bool
    {
        $params ??= [];
        $this->origen->log->eventos[] = [$this->origen->quien, 'execute', $this->sql, $params];
        if (preg_match('/^\s*SELECT\b/i', $this->sql) !== 1) {
            $this->origen->escritas[] = ['sql' => $this->sql, 'params' => $params];
        }
        foreach ($this->origen->errores as $i => [$fragmento, $error]) {
            if (str_contains($this->sql, $fragmento)) {
                unset($this->origen->errores[$i]);
                throw $error;
            }
        }
        $this->filas = $this->origen->respuesta === null ? [] : ($this->origen->respuesta)($this->sql, $params, $this->origen);
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return $this->filas[0] ?? false;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->filas;
    }
}

// --- express_hijo_aislado: el hijo ve la master apuntando a un puerto sin nadie, y este proceso recupera su entorno -----------------------------
$s9Nombres = ['MASTER_DB_HOST', 'MASTER_DB_PORT', 'MASTER_DB_USER', 'MASTER_DB_PASS', 'ADMIN_DB_USER', 'ADMIN_DB_PASS'];
$s9Sonda = (string) tempnam(sys_get_temp_dir(), 'ent_aislado_');
file_put_contents($s9Sonda, <<<'PHP'
<?php
// Hijo de la seccion 9: lo que ve de la master y del administrador de MySQL por $_ENV, que es lo unico que lee MasterDatabase.
$nombres = ['MASTER_DB_HOST', 'MASTER_DB_PORT', 'MASTER_DB_USER', 'MASTER_DB_PASS', 'ADMIN_DB_USER', 'ADMIN_DB_PASS'];
echo json_encode(array_combine($nombres, array_map(static fn (string $n): ?string => $_ENV[$n] ?? null, $nombres)));
PHP);
$s9EntornoAntes = [];
foreach ($s9Nombres as $s9N) {
    $s9EntornoAntes[$s9N] = getenv($s9N);   // false si no estaba puesta
}
try {
    putenv('MASTER_DB_HOST=marca-del-padre');   // el padre tiene una master en su entorno: el hijo no la ve
    putenv('MASTER_DB_PORT');                   // y un puerto sin poner: tampoco queda puesto al terminar
    $s9R = express_hijo_aislado([$s9Sonda]);
    $s9Despues = [getenv('MASTER_DB_HOST'), getenv('MASTER_DB_PORT')];
} finally {
    foreach ($s9EntornoAntes as $s9N => $s9V) {
        putenv($s9V === false ? $s9N : "{$s9N}={$s9V}");
    }
    @unlink($s9Sonda);
}
$s9Ve = json_decode(trim($s9R['salida']), true);
wa_chk('express_hijo_aislado: el hijo ve la master en 127.0.0.1 puerto 1 (loopback, sin nadie) y usuarios y claves de mentira para la master y el administrador, por $_ENV',
    $s9R['codigo'] === 0 && is_array($s9Ve) && $s9Ve['MASTER_DB_HOST'] === '127.0.0.1' && $s9Ve['MASTER_DB_PORT'] === '1'
    && array_filter(['MASTER_DB_USER', 'MASTER_DB_PASS', 'ADMIN_DB_USER', 'ADMIN_DB_PASS'], static fn (string $n): bool => !is_string($s9Ve[$n] ?? null) || $s9Ve[$n] === '') === [], $s9R);
wa_chk('express_hijo_aislado: al terminar este proceso recupera su entorno (la master que tenia puesta y el puerto que no tenia)',
    $s9Despues === ['marca-del-padre', false], $s9Despues);
wa_chk('express_hijo_aislado: la raiz del API de esta suite no trae un .env (si lo trae, express_hijo_aislado se niega con [ALTO] y salida 2)',
    pos_scratch_revisar_raiz(dirname(__DIR__)) === '', pos_scratch_revisar_raiz(dirname(__DIR__)));
wa_chk('express_hijo_aislado(array $args): array{codigo, salida} es la firma del contrato de las tareas 9 y 10',
    $s8Firma('express_hijo_aislado') === 'express_hijo_aislado(array $args): array', $s8Firma('express_hijo_aislado'));

// --- los dobles valen en PHP 8.3 (produccion y CI), como los de la seccion 6: sus firmas, contra copias de las de 8.3 en un php aparte ----------
// Misma tecnica que la seccion 6 ($s6TextoClase y $s6Propios son de ahi): las copias de ExpPdoGuion y ExpSentenciaGuion heredan de copias de
// usuario de las firmas que PDO y PDOStatement tienen en 8.3, y el motor les aplica las reglas de herencia sea cual sea el PHP que corre la suite.
// Cada control cambia UNA firma y tiene que ser rechazado: sin ellos, un cambio de nombres que no se aplicara dejaria la prueba aceptando todo.
$s9Firmas83 = <<<'PHP'
class Firma83Pdo9
{
    public function __construct(string $dsn, ?string $username = null, #[\SensitiveParameter] ?string $password = null, ?array $options = null) {}
    public function prepare(string $query, array $options = []): PDOStatement|false {}
    public function beginTransaction(): bool {}
    public function commit(): bool {}
    public function rollBack(): bool {}
    public function inTransaction(): bool {}
    public function lastInsertId(?string $name = null): string|false {}
}
class Firma83PdoStatement9
{
    public function execute(?array $params = null): bool {}
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed {}
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array {}
}
PHP;
$s9Copias = strtr($s6TextoClase('ExpPdoGuion') . "\n" . $s6TextoClase('ExpSentenciaGuion'), [
    'ExpPdoGuion' => 'Firma83PdoGuion', 'ExpSentenciaGuion' => 'Firma83SentenciaGuion',
    'extends PDOStatement' => 'extends Firma83PdoStatement9', 'extends PDO' => 'extends Firma83Pdo9',
]);
/** Declara las copias de los dobles (con $cambio, un 'texto' => 'reemplazo' que altera una firma) en un php nuevo: [codigo de salida, lo que imprimio]. */
$s9Declarar = static function (array $cambio = []) use ($s9Firmas83, $s9Copias): array {
    $archivo = (string) tempnam(sys_get_temp_dir(), 'ent_firmas9_');
    file_put_contents($archivo, "<?php\n{$s9Firmas83}\n" . strtr($s9Copias, $cambio) . "\necho 'ACEPTADO';\n");
    $r = wa_correr([PHP_BINARY, '-d', 'display_errors=1', '-d', 'error_reporting=-1', $archivo]);
    @unlink($archivo);
    return [$r['codigo'], $r['salida']];
};
wa_chk('dobles de la seccion 9: las copias heredan de las firmas de 8.3 de arriba y de ninguna clase del PHP que corre la suite (el cambio de nombres se aplico)',
    preg_match_all('/extends Firma83Pdo9\b/', $s9Copias) === 1 && preg_match_all('/extends Firma83PdoStatement9\b/', $s9Copias) === 1
    && !preg_match('/extends\s+PDO/', $s9Copias), $s9Copias);
[$s9Cod, $s9Sal] = $s9Declarar();
wa_chk('dobles de la seccion 9: sobre las firmas de PDO y PDOStatement de PHP 8.3 (prepare, las tres de la transaccion, inTransaction, lastInsertId, execute, fetch y fetchAll) el motor los acepta',
    $s9Cod === 0 && str_contains($s9Sal, 'ACEPTADO') && !str_contains($s9Sal, 'Fatal error'), [$s9Cod, $s9Sal]);
foreach ([
    'beginTransaction con otro retorno (int en lugar de bool)' => ['public function beginTransaction(): bool' => 'public function beginTransaction(): int'],
    'inTransaction con otro retorno (string en lugar de bool)' => ['public function inTransaction(): bool' => 'public function inTransaction(): string'],
    'lastInsertId con un parametro mas estrecho (string en lugar de ?string)' => ['lastInsertId(?string $name = null): string|false' => 'lastInsertId(string $name = ""): string|false'],
    'execute con un parametro mas estrecho (array en lugar de ?array)' => ['execute(?array $params = null): bool' => 'execute(array $params = []): bool'],
    'fetchAll con un retorno mas ancho (iterable en lugar de array)' => ['mixed ...$args): array' => 'mixed ...$args): iterable'],
] as $s9Desc => $s9Cambio) {
    [$s9Cod, $s9Sal] = $s9Declarar($s9Cambio);
    wa_chk("control, {$s9Desc}: el motor lo rechaza (Declaration ... must be compatible con la firma de 8.3)",
        $s9Cod !== 0 && str_contains($s9Sal, 'must be compatible with Firma83'), [$s9Cod, $s9Sal]);
}
wa_chk('...y los dobles solo sobreescriben metodos cuya firma de 8.3 esta en la copia de arriba (agregar otro obliga a sumar su firma)',
    $s6Propios('ExpPdoGuion') === ['beginTransaction', 'commit', 'inTransaction', 'lastInsertId', 'prepare', 'rollBack']
    && $s6Propios('ExpSentenciaGuion') === ['execute', 'fetch', 'fetchAll'], [$s6Propios('ExpPdoGuion'), $s6Propios('ExpSentenciaGuion')]);

// --- express_aplicar: tenant primero, UNA transaccion en la master con sus pasos y la auditoria, y la lectura de despues del commit ---------------
// El plan es el real de la empresa nueva de la seccion 8 (dos pasos de la base de la empresa y seis de la master). La auditoria se arma a mano: lo
// que se prueba aqui es express_aplicar, no el armado de la fila.
$s9Estado0 = ['edicion' => 'completa', 'tipos_ecf_habilitados' => null, 'modulos_habilitados' => null, 'cert_vence' => null, 'pos_enabled' => 1, 'inventario_estricto' => 0];
$s9Estado1 = ['edicion' => 'express', 'tipos_ecf_habilitados' => '45', 'modulos_habilitados' => null, 'cert_vence' => date('Y-m-d', $s8Vence), 'pos_enabled' => 0, 'inventario_estricto' => 0];
$s9Auditoria = [
    'tenant_id' => 12,
    'old_values' => $s9Estado0 + ['integracion_hash_anterior' => 'MARCA-hash-anterior'],
    'new_values' => $s9Estado1,
    'description' => 'Prueba de la seccion 9',
];
$s9Columnas4 = ['edicion', 'tipos_ecf_habilitados', 'modulos_habilitados', 'cert_vence'];
/**
 * La fila de audit_logs que dejo el ultimo INSERT de auditoria que recibio $master (con $cambio encima), tal como la leeria la lectura de despues
 * del commit; [] (ninguna fila) si no hubo INSERT.
 * @return list<array<string,mixed>>
 */
$s9LeeAuditoria = static function (ExpPdoGuion $master, array $cambio = []): array {
    $insert = null;
    foreach ($master->escritas as $escrita) {
        if (str_contains($escrita['sql'], 'INSERT INTO audit_logs')) {
            $insert = $escrita['params'];
        }
    }
    return $insert === null ? [] : [array_replace([
        'id' => '41', 'tenant_id' => (string) $insert[':tenant_id'], 'module' => $insert[':module'], 'action' => $insert[':action'],
        'new_values' => $insert[':new_values'],
    ], $cambio)];
};
/**
 * Un par de PDO de mentira con su registro compartido: [la base de la empresa, la master, el registro]. La master contesta las dos lecturas de
 * despues del commit: la fila de tenants ($filaLeida, o la que dejo el plan) y la de audit_logs (la que escribio el INSERT de auditoria, con
 * $cambioAuditoria encima; con $auditoriaPresente en false, ninguna).
 * @return array{0:ExpPdoGuion,1:ExpPdoGuion,2:ExpGuionLog}
 */
$s9Par = static function (?array $filaLeida = null, bool $auditoriaPresente = true, array $cambioAuditoria = []) use ($s9Estado1, $s9Columnas4, $s9LeeAuditoria): array {
    $log = new ExpGuionLog();
    $empresa = new ExpPdoGuion('tenant', $log);
    $master = new ExpPdoGuion('master', $log);
    $master->respuesta = static function (string $sql, array $params, ExpPdoGuion $yo) use ($filaLeida, $auditoriaPresente, $cambioAuditoria, $s9Estado1, $s9Columnas4, $s9LeeAuditoria): array {
        if (str_contains($sql, 'FROM tenants')) {
            return [$filaLeida ?? array_intersect_key($s9Estado1, array_flip($s9Columnas4))];
        }
        if (str_contains($sql, 'FROM audit_logs')) {
            return $auditoriaPresente ? $s9LeeAuditoria($yo, $cambioAuditoria) : [];
        }
        return [];
    };
    return [$empresa, $master, $log];
};
/** Los eventos de un registro como 'quien:tipo', en orden. */
$s9Eventos = static fn (ExpGuionLog $log): array => array_map(static fn (array $e): string => $e[0] . ':' . $e[1], $log->eventos);
/** Los execute de $quien en el registro: [quien, 'execute', sql, parametros]. */
$s9Ejecutadas = static fn (ExpGuionLog $log, string $quien): array => array_values(array_filter($log->eventos, static fn (array $e): bool => $e[0] === $quien && $e[1] === 'execute'));
/** [sql, parametros] de cada paso del plan de $donde, en orden. */
$s9PasosDe = static fn (array $plan, string $donde): array => array_map(
    static fn (array $p): array => [$p['sql'], $p['params']],
    array_values(array_filter($plan, static fn (array $p): bool => $p['donde'] === $donde))
);
/** Corre express_aplicar atrapando todo: [excepcion o null, lo que escribio en el log, lo que imprimio]. */
$s9Corre = static function (ExpPdoGuion $master, ?ExpPdoGuion $empresa, array $plan, array $auditoria): array {
    ob_start();
    [, $error, $log] = wa_con_log(static fn () => express_aplicar($master, $empresa, $plan, $auditoria));
    return [$error, $log, (string) ob_get_clean()];
};

[$s9T, $s9M, $s9Log] = $s9Par();
$s9ArchivoLog = (string) tempnam(sys_get_temp_dir(), 'ent_aplicar_');
$s9LogPrevio = ini_set('error_log', $s9ArchivoLog);
ob_start();
try {
    // La primera llamada va sin atrapar a proposito: si la funcion no existe, el Error corta la suite (exit 255) y no queda una fila de "FALLO" por prueba.
    express_aplicar($s9M, $s9T, $s8Plan, $s9Auditoria);
} finally {
    $s9Salida = (string) ob_get_clean();
    ini_set('error_log', $s9LogPrevio === false ? '' : $s9LogPrevio);
    $s9LogTexto = (string) file_get_contents($s9ArchivoLog);
    @unlink($s9ArchivoLog);
}
$s9Esperado = array_merge(['tenant:execute', 'tenant:execute', 'master:begin'], array_fill(0, 7, 'master:execute'), ['master:commit', 'master:execute', 'master:execute']);
wa_chk('express_aplicar: primero los dos pasos de la base de la empresa, despues UNA transaccion en la master (begin, los seis pasos y el INSERT de auditoria, commit) y recien despues las dos lecturas',
    $s9Eventos($s9Log) === $s9Esperado, $s9Eventos($s9Log));
wa_chk('express_aplicar: la base de la empresa recibe exactamente sus pasos del plan (sql y parametros, en orden) y la master los suyos y despues el INSERT de auditoria',
    array_map(static fn (array $e): array => [$e[2], $e[3]], $s9Ejecutadas($s9Log, 'tenant')) === $s9PasosDe($s8Plan, 'tenant')
    && array_map(static fn (array $e): array => [$e[2], $e[3]], array_slice($s9Ejecutadas($s9Log, 'master'), 0, 6)) === $s9PasosDe($s8Plan, 'master')
    && str_starts_with(trim($s9Ejecutadas($s9Log, 'master')[6][2]), 'INSERT INTO audit_logs'), array_column($s9Ejecutadas($s9Log, 'master'), 2));
wa_chk('express_aplicar: la base de la empresa nunca abre transaccion ni recibe un commit (sus pasos son idempotentes y cada uno vale solo) y no hay rollback',
    array_filter($s9Log->eventos, static fn (array $e): bool => $e[0] === 'tenant' && $e[1] !== 'execute') === []
    && !in_array('master:rollback', $s9Eventos($s9Log), true), $s9Eventos($s9Log));
$s9Insert = $s9Ejecutadas($s9Log, 'master')[6];
$s9InsertParams = $s9Insert[3];
wa_chk('express_aplicar: la fila de auditoria lleva module edicion, action EDICION_CAMBIO, entity_type tenant, el id de la empresa, username tools/tenant_edicion.php, success 1 y las dos listas de valores como JSON',
    ($s9InsertParams[':tenant_id'] ?? null) === 12 && ($s9InsertParams[':entity_id'] ?? null) === '12' && ($s9InsertParams[':module'] ?? null) === 'edicion'
    && ($s9InsertParams[':action'] ?? null) === 'EDICION_CAMBIO' && ($s9InsertParams[':entity_type'] ?? null) === 'tenant'
    && ($s9InsertParams[':username'] ?? null) === 'tools/tenant_edicion.php' && ($s9InsertParams[':success'] ?? null) === 1
    && ($s9InsertParams[':description'] ?? null) === 'Prueba de la seccion 9'
    && json_decode((string) ($s9InsertParams[':old_values'] ?? ''), true) === $s9Auditoria['old_values']
    && json_decode((string) ($s9InsertParams[':new_values'] ?? ''), true) === $s9Auditoria['new_values'], $s9InsertParams);
wa_chk('express_aplicar: el INSERT de auditoria nombra sus diez columnas y lleva todos los valores en parametros (ninguno pegado al texto del SQL)',
    preg_match('/INSERT INTO audit_logs \(tenant_id, username, module, entity_type, entity_id, action, old_values, new_values, description, success\)/', $s9Insert[2]) === 1
    && !preg_match('/MARCA|tools\/tenant_edicion|EDICION_CAMBIO|express/', $s9Insert[2]), $s9Insert[2]);
wa_chk('express_aplicar: no imprime nada ni escribe en el log cuando todo sale bien', $s9Salida === '' && $s9LogTexto === '', [$s9Salida, $s9LogTexto]);
wa_chk('express_aplicar: tras el commit relee tenants (sus cuatro columnas, por el id de la empresa) y la fila de auditoria (por el id que dio el INSERT)',
    count($s9Ejecutadas($s9Log, 'master')) === 9
    && str_contains($s9Ejecutadas($s9Log, 'master')[7][2], 'FROM tenants') && ($s9Ejecutadas($s9Log, 'master')[7][3][':id'] ?? null) === 12
    && str_contains($s9Ejecutadas($s9Log, 'master')[8][2], 'FROM audit_logs') && ($s9Ejecutadas($s9Log, 'master')[8][3][':id'] ?? null) === 41,
    array_map(static fn (array $e): array => [$e[2], $e[3]], array_slice($s9Ejecutadas($s9Log, 'master'), 7)));

$s9Resumen = static function (ExpPdoGuion $m, ExpGuionLog $log) use ($s9Eventos): array {
    return ['eventos' => $s9Eventos($log), 'abierta' => $m->enTransaccion];
};
// Cuando algo falla dentro de la transaccion: rollBack, jamas commit, y se relanza la misma excepcion.
foreach ([
    'el INSERT de auditoria lanza' => ['INSERT INTO audit_logs', array_merge(['tenant:execute', 'tenant:execute', 'master:begin'], array_fill(0, 7, 'master:execute'), ['master:rollback'])],
    'el UPDATE de tenants lanza (el INSERT de auditoria ni se intenta)' => ["UPDATE tenants SET edicion = 'express'", array_merge(['tenant:execute', 'tenant:execute', 'master:begin', 'master:execute', 'master:rollback'])],
    'el primer INSERT del rol user lanza (ya corrieron el UPDATE y el DELETE de sus permisos)' => ['INSERT INTO role_permissions', array_merge(['tenant:execute', 'tenant:execute', 'master:begin'], array_fill(0, 3, 'master:execute'), ['master:rollback'])],
] as $s9Desc => [$s9Fragmento, $s9EventosEsperados]) {
    [$s9T, $s9M, $s9Log] = $s9Par();
    $s9Cae = new PDOException("SQLSTATE[HY000]: General error: simulacro ({$s9Desc})");
    $s9M->errores[] = [$s9Fragmento, $s9Cae];
    [$s9Error, $s9LogErr, $s9Salida] = $s9Corre($s9M, $s9T, $s8Plan, $s9Auditoria);
    wa_chk("express_aplicar, {$s9Desc}: hace rollBack, nunca commit, relanza la misma excepcion y no lee nada despues",
        $s9Error === $s9Cae && $s9Eventos($s9Log) === $s9EventosEsperados && !in_array('master:commit', $s9Eventos($s9Log), true) && !$s9M->enTransaccion,
        [$s9Error?->getMessage(), $s9Resumen($s9M, $s9Log)]);
    wa_chk("express_aplicar, {$s9Desc}: no imprime nada ni escribe en el log", $s9Salida === '' && $s9LogErr === '', [$s9Salida, $s9LogErr]);
}
[$s9T, $s9M, $s9Log] = $s9Par();
$s9Cae = new PDOException('SQLSTATE[HY000]: General error: el INSERT de auditoria');
$s9M->errores[] = ['INSERT INTO audit_logs', $s9Cae];
$s9M->rollBackLanza = new RuntimeException('el rollback tambien falla');
[$s9Error] = $s9Corre($s9M, $s9T, $s8Plan, $s9Auditoria);
wa_chk('express_aplicar: si el rollBack tambien lanza, la excepcion que sale es la original (la que explica el fallo), no la del rollback', $s9Error === $s9Cae, $s9Error?->getMessage());
[$s9T, $s9M, $s9Log] = $s9Par();
$s9M->commitLanza = new PDOException('SQLSTATE[HY000]: General error: se cayo la conexion al confirmar');
[$s9Error] = $s9Corre($s9M, $s9T, $s8Plan, $s9Auditoria);
wa_chk('express_aplicar: un commit que lanza (la conexion se cae al confirmar) deja la transaccion abierta en el PDO: se intenta rollBack y se relanza esa excepcion, sin lecturas',
    $s9Error === $s9M->commitLanza && in_array('master:rollback', $s9Eventos($s9Log), true) && !in_array('master:execute', array_slice($s9Eventos($s9Log), -1), true), $s9Eventos($s9Log));
[$s9T, $s9M, $s9Log] = $s9Par();
$s9M->commitOk = false;
[$s9Error] = $s9Corre($s9M, $s9T, $s8Plan, $s9Auditoria);
wa_chk('express_aplicar: un commit que devuelve false (PDO en modo silencioso) es un fallo: RuntimeException, rollBack y sin lecturas posteriores',
    $s9Error instanceof RuntimeException && in_array('master:rollback', $s9Eventos($s9Log), true) && !$s9M->enTransaccion
    && array_values(array_filter($s9Eventos($s9Log), static fn (string $e): bool => $e === 'master:execute')) === array_fill(0, 7, 'master:execute'), $s9Eventos($s9Log));

// Un paso de la base de la empresa que falla: la master ni se toca (los pasos que ya corrieron se quedan: son idempotentes).
[$s9T, $s9M, $s9Log] = $s9Par();
$s9Cae = new PDOException('SQLSTATE[HY000]: General error: el DELETE de clientes de prueba');
$s9T->errores[] = ['DELETE FROM clients', $s9Cae];
[$s9Error, $s9LogErr, $s9Salida] = $s9Corre($s9M, $s9T, $s8Plan, $s9Auditoria);
wa_chk('express_aplicar: si un paso de la base de la empresa falla, relanza esa excepcion y la master no recibe nada (ni begin): el primer DELETE ya corrio y se queda',
    $s9Error === $s9Cae && $s9Eventos($s9Log) === ['tenant:execute', 'tenant:execute'] && $s9Salida === '' && $s9LogErr === '', [$s9Error?->getMessage(), $s9Eventos($s9Log)]);

// La lectura de despues del commit contra el plan: lo que difiere se nombra, y el cambio ya esta confirmado (no hay rollback posible).
$s9Mala = static fn (string $columna, mixed $valor): array => array_replace(array_intersect_key($s9Estado1, array_flip($s9Columnas4)), [$columna => $valor]);
foreach ([
    'edicion' => ['edicion', 'completa'], 'tipos_ecf_habilitados' => ['tipos_ecf_habilitados', '31'],
    'modulos_habilitados' => ['modulos_habilitados', 'facturacion'], 'cert_vence' => ['cert_vence', '2030-01-01'],
    'modulos_habilitados vacio en lugar de NULL (se compara estricto)' => ['modulos_habilitados', ''],
] as $s9Desc => [$s9Columna, $s9Valor]) {
    [$s9T, $s9M, $s9Log] = $s9Par($s9Mala($s9Columna, $s9Valor));
    [$s9Error, $s9LogErr, $s9Salida] = $s9Corre($s9M, $s9T, $s8Plan, $s9Auditoria);
    wa_chk("express_aplicar, la fila releida trae otra {$s9Desc}: RuntimeException que nombra la columna y dice que el cambio ya se confirmo, despues del commit y sin rollback",
        $s9Error instanceof RuntimeException && str_contains($s9Error->getMessage(), $s9Columna) && str_contains($s9Error->getMessage(), 'confirm')
        && array_search('master:commit', $s9Eventos($s9Log), true) !== false && !in_array('master:rollback', $s9Eventos($s9Log), true)
        && $s9Salida === '' && $s9LogErr === '', [$s9Error?->getMessage(), $s9Eventos($s9Log)]);
}
[$s9T, $s9M, $s9Log] = $s9Par($s9Mala('edicion', 'completa'));
$s9Mensaje = $s9Corre($s9M, $s9T, $s8Plan, $s9Auditoria)[0]?->getMessage() ?? '';
wa_chk('express_aplicar: el mensaje de la diferencia dice lo esperado y lo leido de esa columna y no lleva el hash anterior ni nada de la fila fuera de las cuatro columnas',
    str_contains($s9Mensaje, 'express') && str_contains($s9Mensaje, 'completa') && !str_contains($s9Mensaje, 'MARCA'), $s9Mensaje);
[$s9T, $s9M, $s9Log] = $s9Par(null, false);
[$s9Error] = $s9Corre($s9M, $s9T, $s8Plan, $s9Auditoria);
wa_chk('express_aplicar: si la fila de auditoria no se encuentra al releerla, lanza RuntimeException (confirmado el cambio, falta su registro)',
    $s9Error instanceof RuntimeException && str_contains($s9Error->getMessage(), 'audit'), $s9Error?->getMessage());
[$s9T, $s9M, $s9Log] = $s9Par(null, true, ['new_values' => json_encode(array_replace($s9Estado1, ['edicion' => 'completa']))]);
[$s9Error] = $s9Corre($s9M, $s9T, $s8Plan, $s9Auditoria);
wa_chk('express_aplicar: si el new_values de la fila de auditoria releida difiere del plan (otra edicion), lanza RuntimeException',
    $s9Error instanceof RuntimeException && str_contains($s9Error->getMessage(), 'audit'), $s9Error?->getMessage());
[$s9T, $s9M, $s9Log] = $s9Par(null, true, ['action' => 'OTRA_ACCION']);
[$s9Error] = $s9Corre($s9M, $s9T, $s8Plan, $s9Auditoria);
wa_chk('express_aplicar: si la fila de auditoria releida no es una EDICION_CAMBIO, lanza RuntimeException', $s9Error instanceof RuntimeException, $s9Error?->getMessage());
foreach (['cero' => '0', 'false' => false] as $s9Desc => $s9Id) {
    [$s9T, $s9M, $s9Log] = $s9Par();
    $s9M->idInsertado = $s9Id;
    [$s9Error] = $s9Corre($s9M, $s9T, $s8Plan, $s9Auditoria);
    wa_chk("express_aplicar: si lastInsertId da {$s9Desc} (el INSERT de auditoria no dio un id que releer), lanza RuntimeException ANTES de confirmar: rollBack y ningun commit, para que lo confirmado siempre tenga su fila de auditoria verificable",
        $s9Error instanceof RuntimeException && str_contains($s9Error->getMessage(), 'audit') && in_array('master:rollback', $s9Eventos($s9Log), true)
        && !in_array('master:commit', $s9Eventos($s9Log), true) && !$s9M->enTransaccion, [$s9Error?->getMessage(), $s9Eventos($s9Log)]);
}
[$s9T, $s9M, $s9Log] = $s9Par();
$s9M->errores[] = ['SELECT edicion', new PDOException('SQLSTATE[HY000]: General error: se cayo la conexion al releer')];
[$s9Error] = $s9Corre($s9M, $s9T, $s8Plan, $s9Auditoria);
wa_chk('express_aplicar: si la lectura de despues del commit falla, lanza RuntimeException que dice que el cambio SI se confirmo y trae la causa como previa',
    $s9Error instanceof RuntimeException && str_contains($s9Error->getMessage(), 'confirm') && $s9Error->getPrevious() instanceof PDOException, $s9Error?->getMessage());

// Sin conexion a la base de la empresa solo vale un plan que no la necesita; y un plan o una auditoria mal armados se rechazan antes de tocar nada.
[$s9T, $s9M, $s9Log] = $s9Par();
[$s9Error] = $s9Corre($s9M, null, $s8Plan, $s9Auditoria);
wa_chk('express_aplicar: un plan con pasos de la base de la empresa y sin conexion a ella lanza InvalidArgumentException antes de tocar nada (ni begin en la master)',
    $s9Error instanceof InvalidArgumentException && $s9Log->eventos === [], [$s9Error?->getMessage(), $s9Log->eventos]);
[$s9T, $s9M, $s9Log] = $s9Par();
[$s9Error] = $s9Corre($s9M, null, array_slice($s8Plan, 2), $s9Auditoria);
wa_chk('express_aplicar: un plan solo de la master (revertir, refrescar, o convertir una empresa que ya emitio) no necesita conexion a la base de la empresa y sigue la misma secuencia',
    $s9Error === null && $s9Eventos($s9Log) === array_merge(['master:begin'], array_fill(0, 7, 'master:execute'), ['master:commit', 'master:execute', 'master:execute']), [$s9Error?->getMessage(), $s9Eventos($s9Log)]);
foreach ([
    'un paso con donde desconocido' => [array_merge([['donde' => 'otra', 'que' => 'x', 'sql' => 'SELECT 1', 'params' => []]], $s8Plan), $s9Auditoria],
    'un paso sin sql' => [array_merge($s8Plan, [['donde' => 'master', 'que' => 'x', 'sql' => '', 'params' => []]]), $s9Auditoria],
    'un paso sin params' => [array_merge($s8Plan, [['donde' => 'master', 'que' => 'x', 'sql' => 'SELECT 1']]), $s9Auditoria],
    'una auditoria sin tenant_id' => [$s8Plan, array_diff_key($s9Auditoria, ['tenant_id' => true])],
    'una auditoria con tenant_id 0' => [$s8Plan, ['tenant_id' => 0] + $s9Auditoria],
    'una auditoria cuyo new_values no trae cert_vence' => [$s8Plan, ['new_values' => array_diff_key($s9Estado1, ['cert_vence' => true])] + $s9Auditoria],
    'una auditoria sin new_values' => [$s8Plan, array_diff_key($s9Auditoria, ['new_values' => true])],
] as $s9Desc => [$s9Plan, $s9Aud]) {
    [$s9T, $s9M, $s9Log] = $s9Par();
    [$s9Error] = $s9Corre($s9M, $s9T, $s9Plan, $s9Aud);
    wa_chk("express_aplicar, {$s9Desc}: InvalidArgumentException antes de tocar ninguna base (ni un execute, ni begin)",
        $s9Error instanceof InvalidArgumentException && $s9Log->eventos === [], [$s9Error === null ? null : get_class($s9Error) . ': ' . $s9Error->getMessage(), $s9Log->eventos]);
}
wa_chk('express_aplicar(PDO $master, ?PDO $tenantDb, array $plan, array $auditoria): void es la firma del contrato',
    $s8Firma('express_aplicar') === 'express_aplicar(PDO $master, ?PDO $tenantDb, array $plan, array $auditoria): void', $s8Firma('express_aplicar'));
wa_chk('las otras tres funciones del contrato tienen las firmas del plan: express_convertir, express_revertir y express_refrescar_cert',
    array_map($s8Firma, ['express_convertir', 'express_revertir', 'express_refrescar_cert']) === [
        'express_convertir(PDO $master, int $tenantId, array $pedido, bool $apply): array',
        'express_revertir(PDO $master, int $tenantId, bool $apply): array',
        'express_refrescar_cert(PDO $master, int $tenantId, bool $apply): array',
    ], array_map($s8Firma, ['express_convertir', 'express_revertir', 'express_refrescar_cert']));

// --- el estado que guarda la bitacora, la fila de auditoria y las lineas del informe: funciones puras de la biblioteca --------------------------
$s9Fila = [
    'id' => 12, 'nombre' => 'Constructora X', 'rnc' => '131000001', 'tipo' => 'app', 'activo' => 1, 'ambiente' => 'testecf',
    'edicion' => 'completa', 'tipos_ecf_habilitados' => null, 'modulos_habilitados' => null, 'cert_vence' => null,
    'pos_enabled' => '1', 'inventario_estricto' => '0', 'api_secret_hash' => str_repeat('ab', 32),
    'cert_path' => 'certificado_dgii/131000001/MARCA-ruta-del-p12.p12', 'cert_pass_encrypted' => 'MARCA-clave-cifrada', 'db_pass_encrypted' => 'MARCA-clave-de-la-base',
];
wa_chk('express_estado_auditado: las seis columnas de la bitacora en su orden (las cuatro de la edicion como texto o NULL, POS e inventario como enteros) y nada mas de la fila',
    express_estado_auditado($s9Fila) === $s9Estado0 && array_keys(express_estado_auditado($s9Fila)) === ['edicion', 'tipos_ecf_habilitados', 'modulos_habilitados', 'cert_vence', 'pos_enabled', 'inventario_estricto'],
    express_estado_auditado($s9Fila));
wa_chk('express_estado_auditado: una fila sin esas columnas (master sin migrar) da NULL y ceros, sin avisos',
    express_estado_auditado([]) === ['edicion' => null, 'tipos_ecf_habilitados' => null, 'modulos_habilitados' => null, 'cert_vence' => null, 'pos_enabled' => 0, 'inventario_estricto' => 0]);
$s9Cambios = ['edicion' => 'express', 'tipos_ecf_habilitados' => '45', 'cert_vence' => '2027-03-15', 'pos_enabled' => 0];
$s9A = express_auditoria($s9Fila, $s9Cambios, 'Descripcion de prueba');
wa_chk('express_auditoria: tenant_id, old_values (las seis columnas de la fila y integracion_hash_anterior al final), new_values (las mismas con los cambios encima) y description',
    array_keys($s9A) === ['tenant_id', 'old_values', 'new_values', 'description'] && $s9A['tenant_id'] === 12
    && $s9A['old_values'] === $s9Estado0 + ['integracion_hash_anterior' => str_repeat('ab', 32)]
    && $s9A['new_values'] === ['edicion' => 'express', 'tipos_ecf_habilitados' => '45', 'modulos_habilitados' => null, 'cert_vence' => '2027-03-15', 'pos_enabled' => 0, 'inventario_estricto' => 0]
    && $s9A['description'] === 'Descripcion de prueba', $s9A);
wa_chk('express_auditoria: integracion_hash_anterior solo va en old_values, su nombre no lleva secret, token ni api_key (AuditLogger los redacta) y la fila no filtra la ruta del certificado ni las claves',
    !array_key_exists('integracion_hash_anterior', $s9A['new_values']) && preg_match('/secret|token|api_key/i', 'integracion_hash_anterior') === 0
    && !preg_match('/MARCA|certificado_dgii/', (string) json_encode($s9A)), $s9A);
$s9EvLineas = ['items' => [
    'tenant' => ['estado' => 'ok', 'detalle' => 'La empresa es de tipo app.'],
    'pos' => ['estado' => 'corregir', 'detalle' => "Se apaga.\nEn dos lineas\ty con tabulador."],
    'cert' => ['estado' => 'rechazar', 'detalle' => 'CERT_FALTA: no hay certificado.'],
]];
wa_chk('express_lineas_evaluacion: una linea por item, con [OK  ], [CORR] o [RECH], el id y el detalle, en el orden de los items y sin saltos de linea ni tabuladores dentro',
    express_lineas_evaluacion($s9EvLineas) === ['[OK  ] tenant: La empresa es de tipo app.', '[CORR] pos: Se apaga. En dos lineas y con tabulador.', '[RECH] cert: CERT_FALTA: no hay certificado.']
    && express_lineas_evaluacion(['items' => []]) === [] && express_lineas_evaluacion([]) === [], express_lineas_evaluacion($s9EvLineas));
$s9LineasBase = express_lineas_evaluacion($s8E0);
wa_chk('express_lineas_evaluacion: la evaluacion de la empresa nueva de la base da 17 lineas (con la de grupo), solo integracion con [CORR] y el resto [OK  ], cada una con el detalle del item',
    count($s9LineasBase) === 17 && $s9LineasBase[0] === '[OK  ] tenant: ' . $s8E0['items']['tenant']['detalle'] && $s9LineasBase[1] === '[OK  ] grupo: ' . $s8E0['items']['grupo']['detalle']
    && count(array_filter($s9LineasBase, static fn (string $l): bool => str_starts_with($l, '[CORR] '))) === 1
    && array_values(array_filter($s9LineasBase, static fn (string $l): bool => str_starts_with($l, '[CORR] '))) === ['[CORR] integracion: ' . $s8E0['items']['integracion']['detalle']], $s9LineasBase);
$s9LineasPlan = express_lineas_plan($s8Plan);
wa_chk('express_lineas_plan: un encabezado, un renglon numerado por paso (los de la base de la empresa marcados [empresa] y los de la master [master]) y uno mas para la fila de auditoria; un plan vacio no da lineas',
    count($s9LineasPlan) === 10 && !str_starts_with($s9LineasPlan[0], ' ') && str_starts_with($s9LineasPlan[1], '  1. [empresa] ') && str_starts_with($s9LineasPlan[3], '  3. [master] ')
    && str_contains($s9LineasPlan[9], 'audit_logs') && str_contains($s9LineasPlan[1], $s8Plan[0]['que']) && str_contains($s9LineasPlan[8], $s8Plan[7]['que'])
    && express_lineas_plan([]) === [], $s9LineasPlan);
wa_chk('express_lineas_plan: no lleva ningun valor de los parametros (ni los RNC de los clientes de prueba ni el id de la empresa dentro del SQL)',
    !preg_match('/131880681|533445861|:r1|:id/', implode("\n", $s9LineasPlan)), $s9LineasPlan);

// --- express_revertir, express_refrescar_cert y express_convertir contra un master de mentira (solo lo que no necesita la base de la empresa) ----
// El master contesta la fila de la empresa ($fila; null = no existe), la bitacora ($bitacora: el old_values de cada fila de auditoria, la mas
// nueva primero), la lectura de despues del commit ($despues) y las consultas de la master del preflight. Cualquier otra consulta lanza.
$s9Maestro = static function (?array $fila, array $bitacora = [], ?array $despues = null) use ($s9LeeAuditoria): ExpPdoGuion {
    $master = new ExpPdoGuion('master', new ExpGuionLog());
    $master->respuesta = static function (string $sql, array $params, ExpPdoGuion $yo) use ($fila, $bitacora, $despues, $s9LeeAuditoria): array {
        return match (true) {
            preg_match('/^\s*SELECT\b/i', $sql) !== 1 => [],   // las escrituras (UPDATE, INSERT, DELETE) no devuelven filas: el master de mentira las anota y ya
            str_contains($sql, 'SELECT * FROM tenants') => $fila === null ? [] : [$fila],
            str_contains($sql, 'SELECT old_values FROM audit_logs') => array_map(static fn (string $json): array => ['old_values' => $json], $bitacora),
            str_contains($sql, 'FROM audit_logs') => $s9LeeAuditoria($yo),
            str_contains($sql, 'FROM tenants') => [$despues ?? []],
            str_contains($sql, 'information_schema.COLUMNS') => [['n' => '4']],
            str_contains($sql, 'FROM roles') => [['id' => '5']],
            str_contains($sql, 'FROM role_permissions') => array_map(static fn (string $p): array => ['permission' => $p], ['clients', 'facturas', 'products', 'unidades']),
            default => throw new LogicException('consulta inesperada en el master de mentira: ' . $sql),
        };
    };
    return $master;
};
$s9FilaExpress = array_replace($s9Fila, ['edicion' => 'express', 'tipos_ecf_habilitados' => '45', 'cert_vence' => '2027-03-15', 'pos_enabled' => '0', 'api_secret_hash' => '']);
$s9Hash = hash('sha256', 'marca-del-secreto-de-integracion');   // un sha256 como el que guarda TenantResolver::hashSecret
/** El old_values de una fila de auditoria por cada hash dado: lo que escribio express_aplicar, de la mas nueva a la mas vieja. */
$s9Bitacora = static fn (string ...$hashes): array => array_map(
    static fn (string $hash): string => (string) json_encode(express_estado_auditado($s9FilaExpress) + ['integracion_hash_anterior' => $hash]),
    $hashes
);
$s9Despues = ['edicion' => 'completa', 'tipos_ecf_habilitados' => '45', 'modulos_habilitados' => null, 'cert_vence' => '2027-03-15'];
/** Corre una funcion de la biblioteca atrapando todo: [resultado o null, excepcion o null, lo que escribio en el log, lo que imprimio]. */
$s9Lib = static function (callable $fn): array {
    ob_start();
    [$resultado, $error, $log] = wa_con_log($fn);
    return [$resultado, $error, $log, (string) ob_get_clean()];
};
/** Las lineas del informe de un resultado como un solo texto. */
$s9Texto = static fn (?array $r): string => implode("\n", (array) ($r['lineas'] ?? []));
/** La primera linea del informe que empieza con $prefijo, o null. */
$s9Linea = static fn (?array $r, string $prefijo): ?string => array_values(array_filter((array) ($r['lineas'] ?? []), static fn (string $l): bool => str_starts_with($l, $prefijo)))[0] ?? null;
/** Las sentencias de lectura y de escritura que recibio un master de mentira: ¿todas SELECT? */
$s9SoloLee = static fn (ExpPdoGuion $m): bool => $m->escritas === [] && array_filter($m->log->eventos, static fn (array $e): bool => in_array($e[1], ['begin', 'commit', 'rollback'], true)) === [];

// revertir, en ensayo: solo cambia la edicion, y el hash que la conversion dejo en blanco se restaura desde la bitacora
$s9M = $s9Maestro($s9FilaExpress, $s9Bitacora('', $s9Hash, str_repeat('cd', 32)));
[$s9R, $s9Error, $s9LogErr, $s9Salida] = $s9Lib(static fn (): array => express_revertir($s9M, 12, false));
wa_chk('express_revertir (ensayo): ok, sin aplicar, con exactamente ok, aplicado, plan y lineas (sin evaluacion), y no escribe nada: solo SELECT, sin transaccion',
    $s9Error === null && is_array($s9R) && array_keys($s9R) === ['ok', 'aplicado', 'plan', 'lineas'] && $s9R['ok'] === true && $s9R['aplicado'] === false && $s9SoloLee($s9M),
    [$s9Error?->getMessage(), $s9R, $s9Eventos($s9M->log)]);
wa_chk('express_revertir (ensayo): el plan es el UPDATE de la edicion (solo ella) y el que restaura api_secret_hash con el hash mas nuevo de la bitacora que es un sha256 (se salta la fila sin hash), solo si sigue vacio',
    ($s9R['plan'] ?? null) === [
        ['donde' => 'master', 'que' => $s9R['plan'][0]['que'] ?? '', 'sql' => "UPDATE tenants SET edicion = 'completa' WHERE id = :id AND tipo = 'app'", 'params' => [':id' => 12]],
        ['donde' => 'master', 'que' => $s9R['plan'][1]['que'] ?? '', 'sql' => "UPDATE tenants SET api_secret_hash = :h WHERE id = :id AND tipo = 'app' AND api_secret_hash = ''", 'params' => [':h' => $s9Hash, ':id' => 12]],
    ] && is_string($s9R['plan'][0]['que'] ?? null) && $s9R['plan'][0]['que'] !== '' && is_string($s9R['plan'][1]['que'] ?? null) && $s9R['plan'][1]['que'] !== '', $s9R['plan'] ?? null);
wa_chk('express_revertir (ensayo): el informe marca [CORR] la edicion y la integracion, avisa que volver a completa levanta toda restriccion y que no es una parada segura (las palancas: tipos_ecf_habilitados vacio y revocar tokens) y termina con DRY-RUN',
    $s9Linea($s9R, '[CORR] edicion:') !== null && $s9Linea($s9R, '[CORR] integracion:') !== null
    && preg_match('/^ADVERTENCIA:.*levanta.*no es una parada segura.*tipos_ecf_habilitados.*tokens/s', (string) $s9Linea($s9R, 'ADVERTENCIA:')) === 1
    && ($s9R['lineas'][count($s9R['lineas']) - 1] ?? null) === 'DRY-RUN: no se escribio nada. Agrega --apply para aplicar.', $s9R['lineas'] ?? null);
wa_chk('express_revertir: no imprime ni escribe en el log, y el hash que restauraria no sale en el informe (solo viaja como parametro del UPDATE)',
    $s9Salida === '' && $s9LogErr === '' && !str_contains($s9Texto($s9R), $s9Hash) && !str_contains($s9Texto($s9R), str_repeat('cd', 32)), [$s9Salida, $s9LogErr]);

// revertir, aplicado: UPDATE de la edicion, restauracion del hash y la fila de auditoria, todo en UNA transaccion de la master
$s9M = $s9Maestro($s9FilaExpress, $s9Bitacora('', $s9Hash), $s9Despues);
[$s9R, $s9Error, $s9LogErr, $s9Salida] = $s9Lib(static fn (): array => express_revertir($s9M, 12, true));
wa_chk('express_revertir (--apply): lee la fila y la bitacora, abre UNA transaccion con el UPDATE de la edicion, el del hash y el INSERT de auditoria, confirma y relee; ok y aplicado, y el informe termina con APLICADO',
    $s9Error === null && ($s9R['ok'] ?? null) === true && ($s9R['aplicado'] ?? null) === true
    && $s9Eventos($s9M->log) === ['master:execute', 'master:execute', 'master:begin', 'master:execute', 'master:execute', 'master:execute', 'master:commit', 'master:execute', 'master:execute']
    && ($s9R['lineas'][count($s9R['lineas']) - 1] ?? null) === 'APLICADO: el tenant #12 ahora es completa.', [$s9Error?->getMessage(), $s9Eventos($s9M->log), $s9R['lineas'] ?? null]);
$s9Escritas = array_map(static fn (array $e): array => [$e['sql'], $e['params']], $s9M->escritas);
wa_chk('express_revertir (--apply): escribe, en ese orden, el UPDATE de la edicion, el UPDATE del hash (con el hash de la bitacora como parametro) y el INSERT de auditoria',
    count($s9Escritas) === 3 && $s9Escritas[0] === ["UPDATE tenants SET edicion = 'completa' WHERE id = :id AND tipo = 'app'", [':id' => 12]]
    && $s9Escritas[1] === ["UPDATE tenants SET api_secret_hash = :h WHERE id = :id AND tipo = 'app' AND api_secret_hash = ''", [':h' => $s9Hash, ':id' => 12]]
    && str_starts_with(trim($s9Escritas[2][0]), 'INSERT INTO audit_logs'), array_column($s9Escritas, 0));
$s9Par3 = $s9Escritas[2][1] ?? [];
$s9Antes = json_decode((string) ($s9Par3[':old_values'] ?? ''), true);
$s9Nuevo = json_decode((string) ($s9Par3[':new_values'] ?? ''), true);
wa_chk('express_revertir (--apply): la fila de auditoria guarda la edicion que habia (express, con el hash en blanco) y la que queda (completa); lo demas es igual en las dos listas',
    ($s9Par3[':module'] ?? null) === 'edicion' && ($s9Par3[':action'] ?? null) === 'EDICION_CAMBIO' && ($s9Par3[':tenant_id'] ?? null) === 12
    && is_array($s9Antes) && is_array($s9Nuevo) && $s9Antes['edicion'] === 'express' && $s9Antes['integracion_hash_anterior'] === ''
    && $s9Nuevo['edicion'] === 'completa' && !array_key_exists('integracion_hash_anterior', $s9Nuevo)
    && array_diff_key($s9Antes, ['edicion' => 1, 'integracion_hash_anterior' => 1]) === array_diff_key($s9Nuevo, ['edicion' => 1]), [$s9Antes, $s9Nuevo]);
wa_chk('express_revertir (--apply): el hash restaurado no queda en la fila de auditoria, ni en el informe, ni se imprime',
    !str_contains((string) ($s9Par3[':old_values'] ?? '') . (string) ($s9Par3[':new_values'] ?? '') . (string) ($s9Par3[':description'] ?? ''), $s9Hash)
    && !str_contains($s9Texto($s9R), $s9Hash) && $s9Salida === '' && $s9LogErr === '', [$s9Salida, $s9LogErr]);

// revertir, los demas casos: con hash no hay nada que restaurar (ni se mira la bitacora), sin hash en la bitacora tampoco, un hash mal formado no se restaura
$s9M = $s9Maestro(array_replace($s9FilaExpress, ['api_secret_hash' => $s9Hash]), $s9Bitacora($s9Hash));
[$s9R] = $s9Lib(static fn (): array => express_revertir($s9M, 12, false));
wa_chk('express_revertir: si la empresa ya tiene hash de integracion solo cambia la edicion (un paso) y ni consulta la bitacora',
    count($s9R['plan'] ?? []) === 1 && str_contains($s9R['plan'][0]['sql'] ?? '', "SET edicion = 'completa'") && $s9Linea($s9R, '[CORR] integracion:') === null
    && array_filter($s9M->log->eventos, static fn (array $e): bool => str_contains($e[2], 'audit_logs')) === [], $s9R['lineas'] ?? null);
foreach ([
    'una bitacora vacia' => [[], 'bitacora'],
    'solo filas sin hash o con un valor que no es un sha256 (redactado, corto, en mayusculas, JSON roto)' => [
        array_merge($s9Bitacora('', '***REDACTED***', 'abc', strtoupper($s9Hash)), ['{no es json', '[]', '"texto"']), 'bitacora'],
] as $s9Desc => [$s9Rows, $s9Aguja]) {
    $s9M = $s9Maestro($s9FilaExpress, $s9Rows);
    [$s9R, $s9Error] = $s9Lib(static fn (): array => express_revertir($s9M, 12, false));
    wa_chk("express_revertir con {$s9Desc}: no hay hash que restaurar, el plan es solo el UPDATE de la edicion y el informe lo dice sin marcarlo como correccion",
        $s9Error === null && count($s9R['plan'] ?? []) === 1 && str_contains($s9Linea($s9R, '[OK  ] integracion:') ?? '', $s9Aguja) && ($s9R['ok'] ?? null) === true,
        [$s9Error?->getMessage(), $s9R['lineas'] ?? null]);
}
$s9M = $s9Maestro($s9FilaExpress, $s9Bitacora('***REDACTED***', $s9Hash));
[$s9R] = $s9Lib(static fn (): array => express_revertir($s9M, 12, false));
wa_chk('express_revertir: salta un valor que no es un sha256 y restaura el hash valido mas viejo que encuentra', ($s9R['plan'][1]['params'] ?? null) === [':h' => $s9Hash, ':id' => 12], $s9R['plan'] ?? null);
// ya es completa
$s9FilaCompleta = array_replace($s9Fila, ['api_secret_hash' => $s9Hash]);
$s9M = $s9Maestro($s9FilaCompleta);
[$s9R, $s9Error, $s9LogErr] = $s9Lib(static fn (): array => express_revertir($s9M, 12, true));
wa_chk('express_revertir de una empresa que ya es completa y tiene su hash: ok sin aplicar, plan vacio, no escribe nada ni con --apply (sin fila de auditoria) y el informe termina con SIN CAMBIOS',
    $s9Error === null && ($s9R['ok'] ?? null) === true && ($s9R['aplicado'] ?? null) === false && ($s9R['plan'] ?? null) === [] && $s9SoloLee($s9M)
    && str_starts_with((string) ($s9R['lineas'][count($s9R['lineas'] ?? []) - 1] ?? ''), 'SIN CAMBIOS:') && !str_contains($s9Texto($s9R), 'ADVERTENCIA'), [$s9Error?->getMessage(), $s9R['lineas'] ?? null]);
$s9M = $s9Maestro(array_replace($s9Fila, ['api_secret_hash' => '']), $s9Bitacora($s9Hash), array_replace($s9Despues, ['tipos_ecf_habilitados' => null, 'cert_vence' => null]));
[$s9R, $s9Error] = $s9Lib(static fn (): array => express_revertir($s9M, 12, true));
wa_chk('express_revertir de una empresa ya completa pero con el hash en blanco: solo restaura el hash (un paso, sin UPDATE de la edicion) y lo aplica con su fila de auditoria',
    $s9Error === null && ($s9R['aplicado'] ?? null) === true && count($s9R['plan'] ?? []) === 1 && str_contains($s9R['plan'][0]['sql'] ?? '', 'SET api_secret_hash = :h')
    && count($s9M->escritas) === 2, [$s9Error?->getMessage(), $s9R['plan'] ?? null, array_column($s9M->escritas, 'sql')]);
// una edicion guardada que no es ni completa ni express: se trata como completa, se avisa UNA vez en el log y se reescribe
$s9M = $s9Maestro(array_replace($s9Fila, ['edicion' => 'zz-edicion-invalida-t9']));
[$s9R, $s9Error, $s9LogErr, $s9Salida] = $s9Lib(static fn (): array => express_revertir($s9M, 12, false));
wa_chk('express_revertir con una edicion guardada que no es ni completa ni express: el valor se trata como completa (una linea en el log, no en la salida) y se reescribe como completa',
    $s9Error === null && substr_count($s9LogErr, 'edicion desconocida') === 1 && str_contains($s9LogErr, 'zz-edicion-invalida-t9') && $s9Salida === ''
    && str_contains($s9R['plan'][0]['sql'] ?? '', "SET edicion = 'completa'") && $s9Linea($s9R, '[CORR] edicion:') !== null, [$s9Error?->getMessage(), $s9LogErr, $s9R['plan'] ?? null]);
// la empresa no existe o no es de tipo app
foreach (['no existe' => [null, 'No existe'], "es de tipo 'integracion'" => [array_replace($s9Fila, ['tipo' => 'integracion']), 'app']] as $s9Desc => [$s9FilaRara, $s9Aguja]) {
    $s9M = $s9Maestro($s9FilaRara);
    [$s9R, $s9Error] = $s9Lib(static fn (): array => express_revertir($s9M, 12, true));
    wa_chk("express_revertir de una empresa que {$s9Desc}: ok false, no aplica nada, [RECH] tenant y RECHAZADO: 1 item(s). No se cambio nada.",
        $s9Error === null && ($s9R['ok'] ?? null) === false && ($s9R['aplicado'] ?? null) === false && ($s9R['plan'] ?? null) === [] && $s9SoloLee($s9M)
        && str_contains($s9Linea($s9R, '[RECH] tenant:') ?? '', $s9Aguja)
        && ($s9R['lineas'][count($s9R['lineas'] ?? []) - 1] ?? null) === 'RECHAZADO: 1 item(s). No se cambio nada.', [$s9Error?->getMessage(), $s9R['lineas'] ?? null]);
}

// refrescar el certificado: lo que se puede probar sin openssl (el camino feliz, con un .p12 de verdad, es de la suite scratch)
foreach ([
    'no existe' => [null, 'tenant', 'No existe'],
    'es completa' => [array_replace($s9FilaExpress, ['edicion' => 'completa']), 'edicion', 'Express'],
    "es de tipo 'integracion' (aunque su columna diga express)" => [array_replace($s9FilaExpress, ['tipo' => 'integracion']), 'edicion', 'Express'],
    'es Express pero su certificado no se puede abrir (el archivo no esta)' => [array_replace($s9FilaExpress, ['cert_path' => '/ruta/que/no/existe/MARCA-ruta-del-p12.p12', 'cert_pass_encrypted' => 'MARCA-clave-cifrada']), 'cert', 'CERT_FALTA'],
] as $s9Desc => [$s9FilaRara, $s9Item, $s9Aguja]) {
    $s9M = $s9Maestro($s9FilaRara);
    [$s9R, $s9Error, $s9LogErr, $s9Salida] = $s9Lib(static fn (): array => express_refrescar_cert($s9M, 12, true));
    wa_chk("express_refrescar_cert de una empresa que {$s9Desc}: ok false, nada escrito (ni con --apply), [RECH] {$s9Item}, RECHAZADO y solo las claves ok, aplicado, plan y lineas",
        $s9Error === null && is_array($s9R) && array_keys($s9R) === ['ok', 'aplicado', 'plan', 'lineas'] && $s9R['ok'] === false && $s9R['aplicado'] === false && $s9R['plan'] === [] && $s9SoloLee($s9M)
        && str_contains($s9Linea($s9R, "[RECH] {$s9Item}:") ?? '', $s9Aguja) && str_starts_with((string) ($s9R['lineas'][count($s9R['lineas']) - 1] ?? ''), 'RECHAZADO: ')
        && $s9Salida === '' && $s9LogErr === '' && !preg_match('/MARCA|certificado_dgii|ruta\/que/', $s9Texto($s9R)), [$s9Error?->getMessage(), $s9R['lineas'] ?? null]);
}

// convertir: lo que se rechaza antes de conectar a la base de la empresa (una de integracion no tiene) y lo que ni se intenta
$s9FilaIntegracion = array_replace($s9Fila, ['id' => 5, 'tipo' => 'integracion', 'ambiente' => 'ecf', 'api_secret_hash' => 'MARCA-hash-de-integracion']);
$s9Pedido = ['edicion' => 'express', 'tipos' => ['45'], 'modulos' => null];
$s9M = $s9Maestro($s9FilaIntegracion);
[$s9R, $s9Error, $s9LogErr, $s9Salida] = $s9Lib(static fn (): array => express_convertir($s9M, 5, $s9Pedido, true));
wa_chk('express_convertir de una empresa de integracion con --apply: ok false, nada aplicado, plan vacio, nada escrito en ninguna base, y las claves son ok, aplicado, evaluacion, plan y lineas',
    $s9Error === null && is_array($s9R) && array_keys($s9R) === ['ok', 'aplicado', 'evaluacion', 'plan', 'lineas'] && $s9R['ok'] === false && $s9R['aplicado'] === false && $s9R['plan'] === []
    && $s9SoloLee($s9M) && $s9Salida === '' && $s9LogErr === '', [$s9Error?->getMessage(), $s9R['lineas'] ?? null]);
wa_chk('express_convertir (rechazo): la evaluacion es la de express_preflight_evaluar (tenant y m036 entre los rechazos), el informe lleva [RECH] tenant y [RECH] m036 y termina con RECHAZADO: <n> item(s). No se cambio nada.',
    is_array($s9R) && array_keys($s9R['evaluacion']) === ['items', 'nuevo', 'rechazos', 'acciones'] && in_array('tenant', $s9R['evaluacion']['rechazos'], true) && in_array('m036', $s9R['evaluacion']['rechazos'], true)
    && $s9Linea($s9R, '[RECH] tenant:') !== null && $s9Linea($s9R, '[RECH] m036:') !== null
    && ($s9R['lineas'][count($s9R['lineas']) - 1] ?? null) === 'RECHAZADO: ' . count($s9R['evaluacion']['rechazos']) . ' item(s). No se cambio nada.'
    && count(array_filter($s9R['lineas'], static fn (string $l): bool => str_starts_with($l, '[RECH] '))) === count($s9R['evaluacion']['rechazos']), $s9R['lineas'] ?? null);
wa_chk('express_convertir (rechazo): el informe no lleva el hash de integracion ni la ruta o la clave del certificado de la fila',
    !preg_match('/MARCA|certificado_dgii/', $s9Texto($s9R)), $s9Texto($s9R));
$s9M = $s9Maestro(null);
[$s9R, $s9Error] = $s9Lib(static fn (): array => express_convertir($s9M, 999, $s9Pedido, false));
wa_chk('express_convertir de una empresa que no existe: ok false, [RECH] tenant que dice que no existe, RECHAZADO: 1 item(s) y la misma forma de resultado (evaluacion con ese unico rechazo)',
    $s9Error === null && ($s9R['ok'] ?? null) === false && ($s9R['evaluacion']['rechazos'] ?? null) === ['tenant'] && ($s9R['plan'] ?? null) === [] && $s9SoloLee($s9M)
    && str_contains($s9Linea($s9R, '[RECH] tenant:') ?? '', 'No existe') && ($s9R['lineas'][count($s9R['lineas'] ?? []) - 1] ?? null) === 'RECHAZADO: 1 item(s). No se cambio nada.',
    [$s9Error?->getMessage(), $s9R['lineas'] ?? null]);
[$s9R, $s9Error] = $s9Lib(static fn (): array => express_convertir($s9Maestro($s9FilaIntegracion), 5, ['edicion' => 'completa'] + $s9Pedido, false));
wa_chk('express_convertir solo convierte a express: un pedido de otra edicion lanza InvalidArgumentException antes de leer nada', $s9Error instanceof InvalidArgumentException, $s9Error?->getMessage());

// --- tools/tenant_edicion.php: el analizador de argumentos, el texto de ayuda y la herramienta en un php hijo aislado --------------------------
// Incluir el archivo solo define funciones: su programa principal corre unicamente cuando es ese archivo el que se ejecuta.
require_once __DIR__ . '/tenant_edicion.php';
$s9Herramienta = __DIR__ . '/tenant_edicion.php';
/** Lo que el analizador devuelve para una linea de comandos valida. */
$s9Valida = static fn (string $modo, int $tenant, array $tipos = [], ?array $modulos = null, bool $apply = false): array => [
    'modo' => $modo, 'tenant' => $tenant, 'tipos' => $tipos, 'modulos' => $modulos, 'apply' => $apply,
];
foreach ([
    'express con un tipo: el ensayo es lo que se pide por omision' => [['--tenant=12', '--edicion=express', '--tipos=45'], $s9Valida('express', 12, ['45'])],
    'express con tipos y modulos a mano (espacios y coma al final: "45, 31,") y --apply' => [
        ['--tenant=12', '--edicion=express', '--tipos=45, 31,', '--modulos=facturacion, clientes', '--apply'], $s9Valida('express', 12, ['45', '31'], ['facturacion', 'clientes'], true)],
    'un tipo repetido se queda una vez y un id con ceros a la izquierda es el mismo id' => [['--tenant=0012', '--edicion=express', '--tipos=45,45,31'], $s9Valida('express', 12, ['45', '31'])],
    'un tipo mal escrito (e45) llega tal cual: lo rechaza el preflight, no el analizador' => [['--tenant=12', '--edicion=express', '--tipos=e45'], $s9Valida('express', 12, ['e45'])],
    '--modulos vacio es una lista vacia (todo el preset)' => [['--tenant=12', '--edicion=express', '--tipos=45', '--modulos='], $s9Valida('express', 12, ['45'], [])],
    'completa' => [['--tenant=7', '--edicion=completa'], $s9Valida('completa', 7)],
    'completa con --apply' => [['--tenant=7', '--edicion=completa', '--apply'], $s9Valida('completa', 7, [], null, true)],
    '--refrescar-cert, con las opciones en cualquier orden' => [['--refrescar-cert', '--tenant=7'], $s9Valida('refrescar-cert', 7)],
    '--refrescar-cert con --apply' => [['--tenant=7', '--refrescar-cert', '--apply'], $s9Valida('refrescar-cert', 7, [], null, true)],
    '--help gana a todo lo demas, aunque lo demas sea basura' => [['--tenant=1', '--help', '--no-existe'], ['ayuda' => true]],
] as $s9Desc => [$s9Args, $s9Esperada]) {
    wa_chk("analizador, {$s9Desc}", tenant_edicion_parsear($s9Args) === $s9Esperada, tenant_edicion_parsear($s9Args));
}
foreach ([
    ['sin ningun argumento', [], '--tenant'],
    ['sin --tenant', ['--edicion=express', '--tipos=45'], '--tenant'],
    ['--tenant que no es un numero', ['--tenant=abc', '--edicion=completa'], '--tenant'],
    ['--tenant 0', ['--tenant=0', '--edicion=completa'], '--tenant'],
    ['--tenant negativo', ['--tenant=-3', '--edicion=completa'], '--tenant'],
    ['--tenant con decimales', ['--tenant=1.5', '--edicion=completa'], '--tenant'],
    ['--tenant vacio', ['--tenant=', '--edicion=completa'], '--tenant'],
    ['--tenant sin valor', ['--tenant', '--edicion=completa'], '--tenant'],
    ['--tenant demasiado largo para ser un id', ['--tenant=99999999999', '--edicion=completa'], '--tenant'],
    ['--tenant dos veces', ['--tenant=1', '--tenant=2', '--edicion=completa'], 'repetido'],
    ['--edicion=basica', ['--tenant=12', '--edicion=basica'], "'basica'"],
    ['--edicion=Express (se escribe exacta, en minusculas)', ['--tenant=12', '--edicion=Express', '--tipos=45'], "'Express'"],
    ['--edicion sin valor', ['--tenant=12', '--edicion'], '--edicion'],
    ['sin decir que hacer (ni --edicion ni --refrescar-cert)', ['--tenant=12'], '--refrescar-cert'],
    ['--edicion=express junto con --refrescar-cert', ['--tenant=12', '--edicion=express', '--tipos=45', '--refrescar-cert'], '--refrescar-cert'],
    ['express sin --tipos', ['--tenant=12', '--edicion=express'], '--tipos'],
    ['express con --tipos vacio', ['--tenant=12', '--edicion=express', '--tipos='], '--tipos'],
    ['express con --tipos de solo comas', ['--tenant=12', '--edicion=express', '--tipos=,'], '--tipos'],
    ['--tipos con --edicion=completa', ['--tenant=12', '--edicion=completa', '--tipos=45'], '--tipos'],
    ['--modulos con --edicion=completa', ['--tenant=12', '--edicion=completa', '--modulos=facturacion'], '--modulos'],
    ['--tipos con --refrescar-cert', ['--tenant=12', '--refrescar-cert', '--tipos=45'], '--tipos'],
    ['--apply con un valor', ['--tenant=12', '--edicion=completa', '--apply=1'], '--apply'],
    ['--apply dos veces', ['--tenant=12', '--edicion=completa', '--apply', '--apply'], 'repetido'],
    ['una opcion que no existe (--tipo en lugar de --tipos)', ['--tenant=12', '--edicion=express', '--tipo=45'], '--tipo=45'],
    ['un argumento suelto', ['--tenant=12', '--edicion=completa', '45'], "'45'"],
    ['una opcion corta', ['-t', '12'], "'-t'"],
] as [$s9Desc, $s9Args, $s9Aguja]) {
    $s9P = tenant_edicion_parsear($s9Args);
    wa_chk("analizador, {$s9Desc}: un error que nombra {$s9Aguja}", array_keys($s9P) === ['error'] && str_contains((string) ($s9P['error'] ?? ''), $s9Aguja), $s9P);
}
$s9P = tenant_edicion_parsear(['--tenant=12', '--edicion=completa', "--ra\x00ro\x1b[31m\n=1" . str_repeat('x', 200)]);
wa_chk('analizador: lo que el operador teclea y no se reconoce sale en el mensaje solo como ASCII imprimible y acotado (sin NUL, escapes ni saltos de linea, y sin los 200 caracteres)',
    array_keys($s9P) === ['error'] && !preg_match('/[^\x20-\x7E]/', (string) ($s9P['error'] ?? '')) && strlen((string) ($s9P['error'] ?? '')) < 300 && !str_contains((string) ($s9P['error'] ?? ''), str_repeat('x', 60)), $s9P);
// La ayuda es un heredoc de tenant_edicion.php: en un checkout de Windows con core.autocrlf (CRLF en el arbol de trabajo) trae \r\n, que no son parte del texto.
// Se compara con \n, como hace $s1Leer con los archivos del repo.
$s9Ayuda = str_replace("\r\n", "\n", tenant_edicion_ayuda());
wa_chk('tenant_edicion_ayuda: el texto de --help (en espanol) trae los tres usos, las opciones, que el ensayo es lo normal, los cuatro codigos de salida y el aviso de que volver a completa no es una parada segura',
    array_filter(['--tenant=ID', '--edicion=express', '--tipos=', '--modulos=', '--edicion=completa', '--refrescar-cert', '--apply', 'ensayo', 'Salida', 'preflight', 'no es una parada segura'],
        static fn (string $aguja): bool => !str_contains($s9Ayuda, $aguja)) === [] && !preg_match('/[^\x20-\x7E\n]/', $s9Ayuda), $s9Ayuda);

/** tenant_edicion.php en un hijo aislado (ver express_hijo_aislado): la master apunta a un puerto sin nadie, asi que ninguna conexion puede salir bien. */
$s9Hijo = static fn (array $args): array => express_hijo_aislado(array_merge(['tools/tenant_edicion.php'], $args));
foreach ([
    'sin --tenant' => [['--edicion=express', '--tipos=45'], '--tenant'],
    'con --edicion=basica' => [['--tenant=12', '--edicion=basica'], 'basica'],
    'con --tipos y --edicion=completa' => [['--tenant=12', '--edicion=completa', '--tipos=45'], '--tipos'],
    'sin ninguna opcion' => [[], '--tenant'],
    'con una opcion que no existe (--aply)' => [['--tenant=12', '--edicion=completa', '--aply'], '--aply'],
] as $s9Desc => [$s9Args, $s9Aguja]) {
    $s9R = $s9Hijo($s9Args);
    wa_chk("tenant_edicion.php {$s9Desc}: sale con 2 antes de abrir ninguna conexion, con el motivo en el texto, la invitacion a --help y sin avisos de PHP",
        $s9R['codigo'] === 2 && str_contains($s9R['salida'], $s9Aguja) && str_contains($s9R['salida'], '--help') && !preg_match('/Fatal error|Warning|Notice|Deprecated|Stack trace/i', $s9R['salida']), $s9R);
}
$s9R = $s9Hijo(['--help']);
wa_chk('tenant_edicion.php --help: sale con 0 y escribe la ayuda (la de tenant_edicion_ayuda), sin avisos de PHP',
    $s9R['codigo'] === 0 && trim(str_replace("\r\n", "\n", $s9R['salida'])) === trim($s9Ayuda) && !preg_match('/Fatal error|Warning|Notice|Deprecated/i', $s9R['salida']), $s9R);
$s9R = $s9Hijo(['--tenant=12', '--edicion=completa']);
wa_chk('tenant_edicion.php con argumentos validos y la master inalcanzable (puerto 1 de loopback): sale con 1, dice que no pudo conectar a la base de datos maestra y no nombra el servidor, el usuario ni la clave ni deja la linea que MasterDatabase manda al log',
    $s9R['codigo'] === 1 && str_contains($s9R['salida'], 'base de datos maestra') && !preg_match('/127\.0\.0\.1|aislado_usuario|aislado_clave|MasterDB|Stack trace|Warning/', $s9R['salida']), $s9R);

$s9Fuente = (string) file_get_contents($s9Herramienta);
$s9PosGuarda = strpos($s9Fuente, "PHP_SAPI !== 'cli'");
$s9PosRequire = strpos($s9Fuente, 'require_once');
wa_chk('tenant_edicion.php: empieza en <?php sin BOM y rechaza lo que no es linea de comandos (PHP_SAPI !== cli, con 403) antes de cargar nada: el directorio tools del hosting se puede abrir desde un navegador',
    str_starts_with($s9Fuente, '<?php') && $s9PosGuarda !== false && $s9PosRequire !== false && $s9PosGuarda < $s9PosRequire && str_contains($s9Fuente, 'http_response_code(403)'),
    [$s9PosGuarda, $s9PosRequire]);
wa_chk('tenant_edicion.php: carga MasterDatabase.php, TenantResolver.php y la biblioteca con require_once, y nunca lee $_GET, $_POST, $_REQUEST ni $_COOKIE',
    preg_match_all("~require_once __DIR__ \\. '/(\\.\\./src/MasterDatabase|\\.\\./src/TenantResolver|express_edicion_lib)\\.php';~", $s9Fuente) === 3
    && !preg_match('/\$_(GET|POST|REQUEST|COOKIE)\b/', $s9Fuente), $s9Fuente === '' ? 'sin fuente' : null);
$s9Imprime = [];
foreach (token_get_all($s9Fuente) as $s9T) {
    if (is_array($s9T) && $s9T[0] === T_STRING && in_array(strtolower($s9T[1]), ['print_r', 'var_dump', 'var_export', 'debug_zval_dump', 'phpinfo', 'getenv', 'putenv'], true)) {
        $s9Imprime[] = $s9T[1] . ' (linea ' . $s9T[2] . ')';
    }
}
wa_chk('tenant_edicion.php: no vuelca variables ni toca el entorno (ni print_r, var_dump, var_export, phpinfo, getenv ni putenv): lo que imprime es el informe de la biblioteca y sus propios avisos',
    $s9Imprime === [], $s9Imprime);
$s9Hallado = $s8Ocho3($s8SinTextos($s9Fuente));
wa_chk('PHP 8.3 (produccion y CI): tenant_edicion.php no usa funciones ni sintaxis de PHP 8.4 o 8.5', $s9Hallado === [], $s9Hallado);
$s9Lint = wa_correr([PHP_BINARY, '-d', 'error_reporting=-1', '-d', 'display_errors=1', '-l', $s9Herramienta]);
wa_chk('php -l de tenant_edicion.php: sin errores de sintaxis ni deprecaciones', $s9Lint['codigo'] === 0 && str_starts_with(trim($s9Lint['salida']), 'No syntax errors detected')
    && !str_contains($s9Lint['salida'], 'Deprecated'), $s9Lint);
$s9Lint = wa_correr([PHP_BINARY, '-d', 'error_reporting=-1', '-d', 'display_errors=1', '-l', __DIR__ . '/express_edicion_lib.php']);
wa_chk('php -l de la biblioteca despues de la tarea 9: sin errores de sintaxis ni deprecaciones', $s9Lint['codigo'] === 0 && str_starts_with(trim($s9Lint['salida']), 'No syntax errors detected')
    && !str_contains($s9Lint['salida'], 'Deprecated'), $s9Lint);

// ---------------------------------------------------------------------------
// 10) tools/create_tenant.php: --edicion, --tipos-ecf y --telefono
// ---------------------------------------------------------------------------
echo "\n== 10) create_tenant.php: --edicion, --tipos-ecf y --telefono ==\n";
$s10Herramienta = __DIR__ . '/create_tenant.php';
// El arbol de Windows trae esta herramienta con CRLF (autocrlf): las busquedas de texto van sobre la fuente con \n.
$s10Fuente = str_replace("\r\n", "\n", (string) file_get_contents($s10Herramienta));

/**
 * create_tenant.php en un hijo aislado (ver express_hijo_aislado): la master (por el entorno) y la base de la empresa (--db-host y --db-port) apuntan a un puerto de
 * loopback sin nadie. Los dos -d van antes del script: con el php.ini de produccion (display_errors apagado) un aviso de PHP no saldria en la salida, y las pruebas
 * de "sin avisos" no verian nada.
 */
$s10Hijo = static fn (array $args): array => express_hijo_aislado(array_merge(
    ['-d', 'display_errors=1', '-d', 'error_reporting=-1', 'tools/create_tenant.php', '--db-host=127.0.0.1', '--db-port=1'],
    $args
));
/** Un alta tipo app con todos sus argumentos requeridos: sin banderas de Express llega a abrir la master, que en el hijo aislado no contesta. */
$s10App = ['--tipo=app', '--nombre=X', '--rnc=131000001', '--db-name=a', '--db-user=b', '--db-pass=c'];
$s10Diagnostico = '/Fatal error|Warning|Notice|Deprecated|Stack trace|Uncaught/i';

// --- lo que se rechaza: sale con 1 antes de abrir ninguna conexion (ni siquiera imprime el titulo del alta) ---------------------------------
foreach ([
    '--edicion=express sin --tipos-ecf' => [array_merge($s10App, ['--edicion=express']), ['--tipos-ecf'], []],
    '--edicion=express con --tipos-ecf= (valor vacio: segun la version de PHP, getopt lo descarta o lo entrega vacio; de las dos formas se pide --tipos-ecf)' => [array_merge($s10App, ['--edicion=express', '--tipos-ecf=']), ['--tipos-ecf'], []],
    '--edicion=express con --tipos-ecf sin valor (llega como false)' => [array_merge($s10App, ['--edicion=express', '--tipos-ecf']), ['--tipos-ecf', 'ningun tipo'], []],
    '--edicion=express con --tipos-ecf de solo comas' => [array_merge($s10App, ['--edicion=express', '--tipos-ecf=,']), ['--tipos-ecf', 'ningun tipo'], []],
    '--tipos-ecf=44 (un tipo que Express no vende)' => [array_merge($s10App, ['--edicion=express', '--tipos-ecf=44']), ["'44'", '31, 32, 45, 33'], []],
    '--tipos-ecf=e45 (un tipo mal escrito: RF2)' => [array_merge($s10App, ['--edicion=express', '--tipos-ecf=e45']), ["'e45'", '31, 32, 45, 33'], []],
    '--tipos-ecf=34 (la nota de credito se deriva sola, no se pide)' => [array_merge($s10App, ['--edicion=express', '--tipos-ecf=34']), ["'34'", '31, 32, 45, 33'], []],
    '--tipos-ecf=45,44 (uno bueno y uno malo: se rechaza todo, no se angosta en silencio)' => [array_merge($s10App, ['--edicion=express', '--tipos-ecf=45,44']), ["'44'", '31, 32, 45, 33'], []],
    '--edicion=basica' => [array_merge($s10App, ['--edicion=basica']), ["'basica'", 'express', 'completa'], []],
    '--edicion=Express (se escribe exacta, en minusculas, como en tenant_edicion.php)' => [array_merge($s10App, ['--edicion=Express', '--tipos-ecf=45']), ["'Express'", 'minusculas'], []],
    '--edicion sin valor' => [array_merge($s10App, ['--edicion']), ['--edicion', 'express', 'completa'], []],
    '--tipos-ecf=45 sin --edicion' => [array_merge($s10App, ['--tipos-ecf=45']), ['requiere --edicion=express'], []],
    '--tipos-ecf=45 con --edicion=completa' => [array_merge($s10App, ['--edicion=completa', '--tipos-ecf=45']), ['requiere --edicion=express'], []],
    '--telefono=8095551234 (sin guiones)' => [array_merge($s10App, ['--telefono=8095551234']), ['809-555-1234', "'8095551234'"], []],
    '--telefono=809-555-12345 (un digito de mas)' => [array_merge($s10App, ['--telefono=809-555-12345']), ['809-555-1234'], []],
    '--telefono=809-555-123 (un digito de menos)' => [array_merge($s10App, ['--telefono=809-555-123']), ['809-555-1234'], []],
    '--telefono=abc-def-ghij (letras)' => [array_merge($s10App, ['--telefono=abc-def-ghij']), ['809-555-1234'], []],
    '--telefono=809-555-1234 con un salto de linea al final' => [array_merge($s10App, ["--telefono=809-555-1234\n"]), ['809-555-1234'], []],
    '--telefono sin valor' => [array_merge($s10App, ['--telefono']), ['809-555-1234'], []],
    '--edicion dos veces' => [array_merge($s10App, ['--edicion=express', '--edicion=express', '--tipos-ecf=45']), ['--edicion', 'repetido'], []],
    '--tipos-ecf dos veces (se da una sola lista, separada por comas)' => [array_merge($s10App, ['--edicion=express', '--tipos-ecf=45', '--tipos-ecf=31']), ['--tipos-ecf', 'repetido'], []],
    '--telefono dos veces' => [array_merge($s10App, ['--telefono=809-555-1234', '--telefono=809-555-1235']), ['--telefono', 'repetido'], []],
    // Revision final: la credencial de integracion de cualquier empresa de un grupo actua por las demas (TenantResolver::switchToSibling) sin pasar por los limites de Express.
    '--edicion=express con --grupo=self (la cabeza de un grupo nuevo)' => [array_merge($s10App, ['--edicion=express', '--tipos-ecf=45', '--grupo=self']), ['--grupo', '--edicion=express', 'grupo de empresas'], []],
    '--edicion=express con --grupo=7 (hermana de un grupo)' => [array_merge($s10App, ['--edicion=express', '--tipos-ecf=45', '--grupo=7']), ['--grupo', '--edicion=express', 'grupo de empresas'], []],
    'el grupo se juzga antes que los argumentos requeridos (un app sin --db-name, --db-user ni --db-pass)' => [['--tipo=app', '--nombre=X', '--rnc=131000001', '--edicion=express', '--tipos-ecf=45', '--grupo=7'], ['--grupo', 'grupo de empresas'], ['Faltan argumentos requeridos']],
    'tipo integracion con --edicion=express (sandbox, que no pide certificado)' => [['--tipo=integracion', '--nombre=X', '--rnc=131000001', '--ambiente=sandbox', '--edicion=express'], ['solo para tenants tipo app'], []],
    'tipo integracion con --tipos-ecf' => [['--tipo=integracion', '--nombre=X', '--rnc=131000001', '--ambiente=sandbox', '--tipos-ecf=45'], ['solo para tenants tipo app'], []],
    'tipo integracion con --telefono' => [['--tipo=integracion', '--nombre=X', '--rnc=131000001', '--ambiente=sandbox', '--telefono=809-555-1234'], ['solo para tenants tipo app'], []],
    'las banderas se juzgan antes que los argumentos requeridos (un app sin --db-name, --db-user ni --db-pass)' => [['--tipo=app', '--nombre=X', '--rnc=131000001', '--edicion=express'], ['--tipos-ecf'], ['Faltan argumentos requeridos']],
    'las banderas se juzgan antes que el certificado (una integracion sin --cert-pass)' => [['--tipo=integracion', '--nombre=X', '--rnc=131000001', '--edicion=express'], ['solo para tenants tipo app'], ['Faltan argumentos requeridos']],
] as $s10Desc => [$s10Args, $s10Agujas, $s10Prohibidas]) {
    $s10R = $s10Hijo($s10Args);
    wa_chk("create_tenant.php {$s10Desc}: sale con 1, dice " . implode(' y ', $s10Agujas) . ', no llega al titulo del alta y no deja avisos de PHP',
        $s10R['codigo'] === 1
        && array_filter($s10Agujas, static fn (string $aguja): bool => !str_contains($s10R['salida'], $aguja)) === []
        && array_filter($s10Prohibidas, static fn (string $aguja): bool => str_contains($s10R['salida'], $aguja)) === []
        && !str_contains($s10R['salida'], '== Onboarding tenant') && preg_match($s10Diagnostico, $s10R['salida']) !== 1, $s10R);
}

// --- lo que se acepta pasa la validacion y el hijo llega al titulo del alta y a abrir la master (que no contesta): ninguna bandera valida se rechaza ---
foreach ([
    'sin ninguna bandera de Express (el alta de siempre)' => [],
    '--edicion=express --tipos-ecf=45' => ['--edicion=express', '--tipos-ecf=45'],
    'los tipos escritos a mano con espacios y coma al final, el 33 y el telefono' => ['--edicion=express', '--tipos-ecf= 45, 31 ,33,', '--telefono=809-555-1234'],
    '--edicion=completa (lo mismo que no ponerla)' => ['--edicion=completa'],
    '--telefono sin Express' => ['--telefono=809-555-1234'],
    '--edicion=completa con --grupo=7 (un alta completa si puede unirse a un grupo)' => ['--edicion=completa', '--grupo=7'],
    '--grupo=7 sin ninguna bandera de Express (el alta de siempre)' => ['--grupo=7'],
    '--edicion=express con --grupo= (vacio: no pide ningun grupo)' => ['--edicion=express', '--tipos-ecf=45', '--grupo='],
] as $s10Desc => $s10Args) {
    $s10R = $s10Hijo(array_merge($s10App, $s10Args));
    wa_chk("create_tenant.php {$s10Desc}: pasa la validacion de las banderas, imprime el titulo del alta y llega a abrir la master (que en el hijo aislado no contesta), sin ningun ERROR de uso ni avisos de PHP",
        str_contains($s10R['salida'], '== Onboarding tenant (app): X (RNC 131000001) ==') && str_contains($s10R['salida'], 'base de datos maestra')
        && !str_contains($s10R['salida'], 'ERROR: ') && preg_match('/Warning|Notice|Deprecated/i', $s10R['salida']) !== 1, $s10R);
}

// --- la fuente: lo que no se mueve y lo que tiene que estar ------------------------------------------------------------------------------
// El INSERT del tenant es el de master, letra por letra: el alta nace completa y la conversion es una llamada aparte (una fila Express nunca existe
// antes de pasar su preflight; contratos 4: create_tenant.php ya no inserta las columnas de la 014).
// Los dos literales son nowdocs de ESTE archivo: en un checkout con CRLF traen \r\n, y $s10Fuente ya viene con \n. Se normalizan los dos lados (la fuente arriba, el literal aqui).
$s10Insert = str_replace("\r\n", "\n", <<<'SQL'
'INSERT INTO tenants
        (nombre, rnc, api_key, api_secret_hash, tipo, ' . $colGrupo . 'db_host, db_port, db_name, db_user,
         db_pass_encrypted, cert_path, cert_pass_encrypted, webhook_url, webhook_secret_encrypted,
         ambiente, activo)
     VALUES
        (:nombre, :rnc, :api_key, :api_secret_hash, :tipo, ' . $valGrupo . ':db_host, :db_port, :db_name, :db_user,
         :db_pass_enc, :cert_path, :cert_pass_enc, :webhook_url, :webhook_sec_enc,
         :ambiente, 1)'
SQL);
wa_chk('create_tenant.php: el INSERT INTO tenants es el de master letra por letra, y es el unico (sin columnas de la 014: la conversion es una llamada aparte)',
    substr_count($s10Fuente, $s10Insert) === 1 && substr_count($s10Fuente, 'INSERT INTO tenants') === 1);
// El UPDATE de emisor_config de siempre no cambia: el telefono va en un segundo UPDATE y solo si se dio --telefono.
$s10UpdateEmisor = str_replace("\r\n", "\n", <<<'SQL'
'UPDATE emisor_config
            SET rnc = :rnc, razon_social = :rs, nombre_comercial = :nc, direccion = :dir
          WHERE id = 1'
SQL);
wa_chk('create_tenant.php: el UPDATE emisor_config de siempre (rnc, razon social, nombre comercial y direccion) no cambia',
    substr_count($s10Fuente, $s10UpdateEmisor) === 1);
preg_match("/getopt\\(\\s*'',\\s*\\[(.*?)\\]\\s*\\);/s", $s10Fuente, $s10Lista);
$s10Opciones = $s10Lista[1] ?? '';
wa_chk("create_tenant.php: la lista de getopt trae 'edicion::', 'tipos-ecf::' y 'telefono::' y sigue trayendo las de siempre",
    array_filter(["'edicion::'", "'tipos-ecf::'", "'telefono::'", "'tipo::'", "'nombre:'", "'rnc:'", "'direccion::'", "'skip-create-db'"],
        static fn (string $opcion): bool => !str_contains($s10Opciones, $opcion)) === [], $s10Opciones);
wa_chk('create_tenant.php: el telefono se escribe con un segundo UPDATE emisor_config SET telefono = :tel WHERE id = 1, solo si se dio --telefono, despues del UPDATE de siempre',
    substr_count($s10Fuente, 'UPDATE emisor_config SET telefono = :tel') === 1
    && preg_match('/if \(\$telefono !== null\) \{[^}]*UPDATE emisor_config SET telefono = :tel WHERE id = 1/', $s10Fuente) === 1
    && strpos($s10Fuente, 'UPDATE emisor_config SET telefono = :tel') > strpos($s10Fuente, $s10UpdateEmisor));
// La rama web (anexo 9.4: el envoltorio web no se extiende): se corre el bloque tal como esta escrito, con un $_REQUEST de mentira que trae las tres banderas.
$s10Rama = preg_match('/\n\} else \{\n(    \$opts = \$_REQUEST;.*?\n)\}\n/s', $s10Fuente, $s10M) === 1 ? $s10M[1] : '';
$s10Prueba = (string) tempnam(sys_get_temp_dir(), 'ent_web_');
file_put_contents($s10Prueba, "<?php\n\$_REQUEST = json_decode((string) base64_decode(\$argv[1]), true);\n" . $s10Rama . "echo json_encode(\$opts);\n");
$s10R = wa_correr([PHP_BINARY, $s10Prueba, base64_encode((string) json_encode([
    'token' => 't', 'tipo' => 'app', 'nombre' => 'X', 'edicion' => 'express', 'tipos-ecf' => '45', 'telefono' => '809-555-1234',
]))]);
@unlink($s10Prueba);
wa_chk('create_tenant.php: la rama web ($opts = $_REQUEST) le quita a $opts el token y las tres banderas (edicion, tipos-ecf y telefono) y deja lo demas: el navegador nunca las acepta',
    $s10Rama !== '' && json_decode(trim($s10R['salida']), true) === ['tipo' => 'app', 'nombre' => 'X'], [$s10Rama, $s10R]);
// La conversion: una sola llamada, despues de crear los roles y el usuario admin y antes del resumen, con apply en true.
$s10PosLlamada = strpos($s10Fuente, 'express_convertir(');
$s10PosResumen = strpos($s10Fuente, '== Tenant creado OK ==');
$s10PosRoles = strpos($s10Fuente, 'INSERT INTO roles');
$s10PosAdmin = strpos($s10Fuente, 'INSERT INTO users');
wa_chk('create_tenant.php: express_convertir( aparece una vez, despues de sembrar los roles y de crear el usuario admin y antes del echo "== Tenant creado OK ==", y aplica (apply true)',
    substr_count($s10Fuente, 'express_convertir(') === 1 && !in_array(false, [$s10PosLlamada, $s10PosResumen, $s10PosRoles, $s10PosAdmin], true)
    && $s10PosRoles < $s10PosLlamada && $s10PosAdmin < $s10PosLlamada && $s10PosLlamada < $s10PosResumen
    && preg_match('/express_convertir\(\$master, \$tenantId, \[[^\]]*\], true\)/', $s10Fuente) === 1, [$s10PosRoles, $s10PosAdmin, $s10PosLlamada, $s10PosResumen]);
// Lo que ningun hijo aislado alcanza a correr (sin una master real no se llega a la conversion): el codigo de salida y cuando se imprime la credencial.
wa_chk('create_tenant.php: un rechazo del preflight deja el codigo de salida en 3, un fallo inesperado en 1, y la herramienta termina con ese codigo (ya no con exit(0))',
    preg_match('/\$codigoSalida = \$expressConvertido \? 0 : 3;/', $s10Fuente) === 1
    && preg_match('/catch \(Throwable \$e\) \{[^}]*\$codigoSalida = 1;/', $s10Fuente) === 1
    && preg_match('/\nexit\(\$codigoSalida\);\n/', $s10Fuente) === 1 && !str_contains($s10Fuente, 'exit(0)'));
wa_chk('create_tenant.php: API KEY y API SECRET se imprimen una sola vez y solo cuando el tenant NO quedo convertido (el else de if ($expressConvertido)), y de un tenant convertido tampoco se imprime la guia de entregarlos',
    substr_count($s10Fuente, '  API KEY    : {$apiKey}') === 1 && substr_count($s10Fuente, '  API SECRET : {$apiSecret}') === 1
    && preg_match('/if \(\$expressConvertido\) \{[^}]*Credenciales de integración desactivadas[^}]*\} else \{\s*echo "  API KEY    : \{\$apiKey\}\\\\n";\s*echo "  API SECRET : \{\$apiSecret\}\\\\n";\s*\}/', $s10Fuente) === 1
    && preg_match('/if \(\$expressConvertido\) \{\s*\/\/[^\n]*\n\} elseif \(\$grupoId !== null && !\$grupoSelf\) \{/', $s10Fuente) === 1);
wa_chk('create_tenant.php: las dos lineas del resumen de Express son las de la tarea, letra por letra (credenciales desactivadas, y el tenant que quedo como completa con el comando para terminar)',
    str_contains($s10Fuente, '>> Credenciales de integración desactivadas: es un tenant Express.')
    && preg_match('/quedó creado como completa\. Corrige lo anterior y corre tools\/tenant_edicion\.php --tenant=[^\n]*? --edicion=express --tipos=[^\n]*? --apply/', $s10Fuente) === 1
    // ... con el id y los tipos que se pidieron (no un "..."), y es lo que se imprime con la salida 3 (el rechazo), no con la de un fallo inesperado.
    && str_contains($s10Fuente, '--tenant={$tenantId} --edicion=express --tipos={$tiposCsv} --apply') && str_contains($s10Fuente, '$tiposCsv = implode(\',\', $tiposEcf);')
    && preg_match('/if \(\$codigoSalida === 3\) \{\s*echo "\\\\nEl tenant #\{\$tenantId\} quedó creado como completa\. /', $s10Fuente) === 1);
wa_chk('create_tenant.php: el informe del preflight (las lineas [OK  ], [CORR] y [RECH] y su RECHAZADO o APLICADO) se imprime antes del resumen, tanto si convierte como si rechaza',
    str_contains($s10Fuente, "echo implode(\"\\n\", \$conversion['lineas']), \"\\n\";") && substr_count($s10Fuente, "\$conversion['lineas']") === 1
    && (int) strpos($s10Fuente, "\$conversion['lineas']") < (int) strpos($s10Fuente, '== Tenant creado OK =='));
// La linea de un fallo inesperado de la conversion sale de express_describir_fallo, la funcion de la biblioteca que tambien usa tenant_edicion.php: la politica de que el mensaje
// de una PDOException (trae el usuario y el servidor) no sale nunca vive en un solo lugar. Se prueba la funcion misma (la biblioteca ya esta cargada desde la seccion 8), no una
// copia de su texto, y las dos herramientas la llaman sin tener copia propia (los dos pins de abajo). El mensaje de las excepciones de la propia herramienta y de los errores del
// motor si sale, en una sola linea; de cualquier otra excepcion sale solo la clase.
$s10Biblioteca = str_replace("\r\n", "\n", (string) file_get_contents(__DIR__ . '/express_edicion_lib.php'));
$s10Edicion = str_replace("\r\n", "\n", (string) file_get_contents(__DIR__ . '/tenant_edicion.php'));
$s10Existe = function_exists('express_describir_fallo');
$s10F1 = new PDOException("SQLSTATE[HY000] [1045] Access denied for user 'usuario_secreto'@'servidor_secreto'");
$s10F1->errorInfo = ['HY000', 1045, "Access denied for user 'usuario_secreto'@'servidor_secreto'"];
$s10F2 = new PDOException('sin errorInfo, con el usuario_secreto en el mensaje', 77);
$s10F3 = new PDOException('solo el SQLSTATE, con el usuario_secreto en el mensaje');
$s10F3->errorInfo = ['42S02'];
$s10Casos = [
    'PDOException con errorInfo: solo el SQLSTATE y el errno del driver' => [$s10F1, '/^PDOException \([^)]+:\d+\) SQLSTATE HY000 errno 1045$/'],
    'PDOException sin errorInfo: solo el codigo' => [$s10F2, '/^PDOException \([^)]+:\d+\) codigo 77$/'],
    'PDOException con un errorInfo que trae solo el SQLSTATE: el errno es ?' => [$s10F3, '/^PDOException \([^)]+:\d+\) SQLSTATE 42S02 errno \?$/'],
    'RuntimeException: su mensaje, en una sola linea' => [new RuntimeException("No se pudo conectar\n   a la   base"), '/^RuntimeException \([^)]+:\d+\): No se pudo conectar a la base$/'],
    'InvalidArgumentException: su mensaje, sin tabuladores ni espacios de mas' => [new InvalidArgumentException("  un tipo\tmal   escrito \n"), '/^InvalidArgumentException \([^)]+:\d+\): un tipo mal escrito$/'],
    'una excepcion de logica (DomainException es LogicException): su mensaje' => [new DomainException('el estado no es posible'), '/^DomainException \([^)]+:\d+\): el estado no es posible$/'],
    'un Error del motor (TypeError): su mensaje' => [new TypeError('Argument #1 must be of type X'), '/^TypeError \([^)]+:\d+\): Argument #1 must be of type X$/'],
    'cualquier otra excepcion (JsonException): solo la clase, nunca su mensaje' => [new JsonException('texto de una clase que no se imprime con el usuario_secreto'), '/^JsonException \([^)]+:\d+\)$/'],
];
$s10Mal = [];
if ($s10Existe) {
    foreach ($s10Casos as $s10Desc => [$s10Excepcion, $s10Patron]) {
        $s10Linea = express_describir_fallo($s10Excepcion);
        if (preg_match($s10Patron, $s10Linea) !== 1 || str_contains($s10Linea, 'secreto') || str_contains($s10Linea, "\n")) {
            $s10Mal[$s10Desc] = $s10Linea;
        }
    }
}
wa_chk('express_edicion_lib.php: express_describir_fallo(Throwable $e): string da la clase y archivo:linea; de una PDOException solo el SQLSTATE y el errno (o el codigo), nunca su mensaje; de una excepcion de la herramienta o un Error su mensaje en una linea; de cualquier otra solo la clase',
    $s10Existe && $s8Firma('express_describir_fallo') === 'express_describir_fallo(Throwable $e): string' && $s10Mal === [], $s10Existe ? $s10Mal : 'la funcion no existe');
$s10Declarada = preg_match('/\nfunction express_describir_fallo\(Throwable \$e\): string\n\{.*?\n\}\n/s', $s10Biblioteca, $s10M) === 1 ? $s10M[0] : '';
wa_chk('express_edicion_lib.php: express_describir_fallo se declara una sola vez y se apoya en express_una_linea (no repite su preg_replace)',
    substr_count($s10Biblioteca, 'function express_describir_fallo(') === 1 && $s10Declarada !== ''
    && str_contains($s10Declarada, 'express_una_linea($e->getMessage())') && !str_contains($s10Declarada, 'preg_replace'), $s10Declarada);
wa_chk('create_tenant.php: el fallo inesperado de la conversion se imprime con express_describir_fallo (la de la biblioteca) y la herramienta no tiene copia propia (ni describirFalloExpress ni la politica de la PDOException)',
    substr_count($s10Fuente, 'express_describir_fallo(') === 1
    && preg_match('/catch \(Throwable \$e\) \{\s*echo \'\[FALLO\] \' \. express_describir_fallo\(\$e\) \. "\\\\n";\s*\$codigoSalida = 1;/', $s10Fuente) === 1
    && !str_contains($s10Fuente, 'describirFalloExpress') && !str_contains($s10Fuente, 'errorInfo') && !str_contains($s10Fuente, 'instanceof PDOException'));
// Y el bloque de la conversion de create_tenant.php, tal como esta escrito y con la biblioteca de verdad, contra una master que se cae a media conversion (el PDO de mentira de la
// seccion 9 lanza al leer el tenant). Ningun hijo llega hasta ahi sin una base de empresa de verdad, asi que esto es lo que de verdad corre el catch de esa herramienta.
$s10Bloque = preg_match('/\n(\$expressConvertido = false;\n\$codigoSalida = 0;\nif \(\$expressPedido\) \{\n.*?\n\}\n)\n\/\/ =+\n\/\/ Listo\n/s', $s10Fuente, $s10N) === 1 ? $s10N[1] : '';
/** Corre el bloque en el ambito de esta funcion, con las variables que el script tiene a esa altura, y devuelve lo que imprimio, el codigo de salida que dejo y si convirtio. */
$s10Correr = static function (string $bloque, PDO $master): array {
    $expressPedido = true;
    $tenantId = 12;
    $tiposEcf = ['45'];
    $archivo = (string) tempnam(sys_get_temp_dir(), 'ent_conv_');
    file_put_contents($archivo, "<?php\n" . $bloque);
    ob_start();
    try {
        include $archivo;
    } finally {
        $salida = (string) ob_get_clean();
        @unlink($archivo);
    }
    return ['salida' => $salida, 'codigo' => $codigoSalida ?? null, 'convertido' => $expressConvertido ?? null];
};
$s10Caida = new PDOException("SQLSTATE[HY000] [1045] Access denied for user 'usuario_secreto'@'servidor_secreto'");
$s10Caida->errorInfo = ['HY000', 1045, "Access denied for user 'usuario_secreto'@'servidor_secreto'"];
$s10Master = new ExpPdoGuion('master', new ExpGuionLog());
$s10Master->errores[] = ['FROM tenants', $s10Caida];
$s10Glue = $s10Bloque === '' ? null : $s10Correr($s10Bloque, $s10Master);
wa_chk('create_tenant.php: si la master falla a media conversion (una PDOException al leer el tenant), el bloque imprime UNA linea [FALLO] de express_describir_fallo (clase, archivo:linea, SQLSTATE y errno) sin el mensaje del driver, deja el tenant sin convertir y sale con 1',
    $s10Glue !== null && substr_count($s10Glue['salida'], '[FALLO]') === 1
    && preg_match('/^\[FALLO\] PDOException \([^)]+:\d+\) SQLSTATE HY000 errno 1045$/m', $s10Glue['salida']) === 1
    && !str_contains($s10Glue['salida'], 'secreto') && !str_contains($s10Glue['salida'], 'Access denied')
    && $s10Glue['codigo'] === 1 && $s10Glue['convertido'] === false, $s10Glue ?? 'no se encontro el bloque de la conversion en create_tenant.php');
wa_chk('tenant_edicion.php: el error inesperado se imprime con express_describir_fallo (la de la biblioteca) y la herramienta no tiene copia propia (ni tenant_edicion_describir ni la politica de la PDOException)',
    substr_count($s10Edicion, 'express_describir_fallo(') === 1
    && str_contains($s10Edicion, "fwrite(STDERR, '[FALLO] ' . express_describir_fallo(\$e) . \"\\n\");")
    && !str_contains($s10Edicion, 'tenant_edicion_describir') && !str_contains($s10Edicion, 'errorInfo') && !str_contains($s10Edicion, 'instanceof PDOException'));
// Y por la herramienta de verdad: con la master inalcanzable, la excepcion de MasterDatabase sale como la linea de la funcion compartida (clase, archivo:linea y su mensaje).
$s10R = $s9Hijo(['--tenant=12', '--edicion=completa']);
wa_chk('tenant_edicion.php con la master inalcanzable: su linea [FALLO] es la de express_describir_fallo (clase, archivo:linea y el mensaje de la excepcion en una linea), sin usuario, servidor ni clave',
    $s10R['codigo'] === 1 && preg_match('/^\[FALLO\] RuntimeException \(MasterDatabase\.php:\d+\): No se pudo conectar a la base de datos maestra\.\r?$/m', $s10R['salida']) === 1
    && !preg_match('/127\.0\.0\.1|aislado_usuario|aislado_clave|MasterDB|Stack trace|Warning/', $s10R['salida']), $s10R);
wa_chk('create_tenant.php: la cabecera de uso documenta las tres banderas nuevas (--edicion=express, --tipos-ecf y --telefono=809-555-1234)',
    preg_match('/\A<\?php\s*\/\*\*.*?\*\//s', $s10Fuente, $s10Cabecera) === 1
    && array_filter(['--edicion=express', '--tipos-ecf', '--telefono=809-555-1234'], static fn (string $bandera): bool => !str_contains($s10Cabecera[0], $bandera)) === [],
    $s10Cabecera[0] ?? null);
$s10Hallado = $s8Ocho3($s8SinTextos($s10Fuente));
wa_chk('PHP 8.3 (produccion y CI): create_tenant.php no usa funciones ni sintaxis de PHP 8.4 o 8.5', $s10Hallado === [], $s10Hallado);
$s10Lint = wa_correr([PHP_BINARY, '-d', 'error_reporting=-1', '-d', 'display_errors=1', '-l', $s10Herramienta]);
wa_chk('php -l de create_tenant.php: sin errores de sintaxis ni deprecaciones', $s10Lint['codigo'] === 0 && str_starts_with(trim($s10Lint['salida']), 'No syntax errors detected')
    && !str_contains($s10Lint['salida'], 'Deprecated'), $s10Lint);

// ---------------------------------------------------------------------------
// 11) tools/verificar_express.php (solo lectura) y la consulta 4 de tools/verificar_migraciones_master.sql
// ---------------------------------------------------------------------------
echo "\n== 11) verificar_express.php y la consulta 4 del verificador de la master ==\n";
$s11Herramienta = __DIR__ . '/verificar_express.php';
// Incluirla solo define funciones: su programa principal corre unicamente cuando es ese archivo el que se ejecuta. Dentro de un buffer, para medir que no imprime nada.
ob_start();
require_once $s11Herramienta;
$s11Incluida = (string) ob_get_clean();
wa_chk('incluir tools/verificar_express.php no imprime nada ni arranca su programa principal', $s11Incluida === '', $s11Incluida);

$s11Hoy = strtotime('2026-10-29 12:00:00');   // un mediodia fijo: los dias que faltan no dependen de la hora a la que corre la suite
/** La fecha 'Y-m-d' que cae $dias dias despues del dia de $s11Hoy (antes, si es negativo). */
$s11Dia = static fn (int $dias): string => date('Y-m-d', strtotime(sprintf('%+d days', $dias), $s11Hoy));
/** Una fila de master.tenants de una empresa Express limpia (en testecf, con un tipo de venta y un certificado con fecha lejana); $o pisa lo que cada caso cambia. */
$s11Fila = static fn (array $o = []): array => $o + [
    'id' => 12, 'nombre' => 'Constructora X', 'rnc' => '131000001', 'tipo' => 'app', 'ambiente' => 'testecf',
    'edicion' => 'express', 'tipos_ecf_habilitados' => '45', 'modulos_habilitados' => null, 'pos_enabled' => 0,
    'cert_path' => 'certificado_dgii/131000001/cert.p12', 'cert_vence' => $s11Dia(120),
];
/** Una fila de ncf_sequences de ecf, en la forma que lee el verificador (la de los rangos_ecf de los hechos de la biblioteca). Sin $hasta, el rango no tiene limite. */
$s11Rango = static fn (string $tipo, ?int $hasta = 5000, int $actual = 10, ?string $vence = null): array => [
    'type' => $tipo, 'numero_hasta' => $hasta, 'current_value' => $actual, 'fecha_vencimiento' => $vence,
];
$s11Buenos = [$s11Rango('E45'), $s11Rango('E34')];   // lo que pide un tipo 45: el 45 y el 34 que se deriva de el

// --- express_problemas_tenant: una tabla de filas y los codigos exactos que devuelve (en el orden de la lista de codigos) ------------------------
$s11Casos = [
    'una fila Express limpia (testecf, tipo 45, sin POS, certificado con fecha lejana)' => [$s11Fila(), null, []],
    // La edicion: solo se revisa lo que no es completa, y un valor desconocido no suma los problemas de Express (la API lo trata como completa).
    "edicion '' (lo que MySQL guarda en un ENUM sin modo estricto)" => [$s11Fila(['edicion' => '']), null, ['EDICION_DESCONOCIDA']],
    "edicion 'basico'" => [$s11Fila(['edicion' => 'basico']), null, ['EDICION_DESCONOCIDA']],
    'una edicion desconocida con tipos vacios, POS y sin certificado: solo EDICION_DESCONOCIDA' => [$s11Fila(['edicion' => '', 'tipos_ecf_habilitados' => null, 'pos_enabled' => 1, 'cert_path' => null]), null, ['EDICION_DESCONOCIDA']],
    "edicion ' Express ' (con espacios y mayusculas la API la trata como express)" => [$s11Fila(['edicion' => ' Express ']), null, []],
    'una fila completa no se lista: sin tipos, con modulos mal escritos, con POS, sin certificado y sin rangos en ecf' => [
        $s11Fila(['edicion' => 'completa', 'tipos_ecf_habilitados' => null, 'modulos_habilitados' => 'cliente', 'pos_enabled' => 1, 'cert_path' => null, 'cert_vence' => null, 'ambiente' => 'ecf']), [], []],
    "una fila 'Completa' (con mayusculas) tampoco" => [$s11Fila(['edicion' => 'Completa', 'tipos_ecf_habilitados' => null]), null, []],
    'una fila sin la columna edicion (una master sin la 014) no se revisa' => [array_diff_key($s11Fila(['tipos_ecf_habilitados' => null]), ['edicion' => 1]), null, []],
    'una fila con edicion NULL no se revisa' => [$s11Fila(['edicion' => null, 'tipos_ecf_habilitados' => null]), null, []],
    // Los tipos de e-CF.
    'tipos NULL' => [$s11Fila(['tipos_ecf_habilitados' => null]), null, ['TIPOS_VACIOS']],
    "tipos ''" => [$s11Fila(['tipos_ecf_habilitados' => '']), null, ['TIPOS_VACIOS']],
    "tipos ' , ' (solo separadores)" => [$s11Fila(['tipos_ecf_habilitados' => ' , ']), null, ['TIPOS_VACIOS']],
    "tipos 'e45' (un typo)" => [$s11Fila(['tipos_ecf_habilitados' => 'e45']), null, ['TIPOS_INVALIDOS']],
    "tipos '45,44' (uno bueno y uno que Express no vende)" => [$s11Fila(['tipos_ecf_habilitados' => '45,44']), null, ['TIPOS_INVALIDOS']],
    "tipos 'E45' (se escriben exactos)" => [$s11Fila(['tipos_ecf_habilitados' => 'E45']), null, ['TIPOS_INVALIDOS']],
    "tipos '34' (la nota de credito se deriva sola, no se lista)" => [$s11Fila(['tipos_ecf_habilitados' => '34']), null, ['TIPOS_INVALIDOS']],
    "tipos '44' (un tipo que Express no vende)" => [$s11Fila(['tipos_ecf_habilitados' => '44']), null, ['TIPOS_INVALIDOS']],
    "tipos '45, 31,' (a mano, con espacios y coma al final)" => [$s11Fila(['tipos_ecf_habilitados' => '45, 31,']), null, []],
    "tipos '31,32,45,33' (todos)" => [$s11Fila(['tipos_ecf_habilitados' => '31,32,45,33']), null, []],
    "tipos '33' (solo la nota de debito: no puede vender)" => [$s11Fila(['tipos_ecf_habilitados' => '33']), null, ['TIPOS_SIN_VENTA']],
    "tipos '33,45'" => [$s11Fila(['tipos_ecf_habilitados' => '33,45']), null, []],
    "tipos '33,e45' (el 33 y un typo: no queda ningun tipo de venta)" => [$s11Fila(['tipos_ecf_habilitados' => '33,e45']), null, ['TIPOS_INVALIDOS', 'TIPOS_SIN_VENTA']],
    // Los modulos (RF2: un typo angosta, nunca ensancha).
    "modulos 'facturacion,cliente' (RF2: cliente por clientes)" => [$s11Fila(['modulos_habilitados' => 'facturacion,cliente']), null, ['MODULOS_INVALIDOS']],
    "modulos 'Facturacion' (se escriben exactos)" => [$s11Fila(['modulos_habilitados' => 'Facturacion']), null, ['MODULOS_INVALIDOS']],
    "modulos 'cliente' (no queda ninguno)" => [$s11Fila(['modulos_habilitados' => 'cliente']), null, ['MODULOS_INVALIDOS']],
    "modulos 'facturacion, clientes,' (a mano, con espacios y coma al final)" => [$s11Fila(['modulos_habilitados' => 'facturacion, clientes,']), null, []],
    "modulos '' (todo el preset)" => [$s11Fila(['modulos_habilitados' => '']), null, []],
    "modulos ' , ' (solo separadores: todo el preset)" => [$s11Fila(['modulos_habilitados' => ' , ']), null, []],
    // El POS.
    'pos_enabled 1' => [$s11Fila(['pos_enabled' => 1]), null, ['POS_ACTIVO']],
    "pos_enabled '1' (como lo da PDO sin tipos nativos)" => [$s11Fila(['pos_enabled' => '1']), null, ['POS_ACTIVO']],
    "pos_enabled '0'" => [$s11Fila(['pos_enabled' => '0']), null, []],
    // El grupo y la credencial de integracion (revision final). /api/integracion/* no pasa por los limites de Express (la ruta, los modulos y los tipos), y una empresa de
    // un grupo se alcanza con la credencial de cualquiera de sus hermanas (TenantResolver::switchToSibling): una fila Express con grupo_id o con su hash vivo se salta todo eso.
    'grupo_id 7 (una Express en un grupo)' => [$s11Fila(['grupo_id' => 7]), null, ['EN_GRUPO']],
    'grupo_id igual al id (la cabeza de un grupo)' => [$s11Fila(['grupo_id' => 12]), null, ['EN_GRUPO']],
    "grupo_id '7' (como lo da PDO sin tipos nativos)" => [$s11Fila(['grupo_id' => '7']), null, ['EN_GRUPO']],
    'grupo_id 0 (para TenantResolver::grupoId() es un grupo)' => [$s11Fila(['grupo_id' => 0]), null, ['EN_GRUPO']],
    'grupo_id NULL' => [$s11Fila(['grupo_id' => null]), null, []],
    "grupo_id ''" => [$s11Fila(['grupo_id' => '']), null, []],
    'api_secret_hash con un hash (la credencial de integracion sigue sirviendo)' => [$s11Fila(['api_secret_hash' => str_repeat('a', 64)]), null, ['INTEGRACION_ACTIVA']],
    "api_secret_hash '' (convertida: en blanco)" => [$s11Fila(['api_secret_hash' => '']), null, []],
    'api_secret_hash NULL' => [$s11Fila(['api_secret_hash' => null]), null, []],
    'grupo_id y api_secret_hash a la vez: los dos codigos, en el orden de la lista' => [$s11Fila(['grupo_id' => 7, 'api_secret_hash' => str_repeat('a', 64)]), null, ['EN_GRUPO', 'INTEGRACION_ACTIVA']],
    // Solo se juzga una Express de tipo app: una de integracion (aunque diga express) no esta restringida y su hash es lo que la autentica, y una completa o de edicion desconocida no es Express.
    "tipo 'integracion' (aunque diga express) con grupo y hash: ni EN_GRUPO ni INTEGRACION_ACTIVA" => [$s11Fila(['tipo' => 'integracion', 'grupo_id' => 7, 'api_secret_hash' => str_repeat('a', 64)]), null, []],
    'una fila completa con grupo y hash (un cliente con varias empresas): nada' => [$s11Fila(['edicion' => 'completa', 'grupo_id' => 7, 'api_secret_hash' => str_repeat('a', 64)]), null, []],
    'una edicion desconocida con grupo y hash: solo EDICION_DESCONOCIDA' => [$s11Fila(['edicion' => '', 'grupo_id' => 7, 'api_secret_hash' => str_repeat('a', 64)]), null, ['EDICION_DESCONOCIDA']],
    // El certificado: sin ruta, sin fecha y por vencer (a 30 dias o menos, o ya vencido).
    "cert_path ''" => [$s11Fila(['cert_path' => '']), null, ['SIN_CERTIFICADO']],
    'cert_path NULL' => [$s11Fila(['cert_path' => null]), null, ['SIN_CERTIFICADO']],
    'cert_path de solo espacios' => [$s11Fila(['cert_path' => '   ']), null, ['SIN_CERTIFICADO']],
    'sin cert_path, aunque cert_vence este a 5 dias: solo SIN_CERTIFICADO' => [$s11Fila(['cert_path' => '', 'cert_vence' => $s11Dia(5)]), null, ['SIN_CERTIFICADO']],
    'cert_vence NULL (con certificado)' => [$s11Fila(['cert_vence' => null]), null, ['CERT_SIN_FECHA']],
    "cert_vence ''" => [$s11Fila(['cert_vence' => '']), null, ['CERT_SIN_FECHA']],
    "cert_vence '0000-00-00' (la fecha cero de MySQL)" => [$s11Fila(['cert_vence' => '0000-00-00']), null, ['CERT_SIN_FECHA']],
    "cert_vence '2026-02-30' (no existe en el calendario)" => [$s11Fila(['cert_vence' => '2026-02-30']), null, ['CERT_SIN_FECHA']],
    "cert_vence 'pronto'" => [$s11Fila(['cert_vence' => 'pronto']), null, ['CERT_SIN_FECHA']],
    'cert_vence a 29 dias' => [$s11Fila(['cert_vence' => $s11Dia(29)]), null, ['CERT_POR_VENCER']],
    'cert_vence a 30 dias (el limite: todavia avisa)' => [$s11Fila(['cert_vence' => $s11Dia(30)]), null, ['CERT_POR_VENCER']],
    'cert_vence a 31 dias: nada' => [$s11Fila(['cert_vence' => $s11Dia(31)]), null, []],
    'cert_vence hoy' => [$s11Fila(['cert_vence' => $s11Dia(0)]), null, ['CERT_POR_VENCER']],
    'cert_vence ayer (ya vencio)' => [$s11Fila(['cert_vence' => $s11Dia(-1)]), null, ['CERT_POR_VENCER']],
    'cert_vence hace 400 dias' => [$s11Fila(['cert_vence' => $s11Dia(-400)]), null, ['CERT_POR_VENCER']],
    'cert_vence a un ano: nada' => [$s11Fila(['cert_vence' => $s11Dia(365)]), null, []],
    // Los rangos de ecf: solo con ambiente ecf y solo si se leyeron (null los salta); piden un rango autorizado para cada tipo que la empresa emite, el 34 derivado incluido.
    'ecf con los rangos sin leer (null): se saltan' => [$s11Fila(['ambiente' => 'ecf']), null, []],
    'ecf sin ningun rango' => [$s11Fila(['ambiente' => 'ecf']), [], ['RANGO_FALTANTE']],
    'ecf con el E45 y sin el E34 que se deriva' => [$s11Fila(['ambiente' => 'ecf']), [$s11Rango('E45')], ['RANGO_FALTANTE']],
    'ecf con el E45 y el E34 acotados' => [$s11Fila(['ambiente' => 'ecf']), $s11Buenos, []],
    'ecf con un E45 sin limite como unico rango: no es un rango autorizado y ademas esta sin limite' => [$s11Fila(['ambiente' => 'ecf']), [$s11Rango('E45', null)], ['RANGO_FALTANTE', 'RANGO_ILIMITADO']],
    'ecf con los dos rangos acotados y ademas un E31 sin limite' => [$s11Fila(['ambiente' => 'ecf']), array_merge($s11Buenos, [$s11Rango('E31', null)]), ['RANGO_ILIMITADO']],
    'ecf con el E45 agotado (current_value igual a numero_hasta)' => [$s11Fila(['ambiente' => 'ecf']), [$s11Rango('E45', 5000, 5000), $s11Rango('E34')], ['RANGO_FALTANTE']],
    'ecf con el E45 vencido ayer' => [$s11Fila(['ambiente' => 'ecf']), [$s11Rango('E45', 5000, 10, $s11Dia(-1)), $s11Rango('E34')], ['RANGO_FALTANTE']],
    'ecf con el E45 que vence hoy (todavia vale)' => [$s11Fila(['ambiente' => 'ecf']), [$s11Rango('E45', 5000, 10, $s11Dia(0)), $s11Rango('E34')], []],
    'testecf sin rangos: los rangos solo se revisan en ecf' => [$s11Fila(['ambiente' => 'testecf']), [], []],
    "ambiente 'ECF ' (mayusculas y espacio) cuenta como ecf" => [$s11Fila(['ambiente' => 'ECF ']), [], ['RANGO_FALTANTE']],
    'ecf con el 33 listado: tambien pide el E33' => [$s11Fila(['ambiente' => 'ecf', 'tipos_ecf_habilitados' => '45,33']), $s11Buenos, ['RANGO_FALTANTE']],
    'ecf con el 33 listado y los tres rangos acotados' => [$s11Fila(['ambiente' => 'ecf', 'tipos_ecf_habilitados' => '45,33']), array_merge($s11Buenos, [$s11Rango('E33')]), []],
    'ecf sin tipos: no hay tipo que pida rango, solo TIPOS_VACIOS' => [$s11Fila(['ambiente' => 'ecf', 'tipos_ecf_habilitados' => null]), [], ['TIPOS_VACIOS']],
    'ecf sin tipos y con un rango sin limite: TIPOS_VACIOS y RANGO_ILIMITADO' => [$s11Fila(['ambiente' => 'ecf', 'tipos_ecf_habilitados' => null]), [$s11Rango('E31', null)], ['TIPOS_VACIOS', 'RANGO_ILIMITADO']],
    // Varios a la vez: en el orden de la lista de codigos.
    'tipos vacios, POS prendido y sin certificado (lo que dice el plan)' => [$s11Fila(['tipos_ecf_habilitados' => null, 'pos_enabled' => 1, 'cert_path' => null]), null, ['TIPOS_VACIOS', 'POS_ACTIVO', 'SIN_CERTIFICADO']],
];
$s11Vistos = [];
foreach ($s11Casos as $s11Desc => [$s11Row, $s11Rangos, $s11Esperados]) {
    $s11Obtenidos = array_column(express_problemas_tenant($s11Row, $s11Rangos, $s11Hoy), 'codigo');
    $s11Vistos = array_merge($s11Vistos, $s11Obtenidos);
    wa_chk("express_problemas_tenant: {$s11Desc}: " . ($s11Esperados === [] ? 'sin problemas' : implode(', ', $s11Esperados)), $s11Obtenidos === $s11Esperados, $s11Obtenidos);
}
$s11Codigos = ['EDICION_DESCONOCIDA', 'TIPOS_VACIOS', 'TIPOS_INVALIDOS', 'TIPOS_SIN_VENTA', 'MODULOS_INVALIDOS', 'POS_ACTIVO', 'EN_GRUPO', 'INTEGRACION_ACTIVA', 'SIN_CERTIFICADO', 'CERT_SIN_FECHA', 'CERT_POR_VENCER', 'RANGO_FALTANTE', 'RANGO_ILIMITADO'];
wa_chk('la tabla ejercita los trece codigos de la lista y ninguno mas', array_values(array_unique($s11Vistos)) !== [] && array_diff($s11Codigos, $s11Vistos) === [] && array_diff($s11Vistos, $s11Codigos) === [],
    ['sin ejercitar' => array_values(array_diff($s11Codigos, $s11Vistos)), 'de mas' => array_values(array_diff($s11Vistos, $s11Codigos))]);

// --- la forma de lo que devuelve: dos claves, un texto ASCII de una linea que dice lo que pasa y nada de lo secreto que trae la fila -------------------
$s11Todo = $s11Fila([
    'tipos_ecf_habilitados' => '33,e45', 'modulos_habilitados' => 'cliente', 'pos_enabled' => 1, 'grupo_id' => 7, 'cert_vence' => $s11Dia(5), 'ambiente' => 'ecf',
    'cert_path' => 'MARCA-RUTA-DEL-CERTIFICADO.p12', 'cert_pass_encrypted' => 'MARCA-CLAVE-DEL-CERTIFICADO', 'db_pass_encrypted' => 'MARCA-CLAVE-DE-LA-BASE',
    'api_secret_hash' => 'MARCA-HASH-DE-INTEGRACION', 'db_user' => 'MARCA-USUARIO-DE-LA-BASE', 'db_host' => 'MARCA-SERVIDOR-DE-LA-BASE',
]);
$s11Problemas = express_problemas_tenant($s11Todo, [$s11Rango('E31', null)], $s11Hoy);
wa_chk('una fila con todo mal devuelve los nueve codigos que le tocan, en el orden de la lista (con el grupo y con el hash de integracion de la fila vivo)',
    array_column($s11Problemas, 'codigo') === ['TIPOS_INVALIDOS', 'TIPOS_SIN_VENTA', 'MODULOS_INVALIDOS', 'POS_ACTIVO', 'EN_GRUPO', 'INTEGRACION_ACTIVA', 'CERT_POR_VENCER', 'RANGO_FALTANTE', 'RANGO_ILIMITADO'], array_column($s11Problemas, 'codigo'));
$s11MalFormados = [];
foreach (array_merge($s11Problemas, express_problemas_tenant($s11Fila(['edicion' => '']), null, $s11Hoy), express_problemas_tenant($s11Fila(['tipos_ecf_habilitados' => null, 'cert_path' => null]), null, $s11Hoy),
    express_problemas_tenant($s11Fila(['cert_vence' => null]), null, $s11Hoy)) as $s11P) {
    if (array_keys($s11P) !== ['codigo', 'detalle'] || !is_string($s11P['codigo']) || !is_string($s11P['detalle'])
        || preg_match('/^[\x20-\x7E]{20,600}$/D', $s11P['detalle']) !== 1) {
        $s11MalFormados[] = $s11P;
    }
}
wa_chk('cada problema trae solo codigo y detalle, y el detalle es un texto de una linea en ASCII imprimible (de 20 a 600 caracteres)', $s11MalFormados === [], $s11MalFormados);
wa_chk('ningun detalle lleva la ruta ni la clave del certificado, la clave, el usuario o el servidor de la base ni el hash de integracion de la fila',
    !str_contains(implode("\n", array_column($s11Problemas, 'detalle')), 'MARCA-'), array_column($s11Problemas, 'detalle'));
$s11Detalle = static fn (array $problemas, string $codigo): string => (string) (array_column($problemas, 'detalle', 'codigo')[$codigo] ?? '');
wa_chk('los detalles dicen lo que hay que saber: los tokens que sobran, lo que queda de los modulos, la fecha y los dias que faltan, el tipo sin rango y los que no tienen limite',
    str_contains($s11Detalle($s11Problemas, 'TIPOS_INVALIDOS'), "'e45'") && str_contains($s11Detalle($s11Problemas, 'TIPOS_INVALIDOS'), '31, 32, 45, 33')
    && str_contains($s11Detalle($s11Problemas, 'MODULOS_INVALIDOS'), "'cliente'") && str_contains($s11Detalle($s11Problemas, 'MODULOS_INVALIDOS'), 'ninguno')
    && str_contains($s11Detalle($s11Problemas, 'CERT_POR_VENCER'), $s11Dia(5)) && str_contains($s11Detalle($s11Problemas, 'CERT_POR_VENCER'), '5 dias')
    && str_contains($s11Detalle($s11Problemas, 'RANGO_FALTANTE'), 'E33') && str_contains($s11Detalle($s11Problemas, 'RANGO_ILIMITADO'), "'E31'"), $s11Problemas);
wa_chk('EN_GRUPO nombra el grupo (#7) y dice que una empresa Express no puede estar en uno; INTEGRACION_ACTIVA dice que /api/integracion/* no pasa por los limites de Express y como dejar el hash en blanco (tools/tenant_edicion.php --tenant=12 --edicion=express); ninguno trae el valor del hash',
    str_contains($s11Detalle($s11Problemas, 'EN_GRUPO'), '#7') && str_contains($s11Detalle($s11Problemas, 'EN_GRUPO'), 'Express') && str_contains($s11Detalle($s11Problemas, 'EN_GRUPO'), 'grupo')
    && str_contains($s11Detalle($s11Problemas, 'INTEGRACION_ACTIVA'), '/api/integracion/') && str_contains($s11Detalle($s11Problemas, 'INTEGRACION_ACTIVA'), 'tools/tenant_edicion.php --tenant=12 --edicion=express')
    && !str_contains($s11Detalle($s11Problemas, 'EN_GRUPO') . $s11Detalle($s11Problemas, 'INTEGRACION_ACTIVA'), 'MARCA-'), [$s11Detalle($s11Problemas, 'EN_GRUPO'), $s11Detalle($s11Problemas, 'INTEGRACION_ACTIVA')]);
$s11Angosto = express_problemas_tenant($s11Fila(['modulos_habilitados' => 'facturacion,cliente']), null, $s11Hoy);
wa_chk("MODULOS_INVALIDOS con 'facturacion,cliente' dice que la empresa queda con facturacion (lo que de verdad rige)",
    str_contains($s11Detalle($s11Angosto, 'MODULOS_INVALIDOS'), "'cliente'") && str_contains($s11Detalle($s11Angosto, 'MODULOS_INVALIDOS'), 'queda con facturacion'), $s11Angosto);
$s11Vencido = express_problemas_tenant($s11Fila(['cert_vence' => $s11Dia(-3)]), null, $s11Hoy);
wa_chk('CERT_POR_VENCER con un certificado ya vencido dice la fecha y cuantos dias hace, y no la ruta',
    str_contains($s11Detalle($s11Vencido, 'CERT_POR_VENCER'), $s11Dia(-3)) && str_contains($s11Detalle($s11Vencido, 'CERT_POR_VENCER'), 'hace 3 dias'), $s11Vencido);
$s11Desconocida = express_problemas_tenant($s11Fila(['edicion' => '']), null, $s11Hoy);
wa_chk("EDICION_DESCONOCIDA nombra el valor ('') y dice que la API la trata como completa", str_contains($s11Detalle($s11Desconocida, 'EDICION_DESCONOCIDA'), "''") && str_contains($s11Detalle($s11Desconocida, 'EDICION_DESCONOCIDA'), 'completa'), $s11Desconocida);

// --- es pura: no imprime ni escribe en el log (ni con una edicion desconocida: el aviso del log lo da quien arma el informe) y da lo mismo cada vez ---
ob_start();
[$s11Dos, $s11Error, $s11Log] = wa_con_log(static fn (): array => [
    express_problemas_tenant($s11Fila(['edicion' => 'zz-pura']), null, $s11Hoy), express_problemas_tenant($s11Todo, [$s11Rango('E31', null)], $s11Hoy),
]);
$s11Salida = (string) ob_get_clean();
wa_chk('express_problemas_tenant es pura: no imprime, no escribe en el log (ni con una edicion desconocida) y da lo mismo cada vez',
    $s11Error === null && $s11Salida === '' && $s11Log === '' && ($s11Dos[1] ?? null) === $s11Problemas
    && array_column($s11Dos[0] ?? [], 'codigo') === ['EDICION_DESCONOCIDA'], [$s11Error?->getMessage(), $s11Salida, $s11Log]);
$s11Fuente = str_replace("\r\n", "\n", (string) file_get_contents($s11Herramienta));
$s11Cuerpo = preg_match('/\nfunction express_problemas_tenant\(.*?\n\}\n/s', $s11Fuente, $s11M) === 1 ? $s11M[0] : '';
wa_chk('express_problemas_tenant se declara una sola vez con la firma del contrato, y su cuerpo no toca una base, un archivo, el log ni la salida',
    substr_count($s11Fuente, 'function express_problemas_tenant(') === 1 && $s11Cuerpo !== ''
    && $s8Firma('express_problemas_tenant') === 'express_problemas_tenant(array $tenant, ?array $rangosEcf, int $hoy): array'
    && preg_match('/\b(error_log|echo|print|printf|fwrite|file_get_contents|file_put_contents|normalizarEdicion|prepare|execute|query|PDO)\b/', $s8SinTextos("<?php\n" . $s11Cuerpo)) !== 1,
    $s11Cuerpo === '' ? 'no se encontro la funcion' : $s8Firma('express_problemas_tenant'));
// La muestra de arriba tiene dientes: la misma busqueda sobre un cuerpo que si imprime y escribe en el log lo encuentra (sin la etiqueta <?php el escaneo no veria codigo).
$s11Sucio = "<?php\nfunction x() { echo 1; error_log('x'); \$p->prepare('x'); }";
wa_chk('la busqueda de efectos de arriba tiene dientes: encuentra echo, error_log y prepare en un cuerpo que los usa',
    preg_match_all('/\b(error_log|echo|print|printf|fwrite|file_get_contents|file_put_contents|normalizarEdicion|prepare|execute|query|PDO)\b/', $s8SinTextos($s11Sucio), $s11Halladas) === 3, $s11Halladas[0] ?? []);

// --- la consulta 4 de tools/verificar_migraciones_master.sql: comentada, con su encabezado y la consulta del contrato ----------------------------
$s11Sql = $s1Leer('tools/verificar_migraciones_master.sql');
$s11LineasSql = explode("\n", $s11Sql);
$s11Inicio = null;
foreach ($s11LineasSql as $s11I => $s11L) {
    if (str_contains($s11L, 'Consulta 4')) {
        $s11Inicio = $s11I;
        break;
    }
}
$s11Bloque = $s11Inicio === null ? [] : array_slice($s11LineasSql, $s11Inicio);
$s11Cierre = null;   // la ultima linea de guiones del bloque: cierra el encabezado, y lo que sigue es la consulta
foreach ($s11Bloque as $s11I => $s11L) {
    if (preg_match('/^-- -{20,}$/', rtrim($s11L)) === 1) {
        $s11Cierre = $s11I;
    }
}
$s11Encabezado = $s11Cierre === null ? '' : implode("\n", array_slice($s11Bloque, 0, $s11Cierre));
$s11Consulta = $s11Cierre === null ? '' : trim((string) preg_replace('/\s+/', ' ', implode("\n", array_map(
    static fn (string $l): string => (string) preg_replace('/^-- ?/', '', $l),
    array_filter(array_slice($s11Bloque, $s11Cierre + 1), static fn (string $l): bool => trim($l) !== '')
))));
wa_chk("el verificador de la master trae una Consulta 4 con la condicion WHERE edicion <> 'completa'", $s11Inicio !== null && str_contains($s11Sql, "WHERE edicion <> 'completa'"), $s11Inicio);
wa_chk('la Consulta 4 va escrita como la 2: un encabezado de guiones que dice que se corra SOLO cuando la fila 014 dice APLICADA, y despues la consulta, toda comentada',
    $s11Encabezado !== '' && str_contains($s11Encabezado, 'SOLO') && str_contains($s11Encabezado, '014') && str_contains($s11Encabezado, 'APLICADA')
    && array_filter($s11Bloque, static fn (string $l): bool => trim($l) !== '' && !str_starts_with(ltrim($l), '--')) === [], array_values(array_filter($s11Bloque, static fn (string $l): bool => trim($l) !== '' && !str_starts_with(ltrim($l), '--'))));
wa_chk('la consulta de la Consulta 4 es la del contrato, sin cambiar una coma',
    $s11Consulta === "SELECT id, nombre, rnc, edicion, tipos_ecf_habilitados, modulos_habilitados, pos_enabled, cert_vence, (tipos_ecf_habilitados IS NULL OR TRIM(tipos_ecf_habilitados) = '') AS tipos_vacios FROM tenants WHERE edicion <> 'completa' ORDER BY id;",
    $s11Consulta);
// Ni una linea con codigo del archivo (sin las lineas "--" ni lo que va entre comillas) nombra una columna de la 014: una corrida en una master sin ellas no da error,
// y la fila 014 de arriba las nombra solo como texto (information_schema). Son tambien las lineas que lee tools/check_tenant_schema_orden.php.
$s11Codigo = (string) preg_replace("/'(?:[^']|'')*'/", "''", (string) preg_replace('/^\s*--.*$/m', '', $s11Sql));
wa_chk('el codigo del verificador (sin comentarios ni textos) no nombra edicion, modulos_habilitados, tipos_ecf_habilitados ni cert_vence: corre igual en una master sin la 014',
    $s11Sql !== '' && preg_match('/\b(edicion|modulos_habilitados|tipos_ecf_habilitados|cert_vence)\b/i', $s11Codigo) !== 1, $s11Codigo);
wa_chk('la fila 014 y la fila 012 del verificador siguen como estaban (no se tocaron)',
    preg_match("/SELECT '014', '014_tenants_edicion\\.sql',\\s*'columnas tenants\\.edicion, modulos_habilitados, tipos_ecf_habilitados y cert_vence',\\s*"
        . "\\(SELECT COUNT\\(\\*\\) FROM information_schema\\.COLUMNS\\s+WHERE TABLE_SCHEMA = DATABASE\\(\\) AND TABLE_NAME = 'tenants'\\s+"
        . "AND COLUMN_NAME IN \\('edicion', 'modulos_habilitados', 'tipos_ecf_habilitados', 'cert_vence'\\)\\) = 4/", $s11Sql) === 1
    && preg_match("/SELECT '012', '012_pos\\.sql',/", $s11Sql) === 1);
wa_chk('la Consulta 3 sigue siendo la ultima consulta que se ejecuta (la que lee verificar_migraciones_tenant.sql): despues de ella solo hay comentarios',
    preg_match('/\nSELECT id, nombre, rnc, tipo, db_name FROM tenants ORDER BY id;\n(?:\s*(?:--[^\n]*)?\n)*\z/', $s11Sql . "\n") === 1);

// --- la herramienta: lo que no se mueve y lo que tiene que estar (la fuente), el php limpio y el hijo aislado --------------------------------------------
wa_chk('verificar_express.php: empieza en <?php sin BOM, carga MasterDatabase.php y la biblioteca con require_once, y nunca lee $_GET, $_POST, $_REQUEST ni $_COOKIE',
    str_starts_with($s11Fuente, '<?php') && preg_match_all("~require_once __DIR__ \\. '/(\\.\\./src/MasterDatabase|express_edicion_lib)\\.php';~", $s11Fuente) === 2
    && !preg_match('/\$_(GET|POST|REQUEST|COOKIE)\b/', $s11Fuente), $s11Fuente === '' ? 'sin fuente' : null);
$s11PosGuarda = strpos($s11Fuente, "realpath((string) (\$_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__");
$s11PosCli = strpos($s11Fuente, "PHP_SAPI !== 'cli'");
$s11PosMain = strpos($s11Fuente, 'exit(express_verificar_main())');
wa_chk('verificar_express.php: el programa principal corre solo si es este archivo el que se ejecuta, y un navegador recibe un 403 en lugar de la lista de empresas (el directorio tools del hosting se puede abrir)',
    $s11PosGuarda !== false && $s11PosCli !== false && $s11PosMain !== false && $s11PosGuarda < $s11PosCli && $s11PosCli < $s11PosMain
    && substr_count($s11Fuente, 'exit(express_verificar_main())') === 1 && str_contains($s11Fuente, 'http_response_code(403)'), [$s11PosGuarda, $s11PosCli, $s11PosMain]);
$s11Literales = [];
foreach (token_get_all($s11Fuente) as $s11T) {
    if (is_array($s11T) && in_array($s11T[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
        $s11Literales[] = trim($s11T[1], "'\" \n\t\r");
    }
}
$s11DeSql = array_values(array_filter($s11Literales, static fn (string $l): bool => preg_match('/^(SELECT|INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|CREATE|TRUNCATE|GRANT|REVOKE|RENAME|CALL|SET|LOCK|UNLOCK|START|BEGIN|COMMIT|ROLLBACK|SHOW|DESCRIBE|EXPLAIN|USE)\b/i', $l) === 1));
$s11Escribe = [];
foreach (token_get_all($s11Fuente) as $s11I => $s11T) {
    if (is_array($s11T) && $s11T[0] === T_STRING && in_array(strtolower($s11T[1]), ['exec', 'begintransaction', 'commit', 'rollback'], true)) {
        $s11Escribe[] = $s11T[1] . ' (linea ' . $s11T[2] . ')';
    }
}
wa_chk('verificar_express.php es de solo lectura: toda sentencia SQL que trae es un SELECT, y no llama exec, beginTransaction, commit ni rollBack',
    $s11DeSql !== [] && array_filter($s11DeSql, static fn (string $l): bool => preg_match('/^SELECT\b/i', $l) !== 1) === [] && $s11Escribe === [], [$s11DeSql, $s11Escribe]);
$s11Vuelca = [];
foreach (token_get_all($s11Fuente) as $s11T) {
    if (is_array($s11T) && $s11T[0] === T_STRING && in_array(strtolower($s11T[1]), ['print_r', 'var_dump', 'var_export', 'debug_zval_dump', 'phpinfo', 'getenv', 'putenv'], true)) {
        $s11Vuelca[] = $s11T[1] . ' (linea ' . $s11T[2] . ')';
    }
}
wa_chk('verificar_express.php no vuelca variables ni toca el entorno (ni print_r, var_dump, var_export, phpinfo, getenv ni putenv)', $s11Vuelca === [], $s11Vuelca);

// Un php limpio: incluirla no imprime, no abre ninguna conexion y no corre el programa principal (el hijo llega a imprimir su JSON).
$s11Hijo = (string) tempnam(sys_get_temp_dir(), 'ent_ver_');
file_put_contents($s11Hijo, <<<'PHP'
<?php
// Hijo de la seccion 11: incluye la herramienta en un php limpio, dentro de un buffer, y cuenta lo que dejo.
error_reporting(E_ALL);
ini_set('display_errors', '1');
ob_start();
require $argv[1];
$salida = (string) ob_get_clean();
$instancia = static fn (string $clase): bool => class_exists($clase, false) && (new ReflectionProperty($clase, 'instance'))->getValue() !== null;
echo json_encode([
    'salida' => $salida,
    'database' => $instancia('Database'),
    'master' => $instancia('MasterDatabase'),
    'credenciales' => class_exists('Database', false) && Database::hasCredentials(),
    'funciones' => array_values(array_filter(get_defined_functions()['user'], static fn (string $f): bool => str_starts_with($f, 'express_verificar') || $f === 'express_problemas_tenant')),
]);
PHP);
$s11R = wa_correr([PHP_BINARY, $s11Hijo, $s11Herramienta]);
@unlink($s11Hijo);
$s11J = json_decode(trim($s11R['salida']), true);
wa_chk('incluir la herramienta en un php limpio no imprime nada, no abre conexion (Database y MasterDatabase sin instancia, Database sin credenciales) y declara express_problemas_tenant, express_verificar y express_verificar_main',
    is_array($s11J) && $s11J['salida'] === '' && $s11J['database'] === false && $s11J['master'] === false && $s11J['credenciales'] === false
    && array_diff(['express_problemas_tenant', 'express_verificar', 'express_verificar_main'], $s11J['funciones']) === [], $s11R);
// Corrida directamente, en el hijo aislado (la master apunta a un puerto de loopback sin nadie): no lleva opciones, intenta abrir la master y, al no poder, dice [FALLO] con la
// linea de express_describir_fallo (la misma politica de tenant_edicion.php) y sale con 1, sin usuario, servidor ni clave y sin avisos de PHP.
$s11R = express_hijo_aislado(['-d', 'display_errors=1', '-d', 'error_reporting=-1', 'tools/verificar_express.php']);
wa_chk('verificar_express.php corrida directamente con la master inalcanzable: sale con 1 con UNA linea [FALLO] RuntimeException (MasterDatabase.php:N) que dice que no pudo conectar, sin usuario, servidor ni clave, sin la linea que MasterDatabase manda al log y sin avisos de PHP',
    $s11R['codigo'] === 1 && preg_match('/^\[FALLO\] RuntimeException \(MasterDatabase\.php:\d+\): No se pudo conectar a la base de datos maestra\.\r?$/m', $s11R['salida']) === 1
    && substr_count($s11R['salida'], '[FALLO]') === 1 && !preg_match('/127\.0\.0\.1|aislado_usuario|aislado_clave|MasterDB|Stack trace|Warning|Notice|Deprecated|Fatal/', $s11R['salida']), $s11R);

// --- express_verificar: el informe, contra un master de mentira (el PDO de guion de la seccion 9: solo prepare y execute) ---------------------------------
$s11Maestro = static function (array $filas, ?array $columnas = null): ExpPdoGuion {
    $columnas ??= array_merge(['id', 'nombre', 'rnc', 'tipo', 'ambiente', 'pos_enabled', 'cert_path'], EXPRESS_COLUMNAS_VERIFICADAS);
    $master = new ExpPdoGuion('master', new ExpGuionLog());
    $master->respuesta = static function (string $sql) use ($filas, $columnas): array {
        if (str_contains($sql, 'information_schema.COLUMNS')) {
            return array_map(static fn (string $c): array => ['COLUMN_NAME' => $c], $columnas);
        }
        if (str_contains($sql, 'FROM tenants')) {
            return $filas;
        }
        throw new LogicException('consulta inesperada al master de mentira de la seccion 11: ' . $sql);
    };
    return $master;
};
$s11Lee = static fn (ExpPdoGuion $master): array => array_values(array_map(
    static fn (array $evento): string => $evento[2],
    array_filter($master->log->eventos, static fn (array $evento): bool => $evento[1] === 'execute')
));
wa_chk('express_verificar(PDO $master, int $hoy): array es la firma del informe', $s8Firma('express_verificar') === 'express_verificar(PDO $master, int $hoy): array', $s8Firma('express_verificar'));

$s11A = $s11Fila(['id' => 7, 'nombre' => 'POS Prueba A', 'tipos_ecf_habilitados' => null, 'pos_enabled' => 1, 'cert_path' => null]);
$s11Raro = $s11Fila(['id' => 8, 'nombre' => 'Raro', 'edicion' => 'zz-verificador']);
$s11Limpia = $s11Fila(['id' => 9, 'nombre' => 'Limpia']);
$s11M = $s11Maestro([$s11A, $s11Raro, $s11Limpia]);
[$s11Informe, $s11Error, $s11Log] = wa_con_log(static fn (): array => express_verificar($s11M, $s11Hoy));
$s11Lineas = is_array($s11Informe) ? $s11Informe['lineas'] : [];
wa_chk('el informe lista una linea por problema, "tenant #<id> (<nombre>): <CODIGO> <detalle>", en orden de empresa y de codigo, y sale con 1; la empresa limpia no sale',
    $s11Error === null && ($s11Informe['codigo'] ?? null) === 1 && count($s11Lineas) === 4
    && str_starts_with($s11Lineas[0] ?? '', 'tenant #7 (POS Prueba A): TIPOS_VACIOS ') && str_starts_with($s11Lineas[1] ?? '', 'tenant #7 (POS Prueba A): POS_ACTIVO ')
    && str_starts_with($s11Lineas[2] ?? '', 'tenant #7 (POS Prueba A): SIN_CERTIFICADO ') && str_starts_with($s11Lineas[3] ?? '', 'tenant #8 (Raro): EDICION_DESCONOCIDA ')
    && !str_contains(implode("\n", $s11Lineas), 'tenant #9'), [$s11Error?->getMessage(), $s11Informe]);
wa_chk('el informe escribe en el log UNA linea por cada valor de edicion desconocido (la politica de normalizarEdicion: se trata como completa y se avisa), con el valor',
    substr_count($s11Log, 'zz-verificador') === 1 && substr_count($s11Log, "\n") === 1 && str_contains($s11Log, 'se trata como completa'), $s11Log);
wa_chk("el informe lee las empresas con una sola consulta, un SELECT con WHERE edicion <> 'completa' y ORDER BY id, y no escribe nada (ni una sentencia que no sea SELECT)",
    count(array_filter($s11Lee($s11M), static fn (string $sql): bool => str_contains($sql, 'FROM tenants'))) === 1
    && count(array_filter($s11Lee($s11M), static fn (string $sql): bool => str_contains($sql, "FROM tenants WHERE edicion <> 'completa' ORDER BY id"))) === 1
    && $s11M->escritas === [] && !$s11M->enTransaccion, $s11Lee($s11M));
foreach (['una empresa Express limpia' => [$s11Limpia], 'ninguna empresa que no sea completa' => []] as $s11Desc => $s11Filas) {
    $s11M = $s11Maestro($s11Filas);
    [$s11Informe, $s11Error] = wa_con_log(static fn (): array => express_verificar($s11M, $s11Hoy));
    wa_chk("express_verificar con {$s11Desc}: imprime Sin problemas. y sale con 0", $s11Error === null && $s11Informe === ['codigo' => 0, 'lineas' => ['Sin problemas.']], [$s11Error?->getMessage(), $s11Informe]);
}
$s11Agrupada = $s11Fila(['id' => 14, 'nombre' => 'Agrupada', 'grupo_id' => 14, 'api_secret_hash' => str_repeat('b', 64)]);
$s11M = $s11Maestro([$s11Agrupada, $s11Limpia]);
[$s11Informe, $s11Error] = wa_con_log(static fn (): array => express_verificar($s11M, $s11Hoy));
$s11LineasG = is_array($s11Informe) ? $s11Informe['lineas'] : [];
wa_chk('el informe nombra a una empresa Express que esta en un grupo y conserva su credencial de integracion: dos lineas (EN_GRUPO e INTEGRACION_ACTIVA), salida 1, sin el valor del hash y sin listar a la limpia',
    $s11Error === null && ($s11Informe['codigo'] ?? null) === 1 && count($s11LineasG) === 2
    && str_starts_with($s11LineasG[0] ?? '', 'tenant #14 (Agrupada): EN_GRUPO ') && str_starts_with($s11LineasG[1] ?? '', 'tenant #14 (Agrupada): INTEGRACION_ACTIVA ')
    && !str_contains(implode("\n", $s11LineasG), str_repeat('b', 64)) && !str_contains(implode("\n", $s11LineasG), 'tenant #9'), [$s11Error?->getMessage(), $s11Informe]);
$s11M = $s11Maestro([$s11A], ['id', 'nombre', 'edicion', 'tipos_ecf_habilitados']);
[$s11Informe, $s11Error] = wa_con_log(static fn (): array => express_verificar($s11M, $s11Hoy));
wa_chk('sin dos de las columnas de la 014 (modulos_habilitados y cert_vence) sale con 2, nombra db/master_migrations/014_tenants_edicion.sql y las columnas que faltan, y ni siquiera lee tenants',
    $s11Error === null && ($s11Informe['codigo'] ?? null) === 2 && count($s11Informe['lineas'] ?? []) === 1
    && str_contains($s11Informe['lineas'][0] ?? '', 'db/master_migrations/014_tenants_edicion.sql') && str_contains($s11Informe['lineas'][0] ?? '', 'Faltan en tenants: modulos_habilitados, cert_vence.')
    && count(array_filter($s11Lee($s11M), static fn (string $sql): bool => str_contains($sql, 'FROM tenants'))) === 0, [$s11Error?->getMessage(), $s11Informe]);
$s11M = $s11Maestro([$s11Fila(['id' => 5, 'nombre' => "Mala\n\x1b[31mPe\xc3\xb1a " . str_repeat('x', 200), 'tipos_ecf_habilitados' => null])]);
[$s11Informe] = wa_con_log(static fn (): array => express_verificar($s11M, $s11Hoy));
wa_chk('el nombre de la empresa sale en una sola linea, en ASCII imprimible y acotado (sin saltos de linea, escapes ni acentos de bytes sueltos): viene de una base y se escribio a mano',
    count($s11Informe['lineas'] ?? []) === 1 && preg_match('/^tenant #5 \([\x20-\x7E]{1,60}\): TIPOS_VACIOS [\x20-\x7E]+$/D', $s11Informe['lineas'][0] ?? '') === 1, $s11Informe);
// Solo una empresa Express, de tipo app y en ecf abre su propia base para leer los rangos: una de integracion, una de edicion desconocida o una en testecf no.
foreach ([
    'una de integracion (aunque diga express)' => $s11Fila(['id' => 21, 'tipo' => 'integracion', 'ambiente' => 'ecf', 'tipos_ecf_habilitados' => null]),
    'una de edicion desconocida' => $s11Fila(['id' => 22, 'edicion' => 'zz-verificador', 'ambiente' => 'ecf']),
    'una en testecf' => $s11Fila(['id' => 23, 'ambiente' => 'testecf', 'tipos_ecf_habilitados' => null]),
] as $s11Desc => $s11Row) {
    $s11M = $s11Maestro([$s11Row]);
    [$s11Informe, $s11Error] = wa_con_log(static fn (): array => express_verificar($s11M, $s11Hoy));
    wa_chk("express_verificar no abre la base de {$s11Desc}: da su informe sin intentar conectar", $s11Error === null && ($s11Informe['codigo'] ?? null) === 1 && count($s11Informe['lineas'] ?? []) >= 1,
        [$s11Error === null ? null : get_class($s11Error) . ': ' . $s11Error->getMessage(), $s11Informe]);
}
// La que si la abre: una Express de tipo app en ecf intenta conectar con express_conectar_tenant. Esta fila no trae db_host, db_name ni db_user: el intento se ve en la excepcion.
$s11M = $s11Maestro([$s11Fila(['id' => 24, 'ambiente' => 'ecf'])]);
[, $s11Error] = wa_con_log(static fn (): array => express_verificar($s11M, $s11Hoy));
wa_chk('una empresa Express de tipo app en ecf SI abre su base (express_conectar_tenant): sin db_host, db_name y db_user en la fila lanza InvalidArgumentException, que el programa principal imprime como [FALLO]',
    $s11Error instanceof InvalidArgumentException && str_contains($s11Error->getMessage(), '#24'), $s11Error === null ? null : get_class($s11Error) . ': ' . $s11Error->getMessage());
$s11Hallado = $s8Ocho3($s8SinTextos($s11Fuente));
wa_chk('PHP 8.3 (produccion y CI): verificar_express.php no usa funciones ni sintaxis de PHP 8.4 o 8.5', $s11Hallado === [], $s11Hallado);
$s11Lint = wa_correr([PHP_BINARY, '-d', 'error_reporting=-1', '-d', 'display_errors=1', '-l', $s11Herramienta]);
wa_chk('php -l de verificar_express.php: sin errores de sintaxis ni deprecaciones', $s11Lint['codigo'] === 0 && str_starts_with(trim($s11Lint['salida']), 'No syntax errors detected')
    && !str_contains($s11Lint['salida'], 'Deprecated'), $s11Lint);

// --- la conexion a la master: UNA copia, la de la biblioteca (express_conectar_master) --------------------------------------------------------------------
// tenant_edicion.php y verificar_express.php abren la master con la misma precaucion: cuando la conexion falla, MasterDatabase deja en el log el usuario y el servidor, y en la consola eso saldria
// por stderr. La precaucion vive en un solo lugar, como express_describir_fallo: se prueba la funcion misma (en un php hijo aislado: MasterDatabase es un singleton y el log del proceso de la
// suite no es suyo) y las dos herramientas la llaman sin tener copia propia. El camino que sale bien, con una master de verdad, lo prueba la seccion 4 de la suite scratch.
$s11Biblioteca = str_replace("\r\n", "\n", (string) file_get_contents(__DIR__ . '/express_edicion_lib.php'));
$s11Edicion = str_replace("\r\n", "\n", (string) file_get_contents(__DIR__ . '/tenant_edicion.php'));
$s11Existe = function_exists('express_conectar_master');
$s11CMaster = preg_match('/\nfunction express_conectar_master\(string \$prefijo\): PDO\n\{.*?\n\}\n/s', $s11Biblioteca, $s11CuerpoM) === 1 ? $s11CuerpoM[0] : '';
$s11Efectos = '/\b(tempnam|ini_set)\s*\(/';
wa_chk('express_edicion_lib.php: express_conectar_master(string $prefijo): PDO se declara una sola vez y es el unico lugar de la biblioteca que crea un archivo temporal y cambia error_log (un tempnam y dos ini_set: desviar y restaurar)',
    $s11Existe && $s8Firma('express_conectar_master') === 'express_conectar_master(string $prefijo): PDO'
    && substr_count($s11Biblioteca, 'function express_conectar_master(') === 1 && $s11CMaster !== ''
    && preg_match_all($s11Efectos, $s8SinTextos("<?php\n" . $s11CMaster)) === 3 && preg_match_all($s11Efectos, $s8SinTextos($s11Biblioteca)) === 3,
    $s11Existe ? [$s8Firma('express_conectar_master'), $s11CMaster === '' ? 'no se encontro su cuerpo' : 'con cuerpo'] : 'la funcion no existe');
// La funcion misma, con la master inalcanzable, en un php hijo aislado (puerto 1 de loopback) y con un directorio temporal propio y vacio (-d sys_temp_dir): lanza la RuntimeException de texto fijo,
// lo que MasterDatabase manda al log al fallar (el usuario y el servidor) no llega a ninguna parte visible, error_log queda donde estaba y el directorio temporal queda vacio. En Windows tempnam solo
// usa las tres primeras letras del prefijo, asi que "no queda un archivo con el prefijo" no probaria nada: se mira el directorio entero.
$s11Privado = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ent_master_' . bin2hex(random_bytes(6));
mkdir($s11Privado);
$s11LogPropio = (string) tempnam(sys_get_temp_dir(), 'ent_mlog_');
$s11HijoMaster = (string) tempnam(sys_get_temp_dir(), 'ent_mhijo_');
file_put_contents($s11HijoMaster, <<<'PHP'
<?php
// Hijo de la seccion 11: llama a express_conectar_master con la master inalcanzable y cuenta lo que dejo.
error_reporting(E_ALL);
ini_set('display_errors', '1');
require $argv[1];
ini_set('error_log', $argv[2]);   // el log que el proceso tenia antes de conectar: lo que la funcion tiene que dejar como estaba
$lanzo = null;
try {
    express_conectar_master('ent_hijo_');
} catch (Throwable $e) {
    $lanzo = [get_class($e), $e->getMessage()];
}
echo "\n", json_encode([
    'lanzo' => $lanzo,
    'log_actual' => ini_get('error_log'),
    'log_propio' => (string) @file_get_contents($argv[2]),
    'temporal' => sys_get_temp_dir(),
    'restos' => array_values(array_diff(scandir(sys_get_temp_dir()) ?: [], ['.', '..'])),
    'instancia' => (new ReflectionProperty('MasterDatabase', 'instance'))->getValue() !== null,
]), "\n";
PHP);
$s11RM = express_hijo_aislado(['-d', 'sys_temp_dir=' . $s11Privado, $s11HijoMaster, __DIR__ . '/express_edicion_lib.php', $s11LogPropio]);
foreach (scandir($s11Privado) ?: [] as $s11Resto) {   // lo que el hijo haya dejado (si algo falla), fuera: la suite no ensucia el temp
    if ($s11Resto !== '.' && $s11Resto !== '..') {
        @unlink($s11Privado . DIRECTORY_SEPARATOR . $s11Resto);
    }
}
@rmdir($s11Privado);
@unlink($s11LogPropio);
@unlink($s11HijoMaster);
$s11LineasM = array_values(array_filter(preg_split('/\R/', trim($s11RM['salida'])) ?: [], static fn (string $l): bool => $l !== ''));
$s11JM = json_decode((string) end($s11LineasM), true);
$s11Normal = static fn (string $ruta): string => str_replace('\\', '/', rtrim($ruta, '/\\'));
// Cada prueba de abajo exige antes que la funcion SI corrio y fallo como se espera: sin eso, "no dejo nada" se cumpliria tambien cuando la funcion ni existe (el hijo muere antes de llamarla).
$s11Corrio = is_array($s11JM) && $s11JM['lanzo'] === ['RuntimeException', 'No se pudo conectar a la base de datos maestra.'];
wa_chk('express_conectar_master con la master inalcanzable (hijo aislado): lanza la RuntimeException de texto fijo "No se pudo conectar a la base de datos maestra." y no deja una instancia de MasterDatabase',
    $s11Corrio && $s11JM['instancia'] === false, $s11RM);
wa_chk('...no deja pasar la linea que MasterDatabase manda al log al fallar (el usuario y el servidor): ni al log que el proceso tenia, ni a stderr ni a la salida',
    $s11Corrio && $s11JM['log_propio'] === '' && count($s11LineasM) === 1
    && !preg_match('/127\.0\.0\.1|aislado_usuario|aislado_clave|MasterDB|Stack trace|Warning|Notice|Deprecated|Fatal/', $s11RM['salida']), $s11RM);
wa_chk('...deja error_log donde estaba (el del proceso antes de conectar) y no deja el archivo temporal: su directorio temporal propio queda vacio',
    $s11Corrio && $s11JM['log_actual'] === $s11LogPropio && $s11Normal($s11JM['temporal']) === $s11Normal($s11Privado) && $s11JM['restos'] === [], $s11RM);
// Y las dos herramientas la llaman, cada una con su prefijo, sin tener copia propia: ni la funcion de antes, ni un tempnam, un ini_set, un unlink o un MasterDatabase::getInstance suyos.
foreach ([
    'tenant_edicion.php' => [$s11Edicion, 'tenant_edicion_', 'tenant_edicion_conectar_master'],
    'verificar_express.php' => [$s11Fuente, 'verificar_express_', 'express_verificar_conectar_master'],
] as $s11Quien => [$s11Texto, $s11Prefijo, $s11Viejo]) {
    $s11Codigo2 = $s8SinTextos($s11Texto);   // sin comentarios ni textos: un comentario puede nombrar la funcion, el codigo la llama una sola vez
    wa_chk("{$s11Quien}: abre la master con express_conectar_master (la de la biblioteca) y su propio prefijo, una sola vez, y no tiene copia propia (ni {$s11Viejo}, ni tempnam, ini_set, unlink ni MasterDatabase::getInstance)",
        substr_count($s11Texto, "express_conectar_master('{$s11Prefijo}')") === 1 && preg_match_all('/\bexpress_conectar_master\b/', $s11Codigo2) === 1
        && !str_contains($s11Texto, $s11Viejo) && preg_match('/\b(tempnam|ini_set|sys_get_temp_dir|unlink|getInstance)\b/', $s11Codigo2) !== 1,
        ['con_su_prefijo' => substr_count($s11Texto, "express_conectar_master('{$s11Prefijo}')"), 'en_el_codigo' => preg_match_all('/\bexpress_conectar_master\b/', $s11Codigo2), 'funcion_vieja' => str_contains($s11Texto, $s11Viejo)]);
}

// ---------------------------------------------------------------------------
// Cierre. Cada tarea agrega su seccion justo encima de este bloque.
// ---------------------------------------------------------------------------
wa_fin();
