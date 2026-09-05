<?php
require_once __DIR__ . '/libs.php';
require_once __DIR__ . '/BrandingResolver.php';
require_once __DIR__ . '/../../Models/EmisorConfigModel.php';

/**
 * Reporte de ventas en PDF, listo para imprimir o archivar.
 *
 * Lleva el membrete del emisor (logo, razon social, RNC) porque es un documento
 * que sale de la empresa: se manda al contable o se archiva, y sin identificar
 * de quien es no sirve para eso.
 *
 * El detalle va apaisado (diez columnas no caben a lo ancho de una carta) y las
 * agrupaciones en vertical, que es lo que pide su contenido. La banda de
 * encabezado de la tabla se repite en cada pagina: un reporte de 300 lineas sin
 * eso obliga a volver a la primera hoja para saber que columna es cual.
 */
class ReporteVentasPdf extends FPDF
{
    /** @var array<int,array{ancho:float,titulo:string,campo:string,alin:string,dinero:bool}> */
    private array $columnas = [];
    private string $titulo = '';
    private string $subtitulo = '';
    private array $emisor = [];
    private ?string $logo = null;

    public function __construct(string $orientacion)
    {
        parent::__construct($orientacion, 'mm', 'Letter');
        $this->emisor = $this->cargarEmisor();
        $this->logo = BrandingResolver::logoPath();
        $this->SetAutoPageBreak(true, 18);
        $this->AliasNbPages();
    }

    /**
     * @param array<int,array{ancho:float,titulo:string,campo:string,alin?:string,dinero?:bool}> $columnas
     */
    public function configurar(string $titulo, string $subtitulo, array $columnas): void
    {
        $this->titulo = $titulo;
        $this->subtitulo = $subtitulo;
        $this->columnas = array_map(static fn(array $c): array => [
            'ancho'  => (float) $c['ancho'],
            'titulo' => (string) $c['titulo'],
            'campo'  => (string) $c['campo'],
            'alin'   => (string) ($c['alin'] ?? 'L'),
            'dinero' => (bool) ($c['dinero'] ?? false),
        ], $columnas);
    }

    /** Membrete + titulo del reporte + banda de columnas. Corre en cada pagina. */
    public function Header(): void
    {
        $anchoUtil = $this->GetPageWidth() - $this->lMargin - $this->rMargin;

        if ($this->logo !== null) {
            $this->dibujarLogo($this->logo, $this->lMargin, 8, 38, 13);
        }

        $this->SetY(8);
        $this->SetFont('Arial', 'B', 13);
        $this->Cell($anchoUtil, 5, $this->enc($this->titulo), 0, 1, 'R');
        $this->SetFont('Arial', '', 8.5);
        $this->SetTextColor(90, 90, 90);
        $this->Cell($anchoUtil, 4, $this->enc($this->subtitulo), 0, 1, 'R');

        // Identificacion del emisor a la izquierda, bajo el logo.
        $this->SetY(8 + ($this->logo !== null ? 14 : 0));
        $this->SetFont('Arial', 'B', 9);
        $this->SetTextColor(0, 0, 0);
        $this->Cell($anchoUtil * 0.6, 4, $this->enc($this->emisor['razon_social']), 0, 1, 'L');
        $this->SetFont('Arial', '', 8);
        $this->SetTextColor(90, 90, 90);
        if ($this->emisor['rnc'] !== '') {
            $this->Cell($anchoUtil * 0.6, 3.6, 'RNC: ' . $this->emisor['rnc'], 0, 1, 'L');
        }

        $this->SetTextColor(0, 0, 0);
        $this->SetY(max($this->GetY() + 3, 28));
        $this->bandaColumnas();
    }

    public function Footer(): void
    {
        $this->SetY(-13);
        $this->SetFont('Arial', '', 7.5);
        $this->SetTextColor(120, 120, 120);
        $anchoUtil = $this->GetPageWidth() - $this->lMargin - $this->rMargin;
        $this->Cell($anchoUtil / 2, 4, $this->enc('Generado el ' . date('d/m/Y H:i')), 0, 0, 'L');
        $this->Cell($anchoUtil / 2, 4, $this->enc('Página ' . $this->PageNo() . ' de {nb}'), 0, 0, 'R');
        $this->SetTextColor(0, 0, 0);
    }

    private function bandaColumnas(): void
    {
        $this->SetFont('Arial', 'B', 7.5);
        $this->SetFillColor(35, 40, 55);
        $this->SetTextColor(255, 255, 255);
        foreach ($this->columnas as $c) {
            $this->Cell($c['ancho'], 6, $this->enc($c['titulo']), 0, 0, $c['alin'], true);
        }
        $this->Ln();
        $this->SetTextColor(0, 0, 0);
    }

    /**
     * @param array<int,array<string,mixed>> $filas
     * @param array{cantidad:int,base:float,itbis:float,total:float} $totales
     */
    public function tabla(array $filas, array $totales): void
    {
        $this->SetFont('Arial', '', 7.5);
        $alterna = false;

        foreach ($filas as $f) {
            // El alto de la fila lo manda la celda que mas envuelva (nombres de
            // cliente largos), para que ninguna se corte.
            $lineas = 1;
            foreach ($this->columnas as $c) {
                $texto = $this->valorCelda($f, $c);
                $lineas = max($lineas, $this->numeroLineas($c['ancho'], $texto));
            }
            $alto = 4 * $lineas;

            if ($this->GetY() + $alto > $this->PageBreakTrigger) {
                $this->AddPage($this->CurOrientation);
                $this->SetFont('Arial', '', 7.5);
            }

            // Zebra: en una tabla de diez columnas sin lineas verticales es lo
            // unico que evita saltar de fila al leer de izquierda a derecha.
            $alterna = !$alterna;
            if ($alterna) {
                $this->SetFillColor(246, 247, 249);
                $this->Rect($this->lMargin, $this->GetY(), $this->anchoTabla(), $alto, 'F');
            }

            $y = $this->GetY();
            $x = $this->lMargin;
            $negativo = isset($f['total']) && (float) $f['total'] < 0;
            foreach ($this->columnas as $c) {
                $texto = $this->valorCelda($f, $c);
                // Una nota de credito resta: en rojo se ve de un vistazo por que
                // el total de la columna no es la suma de lo que se ve.
                if ($negativo && $c['dinero']) {
                    $this->SetTextColor(190, 40, 40);
                }
                $this->SetXY($x, $y);
                $this->MultiCell($c['ancho'], 4, $this->enc($texto), 0, $c['alin']);
                $this->SetTextColor(0, 0, 0);
                $x += $c['ancho'];
            }
            $this->SetXY($this->lMargin, $y + $alto);
        }

        $this->filaTotales($totales);
    }

    private function filaTotales(array $totales): void
    {
        if ($this->GetY() + 8 > $this->PageBreakTrigger) {
            $this->AddPage($this->CurOrientation);
        }
        $this->SetFont('Arial', 'B', 8);
        $this->SetFillColor(232, 235, 240);
        $this->Rect($this->lMargin, $this->GetY(), $this->anchoTabla(), 6.5, 'F');

        $y = $this->GetY();
        $x = $this->lMargin;
        $primera = true;
        foreach ($this->columnas as $c) {
            $texto = '';
            if ($primera) {
                $texto = 'TOTAL (' . $totales['cantidad'] . ' doc.)';
                $primera = false;
            } elseif ($c['campo'] === 'cantidad') {
                $texto = (string) $totales['cantidad'];
            } elseif (in_array($c['campo'], ['base', 'itbis', 'total'], true)) {
                $texto = number_format((float) $totales[$c['campo']], 2);
            } elseif ($c['campo'] === 'porcentaje') {
                $texto = '100%';
            }
            $this->SetXY($x, $y);
            $this->Cell($c['ancho'], 6.5, $this->enc($texto), 0, 0, $c['alin']);
            $x += $c['ancho'];
        }
        $this->SetXY($this->lMargin, $y + 6.5);
    }

    /** Nota al pie con las reglas del reporte: sin esto los totales no se pueden auditar. */
    public function notaAlPie(string $texto): void
    {
        $this->Ln(4);
        $this->SetFont('Arial', '', 6.5);
        $this->SetTextColor(120, 120, 120);
        $this->MultiCell($this->anchoTabla(), 3.2, $this->enc($texto), 0, 'L');
        $this->SetTextColor(0, 0, 0);
    }

    public function aviso(string $texto): void
    {
        $this->Ln(2);
        $this->SetFont('Arial', 'I', 7);
        $this->SetTextColor(150, 100, 20);
        $this->MultiCell($this->anchoTabla(), 3.4, $this->enc($texto), 0, 'L');
        $this->SetTextColor(0, 0, 0);
    }

    public function contenido(): string
    {
        return $this->Output('S');
    }

    // ------------------------------------------------------------------

    private function anchoTabla(): float
    {
        return array_sum(array_column($this->columnas, 'ancho'));
    }

    private function valorCelda(array $fila, array $col): string
    {
        $v = $fila[$col['campo']] ?? '';
        if ($col['campo'] === 'porcentaje') {
            return $v === '' ? '' : $v;
        }
        if ($col['dinero']) {
            return number_format((float) $v, 2);
        }
        if ($col['campo'] === 'fecha') {
            $ts = strtotime((string) $v);
            return $ts ? date('d/m/Y', $ts) : (string) $v;
        }
        return (string) $v;
    }

    /** Cuantas lineas ocupa un texto en un ancho dado (mismo criterio que MultiCell). */
    private function numeroLineas(float $ancho, string $texto): int
    {
        $texto = $this->enc($texto);
        $cw = $this->CurrentFont['cw'];
        $max = ($ancho - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $largo = strlen($texto);
        $sep = -1; $i = 0; $j = 0; $l = 0; $nl = 1;
        while ($i < $largo) {
            $c = $texto[$i];
            if ($c === "\n") { $i++; $sep = -1; $j = $i; $l = 0; $nl++; continue; }
            if ($c === ' ') { $sep = $i; }
            $l += $cw[$c] ?? 0;
            if ($l > $max) {
                if ($sep === -1) { if ($i === $j) { $i++; } } else { $i = $sep + 1; }
                $sep = -1; $j = $i; $l = 0; $nl++;
            } else {
                $i++;
            }
        }
        return $nl;
    }

    private function dibujarLogo(string $ruta, float $x, float $y, float $maxW, float $maxH): void
    {
        $info = @getimagesize($ruta);
        $w = $maxW; $h = $maxH;
        if ($info && (int) $info[0] > 0 && (int) $info[1] > 0) {
            $ratio = $info[1] / $info[0];
            $h = $maxW * $ratio;
            if ($h > $maxH) { $h = $maxH; $w = $maxH / $ratio; }
        }
        $this->Image($ruta, $x, $y, $w, $h);
    }

    /**
     * Emisor para el membrete. Si la BD no responde el reporte sale igual, solo
     * sin membrete: un reporte de ventas no puede caerse por eso.
     */
    private function cargarEmisor(): array
    {
        try {
            $e = extension_loaded('pdo_mysql') ? ((new EmisorConfigModel())->get() ?: []) : [];
        } catch (\Throwable $ex) {
            $e = [];
        }
        return [
            'razon_social' => (string) ($e['nombre_comercial'] ?? $e['razon_social'] ?? ''),
            'rnc'          => (string) ($e['rnc'] ?? ''),
        ];
    }

    private function enc(string $s): string
    {
        return mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8');
    }
}
