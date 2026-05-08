<?php

/**
 * Lightweight reader for the DGII Fase 2 set-de-pruebas xlsx file.
 * Avoids any composer dependency: parses the xlsx (a ZIP) with ZipArchive
 * and reads the sheet XML with DOMDocument.
 */
class Fase2XlsxReader
{
    private string $path;
    private array $sharedStrings = [];
    private array $sheetNameToFile = [];

    public function __construct(string $path)
    {
        if (!is_file($path)) {
            throw new RuntimeException('xlsx no encontrado: ' . $path);
        }
        $this->path = $path;
        $this->load();
    }

    /**
     * @return array list of associative rows for the named sheet, header-driven
     */
    public function readSheet(string $sheetName): array
    {
        $file = $this->sheetNameToFile[$sheetName] ?? null;
        if ($file === null) {
            throw new RuntimeException('Hoja no encontrada en xlsx: ' . $sheetName);
        }

        $zip = new ZipArchive();
        if ($zip->open($this->path) !== true) {
            throw new RuntimeException('No se pudo abrir el xlsx.');
        }
        $xml = $zip->getFromName($file);
        $zip->close();
        if ($xml === false) {
            throw new RuntimeException('No se pudo leer ' . $file);
        }

        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = false;
        if (!$doc->loadXML($xml, LIBXML_NONET)) {
            throw new RuntimeException('XML invalido en hoja ' . $sheetName);
        }

        $rows = [];
        $headers = [];
        foreach ($doc->getElementsByTagName('row') as $rowEl) {
            $rowNum = (int) $rowEl->getAttribute('r');
            $cells = [];
            foreach ($rowEl->getElementsByTagName('c') as $cellEl) {
                $ref = $cellEl->getAttribute('r');
                $col = $this->columnIndex($ref);
                if ($col === null) {
                    continue;
                }
                $type = $cellEl->getAttribute('t');
                $val = '';
                if ($type === 's') {
                    $vNode = $cellEl->getElementsByTagName('v')->item(0);
                    if ($vNode !== null) {
                        $idx = (int) $vNode->textContent;
                        $val = $this->sharedStrings[$idx] ?? '';
                    }
                } elseif ($type === 'inlineStr') {
                    $tNode = $cellEl->getElementsByTagName('t')->item(0);
                    $val = $tNode !== null ? $tNode->textContent : '';
                } else {
                    $vNode = $cellEl->getElementsByTagName('v')->item(0);
                    $val = $vNode !== null ? $vNode->textContent : '';
                }
                $cells[$col] = $val;
            }

            if ($rowNum === 1) {
                $headers = $cells;
                continue;
            }

            $assoc = [];
            foreach ($headers as $colIdx => $headerName) {
                if ($headerName === '') {
                    continue;
                }
                $cellValue = $cells[$colIdx] ?? '';
                if ($cellValue === '#e' || $cellValue === '#N/A') {
                    $cellValue = '';
                }
                $assoc[$headerName] = $cellValue;
            }
            $rows[] = $assoc;
        }
        return $rows;
    }

    private function load(): void
    {
        $zip = new ZipArchive();
        if ($zip->open($this->path) !== true) {
            throw new RuntimeException('No se pudo abrir el xlsx.');
        }

        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedXml !== false) {
            $this->sharedStrings = $this->parseSharedStrings($sharedXml);
        }

        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        $zip->close();

        if ($workbookXml === false || $relsXml === false) {
            throw new RuntimeException('Faltan archivos clave en el xlsx.');
        }

        $relsMap = [];
        $relsDoc = new DOMDocument();
        $relsDoc->loadXML($relsXml, LIBXML_NONET);
        foreach ($relsDoc->getElementsByTagName('Relationship') as $r) {
            $relsMap[$r->getAttribute('Id')] = $r->getAttribute('Target');
        }

        $wbDoc = new DOMDocument();
        $wbDoc->loadXML($workbookXml, LIBXML_NONET);
        foreach ($wbDoc->getElementsByTagName('sheet') as $sheet) {
            $name = $sheet->getAttribute('name');
            $rid = $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
            if ($rid === '') {
                $rid = $sheet->getAttribute('r:id');
            }
            $target = $relsMap[$rid] ?? null;
            if ($target === null) {
                continue;
            }
            $this->sheetNameToFile[$name] = 'xl/' . ltrim($target, '/');
        }
    }

    private function parseSharedStrings(string $xml): array
    {
        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = false;
        $doc->loadXML($xml, LIBXML_NONET);
        $strings = [];
        foreach ($doc->getElementsByTagName('si') as $si) {
            $tNodes = $si->getElementsByTagName('t');
            $value = '';
            foreach ($tNodes as $t) {
                $value .= $t->textContent;
            }
            $strings[] = $value;
        }
        return $strings;
    }

    private function columnIndex(string $ref): ?int
    {
        if (!preg_match('/^([A-Z]+)\d+$/', $ref, $m)) {
            return null;
        }
        $letters = $m[1];
        $col = 0;
        $len = strlen($letters);
        for ($i = 0; $i < $len; $i++) {
            $col = $col * 26 + (ord($letters[$i]) - 64);
        }
        return $col;
    }
}
