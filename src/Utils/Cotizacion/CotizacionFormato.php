<?php
/**
 * Contrato de un formato de cotización (Gratex, Ferretería...).
 *
 * El módulo de cotizaciones nació con las reglas y el PDF de Gratex metidos en
 * el controller, y un tenant con otra hoja no tenía dónde poner las suyas. Cada
 * formato es ahora una clase de esta carpeta que valida, calcula, numera, guarda
 * y dibuja a su manera; cotizacionController.php solo decide cuál toca
 * (CotizacionFormatos), lo llama y envuelve la respuesta como siempre.
 *
 * Todos los métodos devuelven:
 *   ['success', $payload]                 -> el controller responde status:true
 *   ['error', string $mensaje, int $http] -> status:false con ese código HTTP.
 *     El mensaje lo lee el usuario (español claro); el detalle técnico va al
 *     error_log. $http puede ser 200: los errores de cabecera de Gratex
 *     responden 200 con status:false desde siempre y así se quedan.
 *
 * $body es el stdClass que el controller decodifica una sola vez con
 * InputSanitizer::jsonInput(false) (un cuerpo vacío o que no es objeto llega
 * como new stdClass). Las líneas siguen siendo objetos.
 *
 * Para agregar un formato: docs/modules/cotizaciones-formatos.md.
 */
abstract class CotizacionFormato
{
    /** Clave del formato en tenants.cotizacion_formato y cotizaciones.formato. */
    abstract public function nombre(): string;

    /**
     * POST /api/cotizaciones: validar, calcular, numerar y guardar.
     * @return array ['success', mixed $data] | ['error', string $msg, int $http]
     */
    abstract public function crear(object $body): array;

    /**
     * PUT /api/cotizaciones sobre una cotización existente. El número y el
     * código nunca cambian.
     * @param array $row La fila actual (getCotizaciones($id)[0]). [] solo cuando
     *                   ya no existe: ese caso resuelve al formato del cuerpo
     *                   (CotizacionFormatos::deLaFila), que tiene que responder
     *                   su "ya no existe" sin guardar nada.
     * @return array ['success', mixed $data] | ['error', string $msg, int $http]
     */
    abstract public function actualizar(array $row, object $body): array;

    /**
     * POST /api/cotizaciones/preview: validar y calcular sin guardar.
     * @param array|null $row La fila cuando el cuerpo trae un id que existe
     *                        (la vista previa imprime su código); si no, null.
     * @return array ['success', string $pdfBytes] | ['error', string $msg, int $http]
     */
    abstract public function preview(object $body, ?array $row): array;

    /**
     * GET /api/cotizaciones/{id}/pdf.
     * @param array $cotizacion Fila de getCotizaciones() con sus items.
     * @return array ['success', string $pdfBytes] | ['error', string $msg, int $http]
     */
    abstract public function pdf(array $cotizacion): array;

    /** Si el formulario de este formato ofrece "Enviar por correo". */
    public function permiteCorreo(): bool
    {
        return false;
    }
}
