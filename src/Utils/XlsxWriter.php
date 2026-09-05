<?php

/**
 * Escritor minimo de archivos .xlsx (Excel nativo), sin dependencias.
 *
 * El proyecto no usa Composer, asi que no hay PhpSpreadsheet. Un .xlsx es un
 * ZIP con unos cuantos XML dentro, y para una tabla con encabezado, numeros y
 * una fila de totales eso son doscientas lineas — mucho menos que vendorizar
 * una libreria entera.
 *
 * El ZIP se arma a mano en vez de con ZipArchive: esa extension puede no estar
 * en un hosting compartido y no hay forma de comprobarlo desde aqui. zlib si
 * esta (FPDF ya comprime los PDF con gzcompress), y si faltara se guarda sin
 * comprimir — el archivo sale mas grande pero igual de valido.
 *
 * NO es CSV con otro nombre: Excel lo abre sin avisos de formato, los montos
 * son numeros de verdad (se pueden sumar) y los encabezados van fijos al hacer
 * scroll.
 */
class XlsxWriter
{
    /** Estilos disponibles para las celdas (indices de cellXfs en styles.xml). */
    public const NORMAL     = 0;
    public const ENCABEZADO = 1;
    public const DINERO     = 2;
    public const DINERO_TOT = 3;
    public const TITULO     = 4;
    public const NEGRITA    = 5;

    private string $hoja;
    /** @var array<int,array<int,array{0:mixed,1:int}>> filas => celdas [valor, estilo] */
    private array $filas = [];
    /** @var array<int,float> anchos de columna (en caracteres) */
    private array $anchos = [];
    private int $filasFijas = 0;

    public function __construct(string $nombreHoja = 'Hoja1')
    {
        // Excel rechaza estos caracteres en el nombre de la hoja y el limite son
        // 31 caracteres; sanear aqui evita un archivo que no abre.
        $limpio = preg_replace('/[\\\\\/\?\*\[\]:]/', '-', $nombreHoja);
        $this->hoja = mb_substr($limpio, 0, 31) ?: 'Hoja1';
    }

    /**
     * Agrega una fila. Cada celda es el valor suelto o [valor, estilo].
     * Los numeros salen como numero; el resto como texto.
     */
    public function fila(array $celdas): void
    {
        $normalizadas = [];
        foreach ($celdas as $c) {
            $normalizadas[] = is_array($c) ? [$c[0], (int) ($c[1] ?? self::NORMAL)] : [$c, self::NORMAL];
        }
        $this->filas[] = $normalizadas;
    }

    /** Fila vacia (separadora). */
    public function filaVacia(): void
    {
        $this->filas[] = [];
    }

    /** @param array<int,float> $anchos Ancho por columna, en caracteres. */
    public function anchos(array $anchos): void
    {
        $this->anchos = $anchos;
    }

    /**
     * Congela las primeras N filas: al hacer scroll el encabezado se queda
     * fijo. En un reporte de cientos de lineas es la diferencia entre poder
     * leerlo y tener que subir cada vez para saber que columna es cual.
     */
    public function congelarFilas(int $n): void
    {
        $this->filasFijas = max(0, $n);
    }

    /** @return string Contenido del .xlsx. */
    public function generar(): string
    {
        $partes = [
            '[Content_Types].xml'     => $this->contentTypes(),
            '_rels/.rels'             => $this->rels(),
            'xl/workbook.xml'         => $this->workbook(),
            'xl/_rels/workbook.xml.rels' => $this->workbookRels(),
            'xl/styles.xml'           => $this->styles(),
            'xl/worksheets/sheet1.xml' => $this->sheet(),
        ];
        return $this->zip($partes);
    }

    // ------------------------------------------------------------------
    // Partes XML
    // ------------------------------------------------------------------

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    private function rels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function workbook(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . $this->esc($this->hoja) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    /**
     * Excel exige que el relleno 0 sea "none" y el 1 "gray125", en ese orden,
     * aunque no se usen: si faltan, considera el libro corrupto.
     */
    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00"/></numFmts>'
            . '<fonts count="3">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="14"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="3">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFEDEFF2"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="6">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="164" fontId="1" fillId="2" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1"/>'
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    private function sheet(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

        if ($this->filasFijas > 0) {
            $y = $this->filasFijas + 1;
            $xml .= '<sheetViews><sheetView workbookViewId="0" tabSelected="1">'
                . '<pane ySplit="' . $this->filasFijas . '" topLeftCell="A' . $y . '" activePane="bottomLeft" state="frozen"/>'
                . '</sheetView></sheetViews>';
        }

        if ($this->anchos !== []) {
            $xml .= '<cols>';
            foreach ($this->anchos as $i => $ancho) {
                $n = $i + 1;
                $xml .= '<col min="' . $n . '" max="' . $n . '" width="' . round($ancho, 2) . '" customWidth="1"/>';
            }
            $xml .= '</cols>';
        }

        $xml .= '<sheetData>';
        foreach ($this->filas as $i => $celdas) {
            $nf = $i + 1;
            $xml .= '<row r="' . $nf . '">';
            foreach ($celdas as $j => [$valor, $estilo]) {
                $ref = $this->columna($j) . $nf;
                $xml .= $this->celda($ref, $valor, $estilo);
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData></worksheet>';
        return $xml;
    }

    private function celda(string $ref, $valor, int $estilo): string
    {
        $s = $estilo !== self::NORMAL ? ' s="' . $estilo . '"' : '';

        if ($valor === null || $valor === '') {
            return '<c r="' . $ref . '"' . $s . '/>';
        }
        if (is_int($valor) || is_float($valor)) {
            // NAN/INF romperian el XML; no deberian llegar, pero si llegan es
            // mejor una celda vacia que un archivo que Excel no abre.
            if (!is_finite((float) $valor)) {
                return '<c r="' . $ref . '"' . $s . '/>';
            }
            return '<c r="' . $ref . '"' . $s . '><v>' . rtrim(rtrim(number_format((float) $valor, 6, '.', ''), '0'), '.') . '</v></c>';
        }
        // Cadena en linea: evita el diccionario sharedStrings entero.
        return '<c r="' . $ref . '" t="inlineStr"' . $s . '><is><t xml:space="preserve">'
            . $this->esc((string) $valor) . '</t></is></c>';
    }

    /** 0 => A, 25 => Z, 26 => AA... */
    private function columna(int $i): string
    {
        $s = '';
        for ($n = $i + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $s = chr(65 + (($n - 1) % 26)) . $s;
        }
        return $s;
    }

    /**
     * Escapa para XML y quita los caracteres de control que el formato prohibe
     * (un \x0B pegado desde otro sistema deja el archivo ilegible para Excel).
     */
    private function esc(string $s): string
    {
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s) ?? $s;
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    // ------------------------------------------------------------------
    // ZIP
    // ------------------------------------------------------------------

    /**
     * Arma el ZIP (sin ZipArchive). Un .xlsx es un ZIP normal: cabecera local
     * por archivo, directorio central al final y el registro de cierre.
     *
     * @param array<string,string> $partes ruta dentro del zip => contenido
     */
    private function zip(array $partes): string
    {
        $comprimir = function_exists('gzdeflate');
        [$hora, $fecha] = $this->fechaDos();

        $local = '';
        $central = '';
        $offset = 0;

        foreach ($partes as $ruta => $contenido) {
            $crc = crc32($contenido);
            $tamano = strlen($contenido);
            $datos = $comprimir ? gzdeflate($contenido, 6) : $contenido;
            if ($datos === false) {
                $datos = $contenido;
                $metodo = 0;
            } else {
                $metodo = $comprimir ? 8 : 0;
            }
            $tamanoComp = strlen($datos);

            // Se arma por partes porque el ZIP alterna shorts y longs y un
            // solo formato de pack() no cubre ese orden.
            $cabecera = pack('Vvvvvv', 0x04034b50, 20, 0, $metodo, $hora, $fecha)
                . pack('VVV', $crc, $tamanoComp, $tamano)
                . pack('vv', strlen($ruta), 0);

            $local .= $cabecera . $ruta . $datos;

            $central .= pack('Vvvvvvv', 0x02014b50, 20, 20, 0, $metodo, $hora, $fecha)
                . pack('VVV', $crc, $tamanoComp, $tamano)
                . pack('vvvvv', strlen($ruta), 0, 0, 0, 0)
                . pack('VV', 0, $offset)
                . $ruta;

            $offset += strlen($cabecera) + strlen($ruta) + strlen($datos);
        }

        $n = count($partes);
        $fin = pack('Vvvvv', 0x06054b50, 0, 0, $n, $n)
            . pack('VV', strlen($central), $offset)
            . pack('v', 0);

        return $local . $central . $fin;
    }

    /** Hora y fecha en formato MS-DOS, que es lo que guarda el ZIP. */
    private function fechaDos(): array
    {
        $t = getdate();
        $hora = ($t['hours'] << 11) | ($t['minutes'] << 5) | ($t['seconds'] >> 1);
        $anio = max(1980, $t['year']);
        $fecha = (($anio - 1980) << 9) | ($t['mon'] << 5) | $t['mday'];
        return [$hora, $fecha];
    }
}
