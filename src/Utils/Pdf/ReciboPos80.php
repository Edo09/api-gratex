<?php
require_once __DIR__ . '/libs.php';
require_once __DIR__ . '/EcfDocumento.php';
require_once __DIR__ . '/BrandingResolver.php';
require_once __DIR__ . '/FacturaTemplateFactory.php';

/**
 * Representacion Impresa en tirilla POS de 80 mm (impresora termica).
 *
 * Mismo contenido que la RI de carta — lo dicta EcfDocumento, no esta clase —
 * pero en una sola columna de 72 mm y en rollo continuo. Todo lo que la norma
 * DGII exige que aparezca aparece: titulo del documento, e-NCF, RNC y razon
 * social del emisor, fechas de emision y vencimiento, comprador, el detalle con
 * sus seis datos (cantidad, descripcion, unidad, precio, ITBIS, valor), los
 * totales con sus etiquetas exactas, y el timbre (QR + codigo de seguridad +
 * fecha de firma). En 72 mm no caben seis columnas, asi que cada linea se
 * apila en dos renglones; el dato no se pierde, cambia de sitio.
 *
 * ALTO VARIABLE: una tirilla no tiene "hoja". Se dibuja dos veces — una pasada
 * de medicion sobre un lienzo largo para saber donde termina el contenido, y la
 * definitiva sobre una pagina de ese alto exacto. Sin eso el driver de la
 * termica alimenta papel en blanco hasta completar la pagina y corta lejos del
 * ultimo renglon.
 */
final class ReciboPos80
{
    /** Ancho del rollo (mm). El estandar de las termicas de tirilla. */
    private const ANCHO = 80.0;
    /** Margen lateral: casi ninguna termica de 80 mm imprime mas de 72 mm. */
    private const MARGEN = 4.0;
    private const UTIL = self::ANCHO - 2 * self::MARGEN;
    /** Lienzo de la pasada de medicion. */
    private const ALTO_MEDICION = 4000.0;
    /** Tope del formato PDF: 14400 unidades de 1/72" = 5080 mm. */
    private const ALTO_MAXIMO = 5000.0;
    /** Alto minimo para que la tirilla no salga ridiculamente corta. */
    private const ALTO_MINIMO = 80.0;

    private EcfDocumento $doc;
    private string $fuente;
    private ?string $qrPng = null;

    public function __construct(EcfDocumento $doc)
    {
        $this->doc = $doc;
        // Familia core de FPDF que pide la plantilla del tenant (Arial por
        // defecto). El acento y los rellenos de color no se usan: una termica
        // imprime en un solo tono y un fondo oscuro solo gasta cabezal.
        $fam = 'Arial';
        try {
            $fam = (string) (FacturaTemplateFactory::create()->style()['font_family'] ?? 'Arial');
        } catch (\Throwable $e) {
            // Sin branding resuelto se queda con Arial.
        }
        $this->fuente = in_array($fam, ['Arial', 'Times', 'Courier'], true) ? $fam : 'Arial';
    }

    /**
     * @param array $factura       Fila de facturas + 'items' (+ 'xml_firmado').
     * @param array $cliente       Fila de clients (vacio = se resuelve por client_id).
     * @param bool  $noElectronica Factura simple / NCF tradicional.
     */
    public static function paraFactura(array $factura, array $cliente = [], bool $noElectronica = false): self
    {
        return new self(new EcfDocumento($factura, $cliente, $noElectronica));
    }

    /** @return string Contenido del PDF. */
    public function generar(): string
    {
        $timbre = $this->doc->timbre();
        // El QR se genera UNA vez y se reusa en las dos pasadas: es lo unico
        // caro del dibujo y su contenido no depende del alto de la pagina.
        if ($timbre !== null) {
            $this->qrPng = EcfDocumento::generarQrPng($timbre['url']);
        }

        try {
            $medicion = $this->dibujar(self::ALTO_MEDICION, $timbre);
            $alto = $medicion->GetY() + self::MARGEN;
            $alto = max(self::ALTO_MINIMO, min(self::ALTO_MAXIMO, $alto));

            return $this->dibujar($alto, $timbre)->Output('S');
        } finally {
            if ($this->qrPng !== null) {
                @unlink($this->qrPng);
                $this->qrPng = null;
            }
        }
    }

    /**
     * Dibuja la tirilla completa sobre una pagina de 80 x $alto mm.
     * @param array{url:string,codigo_seguridad:string,fecha_firma:string,preview:bool}|null $timbre
     */
    private function dibujar(float $alto, ?array $timbre): FPDF
    {
        $pdf = new FPDF('P', 'mm', [self::ANCHO, $alto]);
        // Sin salto automatico: la tirilla es una sola pagina, y en la pasada de
        // medicion un salto falsearia el alto que estamos calculando.
        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(self::MARGEN, self::MARGEN, self::MARGEN);
        $pdf->AddPage();
        $pdf->SetTextColor(0, 0, 0);

        $this->encabezadoEmisor($pdf);
        $this->identificacion($pdf);
        $this->receptor($pdf);
        $this->detalle($pdf);
        $this->totales($pdf);
        $this->timbreFiscal($pdf, $timbre);
        $this->pie($pdf);

        return $pdf;
    }

    // ------------------------------------------------------------------
    // Bloques
    // ------------------------------------------------------------------

    private function encabezadoEmisor(FPDF $pdf): void
    {
        $emisor = $this->doc->emisor();

        $logo = BrandingResolver::logoPath();
        if ($logo !== null) {
            $this->logoCentrado($pdf, $logo, 34.0, 14.0);
        }

        if (trim($emisor['razon_social']) !== '') {
            $pdf->SetFont($this->fuente, 'B', 9);
            $pdf->MultiCell(self::UTIL, 4, $this->enc($emisor['razon_social']), 0, 'C');
        }

        $pdf->SetFont($this->fuente, '', 6.5);
        if (trim($emisor['rnc']) !== '') {
            $pdf->Cell(self::UTIL, 3, $this->enc('RNC: ' . $emisor['rnc']), 0, 1, 'C');
        }
        if (trim($emisor['direccion']) !== '') {
            $pdf->MultiCell(self::UTIL, 3, $this->enc($emisor['direccion']), 0, 'C');
        }
        $contacto = array_filter([
            trim($emisor['telefono']) !== '' ? 'Tel.: ' . $emisor['telefono'] : '',
            trim($emisor['correo']),
        ]);
        if ($contacto !== []) {
            $pdf->MultiCell(self::UTIL, 3, $this->enc(implode(' - ', $contacto)), 0, 'C');
        }

        $this->separador($pdf);
    }

    private function identificacion(FPDF $pdf): void
    {
        $doc = $this->doc;

        $pdf->SetFont($this->fuente, 'B', 8);
        $pdf->MultiCell(self::UTIL, 3.6, $this->enc(mb_strtoupper($doc->titulo(), 'UTF-8')), 0, 'C');
        $pdf->Ln(1);

        $pares = [];
        if ($doc->esElectronica()) {
            // La norma DGII prohibe rotular el e-NCF como "Factura No.".
            $pares[] = ['e-NCF', $doc->eNcf()];
            $pares[] = ['Fecha de Emisión', $doc->fechaLarga()];
            $pares[] = ['Fecha de Vencimiento', $doc->fechaVencimiento()];
        } else {
            $pares[] = ['Factura No.', $doc->noFactura()];
            if ($doc->ncfTradicional() !== '') {
                $pares[] = ['NCF', $doc->ncfTradicional()];
            }
            $pares[] = ['Fecha', $doc->fechaLarga()];
        }

        // Notas E33/E34: el NCF modificado es obligatorio en la RI.
        $nota = $doc->notaModificacion();
        if ($nota !== null && $nota['ncf'] !== '') {
            $pares[] = ['NCF Modificado', $nota['ncf'] . ($nota['fecha'] !== '' ? ' (' . $nota['fecha'] . ')' : '')];
        }
        $this->bloquePares($pdf, $pares);

        $this->separador($pdf);
    }

    private function receptor(FPDF $pdf): void
    {
        $r = $this->doc->receptor();
        if (!$r['mostrar']) {
            return;
        }

        $pares = [];
        if ($r['rnc'] !== '') {
            $pares[] = [$r['label_id'], $r['rnc']];
        }
        $pares[] = ['Razón Social', $r['razon_social']];
        $this->bloquePares($pdf, $pares);
        if ($r['contacto'] !== '') {
            $pdf->SetFont($this->fuente, '', 7);
            $pdf->MultiCell(self::UTIL, 3.2, $this->enc($r['contacto']), 0, 'L');
        }

        $this->separador($pdf);
    }

    /**
     * Detalle. Cada linea ocupa dos renglones: la descripcion completa arriba y,
     * debajo, "cantidad UND x precio" a la izquierda con el valor a la derecha
     * (y el ITBIS de la linea cuando lo hay). Asi caben en 72 mm los seis datos
     * que la norma pide por linea sin recortar la descripcion.
     */
    private function detalle(FPDF $pdf): void
    {
        $anchoIzq = 44.0;
        $anchoDer = self::UTIL - $anchoIzq;

        $pdf->SetFont($this->fuente, 'B', 6.5);
        $pdf->Cell($anchoIzq, 3.2, $this->enc('CANT. x PRECIO'), 0, 0, 'L');
        $pdf->Cell($anchoDer, 3.2, 'VALOR', 0, 1, 'R');
        $this->separador($pdf, 0.5);

        foreach ($this->doc->lineas() as $linea) {
            $pdf->SetFont($this->fuente, '', 7);
            $pdf->MultiCell(self::UTIL, 3.2, $this->enc(html_entity_decode($linea['descripcion'])), 0, 'L');

            $pdf->SetFont($this->fuente, '', 6.5);
            $izq = $linea['cantidad'] . ' ' . $linea['unidad'] . ' x ' . number_format($linea['precio'], 2);
            $itbis = $linea['itbis'] > 0 ? 'ITBIS ' . number_format($linea['itbis'], 2) : '';
            // Con montos de siete cifras "cant x precio (ITBIS ...)" se sale de
            // su columna y se monta sobre el valor. Si no cabe, el ITBIS baja a
            // su propio renglon en vez de recortarse: es un dato obligatorio.
            $cabeJunto = $itbis !== ''
                && $pdf->GetStringWidth($this->enc($izq . '  (' . $itbis . ')')) <= $anchoIzq - 1;
            if ($cabeJunto) {
                $izq .= '  (' . $itbis . ')';
            }
            $pdf->Cell($anchoIzq, 3.2, $this->enc($izq), 0, 0, 'L');
            $pdf->SetFont($this->fuente, '', 7);
            $pdf->Cell($anchoDer, 3.2, number_format($linea['valor'], 2), 0, 1, 'R');
            if ($itbis !== '' && !$cabeJunto) {
                $pdf->SetFont($this->fuente, '', 6.5);
                $pdf->Cell(self::UTIL, 3.2, $this->enc($itbis), 0, 1, 'R');
            }
            $pdf->Ln(0.8);
        }

        // Motivo de la nota E33/E34 que no cupo dentro de una linea.
        $motivo = $this->doc->motivoEnFilaAparte();
        if ($motivo !== '') {
            $pdf->SetFont($this->fuente, '', 6.5);
            $pdf->MultiCell(self::UTIL, 3.2, $this->enc('Motivo: ' . $motivo), 0, 'L');
        }

        $this->separador($pdf);
    }

    private function totales(FPDF $pdf): void
    {
        $anchoEtiqueta = 38.0;
        $anchoValor = self::UTIL - $anchoEtiqueta;

        foreach ($this->doc->filasTotales() as [$etiqueta, $valor, $esTotal]) {
            $pdf->SetFont($this->fuente, $esTotal ? 'B' : '', $esTotal ? 9 : 7);
            $pdf->Cell($anchoEtiqueta, $esTotal ? 5 : 3.6, $this->enc($etiqueta . ':'), 0, 0, 'R');
            $pdf->Cell($anchoValor, $esTotal ? 5 : 3.6, $this->enc(($esTotal ? 'RD$' : '') . number_format((float) $valor, 2)), 0, 1, 'R');
        }

        $this->separador($pdf);
    }

    /**
     * Timbre fiscal: QR centrado + codigo de seguridad + fecha de firma. Sin QR
     * (sin la libreria o sin GD) se imprimen igual el codigo y la fecha: son
     * datos exigidos por la norma y no dependen de que la imagen se pueda armar.
     */
    private function timbreFiscal(FPDF $pdf, ?array $timbre): void
    {
        if ($timbre === null) {
            return;
        }

        if ($this->qrPng !== null) {
            $lado = 26.0;
            $pdf->Image($this->qrPng, self::MARGEN + (self::UTIL - $lado) / 2, $pdf->GetY(), $lado, $lado, 'PNG');
            $pdf->SetY($pdf->GetY() + $lado + 1.5);
        }

        if ($timbre['preview']) {
            $pdf->SetFont($this->fuente, 'B', 7);
            $pdf->MultiCell(self::UTIL, 3.2, $this->enc('VISTA PREVIA - SIN VALIDEZ FISCAL'), 0, 'C');
        }

        $pdf->SetFont($this->fuente, 'B', 6.5);
        $pdf->Cell(self::UTIL, 3.2, $this->enc('Código de Seguridad'), 0, 1, 'C');
        $pdf->SetFont($this->fuente, '', 8);
        $pdf->Cell(self::UTIL, 3.6, $this->enc($timbre['codigo_seguridad']), 0, 1, 'C');

        $pdf->SetFont($this->fuente, 'B', 6.5);
        $pdf->Cell(self::UTIL, 3.2, 'Fecha de Firma', 0, 1, 'C');
        $pdf->SetFont($this->fuente, '', 7);
        $pdf->Cell(self::UTIL, 3.2, $timbre['fecha_firma'] !== '' ? $timbre['fecha_firma'] : 'N/D', 0, 1, 'C');

        $this->separador($pdf);
    }

    private function pie(FPDF $pdf): void
    {
        $pdf->SetFont($this->fuente, '', 6);
        if ($this->doc->esElectronica()) {
            $pdf->MultiCell(self::UTIL, 2.8, $this->enc('Consulte la validez de este comprobante escaneando el código QR en el portal de la DGII.'), 0, 'C');
        }
        $pdf->Ln(1);
        $pdf->MultiCell(self::UTIL, 2.8, $this->enc('¡Gracias por su compra!'), 0, 'C');
    }

    // ------------------------------------------------------------------
    // Utilidades de dibujo
    // ------------------------------------------------------------------

    /**
     * Bloque de pares "Etiqueta: valor", alineados entre si.
     *
     * La columna de etiquetas se mide sobre el texto real del bloque en vez de
     * fijarse en un ancho a ojo: etiquetas como "Fecha de Vencimiento" o
     * "Identificación Tributaria" (E47) se comian el valor con un ancho fijo.
     * Se topa en el 55% del papel para que al valor siempre le quede sitio.
     *
     * @param array<int,array{0:string,1:string}> $pares
     */
    private function bloquePares(FPDF $pdf, array $pares): void
    {
        if ($pares === []) {
            return;
        }
        $pdf->SetFont($this->fuente, 'B', 7);
        $ancho = 0.0;
        foreach ($pares as [$etiqueta, ]) {
            $ancho = max($ancho, $pdf->GetStringWidth($this->enc($etiqueta . ':')));
        }
        $ancho = min($ancho + 1.5, self::UTIL * 0.55);

        foreach ($pares as [$etiqueta, $valor]) {
            $y = $pdf->GetY();
            $pdf->SetFont($this->fuente, 'B', 7);
            $pdf->SetXY(self::MARGEN, $y);
            $pdf->Cell($ancho, 3.2, $this->enc($etiqueta . ':'), 0, 0, 'L');
            $pdf->SetFont($this->fuente, '', 7);
            $pdf->MultiCell(self::UTIL - $ancho, 3.2, $this->enc($valor), 0, 'L');
        }
    }

    private function separador(FPDF $pdf, float $espacio = 1.2): void
    {
        $y = $pdf->GetY() + $espacio;
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetLineWidth(0.15);
        $pdf->Line(self::MARGEN, $y, self::ANCHO - self::MARGEN, $y);
        $pdf->SetY($y + $espacio);
    }

    /** Logo centrado dentro de una caja, preservando la proporcion. */
    private function logoCentrado(FPDF $pdf, string $ruta, float $maxW, float $maxH): void
    {
        $info = @getimagesize($ruta);
        $w = $maxW;
        $h = $maxH;
        if ($info && (int) $info[0] > 0 && (int) $info[1] > 0) {
            $ratio = $info[1] / $info[0];
            $h = $maxW * $ratio;
            if ($h > $maxH) {
                $h = $maxH;
                $w = $maxH / $ratio;
            }
        }
        $pdf->Image($ruta, self::MARGEN + (self::UTIL - $w) / 2, $pdf->GetY(), $w, $h);
        $pdf->SetY($pdf->GetY() + $h + 1.5);
    }

    /** UTF-8 -> ISO-8859-1 (fuentes core de FPDF). */
    private function enc(string $s): string
    {
        return mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8');
    }
}
