<?php
require_once __DIR__ . '/../Database.php';

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
            $current = $this->getCurrentSequence($type);
            if (!$current) {
                return false;
            }
            $newValue = $current['current_value'] + $addValue;
            $sql = "UPDATE ncf_sequences SET current_value = :val WHERE type = :type";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([':val' => $newValue, ':type' => $type]);
            return $this->getCurrentSequence($type);
        } catch (PDOException $e) {
            return false;
        }
    }

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
        if (!$seq) {
            return null;
        }
        $nextVal = $seq['current_value'] + 1;
        return $type . str_pad($nextVal, 8, '0', STR_PAD_LEFT);
    }

    /**
     * Reserves and returns the next e-NCF for an electronic type (E31..E47).
     * Atomic: uses UPDATE with arithmetic so no two concurrent calls return the same number.
     * Format: E + 2-digit type + 10-digit sequence = 13 chars (e.g. E310000000001).
     */
    public function dispenseNextECF(string $type): ?string
    {
        if (!preg_match('/^E\d{2}$/', $type)) {
            return null;
        }
        try {
            $upd = $this->conexion->prepare(
                'UPDATE ncf_sequences SET current_value = current_value + 1 WHERE type = :type'
            );
            $upd->execute([':type' => $type]);
            if ($upd->rowCount() === 0) {
                return null;
            }
            $sel = $this->conexion->prepare(
                'SELECT current_value FROM ncf_sequences WHERE type = :type'
            );
            $sel->execute([':type' => $type]);
            $row = $sel->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }
            $sequenceDigits = str_pad((string) $row['current_value'], 10, '0', STR_PAD_LEFT);
            return $type . $sequenceDigits;
        } catch (PDOException $e) {
            return null;
        }
    }
}
