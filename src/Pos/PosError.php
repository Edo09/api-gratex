<?php

/**
 * Error del POS con lo que necesita la respuesta: el texto para la persona, el
 * HTTP y un `codigo` estable para que pos.* decida que pantalla mostrar
 * (EQUIPO_NO_HABILITADO -> habilitar equipo, SESION_REQUERIDA -> PIN,
 * EQUIPO_BLOQUEADO -> cuenta regresiva...). El texto puede cambiar; el codigo no.
 */
final class PosError extends RuntimeException
{
    private string $codigo;
    private array $extra;

    public function __construct(string $mensaje, int $http, string $codigo, array $extra = [])
    {
        parent::__construct($mensaje, $http);
        $this->codigo = $codigo;
        $this->extra = $extra;
    }

    public function codigo(): string
    {
        return $this->codigo;
    }

    public function http(): int
    {
        return (int) $this->getCode();
    }

    /** Cuerpo JSON de la respuesta: {status:false, error, codigo, ...extra}. */
    public function cuerpo(): array
    {
        return ['status' => false, 'error' => $this->getMessage(), 'codigo' => $this->codigo] + $this->extra;
    }

    /** Responde y corta el request. */
    public function responder(): void
    {
        http_response_code($this->http());
        echo json_encode($this->cuerpo(), JSON_UNESCAPED_UNICODE);
        exit;
    }
}
