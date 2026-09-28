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
            // Vacio = "no existe" para quien llama: sin el log, una caida de la
            // DB se confundiria con una categoria borrada.
            error_log('[categorias] getAll: ' . $e->getMessage());
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
            error_log('[categorias] getPaginated: ' . $e->getMessage());
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
            error_log('[categorias] getCount: ' . $e->getMessage());
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
            // uk_cat_nombre es la unica restriccion que un INSERT puede romper.
            if ((int) $e->getCode() === 23000) {
                return ['error', 'Ya existe una categoría con ese nombre.'];
            }
            error_log('[categorias] save: ' . $e->getMessage());
            return ['error', 'No se pudo crear la categoría. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.'];
        }
    }

    public function update($id, $data)
    {
        try {
            if (count($this->getAll($id)) === 0) {
                return ['error', 'Esta categoría ya no existe; puede que otra persona la haya eliminado.'];
            }
            $sql = 'UPDATE categories SET nombre = :nombre, descripcion = :descripcion, estado = :estado WHERE id = :id';
            $stmt = $this->conexion->prepare($sql);
            $params = $this->bindParams($data);
            $params[':id'] = (int) $id;
            $stmt->execute($params);
            return ['success', 'Categoria actualizada'];
        } catch (PDOException $e) {
            if ((int) $e->getCode() === 23000) {
                return ['error', 'Ya existe una categoría con ese nombre.'];
            }
            error_log('[categorias] update ' . $id . ': ' . $e->getMessage());
            return ['error', 'No se pudo actualizar la categoría. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.'];
        }
    }

    public function delete($id)
    {
        try {
            if (count($this->getAll($id)) === 0) {
                return ['error', 'Esta categoría ya no existe; puede que otra persona la haya eliminado.'];
            }
            // FK products.category_id ON DELETE SET NULL: los productos quedan sin categoria.
            $this->conexion->prepare('DELETE FROM categories WHERE id = :id')->execute([':id' => (int) $id]);
            return ['success', 'Categoria eliminada'];
        } catch (PDOException $e) {
            error_log('[categorias] delete ' . $id . ': ' . $e->getMessage());
            return ['error', 'No se pudo eliminar la categoría. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.'];
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
