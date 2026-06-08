<?php
require_once(__DIR__ . '/TenantResolver.php');

/**
 * Resolves the signing certificate (.p12) + password to use for DGII signing.
 *
 *  - If a tenant is resolved AND has its own cert (`cert_path`), use that
 *    (decrypting `cert_pass_encrypted`). Covers multi-tenant: each emisor signs
 *    with its own certificate.
 *  - Otherwise fall back to the global `.env` cert (`DGII_ECF_CERT_PATH` /
 *    `DGII_ECF_CERT_PASSWORD`). This keeps single-tenant / flag-off behavior
 *    identical to today (Gratex uses the env cert).
 */
class CertResolver
{
    /**
     * @return array{content:string, password:string, path:string}
     */
    public static function resolve(): array
    {
        $tenant = class_exists('TenantResolver') ? TenantResolver::current() : null;

        if ($tenant !== null && !empty($tenant['cert_path'])) {
            $path = self::toAbsolute((string) $tenant['cert_path']);
            $content = @file_get_contents($path);
            if ($content === false) {
                throw new RuntimeException('No se pudo leer el certificado del tenant: ' . $path);
            }
            $password = !empty($tenant['cert_pass_encrypted'])
                ? TenantResolver::decrypt($tenant['cert_pass_encrypted'])
                : '';
            return ['content' => $content, 'password' => $password, 'path' => $path];
        }

        // Fallback: global env cert (comportamiento actual single-tenant).
        $configured = (string) (getenv('DGII_ECF_CERT_PATH') ?: ($_ENV['DGII_ECF_CERT_PATH'] ?? ''));
        if ($configured === '') {
            throw new RuntimeException('DGII_ECF_CERT_PATH no configurado.');
        }
        $path = self::toAbsolute($configured);
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('No se puede leer el certificado: ' . $path);
        }
        $password = (string) (getenv('DGII_ECF_CERT_PASSWORD') ?: ($_ENV['DGII_ECF_CERT_PASSWORD'] ?? ''));
        return ['content' => $content, 'password' => $password, 'path' => $path];
    }

    private static function toAbsolute(string $path): string
    {
        if (preg_match('/^[A-Za-z]:[\\\\\/]/', $path) || str_starts_with($path, '/')) {
            return $path;
        }
        // __DIR__ = <root>/src  ->  project root = dirname(__DIR__)
        return dirname(__DIR__) . DIRECTORY_SEPARATOR
            . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }
}
