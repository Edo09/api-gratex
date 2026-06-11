<?php
require_once(__DIR__ . '/Utils/TokenGenerator.php');

/**
 * Singleton PDO connection to the master DB (gratex_master).
 *
 * The master DB holds the tenant registry plus centralized auth (users +
 * api_tokens), so a request can be routed to the correct tenant DB before any
 * business query runs. See docs/multi-emisor-master-db-prd.md.
 */
class MasterDatabase
{
    private static ?MasterDatabase $instance = null;
    private PDO $conexion;

    private static function loadEnv(): void
    {
        $envFile = __DIR__ . '/../.env';
        if (!is_file($envFile)) {
            return;
        }
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if ($line[0] === '#' || strpos($line, '=') === false) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if (!array_key_exists($key, $_ENV)) {
                $_ENV[$key] = $value;
                putenv("{$key}={$value}");
            }
        }
    }

    private function __construct()
    {
        self::loadEnv();
        $host = $_ENV['MASTER_DB_HOST'] ?? 'localhost';
        $port = $_ENV['MASTER_DB_PORT'] ?? '3306';
        $name = $_ENV['MASTER_DB_NAME'] ?? 'gratex_master';
        $user = $_ENV['MASTER_DB_USER'] ?? '';
        $pass = $_ENV['MASTER_DB_PASS'] ?? '';

        try {
            $this->conexion = new PDO(
                "mysql:host={$host}:{$port};dbname={$name};charset=utf8mb4",
                $user,
                $pass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_PERSISTENT => false
                ]
            );
        } catch (PDOException $e) {
            die('Master DB connection failed: ' . $e->getMessage());
        }
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new MasterDatabase();
        }
        return self::$instance;
    }

    public function getConnection(): PDO
    {
        return $this->conexion;
    }

    // ------------------------------------------------------------------
    // Tenant lookups
    // ------------------------------------------------------------------

    /**
     * Resolve a tenant by its integration api_key (machine JSON->XML mode).
     * @return array|null Tenant row or null if not found / inactive.
     */
    public function getTenantByApiKey(string $apiKey): ?array
    {
        $stmt = $this->conexion->prepare(
            "SELECT * FROM tenants WHERE api_key = :k AND activo = 1 LIMIT 1"
        );
        $stmt->execute([':k' => $apiKey]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Resolve a tenant by RNC (DGII incoming: recepcion / aprobacion comercial).
     */
    public function getTenantByRnc(string $rnc): ?array
    {
        $stmt = $this->conexion->prepare(
            "SELECT * FROM tenants WHERE rnc = :r AND activo = 1 LIMIT 1"
        );
        $stmt->execute([':r' => $rnc]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function getTenantById(int $id): ?array
    {
        $stmt = $this->conexion->prepare(
            "SELECT * FROM tenants WHERE id = :id AND activo = 1 LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Update branding fields of a tenant (Representacion Impresa).
     * Only whitelisted columns; values may be null to clear them.
     * @param array $fields Subset of: pdf_template, pdf_accent_color, logo_path
     */
    public function updateTenantBranding(int $tenantId, array $fields): void
    {
        $allowed = ['pdf_template', 'pdf_accent_color', 'logo_path'];
        $sets = [];
        $params = [':id' => $tenantId];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $fields)) {
                $sets[] = "`{$col}` = :{$col}";
                $params[":{$col}"] = $fields[$col];
            }
        }
        if (!$sets) {
            return;
        }
        $stmt = $this->conexion->prepare(
            'UPDATE tenants SET ' . implode(', ', $sets) . ' WHERE id = :id'
        );
        $stmt->execute($params);
    }

    // ------------------------------------------------------------------
    // Centralized auth (App mode: users + api_tokens live here)
    // ------------------------------------------------------------------

    /**
     * Validate a session token against master.api_tokens.
     * @return array|null ['user_id','tenant_id'] if valid+active, else null.
     */
    public function validateUserToken(string $token): ?array
    {
        $hash = TokenGenerator::hashToken($token);
        $stmt = $this->conexion->prepare(
            "SELECT user_id, tenant_id, is_active FROM api_tokens WHERE token_hash = :h LIMIT 1"
        );
        $stmt->execute([':h' => $hash]);
        $row = $stmt->fetch();
        if ($row && (int)$row['is_active'] === 1) {
            return ['user_id' => (int)$row['user_id'], 'tenant_id' => (int)$row['tenant_id']];
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Integration mode: e-CF backup (tenants tipo "integracion" sin DB propia)
    // ------------------------------------------------------------------

    /**
     * Persist a generated e-CF for backup. Returns the inserted row id.
     * @param array $data tenant_id, rnc_emisor, tipo_ecf?, e_ncf?, rnc_comprador?,
     *                     monto_total?, track_id?, xml_firmado
     */
    public function saveIntegrationEcf(array $data): int
    {
        $stmt = $this->conexion->prepare(
            'INSERT INTO ecf_integracion_backup
                (tenant_id, rnc_emisor, tipo_ecf, e_ncf, rnc_comprador, monto_total, track_id, xml_firmado)
             VALUES
                (:tenant_id, :rnc_emisor, :tipo_ecf, :e_ncf, :rnc_comprador, :monto_total, :track_id, :xml_firmado)'
        );
        $stmt->execute([
            ':tenant_id'     => $data['tenant_id'],
            ':rnc_emisor'    => $data['rnc_emisor'],
            ':tipo_ecf'      => $data['tipo_ecf'] ?? null,
            ':e_ncf'         => $data['e_ncf'] ?? null,
            ':rnc_comprador' => $data['rnc_comprador'] ?? null,
            ':monto_total'   => $data['monto_total'] ?? null,
            ':track_id'      => $data['track_id'] ?? null,
            ':xml_firmado'   => $data['xml_firmado'],
        ]);
        return (int) $this->conexion->lastInsertId();
    }
}
