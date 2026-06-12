<?php

require_once(__DIR__ . '/DgiiXmlSigner.php');
require_once(__DIR__ . '/../../AmbienteResolver.php');

class DgiiAuthService
{
    private const DEFAULT_BASE_URL = 'https://ecf.dgii.gov.do';
    private const DEFAULT_ENVIRONMENT = 'testecf';
    private const DEFAULT_CERT_PATH = 'certificados/20260501-2020077-KQBYARLQB.p12';
    private const DEFAULT_TIMEOUT = 30;

    private string $projectRoot;
    private DgiiXmlSigner $signer;
    private bool $envLoaded = false;

    public function __construct(?DgiiXmlSigner $signer = null)
    {
        $this->projectRoot = dirname(__DIR__, 3);
        $this->signer = $signer ?: new DgiiXmlSigner();
    }

    public function obtenerSemilla(array $options = []): array
    {
        $config = $this->buildConfig($options);
        $endpoint = $this->buildEndpoint($config, 'semilla');

        $response = $this->request('GET', $endpoint, [
            'Accept: application/xml, text/xml, */*'
        ], null, $config['timeout']);

        if ($response['status_code'] < 200 || $response['status_code'] >= 300) {
            throw new RuntimeException('DGII seed request failed: ' . $this->responseSummary($response));
        }

        if (trim($response['body']) === '') {
            throw new RuntimeException('DGII returned an empty seed response.');
        }

        return [
            'xml' => $response['body'],
            'ambiente' => $config['environment'],
            'endpoint' => $endpoint,
            'fecha' => $this->extractXmlValue($response['body'], 'fecha')
        ];
    }

    public function firmarSemilla(string $semillaXml, array $options = []): string
    {
        $certificateContent = $this->getCertificateContent($options);
        $password = $this->getCertificatePassword($options);

        return $this->signer->sign($certificateContent, $password, $semillaXml);
    }

    public function validarSemillaFirmada(string $signedXml, array $options = []): array
    {
        $config = $this->buildConfig($options);
        $endpoint = $this->buildEndpoint($config, 'validarsemilla');
        $boundary = '----GratexDgiiBoundary' . bin2hex(random_bytes(16));
        $body = $this->buildMultipartBody($boundary, 'xml', 'semilla_firmada.xml', 'text/xml', $signedXml);

        $response = $this->request('POST', $endpoint, [
            'Accept: application/json',
            'Content-Type: multipart/form-data; boundary=' . $boundary,
            'Content-Length: ' . strlen($body)
        ], $body, $config['timeout']);

        if ($response['status_code'] < 200 || $response['status_code'] >= 300) {
            throw new RuntimeException('DGII seed validation failed: ' . $this->responseSummary($response));
        }

        $tokenResponse = $this->parseAuthenticationResponse($response['body']);
        $tokenResponse['ambiente'] = $config['environment'];
        $tokenResponse['endpoint'] = $endpoint;

        return $tokenResponse;
    }

    public function autenticar(array $options = []): array
    {
        $semilla = $this->obtenerSemilla($options);
        $signedXml = $this->firmarSemilla($semilla['xml'], $options);
        $token = $this->validarSemillaFirmada($signedXml, $options);

        $token['semilla_fecha'] = $semilla['fecha'];

        return $token;
    }

    public function consultarEndpointAutenticado(string $method, string $pathOrUrl, string $bearerToken, $body = null, array $options = [], array $headers = []): array
    {
        $config = $this->buildConfig($options);
        $endpoint = $this->buildDgiiEndpoint($config, $pathOrUrl);
        $token = $this->normalizeBearerToken($bearerToken);
        $requestHeaders = $this->buildBearerHeaders($token, $headers);
        $requestBody = $body;

        if (is_array($body)) {
            $requestBody = json_encode($body);
            if ($requestBody === false) {
                throw new RuntimeException('Unable to encode DGII request body as JSON.');
            }

            if (!$this->hasHeader($requestHeaders, 'Content-Type')) {
                $requestHeaders[] = 'Content-Type: application/json';
            }
        }

        $response = $this->request(strtoupper($method), $endpoint, $requestHeaders, $requestBody, $config['timeout']);
        $decoded = json_decode($response['body'], true);
        $isError = $response['status_code'] < 200 || $response['status_code'] >= 300;

        // DGII responde algunos RECHAZOS de negocio con HTTP 4xx + cuerpo JSON
        // estructurado ({"codigo":2,"estado":"Rechazado","secuenciaUtilizada":false}).
        // Con tolerate_http_errors el llamador (recepcion e-CF) recibe ese cuerpo
        // para persistir el rechazo y revertir la secuencia, en vez de tratarlo
        // como caida. Un 4xx/5xx SIN cuerpo JSON (gateway/caida real) sigue
        // lanzando excepcion.
        $tolerate = !empty($options['tolerate_http_errors']);
        if ($isError && (!$tolerate || !is_array($decoded))) {
            throw new RuntimeException('DGII authenticated request failed: ' . $this->responseSummary($response));
        }

        return [
            'status_code' => $response['status_code'],
            'headers' => $response['headers'],
            'body' => $response['body'],
            'data' => is_array($decoded) ? $decoded : $response['body'],
            'endpoint' => $endpoint
        ];
    }

    private function buildConfig(array $options): array
    {
        // Sin override explicito: ambiente per-tenant (tenants.ambiente) o global.
        $environment = $this->normalizeEnvironment(
            $options['environment'] ?? $options['ambiente']
                ?? AmbienteResolver::active()
                ?? $this->env('DGII_ECF_ENVIRONMENT', self::DEFAULT_ENVIRONMENT)
        );

        $baseUrl = rtrim((string)($options['base_url'] ?? $this->env('DGII_ECF_BASE_URL', self::DEFAULT_BASE_URL)), '/');
        $timeout = (int)($options['timeout'] ?? $this->env('DGII_ECF_TIMEOUT', self::DEFAULT_TIMEOUT));

        return [
            'base_url' => $baseUrl,
            'environment' => $environment,
            'timeout' => $timeout > 0 ? $timeout : self::DEFAULT_TIMEOUT
        ];
    }

    private function normalizeEnvironment(string $environment): string
    {
        $normalized = strtolower(trim($environment));
        $aliases = [
            'test' => 'testecf',
            'testing' => 'testecf',
            'prueba' => 'testecf',
            'cert' => 'certecf',
            'certificacion' => 'certecf',
            'produccion' => 'ecf',
            'prod' => 'ecf'
        ];

        return $aliases[$normalized] ?? $normalized;
    }

    private function buildEndpoint(array $config, string $resource): string
    {
        return sprintf(
            '%s/%s/autenticacion/api/autenticacion/%s',
            $config['base_url'],
            trim($config['environment'], '/'),
            strtolower($resource)
        );
    }

    private function buildDgiiEndpoint(array $config, string $pathOrUrl): string
    {
        $target = trim($pathOrUrl);
        if ($target === '') {
            throw new RuntimeException('DGII endpoint path is required.');
        }

        if (preg_match('/^https?:\/\//i', $target)) {
            return $target;
        }

        $environment = trim($config['environment'], '/');
        $target = ltrim($target, '/');

        if (stripos($target, $environment . '/') === 0) {
            return $config['base_url'] . '/' . $target;
        }

        return $config['base_url'] . '/' . $environment . '/' . $target;
    }

    private function normalizeBearerToken(string $bearerToken): string
    {
        $token = trim($bearerToken);

        if (preg_match('/^Bearer\s+(.+)$/i', $token, $matches)) {
            $token = trim($matches[1]);
        }

        if ($token === '') {
            throw new RuntimeException('DGII bearer token is required.');
        }

        return $token;
    }

    private function buildBearerHeaders(string $token, array $headers): array
    {
        $cleanHeaders = [];
        foreach ($headers as $header) {
            $name = $this->headerName((string)$header);
            if (in_array($name, ['authorization', 'x-api-key', 'x-api-token'], true)) {
                continue;
            }

            $cleanHeaders[] = (string)$header;
        }

        array_unshift($cleanHeaders, 'Authorization: Bearer ' . $token);

        if (!$this->hasHeader($cleanHeaders, 'Accept')) {
            $cleanHeaders[] = 'Accept: application/json';
        }

        return $cleanHeaders;
    }

    private function hasHeader(array $headers, string $name): bool
    {
        $needle = strtolower($name);
        foreach ($headers as $header) {
            if ($this->headerName((string)$header) === $needle) {
                return true;
            }
        }

        return false;
    }

    private function headerName(string $header): string
    {
        $parts = explode(':', $header, 2);

        return strtolower(trim($parts[0]));
    }

    private function getCertificateContent(array $options): string
    {
        if (!empty($options['certificate_content_base64'])) {
            $decoded = base64_decode((string)$options['certificate_content_base64'], true);
            if ($decoded === false) {
                throw new RuntimeException('certificate_content_base64 is not valid base64.');
            }

            return $decoded;
        }

        if (!empty($options['certificate_content'])) {
            return (string)$options['certificate_content'];
        }

        $certificatePath = (string)($options['certificate_path'] ?? $options['certificado_path'] ?? $this->env('DGII_ECF_CERT_PATH', self::DEFAULT_CERT_PATH));
        if ($certificatePath === '') {
            throw new RuntimeException('Missing certificate path. Configure DGII_ECF_CERT_PATH or send certificate_path.');
        }

        $resolvedPath = $this->resolvePath($certificatePath);
        if (!is_file($resolvedPath) || !is_readable($resolvedPath)) {
            throw new RuntimeException('Certificate file was not found or is not readable');
        }

        $content = file_get_contents($resolvedPath);
        if ($content === false) {
            throw new RuntimeException('Unable to read certificate file');
        }

        return $content;
    }

    private function getCertificatePassword(array $options): string
    {
        $password = (string)($options['certificate_password'] ?? $options['certificado_password'] ?? $this->env('DGII_ECF_CERT_PASSWORD', ''));
        if ($password === '') {
            throw new RuntimeException('Missing certificate password. Configure DGII_ECF_CERT_PASSWORD or send certificate_password.');
        }

        return $password;
    }

    private function resolvePath(string $path): string
    {
        if ($this->isAbsolutePath($path)) {
            return $path;
        }

        return $this->projectRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    private function isAbsolutePath(string $path): bool
    {
        return preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1
            || str_starts_with($path, DIRECTORY_SEPARATOR)
            || str_starts_with($path, '\\\\');
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

    private function request(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        $this->ensureHttpTransport($url);

        if (function_exists('curl_init')) {
            return $this->curlRequest($method, $url, $headers, $body, $timeout);
        }

        return $this->streamRequest($method, $url, $headers, $body, $timeout);
    }

    private function ensureHttpTransport(string $url): void
    {
        if (stripos($url, 'https://') === 0 && !extension_loaded('openssl')) {
            throw new RuntimeException('The PHP OpenSSL extension is required for HTTPS DGII requests.');
        }

        if (!function_exists('curl_init') && !filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            throw new RuntimeException('Enable cURL or allow_url_fopen to call DGII endpoints.');
        }
    }

    private function curlRequest(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize HTTP client.');
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $timeout
        ]);

        $this->configureCurlTls($ch);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $rawResponse = curl_exec($ch);
        if ($rawResponse === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('HTTP request failed: ' . $error);
        }

        $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $responseHeaders = substr($rawResponse, 0, $headerSize);
        $responseBody = substr($rawResponse, $headerSize);
        if (PHP_VERSION_ID < 80500) {
            curl_close($ch);
        }

        return [
            'status_code' => $statusCode,
            'headers' => $responseHeaders,
            'body' => $responseBody
        ];
    }

    private function configureCurlTls($ch): void
    {
        $caInfo = (string)$this->env('DGII_ECF_CAINFO', '');
        if ($caInfo !== '') {
            $resolvedCaInfo = $this->resolvePath($caInfo);
            if (!is_file($resolvedCaInfo) || !is_readable($resolvedCaInfo)) {
                throw new RuntimeException('DGII_ECF_CAINFO is not readable: ' . $caInfo);
            }

            curl_setopt($ch, CURLOPT_CAINFO, $resolvedCaInfo);
        }

        if (defined('CURLOPT_SSL_OPTIONS') && defined('CURLSSLOPT_NATIVE_CA')) {
            curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
        }
    }

    private function streamRequest(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        $contextOptions = [
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'timeout' => $timeout,
                'ignore_errors' => true
            ]
        ];

        $caInfo = (string)$this->env('DGII_ECF_CAINFO', '');
        if ($caInfo !== '') {
            $resolvedCaInfo = $this->resolvePath($caInfo);
            if (!is_file($resolvedCaInfo) || !is_readable($resolvedCaInfo)) {
                throw new RuntimeException('DGII_ECF_CAINFO is not readable: ' . $caInfo);
            }

            $contextOptions['ssl'] = [
                'cafile' => $resolvedCaInfo,
                'verify_peer' => true,
                'verify_peer_name' => true
            ];
        }

        if ($body !== null) {
            $contextOptions['http']['content'] = $body;
        }

        $context = stream_context_create($contextOptions);
        $responseBody = file_get_contents($url, false, $context);

        if ($responseBody === false) {
            throw new RuntimeException('HTTP request failed.');
        }

        $responseHeaders = function_exists('http_get_last_response_headers')
            ? http_get_last_response_headers()
            : [];
        $responseHeaders = is_array($responseHeaders) ? $responseHeaders : [];
        $headersText = implode("\r\n", $responseHeaders);
        $statusCode = 0;
        if (isset($responseHeaders[0]) && preg_match('/\s(\d{3})\s/', $responseHeaders[0], $matches)) {
            $statusCode = (int)$matches[1];
        }

        return [
            'status_code' => $statusCode,
            'headers' => $headersText,
            'body' => $responseBody
        ];
    }

    private function parseAuthenticationResponse(string $body): array
    {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            return $this->normalizeTokenResponse($decoded);
        }

        $token = $this->extractXmlValue($body, 'token');
        $expira = $this->extractXmlValue($body, 'expira');
        $expedido = $this->extractXmlValue($body, 'expedido');

        if ($token !== null) {
            return $this->normalizeTokenResponse([
                'token' => $token,
                'expira' => $expira,
                'expedido' => $expedido
            ]);
        }

        throw new RuntimeException('DGII returned an authentication response that could not be parsed.');
    }

    private function normalizeTokenResponse(array $response): array
    {
        if (empty($response['token'])) {
            throw new RuntimeException('DGII authentication response does not include a token.');
        }

        return [
            'token' => $response['token'],
            'expira' => $response['expira'] ?? null,
            'expedido' => $response['expedido'] ?? null
        ];
    }

    private function extractXmlValue(string $xml, string $tagName): ?string
    {
        if (trim($xml) === '' || !str_contains($xml, '<')) {
            return null;
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return null;
        }

        $elements = $document->getElementsByTagName($tagName);
        $element = $elements->item(0);

        return $element ? trim($element->textContent) : null;
    }

    private function responseSummary(array $response): string
    {
        $body = trim(strip_tags($response['body']));
        if (strlen($body) > 500) {
            $body = substr($body, 0, 500) . '...';
        }

        return 'HTTP ' . $response['status_code'] . ($body !== '' ? ' - ' . $body : '');
    }

    private function env(string $key, $default = null)
    {
        $this->loadEnvFile();

        $value = getenv($key);

        return $value === false ? $default : $value;
    }

    private function loadEnvFile(): void
    {
        if ($this->envLoaded) {
            return;
        }

        $this->envLoaded = true;
        $path = $this->projectRoot . DIRECTORY_SEPARATOR . '.env';

        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            if ($key === '' || (!$this->canOverrideEnvValue($key) && getenv($key) !== false)) {
                continue;
            }

            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            $value = $this->normalizeEnvValue($key, $value);

            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    private function canOverrideEnvValue(string $key): bool
    {
        return in_array($key, ['OPENSSL_CONF', 'OPENSSL_MODULES'], true);
    }

    private function normalizeEnvValue(string $key, string $value): string
    {
        if (!in_array($key, ['OPENSSL_CONF', 'OPENSSL_MODULES', 'DGII_ECF_CAINFO'], true)) {
            return $value;
        }

        if ($value === '' || $this->isAbsolutePath($value)) {
            return $value;
        }

        return $this->resolvePath($value);
    }
}
