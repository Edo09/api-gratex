<?php
// Catálogo DGII "Tipo de Bienes y Servicios Comprados" (solo lectura).
// Ruta: /api/tipos-bienes-servicios
//   GET /api/tipos-bienes-servicios -> [{codigo, descripcion}]
// El catálogo vive en el DB master; lo usa el selector al registrar un gasto o
// una compra, y su código va al campo 3 del Formato 606.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-API-KEY, X-API-SECRET, Authorization, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Allow: GET, OPTIONS');
header('content-type: application/json; charset=utf-8');

require_once __DIR__ . '/../Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../Models/tipoBienesServiciosModel.php';

$auth = new AuthMiddleware();
if ($_SERVER['REQUEST_METHOD'] !== 'OPTIONS') {
    $validation = $auth->validateRequest();
    if (!$validation['valid']) {
        $auth->sendUnauthorized($validation['message']);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => false, 'error' => 'Método no soportado']);
    exit;
}

echo json_encode(['status' => true, 'data' => (new tipoBienesServiciosModel())->all()]);
