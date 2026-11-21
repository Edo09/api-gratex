<?php
require_once __DIR__ . '/PosError.php';
require_once __DIR__ . '/PosPin.php';
require_once __DIR__ . '/../Models/posModel.php';
require_once __DIR__ . '/../Models/posMasterModel.php';
require_once __DIR__ . '/../Models/RoleModel.php';
require_once __DIR__ . '/../PermissionGate.php';

/**
 * Usuarios de FiscalPoint como empleados del POS (docs/specs/pos.md A5b y las
 * decisiones 39 a 41).
 *
 * Quien ya tiene usuario en FiscalPoint no necesita que un administrador lo dé de
 * alta en el POS: su empleado se crea solo (aquí) y el propio usuario elige su PIN
 * la primera vez que entra al POS. Es el mismo empleado de siempre (pos_empleados,
 * migración 040: user_id) con el PIN pendiente hasta que lo crea:
 *
 *  - Quién entra: todo usuario de ESA empresa (users.tenant_id). Un usuario de
 *    soporte con acceso global que está dentro de una empresa ajena no es empleado
 *    de ella (no se le crea nada: el POS de un cliente no se llena de cuentas de
 *    soporte). Si un administrador desactiva a un empleado, no entra ni crea PIN.
 *    Para limitarlo a quien tiene el módulo 'pos', es puedeUsarPos().
 *  - Rol: supervisor si el rol del usuario administra el POS (módulo 'pos' o '*');
 *    cajero en los demás. Solo al crearlo: el administrador lo cambia después y la
 *    sincronización no lo pisa.
 *  - PIN: lo elige la persona (4 dígitos, nada obvio, único en la empresa). Elegir
 *    uno que ya usa otro empleado le dice que ese PIN existe; para que no sirva de
 *    detector de PIN, el límite de choques (MAX_CHOQUES en VENTANA_CHOQUES_MIN
 *    minutos) y que solo se pueda crear UNA vez: ya con PIN, el administrador lo
 *    restablece (posModel::restablecerPin) y se vuelve a crear.
 */
final class PosUsuarios
{
    /** PIN elegidos que ya usa otro empleado, y minutos de la ventana, antes de pedir que espere. */
    public const MAX_CHOQUES = 5;
    public const VENTANA_CHOQUES_MIN = 15;

    /** Nombre del empleado: el de su cuenta (nombre y apellido), o su usuario si no tiene. Cabe en pos_empleados.nombre. */
    public static function nombreDe(array $usuario): string
    {
        $nombre = trim((string) preg_replace('/\s+/', ' ', trim(((string) ($usuario['name'] ?? '')) . ' ' . ((string) ($usuario['last_name'] ?? '')))));
        if ($nombre === '') {
            $nombre = trim((string) ($usuario['username'] ?? ''));
        }
        if ($nombre === '') {
            $nombre = 'Usuario ' . (int) ($usuario['id'] ?? 0);
        }
        return mb_substr($nombre, 0, 80);
    }

    /** Supervisor si el rol administra el POS ('pos' o '*'); cajero en los demás casos. */
    public static function rolPara(array $permisos): string
    {
        return PermissionGate::permMatches($permisos, 'pos') ? 'supervisor' : 'cajero';
    }

    /**
     * ¿Puede este usuario usar el POS? Hoy todo usuario de la empresa (la fila viene
     * de posMasterModel::usuarioDeEmpresa: null = es de otra empresa o ya no existe).
     * El único sitio a cambiar si se quisiera exigir además un módulo.
     */
    public static function puedeUsarPos(?array $usuario): bool
    {
        return $usuario !== null;
    }

    /**
     * El empleado del usuario; si todavía no tiene, se crea (PIN pendiente).
     *
     * @param array $usuario fila de master.users de ESTA empresa
     * @return array{empleado:array,creado:bool}
     */
    public static function asegurarEmpleado(posModel $pos, int $tenantId, array $usuario): array
    {
        $existente = $pos->empleadoDeUsuario((int) $usuario['id']);
        if ($existente !== null) {
            return ['empleado' => $existente, 'creado' => false];
        }
        $permisos = (new RoleModel())->getPermissionsForRole($tenantId, (string) ($usuario['role'] ?? ''));
        $empleado = $pos->crearEmpleadoDeUsuario((int) $usuario['id'], self::nombreDe($usuario), self::rolPara($permisos));
        return ['empleado' => $empleado, 'creado' => true];
    }

    /**
     * Pone al día los empleados con los usuarios de la empresa (lo llama la pantalla
     * Punto de venta → Empleados, para que el administrador los vea a todos):
     *  - crea el empleado de cada usuario que no lo tiene (PIN pendiente);
     *  - sigue el nombre de la cuenta si cambió;
     *  - desactiva al empleado cuyo usuario ya no existe (cierra sus sesiones).
     * Nunca reactiva ni cambia el rol de nadie: lo que el administrador decidió se queda.
     *
     * @return array{creados:int,renombrados:int,desactivados:int}
     */
    public static function sincronizar(posModel $pos, posMasterModel $master, int $tenantId): array
    {
        $usuarios = $master->usuariosDeEmpresa($tenantId);
        $vinculados = $pos->empleadosVinculados();
        $rolPorNombreDeRol = []; // un solo viaje a los permisos por cada rol distinto
        $vigentes = [];
        $creados = 0;
        $renombrados = 0;
        foreach ($usuarios as $u) {
            $uid = (int) $u['id'];
            $vigentes[$uid] = true;
            $nombre = self::nombreDe($u);
            if (isset($vinculados[$uid])) {
                if ($vinculados[$uid]['nombre'] !== $nombre) {
                    $pos->renombrarEmpleado($vinculados[$uid]['id'], $nombre);
                    $renombrados++;
                }
                continue;
            }
            $nombreRol = (string) ($u['role'] ?? '');
            if (!isset($rolPorNombreDeRol[$nombreRol])) {
                $rolPorNombreDeRol[$nombreRol] = self::rolPara((new RoleModel())->getPermissionsForRole($tenantId, $nombreRol));
            }
            $pos->crearEmpleadoDeUsuario($uid, $nombre, $rolPorNombreDeRol[$nombreRol]);
            $creados++;
        }
        $desactivados = 0;
        foreach ($vinculados as $uid => $e) {
            if (!isset($vigentes[$uid]) && $e['activo']) {
                $pos->actualizarEmpleado($e['id'], ['activo' => false]); // cierra sus sesiones
                $desactivados++;
            }
        }
        return ['creados' => $creados, 'renombrados' => $renombrados, 'desactivados' => $desactivados];
    }

    /**
     * Qué le toca hacer al usuario que llegó al POS: crear su PIN, entrar con el que
     * ya tiene, o nada (es de otra empresa, o un administrador lo desactivó). Crea
     * su empleado si todavía no existe.
     *
     * @return array{elegible:bool,motivo:?string,mensaje:?string,empleado:?array,tiene_pin:bool,creado:bool}
     */
    public static function estadoDe(posModel $pos, int $tenantId, ?array $usuario): array
    {
        if (!self::puedeUsarPos($usuario)) {
            return [
                'elegible' => false, 'motivo' => 'OTRA_EMPRESA', 'creado' => false, 'tiene_pin' => false, 'empleado' => null,
                'mensaje' => 'Tu usuario es de otra empresa (soporte): aquí puedes habilitar la caja, pero no entrar a vender.',
            ];
        }
        ['empleado' => $empleado, 'creado' => $creado] = self::asegurarEmpleado($pos, $tenantId, $usuario);
        if (!$empleado['activo']) {
            return [
                'elegible' => false, 'motivo' => 'DESACTIVADO', 'creado' => $creado, 'tiene_pin' => false, 'empleado' => null,
                'mensaje' => 'Un administrador desactivó tu acceso al POS. Pídele que lo active.',
            ];
        }
        return [
            'elegible' => true, 'motivo' => null, 'mensaje' => null, 'creado' => $creado,
            'tiene_pin' => !$empleado['pin_pendiente'],
            'empleado' => ['id' => $empleado['id'], 'nombre' => $empleado['nombre'], 'rol' => $empleado['rol']],
        ];
    }

    /**
     * El usuario crea SU PIN (solo si todavía no tiene).
     *
     * Orden: lo que no depende de los demás empleados (formato, PIN obvio) se
     * rechaza primero, sin tocar el contador; después el límite de choques; y solo
     * entonces se intenta guardar, que es lo que dice si otro empleado lo usa.
     *
     * @param mixed $pin lo que mandó el cliente
     * @return array{empleado_id:int,creado:bool}
     */
    public static function crearPin(posModel $pos, int $tenantId, ?array $usuario, $pin): array
    {
        if (!self::puedeUsarPos($usuario)) {
            throw new PosError('Tu usuario es de otra empresa: no tiene PIN en esta.', 403, 'USUARIO_OTRA_EMPRESA');
        }
        if (is_int($pin)) {
            // Un PIN que empieza con 0 no puede llegar como numero JSON sin perderlo.
            throw new PosError('Manda el PIN como texto.', 422, 'PIN_FORMATO');
        }
        if (!PosPin::formatoValido($pin)) {
            throw new PosError('El PIN tiene ' . PosPin::DIGITOS . ' dígitos.', 422, 'PIN_FORMATO');
        }
        if (PosPin::esObvio($pin)) {
            throw new PosError('Ese PIN es muy fácil de adivinar (0000, 1234, 4321…). Elige otro.', 422, 'PIN_OBVIO');
        }

        ['empleado' => $empleado] = self::asegurarEmpleado($pos, $tenantId, $usuario);
        $id = (int) $empleado['id'];
        if (!$empleado['activo']) {
            throw new PosError('Un administrador desactivó tu acceso al POS. Pídele que lo active.', 403, 'ACCESO_DESACTIVADO');
        }
        if (!$empleado['pin_pendiente']) {
            throw new PosError('Ya tienes un PIN. Si lo olvidaste, un administrador lo restablece y creas otro.', 409, 'PIN_YA_CREADO');
        }

        $estado = $pos->estadoChoquesPin($id, self::MAX_CHOQUES, self::VENTANA_CHOQUES_MIN);
        if ($estado['espera_segundos'] > 0) {
            throw new PosError('Probaste muchos PIN que no están disponibles. Espera unos minutos e inténtalo de nuevo.', 429,
                'PIN_DEMASIADOS_INTENTOS', ['espera_segundos' => $estado['espera_segundos']]);
        }

        $resultado = $pos->fijarPinDeUsuario($id, PosPin::hmac($pin, $tenantId));
        if ($resultado === 'ocupado') {
            $pos->registrarChoquePin($id, self::VENTANA_CHOQUES_MIN);
            AuditLogger::log([
                'module' => 'pos', 'action' => 'POS_PIN_NO_DISPONIBLE', 'entity_type' => 'pos_empleado', 'entity_id' => $id,
                'description' => 'Eligió un PIN que ya usa otro empleado (el PIN no se registra).', 'success' => false,
            ]);
            throw new PosError('Ese PIN no está disponible. Elige otro.', 409, 'PIN_NO_DISPONIBLE');
        }
        if ($resultado !== 'ok') {
            // Otra petición se le adelantó (ya tiene PIN) o lo desactivaron entre medias.
            throw new PosError('Ya tienes un PIN. Si lo olvidaste, un administrador lo restablece y creas otro.', 409, 'PIN_YA_CREADO');
        }
        AuditLogger::log([
            'module' => 'pos', 'action' => 'POS_PIN_CREADO', 'entity_type' => 'pos_empleado', 'entity_id' => $id,
            'new_values' => ['usuario_id' => (int) $usuario['id']],
            'description' => 'El usuario creó su PIN del POS.',
        ]);
        return ['empleado_id' => $id, 'creado' => true];
    }
}
