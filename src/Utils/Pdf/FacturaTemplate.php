<?php
require_once __DIR__ . '/BrandingResolver.php';

/**
 * Plantilla visual de la Representacion Impresa (estrategia de dibujo).
 *
 * El motor (FacturaPdfGenerator) es dueno de TODO el contenido exigido por la
 * norma DGII: identificacion del e-CF (titulo, e-NCF, fechas), receptor,
 * iteracion de items con las columnas obligatorias, filas de totales, QR del
 * timbre + codigo de seguridad + fecha firma, NCF Modificado (E33/E34) y
 * paginacion "Pagina X de Y". Una plantilla solo decide COMO se ve cada
 * bloque (fuentes, colores, disposicion del encabezado/pie) — por
 * construccion no puede eliminar un elemento obligatorio de la RI.
 *
 * Para un diseno a la medida de un cliente: subclasear esta clase en
 * src/Utils/Pdf/Custom/<Nombre>Template.php y apuntar tenants.pdf_template a
 * 'custom:<nombre>'. Ver docs/modules/branding-plantillas.md.
 */
abstract class FacturaTemplate
{
    /** @var array{0:int,1:int,2:int}|null Acento RGB del tenant (null = colores propios de la plantilla). */
    protected ?array $accent;

    /**
     * @param array{0:int,1:int,2:int}|null $accent
     */
    public function __construct(?array $accent = null)
    {
        $this->accent = $accent;
    }

    /**
     * Encabezado de identidad del emisor: logo + razon social/RNC/direccion/
     * contacto. Corre en CADA pagina (FPDF Header()).
     *
     * @param FPDF   $pdf      Documento en curso (expone Cell/MultiCell/Image...).
     * @param array  $emisor   Datos ya resueltos con fallbacks: razon_social,
     *                         direccion, telefono, correo, rnc.
     * @param ?string $logoPath Ruta absoluta del logo del tenant (null = sin logo).
     * @param string $variant  'factura' | 'cotizacion' (la cotizacion conserva
     *                         su propia disposicion en clasico).
     */
    abstract public function drawCompanyHeader($pdf, array $emisor, ?string $logoPath, string $variant = 'factura'): void;

    /**
     * Pie de pagina (firmas, sello, reglas). Corre en CADA pagina (FPDF
     * Footer()). El motor agrega despues la paginacion "Pagina X de Y"
     * (obligatoria DGII), la plantilla no la dibuja.
     */
    abstract public function drawFooter($pdf): void;

    /**
     * Banda de encabezado de la tabla de items. Las columnas (anchos y
     * etiquetas) las fija el motor segun la norma DGII — la plantilla solo
     * decide colores/fuente y dibuja una celda por columna, en orden.
     *
     * @param float[]  $widths Anchos en mm (mismos que las filas del cuerpo).
     * @param string[] $labels Etiquetas ya codificadas para FPDF (ISO-8859-1).
     */
    abstract public function drawItemsTableHeader($pdf, array $widths, array $labels): void;

    /**
     * Cuadro de totales (ancla inferior derecha). Las filas vienen del motor
     * con las etiquetas exactas DGII (Subtotal Gravado / Monto Exento /
     * Total ITBIS / Total).
     *
     * @param array<int, array{0:string,1:float,2:bool}> $filas [label, valor, esTotal]
     */
    abstract public function drawTotals($pdf, array $filas): void;

    /**
     * Parametros tipograficos que el motor aplica al cuerpo del documento.
     * Las subclases pueden sobreescribir entradas individuales via
     * array_merge(parent::style(), [...]).
     */
    public function style(): array
    {
        return [
            'body_font_size'  => 10,
            'line_height'     => 4,
            'title_font_size' => 11,
        ];
    }

    /**
     * Geometria opcional para disenos a la medida. El motor respeta estos
     * valores dentro de limites seguros (la tabla nunca puede invadir la zona
     * de totales/QR). Las plantillas predefinidas usan los defaults.
     */
    public function layout(): array
    {
        return [
            // Y donde inicia el bloque de identificacion del e-CF (titulo,
            // e-NCF, fechas, receptor) en la columna derecha. Plantillas con
            // banda superior (moderno) lo bajan para no quedar sobre la banda.
            'doc_id_y'      => 10,
            // Y minima donde puede empezar la tabla de items (mm).
            'table_start_y' => 56,
        ];
    }

    /** UTF-8 -> ISO-8859-1 (fuentes core de FPDF). */
    protected function enc(string $s): string
    {
        return mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8');
    }

    /**
     * Dibuja el logo ajustado DENTRO de una caja maxima (mm), preservando la
     * proporcion. FPDF con solo width escala la altura libremente: un logo
     * cuadrado/vertical a 65 mm de ancho invadia el bloque del emisor y la
     * tabla. Aqui un logo ancho llena el ancho; uno alto se limita por altura.
     */
    protected function drawLogo($pdf, ?string $logoPath, float $x, float $y, float $maxW, float $maxH): void
    {
        if ($logoPath === null) {
            return;
        }
        $info = @getimagesize($logoPath);
        if ($info && (int) $info[0] > 0 && (int) $info[1] > 0) {
            $ratio = $info[1] / $info[0]; // alto/ancho
            $w = $maxW;
            $h = $maxW * $ratio;
            if ($h > $maxH) {
                $h = $maxH;
                $w = $maxH / $ratio;
            }
            $pdf->Image($logoPath, $x, $y, $w, $h);
            return;
        }
        // Sin dimensiones legibles: comportamiento anterior (solo ancho).
        $pdf->Image($logoPath, $x, $y, $maxW);
    }

    /** Acento del tenant o el color por defecto de la plantilla. */
    protected function accentOr(array $default): array
    {
        return $this->accent ?? $default;
    }

    /** Color de texto legible sobre el acento efectivo. */
    protected function textOver(array $fill): array
    {
        return BrandingResolver::contrastText($fill);
    }
}
