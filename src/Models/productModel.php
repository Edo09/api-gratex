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
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }

    private function searchWhere(): string
    {
        return 'WHERE (p.nombre LIKE :query OR p.sku LIKE :query OR p.descripcion LIKE :query
                       OR c.nombre LIKE :query OR w.nombre LIKE :query)';
    }

    public function getProductsPaginated($offset, $limit, $query = null)
    {
        try {
            $where = $query ? $this->searchWhere() : '';
            $sql = self::SELECT_JOINED . " {$where} ORDER BY p.id DESC LIMIT :limit OFFSET :offset";
            $stmt = $this->conexion->prepare($sql);
            if ($query) {
                $stmt->bindValue(':query', "%{$query}%", PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', (int) $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', (int) $offset, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }

    public function getProductsCount($query = null)
    {
        try {
            $where = $query ? $this->searchWhere() : '';
            $sql = 'SELECT COUNT(*) AS total
                      FROM products p
                      LEFT JOIN categories c ON c.id = p.category_id
                      LEFT JOIN warehouses w ON w.id = p.warehouse_id ' . $where;
            $stmt = $this->conexion->prepare($sql);
            if ($query) {
                $stmt->execute([':query' => "%{$query}%"]);
            } else {
                $stmt->execute();
            }
            $row = $stmt->fetch();
            return $row ? (int) $row['total'] : 0;
        } catch (PDOException $e) {
            return 0;
        }
    }

    public function saveProduct($data)
    {
        try {
            $warehouseId = $this->resolveWarehouseId($data, null);
            if ($warehouseId === null) {
                return ['error', 'No hay un almacen disponible (falta "Almacén Principal").'];
            }
            $sql = "INSERT INTO products
                (sku, nombre, descripcion, category_id, warehouse_id, indicador_bien_servicio, indicador_facturacion,
                 precio, costo, unidad_medida, stock, stock_minimo, activo)
                VALUES
                (:sku, :nombre, :descripcion, :category_id, :warehouse_id, :ibs, :ifact,
                 :precio, :costo, :unidad_medida, :stock, :stock_minimo, :activo)";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute($this->bindParams($data, $warehouseId));
            return ['success', 'Product saved', (int) $this->conexion->lastInsertId()];
        } catch (PDOException $e) {
            if ((int) $e->getCode() === 23000) {
                return ['error', 'SKU duplicado o categoria/almacen inexistente.'];
            }
            return ['error', 'Failed to save product'];
        }
    }

    public function updateProduct($id, $data)
    {
        try {
            $current = $this->getProducts($id);
            if (count($current) === 0) {
                return ['error', "There is no product with ID {$id}"];
            }
            // En update, si no se envia warehouse_id se conserva el actual.
            $warehouseId = $this->resolveWarehouseId($data, (int) $current[0]['warehouse_id']);
            $sql = "UPDATE products SET
                sku = :sku, nombre = :nombre, descripcion = :descripcion,
                category_id = :category_id, warehouse_id = :warehouse_id,
                indicador_bien_servicio = :ibs, indicador_facturacion = :ifact,
                precio = :precio, costo = :costo, unidad_medida = :unidad_medida,
                stock = :stock, stock_minimo = :stock_minimo, activo = :activo
                WHERE id = :id";
            $stmt = $this->conexion->prepare($sql);
            $params = $this->bindParams($data, $warehouseId);
            $params[':id'] = (int) $id;
            $stmt->execute($params);
            return ['success', 'Product updated'];
        } catch (PDOException $e) {
            if ((int) $e->getCode() === 23000) {
                return ['error', 'SKU duplicado o categoria/almacen inexistente.'];
            }
            return ['error', 'Failed to update product'];
        }
    }

    public function deleteProduct($id)
    {
        try {
            if (count($this->getProducts($id)) === 0) {
                return ['error', "Product not found {$id}"];
            }
            $stmt = $this->conexion->prepare("DELETE FROM products WHERE id = :id");
            $stmt->execute([':id' => (int) $id]);
            return ['success', 'Product deleted'];
        } catch (PDOException $e) {
            return ['error', 'Failed to delete product'];
        }
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

    /** Normaliza el payload (stdClass del JSON) a los parametros del INSERT/UPDATE. */
    private function bindParams($d, int $warehouseId): array
    {
        $str = function ($v) {
            $v = isset($v) ? trim((string) $v) : '';
            return $v === '' ? null : $v;
        };
        $intOrNull = function ($v) {
            return ($v === null || $v === '') ? null : (int) $v;
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
            ':costo'         => (float) ($d->costo ?? 0),
            ':unidad_medida' => $str($d->unidad_medida ?? null) ?? '43',
            ':stock'         => $intOrNull($d->stock ?? null),
            ':stock_minimo'  => $intOrNull($d->stock_minimo ?? null),
            ':activo'        => isset($d->activo) ? (int) (bool) $d->activo : 1,
        ];
    }
}
