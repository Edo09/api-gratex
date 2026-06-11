<?php
require_once __DIR__ . '/FacturaTemplate.php';

/**
 * Plantilla "clasico": el diseno historico de Gratex, movido tal cual desde
 * FacturaPdfGenerator (Header/Footer/banda de tabla/totales). Es el default
 * de todos los tenants: sin acento configurado el resultado es identico al
 * anterior (banda negra, totales gris claro, sello + dos firmas).
 */
class ClasicoTemplate extends FacturaTemplate
{
    public function drawCompanyHeader($pdf, array $emisor, ?string $logoPath, string $variant = 'factura'): void
    {
        if ($variant === 'cotizacion') {
            $this->drawCotizacionHeader($pdf, $emisor, $logoPath);
            return;
        }
        // Logo: del tenant (logos/<tenant_id>.<ext>) o el global por defecto.
        if ($logoPath !== null) {
            $pdf->Image($logoPath, 8, 10, 65);
        }
        $pdf->SetFont('Arial', '', 9);
        $pdf->SetY(30);
        $pdf->MultiCell(70, 3.8, $this->enc($emisor['direccion']), 0, 'L');
        $pdf->Cell(70, 3.8, $this->enc('Tel.: ' . $emisor['telefono'] . ' - E-mail: ' . $emisor['correo']), 0, 1, 'L');
        $pdf->Cell(70, 3.8, 'RNC: ' . $emisor['rnc'], 0, 1, 'L');
    }

    /**
     * Disposicion historica de la cotizacion: logo a la izquierda, datos del
     * emisor alineados a la derecha en la franja superior. Solo cambia la
     * fuente de datos (emisor_config / logo del tenant), no el layout.
     */
    private function drawCotizacionHeader($pdf, array $emisor, ?string $logoPath): void
    {
        if ($logoPath !== null) {
            $pdf->Image($logoPath, 5, 10, 65);
        }
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetY(10);
        $pdf->SetX(-78);
        $pdf->MultiCell(70, 3.2, $this->enc($emisor['direccion']), 0, 'R');
        $pdf->SetX(-78);
        $pdf->Cell(70, 3.2, $this->enc('Tel.: ' . $emisor['telefono'] . ' - E-mail:' . $emisor['correo']), 0, 1, 'R');
        if (!empty($emisor['website'])) {
            $pdf->SetX(-78);
            $pdf->Cell(70, 3.2, $this->enc($emisor['website']), 0, 1, 'R');
        }
    }

    public function drawFooter($pdf): void
    {
        // Sello image
        $selloPath = __DIR__ . '/../../../sello.png';
        if (file_exists($selloPath)) {
            $pdf->Image($selloPath, 8, 246, 40);
        }
        // Firma empresa
        $pdf->Line(8, 259, 50, 259);
        $pdf->SetY(-20);
        $pdf->SetX(9);
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(38, 6, 'Firma y sello empresa', 0, 0, 'R');

        // Firma cliente
        $pdf->Line(80, 259, 125, 259);
        $pdf->SetY(-20);
        $pdf->SetX(81);
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(38, 6, 'Firma y sello cliente', 0, 0, 'R');
    }

    public function drawItemsTableHeader($pdf, array $widths, array $labels): void
    {
        $fill = $this->accentOr([0, 0, 0]);
        $text = $this->textOver($fill);
        $pdf->SetFont('Arial', '', 10);
        $pdf->SetFillColor($fill[0], $fill[1], $fill[2]);
        $pdf->SetTextColor($text[0], $text[1], $text[2]);
        foreach ($labels as $i => $label) {
            $pdf->Cell($widths[$i], 6, $label, 0, 0, 'C', 1);
        }
        $pdf->Ln(8);
    }

    public function drawTotals($pdf, array $filas): void
    {
        $pdf->SetFillColor(240, 240, 240);
        $pdf->SetTextColor(0, 0, 0);

        // Ancladas al pie: la fila 'Total' queda en Y=-40 y las demas se apilan
        // hacia arriba (5 mm c/u).
        $y = -40 - 5 * (count($filas) - 1);
        foreach ($filas as [$label, $valor, $bold]) {
            $pdf->SetFont('Arial', $bold ? 'B' : '', $bold ? 9.5 : 9);
            $pdf->SetY($y);
            $pdf->SetX(-58);
            $pdf->Cell(28, 5, $this->enc($label), 1, 0, 'R', 1);
            $pdf->Cell(20, 5, number_format($valor, 2), 1, 1, 'R', 1);
            $y += 5;
        }
    }
}
