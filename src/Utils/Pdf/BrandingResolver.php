<?php
/**
 * Branding por tenant para la Representacion Impresa (facturas y cotizaciones).
 *
 * Lee plantilla, color de acento y logo del tenant resuelto (master.tenants:
 * pdf_template, pdf_accent_color, logo_path) con defaults seguros para modo
 * single-tenant (TenantResolver ausente o sin tenant): plantilla 'clasico',
 * sin acento, logo global.
 *
 * El acento solo cambia rellenos/lineas; el color del texto sobre el acento
 * lo decide contrastText() (luminancia YIQ) — el tenant nunca lo elige, para
 * que la RI siga siendo legible con cualquier color.
 */
class BrandingResolver
{
    /** Plantillas predefinidas. Los disenos a la medida usan 'custom:<nombre>'. */
    public const TEMPLATES = ['clasico', 'moderno', 'compacto'];

    public const DEFAULT_TEMPLATE = 'clasico';

    /** Prefijo de plantillas a la medida (src/Utils/Pdf/Custom/). */
    public const CUSTOM_PREFIX = 'custom:';

    /** Cache por request (Header() de FPDF corre en cada pagina). */
    private static ?array $resolved = null;

    /**
     * Branding efectivo del tenant actual.
     * @return array{template:string, accent:?array{0:int,1:int,2:int}, accent_hex:?string, logo_path:?string}
     */
    public static function resolve(): array
    {
        if (self::$resolved !== null) {
            return self::$resolved;
        }
        $template = self::DEFAULT_TEMPLATE;
        $accentHex = null;
        if (class_exists('TenantResolver')) {
            $tenant = TenantResolver::current();
            if ($tenant) {
                $t = trim((string) ($tenant['pdf_template'] ?? ''));
                if ($t !== '' && self::isValidTemplate($t)) {
                    $template = $t;
                }
                $hex = trim((string) ($tenant['pdf_accent_color'] ?? ''));
                if (self::isValidHex($hex)) {
                    $accentHex = strtoupper($hex);
                }
            }
        }
        self::$resolved = [
            'template'   => $template,
            'accent'     => $accentHex !== null ? self::hexToRgb($accentHex) : null,
            'accent_hex' => $accentHex,
            'logo_path'  => self::logoPath(),
        ];
        return self::$resolved;
    }

    /**
     * Ruta del sello a estampar en el pie (plantilla clasica).
     * Prioridad: convencion sellos/<tenant_id>.png|jpg|jpeg -> sello global
     * (sello.png) SOLO si no hay tenant resuelto. Igual que con el logo, el
     * sello de una empresa no puede aparecer en la factura de otra.
     */
    public static function selloPath(): ?string
    {
        $root = self::projectRoot();
        $tenant = class_exists('TenantResolver') ? TenantResolver::current() : null;
        if ($tenant !== null) {
            foreach (['png', 'jpg', 'jpeg'] as $ext) {
                $p = $root . '/sellos/' . (int) ($tenant['id'] ?? 0) . '.' . $ext;
                if (is_file($p)) {
                    return $p;
                }
            }
            return null;
        }
        $global = $root . '/sello.png';
        return is_file($global) ? $global : null;
    }

    /**
     * Ruta del logo a usar en la Representacion Impresa.
     * Prioridad: ruta explicita en DB (tenants.logo_path) -> convencion
     * logos/<tenant_id>.png|jpg|jpeg. El logo global (logo2020.png) es el de
     * Gratex y SOLO aplica sin tenant resuelto: un tenant sin logo propio no
     * imprime ninguno, nunca el de otra empresa. Null si no hay.
     */
    public static function logoPath(): ?string
    {
        $root = self::projectRoot();
        if (class_exists('TenantResolver')) {
            $tenant = TenantResolver::current();
            if ($tenant) {
                // 1) Ruta explicita en DB (tenants.logo_path).
                if (!empty($tenant['logo_path'])) {
                    $p = $root . '/' . ltrim((string) $tenant['logo_path'], '/');
                    if (is_file($p)) {
                        return $p;
                    }
                }
                // 2) Fallback por convencion logos/<tenant_id>.<ext>.
                if (!empty($tenant['id'])) {
                    foreach (['png', 'jpg', 'jpeg'] as $ext) {
                        $p = $root . '/logos/' . (int) $tenant['id'] . '.' . $ext;
                        if (is_file($p)) {
                            return $p;
                        }
                    }
                }
            }
        }
        // logo2020.png es el logo de Gratex y viaja en el repo, asi que estaba
        // saliendo en la representacion impresa de CUALQUIER tenant sin logo propio.
        // Solo aplica cuando no hay tenant resuelto (preview / single-tenant): la
        // marca de otra empresa en una factura fiscal es peor que no poner logo.
        if (class_exists('TenantResolver') && TenantResolver::current() !== null) {
            return null;
        }
        $global = $root . '/logo2020.png';
        return is_file($global) ? $global : null;
    }

    /**
     * Valida un nombre de plantilla: predefinida o custom:<nombre> cuyo
     * archivo exista en src/Utils/Pdf/Custom/.
     */
    public static function isValidTemplate(string $name): bool
    {
        if (in_array($name, self::TEMPLATES, true)) {
            return true;
        }
        return self::customTemplateFile($name) !== null;
    }

    /**
     * Archivo PHP de una plantilla custom:<nombre>, o null si el valor no es
     * custom o el archivo no existe. El nombre se sanitiza a [a-z0-9_] para
     * que el valor en DB jamas pueda salirse de Pdf/Custom/.
     * Convencion: custom:tenant7 -> Custom/Tenant7Template.php (clase Tenant7Template).
     */
    public static function customTemplateFile(string $value): ?string
    {
        if (strpos($value, self::CUSTOM_PREFIX) !== 0) {
            return null;
        }
        $name = substr($value, strlen(self::CUSTOM_PREFIX));
        if ($name === '' || !preg_match('/^[a-z0-9_]+$/', $name)) {
            return null;
        }
        $file = __DIR__ . '/Custom/' . self::customClassName($value) . '.php';
        return is_file($file) ? $file : null;
    }

    /** Nombre de clase de una plantilla custom: 'custom:tenant7' -> 'Tenant7Template'. */
    public static function customClassName(string $value): string
    {
        $name = substr($value, strlen(self::CUSTOM_PREFIX));
        $studly = str_replace(' ', '', ucwords(str_replace('_', ' ', $name)));
        return $studly . 'Template';
    }

    /**
     * Plantillas disponibles para un tenant: las predefinidas + las custom
     * cuyo archivo exista (custom:tenant<id> del propio tenant).
     * @return string[]
     */
    public static function availableTemplates(?int $tenantId = null): array
    {
        $out = self::TEMPLATES;
        if ($tenantId !== null) {
            $own = self::CUSTOM_PREFIX . 'tenant' . $tenantId;
            if (self::customTemplateFile($own) !== null) {
                $out[] = $own;
            }
        }
        return $out;
    }

    public static function isValidHex(string $hex): bool
    {
        return (bool) preg_match('/^#[0-9A-Fa-f]{6}$/', $hex);
    }

    /** @return array{0:int,1:int,2:int} */
    public static function hexToRgb(string $hex): array
    {
        return [
            (int) hexdec(substr($hex, 1, 2)),
            (int) hexdec(substr($hex, 3, 2)),
            (int) hexdec(substr($hex, 5, 2)),
        ];
    }

    /**
     * Color de texto (negro o blanco) legible sobre un relleno dado,
     * por luminancia YIQ.
     * @param array{0:int,1:int,2:int} $rgb
     * @return array{0:int,1:int,2:int}
     */
    public static function contrastText(array $rgb): array
    {
        $yiq = ($rgb[0] * 299 + $rgb[1] * 587 + $rgb[2] * 114) / 1000;
        return $yiq >= 128 ? [0, 0, 0] : [255, 255, 255];
    }

    /** Limpia el cache (p.ej. preview con plantilla distinta a la persistida). */
    public static function reset(): void
    {
        self::$resolved = null;
    }

    private static function projectRoot(): string
    {
        return __DIR__ . '/../../..';
    }
}
