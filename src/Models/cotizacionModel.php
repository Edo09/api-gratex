<?php
require_once(__DIR__ . '/../Database.php');

class cotizacionModel
{
    private $conexion;

    public function __construct()
    {
        $this->conexion = Database::getInstance()->getConnection();
    }

    public function getCotizaciones($id = null, $page = 1, $pageSize = 10, $query = null)
    {
        try {
            if ($id == null) {
                // Build WHERE clause for query search
                $whereClause = "";
                $params = [];
                if ($query) {
                    $whereClause = "WHERE (code LIKE :query OR client_name LIKE :query)";
                    $params[':query'] = "%{$query}%";
                }
                // Get total count
                $countSql = "SELECT COUNT(*) as total FROM cotizaciones {$whereClause}";
                $countStmt = $this->conexion->prepare($countSql);
                if ($query) {
                    $countStmt->execute($params);
                } else {
                    $countStmt->execute();
                }
                $countResult = $countStmt->fetch();
                $total = $countResult['total'];
                // Calculate offset
                $offset = ((int)$page - 1) * (int)$pageSize;
                $pageSize = (int)$pageSize;
                $offset = (int)$offset;
                // Fetch paginated results
                $sql = "SELECT * FROM cotizaciones {$whereClause} ORDER BY date DESC LIMIT {$pageSize} OFFSET {$offset}";
                $stmt = $this->conexion->prepare($sql);
                if ($query) {
                    $stmt->execute($params);
                } else {
                    $stmt->execute();
                }
                $data = $stmt->fetchAll();

                // For each cotizacion, fetch item descriptions and join with line breaks
                foreach ($data as &$cotizacion) {
                    $itemsSql = "SELECT description FROM cotizacion_items WHERE cotizacion_id = :cotizacion_id";
                    $itemsStmt = $this->conexion->prepare($itemsSql);
                    $itemsStmt->execute([':cotizacion_id' => $cotizacion['id']]);
                    $descriptions = array_column($itemsStmt->fetchAll(), 'description');
                    $cotizacion['description'] = implode("\n", $descriptions);
                }
                unset($cotizacion);

                return [
                    'total' => (int)$total,
                    'page' => (int)$page,
                    'pageSize' => (int)$pageSize,
                    'data' => $data
                ];
            } else {
                // Get single record by ID with items
                $sql = "SELECT * FROM cotizaciones WHERE id = :id";
                $stmt = $this->conexion->prepare($sql);
                $stmt->execute([':id' => $id]);
                $cotizacion = $stmt->fetch();
                
                if ($cotizacion) {
                    // Get items for this cotizacion
                    $itemsSql = "SELECT id, description, amount, quantity, subtotal FROM cotizacion_items WHERE cotizacion_id = :cotizacion_id";
                    $itemsStmt = $this->conexion->prepare($itemsSql);
                    $itemsStmt->execute([':cotizacion_id' => $id]);
                    $cotizacion['items'] = $itemsStmt->fetchAll();
                    return [$cotizacion];
                }
                return [];
            }
        } catch (PDOException $e) {
            return ($id == null) ? ['total' => 0, 'page' => 0, 'pageSize' => 0, 'data' => []] : [];
        }
    }

    public function saveCotizacion($client_id, $client_name, $date, $items, $total)
    {
        try {
            // Custom code generation logic with uniqueness check
            $numberkey = 'ABCDEFGHIJKLMNOPQRSTUVWXY';
            $alphakey = '0123456789';
            $maxAttempts = 10;
            $attempts = 0;
            do {
                $randalpha = substr(str_shuffle($alphakey), 0, 3);
                $randnumber = substr(str_shuffle($numberkey), 0, 3);
                $code = $randnumber . $randalpha;
                // Check if code exists
                $checkSql = "SELECT COUNT(*) FROM cotizaciones WHERE code = :code";
                $checkStmt = $this->conexion->prepare($checkSql);
                $checkStmt->execute([':code' => $code]);
                $exists = $checkStmt->fetchColumn() > 0;
                $attempts++;
            } while ($exists && $attempts < $maxAttempts);
            if ($exists) {
                return ['error', 'Failed to generate unique code after multiple attempts'];
            }

            // Use provided date or current date
            $cotizacion_date = !empty($date) ? $date : date('Y-m-d H:i:s');

            // Begin transaction
            $this->conexion->beginTransaction();

            // Insert main cotizacion record
            $sql = "INSERT INTO cotizaciones(code, date, client_id, client_name, total) VALUES(:code, :date, :client_id, :client_name, :total)";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([
                ':code' => $code,
                ':date' => $cotizacion_date,
                ':client_id' => $client_id,
                ':client_name' => $client_name,
                ':total' => $total
            ]);

            $cotizacion_id = $this->conexion->lastInsertId();

            // Insert items
            $itemSql = "INSERT INTO cotizacion_items(cotizacion_id, description, amount, quantity, subtotal) VALUES(:cotizacion_id, :description, :amount, :quantity, :subtotal)";
            $itemStmt = $this->conexion->prepare($itemSql);

            foreach ($items as $item) {
                $itemStmt->execute([
                    ':cotizacion_id' => $cotizacion_id,
                    ':description' => $item->description ?? $item['description'],
                    ':amount' => $item->amount ?? $item['amount'],
                    ':quantity' => $item->quantity ?? $item['quantity'],
                    ':subtotal' => $item->subtotal ?? $item['subtotal']
                ]);
            }

            // Commit transaction
            $this->conexion->commit();

            // --- PDF Generation and Email Sending ---
            // 1. Generate PDF and save to cotizaciones/ folder
            $pdfPath = __DIR__ . '/../../cotizaciones/';
            if (!is_dir($pdfPath)) {
                mkdir($pdfPath, 0777, true);
            }
            $pdfFile = $pdfPath . 'Cotizacion_' . $code . '.pdf';
            // Use PDF generator utility (generateCotizacionPdf helper)
            require_once(__DIR__ . '/../Utils/CotizacionPdfGenerator.php');
            // Fetch cotizacion data for PDF (with items)
            $cotizacionData = $this->getCotizaciones($cotizacion_id);
            if ($cotizacionData && isset($cotizacionData[0])) {
                $pdfContent = generateCotizacionPdf($cotizacionData[0], 'S');
                file_put_contents($pdfFile, $pdfContent);
            }

            // 2. Send email with PDF attached
            // Get client email from DB
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
            $from = "info@gratex.net";
            $fromName = "Gratex";
            $subject = "Cotizacion anexa";
            $file = $pdfFile;
            $htmlContent = '<p>Estimado cliente:<br/> Su Cotizaci&oacute;n <b>' . $code . '</b> se encuentra anexa a este mensaje.</p>';

            // Prepare headers
            $headers = "From: $fromName <$from>\r\n";
            $headers .= "MIME-Version: 1.0\r\n";
            $semi_rand = md5(time());
            $mime_boundary = "==Multipart_Boundary_x{$semi_rand}x";
            $headers .= "Content-Type: multipart/mixed;\r\n boundary=\"{$mime_boundary}\"";

            // Multipart boundary
            $message = "--{$mime_boundary}\r\n";
            $message .= "Content-Type: text/html; charset=\"UTF-8\"\r\n";
            $message .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
            $message .= $htmlContent . "\r\n\r\n";

            // Attachment section
            if (!empty($file) && is_file($file)) {
                $fp = fopen($file, "rb");
                $data = fread($fp, filesize($file));
                fclose($fp);
                $data = chunk_split(base64_encode($data));
                $message .= "--{$mime_boundary}\r\n";
                $message .= "Content-Type: application/octet-stream; name=\"" . basename($file) . "\"\r\n";
                $message .= "Content-Description: " . basename($file) . "\r\n";
                $message .= "Content-Disposition: attachment; filename=\"" . basename($file) . "\"; size=" . filesize($file) . ";\r\n";
                $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
                $message .= $data . "\r\n\r\n";
            }
            // End boundary
            $message .= "--{$mime_boundary}--\r\n";
            // Return path for the email
            $returnpath = "-f" . $from;
            // Send email
            @mail($to, $subject, $message, $headers, $returnpath);

            return ['success', ['id' => $cotizacion_id, 'code' => $code, 'message' => 'Cotization saved and emailed']];
        } catch (PDOException $e) {
            $this->conexion->rollBack();
            return ['error', 'Failed to save cotization: ' . $e->getMessage()];
        }
    }

    public function updateCotizacion($id, $client_id, $client_name, $date, $items, $total)
    {
        try {
            $existe = $this->getCotizaciones($id);
            if (count($existe) == 0) {
                return ['error', "There is no cotization with ID {$id}"];
            }
            
            // Begin transaction
            $this->conexion->beginTransaction();
            
            // Use provided date or keep existing
            $cotizacion_date = !empty($date) ? $date : $existe[0]['date'];
            
            // Update main cotizacion record
            $sql = "UPDATE cotizaciones SET client_id = :client_id, client_name = :client_name, date = :date, total = :total WHERE id = :id";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([
                ':id' => $id,
                ':client_id' => $client_id,
                ':client_name' => $client_name,
                ':date' => $cotizacion_date,
                ':total' => $total
            ]);
            
            // Delete existing items
            $deleteSql = "DELETE FROM cotizacion_items WHERE cotizacion_id = :cotizacion_id";
            $deleteStmt = $this->conexion->prepare($deleteSql);
            $deleteStmt->execute([':cotizacion_id' => $id]);
            
            // Insert new items
            $itemSql = "INSERT INTO cotizacion_items(cotizacion_id, description, amount, quantity, subtotal) VALUES(:cotizacion_id, :description, :amount, :quantity, :subtotal)";
            $itemStmt = $this->conexion->prepare($itemSql);
            
            foreach ($items as $item) {
                $itemStmt->execute([
                    ':cotizacion_id' => $id,
                    ':description' => $item->description ?? $item['description'],
                    ':amount' => $item->amount ?? $item['amount'],
                    ':quantity' => $item->quantity ?? $item['quantity'],
                    ':subtotal' => $item->subtotal ?? $item['subtotal']
                ]);
            }
            
            // Commit transaction
            $this->conexion->commit();
            
            return ['success', 'Cotization updated'];
        } catch (PDOException $e) {
            $this->conexion->rollBack();
            return ['error', 'Failed to update cotization: ' . $e->getMessage()];
        }
    }

    public function deleteCotizacion($id)
    {
        try {
            // Check if cotizacion exists
            $sql = "SELECT id FROM cotizaciones WHERE id = :id";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([':id' => $id]);
            $existe = $stmt->fetch();
            
            if (!$existe) {
                return ['error', "Cotization not found {$id}"];
            }
            
            // Delete cotizacion (items will be deleted via CASCADE)
            $sql = "DELETE FROM cotizaciones WHERE id = :id";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([':id' => $id]);
            return ['success', 'Cotization deleted'];
        } catch (PDOException $e) {
            return ['error', 'Failed to delete cotization: ' . $e->getMessage()];
        }
    }

    /**
     * Get items for a specific cotizacion
     * @param int $cotizacion_id Cotizacion ID
     * @return array Array of items
     */
    public function getCotizacionItems($cotizacion_id)
    {
        try {
            $sql = "SELECT id, description, amount, quantity, subtotal FROM cotizacion_items WHERE cotizacion_id = :cotizacion_id";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([':cotizacion_id' => $cotizacion_id]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }
}
?>