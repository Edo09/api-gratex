<?php
require_once __DIR__ . '/../Database.php';

class authSeedModel
{
    private $conexion;

    public function __construct()
    {
        $this->conexion = Database::getInstance()->getConnection();
    }

    public function create(string $seedValue, string $xml, int $ttlSeconds = 300): int
    {
        $expira = (new DateTime())->modify('+' . $ttlSeconds . ' seconds')->format('Y-m-d H:i:s');
        $stmt = $this->conexion->prepare(
            'INSERT INTO auth_seeds (seed_value, xml_emitido, expira_at) VALUES (?, ?, ?)'
        );
        $stmt->execute([$seedValue, $xml, $expira]);
        return (int) $this->conexion->lastInsertId();
    }

    public function getBySeedValue(string $seedValue): ?array
    {
        $stmt = $this->conexion->prepare('SELECT * FROM auth_seeds WHERE seed_value = ? LIMIT 1');
        $stmt->execute([$seedValue]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function markConsumed(int $seedId, string $rnc, string $token): void
    {
        $stmt = $this->conexion->prepare(
            'UPDATE auth_seeds SET consumida_at = NOW(), rnc_consumidor = ?, token_emitido = ? WHERE id = ?'
        );
        $stmt->execute([$rnc, $token, $seedId]);
    }

    public function saveToken(string $token, string $rnc, int $ttlSeconds = 3600): void
    {
        $expira = (new DateTime())->modify('+' . $ttlSeconds . ' seconds')->format('Y-m-d H:i:s');
        $stmt = $this->conexion->prepare(
            'INSERT INTO auth_tokens_emitidos (token, rnc_consumidor, expira_at) VALUES (?, ?, ?)'
        );
        $stmt->execute([$token, $rnc, $expira]);
    }

    public function findValidToken(string $token): ?array
    {
        $stmt = $this->conexion->prepare(
            'SELECT * FROM auth_tokens_emitidos
             WHERE token = ? AND revocado_at IS NULL AND expira_at > NOW()
             LIMIT 1'
        );
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
