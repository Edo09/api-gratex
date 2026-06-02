<?php
require_once __DIR__ . '/../Database.php';

class ncfModel
{
    private $conexion;

    public function __construct()
    {
        $this->conexion = Database::getInstance()->getConnection();
    }

    public function resolveActiveAmbiente(): ?string
    {
        $val = getenv('DGII_ECF_ENVIRONMENT') ?: ($_ENV['DGII_ECF_ENVIRONMENT'] ?? null);
        if (!$val) {
            $envFile = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env';
            if (is_file($envFile)) {
                $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                        continue;
                    }
                    [$key, $value] = explode('=', $line, 2);
                    if (trim($key) === 'DGII_ECF_ENVIRONMENT') {
                        $val = trim($value, " '\"");
                        break;
                    }
                }
            }
        }
        if (!$val) return null;
        $aliases = [
            'certecf' => 'certecf', 'cert' => 'certecf', 'certificacion' => 'certecf',
            'ecf'     => 'ecf',     'prod' => 'ecf',      'produccion'   => 'ecf',
            'testecf' => 'testecf', 'test' => 'testecf',
        ];
        return $aliases[strtolower(trim($val))] ?? strtolower(trim($val));
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
    public function dispenseNextECF(string $type, ?string $ambiente = null): ?string
    {
        if (!preg_match('/^E\d{2}$/', $type)) {
            return null;
        }
        $amb = $ambiente ?? $this->resolveActiveAmbiente() ?? 'certecf';
        try {
            $upd = $this->conexion->prepare(
                'UPDATE ncf_sequences SET current_value = current_value + 1 WHERE type = :type AND ambiente = :ambiente'
            );
            $upd->execute([':type' => $type, ':ambiente' => $amb]);
            if ($upd->rowCount() === 0) {
                return null;
            }
            $sel = $this->conexion->prepare(
                'SELECT current_value FROM ncf_sequences WHERE type = :type AND ambiente = :ambiente'
            );
            $sel->execute([':type' => $type, ':ambiente' => $amb]);
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

    /**
     * Revierte (-1) el contador de un e-CF cuando DGII rechaza SIN consumir la
     * secuencia (secuenciaUtilizada=false, p.ej. codigo 135 "No existen rangos de
     * secuencias disponibles"): asi el mismo e-NCF se reutiliza en el proximo
     * intento. Condicional: solo decrementa si el contador sigue en el valor que
     * se entrego, para no pisar el incremento de otra emision posterior. Devuelve
     * true si revirtio.
     */
    public function rollbackECFSequence(string $type, int $expectedValue, ?string $ambiente = null): bool
    {
        if (!preg_match('/^E\d{2}$/', $type) || $expectedValue < 1) {
            return false;
        }
        $amb = $ambiente ?? $this->resolveActiveAmbiente() ?? 'certecf';
        try {
            $upd = $this->conexion->prepare(
                'UPDATE ncf_sequences SET current_value = current_value - 1
                 WHERE type = :type AND ambiente = :ambiente AND current_value = :expected'
            );
            $upd->execute([':type' => $type, ':ambiente' => $amb, ':expected' => $expectedValue]);
            return $upd->rowCount() > 0;
        } catch (PDOException $e) {
            return false;
        }
    }
}
