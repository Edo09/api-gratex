<?php
require_once __DIR__ . '/TenantResolver.php';

/**
 * Resuelve el ambiente DGII activo (testecf | certecf | ecf) con prioridad
 * PER-TENANT:
 *
 *   1. Multi-tenant con tenant resuelto -> tenants.ambiente (cada tenant tiene
 *      el suyo: certecf durante su certificacion, ecf al certificar).
 *   2. Fallback -> DGII_ECF_ENVIRONMENT global (getenv / $_ENV / .env).
 *
 * Punto unico de verdad: emision (servicios DGII), filtros de listados/stats
 * (facturas, gastos, recibidos), secuencias NCF por ambiente y el ambiente que
 * se graba al recibir documentos — todos deben pasar por aqui.
 *
 * Un override explicito (param `ambiente` de un request / runner de cert) SIGUE
 * mandando sobre esto: los callers solo consultan AmbienteResolver cuando no
 * recibieron ambiente explicito.
 */
class AmbienteResolver
{
    public static function active(): ?string
    {
        // 1) Ambiente del tenant resuelto (app o integracion).
        if (self::multiTenant()) {
            $tenant = TenantResolver::current();
            if ($tenant !== null && !empty($tenant['ambiente'])) {
                return self::normalize((string) $tenant['ambiente']);
            }
        }

        // 2) Global del server.
        $val = getenv('DGII_ECF_ENVIRONMENT') ?: ($_ENV['DGII_ECF_ENVIRONMENT'] ?? null);
        if (!$val) {
            $envFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
            if (is_file($envFile)) {
                $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                        continue;
                    }
                    [$key, $value] = explode('=', $line, 2);
                    if (trim($key) === 'DGII_ECF_ENVIRONMENT') {
                        $val = trim($value, " '\"");
                        break;
                    }
                }
            }
        }
        return $val ? self::normalize($val) : null;
    }

    /** Normaliza alias (cert/prod/test, etc.) al nombre canonico DGII. */
    public static function normalize(?string $val): ?string
    {
        if ($val === null || trim($val) === '') {
            return null;
        }
        $aliases = [
            'certecf' => 'certecf', 'cert' => 'certecf', 'certificacion' => 'certecf',
            'ecf'     => 'ecf',     'prod' => 'ecf',      'produccion'   => 'ecf',
            'testecf' => 'testecf', 'test' => 'testecf',
        ];
        return $aliases[strtolower(trim($val))] ?? strtolower(trim($val));
    }

    private static function multiTenant(): bool
    {
        return filter_var(
            getenv('MULTI_TENANT_ENABLED') ?: ($_ENV['MULTI_TENANT_ENABLED'] ?? false),
            FILTER_VALIDATE_BOOLEAN
        );
    }
}
