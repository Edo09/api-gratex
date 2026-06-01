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

$qrLibPath = __DIR__ . '/../../vendor/phpqrcode/qrlib.php';
if (file_exists($qrLibPath)) {
    require_once($qrLibPath);
}

require_once __DIR__ . '/../Models/EmisorConfigModel.php';

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
     * MontoTotal para el QR del timbre. La DGII valida el timbre contra el
     * <MontoTotal> del e-CF firmado, por lo que se extrae del XML emitido
     * (xml_firmado) en vez del campo `total`, que puede no incluir el ITBIS
     * (p.ej. filas sembradas con el monto gravado). Cae al campo `total` solo
     * cuando no hay XML (preview/legacy).
     */
    private function montoTotalParaTimbre(): string
    {
        $xml = $this->factura['xml_firmado'] ?? '';
        if ($xml !== '' && preg_match('/<MontoTotal>\s*([0-9.]+)\s*<\/MontoTotal>/i', $xml, $m)) {
            return number_format((float) $m[1], 2, '.', '');
        }
        return number_format((float) ($this->factura['total'] ?? 0), 2, '.', '');
    }

    /**
     * Totales para el pie de la Representacion Impresa, tomados del e-CF firmado
     * para que cuadren con lo emitido a la DGII. Evita recalcular el ITBIS a
     * ciegas al 18% sobre items exentos/0%/16%. Devuelve null si no hay XML
     * (preview), dejando que el llamador caiga a la suma por linea.
     * @return array{subtotal: float, exento: float, itbis: float, total: float}|null
     */
    private function totalesParaImpresion(): ?array
    {
        $xml = $this->factura['xml_firmado'] ?? '';
        if ($xml === '') {
            return null;
        }
        $get = static function (string $tag) use ($xml): ?float {
            if (preg_match('/<' . $tag . '>\s*([0-9.]+)\s*<\/' . $tag . '>/i', $xml, $m)) {
                return (float) $m[1];
            }
            return null;
        };
        $total = $get('MontoTotal');
        if ($total === null) {
            return null;
        }
        $itbis  = $get('TotalITBIS') ?? 0.0;
        $exento = $get('MontoExento') ?? 0.0;
        $gravado = $get('MontoGravadoTotal');
        if ($gravado === null) {
            $gravado = $total - $itbis - $exento;
        }
        return [
            'subtotal' => round($gravado, 2),
            'exento'   => round($exento, 2),
            'itbis'    => round($itbis, 2),
            'total'    => round($total, 2),
        ];
    }

    /**
     * Render DGII timbre QR + Codigo de Seguridad + Fecha Firma en el pie de
     * factura (seccion de validacion fiscal, segun norma DGII de Representacion
     * Impresa). Solo renderiza si la factura tiene e_ncf y codigo_seguridad.
     */
    private function addQRTimbre(): void
    {
        if (!class_exists('QRcode')) {
            return;
        }

        $eNcf = $this->factura['e_ncf'] ?? '';
        $codigoSeguridad = $this->factura['codigo_seguridad'] ?? '';
        $isPreview = ($eNcf === '' || $codigoSeguridad === '');

        $emisor = [];
        try {
            $emisor = (new EmisorConfigModel())->get() ?: [];
        } catch (\Throwable $e) {
            $emisor = [];
        }
        $rncEmisor = $emisor['rnc'] ?? '';

        if ($isPreview) {
            $url = 'PREVIEW - Sin validez fiscal';
            $codigoSeguridad = 'PREVIEW';
        } else {
            if ($rncEmisor === '') {
                return;
            }
            $ambiente = $this->factura['ambiente_dgii'] ?? ($emisor['environment'] ?? 'CerteCF');
            $ambiente = match(strtolower((string) $ambiente)) {
                'certecf'  => 'CerteCF',
                'testecf'  => 'TesteCF',
                'ecf'      => 'ecf',
                default    => $ambiente,
            };
            $fechaEmision = $this->formatFechaQr($this->factura['date'] ?? '');
            $monto = $this->montoTotalParaTimbre();
            $fechaFirma = $this->formatFechaHoraQr($this->factura['fecha_emision_dgii'] ?? '');

            $isFc = ($this->factura['tipo_ecf'] ?? '') === '32'
                && (float) ($this->factura['total'] ?? 0) < 250000;
            $endpoint = $isFc ? 'ConsultaTimbreFC' : 'ConsultaTimbre';

            // RncComprador en el QR debe coincidir con el XML: DGII valida el timbre
            // contra el e-CF emitido. E43 nunca lleva nodo Comprador y E47 solo lleva
            // IdentificadorExtranjero (jamas RNCComprador). Incluirlo en esos tipos
            // hace que ConsultaTimbre devuelva "no encontrado". Ver ECFXmlBuilder::
            // requiereComprador() y buildComprador().
            $tiposSinRncComprador = ['43', '47'];
            $rncComprador = $this->clientData['rnc'] ?? '';
            $incluyeRncComprador = $rncComprador !== ''
                && !in_array((string) ($this->factura['tipo_ecf'] ?? ''), $tiposSinRncComprador, true);
            $rncCompradorParam = $incluyeRncComprador ? '&RncComprador=' . rawurlencode($rncComprador) : '';

            $url = sprintf(
                'https://ecf.dgii.gov.do/%s/%s?RncEmisor=%s%s&ENCF=%s&FechaEmision=%s&MontoTotal=%s&FechaFirma=%s&CodigoSeguridad=%s',
                rawurlencode($ambiente),
                $endpoint,
                rawurlencode($rncEmisor),
                $rncCompradorParam,
                rawurlencode($eNcf),
                rawurlencode($fechaEmision),
                rawurlencode($monto),
                rawurlencode($fechaFirma),
                rawurlencode($codigoSeguridad)
            );
        }

        $tmpPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qr_' . bin2hex(random_bytes(8)) . '.png';
        try {
            @QRcode::png($url, $tmpPath, QR_ECLEVEL_M, 4, 1);
        } catch (\Throwable $e) {
            return;
        }
        if (!file_exists($tmpPath) || filesize($tmpPath) === 0) {
            return;
        }

        // QR del timbre + datos de firma electronica en el pie de factura.
        // Para reubicar el bloque cambia estas coordenadas (mm). Pagina Letter:
        // 215.9 mm de ancho x 279.4 mm de alto.
        $qrX = 8;
        $qrY = 205;
        $qrSize = 30;
        $this->Image($tmpPath, $qrX, $qrY, $qrSize, $qrSize, 'PNG');
        @unlink($tmpPath);

        // Codigo de Seguridad y Fecha Firma a la derecha del QR (norma DGII).
        $fechaFirma = $this->formatFechaHoraQr($this->factura['fecha_emision_dgii'] ?? '');
        $infoX = $qrX + $qrSize + 4;
        $savedY = $this->GetY();
        $this->SetXY($infoX, $qrY + 4);
        $this->SetFont('Arial', 'B', 8);
        $this->Cell(70, 4, $this->convertEncoding('Código de Seguridad:'), 0, 1, 'L');
        $this->SetX($infoX);
        $this->SetFont('Arial', '', 9);
        $this->Cell(70, 4, $codigoSeguridad, 0, 1, 'L');
        $this->SetX($infoX);
        $this->SetFont('Arial', 'B', 8);
        $this->Cell(70, 4, 'Fecha Firma:', 0, 1, 'L');
        $this->SetX($infoX);
        $this->SetFont('Arial', '', 9);
        $this->Cell(70, 4, $fechaFirma !== '' ? $fechaFirma : 'N/D', 0, 1, 'L');

        $this->SetXY($this->lMargin, $savedY);
    }

    private function formatFechaQr(string $value): string
    {
        if ($value === '') return '';
        $ts = strtotime($value);
        return $ts ? date('d-m-Y', $ts) : '';
    }

    private function formatFechaHoraQr(string $value): string
    {
        if ($value === '') return '';
        $ts = strtotime($value);
        return $ts ? date('d-m-Y H:i:s', $ts) : '';
    }

    /**
     * Titulo dinamico del documento segun el tipo de e-CF (norma DGII).
     */
    private function tituloDocumento(): string
    {
        $tipo = (string) ($this->factura['tipo_ecf'] ?? '');
        $titulos = [
            '31' => 'Factura de Crédito Fiscal Electrónica',
            '32' => 'Factura de Consumo Electrónica',
            '33' => 'Nota de Débito Electrónica',
            '34' => 'Nota de Crédito Electrónica',
            '41' => 'Comprobante Electrónico de Compras',
            '43' => 'Comprobante Electrónico para Gastos Menores',
            '44' => 'Comprobante Electrónico para Regímenes Especiales',
            '45' => 'Comprobante Electrónico Gubernamental',
            '46' => 'Comprobante Electrónico para Exportaciones',
            '47' => 'Comprobante Electrónico para Pagos al Exterior',
        ];
        return $titulos[$tipo] ?? 'Comprobante Fiscal Electrónico';
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
        $facturaDate = $this->factura['date'] ?? date('Y-m-d');
        $fechaEspanol = $this->fechaCastellano($facturaDate);

        // La identificacion del documento (e-NCF y fechas) va en la columna
        // derecha, junto al titulo dinamico. La norma DGII prohibe usar la
        // etiqueta "Factura No.": debe usarse exclusivamente "e-NCF".

        // Right side: titulo dinamico del documento + identificacion del e-CF
        // (e-NCF, fechas) + datos del receptor. Empieza arriba (y=10) junto al logo.
        $hasQR = class_exists('QRcode');
        $eNcfLabel = $this->factura['e_ncf'] ?? $noFactura;
        $this->SetY($hasQR ? 10 : 30);
        $this->SetX(-73);
        $this->SetFont('Arial', 'B', 11);
        $this->MultiCell(70, 5, $this->convertEncoding($this->tituloDocumento()), 0, 'L');
        $this->Ln(1);
        $this->SetFont('Arial', '', 9);
        $this->SetX(-73);
        $this->Cell(70, 3.8, 'e-NCF: ' . $eNcfLabel, 0, 1, 'L');
        $this->SetX(-73);
        $this->Cell(70, 3.8, $this->convertEncoding('Fecha de Emisión: ' . $fechaEspanol), 0, 1, 'L');
        $this->SetX(-73);
        $this->Cell(70, 3.8, 'Fecha de Vencimiento: 31/12/' . date('Y'), 0, 1, 'L');
        $this->Ln(1);
        // El bloque receptor debe reflejar el e-CF emitido (ver ECFXmlBuilder::
        // requiereComprador/buildComprador):
        //  - E43 (Gastos Menores): el e-CF no lleva Comprador -> no se imprime receptor.
        //  - E47 (Pagos al Exterior): comprador extranjero, sin RNC dominicano; el XML
        //    escribe IdentificadorExtranjero -> se etiqueta "Identificación Tributaria".
        $tipoEcfReceptor = (string) ($this->factura['tipo_ecf'] ?? '');
        if ($tipoEcfReceptor !== '43') {
            $labelId = $tipoEcfReceptor === '47' ? 'Identificación Tributaria: ' : 'RNC Cliente: ';
            // Sin RNC (p.ej. E32 Consumo sin comprador) no se imprime la linea.
            if ($rnc !== '') {
                $this->SetX(-73);
                $this->Cell(70, 3.8, $this->convertEncoding($labelId . $rnc), 0, 1, 'L');
            }
            // Sin razon social (E32 Consumo sin comprador) se muestra "Consumidor Final".
            $razonSocial = $companyName !== '' ? $companyName : ($clientName !== '' ? $clientName : 'Consumidor Final');
            $this->SetX(-73);
            $this->MultiCell(70, 3.8, $this->convertEncoding('Razón Social: ' . $razonSocial), 0, 'L');
            $phoneContact = trim($phone);
            if ($clientName) {
                $phoneContact .= ($phoneContact !== '' ? ', ' : '') . 'Att. ' . $clientName;
            }
            if ($phoneContact !== '') {
                $this->SetX(-73);
                $this->Cell(70, 3.8, $this->convertEncoding($phoneContact), 0, 1, 'L');
            }
        }

        // Notas de Debito (E33) / Credito (E34): la norma DGII exige mostrar el
        // NCF Modificado y el Motivo. Se persisten al emitir la nota (ver
        // facturaModel::saveFacturaConECF y la migracion 006). El NCF Modificado
        // va aqui en el encabezado; el Motivo se muestra como descripcion de la
        // linea en la tabla (mas abajo).
        $tipoEcf = (string) ($this->factura['tipo_ecf'] ?? '');
        $ncfModificado = $this->factura['ncf_modificado'] ?? '';
        $razonNota = in_array($tipoEcf, ['33', '34'], true)
            ? trim((string) ($this->factura['razon_modificacion'] ?? ''))
            : '';
        if (in_array($tipoEcf, ['33', '34'], true) && $ncfModificado !== '') {
            $this->SetXY($this->lMargin, 48);
            $this->SetFont('Arial', 'B', 9);
            $fechaMod = $this->formatFechaQr($this->factura['fecha_ncf_modificado'] ?? '');
            $lineNcf = 'NCF Modificado: ' . $ncfModificado;
            if ($fechaMod !== '') {
                $lineNcf .= '  (' . $fechaMod . ')';
            }
            $this->Cell(125, 3.8, $lineNcf, 0, 1, 'L');
        }

        // Force cursor below the header block so the table header doesn't overlap
        // the emisor / receptor columns.
        if ($this->GetY() < 56) {
            $this->SetY(56);
        }

        // Table header
        $this->SetFont('Arial', '', 10);
        $this->SetFillColor(0, 0, 0);
        $this->SetTextColor(255, 255, 255);
        // Columnas exactas y en el orden exigido por la norma DGII:
        // Cantidad | Descripción | Precio | ITBIS | Valor (Precio x Cantidad sin imp.)
        $this->Cell(25, 6, 'Cantidad', 0, 0, 'C', 1);
        $this->Cell(110, 6, $this->convertEncoding('Descripción'), 0, 0, 'C', 1);
        $this->Cell(20, 6, 'Precio', 0, 0, 'C', 1);
        $this->Cell(20, 6, 'ITBIS', 0, 0, 'C', 1);
        $this->Cell(25, 6, 'Valor', 0, 0, 'C', 1);
        $this->Ln(8);

        // Table rows
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Arial', '', 10);
        $this->SetAligns(array('C', 'L', 'C', 'C', 'C'));
        $this->SetLineHeight(4);
        $this->SetWidths(array(25, 110, 20, 20, 25));

        // En Notas E33/E34 el Motivo (razon de modificacion) se muestra como
        // descripcion: si las lineas no traen descripcion propia, llena la columna
        // Descripcion de la linea; si los items ya tienen su propia descripcion,
        // el Motivo va en su propia fila al final (norma DGII).
        $anyDescripcion = false;
        if (isset($this->factura['items']) && is_array($this->factura['items'])) {
            foreach ($this->factura['items'] as $it) {
                $d = $it['description'] ?? $it['nombre_item'] ?? $it['descripcion'] ?? '';
                if (trim((string) $d) !== '') { $anyDescripcion = true; break; }
            }
        }
        $motivoPendiente = $razonNota;

        $subtotal = 0;
        $itbisLineSum = 0;
        if (isset($this->factura['items']) && is_array($this->factura['items'])) {
            foreach ($this->factura['items'] as $item) {
                $cantidad = $item['quantity'] ?? $item['cantidad'] ?? 1;
                $descripcion = $item['description'] ?? $item['nombre_item'] ?? $item['descripcion'] ?? '';
                // Linea sin descripcion propia (y ningun item la trae): el Motivo
                // ocupa la columna Descripcion de esta linea (una sola vez).
                if (trim((string) $descripcion) === '' && !$anyDescripcion && $motivoPendiente !== '') {
                    $descripcion = $motivoPendiente;
                    $motivoPendiente = '';
                }
                $unitario = $item['amount'] ?? $item['precio_unitario'] ?? 0;
                $itbis = $item['itbis_amount'] ?? ($unitario * 0.18);
                $lineSubtotal = $item['subtotal'] ?? $item['monto_item'] ?? ($cantidad * $unitario);
                $subtotal += (float) $lineSubtotal;
                $itbisLineSum += (float) $itbis;

                $this->Row([
                    $cantidad,
                    $this->convertEncoding(html_entity_decode($descripcion)) . "\n ",
                    number_format($unitario, 2),
                    number_format($itbis, 2),
                    number_format($lineSubtotal, 2)
                ]);
            }
        }

        // Si el Motivo no se uso como descripcion de una linea (porque los items
        // ya traen su propia descripcion), se muestra en su propia fila.
        if ($motivoPendiente !== '') {
            $this->Row(['', $this->convertEncoding('Motivo: ' . $motivoPendiente), '', '', '']);
        }

        // Totales del e-CF firmado (cuadran con lo emitido a la DGII). Sin XML
        // (preview) cae a la suma de ITBIS por linea, nunca a un 18% ciego sobre
        // el subtotal (que inventaba ITBIS en facturas exentas/0%/16%).
        $impresion = $this->totalesParaImpresion();
        $subtotalGravado = $impresion['subtotal'] ?? $subtotal;
        $montoExento     = $impresion['exento'] ?? 0.0;
        $itbistotal      = $impresion['itbis'] ?? $itbisLineSum;
        $totalGeneral    = $impresion['total'] ?? ($subtotalGravado + $itbistotal);

        // Totals section (bottom right) — etiquetas exactas exigidas por la DGII:
        // Subtotal Gravado, Monto Exento (solo si aplica), Total ITBIS, Total.
        // 'Monto Exento' se omite cuando es 0 para no recargar facturas gravadas.
        $filasTotales = [['Subtotal Gravado', $subtotalGravado, false]];
        if ($montoExento > 0) {
            $filasTotales[] = ['Monto Exento', $montoExento, false];
        }
        $filasTotales[] = ['Total ITBIS', $itbistotal, false];
        $filasTotales[] = ['Total', $totalGeneral, true];

        $this->SetMargins(10, 0, 10);
        $this->SetFillColor(240, 240, 240);

        // Ancladas al pie: la fila 'Total' queda en Y=-40 y las demas se apilan
        // hacia arriba (5 mm c/u), igual que antes al agregar la fila Exento.
        $y = -40 - 5 * (count($filasTotales) - 1);
        foreach ($filasTotales as [$label, $valor, $bold]) {
            $this->SetFont('Arial', $bold ? 'B' : '', $bold ? 9.5 : 9);
            $this->SetY($y);
            $this->SetX(-58);
            $this->Cell(28, 5, $this->convertEncoding($label), 1, 0, 'R', 1);
            $this->Cell(20, 5, number_format($valor, 2), 1, 1, 'R', 1);
            $y += 5;
        }

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
