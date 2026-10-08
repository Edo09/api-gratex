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
 *   - La cabecera dice el rango 012..NNN, con NNN la migracion mas alta de
 *     db/migrations/ (una migracion nueva sin reflejar en el snapshot falla).
 *   - El bloque de cotizaciones trae el estado final de la migracion 026 y el
 *     de conduces el de la 030 (columnas, nulabilidad, indices y FKs con los
 *     mismos nombres, y la fila de conduce_secuencia).
 *   - La 026 y la 030 nombran esos mismos indices y FKs, cada PREPARE tiene su
 *     EXECUTE y su DEALLOCATE PREPARE, y su SQL dinamico, armado con tipos de
 *     ejemplo, es un ALTER/CREATE (o la fila de la secuencia) con parentesis y
 *     comillas balanceados.
 *   - La 030 sigue la regla de phpMyAdmin (db/migrations/README.md, ver 028):
 *     la primera sentencia es SET @db := DATABASE(), toda consulta a
 *     information_schema filtra por @db, el unico SELECT de nivel superior es
 *     el ultimo, y sus tres tablas armadas son las del snapshot elemento por
 *     elemento. El verificador de migraciones tiene su fila (y la de la 029 de
 *     precios, que deja products.precio .. precio_4 en DECIMAL(18,4)).
 *
 * No reemplaza aplicar el snapshot, la 026 y la 030 a una DB de verdad; solo
 * atrapa en local lo que mas facil se rompe al mover bloques o al escribir SQL
 * dinamico.
 *
 * Uso:
 *   php tools/check_tenant_schema_orden.php            (sale con 1 si algo falla)
 *   php tools/check_tenant_schema_orden.php --mostrar  (ademas imprime el SQL dinamico de la 026 y la 030)
 */

$raiz = dirname(__DIR__);
$rutaSnapshot = $raiz . '/db/tenant_schema.sql';
$rutaMigracion = $raiz . '/db/migrations/026_cotizaciones_formatos.sql';
$rutaMigracion030 = $raiz . '/db/migrations/030_conduces.sql';
$rutaVerificador = $raiz . '/tools/verificar_migraciones_tenant.sql';

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

/**
 * El SQL dinamico de una migracion, armado con valores de ejemplo: cada
 * SET @sql_x := IF(@guarda = N, <expresion>, 'DO 0'); se expande juntando los
 * literales '...' ('' = comilla escapada) y las @variables de $ejemplo. Sin
 * MySQL no se puede preparar, pero si leer lo que se prepararia.
 * @return array<int,array{variable:string, guarda:string, valor:string, sql:string, desconocida:?string}>
 */
function armarDinamicos(string $mig, array $ejemplo): array
{
    preg_match_all("/SET\s+(@sql_\w+)\s*:=\s*IF\((@\w+)\s*=\s*([01]),(.*?),\s*'DO 0'\);/s", $mig, $dinamicos, PREG_SET_ORDER);
    $out = [];
    foreach ($dinamicos as [, $variable, $guarda, $valor, $expresion]) {
        preg_match_all("/'((?:[^']|'')*)'|(@\w+)/", $expresion, $tokens, PREG_SET_ORDER);
        $sql = '';
        $desconocida = null;
        foreach ($tokens as $tok) {
            if (isset($tok[2]) && $tok[2] !== '') {
                $desconocida = isset($ejemplo[$tok[2]]) ? $desconocida : $tok[2];
                $sql .= $ejemplo[$tok[2]] ?? '';
            } else {
                $sql .= str_replace("''", "'", $tok[1]);
            }
        }
        $out[] = ['variable' => $variable, 'guarda' => $guarda, 'valor' => $valor, 'sql' => $sql, 'desconocida' => $desconocida];
    }
    return $out;
}

/** Comillas pares y parentesis balanceados (los de dentro de un literal no cuentan). */
function balanceado(string $sql): bool
{
    $sinLiterales = (string) preg_replace("/'(?:[^']|'')*'/", "''", $sql);
    return substr_count($sql, "'") % 2 === 0
        && substr_count($sinLiterales, '(') === substr_count($sinLiterales, ')');
}

/**
 * Sentencias de nivel superior de un SQL ya sin comentarios: corta en cada ';'
 * de fin de linea (un ';' dentro de un COMMENT no termina la linea).
 * @return string[]
 */
function sentencias(string $sql): array
{
    return array_values(array_filter(
        array_map('trim', preg_split('/;\s*\n/', $sql)),
        fn($s) => $s !== ''
    ));
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

echo "\n== Cotizaciones y conduces: estado final de las migraciones 026 y 030 ==\n";
// La cabecera llega a la migracion mas alta de db/migrations/, no a una fija:
// una migracion nueva que no se refleja en el snapshot falla aqui.
$numeros = array_map(
    fn(string $ruta): string => substr(basename($ruta), 0, 3),
    glob($raiz . '/db/migrations/[0-9][0-9][0-9]_*.sql') ?: []
);
sort($numeros);
$ultima = $numeros !== [] ? end($numeros) : '???';
$chk("cabecera: rango de migraciones 012..{$ultima}", str_contains($crudo, "012..{$ultima}"));

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
    // 030: conduces de mercancia. Nada se borra (activo = 0); cotizacion_id y
    // product_id llevan el tipo de una DB del repo (la 030 lo copia del id).
    'conduces' => [
        'numero'        => '/^INT UNSIGNED NOT NULL\b/i',
        'code'          => '/^VARCHAR\(20\) NOT NULL\b/i',
        'date'          => '/^DATETIME NOT NULL\b/i',
        'cotizacion_id' => '/^INT\(11\) NULL\b/i',
        'client_id'     => '/^INT\(11\) NULL\b/i',
        'user_id'       => '/^INT\(11\) NULL\b/i',
        'client_name'   => '/^VARCHAR\(100\) NULL\b/i',
        'activo'        => '/^TINYINT\(1\) NOT NULL DEFAULT 1\b/i',
    ],
    'conduce_items' => [
        'conduce_id'    => '/^INT\(11\) NOT NULL\b/i',
        'product_id'    => '/^INT\(11\) NULL\b/i',
        'description'   => '/^TEXT NOT NULL\b/i',
        'quantity'      => '/^DECIMAL\(12,3\) NOT NULL DEFAULT 1\.000\b/i',
        'unidad_medida' => "/^VARCHAR\(10\) NOT NULL DEFAULT '43'/i",
        'amount'        => '/^DECIMAL\(18,4\) NOT NULL DEFAULT 0\.0000\b/i',
        'activo'        => '/^TINYINT\(1\) NOT NULL DEFAULT 1\b/i',
    ],
    'conduce_secuencia' => [
        'id'     => '/^TINYINT NOT NULL\b/i',
        'ultimo' => '/^INT UNSIGNED NOT NULL DEFAULT 0\b/i',
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
    'conduces' => [
        'UNIQUE KEY uk_conduces_numero (numero)',
        'KEY idx_conduces_cotizacion (cotizacion_id)',
        'KEY idx_conduces_date (date)',
        'KEY idx_conduces_activo (activo)',
        'CONSTRAINT conduces_cotizacion_fk FOREIGN KEY (cotizacion_id) REFERENCES cotizaciones (id) ON DELETE SET NULL',
    ],
    'conduce_items' => [
        'KEY idx_conduce_items_conduce (conduce_id, activo)',
        'KEY idx_conduce_items_product (product_id)',
        'CONSTRAINT conduce_items_conduce_fk FOREIGN KEY (conduce_id) REFERENCES conduces (id) ON DELETE RESTRICT',
        'CONSTRAINT conduce_items_product_fk FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE SET NULL',
    ],
    // Una sola fila (id = 1): el INSERT IGNORE de la siembra depende de esta PK.
    'conduce_secuencia' => [
        'PRIMARY KEY (id)',
    ],
];
foreach ($indices as $tabla => $lst) {
    foreach ($lst as $esperado) {
        $chk("{$tabla}: {$esperado}", tieneElemento($porNombre[$tabla] ?? [], $esperado));
    }
}
// La fila de la secuencia, como la siembra la 030 (conduceModel tambien la
// asegura con INSERT IGNORE, pero un tenant nuevo debe nacer igual a uno migrado).
$chk('conduce_secuencia: el snapshot siembra la fila (1, 0) con INSERT IGNORE',
    preg_match('/^INSERT IGNORE INTO conduce_secuencia \(id, ultimo\) VALUES \(1, 0\);$/m', $sql) === 1);

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
$mostrar = in_array('--mostrar', $argv, true);
$ejemplo = [
    '@tipo_product_id' => 'int(11)',
    '@tipo_cot_id'     => 'int(11)',
    '@cn_definicion'   => 'varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL',
];
$dinamicos = armarDinamicos($mig, $ejemplo);
$chk('026: cada @sql_* se prepara una vez (' . count($dinamicos) . ' sentencias dinamicas)',
    $dinamicos !== [] && array_column($dinamicos, 'variable') === $prep[2]);
foreach ($dinamicos as $d) {
    $ok = $d['desconocida'] === null
        && preg_match('/^(ALTER TABLE (cotizaciones|cotizacion_items) |CREATE TABLE IF NOT EXISTS cotizacion_ajustes \()/', $d['sql']) === 1
        && balanceado($d['sql']);
    $chk("026: {$d['variable']} arma un DDL valido" . ($d['desconocida'] !== null ? " (variable sin ejemplo: {$d['desconocida']})" : ''), $ok);
    if ($mostrar) {
        echo "         {$d['sql']}\n";
    }
}

echo "\n== Migracion 030 ==\n";
$hay030 = is_file($rutaMigracion030);
$chk('existe db/migrations/030_conduces.sql', $hay030);
$mig030 = $hay030 ? sinComentarios((string) file_get_contents($rutaMigracion030)) : '';
// La misma migracion en una sola linea, para buscar sentencias sin depender del sangrado.
$plano030 = (string) preg_replace('/\s+/', ' ', $mig030);
foreach (['uk_conduces_numero', 'idx_conduces_cotizacion', 'idx_conduces_date', 'idx_conduces_activo',
          'conduces_cotizacion_fk', 'idx_conduce_items_conduce', 'idx_conduce_items_product',
          'conduce_items_conduce_fk', 'conduce_items_product_fk', 'conduce_secuencia'] as $nombre) {
    $chk("030 nombra {$nombre}", $mig030 !== '' && preg_match('/\b' . $nombre . '\b/', $mig030) === 1);
}

// Regla de phpMyAdmin (db/migrations/README.md; la 028 la revisa en
// check_estado_dgii_ancho.php): despues de un SELECT sobre information_schema,
// phpMyAdmin cambia la base actual, asi que la base se fija en @db en la
// PRIMERA sentencia, todo se nombra con ella y el unico SELECT de nivel
// superior es el resultado final. REFERENTIAL_CONSTRAINTS no tiene
// TABLE_SCHEMA: ahi el filtro es CONSTRAINT_SCHEMA = @db.
$sent030 = sentencias($mig030);
$chk('030: la primera sentencia fija la base en @db', ($sent030[0] ?? '') === 'SET @db := DATABASE()');
$chk('030: DATABASE() aparece una sola vez', substr_count($mig030, 'DATABASE()') === 1);
$usosIs = preg_match_all('/\binformation_schema\./i', $mig030);
$filtrosDb = preg_match_all('/\b(?:TABLE|CONSTRAINT)_SCHEMA = @db\b/', $mig030);
$chk("030: cada consulta a information_schema filtra por @db ({$usosIs} consultas, {$filtrosDb} filtros)",
    $usosIs > 0 && $usosIs === $filtrosDb);
$selects030 = array_keys(array_filter($sent030, fn($s) => stripos($s, 'SELECT') === 0));
$chk('030: el unico SELECT de nivel superior es la ultima sentencia',
    $sent030 !== [] && $selects030 === [count($sent030) - 1]);
$final030 = $sent030 !== [] ? $sent030[count($sent030) - 1] : '';
$chk('030: el resultado muestra la base, la regla ON DELETE de cada FK y todo_ok',
    str_contains($final030, '@db AS base')
    && substr_count($final030, 'SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS') === 3
    && str_contains($final030, 'AS todo_ok'));

// Lo que la 030 averigua antes de crear: el tipo de los dos id (se copia a las
// FK) y que cotizaciones y products sean InnoDB. O se crean las tres tablas o
// ninguna: cada guarda pide que falte la tabla, los dos motores y los dos tipos.
$chk('030: copia el tipo de cotizaciones.id y de products.id',
    str_contains($plano030, "SET @tipo_cot_id := ( SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'cotizaciones' AND COLUMN_NAME = 'id' );")
    && str_contains($plano030, "SET @tipo_product_id := ( SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'products' AND COLUMN_NAME = 'id' );"));
$chk('030: cuenta cotizaciones y products con ENGINE = InnoDB',
    str_contains($plano030, "SET @motores_innodb := ( SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN ('cotizaciones', 'products') AND ENGINE = 'InnoDB' );"));
foreach (['conduces', 'conduce_items', 'conduce_secuencia'] as $tabla) {
    $chk("030: @crear_{$tabla} exige que falte la tabla, los dos motores InnoDB y los dos tipos de id",
        str_contains($plano030, "SET @has_{$tabla} := ( SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = '{$tabla}' );")
        && str_contains($plano030, "SET @crear_{$tabla} := (@has_{$tabla} = 0 AND @motores_innodb = 2 AND @tipo_cot_id IS NOT NULL AND @tipo_product_id IS NOT NULL);"));
}

preg_match_all('/^\s*PREPARE\s+(\w+)\s+FROM\s+(@\w+)\s*;/mi', $mig030, $prep030);
preg_match_all('/^\s*EXECUTE\s+(\w+)\s*;/mi', $mig030, $exec030);
preg_match_all('/^\s*DEALLOCATE\s+PREPARE\s+(\w+)\s*;/mi', $mig030, $deal030);
$chk('030: cada PREPARE tiene su EXECUTE y su DEALLOCATE (' . count($prep030[1]) . ' sentencias)',
    $prep030[1] !== [] && $prep030[1] === $exec030[1] && $prep030[1] === $deal030[1]);
$chk('030: ningun nombre de sentencia preparada se repite', count($prep030[1]) === count(array_unique($prep030[1])));

// El SQL armado de la 030 (con la base 'tenant', tipos de una DB del repo y
// cada guarda booleana en 1) solo puede crear las tres tablas, sembrar la fila
// de la secuencia o leerla para el resultado; toda REFERENCES nombra la base.
$ejemplo030 = [
    '@db'                      => 'tenant',
    '@tipo_cot_id'             => 'int(11)',
    '@tipo_product_id'         => 'int(11)',
    '@crear_conduces'          => '1',
    '@crear_conduce_items'     => '1',
    '@crear_conduce_secuencia' => '1',
    '@hay_secuencia'           => '1',
];
$permitidos030 = [
    '/^CREATE TABLE IF NOT EXISTS `tenant`\.(conduces|conduce_items|conduce_secuencia) \(/',
    '/^' . preg_quote('INSERT IGNORE INTO `tenant`.conduce_secuencia (id, ultimo) VALUES (1, 0)', '/') . '$/',
    '/^' . preg_quote("SELECT CONCAT('(', id, ', ', ultimo, ')') INTO @fila_secuencia FROM `tenant`.conduce_secuencia WHERE id = 1", '/') . '$/',
];
$dinamicos030 = armarDinamicos($mig030, $ejemplo030);
$chk('030: cada @sql_* se prepara una vez (' . count($dinamicos030) . ' sentencias dinamicas)',
    $dinamicos030 !== [] && array_column($dinamicos030, 'variable') === $prep030[2]);
$tablas030 = [];
foreach ($dinamicos030 as $d) {
    $permitido = false;
    foreach ($permitidos030 as $patron) {
        $permitido = $permitido || preg_match($patron, $d['sql']) === 1;
    }
    $ok = $d['desconocida'] === null
        && ($ejemplo030[$d['guarda']] ?? null) === $d['valor']
        && $permitido
        && preg_match('/REFERENCES (?!`tenant`\.)/', $d['sql']) === 0
        && balanceado($d['sql']);
    $chk("030: {$d['variable']} arma un SQL valido (guarda {$d['guarda']} = {$d['valor']})"
        . ($d['desconocida'] !== null ? " (variable sin ejemplo: {$d['desconocida']})" : ''), $ok);
    if ($mostrar) {
        echo "         {$d['sql']}\n";
    }
    // tablas() lee CREATE TABLE sin base: se quita `tenant`. para compararla con el snapshot.
    foreach (tablas(str_replace('`tenant`.', '', $d['sql'])) as $t) {
        $tablas030[$t['nombre']] = $t['elementos'];
    }
}
// Lo que crea la 030 y lo que crea el snapshot deben ser la misma tabla: mismas
// columnas en el mismo orden, tipos, defaults, COMMENT, indices y FKs. Si no, un
// tenant nuevo y uno migrado quedan distintos.
foreach (['conduces', 'conduce_items', 'conduce_secuencia'] as $tabla) {
    $de030 = array_map('strtoupper', $tablas030[$tabla] ?? []);
    $deSnapshot = array_map('strtoupper', $porNombre[$tabla] ?? []);
    $distinto = '';
    foreach (array_keys($de030 + $deSnapshot) as $i) {
        if (($de030[$i] ?? '') !== ($deSnapshot[$i] ?? '')) {
            $distinto = ' (030: ' . ($de030[$i] ?? 'nada') . ' | snapshot: ' . ($deSnapshot[$i] ?? 'nada') . ')';
            break;
        }
    }
    $chk("030: {$tabla} queda igual que en el snapshot{$distinto}", $de030 !== [] && $distinto === '');
}

// El verificador de migraciones (tools/verificar_migraciones_tenant.sql) dice
// APLICADA a la 030 solo con las tres tablas.
$verificador = is_file($rutaVerificador) ? str_replace("\r\n", "\n", (string) file_get_contents($rutaVerificador)) : '';
$chk('verificador: la fila 030 pide las tres tablas',
    preg_match("/SELECT '030', '030_conduces\.sql',\s*'tablas conduces, conduce_items y conduce_secuencia',\s*"
        . "\(SELECT COUNT\(\*\) FROM information_schema\.TABLES\s+WHERE TABLE_SCHEMA = DATABASE\(\)\s+"
        . "AND TABLE_NAME IN \('conduces', 'conduce_items', 'conduce_secuencia'\)\) = 3/", $verificador) === 1);
// La 029 de master (029_precios_4_decimales.sql) deja products.precio .. precio_4
// en DECIMAL(18,4): su fila del verificador cuenta las cuatro columnas.
$chk('verificador: la fila 029 (precios) pide las cuatro columnas de products en decimal(18,4)',
    preg_match("/SELECT '029', '029_precios_4_decimales\.sql',\s*'[^']*',\s*"
        . "\(SELECT COUNT\(\*\) FROM information_schema\.COLUMNS\s+WHERE TABLE_SCHEMA = DATABASE\(\) AND TABLE_NAME = 'products'\s+"
        . "AND COLUMN_NAME IN \('precio', 'precio_2', 'precio_3', 'precio_4'\)\s+"
        . "AND COLUMN_TYPE LIKE 'decimal\(18,4\)%'\) = 4/", $verificador) === 1);

printf("\n%d/%d OK\n", $total - $fallos, $total);
exit($fallos === 0 ? 0 : 1);
