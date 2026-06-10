<?php
require_once(__DIR__ . '/../Database.php');

/**
 * Catalogo de productos/servicios (tabla `products`, DB del tenant).
 * `indicador_facturacion`: 1=ITBIS 18% (gravado) | 2=16% | 3=Tasa cero | 4=Exento | 0=No facturable.
 * `indicador_bien_servicio`: 1=Bien | 2=Servicio.
 */
class productModel
{
    private $conexion;

    public function __construct()
    {
        $this->conexion = Database::getInstance()->getConnection();
    }

    public function getProducts($id = null)
    {
        try {
            if ($id === null) {
                $stmt = $this->conexion->prepare("SELECT * FROM products ORDER BY id DESC");
                $stmt->execute();
            } else {
                $stmt = $this->conexion->prepare("SELECT * FROM products WHERE id = :id");
                $stmt->execute([':id' => $id]);
            }
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }

    public function getProductsPaginated($offset, $limit, $query = null)
    {
        try {
            $whereClause = "";
            if ($query) {
                $whereClause = "WHERE (nombre LIKE :query OR sku LIKE :query OR descripcion LIKE :query OR categoria LIKE :query)";
            }
            $sql = "SELECT * FROM products {$whereClause} ORDER BY id DESC LIMIT :limit OFFSET :offset";
            $stmt = $this->conexion->prepare($sql);
            if ($query) {
                $stmt->bindValue(':query', "%{$query}%", \PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', (int) $limit, \PDO::PARAM_INT);
            $stmt->bindValue(':offset', (int) $offset, \PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }

    public function getProductsCount($query = null)
    {
        try {
            $whereClause = "";
            if ($query) {
                $whereClause = "WHERE (nombre LIKE :query OR sku LIKE :query OR descripcion LIKE :query OR categoria LIKE :query)";
            }
            $sql = "SELECT COUNT(*) AS total FROM products {$whereClause}";
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
            $sql = "INSERT INTO products
                (sku, nombre, descripcion, categoria, indicador_bien_servicio, indicador_facturacion,
                 precio, costo, unidad_medida, stock, stock_minimo, activo)
                VALUES
                (:sku, :nombre, :descripcion, :categoria, :ibs, :ifact,
                 :precio, :costo, :unidad_medida, :stock, :stock_minimo, :activo)";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute($this->bindParams($data));
            return ['success', 'Product saved', (int) $this->conexion->lastInsertId()];
        } catch (PDOException $e) {
            return ['error', 'Failed to save product'];
        }
    }

    public function updateProduct($id, $data)
    {
        try {
            if (count($this->getProducts($id)) === 0) {
                return ['error', "There is no product with ID {$id}"];
            }
            $sql = "UPDATE products SET
                sku = :sku, nombre = :nombre, descripcion = :descripcion, categoria = :categoria,
                indicador_bien_servicio = :ibs, indicador_facturacion = :ifact,
                precio = :precio, costo = :costo, unidad_medida = :unidad_medida,
                stock = :stock, stock_minimo = :stock_minimo, activo = :activo
                WHERE id = :id";
            $stmt = $this->conexion->prepare($sql);
            $params = $this->bindParams($data);
            $params[':id'] = (int) $id;
            $stmt->execute($params);
            return ['success', 'Product updated'];
        } catch (PDOException $e) {
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
            $stmt->execute([':id' => $id]);
            return ['success', 'Product deleted'];
        } catch (PDOException $e) {
            return ['error', 'Failed to delete product'];
        }
    }

    /** Normaliza el payload (stdClass del JSON) a los parametros del INSERT/UPDATE. */
    private function bindParams($d): array
    {
        $str = function ($v) {
            $v = isset($v) ? trim((string) $v) : '';
            return $v === '' ? null : $v;
        };
        $intOrNull = function ($v) {
            return ($v === null || $v === '') ? null : (int) $v;
        };
        return [
            ':sku'           => $str($d->sku ?? null),
            ':nombre'        => trim((string) ($d->nombre ?? '')),
            ':descripcion'   => $str($d->descripcion ?? null),
            ':categoria'     => $str($d->categoria ?? null),
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
