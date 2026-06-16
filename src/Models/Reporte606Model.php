<?php
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../AmbienteResolver.php';
require_once __DIR__ . '/../Utils/FacturacionElectronica/IncomingXmlValidator.php';

/**
 * Reporte606Model — agrega compras/gastos del periodo (AAAAMM) y las normaliza
 * a los 23 campos del Formato 606 DGII. Fuentes: ecf_recibidos + gastos/gasto_items.
 */
class Reporte606Model
{
    private $conexion;

    // Defaults DGII para campos sin fuente en el esquema (editar aqui si cambia el negocio).
    const TIPO_BIENES_SERVICIOS_DEFAULT = '09'; // 09 = Compras/gastos parte del costo de venta
    const FORMA_PAGO_DEFAULT            = '04'; // 04 = Compra a credito

    public function __construct()
    {
        $this->conexion = Database::getInstance()->getConnection();
    }

    /** Datos del informante del 606 (emisor_config id=1). */
    public function getEmisor(): ?array
    {
        $stmt = $this->conexion->prepare('SELECT rnc, razon_social FROM emisor_config WHERE id = 1');
        $stmt->execute();
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Todas las transacciones del periodo normalizadas a 23 campos + advertencias.
     * @return array{registros: array<int,array>, advertencias: array<int,string>}
     */
    public function getCompras(string $periodo): array
    {
        [$ini, $fin] = $this->periodoRango($periodo);
        $ambiente = AmbienteResolver::active();

        $registros = [];
        $advertencias = [];

        foreach ($this->fetchEcfRecibidos($ini, $fin, $ambiente) as $r) {
            $registros[] = $this->mapEcfRecibido($r, $advertencias);
        }
        foreach ($this->fetchGastos($ini, $fin, $ambiente) as $g) {
            $registros[] = $this->mapGasto($g, $advertencias);
        }

        return ['registros' => $registros, 'advertencias' => $advertencias];
    }

    // ---------------------------------------------------------------- fuentes

    private function fetchEcfRecibidos(string $ini, string $fin, ?string $ambiente): array
    {
        $sql = "SELECT id, tipo_ecf, e_ncf, rnc_emisor, razon_social_emisor,
                       monto_total, fecha_emision, estado, validacion_firma, xml_firmado
                FROM ecf_recibidos
                WHERE fecha_emision BETWEEN :ini AND :fin
                  AND estado IN ('ACEPTADO','RECIBIDO')";
        if ($ambiente !== null) {
            $sql .= ' AND ambiente = :ambiente';
        }
        $sql .= ' ORDER BY fecha_emision ASC, id ASC';

        $stmt = $this->conexion->prepare($sql);
        $stmt->bindValue(':ini', $ini);
        $stmt->bindValue(':fin', $fin);
        if ($ambiente !== null) {
            $stmt->bindValue(':ambiente', $ambiente);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    private function fetchGastos(string $ini, string $fin, ?string $ambiente): array
    {
        // gastos.ambiente puede ser NULL en compras recibidas (sin emision) -> incluirlos.
        $sql = "SELECT id, tipo_gasto, ncf, rnc_proveedor, nombre_proveedor,
                       fecha, subtotal, itbis, total, categoria, es_auto_emision
                FROM gastos
                WHERE fecha BETWEEN :ini AND :fin";
        if ($ambiente !== null) {
            $sql .= ' AND (ambiente = :ambiente OR ambiente IS NULL)';
        }
        $sql .= ' ORDER BY fecha ASC, id ASC';

        $stmt = $this->conexion->prepare($sql);
        $stmt->bindValue(':ini', $ini);
        $stmt->bindValue(':fin', $fin);
        if ($ambiente !== null) {
            $stmt->bindValue(':ambiente', $ambiente);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** Suma bienes/servicios e ITBIS desde gasto_items (1=Bien, 2=Servicio). */
    private function splitBienesServicios(int $gastoId): array
    {
        $stmt = $this->conexion->prepare(
            'SELECT indicador_bien_servicio AS ind, SUM(subtotal) AS base, SUM(itbis_amount) AS itbis
             FROM gasto_items WHERE gasto_id = :id GROUP BY indicador_bien_servicio'
        );
        $stmt->execute([':id' => $gastoId]);

        $bienes = 0.0; $servicios = 0.0; $itbis = 0.0; $hay = false;
        foreach ($stmt->fetchAll() as $row) {
            $hay = true;
            $itbis += (float) $row['itbis'];
            if ((string) $row['ind'] === '1') {
                $bienes += (float) $row['base'];
            } else {
                $servicios += (float) $row['base'];
            }
        }
        return ['bienes' => $bienes, 'servicios' => $servicios, 'itbis' => $itbis, 'hay_items' => $hay];
    }

    // --------------------------------------------------------------- mappers

    private function mapGasto(array $g, array &$advertencias): array
    {
        $split = $this->splitBienesServicios((int) $g['id']);
        $bienes = $split['bienes'];
        $servicios = $split['servicios'];
        if (!$split['hay_items']) {
            // Gasto sin lineas: E43 (gastos menores) -> servicios; resto -> bienes.
            if ((string) $g['tipo_gasto'] === 'E43') {
                $servicios = (float) $g['subtotal'];
            } else {
                $bienes = (float) $g['subtotal'];
            }
        }
        $rnc = preg_replace('/\D/', '', (string) $g['rnc_proveedor']);
        $this->validarNcf((string) $g['ncf'], $rnc, $advertencias);

        return [
            'rnc'                    => $rnc,                                // 1
            'tipo_id'                => $this->tipoIdentificacion($rnc),     // 2
            'tipo_bienes_serv'       => self::TIPO_BIENES_SERVICIOS_DEFAULT, // 3
            'ncf'                    => (string) $g['ncf'],                  // 4
            'ncf_modificado'         => '',                                 // 5
            'fecha_comprobante'      => $this->fechaDgii($g['fecha']),       // 6
            'fecha_pago'             => '',                                 // 7
            'monto_servicios'        => $servicios,                         // 8
            'monto_bienes'           => $bienes,                            // 9
            'total_facturado'        => $bienes + $servicios,               // 10
            'itbis_facturado'        => (float) $g['itbis'],                // 11
            'itbis_retenido'         => 0.0,                                // 12
            'itbis_proporcionalidad' => 0.0,                                // 13
            'itbis_costo'            => 0.0,                                // 14
            'itbis_adelantar'        => 0.0,                                // 15
            'itbis_percibido'        => 0.0,                                // 16
            'tipo_retencion_isr'     => '',                                 // 17
            'retencion_renta'        => 0.0,                                // 18
            'isr_percibido'          => 0.0,                                // 19
            'isc'                    => 0.0,                                // 20
            'otros_impuestos'        => 0.0,                                // 21
            'propina_legal'          => 0.0,                                // 22
            'forma_pago'             => self::FORMA_PAGO_DEFAULT,            // 23
        ];
    }

    private function mapEcfRecibido(array $r, array &$advertencias): array
    {
        $x   = $this->extractFromXml((string) ($r['xml_firmado'] ?? ''));
        $rnc = preg_replace('/\D/', '', (string) $r['rnc_emisor']);

        if (($r['validacion_firma'] ?? null) !== 'OK') {
            $advertencias[] = "e-CF {$r['e_ncf']} (RNC {$rnc}): firma '" .
                ($r['validacion_firma'] ?? 'NULL') . "' — verificar antes de declarar.";
        }
        $this->validarNcf((string) $r['e_ncf'], $rnc, $advertencias);

        $total     = $x['total'] ?? (float) $r['monto_total'];
        $bienes    = $x['bienes'];
        $servicios = $x['servicios'];
        if ($bienes == 0.0 && $servicios == 0.0) {
            $bienes = $total; // XML sin desglose -> todo a bienes (fallback)
        }

        $itbisRet  = $x['itbis_retenido'];
        $isrRet    = $x['retencion_renta'];
        $fechaPago = $x['fecha_pago'];
        // Regla DGII: si hay ITBIS retenido (12) o retencion renta (18), fecha pago (7) obligatoria.
        if (($itbisRet > 0 || $isrRet > 0) && $fechaPago === '') {
            $advertencias[] = "e-CF {$r['e_ncf']}: tiene retencion (ITBIS/ISR) pero falta " .
                "Fecha de Pago (campo 7 obligatorio).";
        }

        $totalFacturado = ($bienes + $servicios) > 0 ? ($bienes + $servicios) : $total;

        return [
            'rnc'                    => $rnc,                                // 1
            'tipo_id'                => $this->tipoIdentificacion($rnc),     // 2
            'tipo_bienes_serv'       => self::TIPO_BIENES_SERVICIOS_DEFAULT, // 3
            'ncf'                    => (string) $r['e_ncf'],                // 4
            'ncf_modificado'         => $x['ncf_modificado'],               // 5
            'fecha_comprobante'      => $this->fechaDgii($r['fecha_emision']), // 6
            'fecha_pago'             => $fechaPago,                          // 7
            'monto_servicios'        => $servicios,                         // 8
            'monto_bienes'           => $bienes,                            // 9
            'total_facturado'        => $totalFacturado,                    // 10
            'itbis_facturado'        => $x['itbis_facturado'],              // 11
            'itbis_retenido'         => $itbisRet,                          // 12
            'itbis_proporcionalidad' => 0.0,                                // 13
            'itbis_costo'            => 0.0,                                // 14
            'itbis_adelantar'        => 0.0,                                // 15
            'itbis_percibido'        => 0.0,                                // 16
            'tipo_retencion_isr'     => '',                                 // 17
            'retencion_renta'        => $isrRet,                            // 18
            'isr_percibido'          => 0.0,                                // 19
            'isc'                    => $x['isc'],                          // 20
            'otros_impuestos'        => 0.0,                                // 21
            'propina_legal'          => $x['propina'],                      // 22
            'forma_pago'             => $x['forma_pago'] !== '' ? $x['forma_pago'] : self::FORMA_PAGO_DEFAULT, // 23
        ];
    }

    // ------------------------------------------------------- parser de XML e-CF

    /**
     * Extrae del e-CF firmado (sin re-verificar la firma) los montos del 606.
     * getElementsByTagName es namespace-agnostic (el XSD del e-CF no declara ns),
     * igual criterio que IncomingXmlValidator::getText.
     */
    private function extractFromXml(string $xml): array
    {
        $empty = [
            'bienes' => 0.0, 'servicios' => 0.0, 'total' => null,
            'itbis_facturado' => 0.0, 'itbis_retenido' => 0.0, 'retencion_renta' => 0.0,
            'isc' => 0.0, 'propina' => 0.0, 'fecha_pago' => '', 'forma_pago' => '',
            'ncf_modificado' => '',
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

        $v = new IncomingXmlValidator(); // reutiliza getText/getFloat/getDate

        // Desglose bienes/servicios por Item (IndicadorBienoServicio: 1=Bien, 2=Servicio).
        $bienes = 0.0; $servicios = 0.0;
        foreach ($doc->getElementsByTagName('Item') as $item) {
            $montoNodes = $item->getElementsByTagName('MontoItem');
            if ($montoNodes->length === 0) {
                continue;
            }
            $indNodes = $item->getElementsByTagName('IndicadorBienoServicio');
            $ind   = $indNodes->length ? trim($indNodes->item(0)->textContent) : '1';
            $monto = (float) $montoNodes->item(0)->textContent;
            if ($ind === '2') {
                $servicios += $monto;
            } else {
                $bienes += $monto;
            }
        }

        $fechaPago = $v->getDate($doc, 'FechaPago');

        return [
            'bienes'          => $bienes,
            'servicios'       => $servicios,
            'total'           => $v->getFloat($doc, 'MontoTotal'),
            'itbis_facturado' => (float) ($v->getFloat($doc, 'TotalITBIS') ?? 0),
            'itbis_retenido'  => (float) ($v->getFloat($doc, 'TotalITBISRetenido') ?? 0),
            'retencion_renta' => (float) ($v->getFloat($doc, 'TotalISRRetencion') ?? 0),
            'isc'             => (float) ($v->getFloat($doc, 'MontoImpuestoSelectivoConsumo') ?? 0),
            'propina'         => (float) ($v->getFloat($doc, 'MontoPropinaLegal') ?? 0),
            'fecha_pago'      => $fechaPago !== null ? $this->fechaDgii($fechaPago) : '',
            'forma_pago'      => (string) ($v->getText($doc, 'FormaPago') ?? ''),
            'ncf_modificado'  => (string) ($v->getText($doc, 'NCFModificado') ?? ''),
        ];
    }

    // ------------------------------------------------------------- auxiliares

    /** AAAAMM -> [primer dia, ultimo dia] del mes. */
    private function periodoRango(string $periodo): array
    {
        $anio = (int) substr($periodo, 0, 4);
        $mes  = (int) substr($periodo, 4, 2);
        $ini  = sprintf('%04d-%02d-01', $anio, $mes);
        $fin  = date('Y-m-t', strtotime($ini));
        return [$ini, $fin];
    }

    /** 9 digitos = RNC (1); 11 = Cedula (2). */
    private function tipoIdentificacion(string $rnc): string
    {
        return strlen($rnc) === 11 ? '2' : '1';
    }

    /** Cualquier fecha -> AAAAMMDD (vacio si no parsea). */
    private function fechaDgii($fecha): string
    {
        if (empty($fecha)) {
            return '';
        }
        $ts = strtotime((string) $fecha);
        return $ts ? date('Ymd', $ts) : '';
    }

    /** Marca NCF/e-NCF vacios o con formato no autorizado como advertencia. */
    private function validarNcf(string $ncf, string $rnc, array &$advertencias): void
    {
        $ncf = trim($ncf);
        if ($ncf === '') {
            $advertencias[] = "RNC {$rnc}: comprobante sin NCF/e-NCF.";
            return;
        }
        $okEcf    = (bool) preg_match('/^E\d{12}$/', $ncf);   // e-NCF: E + tipo(2) + sec(10)
        $okLegacy = (bool) preg_match('/^[A-B]\d{10}$/', $ncf); // NCF: B/A + serie(2) + sec(8)
        if (!$okEcf && !$okLegacy) {
            $advertencias[] = "NCF {$ncf} (RNC {$rnc}): formato no valido o no autorizado — revisar.";
        }
    }
}
