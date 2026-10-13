<?php
/**
 * test_grupo_multiempresa.php — Limite de seguridad del grupo multi-empresa.
 *
 * Un cliente con varias empresas usa UNA credencial y elige la empresa por RNC
 * (ver docs/api/integracion.md). Este script prueba las dos afirmaciones de las
 * que depende que eso sea seguro:
 *
 *   Parte 1 — Guardas de TenantResolver::switchToSibling. Sin DB: verifica que
 *             un tenant SIN grupo deniega el salto ANTES de consultar la base.
 *             De ahi sale que el codigo se puede desplegar antes de aplicar la
 *             migracion master 008 sin romper nada.
 *
 *   Parte 2 — Predicado de MasterDatabase::getTenantByRncInGroup:
 *                 WHERE rnc = :r AND grupo_id = :g AND activo = 1
 *             Lo critico es que un grupo_id NULL sea inalcanzable, porque en SQL
 *             `NULL = <valor>` es NULL, no TRUE. Si eso fallara, una credencial
 *             cualquiera podria emitir por un tenant ajeno. Corre contra SQLite,
 *             que comparte con MySQL la semantica ternaria de NULL en `=`.
 *
 *   Parte 3 — Deduccion de la empresa compradora en la aprobacion comercial
 *             (IntegracionStoreModel::empresasConRecibido, el metodo real con
 *             un PDO SQLite inyectado). Una credencial de grupo que no manda
 *             rnc_comprador aprueba por la UNICA empresa del grupo que recibio
 *             el e-CF; ninguna o varias = 422 en el controller.
 *
 * Uso:
 *   php tools/test_grupo_multiempresa.php
 *
 * Las Partes 2 y 3 necesitan pdo_sqlite; si no esta activo se saltan con aviso
 * (la Parte 1 corre siempre). Para forzarlo:
 *   php -d extension=php_pdo_sqlite.dll tools/test_grupo_multiempresa.php  (Windows)
 *   php -d extension=pdo_sqlite tools/test_grupo_multiempresa.php          (Linux)
 */

require_once __DIR__ . '/../src/TenantResolver.php';
require_once __DIR__ . '/../src/Models/IntegracionStoreModel.php';

$fallos = 0;
$total = 0;
$chk = function (string $desc, bool $ok) use (&$fallos, &$total) {
    $total++;
    if (!$ok) {
        $fallos++;
    }
    printf("  [%s] %s\n", $ok ? 'OK  ' : 'FALLO', $desc);
};

// =============================================================================
// Parte 1 — Guardas de switchToSibling (sin DB)
// =============================================================================
// No hay conexion configurada en este proceso: si alguna de estas llamadas
// intentara consultar el master, reventaria. Que pasen prueba que denegaron
// antes de tocar la base.

$ref = new ReflectionClass('TenantResolver');
$propCurrent = $ref->getProperty('current');
$propCurrent->setAccessible(true);
$setTenant = fn(?array $t) => $propCurrent->setValue(null, $t);

echo "== Parte 1: guardas de switchToSibling (sin DB) ==\n\n";

echo "-- Fila SIN la columna grupo_id (server con la migracion 008 pendiente)\n";
$setTenant([
    'id' => 3, 'nombre' => 'Ferreventura', 'rnc' => '132615123',
    'tipo' => 'integracion', 'ambiente' => 'ecf', 'activo' => 1,
]);
$chk('grupoId() devuelve null', TenantResolver::grupoId() === null);
$chk('salto a otro RNC deniega sin consultar la DB',
    TenantResolver::switchToSibling('131111111') === false);
$chk('salto al RNC propio es un no-op que permite',
    TenantResolver::switchToSibling('132615123') === true);
$chk('siblings() devuelve solo la empresa propia',
    count(TenantResolver::siblings()) === 1
    && TenantResolver::siblings()[0]['rnc'] === '132615123');

echo "\n-- grupo_id NULL explicito (migracion aplicada, tenant sin agrupar)\n";
$setTenant([
    'id' => 3, 'nombre' => 'Ferreventura', 'rnc' => '132615123',
    'tipo' => 'integracion', 'ambiente' => 'ecf', 'activo' => 1, 'grupo_id' => null,
]);
$chk('grupoId() devuelve null', TenantResolver::grupoId() === null);
$chk('salto deniega sin consultar la DB',
    TenantResolver::switchToSibling('131111111') === false);

echo "\n-- Sin tenant resuelto\n";
$setTenant(null);
$chk('salto deniega', TenantResolver::switchToSibling('131111111') === false);
$chk('siblings() devuelve vacio', TenantResolver::siblings() === []);

echo "\n-- RNC basura\n";
$setTenant([
    'id' => 3, 'nombre' => 'X', 'rnc' => '132615123',
    'tipo' => 'integracion', 'ambiente' => 'ecf', 'activo' => 1, 'grupo_id' => 3,
]);
$chk('RNC vacio deniega', TenantResolver::switchToSibling('') === false);
$chk('RNC sin digitos deniega', TenantResolver::switchToSibling('---') === false);

$setTenant(null); // no dejar estado colgando

// =============================================================================
// Parte 2 — Predicado del grupo (SQLite)
// =============================================================================
echo "\n== Parte 2: predicado de getTenantByRncInGroup ==\n\n";

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "  (SALTADA: pdo_sqlite no esta activo. Ver la cabecera de este archivo.)\n";
} else {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE tenants (
        id INTEGER PRIMARY KEY, nombre TEXT, rnc TEXT, tipo TEXT, grupo_id INTEGER, activo INTEGER
    )');

    $fixtures = [
        // id, nombre,           rnc,          tipo,          grupo_id, activo
        [1, 'Cliente Empresa 1', '131111111', 'integracion',  1,       1],
        [2, 'Cliente Empresa 2', '131111112', 'integracion',  1,       1],
        [3, 'Cliente Empresa 3', '131111113', 'integracion',  1,       1],
        [4, 'Cliente Empresa 4', '131111114', 'integracion',  1,       0], // desactivada
        [5, 'Otro Cliente SRL',  '132222222', 'integracion',  null,    1], // SIN grupo
        [6, 'Grupo Ajeno SRL',   '133333333', 'integracion',  6,       1], // OTRO grupo
        [7, 'Ferreventura',      '132615123', 'app',          null,    1], // tenant app
    ];
    $ins = $pdo->prepare('INSERT INTO tenants VALUES (?,?,?,?,?,?)');
    foreach ($fixtures as $f) {
        $ins->execute($f);
    }

    /** Replica exacta de MasterDatabase::getTenantByRncInGroup. */
    $buscarHermano = function (string $rnc, int $grupoId) use ($pdo): ?array {
        $stmt = $pdo->prepare(
            'SELECT * FROM tenants WHERE rnc = :r AND grupo_id = :g AND activo = 1 LIMIT 1'
        );
        $stmt->execute([':r' => $rnc, ':g' => $grupoId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    };

    $casos = [
        // descripcion                                       rnc destino  grupo  esperado
        ['hermano del mismo grupo -> PERMITE',               '131111112', 1, true],
        ['tercer hermano del mismo grupo -> PERMITE',        '131111113', 1, true],
        ['hermano DESACTIVADO -> DENIEGA',                   '131111114', 1, false],
        ['tenant SIN grupo (NULL) -> DENIEGA',               '132222222', 1, false],
        ['tenant de OTRO grupo -> DENIEGA',                  '133333333', 1, false],
        ['tenant app sin grupo -> DENIEGA',                  '132615123', 1, false],
        ['RNC inexistente -> DENIEGA',                       '999999999', 1, false],
        ['grupo ajeno alcanzando al grupo 1 -> DENIEGA',     '131111111', 6, false],
    ];
    foreach ($casos as [$desc, $rnc, $grupo, $esperado]) {
        $row = $buscarHermano($rnc, $grupo);
        $chk(sprintf('%-48s (rnc=%s grupo=%d)', $desc, $rnc, $grupo), ($row !== null) === $esperado);
    }

    echo "\n-- La razon de fondo\n";
    $cmp = $pdo->query('SELECT (NULL = 1) AS cmp')->fetch(PDO::FETCH_ASSOC);
    $chk('`NULL = 1` evalua a NULL (no TRUE): un grupo_id NULL nunca hace match',
        $cmp['cmp'] === null);

    echo "\n-- Listado del grupo (getTenantsInGroup)\n";
    $stmt = $pdo->prepare('SELECT rnc FROM tenants WHERE grupo_id = :g AND activo = 1 ORDER BY id');
    $stmt->execute([':g' => 1]);
    $rncs = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $chk('grupo 1 lista exactamente las 3 activas (la desactivada queda fuera)',
        $rncs === ['131111111', '131111112', '131111113']);
}

// =============================================================================
// Parte 3 — Deduccion de la empresa compradora (aprobacion comercial)
// =============================================================================
echo "\n== Parte 3: empresa compradora deducida del e-CF recibido ==\n\n";

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "  (SALTADA: pdo_sqlite no esta activo. Ver la cabecera de este archivo.)\n";
} else {
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    // Solo las columnas que usa la consulta.
    $pdo->exec('CREATE TABLE ecf_recibidos (
        id INTEGER PRIMARY KEY, tenant_id INTEGER, rnc_emisor TEXT, e_ncf TEXT
    )');
    $recibidos = [
        // tenant_id, rnc_emisor,  e_ncf
        [2, '131222333', 'E310000000010'], // solo la empresa 2
        [2, '131222333', 'E310000000010'], // reenvio de DGII: misma empresa, otra fila
        [1, '131222333', 'E310000000011'], // solo la cabeza
        [1, '131222333', 'E310000000012'], // cabeza y empresa 3: ambiguo
        [3, '131222333', 'E310000000012'],
        [5, '131222333', 'E310000000013'], // solo un tenant FUERA del grupo
        [3, '139999999', 'E310000000014'], // mismo e-NCF, otro emisor
    ];
    $ins = $pdo->prepare('INSERT INTO ecf_recibidos (tenant_id, rnc_emisor, e_ncf) VALUES (?,?,?)');
    foreach ($recibidos as $r) {
        $ins->execute($r);
    }

    // Lo que devuelve TenantResolver::siblings() para el grupo 1 (la cabeza
    // tiene grupo_id = su propio id; la desactivada no viene).
    $grupo = [
        ['id' => 1, 'nombre' => 'Cliente Empresa 1', 'rnc' => '131111111', 'tipo' => 'integracion', 'ambiente' => 'ecf'],
        ['id' => 2, 'nombre' => 'Cliente Empresa 2', 'rnc' => '131111112', 'tipo' => 'integracion', 'ambiente' => 'certecf'],
        ['id' => 3, 'nombre' => 'Cliente Empresa 3', 'rnc' => '131111113', 'tipo' => 'integracion', 'ambiente' => 'certecf'],
    ];
    $store = new IntegracionStoreModel($pdo);
    $rncsCon = fn(string $rncEmisor, string $eNcf): array
        => array_column($store->empresasConRecibido($grupo, $rncEmisor, $eNcf), 'rnc');

    $casos = [
        // descripcion                                          emisor       e-NCF            esperado
        ['lo recibio un hermano -> 1, se salta a el',           '131222333', 'E310000000010', ['131111112']],
        ['lo recibio la cabeza -> 1, sin salto',                '131222333', 'E310000000011', ['131111111']],
        ['lo recibieron dos -> ambiguo (422)',                  '131222333', 'E310000000012', ['131111111', '131111113']],
        ['nadie lo recibio -> 0 (422)',                         '131222333', 'E310000000099', []],
        ['solo lo tiene un tenant fuera del grupo -> 0 (422)',  '131222333', 'E310000000013', []],
        ['mismo e-NCF de otro emisor no cuenta -> 0 (422)',     '131222333', 'E310000000014', []],
    ];
    foreach ($casos as [$desc, $rncEmisor, $eNcf, $esperado]) {
        $chk(sprintf('%-52s (%s)', $desc, $eNcf), $rncsCon($rncEmisor, $eNcf) === $esperado);
    }

    $fila = $store->empresasConRecibido($grupo, '131222333', 'E310000000010');
    $chk('devuelve la fila completa de la empresa (id, nombre, ambiente)',
        count($fila) === 1 && $fila[0]['id'] === 2 && $fila[0]['nombre'] === 'Cliente Empresa 2');

    // Sin empresas no consulta: contra una base SIN la tabla, una consulta reventaria.
    $vacia = new IntegracionStoreModel(new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]));
    $chk('lista de empresas vacia -> [] sin consultar la DB',
        $vacia->empresasConRecibido([], '131222333', 'E310000000010') === []);
}

echo "\n" . ($fallos === 0
    ? "== TODO OK: {$total} comprobaciones ==\n"
    : "== {$fallos} de {$total} FALLARON ==\n");
exit($fallos === 0 ? 0 : 1);
