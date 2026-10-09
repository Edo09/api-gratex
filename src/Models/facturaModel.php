<?php
require_once(__DIR__ . '/../Database.php');
require_once(__DIR__ . '/../AmbienteResolver.php');
require_once(__DIR__ . '/ReporteVentasModel.php');
require_once(__DIR__ . '/ncfModel.php');

class facturaModel
{
    private $conexion;

    /**
     * Tercer elemento de ['error', mensaje, codigo] cuando la factura simple no
     * existe. facturaSimpleController decide el 404 por este codigo y no por el
     * texto, para que el mensaje se pueda reescribir sin romper nada.
     */
    public const ERROR_NO_ENCONTRADA = 'no_encontrada';

    /** La factura simple desaparecio entre que se abrio y se guardo/elimino. */
    public const MSG_SIMPLE_YA_NO_EXISTE = 'Esta factura ya no existe. Puede que la hayan eliminado; vuelve al listado.';

    /**
     * Decimales de cantidad de una factura simple: los de
     * factura_items.quantity DECIMAL(12,3). No va a la DGII, asi que no la ata
     * el limite de 2 del e-CF (EcfItemMapper::DECIMALES_CANTIDAD).
     */
    public const DECIMALES_CANTIDAD_SIMPLE = 3;

    /** Tipos e-CF que modifican otro comprobante: nota de debito y de credito. */
    public const TIPOS_NOTA = ['33', '34'];

    /**
     * Estados en que una nota E33/E34 cuenta contra la factura que modifica:
     * los que la DGII tiene (aceptada o en proceso). Una en ERROR o
     * NO_ENCONTRADO (o su intento archivado) no llego a valer. Lo usan
     * sqlReferencia() (saldo para una nota nueva) y vincularNotas() (listado y
     * detalle), para que pantalla y emision cuenten las mismas notas.
     */
    public const ESTADOS_NOTA_VIGENTE = ['ACEPTADO', 'ACEPTADO_CONDICIONAL', 'EN_PROCESO', 'ENVIADO'];

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
            $sql = "SELECT id, product_id, description, amount, quantity, subtotal, descuento_monto, itbis_amount, indicador_facturacion, indicador_bien_servicio, unidad_medida FROM factura_items WHERE factura_id = :factura_id ORDER BY id ASC";
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

                // Generate PDF and save to facturas/<tenant_id>/ (los numeros se
                // repiten entre tenants)
                require_once(__DIR__ . '/../Utils/TenantMail.php');
                $pdfPath = TenantMail::carpetaPdf(TenantMail::tenantActual(), 'facturas');
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

                // Identidad del tenant (TenantMail): Gratex como siempre; otro
                // tenant desde su emisor_config.correo y sin copias a Gratex.
                $remitente = TenantMail::remitenteDelTenant();
                $to = TenantMail::destinatarios(TenantMail::tenantActual(), $clientEmail, TenantMail::COPIAS_GRATEX_DOCUMENTOS);
                $subject = 'Factura anexa';
                $htmlContent = '<p>Estimado cliente:<br/> Su Factura <b>' . $no_factura . '</b> se encuentra anexa a este mensaje.</p>';

                $headers = $remitente !== null ? TenantMail::cabeceraFrom($remitente) : '';
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
                // Envio desactivado. Si se reactiva: sin remitente (tenant sin
                // correo) o sin destinatarios no se manda.
                // if ($remitente !== null && $to !== '') {
                //     @mail($to, $subject, $message, $headers, TenantMail::parametroEnvelope($remitente));
                // }
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
    /**
     * Normaliza las lineas de una factura simple al formato que se persiste y
     * que entiende el generador de PDF. Publica a proposito: el preview del
     * controller la usa tal cual, porque una vista previa que no pase por las
     * mismas reglas que el guardado deja de parecerse al documento final.
     *
     * Una factura simple NO lleva ITBIS: es un documento interno, no se emite a
     * la DGII y no entra en el 606/607. `itbis_amount` se fija en 0 (la columna
     * es comun con el e-CF) y no se deriva de ninguna tasa.
     */
    public function normalizeSimpleItems(array $items): array
    {
        $normalized = [];
        // La unidad de una linea del catalogo es la del producto: el formulario
        // de la factura simple no la manda, y sin esto todo se guardaba (e
        // imprimia) como "Unidad" (43), aunque fueran metros o kilos.
        $unidadesProducto = $this->unidadesDeProductos(
            array_map(static fn($it) => ((array) $it)['product_id'] ?? null, $items)
        );
        foreach ($items as $raw) {
            $raw = (array) $raw;
            // Cantidad a 3 decimales y precio a 4: lo que guardan
            // factura_items.quantity DECIMAL(12,3) y amount DECIMAL(18,4). Asi el
            // subtotal se calcula con lo mismo que queda en la base (y que ve la
            // vista previa). La cantidad ya llego validada
            // (problemaCantidadesSimples): esto solo limpia el ruido binario.
            $quantity = round((float) ($raw['quantity'] ?? $raw['cantidad'] ?? 1), self::DECIMALES_CANTIDAD_SIMPLE);
            $amount = round((float) ($raw['amount'] ?? $raw['precio_unitario'] ?? 0), 4);
            // Descuento en MONTO, acotado a [0, bruto]. El subtotal va neto de el.
            $bruto = round($quantity * $amount, 2);
            $descuento = isset($raw['descuento_monto']) && is_numeric($raw['descuento_monto'])
                ? max(0.0, min($bruto, round((float) $raw['descuento_monto'], 2)))
                : 0.0;
            $subtotal = isset($raw['subtotal']) && $raw['subtotal'] !== ''
                ? (float) $raw['subtotal']
                : round($bruto - $descuento, 2);
            $normalized[] = [
                // Linea del catalogo: se guarda para poder descontar inventario.
                'product_id' => !empty($raw['product_id']) ? (int) $raw['product_id'] : null,
                'description' => (string) ($raw['description'] ?? $raw['descripcion'] ?? ''),
                'amount' => $amount,
                'quantity' => $quantity,
                'subtotal' => $subtotal,
                'descuento_monto' => $descuento,
                // Columnas compartidas con el e-CF. En una factura simple no
                // significan nada fiscal: se guardan en su valor neutro para no
                // dejar NULL en el esquema comun.
                'indicador_facturacion' => 1,
                'indicador_bien_servicio' => (int) ($raw['indicador_bien_servicio'] ?? 1),
                'unidad_medida' => (string) (($raw['unidad_medida'] ?? '') !== ''
                    ? $raw['unidad_medida']
                    : ($unidadesProducto[(int) ($raw['product_id'] ?? 0)] ?? '43')),
                'itbis_amount' => 0.0,
            ];
        }
        return $normalized;
    }

    /**
     * Que esta mal en las cantidades de una factura simple, en palabras del
     * usuario ("Línea 2: la unidad «Unidad» no admite fracciones…"), o null si
     * todas estan bien. Se mira lo que mando el usuario, ANTES de que
     * normalizeSimpleItems redondee: 1.2345 no pasa en silencio a 1.235.
     *
     * Unidad con la que se juzga cada linea: la suya si viene; si no, la del
     * producto del catalogo (el formulario de la factura simple no manda la
     * unidad); una linea libre sin ninguna de las dos no tiene regla de unidad,
     * solo mayor que 0 y hasta 3 decimales. Fail-open como el catalogo: si no se
     * pueden leer los productos, se juzga sin unidad.
     *
     * $previas: las lineas guardadas de la factura que se edita. Una linea que
     * repite la cantidad de una de ellas (ver cantidadesHeredadas) tampoco se
     * juzga con la regla de la unidad: una factura vieja pudo guardar 1.5 m en
     * un producto que hoy se cuenta entero, y sin esto no se podia cambiar ni
     * la fecha. Mayor que 0 y hasta 3 decimales se exigen igual; una linea
     * nueva o con otra cantidad lleva la regla completa. El formulario hace lo
     * mismo (fiscalo SimpleInvoiceFormView, cantidadHeredada).
     */
    public function problemaCantidadesSimples(array $items, array $previas = []): ?string
    {
        require_once __DIR__ . '/unidadMedidaModel.php';
        $items = array_values(array_map(static fn($it) => (array) $it, $items));
        $unidadesProducto = $this->unidadesDeProductos(array_column($items, 'product_id'));
        $heredadas = self::cantidadesHeredadas($previas);
        $unidades = new unidadMedidaModel();
        foreach ($items as $i => $raw) {
            $unidad = $raw['unidad_medida'] ?? null;
            if (($unidad === null || $unidad === '') && !empty($raw['product_id'])) {
                $unidad = $unidadesProducto[(int) $raw['product_id']] ?? null;
            }
            // Misma lectura que normalizeSimpleItems (sin cantidad = 1).
            $cantidad = (float) ($raw['quantity'] ?? $raw['cantidad'] ?? 1);
            if (self::esCantidadHeredada($raw['product_id'] ?? null, $cantidad, $heredadas)) {
                // Sin unidad no hay regla de fracciones; lo demas se juzga igual.
                $unidad = null;
            }
            $problema = $unidades->problemaCantidad($cantidad, $unidad, self::DECIMALES_CANTIDAD_SIMPLE);
            if ($problema !== null) {
                // "Línea 2: la cantidad…", igual que arma el front sus avisos por línea.
                return 'Línea ' . ($i + 1) . ': '
                    . mb_strtolower(mb_substr($problema, 0, 1)) . mb_substr($problema, 1);
            }
        }
        return null;
    }

    /**
     * [product_id => cantidades] de las lineas guardadas de una factura simple
     * (0 = linea libre). Por cada fila, dos: la guardada y la que resuelve
     * EcfDocumento::resolverLinea en MODO_SIMPLE, que es la que imprime el papel
     * y la que carga el formulario al editar (lineaQueCuadra en modo 'simple'):
     * 2 / 100 / 150 de antes de la 025 vuelve del formulario como 1.5.
     * Estatica y sin BD para poder probarla sola.
     *
     * @param array $previas Filas de factura_items (quantity, amount, subtotal, descuento_monto, product_id).
     * @return array<int,float[]>
     */
    public static function cantidadesHeredadas(array $previas): array
    {
        if ($previas === []) {
            return [];
        }
        require_once __DIR__ . '/../Utils/Pdf/EcfDocumento.php';
        $previas = array_values(array_map(static fn($it) => (array) $it, $previas));
        $resueltas = EcfDocumento::cantidadesYPrecios($previas, '', EcfDocumento::MODO_SIMPLE);
        $mapa = [];
        foreach ($previas as $i => $fila) {
            $producto = (int) ($fila['product_id'] ?? 0);
            $guardada = $fila['quantity'] ?? $fila['cantidad'] ?? null;
            if (is_numeric($guardada)) {
                $mapa[$producto][] = (float) $guardada;
            }
            $mapa[$producto][] = (float) $resueltas[$i]['cantidad'];
        }
        return $mapa;
    }

    /**
     * ¿La cantidad es la de una linea guardada del mismo producto? (ver
     * cantidadesHeredadas). Tolerancia de 1e-9: ambas llegan ya a 3 decimales.
     *
     * @param array<int,float[]> $heredadas
     */
    public static function esCantidadHeredada($productId, float $cantidad, array $heredadas): bool
    {
        foreach ($heredadas[(int) ($productId ?? 0)] ?? [] as $q) {
            if (abs($q - $cantidad) < 1e-9) {
                return true;
            }
        }
        return false;
    }

    /**
     * [product_id => unidad_medida] de los productos del catalogo que usan las
     * lineas. Vacio si no hay productos o si la consulta falla (fail-open: una
     * lectura fallida no puede impedir vender).
     */
    private function unidadesDeProductos(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($id) => $id > 0)));
        if (!$ids) {
            return [];
        }
        try {
            $marcas = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->conexion->prepare("SELECT id, unidad_medida FROM products WHERE id IN ({$marcas})");
            $stmt->execute($ids);
            $mapa = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $mapa[(int) $p['id']] = (string) $p['unidad_medida'];
            }
            return $mapa;
        } catch (PDOException $e) {
            error_log('[facturas] unidades de productos (fail-open): ' . $e->getMessage());
            return [];
        }
    }

    private function insertSimpleItems(int $facturaId, array $items): void
    {
        $sql = 'INSERT INTO factura_items
                (factura_id, product_id, description, amount, quantity, subtotal, descuento_monto,
                 indicador_facturacion, indicador_bien_servicio, unidad_medida, itbis_amount)
                VALUES
                (:factura_id, :product_id, :description, :amount, :quantity, :subtotal, :descuento_monto,
                 :indicador_facturacion, :indicador_bien_servicio, :unidad_medida, :itbis_amount)';
        $stmt = $this->conexion->prepare($sql);
        foreach ($items as $it) {
            $stmt->execute([
                ':factura_id' => $facturaId,
                ':product_id' => $it['product_id'] ?? null,
                ':description' => $it['description'],
                ':amount' => $it['amount'],
                ':quantity' => $it['quantity'],
                ':subtotal' => $it['subtotal'],
                ':descuento_monto' => (float) ($it['descuento_monto'] ?? 0),
                ':indicador_facturacion' => $it['indicador_facturacion'],
                ':indicador_bien_servicio' => $it['indicador_bien_servicio'],
                ':unidad_medida' => $it['unidad_medida'] ?? '43',
                ':itbis_amount' => $it['itbis_amount'],
            ]);
        }
    }

    /**
     * Total de una factura simple: la suma de los subtotales, sin impuestos.
     * No hay ITBIS que sumar (ver normalizeSimpleItems).
     */
    private function sumSimpleTotal(array $items): float
    {
        $total = 0.0;
        foreach ($items as $it) {
            $total += (float) $it['subtotal'];
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
            return ['error', 'Agrega al menos una línea a la factura.'];
        }
        $total = isset($data['total']) && $data['total'] !== ''
            ? (float) $data['total']
            : $this->sumSimpleTotal($items);

        // El front no envia no_factura: se genera aqui como "{secuencia}-{ddmmaa}".
        // Si llega uno explicito (p.ej. migracion/correccion) se respeta.
        $generaNumero = !(isset($data['no_factura']) && $data['no_factura'] !== '');

        // La numeracion se serializa entre conexiones. Antes el MAX+1 se leia
        // FUERA de la transaccion y sin ningun candado: dos cajas guardando a la
        // vez leian el mismo maximo, las dos insertaban el mismo numero y como
        // facturas.no_factura no tiene indice unico, MySQL no se quejaba. La
        // siguiente venta tomaba MAX+1 y el duplicado quedaba enterrado.
        $lock = $generaNumero ? $this->tomarLockSecuenciaSimple() : false;

        try {
            $this->conexion->beginTransaction();
            // Dentro del candado Y de la transaccion: entre leer el maximo y
            // grabar la fila no puede colarse otra conexion.
            $noFactura = $generaNumero
                ? $this->nextSimpleFacturaNumber()
                : (string) $data['no_factura'];
            $sql = 'INSERT INTO facturas
                    (no_factura, date, client_id, client_name, user_id, total, tipo_pago, NCF, tipo_ecf)
                    VALUES
                    (:no_factura, :date, :client_id, :client_name, :user_id, :total, :tipo_pago, :NCF, NULL)';
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([
                ':no_factura' => $noFactura,
                // '' cuenta como sin fecha: MySQL lo rechaza y la venta no se guardaria.
                ':date' => ($data['date'] ?? '') !== '' ? $data['date'] : date('Y-m-d H:i:s'),
                ':client_id' => $data['client_id'] ?? null,
                ':client_name' => $data['client_name'] ?? '',
                ':user_id' => $data['user_id'],
                ':total' => $total,
                // Contado por defecto: es la venta de mostrador tipica y el lado
                // seguro (el credito exige que el cliente lo tenga habilitado).
                ':tipo_pago' => (int) ($data['tipo_pago'] ?? 1),
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
            // El texto de PDO (SQL, tablas) solo al log; al usuario, uno claro.
            error_log('[facturas] no se pudo crear la factura simple: ' . $e->getMessage());
            return ['error', 'No se pudo guardar la factura. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.'];
        } finally {
            // Se suelta pase lo que pase: si una excepcion se lo llevara puesto,
            // la conexion quedaria con el candado tomado y la siguiente venta
            // esperaria los 5 segundos completos.
            if ($lock) {
                $this->soltarLockSecuenciaSimple();
            }
        }
    }

    /** Nombre del candado, por base de datos: un tenant no serializa a otro. */
    private const LOCK_SECUENCIA_SIMPLE = "CONCAT(DATABASE(), ':factura_simple_seq')";

    /**
     * Toma el candado con nombre que serializa la numeracion de facturas
     * simples. Es un lock de MySQL a nivel de CONEXION, no de tabla: no bloquea
     * filas, asi que la emision de e-CF sigue su curso mientras tanto.
     *
     * Espera hasta 5 segundos. Si no lo consigue NO aborta la venta: sigue sin
     * candado, que es exactamente como se comportaba antes. Un mostrador no se
     * queda sin poder facturar por un candado ocupado; el riesgo que se acepta
     * es el duplicado que ya existia.
     *
     * @return bool true si hay que soltarlo despues.
     */
    private function tomarLockSecuenciaSimple(): bool
    {
        try {
            $stmt = $this->conexion->query('SELECT GET_LOCK(' . self::LOCK_SECUENCIA_SIMPLE . ', 5)');
            // 1 = tomado; 0 = expiro la espera; NULL = error del servidor.
            if ((int) $stmt->fetchColumn() === 1) {
                return true;
            }
            error_log('[facturas] lock de numeracion ocupado: se numera sin serializar');
            return false;
        } catch (PDOException $e) {
            error_log('[facturas] no se pudo tomar el lock de numeracion: ' . $e->getMessage());
            return false;
        }
    }

    private function soltarLockSecuenciaSimple(): void
    {
        try {
            $this->conexion->query('SELECT RELEASE_LOCK(' . self::LOCK_SECUENCIA_SIMPLE . ')');
        } catch (PDOException $e) {
            error_log('[facturas] no se pudo soltar el lock de numeracion: ' . $e->getMessage());
        }
    }

    /**
     * Genera el proximo numero de factura simple con formato "{secuencia}-{ddmmaa}".
     * La secuencia es 1 + el mayor prefijo numerico (antes del "-") entre las
     * facturas NO electronicas existentes (tipo_ecf IS NULL), con un minimo de 4
     * digitos. La fecha es la de hoy sin guiones (dia+mes+anio de 2 digitos).
     * Ej: si la ultima es "0916-010626", hoy (02-06-2026) devuelve "0917-020626".
     */
    private function nextSimpleFacturaNumber(): string
    {
        $max = 0;
        try {
            $stmt = $this->conexion->query(
                "SELECT MAX(CAST(SUBSTRING_INDEX(no_factura, '-', 1) AS UNSIGNED)) AS max_num
                 FROM facturas
                 WHERE tipo_ecf IS NULL AND no_factura REGEXP '^[0-9]+-[0-9]+$'"
            );
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && $row['max_num'] !== null) {
                $max = (int) $row['max_num'];
            }
        } catch (PDOException $e) {
            $max = 0;
        }
        $secuencia = str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
        return $secuencia . '-' . date('dmy');
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
                $conditions[] = '(f.no_factura LIKE :query OR f.NCF LIKE :query OR f.client_name LIKE :query OR cl.company_name LIKE :query OR EXISTS (SELECT 1 FROM factura_items fi WHERE fi.factura_id = f.id AND fi.description LIKE :query))';
                $params[':query'] = "%{$query}%";
            }
            $whereClause = 'WHERE ' . implode(' AND ', $conditions);
            // 'description' = descripciones de las lineas concatenadas (resumen para
            // la lista). GROUP_CONCAT respeta group_concat_max_len (1024 por defecto);
            // el detalle completo va por GET /facturas-simples/{id}.
            $sql = "SELECT f.*, cl.company_name,
                           (SELECT GROUP_CONCAT(fi.description ORDER BY fi.id SEPARATOR '\n')
                              FROM factura_items fi WHERE fi.factura_id = f.id) AS description
                    FROM facturas f
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
                $conditions[] = '(f.no_factura LIKE :query OR f.NCF LIKE :query OR f.client_name LIKE :query OR cl.company_name LIKE :query OR EXISTS (SELECT 1 FROM factura_items fi WHERE fi.factura_id = f.id AND fi.description LIKE :query))';
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
     * Cuantas facturas simples hay y cuanto suman, para el dashboard: en total,
     * por mes (ultimos 12) y por dia (ultimos 31). getECFStats solo mira e-CF
     * (tipo_ecf IS NOT NULL), asi que sin esto las ventas de mostrador no
     * aparecian en "Ventas del dia/mes" ni en el grafico.
     *
     * Se agrupan por `date`, la fecha de la factura: una simple nunca tiene
     * fecha_emision_dgii. Sin filtro de ambiente, igual que ReporteVentasModel:
     * no se envian a la DGII, asi que no pertenecen a ninguno. Por dia y no solo
     * "hoy": el "hoy" lo decide el navegador, y el reloj del server puede ir
     * en otra zona horaria.
     */
    public function getFacturasSimplesStats(): array
    {
        try {
            $resumen = $this->conexion->query(
                "SELECT COUNT(*) AS total, COALESCE(SUM(total), 0) AS monto_total
                 FROM facturas WHERE tipo_ecf IS NULL"
            )->fetch(PDO::FETCH_ASSOC);

            $porMes = $this->conexion->query(
                "SELECT DATE_FORMAT(date, '%Y-%m') AS mes,
                        COUNT(*) AS total,
                        COALESCE(SUM(total), 0) AS monto_total
                 FROM facturas
                 WHERE tipo_ecf IS NULL AND date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                 GROUP BY mes ORDER BY mes DESC"
            )->fetchAll(PDO::FETCH_ASSOC);

            $porDia = $this->conexion->query(
                "SELECT DATE_FORMAT(date, '%Y-%m-%d') AS dia,
                        COUNT(*) AS total,
                        COALESCE(SUM(total), 0) AS monto_total
                 FROM facturas
                 WHERE tipo_ecf IS NULL AND date >= DATE_SUB(CURDATE(), INTERVAL 31 DAY)
                 GROUP BY dia ORDER BY dia DESC"
            )->fetchAll(PDO::FETCH_ASSOC);

            return ['resumen' => $resumen, 'por_mes' => $porMes, 'por_dia' => $porDia];
        } catch (PDOException $e) {
            return ['resumen' => null, 'por_mes' => [], 'por_dia' => []];
        }
    }

    /**
     * Actualiza una factura no electronica. Campos no enviados conservan su valor.
     * Si se envia `items`, reemplaza todas las lineas. Rechaza e-CF emitidos.
     * @return array ['success', payload] | ['error', mensaje] | ['error', mensaje, self::ERROR_NO_ENCONTRADA]
     */
    public function updateFacturaSimple(int $id, array $data): array
    {
        try {
            $cur = $this->conexion->prepare('SELECT * FROM facturas WHERE id = :id');
            $cur->execute([':id' => $id]);
            $row = $cur->fetch();
            if (!$row) {
                return ['error', self::MSG_SIMPLE_YA_NO_EXISTE, self::ERROR_NO_ENCONTRADA];
            }
            if ($row['tipo_ecf'] !== null) {
                return ['error', 'Esta factura electrónica ya se envió a la DGII y no se puede editar. Si hay que corregirla, emite una nota de crédito.'];
            }

            $noFactura = $data['no_factura'] ?? $row['no_factura'];
            $date = ($data['date'] ?? '') !== '' ? $data['date'] : $row['date'];
            $clientId = array_key_exists('client_id', $data) ? $data['client_id'] : $row['client_id'];
            $clientName = $data['client_name'] ?? $row['client_name'];
            $ncf = array_key_exists('NCF', $data) ? $data['NCF'] : $row['NCF'];
            $tipoPago = array_key_exists('tipo_pago', $data) ? (int) $data['tipo_pago'] : (int) $row['tipo_pago'];

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
                        total = :total, tipo_pago = :tipo_pago, NCF = :NCF
                 WHERE id = :id AND tipo_ecf IS NULL'
            );
            $upd->execute([
                ':no_factura' => $noFactura,
                ':date' => $date,
                ':client_id' => $clientId,
                ':client_name' => $clientName,
                ':total' => $total,
                ':tipo_pago' => $tipoPago,
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
            error_log('[facturas] no se pudo actualizar la factura simple ' . $id . ': ' . $e->getMessage());
            return ['error', 'No se pudieron guardar los cambios de la factura. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.'];
        }
    }

    /**
     * Elimina una factura no electronica y sus lineas. Rechaza e-CF emitidos.
     * @return array ['success', mensaje] | ['error', mensaje] | ['error', mensaje, self::ERROR_NO_ENCONTRADA]
     */
    public function deleteFacturaSimple(int $id): array
    {
        try {
            $cur = $this->conexion->prepare('SELECT tipo_ecf FROM facturas WHERE id = :id');
            $cur->execute([':id' => $id]);
            $row = $cur->fetch();
            if (!$row) {
                return ['error', 'Esta factura ya no existe. Puede que otra persona la haya eliminado; actualiza el listado.', self::ERROR_NO_ENCONTRADA];
            }
            if ($row['tipo_ecf'] !== null) {
                return ['error', 'Esta factura electrónica ya se envió a la DGII y no se puede eliminar. Para anularla, emite una nota de crédito.'];
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
            error_log('[facturas] no se pudo eliminar la factura simple ' . $id . ': ' . $e->getMessage());
            return ['error', 'No se pudo eliminar la factura. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.'];
        }
    }

    public function getFacturasPaginated($offset, $limit, $query = null, $estado = null, $tipoEcf = null)
    {
        try {
            $ambiente = $this->resolveActiveAmbiente();
            // Este listado es el de COMPROBANTES. Las facturas simples
            // (tipo_ecf NULL) tienen su propio modulo y su propio endpoint.
            //
            // El filtro es explicito a proposito: antes quedaban fuera solo de
            // rebote, porque el INSERT de simples no escribe ambiente_dgii y la
            // condicion de ambiente no las alcanzaba. Con el ambiente sin
            // resolver (sin DGII_ECF_ENVIRONMENT, o un tenant con ambiente
            // vacio) esa condicion se salta entera y las simples aparecian
            // mezcladas con los e-CF y contadas en la paginacion.
            $conditions = ['f.tipo_ecf IS NOT NULL'];
            $params = [];

            if ($query) {
                $conditions[] = "(f.no_factura LIKE :query OR cl.client_name LIKE :query OR f.NCF LIKE :query OR cl.rnc LIKE :query OR cl.company_name LIKE :query OR cl.phone_number LIKE :query OR cl.email LIKE :query OR EXISTS (SELECT 1 FROM factura_items fi WHERE fi.factura_id = f.id AND fi.description LIKE :query))";
                $params[':query'] = "%{$query}%";
            }
            if ($ambiente !== null) {
                $conditions[] = "f.ambiente_dgii = :ambiente";
                $params[':ambiente'] = $ambiente;
            }
            $this->applyEstadoTipoFilters($conditions, $params, $estado, $tipoEcf);

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
            unset($factura);
            return $this->adjuntarNotasVinculadas($facturas);
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Pega a cada fila de facturas sus notas ("notas") y, si es una nota, el
     * comprobante que modifica ("modifica"). Ver vincularNotas() para la forma.
     *
     * Dos consultas por pagina (notas y originales), con IN (...): nunca una
     * por fila. Cada fila necesita id, e_ncf, tipo_ecf, ncf_modificado y
     * ambiente_dgii. Si la base falla, las filas salen igual, sin notas y con
     * "modifica" de solo el e-NCF: el listado no se cae por esto.
     */
    public function adjuntarNotasVinculadas(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $eNcfs = [];
        $modificados = [];
        foreach ($rows as $row) {
            $eNcf = trim((string) ($row['e_ncf'] ?? ''));
            if ($eNcf !== '') {
                $eNcfs[$eNcf] = true;
            }
            $ref = trim((string) ($row['ncf_modificado'] ?? ''));
            if ($ref !== '' && in_array((string) ($row['tipo_ecf'] ?? ''), self::TIPOS_NOTA, true)) {
                $modificados[$ref] = true;
            }
        }

        $notas = [];
        $originales = [];
        try {
            if ($eNcfs !== []) {
                $tipos = "'" . implode("', '", self::TIPOS_NOTA) . "'";
                $stmt = $this->conexion->prepare(
                    'SELECT id, e_ncf, tipo_ecf, codigo_modificacion, total, estado_dgii, date,
                            ncf_modificado, ambiente_dgii
                     FROM facturas
                     WHERE tipo_ecf IN (' . $tipos . ')
                       AND ncf_modificado IN (' . self::placeholders(count($eNcfs)) . ')
                       AND estado_dgii IN (' . self::sqlEstadosNotaVigente() . ')
                     ORDER BY id ASC'
                );
                $stmt->execute(array_keys($eNcfs));
                $notas = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            if ($modificados !== []) {
                $stmt = $this->conexion->prepare(
                    'SELECT id, e_ncf, tipo_ecf, total, date, ambiente_dgii
                     FROM facturas
                     WHERE e_ncf IN (' . self::placeholders(count($modificados)) . ')
                     ORDER BY id ASC'
                );
                $stmt->execute(array_keys($modificados));
                $originales = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (PDOException $e) {
            error_log('[facturas] adjuntarNotasVinculadas: ' . $e->getMessage());
            $notas = [];
            $originales = [];
        }

        return self::vincularNotas($rows, $notas, $originales);
    }

    /**
     * Emparejamiento puro de adjuntarNotasVinculadas (se prueba sin DB en
     * tools/test_notas_vinculadas.php).
     *
     * $rows:       filas de facturas (id, e_ncf, tipo_ecf, ncf_modificado, ambiente_dgii).
     * $notas:      candidatas a nota (id, e_ncf, tipo_ecf, codigo_modificacion,
     *              total, estado_dgii, date, ncf_modificado, ambiente_dgii).
     * $originales: comprobantes que las notas de $rows modifican (id, e_ncf,
     *              tipo_ecf, total, date, ambiente_dgii).
     *
     * Devuelve $rows en el mismo orden, cada una con:
     *   notas    lista (quiza vacia), por id ascendente, de las E33/E34 vigentes
     *            (ESTADOS_NOTA_VIGENTE) cuyo ncf_modificado es el e_ncf de la fila
     *            en el mismo ambiente_dgii (NULL con NULL, como <=>):
     *            {id, e_ncf, tipo_ecf, codigo_modificacion, total, estado_dgii, date}.
     *            Fila sin e_ncf (p.ej. un intento archivado): [].
     *   modifica null, salvo en una E33/E34 con ncf_modificado:
     *            {id, e_ncf (= ncf_modificado), tipo_ecf, total, date}, con
     *            id/tipo_ecf/total/date null si el comprobante no esta en
     *            facturas en ese ambiente (p.ej. un NCF de papel).
     * El e-NCF se compara sin mayusculas/minusculas ni espacios en los bordes,
     * como la collation de la columna. total queda como texto (DECIMAL).
     */
    public static function vincularNotas(array $rows, array $notas, array $originales): array
    {
        $clave = static function ($eNcf, $ambiente): ?string {
            $eNcf = strtoupper(trim((string) ($eNcf ?? '')));
            if ($eNcf === '') {
                return null;
            }
            $ambiente = $ambiente === null ? "\0" : strtolower(trim((string) $ambiente));
            return $eNcf . '|' . $ambiente;
        };
        $texto = static fn($v): ?string => $v === null ? null : (string) $v;

        usort($notas, static fn($a, $b) => (int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0));
        $notasPorOriginal = [];
        foreach ($notas as $n) {
            if (!in_array((string) ($n['tipo_ecf'] ?? ''), self::TIPOS_NOTA, true)
                || !in_array((string) ($n['estado_dgii'] ?? ''), self::ESTADOS_NOTA_VIGENTE, true)) {
                continue;
            }
            $k = $clave($n['ncf_modificado'] ?? null, $n['ambiente_dgii'] ?? null);
            if ($k === null) {
                continue;
            }
            $notasPorOriginal[$k][] = [
                'id' => (int) $n['id'],
                'e_ncf' => (string) $n['e_ncf'],
                'tipo_ecf' => (string) $n['tipo_ecf'],
                'codigo_modificacion' => $texto($n['codigo_modificacion'] ?? null),
                'total' => (string) $n['total'],
                'estado_dgii' => (string) $n['estado_dgii'],
                'date' => $texto($n['date'] ?? null),
            ];
        }

        $originalPorClave = [];
        foreach ($originales as $o) {
            $k = $clave($o['e_ncf'] ?? null, $o['ambiente_dgii'] ?? null);
            // El primero por id si hubiera repetidos (uk_e_ncf_amb no los
            // impide con ambiente NULL).
            if ($k !== null && (!isset($originalPorClave[$k]) || (int) $o['id'] < (int) $originalPorClave[$k]['id'])) {
                $originalPorClave[$k] = $o;
            }
        }

        foreach ($rows as &$row) {
            $ambiente = $row['ambiente_dgii'] ?? null;
            $k = $clave($row['e_ncf'] ?? null, $ambiente);
            $row['notas'] = $k !== null ? ($notasPorOriginal[$k] ?? []) : [];

            $row['modifica'] = null;
            $ref = trim((string) ($row['ncf_modificado'] ?? ''));
            if ($ref !== '' && in_array((string) ($row['tipo_ecf'] ?? ''), self::TIPOS_NOTA, true)) {
                $o = $originalPorClave[$clave($ref, $ambiente)] ?? null;
                $row['modifica'] = [
                    'id' => $o !== null ? (int) $o['id'] : null,
                    'e_ncf' => $ref,
                    'tipo_ecf' => $o !== null ? $texto($o['tipo_ecf'] ?? null) : null,
                    'total' => $o !== null ? $texto($o['total'] ?? null) : null,
                    'date' => $o !== null ? $texto($o['date'] ?? null) : null,
                ];
            }
        }
        unset($row);
        return $rows;
    }

    /** "?, ?, ?" para un IN (...) de $n valores. */
    private static function placeholders(int $n): string
    {
        return implode(', ', array_fill(0, $n, '?'));
    }

    /** ESTADOS_NOTA_VIGENTE como lista SQL: 'ACEPTADO', 'ACEPTADO_CONDICIONAL', ... */
    private static function sqlEstadosNotaVigente(): string
    {
        return "'" . implode("', '", self::ESTADOS_NOTA_VIGENTE) . "'";
    }

    public function getFacturasCount($query = null, $estado = null, $tipoEcf = null)
    {
        try {
            $ambiente = $this->resolveActiveAmbiente();
            // Este listado es el de COMPROBANTES. Las facturas simples
            // (tipo_ecf NULL) tienen su propio modulo y su propio endpoint.
            //
            // El filtro es explicito a proposito: antes quedaban fuera solo de
            // rebote, porque el INSERT de simples no escribe ambiente_dgii y la
            // condicion de ambiente no las alcanzaba. Con el ambiente sin
            // resolver (sin DGII_ECF_ENVIRONMENT, o un tenant con ambiente
            // vacio) esa condicion se salta entera y las simples aparecian
            // mezcladas con los e-CF y contadas en la paginacion.
            $conditions = ['f.tipo_ecf IS NOT NULL'];
            $params = [];

            if ($query) {
                $conditions[] = "(f.no_factura LIKE :query OR cl.client_name LIKE :query OR f.NCF LIKE :query OR cl.rnc LIKE :query OR cl.company_name LIKE :query OR cl.phone_number LIKE :query OR cl.email LIKE :query OR EXISTS (SELECT 1 FROM factura_items fi WHERE fi.factura_id = f.id AND fi.description LIKE :query))";
                $params[':query'] = "%{$query}%";
            }
            if ($ambiente !== null) {
                $conditions[] = "f.ambiente_dgii = :ambiente";
                $params[':ambiente'] = $ambiente;
            }
            $this->applyEstadoTipoFilters($conditions, $params, $estado, $tipoEcf);

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
     * Filtros compartidos del listado: estado ('aprobado'|'rechazado', ya
     * normalizado por el controller; 'aprobado' incluye ACEPTADO_CONDICIONAL y
     * los estados RFCE_*) y tipo_ecf ('31'..'47', sin la 'E').
     */
    private function applyEstadoTipoFilters(array &$conditions, array &$params, $estado, $tipoEcf): void
    {
        if ($estado === 'aprobado') {
            $conditions[] = "f.estado_dgii LIKE '%ACEPTADO%'";
        } elseif ($estado === 'rechazado') {
            $conditions[] = "f.estado_dgii LIKE '%RECHAZADO%'";
        }
        if ($tipoEcf !== null && $tipoEcf !== '') {
            $conditions[] = "f.tipo_ecf = :tipo_ecf";
            $params[':tipo_ecf'] = $tipoEcf;
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
     * @return array ['success', payload] | ['error', mensaje para el usuario, detalle tecnico para el audit]
     *
     * Ojo con los errores: se llama DESPUES de que la DGII recibio el e-CF. Por
     * eso el mensaje pide NO volver a emitirla (saldria otro e-NCF para la misma
     * venta) y trae el e-NCF para que soporte la ubique.
     *
     * Venta del POS (docs/specs/pos.md §9.5): `$factura['pos']` = {turno_id,
     * pos_empleado_id, pos_idempotency_key, envio_pendiente}. Esas columnas
     * (migracion 030) solo se escriben cuando vienen: un tenant que aun no tiene
     * la migracion sigue facturando desde app.* igual que siempre.
     * `$antesDeConfirmar($facturaId)` corre dentro de la MISMA transaccion (el
     * movimiento de caja del POS): si falla, no queda ni la factura a medias.
     */
    public function saveFacturaConECF(array $factura, array $ecf, ?callable $antesDeConfirmar = null): array
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

            // Reintento de un e-CF RECHAZADO: cuando DGII rechaza sin consumir la
            // secuencia (secuenciaUtilizada=false, p.ej. codigo 135) el contador se
            // revierte y el mismo e-NCF se reutiliza. El registro rechazado anterior
            // con ese e_ncf choca con la clave unica uk_e_ncf_amb, asi que se
            // ARCHIVA antes de re-insertar. NUNCA se archiva un e-CF que no este en
            // estado de rechazo: en ese caso se aborta con un error claro.
            // Solo cuenta el MISMO ambiente (la clave es por ambiente, migracion
            // 027): el mismo numero en certificacion no es este comprobante. Una
            // fila sin ambiente cuenta igual que ECFEmissionService::eNcfYaUsado.
            if (!empty($ecf['e_ncf'])) {
                $prev = $this->conexion->prepare(
                    'SELECT id, estado_dgii FROM facturas
                     WHERE e_ncf = :encf AND (ambiente_dgii = :amb OR ambiente_dgii IS NULL)'
                );
                $prev->execute([':encf' => $ecf['e_ncf'], ':amb' => $ecf['ambiente'] ?? null]);
                $estadosRechazo = ['RECHAZADO', 'RFCE_RECHAZADO', 'NO_ENCONTRADO'];
                foreach ($prev->fetchAll(PDO::FETCH_ASSOC) as $prevRow) {
                    if (!in_array((string) ($prevRow['estado_dgii'] ?? ''), $estadosRechazo, true)) {
                        $this->conexion->rollBack();
                        $detalle = 'Ya existe una factura con e-NCF ' . $ecf['e_ncf']
                            . ' en estado ' . ($prevRow['estado_dgii'] ?? 'desconocido')
                            . '; no se puede re-emitir (solo se permite reintentar e-CF rechazados).';
                        error_log('[ECF] saveFacturaConECF: ' . $detalle);
                        return ['error', 'La DGII recibió la factura ' . $ecf['e_ncf']
                            . ', pero ese número ya estaba usado en el sistema y no se pudo guardar. '
                            . 'No la emitas de nuevo: avisa a soporte con el número ' . $ecf['e_ncf'] . '.', $detalle];
                    }
                    $delId = (int) $prevRow['id'];
                    // Archive the rejected attempt to maintain history for the client,
                    // setting e_ncf to NULL to bypass the uk_e_ncf_amb unique constraint.
                    $this->conexion->prepare(
                        "UPDATE facturas SET e_ncf = NULL, estado_dgii = CONCAT(estado_dgii, '_ARCHIVADO') WHERE id = :id"
                    )->execute([':id' => $delId]);
                }
            }

            $pos = is_array($factura['pos'] ?? null) ? $factura['pos'] : null;
            $sql = 'INSERT INTO facturas
                (no_factura, date, client_id, client_name, total, tipo_pago, NCF, user_id,
                 tipo_ecf, e_ncf, track_id, estado_dgii, codigo_seguridad,
                 fecha_emision_dgii, ambiente_dgii, xml_firmado, respuesta_dgii,
                 rfce_xml, rfce_track_id, rfce_estado, rfce_respuesta,
                 ncf_modificado, fecha_ncf_modificado, codigo_modificacion, razon_modificacion'
                . ($pos ? ', turno_id, pos_empleado_id, pos_idempotency_key, envio_pendiente' : '') . ')
                VALUES
                (:no_factura, :date, :client_id, :client_name, :total, :tipo_pago, NULL, :user_id,
                 :tipo_ecf, :e_ncf, :track_id, :estado_dgii, :codigo_seguridad,
                 :fecha_emision_dgii, :ambiente_dgii, :xml_firmado, :respuesta_dgii,
                 :rfce_xml, :rfce_track_id, :rfce_estado, :rfce_respuesta,
                 :ncf_modificado, :fecha_ncf_modificado, :codigo_modificacion, :razon_modificacion'
                . ($pos ? ', :turno_id, :pos_empleado_id, :pos_idempotency_key, :envio_pendiente' : '') . ')';
            $parametrosPos = $pos ? [
                ':turno_id' => $pos['turno_id'],
                ':pos_empleado_id' => $pos['pos_empleado_id'],
                ':pos_idempotency_key' => $pos['pos_idempotency_key'],
                ':envio_pendiente' => !empty($pos['envio_pendiente']) ? 1 : 0,
            ] : [];
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute($parametrosPos + [
                ':no_factura' => $factura['no_factura'] ?? $ecf['e_ncf'],
                ':date' => $factura['date'],
                ':client_id' => $factura['client_id'],
                ':client_name' => $factura['client_name'],
                ':total' => $factura['total'],
                // Ya viajaba a DGII dentro del XML; ahora tambien queda guardado
                // en la factura, para que la app sepa si fue contado o credito.
                ':tipo_pago' => (int) ($factura['tipo_pago'] ?? 1),
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
                (factura_id, product_id, description, amount, quantity, subtotal, descuento_monto,
                 indicador_facturacion, indicador_bien_servicio, unidad_medida, itbis_amount)
                VALUES
                (:factura_id, :product_id, :description, :amount, :quantity, :subtotal, :descuento_monto,
                 :indicador_facturacion, :indicador_bien_servicio, :unidad_medida, :itbis_amount)';
            $itemStmt = $this->conexion->prepare($itemSql);
            foreach ($factura['items'] as $item) {
                // Precio a 4 y cantidad a 3, lo que guardan amount DECIMAL(18,4) y
                // quantity DECIMAL(12,3). La emision ya los manda normalizados
                // (EcfItemMapper::normalizarCantidadPrecio: 4 y 2, lo del XML);
                // esto solo deja explicito lo que queda en la base.
                $amount = round((float) ($item['amount'] ?? 0), 4);
                $quantity = round((float) ($item['quantity'] ?? 1), 3);
                $subtotal = isset($item['subtotal']) ? (float) $item['subtotal'] : round($amount * $quantity, 2);
                $itemStmt->execute([
                    ':factura_id' => $facturaId,
                    ':product_id' => $item['product_id'] ?? null,
                    ':description' => $item['description'] ?? '',
                    ':amount' => $amount,
                    ':quantity' => $quantity,
                    ':subtotal' => $subtotal,
                    ':descuento_monto' => (float) ($item['descuento_monto'] ?? 0),
                    ':indicador_facturacion' => (int) ($item['indicador_facturacion'] ?? 1),
                    ':indicador_bien_servicio' => (int) ($item['indicador_bien_servicio'] ?? 2),
                    ':unidad_medida' => (string) ($item['unidad_medida'] ?? '43'),
                    ':itbis_amount' => (float) ($item['itbis_amount'] ?? 0),
                ]);
            }

            if ($antesDeConfirmar !== null) {
                $antesDeConfirmar($facturaId);
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
        } catch (Throwable $e) {
            // Throwable y no solo PDOException: un fallo del gancho
            // ($antesDeConfirmar) tambien tiene que deshacer la factura.
            if ($this->conexion->inTransaction()) {
                $this->conexion->rollBack();
            }
            $detalle = 'Failed to save factura with e-CF: ' . $e->getMessage();
            error_log('[ECF] saveFacturaConECF e_ncf=' . ($ecf['e_ncf'] ?? '') . ': ' . $detalle);
            $eNcf = (string) ($ecf['e_ncf'] ?? '');
            return ['error', $eNcf !== ''
                ? 'La factura ' . $eNcf . ' se envió a la DGII, pero no se pudo guardar en el sistema. '
                    . 'No la emitas de nuevo: avisa a soporte con el número ' . $eNcf . '.'
                : 'La factura se envió a la DGII, pero no se pudo guardar en el sistema. No la emitas de nuevo: avisa a soporte.',
                $detalle];
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

    /** Ambiente activo: per-tenant (tenants.ambiente) o global del .env. */
    private function resolveActiveAmbiente(): ?string
    {
        return AmbienteResolver::active();
    }

    public function getECFStats(): array
    {
        try {
            $ambiente = $this->resolveActiveAmbiente();
            $ambFilter = $ambiente !== null ? "AND ambiente_dgii = '{$ambiente}'" : '';

            // monto_neto: lo que de verdad se emitio. La nota de credito (E34)
            // resta y los rechazados no cuentan (cubre RECHAZADO,
            // RECHAZADO_ARCHIVADO y RFCE_RECHAZADO, como $notRechazado mas
            // abajo). monto_total sigue siendo la suma cruda.
            $resumen = $this->conexion->query(
                "SELECT COUNT(*) as total_ecf,
                        COALESCE(SUM(total), 0) as monto_total,
                        COALESCE(SUM(CASE WHEN estado_dgii IS NULL OR estado_dgii NOT LIKE '%RECHAZADO%'
                                          THEN CASE WHEN tipo_ecf = '34' THEN -total ELSE total END
                                          ELSE 0 END), 0) as monto_neto,
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
                        SUM(CASE WHEN estado_dgii LIKE '%RECHAZADO%' THEN 1 ELSE 0 END) as rechazados,
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

            // e-CF emitidos por mes, de todos los tipos (compras E41/E43/E47
            // incluidas). Sin los rechazados: cubre RECHAZADO, RECHAZADO_ARCHIVADO
            // y RFCE_RECHAZADO; conserva NULL/PENDIENTE. NO son ventas: para eso
            // estan ventas_por_mes / ventas_por_dia.
            $notRechazado = "AND (estado_dgii IS NULL OR estado_dgii NOT LIKE '%RECHAZADO%')";
            $porMes = $this->conexion->query(
                "SELECT DATE_FORMAT(fecha_emision_dgii, '%Y-%m') as mes,
                        COUNT(*) as total,
                        COALESCE(SUM(total), 0) as monto_total
                 FROM facturas
                 WHERE tipo_ecf IS NOT NULL {$ambFilter} {$notRechazado}
                   AND fecha_emision_dgii >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                 GROUP BY mes ORDER BY mes DESC"
            )->fetchAll(PDO::FETCH_ASSOC);

            // Ventas en e-CF para el dashboard, con las reglas de ReporteVentasModel
            // (sus constantes, no una copia): suman los tipos de venta y las notas,
            // la nota de credito resta y E41/E43/E47 no entran. Agrupa por `date`
            // como el reporte, para que "Ventas del mes" cuadre con el. Las simples
            // no van aqui: las suma /api/facturas-simples/stats. Por dia y no solo
            // "hoy" por lo mismo que alli: el "hoy" lo decide el navegador.
            $tiposVenta = implode(',', array_map(
                [$this->conexion, 'quote'],
                array_merge(ReporteVentasModel::TIPOS_VENTA, ReporteVentasModel::TIPOS_NOTA)
            ));
            $tipoResta = $this->conexion->quote(ReporteVentasModel::TIPO_RESTA);
            $esVenta = "tipo_ecf IN ({$tiposVenta}) {$ambFilter} {$notRechazado}";
            $montoVenta = "COALESCE(SUM(CASE WHEN tipo_ecf = {$tipoResta} THEN -total ELSE total END), 0)";

            $ventasPorMes = $this->conexion->query(
                "SELECT DATE_FORMAT(date, '%Y-%m') as mes,
                        COUNT(*) as total,
                        {$montoVenta} as monto_total
                 FROM facturas
                 WHERE {$esVenta} AND date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                 GROUP BY mes ORDER BY mes DESC"
            )->fetchAll(PDO::FETCH_ASSOC);

            $ventasPorDia = $this->conexion->query(
                "SELECT DATE_FORMAT(date, '%Y-%m-%d') as dia,
                        COUNT(*) as total,
                        {$montoVenta} as monto_total
                 FROM facturas
                 WHERE {$esVenta} AND date >= DATE_SUB(CURDATE(), INTERVAL 31 DAY)
                 GROUP BY dia ORDER BY dia DESC"
            )->fetchAll(PDO::FETCH_ASSOC);

            $ambSeqFilter = $ambiente !== null ? "AND ns.ambiente = '{$ambiente}'" : "AND ns.ambiente = 'certecf'";
            $secuenciaActual = ncfModel::sqlSecuenciaActual($this->conexion, $ambiente ?? 'certecf');
            // Varias filas por tipo = rangos autorizados DGII: se agregan por tipo.
            // restantes = capacidad disponible en rangos vigentes (NULL = sin limite
            // registrado); vencimiento = el del rango vigente mas proximo a dispensar.
            $secuencias = $this->conexion->query(
                "SELECT ns.type,
                        {$secuenciaActual} as secuencia_actual,
                        COALESCE(MAX(f.total_emitidos), 0) as total_emitidos,
                        SUM(CASE WHEN ns.numero_hasta IS NOT NULL
                                  AND (ns.fecha_vencimiento IS NULL OR ns.fecha_vencimiento >= CURDATE())
                                 THEN GREATEST(ns.numero_hasta - ns.current_value, 0) END) as restantes,
                        MIN(CASE WHEN (ns.numero_hasta IS NULL OR ns.current_value < ns.numero_hasta)
                                  AND (ns.fecha_vencimiento IS NULL OR ns.fecha_vencimiento >= CURDATE())
                                 THEN ns.fecha_vencimiento END) as vencimiento
                 FROM ncf_sequences ns
                 LEFT JOIN (
                     SELECT CONCAT('E', tipo_ecf) as type, COUNT(*) as total_emitidos
                     FROM facturas WHERE tipo_ecf IS NOT NULL {$ambFilter} GROUP BY tipo_ecf
                 ) f ON ns.type = f.type
                 WHERE ns.type LIKE 'E%' {$ambSeqFilter}
                 GROUP BY ns.type
                 ORDER BY ns.type"
            )->fetchAll(PDO::FETCH_ASSOC);

            // Si el ambiente activo aun no tiene secuencias NCF (p.ej. testecf sin
            // migrar), devolvemos todos los tipos e-CF en 0 en vez de una lista
            // vacia, para que el front muestre "0" y no interprete "sin datos".
            // total_emitidos se toma de $porTipo (ya consultado para este ambiente).
            if (empty($secuencias)) {
                $emitidosPorTipo = [];
                foreach ($porTipo as $t) {
                    $emitidosPorTipo['E' . $t['tipo_ecf']] = (int) $t['total'];
                }
                $secuencias = array_map(
                    fn($tipo) => [
                        'type' => 'E' . $tipo,
                        'secuencia_actual' => 0,
                        'total_emitidos' => $emitidosPorTipo['E' . $tipo] ?? 0,
                        'restantes' => null,
                        'vencimiento' => null,
                    ],
                    ['31', '32', '33', '34', '41', '43', '44', '45', '46', '47']
                );
            }

            return [
                'resumen'   => $resumen,
                'por_tipo'  => $porTipo,
                'por_estado' => $porEstado,
                'por_mes'   => $porMes,
                'ventas_por_mes' => $ventasPorMes,
                'ventas_por_dia' => $ventasPorDia,
                'secuencias' => $secuencias,
            ];
        } catch (PDOException $e) {
            return [
                'resumen'   => null,
                'por_tipo'  => [],
                'por_estado' => [],
                'por_mes'   => [],
                'ventas_por_mes' => [],
                'ventas_por_dia' => [],
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

    /** Estados en que la DGII acepto el e-CF: los unicos que una nota puede modificar. */
    public const ESTADOS_MODIFICABLES = ['ACEPTADO', 'ACEPTADO_CONDICIONAL', 'RFCE_ACEPTADO'];

    /**
     * Facturas de un cliente que una nota (E33/E34) puede modificar: de venta
     * (ReporteVentasModel::TIPOS_VENTA), aceptadas por la DGII y del ambiente
     * activo, de la mas reciente a la mas vieja. Ver mapReferencia().
     *
     * @param string|null $query parte del e-NCF
     * @return array|null null si fallo la consulta (no es lo mismo que "no hay")
     */
    public function getFacturasModificables(int $clientId, ?string $query = null, int $limit = 20): ?array
    {
        try {
            $where = ['f.client_id = :client_id', 'f.e_ncf IS NOT NULL'];
            $params = [];
            foreach (ReporteVentasModel::TIPOS_VENTA as $i => $t) {
                $params[":t{$i}"] = $t;
            }
            $where[] = 'f.tipo_ecf IN (' . implode(',', array_keys($params)) . ')';
            $marcasEstado = [];
            foreach (self::ESTADOS_MODIFICABLES as $i => $e) {
                $marcasEstado[] = ":e{$i}";
                $params[":e{$i}"] = $e;
            }
            $where[] = 'f.estado_dgii IN (' . implode(',', $marcasEstado) . ')';

            $ambiente = $this->resolveActiveAmbiente();
            if ($ambiente !== null) {
                $where[] = 'f.ambiente_dgii = :ambiente';
                $params[':ambiente'] = $ambiente;
            }
            // Un e-NCF es alfanumerico: se descarta lo demas (tambien % y _).
            $query = preg_replace('/[^A-Za-z0-9]/', '', (string) $query);
            if ($query !== '') {
                $where[] = 'f.e_ncf LIKE :query';
                $params[':query'] = '%' . $query . '%';
            }

            $sql = $this->sqlReferencia() . ' WHERE ' . implode(' AND ', $where)
                . ' ORDER BY f.id DESC LIMIT :limit';
            $stmt = $this->conexion->prepare($sql);
            $stmt->bindValue(':client_id', $clientId, PDO::PARAM_INT);
            foreach ($params as $key => $val) {
                $stmt->bindValue($key, $val, PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', max(1, min($limit, 50)), PDO::PARAM_INT);
            $stmt->execute();
            return array_map([$this, 'mapReferencia'], $stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (PDOException $e) {
            error_log('[facturas] getFacturasModificables client_id=' . $clientId . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * La factura que modifica una nota, buscada por su e-NCF en el ambiente
     * activo (ver mapReferencia()). null si no esta en la base, p.ej. porque se
     * emitio en otro sistema: esa la valida la DGII.
     */
    public function getReferenciaOriginal(string $eNcf): ?array
    {
        try {
            $sql = $this->sqlReferencia() . ' WHERE f.e_ncf = :e_ncf';
            $params = [':e_ncf' => $eNcf];
            $ambiente = $this->resolveActiveAmbiente();
            if ($ambiente !== null) {
                $sql .= ' AND f.ambiente_dgii = :ambiente';
                $params[':ambiente'] = $ambiente;
            }
            $stmt = $this->conexion->prepare($sql . ' LIMIT 1');
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? $this->mapReferencia($row) : null;
        } catch (PDOException $e) {
            error_log('[facturas] getReferenciaOriginal ' . $eNcf . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Factura + lo que ya le ajustaron las notas que la referencian. Solo
     * cuentan las notas aceptadas o en proceso en la DGII: una rechazada, en
     * ERROR o NO_ENCONTRADO no ajusto nada y el usuario debe poder reintentarla.
     */
    private function sqlReferencia(): string
    {
        // FechaEmision se lee del XML firmado: es la que tiene la DGII y la que
        // pide FechaNCFModificado. SUBSTRING/LOCATE evita traer el XML entero.
        $estados = self::sqlEstadosNotaVigente();
        return "SELECT f.id, f.client_id, f.tipo_ecf, f.e_ncf, f.date, f.fecha_emision_dgii,
                       f.total, f.estado_dgii,
                       SUBSTRING(f.xml_firmado, LOCATE('<FechaEmision>', f.xml_firmado) + 14, 10) AS fecha_xml,
                       COALESCE(n.notas_credito, 0) AS notas_credito,
                       COALESCE(n.notas_debito, 0) AS notas_debito
                FROM facturas f
                LEFT JOIN (
                    SELECT ncf_modificado, ambiente_dgii,
                           SUM(CASE WHEN tipo_ecf = '34' THEN total ELSE 0 END) AS notas_credito,
                           SUM(CASE WHEN tipo_ecf = '33' THEN total ELSE 0 END) AS notas_debito
                    FROM facturas
                    WHERE tipo_ecf IN ('33', '34') AND ncf_modificado IS NOT NULL
                      -- Solo notas que la DGII tiene: aceptadas o en proceso. Una en ERROR o
                      -- NO_ENCONTRADO (o su intento archivado) no llego a valer, y contarla
                      -- bloquearia el reintento que el propio mensaje le pide al usuario.
                      AND estado_dgii IN ({$estados})
                    GROUP BY ncf_modificado, ambiente_dgii
                ) n ON n.ncf_modificado = f.e_ncf AND n.ambiente_dgii <=> f.ambiente_dgii";
    }

    /**
     * Fila de sqlReferencia() para la API: fecha_emision en dd-mm-aaaa (la de
     * FechaNCFModificado) y saldo = total + notas de debito - notas de credito.
     */
    private function mapReferencia(array $r): array
    {
        $fecha = (string) ($r['fecha_xml'] ?? '');
        if (!preg_match('/^\d{2}-\d{2}-\d{4}$/', $fecha)) {
            // Sin XML legible: la fecha de firma es del mismo dia que FechaEmision.
            $ts = strtotime((string) ($r['fecha_emision_dgii'] ?: $r['date']));
            $fecha = $ts ? date('d-m-Y', $ts) : '';
        }
        $total = round((float) $r['total'], 2);
        $credito = round((float) $r['notas_credito'], 2);
        $debito = round((float) $r['notas_debito'], 2);
        return [
            'id' => (int) $r['id'],
            'client_id' => $r['client_id'] !== null ? (int) $r['client_id'] : null,
            'tipo_ecf' => (string) $r['tipo_ecf'],
            'e_ncf' => (string) $r['e_ncf'],
            'fecha_emision' => $fecha,
            'estado_dgii' => (string) $r['estado_dgii'],
            'total' => $total,
            'notas_credito' => $credito,
            'notas_debito' => $debito,
            'saldo' => round($total + $debito - $credito, 2),
        ];
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
