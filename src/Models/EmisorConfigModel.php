<?php
require_once __DIR__ . '/../Database.php';

class EmisorConfigModel
{
    private $conexion;

    public function __construct()
    {
        $this->conexion = Database::getInstance()->getConnection();
    }

    public function get(): ?array
    {
        $stmt = $this->conexion->prepare('SELECT * FROM emisor_config WHERE id = 1');
        $stmt->execute();
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
