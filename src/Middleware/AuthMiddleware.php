<?php
require_once(__DIR__ . '/../Models/authModel.php');
require_once(__DIR__ . '/../TenantResolver.php');
require_once(__DIR__ . '/../RequestContext.php');

class AuthMiddleware
{
    private $authModel;

    /**
     * Valores de edicion desconocidos que ya dejaron su linea de log en este proceso (una peticion). validateRequest corre mas
     * de una vez por peticion (el gate y el controller): la linea sale una sola vez.
     *
     * @var array<string,true>
     */
    private static array $edicionesAvisadas = [];

    public function __construct()
    {
        $this->authModel = new authModel();
    }

    /**
     * Primer segmento de la ruta del API, calculado igual que src/Router.php: a
     * partir del PRIMER '/api/' (las URLs de callback de la DGII traen dos).
     * '' si la peticion no trae ruta (CLI).
     */
    public static function rutaApi(): string
    {
        $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
        $pos = strpos($path, '/api/');
        $resto = $pos !== false ? substr($path, $pos + 5) : ltrim($path, '/');
        $segmentos = explode('/', ltrim($resto, '/'));
        return strtolower((string) ($segmentos[0] ?? ''));
    }

    private static function multiTenant(): bool
    {
        return filter_var(
            getenv('MULTI_TENANT_ENABLED') ?: ($_ENV['MULTI_TENANT_ENABLED'] ?? false),
            FILTER_VALIDATE_BOOLEAN
        );
    }

    /**
     * Deja UNA linea en el log por empresa y valor cuando master.tenants.edicion trae algo que no es completa, express ni ambas
     * (el vacio incluido): la empresa se trata como completa y alguien tiene que corregir la fila. Se escribe aqui y no en
     * TenantEntitlements porque el camino de una empresa que no es Express no carga esa clase. El valor sale cortado a 32
     * caracteres y con todo lo que no es ASCII imprimible cambiado por '?': quien edite la columna no puede partir la linea.
     */
    private static function avisarEdicionDesconocida(array $tenant): void
    {
        $id = (int) ($tenant['id'] ?? 0);
        $valor = (string) preg_replace('/[^\x20-\x7E]/', '?', substr((string) $tenant['edicion'], 0, 32));
        $clave = $id . ':' . $valor;
        if (isset(self::$edicionesAvisadas[$clave])) {
            return;
        }
        self::$edicionesAvisadas[$clave] = true;
        error_log('[Edicion] valor desconocido en el tenant #' . $id . ': ' . $valor);
    }

    /**
     * Niega a una empresa Express con el 403 EDICION_NO_INCLUYE y corta la peticion. Es la respuesta de todo lo que no se puede decidir sobre ella (la clase de la edicion no carga,
     * su config no se puede leer): es un 403 y no un valid=false, porque los llamadores vuelven ese 401 y el cliente cerraria la sesion. El codigo y el texto salen de
     * TenantResolver, que esta cargada siempre.
     */
    private static function denegarEdicion(): never
    {
        http_response_code(403);
        echo json_encode([
            'status' => false,
            'error' => TenantResolver::MSG_EDICION,
            'codigo' => TenantResolver::CODIGO_EDICION_NO_INCLUYE,
        ]);
        exit;
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
            // Una credencial de integracion solo vale en /api/integracion/*. En
            // cualquier otra ruta se rechaza ANTES de resolver el tenant: un tenant
            // de integracion no tiene DB propia, asi que un controller de la app
            // caeria en la DB por defecto del .env (la de Gratex), y PermissionGate
            // en modo sombra (PERMISSIONS_ENFORCE=false) solo lo anotaba en el log.
            if (self::rutaApi() !== 'integracion') {
                return [
                    'valid' => false,
                    'user_id' => null,
                    'tenant_id' => null,
                    'message' => 'Estas credenciales solo sirven para las rutas /api/integracion/*.'
                ];
            }
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
            $result = [
                'valid' => true,
                'user_id' => null,
                'tenant_id' => (int) $tenant['id'],
                'message' => 'Integration tenant validated'
            ];
            RequestContext::fromAuth($result, null); // principal maquina (sin user)
            return $result;
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

            // Edicion Express (plan 02). La prueba va en linea y es falsa para una empresa completa, una de integracion o
            // ninguna: ese camino sigue igual y TenantEntitlements ni se carga. Una empresa Express pasa por el vencimiento
            // por inactividad y por la guarda de rutas ANTES de tocar last_used, asi que una peticion negada no mantiene
            // viva una sesion. Una empresa 'ambas' (la app completa y Express) entra aqui solo con las peticiones que se anuncian
            // como Express (cabecera X-Fiscalpoint-Edicion: express, ver TenantResolver::esFilaExpress); el resto de sus peticiones
            // sigue por el camino de una completa, sin cargar la clase.
            $tenantActual = TenantResolver::current();
            if (TenantResolver::esFilaExpress($tenantActual)) {
                if (!TenantResolver::cargarEntitlements()) {
                    // Sin la clase no se puede decidir y una empresa Express no pasa sin que se decida (403, ver denegarEdicion).
                    self::denegarEdicion();
                }
                // La identidad primero: la fila de auditoria de una negativa tiene que nombrar al usuario.
                RequestContext::fromAuth([
                    'user_id' => $validation['user_id'] ?? null,
                    'tenant_id' => $validation['tenant_id'] ?? null,
                    'role' => $validation['role'] ?? null,
                ], $token);
                // El vencimiento va antes que la guarda: un token ocioso recibe 401 en cualquier ruta. tokenCaducado lee los dias de inactividad de config/editions.php: un archivo que
                // no compila, que lanza al cargarse o que falta no se vuelve un 500. Sin su config no se puede decidir y una empresa Express no pasa: el mismo 403 que sin la clase.
                try {
                    $caducado = TenantEntitlements::tokenCaducado($validation['last_used'] ?? null, $validation['created_at'] ?? null, time());
                } catch (Throwable $e) {
                    error_log('[AuthMiddleware] edicion: no se pudo decidir el vencimiento de la sesion de la empresa Express #' . (int) ($tenantActual['id'] ?? 0)
                        . ' (config/editions.php no carga o es invalido): ' . get_class($e) . ': ' . substr(trim((string) preg_replace('/\s+/', ' ', $e->getMessage())), 0, 300) . '; se niega con 403');
                    self::denegarEdicion();
                }
                if ($caducado) {
                    $this->authModel->revokeTokenByHash(hash('sha256', $token));
                    return [
                        'valid' => false,
                        'user_id' => null,
                        'tenant_id' => null,
                        'message' => 'Tu sesión venció por inactividad'
                    ];
                }
                // Puede contestar 403 o 400 y cortar con exit: solo si deja pasar se llega a last_used.
                TenantEntitlements::guardaRuta($validation['role'] ?? null);
            } elseif (isset($tenantActual['edicion'])) {
                // Un valor que no es completa, express ni ambas se trata como completa (ver avisarEdicionDesconocida).
                $edicionFila = strtolower(trim((string) $tenantActual['edicion']));
                if ($edicionFila !== 'completa' && $edicionFila !== 'express' && $edicionFila !== 'ambas') {
                    self::avisarEdicionDesconocida($tenantActual);
                }
            }

            // Update last_used timestamp
            $token_hash = hash('sha256', $token);
            $this->authModel->updateLastUsed($token_hash);

            $result = [
                'valid' => true,
                'user_id' => $validation['user_id'],
                'tenant_id' => $validation['tenant_id'] ?? null,
                'role' => $validation['role'] ?? null,
                'message' => 'Token validated'
            ];
            // Contexto de auditoria: identidad + hash del token de sesion (nunca el token).
            RequestContext::fromAuth($result, $token);
            return $result;
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
