<?php
require_once(__DIR__ . '/../Models/authModel.php');
require_once(__DIR__ . '/../TenantResolver.php');

class AuthMiddleware
{
    private $authModel;

    public function __construct()
    {
        $this->authModel = new authModel();
    }

    private static function multiTenant(): bool
    {
        return filter_var(
            getenv('MULTI_TENANT_ENABLED') ?: ($_ENV['MULTI_TENANT_ENABLED'] ?? false),
            FILTER_VALIDATE_BOOLEAN
        );
    }

    /**
     * Validate API credentials from request headers.
     *
     * Two schemes:
     *  - Integration (Method A): X-API-KEY + X-API-SECRET (per-tenant credentials).
     *  - App session: X-API-KEY: <token> or Authorization: Bearer <token>.
     *
     * @return array ['valid' => bool, 'user_id' => int|null, 'tenant_id' => int|null, 'message' => string]
     */
    public function validateRequest()
    {
        // --- Integration credentials (api_key + api_secret) ---
        // Presence of X-API-SECRET signals integration mode (machine, JSON->XML).
        if (self::multiTenant() && isset($_SERVER['HTTP_X_API_SECRET'])) {
            $apiKey = isset($_SERVER['HTTP_X_API_KEY']) ? trim($_SERVER['HTTP_X_API_KEY']) : '';
            $apiSecret = trim((string) $_SERVER['HTTP_X_API_SECRET']);
            if ($apiKey === '' || !TenantResolver::resolveByCredentials($apiKey, $apiSecret)) {
                return [
                    'valid' => false,
                    'user_id' => null,
                    'tenant_id' => null,
                    'message' => 'api_key/api_secret invalido o inactivo.'
                ];
            }
            $tenant = TenantResolver::current();
            return [
                'valid' => true,
                'user_id' => null,
                'tenant_id' => (int) $tenant['id'],
                'message' => 'Integration tenant validated'
            ];
        }

        // --- App session token (X-API-KEY or Bearer) ---
        $token = $this->getTokenFromHeader();

        if (!$token) {
            return [
                'valid' => false,
                'user_id' => null,
                'tenant_id' => null,
                'message' => 'Credenciales requeridas. Integracion: X-API-KEY + X-API-SECRET. App: Authorization: Bearer <token>.'
            ];
        }

        // Validate token (session token; from master DB in multi-tenant mode)
        $validation = $this->authModel->validateToken($token);

        if ($validation['valid']) {
            // In multi-tenant mode, point the DB connection at the token's tenant
            // before any controller runs a business query.
            if (self::multiTenant() && !empty($validation['tenant_id'])) {
                if (!TenantResolver::resolveById((int)$validation['tenant_id'])) {
                    return [
                        'valid' => false,
                        'user_id' => null,
                        'tenant_id' => null,
                        'message' => 'Tenant inactivo o no encontrado'
                    ];
                }
            }

            // Update last_used timestamp
            $token_hash = hash('sha256', $token);
            $this->authModel->updateLastUsed($token_hash);

            return [
                'valid' => true,
                'user_id' => $validation['user_id'],
                'tenant_id' => $validation['tenant_id'] ?? null,
                'role' => $validation['role'] ?? null,
                'message' => 'Token validated'
            ];
        }

        return [
            'valid' => false,
            'user_id' => null,
            'tenant_id' => null,
            'message' => 'Invalid or inactive API token'
        ];
    }

    /**
     * Extract token from X-API-KEY header or Authorization Bearer token
     * @return string|null Token or null if not present
     */
    private function getTokenFromHeader()
    {
        // Check for X-API-KEY header first
        if (isset($_SERVER['HTTP_X_API_KEY'])) {
            return trim($_SERVER['HTTP_X_API_KEY']);
        }
        
        // Check for Authorization: Bearer token format using getallheaders() (more reliable)
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            foreach ($headers as $key => $value) {
                if (strtolower($key) === 'authorization') {
                    $auth_header = trim($value);
                    if (preg_match('/Bearer\s+(.+)/i', $auth_header, $matches)) {
                        return trim($matches[1]);
                    }
                }
            }
        } else {
            // Fallback for servers without getallheaders()
            if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
                $auth_header = trim($_SERVER['HTTP_AUTHORIZATION']);
                if (preg_match('/Bearer\s+(.+)/i', $auth_header, $matches)) {
                    return trim($matches[1]);
                }
            }
        }
        
        return null;
    }

    /**
     * Send unauthorized response and exit
     * @param string $message Error message
     */
    public function sendUnauthorized($message = 'Unauthorized')
    {
        http_response_code(401);
        echo json_encode(['status' => false, 'error' => $message]);
        exit;
    }

    /**
     * Send forbidden response and exit (authenticated but not permitted).
     */
    public function sendForbidden($message = 'Forbidden')
    {
        http_response_code(403);
        echo json_encode(['status' => false, 'error' => $message]);
        exit;
    }

    private static function permissionsEnforced(): bool
    {
        return filter_var(
            getenv('PERMISSIONS_ENFORCE') ?: ($_ENV['PERMISSIONS_ENFORCE'] ?? false),
            FILTER_VALIDATE_BOOLEAN
        );
    }

    /**
     * Defense-in-depth helper for controllers needing a finer per-action check
     * than the central PermissionGate. Validates the request, resolves the
     * user's role permissions, and enforces $perm. Honors PERMISSIONS_ENFORCE:
     * in shadow mode it logs the would-be denial and allows.
     *
     * @return array The validation result (valid user).
     */
    public function requirePermission(string $perm): array
    {
        require_once __DIR__ . '/../Models/RoleModel.php';
        require_once __DIR__ . '/../PermissionGate.php';

        $v = $this->validateRequest();
        if (empty($v['valid'])) {
            $this->sendUnauthorized($v['message'] ?? 'Unauthorized');
        }
        if (($v['user_id'] ?? null) === null) {
            if (self::permissionsEnforced()) {
                $this->sendForbidden('Esta ruta requiere una sesion de usuario.');
            }
            error_log("[AuthMiddleware][SHADOW] requirePermission({$perm}): principal no-usuario");
            return $v;
        }
        $perms = (new RoleModel())->getPermissionsForRole($v['tenant_id'] ?? null, (string) ($v['role'] ?? ''));
        if (!PermissionGate::permMatches($perms, $perm)) {
            if (self::permissionsEnforced()) {
                $this->sendForbidden('No tiene permiso para esta accion.');
            }
            error_log("[AuthMiddleware][SHADOW] requirePermission({$perm}): rol sin permiso");
        }
        return $v;
    }
}
