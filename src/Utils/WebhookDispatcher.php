<?php
require_once __DIR__ . '/../TenantResolver.php';

/**
 * Entrega documentos entrantes (e-CF recibido / aprobacion) a la URL webhook
 * del tenant integracion. El payload se firma HMAC-SHA256 con el webhook_secret
 * del tenant para que el cliente verifique autenticidad.
 *
 * Se invoca DESPUES de responder a DGII (idealmente tras fastcgi_finish_request)
 * para no demorar el acuse. Best-effort con reintentos; los fallos solo se logean.
 */
class WebhookDispatcher
{
    private const MAX_ATTEMPTS = 3;
    private const TIMEOUT_SECONDS = 5;

    public static function dispatch(array $tenant, string $event, array $data): void
    {
        $url = (string) ($tenant['webhook_url'] ?? '');
        if ($url === '') {
            return; // tenant sin webhook configurado
        }

        try {
            $secret = !empty($tenant['webhook_secret_encrypted'])
                ? TenantResolver::decrypt($tenant['webhook_secret_encrypted'])
                : '';

            $body = json_encode([
                'event'     => $event,
                'tenant_id' => (int) ($tenant['id'] ?? 0),
                'data'      => $data,
                'sent_at'   => date('c'),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $signature = $secret !== '' ? 'sha256=' . hash_hmac('sha256', $body, $secret) : '';
            self::deliver($url, $body, $signature, $event);
        } catch (Throwable $e) {
            error_log('[webhook] error preparando entrega (' . $event . '): ' . $e->getMessage());
        }
    }

    private static function deliver(string $url, string $body, string $signature, string $event): void
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            if (self::tryPost($url, $body, $signature)) {
                return;
            }
            if ($attempt < self::MAX_ATTEMPTS) {
                usleep(200000 * $attempt); // backoff corto: 0.2s, 0.4s
            }
        }
        error_log('[webhook] entrega fallida tras ' . self::MAX_ATTEMPTS . ' intentos (' . $event . '): ' . $url);
    }

    private static function tryPost(string $url, string $body, string $signature): bool
    {
        if (!function_exists('curl_init')) {
            return false;
        }
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        if ($signature !== '') {
            $headers[] = 'X-Gratex-Signature: ' . $signature;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (PHP_VERSION_ID < 80500) {
            curl_close($ch);
        }
        return $code >= 200 && $code < 300;
    }
}
