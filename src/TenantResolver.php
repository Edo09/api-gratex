<?php
require_once(__DIR__ . '/MasterDatabase.php');
require_once(__DIR__ . '/Database.php');

/**
 * Resolves the active tenant and points the Database singleton at its DB.
 *
 * Resolution sources:
 *   - resolveByApiKey  -> integration mode (machine, JSON->XML)
 *   - resolveById      -> App mode (after token -> tenant_id via master)
 *   - resolveByRnc     -> DGII incoming (recepcion / aprobacion comercial)
 *
 * On success, Database::setCredentials() is set so all existing models/queries
 * transparently hit the tenant DB. See docs/multi-emisor-master-db-prd.md.
 */
class TenantResolver
{
    private static ?array $current = null;

    /** @return array|null The resolved tenant row, or null if none resolved. */
    public static function current(): ?array
    {
        return self::$current;
    }

    public static function resolveByApiKey(string $apiKey): bool
    {
        return self::apply(MasterDatabase::getInstance()->getTenantByApiKey($apiKey));
    }

    /**
     * Integration auth (Method A): resolve by api_key and verify api_secret.
     * The secret is compared against its stored sha256 hash in constant time.
     */
    public static function resolveByCredentials(string $apiKey, string $apiSecret): bool
    {
        $tenant = MasterDatabase::getInstance()->getTenantByApiKey($apiKey);
        if (!$tenant) {
            return false;
        }
        $expected = (string) ($tenant['api_secret_hash'] ?? '');
        if ($expected === '' || !hash_equals($expected, hash('sha256', $apiSecret))) {
            return false;
        }
        return self::apply($tenant);
    }

    /** sha256 hash of an api_secret, for storage/verification. */
    public static function hashSecret(string $apiSecret): string
    {
        return hash('sha256', $apiSecret);
    }

    public static function resolveByRnc(string $rnc): bool
    {
        return self::apply(MasterDatabase::getInstance()->getTenantByRnc($rnc));
    }

    public static function resolveById(int $id): bool
    {
        return self::apply(MasterDatabase::getInstance()->getTenantById($id));
    }

    /**
     * Apply a tenant row.
     *  - tipo "app": decrypt its DB password and point Database at its own DB.
     *  - tipo "integracion": NO per-tenant DB. The connection is NOT switched;
     *    the integration emitter persists e-CF backups in the master DB.
     */
    private static function apply(?array $tenant): bool
    {
        if (!$tenant || (int)($tenant['activo'] ?? 0) !== 1) {
            return false;
        }
        self::$current = $tenant;

        if (($tenant['tipo'] ?? 'app') === 'app') {
            Database::setCredentials([
                'host' => $tenant['db_host'],
                'port' => $tenant['db_port'] ?? '3306',
                'name' => $tenant['db_name'],
                'user' => $tenant['db_user'],
                'pass' => self::decrypt($tenant['db_pass_encrypted']),
            ]);
        }
        return true;
    }

    /** @return bool True if the resolved tenant is integration-type (no own DB). */
    public static function isIntegration(): bool
    {
        return self::$current !== null && (self::$current['tipo'] ?? 'app') === 'integracion';
    }

    // ------------------------------------------------------------------
    // AES-256-GCM credential cipher.
    // Blob layout: iv(12) || tag(16) || ciphertext
    // ------------------------------------------------------------------

    public static function encrypt(string $plain): string
    {
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($ct === false) {
            throw new RuntimeException('No se pudo cifrar la credencial.');
        }
        return $iv . $tag . $ct;
    }

    public static function decrypt(string $blob): string
    {
        $iv  = substr($blob, 0, 12);
        $tag = substr($blob, 12, 16);
        $ct  = substr($blob, 28);
        $plain = openssl_decrypt($ct, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new RuntimeException('No se pudo descifrar la credencial del tenant (key/tag invalido).');
        }
        return $plain;
    }

    private static function key(): string
    {
        $hex = getenv('MASTER_ENCRYPTION_KEY') ?: ($_ENV['MASTER_ENCRYPTION_KEY'] ?? '');
        if ($hex === '' || strlen($hex) !== 64) {
            throw new RuntimeException('MASTER_ENCRYPTION_KEY ausente o no es de 64 chars hex (32 bytes).');
        }
        return hex2bin($hex);
    }
}
