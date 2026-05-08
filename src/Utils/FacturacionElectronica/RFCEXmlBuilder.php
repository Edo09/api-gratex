<?php

/**
 * Builds the RFCE (Resumen de Factura de Consumo Electronica) XML according to
 * the DGII XSD "RFCE 32 v.1.0.xsd". A RFCE is the summary that must be sent
 * for every E32 (Factura de Consumo Electronica) whose total amount is below
 * RD$ 250,000. The factura integra is later loaded into the DGII portal.
 *
 * Input shape (assoc array):
 *   tipo_ecf (must be '32'),
 *   e_ncf,
 *   tipo_ingresos (default '01'),
 *   tipo_pago (1=Contado | 2=Credito | 3=Gratuito, default 1),
 *   formas_pago: [{forma_pago: 1..8, monto_pago: float}, ...] (optional, max 7),
 *   emisor: {rnc, razon_social},
 *   fecha_emision,
 *   comprador: {rnc?, identificador_extranjero?, razon_social?},
 *   totales: {monto_gravado_total?, monto_gravado_i1?, monto_gravado_i2?,
 *             monto_gravado_i3?, monto_exento?, total_itbis?, total_itbis1?,
 *             total_itbis2?, total_itbis3?, monto_total},
 *   codigo_seguridad_ecf (6 chars: hash of the original e-CF signature)
 */
class RFCEXmlBuilder
{
    private const SCHEMA_VERSION = '1.0';

    public function build(array $data): string
    {
        $tipoEcf = (string) ($data['tipo_ecf'] ?? '32');
        if ($tipoEcf !== '32') {
            throw new RuntimeException('RFCE solo aplica a TipoeCF=32 (Factura de Consumo). Recibido: ' . $tipoEcf);
        }

        $codigoSeguridad = (string) ($data['codigo_seguridad_ecf'] ?? '');
        if (strlen($codigoSeguridad) !== 6) {
            throw new RuntimeException('codigo_seguridad_ecf debe ser exactamente 6 caracteres (hash del e-CF original).');
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $document->preserveWhiteSpace = false;
        $document->formatOutput = false;

        $root = $document->createElement('RFCE');
        $document->appendChild($root);

        $encabezado = $document->createElement('Encabezado');
        $root->appendChild($encabezado);

        $encabezado->appendChild($document->createElement('Version', self::SCHEMA_VERSION));
        $encabezado->appendChild($this->buildIdDoc($document, $data));
        $encabezado->appendChild($this->buildEmisor($document, $data));
        $encabezado->appendChild($this->buildComprador($document, $data['comprador'] ?? []));
        $encabezado->appendChild($this->buildTotales($document, $data['totales'] ?? []));
        $encabezado->appendChild($document->createElement('CodigoSeguridadeCF', $codigoSeguridad));

        $xml = $document->saveXML();
        if ($xml === false) {
            throw new RuntimeException('Unable to serialize RFCE XML.');
        }
        return $xml;
    }

    private function buildIdDoc(DOMDocument $doc, array $data): DOMElement
    {
        $idDoc = $doc->createElement('IdDoc');
        $idDoc->appendChild($doc->createElement('TipoeCF', '32'));
        $idDoc->appendChild($doc->createElement('eNCF', (string) ($data['e_ncf'] ?? '')));
        $idDoc->appendChild($doc->createElement('TipoIngresos', (string) ($data['tipo_ingresos'] ?? '01')));
        $idDoc->appendChild($doc->createElement('TipoPago', (string) ($data['tipo_pago'] ?? 1)));

        $formas = $data['formas_pago'] ?? [];
        if (is_array($formas) && count($formas) > 0) {
            $tabla = $doc->createElement('TablaFormasPago');
            $i = 0;
            foreach ($formas as $f) {
                if ($i >= 7) {
                    break;
                }
                $forma = $doc->createElement('FormaDePago');
                if (isset($f['forma_pago'])) {
                    $forma->appendChild($doc->createElement('FormaPago', (string) $f['forma_pago']));
                }
                if (isset($f['monto_pago'])) {
                    $forma->appendChild($doc->createElement('MontoPago', $this->money($f['monto_pago'])));
                }
                $tabla->appendChild($forma);
                $i++;
            }
            $idDoc->appendChild($tabla);
        }

        return $idDoc;
    }

    private function buildEmisor(DOMDocument $doc, array $data): DOMElement
    {
        $emisor = $data['emisor'] ?? [];
        $node = $doc->createElement('Emisor');
        $node->appendChild($doc->createElement('RNCEmisor', (string) ($emisor['rnc'] ?? '')));
        $node->appendChild($this->el($doc, 'RazonSocialEmisor', (string) ($emisor['razon_social'] ?? '')));
        $node->appendChild($doc->createElement('FechaEmision', $this->formatDate($data['fecha_emision'] ?? date('d-m-Y'))));
        return $node;
    }

    private function buildComprador(DOMDocument $doc, array $comprador): DOMElement
    {
        $node = $doc->createElement('Comprador');
        $rnc = preg_replace('/\D/', '', (string) ($comprador['rnc'] ?? ''));
        if ($rnc !== '') {
            $node->appendChild($doc->createElement('RNCComprador', $rnc));
        } elseif (!empty($comprador['identificador_extranjero'])) {
            $node->appendChild($this->el($doc, 'IdentificadorExtranjero', (string) $comprador['identificador_extranjero']));
        }
        $this->appendIfNotEmpty($doc, $node, 'RazonSocialComprador', $comprador['razon_social'] ?? '');
        return $node;
    }

    private function buildTotales(DOMDocument $doc, array $totales): DOMElement
    {
        $node = $doc->createElement('Totales');
        $this->appendMoneyIfSet($doc, $node, 'MontoGravadoTotal', $totales['monto_gravado_total'] ?? null);
        $this->appendMoneyIfSet($doc, $node, 'MontoGravadoI1', $totales['monto_gravado_i1'] ?? null);
        $this->appendMoneyIfSet($doc, $node, 'MontoGravadoI2', $totales['monto_gravado_i2'] ?? null);
        $this->appendMoneyIfSet($doc, $node, 'MontoGravadoI3', $totales['monto_gravado_i3'] ?? null);
        $this->appendMoneyIfSet($doc, $node, 'MontoExento', $totales['monto_exento'] ?? null);
        $this->appendMoneyIfSet($doc, $node, 'TotalITBIS', $totales['total_itbis'] ?? null);
        $this->appendMoneyIfSet($doc, $node, 'TotalITBIS1', $totales['total_itbis1'] ?? null);
        $this->appendMoneyIfSet($doc, $node, 'TotalITBIS2', $totales['total_itbis2'] ?? null);
        $this->appendMoneyIfSet($doc, $node, 'TotalITBIS3', $totales['total_itbis3'] ?? null);
        $node->appendChild($doc->createElement('MontoTotal', $this->money($totales['monto_total'] ?? 0)));
        return $node;
    }

    private function appendMoneyIfSet(DOMDocument $doc, DOMElement $parent, string $name, $value): void
    {
        if ($value === null || $value === '' || (float) $value <= 0) {
            return;
        }
        $parent->appendChild($doc->createElement($name, $this->money($value)));
    }

    private function appendIfNotEmpty(DOMDocument $doc, DOMElement $parent, string $name, $value): void
    {
        $value = (string) $value;
        if ($value === '') {
            return;
        }
        $parent->appendChild($this->el($doc, $name, $value));
    }

    private function el(DOMDocument $doc, string $name, string $value): DOMElement
    {
        $node = $doc->createElement($name);
        $node->appendChild($doc->createTextNode($value));
        return $node;
    }

    private function formatDate(string $date): string
    {
        $date = trim($date);
        if (preg_match('/^\d{2}-\d{2}-\d{4}$/', $date)) {
            return $date;
        }
        $ts = strtotime($date);
        if ($ts === false) {
            throw new RuntimeException('Fecha invalida: ' . $date);
        }
        return date('d-m-Y', $ts);
    }

    private function money($value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }
}
