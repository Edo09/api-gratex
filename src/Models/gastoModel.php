<?php
require_once(__DIR__ . '/../Database.php');
require_once(__DIR__ . '/ncfModel.php');
require_once(__DIR__ . '/tipoBienesServiciosModel.php');
// Arriba y no junto a ECFEmissionService: el catch de createGasto tiene que
// reconocer la clase aunque la emision falle antes de cargar el servicio.
require_once(__DIR__ . '/../Utils/FacturacionElectronica/EcfUsuarioException.php');

/**
 * Modulo de Gastos.
 *
 * Maneja dos clases de gasto segun `es_auto_emision`:
 *   - Auto-emision (1): la empresa EMITE el comprobante y genera la secuencia
 *     interna via ncfModel. Tipos electronicos: E41 (Compras / NCF 11),
 *     E43 (Gastos Menores / NCF 13), E47 (Pagos al Exterior / NCF 17).
 *   - Recibido (0): el proveedor entrego el comprobante; el usuario digita el
 *     NCF. Hoy: E31 (Credito Fiscal) y E33/E34 (notas). Es un registro interno:
 *     no se envia a la DGII. B01 no se da de alta (solo quedan filas historicas).
 *
 * Las lineas con `product_id` mueven inventario al registrar; eso lo hace el
 * controlador despues de guardar (inventoryModel::registrarCompra).
 */
class gastoModel
{
    private $conexion;
    private $ncfModel;

    /**
     * Tipos de NCF/e-CF permitidos por categoria de gasto:
     *   - gastos_menores       -> E43 (Gastos Menores: peajes, suministros, personal).
     *   - facturas_proveedores -> E41 (Compras informal) y E47 (Pagos al Exterior)
     *                             emitidos por la empresa; mas E31 (Credito Fiscal),
     *                             E33 (Nota de Debito) y E34 (Nota de Credito)
     *                             recibidos del proveedor, cuyo NCF digita el usuario.
     *
     * E31 volvio el 2026-09-14. Se habia bloqueado el 2026-06-12 suponiendo que
     * todo Credito Fiscal llega por la recepcion e-CF, pero no todos los sistemas
     * de los proveedores lo envian. Se registra como compra interna SIN mirar la
     * recepcion: si la misma factura tambien llega por ahi, el 606 la declara una
     * sola vez (Reporte606Model::deduplicar). B01 sigue bloqueado; sus filas
     * historicas se conservan.
     */
    private const CATEGORIAS = [
        'gastos_menores' => ['E43'],
        'facturas_proveedores' => ['E41', 'E47', 'E31', 'E33', 'E34'],
    ];

    /**
     * Tipos que EMITE la empresa: para ellos el sistema genera la secuencia
     * interna (es_auto_emision = true). El resto (E31 y las notas E33/E34) son
     * recibidos y el usuario digita el NCF del proveedor.
     */
    private const AUTO_EMISION_TYPES = ['E41', 'E43', 'E47'];

    public function __construct()
    {
        $this->conexion = Database::getInstance()->getConnection();
        $this->ncfModel = new ncfModel();
    }

    // =========================================================================
    // Lectura
    // =========================================================================

    /**
     * Obtiene un gasto por id, con sus lineas.
     * @return array|null Null si no existe.
     */
    public function getGasto(int $id): ?array
    {
        try {
            $stmt = $this->conexion->prepare('SELECT * FROM gastos WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch();
            if (!$row) {
                return null;
            }
            $row['items'] = $this->getGastoItems($id);
            return $row;
        } catch (PDOException $e) {
            // Null = "no existe" para quien llama: sin el log, una caida de la
            // DB se confundiria con un gasto inexistente.
            error_log('[gastos] getGasto ' . $id . ': ' . $e->getMessage());
            return null;
        }
    }

    public function getGastoItems(int $gastoId): array
    {
        try {
            // SELECT * y no la lista de columnas: product_id (migracion 024) sale
            // cuando existe, y un servidor sin la migracion no pierde las lineas.
            $sql = 'SELECT * FROM gasto_items WHERE gasto_id = :id ORDER BY id ASC';
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([':id' => $gastoId]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log('[gastos] getGastoItems ' . $gastoId . ': ' . $e->getMessage());
            return [];
        }
    }

    public function getGastosPaginated(int $offset, int $limit, ?string $query = null, ?string $categoria = null): array
    {
        try {
            $ambiente = $this->ncfModel->resolveActiveAmbiente();
            $conditions = [];
            $params = [];
            if ($query) {
                $conditions[] = '(g.ncf LIKE :query OR g.rnc_proveedor LIKE :query OR g.nombre_proveedor LIKE :query OR g.tipo_gasto LIKE :query)';
                $params[':query'] = "%{$query}%";
            }
            if ($categoria !== null && isset(self::CATEGORIAS[$categoria])) {
                $conditions[] = 'g.categoria = :categoria';
                $params[':categoria'] = $categoria;
            }
            if ($ambiente !== null) {
                $conditions[] = 'g.ambiente = :ambiente';
                $params[':ambiente'] = $ambiente;
            }
            $whereClause = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
            $sql = "SELECT g.* FROM gastos g {$whereClause} ORDER BY g.id DESC LIMIT :limit OFFSET :offset";
            $stmt = $this->conexion->prepare($sql);
            foreach ($params as $key => $val) {
                $stmt->bindValue($key, $val, \PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', (int) $limit, \PDO::PARAM_INT);
            $stmt->bindValue(':offset', (int) $offset, \PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log('[gastos] getGastosPaginated: ' . $e->getMessage());
            return [];
        }
    }

    public function getGastosCount(?string $query = null, ?string $categoria = null): int
    {
        try {
            $ambiente = $this->ncfModel->resolveActiveAmbiente();
            $conditions = [];
            $params = [];
            if ($query) {
                $conditions[] = '(g.ncf LIKE :query OR g.rnc_proveedor LIKE :query OR g.nombre_proveedor LIKE :query OR g.tipo_gasto LIKE :query)';
                $params[':query'] = "%{$query}%";
            }
            if ($categoria !== null && isset(self::CATEGORIAS[$categoria])) {
                $conditions[] = 'g.categoria = :categoria';
                $params[':categoria'] = $categoria;
            }
            if ($ambiente !== null) {
                $conditions[] = 'g.ambiente = :ambiente';
                $params[':ambiente'] = $ambiente;
            }
            $whereClause = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
            $sql = "SELECT COUNT(*) AS total FROM gastos g {$whereClause}";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch();
            return $row ? (int) $row['total'] : 0;
        } catch (PDOException $e) {
            error_log('[gastos] getGastosCount: ' . $e->getMessage());
            return 0;
        }
    }

    // =========================================================================
    // Escritura
    // =========================================================================

    /**
     * Crea un gasto con sus lineas.
     *
     * Flujo segun el tipo:
     *   - Auto-emision (E41/E43/E47): la empresa lo emite a DGII como e-CF
     *     (build XML -> firmar -> enviar) reusando ECFEmissionService. Guarda
     *     track_id/estado/codigo_seguridad/xml_firmado.
     *   - Recibido (E33/E34): solo se registra (estado REGISTRADO); ya lo
     *     emitio el proveedor a DGII.
     *
     * GUARD de seguridad: si DGII_ECF_EMISSION_ENABLED no esta en true, los
     * auto-emision NO se envian a DGII ni consumen secuencia; se guardan como
     * PENDIENTE_EMISION. Protege el ambiente de produccion `ecf`.
     *
     * Los mensajes de error llegan tal cual al formulario de gasto/compra: se
     * escriben para quien lo llena. Cuando hay un detalle tecnico (excepcion de
     * la DB) viaja aparte, como tercer elemento, para la bitacora.
     *
     * @param array $data {categoria, tipo_gasto, rnc_proveedor, nombre_proveedor,
     *                     ncf?, fecha?, subtotal?, itbis?, total?, user_id?, items[]}
     * @return array ['success', payload] | ['error', mensaje, detalle tecnico?]
     */
    public function createGasto(array $data): array
    {
        $categoria = strtolower(trim((string) ($data['categoria'] ?? '')));
        if (!isset(self::CATEGORIAS[$categoria])) {
            return ['error', 'Elige si es un gasto menor o una compra a proveedor.'];
        }

        $tipoGasto = strtoupper(trim((string) ($data['tipo_gasto'] ?? '')));
        if ($tipoGasto === '') {
            return ['error', 'Elige el tipo de comprobante.'];
        }
        if (!in_array($tipoGasto, self::CATEGORIAS[$categoria], true)) {
            $donde = $categoria === 'gastos_menores' ? 'gastos menores' : 'compras a proveedores';
            return ['error', "El tipo de comprobante {$tipoGasto} no se puede usar en {$donde}. Elige otro."];
        }

        // Tipo de Bienes y Servicios Comprados: campo 3 del 606. Es el dato que
        // el reporte declara a la DGII, y antes se estampaba '09' a ciegas en
        // todas las filas. Se exige al registrar para no arrastrar un valor
        // inventado hasta el momento de declarar.
        $tipoBienes = tipoBienesServiciosModel::normalizar($data['tipo_bienes_servicios'] ?? null);
        if ($tipoBienes === null) {
            return ['error', 'Elige el tipo de costo o gasto.'];
        }
        if (!(new tipoBienesServiciosModel())->isValid($tipoBienes)) {
            return ['error', 'El tipo de costo o gasto que elegiste ya no es válido. Elige otro de la lista.'];
        }

        // RNC/Cedula del proveedor: requerido salvo Gastos Menores (E43), que suele
        // sustentar peajes/parqueos/suministros sin RNC formal.
        $rncProveedor = trim((string) ($data['rnc_proveedor'] ?? ''));
        if ($rncProveedor === '' && $tipoGasto !== 'E43') {
            return ['error', 'Elige un proveedor que tenga RNC. Solo los gastos menores se pueden registrar sin proveedor.'];
        }

        $nombreProveedor = trim((string) ($data['nombre_proveedor'] ?? ''));
        if ($nombreProveedor === '') {
            if ($tipoGasto !== 'E43') {
                return ['error', 'Falta el nombre del proveedor. Complétalo en Proveedores y vuelve a intentarlo.'];
            }
            // E43 sin proveedor formal: etiqueta neutra (la columna es NOT NULL y
            // el e-CF 43 se emite SIN comprador, ver buildEcfPayload).
            $nombreProveedor = 'Gasto menor';
        }

        $items = $this->normalizeItems($data['items'] ?? []);
        if (empty($items)) {
            return ['error', 'Agrega al menos una línea con descripción e importe.'];
        }

        // es_auto_emision se DERIVA del tipo: E41/E43/E47 los emite la empresa;
        // E31/E33/E34 son recibidos (el usuario digita el NCF del proveedor).
        $esAutoEmision = in_array($tipoGasto, self::AUTO_EMISION_TYPES, true);
        // Mayusculas: un "e31..." digitado a mano es el mismo comprobante, y tanto
        // el duplicado (rnc_proveedor, ncf) como el cruce del 606 comparan exacto.
        $ncf = strtoupper(trim((string) ($data['ncf'] ?? '')));
        if (!$esAutoEmision && $ncf === '') {
            return ['error', 'Digita el NCF que te entregó el proveedor.'];
        }
        // E31 se copia de una factura impresa: un digito de mas o de menos no
        // saltaria hasta generar el 606, cuando ya toca corregir a destiempo.
        if ($tipoGasto === 'E31' && !preg_match('/^E31\d{10}$/', $ncf)) {
            return ['error', "El e-NCF {$ncf} no es válido: debe ser E31 seguido de 10 dígitos (ej. E310000000123)."];
        }

        // Totales: se respetan los del body; si no vienen se calculan de las lineas.
        $subtotal = isset($data['subtotal']) && $data['subtotal'] !== ''
            ? (float) $data['subtotal']
            : $this->sumField($items, 'subtotal');
        $itbis = isset($data['itbis']) && $data['itbis'] !== ''
            ? (float) $data['itbis']
            : $this->sumField($items, 'itbis_amount');
        $total = isset($data['total']) && $data['total'] !== ''
            ? (float) $data['total']
            : round($subtotal + $itbis, 2);

        $ambiente = $this->ncfModel->resolveActiveAmbiente();

        // Registro base (las columnas DGII van nulas por defecto).
        $g = [
            'categoria' => $categoria,
            'tipo_gasto' => $tipoGasto,
            'tipo_bienes_servicios' => $tipoBienes,
            'ncf' => $ncf !== '' ? $ncf : null,
            'rnc_proveedor' => $rncProveedor !== '' ? $rncProveedor : null,
            'nombre_proveedor' => $nombreProveedor !== '' ? $nombreProveedor : null,
            // '' cuenta como sin fecha: MySQL lo rechaza con un error de formato.
            'fecha' => ($data['fecha'] ?? '') !== '' ? $data['fecha'] : date('Y-m-d'),
            'subtotal' => $subtotal,
            'itbis' => $itbis,
            'total' => $total,
            'es_auto_emision' => $esAutoEmision ? 1 : 0,
            'ambiente' => $ambiente,
            'user_id' => $data['user_id'] ?? null,
            'track_id' => null,
            'estado_dgii' => 'REGISTRADO',
            'codigo_seguridad' => null,
            'fecha_emision_dgii' => null,
            'xml_firmado' => null,
            'respuesta_dgii' => null,
            'secuencia_utilizada' => null,
        ];

        // Recibido: solo registrar.
        if (!$esAutoEmision) {
            return $this->persistGasto($g, $items);
        }

        // Auto-emision con el GUARD apagado: no tocar DGII ni secuencia.
        if (!$this->emissionEnabled()) {
            $g['estado_dgii'] = 'PENDIENTE_EMISION';
            $g['ncf'] = null;
            $result = $this->persistGasto($g, $items);
            if ($result[0] === 'success') {
                // El aviso lo ve el usuario (toast y detalle del gasto): sin el
                // nombre de la variable de entorno ni estados internos.
                $result[1]['aviso'] = 'El gasto se guardó, pero no se envió a la DGII porque el envío de comprobantes '
                    . 'electrónicos está desactivado. No se usó ningún número de comprobante.';
            }
            return $result;
        }

        // Emision real a DGII reusando el pipeline de facturas.
        try {
            $ecf = $this->emitirGastoDgii($tipoGasto, $rncProveedor, $nombreProveedor, $items, $data);
        } catch (Throwable $e) {
            // Se guarda igual con estado ERROR para trazabilidad (punto 3). El
            // texto tecnico queda en respuesta_dgii (y con el gasto, en la
            // bitacora) y en el log; el aviso que ve el usuario es llano.
            error_log('[ECF GASTO] emision ' . $tipoGasto . ' fallo: ' . $e->getMessage());
            $g['estado_dgii'] = 'ERROR';
            $g['respuesta_dgii'] = json_encode(['error' => $e->getMessage()]);
            $result = $this->persistGasto($g, $items);
            if ($result[0] === 'success') {
                $result[1]['aviso'] = $this->avisoFalloEmision($e);
            }
            return $result;
        }

        $g['ncf'] = $ecf['e_ncf'];
        $g['track_id'] = $ecf['track_id'] ?? null;
        $g['estado_dgii'] = $ecf['estado'] ?? 'ENVIADO';
        $g['codigo_seguridad'] = $ecf['codigo_seguridad'] ?? null;
        $g['fecha_emision_dgii'] = $ecf['fecha_emision_dgii'] ?? null;
        $g['xml_firmado'] = $ecf['signed_xml'] ?? null;
        $g['respuesta_dgii'] = isset($ecf['dgii_response']) ? json_encode($ecf['dgii_response']) : null;
        $result = $this->persistGasto($g, $items);
        if ($result[0] !== 'success') {
            // El comprobante YA esta en la DGII y la secuencia consumida: el
            // "intentalo de nuevo" de siempre emitiria un segundo e-CF por la
            // misma compra. Hay que conciliarlo a mano, con el numero a la vista.
            error_log(sprintf(
                '[ECF GASTO] emitido pero NO guardado: ncf=%s track_id=%s detalle=%s',
                (string) $g['ncf'], (string) ($g['track_id'] ?? ''), (string) ($result[2] ?? $result[1])
            ));
            return ['error',
                'El comprobante ' . $g['ncf'] . ' se envió a la DGII, pero no se pudo guardar en el sistema. '
                . 'No lo registres de nuevo: avisa a soporte con el número ' . $g['ncf'] . '.',
                $result[2] ?? $result[1]];
        }
        return $result;
    }

    /**
     * Aviso (con el gasto ya guardado en ERROR) de por que no salio a la DGII.
     *
     * EcfUsuarioException trae un texto que el usuario entiende y puede
     * resolver (rango e-NCF agotado, emisor sin configurar...). Cualquier otra
     * excepcion es un fallo interno o de la DGII: su texto (rutas, codigos HTTP,
     * nombres de variables) no le dice nada a quien registra el gasto.
     */
    private function avisoFalloEmision(Throwable $e): string
    {
        $base = 'El gasto se guardó, pero no se pudo enviar a la DGII.';
        if ($e instanceof EcfUsuarioException) {
            return $base . ' ' . $e->getMensajeUsuario();
        }
        // El gasto YA quedo guardado (estado ERROR): registrarlo otra vez lo
        // duplicaria en los totales y en el 606, y si la DGII alcanzo a recibir el
        // e-CF antes de fallar, le llegaria un segundo comprobante por la misma compra.
        return $base . ' No lo registres de nuevo: avisa a soporte.';
    }

    /**
     * Inserta el gasto (cabecera + lineas) en una transaccion.
     * @param array $g Cabecera completa (todas las columnas de `gastos`).
     * @return array ['success', payload] | ['error', mensaje para el usuario, detalle tecnico]
     *               El detalle (tercer elemento) es lo que va a la bitacora.
     */
    private function persistGasto(array $g, array $items): array
    {
        try {
            $this->conexion->beginTransaction();

            // Archivar reintentos de e-CF rechazados para conservar historial sin chocar con uk_proveedor_ncf
            if (!empty($g['ncf']) && !empty($g['rnc_proveedor'])) {
                $prev = $this->conexion->prepare('SELECT id, estado_dgii FROM gastos WHERE ncf = :ncf AND rnc_proveedor = :rnc_proveedor');
                $prev->execute([':ncf' => $g['ncf'], ':rnc_proveedor' => $g['rnc_proveedor']]);
                $prevRow = $prev->fetch(PDO::FETCH_ASSOC);
                if ($prevRow) {
                    $estadosRechazo = ['RECHAZADO', 'NO_ENCONTRADO'];
                    if (!in_array((string) ($prevRow['estado_dgii'] ?? ''), $estadosRechazo, true)) {
                        $this->conexion->rollBack();
                        // El estado (REGISTRADO, PENDIENTE_EMISION...) es un codigo
                        // interno: al usuario le basta saber que ya lo registro; el
                        // estado queda en el detalle para la bitacora.
                        return ['error',
                            'Ya registraste el comprobante ' . $g['ncf'] . ' de este proveedor. Búscalo en la lista.',
                            'Ya existe un gasto con NCF ' . $g['ncf'] . ' para este proveedor en estado '
                                . ($prevRow['estado_dgii'] ?? 'desconocido')];
                    }
                    $delId = (int) $prevRow['id'];
                    $this->conexion->prepare(
                        "UPDATE gastos SET ncf = NULL, estado_dgii = CONCAT(estado_dgii, '_ARCHIVADO') WHERE id = :id"
                    )->execute([':id' => $delId]);
                }
            }

            $sql = 'INSERT INTO gastos
                    (categoria, tipo_gasto, tipo_bienes_servicios, ncf, rnc_proveedor, nombre_proveedor, fecha,
                     subtotal, itbis, total, es_auto_emision, ambiente, user_id,
                     track_id, estado_dgii, codigo_seguridad, fecha_emision_dgii,
                     xml_firmado, respuesta_dgii, secuencia_utilizada)
                    VALUES
                    (:categoria, :tipo_gasto, :tipo_bienes_servicios, :ncf, :rnc_proveedor, :nombre_proveedor, :fecha,
                     :subtotal, :itbis, :total, :es_auto_emision, :ambiente, :user_id,
                     :track_id, :estado_dgii, :codigo_seguridad, :fecha_emision_dgii,
                     :xml_firmado, :respuesta_dgii, :secuencia_utilizada)';
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([
                ':categoria' => $g['categoria'],
                ':tipo_gasto' => $g['tipo_gasto'],
                ':tipo_bienes_servicios' => $g['tipo_bienes_servicios'],
                ':ncf' => $g['ncf'],
                ':rnc_proveedor' => $g['rnc_proveedor'],
                ':nombre_proveedor' => $g['nombre_proveedor'],
                ':fecha' => $g['fecha'],
                ':subtotal' => $g['subtotal'],
                ':itbis' => $g['itbis'],
                ':total' => $g['total'],
                ':es_auto_emision' => $g['es_auto_emision'],
                ':ambiente' => $g['ambiente'],
                ':user_id' => $g['user_id'],
                ':track_id' => $g['track_id'],
                ':estado_dgii' => $g['estado_dgii'],
                ':codigo_seguridad' => $g['codigo_seguridad'],
                ':fecha_emision_dgii' => $g['fecha_emision_dgii'],
                ':xml_firmado' => $g['xml_firmado'],
                ':respuesta_dgii' => $g['respuesta_dgii'],
                ':secuencia_utilizada' => $g['secuencia_utilizada'],
            ]);
            $gastoId = (int) $this->conexion->lastInsertId();
            $this->insertItems($gastoId, $items);
            $this->conexion->commit();
            return ['success', $this->getGasto($gastoId)];
        } catch (PDOException $e) {
            if ($this->conexion->inTransaction()) {
                $this->conexion->rollBack();
            }
            // El texto de PDO (columnas, SQL) no es para el formulario: va al
            // log y, como detalle, a la bitacora (gastosController lo copia a
            // error_message). El usuario recibe el generico.
            error_log('[gastos] persistGasto: ' . $e->getMessage());
            return ['error',
                'No se pudo guardar el gasto. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.',
                'No se pudo crear el gasto: ' . $e->getMessage()];
        }
    }

    /**
     * Actualiza la cabecera de un gasto (y opcionalmente sus lineas).
     * No re-genera secuencias: tipo_gasto, ncf y es_auto_emision se conservan.
     * @return array ['success', payload] | ['error', mensaje]
     */
    public function updateGasto(int $id, array $data): array
    {
        try {
            $cur = $this->conexion->prepare('SELECT * FROM gastos WHERE id = :id');
            $cur->execute([':id' => $id]);
            $row = $cur->fetch();
            if (!$row) {
                return ['error', 'Este gasto ya no existe.'];
            }

            $rncProveedor = $data['rnc_proveedor'] ?? $row['rnc_proveedor'];
            $nombreProveedor = $data['nombre_proveedor'] ?? $row['nombre_proveedor'];
            $fecha = ($data['fecha'] ?? '') !== '' ? $data['fecha'] : $row['fecha'];

            // Tipo de Bienes y Servicios (campo 3 del 606). Editable a proposito:
            // los gastos anteriores a la migracion quedaron en NULL y hay que
            // poder clasificarlos antes de declarar el periodo. Si no viene en el
            // body se conserva el guardado; si viene, se valida como en el alta.
            $tipoBienes = $row['tipo_bienes_servicios'];
            if (array_key_exists('tipo_bienes_servicios', $data)) {
                $tipoBienes = tipoBienesServiciosModel::normalizar($data['tipo_bienes_servicios']);
                if ($tipoBienes === null) {
                    return ['error', 'Elige el tipo de costo o gasto.'];
                }
                if (!(new tipoBienesServiciosModel())->isValid($tipoBienes)) {
                    return ['error', 'El tipo de costo o gasto que elegiste ya no es válido. Elige otro de la lista.'];
                }
            }

            $replaceItems = isset($data['items']) && is_array($data['items']);
            $items = $replaceItems ? $this->normalizeItems($data['items']) : [];

            if ($replaceItems) {
                $subtotal = $this->sumField($items, 'subtotal');
                $itbis = $this->sumField($items, 'itbis_amount');
            } else {
                $subtotal = isset($data['subtotal']) && $data['subtotal'] !== '' ? (float) $data['subtotal'] : (float) $row['subtotal'];
                $itbis = isset($data['itbis']) && $data['itbis'] !== '' ? (float) $data['itbis'] : (float) $row['itbis'];
            }
            $total = isset($data['total']) && $data['total'] !== ''
                ? (float) $data['total']
                : round($subtotal + $itbis, 2);

            $this->conexion->beginTransaction();
            $upd = $this->conexion->prepare(
                'UPDATE gastos SET rnc_proveedor = :rnc_proveedor, nombre_proveedor = :nombre_proveedor,
                        fecha = :fecha, subtotal = :subtotal, itbis = :itbis, total = :total,
                        tipo_bienes_servicios = :tipo_bienes_servicios
                 WHERE id = :id'
            );
            $upd->execute([
                ':rnc_proveedor' => $rncProveedor,
                ':nombre_proveedor' => $nombreProveedor,
                ':fecha' => $fecha,
                ':subtotal' => $subtotal,
                ':itbis' => $itbis,
                ':total' => $total,
                ':tipo_bienes_servicios' => $tipoBienes,
                ':id' => $id,
            ]);

            if ($replaceItems) {
                $this->conexion->prepare('DELETE FROM gasto_items WHERE gasto_id = :id')->execute([':id' => $id]);
                $this->insertItems($id, $items);
            }

            $this->conexion->commit();
            return ['success', $this->getGasto($id)];
        } catch (PDOException $e) {
            if ($this->conexion->inTransaction()) {
                $this->conexion->rollBack();
            }
            error_log('[gastos] updateGasto ' . $id . ': ' . $e->getMessage());
            return ['error',
                'No se pudieron guardar los cambios del gasto. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.',
                'No se pudo actualizar el gasto: ' . $e->getMessage()];
        }
    }

    /**
     * Elimina un gasto y sus lineas (FK ON DELETE CASCADE cubre las lineas).
     * @return array ['success', mensaje] | ['error', mensaje]
     */
    public function deleteGasto(int $id): array
    {
        try {
            $cur = $this->conexion->prepare('SELECT id FROM gastos WHERE id = :id');
            $cur->execute([':id' => $id]);
            if (!$cur->fetch()) {
                return ['error', 'Este gasto ya no existe.'];
            }
            $stmt = $this->conexion->prepare('DELETE FROM gastos WHERE id = :id');
            $stmt->execute([':id' => $id]);
            return ['success', 'Gasto eliminado'];
        } catch (PDOException $e) {
            error_log('[gastos] deleteGasto ' . $id . ': ' . $e->getMessage());
            return ['error',
                'No se pudo eliminar el gasto. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.',
                'No se pudo eliminar el gasto: ' . $e->getMessage()];
        }
    }

    // =========================================================================
    // Estadisticas
    // =========================================================================

    public function getActiveAmbiente(): ?string
    {
        return $this->ncfModel->resolveActiveAmbiente();
    }

    /**
     * Estadisticas de gastos, analogas a facturaModel::getECFStats pero sobre la
     * tabla `gastos`. Cada comprobante usa su propio tipo:
     *   E41 (Compras/11), E43 (Gastos Menores/13), E47 (Pagos Exterior/17),
     *   E31 (Credito Fiscal recibido) y E33/E34 (notas recibidas). Las filas
     *   historicas B01 (alta bloqueada) se EXCLUYEN de todas las agregaciones.
     * Agrupa por tipo_gasto, por categoria y por mes; ademas reporta el estado de
     * las secuencias internas de los tipos que emite la empresa (E41/E43/E47).
     */
    public function getGastosStats(): array
    {
        try {
            $ambiente = $this->ncfModel->resolveActiveAmbiente();
            $ambFilter = $ambiente !== null ? "AND ambiente = '{$ambiente}'" : '';
            // B01 ya no se registra: fuera de stats aunque existan filas viejas.
            // E31 SI cuenta (volvio como compra recibida el 2026-09-14), tambien
            // sus filas historicas: eran compras reales registradas a mano.
            $tipoFilter = "AND tipo_gasto <> 'B01'";

            $resumen = $this->conexion->query(
                "SELECT COUNT(*) as total_gastos,
                        COALESCE(SUM(total), 0) as monto_total,
                        COALESCE(SUM(subtotal), 0) as subtotal_total,
                        COALESCE(SUM(itbis), 0) as itbis_total,
                        COUNT(DISTINCT tipo_gasto) as tipos_distintos,
                        MIN(fecha) as primer_gasto,
                        MAX(fecha) as ultimo_gasto
                 FROM gastos WHERE 1=1 {$ambFilter} {$tipoFilter}"
            )->fetch(PDO::FETCH_ASSOC);

            $porTipo = $this->conexion->query(
                "SELECT tipo_gasto,
                        COUNT(*) as total,
                        COALESCE(SUM(total), 0) as monto_total,
                        COALESCE(SUM(subtotal), 0) as subtotal_total,
                        COALESCE(SUM(itbis), 0) as itbis_total,
                        SUM(CASE WHEN es_auto_emision = 1 THEN 1 ELSE 0 END) as auto_emitidos,
                        SUM(CASE WHEN es_auto_emision = 0 THEN 1 ELSE 0 END) as recibidos,
                        MAX(fecha) as ultimo
                 FROM gastos WHERE 1=1 {$ambFilter} {$tipoFilter}
                 GROUP BY tipo_gasto ORDER BY tipo_gasto"
            )->fetchAll(PDO::FETCH_ASSOC);

            $porCategoria = $this->conexion->query(
                "SELECT categoria,
                        COUNT(*) as total,
                        COALESCE(SUM(total), 0) as monto_total,
                        COALESCE(SUM(itbis), 0) as itbis_total
                 FROM gastos WHERE 1=1 {$ambFilter} {$tipoFilter}
                 GROUP BY categoria ORDER BY total DESC"
            )->fetchAll(PDO::FETCH_ASSOC);

            $porMes = $this->conexion->query(
                "SELECT DATE_FORMAT(fecha, '%Y-%m') as mes,
                        COUNT(*) as total,
                        COALESCE(SUM(total), 0) as monto_total
                 FROM gastos
                 WHERE fecha >= DATE_SUB(NOW(), INTERVAL 12 MONTH) {$ambFilter} {$tipoFilter}
                 GROUP BY mes ORDER BY mes DESC"
            )->fetchAll(PDO::FETCH_ASSOC);

            // Secuencias internas solo para los tipos que EMITE la empresa.
            // Varias filas por tipo = rangos autorizados DGII (agregadas por tipo;
            // restantes NULL = sin rango con limite registrado).
            $ambSeqFilter = $ambiente !== null ? "AND ns.ambiente = '{$ambiente}'" : "AND ns.ambiente = 'certecf'";
            $secuencias = $this->conexion->query(
                "SELECT ns.type,
                        MAX(ns.current_value) as secuencia_actual,
                        COALESCE(MAX(g.total_emitidos), 0) as total_emitidos,
                        SUM(CASE WHEN ns.numero_hasta IS NOT NULL
                                  AND (ns.fecha_vencimiento IS NULL OR ns.fecha_vencimiento >= CURDATE())
                                 THEN GREATEST(ns.numero_hasta - ns.current_value, 0) END) as restantes,
                        MIN(CASE WHEN (ns.numero_hasta IS NULL OR ns.current_value < ns.numero_hasta)
                                  AND (ns.fecha_vencimiento IS NULL OR ns.fecha_vencimiento >= CURDATE())
                                 THEN ns.fecha_vencimiento END) as vencimiento
                 FROM ncf_sequences ns
                 LEFT JOIN (
                     SELECT tipo_gasto as type, COUNT(*) as total_emitidos
                     FROM gastos WHERE es_auto_emision = 1 {$ambFilter} GROUP BY tipo_gasto
                 ) g ON ns.type = g.type
                 WHERE ns.type IN ('E41','E43','E47') {$ambSeqFilter}
                 GROUP BY ns.type
                 ORDER BY ns.type"
            )->fetchAll(PDO::FETCH_ASSOC);

            return [
                'resumen' => $resumen,
                'por_tipo' => $porTipo,
                'por_categoria' => $porCategoria,
                'por_mes' => $porMes,
                'secuencias' => $secuencias,
            ];
        } catch (PDOException $e) {
            error_log('[gastos] getGastosStats: ' . $e->getMessage());
            return [
                'resumen' => null,
                'por_tipo' => [],
                'por_categoria' => [],
                'por_mes' => [],
                'secuencias' => [],
            ];
        }
    }

    // =========================================================================
    // Emision DGII
    // =========================================================================

    /**
     * GUARD: la emision real a DGII solo corre si DGII_ECF_EMISSION_ENABLED=true.
     * Por defecto false para no emitir e-CF reales en produccion sin querer.
     */
    private function emissionEnabled(): bool
    {
        return filter_var($this->readEnv('DGII_ECF_EMISSION_ENABLED'), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Emite el gasto como e-CF a DGII reusando ECFEmissionService (mismo pipeline
     * de facturas: build XML -> firmar -> token -> enviar). El servicio dispensa la
     * secuencia y devuelve e_ncf/track_id/estado/codigo_seguridad/signed_xml.
     */
    private function emitirGastoDgii(string $tipoGasto, string $rncProveedor, string $nombreProveedor, array $items, array $data): array
    {
        require_once __DIR__ . '/../Utils/FacturacionElectronica/ECFEmissionService.php';
        $tipoEcf = substr($tipoGasto, 1); // 'E41' -> '41'

        // E43 (Gastos Menores) puede emitirse sin comprador; E41/E47 llevan al
        // proveedor como comprador (rnc + razon social).
        $comprador = [];
        if ($tipoGasto !== 'E43' && $rncProveedor !== '') {
            $comprador = [
                'rnc' => $rncProveedor,
                'razon_social' => $nombreProveedor,
            ];
        }

        $payload = [
            'tipo_ecf' => $tipoEcf,
            'fecha_emision' => $this->toDgiiDate($data['fecha'] ?? null),
            'comprador' => $comprador,
            'items' => $this->mapItemsForEcf($items),
            'totales' => $this->computeTotalesEcf($items),
        ];

        $service = new ECFEmissionService();
        return $service->emitir($payload);
    }

    /**
     * Lleva las lineas normalizadas al formato que espera ECFXmlBuilder.
     */
    private function mapItemsForEcf(array $items): array
    {
        $mapped = [];
        foreach ($items as $i => $it) {
            $nombre = (string) $it['description'];
            $mapped[] = [
                'numero_linea' => $i + 1,
                'indicador_facturacion' => (int) $it['indicador_facturacion'],
                'nombre_item' => $nombre !== '' ? $nombre : 'Item',
                'indicador_bien_servicio' => (int) $it['indicador_bien_servicio'],
                'descripcion' => (string) $it['description'],
                'cantidad' => (float) $it['quantity'],
                'unidad_medida' => (string) ($it['unidad_medida'] ?? '43'),
                'precio_unitario' => (float) $it['amount'],
                'monto_item' => (float) $it['subtotal'],
                'itbis_amount' => (float) $it['itbis_amount'],
            ];
        }
        return $mapped;
    }

    /**
     * Totales para el e-CF, mismo contrato que computeTotales del modulo de
     * facturas (gravado i1/i2/i3, exento, ITBIS por tasa, monto_total).
     */
    private function computeTotalesEcf(array $items): array
    {
        $i1 = 0.0; $i2 = 0.0; $i3 = 0.0; $exento = 0.0;
        $itbis1 = 0.0; $itbis2 = 0.0; $itbis3 = 0.0; $montoTotal = 0.0;
        foreach ($items as $it) {
            $base = (float) $it['subtotal'];
            $ind = (int) $it['indicador_facturacion'];
            $itbis = (float) $it['itbis_amount'];
            if ($ind === 1) {
                $i1 += $base; $itbis1 += $itbis;
            } elseif ($ind === 2) {
                $i2 += $base; $itbis2 += $itbis;
            } elseif ($ind === 3) {
                $i3 += $base;
            } else {
                $exento += $base; // 0 = No facturable | 4 = Exento
            }
            $montoTotal += $base + $itbis;
        }
        return [
            'monto_gravado_total' => round($i1 + $i2 + $i3, 2),
            'monto_gravado_i1' => round($i1, 2),
            'monto_gravado_i2' => round($i2, 2),
            'monto_gravado_i3' => round($i3, 2),
            'monto_exento' => round($exento, 2),
            'itbis1' => 18,
            'itbis2' => 16,
            'itbis3' => 0,
            'total_itbis' => round($itbis1 + $itbis2 + $itbis3, 2),
            'total_itbis1' => round($itbis1, 2),
            'total_itbis2' => round($itbis2, 2),
            'total_itbis3' => round($itbis3, 2),
            'monto_total' => round($montoTotal, 2),
        ];
    }

    private function toDgiiDate(?string $fecha): string
    {
        if ($fecha === null || $fecha === '') {
            return date('d-m-Y');
        }
        $dt = DateTime::createFromFormat('Y-m-d', substr($fecha, 0, 10));
        return $dt ? $dt->format('d-m-Y') : date('d-m-Y');
    }

    /**
     * Datos e-CF de un gasto (para consultar estado en DGII).
     */
    public function getEcfData(int $id): ?array
    {
        try {
            $sql = 'SELECT id, ncf, tipo_gasto, track_id, estado_dgii, secuencia_utilizada,
                           codigo_seguridad, fecha_emision_dgii, ambiente, respuesta_dgii
                    FROM gastos WHERE id = :id';
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch();
            return $row ?: null;
        } catch (PDOException $e) {
            error_log('[gastos] getEcfData ' . $id . ': ' . $e->getMessage());
            return null;
        }
    }

    public function updateEcfEstado(int $gastoId, string $estado, ?array $dgiiResponse = null): bool
    {
        try {
            $secuenciaUtilizada = null;
            if (is_array($dgiiResponse) && array_key_exists('secuenciaUtilizada', $dgiiResponse)) {
                $secuenciaUtilizada = (int) filter_var($dgiiResponse['secuenciaUtilizada'], FILTER_VALIDATE_BOOLEAN);
            }
            $sql = 'UPDATE gastos SET estado_dgii = :estado, respuesta_dgii = :resp,
                           secuencia_utilizada = :secuencia WHERE id = :id';
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([
                ':estado' => $estado,
                ':resp' => $dgiiResponse !== null ? json_encode($dgiiResponse) : null,
                ':secuencia' => $secuenciaUtilizada,
                ':id' => $gastoId,
            ]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log('[gastos] updateEcfEstado ' . $gastoId . ': ' . $e->getMessage());
            return false;
        }
    }

    public function getXmlFirmado(int $gastoId): ?array
    {
        try {
            $sql = 'SELECT ncf AS e_ncf, tipo_gasto, xml_firmado AS xml FROM gastos WHERE id = :id';
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([':id' => $gastoId]);
            $row = $stmt->fetch();
            if (!$row || empty($row['xml'])) {
                return null;
            }
            return $row;
        } catch (PDOException $e) {
            // Null = "no hay XML" para el controlador: sin el log, una caida de
            // la DB se leeria como un gasto que nunca se envio.
            error_log('[gastos] getXmlFirmado ' . $gastoId . ': ' . $e->getMessage());
            return null;
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Lee una variable de entorno (getenv / $_ENV / .env como respaldo).
     */
    private function readEnv(string $key): ?string
    {
        $val = getenv($key);
        if ($val === false || $val === '') {
            $val = $_ENV[$key] ?? null;
        }
        if ($val !== null && $val !== false && $val !== '') {
            return (string) $val;
        }
        $envFile = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env';
        if (is_file($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                if (trim($k) === $key) {
                    return trim($v, " '\"");
                }
            }
        }
        return null;
    }

    /**
     * Normaliza las lineas recibidas al formato de gasto_items.
     * El ITBIS, si no viene explicito, se calcula del indicador_facturacion
     * (1=18%, 2=16%, resto=0), igual que computeTotales del modulo de facturas.
     */
    private function normalizeItems(array $items): array
    {
        $normalized = [];
        foreach ($items as $raw) {
            $raw = (array) $raw;
            $quantity = (float) ($raw['quantity'] ?? $raw['cantidad'] ?? 1);
            $amount = (float) ($raw['amount'] ?? $raw['precio_unitario'] ?? 0);
            $subtotal = isset($raw['subtotal']) && $raw['subtotal'] !== ''
                ? (float) $raw['subtotal']
                : round($quantity * $amount, 2);
            $indFact = (int) ($raw['indicador_facturacion'] ?? 1);
            $indBien = (int) ($raw['indicador_bien_servicio'] ?? 2);
            if (isset($raw['itbis_amount']) && $raw['itbis_amount'] !== '') {
                $itbis = (float) $raw['itbis_amount'];
            } elseif ($indFact === 1) {
                $itbis = round($subtotal * 0.18, 2);
            } elseif ($indFact === 2) {
                $itbis = round($subtotal * 0.16, 2);
            } else {
                $itbis = 0.0;
            }
            // Vinculo con el catalogo: sin esto la compra no suma al inventario.
            $productId = isset($raw['product_id']) && is_numeric($raw['product_id']) && (int) $raw['product_id'] > 0
                ? (int) $raw['product_id']
                : null;
            $normalized[] = [
                'product_id' => $productId,
                'description' => (string) ($raw['description'] ?? $raw['descripcion'] ?? ''),
                'amount' => $amount,
                'quantity' => $quantity,
                'subtotal' => $subtotal,
                'itbis_amount' => $itbis,
                'indicador_facturacion' => $indFact,
                'indicador_bien_servicio' => $indBien,
                // Código DGII de unidad de medida (id del catálogo; 43 = Unidad).
                'unidad_medida' => (string) ($raw['unidad_medida'] ?? '43'),
            ];
        }
        return $normalized;
    }

    private function insertItems(int $gastoId, array $items): void
    {
        // product_id solo entra en el INSERT si alguna linea lo trae. Asi un
        // servidor al que aun no se le corrio la migracion 024 sigue registrando
        // gastos sin productos, en vez de fallar todos por una columna que no existe.
        $conProducto = false;
        foreach ($items as $it) {
            if (!empty($it['product_id'])) {
                $conProducto = true;
                break;
            }
        }
        $columnas = 'gasto_id, description, amount, quantity, subtotal, itbis_amount,
                     indicador_facturacion, indicador_bien_servicio, unidad_medida';
        $valores = ':gasto_id, :description, :amount, :quantity, :subtotal, :itbis_amount,
                    :indicador_facturacion, :indicador_bien_servicio, :unidad_medida';
        if ($conProducto) {
            $columnas .= ', product_id';
            $valores .= ', :product_id';
        }
        $stmt = $this->conexion->prepare("INSERT INTO gasto_items ({$columnas}) VALUES ({$valores})");
        foreach ($items as $it) {
            $params = [
                ':gasto_id' => $gastoId,
                ':description' => $it['description'],
                ':amount' => $it['amount'],
                ':quantity' => $it['quantity'],
                ':subtotal' => $it['subtotal'],
                ':itbis_amount' => $it['itbis_amount'],
                ':indicador_facturacion' => $it['indicador_facturacion'],
                ':indicador_bien_servicio' => $it['indicador_bien_servicio'],
                ':unidad_medida' => $it['unidad_medida'] ?? '43',
            ];
            if ($conProducto) {
                $params[':product_id'] = !empty($it['product_id']) ? (int) $it['product_id'] : null;
            }
            $stmt->execute($params);
        }
    }

    private function sumField(array $items, string $field): float
    {
        $sum = 0.0;
        foreach ($items as $it) {
            $sum += (float) ($it[$field] ?? 0);
        }
        return round($sum, 2);
    }
}
