<?php
require_once __DIR__ . '/../Pdf/libs.php';
require_once __DIR__ . '/../Pdf/EcfDocumento.php';
// FerreteriaFormato tambien incluye este archivo (para pdf()/preview()):
// require_once corta el ciclo, y ninguno de los dos usa al otro al cargarse,
// solo dentro de sus metodos.
require_once __DIR__ . '/FerreteriaFormato.php';
require_once __DIR__ . '/Redondeo.php';

/**
 * Hoja FPDF de la cotizacion de Ferreteria.
 *
 * Solo agrega a FPDF lo que el renderizador necesita y FPDF no expone: el pie
 * con "Pagina X de Y", la rejilla de calibracion, cuantos renglones ocupa una
 * celda y si un bloque cabe antes del margen inferior. QUE se imprime lo
 * decide FerreteriaCotizacionPdf.
 */
final class FerreteriaCotizacionHoja extends FPDF
{
    /** "Pagina X de Y" en el pie. Solo cuando el documento tiene mas de una pagina. */
    public bool $paginar = false;

    /** Rejilla de calibracion cada 10 mm (tools/test_cotizacion_ferreteria.php --pdf --grid). */
    public bool $grilla = false;

    public function Footer(): void
    {
        if ($this->paginar) {
            // Cae en el margen inferior, fuera de la zona que miden los saltos
            // de pagina: imprimirlo o no nunca mueve el contenido.
            $this->SetY(-11);
            $this->SetFont('Times', '', 8);
            $this->SetTextColor(0, 0, 0);
            $this->Cell(0, 4, mb_convert_encoding('Página ' . $this->PageNo() . ' de {nb}', 'ISO-8859-1', 'UTF-8'), 0, 0, 'C');
        }
        if ($this->grilla) {
            $this->dibujarGrilla();
        }
    }

    /** Si un bloque de $alto mm cabe entre la Y actual y el margen inferior. */
    public function cabe(float $alto): bool
    {
        return $this->GetY() + $alto <= $this->PageBreakTrigger;
    }

    /**
     * Renglones que ocupa $txt (ya en ISO-8859-1) en una celda de $w mm con la
     * fuente actual. Mismo algoritmo que MultiCell (FacturaPdfGenerator::NbLines):
     * el alto de la fila tiene que coincidir con lo que MultiCell dibuja.
     */
    public function renglones(float $w, string $txt): int
    {
        $cw = &$this->CurrentFont['cw'];
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s = str_replace("\r", '', $txt);
        $nb = strlen($s);
        if ($nb > 0 && $s[$nb - 1] === "\n") {
            $nb--;
        }
        $sep = -1;
        $i = 0;
        $j = 0;
        $l = 0;
        $nl = 1;
        while ($i < $nb) {
            $c = $s[$i];
            if ($c === "\n") {
                $i++;
                $sep = -1;
                $j = $i;
                $l = 0;
                $nl++;
                continue;
            }
            if ($c === ' ') {
                $sep = $i;
            }
            $l += $cw[$c] ?? 0;
            if ($l > $wmax) {
                if ($sep === -1) {
                    if ($i === $j) {
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
     * Rejilla cada 10 mm con etiquetas en cm, encima de todo (misma que
     * FacturaPdfGenerator::drawDebugGrid). Sirve para medir el PDF contra la
     * hoja de Excel del cliente; jamas se activa fuera del script de prueba.
     */
    private function dibujarGrilla(): void
    {
        $w = $this->GetPageWidth();
        $h = $this->GetPageHeight();
        for ($x = 0; $x <= $w; $x += 10) {
            $this->SetDrawColor(($x % 50 === 0) ? 150 : 205, 205, 235);
            $this->Line($x, 0, $x, $h);
        }
        for ($y = 0; $y <= $h; $y += 10) {
            $this->SetDrawColor(($y % 50 === 0) ? 150 : 205, 205, 235);
            $this->Line(0, $y, $w, $y);
        }
        $this->SetFont('Times', '', 5);
        $this->SetTextColor(120, 130, 170);
        for ($x = 10; $x < $w; $x += 10) {
            $this->Text($x + 0.4, 3, (string) ((int) ($x / 10)));
        }
        for ($y = 10; $y < $h; $y += 10) {
            $this->Text(0.5, $y - 0.6, (string) ((int) ($y / 10)));
        }
        $this->SetDrawColor(0, 0, 0);
        $this->SetTextColor(0, 0, 0);
    }
}

/**
 * PDF de la cotizacion en el formato de Ferreteria ("COTIZACION MERCANCIAS"),
 * calcado de la hoja de Excel que el cliente ya usaba (spec 2026-10-01, 7).
 *
 * Renderizador puro: recibe la cotizacion con sus totales ya calculados
 * (FerreteriaFormato::totales), el emisor, el cliente y la ruta del logo, y
 * devuelve los bytes. No toca Database, TenantResolver ni BrandingResolver:
 * el script de prueba lo corre sin BD y el mismo dibujo sirve para el PDF
 * guardado y para la vista previa.
 *
 * Los saltos de pagina los decide esta clase, no FPDF: una fila nunca se
 * parte, la marca "No hay mas productos" queda con la ultima fila, y totales +
 * "Recibido por" + pie pasan enteros a otra pagina si no caben.
 *
 * Con documento => 'conduce' dibuja el conduce de mercancia (spec conduces
 * 4.4): la misma hoja, titulada CONDUCE DE MERCANCIA, con las columnas
 * Cantidad | Unidad | Descripcion y sin precios ni totales (va con la
 * mercancia y lo firma quien la recibe). Sin esa clave, o con 'cotizacion',
 * la cotizacion sale exactamente como antes.
 */
final class FerreteriaCotizacionPdf
{
    /** Margen izquierdo/derecho (mm). Arriba van 10. */
    private const MARGEN = 15.0;

    /** Margen inferior (mm): ahi cae "Pagina X de Y" y nada mas. */
    private const MARGEN_INF = 15.0;

    /** Columnas fijas (mm); Descripcion se queda con el resto del ancho util. */
    private const ANCHO_CANTIDAD = 24.0;
    private const ANCHO_UNITARIO = 29.0;
    private const ANCHO_TOTAL = 30.0;

    /** Unidad, solo en el conduce: Cantidad | Unidad | Descripcion (el resto). */
    private const ANCHO_UNIDAD = 30.0;

    /** Caja maxima del logo (mm); se respeta su proporcion. */
    private const LOGO_MAX_W = 75.0;
    private const LOGO_MAX_H = 28.0;

    private const ALTO_BLOQUE = 4.5;
    private const ALTO_CABECERA = 7.0;
    private const ALTO_RENGLON = 4.5;
    private const ALTO_TOTAL = 5.5;
    private const ESPACIO_TOTALES = 1.5;
    private const ESPACIO_RECIBIDO = 6.0;
    private const ALTO_RECIBIDO = 6.0;
    private const ESPACIO_PIE = 3.0;
    private const ALTO_PIE = 4.5;

    /** Azul de la banda de la tabla y de los valores de totales en su Excel (#BDD7EE). */
    private const AZUL = [189, 215, 238];

    /** Azul de hipervinculo de Excel para el correo (#0563C1). */
    private const AZUL_CORREO = [5, 99, 193];

    private const MARCA = '***********No hay más productos debajo de la línea*****';

    private array $cotizacion;
    private array $emisor;
    private array $cliente;
    private ?string $logoPath;
    private bool $grilla = false;

    /** Un logo que FPDF no pudo leer en la primera pasada no se reintenta en la segunda. */
    private bool $logoFallo = false;

    /**
     * @param array $cotizacion code (?string; null => 'VISTA PREVIA'), date, items
     *                          [{description, quantity, amount}] y totales (salida
     *                          exacta de FerreteriaFormato::totales). Conduce:
     *                          documento 'conduce', code, date, cotizacion_code
     *                          (?string; null => sin linea "Cotizacion:") e items
     *                          [{description, quantity, unidad}] con el nombre de
     *                          la unidad ya resuelto; sin totales.
     * @param array $emisor     Fila de emisor_config: rnc, razon_social, direccion, telefono, correo.
     * @param array $cliente    razon_social, company_name, client_name, rnc.
     * @param ?string $logoPath Ruta absoluta del logo; null o ilegible => razon social en texto.
     */
    public function __construct(array $cotizacion, array $emisor, array $cliente, ?string $logoPath)
    {
        $this->cotizacion = $cotizacion;
        $this->emisor = $emisor;
        $this->cliente = $cliente;
        $this->logoPath = $logoPath;
    }

    /** Rejilla de calibracion (solo para comparar contra el Excel desde el script de prueba). */
    public function setDebugGrid(bool $v = true): void
    {
        $this->grilla = $v;
    }

    /** Bytes del PDF. */
    public function render(): string
    {
        // "Pagina X de Y" solo va si hay mas de una pagina, y eso no se sabe
        // hasta terminar de dibujar: la primera pasada cuenta, la segunda
        // imprime. Las dos dibujan exactamente lo mismo (el pie cae en el
        // margen inferior), asi que el conteo no cambia entre una y otra.
        $paginas = $this->dibujar(false)->PageNo();
        return $this->dibujar($paginas > 1)->Output('S');
    }

    private function dibujar(bool $paginar): FerreteriaCotizacionHoja
    {
        $pdf = new FerreteriaCotizacionHoja('P', 'mm', 'Letter');
        $pdf->paginar = $paginar;
        $pdf->grilla = $this->grilla;
        $pdf->AliasNbPages();
        $pdf->SetMargins(self::MARGEN, 10, self::MARGEN);
        // Sin salto automatico: FPDF cortaria una celda a la mitad. El margen
        // igual se pasa porque fija el limite que mide cabe().
        $pdf->SetAutoPageBreak(false, self::MARGEN_INF);
        $pdf->AddPage();
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetDrawColor(0, 0, 0);

        $this->encabezado($pdf);
        $this->tabla($pdf);
        $this->cierre($pdf);
        return $pdf;
    }

    /** Logo, direccion, RNC, titulo y el bloque de fecha/numero/cliente (solo en la primera pagina). */
    private function encabezado(FerreteriaCotizacionHoja $pdf): void
    {
        $util = $this->anchoUtil($pdf);

        $y = $pdf->GetY();
        $logo = $this->medidasLogo();
        if ($logo !== null && $this->ponerLogo($pdf, $logo, ($pdf->GetPageWidth() - $logo['w']) / 2, $y)) {
            $pdf->SetY($y + $logo['h'] + 1.5);
        } else {
            // Sin logo el documento igual tiene que decir quien lo emite.
            $nombre = $this->texto($this->emisor['razon_social'] ?? '');
            if ($nombre !== '') {
                $pdf->SetFont('Times', 'B', 16);
                $pdf->MultiCell($util, 7, $this->enc($nombre), 0, 'C');
                $pdf->Ln(1);
            }
        }

        // En su hoja la direccion ocupa dos renglones; una mas larga se recorta
        // para que el encabezado no empuje la tabla.
        $direccion = (string) preg_replace('/\s+/', ' ', $this->texto($this->emisor['direccion'] ?? ''));
        if ($direccion !== '') {
            $pdf->SetFont('Times', '', 10);
            $pdf->MultiCell($util, 4.5, $this->recortar($pdf, $this->enc($direccion), $util, 2), 0, 'C');
        }

        $rnc = $this->rnc($this->emisor['rnc'] ?? null);
        if ($rnc !== '') {
            $pdf->Ln(1);
            $pdf->SetFont('Times', 'B', 10);
            $pdf->Cell($util, 5, $this->enc('RNC ' . $rnc), 0, 1, 'L');
        }

        $pdf->Ln(1.5);
        $pdf->SetFont('Times', 'B', 14);
        $titulo = $this->esConduce() ? 'CONDUCE DE MERCANCÍA' : 'COTIZACIÓN MERCANCÍAS';
        $pdf->Cell($util, 7, $this->enc($titulo), 0, 1, 'C');
        $pdf->Ln(1.5);

        // Bloque izquierdo, como en su hoja: fecha, numero, rotulo y cliente.
        $pdf->SetFont('Times', 'B', 10);
        $fecha = $this->texto($this->cotizacion['date'] ?? '');
        if ($fecha !== '') {
            $pdf->Cell($util, self::ALTO_BLOQUE, $this->enc(FerreteriaFormato::fechaLarga($fecha)), 0, 1, 'L');
        }
        $pdf->Cell($util, self::ALTO_BLOQUE, $this->enc($this->codigo()), 0, 1, 'L');
        // El conduce dice de que cotizacion salio. Si esa cotizacion ya no
        // existe, cotizacion_code llega null y la linea no va.
        $origen = $this->esConduce() ? $this->texto($this->cotizacion['cotizacion_code'] ?? '') : '';
        if ($origen !== '') {
            $pdf->Cell($util, self::ALTO_BLOQUE, $this->enc('Cotización: ' . $origen), 0, 1, 'L');
        }
        $pdf->Cell($util, self::ALTO_BLOQUE, $this->enc('NOMBRE O RAZÓN SOCIAL'), 0, 1, 'L');
        $nombreCliente = $this->nombreCliente();
        if ($nombreCliente !== '') {
            $pdf->MultiCell($util, self::ALTO_BLOQUE, $this->enc($nombreCliente), 0, 'L');
        }
        $rncCliente = $this->rnc($this->cliente['rnc'] ?? null);
        if ($rncCliente !== '') {
            $pdf->Cell($util, self::ALTO_BLOQUE, $this->enc($rncCliente), 0, 1, 'L');
        }
        $pdf->Ln(3);
    }

    /** Filas de items + la marca "No hay mas productos", con sus saltos de pagina. */
    private function tabla(FerreteriaCotizacionHoja $pdf): void
    {
        $anchos = $this->anchos($pdf);
        $items = array_values($this->cotizacion['items'] ?? []);
        $ultima = count($items) - 1;

        $pdf->SetFont('Times', '', 10);
        // La marca va bajo Descripcion: segunda columna en la cotizacion, tercera en el conduce.
        $marca = $this->esConduce() ? ['', '', $this->enc(self::MARCA)] : ['', $this->enc(self::MARCA), '', ''];
        $altoMarca = $this->altoFila($pdf, $anchos, $marca);

        // La cabecera se dibuja junto con la fila que la sigue: asi nunca queda
        // sola al pie de una pagina y solo se repite donde continuan filas.
        $cabeceraPendiente = true;
        foreach ($items as $i => $item) {
            $celdas = $this->esConduce() ? $this->celdasConduce($item) : [
                number_format((float) ($item['quantity'] ?? 0), 2),
                $this->enc($this->texto($item['description'] ?? '')),
                // Hasta 4 decimales si los trae: con 2 fijos, 3 x 84.7458
                // se leeria "3 x 84.75 = 254.24" y la linea no sumaria.
                EcfDocumento::textoPrecio($item['amount'] ?? 0),
                number_format($this->valorLinea($i, $item), 2),
            ];
            $alto = $this->altoFila($pdf, $anchos, $celdas);
            // La marca va pegada a la ultima fila: si no caben las dos, pasan juntas.
            $necesario = $alto
                + ($i === $ultima ? $altoMarca : 0.0)
                + ($cabeceraPendiente ? self::ALTO_CABECERA : 0.0);
            if (!$pdf->cabe($necesario)) {
                $pdf->AddPage();
                $cabeceraPendiente = true;
            }
            if ($cabeceraPendiente) {
                $this->cabeceraTabla($pdf, $anchos);
                $cabeceraPendiente = false;
            }
            $this->fila($pdf, $anchos, $celdas, $this->esConduce() ? ['C', 'C', 'L'] : ['C', 'L', 'R', 'R'], $alto);
        }

        // Sin items (la validacion lo impide, pero el PDF no debe romperse) la
        // cabecera y la marca caben de sobra en la primera pagina.
        if ($cabeceraPendiente) {
            $this->cabeceraTabla($pdf, $anchos);
        }
        $this->fila($pdf, $anchos, $marca, $this->esConduce() ? ['C', 'C', 'L'] : ['C', 'L', 'C', 'C'], $altoMarca);
    }

    /** Totales + "Recibido por" + pie: un solo bloque que nunca se parte. */
    private function cierre(FerreteriaCotizacionHoja $pdf): void
    {
        // El conduce no lleva totales: su bloque es el espacio, "Recibido por" y el pie.
        $filas = $this->esConduce() ? [] : $this->filasTotales();
        $pie = $this->lineasPie();
        $alto = self::ESPACIO_TOTALES + count($filas) * self::ALTO_TOTAL
            + self::ESPACIO_RECIBIDO + self::ALTO_RECIBIDO
            + self::ESPACIO_PIE + count($pie) * self::ALTO_PIE;
        // Si no cabe pasa entero a una pagina nueva, sin cabecera de tabla:
        // ahi no continuan filas.
        if (!$pdf->cabe($alto)) {
            $pdf->AddPage();
        }
        $pdf->Ln(self::ESPACIO_TOTALES);

        // Rotulo bajo la columna Descripcion y valor bajo la ultima, como en su hoja.
        $anchos = $this->anchos($pdf);
        $xRotulo = self::MARGEN + $anchos[0];
        $xValor = $xRotulo + $anchos[1] + $anchos[2];
        $pdf->SetFillColor(self::AZUL[0], self::AZUL[1], self::AZUL[2]);
        foreach ($filas as [$rotulo, $valor, $esTotal]) {
            $y = $pdf->GetY();
            $pdf->SetXY($xRotulo, $y);
            $pdf->SetFont('Times', 'B', 10);
            $pdf->Cell($anchos[1], self::ALTO_TOTAL, $this->enc($rotulo), 0, 0, 'L');
            $pdf->SetXY($xValor, $y);
            $pdf->SetFont('Times', $esTotal ? 'B' : '', 10);
            $pdf->Cell($anchos[3], self::ALTO_TOTAL, number_format($valor, 2), 1, 1, 'R', true);
        }

        $pdf->Ln(self::ESPACIO_RECIBIDO);
        $y = $pdf->GetY();
        $pdf->SetFont('Times', 'B', 10);
        $pdf->Cell(26, self::ALTO_RECIBIDO, $this->enc('Recibido por:'), 0, 0, 'L');
        $pdf->Line(self::MARGEN + 26, $y + self::ALTO_RECIBIDO - 1, self::MARGEN + 110, $y + self::ALTO_RECIBIDO - 1);
        $pdf->SetXY(self::MARGEN, $y + self::ALTO_RECIBIDO);

        // Pie centrado que sigue al "Recibido por" (no es el Footer() de FPDF,
        // que se repetiria en cada pagina).
        $pdf->Ln(self::ESPACIO_PIE);
        $util = $this->anchoUtil($pdf);
        foreach ($pie as [$texto, $estilo, $esCorreo]) {
            $pdf->SetFont('Times', $estilo, 10);
            if (!$esCorreo) {
                $pdf->Cell($util, self::ALTO_PIE, $this->enc($texto), 0, 1, 'C');
                continue;
            }
            // El correo va azul, subrayado y como enlace mailto, igual que el
            // hipervinculo de su hoja. La celda mide lo que el texto para que
            // el enlace no ocupe todo el ancho del renglon.
            $ancho = min($util, $pdf->GetStringWidth($this->enc($texto)) + 2);
            $pdf->SetX(self::MARGEN + ($util - $ancho) / 2);
            $pdf->SetTextColor(self::AZUL_CORREO[0], self::AZUL_CORREO[1], self::AZUL_CORREO[2]);
            $pdf->Cell($ancho, self::ALTO_PIE, $this->enc($texto), 0, 1, 'C', false, 'mailto:' . $texto);
            $pdf->SetTextColor(0, 0, 0);
        }
    }

    /**
     * Filas de totales en el orden de la spec 6.2. Sub-total, ITBIS y TOTAL
     * siempre; cargos, retencion y abono solo con valor; Restante solo con
     * retencion o abono. Lo adeudado (TOTAL - retencion) no lleva fila propia:
     * Restante ya es TOTAL - retencion - abono.
     *
     * @return array<int,array{0:string,1:float,2:bool}> [rotulo, valor, esTotal]
     */
    private function filasTotales(): array
    {
        $t = $this->cotizacion['totales'] ?? [];
        $monto = static fn(string $k): float => (float) ($t[$k] ?? 0);

        $filas = [
            ['Sub-total RD$', $monto('subtotal'), false],
            [(string) ($t['etiqueta_itbis'] ?? 'ITBIS'), $monto('itbis'), false],
        ];
        $cargos = [
            'cargos_bancarios' => 'Cargos bancarios',
            'manejo_bancario'  => 'Manejos de operaciones bancarias',
            'mano_obra'        => 'Costo mano de obra',
        ];
        foreach ($cargos as $clave => $rotulo) {
            if ($monto($clave) > 0) {
                $filas[] = [$rotulo, $monto($clave), false];
            }
        }
        $filas[] = ['TOTAL RD$', $monto('total'), true];
        if ($monto('retencion_isr') > 0) {
            $filas[] = ['Retención Renta por Tercero 5%', $monto('retencion_isr'), false];
        }
        if ($monto('abono') > 0) {
            $filas[] = ['Abono realizado', $monto('abono'), false];
        }
        if (!empty($t['mostrar_restante'])) {
            $filas[] = ['Restante (Adeudado)', $monto('restante'), false];
        }
        return $filas;
    }

    /**
     * Pie: razon social (la legal, no nombre_comercial), correo y telefono;
     * cada parte solo si tiene valor.
     *
     * @return array<int,array{0:string,1:string,2:bool}> [texto, estilo FPDF, esCorreo]
     */
    private function lineasPie(): array
    {
        $lineas = [];
        $razon = $this->texto($this->emisor['razon_social'] ?? '');
        if ($razon !== '') {
            $lineas[] = [$razon, 'B', false];
        }
        $correo = $this->texto($this->emisor['correo'] ?? '');
        if ($correo !== '') {
            $lineas[] = [$correo, 'U', true];
        }
        $telefono = $this->texto($this->emisor['telefono'] ?? '');
        if ($telefono !== '') {
            $lineas[] = ['Teléfono ' . $telefono, '', false];
        }
        return $lineas;
    }

    private function cabeceraTabla(FerreteriaCotizacionHoja $pdf, array $anchos): void
    {
        $pdf->SetFont('Times', 'B', 10);
        $pdf->SetFillColor(self::AZUL[0], self::AZUL[1], self::AZUL[2]);
        $pdf->SetTextColor(0, 0, 0);
        $titulos = $this->esConduce()
            ? ['Cantidad', 'Unidad', 'Descripción mercancías']
            : ['Cantidad', 'Descripción mercancías', 'Valor Unitario', 'Valor Total RD$'];
        foreach ($titulos as $k => $titulo) {
            $pdf->Cell($anchos[$k], self::ALTO_CABECERA, $this->enc($titulo), 1, 0, 'C', true);
        }
        $pdf->Ln(self::ALTO_CABECERA);
        $pdf->SetFont('Times', '', 10);
    }

    /** Fila con borde en cada celda, tan alta como su celda mas envuelta. */
    private function fila(FerreteriaCotizacionHoja $pdf, array $anchos, array $celdas, array $alineacion, float $alto): void
    {
        $x = self::MARGEN;
        $y = $pdf->GetY();
        foreach ($celdas as $k => $txt) {
            $pdf->Rect($x, $y, $anchos[$k], $alto);
            // Centrado vertical: el precio de una descripcion de tres renglones
            // queda a media fila y no pegado arriba.
            $usado = $pdf->renglones($anchos[$k], $txt) * self::ALTO_RENGLON;
            $pdf->SetXY($x, $y + max(0.0, ($alto - $usado) / 2));
            $pdf->MultiCell($anchos[$k], self::ALTO_RENGLON, $txt, 0, $alineacion[$k]);
            $x += $anchos[$k];
        }
        $pdf->SetXY(self::MARGEN, $y + $alto);
    }

    private function altoFila(FerreteriaCotizacionHoja $pdf, array $anchos, array $celdas): float
    {
        $renglones = 1;
        foreach ($celdas as $k => $txt) {
            $renglones = max($renglones, $pdf->renglones($anchos[$k], $txt));
        }
        return $renglones * self::ALTO_RENGLON;
    }

    /**
     * @return float[] Cotizacion: Cantidad | Descripcion | Valor Unitario | Valor Total.
     *                 Conduce: Cantidad | Unidad | Descripcion.
     */
    private function anchos(FerreteriaCotizacionHoja $pdf): array
    {
        if ($this->esConduce()) {
            return [self::ANCHO_CANTIDAD, self::ANCHO_UNIDAD, $this->anchoUtil($pdf) - self::ANCHO_CANTIDAD - self::ANCHO_UNIDAD];
        }
        $descripcion = $this->anchoUtil($pdf) - self::ANCHO_CANTIDAD - self::ANCHO_UNITARIO - self::ANCHO_TOTAL;
        return [self::ANCHO_CANTIDAD, $descripcion, self::ANCHO_UNITARIO, self::ANCHO_TOTAL];
    }

    private function anchoUtil(FerreteriaCotizacionHoja $pdf): float
    {
        return $pdf->GetPageWidth() - 2 * self::MARGEN;
    }

    /** documento => 'conduce'. Sin esa clave, o con 'cotizacion', es la cotizacion de siempre. */
    private function esConduce(): bool
    {
        return ($this->cotizacion['documento'] ?? null) === 'conduce';
    }

    /**
     * Cantidad | Unidad | Descripcion de una linea del conduce. Nunca el precio,
     * aunque el item lo traiga: el conduce va con la mercancia. La unidad llega
     * con su nombre ya resuelto (FerreteriaConduce::itemsPdf), asi que aqui no
     * se lee el catalogo y el renderizador sigue siendo puro.
     */
    private function celdasConduce(array $item): array
    {
        return [
            number_format((float) ($item['quantity'] ?? 0), 2),
            $this->enc($this->texto($item['unidad'] ?? '')),
            $this->enc($this->texto($item['description'] ?? '')),
        ];
    }

    /** Base de la linea tal como la calculo totales(); sin ella, la misma regla (spec 6.2). */
    private function valorLinea(int $i, array $item): float
    {
        $base = $this->cotizacion['totales']['lineas'][$i]['base'] ?? null;
        if ($base !== null) {
            return (float) $base;
        }
        return Redondeo::r2(Redondeo::r2((float) ($item['quantity'] ?? 0)) * Redondeo::r4((float) ($item['amount'] ?? 0)));
    }

    /**
     * Tamano del logo dentro de su caja, respetando la proporcion (misma
     * matematica que FacturaTemplate::drawLogo). Null si no hay archivo o no es
     * una imagen que FPDF sepa leer: entonces se imprime la razon social.
     *
     * @return array{w:float,h:float,tipo:string}|null
     */
    private function medidasLogo(): ?array
    {
        if ($this->logoFallo || $this->logoPath === null || !is_file($this->logoPath)) {
            return null;
        }
        $info = @getimagesize($this->logoPath);
        if (!$info || (int) $info[0] <= 0 || (int) $info[1] <= 0) {
            return null;
        }
        $tipos = [IMAGETYPE_JPEG => 'JPG', IMAGETYPE_PNG => 'PNG', IMAGETYPE_GIF => 'GIF'];
        $tipo = $tipos[$info[2]] ?? null;
        if ($tipo === null) {
            return null;
        }
        $ratio = $info[1] / $info[0];
        $w = self::LOGO_MAX_W;
        $h = $w * $ratio;
        if ($h > self::LOGO_MAX_H) {
            $h = self::LOGO_MAX_H;
            $w = $h / $ratio;
        }
        return ['w' => $w, 'h' => $h, 'tipo' => $tipo];
    }

    /**
     * Dibuja el logo. FPDF lanza con imagenes que no soporta (p.ej. PNG
     * entrelazado): un logo raro no puede tumbar la cotizacion, sale con la
     * razon social en su lugar.
     */
    private function ponerLogo(FerreteriaCotizacionHoja $pdf, array $logo, float $x, float $y): bool
    {
        try {
            $pdf->Image((string) $this->logoPath, $x, $y, $logo['w'], $logo['h'], $logo['tipo']);
            return true;
        } catch (\Throwable $e) {
            error_log('[FerreteriaCotizacionPdf] logo ilegible (' . $this->logoPath . '): ' . $e->getMessage());
            $this->logoFallo = true;
            return false;
        }
    }

    /** Recorta $texto (ISO-8859-1) con "..." para que ocupe a lo sumo $max renglones de $ancho mm. */
    private function recortar(FerreteriaCotizacionHoja $pdf, string $texto, float $ancho, int $max): string
    {
        if ($pdf->renglones($ancho, $texto) <= $max) {
            return $texto;
        }
        $palabras = explode(' ', $texto);
        while (count($palabras) > 1) {
            array_pop($palabras);
            $corto = rtrim(implode(' ', $palabras), ' ,.;-') . '...';
            if ($pdf->renglones($ancho, $corto) <= $max) {
                return $corto;
            }
        }
        // Una sola "palabra" enorme: se corta por caracteres.
        $corto = $palabras[0];
        while (strlen($corto) > 1 && $pdf->renglones($ancho, $corto . '...') > $max) {
            $corto = substr($corto, 0, -1);
        }
        return $corto . '...';
    }

    private function codigo(): string
    {
        $code = $this->texto($this->cotizacion['code'] ?? '');
        return $code === '' ? 'VISTA PREVIA' : $code;
    }

    /** razon_social, si no company_name, si no client_name (el primero con texto). */
    private function nombreCliente(): string
    {
        foreach (['razon_social', 'company_name', 'client_name'] as $campo) {
            $valor = $this->texto($this->cliente[$campo] ?? '');
            if ($valor !== '') {
                return $valor;
            }
        }
        return '';
    }

    private function rnc($valor): string
    {
        return FerreteriaFormato::formatearRnc(is_scalar($valor) ? (string) $valor : null);
    }

    private function texto($valor): string
    {
        // Mismo criterio que validarForma: un salto de linea en una fila vieja o
        // en un dato del emisor no debe estirar la celda (ver limpiarDescripcion).
        return is_scalar($valor) ? FerreteriaFormato::limpiarDescripcion((string) $valor) : '';
    }

    /** UTF-8 -> ISO-8859-1 (fuentes core de FPDF). */
    private function enc(string $s): string
    {
        return mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8');
    }
}
