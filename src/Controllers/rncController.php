<?php
// Consulta de RNC / cedula para autocompletar el alta de clientes y proveedores.
// Ruta: /api/rnc/consulta  (requiere token; modulo RBAC 'unidades', como los catalogos)
//   GET /api/rnc/consulta?rnc=131256432
//     200 {status:true,  data:{rnc, tipo, razon_social, nombre_comercial, estado,
//                              facturador_electronico, actividad_economica, regimen_pagos}}
//     404 {status:false, error} -> no inscrito como contribuyente
//     422 {status:false, error} -> no tiene 9 u 11 digitos
//     502 {status:false, error} -> el servicio externo fallo o tardo: llenar a mano
// No toca la DB. La consulta vive en src/Utils/RncConsultaService.php.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-API-KEY, X-API-SECRET, Authorization, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Allow: GET, OPTIONS');
header('content-type: application/json; charset=utf-8');

require_once __DIR__ . '/../Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../Utils/RncConsultaService.php';

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

$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (!preg_match('#/rnc/consulta/?$#', $path)) {
    http_response_code(404);
    echo json_encode(['status' => false, 'error' => 'Endpoint no encontrado. Use GET /api/rnc/consulta?rnc=']);
    exit;
}

$res = (new RncConsultaService())->consultar((string) ($_GET['rnc'] ?? ''));
http_response_code($res['status']);
echo json_encode($res['status'] === 200
    ? ['status' => true, 'data' => $res['data']]
    : ['status' => false, 'error' => $res['error']]);
