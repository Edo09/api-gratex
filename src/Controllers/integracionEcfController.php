<?php
/**
 * integracionEcfController — Emision de e-CF por integracion (JSON -> XML).
 *
 *   POST /api/integracion/ecf
 *   Headers: X-API-KEY + X-API-SECRET (tenant tipo integracion)
 *   Body: payload de emision (mismo shape que ECFEmissionService::emitir),
 *         incluyendo `emisor` y `e_ncf` (el cliente asigna su secuencia).
 *
 * Firma con el cert del tenant, envia a DGII y guarda respaldo en master.
 * No persiste facturas/clientes (el cliente eso lo maneja en su sistema).
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-API-KEY, X-API-SECRET, Authorization, Origin, X-Requested-With, Content-Type, Accept');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../TenantResolver.php';
require_once __DIR__ . '/../Models/IntegracionStoreModel.php';
require_once __DIR__ . '/../Utils/FacturacionElectronica/ECFEmissionService.php';

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
    echo json_encode(['status' => false, 'error' => 'Solo POST permitido en /api/integracion/ecf']);
    return;
}

handleEmitirIntegracion();

function handleEmitirIntegracion(): void
{
    $tenant = TenantResolver::current();
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        respondIntegracionEcf(false, 'JSON body invalido.', 400);
        return;
    }

    $emisor = is_array($input['emisor'] ?? null) ? $input['emisor'] : [];
    if (empty($emisor['rnc'])) {
        respondIntegracionEcf(false, 'Falta emisor.rnc en el JSON.', 422);
        return;
    }
    // El emisor del JSON debe ser el tenant autenticado (no emitir por otro RNC).
    if ((string) $emisor['rnc'] !== (string) $tenant['rnc']) {
        respondIntegracionEcf(false, 'emisor.rnc no coincide con el RNC del tenant autenticado.', 422);
        return;
    }
    if (empty($input['e_ncf'])) {
        respondIntegracionEcf(false, 'Falta e_ncf en el JSON (el cliente asigna la secuencia).', 422);
        return;
    }

    // Forzar modo integracion + ambiente del tenant (el cliente no elige ambiente).
    $payload = $input;
    $payload['integration'] = true;
    $payload['emisor'] = $emisor;
    $payload['ambiente'] = $tenant['ambiente'] ?: 'ecf';

    try {
        $result = (new ECFEmissionService())->emitir($payload);
    } catch (Throwable $e) {
        error_log('[integracionEcf] emision fallo (tenant ' . ($tenant['id'] ?? '?') . '): ' . $e->getMessage());
        AuditLogger::log([
            'module' => 'integracion', 'action' => 'INTEGRACION_EMIT',
            'entity_type' => 'ecf', 'entity_id' => $input['e_ncf'] ?? null,
            'tenant_id' => (int) $tenant['id'],
            'new_values' => ['tipo_ecf' => $input['tipo_ecf'] ?? null, 'rnc_emisor' => $emisor['rnc']],
            'success' => false, 'error_message' => $e->getMessage(),
            'description' => 'Fallo emitiendo e-CF por integracion.',
        ]);
        respondIntegracionEcf(false, 'Fallo emitiendo e-CF: ' . $e->getMessage(), 502);
        return;
    }

    // Respaldo en master (best-effort: no romper la respuesta si falla).
    try {
        (new IntegracionStoreModel())->saveEmitido([
            'tenant_id'     => (int) $tenant['id'],
            'rnc_emisor'    => (string) $emisor['rnc'],
            'tipo_ecf'      => $result['tipo_ecf'] ?? ($input['tipo_ecf'] ?? null),
            'e_ncf'         => $result['e_ncf'] ?? $input['e_ncf'],
            'rnc_comprador' => $input['comprador']['rnc'] ?? null,
            'monto_total'   => $input['totales']['monto_total'] ?? null,
            'track_id'      => $result['track_id'] ?? null,
            'xml_firmado'   => $result['signed_xml'],
        ]);
    } catch (Throwable $e) {
        error_log('[integracionEcf] no se pudo guardar respaldo: ' . $e->getMessage());
    }

    AuditLogger::log([
        'module' => 'integracion', 'action' => 'INTEGRACION_EMIT',
        'entity_type' => 'ecf', 'entity_id' => $result['e_ncf'] ?? ($input['e_ncf'] ?? null),
        'tenant_id' => (int) $tenant['id'],
        'new_values' => [
            'tipo_ecf' => $result['tipo_ecf'] ?? null, 'estado' => $result['estado'] ?? null,
            'track_id' => $result['track_id'] ?? null, 'ambiente' => $result['ambiente'] ?? null,
            'rnc_comprador' => $input['comprador']['rnc'] ?? null,
        ],
        'description' => 'e-CF emitido por integracion (' . (string) ($result['estado'] ?? '') . ').',
    ]);

    echo json_encode([
        'status' => true,
        'data' => [
            'e_ncf'            => $result['e_ncf'] ?? null,
            'tipo_ecf'         => $result['tipo_ecf'] ?? null,
            'estado'           => $result['estado'] ?? null,
            'track_id'         => $result['track_id'] ?? null,
            'codigo_seguridad' => $result['codigo_seguridad'] ?? null,
            'ambiente'         => $result['ambiente'] ?? null,
            'fecha_emision'    => $result['fecha_emision_dgii'] ?? null,
            'xml_firmado'      => $result['signed_xml'] ?? null,
            'dgii_response'    => $result['dgii_response'] ?? null,
        ],
    ]);
}

function respondIntegracionEcf(bool $status, string $message, int $code = 200): void
{
    http_response_code($code);
    echo json_encode([
        'status' => $status,
        $status ? 'mensaje' : 'error' => $message,
    ]);
}
