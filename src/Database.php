<?php
//YourStrongPasswordHere
class Database
{
    private static $instance = null;
    private $conexion;

    private function __construct()
    {
        try {
            $this->conexion = new PDO(
                'mysql:host=sh00032.hostgator.com:3306;dbname=mtldtmte_new_gratexdb;charset=utf8mb4',
                'mtldtmte_edwin',
                'gratexdb.',
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_PERSISTENT => false
                ]
            );
        } catch (PDOException $e) {
            die('Database connection failed: ' . $e->getMessage());
        }
    }

    /**
     * Get singleton instance of Database
     * @return Database
     */
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    /**
     * Get PDO connection
     * @return PDO
     */
    public function getConnection()
    {
        return $this->conexion;
    }

    /**
     * Execute prepared statement
     * @param string $sql SQL query
     * @param array $params Parameters for prepared statement
     * @return PDOStatement
     */
    public function query($sql, $params = [])
    {
        $stmt = $this->conexion->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Close connection
     */
    public function closeConnection()
    {
        $this->conexion = null;
    }
}

