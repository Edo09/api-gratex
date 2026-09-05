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
    private array $factura;
    private array $clientData;
    private bool $noElectronica;

    /** @var array<int,array{nombre_item:string,descripcion:string}>|null */
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
        $sinTenant = !class_exists('TenantResolver') || TenantResolver::current() === null;
        $fb = fn(string $valor) => $sinTenant ? $valor : '';
        return [
            'razon_social' => $e['nombre_comercial'] ?? $e['razon_social'] ?? '',
            'direccion'    => $e['direccion'] ?? $fb('Calle Jose Nicolas Casimiro #85, Ensanche Espaillat, Santo Domingo, D.N.'),
            'telefono'     => $e['telefono'] ?? $fb('809-681-5141'),
            'correo'       => $e['correo'] ?? $fb('info@gratex.net'),
            'rnc'          => $e['rnc'] ?? $fb('131256432'),
            'website'      => $e['website'] ?? '',
        ];
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
        $ts = strtotime($this->fecha());
        return '31/12/' . date('Y', $ts ?: time());
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
     * @return array<int,array{cantidad:string,descripcion:string,unidad:string,precio:float,itbis:float,valor:float}>
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
            $descuento = (float) ($item['descuento_monto'] ?? 0);
            if ($descuento > 0) {
                $descripcion .= "\nDescuento: -" . number_format($descuento, 2);
            }

            $cantidad = $item['quantity'] ?? $item['cantidad'] ?? 1;
            $precio   = (float) ($item['amount'] ?? $item['precio_unitario'] ?? 0);
            $valor    = (float) ($item['subtotal'] ?? $item['monto_item'] ?? ($cantidad * $precio));

            $lineas[] = [
                'cantidad'    => (string) $cantidad,
                'descripcion' => $descripcion,
                'unidad'      => $this->unidadSigla($item['unidad_medida'] ?? ''),
                'precio'      => $precio,
                'itbis'       => $this->itbisLinea($item, $valor),
                'valor'       => $valor,
            ];
        }

        $this->detalleCache = ['lineas' => $lineas, 'motivo_fila' => $motivoPendiente];
        return $this->detalleCache;
    }

    /**
     * ITBIS de la linea: usa el valor guardado; si no viene o viene en 0 sobre
     * una linea gravada (indicador 1=18%, 2=16%), lo calcula desde el subtotal
     * y la tasa del indicador. Exento/0% se quedan en 0 — nunca un 18% ciego.
     * Necesario para facturas simples viejas que guardaron itbis_amount=0.
     */
    private function itbisLinea(array $item, float $valor): float
    {
        $ind = (int) ($item['indicador_facturacion'] ?? 1);
        $guardado = $item['itbis_amount'] ?? null;
        if ($guardado === null || ((float) $guardado == 0.0 && in_array($ind, [1, 2], true))) {
            $tasa = $ind === 1 ? 0.18 : ($ind === 2 ? 0.16 : 0.0);
            return round($valor * $tasa, 2);
        }
        return (float) $guardado;
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
     * @return array<int,array{0:string,1:float,2:bool}> [etiqueta, valor, esTotal]
     */
    public function filasTotales(): array
    {
        $t = $this->totales();
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
        $rncEmisor = (string) ($emisor['rnc'] ?? '');
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

    /** @return array<int,array{nombre_item:string,descripcion:string}> */
    private function itemsDesdeXml(): array
    {
        if ($this->itemsXml !== null) {
            return $this->itemsXml;
        }
        $this->itemsXml = [];
        $xml = (string) ($this->factura['xml_firmado'] ?? '');
        if ($xml === '' || !class_exists('DOMDocument')) {
            return $this->itemsXml;
        }
        $previo = libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        $opciones = defined('LIBXML_NONET') ? LIBXML_NONET : 0;
        $ok = $doc->loadXML($xml, $opciones);
        libxml_clear_errors();
        libxml_use_internal_errors($previo);
        if (!$ok) {
            return $this->itemsXml;
        }
        foreach ($doc->getElementsByTagName('Item') as $el) {
            $this->itemsXml[] = [
                'nombre_item' => $this->textoHijo($el, 'NombreItem'),
                'descripcion' => $this->textoHijo($el, 'DescripcionItem'),
            ];
        }
        return $this->itemsXml;
    }

    private function textoHijo($padre, string $tag): string
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
