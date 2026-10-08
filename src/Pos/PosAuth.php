<?php
require_once __DIR__ . '/PosError.php';
require_once __DIR__ . '/../TenantResolver.php';
require_once __DIR__ . '/../RequestContext.php';
require_once __DIR__ . '/../Models/posMasterModel.php';

/**
 * Autenticacion del POS (docs/specs/pos.md §9.2): dos niveles, cada uno con su
 * header, separados de la sesion de usuario de app.* (Authorization/X-API-KEY).
 *
 *   X-POS-EQUIPO  token del equipo de caja: resuelve tenant + caja. Lo entrega
 *                 un admin al habilitar el equipo (POST /api/pos-admin/equipos)
 *                 y SOLO abre /api/pos/*.
 *   X-POS-SESION  token del empleado que entro con su PIN en ESE equipo
 *                 (POST /api/pos/sesion).
 *
 * Los dos se guardan como sha256; el valor en claro solo existe en el equipo.
 */
final class PosAuth
{
    private static ?array $equipo = null;

    /**
     * Equipo del request con su tenant resuelto, o PosError con el codigo que
     * lleva a la pantalla correcta. Se resuelve una vez por request.
     */
    public static function requerirEquipo(): array
    {
        if (self::$equipo !== null) {
            return self::$equipo;
        }
        $token = trim((string) ($_SERVER['HTTP_X_POS_EQUIPO'] ?? ''));
        if ($token === '') {
            throw new PosError('Este equipo no está habilitado como caja. Pide a un administrador que lo habilite.', 401, 'EQUIPO_NO_HABILITADO');
        }

        $master = new posMasterModel();
        $equipo = $master->equipoPorToken(hash('sha256', $token));
        if ($equipo === null) {
            // Token desconocido o revocado: para el equipo es lo mismo.
            throw new PosError('Este equipo ya no está habilitado como caja. Pide a un administrador que lo habilite de nuevo.', 401, 'EQUIPO_NO_HABILITADO');
        }
        if (!TenantResolver::resolveById($equipo['tenant_id'])) {
            throw new PosError('La empresa de este equipo no está activa.', 403, 'EMPRESA_INACTIVA');
        }
        self::exigirPosActivo(TenantResolver::current());

        RequestContext::fromAuth(['tenant_id' => $equipo['tenant_id']], null);
        $master->tocarEquipo($equipo['id']);
        return self::$equipo = $equipo;
    }

    /**
     * Sesion del empleado en el equipo del request. Requiere requerirEquipo()
     * antes (y el tenant resuelto: posModel conecta a su DB).
     *
     * @return array{sesion_id:int,empleado:array}
     */
    public static function requerirSesion(posModel $pos, array $equipo): array
    {
        $token = trim((string) ($_SERVER['HTTP_X_POS_SESION'] ?? ''));
        if ($token === '') {
            throw new PosError('Entra con tu PIN.', 401, 'SESION_REQUERIDA');
        }
        $sesion = $pos->sesionVigente(hash('sha256', $token), $equipo['id']);
        if ($sesion === null) {
            throw new PosError('Tu sesión se cerró. Entra de nuevo con tu PIN.', 401, 'SESION_REQUERIDA');
        }
        RequestContext::set('username', 'POS · ' . $sesion['empleado']['nombre']);
        return $sesion;
    }

    /**
     * Para el PermissionGate: resolver el tenant del equipo sin cortar el
     * request. El controller valida de verdad (requerirEquipo) y responde con
     * el codigo correcto; aqui un fallo solo se ignora.
     */
    public static function resolverTenantSiSePuede(): void
    {
        try {
            self::requerirEquipo();
        } catch (Throwable $e) {
            // El controller lo vuelve a intentar y responde.
        }
    }

    /** El tenant tiene que ser tipo app y tener el POS activo (master.tenants.pos_enabled). */
    public static function exigirPosActivo(?array $tenant): void
    {
        if ($tenant === null || ($tenant['tipo'] ?? 'app') !== 'app' || (int) ($tenant['pos_enabled'] ?? 0) !== 1) {
            throw new PosError('El POS no está activo para esta empresa.', 403, 'POS_INACTIVO');
        }
    }

    /** Solo para pruebas: olvida el equipo resuelto en este proceso. */
    public static function reiniciar(): void
    {
        self::$equipo = null;
    }
}
