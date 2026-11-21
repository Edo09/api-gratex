<?php
/**
 * test_pos_sesion_restablecida.php — Cuando un administrador restablece el PIN de un usuario de FiscalPoint
 * (docs/specs/pos.md A5b) y esa persona estaba dentro del POS, el servidor tiene que decirle POR QUE ya no vale
 * su sesion ("restablecieron tu PIN") y no mandarla a escribir un PIN que ya no existe. Se prueba en proceso y
 * con SQLite en memoria: sin servidor, sin MySQL y sin red.
 *
 *   - posModel::sesionPerdidaPorRestablecimiento: solo es verdad para una sesion DE ESE EQUIPO de un empleado
 *     que es usuario de FiscalPoint, esta activo y tiene el PIN pendiente. Ni un token desconocido, ni otro
 *     equipo, ni quien ya tiene PIN, ni un empleado creado a mano, ni uno desactivado la activan.
 *   - PosAuth::requerirSesion: el texto, el HTTP, el codigo y el campo pin_restablecido de cada caso.
 *   - PosAuth::esPinRestablecido: lo que GET /api/pos/estado repite como pin_restablecido.
 *
 * posModel::sesionVigente usa NOW() de MySQL: aqui se sustituye por una que nunca encuentra la sesion, porque el
 * caso que se prueba es el de la sesion que ya no vale. La consulta nueva es SQL estandar y corre igual en SQLite.
 *
 * Uso:
 *   php -d extension=pdo_sqlite tools/test_pos_sesion_restablecida.php      (sale con 1 si algo falla)
 */

require_once __DIR__ . '/write_ahead_test_lib.php';
require_once __DIR__ . '/../src/Pos/PosAuth.php';
require_once __DIR__ . '/../src/Models/posModel.php';

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "Falta la extension pdo_sqlite: php -d extension=pdo_sqlite tools/test_pos_sesion_restablecida.php\n");
    exit(2);
}

/** posModel sin MySQL: la conexion es la SQLite de la prueba. */
final class PosModelSqlite extends posModel
{
    public function __construct(PDO $pdo)
    {
        (new ReflectionProperty(posModel::class, 'conexion'))->setValue($this, $pdo);
    }

    /** La de MySQL usa NOW() - INTERVAL: aqui la sesion nunca esta vigente, que es el caso que se prueba. */
    public function sesionVigente(string $tokenHash, int $equipoId): ?array
    {
        return null;
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE pos_empleados (id INTEGER PRIMARY KEY, nombre TEXT, user_id INTEGER NULL, pin_hmac TEXT NULL, activo INTEGER NOT NULL DEFAULT 1)');
$pdo->exec('CREATE TABLE pos_sesiones (id INTEGER PRIMARY KEY, empleado_id INTEGER NOT NULL, equipo_id INTEGER NOT NULL, token_hash TEXT NOT NULL, cerrada_at TEXT NULL)');

// Empleados: 1 Ana, usuario de FiscalPoint con el PIN restablecido (pendiente); 2 Beto, usuario con PIN; 3 Caro,
// sin usuario y sin PIN (no deberia existir: la base lo admite desde la 040); 4 Dani, usuario pendiente y desactivado.
$pdo->exec("INSERT INTO pos_empleados (id, nombre, user_id, pin_hmac, activo) VALUES
    (1, 'Ana',  56,   NULL,  1),
    (2, 'Beto', 57,   'abc', 1),
    (3, 'Caro', NULL, NULL,  1),
    (4, 'Dani', 58,   NULL,  0)");
$EQUIPO = 24;
$OTRO_EQUIPO = 25;
$sesion = static function (int $id, int $empleado, int $equipo, string $token, ?string $cerrada) use ($pdo): void {
    $pdo->prepare('INSERT INTO pos_sesiones (id, empleado_id, equipo_id, token_hash, cerrada_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$id, $empleado, $equipo, hash('sha256', $token), $cerrada]);
};
$sesion(1, 1, $EQUIPO, 'tok-ana-cerrada', '2026-11-21 10:28:58');   // la cierra el restablecimiento
$sesion(2, 1, $EQUIPO, 'tok-ana-abierta', null);                    // abierta pero vencida por inactividad
$sesion(3, 2, $EQUIPO, 'tok-beto', '2026-11-21 09:00:00');
$sesion(4, 3, $EQUIPO, 'tok-caro', '2026-11-21 09:00:00');
$sesion(5, 4, $EQUIPO, 'tok-dani', '2026-11-21 09:00:00');

$pos = new PosModelSqlite($pdo);
$hash = static fn (string $t): string => hash('sha256', $t);
$equipo = ['id' => $EQUIPO, 'tenant_id' => 5];

echo "posModel::sesionPerdidaPorRestablecimiento\n";
wa_chk('la sesion cerrada de un usuario con el PIN restablecido: si', $pos->sesionPerdidaPorRestablecimiento($hash('tok-ana-cerrada'), $EQUIPO) === true);
wa_chk('una sesion abierta pero vencida del mismo usuario pendiente: tambien (sin PIN no hay otra forma de haber tenido sesion)', $pos->sesionPerdidaPorRestablecimiento($hash('tok-ana-abierta'), $EQUIPO) === true);
wa_chk('el mismo token en OTRO equipo: no (la consulta va acotada al equipo del request)', $pos->sesionPerdidaPorRestablecimiento($hash('tok-ana-cerrada'), $OTRO_EQUIPO) === false);
wa_chk('un token desconocido: no', $pos->sesionPerdidaPorRestablecimiento($hash('inventado'), $EQUIPO) === false);
wa_chk('un usuario que tiene PIN (la sesion se cerro por otra causa): no', $pos->sesionPerdidaPorRestablecimiento($hash('tok-beto'), $EQUIPO) === false);
wa_chk('un empleado sin usuario (creado a mano): no, aunque su PIN estuviera vacio', $pos->sesionPerdidaPorRestablecimiento($hash('tok-caro'), $EQUIPO) === false);
wa_chk('un usuario pendiente pero desactivado: no (su acceso esta desactivado, no restablecido)', $pos->sesionPerdidaPorRestablecimiento($hash('tok-dani'), $EQUIPO) === false);
wa_chk('el hash vacio no coincide con nada', $pos->sesionPerdidaPorRestablecimiento('', $EQUIPO) === false);

/** Corre requerirSesion con ese token en la cabecera (null = sin cabecera) y devuelve el PosError, o null si no corto. */
$capturar = static function (?string $token) use ($pos, $equipo): ?PosError {
    if ($token === null) {
        unset($_SERVER['HTTP_X_POS_SESION']);
    } else {
        $_SERVER['HTTP_X_POS_SESION'] = $token;
    }
    try {
        PosAuth::requerirSesion($pos, $equipo);
    } catch (PosError $e) {
        return $e;
    }
    return null;
};

echo "PosAuth::requerirSesion\n";
$e = $capturar('tok-ana-cerrada');
wa_chk('restablecido: corta con 401 y el codigo de siempre (el front anterior sigue yendo al PIN)', $e !== null && $e->http() === 401 && $e->codigo() === 'SESION_REQUERIDA', $e?->cuerpo());
wa_chk('restablecido: el cuerpo lleva pin_restablecido = true', $e !== null && ($e->cuerpo()['pin_restablecido'] ?? null) === true, $e?->cuerpo());
wa_chk('restablecido: el texto dice que restablecieron el PIN y manda a crear otro con el usuario', $e !== null && str_contains($e->getMessage(), 'restableció tu PIN') && str_contains($e->getMessage(), 'usuario'), $e?->getMessage());
wa_chk('restablecido: el cuerpo sigue siendo {status:false, error, codigo}', $e !== null && ($e->cuerpo()['status'] ?? null) === false && isset($e->cuerpo()['error'], $e->cuerpo()['codigo']), $e?->cuerpo());

$e = $capturar('tok-beto');
wa_chk('quien tiene PIN: el mensaje de siempre y sin pin_restablecido', $e !== null && $e->codigo() === 'SESION_REQUERIDA' && $e->getMessage() === 'Tu sesión se cerró. Entra de nuevo con tu PIN.' && !array_key_exists('pin_restablecido', $e->cuerpo()), $e?->cuerpo());
$e = $capturar('inventado');
wa_chk('token desconocido: el mensaje de siempre y sin pin_restablecido (no revela nada)', $e !== null && $e->getMessage() === 'Tu sesión se cerró. Entra de nuevo con tu PIN.' && !array_key_exists('pin_restablecido', $e->cuerpo()), $e?->cuerpo());
$e = $capturar(null);
wa_chk('sin cabecera: "Entra con tu PIN." y sin pin_restablecido', $e !== null && $e->getMessage() === 'Entra con tu PIN.' && $e->codigo() === 'SESION_REQUERIDA' && !array_key_exists('pin_restablecido', $e->cuerpo()), $e?->cuerpo());
$e = $capturar('   ');
wa_chk('cabecera solo con espacios: igual que sin cabecera', $e !== null && $e->getMessage() === 'Entra con tu PIN.', $e?->cuerpo());

echo "PosAuth::esPinRestablecido (lo que GET /api/pos/estado repite)\n";
wa_chk('el error de restablecimiento: si', PosAuth::esPinRestablecido($capturar('tok-ana-cerrada') ?? new PosError('x', 500, 'X')) === true);
wa_chk('el de quien tiene PIN: no', PosAuth::esPinRestablecido($capturar('tok-beto') ?? new PosError('x', 500, 'X')) === false);
wa_chk('el de un token desconocido: no', PosAuth::esPinRestablecido($capturar('inventado') ?? new PosError('x', 500, 'X')) === false);
wa_chk('el de sin cabecera: no', PosAuth::esPinRestablecido($capturar(null) ?? new PosError('x', 500, 'X')) === false);
wa_chk('solo cuenta SESION_REQUERIDA: otro codigo con el mismo campo extra no',
    PosAuth::esPinRestablecido(new PosError('otro', 401, 'PIN_INCORRECTO', ['pin_restablecido' => true])) === false);
wa_chk('el campo tiene que ser exactamente true (un "1" o un "true" como texto no vale)',
    PosAuth::esPinRestablecido(new PosError('x', 401, 'SESION_REQUERIDA', ['pin_restablecido' => 'true'])) === false
    && PosAuth::esPinRestablecido(new PosError('x', 401, 'SESION_REQUERIDA', ['pin_restablecido' => 1])) === false);

echo "Despues de crear el PIN nuevo\n";
$pdo->exec("UPDATE pos_empleados SET pin_hmac = 'nuevo-hmac' WHERE id = 1");
wa_chk('con el PIN nuevo creado, el token viejo ya no dice "restablecido": vuelve el mensaje de siempre',
    $pos->sesionPerdidaPorRestablecimiento($hash('tok-ana-cerrada'), $EQUIPO) === false
    && ($capturar('tok-ana-cerrada')?->getMessage() ?? '') === 'Tu sesión se cerró. Entra de nuevo con tu PIN.');
$pdo->exec('UPDATE pos_empleados SET pin_hmac = NULL WHERE id = 1');
wa_chk('si se lo vuelven a restablecer, vuelve a decirlo', $pos->sesionPerdidaPorRestablecimiento($hash('tok-ana-cerrada'), $EQUIPO) === true);

wa_fin();
