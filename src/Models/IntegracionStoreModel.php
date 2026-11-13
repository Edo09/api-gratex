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

    /** @param PDO|null $conexion Solo para pruebas (SQLite en memoria); en uso normal, el master. */
    public function __construct(?PDO $conexion = null)
    {
        $this->conexion = $conexion ?? MasterDatabase::getInstance()->getConnection();
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

    /**
     * De las empresas dadas, las que tienen registrado el e-CF recibido
     * rnc_emisor + e_ncf. Sirve para deducir cual empresa de un grupo es la
     * compradora cuando la credencial de grupo aprueba sin mandar rnc_comprador.
     *
     * Solo busca entre los tenant_id de $empresas: quien llama decide el
     * universo (las empresas del grupo), este metodo no amplia el acceso.
     *
     * @param array<int,array{id:int}> $empresas filas de TenantResolver::siblings()
     * @return array las filas de $empresas que lo recibieron, en el mismo orden
     */
    public function empresasConRecibido(array $empresas, string $rncEmisor, string $eNcf): array
    {
        $porId = [];
        foreach ($empresas as $e) {
            $porId[(int) $e['id']] = $e;
        }
        if (!$porId) {
            return [];
        }
        $marcas = implode(',', array_fill(0, count($porId), '?'));
        // DISTINCT: un reenvio de DGII puede dejar mas de una fila del mismo
        // e-CF en la misma empresa; eso no la vuelve ambigua.
        $stmt = $this->conexion->prepare(
            "SELECT DISTINCT tenant_id FROM ecf_recibidos
              WHERE tenant_id IN ({$marcas}) AND rnc_emisor = ? AND e_ncf = ?"
        );
        $stmt->execute(array_merge(array_keys($porId), [$rncEmisor, $eNcf]));
        $conRecibido = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        return array_values(array_filter(
            $porId,
            fn(array $e) => in_array((int) $e['id'], $conRecibido, true)
        ));
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
                 mensaje_resultado, xml_firmado, validacion_firma, ambiente,
                 origen_ip, origen_user_agent, origen_auth, origen_rnc_bearer,
                 firma_rnc, firma_subject)
             VALUES
                (:tenant_id, :track_id, :tipo_ecf, :e_ncf, :rnc_emisor, :razon_social_emisor,
                 :rnc_comprador, :monto_total, :fecha_emision, :estado, :codigo_resultado,
                 :mensaje_resultado, :xml_firmado, :validacion_firma, :ambiente,
                 :origen_ip, :origen_user_agent, :origen_auth, :origen_rnc_bearer,
                 :firma_rnc, :firma_subject)'
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
            ':origen_ip'           => $d['origen_ip'] ?? null,
            ':origen_user_agent'   => $d['origen_user_agent'] ?? null,
            ':origen_auth'         => $d['origen_auth'] ?? null,
            ':origen_rnc_bearer'   => $d['origen_rnc_bearer'] ?? null,
            ':firma_rnc'           => $d['firma_rnc'] ?? null,
            ':firma_subject'       => $d['firma_subject'] ?? null,
        ]);
        return (int) $this->conexion->lastInsertId();
    }

    public function listRecibidos(int $tenantId, int $offset, int $limit, ?string $ambiente = null): array
    {
        // El ambiente de un tenant integracion es per-tenant (tenants.ambiente:
        // certecf durante certificacion, ecf en produccion), NO el env global del
        // servidor. Filtrar por el ambiente actual del tenant evita mezclar datos
        // de prueba con produccion.
        $where = 'WHERE tenant_id = :t';
        if ($ambiente !== null && $ambiente !== '') {
            $where .= ' AND ambiente = :amb';
        }
        // Lo lee un sistema EXTERNO (el del cliente): sin origen_ip (IP de un
        // tercero), origen_auth ni mensaje_resultado ("recibido sin autenticacion
        // previa..."), que describen como recibe nuestro servidor. Siguen en la
        // tabla para soporte y auditoria.
        $stmt = $this->conexion->prepare(
            "SELECT id, track_id, tipo_ecf, e_ncf, rnc_emisor, razon_social_emisor,
                    rnc_comprador, monto_total, fecha_emision, fecha_recepcion, estado,
                    codigo_resultado, validacion_firma, ambiente,
                    firma_rnc, firma_subject,
                    aprobacion_comercial, aprobacion_comercial_estado_dgii
               FROM ecf_recibidos
              {$where}
              ORDER BY fecha_recepcion DESC
              LIMIT :lim OFFSET :off"
        );
        $stmt->bindValue(':t', $tenantId, PDO::PARAM_INT);
        if ($ambiente !== null && $ambiente !== '') {
            $stmt->bindValue(':amb', $ambiente, PDO::PARAM_STR);
        }
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function countRecibidos(int $tenantId, ?string $ambiente = null): int
    {
        $sql = 'SELECT COUNT(*) FROM ecf_recibidos WHERE tenant_id = :t';
        $params = [':t' => $tenantId];
        if ($ambiente !== null && $ambiente !== '') {
            $sql .= ' AND ambiente = :amb';
            $params[':amb'] = $ambiente;
        }
        $stmt = $this->conexion->prepare($sql);
        $stmt->execute($params);
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

    public function listAprobaciones(int $tenantId, int $offset, int $limit, ?string $ambiente = null): array
    {
        $where = 'WHERE tenant_id = :t';
        if ($ambiente !== null && $ambiente !== '') {
            $where .= ' AND ambiente = :amb';
        }
        $stmt = $this->conexion->prepare(
            "SELECT id, e_ncf, rnc_emisor, rnc_comprador, estado_comercial, detalle_motivo,
                    validacion_firma, ambiente, fecha_recepcion
               FROM aprobaciones_comerciales
              {$where}
              ORDER BY fecha_recepcion DESC
              LIMIT :lim OFFSET :off"
        );
        $stmt->bindValue(':t', $tenantId, PDO::PARAM_INT);
        if ($ambiente !== null && $ambiente !== '') {
            $stmt->bindValue(':amb', $ambiente, PDO::PARAM_STR);
        }
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function countAprobaciones(int $tenantId, ?string $ambiente = null): int
    {
        $sql = 'SELECT COUNT(*) FROM aprobaciones_comerciales WHERE tenant_id = :t';
        $params = [':t' => $tenantId];
        if ($ambiente !== null && $ambiente !== '') {
            $sql .= ' AND ambiente = :amb';
            $params[':amb'] = $ambiente;
        }
        $stmt = $this->conexion->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    // --------------------------------------------------------------- emitidos

    /** Respaldo de e-CF emitido (delega en MasterDatabase). */
    public function saveEmitido(array $data): int
    {
        return MasterDatabase::getInstance()->saveIntegrationEcf($data);
    }

    /**
     * Respaldo de un e-CF emitido, por e-NCF. Sirve para resolver el track_id
     * cuando el cliente consulta el estado sabiendo solo el e-NCF. Sin el
     * xml_firmado: no hace falta aqui y es una columna MEDIUMTEXT.
     * El mas reciente primero (un reenvio deja mas de una fila).
     */
    public function getEmitidoByENCF(int $tenantId, string $rncEmisor, string $eNcf): ?array
    {
        $stmt = $this->conexion->prepare(
            'SELECT id, tenant_id, rnc_emisor, tipo_ecf, e_ncf, rnc_comprador, monto_total, track_id, created_at
               FROM ecf_integracion_backup
              WHERE tenant_id = :t AND rnc_emisor = :r AND e_ncf = :e
              ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':t' => $tenantId, ':r' => $rncEmisor, ':e' => $eNcf]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Guarda lo que respondio la DGII al consultar el estado (GET /integracion/estado)
     * en la fila del respaldo de ese envio: la del track_id si se consulto por el,
     * si no (RFCE) el ultimo envio de ese e-NCF. Best-effort: false si no hay fila
     * o si falta master_migrations/014; nunca lanza.
     *
     * @param array $consultaData la respuesta de la DGII (consulta['data'])
     */
    public function guardarEstadoConsultado(
        int $tenantId,
        string $rncEmisor,
        string $eNcf,
        ?string $trackId,
        ?string $estado,
        ?string $ambiente,
        $consultaData
    ): bool {
        try {
            if ($trackId !== null && $trackId !== '') {
                $stmt = $this->conexion->prepare(
                    'SELECT id FROM ecf_integracion_backup
                      WHERE tenant_id = :t AND rnc_emisor = :r AND e_ncf = :e AND track_id = :k
                      ORDER BY id DESC LIMIT 1'
                );
                $stmt->execute([':t' => $tenantId, ':r' => $rncEmisor, ':e' => $eNcf, ':k' => $trackId]);
            } else {
                $stmt = $this->conexion->prepare(
                    'SELECT id FROM ecf_integracion_backup
                      WHERE tenant_id = :t AND rnc_emisor = :r AND e_ncf = :e
                      ORDER BY id DESC LIMIT 1'
                );
                $stmt->execute([':t' => $tenantId, ':r' => $rncEmisor, ':e' => $eNcf]);
            }
            $id = (int) ($stmt->fetchColumn() ?: 0);
        } catch (\Throwable $e) {
            error_log('[IntegracionStore] no se pudo ubicar el respaldo de ' . $eNcf . ': ' . $e->getMessage());
            return false;
        }
        if ($id <= 0) {
            return false;
        }
        return MasterDatabase::updateIntegrationEcfEstadoEn($this->conexion, $id, [
            'ambiente'       => $ambiente,
            'estado_dgii'    => $estado,
            'dgii_mensajes'  => is_array($consultaData) ? ($consultaData['mensajes'] ?? null) : null,
            'dgii_respuesta' => $consultaData,
        ]);
    }

    /**
     * Lo que la DGII dijo del ultimo envio de ese e-NCF, tal como quedo en el
     * respaldo (master_migrations/014): estado_dgii, codigo_seguridad, ambiente,
     * dgii_respuesta y dgii_mensajes (JSON ya decodificado), created_at.
     * null si no hay respaldo o falta la 014; nunca lanza.
     */
    public function getVeredictoGuardado(int $tenantId, string $rncEmisor, string $eNcf): ?array
    {
        try {
            $stmt = $this->conexion->prepare(
                'SELECT id, track_id, ambiente, codigo_seguridad, estado_dgii, dgii_mensajes, dgii_respuesta,
                        estado_consultado_at, created_at
                   FROM ecf_integracion_backup
                  WHERE tenant_id = :t AND rnc_emisor = :r AND e_ncf = :e
                  ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute([':t' => $tenantId, ':r' => $rncEmisor, ':e' => $eNcf]);
            $fila = $stmt->fetch();
        } catch (\Throwable $e) {
            error_log('[IntegracionStore] no se pudo leer el veredicto guardado de ' . $eNcf . ': ' . $e->getMessage());
            return null;
        }
        if (!$fila) {
            return null;
        }
        foreach (['dgii_respuesta', 'dgii_mensajes'] as $columna) {
            if (is_string($fila[$columna] ?? null) && $fila[$columna] !== '') {
                $decodificado = json_decode($fila[$columna], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $fila[$columna] = $decodificado;
                }
            }
        }
        return $fila;
    }

    /**
     * Respuesta de GET /integracion/estado armada con el veredicto GUARDADO de un
     * RFCE (E32 < 250k), para cuando la consulta en vivo a la DGII falla. Pura.
     *
     * Vale solo para RFCE: RecepcionFC contesta en el acto con el veredicto final
     * (Aceptado / Rechazado y el motivo), y la emision lo guarda. Un e-CF con
     * track_id no: al emitir solo queda ENVIADO, que no es un veredicto.
     *
     * Caso que lo origino (tenant 18, certecf, 2026-11-13): la DGII rechaza en
     * fc.dgii.gov.do/certecf/consultarfce el token de su propia autenticacion de
     * certecf ("invalid_token", "The signature is invalid"); en testecf y ecf la
     * misma consulta funciona. El cliente quedaba con un 502 y sin el motivo.
     *
     * @param array|null $guardado getVeredictoGuardado()
     * @param string     $codigoPedido el codigo de seguridad consultado: tiene que ser el
     *                   del envio guardado (otro codigo es otro envio del mismo e-NCF)
     * @return array{estado: string, consulta: mixed, con_motivo: bool, fecha: ?string}|null
     *         null si no hay un veredicto RFCE guardado de ese envio
     */
    public static function veredictoRfceGuardado(?array $guardado, string $codigoPedido): ?array
    {
        if ($guardado === null) {
            return null;
        }
        $estado = strtoupper(trim((string) ($guardado['estado_dgii'] ?? '')));
        if (!str_starts_with($estado, 'RFCE_') || $estado === 'RFCE_ENVIADO') {
            return null;
        }
        $codigoGuardado = trim((string) ($guardado['codigo_seguridad'] ?? ''));
        if ($codigoGuardado === '' || $codigoGuardado !== trim($codigoPedido)) {
            return null;
        }
        $consulta = $guardado['dgii_respuesta'] ?? null;
        return [
            'estado'     => $estado,
            'consulta'   => ($consulta === '' ? null : $consulta),
            'con_motivo' => is_array($consulta) && array_key_exists('mensajes', $consulta),
            'fecha'      => isset($guardado['created_at']) ? (string) $guardado['created_at'] : null,
        ];
    }

    /**
     * Igual que getEmitidoByENCF pero CON el xml_firmado: el ultimo envio de ese
     * e-NCF, para volver a bajar el comprobante (GET /integracion/xml). Lo usa
     * la certificacion: el XML integro de cada E32 <250k se sube a mano al portal.
     */
    public function getXmlEmitidoByENCF(int $tenantId, string $rncEmisor, string $eNcf): ?array
    {
        $stmt = $this->conexion->prepare(
            'SELECT id, tipo_ecf, e_ncf, track_id, created_at, xml_firmado
               FROM ecf_integracion_backup
              WHERE tenant_id = :t AND rnc_emisor = :r AND e_ncf = :e
              ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':t' => $tenantId, ':r' => $rncEmisor, ':e' => $eNcf]);
        return $stmt->fetch() ?: null;
    }
}
