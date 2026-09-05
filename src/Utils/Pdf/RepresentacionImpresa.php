<?php
require_once __DIR__ . '/ReciboPos80.php';

/**
 * Punto unico para pedir la Representacion Impresa de un comprobante en el
 * formato que quiera el cliente: la hoja carta de siempre o la tirilla POS de
 * 80 mm.
 *
 * Existe para que ningun controller tenga que saber que clase instanciar ni
 * como se deletrea el parametro. Los dos formatos dicen exactamente lo mismo
 * (EcfDocumento manda sobre el contenido); solo cambia el papel.
 */
final class RepresentacionImpresa
{
    /** Valores aceptados para pedir la tirilla. 'carta' (o nada) = hoja completa. */
    private const ALIAS_POS = ['pos', '80mm', '80', 'tirilla', 'termica'];

    /**
     * Formato pedido en la peticion. Se lee del query string y del cuerpo
     * (?formato=pos o {"formato":"pos"}) para que sirva igual en los GET de
     * descarga y en los POST de vista previa.
     */
    public static function esPos(array $body = []): bool
    {
        $v = strtolower(trim((string) ($_GET['formato'] ?? $body['formato'] ?? '')));
        return in_array($v, self::ALIAS_POS, true);
    }

    /**
     * @param array $factura       Fila de facturas + 'items' (+ 'xml_firmado').
     * @param array $cliente       Fila de clients (vacio = se resuelve por client_id).
     * @param bool  $noElectronica Factura simple / NCF tradicional.
     * @param bool  $pos           true = tirilla 80 mm; false = carta.
     * @return string Contenido del PDF.
     */
    public static function generar(array $factura, array $cliente = [], bool $noElectronica = false, bool $pos = false): string
    {
        if ($pos) {
            return ReciboPos80::paraFactura($factura, $cliente, $noElectronica)->generar();
        }

        require_once __DIR__ . '/../FacturaPdfGenerator.php';
        $pdf = new FacturaPdfGenerator('P', 'mm', 'Letter');
        $pdf->setNoElectronica($noElectronica);
        $pdf->setFactura($factura);
        if (!empty($cliente)) {
            $pdf->setClientData($cliente);
        }
        return $pdf->generatePdf();
    }

    /**
     * Sufijo del nombre de archivo. Sin el, descargar los dos formatos de la
     * misma factura deja dos archivos con el mismo nombre y el segundo pisa al
     * primero (o el navegador lo renombra a "(1)", que no dice cual es cual).
     */
    public static function sufijo(bool $pos): string
    {
        return $pos ? '_POS80' : '';
    }
}
