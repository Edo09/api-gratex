<?php
/**
 * test_pos_soporte_otra_empresa.php — El usuario de soporte no aparece en ningun lado del POS de una empresa que no
 * es la suya (docs/specs/pos.md, decisiones 43 a 45). Se prueba en proceso, con SQLite en memoria (un master y la
 * base de una empresa) y la bitacora reemplazada por un doble: sin servidor, sin MySQL y sin red.
 *
 *   - posMasterModel::responsableDeVentas: las ventas de un equipo quedan a nombre de quien lo habilito si es de ESA
 *     empresa; si no (soporte, o un usuario que ya no existe), de su primer administrador, o de su primer usuario.
 *   - posMasterModel::esSoporte, tambien con una master sin la columna acceso_global (nadie lo es por un error).
 *   - PosUsuarios::estadoDe: el soporte dentro de un cliente no es elegible y no se le crea empleado.
 *   - PosUsuarios::sincronizar: nunca crea el empleado del soporte en un cliente, y si hubiera uno, lo da de baja.
 * La lista de equipos (listarEquipos, que oculta id y nombre de quien lo habilito si no es de la empresa) usa
 * funciones de MySQL: se comprobo con un SELECT de solo lectura contra la master real.
 *
 * Se niega a correr si la raiz del API trae un .env: MasterDatabase/Database lo cargarian y un error de la prueba
 * podria llegar a esa base. Corre en el CI o en un worktree limpio.
 *
 * Uso:
 *   php -d extension=pdo_sqlite tools/test_pos_soporte_otra_empresa.php      (sale con 1 si algo falla)
 */

if (is_file(dirname(__DIR__) . '/.env')) {
    echo "[ALTO] La raiz del API trae un .env: esta prueba no corre aqui (podria tocar esa base). Córrela en un worktree limpio, como el CI.\n";
    exit(2);
}
if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "Falta la extension pdo_sqlite: php -d extension=pdo_sqlite tools/test_pos_soporte_otra_empresa.php\n");
    exit(2);
}

/** Doble de la bitacora: guarda lo que se registraria. (Los archivos que se cargan no traen src/AuditLogger.php.) */
final class AuditLogger
{
    public static array $filas = [];

    public static function log(array $evento): void
    {
        self::$filas[] = $evento;
    }
}

require_once __DIR__ . '/write_ahead_test_lib.php';
require_once __DIR__ . '/../src/Pos/PosUsuarios.php';

/** SQLite en memoria con NOW() (posModel cierra sesiones con NOW() de MySQL). */
function prueba_sqlite(): PDO
{
    $ahora = static fn (): string => date('Y-m-d H:i:s');
    if (class_exists('Pdo\\Sqlite')) {            // PHP 8.4+
        $pdo = PDO::connect('sqlite::memory:');
        $pdo->createFunction('NOW', $ahora, 0);
    } else {
        $pdo = new PDO('sqlite::memory:');
        $pdo->sqliteCreateFunction('NOW', $ahora, 0);
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}

final class MasterSqlite extends posMasterModel
{
    public function __construct(PDO $pdo)
    {
        (new ReflectionProperty(posMasterModel::class, 'conexion'))->setValue($this, $pdo);
    }
}

final class PosSqlite extends posModel
{
    public function __construct(PDO $pdo)
    {
        (new ReflectionProperty(posModel::class, 'conexion'))->setValue($this, $pdo);
    }
}

/** Un master; $conAcceso = false lo arma sin la columna acceso_global (anterior a la migracion master 019). */
function prueba_master(bool $conAcceso = true): PDO
{
    $pdo = prueba_sqlite();
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, last_name TEXT, username TEXT, role TEXT, tenant_id INTEGER'
        . ($conAcceso ? ', acceso_global INTEGER NOT NULL DEFAULT 0' : '') . ')');
    // Empresa 5: 57 (user) y 56 (admin; id mayor a proposito: el admin va primero aunque no sea el id mas bajo).
    // 2: soporte, su empresa es la 1. Empresa 8: sin administrador (60 y 61). Empresa 9: sin usuarios.
    $filas = [[57, 'Ana', 'Caja', 'ana', 'user', 5, 0], [56, 'Admin', 'Demo', 'demo_admin', 'admin', 5, 0],
        [2, 'Edo', 'Garcia', 'edo', 'admin', 1, 1], [61, 'Luis', 'B', 'luis', 'user', 8, 0], [60, 'Rosa', 'A', 'rosa', 'user', 8, 0]];
    foreach ($filas as $f) {
        if ($conAcceso) {
            $pdo->prepare('INSERT INTO users VALUES (?, ?, ?, ?, ?, ?, ?)')->execute($f);
        } else {
            $pdo->prepare('INSERT INTO users VALUES (?, ?, ?, ?, ?, ?)')->execute(array_slice($f, 0, 6));
        }
    }
    return $pdo;
}

/** La base de la empresa 5 con sus empleados: [id, nombre, user_id]. Todos activos, con PIN y una sesion abierta. */
function prueba_empresa(array $empleados): PDO
{
    $pdo = prueba_sqlite();
    $pdo->exec("CREATE TABLE pos_empleados (id INTEGER PRIMARY KEY, nombre TEXT NOT NULL, rol TEXT NOT NULL DEFAULT 'cajero',
        user_id INTEGER NULL, pin_hmac TEXT NULL, pin_generado_at TEXT NULL, pin_choques INTEGER NOT NULL DEFAULT 0, pin_choque_at TEXT NULL,
        activo INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL DEFAULT '2026-11-21 08:00:00', updated_at TEXT NOT NULL DEFAULT '2026-11-21 08:00:00')");
    $pdo->exec('CREATE TABLE pos_sesiones (id INTEGER PRIMARY KEY, empleado_id INTEGER NOT NULL, equipo_id INTEGER NOT NULL, token_hash TEXT NOT NULL, cerrada_at TEXT NULL)');
    foreach ($empleados as [$id, $nombre, $userId]) {
        $pdo->prepare("INSERT INTO pos_empleados (id, nombre, user_id, pin_hmac, pin_generado_at) VALUES (?, ?, ?, ?, '2026-11-21 09:00:00')")
            ->execute([$id, $nombre, $userId, 'hmac-' . $id]);
        $pdo->prepare('INSERT INTO pos_sesiones (empleado_id, equipo_id, token_hash) VALUES (?, 24, ?)')->execute([$id, 'sesion-' . $id]);
    }
    return $pdo;
}

$master = new MasterSqlite(prueba_master());

// ---------------------------------------------------------------------------------------------------------------
echo "posMasterModel::responsableDeVentas (a nombre de quien quedan las ventas de un equipo)\n";
wa_chk('lo habilito un admin de la empresa: a su nombre', $master->responsableDeVentas(5, 56) === 56);
wa_chk('lo habilito un usuario de la empresa que no es admin: a su nombre igual (es de la empresa)', $master->responsableDeVentas(5, 57) === 57);
wa_chk('lo habilito soporte estando en el cliente: al primer administrador del cliente, no a soporte', $master->responsableDeVentas(5, 2) === 56);
wa_chk('el primer administrador va antes que un usuario con id mas bajo', $master->responsableDeVentas(5, 2) === 56 && $master->responsableDeVentas(5, null) === 56);
wa_chk('quien lo habilito ya no existe: al primer administrador', $master->responsableDeVentas(5, 999) === 56);
wa_chk('una empresa sin administrador: a su primer usuario', $master->responsableDeVentas(8, 2) === 60);
wa_chk('una empresa sin usuarios: null (la venta corta con EQUIPO_SIN_RESPONSABLE)', $master->responsableDeVentas(9, 2) === null);
wa_chk('soporte en SU propia empresa: a su nombre, como cualquiera de la empresa', $master->responsableDeVentas(1, 2) === 2);
wa_chk('un id 0 o negativo cuenta como desconocido', $master->responsableDeVentas(5, 0) === 56 && $master->responsableDeVentas(5, -3) === 56);

// ---------------------------------------------------------------------------------------------------------------
echo "posMasterModel::esSoporte\n";
wa_chk('el de soporte si; el de la empresa y uno que no existe, no', $master->esSoporte(2) && !$master->esSoporte(56) && !$master->esSoporte(999));
wa_chk('una master sin la columna acceso_global (sin la 019): nadie es soporte por un error', !(new MasterSqlite(prueba_master(false)))->esSoporte(2));

// ---------------------------------------------------------------------------------------------------------------
echo "PosUsuarios::estadoDe (el soporte que llega al POS de un cliente)\n";
$t = prueba_empresa([[1, 'Admin Demo', 56]]);
$soporteEnCliente = $master->usuarioDeEmpresa(2, 5);
wa_chk('soporte no es usuario de la empresa del cliente (usuarioDeEmpresa = null)', $soporteEnCliente === null);
$estado = PosUsuarios::estadoDe(new PosSqlite($t), 5, $soporteEnCliente);
wa_chk('no es elegible: OTRA_EMPRESA, sin empleado ni PIN', $estado['elegible'] === false && $estado['motivo'] === 'OTRA_EMPRESA'
    && $estado['empleado'] === null && $estado['creado'] === false && $estado['tiene_pin'] === false, $estado);
wa_chk('el texto le dice que como soporte puede habilitar la caja, pero no vender', str_contains((string) $estado['mensaje'], 'soporte')
    && str_contains((string) $estado['mensaje'], 'no entrar a vender'), $estado['mensaje']);
wa_chk('no se le crea ningun empleado en el cliente', (int) $t->query('SELECT COUNT(*) FROM pos_empleados')->fetchColumn() === 1
    && (int) $t->query('SELECT COUNT(*) FROM pos_empleados WHERE user_id = 2')->fetchColumn() === 0);

// ---------------------------------------------------------------------------------------------------------------
echo "PosUsuarios::sincronizar (Empleados del cliente)\n";
$t = prueba_empresa([[1, 'Admin Demo', 56], [2, 'Ana Caja', 57]]);
$r = PosUsuarios::sincronizar(new PosSqlite($t), $master, 5);
wa_chk('con todos los de la empresa ya ligados: no cambia nada, y nunca crea uno para soporte',
    $r === ['creados' => 0, 'renombrados' => 0, 'desactivados' => 0] && (int) $t->query('SELECT COUNT(*) FROM pos_empleados WHERE user_id = 2')->fetchColumn() === 0, $r);
$t = prueba_empresa([[1, 'Admin Demo', 56], [2, 'Ana Caja', 57], [3, 'Edo Garcia', 2]]);
$r = PosUsuarios::sincronizar(new PosSqlite($t), $master, 5);
$edo = $t->query('SELECT activo FROM pos_empleados WHERE id = 3')->fetch();
$sesionEdo = (int) $t->query('SELECT COUNT(*) FROM pos_sesiones WHERE empleado_id = 3 AND cerrada_at IS NULL')->fetchColumn();
wa_chk('si hubiera un empleado de soporte en el cliente, se da de baja y se le cierra la sesion',
    $r['desactivados'] === 1 && (int) $edo['activo'] === 0 && $sesionEdo === 0, [$r, $edo, $sesionEdo]);
wa_chk('nada de esto escribe en la bitacora', AuditLogger::$filas === []);

wa_fin();
