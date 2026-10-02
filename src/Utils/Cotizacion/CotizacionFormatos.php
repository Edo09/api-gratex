<?php
require_once __DIR__ . '/CotizacionFormato.php';
require_once __DIR__ . '/../../Models/cotizacionModel.php';
require_once __DIR__ . '/../../TenantResolver.php';

/**
 * Registro de formatos de cotización: qué clase atiende cada nombre.
 *
 * Quién decide el formato (cotizacionController.php):
 *   - una cotización nueva, el de la empresa: tenants.cotizacion_formato
 *     (delTenant);
 *   - una que ya existe, el suyo: cotizaciones.formato (NULL = gratex). Así
 *     cambiar el ajuste del tenant no reinterpreta las cotizaciones guardadas.
 *
 * Un nombre desconocido o NULL cae en Gratex: es lo que tenían todos los
 * tenants antes de que hubiera formatos, y lo que espera un tenant sin la
 * migración master 011.
 *
 * Agregar un formato es UNA línea en FORMATOS: el archivo de la clase se llama
 * igual que ella y vive en esta carpeta, y se carga solo cuando se usa (una
 * petición de Gratex no carga el formato ni el PDF de otro tenant).
 */
final class CotizacionFormatos
{
    public const DEFAULT = 'gratex';

    /** Respuesta 409 de cotizacionController cuando el cuerpo trae otro formato que el elegido. */
    public const MSG_DESACTUALIZADA = 'La pantalla de cotizaciones está desactualizada (cambió el formato de tu empresa). Recarga la página.';

    /** @var array<string,class-string<CotizacionFormato>> nombre => clase */
    private const FORMATOS = [
        'gratex' => GratexFormato::class,
        'ferreteria' => FerreteriaFormato::class,
    ];

    public static function existe(?string $nombre): bool
    {
        return $nombre !== null && isset(self::FORMATOS[$nombre]);
    }

    /** El formato $nombre; null o desconocido => Gratex. No lanza. */
    public static function para(?string $nombre, cotizacionModel $modelo): CotizacionFormato
    {
        if ($nombre !== null && !self::existe($nombre)) {
            // Una fila o un tenant con un formato que este código no conoce
            // (un error de tipeo en el UPDATE, o código viejo): se dibuja como
            // Gratex, y ops tiene que poder verlo.
            error_log('[cotizaciones] formato desconocido "' . $nombre . '": se usa ' . self::DEFAULT);
        }
        $clase = self::FORMATOS[self::existe($nombre) ? $nombre : self::DEFAULT];
        require_once __DIR__ . '/' . $clase . '.php';
        return new $clase($modelo);
    }

    /**
     * El formato de la empresa de la petición: tenants.cotizacion_formato. Sin
     * tenant resuelto (instalación de un solo tenant) o sin la columna (master
     * sin la 011; TenantResolver lee los tenants con SELECT *) es Gratex.
     */
    public static function delTenant(): string
    {
        $nombre = TenantResolver::current()['cotizacion_formato'] ?? null;
        if ($nombre === null) {
            return self::DEFAULT;
        }
        if (is_string($nombre) && self::existe($nombre)) {
            return $nombre;
        }
        error_log('[cotizaciones] tenants.cotizacion_formato = "' . print_r($nombre, true) . '" no es un formato conocido: se usa ' . self::DEFAULT);
        return self::DEFAULT;
    }

    /**
     * El formato que dice el cuerpo de la petición. El formulario de Gratex no
     * manda "formato" (es gratex); los demás lo mandan siempre, para que el
     * controller detecte una pestaña o un bundle viejo (409) en vez de guardar
     * un cuerpo de un formato con las reglas de otro.
     */
    public static function delCuerpo(object $body): string
    {
        return isset($body->formato) && is_string($body->formato) ? $body->formato : self::DEFAULT;
    }
}
