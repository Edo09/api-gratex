<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-API-KEY, Authorization, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE');
header('content-type: application/json; charset=utf-8');

require_once __DIR__ . '/../Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../Utils/FacturacionElectronica/ACECFEmissionService.php';

$auth = new AuthMiddleware();
if ($_SERVER['REQUEST_METHOD'] !== 'OPTIONS') {
    $validation = $auth->validateRequest();
    if (!$validation['valid']) {
        $auth->sendUnauthorized($validation['message']);
    }
}

switch ($_SERVER['REQUEST_METHOD']) {
    case 'POST':
        handleEnvioACECF();
        break;
    default:
        http_response_code(405);
        echo json_encode(['status' => false, 'error' => 'Solo POST permitido en /api/aprobaciones-comerciales']);
}

function handleEnvioACECF(): void
{
    $input = json_decode(file_get_contents('php://input', true), true);
    if (!is_array($input)) {
        respondACECF(false, 'JSON body invalido', 400);
        return;
    }

    $required = ['rnc_emisor', 'e_ncf', 'fecha_emision', 'monto_total', 'estado'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || $input[$field] === '') {
            respondACECF(false, "Campo requerido faltante: $field", 422);
            return;
        }
    }
    if (!in_array((string) $input['estado'], ['1', '2'], true)) {
        respondACECF(false, 'estado debe ser 1 (Aceptado) o 2 (Rechazado)', 422);
        return;
    }
    if ((string) $input['estado'] === '2' && empty($input['detalle_motivo'])) {
        respondACECF(false, 'detalle_motivo requerido cuando estado=2', 422);
        return;
    }

    try {
        $service = new ACECFEmissionService();
        $result = $service->enviar($input);
    } catch (Throwable $e) {
        respondACECF(false, 'Fallo enviando ACECF a DGII: ' . $e->getMessage(), 502);
        return;
    }

    echo json_encode([
        'status' => true,
        'data' => [
            'rnc_emisor' => $input['rnc_emisor'],
            'e_ncf' => $input['e_ncf'],
            'estado_aprobacion' => $input['estado'],
            'track_id' => $result['track_id'],
            'estado_dgii' => $result['estado'],
            'codigo_seguridad' => $result['codigo_seguridad'],
            'ambiente' => $result['ambiente'],
            'fecha_envio' => $result['fecha_emision_dgii'],
            'dgii_response' => $result['dgii_response'],
        ],
    ]);
}

function respondACECF(bool $status, string $message, int $code = 200): void
{
    http_response_code($code);
    echo json_encode([
        'status' => $status,
        $status ? 'message' : 'error' => $message,
    ]);
}
