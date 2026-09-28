<?php
require_once(__DIR__ . '/../Database.php');

/**
 * Almacenes de inventario (tabla `warehouses`, DB del tenant).
 * Aislamiento por DB-per-tenant: una empresa solo ve sus almacenes.
 * `estado`: 1=activo | 0=inactivo. Borrado fisico con guardas:
 *   - no se borra el almacen por defecto "Almacén Principal";
 *   - FK products.warehouse_id ON DELETE RESTRICT: no se borra si tiene productos;
 *   - FKs inv_adj_warehouse_fk / inv_mov_warehouse_fk: tampoco si ya tuvo ajustes
 *     o movimientos de inventario, aunque hoy no le quede ningun producto.
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
            // Vacio = "no existe" para quien llama: sin el log, una caida de la
            // DB se confundiria con un almacen borrado.
            error_log('[almacenes] getAll: ' . $e->getMessage());
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
            error_log('[almacenes] getPaginated: ' . $e->getMessage());
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
            error_log('[almacenes] getCount: ' . $e->getMessage());
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
            error_log('[almacenes] getDefaultId: ' . $e->getMessage());
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
            // uk_wh_nombre es la unica restriccion que un INSERT puede romper.
            if ((int) $e->getCode() === 23000) {
                return ['error', 'Ya existe un almacén con ese nombre.'];
            }
            error_log('[almacenes] save: ' . $e->getMessage());
            return ['error', 'No se pudo crear el almacén. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.'];
        }
    }

    public function update($id, $data)
    {
        try {
            if (count($this->getAll($id)) === 0) {
                return ['error', 'Este almacén ya no existe; puede que otra persona lo haya eliminado.'];
            }
            $sql = 'UPDATE warehouses SET nombre = :nombre, descripcion = :descripcion, estado = :estado WHERE id = :id';
            $stmt = $this->conexion->prepare($sql);
            $params = $this->bindParams($data);
            $params[':id'] = (int) $id;
            $stmt->execute($params);
            return ['success', 'Almacen actualizado'];
        } catch (PDOException $e) {
            if ((int) $e->getCode() === 23000) {
                return ['error', 'Ya existe un almacén con ese nombre.'];
            }
            error_log('[almacenes] update ' . $id . ': ' . $e->getMessage());
            return ['error', 'No se pudo actualizar el almacén. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.'];
        }
    }

    public function delete($id)
    {
        try {
            $rows = $this->getAll($id);
            if (count($rows) === 0) {
                return ['error', 'Este almacén ya no existe; puede que otra persona lo haya eliminado.'];
            }
            if (($rows[0]['nombre'] ?? '') === self::DEFAULT_NOMBRE) {
                return ['error', 'No se puede eliminar el almacén por defecto (Almacén Principal).'];
            }
            $this->conexion->prepare('DELETE FROM warehouses WHERE id = :id')->execute([':id' => (int) $id]);
            return ['success', 'Almacen eliminado'];
        } catch (PDOException $e) {
            if ((int) $e->getCode() === 23000) {
                return ['error', $this->mensajeBorradoBloqueado($id, $e)];
            }
            error_log('[almacenes] delete ' . $id . ': ' . $e->getMessage());
            return ['error', 'No se pudo eliminar el almacén. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.'];
        }
    }

    /**
     * Por que no se pudo borrar un almacen (23000 en el DELETE = una FK lo impide).
     *
     * Tres FKs apuntan a warehouses y se arreglan distinto: con productos
     * (fk_products_warehouse) basta pasarlos a otro almacen; con ajustes o
     * movimientos (inv_adj_warehouse_fk / inv_mov_warehouse_fk) no hay arreglo,
     * porque el historial no se borra. Antes todo decia "tiene productos
     * asignados", y a un almacen ya vacio pero con historial reasignar no le
     * servia de nada. El nombre de la FK viene en el detalle de MySQL.
     */
    private function mensajeBorradoBloqueado($id, PDOException $e): string
    {
        $detalle = (string) ($e->errorInfo[2] ?? $e->getMessage());
        if (stripos($detalle, 'fk_products_warehouse') !== false) {
            return 'No puedes eliminar este almacén porque tiene productos asignados. '
                . 'Pásalos a otro almacén o, si ya no lo usas, desactívalo.';
        }
        if (stripos($detalle, 'inv_adj_warehouse_fk') !== false || stripos($detalle, 'inv_mov_warehouse_fk') !== false) {
            return 'No puedes eliminar este almacén porque ya tiene movimientos de inventario (ajustes, ventas o compras). '
                . 'Si ya no lo usas, desactívalo y guarda los cambios.';
        }
        error_log('[almacenes] delete ' . $id . ' bloqueado por FK: ' . $detalle);
        return 'No puedes eliminar este almacén porque tiene productos o movimientos de inventario. '
            . 'Si ya no lo usas, desactívalo y guarda los cambios.';
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
