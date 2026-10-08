<?php
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Pos/PosError.php';

/**
 * POS en la DB del tenant (migracion 030): cajas, empleados y sesiones.
 * Turnos y movimientos de caja se agregan con la venta (docs/specs/pos.md §6.6).
 *
 * Instanciar DESPUES de resolver el tenant: la conexion se fija en el
 * constructor (igual que el resto de los models).
 *
 * Errores de negocio: PosError con su codigo. Los de base de datos suben como
 * PDOException y el controller responde un 500 generico (el detalle va al log).
 */
class posModel
{
    public const ROLES = ['cajero', 'supervisor'];

    /** Una sesion de empleado sin uso por mas de esto ya no vale (pide PIN otra vez). */
    public const SESION_MAX_HORAS = 16;

    private PDO $conexion;

    public function __construct()
    {
        $this->conexion = Database::getInstance()->getConnection();
    }

    // ------------------------------------------------------------------
    // Cajas
    // ------------------------------------------------------------------

    public function listarCajas(): array
    {
        $filas = $this->conexion->query(
            'SELECT id, nombre, activa, created_at, updated_at FROM pos_cajas ORDER BY activa DESC, nombre'
        )->fetchAll();
        return array_map([self::class, 'normalizarCaja'], $filas);
    }

    public function cajaPorId(int $id): ?array
    {
        $stmt = $this->conexion->prepare('SELECT id, nombre, activa, created_at, updated_at FROM pos_cajas WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $f = $stmt->fetch();
        return $f ? self::normalizarCaja($f) : null;
    }

    public function crearCaja(string $nombre): array
    {
        $nombre = self::nombreValido($nombre, 60, 'de la caja');
        try {
            $stmt = $this->conexion->prepare('INSERT INTO pos_cajas (nombre) VALUES (:n)');
            $stmt->execute([':n' => $nombre]);
        } catch (PDOException $e) {
            throw self::duplicado($e, "Ya hay una caja llamada «{$nombre}». Usa otro nombre.");
        }
        return $this->cajaPorId((int) $this->conexion->lastInsertId());
    }

    /** @param array{nombre?:string,activa?:bool} $campos */
    public function actualizarCaja(int $id, array $campos): array
    {
        if ($this->cajaPorId($id) === null) {
            throw new PosError('Esa caja no existe.', 404, 'CAJA_NO_EXISTE');
        }
        $sets = [];
        $params = [':id' => $id];
        if (array_key_exists('nombre', $campos)) {
            $sets[] = 'nombre = :n';
            $params[':n'] = self::nombreValido((string) $campos['nombre'], 60, 'de la caja');
        }
        if (array_key_exists('activa', $campos)) {
            $sets[] = 'activa = :a';
            $params[':a'] = $campos['activa'] ? 1 : 0;
        }
        if ($sets) {
            try {
                $this->conexion->prepare('UPDATE pos_cajas SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);
            } catch (PDOException $e) {
                throw self::duplicado($e, 'Ya hay otra caja con ese nombre. Usa otro.');
            }
        }
        return $this->cajaPorId($id);
    }

    // ------------------------------------------------------------------
    // Empleados
    // ------------------------------------------------------------------

    /** Sin pin_hmac: el PIN no se puede volver a leer, ni siquiera su hash. */
    public function listarEmpleados(): array
    {
        $filas = $this->conexion->query(
            'SELECT id, nombre, rol, activo, pin_generado_at, created_at, updated_at
             FROM pos_empleados ORDER BY activo DESC, nombre'
        )->fetchAll();
        return array_map([self::class, 'normalizarEmpleado'], $filas);
    }

    public function empleadoPorId(int $id): ?array
    {
        $stmt = $this->conexion->prepare(
            'SELECT id, nombre, rol, activo, pin_generado_at, created_at, updated_at FROM pos_empleados WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
        $f = $stmt->fetch();
        return $f ? self::normalizarEmpleado($f) : null;
    }

    /** Empleado ACTIVO dueño de ese PIN (por su HMAC), o null. */
    public function empleadoPorPin(string $pinHmac): ?array
    {
        $stmt = $this->conexion->prepare(
            'SELECT id, nombre, rol, activo, pin_generado_at, created_at, updated_at
             FROM pos_empleados WHERE pin_hmac = :h AND activo = 1'
        );
        $stmt->execute([':h' => $pinHmac]);
        $f = $stmt->fetch();
        return $f ? self::normalizarEmpleado($f) : null;
    }

    /**
     * Crea el empleado con un PIN generado. $generar() devuelve [pin, hmac]; se
     * llama de nuevo si el HMAC ya existe (UNIQUE), hasta 10 veces: con 10^4
     * PINs posibles y unas decenas de empleados, chocar diez veces seguidas no pasa.
     *
     * @param callable():array{0:string,1:string} $generar
     * @return array{empleado:array,pin:string}
     */
    public function crearEmpleado(string $nombre, string $rol, callable $generar): array
    {
        $nombre = self::nombreValido($nombre, 80, 'del empleado');
        $rol = self::rolValido($rol);
        for ($i = 0; $i < 10; $i++) {
            [$pin, $hmac] = $generar();
            try {
                $stmt = $this->conexion->prepare('INSERT INTO pos_empleados (nombre, rol, pin_hmac) VALUES (:n, :r, :h)');
                $stmt->execute([':n' => $nombre, ':r' => $rol, ':h' => $hmac]);
                return ['empleado' => $this->empleadoPorId((int) $this->conexion->lastInsertId()), 'pin' => $pin];
            } catch (PDOException $e) {
                if (!self::esDuplicado($e)) {
                    throw $e;
                }
            }
        }
        throw new PosError('No se pudo generar un PIN. Inténtalo de nuevo.', 500, 'PIN_NO_GENERADO');
    }

    /**
     * PIN nuevo para un empleado: el anterior deja de servir y sus sesiones se
     * cierran (quien lo tenga anotado ya no entra).
     *
     * @param callable():array{0:string,1:string} $generar
     */
    public function regenerarPin(int $id, callable $generar): string
    {
        if ($this->empleadoPorId($id) === null) {
            throw new PosError('Ese empleado no existe.', 404, 'EMPLEADO_NO_EXISTE');
        }
        // El nuevo tiene que ser DISTINTO del actual: un UPDATE al mismo valor no
        // choca con el UNIQUE (es la misma fila), y con 4 digitos repetir el PIN
        // que se queria invalidar pasa 1 de cada 10,000 veces.
        $stmt = $this->conexion->prepare('SELECT pin_hmac FROM pos_empleados WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $actual = (string) $stmt->fetchColumn();
        for ($i = 0; $i < 10; $i++) {
            [$pin, $hmac] = $generar();
            if (hash_equals($actual, $hmac)) {
                continue;
            }
            try {
                $stmt = $this->conexion->prepare(
                    'UPDATE pos_empleados SET pin_hmac = :h, pin_generado_at = NOW() WHERE id = :id'
                );
                $stmt->execute([':h' => $hmac, ':id' => $id]);
                $this->cerrarSesionesDeEmpleado($id);
                return $pin;
            } catch (PDOException $e) {
                if (!self::esDuplicado($e)) {
                    throw $e;
                }
            }
        }
        throw new PosError('No se pudo generar un PIN. Inténtalo de nuevo.', 500, 'PIN_NO_GENERADO');
    }

    /**
     * @param array{nombre?:string,rol?:string,activo?:bool} $campos
     * Desactivar cierra sus sesiones: deja de poder vender en el acto.
     */
    public function actualizarEmpleado(int $id, array $campos): array
    {
        if ($this->empleadoPorId($id) === null) {
            throw new PosError('Ese empleado no existe.', 404, 'EMPLEADO_NO_EXISTE');
        }
        $sets = [];
        $params = [':id' => $id];
        if (array_key_exists('nombre', $campos)) {
            $sets[] = 'nombre = :n';
            $params[':n'] = self::nombreValido((string) $campos['nombre'], 80, 'del empleado');
        }
        if (array_key_exists('rol', $campos)) {
            $sets[] = 'rol = :r';
            $params[':r'] = self::rolValido((string) $campos['rol']);
        }
        if (array_key_exists('activo', $campos)) {
            $sets[] = 'activo = :a';
            $params[':a'] = $campos['activo'] ? 1 : 0;
        }
        if ($sets) {
            $this->conexion->prepare('UPDATE pos_empleados SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);
        }
        if (array_key_exists('activo', $campos) && !$campos['activo']) {
            $this->cerrarSesionesDeEmpleado($id);
        }
        return $this->empleadoPorId($id);
    }

    // ------------------------------------------------------------------
    // Sesiones (empleado entrado con PIN en un equipo)
    // ------------------------------------------------------------------

    /**
     * Abre la sesion del empleado en el equipo. Un equipo tiene UNA sesion
     * abierta: entrar con otro PIN cierra la anterior (cambio de cajero).
     * Devuelve el token en claro; se guarda su sha256.
     */
    public function abrirSesion(int $empleadoId, int $equipoId): string
    {
        $this->cerrarSesionesDeEquipo($equipoId);
        $token = bin2hex(random_bytes(32));
        $stmt = $this->conexion->prepare(
            'INSERT INTO pos_sesiones (empleado_id, equipo_id, token_hash, ultimo_uso) VALUES (:e, :q, :h, NOW())'
        );
        $stmt->execute([':e' => $empleadoId, ':q' => $equipoId, ':h' => hash('sha256', $token)]);
        return $token;
    }

    /**
     * Sesion abierta de ESTE equipo por el hash de su token, con su empleado
     * (que tiene que seguir activo). Vencida por inactividad = null. Si vale,
     * se toca su ultimo_uso.
     */
    public function sesionVigente(string $tokenHash, int $equipoId): ?array
    {
        $stmt = $this->conexion->prepare(
            'SELECT s.id AS sesion_id, e.id, e.nombre, e.rol, e.activo, e.pin_generado_at, e.created_at, e.updated_at
             FROM pos_sesiones s
             JOIN pos_empleados e ON e.id = s.empleado_id
             WHERE s.token_hash = :h AND s.equipo_id = :q AND s.cerrada_at IS NULL AND e.activo = 1
               AND COALESCE(s.ultimo_uso, s.created_at) > NOW() - INTERVAL ' . self::SESION_MAX_HORAS . ' HOUR
             LIMIT 1'
        );
        $stmt->execute([':h' => $tokenHash, ':q' => $equipoId]);
        $f = $stmt->fetch();
        if (!$f) {
            return null;
        }
        $this->conexion->prepare('UPDATE pos_sesiones SET ultimo_uso = NOW() WHERE id = :id')->execute([':id' => $f['sesion_id']]);
        return ['sesion_id' => (int) $f['sesion_id'], 'empleado' => self::normalizarEmpleado($f)];
    }

    public function cerrarSesion(int $sesionId): void
    {
        $this->conexion->prepare('UPDATE pos_sesiones SET cerrada_at = NOW() WHERE id = :id AND cerrada_at IS NULL')
            ->execute([':id' => $sesionId]);
    }

    public function cerrarSesionesDeEquipo(int $equipoId): void
    {
        $this->conexion->prepare('UPDATE pos_sesiones SET cerrada_at = NOW() WHERE equipo_id = :q AND cerrada_at IS NULL')
            ->execute([':q' => $equipoId]);
    }

    public function cerrarSesionesDeEmpleado(int $empleadoId): void
    {
        $this->conexion->prepare('UPDATE pos_sesiones SET cerrada_at = NOW() WHERE empleado_id = :e AND cerrada_at IS NULL')
            ->execute([':e' => $empleadoId]);
    }

    // ------------------------------------------------------------------
    // Turnos (solo lectura por ahora: la apertura y el cierre llegan con la venta)
    // ------------------------------------------------------------------

    /** Turno abierto de una caja, con el nombre de su empleado, o null. */
    public function turnoAbiertoDeCaja(int $cajaId): ?array
    {
        $stmt = $this->conexion->prepare(
            'SELECT t.id, t.caja_id, t.empleado_id, e.nombre AS empleado_nombre, t.abierto_at, t.fondo_inicial,
                    DATE(t.abierto_at) < CURDATE() AS de_dia_anterior
             FROM pos_turnos t JOIN pos_empleados e ON e.id = t.empleado_id
             WHERE t.caja_id = :c AND t.abierto = 1 LIMIT 1'
        );
        $stmt->execute([':c' => $cajaId]);
        $f = $stmt->fetch();
        if (!$f) {
            return null;
        }
        return [
            'id' => (int) $f['id'],
            'caja_id' => (int) $f['caja_id'],
            'empleado_id' => (int) $f['empleado_id'],
            'empleado_nombre' => $f['empleado_nombre'],
            'abierto_at' => $f['abierto_at'],
            'fondo_inicial' => (float) $f['fondo_inicial'],
            'de_dia_anterior' => (int) $f['de_dia_anterior'] === 1,
        ];
    }

    // ------------------------------------------------------------------
    // Catalogo de la caja (docs/specs/pos.md C1)
    // ------------------------------------------------------------------

    /**
     * Productos que se venden en el POS: activos y facturables. Filas crudas
     * (precio DECIMAL como texto); el precio final lo calcula PosPrecio.
     */
    public function catalogoProductos(): array
    {
        return $this->conexion->query(
            'SELECT id, sku, nombre, category_id, indicador_facturacion, precio, unidad_medida, stock, stock_minimo
             FROM products
             WHERE activo = 1 AND indicador_facturacion <> 0
             ORDER BY nombre, id'
        )->fetchAll();
    }

    /** Categorias activas: [id => nombre]. */
    public function catalogoCategorias(): array
    {
        $out = [];
        foreach ($this->conexion->query('SELECT id, nombre FROM categories WHERE estado = 1 ORDER BY nombre')->fetchAll() as $f) {
            $out[(int) $f['id']] = $f['nombre'];
        }
        return $out;
    }

    // ------------------------------------------------------------------

    private static function nombreValido(string $nombre, int $max, string $que): string
    {
        $nombre = trim(preg_replace('/\s+/', ' ', $nombre));
        if ($nombre === '') {
            throw new PosError("Escribe el nombre {$que}.", 422, 'NOMBRE_REQUERIDO');
        }
        if (mb_strlen($nombre) > $max) {
            throw new PosError("El nombre no puede pasar de {$max} caracteres.", 422, 'NOMBRE_LARGO');
        }
        return $nombre;
    }

    private static function rolValido(string $rol): string
    {
        $rol = strtolower(trim($rol));
        if (!in_array($rol, self::ROLES, true)) {
            throw new PosError('El rol tiene que ser cajero o supervisor.', 422, 'ROL_INVALIDO');
        }
        return $rol;
    }

    private static function esDuplicado(PDOException $e): bool
    {
        return (string) $e->getCode() === '23000' && (int) ($e->errorInfo[1] ?? 0) === 1062;
    }

    /** PDOException de UNIQUE -> PosError 409 con el texto dado; cualquier otra sube igual. */
    private static function duplicado(PDOException $e, string $mensaje): Throwable
    {
        return self::esDuplicado($e) ? new PosError($mensaje, 409, 'DUPLICADO') : $e;
    }

    private static function normalizarCaja(array $f): array
    {
        return [
            'id' => (int) $f['id'],
            'nombre' => $f['nombre'],
            'activa' => (int) $f['activa'] === 1,
            'created_at' => $f['created_at'],
            'updated_at' => $f['updated_at'],
        ];
    }

    private static function normalizarEmpleado(array $f): array
    {
        return [
            'id' => (int) $f['id'],
            'nombre' => $f['nombre'],
            'rol' => $f['rol'],
            'activo' => (int) $f['activo'] === 1,
            'pin_generado_at' => $f['pin_generado_at'],
            'created_at' => $f['created_at'],
            'updated_at' => $f['updated_at'],
        ];
    }
}
