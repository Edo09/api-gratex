<?php
/**
 * Include FPDF library
 * Download FPDF from http://www.fpdf.org/ and place fpdf.php in vendor/fpdf/ directory
 * Or install via composer: composer require setasign/fpdf
 */
$fpdfPath = __DIR__ . '/../../vendor/fpdf/fpdf.php';
$composerPath = __DIR__ . '/../../vendor/autoload.php';

if (file_exists($composerPath)) {
    require_once($composerPath);
} elseif (file_exists($fpdfPath)) {
    require_once($fpdfPath);
} else {
    // Fallback: Try to download and use FPDF
    die('FPDF library not found. Please install via composer (composer require setasign/fpdf) or download from http://www.fpdf.org/');
}

/**
 * PDF Generator for Cotizaciones
 * Extends FPDF to create quotation PDFs with custom layout
 */
class CotizacionPdfGenerator extends FPDF
{
    private $cotizacion;
    private $widths;
    private $aligns;
    private $lineHeight;

    /**
     * Set the cotizacion data
     * @param array $cotizacion Cotizacion data
     */
    public function setCotizacion($cotizacion)
    {
        $this->cotizacion = $cotizacion;
    }

    /**
     * Convert UTF-8 string to ISO-8859-1 for FPDF compatibility
     * Replacement for deprecated utf8_decode()
     * @param string $string UTF-8 encoded string
     * @return string ISO-8859-1 encoded string
     */
    private function convertEncoding($string)
    {
        return mb_convert_encoding($string, 'ISO-8859-1', 'UTF-8');
    }

    /**
     * Set the array of column widths
     * @param array $w Array of widths
     */
    public function SetWidths($w)
    {
        $this->widths = $w;
    }

    /**
     * Set the array of column alignments
     * @param array $a Array of alignments
     */
    public function SetAligns($a)
    {
        $this->aligns = $a;
    }

    /**
     * Set line height
     * @param float $h Line height
     */
    public function SetLineHeight($h)
    {
        $this->lineHeight = $h;
    }

    /**
     * Calculate and render a row with multi-cell support
     * @param array $data Row data
     */
    public function Row($data)
    {
        $nb = 0;
        for ($i = 0; $i < count($data); $i++) {
            $nb = max($nb, $this->NbLines($this->widths[$i], $data[$i]));
        }

        $h = $this->lineHeight * $nb;
        $this->CheckPageBreak($h);

        for ($i = 0; $i < count($data); $i++) {
            $w = $this->widths[$i];
            $a = isset($this->aligns[$i]) ? $this->aligns[$i] : 'L';
            $x = $this->GetX();
            $y = $this->GetY();
            $this->MultiCell($w, $this->lineHeight, $data[$i], 0, $a);
            $this->SetXY($x + $w, $y);
        }
        $this->Ln($h);
    }

    /**
     * Check if page break is needed
     * @param float $h Height to check
     */
    public function CheckPageBreak($h)
    {
        if ($this->GetY() + $h > $this->PageBreakTrigger) {
            $this->AddPage($this->CurOrientation);
        }
    }

    /**
     * Calculate number of lines for text in given width
     * @param float $w Width
     * @param string $txt Text
     * @return int Number of lines
     */
    public function NbLines($w, $txt)
    {
        $cw = &$this->CurrentFont['cw'];
        if ($w == 0) {
            $w = $this->w - $this->rMargin - $this->x;
        }
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s = str_replace("\r", '', $txt);
        $nb = strlen($s);
        if ($nb > 0 && $s[$nb - 1] == "\n") {
            $nb--;
        }
        $sep = -1;
        $i = 0;
        $j = 0;
        $l = 0;
        $nl = 1;
        while ($i < $nb) {
            $c = $s[$i];
            if ($c == "\n") {
                $i++;
                $sep = -1;
                $j = $i;
                $l = 0;
                $nl++;
                continue;
            }
            if ($c == ' ') {
                $sep = $i;
            }
            if (isset($cw[$c])) {
                $l += $cw[$c];
            }
            if ($l > $wmax) {
                if ($sep == -1) {
                    if ($i == $j) {
                        $i++;
                    }
                } else {
                    $i = $sep + 1;
                }
                $sep = -1;
                $j = $i;
                $l = 0;
                $nl++;
            } else {
                $i++;
            }
        }
        return $nl;
    }

    /**
     * Page header
     */
    public function Header()
    {
        // Company Header
        $this->SetFont('Arial', 'B', 14);
        $this->Cell(0, 8, 'COTIZACION', 0, 1, 'C');
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 5, 'Quotation Document', 0, 1, 'C');
        $this->Ln(5);

        // Quotation info box
        $this->SetFont('Arial', 'B', 11);
        $this->SetFillColor(240, 240, 240);
        
        if ($this->cotizacion) {
            $this->Cell(95, 7, 'Cotizacion #' . $this->cotizacion['code'], 1, 0, 'L', true);
            $date = isset($this->cotizacion['date']) ? date('d/m/Y', strtotime($this->cotizacion['date'])) : date('d/m/Y');
            $this->Cell(95, 7, 'Fecha: ' . $date, 1, 1, 'R', true);
        }
        
        $this->Ln(5);
    }

    /**
     * Page footer
     */
    public function Footer()
    {
        $this->SetY(-30);
        
        // Signature lines
        $this->Line(20, $this->GetY(), 80, $this->GetY());
        $this->Line(130, $this->GetY(), 190, $this->GetY());
        
        $this->SetFont('Arial', '', 9);
        $this->Cell(95, 10, 'Firma Cliente', 0, 0, 'C');
        $this->Cell(95, 10, 'Firma Empresa', 0, 1, 'C');
        
        // Page number
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 10, 'Pagina ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
    }

    /**
     * Generate the PDF content
     * @return string PDF content as string
     */
    public function generatePdf()
    {
        $this->AliasNbPages();
        $this->AddPage();
        $this->SetMargins(10, 10, 10);

        // Client Information Section
        $this->SetFont('Arial', 'B', 10);
        $this->SetFillColor(51, 51, 51);
        $this->SetTextColor(255, 255, 255);
        $this->Cell(0, 7, 'INFORMACION DEL CLIENTE', 1, 1, 'L', true);
        
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Arial', '', 10);
        $this->SetFillColor(250, 250, 250);
        
        $this->Cell(40, 6, 'Cliente:', 1, 0, 'L', true);
        $this->Cell(150, 6, $this->convertEncoding($this->cotizacion['client'] ?? 'N/A'), 1, 1, 'L');
        
        $this->Ln(5);

        // Quotation Details Section
        $this->SetFont('Arial', 'B', 10);
        $this->SetFillColor(51, 51, 51);
        $this->SetTextColor(255, 255, 255);
        $this->Cell(30, 7, 'Codigo', 1, 0, 'C', true);
        $this->Cell(30, 7, 'Cantidad', 1, 0, 'C', true);
        $this->Cell(100, 7, 'Descripcion', 1, 0, 'C', true);
        $this->Cell(30, 7, 'Monto', 1, 1, 'C', true);

        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Arial', '', 10);

        // Data row
        $this->Cell(30, 6, $this->cotizacion['code'] ?? '', 1, 0, 'C');
        $this->Cell(30, 6, $this->cotizacion['amount'] ?? '0', 1, 0, 'C');
        
        // Description with multi-cell
        $x = $this->GetX();
        $y = $this->GetY();
        $this->MultiCell(100, 6, $this->convertEncoding($this->cotizacion['description'] ?? ''), 1, 'L');
        $descHeight = $this->GetY() - $y;
        
        $this->SetXY($x + 100, $y);
        $this->Cell(30, $descHeight > 6 ? $descHeight : 6, '$' . number_format($this->cotizacion['amount'] ?? 0, 2), 1, 1, 'R');

        $this->Ln(5);

        // Totals Section
        $this->SetX(130);
        $this->SetFont('Arial', 'B', 10);
        $this->SetFillColor(240, 240, 240);
        
        $subtotal = floatval($this->cotizacion['amount'] ?? 0);
        $itbis = $subtotal * 0.18;
        $total = $subtotal + $itbis;

        $this->Cell(30, 6, 'Subtotal:', 1, 0, 'L', true);
        $this->Cell(30, 6, '$' . number_format($subtotal, 2), 1, 1, 'R');
        
        $this->SetX(130);
        $this->Cell(30, 6, 'ITBIS (18%):', 1, 0, 'L', true);
        $this->Cell(30, 6, '$' . number_format($itbis, 2), 1, 1, 'R');
        
        $this->SetX(130);
        $this->SetFillColor(51, 51, 51);
        $this->SetTextColor(255, 255, 255);
        $this->Cell(30, 7, 'TOTAL:', 1, 0, 'L', true);
        $this->Cell(30, 7, '$' . number_format($total, 2), 1, 1, 'R', true);

        $this->SetTextColor(0, 0, 0);
        $this->Ln(10);

        // Terms and Conditions
        $this->SetFont('Arial', 'B', 9);
        $this->Cell(0, 5, 'Condiciones de Pago:', 0, 1, 'L');
        $this->SetFont('Arial', '', 9);
        $this->MultiCell(0, 4, $this->convertEncoding('60% al ordenar con orden de compra. 40% restante a la entrega del pedido.'), 0, 'L');
        
        $this->Ln(3);
        
        $this->SetFont('Arial', 'B', 9);
        $this->Cell(0, 5, 'Validez de la Cotizacion:', 0, 1, 'L');
        $this->SetFont('Arial', '', 9);
        $this->MultiCell(0, 4, 'Esta cotizacion tiene una validez de 30 dias a partir de la fecha de emision.', 0, 'L');

        return $this->Output('S');
    }

    /**
     * Output PDF with proper headers for download
     * @param string $filename Filename for download
     */
    public function outputForDownload($filename = 'cotizacion.pdf')
    {
        $this->Output('D', $filename);
    }

    /**
     * Output PDF inline (display in browser)
     * @param string $filename Filename
     */
    public function outputInline($filename = 'cotizacion.pdf')
    {
        $this->Output('I', $filename);
    }
}

/**
 * Helper function to generate PDF for a cotizacion
 * @param array $cotizacion Cotizacion data
 * @param string $output Output type: 'S' (string), 'D' (download), 'I' (inline)
 * @return string|void PDF content if output is 'S', otherwise outputs directly
 */
function generateCotizacionPdf($cotizacion, $output = 'S')
{
    $pdf = new CotizacionPdfGenerator('P', 'mm', 'Letter');
    $pdf->setCotizacion($cotizacion);
    
    switch ($output) {
        case 'D':
            $filename = 'Cotizacion_' . ($cotizacion['code'] ?? 'unknown') . '.pdf';
            $pdf->generatePdf();
            $pdf->outputForDownload($filename);
            break;
        case 'I':
            $filename = 'Cotizacion_' . ($cotizacion['code'] ?? 'unknown') . '.pdf';
            $pdf->generatePdf();
            $pdf->outputInline($filename);
            break;
        case 'S':
        default:
            return $pdf->generatePdf();
    }
}
