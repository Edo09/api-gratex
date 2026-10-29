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
 *   (--hijo=validar --escenario=<base64 de un JSON> es el modo hijo de la seccion 4: lo usa la propia suite, no se llama a mano)
 */

require_once __DIR__ . '/write_ahead_test_lib.php';

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

if (wa_arg('hijo') === 'validar') {
    ent_hijo_validar((array) json_decode((string) base64_decode((string) wa_arg('escenario')), true));
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

// ---------------------------------------------------------------------------
// Cierre. Cada tarea agrega su seccion justo encima de este bloque.
// ---------------------------------------------------------------------------
wa_fin();
