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
    /**
     * Codigo y texto de la negativa EDICION_NO_INCLUYE (403): una ruta o un modulo que la edicion Express no incluye.
     * Viven aqui y no en TenantEntitlements porque son lo unico que una peticion necesita cuando esa clase no carga
     * (cargarEntitlements() devuelve false): AuthMiddleware contesta con ellos. TenantEntitlements::CODIGO_EDICION_NO_INCLUYE
     * y TenantEntitlements::MSG_EDICION son alias de estos, no otra copia.
     *
     * El texto no termina en punto y tiene que ser igual, byte a byte, a MSG_EDICION del mock del front
     * (express/dev/mock-api.mjs); el cliente se guia por el codigo, nunca por el texto.
     */
    public const CODIGO_EDICION_NO_INCLUYE = 'EDICION_NO_INCLUYE';
    public const MSG_EDICION = 'Tu plan Express no incluye esta función';

    /**
     * Cabecera con la que el cliente de Fiscalpoint Express se anuncia en cada peticion (valor 'express'): en PHP llega como
     * $_SERVER['HTTP_X_FISCALPOINT_EDICION']. Solo la mira esFilaExpress, para una empresa 'ambas'. Un cliente que la manda llega
     * a Express por la misma ruta de la misma API: tiene que salir del mismo origen que la pagina (el CSP de Express ya lo exige),
     * porque ninguno de los controllers la lista en su Access-Control-Allow-Headers.
     */
    public const CABECERA_EDICION = 'X-Fiscalpoint-Edicion';

    private static ?array $current = null;

    /** Resultado de cargarEntitlements() en este proceso; null = aun no se intento. */
    private static ?bool $entitlementsCargados = null;

    /** @return array|null The resolved tenant row, or null if none resolved. */
    public static function current(): ?array
    {
        return self::$current;
    }

    /**
     * ¿La peticion en curso se anuncia como de Fiscalpoint Express (CABECERA_EDICION con el valor express, sin espacios y en
     * minusculas)? La cabecera solo RESTRINGE: la usa esFilaExpress para que una empresa 'ambas' corra esta peticion con los
     * limites de Express, y nunca da mas de lo que la empresa ya tiene (quien la omite corre como la app completa, y una empresa
     * 'completa' la ignora). Por eso no es una credencial: quitarla no abre nada que la misma cuenta no tenga ya en la app completa.
     */
    public static function peticionExpress(): bool
    {
        return strtolower(trim((string) ($_SERVER['HTTP_X_FISCALPOINT_EDICION'] ?? ''))) === 'express';
    }

    /**
     * ¿Corre esta peticion, para la empresa de $fila, con los limites de Express? Es la prueba en linea que las peticiones hacen
     * ANTES de cargar TenantEntitlements (esa clase no se incluye jamas para quien no la necesita), y la unica copia de la regla:
     *   - tipo app y edicion 'express' (solo Express): siempre, sea cual sea la cabecera;
     *   - tipo app y edicion 'ambas' (la app completa Y Express): solo si la peticion se anuncia como Express (peticionExpress);
     *   - cualquier otra fila (completa, un valor desconocido, integracion, la columna ausente, ninguna fila): nunca.
     * La edicion se lee sin espacios y en minusculas, como TenantEntitlements::normalizarEdicion. No escribe en el log.
     */
    public static function esFilaExpress(?array $fila): bool
    {
        if (($fila['tipo'] ?? 'app') !== 'app') {
            return false;
        }
        $edicion = strtolower(trim((string) ($fila['edicion'] ?? '')));
        return $edicion === 'express' || ($edicion === 'ambas' && self::peticionExpress());
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

    /**
     * ¿La empresa resuelta bloquea las ventas sin existencia? (master.tenants
     * .inventario_estricto, migracion 013; un admin la cambia en Configuracion).
     *
     * Solo vale para empresas tipo 'app': una de integracion no tiene catalogo ni
     * stock. Sin empresa resuelta, con la columna ausente (master sin migrar) o en
     * 0 es false: el codigo nuevo puede subir antes que la migracion sin cambiar
     * nada, y toda empresa sigue vendiendo sin existencia como hasta hoy.
     */
    public static function inventarioEstricto(): bool
    {
        $tenant = self::$current;
        return $tenant !== null
            && ($tenant['tipo'] ?? 'app') === 'app'
            && (int) ($tenant['inventario_estricto'] ?? 0) === 1;
    }

    /**
     * Carga src/TenantEntitlements.php, la clase de la edicion Express, y dice si quedo disponible. Es el unico lugar que la
     * carga, y solo se llama despues de la prueba en linea que ya probo que la empresa es Express: para una empresa completa,
     * una de integracion o ninguna la clase no se incluye jamas, asi un archivo ausente o roto no le cambia nada a nadie mas.
     *
     * false si el archivo no esta, si no compila o lanza al cargarse, o si cargado no declara la clase. Entonces deja UNA linea
     * en el log (el resultado se recuerda: un proceso no lo vuelve a intentar) y quien llama lo trata como una negativa para la
     * empresa Express, nunca como un 500: sin su clase no se puede decidir y no pasa.
     */
    public static function cargarEntitlements(): bool
    {
        if (self::$entitlementsCargados !== null) {
            return self::$entitlementsCargados;
        }
        $archivo = __DIR__ . '/TenantEntitlements.php';
        $motivo = null;
        if (!is_file($archivo)) {
            $motivo = 'no existe src/TenantEntitlements.php';
        } else {
            try {
                require_once $archivo;
            } catch (Throwable $e) {
                $motivo = get_class($e) . ': ' . $e->getMessage();
            }
            if ($motivo === null && !class_exists('TenantEntitlements', false)) {
                $motivo = 'el archivo no declara la clase';
            }
        }
        if ($motivo !== null) {
            error_log('[TenantResolver] cargarEntitlements: no se pudo cargar TenantEntitlements (' . $motivo . '); las empresas Express se niegan');
        }
        return self::$entitlementsCargados = ($motivo === null);
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
