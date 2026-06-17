<?php
/**
 * new_custom_template.php — Andamiaje de una plantilla de factura a la medida.
 *
 * Crea src/Utils/Pdf/Custom/Tenant<id>Template.php (clase Tenant<id>Template),
 * el punto de partida para replicar el formato impreso propio de un cliente.
 * El stub extiende ClasicoTemplate, asi que funciona de inmediato y se
 * personaliza hook por hook hasta calcar el diseno del cliente.
 *
 * Solo CLI (escribe un archivo fuente dentro del repo).
 *
 * Uso:
 *   php tools/new_custom_template.php <tenantId>
 *
 * Despues:
 *   1. Edita el archivo generado (medidas en mm; usa la rejilla de calibracion).
 *   2. Activa:  UPDATE tenants SET pdf_template='custom:tenant<id>' WHERE id=<id>;
 *               (o PUT /api/branding {"template":"custom:tenant<id>"}).
 *   3. Verifica: POST /api/branding/preview {"template":"custom:tenant<id>","grid":true}
 *
 * Guia completa: docs/modules/branding-plantillas.md ("Replicar el formato existente").
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Solo CLI: este generador escribe un archivo fuente en el repo.\n");
}

$arg = isset($argv[1]) ? preg_replace('/\D/', '', (string) $argv[1]) : '';
if ($arg === '' || (int) $arg <= 0) {
    fwrite(STDERR, "Uso: php tools/new_custom_template.php <tenantId>\n");
    exit(1);
}
$id = (int) $arg;

$class     = 'Tenant' . $id . 'Template';
$customDir = __DIR__ . '/../src/Utils/Pdf/Custom';
$dest      = $customDir . '/' . $class . '.php';

if (!is_dir($customDir)) {
    fwrite(STDERR, "ERROR: no existe el directorio {$customDir}\n");
    exit(1);
}
if (is_file($dest)) {
    fwrite(STDERR, "ERROR: {$dest} ya existe. No se sobrescribe (borra o renombra antes).\n");
    exit(1);
}

// Stub en nowdoc: las variables PHP ($pdf, $this, ...) quedan literales; solo
// se sustituyen los placeholders {{CLASS}} y {{ID}}.
$tpl = <<<'TPL'
<?php
require_once __DIR__ . '/../ClasicoTemplate.php';

/**
 * Plantilla a la medida del tenant {{ID}} ('custom:tenant{{ID}}').
 *
 * Generada por tools/new_custom_template.php. Reproduce el formato impreso del
 * cliente. Parte de ClasicoTemplate: cada hook ya funciona (llama a parent::) —
 * personalizalos uno a uno hasta calcar el diseno.
 *
 * COMO MEDIR
 *   Pagina Letter = 215.9 x 279.4 mm. Origen (0,0) arriba-izquierda; X crece a
 *   la derecha, Y hacia abajo; todo en mm. Para ubicar cada bloque corre:
 *     POST /api/branding/preview {"template":"custom:tenant{{ID}}","grid":true}
 *   Superpone una rejilla cada 10 mm (numeros en cm): alinea sobre el PDF/escaneo
 *   del cliente y lee las coordenadas de cada elemento.
 *
 * QUE PUEDES CAMBIAR (lo visual): logo; colores (acento via accentOr()/textOver());
 *   tipografias (SOLO core FPDF: Arial/Helvetica/Times/Courier + Arial Narrow
 *   vendorizada, cargada con guard como en CompactoTemplate::narrowFont());
 *   disposicion del encabezado y del pie; firmas; estilo de la banda de la tabla
 *   y del cuadro de totales; fuentes/interlineado (style()); margenes verticales
 *   seguros (layout()).
 *
 * QUE NO (lo fija el motor por norma DGII; no se puede mover/cubrir/quitar):
 *   el QR del timbre (y~205, x=8, 30 mm), el cuadro de totales (anclado a y=-40),
 *   las 6 columnas obligatorias de items y la paginacion "Pagina X de Y". Aqui
 *   solo decides COMO se ven; el motor las dibuja en posicion fija.
 *
 * Activar:  UPDATE tenants SET pdf_template='custom:tenant{{ID}}' WHERE id={{ID}};
 *           (o PUT /api/branding {"template":"custom:tenant{{ID}}"}).
 */
class {{CLASS}} extends ClasicoTemplate
{
    /** Color de acento por defecto si el tenant no fija pdf_accent_color. Edita al del cliente. */
    private const DEFAULT_ACCENT = [0, 0, 0];

    /**
     * Encabezado de identidad del emisor — corre en CADA pagina.
     * $variant es 'factura' o 'cotizacion': ajusta ambos casos.
     * Para el logo usa $this->drawLogo($pdf, $logoPath, $x, $y, $maxW, $maxH).
     */
    public function drawCompanyHeader($pdf, array $emisor, ?string $logoPath, string $variant = 'factura'): void
    {
        // TODO: calcar el encabezado del cliente. Por ahora usa el clasico.
        parent::drawCompanyHeader($pdf, $emisor, $logoPath, $variant);

        // Ejemplo (descomentar y ajustar a las medidas del cliente):
        // $this->drawLogo($pdf, $logoPath, 8, 10, 60, 18);
        // $pdf->SetFont('Arial', '', 9);
        // $pdf->SetY(30);
        // $pdf->MultiCell(80, 4, $this->enc($emisor['direccion']), 0, 'L');
        // $pdf->Cell(80, 4, $this->enc('Tel.: ' . $emisor['telefono'] . ' - ' . $emisor['correo']), 0, 1, 'L');
        // $pdf->Cell(80, 4, 'RNC: ' . $emisor['rnc'], 0, 1, 'L');
    }

    /** Pie: firmas/sello/reglas (el motor agrega "Pagina X de Y" despues). */
    public function drawFooter($pdf): void
    {
        // TODO: pie del cliente. Por ahora usa el clasico.
        parent::drawFooter($pdf);
    }

    /**
     * Banda de encabezado de la tabla de items. Los anchos ($widths) y las
     * etiquetas ($labels) los fija el motor: dibuja UNA celda por columna, en
     * orden. El color del texto sobre el acento SIEMPRE con $this->textOver().
     */
    public function drawItemsTableHeader($pdf, array $widths, array $labels): void
    {
        // TODO: estilo de la banda. Por ahora usa el clasico.
        parent::drawItemsTableHeader($pdf, $widths, $labels);

        // Ejemplo con acento del cliente (descomentar y borrar el parent:: de arriba):
        // $fill = $this->accentOr(self::DEFAULT_ACCENT);
        // $text = $this->textOver($fill);
        // $pdf->SetFont('Arial', 'B', 10);
        // $pdf->SetFillColor($fill[0], $fill[1], $fill[2]);
        // $pdf->SetTextColor($text[0], $text[1], $text[2]);
        // foreach ($labels as $i => $label) {
        //     $pdf->Cell($widths[$i], 6, $label, 0, 0, 'C', 1);
        // }
        // $pdf->Ln(8);
    }

    /**
     * Cuadro de totales — filas DGII del motor: [label, valor, esTotal].
     * El motor lo ancla al pie (y=-40); aqui solo color/fuente/bordes.
     */
    public function drawTotals($pdf, array $filas): void
    {
        // TODO: estilo de totales. Por ahora usa el clasico.
        parent::drawTotals($pdf, $filas);
    }

    /** Tipografia del cuerpo. Descomenta y ajusta si el cliente usa otra escala. */
    public function style(): array
    {
        return parent::style();
        // return array_merge(parent::style(), [
        //     'body_font_size'  => 10,
        //     'line_height'     => 4,
        //     'title_font_size' => 11,
        // ]);
    }

    /** Geometria vertical (el motor acota table_start_y a [36, 120] mm). */
    public function layout(): array
    {
        return parent::layout();
        // return array_merge(parent::layout(), [
        //     'doc_id_y'      => 10,
        //     'table_start_y' => 56,
        // ]);
    }
}
TPL;

$src = str_replace(['{{CLASS}}', '{{ID}}'], [$class, (string) $id], $tpl);

if (file_put_contents($dest, $src) === false) {
    fwrite(STDERR, "ERROR: no se pudo escribir {$dest}\n");
    exit(1);
}

$rel = 'src/Utils/Pdf/Custom/' . $class . '.php';
echo "== Plantilla a la medida creada ==\n";
echo "  archivo : {$rel}\n";
echo "  clase   : {$class}\n";
echo "  template: custom:tenant{$id}\n\n";
echo "Siguientes pasos:\n";
echo "  1. Edita {$rel} (medidas en mm; usa la rejilla de calibracion).\n";
echo "  2. Activa para el tenant {$id}:\n";
echo "       UPDATE tenants SET pdf_template='custom:tenant{$id}' WHERE id={$id};\n";
echo "       (o PUT /api/branding {\"template\":\"custom:tenant{$id}\"} con el token del tenant)\n";
echo "  3. Verifica con la rejilla:\n";
echo "       POST /api/branding/preview {\"template\":\"custom:tenant{$id}\",\"grid\":true}\n\n";
echo "Guia: docs/modules/branding-plantillas.md (\"Replicar el formato existente de un cliente\").\n";
exit(0);
