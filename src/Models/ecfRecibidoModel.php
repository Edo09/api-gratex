<?php
require_once __DIR__ . '/../Database.php';

class ecfRecibidoModel
{
    private $conexion;

    public function __construct()
    {
        $this->conexion = Database::getInstance()->getConnection();
    }

    public function exists(string $rncEmisor, string $eNcf): bool
    {
        $stmt = $this->conexion->prepare(
            'SELECT id FROM ecf_recibidos WHERE rnc_emisor = ? AND e_ncf = ? LIMIT 1'
        );
        $stmt->execute([$rncEmisor, $eNcf]);
        return (bool) $stmt->fetch();
    }

    public function save(array $data): int
    {
        $stmt = $this->conexion->prepare(
            'INSERT INTO ecf_recibidos (
                track_id, tipo_ecf, e_ncf, rnc_emisor, razon_social_emisor,
                rnc_comprador, monto_total, fecha_emision,
                estado, codigo_resultado, mensaje_resultado,
                xml_firmado, validacion_firma
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['track_id'],
            $data['tipo_ecf'] ?? null,
            $data['e_ncf'] ?? null,
            $data['rnc_emisor'],
            $data['razon_social_emisor'] ?? null,
            $data['rnc_comprador'] ?? null,
            $data['monto_total'] ?? null,
            $data['fecha_emision'] ?? null,
            $data['estado'] ?? 'RECIBIDO',
            $data['codigo_resultado'] ?? null,
            $data['mensaje_resultado'] ?? null,
            $data['xml_firmado'] ?? null,
            $data['validacion_firma'] ?? null,
        ]);
        return (int) $this->conexion->lastInsertId();
    }

    public function getByTrackId(string $trackId): ?array
    {
        $stmt = $this->conexion->prepare('SELECT * FROM ecf_recibidos WHERE track_id = ? LIMIT 1');
        $stmt->execute([$trackId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function listPaginated(int $offset, int $pageSize): array
    {
        $stmt = $this->conexion->prepare(
            'SELECT id, track_id, tipo_ecf, e_ncf, rnc_emisor, razon_social_emisor,
                    monto_total, fecha_emision, fecha_recepcion, estado
             FROM ecf_recibidos
             ORDER BY fecha_recepcion DESC
             LIMIT ?, ?'
        );
        $stmt->bindValue(1, $offset, PDO::PARAM_INT);
        $stmt->bindValue(2, $pageSize, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function count(): int
    {
        $stmt = $this->conexion->query('SELECT COUNT(*) AS c FROM ecf_recibidos');
        return (int) ($stmt->fetch()['c'] ?? 0);
    }
}
