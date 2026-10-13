<?php
require_once __DIR__ . '/PosError.php';
require_once __DIR__ . '/PosPrecio.php';
require_once __DIR__ . '/../Models/posModel.php';
require_once __DIR__ . '/../Models/facturaModel.php';
require_once __DIR__ . '/../Models/unidadMedidaModel.php';
require_once __DIR__ . '/../Utils/FacturacionElectronica/ECFEmissionService.php';
require_once __DIR__ . '/../Utils/FacturacionElectronica/EcfItemMapper.php';
require_once __DIR__ . '/../Utils/FacturacionElectronica/EcfUsuarioException.php';
require_once __DIR__ . '/../Utils/FacturacionElectronica/EcfNumeroOcupadoException.php';
require_once __DIR__ . '/../Utils/FacturacionElectronica/EcfEnvioInciertoException.php';
require_once __DIR__ . '/../Utils/FacturacionElectronica/EcfVentaRepetidaException.php';
require_once __DIR__ . '/../Models/EmisorConfigModel.php';
require_once __DIR__ . '/PosCliente.php';
require_once __DIR__ . '/../Utils/FacturacionElectronica/EstadosEcf.php';
require_once __DIR__ . '/../Models/ncfModel.php';

/**
 * Cobro de una venta del POS (docs/specs/pos.md §9.5, F1, F5, F6, P1-P3) y
 * reenvio de las que quedaron pendientes (F7).
 *
 * Factura de consumo (E32) a consumidor final, por debajo de RD$250,000 (va
 * por RFCE), y credito fiscal (E31) a un cliente con RNC o cedula (e-CF
 * completo; ver PosCliente). Con cliente se aplica su descuento (V5).
 *
 * Reglas que no se negocian:
 *  - El navegador no manda precios: las lineas se arman aqui desde product_id
 *    con PosPrecio (el mismo calculo del catalogo). El total que vio el cajero
 *    viaja solo para comparar: si no coincide, no se emite (TOTAL_DISTINTO).
 *  - Una clave por intento de venta (idempotencia): la misma clave nunca saca
 *    otro e-NCF. Un candado de MySQL cubre el doble toque concurrente.
 *  - La factura se guarda ANTES de enviarse (gancho write-ahead de emitir). Desde
 *    ahi la venta existe y se entrega (caja, inventario y recibo) pase lo que
 *    pase con el envio: aceptada, rechazada o sin respuesta. El cajero no espera
 *    a la DGII ni la ve; lo que quede abierto lo cierra reenviarPendientes.
 *  - Entregar es una sola funcion idempotente (entregarVenta): repetir la clave
 *    devuelve la misma venta y completa lo que haya quedado sin hacer.
 *  - Si el e-CF no se puede generar ANTES de guardarse (certificado ausente,
 *    vencido o con otra clave, firma, datos del emisor, sin rango...), la venta
 *    no se detiene: sale como factura simple (ventaComoFacturaSimple), con la
 *    misma entrega y el mismo recibo. Lo unico que la detiene es la base.
 */
final class PosVenta
{
    /** Formas de pago (codigo DGII de TablaFormasPago). */
    public const FORMAS_PAGO = [1 => 'Efectivo', 2 => 'Transferencia / depósito', 3 => 'Tarjeta'];
    public const ANCHOS = [80, 76, 72];
    /** Tope de lineas de una venta del POS. */
    public const MAX_LINEAS = 200;
    /** E32 desde este total exige identificar al comprador (F3): no va por el POS todavia. */
    public const TOPE_CONSUMIDOR_FINAL_CENTAVOS = 25000000;

    /** Comprobantes que emite el POS: consumo y credito fiscal. */
    public const TIPOS = ['32', '31'];
    // Rechazos de la DGII: RFCE (E32 < 250k) y e-CF completo (E31). Solo sirven
    // para la bitacora: una venta rechazada se entrega igual.
    private const RECHAZADOS = ['RFCE_RECHAZADO', 'RECHAZADO'];

    /**
     * Fabrica del servicio de emision. Solo las pruebas (tools/test_pos_cobrar_wa.php)
     * la cambian, para inyectarle una DGII falsa; en produccion es null y se usa
     * el servicio de verdad.
     */
    public static ?Closure $fabricaServicio = null;

    /**
     * Cobra la venta. Devuelve los datos para la respuesta; lanza PosError con
     * su codigo en cualquier caso que el POS tenga que tratar.
     *
     * @param array $equipo  PosAuth::requerirEquipo() (con habilitado_por)
     * @param array $empleado el de la sesion
     * @param array $caja    activa
     */
    public static function cobrar(posModel $pos, array $equipo, array $empleado, array $caja, array $body): array
    {
        $clave = strtolower(trim((string) ($body['clave'] ?? '')));
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $clave) !== 1) {
            throw new PosError('No se pudo identificar la venta. Recarga la página e inténtalo de nuevo.', 422, 'CLAVE_INVALIDA');
        }
        $ancho = self::ancho($body['ancho'] ?? null);

        // Mientras otra peticion con la misma clave emite, esta espera; al
        // soltarse, la encuentra registrada y devuelve esa misma venta.
        if (!$pos->bloquearClave($clave, 45)) {
            throw new PosError('Esta venta todavía se está procesando. Espera un momento y vuelve a intentarlo.', 409, 'VENTA_EN_PROCESO');
        }
        try {
            $previa = $pos->ventaPorClave($clave);
            if ($previa !== null) {
                return self::respuestaDeVentaExistente($pos, $previa, $ancho, $body);
            }
            return self::emitirNueva($pos, $equipo, $empleado, $caja, $body, $clave, $ancho);
        } finally {
            try {
                $pos->liberarClave($clave);
            } catch (Throwable $e) {
                // La conexion se cierra al terminar el request y el candado se va con ella.
            }
        }
    }

    private static function emitirNueva(posModel $pos, array $equipo, array $empleado, array $caja, array $body, string $clave, int $ancho): array
    {
        // --- Turno propio abierto en esta caja (K3) ---------------------------
        $turno = $pos->turnoAbiertoDeCaja($caja['id']);
        if ($turno === null) {
            throw new PosError('Abre el turno de la caja antes de cobrar.', 409, 'TURNO_REQUERIDO');
        }
        if ($turno['empleado_id'] !== $empleado['id']) {
            throw new PosError("La caja tiene el turno abierto de {$turno['empleado_nombre']}. Un supervisor tiene que cerrarlo primero.",
                409, 'TURNO_AJENO', ['turno_caja' => $turno]);
        }

        // Candado del turno mientras se emite: si en este momento lo estan
        // cerrando, la venta espera y despues ve que ya no hay turno (y un cierre
        // que llega despues espera a que esta venta quede guardada).
        if (!$pos->bloquearTurno($turno['id'], 45)) {
            throw new PosError('La caja está cerrando el turno. Espera un momento.', 409, 'TURNO_OCUPADO');
        }
        try {
            $sigue = $pos->turnoAbiertoDeCaja($caja['id']);
            if ($sigue === null || $sigue['id'] !== $turno['id']) {
                throw new PosError('El turno se cerró. Abre uno nuevo para cobrar.', 409, 'TURNO_REQUERIDO');
            }
            return self::emitirEnTurno($pos, $equipo, $empleado, $caja, $body, $clave, $ancho, $turno);
        } finally {
            try {
                $pos->liberarTurno($turno['id']);
            } catch (Throwable $e) {
                // Se suelta solo al terminar el request.
            }
        }
    }

    private static function emitirEnTurno(posModel $pos, array $equipo, array $empleado, array $caja, array $body, string $clave, int $ancho, array $turno): array
    {

        // facturas.user_id es NOT NULL: el empleado del POS no es usuario, asi
        // que la venta queda a nombre del admin que habilito esta caja (y el
        // empleado en pos_empleado_id).
        $userId = (int) ($equipo['habilitado_por'] ?? 0);
        if ($userId <= 0) {
            throw new PosError('No se sabe quién habilitó este equipo. Pide a un administrador que lo habilite de nuevo.', 409, 'EQUIPO_SIN_RESPONSABLE');
        }

        // --- Comprobante y cliente (F1, F2) -------------------------------------
        $tipoEcf = (string) ($body['tipo_ecf'] ?? '32');
        if (!in_array($tipoEcf, self::TIPOS, true)) {
            throw new PosError('Ese tipo de comprobante no se emite en el POS.', 422, 'TIPO_INVALIDO');
        }
        $cliente = null;
        if ($tipoEcf === '31') {
            $clientId = $body['client_id'] ?? null;
            if (!is_int($clientId) || $clientId <= 0) {
                throw new PosError('Elige el cliente del crédito fiscal (RNC o cédula).', 422, 'CLIENTE_REQUERIDO');
            }
            $cliente = $pos->clientePorId($clientId);
            if ($cliente === null) {
                throw new PosError('Ese cliente ya no existe. Búscalo de nuevo por RNC.', 404, 'CLIENTE_NO_EXISTE');
            }
            $rncCliente = (string) preg_replace('/\D/', '', (string) ($cliente['rnc'] ?? ''));
            if (strlen($rncCliente) !== 9 && strlen($rncCliente) !== 11) {
                throw new PosError('Ese cliente no tiene RNC ni cédula: no se le puede hacer crédito fiscal.', 422, 'CLIENTE_SIN_RNC');
            }
            $rncEmisor = (string) ((new EmisorConfigModel())->get()['rnc'] ?? '');
            if ($rncEmisor !== '' && $rncEmisor === $rncCliente) {
                throw new PosError('Ese RNC es el de tu propia empresa: no puedes facturarte a ti mismo.', 422, 'AUTOFACTURA');
            }
        }
        // El descuento del cliente (V5) se aplica solo, sin PIN: lo configuro el admin.
        $descuentoPct = $cliente !== null ? max(0.0, min(100.0, round((float) ($cliente['descuento'] ?? 0), 2))) : 0.0;

        // --- Lineas desde el catalogo -----------------------------------------
        [$items, $totalCentavos] = self::armarLineas($pos, $body['lineas'] ?? null, $descuentoPct);

        $vistoPorCajero = $body['total_centavos'] ?? null;
        if (!is_int($vistoPorCajero) || $vistoPorCajero !== $totalCentavos) {
            // Con cliente va tambien su ficha: si el admin le cambio el descuento, la
            // caja lo toma de aqui y muestra el total nuevo.
            throw new PosError($cliente !== null
                ? 'El total cambió (algún precio o el descuento del cliente se actualizó). Revisa la venta y cobra de nuevo.'
                : 'El total cambió (algún precio se actualizó). Revisa la venta y cobra de nuevo.', 409, 'TOTAL_DISTINTO',
                ['total_centavos' => $totalCentavos, 'cliente' => $cliente !== null ? PosCliente::forma($cliente) : null]);
        }
        if ($tipoEcf === '32' && $totalCentavos >= self::TOPE_CONSUMIDOR_FINAL_CENTAVOS) {
            throw new PosError('Las ventas de RD$250,000 o más tienen que identificar al comprador: hazla como crédito fiscal con su RNC o cédula.',
                422, 'COMPRADOR_REQUERIDO');
        }

        // --- Forma de pago (P1-P3) ---------------------------------------------
        $cobro = self::leerCobro($body, $totalCentavos, (int) $turno['id']);
        $formaPago = $cobro['forma_pago'];
        $total = $totalCentavos / 100;

        // --- Emision -----------------------------------------------------------
        $itemsXml = EcfItemMapper::map($items, false, true);
        $totales = EcfItemMapper::totales($items, true);
        if ((int) round(((float) ($totales['monto_total'] ?? 0)) * 100) !== $totalCentavos) {
            // No deberia pasar nunca (mismo redondeo): si pasa, no se emite algo
            // distinto de lo que se cobra.
            error_log('[pos] descuadre total: carrito=' . $totalCentavos . ' mapper=' . json_encode($totales));
            throw new PosError('No se pudo cuadrar el total de la venta. Avisa a soporte.', 500, 'DESCUADRE');
        }
        $payload = [
            'tipo_ecf' => $tipoEcf,
            'fecha_emision' => date('d-m-Y'),
            'tipo_ingresos' => '01',
            'tipo_pago' => 1,
            'indicador_monto_gravado' => '1',
            'comprador' => $cliente !== null ? PosCliente::comprador($cliente) : [],
            'items' => $itemsXml,
            'totales' => $totales,
            'formas_pago' => [['forma_pago' => $formaPago, 'monto_pago' => $total]],
            'informacion_referencia' => null,
            'strict_input' => false,
            'dgii_timeout' => self::timeoutDgii(),
            // Opciones de la emision solo para el POS (la app no las pasa y se porta
            // como siempre). El numero de una venta ya sale impreso, asi que NUNCA
            // vuelve al contador, ni aunque la DGII lo rechace. Y el token de la DGII
            // se pide DESPUES de guardar: si la DGII no contesta, la venta queda
            // guardada y sin confirmar en vez de fallar.
            'reclamar_secuencia' => false,
            'autenticar_despues_de_reservar' => true,
            // Rango agotado o vencido: la venta toma el numero siguiente al ultimo, se guarda y se
            // imprime, y NO se envia (la DGII rechaza numeros fuera de un rango autorizado). La envia
            // el reenvio automatico cuando un rango registrado lo cubre (spec 2026-10-12, 4.2.6).
            'numerar_sin_rango' => true,
            // Un certificado vencido firma igual, pero la DGII rechazaria el comprobante: se revisa
            // ANTES de reservar, y la venta sale como factura simple en vez de quedar rechazada.
            'validar_vigencia_certificado' => true,
        ];

        $auditBase = [
            'pos_empleado_id' => $empleado['id'], 'empleado' => $empleado['nombre'],
            'caja_id' => $caja['id'], 'turno_id' => $turno['id'], 'total' => $total, 'forma_pago' => $formaPago,
        ];

        // Write-ahead: la factura se arma ANTES de emitir y el servicio la guarda
        // (gancho 'reservar') antes de mandar nada a la DGII. Sin 'no_factura': la
        // reserva lo llena con el e-NCF. 'pos' trae las columnas del POS: la reserva
        // las escribe y deja envio_pendiente = 1.
        $facturaInput = [
            'date' => date('Y-m-d H:i:s'),
            'client_id' => $cliente !== null ? (int) $cliente['id'] : null,
            'client_name' => $cliente !== null ? (string) ($cliente['client_name'] ?: PosCliente::forma($cliente)['nombre']) : 'Consumidor Final',
            'total' => $total,
            'tipo_pago' => 1,
            'user_id' => $userId,
            'informacion_referencia' => null,
            'items' => array_map(static function (array $it): array {
                return [
                    'description' => (string) ($it['nombre_item'] ?? ''),
                    'amount' => $it['precio_unitario'] ?? 0,
                    'quantity' => $it['cantidad'] ?? 1,
                    'product_id' => $it['product_id'] ?? null,
                    // Base sin ITBIS (reportes y 607); la RI la vuelve a juntar.
                    'subtotal' => $it['monto_neto'] ?? 0,
                    'descuento_monto' => $it['descuento_monto'] ?? 0,
                    'indicador_facturacion' => $it['indicador_facturacion'] ?? 1,
                    'indicador_bien_servicio' => $it['indicador_bien_servicio'] ?? 1,
                    'unidad_medida' => $it['unidad_medida'] ?? '43',
                    'itbis_amount' => $it['itbis_amount'] ?? 0,
                ];
            }, $itemsXml),
            'pos' => [
                'turno_id' => $turno['id'],
                'pos_empleado_id' => $empleado['id'],
                'pos_idempotency_key' => $clave,
            ],
        ];

        // Mismo gancho que facturaController::emitirFacturaEcf: 'reservar' guarda la
        // fila, 'confirmar' le pone la respuesta de la DGII e 'incierto' la deja
        // ENVIO_INCIERTO si el envio o la confirmacion fallan.
        $facturas = new facturaModel();
        $reserva = null;
        $persistencia = static function (string $fase, array $ctx) use ($facturas, $facturaInput, &$reserva) {
            switch ($fase) {
                case 'reservar':  return $reserva = $facturas->reservarFacturaECF($facturaInput, $ctx);
                case 'confirmar': return $facturas->confirmarFacturaECF((int) $reserva['factura_id'], $ctx);
                case 'incierto':  return $facturas->marcarEnvioIncierto((int) $reserva['factura_id'], $ctx);
            }
            return null;
        };

        $repetida = false;
        $claveGuardada = $clave;
        $fallo = null;
        try {
            self::emitirConReintento(self::servicioEmision(), $payload, $persistencia);
        } catch (EcfVentaRepetidaException $e) {
            // Otra peticion con esta clave ya guardo la venta (el candado de la clave
            // lo hace muy raro). El servicio ya devolvio el numero que habia tomado.
            $repetida = true;
            $claveGuardada = $e->clave;
        } catch (Throwable $e) {
            $fallo = $e;
        }

        // Quien dice si la venta existe es la base, no la excepcion: una falla despues
        // de guardar (envio sin respuesta, un gancho que dejo la transaccion abierta)
        // deja la fila en pie, y esa venta se entrega.
        $fila = $pos->ventaPorClave($claveGuardada);
        if ($fila === null) {
            // Nada quedo guardado ni llego a la DGII (el numero ya volvio al contador): la venta no
            // se detiene (spec 2026-10-12, 4.2.5). Sale como factura simple y se entrega igual.
            $causa = $fallo ?? new RuntimeException('la emision termino sin guardar la venta');
            error_log('[pos] fallo antes de guardar la venta: ' . get_class($causa) . ': ' . $causa->getMessage());
            return self::ventaComoFacturaSimple($pos, $empleado, $turno, $clave, $ancho, $userId, $cliente, $tipoEcf, $items, $cobro, $auditBase, $causa);
        }
        if ($repetida) {
            return self::respuestaDeVentaExistente($pos, $fila, $ancho, $body);
        }
        if ($fallo !== null && !($fallo instanceof EcfEnvioInciertoException)) {
            error_log('[pos] falla despues de guardar la venta ' . (int) $fila['id'] . ', se entrega igual: '
                . get_class($fallo) . ': ' . $fallo->getMessage());
        }

        $facturaId = (int) $fila['id'];
        $estado = (string) $fila['estado_dgii'];
        $pendiente = (int) $fila['envio_pendiente'] === 1;
        $eNcf = $fila['e_ncf'];
        self::entregarVenta($pos, $facturaId, $facturaInput['items'], $tipoEcf, $userId, $cobro, [
            'accion' => 'POS_VENTA',
            'entidad' => $eNcf,
            'valores' => $auditBase + [
                'factura_id' => $facturaId, 'estado_dgii' => $estado, 'envio_pendiente' => $pendiente,
                'recibido' => $cobro['recibido_centavos'] !== null ? $cobro['recibido_centavos'] / 100 : null,
            ],
            'descripcion' => $pendiente ? 'Venta del POS entregada; el envío sigue pendiente y se completa solo.' : 'Venta del POS entregada.',
        ]);
        if (in_array($estado, self::RECHAZADOS, true)) {
            $resp = json_decode((string) ($fila['rfce_respuesta'] ?? $fila['respuesta_dgii'] ?? ''), true);
            self::audit('POS_VENTA_RECHAZADA', $eNcf, $auditBase + ['factura_id' => $facturaId, 'motivo' => self::motivoRechazo($resp)],
                'La DGII rechazó la venta del POS al cobrar; la venta ya estaba entregada.', false);
        }

        return [
            'venta' => [
                'factura_id' => $facturaId,
                'e_ncf' => $eNcf,
                'tipo_ecf' => $tipoEcf,
                'estado_dgii' => $estado,
                'envio_pendiente' => $pendiente,
                'total_centavos' => $totalCentavos,
                'cliente' => $cliente !== null ? PosCliente::forma($cliente) : null,
            ],
            'cobro' => self::cobro($formaPago, $totalCentavos, $cobro['recibido_centavos'], $cobro['devuelta_centavos']),
            'recibo' => self::recibo($facturaId, $ancho),
            'repetida' => false,
        ];
    }

    /** El servicio de emision: el de verdad, o el que inyecta una prueba (PosVenta::$fabricaServicio). */
    private static function servicioEmision(): ECFEmissionService
    {
        return self::$fabricaServicio !== null ? (self::$fabricaServicio)() : new ECFEmissionService();
    }

    /**
     * Forma de pago y efectivo recibido (P1-P3), validados contra el total: lo
     * que se va a registrar en la caja. Lo usa la venta nueva y el reintento que
     * tiene que completar un cobro que quedo sin registrar.
     *
     * @return array{turno_id: int, forma_pago: int, total_centavos: int, recibido_centavos: ?int, devuelta_centavos: ?int, iniciada_at: ?string}
     * @throws PosError FORMA_PAGO_INVALIDA | RECIBIDO_INSUFICIENTE
     */
    private static function leerCobro(array $body, int $totalCentavos, int $turnoId): array
    {
        $formaPago = (int) ($body['forma_pago'] ?? 0);
        if (!isset(self::FORMAS_PAGO[$formaPago])) {
            throw new PosError('Elige la forma de pago.', 422, 'FORMA_PAGO_INVALIDA');
        }
        $recibidoCentavos = null;
        $devueltaCentavos = null;
        if ($formaPago === 1) {
            $recibidoCentavos = $body['recibido_centavos'] ?? null;
            if (!is_int($recibidoCentavos) || $recibidoCentavos < $totalCentavos || $recibidoCentavos > 100000000) {
                throw new PosError('El efectivo recibido no alcanza para el total.', 422, 'RECIBIDO_INSUFICIENTE');
            }
            $devueltaCentavos = $recibidoCentavos - $totalCentavos;
        }
        return [
            'turno_id' => $turnoId,
            'forma_pago' => $formaPago,
            'total_centavos' => $totalCentavos,
            'recibido_centavos' => $recibidoCentavos,
            'devuelta_centavos' => $devueltaCentavos,
            'iniciada_at' => self::iniciadaAt($body['iniciada_ms'] ?? null),
        ];
    }

    /**
     * El cobro de una venta cuyo movimiento de caja ya existe, con la misma
     * forma que leerCobro. No se vuelve a escribir: solo arma la respuesta.
     *
     * @param array $mov fila de posModel::movimientoDeFactura
     */
    private static function cobroDeMovimiento(array $mov, int $turnoId, int $totalCentavos): array
    {
        return [
            'turno_id' => $turnoId,
            'forma_pago' => (int) ($mov['forma_pago'] ?? 1),
            'total_centavos' => $totalCentavos,
            'recibido_centavos' => isset($mov['monto_recibido']) ? (int) round(((float) $mov['monto_recibido']) * 100) : null,
            'devuelta_centavos' => isset($mov['devuelta']) ? (int) round(((float) $mov['devuelta']) * 100) : null,
            'iniciada_at' => null,
        ];
    }

    /**
     * Entrega una venta ya guardada: movimiento de caja, salida de inventario y
     * bitacora. Es idempotente. La llaman la venta nueva y el reintento de una
     * venta que ya existe, siempre con el candado de la clave tomado, y cada parte
     * se hace solo si falta: una caida a medias se completa en el reintento sin
     * duplicar nada.
     *  1. Caja, solo si la factura no tiene movimiento. Va primero: es el dinero.
     *     Si falla, el error sube y el reintento con la misma clave lo completa.
     *  2. Inventario, solo si yaMovioDocumento('factura', id) es false. Un fallo
     *     aqui no tumba una venta que ya existe: queda en el log para un ajuste.
     *  3. Bitacora, solo si esta llamada registro la caja (la primera entrega).
     *
     * @param array $items  lineas de la factura (product_id, quantity)
     * @param array $cobro  forma de leerCobro (turno_id, forma_pago, total_centavos, recibido_centavos, devuelta_centavos, iniciada_at)
     * @param array $auditCtx {accion: string, entidad: ?string, valores: array, descripcion: string}
     */
    private static function entregarVenta(posModel $pos, int $facturaId, array $items, ?string $tipoEcf, int $userId, array $cobro, array $auditCtx): void
    {
        $registroCaja = false;
        if ($pos->movimientoDeFactura($facturaId) === null) {
            $pos->registrarMovimiento(
                (int) $cobro['turno_id'], $facturaId, 'VENTA', (int) $cobro['forma_pago'], $cobro['total_centavos'] / 100,
                $cobro['recibido_centavos'] !== null ? $cobro['recibido_centavos'] / 100 : null,
                $cobro['devuelta_centavos'] !== null ? $cobro['devuelta_centavos'] / 100 : null,
                $cobro['iniciada_at']
            );
            $registroCaja = true;
        }

        try {
            require_once __DIR__ . '/../Models/inventoryModel.php';
            $inventario = new inventoryModel();
            if (!$inventario->yaMovioDocumento('factura', $facturaId)) {
                $inventario->registrarVenta($facturaId, $items, $tipoEcf, $userId > 0 ? $userId : null);
            }
        } catch (Throwable $e) {
            error_log('[pos] inventario de la factura ' . $facturaId . ': ' . $e->getMessage());
        }

        if ($registroCaja) {
            self::audit($auditCtx['accion'], $auditCtx['entidad'], $auditCtx['valores'], $auditCtx['descripcion']);
        }
    }

    /**
     * Lineas para EcfItemMapper desde el catalogo, y el total en centavos.
     * Cantidades con 2 decimales como maximo, y enteras si la unidad no admite
     * fracciones. Un producto repetido se suma en una sola linea.
     *
     * @return array{0: array, 1: int}
     */
    private static function armarLineas(posModel $pos, $lineas, float $descuentoPct = 0.0): array
    {
        // Descuento del cliente por linea: round(bruto x % / 100, 2), igual que
        // EcfItemMapper::aplicarDescuentoPorcentaje y que el carrito.
        $pct100 = (int) round($descuentoPct * 100);
        if (!is_array($lineas) || $lineas === [] || count($lineas) > self::MAX_LINEAS) {
            throw new PosError('La venta no tiene productos.', 422, 'VENTA_VACIA');
        }
        $cantidades = [];
        foreach ($lineas as $l) {
            $id = is_array($l) ? ($l['product_id'] ?? null) : null;
            $cant = is_array($l) ? ($l['cantidad'] ?? null) : null;
            if (!is_int($id) || $id <= 0 || !(is_int($cant) || is_float($cant)) || !is_finite((float) $cant)) {
                throw new PosError('Una línea de la venta no es válida. Quítala y agrégala de nuevo.', 422, 'LINEA_INVALIDA');
            }
            // Sin redondear: la cantidad se juzga tal como llego (1.255 libras
            // no pasa calladamente a 1.26). problemaCantidad tolera el ruido binario.
            $cantidades[$id] = ($cantidades[$id] ?? 0) + (float) $cant;
        }
        $productos = $pos->productosPorId(array_keys($cantidades));
        $unidades = new unidadMedidaModel();
        $items = [];
        $total = 0;
        foreach ($cantidades as $id => $cantidad) {
            $p = $productos[$id] ?? null;
            $indicador = $p !== null ? (int) $p['indicador_facturacion'] : 0;
            if ($p === null || (int) $p['activo'] !== 1 || PosPrecio::tasa($indicador) === null) {
                throw new PosError(($p['nombre'] ?? 'Un producto') . ' ya no se vende. Quítalo de la venta.', 409, 'PRODUCTO_NO_DISPONIBLE',
                    ['product_id' => $id]);
            }
            $problema = $unidades->problemaCantidad($cantidad, $p['unidad_medida'], EcfItemMapper::DECIMALES_CANTIDAD);
            if ($problema !== null) {
                throw new PosError($p['nombre'] . ': ' . mb_strtolower(mb_substr($problema, 0, 1)) . mb_substr($problema, 1),
                    422, 'CANTIDAD_INVALIDA', ['product_id' => $id]);
            }
            $cantidad = round($cantidad, EcfItemMapper::DECIMALES_CANTIDAD);
            $centavos = PosPrecio::finalCentavos((string) $p['precio'], $indicador);
            if ($centavos <= 0) {
                throw new PosError($p['nombre'] . ' no tiene precio. Corrígelo en FiscalPoint antes de venderlo.', 422, 'PRECIO_CERO',
                    ['product_id' => $id]);
            }
            // round(precio x cantidad, 2) en enteros: lo mismo que el carrito y que el mapper.
            $centesimas = (int) round($cantidad * 100);
            $bruto = intdiv($centavos * $centesimas + 50, 100);
            $total += $bruto - ($pct100 > 0 ? intdiv($bruto * $pct100 + 5000, 10000) : 0);
            $items[] = [
                'product_id' => $id,
                'nombre_item' => (string) $p['nombre'],
                'descripcion' => '',
                'cantidad' => $cantidad,
                'precio_unitario' => $centavos / 100,
                'indicador_facturacion' => $indicador,
                'indicador_bien_servicio' => (int) ($p['indicador_bien_servicio'] ?? 1),
                'unidad_medida' => (string) ($p['unidad_medida'] ?: '43'),
            ];
        }
        $items = EcfItemMapper::normalizarCantidadPrecio($items);
        if ($descuentoPct > 0) {
            $items = EcfItemMapper::aplicarDescuentoPorcentaje($items, $descuentoPct);
        }
        return [$items, $total];
    }

    /**
     * La venta ya existe con esa clave: se devuelve la misma (nunca otro e-NCF),
     * este como este su envio (aceptada, rechazada, sin confirmar). Siempre
     * responde como venta repetida y completa lo que le falte a la entrega.
     *
     * Sin movimiento de caja, el servidor cayo (o fallo la base) entre guardar la
     * venta y registrar el cobro. El reintento trae el mismo cuerpo, y de ahi salen
     * la forma de pago y el efectivo recibido; si ese cuerpo no los trae validos
     * responde 422 como una venta nueva. El movimiento queda en el turno de la
     * venta, aunque ese turno ya se haya cerrado.
     */
    private static function respuestaDeVentaExistente(posModel $pos, array $venta, int $ancho, array $body): array
    {
        $facturaId = (int) $venta['id'];
        $turnoId = (int) ($venta['turno_id'] ?? 0);
        $totalCentavos = (int) round(((float) $venta['total']) * 100);
        $mov = $pos->movimientoDeFactura($facturaId);
        $cobro = $mov !== null
            ? self::cobroDeMovimiento($mov, $turnoId, $totalCentavos)
            : self::leerCobro($body, $totalCentavos, $turnoId);
        $tipoEcf = $venta['tipo_ecf'] !== null ? (string) $venta['tipo_ecf'] : null;
        self::entregarVenta($pos, $facturaId, (new facturaModel())->getFacturaItems($facturaId), $tipoEcf, (int) ($venta['user_id'] ?? 0), $cobro, [
            'accion' => 'POS_VENTA',
            'entidad' => $venta['e_ncf'] ?? null,
            'valores' => [
                'factura_id' => $facturaId, 'turno_id' => $turnoId, 'estado_dgii' => (string) $venta['estado_dgii'],
                'envio_pendiente' => (int) $venta['envio_pendiente'] === 1, 'completada_en_reintento' => true,
            ],
            'descripcion' => 'Venta del POS completada en un reintento: faltaba registrar el cobro.',
        ]);
        return [
            'venta' => [
                'factura_id' => $facturaId,
                'e_ncf' => self::numeroDeVenta($venta),
                'tipo_ecf' => $tipoEcf,
                'estado_dgii' => (string) $venta['estado_dgii'],
                'envio_pendiente' => (int) $venta['envio_pendiente'] === 1,
                'total_centavos' => $totalCentavos,
                'cliente' => !empty($venta['client_id']) && ($c = $pos->clientePorId((int) $venta['client_id'])) !== null
                    ? PosCliente::forma($c) : null,
            ],
            'cobro' => self::cobro($cobro['forma_pago'], $totalCentavos, $cobro['recibido_centavos'], $cobro['devuelta_centavos']),
            'recibo' => self::recibo($facturaId, $ancho),
            'repetida' => true,
        ];
    }

    private static function cobro(int $forma, int $total, ?int $recibido, ?int $devuelta): array
    {
        return [
            'forma_pago' => $forma,
            'forma_pago_nombre' => self::FORMAS_PAGO[$forma] ?? 'Otra',
            'total_centavos' => $total,
            'recibido_centavos' => $recibido,
            'devuelta_centavos' => $devuelta,
        ];
    }

    /**
     * Datos del recibo de tirilla (los mismos de app.*: RepresentacionImpresa).
     * null si no se pudo armar: la venta ya existe y se reimprime despues.
     */
    public static function recibo(int $facturaId, int $ancho): ?array
    {
        try {
            require_once __DIR__ . '/../Utils/Pdf/RepresentacionImpresa.php';
            $facturas = new facturaModel();
            $fila = $facturas->getFacturas($facturaId);
            if (empty($fila)) {
                return null;
            }
            $factura = $fila[0];
            $factura['items'] = $facturas->getFacturaItems($facturaId);
            // Factura simple (tipo_ecf NULL): se imprime como cualquier factura, con su numero interno y sin timbre.
            $simple = ($factura['tipo_ecf'] ?? null) === null;
            $nombre = 'Factura_' . ($simple ? ($factura['no_factura'] ?? $facturaId) : ($factura['e_ncf'] ?? $facturaId)) . RepresentacionImpresa::sufijo($ancho);
            // Credito fiscal: el recibo lleva el RNC y la razon social del comprador.
            $cliente = !empty($factura['client_id']) ? ((new posModel())->clientePorId((int) $factura['client_id']) ?? []) : [];
            return RepresentacionImpresa::datosRecibo($factura, $cliente, $simple, $ancho, $nombre);
        } catch (Throwable $e) {
            error_log('[pos] recibo de la factura ' . $facturaId . ': ' . get_class($e) . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * emitir con UN reintento cuando otra fila ya tenia el numero
     * (EcfNumeroOcupadoException): el contador ya avanzo, asi que el segundo
     * intento toma el siguiente. Un segundo choque sale tal cual y la venta
     * sigue como factura simple (ventaComoFacturaSimple). Cualquier otra
     * excepcion sale sin reintentar: repetir un envio incierto duplicaria la venta.
     */
    private static function emitirConReintento(ECFEmissionService $servicio, array $payload, ?callable $persistencia = null): array
    {
        try {
            return $servicio->emitir($payload, $persistencia);
        } catch (EcfNumeroOcupadoException $e) {
            error_log('[pos] e-NCF ocupado al reservar; se reintenta con el siguiente: ' . $e->getMessage());
            return $servicio->emitir($payload, $persistencia);
        }
    }

    /**
     * El e-CF no se pudo generar (certificado ausente, vencido o con otra clave,
     * fallo de firma, datos del emisor incompletos, sin rango...): nada llego a
     * la DGII y el contador ya recupero su numero. La venta NO se detiene (spec
     * 2026-10-12, 4.2.5; U8/U9): sale como factura simple, sin e-NCF, sin ITBIS y
     * sin envio, con las mismas lineas, el mismo total y las columnas del POS, asi
     * que la clave de idempotencia sigue valiendo. La entrega (caja, inventario,
     * auditoria) es la de cualquier venta (entregarVenta) y el cajero no ve nada
     * distinto: 201 con su recibo.
     *
     * Nunca arma una segunda factura para una venta que ya tiene fila: con
     * EcfEnvioInciertoException o EcfVentaRepetidaException hay un e-CF guardado
     * con esta clave y la excepcion se relanza.
     *
     * @param array $items lineas de armarLineas (nombre_item, cantidad, precio_unitario final...)
     * @param array $cobro el de leerCobro (turno_id, forma_pago, total_centavos, recibido_centavos, devuelta_centavos, iniciada_at)
     * @return array 201: {venta, cobro, recibo, repetida: false}
     */
    private static function ventaComoFacturaSimple(
        posModel $pos, array $empleado, array $turno, string $clave, int $ancho, int $userId, ?array $cliente,
        string $tipoEcfPedido, array $items, array $cobro, array $auditBase, Throwable $causa
    ): array {
        if ($causa instanceof EcfEnvioInciertoException || $causa instanceof EcfVentaRepetidaException) {
            throw $causa;
        }
        // El texto del fallo ya quedo en el log (lo escribio quien llama).
        error_log('[pos] la venta sale como factura simple: ' . get_class($causa));

        $facturas = new facturaModel();
        try {
            $facturaId = $facturas->crearFacturaSimplePos([
                'date' => date('Y-m-d H:i:s'),
                'client_id' => $cliente !== null ? (int) $cliente['id'] : null,
                'client_name' => $cliente !== null ? (string) ($cliente['client_name'] ?: PosCliente::forma($cliente)['nombre']) : 'Consumidor Final',
                'total' => $cobro['total_centavos'] / 100,
                'user_id' => $userId,
                // El precio final (con ITBIS) de cada linea: la factura simple no desglosa impuestos.
                'items' => array_map(static function (array $it): array {
                    return [
                        'product_id' => $it['product_id'] ?? null,
                        'description' => (string) ($it['nombre_item'] ?? ''),
                        'amount' => $it['precio_unitario'] ?? 0,
                        'quantity' => $it['cantidad'] ?? 1,
                        'descuento_monto' => $it['descuento_monto'] ?? 0,
                        'indicador_bien_servicio' => $it['indicador_bien_servicio'] ?? 1,
                        'unidad_medida' => $it['unidad_medida'] ?? '43',
                    ];
                }, $items),
                'pos' => ['turno_id' => $turno['id'], 'pos_empleado_id' => $empleado['id'], 'pos_idempotency_key' => $clave],
            ]);
        } catch (EcfVentaRepetidaException $e) {
            // Otra peticion dejo una fila con esta clave entre tanto: el reintento
            // del cajero (misma clave) la encuentra y la entrega, sin duplicarla.
            throw new PosError('Esta venta todavía se está procesando. Espera un momento y vuelve a intentarlo.', 409, 'VENTA_EN_PROCESO');
        } catch (Throwable $e) {
            // Sin base no hay donde guardar la venta: lo unico que detiene el POS.
            error_log('[pos] factura simple de la venta: ' . get_class($e) . ': ' . $e->getMessage());
            self::audit('POS_VENTA_FALLIDA', null, $auditBase + ['causa' => get_class($causa)], 'No se pudo guardar la venta del POS.', false);
            throw new PosError('No se pudo guardar la venta en este momento.', 500, 'VENTA_NO_GUARDADA');
        }

        $guardada = $facturas->getFacturaSimple($facturaId) ?? [];
        $numero = (string) ($guardada['no_factura'] ?? '');
        // Un solo evento, el de entregarVenta. Solo la clase del fallo: el mensaje puede traer rutas del servidor.
        self::entregarVenta($pos, $facturaId, $facturas->getFacturaItems($facturaId), null, $userId, $cobro, [
            'accion' => 'POS_VENTA_FACTURA_SIMPLE',
            'entidad' => $numero,
            'valores' => $auditBase + ['factura_id' => $facturaId, 'tipo_ecf_pedido' => $tipoEcfPedido, 'causa' => get_class($causa)],
            'descripcion' => 'La venta del POS salió como factura simple: no se pudo generar el comprobante electrónico.',
        ]);

        return [
            'venta' => [
                'factura_id' => $facturaId,
                'e_ncf' => $numero,
                'tipo_ecf' => null,
                // El default de la columna en toda factura simple: no es un estado de la DGII.
                'estado_dgii' => (string) ($guardada['estado_dgii'] ?? ''),
                'envio_pendiente' => false,
                'total_centavos' => $cobro['total_centavos'],
                'cliente' => $cliente !== null ? PosCliente::forma($cliente) : null,
            ],
            'cobro' => self::cobro($cobro['forma_pago'], $cobro['total_centavos'], $cobro['recibido_centavos'], $cobro['devuelta_centavos']),
            'recibo' => self::recibo($facturaId, $ancho),
            'repetida' => false,
        ];
    }

    /** Numero que imprime la venta: el e-NCF o, en una factura simple, su numero interno. */
    private static function numeroDeVenta(array $fila): string
    {
        $eNcf = trim((string) ($fila['e_ncf'] ?? ''));
        return $eNcf !== '' ? $eNcf : trim((string) ($fila['no_factura'] ?? ''));
    }

    public static function ancho($valor): int
    {
        $n = is_numeric($valor) ? (int) $valor : 80;
        return in_array($n, self::ANCHOS, true) ? $n : 80;
    }

    // ------------------------------------------------------------------
    // Reenvio de pendientes (F7, §9.6)
    // ------------------------------------------------------------------

    /**
     * Una venta PENDIENTE_ENVIO mas nueva que esto sigue en curso: la peticion que la guardo todavia esta
     * enviando (el envio dura como mucho POS_DGII_TIMEOUT segundos). El reenvio no la toca, o mandaria dos
     * veces el mismo comprobante.
     */
    public const REENVIO_ESPERA_SEG = 120;

    /**
     * Candidatas que se leen por pasada y por grupo. El reenvio salta las que todavia no le toca (en curso, o
     * sin_rango sin rango que las cubra) y no puede quedarse sin ver las que si: por eso las sin_rango se leen
     * aparte y no entran en el LIMIT de las demas.
     */
    public const REENVIO_CANDIDATAS = 60;

    /**
     * Reenvia hasta $max ventas pendientes. Antes de reenviar CONSULTA a la DGII por e-NCF + codigo de
     * seguridad (RFCE) o por e-NCF (e-CF): si ya la tenia (el timeout fue de la respuesta, no del envio), solo
     * se actualiza el estado. Una que siga sin respuesta queda pendiente para la proxima vuelta.
     *
     * Reglas (spec 4.3):
     *  - El flujo lo decide el XML guardado: RFCE si rfce_xml no es nulo, e-CF completo si no. PENDIENTE_ENVIO
     *    y ENVIO_INCIERTO no llevan el prefijo RFCE_ del estado.
     *  - Nunca re-firma ni devuelve numeros al contador: el numero de una venta del POS ya esta impreso.
     *  - Una venta cuya peticion sigue viva no se toca: se salta mientras tenga tomado el candado de su clave
     *    (el mismo de cobrar; aqui se pide sin esperar y se suelta al terminar con la fila).
     *  - Cada escritura es compare-and-set sobre el estado que se leyo (posModel::marcarEnvio*) y pone
     *    envio_pendiente en la misma sentencia: si otro proceso ya movio la fila, no se pisa.
     *
     * @return array{revisadas: int, aceptadas: int, rechazadas: array, pendientes: int}
     */
    public static function reenviarPendientes(posModel $pos, int $max = 5): array
    {
        $aceptadas = 0;
        $rechazadas = [];
        $revisadas = 0;
        foreach (self::pendientesAReenviar($pos) as $v) {
            if ($revisadas >= max(1, $max)) {
                break;
            }
            $id = (int) $v['id'];
            $clave = (string) ($v['pos_idempotency_key'] ?? '');
            if ($clave !== '') {
                try {
                    if (!$pos->bloquearClave($clave, 0)) {
                        continue;   // su venta sigue en curso (tiene el candado): no cuenta como revisada
                    }
                } catch (Throwable $e) {
                    error_log('[pos] pendiente ' . $id . ': no se pudo pedir el candado de la venta: ' . get_class($e) . ': ' . $e->getMessage());
                    continue;
                }
            }
            $revisadas++;
            try {
                if (empty($v['e_ncf'])) {
                    error_log('[pos] pendiente ' . $id . ' sin e-NCF: no se puede reenviar');
                    continue;
                }
                $esRfce = !empty($v['rfce_xml']);
                $servicio = self::servicioEmision();
                [$estado, $respuesta, $trackId] = $esRfce
                    ? self::revisarRFCE($servicio, $v)
                    : self::revisarECF($pos, $servicio, $v);
                $prefijo = $esRfce ? 'RFCE_' : '';
                // El estado que la fila tiene de verdad: revisarECF actualiza $v['estado_dgii'] cuando deja la
                // fila en ENVIADO antes de consultar, y el compare-and-set del veredicto espera ese.
                $leido = (string) $v['estado_dgii'];
                $marcar = static function (string $final) use ($pos, $id, $esRfce, $leido, $respuesta, $trackId): bool {
                    $escrito = $esRfce
                        ? $pos->marcarEnvio($id, $leido, $final, $respuesta)
                        : $pos->marcarEnvioECF($id, $leido, $final, $respuesta, $trackId);
                    if (!$escrito) {
                        error_log('[pos] pendiente ' . $id . ': otro proceso ya cambio su estado; no se pisa con ' . $final);
                    }
                    return $escrito;
                };
                if (in_array($estado, ['ACEPTADO', 'ACEPTADO_CONDICIONAL'], true)) {
                    if ($marcar($prefijo . $estado)) {
                        $aceptadas++;
                        self::audit('POS_VENTA_ENVIADA', $v['e_ncf'], ['factura_id' => $id, 'estado_dgii' => $prefijo . $estado],
                            'Venta pendiente del POS aceptada por la DGII.');
                    }
                } elseif ($estado === 'RECHAZADO') {
                    if ($marcar($prefijo . 'RECHAZADO')) {
                        $motivo = self::motivoRechazo($respuesta);
                        $rechazadas[] = ['factura_id' => $id, 'e_ncf' => $v['e_ncf'], 'motivo' => $motivo];
                        self::audit('POS_VENTA_RECHAZADA', $v['e_ncf'], ['factura_id' => $id, 'motivo' => $motivo],
                            'La DGII rechazó una venta del POS que ya se había entregado (estaba pendiente).', false);
                    }
                }
                // EN_PROCESO, sin veredicto o sin saber si llego: sigue pendiente.
            } catch (Throwable $e) {
                error_log('[pos] reenvio de la pendiente ' . $id . ': ' . get_class($e) . ': ' . $e->getMessage());
            } finally {
                if ($clave !== '') {
                    try {
                        $pos->liberarClave($clave);
                    } catch (Throwable $e) {
                        // La conexion se cierra al terminar el request y el candado se va con ella.
                    }
                }
            }
        }
        return [
            'revisadas' => $revisadas,
            'aceptadas' => $aceptadas,
            'rechazadas' => $rechazadas,
            'pendientes' => $pos->contarPendientes(),
        ];
    }

    /**
     * Las ventas que el reenvio puede tocar ahora, las mas viejas primero (hasta REENVIO_CANDIDATAS de cada
     * grupo; reenviarPendientes se queda con las primeras $max cuyo candado este libre). Salta:
     *  - PENDIENTE_ENVIO con menos de REENVIO_ESPERA_SEG segundos desde su reserva (meta 'desde'): la peticion
     *    que la guardo sigue enviando. Sin 'desde' en la meta no hay como saber que es reciente: se revisa.
     *    ENVIO_INCIERTO y los demas estados no se saltan: su envio ya termino.
     *  - sin_rango (numero fuera de rango autorizado) mientras ningun rango registrado y vigente cubra su
     *    numero (ncfModel::rangoCubre): la DGII lo rechazaria. La meta dice con que ambiente se numero.
     *
     * @return list<array>
     */
    private static function pendientesAReenviar(posModel $pos): array
    {
        $ahora = time();
        $elegibles = [];
        foreach ($pos->ventasPendientes(self::REENVIO_CANDIDATAS, false) as $v) {
            if ((string) $v['estado_dgii'] === EstadosEcf::PENDIENTE_ENVIO) {
                $desde = strtotime((string) (facturaModel::metaEnvio($v['respuesta_dgii'] ?? null)['desde'] ?? ''));
                if ($desde !== false && $ahora - $desde < self::REENVIO_ESPERA_SEG) {
                    continue;
                }
            }
            $elegibles[] = $v;
        }
        $ncf = null;
        foreach ($pos->ventasPendientes(self::REENVIO_CANDIDATAS, true) as $v) {
            $meta = facturaModel::metaEnvio($v['respuesta_dgii'] ?? null);
            $ambiente = (string) ($meta['ambiente_secuencia'] ?? $v['ambiente_dgii'] ?? '');
            $ncf ??= new ncfModel();
            if ($ambiente !== '' && $ncf->rangoCubre('E' . $v['tipo_ecf'], $ambiente, (int) substr((string) $v['e_ncf'], 3))) {
                $elegibles[] = $v;
            }
        }
        usort($elegibles, static fn(array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);
        return $elegibles;
    }

    /**
     * RFCE pendiente: consulta por e-NCF + codigo de seguridad; si la DGII no lo tiene, reenvia el RFCE firmado
     * que se guardo.
     * @return array{0: ?string, 1: mixed, 2: null} [estado sin prefijo, respuesta, trackId]
     */
    private static function revisarRFCE(ECFEmissionService $servicio, array $v): array
    {
        if (empty($v['codigo_seguridad'])) {
            error_log('[pos] pendiente ' . $v['id'] . ' sin codigo de seguridad: no se puede consultar');
            return [null, null, null];
        }
        $consulta = $servicio->consultarEstadoRFCE((string) $v['e_ncf'], (string) $v['codigo_seguridad'], $v['ambiente_dgii'] ?? null);
        $estado = self::estadoDeConsulta($consulta['data'] ?? null);
        $respuesta = $consulta['data'] ?? null;
        if ($estado === 'NO_ENCONTRADO') {
            if (empty($v['rfce_xml'])) {
                error_log('[pos] pendiente ' . $v['id'] . ' sin RFCE firmado guardado: no se puede reenviar');
                return [null, null, null];
            }
            $envio = $servicio->reenviarRFCE((string) $v['rfce_xml'], $v['ambiente_dgii'] ?? null, self::timeoutDgii());
            return [preg_replace('/^RFCE_/', '', (string) $envio['estado']), $envio['response'], null];
        }
        return [$estado, $respuesta, null];
    }

    /**
     * e-CF completo (E31, o E32 desde 250k) pendiente. Con trackId: se pregunta el resultado
     * (ConsultaResultado). Sin trackId (no hubo respuesta al enviar): primero ConsultaTrackIds; si la DGII lo
     * tiene, se toma su trackId; si responde que no, se reenvia el XML firmado. Si no se puede saber, no se
     * reenvia. Cuando deja la fila en ENVIADO (esperando su veredicto) actualiza $v['estado_dgii'], que es el
     * estado que el compare-and-set del veredicto tiene que esperar.
     *
     * @return array{0: ?string, 1: mixed, 2: ?string} [estado, respuesta, trackId]
     */
    private static function revisarECF(posModel $pos, ECFEmissionService $servicio, array &$v): array
    {
        $ambiente = $v['ambiente_dgii'] ?? null;
        $trackId = !empty($v['track_id']) ? (string) $v['track_id'] : null;
        if ($trackId === null) {
            $tracks = $servicio->consultarTrackIds((string) $v['e_ncf'], $ambiente);
            if ($tracks === null) {
                return [null, null, null];
            }
            if ($tracks === []) {
                if (empty($v['xml_firmado'])) {
                    error_log('[pos] pendiente ' . $v['id'] . ' sin XML firmado guardado: no se puede reenviar');
                    return [null, null, null];
                }
                $envio = $servicio->reenviarECF((string) $v['xml_firmado'], $ambiente, self::timeoutDgii());
                $trackId = $envio['track_id'];
                if (in_array($envio['estado'], ['ACEPTADO', 'ACEPTADO_CONDICIONAL', 'RECHAZADO'], true) || $trackId === null) {
                    return [$envio['estado'], $envio['response'], $trackId];
                }
            } else {
                $trackId = $tracks[count($tracks) - 1]['trackId'];
            }
            // Ya esta en la DGII: queda esperando su veredicto, con su trackId. Compare-and-set: si otro
            // proceso ya movio la fila, no se sigue con ella.
            if (!$pos->marcarEnvioECF((int) $v['id'], (string) $v['estado_dgii'], 'ENVIADO', null, $trackId)) {
                error_log('[pos] pendiente ' . $v['id'] . ': otro proceso ya cambio su estado; no se pisa con ENVIADO');
                return [null, null, null];
            }
            $v['estado_dgii'] = 'ENVIADO';
        }
        $consulta = $servicio->consultarEstado($trackId, (string) $v['e_ncf'], $ambiente);
        return [self::estadoDeConsulta($consulta['data'] ?? null), $consulta['data'] ?? null, $trackId];
    }

    /** Estado de una consulta DGII ({estado: "Aceptado"} o {codigo: 1}) en mayusculas, o null. */
    private static function estadoDeConsulta($data): ?string
    {
        if (!is_array($data)) {
            return null;
        }
        $texto = is_string($data['estado'] ?? null) ? strtolower(trim($data['estado'])) : '';
        if ($texto !== '') {
            if (str_contains($texto, 'rechaz')) return 'RECHAZADO';
            if (str_contains($texto, 'condicion')) return 'ACEPTADO_CONDICIONAL';
            if (str_contains($texto, 'acept')) return 'ACEPTADO';
            if (str_contains($texto, 'proceso')) return 'EN_PROCESO';
            if (str_contains($texto, 'no encontrado')) return 'NO_ENCONTRADO';
        }
        $codigo = $data['codigo'] ?? null;
        if (is_numeric($codigo)) {
            return [0 => 'NO_ENCONTRADO', 1 => 'ACEPTADO', 2 => 'RECHAZADO', 3 => 'EN_PROCESO', 4 => 'ACEPTADO_CONDICIONAL'][(int) $codigo] ?? null;
        }
        return null;
    }

    /** Motivo legible de un rechazo DGII ({mensajes: [{valor, codigo}]}). */
    public static function motivoRechazo($respuesta): string
    {
        $motivos = [];
        foreach ((is_array($respuesta) ? ($respuesta['mensajes'] ?? []) : []) as $m) {
            $valor = is_array($m) ? trim((string) ($m['valor'] ?? '')) : (is_string($m) ? trim($m) : '');
            if ($valor !== '') {
                $motivos[] = rtrim($valor, '.') . '.';
            }
        }
        return $motivos ? implode(' ', array_slice($motivos, 0, 3)) : 'la DGII no dio el motivo.';
    }

    /** Segundos de espera a la DGII al enviar (POS_DGII_TIMEOUT, 5 por defecto, entre 3 y 30). */
    private static function timeoutDgii(): int
    {
        $v = (int) (getenv('POS_DGII_TIMEOUT') ?: ($_ENV['POS_DGII_TIMEOUT'] ?? 5));
        return max(3, min(30, $v ?: 5));
    }

    /** Hora del primer articulo (milisegundos del POS) para la metrica de tiempo por venta. */
    private static function iniciadaAt($ms): ?string
    {
        if (!is_int($ms) && !is_float($ms)) {
            return null;
        }
        $seg = (int) floor(((float) $ms) / 1000);
        // Solo si es razonable: de las ultimas 24 horas y no del futuro.
        return ($seg > time() - 86400 && $seg <= time() + 60) ? date('Y-m-d H:i:s', $seg) : null;
    }

    private static function audit(string $accion, $entidad, array $valores, string $descripcion, bool $ok = true): void
    {
        AuditLogger::log([
            'module' => 'pos', 'action' => $accion,
            'entity_type' => 'factura', 'entity_id' => $entidad,
            'new_values' => $valores, 'description' => $descripcion, 'success' => $ok,
        ]);
    }
}
