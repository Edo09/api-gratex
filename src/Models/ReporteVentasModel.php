<?php
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../MasterDatabase.php';
require_once __DIR__ . '/../AmbienteResolver.php';

/**
 * ReporteVentasModel — ventas del periodo, en detalle o agrupadas.
 *
 * Es un reporte de GESTION, no fiscal: responde "cuanto vendi y a quien", no
 * "que le declaro a la DGII". Por eso incluye las facturas simples (que el 607
 * excluye por no ser comprobantes fiscales) y las facturas enviadas que aun no
 * tienen respuesta de la DGII: son ventas reales del negocio aunque el acuse
 * todavia no haya llegado. Para lo fiscal esta el 607, que si exige ACEPTADO.
 *
 * Qué cuenta como venta (y qué no):
 *   - E31, E32, E44, E45, E46 y las facturas simples (tipo_ecf NULL): SUMAN.
 *   - E33 nota de debito: SUMA. E34 nota de credito: RESTA.
 *   - E41 (compras), E43 (gastos menores) y E47 (pagos al exterior) NO entran:
 *     son documentos que el emisor genera por lo que COMPRA, no por lo que
 *     vende. Meterlos inflaria las ventas con gasto propio.
 *   - Los RECHAZADOS quedan fuera: nunca llegaron a ser una venta valida.
 */
class ReporteVentasModel
{
    /** e-CF que representan una venta. */
    private const TIPOS_VENTA = ['31', '32', '44', '45', '46'];
    /** Notas que ajustan ventas ya hechas. */
    private const TIPOS_NOTA = ['33', '34'];
    /** Nota de credito: resta del total vendido. */
    private const TIPO_RESTA = '34';

    /** Catalogo de tipo_pago (ver docs/api/facturas.md). */
    public const FORMAS_PAGO = [
        1 => 'Contado',
        2 => 'Crédito',
        3 => 'Gratuito',
        4 => 'Permuta',
        5 => 'Otros',
    ];

    /** Agrupaciones disponibles. 'documento' = detalle sin agrupar. */
    public const AGRUPACIONES = ['documento', 'cliente', 'forma_pago', 'usuario'];

    private $conexion;
    /** Cache por instancia: el mapa de usuarios vive en otra DB, no se pide dos veces. */
    private ?array $usuarios = null;

    public function __construct()
    {
        $this->conexion = Database::getInstance()->getConnection();
    }

    /**
     * @param string $desde  YYYY-MM-DD (inclusive)
     * @param string $hasta  YYYY-MM-DD (inclusive)
     * @return array{filas:array<int,array>, totales:array, advertencias:array<int,string>}
     */
    public function reporte(string $desde, string $hasta, string $agrupar): array
    {
        $documentos = $this->fetchDocumentos($desde, $hasta);
        $advertencias = [];

        $sinLineas = 0;
        foreach ($documentos as $d) {
            if ($d['sin_lineas']) {
                $sinLineas++;
            }
        }
        if ($sinLineas > 0) {
            $advertencias[] = "{$sinLineas} documento(s) sin líneas guardadas: su base se tomó del total "
                . 'y no tienen desglose de ITBIS.';
        }

        $filas = $agrupar === 'documento' ? $documentos : $this->agrupar($documentos, $agrupar);

        return [
            'filas'        => $filas,
            'totales'      => $this->totales($documentos),
            'advertencias' => $advertencias,
        ];
    }

    /**
     * Documentos del rango, ya normalizados con su signo.
     *
     * El desglose base/ITBIS sale de factura_items en un solo LEFT JOIN agregado
     * (nada de una consulta por factura). El total manda `facturas.total`, que es
     * el MontoTotal firmado; las lineas solo reparten ese total entre base e ITBIS.
     */
    private function fetchDocumentos(string $desde, string $hasta): array
    {
        $tipos = array_merge(self::TIPOS_VENTA, self::TIPOS_NOTA);
        $marcas = implode(',', array_fill(0, count($tipos), '?'));
        $ambiente = AmbienteResolver::active();

        $sql = "SELECT f.id, f.no_factura, f.date, f.tipo_ecf, f.e_ncf, f.NCF,
                       f.client_id, f.client_name, f.total, f.tipo_pago, f.user_id,
                       f.estado_dgii, f.ambiente_dgii,
                       c.company_name, c.rnc AS cliente_rnc,
                       COALESCE(it.base, 0)  AS base,
                       COALESCE(it.itbis, 0) AS itbis,
                       it.factura_id         AS tiene_lineas
                FROM facturas f
                LEFT JOIN clients c ON c.id = f.client_id
                LEFT JOIN (
                    SELECT factura_id,
                           SUM(subtotal)     AS base,
                           SUM(itbis_amount) AS itbis
                    FROM factura_items
                    GROUP BY factura_id
                ) it ON it.factura_id = f.id
                WHERE f.date >= ? AND f.date < ?
                  AND (f.tipo_ecf IS NULL OR f.tipo_ecf IN ({$marcas}))
                  AND f.estado_dgii NOT LIKE '%RECHAZADO%'";

        $params = [$desde . ' 00:00:00', $this->diaSiguiente($hasta)];
        foreach ($tipos as $t) {
            $params[] = $t;
        }

        // Mismo criterio que listados y stats: con un ambiente activo, la data de
        // otro ambiente (pruebas de certificacion) no puede contaminar los montos.
        // Las simples no tienen ambiente, por eso el OR.
        if ($ambiente !== null) {
            $sql .= ' AND (f.tipo_ecf IS NULL OR f.ambiente_dgii = ?)';
            $params[] = $ambiente;
        }
        $sql .= ' ORDER BY f.date, f.id';

        $stmt = $this->conexion->prepare($sql);
        $stmt->execute($params);

        $usuarios = $this->nombresUsuarios();
        $docs = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $tipo = $f['tipo_ecf'] !== null ? (string) $f['tipo_ecf'] : null;
            $signo = $tipo === self::TIPO_RESTA ? -1 : 1;

            $total = (float) $f['total'];
            $itbis = (float) $f['itbis'];
            $base  = (float) $f['base'];
            $sinLineas = $f['tiene_lineas'] === null;
            if ($sinLineas) {
                // Sin lineas no hay desglose posible: todo el total va a base.
                $base = $total;
                $itbis = 0.0;
            }

            $uid = $f['user_id'] !== null ? (int) $f['user_id'] : null;
            $docs[] = [
                'id'            => (int) $f['id'],
                'fecha'         => (string) $f['date'],
                'documento'     => (string) ($f['e_ncf'] ?: $f['NCF'] ?: $f['no_factura']),
                'tipo'          => $tipo !== null ? 'E' . $tipo : 'Simple',
                'estado'        => (string) $f['estado_dgii'],
                'client_id'     => $f['client_id'] !== null ? (int) $f['client_id'] : null,
                'cliente'       => $this->nombreCliente($f),
                'cliente_rnc'   => (string) ($f['cliente_rnc'] ?? ''),
                'tipo_pago'     => (int) $f['tipo_pago'],
                'forma_pago'    => self::FORMAS_PAGO[(int) $f['tipo_pago']] ?? ('Tipo ' . (int) $f['tipo_pago']),
                'user_id'       => $uid,
                'usuario'       => $uid !== null ? ($usuarios[$uid] ?? ('Usuario #' . $uid)) : 'Sin usuario',
                'es_devolucion' => $signo === -1,
                'sin_lineas'    => $sinLineas,
                'base'          => round($signo * $base, 2),
                'itbis'         => round($signo * $itbis, 2),
                'total'         => round($signo * $total, 2),
            ];
        }
        return $docs;
    }

    /**
     * Agrupa los documentos por cliente, forma de pago o usuario.
     *
     * Se agrupa en PHP y no en SQL a propósito: el signo de las notas de crédito
     * y el arreglo de las facturas sin líneas ya se aplicaron arriba, así que
     * agrupar aquí garantiza que el detalle y los agrupados cuadren siempre. Un
     * GROUP BY paralelo en SQL tendría que repetir esas reglas y podría divergir.
     */
    private function agrupar(array $documentos, string $por): array
    {
        [$campoClave, $campoEtiqueta] = match ($por) {
            'cliente'    => ['client_id', 'cliente'],
            'forma_pago' => ['tipo_pago', 'forma_pago'],
            'usuario'    => ['user_id', 'usuario'],
        };

        $grupos = [];
        foreach ($documentos as $d) {
            // Un cliente sin id (consumidor final) agrupa por nombre: si no, todos
            // los consumidores finales caerían en el mismo saco.
            $clave = $d[$campoClave] ?? ('sn:' . $d[$campoEtiqueta]);
            if (!isset($grupos[$clave])) {
                $grupos[$clave] = [
                    'clave'     => $d[$campoClave],
                    'etiqueta'  => $d[$campoEtiqueta],
                    'cantidad'  => 0,
                    'base'      => 0.0,
                    'itbis'     => 0.0,
                    'total'     => 0.0,
                ];
                if ($por === 'cliente') {
                    $grupos[$clave]['cliente_rnc'] = $d['cliente_rnc'];
                }
            }
            $grupos[$clave]['cantidad']++;
            $grupos[$clave]['base']  += $d['base'];
            $grupos[$clave]['itbis'] += $d['itbis'];
            $grupos[$clave]['total'] += $d['total'];
        }

        foreach ($grupos as &$g) {
            $g['base']  = round($g['base'], 2);
            $g['itbis'] = round($g['itbis'], 2);
            $g['total'] = round($g['total'], 2);
        }
        unset($g);

        // De mayor a menor venta: lo primero que se mira en un reporte asi.
        usort($grupos, static fn($a, $b) => $b['total'] <=> $a['total']);
        return array_values($grupos);
    }

    private function totales(array $documentos): array
    {
        $t = ['cantidad' => count($documentos), 'base' => 0.0, 'itbis' => 0.0, 'total' => 0.0];
        foreach ($documentos as $d) {
            $t['base']  += $d['base'];
            $t['itbis'] += $d['itbis'];
            $t['total'] += $d['total'];
        }
        foreach (['base', 'itbis', 'total'] as $k) {
            $t[$k] = round($t[$k], 2);
        }
        return $t;
    }

    private function nombreCliente(array $f): string
    {
        foreach ([$f['company_name'] ?? '', $f['client_name'] ?? ''] as $n) {
            if (trim((string) $n) !== '') {
                return (string) $n;
            }
        }
        return 'Consumidor Final';
    }

    /**
     * id => nombre de los usuarios del tenant. En multi-tenant viven en el
     * MASTER, asi que no hay JOIN posible: se traen aparte y se mapean en PHP.
     * Si el master no responde, el reporte sale igual con "Usuario #id" — un
     * reporte de ventas no puede caerse porque falten los nombres.
     */
    private function nombresUsuarios(): array
    {
        if ($this->usuarios !== null) {
            return $this->usuarios;
        }
        try {
            $multi = filter_var(
                getenv('MULTI_TENANT_ENABLED') ?: ($_ENV['MULTI_TENANT_ENABLED'] ?? false),
                FILTER_VALIDATE_BOOLEAN
            );
            if (!$multi) {
                $stmt = $this->conexion->query('SELECT id, name, last_name FROM users');
            } else {
                $tenant = class_exists('TenantResolver') ? TenantResolver::current() : null;
                $conn = MasterDatabase::getInstance()->getConnection();
                if ($tenant !== null && !empty($tenant['id'])) {
                    $stmt = $conn->prepare('SELECT id, name, last_name FROM users WHERE tenant_id = :tid');
                    $stmt->execute([':tid' => (int) $tenant['id']]);
                } else {
                    $stmt = $conn->query('SELECT id, name, last_name FROM users');
                }
            }
            $map = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
                $nombre = trim(($u['name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
                $map[(int) $u['id']] = $nombre !== '' ? $nombre : ('Usuario #' . $u['id']);
            }
            return $this->usuarios = $map;
        } catch (\Throwable $e) {
            error_log('[ReporteVentas] no se pudieron leer los nombres de usuario: ' . $e->getMessage());
            return $this->usuarios = [];
        }
    }

    /** Fin de rango exclusivo: incluye todo el dia `hasta`, con hora. */
    private function diaSiguiente(string $hasta): string
    {
        return date('Y-m-d 00:00:00', strtotime($hasta . ' +1 day'));
    }
}
