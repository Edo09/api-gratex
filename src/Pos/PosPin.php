<?php
require_once __DIR__ . '/PosError.php';

/**
 * PIN de los empleados del POS (docs/specs/pos.md A5, A6 y §9.2).
 *
 * - Lo genera el sistema: 4 digitos al azar, unico en el tenant. El admin nunca
 *   lo elige, asi que nunca hay que responder "ese PIN ya existe" (eso le
 *   revelaria el PIN de otro empleado). Se muestra UNA vez, al crearlo o
 *   regenerarlo, y no se puede volver a leer.
 * - Se guarda HMAC-SHA256(pin, POS_PIN_PEPPER | tenant). Con el secreto del
 *   server se busca al empleado por PIN en una consulta (UNIQUE en la DB), y un
 *   volcado de la DB sin el secreto no sirve para probar los 10^4 PINs. El
 *   tenant va en la clave: el mismo PIN da hashes distintos en cada empresa.
 *
 * 4 digitos y no 6 (decision del 2026-10-08, rapidez en mostrador): con 10^4
 * combinaciones y "solo PIN" (cualquier PIN de cualquier empleado entra), lo
 * que frena a quien prueba al azar es el bloqueo PROGRESIVO por equipo
 * (posMasterModel::registrarFalloPin): 5 fallos -> 5 min, luego 10, 20...
 */
final class PosPin
{
    public const DIGITOS = 4;

    /** Largo minimo del secreto: 32 caracteres (p. ej. bin2hex(random_bytes(16))). */
    private const PEPPER_MIN = 32;

    public static function hmac(string $pin, int $tenantId): string
    {
        return hash_hmac('sha256', $pin, self::pepper() . '|' . $tenantId);
    }

    /**
     * Firma de los permisos de supervisor (PosAutorizacion). Misma raiz secreta
     * que los PIN, con otro proposito en la clave: una firma de permiso no sirve
     * como HMAC de PIN ni al reves.
     */
    public static function firma(string $datos): string
    {
        return hash_hmac('sha256', $datos, 'autorizacion|' . self::pepper());
    }

    /** PIN nuevo al azar, sin los obvios (todos iguales o en escalera). */
    public static function generar(): string
    {
        do {
            $pin = str_pad((string) random_int(0, 10 ** self::DIGITOS - 1), self::DIGITOS, '0', STR_PAD_LEFT);
        } while (self::esObvio($pin));
        return $pin;
    }

    /** 0000, 7777, 1234, 4321: los primeros que alguien prueba. */
    public static function esObvio(string $pin): bool
    {
        if (preg_match('/^(\d)\1+$/', $pin) === 1) {
            return true;
        }
        $sube = true;
        $baja = true;
        for ($i = 1, $n = strlen($pin); $i < $n; $i++) {
            $paso = (int) $pin[$i] - (int) $pin[$i - 1];
            $sube = $sube && $paso === 1;
            $baja = $baja && $paso === -1;
        }
        return $sube || $baja;
    }

    /** Lo que escribio el empleado: exactamente DIGITOS digitos, nada mas. */
    public static function formatoValido($pin): bool
    {
        return is_string($pin) && preg_match('/^\d{' . self::DIGITOS . '}$/', $pin) === 1;
    }

    /**
     * Secreto del server (.env). Sin el no se puede crear ni verificar ningun PIN:
     * se corta con un error de configuracion en vez de guardar un hash debil.
     */
    private static function pepper(): string
    {
        $p = (string) (getenv('POS_PIN_PEPPER') ?: ($_ENV['POS_PIN_PEPPER'] ?? ''));
        if (strlen($p) < self::PEPPER_MIN) {
            throw new PosError(
                'Falta configurar el POS en el servidor (POS_PIN_PEPPER). Avisa a soporte.',
                500,
                'POS_SIN_CONFIGURAR'
            );
        }
        return $p;
    }
}
