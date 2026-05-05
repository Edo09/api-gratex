<?php
class Database
{
    private static $instance = null;
    private $conexion;

    private static function loadEnv(): void
    {
        $envFile = __DIR__ . '/../.env';
        if (!is_file($envFile)) {
            return;
        }
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if ($line[0] === '#' || strpos($line, '=') === false) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if (!array_key_exists($key, $_ENV)) {
                $_ENV[$key] = $value;
                putenv("{$key}={$value}");
            }
        }
    }

    private function __construct()
    {
        self::loadEnv();
        $host = $_ENV['DB_HOST'] ?? 'sh00032.hostgator.com';
        $port = $_ENV['DB_PORT'] ?? '3306';
        $name = $_ENV['DB_NAME'] ?? 'mtldtmte_new_gratexdb';
        $user = $_ENV['DB_USER'] ?? 'mtldtmte_edwin';
        $pass = $_ENV['DB_PASS'] ?? 'gratexdb.';

        try {
            $this->conexion = new PDO(
                "mysql:host={$host}:{$port};dbname={$name};charset=utf8mb4",
                $user,
                $pass,
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

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    public function getConnection()
    {
        return $this->conexion;
    }

    public function query($sql, $params = [])
    {
        $stmt = $this->conexion->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function closeConnection()
    {
        $this->conexion = null;
    }
}

