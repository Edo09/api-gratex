<?php
require_once(__DIR__ . '/../Database.php');
require_once(__DIR__ . '/../MasterDatabase.php');

/**
 * AuditLogModel — persistencia de la bitacora de auditoria (audit_logs).
 *
 * Conexion igual que userModel/RoleModel: en multi-tenant la tabla vive en el
 * MASTER (gratex_master) y se aisla por tenant_id; en single-tenant vive en la
 * DB del tenant. El MASTER es independiente del switch de DB por-tenant
 * (TenantResolver), asi que la escritura siempre llega a una conexion viva.
 *
 * No exponer nunca este modelo directo a un controller: usar src/AuditLogger.php,
 * que auto-rellena el contexto y redacta secretos antes de insertar.
 */
class AuditLogModel
{
    private $conexion;

    public function __construct()
    {
        $this->conexion = self::multiTenant()
            ? MasterDatabase::getInstance()->getConnection()
            : Database::getInstance()->getConnection();
    }

    private static function multiTenant(): bool
    {
        return filter_var(
            getenv('MULTI_TENANT_ENABLED') ?: ($_ENV['MULTI_TENANT_ENABLED'] ?? false),
            FILTER_VALIDATE_BOOLEAN
        );
    }

    private const COLS = [
        'tenant_id', 'user_id', 'username', 'email', 'module', 'entity_type',
        'entity_id', 'action', 'http_method', 'endpoint', 'ip_address',
        'user_agent', 'browser', 'os', 'device_type', 'session_token_hash',
        'old_values', 'new_values', 'description', 'success', 'error_message',
    ];

    /**
     * Inserta una fila de auditoria. $row usa las claves de self::COLS; las que
     * falten van como NULL. created_at lo pone la DB (DEFAULT CURRENT_TIMESTAMP).
     */
    public function insert(array $row): void
    {
        $cols = self::COLS;
        $placeholders = array_map(fn($c) => ':' . $c, $cols);
        $sql = 'INSERT INTO audit_logs (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $placeholders) . ')';
        $stmt = $this->conexion->prepare($sql);
        $params = [];
        foreach ($cols as $c) {
            $params[':' . $c] = $row[$c] ?? null;
        }
        $stmt->execute($params);
    }

    private const SELECT_COLS = 'id, tenant_id, user_id, username, email, module, entity_type, entity_id, '
        . 'action, http_method, endpoint, ip_address, user_agent, browser, os, device_type, '
        . 'old_values, new_values, description, success, error_message, created_at';

    /**
     * Busca filas filtradas y paginadas (para timeline / historial / filtros UI).
     * Siempre exige tenant_id para aislar. Filtros soportados: user_id, module,
     * action, entity_type, entity_id, success, from (>=), to (<=).
     */
    public function search(array $filters, int $offset, int $limit): array
    {
        return $this->fetchPage(self::buildWhere($filters, true), $offset, $limit);
    }

    /** Total de filas que cumplen los filtros (para paginacion). */
    public function count(array $filters): int
    {
        return $this->fetchCount(self::buildWhere($filters, true));
    }

    /**
     * Resumen de las filas que cumplen los filtros (tarjetas de la bitacora):
     * totales, fallidos, accesos denegados, logins fallidos, usuarios distintos,
     * los 5 usuarios con mas actividad y el conteo por modulo. Mismos filtros y
     * mismo aislamiento por tenant que search().
     */
    public function summary(array $filters): array
    {
        [$clause, $params] = self::buildWhere($filters, true);

        $stmt = $this->conexion->prepare(
            'SELECT COUNT(*) AS total,'
            . ' COALESCE(SUM(success = 0), 0) AS fallidos,'
            . " COALESCE(SUM(action = 'ACCESS_DENIED'), 0) AS accesos_denegados,"
            . " COALESCE(SUM(action = 'LOGIN_FAILED'), 0) AS logins_fallidos,"
            . ' COUNT(DISTINCT user_id) AS usuarios'
            . ' FROM audit_logs WHERE ' . $clause
        );
        $stmt->execute($params);
        $tot = $stmt->fetch() ?: [];

        $stmt = $this->conexion->prepare(
            'SELECT user_id, MAX(username) AS username, MAX(email) AS email, COUNT(*) AS total'
            . ' FROM audit_logs WHERE ' . $clause . ' AND user_id IS NOT NULL'
            . ' GROUP BY user_id ORDER BY total DESC LIMIT 5'
        );
        $stmt->execute($params);
        $usuarios = $stmt->fetchAll();

        $stmt = $this->conexion->prepare(
            'SELECT module, COUNT(*) AS total FROM audit_logs WHERE ' . $clause
            . ' GROUP BY module ORDER BY total DESC'
        );
        $stmt->execute($params);
        $modulos = $stmt->fetchAll();

        return [
            'total'             => (int) ($tot['total'] ?? 0),
            'fallidos'          => (int) ($tot['fallidos'] ?? 0),
            'accesos_denegados' => (int) ($tot['accesos_denegados'] ?? 0),
            'logins_fallidos'   => (int) ($tot['logins_fallidos'] ?? 0),
            'usuarios'          => (int) ($tot['usuarios'] ?? 0),
            'top_usuarios'      => array_map(static fn($r) => [
                'user_id'  => (int) $r['user_id'],
                'username' => $r['username'],
                'email'    => $r['email'],
                'total'    => (int) $r['total'],
            ], $usuarios),
            'por_modulo'        => array_map(static fn($r) => [
                'module' => $r['module'],
                'total'  => (int) $r['total'],
            ], $modulos),
        ];
    }

    /**
     * Valores que existen en la bitacora de UN tenant, para los desplegables de
     * filtros: modulos, acciones y usuarios (solo los que tienen filas, asi no
     * se ofrece un filtro que siempre da vacio).
     */
    public function facets(?int $tenantId): array
    {
        [$clause, $params] = self::buildWhere(['tenant_id' => $tenantId], true);

        $valores = function (string $col) use ($clause, $params): array {
            $stmt = $this->conexion->prepare("SELECT DISTINCT {$col} FROM audit_logs WHERE {$clause} ORDER BY {$col}");
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        };

        $stmt = $this->conexion->prepare(
            'SELECT user_id, MAX(username) AS username, MAX(email) AS email FROM audit_logs'
            . ' WHERE ' . $clause . ' AND user_id IS NOT NULL GROUP BY user_id ORDER BY username'
        );
        $stmt->execute($params);

        return [
            'modulos'  => $valores('module'),
            'acciones' => $valores('action'),
            'usuarios' => array_map(static fn($r) => [
                'user_id'  => (int) $r['user_id'],
                'username' => $r['username'],
                'email'    => $r['email'],
            ], $stmt->fetchAll()),
        ];
    }

    // ------------------------------------------------------------------
    // Vista de operaciones (TODOS los tenants) — solo public/audit_logs.php
    // ------------------------------------------------------------------

    /**
     * Como search() pero SIN forzar el aislamiento por tenant: la usa la
     * herramienta de operaciones public/audit_logs.php (token de ops del .env),
     * que ve la bitacora de todos los tenants. NUNCA llamarla desde un controller
     * de la API: esos usan search()/count() con el tenant del solicitante.
     *
     * tenant_id es opcional: ausente = todos; null = solo filas sin tenant (login
     * fallido, DGII entrante); int = ese tenant. Ademas de los filtros de search()
     * acepta `q`: texto libre sobre usuario, email, entidad, descripcion,
     * endpoint e IP.
     */
    public function searchAllTenants(array $filters, int $offset, int $limit): array
    {
        return $this->fetchPage(self::buildWhere($filters, false), $offset, $limit);
    }

    /** Total para searchAllTenants() (misma semantica de filtros). */
    public function countAllTenants(array $filters): int
    {
        return $this->fetchCount(self::buildWhere($filters, false));
    }

    /**
     * Valores distintos de `module` o `action` en toda la bitacora, para poblar
     * los filtros de la vista de operaciones. Columna por whitelist (va al SQL).
     */
    public function distinctValues(string $column): array
    {
        if (!in_array($column, ['module', 'action'], true)) {
            throw new InvalidArgumentException("Columna no permitida: {$column}");
        }
        return $this->conexion
            ->query("SELECT DISTINCT {$column} FROM audit_logs ORDER BY {$column}")
            ->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @param array{0:string,1:array} $where */
    private function fetchPage(array $where, int $offset, int $limit): array
    {
        [$clause, $params] = $where;
        $sql = 'SELECT ' . self::SELECT_COLS . ' FROM audit_logs WHERE ' . $clause
            . ' ORDER BY id DESC LIMIT :limit OFFSET :offset';
        $stmt = $this->conexion->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** @param array{0:string,1:array} $where */
    private function fetchCount(array $where): int
    {
        [$clause, $params] = $where;
        $stmt = $this->conexion->prepare('SELECT COUNT(*) AS c FROM audit_logs WHERE ' . $clause);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return (int) ($row['c'] ?? 0);
    }

    /**
     * @param bool $isolate true = tenant_id SIEMPRE presente (API, por tenant).
     *                      false = solo si viene en $filters (vista de operaciones).
     * @return array{0:string,1:array} clausula WHERE + parametros.
     */
    private static function buildWhere(array $filters, bool $isolate): array
    {
        $clauses = [];
        $params = [];

        // Con $isolate, tenant_id SIEMPRE presente para aislar (NULL valido para auditorias globales).
        if (array_key_exists('tenant_id', $filters) && $filters['tenant_id'] === null) {
            $clauses[] = 'tenant_id IS NULL';
        } elseif ($isolate || array_key_exists('tenant_id', $filters)) {
            $clauses[] = 'tenant_id = :tenant_id';
            $params[':tenant_id'] = (int) ($filters['tenant_id'] ?? 0);
        }

        $eq = ['user_id', 'module', 'action', 'entity_type', 'entity_id', 'success'];
        foreach ($eq as $f) {
            if (isset($filters[$f]) && $filters[$f] !== '') {
                $clauses[] = "{$f} = :{$f}";
                $params[":{$f}"] = $filters[$f];
            }
        }
        if (!empty($filters['from'])) {
            $clauses[] = 'created_at >= :from';
            $params[':from'] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $clauses[] = 'created_at <= :to';
            $params[':to'] = $filters['to'];
        }

        // Texto libre. Un placeholder por columna: con prepares nativos un mismo
        // :q repetido no es valido. % y _ del usuario se escapan (literal, no comodin).
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $ors = [];
            foreach (['username', 'email', 'entity_id', 'description', 'endpoint', 'ip_address'] as $i => $col) {
                $ors[] = "{$col} LIKE :q{$i}";
                $params[":q{$i}"] = $like;
            }
            $clauses[] = '(' . implode(' OR ', $ors) . ')';
        }

        return [$clauses ? implode(' AND ', $clauses) : '1=1', $params];
    }
}
