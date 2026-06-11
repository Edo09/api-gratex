<?php
/**
 * Guardado del logo de un tenant en logos/<tenant_id>.<ext>.
 *
 * Logica compartida entre POST /api/branding/logo (el tenant gestiona su
 * propio logo) y public/upload_logo.php (herramienta de operaciones con
 * token, puede fijar el logo de cualquier tenant). Valida extension, MIME
 * real (getimagesize) y tamano maximo antes de mover el archivo.
 */
class LogoStorage
{
    public const MAX_BYTES = 2 * 1024 * 1024; // 2 MB

    /**
     * Valida y guarda el archivo subido ($_FILES['...']) como logo del tenant.
     * Elimina logos previos del tenant en cualquier extension.
     *
     * @param int   $tenantId Id del tenant (ya validado contra master).
     * @param array $file     Entrada de $_FILES (name, tmp_name, error, size).
     * @return array{ok:bool, logo_path?:string, error?:string, code?:int}
     *               code: HTTP sugerido para el error (422 validacion, 500 disco).
     */
    public static function store(int $tenantId, array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'No se recibio el archivo de logo.', 'code' => 422];
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            return ['ok' => false, 'error' => 'El archivo de logo esta vacio.', 'code' => 422];
        }
        if ($size > self::MAX_BYTES) {
            return ['ok' => false, 'error' => 'El logo excede el maximo de 2 MB.', 'code' => 422];
        }

        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, ['png', 'jpg', 'jpeg'], true)) {
            return ['ok' => false, 'error' => "Extension '{$ext}' no permitida (png/jpg).", 'code' => 422];
        }
        $ext = $ext === 'jpeg' ? 'jpg' : $ext;

        // MIME real del contenido, no de la extension declarada.
        $info = @getimagesize((string) ($file['tmp_name'] ?? ''));
        $mime = $info['mime'] ?? '';
        if (!in_array($mime, ['image/png', 'image/jpeg'], true)) {
            return ['ok' => false, 'error' => 'El archivo no es una imagen PNG/JPG valida.', 'code' => 422];
        }

        $logosDir = self::logosDir();
        if (!is_dir($logosDir) && !mkdir($logosDir, 0755, true) && !is_dir($logosDir)) {
            return ['ok' => false, 'error' => 'No se pudo crear la carpeta logos/.', 'code' => 500];
        }

        self::removeFiles($tenantId);

        $dest = $logosDir . '/' . $tenantId . '.' . $ext;
        if (!move_uploaded_file((string) $file['tmp_name'], $dest)) {
            return ['ok' => false, 'error' => 'No se pudo guardar el logo (revisa permisos de logos/).', 'code' => 500];
        }

        return ['ok' => true, 'logo_path' => 'logos/' . $tenantId . '.' . $ext];
    }

    /** Borra los archivos de logo del tenant (cualquier extension). */
    public static function removeFiles(int $tenantId): void
    {
        $logosDir = self::logosDir();
        foreach (['png', 'jpg', 'jpeg'] as $ext) {
            $p = $logosDir . '/' . $tenantId . '.' . $ext;
            if (is_file($p)) {
                @unlink($p);
            }
        }
    }

    private static function logosDir(): string
    {
        return __DIR__ . '/../../logos';
    }
}
