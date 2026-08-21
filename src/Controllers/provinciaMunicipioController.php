<?php
// Catálogo DGII de provincias/municipios/distritos (solo lectura).
// Ruta: /api/provincias-municipios
//   GET /api/provincias-municipios                -> todo el catálogo
//   GET /api/provincias-municipios?tipo=PROVINCIA -> filtra por nivel
//   GET /api/provincias-municipios?provincia=25   -> filtra por provincia (2 díg.)
//   -> [{codigo, provincia_codigo, descripcion, tipo}]  (codigo = valor XML DGII)
// El catálogo vive en el DB master; lo usan los selectores de ubicación al crear e-CF.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-API-KEY, X-API-SECRET, Authorization, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Allow: GET, OPTIONS');
header('content-type: application/json; charset=utf-8');

require_once __DIR__ . '/../Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../Models/provinciaMunicipioModel.php';

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

$filters = [];
if (!empty($_GET['tipo'])) {
    $filters['tipo'] = $_GET['tipo'];
}
if (!empty($_GET['provincia'])) {
    $filters['provincia'] = $_GET['provincia'];
}

echo json_encode(['status' => true, 'data' => (new provinciaMunicipioModel())->all($filters)]);
