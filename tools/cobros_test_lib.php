<?php
/**
 * cobros_test_lib.php — ayudas que comparten las suites de Cobros: tools/test_cobros.php (el modulo de cada
 * empresa, snapshot de tenant) y tools/test_cobros_tenants.php (el de la plataforma a sus tenants, snapshot de
 * la master). Traducen a SQLite el DDL REAL de un snapshot (asi una restriccion que se pierda del snapshot la ve
 * la suite) y revisan que los saldos de la base cuadren.
 *
 * Incluirlo solo define funciones: no corre nada ni abre conexiones.
 */

require_once __DIR__ . '/../src/Utils/Cobros/CobroReglas.php';

/**
 * Traduce de MySQL a SQLite el DDL de unas tablas de un snapshot (db/tenant_schema.sql o db/master_schema.sql): quita COMMENT y
 * ENGINE, convierte INT(11) AUTO_INCREMENT en INTEGER PRIMARY KEY AUTOINCREMENT, los KEY en
 * CREATE INDEX y los ENUM en TEXT + CHECK. UNIQUE, CHECK y FOREIGN KEY pasan tal cual.
 * @return list<string>
 */
function cobros_ddl_sqlite(string $rutaSnapshot, array $tablas): array
{
    $sql = str_replace("\r\n", "\n", (string) file_get_contents($rutaSnapshot));
    $sql = (string) preg_replace('/^[ \t]*--.*$/m', '', $sql);
    preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?\s*\((.*?)\)\s*ENGINE\s*=[^;]*;/is', $sql, $m, PREG_SET_ORDER);
    $partir = static function (string $cuerpo): array {
        $partes = [];
        $actual = '';
        $nivel = 0;
        $comilla = false;
        $n = strlen($cuerpo);
        for ($i = 0; $i < $n; $i++) {
            $c = $cuerpo[$i];
            if ($comilla) {
                $actual .= $c;
                if ($c === "'") {
                    if ($i + 1 < $n && $cuerpo[$i + 1] === "'") {
                        $actual .= "'";
                        $i++;
                    } else {
                        $comilla = false;
                    }
                }
                continue;
            }
            if ($c === "'") {
                $comilla = true;
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
        return array_values(array_filter(array_map(static fn(string $p): string => trim((string) preg_replace('/\s+/', ' ', str_replace('`', '', $p))), $partes), static fn(string $p): bool => $p !== ''));
    };
    $out = [];
    $vistas = [];
    foreach ($m as [, $nombre, $cuerpo]) {
        if (!in_array($nombre, $tablas, true) || isset($vistas[$nombre])) {
            continue;
        }
        $vistas[$nombre] = true;
        $cols = [];
        $indices = [];
        $pk = null;
        $auto = false;
        foreach ($partir($cuerpo) as $e) {
            if (preg_match('/^PRIMARY KEY \(/i', $e)) {
                $pk = $e;
            } elseif (preg_match('/^UNIQUE KEY (\w+) \((.*)\)$/i', $e, $k)) {
                $indices[] = "CREATE UNIQUE INDEX {$k[1]} ON {$nombre} ({$k[2]})";
            } elseif (preg_match('/^KEY (\w+) \((.*)\)$/i', $e, $k)) {
                $indices[] = "CREATE INDEX {$k[1]} ON {$nombre} ({$k[2]})";
            } elseif (preg_match('/^CONSTRAINT /i', $e)) {
                $cols[] = $e;
            } else {
                $e = (string) preg_replace("/ COMMENT '(?:[^']|'')*'/i", '', $e);
                if (preg_match('/^(\w+) INT\(11\) NOT NULL AUTO_INCREMENT$/i', $e, $a)) {
                    $e = "{$a[1]} INTEGER PRIMARY KEY AUTOINCREMENT";
                    $auto = true;
                }
                $e = (string) preg_replace(['/\bTINYINT UNSIGNED\b/i', '/\bTINYINT(\(\d+\))?/i', '/\bINT\(11\)/i'], 'INTEGER', $e);
                if (preg_match('/^(\w+) ENUM\(([^)]*)\)(.*)$/i', $e, $en)) {
                    $e = "{$en[1]} TEXT{$en[3]} CHECK ({$en[1]} IN ({$en[2]}))";
                }
                $cols[] = $e;
            }
        }
        // Las columnas van antes que las restricciones de tabla (la PK compuesta o de una columna no auto).
        $colsSolo = array_values(array_filter($cols, static fn(string $c): bool => !preg_match('/^CONSTRAINT /i', $c)));
        $restricciones = array_values(array_filter($cols, static fn(string $c): bool => (bool) preg_match('/^CONSTRAINT /i', $c)));
        if (!$auto && $pk !== null) {
            $restricciones[] = $pk;
        }
        $out[] = "CREATE TABLE {$nombre} (" . implode(', ', array_merge($colsSolo, $restricciones)) . ')';
        foreach ($indices as $i) {
            $out[] = $i;
        }
    }
    return $out;
}

/** @return list<string> lo que esta mal en los saldos de la base: lista vacia = consistente */
function cobros_invariantes(PDO $pdo): array
{
    $mal = [];
    foreach ($pdo->query('SELECT id, monto, monto_pagado, cancelado_at FROM cobros')->fetchAll() as $c) {
        $suma = 0;
        $s = $pdo->prepare('SELECT monto FROM cobro_pagos WHERE cobro_id = ? AND anulado_at IS NULL');
        $s->execute([$c['id']]);
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $m) {
            $suma += CobroReglas::centavosDb($m);
        }
        $pagado = CobroReglas::centavosDb($c['monto_pagado']);
        $monto = CobroReglas::centavosDb($c['monto']);
        if ($pagado !== $suma) {
            $mal[] = "cobro {$c['id']}: monto_pagado {$pagado} y la suma de sus pagos vigentes {$suma}";
        }
        if ($pagado < 0 || $pagado > $monto) {
            $mal[] = "cobro {$c['id']}: monto_pagado {$pagado} fuera de 0..{$monto}";
        }
        if ($c['cancelado_at'] !== null && $pagado > 0) {
            $mal[] = "cobro {$c['id']}: cancelado con pagos vigentes";
        }
    }
    $dup = $pdo->query('SELECT client_id, periodo, COUNT(*) n FROM cobros GROUP BY client_id, periodo HAVING n > 1')->fetchAll();
    foreach ($dup as $d) {
        $mal[] = "duplicado: cliente {$d['client_id']} periodo {$d['periodo']} x{$d['n']}";
    }
    return $mal;
}
