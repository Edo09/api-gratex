<?php
/**
 * check_estado_dgii_ancho.php — Que todo estado_dgii que el codigo puede
 * escribir quepa en la columna, sin MySQL a mano (migracion 028).
 *
 * facturas.estado_dgii y gastos.estado_dgii eran VARCHAR(20), pero el codigo
 * escribe valores mas largos DESPUES de que la DGII ya recibio el e-CF:
 *   - 'RFCE_' . estado  (ECFEmissionService, flujo RFCE E32 < 250k)
 *     -> RFCE_ACEPTADO_CONDICIONAL = 25
 *   - CONCAT(estado, '_ARCHIVADO') al reutilizar un numero rechazado
 *     (facturaModel / gastoModel) -> RFCE_RECHAZADO_ARCHIVADO = 24,
 *     NO_ENCONTRADO_ARCHIVADO = 23
 * En modo estricto eso es "Data too long" y la factura se pierde ("se envio a
 * la DGII, pero no se pudo guardar"); sin modo estricto, el estado se trunca.
 *
 * Revisa leyendo el texto:
 *   - Los estados base salen del codigo (returns de mapEstado y literales de
 *     gastoModel), se combinan con el prefijo RFCE_ y el sufijo _ARCHIVADO que
 *     el codigo realmente usa, y el mas largo cabe en el ancho del snapshot.
 *   - El snapshot y la migracion 028 usan el mismo ancho y el mismo COMMENT.
 *   - La 028 toca las dos columnas, cada PREPARE tiene su EXECUTE y su
 *     DEALLOCATE PREPARE, y el verificador de migraciones tiene la fila 028.
 *
 * No reemplaza correr la 028 en una DB de verdad.
 *
 * Uso:
 *   php tools/check_estado_dgii_ancho.php      (sale con 1 si algo falla)
 */

$raiz = dirname(__DIR__);
$leer = static function (string $rel) use ($raiz): string {
    $ruta = $raiz . '/' . $rel;
    return is_file($ruta) ? str_replace("\r\n", "\n", (string) file_get_contents($ruta)) : '';
};

$fallos = 0;
$total = 0;
$chk = function (string $desc, bool $ok, string $detalle = '') use (&$fallos, &$total) {
    $total++;
    if (!$ok) {
        $fallos++;
    }
    printf("  [%s] %s\n", $ok ? 'OK  ' : 'FALLO', $desc);
    if (!$ok && $detalle !== '') {
        echo "         {$detalle}\n";
    }
};

$servicio = $leer('src/Utils/FacturacionElectronica/ECFEmissionService.php');
$facturaModel = $leer('src/Models/facturaModel.php');
$gastoModel = $leer('src/Models/gastoModel.php');
$snapshot = $leer('db/tenant_schema.sql');
$migracion = $leer('db/migrations/028_estado_dgii_ancho.sql');
$verificador = $leer('tools/verificar_migraciones_tenant.sql');
$readme = $leer('db/migrations/README.md');

// ---------------------------------------------------------------------------
// 1) Estados que el codigo puede escribir
// ---------------------------------------------------------------------------
$base = [];
if (preg_match('/function mapEstado\(.*?\n    \}\n/s', $servicio, $m)) {
    preg_match_all("/return '([A-Z_]+)'/", $m[0], $r);
    $base = array_merge($base, $r[1]);
}
$chk('mapEstado: se leyeron sus estados', count($base) >= 5, 'estados: ' . implode(', ', $base));

// gastos: literales asignados a estado_dgii (REGISTRADO, PENDIENTE_EMISION, ERROR...)
preg_match_all("/\['estado_dgii'\]\s*=\s*'([A-Z_]+)'/", $gastoModel, $r);
$base = array_values(array_unique(array_merge($base, $r[1])));

$prefijoRfce = (bool) preg_match("/'estado'\s*=>\s*'RFCE_'\s*\.\s*\\\$rfceEstado/", $servicio);
$chk('el flujo RFCE antepone RFCE_ al estado', $prefijoRfce);
$sufijoFact = str_contains($facturaModel, "CONCAT(estado_dgii, '_ARCHIVADO')");
$sufijoGasto = str_contains($gastoModel, "CONCAT(estado_dgii, '_ARCHIVADO')");
$chk('facturas y gastos archivan con el sufijo _ARCHIVADO', $sufijoFact && $sufijoGasto);

$posibles = [];
foreach ($base as $e) {
    foreach (['', $prefijoRfce ? 'RFCE_' : ''] as $pre) {
        foreach (['', ($sufijoFact || $sufijoGasto) ? '_ARCHIVADO' : ''] as $suf) {
            $posibles[$pre . $e . $suf] = true;
        }
    }
}
$posibles = array_keys($posibles);
usort($posibles, fn($a, $b) => strlen($b) <=> strlen($a));
$maximo = $posibles !== [] ? strlen($posibles[0]) : 0;
echo "         mas largo: {$posibles[0]} ({$maximo})\n";

// ---------------------------------------------------------------------------
// 2) Ancho y COMMENT en el snapshot
// ---------------------------------------------------------------------------
$columna = static function (string $sql, string $tabla): ?array {
    if (!preg_match('/CREATE TABLE IF NOT EXISTS ' . $tabla . ' \((.*?)\n\) ENGINE/s', $sql, $t)) {
        return null;
    }
    if (!preg_match("/\n\s*estado_dgii\s+VARCHAR\((\d+)\)\s+NOT NULL DEFAULT '([A-Z_]+)'\s+COMMENT '([^']*)'/", $t[1], $c)) {
        return null;
    }
    return ['ancho' => (int) $c[1], 'default' => $c[2], 'comment' => $c[3]];
};
$snapF = $columna($snapshot, 'facturas');
$snapG = $columna($snapshot, 'gastos');
$chk('snapshot: facturas.estado_dgii legible', $snapF !== null);
$chk('snapshot: gastos.estado_dgii legible', $snapG !== null);
$anchoF = $snapF['ancho'] ?? 0;
$anchoG = $snapG['ancho'] ?? 0;
$chk("snapshot: facturas.estado_dgii ({$anchoF}) >= estado mas largo ({$maximo})", $anchoF >= $maximo);
$chk("snapshot: gastos.estado_dgii ({$anchoG}) >= estado mas largo ({$maximo})", $anchoG >= $maximo);
$chk('snapshot: defaults intactos (PENDIENTE / REGISTRADO)',
    ($snapF['default'] ?? '') === 'PENDIENTE' && ($snapG['default'] ?? '') === 'REGISTRADO');

// ---------------------------------------------------------------------------
// 3) Migracion 028
// ---------------------------------------------------------------------------
$chk('028: el archivo existe', $migracion !== '');
$sinComentarios = (string) preg_replace('/^[ \t]*--.*$/m', '', $migracion);
// phpMyAdmin, despues de un SELECT sobre information_schema, cambia la base
// actual a information_schema para lo que sigue (2026-10-05: "#1109 Unknown
// table 'FACTURAS' in information_schema"). La 028 fija la base en @db en la
// PRIMERA sentencia y nombra todo con ella; el unico SELECT de nivel superior
// es el resultado final.
$sentencias = array_values(array_filter(
    array_map('trim', preg_split('/;\s*\n/', $sinComentarios)),
    fn($s) => $s !== ''
));
$chk('028: la primera sentencia fija la base en @db', ($sentencias[0] ?? '') === 'SET @db := DATABASE()',
    'primera sentencia: ' . substr((string) ($sentencias[0] ?? ''), 0, 60));
$chk('028: DATABASE() aparece una sola vez', substr_count($sinComentarios, 'DATABASE()') === 1);
$chk('028: cada consulta a information_schema filtra por TABLE_SCHEMA = @db',
    preg_match_all('/information_schema\.COLUMNS/', $sinComentarios) > 0
    && preg_match_all('/information_schema\.COLUMNS/', $sinComentarios) === preg_match_all('/TABLE_SCHEMA = @db/', $sinComentarios));
$selects = array_keys(array_filter($sentencias, fn($s) => stripos($s, 'SELECT') === 0));
$chk('028: el unico SELECT de nivel superior es la ultima sentencia',
    $selects === [count($sentencias) - 1], 'SELECT en las sentencias: ' . implode(',', $selects));
$chk('028: las filas truncadas se cuentan en las tablas de @db',
    preg_match_all("/INTO @[fg]_truncadas FROM `', @db, '`\.(facturas|gastos) /", $sinComentarios) === 2);
foreach (['facturas' => $snapF, 'gastos' => $snapG] as $tabla => $snap) {
    $chk("028: modifica {$tabla}.estado_dgii nombrando la base",
        (bool) preg_match("/ALTER TABLE `', @db, '`\.{$tabla} MODIFY estado_dgii /", $sinComentarios));
    $chk("028: solo actua si {$tabla} tiene menos de " . ($snap['ancho'] ?? '?'),
        (bool) preg_match("/TABLE_NAME = '{$tabla}'.*?COLUMN_NAME = 'estado_dgii'.*?CHARACTER_MAXIMUM_LENGTH < " . ($snap['ancho'] ?? -1) . '/s', $sinComentarios));
    $chk("028: {$tabla} usa el mismo COMMENT que el snapshot",
        $snap !== null && str_contains($sinComentarios, "''" . str_replace("'", "''''", $snap['comment']) . "''"));
}
preg_match_all('/VARCHAR\((\d+)\)/', $sinComentarios, $anchos);
$chk('028: el ancho nuevo es el del snapshot', $anchos[1] !== [] && count(array_unique($anchos[1])) === 1
    && (int) $anchos[1][0] === $anchoF && $anchoF === $anchoG, 'anchos en la 028: ' . implode(',', $anchos[1]));
preg_match_all('/PREPARE (\w+) FROM/', $sinComentarios, $prep);
preg_match_all('/EXECUTE (\w+);/', $sinComentarios, $exec);
preg_match_all('/DEALLOCATE PREPARE (\w+);/', $sinComentarios, $deal);
$chk('028: cada PREPARE tiene EXECUTE y DEALLOCATE (' . count($prep[1]) . ')',
    $prep[1] !== [] && $prep[1] === $exec[1] && $prep[1] === $deal[1]);
$chk('028: MariaDB (default entre comillas) y MySQL (sin comillas)',
    str_contains($sinComentarios, "LEFT(COLUMN_DEFAULT, 1) = ''''"));
$chk('028: no cambia la nulabilidad', str_contains($sinComentarios, "IF(IS_NULLABLE = 'NO', ' NOT NULL', ' NULL')"));

// ---------------------------------------------------------------------------
// 4) Verificador y README
// ---------------------------------------------------------------------------
$chk('verificador: tiene la fila 028',
    (bool) preg_match("/SELECT '028', '028_estado_dgii_ancho\.sql'/", $verificador));
$chk('verificador: la fila 028 mira las dos columnas con CHARACTER_MAXIMUM_LENGTH >= ' . $anchoF,
    substr_count($verificador, "COLUMN_NAME = 'estado_dgii' AND CHARACTER_MAXIMUM_LENGTH >= {$anchoF}") === 2);
$chk('verificador: muestra en que base se evaluo', str_contains($verificador, 'DATABASE() AS base'));
$chk('README: el rango de migraciones activas llega a la 028', str_contains($readme, '012–028'));
$chk('README: advierte el cambio de base de phpMyAdmin', str_contains($readme, 'SET @db := DATABASE();'));
$chk('snapshot: el encabezado dice 012..028', str_contains($snapshot, '012..028'));

printf("\n%d/%d OK\n", $total - $fallos, $total);
exit($fallos === 0 ? 0 : 1);
