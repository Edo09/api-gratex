<?php
require_once(__DIR__ . '/../Database.php');

class clientModel
{
    private $conexion;

    public function __construct()
    {
        $this->conexion = Database::getInstance()->getConnection();
    }

    public function getClients($id = null)
    {
        try {
            if ($id == null) {
                $sql = "SELECT * FROM clients";
                $stmt = $this->conexion->prepare($sql);
                $stmt->execute();
            } else {
                $sql = "SELECT * FROM clients WHERE id = :id";
                $stmt = $this->conexion->prepare($sql);
                $stmt->execute([':id' => $id]);
            }
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }

    public function saveClient($email, $client_name, $company_name, $phone_number, $rnc = null, $descuento = null, $permitirCredito = null)
    {
        try {
            $valida = $this->validateClients($email, $client_name, $company_name, $phone_number);
            $resultado = ['error', 'This client already exists'];
            if (count($valida) == 0) {
                $sql = "INSERT INTO clients(email, client_name, company_name, phone_number, rnc, razon_social, descuento, permitir_credito)
                    VALUES(:email, :client_name, :company_name, :phone_number, :rnc, :razon_social, :descuento, :permitir_credito)";
                $stmt = $this->conexion->prepare($sql);
                $stmt->execute([
                    ':email' => $email,
                    ':client_name' => $client_name,
                    ':company_name' => $company_name,
                    ':phone_number' => $phone_number,
                    ':rnc' => $this->normalizeRnc($rnc),
                    ':razon_social' => $company_name,
                    // Las columnas son NOT NULL: en alta, "no enviado" = 0.
                    ':descuento' => $this->normalizeDescuento($descuento) ?? 0.0,
                    ':permitir_credito' => $this->normalizeCredito($permitirCredito) ?? 0
                ]);
                $resultado = ['success', 'Client saved', (int) $this->conexion->lastInsertId()];
            }
            return $resultado;
        } catch (PDOException $e) {
            return ['error', 'Failed to save client'];
        }
    }

    public function updateClient($id, $email, $client_name, $company_name, $phone_number, $rnc = null, $descuento = null, $permitirCredito = null)
    {
        try {
            $existe = $this->getClients($id);
            $resultado = ['error', "There is no client with ID {$id}"];
            if (count($existe) > 0) {
                $valida = $this->validateClients($email, $client_name, $company_name, $phone_number);
                $resultado = ['error', 'This client already exists'];
                if (count($valida) == 0) {
                    $sql = "UPDATE clients SET email = :email, client_name = :client_name, company_name = :company_name,
                        phone_number = :phone_number, rnc = :rnc, razon_social = :razon_social,
                        descuento = COALESCE(:descuento, descuento),
                        permitir_credito = COALESCE(:permitir_credito, permitir_credito) WHERE id = :id";
                    $stmt = $this->conexion->prepare($sql);
                    $stmt->execute([
                        ':id' => $id,
                        ':email' => $email,
                        ':client_name' => $client_name,
                        ':company_name' => $company_name,
                        ':phone_number' => $phone_number,
                        ':rnc' => $this->normalizeRnc($rnc),
                        ':razon_social' => $company_name,
                        // null = el PUT no las mando: el COALESCE del SQL conserva
                        // lo que ya tenia. Asi un cliente de otro consumidor del
                        // API no pierde su descuento ni su credito al editarlo.
                        ':descuento' => $this->normalizeDescuento($descuento),
                        ':permitir_credito' => $this->normalizeCredito($permitirCredito)
                    ]);
                    $resultado = ['success', 'Client updated'];
                }
            }
            return $resultado;
        } catch (PDOException $e) {
            return ['error', 'Failed to update client'];
        }
    }

    public function deleteClient($id)
    {
        try {
            $valida = $this->getClients($id);
            $resultado = ['error', "Client not found {$id}"];
            if (count($valida) > 0) {
                $sql = "DELETE FROM clients WHERE id = :id";
                $stmt = $this->conexion->prepare($sql);
                $stmt->execute([':id' => $id]);
                $resultado = ['success', 'Client deleted'];
            }
            return $resultado;
        } catch (PDOException $e) {
            return ['error', 'Failed to delete client'];
        }
    }

    private function normalizeRnc($rnc): ?string
    {
        if ($rnc === null) {
            return null;
        }
        $digits = preg_replace('/\D/', '', (string) $rnc);
        return $digits === '' ? null : $digits;
    }

    /**
     * Descuento del cliente en %. Las columnas son NOT NULL DEFAULT 0, asi que
     * null/vacio = 0 (sin descuento), no "conservar el anterior". Se acota a
     * 0-100 para que un dato sucio del catalogo anterior no entre como 999.
     */
    private function normalizeDescuento($valor): ?float
    {
        if ($valor === null || $valor === '' || !is_numeric($valor)) {
            return null;
        }
        return max(0.0, min(100.0, round((float) $valor, 2)));
    }

    /**
     * permitir_credito: cualquier cosa distinta de 1 es 0 (solo contado).
     * null solo cuando no vino en el request, para poder distinguir "ponlo en 0"
     * de "no lo toques".
     */
    private function normalizeCredito($valor): ?int
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        return ((int) $valor) === 1 ? 1 : 0;
    }

    // Duplicate check intentionally ignores rnc and company_name: multiple
    // clients may share the same RNC or company_name (e.g. several contacts of
    // the same company). Only an exact email + client_name + phone match counts
    // as a duplicate, to guard against accidental re-submission.
    public function validateClients($email, $client_name, $company_name, $phone_number)
    {
        try {
            $sql = "SELECT * FROM clients WHERE email = :email AND client_name = :client_name AND phone_number = :phone_number";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([
                ':email' => $email,
                ':client_name' => $client_name,
                ':phone_number' => $phone_number
            ]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }

    public function getClientsPaginated($offset, $limit, $query = null)
    {
        try {
            $whereClause = "";
            if ($query) {
                $whereClause = "WHERE (client_name LIKE :query OR company_name LIKE :query OR email LIKE :query OR phone_number LIKE :query OR rnc LIKE :query)";
            }
            $sql = "SELECT * FROM clients {$whereClause} ORDER BY id DESC LIMIT :limit OFFSET :offset";
            $stmt = $this->conexion->prepare($sql);
            if ($query) {
                $stmt->bindValue(':query', "%{$query}%", \PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', (int)$limit, \PDO::PARAM_INT);
            $stmt->bindValue(':offset', (int)$offset, \PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }

    public function getClientsCount($query = null)
    {
        try {
            $whereClause = "";
            if ($query) {
                $whereClause = "WHERE (client_name LIKE :query OR company_name LIKE :query OR email LIKE :query OR phone_number LIKE :query OR rnc LIKE :query)";
            }
            $sql = "SELECT COUNT(*) as total FROM clients {$whereClause}";
            $stmt = $this->conexion->prepare($sql);
            if ($query) {
                $stmt->execute([':query' => "%{$query}%"]);
            } else {
                $stmt->execute();
            }
            $row = $stmt->fetch();
            return $row ? (int)$row['total'] : 0;
        } catch (PDOException $e) {
            return 0;
        }
    }
}
