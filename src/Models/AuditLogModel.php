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
        [$where, $params] = self::buildWhere($filters);
        $sql = 'SELECT ' . self::SELECT_COLS . ' FROM audit_logs WHERE ' . $where
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

    /** Total de filas que cumplen los filtros (para paginacion). */
    public function count(array $filters): int
    {
        [$where, $params] = self::buildWhere($filters);
        $stmt = $this->conexion->prepare('SELECT COUNT(*) AS c FROM audit_logs WHERE ' . $where);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return (int) ($row['c'] ?? 0);
    }

    /** @return array{0:string,1:array} clausula WHERE + parametros. */
    private static function buildWhere(array $filters): array
    {
        $clauses = [];
        $params = [];

        // tenant_id SIEMPRE presente para aislar (NULL valido para auditorias globales).
        if (array_key_exists('tenant_id', $filters) && $filters['tenant_id'] === null) {
            $clauses[] = 'tenant_id IS NULL';
        } else {
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
        return [implode(' AND ', $clauses), $params];
    }
}
