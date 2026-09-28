<?php
/**
 * Imagenes del contenido de la landing (carrusel y servicios) en public/uploads/.
 *
 * public/uploads/ lo sirve Apache directo (el .htaccess deja pasar cualquier
 * archivo existente bajo /api/public/), asi que aqui solo puede entrar una
 * imagen: un .php se ejecutaria y un .html/.svg se serviria tal cual. Por eso:
 *   - el tipo sale del CONTENIDO (finfo + getimagesize), nunca de la extension
 *     ni del Content-Type que declara el cliente;
 *   - el nombre en disco lo genera el servidor (aleatorio) con la extension del
 *     tipo detectado; el nombre original del usuario se descarta;
 *   - tamano maximo MAX_BYTES.
 *
 * Las filas viejas (public/uploads/<uniqid>_<nombre original>) siguen igual:
 * image_path conserva el mismo formato y removeFile() las borra.
 */
class LandingImageStorage
{
    public const MAX_BYTES = 5 * 1024 * 1024; // 5 MB

    /** MIME detectado => extension en disco. Nada de SVG: puede llevar scripts. */
    private const TIPOS = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    /** Carpeta relativa a la raiz del proyecto, tal como se guarda en image_path. */
    private const DIR_RELATIVO = 'public/uploads';

    private const ERROR_NO_RECIBIDA = 'No pudimos recibir la imagen. Inténtalo de nuevo.';
    private const ERROR_TIPO       = 'Ese archivo no es una imagen que podamos usar. Sube una foto JPG, PNG o WebP.';
    private const ERROR_GUARDAR     = 'No pudimos guardar la imagen. Inténtalo de nuevo más tarde.';

    /**
     * Valida y guarda el archivo subido ($_FILES['...']).
     *
     * @param array $file Entrada de $_FILES (name, tmp_name, error, size).
     * @return array{ok:bool, image_path?:string, error?:string, code?:int}
     *               code: HTTP sugerido para el error (400/422 validacion, 500 disco).
     */
    public static function store(array $file): array
    {
        $check = self::validate($file);
        if (!$check['ok']) {
            return $check;
        }

        $dir = self::uploadsDir();
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            error_log('[LandingImageStorage] no se pudo crear ' . $dir);
            return ['ok' => false, 'error' => self::ERROR_GUARDAR, 'code' => 500];
        }

        $fileName = bin2hex(random_bytes(16)) . '.' . $check['ext'];
        if (!move_uploaded_file((string) $file['tmp_name'], $dir . '/' . $fileName)) {
            error_log('[LandingImageStorage] move_uploaded_file fallo hacia ' . $dir . '/' . $fileName);
            return ['ok' => false, 'error' => self::ERROR_GUARDAR, 'code' => 500];
        }

        return ['ok' => true, 'image_path' => self::DIR_RELATIVO . '/' . $fileName];
    }

    /**
     * Revisa el archivo sin moverlo. Separado de store() para poder probarlo
     * con archivos locales (move_uploaded_file solo acepta subidas reales).
     *
     * @return array{ok:bool, mime?:string, ext?:string, error?:string, code?:int}
     */
    public static function validate(array $file): array
    {
        // image[] (varios archivos) llega con error/tmp_name como arrays: no se acepta.
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) {
            // Si el request entero pasa post_max_size, PHP descarta $_FILES y
            // $_POST: parece "sin archivo", pero en realidad era muy pesado.
            $postMax = self::iniBytes((string) ini_get('post_max_size'));
            if ($file === [] && $postMax > 0 && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $postMax) {
                return self::errorPesada();
            }
            return ['ok' => false, 'error' => 'Elige una imagen para subir.', 'code' => 400];
        }
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            return self::errorPesada();
        }
        $tmp = $file['tmp_name'] ?? null;
        if ($error !== UPLOAD_ERR_OK || !is_string($tmp) || $tmp === '' || !is_file($tmp)) {
            return ['ok' => false, 'error' => self::ERROR_NO_RECIBIDA, 'code' => 422];
        }

        $size = (int) filesize($tmp);
        if ($size <= 0) {
            return ['ok' => false, 'error' => 'La imagen está vacía. Elige otra.', 'code' => 422];
        }
        if ($size > self::limiteBytes()) {
            return self::errorPesada();
        }

        // Sin fileinfo no hay como revisar el contenido: se rechaza (fail-closed)
        // en vez de caer a la extension.
        if (!class_exists('finfo')) {
            error_log('[LandingImageStorage] extension fileinfo no disponible; subida rechazada');
            return ['ok' => false, 'error' => self::ERROR_GUARDAR, 'code' => 500];
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!is_string($mime) || !isset(self::TIPOS[$mime])) {
            return ['ok' => false, 'error' => self::ERROR_TIPO, 'code' => 422];
        }

        // Segunda opinion: que se lea como imagen de verdad (con dimensiones) y
        // del mismo tipo que dijo finfo, no solo que empiece con la firma correcta.
        $info = @getimagesize($tmp);
        if ($info === false || ($info['mime'] ?? '') !== $mime || $info[0] < 1 || $info[1] < 1) {
            return ['ok' => false, 'error' => self::ERROR_TIPO, 'code' => 422];
        }

        return ['ok' => true, 'mime' => $mime, 'ext' => self::TIPOS[$mime]];
    }

    /**
     * Borra el archivo de una fila (nueva o vieja). Solo actua dentro de
     * public/uploads/: image_path viene de la DB y no debe poder apuntar a otro
     * archivo del proyecto.
     */
    public static function removeFile(?string $imagePath): void
    {
        if ($imagePath === null || $imagePath === '') {
            return;
        }
        $dir = realpath(self::uploadsDir());
        $abs = realpath(dirname(__DIR__, 2) . '/' . ltrim($imagePath, '/\\'));
        if ($dir === false || $abs === false || !is_file($abs)) {
            return;
        }
        if (strpos($abs, $dir . DIRECTORY_SEPARATOR) !== 0) {
            error_log('[LandingImageStorage] ruta fuera de uploads, no se borra: ' . $imagePath);
            return;
        }
        @unlink($abs);
    }

    /**
     * El mensaje dice el tope que de verdad aplica: si el servidor deja subir
     * menos que MAX_BYTES (upload_max_filesize=2M es comun en cPanel), decir
     * "5 MB" a quien sube una foto de 3 MB lo confunde.
     */
    private static function errorPesada(): array
    {
        $mb = self::limiteBytes() / (1024 * 1024);
        $texto = floor($mb) == $mb ? (string) (int) $mb : number_format($mb, 1, ',', '.');
        return ['ok' => false, 'error' => "La imagen pesa más de {$texto} MB. Elige una más liviana.", 'code' => 422];
    }

    /** El menor entre MAX_BYTES y lo que PHP deja subir en este servidor. */
    private static function limiteBytes(): int
    {
        $limite = self::MAX_BYTES;
        foreach (['upload_max_filesize', 'post_max_size'] as $clave) {
            $bytes = self::iniBytes((string) ini_get($clave));
            if ($bytes > 0 && $bytes < $limite) {
                $limite = $bytes;
            }
        }
        return $limite;
    }

    /** '2M' / '512K' / '1G' / '2097152' -> bytes. 0 = sin limite o vacio. */
    private static function iniBytes(string $valor): int
    {
        $valor = trim($valor);
        $bytes = (int) $valor;
        switch (strtoupper(substr($valor, -1))) {
            case 'G':
                $bytes *= 1024;
                // no break
            case 'M':
                $bytes *= 1024;
                // no break
            case 'K':
                $bytes *= 1024;
        }
        return $bytes;
    }

    private static function uploadsDir(): string
    {
        return dirname(__DIR__, 2) . '/' . self::DIR_RELATIVO;
    }
}
