<?php
/**
 * TimbreEcf — URL de ConsultaTimbre (el contenido del QR) de un e-CF firmado.
 *
 * Los clientes de integracion imprimen su propia Representacion Impresa: aqui se
 * les arma la URL para que solo la conviertan a QR. Sale del XML firmado (la DGII
 * valida el timbre contra ese XML), no de los datos de la solicitud: los totales
 * los calculamos nosotros. Mismas reglas que EcfDocumento::timbre().
 */
class TimbreEcf
{
    private const BASE_URL = 'https://ecf.dgii.gov.do';
    private const RFCE_UMBRAL = 250000;

    /**
     * @param string $ambiente 'ecf' | 'certecf' | 'testecf' (el del tenant al emitir)
     * @return string|null null si el XML no trae algun dato del timbre o no esta firmado
     */
    public static function urlDesdeXml(string $signedXml, string $ambiente): ?string
    {
        $codigo = ECFEmissionService::codigoSeguridadDeXml($signedXml);
        if ($codigo === null || $codigo === '') {
            return null;
        }

        $dom = new DOMDocument();
        if (trim($signedXml) === '' || !@$dom->loadXML($signedXml)) {
            return null;
        }
        $xp = new DOMXPath($dom);
        $txt = static function (string $ruta) use ($xp): string {
            $n = $xp->query('/*/' . $ruta);
            return $n !== false && $n->length > 0 ? trim($n->item(0)->textContent) : '';
        };

        $tipo = $txt('Encabezado/IdDoc/TipoeCF');
        $eNcf = $txt('Encabezado/IdDoc/eNCF');
        $rncEmisor = $txt('Encabezado/Emisor/RNCEmisor');
        $fechaEmision = $txt('Encabezado/Emisor/FechaEmision');
        $fechaFirma = $txt('FechaHoraFirma');
        $monto = $txt('Encabezado/Totales/MontoTotal');
        if ($tipo === '' || $eNcf === '' || $rncEmisor === '' || $fechaEmision === ''
            || $fechaFirma === '' || !is_numeric($monto)) {
            return null;
        }
        $monto = number_format((float) $monto, 2, '.', '');

        $amb = match (strtolower(trim($ambiente))) {
            'certecf' => 'CerteCF',
            'testecf' => 'TesteCF',
            'ecf'     => 'ecf',
            default   => $ambiente,
        };
        if ($amb === '') {
            return null;
        }

        $endpoint = ($tipo === '32' && (float) $monto < self::RFCE_UMBRAL) ? 'ConsultaTimbreFC' : 'ConsultaTimbre';

        // E43 no tiene Comprador y E47 solo IdentificadorExtranjero: sin RNCComprador
        // en el XML no va en el QR, y con el la DGII responde "no encontrado".
        $rncComprador = $txt('Encabezado/Comprador/RNCComprador');
        $paramComprador = ($rncComprador !== '' && !in_array($tipo, ['43', '47'], true))
            ? '&RncComprador=' . rawurlencode($rncComprador)
            : '';

        return sprintf(
            '%s/%s/%s?RncEmisor=%s%s&ENCF=%s&FechaEmision=%s&MontoTotal=%s&FechaFirma=%s&CodigoSeguridad=%s',
            self::BASE_URL,
            rawurlencode($amb),
            $endpoint,
            rawurlencode($rncEmisor),
            $paramComprador,
            rawurlencode($eNcf),
            rawurlencode($fechaEmision),
            rawurlencode($monto),
            rawurlencode($fechaFirma),
            rawurlencode($codigo)
        );
    }
}
