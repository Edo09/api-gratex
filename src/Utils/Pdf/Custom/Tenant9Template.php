<?php
require_once __DIR__ . '/../FacturaTemplate.php';

/**
 * Plantilla a la medida de NUEVAS IDEAS NOTICIAS R.D. (tenant 9, 'custom:tenant9').
 *
 * Calca la factura que el cliente hacia en Excel (BANCO_AGRICOLA14.pdf):
 * logo a la izquierda con el nombre comercial grande y la direccion en negrita
 * pequena a su derecha, debajo una columna de etiquetas en negrita (RNC,
 * contacto, cuenta bancaria, fecha), tabla y totales en blanco con filete fino
 * (sin bandas de color: su formato es en blanco y negro) y el pie con
 * "Entregado por:" y "Recibido por:" con su linea de firma. Sin sello.
 *
 * Usa la disposicion del modelo de RI de la DGII (usaDisposicionNorma), la
 * misma de ClasicoTemplate: el motor pone a la derecha el titulo del
 * comprobante, el e-NCF, el NCF Modificado y el vencimiento, y debajo la franja
 * del cliente con sus etiquetas en negrita (como el "Cliente:/RNC:" de su
 * Excel). Lo obligatorio de la norma (6 columnas, totales, QR + Codigo de
 * Seguridad + Fecha Firma, paginacion) lo sigue dibujando el motor.
 *
 * Con acento del tenant (pdf_accent_color) la banda de la tabla, el titulo y
 * las reglas toman ese color; sin acento queda en negro como su formato.
 *
 * Activar:  UPDATE tenants SET pdf_template = 'custom:tenant9' WHERE id = 9;
 *           (o PUT /api/branding {"template":"custom:tenant9"}).
 */
class Tenant9Template extends FacturaTemplate
{
    /**
     * Cuenta bancaria que su factura imprime bajo los datos del cliente. No
     * existe en emisor_config: es dato propio de este cliente.
     */
    private const CUENTA_ETIQUETA = 'Cuenta Banreservas:';
    private const CUENTA_VALOR = 'Ahorro No. 0220214223';

    /** Filete de la tabla y los totales: el mismo gris con que el motor dibuja las filas. */
    private const FILETE = [110, 110, 110];

    /** Fin de la columna izquierda: el motor dibuja el comprobante desde x=108. */
    private const X_FIN_IZQUIERDA = 106;

    /** Caja del logo (mm). Su logo es apaisado (~1.7:1): 40 x 23.5 mm. */
    private const LOGO_X = 8;
    private const LOGO_Y = 8;
    private const LOGO_W = 40;
    private const LOGO_H = 24;

    public function usaDisposicionNorma(): bool
    {
        return true;
    }

    public function coloresNorma(): array
    {
        return [
            'titulo' => $this->accentOr([0, 0, 0]),
            'linea'  => $this->accentOr([0, 0, 0]),
        ];
    }

    public function style(): array
    {
        return array_merge(parent::style(), [
            'font_family'    => 'Arial',
            'body_font_size' => 9.5,
            'line_height'    => 4,
        ]);
    }

    public function layout(): array
    {
        return array_merge(parent::layout(), [
            // El pie ("Entregado por:" / "Recibido por:") empieza en y=250.
            'pie_y' => 248,
        ]);
    }

    // ------------------------------------------------------------------
    // Encabezado
    // ------------------------------------------------------------------

    public function drawCompanyHeader($pdf, array $emisor, ?string $logoPath, string $variant = 'factura'): void
    {
        if ($variant === 'cotizacion') {
            $this->drawCotizacionHeader($pdf, $emisor, $logoPath);
            return;
        }

        $pdf->SetTextColor(0, 0, 0);
        $finLogo = $this->dibujarLogo($pdf, $logoPath);

        // Nombre comercial y direccion, centrados a la derecha del logo (o en
        // todo el ancho de la columna si no hay logo).
        $x = $finLogo > 0 ? self::LOGO_X + self::LOGO_W + 2 : 8;
        $ancho = self::X_FIN_IZQUIERDA - $x;
        $y = $this->dibujarNombreYDireccion($pdf, $emisor, $x, self::LOGO_Y + 1, $ancho, 16, 'C');

        // Columna de etiquetas en negrita, debajo del logo, como en su Excel.
        $pdf->SetY(max($y, $finLogo) + 2);
        $lineas = [['RNC:', (string) $emisor['rnc']]];
        if (trim((string) $emisor['telefono']) !== '') {
            $lineas[] = ['Teléfono:', (string) $emisor['telefono']];
        }
        if (trim((string) $emisor['correo']) !== '') {
            $lineas[] = ['Correo:', (string) $emisor['correo']];
        }
        $lineas[] = [self::CUENTA_ETIQUETA, self::CUENTA_VALOR];
        // La fecha de emision la dibuja la plantilla en la disposicion de la
        // norma (el motor pone el resto del comprobante a la derecha).
        $doc = $this->documento();
        if ($doc !== null) {
            $lineas[] = [$doc->esElectronica() ? 'Fecha Emisión:' : 'Fecha:', $doc->fechaCorta()];
        }
        $this->columnaEtiquetada($pdf, $lineas, 8, self::X_FIN_IZQUIERDA - 8);
        $pdf->SetY($pdf->GetY() + 1);
    }

    /**
     * Cotizacion (formato gratex): el generador escribe su titulo debajo de lo
     * que deje este encabezado (minimo y=30) y no tiene nada a la derecha
     * arriba, asi que la identidad usa todo el ancho.
     */
    private function drawCotizacionHeader($pdf, array $emisor, ?string $logoPath): void
    {
        $pdf->SetTextColor(0, 0, 0);
        $finLogo = $this->dibujarLogo($pdf, $logoPath);
        $x = $finLogo > 0 ? self::LOGO_X + self::LOGO_W + 4 : 8;
        $ancho = 207.9 - $x;
        $y = $this->dibujarNombreYDireccion($pdf, $emisor, $x, self::LOGO_Y + 1, $ancho, 18, 'L');

        $contacto = ['RNC: ' . $emisor['rnc']];
        if (trim((string) $emisor['telefono']) !== '') {
            $contacto[] = 'Tel.: ' . $emisor['telefono'];
        }
        if (trim((string) $emisor['correo']) !== '') {
            $contacto[] = $emisor['correo'];
        }
        $pdf->SetFont('Arial', '', 9);
        $pdf->SetXY($x, $y + 0.5);
        $pdf->MultiCell($ancho, 3.8, $this->enc(implode('  ·  ', $contacto)), 0, 'L');
        $pdf->SetX($x);
        $pdf->Cell($ancho, 3.8, $this->enc(self::CUENTA_ETIQUETA . ' ' . self::CUENTA_VALOR), 0, 1, 'L');

        $pdf->SetY(max($pdf->GetY(), $finLogo) + 1);
    }

    /**
     * Logo del tenant en su caja. Devuelve la Y donde termina (0 si no hay
     * logo: el nombre en texto ocupa entonces su lugar).
     */
    private function dibujarLogo($pdf, ?string $logoPath): float
    {
        if ($logoPath === null) {
            return 0.0;
        }
        $this->drawLogo($pdf, $logoPath, self::LOGO_X, self::LOGO_Y, self::LOGO_W, self::LOGO_H);
        $alto = self::LOGO_H;
        $info = @getimagesize($logoPath);
        if ($info && (int) $info[0] > 0 && (int) $info[1] > 0) {
            // Misma cuenta que drawLogo: un logo ancho llena el ancho y baja menos.
            $alto = min(self::LOGO_H, self::LOGO_W * $info[1] / $info[0]);
        }
        return self::LOGO_Y + $alto;
    }

    /**
     * Nombre comercial en negrita grande y la direccion en negrita pequena
     * debajo, como en su Excel. El nombre va en un renglon si cabe a 13 pt o
     * mas; si no, en dos renglones parejos ("Nuevas Ideas" / "Noticias R.D.")
     * al mayor tamano que quepa, nunca menos de 11 pt. Devuelve la Y donde
     * termina.
     */
    private function dibujarNombreYDireccion($pdf, array $emisor, float $x, float $y, float $ancho, float $tamanoMax, string $alineacion): float
    {
        [$renglones, $tamano] = $this->renglonesNombre($pdf, $this->enc(trim((string) $emisor['razon_social'])), $ancho - 1, $tamanoMax);
        $color = $this->coloresNorma()['titulo'];
        $pdf->SetTextColor($color[0], $color[1], $color[2]);
        $pdf->SetFont('Arial', 'B', $tamano);
        $pdf->SetXY($x, $y);
        foreach ($renglones as $renglon) {
            $pdf->SetX($x);
            // MultiCell: un renglon que aun no quepa a 11 pt parte, no se sale.
            $pdf->MultiCell($ancho, $tamano * 0.45, $renglon, 0, $alineacion);
        }
        $pdf->SetTextColor(0, 0, 0);

        if (trim((string) $emisor['direccion']) !== '') {
            $pdf->SetFont('Arial', 'B', 8);
            $pdf->SetX($x);
            $pdf->MultiCell($ancho, 3.6, $this->enc((string) $emisor['direccion']), 0, $alineacion);
        }
        return $pdf->GetY();
    }

    /**
     * Renglones del nombre y su tamano en pt (ver dibujarNombreYDireccion).
     *
     * @return array{0:string[],1:float}
     */
    private function renglonesNombre($pdf, string $nombre, float $ancho, float $tamanoMax): array
    {
        $cabe = static function (array $renglones, float $tamano) use ($pdf, $ancho): bool {
            $pdf->SetFont('Arial', 'B', $tamano);
            foreach ($renglones as $r) {
                if ($pdf->GetStringWidth($r) > $ancho) {
                    return false;
                }
            }
            return true;
        };

        for ($t = $tamanoMax; $t >= 13; $t -= 0.5) {
            if ($cabe([$nombre], $t)) {
                return [[$nombre], $t];
            }
        }

        // Dos renglones: el corte entre palabras que deja el renglon mas largo
        // lo mas corto posible.
        $palabras = preg_split('/\s+/', $nombre) ?: [$nombre];
        $par = [$nombre];
        if (count($palabras) > 1) {
            $pdf->SetFont('Arial', 'B', 10);
            $mejor = null;
            for ($i = 1; $i < count($palabras); $i++) {
                $a = implode(' ', array_slice($palabras, 0, $i));
                $b = implode(' ', array_slice($palabras, $i));
                $largo = max($pdf->GetStringWidth($a), $pdf->GetStringWidth($b));
                if ($mejor === null || $largo < $mejor) {
                    $mejor = $largo;
                    $par = [$a, $b];
                }
            }
        }
        for ($t = $tamanoMax; $t > 11; $t -= 0.5) {
            if ($cabe($par, $t)) {
                return [$par, $t];
            }
        }
        return [$par, 11.0];
    }

    /**
     * Etiquetas en negrita en una columna fija y los valores alineados a su
     * derecha, como las celdas de su Excel. Un valor largo parte de renglon
     * dentro de su columna.
     *
     * @param array<int, array{0:string,1:string}> $lineas
     */
    private function columnaEtiquetada($pdf, array $lineas, float $x, float $ancho): void
    {
        $pdf->SetFont('Arial', 'B', 9);
        $anchoEtiqueta = 0.0;
        foreach ($lineas as [$etiqueta]) {
            $anchoEtiqueta = max($anchoEtiqueta, $pdf->GetStringWidth($this->enc($etiqueta)));
        }
        $anchoEtiqueta += 3;
        foreach ($lineas as [$etiqueta, $valor]) {
            $pdf->SetX($x);
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->Cell($anchoEtiqueta, 4, $this->enc($etiqueta), 0, 0, 'L');
            $pdf->SetFont('Arial', '', 9);
            $pdf->MultiCell($ancho - $anchoEtiqueta, 4, $this->enc($valor), 0, 'L');
        }
    }

    // ------------------------------------------------------------------
    // Tabla y totales
    // ------------------------------------------------------------------

    /**
     * Encabezado de la tabla como el de su Excel: celdas blancas con filete y
     * rotulo en negrita (los largos, como "Unidad de Medida", en dos
     * renglones). Con acento, la banda toma el acento con texto de contraste.
     */
    public function drawItemsTableHeader($pdf, array $widths, array $labels): void
    {
        $fill = $this->accent ?? [255, 255, 255];
        $text = $this->accent !== null ? $this->textOver($fill) : [0, 0, 0];
        $alto = 9;
        $lh = 3.8;
        $x0 = $pdf->GetX();
        $y0 = $pdf->GetY();
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetFillColor($fill[0], $fill[1], $fill[2]);
        $pdf->SetDrawColor(self::FILETE[0], self::FILETE[1], self::FILETE[2]);
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
        // El motor dibuja las filas justo despues: devolver negro.
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetXY($x0, $y0 + $alto);
    }

    /**
     * Totales como su Excel: etiqueta en negrita alineada a la derecha y el
     * valor en su celda, todo en blanco con filete. Mismo anclaje que el resto
     * de plantillas (fila Total en y=-40, las demas 5 mm arriba): la zona de
     * totales no se mueve.
     */
    public function drawTotals($pdf, array $filas): void
    {
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetDrawColor(self::FILETE[0], self::FILETE[1], self::FILETE[2]);
        $pdf->SetLineWidth(0.2);
        $y = -40 - 5 * (count($filas) - 1);
        foreach ($filas as [$label, $valor, $esTotal]) {
            $pdf->SetY($y);
            $pdf->SetX(-68);
            $pdf->SetFont('Arial', 'B', $esTotal ? 10 : 9);
            $pdf->Cell(34, 5, $this->enc($label . ':'), 1, 0, 'R');
            $pdf->SetFont('Arial', $esTotal ? 'B' : '', $esTotal ? 10 : 9);
            $pdf->Cell(26, 5, number_format((float) $valor, 2), 1, 1, 'R');
            $y += 5;
        }
        $pdf->SetDrawColor(0, 0, 0);
    }

    // ------------------------------------------------------------------
    // Pie
    // ------------------------------------------------------------------

    /**
     * "Entregado por:" y "Recibido por:" con su linea de firma, en el mismo
     * renglon como en su Excel. Va debajo del bloque del QR (que termina en
     * y~243): nada en x<100 por encima de y=244. El motor estampa
     * "Pagina X de Y" en y~271.
     */
    public function drawFooter($pdf): void
    {
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetLineWidth(0.2);
        $pdf->SetFont('Arial', '', 9.5);

        $y = 252;
        $pdf->SetXY(8, $y);
        $pdf->Cell(25, 5, 'Entregado por:', 0, 0, 'L');
        $pdf->Line(33, $y + 4.2, 98, $y + 4.2);

        $pdf->SetXY(116, $y);
        $pdf->Cell(24, 5, 'Recibido por:', 0, 0, 'L');
        $pdf->Line(140, $y + 4.2, 207.9, $y + 4.2);
    }
}
