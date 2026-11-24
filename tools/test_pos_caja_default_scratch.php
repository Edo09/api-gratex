<?php
/**
 * test_pos_caja_default_scratch.php — la migracion 041 ("Caja 1" por defecto en el POS) contra un MySQL de
 * verdad: las bases scratch del write-ahead y del POS. No corre en CI (necesita MySQL), como las demas *_scratch.
 * Su mitad sin base de datos (el texto de la migracion y del snapshot) es tools/check_tenant_schema_orden.php.
 *
 * Que comprueba:
 *   1) La sentencia INSERT de la migracion sobre una TABLA GEMELA (zz_caja1_prueba, copia de pos_cajas): vacia recibe
 *      "Caja 1"; dos corridas dejan una sola; una caja renombrada o desactivada no se recrea; una empresa que ya
 *      tiene otra caja (activa o no) no recibe una segunda. Asi la prueba no depende de lo que tenga pos_cajas.
 *   2) El archivo COMPLETO sobre la pos_cajas real de la base de prueba, dentro de una transaccion que se deshace:
 *      corre sin error, el resultado trae base, cajas y caja_1, la segunda corrida no cambia el total y al final la
 *      base queda como estaba (otras suites POS crean su propia "Caja 1" y chocarian si se quedara sembrada).
 *   3) La guardia: sin base de empresa seleccionada (phpMyAdmin parado en otra base) falla en el PREPARE o en el
 *      EXECUTE de la guardia, antes del INSERT, con el error de la guardia (#1049, #1142 o #1044), y no escribe nada.
 *
 * Solo bases de prueba: wa_pdo() se niega a usar una base sin la tabla marca zz_write_ahead_scratch.
 *
 * ESTADO (2026-11-24): escrita cuando las bases scratch de HostGator ya no estaban disponibles (el usuario de
 * pruebas solo tenia USAGE, sin acceso a ninguna base). NUNCA SE CORRIO contra MySQL: solo pasa php -l y se
 * comprobo la logica del INSERT (los seis casos del bloque 1 y dos versiones rotas de la sentencia) en SQLite
 * en memoria, que no prueba la guardia, PREPARE ni DATABASE(). Con una base scratch de vuelta (la crea el dueno
 * en cPanel y la carga wa_preparar de tools/write_ahead_test_lib.php), correrla antes de fiarse de ella.
 *
 * Uso:  php -d extension=pdo_mysql tools/test_pos_caja_default_scratch.php [--tenant-db=wa_scratch] [--migracion=<ruta>]
 *       (--migracion sirve para probar una copia alterada de la 041 y ver que esta prueba la atrapa)
 *       Sale con 1 si algo falla.
 */

require_once __DIR__ . '/write_ahead_test_lib.php';

const CAJA1_GEMELA = 'zz_caja1_prueba';

$ruta = (string) wa_arg('migracion', dirname(__DIR__) . '/db/migrations/041_pos_caja_por_defecto.sql');
$base = (string) wa_arg('tenant-db', 'wa_scratch');

/** Sentencias de la migracion: sin las lineas -- y cortadas en ';' de fin de linea (la 041 no tiene ';' dentro de cadenas). */
function caja1_sentencias(string $ruta): array
{
    $sql = str_replace("\r\n", "\n", (string) file_get_contents($ruta));
    $sql = (string) preg_replace('/^[ \t]*--.*$/m', '', $sql);
    return array_values(array_filter(array_map('trim', preg_split('/;[ \t]*\n/', $sql)), static fn(string $s): bool => $s !== ''));
}

/** Corre las sentencias de la migracion en orden y devuelve la fila del SELECT final. */
function caja1_correr(PDO $pdo, array $sentencias): array
{
    $ultima = count($sentencias) - 1;
    foreach ($sentencias as $i => $s) {
        if ($i === $ultima) {
            $q = $pdo->query($s);
            $fila = $q->fetch();
            $q->closeCursor();
            return $fila === false ? [] : $fila;
        }
        $pdo->exec($s);
    }
    return [];
}

wa_chk('existe la migracion a probar', is_file($ruta), $ruta);
$sent = is_file($ruta) ? caja1_sentencias($ruta) : [];
wa_chk('la migracion son 7 sentencias (SET @db, guardia, PREPARE, EXECUTE, DEALLOCATE, INSERT, SELECT final)', count($sent) === 7, count($sent));
if (count($sent) !== 7) {
    wa_fin();
}
$pdo = wa_pdo($base);
$total = static fn(PDO $p, string $tabla = 'pos_cajas'): int => (int) $p->query("SELECT COUNT(*) FROM {$tabla}")->fetchColumn();
$totalAntes = $total($pdo);

// ---------------------------------------------------------------------------------------------------------------
echo "\n1) La sentencia sobre una tabla gemela de pos_cajas\n";
$insertGemela = str_replace('pos_cajas', CAJA1_GEMELA, $sent[5]);
wa_chk('el INSERT de la migracion nombra pos_cajas y la copia para la gemela lo cambia por completo',
    str_starts_with($sent[5], 'INSERT INTO pos_cajas') && $insertGemela !== $sent[5] && !str_contains($insertGemela, 'pos_cajas'), $insertGemela);
$filas = static fn(): array => array_map(
    static fn(array $f): array => [$f['nombre'], (int) $f['activa']],
    $pdo->query('SELECT nombre, activa FROM ' . CAJA1_GEMELA . ' ORDER BY id')->fetchAll()
);
$vaciar = static fn() => $pdo->exec('DELETE FROM ' . CAJA1_GEMELA);
try {
    $pdo->exec('DROP TABLE IF EXISTS ' . CAJA1_GEMELA);
    $pdo->exec('CREATE TABLE ' . CAJA1_GEMELA . ' LIKE pos_cajas');

    $pdo->exec($insertGemela);
    wa_chk("tabla vacia: queda UNA fila, nombre 'Caja 1', activa 1", $filas() === [['Caja 1', 1]], $filas());
    $pdo->exec($insertGemela);
    wa_chk('segunda corrida (la migracion pegada dos veces): sigue UNA fila', $filas() === [['Caja 1', 1]], $filas());

    $pdo->exec('UPDATE ' . CAJA1_GEMELA . " SET nombre = 'Principal'");
    $pdo->exec($insertGemela);
    wa_chk("renombrada a 'Principal': la corrida no agrega nada y 'Caja 1' no reaparece", $filas() === [['Principal', 1]], $filas());

    $pdo->exec('UPDATE ' . CAJA1_GEMELA . ' SET activa = 0');
    $pdo->exec($insertGemela);
    wa_chk('desactivada (activa 0): la corrida no agrega nada y sigue desactivada', $filas() === [['Principal', 0]], $filas());

    $vaciar();
    $pdo->exec('INSERT INTO ' . CAJA1_GEMELA . " (nombre) VALUES ('Mostrador')");
    $pdo->exec($insertGemela);
    wa_chk("con otra caja activa ('Mostrador'): no agrega 'Caja 1'", $filas() === [['Mostrador', 1]], $filas());

    $vaciar();
    $pdo->exec('INSERT INTO ' . CAJA1_GEMELA . " (nombre, activa) VALUES ('Mostrador', 0)");
    $pdo->exec($insertGemela);
    wa_chk("con solo una caja inactiva ('Mostrador'): no agrega 'Caja 1'", $filas() === [['Mostrador', 0]], $filas());
} finally {
    $pdo->exec('DROP TABLE IF EXISTS ' . CAJA1_GEMELA);
}

// ---------------------------------------------------------------------------------------------------------------
echo "\n2) El archivo completo sobre la pos_cajas real, en una transaccion que se deshace\n";
$pdo->beginTransaction();
try {
    $r1 = caja1_correr($pdo, $sent);
    wa_chk('el archivo corre sin error y el SELECT final trae base, cajas y caja_1',
        isset($r1['base'], $r1['cajas'], $r1['caja_1']), $r1);
    $totalTras1 = $total($pdo);
    wa_chk('despues de correrla hay al menos una caja (si la base no tenia ninguna, la crea; si tenia, no agrega)',
        $totalTras1 >= 1 && $totalTras1 >= $totalAntes && (int) ($r1['cajas'] ?? -1) === $totalTras1, [$totalAntes, $totalTras1, $r1]);
    wa_chk("si la base no tenia cajas, queda exactamente una y es 'Caja 1'; si tenia, el total no cambia",
        $totalAntes === 0 ? ($totalTras1 === 1 && (int) ($r1['caja_1'] ?? -1) === 1) : $totalTras1 === $totalAntes, [$totalAntes, $totalTras1, $r1]);
    $r2 = caja1_correr($pdo, $sent);
    wa_chk('la segunda corrida no cambia el total', $total($pdo) === $totalTras1 && (int) ($r2['cajas'] ?? -1) === $totalTras1, [$totalTras1, $r2]);
} finally {
    $pdo->rollBack();
}
wa_chk('la base de prueba queda como estaba: mismo total de cajas que al empezar', $total($pdo) === $totalAntes, [$totalAntes, $total($pdo)]);

// ---------------------------------------------------------------------------------------------------------------
echo "\n3) La guardia: sin base de empresa seleccionada\n";
$sinBase = wa_conectar();   // al servidor, sin base: DATABASE() es NULL, como phpMyAdmin parado en otro lado
$indiceFallo = null;
$errorGuardia = null;
try {
    foreach (array_slice($sent, 0, 5) as $i => $s) {
        $sinBase->exec($s);
    }
} catch (PDOException $e) {
    $indiceFallo = $i;
    $errorGuardia = [(int) ($e->errorInfo[1] ?? 0), (string) ($e->errorInfo[2] ?? $e->getMessage())];
}
// MySQL resuelve las tablas de una sentencia ya en el PREPARE, asi que el fallo puede salir en la sentencia 3 (PREPARE) o en
// la 4 (EXECUTE); lo que importa es que sea ANTES del INSERT y que sea el error de la guardia, no cualquier otro. Con sinBase
// la guardia consulta `ALTO_elige_la_base_de_la_empresa_en_el_panel`.`x`: MySQL contesta #1049 (la base no existe) a quien tiene
// permisos globales y #1142 (SELECT denegado en la tabla 'x') o #1044 (acceso denegado a esa base) al usuario de pruebas.
$esGuardia = $errorGuardia !== null
    && (($errorGuardia[0] === 1049 && stripos($errorGuardia[1], 'ALTO_elige_la_base_de_la_empresa_en_el_panel') !== false)
        || ($errorGuardia[0] === 1142 && str_contains($errorGuardia[1], "'x'"))
        || ($errorGuardia[0] === 1044 && stripos($errorGuardia[1], 'ALTO_elige_la_base_de_la_empresa_en_el_panel') !== false));
wa_chk('sin base seleccionada la guardia falla (PREPARE o EXECUTE, sentencia 3 o 4 de 7), antes del INSERT, y con el error de la guardia',
    in_array($indiceFallo, [2, 3], true) && $esGuardia, [$indiceFallo, $errorGuardia]);
wa_chk('...y no escribio nada: el total de cajas de la base de prueba no cambio', $total($pdo) === $totalAntes, [$totalAntes, $total($pdo)]);

wa_fin();
