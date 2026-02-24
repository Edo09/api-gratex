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
            var_dump($e->getMessage());
            return [];
        }
    }

    public function saveClient($email, $client_name, $company_name, $phone_number)
    {
        try {
            $valida = $this->validateClients($email, $client_name, $company_name, $phone_number);
            $resultado = ['error', 'This client already exists'];
            if (count($valida) == 0) {
                $sql = "INSERT INTO clients(email, client_name, company_name, phone_number) VALUES(:email, :client_name, :company_name, :phone_number)";
                $stmt = $this->conexion->prepare($sql);
                $stmt->execute([
                    ':email' => $email,
                    ':client_name' => $client_name,
                    ':company_name' => $company_name,
                    ':phone_number' => $phone_number
                ]);
                $resultado = ['success', 'Client saved'];
            }
            return $resultado;
        } catch (PDOException $e) {
            var_dump($e->getMessage());
            return ['error', 'Failed to save client'];
        }
    }

    public function updateClient($id, $email, $client_name, $company_name, $phone_number)
    {
        try {
            $existe = $this->getClients($id);
            $resultado = ['error', "There is no client with ID {$id}"];
            if (count($existe) > 0) {
                $valida = $this->validateClients($email, $client_name, $company_name, $phone_number);
                $resultado = ['error', 'This client already exists'];
                if (count($valida) == 0) {
                    $sql = "UPDATE clients SET email = :email, client_name = :client_name, company_name = :company_name, phone_number = :phone_number WHERE id = :id";
                    $stmt = $this->conexion->prepare($sql);
                    $stmt->execute([
                        ':id' => $id,
                        ':email' => $email,
                        ':client_name' => $client_name,
                        ':company_name' => $company_name,
                        ':phone_number' => $phone_number
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

    public function validateClients($email, $client_name, $company_name, $phone_number)
    {
        try {
            $sql = "SELECT * FROM clients WHERE email = :email AND client_name = :client_name AND company_name = :company_name AND phone_number = :phone_number";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([
                ':email' => $email,
                ':client_name' => $client_name,
                ':company_name' => $company_name,
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
