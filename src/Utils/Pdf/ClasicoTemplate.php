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
    /** Azul del nombre del emisor y del titulo, y de las reglas, sin acento del tenant. */
    private const AZUL_TITULO = [31, 78, 140];
    private const AZUL_LINEA = [46, 117, 182];
    /** Gris del borde de la tabla y del encabezado/valores sombreados. */
    private const GRIS_BORDE = [110, 110, 110];
    private const GRIS_ENCABEZADO = [221, 221, 221];
    private const GRIS_VALOR = [240, 240, 240];

    public function usaDisposicionNorma(): bool
    {
        return true;
    }

    public function coloresNorma(): array
    {
        return [
            'titulo' => $this->accentOr(self::AZUL_TITULO),
            'linea'  => $this->accentOr(self::AZUL_LINEA),
        ];
    }

    public function drawCompanyHeader($pdf, array $emisor, ?string $logoPath, string $variant = 'factura'): void
    {
        if ($variant === 'cotizacion') {
            $this->drawCotizacionHeader($pdf, $emisor, $logoPath);
            return;
        }
        // Con comprobante en curso (toda factura / e-CF) el emisor se dibuja
        // como en el modelo de RI de la DGII. Sin el (vista de diseno), el
        // encabezado historico de abajo.
        $doc = $this->documento();
        if ($doc !== null) {
            $this->drawEmisorNorma($pdf, $emisor, $logoPath, $doc);
            return;
        }
        // Logo: del tenant (logos/<tenant_id>.<ext>) o el global por defecto.
        // Sin logo va la razon social en texto: el emisor tiene que quedar
        // identificado igual. Caja maxima 65x18 mm: el bloque del emisor
        // empieza en y=30.
        $this->drawLogoOrNombre($pdf, $logoPath, $emisor['razon_social'], 8, 10, 65, 18, 13);
        $pdf->SetFont('Arial', '', 9);
        // Sin logo, una razon social de 4 renglones a 13 pt baja de y=30 y
        // tapaba el primer renglon de la direccion (la DGII exige verla): la
        // direccion arranca debajo del nombre. El logo no mueve el cursor.
        $pdf->SetY(max(30, $pdf->GetY() + 1));
        $pdf->MultiCell(70, 3.8, $this->enc($emisor['direccion']), 0, 'L');
        // Solo lo que el emisor tenga: un e-CF reimpreso desde su XML no trae
        // telefono ni correo, y "Tel.: - E-mail:" vacio ensucia la factura.
        $contacto = [];
        if (trim($emisor['telefono']) !== '') {
            $contacto[] = 'Tel.: ' . $emisor['telefono'];
        }
        if (trim($emisor['correo']) !== '') {
            $contacto[] = 'E-mail: ' . $emisor['correo'];
        }
        if ($contacto !== []) {
            $pdf->Cell(70, 3.8, $this->enc(implode(' - ', $contacto)), 0, 1, 'L');
        }
        $pdf->Cell(70, 3.8, 'RNC: ' . $emisor['rnc'], 0, 1, 'L');
    }

    /**
     * Bloque izquierdo del modelo DGII: logo (si hay), nombre del emisor en
     * azul, RNC, direccion, telefono/correo y la fecha de emision. El cursor
     * queda en el renglon siguiente: el motor dibuja a la derecha el
     * comprobante y debajo las franjas del cliente y de la tabla.
     */
    private function drawEmisorNorma($pdf, array $emisor, ?string $logoPath, EcfDocumento $doc): void
    {
        $ancho = 98;
        $y = 10;
        if ($logoPath !== null) {
            // Caja maxima 50x16 mm; el nombre va debajo.
            $this->drawLogo($pdf, $logoPath, 8, $y, 50, 16);
            $y += 18;
        }
        $color = $this->coloresNorma()['titulo'];
        $pdf->SetXY(8, $y);
        $pdf->SetFont('Arial', 'B', 13);
        $pdf->SetTextColor($color[0], $color[1], $color[2]);
        $pdf->MultiCell($ancho, 5.5, $this->enc($emisor['razon_social']), 0, 'L');
        $pdf->SetTextColor(0, 0, 0);

        $this->lineaEtiquetada($pdf, 'RNC:', $emisor['rnc'], $ancho);
        if (trim($emisor['direccion']) !== '') {
            $this->lineaEtiquetada($pdf, 'Dirección:', $emisor['direccion'], $ancho);
        }
        if (trim($emisor['telefono']) !== '') {
            $this->lineaEtiquetada($pdf, 'Teléfono:', $emisor['telefono'], $ancho);
        }
        if (trim($emisor['correo']) !== '') {
            $this->lineaEtiquetada($pdf, 'Correo:', $emisor['correo'], $ancho);
        }
        $this->lineaEtiquetada(
            $pdf,
            $doc->esElectronica() ? 'Fecha Emisión:' : 'Fecha:',
            $doc->fechaCorta(),
            $ancho
        );
        $pdf->SetY($pdf->GetY() + 1);
    }

    /** "Etiqueta: valor" con la etiqueta en negrita; un valor largo sangra bajo si mismo. */
    private function lineaEtiquetada($pdf, string $etiqueta, string $valor, float $ancho): void
    {
        $pdf->SetX(8);
        $pdf->SetFont('Arial', 'B', 9);
        $e = $this->enc($etiqueta) . ' ';
        $we = $pdf->GetStringWidth($e);
        $pdf->Cell($we, 4, $e, 0, 0, 'L');
        $pdf->SetFont('Arial', '', 9);
        $pdf->MultiCell($ancho - $we, 4, $this->enc($valor), 0, 'L');
    }

    /**
     * Disposicion historica de la cotizacion: logo a la izquierda, datos del
     * emisor alineados a la derecha en la franja superior. Solo cambia la
     * fuente de datos (emisor_config / logo del tenant), no el layout.
     */
    private function drawCotizacionHeader($pdf, array $emisor, ?string $logoPath): void
    {
        // Caja maxima 65x18 mm: el titulo de la cotizacion empieza en y=30.
        $this->drawLogoOrNombre($pdf, $logoPath, $emisor['razon_social'], 5, 10, 65, 18, 13);
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
        // Sello del tenant (sellos/<id>.png). El sello.png global es el de Gratex:
        // solo aplica sin tenant resuelto, nunca en la factura de otro emisor.
        $selloPath = BrandingResolver::selloPath();
        if ($selloPath !== null) {
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

    /**
     * El pie de esta clase empieza en el sello (y=246) si el tenant tiene uno;
     * sin sello, en las lineas de firma (y=259). Con el default (243) una
     * factura sin sello pasaria filas a la hoja siguiente sin necesidad.
     *
     * Solo vale para ESTE pie: una subclase que redibuja drawFooter() (una
     * Tenant<id> a la medida) se queda con el default del motor hasta que
     * declare su propio pie_y, para que la tabla no le pase por encima.
     */
    public function layout(): array
    {
        $layout = parent::layout();
        $pieDeEstaClase = (new ReflectionMethod($this, 'drawFooter'))->getDeclaringClass()->getName() === self::class;
        if ($pieDeEstaClase) {
            $layout['pie_y'] = BrandingResolver::selloPath() !== null ? 245 : 258.5;
        }
        return $layout;
    }

    public function drawItemsTableHeader($pdf, array $widths, array $labels): void
    {
        if ($this->documento() !== null) {
            $this->drawItemsTableHeaderNorma($pdf, $widths, $labels);
            return;
        }
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

    /**
     * Encabezado del modelo DGII: una celda con borde por columna, fondo gris y
     * el rotulo en negrita (los largos, como "Unidad de Medida", en dos
     * renglones). Sin acento del tenant el texto es negro sobre gris; con acento
     * va el acento con texto de contraste. Deja el cursor bajo la fila.
     */
    private function drawItemsTableHeaderNorma($pdf, array $widths, array $labels): void
    {
        $fill = $this->accent ?? self::GRIS_ENCABEZADO;
        $text = $this->accent !== null ? $this->textOver($fill) : [0, 0, 0];
        $alto = 9;
        $lh = 3.8;
        $x0 = $pdf->GetX();
        $y0 = $pdf->GetY();
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetFillColor($fill[0], $fill[1], $fill[2]);
        $pdf->SetDrawColor(self::GRIS_BORDE[0], self::GRIS_BORDE[1], self::GRIS_BORDE[2]);
        $pdf->SetLineWidth(0.2);
        $pdf->SetTextColor($text[0], $text[1], $text[2]);
        $x = $x0;
        foreach ($labels as $i => $label) {
            $pdf->Rect($x, $y0, $widths[$i], $alto, 'DF');
            $renglones = $pdf->NbLines($widths[$i], $label);
            $pdf->SetXY($x, $y0 + ($alto - $renglones * $lh) / 2);
            $pdf->MultiCell($widths[$i], $lh, $label, 0, 'C');
            $x += $widths[$i];
        }
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetXY($x0, $y0 + $alto);
    }

    public function drawTotals($pdf, array $filas): void
    {
        if ($this->documento() !== null) {
            $this->drawTotalsNorma($pdf, $filas);
            return;
        }
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

    /**
     * Totales del modelo DGII: etiqueta en negrita con dos puntos (celda
     * blanca con borde) y el valor en celda gris, alineados a la derecha. Mismo
     * anclaje de siempre: la fila Total en Y=-40 y las demas 5 mm mas arriba.
     */
    private function drawTotalsNorma($pdf, array $filas): void
    {
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetDrawColor(self::GRIS_BORDE[0], self::GRIS_BORDE[1], self::GRIS_BORDE[2]);
        $pdf->SetLineWidth(0.2);
        $pdf->SetFillColor(self::GRIS_VALOR[0], self::GRIS_VALOR[1], self::GRIS_VALOR[2]);
        $y = -40 - 5 * (count($filas) - 1);
        foreach ($filas as [$label, $valor]) {
            $pdf->SetY($y);
            $pdf->SetX(-68);
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->Cell(34, 5, $this->enc($label . ':'), 1, 0, 'R');
            $pdf->SetFont('Arial', '', 9);
            $pdf->Cell(26, 5, number_format($valor, 2), 1, 1, 'R', true);
            $y += 5;
        }
        $pdf->SetDrawColor(0, 0, 0);
    }
}
