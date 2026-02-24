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
                $sql = "SELECT f.*, cl.client_name FROM facturas f LEFT JOIN clients cl ON f.client_id = cl.id";
                $stmt = $this->conexion->prepare($sql);
                $stmt->execute();
                $facturas = $stmt->fetchAll();
            } else {
                $sql = "SELECT f.*, cl.client_name FROM facturas f LEFT JOIN clients cl ON f.client_id = cl.id WHERE f.id = :id";
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
    public function saveFacturaWithItems($no_factura, $date, $client_id, $client_name, $total, $NCF, $items)
    {
        try {
            $this->conexion->beginTransaction();
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
                    mkdir($pdfPath, 0777, true);
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
                @mail($to, $subject, $message, $headers, $returnpath);
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

    public function updateFactura($id, $no_factura, $date, $client_id, $client_name, $total, $NCF)
    {
        try {
            $sql = "UPDATE facturas SET no_factura = :no_factura, date = :date, client_id = :client_id, client_name = :client_name, total = :total, NCF = :NCF WHERE id = :id";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([
                ':id' => $id,
                ':no_factura' => $no_factura,
                ':date' => $date,
                ':client_id' => $client_id,
                ':client_name' => $client_name,
                ':total' => $total,
                ':NCF' => $NCF
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

        // Pagination support with optional search query
    public function getFacturasPaginated($offset, $limit, $query = null)
    {
        try {
            $whereClause = "";
            if ($query) {
                $whereClause = "WHERE (f.no_factura LIKE :query OR cl.client_name LIKE :query OR f.NCF LIKE :query OR cl.rnc LIKE :query OR cl.company_name LIKE :query OR cl.phone_number LIKE :query OR cl.email LIKE :query)";
            }
            $sql = "SELECT f.*, cl.client_name FROM facturas f LEFT JOIN clients cl ON f.client_id = cl.id {$whereClause} ORDER BY f.id DESC LIMIT :limit OFFSET :offset";
            $stmt = $this->conexion->prepare($sql);
            if ($query) {
                $stmt->bindValue(':query', "%{$query}%", \PDO::PARAM_STR);
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
                $whereClause = "";
                $params = [];
                if ($query) {
                    $whereClause = "WHERE (f.no_factura LIKE :query OR cl.client_name LIKE :query OR f.NCF LIKE :query OR cl.rnc LIKE :query OR cl.company_name LIKE :query OR cl.phone_number LIKE :query OR cl.email LIKE :query)";
                    $params[':query'] = "%{$query}%";
                }
                $sql = "SELECT COUNT(*) as total FROM facturas f LEFT JOIN clients cl ON f.client_id = cl.id {$whereClause}";
                $stmt = $this->conexion->prepare($sql);
                if ($query) {
                    $stmt->execute($params);
                } else {
                    $stmt->execute();
                }
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
