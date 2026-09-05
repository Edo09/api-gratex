<?php
/**
 * ErrorHandler — ningun fallo del API vuelve a salir como un 500 vacio.
 *
 * Sin esto, un Throwable no capturado (o un require que falla, o un parse
 * error) termina en HTTP 500 con Content-Length: 0. Desde fuera eso es
 * indistinguible de un archivo que falta, de un error de sintaxis o de una
 * caida de red, y en un hosting compartido sin shell el unico modo de saberlo
 * es abrir el error_log por cPanel. Pasarlo a JSON convierte horas de biseccion
 * a ciegas en un mensaje.
 *
 * El detalle (archivo, linea, traza) SIEMPRE va al log y NUNCA al cliente: la
 * respuesta lleva un mensaje neutro y un id corto con el que cruzarla contra el
 * error_log. Ver src/Database.php, que ya sigue este mismo criterio para no
 * filtrar usuario y host de la base de datos.
 */
class ErrorHandler
{
    private static $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        set_exception_handler([self::class, 'onException']);
        register_shutdown_function([self::class, 'onShutdown']);
    }

    public static function onException(Throwable $e): void
    {
        self::responder(self::log(
            'excepcion no capturada',
            get_class($e) . ': ' . $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        ));
    }

    /**
     * Los errores fatales (E_ERROR, parse, require fallido) no pasan por
     * set_exception_handler: solo se ven aqui, ya en el apagado.
     */
    public static function onShutdown(): void
    {
        $err = error_get_last();
        $fatales = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if ($err === null || !in_array($err['type'], $fatales, true)) {
            return;
        }
        self::responder(self::log(
            'error fatal',
            $err['message'],
            $err['file'] ?? '?',
            $err['line'] ?? 0,
            ''
        ));
    }

    /** @return string id corto para cruzar la respuesta con el log */
    private static function log(string $que, string $msg, string $file, int $line, string $traza): string
    {
        $id = substr(bin2hex(random_bytes(4)), 0, 8);
        $ruta = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
        $ruta .= ' ' . ($_SERVER['REQUEST_URI'] ?? '-');
        error_log("[error {$id}] {$que} en {$ruta}: {$msg} ({$file}:{$line})");
        if ($traza !== '') {
            error_log("[error {$id}] traza: {$traza}");
        }
        return $id;
    }

    private static function responder(string $id): void
    {
        // headers_sent() es true solo cuando ya se escribio algo al cliente. Si
        // la peticion murio antes de imprimir (el caso normal de un fatal) aun
        // se puede responder; si ya habia salida, añadir JSON encima corromperia
        // una respuesta que quiza estaba completa.
        if (headers_sent()) {
            return;
        }
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => false,
            'error' => 'Error interno del servidor. Referencia: ' . $id,
            'error_id' => $id,
        ]);
    }
}
