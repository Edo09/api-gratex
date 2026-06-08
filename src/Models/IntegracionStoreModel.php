<?php
require_once __DIR__ . '/../MasterDatabase.php';

/**
 * Almacenamiento en el master DB para tenants tipo "integracion" (sin DB propia).
 * Tablas espejo de las de un tenant "app", aisladas por tenant_id:
 *   - ecf_recibidos            (e-CF que le facturan + aprobacion comercial saliente)
 *   - aprobaciones_comerciales (aprobaciones que recibe sobre lo que emitio)
 *   - ecf_integracion_backup   (respaldo de e-CF que emite)
 */
class IntegracionStoreModel
{
    private PDO $conexion;

    public function __construct()
    {
        $this->conexion = MasterDatabase::getInstance()->getConnection();
    }

    // ---------------------------------------------------------------- recibidos

    public function existsRecibido(int $tenantId, string $rncEmisor, string $eNcf): bool
    {
        $stmt = $this->conexion->prepare(
            'SELECT 1 FROM ecf_recibidos WHERE tenant_id = :t AND rnc_emisor = :r AND e_ncf = :e LIMIT 1'
        );
        $stmt->execute([':t' => $tenantId, ':r' => $rncEmisor, ':e' => $eNcf]);
        return (bool) $stmt->fetchColumn();
    }

    public function getRecibidoByENCF(int $tenantId, string $rncEmisor, string $eNcf): ?array
    {
        $stmt = $this->conexion->prepare(
            'SELECT * FROM ecf_recibidos WHERE tenant_id = :t AND rnc_emisor = :r AND e_ncf = :e LIMIT 1'
        );
        $stmt->execute([':t' => $tenantId, ':r' => $rncEmisor, ':e' => $eNcf]);
        return $stmt->fetch() ?: null;
    }

    public function getRecibidoByTrackId(int $tenantId, string $trackId): ?array
    {
        $stmt = $this->conexion->prepare(
            'SELECT * FROM ecf_recibidos WHERE tenant_id = :t AND track_id = :tk LIMIT 1'
        );
        $stmt->execute([':t' => $tenantId, ':tk' => $trackId]);
        return $stmt->fetch() ?: null;
    }

    public function saveRecibido(int $tenantId, array $d): int
    {
        $stmt = $this->conexion->prepare(
            'INSERT INTO ecf_recibidos
                (tenant_id, track_id, tipo_ecf, e_ncf, rnc_emisor, razon_social_emisor,
                 rnc_comprador, monto_total, fecha_emision, estado, codigo_resultado,
                 mensaje_resultado, xml_firmado, validacion_firma, ambiente)
             VALUES
                (:tenant_id, :track_id, :tipo_ecf, :e_ncf, :rnc_emisor, :razon_social_emisor,
                 :rnc_comprador, :monto_total, :fecha_emision, :estado, :codigo_resultado,
                 :mensaje_resultado, :xml_firmado, :validacion_firma, :ambiente)'
        );
        $stmt->execute([
            ':tenant_id'           => $tenantId,
            ':track_id'            => $d['track_id'],
            ':tipo_ecf'            => $d['tipo_ecf'] ?? null,
            ':e_ncf'               => $d['e_ncf'] ?? null,
            ':rnc_emisor'          => $d['rnc_emisor'],
            ':razon_social_emisor' => $d['razon_social_emisor'] ?? null,
            ':rnc_comprador'       => $d['rnc_comprador'] ?? null,
            ':monto_total'         => $d['monto_total'] ?? null,
            ':fecha_emision'       => $d['fecha_emision'] ?? null,
            ':estado'              => $d['estado'] ?? 'RECIBIDO',
            ':codigo_resultado'    => $d['codigo_resultado'] ?? null,
            ':mensaje_resultado'   => $d['mensaje_resultado'] ?? null,
            ':xml_firmado'         => $d['xml_firmado'] ?? null,
            ':validacion_firma'    => $d['validacion_firma'] ?? null,
            ':ambiente'            => $d['ambiente'] ?? null,
        ]);
        return (int) $this->conexion->lastInsertId();
    }

    public function listRecibidos(int $tenantId, int $offset, int $limit): array
    {
        $stmt = $this->conexion->prepare(
            'SELECT id, track_id, tipo_ecf, e_ncf, rnc_emisor, razon_social_emisor,
                    rnc_comprador, monto_total, fecha_emision, fecha_recepcion, estado,
                    codigo_resultado, mensaje_resultado, validacion_firma, ambiente,
                    aprobacion_comercial, aprobacion_comercial_estado_dgii
               FROM ecf_recibidos
              WHERE tenant_id = :t
              ORDER BY fecha_recepcion DESC
              LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue(':t', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function countRecibidos(int $tenantId): int
    {
        $stmt = $this->conexion->prepare('SELECT COUNT(*) FROM ecf_recibidos WHERE tenant_id = :t');
        $stmt->execute([':t' => $tenantId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Actualiza la decision comercial saliente sobre un e-CF recibido.
     * @return int filas afectadas
     */
    public function updateAprobacionComercial(int $tenantId, string $rncEmisor, string $eNcf, array $d): int
    {
        $stmt = $this->conexion->prepare(
            'UPDATE ecf_recibidos SET
                aprobacion_comercial              = :ap,
                aprobacion_comercial_detalle      = :det,
                aprobacion_comercial_codigo_dgii  = :cod,
                aprobacion_comercial_estado_dgii  = :est,
                aprobacion_comercial_mensaje_dgii = :msg,
                aprobacion_comercial_procesada    = :proc,
                aprobacion_comercial_fecha        = NOW()
             WHERE tenant_id = :t AND rnc_emisor = :r AND e_ncf = :e'
        );
        $stmt->execute([
            ':ap'   => $d['aprobacion_comercial'] ?? null,
            ':det'  => $d['aprobacion_comercial_detalle'] ?? null,
            ':cod'  => $d['aprobacion_comercial_codigo_dgii'] ?? null,
            ':est'  => $d['aprobacion_comercial_estado_dgii'] ?? null,
            ':msg'  => $d['aprobacion_comercial_mensaje_dgii'] ?? null,
            ':proc' => $d['aprobacion_comercial_procesada'] ?? null,
            ':t'    => $tenantId,
            ':r'    => $rncEmisor,
            ':e'    => $eNcf,
        ]);
        return $stmt->rowCount();
    }

    // ----------------------------------------------------------- aprobaciones

    public function saveAprobacion(int $tenantId, array $d): int
    {
        $stmt = $this->conexion->prepare(
            'INSERT INTO aprobaciones_comerciales
                (tenant_id, e_ncf, rnc_emisor, rnc_comprador, estado_comercial,
                 detalle_motivo, xml_firmado, validacion_firma, ambiente)
             VALUES
                (:tenant_id, :e_ncf, :rnc_emisor, :rnc_comprador, :estado_comercial,
                 :detalle_motivo, :xml_firmado, :validacion_firma, :ambiente)'
        );
        $stmt->execute([
            ':tenant_id'        => $tenantId,
            ':e_ncf'            => $d['e_ncf'],
            ':rnc_emisor'       => $d['rnc_emisor'],
            ':rnc_comprador'    => $d['rnc_comprador'] ?? '',
            ':estado_comercial' => $d['estado_comercial'],
            ':detalle_motivo'   => $d['detalle_motivo'] ?? null,
            ':xml_firmado'      => $d['xml_firmado'] ?? null,
            ':validacion_firma' => $d['validacion_firma'] ?? null,
            ':ambiente'         => $d['ambiente'] ?? null,
        ]);
        return (int) $this->conexion->lastInsertId();
    }

    public function listAprobaciones(int $tenantId, int $offset, int $limit): array
    {
        $stmt = $this->conexion->prepare(
            'SELECT id, e_ncf, rnc_emisor, rnc_comprador, estado_comercial, detalle_motivo,
                    validacion_firma, ambiente, fecha_recepcion
               FROM aprobaciones_comerciales
              WHERE tenant_id = :t
              ORDER BY fecha_recepcion DESC
              LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue(':t', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function countAprobaciones(int $tenantId): int
    {
        $stmt = $this->conexion->prepare('SELECT COUNT(*) FROM aprobaciones_comerciales WHERE tenant_id = :t');
        $stmt->execute([':t' => $tenantId]);
        return (int) $stmt->fetchColumn();
    }

    // --------------------------------------------------------------- emitidos

    /** Respaldo de e-CF emitido (delega en MasterDatabase). */
    public function saveEmitido(array $data): int
    {
        return MasterDatabase::getInstance()->saveIntegrationEcf($data);
    }
}
