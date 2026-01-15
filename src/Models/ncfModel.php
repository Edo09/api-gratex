<?php
require_once(__DIR__ . '/../Database.php');

class ncfModel
{
    private $conexion;

    public function __construct()
    {
        $this->conexion = Database::getInstance()->getConnection();
    }

    public function getCurrentSequence($type = 'B01')
    {
        try {
            $sql = "SELECT * FROM ncf_sequences WHERE type = :type";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([':type' => $type]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return null;
        }
    }

    public function updateSequence($type, $addValue)
    {
        try {
            // First get current
            $current = $this->getCurrentSequence($type);
            if (!$current)
                return false;

            $newValue = $current['current_value'] + $addValue;

            $sql = "UPDATE ncf_sequences SET current_value = :val WHERE type = :type";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([':val' => $newValue, ':type' => $type]);

            return $this->getCurrentSequence($type);
        } catch (PDOException $e) {
            return false;
        }
    }

    // Set absolute value (for admin config)
    public function setSequence($type, $value)
    {
        try {
            $sql = "UPDATE ncf_sequences SET current_value = :val WHERE type = :type";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([':val' => $value, ':type' => $type]);
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    public function getNextNCF($type = 'B01')
    {
        $seq = $this->getCurrentSequence($type);
        if (!$seq)
            return null;

        $nextVal = $seq['current_value'] + 1;
        // Format: B0100000001 (Type + 8 digits)
        return $type . str_pad($nextVal, 8, '0', STR_PAD_LEFT);
    }
}
?>