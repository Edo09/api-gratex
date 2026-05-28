<?php
require_once(__DIR__ . '/../Database.php');

class facturaModel
{
    private $conexion;

    public function __construct()
    {
        $this->conexion = Database::getInstance()->getConnection();
    }

    public function getFacturas($id = null)
    {
        try {
            if ($id == null) {
                $sql = "SELECT f.*, cl.client_name, cl.company_name FROM facturas f LEFT JOIN clients cl ON f.client_id = cl.id";
                $stmt = $this->conexion->prepare($sql);
                $stmt->execute();
                $facturas = $stmt->fetchAll();
            } else {
                $sql = "SELECT f.*, cl.client_name, cl.company_name FROM facturas f LEFT JOIN clients cl ON f.client_id = cl.id WHERE f.id = :id";
                $stmt = $this->conexion->prepare($sql);
                $stmt->execute([':id' => $id]);
                $facturas = $stmt->fetchAll();
            }
            // Add concatenated description for each factura
            foreach ($facturas as &$factura) {
                $factura['description'] = $this->getFacturaItemsDescription($factura['id']);
            }
            return $facturas;
        } catch (PDOException $e) {
            return [];
        }
    }

    public function saveFactura($no_factura, $date, $client_id, $client_name, $total, $NCF)
    {
        try {
            $sql = "INSERT INTO facturas(no_factura, date, client_id, client_name, total, NCF) VALUES(:no_factura, :date, :client_id, :client_name, :total, :NCF)";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([
                ':no_factura' => $no_factura,
                ':date' => $date,
                ':client_id' => $client_id,
                ':client_name' => $client_name,
                ':total' => $total,
                ':NCF' => $NCF
            ]);
            return ['success', 'Factura saved'];
        } catch (PDOException $e) {
            return ['error', 'Failed to save factura'];
        }
    }
        public function getFacturaItemsDescription($factura_id)
    {
        try {
            $sql = "SELECT description FROM factura_items WHERE factura_id = :factura_id ORDER BY id ASC";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([':factura_id' => $factura_id]);
            $descriptions = array_map(function($row) { return $row['description']; }, $stmt->fetchAll());
            return implode("\n", $descriptions);
        } catch (PDOException $e) {
            return '';
        }
    }

    /**
     * Get full item details for a factura
     * @param int $factura_id Factura ID
     * @return array Array of items with id, description, amount, quantity, subtotal
     */
    public function getFacturaItems($factura_id)
    {
        try {
            $sql = "SELECT id, description, amount, quantity, subtotal FROM factura_items WHERE factura_id = :factura_id ORDER BY id ASC";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([':factura_id' => $factura_id]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }
    public function saveFacturaWithItems($no_factura, $date, $client_id, $client_name, $total, $NCF, $items, $user_id = null)
    {
        try {
            $this->conexion->beginTransaction();
            $sql = "INSERT INTO facturas(no_factura, date, client_id, client_name, total, NCF, user_id) VALUES(:no_factura, :date, :client_id, :client_name, :total, :NCF, :user_id)";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([
                ':no_factura' => $no_factura,
                ':date' => $date,
                ':client_id' => $client_id,
                ':client_name' => $client_name,
                ':total' => $total,
                ':NCF' => $NCF,
                ':user_id' => $user_id
            ]);
            $factura_id = $this->conexion->lastInsertId();
            $sql_item = "INSERT INTO factura_items(factura_id, description, amount, quantity, subtotal) VALUES(:factura_id, :description, :amount, :quantity, :subtotal)";
            $stmt_item = $this->conexion->prepare($sql_item);
            foreach ($items as $item) {
                $subtotal = $item->amount * $item->quantity;
                $stmt_item->execute([
                    ':factura_id' => $factura_id,
                    ':description' => $item->description,
                    ':amount' => $item->amount,
                    ':quantity' => $item->quantity,
                    ':subtotal' => $subtotal
                ]);
            }
            $this->conexion->commit();

            // --- PDF Generation and Email Sending ---
            require_once(__DIR__ . '/../Utils/FacturaPdfGenerator.php');

            // Fetch full factura data with items
            $facturaData = $this->getFacturas($factura_id);
            if ($facturaData && isset($facturaData[0])) {
                $facturaForPdf = $facturaData[0];
                $facturaForPdf['items'] = $this->getFacturaItems($factura_id);
                $facturaForPdf['NCF'] = $NCF;
                $facturaForPdf['no_factura'] = $no_factura;

                // Generate PDF and save
                $pdfPath = __DIR__ . '/../../facturas/';
                if (!is_dir($pdfPath)) {
                    mkdir($pdfPath, 0755, true);
                }
                $pdfFile = $pdfPath . 'Factura_' . $no_factura . '.pdf';
                $pdfContent = generateFacturaPdf($facturaForPdf, null, 'S');
                file_put_contents($pdfFile, $pdfContent);

                // Send email with PDF attached
                $clientEmail = '';
                $clientSql = "SELECT email FROM clients WHERE id = :id";
                $clientStmt = $this->conexion->prepare($clientSql);
                $clientStmt->execute([':id' => $client_id]);
                $clientRow = $clientStmt->fetch();
                if ($clientRow && !empty($clientRow['email'])) {
                    $clientEmail = $clientRow['email'];
                }

                $to = $clientEmail;
                $to .= ', edwin@gratex.net';
                $to .= ', omareogm09@gmail.com';
                $to .= ', info@gratex.net';
                $from = 'info@gratex.net';
                $fromName = 'Gratex';
                $subject = 'Factura anexa';
                $htmlContent = '<p>Estimado cliente:<br/> Su Factura <b>' . $no_factura . '</b> se encuentra anexa a este mensaje.</p>';

                $headers = "From: $fromName <$from>\r\n";
                $headers .= "MIME-Version: 1.0\r\n";
                $semi_rand = md5(time());
                $mime_boundary = "==Multipart_Boundary_x{$semi_rand}x";
                $headers .= "Content-Type: multipart/mixed;\r\n boundary=\"{$mime_boundary}\"";

                $message = "--{$mime_boundary}\r\n";
                $message .= "Content-Type: text/html; charset=\"UTF-8\"\r\n";
                $message .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
                $message .= $htmlContent . "\r\n\r\n";

                if (!empty($pdfFile) && is_file($pdfFile)) {
                    $fp = fopen($pdfFile, 'rb');
                    $data = fread($fp, filesize($pdfFile));
                    fclose($fp);
                    $data = chunk_split(base64_encode($data));
                    $message .= "--{$mime_boundary}\r\n";
                    $message .= "Content-Type: application/octet-stream; name=\"" . basename($pdfFile) . "\"\r\n";
                    $message .= "Content-Description: " . basename($pdfFile) . "\r\n";
                    $message .= "Content-Disposition: attachment; filename=\"" . basename($pdfFile) . "\"; size=" . filesize($pdfFile) . ";\r\n";
                    $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
                    $message .= $data . "\r\n\r\n";
                }
                $message .= "--{$mime_boundary}--\r\n";
                $returnpath = '-f' . $from;
                // @mail($to, $subject, $message, $headers, $returnpath);
            }

            return ['success', [
                'factura_id' => $factura_id,
                'no_factura' => $no_factura,
                'total' => $total
            ]];
        } catch (PDOException $e) {
            $this->conexion->rollBack();
            return ['error', 'Failed to save factura and items'];
        }
    }

    public function updateFactura($id, $no_factura, $date, $client_id, $client_name, $total, $NCF, $user_id = null)
    {
        try {
            $sql = "UPDATE facturas SET no_factura = :no_factura, date = :date, client_id = :client_id, client_name = :client_name, total = :total, NCF = :NCF, user_id = :user_id WHERE id = :id";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([
                ':id' => $id,
                ':no_factura' => $no_factura,
                ':date' => $date,
                ':client_id' => $client_id,
                ':client_name' => $client_name,
                ':total' => $total,
                ':NCF' => $NCF,
                ':user_id' => $user_id
            ]);
            return ['success', 'Factura updated'];
        } catch (PDOException $e) {
            return ['error', 'Failed to update factura'];
        }
    }

    public function deleteFactura($id)
    {
        try {
            $sql = "DELETE FROM facturas WHERE id = :id";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([':id' => $id]);
            return ['success', 'Factura deleted'];
        } catch (PDOException $e) {
            return ['error', 'Failed to delete factura'];
        }
    }

    public function getFacturasPaginated($offset, $limit, $query = null)
    {
        try {
            $ambiente = $this->resolveActiveAmbiente();
            $conditions = [];
            $params = [];

            if ($query) {
                $conditions[] = "(f.no_factura LIKE :query OR cl.client_name LIKE :query OR f.NCF LIKE :query OR cl.rnc LIKE :query OR cl.company_name LIKE :query OR cl.phone_number LIKE :query OR cl.email LIKE :query)";
                $params[':query'] = "%{$query}%";
            }
            if ($ambiente !== null) {
                $conditions[] = "f.ambiente_dgii = :ambiente";
                $params[':ambiente'] = $ambiente;
            }

            $whereClause = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
            $sql = "SELECT f.*, cl.client_name, cl.company_name FROM facturas f LEFT JOIN clients cl ON f.client_id = cl.id {$whereClause} ORDER BY f.id DESC LIMIT :limit OFFSET :offset";
            $stmt = $this->conexion->prepare($sql);
            foreach ($params as $key => $val) {
                $stmt->bindValue($key, $val, \PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', (int)$limit, \PDO::PARAM_INT);
            $stmt->bindValue(':offset', (int)$offset, \PDO::PARAM_INT);
            $stmt->execute();
            $facturas = $stmt->fetchAll();
            foreach ($facturas as &$factura) {
                $factura['description'] = $this->getFacturaItemsDescription($factura['id']);
            }
            return $facturas;
        } catch (PDOException $e) {
            return [];
        }
    }

    public function getFacturasCount($query = null)
    {
        try {
            $ambiente = $this->resolveActiveAmbiente();
            $conditions = [];
            $params = [];

            if ($query) {
                $conditions[] = "(f.no_factura LIKE :query OR cl.client_name LIKE :query OR f.NCF LIKE :query OR cl.rnc LIKE :query OR cl.company_name LIKE :query OR cl.phone_number LIKE :query OR cl.email LIKE :query)";
                $params[':query'] = "%{$query}%";
            }
            if ($ambiente !== null) {
                $conditions[] = "f.ambiente_dgii = :ambiente";
                $params[':ambiente'] = $ambiente;
            }

            $whereClause = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
            $sql = "SELECT COUNT(*) as total FROM facturas f LEFT JOIN clients cl ON f.client_id = cl.id {$whereClause}";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch();
            return $row ? (int)$row['total'] : 0;
        } catch (PDOException $e) {
            return 0;
        }
    }

    /**
     * Get NCF information for a specific factura
     * @param int $factura_id The factura ID
     * @return array|null NCF data or null if not found
     */
    public function getNCF($factura_id)
    {
        try {
            $sql = "SELECT id, no_factura, NCF FROM facturas WHERE id = :id";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([':id' => $factura_id]);
            $result = $stmt->fetch();
            return $result ? $result : null;
        } catch (PDOException $e) {
            return null;
        }
    }

    /**
     * Save a factura together with its e-CF emission result.
     * @param array $factura {date, client_id, client_name, total, items[], user_id?, tipo_ecf}
     * @param array $ecf {e_ncf, track_id, estado, codigo_seguridad, fecha_emision_dgii, ambiente, signed_xml, dgii_response}
     * @return array ['success'|'error', payload]
     */
    public function saveFacturaConECF(array $factura, array $ecf): array
    {
        try {
            $this->conexion->beginTransaction();

            $sql = 'INSERT INTO facturas
                (no_factura, date, client_id, client_name, total, NCF, user_id,
                 tipo_ecf, e_ncf, track_id, estado_dgii, codigo_seguridad,
                 fecha_emision_dgii, ambiente_dgii, xml_firmado, respuesta_dgii,
                 rfce_xml, rfce_track_id, rfce_estado, rfce_respuesta)
                VALUES
                (:no_factura, :date, :client_id, :client_name, :total, NULL, :user_id,
                 :tipo_ecf, :e_ncf, :track_id, :estado_dgii, :codigo_seguridad,
                 :fecha_emision_dgii, :ambiente_dgii, :xml_firmado, :respuesta_dgii,
                 :rfce_xml, :rfce_track_id, :rfce_estado, :rfce_respuesta)';
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([
                ':no_factura' => $factura['no_factura'] ?? $ecf['e_ncf'],
                ':date' => $factura['date'],
                ':client_id' => $factura['client_id'],
                ':client_name' => $factura['client_name'],
                ':total' => $factura['total'],
                ':user_id' => $factura['user_id'] ?? null,
                ':tipo_ecf' => $ecf['tipo_ecf'],
                ':e_ncf' => $ecf['e_ncf'],
                ':track_id' => $ecf['track_id'] ?? null,
                ':estado_dgii' => $ecf['estado'] ?? 'PENDIENTE',
                ':codigo_seguridad' => $ecf['codigo_seguridad'] ?? null,
                ':fecha_emision_dgii' => $ecf['fecha_emision_dgii'] ?? null,
                ':ambiente_dgii' => $ecf['ambiente'] ?? null,
                ':xml_firmado' => $ecf['signed_xml'] ?? null,
                ':respuesta_dgii' => isset($ecf['dgii_response']) ? json_encode($ecf['dgii_response']) : null,
                ':rfce_xml' => $ecf['rfce_xml'] ?? null,
                ':rfce_track_id' => $ecf['rfce_track_id'] ?? null,
                ':rfce_estado' => $ecf['rfce_estado'] ?? null,
                ':rfce_respuesta' => isset($ecf['rfce_response']) ? json_encode($ecf['rfce_response']) : null,
            ]);
            $facturaId = (int) $this->conexion->lastInsertId();

            $itemSql = 'INSERT INTO factura_items
                (factura_id, description, amount, quantity, subtotal,
                 indicador_facturacion, indicador_bien_servicio, itbis_amount)
                VALUES
                (:factura_id, :description, :amount, :quantity, :subtotal,
                 :indicador_facturacion, :indicador_bien_servicio, :itbis_amount)';
            $itemStmt = $this->conexion->prepare($itemSql);
            foreach ($factura['items'] as $item) {
                $amount = (float) ($item['amount'] ?? 0);
                $quantity = (float) ($item['quantity'] ?? 1);
                $subtotal = isset($item['subtotal']) ? (float) $item['subtotal'] : $amount * $quantity;
                $itemStmt->execute([
                    ':factura_id' => $facturaId,
                    ':description' => $item['description'] ?? '',
                    ':amount' => $amount,
                    ':quantity' => $quantity,
                    ':subtotal' => $subtotal,
                    ':indicador_facturacion' => (int) ($item['indicador_facturacion'] ?? 1),
                    ':indicador_bien_servicio' => (int) ($item['indicador_bien_servicio'] ?? 2),
                    ':itbis_amount' => (float) ($item['itbis_amount'] ?? 0),
                ]);
            }

            $this->conexion->commit();
            return ['success', [
                'factura_id' => $facturaId,
                'e_ncf' => $ecf['e_ncf'],
                'track_id' => $ecf['track_id'] ?? null,
                'estado_dgii' => $ecf['estado'] ?? 'PENDIENTE',
                'codigo_seguridad' => $ecf['codigo_seguridad'] ?? null,
                'total' => $factura['total'],
            ]];
        } catch (PDOException $e) {
            if ($this->conexion->inTransaction()) {
                $this->conexion->rollBack();
            }
            return ['error', 'Failed to save factura with e-CF: ' . $e->getMessage()];
        }
    }

    public function updateECFEstado(int $facturaId, string $estado, ?array $dgiiResponse = null): bool
    {
        try {
            $sql = 'UPDATE facturas SET estado_dgii = :estado, respuesta_dgii = :resp WHERE id = :id';
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([
                ':estado' => $estado,
                ':resp' => $dgiiResponse !== null ? json_encode($dgiiResponse) : null,
                ':id' => $facturaId,
            ]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            return false;
        }
    }

    public function getXmlFirmado(int $facturaId, string $type = 'ecf'): ?array
    {
        $column = $type === 'rfce' ? 'rfce_xml' : 'xml_firmado';
        $sql = "SELECT e_ncf, tipo_ecf, {$column} AS xml FROM facturas WHERE id = :id";
        $stmt = $this->conexion->prepare($sql);
        $stmt->execute([':id' => $facturaId]);
        $row = $stmt->fetch();
        if (!$row || empty($row['xml'])) {
            return null;
        }
        return $row;
    }

    private function resolveActiveAmbiente(): ?string
    {
        $val = getenv('DGII_ECF_ENVIRONMENT');
        if ($val === false || $val === '') {
            $envFile = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env';
            if (is_file($envFile)) {
                $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (str_starts_with($line, 'DGII_ECF_ENVIRONMENT=')) {
                        $val = trim(substr($line, strlen('DGII_ECF_ENVIRONMENT=')), " '\"");
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

    public function getECFStats(): array
    {
        try {
            $ambiente = $this->resolveActiveAmbiente();
            $ambFilter = $ambiente !== null ? "AND ambiente_dgii = '{$ambiente}'" : '';

            $resumen = $this->conexion->query(
                "SELECT COUNT(*) as total_ecf,
                        COALESCE(SUM(total), 0) as monto_total,
                        COUNT(DISTINCT tipo_ecf) as tipos_distintos,
                        MIN(fecha_emision_dgii) as primer_ecf,
                        MAX(fecha_emision_dgii) as ultimo_ecf
                 FROM facturas WHERE tipo_ecf IS NOT NULL {$ambFilter}"
            )->fetch(PDO::FETCH_ASSOC);

            $porTipo = $this->conexion->query(
                "SELECT tipo_ecf,
                        COUNT(*) as total,
                        COALESCE(SUM(total), 0) as monto_total,
                        SUM(CASE WHEN estado_dgii = 'ACEPTADO' THEN 1 ELSE 0 END) as aceptados,
                        SUM(CASE WHEN estado_dgii LIKE 'RFCE_%' THEN 1 ELSE 0 END) as rfce,
                        SUM(CASE WHEN estado_dgii = 'RECHAZADO' THEN 1 ELSE 0 END) as rechazados,
                        SUM(CASE WHEN estado_dgii = 'ENVIADO' THEN 1 ELSE 0 END) as enviados,
                        MAX(fecha_emision_dgii) as ultimo_emitido
                 FROM facturas WHERE tipo_ecf IS NOT NULL {$ambFilter}
                 GROUP BY tipo_ecf ORDER BY tipo_ecf"
            )->fetchAll(PDO::FETCH_ASSOC);

            $porEstado = $this->conexion->query(
                "SELECT COALESCE(estado_dgii, 'PENDIENTE') as estado,
                        COUNT(*) as total,
                        COALESCE(SUM(total), 0) as monto_total
                 FROM facturas WHERE tipo_ecf IS NOT NULL {$ambFilter}
                 GROUP BY estado_dgii ORDER BY total DESC"
            )->fetchAll(PDO::FETCH_ASSOC);

            $porMes = $this->conexion->query(
                "SELECT DATE_FORMAT(fecha_emision_dgii, '%Y-%m') as mes,
                        COUNT(*) as total,
                        COALESCE(SUM(total), 0) as monto_total
                 FROM facturas
                 WHERE tipo_ecf IS NOT NULL {$ambFilter}
                   AND fecha_emision_dgii >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                 GROUP BY mes ORDER BY mes DESC"
            )->fetchAll(PDO::FETCH_ASSOC);

            $ambSeqFilter = $ambiente !== null ? "AND ns.ambiente = '{$ambiente}'" : "AND ns.ambiente = 'certecf'";
            $secuencias = $this->conexion->query(
                "SELECT ns.type, ns.current_value as secuencia_actual,
                        COALESCE(f.total_emitidos, 0) as total_emitidos
                 FROM ncf_sequences ns
                 LEFT JOIN (
                     SELECT CONCAT('E', tipo_ecf) as type, COUNT(*) as total_emitidos
                     FROM facturas WHERE tipo_ecf IS NOT NULL {$ambFilter} GROUP BY tipo_ecf
                 ) f ON ns.type = f.type
                 WHERE ns.type LIKE 'E%' {$ambSeqFilter}
                 ORDER BY ns.type"
            )->fetchAll(PDO::FETCH_ASSOC);

            return [
                'resumen'   => $resumen,
                'por_tipo'  => $porTipo,
                'por_estado' => $porEstado,
                'por_mes'   => $porMes,
                'secuencias' => $secuencias,
            ];
        } catch (PDOException $e) {
            return [
                'resumen'   => null,
                'por_tipo'  => [],
                'por_estado' => [],
                'por_mes'   => [],
                'secuencias' => [],
            ];
        }
    }

    public function getECFData(int $facturaId): ?array
    {
        try {
            $sql = 'SELECT id, no_factura, tipo_ecf, e_ncf, track_id, rfce_track_id, estado_dgii,
                           codigo_seguridad, fecha_emision_dgii, ambiente_dgii, respuesta_dgii
                    FROM facturas WHERE id = :id';
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([':id' => $facturaId]);
            $row = $stmt->fetch();
            return $row ?: null;
        } catch (PDOException $e) {
            return null;
        }
    }

    /**
     * Update only the NCF field for a factura
     * @param int $factura_id The factura ID
     * @param string $ncf The new NCF value
     * @return array Status array [status, message/data]
     */
    public function updateNCF($factura_id, $ncf)
    {
        try {
            // First check if factura exists
            $check_sql = "SELECT id FROM facturas WHERE id = :id";
            $check_stmt = $this->conexion->prepare($check_sql);
            $check_stmt->execute([':id' => $factura_id]);
            
            if (!$check_stmt->fetch()) {
                return ['error', 'Factura not found'];
            }

            // Update NCF
            $sql = "UPDATE facturas SET NCF = :ncf WHERE id = :id";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([
                ':id' => $factura_id,
                ':ncf' => $ncf
            ]);
            
            return ['success', [
                'id' => $factura_id,
                'NCF' => $ncf
            ]];
        } catch (PDOException $e) {
            return ['error', 'Failed to update NCF'];
        }
    }
}
