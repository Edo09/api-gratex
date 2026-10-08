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
 *     de conduces el de la 031 (columnas, nulabilidad, indices y FKs con los
 *     mismos nombres, y la fila de conduce_secuencia).
 *   - La 026 y la 031 nombran esos mismos indices y FKs, cada PREPARE tiene su
 *     EXECUTE y su DEALLOCATE PREPARE, y su SQL dinamico, armado con tipos de
 *     ejemplo, es un ALTER/CREATE (o la fila de la secuencia) con parentesis y
 *     comillas balanceados.
 *   - La 031 sigue la regla de phpMyAdmin (db/migrations/README.md, ver 028):
 *     la primera sentencia es SET @db := DATABASE(), la segunda es la guardia
 *     (#1049 sin base de empresa seleccionada), toda consulta a
 *     information_schema filtra por @db, el unico SELECT de nivel superior es
 *     el ultimo, y sus tres tablas armadas son las del snapshot elemento por
 *     elemento. El verificador de migraciones tiene su fila (y las de la 029 de
 *     precios, que deja products.precio .. precio_4 en DECIMAL(18,4), y la
 *     030 del POS, que deja seis tablas y cuatro columnas de facturas). El del
 *     master tiene la de la 012 (POS: dos columnas y dos tablas).
 *
 * No reemplaza aplicar el snapshot, la 026 y la 031 a una DB de verdad; solo
 * atrapa en local lo que mas facil se rompe al mover bloques o al escribir SQL
 * dinamico.
 *
 * Uso:
 *   php tools/check_tenant_schema_orden.php            (sale con 1 si algo falla)
 *   php tools/check_tenant_schema_orden.php --mostrar  (ademas imprime el SQL dinamico de la 026 y la 031)
 */

$raiz = dirname(__DIR__);
$rutaSnapshot = $raiz . '/db/tenant_schema.sql';
$rutaMigracion = $raiz . '/db/migrations/026_cotizaciones_formatos.sql';
$rutaMigracion031 = $raiz . '/db/migrations/031_conduces.sql';
$rutaVerificador = $raiz . '/tools/verificar_migraciones_tenant.sql';
$rutaVerificadorMaster = $raiz . '/tools/verificar_migraciones_master.sql';

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

echo "\n== Cotizaciones y conduces: estado final de las migraciones 026 y 031 ==\n";
// La cabecera dice hasta que migracion incluye el snapshot: la ultima de
// db/migrations/ (antes estaba fija en 028 y fallaba con cada migracion nueva).
$ultimaMigracion = max(array_map(
    static fn($f) => (int) basename($f),
    glob(__DIR__ . '/../db/migrations/[0-9][0-9][0-9]_*.sql') ?: ['0']
));
$rangoEsperado = sprintf('012..%03d', $ultimaMigracion);
$chk("cabecera: rango de migraciones $rangoEsperado", str_contains($crudo, $rangoEsperado));

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
    // 031: conduces de mercancia. Nada se borra (activo = 0); cotizacion_id y
    // product_id llevan el tipo de una DB del repo (la 031 lo copia del id).
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
// La fila de la secuencia, como la siembra la 031 (conduceModel tambien la
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

echo "\n== Migracion 031 ==\n";
$hay031 = is_file($rutaMigracion031);
$chk('existe db/migrations/031_conduces.sql', $hay031);
$mig031 = $hay031 ? sinComentarios((string) file_get_contents($rutaMigracion031)) : '';
// La misma migracion en una sola linea, para buscar sentencias sin depender del sangrado.
$plano031 = (string) preg_replace('/\s+/', ' ', $mig031);
foreach (['uk_conduces_numero', 'idx_conduces_cotizacion', 'idx_conduces_date', 'idx_conduces_activo',
          'conduces_cotizacion_fk', 'idx_conduce_items_conduce', 'idx_conduce_items_product',
          'conduce_items_conduce_fk', 'conduce_items_product_fk', 'conduce_secuencia'] as $nombre) {
    $chk("031 nombra {$nombre}", $mig031 !== '' && preg_match('/\b' . $nombre . '\b/', $mig031) === 1);
}

// Regla de phpMyAdmin (db/migrations/README.md; la 028 la revisa en
// check_estado_dgii_ancho.php): despues de un SELECT sobre information_schema,
// phpMyAdmin cambia la base actual, asi que la base se fija en @db en la
// PRIMERA sentencia, todo se nombra con ella y el unico SELECT de nivel
// superior es el resultado final. REFERENTIAL_CONSTRAINTS no tiene
// TABLE_SCHEMA: ahi el filtro es CONSTRAINT_SCHEMA = @db.
$sent031 = sentencias($mig031);
$chk('031: la primera sentencia fija la base en @db', ($sent031[0] ?? '') === 'SET @db := DATABASE()');
// Desde la 029/030 (y la master 012) la segunda sentencia es la guardia: falla
// con #1049 si no hay base de empresa seleccionada (NULL o una de sistema) y con
// #1146 si la base no tiene cotizaciones, ANTES de crear nada. Aqui se fija con
// su PREPARE/EXECUTE/DEALLOCATE, que son las sentencias 3, 4 y 5.
$guardia031 = "SET @guardia := IF(@db IS NULL OR @db IN ('information_schema', 'mysql', 'performance_schema', 'sys'), "
    . "'DO (SELECT 1 FROM `ALTO_elige_la_base_de_la_empresa_en_el_panel`.`x` LIMIT 1)', "
    . "CONCAT('DO (SELECT 1 FROM `', @db, '`.cotizaciones LIMIT 1)'))";
$chk('031: la segunda sentencia es la guardia (#1049 sin base de empresa, #1146 sin cotizaciones) y su PREPARE/EXECUTE/DEALLOCATE van enseguida',
    preg_replace('/\s+/', ' ', $sent031[1] ?? '') === $guardia031
    && array_slice($sent031, 2, 3) === ['PREPARE s_guardia FROM @guardia', 'EXECUTE s_guardia', 'DEALLOCATE PREPARE s_guardia']);
$chk('031: DATABASE() aparece una sola vez', substr_count($mig031, 'DATABASE()') === 1);
$usosIs = preg_match_all('/\binformation_schema\./i', $mig031);
$filtrosDb = preg_match_all('/\b(?:TABLE|CONSTRAINT)_SCHEMA = @db\b/', $mig031);
$chk("031: cada consulta a information_schema filtra por @db ({$usosIs} consultas, {$filtrosDb} filtros)",
    $usosIs > 0 && $usosIs === $filtrosDb);
$selects031 = array_keys(array_filter($sent031, fn($s) => stripos($s, 'SELECT') === 0));
$chk('031: el unico SELECT de nivel superior es la ultima sentencia',
    $sent031 !== [] && $selects031 === [count($sent031) - 1]);
$final031 = $sent031 !== [] ? $sent031[count($sent031) - 1] : '';
$chk('031: el resultado muestra la base, la regla ON DELETE de cada FK y todo_ok',
    str_contains($final031, '@db AS base')
    && substr_count($final031, 'SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS') === 3
    && str_contains($final031, 'AS todo_ok'));

// Lo que la 031 averigua antes de crear: el tipo de los dos id (se copia a las
// FK) y que cotizaciones y products sean InnoDB. O se crean las tres tablas o
// ninguna: cada guarda pide que falte la tabla, los dos motores y los dos tipos.
$chk('031: copia el tipo de cotizaciones.id y de products.id',
    str_contains($plano031, "SET @tipo_cot_id := ( SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'cotizaciones' AND COLUMN_NAME = 'id' );")
    && str_contains($plano031, "SET @tipo_product_id := ( SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'products' AND COLUMN_NAME = 'id' );"));
$chk('031: cuenta cotizaciones y products con ENGINE = InnoDB',
    str_contains($plano031, "SET @motores_innodb := ( SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME IN ('cotizaciones', 'products') AND ENGINE = 'InnoDB' );"));
foreach (['conduces', 'conduce_items', 'conduce_secuencia'] as $tabla) {
    $chk("031: @crear_{$tabla} exige que falte la tabla, los dos motores InnoDB y los dos tipos de id",
        str_contains($plano031, "SET @has_{$tabla} := ( SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = @db AND TABLE_NAME = '{$tabla}' );")
        && str_contains($plano031, "SET @crear_{$tabla} := (@has_{$tabla} = 0 AND @motores_innodb = 2 AND @tipo_cot_id IS NOT NULL AND @tipo_product_id IS NOT NULL);"));
}

preg_match_all('/^\s*PREPARE\s+(\w+)\s+FROM\s+(@\w+)\s*;/mi', $mig031, $prep031);
preg_match_all('/^\s*EXECUTE\s+(\w+)\s*;/mi', $mig031, $exec031);
preg_match_all('/^\s*DEALLOCATE\s+PREPARE\s+(\w+)\s*;/mi', $mig031, $deal031);
$chk('031: cada PREPARE tiene su EXECUTE y su DEALLOCATE (' . count($prep031[1]) . ' sentencias)',
    $prep031[1] !== [] && $prep031[1] === $exec031[1] && $prep031[1] === $deal031[1]);
$chk('031: ningun nombre de sentencia preparada se repite', count($prep031[1]) === count(array_unique($prep031[1])));

// El SQL armado de la 031 (con la base 'tenant', tipos de una DB del repo y
// cada guarda booleana en 1) solo puede crear las tres tablas, sembrar la fila
// de la secuencia o leerla para el resultado; toda REFERENCES nombra la base.
$ejemplo031 = [
    '@db'                      => 'tenant',
    '@tipo_cot_id'             => 'int(11)',
    '@tipo_product_id'         => 'int(11)',
    '@crear_conduces'          => '1',
    '@crear_conduce_items'     => '1',
    '@crear_conduce_secuencia' => '1',
    '@hay_secuencia'           => '1',
];
$permitidos031 = [
    '/^CREATE TABLE IF NOT EXISTS `tenant`\.(conduces|conduce_items|conduce_secuencia) \(/',
    '/^' . preg_quote('INSERT IGNORE INTO `tenant`.conduce_secuencia (id, ultimo) VALUES (1, 0)', '/') . '$/',
    '/^' . preg_quote("SELECT CONCAT('(', id, ', ', ultimo, ')') INTO @fila_secuencia FROM `tenant`.conduce_secuencia WHERE id = 1", '/') . '$/',
];
$dinamicos031 = armarDinamicos($mig031, $ejemplo031);
// La guardia (@guardia) tambien se prepara, pero no es un @sql_*: va aparte.
$chk('031: cada @sql_* se prepara una vez (' . count($dinamicos031) . ' sentencias dinamicas, mas la guardia)',
    $dinamicos031 !== [] && array_column($dinamicos031, 'variable') === array_values(array_diff($prep031[2], ['@guardia'])));
$tablas031 = [];
foreach ($dinamicos031 as $d) {
    $permitido = false;
    foreach ($permitidos031 as $patron) {
        $permitido = $permitido || preg_match($patron, $d['sql']) === 1;
    }
    $ok = $d['desconocida'] === null
        && ($ejemplo031[$d['guarda']] ?? null) === $d['valor']
        && $permitido
        && preg_match('/REFERENCES (?!`tenant`\.)/', $d['sql']) === 0
        && balanceado($d['sql']);
    $chk("031: {$d['variable']} arma un SQL valido (guarda {$d['guarda']} = {$d['valor']})"
        . ($d['desconocida'] !== null ? " (variable sin ejemplo: {$d['desconocida']})" : ''), $ok);
    if ($mostrar) {
        echo "         {$d['sql']}\n";
    }
    // tablas() lee CREATE TABLE sin base: se quita `tenant`. para compararla con el snapshot.
    foreach (tablas(str_replace('`tenant`.', '', $d['sql'])) as $t) {
        $tablas031[$t['nombre']] = $t['elementos'];
    }
}
// Lo que crea la 031 y lo que crea el snapshot deben ser la misma tabla: mismas
// columnas en el mismo orden, tipos, defaults, COMMENT, indices y FKs. Si no, un
// tenant nuevo y uno migrado quedan distintos.
foreach (['conduces', 'conduce_items', 'conduce_secuencia'] as $tabla) {
    $de031 = array_map('strtoupper', $tablas031[$tabla] ?? []);
    $deSnapshot = array_map('strtoupper', $porNombre[$tabla] ?? []);
    $distinto = '';
    foreach (array_keys($de031 + $deSnapshot) as $i) {
        if (($de031[$i] ?? '') !== ($deSnapshot[$i] ?? '')) {
            $distinto = ' (031: ' . ($de031[$i] ?? 'nada') . ' | snapshot: ' . ($deSnapshot[$i] ?? 'nada') . ')';
            break;
        }
    }
    $chk("031: {$tabla} queda igual que en el snapshot{$distinto}", $de031 !== [] && $distinto === '');
}

// El verificador de migraciones (tools/verificar_migraciones_tenant.sql) dice
// APLICADA a la 031 solo con las tres tablas.
$verificador = is_file($rutaVerificador) ? str_replace("\r\n", "\n", (string) file_get_contents($rutaVerificador)) : '';
$chk('verificador: la fila 031 pide las tres tablas',
    preg_match("/SELECT '031', '031_conduces\.sql',\s*'tablas conduces, conduce_items y conduce_secuencia',\s*"
        . "\(SELECT COUNT\(\*\) FROM information_schema\.TABLES\s+WHERE TABLE_SCHEMA = DATABASE\(\)\s+"
        . "AND TABLE_NAME IN \('conduces', 'conduce_items', 'conduce_secuencia'\)\) = 3/", $verificador) === 1);
// La 029 de master (029_precios_4_decimales.sql) deja products.precio .. precio_4
// en DECIMAL(18,4): su fila del verificador cuenta las cuatro columnas.
$chk('verificador: la fila 029 (precios) pide las cuatro columnas de products en decimal(18,4)',
    preg_match("/SELECT '029', '029_precios_4_decimales\.sql',\s*'[^']*',\s*"
        . "\(SELECT COUNT\(\*\) FROM information_schema\.COLUMNS\s+WHERE TABLE_SCHEMA = DATABASE\(\) AND TABLE_NAME = 'products'\s+"
        . "AND COLUMN_NAME IN \('precio', 'precio_2', 'precio_3', 'precio_4'\)\s+"
        . "AND COLUMN_TYPE LIKE 'decimal\(18,4\)%'\) = 4/", $verificador) === 1);
// La 030 de master (030_pos.sql) deja seis tablas y cuatro columnas de facturas:
// su fila del verificador cuenta las dos cosas.
$chk('verificador: la fila 030 (POS) pide las seis tablas del POS y las cuatro columnas de facturas',
    preg_match("/SELECT '030', '030_pos\.sql',\s*'[^']*',\s*"
        . "\(SELECT COUNT\(\*\) FROM information_schema\.TABLES\s+WHERE TABLE_SCHEMA = DATABASE\(\)\s+"
        . "AND TABLE_NAME IN \('pos_cajas', 'pos_empleados', 'pos_sesiones', 'pos_turnos',\s*'pos_caja_movimientos', 'product_barcodes'\)\) = 6\s+"
        . "AND \(SELECT COUNT\(\*\) FROM information_schema\.COLUMNS\s+WHERE TABLE_SCHEMA = DATABASE\(\) AND TABLE_NAME = 'facturas'\s+"
        . "AND COLUMN_NAME IN \('turno_id', 'pos_empleado_id', 'pos_idempotency_key', 'envio_pendiente'\)\) = 4/", $verificador) === 1);
// La 012 del master (012_pos.sql, base master) deja tenants.pos_enabled,
// pos_equipos.bloqueos_seguidos y las tablas pos_handoff_codes y pos_equipos: es lo
// mismo que cuenta la consulta final de la propia 012, y su fila del verificador del
// master (tools/verificar_migraciones_master.sql) lo pide entero.
$verificadorMaster = is_file($rutaVerificadorMaster) ? str_replace("\r\n", "\n", (string) file_get_contents($rutaVerificadorMaster)) : '';
$chk('verificador del master: la fila 012 (POS) pide tenants.pos_enabled, pos_equipos.bloqueos_seguidos y las tablas pos_handoff_codes y pos_equipos',
    preg_match("/SELECT '012', '012_pos\.sql',\s*'[^']*',\s*"
        . "EXISTS \(SELECT 1 FROM information_schema\.COLUMNS\s+WHERE TABLE_SCHEMA = DATABASE\(\) AND TABLE_NAME = 'tenants'\s+"
        . "AND COLUMN_NAME = 'pos_enabled'\)\s+"
        . "AND EXISTS \(SELECT 1 FROM information_schema\.COLUMNS\s+WHERE TABLE_SCHEMA = DATABASE\(\) AND TABLE_NAME = 'pos_equipos'\s+"
        . "AND COLUMN_NAME = 'bloqueos_seguidos'\)\s+"
        . "AND \(SELECT COUNT\(\*\) FROM information_schema\.TABLES\s+WHERE TABLE_SCHEMA = DATABASE\(\)\s+"
        . "AND TABLE_NAME IN \('pos_handoff_codes', 'pos_equipos'\)\) = 2/", $verificadorMaster) === 1);

printf("\n%d/%d OK\n", $total - $fallos, $total);
exit($fallos === 0 ? 0 : 1);
