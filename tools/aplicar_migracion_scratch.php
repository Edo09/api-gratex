<?php
/**
 * aplicar_migracion_scratch.php — aplica migraciones de db/migrations a una base
 * de PRUEBA del write-ahead y comprueba, con tools/verificar_migraciones_tenant.sql,
 * que quedaron APLICADA.
 *
 * Existe para los merges con master: cada migracion nueva de master tiene que
 * llegar a las dos bases de prueba (wa_scratch y wa_scratch_sin_gate) antes de
 * correr la compuerta y las suites. Las migraciones son idempotentes (cada paso
 * mira information_schema), asi que se puede repetir.
 *
 * Reglas:
 *  - Solo bases con la marca zz_write_ahead_scratch (wa_pdo sale con 1 si no).
 *  - 027 y 028 NUNCA se aplican a wa_scratch_sin_gate: esa copia esta rota a
 *    proposito (tools/test_write_ahead_gate.php) y tiene que seguir FALTA.
 *  - Solo migraciones de tres digitos que tengan fila en el verificador.
 *  - Sentencia por sentencia: un exec() con varias sentencias no avisa si falla
 *    una de las ultimas, y la migracion quedaria a medias sin que se note. Un
 *    fallo imprime la sentencia y solo SQLSTATE y errno (nunca el mensaje del
 *    driver, que trae usuario y host) y sale con 1.
 *
 * Uso:
 *   php -d extension=pdo_mysql tools/aplicar_migracion_scratch.php --tenant-db=wa_scratch --migraciones=032,033,034
 *   php -d extension=pdo_mysql tools/aplicar_migracion_scratch.php --tenant-db=wa_scratch_sin_gate --migraciones=032,033,034
 * Sale con 2 si los argumentos no valen y con 1 si una migracion no quedo APLICADA.
 */

require_once __DIR__ . '/write_ahead_test_lib.php';

$baseArg = (string) wa_arg('tenant-db', '');
$pedidas = array_values(array_filter(array_map('trim', explode(',', (string) wa_arg('migraciones', '')))));
if ($baseArg === '' || $pedidas === []) {
    echo "Uso: php tools/aplicar_migracion_scratch.php --tenant-db=<base de prueba> --migraciones=032,033,034\n";
    exit(2);
}
foreach ($pedidas as $m) {
    if (!preg_match('/^\d{3}$/', $m)) {
        echo "[FALLO] migracion invalida: {$m} (tres digitos, por ejemplo 032)\n";
        exit(2);
    }
}
$corta = wa_base_corta($baseArg);
if (str_ends_with($corta, 'wa_scratch_sin_gate') && array_intersect($pedidas, ['027', '028']) !== []) {
    echo "[FALLO] 027 y 028 no se aplican a wa_scratch_sin_gate: esa copia se queda sin la compuerta a proposito\n";
    exit(2);
}

/** Corre el verificador (un solo SELECT) en la base de $pdo. @return array<string,array> por migracion */
function wa_estado_migraciones(PDO $pdo): array
{
    $lineas = preg_split('/\R/', (string) file_get_contents(__DIR__ . '/verificar_migraciones_tenant.sql'));
    $sql = implode("\n", array_filter($lineas, static fn(string $l): bool => !str_starts_with(ltrim($l), '--')));
    $sql = rtrim(trim($sql), ';');
    $porMigracion = [];
    foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $porMigracion[(string) $f['migracion']] = $f;
    }
    return $porMigracion;
}

/** Parte un script SQL en sentencias: respeta '...' (con ''), `...` y los comentarios -- de linea. */
function wa_partir_sql(string $sql): array
{
    $sentencias = [];
    $actual = '';
    $comilla = null;
    $largo = strlen($sql);
    for ($i = 0; $i < $largo; $i++) {
        $c = $sql[$i];
        if ($comilla !== null) {
            $actual .= $c;
            if ($c === $comilla) {
                if ($comilla === "'" && $i + 1 < $largo && $sql[$i + 1] === "'") {
                    $actual .= $sql[++$i];   // '' dentro de una cadena
                } else {
                    $comilla = null;
                }
            }
            continue;
        }
        if ($c === "'" || $c === '`' || $c === '"') {
            $comilla = $c;
            $actual .= $c;
            continue;
        }
        if ($c === '-' && $i + 1 < $largo && $sql[$i + 1] === '-') {
            while ($i < $largo && $sql[$i] !== "\n") {
                $i++;
            }
            $actual .= "\n";
            continue;
        }
        if ($c === ';') {
            if (trim($actual) !== '') {
                $sentencias[] = trim($actual);
            }
            $actual = '';
            continue;
        }
        $actual .= $c;
    }
    if (trim($actual) !== '') {
        $sentencias[] = trim($actual);
    }
    return $sentencias;
}

/** Corre un script de migracion sentencia por sentencia. Un fallo imprime cual y sale con 1. */
function wa_correr_migracion(PDO $pdo, string $sql): void
{
    foreach (wa_partir_sql($sql) as $n => $sentencia) {
        try {
            $resultado = $pdo->query($sentencia);
            if ($resultado instanceof PDOStatement) {
                $resultado->closeCursor();
            }
        } catch (PDOException $e) {
            $info = (array) $e->errorInfo;
            echo '[FALLO] sentencia #' . ($n + 1) . ' (' . substr((string) preg_replace('/\s+/', ' ', $sentencia), 0, 70)
                . '...) SQLSTATE ' . ($info[0] ?? '?') . ' errno ' . ($info[1] ?? '?') . "\n";
            exit(1);
        }
    }
}

$pdo = wa_pdo($baseArg);
$antes = wa_estado_migraciones($pdo);
echo 'Base de prueba (' . wa_base_corta(wa_base_real($baseArg)) . ")\n";
foreach ($pedidas as $m) {
    if (!isset($antes[$m])) {
        wa_chk("{$m}: tiene fila en tools/verificar_migraciones_tenant.sql", false);
        continue;
    }
    $archivos = glob(dirname(__DIR__) . '/db/migrations/' . $m . '_*.sql') ?: [];
    if (count($archivos) !== 1) {
        wa_chk("{$m}: un solo archivo en db/migrations", false, array_map('basename', $archivos));
        continue;
    }
    if ($antes[$m]['estado'] !== 'APLICADA') {
        wa_correr_migracion($pdo, (string) file_get_contents($archivos[0]));
    }
    $despues = wa_estado_migraciones($pdo);
    wa_chk("{$m}: " . $antes[$m]['estado'] . ' -> ' . ($despues[$m]['estado'] ?? '?') . ' (' . basename($archivos[0]) . ')',
        ($despues[$m]['estado'] ?? null) === 'APLICADA', $despues[$m] ?? null);
}
wa_fin();
