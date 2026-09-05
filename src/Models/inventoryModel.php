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
     * @param array $lineas  [['product_id'=>int, 'cantidad'=>int (con signo),
     *                         'costo_unitario'=>float|null], ...]
     * @param array $ctx     tipo_movimiento, referencia_tipo, referencia_id,
     *                       warehouse_id (opcional: si falta usa el del producto),
     *                       user_id
     * @param bool  $ownTransaction  false cuando el llamador ya abrio una.
     * @return array{0:string,1:mixed} ['success', [movimientos]] | ['error', mensaje]
     */
    public function aplicarMovimientos(array $lineas, array $ctx, bool $ownTransaction = true): array
    {
        $lineas = array_values(array_filter($lineas, static function ($l) {
            return !empty($l['product_id']) && (int) ($l['cantidad'] ?? 0) !== 0;
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
                    return ['error', "El producto {$productId} no existe."];
                }

                $cantidad = (int) $l['cantidad'];
                $anterior = (int) ($prod['stock'] ?? 0);
                $nueva = $anterior + $cantidad;
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
            return ['error', 'No se pudo aplicar el movimiento: ' . $e->getMessage()];
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
        $signo = $entrada ? 1 : -1;

        // Todo el cuerpo va en try/catch: el contrato de este metodo es que un
        // fallo de inventario NUNCA tumba una factura ya emitida, y eso incluye
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
                $cruda = (float) ($it['quantity'] ?? $it['cantidad'] ?? 0);
                $cantidad = (int) round($cruda);
                // products.stock e inventory_movements.cantidad son enteros en
                // todo el sistema: una venta fraccionada se redondea (y por debajo
                // de 0.5 desaparece). Queda en el log para poder rastrear el
                // descuadre en vez de descubrirlo en el proximo conteo fisico.
                if (abs($cruda - $cantidad) > 0.0001) {
                    error_log('[inventario] factura ' . $facturaId . ' producto ' . $productId
                        . ': cantidad ' . $cruda . ' redondeada a ' . $cantidad . ' (el stock es entero)');
                }
                if ($cantidad <= 0) {
                    continue;
                }
                $lineas[] = [
                    'product_id' => $productId,
                    'cantidad' => $signo * $cantidad,
                    // El costo lo pone el producto: valorizar la salida al precio de
                    // venta inflaria el valor del movimiento.
                    'costo_unitario' => null,
                ];
            }
            if ($lineas === []) {
                return 0;
            }

            $res = $this->aplicarMovimientos($lineas, [
                'tipo_movimiento' => (string) $tipoEcf === '41'
                    ? 'COMPRA'
                    : ($entrada ? 'DEVOLUCION' : 'VENTA'),
                'referencia_tipo' => 'factura',
                'referencia_id' => $facturaId,
                'user_id' => $userId,
            ]);
            if ($res[0] !== 'success') {
                error_log('[inventario] factura ' . $facturaId . ': ' . $res[1]);
                return 0;
            }
            return count($res[1]);
        } catch (Throwable $e) {
            error_log('[inventario] factura ' . $facturaId . ' fallo inesperado: ' . $e->getMessage());
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

    // ------------------------------------------------------------------
    // Ajustes
    // ------------------------------------------------------------------

    /**
     * Crea un ajuste con sus lineas.
     *
     * @param array $data motivo, nota, warehouse_id, user_id, anula_a_id,
     *                    lineas: [['product_id','tipo'=>'INCREMENTO|DISMINUCION',
     *                              'cantidad'=>int>0,'costo_unitario'=>float|null], ...]
     * @param bool  $ownTransaction false cuando el llamador ya abrio una.
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
            return ['error', 'motivo invalido. Use: ' . implode(', ', $permitidos)];
        }

        $lineas = is_array($data['lineas'] ?? null) ? $data['lineas'] : [];
        if ($lineas === []) {
            return ['error', 'El ajuste necesita al menos una linea.'];
        }
        // Cada linea bloquea una fila de products dentro de una sola transaccion:
        // sin tope, una peticion deja en espera a toda la facturacion.
        if (count($lineas) > self::MAX_LINEAS) {
            return ['error', 'Un ajuste admite hasta ' . self::MAX_LINEAS . ' lineas. Divide el conteo en varios.'];
        }

        // El signo lo decide el tipo de la linea; la cantidad siempre llega positiva.
        $movimientos = [];
        foreach ($lineas as $i => $l) {
            $cantidad = (int) ($l['cantidad'] ?? 0);
            if ($cantidad <= 0) {
                return ['error', 'La linea ' . ($i + 1) . ' necesita una cantidad mayor que 0.'];
            }
            $tipo = strtoupper(trim((string) ($l['tipo'] ?? 'INCREMENTO')));
            if (!in_array($tipo, ['INCREMENTO', 'DISMINUCION'], true)) {
                return ['error', 'La linea ' . ($i + 1) . ' tiene un tipo invalido (INCREMENTO o DISMINUCION).'];
            }
            $movimientos[] = [
                'product_id' => (int) ($l['product_id'] ?? 0),
                'cantidad' => $tipo === 'DISMINUCION' ? -$cantidad : $cantidad,
                'costo_unitario' => $l['costo_unitario'] ?? null,
            ];
        }

        $warehouseId = (int) ($data['warehouse_id'] ?? 0);
        if ($warehouseId <= 0) {
            $warehouseId = (int) $this->conexion->query('SELECT id FROM warehouses ORDER BY id LIMIT 1')->fetchColumn();
        }
        if ($warehouseId <= 0) {
            return ['error', 'No hay almacenes registrados.'];
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
            // error del usuario: merece un mensaje que se entienda.
            if ($e instanceof PDOException && (string) $e->getCode() === '23000') {
                return ['error', 'Otro ajuste se guardo al mismo tiempo. Vuelve a intentarlo.'];
            }
            return ['error', 'No se pudo crear el ajuste: ' . $e->getMessage()];
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
                $this->conexion->rollBack();
                return ['error', 'Ese ajuste ya fue anulado (por el ' . $ajuste['anulado_por_id'] . ').'];
            }
            if ($ajuste['motivo'] === 'ANULACION') {
                $this->conexion->rollBack();
                return ['error', 'Un ajuste de anulacion no se puede anular.'];
            }

            // Lineas invertidas: lo que sumo, resta; lo que resto, suma.
            $lineas = [];
            foreach ($this->movimientosDeAjuste($id) as $mov) {
                $cantidad = (int) $mov['cantidad'];
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
            return ['error', 'No se pudo anular el ajuste: ' . $e->getMessage()];
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
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
}
