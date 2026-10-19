<?php
require_once(__DIR__ . '/../Database.php');
// El libro de inventario solo hace falta con el interruptor de existencias
// encendido (saveProduct). Un require_once que falla es un fatal que ningun
// try/catch atrapa: con un archivo que no subio (subida parcial) TODO /api/products,
// de TODAS las empresas, respondia un 500 vacio. Como en los otros consumidores
// (facturaController::inventarioModelo, fsInventario), el archivo se comprueba antes.
if (is_file(__DIR__ . '/inventoryModel.php')) {
    require_once(__DIR__ . '/inventoryModel.php');
}

/**
 * Catalogo de productos/servicios (tabla `products`, DB del tenant).
 * `indicador_facturacion`: 1=ITBIS 18% (gravado) | 2=16% | 3=Tasa cero | 4=Exento | 0=No facturable.
 * `indicador_bien_servicio`: 1=Bien | 2=Servicio.
 *
 * Inventario: `category_id` (FK categories, opcional) y `warehouse_id` (FK warehouses,
 * obligatorio). Si no se envia warehouse_id al crear, se asigna "Almacén Principal".
 *
 * Existencia (`stock`): con el interruptor «Bloquear ventas sin existencia» de la
 * empresa APAGADO (lo normal) el formulario escribe `products.stock` directo, como
 * siempre. Con el interruptor ENCENDIDO la existencia solo se mueve por el libro
 * (inventoryModel): la que se teclea al CREAR entra como un ajuste INVENTARIO_INICIAL
 * y al EDITAR el campo se ignora. Si el formulario pudiera escribirla sin dejar
 * rastro, el bloqueo de ventas no tendria con que cuadrar.
 */
class productModel
{
    private $conexion;

    public function __construct()
    {
        $this->conexion = Database::getInstance()->getConnection();
    }

    /** SELECT con los nombres de categoria y almacen (no solo los ids). */
    private const SELECT_JOINED =
        'SELECT p.*, c.nombre AS categoria_nombre, w.nombre AS almacen_nombre
           FROM products p
           LEFT JOIN categories c ON c.id = p.category_id
           LEFT JOIN warehouses w ON w.id = p.warehouse_id';

    public function getProducts($id = null)
    {
        try {
            if ($id === null) {
                $stmt = $this->conexion->prepare(self::SELECT_JOINED . ' ORDER BY p.id DESC');
                $stmt->execute();
            } else {
                $stmt = $this->conexion->prepare(self::SELECT_JOINED . ' WHERE p.id = :id');
                $stmt->execute([':id' => (int) $id]);
            }
            return self::existenciasComoNumero($stmt->fetchAll());
        } catch (PDOException $e) {
            // Vacio = "no existe" para quien llama: sin el log, una caida de la
            // DB se confundiria con un producto borrado.
            error_log('[productos] getProducts: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Condiciones del listado, compartidas por la pagina y el conteo: si se
     * escribieran por separado, el total dejaria de cuadrar con las filas y la
     * paginacion mostraria paginas vacias.
     *
     * @return array{0:string,1:array<string,mixed>} [WHERE (o vacio), parametros]
     */
    private function listFilters($query = null, $categoryId = null): array
    {
        $where = [];
        $params = [];
        if ($query !== null && $query !== '') {
            $where[] = '(p.nombre LIKE :query OR p.sku LIKE :query OR p.descripcion LIKE :query
                         OR c.nombre LIKE :query OR w.nombre LIKE :query)';
            $params[':query'] = "%{$query}%";
        }
        // Filtra por la categoria del producto, no por su nombre: `?query=` ya
        // matchea el nombre de la categoria y traeria ademas los productos que
        // la mencionan en su propio nombre o descripcion.
        if ($categoryId !== null && (int) $categoryId > 0) {
            $where[] = 'p.category_id = :category_id';
            $params[':category_id'] = (int) $categoryId;
        }
        return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $params];
    }

    public function getProductsPaginated($offset, $limit, $query = null, $categoryId = null)
    {
        try {
            [$where, $params] = $this->listFilters($query, $categoryId);
            $sql = self::SELECT_JOINED . " {$where} ORDER BY p.id DESC LIMIT :limit OFFSET :offset";
            $stmt = $this->conexion->prepare($sql);
            foreach ($params as $nombre => $valor) {
                $stmt->bindValue($nombre, $valor, is_int($valor) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            // LIMIT/OFFSET van con bindValue y PARAM_INT: execute() con array los
            // manda como string y MySQL rechaza `LIMIT '100'`.
            $stmt->bindValue(':limit', (int) $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', (int) $offset, PDO::PARAM_INT);
            $stmt->execute();
            return self::existenciasComoNumero($stmt->fetchAll());
        } catch (PDOException $e) {
            error_log('[productos] getProductsPaginated: ' . $e->getMessage());
            return [];
        }
    }

    public function getProductsCount($query = null, $categoryId = null)
    {
        try {
            [$where, $params] = $this->listFilters($query, $categoryId);
            $sql = 'SELECT COUNT(*) AS total
                      FROM products p
                      LEFT JOIN categories c ON c.id = p.category_id
                      LEFT JOIN warehouses w ON w.id = p.warehouse_id ' . $where;
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch();
            return $row ? (int) $row['total'] : 0;
        } catch (PDOException $e) {
            error_log('[productos] getProductsCount: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Crea el producto.
     *
     * Con el interruptor de existencias de la empresa encendido y una existencia
     * inicial en el formulario, el producto se inserta con stock NULL y esa
     * cantidad se registra por el libro (ajuste INVENTARIO_INICIAL, a nombre del
     * usuario del token), todo en una sola transaccion: si el libro falla, el
     * producto tampoco se crea. En cualquier otro caso el INSERT es el de siempre.
     *
     * Con el interruptor encendido y el archivo del libro (inventoryModel.php) sin
     * subir, se rechaza antes de tocar la base con un texto llano: un producto que
     * naciera sin pasar por el libro dejaria saldo sin movimiento que lo explique.
     *
     * @param ?int $userId el usuario del token, para el ajuste (null = sin usuario)
     * @return array ['success', mensaje, id] | ['error', mensaje]
     */
    public function saveProduct($data, ?int $userId = null)
    {
        try {
            $estricto = self::inventarioEstricto();
            if ($estricto && !class_exists('inventoryModel', false)) {
                error_log('[productos] saveProduct: falta Models/inventoryModel.php (subida incompleta) y el interruptor de existencias esta encendido; el producto no se creo');
                return ['error', 'No se pudo guardar el producto: el control de existencias no está disponible en este momento. '
                    . 'Inténtalo de nuevo más tarde y, si sigue pasando, avisa a soporte.'];
            }
            $warehouseId = $this->resolveWarehouseId($data, null);
            if ($warehouseId === null) {
                // Sin almacen elegido se usa "Almacén Principal"; si no aparece es
                // que no hay almacenes o que lo renombraron. El texto cubre ambos.
                return ['error', 'Elige el almacén del producto. Si no tienes ninguno, créalo primero en Almacenes.'];
            }
            $inicial = self::existenciaInicialDelLibro($data, $estricto);
            // El controlador ya la responde con 422; esto cubre a quien llame al
            // modelo sin pasar por el.
            $problemaInicial = self::problemaExistenciaInicial($data, $estricto);
            if ($problemaInicial !== null) {
                return ['error', $problemaInicial];
            }
            $sql = "INSERT INTO products
                (sku, nombre, descripcion, category_id, warehouse_id, indicador_bien_servicio, indicador_facturacion,
                 precio, precio_2, precio_3, precio_4, costo, unidad_medida, stock, stock_minimo, activo)
                VALUES
                (:sku, :nombre, :descripcion, :category_id, :warehouse_id, :ibs, :ifact,
                 :precio, :precio_2, :precio_3, :precio_4, :costo, :unidad_medida, :stock, :stock_minimo, :activo)";
            $params = $this->bindParams($data, $warehouseId);
            if ($inicial === null) {
                $stmt = $this->conexion->prepare($sql);
                $stmt->execute($params);
                return ['success', 'Product saved', (int) $this->conexion->lastInsertId()];
            }

            // La existencia la pone el libro, no el INSERT: sin esto el producto
            // tendria saldo sin ningun movimiento que lo explique.
            $params[':stock'] = null;
            $this->conexion->beginTransaction();
            try {
                $stmt = $this->conexion->prepare($sql);
                $stmt->execute($params);
                // El id se toma ya: el libro escribe otras filas y lastInsertId()
                // devolveria el id de la ultima.
                $productoId = (int) $this->conexion->lastInsertId();
                // SU conexion y sin transaccion propia (false): el libro corre dentro
                // de esta, asi que un fallo suyo se deshace junto con el producto.
                $res = (new inventoryModel($this->conexion))->crearAjuste([
                    'motivo' => 'INVENTARIO_INICIAL',
                    'nota' => 'Existencia inicial al crear el producto.',
                    // El almacen del producto, no el primero que haya.
                    'warehouse_id' => $warehouseId,
                    'user_id' => $userId,
                    'lineas' => [['product_id' => $productoId, 'tipo' => 'INCREMENTO', 'cantidad' => $inicial]],
                ], false);
                if ($res[0] !== 'success') {
                    $this->conexion->rollBack();
                    // El texto del libro habla de un «ajuste» que quien crea un
                    // producto no pidio, y el producto no se creo: se le dice eso.
                    // Lo que dijo el libro queda en el log.
                    error_log('[productos] saveProduct: el libro no abrio la existencia inicial (' . $inicial . ') y el producto no se creo: ' . $res[1]);
                    return ['error', 'No se pudo guardar el producto con su existencia inicial. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.'];
                }
                $this->conexion->commit();
                return ['success', 'Product saved', $productoId];
            } catch (Throwable $e) {
                if ($this->conexion->inTransaction()) {
                    $this->conexion->rollBack();
                }
                throw $e;
            }
        } catch (PDOException $e) {
            $conflicto = $this->mensajeConflicto($e);
            if ($conflicto !== null) {
                return ['error', $conflicto];
            }
            error_log('[productos] saveProduct: ' . $e->getMessage());
            return ['error', 'No se pudo guardar el producto. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.'];
        }
    }

    /**
     * Actualiza el producto.
     *
     * Con el interruptor de existencias de la empresa encendido, el `stock` que
     * llegue se ignora en silencio y la fila conserva el que tiene (ver
     * existenciaAGuardarEnEdicion); un cliente viejo que lo siga reenviando no se
     * rompe. Ese saldo se lee con FOR UPDATE dentro de una transaccion y el UPDATE
     * va en ella: una venta que se lleva existencia entre la lectura del producto
     * y este UPDATE no queda pisada con el saldo viejo. Apagado, no hay
     * transaccion ni bloqueo: el UPDATE es el de siempre.
     */
    public function updateProduct($id, $data)
    {
        $transaccionPropia = false;
        try {
            $current = $this->getProducts($id);
            if (count($current) === 0) {
                return ['error', 'Este producto ya no existe; puede que otra persona lo haya eliminado. Cierra la ventana y actualiza la lista.'];
            }
            // En update, si no se envia warehouse_id se conserva el actual.
            $warehouseId = $this->resolveWarehouseId($data, (int) $current[0]['warehouse_id']);
            $estricto = self::inventarioEstricto();
            $actual = $current[0]['stock'] ?? null;
            if ($estricto) {
                $this->conexion->beginTransaction();
                $transaccionPropia = true;
                $bloqueo = $this->conexion->prepare('SELECT stock FROM products WHERE id = :id FOR UPDATE');
                $bloqueo->execute([':id' => (int) $id]);
                $fila = $bloqueo->fetch(PDO::FETCH_ASSOC);
                if (!$fila) {
                    // Lo borraron entre la lectura y el bloqueo.
                    $this->conexion->rollBack();
                    $transaccionPropia = false;
                    return ['error', 'Este producto ya no existe; puede que otra persona lo haya eliminado. Cierra la ventana y actualiza la lista.'];
                }
                // El saldo de ahora, ya con lo que movieron las ventas en curso.
                $actual = $fila['stock'] === null ? null : round((float) $fila['stock'], 3);
            }
            $sql = "UPDATE products SET
                sku = :sku, nombre = :nombre, descripcion = :descripcion,
                category_id = :category_id, warehouse_id = :warehouse_id,
                indicador_bien_servicio = :ibs, indicador_facturacion = :ifact,
                precio = :precio, precio_2 = :precio_2, precio_3 = :precio_3, precio_4 = :precio_4,
                costo = :costo, unidad_medida = :unidad_medida,
                stock = :stock, stock_minimo = :stock_minimo, activo = :activo
                WHERE id = :id";
            $stmt = $this->conexion->prepare($sql);
            $params = $this->bindParams($data, $warehouseId);
            $params[':stock'] = self::existenciaAGuardarEnEdicion($estricto, $data->stock ?? null, $actual);
            $params[':id'] = (int) $id;
            $stmt->execute($params);
            if ($transaccionPropia) {
                $this->conexion->commit();
            }
            return ['success', 'Product updated'];
        } catch (PDOException $e) {
            if ($transaccionPropia && $this->conexion->inTransaction()) {
                $this->conexion->rollBack();
            }
            $conflicto = $this->mensajeConflicto($e);
            if ($conflicto !== null) {
                return ['error', $conflicto];
            }
            error_log('[productos] updateProduct ' . $id . ': ' . $e->getMessage());
            return ['error', 'No se pudo actualizar el producto. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.'];
        }
    }

    /**
     * Fija (o quita, con null) la foto del producto (migracion 032). Devuelve la
     * ruta que tenia, para borrar ese archivo, o false si el producto no existe.
     *
     * @return string|null|false
     */
    public function cambiarImagen(int $id, ?string $imagenPath)
    {
        $stmt = $this->conexion->prepare('SELECT imagen_path FROM products WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($fila === false) {
            return false;
        }
        $stmt = $this->conexion->prepare('UPDATE products SET imagen_path = :p WHERE id = :id');
        $stmt->execute([':p' => $imagenPath, ':id' => $id]);
        return $fila['imagen_path'] !== null && $fila['imagen_path'] !== '' ? (string) $fila['imagen_path'] : null;
    }

    public function deleteProduct($id)
    {
        try {
            if (count($this->getProducts($id)) === 0) {
                return ['error', 'Este producto ya no existe; puede que otra persona lo haya eliminado.'];
            }
            $stmt = $this->conexion->prepare("DELETE FROM products WHERE id = :id");
            $stmt->execute([':id' => (int) $id]);
            return ['success', 'Product deleted'];
        } catch (PDOException $e) {
            // En un DELETE el 23000 solo puede ser una FK que lo impide. Hoy la
            // unica sin ON DELETE es inv_mov_product_fk: un producto que ya se
            // vendio, se compro o se ajusto no se borra, porque el kardex
            // perderia su historia. Antes caia en el "no se pudo" generico y el
            // usuario reintentaba sin saber que nunca iba a poder.
            if ((string) $e->getCode() === '23000') {
                $detalle = (string) ($e->errorInfo[2] ?? $e->getMessage());
                if (stripos($detalle, 'inv_mov_product_fk') !== false || stripos($detalle, 'inventory_movements') !== false) {
                    return ['error', 'No puedes eliminar este producto porque ya tiene movimientos de inventario (ventas, compras o ajustes). '
                        . 'Si ya no lo usas, desactívalo y guarda los cambios.'];
                }
                // Otra tabla que lo referencia (una FK futura): mismo consejo.
                error_log('[productos] deleteProduct ' . $id . ' bloqueado por FK: ' . $detalle);
                return ['error', 'No puedes eliminar este producto porque se usa en otros registros. Si ya no lo usas, desactívalo y guarda los cambios.'];
            }
            error_log('[productos] deleteProduct ' . $id . ': ' . $e->getMessage());
            return ['error', 'No se pudo eliminar el producto. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.'];
        }
    }

    /**
     * Texto para el usuario de un 23000 al guardar un producto, o null si no es
     * un conflicto conocido (el llamador lo registra y responde el generico).
     *
     * El SQLSTATE 23000 lo comparten dos casos que se arreglan distinto: el SKU
     * repetido (uk_sku, error 1062) y la categoria o el almacen que ya no
     * existen (FK, error 1452: otra persona los borro con el formulario
     * abierto). Con un solo mensaje para ambos, quien tenia la categoria borrada
     * buscaba un SKU repetido que no existia.
     */
    private function mensajeConflicto(PDOException $e): ?string
    {
        if ((string) $e->getCode() !== '23000') {
            return null;
        }
        $codigo = (int) ($e->errorInfo[1] ?? 0);
        if ($codigo === 1062) {
            return 'Ya hay otro producto con ese SKU. Usa uno diferente.';
        }
        if ($codigo === 1452) {
            // El nombre de la FK viene en el detalle de MySQL y dice cual de los dos falta.
            $detalle = (string) ($e->errorInfo[2] ?? $e->getMessage());
            if (stripos($detalle, 'fk_products_category') !== false) {
                return 'La categoría elegida ya no existe. Elige otra.';
            }
            if (stripos($detalle, 'fk_products_warehouse') !== false) {
                return 'El almacén elegido ya no existe. Elige otro.';
            }
            return 'La categoría o el almacén elegido ya no existe. Elige otro.';
        }
        return null;
    }

    /** id del almacen por defecto ("Almacén Principal"), o null si no existe. */
    private function getDefaultWarehouseId(): ?int
    {
        $stmt = $this->conexion->prepare("SELECT id FROM warehouses WHERE nombre = 'Almacén Principal' LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch();
        return $row ? (int) $row['id'] : null;
    }

    /** Resuelve el almacen: el enviado, o el fallback (actual en update), o el por defecto. */
    private function resolveWarehouseId($d, ?int $fallback): ?int
    {
        $wid = $d->warehouse_id ?? null;
        if ($wid !== null && $wid !== '' && (int) $wid > 0) {
            return (int) $wid;
        }
        return $fallback ?? $this->getDefaultWarehouseId();
    }

    /**
     * Unidad de medida (codigo DGII) con la que se guarda el producto: la
     * enviada o 43 (Unidad). El controlador juzga los decimales de la
     * existencia con esta misma unidad; si cada uno la resolviera a su manera,
     * se validaria contra una unidad y se guardaria otra.
     */
    public static function unidadDelPayload($d): string
    {
        $u = isset($d->unidad_medida) ? trim((string) $d->unidad_medida) : '';
        return $u === '' ? '43' : $u;
    }

    /**
     * Si la empresa tiene encendido «Bloquear ventas sin existencia»
     * (tenants.inventario_estricto, via TenantResolver). Sin empresa resuelta
     * (instalacion de un solo tenant, scripts) es false. Publica: el controlador
     * lo lee por aqui para no repetir la guarda de class_exists.
     */
    public static function inventarioEstricto(): bool
    {
        // class_exists: el modelo tambien corre en scripts sin empresa resuelta,
        // como inventoryModel.
        return class_exists('TenantResolver', false) && TenantResolver::inventarioEstricto();
    }

    /**
     * La cantidad que se registra por el libro al CREAR un producto: la existencia
     * que trae el formulario, solo con el interruptor encendido, para un Bien
     * (indicador_bien_servicio distinto de 2, como en el INSERT, que por defecto
     * es 1) y si es un numero distinto de 0 una vez redondeada a 3 decimales (lo
     * que guarda la columna; el libro descarta lo que queda en 0). En cualquier
     * otro caso null: nada que registrar y el INSERT queda como hoy.
     *
     * Una cantidad negativa se devuelve tal cual: se rechaza antes de guardar
     * (problemaExistenciaInicial), porque el libro de apertura solo suma.
     *
     * @param object $data el payload del formulario (stdClass del JSON)
     */
    public static function existenciaInicialDelLibro(object $data, bool $estricto): ?float
    {
        if (!$estricto || (int) ($data->indicador_bien_servicio ?? 1) === 2) {
            return null;
        }
        $enviada = $data->stock ?? null;
        if ($enviada === null || $enviada === '' || !is_numeric($enviada)) {
            return null;
        }
        $cantidad = round((float) $enviada, 3);
        return $cantidad === 0.0 ? null : $cantidad;
    }

    /**
     * El problema, para quien llena el formulario, de la existencia con que se
     * abre un producto nuevo; null si no hay ninguno. Hoy uno solo: con el
     * interruptor encendido el libro de apertura solo suma, y un producto que nace
     * en rojo dejaria sin vender lo que el interruptor quiere proteger. El
     * controlador lo responde con 422 (es un dato del formulario) y saveProduct lo
     * vuelve a mirar por si lo llaman sin pasar por el controlador. Con el
     * interruptor apagado, un servicio o sin existencia, no hay problema: el INSERT
     * guarda lo que venga, como siempre.
     *
     * @param object $data el payload del formulario (stdClass del JSON)
     */
    public static function problemaExistenciaInicial(object $data, bool $estricto): ?string
    {
        $inicial = self::existenciaInicialDelLibro($data, $estricto);
        if ($inicial !== null && $inicial < 0) {
            return 'La existencia inicial no puede ser negativa. Escribe 0 o más; para corregir existencias usa Ajuste de inventario.';
        }
        return null;
    }

    /**
     * El stock que escribe el UPDATE de la edicion. Con el interruptor encendido,
     * el que ya tiene el producto: el campo se ignora en silencio (la existencia
     * solo cambia por el libro). Apagado, lo enviado con 3 decimales o null si
     * vino vacio, exactamente como lo calcula bindParams.
     *
     * @param mixed  $enviada el stock del payload (puede faltar, ser texto o null)
     * @param ?float $actual  el stock guardado (null si el producto nunca tuvo)
     */
    public static function existenciaAGuardarEnEdicion(bool $estricto, $enviada, ?float $actual): ?float
    {
        if ($estricto) {
            return $actual;
        }
        return ($enviada === null || $enviada === '') ? null : round((float) $enviada, 3);
    }

    /**
     * El cuerpo que la bitacora guarda como `new_values` de un PUT de producto.
     * Con el interruptor encendido el UPDATE ignora el `stock` enviado y la fila
     * conserva el suyo (existenciaAGuardarEnEdicion): si la bitacora anotara el que
     * mando el cliente, un valor viejo o reenviado se leeria como un cambio de
     * existencia que nunca ocurrio. Por eso lleva el guardado. No se quita el
     * campo: la vista de la bitacora (public/audit_logs.html, OMITTED_BECOMES) lee
     * un `stock` que falta en un UPDATE de products como «se vacio». Apagado, o sin
     * fila guardada, es el cuerpo enviado tal cual, como hoy.
     *
     * @param object $enviados el payload del PUT (no se modifica)
     * @param ?array $guardado la fila de products leida antes de guardar (getProducts)
     */
    public static function nuevosValoresParaBitacora(object $enviados, ?array $guardado, bool $estricto): object
    {
        if (!$estricto || $guardado === null) {
            return $enviados;
        }
        $valores = clone $enviados;
        $valores->stock = $guardado['stock'] ?? null;
        return $valores;
    }

    /**
     * stock y stock_minimo como numero (o null en servicios). Eran INT y el
     * front los recibia como numero; como DECIMAL(15,3) MySQL los devuelve como
     * texto ("12.500") y el front comparaba textos ("10.000" <= "9.000" daba
     * true) o los pintaba tal cual. Mismas claves, solo cambia el tipo.
     */
    private static function existenciasComoNumero(array $filas): array
    {
        foreach ($filas as &$f) {
            foreach (['stock', 'stock_minimo'] as $col) {
                if (array_key_exists($col, $f) && $f[$col] !== null) {
                    $f[$col] = (float) $f[$col];
                }
            }
        }
        unset($f);
        return $filas;
    }

    /** Normaliza el payload (stdClass del JSON) a los parametros del INSERT/UPDATE. */
    private function bindParams($d, int $warehouseId): array
    {
        $str = function ($v) {
            $v = isset($v) ? trim((string) $v) : '';
            return $v === '' ? null : $v;
        };
        // Existencia y minimo con hasta 3 decimales (DECIMAL(15,3), migracion
        // 025). Con (int) cada vez que se guardaba el producto 12,5 m quedaban
        // en 12 sin movimiento en el libro. Que la unidad admita decimales lo
        // valida el controlador antes de llegar aqui.
        $cantidadOrNull = function ($v) {
            return ($v === null || $v === '') ? null : round((float) $v, 3);
        };
        $precioOpcional = function ($v) {
            return ($v === null || $v === '' || !is_numeric($v)) ? null : (float) $v;
        };
        $catId = $d->category_id ?? null;
        return [
            ':sku'           => $str($d->sku ?? null),
            ':nombre'        => trim((string) ($d->nombre ?? '')),
            ':descripcion'   => $str($d->descripcion ?? null),
            ':category_id'   => ($catId === null || $catId === '' || (int) $catId <= 0) ? null : (int) $catId,
            ':warehouse_id'  => $warehouseId,
            ':ibs'           => (int) ($d->indicador_bien_servicio ?? 1),
            ':ifact'         => (int) ($d->indicador_facturacion ?? 1),
            ':precio'        => (float) ($d->precio ?? 0),
            // Listas 2-4: NULL = "no aplica", distinto de 0.00 (precio cero).
            // Por eso no se castea a float lo vacio.
            ':precio_2'      => $precioOpcional($d->precio_2 ?? null),
            ':precio_3'      => $precioOpcional($d->precio_3 ?? null),
            ':precio_4'      => $precioOpcional($d->precio_4 ?? null),
            ':costo'         => (float) ($d->costo ?? 0),
            ':unidad_medida' => self::unidadDelPayload($d),
            ':stock'         => $cantidadOrNull($d->stock ?? null),
            ':stock_minimo'  => $cantidadOrNull($d->stock_minimo ?? null),
            ':activo'        => isset($d->activo) ? (int) (bool) $d->activo : 1,
        ];
    }
}
