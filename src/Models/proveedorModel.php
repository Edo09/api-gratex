<?php
require_once(__DIR__ . '/../Database.php');

/**
 * Directorio de proveedores (tabla `proveedores`, DB del tenant).
 * `compras` se deriva uniendo gastos por rnc_proveedor (no se almacena).
 */
class proveedorModel
{
    private $conexion;

    /** Subquery del conteo de compras/gastos asociados al RNC del proveedor. */
    private const COMPRAS_SUBQUERY =
        '(SELECT COUNT(*) FROM gastos g WHERE p.rnc IS NOT NULL AND g.rnc_proveedor = p.rnc) AS compras';

    public function __construct()
    {
        $this->conexion = Database::getInstance()->getConnection();
    }

    public function getProveedores($id = null)
    {
        try {
            $select = 'SELECT p.*, ' . self::COMPRAS_SUBQUERY . ' FROM proveedores p';
            if ($id === null) {
                $stmt = $this->conexion->prepare("$select ORDER BY p.id DESC");
                $stmt->execute();
            } else {
                $stmt = $this->conexion->prepare("$select WHERE p.id = :id");
                $stmt->execute([':id' => $id]);
            }
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }

    public function getProveedoresPaginated($offset, $limit, $query = null)
    {
        try {
            $whereClause = "";
            if ($query) {
                $whereClause = "WHERE (p.nombre LIKE :query OR p.rnc LIKE :query OR p.contacto LIKE :query OR p.telefono LIKE :query OR p.correo LIKE :query)";
            }
            $sql = 'SELECT p.*, ' . self::COMPRAS_SUBQUERY . " FROM proveedores p {$whereClause} ORDER BY p.id DESC LIMIT :limit OFFSET :offset";
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

    public function getProveedoresCount($query = null)
    {
        try {
            $whereClause = "";
            if ($query) {
                $whereClause = "WHERE (p.nombre LIKE :query OR p.rnc LIKE :query OR p.contacto LIKE :query OR p.telefono LIKE :query OR p.correo LIKE :query)";
            }
            $stmt = $this->conexion->prepare("SELECT COUNT(*) AS total FROM proveedores p {$whereClause}");
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

    public function saveProveedor($data)
    {
        try {
            $sql = "INSERT INTO proveedores (rnc, nombre, contacto, telefono, correo, direccion, notas, activo)
                    VALUES (:rnc, :nombre, :contacto, :telefono, :correo, :direccion, :notas, :activo)";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute($this->bindParams($data));
            return ['success', 'Proveedor saved', (int) $this->conexion->lastInsertId()];
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                return ['error', 'Ya existe un proveedor con ese RNC'];
            }
            return ['error', 'Failed to save proveedor'];
        }
    }

    public function updateProveedor($id, $data)
    {
        try {
            if (count($this->getProveedores($id)) === 0) {
                return ['error', "There is no proveedor with ID {$id}"];
            }
            $sql = "UPDATE proveedores SET
                rnc = :rnc, nombre = :nombre, contacto = :contacto, telefono = :telefono,
                correo = :correo, direccion = :direccion, notas = :notas, activo = :activo
                WHERE id = :id";
            $stmt = $this->conexion->prepare($sql);
            $params = $this->bindParams($data);
            $params[':id'] = (int) $id;
            $stmt->execute($params);
            return ['success', 'Proveedor updated'];
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                return ['error', 'Ya existe un proveedor con ese RNC'];
            }
            return ['error', 'Failed to update proveedor'];
        }
    }

    public function deleteProveedor($id)
    {
        try {
            if (count($this->getProveedores($id)) === 0) {
                return ['error', "Proveedor not found {$id}"];
            }
            $stmt = $this->conexion->prepare("DELETE FROM proveedores WHERE id = :id");
            $stmt->execute([':id' => $id]);
            return ['success', 'Proveedor deleted'];
        } catch (PDOException $e) {
            return ['error', 'Failed to delete proveedor'];
        }
    }

    /** Normaliza el payload (stdClass del JSON) a los parametros del INSERT/UPDATE. */
    private function bindParams($d): array
    {
        $str = function ($v) {
            $v = isset($v) ? trim((string) $v) : '';
            return $v === '' ? null : $v;
        };
        $rnc = $str($d->rnc ?? null);
        if ($rnc !== null) {
            $digits = preg_replace('/\D/', '', $rnc);
            $rnc = $digits === '' ? null : $digits;
        }
        return [
            ':rnc'       => $rnc,
            ':nombre'    => trim((string) ($d->nombre ?? '')),
            ':contacto'  => $str($d->contacto ?? null),
            ':telefono'  => $str($d->telefono ?? null),
            ':correo'    => $str($d->correo ?? null),
            ':direccion' => $str($d->direccion ?? null),
            ':notas'     => $str($d->notas ?? null),
            ':activo'    => isset($d->activo) ? (int) (bool) $d->activo : 1,
        ];
    }
}
