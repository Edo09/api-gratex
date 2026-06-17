<?php
require_once __DIR__ . '/BrandingResolver.php';
require_once __DIR__ . '/FacturaTemplate.php';
require_once __DIR__ . '/ClasicoTemplate.php';
require_once __DIR__ . '/ModernoTemplate.php';
require_once __DIR__ . '/CompactoTemplate.php';

/**
 * Crea la plantilla de Representacion Impresa.
 *
 * Sin argumentos usa el branding del tenant resuelto (BrandingResolver).
 * Nombres 'custom:<nombre>' cargan src/Utils/Pdf/Custom/<Nombre>Template.php
 * (disenos a la medida; ver docs/modules/branding-plantillas.md). Nombre desconocido
 * o archivo custom ausente caen a ClasicoTemplate — nunca fatal: una factura
 * siempre debe poder imprimirse.
 */
class FacturaTemplateFactory
{
    /**
     * @param ?string $name   Plantilla (clasico|moderno|compacto|custom:<n>).
     *                        Null = la del tenant actual.
     * @param ?array  $accent RGB de acento. Null = el del tenant actual
     *                        (o ninguno si $name viene explicito sin acento).
     */
    public static function create(?string $name = null, ?array $accent = null): FacturaTemplate
    {
        if ($name === null) {
            $branding = BrandingResolver::resolve();
            $name = $branding['template'];
            $accent = $accent ?? $branding['accent'];
        }

        $customFile = BrandingResolver::customTemplateFile($name);
        if ($customFile !== null) {
            require_once $customFile;
            $class = BrandingResolver::customClassName($name);
            if (class_exists($class) && is_subclass_of($class, 'FacturaTemplate')) {
                return new $class($accent);
            }
        }

        switch ($name) {
            case 'moderno':
                return new ModernoTemplate($accent);
            case 'compacto':
                return new CompactoTemplate($accent);
            case 'clasico':
            default:
                return new ClasicoTemplate($accent);
        }
    }
}
