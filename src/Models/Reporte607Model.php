<?php
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../AmbienteResolver.php';
require_once __DIR__ . '/../Utils/FacturacionElectronica/IncomingXmlValidator.php';

/**
 * Reporte607Model — agrega las ventas del periodo (AAAAMM) y las normaliza a los
 * 23 campos del Formato 607 DGII. Fuentes: facturas (e-CF 31/32/33/34 + simples
 * tipo_ecf NULL) + factura_items + clients. Retenciones, propina, ISC y formas de
 * pago se parsean del xml_firmado (las facturas simples no tienen XML).
 */
class Reporte607Model
{
    private $conexion;

    const TIPO_INGRESO_DEFAULT = '01'; // Ingresos por operaciones (default DGII)

    // Columna usada cuando NO hay XML (factura simple) ni TablaFormasPago.
    // Valores: efectivo|cheque_transf|tarjeta|credito|bonos|permuta|otras  (editar aqui).
    const FORMA_PAGO_DEFAULT_FIELD = 'efectivo';

    public function __construct()
    {
        $this->conexion = Database::getInstance()->getConnection();
    }

    public function getEmisor(): ?array
    {
        $stmt = $this->conexion->prepare('SELECT rnc, razon_social FROM emisor_config WHERE id = 1');
        $stmt->execute();
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array{registros: array<int,array>, advertencias: array<int,string>} */
    public function getVentas(string $periodo): array
    {
        [$ini, $finExcl] = $this->periodoRango($periodo);
        $ambiente = AmbienteResolver::active();

        $registros = [];
        $advertencias = [];
        foreach ($this->fetchFacturas($ini, $finExcl, $ambiente) as $f) {
            $registros[] = $this->mapFactura($f, $advertencias);
        }
        return ['registros' => $registros, 'advertencias' => $advertencias];
    }

    private function fetchFacturas(string $ini, string $finExcl, ?string $ambiente): array
    {
        // Todas las ventas del periodo: e-CF (cualquier estado) + simples (tipo_ecf NULL).
        // 'date' es DATETIME -> rango [ini, primer dia del mes siguiente).
        $sql = "SELECT f.id, f.tipo_ecf, f.e_ncf, f.NCF, f.client_id, f.client_name,
                       f.date, f.total, f.estado_dgii, f.ambiente_dgii,
                       f.ncf_modificado, f.fecha_ncf_modificado, f.xml_firmado,
                       c.rnc AS cliente_rnc, c.razon_social AS cliente_razon
                FROM facturas f
                LEFT JOIN clients c ON f.client_id = c.id
                WHERE f.date >= :ini AND f.date < :finExcl";
        if ($ambiente !== null) {
            // e-CF llevan ambiente_dgii; las simples lo tienen NULL -> incluirlas.
            $sql .= ' AND (f.ambiente_dgii = :ambiente OR f.ambiente_dgii IS NULL)';
        }
        $sql .= ' ORDER BY f.date ASC, f.id ASC';

        $stmt = $this->conexion->prepare($sql);
        $stmt->bindValue(':ini', $ini);
        $stmt->bindValue(':finExcl', $finExcl);
        if ($ambiente !== null) {
            $stmt->bindValue(':ambiente', $ambiente);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** Monto facturado (base) e ITBIS de la factura, sumando sus lineas. */
    private function itemsTotales(int $facturaId): array
    {
        $stmt = $this->conexion->prepare(
            'SELECT COALESCE(SUM(subtotal),0) AS monto, COALESCE(SUM(itbis_amount),0) AS itbis
             FROM factura_items WHERE factura_id = :id'
        );
        $stmt->execute([':id' => $facturaId]);
        $row = $stmt->fetch() ?: ['monto' => 0, 'itbis' => 0];
        return ['monto' => (float) $row['monto'], 'itbis' => (float) $row['itbis']];
    }

    private function mapFactura(array $f, array &$advertencias): array
    {
        $esNota = in_array((string) $f['tipo_ecf'], ['33', '34'], true);
        $ncf    = (string) ($f['e_ncf'] ?: $f['NCF'] ?: '');
        $esEcf  = ((string) ($f['e_ncf'] ?? '')) !== '';
        $rnc    = preg_replace('/\D/', '', (string) ($f['cliente_rnc'] ?? ''));

        $tot   = $this->itemsTotales((int) $f['id']);
        $monto = $tot['monto'];
        $itbis = $tot['itbis'];
        if ($monto == 0.0 && (float) $f['total'] > 0) {
            // Factura sin lineas: usar total (sin desglose de ITBIS disponible).
            $monto = (float) $f['total'];
        }
        $totalConItbis = ($monto + $itbis) > 0 ? ($monto + $itbis) : (float) $f['total'];

        $x = $this->extractFromXml((string) ($f['xml_firmado'] ?? ''));
        $formas = $this->resolverFormas($x, $esEcf, $totalConItbis);
        $r2 = static fn ($v) => round((float) $v, 2); // limpia ruido de float en el preview JSON

        // Advertencias.
        if ($ncf === '') {
            $advertencias[] = "Factura id {$f['id']}: sin NCF/e-NCF.";
        } else {
            $this->validarNcf($ncf, $advertencias);
        }
        if ($rnc === '' && (string) $f['tipo_ecf'] !== '32') {
            // E32 (consumo) menor al limite legal puede ir sin RNC; el resto no.
            $advertencias[] = "NCF {$ncf}: venta sin RNC/Cedula de cliente.";
        }
        if ($esNota && (string) ($f['ncf_modificado'] ?? '') === '') {
            $advertencias[] = "NCF {$ncf} (nota {$f['tipo_ecf']}): falta NCF modificado (campo 4).";
        }
        if (($x['itbis_retenido'] > 0 || $x['retencion_renta'] > 0) && $x['fecha_retencion'] === '') {
            $advertencias[] = "NCF {$ncf}: tiene retencion (ITBIS/ISR) pero falta Fecha de Retencion (campo 7).";
        }

        return [
            // display (no van al TXT)
            'razon_social'      => (string) ($f['cliente_razon'] ?: $f['client_name'] ?: ''),
            'tipo_comprobante'  => $f['tipo_ecf'] !== null ? ('E' . $f['tipo_ecf']) : 'NCF',
            'estado_dgii'       => (string) ($f['estado_dgii'] ?? ''),
            // 23 campos 607
            'rnc'               => $rnc,                                            // 1
            'tipo_id'           => $rnc === '' ? '' : $this->tipoIdentificacion($rnc), // 2
            'ncf'               => $ncf,                                            // 3
            'ncf_modificado'    => $esNota ? (string) ($f['ncf_modificado'] ?? '') : '', // 4
            'tipo_ingreso'      => self::TIPO_INGRESO_DEFAULT,                      // 5
            'fecha_comprobante' => $this->fechaDgii($f['date']),                    // 6
            'fecha_retencion'   => $x['fecha_retencion'],                          // 7
            'monto_facturado'   => $r2($monto),                                    // 8
            'itbis_facturado'   => $r2($itbis),                                    // 9
            'itbis_retenido'    => $r2($x['itbis_retenido']),                      // 10
            'itbis_percibido'   => 0.0,                                            // 11
            'retencion_renta'   => $r2($x['retencion_renta']),                     // 12
            'isr_percibido'     => 0.0,                                            // 13
            'isc'               => $r2($x['isc']),                                 // 14
            'otros_impuestos'   => $r2($x['otros_impuestos']),                     // 15
            'propina_legal'     => $r2($x['propina']),                             // 16
            'efectivo'          => $r2($formas['efectivo']),                       // 17
            'cheque_transf'     => $r2($formas['cheque_transf']),                  // 18
            'tarjeta'           => $r2($formas['tarjeta']),                        // 19
            'credito'           => $r2($formas['credito']),                        // 20
            'bonos'             => $r2($formas['bonos']),                          // 21
            'permuta'           => $r2($formas['permuta']),                        // 22
            'otras'             => $r2($formas['otras']),                          // 23
        ];
    }

    /** Distribuye el monto en las 7 columnas de forma de pago del 607. */
    private function resolverFormas(array $x, bool $esEcf, float $total): array
    {
        $base = ['efectivo'=>0.0,'cheque_transf'=>0.0,'tarjeta'=>0.0,'credito'=>0.0,'bonos'=>0.0,'permuta'=>0.0,'otras'=>0.0];
        if ($x['tiene_formas']) {
            return array_merge($base, $x['formas']); // desglose real del XML
        }
        // Sin TablaFormasPago: todo el total en una sola columna.
        $field = self::FORMA_PAGO_DEFAULT_FIELD;
        if ($esEcf && $x['tipo_pago'] !== null) {
            $map = ['1' => 'efectivo', '2' => 'credito', '3' => 'otras']; // TipoPago e-CF
            $field = $map[(string) $x['tipo_pago']] ?? self::FORMA_PAGO_DEFAULT_FIELD;
        }
        $base[$field] = $total;
        return $base;
    }

    /** FormaPago DGII (1-8) -> columna 607. */
    private function formaPagoField(string $code): string
    {
        switch ($code) {
            case '1': return 'efectivo';       // 17
            case '2': return 'cheque_transf';  // 18
            case '3': return 'tarjeta';        // 19
            case '4': return 'credito';        // 20
            case '5': return 'bonos';          // 21
            case '6': return 'permuta';        // 22
            case '7': // Nota de credito
            case '8': // Otras
            default:  return 'otras';          // 23
        }
    }

    /**
     * Parsea del e-CF firmado las formas de pago y los impuestos/retenciones del 607.
     * NOTA DE ADAPTACION: si tu e-CF usa nombres de tag distintos para ISC / otros
     * impuestos, ajusta los getFloat() de abajo. Los ausentes devuelven 0 (no rompe).
     */
    private function extractFromXml(string $xml): array
    {
        $empty = [
            'tiene_formas' => false, 'formas' => [], 'tipo_pago' => null,
            'itbis_retenido' => 0.0, 'retencion_renta' => 0.0, 'isc' => 0.0,
            'otros_impuestos' => 0.0, 'propina' => 0.0, 'fecha_retencion' => '',
        ];
        if (trim($xml) === '') {
            return $empty;
        }
        $doc  = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok   = $doc->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok) {
            return $empty;
        }
        $v = new IncomingXmlValidator();

        // TablaFormasPago/FormaDePago -> { FormaPago(1-8), MontoPago }.
        $formas = ['efectivo'=>0.0,'cheque_transf'=>0.0,'tarjeta'=>0.0,'credito'=>0.0,'bonos'=>0.0,'permuta'=>0.0,'otras'=>0.0];
        $tieneFormas = false;
        foreach ($doc->getElementsByTagName('FormaDePago') as $fp) {
            $codeNodes  = $fp->getElementsByTagName('FormaPago');
            $montoNodes = $fp->getElementsByTagName('MontoPago');
            if ($codeNodes->length === 0 || $montoNodes->length === 0) {
                continue;
            }
            $monto = (float) $montoNodes->item(0)->textContent;
            if ($monto == 0.0) {
                continue;
            }
            $formas[$this->formaPagoField(trim($codeNodes->item(0)->textContent))] += $monto;
            $tieneFormas = true;
        }

        $fechaRet = $v->getText($doc, 'FechaRetencion');
        return [
            'tiene_formas'    => $tieneFormas,
            'formas'          => $formas,
            'tipo_pago'       => $v->getText($doc, 'TipoPago'),
            'itbis_retenido'  => (float) ($v->getFloat($doc, 'TotalITBISRetenido') ?? 0),
            'retencion_renta' => (float) ($v->getFloat($doc, 'TotalISRRetencion') ?? 0),
            'isc'             => (float) ($v->getFloat($doc, 'MontoImpuestoSelectivoConsumo') ?? 0),
            'otros_impuestos' => (float) ($v->getFloat($doc, 'OtrosImpuestosAdicionales') ?? 0),
            'propina'         => (float) ($v->getFloat($doc, 'MontoPropinaLegal') ?? 0),
            'fecha_retencion' => $fechaRet !== null ? $this->fechaDgii($fechaRet) : '',
        ];
    }

    /** AAAAMM -> [primer dia 00:00:00, primer dia del mes siguiente). */
    private function periodoRango(string $periodo): array
    {
        $anio = (int) substr($periodo, 0, 4);
        $mes  = (int) substr($periodo, 4, 2);
        $ini  = sprintf('%04d-%02d-01 00:00:00', $anio, $mes);
        $finExcl = date('Y-m-d 00:00:00', strtotime($ini . ' +1 month'));
        return [$ini, $finExcl];
    }

    private function tipoIdentificacion(string $rnc): string
    {
        return strlen($rnc) === 11 ? '2' : '1';
    }

    private function fechaDgii($fecha): string
    {
        if (empty($fecha)) {
            return '';
        }
        $ts = strtotime((string) $fecha);
        return $ts ? date('Ymd', $ts) : '';
    }

    private function validarNcf(string $ncf, array &$advertencias): void
    {
        $ncf = trim($ncf);
        $okEcf    = (bool) preg_match('/^E\d{12}$/', $ncf);   // e-NCF
        $okLegacy = (bool) preg_match('/^[A-B]\d{10}$/', $ncf); // NCF legacy
        if (!$okEcf && !$okLegacy) {
            $advertencias[] = "NCF {$ncf}: formato no valido o no autorizado — revisar.";
        }
    }
}
