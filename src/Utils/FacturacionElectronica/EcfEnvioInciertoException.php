<?php

/**
 * La fila del e-CF ya estaba guardada (PENDIENTE_ENVIO) y el envio a la DGII o
 * la confirmacion fallo: no se sabe si la DGII lo tiene. Solo la lanza
 * ECFEmissionService::emitir cuando hay hook de persistencia y una reserva;
 * integracion y gastos siguen recibiendo la excepcion original.
 *
 * El controller responde 502 con data.factura_id para llevar al usuario a la
 * fila. La app nunca reenvia el e-CF (decision 7): lo resuelve soporte.
 */
class EcfEnvioInciertoException extends RuntimeException
{
    public readonly string $eNcf;
    public readonly int $facturaId;
    public readonly string $ambiente;
    /**
     * 'envio' (fallo el envio), 'confirmar' (la DGII respondio y fallo el UPDATE)
     * o 'autenticar' (solo el POS, que pide el token DESPUES de reservar: fallo el
     * token y nada salio hacia la DGII).
     */
    public readonly string $faseFallida;
    /** Proyeccion sin XML de la respuesta (las 8 claves de proyectarResultado) o null. */
    public readonly ?array $resultadoParcial;
    /** true si 'incierto' dejo la fila en ENVIO_INCIERTO; false si quedo en PENDIENTE_ENVIO. */
    public readonly bool $marcado;

    public function __construct(
        string $eNcf,
        int $facturaId,
        string $ambiente,
        string $faseFallida,
        ?array $resultadoParcial,
        bool $marcado,
        ?Throwable $previous = null
    ) {
        $this->eNcf = $eNcf;
        $this->facturaId = $facturaId;
        $this->ambiente = $ambiente;
        $this->faseFallida = $faseFallida;
        $this->resultadoParcial = $resultadoParcial;
        $this->marcado = $marcado;
        parent::__construct(
            sprintf('Envio a la DGII sin confirmar: e-NCF %s, factura_id %d, fase %s', $eNcf, $facturaId, $faseFallida),
            0,
            $previous
        );
    }
}
