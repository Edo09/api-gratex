<?php
require_once __DIR__ . '/FacturaTemplate.php';

/**
 * Plantilla "compacto": logo reducido (45 mm), datos del emisor condensados
 * en dos lineas (Arial Narrow 8pt si la fuente vendorizada existe), cuerpo a
 * 8.5pt con interlineado 3.2 — caben mas lineas por pagina. Pie minimo: una
 * sola linea combinada de firmas, sin sello. Totales en escala de grises con
 * el Total en negrita (y acento en la banda de tabla si esta configurado).
 */
class CompactoTemplate extends FacturaTemplate
{
    /** Carga defensiva de Arial Narrow (vendor/fpdf/font/arial-narrow.php). */
    private function narrowFont($pdf): string
    {
        static $loaded = null;
        if ($loaded === null) {
            $loaded = false;
            $fontFile = __DIR__ . '/../../../vendor/fpdf/font/arial-narrow.php';
            if (is_file($fontFile)) {
                try {
                    $pdf->AddFont('Arial-narrow', '', 'arial-narrow.php');
                    $loaded = true;
                } catch (\Throwable $e) {
                    $loaded = false;
                }
            }
        }
        return $loaded ? 'Arial-narrow' : 'Arial';
    }

    public function drawCompanyHeader($pdf, array $emisor, ?string $logoPath, string $variant = 'factura'): void
    {
        if ($logoPath !== null) {
            $pdf->Image($logoPath, 8, 8, 45);
        }
        $font = $this->narrowFont($pdf);
        $pdf->SetFont($font, '', 8);
        $pdf->SetY(24);
        // Dos lineas condensadas: direccion / contacto + RNC.
        $pdf->MultiCell(95, 3.2, $this->enc($emisor['direccion']), 0, 'L');
        $pdf->Cell(95, 3.2, $this->enc('Tel.: ' . $emisor['telefono'] . ' - ' . $emisor['correo'] . ' - RNC: ' . $emisor['rnc']), 0, 1, 'L');
    }

    public function drawFooter($pdf): void
    {
        // Pie minimo: una sola linea combinada de firmas, sin sello.
        $pdf->Line(8, 259, 60, 259);
        $pdf->Line(120, 259, 172, 259);
        $pdf->SetY(-20);
        $pdf->SetX(9);
        $pdf->SetFont('Arial', '', 8);
        $pdf->Cell(50, 5, 'Firma y sello empresa', 0, 0, 'C');
        $pdf->SetX(121);
        $pdf->Cell(50, 5, 'Firma y sello cliente', 0, 0, 'C');
    }

    public function drawItemsTableHeader($pdf, array $widths, array $labels): void
    {
        $fill = $this->accentOr([60, 60, 60]);
        $text = $this->textOver($fill);
        $pdf->SetFont('Arial', 'B', 8.5);
        $pdf->SetFillColor($fill[0], $fill[1], $fill[2]);
        $pdf->SetTextColor($text[0], $text[1], $text[2]);
        foreach ($labels as $i => $label) {
            $pdf->Cell($widths[$i], 5, $label, 0, 0, 'C', 1);
        }
        $pdf->Ln(6.5);
    }

    public function drawTotals($pdf, array $filas): void
    {
        $pdf->SetTextColor(0, 0, 0);
        $y = -40 - 5 * (count($filas) - 1);
        foreach ($filas as [$label, $valor, $bold]) {
            $pdf->SetFillColor($bold ? 225 : 245, $bold ? 225 : 245, $bold ? 225 : 245);
            $pdf->SetFont('Arial', $bold ? 'B' : '', $bold ? 9 : 8.5);
            $pdf->SetY($y);
            $pdf->SetX(-58);
            $pdf->Cell(28, 5, $this->enc($label), 1, 0, 'R', 1);
            $pdf->Cell(20, 5, number_format($valor, 2), 1, 1, 'R', 1);
            $y += 5;
        }
    }

    public function style(): array
    {
        return array_merge(parent::style(), [
            'body_font_size'  => 8.5,
            'line_height'     => 3.2,
            'title_font_size' => 10,
        ]);
    }

    public function layout(): array
    {
        return array_merge(parent::layout(), [
            'doc_id_y'      => 8,
            'table_start_y' => 48,
        ]);
    }
}
