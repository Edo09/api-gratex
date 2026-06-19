<?php
require_once(__DIR__ . '/../AuditLogger.php');

/**
 * AuditMiddleware — capa fina opcional para auditoria.
 *
 * RequestContext lee sus metadatos de forma perezosa, asi que NO es obligatorio
 * "bootear" nada para que el logging funcione. Esta clase existe para:
 *   - dar un unico punto temprano (Router) donde el contexto del request queda
 *     disponible, incluso en rutas que fallan auth antes de llegar al controller;
 *   - centralizar el registro de fallos genericos de acceso (deny/unauthorized)
 *     sin ensuciar el Router ni los controllers.
 *
 * Es complemento, no reemplazo: las mutaciones y el ciclo e-CF se auditan con
 * AuditLogger::log() en el sitio donde ocurren (unico lugar que conoce old/new).
 */
class AuditMiddleware
{
    /**
     * Punto de arranque (llamado por el Router tras resolver la ruta). Hoy es un
     * no-op deliberado: deja el contexto listo y sirve de hook para futuras
     * politicas (rate-limit logging, etc.) sin tocar el Router de nuevo.
     */
    public static function boot(): void
    {
        // RequestContext es perezoso; nada que precargar. Hook reservado.
    }

    /**
     * Registra un acceso denegado/no autorizado (uso opcional desde el gate o
     * controllers). module describe el recurso; reason el motivo.
     */
    public static function logAccessDenied(string $module, string $reason): void
    {
        AuditLogger::log([
            'module'        => $module,
            'action'        => 'ACCESS_DENIED',
            'success'       => false,
            'error_message' => $reason,
            'description'   => 'Acceso denegado.',
        ]);
    }
}
