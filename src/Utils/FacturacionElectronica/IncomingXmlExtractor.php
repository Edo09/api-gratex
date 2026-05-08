<?php

/**
 * Extracts the XML payload from incoming requests.
 *
 * Supports:
 *   - multipart/form-data with the standard "xml" file field (DGII spec)
 *   - any uploaded file (first $_FILES entry) as a fallback
 *   - text/xml or application/xml raw bodies
 */
class IncomingXmlExtractor
{
    public function extract(): ?string
    {
        if (!empty($_FILES)) {
            $first = $_FILES['xml'] ?? $_FILES[array_key_first($_FILES)];
            if (
                isset($first['tmp_name'])
                && is_string($first['tmp_name'])
                && is_readable($first['tmp_name'])
                && (int) ($first['error'] ?? UPLOAD_ERR_OK) === UPLOAD_ERR_OK
            ) {
                $content = file_get_contents($first['tmp_name']);
                return $content !== false ? $content : null;
            }
        }

        $rawBody = file_get_contents('php://input');
        if (is_string($rawBody) && trim($rawBody) !== '' && str_starts_with(trim($rawBody), '<')) {
            return $rawBody;
        }

        return null;
    }
}
