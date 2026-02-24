<?php
/**
 * Include FPDF library
 */
$fpdfPath = __DIR__ . '/../../vendor/fpdf/fpdf.php';
$composerPath = __DIR__ . '/../../vendor/autoload.php';

if (file_exists($composerPath)) {
    require_once($composerPath);
} elseif (file_exists($fpdfPath)) {
    require_once($fpdfPath);
} else {
    die('FPDF library not found. Please install via composer (composer require setasign/fpdf) or download from http://www.fpdf.org/');
}

/**
 * PDF Generator for Facturas
 * Extends FPDF to create invoice PDFs with custom layout
 */
class FacturaPdfGenerator extends FPDF
{
    private $factura;
    private $widths;
    private $aligns;
    private $lineHeight;
    private $clientData;

    /**
     * Set the factura data
     * @param array $factura Factura data
     */
    public function setFactura($factura)
    {
        $this->factura = $factura;
    }

    /**
     * Set client data fetched from DB
     * @param array $clientData Client data
     */
    public function setClientData($clientData)
    {
        $this->clientData = $clientData;
    }

    /**
     * Convert UTF-8 string to ISO-8859-1 for FPDF compatibility
     * @param string $string UTF-8 encoded string
     * @return string ISO-8859-1 encoded string
     */
    private function convertEncoding($string)
    {
        return mb_convert_encoding($string, 'ISO-8859-1', 'UTF-8');
    }

    /**
     * Convert date to Spanish format (e.g. "Febrero 24, 2026")
     * @param string $date Date string
     * @return string Spanish formatted date
     */
    private function fechaCastellano($date)
    {
        $timestamp = strtotime($date);
        $numeroDia = date('d', $timestamp);
        $mes = date('F', $timestamp);
        $anio = date('Y', $timestamp);
        $meses_EN = array("January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December");
        $meses_ES = array("Enero", "Febrero", "Marzo", "Abril", "Mayo", "Junio", "Julio", "Agosto", "Septiembre", "Octubre", "Noviembre", "Diciembre");
        $nombreMes = str_replace($meses_EN, $meses_ES, $mes);
        return $nombreMes . " " . $numeroDia . ", " . $anio;
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
     * Print a table row with multi-cell support
     * @param array $data Array of cell values
     */
    public function Row($data)
    {
        $nb = 0;
        for ($i = 0; $i < count($data); $i++) {
            if ($data[$i] === null || $data[$i] === '') {
                $data[$i] = '';
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
            $this->MultiCell($w, 4, $data[$i], 0, $a);
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
        // Logo
        $logoPath = __DIR__ . '/../../logo2020.png';
        if (file_exists($logoPath)) {
            $this->Image($logoPath, 8, 10, 65);
        }
        // Company info below logo
        $this->SetFont('Arial', '', 9);
        $this->SetY(30);
        $this->Cell(70, 3.8, $this->convertEncoding('Calle José Nicolás Casimiro #85'), 0, 1, 'L');
        $this->Cell(70, 3.8, 'Ensanche Espaillat, Santo Domingo, D.N.', 0, 1, 'L');
        $this->Cell(70, 3.8, $this->convertEncoding('Tel.: 809-681-5141 - E-mail:info@gratex.net'), 0, 1, 'L');
        $this->Cell(70, 3.8, 'RNC: 131256432', 0, 1, 'L');
    }

    /**
     * Page footer - signatures
     */
    public function Footer()
    {
        // Sello image
        $selloPath = __DIR__ . '/../../sello.png';
        if (file_exists($selloPath)) {
            $this->Image($selloPath, 8, 246, 40);
        }
        // Firma empresa
        $this->Line(8, 259, 50, 259);
        $this->SetY(-20);
        $this->SetX(9);
        $this->SetFont('Arial', '', 10);
        $this->Cell(38, 6, 'Firma y sello empresa', 0, 0, 'R');

        // Firma cliente
        $this->Line(80, 259, 125, 259);
        $this->SetY(-20);
        $this->SetX(81);
        $this->SetFont('Arial', '', 10);
        $this->Cell(38, 6, 'Firma y sello cliente', 0, 0, 'R');
    }

    /**
     * Generate the PDF content
     * @return string PDF content as string
     */
    public function generatePdf()
    {
        $this->AliasNbPages();
        $this->SetMargins(8, 10, 8);
        $this->AddPage();

        // Fetch client data from DB if not already set
        $clientName = '';
        $companyName = '';
        $phone = '';
        $rnc = '';
        $email = '';

        if ($this->clientData) {
            $clientName = $this->clientData['client_name'] ?? '';
            $companyName = $this->clientData['company_name'] ?? '';
            $phone = $this->clientData['phone_number'] ?? '';
            $rnc = $this->clientData['rnc'] ?? '';
            $email = $this->clientData['email'] ?? '';
        } elseif (!empty($this->factura['client_id'])) {
            try {
                $db = Database::getInstance()->getConnection();
                $stmt = $db->prepare('SELECT client_name, company_name, email, phone_number, rnc FROM clients WHERE id = :id LIMIT 1');
                $stmt->execute([':id' => $this->factura['client_id']]);
                $clientRow = $stmt->fetch(\PDO::FETCH_ASSOC);
                if ($clientRow) {
                    $clientName = $clientRow['client_name'];
                    $companyName = $clientRow['company_name'];
                    $phone = $clientRow['phone_number'];
                    $rnc = $clientRow['rnc'];
                    $email = $clientRow['email'];
                }
            } catch (\Exception $e) {
                // fallback
            }
        }

        // Fallbacks
        if (!$clientName) $clientName = $this->factura['client_name'] ?? '';
        if (!$companyName) $companyName = $this->factura['company_name'] ?? '';

        $noFactura = $this->factura['no_factura'] ?? '';
        $ncf = $this->factura['NCF'] ?? $this->factura['ncf'] ?? '';
        $facturaDate = $this->factura['date'] ?? date('Y-m-d');
        $fechaEspanol = $this->fechaCastellano($facturaDate);

        // Left side: Factura No. and Fecha
        $this->Cell(70, 3.8, 'Factura No.: ' . $noFactura, 0, 1, 'L');
        $this->Cell(70, 3.8, 'Fecha: ' . $this->convertEncoding($fechaEspanol), 0, 1, 'L');

        // Right side: Factura Crédito Fiscal, NCF, RNC, Razón Social, Contact, Vencimiento
        $this->SetY(30);
        $this->SetX(-73);
        $this->Cell(70, 3.8, $this->convertEncoding('Factura Crédito Fiscal'), 0, 1, 'L');
        $this->SetX(-73);
        $this->Cell(70, 3.8, 'NCF: ' . $ncf, 0, 1, 'L');
        $this->SetX(-73);
        $this->Cell(70, 3.8, 'RNC: ' . $rnc, 0, 1, 'L');
        $this->SetX(-73);
        $this->MultiCell(70, 3.8, $this->convertEncoding('Razón Social/Nombre: ' . $companyName), 0, 'L');
        $this->SetX(-73);
        $phoneContact = trim($phone);
        if ($clientName) {
            $phoneContact .= ', Att. ' . $clientName;
        }
        $this->Cell(70, 3.8, $this->convertEncoding($phoneContact), 0, 1, 'L');
        $this->SetX(-73);
        $this->Cell(70, 3.8, 'Fecha Vencimiento: 31/12/' . date('Y'), 0, 1, 'L');
        $this->Ln(1.6);

        // Table header
        $this->SetFont('Arial', '', 10);
        $this->SetFillColor(0, 0, 0);
        $this->SetTextColor(255, 255, 255);
        $this->Cell(25, 6, 'Cantidad', 0, 0, 'C', 1);
        $this->Cell(110, 6, $this->convertEncoding('Descripción'), 0, 0, 'C', 1);
        $this->Cell(20, 6, 'Precio Unit.', 0, 0, 'C', 1);
        $this->Cell(20, 6, 'ITBIS', 0, 0, 'C', 1);
        $this->Cell(25, 6, 'Sub Total', 0, 0, 'C', 1);
        $this->Ln(8);

        // Table rows
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Arial', '', 11);
        $this->SetAligns(array('C', 'L', 'C', 'C', 'C'));
        $this->SetLineHeight(4);
        $this->SetWidths(array(25, 110, 20, 20, 25));

        $subtotal = 0;
        if (isset($this->factura['items']) && is_array($this->factura['items'])) {
            foreach ($this->factura['items'] as $item) {
                $cantidad = $item['quantity'] ?? 1;
                $descripcion = $item['description'] ?? '';
                $unitario = $item['amount'] ?? 0;
                $itbis = $unitario * 0.18;
                $lineSubtotal = $cantidad * $unitario;
                $subtotal += $lineSubtotal;

                $this->Row([
                    $cantidad,
                    $this->convertEncoding(html_entity_decode($descripcion)) . "\n ",
                    number_format($unitario, 2),
                    number_format($itbis, 2),
                    number_format($lineSubtotal, 2)
                ]);
            }
        }

        $itbistotal = $subtotal * 0.18;

        // Totals section (bottom right)
        $this->SetMargins(10, 0, 10);
        $this->SetFillColor(240, 240, 240);

        // Subtotal
        $this->SetY(-55);
        $this->SetX(-58);
        $this->SetFont('Arial', '', 10.5);
        $this->Cell(25, 5, 'Sub Total', 1, 0, 'R', 1);
        $this->Cell(23, 5, number_format($subtotal, 2), 1, 1, 'R', 1);

        // Descuento
        $this->SetY(-50);
        $this->SetX(-58);
        $this->Cell(25, 5, 'Descuento', 1, 0, 'R', 1);
        $this->Cell(23, 5, '0.00', 1, 1, 'R', 1);

        // ITBIS
        $this->SetY(-45);
        $this->SetX(-58);
        $this->Cell(25, 5, 'ITBIS', 1, 0, 'R', 1);
        $this->Cell(23, 5, number_format($itbistotal, 2), 1, 1, 'R', 1);

        // Total
        $this->SetY(-40);
        $this->SetX(-58);
        $this->Cell(25, 5, 'Total RD$', 1, 0, 'R', 1);
        $this->Cell(23, 5, number_format($subtotal + $itbistotal, 2), 1, 1, 'R', 1);

        return $this->Output('S');
    }

    /**
     * Output PDF for download
     * @param string $filename Filename
     */
    public function outputForDownload($filename = 'Factura.pdf')
    {
        $this->Output('D', $filename);
    }

    /**
     * Output PDF inline
     * @param string $filename Filename
     */
    public function outputInline($filename = 'Factura.pdf')
    {
        $this->Output('I', $filename);
    }
}

/**
 * Helper function to generate PDF for a factura
 * @param array $factura Factura data (with items)
 * @param array|null $clientData Client data from clients table
 * @param string $output Output type: 'S' (string), 'D' (download), 'I' (inline)
 * @return string|void PDF content if output is 'S'
 */
function generateFacturaPdf($factura, $clientData = null, $output = 'S')
{
    $pdf = new FacturaPdfGenerator('P', 'mm', 'Letter');
    $pdf->setFactura($factura);
    if ($clientData) {
        $pdf->setClientData($clientData);
    }

    switch ($output) {
        case 'D':
            $filename = 'Factura_' . ($factura['no_factura'] ?? 'unknown') . '.pdf';
            $pdf->generatePdf();
            $pdf->outputForDownload($filename);
            break;
        case 'I':
            $filename = 'Factura_' . ($factura['no_factura'] ?? 'unknown') . '.pdf';
            $pdf->generatePdf();
            $pdf->outputInline($filename);
            break;
        case 'S':
        default:
            return $pdf->generatePdf();
    }
}
