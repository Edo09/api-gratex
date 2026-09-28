<?php

/**
 * Fallo de la emision e-CF que el USUARIO de la app puede entender y resolver
 * (rango e-NCF agotado, emisor sin configurar, RNC del comprador, fechas...).
 *
 * El mensaje tecnico original sigue siendo getMessage(), sin cambios: los
 * integradores (integracion*Controller) y el audit log lo siguen recibiendo
 * igual, porque la clase hereda de RuntimeException y se lanza en el mismo
 * sitio. La app, en cambio, muestra getMensajeUsuario(): texto en espanol
 * llano, sin nombres de campos ni de tablas.
 *
 * Cualquier otra excepcion de la emision es un fallo interno: el controller de
 * la app muestra un texto generico y registra el detalle (error_log / audit).
 */
class EcfUsuarioException extends RuntimeException
{
    private string $mensajeUsuario;

    public function __construct(string $mensajeTecnico, string $mensajeUsuario, int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($mensajeTecnico, $code, $previous);
        $this->mensajeUsuario = $mensajeUsuario;
    }

    public function getMensajeUsuario(): string
    {
        return $this->mensajeUsuario;
    }
}
