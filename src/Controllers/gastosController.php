<?php
// CRUD de Gastos.
// Ruta: /api/gastos  (requiere token X-API-KEY / Bearer via AuthMiddleware)
//   GET  /api/gastos               -> lista paginada (?page,?pageSize,?query,?categoria)
//   GET  /api/gastos/{id}          -> un gasto con sus lineas
//   GET  /api/gastos?id={id}       -> idem
//   GET  /api/gastos/stats         -> estadisticas
//   GET  /api/gastos/{id}/estado   -> consulta estado en DGII (e-CF emitido)
//   GET  /api/gastos/{id}/xml      -> XML firmado (e-CF emitido)
//   POST /api/gastos               -> crear
//
// Dos categorias (campo `categoria`):
//   - gastos_menores       -> E43 (peajes, suministros, pagos del personal).
//   - facturas_proveedores -> E41/E47 (emitidos por la empresa) y E33/E34
//                             (notas recibidas del proveedor).
//
// Reglas de negocio (DGII):
//   - Compras (11/E41), Gastos Menores (13/E43) y Pagos Exterior (17/E47): la
//     empresa los EMITE a DGII como e-CF (firmar + enviar) reusando
//     ECFEmissionService. Guard DGII_ECF_EMISSION_ENABLED protege produccion.
//   - Notas recibidas (E33/E34): es_auto_emision=false; solo se registran (ya
//     las emitio el proveedor). El usuario digita el NCF.
//   - E31/B01 (Credito Fiscal) YA NO se registran como gasto (2026-06-12):
//     llegan por la recepcion e-CF. Filas historicas se conservan y /stats
//     las sigue etiquetando.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-API-KEY, Authorization, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Allow: GET, POST, OPTIONS');
header('content-type: application/json; charset=utf-8');

require_once __DIR__ . '/../Models/gastoModel.php';
require_once __DIR__ . '/../Middleware/AuthMiddleware.php';

$gastoModel = new gastoModel();
$auth = new AuthMiddleware();

$authUserId = null;
if ($_SERVER['REQUEST_METHOD'] !== 'OPTIONS') {
    $validation = $auth->validateRequest();
    if (!$validation['valid']) {
        $auth->sendUnauthorized($validation['message']);
    }
    $authUserId = $validation['user_id'] ?? null;
}

// Id opcional desde la ruta /gastos/{id}
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$pathId = preg_match('#/gastos/(\d+)#', $path, $m) ? (int) $m[1] : null;
// GET /api/gastos/stats -> estadisticas (stats no es numerico, no choca con /{id})
$isStatsRequest = (bool) preg_match('#/gastos/stats#', $path);
$isEstadoRequest = preg_match('#/gastos/(\d+)/estado#', $path, $estadoM);
$isXmlRequest = preg_match('#/gastos/(\d+)/xml#', $path, $xmlM);

function gastoBody(): array
{
    $data = json_decode(file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

function gastoRespond(bool $ok, $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($ok ? ['status' => true, 'data' => $payload] : ['status' => false, 'error' => $payload]);
}

/**
 * GET /api/gastos/stats
 * Estadisticas de gastos. Cada comprobante usa su propio tipo: E41 (Compras/11),
 * E43 (Gastos Menores/13), E47 (Pagos Exterior/17), E33/E34 (notas). E31/B01
 * quedan fuera (el modelo los excluye de las agregaciones).
 */
function handleGastosStats(gastoModel $gastoModel): void
{
    $stats = $gastoModel->getGastosStats();

    $labels = [
        'E41' => 'Comprobante de Compras (11)',
        'E43' => 'Comprobante para Gastos Menores (13)',
        'E47' => 'Comprobante para Pagos al Exterior (17)',
        'E33' => 'Nota de Débito (03)',
        'E34' => 'Nota de Crédito (04)',
    ];
    $categoriaLabels = [
        'gastos_menores' => 'Gastos Menores',
        'facturas_proveedores' => 'Facturas de Proveedores',
    ];

    foreach ($stats['por_tipo'] as &$tipo) {
        $tipo['nombre'] = $labels[$tipo['tipo_gasto']] ?? 'Desconocido';
        $tipo['total'] = (int) $tipo['total'];
        $tipo['monto_total'] = (float) $tipo['monto_total'];
        $tipo['subtotal_total'] = (float) $tipo['subtotal_total'];
        $tipo['itbis_total'] = (float) $tipo['itbis_total'];
        $tipo['auto_emitidos'] = (int) $tipo['auto_emitidos'];
        $tipo['recibidos'] = (int) $tipo['recibidos'];
    }
    unset($tipo);

    foreach ($stats['por_categoria'] as &$cat) {
        $cat['nombre'] = $categoriaLabels[$cat['categoria']] ?? 'Desconocido';
        $cat['total'] = (int) $cat['total'];
        $cat['monto_total'] = (float) $cat['monto_total'];
        $cat['itbis_total'] = (float) $cat['itbis_total'];
    }
    unset($cat);

    foreach ($stats['por_mes'] as &$mes) {
        $mes['total'] = (int) $mes['total'];
        $mes['monto_total'] = (float) $mes['monto_total'];
    }
    unset($mes);

    foreach ($stats['secuencias'] as &$seq) {
        $seq['secuencia_actual'] = (int) $seq['secuencia_actual'];
        $seq['total_emitidos'] = (int) $seq['total_emitidos'];
        $seq['nombre'] = $labels[$seq['type']] ?? 'Desconocido';
    }
    unset($seq);

    if ($stats['resumen']) {
        $stats['resumen']['total_gastos'] = (int) $stats['resumen']['total_gastos'];
        $stats['resumen']['monto_total'] = (float) $stats['resumen']['monto_total'];
        $stats['resumen']['subtotal_total'] = (float) $stats['resumen']['subtotal_total'];
        $stats['resumen']['itbis_total'] = (float) $stats['resumen']['itbis_total'];
        $stats['resumen']['tipos_distintos'] = (int) $stats['resumen']['tipos_distintos'];
    }

    $stats['ambiente_activo'] = $gastoModel->getActiveAmbiente();
    echo json_encode(['status' => true, 'data' => $stats]);
}

/**
 * GET /api/gastos/{id}/estado
 * Consulta el estado del e-CF emitido en DGII y actualiza el registro.
 */
function handleGastoEstado(int $gastoId, gastoModel $gastoModel): void
{
    $ecf = $gastoModel->getEcfData($gastoId);
    if (!$ecf) {
        gastoRespond(false, 'Gasto no encontrado', 404);
        return;
    }
    if (empty($ecf['track_id']) || empty($ecf['ncf'])) {
        gastoRespond(false, 'Gasto sin track_id o NCF (no fue emitido a DGII)', 422);
        return;
    }
    try {
        require_once __DIR__ . '/../Utils/FacturacionElectronica/ECFEmissionService.php';
        $service = new ECFEmissionService();
        $consulta = $service->consultarEstado($ecf['track_id'], $ecf['ncf'], $ecf['ambiente'] ?? null);
        $estadoNuevo = mapEstadoConsultaGasto($consulta);
        if ($estadoNuevo !== null) {
            $estadoAnterior = $ecf['estado_dgii'] ?? '';
            $gastoModel->updateEcfEstado($gastoId, $estadoNuevo, $consulta['data']);
            $ecf['estado_dgii'] = $estadoNuevo;
            
            // Si el e-CF es rechazado asincronamente y la secuencia no se consumió, revertir el contador
            $secuenciaUtilizada = normalizeSecuenciaUtilizadaGasto($consulta['data']['secuenciaUtilizada'] ?? null);
            if (($estadoNuevo === 'RECHAZADO' || $estadoNuevo === 'NO_ENCONTRADO') && 
                ($estadoAnterior !== 'RECHAZADO' && $estadoAnterior !== 'NO_ENCONTRADO')) {
                if ($secuenciaUtilizada === false) {
                    require_once __DIR__ . '/../Models/ncfModel.php';
                    $ncfModel = new ncfModel();
                    $tipoEcf = substr($ecf['ncf'], 0, 3);
                    $valor = (int) substr($ecf['ncf'], 3);
                    
                    $resultado = $ncfModel->rollbackECFSequence($tipoEcf, $valor, $ecf['ambiente'] ?? null);
                    error_log(sprintf(
                        '[ECF GASTO] consulta estado rollback: gasto_id=%d ncf=%s estado=%s -> %s',
                        $gastoId, $ecf['ncf'], $estadoNuevo, $resultado ? 'revertido' : 'rollback_sin_coincidencia'
                    ));
                }
            }
        }
        gastoRespond(true, [
            'gasto_id' => $gastoId,
            'ncf' => $ecf['ncf'],
            'track_id' => $ecf['track_id'],
            'estado_dgii' => $ecf['estado_dgii'],
            'secuencia_utilizada' => normalizeSecuenciaUtilizadaGasto($consulta['data']['secuenciaUtilizada'] ?? null),
            'consulta' => $consulta['data'],
        ]);
    } catch (Throwable $e) {
        gastoRespond(false, 'Fallo consultando DGII: ' . $e->getMessage(), 502);
    }
}

/**
 * Mapea el codigo de la consulta de estado DGII a nuestro estado interno.
 */
function mapEstadoConsultaGasto(array $consulta): ?string
{
    $data = is_array($consulta['data'] ?? null) ? $consulta['data'] : [];
    $codigo = $data['codigo'] ?? $data['estado'] ?? null;
    if (!is_numeric($codigo)) {
        return null;
    }
    // Codigos DGII: 0=No encontrado, 1=Aceptado, 2=Rechazado, 3=En Proceso, 4=Aceptado Condicional.
    switch ((int) $codigo) {
        case 0: return 'NO_ENCONTRADO';
        case 1: return 'ACEPTADO';
        case 2: return 'RECHAZADO';
        case 3: return 'EN_PROCESO';
        case 4: return 'ACEPTADO_CONDICIONAL';
        default: return null;
    }
}

/**
 * Normaliza el flag `secuenciaUtilizada` de DGII a bool|null.
 * false => el e-NCF puede reutilizarse en un nuevo envio; true => consumido.
 */
function normalizeSecuenciaUtilizadaGasto($value): ?bool
{
    if ($value === null || $value === '') {
        return null;
    }
    return (bool) filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

/**
 * GET /api/gastos/{id}/xml
 * Devuelve el XML firmado del e-CF emitido.
 */
function handleGastoXml(int $gastoId, gastoModel $gastoModel): void
{
    $xml = $gastoModel->getXmlFirmado($gastoId);
    if (!$xml) {
        gastoRespond(false, 'Gasto sin XML firmado (no fue emitido a DGII)', 404);
        return;
    }
    header('Content-Type: application/xml; charset=utf-8');
    $filename = ($xml['e_ncf'] ?? 'gasto') . '.xml';
    header('Content-Disposition: inline; filename="' . $filename . '"');
    echo $xml['xml'];
}

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        if ($isStatsRequest) {
            handleGastosStats($gastoModel);
            break;
        }
        if ($isEstadoRequest) {
            handleGastoEstado((int) $estadoM[1], $gastoModel);
            break;
        }
        if ($isXmlRequest) {
            handleGastoXml((int) $xmlM[1], $gastoModel);
            break;
        }
        $id = $pathId ?? (isset($_GET['id']) && is_numeric($_GET['id']) ? (int) $_GET['id'] : null);
        if ($id !== null) {
            $gasto = $gastoModel->getGasto($id);
            if ($gasto === null) {
                gastoRespond(false, 'Gasto no encontrado', 404);
                break;
            }
            gastoRespond(true, $gasto);
            break;
        }
        $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int) $_GET['page'] : 1;
        $pageSize = isset($_GET['pageSize']) && is_numeric($_GET['pageSize']) && $_GET['pageSize'] > 0 ? (int) $_GET['pageSize'] : 10;
        $query = $_GET['query'] ?? null;
        // Filtro opcional por categoria (gastos_menores | facturas_proveedores).
        $categoria = isset($_GET['categoria']) ? strtolower(trim((string) $_GET['categoria'])) : null;
        $offset = ($page - 1) * $pageSize;
        $gastos = $gastoModel->getGastosPaginated($offset, $pageSize, $query, $categoria);
        $total = $gastoModel->getGastosCount($query, $categoria);
        echo json_encode([
            'status' => true,
            'data' => $gastos,
            'pagination' => [
                'page' => $page,
                'pageSize' => $pageSize,
                'total' => $total,
                'totalPages' => (int) ceil($total / $pageSize),
            ],
        ]);
        break;

    case 'POST':
        $body = gastoBody();
        if (empty($body['categoria'])) {
            gastoRespond(false, 'categoria requerida (gastos_menores | facturas_proveedores)', 422);
            break;
        }
        if (empty($body['tipo_gasto'])) {
            gastoRespond(false, 'tipo_gasto requerido', 422);
            break;
        }
        // rnc_proveedor: requerido salvo Gastos Menores (E43).
        if (empty($body['rnc_proveedor']) && strtoupper(trim((string) $body['tipo_gasto'])) !== 'E43') {
            gastoRespond(false, 'rnc_proveedor requerido (excepto Gastos Menores E43)', 422);
            break;
        }
        if (empty($body['nombre_proveedor']) && strtoupper(trim((string) $body['tipo_gasto'])) !== 'E43') {
            gastoRespond(false, 'nombre_proveedor requerido', 422);
            break;
        }
        if (!isset($body['items']) || !is_array($body['items']) || count($body['items']) === 0) {
            gastoRespond(false, 'items debe ser un arreglo con al menos un elemento', 422);
            break;
        }
        $body['user_id'] = $authUserId ?? ($body['user_id'] ?? null);

        $result = $gastoModel->createGasto($body);
        gastoRespond($result[0] === 'success', $result[1], $result[0] === 'success' ? 201 : 400);
        break;

    default:
        gastoRespond(false, 'Metodo no soportado', 405);
}
