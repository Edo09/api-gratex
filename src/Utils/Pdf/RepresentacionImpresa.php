<?php
require_once __DIR__ . '/ReciboPos.php';

/**
 * Punto unico para pedir la Representacion Impresa de un comprobante en el
 * formato que quiera el cliente: la hoja carta de siempre o la tirilla POS de
 * 80, 76 o 72 mm.
 *
 * Existe para que ningun controller tenga que saber que clase instanciar ni
 * como se deletrea el parametro. Todos los formatos dicen exactamente lo mismo
 * (EcfDocumento manda sobre el contenido); solo cambia el papel.
 */
final class RepresentacionImpresa
{
    /**
     * Piden la tirilla sin decir el ancho: son los valores de antes de que
     * hubiera varios anchos, y siguen dando la de 80 mm.
     */
    private const ALIAS_POS = ['pos', 'tirilla', 'termica'];

    /**
     * Ancho de tirilla pedido en la peticion, o null para la hoja carta. Se lee
     * del query string y del cuerpo (?formato=pos76 o {"formato":"pos76"}) para
     * que sirva igual en los GET de descarga y en los POST de vista previa.
     */
    public static function anchoPos(array $body = []): ?int
    {
        return self::interpretarFormato((string) ($_GET['formato'] ?? $body['formato'] ?? ''));
    }

    /**
     * 'pos', 'tirilla', 'termica'          -> 80 (el de siempre)
     * 'pos80', '80mm', '80' (y 76, 72)     -> ese ancho
     * cualquier otro valor, o ninguno      -> null (hoja carta)
     *
     * Un ancho que no existe ('pos58') tambien es carta: es el contrato de
     * siempre para un formato desconocido.
     */
    public static function interpretarFormato(string $formato): ?int
    {
        $v = strtolower(trim($formato));
        if (in_array($v, self::ALIAS_POS, true)) {
            return ReciboPos::ANCHO_POR_DEFECTO;
        }
        if (preg_match('/^(?:pos)?(\d{2})(?:mm)?$/', $v, $m) && ReciboPos::anchoValido((int) $m[1])) {
            return (int) $m[1];
        }
        return null;
    }

    /**
     * @param array    $factura       Fila de facturas + 'items' (+ 'xml_firmado').
     * @param array    $cliente       Fila de clients (vacio = se resuelve por client_id).
     * @param bool     $noElectronica Factura simple / NCF tradicional.
     * @param int|null $anchoPos      Ancho de la tirilla en mm; null = carta.
     * @return string Contenido del PDF.
     */
    public static function generar(array $factura, array $cliente = [], bool $noElectronica = false, ?int $anchoPos = null): string
    {
        if ($anchoPos !== null) {
            return ReciboPos::paraFactura($factura, $cliente, $noElectronica, $anchoPos)->generar();
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
     * Datos del recibo de tirilla para imprimirlo como pagina web
     * (?format=datos en los endpoints de PDF). Con un PDF, el largo del papel
     * lo decide el tamano elegido en el driver; una pagina web le dice al
     * navegador el largo exacto. Mismos textos que el PDF: ReciboPos::datos().
     *
     * @param string $nombre Nombre del documento sin extension (titulo de la pagina).
     */
    public static function datosRecibo(array $factura, array $cliente, bool $noElectronica, int $anchoPos, string $nombre): array
    {
        return ['nombre' => $nombre] + ReciboPos::paraFactura($factura, $cliente, $noElectronica, $anchoPos)->datos();
    }

    /**
     * Sufijo del nombre de archivo (_POS80, _POS76, _POS72). Sin el, descargar
     * dos formatos de la misma factura deja dos archivos con el mismo nombre y
     * el segundo pisa al primero (o el navegador lo renombra a "(1)", que no
     * dice cual es cual).
     */
    public static function sufijo(?int $anchoPos): string
    {
        return $anchoPos === null ? '' : '_POS' . $anchoPos;
    }
}
