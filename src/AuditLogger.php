<?php
require_once(__DIR__ . '/RequestContext.php');
require_once(__DIR__ . '/Models/AuditLogModel.php');

/**
 * AuditLogger — fachada central de auditoria. Es lo UNICO que tocan los
 * controllers: una linea por accion significativa.
 *
 *   AuditLogger::log([
 *       'module'      => 'facturas',
 *       'action'      => 'EMIT',
 *       'entity_type' => 'factura',
 *       'entity_id'   => $facturaId,
 *       'old_values'  => null,
 *       'new_values'  => $response,
 *       'description' => 'Factura E31 emitida correctamente.',
 *   ]);
 *
 * Auto-rellena identidad/tenant/IP/UA/navegador/SO/dispositivo/metodo/endpoint
 * desde RequestContext, redacta secretos de old/new, y persiste via AuditLogModel.
 *
 * Garantias:
 *   - NUNCA lanza: cualquier fallo se traga (error_log) y no rompe el request.
 *   - Se puede apagar con AUDIT_LOG_ENABLED=false.
 *   - NUNCA guarda passwords, api_secrets, claves de certificado ni tokens en
 *     claro: redact() los reemplaza por '***REDACTED***' antes de serializar.
 */
class AuditLogger
{
    /** Subcadenas de nombre de campo cuyo valor se redacta (case-insensitive). */
    private const SENSITIVE = [
        'password', 'passwd', 'pwd', 'contrasena', 'contraseña',
        'secret', 'api_secret', 'apisecret', 'x-api-secret',
        'token', 'authorization', 'cert_pass', 'certpass', 'cert_password',
        'db_pass', 'dbpass', 'private_key', 'privatekey', 'api_key', 'apikey',
    ];

    private const REDACTED = '***REDACTED***';

    public static function enabled(): bool
    {
        $raw = getenv('AUDIT_LOG_ENABLED');
        if ($raw === false || $raw === '') {
            $raw = $_ENV['AUDIT_LOG_ENABLED'] ?? 'true'; // default ON
        }
        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Registra un evento. Claves requeridas: module, action. Opcionales (override
     * de lo auto-rellenado): entity_type, entity_id, old_values, new_values,
     * description, success, error_message, tenant_id, user_id, username, email,
     * session_token_hash.
     */
    public static function log(array $event): void
    {
        if (!self::enabled()) {
            return;
        }
        try {
            if (empty($event['module']) || empty($event['action'])) {
                error_log('[AuditLogger] evento sin module/action; se ignora.');
                return;
            }

            $row = [
                'tenant_id'          => $event['tenant_id']          ?? RequestContext::tenantId(),
                'user_id'            => $event['user_id']            ?? RequestContext::userId(),
                'username'           => $event['username']           ?? RequestContext::username(),
                'email'              => $event['email']              ?? RequestContext::email(),
                'module'             => self::cut($event['module'], 40),
                'entity_type'        => isset($event['entity_type']) ? self::cut($event['entity_type'], 60) : null,
                'entity_id'          => isset($event['entity_id']) && $event['entity_id'] !== null
                                            ? self::cut((string) $event['entity_id'], 64) : null,
                'action'             => self::cut($event['action'], 40),
                'http_method'        => self::cut(RequestContext::httpMethod(), 10),
                'endpoint'           => RequestContext::endpoint(),
                'ip_address'         => RequestContext::ip(),
                'user_agent'         => RequestContext::userAgent(),
                'browser'            => self::cut(RequestContext::browser(), 60),
                'os'                 => self::cut(RequestContext::os(), 60),
                'device_type'        => self::cut(RequestContext::deviceType(), 20),
                'session_token_hash' => $event['session_token_hash'] ?? RequestContext::sessionTokenHash(),
                'old_values'         => self::serialize($event['old_values'] ?? null),
                'new_values'         => self::serialize($event['new_values'] ?? null),
                'description'        => isset($event['description']) ? self::cut($event['description'], 255) : null,
                'success'            => array_key_exists('success', $event) ? (int) (bool) $event['success'] : 1,
                'error_message'      => isset($event['error_message']) ? self::cut((string) $event['error_message'], 500) : null,
            ];

            (new AuditLogModel())->insert($row);
        } catch (Throwable $e) {
            // La auditoria jamas rompe la operacion de negocio.
            error_log('[AuditLogger] fallo al registrar: ' . $e->getMessage());
        }
    }

    /**
     * Atajo para eventos de autenticacion (login/logout/expiracion). Fuerza
     * module=auth y tolera tenant/user nulos. Acepta username/email/success/
     * error_message/session_token_hash explicitos (vienen del payload de login).
     */
    public static function authEvent(array $event): void
    {
        $event['module'] = 'auth';
        self::log($event);
    }

    // ---- helpers -------------------------------------------------------------

    private static function cut(?string $v, int $max): ?string
    {
        if ($v === null) {
            return null;
        }
        // substr (no mb_*): consistente con el resto del codebase y no depende de
        // la extension mbstring (ausente en algunos entornos locales/server).
        return substr($v, 0, $max);
    }

    /** Redacta secretos y serializa a JSON (string) para old_values/new_values. */
    private static function serialize($value): ?string
    {
        if ($value === null) {
            return null;
        }
        // Strings JSON: decodificar para poder redactar por clave.
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = (json_last_error() === JSON_ERROR_NONE) ? $decoded : ['_raw' => $value];
        }
        $clean = self::redact($value);
        $json = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($json === false) {
            return null;
        }
        // Tope de seguridad (MEDIUMTEXT aguanta 16MB, pero no guardamos blobs enormes).
        return strlen($json) > 100000 ? substr($json, 0, 100000) : $json;
    }

    /**
     * Redacta recursivamente cualquier valor cuyo nombre de clave contenga una
     * subcadena sensible. Acepta arrays, objetos (stdClass) y escalares.
     */
    private static function redact($data, int $depth = 0)
    {
        if ($depth > 8) {
            return '***DEPTH_LIMIT***';
        }
        if (is_object($data)) {
            $data = (array) $data;
        }
        if (!is_array($data)) {
            return $data;
        }
        $out = [];
        foreach ($data as $key => $val) {
            if (is_string($key) && self::isSensitive($key)) {
                $out[$key] = self::REDACTED;
                continue;
            }
            $out[$key] = (is_array($val) || is_object($val)) ? self::redact($val, $depth + 1) : $val;
        }
        return $out;
    }

    private static function isSensitive(string $key): bool
    {
        $k = strtolower($key);
        foreach (self::SENSITIVE as $needle) {
            if (strpos($k, $needle) !== false) {
                return true;
            }
        }
        return false;
    }
}
