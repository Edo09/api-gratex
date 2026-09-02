<?php
/**
 * integracionConsultaController — Polling de documentos entrantes (integracion).
 *
 *   GET /api/integracion/recibidos     -> e-CF que le facturaron al tenant
 *   GET /api/integracion/aprobaciones  -> aprobaciones comerciales recibidas
 *   GET /api/integracion/empresas      -> empresas que cubre esta credencial
 *   GET /api/integracion/estado        -> estado en DGII de un e-CF que emitio
 *   Headers: X-API-KEY + X-API-SECRET (tenant tipo integracion)
 *   Query: ?page=1&pageSize=20&rnc=<empresa>
 *
 * Lee del master DB filtrando por tenant_id (aislamiento por tenant). `estado`
 * es la excepcion: consulta a DGII en vivo (el respaldo del master no guarda
 * estado, la fuente de verdad es el sistema del cliente).
 *
 * Multi-empresa: con `grupo_id`, `?rnc=` elige de cual empresa es la bandeja
 * (default: la dueña de la credencial). `/empresas` lista las disponibles.
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-API-KEY, X-API-SECRET, Authorization, Origin, X-Requested-With, Content-Type, Accept');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../TenantResolver.php';
require_once __DIR__ . '/../Models/IntegracionStoreModel.php';

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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => false, 'error' => 'Solo GET permitido en este endpoint.']);
    return;
}

handleIntegracionConsulta();

function handleIntegracionConsulta(): void
{
    $tenant = TenantResolver::current();

    // Multi-empresa: con `grupo_id`, `?rnc=` elige la bandeja de cual empresa
    // se consulta. Sin el parametro se devuelve la de la dueña de la credencial,
    // igual que antes.
    $rncPedido = isset($_GET['rnc']) ? preg_replace('/\D/', '', (string) $_GET['rnc']) : '';
    if ($rncPedido !== '' && $rncPedido !== (string) $tenant['rnc']) {
        if (!TenantResolver::switchToSibling($rncPedido)) {
            http_response_code(422);
            echo json_encode([
                'status' => false,
                'error' => 'rnc (' . $rncPedido . ') no corresponde a la credencial usada'
                    . ' ni a una empresa de su grupo.',
            ]);
            return;
        }
        $tenant = TenantResolver::current();
    }

    $tenantId = (int) $tenant['id'];
    // Ambiente actual del tenant (certecf durante certificacion, ecf en produccion):
    // filtra la bandeja para no mezclar datos de certificacion con produccion.
    $ambiente = $tenant['ambiente'] ?? null;

    $endpoint = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '';

    // Descubrimiento: que empresas puede representar esta credencial. Con una
    // sola empresa devuelve esa; con grupo, las del grupo. Asi el cliente no
    // tiene que llevar la lista de RNC a mano en su codigo.
    if (str_contains($endpoint, 'empresas')) {
        echo json_encode([
            'status' => true,
            'recurso' => 'empresas',
            'data' => TenantResolver::siblings(),
        ]);
        return;
    }

    // Estado en DGII de un e-CF emitido. Un tenant de integracion no tiene
    // facturas persistidas, asi que no hay GET /facturas/{id}/estado: se
    // consulta por e-NCF (+ trackId, que se resuelve del respaldo si no lo
    // mandan) contra ConsultaResultado, o por codigo de seguridad para los
    // RFCE (E32 <250k), que no generan trackId.
    if (str_contains($endpoint, 'estado')) {
        handleIntegracionEstado($tenant);
        return;
    }

    $recurso = str_contains($endpoint, 'aprobaciones') ? 'aprobaciones' : 'recibidos';

    $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int) $_GET['page'] : 1;
    $pageSize = isset($_GET['pageSize']) && is_numeric($_GET['pageSize']) && $_GET['pageSize'] > 0
        ? min((int) $_GET['pageSize'], 100)
        : 20;
    $offset = ($page - 1) * $pageSize;

    $store = new IntegracionStoreModel();
    if ($recurso === 'aprobaciones') {
        $rows = $store->listAprobaciones($tenantId, $offset, $pageSize, $ambiente);
        $total = $store->countAprobaciones($tenantId, $ambiente);
    } else {
        $rows = $store->listRecibidos($tenantId, $offset, $pageSize, $ambiente);
        $total = $store->countRecibidos($tenantId, $ambiente);
    }

    echo json_encode([
        'status' => true,
        'recurso' => $recurso,
        // De que empresa es esta bandeja (util cuando una credencial cubre varias).
        'rnc' => $tenant['rnc'],
        'empresa' => $tenant['nombre'],
        'data' => $rows,
        'pagination' => [
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $total,
            'totalPages' => $pageSize > 0 ? (int) ceil($total / $pageSize) : 0,
        ],
    ]);
}

/**
 * GET /api/integracion/estado?e_ncf=E310000000001[&track_id=...][&codigo_seguridad=...][&rnc=...]
 *
 * Pasarela a la consulta de DGII: no persiste nada (el respaldo del master no
 * guarda estado y la fuente de verdad es el sistema del cliente). El ambiente
 * sale del tenant, igual que en la emision.
 */
function handleIntegracionEstado(array $tenant): void
{
    $eNcf = trim((string) ($_GET['e_ncf'] ?? ''));
    $trackId = trim((string) ($_GET['track_id'] ?? ''));
    $codigoSeguridad = trim((string) ($_GET['codigo_seguridad'] ?? ''));

    if ($eNcf === '') {
        respondIntegracionEstado(422, ['status' => false, 'error' => 'Falta e_ncf.']);
        return;
    }

    $tenantId = (int) $tenant['id'];
    $rncEmisor = (string) $tenant['rnc'];
    $ambiente = $tenant['ambiente'] ?: null;

    // Sin track_id: se busca en el respaldo de lo que emitio este tenant. Asi el
    // cliente puede consultar sabiendo solo su e-NCF.
    $respaldo = null;
    if ($trackId === '' && $codigoSeguridad === '') {
        $respaldo = (new IntegracionStoreModel())->getEmitidoByENCF($tenantId, $rncEmisor, $eNcf);
        $trackId = (string) ($respaldo['track_id'] ?? '');
        if ($trackId === '') {
            respondIntegracionEstado(404, [
                'status' => false,
                'error' => $respaldo === null
                    ? 'No hay respaldo de ese e-NCF para este RNC. Manda track_id (o codigo_seguridad si es un RFCE E32 <250k).'
                    : 'El respaldo de ese e-NCF no tiene track_id (tipico de RFCE E32 <250k): manda codigo_seguridad.',
            ]);
            return;
        }
    }

    require_once __DIR__ . '/../Utils/FacturacionElectronica/ECFEmissionService.php';

    try {
        $service = new ECFEmissionService();
        // Los RFCE no generan trackId: se consultan en RecepcionFC por codigo de
        // seguridad. Si vienen los dos, manda el trackId (consulta canonica).
        $esRfce = $trackId === '' && $codigoSeguridad !== '';
        $consulta = $esRfce
            ? $service->consultarEstadoRFCE($eNcf, $codigoSeguridad, $ambiente, $rncEmisor)
            : $service->consultarEstado($trackId, $eNcf, $ambiente, $rncEmisor);
    } catch (Throwable $e) {
        error_log('[integracionEstado] consulta fallo (tenant ' . $tenantId . ', ' . $eNcf . '): ' . $e->getMessage());
        respondIntegracionEstado(502, [
            'status' => false,
            'error' => 'Fallo consultando el estado a DGII: ' . $e->getMessage(),
        ]);
        return;
    }

    $estado = mapEstadoDgiiIntegracion($consulta);
    if ($esRfce && $estado !== null) {
        $estado = 'RFCE_' . $estado;
    }

    respondIntegracionEstado(200, [
        'status' => true,
        'recurso' => 'estado',
        'rnc' => $rncEmisor,
        'empresa' => $tenant['nombre'],
        'e_ncf' => $eNcf,
        'track_id' => $trackId !== '' ? $trackId : null,
        'flujo' => $esRfce ? 'RFCE' : 'ECF',
        'ambiente' => $ambiente,
        'estado' => $estado,
        'fecha_emision' => $respaldo['created_at'] ?? null,
        'consulta' => $consulta['data'] ?? null,
    ]);
}

/**
 * Normaliza el estado que devuelve DGII. Acepta el texto ('Aceptado', 'En
 * Proceso'...) o el codigo numerico: 0=No encontrado, 1=Aceptado, 2=Rechazado,
 * 3=En Proceso, 4=Aceptado Condicional. Mismo criterio que facturaController.
 */
function mapEstadoDgiiIntegracion(array $consulta): ?string
{
    $data = $consulta['data'] ?? null;
    if (!is_array($data)) {
        return null;
    }
    $estado = $data['estado'] ?? $data['codigo'] ?? null;
    if ($estado === null) {
        return null;
    }
    if (is_string($estado) && !is_numeric($estado)) {
        $upper = strtoupper(trim($estado));
        return in_array($upper, ['ACEPTADO', 'RECHAZADO', 'EN PROCESO', 'ACEPTADO CONDICIONAL', 'NO ENCONTRADO'], true)
            ? str_replace(' ', '_', $upper)
            : null;
    }
    $map = [
        0 => 'NO_ENCONTRADO',
        1 => 'ACEPTADO',
        2 => 'RECHAZADO',
        3 => 'EN_PROCESO',
        4 => 'ACEPTADO_CONDICIONAL',
    ];
    return $map[(int) $estado] ?? null;
}

function respondIntegracionEstado(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body);
}
