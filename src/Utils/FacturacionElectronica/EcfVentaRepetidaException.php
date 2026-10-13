<?php

/**
 * La clave de idempotencia de una venta del POS ya tiene factura: 1062 sobre
 * uk_facturas_pos_idem (migracion 030) al reservar. Solo la lanza
 * facturaModel::reservarFacturaECF, antes de enviar nada a la DGII.
 *
 * Es otra cosa que EcfNumeroOcupadoException: aqui el e-NCF recien dispensado
 * esta libre (ninguna fila lo ocupa) y el servicio lo devuelve a la secuencia;
 * lo que ya existe es la VENTA. PosVenta la relee por la clave y la responde
 * como repetida. No es EcfUsuarioException: nunca llega al cajero.
 */
class EcfVentaRepetidaException extends RuntimeException
{
    public string $clave;

    public function __construct(string $clave, ?Throwable $previous = null)
    {
        $this->clave = $clave;
        parent::__construct(sprintf('Venta repetida: la clave %s ya tiene factura', $clave), 0, $previous);
    }
}
