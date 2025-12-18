<?php
require_once(__DIR__ . '/../Database.php');

class cotizacionModel
{
    private $conexion;

    public function __construct()
    {
        $this->conexion = Database::getInstance()->getConnection();
    }

    public function getCotizaciones($id = null, $page = 1, $pageSize = 10, $query = null)
    {
        try {
            if ($id == null) {
                // Build WHERE clause for query search
                $whereClause = "";
                $params = [];
                
                if ($query) {
                    $whereClause = "WHERE (code LIKE :query OR client LIKE :query OR description LIKE :query)";
                    $params[':query'] = "%{$query}%";
                }
                
                // Get total count
                $countSql = "SELECT COUNT(*) as total FROM cotizaciones {$whereClause}";
                $countStmt = $this->conexion->prepare($countSql);
                if ($query) {
                    $countStmt->execute($params);
                } else {
                    $countStmt->execute();
                }
                $countResult = $countStmt->fetch();
                $total = $countResult['total'];
                
                // Calculate offset
                $offset = ((int)$page - 1) * (int)$pageSize;
                $pageSize = (int)$pageSize;
                $offset = (int)$offset;
                
                // Fetch paginated results
                $sql = "SELECT * FROM cotizaciones {$whereClause} LIMIT {$pageSize} OFFSET {$offset}";
                $stmt = $this->conexion->prepare($sql);
                if ($query) {
                    $stmt->execute($params);
                } else {
                    $stmt->execute();
                }
                $data = $stmt->fetchAll();
                
                return [
                    'total' => (int)$total,
                    'page' => (int)$page,
                    'pageSize' => (int)$pageSize,
                    'data' => $data
                ];
            } else {
                // Get single record by ID
                $sql = "SELECT * FROM cotizaciones WHERE id = :id";
                $stmt = $this->conexion->prepare($sql);
                $stmt->execute([':id' => $id]);
                return $stmt->fetchAll();
            }
        } catch (PDOException $e) {
            return ($id == null) ? ['total' => 0, 'page' => 0, 'pageSize' => 0, 'data' => []] : [];
        }
    }

    public function saveCotizacion($code, $amount, $client, $description)
    {
        try {
            $valida = $this->validateCotizaciones($code);
            if (count($valida) == 0) {
                $date = date('Y-m-d H:i:s');
                $sql = "INSERT INTO cotizaciones(code, date, amount, client, description) VALUES(:code, :date, :amount, :client, :description)";
                $stmt = $this->conexion->prepare($sql);
                $stmt->execute([
                    ':code' => $code,
                    ':date' => $date,
                    ':amount' => $amount,
                    ':client' => $client,
                    ':description' => $description
                ]);
                return ['success', 'Cotization saved'];
            }
            return ['error', 'This quotation code already exists'];
        } catch (PDOException $e) {
            return ['error', 'Failed to save cotization'];
        }
    }

    public function updateCotizacion($id, $code, $amount, $client, $description)
    {
        try {
            $existe = $this->getCotizaciones($id);
            if (count($existe) == 0) {
                return ['error', "There is no cotization with ID {$id}"];
            }
            
            $valida = $this->validateCotizaciones($code);
            if (count($valida) > 0 && $valida[0]['id'] != $id) {
                return ['error', 'This quotation code already exists'];
            }
            
            $sql = "UPDATE cotizaciones SET code = :code, amount = :amount, client = :client, description = :description WHERE id = :id";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([
                ':id' => $id,
                ':code' => $code,
                ':amount' => $amount,
                ':client' => $client,
                ':description' => $description
            ]);
            return ['success', 'Cotization updated'];
        } catch (PDOException $e) {
            return ['error', 'Failed to update cotization'];
        }
    }

    public function deleteCotizacion($id)
    {
        try {
            $valida = $this->getCotizaciones($id);
            if (count($valida) == 0) {
                return ['error', "Cotization not found {$id}"];
            }
            
            $sql = "DELETE FROM cotizaciones WHERE id = :id";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([':id' => $id]);
            return ['success', 'Cotization deleted'];
        } catch (PDOException $e) {
            return ['error', 'Failed to delete cotization'];
        }
    }

    public function validateCotizaciones($code)
    {
        try {
            $sql = "SELECT * FROM cotizaciones WHERE code = :code";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([':code' => $code]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }
}
?>