<?php

/**
 * Minimal XMLDSig signer for DGII seed XML files.
 *
 * The signing structure follows the approach used by
 * platinum-place/php-dgii-xml-signer, adapted locally so this API can work
 * without introducing Composer into the current project layout.
 */
class DgiiXmlSigner
{
    public function sign(string $certificateContent, string $password, string $xmlContent): string
    {
        $this->ensureExtensions();

        if (!openssl_pkcs12_read($certificateContent, $certificates, $password)) {
            throw new RuntimeException("Unable to read certificate. Verify the password or OpenSSL legacy configuration.");
        }

        if (empty($certificates['cert']) || empty($certificates['pkey'])) {
            throw new RuntimeException('The certificate must include both the X509 certificate and private key.');
        }

        $privateKey = openssl_pkey_get_private($certificates['pkey']);
        if ($privateKey === false) {
            throw new RuntimeException('Unable to load the certificate private key.');
        }

        $document = $this->loadXml($xmlContent);
        $root = $document->documentElement;

        if ($root === null) {
            throw new RuntimeException('The XML document has no root element.');
        }

        $canonicalData = $root->C14N();
        if ($canonicalData === false) {
            throw new RuntimeException('Unable to canonicalize the XML document.');
        }

        $digestValue = base64_encode(hash('sha256', $canonicalData, true));
        $signedInfoElement = $this->appendSignature($document, $digestValue, $certificates['cert']);
        $signedInfoCanonical = $signedInfoElement->C14N();

        if ($signedInfoCanonical === false) {
            throw new RuntimeException('Unable to canonicalize the SignedInfo element.');
        }

        $signature = '';
        if (!openssl_sign($signedInfoCanonical, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Unable to sign the XML document.');
        }

        $signatureValue = $this->findFirstElement($document, 'SignatureValue');
        if ($signatureValue === null) {
            throw new RuntimeException('SignatureValue element was not generated.');
        }

        $signatureValue->nodeValue = base64_encode($signature);

        $result = $document->saveXML();
        if ($result === false) {
            throw new RuntimeException('Unable to serialize the signed XML document.');
        }

        return $result;
    }

    private function ensureExtensions(): void
    {
        if (!extension_loaded('dom')) {
            throw new RuntimeException('The PHP DOM extension is required to sign XML.');
        }

        if (!extension_loaded('openssl')) {
            throw new RuntimeException('The PHP OpenSSL extension is required to sign XML.');
        }
    }

    private function loadXml(string $xmlContent): DOMDocument
    {
        $document = new DOMDocument('1.0', 'utf-8');
        $document->preserveWhiteSpace = false;
        $document->formatOutput = false;

        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xmlContent, LIBXML_NONET);

        if (!$loaded) {
            $errors = libxml_get_errors();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            $message = 'Unable to load provided XML.';
            if (!empty($errors)) {
                $message .= ' ' . trim($errors[0]->message);
            }

            throw new RuntimeException($message);
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document;
    }

    private function appendSignature(DOMDocument $document, string $digestValue, string $certificatePem): DOMElement
    {
        if ($document->documentElement === null) {
            throw new RuntimeException('Root element not defined.');
        }

        $signatureElement = $document->createElement('Signature');
        $signatureElement->setAttribute('xmlns', 'http://www.w3.org/2000/09/xmldsig#');
        $document->documentElement->appendChild($signatureElement);

        $signedInfoElement = $document->createElement('SignedInfo');
        $signatureElement->appendChild($signedInfoElement);

        $canonicalizationMethodElement = $document->createElement('CanonicalizationMethod');
        $canonicalizationMethodElement->setAttribute('Algorithm', 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315');
        $signedInfoElement->appendChild($canonicalizationMethodElement);

        $signatureMethodElement = $document->createElement('SignatureMethod');
        $signatureMethodElement->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256');
        $signedInfoElement->appendChild($signatureMethodElement);

        $referenceElement = $document->createElement('Reference');
        $referenceElement->setAttribute('URI', '');
        $signedInfoElement->appendChild($referenceElement);

        $transformsElement = $document->createElement('Transforms');
        $referenceElement->appendChild($transformsElement);

        $transformElement = $document->createElement('Transform');
        $transformElement->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#enveloped-signature');
        $transformsElement->appendChild($transformElement);

        $digestMethodElement = $document->createElement('DigestMethod');
        $digestMethodElement->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmlenc#sha256');
        $referenceElement->appendChild($digestMethodElement);

        $digestValueElement = $document->createElement('DigestValue', $digestValue);
        $referenceElement->appendChild($digestValueElement);

        $signatureValueElement = $document->createElement('SignatureValue', '');
        $signatureElement->appendChild($signatureValueElement);

        $keyInfoElement = $document->createElement('KeyInfo');
        $signatureElement->appendChild($keyInfoElement);

        $x509DataElement = $document->createElement('X509Data');
        $keyInfoElement->appendChild($x509DataElement);

        $x509CertificateElement = $document->createElement('X509Certificate', $this->certificateToRawBase64($certificatePem));
        $x509DataElement->appendChild($x509CertificateElement);

        return $signedInfoElement;
    }

    private function certificateToRawBase64(string $certificatePem): string
    {
        return str_replace(
            ['-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----', "\r", "\n", ' '],
            '',
            $certificatePem
        );
    }

    private function findFirstElement(DOMDocument $document, string $name): ?DOMElement
    {
        $elements = $document->getElementsByTagName($name);
        $element = $elements->item(0);

        return $element instanceof DOMElement ? $element : null;
    }
}
