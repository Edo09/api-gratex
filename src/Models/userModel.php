<?php
require_once(__DIR__ . '/../Database.php');

class userModel
{
    private $conexion;

    public function __construct()
    {
        $this->conexion = Database::getInstance()->getConnection();
    }

    public function getUsers($id = null)
    {
        try {
            if ($id == null) {
                $sql = "SELECT * FROM users";
                $stmt = $this->conexion->prepare($sql);
                $stmt->execute();
            } else {
                $sql = "SELECT * FROM users WHERE id = :id";
                $stmt = $this->conexion->prepare($sql);
                $stmt->execute([':id' => $id]);
            }
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }

    public function saveUser($name, $lastname, $email)
    {
        try {
            $valida = $this->validateUsers($name, $lastname, $email);
            if (count($valida) == 0) {
                $sql = "INSERT INTO users(name, last_name, email) VALUES(:name, :lastname, :email)";
                $stmt = $this->conexion->prepare($sql);
                $stmt->execute([
                    ':name' => $name,
                    ':lastname' => $lastname,
                    ':email' => $email
                ]);
                return ['success', 'User saved'];
            }
            return ['error', 'This user already exists'];
        } catch (PDOException $e) {
            return ['error', 'Failed to save user'];
        }
    }

    public function updateUser($id, $name, $lastname, $email)
    {
        try {
            $existe = $this->getUsers($id);
            if (count($existe) == 0) {
                return ['error', "There is no user with ID {$id}"];
            }
            
            $valida = $this->validateUsers($name, $lastname, $email);
            if (count($valida) > 0) {
                return ['error', 'This user already exists'];
            }
            
            $sql = "UPDATE users SET name = :name, last_name = :lastname, email = :email WHERE id = :id";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([
                ':id' => $id,
                ':name' => $name,
                ':lastname' => $lastname,
                ':email' => $email
            ]);
            return ['success', 'User updated'];
        } catch (PDOException $e) {
            return ['error', 'Failed to update user'];
        }
    }

    public function deleteUser($id)
    {
        try {
            $valida = $this->getUsers($id);
            if (count($valida) == 0) {
                return ['error', "User not found {$id}"];
            }
            
            $sql = "DELETE FROM users WHERE id = :id";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([':id' => $id]);
            return ['success', 'User deleted'];
        } catch (PDOException $e) {
            return ['error', 'Failed to delete user'];
        }
    }

    public function validateUsers($name, $lastname, $email)
    {
        try {
            $sql = "SELECT * FROM users WHERE name = :name AND last_name = :lastname AND email = :email";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([
                ':name' => $name,
                ':lastname' => $lastname,
                ':email' => $email
            ]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }
}

