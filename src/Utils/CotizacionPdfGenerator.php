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
    /**
     * Print a table row with multi-cell support
     * @param array $data Array of cell values
     */
    public function Row($data)
    {
        $nb = 0;
        $mockValues = ['N/A', 'Sample', '---', 'No Data', 'Test'];
        for ($i = 0; $i < count($data); $i++) {
            // If null, use mock value (cycle through mockValues for variety)
            if ($data[$i] === null || $data[$i] === '') {
                $data[$i] = $mockValues[$i % count($mockValues)];
            }
            $width = (isset($this->widths) && isset($this->widths[$i])) ? $this->widths[$i] : 40;
            $nb = max($nb, $this->NbLines($width, $data[$i]));
        }
        $h = $this->lineHeight * $nb;
        $this->CheckPageBreak($h);
        for ($i = 0; $i < count($data); $i++) {
            $w = (isset($this->widths) && isset($this->widths[$i])) ? $this->widths[$i] : 40;
            $a = isset($this->aligns[$i]) ? $this->aligns[$i] : 'L';
            $x = $this->GetX();
            $y = $this->GetY();
            $this->MultiCell($w, $this->lineHeight, $data[$i], 0, $a);
            $this->SetXY($x + $w, $y);
        }
        $this->Ln($h);
    }
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
        // Custom Gratex Header (logo, address, etc.)
        // Add custom fonts if available
        if (method_exists($this, 'AddFont')) {
            // Uncomment if you have these fonts available
            $this->AddFont('Arial-narrow', '', 'arial-narrow.php');
            $this->AddFont('Arial-narrow-bold', '', 'arial-narrow-bold.php');
        }
        // Logo (adjust path as needed)
        $logoPath = __DIR__ . '/../../logo2020.png';
        if (file_exists($logoPath)) {
            $this->Image($logoPath, 5, 10, 65); // X, Y, Width
        }
        $this->SetFont('Arial', 'B', 8);
        $this->SetY(10);
        $this->SetX(-78);
        $this->Cell(70, 3.2, $this->convertEncoding('Calle José Nicolás Casimiro #85'), 0, 1, 'R');
        $this->SetX(-78);
        $this->Cell(70, 3.2, 'Ensanche Espaillat, Santo Domingo, D.N.', 0, 1, 'R');
        $this->SetX(-78);
        $this->Cell(70, 3.2, $this->convertEncoding('Tel.: 809-681-5141 - E-mail:info@gratex.net'), 0, 1, 'R');
        $this->SetX(-78);
        $this->Cell(70, 3.2, 'www.gratex.net', 0, 1, 'R');

        $this->SetY(30);
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(30, 4, $this->convertEncoding('Cotización/Factura Proforma'), 0, 1, 'L');
        $this->Ln(1);
        $this->SetFont('Arial', '', 14);
        $this->Cell(22, 5, '#' . ($this->cotizacion['code'] ?? ''), 0, 1, 'L');
        $this->Ln(1);
        $this->SetFont('Arial', '', 10);
        $this->MultiCell(200, 4, $this->convertEncoding('Esta cotización/factura proforma es para uso provisional. Al momento de la entrega del pedido se emitirá una factura válida para crédito fiscal.'), 0, 'L');
        $this->Ln(5);
    }

    /**
     * Page footer
     */
    public function Footer()
    {
        // Firmas
        $this->Line(15, 268, 70, 268);
        $this->SetY(-10);
        $this->SetX(40);
        $this->SetFont('Arial-narrow', '', 9);
        $this->Cell(15, 6, 'Firma y sello cliente', 0, 0, 'R');

        $this->Line(145, 268, 200, 268);
        $this->SetY(-10);
        $this->SetX(-40);
        // Sello image (adjust path as needed)
        $selloPath = __DIR__ . '/../../sello.png';
        if (file_exists($selloPath)) {
            $this->Image($selloPath, 145, 252, -400);
        }
        $this->SetFont('Arial-narrow', '', 9);
        $this->Cell(15, 6, 'Firma y sello empresa', 0, 0, 'R');
    }

    /**
     * Generate the PDF content
     * @return string PDF content as string
     */
    public function generatePdf()
    {
        $this->AliasNbPages();
        $this->SetMargins(5, 10, 5);
        $this->AddPage();

        // Fetch client data from DB if client_id is present
        $contacto = '';
        $telefono = '';
        $email = '';
        if (!empty($this->cotizacion['client_id'])) {
            try {
                $db = Database::getInstance()->getConnection();
                $stmt = $db->prepare('SELECT client_name, email, phone_number,company_name FROM clients WHERE id = :id LIMIT 1');
                $stmt->execute([':id' => $this->cotizacion['client_id']]);
                $clientRow = $stmt->fetch(\PDO::FETCH_ASSOC);
                if ($clientRow) {
                    $contacto = $clientRow['client_name'];
                    $email = $clientRow['email'];
                    $telefono = $clientRow['phone_number'];
                    $cliente = $clientRow['company_name'];
                }
            } catch (\Exception $e) {
                // fallback to whatever is in cotizacion
            }
        }

        $fulltelandcel = trim('Tel.: ' . $telefono);
        $condiciones = "+ 60% al ordenar\n+ orden de compra\n+ 40% a la entrega";

        // Client info section styled as a table row (like products)
        $this->SetFont('Arial', 'B', 10);
        $this->SetFillColor(0, 0, 0);
        $this->SetTextColor(255, 255, 255);
        $this->Cell(45, 6, 'Cliente', 0, 0, 'L', 1);
        $this->Cell(40, 6, 'Telefono/Celular', 0, 0, 'L', 1);
        $this->Cell(50, 6, 'Correo', 0, 0, 'L', 1);
        $this->Cell(30, 6, 'Contacto', 0, 0, 'L', 1);
        $this->Cell(40, 6, 'Condiciones de Pago', 0, 1, 'L', 1);
        $this->SetFont('Arial', '', 10);
        $this->SetTextColor(0, 0, 0);
        $this->Cell(45, 7, $this->convertEncoding($cliente), 0, 0, 'L');
        $this->Cell(40, 7, $this->convertEncoding($fulltelandcel), 0, 0, 'L');
        $this->Cell(50, 7, $this->convertEncoding($email), 0, 0, 'L');
        $this->Cell(30, 7, $this->convertEncoding($contacto), 0, 0, 'L');
        // Use MultiCell for Condiciones de Pago so each line is stacked
        $x = $this->GetX();
        $y = $this->GetY();
        $this->SetXY($x, $y);
        $this->MultiCell(40, 5, $this->convertEncoding($condiciones), 0, 'L');
        $this->Ln(2);

        // Second header row: Fecha, Cliente, Cliente#, Cantidad, Descripcion Producto, ITBIS, Valor Unit
        $this->SetFont('Arial', 'B', 10);
        $this->SetFillColor(0, 0, 0);
        $this->SetTextColor(255, 255, 255);
        $this->Cell(25, 6, 'Fecha', 0, 0, 'L', 1);
        $this->Cell(25, 6, 'Cantidad', 0, 0, 'L', 1);
        $this->Cell(105, 6, 'Descripcion Producto', 0, 0, 'L', 1);
        $this->Cell(25, 6, 'ITBIS', 0, 0, 'L', 1);
        $this->Cell(25, 6, 'Valor Unit', 0, 0, 'L', 1);
        $this->Ln(7);

        $this->SetWidths([25, 25, 105, 25, 25]);
        $this->SetLineHeight(4.5);
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Arial', '', 11);

        $fecha = isset($this->cotizacion['date']) ? date('d/m/Y', strtotime($this->cotizacion['date'])) : date('d/m/Y');
        $codcliente = $this->cotizacion['client_id'] ?? '';

        $subtotal = 0;
        if (isset($this->cotizacion['items']) && is_array($this->cotizacion['items'])) {
            foreach ($this->cotizacion['items'] as $item) {
                $cantidad = $item['quantity'] ?? 1;
                $descripcion = $item['description'] ?? '';
                $unitario = $item['amount'] ?? 0;
                $itbis = $unitario * 0.18;
                $subtotal += $cantidad * $unitario;
                $this->Row([
                    $fecha,

                    $cantidad,
                    $this->convertEncoding(html_entity_decode($descripcion)),
                    '$' . number_format($itbis, 2),
                    '$' . number_format($unitario, 2)
                ]);
            }
        }
        $itbistotal = $subtotal * 0.18;

        // Subtotal, Descuento, ITBIS, Total
        $this->SetFont('Arial', 'B', 9);
        $this->SetY(-90);
        $this->Cell(31, 4, 'Condiciones de pago', 0, 1, 'L', 0);
        $this->SetFont('Arial', '', 9);
        $this->MultiCell(144, 4, $this->convertEncoding('Persona Jurídica (empresa) 60% avance del total de la Cotización/Factura Proforma y/o envío de una orden de compra/carta constancia firmada y sellada. Restante 40% será pagado al momento de la entrega del pedido. Personas Físicas deben hacer pago por adelantado.'), 0, 'L');
        $this->Ln(7);
        $this->SetFont('Arial', 'B', 9);
        $this->Cell(31, 4, 'Forma y constancias de pago', 0, 1, 'L', 0);
        $this->SetFont('Arial', '', 9);
        $this->MultiCell(144, 4, $this->convertEncoding('Pagos vía transferencia electrónica o con depósito a la cuenta corriente #790371603 a nombre de Gratex EIRL en el Banco Popular Dominicano. Constancia del pago debe ser enviada al e-mail pagoenlinea@gratex.net o whatsapp 849-401-1017.'), 0, 'L');

        $this->SetY(-92);
        // $this->SetX(7);
        $this->Cell(144, 20, '', 1);
        $this->SetY(-69);
        // $this->SetX(7);
        $this->Cell(144, 20, '', 1);

        // Subtotal
        $this->SetY(-92);
        $this->SetX(-53);
        $this->SetFont('Arial', 'B', 11);
        $this->Cell(25, 5, 'Sub Total', 0, 0, 'R', 0);
        $this->SetFillColor(240, 240, 240);
        $this->SetFont('Arial', '', 11);
        $this->Cell(20, 7, number_format($subtotal, 2), 1, 1, 'R', 1);

        // Descuento
        $this->SetY(-83);
        $this->SetX(-53);
        $this->SetFont('Arial', 'B', 11);
        $this->Cell(25, 5, 'Descuento', 0, 0, 'R', 0);
        $this->SetFillColor(240, 240, 240);
        $this->SetFont('Arial', '', 11);
        $this->Cell(20, 7, '0.00', 1, 1, 'R', 1);

        // ITBIS
        $this->SetY(-74);
        $this->SetX(-53);
        $this->SetFont('Arial', 'B', 11);
        $this->Cell(25, 5, 'ITBIS', 0, 0, 'R', 0);
        $this->SetFillColor(240, 240, 240);
        $this->SetFont('Arial', '', 11);
        $this->Cell(20, 7, number_format($itbistotal, 2), 1, 1, 'R', 1);

        // Total
        $this->SetY(-65);
        $this->SetX(-53);
        $this->SetFont('Arial', 'B', 11);
        $this->Cell(25, 5, 'Total RD$', 0, 0, 'R', 0);
        $this->SetFillColor(240, 240, 240);
        $this->SetFont('Arial', '', 11);
        $this->Cell(20, 7, number_format(($subtotal + $itbistotal), 2), 1, 1, 'R', 1);

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
