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
 * transparently hit the tenant DB. See docs/architecture.md.
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
    // Grupo de empresas — una credencial para varios RNC.
    //
    // Un cliente que administra varias empresas en SU propio sistema no deberia
    // manejar N pares de credenciales. Si sus tenants comparten `grupo_id`, la
    // credencial de cualquiera de ellos puede actuar por sus hermanos: el RNC
    // del payload elige la empresa (emisor.rnc al emitir, rnc_comprador al
    // aprobar, ?rnc= al consultar).
    //
    // Cada empresa sigue siendo un tenant completo: su propio certificado, su
    // propio ambiente y su propia certificacion DGII. El grupo agrupa el ACCESO,
    // no la identidad fiscal.
    // ------------------------------------------------------------------

    /** grupo_id del tenant actual, o null si esta aislado (el default). */
    public static function grupoId(): ?int
    {
        $g = self::$current['grupo_id'] ?? null;
        return ($g === null || $g === '') ? null : (int) $g;
    }

    /**
     * Empresas que la credencial actual puede representar. Siempre incluye al
     * tenant propio; con grupo, tambien sus hermanos activos.
     *
     * @return array<int,array{id:int,nombre:string,rnc:string,tipo:string,ambiente:string}>
     */
    public static function siblings(): array
    {
        if (self::$current === null) {
            return [];
        }
        $grupoId = self::grupoId();
        if ($grupoId === null) {
            return [[
                'id'       => (int) self::$current['id'],
                'nombre'   => (string) self::$current['nombre'],
                'rnc'      => (string) self::$current['rnc'],
                'tipo'     => (string) (self::$current['tipo'] ?? 'app'),
                'ambiente' => (string) (self::$current['ambiente'] ?? ''),
            ]];
        }
        return MasterDatabase::getInstance()->getTenantsInGroup($grupoId);
    }

    /**
     * Cambia el tenant activo a una empresa HERMANA (mismo grupo) por su RNC.
     *
     * Este es el limite de seguridad del modo multi-empresa. Deniega salvo que
     * TODAS estas condiciones se cumplan:
     *   1. Hay un tenant resuelto (la credencial ya se autentico).
     *   2. Ese tenant tiene `grupo_id` no nulo. Sin grupo no hay salto posible
     *      — es el caso de todos los tenants existentes.
     *   3. El RNC destino existe, esta activo y comparte el MISMO `grupo_id`
     *      (el filtro va en el SQL: `grupo_id = :g` nunca matchea un NULL).
     *   4. El hermano es del mismo `tipo` que el origen: una credencial de
     *      integracion no puede saltar a un tenant `app` (que tiene DB propia,
     *      usuarios y RBAC) ni al reves.
     *
     * Al cambiar tambien se actualiza el tenant del contexto de auditoria, para
     * que el log registre la empresa por la que realmente se actuo y no la
     * dueña de la credencial.
     *
     * @param string $rnc RNC destino (se normaliza a digitos).
     * @return bool True si el tenant activo quedo en el RNC pedido.
     */
    public static function switchToSibling(string $rnc): bool
    {
        if (self::$current === null) {
            return false;
        }
        $rnc = preg_replace('/\D/', '', $rnc);
        if ($rnc === '') {
            return false;
        }
        // Ya es el tenant activo: nada que hacer (caso normal de un solo RNC).
        if ($rnc === (string) self::$current['rnc']) {
            return true;
        }
        $grupoId = self::grupoId();
        if ($grupoId === null) {
            return false;
        }

        $destino = MasterDatabase::getInstance()->getTenantByRncInGroup($rnc, $grupoId);
        if (!$destino) {
            return false;
        }
        if (($destino['tipo'] ?? 'app') !== (self::$current['tipo'] ?? 'app')) {
            return false;
        }
        if (!self::apply($destino)) {
            return false;
        }

        if (class_exists('RequestContext')) {
            RequestContext::set('tenant_id', (int) $destino['id']);
        }
        return true;
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
