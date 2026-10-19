<?php
require_once(__DIR__ . '/../Database.php');

/**
 * inventoryModel — libro de movimientos de inventario.
 *
 * Toda variacion de `products.stock` pasa por aqui: hoy los ajustes, y la venta
 * al facturar. Nada mas debe tocar esa columna a mano, o el ledger deja de
 * cuadrar con el saldo.
 *
 * El metodo central es aplicarMovimientos(): bloquea las filas de producto,
 * escribe el movimiento con su foto (saldo antes / despues) y actualiza el
 * saldo, todo en una transaccion. Los ajustes y las facturas lo comparten.
 */
class inventoryModel
{
    private $conexion;

    /**
     * Motivos validos de un ajuste. ANULACION la pone el sistema, no el usuario.
     * ENTRADA (mercancia que llega) e INVENTARIO_INICIAL (la existencia con que
     * se abre un producto) son los dos que SUMAN con un nombre honesto: antes
     * habia que escoger DEVOLUCION u OTRO para una compra o una apertura.
     * inventory_adjustments.motivo es VARCHAR(20): no hace falta migracion.
     */
    public const MOTIVOS = [
        'CONTEO_FISICO', 'MERMA', 'DANO', 'ROBO',
        'DEVOLUCION', 'ERROR_CAPTURA', 'ANULACION', 'OTRO',
        'ENTRADA', 'INVENTARIO_INICIAL',
    ];

    /**
     * Tope de lineas por ajuste. Cada linea toma un SELECT ... FOR UPDATE dentro
     * de la misma transaccion, asi que un ajuste sin limite puede dejar en espera
     * a toda la facturacion hasta agotar el lock wait timeout.
     */
    public const MAX_LINEAS = 500;

    /**
     * Codigo del error de existencia insuficiente (spec 4.4): viaja en el JSON
     * como `codigo` y el front lo lee para armar el aviso con su boton a Ajuste.
     */
    public const CODIGO_STOCK_INSUFICIENTE = 'STOCK_INSUFICIENTE';

    /** Lo que ve quien guarda cuando el libro no se pudo actualizar (el detalle va al log). */
    private const FALLO_EXISTENCIAS = 'No se pudieron actualizar las existencias. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.';

    /**
     * @param PDO|null $conexion La conexion a usar. Sin ella, la de siempre
     *     (Database::getInstance()). La da quien ya tiene una transaccion
     *     abierta en SU conexion y necesita que el inventario corra en esa misma:
     *     un `new inventoryModel()` tardio puede abrir otra (el singleton se
     *     reinicia al resolver la empresa), y en esa el bloqueo se suelta de una
     *     vez y un rollback del documento no desharia el descuento (spec C20).
     */
    public function __construct(?PDO $conexion = null)
    {
        $this->conexion = $conexion ?? Database::getInstance()->getConnection();
    }

    // ------------------------------------------------------------------
    // Nucleo: aplicar movimientos al inventario
    // ------------------------------------------------------------------

    /**
     * Escribe N movimientos y actualiza el saldo de cada producto.
     *
     * @param array $lineas  [['product_id'=>int, 'cantidad'=>float (con signo,
     *                         hasta 3 decimales), 'costo_unitario'=>float|null], ...]
     * @param array $ctx     tipo_movimiento, referencia_tipo, referencia_id,
     *                       warehouse_id (opcional: si falta usa el del producto),
     *                       user_id,
     *                       bloquear_sin_existencia (opcional, false por defecto):
     *                       con true, una linea de SALIDA (cantidad < 0) que dejaria
     *                       el saldo bloqueado por debajo de 0 no se escribe y se
     *                       devuelve el error de existencia. Sin la clave, el
     *                       saldo negativo se tolera como siempre.
     * @param bool  $ownTransaction  false cuando el llamador ya abrio una. Entonces
     *                       esto NO abre, confirma ni deshace nada: si devuelve un
     *                       error, el rollback es de quien abrio la transaccion.
     * @return array ['success', [movimientos]]
     *               | ['error', mensaje]
     *               | ['error', mensaje, 422, ['codigo' => 'STOCK_INSUFICIENTE',
     *                 'faltantes' => [una fila]]]   solo con bloquear_sin_existencia
     */
    public function aplicarMovimientos(array $lineas, array $ctx, bool $ownTransaction = true): array
    {
        // Con (int) una salida de 0,5 kg se descartaba aqui en silencio. Se
        // compara ya redondeada a 3 decimales (lo que guarda la columna): lo que
        // queda en 0 no es un movimiento.
        $lineas = array_values(array_filter($lineas, static function ($l) {
            return !empty($l['product_id']) && abs(round((float) ($l['cantidad'] ?? 0), 3)) > 0;
        }));
        if ($lineas === []) {
            return ['success', []];
        }

        try {
            if ($ownTransaction) {
                $this->conexion->beginTransaction();
            }

            $bloquear = !empty($ctx['bloquear_sin_existencia']);

            // FOR UPDATE: dos usuarios ajustando el mismo producto a la vez leerian
            // el mismo saldo y el segundo pisaria al primero. El bloqueo los serializa.
            // nombre, sku y unidad_medida solo sirven para el mensaje cuando se
            // rechaza por existencia: la fila ya esta bloqueada, no cuesta otra consulta.
            $lock = $this->conexion->prepare(
                'SELECT id, nombre, sku, unidad_medida, stock, costo, warehouse_id FROM products WHERE id = :id FOR UPDATE'
            );
            $insert = $this->conexion->prepare(
                'INSERT INTO inventory_movements
                    (product_id, warehouse_id, tipo_movimiento, referencia_tipo, referencia_id,
                     cantidad, cantidad_anterior, cantidad_nueva, costo_unitario, valor_movimiento,
                     user_id, created_at)
                 VALUES
                    (:product_id, :warehouse_id, :tipo, :ref_tipo, :ref_id,
                     :cantidad, :anterior, :nueva, :costo, :valor, :user_id, NOW())'
            );
            $updStock = $this->conexion->prepare(
                'UPDATE products SET stock = :nueva WHERE id = :id'
            );

            // Orden del bloqueo: por product_id, sin importar el orden de las
            // lineas. Dos documentos que llevan los mismos productos en orden
            // distinto (A,B contra B,A) se bloqueaban cruzados y MySQL abortaba a
            // uno por deadlock, que aqui se tragaba como "venta sin movimiento".
            // Con todos bloqueando de menor a mayor id, uno espera al otro.
            // Solo se ordena el BLOQUEO: los movimientos se escriben (mas abajo)
            // en el orden en que llegaron las lineas, asi el libro y el detalle de
            // un ajuste quedan como los capturo quien los hizo.
            $ids = array_values(array_unique(array_map(static fn($l) => (int) $l['product_id'], $lineas)));
            sort($ids);
            $filas = [];
            $inicial = [];
            foreach ($ids as $productId) {
                $lock->execute([':id' => $productId]);
                $prod = $lock->fetch(PDO::FETCH_ASSOC);
                if (!$prod) {
                    if ($ownTransaction) {
                        $this->conexion->rollBack();
                    }
                    // El id sirve para rastrearlo, no a quien ajusta: va al log y el
                    // texto (que tambien sirve para ventas y compras) queda llano.
                    error_log('[inventario] ' . ($ctx['referencia_tipo'] ?? 'movimiento') . ' '
                        . ($ctx['referencia_id'] ?? '-') . ': el producto ' . $productId . ' no existe');
                    return ['error', 'Uno de los productos ya no existe en el catálogo; puede que otra persona lo haya eliminado. '
                        . 'Quítalo y vuelve a intentarlo.'];
                }
                $filas[$productId] = $prod;
                $inicial[$productId] = round((float) ($prod['stock'] ?? 0), 3);
            }
            // Saldo corriente por producto: lo que leeria el siguiente SELECT ...
            // FOR UPDATE de la misma transaccion. Un producto en dos lineas parte,
            // en la segunda, del saldo que dejo la primera.
            $saldos = $inicial;

            $creados = [];
            foreach ($lineas as $l) {
                $productId = (int) $l['product_id'];
                $prod = $filas[$productId];

                // Stock y libro son DECIMAL(15,3) (migracion 025): 1,5 m de cable
                // mueven 1,5, no 2. MySQL devuelve el DECIMAL como texto
                // ("10.500"), de ahi el (float). Todo se redondea a 3 para que la
                // foto guardada (antes / movimiento / despues) sea exactamente lo
                // que la columna conserva y 10,1 - 0,3 no quede en 9,7999999.
                $cantidad = round((float) $l['cantidad'], 3);
                $anterior = $saldos[$productId];
                $nueva = round($anterior + $cantidad, 3);
                // Con el interruptor de la empresa apagado (tenants.inventario_estricto
                // = 0, lo normal) el saldo negativo se tolera: la venta ya ocurrio y
                // el comprobante puede estar emitido. Se registra y queda visible en
                // el reporte para que lo corrijan con un ajuste. Con el interruptor
                // encendido la venta se rechaza ANTES de guardarse
                // (verificarDisponibilidad); y la que se guarda dentro de su propia
                // transaccion (factura simple) pide ademas bloquear_sin_existencia,
                // que aqui, ya con la fila bloqueada, es la palabra final: la
                // verificacion previa lee sin bloquear y otra venta pudo llevarse la
                // existencia entre su lectura y este bloqueo.
                if ($bloquear && $cantidad < 0 && self::milesimas($nueva) < 0) {
                    if ($ownTransaction) {
                        $this->conexion->rollBack();
                    }
                    // Lo que haria falta contra el saldo con que EMPEZO la llamada
                    // (no contra el que dejo una linea anterior del mismo producto):
                    // dos lineas de 3 contra 4 piden 6, no 3, para que el ajuste
                    // sugerido lo lleve hasta lo que se vende.
                    $fila = self::filaFaltante(
                        $productId,
                        $prod,
                        self::milesimas($inicial[$productId] - $nueva),
                        self::milesimas($inicial[$productId])
                    );
                    return ['error', self::mensajeFaltantes([$fila]), 422, [
                        'codigo' => self::CODIGO_STOCK_INSUFICIENTE,
                        'faltantes' => [$fila],
                    ]];
                }
                $costo = isset($l['costo_unitario']) && $l['costo_unitario'] !== null
                    ? round((float) $l['costo_unitario'], 2)
                    : round((float) ($prod['costo'] ?? 0), 2);
                $warehouseId = (int) ($ctx['warehouse_id'] ?? $prod['warehouse_id']);
                // Un solo calculo: la fila guardada y el total que suma el ajuste
                // tienen que salir de la misma expresion o dejan de cuadrar.
                $valor = round($cantidad * $costo, 2);

                $insert->execute([
                    ':product_id' => $productId,
                    ':warehouse_id' => $warehouseId,
                    ':tipo' => (string) ($ctx['tipo_movimiento'] ?? 'AJUSTE'),
                    ':ref_tipo' => $ctx['referencia_tipo'] ?? null,
                    ':ref_id' => $ctx['referencia_id'] ?? null,
                    ':cantidad' => $cantidad,
                    ':anterior' => $anterior,
                    ':nueva' => $nueva,
                    ':costo' => $costo,
                    ':valor' => $valor,
                    ':user_id' => $ctx['user_id'] ?? null,
                ]);
                $updStock->execute([':nueva' => $nueva, ':id' => $productId]);
                $saldos[$productId] = $nueva;

                $creados[] = [
                    'id' => (int) $this->conexion->lastInsertId(),
                    'product_id' => $productId,
                    'cantidad' => $cantidad,
                    'cantidad_anterior' => $anterior,
                    'cantidad_nueva' => $nueva,
                    'costo_unitario' => $costo,
                    'valor_movimiento' => $valor,
                ];
            }

            if ($ownTransaction) {
                $this->conexion->commit();
            }
            return ['success', $creados];
        } catch (Throwable $e) {
            // Throwable y no PDOException: un TypeError por una linea malformada
            // escapaba con la transaccion abierta y sin rollback.
            if ($ownTransaction && $this->conexion->inTransaction()) {
                $this->conexion->rollBack();
            }
            // El detalle tecnico al log; quien ajusta solo necesita saber que no
            // se guardo y que hacer.
            error_log('[inventario] aplicarMovimientos ' . ($ctx['referencia_tipo'] ?? 'movimiento') . ' '
                . ($ctx['referencia_id'] ?? '-') . ': ' . $e->getMessage());
            return ['error', self::FALLO_EXISTENCIAS];
        }
    }

    /**
     * Descuenta (o repone) el inventario de una factura ya guardada.
     *
     * Reglas:
     *  - Solo mueven inventario las lineas con `product_id` (las libres —"mano de
     *    obra", "flete"— no son del catalogo) y de tipo BIEN. Un servicio no
     *    tiene existencias.
     *  - E34 (Nota de Credito) es una devolucion: SUMA. Todo lo demas RESTA.
     *  - NUNCA falla la factura. Se llama despues de guardar y, en e-CF, despues
     *    de que DGII acepto: a esas alturas el comprobante ya existe y no se
     *    puede deshacer. Si el inventario falla se registra en el log y se
     *    corrige con un ajuste.
     *  - Con el interruptor de la empresa apagado (lo normal) se permite saldo
     *    negativo: bloquear una venta por un stock que hasta hoy nadie mantenia
     *    seria peor que registrarlo y que lo vean en el reporte. Con el
     *    interruptor encendido, verificarDisponibilidad rechaza la venta antes de
     *    guardarla; esto sigue sin bloquear porque corre despues de guardar.
     *
     * @param array $items lineas ya persistidas (product_id, quantity, indicador_bien_servicio)
     * @return int cuantos movimientos se registraron
     */
    public function registrarVenta(int $facturaId, array $items, ?string $tipoEcf, ?int $userId = null): int
    {
        // Que comprobantes ENTRAN mercancia en vez de sacarla: ver esEntrada().
        $entrada = self::esEntrada($tipoEcf);

        return $this->moverPorFactura(
            $facturaId,
            $items,
            $entrada ? 1 : -1,
            (string) $tipoEcf === '41' ? 'COMPRA' : ($entrada ? 'DEVOLUCION' : 'VENTA'),
            $userId
        );
    }

    /**
     * ¿Este comprobante ENTRA mercancia en vez de sacarla?
     *   E34 Nota de Credito -> devolucion del cliente, vuelve al almacen.
     *   E41 Compras         -> comprobante que el emisor levanta por una compra a
     *                          un proveedor no registrado: la mercancia entra, no
     *                          sale.
     * Es la unica prueba de "entrada": la usan lo que se descuenta
     * (registrarVenta) y lo que se revisa (verificarDisponibilidad), para que
     * "bloquea" y "descuenta" nunca se separen.
     *
     * @param string|null $tipoEcf '31'..'47'; null = factura simple (sale)
     */
    public static function esEntrada(?string $tipoEcf): bool
    {
        return in_array((string) $tipoEcf, ['34', '41'], true);
    }

    /**
     * Devuelve al almacen la mercancia de una factura que se borra o cuyas
     * lineas se reemplazan. Es el inverso exacto de registrarVenta: mismas
     * reglas de bien/servicio y la misma cantidad (con sus decimales), signo
     * opuesto.
     *
     * Una factura simple SI se puede editar y borrar (a diferencia del e-CF, que
     * se corrige con una nota de credito E34). Sin esta reversa el descuento de
     * la venta quedaba aplicado para siempre y products.stock derivaba hacia
     * abajo en cada borrado.
     *
     * @param array $items lineas tal como estaban guardadas antes del cambio
     * @return int cuantos movimientos se registraron
     */
    public function revertirVenta(int $facturaId, array $items, ?int $userId = null): int
    {
        return $this->moverPorFactura($facturaId, $items, 1, 'DEVOLUCION', $userId);
    }

    /**
     * La venta de una factura simple CON el interruptor de la empresa encendido
     * (spec C2): revisar y descontar dentro de la transaccion de la propia factura,
     * de modo que si falta existencia la factura no se guarda, y si el documento se
     * deshace el descuento se deshace con el.
     *
     * Debe correr DENTRO de la transaccion que el llamador tiene abierta, y en SU
     * conexion (por eso el modelo se arma con ella: constructor con ?PDO, C20).
     * Nunca abre, confirma ni deshace una transaccion: si devuelve un error, el
     * rollback es del llamador. Sin una transaccion abierta no mueve nada y
     * devuelve error: cada sentencia suelta se confirmaria sola, el bloqueo se
     * soltaria de una vez y un rollback posterior no desharia el descuento.
     *
     * Pasos, en este orden:
     *  0. Si hay lineas viejas que devolver Y lineas nuevas que vender (un PUT que
     *     reemplaza las lineas), se bloquea la union de sus productos de menor a
     *     mayor product_id en una sola pasada (bloquearProductosEnOrden), para que
     *     el orden de bloqueo sea ascendente en TODA la transaccion y no solo
     *     dentro de cada pasada de aplicarMovimientos.
     *  1. Si hay lineas viejas (la factura se esta editando), se devuelven al
     *     almacen (DEVOLUCION). Asi la revision que sigue ya cuenta lo que la
     *     factura tenia: re-guardar con la misma cantidad y existencia 0 pasa, y
     *     subir de 3 a 5 con existencia 1 falta 1, no 4 (spec C13).
     *  2. verificarDisponibilidad: solo lee, y devuelve el error con faltantes.
     *  3. Se descuenta (VENTA) con bloquear_sin_existencia: la revision del paso 2
     *     no bloquea, y esta es la palabra final con la fila bloqueada si otra venta
     *     se llevo la existencia entretanto.
     * Usa el mismo filtro de lineas que el camino de despues del commit
     * (lineasQueMueve): servicios y lineas sin product_id no mueven ni se revisan.
     *
     * @param array      $itemsNuevos lineas tal como las escribio la factura (product_id, quantity)
     * @param array|null $itemsViejos lineas que la factura tenia guardadas, o null si es nueva
     * @return array ['success', int movimientos registrados (devolucion + venta)]
     *               | ['error', mensaje, 422]                       producto inexistente o cantidad <= 0
     *               | ['error', mensaje, 422, ['codigo' => 'STOCK_INSUFICIENTE', 'faltantes' => [...]]]
     *               | ['error', mensaje]                            fallo al actualizar
     */
    public function moverVentaEnTransaccion(int $facturaId, array $itemsNuevos, ?int $userId, ?array $itemsViejos = null): array
    {
        if (!$this->conexion->inTransaction()) {
            error_log('[inventario] moverVentaEnTransaccion factura ' . $facturaId . ': no hay una transaccion abierta en la conexion');
            return ['error', self::FALLO_EXISTENCIAS];
        }
        try {
            $movimientos = 0;

            // Lo que se devuelve y lo que se vende, con el mismo filtro que usa el
            // camino de despues del commit. Solo lee: no toma ningun bloqueo.
            $devolver = $itemsViejos !== null ? $this->lineasQueMueve($itemsViejos, 1, false) : [];
            $descontar = $this->lineasQueMueve($itemsNuevos, -1, false);

            // Orden global de bloqueo (4.2): el PUT hace DOS pasadas de
            // aplicarMovimientos (la reversa y la venta) y cada una ordena solo sus
            // propios ids. Con lineas viejas del producto 9 y nuevas del 4 se
            // bloqueaba 9 y despues 4, mientras una venta de {4, 9} bloquea 4 y
            // despues 9: se cruzan, MySQL mata a una por interbloqueo y, si la
            // victima es el descuento de un e-CF ya aceptado, ese descuento se
            // pierde (moverPorDocumento lo anota y no lo reintenta). Por eso la UNION
            // de ids viejos y nuevos se bloquea ANTES, de menor a mayor y de una vez:
            // las dos pasadas siguientes solo vuelven a bloquear filas que esta
            // transaccion ya tiene. Con una sola pasada (factura nueva, o lineas que
            // no mueven nada) aplicarMovimientos ya ordena por si mismo.
            if ($devolver !== [] && $descontar !== []) {
                $this->bloquearProductosEnOrden(array_merge($devolver, $descontar));
            }

            if ($devolver !== []) {
                $res = $this->aplicarMovimientos($devolver, [
                    'tipo_movimiento' => 'DEVOLUCION',
                    'referencia_tipo' => 'factura',
                    'referencia_id' => $facturaId,
                    'user_id' => $userId,
                ], false);
                if ($res[0] !== 'success') {
                    return $res;
                }
                $movimientos += count($res[1]);
            }

            $revision = $this->verificarDisponibilidad($itemsNuevos, null);
            if ($revision[0] !== 'success') {
                return $revision;
            }

            if ($descontar !== []) {
                $res = $this->aplicarMovimientos($descontar, [
                    'tipo_movimiento' => 'VENTA',
                    'referencia_tipo' => 'factura',
                    'referencia_id' => $facturaId,
                    'user_id' => $userId,
                    'bloquear_sin_existencia' => true,
                ], false);
                if ($res[0] !== 'success') {
                    return $res;
                }
                $movimientos += count($res[1]);
            }
            return ['success', $movimientos];
        } catch (Throwable $e) {
            // Una venta que no se pudo descontar no se guarda: se devuelve el error
            // para que el llamador deshaga la factura (aqui no se traga como en el
            // camino de despues del commit, donde el documento ya existe).
            error_log('[inventario] moverVentaEnTransaccion factura ' . $facturaId . ': ' . $e->getMessage());
            return ['error', self::FALLO_EXISTENCIAS];
        }
    }

    /**
     * Bloquea (SELECT ... FOR UPDATE) las filas de producto de estas lineas, cada
     * una una sola vez y de menor a mayor product_id: el mismo orden con que
     * aplicarMovimientos bloquea, para que todos los que toman filas de producto
     * las tomen en el mismo sentido y uno espere al otro en vez de cruzarse.
     *
     * Debe correr DENTRO de la transaccion que el llamador tiene abierta: nunca la
     * abre, confirma ni deshace (el bloqueo se mantiene hasta que ella termine). Un
     * id que ya no existe no se reporta aqui (no hay fila que bloquear): lo reporta
     * aplicarMovimientos, con su mensaje de siempre. Una excepcion de la base sale
     * tal cual.
     *
     * @param array $lineas lineas con 'product_id' (las de lineasQueMueve)
     */
    private function bloquearProductosEnOrden(array $lineas): void
    {
        $ids = array_values(array_unique(array_map(static fn($l) => (int) $l['product_id'], $lineas)));
        sort($ids);
        $lock = $this->conexion->prepare('SELECT id FROM products WHERE id = :id FOR UPDATE');
        foreach ($ids as $productId) {
            $lock->execute([':id' => $productId]);
            // Se lee la fila para dejar la sentencia lista para la siguiente vuelta.
            $lock->fetch(PDO::FETCH_ASSOC);
        }
    }

    /**
     * true si el documento ya tiene algun movimiento de inventario (por
     * ejemplo referencia_tipo 'factura' y su id). Lo usa la emision e-CF y la
     * consulta de estado para mover el stock de una factura UNA sola vez,
     * llegue primero quien llegue (spec 4.2.4 y 10.5).
     *
     * Quien llama lo pregunta ANTES de registrarVenta. registrarVenta no lo
     * revisa a proposito: la factura simple llama revertirVenta y despues
     * registrarVenta sobre el mismo id cuando reemplaza sus lineas, y eso
     * tiene que seguir moviendo.
     *
     * Una PDOException sale tal cual: el que llama la atrapa y NO mueve el
     * stock (mejor un movimiento de menos, que se corrige con un ajuste, que
     * uno repetido). Usa el indice idx_referencia.
     */
    public function yaMovioDocumento(string $referenciaTipo, int $referenciaId): bool
    {
        $stmt = $this->conexion->prepare(
            'SELECT 1 FROM inventory_movements WHERE referencia_tipo = :t AND referencia_id = :id LIMIT 1'
        );
        $stmt->execute([':t' => $referenciaTipo, ':id' => $referenciaId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Deshace lo que una factura todavia tiene movido: el saldo neto de sus
     * movimientos (referencia factura/<id>), producto por producto.
     *
     * Para el reenvio de un e-CF rechazado (facturaController::handleReenviar):
     * el intento rechazado ya movio la mercancia y el envio nuevo la mueve otra
     * vez con su propia factura. A diferencia de revertirVenta, no supone que
     * hubo movimiento: si el primero fallo (o ya se deshizo) el saldo es 0 y no
     * hace nada, asi que repetirlo no duplica la devolucion.
     *
     * @return int cuantos movimientos se registraron
     */
    public function devolverSaldoDeFactura(int $facturaId, ?int $userId = null): int
    {
        try {
            $stmt = $this->conexion->prepare(
                "SELECT product_id, SUM(cantidad) AS neto FROM inventory_movements
                 WHERE referencia_tipo = 'factura' AND referencia_id = :id
                 GROUP BY product_id
                 HAVING ROUND(SUM(cantidad), 3) <> 0"
            );
            $stmt->execute([':id' => $facturaId]);
            // Salio (venta): vuelve como DEVOLUCION. Entro (nota de credito E34):
            // sale como VENTA. Mismas etiquetas que ya muestra el kardex.
            $porTipo = ['DEVOLUCION' => [], 'VENTA' => []];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $neto = round((float) $r['neto'], 3);
                $porTipo[$neto < 0 ? 'DEVOLUCION' : 'VENTA'][] = [
                    'product_id' => (int) $r['product_id'],
                    'cantidad' => -$neto,
                    'costo_unitario' => null,
                ];
            }
            $total = 0;
            foreach ($porTipo as $tipo => $lineas) {
                if ($lineas === []) {
                    continue;
                }
                $res = $this->aplicarMovimientos($lineas, [
                    'tipo_movimiento' => $tipo,
                    'referencia_tipo' => 'factura',
                    'referencia_id' => $facturaId,
                    'user_id' => $userId,
                ]);
                if ($res[0] !== 'success') {
                    error_log('[inventario] saldo de factura ' . $facturaId . ': ' . $res[1]);
                    continue;
                }
                $total += count($res[1]);
            }
            return $total;
        } catch (Throwable $e) {
            error_log('[inventario] saldo de factura ' . $facturaId . ' fallo inesperado: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Mueve el inventario de una compra (gasto) ya guardada.
     *
     * Que comprobantes mueven existencias. Espejo de efectoInventario() en el
     * front (config/gastos.ts): si cambia uno, cambiar el otro.
     *   E31 Credito Fiscal, E41 Compras, E47 Pagos al Exterior -> ENTRA (COMPRA).
     *   E34 Nota de Credito del proveedor -> devolucion al proveedor, SALE.
     *   E33 Nota de Debito y E43 Gastos Menores -> no mueven.
     *
     * Una compra que nace en ERROR o RECHAZADO no mueve nada: el comprobante no
     * quedo valido y al reintentarlo se crea otro gasto, que volveria a sumar.
     * Igual que en la venta, un rechazo que llega DESPUES por la consulta de
     * estado no revierte el movimiento: se corrige con un ajuste.
     *
     * Se valoriza al precio de la linea, no al costo de ficha, y products.costo
     * no se toca: el costo promedio sale del libro (ver valorInventario).
     *
     * @param array $items lineas ya persistidas (product_id, quantity, amount)
     * @return int cuantos movimientos se registraron
     */
    public function registrarCompra(int $gastoId, array $items, string $tipoGasto, ?string $estadoDgii, ?int $userId = null): int
    {
        if (in_array((string) $estadoDgii, ['ERROR', 'RECHAZADO'], true)) {
            return 0;
        }
        $tipo = strtoupper(trim($tipoGasto));
        if (in_array($tipo, ['E31', 'E41', 'E47'], true)) {
            return $this->moverPorDocumento('gasto', $gastoId, $items, 1, 'COMPRA', $userId, true);
        }
        if ($tipo === 'E34') {
            return $this->moverPorDocumento('gasto', $gastoId, $items, -1, 'DEVOLUCION', $userId, true);
        }
        return 0;
    }

    /**
     * Cuerpo comun de registrarVenta/revertirVenta (ver moverPorDocumento).
     *
     * @param int $signo -1 saca del almacen, +1 devuelve
     */
    private function moverPorFactura(
        int $facturaId,
        array $items,
        int $signo,
        string $tipoMovimiento,
        ?int $userId = null
    ): int {
        return $this->moverPorDocumento('factura', $facturaId, $items, $signo, $tipoMovimiento, $userId, false);
    }

    /**
     * Filtra las lineas de un documento (factura o gasto) que mueven existencias
     * y aplica los movimientos con el signo pedido.
     *
     * @param string $referenciaTipo 'factura' | 'gasto' (inventory_movements.referencia_tipo)
     * @param int    $signo          -1 saca del almacen, +1 entra
     * @param bool   $costoDeLinea   true = valoriza al precio de la linea (compras);
     *                               false = al costo del producto (ventas)
     */
    private function moverPorDocumento(
        string $referenciaTipo,
        int $referenciaId,
        array $items,
        int $signo,
        string $tipoMovimiento,
        ?int $userId,
        bool $costoDeLinea
    ): int {
        // Todo el cuerpo va en try/catch: el contrato de este metodo es que un
        // fallo de inventario NUNCA tumba un documento ya guardado, y eso incluye
        // los errores que no vienen de aplicarMovimientos.
        try {
            $lineas = $this->lineasQueMueve($items, $signo, $costoDeLinea);
            if ($lineas === []) {
                return 0;
            }

            $res = $this->aplicarMovimientos($lineas, [
                'tipo_movimiento' => $tipoMovimiento,
                'referencia_tipo' => $referenciaTipo,
                'referencia_id' => $referenciaId,
                'user_id' => $userId,
            ]);
            if ($res[0] !== 'success') {
                error_log('[inventario] ' . $referenciaTipo . ' ' . $referenciaId . ': ' . $res[1]);
                return 0;
            }
            if ($tipoMovimiento === 'VENTA') {
                self::avisarSobreventa($referenciaId, $res[1]);
            }
            return count($res[1]);
        } catch (Throwable $e) {
            error_log('[inventario] ' . $referenciaTipo . ' ' . $referenciaId . ' fallo inesperado: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Las lineas de un documento (factura o gasto) que de verdad mueven
     * existencias, ya con signo y listas para aplicarMovimientos. Es el filtro
     * unico: lo usan el camino de despues del commit (moverPorDocumento) y el de
     * dentro de la transaccion (moverVentaEnTransaccion), para que "lo que se
     * descuenta" no se separe segun por donde llegue la venta.
     *
     * Solo cuentan las lineas con product_id que no son servicio del catalogo y
     * cuya cantidad, a 3 decimales, es mayor que 0. Hace UNA consulta (los
     * servicios) y solo si alguna linea trae product_id; una PDOException sale tal
     * cual: cada llamador decide que hacer con ella.
     *
     * @param int  $signo        -1 saca del almacen, +1 entra
     * @param bool $costoDeLinea true = valoriza al precio de la linea (compras);
     *                           false = al costo del producto (ventas)
     * @return array<int,array{product_id:int,cantidad:float,costo_unitario:?float}>
     */
    private function lineasQueMueve(array $items, int $signo, bool $costoDeLinea): array
    {
        $ids = [];
        foreach ($items as $it) {
            $it = (array) $it;
            if (!empty($it['product_id'])) {
                $ids[(int) $it['product_id']] = true;
            }
        }
        if ($ids === []) {
            return [];
        }
        // Quien decide que es servicio es el catalogo, no la linea: las
        // facturas simples no llevan indicador_bien_servicio, y asumir "bien"
        // hacia que un servicio del catalogo descontara existencias.
        $servicios = $this->serviciosDelCatalogo(array_keys($ids));

        $lineas = [];
        foreach ($items as $it) {
            $it = (array) $it;
            $productId = (int) ($it['product_id'] ?? 0);
            if ($productId <= 0 || isset($servicios[$productId])) {
                continue;
            }
            // La cantidad de la linea tal cual, con sus decimales: el stock es
            // DECIMAL(15,3) desde la migracion 025. Antes se redondeaba a entero
            // (1,5 m descontaba 2 y 0,4 kg no descontaba nada). El documento ya
            // la limito (2 decimales en e-CF, 3 en la factura simple); el
            // round(3) solo la iguala a lo que guarda la columna. Viene como
            // texto ("1.500") cuando son las lineas releidas de la DB.
            $cantidad = round((float) ($it['quantity'] ?? $it['cantidad'] ?? 0), 3);
            if ($cantidad <= 0) {
                continue;
            }
            $lineas[] = [
                'product_id' => $productId,
                'cantidad' => $signo * $cantidad,
                // Venta: el costo lo pone el producto (valorizar la salida al
                // precio de venta inflaria el valor del movimiento). Compra: lo
                // pone la linea, que es lo que costo de verdad y es lo que
                // alimenta el costo promedio.
                'costo_unitario' => $costoDeLinea
                    ? (float) ($it['amount'] ?? $it['precio_unitario'] ?? 0)
                    : null,
            ];
        }
        return $lineas;
    }

    /**
     * Con el interruptor de la empresa encendido, una venta que se guardo y dejo un
     * saldo por debajo de 0 es una sobreventa: pasa en e-CF, donde la revision previa
     * y el descuento estan separados por la ida y vuelta con la DGII (dos ventas de
     * la ultima unidad pueden pasar las dos). No cambia nada: solo la deja en el log
     * para poder medir cuantas ocurren.
     *
     * Nunca lanza: corre despues de guardar el documento y de aplicar los
     * movimientos, y un fallo aqui no puede hacer que se reporten como no aplicados.
     *
     * @param array $movimientos lo que devolvio aplicarMovimientos (cantidad_nueva)
     */
    private static function avisarSobreventa(int $facturaId, array $movimientos): void
    {
        try {
            // class_exists: el modelo tambien corre en scripts sin resolver empresa.
            if (!class_exists('TenantResolver', false) || !TenantResolver::inventarioEstricto()) {
                return;
            }
            foreach ($movimientos as $m) {
                $saldo = round((float) ($m['cantidad_nueva'] ?? 0), 3);
                if (self::milesimas($saldo) < 0) {
                    error_log('[inventario] SOBREVENTA factura ' . $facturaId . ' producto ' . ($m['product_id'] ?? '?') . ' saldo ' . $saldo);
                }
            }
        } catch (Throwable $e) {
            // Es solo un aviso: no se reporta el fallo del aviso.
        }
    }

    /**
     * Ids del catalogo marcados como servicio (indicador_bien_servicio = 2).
     * Un servicio no tiene existencias: products.stock es NULL para ellos.
     *
     * @param int[] $ids
     * @return array<int,true>
     */
    private function serviciosDelCatalogo(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $marcadores = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->conexion->prepare(
            "SELECT id FROM products WHERE id IN ({$marcadores}) AND indicador_bien_servicio = 2"
        );
        $stmt->execute(array_map('intval', $ids));
        return array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
    }

    /**
     * Unidad de medida (codigo DGII), nombre y existencia de cada producto: un
     * ajuste juzga los decimales de su cantidad con la unidad del catalogo, el
     * nombre va en el mensaje para que se sepa que linea corregir, y la
     * existencia dice si esa cantidad solo quita una fraccion que ya habia (ver
     * dejaExistenciaEntera). Un id que no existe no sale aqui; lo rechaza
     * despues aplicarMovimientos con su propio mensaje.
     *
     * @param array<int,mixed> $ids
     * @return array<int,array{unidad_medida:?string,nombre:string,stock:?float}>
     */
    private function unidadesDeProductos(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id) => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $marcadores = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->conexion->prepare(
            "SELECT id, nombre, unidad_medida, stock FROM products WHERE id IN ({$marcadores})"
        );
        $stmt->execute($ids);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['id']] = [
                'unidad_medida' => $r['unidad_medida'] !== null ? (string) $r['unidad_medida'] : null,
                'nombre' => (string) ($r['nombre'] ?? ''),
                // DECIMAL llega como texto ("8.500"); NULL en servicios.
                'stock' => isset($r['stock']) && is_numeric($r['stock']) ? round((float) $r['stock'], 3) : null,
            ];
        }
        return $out;
    }

    /**
     * ¿Esta linea deja entera una existencia que hoy tiene fraccion?
     *
     * Un producto en una unidad sin decimales ("Unidad") puede quedar con 8,5:
     * una linea de e-CF o de gasto se juzga con SU unidad (1,5 metros de ese
     * producto valen) y el libro mueve 1,5. Corregirlo en el libro exige una
     * fraccion (DISMINUCION 0,5 o INCREMENTO 0,5), y la regla de la unidad la
     * rechazaba: solo quedaba sobrescribir la existencia en el producto, sin
     * movimiento. Se deja pasar SOLO la fraccion que cierra la que ya habia; una
     * que crea una fraccion nueva sigue rechazada.
     *
     * La existencia se lee antes del bloqueo FOR UPDATE: si una venta la cambia
     * entretanto, lo peor es que quede con fraccion, que es de donde se partio.
     *
     * @param array|null $prod   fila de unidadesDeProductos (null = no existe)
     * @param float      $previo lo que ya movieron las lineas anteriores del
     *                           mismo producto en este ajuste
     * @param float      $delta  lo que mueve esta linea, con signo, a 3 decimales
     */
    private static function dejaExistenciaEntera(?array $prod, float $previo, float $delta): bool
    {
        if ($prod === null || $prod['stock'] === null) {
            return false;
        }
        $antes = round($prod['stock'] + $previo, 3);
        return unidadMedidaModel::decimalesDe($antes) > 0
            && unidadMedidaModel::decimalesDe(round($antes + $delta, 3)) === 0;
    }

    /**
     * Nombre del producto para un mensaje, entre «» para que el front no lo
     * tome por texto tecnico ("Tubo 1/2 = 3m" lleva un "="; ver
     * fiscalo src/api/errores.ts). Si el nombre parece una constante
     * (CABLE_THHN_12) el front descartaria el mensaje aun entre «», asi que se
     * omite: la linea ya va numerada y el formulario marca la fila.
     */
    private static function nombreEnMensaje(?array $prod): string
    {
        $nombre = self::nombreMostrable($prod);
        return $nombre === '' ? '' : ' («' . $nombre . '»)';
    }

    /**
     * El nombre limpio (sin «»), o '' cuando no conviene ponerlo en un mensaje:
     * esta en blanco o parece una constante. Es la regla de nombreEnMensaje, para
     * que los mensajes de ajuste y los de existencia decidan igual.
     */
    private static function nombreMostrable(?array $prod): string
    {
        $nombre = trim((string) ($prod['nombre'] ?? ''));
        if ($nombre === '' || preg_match('/\b[A-Z][A-Z0-9]+_[A-Z][A-Z0-9_]{2,}\b/', $nombre)) {
            return '';
        }
        return $nombre;
    }

    // ------------------------------------------------------------------
    // Verificacion de existencia al vender (spec 4.2 y 4.4)
    //
    // Solo LEE. La llama quien tiene el interruptor de la empresa encendido
    // (TenantResolver::inventarioEstricto()), ANTES de cualquier efecto: antes de
    // gastar el e-NCF y antes de guardar la factura simple. Quien descuenta sigue
    // siendo aplicarMovimientos.
    // ------------------------------------------------------------------

    /**
     * Cuanto de un producto se puede vender, en milesimas. Un Bien sin dato de
     * existencia (NULL) cuenta como 0, igual que el libro (`stock ?? 0`).
     *
     * Es el UNICO lugar que responde "cuanto hay del producto X": cuando haya
     * saldo por almacen cambia esta funcion (y la consulta que arma el catalogo),
     * no los comparadores. Puede ser negativo: el stock guardado puede estarlo.
     *
     * @param array $fila fila del catalogo de calcularFaltantes
     */
    private static function disponibleEnMilesimas(array $fila): int
    {
        return self::milesimas($fila['stock'] ?? 0);
    }

    /**
     * Un numero a milesimas enteras: (int) round($x * 1000). Todas las
     * comparaciones de existencia se hacen en enteros: con flotantes
     * 0.1 + 0.2 no es 0.3. Lo que no es numero cuenta como 0, y el tope de
     * +-1e12 (mas que lo que cabe en DECIMAL(15,3)) evita que un valor absurdo
     * desborde el entero.
     *
     * @param mixed $valor numero o texto DECIMAL ("1.500")
     */
    private static function milesimas($valor): int
    {
        if (!is_numeric($valor)) {
            return 0;
        }
        $v = (float) $valor;
        if (is_nan($v)) {
            return 0;
        }
        return (int) round(max(-1e12, min(1e12, $v)) * 1000);
    }

    /**
     * Lo que faltaria si se vendieran estas lineas: el nucleo puro de la
     * verificacion, sin base de datos.
     *
     * Reglas (espejo de lo que descuenta moverPorDocumento: "revisa" = "descuenta"):
     *  - Solo cuentan las lineas con product_id, que no sean servicio del catalogo.
     *    Una entrada (E34, E41) no se revisa en absoluto.
     *  - Las lineas del mismo producto se SUMAN antes de comparar (dos lineas de 3
     *    contra un stock de 4 no pasan).
     *  - Se compara en milesimas enteras; igual pasa; stock NULL cuenta como 0.
     *  - Un product_id que no esta en el catalogo va en no_existe, y una cantidad
     *    <= 0 (o que redondea a 0 a 3 decimales) de un producto va en
     *    cantidad_invalida. Ninguno de los dos es un faltante: nunca salen en
     *    `faltantes`, que solo nombra productos reales con existencia corta.
     *  - `faltante` = solicitado - saldo guardado (puede ser negativo: -3 y pide 5
     *    falta 8, para que el ajuste lo lleve hasta lo vendido); `disponible` es
     *    el saldo con piso en 0, para que ningun texto diga "quedan -3".
     *
     * @param array $items lineas con product_id y quantity o cantidad (arreglos u objetos)
     * @param array<int,array{nombre:string,sku:?string,unidad_medida:?string,stock:?float,servicio:bool}> $catalogo
     *        filas de products por id; un id ausente es un producto que no existe
     * @return array{faltantes:list<array{product_id:int,nombre:string,sku:?string,unidad_medida:?string,solicitado:float,disponible:float,faltante:float}>,no_existe:list<int>,cantidad_invalida:list<int>}
     *         todo ordenado por product_id
     */
    public static function calcularFaltantes(array $items, array $catalogo, ?string $tipoEcf): array
    {
        if (self::esEntrada($tipoEcf)) {
            return ['faltantes' => [], 'no_existe' => [], 'cantidad_invalida' => []];
        }

        $pedido = [];   // product_id => milesimas pedidas, sumadas
        $noExiste = [];
        $invalida = [];
        foreach ($items as $it) {
            $it = (array) $it;
            $productId = (int) ($it['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }
            $fila = $catalogo[$productId] ?? null;
            if ($fila === null) {
                $noExiste[$productId] = true;
                continue;
            }
            if (!empty($fila['servicio'])) {
                continue;
            }
            // La misma cantidad que lee moverPorDocumento: quantity y, si no
            // viene, cantidad.
            $milesimas = self::milesimas($it['quantity'] ?? $it['cantidad'] ?? 0);
            if ($milesimas <= 0) {
                $invalida[$productId] = true;
                continue;
            }
            $pedido[$productId] = ($pedido[$productId] ?? 0) + $milesimas;
        }

        ksort($pedido);
        $faltantes = [];
        foreach ($pedido as $productId => $solicitado) {
            $fila = $catalogo[$productId];
            $saldo = self::disponibleEnMilesimas($fila);
            if ($solicitado <= $saldo) {
                continue;
            }
            $faltantes[] = self::filaFaltante((int) $productId, $fila, $solicitado, $saldo);
        }

        $noExiste = array_keys($noExiste);
        $invalida = array_keys($invalida);
        sort($noExiste);
        sort($invalida);
        return ['faltantes' => $faltantes, 'no_existe' => $noExiste, 'cantidad_invalida' => $invalida];
    }

    /**
     * Una fila de `faltantes` (spec 4.4). Es el UNICO lugar que decide como se
     * cuentan solicitado, disponible y faltante: la usan calcularFaltantes (la
     * revision previa) y aplicarMovimientos (el rechazo con la fila bloqueada), para
     * que el front reciba la misma forma venga de donde venga.
     *
     * `disponible` es el saldo con piso en 0 (nada dice "quedan -3"); `faltante` es
     * solicitado menos el saldo guardado SIN piso, para que el ajuste sugerido
     * lleve la existencia hasta lo que se vende aunque el saldo sea negativo.
     *
     * @param array $fila     nombre, sku y unidad_medida del producto (la fila del
     *                        catalogo o la de products tal como sale de MySQL)
     * @param int   $solicitado milesimas pedidas
     * @param int   $saldo      milesimas guardadas (puede ser negativo)
     * @return array{product_id:int,nombre:string,sku:?string,unidad_medida:?string,solicitado:float,disponible:float,faltante:float}
     */
    private static function filaFaltante(int $productId, array $fila, int $solicitado, int $saldo): array
    {
        return [
            'product_id' => $productId,
            'nombre' => (string) ($fila['nombre'] ?? ''),
            'sku' => isset($fila['sku']) && $fila['sku'] !== '' ? (string) $fila['sku'] : null,
            'unidad_medida' => isset($fila['unidad_medida']) && $fila['unidad_medida'] !== '' ? (string) $fila['unidad_medida'] : null,
            // / 1000.0: siempre float (6000 / 1000 en enteros daria int).
            'solicitado' => $solicitado / 1000.0,
            'disponible' => max(0, $saldo) / 1000.0,
            'faltante' => ($solicitado - $saldo) / 1000.0,
        ];
    }

    /**
     * Una cantidad para un mensaje, como la muestra el app (es-DO): sin ceros de
     * relleno, punto decimal y coma de miles ("5", "0.299", "1,250.5").
     */
    private static function cantidadEnMensaje(float $cantidad): string
    {
        return rtrim(rtrim(number_format(round($cantidad, 3), 3, '.', ','), '0'), '.');
    }

    /**
     * El texto para quien vende: una oracion por producto, unidas. Los nombres
     * van entre «» y se omiten cuando parecen una constante (ver nombreEnMensaje);
     * la oracion sigue diciendo cuanto se pide y cuanto hay. Nunca imprime un
     * saldo negativo. El front arma su propia lista con `faltantes` y puede
     * ignorar este texto.
     *
     * @param array $faltantes filas de calcularFaltantes()['faltantes']
     */
    public static function mensajeFaltantes(array $faltantes): string
    {
        $frases = [];
        foreach ($faltantes as $f) {
            $nombre = self::nombreMostrable(['nombre' => $f['nombre'] ?? '']);
            $frases[] = 'No hay existencia suficiente' . ($nombre !== '' ? ' de «' . $nombre . '»' : '')
                . ': pides ' . self::cantidadEnMensaje((float) ($f['solicitado'] ?? 0))
                . ' y hay ' . self::cantidadEnMensaje(max(0.0, (float) ($f['disponible'] ?? 0))) . '.';
        }
        return implode(' ', $frases);
    }

    /**
     * Fila de products de cada id, en la forma que espera calcularFaltantes.
     * Una sola consulta de lectura (sin FOR UPDATE: la verificacion no bloquea).
     * Un id que no existe no sale.
     *
     * @param int[] $ids
     * @return array<int,array{nombre:string,sku:?string,unidad_medida:?string,stock:?float,servicio:bool}>
     */
    private function catalogoParaVenta(array $ids): array
    {
        $marcadores = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->conexion->prepare(
            "SELECT id, nombre, sku, unidad_medida, stock, indicador_bien_servicio FROM products WHERE id IN ({$marcadores})"
        );
        $stmt->execute(array_map('intval', $ids));
        $catalogo = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $catalogo[(int) $r['id']] = [
                'nombre' => (string) ($r['nombre'] ?? ''),
                'sku' => isset($r['sku']) && $r['sku'] !== '' ? (string) $r['sku'] : null,
                'unidad_medida' => isset($r['unidad_medida']) && $r['unidad_medida'] !== '' ? (string) $r['unidad_medida'] : null,
                // DECIMAL llega como texto ("8.500"); NULL = sin dato (cuenta como 0).
                'stock' => isset($r['stock']) && is_numeric($r['stock']) ? round((float) $r['stock'], 3) : null,
                'servicio' => (int) ($r['indicador_bien_servicio'] ?? 1) === 2,
            ];
        }
        return $catalogo;
    }

    /**
     * ¿Alcanza la existencia para estas lineas? Solo lee.
     *
     * Quien llama ya decidio que el interruptor de la empresa esta encendido
     * (ver verificarVentaEcf y el camino de la factura simple); esta funcion no
     * lo consulta.
     *
     * Una PDOException de la consulta sale tal cual: quien llama decide que hacer
     * (en e-CF la regla es un resguardo, no una dependencia fiscal, y no debe
     * impedir la venta si la base falla).
     *
     * @param array       $items   lineas con product_id y quantity o cantidad
     * @param string|null $tipoEcf '31'..'47'; null = factura simple. Una entrada
     *                             (E34, E41) devuelve success sin consultar.
     * @return array ['success', []]
     *               | ['error', mensaje, 422]  producto que no existe o cantidad <= 0,
     *                 en texto llano: no es un faltante de existencia
     *               | ['error', mensaje, 422, ['codigo' => 'STOCK_INSUFICIENTE',
     *                 'faltantes' => [...]]]   ver calcularFaltantes()
     */
    public function verificarDisponibilidad(array $items, ?string $tipoEcf): array
    {
        if (self::esEntrada($tipoEcf)) {
            return ['success', []];
        }
        $ids = [];
        foreach ($items as $it) {
            $it = (array) $it;
            $productId = (int) ($it['product_id'] ?? 0);
            if ($productId > 0) {
                $ids[$productId] = true;
            }
        }
        if ($ids === []) {
            return ['success', []];
        }
        $ids = array_keys($ids);
        sort($ids);

        $catalogo = $this->catalogoParaVenta($ids);
        $r = self::calcularFaltantes($items, $catalogo, $tipoEcf);

        if ($r['no_existe'] !== []) {
            // El id sirve para rastrearlo, no a quien vende: va al log y el texto
            // queda llano (el mismo de aplicarMovimientos cuando un producto
            // desaparece). Antes de esta revision el error llegaba como una
            // violacion de llave foranea y se veia como un fallo generico.
            foreach ($r['no_existe'] as $id) {
                error_log('[inventario] verificarDisponibilidad: el producto ' . $id . ' no existe');
            }
            return ['error', 'Uno de los productos ya no existe en el catálogo; puede que otra persona lo haya eliminado. '
                . 'Quítalo y vuelve a intentarlo.', 422];
        }
        if ($r['cantidad_invalida'] !== []) {
            $frases = [];
            foreach ($r['cantidad_invalida'] as $id) {
                $nombre = self::nombreMostrable($catalogo[$id] ?? null);
                $frases[] = ($nombre !== '' ? 'El producto «' . $nombre . '»' : 'Uno de los productos')
                    . ' necesita una cantidad mayor que 0.';
            }
            return ['error', implode(' ', $frases), 422];
        }
        if ($r['faltantes'] !== []) {
            return ['error', self::mensajeFaltantes($r['faltantes']), 422, [
                'codigo' => self::CODIGO_STOCK_INSUFICIENTE,
                'faltantes' => $r['faltantes'],
            ]];
        }
        return ['success', []];
    }

    /**
     * La puerta del e-CF (POST /api/facturas): ¿alcanza la existencia para esta
     * factura? La llama facturaController::emitirFacturaEcf ANTES de armar el
     * payload, o sea antes de que el servicio gaste el e-NCF: un rechazo despues de
     * dispensarlo puede dejar un hueco en la secuencia fiscal (spec C1).
     *
     * Devuelve success SIN consultar nada (ni siquiera la base) cuando:
     *  - el interruptor de la empresa esta apagado (TenantResolver::inventarioEstricto(),
     *    lo normal): la factura se comporta exactamente como siempre;
     *  - es el reenvio de un rechazado ($reenvioDe !== null, spec C5): es una
     *    obligacion fiscal por mercancia que ya se vendio y no puede frenarla la
     *    existencia de hoy;
     *  - el comprobante es una entrada (E34, E41; esEntrada): no saca mercancia.
     * Con el interruptor encendido todo lo demas (E31, E32, E33, E43, E44, E45, E46,
     * E47) se revisa, igual que lo que descuenta moverStockFacturaEcf (C15).
     *
     * Las cantidades se leen COMO LAS LEE EcfItemMapper::map, que es lo que luego
     * descuenta ("revisa" = "descuenta"): `cantidad` manda sobre `quantity` y una
     * linea sin cantidad cuenta 1. Con el request normal (no strict_input) los
     * items ya traen `cantidad` redondeada; el set de pruebas de la DGII puede traer
     * solo `quantity`, o ninguna, y no debe salir como "cantidad invalida" una linea
     * que si se descontaria. Lo demas (lineas libres, servicios, producto inexistente,
     * cantidad <= 0, suma por producto) lo decide verificarDisponibilidad.
     *
     * Una PDOException de la consulta sale tal cual: en e-CF la regla es un
     * resguardo, no una dependencia fiscal, y el controlador la atrapa para no
     * impedir la venta si la base falla.
     *
     * @param array  $items     lineas del e-CF ya normalizadas (product_id, cantidad)
     * @param string $tipoEcf   '31'..'47'
     * @param ?int   $reenvioDe id de la factura rechazada que este envio reemplaza
     * @return array ['success', []] | los errores de verificarDisponibilidad
     */
    public function verificarVentaEcf(array $items, string $tipoEcf, ?int $reenvioDe): array
    {
        // class_exists: el modelo tambien corre en scripts sin resolver empresa (como
        // en avisarSobreventa); sin empresa el interruptor esta apagado.
        if (!class_exists('TenantResolver', false) || !TenantResolver::inventarioEstricto()
            || $reenvioDe !== null || self::esEntrada($tipoEcf)) {
            return ['success', []];
        }
        $lineas = [];
        foreach ($items as $it) {
            $it = (array) $it;
            $lineas[] = [
                'product_id' => $it['product_id'] ?? null,
                // La misma lectura que EcfItemMapper::map: cantidad, luego quantity,
                // y sin ninguna de las dos, 1.
                'cantidad' => (float) ($it['cantidad'] ?? $it['quantity'] ?? 1),
            ];
        }
        return $this->verificarDisponibilidad($lineas, $tipoEcf);
    }

    /**
     * El cuerpo JSON de una respuesta de verificarDisponibilidad (o de un
     * aplicarMovimientos que rechazo por existencia): lo que imprimen los
     * controladores con HTTP 422. Con faltantes lleva `codigo` y `data.faltantes`
     * (spec 4.4); en el caso llano (producto inexistente, cantidad <= 0), solo
     * status y error.
     *
     * @param array $res ['error', mensaje, 422] | ['error', mensaje, 422, ['codigo' =>, 'faltantes' =>]]
     * @return array{status:false,error:string,codigo?:string,data?:array{faltantes:array}}
     */
    public static function respuestaExistencia(array $res): array
    {
        $cuerpo = ['status' => false, 'error' => (string) ($res[1] ?? '')];
        $datos = $res[3] ?? null;
        if (is_array($datos) && isset($datos['faltantes']) && is_array($datos['faltantes'])) {
            $cuerpo['codigo'] = (string) ($datos['codigo'] ?? self::CODIGO_STOCK_INSUFICIENTE);
            $cuerpo['data'] = ['faltantes' => $datos['faltantes']];
        }
        return $cuerpo;
    }

    // ------------------------------------------------------------------
    // Configuracion del control de existencias (GET/PUT /api/inventario/configuracion)
    // ------------------------------------------------------------------

    /**
     * ¿Este rol puede encender o apagar el bloqueo de ventas sin existencia?
     *
     * Solo el rol de sistema 'admin', por nombre (sin importar mayusculas) y no
     * por permiso: el mismo criterio que la bitacora (auditLogController). Un rol
     * personalizado puede tener '*' o el modulo de productos y aun asi no debe
     * decidir si la empresa vende sin existencia. Se exige tambien con
     * PERMISSIONS_ENFORCE apagado: el controlador lo llama en cada PUT.
     */
    public static function rolPuedeConfigurar(?string $rol): bool
    {
        return strtolower((string) $rol) === 'admin';
    }

    /**
     * Cuantos productos rechazaria hoy el bloqueo: Bienes activos con stock NULL
     * o <= 0 (un NULL cuenta como 0, C3). Lo muestra Configuracion junto al
     * interruptor para que el admin vea el efecto antes de encenderlo.
     *
     * "Bien" es lo mismo que en la venta y en el reporte de valor: todo lo que no
     * es Servicio (indicador_bien_servicio = 2). Solo lee.
     */
    public function contarSinExistencia(): int
    {
        $stmt = $this->conexion->query(
            'SELECT COUNT(*) FROM products
             WHERE activo = 1 AND indicador_bien_servicio <> 2
               AND (stock IS NULL OR stock <= 0)'
        );
        return (int) $stmt->fetchColumn();
    }

    // ------------------------------------------------------------------
    // Ajustes
    // ------------------------------------------------------------------

    /**
     * Crea un ajuste con sus lineas.
     *
     * @param array $data motivo, nota, warehouse_id, user_id, anula_a_id,
     *                    lineas: [['product_id','tipo'=>'INCREMENTO|DISMINUCION',
     *                              'cantidad'=>float>0 (hasta 3 decimales, si la
     *                              unidad del producto los admite o si la
     *                              fraccion deja entera la existencia),
     *                              'costo_unitario'=>float|null], ...]
     * @param bool  $ownTransaction false cuando el llamador ya abrio una.
     * @return array ['success', ajuste] | ['error', mensaje] | ['error', mensaje, 422]
     *               El 422 marca un dato mal escrito (cantidad, motivo, tipo):
     *               el controlador lo responde como tal y no como un fallo al guardar.
     */
    public function crearAjuste(array $data, bool $ownTransaction = true): array
    {
        // ANULACION no la elige el usuario: solo vale acompanada del ajuste que
        // anula. Antes se insertaba un motivo falso y se corregia con un UPDATE
        // posterior al commit, asi que un fallo a medias dejaba la anulacion
        // guardada para siempre como un conteo fisico.
        $motivo = strtoupper(trim((string) ($data['motivo'] ?? '')));
        $permitidos = !empty($data['anula_a_id'])
            ? ['ANULACION']
            : array_values(array_diff(self::MOTIVOS, ['ANULACION']));
        if (!in_array($motivo, $permitidos, true)) {
            return ['error', 'Elige el motivo del ajuste.', 422];
        }

        $lineas = is_array($data['lineas'] ?? null) ? $data['lineas'] : [];
        if ($lineas === []) {
            return ['error', 'Agrega al menos un producto con cantidad al ajuste.', 422];
        }
        // Cada linea bloquea una fila de products dentro de una sola transaccion:
        // sin tope, una peticion deja en espera a toda la facturacion.
        if (count($lineas) > self::MAX_LINEAS) {
            return ['error', 'Un ajuste puede tener hasta ' . self::MAX_LINEAS . ' productos. Divide el conteo en varios ajustes.', 422];
        }

        // La anulacion no se juzga por unidad: invierte al pie de la letra lo que
        // ya se movio, aunque despues le hayan cambiado la unidad al producto.
        $esAnulacion = !empty($data['anula_a_id']);
        // Unidad, nombre y existencia de los productos del ajuste: se cargan una
        // sola vez y solo si alguna linea trae decimales (una cantidad entera vale
        // en cualquier unidad y no hace falta ir al catalogo de la master).
        $productos = null;
        $unidades = null;
        require_once __DIR__ . '/unidadMedidaModel.php';

        // El signo lo decide el tipo de la linea; la cantidad siempre llega positiva.
        $movimientos = [];
        // Lo que ya movieron las lineas anteriores de cada producto: si uno sale
        // dos veces, la segunda linea se juzga contra la existencia que dejo la
        // primera, que es como las aplica aplicarMovimientos.
        $movidoPorProducto = [];
        foreach ($lineas as $i => $l) {
            // (float) y no (int): con (int) un ajuste de 1,5 kg movia 1 y uno de
            // 0,5 se rechazaba como si fuera 0. Un texto no numerico ("1,5") no
            // se convierte a medias (daria 1): cuenta como sin cantidad.
            $crudo = $l['cantidad'] ?? 0;
            $cantidad = is_numeric($crudo) ? (float) $crudo : 0.0;
            // Se juzga lo que de verdad se guarda (3 decimales): 0.0000004 pasaba
            // el "> 0", quedaba en 0 al redondear y aplicarMovimientos descartaba
            // la linea, asi que se guardaba un ajuste vacio que ni se podia anular.
            $cantidad3 = round($cantidad, 3);
            if (!($cantidad3 > 0)) {
                return ['error', 'La línea ' . ($i + 1) . ' necesita una cantidad mayor que 0.', 422];
            }
            // Stock y libro son DECIMAL(15,3) (migracion 025): llegan a
            // 999,999,999,999.999; pasado eso MySQL lo rechaza y quien ajusta
            // veria un "no se pudo guardar" sin saber por que.
            if ($cantidad3 >= 1000000000000) {
                return ['error', 'La cantidad de la línea ' . ($i + 1) . ' es demasiado grande.', 422];
            }
            $tipo = strtoupper(trim((string) ($l['tipo'] ?? 'INCREMENTO')));
            if (!in_array($tipo, ['INCREMENTO', 'DISMINUCION'], true)) {
                return ['error', 'En la línea ' . ($i + 1) . ', elige si es Incremento o Disminución.', 422];
            }
            $productId = (int) ($l['product_id'] ?? 0);
            $delta = $tipo === 'DISMINUCION' ? -$cantidad3 : $cantidad3;
            // Decimales: solo si la unidad del PRODUCTO los admite (1,5 metros si,
            // 1,5 "Unidad" no) y hasta 3, que es lo que guarda el stock. Se mira
            // la cantidad tal como llego: 1.0004 no se convierte en 1 sin avisar.
            if (!$esAnulacion && unidadMedidaModel::decimalesDe($cantidad) > 0) {
                if ($productos === null) {
                    $productos = $this->unidadesDeProductos(array_column($lineas, 'product_id'));
                    // Fail-open, como permiteDecimales: sin la master (instalacion de
                    // un solo tenant, caida momentanea) el ajuste no se bloquea por
                    // eso; la cantidad se guarda redondeada a 3.
                    try {
                        $unidades = new unidadMedidaModel();
                    } catch (Throwable $e) {
                        error_log('[inventario] crearAjuste sin catalogo de unidades (fail-open): ' . $e->getMessage());
                        $unidades = false;
                    }
                }
                $prod = $productos[$productId] ?? null;
                // Una fraccion que deja entera la existencia (8,5 - 0,5) es la
                // correccion, no una fraccion nueva: no se juzga con la unidad
                // (null = sin unidad que juzgar); solo cuenta el tope de 3 decimales.
                $unidadQueJuzga = self::dejaExistenciaEntera($prod, $movidoPorProducto[$productId] ?? 0.0, $delta)
                    ? null
                    : ($prod['unidad_medida'] ?? null);
                $problema = $unidades !== false
                    ? $unidades->problemaCantidad($cantidad, $unidadQueJuzga, 3)
                    : null;
                if ($problema !== null) {
                    // El mismo texto que da el formulario, con la linea y el producto
                    // delante: en un conteo de 200 lineas "la cantidad" sola no dice cual.
                    return ['error', 'En la línea ' . ($i + 1) . self::nombreEnMensaje($prod) . ', ' . lcfirst($problema), 422];
                }
            }
            $movidoPorProducto[$productId] = round(($movidoPorProducto[$productId] ?? 0.0) + $delta, 3);
            $movimientos[] = [
                'product_id' => $productId,
                'cantidad' => $delta,
                'costo_unitario' => $l['costo_unitario'] ?? null,
            ];
        }

        $warehouseId = (int) ($data['warehouse_id'] ?? 0);
        if ($warehouseId <= 0) {
            $warehouseId = (int) $this->conexion->query('SELECT id FROM warehouses ORDER BY id LIMIT 1')->fetchColumn();
        }
        if ($warehouseId <= 0) {
            return ['error', 'No tienes almacenes creados. Crea uno en Almacenes antes de ajustar el inventario.'];
        }

        try {
            if ($ownTransaction) {
                $this->conexion->beginTransaction();
            }

            $stmt = $this->conexion->prepare(
                'INSERT INTO inventory_adjustments
                    (codigo, fecha, motivo, nota, warehouse_id, user_id, total_lineas, total_valor, anula_a_id, created_at)
                 VALUES
                    (:codigo, NOW(), :motivo, :nota, :warehouse_id, :user_id, 0, 0, :anula_a_id, NOW())'
            );
            $stmt->execute([
                ':codigo' => $this->siguienteCodigo(),
                ':motivo' => $motivo,
                ':nota' => $data['nota'] ?? null,
                ':warehouse_id' => $warehouseId,
                ':user_id' => $data['user_id'] ?? null,
                ':anula_a_id' => $data['anula_a_id'] ?? null,
            ]);
            $ajusteId = (int) $this->conexion->lastInsertId();

            $res = $this->aplicarMovimientos($movimientos, [
                'tipo_movimiento' => 'AJUSTE',
                'referencia_tipo' => 'ajuste',
                'referencia_id' => $ajusteId,
                'warehouse_id' => $warehouseId,
                'user_id' => $data['user_id'] ?? null,
            ], false);
            if ($res[0] !== 'success') {
                if ($ownTransaction) {
                    $this->conexion->rollBack();
                }
                return $res;
            }

            $totalValor = array_sum(array_column($res[1], 'valor_movimiento'));
            $this->conexion->prepare(
                'UPDATE inventory_adjustments SET total_lineas = :n, total_valor = :v WHERE id = :id'
            )->execute([':n' => count($res[1]), ':v' => round($totalValor, 2), ':id' => $ajusteId]);

            if ($ownTransaction) {
                $this->conexion->commit();
            }
            return ['success', $this->getAjuste($ajusteId)];
        } catch (Throwable $e) {
            if ($ownTransaction && $this->conexion->inTransaction()) {
                $this->conexion->rollBack();
            }
            // Chocar con uk_codigo es la carrera de dos ajustes simultaneos, no un
            // error del usuario: merece un mensaje que se entienda. El mismo
            // SQLSTATE lo da la FK del almacen (1452) si lo borraron entretanto;
            // decirle "otro ajuste" ahi lo haria reintentar sin arreglo.
            if ($e instanceof PDOException && (string) $e->getCode() === '23000') {
                if ((int) ($e->errorInfo[1] ?? 0) === 1452) {
                    return ['error', 'El almacén elegido para el ajuste ya no existe. Elige otro e inténtalo de nuevo.'];
                }
                return ['error', 'Otro ajuste se guardó al mismo tiempo. Vuelve a intentarlo.'];
            }
            // Detalle tecnico al log. crearAjuste tambien corre dentro de
            // anularAjuste: ahi el usuario pulso "Anular", no "Guardar".
            error_log('[inventario] crearAjuste' . (!empty($data['anula_a_id']) ? ' (anula ' . $data['anula_a_id'] . ')' : '')
                . ': ' . $e->getMessage());
            return ['error', (!empty($data['anula_a_id']) ? 'No se pudo anular el ajuste.' : 'No se pudo guardar el ajuste.')
                . ' Inténtalo de nuevo y, si sigue pasando, avisa a soporte.'];
        }
    }

    /**
     * Anula un ajuste creando el ajuste INVERSO. No se borra nada: un historial
     * que se puede borrar no sirve como historial.
     *
     * Todo pasa en UNA transaccion y con la cabecera original bloqueada. Antes el
     * inverso se confirmaba por su cuenta y solo despues se marcaba el original,
     * asi que dos anulaciones a la vez revertian el stock dos veces.
     */
    public function anularAjuste(int $id, array $ctx = []): array
    {
        try {
            $this->conexion->beginTransaction();

            $stmt = $this->conexion->prepare(
                'SELECT id, codigo, motivo, warehouse_id, anulado_por_id
                 FROM inventory_adjustments WHERE id = :id FOR UPDATE'
            );
            $stmt->execute([':id' => $id]);
            $ajuste = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$ajuste) {
                $this->conexion->rollBack();
                return ['error', 'Ajuste no encontrado'];
            }
            if (!empty($ajuste['anulado_por_id'])) {
                // El usuario conoce los ajustes por su codigo (AJ-000123), no por
                // el id interno: se busca el del ajuste inverso para nombrarlo.
                $inverso = $this->conexion->prepare('SELECT codigo FROM inventory_adjustments WHERE id = :id');
                $inverso->execute([':id' => (int) $ajuste['anulado_por_id']]);
                $codigoInverso = (string) ($inverso->fetchColumn() ?: '');
                $this->conexion->rollBack();
                return ['error', $codigoInverso !== ''
                    ? 'Este ajuste ya fue anulado con el ajuste ' . $codigoInverso . '.'
                    : 'Este ajuste ya fue anulado.'];
            }
            if ($ajuste['motivo'] === 'ANULACION') {
                $this->conexion->rollBack();
                return ['error', 'Un ajuste de anulación no se puede anular.'];
            }

            // Lineas invertidas: lo que sumo, resta; lo que resto, suma. Con sus
            // decimales: con (int) anular un ajuste de 1,5 revertia solo 1.
            $lineas = [];
            foreach ($this->movimientosDeAjuste($id) as $mov) {
                $cantidad = round((float) $mov['cantidad'], 3);
                $lineas[] = [
                    'product_id' => (int) $mov['product_id'],
                    'tipo' => $cantidad > 0 ? 'DISMINUCION' : 'INCREMENTO',
                    'cantidad' => abs($cantidad),
                    'costo_unitario' => $mov['costo_unitario'],
                ];
            }

            $res = $this->crearAjuste([
                'motivo' => 'ANULACION',
                'nota' => 'Anulacion del ajuste ' . $ajuste['codigo']
                    . (!empty($ctx['nota']) ? ' - ' . $ctx['nota'] : ''),
                'warehouse_id' => $ajuste['warehouse_id'],
                'user_id' => $ctx['user_id'] ?? null,
                'anula_a_id' => $id,
                'lineas' => $lineas,
            ], false);
            if ($res[0] !== 'success') {
                $this->conexion->rollBack();
                return $res;
            }

            $this->conexion->prepare('UPDATE inventory_adjustments SET anulado_por_id = :nuevo WHERE id = :id')
                ->execute([':nuevo' => $res[1]['id'], ':id' => $id]);

            $this->conexion->commit();
            return ['success', $res[1]];
        } catch (Throwable $e) {
            if ($this->conexion->inTransaction()) {
                $this->conexion->rollBack();
            }
            error_log('[inventario] anularAjuste ' . $id . ': ' . $e->getMessage());
            return ['error', 'No se pudo anular el ajuste. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.'];
        }
    }

    // ------------------------------------------------------------------
    // Lecturas
    // ------------------------------------------------------------------

    public function getAjuste(int $id): ?array
    {
        $stmt = $this->conexion->prepare(
            'SELECT a.*, w.nombre AS almacen_nombre
             FROM inventory_adjustments a
             LEFT JOIN warehouses w ON w.id = a.warehouse_id
             WHERE a.id = :id'
        );
        $stmt->execute([':id' => $id]);
        $ajuste = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$ajuste) {
            return null;
        }
        $ajuste['lineas'] = $this->movimientosDeAjuste($id);
        return $ajuste;
    }

    public function movimientosDeAjuste(int $ajusteId): array
    {
        $stmt = $this->conexion->prepare(
            'SELECT m.*, p.nombre AS producto_nombre, p.sku
             FROM inventory_movements m
             LEFT JOIN products p ON p.id = m.product_id
             WHERE m.referencia_tipo = :ref_tipo AND m.referencia_id = :id
             ORDER BY m.id ASC'
        );
        $stmt->execute([':ref_tipo' => 'ajuste', ':id' => $ajusteId]);
        return self::cantidadesComoNumero($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * cantidad, cantidad_anterior y cantidad_nueva como numero. Eran INT y
     * llegaban al front como numero; como DECIMAL(15,3) MySQL las devuelve como
     * texto ("10.000", "-2.500") y la pantalla las pintaba tal cual. Mismas
     * claves, solo cambia el tipo.
     */
    private static function cantidadesComoNumero(array $filas): array
    {
        foreach ($filas as &$f) {
            foreach (['cantidad', 'cantidad_anterior', 'cantidad_nueva'] as $col) {
                if (array_key_exists($col, $f) && $f[$col] !== null) {
                    $f[$col] = (float) $f[$col];
                }
            }
        }
        unset($f);
        return $filas;
    }

    public function listarAjustes(int $offset, int $limit, array $filtros = []): array
    {
        $where = [];
        $params = [];
        if (!empty($filtros['motivo'])) {
            $where[] = 'a.motivo = :motivo';
            $params[':motivo'] = strtoupper($filtros['motivo']);
        }
        if (!empty($filtros['desde'])) {
            $where[] = 'a.fecha >= :desde';
            $params[':desde'] = $filtros['desde'] . ' 00:00:00';
        }
        if (!empty($filtros['hasta'])) {
            $where[] = 'a.fecha <= :hasta';
            $params[':hasta'] = $filtros['hasta'] . ' 23:59:59';
        }
        $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $sql = "SELECT a.*, w.nombre AS almacen_nombre
                FROM inventory_adjustments a
                LEFT JOIN warehouses w ON w.id = a.warehouse_id
                {$sqlWhere}
                ORDER BY a.id DESC
                LIMIT :limit OFFSET :offset";
        $stmt = $this->conexion->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $cnt = $this->conexion->prepare("SELECT COUNT(*) FROM inventory_adjustments a {$sqlWhere}");
        foreach ($params as $k => $v) {
            $cnt->bindValue($k, $v);
        }
        $cnt->execute();

        return ['items' => $rows, 'total' => (int) $cnt->fetchColumn()];
    }

    /** Kardex: todos los movimientos de un producto, del mas reciente al mas viejo. */
    public function kardex(int $productId, int $offset, int $limit): array
    {
        $stmt = $this->conexion->prepare(
            'SELECT m.*, a.codigo AS ajuste_codigo, a.motivo
             FROM inventory_movements m
             LEFT JOIN inventory_adjustments a
                    ON a.id = m.referencia_id AND m.referencia_tipo = :ref_tipo
             WHERE m.product_id = :pid
             ORDER BY m.id DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':ref_tipo', 'ajuste');
        $stmt->bindValue(':pid', $productId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = self::cantidadesComoNumero($stmt->fetchAll(PDO::FETCH_ASSOC));

        $cnt = $this->conexion->prepare('SELECT COUNT(*) FROM inventory_movements WHERE product_id = :pid');
        $cnt->execute([':pid' => $productId]);

        return ['items' => $rows, 'total' => (int) $cnt->fetchColumn()];
    }

    /**
     * Consecutivo visible del ajuste: AJ-000001.
     *
     * FOR UPDATE: sin el, dos ajustes simultaneos leen el mismo ultimo codigo y
     * el segundo choca contra uk_codigo. Siempre se llama dentro de la
     * transaccion del ajuste, asi que el bloqueo se suelta en el commit.
     */
    private function siguienteCodigo(): string
    {
        $ultimo = $this->conexion
            ->query('SELECT codigo FROM inventory_adjustments ORDER BY id DESC LIMIT 1 FOR UPDATE')
            ->fetchColumn();
        $n = ($ultimo && preg_match('/(\d+)$/', (string) $ultimo, $m)) ? ((int) $m[1] + 1) : 1;
        return 'AJ-' . str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }

    // ------------------------------------------------------------------
    // Valor de inventario
    // ------------------------------------------------------------------

    /**
     * Cuanto vale el inventario, producto por producto, a una fecha de corte.
     *
     * La existencia NO se reconstruye sumando movimientos desde cero: se parte
     * de `products.stock` (la verdad de hoy) y se le RESTAN los movimientos
     * posteriores al corte. Eso da el saldo exacto sin necesitar un asiento de
     * apertura — y aqui hace falta, porque el stock de este sistema se cargo
     * directo en products, no por el libro: sumar el libro desde cero daria
     * cero para todo lo que existia antes del primer movimiento.
     *
     * El costo promedio es el PONDERADO de las entradas del libro hasta el
     * corte (valor total entrado / cantidad total entrada). Un producto sin
     * entradas registradas cae a `products.costo`, que es el unico costo que se
     * conoce de el; la respuesta dice cual de los dos se uso, para que nadie
     * confunda un promedio real con el costo de ficha.
     *
     * Se calcula sobre TODOS los productos que pasan el filtro y se pagina
     * despues: el total del reporte tiene que ser el del inventario completo,
     * no el de la pagina, y ordenar por valor exige tenerlos todos.
     *
     * @param array{query?:string,warehouse_id?:int,category_id?:int,estado?:string,hasta?:string} $filtros
     * @return array{items:array<int,array>,total:int,totales:array}
     */
    public function valorInventario(int $offset, int $limit, array $filtros = []): array
    {
        // Corte: fin del dia indicado, o ahora. Sin hora, un `hasta` de hoy
        // dejaria fuera todo lo que se movio hoy mismo.
        $hasta = !empty($filtros['hasta'])
            ? date('Y-m-d 23:59:59', strtotime((string) $filtros['hasta']))
            : date('Y-m-d H:i:s');

        $where = ['p.indicador_bien_servicio <> 2'];  // los servicios no tienen existencia
        $params = [];

        $estado = strtolower((string) ($filtros['estado'] ?? 'activos'));
        if ($estado === 'activos') {
            $where[] = 'p.activo = 1';
        } elseif ($estado === 'inactivos') {
            $where[] = 'p.activo = 0';
        }
        if (!empty($filtros['warehouse_id'])) {
            $where[] = 'p.warehouse_id = :wid';
            $params[':wid'] = (int) $filtros['warehouse_id'];
        }
        if (!empty($filtros['category_id'])) {
            $where[] = 'p.category_id = :cid';
            $params[':cid'] = (int) $filtros['category_id'];
        }
        if (!empty($filtros['query'])) {
            $where[] = '(p.nombre LIKE :q OR p.sku LIKE :q)';
            $params[':q'] = '%' . $filtros['query'] . '%';
        }

        $sql = 'SELECT p.id, p.sku, p.nombre, p.stock, p.costo, p.activo, p.created_at,
                       p.category_id, p.warehouse_id,
                       c.nombre AS categoria, w.nombre AS almacen
                FROM products p
                LEFT JOIN categories c ON c.id = p.category_id
                LEFT JOIN warehouses w ON w.id = p.warehouse_id
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY p.id';
        $stmt = $this->conexion->prepare($sql);
        $stmt->execute($params);
        $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $agregados = $this->agregadosMovimientos(array_column($productos, 'id'), $hasta);

        $filas = [];
        $totalExistencia = 0.0;
        $totalValor = 0.0;
        foreach ($productos as $p) {
            $fila = $this->filaValorizada($p, $agregados[(int) $p['id']] ?? [], $hasta);
            $totalExistencia += $fila['existencia'];
            $totalValor += $fila['valor_inventario'];
            $filas[] = $fila;
        }

        // De mayor a menor valor: en un reporte de valorizacion lo primero que
        // se mira es donde esta el dinero.
        usort($filas, static fn(array $a, array $b) => $b['valor_inventario'] <=> $a['valor_inventario']);

        return [
            'items' => array_slice($filas, $offset, $limit),
            'total' => count($filas),
            'totales' => [
                'productos'  => count($filas),
                // Suma de existencias con decimales: sin el round, el ruido
                // binario de sumar 0,1 + 0,2 llega al reporte (0,30000000000000004).
                'existencia' => round($totalExistencia, 3),
                'valor'      => round($totalValor, 2),
            ],
            'hasta' => $hasta,
        ];
    }

    /**
     * La aritmetica de una fila del reporte, aislada de SQL a proposito: es
     * dinero, y asi se puede probar sin base de datos.
     *
     * @param array $p        Fila de products (stock, costo, created_at...).
     * @param array $agregado Salida de agregadosMovimientos() para ese producto.
     */
    private function filaValorizada(array $p, array $agregado, string $hasta): array
    {
        $a = $agregado + ['delta_posterior' => 0.0, 'entradas' => 0.0, 'salidas' => 0.0,
                          'ent_cant' => 0.0, 'ent_valor' => 0.0];

        // Cantidades con decimales (DECIMAL(15,3), migracion 025). Con (int) el
        // stock "12.500" contaba 12, y una entrada de 0,5 kg contaba 0: el
        // promedio caia al costo de ficha o se dividia por una cantidad truncada.
        // Producto dado de alta despues del corte: a esa fecha no existia.
        $nacioDespues = !empty($p['created_at']) && $p['created_at'] > $hasta;
        $existencia = $nacioDespues ? 0.0 : round((float) $p['stock'] - (float) $a['delta_posterior'], 3);

        $entCant = (float) $a['ent_cant'];
        $promediado = $entCant > 0;
        $costo = $promediado
            ? round((float) $a['ent_valor'] / $entCant, 4)
            : round((float) ($p['costo'] ?? 0), 4);

        return [
            'id'             => (int) $p['id'],
            'sku'            => (string) ($p['sku'] ?? ''),
            'nombre'         => (string) ($p['nombre'] ?? ''),
            'categoria'      => (string) ($p['categoria'] ?? ''),
            'almacen'        => (string) ($p['almacen'] ?? ''),
            'activo'         => (int) ($p['activo'] ?? 0) === 1,
            'entradas'       => $nacioDespues ? 0.0 : round((float) $a['entradas'], 3),
            'salidas'        => $nacioDespues ? 0.0 : round((float) $a['salidas'], 3),
            'existencia'     => $existencia,
            'costo_promedio' => $costo,
            // false => es el costo de ficha del producto, no un promedio
            // calculado. El front lo marca para que nadie de por real un
            // promedio que nadie calculo.
            'costo_ponderado' => $promediado,
            'valor_inventario' => round($existencia * $costo, 2),
        ];
    }

    /**
     * Agregados del libro por producto hasta el corte, en una sola consulta
     * (nada de una por producto).
     *
     * @param array<int,int|string> $productIds
     * @return array<int,array{delta_posterior:float,entradas:float,salidas:float,ent_cant:float,ent_valor:float}>
     */
    private function agregadosMovimientos(array $productIds, string $hasta): array
    {
        if ($productIds === []) {
            return [];
        }
        $marcas = implode(',', array_fill(0, count($productIds), '?'));

        $sql = "SELECT product_id,
                       SUM(CASE WHEN created_at >  ? THEN cantidad ELSE 0 END) AS delta_posterior,
                       SUM(CASE WHEN created_at <= ? AND cantidad > 0 THEN cantidad ELSE 0 END) AS entradas,
                       SUM(CASE WHEN created_at <= ? AND cantidad < 0 THEN -cantidad ELSE 0 END) AS salidas,
                       SUM(CASE WHEN created_at <= ? AND cantidad > 0 THEN cantidad ELSE 0 END) AS ent_cant,
                       SUM(CASE WHEN created_at <= ? AND cantidad > 0 THEN valor_movimiento ELSE 0 END) AS ent_valor
                FROM inventory_movements
                WHERE product_id IN ({$marcas})
                GROUP BY product_id";

        $stmt = $this->conexion->prepare($sql);
        $stmt->execute(array_merge([$hasta, $hasta, $hasta, $hasta, $hasta], array_values($productIds)));

        // SUM sobre DECIMAL llega como texto ("1.500"): (int) lo truncaba.
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['product_id']] = [
                'delta_posterior' => (float) $r['delta_posterior'],
                'entradas'        => (float) $r['entradas'],
                'salidas'         => (float) $r['salidas'],
                'ent_cant'        => (float) $r['ent_cant'],
                'ent_valor'       => (float) $r['ent_valor'],
            ];
        }
        return $out;
    }
}
