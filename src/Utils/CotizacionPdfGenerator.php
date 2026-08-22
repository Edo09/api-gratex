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

require_once __DIR__ . '/../Models/EmisorConfigModel.php';
require_once __DIR__ . '/Pdf/FacturaTemplateFactory.php';

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
        $this->CheckPageBreak($h + 2); // +2 = el Ln($h + 2) del cierre de fila
        for ($i = 0; $i < count($data); $i++) {
            $w = (isset($this->widths) && isset($this->widths[$i])) ? $this->widths[$i] : 40;
            $a = isset($this->aligns[$i]) ? $this->aligns[$i] : 'L';
            $x = $this->GetX();
            $y = $this->GetY();
            $this->MultiCell($w, $this->lineHeight, $data[$i], 0, $a);
            $this->SetXY($x + $w, $y);
        }
        $this->Ln($h + 2);
    }
    private $cotizacion;
    private $widths;
    private $aligns;
    private $lineHeight;

    /**
     * Banda inferior reservada (mm) para el bloque fijo de cierre:
     * condiciones/forma de pago (SetY(-92)) + totales + firmas del Footer.
     * El auto page break se configura con este margen para que las filas de
     * items corten antes de invadirlo (antes se solapaban: el detalle llegaba
     * hasta y=277 mientras el cierre se dibuja desde y=205).
     */
    private const CIERRE_RESERVADO = 95;

    /**
     * Datos ya resueltos de la banda de cliente (cliente, telefono, correo,
     * contacto, condiciones). Se guardan para poder repetir la banda en las
     * paginas de continuacion desde Header().
     */
    private $clienteBanda = null;
    /** @var FacturaTemplate|null Plantilla del tenant (encabezado de identidad). */
    private $template = null;
    private $emisorConfig = null;
    private $emisorConfigLoaded = false;
    /** @var bool|null Arial Narrow vendorizada cargada (guard del AddFont). */
    private $narrowLoaded = null;

    /** Plantilla del tenant (branding), lazy igual que en FacturaPdfGenerator. */
    private function template(): FacturaTemplate
    {
        if ($this->template === null) {
            $this->template = FacturaTemplateFactory::create();
        }
        return $this->template;
    }

    /**
     * Datos del emisor desde emisor_config con los fallbacks historicos de
     * gratex (misma forma que FacturaPdfGenerator::emisorParaPlantilla()).
     */
    private function emisorParaPlantilla(): array
    {
        if (!$this->emisorConfigLoaded) {
            try {
                // Mismo guard que FacturaPdfGenerator::emisorConfig(): sin
                // driver pdo_mysql, Database::getInstance() haria die().
                $this->emisorConfig = extension_loaded('pdo_mysql')
                    ? ((new EmisorConfigModel())->get() ?: [])
                    : [];
            } catch (\Throwable $e) {
                $this->emisorConfig = [];
            }
            $this->emisorConfigLoaded = true;
        }
        $emisor = $this->emisorConfig;
        // Los valores de Gratex solo aplican cuando NO hay tenant resuelto (preview
        // sin BD / single-tenant). Con un tenant resuelto se deja vacio: imprimir el
        // telefono, el correo o el RNC de Gratex en la factura de otro contribuyente
        // es peor que no imprimir nada, y DGII valida la representacion impresa.
        $sinTenant = !class_exists('TenantResolver') || TenantResolver::current() === null;
        $fb = fn(string $valor) => $sinTenant ? $valor : '';
        return [
            'razon_social' => $emisor['nombre_comercial'] ?? $emisor['razon_social'] ?? '',
            'direccion'    => $emisor['direccion'] ?? $fb('Calle Jose Nicolas Casimiro #85, Ensanche Espaillat, Santo Domingo, D.N.'),
            'telefono'     => $emisor['telefono'] ?? $fb('809-681-5141'),
            'correo'       => $emisor['correo'] ?? $fb('info@gratex.net'),
            'rnc'          => $emisor['rnc'] ?? $fb('131256432'),
            'website'      => $emisor['website'] ?? $fb('www.gratex.net'),
        ];
    }

    /**
     * Fuente para el pie: Arial Narrow vendorizada si existe, Arial si no
     * (el AddFont sin guard rompia el PDF cuando faltaba la fuente).
     */
    private function narrowFont(): string
    {
        if ($this->narrowLoaded === null) {
            $this->narrowLoaded = false;
            if (is_file(__DIR__ . '/../../vendor/fpdf/font/arial-narrow.php')) {
                try {
                    $this->AddFont('Arial-narrow', '', 'arial-narrow.php');
                    $this->narrowLoaded = true;
                } catch (\Throwable $e) {
                    $this->narrowLoaded = false;
                }
            }
        }
        return $this->narrowLoaded ? 'Arial-narrow' : 'Arial';
    }

    /** Acento del tenant (o negro) + texto de contraste, para las bandas. */
    private function bandColors(): array
    {
        $branding = BrandingResolver::resolve();
        $fill = $branding['accent'] ?? [0, 0, 0];
        return [$fill, BrandingResolver::contrastText($fill)];
    }

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
        // Identidad del emisor (logo + contacto) segun la plantilla/branding
        // del tenant; los datos salen de emisor_config (fallback gratex).
        $this->template()->drawCompanyHeader(
            $this,
            $this->emisorParaPlantilla(),
            BrandingResolver::logoPath(),
            'cotizacion'
        );
        $this->SetTextColor(0, 0, 0);

        // Bloque propio de la cotizacion (titulo, numero, descargo): se
        // mantiene en el generador. max(30, Y) por si la plantilla deja el
        // cursor mas abajo (p.ej. banda del moderno).
        $continuacion = $this->PageNo() > 1;

        $this->SetY(max(30, $this->GetY() + 1));
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(30, 4, $this->convertEncoding('Cotización/Factura Proforma'), 0, 1, 'L');
        $this->Ln(1);
        $this->SetFont('Arial', '', 14);
        $this->Cell(22, 5, '#' . ($this->cotizacion['code'] ?? ''), 0, 1, 'L');
        $this->Ln(1);

        if ($continuacion) {
            // Marca de continuacion: mismo encabezado que la pagina 1, pero
            // dejando claro que no es el inicio del documento. {nb} lo
            // sustituye AliasNbPages() al cerrar el PDF.
            $this->SetFont('Arial', 'B', 10);
            $this->Cell(0, 5, $this->convertEncoding('Continuación · Página ') . $this->PageNo() . ' de {nb}', 0, 1, 'L');
            $this->Ln(1);
        }

        if (!$continuacion) {
            // El descargo legal solo en la primera pagina: repetirlo en las de
            // continuacion roba altura util y deja una sola fila por pagina.
            $this->SetFont('Arial', '', 9);
            $this->MultiCell(200, 3.6, $this->convertEncoding('Esta cotización/factura proforma es para uso provisional. Al momento de la entrega del pedido se emitirá una factura válida para crédito fiscal.'), 0, 'L');
        }
        $this->Ln(3);

        if ($continuacion) {
            // Repetir las bandas para que las filas de la pagina 2+ conserven
            // el contexto (a quien se cotiza y que significa cada columna).
            $this->drawClienteBanda();
            $this->drawItemsBanda();
        }
    }

    /**
     * Banda de cliente (encabezado oscuro + valores). Usa los datos ya
     * resueltos en generatePdf(); no hace nada si aun no se han cargado.
     */
    private function drawClienteBanda(): void
    {
        if ($this->clienteBanda === null) {
            return;
        }
        $d = $this->clienteBanda;
        [$bandFill, $bandText] = $this->bandColors();
        $this->SetFont('Arial', 'B', 10);
        $this->SetFillColor($bandFill[0], $bandFill[1], $bandFill[2]);
        $this->SetTextColor($bandText[0], $bandText[1], $bandText[2]);
        $this->Cell(40, 6, 'Cliente', 0, 0, 'L', 1);
        $this->Cell(40, 6, 'Telefono/Celular', 0, 0, 'L', 1);
        $this->Cell(55, 6, 'Correo Electronico', 0, 0, 'L', 1);
        $this->Cell(30, 6, 'Contacto', 0, 0, 'L', 1);
        $this->Cell(40, 6, 'Condiciones de Pago', 0, 1, 'L', 1);
        $this->SetFont('Arial', '', 10);
        $this->SetTextColor(0, 0, 0);
        $rowHeight = 5;
        $startY = $this->GetY();
        $startX = $this->GetX();
        $this->MultiCell(40, $rowHeight, $this->convertEncoding($d['cliente']), 0, 'L');
        $this->SetXY($startX + 40, $startY);
        $this->MultiCell(40, $rowHeight, $this->convertEncoding($d['telefono']), 0, 'L');
        $this->SetXY($startX + 40 + 40, $startY);
        $this->MultiCell(55, $rowHeight, $this->convertEncoding($d['email']), 0, 'L');
        $this->SetXY($startX + 40 + 40 + 55, $startY);
        $this->MultiCell(30, $rowHeight, $this->convertEncoding($d['contacto']), 0, 'L');
        $this->SetXY($startX + 40 + 40 + 55 + 30, $startY);
        $this->MultiCell(40, $rowHeight, $this->convertEncoding($d['condiciones']), 0, 'L');
        $this->Ln(2);
    }

    /** Banda de columnas del detalle (Fecha, Cantidad, Descripcion, ITBIS, Valor Unit). */
    private function drawItemsBanda(): void
    {
        [$bandFill, $bandText] = $this->bandColors();
        $this->SetFont('Arial', 'B', 10);
        $this->SetFillColor($bandFill[0], $bandFill[1], $bandFill[2]);
        $this->SetTextColor($bandText[0], $bandText[1], $bandText[2]);
        $this->Cell(25, 6, 'Fecha', 0, 0, 'L', 1);
        $this->Cell(25, 6, 'Cantidad', 0, 0, 'L', 1);
        $this->Cell(105, 6, 'Descripcion Producto', 0, 0, 'L', 1);
        $this->Cell(25, 6, 'ITBIS', 0, 0, 'L', 1);
        $this->Cell(25, 6, 'Valor Unit', 0, 1, 'L', 1);
        $this->Ln(1);
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Arial', '', 11);
    }

    /**
     * Page footer
     */
    public function Footer()
    {
        // Firmas
        $firmaFont = $this->narrowFont();
        $this->Line(15, 268, 70, 268);
        $this->SetY(-10);
        $this->SetX(40);
        $this->SetFont($firmaFont, '', 9);
        $this->Cell(15, 6, 'Firma y sello cliente', 0, 0, 'R');

        $this->Line(145, 268, 200, 268);
        $this->SetY(-10);
        $this->SetX(-40);
        // Mismo criterio que la factura: el sello global es el de Gratex, asi que
        // con un tenant resuelto solo se usa el suyo (sellos/<id>.png) o ninguno.
        $selloPath = class_exists('BrandingResolver')
            ? BrandingResolver::selloPath()
            : (is_file(__DIR__ . '/../../sello.png') ? __DIR__ . '/../../sello.png' : null);
        if ($selloPath !== null) {
            $this->Image($selloPath, 145, 252, -400);
        }
        $this->SetFont($firmaFont, '', 9);
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
        // Reservar la banda inferior fija (cierre + firmas) para que el
        // detalle de items nunca se solape con ella.
        $this->SetAutoPageBreak(true, self::CIERRE_RESERVADO);
        $this->AddPage();

        // Fetch client data from DB if client_id is present
        $contacto = '';
        $telefono = '';
        $email = '';
        $cliente = $this->cotizacion['client_name'] ?? ''; // sin fila de clients quedaba indefinida
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

        // Client info section styled as a table row (like products).
        // Bandas en el acento del tenant (negro si no hay) + texto de contraste.
        // Se guardan los datos para repetir la banda en paginas de continuacion.
        $this->clienteBanda = [
            'cliente'     => $cliente,
            'telefono'    => $fulltelandcel,
            'email'       => $email,
            'contacto'    => $contacto,
            'condiciones' => $condiciones,
        ];
        $this->drawClienteBanda();

        // Second header row: Fecha, Cantidad, Descripcion Producto, ITBIS, Valor Unit
        $this->drawItemsBanda();

        $this->SetWidths([25, 25, 105, 25, 25]);
        // Interlineado del detalle: 4.8 en vez de 5.5. Las descripciones de
        // estas cotizaciones traen ~10 lineas por item; con 5.5 dos items
        // largos ya rozaban el corte de pagina (y=198 vs corte 202) y se
        // partian en cuanto el encabezado real (logo + emisor) crecia.
        $this->SetLineHeight(4.8);

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
        $this->SetX(7);

        // El bloque de cierre se dibuja con coordenadas absolutas dentro de la
        // banda reservada, que queda por debajo del PageBreakTrigger: sin
        // apagar el auto page break cada Cell/MultiCell abriria una pagina.
        $this->SetAutoPageBreak(false);
        // Subtotal, Descuento, ITBIS, Total
        $this->SetFont('Arial', 'B', 9);
        $this->SetY(-90);
           $this->SetX(7);
        $this->Cell(31, 4, 'Condiciones de pago', 0, 1, 'L', 0);
        $this->SetFont('Arial', '', 9);
           $this->SetX(7);
        $this->MultiCell(144, 4, $this->convertEncoding('Persona Jurídica (empresa) 60% avance del total de la Cotización/Factura Proforma y/o envío de una orden de compra/carta constancia firmada y sellada. Restante 40% será pagado al momento de la entrega del pedido. Personas Físicas deben hacer pago por adelantado.'), 0, 'L');
        $this->Ln(7);
        
        $this->SetFont('Arial', 'B', 9);
           $this->SetX(7);
        $this->Cell(31, 4, 'Forma y constancias de pago', 0, 1, 'L', 0);
        $this->SetFont('Arial', '', 9);
           $this->SetX(7);
        $this->MultiCell(144, 4, $this->convertEncoding('Pagos vía transferencia electrónica o con depósito a la cuenta corriente #790371603 a nombre de Gratex EIRL en el Banco Popular Dominicano. Constancia del pago debe ser enviada al e-mail pagoenlinea@gratex.net o whatsapp 849-401-1017.'), 0, 'L');

        $this->SetY(-92);

        $this->Cell(147, 20, '', 1);
        $this->SetY(-69);
        // $this->SetX(7);
        $this->Cell(147, 20, '', 1);

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
