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
    private const FC_BASE_URL = 'https://fc.dgii.gov.do';
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

        // FC < 250k: la DGII la consulta en fc.dgii.gov.do y solo con estos 4
        // parametros (Informe Tecnico e-CF v1.0, RI). En ecf.dgii.gov.do,
        // ConsultaTimbreFC responde 404.
        if ($tipo === '32' && (float) $monto < self::RFCE_UMBRAL) {
            return sprintf(
                '%s/%s/ConsultaTimbreFC?RncEmisor=%s&ENCF=%s&MontoTotal=%s&CodigoSeguridad=%s',
                self::FC_BASE_URL,
                rawurlencode($amb),
                rawurlencode($rncEmisor),
                rawurlencode($eNcf),
                rawurlencode($monto),
                rawurlencode($codigo)
            );
        }

        // E43 no tiene Comprador y E47 solo IdentificadorExtranjero: sin RNCComprador
        // en el XML no va en el QR, y con el la DGII responde "no encontrado".
        $rncComprador = $txt('Encabezado/Comprador/RNCComprador');
        $paramComprador = ($rncComprador !== '' && !in_array($tipo, ['43', '47'], true))
            ? '&RncComprador=' . rawurlencode($rncComprador)
            : '';

        return sprintf(
            '%s/%s/ConsultaTimbre?RncEmisor=%s%s&ENCF=%s&FechaEmision=%s&MontoTotal=%s&FechaFirma=%s&CodigoSeguridad=%s',
            self::BASE_URL,
            rawurlencode($amb),
            rawurlencode($rncEmisor),
            $paramComprador,
            rawurlencode($eNcf),
            rawurlencode($fechaEmision),
            rawurlencode($monto),
            rawurlencode($fechaFirma),
            rawurlencode($codigo)
        );
    }

    /**
     * ¿La pagina publica del timbre dice la verdad sobre un rechazo en ese ambiente?
     *
     * En ecf y testecf si: para un mismo e-NCF muestra "Rechazado" con el codigo de
     * seguridad del envio rechazado y "Aceptado" con el del aceptado (tenant 6 en ecf,
     * "e-NCF ya utilizado"; tenant 5 en testecf, 2026-11-13).
     *
     * En certecf NO: muestra "Aceptado" para un RFCE que RecepcionFC rechazo por el
     * proceso de certificacion (Gratex E320000000369, 2026-06-12: "codigo 2, no tiene
     * una postulacion activa"). Asi le devolvimos RFCE_ACEPTADO a AGROVISA (tenant 18,
     * 2026-11-13) por dos E32 rechazados con el 145 ("fase valida").
     */
    public static function paginaReflejaRechazos(string $ambiente): bool
    {
        return in_array(strtolower(trim($ambiente)), ['ecf', 'testecf'], true);
    }

    /**
     * Estado que muestra la pagina publica del timbre (la del QR): ACEPTADO,
     * ACEPTADO_CONDICIONAL, RECHAZADO, EN_PROCESO o NO_ENCONTRADO ("No fue encontrada
     * la factura"). null si la pagina no trae ninguno reconocible (otra pagina, un
     * desafio del WAF). Pura. En certecf no sirve para un rechazo: ver
     * paginaReflejaRechazos().
     */
    public static function estadoDePagina(string $html): ?string
    {
        if (stripos($html, 'No fue encontrada') !== false) {
            return 'NO_ENCONTRADO';
        }
        if (!preg_match('#<th[^>]*>\s*Estado\s*</th>\s*<td[^>]*>\s*([^<]+?)\s*</td>#i', $html, $m)) {
            return null;
        }
        $texto = strtolower(trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        return match (true) {
            str_contains($texto, 'rechaz')    => 'RECHAZADO',
            str_contains($texto, 'condicion') => 'ACEPTADO_CONDICIONAL',
            str_contains($texto, 'acept')     => 'ACEPTADO',
            str_contains($texto, 'proceso')   => 'EN_PROCESO',
            default                           => null,
        };
    }

    /**
     * Abre la URL del timbre (urlDesdeXml) y devuelve el estado que muestra la DGII.
     * La pagina es publica (sin token): sirve donde la consulta con token falla. Caso:
     * RFCE en certecf, donde fc.dgii.gov.do/certecf/consultarfce rechaza el token de la
     * propia DGII (401 "The signature is invalid", 2026-11-13). Lanza si no hay
     * respuesta HTTP.
     *
     * @return array{estado: ?string, http: int, url: string}
     */
    public static function consultarEstado(DgiiAuthService $auth, string $url, int $timeout = 20): array
    {
        $r = $auth->obtenerPaginaPublica($url, $timeout);
        $http = (int) ($r['status_code'] ?? 0);
        return [
            'estado' => $http === 200 ? self::estadoDePagina((string) ($r['body'] ?? '')) : null,
            'http'   => $http,
            'url'    => $url,
        ];
    }
}
