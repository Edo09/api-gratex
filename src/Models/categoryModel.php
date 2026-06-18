<?php
require_once(__DIR__ . '/../Database.php');

/**
 * Categorias de inventario (tabla `categories`, DB del tenant).
 * Aislamiento por DB-per-tenant: una empresa solo ve sus categorias.
 * `estado`: 1=activo | 0=inactivo. Borrado fisico (FK products.category_id ON DELETE SET NULL).
 */
class categoryModel
{
    private $conexion;

    public function __construct()
    {
        $this->conexion = Database::getInstance()->getConnection();
    }

    public function getAll($id = null)
    {
        try {
            if ($id === null) {
                $stmt = $this->conexion->prepare('SELECT * FROM categories ORDER BY nombre ASC');
                $stmt->execute();
            } else {
                $stmt = $this->conexion->prepare('SELECT * FROM categories WHERE id = :id');
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
            $sql = "SELECT * FROM categories {$where} ORDER BY nombre ASC LIMIT :limit OFFSET :offset";
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
            $stmt = $this->conexion->prepare("SELECT COUNT(*) AS total FROM categories {$where}");
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

    public function save($data)
    {
        try {
            $sql = 'INSERT INTO categories (nombre, descripcion, estado) VALUES (:nombre, :descripcion, :estado)';
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute($this->bindParams($data));
            return ['success', 'Categoria creada', (int) $this->conexion->lastInsertId()];
        } catch (PDOException $e) {
            if ((int) $e->getCode() === 23000) {
                return ['error', 'Ya existe una categoria con ese nombre.'];
            }
            return ['error', 'No se pudo crear la categoria.'];
        }
    }

    public function update($id, $data)
    {
        try {
            if (count($this->getAll($id)) === 0) {
                return ['error', "No existe la categoria {$id}."];
            }
            $sql = 'UPDATE categories SET nombre = :nombre, descripcion = :descripcion, estado = :estado WHERE id = :id';
            $stmt = $this->conexion->prepare($sql);
            $params = $this->bindParams($data);
            $params[':id'] = (int) $id;
            $stmt->execute($params);
            return ['success', 'Categoria actualizada'];
        } catch (PDOException $e) {
            if ((int) $e->getCode() === 23000) {
                return ['error', 'Ya existe una categoria con ese nombre.'];
            }
            return ['error', 'No se pudo actualizar la categoria.'];
        }
    }

    public function delete($id)
    {
        try {
            if (count($this->getAll($id)) === 0) {
                return ['error', "Categoria no encontrada {$id}."];
            }
            // FK products.category_id ON DELETE SET NULL: los productos quedan sin categoria.
            $this->conexion->prepare('DELETE FROM categories WHERE id = :id')->execute([':id' => (int) $id]);
            return ['success', 'Categoria eliminada'];
        } catch (PDOException $e) {
            return ['error', 'No se pudo eliminar la categoria.'];
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
