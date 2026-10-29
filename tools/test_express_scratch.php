<?php
/**
 * test_express_scratch.php — Fiscalpoint Express, plan 02 contra un MySQL de verdad: las bases scratch del
 * write-ahead y del POS (la master es wa_scratch_sin_gate; la empresa A vive en wa_scratch). Su mitad sin base
 * de datos ni red es tools/test_express_entitlements.php.
 *
 * Cada tarea del plan agrega su seccion justo encima del bloque de cierre (wa_fin), en orden. Las variables de
 * cada seccion llevan su numero (s1, s2...); $ctx es el entorno que arma express_scratch_preparar() y que las
 * secciones comparten.
 *
 * Que dice cada seccion:
 *   1) la migracion master 014 sobre la master scratch: aplica de cero, es idempotente, completa un estado a
 *      medias, su guardia corta antes de tocar nada, el ENUM rechaza una edicion desconocida, el verificador de
 *      migraciones la ve, y express_scratch_preparar() deja las dos empresas de prueba como completas.
 *
 * Nunca produccion: wa_pdo() se niega a usar una base sin la tabla marca zz_write_ahead_scratch y
 * pos_scratch_exigir() una que no se llame wa_scratch o wa_scratch_sin_gate. Las credenciales salen de
 * tools/.env (o del entorno) y no se imprimen.
 *
 * La seccion 1 quita y vuelve a crear columnas de tenants en la master scratch (y deja a las dos empresas de
 * prueba como completas al terminar): no corras a la vez otra suite que use esas bases.
 *
 * Uso:
 *   php -d extension=curl -d extension=openssl -d extension=pdo_mysql tools/test_express_scratch.php
 *   (un -d extension=... solo para lo que tu php.ini no carga; ver write_ahead_test_lib.php)
 * Sale con 1 si algo falla.
 */

require_once __DIR__ . '/pos_scratch_prepare.php';

/** La migracion master 014, tal como se pega en phpMyAdmin. */
const EXPRESS_MIGRACION_014 = __DIR__ . '/../db/master_migrations/014_tenants_edicion.sql';

/**
 * Corre un archivo SQL entero en una sola llamada, como cuando se pega en la pestaña SQL de phpMyAdmin
 * (PDO_MYSQL acepta varias sentencias con los prepares emulados). nextRowset() pasa por cada sentencia, asi que
 * un error en la sentencia N salta como excepcion y no se pierde.
 * @return array las filas del ultimo resultado con columnas (el SELECT final de una migracion)
 */
function correrSql(PDO $pdo, string $sql): array
{
    $stmt = $pdo->query($sql);
    $filas = [];
    do {
        if ($stmt->columnCount() > 0) {
            $filas = $stmt->fetchAll();
        }
    } while ($stmt->nextRowset());
    return $filas;
}

/**
 * Corre un SQL que tiene que FALLAR y devuelve el error de MySQL que lo detuvo, [codigo, mensaje]; null si termino
 * sin error. Al mensaje se le tapa el 'usuario'@'host' que el driver suele traer: nada de eso se imprime.
 * @return ?array{0:int,1:string}
 */
function errorDeSql(PDO $pdo, string $sql): ?array
{
    try {
        correrSql($pdo, $sql);
        return null;
    } catch (PDOException $e) {
        $info = (array) $e->errorInfo;
        return [(int) ($info[1] ?? 0), (string) preg_replace("/'[^']*'@'[^']*'/", "'***'@'***'", (string) ($info[2] ?? ''))];
    }
}

/**
 * El entorno scratch de las pruebas de Express: el de las suites del POS (pos_scratch_preparar: master
 * wa_scratch_sin_gate, empresa A en wa_scratch, empresa B en wa_scratch_sin_gate, con sus roles y usuarios) mas la
 * migracion master 014 aplicada a esa master. Deja las dos empresas de prueba como completas: edicion = 'completa',
 * las otras tres columnas de la 014 en NULL y sin certificado. Lo mismo se repite al terminar este proceso (lo que
 * una prueba cambie en las empresas se restaura solo, tambien si la prueba falla): primero pos_scratch_preparar()
 * devuelve roles, usuarios y campos del POS, que nunca toca las columnas de la 014, y despues se reponen ellas. Si
 * una corrida anterior murio sin llegar a esa restauracion (kill -9), el reinicio de arriba lo arregla en la
 * siguiente: no se filtra a la comparacion dorada del plan 01 ni a las suites del POS.
 * @return array{master:string, dbA:string, dbB:string, tenantA:int, tenantB:int, cred:array, pdo:PDO}
 *         lo de pos_scratch_preparar() mas 'pdo' sobre la master scratch (wa_scratch_sin_gate)
 */
function express_scratch_preparar(): array
{
    static $restauracionPuesta = false;
    // La 014 (idempotente: tambien repone lo que una prueba haya quitado) y el reinicio de las dos empresas de
    // prueba, por RNC porque los id cambian si alguien limpia y vuelve a preparar.
    $reiniciar = static function (PDO $pdo): void {
        correrSql($pdo, (string) file_get_contents(EXPRESS_MIGRACION_014));
        $rncs = array_column(POS_SCRATCH_TENANTS, 'rnc');
        $pdo->prepare(
            "UPDATE tenants SET edicion = 'completa', modulos_habilitados = NULL, tipos_ecf_habilitados = NULL, cert_vence = NULL,
                    cert_path = NULL, cert_pass_encrypted = NULL
             WHERE rnc IN (" . implode(',', array_fill(0, count($rncs), '?')) . ')'
        )->execute($rncs);
    };
    $info = pos_scratch_preparar();
    $pdo = wa_pdo(POS_SCRATCH_BASE_MASTER);
    $reiniciar($pdo);
    if (!$restauracionPuesta) {
        $restauracionPuesta = true;
        register_shutdown_function(static function () use ($reiniciar): void {
            try {
                pos_scratch_preparar();
                $reiniciar(wa_pdo(POS_SCRATCH_BASE_MASTER));
            } catch (Throwable $e) {
                // wa_describir_excepcion: clase, archivo:linea y codigos; nunca el mensaje del driver (trae usuario y host).
                echo '[FALLO] no se pudo dejar limpia la master scratch al terminar: ' . wa_describir_excepcion($e) . "\n";
                exit(1);
            }
        });
    }
    return $info + ['pdo' => $pdo];
}

// Modo hijo: lo lanza la seccion 1 para ver la restauracion de al terminar. Ensucia las dos empresas (columnas
// de la 014, campo del POS, un usuario y los permisos de un rol) y sale sin limpiar nada. No se corre a mano.
//   php <flags> tools/test_express_scratch.php --hijo=restauracion
if (wa_arg('hijo') === 'restauracion') {
    $hijo = express_scratch_preparar();
    $ids = [(int) $hijo['tenantA'], (int) $hijo['tenantB']];
    $hijo['pdo']->exec("UPDATE tenants SET edicion = 'express', modulos_habilitados = 'facturacion', tipos_ecf_habilitados = '45',
                               cert_vence = '2027-01-31', cert_path = 'certificado_dgii/prueba/cert.p12', pos_enabled = 0
                        WHERE id IN (" . implode(',', $ids) . ')');
    $hijo['pdo']->exec("UPDATE users SET role = 'user' WHERE username = 'admin_pos_a'");
    $hijo['pdo']->exec("DELETE FROM role_permissions WHERE role_id IN (SELECT id FROM roles WHERE tenant_id = {$ids[0]} AND name = 'user')");
    exit(0);
}

$ctx = express_scratch_preparar();

// ---------------------------------------------------------------------------
// 1) Migracion master 014 sobre la master scratch
// ---------------------------------------------------------------------------
echo "\n== 1) Migracion master 014 sobre la master scratch ==\n";
$s1Pdo = $ctx['pdo'];
$s1Sql = (string) file_get_contents(EXPRESS_MIGRACION_014);
$s1Cols = ['edicion', 'modulos_habilitados', 'tipos_ecf_habilitados', 'cert_vence'];
$s1Ids = [(int) $ctx['tenantA'], (int) $ctx['tenantB']];

/** Posicion de cada columna de tenants en la master scratch (nombre => ordinal). */
$s1Posiciones = static function () use ($s1Pdo): array {
    $st = $s1Pdo->query("SELECT COLUMN_NAME, ORDINAL_POSITION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenants'");
    return array_map('intval', array_column($st->fetchAll(), 'ORDINAL_POSITION', 'COLUMN_NAME'));
};
/** Quita de tenants las columnas de la 014 que se pidan (solo en la master scratch: wa_exigir_marca corta si la conexion no lo es). */
$s1Soltar = static function (array $columnas) use ($s1Pdo, $s1Cols, $s1Posiciones): void {
    wa_exigir_marca($s1Pdo);
    $hay = $s1Posiciones();
    foreach ($columnas as $columna) {
        if (!in_array($columna, $s1Cols, true)) {
            throw new InvalidArgumentException("no es una columna de la 014: {$columna}");
        }
        if (isset($hay[$columna])) {
            $s1Pdo->exec("ALTER TABLE tenants DROP COLUMN {$columna}");
        }
    }
};
/** Cuantas de las cuatro columnas de la 014 tiene tenants ahora. */
$s1Cuantas = static fn (): int => count(array_intersect($s1Cols, array_keys($s1Posiciones())));
/** La fila de una empresa de prueba (sus columnas de la 014, su certificado y pos_enabled), con cada valor en texto o NULL. */
$s1Fila = static function (int $id) use ($s1Pdo): array {
    $st = $s1Pdo->prepare('SELECT edicion, modulos_habilitados, tipos_ecf_habilitados, cert_vence, cert_path, cert_pass_encrypted, pos_enabled FROM tenants WHERE id = :id');
    $st->execute([':id' => $id]);
    return array_map(static fn ($valor): ?string => $valor === null ? null : (string) $valor, (array) $st->fetch());
};
/** Conexion al mismo servidor sin base seleccionada (null) o parada en una base de sistema: lo que ve la 014 si phpMyAdmin no tiene la master elegida. */
$s1SinBase = static function (?string $sistema): PDO {
    $c = wa_credenciales();
    set_error_handler(static fn (): bool => true); // mysqlnd avisa con el host: se calla mientras conecta
    try {
        $otra = new PDO("mysql:host={$c['host']};port={$c['port']};" . ($sistema === null ? '' : "dbname={$sistema};") . 'charset=utf8mb4',
            $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    } catch (PDOException $e) {
        echo '[FALLO] no se pudo conectar ' . ($sistema === null ? 'sin base' : "a {$sistema}") . " (codigo {$e->getCode()})\n";
        exit(1);
    } finally {
        restore_error_handler();
    }
    if (($otra->query('SELECT DATABASE()')->fetchColumn() ?: null) !== $sistema) {
        echo "[FALLO] la conexion de la guardia quedo en otra base: no se prueba nada\n";
        exit(1);
    }
    return $otra;
};
/**
 * ¿Es el error de la guardia de la 014? Con la base equivocada la guardia consulta `ALTO_elige_la_base_master_en_el_panel`.`x`:
 * MySQL contesta #1049 (la base no existe, o #1146 si existiera sin esa tabla) a quien tiene permisos globales, como el
 * usuario de phpMyAdmin, y #1142 (SELECT denegado en la tabla 'x') al usuario de pruebas, que solo llega a sus bases
 * scratch: revisa permisos antes que existencia (lo mismo que ve tools/test_conduces_mysql.php con la guardia de la 031).
 * Los tres nombran lo que puso la guardia.
 */
$s1EsGuardia = static fn (?array $e): bool => $e !== null
    && ((in_array($e[0], [1049, 1146], true) && str_contains($e[1], 'ALTO_elige_la_base_master'))
        || ($e[0] === 1142 && str_contains($e[1], "'x'")));

wa_chk('express_scratch_preparar(): lo del POS mas un PDO sobre la master scratch, con master, dbA, dbB, tenantA, tenantB y cred',
    array_diff(['master', 'dbA', 'dbB', 'tenantA', 'tenantB', 'cred', 'pdo'], array_keys($ctx)) === []
    && $s1Pdo instanceof PDO && $s1Pdo->query('SELECT DATABASE()')->fetchColumn() === $ctx['master']
    && $s1Ids[0] > 0 && $s1Ids[1] > 0 && $s1Ids[0] !== $s1Ids[1], array_keys($ctx));
wa_chk('precondicion: la master scratch tiene inventario_estricto (la 013), de donde cuelga la 014',
    isset($s1Posiciones()['inventario_estricto']));

// Sin las cuatro columnas: la 014 las crea.
$s1Soltar($s1Cols);
wa_chk('sin las cuatro columnas, tenants no tiene ninguna', $s1Cuantas() === 0);
$s1Resultado = correrSql($s1Pdo, $s1Sql);
wa_chk('014 sobre una master sin las cuatro columnas: el resultado dice columnas_ok = 4 en la master scratch',
    count($s1Resultado) === 1 && (int) ($s1Resultado[0]['columnas_ok'] ?? 0) === 4 && ($s1Resultado[0]['base'] ?? null) === $ctx['master'],
    ['filas' => count($s1Resultado), 'columnas_ok' => $s1Resultado[0]['columnas_ok'] ?? null]);
$s1Definiciones = [];
foreach ($s1Pdo->query(
    "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenants' AND COLUMN_NAME IN ('" . implode("','", $s1Cols) . "')"
)->fetchAll() as $s1F) {
    $s1Definiciones[$s1F['COLUMN_NAME']] = [$s1F['COLUMN_TYPE'], $s1F['IS_NULLABLE'], $s1F['COLUMN_DEFAULT']];
}
ksort($s1Definiciones);
wa_chk('014: edicion ENUM NOT NULL DEFAULT completa, modulos TEXT NULL, tipos VARCHAR(64) NULL y cert_vence DATE NULL, como queda en MySQL',
    $s1Definiciones === [
        'cert_vence' => ['date', 'YES', null],
        'edicion' => ["enum('completa','express')", 'NO', 'completa'],
        'modulos_habilitados' => ['text', 'YES', null],
        'tipos_ecf_habilitados' => ['varchar(64)', 'YES', null],
    ], $s1Definiciones);
$s1P = $s1Posiciones();
wa_chk('014: las cuatro quedan en orden detras de inventario_estricto y antes de ambiente',
    isset($s1P['inventario_estricto'], $s1P['edicion'], $s1P['modulos_habilitados'], $s1P['tipos_ecf_habilitados'], $s1P['cert_vence'], $s1P['ambiente'])
    && $s1P['inventario_estricto'] < $s1P['edicion'] && $s1P['edicion'] < $s1P['modulos_habilitados']
    && $s1P['modulos_habilitados'] < $s1P['tipos_ecf_habilitados'] && $s1P['tipos_ecf_habilitados'] < $s1P['cert_vence']
    && $s1P['cert_vence'] < $s1P['ambiente'], $s1P);
$s1Filas = $s1Pdo->query('SELECT edicion, modulos_habilitados, tipos_ecf_habilitados, cert_vence FROM tenants')->fetchAll();
wa_chk('014 no enciende a nadie: toda empresa que ya existia queda completa y con las otras tres columnas en NULL',
    count($s1Filas) >= 2 && $s1Filas === array_fill(0, count($s1Filas),
        ['edicion' => 'completa', 'modulos_habilitados' => null, 'tipos_ecf_habilitados' => null, 'cert_vence' => null]), $s1Filas);

// Aplicada otra vez: no cambia nada, ni las posiciones de las columnas ni los datos de las empresas.
$s1Datos = static fn (): array => $s1Pdo->query('SELECT id, edicion, modulos_habilitados, tipos_ecf_habilitados, cert_vence FROM tenants ORDER BY id')->fetchAll();
$s1Antes = [$s1Posiciones(), $s1Datos()];
$s1Resultado = correrSql($s1Pdo, $s1Sql);
wa_chk('014 otra vez: no cambia nada (mismas posiciones y mismos datos) y columnas_ok sigue en 4',
    [$s1Posiciones(), $s1Datos()] === $s1Antes && (int) ($s1Resultado[0]['columnas_ok'] ?? 0) === 4, ['columnas_ok' => $s1Resultado[0]['columnas_ok'] ?? null]);

// La guardia: sin base seleccionada y parada en information_schema corta ANTES de la primera sonda, y no cambia nada.
$s1Soltar($s1Cols);
$s1Guardias = [];
foreach ([null, 'information_schema'] as $s1Sistema) {
    $s1Otra = $s1SinBase($s1Sistema);
    $s1Error = errorDeSql($s1Otra, $s1Sql);
    $s1Guardias[$s1Sistema ?? '(sin base)'] = [
        'guardia' => $s1EsGuardia($s1Error),
        // @has_col lo fija la primera sonda: si sigue NULL, el lote se detuvo en la guardia y no llego a ninguna.
        'sin_sondas' => $s1Otra->query('SELECT @has_col')->fetchColumn() === null,
        'error' => $s1Error,
    ];
}
wa_chk('014 sin base de empresa seleccionada: falla en la guardia (no en un ALTER) antes de la primera sonda',
    $s1Guardias['(sin base)']['guardia'] && $s1Guardias['(sin base)']['sin_sondas'], $s1Guardias['(sin base)']);
wa_chk('014 parada en information_schema: falla en la guardia (no en un ALTER) antes de la primera sonda',
    $s1Guardias['information_schema']['guardia'] && $s1Guardias['information_schema']['sin_sondas'], $s1Guardias['information_schema']);
wa_chk('014 con la base equivocada no cambia nada: la master scratch sigue sin las cuatro columnas', $s1Cuantas() === 0);
unset($s1Otra);
correrSql($s1Pdo, $s1Sql);
wa_chk('014 en la base correcta, justo despues, vuelve a dejar las cuatro', $s1Cuantas() === 4);

// Un estado a medias: sin modulos_habilitados ni cert_vence. Completa las cuatro en orden y no toca lo que habia.
$s1Pdo->prepare("UPDATE tenants SET edicion = 'express', tipos_ecf_habilitados = '45' WHERE id = :id")->execute([':id' => $s1Ids[0]]);
$s1Soltar(['cert_vence', 'modulos_habilitados']);
wa_chk('estado a medias: solo quedan edicion y tipos_ecf_habilitados', $s1Cuantas() === 2);
$s1Resultado = correrSql($s1Pdo, $s1Sql);
$s1P = $s1Posiciones();
$s1A = $s1Fila($s1Ids[0]);
wa_chk('014 a medias: completa las cuatro en orden edicion < modulos_habilitados < tipos_ecf_habilitados < cert_vence y respeta lo que ya habia',
    (int) ($s1Resultado[0]['columnas_ok'] ?? 0) === 4
    && isset($s1P['edicion'], $s1P['modulos_habilitados'], $s1P['tipos_ecf_habilitados'], $s1P['cert_vence'])
    && $s1P['edicion'] < $s1P['modulos_habilitados'] && $s1P['modulos_habilitados'] < $s1P['tipos_ecf_habilitados'] && $s1P['tipos_ecf_habilitados'] < $s1P['cert_vence']
    && $s1A['edicion'] === 'express' && $s1A['tipos_ecf_habilitados'] === '45' && $s1A['modulos_habilitados'] === null && $s1A['cert_vence'] === null,
    ['posiciones' => $s1P, 'A' => array_intersect_key($s1A, array_flip($s1Cols))]);
$s1Pdo->prepare("UPDATE tenants SET edicion = 'completa', tipos_ecf_habilitados = NULL WHERE id = :id")->execute([':id' => $s1Ids[0]]);

// El ENUM rechaza una edicion desconocida cuando el servidor es estricto (como el de produccion). Conexion propia: el sql_mode no se queda en $s1Pdo.
$s1Estricto = wa_pdo(POS_SCRATCH_BASE_MASTER);
$s1Estricto->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES'");
$s1Estricto->beginTransaction(); // si el UPDATE se aceptara por error, no queda nada guardado
$s1Error = errorDeSql($s1Estricto, "UPDATE tenants SET edicion = 'basico'");
$s1Estricto->rollBack();
unset($s1Estricto);
wa_chk("con sql_mode STRICT_ALL_TABLES, UPDATE tenants SET edicion = 'basico' falla con 1265", ($s1Error[0] ?? 0) === 1265, $s1Error);
wa_chk('...y ninguna empresa quedo con una edicion distinta de completa',
    (int) $s1Pdo->query("SELECT COUNT(*) FROM tenants WHERE edicion <> 'completa'")->fetchColumn() === 0);

// El verificador de migraciones del master: su primera consulta (sin las lineas "--", cortada en el primer ";").
$s1Verificador = implode("\n", array_filter(
    preg_split('/\R/', (string) file_get_contents(__DIR__ . '/verificar_migraciones_master.sql')) ?: [],
    static fn (string $l): bool => !str_starts_with(ltrim($l), '--')
));
$s1Consulta = substr($s1Verificador, 0, (int) strpos($s1Verificador, ';'));
/** Lo que dice la fila 014 del verificador (APLICADA, FALTA...), o null si no hay fila. */
$s1Estado = static function () use ($s1Pdo, $s1Consulta): ?string {
    foreach ($s1Pdo->query($s1Consulta)->fetchAll() as $s1F) {
        if ((string) $s1F['migracion'] === '014') {
            return (string) $s1F['estado'];
        }
    }
    return null;
};
$s1Dice = [$s1Estado()];
$s1Soltar(['cert_vence']);
$s1Dice[] = $s1Estado();
correrSql($s1Pdo, $s1Sql);
$s1Dice[] = $s1Estado();
wa_chk('verificador del master: la 014 dice APLICADA con las cuatro, FALTA sin cert_vence y APLICADA al aplicarla de nuevo',
    $s1Dice === ['APLICADA', 'FALTA', 'APLICADA'], $s1Dice);

// express_scratch_preparar() deja a las dos empresas limpias aunque una corrida anterior las haya dejado sucias.
$s1Sucio = $s1Pdo->prepare(
    "UPDATE tenants SET edicion = 'express', modulos_habilitados = 'facturacion,clientes', tipos_ecf_habilitados = :tipos, cert_vence = '2027-01-31',
            cert_path = 'certificado_dgii/prueba/cert.p12', cert_pass_encrypted = :clave WHERE id = :id"
);
foreach ([[$s1Ids[0], '45'], [$s1Ids[1], '31,32']] as [$s1Id, $s1Tipos]) {
    $s1Sucio->bindValue(':tipos', $s1Tipos);
    $s1Sucio->bindValue(':clave', 'no-es-una-clave', PDO::PARAM_LOB);
    $s1Sucio->bindValue(':id', $s1Id, PDO::PARAM_INT);
    $s1Sucio->execute();
}
wa_chk('precondicion: las dos empresas de prueba quedaron sucias (express, tipos, certificado)',
    $s1Fila($s1Ids[0])['edicion'] === 'express' && $s1Fila($s1Ids[1])['tipos_ecf_habilitados'] === '31,32' && $s1Fila($s1Ids[1])['cert_path'] !== null);
$s1Otra = express_scratch_preparar();
$s1Limpia = ['edicion' => 'completa', 'modulos_habilitados' => null, 'tipos_ecf_habilitados' => null, 'cert_vence' => null, 'cert_path' => null, 'cert_pass_encrypted' => null];
$s1Esperado = [];
foreach ($s1Ids as $s1Id) {
    $s1Esperado[$s1Id] = array_intersect_key($s1Fila($s1Id), $s1Limpia);
}
wa_chk('express_scratch_preparar() otra vez: A y B quedan edicion = completa, las otras tres de la 014 en NULL y sin cert_path ni cert_pass_encrypted',
    $s1Esperado === [$s1Ids[0] => $s1Limpia, $s1Ids[1] => $s1Limpia]
    && $s1Otra['tenantA'] === $ctx['tenantA'] && $s1Otra['tenantB'] === $ctx['tenantB'], $s1Esperado);
unset($s1Otra);

// La restauracion de al terminar el proceso: un hijo ensucia todo y sale sin limpiar. Aqui se ve lo que dejo.
$s1Hijo = wa_correr(array_merge([PHP_BINARY], wa_php_flags(), [__FILE__, '--hijo=restauracion']));
$s1Defaults = (require dirname(__DIR__) . '/config/permissions.php')['defaults'];
$s1Permisos = $s1Pdo->prepare("SELECT COUNT(*) FROM role_permissions rp JOIN roles r ON r.id = rp.role_id WHERE r.tenant_id = :t AND r.name = 'user'");
$s1Permisos->execute([':t' => $s1Ids[0]]);
$s1Rol = $s1Pdo->query("SELECT role FROM users WHERE username = 'admin_pos_a'")->fetchColumn();
$s1Despues = ['hijo' => ['codigo' => $s1Hijo['codigo'], 'bytes_impresos' => strlen($s1Hijo['salida'])]];
$s1LimpiaConPos = $s1Limpia + ['pos_enabled' => '1'];
foreach ($s1Ids as $s1Id) {
    $s1Despues[$s1Id] = array_intersect_key($s1Fila($s1Id), $s1LimpiaConPos);
}
wa_chk('al terminar un proceso se restaura todo: las dos empresas completas (columnas de la 014) y con el POS, admin_pos_a admin y los permisos del rol user',
    $s1Hijo['codigo'] === 0 && $s1Hijo['salida'] === ''
    && $s1Despues[$s1Ids[0]] === $s1LimpiaConPos && $s1Despues[$s1Ids[1]] === $s1LimpiaConPos
    && $s1Rol === 'admin' && (int) $s1Permisos->fetchColumn() === count($s1Defaults['user']), [$s1Despues, $s1Rol]);

// ---------------------------------------------------------------------------
// Cierre. Cada tarea agrega su seccion justo encima de este bloque.
// ---------------------------------------------------------------------------
wa_fin();
