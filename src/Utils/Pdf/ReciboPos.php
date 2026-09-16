<?php
require_once __DIR__ . '/libs.php';
require_once __DIR__ . '/EcfDocumento.php';
require_once __DIR__ . '/BrandingResolver.php';
require_once __DIR__ . '/FacturaTemplateFactory.php';

/**
 * Representacion Impresa en tirilla POS (impresora de rollo): 80, 76 o 72 mm.
 *
 * Mismo contenido que la RI de carta — lo dicta EcfDocumento, no esta clase —
 * pero en una sola columna y en rollo continuo. Todo lo que la norma DGII exige
 * que aparezca aparece: titulo del documento, e-NCF, RNC y razon social del
 * emisor, fechas de emision y vencimiento, comprador, el detalle con sus seis
 * datos (cantidad, descripcion, unidad, precio, ITBIS, valor), los totales con
 * sus etiquetas exactas, y el timbre (QR + codigo de seguridad + fecha de
 * firma). En una tirilla no caben seis columnas, asi que cada linea se apila en
 * dos renglones; el dato no se pierde, cambia de sitio.
 *
 * ANCHO: la pagina mide lo que el driver deja imprimir, no lo que mide el rollo
 * (ver MEDIDAS). Que la pagina coincida con esa area es lo que deja imprimir al
 * 100 % sin que el navegador reescale ni recorte.
 *
 * ALTO VARIABLE: una tirilla no tiene "hoja". Se dibuja dos veces — una pasada
 * de medicion sobre un lienzo largo para saber donde termina el contenido, y la
 * definitiva sobre una pagina de ese alto exacto. Sin eso el driver de la
 * termica alimenta papel en blanco hasta completar la pagina y corta lejos del
 * ultimo renglon.
 */
final class ReciboPos
{
    /** Lo que se imprime cuando se pide la tirilla sin decir el ancho. */
    public const ANCHO_POR_DEFECTO = 80;

    /**
     * Rollo que se elige (mm) => [ancho de la pagina, margen lateral] (mm). El
     * ancho util es la pagina menos dos margenes.
     *
     * La pagina tiene que medir lo que el DRIVER deja imprimir, no lo que mide
     * el rollo: con "Tamano real", Chrome pega la pagina del PDF al borde
     * izquierdo del area imprimible y corta lo que pase de su ancho, sin
     * centrar. Verificado con una TM-U220 (papel "76(63.5) x 3276 mm"): una
     * pagina de 76 mm con 6 mm de margen salio con 6 mm en blanco a la
     * izquierda y 6,5 mm cortados a la derecha, justo donde van los montos.
     *
     *  - 80: pagina de rollo completo, 72 mm utiles (sin verificar en papel).
     *  - 76: impacto tipo TM-U220; el driver imprime 63,5 mm. Verificado.
     *  - 72: rollo angosto, 64 mm utiles (sin verificar en papel).
     *
     * Para ajustar un modelo que corte o deje demasiado blanco, se toca solo
     * esta tabla: el numero entre parentesis del papel en el dialogo de
     * impresion es el ancho de pagina correcto. Todo el dibujo sale de aqui.
     */
    private const MEDIDAS = [
        80 => [80.0, 4.0],
        76 => [63.5, 1.0],
        72 => [72.0, 4.0],
    ];

    /**
     * Anchos internos, pensados sobre los 72 mm utiles del rollo de 80 y
     * escalados al ancho util de cada rollo. En 80 mm salen identicos a los de
     * siempre.
     */
    private const REFERENCIA_UTIL = 72.0;
    private const DETALLE_IZQUIERDA = 44.0;
    private const TOTALES_ETIQUETA = 38.0;

    /** Lienzo de la pasada de medicion. */
    private const ALTO_MEDICION = 4000.0;
    /** Tope del formato PDF: 14400 unidades de 1/72" = 5080 mm. */
    private const ALTO_MAXIMO = 5000.0;
    /**
     * Alto minimo para que la tirilla no salga ridiculamente corta. Nunca menor
     * que el ancho: FPDF ordena [ancho, alto] de menor a mayor, y una pagina mas
     * ancha que alta saldria girada.
     */
    private const ALTO_MINIMO = 80.0;

    private EcfDocumento $doc;
    private string $fuente;
    private ?string $qrPng = null;
    private float $anchoPagina;
    private float $margen;
    private float $util;

    public function __construct(EcfDocumento $doc, int $ancho = self::ANCHO_POR_DEFECTO)
    {
        if (!self::anchoValido($ancho)) {
            throw new InvalidArgumentException(
                "Ancho de tirilla no soportado: {$ancho} mm (validos: " . implode(', ', self::anchos()) . ').'
            );
        }
        $this->doc = $doc;
        [$this->anchoPagina, $this->margen] = self::MEDIDAS[$ancho];
        $this->util = $this->anchoPagina - 2 * $this->margen;

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

    /** @return int[] Anchos de rollo soportados, en mm. */
    public static function anchos(): array
    {
        return array_keys(self::MEDIDAS);
    }

    public static function anchoValido(int $ancho): bool
    {
        return isset(self::MEDIDAS[$ancho]);
    }

    /**
     * @param array $factura       Fila de facturas + 'items' (+ 'xml_firmado').
     * @param array $cliente       Fila de clients (vacio = se resuelve por client_id).
     * @param bool  $noElectronica Factura simple / NCF tradicional.
     * @param int   $ancho         Ancho del rollo en mm (ver anchos()).
     */
    public static function paraFactura(
        array $factura,
        array $cliente = [],
        bool $noElectronica = false,
        int $ancho = self::ANCHO_POR_DEFECTO
    ): self {
        return new self(new EcfDocumento($factura, $cliente, $noElectronica), $ancho);
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
            $alto = $medicion->GetY() + $this->margen;
            $alto = max(self::ALTO_MINIMO, $this->anchoPagina, min(self::ALTO_MAXIMO, $alto));

            return $this->dibujar($alto, $timbre)->Output('S');
        } finally {
            if ($this->qrPng !== null) {
                @unlink($this->qrPng);
                $this->qrPng = null;
            }
        }
    }

    /**
     * Dibuja la tirilla completa sobre una pagina de anchoPagina x $alto mm.
     * @param array{url:string,codigo_seguridad:string,fecha_firma:string,preview:bool}|null $timbre
     */
    private function dibujar(float $alto, ?array $timbre): FPDF
    {
        $pdf = new FPDF('P', 'mm', [$this->anchoPagina, $alto]);
        // Sin salto automatico: la tirilla es una sola pagina, y en la pasada de
        // medicion un salto falsearia el alto que estamos calculando.
        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins($this->margen, $this->margen, $this->margen);
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
            $pdf->MultiCell($this->util, 4, $this->enc($emisor['razon_social']), 0, 'C');
        }

        $pdf->SetFont($this->fuente, '', 6.5);
        if (trim($emisor['rnc']) !== '') {
            $pdf->Cell($this->util, 3, $this->enc('RNC: ' . $emisor['rnc']), 0, 1, 'C');
        }
        if (trim($emisor['direccion']) !== '') {
            $pdf->MultiCell($this->util, 3, $this->enc($emisor['direccion']), 0, 'C');
        }
        $contacto = array_filter([
            trim($emisor['telefono']) !== '' ? 'Tel.: ' . $emisor['telefono'] : '',
            trim($emisor['correo']),
        ]);
        if ($contacto !== []) {
            $pdf->MultiCell($this->util, 3, $this->enc(implode(' - ', $contacto)), 0, 'C');
        }

        $this->separador($pdf);
    }

    private function identificacion(FPDF $pdf): void
    {
        $doc = $this->doc;

        $pdf->SetFont($this->fuente, 'B', 8);
        $pdf->MultiCell($this->util, 3.6, $this->enc(mb_strtoupper($doc->titulo(), 'UTF-8')), 0, 'C');
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
            $pdf->MultiCell($this->util, 3.2, $this->enc($r['contacto']), 0, 'L');
        }

        $this->separador($pdf);
    }

    /**
     * Detalle. Cada linea ocupa dos renglones: la descripcion completa arriba y,
     * debajo, "cantidad UND x precio" a la izquierda con el valor a la derecha
     * (y el ITBIS de la linea cuando lo hay). Asi caben en el ancho de la
     * tirilla los seis datos que la norma pide por linea sin recortar la
     * descripcion.
     */
    private function detalle(FPDF $pdf): void
    {
        $anchoIzq = $this->escalado(self::DETALLE_IZQUIERDA);
        $anchoDer = $this->util - $anchoIzq;

        $pdf->SetFont($this->fuente, 'B', 6.5);
        $pdf->Cell($anchoIzq, 3.2, $this->enc('CANT. x PRECIO'), 0, 0, 'L');
        $pdf->Cell($anchoDer, 3.2, 'VALOR', 0, 1, 'R');
        $this->separador($pdf, 0.5);

        foreach ($this->doc->lineas() as $linea) {
            $pdf->SetFont($this->fuente, '', 7);
            $pdf->MultiCell($this->util, 3.2, $this->enc(html_entity_decode($linea['descripcion'])), 0, 'L');

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
                $pdf->Cell($this->util, 3.2, $this->enc($itbis), 0, 1, 'R');
            }
            $pdf->Ln(0.8);
        }

        // Motivo de la nota E33/E34 que no cupo dentro de una linea.
        $motivo = $this->doc->motivoEnFilaAparte();
        if ($motivo !== '') {
            $pdf->SetFont($this->fuente, '', 6.5);
            $pdf->MultiCell($this->util, 3.2, $this->enc('Motivo: ' . $motivo), 0, 'L');
        }

        $this->separador($pdf);
    }

    private function totales(FPDF $pdf): void
    {
        $filas = [];
        $valorMasAncho = 0.0;
        foreach ($this->doc->filasTotales() as [$etiqueta, $valor, $esTotal]) {
            $texto = ($esTotal ? 'RD$' : '') . number_format((float) $valor, 2);
            $pdf->SetFont($this->fuente, $esTotal ? 'B' : '', $esTotal ? 9 : 7);
            $valorMasAncho = max($valorMasAncho, $pdf->GetStringWidth($texto));
            $filas[] = [$etiqueta, $texto, $esTotal];
        }

        // La columna de etiquetas solo cede cuando un monto no cabe en la suya:
        // en Courier, "RD$99,999,999.99" en negrita ocupa 30,5 mm y en 61,5 mm
        // utiles no entra en la proporcion de siempre. Las etiquetas son cortas
        // ("Subtotal Gravado:" tiene holgura de sobra); los montos no se recortan.
        $anchoEtiqueta = min(
            $this->escalado(self::TOTALES_ETIQUETA),
            $this->util - ($valorMasAncho + 2)
        );
        $anchoValor = $this->util - $anchoEtiqueta;

        foreach ($filas as [$etiqueta, $texto, $esTotal]) {
            $pdf->SetFont($this->fuente, $esTotal ? 'B' : '', $esTotal ? 9 : 7);
            $pdf->Cell($anchoEtiqueta, $esTotal ? 5 : 3.6, $this->enc($etiqueta . ':'), 0, 0, 'R');
            $pdf->Cell($anchoValor, $esTotal ? 5 : 3.6, $this->enc($texto), 0, 1, 'R');
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
            // El QR no se achica con el rollo: su tamano es el que asegura que
            // el lector lo tome, y 26 mm caben en el ancho util mas angosto.
            $lado = 26.0;
            $pdf->Image($this->qrPng, $this->margen + ($this->util - $lado) / 2, $pdf->GetY(), $lado, $lado, 'PNG');
            $pdf->SetY($pdf->GetY() + $lado + 1.5);
        }

        if ($timbre['preview']) {
            $pdf->SetFont($this->fuente, 'B', 7);
            $pdf->MultiCell($this->util, 3.2, $this->enc('VISTA PREVIA - SIN VALIDEZ FISCAL'), 0, 'C');
        }

        $pdf->SetFont($this->fuente, 'B', 6.5);
        $pdf->Cell($this->util, 3.2, $this->enc('Código de Seguridad'), 0, 1, 'C');
        $pdf->SetFont($this->fuente, '', 8);
        $pdf->Cell($this->util, 3.6, $this->enc($timbre['codigo_seguridad']), 0, 1, 'C');

        $pdf->SetFont($this->fuente, 'B', 6.5);
        $pdf->Cell($this->util, 3.2, 'Fecha de Firma', 0, 1, 'C');
        $pdf->SetFont($this->fuente, '', 7);
        $pdf->Cell($this->util, 3.2, $timbre['fecha_firma'] !== '' ? $timbre['fecha_firma'] : 'N/D', 0, 1, 'C');

        $this->separador($pdf);
    }

    private function pie(FPDF $pdf): void
    {
        $pdf->SetFont($this->fuente, '', 6);
        if ($this->doc->esElectronica()) {
            $pdf->MultiCell($this->util, 2.8, $this->enc('Consulte la validez de este comprobante escaneando el código QR en el portal de la DGII.'), 0, 'C');
        }
        $pdf->Ln(1);
        $pdf->MultiCell($this->util, 2.8, $this->enc('¡Gracias por su compra!'), 0, 'C');
    }

    // ------------------------------------------------------------------
    // Utilidades de dibujo
    // ------------------------------------------------------------------

    /** Un ancho pensado para 72 mm utiles, llevado al ancho util de este rollo. */
    private function escalado(float $mm): float
    {
        return $mm * $this->util / self::REFERENCIA_UTIL;
    }

    /**
     * Bloque de pares "Etiqueta: valor", alineados entre si.
     *
     * La columna de etiquetas se mide sobre el texto real del bloque en vez de
     * fijarse en un ancho a ojo: etiquetas como "Fecha de Vencimiento" o
     * "Identificación Tributaria" (E47) se comian el valor con un ancho fijo.
     * Se topa en el 55% del papel para que al valor siempre le quede sitio.
     *
     * Una etiqueta que no cabe ni en ese tope (en Courier, "Identificación
     * Tributaria:" mide 38,5 mm y en 61,5 mm utiles el tope es 33,8) va en su
     * propio renglon con el valor debajo: montada sobre el valor no se leeria.
     *
     * @param array<int,array{0:string,1:string}> $pares
     */
    private function bloquePares(FPDF $pdf, array $pares): void
    {
        if ($pares === []) {
            return;
        }
        $tope = $this->util * 0.55;
        $pdf->SetFont($this->fuente, 'B', 7);
        $ancho = 0.0;
        foreach ($pares as [$etiqueta, ]) {
            $medida = $pdf->GetStringWidth($this->enc($etiqueta . ':'));
            if ($medida + 1 <= $tope) {
                $ancho = max($ancho, $medida);
            }
        }
        $ancho = min($ancho + 1.5, $tope);

        foreach ($pares as [$etiqueta, $valor]) {
            $y = $pdf->GetY();
            $pdf->SetFont($this->fuente, 'B', 7);
            $pdf->SetXY($this->margen, $y);
            if ($pdf->GetStringWidth($this->enc($etiqueta . ':')) + 1 > $tope) {
                $pdf->Cell($this->util, 3.2, $this->enc($etiqueta . ':'), 0, 1, 'L');
                $pdf->SetFont($this->fuente, '', 7);
                $pdf->MultiCell($this->util, 3.2, $this->enc($valor), 0, 'L');
                continue;
            }
            $pdf->Cell($ancho, 3.2, $this->enc($etiqueta . ':'), 0, 0, 'L');
            $pdf->SetFont($this->fuente, '', 7);
            $pdf->MultiCell($this->util - $ancho, 3.2, $this->enc($valor), 0, 'L');
        }
    }

    private function separador(FPDF $pdf, float $espacio = 1.2): void
    {
        $y = $pdf->GetY() + $espacio;
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetLineWidth(0.15);
        $pdf->Line($this->margen, $y, $this->anchoPagina - $this->margen, $y);
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
        $pdf->Image($ruta, $this->margen + ($this->util - $w) / 2, $pdf->GetY(), $w, $h);
        $pdf->SetY($pdf->GetY() + $h + 1.5);
    }

    /** UTF-8 -> ISO-8859-1 (fuentes core de FPDF). */
    private function enc(string $s): string
    {
        return mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8');
    }
}
