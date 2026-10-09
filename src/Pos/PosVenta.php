<?php
require_once __DIR__ . '/PosError.php';
require_once __DIR__ . '/PosPrecio.php';
require_once __DIR__ . '/../Models/posModel.php';
require_once __DIR__ . '/../Models/facturaModel.php';
require_once __DIR__ . '/../Models/unidadMedidaModel.php';
require_once __DIR__ . '/../Utils/FacturacionElectronica/ECFEmissionService.php';
require_once __DIR__ . '/../Utils/FacturacionElectronica/EcfItemMapper.php';
require_once __DIR__ . '/../Utils/FacturacionElectronica/EcfUsuarioException.php';

/**
 * Cobro de una venta del POS (docs/specs/pos.md §9.5, F1, F5, F6, P1-P3) y
 * reenvio de las que quedaron pendientes (F7).
 *
 * Por ahora solo factura de consumo (E32) a consumidor final y por debajo de
 * RD$250,000, que va por RFCE. El credito fiscal (E31) llega aparte.
 *
 * Reglas que no se negocian:
 *  - El navegador no manda precios: las lineas se arman aqui desde product_id
 *    con PosPrecio (el mismo calculo del catalogo). El total que vio el cajero
 *    viaja solo para comparar: si no coincide, no se emite (TOTAL_DISTINTO).
 *  - Una clave por intento de venta (idempotencia): la misma clave nunca saca
 *    otro e-NCF. Un candado de MySQL cubre el doble toque concurrente.
 *  - Factura, movimiento de caja y salida de inventario van juntos; si la DGII
 *    rechaza, la factura queda como historial pero sin dinero ni inventario.
 *  - Sin respuesta de la DGII en POS_DGII_TIMEOUT segundos: la venta queda
 *    firmada y guardada con envio pendiente, se imprime y se reenvia sola
 *    (decision 17; el contador lo valida en Q1).
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

    private const ACEPTADOS = ['RFCE_ACEPTADO', 'RFCE_ACEPTADO_CONDICIONAL'];
    private const RECHAZADOS = ['RFCE_RECHAZADO'];

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
                return self::respuestaDeVentaExistente($pos, $previa, $ancho);
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

        // facturas.user_id es NOT NULL: el empleado del POS no es usuario, asi
        // que la venta queda a nombre del admin que habilito esta caja (y el
        // empleado en pos_empleado_id).
        $userId = (int) ($equipo['habilitado_por'] ?? 0);
        if ($userId <= 0) {
            throw new PosError('No se sabe quién habilitó este equipo. Pide a un administrador que lo habilite de nuevo.', 409, 'EQUIPO_SIN_RESPONSABLE');
        }

        // --- Lineas desde el catalogo -----------------------------------------
        [$items, $totalCentavos] = self::armarLineas($pos, $body['lineas'] ?? null);

        $vistoPorCajero = $body['total_centavos'] ?? null;
        if (!is_int($vistoPorCajero) || $vistoPorCajero !== $totalCentavos) {
            throw new PosError('El total cambió (algún precio se actualizó). Revisa la venta y cobra de nuevo.', 409, 'TOTAL_DISTINTO',
                ['total_centavos' => $totalCentavos]);
        }
        if ($totalCentavos >= self::TOPE_CONSUMIDOR_FINAL_CENTAVOS) {
            throw new PosError('Las ventas de RD$250,000 o más tienen que identificar al comprador con RNC o cédula. Emítela desde FiscalPoint (Facturación).',
                422, 'COMPRADOR_REQUERIDO');
        }

        // --- Forma de pago (P1-P3) ---------------------------------------------
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
            'tipo_ecf' => '32',
            'fecha_emision' => date('d-m-Y'),
            'tipo_ingresos' => '01',
            'tipo_pago' => 1,
            'indicador_monto_gravado' => '1',
            'comprador' => [],
            'items' => $itemsXml,
            'totales' => $totales,
            'formas_pago' => [['forma_pago' => $formaPago, 'monto_pago' => $total]],
            'informacion_referencia' => null,
            'strict_input' => false,
            'dgii_timeout' => self::timeoutDgii(),
            'tolerar_fallo_envio' => true,
        ];

        $auditBase = [
            'pos_empleado_id' => $empleado['id'], 'empleado' => $empleado['nombre'],
            'caja_id' => $caja['id'], 'turno_id' => $turno['id'], 'total' => $total, 'forma_pago' => $formaPago,
        ];
        try {
            $result = (new ECFEmissionService())->emitir($payload);
        } catch (Throwable $e) {
            error_log('[pos] fallo en emision: ' . get_class($e) . ': ' . $e->getMessage());
            self::audit('POS_VENTA_FALLIDA', null, $auditBase, 'No se pudo emitir la venta del POS: ' . $e->getMessage(), false);
            throw new PosError($e instanceof EcfUsuarioException
                ? $e->getMensajeUsuario()
                : 'No se pudo emitir en la DGII. Inténtalo de nuevo en un momento; si sigue fallando, usa el procedimiento de contingencia.',
                502, 'EMISION_FALLIDA');
        }

        $estado = (string) ($result['estado'] ?? '');
        $rechazada = in_array($estado, self::RECHAZADOS, true);
        // Todo lo que no es un veredicto (sin respuesta, en proceso, una
        // respuesta rara) queda pendiente: el reenvio consulta primero.
        $pendiente = !$rechazada && !in_array($estado, self::ACEPTADOS, true);
        $iniciadaAt = self::iniciadaAt($body['iniciada_ms'] ?? null);

        $facturaInput = [
            'no_factura' => $result['e_ncf'],
            'date' => date('Y-m-d H:i:s'),
            'client_id' => null,
            'client_name' => 'Consumidor Final',
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
                'envio_pendiente' => $pendiente,
            ],
        ];
        // El dinero entra a la caja en la misma transaccion que la factura.
        $movimiento = $rechazada ? null : static function (int $facturaId) use ($pos, $turno, $formaPago, $total, $recibidoCentavos, $devueltaCentavos, $iniciadaAt): void {
            $pos->registrarMovimiento($turno['id'], $facturaId, 'VENTA', $formaPago, $total,
                $recibidoCentavos !== null ? $recibidoCentavos / 100 : null,
                $devueltaCentavos !== null ? $devueltaCentavos / 100 : null,
                $iniciadaAt);
        };
        $facturas = new facturaModel();
        $saved = $facturas->saveFacturaConECF($facturaInput, $result, $movimiento);
        if ($saved[0] !== 'success') {
            self::audit('POS_VENTA_SIN_GUARDAR', $result['e_ncf'] ?? null, $auditBase + ['estado_dgii' => $estado],
                'Venta del POS emitida pero no guardada: ' . ($saved[2] ?? $saved[1]), false);
            throw new PosError($saved[1], 500, 'GUARDADO_FALLIDO', ['e_ncf' => $result['e_ncf'] ?? null]);
        }
        $facturaId = (int) $saved[1]['factura_id'];

        if ($rechazada) {
            $motivo = self::motivoRechazo($result['rfce_response'] ?? $result['dgii_response'] ?? null);
            self::audit('POS_VENTA_RECHAZADA', $result['e_ncf'], $auditBase + ['factura_id' => $facturaId, 'motivo' => $motivo],
                'La DGII rechazó la venta del POS.', false);
            throw new PosError('La DGII rechazó la venta: ' . $motivo . ' No se cobró nada; la venta sigue en pantalla.', 422, 'DGII_RECHAZO',
                ['e_ncf' => $result['e_ncf']]);
        }

        // Inventario: despues de guardar, como en app.*. Un fallo aqui no tumba
        // una venta que ya existe en la DGII: queda en el log para un ajuste.
        try {
            require_once __DIR__ . '/../Models/inventoryModel.php';
            (new inventoryModel())->registrarVenta($facturaId, $facturaInput['items'], '32', $userId);
        } catch (Throwable $e) {
            error_log('[pos] inventario de la factura ' . $facturaId . ': ' . $e->getMessage());
        }

        self::audit('POS_VENTA', $result['e_ncf'], $auditBase + [
            'factura_id' => $facturaId, 'estado_dgii' => $estado, 'envio_pendiente' => $pendiente,
            'recibido' => $recibidoCentavos !== null ? $recibidoCentavos / 100 : null,
        ], $pendiente ? 'Venta del POS emitida; la DGII no respondió a tiempo y se reenviará.' : 'Venta del POS emitida.');

        return [
            'venta' => [
                'factura_id' => $facturaId,
                'e_ncf' => $result['e_ncf'],
                'tipo_ecf' => '32',
                'estado_dgii' => $estado,
                'envio_pendiente' => $pendiente,
                'total_centavos' => $totalCentavos,
            ],
            'cobro' => self::cobro($formaPago, $totalCentavos, $recibidoCentavos, $devueltaCentavos),
            'recibo' => self::recibo($facturaId, $ancho),
            'repetida' => false,
        ];
    }

    /**
     * Lineas para EcfItemMapper desde el catalogo, y el total en centavos.
     * Cantidades con 2 decimales como maximo, y enteras si la unidad no admite
     * fracciones. Un producto repetido se suma en una sola linea.
     *
     * @return array{0: array, 1: int}
     */
    private static function armarLineas(posModel $pos, $lineas): array
    {
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
            $total += intdiv($centavos * $centesimas + 50, 100);
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
        return [EcfItemMapper::normalizarCantidadPrecio($items), $total];
    }

    /** La venta ya existe con esa clave: se devuelve tal cual (nunca otro e-NCF). */
    private static function respuestaDeVentaExistente(posModel $pos, array $venta, int $ancho): array
    {
        $estado = (string) $venta['estado_dgii'];
        if (in_array($estado, self::RECHAZADOS, true) || str_ends_with($estado, '_ARCHIVADO')) {
            $resp = json_decode((string) ($venta['rfce_respuesta'] ?? $venta['respuesta_dgii'] ?? ''), true);
            throw new PosError('La DGII rechazó esta venta: ' . self::motivoRechazo($resp) . ' No se cobró nada.', 422, 'DGII_RECHAZO',
                ['e_ncf' => $venta['e_ncf']]);
        }
        $facturaId = (int) $venta['id'];
        $totalCentavos = (int) round(((float) $venta['total']) * 100);
        $mov = $pos->movimientoDeFactura($facturaId);
        $forma = (int) ($mov['forma_pago'] ?? 1);
        $recibido = isset($mov['monto_recibido']) ? (int) round(((float) $mov['monto_recibido']) * 100) : null;
        $devuelta = isset($mov['devuelta']) ? (int) round(((float) $mov['devuelta']) * 100) : null;
        return [
            'venta' => [
                'factura_id' => $facturaId,
                'e_ncf' => $venta['e_ncf'],
                'tipo_ecf' => (string) $venta['tipo_ecf'],
                'estado_dgii' => $estado,
                'envio_pendiente' => (int) $venta['envio_pendiente'] === 1,
                'total_centavos' => $totalCentavos,
            ],
            'cobro' => self::cobro($forma, $totalCentavos, $recibido, $devuelta),
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
            $nombre = 'Factura_' . ($factura['e_ncf'] ?? $facturaId) . RepresentacionImpresa::sufijo($ancho);
            return RepresentacionImpresa::datosRecibo($factura, [], false, $ancho, $nombre);
        } catch (Throwable $e) {
            error_log('[pos] recibo de la factura ' . $facturaId . ': ' . get_class($e) . ': ' . $e->getMessage());
            return null;
        }
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
     * Reenvia hasta $max ventas pendientes. Antes de reenviar CONSULTA a la DGII
     * por e-NCF + codigo de seguridad: si ya la tenia (el timeout fue de la
     * respuesta, no del envio), solo se actualiza el estado. Una que siga sin
     * respuesta queda pendiente para la proxima vuelta.
     *
     * @return array{revisadas: int, aceptadas: int, rechazadas: array, pendientes: int}
     */
    public static function reenviarPendientes(posModel $pos, int $max = 5): array
    {
        $aceptadas = 0;
        $rechazadas = [];
        $revisadas = 0;
        foreach ($pos->ventasPendientes($max) as $v) {
            $revisadas++;
            $id = (int) $v['id'];
            if (empty($v['e_ncf']) || empty($v['codigo_seguridad'])) {
                error_log('[pos] pendiente ' . $id . ' sin e-NCF o codigo de seguridad: no se puede reenviar');
                continue;
            }
            try {
                $servicio = new ECFEmissionService();
                $consulta = $servicio->consultarEstadoRFCE((string) $v['e_ncf'], (string) $v['codigo_seguridad'], $v['ambiente_dgii'] ?? null);
                $estado = self::estadoDeConsulta($consulta['data'] ?? null);
                $respuesta = $consulta['data'] ?? null;
                if ($estado === 'NO_ENCONTRADO') {
                    if (empty($v['rfce_xml'])) {
                        error_log('[pos] pendiente ' . $id . ' sin RFCE firmado guardado: no se puede reenviar');
                        continue;
                    }
                    $envio = $servicio->reenviarRFCE((string) $v['rfce_xml'], $v['ambiente_dgii'] ?? null, self::timeoutDgii());
                    $estado = preg_replace('/^RFCE_/', '', (string) $envio['estado']);
                    $respuesta = $envio['response'];
                }
                if (in_array($estado, ['ACEPTADO', 'ACEPTADO_CONDICIONAL'], true)) {
                    $pos->marcarEnvio($id, 'RFCE_' . $estado, $respuesta, false);
                    $aceptadas++;
                    self::audit('POS_VENTA_ENVIADA', $v['e_ncf'], ['factura_id' => $id, 'estado_dgii' => 'RFCE_' . $estado],
                        'Venta pendiente del POS aceptada por la DGII.');
                } elseif ($estado === 'RECHAZADO') {
                    $pos->marcarEnvio($id, 'RFCE_RECHAZADO', $respuesta, false);
                    $motivo = self::motivoRechazo($respuesta);
                    $rechazadas[] = ['factura_id' => $id, 'e_ncf' => $v['e_ncf'], 'motivo' => $motivo];
                    self::audit('POS_VENTA_RECHAZADA', $v['e_ncf'], ['factura_id' => $id, 'motivo' => $motivo],
                        'La DGII rechazó una venta del POS que ya se había entregado (estaba pendiente).', false);
                }
                // EN_PROCESO, sin veredicto: sigue pendiente.
            } catch (Throwable $e) {
                error_log('[pos] reenvio de la pendiente ' . $id . ': ' . get_class($e) . ': ' . $e->getMessage());
            }
        }
        return [
            'revisadas' => $revisadas,
            'aceptadas' => $aceptadas,
            'rechazadas' => $rechazadas,
            'pendientes' => $pos->contarPendientes(),
        ];
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
