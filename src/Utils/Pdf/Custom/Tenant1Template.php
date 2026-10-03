<?php
require_once __DIR__ . '/../ClasicoTemplate.php';

/**
 * Plantilla de GRATEX (tenant 1, 'custom:tenant1').
 *
 * Es la clasica tal cual mas el aviso de la fecha limite de pago a la derecha
 * de las firmas. El aviso es politica de cobro de Gratex, no de la norma DGII:
 * por eso vive aqui y no en ClasicoTemplate, que es la de todos los tenants.
 *
 * Activar:  UPDATE tenants SET pdf_template = 'custom:tenant1' WHERE id = 1;
 *           (o PUT /api/branding {"template":"custom:tenant1"}).
 */
class Tenant1Template extends ClasicoTemplate
{
    /**
     * Comprobantes donde el aviso no aplica: la nota de credito no se cobra, y
     * en E41/E43/E47 Gratex es quien paga (el "cliente" es su proveedor).
     */
    private const SIN_AVISO_DE_PAGO = ['34', '41', '43', '47'];

    public function drawFooter($pdf): void
    {
        parent::drawFooter($pdf);

        $doc = $this->documento();
        if ($doc === null || in_array($doc->tipoEcf(), self::SIN_AVISO_DE_PAGO, true)) {
            return;
        }

        // Entre la firma del cliente (termina en x=125) y el margen derecho, a
        // la altura de las firmas. La tercera linea cierra en y=271.4, donde el
        // motor estampa "Pagina X de Y" (centrada, no se cruzan).
        $lineas = [
            'Apreciado cliente: La fecha límite del pago de',
            'la presente factura es para el ' . $doc->fechaLimitePago() . '.',
            'Agradeceremos tener pendiente esta información.',
        ];
        $pdf->SetFont('Arial', '', 9);
        $y = 260.6;
        foreach ($lineas as $linea) {
            $pdf->SetXY(134, $y);
            $pdf->Cell(72, 3.6, $this->enc($linea), 0, 0, 'L');
            $y += 3.6;
        }
    }
}
