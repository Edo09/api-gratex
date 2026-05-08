<?php

/**
 * Builds the e-CF XML according to DGII XSD (e-CF 31, 32, 33, 34, 41, 43, 44, 45, 46, 47).
 *
 * Input shape (associative array):
 *   tipo_ecf (string '31'..'47'), e_ncf, fecha_emision (Y-m-d), fecha_vencimiento_secuencia (Y-m-d),
 *   tipo_ingresos (default '01'), tipo_pago (1|2|3, default 1),
 *   emisor: {rnc, razon_social, nombre_comercial?, direccion, municipio?, provincia?, telefono?, correo?, website?, actividad_economica?},
 *   comprador: {rnc?, razon_social, direccion?, municipio?, provincia?, correo?, contacto?},
 *   items: [{numero_linea, indicador_facturacion, nombre_item, indicador_bien_servicio, descripcion?, cantidad, precio_unitario, monto_item}],
 *   totales: {monto_gravado_total, monto_exento, total_itbis, monto_total}
 */
class ECFXmlBuilder
{
    private const SCHEMA_VERSION = '1.0';

    public function build(array $data): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->preserveWhiteSpace = false;
        $document->formatOutput = false;

        $root = $document->createElement('ECF');
        $document->appendChild($root);

        $root->appendChild($this->buildEncabezado($document, $data));
        $root->appendChild($this->buildDetallesItems($document, $data['items'] ?? []));

        if (!empty($data['informacion_referencia'])) {
            $root->appendChild($this->buildInformacionReferencia($document, $data['informacion_referencia']));
        }

        $fechaHoraFirma = $data['fecha_hora_firma'] ?? date('d-m-Y H:i:s');
        $root->appendChild($document->createElement('FechaHoraFirma', $fechaHoraFirma));

        $xml = $document->saveXML();
        if ($xml === false) {
            throw new RuntimeException('Unable to serialize e-CF XML.');
        }
        return $xml;
    }

    private function buildEncabezado(DOMDocument $doc, array $data): DOMElement
    {
        $encabezado = $doc->createElement('Encabezado');
        $encabezado->appendChild($doc->createElement('Version', self::SCHEMA_VERSION));
        $encabezado->appendChild($this->buildIdDoc($doc, $data));
        $encabezado->appendChild($this->buildEmisor($doc, $data['emisor'] ?? [], $data['fecha_emision'] ?? date('d-m-Y')));

        $tipoEcf = (string) ($data['tipo_ecf'] ?? '');
        if ($this->requiereComprador($tipoEcf, $data)) {
            $encabezado->appendChild($this->buildComprador($doc, $data['comprador'] ?? [], $tipoEcf));
        }

        $encabezado->appendChild($this->buildTotales($doc, $data['totales'] ?? []));
        return $encabezado;
    }

    private function buildIdDoc(DOMDocument $doc, array $data): DOMElement
    {
        $idDoc = $doc->createElement('IdDoc');
        $idDoc->appendChild($doc->createElement('TipoeCF', (string) $data['tipo_ecf']));
        $idDoc->appendChild($doc->createElement('eNCF', (string) $data['e_ncf']));
        $idDoc->appendChild($doc->createElement(
            'FechaVencimientoSecuencia',
            $this->formatDate($data['fecha_vencimiento_secuencia'] ?? '31-12-2030')
        ));
        $tipoEcfStr = (string) $data['tipo_ecf'];
        if ($tipoEcfStr === '34') {
            $idDoc->appendChild($doc->createElement(
                'IndicadorNotaCredito',
                (string) ($data['indicador_nota_credito'] ?? '0')
            ));
        }
        $idDoc->appendChild($doc->createElement(
            'IndicadorMontoGravado',
            (string) ($data['indicador_monto_gravado'] ?? '0')
        ));
        $idDoc->appendChild($doc->createElement('TipoIngresos', (string) ($data['tipo_ingresos'] ?? '01')));
        $idDoc->appendChild($doc->createElement('TipoPago', (string) ($data['tipo_pago'] ?? 1)));
        return $idDoc;
    }

    private function buildEmisor(DOMDocument $doc, array $emisor, string $fechaEmision): DOMElement
    {
        $node = $doc->createElement('Emisor');
        $node->appendChild($doc->createElement('RNCEmisor', (string) ($emisor['rnc'] ?? '')));
        $node->appendChild($this->el($doc, 'RazonSocialEmisor', $emisor['razon_social'] ?? ''));
        $this->appendIfNotEmpty($doc, $node, 'NombreComercial', $emisor['nombre_comercial'] ?? '');
        $this->appendIfNotEmpty($doc, $node, 'Sucursal', $emisor['sucursal'] ?? '');
        $node->appendChild($this->el($doc, 'DireccionEmisor', $emisor['direccion'] ?? ''));
        $this->appendIfNotEmpty($doc, $node, 'Municipio', $emisor['municipio'] ?? '');
        $this->appendIfNotEmpty($doc, $node, 'Provincia', $emisor['provincia'] ?? '');

        if (!empty($emisor['telefono'])) {
            $tablaTel = $doc->createElement('TablaTelefonoEmisor');
            $tablaTel->appendChild($doc->createElement('TelefonoEmisor', $this->formatPhone($emisor['telefono'])));
            $node->appendChild($tablaTel);
        }

        $this->appendIfNotEmpty($doc, $node, 'CorreoEmisor', $emisor['correo'] ?? '');
        $this->appendIfNotEmpty($doc, $node, 'WebSite', $emisor['website'] ?? '');
        $this->appendIfNotEmpty($doc, $node, 'ActividadEconomica', $emisor['actividad_economica'] ?? '');

        $node->appendChild($doc->createElement('FechaEmision', $this->formatDate($fechaEmision)));
        return $node;
    }

    private function buildComprador(DOMDocument $doc, array $comprador, string $tipoEcf): DOMElement
    {
        $node = $doc->createElement('Comprador');

        $rnc = preg_replace('/\D/', '', (string) ($comprador['rnc'] ?? ''));
        if ($rnc !== '') {
            $node->appendChild($doc->createElement('RNCComprador', $rnc));
        } elseif ($tipoEcf === '31') {
            throw new RuntimeException('RNC del comprador es requerido para e-CF tipo 31 (Credito Fiscal).');
        }

        $node->appendChild($this->el($doc, 'RazonSocialComprador', $comprador['razon_social'] ?? ''));
        $this->appendIfNotEmpty($doc, $node, 'ContactoComprador', $comprador['contacto'] ?? '');
        $this->appendIfNotEmpty($doc, $node, 'CorreoComprador', $comprador['correo'] ?? '');
        $this->appendIfNotEmpty($doc, $node, 'DireccionComprador', $comprador['direccion'] ?? '');
        $this->appendIfNotEmpty($doc, $node, 'MunicipioComprador', $comprador['municipio'] ?? '');
        $this->appendIfNotEmpty($doc, $node, 'ProvinciaComprador', $comprador['provincia'] ?? '');
        return $node;
    }

    private function buildTotales(DOMDocument $doc, array $totales): DOMElement
    {
        $node = $doc->createElement('Totales');

        $i1 = (float) ($totales['monto_gravado_i1'] ?? 0);
        $i2 = (float) ($totales['monto_gravado_i2'] ?? 0);
        $i3 = (float) ($totales['monto_gravado_i3'] ?? 0);
        $exento = (float) ($totales['monto_exento'] ?? 0);
        $itbis1 = (float) ($totales['total_itbis1'] ?? 0);
        $itbis2 = (float) ($totales['total_itbis2'] ?? 0);
        $itbis3 = (float) ($totales['total_itbis3'] ?? 0);

        if ($i1 + $i2 + $i3 > 0) {
            $node->appendChild($doc->createElement('MontoGravadoTotal', $this->money($i1 + $i2 + $i3)));
        }
        if ($i1 > 0) {
            $node->appendChild($doc->createElement('MontoGravadoI1', $this->money($i1)));
        }
        if ($i2 > 0) {
            $node->appendChild($doc->createElement('MontoGravadoI2', $this->money($i2)));
        }
        if ($i3 > 0) {
            $node->appendChild($doc->createElement('MontoGravadoI3', $this->money($i3)));
        }
        if ($exento > 0) {
            $node->appendChild($doc->createElement('MontoExento', $this->money($exento)));
        }

        if ($i1 > 0) {
            $node->appendChild($doc->createElement('ITBIS1', '18'));
        }
        if ($i2 > 0) {
            $node->appendChild($doc->createElement('ITBIS2', '16'));
        }
        if ($i3 > 0) {
            $node->appendChild($doc->createElement('ITBIS3', '0'));
        }

        $totalItbis = $itbis1 + $itbis2 + $itbis3;
        if ($totalItbis > 0) {
            $node->appendChild($doc->createElement('TotalITBIS', $this->money($totalItbis)));
        }
        if ($i1 > 0) {
            $node->appendChild($doc->createElement('TotalITBIS1', $this->money($itbis1)));
        }
        if ($i2 > 0) {
            $node->appendChild($doc->createElement('TotalITBIS2', $this->money($itbis2)));
        }
        if ($i3 > 0) {
            $node->appendChild($doc->createElement('TotalITBIS3', $this->money($itbis3)));
        }

        $node->appendChild($doc->createElement('MontoTotal', $this->money($totales['monto_total'] ?? 0)));
        return $node;
    }

    private function buildDetallesItems(DOMDocument $doc, array $items): DOMElement
    {
        $detalles = $doc->createElement('DetallesItems');
        foreach ($items as $i => $item) {
            $itemEl = $doc->createElement('Item');
            $itemEl->appendChild($doc->createElement('NumeroLinea', (string) ($item['numero_linea'] ?? ($i + 1))));
            $itemEl->appendChild($doc->createElement('IndicadorFacturacion', (string) ($item['indicador_facturacion'] ?? 1)));
            $itemEl->appendChild($this->el($doc, 'NombreItem', (string) ($item['nombre_item'] ?? '')));
            $itemEl->appendChild($doc->createElement('IndicadorBienoServicio', (string) ($item['indicador_bien_servicio'] ?? 2)));
            $this->appendIfNotEmpty($doc, $itemEl, 'DescripcionItem', $item['descripcion'] ?? '');
            $itemEl->appendChild($doc->createElement('CantidadItem', $this->qty($item['cantidad'] ?? 1)));
            $this->appendIfNotEmpty($doc, $itemEl, 'UnidadMedida', $item['unidad_medida'] ?? '');
            $itemEl->appendChild($doc->createElement('PrecioUnitarioItem', $this->money($item['precio_unitario'] ?? 0)));
            $itemEl->appendChild($doc->createElement('MontoItem', $this->money($item['monto_item'] ?? 0)));
            $detalles->appendChild($itemEl);
        }
        return $detalles;
    }

    private function buildInformacionReferencia(DOMDocument $doc, array $ref): DOMElement
    {
        $node = $doc->createElement('InformacionReferencia');
        $this->appendIfNotEmpty($doc, $node, 'NCFModificado', $ref['ncf_modificado'] ?? '');
        $this->appendIfNotEmpty($doc, $node, 'RNCOtroContribuyente', $ref['rnc_otro_contribuyente'] ?? '');
        if (!empty($ref['fecha_ncf_modificado'])) {
            $node->appendChild($doc->createElement('FechaNCFModificado', $this->formatDate($ref['fecha_ncf_modificado'])));
        }
        $this->appendIfNotEmpty($doc, $node, 'CodigoModificacion', $ref['codigo_modificacion'] ?? '');
        return $node;
    }

    private function requiereComprador(string $tipoEcf, array $data): bool
    {
        if (in_array($tipoEcf, ['31', '34', '41', '45', '46', '47'], true)) {
            return true;
        }
        return !empty($data['comprador']);
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

    private function formatPhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);
        if (strlen($digits) === 10) {
            return substr($digits, 0, 3) . '-' . substr($digits, 3, 3) . '-' . substr($digits, 6, 4);
        }
        return $phone;
    }

    private function money($value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }

    private function qty($value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }
}
