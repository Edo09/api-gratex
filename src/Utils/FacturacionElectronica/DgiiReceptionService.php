<?php

require_once __DIR__ . '/DgiiAuthService.php';

/**
 * Sends signed e-CF XML to DGII reception endpoint and queries status.
 * URLs verified against DGII Swagger spec at /api-docs/v1/definition.json:
 *   - Recepcion e-CF integro:
 *       {base_url}/{ambiente}/Recepcion/api/FacturasElectronicas
 *   - Recepcion RFCE (resumen E32 < 250,000 DOP):
 *       https://fc.dgii.gov.do/{ambiente}/RecepcionFC/api/recepcion/ecf
 *   - Consulta estado:
 *       {base_url}/{ambiente}/ConsultaResultado/api/Consultas/Estado?trackId=...&rnc=...&encf=...
 */
class DgiiReceptionService
{
    private const DEFAULT_FC_BASE_URL = 'https://fc.dgii.gov.do';

    private DgiiAuthService $auth;

    public function __construct(?DgiiAuthService $auth = null)
    {
        $this->auth = $auth ?: new DgiiAuthService();
    }

    /**
     * Sends a signed e-CF XML to DGII. Returns DGII response decoded.
     */
    public function recibir(string $signedXml, string $bearerToken, array $options = []): array
    {
        $environment = $this->resolveEnvironment($options);
        $path = sprintf('%s/Recepcion/api/FacturasElectronicas', $environment);

        $filename = $this->buildDgiiFilename($signedXml, 'ecf.xml');

        $boundary = '----GratexDgiiBoundary' . bin2hex(random_bytes(16));
        $body = $this->buildMultipartBody($boundary, 'xml', $filename, 'text/xml', $signedXml);

        $extraHeaders = [
            'Content-Type: multipart/form-data; boundary=' . $boundary,
            'Content-Length: ' . strlen($body),
        ];

        $response = $this->auth->consultarEndpointAutenticado(
            'POST',
            $path,
            $bearerToken,
            $body,
            $options,
            $extraHeaders
        );

        return [
            'status_code' => $response['status_code'],
            'data' => $response['data'],
            'raw_body' => $response['body'],
            'endpoint' => $response['endpoint'],
        ];
    }

    /**
     * Sends a signed RFCE (Resumen de Factura de Consumo Electronica) to the
     * DGII RecepcionFC service hosted on https://fc.dgii.gov.do. Required for
     * any E32 with monto_total < 250,000 DOP.
     */
    public function recibirResumen(string $signedXml, string $bearerToken, array $options = []): array
    {
        $environment = $this->resolveEnvironment($options);
        $baseUrl = rtrim((string) ($options['fc_base_url'] ?? getenv('DGII_FC_BASE_URL') ?: self::DEFAULT_FC_BASE_URL), '/');
        $url = sprintf('%s/%s/RecepcionFC/api/recepcion/ecf', $baseUrl, $environment);

        $filename = $this->buildDgiiFilename($signedXml, 'rfce.xml');

        $boundary = '----GratexDgiiBoundary' . bin2hex(random_bytes(16));
        $body = $this->buildMultipartBody($boundary, 'xml', $filename, 'text/xml', $signedXml);

        $extraHeaders = [
            'Content-Type: multipart/form-data; boundary=' . $boundary,
            'Content-Length: ' . strlen($body),
        ];

        $response = $this->auth->consultarEndpointAutenticado(
            'POST',
            $url,
            $bearerToken,
            $body,
            $options,
            $extraHeaders
        );

        return [
            'status_code' => $response['status_code'],
            'data' => $response['data'],
            'raw_body' => $response['body'],
            'endpoint' => $response['endpoint'],
        ];
    }

    /**
     * Sends a signed ACECF (Aprobacion Comercial e-CF) to DGII at
     *   {base_url}/{ambiente}/AprobacionComercial/api/AprobacionComercial
     * Used in Fase 3 of certification (our role: buyer approving e-CFs issued to us).
     */
    public function enviarAprobacionComercial(string $signedXml, string $bearerToken, array $options = []): array
    {
        $environment = $this->resolveEnvironment($options);
        $path = sprintf('%s/AprobacionComercial/api/AprobacionComercial', $environment);

        $filename = $this->buildDgiiFilename($signedXml, 'acecf.xml');

        $boundary = '----GratexDgiiBoundary' . bin2hex(random_bytes(16));
        $body = $this->buildMultipartBody($boundary, 'xml', $filename, 'text/xml', $signedXml);

        $extraHeaders = [
            'Content-Type: multipart/form-data; boundary=' . $boundary,
            'Content-Length: ' . strlen($body),
        ];

        $response = $this->auth->consultarEndpointAutenticado(
            'POST',
            $path,
            $bearerToken,
            $body,
            $options,
            $extraHeaders
        );

        return [
            'status_code' => $response['status_code'],
            'data' => $response['data'],
            'raw_body' => $response['body'],
            'endpoint' => $response['endpoint'],
        ];
    }

    /**
     * Query DGII for the current status of a previously sent e-CF.
     */
    public function consultarEstado(string $trackId, string $rnc, string $eNcf, string $bearerToken, array $options = []): array
    {
        $environment = $this->resolveEnvironment($options);
        $query = http_build_query([
            'trackId' => $trackId,
            'rnc' => $rnc,
            'encf' => $eNcf,
        ]);
        $path = sprintf('%s/ConsultaResultado/api/Consultas/Estado?%s', $environment, $query);

        $response = $this->auth->consultarEndpointAutenticado(
            'GET',
            $path,
            $bearerToken,
            null,
            $options
        );

        return [
            'status_code' => $response['status_code'],
            'data' => $response['data'],
            'raw_body' => $response['body'],
            'endpoint' => $response['endpoint'],
        ];
    }

    /**
     * Consulta el estado fiscal de un RFCE (E32 < 250,000 DOP) en el servicio
     * RecepcionFC de https://fc.dgii.gov.do. A diferencia de ConsultaResultado
     * (que usa trackId), este se identifica por RNC Emisor + e-NCF + codigo de
     * seguridad, por lo que sirve para RFCE que no generan trackId.
     *
     *   GET {fc_base_url}/{ambiente}/consultarfce/api/Consultas/Consulta
     *       ?RNC_Emisor=...&ENCF=...&Cod_Seguridad_eCF=...
     *
     * Estados: 0=No encontrado, 1=Aceptado, 2=Rechazado.
     */
    public function consultarResumenRFCE(string $rnc, string $eNcf, string $codigoSeguridad, string $bearerToken, array $options = []): array
    {
        $environment = $this->resolveEnvironment($options);
        $baseUrl = rtrim((string) ($options['fc_base_url'] ?? getenv('DGII_FC_BASE_URL') ?: self::DEFAULT_FC_BASE_URL), '/');
        $query = http_build_query([
            'RNC_Emisor' => $rnc,
            'ENCF' => $eNcf,
            'Cod_Seguridad_eCF' => $codigoSeguridad,
        ]);
        $url = sprintf('%s/%s/consultarfce/api/Consultas/Consulta?%s', $baseUrl, $environment, $query);

        $response = $this->auth->consultarEndpointAutenticado(
            'GET',
            $url,
            $bearerToken,
            null,
            $options
        );

        return [
            'status_code' => $response['status_code'],
            'data' => $response['data'],
            'raw_body' => $response['body'],
            'endpoint' => $response['endpoint'],
        ];
    }

    private function resolveEnvironment(array $options): string
    {
        $env = $options['environment'] ?? $options['ambiente'] ?? getenv('DGII_ECF_ENVIRONMENT') ?: 'testecf';
        $env = strtolower(trim((string) $env));
        $aliases = [
            'test' => 'testecf', 'testing' => 'testecf', 'prueba' => 'testecf',
            'cert' => 'certecf', 'certificacion' => 'certecf',
            'produccion' => 'ecf', 'prod' => 'ecf',
        ];
        return $aliases[$env] ?? $env;
    }

    private function buildMultipartBody(string $boundary, string $name, string $filename, string $contentType, string $content): string
    {
        $body = "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"{$name}\"; filename=\"{$filename}\"\r\n";
        $body .= "Content-Type: {$contentType}\r\n\r\n";
        $body .= $content . "\r\n";
        $body .= "--{$boundary}--\r\n";
        return $body;
    }

    /**
     * Build DGII-compatible filename "{RNCEmisor}{eNCF}.xml" extracted from
     * the signed XML. DGII validates filename length and rejects generic ones
     * like "rfce.xml" with code 3243.
     */
    private function buildDgiiFilename(string $signedXml, string $fallback): string
    {
        $rnc = '';
        $eNcf = '';
        if (preg_match('/<RNCEmisor>\s*([0-9]+)\s*<\/RNCEmisor>/i', $signedXml, $m)) {
            $rnc = $m[1];
        }
        if (preg_match('/<eNCF>\s*([A-Za-z0-9]+)\s*<\/eNCF>/i', $signedXml, $m)) {
            $eNcf = $m[1];
        }
        if ($rnc !== '' && $eNcf !== '') {
            return $rnc . $eNcf . '.xml';
        }
        return $fallback;
    }
}
