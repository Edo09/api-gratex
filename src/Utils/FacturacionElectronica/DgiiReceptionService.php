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

        $boundary = '----GratexDgiiBoundary' . bin2hex(random_bytes(16));
        $body = $this->buildMultipartBody($boundary, 'xml', 'ecf.xml', 'text/xml', $signedXml);

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

        $boundary = '----GratexDgiiBoundary' . bin2hex(random_bytes(16));
        $body = $this->buildMultipartBody($boundary, 'xml', 'rfce.xml', 'text/xml', $signedXml);

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
}
