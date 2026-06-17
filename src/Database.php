<?php
class Database
{
    private static $instance = null;
    private static $credentials = null;
    private $conexion;

    public static function loadEnv(): void
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

        if (self::$credentials !== null) {
            // Credenciales dinamicas del tenant resuelto (TenantResolver).
            $host = self::$credentials['host'] ?? 'localhost';
            $port = self::$credentials['port'] ?? '3306';
            $name = self::$credentials['name'] ?? '';
            $user = self::$credentials['user'] ?? '';
            $pass = self::$credentials['pass'] ?? '';
        } else {
            // Sin tenant resuelto: cae al DB por defecto del .env.
            // NOTA multi-tenant: los controllers instancian sus models en el tope
            // (antes de validateRequest), y los models fijan la conexion en su
            // constructor. Por eso NO se lanza excepcion aqui: romperia toda la app.
            // Implicacion: para un 2do tenant tipo "app" con DB distinta hace falta
            // resolver el tenant ANTES de instanciar models (o conexion lazy).
            // Gratex (tenant #1) no se ve afectado: su DB == la default del .env.
            // Integracion no usa la DB de tenant. Ver docs/architecture.md.
            $host = $_ENV['DB_HOST'] ?? 'sh00032.hostgator.com';
            $port = $_ENV['DB_PORT'] ?? '3306';
            $name = $_ENV['DB_NAME'] ?? 'mtldtmte_new_gratexdb';
            $user = $_ENV['DB_USER'] ?? 'mtldtmte_edwin';
            $pass = $_ENV['DB_PASS'] ?? 'gratexdb.';
        }

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

    /**
     * Set dynamic connection credentials for the resolved tenant.
     * Resets the singleton so the next getInstance() reconnects to that DB.
     * @param array $creds ['host','port'?,'name','user','pass']
     */
    public static function setCredentials(array $creds): void
    {
        self::$credentials = $creds;
        self::$instance = null;
    }

    /**
     * Clear tenant credentials and reset the singleton (back to .env default).
     */
    public static function clearCredentials(): void
    {
        self::$credentials = null;
        self::$instance = null;
    }

    /**
     * @return bool True if tenant credentials have been resolved.
     */
    public static function hasCredentials(): bool
    {
        return self::$credentials !== null;
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

