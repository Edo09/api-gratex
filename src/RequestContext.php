<?php
require_once(__DIR__ . '/Utils/UserAgentParser.php');

/**
 * RequestContext — contexto de la peticion actual para auditoria.
 *
 * Captura UNA sola vez la identidad (user/tenant/rol/hash de token de sesion) y
 * los metadatos del request (IP, User-Agent + navegador/SO/dispositivo parseados,
 * metodo HTTP, endpoint). AuditLogger lee de aqui para auto-rellenar cada log,
 * de modo que los controllers solo pasan los campos de dominio (module, action,
 * entity, old/new, description) y no repiten user/tenant/ip en cada llamada.
 *
 * Poblacion:
 *   - Identidad: AuthMiddleware::validateRequest() llama fromAuth() en todo
 *     resultado valido (app e integracion). Un solo punto cubre todos los
 *     controllers de usuario.
 *   - Metadatos del request: se leen perezosamente de $_SERVER al pedirlos.
 *   - tenantId() cae a TenantResolver::current() (flujos DGII entrantes que
 *     resuelven el tenant por RNC sin sesion de usuario).
 */
class RequestContext
{
    private static ?int $userId = null;
    private static ?int $tenantId = null;
    private static ?string $role = null;
    private static ?string $username = null;
    private static ?string $email = null;
    private static ?string $sessionTokenHash = null;

    private static ?array $ua = null;        // cache del parse de User-Agent
    private static bool $identityLookedUp = false;

    /**
     * Set identity from an AuthMiddleware validation result.
     * @param array       $auth     ['user_id','tenant_id','role'] (cualquiera puede faltar)
     * @param string|null $rawToken token de sesion en claro (se guarda solo su sha256)
     */
    public static function fromAuth(array $auth, ?string $rawToken = null): void
    {
        if (!empty($auth['user_id'])) {
            self::$userId = (int) $auth['user_id'];
        }
        if (!empty($auth['tenant_id'])) {
            self::$tenantId = (int) $auth['tenant_id'];
        }
        if (!empty($auth['role'])) {
            self::$role = (string) $auth['role'];
        }
        if ($rawToken !== null && $rawToken !== '') {
            self::$sessionTokenHash = hash('sha256', $rawToken);
        }
    }

    /** Override puntual (ej. eventos de auth que conocen username/email del payload). */
    public static function set(string $field, $value): void
    {
        switch ($field) {
            case 'user_id':            self::$userId = $value !== null ? (int) $value : null; break;
            case 'tenant_id':          self::$tenantId = $value !== null ? (int) $value : null; break;
            case 'role':               self::$role = $value !== null ? (string) $value : null; break;
            case 'username':           self::$username = $value !== null ? (string) $value : null; break;
            case 'email':              self::$email = $value !== null ? (string) $value : null; break;
            case 'session_token_hash': self::$sessionTokenHash = $value !== null ? (string) $value : null; break;
        }
    }

    public static function userId(): ?int
    {
        return self::$userId;
    }

    public static function tenantId(): ?int
    {
        if (self::$tenantId !== null) {
            return self::$tenantId;
        }
        // Fallback: tenant resuelto por TenantResolver (DGII entrante / integracion).
        if (class_exists('TenantResolver')) {
            $t = TenantResolver::current();
            if ($t && isset($t['id'])) {
                return (int) $t['id'];
            }
        }
        return null;
    }

    public static function role(): ?string
    {
        return self::$role;
    }

    public static function sessionTokenHash(): ?string
    {
        return self::$sessionTokenHash;
    }

    public static function username(): ?string
    {
        self::lookupIdentity();
        return self::$username;
    }

    public static function email(): ?string
    {
        self::lookupIdentity();
        return self::$email;
    }

    /** Resuelve username/email del usuario actual una sola vez (master.users). */
    private static function lookupIdentity(): void
    {
        if (self::$identityLookedUp || self::$userId === null) {
            return;
        }
        if (self::$username !== null && self::$email !== null) {
            return;
        }
        self::$identityLookedUp = true;
        try {
            require_once(__DIR__ . '/Models/authModel.php');
            $profile = (new authModel())->getUserProfile(self::$userId);
            if ($profile) {
                self::$username = self::$username ?? ($profile['username'] ?? null);
                self::$email = self::$email ?? ($profile['email'] ?? null);
                self::$role = self::$role ?? ($profile['role'] ?? null);
            }
        } catch (Throwable $e) {
            // Auditoria nunca debe romper el request; sin identidad, seguimos.
        }
    }

    // ---- Metadatos del request (perezosos, desde $_SERVER) -------------------

    public static function ip(): ?string
    {
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $first = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
            return $first !== '' ? substr($first, 0, 45) : null;
        }
        return isset($_SERVER['REMOTE_ADDR']) ? substr($_SERVER['REMOTE_ADDR'], 0, 45) : null;
    }

    public static function userAgent(): ?string
    {
        return isset($_SERVER['HTTP_USER_AGENT'])
            ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255)
            : null;
    }

    public static function httpMethod(): ?string
    {
        return $_SERVER['REQUEST_METHOD'] ?? null;
    }

    public static function endpoint(): ?string
    {
        if (empty($_SERVER['REQUEST_URI'])) {
            return null;
        }
        return substr((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), 0, 255);
    }

    public static function browser(): ?string
    {
        return self::uaPart('browser');
    }

    public static function os(): ?string
    {
        return self::uaPart('os');
    }

    public static function deviceType(): ?string
    {
        return self::uaPart('device_type');
    }

    private static function uaPart(string $key): ?string
    {
        if (self::$ua === null) {
            self::$ua = UserAgentParser::parse(self::userAgent());
        }
        $val = self::$ua[$key] ?? null;
        return ($val === null || $val === 'Unknown' || $val === 'unknown') ? null : $val;
    }
}
