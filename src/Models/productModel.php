<?php
require_once(__DIR__ . '/../Database.php');

/**
 * Catalogo de productos/servicios (tabla `products`, DB del tenant).
 * `indicador_facturacion`: 1=ITBIS 18% (gravado) | 2=16% | 3=Tasa cero | 4=Exento | 0=No facturable.
 * `indicador_bien_servicio`: 1=Bien | 2=Servicio.
 *
 * Inventario: `category_id` (FK categories, opcional) y `warehouse_id` (FK warehouses,
 * obligatorio). Si no se envia warehouse_id al crear, se asigna "Almacén Principal".
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

    public function saveProduct($data)
    {
        try {
            $warehouseId = $this->resolveWarehouseId($data, null);
            if ($warehouseId === null) {
                // Sin almacen elegido se usa "Almacén Principal"; si no aparece es
                // que no hay almacenes o que lo renombraron. El texto cubre ambos.
                return ['error', 'Elige el almacén del producto. Si no tienes ninguno, créalo primero en Almacenes.'];
            }
            $sql = "INSERT INTO products
                (sku, nombre, descripcion, category_id, warehouse_id, indicador_bien_servicio, indicador_facturacion,
                 precio, precio_2, precio_3, precio_4, costo, unidad_medida, stock, stock_minimo, activo)
                VALUES
                (:sku, :nombre, :descripcion, :category_id, :warehouse_id, :ibs, :ifact,
                 :precio, :precio_2, :precio_3, :precio_4, :costo, :unidad_medida, :stock, :stock_minimo, :activo)";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute($this->bindParams($data, $warehouseId));
            return ['success', 'Product saved', (int) $this->conexion->lastInsertId()];
        } catch (PDOException $e) {
            $conflicto = $this->mensajeConflicto($e);
            if ($conflicto !== null) {
                return ['error', $conflicto];
            }
            error_log('[productos] saveProduct: ' . $e->getMessage());
            return ['error', 'No se pudo guardar el producto. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.'];
        }
    }

    public function updateProduct($id, $data)
    {
        try {
            $current = $this->getProducts($id);
            if (count($current) === 0) {
                return ['error', 'Este producto ya no existe; puede que otra persona lo haya eliminado. Cierra la ventana y actualiza la lista.'];
            }
            // En update, si no se envia warehouse_id se conserva el actual.
            $warehouseId = $this->resolveWarehouseId($data, (int) $current[0]['warehouse_id']);
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
            $params[':id'] = (int) $id;
            $stmt->execute($params);
            return ['success', 'Product updated'];
        } catch (PDOException $e) {
            $conflicto = $this->mensajeConflicto($e);
            if ($conflicto !== null) {
                return ['error', $conflicto];
            }
            error_log('[productos] updateProduct ' . $id . ': ' . $e->getMessage());
            return ['error', 'No se pudo actualizar el producto. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.'];
        }
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
