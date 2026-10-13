<?php
/**
 * integracionAprobacionController — Aprobacion/Rechazo comercial SALIENTE por
 * integracion (el tenant, como comprador, aprueba o rechaza un e-CF recibido).
 *
 *   POST /api/integracion/aprobacion-comercial
 *   Headers: X-API-KEY + X-API-SECRET (tenant tipo integracion)
 *   Body JSON: rnc_emisor, e_ncf, fecha_emision, monto_total, estado (1|2),
 *              [detalle_motivo (req si estado=2)], [rnc_comprador]
 *
 * Construye el ACECF, firma con cert del tenant, lo envia a DGII y persiste la
 * decision en master (ecf_recibidos del tenant).
 *
 * Multi-empresa: con `grupo_id` una credencial aprueba por varias empresas.
 * `rnc_comprador` elige cual aprueba; de ella salen el certificado que firma el
 * ACECF y el ambiente. Si una credencial de grupo lo omite, la empresa se deduce
 * del e-CF recibido (la unica del grupo que lo tiene; 422 si ninguna o varias).
 * Sin grupo, omitirlo = la dueña de la credencial (como siempre).
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-API-KEY, X-API-SECRET, Authorization, Origin, X-Requested-With, Content-Type, Accept');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../TenantResolver.php';
require_once __DIR__ . '/../Models/IntegracionStoreModel.php';
require_once __DIR__ . '/../Utils/FacturacionElectronica/ACECFEmissionService.php';
require_once __DIR__ . '/../Utils/FacturacionElectronica/EcfUsuarioException.php';

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

    $input = InputSanitizer::jsonInput();
    if (!is_array($input)) {
        respondIntegracionApc(false, 'JSON body invalido.', 400);
        return;
    }

    // Multi-empresa: con `grupo_id`, una credencial aprueba por varias empresas.
    // `rnc_comprador` elige cual de ellas es la que aprueba. Si falta, mas abajo
    // (ya validados rnc_emisor y e_ncf) se deduce del e-CF recibido.
    if (!empty($input['rnc_comprador'])
        && (string) $input['rnc_comprador'] !== (string) $tenant['rnc']) {
        if (!TenantResolver::switchToSibling((string) $input['rnc_comprador'])) {
            respondIntegracionApc(
                false,
                'rnc_comprador (' . $input['rnc_comprador'] . ') no corresponde a la credencial'
                . ' usada ni a una empresa de su grupo.',
                422
            );
            return;
        }
        // Cambio el tenant activo: el cert que firma el ACECF y el ambiente
        // deben ser los de la empresa que aprueba.
        $tenant = TenantResolver::current();
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

    // Credencial de grupo sin rnc_comprador: la compradora es la empresa del
    // grupo que recibio ese e-CF. Antes se asumia la dueña de la credencial y,
    // si lo habia recibido un hermano, el ACECF salia con el RNC y el cert
    // equivocados y la decision no se guardaba en ningun lado. Sin grupo no
    // entra aqui: sigue siendo la dueña de la credencial.
    if (empty($input['rnc_comprador']) && TenantResolver::grupoId() !== null) {
        $empresa = inferirEmpresaCompradora($store, (string) $input['rnc_emisor'], (string) $input['e_ncf']);
        if (is_string($empresa)) {
            respondIntegracionApc(false, $empresa, 422);
            return;
        }
        if ((string) $empresa['rnc'] !== (string) $tenant['rnc']) {
            if (!TenantResolver::switchToSibling((string) $empresa['rnc'])) {
                // Solo si la empresa se desactivo/desagrupo entre las dos consultas.
                respondIntegracionApc(
                    false,
                    'rnc_comprador (' . $empresa['rnc'] . ', deducido del e-CF recibido) no corresponde'
                    . ' a la credencial usada ni a una empresa de su grupo.',
                    422
                );
                return;
            }
            $tenant = TenantResolver::current();
        }
    }
    $tenantId = (int) $tenant['id'];

    // Apuntar al mismo ambiente en que se recibio el e-CF (evita codigo 02). El
    // cliente no elige ambiente (igual que en la emision): si lo manda, se pisa.
    $recibido = $store->getRecibidoByENCF($tenantId, (string) $input['rnc_emisor'], (string) $input['e_ncf']);
    if ($recibido && !empty($recibido['ambiente'])) {
        $input['ambiente'] = $recibido['ambiente'];
    } else {
        $input['ambiente'] = $tenant['ambiente'] ?: 'ecf';
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
        AuditLogger::log([
            'module' => 'integracion', 'action' => 'INTEGRACION_ACECF',
            'entity_type' => 'aprobacion_comercial', 'entity_id' => $input['e_ncf'] ?? null,
            'tenant_id' => $tenantId,
            'new_values' => ['rnc_emisor' => $input['rnc_emisor'] ?? null, 'decision' => $decision, 'dgii' => $dgii],
            'success' => false, 'error_message' => $e->getMessage(),
            'description' => 'Fallo enviando aprobacion comercial por integracion.',
        ]);
        // Sin rutas del server ni nombres de configuracion (el detalle va al log).
        respondIntegracionApc(false, 'Fallo enviando ACECF a DGII: ' . EcfUsuarioException::mensajePublico($e), 502);
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

    persistDecision($store, $tenantId, $input, [
        'aprobacion_comercial' => $decision,
        'aprobacion_comercial_detalle' => $input['detalle_motivo'] ?? null,
        'aprobacion_comercial_codigo_dgii' => $codigoDgii,
        'aprobacion_comercial_estado_dgii' => $estadoDgii,
        'aprobacion_comercial_mensaje_dgii' => $mensajeDgii,
        'aprobacion_comercial_procesada' => $procesada,
    ]);

    AuditLogger::log([
        'module' => 'integracion', 'action' => 'INTEGRACION_ACECF',
        'entity_type' => 'aprobacion_comercial', 'entity_id' => $input['e_ncf'] ?? null,
        'tenant_id' => $tenantId,
        'new_values' => [
            'rnc_emisor' => $input['rnc_emisor'] ?? null, 'decision' => $decision,
            'codigo_dgii' => $codigoDgii, 'estado_dgii' => $estadoDgii, 'track_id' => $result['track_id'] ?? null,
        ],
        'success' => $procesada === 1,
        'description' => 'Aprobacion comercial ' . $decision . ' enviada por integracion.',
    ]);

    echo json_encode([
        'status' => true,
        'data' => [
            'rnc_emisor' => $input['rnc_emisor'],
            'e_ncf' => $input['e_ncf'],
            // Empresa por la que se aprobo (la elegida o la deducida en un grupo).
            'rnc_comprador' => (string) $tenant['rnc'],
            'empresa' => (string) ($tenant['nombre'] ?? ''),
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
 * Empresa del grupo que recibio el e-CF rnc_emisor + e_ncf, o el mensaje del 422
 * si ninguna o mas de una lo tiene. Busca solo entre las empresas integracion
 * activas del grupo de la credencial: las unicas con recibidos en el master.
 *
 * @return array|string fila de TenantResolver::siblings(), o el mensaje de error
 */
function inferirEmpresaCompradora(IntegracionStoreModel $store, string $rncEmisor, string $eNcf): array|string
{
    $empresas = array_values(array_filter(
        TenantResolver::siblings(),
        fn(array $e) => ($e['tipo'] ?? '') === 'integracion'
    ));
    $conRecibido = $store->empresasConRecibido($empresas, $rncEmisor, $eNcf);

    if (count($conRecibido) === 1) {
        return $conRecibido[0];
    }
    if (!$conRecibido) {
        return 'No hay un e-CF recibido ' . $eNcf . ' de ' . $rncEmisor . ' en ninguna empresa del grupo;'
            . ' mande rnc_comprador con el RNC de la empresa que lo recibio.';
    }
    // Varias empresas lo tienen: la credencial ya puede verlas (GET /empresas),
    // asi que listar sus RNC no expone nada nuevo y le dice al cliente que elegir.
    return 'El e-CF ' . $eNcf . ' de ' . $rncEmisor . ' lo recibieron varias empresas del grupo ('
        . implode(', ', array_column($conRecibido, 'rnc')) . ');'
        . ' mande rnc_comprador con el RNC de la empresa que aprueba.';
}

function persistDecision(IntegracionStoreModel $store, int $tenantId, array $input, array $data): void
{
    try {
        $filas = $store->updateAprobacionComercial($tenantId, (string) $input['rnc_emisor'], (string) $input['e_ncf'], $data);
        if ($filas === 0) {
            // El e-CF no esta en los recibidos de esa empresa (o se repitio la
            // misma decision en el mismo segundo: MySQL cuenta filas cambiadas).
            // No cambia la respuesta al cliente; queda la pista para soporte.
            error_log('[integracionAprobacion] AVISO: decision sin persistir, 0 filas en ecf_recibidos'
                . ' (tenant_id=' . $tenantId . ', rnc_emisor=' . ($input['rnc_emisor'] ?? '?')
                . ', e_ncf=' . ($input['e_ncf'] ?? '?') . ')');
        }
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
