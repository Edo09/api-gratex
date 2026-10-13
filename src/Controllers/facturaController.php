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
require_once __DIR__ . '/../Utils/FacturacionElectronica/EcfUsuarioException.php';
require_once __DIR__ . '/../Utils/FacturacionElectronica/InformacionReferencia.php';
require_once __DIR__ . '/../Utils/FacturacionElectronica/EstadosEcf.php';
require_once __DIR__ . '/../Utils/FacturacionElectronica/EcfEnvioInciertoException.php';

// Texto para cualquier fallo de la emision que NO sea un caso de negocio
// (EcfUsuarioException): DGII caida, XML, firma, un Error de PHP... El detalle
// real va al error_log y al audit log, nunca al cajero.
const FACTURA_EMISION_FALLO_GENERICO = 'No se pudo emitir la factura en la DGII. Espera unos minutos y vuelve a intentarlo; si sigue fallando, avisa a soporte.';

// La DGII pudo haber recibido el e-CF pero no se sabe: la fila YA esta guardada
// (write-ahead) en ENVIO_INCIERTO o PENDIENTE_ENVIO. %s = el e-NCF de la fila.
// Texto fijado en la spec (4.7); el front lo muestra tal cual con "Ver factura".
const FACTURA_EMISION_INCIERTA = 'No se pudo confirmar si la DGII recibió la factura %s. No la emitas de nuevo: ya quedó guardada. Búscala en Facturación con el filtro Pendientes y, si sigue sin confirmar, avisa a soporte con ese número.';

// Notas de GET /api/facturas/{id}/estado para una fila sin veredicto que la
// app no puede consultar (spec 7.3, Release 1). %s = el e-NCF. Decision 7: la
// app nunca reenvia un comprobante sin confirmar; la nota manda a soporte y pide
// no emitirlo de nuevo.
const FACTURA_NOTA_EN_VUELO = 'La DGII todavía no ha confirmado este comprobante. No lo emitas de nuevo; si sigue así en unos minutos, avisa a soporte con el número %s.';
const FACTURA_NOTA_ENVIADO_SIN_TRACK = 'La DGII recibió este comprobante, pero no devolvió su número de seguimiento y por eso no se puede consultar su estado desde aquí. Si necesitas confirmarlo, avisa a soporte con el número %s.';

// POST /api/facturas/{id}/reenviar sobre un comprobante SIN confirmar (PENDIENTE_ENVIO, ENVIO_INCIERTO,
// ENVIADO, EN_PROCESO y sus RFCE_). Decision 7, redefinida el 10-12: la app nunca reenvia uno sin confirmar,
// porque la DGII pudo haberlo recibido; solo reemite uno RECHAZADO. %s = el e-NCF.
const FACTURA_REENVIO_SIN_CONFIRMAR = 'Este comprobante todavía no tiene respuesta de la DGII, así que no se puede reenviar desde aquí. No lo emitas de nuevo; avisa a soporte con el número %s.';

// Estados en que la DGII acepto el comprobante: el primer paso a uno de estos
// desde la consulta de estado mueve el stock (una sola vez).
const FACTURA_ESTADOS_ACEPTADOS = ['ACEPTADO', 'ACEPTADO_CONDICIONAL', 'RFCE_ACEPTADO', 'RFCE_ACEPTADO_CONDICIONAL'];

// format=datos (recibo de tirilla como pagina web) sin ancho de papel: la app
// siempre manda el ancho que tiene guardado el equipo, asi que si falta es la
// configuracion de la impresora de ese equipo.
const FACTURA_RECIBO_SIN_ANCHO = 'No se pudo preparar el recibo. Revisa el ancho de papel en Configuración → Impresora de recibos o imprime en hoja carta.';

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
$isStatsRequest = preg_match('/\/api\/facturas\/stats/', $endpoint);
$isModificablesRequest = preg_match('/\/api\/facturas\/modificables\/?$/', $endpoint);

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        if ($isStatsRequest) {
            handleECFStats($facturaModel);
            break;
        }
        if ($isModificablesRequest) {
            handleFacturasModificables($facturaModel);
            break;
        }
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
            $facturas = array_map('enrichFacturaTotales', $facturaModel->getFacturas($_GET['id']));
            // Notas que modifican este comprobante ("notas") y, si es una nota,
            // el que modifica ("modifica"): lo mismo que trae cada fila del listado.
            $facturas = $facturaModel->adjuntarNotasVinculadas($facturas);
            // Detalle por id: enriquecer con lineas, cliente y emisor para que el
            // front pinte el documento completo en una sola llamada. La forma de
            // `data` sigue siendo un array (compatibilidad con clientes previos).
            if (!empty($facturas)) {
                $facturas[0]['items'] = $facturaModel->getFacturaItems((int) $_GET['id']);
                if (!empty($facturas[0]['client_id'])) {
                    $clientData = $clientModel->getClients($facturas[0]['client_id']);
                    if (!empty($clientData)) {
                        $facturas[0]['cliente'] = $clientData[0];
                    }
                }
                require_once __DIR__ . '/../Models/EmisorConfigModel.php';
                $facturas[0]['emisor'] = (new EmisorConfigModel())->get();
            }
            echo json_encode(['status' => true, 'data' => $facturas]);
            break;
        }
        $page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int) $_GET['page'] : 1;
        $pageSize = isset($_GET['pageSize']) && is_numeric($_GET['pageSize']) && $_GET['pageSize'] > 0 ? (int) $_GET['pageSize'] : 10;
        $query = $_GET['query'] ?? null;
        $estado = normalizeEstadoFilter($_GET['estado'] ?? null);
        if ($estado === false) {
            respond(false, 'Ese filtro de estado no es válido. Elige otro en la lista.', 422);
            break;
        }
        $tipoEcf = normalizeTipoEcfFilter($_GET['tipo_ecf'] ?? null);
        if ($tipoEcf === false) {
            respond(false, 'Ese tipo de comprobante no es válido como filtro. Elige otro en la lista.', 422);
            break;
        }
        $offset = ($page - 1) * $pageSize;
        $facturas = array_map('enrichFacturaTotales', $facturaModel->getFacturasPaginated($offset, $pageSize, $query, $estado, $tipoEcf));
        $total = $facturaModel->getFacturasCount($query, $estado, $tipoEcf);
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
            handleReenviar((int) $reenviarMatches[1], $facturaModel, $clientModel);
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
    $input = InputSanitizer::jsonInput();
    if (!is_array($input)) {
        respond(false, 'No se pudieron leer los datos de la factura. Recarga la página e inténtalo de nuevo.', 400);
        return;
    }
    emitirFacturaEcf($input, $facturaModel, $clientModel);
}

/**
 * Valida, emite a la DGII, guarda y responde un e-CF. Cuerpo de POST
 * /api/facturas y del reenvio de un rechazado (handleReenviar), que arma el
 * mismo $input desde la factura guardada: las dos rutas pasan por las mismas
 * reglas.
 *
 * @param ?int $reenvioDe id de la factura RECHAZADA que este envio reemplaza:
 *                        al guardar se archiva y se le devuelve al almacen lo
 *                        que habia descontado (el envio nuevo descuenta otra vez).
 */
function emitirFacturaEcf(array $input, facturaModel $facturaModel, clientModel $clientModel, ?int $reenvioDe = null): void
{
    $tipoEcf = (string) ($input['tipo_ecf'] ?? '');
    $clientId = $input['client_id'] ?? null;
    $items = $input['items'] ?? null;

    if (!preg_match('/^(31|32|33|34|41|43|44|45|46|47)$/', $tipoEcf)) {
        respond(false, 'Elige el tipo de comprobante.', 422);
        return;
    }
    // E32 (Consumo) y E43 (Gastos Menores) pueden emitirse sin comprador: el e-CF
    // no exige RNCComprador (ver ECFXmlBuilder::requiereComprador). Tampoco la
    // nota de credito de un E32 a consumidor final (devolucion del POS): su
    // factura no tenia comprador y el XSD del E34 admite omitirlo. Para el resto
    // el client_id sigue siendo obligatorio.
    $permiteSinCliente = in_array($tipoEcf, ['32', '43'], true)
        || ($tipoEcf === '34' && !$clientId
            && notaDeConsumoSinCliente($facturaModel, $input['informacion_referencia'] ?? null));
    if (!$clientId && !$permiteSinCliente) {
        respond(false, 'Elige un cliente para esta factura. Solo las facturas de consumo y de gastos menores pueden ir sin cliente.', 422);
        return;
    }
    if (!is_array($items) || count($items) === 0) {
        respond(false, 'Agrega al menos un producto o servicio.', 422);
        return;
    }
    if (!assertUnidadesMedida($items)) {
        return;
    }

    $strictInput = !empty($input['strict_input']);

    // Quien emite: el user_id del body (lo manda el front) o, si no viene, el
    // usuario del token. Se resuelve ANTES de emitir: facturas.user_id es NOT
    // NULL en produccion y, si el INSERT falla, la DGII ya recibio el e-CF y
    // queda fuera de la base. Paso con E310000000058 el 2026-10-08 (un request
    // de prueba sin user_id): hubo que rescatarlo a mano.
    $userId = $input['user_id'] ?? RequestContext::userId();
    if ($userId === null || $userId === '' || (int) $userId <= 0) {
        respond(false, 'No se pudo identificar quién emite la factura. Cierra sesión y vuelve a entrar.', 422);
        return;
    }
    $userId = (int) $userId;

    // Precios con ITBIS incluido (IndicadorMontoGravado = 1): el precio de cada
    // linea es el que paga el cliente y el total sale exacto (ver
    // EcfItemMapper::desglosarIncluido). Lo usa el POS. Va en su propio campo y
    // NO se lee de `indicador_monto_gravado`: un bundle viejo del front mandaba
    // ese en "1" con precios SIN ITBIS (ver el payload abajo), y leerlo ahora le
    // quitaria el impuesto a facturas que lo deben llevar encima.
    $preciosConItbis = !$strictInput
        && filter_var($input['precios_incluyen_itbis'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if ($preciosConItbis && !in_array($tipoEcf, EcfItemMapper::TIPOS_PRECIOS_CON_ITBIS, true)) {
        respond(false, 'Los precios con ITBIS incluido solo se admiten en facturas de crédito fiscal y de consumo, y en sus notas de débito y crédito.', 422);
        return;
    }

    // Cantidad y precio con los decimales del XML, UNA vez y antes de todo: el
    // descuento del cliente, los totales, las lineas del XML y lo que se guarda
    // salen de los mismos valores (ver EcfItemMapper::normalizarCantidadPrecio).
    // La cantidad se valida antes de redondear y antes de reservar el e-NCF. El
    // set de pruebas DGII (strict_input) trae sus montos exactos: no se toca.
    if (!$strictInput) {
        if (!assertCantidadesEcf($items)) {
            return;
        }
        $items = EcfItemMapper::normalizarCantidadPrecio($items);
    }

    $client = null;
    if ($clientId) {
        $clients = $clientModel->getClients($clientId);
        if (empty($clients)) {
            respond(false, 'No encontramos ese cliente. Puede que lo hayan eliminado; búscalo de nuevo o elige otro.', 404);
            return;
        }
        $client = $clients[0];
    }

    if ($tipoEcf === '31' && empty($client['rnc'] ?? null)) {
        respond(false, 'Este cliente no tiene RNC ni cédula, y la factura de crédito fiscal los necesita. Agrégale el RNC o emite una factura de consumo.', 422);
        return;
    }

    // Credito: solo si el cliente lo tiene habilitado. TipoPago DGII: 1=Contado,
    // 2=Credito. Se valida aqui y no en el front porque es una regla comercial
    // del cliente, no una preferencia de la pantalla. El texto sale de
    // clientModel para que la factura simple diga exactamente lo mismo.
    if ($client && (int) ($input['tipo_pago'] ?? 1) === 2 && (int) ($client['permitir_credito'] ?? 0) !== 1) {
        respond(false, clientModel::mensajeSinCredito($client['client_name'] ?? null), 422);
        return;
    }

    // Descuento: el del cliente es el DEFAULT. Si la factura manda `descuento`
    // (aunque sea 0) gana ese, que es lo que el usuario decidio al crearla.
    // En modo estricto (set de pruebas DGII) no se aplica nada automatico: los
    // montos del set son exactos y cualquier ajuste nuestro lo hace fallar.
    if (!$strictInput) {
        $descuentoPct = array_key_exists('descuento', $input)
            ? (float) $input['descuento']
            : (float) ($client['descuento'] ?? 0);
        if ($descuentoPct > 0) {
            $items = EcfItemMapper::aplicarDescuentoPorcentaje($items, $descuentoPct);
        }
    }

    $totales = computeTotales($items, $preciosConItbis);
    $totalesOverride = is_array($input['totales'] ?? null)
        ? array_filter($input['totales'], fn($v) => $v !== null && $v !== '')
        : [];
    if ($strictInput && $totalesOverride) {
        $totales = $totalesOverride;
    } elseif ($totalesOverride) {
        $totales = array_merge($totales, $totalesOverride);
    }
    // Sin un monto_total numerico (p.ej. strict_input con `totales` incompleto)
    // la fila no se puede guardar: facturas.total es NOT NULL. Antes eso se
    // descubria DESPUES de la respuesta de la DGII; ahora se corta aqui, antes
    // de reservar el e-NCF.
    if (!isset($totales['monto_total']) || !is_numeric($totales['monto_total'])) {
        respond(false, 'No se pudo calcular el total de la factura. Revisa las líneas e inténtalo de nuevo.', 422);
        return;
    }

    // La cadena de respaldo salta los vacios, no solo los NULL: un cliente
    // importado con razon_social = '' dejaba RazonSocialComprador en blanco y la
    // DGII rechaza el e-CF por un campo obligatorio sin contenido.
    $primeroConTexto = static function (array $candidatos): ?string {
        foreach ($candidatos as $v) {
            if ($v !== null && trim((string) $v) !== '') {
                return (string) $v;
            }
        }
        return null;
    };
    $compradorBase = $client ? [
        'rnc' => $client['rnc'] ?? null,
        'razon_social' => $primeroConTexto([
            $client['razon_social'] ?? null,
            $client['company_name'] ?? null,
            $client['client_name'] ?? null,
        ]),
        'direccion' => $client['direccion'] ?? null,
        'municipio' => $client['municipio'] ?? null,
        'provincia' => $client['provincia'] ?? null,
        'correo' => $client['email'] ?? null,
        'contacto' => $client['client_name'] ?? null,
    ] : [];
    $compradorOverride = is_array($input['comprador'] ?? null) ? $input['comprador'] : [];
    $comprador = $strictInput
        ? $compradorOverride
        : array_merge($compradorBase, array_filter($compradorOverride, fn($v) => $v !== null && $v !== ''));

    // Norma DGII de la RI: la factura de consumo de RD$250,000 o mas tiene que
    // identificar al comprador (docs/business-rules/representacion-impresa.md).
    // Sin cliente el builder omite <Comprador>, que el XSD del E32 exige, y como
    // a ese monto no va por RFCE la DGII recibe el XML entero y lo rechaza. Se
    // corta antes de reservar el e-NCF.
    if (!$strictInput && $tipoEcf === '32' && (float) ($totales['monto_total'] ?? 0) >= 250000
        && trim((string) ($comprador['rnc'] ?? '')) === '') {
        respond(false, 'Las facturas de consumo de RD$250,000 o más tienen que identificar al comprador. Elige un cliente con RNC o cédula.', 422);
        return;
    }

    // No se puede facturar a si mismo: el RNCComprador no puede ser el RNC del
    // emisor. Cubre tanto el override del body como el RNC tomado del cliente.
    $compradorRnc = (string) ($comprador['rnc'] ?? '');
    if ($compradorRnc !== '') {
        $emisorRnc = (string) ((new EmisorConfigModel())->get()['rnc'] ?? '');
        if ($emisorRnc !== '' && $compradorRnc === $emisorRnc) {
            respond(false, 'El RNC de este cliente (' . $compradorRnc . ') es el de tu propia empresa, y no puedes facturarte a ti mismo. Elige otro cliente.', 422);
            return;
        }
    }

    // DGII rechaza NCF/campos con espacios accidentales (ej. " E310000000018"
    // en NCFModificado). Sanear e_ncf y toda informacion_referencia antes de
    // construir XML y de persistir en BD.
    $eNcf = isset($input['e_ncf']) && is_string($input['e_ncf'])
        ? preg_replace('/\s+/', '', $input['e_ncf'])
        : ($input['e_ncf'] ?? null);
    $infoReferencia = is_array($input['informacion_referencia'] ?? null)
        ? array_map(
            fn($v) => is_string($v) ? trim($v) : $v,
            $input['informacion_referencia']
        )
        : null;
    if (is_array($infoReferencia) && isset($infoReferencia['ncf_modificado']) && is_string($infoReferencia['ncf_modificado'])) {
        $infoReferencia['ncf_modificado'] = preg_replace('/\s+/', '', $infoReferencia['ncf_modificado']);
    }

    // Notas E33/E34: la referencia se valida ANTES de emitir. El servicio la
    // vuelve a validar antes de reservar el e-NCF (red para cualquier otro
    // llamador); aqui va primero para responder 422 con un texto que el usuario
    // entiende, y para las reglas que necesitan la base (ver
    // validarFacturaModificada).
    $indicadorNotaCredito = $input['indicador_nota_credito'] ?? null;
    if (in_array($tipoEcf, InformacionReferencia::TIPOS_NOTA, true)) {
        $fechaEmisionNota = (string) ($input['fecha_emision'] ?? date('d-m-Y'));
        try {
            $infoReferencia = InformacionReferencia::normalizar($tipoEcf, $infoReferencia, $strictInput, $fechaEmisionNota);
        } catch (EcfUsuarioException $e) {
            respond(false, $e->getMensajeUsuario(), 422);
            return;
        }
        // En modo estricto (set de pruebas DGII) el set trae sus propias
        // referencias e indicadores: no se contrasta ni se deriva nada.
        if (!$strictInput) {
            $errorReferencia = validarFacturaModificada(
                $facturaModel, $tipoEcf, $infoReferencia, $clientId, (float) ($totales['monto_total'] ?? 0)
            );
            if ($errorReferencia !== null) {
                respond(false, $errorReferencia, 422);
                return;
            }
            // IndicadorNotaCredito es obligatorio en el E34 y depende de las
            // fechas (0 = dentro de los 30 dias de la factura, 1 = despues). Sin
            // esto salia siempre el '0' del builder.
            if ($tipoEcf === '34' && ($indicadorNotaCredito === null || $indicadorNotaCredito === '')) {
                $indicadorNotaCredito = InformacionReferencia::indicadorNotaCredito(
                    $fechaEmisionNota, $infoReferencia['fecha_ncf_modificado']
                );
            }
        }
    }

    $payload = [
        'tipo_ecf' => $tipoEcf,
        'e_ncf' => $eNcf,
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
        // XSD DGII: 0 = los montos de las lineas NO incluyen ITBIS, 1 = si. Por
        // defecto esta ruta calcula el ITBIS encima del precio (EcfItemMapper),
        // asi que el XML dice 0. El front mandaba "1" con precios sin ITBIS (al
        // reves); un bundle viejo en cache lo seguiria mandando, por eso el 1
        // solo sale de `precios_incluyen_itbis`. El set de pruebas
        // (strict_input) trae sus propios montos y su indicador.
        'indicador_monto_gravado' => $strictInput
            ? ($input['indicador_monto_gravado'] ?? null)
            : ($preciosConItbis ? '1' : '0'),
        'indicador_nota_credito' => $indicadorNotaCredito,
        'ambiente' => $input['ambiente'] ?? null,
        'strict_input' => $strictInput,
        'emisor_override' => is_array($input['emisor'] ?? null) ? $input['emisor'] : null,
        'rfce_emisor_override' => is_array($input['rfce_emisor'] ?? null) ? $input['rfce_emisor'] : null,
        'rfce_comprador_override' => is_array($input['rfce_comprador'] ?? null) ? $input['rfce_comprador'] : null,
        'comprador' => $comprador,
        'items' => mapItemsForXml($items, $strictInput, $preciosConItbis),
        // Solo el set de pruebas (ECFEmissionService lo ignora fuera de strict).
        'descuentos_o_recargos' => $strictInput && is_array($input['descuentos_o_recargos'] ?? null)
            ? $input['descuentos_o_recargos'] : [],
        'totales' => $totales,
        'informacion_referencia' => $infoReferencia,
    ];

    // Write-ahead (spec 4.7): la fila se arma ANTES de emitir y el servicio la
    // guarda (hook 'reservar') antes de mandar nada a la DGII. Sin
    // 'no_factura': reservarFacturaECF lo llena con el e-NCF reservado.
    $facturaInput = [
        'date' => $input['date'] ?? date('Y-m-d H:i:s'),
        'client_id' => $clientId,
        'client_name' => $client['client_name'] ?? 'Consumidor Final',
        'total' => $totales['monto_total'],
        'tipo_pago' => (int) ($input['tipo_pago'] ?? 1),
        'user_id' => $userId,
        // Para Notas E33/E34: se persiste para mostrar NCF Modificado + Motivo
        // en la Representacion Impresa (norma DGII).
        'informacion_referencia' => $payload['informacion_referencia'],
        'items' => array_map(function ($item) use ($preciosConItbis) {
            // `descripcion` viene de mapItemsForXml como '' cuando el front solo
            // envia `nombre_item` (caso comun). `??` no cae con string vacio, asi
            // que se usa el nombre_item como respaldo explicito para no guardar ''.
            $descripcion = (string) ($item['descripcion'] ?? '');
            return [
                'description' => $descripcion !== '' ? $descripcion : (string) ($item['nombre_item'] ?? ''),
                'amount' => $item['precio_unitario'] ?? 0,
                'quantity' => $item['cantidad'] ?? 1,
                // Vinculo con el catalogo: sin esto la venta no puede descontar.
                'product_id' => $item['product_id'] ?? null,
                // subtotal es SIEMPRE la base sin ITBIS: el reporte de ventas y
                // el 607 lo suman como base y le suman itbis_amount aparte. Con
                // precios con ITBIS el MontoItem lo trae adentro, asi que se guarda
                // la parte neta (subtotal + itbis_amount = MontoItem firmado; la RI
                // lo vuelve a juntar, ver EcfDocumento::preciosIncluyenItbis).
                'subtotal' => $preciosConItbis ? ($item['monto_neto'] ?? 0) : ($item['monto_item'] ?? 0),
                // monto_item ya viene neto; el descuento se guarda aparte para
                // que la Representacion Impresa y el detalle lo puedan mostrar.
                'descuento_monto' => $item['descuento_monto'] ?? 0,
                'indicador_facturacion' => $item['indicador_facturacion'] ?? 1,
                'indicador_bien_servicio' => $item['indicador_bien_servicio'] ?? 2,
                // Código DGII de unidad de medida (id del catálogo; 43 = Unidad).
                'unidad_medida' => $item['unidad_medida'] ?? '43',
                'itbis_amount' => $item['itbis_amount'] ?? 0,
            ];
        }, mapItemsForXml($items, false, $preciosConItbis)),
    ];

    // Hook de persistencia (spec 4.4): 'reservar' guarda la fila (XML firmado,
    // codigo de seguridad y lineas) antes del envio; 'confirmar' le pone la
    // respuesta de la DGII; 'incierto' la deja en ENVIO_INCIERTO si el envio o
    // la confirmacion fallan. $reserva va por referencia: el catch la lee.
    $reserva = null;
    $persistencia = function (string $fase, array $ctx) use ($facturaModel, $facturaInput, &$reserva) {
        switch ($fase) {
            case 'reservar':  return $reserva = $facturaModel->reservarFacturaECF($facturaInput, $ctx);
            case 'confirmar': return $facturaModel->confirmarFacturaECF((int) $reserva['factura_id'], $ctx);
            case 'incierto':  return $facturaModel->marcarEnvioIncierto((int) $reserva['factura_id'], $ctx);
        }
        return null;
    };

    try {
        $result = (new ECFEmissionService())->emitir($payload, $persistencia);
    } catch (Throwable $e) {
        // ¿Quedo una fila guardada? Con EcfEnvioInciertoException el servicio
        // lo dice. Si no, la falla pudo llegar despues de la reserva (un hook
        // que dejo una transaccion abierta, spec 4.4 paso 4), y esa misma falla
        // pudo borrar la fila: se confirma en la base antes de nombrarla.
        $filaId = null;
        if ($e instanceof EcfEnvioInciertoException) {
            $filaId = $e->facturaId;
        } elseif ($reserva !== null) {
            try {
                $existe = $facturaModel->existeFactura((int) $reserva['factura_id']);
            } catch (Throwable $sinBase) {
                // Sin base no se puede confirmar la fila, y solo se nombra una
                // fila confirmada (spec 4.7; seccion 5 fila 18: nunca un
                // factura_id fantasma). Este camino es el paso 4 de 4.4: despues
                // de la reserva y antes del envio, asi que nada llego a la DGII y
                // el 502 generico de abajo es correcto. Si la fila existe, queda
                // en PENDIENTE_ENVIO y sale en el filtro Pendientes.
                error_log('[ECF] no se pudo confirmar la fila reservada factura_id='
                    . (int) $reserva['factura_id'] . ' e-NCF=' . (string) ($reserva['e_ncf'] ?? '')
                    . ': ' . $sinBase->getMessage());
                $existe = false;
            }
            if ($existe) {
                $filaId = (int) $reserva['factura_id'];
            }
        }

        if ($filaId !== null) {
            // El e-NCF sale de la fila, no del payload: $eNcf es el override del
            // body, que en el flujo normal (el servicio dispensa) es null.
            $eNcfFila = $e instanceof EcfEnvioInciertoException ? $e->eNcf : (string) $reserva['e_ncf'];
            // El estado que la fila tiene de verdad: 'incierto' puede fallar
            // (base caida) y un hook con la transaccion abierta nunca llega a el.
            $estadoFila = ($e instanceof EcfEnvioInciertoException && $e->marcado) ? 'ENVIO_INCIERTO' : 'PENDIENTE_ENVIO';
            $faseFila = $e instanceof EcfEnvioInciertoException ? $e->faseFallida : 'reservar';
            error_log(sprintf(
                '[ECF e-NCF=%s factura_id=%d] emision sin confirmar: estado=%s fase=%s %s: %s',
                $eNcfFila, $filaId, $estadoFila, $faseFila, get_class($e), $e->getMessage()
            ));
            AuditLogger::log([
                'module' => 'facturas', 'action' => 'EMIT', 'entity_type' => 'factura',
                'entity_id' => $eNcfFila,
                'new_values' => [
                    'factura_id' => $filaId, 'tipo_ecf' => $tipoEcf, 'client_id' => $clientId,
                    'estado_dgii' => $estadoFila, 'fase_fallida' => $faseFila,
                    'resultado_parcial' => $e instanceof EcfEnvioInciertoException ? $e->resultadoParcial : null,
                ],
                'success' => false, 'error_message' => ($e->getPrevious() ?? $e)->getMessage(),
                'description' => 'e-CF reservado y enviado sin confirmación de la DGII (envío incierto).',
            ]);
            // Reenvio de una rechazada: la fila nueva ya esta guardada aunque el envio quede sin confirmar.
            if ($reenvioDe !== null) {
                cerrarReenvioRechazado($reenvioDe, $userId, $facturaModel);
            }
            // respond() no lleva `data`: el front necesita factura_id para "Ver factura".
            http_response_code(502);
            echo json_encode([
                'status' => false,
                'error' => sprintf(FACTURA_EMISION_INCIERTA, $eNcfFila),
                'data' => [
                    'factura_id' => $filaId,
                    'e_ncf' => $eNcfFila,
                    'tipo_ecf' => $tipoEcf,
                    'estado_dgii' => $estadoFila,
                ],
            ]);
            return;
        }

        // Sin fila: nada llego a la DGII (o la reserva no se pudo hacer). El
        // audit y el error_log guardan el texto tecnico, como siempre.
        error_log('[ECF] fallo en emision tipo=' . $tipoEcf . ': ' . get_class($e) . ': ' . $e->getMessage());
        AuditLogger::log([
            'module' => 'facturas', 'action' => 'EMIT', 'entity_type' => 'factura',
            'entity_id' => $input['e_ncf'] ?? null,
            'new_values' => ['tipo_ecf' => $tipoEcf, 'client_id' => $clientId],
            'success' => false, 'error_message' => $e->getMessage(),
            'description' => 'Fallo en emision e-CF a DGII.',
        ]);
        // Al cajero, solo lo que puede entender: los casos de negocio (rango
        // agotado, numero ocupado, emisor o certificado sin configurar...) traen
        // su propio texto; cualquier otra cosa es un fallo interno y va el generico.
        respond(false, $e instanceof EcfUsuarioException
            ? $e->getMensajeUsuario()
            : FACTURA_EMISION_FALLO_GENERICO, 502);
        return;
    }

    // La fila ya esta guardada y confirmada: emitir() devuelve su factura_id.
    $facturaId = (int) $result['factura_id'];

    // Reenvio de una rechazada: la reserva ya archivo la vieja; falta devolverle su saldo de inventario.
    if ($reenvioDe !== null) {
        cerrarReenvioRechazado($reenvioDe, $userId, $facturaModel);
    }

    // La respuesta de DGII puede venir en dgii_response (e-CF integro) o
    // rfce_response (RFCE E32 < 250k).
    $dgiiResp = $result['dgii_response'] ?? $result['rfce_response'] ?? null;
    // Las mismas 10 claves de siempre (spec 7.1).
    $data = [
        'factura_id' => $facturaId,
        'e_ncf' => $result['e_ncf'],
        'track_id' => $result['track_id'] ?? null,
        'estado_dgii' => $result['estado'],
        'codigo_seguridad' => $result['codigo_seguridad'],
        'total' => $totales['monto_total'],
        'tipo_ecf' => $result['tipo_ecf'],
        'ambiente' => $result['ambiente'],
        'fecha_emision_dgii' => $result['fecha_emision_dgii'],
        'dgii_response' => $dgiiResp,
    ];
    if ($reenvioDe !== null) {
        $data['reenvio_de'] = $reenvioDe;
    }

    // DGII proceso el e-CF y lo RECHAZO (o no se encontro). El intento queda
    // persistido (historial) y, si la secuencia no se consumio
    // (secuenciaUtilizada=false), ya fue revertida para reutilizar el e-NCF.
    // Se responde como error para que la UI muestre el motivo del rechazo, pero
    // con `data` incluido para confirmar que se guardo y con que e-NCF.
    $estadoFinal = (string) ($result['estado'] ?? '');
    $rechazado = in_array($estadoFinal, ['RECHAZADO', 'RFCE_RECHAZADO', 'NO_ENCONTRADO', 'ERROR'], true);

    // Inventario: despues del veredicto. moverStockFacturaEcf no mueve nada en
    // un estado de EstadosEcf::SIN_STOCK (aqui no se usa $rechazado: le faltan
    // RFCE_NO_ENCONTRADO y RFCE_ERROR) ni dos veces la misma factura, y nunca
    // lanza: la factura ya existe y un problema de inventario se corrige con
    // un ajuste.
    moverStockFacturaEcf(
        $facturaId,
        $facturaInput['items'],
        $tipoEcf,
        $userId,
        $estadoFinal,
        'emision'
    );

    if ($rechazado) {
        AuditLogger::log([
            'module' => 'facturas', 'action' => 'EMIT', 'entity_type' => 'factura',
            'entity_id' => $result['e_ncf'] ?? null,
            'new_values' => $data, 'success' => false,
            'error_message' => dgiiMensajeRechazo($dgiiResp, $estadoFinal),
            'description' => 'e-CF ' . $estadoFinal . ' por DGII.',
        ]);
        http_response_code(422);
        echo json_encode([
            'status' => false,
            'error' => dgiiMensajeRechazoUsuario($dgiiResp, $estadoFinal),
            'data' => $data,
        ]);
        return;
    }

    AuditLogger::log([
        'module' => 'facturas', 'action' => 'EMIT', 'entity_type' => 'factura',
        'entity_id' => $result['e_ncf'] ?? null,
        'new_values' => $data,
        'description' => 'e-CF ' . ($result['tipo_ecf'] ?? '') . ' emitido (' . $estadoFinal . ').',
    ]);
    echo json_encode([
        'status' => true,
        'data' => $data,
    ]);
}

/**
 * inventoryModel con require_once guardado: is_file antes del require, porque
 * un require que falla es un fatal que ningun try/catch atrapa, y tumbaria la
 * respuesta de una factura que la DGII ya acepto. Asi baja a excepcion.
 * @throws RuntimeException si falta el archivo del modelo
 */
function inventarioModelo(): inventoryModel
{
    $ruta = __DIR__ . '/../Models/inventoryModel.php';
    if (!is_file($ruta)) {
        throw new RuntimeException('falta ' . $ruta);
    }
    require_once $ruta;
    return new inventoryModel();
}

/**
 * Mueve el stock de una factura e-CF UNA sola vez (spec 4.2.4 y 10.5), desde
 * la emision o desde la primera aceptacion que ve GET /estado ($origen solo
 * va al log). No mueve nada si $estado esta en EstadosEcf::SIN_STOCK o en
 * EstadosEcf::EN_VUELO (sin veredicto), ni si yaMovioDocumento('factura', id)
 * ya encuentra movimientos. Nunca lanza: anota en error_log '[inventario] ...'
 * y sigue (si no se puede saber si ya movio, no mueve).
 */
function moverStockFacturaEcf(int $facturaId, array $items, string $tipoEcf, ?int $userId, string $estado, string $origen): void
{
    if ($facturaId <= 0
        || in_array($estado, EstadosEcf::SIN_STOCK, true)
        || in_array($estado, EstadosEcf::EN_VUELO, true)) {
        return;
    }
    try {
        $inventario = inventarioModelo();
        if ($inventario->yaMovioDocumento('factura', $facturaId)) {
            return;
        }
        $inventario->registrarVenta($facturaId, $items, $tipoEcf, $userId);
    } catch (Throwable $e) {
        error_log('[inventario] no se pudo mover el stock de la factura ' . $facturaId
            . ' (' . $origen . ', ' . $estado . '): ' . $e->getMessage());
    }
}

/**
 * Cierra el reenvio de una rechazada (Reenviar a la DGII) cuando la fila nueva ya esta guardada: la vieja no debe
 * volver a ofrecerse ni dejar mercancia movida.
 *  - Archivo: la reserva ya archivo la rechazada (mismo e-NCF, regla REINTENTABLES de reservarFacturaECF);
 *    archivarRechazoReenviado es el respaldo y solo actua si sigue en rechazo.
 *  - Inventario: devolverSaldoDeFactura deshace lo que la rechazada haya movido (una venta del POS o una fila
 *    anterior a Release 1; la app no mueve stock en un rechazo, asi que suele ser 0). Repetirlo no duplica.
 * Nunca lanza: la fila nueva ya existe y un problema de inventario se corrige con un ajuste.
 */
function cerrarReenvioRechazado(int $reenvioDe, int $userId, facturaModel $facturaModel): void
{
    $facturaModel->archivarRechazoReenviado($reenvioDe);
    try {
        inventarioModelo()->devolverSaldoDeFactura($reenvioDe, $userId);
    } catch (Throwable $e) {
        error_log('[inventario] no se pudo devolver el saldo de la factura reenviada ' . $reenvioDe . ': ' . $e->getMessage());
    }
}

/**
 * true si la nota apunta a una factura de consumo (E32) de esta base emitida
 * sin cliente: su nota de credito tampoco lo lleva (no hay a quien ponerle) y
 * el XML sale sin <Comprador>, que el XSD del E34 permite omitir.
 *
 * Solo E32 sin cliente: una factura con cliente se acredita a ese cliente
 * (validarFacturaModificada lo exige), y una que no esta en la base no se
 * puede comprobar aqui.
 *
 * @param mixed $ref informacion_referencia del body, tal como llego.
 */
function notaDeConsumoSinCliente(facturaModel $facturaModel, $ref): bool
{
    $ncf = is_array($ref) ? (string) preg_replace('/\s+/', '', (string) ($ref['ncf_modificado'] ?? '')) : '';
    if ($ncf === '') {
        return false;
    }
    $original = $facturaModel->getReferenciaOriginal($ncf);
    return $original !== null && $original['tipo_ecf'] === '32' && $original['client_id'] === null;
}

/**
 * Motivos que da la DGII en su cuerpo de rechazo
 * ({"codigo":..,"estado":"Rechazado","mensajes":[{"valor":"..."}]}), o [] si no
 * trae mensajes estructurados.
 */
function dgiiMotivos($dgiiResponse): array
{
    if (!is_array($dgiiResponse)) {
        return [];
    }
    $mensajes = $dgiiResponse['mensajes'] ?? $dgiiResponse['mensaje'] ?? null;
    if (is_array($mensajes)) {
        $textos = [];
        foreach ($mensajes as $m) {
            if (is_array($m) && isset($m['valor']) && $m['valor'] !== '') {
                $textos[] = (string) $m['valor'];
            } elseif (is_string($m) && $m !== '') {
                $textos[] = $m;
            }
        }
        if ($textos) {
            return $textos;
        }
    }
    if (isset($dgiiResponse['mensaje']) && is_string($dgiiResponse['mensaje']) && $dgiiResponse['mensaje'] !== '') {
        return [$dgiiResponse['mensaje']];
    }
    return [];
}

/**
 * Texto TECNICO del rechazo, para el audit log (error_message). Se deja tal
 * cual estaba: es lo que soporte busca en la bitacora. Al usuario va
 * dgiiMensajeRechazoUsuario().
 */
function dgiiMensajeRechazo($dgiiResponse, string $estado): string
{
    $prefijo = in_array($estado, ['RECHAZADO', 'RFCE_RECHAZADO'], true)
        ? 'e-CF rechazado por DGII'
        : 'e-CF no procesado por DGII (' . $estado . ')';
    $textos = dgiiMotivos($dgiiResponse);
    return $textos ? $prefijo . ': ' . implode(' | ', $textos) : $prefijo . '.';
}

/**
 * Texto del rechazo para el cajero. En un rechazo se conservan los motivos de
 * la DGII (son el motivo legal y lo que hay que corregir); cuando la DGII no
 * dio veredicto (NO_ENCONTRADO / ERROR) no hay nada que corregir todavia, solo
 * esperar y revisar el estado antes de emitir otra vez.
 */
function dgiiMensajeRechazoUsuario($dgiiResponse, string $estado): string
{
    if (!in_array($estado, ['RECHAZADO', 'RFCE_RECHAZADO'], true)) {
        return 'La DGII no pudo procesar la factura. Revisa su estado en el detalle en unos minutos antes de volver a emitirla.';
    }
    $textos = dgiiMotivos($dgiiResponse);
    if (!$textos) {
        return 'La DGII rechazó la factura sin indicar el motivo. Revisa los datos y vuelve a emitirla; si sigue pasando, avisa a soporte.';
    }
    // Sin el punto final de la DGII, para no dejar ".." al unir con la frase.
    return 'La DGII rechazó la factura: ' . rtrim(implode(' | ', $textos), '. ')
        . '. Corrige lo que indica y vuelve a emitirla.';
}

/**
 * Contrasta la referencia de una nota E33/E34 con la factura que modifica,
 * cuando esa factura esta en la base (una emitida en otro sistema no se puede
 * revisar aqui: la valida la DGII). Devuelve el texto para el usuario, o null
 * si todo cuadra.
 *
 * El tope de una nota de credito es el saldo de la factura: su total, mas las
 * notas de debito, menos las de credito que la DGII acepto, tiene en proceso o
 * siguen en vuelo (PENDIENTE_ENVIO y ENVIO_INCIERTO: ya estan guardadas y
 * reservan saldo hasta que se resuelvan). Una rechazada, en ERROR o
 * NO_ENCONTRADO no cuenta: se debe poder reintentar. Sin el tope, dos notas por
 * el total restaban la misma venta dos veces en el reporte y en el dashboard.
 * Esta validacion corre ANTES de reservar la nota nueva (va antes de emitir),
 * asi que una nota en vuelo nunca cuenta contra su propio saldo.
 *
 * @param int|string|null $clientId
 */
function validarFacturaModificada(facturaModel $facturaModel, string $tipoEcf, array $ref, $clientId, float $montoNota): ?string
{
    $original = $facturaModel->getReferenciaOriginal((string) $ref['ncf_modificado']);
    if ($original === null) {
        return null;
    }
    $eNcf = $original['e_ncf'];
    $nota = $tipoEcf === '34' ? 'nota de crédito' : 'nota de débito';

    if (!in_array($original['estado_dgii'], facturaModel::ESTADOS_MODIFICABLES, true)) {
        return str_contains($original['estado_dgii'], 'RECHAZADO')
            ? 'La DGII rechazó la factura ' . $eNcf . ', así que no se le puede hacer una ' . $nota . '.'
            : 'La DGII todavía no ha aceptado la factura ' . $eNcf . ', así que aún no puedes hacerle una ' . $nota
                . '. Revisa su estado en el detalle de la factura y vuelve cuando esté aceptada.';
    }
    // Una factura a consumidor final no tiene cliente con quien comparar.
    if ($original['client_id'] !== null && $clientId && $original['client_id'] !== (int) $clientId) {
        return 'La factura ' . $eNcf . ' es de otro cliente. Elige una factura de este cliente.';
    }
    if ($original['fecha_emision'] !== '' && $original['fecha_emision'] !== $ref['fecha_ncf_modificado']) {
        return 'La factura ' . $eNcf . ' es del ' . $original['fecha_emision'] . ', no del '
            . $ref['fecha_ncf_modificado'] . '. Vuelve a elegir la factura para que tome su fecha.';
    }
    if ($tipoEcf === '34' && $montoNota > $original['saldo'] + 0.005) {
        $rd = static fn(float $v): string => 'RD$ ' . number_format($v, 2, '.', ',');
        if ($original['saldo'] <= 0) {
            return 'La factura ' . $eNcf . ' ya está acreditada por completo con otras notas de crédito: no le queda monto para otra.';
        }
        return 'Esta nota de crédito es de ' . $rd($montoNota) . ' y a la factura ' . $eNcf . ' solo le quedan '
            . $rd($original['saldo']) . ' por acreditar'
            . ($original['notas_credito'] > 0 ? ' (ya tiene notas de crédito por ' . $rd($original['notas_credito']) . ')' : '')
            . '. Baja el monto de la nota.';
    }
    return null;
}

/**
 * GET /api/facturas/modificables?client_id=&query=&limit= — facturas del
 * cliente que una nota E33/E34 puede modificar: de venta y aceptadas por la
 * DGII, con la fecha que pide FechaNCFModificado y el saldo que les queda por
 * acreditar. `query` filtra por parte del e-NCF; `limit` va de 1 a 50 (20).
 */
function handleFacturasModificables(facturaModel $facturaModel): void
{
    $clientId = filter_var($_GET['client_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($clientId === false) {
        respond(false, 'Elige un cliente para ver sus facturas.', 422);
        return;
    }
    $limit = filter_var($_GET['limit'] ?? 20, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 50]]);
    $query = is_string($_GET['query'] ?? null) ? $_GET['query'] : null;

    $filas = $facturaModel->getFacturasModificables($clientId, $query, $limit === false ? 20 : $limit);
    if ($filas === null) {
        respond(false, 'No se pudieron cargar las facturas de este cliente. Inténtalo de nuevo en unos minutos.', 500);
        return;
    }
    echo json_encode(['status' => true, 'data' => $filas]);
}

function handleConsultarEstado(int $facturaId, facturaModel $facturaModel): void
{
    $ecf = $facturaModel->getECFData($facturaId);
    if (!$ecf) {
        respond(false, 'No encontramos esta factura. Puede que ya no exista.', 404);
        return;
    }

    // Sin veredicto y sin forma de consultarlo: en vuelo (PENDIENTE_ENVIO,
    // ENVIO_INCIERTO) o ENVIADO sin track_id. Nunca 422 para una fila que
    // existe (spec 7.3). Va antes de la ruta RFCE y de updateECFEstado.
    if (in_array($ecf['estado_dgii'], EstadosEcf::EN_VUELO, true)
        || ($ecf['estado_dgii'] === 'ENVIADO' && empty($ecf['track_id']) && !empty($ecf['e_ncf']))) {
        handleEstadoEnVuelo($facturaId, $ecf);
        return;
    }

    // E32 RFCE (< 250k): no genera trackId. Se consulta por RNC + e-NCF + codigo
    // de seguridad en el servicio RecepcionFC (ConsultaRFCE), no por ConsultaResultado.
    if (empty($ecf['track_id']) && str_starts_with((string) ($ecf['estado_dgii'] ?? ''), 'RFCE_')) {
        handleConsultarEstadoRFCE($facturaId, $ecf, $facturaModel);
        return;
    }

    if (empty($ecf['track_id']) || empty($ecf['e_ncf'])) {
        respond(false, 'Esta factura no se envió a la DGII, así que no tiene un estado que consultar.', 422);
        return;
    }
    try {
        $service = new ECFEmissionService();
        $consulta = $service->consultarEstado($ecf['track_id'], $ecf['e_ncf'], $ecf['ambiente_dgii'] ?? null);
        $estadoNuevo = mapEstadoFromConsulta($consulta);
        if ($estadoNuevo !== null) {
            $estadoAnterior = $ecf['estado_dgii'] ?? '';
            $actualizado = $facturaModel->updateECFEstado($facturaId, $estadoNuevo, $consulta['data']);
            $ecf['estado_dgii'] = $estadoNuevo;
            if ($estadoAnterior !== $estadoNuevo) {
                AuditLogger::log([
                    'module' => 'facturas', 'action' => 'STATUS_CHANGE', 'entity_type' => 'factura',
                    'entity_id' => $ecf['e_ncf'] ?? $facturaId,
                    'old_values' => ['estado_dgii' => $estadoAnterior],
                    'new_values' => ['estado_dgii' => $estadoNuevo],
                    'description' => 'Transicion de estado e-CF: ' . $estadoAnterior . ' -> ' . $estadoNuevo . '.',
                ]);
            }
            moverStockPrimeraAceptacion($facturaModel, $facturaId, $ecf, $estadoAnterior, $estadoNuevo, $actualizado);

            // Si el e-CF es rechazado asincronamente y la secuencia no se consumió, revertir el contador
            $secuenciaUtilizada = normalizeSecuenciaUtilizada($consulta['data']['secuenciaUtilizada'] ?? null);
            if (($estadoNuevo === 'RECHAZADO' || $estadoNuevo === 'NO_ENCONTRADO') && 
                ($estadoAnterior !== 'RECHAZADO' && $estadoAnterior !== 'NO_ENCONTRADO')) {
                if ($secuenciaUtilizada === false) {
                    $ncfModel = new ncfModel();
                    $tipoEcf = 'E' . $ecf['tipo_ecf'];
                    $valor = (int) substr($ecf['e_ncf'], 3);
                    
                    $resultado = $ncfModel->rollbackECFSequence($tipoEcf, $valor, $ecf['ambiente_dgii'] ?? null);
                    error_log(sprintf(
                        '[ECF] consulta estado rollback: factura_id=%d e_ncf=%s estado=%s -> %s',
                        $facturaId, $ecf['e_ncf'], $estadoNuevo, $resultado ? 'revertido' : 'rollback_sin_coincidencia'
                    ));
                }
            }
        }
        echo json_encode([
            'status' => true,
            'data' => [
                'factura_id' => $facturaId,
                'e_ncf' => $ecf['e_ncf'],
                'track_id' => $ecf['track_id'],
                'estado_dgii' => $ecf['estado_dgii'],
                'secuencia_utilizada' => normalizeSecuenciaUtilizada($consulta['data']['secuenciaUtilizada'] ?? null),
                'consulta' => $consulta['data'],
            ],
        ]);
    } catch (Throwable $e) {
        error_log('[ECF] fallo consultando estado DGII factura_id=' . $facturaId . ': ' . get_class($e) . ': ' . $e->getMessage());
        respond(false, $e instanceof EcfUsuarioException
            ? $e->getMensajeUsuario()
            : 'No se pudo consultar el estado en la DGII en este momento. Inténtalo de nuevo en unos minutos.', 502);
    }
}

/**
 * Consulta el estado fiscal de un RFCE (E32 < 250k) en DGII por RNC + e-NCF +
 * codigo de seguridad. Si falta el codigo de seguridad o falla la consulta,
 * devuelve la informacion almacenada como respaldo.
 */
function handleConsultarEstadoRFCE(int $facturaId, array $ecf, facturaModel $facturaModel): void
{
    $codigoSeguridad = (string) ($ecf['codigo_seguridad'] ?? '');
    $respuestaGuardada = [
        'factura_id' => $facturaId,
        'e_ncf' => $ecf['e_ncf'],
        'tipo_ecf' => $ecf['tipo_ecf'],
        'estado_dgii' => $ecf['estado_dgii'],
        'secuencia_utilizada' => normalizeSecuenciaUtilizada($ecf['secuencia_utilizada'] ?? null),
        'rfce_estado' => $ecf['rfce_estado'] ?? null,
        'flujo' => 'RFCE',
    ];

    if ($codigoSeguridad === '' || empty($ecf['e_ncf'])) {
        $respuestaGuardada['nota'] = 'Esta factura no tiene los datos necesarios para consultarla en la DGII. Se muestra el último estado guardado.';
        echo json_encode(['status' => true, 'data' => $respuestaGuardada]);
        return;
    }

    try {
        $service = new ECFEmissionService();
        $consulta = $service->consultarEstadoRFCE($ecf['e_ncf'], $codigoSeguridad, $ecf['ambiente_dgii'] ?? null);
        $estadoBase = mapEstadoFromConsulta($consulta);
        $estadoNuevo = $estadoBase !== null ? 'RFCE_' . $estadoBase : null;
        if ($estadoNuevo !== null) {
            $estadoAnterior = $ecf['estado_dgii'] ?? '';
            $actualizado = $facturaModel->updateECFEstado($facturaId, $estadoNuevo, $consulta['data']);
            $ecf['estado_dgii'] = $estadoNuevo;
            if ($estadoAnterior !== $estadoNuevo) {
                AuditLogger::log([
                    'module' => 'facturas', 'action' => 'STATUS_CHANGE', 'entity_type' => 'factura',
                    'entity_id' => $ecf['e_ncf'] ?? $facturaId,
                    'old_values' => ['estado_dgii' => $estadoAnterior],
                    'new_values' => ['estado_dgii' => $estadoNuevo],
                    'description' => 'Transicion de estado RFCE: ' . $estadoAnterior . ' -> ' . $estadoNuevo . '.',
                ]);
            }
            moverStockPrimeraAceptacion($facturaModel, $facturaId, $ecf, $estadoAnterior, $estadoNuevo, $actualizado);

            // Si el RFCE es rechazado asincronamente y la secuencia no se consumió, revertir el contador
            $secuenciaUtilizada = normalizeSecuenciaUtilizada($consulta['data']['secuenciaUtilizada'] ?? null);
            if (($estadoNuevo === 'RFCE_RECHAZADO' || $estadoNuevo === 'RFCE_NO_ENCONTRADO') && 
                ($estadoAnterior !== 'RFCE_RECHAZADO' && $estadoAnterior !== 'RFCE_NO_ENCONTRADO')) {
                if ($secuenciaUtilizada === false) {
                    $ncfModel = new ncfModel();
                    $tipoEcf = 'E' . $ecf['tipo_ecf'];
                    $valor = (int) substr($ecf['e_ncf'], 3);
                    
                    $resultado = $ncfModel->rollbackECFSequence($tipoEcf, $valor, $ecf['ambiente_dgii'] ?? null);
                    error_log(sprintf(
                        '[ECF] consulta RFCE rollback: factura_id=%d e_ncf=%s estado=%s -> %s',
                        $facturaId, $ecf['e_ncf'], $estadoNuevo, $resultado ? 'revertido' : 'rollback_sin_coincidencia'
                    ));
                }
            }
        }
        echo json_encode([
            'status' => true,
            'data' => [
                'factura_id' => $facturaId,
                'e_ncf' => $ecf['e_ncf'],
                'tipo_ecf' => $ecf['tipo_ecf'],
                'estado_dgii' => $ecf['estado_dgii'],
                'secuencia_utilizada' => normalizeSecuenciaUtilizada($consulta['data']['secuenciaUtilizada'] ?? null),
                'flujo' => 'RFCE',
                'consulta' => $consulta['data'],
            ],
        ]);
    } catch (Throwable $e) {
        // La nota la puede leer el usuario: el motivo tecnico solo al error_log.
        error_log('[ECF] fallo consultando RFCE a DGII factura_id=' . $facturaId . ': ' . get_class($e) . ': ' . $e->getMessage());
        $respuestaGuardada['nota'] = ($e instanceof EcfUsuarioException
            ? $e->getMensajeUsuario()
            : 'No se pudo consultar el estado en la DGII en este momento.')
            . ' Se muestra el último estado guardado.';
        echo json_encode(['status' => true, 'data' => $respuestaGuardada]);
    }
}

/**
 * GET /estado de una fila sin veredicto que no se puede consultar: en vuelo
 * (PENDIENTE_ENVIO, ENVIO_INCIERTO) o ENVIADO sin track_id. Release 1 no llama
 * a la DGII: devuelve el estado guardado y una nota que manda a soporte
 * (spec 7.3). No ofrece ningun reenvio: la app nunca reenvia un comprobante sin
 * confirmar (decision 7).
 */
function handleEstadoEnVuelo(int $facturaId, array $ecf): void
{
    $estado = (string) ($ecf['estado_dgii'] ?? '');
    $eNcf = (string) ($ecf['e_ncf'] ?? '');
    $enVuelo = in_array($estado, EstadosEcf::EN_VUELO, true);
    echo json_encode([
        'status' => true,
        'data' => [
            'factura_id' => $facturaId,
            'e_ncf' => $eNcf,
            'tipo_ecf' => (string) ($ecf['tipo_ecf'] ?? ''),
            'track_id' => ($ecf['track_id'] ?? '') !== '' ? (string) $ecf['track_id'] : null,
            'estado_dgii' => $estado,
            'secuencia_utilizada' => normalizeSecuenciaUtilizada($ecf['secuencia_utilizada'] ?? null),
            'en_vuelo' => $enVuelo,
            'nota' => sprintf($enVuelo ? FACTURA_NOTA_EN_VUELO : FACTURA_NOTA_ENVIADO_SIN_TRACK, $eNcf),
        ],
    ]);
}

/**
 * Primera aceptacion vista por la consulta de estado (e-CF o RFCE): mueve el
 * stock una sola vez (spec 4.7 y 10.5). Solo si el UPDATE de la consulta
 * cambio la fila y el estado pasa de uno no aceptado a uno aceptado: una fila
 * vieja que ya estaba ACEPTADO (ventas de antes del inventario) no mueve.
 * moverStockFacturaEcf revisa ademas yaMovioDocumento.
 */
function moverStockPrimeraAceptacion(facturaModel $facturaModel, int $facturaId, array $ecf, string $estadoAnterior, string $estadoNuevo, bool $actualizado): void
{
    if (!$actualizado
        || !in_array($estadoNuevo, FACTURA_ESTADOS_ACEPTADOS, true)
        || in_array($estadoAnterior, FACTURA_ESTADOS_ACEPTADOS, true)) {
        return;
    }
    $items = $facturaModel->getFacturaItems($facturaId);
    if ($items === []) {
        // getFacturaItems tambien da [] si la base fallo, y la proxima consulta
        // ya no vera la transicion: que quede en el log para un ajuste a mano.
        error_log(sprintf('[inventario] factura_id=%d %s aceptada sin lineas leidas: no se movio stock',
            $facturaId, (string) ($ecf['e_ncf'] ?? '')));
    }
    moverStockFacturaEcf($facturaId, $items, (string) ($ecf['tipo_ecf'] ?? ''), RequestContext::userId(), $estadoNuevo, 'consulta');
}

/**
 * POST /api/facturas/{id}/reenviar — vuelve a emitir un e-CF que la DGII RECHAZO
 * (p.ej. cod. 145, fecha de vencimiento de la secuencia invalida): corregido el
 * dato, el comprobante sale otra vez con el MISMO e-NCF. Nunca reenvia uno sin
 * confirmar (decision 7, redefinida el 10-12): la DGII pudo haberlo recibido, y
 * esos los resuelve soporte.
 *
 * Arma el mismo body que manda el front al emitir, con 'e_ncf' = el de la
 * rechazada, y lo pasa por emitirFacturaEcf: mismas validaciones y el mismo
 * write-ahead (guardar, enviar, anotar). La reserva archiva la rechazada con la
 * regla de reintentables de reservarFacturaECF. Las lineas salen del XML firmado
 * (nombre, descripcion, cantidad, precio y descuento tal como se enviaron)
 * completadas con lo que el XML no trae y la base si (producto, unidad,
 * indicadores). Cliente y emisor se leen de nuevo: si el rechazo era por un dato
 * del cliente, la correccion ya entra.
 */
function handleReenviar(int $facturaId, facturaModel $facturaModel, clientModel $clientModel): void
{
    // Antes de leer el estado: el segundo de dos clics seguidos se corta aqui, y
    // uno posterior encuentra la factura ya archivada por el primero.
    if (!$facturaModel->tomarLockReenvio($facturaId)) {
        respond(false, 'Este comprobante ya se está reenviando. Espera unos segundos y actualiza.', 409);
        return;
    }
    $facturas = $facturaModel->getFacturas($facturaId);
    if (empty($facturas)) {
        respond(false, 'No encontramos esta factura. Puede que ya no exista; actualiza el listado.', 404);
        return;
    }
    $f = $facturas[0];
    $estado = (string) ($f['estado_dgii'] ?? '');

    if (empty($f['tipo_ecf'])) {
        respond(false, 'Solo se pueden reenviar comprobantes electrónicos.', 422);
        return;
    }
    if (str_ends_with($estado, '_ARCHIVADO')) {
        respond(false, 'Este comprobante ya se reenvió. Búscalo en el listado de facturas.', 409);
        return;
    }
    // Decision 7: un comprobante sin confirmar nunca se reenvia desde la app.
    if (in_array($estado, EstadosEcf::SIN_VEREDICTO, true)) {
        respond(false, sprintf(FACTURA_REENVIO_SIN_CONFIRMAR, (string) ($f['e_ncf'] ?? '')), 409);
        return;
    }
    if (!in_array($estado, ['RECHAZADO', 'RFCE_RECHAZADO', 'NO_ENCONTRADO'], true)) {
        respond(false, 'Solo se puede reenviar un comprobante que la DGII rechazó.', 409);
        return;
    }
    if ((string) ($f['secuencia_utilizada'] ?? '') === '1') {
        respond(false, 'La DGII consumió el e-NCF de este comprobante, así que no se puede reenviar con el mismo número. Emítelo de nuevo desde Nueva factura.', 409);
        return;
    }
    $ambiente = AmbienteResolver::active();
    if ($ambiente !== null && (string) ($f['ambiente_dgii'] ?? '') !== $ambiente) {
        respond(false, 'Este comprobante se envió en otro ambiente de la DGII (' . ($f['ambiente_dgii'] ?? 'desconocido') . ') y no se puede reenviar desde aquí.', 409);
        return;
    }

    $guardadas = $facturaModel->getFacturaItems($facturaId);
    if (empty($guardadas)) {
        respond(false, 'No se encontraron las líneas de esta factura, así que no se puede reenviar. Avisa a soporte.', 422);
        return;
    }

    // Mismo numero: una rechazada sin archivar siempre lo tiene.
    if (trim((string) ($f['e_ncf'] ?? '')) === '') {
        respond(false, 'No se encontró el número de comprobante (e-NCF) de esta factura, así que no se puede reenviar. Avisa a soporte.', 422);
        return;
    }
    $input = armarInputReenvio($f, $guardadas, RequestContext::userId());
    // Con el e-NCF dado, emitir() no dispensa un numero y por eso tampoco toma el vencimiento del rango que lo
    // dispensaria: se toma aqui del rango vigente que cubre el numero. Sin esto el XML llevaria el de
    // emisor_config y una rechazada por la fecha de la secuencia (cod. 145) saldria rechazada otra vez.
    $vence = vencimientoDeLaSecuencia((string) $f['e_ncf'], (string) ($f['ambiente_dgii'] ?? ''));
    if ($vence !== null) {
        $input['fecha_vencimiento_secuencia'] = $vence;
    }
    emitirFacturaEcf($input, $facturaModel, $clientModel, $facturaId);
}

/**
 * Vencimiento (Y-m-d) del rango autorizado y vigente que cubre un e-NCF, o null si ninguno lo cubre, el rango
 * no tiene vencimiento o ncf_sequences no se pudo leer (listRanges devuelve [] en ese caso): el XML usa entonces
 * el vencimiento de emisor_config, como siempre.
 */
function vencimientoDeLaSecuencia(string $eNcf, string $ambiente): ?string
{
    if ($ambiente === '' || !preg_match('/^(E\d{2})(\d{10})$/', $eNcf, $m)) {
        return null;
    }
    require_once __DIR__ . '/../Models/ncfModel.php';
    $valor = (int) $m[2];
    foreach ((new ncfModel())->listRanges($ambiente, $m[1]) as $rango) {
        $hasta = $rango['numero_hasta'] !== null ? (int) $rango['numero_hasta'] : null;
        if ((int) $rango['numero_desde'] <= $valor && ($hasta === null || $valor <= $hasta)
            && $rango['estado'] !== 'vencido' && !empty($rango['fecha_vencimiento'])) {
            return (string) $rango['fecha_vencimiento'];
        }
    }
    return null;
}

/**
 * El body de POST /api/facturas que reproduce una factura guardada (ver
 * handleReenviar). Puro: no lee la base ni la DGII.
 *
 * @param array    $f         fila de facturas (con xml_firmado)
 * @param array    $guardadas lineas de factura_items, en orden de guardado
 * @param int|null $userId    quien reenvia (null = quien la emitio)
 */
function armarInputReenvio(array $f, array $guardadas, ?int $userId): array
{
    require_once __DIR__ . '/../Utils/Pdf/EcfDocumento.php';
    $xml = (string) ($f['xml_firmado'] ?? '');
    $lineasXml = EcfDocumento::itemsXml($xml);
    // Solo se cruzan si cuadran una a una (mismo orden: NumeroLinea = orden de guardado).
    $usarXml = count($lineasXml) === count($guardadas);
    $conValor = static fn($v) => $v !== null && trim((string) $v) !== '';

    $items = [];
    foreach (array_values($guardadas) as $i => $g) {
        $x = $usarXml ? $lineasXml[$i] : [];
        $nombre = $conValor($x['nombre_item'] ?? null) ? (string) $x['nombre_item'] : (string) ($g['description'] ?? '');
        $item = [
            'numero_linea' => $i + 1,
            'nombre_item' => mb_substr(trim($nombre), 0, 80),
            'indicador_facturacion' => (int) ($g['indicador_facturacion'] ?? 1),
            'indicador_bien_servicio' => (int) ($g['indicador_bien_servicio'] ?? 1),
            'cantidad' => (float) ($conValor($x['cantidad'] ?? null) ? $x['cantidad'] : $g['quantity']),
            'unidad_medida' => (string) ($g['unidad_medida'] ?? '43'),
            'precio_unitario' => (float) ($conValor($x['precio'] ?? null) ? $x['precio'] : $g['amount']),
        ];
        if ($conValor($x['descripcion'] ?? null)) {
            $item['descripcion'] = (string) $x['descripcion'];
        }
        $descuento = (float) ($conValor($x['descuento'] ?? null) ? $x['descuento'] : ($g['descuento_monto'] ?? 0));
        if ($descuento > 0) {
            $item['descuento_monto'] = $descuento;
        }
        if ((int) ($g['product_id'] ?? 0) > 0) {
            $item['product_id'] = (int) $g['product_id'];
        }
        $items[] = $item;
    }

    $tipoPagoXml = campoXmlReenvio($xml, 'TipoPago');
    $tipoPago = $tipoPagoXml !== '' ? (int) $tipoPagoXml : (int) ($f['tipo_pago'] ?? 1);
    $input = [
        'tipo_ecf' => (string) $f['tipo_ecf'],
        // El mismo e-NCF de la rechazada: emitir() no dispensa uno nuevo y la reserva archiva la fila vieja.
        'e_ncf' => (string) $f['e_ncf'],
        'client_id' => $f['client_id'] ?: null,
        'user_id' => $userId ?? $f['user_id'] ?? null,
        'tipo_pago' => $tipoPago,
        'tipo_ingresos' => campoXmlReenvio($xml, 'TipoIngresos') ?: '01',
        // El descuento ya viaja en cada linea: 0 para que no se aplique el del cliente encima.
        'descuento' => 0,
        'precios_incluyen_itbis' => campoXmlReenvio($xml, 'IndicadorMontoGravado') === '1',
        'items' => $items,
    ];
    // A credito se conserva el PLAZO, no la fecha: reenviado otro dia, la fecha
    // vieja podria quedar antes de la emision nueva y la DGII la rechaza.
    if ($tipoPago === 2) {
        $emision = DateTime::createFromFormat('!d-m-Y', campoXmlReenvio($xml, 'FechaEmision'));
        $limite = DateTime::createFromFormat('!d-m-Y', campoXmlReenvio($xml, 'FechaLimitePago'));
        if ($emision && $limite && $limite > $emision) {
            $input['fecha_limite_pago'] = date('Y-m-d', strtotime('+' . $emision->diff($limite)->days . ' days'));
        }
    }
    if (in_array((string) $f['tipo_ecf'], InformacionReferencia::TIPOS_NOTA, true) && !empty($f['ncf_modificado'])) {
        $fechaOriginal = !empty($f['fecha_ncf_modificado']) ? strtotime((string) $f['fecha_ncf_modificado']) : false;
        $input['informacion_referencia'] = [
            'ncf_modificado' => (string) $f['ncf_modificado'],
            'rnc_otro_contribuyente' => null,
            'fecha_ncf_modificado' => $fechaOriginal ? date('d-m-Y', $fechaOriginal) : '',
            'codigo_modificacion' => (string) ($f['codigo_modificacion'] ?? ''),
            'razon_modificacion' => (string) ($f['razon_modificacion'] ?? ''),
        ];
    }
    return $input;
}

/** Texto de un nodo simple del XML firmado ('' si no esta). Mismo criterio que EcfDocumento::campoXml. */
function campoXmlReenvio(string $xml, string $tag): string
{
    if ($xml === '' || !preg_match('/<' . $tag . '>([^<]*)<\/' . $tag . '>/i', $xml, $m)) {
        return '';
    }
    return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8'));
}

function handlePreview(clientModel $clientModel): void
{
    $input = InputSanitizer::jsonInput();
    if (!is_array($input)) {
        respond(false, 'No se pudieron leer los datos de la factura. Recarga la página e inténtalo de nuevo.', 400);
        return;
    }

    $clientId = $input['client_id'] ?? null;
    $items    = $input['items'] ?? null;
    $tipoEcf  = (string) ($input['tipo_ecf'] ?? '');

    // E32 (Consumo) y E43 (Gastos Menores) pueden previsualizarse sin comprador
    // (consumidor final), igual que en la emisión (ver handleEmisionECF).
    $permiteSinCliente = in_array($tipoEcf, ['32', '43'], true);
    if (!$clientId && !$permiteSinCliente) {
        respond(false, 'Elige un cliente para ver la vista previa. Solo las facturas de consumo y de gastos menores pueden ir sin cliente.', 422);
        return;
    }
    if (!is_array($items) || count($items) === 0) {
        respond(false, 'Agrega al menos un producto o servicio.', 422);
        return;
    }
    if (!assertUnidadesMedida($items)) {
        return;
    }
    // Mismas cantidades y precios que la emision: la vista previa no puede
    // mostrar una linea que al emitir se rechazaria o saldria redondeada.
    if (!assertCantidadesEcf($items)) {
        return;
    }
    $items = EcfItemMapper::normalizarCantidadPrecio($items);

    // Mismo campo y misma regla que la emision (ver handleEmisionECF).
    $preciosConItbis = filter_var($input['precios_incluyen_itbis'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if ($preciosConItbis && !in_array($tipoEcf, EcfItemMapper::TIPOS_PRECIOS_CON_ITBIS, true)) {
        respond(false, 'Los precios con ITBIS incluido solo se admiten en facturas de crédito fiscal y de consumo, y en sus notas de débito y crédito.', 422);
        return;
    }

    $client = null;
    if ($clientId) {
        $clients = $clientModel->getClients($clientId);
        if (empty($clients)) {
            respond(false, 'No encontramos ese cliente. Puede que lo hayan eliminado; búscalo de nuevo o elige otro.', 404);
            return;
        }
        $client = $clients[0];
    }

    $totales = computeTotales($items, $preciosConItbis);

    $factura = [
        'no_factura'         => $input['ncf'] ?? 'PREVIEW',
        'e_ncf'              => null,
        'codigo_seguridad'   => null,
        'ambiente_dgii'      => null,
        'date'               => $input['date'] ?? date('Y-m-d'),
        'fecha_emision_dgii' => null,
        // Para la fecha limite de pago del pie (EcfDocumento::fechaLimitePago).
        'tipo_pago'          => $input['tipo_pago'] ?? 1,
        'fecha_limite_pago'  => $input['fecha_limite_pago'] ?? null,
        'total'              => $totales['monto_total'],
        'tipo_ecf'           => $input['tipo_ecf'] ?? null,
        'client_id'          => $clientId,
        'client_name'        => $client['client_name'] ?? '',
        'company_name'       => $client['company_name'] ?? null,
        'items'              => mapItemsForXml($items, false, $preciosConItbis),
        // Sin XML firmado la RI no puede leer el indicador del documento: va aqui.
        'indicador_monto_gravado' => $preciosConItbis ? 1 : 0,
    ];
    // Notas: la vista previa muestra a que factura modifican, igual que el
    // PDF de la nota emitida (EcfDocumento::notaModificacion). Sin validar: es
    // solo una vista previa.
    $refPreview = $input['informacion_referencia'] ?? null;
    if (in_array($tipoEcf, InformacionReferencia::TIPOS_NOTA, true) && is_array($refPreview)) {
        $factura['ncf_modificado'] = (string) ($refPreview['ncf_modificado'] ?? '');
        $factura['fecha_ncf_modificado'] = (string) ($refPreview['fecha_ncf_modificado'] ?? '');
        $factura['razon_modificacion'] = (string) ($refPreview['razon_modificacion'] ?? '');
    }

    require_once __DIR__ . '/../Utils/Pdf/RepresentacionImpresa.php';
    $anchoPos = RepresentacionImpresa::anchoPos($input);
    $filenameBase = ($input['ncf'] ?? 'preview') . RepresentacionImpresa::sufijo($anchoPos);
    $format = $_GET['format'] ?? $input['format'] ?? 'base64';

    // format=datos -> datos del recibo de tirilla para imprimirlo como pagina web.
    if ($format === 'datos') {
        if ($anchoPos === null) {
            respond(false, FACTURA_RECIBO_SIN_ANCHO, 422);
            return;
        }
        echo json_encode([
            'status' => true,
            'data'   => RepresentacionImpresa::datosRecibo($factura, $client ?? [], false, $anchoPos, 'Preview_' . $filenameBase),
        ]);
        return;
    }

    $pdfContent = RepresentacionImpresa::generar($factura, $client ?? [], false, $anchoPos);

    if ($format === 'download') {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="Preview_' . $filenameBase . '.pdf"');
        header('Content-Length: ' . strlen($pdfContent));
        echo $pdfContent;
        return;
    }

    echo json_encode([
        'status' => true,
        'data'   => [
            'filename'  => 'Preview_' . $filenameBase . '.pdf',
            'content'   => base64_encode($pdfContent),
            'mime_type' => 'application/pdf',
        ],
    ]);
}

function handleFacturaXml(int $facturaId, facturaModel $facturaModel): void
{
    $type = ($_GET['type'] ?? 'ecf') === 'rfce' ? 'rfce' : 'ecf';
    $row = $facturaModel->getXmlFirmado($facturaId, $type);
    if ($row === null) {
        respond(false, $type === 'rfce'
            ? 'Esta factura no tiene XML de resumen para descargar. Solo lo tienen las facturas de consumo de menos de RD$250,000 ya enviadas a la DGII.'
            : 'Esta factura no tiene XML para descargar porque no se emitió como factura electrónica.', 404);
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
        respond(false, 'No encontramos esta factura. Puede que ya no exista; actualiza el listado.', 404);
        return;
    }
    $factura = $facturas[0];
    $factura['items'] = $facturaModel->getFacturaItems($facturaId);
    // client_id puede ser null (E32 Consumo / E43 sin comprador). getClients(null)
    // devolveria TODOS los clientes, asi que solo se consulta cuando hay id.
    $client = [];
    if (!empty($factura['client_id'])) {
        $clientData = $clientModel->getClients($factura['client_id']);
        if (!empty($clientData)) {
            $client = $clientData[0];
        }
    }

    // ?formato=pos (80 mm), pos76 o pos72 -> tirilla termica de ese ancho; por
    // defecto, la hoja carta.
    require_once __DIR__ . '/../Utils/Pdf/RepresentacionImpresa.php';
    $anchoPos = RepresentacionImpresa::anchoPos();
    $filenameBase = ($factura['e_ncf'] ?? $factura['no_factura']) . RepresentacionImpresa::sufijo($anchoPos);
    $format = $_GET['format'] ?? 'download';

    // ?format=datos -> datos del recibo de tirilla para imprimirlo como pagina
    // web en vez de PDF (el navegador fija el largo del papel). Ver
    // RepresentacionImpresa::datosRecibo.
    if ($format === 'datos') {
        if ($anchoPos === null) {
            respond(false, FACTURA_RECIBO_SIN_ANCHO, 422);
            return;
        }
        echo json_encode([
            'status' => true,
            'data'   => RepresentacionImpresa::datosRecibo($factura, $client, false, $anchoPos, 'Factura_' . $filenameBase),
        ]);
        return;
    }

    $pdfContent = RepresentacionImpresa::generar($factura, $client, false, $anchoPos);
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

/**
 * Agrega totales derivados (no son columnas) a la respuesta de factura,
 * tomados del e-CF firmado: monto_gravado, monto_exento, total_itbis. El campo
 * `total` YA incluye el ITBIS (es el MontoTotal del e-CF). Sin XML (facturas
 * legacy/preview) los derivados quedan en null.
 */
function enrichFacturaTotales(array $factura): array
{
    $xml = $factura['xml_firmado'] ?? '';
    $get = static function (string $tag) use ($xml): ?string {
        if ($xml !== '' && preg_match('/<' . $tag . '>\s*([0-9.]+)\s*<\/' . $tag . '>/i', $xml, $m)) {
            return number_format((float) $m[1], 2, '.', '');
        }
        return null;
    };
    $factura['monto_gravado'] = $get('MontoGravadoTotal');
    $factura['monto_exento']  = $get('MontoExento');
    $factura['total_itbis']   = $get('TotalITBIS');
    return $factura;
}

function computeTotales(array $items, bool $preciosConItbis = false): array
{
    // Implementacion compartida con la ruta de integracion.
    return EcfItemMapper::totales($items, $preciosConItbis);
}

/**
 * Valida que la unidad_medida de cada item sea un código DGII del catálogo
 * (unidades_medida.id). Vacío/ausente se permite (toma el default 43 luego).
 *
 * Si alguno es inválido responde 422 y devuelve false: el que llama TIENE que
 * cortar con `if (!assertUnidadesMedida(...)) return;`. Antes solo respondia y
 * la ejecucion seguia: el e-CF se emitia igual en la DGII y a la respuesta se
 * le pegaba un segundo JSON, que el front leia como respuesta no valida.
 */
function assertUnidadesMedida(array $items): bool
{
    require_once __DIR__ . '/../Models/unidadMedidaModel.php';
    static $model = null;
    if ($model === null) {
        $model = new unidadMedidaModel();
    }
    foreach (array_values($items) as $i => $it) {
        $it = (array) $it;
        $u = $it['unidad_medida'] ?? null;
        if ($u === null || $u === '') {
            continue;
        }
        if (!$model->isValid($u)) {
            respond(false, 'La unidad de medida de la línea ' . ($i + 1)
                . ' no es válida. Elige otra unidad en esa línea.', 422);
            return false;
        }
    }
    return true;
}

/**
 * Valida la cantidad de cada linea de un e-CF: mayor que 0, con a lo sumo 2
 * decimales (CantidadItem es Decimal18D1or2) y entera si la unidad de la linea
 * no admite fracciones (unidades_medida.permite_decimales; sin unidad, o sin
 * la marca en la master, no se juzga). Se mira lo que mando el usuario, antes
 * de redondear: 1.125 no pasa en silencio a 1.13, se le pide corregirlo.
 *
 * Mismo contrato que assertUnidadesMedida: si falla responde 422 y devuelve
 * false, y el que llama TIENE que cortar. Va antes de reservar el e-NCF.
 */
function assertCantidadesEcf(array $items): bool
{
    require_once __DIR__ . '/../Models/unidadMedidaModel.php';
    static $model = null;
    if ($model === null) {
        $model = new unidadMedidaModel();
    }
    foreach (array_values($items) as $i => $it) {
        $it = (array) $it;
        // Misma lectura que EcfItemMapper::map (sin cantidad = 1).
        $cantidad = (float) ($it['cantidad'] ?? $it['quantity'] ?? 1);
        $problema = $model->problemaCantidad($cantidad, $it['unidad_medida'] ?? null, EcfItemMapper::DECIMALES_CANTIDAD);
        if ($problema !== null) {
            // "Línea 2: la cantidad…", igual que arma el front sus avisos por línea.
            respond(false, 'Línea ' . ($i + 1) . ': '
                . mb_strtolower(mb_substr($problema, 0, 1)) . mb_substr($problema, 1), 422);
            return false;
        }
    }
    return true;
}

function mapItemsForXml(array $items, bool $strict = false, bool $preciosConItbis = false): array
{
    // La implementacion vive en EcfItemMapper para que la ruta de
    // integracion (que no pasa por este controller) normalice igual.
    return EcfItemMapper::map($items, $strict, $preciosConItbis);
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
        if (in_array($upper, ['ACEPTADO', 'RECHAZADO', 'EN PROCESO', 'ACEPTADO CONDICIONAL', 'NO ENCONTRADO'], true)) {
            return str_replace(' ', '_', $upper);
        }
    }
    if (is_numeric($estado)) {
        // Codigos DGII (consulta resultado): 0=No encontrado, 1=Aceptado,
        // 2=Rechazado, 3=En Proceso, 4=Aceptado Condicional.
        $map = [
            0 => 'NO_ENCONTRADO',
            1 => 'ACEPTADO',
            2 => 'RECHAZADO',
            3 => 'EN_PROCESO',
            4 => 'ACEPTADO_CONDICIONAL',
        ];
        return $map[(int) $estado] ?? null;
    }
    return null;
}

/**
 * Normaliza el flag `secuenciaUtilizada` de DGII a bool|null.
 * false => el e-NCF puede reutilizarse en un nuevo envio; true => consumido.
 */
function normalizeSecuenciaUtilizada($value): ?bool
{
    if ($value === null || $value === '') {
        return null;
    }
    return (bool) filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

function handleECFStats(facturaModel $facturaModel): void
{
    $stats = $facturaModel->getECFStats();

    $labels = [
        '31' => 'Factura de Crédito Fiscal',
        '32' => 'Factura de Consumo',
        '33' => 'Nota de Débito',
        '34' => 'Nota de Crédito',
        '41' => 'Comprobante de Compras',
        '43' => 'Gastos Menores',
        '44' => 'Regímenes Especiales',
        '45' => 'Gubernamental',
        '46' => 'Comprobante de Exportaciones',
        '47' => 'Comprobante para Pagos al Exterior',
    ];

    foreach ($stats['por_tipo'] as &$tipo) {
        $tipo['nombre'] = $labels[$tipo['tipo_ecf']] ?? 'Desconocido';
        $tipo['total'] = (int) $tipo['total'];
        $tipo['aceptados'] = (int) $tipo['aceptados'];
        $tipo['rfce'] = (int) $tipo['rfce'];
        $tipo['rechazados'] = (int) $tipo['rechazados'];
        $tipo['enviados'] = (int) $tipo['enviados'];
        $tipo['monto_total'] = (float) $tipo['monto_total'];
    }
    unset($tipo);

    foreach ($stats['por_estado'] as &$estado) {
        $estado['total'] = (int) $estado['total'];
        $estado['monto_total'] = (float) $estado['monto_total'];
    }
    unset($estado);

    foreach (['por_mes', 'ventas_por_mes', 'ventas_por_dia'] as $serie) {
        foreach ($stats[$serie] as &$fila) {
            $fila['total'] = (int) $fila['total'];
            $fila['monto_total'] = (float) $fila['monto_total'];
        }
        unset($fila);
    }

    foreach ($stats['secuencias'] as &$seq) {
        $seq['secuencia_actual'] = (int) $seq['secuencia_actual'];
        $seq['total_emitidos'] = (int) $seq['total_emitidos'];
        $seq['nombre'] = $labels[ltrim($seq['type'], 'E')] ?? 'Desconocido';
    }
    unset($seq);

    if ($stats['resumen']) {
        $stats['resumen']['total_ecf'] = (int) $stats['resumen']['total_ecf'];
        $stats['resumen']['monto_total'] = (float) $stats['resumen']['monto_total'];
        $stats['resumen']['tipos_distintos'] = (int) $stats['resumen']['tipos_distintos'];
    }

    $stats['ambiente_activo'] = $facturaModel->getActiveAmbiente();
    echo json_encode(['status' => true, 'data' => $stats]);
}

/**
 * Normaliza el filtro `estado` del listado.
 * Acepta 'aprobado' (y la variante 'aprovado'), 'rechazado', 'pendiente' y
 * 'todos' en cualquier capitalizacion. 'pendiente' = sin veredicto de la DGII
 * (EstadosEcf::SIN_VEREDICTO: en vuelo, ENVIADO, EN_PROCESO y sus RFCE_).
 * 'todos', vacio o ausente => null (sin filtro).
 * @return string|null|false null = sin filtro, false = valor invalido
 */
function normalizeEstadoFilter($raw)
{
    if ($raw === null || trim((string) $raw) === '') {
        return null;
    }
    $estado = strtolower(trim((string) $raw));
    if ($estado === 'todos') {
        return null;
    }
    if ($estado === 'aprobado' || $estado === 'aprovado') {
        return 'aprobado';
    }
    if ($estado === 'rechazado') {
        return 'rechazado';
    }
    if ($estado === 'pendiente') {
        return 'pendiente';
    }
    return false;
}

/**
 * Normaliza el filtro `tipo_ecf` del listado: acepta 'E31' o '31' y devuelve
 * el codigo sin la 'E' (como se guarda en facturas.tipo_ecf).
 * @return string|null|false null = sin filtro, false = valor invalido
 */
function normalizeTipoEcfFilter($raw)
{
    if ($raw === null || trim((string) $raw) === '') {
        return null;
    }
    $tipo = strtoupper(trim((string) $raw));
    if ($tipo[0] === 'E') {
        $tipo = substr($tipo, 1);
    }
    if (!preg_match('/^(31|32|33|34|41|43|44|45|46|47)$/', $tipo)) {
        return false;
    }
    return $tipo;
}

function respond(bool $status, string $message, int $code = 200): void
{
    http_response_code($code);
    echo json_encode([
        'status' => $status,
        $status ? 'message' : 'error' => $message,
    ]);
}
