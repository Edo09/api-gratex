<?php
header('Access-Control-Allow-Origin: *');
header("Access-Control-Allow-Headers: X-API-KEY, Authorization, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Allow: GET, POST, OPTIONS, PUT, DELETE");
header('content-type: application/json; charset=utf-8');
require_once(__DIR__ . '/../Models/conduceModel.php');
require_once(__DIR__ . '/../Models/cotizacionModel.php');
require_once(__DIR__ . '/../Middleware/AuthMiddleware.php');
require_once(__DIR__ . '/../Utils/Cotizacion/FerreteriaConduce.php');
require_once(__DIR__ . '/../Utils/InputSanitizer.php');

/*
 * /api/conduces: conduces de mercancía de Ferretería (spec
 * 2026-10-05-conduces-design, 4.1).
 *
 *   GET    /api/conduces?page&pageSize&query    listado de activos
 *   GET    /api/conduces?id=N                   uno ([] si no está o está eliminado)
 *   GET    /api/conduces/{id}/pdf[?format=base64]
 *   POST   /api/conduces                        crear, con cotizacion_id o sin él
 *   POST   /api/conduces/preview                vista previa, sin guardar
 *   PUT    /api/conduces                        editar
 *   DELETE /api/conduces                        eliminar (activo = 0; no se borra nada)
 *
 * Las reglas, el número, el PDF y quién puede usar conduces viven en
 * FerreteriaConduce y conduceModel. Aquí se revisa la petición, se elige la
 * acción y se envuelve la respuesta ({status, data|error}), como en
 * cotizacionController.php. AuditLogger lo incluye el Router para todos.
 *
 * Orden de las revisiones (spec 4.1): el token primero (401; de paso resuelve
 * el tenant); después el formato del tenant (422 para Gratex, un formato
 * desconocido o una instalación de un solo tenant), antes de leer el cuerpo o
 * abrir la DB del tenant; recién entonces se crean los modelos.
 */

const CONDUCE_SIN_ID_EDITAR = 'No se pudo identificar el conduce que quieres modificar. Ábrelo de nuevo desde el listado.';
const CONDUCE_SIN_ID_ELIMINAR = 'No se pudo identificar el conduce que quieres eliminar. Actualiza el listado e inténtalo de nuevo.';
const CONDUCE_RUTA_NO_EXISTE = 'Esta dirección de conduces no existe.';
const CONDUCE_ERROR_GENERICO = 'No se pudo completar la operación con los conduces. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.';

/** Responde un ['error', $mensaje, $http] con su código HTTP (500 si no trae). */
function conduceResponderError(array $resultado): void
{
    http_response_code((int) ($resultado[2] ?? 500));
    echo json_encode(['status' => false, 'error' => $resultado[1]]);
}

/** El conduce activo de ese id (del cuerpo o de ?id=) con sus líneas, o null si no está o el id no es válido. */
function conduceFila(conduceModel $modelo, mixed $id): ?array
{
    $id = FerreteriaConduce::idDe($id);
    return $id === null ? null : $modelo->obtener($id);
}

$auth = new AuthMiddleware();

// El Router responde el preflight (OPTIONS) antes de incluir un controller. Si
// llegara uno hasta aquí, no lleva token ni datos: no hay nada que revisar.
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    return;
}

$validation = $auth->validateRequest();
if (!$validation['valid']) {
    $auth->sendUnauthorized($validation['message']);
}

$noDisponible = FerreteriaConduce::errorDisponibilidad();
if ($noDisponible !== null) {
    conduceResponderError(['error', $noDisponible, 422]);
    return;
}

$ruta = FerreteriaConduce::ruta($_SERVER['REQUEST_METHOD'], (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// El cuerpo se decodifica una sola vez. Vacío o que no es un objeto JSON queda
// como objeto vacío: FerreteriaConduce recibe siempre un objeto.
$body = InputSanitizer::jsonInput(false);
if (!is_object($body)) {
    $body = new stdClass();
}

try {
    $conduceModel = new conduceModel();
    $conduces = new FerreteriaConduce($conduceModel, new cotizacionModel());

    switch ($ruta['accion']) {
        case 'pdf':
            $row = $ruta['id'] !== null ? $conduceModel->obtener($ruta['id']) : null;
            if ($row === null) {
                conduceResponderError(['error', FerreteriaConduce::MSG_NO_EXISTE, 404]);
                break;
            }
            $resultado = $conduces->pdf($row);
            if ($resultado[0] !== 'success') {
                conduceResponderError($resultado);
                break;
            }
            $nombre = 'Conduce_' . $row['code'] . '.pdf';
            if (($_GET['format'] ?? 'download') === 'base64') {
                echo json_encode(['status' => true, 'data' => [
                    'filename' => $nombre,
                    'content' => base64_encode($resultado[1]),
                    'mime_type' => 'application/pdf',
                ]]);
                break;
            }
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $nombre . '"');
            header('Content-Length: ' . strlen($resultado[1]));
            echo $resultado[1];
            break;

        case 'leer':
            if (isset($_GET['id'])) {
                // [] si no está o está eliminado, como el GET ?id= de cotizaciones.
                $row = conduceFila($conduceModel, $_GET['id']);
                echo json_encode(['status' => true, 'data' => $row !== null ? [$row] : []]);
                break;
            }
            $pagina = FerreteriaConduce::paginacion($_GET);
            $filas = $conduceModel->listar($pagina['offset'], $pagina['pageSize'], $pagina['query']);
            $total = $conduceModel->contar($pagina['query']);
            echo json_encode([
                'status' => true,
                'data' => $filas,
                'pagination' => [
                    'page' => $pagina['page'],
                    'pageSize' => $pagina['pageSize'],
                    'total' => $total,
                    'totalPages' => (int) ceil($total / $pagina['pageSize']),
                ],
            ]);
            break;

        case 'crear':
            $resultado = $conduces->crear($body);
            if ($resultado[0] !== 'success') {
                conduceResponderError($resultado);
                break;
            }
            AuditLogger::log([
                'module' => 'conduces', 'action' => 'CREATE',
                'entity_type' => 'conduce', 'entity_id' => $resultado[1]['id'],
                'new_values' => $body, 'description' => 'Conduce ' . $resultado[1]['code'] . ' creado.',
            ]);
            echo json_encode(['status' => true, 'data' => $resultado[1]]);
            break;

        case 'preview':
            // Con id, la vista previa de ese conduce guardado (FerreteriaConduce
            // responde 404 si no está activo); sin id, la de uno nuevo.
            $row = isset($body->id) ? conduceFila($conduceModel, $body->id) : null;
            $resultado = $conduces->preview($body, $row);
            if ($resultado[0] !== 'success') {
                conduceResponderError($resultado);
                break;
            }
            echo json_encode(['status' => true, 'data' => [
                'filename' => 'Conduce_Preview.pdf',
                'content' => base64_encode($resultado[1]),
                'mime_type' => 'application/pdf',
            ]]);
            break;

        case 'actualizar':
            $id = FerreteriaConduce::idDe($body->id ?? null);
            if ($id === null) {
                conduceResponderError(['error', CONDUCE_SIN_ID_EDITAR, 422]);
                break;
            }
            // La fila de antes: FerreteriaConduce responde 404 si no está o está
            // eliminado, y es el old_values de la auditoría.
            $anterior = $conduceModel->obtener($id);
            $resultado = $conduces->actualizar($anterior ?? [], $body);
            if ($resultado[0] !== 'success') {
                conduceResponderError($resultado);
                break;
            }
            AuditLogger::log([
                'module' => 'conduces', 'action' => 'UPDATE',
                'entity_type' => 'conduce', 'entity_id' => $id,
                'old_values' => $anterior, 'new_values' => $body,
                'description' => 'Conduce ' . $resultado[1]['code'] . ' actualizado.',
            ]);
            echo json_encode(['status' => true, 'data' => $resultado[1]]);
            break;

        case 'eliminar':
            $id = FerreteriaConduce::idDe($body->id ?? null);
            if ($id === null) {
                conduceResponderError(['error', CONDUCE_SIN_ID_ELIMINAR, 422]);
                break;
            }
            $anterior = $conduceModel->obtener($id);
            $resultado = $conduces->eliminar($id);
            if ($resultado[0] !== 'success') {
                conduceResponderError($resultado);
                break;
            }
            AuditLogger::log([
                'module' => 'conduces', 'action' => 'DELETE',
                'entity_type' => 'conduce', 'entity_id' => $id,
                'old_values' => $anterior,
                'description' => 'Conduce ' . ($anterior['code'] ?? $id) . ' eliminado (activo = 0).',
            ]);
            echo json_encode(['status' => true, 'data' => $resultado[1]]);
            break;

        default:
            conduceResponderError(['error', CONDUCE_RUTA_NO_EXISTE, 404]);
    }
} catch (Throwable $e) {
    // Las lecturas de conduceModel no atrapan, a propósito: un fallo de la DB
    // no es "no hay conduces" ni "ya no existe". Aquí se responde 500 con un
    // mensaje neutro, y el detalle va al log.
    error_log('[conduces] ' . $_SERVER['REQUEST_METHOD'] . ' ' . $ruta['accion'] . ': ' . $e->getMessage());
    conduceResponderError(['error', CONDUCE_ERROR_GENERICO, 500]);
}
