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
            $color = self::normalizarColor($data->color ?? null);
            $usado = $this->colorEnUso($color);
            if ($usado !== null) {
                return ['error', self::mensajeColorUsado($color, $usado)];
            }
            $sql = 'INSERT INTO categories (nombre, descripcion, color, estado) VALUES (:nombre, :descripcion, :color, :estado)';
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute($this->bindParams($data) + [':color' => $color]);
            return ['success', 'Categoria creada', (int) $this->conexion->lastInsertId()];
        } catch (PDOException $e) {
            if ((int) $e->getCode() === 23000) {
                return ['error', self::mensajeDuplicado($e, $color ?? null)];
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
            $params = $this->bindParams($data);
            $params[':id'] = (int) $id;
            // El color solo se toca si viene: un cliente que no lo conoce (o un PUT
            // sin el campo) no se lo borra.
            $conColor = is_object($data) && property_exists($data, 'color');
            $color = $conColor ? self::normalizarColor($data->color) : null;
            if ($conColor) {
                $usado = $this->colorEnUso($color, (int) $id);
                if ($usado !== null) {
                    return ['error', self::mensajeColorUsado($color, $usado)];
                }
                $params[':color'] = $color;
            }
            $sql = 'UPDATE categories SET nombre = :nombre, descripcion = :descripcion, estado = :estado'
                . ($conColor ? ', color = :color' : '') . ' WHERE id = :id';
            $this->conexion->prepare($sql)->execute($params);
            return ['success', 'Categoria actualizada'];
        } catch (PDOException $e) {
            if ((int) $e->getCode() === 23000) {
                return ['error', self::mensajeDuplicado($e, $color ?? null)];
            }
            error_log('[categorias] update ' . $id . ': ' . $e->getMessage());
            return ['error', 'No se pudo actualizar la categoría. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.'];
        }
    }

    /** true si $valor sirve como color: '#RRGGBB' (o vacio = sin color). */
    public static function colorValido($valor): bool
    {
        return $valor === null || trim((string) $valor) === ''
            || preg_match('/^#[0-9A-Fa-f]{6}$/', trim((string) $valor)) === 1;
    }

    /** '#rrggbb' -> '#RRGGBB'; vacio -> null. Asi la clave unica no distingue mayusculas. */
    private static function normalizarColor($valor): ?string
    {
        $v = strtoupper(trim((string) ($valor ?? '')));
        return $v === '' ? null : $v;
    }

    /**
     * Nombre de la categoria que ya usa $color (otra que $exceptoId), o null.
     * Se pregunta antes para decir CUAL lo usa; uk_cat_color (migracion 034)
     * sigue siendo la red si dos guardan a la vez.
     */
    private function colorEnUso(?string $color, ?int $exceptoId = null): ?string
    {
        if ($color === null) {
            return null;
        }
        $stmt = $this->conexion->prepare('SELECT nombre FROM categories WHERE color = :color AND id <> :id LIMIT 1');
        $stmt->execute([':color' => $color, ':id' => $exceptoId ?? 0]);
        $nombre = $stmt->fetchColumn();
        return $nombre === false ? null : (string) $nombre;
    }

    private static function mensajeColorUsado(string $color, string $categoria): string
    {
        return "El color {$color} ya lo usa la categoría «{$categoria}». Elige otro: cada categoría tiene el suyo.";
    }

    /** Texto de un 23000: la clave que choco es la del color o la del nombre. */
    private static function mensajeDuplicado(PDOException $e, ?string $color): string
    {
        if (str_contains($e->getMessage(), 'uk_cat_color')) {
            return 'El color ' . ($color ?? '') . ' ya lo usa otra categoría. Elige otro: cada categoría tiene el suyo.';
        }
        return 'Ya existe una categoría con ese nombre.';
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
