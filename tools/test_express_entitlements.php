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
wa_chk('README de migraciones: en el master, 008, 010, 011 y 014 son las idempotentes con PREPARE/EXECUTE',
    str_contains($s1Leer('db/migrations/README.md'), 'en el master, 008, 010, 011 y 014'));
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
// Cierre. Cada tarea agrega su seccion justo encima de este bloque.
// ---------------------------------------------------------------------------
wa_fin();
