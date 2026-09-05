<?php
require_once __DIR__ . '/Pdf/libs.php';
require_once __DIR__ . '/../Models/EmisorConfigModel.php';
require_once __DIR__ . '/Pdf/FacturaTemplateFactory.php';
require_once __DIR__ . '/Pdf/EcfDocumento.php';

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
     * Contenido del comprobante segun la norma DGII (titulo, receptor, lineas,
     * totales, timbre). Vive aparte porque la tirilla POS de 80 mm imprime
     * exactamente lo mismo con otro diseno: un solo lugar donde cambiarlo.
     */
    private $doc = null;
    /** @var FacturaTemplate|null Plantilla visual (estrategia). Lazy: branding del tenant. */
    private $template = null;
    // true => factura NO electronica (NCF tradicional / factura simple): se omite
    // todo lo propio del e-CF (titulo "Comprobante Fiscal Electronico", etiqueta
    // e-NCF, fecha de vencimiento y QR de timbre DGII).
    private $noElectronica = false;
    // true => superpone una rejilla de calibracion (lineas cada 10 mm + etiquetas
    // en cm) sobre cada pagina. SOLO para disenar plantillas a la medida via
    // /api/branding/preview; jamas se activa en una factura real.
    private $debugGrid = false;
    /**
     * Datos del emisor desde emisor_config (cacheados: Header() se invoca por
     * pagina). Asi la Representacion Impresa (RNC, direccion, telefono, correo)
     * sigue al emisor_config en vez de quedar fija en codigo.
     */
    private function emisorConfig(): array
    {
        return $this->doc()->emisorConfig();
    }

    /**
     * Datos del comprobante (lazy). Se reconstruye cuando cambia la factura,
     * el cliente o el modo no-electronico.
     */
    private function doc(): EcfDocumento
    {
        if ($this->doc === null) {
            $this->doc = new EcfDocumento(
                $this->factura ?? [],
                $this->clientData ?: [],
                $this->noElectronica
            );
        }
        return $this->doc;
    }

    /**
     * Familia core de FPDF que pide la plantilla activa (Arial por defecto).
     * Se valida contra las tres core: un valor raro dejaria el PDF sin fuente.
     */
    private function fontFamily(): string
    {
        $fam = (string) ($this->template()->style()['font_family'] ?? 'Arial');
        return in_array($fam, ['Arial', 'Times', 'Courier'], true) ? $fam : 'Arial';
    }

    /**
     * Plantilla visual activa. Lazy: sin setTemplate() previo usa el branding
     * del tenant resuelto (tenants.pdf_template + pdf_accent_color).
     */
    private function template(): FacturaTemplate
    {
        if ($this->template === null) {
            $this->template = FacturaTemplateFactory::create();
        }
        return $this->template;
    }

    /**
     * Fija la plantilla explicitamente (p.ej. POST /api/branding/preview, que
     * renderiza una plantilla distinta a la persistida del tenant).
     */
    public function setTemplate(FacturaTemplate $template): void
    {
        $this->template = $template;
    }

    /**
     * Datos del emisor con fallbacks (los valores historicos de gratex, p.ej.
     * en previews sin BD), ya listos para que la plantilla los dibuje.
     */
    private function emisorParaPlantilla(): array
    {
        return $this->doc()->emisor();
    }

    /**
     * Set the factura data
     * @param array $factura Factura data
     */
    public function setFactura($factura)
    {
        $this->factura = $factura;
        $this->doc = null;
    }

    /**
     * Marca la factura como NO electronica (NCF tradicional / factura simple).
     * Cambia el diseño: titulo "Factura", etiqueta "NCF" (o "Factura No."),
     * sin fecha de vencimiento y sin QR de timbre fiscal DGII.
     */
    public function setNoElectronica(bool $v = true)
    {
        $this->noElectronica = $v;
        $this->doc = null;
    }

    /**
     * Activa la rejilla de calibracion (10 mm + etiquetas en cm) sobre cada
     * pagina. Herramienta de diseno para plantillas a la medida (replicar el
     * formato impreso de un cliente desde su PDF/escaneo): permite medir y
     * alinear. Ver docs/modules/branding-plantillas.md. No usar en facturas reales.
     */
    public function setDebugGrid(bool $v = true)
    {
        $this->debugGrid = $v;
    }

    /**
     * Set client data fetched from DB
     * @param array $clientData Client data
     */
    public function setClientData($clientData)
    {
        $this->clientData = $clientData;
        $this->doc = null;
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
            $this->MultiCell($w, $this->lineHeight ?: 4, $data[$i], 0, $a);
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
     * Page header — la identidad del emisor (logo + contacto) la dibuja la
     * plantilla del tenant; el contenido (emisor_config) lo fija el motor.
     */
    public function Header()
    {
        $this->template()->drawCompanyHeader(
            $this,
            $this->emisorParaPlantilla(),
            BrandingResolver::logoPath()
        );
        $this->SetTextColor(0, 0, 0);
    }

    /**
     * Page footer — firmas/sello segun la plantilla + paginacion "Página X de Y"
     * (obligatoria DGII en documentos de mas de una hoja; el motor la agrega
     * siempre para que ninguna plantilla pueda omitirla).
     */
    public function Footer()
    {
        $this->template()->drawFooter($this);

        $this->SetTextColor(0, 0, 0);
        $this->SetY(-8);
        $this->SetFont($this->fontFamily(), '', 7);
        $this->Cell(0, 4, $this->convertEncoding('Página ' . $this->PageNo() . ' de {nb}'), 0, 0, 'C');

        // Rejilla de calibracion al final (encima de todo) si esta activada.
        if ($this->debugGrid) {
            $this->drawDebugGrid();
        }
    }

    /**
     * Rejilla de calibracion: lineas cada 10 mm con etiquetas en cm sobre toda
     * la pagina. Sirve para replicar el formato impreso de un cliente —
     * superponer la vista previa sobre su PDF/escaneo y leer las coordenadas
     * (mm) de cada bloque. Se dibuja al final del Footer, asi queda sobre el
     * contenido. Solo se invoca cuando setDebugGrid(true) (preview de diseno).
     */
    private function drawDebugGrid(): void
    {
        $w = $this->GetPageWidth();
        $h = $this->GetPageHeight();
        // Lineas tenues cada 10 mm; las multiplos de 50 mm un poco mas marcadas.
        for ($x = 0; $x <= $w; $x += 10) {
            $this->SetDrawColor(($x % 50 === 0) ? 150 : 205, 205, 235);
            $this->Line($x, 0, $x, $h);
        }
        for ($y = 0; $y <= $h; $y += 10) {
            $this->SetDrawColor(($y % 50 === 0) ? 150 : 205, 205, 235);
            $this->Line(0, $y, $w, $y);
        }
        // Etiquetas en cm (cada 10 mm) en el borde superior e izquierdo.
        $this->SetFont($this->fontFamily(), '', 5);
        $this->SetTextColor(120, 130, 170);
        for ($x = 10; $x < $w; $x += 10) {
            $this->Text($x + 0.4, 3, (string) ((int) ($x / 10)));
        }
        for ($y = 10; $y < $h; $y += 10) {
            $this->Text(0.5, $y - 0.6, (string) ((int) ($y / 10)));
        }
        // Restaurar estado de dibujo/texto para no afectar otras paginas.
        $this->SetDrawColor(0, 0, 0);
        $this->SetTextColor(0, 0, 0);
    }

    /**
     * Dibuja el timbre fiscal DGII (QR + Codigo de Seguridad + Fecha Firma) en
     * el pie. El CONTENIDO del timbre (la URL de ConsultaTimbre con todos sus
     * parametros) lo arma EcfDocumento: es lo que la DGII valida contra el e-CF
     * y debe ser identico en carta y en tirilla POS. Aqui solo se coloca.
     */
    private function addQRTimbre(): void
    {
        $timbre = $this->doc()->timbre();
        if ($timbre === null) {
            return;
        }
        $png = EcfDocumento::generarQrPng($timbre['url']);
        if ($png === null) {
            return;
        }

        // Pagina Letter: 215.9 x 279.4 mm. Para reubicar el bloque, estas mm.
        $qrX = 8;
        $qrY = 205;
        $qrSize = 30;
        $this->Image($png, $qrX, $qrY, $qrSize, $qrSize, 'PNG');
        @unlink($png);

        // Codigo de Seguridad y Fecha Firma a la derecha del QR (norma DGII).
        $infoX = $qrX + $qrSize + 4;
        $savedY = $this->GetY();
        $this->SetXY($infoX, $qrY + 4);
        $this->SetFont($this->fontFamily(), 'B', 8);
        $this->Cell(70, 4, $this->convertEncoding('Código de Seguridad:'), 0, 1, 'L');
        $this->SetX($infoX);
        $this->SetFont($this->fontFamily(), '', 9);
        $this->Cell(70, 4, $timbre['codigo_seguridad'], 0, 1, 'L');
        $this->SetX($infoX);
        $this->SetFont($this->fontFamily(), 'B', 8);
        $this->Cell(70, 4, 'Fecha Firma:', 0, 1, 'L');
        $this->SetX($infoX);
        $this->SetFont($this->fontFamily(), '', 9);
        $this->Cell(70, 4, $timbre['fecha_firma'] !== '' ? $timbre['fecha_firma'] : 'N/D', 0, 1, 'L');

        $this->SetXY($this->lMargin, $savedY);
    }

    /**
     * Generate the PDF content
     * @return string PDF content as string
     */
    public function generatePdf()
    {
        // Resolver plantilla ANTES de AddPage() (Header/Footer la usan).
        $tpl = $this->template();
        $style = $tpl->style();
        $layout = $tpl->layout();
        // Limites del motor: la tabla jamas invade la zona de totales/QR
        // (anclados abajo) ni el encabezado minimo.
        $tableStartY = max(36, min(120, (float) ($layout['table_start_y'] ?? 56)));
        $docIdY = max(6, min(80, (float) ($layout['doc_id_y'] ?? 10)));

        $this->AliasNbPages();
        $this->SetMargins(8, 10, 8);
        $this->AddPage();

        $doc = $this->doc();
        $receptor = $doc->receptor();
        $fechaEspanol = $doc->fechaLarga();

        // La identificacion del documento (e-NCF y fechas) va en la columna
        // derecha, junto al titulo dinamico. La norma DGII prohibe usar la
        // etiqueta "Factura No.": debe usarse exclusivamente "e-NCF".

        // Right side: titulo dinamico del documento + identificacion del e-CF
        // (e-NCF, fechas) + datos del receptor. La Y inicial la decide la
        // plantilla (doc_id_y) — p.ej. moderno la baja para librar su banda.
        $hasQR = class_exists('QRcode');
        $this->SetY($hasQR ? $docIdY : max(30, $docIdY));
        $this->SetX(-73);
        $this->SetFont($this->fontFamily(), 'B', $style['title_font_size'] ?? 11);
        $this->MultiCell(70, 5, $this->convertEncoding($doc->titulo()), 0, 'L');
        $this->Ln(1);
        $this->SetFont($this->fontFamily(), '', 9);
        if ($this->noElectronica) {
            // Factura NO electronica: numero interno + NCF tradicional (si lo hay).
            // Sin etiqueta "e-NCF" ni fecha de vencimiento (eso es propio del e-CF).
            $this->SetX(-73);
            $this->Cell(70, 3.8, $this->convertEncoding('Factura No.: ' . $doc->noFactura()), 0, 1, 'L');
            $ncfTradicional = $doc->ncfTradicional();
            if ($ncfTradicional !== '') {
                $this->SetX(-73);
                $this->Cell(70, 3.8, 'NCF: ' . $ncfTradicional, 0, 1, 'L');
            }
            $this->SetX(-73);
            $this->Cell(70, 3.8, $this->convertEncoding('Fecha: ' . $fechaEspanol), 0, 1, 'L');
        } else {
            $this->SetX(-73);
            $this->Cell(70, 3.8, 'e-NCF: ' . $doc->eNcf(), 0, 1, 'L');
            $this->SetX(-73);
            $this->Cell(70, 3.8, $this->convertEncoding('Fecha de Emisión: ' . $fechaEspanol), 0, 1, 'L');
            $this->SetX(-73);
            $this->Cell(70, 3.8, 'Fecha de Vencimiento: ' . $doc->fechaVencimiento(), 0, 1, 'L');
        }
        $this->Ln(1);
        // El bloque receptor debe reflejar el e-CF emitido (ver ECFXmlBuilder::
        // requiereComprador/buildComprador):
        //  - E43 (Gastos Menores): el e-CF no lleva Comprador -> no se imprime receptor.
        //  - E47 (Pagos al Exterior): comprador extranjero, sin RNC dominicano; el XML
        //    escribe IdentificadorExtranjero -> se etiqueta "Identificación Tributaria".
        if ($receptor['mostrar']) {
            // Sin RNC (p.ej. E32 Consumo sin comprador) no se imprime la linea.
            if ($receptor['rnc'] !== '') {
                $this->SetX(-73);
                $this->Cell(70, 3.8, $this->convertEncoding($receptor['label_id'] . ': ' . $receptor['rnc']), 0, 1, 'L');
            }
            $this->SetX(-73);
            $this->MultiCell(70, 3.8, $this->convertEncoding('Razón Social: ' . $receptor['razon_social']), 0, 'L');
            if ($receptor['contacto'] !== '') {
                $this->SetX(-73);
                $this->Cell(70, 3.8, $this->convertEncoding($receptor['contacto']), 0, 1, 'L');
            }
        }

        // Notas de Debito (E33) / Credito (E34): la norma DGII exige mostrar el
        // NCF Modificado y el Motivo. Se persisten al emitir la nota (ver
        // facturaModel::saveFacturaConECF y la migracion 006). El NCF Modificado
        // va aqui en el encabezado; el Motivo se muestra como descripcion de la
        // linea en la tabla (mas abajo).
        $nota = $doc->notaModificacion();
        if ($nota !== null && $nota['ncf'] !== '') {
            $this->SetXY($this->lMargin, 48);
            $this->SetFont($this->fontFamily(), 'B', 9);
            $lineNcf = 'NCF Modificado: ' . $nota['ncf'];
            if ($nota['fecha'] !== '') {
                $lineNcf .= '  (' . $nota['fecha'] . ')';
            }
            $this->Cell(125, 3.8, $lineNcf, 0, 1, 'L');
        }

        // Force cursor below the header block so the table header doesn't overlap
        // the emisor / receptor columns.
        if ($this->GetY() < $tableStartY) {
            $this->SetY($tableStartY);
        }

        // Table header — columnas exactas y en el orden exigido por la norma
        // DGII (anchos y etiquetas los fija el motor; la plantilla solo dibuja):
        // Cantidad | Descripción | Unidad de Medida | Precio | ITBIS | Valor
        $columnWidths = [18, 92, 24, 21, 21, 24];
        $columnLabels = [
            'Cantidad',
            $this->convertEncoding('Descripción'),
            'Und. Medida',
            'Precio',
            'ITBIS',
            'Valor',
        ];
        $tpl->drawItemsTableHeader($this, $columnWidths, $columnLabels);

        // Table rows
        $this->SetTextColor(0, 0, 0);
        $this->SetFont($this->fontFamily(), '', $style['body_font_size'] ?? 10);
        $this->SetAligns(array('C', 'L', 'C', 'C', 'C', 'C'));
        $this->SetLineHeight($style['line_height'] ?? 4);
        $this->SetWidths($columnWidths);

        // Las lineas ya vienen resueltas por EcfDocumento: nombre + descripcion
        // (fila de la BD completada con el XML firmado), ITBIS por linea, sigla
        // de unidad y el Motivo de las notas E33/E34 anexado donde corresponde.
        foreach ($doc->lineas() as $linea) {
            $this->Row([
                $linea['cantidad'],
                $this->convertEncoding(html_entity_decode($linea['descripcion'])) . "\n ",
                $linea['unidad'],
                number_format($linea['precio'], 2),
                number_format($linea['itbis'], 2),
                number_format($linea['valor'], 2),
            ]);
        }

        // Si el Motivo no se pudo usar como descripcion de una linea (porque los
        // items ya traen la suya), se muestra en su propia fila (norma DGII).
        $motivoFila = $doc->motivoEnFilaAparte();
        if ($motivoFila !== '') {
            $this->Row(['', $this->convertEncoding('Motivo: ' . $motivoFila), '', '', '', '']);
        }

        // Totales del e-CF firmado (cuadran con lo emitido a la DGII); sin XML
        // (preview) caen a la suma por linea. Etiquetas exactas exigidas por la
        // DGII: Subtotal Gravado, Monto Exento (si aplica), Total ITBIS, Total.
        $filasTotales = $doc->filasTotales();

        // Cuadro de totales anclado al pie (la plantilla decide colores/fuente;
        // las filas y etiquetas DGII las fija el motor).
        $this->SetMargins(10, 0, 10);
        $tpl->drawTotals($this, $filasTotales);
        $this->SetTextColor(0, 0, 0);

        // QR del timbre al final, en la pagina actual (ultima), junto a las firmas.
        $this->addQRTimbre();

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
