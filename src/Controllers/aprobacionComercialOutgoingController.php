<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-API-KEY, Authorization, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE');
header('content-type: application/json; charset=utf-8');

require_once __DIR__ . '/../Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../Utils/FacturacionElectronica/ACECFEmissionService.php';
require_once __DIR__ . '/../Models/ecfRecibidoModel.php';

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

    $decision = (string) $input['estado'] === '2' ? 'RECHAZADO' : 'ACEPTADO';
    $recibidos = new ecfRecibidoModel();

    // Apuntar la aprobacion al mismo ambiente en que se recibio el e-CF
    // (evita codigo 02 por mismatch). Un ambiente explicito en el body manda.
    if (empty($input['ambiente'])) {
        $rowRecibido = $recibidos->getByENCF($input['rnc_emisor'], $input['e_ncf']);
        if ($rowRecibido && !empty($rowRecibido['ambiente'])) {
            $input['ambiente'] = $rowRecibido['ambiente'];
        }
    }

    try {
        $service = new ACECFEmissionService();
        $result = $service->enviar($input);
    } catch (Throwable $e) {
        // DGII devolvio error (ej. HTTP 400): la RespuestaAprobacionComercial
        // viene embebida en el mensaje. La extraemos para persistir el resultado
        // real en vez de perderlo.
        $dgii = parseDgiiAprobacionResponse($e->getMessage());
        persistAprobacionComercial($recibidos, $input, [
            'aprobacion_comercial' => $decision,
            'aprobacion_comercial_detalle' => $input['detalle_motivo'] ?? null,
            'aprobacion_comercial_codigo_dgii' => $dgii['codigo'],
            'aprobacion_comercial_estado_dgii' => $dgii['estado'],
            'aprobacion_comercial_mensaje_dgii' => $dgii['mensaje'],
            'aprobacion_comercial_procesada' => 0,
        ]);
        AuditLogger::log([
            'module' => 'aprobaciones', 'action' => 'ACECF_SENT',
            'entity_type' => 'aprobacion_comercial', 'entity_id' => $input['e_ncf'] ?? null,
            'new_values' => ['rnc_emisor' => $input['rnc_emisor'] ?? null, 'decision' => $decision, 'dgii' => $dgii],
            'success' => false, 'error_message' => $e->getMessage(),
            'description' => 'Fallo enviando aprobacion comercial (ACECF) a DGII.',
        ]);
        respondACECF(false, 'Fallo enviando ACECF a DGII: ' . $e->getMessage(), 502);
        return;
    }

    $dgiiResp = is_array($result['dgii_response']) ? $result['dgii_response'] : [];
    $codigoDgii = isset($dgiiResp['codigo']) ? (string) $dgiiResp['codigo'] : null;
    $estadoDgii = $dgiiResp['estado'] ?? $result['estado'] ?? null;
    $mensajeDgii = isset($dgiiResp['mensaje'])
        ? (is_array($dgiiResp['mensaje']) ? implode(' | ', $dgiiResp['mensaje']) : (string) $dgiiResp['mensaje'])
        : null;
    // DGII devuelve el codigo zero-padded ("01" = procesada). Comparar como entero
    // para no fallar el flag por el "0" inicial ("01" !== "1" como string).
    $procesada = ($codigoDgii !== null && (int) $codigoDgii === 1) ? 1 : 0;

    persistAprobacionComercial($recibidos, $input, [
        'aprobacion_comercial' => $decision,
        'aprobacion_comercial_detalle' => $input['detalle_motivo'] ?? null,
        'aprobacion_comercial_codigo_dgii' => $codigoDgii,
        'aprobacion_comercial_estado_dgii' => $estadoDgii,
        'aprobacion_comercial_mensaje_dgii' => $mensajeDgii,
        'aprobacion_comercial_procesada' => $procesada,
    ]);

    AuditLogger::log([
        'module' => 'aprobaciones', 'action' => 'ACECF_SENT',
        'entity_type' => 'aprobacion_comercial', 'entity_id' => $input['e_ncf'] ?? null,
        'new_values' => [
            'rnc_emisor' => $input['rnc_emisor'] ?? null, 'decision' => $decision,
            'codigo_dgii' => $codigoDgii, 'estado_dgii' => $estadoDgii, 'track_id' => $result['track_id'] ?? null,
        ],
        'success' => $procesada === 1,
        'description' => 'Aprobacion comercial (ACECF) ' . $decision . ' enviada a DGII.',
    ]);

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

/**
 * Persiste la decision comercial sin romper la respuesta si la DB falla
 * (ej. migration 009 aun no aplicada). Solo registra el error en el log.
 */
function persistAprobacionComercial(ecfRecibidoModel $recibidos, array $input, array $data): void
{
    try {
        $recibidos->updateAprobacionComercial($input['rnc_emisor'], $input['e_ncf'], $data);
    } catch (Throwable $e) {
        error_log('[aprobacionComercialOutgoing] no se pudo persistir aprobacion comercial ('
            . ($input['e_ncf'] ?? '?') . '): ' . $e->getMessage());
    }
}

/**
 * Extrae la RespuestaAprobacionComercial { codigo, estado, mensaje[] } que la
 * DGII devuelve embebida en el mensaje de error de DgiiAuthService
 * (formato "HTTP 400 - {json}"). Devuelve campos null si no se puede parsear.
 */
function parseDgiiAprobacionResponse(string $message): array
{
    $out = ['codigo' => null, 'estado' => null, 'mensaje' => null];
    if (preg_match('/\{.*\}/s', $message, $m)) {
        $json = json_decode($m[0], true);
        if (is_array($json)) {
            $out['codigo'] = isset($json['codigo']) ? (string) $json['codigo'] : null;
            $out['estado'] = $json['estado'] ?? null;
            if (isset($json['mensaje'])) {
                $out['mensaje'] = is_array($json['mensaje'])
                    ? implode(' | ', $json['mensaje'])
                    : (string) $json['mensaje'];
            }
        }
    }
    return $out;
}

function respondACECF(bool $status, string $message, int $code = 200): void
{
    http_response_code($code);
    echo json_encode([
        'status' => $status,
        $status ? 'message' : 'error' => $message,
    ]);
}
