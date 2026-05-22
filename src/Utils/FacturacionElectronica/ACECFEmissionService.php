<?php

require_once __DIR__ . '/DgiiAuthService.php';
require_once __DIR__ . '/DgiiXmlSigner.php';
require_once __DIR__ . '/DgiiReceptionService.php';
require_once __DIR__ . '/ACECFXmlBuilder.php';
require_once __DIR__ . '/../../Models/EmisorConfigModel.php';

/**
 * Orchestrates the full ACECF (Aprobacion Comercial) outgoing flow:
 *   1. Build the ACECF XML
 *   2. Sign with our certificate
 *   3. Get DGII auth token
 *   4. POST signed XML to DGII /AprobacionComercial/api/AprobacionComercial
 *
 * Used when WE (as buyer) approve or reject an e-CF that was issued to us.
 * In production: the seller has registered a URL Aprobacion and DGII forwards.
 * In Fase 3 certification: we send directly to DGII's AprobacionComercial endpoint.
 */
class ACECFEmissionService
{
    private DgiiAuthService $auth;
    private DgiiXmlSigner $signer;
    private DgiiReceptionService $reception;
    private ACECFXmlBuilder $builder;
    private EmisorConfigModel $emisorModel;

    public function __construct()
    {
        $this->auth = new DgiiAuthService();
        $this->signer = new DgiiXmlSigner();
        $this->reception = new DgiiReceptionService($this->auth);
        $this->builder = new ACECFXmlBuilder();
        $this->emisorModel = new EmisorConfigModel();
    }

    /**
     * @param array $payload Required:
     *   rnc_emisor, e_ncf, fecha_emision, monto_total, estado (1|2).
     *   Optional:
     *   rnc_comprador (default: nuestro RNC del emisor_config),
     *   detalle_motivo (req si estado=2),
     *   fecha_hora (default: ahora),
     *   ambiente (override del .env).
     *
     * @return array {
     *   signed_xml, codigo_seguridad, dgii_response, dgii_status_code,
     *   ambiente, fecha_emision_dgii, track_id
     * }
     */
    public function enviar(array $payload): array
    {
        $emisor = $this->emisorModel->get();
        if (!$emisor) {
            throw new RuntimeException('emisor_config no configurado.');
        }

        $xmlData = [
            'rnc_emisor' => $payload['rnc_emisor'] ?? '',
            'e_ncf' => $payload['e_ncf'] ?? '',
            'fecha_emision' => $payload['fecha_emision'] ?? date('d-m-Y'),
            'monto_total' => $payload['monto_total'] ?? 0,
            'rnc_comprador' => $payload['rnc_comprador'] ?? $emisor['rnc'],
            'estado' => $payload['estado'] ?? '1',
            'detalle_motivo' => $payload['detalle_motivo'] ?? null,
            'fecha_hora' => $payload['fecha_hora'] ?? date('d-m-Y H:i:s'),
        ];

        $unsignedXml = $this->builder->build($xmlData);

        $certPath = $this->resolveCertPath();
        $certContent = file_get_contents($certPath);
        if ($certContent === false) {
            throw new RuntimeException("No se puede leer el certificado: $certPath");
        }
        $certPassword = getenv('DGII_ECF_CERT_PASSWORD') ?: '';
        if ($certPassword === '') {
            throw new RuntimeException('DGII_ECF_CERT_PASSWORD no configurado.');
        }

        $signedXml = $this->signer->sign($certContent, $certPassword, $unsignedXml);
        $codigoSeguridad = $this->extractCodigoSeguridad($signedXml);

        $tokenInfo = $this->auth->autenticar([
            'environment' => $payload['ambiente'] ?? null,
        ]);
        $bearerToken = $tokenInfo['token'];
        $ambiente = $tokenInfo['ambiente'];

        $reception = $this->reception->enviarAprobacionComercial($signedXml, $bearerToken, [
            'environment' => $ambiente,
        ]);

        $trackId = $this->extractTrackId($reception);

        return [
            'signed_xml' => $signedXml,
            'codigo_seguridad' => $codigoSeguridad,
            'track_id' => $trackId,
            'estado' => $this->mapEstado($reception),
            'ambiente' => $ambiente,
            'fecha_emision_dgii' => date('Y-m-d H:i:s'),
            'dgii_response' => $reception['data'],
            'dgii_status_code' => $reception['status_code'],
        ];
    }

    private function resolveCertPath(): string
    {
        $configured = getenv('DGII_ECF_CERT_PATH') ?: '';
        if ($configured === '') {
            throw new RuntimeException('DGII_ECF_CERT_PATH no configurado.');
        }
        if (preg_match('/^[A-Za-z]:[\\\\\/]/', $configured) || str_starts_with($configured, '/')) {
            return $configured;
        }
        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR
            . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $configured);
    }

    private function extractCodigoSeguridad(string $signedXml): string
    {
        if (preg_match('/<SignatureValue>([^<]+)<\/SignatureValue>/i', $signedXml, $m)) {
            $clean = preg_replace('/[^A-Za-z0-9]/', '', $m[1]);
            return strtoupper(substr($clean, 0, 6));
        }
        return strtoupper(substr(sha1($signedXml), 0, 6));
    }

    private function extractTrackId(array $reception): ?string
    {
        if (!is_array($reception['data'])) {
            return null;
        }
        foreach (['trackId', 'TrackId', 'trackid'] as $key) {
            if (isset($reception['data'][$key])) {
                return (string) $reception['data'][$key];
            }
        }
        return null;
    }

    private function mapEstado(array $reception): string
    {
        $code = $reception['status_code'];
        $data = is_array($reception['data']) ? $reception['data'] : [];
        $estadoCodigo = $data['codigo'] ?? $data['estado'] ?? null;

        if ($code < 200 || $code >= 300) {
            return 'ERROR';
        }
        if (is_numeric($estadoCodigo)) {
            return match ((int) $estadoCodigo) {
                1 => 'ACEPTADO',
                2 => 'RECHAZADO',
                default => 'ENVIADO',
            };
        }
        return 'ENVIADO';
    }
}
