<?php
require_once __DIR__ . '/../Database.php';

class aprobacionComercialModel
{
    private $conexion;

    public function __construct()
    {
        $this->conexion = Database::getInstance()->getConnection();
    }

    public function save(array $data): int
    {
        $stmt = $this->conexion->prepare(
            'INSERT INTO aprobaciones_comerciales (
                factura_id, e_ncf, rnc_emisor, rnc_comprador,
                estado_comercial, detalle_motivo, xml_firmado, validacion_firma, ambiente
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $data['factura_id'] ?? null,
            $data['e_ncf'],
            $data['rnc_emisor'],
            $data['rnc_comprador'],
            $data['estado_comercial'],
            $data['detalle_motivo'] ?? null,
            $data['xml_firmado'] ?? null,
            $data['validacion_firma'] ?? null,
            $data['ambiente'] ?? null,
        ]);
        return (int) $this->conexion->lastInsertId();
    }

    public function findFacturaIdByENcf(string $eNcf): ?int
    {
        $stmt = $this->conexion->prepare('SELECT id FROM facturas WHERE e_ncf = ? LIMIT 1');
        $stmt->execute([$eNcf]);
        $row = $stmt->fetch();
        return $row ? (int) $row['id'] : null;
    }

    public function listByFactura(int $facturaId): array
    {
        $stmt = $this->conexion->prepare(
            'SELECT * FROM aprobaciones_comerciales WHERE factura_id = ? ORDER BY fecha_recepcion DESC'
        );
        $stmt->execute([$facturaId]);
        return $stmt->fetchAll();
    }
}
