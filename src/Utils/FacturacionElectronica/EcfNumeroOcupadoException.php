<?php

require_once __DIR__ . '/EcfUsuarioException.php';

/**
 * El e-NCF que se iba a reservar ya tiene dueno en ese ambiente: una fila que
 * no es reintentable, una clave duplicada (1062) o un segundo deadlock (1213)
 * al reservar. Solo la lanza facturaModel::reservarFacturaECF, antes de enviar
 * nada a la DGII.
 *
 * Como una fila ocupa el numero, la emision NO devuelve el contador: es el
 * mismo efecto que el salto de eNcfYaUsado, y el siguiente intento toma el
 * numero que sigue. La app muestra getMensajeUsuario() en el 502.
 */
class EcfNumeroOcupadoException extends EcfUsuarioException
{
    public const MENSAJE_USUARIO = 'No se pudo asignar el número de comprobante. Inténtalo de nuevo.';

    public function __construct(string $eNcf, string $ambiente, string $causa, ?Throwable $previous = null)
    {
        parent::__construct(
            sprintf('e-NCF %s ocupado en ambiente %s: %s', $eNcf, $ambiente, $causa),
            self::MENSAJE_USUARIO,
            0,
            $previous
        );
    }
}
