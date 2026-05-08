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
        $root->appendChild($this->buildDetallesItems($document, $data['items'] ?? [], (string) ($data['tipo_ecf'] ?? '')));

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

        $encabezado->appendChild($this->buildTotales($doc, $data['totales'] ?? [], $tipoEcf));
        return $encabezado;
    }

    /**
     * Configuracion de IdDoc por tipo de e-CF, derivada de los XSDs en samples/.
     * Cada tipo tiene una estructura distinta de IdDoc.
     */
    private const ID_DOC_CONFIG = [
        '31' => ['fecha_vence' => true,  'ind_nota_credito' => false, 'ind_monto_gravado' => true,  'tipo_ingresos' => true],
        '32' => ['fecha_vence' => false, 'ind_nota_credito' => false, 'ind_monto_gravado' => true,  'tipo_ingresos' => true],
        '33' => ['fecha_vence' => true,  'ind_nota_credito' => false, 'ind_monto_gravado' => true,  'tipo_ingresos' => true],
        '34' => ['fecha_vence' => false, 'ind_nota_credito' => true,  'ind_monto_gravado' => true,  'tipo_ingresos' => true],
        '41' => ['fecha_vence' => true,  'ind_nota_credito' => false, 'ind_monto_gravado' => true,  'tipo_ingresos' => false],
        '43' => ['fecha_vence' => true,  'ind_nota_credito' => false, 'ind_monto_gravado' => false, 'tipo_ingresos' => false],
        '44' => ['fecha_vence' => true,  'ind_nota_credito' => false, 'ind_monto_gravado' => false, 'tipo_ingresos' => true],
        '45' => ['fecha_vence' => true,  'ind_nota_credito' => false, 'ind_monto_gravado' => true,  'tipo_ingresos' => true],
        '46' => ['fecha_vence' => true,  'ind_nota_credito' => false, 'ind_monto_gravado' => false, 'tipo_ingresos' => true],
        '47' => ['fecha_vence' => true,  'ind_nota_credito' => false, 'ind_monto_gravado' => false, 'tipo_ingresos' => false],
    ];

    private function buildIdDoc(DOMDocument $doc, array $data): DOMElement
    {
        $tipoEcfStr = (string) $data['tipo_ecf'];
        $cfg = self::ID_DOC_CONFIG[$tipoEcfStr] ?? self::ID_DOC_CONFIG['31'];

        $idDoc = $doc->createElement('IdDoc');
        $idDoc->appendChild($doc->createElement('TipoeCF', $tipoEcfStr));
        $idDoc->appendChild($doc->createElement('eNCF', (string) $data['e_ncf']));

        if ($cfg['fecha_vence']) {
            $idDoc->appendChild($doc->createElement(
                'FechaVencimientoSecuencia',
                $this->formatDate($data['fecha_vencimiento_secuencia'] ?? '31-12-2030')
            ));
        }

        if ($cfg['ind_nota_credito']) {
            $idDoc->appendChild($doc->createElement(
                'IndicadorNotaCredito',
                (string) ($data['indicador_nota_credito'] ?? '0')
            ));
        }

        if ($cfg['ind_monto_gravado'] && isset($data['indicador_monto_gravado']) && $data['indicador_monto_gravado'] !== '') {
            $idDoc->appendChild($doc->createElement(
                'IndicadorMontoGravado',
                (string) $data['indicador_monto_gravado']
            ));
        }

        if ($cfg['tipo_ingresos']) {
            $idDoc->appendChild($doc->createElement('TipoIngresos', (string) ($data['tipo_ingresos'] ?? '01')));
        }

        $tipoPago = $data['tipo_pago'] ?? null;
        if ($tipoPago !== null && $tipoPago !== '' && (string) $tipoPago !== '0') {
            $idDoc->appendChild($doc->createElement('TipoPago', (string) $tipoPago));
        }
        if (!empty($data['fecha_limite_pago'])) {
            $idDoc->appendChild($doc->createElement('FechaLimitePago', $this->formatDate($data['fecha_limite_pago'])));
        }
        $this->appendIfNotEmpty($doc, $idDoc, 'TerminoPago', $data['termino_pago'] ?? '');
        $this->appendFormasPago($doc, $idDoc, $data['formas_pago'] ?? []);
        $this->appendIfNotEmpty($doc, $idDoc, 'TipoCuentaPago', $data['tipo_cuenta_pago'] ?? '');
        $this->appendIfNotEmpty($doc, $idDoc, 'NumeroCuentaPago', $data['numero_cuenta_pago'] ?? '');
        $this->appendIfNotEmpty($doc, $idDoc, 'BancoPago', $data['banco_pago'] ?? '');
        if (!empty($data['fecha_desde'])) {
            $idDoc->appendChild($doc->createElement('FechaDesde', $this->formatDate($data['fecha_desde'])));
        }
        if (!empty($data['fecha_hasta'])) {
            $idDoc->appendChild($doc->createElement('FechaHasta', $this->formatDate($data['fecha_hasta'])));
        }
        $this->appendIfNotEmpty($doc, $idDoc, 'TotalPaginas', $data['total_paginas'] ?? '');
        return $idDoc;
    }

    private function appendFormasPago(DOMDocument $doc, DOMElement $idDoc, array $formas): void
    {
        if (count($formas) === 0) {
            return;
        }

        $tabla = $doc->createElement('TablaFormasPago');
        $count = 0;
        foreach ($formas as $formaPago) {
            if ($count >= 7) {
                break;
            }
            if (!is_array($formaPago)) {
                continue;
            }
            $forma = $doc->createElement('FormaDePago');
            $this->appendIfNotEmpty($doc, $forma, 'FormaPago', $formaPago['forma_pago'] ?? '');
            $this->appendMoneyIfSet($doc, $forma, 'MontoPago', $formaPago['monto_pago'] ?? null);
            if ($forma->childNodes->length > 0) {
                $tabla->appendChild($forma);
                $count++;
            }
        }
        if ($tabla->childNodes->length > 0) {
            $idDoc->appendChild($tabla);
        }
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
        $this->appendIfNotEmpty($doc, $node, 'CodigoVendedor', $emisor['codigo_vendedor'] ?? '');
        $this->appendIfNotEmpty($doc, $node, 'NumeroFacturaInterna', $emisor['numero_factura_interna'] ?? '');
        $this->appendIfNotEmpty($doc, $node, 'NumeroPedidoInterno', $emisor['numero_pedido_interno'] ?? '');
        $this->appendIfNotEmpty($doc, $node, 'ZonaVenta', $emisor['zona_venta'] ?? '');
        $this->appendIfNotEmpty($doc, $node, 'RutaVenta', $emisor['ruta_venta'] ?? '');
        $this->appendIfNotEmpty($doc, $node, 'InformacionAdicionalEmisor', $emisor['informacion_adicional'] ?? '');

        $node->appendChild($doc->createElement('FechaEmision', $this->formatDate($fechaEmision)));
        return $node;
    }

    private function buildComprador(DOMDocument $doc, array $comprador, string $tipoEcf): DOMElement
    {
        $node = $doc->createElement('Comprador');

        if ($tipoEcf === '47') {
            $this->appendIfNotEmpty($doc, $node, 'IdentificadorExtranjero', $comprador['identificador_extranjero'] ?? '');
            $this->appendIfNotEmpty($doc, $node, 'RazonSocialComprador', $comprador['razon_social'] ?? '');
            return $node;
        }

        $rnc = preg_replace('/\D/', '', (string) ($comprador['rnc'] ?? ''));
        if ($rnc !== '') {
            $node->appendChild($doc->createElement('RNCComprador', $rnc));
        } elseif (!empty($comprador['identificador_extranjero'])) {
            $node->appendChild($this->el($doc, 'IdentificadorExtranjero', (string) $comprador['identificador_extranjero']));
        } elseif ($tipoEcf === '31') {
            throw new RuntimeException('RNC del comprador es requerido para e-CF tipo 31 (Credito Fiscal).');
        }

        $node->appendChild($this->el($doc, 'RazonSocialComprador', $comprador['razon_social'] ?? ''));
        $this->appendIfNotEmpty($doc, $node, 'ContactoComprador', $comprador['contacto'] ?? '');
        $this->appendIfNotEmpty($doc, $node, 'CorreoComprador', $comprador['correo'] ?? '');
        $this->appendIfNotEmpty($doc, $node, 'DireccionComprador', $comprador['direccion'] ?? '');
        $this->appendIfNotEmpty($doc, $node, 'MunicipioComprador', $comprador['municipio'] ?? '');
        $this->appendIfNotEmpty($doc, $node, 'ProvinciaComprador', $comprador['provincia'] ?? '');
        if (!empty($comprador['fecha_entrega'])) {
            $node->appendChild($doc->createElement('FechaEntrega', $this->formatDate($comprador['fecha_entrega'])));
        }
        $this->appendIfNotEmpty($doc, $node, 'ContactoEntrega', $comprador['contacto_entrega'] ?? '');
        $this->appendIfNotEmpty($doc, $node, 'DireccionEntrega', $comprador['direccion_entrega'] ?? '');
        if (!empty($comprador['telefono_adicional'])) {
            $node->appendChild($doc->createElement('TelefonoAdicional', $this->formatPhone($comprador['telefono_adicional'])));
        }
        if (!empty($comprador['fecha_orden_compra'])) {
            $node->appendChild($doc->createElement('FechaOrdenCompra', $this->formatDate($comprador['fecha_orden_compra'])));
        }
        $this->appendIfNotEmpty($doc, $node, 'NumeroOrdenCompra', $comprador['numero_orden_compra'] ?? '');
        $this->appendIfNotEmpty($doc, $node, 'CodigoInternoComprador', $comprador['codigo_interno'] ?? '');
        $this->appendIfNotEmpty($doc, $node, 'ResponsablePago', $comprador['responsable_pago'] ?? '');
        $this->appendIfNotEmpty($doc, $node, 'InformacionAdicionalComprador', $comprador['informacion_adicional'] ?? '');
        return $node;
    }

    /**
     * Configuracion de Totales por tipo de e-CF. Derivada de los XSDs en samples/.
     *   - allow_gravado: emite MontoGravadoTotal + MontoGravadoIx
     *   - allow_exento : emite MontoExento
     *   - allowed_rates: tasas de ITBIS permitidas (1=18%, 2=16%, 3=0%)
     */
    private const TOTALES_CONFIG = [
        '31' => ['gravado' => true,  'exento' => true,  'rates' => [1, 2, 3]],
        '32' => ['gravado' => true,  'exento' => true,  'rates' => [1, 2, 3]],
        '33' => ['gravado' => true,  'exento' => true,  'rates' => [1, 2, 3]],
        '34' => ['gravado' => true,  'exento' => true,  'rates' => [1, 2, 3]],
        '41' => ['gravado' => true,  'exento' => true,  'rates' => [1, 2, 3]],
        '43' => ['gravado' => false, 'exento' => true,  'rates' => []],
        '44' => ['gravado' => false, 'exento' => true,  'rates' => []],
        '45' => ['gravado' => true,  'exento' => true,  'rates' => [1, 2, 3]],
        '46' => ['gravado' => true,  'exento' => false, 'rates' => [3]],
        '47' => ['gravado' => false, 'exento' => true,  'rates' => []],
    ];

    private const TOTALES_EXTRA_CONFIG = [
        '31' => ['monto_periodo', 'saldo_anterior', 'monto_avance_pago', 'valor_pagar', 'total_itbis_retenido', 'total_isr_retencion', 'total_itbis_percepcion', 'total_isr_percepcion'],
        '32' => ['monto_periodo', 'saldo_anterior', 'monto_avance_pago', 'valor_pagar'],
        '33' => ['monto_periodo', 'saldo_anterior', 'monto_avance_pago', 'valor_pagar', 'total_itbis_retenido', 'total_isr_retencion', 'total_itbis_percepcion', 'total_isr_percepcion'],
        '34' => ['monto_periodo', 'saldo_anterior', 'monto_avance_pago', 'valor_pagar', 'total_itbis_retenido', 'total_isr_retencion', 'total_itbis_percepcion', 'total_isr_percepcion'],
        '41' => ['monto_periodo', 'saldo_anterior', 'monto_avance_pago', 'valor_pagar', 'total_itbis_retenido', 'total_isr_retencion', 'total_itbis_percepcion', 'total_isr_percepcion'],
        '43' => ['monto_periodo', 'saldo_anterior', 'monto_avance_pago', 'valor_pagar'],
        '44' => ['monto_periodo', 'saldo_anterior', 'monto_avance_pago', 'valor_pagar'],
        '45' => ['monto_periodo', 'saldo_anterior', 'monto_avance_pago', 'valor_pagar'],
        '46' => ['monto_periodo', 'saldo_anterior', 'monto_avance_pago', 'valor_pagar'],
        '47' => ['monto_periodo', 'saldo_anterior', 'monto_avance_pago', 'valor_pagar', 'total_isr_retencion'],
    ];

    private function buildTotales(DOMDocument $doc, array $totales, string $tipoEcf): DOMElement
    {
        $cfg = self::TOTALES_CONFIG[$tipoEcf] ?? self::TOTALES_CONFIG['31'];
        $node = $doc->createElement('Totales');

        $i1 = in_array(1, $cfg['rates'], true) ? (float) ($totales['monto_gravado_i1'] ?? 0) : 0;
        $i2 = in_array(2, $cfg['rates'], true) ? (float) ($totales['monto_gravado_i2'] ?? 0) : 0;
        $i3 = in_array(3, $cfg['rates'], true) ? (float) ($totales['monto_gravado_i3'] ?? 0) : 0;
        $itbis1 = in_array(1, $cfg['rates'], true) ? (float) ($totales['total_itbis1'] ?? 0) : 0;
        $itbis2 = in_array(2, $cfg['rates'], true) ? (float) ($totales['total_itbis2'] ?? 0) : 0;
        $itbis3 = in_array(3, $cfg['rates'], true) ? (float) ($totales['total_itbis3'] ?? 0) : 0;
        $exento = $cfg['exento'] ? (float) ($totales['monto_exento'] ?? 0) : 0;

        if ($cfg['gravado'] && $this->hasTotal($totales, 'monto_gravado_total')) {
            $node->appendChild($doc->createElement('MontoGravadoTotal', $this->money($totales['monto_gravado_total'])));
        } elseif ($cfg['gravado'] && ($i1 + $i2 + $i3) > 0) {
            $node->appendChild($doc->createElement('MontoGravadoTotal', $this->money($i1 + $i2 + $i3)));
        }
        if ($cfg['gravado'] && ($this->hasTotal($totales, 'monto_gravado_i1') || $i1 > 0)) {
            $node->appendChild($doc->createElement('MontoGravadoI1', $this->money($totales['monto_gravado_i1'] ?? $i1)));
        }
        if ($cfg['gravado'] && ($this->hasTotal($totales, 'monto_gravado_i2') || $i2 > 0)) {
            $node->appendChild($doc->createElement('MontoGravadoI2', $this->money($totales['monto_gravado_i2'] ?? $i2)));
        }
        if ($cfg['gravado'] && ($this->hasTotal($totales, 'monto_gravado_i3') || $i3 > 0)) {
            $node->appendChild($doc->createElement('MontoGravadoI3', $this->money($totales['monto_gravado_i3'] ?? $i3)));
        }
        if ($this->hasTotal($totales, 'monto_exento') || $exento > 0) {
            $node->appendChild($doc->createElement('MontoExento', $this->money($exento)));
        }

        if ($this->hasTotal($totales, 'itbis1') || $i1 > 0) {
            $node->appendChild($doc->createElement('ITBIS1', (string) ($totales['itbis1'] ?? '18')));
        }
        if ($this->hasTotal($totales, 'itbis2') || $i2 > 0) {
            $node->appendChild($doc->createElement('ITBIS2', (string) ($totales['itbis2'] ?? '16')));
        }
        if ($this->hasTotal($totales, 'itbis3') || $i3 > 0) {
            $node->appendChild($doc->createElement('ITBIS3', (string) ($totales['itbis3'] ?? '0')));
        }

        $totalItbis = $itbis1 + $itbis2 + $itbis3;
        if ($this->hasTotal($totales, 'total_itbis') || $totalItbis > 0) {
            $node->appendChild($doc->createElement('TotalITBIS', $this->money($totales['total_itbis'] ?? $totalItbis)));
        }
        if ($this->hasTotal($totales, 'total_itbis1') || $i1 > 0) {
            $node->appendChild($doc->createElement('TotalITBIS1', $this->money($totales['total_itbis1'] ?? $itbis1)));
        }
        if ($this->hasTotal($totales, 'total_itbis2') || $i2 > 0) {
            $node->appendChild($doc->createElement('TotalITBIS2', $this->money($totales['total_itbis2'] ?? $itbis2)));
        }
        if ($this->hasTotal($totales, 'total_itbis3') || $i3 > 0) {
            $node->appendChild($doc->createElement('TotalITBIS3', $this->money($totales['total_itbis3'] ?? $itbis3)));
        }
        $this->appendMoneyIfSet($doc, $node, 'MontoImpuestoAdicional', $totales['monto_impuesto_adicional'] ?? null);
        $this->appendImpuestosAdicionales($doc, $node, $totales['impuestos_adicionales'] ?? []);

        $node->appendChild($doc->createElement('MontoTotal', $this->money($totales['monto_total'] ?? 0)));

        $allowedExtras = self::TOTALES_EXTRA_CONFIG[$tipoEcf] ?? [];
        $extraMap = [
            'monto_periodo' => 'MontoPeriodo',
            'saldo_anterior' => 'SaldoAnterior',
            'monto_avance_pago' => 'MontoAvancePago',
            'valor_pagar' => 'ValorPagar',
            'total_itbis_retenido' => 'TotalITBISRetenido',
            'total_isr_retencion' => 'TotalISRRetencion',
            'total_itbis_percepcion' => 'TotalITBISPercepcion',
            'total_isr_percepcion' => 'TotalISRPercepcion',
        ];
        foreach ($extraMap as $key => $name) {
            if (in_array($key, $allowedExtras, true)) {
                $this->appendMoneyIfSet($doc, $node, $name, $totales[$key] ?? null);
            }
        }
        return $node;
    }

    private function buildDetallesItems(DOMDocument $doc, array $items, string $tipoEcf): DOMElement
    {
        $detalles = $doc->createElement('DetallesItems');
        foreach ($items as $i => $item) {
            $itemEl = $doc->createElement('Item');
            $itemEl->appendChild($doc->createElement('NumeroLinea', (string) ($item['numero_linea'] ?? ($i + 1))));
            $itemEl->appendChild($doc->createElement('IndicadorFacturacion', (string) ($item['indicador_facturacion'] ?? 1)));
            $this->appendRetencionIfNeeded($doc, $itemEl, $item, $tipoEcf);
            $itemEl->appendChild($this->el($doc, 'NombreItem', (string) ($item['nombre_item'] ?? '')));
            $itemEl->appendChild($doc->createElement('IndicadorBienoServicio', (string) ($item['indicador_bien_servicio'] ?? 2)));
            $this->appendIfNotEmpty($doc, $itemEl, 'DescripcionItem', $item['descripcion'] ?? '');
            $itemEl->appendChild($doc->createElement('CantidadItem', $this->qty($item['cantidad_raw'] ?? $item['cantidad'] ?? 1)));
            $this->appendIfNotEmpty($doc, $itemEl, 'UnidadMedida', $item['unidad_medida'] ?? '');
            $this->appendNumberIfSet($doc, $itemEl, 'CantidadReferencia', $item['cantidad_referencia'] ?? null);
            $this->appendIfNotEmpty($doc, $itemEl, 'UnidadReferencia', $item['unidad_referencia'] ?? '');
            $this->appendSubcantidades($doc, $itemEl, $item['subcantidades'] ?? []);
            $this->appendNumberIfSet($doc, $itemEl, 'GradosAlcohol', $item['grados_alcohol'] ?? null);
            $this->appendNumberIfSet($doc, $itemEl, 'PrecioUnitarioReferencia', $item['precio_unitario_referencia'] ?? null);
            if (!empty($item['fecha_elaboracion'])) {
                $itemEl->appendChild($doc->createElement('FechaElaboracion', $this->formatDate($item['fecha_elaboracion'])));
            }
            if (!empty($item['fecha_vencimiento_item'])) {
                $itemEl->appendChild($doc->createElement('FechaVencimientoItem', $this->formatDate($item['fecha_vencimiento_item'])));
            }
            $itemEl->appendChild($doc->createElement('PrecioUnitarioItem', $this->price($item['precio_unitario_raw'] ?? $item['precio_unitario'] ?? 0)));
            $this->appendNumberIfSet($doc, $itemEl, 'DescuentoMonto', $item['descuento_monto'] ?? null);
            $this->appendSubDescuentos($doc, $itemEl, $item['subdescuentos'] ?? []);
            $this->appendNumberIfSet($doc, $itemEl, 'RecargoMonto', $item['recargo_monto'] ?? null);
            $this->appendSubRecargos($doc, $itemEl, $item['subrecargos'] ?? []);
            $this->appendItemImpuestosAdicionales($doc, $itemEl, $item['impuestos_adicionales'] ?? []);
            $itemEl->appendChild($doc->createElement('MontoItem', $this->money($item['monto_item_raw'] ?? $item['monto_item'] ?? 0)));
            $detalles->appendChild($itemEl);
        }
        return $detalles;
    }

    private function appendRetencionIfNeeded(DOMDocument $doc, DOMElement $itemEl, array $item, string $tipoEcf): void
    {
        $required = in_array($tipoEcf, ['41', '47'], true);
        $indicador = $item['indicador_agente_retencion_percepcion'] ?? null;
        $itbis = $item['monto_itbis_retenido'] ?? null;
        $isr = $item['monto_isr_retenido'] ?? null;

        if (!$required && ($indicador === null || $indicador === '') && ($itbis === null || $itbis === '') && ($isr === null || $isr === '')) {
            return;
        }

        $retencion = $doc->createElement('Retencion');
        if ($indicador !== null && $indicador !== '') {
            $retencion->appendChild($doc->createElement('IndicadorAgenteRetencionoPercepcion', (string) $indicador));
        }
        $this->appendMoneyIfSet($doc, $retencion, 'MontoITBISRetenido', $itbis);
        $this->appendMoneyIfSet($doc, $retencion, 'MontoISRRetenido', $isr);
        $itemEl->appendChild($retencion);
    }

    private function appendSubcantidades(DOMDocument $doc, DOMElement $itemEl, array $subcantidades): void
    {
        if ($subcantidades === []) return;
        $tabla = $doc->createElement('TablaSubcantidad');
        foreach (array_slice($subcantidades, 0, 5) as $sub) {
            if (!is_array($sub)) continue;
            $node = $doc->createElement('SubcantidadItem');
            $this->appendNumberIfSet($doc, $node, 'Subcantidad', $sub['subcantidad'] ?? null);
            $this->appendIfNotEmpty($doc, $node, 'CodigoSubcantidad', $sub['codigo_subcantidad'] ?? '');
            if ($node->childNodes->length > 0) $tabla->appendChild($node);
        }
        if ($tabla->childNodes->length > 0) $itemEl->appendChild($tabla);
    }

    private function appendSubDescuentos(DOMDocument $doc, DOMElement $itemEl, array $subdescuentos): void
    {
        if ($subdescuentos === []) return;
        $tabla = $doc->createElement('TablaSubDescuento');
        foreach (array_slice($subdescuentos, 0, 12) as $sub) {
            if (!is_array($sub)) continue;
            $node = $doc->createElement('SubDescuento');
            $this->appendIfNotEmpty($doc, $node, 'TipoSubDescuento', $sub['tipo_sub_descuento'] ?? '');
            $this->appendNumberIfSet($doc, $node, 'SubDescuentoPorcentaje', $sub['sub_descuento_porcentaje'] ?? null);
            $this->appendNumberIfSet($doc, $node, 'MontoSubDescuento', $sub['monto_sub_descuento'] ?? null);
            if ($node->childNodes->length > 0) $tabla->appendChild($node);
        }
        if ($tabla->childNodes->length > 0) $itemEl->appendChild($tabla);
    }

    private function appendSubRecargos(DOMDocument $doc, DOMElement $itemEl, array $subrecargos): void
    {
        if ($subrecargos === []) return;
        $tabla = $doc->createElement('TablaSubRecargo');
        foreach (array_slice($subrecargos, 0, 12) as $sub) {
            if (!is_array($sub)) continue;
            $node = $doc->createElement('SubRecargo');
            $this->appendIfNotEmpty($doc, $node, 'TipoSubRecargo', $sub['tipo_sub_recargo'] ?? '');
            $this->appendNumberIfSet($doc, $node, 'SubRecargoPorcentaje', $sub['sub_recargo_porcentaje'] ?? null);
            $this->appendNumberIfSet($doc, $node, 'MontoSubRecargo', $sub['monto_sub_recargo'] ?? null);
            if ($node->childNodes->length > 0) $tabla->appendChild($node);
        }
        if ($tabla->childNodes->length > 0) $itemEl->appendChild($tabla);
    }

    private function appendItemImpuestosAdicionales(DOMDocument $doc, DOMElement $itemEl, array $impuestos): void
    {
        if ($impuestos === []) return;
        $tabla = $doc->createElement('TablaImpuestoAdicional');
        foreach (array_slice($impuestos, 0, 2) as $imp) {
            if (!is_array($imp)) continue;
            $node = $doc->createElement('ImpuestoAdicional');
            $this->appendIfNotEmpty($doc, $node, 'TipoImpuesto', $imp['tipo_impuesto'] ?? '');
            if ($node->childNodes->length > 0) $tabla->appendChild($node);
        }
        if ($tabla->childNodes->length > 0) $itemEl->appendChild($tabla);
    }

    private function appendImpuestosAdicionales(DOMDocument $doc, DOMElement $totales, array $impuestos): void
    {
        if ($impuestos === []) return;
        $tabla = $doc->createElement('ImpuestosAdicionales');
        foreach (array_slice($impuestos, 0, 20) as $imp) {
            if (!is_array($imp)) continue;
            $node = $doc->createElement('ImpuestoAdicional');
            $this->appendIfNotEmpty($doc, $node, 'TipoImpuesto', $imp['tipo_impuesto'] ?? '');
            $this->appendNumberIfSet($doc, $node, 'TasaImpuestoAdicional', $imp['tasa_impuesto_adicional'] ?? null);
            $this->appendMoneyIfSet($doc, $node, 'MontoImpuestoSelectivoConsumoEspecifico', $imp['monto_impuesto_selectivo_consumo_especifico'] ?? null);
            $this->appendMoneyIfSet($doc, $node, 'MontoImpuestoSelectivoConsumoAdvalorem', $imp['monto_impuesto_selectivo_consumo_advalorem'] ?? null);
            $this->appendMoneyIfSet($doc, $node, 'OtrosImpuestosAdicionales', $imp['otros_impuestos_adicionales'] ?? null);
            if ($node->childNodes->length > 0) $tabla->appendChild($node);
        }
        if ($tabla->childNodes->length > 0) $totales->appendChild($tabla);
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
        $this->appendIfNotEmpty($doc, $node, 'RazonModificacion', $ref['razon_modificacion'] ?? '');
        return $node;
    }

    private function requiereComprador(string $tipoEcf, array $data): bool
    {
        if (in_array($tipoEcf, ['31', '34', '41', '45', '46', '47'], true)) {
            return true;
        }
        return $this->hasAnyValue($data['comprador'] ?? []);
    }

    private function hasAnyValue($value): bool
    {
        if (!is_array($value)) {
            return $value !== null && $value !== '';
        }
        foreach ($value as $item) {
            if ($this->hasAnyValue($item)) {
                return true;
            }
        }
        return false;
    }

    private function appendIfNotEmpty(DOMDocument $doc, DOMElement $parent, string $name, $value): void
    {
        $value = (string) $value;
        if ($value === '') {
            return;
        }
        $parent->appendChild($this->el($doc, $name, $value));
    }

    private function appendMoneyIfSet(DOMDocument $doc, DOMElement $parent, string $name, $value): void
    {
        if ($value === null || $value === '') {
            return;
        }
        $parent->appendChild($doc->createElement($name, $this->money($value)));
    }

    private function appendNumberIfSet(DOMDocument $doc, DOMElement $parent, string $name, $value): void
    {
        if ($value === null || $value === '') {
            return;
        }
        $parent->appendChild($doc->createElement($name, (string) $value));
    }

    private function hasTotal(array $totales, string $key): bool
    {
        return array_key_exists($key, $totales) && $totales[$key] !== null && $totales[$key] !== '';
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

    private function price($value): string
    {
        $text = trim((string) ($value ?? ''));
        if (preg_match('/^\d+\.\d{1,4}$/', $text)) {
            return $text;
        }
        return $this->money($value);
    }

    private function qty($value): string
    {
        $text = trim((string) ($value ?? ''));
        if (preg_match('/^\d+(\.\d{1,2})?$/', $text)) {
            return $text;
        }
        return number_format((float) ($value ?? 0), 2, '.', '');
    }
}
