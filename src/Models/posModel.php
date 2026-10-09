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
    // Turnos (docs/specs/pos.md K2, K3). El cierre llega aparte (K6-K8).
    // ------------------------------------------------------------------

    /** Turno abierto de una caja, con el nombre de su empleado, o null. */
    public function turnoAbiertoDeCaja(int $cajaId): ?array
    {
        return $this->turnoAbierto('t.caja_id = :x', $cajaId);
    }

    /** Turno abierto de un empleado (en cualquier caja), o null. */
    public function turnoAbiertoDeEmpleado(int $empleadoId): ?array
    {
        return $this->turnoAbierto('t.empleado_id = :x', $empleadoId);
    }

    /**
     * Abre el turno del empleado en la caja con su fondo inicial (K2). Un turno
     * abierto por caja y uno por empleado (K3): lo garantizan los UNIQUE de
     * pos_turnos, asi que dos aperturas a la vez no pasan las dos.
     */
    public function abrirTurno(int $cajaId, int $empleadoId, float $fondo): array
    {
        if (!is_finite($fondo) || $fondo < 0 || $fondo > 10000000) {
            throw new PosError('Escribe el fondo inicial de la caja (0 si empieza vacía).', 422, 'FONDO_INVALIDO');
        }
        if (abs(round($fondo, 2) - $fondo) > 1e-9) {
            throw new PosError('El fondo inicial lleva a lo sumo 2 decimales.', 422, 'FONDO_INVALIDO');
        }
        try {
            $stmt = $this->conexion->prepare(
                'INSERT INTO pos_turnos (caja_id, empleado_id, fondo_inicial, abierto) VALUES (:c, :e, :f, 1)'
            );
            $stmt->execute([':c' => $cajaId, ':e' => $empleadoId, ':f' => round($fondo, 2)]);
        } catch (PDOException $e) {
            if (!self::esDuplicado($e)) {
                throw $e;
            }
            $deLaCaja = $this->turnoAbiertoDeCaja($cajaId);
            if ($deLaCaja !== null) {
                throw new PosError(
                    $deLaCaja['empleado_id'] === $empleadoId
                        ? 'Ya tienes el turno abierto en esta caja.'
                        : "La caja ya tiene un turno abierto de {$deLaCaja['empleado_nombre']}. Un supervisor tiene que cerrarlo primero.",
                    409, 'TURNO_CAJA_OCUPADA', ['turno_caja' => $deLaCaja]
                );
            }
            throw new PosError('Ya tienes un turno abierto en otra caja. Ciérralo antes de abrir uno aquí.', 409, 'TURNO_EN_OTRA_CAJA');
        }
        return $this->turnoAbiertoDeCaja($cajaId);
    }

    /** Turno por id (abierto o cerrado), con el nombre de su empleado, o null. */
    public function turnoPorId(int $turnoId): ?array
    {
        $stmt = $this->conexion->prepare(
            'SELECT t.*, e.nombre AS empleado_nombre, c.nombre AS caja_nombre, s.nombre AS cerrado_por_nombre
             FROM pos_turnos t
             JOIN pos_empleados e ON e.id = t.empleado_id
             JOIN pos_cajas c ON c.id = t.caja_id
             LEFT JOIN pos_empleados s ON s.id = t.cerrado_por
             WHERE t.id = :id'
        );
        $stmt->execute([':id' => $turnoId]);
        $f = $stmt->fetch();
        return $f ? self::normalizarTurnoCompleto($f) : null;
    }

    /**
     * Candado del turno: la venta lo toma mientras emite y el cierre antes de
     * contar. Asi un cierre nunca deja afuera una venta que se estaba emitiendo
     * (ni una venta entra a un turno ya cerrado).
     */
    public function bloquearTurno(int $turnoId, int $segundos): bool
    {
        $stmt = $this->conexion->prepare("SELECT GET_LOCK(CONCAT('post_', SHA1(CONCAT(DATABASE(), ':', :t))), :s)");
        $stmt->execute([':t' => $turnoId, ':s' => $segundos]);
        return (int) $stmt->fetchColumn() === 1;
    }

    public function liberarTurno(int $turnoId): void
    {
        $stmt = $this->conexion->prepare("SELECT RELEASE_LOCK(CONCAT('post_', SHA1(CONCAT(DATABASE(), ':', :t))))");
        $stmt->execute([':t' => $turnoId]);
    }

    /**
     * Movimientos del turno agrupados: [tipo => [forma_pago => [cantidad, monto]]].
     * Montos en pesos (DECIMAL). Tipos: VENTA, DEVOLUCION, CANCELADA, QUITADA.
     */
    public function movimientosDelTurno(int $turnoId): array
    {
        $stmt = $this->conexion->prepare(
            'SELECT tipo, forma_pago, COUNT(*) AS cantidad, COALESCE(SUM(monto), 0) AS monto
             FROM pos_caja_movimientos WHERE turno_id = :t GROUP BY tipo, forma_pago'
        );
        $stmt->execute([':t' => $turnoId]);
        $out = [];
        foreach ($stmt->fetchAll() as $f) {
            $out[$f['tipo']][(int) $f['forma_pago']] = ['cantidad' => (int) $f['cantidad'], 'monto' => (string) $f['monto']];
        }
        return $out;
    }

    /**
     * Ventas cobradas del turno (las que tienen movimiento de caja), con su
     * estado DGII: para contar comprobantes y listar pendientes y rechazadas.
     */
    public function ventasCobradasDelTurno(int $turnoId): array
    {
        $stmt = $this->conexion->prepare(
            "SELECT f.id, f.e_ncf, f.tipo_ecf, f.estado_dgii, f.envio_pendiente, f.total, f.date, m.forma_pago
             FROM pos_caja_movimientos m JOIN facturas f ON f.id = m.factura_id
             WHERE m.turno_id = :t AND m.tipo = 'VENTA'
             ORDER BY f.id DESC"
        );
        $stmt->execute([':t' => $turnoId]);
        return $stmt->fetchAll();
    }

    /**
     * Cierra el turno si sigue abierto (una sola vez: `abierto = 1` en el WHERE).
     * Montos en pesos. Devuelve false si otro lo cerro antes.
     */
    public function cerrarTurno(int $turnoId, int $cerradoPor, array $conteo, float $contado, float $esperado, float $diferencia, array $totales): bool
    {
        $stmt = $this->conexion->prepare(
            'UPDATE pos_turnos
             SET abierto = NULL, cerrado_at = NOW(), cerrado_por = :por, conteo_json = :conteo,
                 efectivo_contado = :contado, efectivo_esperado = :esperado, diferencia = :dif, totales_json = :tot
             WHERE id = :id AND abierto = 1'
        );
        $stmt->execute([
            ':por' => $cerradoPor,
            ':conteo' => json_encode($conteo, JSON_UNESCAPED_UNICODE),
            ':contado' => $contado,
            ':esperado' => $esperado,
            ':dif' => $diferencia,
            ':tot' => json_encode($totales, JSON_UNESCAPED_UNICODE),
            ':id' => $turnoId,
        ]);
        return $stmt->rowCount() === 1;
    }

    /** Nota del cierre: una sola vez y poco despues de cerrar (K6). */
    public function guardarNotaTurno(int $turnoId, int $cajaId, string $nota): bool
    {
        $stmt = $this->conexion->prepare(
            'UPDATE pos_turnos SET nota = :n
             WHERE id = :id AND caja_id = :c AND abierto IS NULL AND nota IS NULL
               AND cerrado_at >= NOW() - INTERVAL 30 MINUTE'
        );
        $stmt->execute([':n' => $nota, ':id' => $turnoId, ':c' => $cajaId]);
        return $stmt->rowCount() === 1;
    }

    /**
     * Venta cancelada o linea quitada del carrito (V4): no mueven dinero, pero
     * salen en el cierre. Van en pos_caja_movimientos con forma_pago 0 y sin
     * factura; el efectivo esperado solo suma VENTA y DEVOLUCION.
     */
    public function registrarEvento(int $turnoId, string $tipo, float $monto): void
    {
        $stmt = $this->conexion->prepare(
            'INSERT INTO pos_caja_movimientos (turno_id, factura_id, tipo, forma_pago, monto) VALUES (:t, NULL, :tipo, 0, :m)'
        );
        $stmt->execute([':t' => $turnoId, ':tipo' => $tipo, ':m' => $monto]);
    }

    /**
     * Turnos para app.* (Punto de venta -> Turnos), los mas recientes primero.
     * Filtros opcionales: caja, empleado, desde/hasta (fecha de apertura).
     */
    public function listarTurnos(array $filtros, int $limite): array
    {
        $where = [];
        $params = [];
        if (!empty($filtros['caja_id'])) {
            $where[] = 't.caja_id = :c';
            $params[':c'] = (int) $filtros['caja_id'];
        }
        if (!empty($filtros['empleado_id'])) {
            $where[] = 't.empleado_id = :e';
            $params[':e'] = (int) $filtros['empleado_id'];
        }
        if (!empty($filtros['desde'])) {
            $where[] = 't.abierto_at >= :d';
            $params[':d'] = $filtros['desde'] . ' 00:00:00';
        }
        if (!empty($filtros['hasta'])) {
            $where[] = 't.abierto_at < :h + INTERVAL 1 DAY';
            $params[':h'] = $filtros['hasta'];
        }
        $stmt = $this->conexion->prepare(
            'SELECT t.*, e.nombre AS empleado_nombre, c.nombre AS caja_nombre, s.nombre AS cerrado_por_nombre
             FROM pos_turnos t
             JOIN pos_empleados e ON e.id = t.empleado_id
             JOIN pos_cajas c ON c.id = t.caja_id
             LEFT JOIN pos_empleados s ON s.id = t.cerrado_por'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY t.abierto_at DESC, t.id DESC LIMIT ' . max(1, min(200, $limite))
        );
        $stmt->execute($params);
        return array_map([self::class, 'normalizarTurnoCompleto'], $stmt->fetchAll());
    }

    private static function normalizarTurnoCompleto(array $f): array
    {
        $num = static fn($v) => $v === null ? null : (float) $v;
        return [
            'id' => (int) $f['id'],
            'caja_id' => (int) $f['caja_id'],
            'caja_nombre' => $f['caja_nombre'] ?? null,
            'empleado_id' => (int) $f['empleado_id'],
            'empleado_nombre' => $f['empleado_nombre'] ?? null,
            'abierto' => (int) ($f['abierto'] ?? 0) === 1,
            'abierto_at' => $f['abierto_at'],
            'cerrado_at' => $f['cerrado_at'],
            'cerrado_por' => $f['cerrado_por'] !== null ? (int) $f['cerrado_por'] : null,
            'cerrado_por_nombre' => $f['cerrado_por_nombre'] ?? null,
            'fondo_inicial' => (float) $f['fondo_inicial'],
            'efectivo_esperado' => $num($f['efectivo_esperado']),
            'efectivo_contado' => $num($f['efectivo_contado']),
            'diferencia' => $num($f['diferencia']),
            'nota' => $f['nota'],
            'reporte' => $f['totales_json'] !== null ? json_decode((string) $f['totales_json'], true) : null,
        ];
    }

    private function turnoAbierto(string $condicion, int $valor): ?array
    {
        $stmt = $this->conexion->prepare(
            'SELECT t.id, t.caja_id, t.empleado_id, e.nombre AS empleado_nombre, t.abierto_at, t.fondo_inicial,
                    DATE(t.abierto_at) < CURDATE() AS de_dia_anterior
             FROM pos_turnos t JOIN pos_empleados e ON e.id = t.empleado_id
             WHERE ' . $condicion . ' AND t.abierto = 1 LIMIT 1'
        );
        $stmt->execute([':x' => $valor]);
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
    // Ventas (docs/specs/pos.md §9.5)
    // ------------------------------------------------------------------

    /**
     * Candado por clave de venta (idempotencia, F5): dos peticiones con la misma
     * clave (doble toque, reintento con la primera aun en curso) no emiten dos
     * veces. GET_LOCK es del servidor MySQL entero, por eso el nombre lleva la
     * base del tenant. Se suelta solo si se cae la conexion.
     */
    public function bloquearClave(string $clave, int $segundos): bool
    {
        $stmt = $this->conexion->prepare("SELECT GET_LOCK(CONCAT('posv_', SHA1(CONCAT(DATABASE(), ':', :k))), :s)");
        $stmt->execute([':k' => $clave, ':s' => $segundos]);
        return (int) $stmt->fetchColumn() === 1;
    }

    public function liberarClave(string $clave): void
    {
        $stmt = $this->conexion->prepare("SELECT RELEASE_LOCK(CONCAT('posv_', SHA1(CONCAT(DATABASE(), ':', :k))))");
        $stmt->execute([':k' => $clave]);
    }

    /** Venta ya registrada con esa clave (la emitida o la rechazada), o null. */
    public function ventaPorClave(string $clave): ?array
    {
        $stmt = $this->conexion->prepare(
            'SELECT id, e_ncf, tipo_ecf, estado_dgii, total, envio_pendiente, turno_id, client_id, respuesta_dgii, rfce_respuesta
             FROM facturas WHERE pos_idempotency_key = :k LIMIT 1'
        );
        $stmt->execute([':k' => $clave]);
        $f = $stmt->fetch();
        return $f ?: null;
    }

    /** Venta del POS por id (tiene empleado del POS), o null. */
    public function ventaPos(int $facturaId): ?array
    {
        $stmt = $this->conexion->prepare(
            'SELECT id, e_ncf, tipo_ecf, estado_dgii, total, envio_pendiente, turno_id
             FROM facturas WHERE id = :id AND pos_empleado_id IS NOT NULL'
        );
        $stmt->execute([':id' => $facturaId]);
        $f = $stmt->fetch();
        return $f ?: null;
    }

    /** Productos de una venta, por id: [id => fila]. Incluye inactivos (se valida aparte). */
    public function productosPorId(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->conexion->prepare(
            "SELECT id, sku, nombre, indicador_facturacion, indicador_bien_servicio, precio, unidad_medida, activo
             FROM products WHERE id IN ({$marcas})"
        );
        $stmt->execute($ids);
        $out = [];
        foreach ($stmt->fetchAll() as $f) {
            $out[(int) $f['id']] = $f;
        }
        return $out;
    }

    /** Movimiento de caja de una venta o devolucion (K7). Montos en pesos. */
    public function registrarMovimiento(
        int $turnoId, int $facturaId, string $tipo, int $formaPago, float $monto,
        ?float $recibido, ?float $devuelta, ?string $iniciadaAt
    ): void {
        $stmt = $this->conexion->prepare(
            'INSERT INTO pos_caja_movimientos (turno_id, factura_id, tipo, forma_pago, monto, monto_recibido, devuelta, iniciada_at)
             VALUES (:t, :f, :tipo, :fp, :m, :r, :d, :i)'
        );
        $stmt->execute([
            ':t' => $turnoId, ':f' => $facturaId, ':tipo' => $tipo, ':fp' => $formaPago, ':m' => $monto,
            ':r' => $recibido, ':d' => $devuelta, ':i' => $iniciadaAt,
        ]);
    }

    public function movimientoDeFactura(int $facturaId): ?array
    {
        $stmt = $this->conexion->prepare(
            'SELECT forma_pago, monto, monto_recibido, devuelta FROM pos_caja_movimientos WHERE factura_id = :f ORDER BY id LIMIT 1'
        );
        $stmt->execute([':f' => $facturaId]);
        $f = $stmt->fetch();
        return $f ?: null;
    }

    /** Ventas con envio pendiente a la DGII (F7), las mas viejas primero. */
    public function ventasPendientes(int $limite): array
    {
        $stmt = $this->conexion->prepare(
            'SELECT id, e_ncf, tipo_ecf, estado_dgii, codigo_seguridad, ambiente_dgii, rfce_xml, track_id, xml_firmado
             FROM facturas WHERE envio_pendiente = 1 ORDER BY id LIMIT ' . max(1, min(20, $limite))
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function contarPendientes(): int
    {
        return (int) $this->conexion->query('SELECT COUNT(*) FROM facturas WHERE envio_pendiente = 1')->fetchColumn();
    }

    /** Resultado de un reenvio o consulta: estado y, si ya es definitivo, fuera de pendientes. */
    public function marcarEnvio(int $facturaId, string $estado, $respuesta, bool $sigue): void
    {
        $stmt = $this->conexion->prepare(
            'UPDATE facturas SET estado_dgii = :e, rfce_estado = :re, rfce_respuesta = :r, envio_pendiente = :p WHERE id = :id'
        );
        $stmt->execute([
            ':e' => $estado,
            ':re' => preg_replace('/^RFCE_/', '', $estado),
            ':r' => $respuesta !== null ? json_encode($respuesta, JSON_UNESCAPED_UNICODE) : null,
            ':p' => $sigue ? 1 : 0,
            ':id' => $facturaId,
        ]);
    }

    /** Resultado de un e-CF completo (E31): estado, respuesta y trackId; fuera de pendientes si ya es definitivo. */
    public function marcarEnvioECF(int $facturaId, string $estado, $respuesta, ?string $trackId, bool $sigue): void
    {
        $stmt = $this->conexion->prepare(
            'UPDATE facturas SET estado_dgii = :e, respuesta_dgii = :r, track_id = COALESCE(:t, track_id), envio_pendiente = :p
             WHERE id = :id'
        );
        $stmt->execute([
            ':e' => $estado,
            ':r' => $respuesta !== null ? json_encode($respuesta, JSON_UNESCAPED_UNICODE) : null,
            ':t' => $trackId,
            ':p' => $sigue ? 1 : 0,
            ':id' => $facturaId,
        ]);
    }

    // ------------------------------------------------------------------
    // Clientes del POS (credito fiscal, F2)
    // ------------------------------------------------------------------

    /**
     * Cliente por RNC o cedula (solo digitos). Varios clientes pueden compartir
     * RNC (contactos de una misma empresa): se usa el primero que se creo.
     */
    public function clientePorRnc(string $rnc): ?array
    {
        $stmt = $this->conexion->prepare('SELECT * FROM clients WHERE rnc = :r ORDER BY id LIMIT 1');
        $stmt->execute([':r' => $rnc]);
        $f = $stmt->fetch();
        return $f ?: null;
    }

    public function clientePorId(int $id): ?array
    {
        $stmt = $this->conexion->prepare('SELECT * FROM clients WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $f = $stmt->fetch();
        return $f ?: null;
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
