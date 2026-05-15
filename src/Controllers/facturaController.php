<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-API-KEY, Authorization, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE');
header('Allow: GET, POST, OPTIONS, PUT, DELETE');
header('content-type: application/json; charset=utf-8');

require_once __DIR__ . '/../Models/facturaModel.php';
require_once __DIR__ . '/../Models/clientModel.php';
require_once __DIR__ . '/../Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../Utils/FacturacionElectronica/ECFEmissionService.php';

$facturaModel = new facturaModel();
$clientModel = new clientModel();
$auth = new AuthMiddleware();

if ($_SERVER['REQUEST_METHOD'] !== 'OPTIONS') {
    $validation = $auth->validateRequest();
    if (!$validation['valid']) {
        $auth->sendUnauthorized($validation['message']);
    }
}

$endpoint = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$isPdfRequest = preg_match('/\/api\/facturas\/(\d+)\/pdf/', $endpoint, $pdfMatches);
$isEstadoRequest = preg_match('/\/api\/facturas\/(\d+)\/estado/', $endpoint, $estadoMatches);
$isReenviarRequest = preg_match('/\/api\/facturas\/(\d+)\/reenviar/', $endpoint, $reenviarMatches);
$isXmlRequest = preg_match('/\/api\/facturas\/(\d+)\/xml/', $endpoint, $xmlMatches);
$isPreviewRequest = preg_match('/\/api\/facturas\/preview$/', $endpoint);

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        if ($isPdfRequest) {
            handleFacturaPdf((int) $pdfMatches[1], $facturaModel, $clientModel);
            break;
        }
        if ($isEstadoRequest) {
            handleConsultarEstado((int) $estadoMatches[1], $facturaModel);
            break;
        }
        if ($isXmlRequest) {
            handleFacturaXml((int) $xmlMatches[1], $facturaModel);
            break;
        }
        if (isset($_GET['id'])) {
            $facturas = $facturaModel->getFacturas($_GET['id']);
            echo json_encode(['status' => true, 'data' => $facturas]);
            break;
        }
        $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int) $_GET['page'] : 1;
        $pageSize = isset($_GET['pageSize']) && is_numeric($_GET['pageSize']) && $_GET['pageSize'] > 0 ? (int) $_GET['pageSize'] : 10;
        $query = $_GET['query'] ?? null;
        $offset = ($page - 1) * $pageSize;
        $facturas = $facturaModel->getFacturasPaginated($offset, $pageSize, $query);
        $total = $facturaModel->getFacturasCount($query);
        echo json_encode([
            'status' => true,
            'data' => $facturas,
            'pagination' => [
                'page' => $page,
                'pageSize' => $pageSize,
                'total' => $total,
                'totalPages' => (int) ceil($total / $pageSize),
            ],
        ]);
        break;

    case 'POST':
        if ($isReenviarRequest) {
            handleReenviar((int) $reenviarMatches[1], $facturaModel);
            break;
        }
        if ($isPreviewRequest) {
            handlePreview($clientModel);
            break;
        }
        handleEmisionECF($facturaModel, $clientModel);
        break;

    case 'PUT':
        echo json_encode([
            'status' => false,
            'error' => 'PUT no soportado: una factura electronica no puede modificarse despues de emitida. Use Nota de Credito (E34) o Nota de Debito (E33).',
        ]);
        http_response_code(405);
        break;

    case 'DELETE':
        echo json_encode([
            'status' => false,
            'error' => 'DELETE no soportado: una factura electronica no puede eliminarse. Emita una Nota de Credito (E34).',
        ]);
        http_response_code(405);
        break;
}

function handleEmisionECF(facturaModel $facturaModel, clientModel $clientModel): void
{
    $input = json_decode(file_get_contents('php://input', true), true);
    if (!is_array($input)) {
        respond(false, 'JSON body invalido', 400);
        return;
    }

    $tipoEcf = (string) ($input['tipo_ecf'] ?? '');
    $clientId = $input['client_id'] ?? null;
    $items = $input['items'] ?? null;

    if (!preg_match('/^(31|32|33|34|41|43|44|45|46|47)$/', $tipoEcf)) {
        respond(false, 'tipo_ecf requerido (31, 32, 33, 34, 41, 43, 44, 45, 46, 47)', 422);
        return;
    }
    if (!$clientId) {
        respond(false, 'client_id requerido', 422);
        return;
    }
    if (!is_array($items) || count($items) === 0) {
        respond(false, 'items debe ser un arreglo con al menos un elemento', 422);
        return;
    }

    $clients = $clientModel->getClients($clientId);
    if (empty($clients)) {
        respond(false, 'Cliente no encontrado', 404);
        return;
    }
    $client = $clients[0];

    if ($tipoEcf === '31' && empty($client['rnc'])) {
        respond(false, 'El cliente no tiene RNC y es requerido para e-CF tipo 31 (Credito Fiscal)', 422);
        return;
    }

    $strictInput = !empty($input['strict_input']);
    $totales = computeTotales($items);
    $totalesOverride = is_array($input['totales'] ?? null)
        ? array_filter($input['totales'], fn($v) => $v !== null && $v !== '')
        : [];
    if ($strictInput && $totalesOverride) {
        $totales = $totalesOverride;
    } elseif ($totalesOverride) {
        $totales = array_merge($totales, $totalesOverride);
    }

    $compradorBase = [
        'rnc' => $client['rnc'] ?? null,
        'razon_social' => $client['razon_social'] ?? $client['company_name'] ?? $client['client_name'],
        'direccion' => $client['direccion'] ?? null,
        'municipio' => $client['municipio'] ?? null,
        'provincia' => $client['provincia'] ?? null,
        'correo' => $client['email'] ?? null,
        'contacto' => $client['client_name'] ?? null,
    ];
    $compradorOverride = is_array($input['comprador'] ?? null) ? $input['comprador'] : [];
    $comprador = $strictInput
        ? $compradorOverride
        : array_merge($compradorBase, array_filter($compradorOverride, fn($v) => $v !== null && $v !== ''));

    $payload = [
        'tipo_ecf' => $tipoEcf,
        'e_ncf' => $input['e_ncf'] ?? null,
        'fecha_emision' => $input['fecha_emision'] ?? date('d-m-Y'),
        'fecha_vencimiento_secuencia' => $input['fecha_vencimiento_secuencia'] ?? null,
        'tipo_ingresos' => $input['tipo_ingresos'] ?? '01',
        'tipo_pago' => array_key_exists('tipo_pago', $input) ? $input['tipo_pago'] : 1,
        'fecha_limite_pago' => $input['fecha_limite_pago'] ?? null,
        'termino_pago' => $input['termino_pago'] ?? null,
        'tipo_cuenta_pago' => $input['tipo_cuenta_pago'] ?? null,
        'numero_cuenta_pago' => $input['numero_cuenta_pago'] ?? null,
        'banco_pago' => $input['banco_pago'] ?? null,
        'fecha_desde' => $input['fecha_desde'] ?? null,
        'fecha_hasta' => $input['fecha_hasta'] ?? null,
        'total_paginas' => $input['total_paginas'] ?? null,
        'indicador_monto_gravado' => $input['indicador_monto_gravado'] ?? null,
        'indicador_nota_credito' => $input['indicador_nota_credito'] ?? null,
        'ambiente' => $input['ambiente'] ?? null,
        'strict_input' => $strictInput,
        'emisor_override' => is_array($input['emisor'] ?? null) ? $input['emisor'] : null,
        'rfce_emisor_override' => is_array($input['rfce_emisor'] ?? null) ? $input['rfce_emisor'] : null,
        'rfce_comprador_override' => is_array($input['rfce_comprador'] ?? null) ? $input['rfce_comprador'] : null,
        'comprador' => $comprador,
        'items' => mapItemsForXml($items),
        'totales' => $totales,
        'informacion_referencia' => $input['informacion_referencia'] ?? null,
    ];

    try {
        $service = new ECFEmissionService();
        $result = $service->emitir($payload);
    } catch (Throwable $e) {
        respond(false, 'Fallo en emision DGII: ' . $e->getMessage(), 502);
        return;
    }

    $facturaInput = [
        'no_factura' => $result['e_ncf'],
        'date' => $input['date'] ?? date('Y-m-d H:i:s'),
        'client_id' => $clientId,
        'client_name' => $client['client_name'] ?? '',
        'total' => $totales['monto_total'],
        'user_id' => $input['user_id'] ?? null,
        'items' => array_map(function ($item) {
            return [
                'description' => $item['descripcion'] ?? $item['nombre_item'] ?? '',
                'amount' => $item['precio_unitario'] ?? 0,
                'quantity' => $item['cantidad'] ?? 1,
                'subtotal' => $item['monto_item'] ?? 0,
                'indicador_facturacion' => $item['indicador_facturacion'] ?? 1,
                'indicador_bien_servicio' => $item['indicador_bien_servicio'] ?? 2,
                'itbis_amount' => $item['itbis_amount'] ?? 0,
            ];
        }, mapItemsForXml($items)),
    ];

    $saved = $facturaModel->saveFacturaConECF($facturaInput, $result);
    if ($saved[0] !== 'success') {
        respond(false, $saved[1], 500);
        return;
    }

    echo json_encode([
        'status' => true,
        'data' => $saved[1] + [
            'tipo_ecf' => $result['tipo_ecf'],
            'ambiente' => $result['ambiente'],
            'fecha_emision_dgii' => $result['fecha_emision_dgii'],
            'dgii_response' => $result['dgii_response'],
        ],
    ]);
}

function handleConsultarEstado(int $facturaId, facturaModel $facturaModel): void
{
    $ecf = $facturaModel->getECFData($facturaId);
    if (!$ecf) {
        respond(false, 'Factura no encontrada', 404);
        return;
    }
    if (empty($ecf['track_id']) || empty($ecf['e_ncf'])) {
        respond(false, 'Factura no tiene track_id o e_ncf (no fue emitida a DGII)', 422);
        return;
    }
    try {
        $service = new ECFEmissionService();
        $consulta = $service->consultarEstado($ecf['track_id'], $ecf['e_ncf'], $ecf['ambiente_dgii'] ?? null);
        $estadoNuevo = mapEstadoFromConsulta($consulta);
        if ($estadoNuevo !== null) {
            $facturaModel->updateECFEstado($facturaId, $estadoNuevo, $consulta['data']);
            $ecf['estado_dgii'] = $estadoNuevo;
        }
        echo json_encode([
            'status' => true,
            'data' => [
                'factura_id' => $facturaId,
                'e_ncf' => $ecf['e_ncf'],
                'track_id' => $ecf['track_id'],
                'estado_dgii' => $ecf['estado_dgii'],
                'consulta' => $consulta['data'],
            ],
        ]);
    } catch (Throwable $e) {
        respond(false, 'Fallo consultando DGII: ' . $e->getMessage(), 502);
    }
}

function handleReenviar(int $facturaId, facturaModel $facturaModel): void
{
    respond(false, 'Reenvio aun no implementado: implica reconstruir XML desde la factura guardada.', 501);
}

function handlePreview(clientModel $clientModel): void
{
    respond(false, 'Preview no implementado en el flujo e-CF.', 501);
}

function handleFacturaXml(int $facturaId, facturaModel $facturaModel): void
{
    $type = ($_GET['type'] ?? 'ecf') === 'rfce' ? 'rfce' : 'ecf';
    $row = $facturaModel->getXmlFirmado($facturaId, $type);
    if ($row === null) {
        respond(false, $type === 'rfce'
            ? 'Esta factura no tiene RFCE (no es E32 < 250,000 o no se ha emitido).'
            : 'Factura no tiene XML firmado.', 404);
        return;
    }

    $filenameBase = $row['e_ncf'] ?? ('factura_' . $facturaId);
    $suffix = $type === 'rfce' ? '_RFCE' : '';
    $format = $_GET['format'] ?? 'download';

    if ($format === 'base64') {
        echo json_encode([
            'status' => true,
            'data' => [
                'filename' => $filenameBase . $suffix . '.xml',
                'content' => base64_encode($row['xml']),
                'mime_type' => 'application/xml',
            ],
        ]);
        return;
    }

    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filenameBase . $suffix . '.xml"');
    header('Content-Length: ' . strlen($row['xml']));
    echo $row['xml'];
}

function handleFacturaPdf(int $facturaId, facturaModel $facturaModel, clientModel $clientModel): void
{
    $facturas = $facturaModel->getFacturas($facturaId);
    if (empty($facturas)) {
        respond(false, 'Factura not found', 404);
        return;
    }
    $factura = $facturas[0];
    $factura['items'] = $facturaModel->getFacturaItems($facturaId);
    require_once __DIR__ . '/../Utils/FacturaPdfGenerator.php';
    $pdf = new FacturaPdfGenerator('P', 'mm', 'Letter');
    $pdf->setFactura($factura);
    $clientData = $clientModel->getClients($factura['client_id']);
    if (!empty($clientData)) {
        $pdf->setClientData($clientData[0]);
    }
    $pdfContent = $pdf->generatePdf();
    $filenameBase = $factura['e_ncf'] ?? $factura['no_factura'];
    $format = $_GET['format'] ?? 'download';
    if ($format === 'base64') {
        echo json_encode([
            'status' => true,
            'data' => [
                'filename' => 'Factura_' . $filenameBase . '.pdf',
                'content' => base64_encode($pdfContent),
                'mime_type' => 'application/pdf',
            ],
        ]);
        return;
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="Factura_' . $filenameBase . '.pdf"');
    header('Content-Length: ' . strlen($pdfContent));
    echo $pdfContent;
}

function computeTotales(array $items): array
{
    $i1 = 0.0;       // gravado al 18%
    $i2 = 0.0;       // gravado al 16%
    $i3 = 0.0;       // gravado al 0%
    $exento = 0.0;   // exento (indicador 4)
    $itbis1 = 0.0;
    $itbis2 = 0.0;
    $itbis3 = 0.0;
    $montoTotal = 0.0;

    foreach ($items as $item) {
        $cantidad = (float) ($item['cantidad'] ?? $item['quantity'] ?? 1);
        $precio = (float) ($item['precio_unitario'] ?? $item['amount'] ?? 0);
        $base = round($cantidad * $precio, 2);
        $indicador = (int) ($item['indicador_facturacion'] ?? 1);

        $itbis = 0.0;
        if ($indicador === 1) {
            $itbis = round($base * 0.18, 2);
            $i1 += $base;
            $itbis1 += $itbis;
        } elseif ($indicador === 2) {
            $itbis = round($base * 0.16, 2);
            $i2 += $base;
            $itbis2 += $itbis;
        } elseif ($indicador === 3) {
            $i3 += $base;
        } elseif ($indicador === 4 || $indicador === 0) {
            $exento += $base;
        } else {
            $i1 += $base;
            $itbis1 += round($base * 0.18, 2);
        }

        $montoTotal += $base + $itbis;
    }

    $montoGravadoTotal = $i1 + $i2 + $i3;
    $totalItbis = $itbis1 + $itbis2 + $itbis3;

    return [
        'monto_gravado_total' => round($montoGravadoTotal, 2),
        'monto_gravado_i1' => round($i1, 2),
        'monto_gravado_i2' => round($i2, 2),
        'monto_gravado_i3' => round($i3, 2),
        'monto_exento' => round($exento, 2),
        'total_itbis' => round($totalItbis, 2),
        'total_itbis1' => round($itbis1, 2),
        'total_itbis2' => round($itbis2, 2),
        'total_itbis3' => round($itbis3, 2),
        'monto_total' => round($montoTotal, 2),
    ];
}

function mapItemsForXml(array $items): array
{
    $mapped = [];
    foreach ($items as $i => $raw) {
        $cantidad = (float) ($raw['cantidad'] ?? $raw['quantity'] ?? 1);
        $precio = (float) ($raw['precio_unitario'] ?? $raw['amount'] ?? 0);
        $monto = round($cantidad * $precio, 2);
        $indicador = (int) ($raw['indicador_facturacion'] ?? 1);
        $itbis = 0.0;
        if ($indicador === 1) $itbis = round($monto * 0.18, 2);
        elseif ($indicador === 2) $itbis = round($monto * 0.16, 2);

        $mapped[] = [
            'numero_linea' => (int) ($raw['numero_linea'] ?? ($i + 1)),
            'indicador_facturacion' => $indicador,
            'indicador_agente_retencion_percepcion' => $raw['indicador_agente_retencion_percepcion'] ?? null,
            'monto_itbis_retenido' => $raw['monto_itbis_retenido'] ?? null,
            'monto_isr_retenido' => $raw['monto_isr_retenido'] ?? null,
            'nombre_item' => (string) ($raw['nombre_item'] ?? $raw['description'] ?? 'Item'),
            'indicador_bien_servicio' => (int) ($raw['indicador_bien_servicio'] ?? 2),
            'descripcion' => (string) ($raw['descripcion'] ?? $raw['description'] ?? ''),
            'cantidad' => $cantidad,
            'cantidad_raw' => $raw['cantidad_raw'] ?? null,
            'unidad_medida' => isset($raw['unidad_medida']) ? (string) $raw['unidad_medida'] : '',
            'cantidad_referencia' => $raw['cantidad_referencia'] ?? null,
            'unidad_referencia' => $raw['unidad_referencia'] ?? null,
            'subcantidades' => is_array($raw['subcantidades'] ?? null) ? $raw['subcantidades'] : [],
            'grados_alcohol' => $raw['grados_alcohol'] ?? null,
            'precio_unitario_referencia' => $raw['precio_unitario_referencia'] ?? null,
            'fecha_elaboracion' => $raw['fecha_elaboracion'] ?? null,
            'fecha_vencimiento_item' => $raw['fecha_vencimiento_item'] ?? null,
            'precio_unitario' => $precio,
            'precio_unitario_raw' => $raw['precio_unitario_raw'] ?? null,
            'descuento_monto' => $raw['descuento_monto'] ?? null,
            'subdescuentos' => is_array($raw['subdescuentos'] ?? null) ? $raw['subdescuentos'] : [],
            'recargo_monto' => $raw['recargo_monto'] ?? null,
            'subrecargos' => is_array($raw['subrecargos'] ?? null) ? $raw['subrecargos'] : [],
            'impuestos_adicionales' => is_array($raw['impuestos_adicionales'] ?? null) ? $raw['impuestos_adicionales'] : [],
            'monto_item' => isset($raw['monto_item']) && $raw['monto_item'] !== '' ? (float) $raw['monto_item'] : $monto,
            'monto_item_raw' => $raw['monto_item_raw'] ?? null,
            'itbis_amount' => $itbis,
        ];
    }
    return $mapped;
}

function mapEstadoFromConsulta(array $consulta): ?string
{
    $data = $consulta['data'] ?? null;
    if (!is_array($data)) {
        return null;
    }
    $estado = $data['estado'] ?? $data['codigo'] ?? null;
    if ($estado === null) {
        return null;
    }
    if (is_string($estado)) {
        $upper = strtoupper(trim($estado));
        if (in_array($upper, ['ACEPTADO', 'RECHAZADO', 'EN PROCESO', 'ACEPTADO CONDICIONAL'], true)) {
            return str_replace(' ', '_', $upper);
        }
    }
    if (is_numeric($estado)) {
        $map = [1 => 'ACEPTADO', 2 => 'ACEPTADO_CONDICIONAL', 3 => 'EN_PROCESO', 4 => 'RECHAZADO'];
        return $map[(int) $estado] ?? null;
    }
    return null;
}

function respond(bool $status, string $message, int $code = 200): void
{
    http_response_code($code);
    echo json_encode([
        'status' => $status,
        $status ? 'message' : 'error' => $message,
    ]);
}
