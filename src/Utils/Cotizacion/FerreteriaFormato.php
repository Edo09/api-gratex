<?php
require_once __DIR__ . '/Redondeo.php';
require_once __DIR__ . '/../../Models/unidadMedidaModel.php';
require_once __DIR__ . '/CotizacionFormato.php';
// FerreteriaCotizacionPdf.php tambien incluye este archivo: require_once corta
// el ciclo, y ninguno de los dos usa al otro al cargarse, solo en sus metodos.
require_once __DIR__ . '/FerreteriaCotizacionPdf.php';
require_once __DIR__ . '/../Pdf/BrandingResolver.php';
require_once __DIR__ . '/../../Models/cotizacionModel.php';
require_once __DIR__ . '/../../Models/EmisorConfigModel.php';
require_once __DIR__ . '/../../RequestContext.php';

/**
 * Formato de cotización de Ferretería (FERREHERRAMIENTAS VENTURA, SRL): su
 * hoja de Excel "COTIZACION MERCANCIAS", con líneas del catálogo, cargos sin
 * ITBIS, retención y abono.
 *
 * Las funciones estáticas son las reglas puras: la forma del cuerpo, los
 * totales, el número y los textos del PDF. No tocan la base de datos, para que
 * tools/test_cotizacion_ferreteria.php las pruebe por CLI. Lo que necesita la
 * DB (que el cliente y los productos existan, guardar, el PDF) va en los
 * métodos de instancia.
 *
 * Todo monto se redondea con Redondeo (la copia de montosLinea.redondear), no
 * con round(): la pantalla calcula lo mismo con totalesFerreteria() (fiscalo
 * src/features/cotizaciones/formatos/ferreteria/totales.ts) y tiene que dar
 * igual al centavo. Un cambio de regla va en los dos lados en el mismo cambio.
 */
final class FerreteriaFormato extends CotizacionFormato
{
    public const NOMBRE = 'ferreteria';
    /** Ajustes con monto que acepta este formato (cotizacion_ajustes.concepto). */
    public const AJUSTES_MONTO = ['cargos_bancarios', 'manejo_bancario', 'mano_obra', 'abono'];
    /** La retención llega como casilla (bool) y se guarda como monto. */
    public const RETENCION = 'retencion_isr';
    /** Retención Renta por Tercero: 5% del Sub-total, el monto antes del ITBIS. */
    public const TASA_RETENCION = 0.05;
    /** Tope de la descripción de una línea: así una fila siempre cabe en una página del PDF. */
    public const MAX_DESCRIPCION = 1000;
    /** Código DGII de "Unidad" (id del catálogo de master): el de una línea sin unidad. */
    public const UNIDAD_DEFAULT = '43';

    /** Nombre de cada ajuste en la pantalla y el PDF, para los mensajes. */
    private const ETIQUETAS_AJUSTE = [
        'cargos_bancarios' => 'Cargos bancarios',
        'manejo_bancario' => 'Manejos de operaciones bancarias',
        'mano_obra' => 'Costo mano de obra',
        'abono' => 'Abono realizado',
    ];

    private const MESES = [
        'ENERO', 'FEBRERO', 'MARZO', 'ABRIL', 'MAYO', 'JUNIO',
        'JULIO', 'AGOSTO', 'SEPTIEMBRE', 'OCTUBRE', 'NOVIEMBRE', 'DICIEMBRE',
    ];

    /** El modelo de cotizaciones de la peticion: lo pasa CotizacionFormatos::para(). */
    private cotizacionModel $modelo;

    public function __construct(cotizacionModel $modelo)
    {
        $this->modelo = $modelo;
    }

    public function nombre(): string
    {
        return self::NOMBRE;
    }

    /** Tasa de ITBIS según indicador_facturacion: 1 = 18%, 2 = 16%, 3 y 4 = 0%. Igual que itbisRate del front. */
    public static function tasa(int $indicador): float
    {
        return $indicador === 1 ? 0.18 : ($indicador === 2 ? 0.16 : 0.0);
    }

    /**
     * Totales de la cotización (spec 6.2), con las reglas de línea del e-CF:
     * base = r2(r2(cantidad) × r4(precio sin ITBIS)) e ITBIS por línea sobre
     * esa base. Sin descuento: el fijo del cliente lo aplica la factura al
     * convertir, no la cotización.
     *
     * Las sumas se redondean al final, como totalesDocumento del front. Los
     * cargos y la mano de obra van después del ITBIS y no lo llevan; la
     * retención y el abono no cambian el TOTAL, solo lo que queda por pagar.
     *
     * @param array<int,array{quantity:float,amount:float,indicador_facturacion:int}> $lineas
     * @param array{cargos_bancarios:float,manejo_bancario:float,mano_obra:float,abono:float,retencion_isr:bool} $ajustes
     */
    public static function totales(array $lineas, array $ajustes): array
    {
        $porLinea = [];
        $sumaBase = 0.0;
        $sumaItbis = 0.0;
        $gravadas = 0;
        $todasAl18 = true;
        foreach (array_values($lineas) as $l) {
            $indicador = (int) ($l['indicador_facturacion'] ?? 1);
            $base = Redondeo::r2(Redondeo::r2((float) $l['quantity']) * Redondeo::r4((float) $l['amount']));
            $itbis = Redondeo::r2($base * self::tasa($indicador));
            $porLinea[] = ['base' => $base, 'itbis' => $itbis];
            $sumaBase += $base;
            $sumaItbis += $itbis;
            if ($itbis > 0) {
                $gravadas++;
                $todasAl18 = $todasAl18 && $indicador === 1;
            }
        }
        $subtotal = Redondeo::r2($sumaBase);
        $itbis = Redondeo::r2($sumaItbis);
        $cargos = Redondeo::r2((float) ($ajustes['cargos_bancarios'] ?? 0));
        $manejo = Redondeo::r2((float) ($ajustes['manejo_bancario'] ?? 0));
        $manoObra = Redondeo::r2((float) ($ajustes['mano_obra'] ?? 0));
        $total = Redondeo::r2($subtotal + $itbis + $cargos + $manejo + $manoObra);
        $retencion = !empty($ajustes[self::RETENCION]) ? Redondeo::r2($subtotal * self::TASA_RETENCION) : 0.0;
        $adeudado = Redondeo::r2($total - $retencion);
        $abono = Redondeo::r2((float) ($ajustes['abono'] ?? 0));
        return [
            'lineas' => $porLinea,
            'subtotal' => $subtotal,
            'itbis' => $itbis,
            'cargos_bancarios' => $cargos,
            'manejo_bancario' => $manejo,
            'mano_obra' => $manoObra,
            'total' => $total,
            'retencion_isr' => $retencion,
            'adeudado' => $adeudado,
            'abono' => $abono,
            'restante' => Redondeo::r2($adeudado - $abono),
            // "ITBIS 18%" solo si todo lo que lleva ITBIS va al 18%: con una
            // línea al 16%, o sin nada gravado, el rótulo no puede prometer una tasa.
            'etiqueta_itbis' => $gravadas > 0 && $todasAl18 ? 'ITBIS 18%' : 'ITBIS',
            // Restante (Adeudado) solo dice algo distinto del TOTAL si hubo
            // retención o abono; si no, la hoja lo deja en blanco.
            'mostrar_restante' => $retencion > 0 || $abono > 0,
        ];
    }

    /**
     * El abono no puede pasar de lo adeudado (TOTAL − retención). Se comparan
     * los montos ya redondeados de totales(): 15.48 contra 16.17 − 0.69 cuadra
     * justo y no da error por un resto binario.
     *
     * @return string|null el mensaje para el usuario, o null si está bien
     */
    public static function errorAbono(array $totales): ?string
    {
        $abono = (float) ($totales['abono'] ?? 0);
        $adeudado = (float) ($totales['adeudado'] ?? 0);
        if ($abono <= $adeudado) {
            return null;
        }
        return 'El abono (RD$ ' . number_format($abono, 2) . ') no puede ser mayor que lo adeudado (RD$ '
            . number_format($adeudado, 2) . ').';
    }

    /**
     * Forma y rangos del cuerpo de crear, actualizar y vista previa, sin DB,
     * con sus defaults ya puestos. Que el cliente y los productos existan se
     * revisa aparte, contra la DB.
     *
     * Las reglas de unidades vienen inyectadas para que el CLI las pruebe sin
     * el catálogo de master; en producción son unidadMedidaModel::problemaCantidad
     * e ::isValid (fail-open: un catálogo ilegible no bloquea la cotización).
     *
     * @param callable(float $cantidad, string $unidad, int $maxDec): ?string $problemaCantidad
     * @param callable(string $unidad): bool $unidadValida
     * @return array{ok:true, cot:array}|array{ok:false, error:string}
     */
    public static function validarForma(object $body, callable $problemaCantidad, callable $unidadValida): array
    {
        $clientId = self::leerEntero($body->client_id ?? null);
        if ($clientId === null || $clientId <= 0) {
            return ['ok' => false, 'error' => 'Elige un cliente para la cotización.'];
        }
        $fecha = self::normalizarFecha($body->date ?? null);
        if ($fecha === false) {
            return ['ok' => false, 'error' => 'La fecha no es válida.'];
        }
        $items = $body->items ?? null;
        if (!is_array($items) || $items === []) {
            return ['ok' => false, 'error' => 'Agrega al menos una línea a la cotización.'];
        }
        $lineas = [];
        foreach (array_values($items) as $i => $item) {
            // Las líneas se numeran desde 1, como las ve el usuario en el formulario.
            $linea = self::normalizarLinea($item, $i + 1, $problemaCantidad, $unidadValida);
            if (is_string($linea)) {
                return ['ok' => false, 'error' => $linea];
            }
            $lineas[] = $linea;
        }
        $ajustes = self::normalizarAjustes($body->ajustes ?? null);
        if (is_string($ajustes)) {
            return ['ok' => false, 'error' => $ajustes];
        }
        return ['ok' => true, 'cot' => [
            'date' => $fecha,
            'client_id' => $clientId,
            'items' => $lineas,
            'ajustes' => $ajustes,
        ]];
    }

    /** 'COT-000123': el número de la secuencia del tenant, a 6 cifras. */
    public static function codigo(int $numero): string
    {
        return 'COT-' . str_pad((string) $numero, 6, '0', STR_PAD_LEFT);
    }

    /**
     * RNC (9 dígitos: 401-51513-1) o cédula (11: 001-1234567-8) con guiones,
     * contando solo los dígitos. Cualquier otra cosa (pasaporte, vacío) se
     * imprime tal como está guardada.
     */
    public static function formatearRnc(?string $rnc): string
    {
        $tal = trim((string) $rnc);
        $d = (string) preg_replace('/\D/', '', $tal);
        if (strlen($d) === 9) {
            return substr($d, 0, 3) . '-' . substr($d, 3, 5) . '-' . substr($d, 8, 1);
        }
        if (strlen($d) === 11) {
            return substr($d, 0, 3) . '-' . substr($d, 3, 7) . '-' . substr($d, 10, 1);
        }
        return $tal;
    }

    /**
     * Descripcion de una linea lista para guardar e imprimir: cada corrida de
     * caracteres de control (saltos de linea, tabuladores...) o de separadores
     * Unicode de linea/parrafo pasa a UN espacio, y se recortan los extremos.
     * El formulario ya junta los saltos, pero la API acepta lo que le manden, y
     * en el PDF un "\n" es un renglon nuevo dentro de la celda: una descripcion
     * llena de saltos estiraba la fila hasta salirse de la pagina. Los espacios
     * normales se respetan: en sus hojas hay descripciones como 'T  3"'.
     */
    public static function limpiarDescripcion(string $s): string
    {
        $limpia = preg_replace('/[\p{Cc}\x{2028}\x{2029}]+/u', ' ', $s);
        // preg_replace devuelve null con UTF-8 invalido: en ese caso se quitan los
        // controles ASCII byte a byte (la conversion a ISO-8859-1 del PDF ya
        // cambia lo demas por '?').
        if ($limpia === null) {
            $limpia = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $s) ?? $s;
        }
        return trim($limpia);
    }

    /**
     * La fecha como la escribe la hoja: 'SEPTIEMBRE 2/2026.-'. Vacía, en ceros
     * o ilegible se usa hoy en hora de RD (la app no fija zona horaria), nunca
     * el 31/12/1969 de un strtotime fallido.
     */
    public static function fechaLarga(string $fecha): string
    {
        $ymd = substr(trim($fecha), 0, 10);
        $dia = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);
        if ($dia === false || $dia->format('Y-m-d') !== $ymd) {
            $dia = new DateTimeImmutable('now', new DateTimeZone('America/Santo_Domingo'));
        }
        return self::MESES[(int) $dia->format('n') - 1] . ' ' . $dia->format('j') . '/' . $dia->format('Y') . '.-';
    }

    // ------------------------------------------------------------------------
    // Contrato de CotizacionFormato: crear, actualizar, vista previa y PDF.
    // Tocan la DB (cliente, productos, guardar, emisor); las reglas que
    // dependen de lo que la DB contesta van en las funciones puras de abajo
    // (aplicarCatalogo, nombreCliente, lineasDesdeFilas, ajustesDesdeFilas,
    // itemsPdf), que el CLI prueba sin base de datos.
    // ------------------------------------------------------------------------

    public function crear(object $body): array
    {
        $datos = $this->prepararDatos($body);
        if ($datos[0] !== 'ok') {
            return $datos;
        }
        [, $cot, $tot, $cliente] = $datos;
        // user_id sale del token, nunca del cuerpo: un cuerpo puede traer
        // cualquier id. sent_email y total del cuerpo se ignoran (spec 6.5).
        // Sin fecha, el modelo pone la de ahora.
        $r = $this->modelo->crearConFormato($cot, $tot, self::NOMBRE, RequestContext::userId(), self::nombreCliente($cliente));
        return $r[0] === 'success' ? $r : ['error', $r[1], $r[2] ?? 500];
    }

    public function actualizar(array $row, object $body): array
    {
        // Sin fila: la borraron antes del PUT. El controller resuelve entonces
        // el formato del cuerpo (CotizacionFormatos::deLaFila), asi que este
        // "ya no existe" es el que recibe la pantalla de Ferreteria. Antes de
        // validar ni leer la DB: no hay nada que guardar.
        if (empty($row['id'])) {
            return ['error', 'Esta cotización ya no existe. Puede que la hayan eliminado; vuelve al listado.', 404];
        }
        $datos = $this->prepararDatos($body);
        if ($datos[0] !== 'ok') {
            return $datos;
        }
        [, $cot, $tot, $cliente] = $datos;
        // numero y code no cambian nunca; date null conserva la guardada.
        $r = $this->modelo->actualizarConFormato((int) $row['id'], $cot, $tot, RequestContext::userId(), self::nombreCliente($cliente));
        return $r[0] === 'success' ? $r : ['error', $r[1], $r[2] ?? 500];
    }

    public function preview(object $body, ?array $row): array
    {
        $datos = $this->prepararDatos($body);
        if ($datos[0] !== 'ok') {
            return $datos;
        }
        [, $cot, $tot, $cliente] = $datos;
        return $this->renderizar([
            // Sin id todavia no hay numero: el PDF imprime VISTA PREVIA.
            'code' => $row !== null ? (string) $row['code'] : null,
            // La fecha que se guardaria: la del cuerpo; si no viene, la
            // guardada (un PUT sin fecha la conserva); si no hay, hoy.
            'date' => $cot['date'] ?? (string) ($row['date'] ?? self::ahoraRd()),
            'items' => self::itemsPdf($cot['items']),
            'totales' => $tot,
        ], $cliente);
    }

    public function pdf(array $cotizacion): array
    {
        // Los totales se recalculan desde lo guardado con las reglas del
        // guardado: las lineas y los ajustes son la fuente, y la retencion
        // sale otra vez del Sub-total, que es el mismo que se guardo.
        $tot = self::totales(
            self::lineasDesdeFilas($cotizacion['items'] ?? []),
            self::ajustesDesdeFilas($cotizacion['ajustes'] ?? [])
        );
        $cliente = null;
        if (!empty($cotizacion['client_id'])) {
            try {
                $cliente = $this->modelo->getCliente((int) $cotizacion['client_id']);
            } catch (Throwable $e) {
                error_log('[cotizaciones] cliente del PDF de la cotizacion ' . ($cotizacion['id'] ?? '?') . ': ' . $e->getMessage());
            }
        }
        // Cliente borrado (cotizaciones.client_id no tiene FK) o lectura
        // fallida: lo que trajo el JOIN de getCotizaciones, y el PDF sale igual.
        $cliente ??= [
            'razon_social' => null,
            'company_name' => $cotizacion['company_name'] ?? null,
            'client_name' => $cotizacion['client_name'] ?? null,
            'rnc' => $cotizacion['rnc'] ?? null,
        ];
        return $this->renderizar([
            'code' => (string) ($cotizacion['code'] ?? ''),
            'date' => (string) ($cotizacion['date'] ?? self::ahoraRd()),
            'items' => self::itemsPdf($cotizacion['items'] ?? []),
            'totales' => $tot,
        ], $cliente);
    }

    /**
     * Lo comun de crear, actualizar y vista previa: la forma del cuerpo
     * (validarForma, sin DB), lo que solo sabe la DB (el cliente y los
     * productos) y las reglas que dependen de eso (aplicarCatalogo).
     *
     * Las unidades son el catalogo de master: problemaCantidad e isValid son
     * fail-open, una lectura fallida del catalogo no bloquea la cotizacion.
     *
     * @return array ['ok', array $cot, array $tot, array $cliente] | ['error', string, int]
     */
    private function prepararDatos(object $body): array
    {
        try {
            $unidades = new unidadMedidaModel();
            $forma = self::validarForma($body, [$unidades, 'problemaCantidad'], [$unidades, 'isValid']);
            if (!$forma['ok']) {
                return ['error', $forma['error'], 422];
            }
            $cot = $forma['cot'];
            $cliente = $this->modelo->getCliente($cot['client_id']);
            $productos = $this->modelo->getProductosInfo(array_column($cot['items'], 'product_id'));
        } catch (Throwable $e) {
            // getCliente y getProductosInfo no atrapan a proposito: una DB
            // caida no puede contestarse "el cliente no existe".
            error_log('[cotizaciones] ferreteria: no se pudo revisar la cotizacion contra la DB: ' . $e->getMessage());
            return ['error', 'No se pudo revisar la cotización. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.', 500];
        }
        $r = self::aplicarCatalogo($cot, $cliente, $productos);
        if ($r[0] !== 'ok') {
            return $r;
        }
        return ['ok', $r[1], $r[2], $cliente];
    }

    /**
     * El PDF con los datos del tenant. El renderizador es puro; aqui se junta
     * lo que vive en la DB del tenant (emisor_config) y en master (el logo).
     *
     * @return array ['success', string $pdf] | ['error', string, int]
     */
    private function renderizar(array $cotizacion, array $cliente): array
    {
        try {
            $emisor = (new EmisorConfigModel())->get() ?? [];
            $pdf = new FerreteriaCotizacionPdf($cotizacion, $emisor, $cliente, BrandingResolver::logoPath());
            return ['success', $pdf->render()];
        } catch (Throwable $e) {
            error_log('[cotizaciones] no se pudo generar el PDF de Ferreteria (' . ($cotizacion['code'] ?? 'vista previa') . '): ' . $e->getMessage());
            return ['error', 'No se pudo generar el PDF de la cotización. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.', 500];
        }
    }

    /**
     * Reglas que dependen de la DB, ya con lo que la DB contesto: el cliente
     * existe, cada producto existe y de el sale bien/servicio; luego los
     * totales y el tope del abono. Pura: el CLI la prueba sin base de datos.
     *
     * @param array      $cot       'cot' de validarForma
     * @param array|null $cliente   getCliente() (null = no existe)
     * @param array      $productos getProductosInfo()
     * @return array ['ok', array $cot, array $tot] | ['error', string, int]
     */
    public static function aplicarCatalogo(array $cot, ?array $cliente, array $productos): array
    {
        if ($cliente === null) {
            return ['error', 'Elige un cliente para la cotización.', 422];
        }
        $cot['items'] = array_values($cot['items']);
        foreach ($cot['items'] as $i => $item) {
            if (empty($item['product_id'])) {
                continue;
            }
            $info = $productos[(int) $item['product_id']] ?? null;
            if ($info === null) {
                return ['error', 'Línea ' . ($i + 1) . ': el producto ya no existe en el catálogo. Búscalo de nuevo o déjala como línea libre.', 422];
            }
            // Bien o servicio lo dice el catalogo, no la pantalla: es lo que
            // la factura copiara al convertir (y lo que decide si mueve inventario).
            $cot['items'][$i]['indicador_bien_servicio'] = (int) $info['indicador_bien_servicio'];
        }
        $tot = self::totales(self::lineasDesdeFilas($cot['items']), $cot['ajustes']);
        $error = self::errorAbono($tot);
        if ($error !== null) {
            return ['error', $error, 422];
        }
        return ['ok', $cot, $tot];
    }

    /**
     * Nombre que se guarda en cotizaciones.client_name: el mismo orden que el
     * PDF (razon_social, si no company_name, si no client_name). Recortado a
     * 100, el ancho de la columna: razon_social admite 150 y en modo estricto
     * MySQL rechazaria la fila entera por un nombre largo.
     */
    public static function nombreCliente(array $cliente): string
    {
        foreach (['razon_social', 'company_name', 'client_name'] as $campo) {
            $v = trim((string) ($cliente[$campo] ?? ''));
            if ($v !== '') {
                return mb_substr($v, 0, 100);
            }
        }
        return '';
    }

    /**
     * Lineas para totales() desde las filas guardadas (cotizacion_items, con
     * los DECIMAL como texto) o desde las ya validadas. Sin indicador (fila
     * vieja o NULL) cuenta como 18%, el mismo default que al guardar.
     *
     * @param array<int,array|object> $items
     * @return array<int,array{quantity:float,amount:float,indicador_facturacion:int}>
     */
    public static function lineasDesdeFilas(array $items): array
    {
        $lineas = [];
        foreach (array_values($items) as $fila) {
            $fila = (array) $fila;
            $ind = $fila['indicador_facturacion'] ?? null;
            $lineas[] = [
                'quantity' => (float) ($fila['quantity'] ?? 0),
                'amount' => (float) ($fila['amount'] ?? 0),
                'indicador_facturacion' => ($ind === null || $ind === '') ? 1 : (int) $ind,
            ];
        }
        return $lineas;
    }

    /**
     * Ajustes para totales() desde lo guardado ([concepto => monto], como lo
     * devuelve getAjustes, o el objeto `ajustes` de la fila del GET). La
     * retencion se guarda como monto; aqui vuelve a ser la casilla (marcada si
     * el monto es > 0) y totales() la recalcula desde el Sub-total.
     */
    public static function ajustesDesdeFilas(array|object $filas): array
    {
        $guardados = (array) $filas;
        $out = [];
        foreach (self::AJUSTES_MONTO as $concepto) {
            $v = $guardados[$concepto] ?? 0;
            $out[$concepto] = is_numeric($v) ? (float) $v : 0.0;
        }
        $ret = $guardados[self::RETENCION] ?? 0;
        $out[self::RETENCION] = is_numeric($ret) && (float) $ret > 0;
        return $out;
    }

    /**
     * Lineas en la forma de FerreteriaCotizacionPdf. Sirve igual para las del
     * cuerpo ya validado (vista previa) que para las filas guardadas (PDF).
     *
     * @return array<int,array{description:string,quantity:float,amount:float}>
     */
    public static function itemsPdf(array $items): array
    {
        $out = [];
        foreach (array_values($items) as $item) {
            $item = (array) $item;
            $out[] = [
                'description' => (string) ($item['description'] ?? ''),
                'quantity' => (float) ($item['quantity'] ?? 0),
                'amount' => (float) ($item['amount'] ?? 0),
            ];
        }
        return $out;
    }

    /** Fecha y hora de ahora en Santo Domingo, como la guarda un DATETIME. */
    private static function ahoraRd(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('America/Santo_Domingo')))->format('Y-m-d H:i:s');
    }

    /**
     * Una línea del cuerpo revisada y con sus defaults, o el mensaje para el
     * usuario. $n es el número de la línea como la ve el usuario.
     */
    private static function normalizarLinea(mixed $item, int $n, callable $problemaCantidad, callable $unidadValida): array|string
    {
        if (is_array($item)) {
            $item = (object) $item;
        }
        if (!is_object($item)) {
            return 'La línea ' . $n . ' no es válida. Quítala y vuelve a agregarla.';
        }

        $descripcion = is_string($item->description ?? null) ? self::limpiarDescripcion($item->description) : '';
        if ($descripcion === '') {
            return 'La línea ' . $n . ' no tiene descripción. Escríbela o quita esa línea.';
        }
        if (mb_strlen($descripcion) > self::MAX_DESCRIPCION) {
            return 'La descripción de la línea ' . $n . ' es muy larga: admite hasta '
                . self::MAX_DESCRIPCION . ' caracteres.';
        }

        // Sin unidad = Unidad (43), como una línea libre. Se guarda el código
        // DGII normalizado ('043' y 43 son '43'), el mismo de factura_items, y
        // con ese se juzgan las fracciones: nunca con null, que no bloquea nada.
        $crudo = $item->unidad_medida ?? null;
        if ($crudo === null || $crudo === '') {
            $unidad = self::UNIDAD_DEFAULT;
        } else {
            $unidad = (is_int($crudo) || is_float($crudo) || is_string($crudo)) ? (string) (int) $crudo : '0';
        }
        if (!$unidadValida($unidad)) {
            return 'La unidad de medida de la línea ' . $n . ' no es válida. Elige otra unidad en esa línea.';
        }

        // Sin cantidad, o una que no es número, cuenta como 0 (mayor que 0),
        // igual que en Gratex. 2 decimales y no 3: la cotización se convierte
        // en e-CF, cuyo CantidadItem admite 2.
        $cantidad = self::leerNumero($item->quantity ?? null) ?? 0.0;
        $problema = $cantidad > 0 ? $problemaCantidad($cantidad, $unidad, 2) : 'La cantidad debe ser mayor que 0.';
        if ($problema !== null) {
            return 'Línea ' . $n . ': ' . mb_strtolower(mb_substr($problema, 0, 1)) . mb_substr($problema, 1);
        }

        // Precio SIN ITBIS, con los 4 decimales que admite PrecioUnitarioItem.
        $precio = self::leerNumero($item->amount ?? null);
        if ($precio === null) {
            return 'El precio de la línea ' . $n . ' no es válido. Revísalo.';
        }
        if (!($precio > 0)) {
            return 'Línea ' . $n . ': el precio debe ser mayor que 0.';
        }
        if (unidadMedidaModel::decimalesDe($precio) > 4) {
            return 'Línea ' . $n . ': el precio admite hasta 4 decimales.';
        }

        $indicador = ($item->indicador_facturacion ?? null) === null ? 1 : self::leerEntero($item->indicador_facturacion);
        if (!in_array($indicador, [1, 2, 3, 4], true)) {
            return 'Línea ' . $n . ': el tipo de ITBIS no es válido. Elige 18%, 16%, 0% o exento.';
        }
        // Con producto, el servidor lo reemplaza por el del catálogo; esto vale para las líneas libres.
        $bienServicio = ($item->indicador_bien_servicio ?? null) === null ? 1 : self::leerEntero($item->indicador_bien_servicio);
        if (!in_array($bienServicio, [1, 2], true)) {
            return 'Línea ' . $n . ': elige si es un bien o un servicio.';
        }
        $productId = null;
        if (($item->product_id ?? null) !== null) {
            $productId = self::leerEntero($item->product_id);
            if ($productId === null || $productId <= 0) {
                return 'Línea ' . $n . ': el producto no es válido. Búscalo de nuevo o déjala como línea libre.';
            }
        }

        return [
            'product_id' => $productId,
            'description' => $descripcion,
            'quantity' => $cantidad,
            'amount' => $precio,
            'unidad_medida' => $unidad,
            'indicador_facturacion' => $indicador,
            'indicador_bien_servicio' => $bienServicio,
        ];
    }

    /**
     * Los cuatro montos y la casilla de retención, o el mensaje para el
     * usuario. Sin ajustes = ninguno: un PUT reemplaza el set completo.
     */
    private static function normalizarAjustes(mixed $ajustes): array|string
    {
        $out = [
            'cargos_bancarios' => 0.0,
            'manejo_bancario' => 0.0,
            'mano_obra' => 0.0,
            'abono' => 0.0,
            self::RETENCION => false,
        ];
        if ($ajustes === null) {
            return $out;
        }
        if (is_object($ajustes)) {
            $ajustes = get_object_vars($ajustes);
        }
        if (!is_array($ajustes)) {
            return 'Los cargos y abonos de la cotización no son válidos. Revísalos.';
        }
        foreach ($ajustes as $clave => $valor) {
            $clave = (string) $clave;
            if ($clave === self::RETENCION) {
                // Una casilla: "0", 1 o null no se adivinan, se rechazan.
                if (!is_bool($valor)) {
                    return 'La casilla «Retención Renta por Tercero 5%» no es válida: tiene que ser sí o no.';
                }
                $out[self::RETENCION] = $valor;
                continue;
            }
            if (!in_array($clave, self::AJUSTES_MONTO, true)) {
                return 'Los cargos y abonos traen un concepto que este formato no conoce («' . mb_substr($clave, 0, 40) . '»).';
            }
            if ($valor === null) {
                continue;   // campo vacío = 0
            }
            $etiqueta = self::ETIQUETAS_AJUSTE[$clave];
            $monto = self::leerNumero($valor);
            if ($monto === null) {
                return '«' . $etiqueta . '» no es un monto válido. Revísalo.';
            }
            if ($monto < 0) {
                return '«' . $etiqueta . '» no puede ser negativo.';
            }
            if (unidadMedidaModel::decimalesDe($monto) > 2) {
                return '«' . $etiqueta . '» admite hasta 2 decimales.';
            }
            $out[$clave] = $monto;
        }
        return $out;
    }

    /**
     * 'Y-m-d H:i:s' tal cual; 'Y-m-d' con la hora de ahora en RD (la app no
     * fija zona horaria y la del servidor no es la de RD); null si no vino.
     * false si no es una fecha real (2026-02-30, 25:00:00, 02/09/2026).
     */
    private static function normalizarFecha(mixed $crudo): string|false|null
    {
        if ($crudo === null || (is_string($crudo) && trim($crudo) === '')) {
            return null;
        }
        if (!is_string($crudo)) {
            return false;
        }
        $crudo = trim($crudo);
        $soloDia = preg_match('/^\d{4}-\d{2}-\d{2}$/', $crudo) === 1;
        $formato = $soloDia ? '!Y-m-d' : '!Y-m-d H:i:s';
        $dt = DateTimeImmutable::createFromFormat($formato, $crudo);
        // Ida y vuelta: createFromFormat acepta el 30 de febrero (lo pasa a
        // marzo); si al formatear no sale lo mismo, la fecha no existe. El año
        // mínimo es el de un DATETIME de MySQL.
        if ($dt === false || $dt->format(ltrim($formato, '!')) !== $crudo || (int) $dt->format('Y') < 1000) {
            return false;
        }
        if (!$soloDia) {
            return $crudo;
        }
        return $crudo . ' ' . (new DateTimeImmutable('now', new DateTimeZone('America/Santo_Domingo')))->format('H:i:s');
    }

    /** Un número finito del JSON (int, float o texto numérico); null si no lo es. */
    private static function leerNumero(mixed $v): ?float
    {
        if (is_string($v)) {
            $v = trim($v);
            if (!is_numeric($v)) {
                return null;
            }
        } elseif (!is_int($v) && !is_float($v)) {
            return null;
        }
        $n = (float) $v;
        return is_finite($n) ? $n : null;
    }

    /** Un entero del JSON (5, 5.0 o "5"); null si no lo es (5.5, true, "x"). */
    private static function leerEntero(mixed $v): ?int
    {
        if (is_int($v)) {
            return $v;
        }
        if (is_float($v) && is_finite($v) && floor($v) === $v && abs($v) < 1e15) {
            return (int) $v;
        }
        if (is_string($v) && preg_match('/^\s*-?\d{1,15}\s*$/', $v) === 1) {
            return (int) trim($v);
        }
        return null;
    }
}
