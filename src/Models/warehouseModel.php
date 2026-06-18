<?php
require_once(__DIR__ . '/../Database.php');

/**
 * Almacenes de inventario (tabla `warehouses`, DB del tenant).
 * Aislamiento por DB-per-tenant: una empresa solo ve sus almacenes.
 * `estado`: 1=activo | 0=inactivo. Borrado fisico con guardas:
 *   - no se borra el almacen por defecto "Almacén Principal";
 *   - FK products.warehouse_id ON DELETE RESTRICT: no se borra si tiene productos.
 */
class warehouseModel
{
    public const DEFAULT_NOMBRE = 'Almacén Principal';

    private $conexion;

    public function __construct()
    {
        $this->conexion = Database::getInstance()->getConnection();
    }

    public function getAll($id = null)
    {
        try {
            if ($id === null) {
                $stmt = $this->conexion->prepare('SELECT * FROM warehouses ORDER BY nombre ASC');
                $stmt->execute();
            } else {
                $stmt = $this->conexion->prepare('SELECT * FROM warehouses WHERE id = :id');
                $stmt->execute([':id' => (int) $id]);
            }
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }

    public function getPaginated($offset, $limit, $query = null)
    {
        try {
            $where = $query ? 'WHERE (nombre LIKE :query OR descripcion LIKE :query)' : '';
            $sql = "SELECT * FROM warehouses {$where} ORDER BY nombre ASC LIMIT :limit OFFSET :offset";
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

    public function getCount($query = null)
    {
        try {
            $where = $query ? 'WHERE (nombre LIKE :query OR descripcion LIKE :query)' : '';
            $stmt = $this->conexion->prepare("SELECT COUNT(*) AS total FROM warehouses {$where}");
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

    /** id del almacen por defecto, o null si no existe (no deberia pasar tras la migracion). */
    public function getDefaultId(): ?int
    {
        try {
            $stmt = $this->conexion->prepare('SELECT id FROM warehouses WHERE nombre = :n LIMIT 1');
            $stmt->execute([':n' => self::DEFAULT_NOMBRE]);
            $row = $stmt->fetch();
            return $row ? (int) $row['id'] : null;
        } catch (PDOException $e) {
            return null;
        }
    }

    public function save($data)
    {
        try {
            $sql = 'INSERT INTO warehouses (nombre, descripcion, estado) VALUES (:nombre, :descripcion, :estado)';
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute($this->bindParams($data));
            return ['success', 'Almacen creado', (int) $this->conexion->lastInsertId()];
        } catch (PDOException $e) {
            if ((int) $e->getCode() === 23000) {
                return ['error', 'Ya existe un almacen con ese nombre.'];
            }
            return ['error', 'No se pudo crear el almacen.'];
        }
    }

    public function update($id, $data)
    {
        try {
            if (count($this->getAll($id)) === 0) {
                return ['error', "No existe el almacen {$id}."];
            }
            $sql = 'UPDATE warehouses SET nombre = :nombre, descripcion = :descripcion, estado = :estado WHERE id = :id';
            $stmt = $this->conexion->prepare($sql);
            $params = $this->bindParams($data);
            $params[':id'] = (int) $id;
            $stmt->execute($params);
            return ['success', 'Almacen actualizado'];
        } catch (PDOException $e) {
            if ((int) $e->getCode() === 23000) {
                return ['error', 'Ya existe un almacen con ese nombre.'];
            }
            return ['error', 'No se pudo actualizar el almacen.'];
        }
    }

    public function delete($id)
    {
        try {
            $rows = $this->getAll($id);
            if (count($rows) === 0) {
                return ['error', "Almacen no encontrado {$id}."];
            }
            if (($rows[0]['nombre'] ?? '') === self::DEFAULT_NOMBRE) {
                return ['error', 'No se puede eliminar el almacen por defecto (Almacén Principal).'];
            }
            $this->conexion->prepare('DELETE FROM warehouses WHERE id = :id')->execute([':id' => (int) $id]);
            return ['success', 'Almacen eliminado'];
        } catch (PDOException $e) {
            if ((int) $e->getCode() === 23000) {
                return ['error', 'El almacen tiene productos asignados; reasignalos antes de eliminarlo.'];
            }
            return ['error', 'No se pudo eliminar el almacen.'];
        }
    }

    private function bindParams($d): array
    {
        $str = function ($v) {
            $v = isset($v) ? trim((string) $v) : '';
            return $v === '' ? null : $v;
        };
        return [
            ':nombre'      => trim((string) ($d->nombre ?? '')),
            ':descripcion' => $str($d->descripcion ?? null),
            ':estado'      => isset($d->estado) ? (int) (bool) $d->estado : 1,
        ];
    }
}
