<?php
require_once __DIR__ . '/../ClasicoTemplate.php';

/**
 * Plantilla custom de EJEMPLO ('custom:ejemplo') — base de copia para el
 * primer diseno a la medida de un cliente. Ver docs/modules/branding-plantillas.md.
 *
 * Flujo para un cliente real:
 *   1. Copiar este archivo a Tenant<id>Template.php (clase Tenant<id>Template).
 *   2. Ajustar el diseno con los hooks heredados (drawCompanyHeader,
 *      drawFooter, drawItemsTableHeader, drawTotals, style, layout).
 *   3. UPDATE tenants SET pdf_template = 'custom:tenant<id>' (o PUT /api/branding).
 *   4. El cliente verifica con POST /api/branding/preview {"template":"custom:tenant<id>"}.
 *
 * El motor sigue siendo dueno de todo el contenido obligatorio DGII (e-NCF,
 * receptor, columnas de items, totales, QR/codigo de seguridad/fecha firma,
 * paginacion): un custom solo cambia lo visual.
 *
 * Este ejemplo parte del clasico y cambia: doble regla bajo el encabezado y
 * acento por defecto verde corporativo en banda de tabla y totales.
 */
class EjemploTemplate extends ClasicoTemplate
{
    private const DEFAULT_ACCENT = [22, 101, 52]; // verde oscuro

    public function drawCompanyHeader($pdf, array $emisor, ?string $logoPath, string $variant = 'factura'): void
    {
        parent::drawCompanyHeader($pdf, $emisor, $logoPath, $variant);
        if ($variant === 'factura') {
            $accent = $this->accentOr(self::DEFAULT_ACCENT);
            $pdf->SetDrawColor($accent[0], $accent[1], $accent[2]);
            $pdf->Line(8, 43, 207.9, 43);
            $pdf->Line(8, 44, 207.9, 44);
            $pdf->SetDrawColor(0, 0, 0);
        }
    }

    public function drawItemsTableHeader($pdf, array $widths, array $labels): void
    {
        $fill = $this->accentOr(self::DEFAULT_ACCENT);
        $text = $this->textOver($fill);
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetFillColor($fill[0], $fill[1], $fill[2]);
        $pdf->SetTextColor($text[0], $text[1], $text[2]);
        foreach ($labels as $i => $label) {
            $pdf->Cell($widths[$i], 6, $label, 0, 0, 'C', 1);
        }
        $pdf->Ln(8);
    }
}
