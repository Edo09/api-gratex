<?php
require_once __DIR__ . '/../MasterDatabase.php';

/**
 * POS en el master (master_migrations/012): codigos de traspaso app.* -> pos.*
 * y equipos de caja habilitados. Va en el master porque los dos se resuelven
 * ANTES de saber a que DB de tenant ir.
 *
 * Fechas: todas con NOW() de la base, nunca con date() de PHP. El vencimiento
 * del codigo (60 s) y el bloqueo por PIN (5 min) se comparan contra el mismo
 * reloj con el que se escribieron, sin depender de la zona horaria del PHP.
 *
 * Tokens y codigos: se guarda solo su sha256; el valor en claro sale una vez,
 * en la respuesta que lo crea.
 */
class posMasterModel
{
    /** Vida del codigo de traspaso, en segundos (docs/specs/pos.md A2). */
    public const CODIGO_SEGUNDOS = 60;

    /**
     * PIN fallidos seguidos en un equipo antes de bloquearlo, y por cuanto. El
     * bloqueo es PROGRESIVO: cada bloqueo seguido dura el doble (5, 10, 20, 40...
     * minutos) hasta un dia, y la cuenta vuelve a cero cuando alguien entra con
     * un PIN valido. Con PIN de 4 digitos y "solo PIN" es lo que frena a quien
     * prueba al azar: ~40 intentos por dia y equipo en vez de ~1,440.
     */
    public const MAX_INTENTOS = 5;
    public const BLOQUEO_MINUTOS = 5;
    public const BLOQUEO_MAX_MINUTOS = 1440;

    private PDO $conexion;

    public function __construct()
    {
        $this->conexion = MasterDatabase::getInstance()->getConnection();
    }

    // ------------------------------------------------------------------
    // Codigo de traspaso (boton POS de app.* -> pos.* ya autenticado)
    // ------------------------------------------------------------------

    /** Codigo nuevo de un solo uso. Devuelve el codigo en claro (no se guarda). */
    public function crearCodigo(int $userId, int $tenantId): string
    {
        // Limpieza oportunista: los vencidos hace mas de un dia no sirven ni
        // para la bitacora (que ya tiene su propio registro).
        $this->conexion->exec('DELETE FROM pos_handoff_codes WHERE expira_at < NOW() - INTERVAL 1 DAY');

        $codigo = bin2hex(random_bytes(24));
        $stmt = $this->conexion->prepare(
            'INSERT INTO pos_handoff_codes (code_hash, user_id, tenant_id, expira_at)
             VALUES (:h, :u, :t, NOW() + INTERVAL ' . self::CODIGO_SEGUNDOS . ' SECOND)'
        );
        $stmt->execute([':h' => hash('sha256', $codigo), ':u' => $userId, ':t' => $tenantId]);
        return $codigo;
    }

    /**
     * Canjea un codigo: lo marca usado en el MISMO UPDATE que comprueba que no
     * este usado ni vencido, asi dos canjes simultaneos no pueden ganar los dos.
     *
     * @return array{user_id:int,tenant_id:int}|null null = invalido, usado o vencido.
     */
    public function canjearCodigo(string $codigo): ?array
    {
        $hash = hash('sha256', $codigo);
        $stmt = $this->conexion->prepare(
            'UPDATE pos_handoff_codes SET usado_at = NOW()
             WHERE code_hash = :h AND usado_at IS NULL AND expira_at > NOW()'
        );
        $stmt->execute([':h' => $hash]);
        if ($stmt->rowCount() !== 1) {
            return null;
        }
        $stmt = $this->conexion->prepare('SELECT user_id, tenant_id FROM pos_handoff_codes WHERE code_hash = :h');
        $stmt->execute([':h' => $hash]);
        $fila = $stmt->fetch();
        return $fila ? ['user_id' => (int) $fila['user_id'], 'tenant_id' => (int) $fila['tenant_id']] : null;
    }

    // ------------------------------------------------------------------
    // Equipos
    // ------------------------------------------------------------------

    /**
     * Equipo vigente (no revocado) por el hash de su token, con su estado de
     * bloqueo calculado contra el reloj de la base.
     */
    public function equipoPorToken(string $tokenHash): ?array
    {
        $stmt = $this->conexion->prepare(
            'SELECT id, tenant_id, caja_id, nombre, created_at, last_used, habilitado_por,
                    (bloqueado_hasta IS NOT NULL AND bloqueado_hasta > NOW()) AS bloqueado,
                    GREATEST(TIMESTAMPDIFF(SECOND, NOW(), bloqueado_hasta), 0) AS bloqueo_segundos
             FROM pos_equipos
             WHERE token_hash = :h AND revocado_at IS NULL
             LIMIT 1'
        );
        $stmt->execute([':h' => $tokenHash]);
        $fila = $stmt->fetch();
        return $fila ? self::normalizarEquipo($fila) : null;
    }

    public function tocarEquipo(int $equipoId): void
    {
        $stmt = $this->conexion->prepare('UPDATE pos_equipos SET last_used = NOW() WHERE id = :id');
        $stmt->execute([':id' => $equipoId]);
    }

    /** Equipos vigentes del tenant (los revocados no se listan). */
    public function listarEquipos(int $tenantId): array
    {
        $stmt = $this->conexion->prepare(
            'SELECT e.id, e.caja_id, e.nombre, e.created_at, e.last_used,
                    (e.bloqueado_hasta IS NOT NULL AND e.bloqueado_hasta > NOW()) AS bloqueado,
                    GREATEST(TIMESTAMPDIFF(SECOND, NOW(), e.bloqueado_hasta), 0) AS bloqueo_segundos,
                    e.habilitado_por, TRIM(CONCAT(COALESCE(u.name, \'\'), \' \', COALESCE(u.last_name, \'\'))) AS habilitado_por_nombre
             FROM pos_equipos e
             LEFT JOIN users u ON u.id = e.habilitado_por
             WHERE e.tenant_id = :t AND e.revocado_at IS NULL
             ORDER BY e.caja_id, e.id'
        );
        $stmt->execute([':t' => $tenantId]);
        return array_map([self::class, 'normalizarEquipo'], $stmt->fetchAll());
    }

    /** Equipo vigente que ocupa una caja, o null. */
    public function equipoDeCaja(int $tenantId, int $cajaId): ?array
    {
        $stmt = $this->conexion->prepare(
            'SELECT id, tenant_id, caja_id, nombre, created_at, last_used, 0 AS bloqueado, 0 AS bloqueo_segundos
             FROM pos_equipos WHERE tenant_id = :t AND caja_id = :c AND revocado_at IS NULL
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':t' => $tenantId, ':c' => $cajaId]);
        $fila = $stmt->fetch();
        return $fila ? self::normalizarEquipo($fila) : null;
    }

    /**
     * Habilita un equipo para una caja. Una caja tiene UN equipo vigente
     * (docs/specs/pos.md A4): si ya hay uno y no se pidio reemplazarlo, no se
     * toca nada y se devuelve 'ocupada' con ese equipo.
     *
     * @return array ['ok', ['equipo_id','token','revocados'=>int[]]] | ['ocupada', equipo]
     */
    public function habilitarEquipo(int $tenantId, int $cajaId, string $nombre, int $userId, bool $reemplazar): array
    {
        $this->conexion->beginTransaction();
        try {
            $stmt = $this->conexion->prepare(
                'SELECT id, tenant_id, caja_id, nombre, created_at, last_used, 0 AS bloqueado, 0 AS bloqueo_segundos
                 FROM pos_equipos WHERE tenant_id = :t AND caja_id = :c AND revocado_at IS NULL FOR UPDATE'
            );
            $stmt->execute([':t' => $tenantId, ':c' => $cajaId]);
            $vigentes = $stmt->fetchAll();
            if ($vigentes && !$reemplazar) {
                $this->conexion->rollBack();
                return ['ocupada', self::normalizarEquipo($vigentes[0])];
            }
            $revocados = array_map(fn($e) => (int) $e['id'], $vigentes);
            if ($revocados) {
                $marcas = implode(',', array_fill(0, count($revocados), '?'));
                $this->conexion->prepare("UPDATE pos_equipos SET revocado_at = NOW() WHERE id IN ({$marcas})")
                    ->execute($revocados);
            }

            $token = bin2hex(random_bytes(32));
            $stmt = $this->conexion->prepare(
                'INSERT INTO pos_equipos (tenant_id, caja_id, token_hash, nombre, habilitado_por)
                 VALUES (:t, :c, :h, :n, :u)'
            );
            $stmt->execute([
                ':t' => $tenantId, ':c' => $cajaId, ':h' => hash('sha256', $token),
                ':n' => mb_substr($nombre, 0, 80), ':u' => $userId,
            ]);
            $equipoId = (int) $this->conexion->lastInsertId();
            $this->conexion->commit();
            return ['ok', ['equipo_id' => $equipoId, 'token' => $token, 'revocados' => $revocados]];
        } catch (Throwable $e) {
            if ($this->conexion->inTransaction()) {
                $this->conexion->rollBack();
            }
            throw $e;
        }
    }

    /** Revoca un equipo del tenant. false si no existe, es de otro tenant o ya estaba revocado. */
    public function revocarEquipo(int $tenantId, int $equipoId): bool
    {
        $stmt = $this->conexion->prepare(
            'UPDATE pos_equipos SET revocado_at = NOW()
             WHERE id = :id AND tenant_id = :t AND revocado_at IS NULL'
        );
        $stmt->execute([':id' => $equipoId, ':t' => $tenantId]);
        return $stmt->rowCount() === 1;
    }

    // ------------------------------------------------------------------
    // Bloqueo por PIN fallidos (docs/specs/pos.md A6)
    // ------------------------------------------------------------------

    /**
     * Cuenta un PIN fallido. Al quinto seguido bloquea el equipo y reinicia la
     * cuenta de intentos; cada bloqueo seguido dura el doble del anterior
     * (BLOQUEO_MINUTOS x 2^bloqueos_seguidos, tope BLOQUEO_MAX_MINUTOS).
     *
     * Un solo UPDATE: MySQL asigna de izquierda a derecha, asi que
     * bloqueado_hasta se calcula con bloqueos_seguidos e intentos VIEJOS, y
     * bloqueos_seguidos con los intentos VIEJOS, antes de tocarlos.
     *
     * @return array{bloqueado:bool,bloqueo_segundos:int,intentos_restantes:int}
     */
    public function registrarFalloPin(int $equipoId): array
    {
        $minutos = 'CAST(LEAST(' . self::BLOQUEO_MINUTOS . ' * POW(2, LEAST(bloqueos_seguidos, 20)), '
            . self::BLOQUEO_MAX_MINUTOS . ') AS UNSIGNED)';
        $stmt = $this->conexion->prepare(
            'UPDATE pos_equipos
             SET bloqueado_hasta   = IF(intentos_fallidos + 1 >= :max1, NOW() + INTERVAL ' . $minutos . ' MINUTE, bloqueado_hasta),
                 bloqueos_seguidos = IF(intentos_fallidos + 1 >= :max2, bloqueos_seguidos + 1, bloqueos_seguidos),
                 intentos_fallidos = IF(intentos_fallidos + 1 >= :max3, 0, intentos_fallidos + 1)
             WHERE id = :id'
        );
        $stmt->execute([':max1' => self::MAX_INTENTOS, ':max2' => self::MAX_INTENTOS, ':max3' => self::MAX_INTENTOS, ':id' => $equipoId]);

        $stmt = $this->conexion->prepare(
            'SELECT intentos_fallidos,
                    (bloqueado_hasta IS NOT NULL AND bloqueado_hasta > NOW()) AS bloqueado,
                    GREATEST(TIMESTAMPDIFF(SECOND, NOW(), bloqueado_hasta), 0) AS bloqueo_segundos
             FROM pos_equipos WHERE id = :id'
        );
        $stmt->execute([':id' => $equipoId]);
        $f = $stmt->fetch() ?: ['intentos_fallidos' => 0, 'bloqueado' => 0, 'bloqueo_segundos' => 0];
        $bloqueado = (int) $f['bloqueado'] === 1;
        return [
            'bloqueado' => $bloqueado,
            'bloqueo_segundos' => $bloqueado ? (int) $f['bloqueo_segundos'] : 0,
            'intentos_restantes' => $bloqueado ? 0 : self::MAX_INTENTOS - (int) $f['intentos_fallidos'],
        ];
    }

    /** Alguien entro con un PIN valido: intentos y bloqueos seguidos vuelven a cero. */
    public function reiniciarIntentos(int $equipoId): void
    {
        $stmt = $this->conexion->prepare('UPDATE pos_equipos SET intentos_fallidos = 0, bloqueos_seguidos = 0 WHERE id = :id');
        $stmt->execute([':id' => $equipoId]);
    }

    private static function normalizarEquipo(array $f): array
    {
        $bloqueado = (int) ($f['bloqueado'] ?? 0) === 1;
        $salida = [
            'id' => (int) $f['id'],
            'caja_id' => (int) $f['caja_id'],
            'nombre' => $f['nombre'] ?? null,
            'created_at' => $f['created_at'] ?? null,
            'last_used' => $f['last_used'] ?? null,
            'bloqueado' => $bloqueado,
            'bloqueo_segundos' => $bloqueado ? (int) ($f['bloqueo_segundos'] ?? 0) : 0,
        ];
        if (array_key_exists('tenant_id', $f)) {
            $salida['tenant_id'] = (int) $f['tenant_id'];
        }
        if (array_key_exists('habilitado_por', $f)) {
            $salida['habilitado_por'] = $f['habilitado_por'] !== null ? (int) $f['habilitado_por'] : null;
            $salida['habilitado_por_nombre'] = ($f['habilitado_por_nombre'] ?? '') !== '' ? $f['habilitado_por_nombre'] : null;
        }
        return $salida;
    }
}
