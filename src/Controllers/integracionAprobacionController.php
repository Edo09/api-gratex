<?php
/**
 * integracionAprobacionController — Aprobacion/Rechazo comercial SALIENTE por
 * integracion (el tenant, como comprador, aprueba o rechaza un e-CF recibido).
 *
 *   POST /api/integracion/aprobacion-comercial
 *   Headers: X-API-KEY + X-API-SECRET (tenant tipo integracion)
 *   Body JSON: rnc_emisor, e_ncf, fecha_emision, monto_total, estado (1|2),
 *              [detalle_motivo (req si estado=2)]
 *
 * Construye el ACECF, firma con cert del tenant, lo envia a DGII y persiste la
 * decision en master (ecf_recibidos del tenant).
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-API-KEY, X-API-SECRET, Authorization, Origin, X-Requested-With, Content-Type, Accept');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../TenantResolver.php';
require_once __DIR__ . '/../Models/IntegracionStoreModel.php';
require_once __DIR__ . '/../Utils/FacturacionElectronica/ACECFEmissionService.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    return;
}

$auth = new AuthMiddleware();
$validation = $auth->validateRequest();
if (!$validation['valid']) {
    $auth->sendUnauthorized($validation['message']);
}
if (!TenantResolver::isIntegration()) {
    http_response_code(403);
    echo json_encode(['status' => false, 'error' => 'Endpoint solo para tenants tipo integracion.']);
    return;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => false, 'error' => 'Solo POST permitido en /api/integracion/aprobacion-comercial']);
    return;
}

handleIntegracionAprobacion();

function handleIntegracionAprobacion(): void
{
    $tenant = TenantResolver::current();
    $tenantId = (int) $tenant['id'];

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        respondIntegracionApc(false, 'JSON body invalido.', 400);
        return;
    }

    $required = ['rnc_emisor', 'e_ncf', 'fecha_emision', 'monto_total', 'estado'];
    foreach ($required as $field) {
        if (!isset($input[$field]) || $input[$field] === '') {
            respondIntegracionApc(false, "Campo requerido faltante: $field", 422);
            return;
        }
    }
    if (!in_array((string) $input['estado'], ['1', '2'], true)) {
        respondIntegracionApc(false, 'estado debe ser 1 (Aceptado) o 2 (Rechazado).', 422);
        return;
    }
    if ((string) $input['estado'] === '2' && empty($input['detalle_motivo'])) {
        respondIntegracionApc(false, 'detalle_motivo requerido cuando estado=2.', 422);
        return;
    }

    $decision = (string) $input['estado'] === '2' ? 'RECHAZADO' : 'ACEPTADO';
    $store = new IntegracionStoreModel();

    // Apuntar al mismo ambiente en que se recibio el e-CF (evita codigo 02).
    if (empty($input['ambiente'])) {
        $recibido = $store->getRecibidoByENCF($tenantId, (string) $input['rnc_emisor'], (string) $input['e_ncf']);
        if ($recibido && !empty($recibido['ambiente'])) {
            $input['ambiente'] = $recibido['ambiente'];
        } else {
            $input['ambiente'] = $tenant['ambiente'] ?: 'ecf';
        }
    }

    // Integracion: el comprador somos el tenant.
    $input['integration'] = true;
    $input['rnc_comprador'] = (string) $tenant['rnc'];

    try {
        $result = (new ACECFEmissionService())->enviar($input);
    } catch (Throwable $e) {
        $dgii = parseDgiiApcResponse($e->getMessage());
        persistDecision($store, $tenantId, $input, [
            'aprobacion_comercial' => $decision,
            'aprobacion_comercial_detalle' => $input['detalle_motivo'] ?? null,
            'aprobacion_comercial_codigo_dgii' => $dgii['codigo'],
            'aprobacion_comercial_estado_dgii' => $dgii['estado'],
            'aprobacion_comercial_mensaje_dgii' => $dgii['mensaje'],
            'aprobacion_comercial_procesada' => 0,
        ]);
        respondIntegracionApc(false, 'Fallo enviando ACECF a DGII: ' . $e->getMessage(), 502);
        return;
    }

    $dgiiResp = is_array($result['dgii_response']) ? $result['dgii_response'] : [];
    $codigoDgii = isset($dgiiResp['codigo']) ? (string) $dgiiResp['codigo'] : null;
    $estadoDgii = $dgiiResp['estado'] ?? $result['estado'] ?? null;
    $mensajeDgii = isset($dgiiResp['mensaje'])
        ? (is_array($dgiiResp['mensaje']) ? implode(' | ', $dgiiResp['mensaje']) : (string) $dgiiResp['mensaje'])
        : null;
    $procesada = ($codigoDgii !== null && $codigoDgii === '1') ? 1 : 0;

    persistDecision($store, $tenantId, $input, [
        'aprobacion_comercial' => $decision,
        'aprobacion_comercial_detalle' => $input['detalle_motivo'] ?? null,
        'aprobacion_comercial_codigo_dgii' => $codigoDgii,
        'aprobacion_comercial_estado_dgii' => $estadoDgii,
        'aprobacion_comercial_mensaje_dgii' => $mensajeDgii,
        'aprobacion_comercial_procesada' => $procesada,
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

function persistDecision(IntegracionStoreModel $store, int $tenantId, array $input, array $data): void
{
    try {
        $store->updateAprobacionComercial($tenantId, (string) $input['rnc_emisor'], (string) $input['e_ncf'], $data);
    } catch (Throwable $e) {
        error_log('[integracionAprobacion] no se pudo persistir decision ('
            . ($input['e_ncf'] ?? '?') . '): ' . $e->getMessage());
    }
}

function parseDgiiApcResponse(string $message): array
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

function respondIntegracionApc(bool $status, string $message, int $code = 200): void
{
    http_response_code($code);
    echo json_encode([
        'status' => $status,
        $status ? 'mensaje' : 'error' => $message,
    ]);
}
