<?php
require_once(__DIR__ . '/Middleware/AuthMiddleware.php');
require_once(__DIR__ . '/Models/RoleModel.php');

/**
 * PermissionGate — control de acceso central (RBAC), invocado por el Router
 * ANTES de incluir el controller. Reemplaza la pre-resolucion best-effort de
 * tenant: para principals maquina/externos (integracion/DGII) resuelve el tenant
 * igual que antes; para rutas de usuario-app exige token valido + permiso.
 *
 * Clasificacion por el valor de config/permissions.php['routes']:
 *   'public'             -> sin auth.
 *   'dgii' | 'integration' -> principal externo; resuelve tenant best-effort, el
 *                            controller hace su propia validacion (firma/secret).
 *   '<permiso>'          -> ruta de usuario-app: 401 si no hay user valido, 403 si
 *                            el rol del user no tiene el permiso.
 *
 * Rollout en sombra: con PERMISSIONS_ENFORCE=false (default) NO bloquea; solo
 * registra en error_log lo que se denegaria. Con true aplica de verdad.
 */
class PermissionGate
{
    public static function enforce(string $route, string $method, string $sub = ''): void
    {
        $cfg = require __DIR__ . '/../config/permissions.php';
        $required = self::resolveRequirement($cfg['routes'] ?? [], $route, $method, $sub);

        // Ruta no mapeada: no se aplica RBAC (el controller sigue exigiendo token
        // por su cuenta). Se registra para que se agregue al mapa.
        //
        // Pero el tenant hay que resolverlo IGUAL. Los controllers instancian sus
        // models en el tope del archivo, antes de su propio validateRequest, y un
        // model sin credenciales de tenant hace que Database no sepa a que DB
        // conectarse. Cuando esta rama volvia sin resolver nada, el modulo nuevo
        // moria con un 500 vacio que no apuntaba a ningun sitio (paso con
        // 'inventario' el 2026-09-04). Olvidarse del mapa debe costar una entrada
        // en el log, no un modulo caido.
        if ($required === null) {
            error_log("[PermissionGate] ruta sin mapeo RBAC: {$route} {$method}");
            self::resolverTenant('ruta sin mapeo');
            return;
        }

        if ($required === 'public') {
            return;
        }

        $auth = new AuthMiddleware();

        // Principals externos (DGII / integracion): resolver tenant best-effort,
        // el controller valida firma/secret. Sin chequeo de rol.
        if ($required === 'dgii' || $required === 'integration') {
            self::resolverTenant('externo');
            return;
        }

        // Ruta de usuario-app: required es un permiso concreto.
        $v = $auth->validateRequest();

        if (empty($v['valid'])) {
            self::deny($route, $method, $required, 'sin credenciales validas', 401, $v['message'] ?? 'Unauthorized');
            return;
        }

        $userId = $v['user_id'] ?? null;
        if ($userId === null) {
            // Principal maquina (integracion) sobre una ruta de app: prohibido.
            self::deny($route, $method, $required, 'principal no-usuario en ruta de app', 403,
                'Esta ruta requiere una sesion de usuario.');
            return;
        }

        $perms = (new RoleModel())->getPermissionsForRole(
            $v['tenant_id'] ?? null,
            (string) ($v['role'] ?? '')
        );

        if (!self::permMatches($perms, $required)) {
            self::deny($route, $method, $required, 'rol sin permiso', 403,
                'No tiene permiso para esta accion.');
            return;
        }
        // Autorizado: continua al controller.
    }

    /**
     * Resuelve el tenant sin exigir permiso: validateRequest() es quien llama a
     * Database::setCredentials(), asi que sin esto el controller conecta a la DB
     * equivocada (o a ninguna). Best-effort a proposito: si el token no sirve, el
     * controller devolvera su propio 401; lo que no puede es morir antes.
     */
    private static function resolverTenant(string $motivo): void
    {
        try {
            (new AuthMiddleware())->validateRequest();
        } catch (Throwable $e) {
            error_log("[PermissionGate] resolucion tenant ({$motivo}) fallo: " . $e->getMessage());
        }
    }

    /** Resuelve el permiso/tag requerido para una ruta+metodo. null = sin mapeo. */
    private static function resolveRequirement(array $routes, string $route, string $method, string $sub = ''): ?string
    {
        // Una subruta puede tener su propia regla: 'ecf/autenticacion' se resuelve
        // ANTES que 'ecf'. Sin esto, todas las rutas bajo un recurso comparten
        // requisito, y el handshake de DGII (un GET) caia en el permiso de app.
        $clave = $sub !== '' && array_key_exists($route . '/' . $sub, $routes)
            ? $route . '/' . $sub
            : $route;
        if (!array_key_exists($clave, $routes)) {
            return null;
        }
        $entry = $routes[$clave];
        if (is_string($entry)) {
            return $entry;
        }
        if (is_array($entry)) {
            return $entry[$method] ?? $entry['*'] ?? null;
        }
        return null;
    }

    /**
     * Match de permiso con comodines:
     *   '*'  | 'recurso.accion' | 'recurso.*' | '*.accion'
     */
    public static function permMatches(array $perms, string $required): bool
    {
        if (in_array('*', $perms, true) || in_array($required, $perms, true)) {
            return true;
        }
        $dot = strpos($required, '.');
        if ($dot !== false) {
            $res = substr($required, 0, $dot);
            $act = substr($required, $dot + 1);
            if (in_array("{$res}.*", $perms, true) || in_array("*.{$act}", $perms, true)) {
                return true;
            }
        }
        return false;
    }

    private static function enforcing(): bool
    {
        return filter_var(
            getenv('PERMISSIONS_ENFORCE') ?: ($_ENV['PERMISSIONS_ENFORCE'] ?? false),
            FILTER_VALIDATE_BOOLEAN
        );
    }

    /**
     * Deniega: en modo enforce responde y corta; en sombra solo registra y deja
     * continuar (para descubrir gaps con trafico real sin romper nada).
     */
    private static function deny(string $route, string $method, string $required, string $reason, int $code, string $msg): void
    {
        if (!self::enforcing()) {
            error_log("[PermissionGate][SHADOW] denegaria {$route} {$method} (req={$required}, {$reason})");
            return;
        }
        http_response_code($code);
        echo json_encode(['status' => false, 'error' => $msg]);
        exit;
    }
}
