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

    // =========================================================================
    // CRUD de facturas NO electronicas (no e-CF).
    // Una factura simple es la que tiene `tipo_ecf IS NULL`: una factura interna
    // que NO se emitio a la DGII. Todos los metodos filtran/validan por ese
    // discriminador para no tocar nunca un e-CF emitido.
    // =========================================================================

    /**
     * Normaliza las lineas recibidas a la forma de factura_items.
     * @param array $items Lineas crudas (description, quantity, amount, ...)
     * @return array Lineas normalizadas listas para insertar
     */
    private function normalizeSimpleItems(array $items): array
    {
        $normalized = [];
        foreach ($items as $raw) {
            $raw = (array) $raw;
            $quantity = (float) ($raw['quantity'] ?? $raw['cantidad'] ?? 1);
            $amount = (float) ($raw['amount'] ?? $raw['precio_unitario'] ?? 0);
            $subtotal = isset($raw['subtotal']) && $raw['subtotal'] !== ''
                ? (float) $raw['subtotal']
                : round($quantity * $amount, 2);
            $itbis = isset($raw['itbis_amount']) && $raw['itbis_amount'] !== ''
                ? (float) $raw['itbis_amount']
                : 0.0;
            $normalized[] = [
                'description' => (string) ($raw['description'] ?? $raw['descripcion'] ?? ''),
                'amount' => $amount,
                'quantity' => $quantity,
                'subtotal' => $subtotal,
                'indicador_facturacion' => (int) ($raw['indicador_facturacion'] ?? 1),
                'indicador_bien_servicio' => (int) ($raw['indicador_bien_servicio'] ?? 1),
                'itbis_amount' => $itbis,
            ];
        }
        return $normalized;
    }

    private function insertSimpleItems(int $facturaId, array $items): void
    {
        $sql = 'INSERT INTO factura_items
                (factura_id, description, amount, quantity, subtotal,
                 indicador_facturacion, indicador_bien_servicio, itbis_amount)
                VALUES
                (:factura_id, :description, :amount, :quantity, :subtotal,
                 :indicador_facturacion, :indicador_bien_servicio, :itbis_amount)';
        $stmt = $this->conexion->prepare($sql);
        foreach ($items as $it) {
            $stmt->execute([
                ':factura_id' => $facturaId,
                ':description' => $it['description'],
                ':amount' => $it['amount'],
                ':quantity' => $it['quantity'],
                ':subtotal' => $it['subtotal'],
                ':indicador_facturacion' => $it['indicador_facturacion'],
                ':indicador_bien_servicio' => $it['indicador_bien_servicio'],
                ':itbis_amount' => $it['itbis_amount'],
            ]);
        }
    }

    private function sumSimpleTotal(array $items): float
    {
        $total = 0.0;
        foreach ($items as $it) {
            $total += (float) $it['subtotal'] + (float) $it['itbis_amount'];
        }
        return round($total, 2);
    }

    /**
     * Crea una factura no electronica con sus lineas.
     * @param array $data {no_factura, client_id?, client_name, user_id, date?, NCF?, total?, items[]}
     * @return array ['success', payload] | ['error', mensaje]
     */
    public function createFacturaSimple(array $data): array
    {
        $items = $this->normalizeSimpleItems($data['items'] ?? []);
        if (empty($items)) {
            return ['error', 'items requerido (al menos una linea)'];
        }
        $total = isset($data['total']) && $data['total'] !== ''
            ? (float) $data['total']
            : $this->sumSimpleTotal($items);

        try {
            $this->conexion->beginTransaction();
            $sql = 'INSERT INTO facturas
                    (no_factura, date, client_id, client_name, user_id, total, NCF, tipo_ecf)
                    VALUES
                    (:no_factura, :date, :client_id, :client_name, :user_id, :total, :NCF, NULL)';
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([
                ':no_factura' => $data['no_factura'],
                ':date' => $data['date'] ?? date('Y-m-d H:i:s'),
                ':client_id' => $data['client_id'] ?? null,
                ':client_name' => $data['client_name'] ?? '',
                ':user_id' => $data['user_id'],
                ':total' => $total,
                ':NCF' => $data['NCF'] ?? null,
            ]);
            $facturaId = (int) $this->conexion->lastInsertId();
            $this->insertSimpleItems($facturaId, $items);
            $this->conexion->commit();

            return ['success', $this->getFacturaSimple($facturaId)];
        } catch (PDOException $e) {
            if ($this->conexion->inTransaction()) {
                $this->conexion->rollBack();
            }
            return ['error', 'No se pudo crear la factura: ' . $e->getMessage()];
        }
    }

    /**
     * Obtiene una factura no electronica por id, con sus lineas.
     * @return array|null Null si no existe o si es un e-CF.
     */
    public function getFacturaSimple(int $id): ?array
    {
        try {
            $sql = 'SELECT f.*, cl.company_name, cl.email AS client_email,
                           cl.phone_number AS client_phone, cl.rnc AS client_rnc
                    FROM facturas f LEFT JOIN clients cl ON f.client_id = cl.id
                    WHERE f.id = :id AND f.tipo_ecf IS NULL';
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch();
            if (!$row) {
                return null;
            }
            $row['items'] = $this->getFacturaItems($id);
            return $row;
        } catch (PDOException $e) {
            return null;
        }
    }

    public function getFacturasSimplesPaginated(int $offset, int $limit, ?string $query = null): array
    {
        try {
            $conditions = ['f.tipo_ecf IS NULL'];
            $params = [];
            if ($query) {
                $conditions[] = '(f.no_factura LIKE :query OR f.NCF LIKE :query OR f.client_name LIKE :query OR cl.company_name LIKE :query)';
                $params[':query'] = "%{$query}%";
            }
            $whereClause = 'WHERE ' . implode(' AND ', $conditions);
            $sql = "SELECT f.*, cl.company_name FROM facturas f
                    LEFT JOIN clients cl ON f.client_id = cl.id
                    {$whereClause} ORDER BY f.id DESC LIMIT :limit OFFSET :offset";
            $stmt = $this->conexion->prepare($sql);
            foreach ($params as $key => $val) {
                $stmt->bindValue($key, $val, \PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', (int) $limit, \PDO::PARAM_INT);
            $stmt->bindValue(':offset', (int) $offset, \PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }

    public function getFacturasSimplesCount(?string $query = null): int
    {
        try {
            $conditions = ['f.tipo_ecf IS NULL'];
            $params = [];
            if ($query) {
                $conditions[] = '(f.no_factura LIKE :query OR f.NCF LIKE :query OR f.client_name LIKE :query OR cl.company_name LIKE :query)';
                $params[':query'] = "%{$query}%";
            }
            $whereClause = 'WHERE ' . implode(' AND ', $conditions);
            $sql = "SELECT COUNT(*) AS total FROM facturas f
                    LEFT JOIN clients cl ON f.client_id = cl.id {$whereClause}";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch();
            return $row ? (int) $row['total'] : 0;
        } catch (PDOException $e) {
            return 0;
        }
    }

    /**
     * Actualiza una factura no electronica. Campos no enviados conservan su valor.
     * Si se envia `items`, reemplaza todas las lineas. Rechaza e-CF emitidos.
     * @return array ['success', payload] | ['error', mensaje]
     */
    public function updateFacturaSimple(int $id, array $data): array
    {
        try {
            $cur = $this->conexion->prepare('SELECT * FROM facturas WHERE id = :id');
            $cur->execute([':id' => $id]);
            $row = $cur->fetch();
            if (!$row) {
                return ['error', 'Factura no encontrada'];
            }
            if ($row['tipo_ecf'] !== null) {
                return ['error', 'Esta factura es un e-CF emitido; no puede editarse por esta via'];
            }

            $noFactura = $data['no_factura'] ?? $row['no_factura'];
            $date = $data['date'] ?? $row['date'];
            $clientId = array_key_exists('client_id', $data) ? $data['client_id'] : $row['client_id'];
            $clientName = $data['client_name'] ?? $row['client_name'];
            $ncf = array_key_exists('NCF', $data) ? $data['NCF'] : $row['NCF'];

            $replaceItems = isset($data['items']) && is_array($data['items']);
            $items = $replaceItems ? $this->normalizeSimpleItems($data['items']) : [];

            if (isset($data['total']) && $data['total'] !== '') {
                $total = (float) $data['total'];
            } elseif ($replaceItems) {
                $total = $this->sumSimpleTotal($items);
            } else {
                $total = (float) $row['total'];
            }

            $this->conexion->beginTransaction();
            $upd = $this->conexion->prepare(
                'UPDATE facturas SET no_factura = :no_factura, date = :date,
                        client_id = :client_id, client_name = :client_name,
                        total = :total, NCF = :NCF
                 WHERE id = :id AND tipo_ecf IS NULL'
            );
            $upd->execute([
                ':no_factura' => $noFactura,
                ':date' => $date,
                ':client_id' => $clientId,
                ':client_name' => $clientName,
                ':total' => $total,
                ':NCF' => $ncf,
                ':id' => $id,
            ]);

            if ($replaceItems) {
                $del = $this->conexion->prepare('DELETE FROM factura_items WHERE factura_id = :id');
                $del->execute([':id' => $id]);
                $this->insertSimpleItems($id, $items);
            }

            $this->conexion->commit();
            return ['success', $this->getFacturaSimple($id)];
        } catch (PDOException $e) {
            if ($this->conexion->inTransaction()) {
                $this->conexion->rollBack();
            }
            return ['error', 'No se pudo actualizar la factura: ' . $e->getMessage()];
        }
    }

    /**
     * Elimina una factura no electronica y sus lineas. Rechaza e-CF emitidos.
     * @return array ['success', mensaje] | ['error', mensaje]
     */
    public function deleteFacturaSimple(int $id): array
    {
        try {
            $cur = $this->conexion->prepare('SELECT tipo_ecf FROM facturas WHERE id = :id');
            $cur->execute([':id' => $id]);
            $row = $cur->fetch();
            if (!$row) {
                return ['error', 'Factura no encontrada'];
            }
            if ($row['tipo_ecf'] !== null) {
                return ['error', 'Esta factura es un e-CF emitido; no puede eliminarse'];
            }

            $this->conexion->beginTransaction();
            $this->conexion->prepare('DELETE FROM factura_items WHERE factura_id = :id')->execute([':id' => $id]);
            $this->conexion->prepare('DELETE FROM facturas WHERE id = :id AND tipo_ecf IS NULL')->execute([':id' => $id]);
            $this->conexion->commit();
            return ['success', 'Factura eliminada'];
        } catch (PDOException $e) {
            if ($this->conexion->inTransaction()) {
                $this->conexion->rollBack();
            }
            return ['error', 'No se pudo eliminar la factura: ' . $e->getMessage()];
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

            // InformacionReferencia (Notas E33/E34): se persiste para mostrar el
            // NCF Modificado y el Motivo en la Representacion Impresa (norma DGII).
            // La razon se completa con un default si viene vacia, igual que
            // ECFXmlBuilder::normalizeNotaReferenceData(), para que el PDF y el
            // XML firmado muestren el mismo texto.
            $infoRef = is_array($factura['informacion_referencia'] ?? null) ? $factura['informacion_referencia'] : [];
            $tipoEcfNota = (string) ($ecf['tipo_ecf'] ?? '');
            $ncfModificado = ($infoRef['ncf_modificado'] ?? '') !== '' ? (string) $infoRef['ncf_modificado'] : null;
            $codigoModificacion = ($infoRef['codigo_modificacion'] ?? '') !== '' ? (string) $infoRef['codigo_modificacion'] : null;
            $razonModificacion = (string) ($infoRef['razon_modificacion'] ?? '');
            if (in_array($tipoEcfNota, ['33', '34'], true) && $razonModificacion === '') {
                $razonModificacion = $tipoEcfNota === '34'
                    ? 'Nota de credito por ajuste de monto'
                    : 'Nota de debito por ajuste de monto';
            }
            $razonModificacion = $razonModificacion !== '' ? $razonModificacion : null;
            // El payload trae la fecha en formato d-m-Y; la columna es DATE (Y-m-d).
            $fechaNcfModificado = null;
            if (($infoRef['fecha_ncf_modificado'] ?? '') !== '') {
                $dt = DateTime::createFromFormat('d-m-Y', (string) $infoRef['fecha_ncf_modificado']);
                $fechaNcfModificado = $dt ? $dt->format('Y-m-d') : null;
            }

            $sql = 'INSERT INTO facturas
                (no_factura, date, client_id, client_name, total, NCF, user_id,
                 tipo_ecf, e_ncf, track_id, estado_dgii, codigo_seguridad,
                 fecha_emision_dgii, ambiente_dgii, xml_firmado, respuesta_dgii,
                 rfce_xml, rfce_track_id, rfce_estado, rfce_respuesta,
                 ncf_modificado, fecha_ncf_modificado, codigo_modificacion, razon_modificacion)
                VALUES
                (:no_factura, :date, :client_id, :client_name, :total, NULL, :user_id,
                 :tipo_ecf, :e_ncf, :track_id, :estado_dgii, :codigo_seguridad,
                 :fecha_emision_dgii, :ambiente_dgii, :xml_firmado, :respuesta_dgii,
                 :rfce_xml, :rfce_track_id, :rfce_estado, :rfce_respuesta,
                 :ncf_modificado, :fecha_ncf_modificado, :codigo_modificacion, :razon_modificacion)';
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
                ':ncf_modificado' => $ncfModificado,
                ':fecha_ncf_modificado' => $fechaNcfModificado,
                ':codigo_modificacion' => $codigoModificacion,
                ':razon_modificacion' => $razonModificacion,
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
            // DGII devuelve `secuenciaUtilizada` (bool) en la consulta de estado.
            // false => el e-NCF puede reutilizarse en un nuevo envio. NULL si no viene.
            $secuenciaUtilizada = null;
            if (is_array($dgiiResponse) && array_key_exists('secuenciaUtilizada', $dgiiResponse)) {
                $secuenciaUtilizada = (int) filter_var($dgiiResponse['secuenciaUtilizada'], FILTER_VALIDATE_BOOLEAN);
            }
            $sql = 'UPDATE facturas SET estado_dgii = :estado, respuesta_dgii = :resp,
                           secuencia_utilizada = :secuencia WHERE id = :id';
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([
                ':estado' => $estado,
                ':resp' => $dgiiResponse !== null ? json_encode($dgiiResponse) : null,
                ':secuencia' => $secuenciaUtilizada,
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

    public function getActiveAmbiente(): ?string
    {
        return $this->resolveActiveAmbiente();
    }

    private function resolveActiveAmbiente(): ?string
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
                           secuencia_utilizada, codigo_seguridad, fecha_emision_dgii, ambiente_dgii, respuesta_dgii
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
