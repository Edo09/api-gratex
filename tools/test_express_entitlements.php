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
 */

require_once __DIR__ . '/write_ahead_test_lib.php';

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
ob_start();
require_once __DIR__ . '/../src/TenantEntitlements.php';
$s2Config = TenantEntitlements::config();
$s2Salida = (string) ob_get_clean();
wa_chk('incluir la clase y cargar config/editions.php no imprime nada', $s2Salida === '', $s2Salida);

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
// Cierre. Cada tarea agrega su seccion justo encima de este bloque.
// ---------------------------------------------------------------------------
wa_fin();
