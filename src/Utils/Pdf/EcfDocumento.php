<?php

/**
 * Datos de un comprobante listos para imprimir, SIN nada de dibujo.
 *
 * Todo lo que la norma DGII fija sobre el CONTENIDO de la Representacion
 * Impresa vive aqui: el titulo por tipo de e-CF, la razon social tal como se
 * firmo, los totales tomados del XML emitido, el ITBIS por linea, la sigla de
 * unidad de medida, el motivo de las notas E33/E34 y la URL del timbre.
 *
 * Existe porque hay mas de un formato de impresion (carta 8.5x11 y tirilla POS
 * de 80 mm) y ambos deben decir EXACTAMENTE lo mismo. Si cada generador
 * calculara sus propios totales o armara su propia URL de timbre, un cambio de
 * norma se aplicaria en uno y no en el otro, y la divergencia solo se veria
 * cuando la DGII rechace un timbre impreso. Los generadores deciden COMO se ve;
 * esta clase decide QUE dice.
 */
final class EcfDocumento
{
    /**
     * Como se resuelven las lineas viejas que no cuadran (ver resolverLinea):
     * e-CF (precio recortado primero, cantidad a 2 decimales) o factura simple
     * (cantidad redondeada primero, a 3). Mismos nombres que ModoLinea del front.
     */
    public const MODO_ECF = 'ecf';
    public const MODO_SIMPLE = 'simple';

    private array $factura;
    private array $clientData;
    private bool $noElectronica;

    /** @var array<int,array{nombre_item:string,descripcion:string,cantidad:string,precio:string,monto:string,descuento:string}>|null */
    private ?array $itemsXml = null;
    private ?array $detalleCache = null;
    private ?array $clienteCache = null;
    private ?array $emisorCache = null;

    /**
     * @param array $factura       Fila de facturas + 'items' (y 'xml_firmado' si se emitio).
     * @param array $clientData    Fila de clients. Vacio => se resuelve por client_id.
     * @param bool  $noElectronica Factura simple / NCF tradicional: sin timbre ni etiquetas e-CF.
     */
    public function __construct(array $factura, array $clientData = [], bool $noElectronica = false)
    {
        $this->factura = $factura;
        $this->clientData = $clientData;
        $this->noElectronica = $noElectronica;
    }

    public function esElectronica(): bool
    {
        return !$this->noElectronica;
    }

    public function campo(string $clave, $default = null)
    {
        return $this->factura[$clave] ?? $default;
    }

    public function tipoEcf(): string
    {
        return (string) ($this->factura['tipo_ecf'] ?? '');
    }

    // ------------------------------------------------------------------
    // Emisor
    // ------------------------------------------------------------------

    /**
     * emisor_config crudo, cacheado (el Header() de FPDF corre por pagina).
     * Sin driver pdo_mysql (CLI sin BD) no se intenta: solo devuelve vacio.
     */
    public function emisorConfig(): array
    {
        if ($this->emisorCache === null) {
            try {
                require_once __DIR__ . '/../../Models/EmisorConfigModel.php';
                $this->emisorCache = extension_loaded('pdo_mysql')
                    ? ((new EmisorConfigModel())->get() ?: [])
                    : [];
            } catch (\Throwable $e) {
                $this->emisorCache = [];
            }
        }
        return $this->emisorCache;
    }

    /**
     * Emisor con fallbacks para imprimir. Los valores de Gratex solo aplican
     * cuando NO hay tenant resuelto (preview sin BD / single-tenant): imprimir
     * el telefono o el RNC de Gratex en la factura de otro contribuyente es
     * peor que no imprimir nada, y la DGII valida la representacion impresa.
     */
    public function emisor(): array
    {
        $e = $this->emisorConfig();
        $o = $this->emisorOverride();
        $sinTenant = !class_exists('TenantResolver') || TenantResolver::current() === null;
        // Con emisor explicito los valores historicos de Gratex NO aplican: el
        // impreso dice quien firmo el documento y lo que este no traiga se deja
        // en blanco, nunca se completa con el telefono o la direccion de otro.
        $fb = fn(string $valor) => ($sinTenant && $o === []) ? $valor : '';
        return [
            'razon_social' => $this->primeroNoVacio([$o['razon_social'] ?? null, $e['nombre_comercial'] ?? null, $e['razon_social'] ?? null]),
            'direccion'    => $this->primeroNoVacio([$o['direccion'] ?? null, $e['direccion'] ?? null, $fb('Calle Jose Nicolas Casimiro #85, Ensanche Espaillat, Santo Domingo, D.N.')]),
            'telefono'     => $this->primeroNoVacio([$o['telefono'] ?? null, $e['telefono'] ?? null, $fb('809-681-5141')]),
            'correo'       => $this->primeroNoVacio([$o['correo'] ?? null, $e['correo'] ?? null, $fb('info@gratex.net')]),
            'rnc'          => $this->primeroNoVacio([$o['rnc'] ?? null, $e['rnc'] ?? null, $fb('131256432')]),
            'website'      => $this->primeroNoVacio([$o['website'] ?? null, $e['website'] ?? null]),
        ];
    }

    /**
     * Emisor pasado explicitamente por el llamador en $factura['emisor'].
     * Manda sobre emisor_config porque al reimprimir un e-CF que llega como XML
     * (tools/ri_desde_xml.php, respaldo de otro contribuyente) el papel debe
     * decir el emisor de ESE documento: imprimir el del tenant conectado seria
     * una representacion impresa que no corresponde al comprobante firmado.
     *
     * @return array{razon_social?:string,direccion?:string,telefono?:string,correo?:string,rnc?:string,website?:string}
     */
    private function emisorOverride(): array
    {
        $o = $this->factura['emisor'] ?? null;
        return is_array($o) ? $o : [];
    }

    // ------------------------------------------------------------------
    // Identificacion del documento
    // ------------------------------------------------------------------

    /** Titulo dinamico segun el tipo de e-CF (norma DGII). */
    public function titulo(): string
    {
        if ($this->noElectronica) {
            return 'Factura';
        }
        $titulos = [
            '31' => 'Factura de Crédito Fiscal Electrónica',
            '32' => 'Factura de Consumo Electrónica',
            '33' => 'Nota de Débito Electrónica',
            '34' => 'Nota de Crédito Electrónica',
            '41' => 'Comprobante Electrónico de Compras',
            '43' => 'Comprobante Electrónico para Gastos Menores',
            '44' => 'Comprobante Electrónico para Regímenes Especiales',
            '45' => 'Comprobante Electrónico Gubernamental',
            '46' => 'Comprobante Electrónico para Exportaciones',
            '47' => 'Comprobante Electrónico para Pagos al Exterior',
        ];
        return $titulos[$this->tipoEcf()] ?? 'Comprobante Fiscal Electrónico';
    }

    public function eNcf(): string
    {
        return (string) ($this->factura['e_ncf'] ?? $this->factura['no_factura'] ?? '');
    }

    public function noFactura(): string
    {
        return (string) ($this->factura['no_factura'] ?? '');
    }

    /** NCF tradicional de una factura simple (no e-CF). */
    public function ncfTradicional(): string
    {
        return (string) ($this->factura['NCF'] ?? $this->factura['ncf'] ?? '');
    }

    public function fecha(): string
    {
        return (string) ($this->factura['date'] ?? date('Y-m-d'));
    }

    /** Fecha larga en castellano, p.ej. "Mayo 27, 2026". */
    public function fechaLarga(): string
    {
        $ts = strtotime($this->fecha());
        $mesesEn = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
        $mesesEs = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
        return str_replace($mesesEn, $mesesEs, date('F', $ts)) . ' ' . date('d', $ts) . ', ' . date('Y', $ts);
    }

    public function fechaCorta(): string
    {
        return $this->formatFecha($this->fecha());
    }

    /**
     * Vencimiento del e-NCF. La secuencia autorizada vence el 31 de diciembre
     * del ano de emision — se calcula sobre la fecha de la factura, no sobre
     * "hoy", para que reimprimir en enero no cambie lo que decia el papel.
     */
    public function fechaVencimiento(): string
    {
        // La emitida en el e-CF firmado manda: es la que la DGII autorizo para
        // esa secuencia y puede no ser el 31/12 del ano de emision.
        $delXml = $this->campoXml('FechaVencimientoSecuencia');
        if ($delXml !== '') {
            return str_replace('-', '/', $delXml);
        }
        $ts = strtotime($this->fecha());
        return '31/12/' . date('Y', $ts ?: time());
    }

    /**
     * Fecha limite de pago (dd/mm/aaaa). De contado se paga al emitir: es la
     * fecha de la factura. A credito manda la FechaLimitePago del e-CF firmado;
     * sin XML (factura simple, vista previa) la que pidio el front o, si no, la
     * misma regla que ECFXmlBuilder aplica al emitir: fecha + 30 dias.
     */
    public function fechaLimitePago(): string
    {
        $delXml = $this->campoXml('FechaLimitePago');
        if ($delXml !== '') {
            return str_replace('-', '/', $delXml);
        }
        $ts = strtotime($this->fecha()) ?: time();
        if ((int) ($this->factura['tipo_pago'] ?? 1) === 2) {
            $pedida = strtotime((string) ($this->factura['fecha_limite_pago'] ?? ''));
            $ts = $pedida ?: strtotime('+30 days', $ts);
        }
        return date('d/m/Y', $ts);
    }

    /** Texto de un nodo simple del e-CF firmado ('' si no hay XML o no existe). */
    private function campoXml(string $tag): string
    {
        $xml = (string) ($this->factura['xml_firmado'] ?? '');
        if ($xml === '' || !preg_match('/<' . $tag . '>([^<]*)<\/' . $tag . '>/i', $xml, $m)) {
            return '';
        }
        return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }

    // ------------------------------------------------------------------
    // Receptor
    // ------------------------------------------------------------------

    /** Cliente: el pasado por el llamador o, si no, el de la BD por client_id. */
    public function cliente(): array
    {
        if ($this->clienteCache !== null) {
            return $this->clienteCache;
        }
        $c = $this->clientData;
        if (empty($c) && !empty($this->factura['client_id'])) {
            try {
                $db = Database::getInstance()->getConnection();
                $stmt = $db->prepare('SELECT client_name, company_name, email, phone_number, rnc FROM clients WHERE id = :id LIMIT 1');
                $stmt->execute([':id' => $this->factura['client_id']]);
                $c = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
            } catch (\Throwable $e) {
                $c = [];
            }
        }
        // La fila de clients manda, pero un campo vacio cae al de la factura:
        // en E32 de consumo el cliente suele venir solo en la factura.
        $this->clienteCache = [
            'client_name'  => $this->primeroNoVacio([$c['client_name'] ?? null, $this->factura['client_name'] ?? null]),
            'company_name' => $this->primeroNoVacio([$c['company_name'] ?? null, $this->factura['company_name'] ?? null]),
            'phone_number' => (string) ($c['phone_number'] ?? ''),
            'rnc'          => (string) ($c['rnc'] ?? ''),
            'email'        => (string) ($c['email'] ?? ''),
        ];
        return $this->clienteCache;
    }

    /**
     * Bloque del receptor tal como debe salir impreso. Refleja el e-CF emitido
     * (ver ECFXmlBuilder::requiereComprador/buildComprador):
     *  - E43 (Gastos Menores): el e-CF no lleva Comprador -> no se imprime.
     *  - E47 (Pagos al Exterior): comprador extranjero sin RNC dominicano; el
     *    XML escribe IdentificadorExtranjero -> etiqueta distinta.
     *
     * @return array{mostrar:bool,label_id:string,rnc:string,razon_social:string,contacto:string}
     */
    public function receptor(): array
    {
        $tipo = $this->tipoEcf();
        $c = $this->cliente();
        $rnc = (string) $c['rnc'];
        // Prioridad: la razon social emitida en el e-CF firmado (lo que valido
        // la DGII). Sin XML cae al registro del cliente y, en ultimo caso (E32
        // Consumo sin comprador), a "Consumidor Final".
        $razon = $this->razonSocialCompradorDesdeXml()
            ?? ($c['company_name'] !== '' ? $c['company_name'] : ($c['client_name'] !== '' ? $c['client_name'] : 'Consumidor Final'));

        $contacto = trim((string) $c['phone_number']);
        if ($c['client_name'] !== '') {
            $contacto .= ($contacto !== '' ? ', ' : '') . 'Att. ' . $c['client_name'];
        }

        return [
            'mostrar'      => $tipo !== '43',
            'label_id'     => $tipo === '47' ? 'Identificación Tributaria' : 'RNC Cliente',
            'rnc'          => $rnc,
            'razon_social' => $razon,
            'contacto'     => $contacto,
        ];
    }

    /**
     * Razon social del comprador tal como se emitio en el e-CF firmado, para que
     * el impreso coincida con lo que valido la DGII. Evita divergencias si el
     * registro del cliente cambia tras emitir. Null si no hay XML o el nodo no
     * existe (preview / comprador ausente).
     */
    public function razonSocialCompradorDesdeXml(): ?string
    {
        $xml = (string) ($this->factura['xml_firmado'] ?? '');
        if ($xml !== '' && preg_match('/<RazonSocialComprador>([^<]*)<\/RazonSocialComprador>/i', $xml, $m)) {
            $val = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
            if ($val !== '') {
                return $val;
            }
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Notas de credito / debito (E33 / E34)
    // ------------------------------------------------------------------

    /** @return array{ncf:string,fecha:string,razon:string}|null */
    public function notaModificacion(): ?array
    {
        if (!in_array($this->tipoEcf(), ['33', '34'], true)) {
            return null;
        }
        $ncf = trim((string) ($this->factura['ncf_modificado'] ?? ''));
        $razon = trim((string) ($this->factura['razon_modificacion'] ?? ''));
        if ($ncf === '' && $razon === '') {
            return null;
        }
        return [
            'ncf'   => $ncf,
            'fecha' => $this->formatFecha((string) ($this->factura['fecha_ncf_modificado'] ?? '')),
            'razon' => $razon,
        ];
    }

    // ------------------------------------------------------------------
    // Lineas
    // ------------------------------------------------------------------

    /**
     * Lineas normalizadas para imprimir, en UTF-8 (cada formato las codifica
     * a su manera). Incluye el ITBIS por linea ya resuelto y el descuento
     * anotado dentro de la descripcion.
     *
     * 'cantidad' y 'precio_texto' ya salen formateados (textoCantidad /
     * textoPrecio) y resueltos contra el XML firmado o derivados para que
     * Cantidad x Precio - Descuento de el Valor (ver cantidadesYPrecios).
     * 'precio' es el mismo precio como numero.
     *
     * @return array<int,array{cantidad:string,descripcion:string,unidad:string,precio:float,precio_texto:string,itbis:float,valor:float}>
     */
    public function lineas(): array
    {
        return $this->detalle()['lineas'];
    }

    /**
     * Motivo de la nota E33/E34 cuando NO se pudo anexar a la descripcion de
     * una linea (porque los items ya traen la suya). Cadena vacia = ya salio
     * dentro de una linea. La norma exige que aparezca de una forma u otra.
     */
    public function motivoEnFilaAparte(): string
    {
        return $this->detalle()['motivo_fila'];
    }

    private function detalle(): array
    {
        if ($this->detalleCache !== null) {
            return $this->detalleCache;
        }

        $items = [];
        if (isset($this->factura['items']) && is_array($this->factura['items'])) {
            $items = array_values($this->factura['items']);
        }

        $nota = $this->notaModificacion();
        $motivoPendiente = $nota['razon'] ?? '';

        // Si alguna linea trae descripcion propia, el Motivo no puede colarse
        // en ella: va en su propia fila al final.
        $algunaDescripcion = false;
        foreach ($items as $i => $it) {
            [, $desc] = $this->partesItem((array) $it, $i);
            if (trim($desc) !== '') {
                $algunaDescripcion = true;
                break;
            }
        }

        // Cantidad y precio se resuelven UNA vez aqui, y de aqui salen la carta,
        // la tirilla, el recibo web y las vistas previas: si cada formato
        // resolviera por su cuenta, el mismo comprobante podria decir 84.75 en
        // uno y 84.7458 en otro. El modo decide el orden y los decimales de una
        // cantidad derivada (ver resolverLinea).
        $montos = self::resolverLineas(
            $this->preciosIncluyenItbis() ? array_map([self::class, 'conItbisEnValor'], $items) : $items,
            $this->itemsDesdeXml(),
            $this->noElectronica ? self::MODO_SIMPLE : self::MODO_ECF
        );

        $lineas = [];
        foreach ($items as $i => $item) {
            $item = (array) $item;
            $extra = '';
            if (!$algunaDescripcion && $motivoPendiente !== '') {
                $extra = $motivoPendiente;
                $motivoPendiente = '';
            }
            $descripcion = $this->descripcionItem($item, $i, $extra);

            // El descuento de la linea se anota en la descripcion en vez de
            // abrir una columna: las columnas de la RI son las que exige la
            // norma DGII y agregar una rompe ese formato. Asi el cliente ve
            // por que el Valor es menor que Cantidad x Precio.
            $descuento = $montos[$i]['descuento'];
            if ($descuento > 0) {
                $descripcion .= "\nDescuento: -" . number_format($descuento, 2);
            }

            $precio = $montos[$i]['precio'];
            $valor  = $montos[$i]['valor'];

            $lineas[] = [
                'cantidad'     => self::textoCantidad($montos[$i]['cantidad']),
                'descripcion'  => $descripcion,
                'unidad'       => $this->unidadSigla($item['unidad_medida'] ?? ''),
                'precio'       => $precio,
                'precio_texto' => self::textoPrecio($precio),
                'itbis'        => $this->itbisLinea($item, $valor),
                'valor'        => $valor,
            ];
        }

        $this->detalleCache = ['lineas' => $lineas, 'motivo_fila' => $motivoPendiente];
        return $this->detalleCache;
    }

    // ------------------------------------------------------------------
    // Cantidad y precio de cada linea (resolucion + formato)
    // ------------------------------------------------------------------

    /**
     * Cantidad, precio y valor que se imprimen en cada linea, en el orden de
     * $items. Publico y sin estado para que cualquier salida de las lineas
     * (el JSON del detalle, un reporte) diga lo mismo que el papel sin tener
     * que instanciar el documento.
     *
     * Hasta la migracion 025 factura_items guardaba quantity en INT(11) y
     * amount en DECIMAL(10,2): 1.5 se guardo como 2 y 84.7458 como 84.75,
     * mientras subtotal si se guardo bien. Imprimir esas filas tal cual da
     * lineas que no suman (2 x 100 = 150). Ver resolverLinea para el orden.
     *
     * @param array  $items      Filas de factura_items (quantity, amount,
     *                           subtotal, descuento_monto) o items de
     *                           EcfItemMapper (cantidad, precio_unitario, monto_item).
     * @param string $xmlFirmado facturas.xml_firmado ('' si no hay; una factura
     *                           simple nunca lo tiene).
     * @param string $modo       self::MODO_ECF o self::MODO_SIMPLE (ver resolverLinea).
     * @return array<int,array{cantidad:float,precio:float,valor:float,descuento:float,origen:string}>
     */
    public static function cantidadesYPrecios(array $items, string $xmlFirmado = '', string $modo = self::MODO_SIMPLE): array
    {
        return self::resolverLineas(array_values($items), self::itemsXml($xmlFirmado), $modo);
    }

    /**
     * @param array<int,array{cantidad:string,precio:string,monto:string}> $itemsXml
     * @return array<int,array{cantidad:float,precio:float,valor:float,descuento:float,origen:string}>
     */
    private static function resolverLineas(array $items, array $itemsXml, string $modo): array
    {
        // factura_items se inserta uno a uno en el orden de los Item firmados y
        // se lee ORDER BY id, asi que el indice empareja fila e Item. Pero una
        // fila de mas o de menos correria todos los indices: con conteos
        // distintos el XML no se usa para montos y cada fila se resuelve sola.
        $usarXml = $itemsXml !== [] && count($itemsXml) === count($items);

        $resueltas = [];
        foreach ($items as $i => $item) {
            $item = (array) $item;
            $cantidad  = self::aNumero($item['quantity'] ?? $item['cantidad'] ?? 1);
            $precio    = self::aNumero($item['amount'] ?? $item['precio_unitario'] ?? 0);
            $descuento = self::aNumero($item['descuento_monto'] ?? 0);
            $valorGuardado = $item['subtotal'] ?? $item['monto_item'] ?? null;
            // Sin valor guardado se calcula como EcfItemMapper: redondeo por linea.
            // Vacio cuenta como sin valor, igual que en el front (lineaQueCuadra).
            $valor = $valorGuardado !== null && $valorGuardado !== ''
                ? self::aNumero($valorGuardado)
                : round(round($cantidad * $precio, 2) - $descuento, 2);

            $resueltas[] = [
                'valor'     => $valor,
                'descuento' => $descuento,
            ] + self::resolverLinea(
                $cantidad,
                $precio,
                $valor,
                $descuento,
                $usarXml ? ($itemsXml[$i] ?? null) : null,
                $modo
            );
        }
        return $resueltas;
    }

    /**
     * Cantidad y precio de UNA linea, de modo que el papel sume:
     * round(Cantidad x Precio, 2) - Descuento = Valor.
     *
     * Hasta la 025 la BD perdia precision de dos formas: la cantidad en INT(11)
     * (1.5 -> 2, 0.4 -> 0) y el precio en DECIMAL(10,2) (84.7458 -> 84.75),
     * mientras el Valor se guardaba con lo escrito. Sin XML no se puede saber
     * cual se perdio, y con cantidades grandes las dos explicaciones cuadran a
     * la vez (100 / 84.75 / 8474.58 es 100 x 84.7458 o 99.995 x 84.75). El modo
     * dice cual fue la perdida realista en cada documento:
     *
     *  - MODO_ECF: el e-CF manda el precio SIN ITBIS con 4 decimales (el precio
     *    con ITBIS / 1.18): se perdia el precio. La cantidad derivada lleva 2
     *    decimales, los de CantidadItem.
     *  - MODO_SIMPLE: precios de catalogo de 2 decimales y sin ITBIS que
     *    desglosar, pero cantidades con decimales en el formulario: se perdia
     *    la cantidad. La cantidad derivada lleva 3 decimales.
     *
     * Orden:
     *  1. e-CF con su Item firmado: CantidadItem y PrecioUnitarioItem del XML,
     *     si su MontoItem es el Valor de la fila. Es lo que tiene la DGII.
     *  2. La fila ya cuadra: tal cual (todo lo guardado desde la 025 y lo viejo
     *     de cantidad entera y precio de 2 decimales).
     *  3. y 4., en el orden del modo (e-CF: precio y luego cantidad; simple:
     *     cantidad y luego precio):
     *     - Precio recortado: P' = round((Valor + Descuento) / Cantidad, 4), solo
     *       si cuadra y round(P', 2) es justo el precio guardado.
     *     - Cantidad redondeada: Q' = round((Valor + Descuento) / Precio, 2|3),
     *       solo si la guardada es entera, round(Q', 0) es ella y Q' cuadra con
     *       el precio guardado.
     *  5. Se perdieron las dos: el mismo Q' con el precio ajustado a el,
     *     round((Valor + Descuento) / Q', 4).
     *  6. El precio que hace cuadrar la linea con la cantidad guardada, aunque
     *     no redondee al guardado.
     *  7. Nada la hace cuadrar (p.ej. cantidad 0 sin Q' posible): lo guardado.
     *
     * Un valor derivado cuadra la linea pero no es necesariamente el que se
     * escribio (254.24 / 3 = 84.7467, no 84.7458): sin XML no hay forma de
     * saberlo, y una linea que suma vale mas que un numero que no se puede
     * comprobar.
     *
     * COPIA EXACTA en el front: lineaQueCuadra (fiscalo
     * src/features/invoices/montosLinea.ts), con la que se carga el formulario
     * de la factura simple y se muestra el detalle del e-CF. Un cambio aqui va
     * alli en el mismo cambio, o formulario, pantalla y papel dejan de decir lo
     * mismo (y reguardar reescribe cantidades).
     *
     * @param array|null $xml  Item firmado: ['cantidad'=>, 'precio'=>, 'monto'=>] como texto.
     * @param string     $modo self::MODO_ECF o self::MODO_SIMPLE (cualquier otro = simple).
     * @return array{cantidad:float,precio:float,origen:string}
     */
    public static function resolverLinea(
        float $cantidad,
        float $precio,
        float $valor,
        float $descuento = 0.0,
        ?array $xml = null,
        string $modo = self::MODO_SIMPLE
    ): array {
        if ($xml !== null && is_numeric($xml['precio'] ?? null)) {
            $montoXml = $xml['monto'] ?? null;
            // MontoItem es el mismo numero que se guardo como subtotal. Si no
            // coincide, ese Item no es esta fila y mezclarlos imprimiria una
            // linea que no suma: mejor resolverla con lo guardado.
            if (!is_numeric($montoXml) || abs((float) $montoXml - $valor) < 0.005) {
                return [
                    'cantidad' => is_numeric($xml['cantidad'] ?? null) ? (float) $xml['cantidad'] : $cantidad,
                    'precio'   => (float) $xml['precio'],
                    'origen'   => 'xml',
                ];
            }
        }

        if (self::cuadra($cantidad, $precio, $valor, $descuento)) {
            return ['cantidad' => $cantidad, 'precio' => $precio, 'origen' => 'bd'];
        }

        $bruto = $valor + $descuento;

        // Precio recortado: el que cuadra con la cantidad guardada y que el
        // DECIMAL(10,2) habria dejado justo en el precio que hay.
        $precioDerivado = $cantidad > 0 ? round($bruto / $cantidad, 4) : null;
        $cuadraConPrecio = $precioDerivado !== null
            && self::cuadra($cantidad, $precioDerivado, $valor, $descuento);
        $porPrecio = $cuadraConPrecio && abs(round($precioDerivado, 2) - $precio) < 1e-6
            ? ['cantidad' => $cantidad, 'precio' => $precioDerivado, 'origen' => 'precio_derivado']
            : null;

        // Cantidad redondeada: solo si "la explica el INT", es decir, si la
        // columna (redondeando la mitad hacia arriba, como MySQL) habria guardado
        // justo la cantidad que hay.
        $cantidadDerivada = null;
        if (abs($cantidad - round($cantidad)) < 1e-9 && $precio > 0) {
            $q = round($bruto / $precio, self::decimalesCantidad($modo));
            if ($q > 0 && abs($q - $cantidad) > 1e-9 && abs(round($q) - $cantidad) < 1e-9) {
                $cantidadDerivada = $q;
            }
        }
        $porCantidad = $cantidadDerivada !== null
            && self::cuadra($cantidadDerivada, $precio, $valor, $descuento)
            ? ['cantidad' => $cantidadDerivada, 'precio' => $precio, 'origen' => 'cantidad_derivada']
            : null;

        $primero = $modo === self::MODO_ECF ? ($porPrecio ?? $porCantidad) : ($porCantidad ?? $porPrecio);
        if ($primero !== null) {
            return $primero;
        }

        if ($cantidadDerivada !== null) {
            $precioAjustado = round($bruto / $cantidadDerivada, 4);
            if (self::cuadra($cantidadDerivada, $precioAjustado, $valor, $descuento)) {
                return ['cantidad' => $cantidadDerivada, 'precio' => $precioAjustado, 'origen' => 'cantidad_y_precio_derivados'];
            }
        }

        if ($cuadraConPrecio) {
            return ['cantidad' => $cantidad, 'precio' => $precioDerivado, 'origen' => 'precio_derivado'];
        }

        // Nada la hace cuadrar: lo guardado.
        return ['cantidad' => $cantidad, 'precio' => $precio, 'origen' => 'bd'];
    }

    /**
     * Decimales de una cantidad derivada: 2 en el e-CF (CantidadItem, igual que
     * EcfItemMapper::DECIMALES_CANTIDAD), 3 en la factura simple
     * (facturaModel::DECIMALES_CANTIDAD_SIMPLE).
     */
    private static function decimalesCantidad(string $modo): int
    {
        return $modo === self::MODO_ECF ? 2 : 3;
    }

    /** round(Cantidad x Precio, 2) - Descuento = Valor, con el redondeo de EcfItemMapper. */
    private static function cuadra(float $cantidad, float $precio, float $valor, float $descuento): bool
    {
        return abs(round(round($cantidad * $precio, 2) - $descuento, 2) - $valor) < 0.005;
    }

    /**
     * Cantidad para imprimir: sin ceros de relleno y hasta 3 decimales, con
     * separador de miles (3 -> "3", "1.500" -> "1.5", 1234.125 -> "1,234.125").
     * Desde la 025 MySQL devuelve la columna como texto DECIMAL ("3.000"):
     * sin esto el papel diria "3.000". Mismo formato que fmtCantidad del front.
     */
    public static function textoCantidad($valor): string
    {
        $texto = number_format(self::aNumero($valor), 3, '.', ',');
        // Siempre lleva punto decimal, asi que el rtrim de ceros nunca se come
        // los del entero ("1,000.000" -> "1,000.").
        $texto = rtrim(rtrim($texto, '0'), '.');
        return $texto === '-0' ? '0' : $texto;
    }

    /**
     * Precio unitario para imprimir: 2 decimales, o hasta 4 cuando los tiene
     * (100 -> "100.00", 84.7 -> "84.70", 84.7458 -> "84.7458"). Con 2 fijos,
     * 3 x 84.7458 se leeria "3 x 84.75 = 254.24" y el cliente veria una linea
     * que no suma. Mismo formato que fmtPrecio del front.
     */
    public static function textoPrecio($valor): string
    {
        $texto = number_format(self::aNumero($valor), 4, '.', ',');
        // Los decimales 3 y 4 solo se quedan si traen algo: "84.7000" -> "84.70".
        $texto = (string) preg_replace('/(\.\d{2}\d*?)0+$/', '$1', $texto);
        return $texto === '-0.00' ? '0.00' : $texto;
    }

    /** Numero de lo que llegue de la BD (int, float o texto DECIMAL); lo no numerico es 0. */
    private static function aNumero($valor): float
    {
        if (is_int($valor) || is_float($valor)) {
            return (float) $valor;
        }
        return is_scalar($valor) && is_numeric(trim((string) $valor)) ? (float) trim((string) $valor) : 0.0;
    }

    /**
     * ITBIS de la linea.
     *
     * Una factura simple no lleva impuesto: es un documento interno, no se emite
     * a la DGII y no entra en el 606/607, asi que no hay ITBIS que declarar ni
     * que cobrar. Se corta aqui, antes de mirar el indicador, para que ni las
     * facturas viejas (que llegaron a guardar itbis_amount > 0) ni las lineas
     * sin indicador reintroduzcan un impuesto por la puerta de atras.
     *
     * En el e-CF se usa el valor guardado; si no viene o viene en 0 sobre una
     * linea gravada (indicador 1=18%, 2=16%), se calcula desde el subtotal y la
     * tasa del indicador. Exento/0% se quedan en 0 — nunca un 18% ciego.
     */
    private function itbisLinea(array $item, float $valor): float
    {
        if ($this->noElectronica) {
            return 0.0;
        }
        $ind = (int) ($item['indicador_facturacion'] ?? 1);
        $guardado = $item['itbis_amount'] ?? null;
        if ($guardado === null || ((float) $guardado == 0.0 && in_array($ind, [1, 2], true))) {
            $tasa = $ind === 1 ? 0.18 : ($ind === 2 ? 0.16 : 0.0);
            // Con precios con ITBIS el Valor ya lo trae: se saca, no se suma.
            return $this->preciosIncluyenItbis()
                ? round($valor - round($valor / (1 + $tasa), 2), 2)
                : round($valor * $tasa, 2);
        }
        return (float) $guardado;
    }

    /**
     * IndicadorMontoGravado = 1: los precios y el Valor de cada linea traen el
     * ITBIS adentro (ventas del POS). Lo dice el XML firmado; sin el (vista
     * previa) lo trae la factura armada por el controller.
     *
     * factura_items guarda en `subtotal` la base SIN ITBIS (lo que suman el
     * reporte de ventas y el 607), asi que para imprimir la linea hay que volver
     * a juntarla con su ITBIS: ver conItbisEnValor.
     */
    public function preciosIncluyenItbis(): bool
    {
        if (array_key_exists('indicador_monto_gravado', $this->factura)) {
            return (string) $this->factura['indicador_monto_gravado'] === '1';
        }
        return $this->campoXml('IndicadorMontoGravado') === '1';
    }

    /**
     * Fila de factura_items de un e-CF con precios con ITBIS: su Valor impreso
     * es el MontoItem firmado = subtotal (base) + itbis_amount. Sin esto la linea
     * no empareja con su Item del XML (resolverLinea paso 1) y se imprimiria un
     * precio sin ITBIS derivado que no esta en el e-CF. Los items armados por
     * EcfItemMapper (vista previa) no traen `subtotal`: su monto_item ya es el
     * MontoItem y quedan igual.
     */
    private static function conItbisEnValor($item): array
    {
        $item = (array) $item;
        if (isset($item['subtotal']) && $item['subtotal'] !== '') {
            $item['subtotal'] = round(self::aNumero($item['subtotal']) + self::aNumero($item['itbis_amount'] ?? 0), 2);
        }
        return $item;
    }

    // ------------------------------------------------------------------
    // Totales
    // ------------------------------------------------------------------

    /**
     * Totales del pie. Se toman del e-CF firmado para que cuadren con lo
     * emitido a la DGII; sin XML (preview) caen a la suma por linea, nunca a
     * un 18% ciego sobre el subtotal.
     *
     * @return array{subtotal:float,exento:float,itbis:float,total:float}
     */
    public function totales(): array
    {
        $delXml = $this->totalesDesdeXml();
        if ($delXml !== null) {
            return $delXml;
        }
        $subtotal = 0.0;
        $itbis = 0.0;
        foreach ($this->lineas() as $l) {
            $subtotal += $l['valor'];
            $itbis += $l['itbis'];
        }
        if ($this->preciosIncluyenItbis()) {
            // El Valor de cada linea ya trae su ITBIS: el total es la suma de
            // las lineas y el subtotal gravado, lo que queda sin el impuesto.
            return [
                'subtotal' => round($subtotal - $itbis, 2),
                'exento'   => 0.0,
                'itbis'    => round($itbis, 2),
                'total'    => round($subtotal, 2),
            ];
        }
        return [
            'subtotal' => $subtotal,
            'exento'   => 0.0,
            'itbis'    => $itbis,
            'total'    => $subtotal + $itbis,
        ];
    }

    /** @return array{subtotal:float,exento:float,itbis:float,total:float}|null */
    private function totalesDesdeXml(): ?array
    {
        $xml = (string) ($this->factura['xml_firmado'] ?? '');
        if ($xml === '') {
            return null;
        }
        $get = static function (string $tag) use ($xml): ?float {
            if (preg_match('/<' . $tag . '>\s*([0-9.]+)\s*<\/' . $tag . '>/i', $xml, $m)) {
                return (float) $m[1];
            }
            return null;
        };
        $total = $get('MontoTotal');
        if ($total === null) {
            return null;
        }
        $itbis  = $get('TotalITBIS') ?? 0.0;
        $exento = $get('MontoExento') ?? 0.0;
        $gravado = $get('MontoGravadoTotal');
        if ($gravado === null) {
            $gravado = $total - $itbis - $exento;
        }
        return [
            'subtotal' => round($gravado, 2),
            'exento'   => round($exento, 2),
            'itbis'    => round($itbis, 2),
            'total'    => round($total, 2),
        ];
    }

    /**
     * Filas del cuadro de totales con las etiquetas exactas que exige la DGII.
     * 'Monto Exento' se omite en 0 para no recargar facturas gravadas.
     *
     * La factura simple no declara impuestos, asi que no usa esas etiquetas:
     * 'Subtotal Gravado', 'Monto Exento' y 'Total ITBIS' son vocabulario fiscal
     * de la norma DGII. Lleva un pie llano de Subtotal y Total.
     *
     * @return array<int,array{0:string,1:float,2:bool}> [etiqueta, valor, esTotal]
     */
    public function filasTotales(): array
    {
        $t = $this->totales();
        if ($this->noElectronica) {
            return [
                ['Subtotal', $t['subtotal'], false],
                ['Total', $t['total'], true],
            ];
        }
        $filas = [['Subtotal Gravado', $t['subtotal'], false]];
        if ($t['exento'] > 0) {
            $filas[] = ['Monto Exento', $t['exento'], false];
        }
        $filas[] = ['Total ITBIS', $t['itbis'], false];
        $filas[] = ['Total', $t['total'], true];
        return $filas;
    }

    // ------------------------------------------------------------------
    // Timbre fiscal (QR + codigo de seguridad)
    // ------------------------------------------------------------------

    /**
     * Datos del timbre para el pie. Null cuando el documento no lo lleva
     * (factura no electronica) o falta el RNC del emisor para armar la URL.
     *
     * 'preview' = true cuando aun no hay e-NCF/codigo de seguridad: se imprime
     * un QR de muestra rotulado sin validez fiscal, jamas una URL invalida que
     * el cliente pueda escanear creyendo que valida.
     *
     * @return array{url:string,codigo_seguridad:string,fecha_firma:string,preview:bool}|null
     */
    public function timbre(): ?array
    {
        if ($this->noElectronica) {
            return null;
        }

        $eNcf = (string) ($this->factura['e_ncf'] ?? '');
        $codigo = (string) ($this->factura['codigo_seguridad'] ?? '');
        $fechaFirma = $this->formatFechaHora((string) ($this->factura['fecha_emision_dgii'] ?? ''));

        if ($eNcf === '' || $codigo === '') {
            return [
                'url'              => 'PREVIEW - Sin validez fiscal',
                'codigo_seguridad' => 'PREVIEW',
                'fecha_firma'      => $fechaFirma,
                'preview'          => true,
            ];
        }

        $emisor = $this->emisorConfig();
        $rncEmisor = $this->primeroNoVacio([
            $this->emisorOverride()['rnc'] ?? null,
            $emisor['rnc'] ?? null,
        ]);
        if ($rncEmisor === '') {
            return null;
        }

        $ambiente = $this->factura['ambiente_dgii'] ?? ($emisor['environment'] ?? 'CerteCF');
        $ambiente = match (strtolower((string) $ambiente)) {
            'certecf' => 'CerteCF',
            'testecf' => 'TesteCF',
            'ecf'     => 'ecf',
            default   => $ambiente,
        };

        $isFc = $this->tipoEcf() === '32' && (float) ($this->factura['total'] ?? 0) < 250000;
        $endpoint = $isFc ? 'ConsultaTimbreFC' : 'ConsultaTimbre';

        // RncComprador en el QR debe coincidir con el XML: la DGII valida el
        // timbre contra el e-CF emitido. E43 nunca lleva nodo Comprador y E47
        // solo lleva IdentificadorExtranjero (jamas RNCComprador). Incluirlo en
        // esos tipos hace que ConsultaTimbre devuelva "no encontrado".
        $rncComprador = (string) ($this->cliente()['rnc'] ?? '');
        $incluye = $rncComprador !== '' && !in_array($this->tipoEcf(), ['43', '47'], true);
        $paramComprador = $incluye ? '&RncComprador=' . rawurlencode($rncComprador) : '';

        $url = sprintf(
            'https://ecf.dgii.gov.do/%s/%s?RncEmisor=%s%s&ENCF=%s&FechaEmision=%s&MontoTotal=%s&FechaFirma=%s&CodigoSeguridad=%s',
            rawurlencode($ambiente),
            $endpoint,
            rawurlencode($rncEmisor),
            $paramComprador,
            rawurlencode($eNcf),
            rawurlencode($this->formatFecha($this->fecha())),
            rawurlencode($this->montoTotalParaTimbre()),
            rawurlencode($fechaFirma),
            rawurlencode($codigo)
        );

        return [
            'url'              => $url,
            'codigo_seguridad' => $codigo,
            'fecha_firma'      => $fechaFirma,
            'preview'          => false,
        ];
    }

    /**
     * Genera el PNG del QR en un temporal y devuelve su ruta (null si la
     * libreria o GD no estan). El llamador debe borrarlo tras insertarlo.
     */
    public static function generarQrPng(string $contenido): ?string
    {
        if (!class_exists('QRcode')) {
            return null;
        }
        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qr_' . bin2hex(random_bytes(8)) . '.png';
        try {
            @QRcode::png($contenido, $tmp, QR_ECLEVEL_M, 4, 1);
        } catch (\Throwable $e) {
            return null;
        }
        if (!file_exists($tmp) || filesize($tmp) === 0) {
            return null;
        }
        return $tmp;
    }

    private function montoTotalParaTimbre(): string
    {
        $xml = (string) ($this->factura['xml_firmado'] ?? '');
        if ($xml !== '' && preg_match('/<MontoTotal>\s*([0-9.]+)\s*<\/MontoTotal>/i', $xml, $m)) {
            return number_format((float) $m[1], 2, '.', '');
        }
        return number_format((float) ($this->factura['total'] ?? 0), 2, '.', '');
    }

    // ------------------------------------------------------------------
    // Unidades de medida
    // ------------------------------------------------------------------

    /**
     * Sigla a imprimir en "Und. Medida" (norma DGII: siglas estandar, ej. UND,
     * PZA, CAJ). Las lineas guardan el CODIGO DGII (43 = unidad); valores no
     * numericos se asumen ya como sigla.
     */
    public function unidadSigla($value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return 'UND';
        }
        if (!ctype_digit($value)) {
            return strtoupper($value);
        }
        static $map = null;
        if ($map === null) {
            $map = [];
            try {
                require_once __DIR__ . '/../../Models/unidadMedidaModel.php';
                $map = (new unidadMedidaModel())->codigoMap();
            } catch (\Throwable $e) {
                // Sin catalogo (master caido / CLI sin BD) se imprime el codigo:
                // una factura ya emitida siempre debe poder reimprimirse.
                $map = [];
            }
        }
        return $map[(int) $value] ?? $value;
    }

    // ------------------------------------------------------------------
    // Descripcion del item (nombre + descripcion, DB y XML firmado)
    // ------------------------------------------------------------------

    /**
     * Descripcion completa a imprimir: "NombreItem\nDescripcionItem", mas el
     * texto extra que le pase el llamador (el Motivo de una nota E33/E34).
     */
    private function descripcionItem(array $item, int $index, string $extra = ''): string
    {
        [$nombre, $descripcion] = $this->partesItem($item, $index);
        if ($extra !== '') {
            if ($descripcion === '') {
                $descripcion = $extra;
            } elseif (!$this->mismoTextoVisible($descripcion, $extra)) {
                $descripcion .= ' ' . $extra;
            }
        }
        $partes = [];
        if ($nombre !== '') {
            $partes[] = $nombre;
        }
        if ($descripcion !== '') {
            $partes[] = $descripcion;
        }
        return implode("\n", $partes);
    }

    /**
     * Nombre y descripcion del item. La fila de la BD manda; el XML firmado
     * rellena lo que falte (facturas viejas guardadas sin desglose). Si ambos
     * dicen lo mismo se imprime una sola vez.
     *
     * @return array{0:string,1:string}
     */
    private function partesItem(array $item, int $index): array
    {
        $xmlItem = $this->itemsDesdeXml()[$index] ?? [];
        $nombre = $this->primeroNoVacio([$item['nombre_item'] ?? null, $xmlItem['nombre_item'] ?? null]);
        $descripcion = $this->primeroNoVacio([$item['descripcion'] ?? null, $xmlItem['descripcion'] ?? null]);
        $legacy = $this->primeroNoVacio([$item['description'] ?? null]);

        if ($descripcion === '' && $legacy !== '') {
            if ($nombre === '' || !$this->mismoTextoVisible($legacy, $nombre)) {
                $descripcion = $legacy;
            }
        }
        if ($nombre !== '' && $descripcion !== '' && $this->mismoTextoVisible($nombre, $descripcion)) {
            $descripcion = '';
        }
        return [$nombre, $descripcion];
    }

    /** @return array<int,array{nombre_item:string,descripcion:string,cantidad:string,precio:string,monto:string,descuento:string}> */
    private function itemsDesdeXml(): array
    {
        if ($this->itemsXml === null) {
            $this->itemsXml = self::itemsXml((string) ($this->factura['xml_firmado'] ?? ''));
        }
        return $this->itemsXml;
    }

    /**
     * Items del e-CF firmado en el orden del documento (NumeroLinea), con los
     * textos tal como se firmaron. Publico para que quien exponga las lineas
     * (el JSON del detalle) lea los mismos valores sin armar el documento.
     * [] si no hay XML o no se puede leer.
     *
     * @return array<int,array{nombre_item:string,descripcion:string,cantidad:string,precio:string,monto:string,descuento:string}>
     */
    public static function itemsXml(string $xml): array
    {
        if ($xml === '' || !class_exists('DOMDocument')) {
            return [];
        }
        $previo = libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $opciones = defined('LIBXML_NONET') ? LIBXML_NONET : 0;
        $ok = $doc->loadXML($xml, $opciones);
        libxml_clear_errors();
        libxml_use_internal_errors($previo);
        if (!$ok) {
            return [];
        }
        $items = [];
        foreach ($doc->getElementsByTagName('Item') as $el) {
            $items[] = [
                'nombre_item' => self::textoHijo($el, 'NombreItem'),
                'descripcion' => self::textoHijo($el, 'DescripcionItem'),
                // Los montos firmados: con ellos se reimprime exacto lo que la
                // BD vieja recorto (ver resolverLinea).
                'cantidad'    => self::textoHijo($el, 'CantidadItem'),
                'precio'      => self::textoHijo($el, 'PrecioUnitarioItem'),
                'monto'       => self::textoHijo($el, 'MontoItem'),
                'descuento'   => self::textoHijo($el, 'DescuentoMonto'),
            ];
        }
        return $items;
    }

    private static function textoHijo($padre, string $tag): string
    {
        $nodos = $padre->getElementsByTagName($tag);
        return $nodos->length === 0 ? '' : trim((string) $nodos->item(0)->textContent);
    }

    private function primeroNoVacio(array $valores): string
    {
        foreach ($valores as $v) {
            if ($v === null) {
                continue;
            }
            if (trim((string) $v) !== '') {
                return (string) $v;
            }
        }
        return '';
    }

    private function mismoTextoVisible(string $a, string $b): bool
    {
        $n = static fn(string $v): string => trim((string) preg_replace('/\s+/', ' ', $v));
        return $n($a) === $n($b);
    }

    // ------------------------------------------------------------------
    // Fechas
    // ------------------------------------------------------------------

    private function formatFecha(string $valor): string
    {
        if ($valor === '') {
            return '';
        }
        $ts = strtotime($valor);
        return $ts ? date('d-m-Y', $ts) : '';
    }

    private function formatFechaHora(string $valor): string
    {
        if ($valor === '') {
            return '';
        }
        $ts = strtotime($valor);
        return $ts ? date('d-m-Y H:i:s', $ts) : '';
    }
}
