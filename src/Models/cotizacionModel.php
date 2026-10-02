<?php
require_once(__DIR__ . '/../Database.php');
require_once(__DIR__ . '/../Utils/TenantMail.php');

class cotizacionModel
{
    private $conexion;

    public function __construct()
    {
        $this->conexion = Database::getInstance()->getConnection();
    }

    /**
     * Parametros de una linea para el INSERT, venga como objeto (json_decode sin
     * assoc) o como arreglo. `$item->x ?? $item['x']` no servia: si el campo
     * faltaba en un objeto, PHP 8 cortaba con "Cannot use object of type
     * stdClass as array" (500) en vez de caer al respaldo.
     *
     * Precio hasta 4 decimales (amount DECIMAL(18,4)) y cantidad hasta 2
     * (quantity DECIMAL(12,3); la cotizacion se convierte en e-CF, que admite 2).
     * cotValidarItems ya rechazo lo que tuviera mas: el round solo limpia el
     * ruido binario. El subtotal, si no viene, es precio x cantidad.
     */
    private static function filaItem($item): array
    {
        $campo = static fn(string $k) => is_array($item) ? ($item[$k] ?? null) : ($item->$k ?? null);
        $amount = round((float) $campo('amount'), 4);
        $quantity = round((float) $campo('quantity'), 2);
        $subtotal = $campo('subtotal');
        return [
            ':description' => (string) ($campo('description') ?? ''),
            ':amount' => $amount,
            ':quantity' => $quantity,
            ':subtotal' => $subtotal !== null && $subtotal !== '' ? round((float) $subtotal, 2) : round($amount * $quantity, 2),
        ];
    }

    public function getCotizaciones($id = null)
    {
        try {
            if ($id == null) {
                $sql = "SELECT c.*, cl.client_name,cl.company_name,cl.rnc FROM cotizaciones c LEFT JOIN clients cl ON c.client_id = cl.id";
                $stmt = $this->conexion->prepare($sql);
                $stmt->execute();
                $cotizaciones = $stmt->fetchAll();
            } else {
                $sql = "SELECT c.*, cl.client_name,cl.company_name,cl.rnc FROM cotizaciones c LEFT JOIN clients cl ON c.client_id = cl.id WHERE c.id = :id";
                $stmt = $this->conexion->prepare($sql);
                $stmt->execute([':id' => $id]);
                $cotizaciones = $stmt->fetchAll();
            }
            // Add concatenated description for each cotizacion
            foreach ($cotizaciones as &$cotizacion) {
                $cotizacion['description'] = $this->getCotizacionItemsDescription($cotizacion['id']);
                $cotizacion['items'] = $this->getCotizacionItems($cotizacion['id']);
                $cotizacion['ajustes'] = $this->ajustesDeFila($cotizacion);
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
            // c.id DESC desempata las cotizaciones con la misma fecha: sin el, el
            // orden entre ellas lo elegia MySQL y podia cambiar de una pagina a otra.
            $sql = "SELECT c.*, cl.client_name,cl.company_name,cl.rnc FROM cotizaciones c LEFT JOIN clients cl ON c.client_id = cl.id {$whereClause} ORDER BY c.date DESC, c.id DESC LIMIT :limit OFFSET :offset";
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
                $cotizacion['ajustes'] = $this->ajustesDeFila($cotizacion);
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
                error_log('[cotizaciones] no se encontro un codigo libre tras ' . $maxAttempts . ' intentos');
                return ['error', 'No se pudo guardar la cotización. Inténtalo de nuevo.'];
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
                $itemStmt->execute([':cotizacion_id' => $cotizacion_id] + self::filaItem($item));
            }

            // Commit transaction
            $this->conexion->commit();

            // --- PDF Generation and Email Sending ---
            // Only generate PDF and send email if send_email is true
            if (!$send_email) {
                return ['success', ['id' => $cotizacion_id, 'code' => $code, 'message' => 'Cotization saved']];
            }

            // 1. Generate PDF and save to cotizaciones/<tenant_id>/ (los codigos
            // se repiten entre tenants)
            $pdfPath = TenantMail::carpetaPdf(TenantMail::tenantActual(), 'cotizaciones');
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

            $avisoCorreo = $this->sendCotizacionPdfEmail($clientEmail, $code, $pdfFile);

            return ['success', ['id' => $cotizacion_id, 'code' => $code, 'message' => $avisoCorreo ?? 'Cotization saved and emailed']];
        } catch (PDOException $e) {
            // inTransaction: el fallo puede venir de la busqueda del codigo, antes
            // del beginTransaction, y un rollBack sin transaccion lanza otra excepcion.
            if ($this->conexion->inTransaction()) {
                $this->conexion->rollBack();
            }
            // El texto de PDO (SQL, tablas) solo al log; al usuario, uno claro.
            error_log('[cotizaciones] no se pudo guardar la cotizacion: ' . $e->getMessage());
            return ['error', 'No se pudo guardar la cotización. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.'];
        }
    }

    public function updateCotizacion($id, $client_id, $date, $items, $total, $user_id = null, $send_email = false)
    {
        try {
            $existe = $this->getCotizaciones($id);
            if (count($existe) == 0) {
                return ['error', 'Esta cotización ya no existe. Puede que la hayan eliminado; vuelve al listado.'];
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
                $itemStmt->execute([':cotizacion_id' => $id] + self::filaItem($item));
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

            // 1. Generate PDF and save to cotizaciones/<tenant_id>/
            $pdfPath = TenantMail::carpetaPdf(TenantMail::tenantActual(), 'cotizaciones');
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

            $avisoCorreo = $this->sendCotizacionPdfEmail($clientEmail, $code, $pdfFile);

            return ['success', $avisoCorreo ?? 'Cotization updated and emailed'];
        } catch (PDOException $e) {
            if ($this->conexion->inTransaction()) {
                $this->conexion->rollBack();
            }
            error_log('[cotizaciones] no se pudo actualizar la cotizacion ' . $id . ': ' . $e->getMessage());
            return ['error', 'No se pudieron guardar los cambios de la cotización. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.'];
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
                return ['error', 'Esta cotización ya no existe. Puede que otra persona la haya eliminado; actualiza el listado.'];
            }
            
            // Delete cotizacion (items will be deleted via CASCADE)
            $sql = "DELETE FROM cotizaciones WHERE id = :id";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([':id' => $id]);
            return ['success', 'Cotization deleted'];
        } catch (PDOException $e) {
            error_log('[cotizaciones] no se pudo eliminar la cotizacion ' . $id . ': ' . $e->getMessage());
            return ['error', 'No se pudo eliminar la cotización. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.'];
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
            // SELECT * a proposito, sin nombrar columnas: las de la 026
            // (product_id, unidad_medida, indicadores, itbis_amount) salen cuando
            // existen. Nombrarlas en una base sin la 026 haria fallar la consulta,
            // el catch de abajo devolveria [] y la cotizacion se veria sin lineas;
            // guardarla asi borraria las de verdad. ORDER BY id: el orden en que
            // se escribieron, que es el de la pantalla y el del PDF.
            $sql = "SELECT * FROM cotizacion_items WHERE cotizacion_id = :cotizacion_id ORDER BY id ASC";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([':cotizacion_id' => $cotizacion_id]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }

    // ------------------------------------------------------------------------
    // Formatos de cotizacion por tenant (src/Utils/Cotizacion/). Lo de abajo lo
    // usa solo un formato con numeracion propia (hoy FerreteriaFormato); Gratex
    // sigue por saveCotizacion/updateCotizacion, que no cambian.
    // ------------------------------------------------------------------------

    /**
     * Ajustes de la fila para la respuesta del GET, siempre como objeto
     * (json_encode de un arreglo vacio daria [] y el front espera {}).
     *
     * Una fila de Gratex (formato NULL o 'gratex') recibe {} SIN consultar: una
     * base sin cotizacion_ajustes (026 sin correr) nunca puede vaciar el
     * listado de Gratex, dar 404 a su PDF ni hacer que el PUT diga "ya no
     * existe".
     */
    private function ajustesDeFila(array $cotizacion): object
    {
        $formato = $cotizacion['formato'] ?? null;
        if ($formato === null || $formato === '' || $formato === 'gratex') {
            return (object) [];
        }
        return (object) $this->getAjustes((int) $cotizacion['id']);
    }

    /**
     * [concepto => monto] de una cotizacion (montos DECIMAL como texto, como
     * llegan de PDO). Su propio try/catch: un fallo aqui deja la cotizacion
     * sin ajustes en la respuesta, nunca sin lineas ni fuera del listado.
     */
    public function getAjustes(int $id): array
    {
        try {
            $stmt = $this->conexion->prepare('SELECT concepto, monto FROM cotizacion_ajustes WHERE cotizacion_id = :id ORDER BY id ASC');
            $stmt->execute([':id' => $id]);
            return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (PDOException $e) {
            error_log('[cotizaciones] no se pudieron leer los ajustes de la cotizacion ' . $id . ': ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Datos del cliente que necesita un formato: el nombre que se guarda en
     * client_name y lo que imprime el PDF. null si el id no existe.
     *
     * SELECT * y no una lista: si a una base vieja le faltara una columna, se
     * lee como null en vez de impedir cotizar. Sin try/catch a proposito: un
     * fallo de la DB no puede leerse como "el cliente no existe" (el usuario
     * buscaria un cliente que si esta); quien llama lo atrapa y responde el
     * error generico.
     *
     * @return array{client_name:?string, company_name:?string, razon_social:?string, rnc:?string, email:?string}|null
     */
    public function getCliente(int $id): ?array
    {
        $stmt = $this->conexion->prepare('SELECT * FROM clients WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $fila = $stmt->fetch();
        if (!$fila) {
            return null;
        }
        return [
            'client_name' => $fila['client_name'] ?? null,
            'company_name' => $fila['company_name'] ?? null,
            'razon_social' => $fila['razon_social'] ?? null,
            'rnc' => $fila['rnc'] ?? null,
            'email' => $fila['email'] ?? null,
        ];
    }

    /**
     * [product_id => ['indicador_bien_servicio' => int]] de los productos que
     * existen entre $ids; un id que no sale en el resultado no esta en el
     * catalogo. No filtra por `activo`: una cotizacion con un producto que
     * despues se desactivo se tiene que poder seguir editando (la FK tampoco
     * mira activo). Sin try/catch, por lo mismo que getCliente.
     */
    public function getProductosInfo(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($id) => $id > 0)));
        if (!$ids) {
            return [];
        }
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->conexion->prepare("SELECT id, indicador_bien_servicio FROM products WHERE id IN ({$marcas})");
        $stmt->execute($ids);
        $mapa = [];
        foreach ($stmt->fetchAll() as $p) {
            $mapa[(int) $p['id']] = ['indicador_bien_servicio' => (int) $p['indicador_bien_servicio']];
        }
        return $mapa;
    }

    /**
     * Crea una cotizacion con numeracion propia: cabecera, lineas y ajustes en
     * una sola transaccion (spec 6.3).
     *
     * El candado con nombre serializa a dos cajas que guardan a la vez, y el
     * MAX+1 se lee DENTRO de la transaccion. Si aun asi el numero choca con
     * uk_cotizaciones_numero (el candado estaba ocupado y se siguio sin el),
     * se deshace y se repite UNA vez en una transaccion nueva, que vuelve a
     * leer el MAX. Cualquier otro error no se reintenta.
     *
     * @param array $cot 'cot' de FerreteriaFormato::validarForma, ya con el catalogo aplicado.
     *                   date null = ahora.
     * @param array $tot FerreteriaFormato::totales de esas lineas y ajustes
     * @return array ['success', ['id'=>int,'code'=>string,'numero'=>int,'total'=>float]]
     *             | ['error', string] | ['error', string, int $http]
     */
    public function crearConFormato(array $cot, array $tot, string $formato, ?int $userId, string $clientName): array
    {
        // El codigo visible (COT-000123) lo define FerreteriaFormato::codigo,
        // el que prueba el CLI. Se carga aqui y no arriba: Gratex no lo usa.
        require_once __DIR__ . '/../Utils/Cotizacion/FerreteriaFormato.php';
        $fecha = $cot['date'] ?? self::ahoraRd();
        $lock = $this->tomarLockSecuencia();
        try {
            $intento = 0;
            while (true) {
                $intento++;
                $numero = null;
                try {
                    $this->conexion->beginTransaction();
                    $numero = $this->siguienteNumero();
                    $code = FerreteriaFormato::codigo($numero);
                    $stmt = $this->conexion->prepare(
                        'INSERT INTO cotizaciones
                            (formato, numero, code, date, client_id, client_name, subtotal, itbis, total, user_id)
                         VALUES
                            (:formato, :numero, :code, :date, :client_id, :client_name, :subtotal, :itbis, :total, :user_id)'
                    );
                    $stmt->execute([
                        ':formato' => $formato,
                        ':numero' => $numero,
                        ':code' => $code,
                        ':date' => $fecha,
                        ':client_id' => (int) $cot['client_id'],
                        ':client_name' => $clientName,
                        ':subtotal' => $tot['subtotal'],
                        ':itbis' => $tot['itbis'],
                        ':total' => $tot['total'],
                        ':user_id' => $userId,
                    ]);
                    $id = (int) $this->conexion->lastInsertId();
                    $this->insertarDetalle($id, $cot, $tot);
                    $this->conexion->commit();
                    return ['success', ['id' => $id, 'code' => $code, 'numero' => $numero, 'total' => (float) $tot['total']]];
                } catch (PDOException $e) {
                    if ($this->conexion->inTransaction()) {
                        $this->conexion->rollBack();
                    }
                    if ($intento === 1 && self::esNumeroRepetido($e)) {
                        error_log('[cotizaciones] el numero ' . $numero . ' ya estaba tomado (uk_cotizaciones_numero): se reintenta una vez');
                        continue;
                    }
                    error_log('[cotizaciones] no se pudo guardar la cotizacion (' . $formato . '): ' . $e->getMessage());
                    return self::errorConFormato($e, 'No se pudo guardar la cotización. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.');
                }
            }
        } finally {
            // Se suelta pase lo que pase: con el candado puesto, la siguiente
            // cotizacion de esta conexion esperaria los 5 segundos completos.
            if ($lock) {
                $this->soltarLockSecuencia();
            }
        }
    }

    /**
     * Reescribe una cotizacion con numeracion propia: la cabecera, y reemplaza
     * lineas y ajustes (un PUT sin ajustes los deja en ninguno). numero y code
     * no cambian nunca; $cot['date'] null conserva la fecha guardada.
     *
     * @return array mismo formato que crearConFormato; ['error', string, 404] si ya no existe
     */
    public function actualizarConFormato(int $id, array $cot, array $tot, ?int $userId, string $clientName): array
    {
        try {
            $this->conexion->beginTransaction();
            // FOR UPDATE: si otra persona la borra entre la carga del
            // controller y este guardado, se sabe aqui y no se escriben lineas
            // huerfanas. De paso trae numero y code para la respuesta.
            $stmt = $this->conexion->prepare('SELECT code, numero FROM cotizaciones WHERE id = :id FOR UPDATE');
            $stmt->execute([':id' => $id]);
            $actual = $stmt->fetch();
            if (!$actual) {
                $this->conexion->rollBack();
                return ['error', 'Esta cotización ya no existe. Puede que la hayan eliminado; vuelve al listado.', 404];
            }
            $stmt = $this->conexion->prepare(
                'UPDATE cotizaciones
                    SET client_id = :client_id, client_name = :client_name, date = COALESCE(:date, date),
                        subtotal = :subtotal, itbis = :itbis, total = :total,
                        user_id = :user_id, updated_at = :updated_at
                  WHERE id = :id'
            );
            $stmt->execute([
                ':id' => $id,
                ':client_id' => (int) $cot['client_id'],
                ':client_name' => $clientName,
                ':date' => $cot['date'],
                ':subtotal' => $tot['subtotal'],
                ':itbis' => $tot['itbis'],
                ':total' => $tot['total'],
                ':user_id' => $userId,
                ':updated_at' => self::ahoraRd(),
            ]);
            $this->conexion->prepare('DELETE FROM cotizacion_items WHERE cotizacion_id = :id')->execute([':id' => $id]);
            $this->conexion->prepare('DELETE FROM cotizacion_ajustes WHERE cotizacion_id = :id')->execute([':id' => $id]);
            $this->insertarDetalle($id, $cot, $tot);
            $this->conexion->commit();
            return ['success', [
                'id' => $id,
                'code' => (string) $actual['code'],
                'numero' => (int) $actual['numero'],
                'total' => (float) $tot['total'],
            ]];
        } catch (PDOException $e) {
            if ($this->conexion->inTransaction()) {
                $this->conexion->rollBack();
            }
            error_log('[cotizaciones] no se pudo actualizar la cotizacion ' . $id . ' (con formato): ' . $e->getMessage());
            return self::errorConFormato($e, 'No se pudieron guardar los cambios de la cotización. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.');
        }
    }

    /**
     * Lineas (con producto, unidad, indicadores e ITBIS) y ajustes de una
     * cotizacion. Corre dentro de la transaccion de quien llama. subtotal e
     * itbis_amount de cada linea son los de totales(): lo guardado y lo
     * impreso salen de la misma cuenta. El round() de cantidad y precio solo
     * limpia el ruido binario: validarForma ya rechazo lo que tuviera mas
     * decimales, asi que no cambia ningun valor (en 8.3 ni en 8.5).
     */
    private function insertarDetalle(int $cotizacionId, array $cot, array $tot): void
    {
        $stmt = $this->conexion->prepare(
            'INSERT INTO cotizacion_items
                (cotizacion_id, product_id, description, amount, quantity, subtotal,
                 unidad_medida, indicador_facturacion, indicador_bien_servicio, itbis_amount)
             VALUES
                (:cotizacion_id, :product_id, :description, :amount, :quantity, :subtotal,
                 :unidad_medida, :indicador_facturacion, :indicador_bien_servicio, :itbis_amount)'
        );
        foreach (array_values($cot['items']) as $i => $item) {
            $stmt->execute([
                ':cotizacion_id' => $cotizacionId,
                ':product_id' => !empty($item['product_id']) ? (int) $item['product_id'] : null,
                ':description' => (string) $item['description'],
                ':amount' => round((float) $item['amount'], 4),
                ':quantity' => round((float) $item['quantity'], 2),
                ':subtotal' => $tot['lineas'][$i]['base'],
                ':unidad_medida' => (string) $item['unidad_medida'],
                ':indicador_facturacion' => (int) $item['indicador_facturacion'],
                ':indicador_bien_servicio' => (int) $item['indicador_bien_servicio'],
                ':itbis_amount' => $tot['lineas'][$i]['itbis'],
            ]);
        }
        $ajustes = self::ajustesAGuardar($cot, $tot);
        if (!$ajustes) {
            return;
        }
        $stmt = $this->conexion->prepare('INSERT INTO cotizacion_ajustes (cotizacion_id, concepto, monto) VALUES (:cotizacion_id, :concepto, :monto)');
        foreach ($ajustes as $concepto => $monto) {
            $stmt->execute([':cotizacion_id' => $cotizacionId, ':concepto' => $concepto, ':monto' => $monto]);
        }
    }

    /**
     * [concepto => monto] que se guardan: cada concepto que el formato
     * declaro en $cot['ajustes'], con el monto que calculo totales() bajo la
     * misma clave (redondeado; para retencion_isr, el monto de la retencion y
     * no la casilla). Solo los que no son cero (spec 6.1 D).
     */
    private static function ajustesAGuardar(array $cot, array $tot): array
    {
        $out = [];
        foreach (array_keys($cot['ajustes']) as $concepto) {
            $monto = (float) ($tot[$concepto] ?? 0);
            if ($monto > 0) {
                $out[$concepto] = $monto;
            }
        }
        return $out;
    }

    /** Siguiente numero de la secuencia. No bloquea nada: lo serializa el candado de quien llama. */
    private function siguienteNumero(): int
    {
        return (int) $this->conexion->query('SELECT COALESCE(MAX(numero), 0) + 1 FROM cotizaciones')->fetchColumn();
    }

    /** ¿El error es el numero repetido (uk_cotizaciones_numero)? Es el unico que se reintenta. */
    private static function esNumeroRepetido(PDOException $e): bool
    {
        return (int) ($e->errorInfo[1] ?? 0) === 1062
            && stripos((string) ($e->errorInfo[2] ?? $e->getMessage()), 'uk_cotizaciones_numero') !== false;
    }

    /**
     * Mensaje para el usuario de un fallo al guardar con formato. El detalle
     * tecnico ya fue al log; aqui solo se distingue lo que el usuario puede
     * arreglar. El tercer elemento, cuando esta, es el HTTP (si falta, 500).
     */
    private static function errorConFormato(PDOException $e, string $generico): array
    {
        $codigo = (int) ($e->errorInfo[1] ?? 0);
        $detalle = (string) ($e->errorInfo[2] ?? $e->getMessage());
        // 1452 en la FK del producto: lo borraron del catalogo entre la
        // revision (getProductosInfo) y el INSERT.
        if ($codigo === 1452 && stripos($detalle, 'cotizacion_items_product_fk') !== false) {
            return ['error', 'Un producto de la cotización ya no existe en el catálogo (lo eliminaron mientras la editabas). Búscalo de nuevo o quita la línea.', 422];
        }
        // Segundo choque seguido con el numero: dos cajas guardando a la vez.
        if (self::esNumeroRepetido($e)) {
            return ['error', 'Otra cotización se guardó al mismo tiempo. Vuelve a guardar.'];
        }
        return ['error', $generico];
    }

    /** Nombre del candado, por base de datos: un tenant no serializa a otro. */
    private const LOCK_SECUENCIA = "CONCAT(DATABASE(), ':cotizacion_seq')";

    /**
     * Copia de facturaModel::tomarLockSecuenciaSimple (alli es privado): lock
     * de MySQL a nivel de CONEXION, no de tabla. Espera hasta 5 segundos; si no
     * lo consigue NO aborta: se numera sin serializar y el indice unico mas el
     * reintento cubren el choque.
     *
     * @return bool true si hay que soltarlo despues.
     */
    private function tomarLockSecuencia(): bool
    {
        try {
            $stmt = $this->conexion->query('SELECT GET_LOCK(' . self::LOCK_SECUENCIA . ', 5)');
            // 1 = tomado; 0 = expiro la espera; NULL = error del servidor.
            if ((int) $stmt->fetchColumn() === 1) {
                return true;
            }
            error_log('[cotizaciones] lock de numeracion ocupado: se numera sin serializar');
            return false;
        } catch (PDOException $e) {
            error_log('[cotizaciones] no se pudo tomar el lock de numeracion: ' . $e->getMessage());
            return false;
        }
    }

    private function soltarLockSecuencia(): void
    {
        try {
            $this->conexion->query('SELECT RELEASE_LOCK(' . self::LOCK_SECUENCIA . ')');
        } catch (PDOException $e) {
            error_log('[cotizaciones] no se pudo soltar el lock de numeracion: ' . $e->getMessage());
        }
    }

    /** Ahora en Santo Domingo, como lo guarda un DATETIME (el server puede estar en otra zona). */
    private static function ahoraRd(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('America/Santo_Domingo')))->format('Y-m-d H:i:s');
    }

    /**
     * Envia la cotizacion al cliente con la identidad del tenant (TenantMail):
     * Gratex como siempre; otro tenant desde su emisor_config.correo y sin
     * copias a Gratex.
     *
     * @return string|null null si se envio; si no, el aviso para el usuario (la
     *                     cotizacion ya quedo guardada).
     */
    private function sendCotizacionPdfEmail(string $clientEmail, string $code, string $pdfFile): ?string
    {
        $tenant = TenantMail::tenantActual();
        $remitente = TenantMail::remitenteDelTenant();
        if ($remitente === null) {
            return 'La cotización se guardó, pero no se envió por correo: tu empresa no tiene un correo registrado. Pide a soporte que lo agregue y vuelve a guardarla con "Enviar por correo" activado.';
        }
        $to = TenantMail::destinatarios($tenant, $clientEmail, TenantMail::COPIAS_GRATEX_DOCUMENTOS);
        if ($to === '') {
            return 'La cotización se guardó, pero no se envió por correo: el cliente no tiene un correo válido registrado.';
        }
        $subject = 'Cotizacion anexa';
        $htmlContent = '<p>Estimado cliente:<br/> Su Cotizaci&oacute;n <b>' . $code . '</b> se encuentra anexa a este mensaje.</p>';

        $mime_boundary = '==Multipart_Boundary_x' . md5(time()) . 'x';
        $headers = TenantMail::cabeceraFrom($remitente);
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: multipart/mixed;\r\n boundary=\"{$mime_boundary}\"";

        $message = "--{$mime_boundary}\r\n";
        $message .= "Content-Type: text/html; charset=\"UTF-8\"\r\n";
        $message .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
        $message .= $htmlContent . "\r\n\r\n";

        if (!empty($pdfFile) && is_file($pdfFile)) {
            $fp = fopen($pdfFile, 'rb');
            $data = chunk_split(base64_encode(fread($fp, filesize($pdfFile))));
            fclose($fp);
            $message .= "--{$mime_boundary}\r\n";
            $message .= "Content-Type: application/octet-stream; name=\"" . basename($pdfFile) . "\"\r\n";
            $message .= "Content-Description: " . basename($pdfFile) . "\r\n";
            $message .= "Content-Disposition: attachment; filename=\"" . basename($pdfFile) . "\"; size=" . filesize($pdfFile) . ";\r\n";
            $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $message .= $data . "\r\n\r\n";
        }
        $message .= "--{$mime_boundary}--\r\n";

        if (!mail($to, $subject, $message, $headers, TenantMail::parametroEnvelope($remitente))) {
            error_log('[cotizaciones] mail() no acepto la cotizacion ' . $code . ' (tenant ' . ($tenant['id'] ?? 'sin tenant') . ', from ' . $remitente['correo'] . ')');
            return 'La cotización se guardó, pero no se pudo enviar el correo. Inténtalo de nuevo más tarde y, si sigue pasando, avisa a soporte.';
        }
        return null;
    }
}