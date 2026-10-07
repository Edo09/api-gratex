<?php
/**
 * test_write_ahead_gate.php — la compuerta de despliegue del write-ahead
 * (spec 6.1 y 12.0, pasos 1-2): tools/verificar_migraciones_tenant.sql dice
 * APLICADA en 027 y 028 sobre la base de prueba, y FALTA sobre una copia a la
 * que se le quitan las dos migraciones a proposito.
 *
 * La copia queda rota a proposito (indice global uk_e_ncf, estado_dgii
 * VARCHAR(20)): la usan tools/test_reservar_confirmar.php (desborde 6.2) y
 * tools/test_write_ahead_api.php (12.0, pasos 3-4). Sobre --tenant-db no se
 * escribe nada. Se puede correr las veces que haga falta.
 *
 * Los nombres de base se dan sin el prefijo de WA_DB_PREFIX (con el tambien
 * valen: wa_base_real es idempotente). Lo que se imprime lleva el nombre real.
 *
 * Uso:
 *   php -d extension=pdo_mysql tools/test_write_ahead_gate.php --tenant-db=wa_scratch --copia-db=wa_scratch_sin_gate
 */

require_once __DIR__ . '/write_ahead_test_lib.php';

// Primero se valida que vengan los dos argumentos y luego se pasan al nombre
// real del servidor (con prefijo): DATABASE(), information_schema y los ALTER
// usan siempre el nombre real.
$baseArg = (string) wa_arg('tenant-db', '');
$copiaArg = (string) wa_arg('copia-db', '');
if ($baseArg === '' || $copiaArg === '') {
    echo "Uso: php tools/test_write_ahead_gate.php --tenant-db=<base> --copia-db=<otra base de prueba>\n";
    exit(2);
}
$base = wa_base_real($baseArg);
$copia = wa_base_real($copiaArg);
if ($base === $copia) {
    echo "Uso: php tools/test_write_ahead_gate.php --tenant-db=<base> --copia-db=<otra base de prueba>\n";
    echo "     --tenant-db y --copia-db no pueden ser la misma base: la copia se rompe a proposito.\n";
    exit(2);
}

/** Corre el verificador (un solo SELECT) en la base de $pdo. @return array<string,array> por migracion */
function wa_verificar_migraciones(PDO $pdo): array
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

/** Ancho de facturas.estado_dgii y si existe el indice $indice, en la base de $pdo. */
function wa_facturas_esquema(PDO $pdo, string $base, string $indice): array
{
    $col = $pdo->prepare("SELECT CHARACTER_MAXIMUM_LENGTH, COLUMN_COMMENT FROM information_schema.COLUMNS
                          WHERE TABLE_SCHEMA = :b AND TABLE_NAME = 'facturas' AND COLUMN_NAME = 'estado_dgii'");
    $col->execute([':b' => $base]);
    $c = $col->fetch(PDO::FETCH_ASSOC) ?: [];
    $idx = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS
                          WHERE TABLE_SCHEMA = :b AND TABLE_NAME = 'facturas' AND INDEX_NAME = :i");
    $idx->execute([':b' => $base, ':i' => $indice]);
    return [
        'ancho' => (int) ($c['CHARACTER_MAXIMUM_LENGTH'] ?? 0),
        'comentario' => (string) ($c['COLUMN_COMMENT'] ?? ''),
        'indice' => (int) $idx->fetchColumn() > 0,
    ];
}

$pdoBase = wa_pdo($base);
$pdoCopia = wa_pdo($copia);

// Romper la copia: deshacer 027 (vuelve la clave global uk_e_ncf) y 028
// (estado_dgii a VARCHAR(20)). Cada ALTER mira information_schema antes, para
// que el script se pueda repetir.
$esq = wa_facturas_esquema($pdoCopia, $copia, 'uk_e_ncf_amb');
if ($esq['indice']) {
    $pdoCopia->exec('ALTER TABLE facturas DROP INDEX uk_e_ncf_amb, ADD UNIQUE KEY uk_e_ncf (e_ncf)');
}
if ($esq['ancho'] > 20) {
    // Mismo COMMENT que db/tenant_schema.sql:218-220 (se lee de la propia columna).
    $pdoCopia->exec("ALTER TABLE facturas MODIFY estado_dgii VARCHAR(20) NOT NULL DEFAULT 'PENDIENTE' COMMENT "
        . $pdoCopia->quote($esq['comentario']));
}

echo "Base de prueba ({$base})\n";
$v = wa_verificar_migraciones($pdoBase);
wa_chk("base: la columna base dice {$base} en todas las filas",
    $v !== [] && array_unique(array_column($v, 'base')) === [$base], array_unique(array_column($v, 'base')));
wa_chk('base: 027 APLICADA', ($v['027']['estado'] ?? null) === 'APLICADA', $v['027'] ?? null);
wa_chk('base: 028 APLICADA', ($v['028']['estado'] ?? null) === 'APLICADA', $v['028'] ?? null);

echo "Copia sin la compuerta ({$copia})\n";
$v = wa_verificar_migraciones($pdoCopia);
wa_chk("copia: la columna base dice {$copia} en todas las filas",
    $v !== [] && array_unique(array_column($v, 'base')) === [$copia], array_unique(array_column($v, 'base')));
wa_chk('copia: 027 FALTA', ($v['027']['estado'] ?? null) === 'FALTA', $v['027'] ?? null);
wa_chk('copia: 028 FALTA', ($v['028']['estado'] ?? null) === 'FALTA', $v['028'] ?? null);
$resto = array_diff_key($v, ['027' => 1, '028' => 1]);
wa_chk('copia: las demas migraciones siguen APLICADA',
    $resto !== [] && array_unique(array_column($resto, 'estado')) === ['APLICADA'], array_column($resto, 'estado', 'migracion'));

echo "La base de prueba no se toco\n";
// Se pregunta por la clave vieja: uk_e_ncf_amb ya la afirmo el verificador (027 APLICADA).
$esq = wa_facturas_esquema($pdoBase, $base, 'uk_e_ncf');
wa_chk('base: sin la clave vieja uk_e_ncf', $esq['indice'] === false, $esq);
wa_chk('base: estado_dgii sigue en 40', $esq['ancho'] === 40, $esq['ancho']);

wa_fin();
