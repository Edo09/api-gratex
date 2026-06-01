<?php

/**
 * Validates incoming signed XML documents (e-CF reception, commercial approval).
 *
 * Performs:
 *   - Well-formedness check (loadXML succeeds)
 *   - XMLDSig signature verification using the embedded X509Certificate
 *   - Digest re-computation against canonicalized payload
 *   - Helper extraction of common fields
 *
 * NOTE: Issuer trust validation (CA chain) is intentionally minimal — DGII only
 * requires that the signature was made with the private key of the certificate
 * embedded in the document and that the certificate is well-formed. Production
 * deployments may want to additionally verify the certificate against DGII's
 * trusted CAs.
 */
class IncomingXmlValidator
{
    public function loadAndValidate(string $xmlContent): array
    {
        if (trim($xmlContent) === '') {
            return $this->fail('xml_vacio', 'El contenido XML esta vacio.');
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $document->preserveWhiteSpace = false;
        $document->formatOutput = false;

        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xmlContent, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded || $document->documentElement === null) {
            return $this->fail('xml_invalido', 'El XML no es valido o esta mal formado.');
        }

        $signatureValidation = $this->verifySignature($document);

        return [
            'ok' => $signatureValidation['ok'],
            'document' => $document,
            'root_name' => $document->documentElement->localName,
            'firma' => $signatureValidation['ok'] ? 'OK' : 'INVALIDA',
            'firma_detalle' => $signatureValidation['detalle'],
            'firma_rnc' => $signatureValidation['rnc'] ?? null,
            'firma_subject' => $signatureValidation['subject'] ?? null,
        ];
    }

    /**
     * Returns scalar text of a tag inside the e-CF / ACECF tree.
     * Unaware of namespaces (XSD does not declare them).
     */
    public function getText(DOMDocument $doc, string $tagName): ?string
    {
        $elements = $doc->getElementsByTagName($tagName);
        if ($elements->length === 0) {
            return null;
        }
        $value = trim($elements->item(0)->textContent);
        return $value === '' ? null : $value;
    }

    public function getFloat(DOMDocument $doc, string $tagName): ?float
    {
        $value = $this->getText($doc, $tagName);
        return $value === null ? null : (float) $value;
    }

    public function getDate(DOMDocument $doc, string $tagName): ?string
    {
        $value = $this->getText($doc, $tagName);
        if ($value === null) {
            return null;
        }
        if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $value, $m)) {
            return sprintf('%s-%s-%s', $m[3], $m[2], $m[1]);
        }
        $ts = strtotime($value);
        return $ts ? date('Y-m-d', $ts) : null;
    }

    private function verifySignature(DOMDocument $document): array
    {
        $signatures = $document->getElementsByTagName('Signature');
        if ($signatures->length === 0) {
            return ['ok' => false, 'detalle' => 'El XML no contiene Signature.'];
        }
        $signature = $signatures->item(0);

        $signedInfoNodes = $signature->getElementsByTagName('SignedInfo');
        $signatureValueNodes = $signature->getElementsByTagName('SignatureValue');
        $digestValueNodes = $signature->getElementsByTagName('DigestValue');
        $x509Nodes = $signature->getElementsByTagName('X509Certificate');

        if (
            $signedInfoNodes->length === 0
            || $signatureValueNodes->length === 0
            || $digestValueNodes->length === 0
            || $x509Nodes->length === 0
        ) {
            return ['ok' => false, 'detalle' => 'Signature incompleta (faltan SignedInfo / SignatureValue / DigestValue / X509Certificate).'];
        }

        $signedInfoCanonical = $signedInfoNodes->item(0)->C14N();
        $signatureValueB64 = preg_replace('/\s+/', '', $signatureValueNodes->item(0)->textContent);
        $digestValueB64 = preg_replace('/\s+/', '', $digestValueNodes->item(0)->textContent);
        $x509Raw = preg_replace('/\s+/', '', $x509Nodes->item(0)->textContent);

        $signatureBin = base64_decode($signatureValueB64, true);
        if ($signatureBin === false) {
            return ['ok' => false, 'detalle' => 'SignatureValue no es base64 valido.'];
        }

        $certPem = "-----BEGIN CERTIFICATE-----\n" . chunk_split($x509Raw, 64, "\n") . '-----END CERTIFICATE-----';
        $publicKey = openssl_pkey_get_public($certPem);
        if ($publicKey === false) {
            return ['ok' => false, 'detalle' => 'No se pudo cargar la llave publica del X509Certificate.'];
        }

        $verifyResult = openssl_verify($signedInfoCanonical, $signatureBin, $publicKey, OPENSSL_ALGO_SHA256);
        if ($verifyResult !== 1) {
            return ['ok' => false, 'detalle' => 'La firma de SignedInfo no coincide con la llave publica.'];
        }

        // Remove Signature from original document, C14N root, then restore.
        // Must use original doc (not a clone) — importNode changes namespace
        // context and breaks C14N output vs what the signer computed.
        $sigParent = $signature->parentNode;
        $sigParent->removeChild($signature);
        $payloadCanonical = $document->documentElement->C14N();
        $sigParent->appendChild($signature);
        $expectedDigest = base64_encode(hash('sha256', $payloadCanonical, true));

        if (!hash_equals($expectedDigest, $digestValueB64)) {
            return ['ok' => false, 'detalle' => 'El DigestValue no coincide con el contenido del documento.'];
        }

        $certInfo = openssl_x509_parse($certPem) ?: [];
        $subject = $certInfo['subject'] ?? [];
        $rnc = $this->extractRncFromSubject($subject);

        return [
            'ok' => true,
            'detalle' => 'Firma valida.',
            'rnc' => $rnc,
            'subject' => $subject,
        ];
    }

    private function extractRncFromSubject(array $subject): ?string
    {
        $candidates = [
            $subject['serialNumber'] ?? null,
            $subject['SERIALNUMBER'] ?? null,
            $subject['CN'] ?? null,
        ];
        foreach ($candidates as $value) {
            if (!is_string($value)) {
                continue;
            }
            if (preg_match('/(\d{9,11})/', $value, $m)) {
                return $m[1];
            }
        }
        return null;
    }

    private function fail(string $codigo, string $mensaje): array
    {
        return [
            'ok' => false,
            'document' => null,
            'root_name' => null,
            'firma' => 'NO_VERIFICADA',
            'firma_detalle' => $mensaje,
            'firma_codigo' => $codigo,
        ];
    }
}
