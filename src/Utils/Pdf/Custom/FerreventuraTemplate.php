<?php
require_once __DIR__ . '/../FacturaTemplate.php';

/**
 * Plantilla a la medida de FERREHERRAMIENTAS VENTURA.
 *
 * Calca el formato que el cliente ya usaba (factura en Excel, pre-e-CF):
 * logo centrado con la direccion debajo, tipografia serif, banda de tabla y
 * celdas de totales en su azul, y el pie con "Recibido por:" + los datos de
 * contacto y la cuenta bancaria. SIN sello.
 *
 * Lo obligatorio de la norma DGII lo sigue poniendo el motor y esta plantilla
 * no lo toca: e-NCF y fechas arriba a la derecha, las 6 columnas de items, el
 * cuadro de totales, el QR + Codigo de Seguridad + Fecha Firma, el NCF
 * Modificado de las notas y la paginacion.
 *
 * Activar:  UPDATE tenants SET pdf_template = 'custom:ferreventura' WHERE id = <id>;
 */
class FerreventuraTemplate extends FacturaTemplate
{
    /** Azul de la banda de la tabla, muestreado de su factura (#A2B8CC). */
    private const AZUL_BANDA = [162, 184, 204];

    /** Azul de las celdas de totales (#BCD6ED). */
    private const AZUL_TOTAL = [188, 214, 237];

    /** Gris azulado de los filetes. */
    private const FILETE = [120, 140, 160];

    /**
     * Linea de cuenta bancaria del pie. No existe en emisor_config: es dato
     * propio de este cliente, por eso vive aqui y no en el motor.
     */
    private const CUENTA = 'Cuenta Popular 829580422';

    /** Emisor cacheado en el encabezado para poder repetirlo en el pie. */
    private array $emisor = [];

    public function style(): array
    {
        return [
            'font_family'     => 'Times',
            'body_font_size'  => 10,
            'line_height'     => 4,
            'title_font_size' => 12,
        ];
    }

    public function layout(): array
    {
        return [
            'doc_id_y'      => 10,
            // El encabezado centrado es mas alto que el clasico: la tabla baja.
            'table_start_y' => 62,
        ];
    }

    public function drawCompanyHeader($pdf, array $emisor, ?string $logoPath, string $variant = 'factura'): void
    {
        $this->emisor = $emisor;

        // Centro del bloque izquierdo: la columna derecha del motor (titulo,
        // e-NCF, fechas, receptor) arranca alrededor de x=120, asi que el logo
        // se centra sobre el cuerpo sin invadirla.
        $centro = 64.0;
        $maxW = 74.0;
        $maxH = 20.0;
        $ancho = $this->anchoLogo($logoPath, $maxW, $maxH);
        $this->drawLogo($pdf, $logoPath, $centro - $ancho / 2, 9.0, $maxW, $maxH);

        $pdf->SetY(9.0 + $maxH + 1.5);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('Times', '', 9);
        $pdf->SetX(8);
        $pdf->MultiCell(112, 3.8, $this->enc((string) ($emisor['direccion'] ?? '')), 0, 'C');
        $contacto = trim(
            ($emisor['telefono'] ?? '') !== '' ? 'Tel.: ' . $emisor['telefono'] : ''
        );
        if (($emisor['correo'] ?? '') !== '') {
            $contacto = $contacto === '' ? (string) $emisor['correo'] : $contacto . ' - ' . $emisor['correo'];
        }
        if ($contacto !== '') {
            $pdf->SetX(8);
            $pdf->Cell(112, 3.8, $this->enc($contacto), 0, 1, 'C');
        }
        $pdf->SetX(8);
        $pdf->SetFont('Times', 'B', 9);
        $pdf->Cell(112, 3.8, 'RNC: ' . (string) ($emisor['rnc'] ?? ''), 0, 1, 'C');
    }

    public function drawItemsTableHeader($pdf, array $widths, array $labels): void
    {
        $fill = $this->accentOr(self::AZUL_BANDA);
        $text = $this->textOver($fill);
        $pdf->SetFont('Times', 'B', 9.5);
        $pdf->SetFillColor($fill[0], $fill[1], $fill[2]);
        $pdf->SetTextColor($text[0], $text[1], $text[2]);
        $pdf->SetDrawColor(self::FILETE[0], self::FILETE[1], self::FILETE[2]);
        foreach ($labels as $i => $label) {
            $pdf->Cell($widths[$i], 6.5, $label, 1, 0, 'C', 1);
        }
        $pdf->Ln(8);
        // El motor dibuja las filas justo despues: devolver el negro.
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetDrawColor(0, 0, 0);
    }

    public function drawTotals($pdf, array $filas): void
    {
        $azul = self::AZUL_TOTAL;
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetDrawColor(self::FILETE[0], self::FILETE[1], self::FILETE[2]);

        // Mismo anclaje que el resto de plantillas: la fila Total en y=-40 y las
        // demas apiladas hacia arriba. La zona de totales no se puede mover.
        $y = -40 - 5 * (count($filas) - 1);
        foreach ($filas as [$label, $valor, $bold]) {
            $pdf->SetFont('Times', $bold ? 'B' : '', $bold ? 10 : 9.5);
            $pdf->SetY($y);
            $pdf->SetX(-62);
            // Etiqueta sobre blanco y valor sobre azul, como en su formato.
            $pdf->SetFillColor(255, 255, 255);
            $pdf->Cell(32, 5, $this->enc($label), 1, 0, 'R', 1);
            $pdf->SetFillColor($azul[0], $azul[1], $azul[2]);
            $pdf->Cell(24, 5, number_format((float) $valor, 2), 1, 1, 'R', 1);
            $y += 5;
        }
        $pdf->SetDrawColor(0, 0, 0);
    }

    public function drawFooter($pdf): void
    {
        // Sin sello: el global es el de Gratex y este cliente no usa sello.
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetDrawColor(0, 0, 0);

        // "Recibido por:" con su linea de firma, como en su formato.
        $pdf->SetFont('Times', '', 10);
        $pdf->SetXY(8, 245);
        $pdf->Cell(28, 5, 'Recibido por:', 0, 0, 'L');
        $pdf->Line(36, 249.5, 110, 249.5);

        // Pie centrado: razon social, contacto y cuenta bancaria.
        $nombre = (string) ($this->emisor['razon_social'] ?? '');
        $correo = (string) ($this->emisor['correo'] ?? '');
        $tel    = (string) ($this->emisor['telefono'] ?? '');

        // El motor estampa "Pagina X de Y" cerca de y=267: el bloque cierra antes.
        $y = 251.5;
        if ($nombre !== '') {
            $pdf->SetFont('Times', 'B', 10);
            $pdf->SetXY(8, $y);
            $pdf->Cell(200, 3.6, $this->enc($nombre), 0, 1, 'C');
            $y += 3.6;
        }
        $pdf->SetFont('Times', '', 9);
        foreach ([$correo, $tel !== '' ? 'Telefono ' . $tel : '', self::CUENTA] as $linea) {
            if ($linea === '') {
                continue;
            }
            $pdf->SetXY(8, $y);
            $pdf->Cell(200, 3.6, $this->enc($linea), 0, 1, 'C');
            $y += 3.6;
        }
    }

    /**
     * Ancho con que drawLogo va a dibujar el archivo, para poder centrarlo.
     * Misma matematica que FacturaTemplate::drawLogo.
     */
    private function anchoLogo(?string $logoPath, float $maxW, float $maxH): float
    {
        if ($logoPath === null) {
            return 0.0;
        }
        $info = @getimagesize($logoPath);
        if (!$info || (int) $info[0] <= 0 || (int) $info[1] <= 0) {
            return $maxW;
        }
        $ratio = $info[1] / $info[0];
        $alto = $maxW * $ratio;
        return $alto > $maxH ? $maxH / $ratio : $maxW;
    }
}
