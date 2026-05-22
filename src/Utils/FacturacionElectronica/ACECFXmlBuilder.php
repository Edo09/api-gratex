<?php

/**
 * Builds the ACECF (Aprobacion Comercial e-CF) XML for outgoing approvals.
 * Per "ACECF v.1.0.xsd": structure is fixed and short.
 *
 * Input shape (assoc array):
 *   rnc_emisor        (req) RNC del emisor del e-CF original
 *   e_ncf             (req) e-NCF del e-CF original
 *   fecha_emision     (req) Fecha del e-CF original (dd-mm-yyyy)
 *   monto_total       (req)
 *   rnc_comprador     (req) RNC del receptor (nosotros)
 *   estado            (req) 1=Aceptado, 2=Rechazado
 *   detalle_motivo    (opt) requerido si estado=2
 *   fecha_hora        (opt) default ahora (dd-mm-yyyy hh:mm:ss)
 */
class ACECFXmlBuilder
{
    private const SCHEMA_VERSION = '1.0';

    public function build(array $data): string
    {
        $estado = (string) ($data['estado'] ?? '');
        if (!in_array($estado, ['1', '2'], true)) {
            throw new RuntimeException('estado debe ser 1 (Aceptado) o 2 (Rechazado). Recibido: ' . $estado);
        }
        if ($estado === '2' && empty($data['detalle_motivo'])) {
            throw new RuntimeException('detalle_motivo es requerido cuando estado=2 (Rechazado).');
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $document->preserveWhiteSpace = false;
        $document->formatOutput = false;

        $root = $document->createElement('ACECF');
        $document->appendChild($root);

        $detalle = $document->createElement('DetalleAprobacionComercial');
        $root->appendChild($detalle);

        $detalle->appendChild($document->createElement('Version', self::SCHEMA_VERSION));
        $detalle->appendChild($document->createElement('RNCEmisor', (string) ($data['rnc_emisor'] ?? '')));
        $detalle->appendChild($document->createElement('eNCF', (string) ($data['e_ncf'] ?? '')));
        $detalle->appendChild($document->createElement(
            'FechaEmision',
            $this->formatDate($data['fecha_emision'] ?? date('d-m-Y'))
        ));
        $detalle->appendChild($document->createElement(
            'MontoTotal',
            $this->money($data['monto_total'] ?? 0)
        ));
        $detalle->appendChild($document->createElement('RNCComprador', (string) ($data['rnc_comprador'] ?? '')));
        $detalle->appendChild($document->createElement('Estado', $estado));

        if ($estado === '2' && !empty($data['detalle_motivo'])) {
            $el = $document->createElement('DetalleMotivoRechazo');
            $el->appendChild($document->createTextNode((string) $data['detalle_motivo']));
            $detalle->appendChild($el);
        }

        $detalle->appendChild($document->createElement(
            'FechaHoraAprobacionComercial',
            $this->formatDateTime($data['fecha_hora'] ?? date('d-m-Y H:i:s'))
        ));

        $xml = $document->saveXML();
        if ($xml === false) {
            throw new RuntimeException('Unable to serialize ACECF XML.');
        }
        return $xml;
    }

    private function formatDate(string $value): string
    {
        $v = trim($value);
        if (preg_match('/^\d{2}-\d{2}-\d{4}$/', $v)) {
            return $v;
        }
        $ts = strtotime($v);
        if ($ts === false) {
            throw new RuntimeException('Fecha invalida: ' . $value);
        }
        return date('d-m-Y', $ts);
    }

    private function formatDateTime(string $value): string
    {
        $v = trim($value);
        if (preg_match('/^\d{2}-\d{2}-\d{4} \d{2}:\d{2}:\d{2}$/', $v)) {
            return $v;
        }
        $ts = strtotime($v);
        if ($ts === false) {
            throw new RuntimeException('FechaHora invalida: ' . $value);
        }
        return date('d-m-Y H:i:s', $ts);
    }

    private function money($value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }
}
