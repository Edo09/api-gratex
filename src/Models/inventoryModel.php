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

    /** Motivos validos de un ajuste. ANULACION la pone el sistema, no el usuario. */
    public const MOTIVOS = [
        'CONTEO_FISICO', 'MERMA', 'DANO', 'ROBO',
        'DEVOLUCION', 'ERROR_CAPTURA', 'ANULACION', 'OTRO',
    ];

    /**
     * Tope de lineas por ajuste. Cada linea toma un SELECT ... FOR UPDATE dentro
     * de la misma transaccion, asi que un ajuste sin limite puede dejar en espera
     * a toda la facturacion hasta agotar el lock wait timeout.
     */
    public const MAX_LINEAS = 500;

    public function __construct()
    {
        $this->conexion = Database::getInstance()->getConnection();
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
     *                       user_id
     * @param bool  $ownTransaction  false cuando el llamador ya abrio una.
     * @return array{0:string,1:mixed} ['success', [movimientos]] | ['error', mensaje]
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

            // FOR UPDATE: dos usuarios ajustando el mismo producto a la vez leerian
            // el mismo saldo y el segundo pisaria al primero. El bloqueo los serializa.
            $lock = $this->conexion->prepare(
                'SELECT id, stock, costo, warehouse_id FROM products WHERE id = :id FOR UPDATE'
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

            $creados = [];
            foreach ($lineas as $l) {
                $productId = (int) $l['product_id'];
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

                // Stock y libro son DECIMAL(15,3) (migracion 025): 1,5 m de cable
                // mueven 1,5, no 2. MySQL devuelve el DECIMAL como texto
                // ("10.500"), de ahi el (float). Todo se redondea a 3 para que la
                // foto guardada (antes / movimiento / despues) sea exactamente lo
                // que la columna conserva y 10,1 - 0,3 no quede en 9,7999999.
                $cantidad = round((float) $l['cantidad'], 3);
                $anterior = round((float) ($prod['stock'] ?? 0), 3);
                $nueva = round($anterior + $cantidad, 3);
                // El saldo negativo NO se bloquea: la venta ya ocurrio y el
                // comprobante puede estar emitido. Se registra y queda visible
                // en el reporte para que lo corrijan con un ajuste.
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
            return ['error', 'No se pudieron actualizar las existencias. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.'];
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
     *  - Se permite saldo negativo. Bloquear una venta por un stock que hasta hoy
     *    nadie mantenia seria peor que registrarlo y que lo vean en el reporte.
     *
     * @param array $items lineas ya persistidas (product_id, quantity, indicador_bien_servicio)
     * @return int cuantos movimientos se registraron
     */
    public function registrarVenta(int $facturaId, array $items, ?string $tipoEcf, ?int $userId = null): int
    {
        // Que comprobantes ENTRAN mercancia en vez de sacarla:
        //   E34 Nota de Credito -> devolucion del cliente, vuelve al almacen.
        //   E41 Compras         -> comprobante que el emisor levanta por una
        //                          compra a un proveedor no registrado: la
        //                          mercancia entra, no sale.
        $entrada = in_array((string) $tipoEcf, ['34', '41'], true);

        return $this->moverPorFactura(
            $facturaId,
            $items,
            $entrada ? 1 : -1,
            (string) $tipoEcf === '41' ? 'COMPRA' : ($entrada ? 'DEVOLUCION' : 'VENTA'),
            $userId
        );
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
            $ids = [];
            foreach ($items as $it) {
                $it = (array) $it;
                if (!empty($it['product_id'])) {
                    $ids[(int) $it['product_id']] = true;
                }
            }
            if ($ids === []) {
                return 0;
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
            return count($res[1]);
        } catch (Throwable $e) {
            error_log('[inventario] ' . $referenciaTipo . ' ' . $referenciaId . ' fallo inesperado: ' . $e->getMessage());
            return 0;
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
        $nombre = trim((string) ($prod['nombre'] ?? ''));
        if ($nombre === '' || preg_match('/\b[A-Z][A-Z0-9]+_[A-Z][A-Z0-9_]{2,}\b/', $nombre)) {
            return '';
        }
        return ' («' . $nombre . '»)';
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
