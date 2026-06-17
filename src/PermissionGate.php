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
    public static function enforce(string $route, string $method): void
    {
        $cfg = require __DIR__ . '/../config/permissions.php';
        $required = self::resolveRequirement($cfg['routes'] ?? [], $route, $method);

        // Ruta no mapeada: no se aplica RBAC (el controller sigue exigiendo token
        // por su cuenta). Se registra para que se agregue al mapa.
        if ($required === null) {
            error_log("[PermissionGate] ruta sin mapeo RBAC: {$route} {$method}");
            return;
        }

        if ($required === 'public') {
            return;
        }

        $auth = new AuthMiddleware();

        // Principals externos (DGII / integracion): resolver tenant best-effort,
        // el controller valida firma/secret. Sin chequeo de rol.
        if ($required === 'dgii' || $required === 'integration') {
            try {
                $auth->validateRequest();
            } catch (Throwable $e) {
                error_log('[PermissionGate] resolucion tenant (externo) fallo: ' . $e->getMessage());
            }
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

    /** Resuelve el permiso/tag requerido para una ruta+metodo. null = sin mapeo. */
    private static function resolveRequirement(array $routes, string $route, string $method): ?string
    {
        if (!array_key_exists($route, $routes)) {
            return null;
        }
        $entry = $routes[$route];
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
