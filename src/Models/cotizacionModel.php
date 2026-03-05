<?php
require_once(__DIR__ . '/../Database.php');

class cotizacionModel
{
    private $conexion;

    public function __construct()
    {
        $this->conexion = Database::getInstance()->getConnection();
    }

    public function getCotizaciones($id = null)
    {
        try {
            if ($id == null) {
                $sql = "SELECT c.*, cl.client_name FROM cotizaciones c LEFT JOIN clients cl ON c.client_id = cl.id";
                $stmt = $this->conexion->prepare($sql);
                $stmt->execute();
                $cotizaciones = $stmt->fetchAll();
            } else {
                $sql = "SELECT c.*, cl.client_name FROM cotizaciones c LEFT JOIN clients cl ON c.client_id = cl.id WHERE c.id = :id";
                $stmt = $this->conexion->prepare($sql);
                $stmt->execute([':id' => $id]);
                $cotizaciones = $stmt->fetchAll();
            }
            // Add concatenated description for each cotizacion
            foreach ($cotizaciones as &$cotizacion) {
                $cotizacion['description'] = $this->getCotizacionItemsDescription($cotizacion['id']);
                $cotizacion['items'] = $this->getCotizacionItems($cotizacion['id']);
            }
            return $cotizaciones;
        } catch (PDOException $e) {
            return [];
        }
    }

    public function getCotizacionItemsDescription($cotizacion_id)
    {
        try {
            $sql = "SELECT description FROM cotizacion_items WHERE cotizacion_id = :cotizacion_id ORDER BY id ASC";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([':cotizacion_id' => $cotizacion_id]);
            $descriptions = array_map(function($row) { return $row['description']; }, $stmt->fetchAll());
            return implode("\n", $descriptions);
        } catch (PDOException $e) {
            return '';
        }
    }

    // Pagination support with optional search query
    public function getCotizacionesPaginated($offset, $limit, $query = null)
    {
        try {
            $whereClause = "";
            if ($query) {
                $whereClause = "WHERE (c.code LIKE :query OR cl.client_name LIKE :query OR cl.rnc LIKE :query OR cl.company_name LIKE :query OR cl.phone_number LIKE :query OR cl.email LIKE :query)";
            }
            $sql = "SELECT c.*, cl.client_name FROM cotizaciones c LEFT JOIN clients cl ON c.client_id = cl.id {$whereClause} ORDER BY c.date DESC LIMIT :limit OFFSET :offset";
            $stmt = $this->conexion->prepare($sql);
            if ($query) {
                $stmt->bindValue(':query', "%{$query}%", \PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', (int)$limit, \PDO::PARAM_INT);
            $stmt->bindValue(':offset', (int)$offset, \PDO::PARAM_INT);
            $stmt->execute();
            $cotizaciones = $stmt->fetchAll();
            foreach ($cotizaciones as &$cotizacion) {
                $cotizacion['description'] = $this->getCotizacionItemsDescription($cotizacion['id']);
                $cotizacion['items'] = $this->getCotizacionItems($cotizacion['id']);
            }
            return $cotizaciones;
        } catch (PDOException $e) {
            return [];
        }
    }

    public function getCotizacionesCount($query = null)
    {
        try {
            $whereClause = "";
            $params = [];
            if ($query) {
                $whereClause = "WHERE (c.code LIKE :query OR cl.client_name LIKE :query OR cl.rnc LIKE :query OR cl.company_name LIKE :query OR cl.phone_number LIKE :query OR cl.email LIKE :query)";
                $params[':query'] = "%{$query}%";
            }
            $sql = "SELECT COUNT(*) as total FROM cotizaciones c LEFT JOIN clients cl ON c.client_id = cl.id {$whereClause}";
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

    public function saveCotizacion($client_id, $date, $items, $total, $user_id = null, $send_email = false)
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
            $sql = "INSERT INTO cotizaciones(code, date, client_id, total, user_id) VALUES(:code, :date, :client_id, :total, :user_id)";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([
                ':code' => $code,
                ':date' => $cotizacion_date,
                ':client_id' => $client_id,
                ':total' => $total,
                ':user_id' => $user_id
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
            // Only generate PDF and send email if send_email is true
            if (!$send_email) {
                return ['success', ['id' => $cotizacion_id, 'code' => $code, 'message' => 'Cotization saved']];
            }

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
                $cotizacionData[0]['items'] = $this->getCotizacionItems($cotizacion_id);
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

    public function updateCotizacion($id, $client_id, $date, $items, $total, $user_id = null, $send_email = false)
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
            $sql = "UPDATE cotizaciones SET client_id = :client_id, date = :date, total = :total, user_id = :user_id, updated_at = :updated_at WHERE id = :id";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([
                ':id' => $id,
                ':client_id' => $client_id,
                ':date' => $cotizacion_date,
                ':total' => $total,
                ':user_id' => $user_id,
                ':updated_at' => date('Y-m-d H:i:s')
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

            if (!$send_email) {
                return ['success', 'Cotization updated'];
            }

            // --- PDF Generation and Email Sending ---
            // Get the cotizacion code
            $codeSql = "SELECT code FROM cotizaciones WHERE id = :id";
            $codeStmt = $this->conexion->prepare($codeSql);
            $codeStmt->execute([':id' => $id]);
            $codeRow = $codeStmt->fetch();
            $code = $codeRow ? $codeRow['code'] : $id;

            // 1. Generate PDF and save to cotizaciones/ folder
            $pdfPath = __DIR__ . '/../../cotizaciones/';
            if (!is_dir($pdfPath)) {
                mkdir($pdfPath, 0777, true);
            }
            $pdfFile = $pdfPath . 'Cotizacion_' . $code . '.pdf';
            require_once(__DIR__ . '/../Utils/CotizacionPdfGenerator.php');
            $cotizacionData = $this->getCotizaciones($id);
            if ($cotizacionData && isset($cotizacionData[0])) {
                $cotizacionData[0]['items'] = $this->getCotizacionItems($id);
                $pdfContent = generateCotizacionPdf($cotizacionData[0], 'S');
                file_put_contents($pdfFile, $pdfContent);
            }

            // 2. Send email with PDF attached
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

            $headers = "From: $fromName <$from>\r\n";
            $headers .= "MIME-Version: 1.0\r\n";
            $semi_rand = md5(time());
            $mime_boundary = "==Multipart_Boundary_x{$semi_rand}x";
            $headers .= "Content-Type: multipart/mixed;\r\n boundary=\"{$mime_boundary}\"";

            $message = "--{$mime_boundary}\r\n";
            $message .= "Content-Type: text/html; charset=\"UTF-8\"\r\n";
            $message .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
            $message .= $htmlContent . "\r\n\r\n";

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
            $message .= "--{$mime_boundary}--\r\n";
            $returnpath = "-f" . $from;
            @mail($to, $subject, $message, $headers, $returnpath);

            return ['success', 'Cotization updated and emailed'];
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