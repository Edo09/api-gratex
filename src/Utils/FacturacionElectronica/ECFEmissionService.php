<?php

require_once __DIR__ . '/DgiiAuthService.php';
require_once __DIR__ . '/DgiiXmlSigner.php';
require_once __DIR__ . '/DgiiReceptionService.php';
require_once __DIR__ . '/ECFXmlBuilder.php';
require_once __DIR__ . '/RFCEXmlBuilder.php';
require_once __DIR__ . '/../../Models/EmisorConfigModel.php';
require_once __DIR__ . '/../../Models/ncfModel.php';

/**
 * Orchestrates the full e-CF emission flow:
 *   1. Reserve next e-NCF for the requested type
 *   2. Build the XML
 *   3. Sign with the certificate
 *   4. Get DGII auth token
 *   5. POST signed XML to DGII reception
 *   6. Return result with track_id, status, signed XML and codigo_seguridad
 */
class ECFEmissionService
{
    private const RFCE_THRESHOLD = 250000.00;

    private DgiiAuthService $auth;
    private DgiiXmlSigner $signer;
    private DgiiReceptionService $reception;
    private ECFXmlBuilder $builder;
    private RFCEXmlBuilder $rfceBuilder;
    private EmisorConfigModel $emisorModel;
    private ncfModel $ncfModel;

    public function __construct()
    {
        $this->auth = new DgiiAuthService();
        $this->signer = new DgiiXmlSigner();
        $this->reception = new DgiiReceptionService($this->auth);
        $this->builder = new ECFXmlBuilder();
        $this->rfceBuilder = new RFCEXmlBuilder();
        $this->emisorModel = new EmisorConfigModel();
        $this->ncfModel = new ncfModel();
    }

    /**
     * @param array $payload Required keys:
     *   tipo_ecf, comprador (assoc), items (array of assoc), totales (assoc).
     * @return array {
     *   e_ncf, tipo_ecf, signed_xml, codigo_seguridad,
     *   track_id, estado, ambiente, fecha_emision_dgii, dgii_response
     * }
     */
    public function emitir(array $payload): array
    {
        $tipoEcf = (string) ($payload['tipo_ecf'] ?? '');
        if (!preg_match('/^(31|32|33|34|41|43|44|45|46|47)$/', $tipoEcf)) {
            throw new RuntimeException('tipo_ecf invalido: ' . $tipoEcf);
        }

        $emisor = $this->emisorModel->get();
        if (!$emisor) {
            throw new RuntimeException('emisor_config no configurado. Insertar registro id=1 con datos fiscales.');
        }

        $eNcfOverride = $payload['e_ncf'] ?? null;
        if ($eNcfOverride !== null && $eNcfOverride !== '') {
            if (!preg_match('/^E' . $tipoEcf . '\d{10}$/', (string) $eNcfOverride)) {
                throw new RuntimeException('e_ncf override invalido: debe ser E' . $tipoEcf . ' + 10 digitos. Recibido: ' . $eNcfOverride);
            }
            $eNcf = (string) $eNcfOverride;
        } else {
            $eNcf = $this->ncfModel->dispenseNextECF('E' . $tipoEcf);
            if ($eNcf === null) {
                throw new RuntimeException('No se pudo asignar e-NCF para el tipo E' . $tipoEcf);
            }
        }

        $emisorBase = [
            'rnc' => $emisor['rnc'],
            'razon_social' => $emisor['razon_social'],
            'nombre_comercial' => $emisor['nombre_comercial'] ?? null,
            'sucursal' => $emisor['sucursal'] ?? null,
            'direccion' => $emisor['direccion'],
            'municipio' => $emisor['municipio'] ?? null,
            'provincia' => $emisor['provincia'] ?? null,
            'telefono' => $emisor['telefono'] ?? null,
            'correo' => $emisor['correo'] ?? null,
            'website' => $emisor['website'] ?? null,
            'actividad_economica' => $emisor['actividad_economica'] ?? null,
        ];
        $strictInput = !empty($payload['strict_input']);
        $emisorOverride = is_array($payload['emisor_override'] ?? null) ? $payload['emisor_override'] : [];
        $emisorMerged = $strictInput
            ? array_merge($emisorBase, $emisorOverride)
            : array_merge($emisorBase, array_filter($emisorOverride, fn($v) => $v !== null && $v !== ''));

        $xmlData = [
            'tipo_ecf' => $tipoEcf,
            'e_ncf' => $eNcf,
            'fecha_emision' => $payload['fecha_emision'] ?? date('d-m-Y'),
            'fecha_vencimiento_secuencia' => $payload['fecha_vencimiento_secuencia']
                ?? $emisor['fecha_vencimiento_secuencia']
                ?? '31-12-2030',
            'tipo_ingresos' => $payload['tipo_ingresos'] ?? '01',
            'tipo_pago' => array_key_exists('tipo_pago', $payload) ? $payload['tipo_pago'] : 1,
            'fecha_limite_pago' => $payload['fecha_limite_pago'] ?? null,
            'termino_pago' => $payload['termino_pago'] ?? null,
            'tipo_cuenta_pago' => $payload['tipo_cuenta_pago'] ?? null,
            'numero_cuenta_pago' => $payload['numero_cuenta_pago'] ?? null,
            'banco_pago' => $payload['banco_pago'] ?? null,
            'fecha_desde' => $payload['fecha_desde'] ?? null,
            'fecha_hasta' => $payload['fecha_hasta'] ?? null,
            'total_paginas' => $payload['total_paginas'] ?? null,
            'indicador_monto_gravado' => $payload['indicador_monto_gravado'] ?? null,
            'indicador_nota_credito' => $payload['indicador_nota_credito'] ?? null,
            'emisor' => $emisorMerged,
            'comprador' => $payload['comprador'] ?? [],
            'items' => $payload['items'] ?? [],
            'totales' => $payload['totales'] ?? [],
            'informacion_referencia' => $payload['informacion_referencia'] ?? null,
            'fecha_hora_firma' => $payload['fecha_hora_firma'] ?? date('d-m-Y H:i:s'),
        ];

        $fechaEmisionDgii = DateTime::createFromFormat('d-m-Y H:i:s', $xmlData['fecha_hora_firma'])->format('Y-m-d H:i:s');

        $unsignedXml = $this->builder->build($xmlData);

        $certPath = $this->resolveCertPath();
        $certContent = file_get_contents($certPath);
        if ($certContent === false) {
            throw new RuntimeException('No se puede leer el certificado: ' . $certPath);
        }
        $certPassword = (string) (getenv('DGII_ECF_CERT_PASSWORD') ?: '');
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

        $montoTotal = (float) ($payload['totales']['monto_total'] ?? 0);
        $usaRFCE = $tipoEcf === '32' && $montoTotal < self::RFCE_THRESHOLD;

        if ($usaRFCE) {
            $rfceEmisorOverride = is_array($payload['rfce_emisor_override'] ?? null) ? $payload['rfce_emisor_override'] : [];
            $rfceCompradorOverride = is_array($payload['rfce_comprador_override'] ?? null) ? $payload['rfce_comprador_override'] : [];

            $rfceEmisor = array_merge(
                [
                    'rnc' => $emisorMerged['rnc'],
                    'razon_social' => $emisorMerged['razon_social'],
                ],
                array_filter($rfceEmisorOverride, fn($v) => $v !== null && $v !== '')
            );
            $rfceComprador = array_merge(
                [
                    'rnc' => $payload['comprador']['rnc'] ?? null,
                    'identificador_extranjero' => $payload['comprador']['identificador_extranjero'] ?? null,
                    'razon_social' => $payload['comprador']['razon_social'] ?? null,
                ],
                array_filter($rfceCompradorOverride, fn($v) => $v !== null && $v !== '')
            );

            $rfceXmlData = [
                'tipo_ecf' => '32',
                'e_ncf' => $eNcf,
                'tipo_ingresos' => $xmlData['tipo_ingresos'],
                'tipo_pago' => $xmlData['tipo_pago'],
                'formas_pago' => $payload['formas_pago'] ?? [],
                'emisor' => $rfceEmisor,
                'fecha_emision' => $xmlData['fecha_emision'],
                'comprador' => $rfceComprador,
                'totales' => $payload['totales'] ?? [],
                'codigo_seguridad_ecf' => $codigoSeguridad,
            ];

            $unsignedRfce = $this->rfceBuilder->build($rfceXmlData);
            $signedRfce = $this->signer->sign($certContent, $certPassword, $unsignedRfce);

            $rfceReception = $this->reception->recibirResumen($signedRfce, $bearerToken, [
                'environment' => $ambiente,
            ]);

            $rfceEstado = $this->mapEstado($rfceReception);
            $rfceTrackId = $this->extractTrackId($rfceReception);

            return [
                'e_ncf' => $eNcf,
                'tipo_ecf' => $tipoEcf,
                'signed_xml' => $signedXml,
                'codigo_seguridad' => $codigoSeguridad,
                'track_id' => null,
                'estado' => 'RFCE_' . $rfceEstado,
                'ambiente' => $ambiente,
                'fecha_emision_dgii' => $fechaEmisionDgii,
                'dgii_response' => null,
                'dgii_status_code' => null,
                'flujo' => 'RFCE',
                'monto_total' => $montoTotal,
                'rfce_xml' => $signedRfce,
                'rfce_track_id' => $rfceTrackId,
                'rfce_estado' => $rfceEstado,
                'rfce_response' => $rfceReception['data'],
                'rfce_status_code' => $rfceReception['status_code'],
                'aviso' => 'E32 con monto < 250,000: RFCE enviado a DGII. La factura integra debe cargarse manualmente al portal DGII.',
            ];
        }

        $reception = $this->reception->recibir($signedXml, $bearerToken, [
            'environment' => $ambiente,
        ]);

        $estado = $this->mapEstado($reception);
        $trackId = $this->extractTrackId($reception);

        return [
            'e_ncf' => $eNcf,
            'tipo_ecf' => $tipoEcf,
            'signed_xml' => $signedXml,
            'codigo_seguridad' => $codigoSeguridad,
            'track_id' => $trackId,
            'estado' => $estado,
            'ambiente' => $ambiente,
            'fecha_emision_dgii' => date('Y-m-d H:i:s'),
            'dgii_response' => $reception['data'],
            'dgii_status_code' => $reception['status_code'],
            'flujo' => 'ECF',
        ];
    }

    public function consultarEstado(string $trackId, string $eNcf, ?string $ambiente = null): array
    {
        $emisor = $this->emisorModel->get();
        if (!$emisor) {
            throw new RuntimeException('emisor_config no configurado.');
        }
        $tokenInfo = $this->auth->autenticar(['environment' => $ambiente]);
        return $this->reception->consultarEstado(
            $trackId,
            $emisor['rnc'],
            $eNcf,
            $tokenInfo['token'],
            ['environment' => $tokenInfo['ambiente']]
        );
    }

    private function resolveCertPath(): string
    {
        $configured = (string) (getenv('DGII_ECF_CERT_PATH') ?: '');
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
            $clean = preg_replace('/\s+/', '', $m[1]);
            return substr($clean, 0, 6);
        }
        return substr(sha1($signedXml), 0, 6);
    }

    private function extractTrackId(array $reception): ?string
    {
        $data = $reception['data'];
        if (!is_array($data)) {
            return null;
        }
        foreach (['trackId', 'TrackId', 'trackid'] as $key) {
            if (isset($data[$key])) {
                return (string) $data[$key];
            }
        }
        return null;
    }

    private function mapEstado(array $reception): string
    {
        $code = $reception['status_code'];
        $data = is_array($reception['data']) ? $reception['data'] : [];
        $estadoCodigo = $data['codigo'] ?? $data['estado'] ?? null;

        if ($code >= 200 && $code < 300) {
            if (is_numeric($estadoCodigo)) {
                $estadoCodigo = (int) $estadoCodigo;
                if ($estadoCodigo === 1) return 'ACEPTADO';
                if ($estadoCodigo === 2) return 'ACEPTADO_CONDICIONAL';
                if ($estadoCodigo === 3) return 'EN_PROCESO';
                if ($estadoCodigo === 4) return 'RECHAZADO';
            }
            return 'ENVIADO';
        }
        return 'ERROR';
    }
}
