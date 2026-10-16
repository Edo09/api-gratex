<?php

/**
 * Ambiente SANDBOX de integracion (tenants.ambiente = 'sandbox').
 *
 * Para que un cliente tipo integracion pruebe nuestra API ANTES de certificarse
 * en la DGII: arma y firma el XML de verdad, pero NADA sale hacia la DGII y no
 * hay valor fiscal.
 *
 *  - Firma con un certificado autofirmado descartable (nunca CertResolver): el
 *    tenant no necesita .p12 y, sobre todo, no cae al cert global del .env, que
 *    es el de produccion de Gratex.
 *  - El codigo de seguridad sale de la firma, igual que en produccion, asi que la
 *    Representacion Impresa y el QR se pueden probar con datos coherentes.
 *  - El track_id es 'SANDBOX-<uuid>' y la respuesta de la DGII se simula con la
 *    misma forma que la real (codigo/estado/trackId/mensajes).
 *
 * Solo aplica a integracion: lo decide tenants.ambiente (lo fija el servidor, el
 * cliente no lo elige). Para pasar a certificacion: ver docs/api/integracion.md.
 */
final class SandboxEcf
{
    public const AMBIENTE = 'sandbox';
    public const PREFIJO_TRACK_ID = 'SANDBOX-';

    // La llave del cert sandbox no protege nada: cualquiera puede generar otro igual.
    private const CERT_PASS = 'gratex-sandbox';

    private const AVISO = 'Respuesta simulada por el ambiente sandbox de Gratex: no se envio a la DGII y este comprobante no tiene validez fiscal.';

    public static function activo($ambiente): bool
    {
        return is_string($ambiente) && strtolower(trim($ambiente)) === self::AMBIENTE;
    }

    public static function aviso(): string
    {
        return self::AVISO;
    }

    public static function esTrackId(string $trackId): bool
    {
        return str_starts_with($trackId, self::PREFIJO_TRACK_ID);
    }

    /**
     * Mismo contrato que CertResolver::resolve(). El .p12 se genera una vez y se
     * guarda en el directorio temporal del servidor; si no se puede escribir, se
     * genera en cada llamada (mas lento pero correcto).
     *
     * @return array{content:string, password:string, path:string}
     */
    public static function certificado(): array
    {
        $cache = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'gratex_sandbox_cert_v1.p12';

        $content = is_file($cache) ? @file_get_contents($cache) : false;
        if (is_string($content) && $content !== '' && @openssl_pkcs12_read($content, $leido, self::CERT_PASS)) {
            return ['content' => $content, 'password' => self::CERT_PASS, 'path' => $cache];
        }

        $content = self::generarCertificado();
        // Escritura atomica: otra peticion no debe leer un .p12 a medio escribir.
        $tmp = $cache . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $content) !== false && !@rename($tmp, $cache)) {
            @unlink($tmp);
        }
        return ['content' => $content, 'password' => self::CERT_PASS, 'path' => $cache];
    }

    public static function trackId(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);
        return self::PREFIJO_TRACK_ID
            . substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4)
            . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
    }

    /**
     * Respuesta simulada de la DGII, con la forma de DgiiReceptionService
     * (['status_code' => int, 'data' => array]). Sirve para el envio del e-CF, del
     * RFCE (que no trae trackId) y de la aprobacion comercial.
     *
     * @return array{status_code:int, data:array}
     */
    public static function respuesta(string $eNcf, bool $conTrackId = true, ?string $trackId = null): array
    {
        $data = [
            'codigo' => 1,
            'estado' => 'Aceptado',
            'encf' => $eNcf,
            'secuenciaUtilizada' => true,
            'fechaRecepcion' => date('d/m/Y H:i:s'),
            'mensajes' => [['valor' => self::AVISO, 'codigo' => 0]],
            'sandbox' => true,
        ];
        if ($conTrackId) {
            $data = ['trackId' => $trackId ?? self::trackId()] + $data;
        }
        return ['status_code' => 200, 'data' => $data];
    }

    /**
     * Consulta de estado simulada. Un track_id que no sea de sandbox no existe en
     * este ambiente (la DGII real tambien responde "no encontrado" a un trackId
     * ajeno); un RFCE (sin trackId) se da por aceptado.
     *
     * @return array{status_code:int, data:array}
     */
    public static function consulta(string $eNcf, string $trackId): array
    {
        if ($trackId !== '' && !self::esTrackId($trackId)) {
            return [
                'status_code' => 200,
                'data' => [
                    'trackId' => $trackId,
                    'codigo' => 0,
                    'estado' => 'No encontrado',
                    'encf' => $eNcf,
                    'mensajes' => [['valor' => 'El trackId no pertenece al ambiente sandbox.', 'codigo' => 0]],
                    'sandbox' => true,
                ],
            ];
        }
        return self::respuesta($eNcf, $trackId !== '', $trackId !== '' ? $trackId : null);
    }

    private static function generarCertificado(): string
    {
        $llave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($llave === false) {
            throw new RuntimeException('Sandbox: no se pudo generar la llave del certificado de prueba.');
        }
        $csr = openssl_csr_new(
            ['commonName' => 'GRATEX SANDBOX - SIN VALIDEZ FISCAL', 'organizationName' => 'Gratex Sandbox', 'countryName' => 'DO'],
            $llave,
            ['digest_alg' => 'sha256']
        );
        $x509 = $csr === false ? false : openssl_csr_sign($csr, null, $llave, 3650, ['digest_alg' => 'sha256']);
        if ($x509 === false || !openssl_pkcs12_export($x509, $p12, $llave, self::CERT_PASS)) {
            throw new RuntimeException('Sandbox: no se pudo generar el certificado de prueba.');
        }
        return $p12;
    }
}
