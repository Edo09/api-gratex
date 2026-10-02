<?php
/**
 * check_tenant_schema_orden.php — Que db/tenant_schema.sql se pueda aplicar tal
 * cual a una DB vacia, sin tener MySQL a mano.
 *
 * El snapshot NO desactiva FOREIGN_KEY_CHECKS (tools/create_tenant.php lo corre
 * en un solo exec), asi que una FK hacia una tabla que se crea MAS ABAJO hace
 * fallar el alta de un tenant nuevo, y eso solo se descubriria en el server.
 * Este script lo revisa leyendo el texto:
 *
 *   - Cada REFERENCES apunta a una tabla creada ANTES (o a la misma tabla).
 *   - Ninguna tabla se crea dos veces.
 *   - El bloque de cotizaciones trae el estado final de la migracion 026
 *     (columnas, nulabilidad, indices y FKs con los mismos nombres).
 *   - La 026 nombra esos mismos indices y FKs, cada PREPARE tiene su EXECUTE y
 *     su DEALLOCATE PREPARE, y su SQL dinamico, armado con tipos de ejemplo, es
 *     un ALTER/CREATE con parentesis y comillas balanceados.
 *
 * No reemplaza aplicar el snapshot y la 026 a una DB de verdad; solo atrapa en
 * local lo que mas facil se rompe al mover bloques o al escribir SQL dinamico.
 *
 * Uso:
 *   php tools/check_tenant_schema_orden.php            (sale con 1 si algo falla)
 *   php tools/check_tenant_schema_orden.php --mostrar  (ademas imprime el SQL dinamico de la 026)
 */

$raiz = dirname(__DIR__);
$rutaSnapshot = $raiz . '/db/tenant_schema.sql';
$rutaMigracion = $raiz . '/db/migrations/026_cotizaciones_formatos.sql';

$fallos = 0;
$total = 0;
$chk = function (string $desc, bool $ok) use (&$fallos, &$total) {
    $total++;
    if (!$ok) {
        $fallos++;
    }
    printf("  [%s] %s\n", $ok ? 'OK  ' : 'FALLO', $desc);
};

/**
 * Quita los comentarios de linea completa (-- ...), para que un REFERENCES o un
 * nombre citado en un comentario no cuente. El snapshot no tiene "--" despues
 * de codigo en la misma linea ni comentarios de bloque.
 */
function sinComentarios(string $sql): string
{
    $sql = str_replace("\r\n", "\n", $sql);
    return (string) preg_replace('/^[ \t]*--.*$/m', '', $sql);
}

/**
 * Parte el cuerpo de un CREATE TABLE en sus elementos (columnas, KEY,
 * CONSTRAINT...) por las comas de nivel 0: las de DECIMAL(18,2) o las de un
 * COMMENT '...' no cortan. Cada elemento sale con espacios colapsados.
 * @return string[]
 */
function elementos(string $cuerpo): array
{
    $partes = [];
    $actual = '';
    $nivel = 0;
    $enComilla = false;
    $largo = strlen($cuerpo);
    for ($i = 0; $i < $largo; $i++) {
        $c = $cuerpo[$i];
        if ($enComilla) {
            $actual .= $c;
            if ($c === "'") {
                // '' dentro de un literal es una comilla escapada, no el cierre.
                if ($i + 1 < $largo && $cuerpo[$i + 1] === "'") {
                    $actual .= "'";
                    $i++;
                } else {
                    $enComilla = false;
                }
            }
            continue;
        }
        if ($c === "'") {
            $enComilla = true;
        } elseif ($c === '(') {
            $nivel++;
        } elseif ($c === ')') {
            $nivel--;
        } elseif ($c === ',' && $nivel === 0) {
            $partes[] = $actual;
            $actual = '';
            continue;
        }
        $actual .= $c;
    }
    $partes[] = $actual;
    $limpias = [];
    foreach ($partes as $p) {
        $p = trim((string) preg_replace('/\s+/', ' ', str_replace('`', '', $p)));
        if ($p !== '') {
            $limpias[] = $p;
        }
    }
    return $limpias;
}

/**
 * Tablas del snapshot en el orden en que se crean.
 * @return array<int,array{nombre:string, elementos:string[]}>
 */
function tablas(string $sql): array
{
    preg_match_all(
        '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?\s*\((.*?)\)\s*ENGINE\s*=/is',
        $sql,
        $m,
        PREG_SET_ORDER
    );
    $out = [];
    foreach ($m as $t) {
        $out[] = ['nombre' => strtolower($t[1]), 'elementos' => elementos($t[2])];
    }
    return $out;
}

/** Definicion de una columna sin su nombre ("VARCHAR(100) NULL ..."), o null si no esta. */
function definicion(array $elementos, string $columna): ?string
{
    foreach ($elementos as $e) {
        $primera = strtolower(strtok($e, ' '));
        if ($primera === strtolower($columna)) {
            return trim(substr($e, strlen($columna)));
        }
    }
    return null;
}

/** El elemento (KEY, CONSTRAINT...) esta, comparando sin mayusculas ni espacios de mas. */
function tieneElemento(array $elementos, string $esperado): bool
{
    $esperado = strtoupper((string) preg_replace('/\s+/', ' ', trim($esperado)));
    foreach ($elementos as $e) {
        if (strtoupper($e) === $esperado) {
            return true;
        }
    }
    return false;
}

if (!is_file($rutaSnapshot)) {
    fwrite(STDERR, "No existe {$rutaSnapshot}\n");
    exit(1);
}
$crudo = (string) file_get_contents($rutaSnapshot);
$sql = sinComentarios($crudo);
$lista = tablas($sql);

echo "== Orden de creacion (cada FK apunta a una tabla creada antes) ==\n";
$posicion = [];
foreach ($lista as $i => $t) {
    // Si se repite, se queda la primera: es la que vale al aplicar el snapshot.
    if (!isset($posicion[$t['nombre']])) {
        $posicion[$t['nombre']] = $i;
    }
}
$fksVistas = 0;
foreach ($lista as $i => $t) {
    foreach ($t['elementos'] as $e) {
        if (!preg_match('/^(?:CONSTRAINT\s+(\w+)\s+)?FOREIGN\s+KEY\s*\([^)]*\)\s*REFERENCES\s+(\w+)/i', $e, $fk)) {
            continue;
        }
        $fksVistas++;
        $nombreFk = $fk[1] !== '' ? $fk[1] : '(sin nombre)';
        $destino = strtolower($fk[2]);
        $ok = $destino === $t['nombre'] || (isset($posicion[$destino]) && $posicion[$destino] < $i);
        $chk(
            "FK {$nombreFk}: {$t['nombre']} -> {$destino}"
                . ($ok ? '' : (isset($posicion[$destino]) ? ' (se crea DESPUES)' : ' (no se crea en el snapshot)')),
            $ok
        );
    }
}
// Un REFERENCES fuera de un CREATE TABLE (ej. un ALTER TABLE ... ADD CONSTRAINT)
// no lo revisa este script: avisar en vez de darlo por bueno.
$chk(
    'todo REFERENCES esta dentro de un CREATE TABLE',
    preg_match_all('/\bREFERENCES\s+`?\w+`?\s*\(/i', $sql) === $fksVistas
);

echo "\n== Tablas unicas ==\n";
$conteo = array_count_values(array_column($lista, 'nombre'));
$repetidas = array_keys(array_filter($conteo, fn($n) => $n > 1));
$chk('ninguna tabla se crea dos veces' . ($repetidas ? ' (repetidas: ' . implode(', ', $repetidas) . ')' : ''), $repetidas === []);

echo "\n== Cotizaciones: estado final de la migracion 026 ==\n";
$chk('cabecera: rango de migraciones 012..026', (bool) preg_match('/012\.\.026/', $crudo));

$porNombre = [];
foreach ($lista as $t) {
    $porNombre[$t['nombre']] ??= $t['elementos'];
}
// Tipo + nulabilidad que debe quedar en cada columna (despues de colapsar espacios).
$columnas = [
    'cotizaciones' => [
        'client_name' => '/^VARCHAR\(100\) NULL\b/i',
        'user_id'     => '/^INT\(11\) NULL\b/i',
        'updated_at'  => '/^DATETIME NULL\b/i',
        'formato'     => '/^VARCHAR\(40\) NULL\b/i',
        'numero'      => '/^INT UNSIGNED NULL\b/i',
        'subtotal'    => '/^DECIMAL\(18,2\) NULL\b/i',
        'itbis'       => '/^DECIMAL\(18,2\) NULL\b/i',
    ],
    'cotizacion_items' => [
        'product_id'              => '/^INT\(11\) NULL\b/i',
        'unidad_medida'           => '/^VARCHAR\(10\) NULL\b/i',
        'indicador_facturacion'   => '/^TINYINT NULL\b/i',
        'indicador_bien_servicio' => '/^TINYINT NULL\b/i',
        'itbis_amount'            => '/^DECIMAL\(18,2\) NULL\b/i',
    ],
    'cotizacion_ajustes' => [
        'id'            => '/^INT\(11\) NOT NULL AUTO_INCREMENT\b/i',
        'cotizacion_id' => '/^INT\(11\) NOT NULL\b/i',
        'concepto'      => '/^VARCHAR\(30\) NOT NULL\b/i',
        'monto'         => '/^DECIMAL\(18,2\) NOT NULL DEFAULT 0\.00\b/i',
    ],
];
foreach ($columnas as $tabla => $cols) {
    $chk("tabla {$tabla} existe", isset($porNombre[$tabla]));
    foreach ($cols as $col => $patron) {
        $def = definicion($porNombre[$tabla] ?? [], $col);
        $chk("{$tabla}.{$col}" . ($def === null ? ' (falta)' : " = {$def}"), $def !== null && preg_match($patron, $def) === 1);
    }
}
$indices = [
    'cotizaciones' => [
        'UNIQUE KEY uk_cotizaciones_numero (numero)',
    ],
    'cotizacion_items' => [
        'KEY idx_cotizacion_items_product (product_id)',
        'CONSTRAINT cotizacion_items_ibfk_1 FOREIGN KEY (cotizacion_id) REFERENCES cotizaciones (id) ON DELETE CASCADE',
        'CONSTRAINT cotizacion_items_product_fk FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE SET NULL',
    ],
    'cotizacion_ajustes' => [
        'UNIQUE KEY uk_cotizacion_ajuste (cotizacion_id, concepto)',
        'CONSTRAINT cotizacion_ajustes_cot_fk FOREIGN KEY (cotizacion_id) REFERENCES cotizaciones (id) ON DELETE CASCADE',
    ],
];
foreach ($indices as $tabla => $lst) {
    foreach ($lst as $esperado) {
        $chk("{$tabla}: {$esperado}", tieneElemento($porNombre[$tabla] ?? [], $esperado));
    }
}

echo "\n== Migracion 026 ==\n";
$hayMigracion = is_file($rutaMigracion);
$chk('existe db/migrations/026_cotizaciones_formatos.sql', $hayMigracion);
$mig = $hayMigracion ? sinComentarios((string) file_get_contents($rutaMigracion)) : '';
// Mismos nombres que el snapshot: si difieren, un tenant nuevo y uno migrado
// quedan con indices distintos y la proxima migracion no sabe cual buscar.
foreach (['uk_cotizaciones_numero', 'idx_cotizacion_items_product', 'cotizacion_items_product_fk',
          'uk_cotizacion_ajuste', 'cotizacion_ajustes_cot_fk', 'cotizacion_ajustes'] as $nombre) {
    $chk("026 nombra {$nombre}", $mig !== '' && preg_match('/\b' . $nombre . '\b/', $mig) === 1);
}
preg_match_all('/^\s*PREPARE\s+(\w+)\s+FROM\s+(@\w+)\s*;/mi', $mig, $prep);
preg_match_all('/^\s*EXECUTE\s+(\w+)\s*;/mi', $mig, $exec);
preg_match_all('/^\s*DEALLOCATE\s+PREPARE\s+(\w+)\s*;/mi', $mig, $deal);
$chk('026: cada PREPARE tiene su EXECUTE y su DEALLOCATE (' . count($prep[1]) . ' sentencias)',
    $prep[1] !== [] && $prep[1] === $exec[1] && $prep[1] === $deal[1]);
$chk('026: ningun nombre de sentencia preparada se repite', count($prep[1]) === count(array_unique($prep[1])));

// Sin MySQL no se puede preparar el SQL dinamico, pero si armarlo: cada
// SET @sql_x := IF(cond, <sql>, 'DO 0') se expande con tipos de ejemplo (los
// que daria una DB creada desde el repo) y se revisa que sea un ALTER/CREATE
// de las tablas de cotizaciones con parentesis y comillas balanceados. Para
// leerlo entero: php tools/check_tenant_schema_orden.php --mostrar
$ejemplo = [
    '@tipo_product_id' => 'int(11)',
    '@tipo_cot_id'     => 'int(11)',
    '@cn_definicion'   => 'varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL',
];
preg_match_all("/SET\s+(@sql_\w+)\s*:=\s*IF\(@\w+\s*=\s*[01],(.*?),\s*'DO 0'\);/s", $mig, $dinamicos, PREG_SET_ORDER);
$chk('026: cada @sql_* se prepara una vez (' . count($dinamicos) . ' sentencias dinamicas)',
    $dinamicos !== [] && array_column($dinamicos, 1) === $prep[2]);
foreach ($dinamicos as [, $variable, $expresion]) {
    // Tokens de la expresion: literales '...' ('' = comilla escapada) o @variables.
    preg_match_all("/'((?:[^']|'')*)'|(@\w+)/", $expresion, $tokens, PREG_SET_ORDER);
    $ddl = '';
    $desconocida = null;
    foreach ($tokens as $tok) {
        if (isset($tok[2]) && $tok[2] !== '') {
            $desconocida = isset($ejemplo[$tok[2]]) ? $desconocida : $tok[2];
            $ddl .= $ejemplo[$tok[2]] ?? '';
        } else {
            $ddl .= str_replace("''", "'", $tok[1]);
        }
    }
    $sinLiterales = (string) preg_replace("/'(?:[^']|'')*'/", "''", $ddl);
    $ok = $desconocida === null
        && preg_match('/^(ALTER TABLE (cotizaciones|cotizacion_items) |CREATE TABLE IF NOT EXISTS cotizacion_ajustes \()/', $ddl) === 1
        && substr_count($ddl, "'") % 2 === 0
        && substr_count($sinLiterales, '(') === substr_count($sinLiterales, ')');
    $chk("026: {$variable} arma un DDL valido" . ($desconocida !== null ? " (variable sin ejemplo: {$desconocida})" : ''), $ok);
    if (in_array('--mostrar', $argv, true)) {
        echo "         {$ddl}\n";
    }
}

printf("\n%d/%d OK\n", $total - $fallos, $total);
exit($fallos === 0 ? 0 : 1);
