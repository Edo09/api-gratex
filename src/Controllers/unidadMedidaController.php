<?php
// Catálogo DGII de unidades de medida (solo lectura). Ruta: /api/unidades-medida
//   GET /api/unidades-medida -> [{id, codigo, descripcion}] (id = código DGII)
// El catálogo vive en el DB master; lo usan los selectores de unidad al crear e-CF.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-API-KEY, X-API-SECRET, Authorization, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Allow: GET, OPTIONS');
header('content-type: application/json; charset=utf-8');

require_once __DIR__ . '/../Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../Models/unidadMedidaModel.php';

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

echo json_encode(['status' => true, 'data' => (new unidadMedidaModel())->all()]);
