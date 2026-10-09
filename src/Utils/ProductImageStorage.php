<?php
require_once __DIR__ . '/LandingImageStorage.php';

/**
 * Foto de un producto en public/uploads/productos/<tenant>/<aleatorio>.<ext>
 * (migracion 032: products.imagen_path guarda esa ruta relativa).
 *
 * public/uploads/ lo sirve Apache directo (el .htaccess deja pasar cualquier
 * archivo existente bajo /api/public/), asi el POS y la lista de productos la
 * cargan con un <img> comun, sin token. Por eso las mismas reglas que
 * LandingImageStorage, de donde sale la validacion:
 *   - el tipo sale del CONTENIDO (finfo + getimagesize): solo JPG, PNG o WebP;
 *   - el nombre en disco lo genera el servidor (aleatorio, no adivinable) con la
 *     extension del tipo detectado; el nombre original se descarta;
 *   - tamano maximo el menor entre 5 MB y lo que deje subir el servidor.
 * Ademas, a lo sumo LADO_MAX px por lado: app.* reduce la foto en el navegador
 * a 800 px antes de subirla (y de paso le quita los datos GPS del telefono), asi
 * que algo mas grande no vino del formulario.
 *
 * Cada empresa en su carpeta: el id del tenant (o "local" sin multi-tenant).
 */
class ProductImageStorage
{
    public const LADO_MAX = 4096;
    private const DIR_RELATIVO = 'public/uploads/productos';

    /**
     * @param array $file Entrada de $_FILES (name, tmp_name, error, size).
     * @return array{ok:bool, imagen_path?:string, error?:string, code?:int}
     */
    public static function store(array $file, ?int $tenantId): array
    {
        $check = self::validate($file);
        if (!$check['ok']) {
            return $check;
        }
        $carpeta = $tenantId !== null && $tenantId > 0 ? (string) $tenantId : 'local';
        $dir = self::baseDir() . '/' . $carpeta;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            error_log('[ProductImageStorage] no se pudo crear ' . $dir);
            return ['ok' => false, 'error' => 'No pudimos guardar la foto. Inténtalo de nuevo más tarde.', 'code' => 500];
        }
        $nombre = bin2hex(random_bytes(16)) . '.' . $check['ext'];
        if (!move_uploaded_file((string) $file['tmp_name'], $dir . '/' . $nombre)) {
            error_log('[ProductImageStorage] move_uploaded_file fallo hacia ' . $dir . '/' . $nombre);
            return ['ok' => false, 'error' => 'No pudimos guardar la foto. Inténtalo de nuevo más tarde.', 'code' => 500];
        }
        return ['ok' => true, 'imagen_path' => self::DIR_RELATIVO . '/' . $carpeta . '/' . $nombre];
    }

    /**
     * Revisa el archivo sin moverlo (se puede probar con archivos locales).
     *
     * @return array{ok:bool, mime?:string, ext?:string, error?:string, code?:int}
     */
    public static function validate(array $file): array
    {
        $check = LandingImageStorage::validate($file);
        if (!$check['ok']) {
            return $check;
        }
        $info = @getimagesize((string) $file['tmp_name']);
        if ($info === false || $info[0] > self::LADO_MAX || $info[1] > self::LADO_MAX) {
            return ['ok' => false, 'error' => 'La foto es demasiado grande (más de ' . self::LADO_MAX . ' píxeles de lado). Usa una más pequeña.', 'code' => 422];
        }
        return $check;
    }

    /**
     * Borra la foto de una fila. Solo actua dentro de public/uploads/productos/:
     * imagen_path viene de la DB y no debe poder apuntar a otro archivo.
     */
    public static function removeFile(?string $imagenPath): void
    {
        if ($imagenPath === null || $imagenPath === '') {
            return;
        }
        $dir = realpath(self::baseDir());
        $abs = realpath(dirname(__DIR__, 2) . '/' . ltrim($imagenPath, '/\\'));
        if ($dir === false || $abs === false || !is_file($abs)) {
            return;
        }
        if (strpos($abs, $dir . DIRECTORY_SEPARATOR) !== 0) {
            error_log('[ProductImageStorage] ruta fuera de uploads/productos, no se borra: ' . $imagenPath);
            return;
        }
        @unlink($abs);
    }

    private static function baseDir(): string
    {
        return dirname(__DIR__, 2) . '/' . self::DIR_RELATIVO;
    }
}
