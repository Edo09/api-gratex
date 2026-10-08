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

    /**
     * Mensaje de un fallo apto para quien llama desde FUERA (integradores, y las
     * URLs publicas de la DGII). Conserva lo que le sirve a un desarrollador (el
     * campo que fallo, la respuesta de la DGII) y tapa lo que es nuestro: rutas
     * del servidor (p.ej. la del .p12 en "No se puede leer el certificado: ...")
     * y nombres de variables de configuracion (DGII_ECF_CERT_PATH...). El detalle
     * completo sigue yendo al error_log y a la bitacora.
     */
    public static function mensajePublico(Throwable $e): string
    {
        $m = $e->getMessage();
        // Rutas absolutas: Windows (C:\...) o Unix con al menos un directorio. El
        // lookbehind deja pasar URLs (https://...) y textos como "E31/E32".
        $m = (string) preg_replace('#(?<![\w:/.])(?:[A-Za-z]:\\\\[^\s\'"]+|/(?:[\w.\-]+/)+[\w.\-]*)#', '[ruta del servidor]', $m);
        $m = (string) preg_replace(
            '/\b(?:DGII_ECF|DB|MASTER|OPENSSL|SMTP|MAIL|ONBOARD|CERT_RUN|READLOG|ENCRYPT|MULTI_TENANT|PERMISSIONS)_[A-Z0-9_]+\b/',
            '[configuración]',
            $m
        );
        return trim($m) !== '' ? $m : 'Error interno.';
    }
}
