<?php
require_once __DIR__ . '/CotizacionFormato.php';
require_once __DIR__ . '/../../Models/cotizacionModel.php';

/**
 * Formato de cotización de Gratex: el de siempre, y el de todo tenant cuyo
 * tenants.cotizacion_formato no diga otra cosa.
 *
 * Es el código de las ramas POST, POST /preview, PUT y GET /{id}/pdf de
 * cotizacionController.php MOVIDO TAL CUAL: mismas validaciones en el mismo
 * orden, mismos mensajes, mismos códigos HTTP (los errores de cabecera y los
 * del modelo responden 200 con status:false; los de línea, 422) y las mismas
 * llamadas al modelo con los mismos argumentos. Solo cambió de dónde sale el
 * cuerpo ($body, que el controller decodifica una vez) y que se devuelve
 * ['success'|'error', ...] en vez de armar $respuesta: el controller la
 * envuelve y escribe la auditoría igual que antes. Un cambio de reglas de
 * Gratex va aquí, no en el controller.
 *
 * cotValidarItems() y las constantes COT_* siguen definidas en
 * cotizacionController.php (globales). Esta clase solo se usa desde ese
 * controller, así que ya existen cuando estos métodos corren.
 *
 * El correo sigue dentro de cotizacionModel::saveCotizacion/updateCotizacion,
 * sin tocar: por eso este es el formato que permite enviar por correo.
 */
final class GratexFormato extends CotizacionFormato
{
    private cotizacionModel $modelo;

    public function __construct(cotizacionModel $modelo)
    {
        $this->modelo = $modelo;
    }

    public function nombre(): string
    {
        return 'gratex';
    }

    public function permiteCorreo(): bool
    {
        return true;
    }

    public function crear(object $body): array
    {
        if (!isset($body->client_id) || is_null($body->client_id)) {
            return ['error', COT_SIN_CLIENTE, 200];
        } else if (!isset($body->items) || !is_array($body->items) || count($body->items) == 0) {
            return ['error', COT_SIN_LINEAS, 200];
        } else if (!isset($body->total) || !is_numeric($body->total)) {
            return ['error', COT_TOTAL_INVALIDO, 200];
        } else {
            $itemError = cotValidarItems($body->items);
            if ($itemError !== null) {
                // 422 como las demas validaciones de lineas (facturas, gastos).
                return ['error', $itemError, 422];
            } else {
                $date = isset($body->date) ? $body->date : '';
                $user_id = isset($body->user_id) ? $body->user_id : null;
                $send_email = isset($body->sent_email) && $body->sent_email === true;
                $result = $this->modelo->saveCotizacion($body->client_id, $date, $body->items, $body->total, $user_id, $send_email);
                if ($result[0] === 'success') {
                    return ['success', $result[1]];
                } else {
                    return ['error', $result[1], 200];
                }
            }
        }
    }

    /**
     * Gratex no lee $row: updateCotizacion vuelve a buscar la fila por id y,
     * si ya no existe, responde su "ya no existe" de siempre.
     */
    public function actualizar(array $row, object $body): array
    {
        if (!isset($body->client_id) || is_null($body->client_id)) {
            return ['error', COT_SIN_CLIENTE, 200];
        } else if (!isset($body->items) || !is_array($body->items) || count($body->items) == 0) {
            return ['error', COT_SIN_LINEAS, 200];
        } else if (!isset($body->total) || !is_numeric($body->total)) {
            return ['error', COT_TOTAL_INVALIDO, 200];
        } else {
            $itemError = cotValidarItems($body->items);
            if ($itemError !== null) {
                return ['error', $itemError, 422];
            } else {
                $date = isset($body->date) ? $body->date : '';
                $user_id = isset($body->user_id) ? $body->user_id : null;
                $send_email = isset($body->sent_email) && $body->sent_email === true;
                $result = $this->modelo->updateCotizacion($body->id, $body->client_id, $date, $body->items, $body->total, $user_id, $send_email);
                if ($result[0] === 'success') {
                    return ['success', $result[1]];
                } else {
                    return ['error', $result[1], 200];
                }
            }
        }
    }

    /**
     * La vista previa de siempre: las mismas 3 comprobaciones de cabecera y
     * NINGUNA de líneas (cotValidarItems no corre aquí, como antes), código
     * 'PREVIEW'. Gratex no lee $row: no imprime el código de la guardada.
     */
    public function preview(object $body, ?array $row): array
    {
        // Validate required fields
        if (!isset($body->client_id) || is_null($body->client_id)) {
            return ['error', COT_SIN_CLIENTE, 200];
        } else if (!isset($body->items) || !is_array($body->items)) {
            return ['error', COT_SIN_LINEAS, 200];
        } else if (!isset($body->total) || !is_numeric($body->total)) {
            return ['error', COT_TOTAL_INVALIDO, 200];
        } else {
            // Convert items to associative arrays
            $items = array_map(function ($item) {
                return (array)$item;
            }, $body->items);
            // Look up client_name from clients table
            require_once(__DIR__ . '/../../Models/clientModel.php');
            $clientModelInstance = new clientModel();
            $clientData = $clientModelInstance->getClients($body->client_id);
            $client_name = (!empty($clientData) && isset($clientData[0]['client_name'])) ? $clientData[0]['client_name'] : '';
            // Prepare a fake cotizacion array (as in getCotizaciones)
            $cotizacion = [[
                'id' => null,
                'code' => 'PREVIEW',
                'date' => isset($body->date) ? $body->date : '',
                'client_id' => $body->client_id,
                'client_name' => $client_name,
                'total' => $body->total,
                'items' => $items,
                'description' => '',
            ]];
            // Generate PDF
            require_once(__DIR__ . '/../CotizacionPdfGenerator.php');
            $pdf = new CotizacionPdfGenerator('P', 'mm', 'Letter');
            $pdf->setCotizacion($cotizacion[0]);
            $pdfContent = $pdf->generatePdf();

            return ['success', $pdfContent];
        }
    }

    public function pdf(array $cotizacion): array
    {
        // Include PDF generator
        require_once(__DIR__ . '/../CotizacionPdfGenerator.php');
        // Generate and output PDF
        $pdf = new CotizacionPdfGenerator('P', 'mm', 'Letter');
        $pdf->setCotizacion($cotizacion);
        $pdfContent = $pdf->generatePdf();
        return ['success', $pdfContent];
    }
}
