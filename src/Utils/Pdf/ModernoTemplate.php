<?php
require_once __DIR__ . '/FacturaTemplate.php';

/**
 * Plantilla "moderno": banda de acento a todo lo ancho en el encabezado
 * (logo a la izquierda sobre recuadro blanco, contacto del emisor alineado a
 * la derecha en texto de contraste), banda de tabla y fila Total en acento,
 * pie minimalista con regla fina (sin sello).
 *
 * Acento por defecto: azul oscuro corporativo. Con tenants.pdf_accent_color
 * la banda y los rellenos usan ese color y contrastText() decide el texto.
 */
class ModernoTemplate extends FacturaTemplate
{
    private const DEFAULT_ACCENT = [26, 54, 93]; // azul oscuro

    private function accent(): array
    {
        return $this->accentOr(self::DEFAULT_ACCENT);
    }

    public function drawCompanyHeader($pdf, array $emisor, ?string $logoPath, string $variant = 'factura'): void
    {
        $accent = $this->accent();
        $text = $this->textOver($accent);

        // Banda superior a todo lo ancho (pagina Letter: 215.9 mm).
        $pdf->SetFillColor($accent[0], $accent[1], $accent[2]);
        $pdf->Rect(0, 0, 215.9, 26, 'F');

        // Logo sobre recuadro blanco dentro de la banda (caja 50x16 mm).
        if ($logoPath !== null) {
            $pdf->SetFillColor(255, 255, 255);
            $pdf->Rect(6, 3, 54, 20, 'F');
            $this->drawLogo($pdf, $logoPath, 8, 5, 50, 16);
        }

        // Contacto del emisor a la derecha, en texto de contraste sobre la banda.
        $pdf->SetTextColor($text[0], $text[1], $text[2]);
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetXY(-110, 4);
        $pdf->Cell(102, 4.5, $this->enc($emisor['razon_social']), 0, 1, 'R');
        $pdf->SetFont('Arial', '', 8);
        $pdf->SetX(-110);
        $pdf->MultiCell(102, 3.6, $this->enc($emisor['direccion']), 0, 'R');
        $pdf->SetX(-110);
        $pdf->Cell(102, 3.6, $this->enc('Tel.: ' . $emisor['telefono'] . ' - ' . $emisor['correo']), 0, 1, 'R');
        $pdf->SetX(-110);
        $pdf->Cell(102, 3.6, 'RNC: ' . $emisor['rnc'], 0, 1, 'R');

        // Regla fina de acento bajo la banda.
        $pdf->SetDrawColor($accent[0], $accent[1], $accent[2]);
        $pdf->SetLineWidth(0.6);
        $pdf->Line(8, 28, 207.9, 28);
        $pdf->SetLineWidth(0.2);
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetTextColor(0, 0, 0);
        // Cursor SIEMPRE bajo la banda: en paginas de continuacion las filas
        // retoman donde el Header dejo la Y (si quedara dentro de la banda,
        // la primera fila se imprimiria encima).
        $pdf->SetY(31);
    }

    public function drawFooter($pdf): void
    {
        $accent = $this->accent();
        // Regla fina + lineas de firma, sin sello.
        $pdf->SetDrawColor($accent[0], $accent[1], $accent[2]);
        $pdf->SetLineWidth(0.4);
        $pdf->Line(8, 244, 207.9, 244);
        $pdf->SetLineWidth(0.2);
        $pdf->SetDrawColor(0, 0, 0);

        $pdf->Line(8, 259, 50, 259);
        $pdf->SetY(-20);
        $pdf->SetX(9);
        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell(38, 6, 'Firma y sello empresa', 0, 0, 'R');

        $pdf->Line(80, 259, 125, 259);
        $pdf->SetY(-20);
        $pdf->SetX(81);
        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell(38, 6, 'Firma y sello cliente', 0, 0, 'R');
    }

    public function drawItemsTableHeader($pdf, array $widths, array $labels): void
    {
        $accent = $this->accent();
        $text = $this->textOver($accent);
        $pdf->SetFont('Arial', 'B', 9.5);
        $pdf->SetFillColor($accent[0], $accent[1], $accent[2]);
        $pdf->SetTextColor($text[0], $text[1], $text[2]);
        foreach ($labels as $i => $label) {
            $pdf->Cell($widths[$i], 6.5, $label, 0, 0, 'C', 1);
        }
        $pdf->Ln(8.5);
    }

    public function drawTotals($pdf, array $filas): void
    {
        $accent = $this->accent();
        $accentText = $this->textOver($accent);

        $y = -40 - 5 * (count($filas) - 1);
        foreach ($filas as [$label, $valor, $bold]) {
            if ($bold) {
                // Fila Total resaltada con el acento.
                $pdf->SetFillColor($accent[0], $accent[1], $accent[2]);
                $pdf->SetTextColor($accentText[0], $accentText[1], $accentText[2]);
                $pdf->SetFont('Arial', 'B', 9.5);
            } else {
                $pdf->SetFillColor(245, 245, 245);
                $pdf->SetTextColor(0, 0, 0);
                $pdf->SetFont('Arial', '', 9);
            }
            $pdf->SetY($y);
            $pdf->SetX(-58);
            $pdf->Cell(28, 5, $this->enc($label), 0, 0, 'R', 1);
            $pdf->Cell(20, 5, number_format($valor, 2), 0, 1, 'R', 1);
            $y += 5;
        }
        $pdf->SetTextColor(0, 0, 0);
    }

    public function style(): array
    {
        return array_merge(parent::style(), [
            'title_font_size' => 12,
        ]);
    }

    public function layout(): array
    {
        return array_merge(parent::layout(), [
            // Bajo la banda de acento (0-26 mm) + regla (28 mm).
            'doc_id_y'      => 32,
            'table_start_y' => 62,
        ]);
    }
}
