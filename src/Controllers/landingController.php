<?php
// Contenido de la landing (marketing, global: en multi-tenant vive en master).
// Ruta: /api/landing/{carousel|services}
//   GET    -> publico: lo lee la pagina de inicio sin sesion
//             (config/permissions.php: landing GET => 'public').
//   POST   -> multipart: image (jpg/png/webp, max 5 MB) + title + subtitle|description
//   DELETE -> {"id": N} en el body, o ?id=N
//
// POST y DELETE exigen sesion de usuario con el modulo 'landing' SIEMPRE, aun
// con PERMISSIONS_ENFORCE=false (mismo criterio que roleController): en modo
// sombra el PermissionGate solo registra, y aqui se escribe en public/uploads/
// (Apache lo sirve directo) y en contenido que ve cualquier visitante.
header('Access-Control-Allow-Origin: *');
header("Access-Control-Allow-Headers: X-API-KEY, Authorization, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Allow: GET, POST, OPTIONS, PUT, DELETE");
header('content-type: application/json; charset=utf-8');

require_once(__DIR__ . '/../Models/LandingModel.php');
require_once(__DIR__ . '/../Models/RoleModel.php');
require_once(__DIR__ . '/../Middleware/AuthMiddleware.php');
require_once(__DIR__ . '/../PermissionGate.php');
require_once(__DIR__ . '/../Utils/LandingImageStorage.php');

$method = $_SERVER['REQUEST_METHOD'];
$request_uri = $_SERVER['REQUEST_URI'];
$endpoint_parts = explode('/', parse_url($request_uri, PHP_URL_PATH));
$resource = end($endpoint_parts); // carousel or services

function ldFail(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

if ($method !== 'GET' && $method !== 'OPTIONS') {
    $auth = new AuthMiddleware();
    $v = $auth->validateRequest();
    if (empty($v['valid'])) {
        $auth->sendUnauthorized('Tu sesión expiró o no has iniciado sesión. Entra de nuevo para continuar.');
    }
    // Una llave de integracion (X-API-SECRET) no es un usuario.
    if (($v['user_id'] ?? null) === null) {
        $auth->sendForbidden('Para hacer esto necesitas iniciar sesión con tu usuario.');
    }
    $perms = (new RoleModel())->getPermissionsForRole($v['tenant_id'] ?? null, (string) ($v['role'] ?? ''));
    if (!PermissionGate::permMatches($perms, 'landing')) {
        $auth->sendForbidden('No tienes permiso para cambiar el contenido de la página de inicio. Pídeselo a un administrador.');
    }
}

if (!in_array($method, ['GET', 'POST', 'DELETE'], true)) {
    ldFail(405, 'Esta acción no está disponible.');
}
if ($resource !== 'carousel' && $resource !== 'services') {
    ldFail(404, 'Esa sección de la página de inicio no existe.');
}

$landingModel = new LandingModel();
$entityType = $resource === 'carousel' ? 'landing_carousel' : 'landing_service';

switch ($method) {
    case 'GET':
        $items = $resource === 'carousel' ? $landingModel->getCarouselItems() : $landingModel->getServices();
        echo json_encode(['success' => true, 'data' => $items]);
        break;

    case 'POST':
        // Tipo por contenido, tamano maximo y nombre generado en el servidor.
        $stored = LandingImageStorage::store($_FILES['image'] ?? []);
        if (!$stored['ok']) {
            ldFail($stored['code'] ?? 422, $stored['error']);
        }
        $imagePath = $stored['image_path'];

        $title = $_POST['title'] ?? '';
        if ($resource === 'carousel') {
            $result = $landingModel->addCarouselItem($title, $_POST['subtitle'] ?? '', $imagePath);
        } else {
            $result = $landingModel->addService($title, $_POST['description'] ?? '', $imagePath);
        }

        if ($result[0] !== 'success') {
            // La fila no se creo: que la imagen no quede huerfana en public/uploads/.
            LandingImageStorage::removeFile($imagePath);
            error_log('[landing] POST ' . $resource . ' fallo: ' . $result[1]);
            ldFail(500, 'No pudimos guardar los cambios. Inténtalo de nuevo más tarde.');
        }

        AuditLogger::log([
            'module' => 'landing', 'action' => 'CREATE',
            'entity_type' => $entityType,
            'entity_id' => $result[2] ?? null,
            'new_values' => ['title' => $title, 'image_path' => $imagePath],
            'description' => 'Elemento de landing creado.',
        ]);
        echo json_encode(['success' => true, 'message' => 'Listo, se agregó a la página de inicio.', 'id' => $result[2]]);
        break;

    case 'DELETE':
        $data = InputSanitizer::jsonInput();
        $id = (is_array($data) ? ($data['id'] ?? null) : null) ?? ($_GET['id'] ?? null);
        $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            ldFail(400, 'Falta indicar qué elemento quieres eliminar.');
        }

        $result = $resource === 'carousel'
            ? $landingModel->deleteCarouselItem($id)
            : $landingModel->deleteService($id);

        if ($result[0] === 'not_found') {
            ldFail(404, 'No encontramos ese elemento. Puede que ya se haya eliminado.');
        }
        if ($result[0] !== 'success') {
            error_log('[landing] DELETE ' . $resource . ' ' . $id . ' fallo: ' . $result[1]);
            ldFail(500, 'No pudimos eliminar el elemento. Inténtalo de nuevo más tarde.');
        }

        // Borra el archivo (filas nuevas y viejas), solo dentro de public/uploads/.
        LandingImageStorage::removeFile($result[2] ?? null);
        AuditLogger::log([
            'module' => 'landing', 'action' => 'DELETE',
            'entity_type' => $entityType,
            'entity_id' => $id,
            'description' => 'Elemento de landing eliminado.',
        ]);
        echo json_encode(['success' => true, 'message' => 'Listo, se eliminó de la página de inicio.']);
        break;
}
