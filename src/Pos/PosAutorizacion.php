<?php
require_once __DIR__ . '/PosError.php';
require_once __DIR__ . '/PosPin.php';

/**
 * Permiso de supervisor de un solo proposito (docs/specs/pos.md S1, S2).
 *
 * El supervisor escribe su PIN en la sesion del cajero (POST /api/pos/autorizar)
 * y recibe un permiso para UNA accion sobre UN objeto, en ESE equipo, por
 * pocos minutos. La accion lo consume (cerrar un turno solo se puede una vez).
 *
 * Sin tabla: el permiso va firmado (PosPin::firma, con el secreto del server).
 * Formato: base64url(json) "." firma. El json lleva supervisor, accion, objeto,
 * equipo, tenant y vencimiento; cambiar cualquier cosa rompe la firma.
 */
final class PosAutorizacion
{
    /** Lo que dura un permiso: lo justo para contar la caja. */
    public const VIGENCIA_SEGUNDOS = 15 * 60;
    public const ACCIONES = ['cerrar_turno'];

    public static function emitir(array $supervisor, string $accion, int $objetoId, int $equipoId, int $tenantId): string
    {
        $datos = rtrim(strtr(base64_encode(json_encode([
            'sup' => (int) $supervisor['id'],
            'nom' => (string) $supervisor['nombre'],
            'acc' => $accion,
            'obj' => $objetoId,
            'eq' => $equipoId,
            'ten' => $tenantId,
            'exp' => time() + self::VIGENCIA_SEGUNDOS,
        ])), '+/', '-_'), '=');
        return $datos . '.' . PosPin::firma($datos);
    }

    /**
     * Verifica el permiso para esta accion, objeto, equipo y tenant.
     * @return array{id: int, nombre: string} el supervisor que autorizo
     */
    public static function verificar($permiso, string $accion, int $objetoId, int $equipoId, int $tenantId): array
    {
        $invalido = new PosError('La autorización del supervisor no vale para esto o ya venció. Pídela de nuevo.', 403, 'PERMISO_INVALIDO');
        if (!is_string($permiso) || substr_count($permiso, '.') !== 1) {
            throw $invalido;
        }
        [$datos, $firma] = explode('.', $permiso);
        if (!hash_equals(PosPin::firma($datos), $firma)) {
            throw $invalido;
        }
        $p = json_decode((string) base64_decode(strtr($datos, '-_', '+/')), true);
        if (!is_array($p) || ($p['acc'] ?? null) !== $accion || (int) ($p['obj'] ?? 0) !== $objetoId
            || (int) ($p['eq'] ?? 0) !== $equipoId || (int) ($p['ten'] ?? 0) !== $tenantId || (int) ($p['exp'] ?? 0) < time()) {
            throw $invalido;
        }
        return ['id' => (int) $p['sup'], 'nombre' => (string) ($p['nom'] ?? '')];
    }
}
