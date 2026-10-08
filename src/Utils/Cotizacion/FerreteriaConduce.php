<?php
require_once __DIR__ . '/FerreteriaFormato.php';
require_once __DIR__ . '/CotizacionFormatos.php';
// conduceModel.php tambien incluye este archivo: require_once corta el ciclo,
// y ninguno de los dos usa al otro al cargarse, solo en sus metodos.
// FerreteriaFormato.php ya carga cotizacionModel, unidadMedidaModel,
// EmisorConfigModel, BrandingResolver, RequestContext y el PDF.
require_once __DIR__ . '/../../Models/conduceModel.php';

/**
 * Conduce de mercancía de Ferretería: la nota de entrega que va con la
 * mercancía y que el cliente firma ("Recibido por"). Sale de una cotización de
 * Ferretería, lleva su propio número (CON-000001) y se imprime como la
 * cotización, pero sin precios ni totales (spec 2026-10-05-conduces-design).
 *
 * Las funciones estáticas son las reglas puras: la forma del cuerpo, el
 * número, quién puede usar conduces y lo que recibe el PDF. No tocan la base de
 * datos, para que tools/test_conduces.php las pruebe por CLI. Lo que necesita
 * la DB (que la cotización, el cliente y los productos existan, guardar, el
 * PDF con el emisor, las unidades y el logo) va en los métodos de instancia,
 * que llama conduceController.php.
 *
 * Cada línea se revisa con las reglas y los textos de la cotización
 * (FerreteriaFormato::normalizarLinea), con dos diferencias: el precio
 * puede ir en 0 (el conduce no lo imprime; lo guarda para facturar, y la
 * factura no deja emitir una línea sin precio) y la cantidad y el precio no
 * pueden pasar de lo que cabe en conduce_items (LIMITE_CANTIDAD y LIMITE_PRECIO).
 */
final class FerreteriaConduce
{
    public const PREFIJO = 'CON-';
    public const MSG_NO_DISPONIBLE = 'Los conduces no están disponibles para tu empresa.';
    public const MSG_SIN_CLIENTE = 'Elige un cliente para el conduce.';
    public const MSG_SIN_LINEAS = 'Agrega al menos una línea al conduce.';
    public const MSG_FECHA = 'La fecha no es válida.';
    public const MSG_AJUSTES = 'Los conduces no llevan cargos ni abonos.';
    public const MSG_COTIZACION = 'Elige una cotización de Ferretería para crear el conduce.';
    public const MSG_NO_EXISTE = 'Este conduce ya no existe. Puede que lo hayan eliminado; vuelve al listado.';
    public const MSG_CHOQUE = 'Otro conduce se guardó al mismo tiempo. Vuelve a guardar.';
    public const MSG_PRODUCTO_FK = 'Un producto del conduce ya no existe en el catálogo (lo eliminaron mientras lo editabas). Búscalo de nuevo o quita la línea.';
    public const MSG_COTIZACION_FK = 'La cotización de origen ya no existe; vuelve a Cotizaciones.';
    /** 422 de respaldo de conduceModel: una cantidad o un precio que MySQL no aceptó (1264). validarForma lo dice antes, con la línea. */
    public const MSG_FUERA_DE_RANGO = 'Una cantidad o un precio del conduce es demasiado grande. Revisa las líneas e inténtalo de nuevo.';

    /**
     * Lo que cabe en conduce_items (031): quantity DECIMAL(12,3) guarda 9 cifras
     * enteras y amount DECIMAL(18,4) guarda 14, así que un valor igual o mayor
     * a estos topes (10^9 y 10^14, exclusivos) no entra: MySQL lo rechaza (1264)
     * y el usuario veía un error genérico. Con los decimales que admite
     * normalizarLinea (2 en la cantidad), lo más grande que entra de cantidad
     * es 999999999.99. Un float no distingue 99999999999999.9999 de 10^14:
     * ese ya cuenta como el tope y se rechaza; el precio más alto que
     * entra es 99999999999999.98. PDO manda el float a MySQL con 14 cifras
     * (ini precision), y un precio de 99999999999999.5 en adelante llega
     * como "1.0E+14": pasa este tope y MySQL lo rechaza; ese caso lo atrapa
     * conduceModel::errorAlGuardar con MSG_FUERA_DE_RANGO.
     */
    public const LIMITE_CANTIDAD = 1e9;
    public const LIMITE_PRECIO = 1e14;
    /** Va como el resto de los problemas de cantidad: normalizarLinea le pone "Línea N: " y baja la primera letra. */
    private const PROBLEMA_CANTIDAD_GRANDE = 'La cantidad es demasiado grande.';
    private const PRECIO_GRANDE = 'el precio es demasiado grande.';

    /** 500 al revisar: la DB no contestó por la cotización, el cliente o los productos. */
    private const MSG_REVISAR = 'No se pudo revisar el conduce. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.';
    private const MSG_PDF = 'No se pudo generar el PDF del conduce. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.';

    /** Los conduces de la petición (la DB del tenant). */
    private conduceModel $modelo;
    /** El de cotizaciones, solo para leer: getCliente y getProductosInfo. */
    private cotizacionModel $cotizaciones;

    public function __construct(conduceModel $modelo, cotizacionModel $cotizaciones)
    {
        $this->modelo = $modelo;
        $this->cotizaciones = $cotizaciones;
    }

    /**
     * null si la empresa de la petición puede usar conduces; si no, el mensaje
     * del 422. Solo el formato ferreteria los tiene: Gratex, un formato
     * desconocido y una instalación de un solo tenant (sin tenant resuelto)
     * dan gratex en CotizacionFormatos::delTenant(), que ya avisa en el
     * error_log de un formato desconocido.
     */
    public static function errorDisponibilidad(): ?string
    {
        return CotizacionFormatos::delTenant() === FerreteriaFormato::NOMBRE ? null : self::MSG_NO_DISPONIBLE;
    }

    /** 'CON-000001': el número de conduce_secuencia, a 6 cifras (uno más largo no se corta). */
    public static function codigo(int $numero): string
    {
        return self::PREFIJO . str_pad((string) $numero, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Forma del cuerpo de crear, actualizar y vista previa, sin DB. Que el
     * cliente, la cotización y los productos existan se revisa aparte, contra
     * la DB.
     *
     * Las reglas de unidades vienen inyectadas, como en
     * FerreteriaFormato::validarForma: en producción son
     * unidadMedidaModel::problemaCantidad e ::isValid (fail-open).
     *
     * date: 'Y-m-d H:i:s' (solo el día lleva la hora de ahora en RD) o null si
     * no vino (al crear, ahora; al editar, la guardada). cotizacion_id solo se
     * lee al crear: un conduce nunca cambia de origen. Las claves formato y
     * tipo no se leen.
     *
     * @param callable(float $cantidad, string $unidad, int $maxDec): ?string $problemaCantidad
     * @param callable(string $unidad): bool $unidadValida
     * @return array{ok:true, cot:array{date:?string, client_id:int, cotizacion_id:?int, items:array}}|array{ok:false, error:string}
     */
    public static function validarForma(object $body, callable $problemaCantidad, callable $unidadValida, bool $esCreacion): array
    {
        // Cargos o abonos en el cuerpo: viene de otra pantalla (la de la
        // cotización). Se rechaza entero en vez de guardar el conduce sin ellos
        // en silencio; basta la clave, ni null ni {} se adivinan.
        if (property_exists($body, 'ajustes')) {
            return ['ok' => false, 'error' => self::MSG_AJUSTES];
        }
        $cotizacionId = null;
        if ($esCreacion) {
            $cotizacionId = FerreteriaFormato::leerEntero($body->cotizacion_id ?? null);
            if ($cotizacionId === null || $cotizacionId <= 0) {
                return ['ok' => false, 'error' => self::MSG_COTIZACION];
            }
        }
        $clientId = FerreteriaFormato::leerEntero($body->client_id ?? null);
        if ($clientId === null || $clientId <= 0) {
            return ['ok' => false, 'error' => self::MSG_SIN_CLIENTE];
        }
        $fecha = FerreteriaFormato::normalizarFecha($body->date ?? null);
        if ($fecha === false) {
            return ['ok' => false, 'error' => self::MSG_FECHA];
        }
        $items = $body->items ?? null;
        if (!is_array($items) || $items === []) {
            return ['ok' => false, 'error' => self::MSG_SIN_LINEAS];
        }
        // El tope de la cantidad va delante de las reglas de unidades, en el
        // mismo lugar donde normalizarLinea las aplica y con su mismo formato
        // ("Línea N: la cantidad ..."): normalizarLinea no cambia, la cotización
        // no tiene este tope.
        $cantidadConTope = static fn(float $cantidad, string $unidad, int $maxDec): ?string
            => $cantidad >= self::LIMITE_CANTIDAD ? self::PROBLEMA_CANTIDAD_GRANDE : $problemaCantidad($cantidad, $unidad, $maxDec);
        $lineas = [];
        foreach (array_values($items) as $i => $item) {
            // Las líneas se numeran desde 1, como las ve el usuario. true: el
            // precio puede ir en 0 (nunca negativo).
            $linea = FerreteriaFormato::normalizarLinea($item, $i + 1, $cantidadConTope, $unidadValida, true);
            if (is_string($linea)) {
                return ['ok' => false, 'error' => $linea];
            }
            // El precio sale del cuerpo (el catálogo solo pone bien/servicio):
            // ya es un número finito, no negativo y con hasta 4 decimales; falta
            // que quepa en conduce_items.amount.
            if ($linea['amount'] >= self::LIMITE_PRECIO) {
                return ['ok' => false, 'error' => 'Línea ' . ($i + 1) . ': ' . self::PRECIO_GRANDE];
            }
            $lineas[] = $linea;
        }
        return ['ok' => true, 'cot' => [
            'date' => $fecha,
            'client_id' => $clientId,
            'cotizacion_id' => $cotizacionId,
            'items' => $lineas,
        ]];
    }

    /**
     * Líneas en la forma del PDF en modo conduce: descripción, cantidad y el
     * NOMBRE de la unidad, ya resuelto (el renderizador es puro y no lee el
     * catálogo). Nunca el precio: el conduce no lo imprime.
     *
     * Sirve igual para las filas guardadas (DECIMAL como texto) que para las
     * líneas ya validadas de una vista previa. Una unidad que no está en
     * $nombresUnidad (inactiva, desconocida o catálogo ilegible) imprime su
     * código guardado; sin unidad es Unidad (43), como al guardar.
     *
     * @param array<int|string,string> $nombresUnidad [código DGII => descripción]
     * @return array<int,array{description:string,quantity:float,unidad:string}>
     */
    public static function itemsPdf(array $items, array $nombresUnidad): array
    {
        $out = [];
        foreach (array_values($items) as $item) {
            $item = (array) $item;
            $codigo = trim((string) ($item['unidad_medida'] ?? ''));
            if ($codigo === '') {
                $codigo = FerreteriaFormato::UNIDAD_DEFAULT;
            }
            // '43' y 43 son la misma clave de arreglo en PHP.
            $nombre = trim((string) ($nombresUnidad[$codigo] ?? ''));
            $out[] = [
                'description' => (string) ($item['description'] ?? ''),
                'quantity' => (float) ($item['quantity'] ?? 0),
                'unidad' => $nombre !== '' ? $nombre : $codigo,
            ];
        }
        return $out;
    }

    /**
     * Lo que recibe FerreteriaCotizacionPdf en modo conduce (spec 4.4):
     * 'conduce' es el primer argumento del constructor (el documento) y
     * 'cliente' el tercero. El emisor y el logo los pone quien llama.
     *
     * $row es la fila de conduceModel::obtener(), o la que arma la vista previa
     * con la misma forma. $code null = todavía sin número: el renderizador
     * imprime VISTA PREVIA. Sin fecha, ahora en RD. cotizacion_code null = la
     * cotización de origen se eliminó, y el PDF no lleva la línea Cotización.
     *
     * El cliente es el de hoy (el LEFT JOIN de clients; razon_social si la fila
     * la trae). Si lo borraron, conduces.client_id no tiene FK y el JOIN no
     * trae nada: sale el nombre guardado al crear o editar el conduce, sin RNC
     * (el renderizador no imprime un RNC vacío).
     *
     * @return array{conduce: array{documento:string, code:?string, date:string, cotizacion_code:?string, items:array},
     *               cliente: array{razon_social:?string, company_name:?string, client_name:?string, rnc:?string}}
     */
    public static function datosPdf(array $row, array $nombresUnidad, ?string $code): array
    {
        $fecha = trim((string) ($row['date'] ?? ''));
        $cotizacion = trim((string) ($row['cotizacion_code'] ?? ''));
        return [
            'conduce' => [
                'documento' => 'conduce',
                'code' => $code,
                'date' => $fecha !== '' ? $fecha : FerreteriaFormato::ahoraRd(),
                'cotizacion_code' => $cotizacion !== '' ? $cotizacion : null,
                'items' => self::itemsPdf($row['items'] ?? [], $nombresUnidad),
            ],
            'cliente' => self::clientePdf($row),
        ];
    }

    /** El cliente del PDF: el de hoy, o el nombre guardado si lo borraron (ver datosPdf). */
    private static function clientePdf(array $row): array
    {
        // clients.client_name y company_name son NOT NULL: si el JOIN encontró
        // al cliente, al menos uno no es null.
        $encontrado = ($row['client_name'] ?? null) !== null || ($row['company_name'] ?? null) !== null
            || ($row['razon_social'] ?? null) !== null;
        if ($encontrado) {
            return [
                'razon_social' => $row['razon_social'] ?? null,
                'company_name' => $row['company_name'] ?? null,
                'client_name' => $row['client_name'] ?? null,
                'rnc' => $row['rnc'] ?? null,
            ];
        }
        return [
            'razon_social' => null,
            'company_name' => null,
            'client_name' => $row['client_name_guardado'] ?? null,
            'rnc' => '',
        ];
    }

    // ------------------------------------------------------------------------
    // Reglas que dependen de lo que contesta la DB, y las piezas de
    // conduceController que leen la petición (Task 6). Puras: el CLI las prueba.
    // ------------------------------------------------------------------------

    /**
     * Reglas que dependen de la DB, ya con lo que la DB contestó, en el orden
     * de validarForma:
     *  1. al crear, la cotización de origen existe y es de Ferretería (al
     *     editar no se mira: un conduce nunca cambia de origen);
     *  2. el cliente existe, también al editar: si borraron el guardado, hay
     *     que elegir otro;
     *  3. cada producto existe y de él sale bien/servicio, con las reglas y el
     *     texto de la cotización (FerreteriaFormato::aplicarCatalogoLineas).
     *
     * @param array      $cot       'cot' de validarForma
     * @param array|null $origen    conduceModel::cotizacionDeOrigen() (null = no existe)
     * @param array|null $cliente   cotizacionModel::getCliente() (null = no existe)
     * @param array      $productos cotizacionModel::getProductosInfo()
     * @return array ['ok', array $cot] | ['error', string, 422]
     */
    public static function aplicarCatalogo(array $cot, bool $esCreacion, ?array $origen, ?array $cliente, array $productos): array
    {
        if ($esCreacion && ($origen['formato'] ?? null) !== FerreteriaFormato::NOMBRE) {
            return ['error', self::MSG_COTIZACION, 422];
        }
        if ($cliente === null) {
            return ['error', self::MSG_SIN_CLIENTE, 422];
        }
        $items = FerreteriaFormato::aplicarCatalogoLineas($cot['items'], $productos);
        if (is_string($items)) {
            return ['error', $items, 422];
        }
        $cot['items'] = $items;
        return ['ok', $cot];
    }

    /**
     * La fila que la vista previa le pasa a datosPdf, con la forma de
     * conduceModel::obtener(): lo que se guardaría, sin guardar nada.
     *  - date: la del cuerpo; si no viene, la guardada (un PUT sin fecha la
     *    conserva); si tampoco hay, null y datosPdf pone la de ahora.
     *  - cotizacion_code: con fila, el de la fila (el cuerpo no cambia el
     *    origen); sin fila, el de la cotización que se revisó al validar.
     *  - el cliente: el elegido (getCliente, que existe), y como nombre guardado
     *    el que se guardaría.
     */
    public static function filaPreview(array $cot, array $cliente, ?array $row, ?array $origen): array
    {
        return [
            'date' => $cot['date'] ?? ($row['date'] ?? null),
            'cotizacion_code' => $row !== null ? ($row['cotizacion_code'] ?? null) : ($origen['code'] ?? null),
            'razon_social' => $cliente['razon_social'] ?? null,
            'company_name' => $cliente['company_name'] ?? null,
            'client_name' => $cliente['client_name'] ?? null,
            'rnc' => $cliente['rnc'] ?? null,
            'client_name_guardado' => FerreteriaFormato::nombreCliente($cliente),
            'items' => $cot['items'],
        ];
    }

    /**
     * La fila de obtener() para el PDF, con el cliente de hoy cuando getCliente
     * lo encontró: trae razon_social, que el JOIN no lee, y así el conduce
     * imprime el mismo nombre que la cotización. Sin cliente (lo borraron, o la
     * lectura falló) la fila queda como está: el JOIN, o el nombre guardado.
     */
    public static function filaPdf(array $row, ?array $cliente): array
    {
        if ($cliente === null) {
            return $row;
        }
        foreach (['razon_social', 'company_name', 'client_name', 'rnc'] as $campo) {
            $row[$campo] = $cliente[$campo] ?? null;
        }
        return $row;
    }

    /**
     * Qué pide la petición, para conduceController (spec 4.1). Las sub-rutas se
     * leen aquí, como cotizacionController lee las suyas:
     *   GET    /api/conduces/{id}/pdf  'pdf' (id null si no es un entero > 0)
     *   GET    /api/conduces           'leer' (?id= o el listado)
     *   POST   /api/conduces/preview   'preview'
     *   POST   /api/conduces           'crear'
     *   PUT    /api/conduces           'actualizar'
     *   DELETE /api/conduces           'eliminar'
     * Cualquier otra combinación es 'no_existe' (404): un POST a
     * /api/conduces/7/pdf no puede crear un conduce.
     *
     * @return array{accion:string, id:?int}
     */
    public static function ruta(string $metodo, string $path): array
    {
        $path = rtrim($path, '/');
        $id = null;
        if (preg_match('#/api/conduces/(\d+)/pdf$#', $path, $m) === 1) {
            $sub = 'pdf';
            $id = self::idDe($m[1]);
        } elseif (preg_match('#/api/conduces/preview$#', $path) === 1) {
            $sub = 'preview';
        } elseif (preg_match('#/api/conduces$#', $path) === 1) {
            $sub = '';
        } else {
            $sub = null;
        }
        $accion = match ([$metodo, $sub]) {
            ['GET', 'pdf'] => 'pdf',
            ['GET', ''] => 'leer',
            ['POST', 'preview'] => 'preview',
            ['POST', ''] => 'crear',
            ['PUT', ''] => 'actualizar',
            ['DELETE', ''] => 'eliminar',
            default => 'no_existe',
        };
        return ['accion' => $accion, 'id' => $accion === 'pdf' ? $id : null];
    }

    /** Un id de conduce del cuerpo o de la URL (7, "7", 7.0): un entero mayor que 0, o null. */
    public static function idDe(mixed $v): ?int
    {
        $id = FerreteriaFormato::leerEntero($v);
        return $id !== null && $id > 0 ? $id : null;
    }

    /**
     * page, pageSize y query del listado, como cotizacionController: page y
     * pageSize numéricos (1 y 10 si no), query de texto o null. Lo que como
     * entero no llega a 1 (0, negativos, 0.5) también cae en el default: un
     * OFFSET negativo rompería la consulta y un pageSize 0 la cuenta de páginas.
     *
     * @return array{page:int, pageSize:int, query:?string, offset:int}
     */
    public static function paginacion(array $get): array
    {
        $entero = static function (mixed $v, int $porDefecto): int {
            $n = is_numeric($v) ? (int) $v : 0;
            return $n > 0 ? $n : $porDefecto;
        };
        $page = $entero($get['page'] ?? null, 1);
        $pageSize = $entero($get['pageSize'] ?? null, 10);
        $query = isset($get['query']) && is_string($get['query']) ? $get['query'] : null;
        return ['page' => $page, 'pageSize' => $pageSize, 'query' => $query, 'offset' => ($page - 1) * $pageSize];
    }

    // ------------------------------------------------------------------------
    // Lo que toca la DB (Task 6): crear, actualizar, eliminar, vista previa y
    // PDF, para conduceController. La misma forma de respuesta que los
    // formatos de cotización: ['success', $data] | ['error', string, int $http].
    // ------------------------------------------------------------------------

    /** POST /api/conduces: revisar, numerar y guardar. */
    public function crear(object $body): array
    {
        $datos = $this->prepararDatos($body, true);
        if ($datos[0] !== 'ok') {
            return $datos;
        }
        [, $cot, $cliente] = $datos;
        // user_id sale del token, nunca del cuerpo: un cuerpo puede traer
        // cualquier id. Sin fecha, el modelo pone la de ahora.
        return $this->modelo->crear($cot, RequestContext::userId(), FerreteriaFormato::nombreCliente($cliente));
    }

    /**
     * PUT /api/conduces. $row es obtener() del id del cuerpo, o [] si ya no
     * existe o está eliminado: entonces 404 antes de validar ni leer la DB. El
     * número, el código y la cotización de origen no cambian nunca.
     */
    public function actualizar(array $row, object $body): array
    {
        if (empty($row['id'])) {
            return ['error', self::MSG_NO_EXISTE, 404];
        }
        $datos = $this->prepararDatos($body, false);
        if ($datos[0] !== 'ok') {
            return $datos;
        }
        [, $cot, $cliente] = $datos;
        return $this->modelo->actualizar((int) $row['id'], $cot, RequestContext::userId(), FerreteriaFormato::nombreCliente($cliente));
    }

    /** DELETE /api/conduces: activo = 0, nunca un DELETE. */
    public function eliminar(int $id): array
    {
        return $this->modelo->desactivar($id);
    }

    /**
     * POST /api/conduces/preview: el PDF de lo que se guardaría, sin guardar.
     *  - Con id en el cuerpo, el de ese conduce: $row tiene que ser el conduce
     *    activo (si no, 404); imprime su número y su cotización, y el
     *    cotizacion_id del cuerpo se ignora, como en el PUT.
     *  - Sin id, el de uno nuevo: la cotización del cuerpo se revisa como en el
     *    POST, y el PDF dice VISTA PREVIA.
     */
    public function preview(object $body, ?array $row): array
    {
        $conId = isset($body->id);
        if ($conId && empty($row['id'])) {
            return ['error', self::MSG_NO_EXISTE, 404];
        }
        $datos = $this->prepararDatos($body, !$conId);
        if ($datos[0] !== 'ok') {
            return $datos;
        }
        [, $cot, $cliente, $origen] = $datos;
        return $this->renderizar(
            self::filaPreview($cot, $cliente, $conId ? $row : null, $origen),
            $conId ? (string) $row['code'] : null
        );
    }

    /** GET /api/conduces/{id}/pdf. $row: obtener(), con sus líneas activas. */
    public function pdf(array $row): array
    {
        $cliente = null;
        if (!empty($row['client_id'])) {
            try {
                $cliente = $this->cotizaciones->getCliente((int) $row['client_id']);
            } catch (Throwable $e) {
                // El PDF sale igual, con lo que trajo el JOIN de obtener().
                error_log('[conduces] cliente del PDF del conduce ' . ($row['id'] ?? '?') . ': ' . $e->getMessage());
            }
        }
        return $this->renderizar(self::filaPdf($row, $cliente), (string) ($row['code'] ?? ''));
    }

    /**
     * Lo común de crear, actualizar y vista previa: la forma del cuerpo
     * (validarForma, sin DB), lo que solo sabe la DB (la cotización de origen
     * al crear, el cliente y los productos) y las reglas que dependen de eso
     * (aplicarCatalogo).
     *
     * Las unidades son el catálogo de master: problemaCantidad e isValid son
     * fail-open, una lectura fallida del catálogo no bloquea el conduce.
     *
     * @return array ['ok', array $cot, array $cliente, ?array $origen] | ['error', string, int]
     */
    private function prepararDatos(object $body, bool $esCreacion): array
    {
        try {
            $unidades = new unidadMedidaModel();
            $forma = self::validarForma($body, [$unidades, 'problemaCantidad'], [$unidades, 'isValid'], $esCreacion);
            if (!$forma['ok']) {
                return ['error', $forma['error'], 422];
            }
            $cot = $forma['cot'];
            $origen = $esCreacion ? $this->modelo->cotizacionDeOrigen((int) $cot['cotizacion_id']) : null;
            $cliente = $this->cotizaciones->getCliente($cot['client_id']);
            $productos = $this->cotizaciones->getProductosInfo(array_column($cot['items'], 'product_id'));
        } catch (Throwable $e) {
            // Las lecturas no atrapan a propósito: una DB caída no puede
            // contestarse "elige un cliente" ni "elige una cotización".
            error_log('[conduces] no se pudo revisar el conduce contra la DB: ' . $e->getMessage());
            return ['error', self::MSG_REVISAR, 500];
        }
        $r = self::aplicarCatalogo($cot, $esCreacion, $origen, $cliente, $productos);
        if ($r[0] !== 'ok') {
            return $r;
        }
        return ['ok', $r[1], $cliente, $origen];
    }

    /**
     * El PDF con los datos del tenant. El renderizador es puro; aquí se junta
     * lo que vive en master (los nombres de las unidades y el logo) y en la DB
     * del tenant (emisor_config).
     *
     * @return array ['success', string $pdf] | ['error', string, int]
     */
    private function renderizar(array $fila, ?string $code): array
    {
        try {
            $datos = self::datosPdf($fila, self::nombresUnidad(), $code);
            $emisor = (new EmisorConfigModel())->get() ?? [];
            $pdf = new FerreteriaCotizacionPdf($datos['conduce'], $emisor, $datos['cliente'], BrandingResolver::logoPath());
            return ['success', $pdf->render()];
        } catch (Throwable $e) {
            error_log('[conduces] no se pudo generar el PDF del conduce (' . ($code ?? 'vista previa') . '): ' . $e->getMessage());
            return ['error', self::MSG_PDF, 500];
        }
    }

    /**
     * [código DGII => descripción] de las unidades activas, leído UNA vez por
     * PDF (descripcion() por línea relee el catálogo cada vez). Si master no se
     * puede leer, []: cada línea imprime su código y el PDF sale igual.
     */
    private static function nombresUnidad(): array
    {
        try {
            return array_column((new unidadMedidaModel())->all(), 'descripcion', 'id');
        } catch (Throwable $e) {
            error_log('[conduces] catalogo de unidades ilegible, el PDF imprime los codigos: ' . $e->getMessage());
            return [];
        }
    }
}
