<?php
require_once __DIR__ . '/PosError.php';
require_once __DIR__ . '/PosAutorizacion.php';
require_once __DIR__ . '/PosVenta.php';
require_once __DIR__ . '/../Models/posModel.php';

/**
 * Cierre de turno (docs/specs/pos.md K4, K6, K7, K8).
 *
 *  - Conteo a ciegas por denominacion: el cajero cuenta sin ver lo esperado.
 *    El esperado y la diferencia salen recien en la respuesta del cierre, y el
 *    conteo no se puede cambiar despues (el turno ya quedo cerrado).
 *  - esperado = fondo + ventas en efectivo - devoluciones en efectivo (K7). Una
 *    venta con envio pendiente cuenta: el dinero ya entro. Tarjeta y
 *    transferencia son informativos.
 *  - El turno de otro empleado lo cierra un supervisor (su sesion o un permiso
 *    con su PIN, PosAutorizacion). El cierre queda a nombre de quien conto.
 *  - El reporte (K8) se guarda como foto en pos_turnos.totales_json: lo que se
 *    imprime al cerrar y lo que reimprime app.* son exactamente lo mismo.
 *
 * Todos los montos del reporte van en centavos enteros.
 */
final class PosCierre
{
    /** Billetes y monedas dominicanos, del mayor al menor (en pesos). */
    public const DENOMINACIONES = [2000, 1000, 500, 200, 100, 50, 20, 25, 10, 5, 1];
    public const BILLETES = [2000, 1000, 500, 200, 100, 50, 20];
    private const MAX_UNIDADES = 100000;
    private const MAX_OTROS_CENTAVOS = 10000000;

    /**
     * Cierra el turno abierto de la caja.
     *
     * @param array $sesionEmpleado quien esta en el POS
     * @return array{turno: array, reporte: array}
     */
    public static function cerrar(posModel $pos, array $equipo, array $caja, array $sesionEmpleado, array $body, int $tenantId): array
    {
        $turno = $pos->turnoAbiertoDeCaja($caja['id']);
        if ($turno === null) {
            throw new PosError('La caja no tiene un turno abierto.', 409, 'SIN_TURNO');
        }
        if ((int) ($body['turno_id'] ?? 0) !== $turno['id']) {
            // Lo que se conto era para otro turno (se cerro y se abrio otro mientras tanto).
            throw new PosError('El turno de la caja cambió. Revisa y cuenta de nuevo.', 409, 'TURNO_CAMBIO', ['turno_caja' => $turno]);
        }

        // --- Quien cierra (K4) -------------------------------------------------
        if ($turno['empleado_id'] === $sesionEmpleado['id'] || $sesionEmpleado['rol'] === 'supervisor') {
            $cierra = ['id' => $sesionEmpleado['id'], 'nombre' => $sesionEmpleado['nombre'], 'rol' => $sesionEmpleado['rol']];
        } else {
            $sup = PosAutorizacion::verificar($body['permiso'] ?? null, 'cerrar_turno', $turno['id'], $equipo['id'], $tenantId);
            $actual = $pos->empleadoPorId($sup['id']);
            if ($actual === null || !$actual['activo'] || $actual['rol'] !== 'supervisor') {
                throw new PosError('Ese supervisor ya no puede autorizar. Pide la autorización a otro.', 403, 'PERMISO_INVALIDO');
            }
            $cierra = ['id' => $actual['id'], 'nombre' => $actual['nombre'], 'rol' => 'supervisor'];
        }

        $conteo = self::conteo($body['conteo'] ?? null);
        $contado = self::contadoCentavos($conteo);

        // Una venta que se esta emitiendo en este turno termina antes de contar.
        if (!$pos->bloquearTurno($turno['id'], 45)) {
            throw new PosError('Hay una venta emitiéndose en esta caja. Espera unos segundos y confirma de nuevo.', 409, 'TURNO_OCUPADO');
        }
        try {
            $reporte = self::reporte($pos, $turno, $caja, $cierra, $conteo, $contado);
            $ok = $pos->cerrarTurno($turno['id'], $cierra['id'], $conteo, $contado / 100,
                $reporte['esperado_centavos'] / 100, $reporte['diferencia_centavos'] / 100, $reporte);
            if (!$ok) {
                throw new PosError('Ese turno ya se cerró.', 409, 'TURNO_YA_CERRADO');
            }
        } finally {
            try {
                $pos->liberarTurno($turno['id']);
            } catch (Throwable $e) {
                // Se suelta solo al terminar el request.
            }
        }

        AuditLogger::log([
            'module' => 'pos', 'action' => 'POS_TURNO_CERRADO', 'entity_type' => 'pos_turno', 'entity_id' => $turno['id'],
            'new_values' => [
                'caja_id' => $caja['id'], 'empleado_id' => $turno['empleado_id'], 'empleado' => $turno['empleado_nombre'],
                'cerrado_por' => $cierra['id'], 'cerrado_por_nombre' => $cierra['nombre'],
                'esperado' => $reporte['esperado_centavos'] / 100, 'contado' => $contado / 100,
                'diferencia' => $reporte['diferencia_centavos'] / 100,
            ],
            'description' => $cierra['id'] === $turno['empleado_id']
                ? 'Turno del POS cerrado por su cajero.'
                : 'Turno del POS cerrado por un supervisor (turno de otro empleado).',
            'success' => true,
        ]);

        $cerrado = $pos->turnoPorId($turno['id']);
        return ['turno' => self::sinReporte($cerrado), 'reporte' => $cerrado['reporte']];
    }

    /** Nota del cierre (una vez, hasta 30 min despues). Devuelve el reporte con la nota. */
    public static function nota(posModel $pos, array $caja, array $body): array
    {
        $nota = trim(preg_replace('/\s+/', ' ', (string) ($body['nota'] ?? '')));
        if ($nota === '' || mb_strlen($nota) > 255) {
            throw new PosError('Escribe la nota (hasta 255 caracteres).', 422, 'NOTA_INVALIDA');
        }
        $turnoId = (int) ($body['turno_id'] ?? 0);
        if (!$pos->guardarNotaTurno($turnoId, $caja['id'], $nota)) {
            throw new PosError('La nota ya no se puede agregar a ese cierre.', 409, 'NOTA_NO_PERMITIDA');
        }
        $turno = $pos->turnoPorId($turnoId);
        return ['turno' => self::sinReporte($turno), 'reporte' => self::conNota($turno)];
    }

    /** El reporte guardado con la nota del turno (la nota se escribe despues de la foto). */
    public static function conNota(array $turno): ?array
    {
        if ($turno['reporte'] === null) {
            return null;
        }
        return ['nota' => $turno['nota']] + $turno['reporte'];
    }

    public static function sinReporte(array $turno): array
    {
        unset($turno['reporte']);
        return $turno;
    }

    /**
     * Conteo valido: {"2000": n, ..., "1": n, "otros_centavos": n}. Faltantes = 0.
     * @return array<string,int>
     */
    public static function conteo($crudo): array
    {
        if (!is_array($crudo)) {
            throw new PosError('Cuenta el efectivo de la gaveta.', 422, 'CONTEO_INVALIDO');
        }
        $permitidas = array_merge(array_map('strval', self::DENOMINACIONES), ['otros_centavos']);
        foreach (array_keys($crudo) as $k) {
            if (!in_array((string) $k, $permitidas, true)) {
                throw new PosError('El conteo trae una denominación que no existe.', 422, 'CONTEO_INVALIDO');
            }
        }
        $out = [];
        foreach (self::DENOMINACIONES as $d) {
            $n = $crudo[(string) $d] ?? 0;
            if (!is_int($n) || $n < 0 || $n > self::MAX_UNIDADES) {
                throw new PosError("La cantidad de RD\${$d} no es válida.", 422, 'CONTEO_INVALIDO');
            }
            $out[(string) $d] = $n;
        }
        $otros = $crudo['otros_centavos'] ?? 0;
        if (!is_int($otros) || $otros < 0 || $otros > self::MAX_OTROS_CENTAVOS) {
            throw new PosError('El monto de "otros / centavos" no es válido.', 422, 'CONTEO_INVALIDO');
        }
        $out['otros_centavos'] = $otros;
        return $out;
    }

    public static function contadoCentavos(array $conteo): int
    {
        $total = (int) $conteo['otros_centavos'];
        foreach (self::DENOMINACIONES as $d) {
            $total += $d * 100 * (int) $conteo[(string) $d];
        }
        return $total;
    }

    /** Foto del cierre (K8). Todo en centavos. */
    private static function reporte(posModel $pos, array $turno, array $caja, array $cierra, array $conteo, int $contado): array
    {
        $centavos = static fn($pesos) => (int) round(((float) $pesos) * 100);
        $mov = $pos->movimientosDelTurno($turno['id']);

        // Por forma de pago: las ventas siempre con las tres formas (en 0 si no
        // hubo); las devoluciones solo las que tuvieron movimiento.
        $agrupar = static function (string $tipo, bool $todas) use ($mov, $centavos): array {
            $g = ['cantidad' => 0, 'total_centavos' => 0, 'por_forma' => []];
            foreach (PosVenta::FORMAS_PAGO as $forma => $nombre) {
                $f = $mov[$tipo][$forma] ?? ['cantidad' => 0, 'monto' => '0'];
                if ($f['cantidad'] === 0 && !$todas) {
                    continue;
                }
                $g['por_forma'][] = ['forma_pago' => $forma, 'nombre' => $nombre,
                    'cantidad' => $f['cantidad'], 'monto_centavos' => $centavos($f['monto'])];
                $g['cantidad'] += $f['cantidad'];
                $g['total_centavos'] += $centavos($f['monto']);
            }
            return $g;
        };
        $ventas = $agrupar('VENTA', true);
        $devoluciones = $agrupar('DEVOLUCION', false);
        $evento = static function (string $tipo) use ($mov, $centavos): array {
            $f = $mov[$tipo][0] ?? ['cantidad' => 0, 'monto' => '0'];
            return ['cantidad' => $f['cantidad'], 'monto_centavos' => $centavos($f['monto'])];
        };

        $fondo = $centavos($turno['fondo_inicial']);
        $efectivoVentas = $centavos($mov['VENTA'][1]['monto'] ?? 0);
        $efectivoDevoluciones = $centavos($mov['DEVOLUCION'][1]['monto'] ?? 0);
        $esperado = $fondo + $efectivoVentas - $efectivoDevoluciones;

        $comprobantes = [];
        $pendientes = [];
        $rechazadas = [];
        foreach ($pos->ventasCobradasDelTurno($turno['id']) as $v) {
            $tipo = 'E' . $v['tipo_ecf'];
            $comprobantes[$tipo] = ($comprobantes[$tipo] ?? 0) + 1;
            $item = ['e_ncf' => $v['e_ncf'], 'total_centavos' => $centavos($v['total'])];
            if (str_contains((string) $v['estado_dgii'], 'RECHAZADO')) {
                $rechazadas[] = $item;
            } elseif ((int) $v['envio_pendiente'] === 1) {
                $pendientes[] = $item;
            }
        }
        ksort($comprobantes);

        return [
            'version' => 1,
            'turno_id' => $turno['id'],
            'caja' => ['id' => $caja['id'], 'nombre' => $caja['nombre']],
            'empleado' => ['id' => $turno['empleado_id'], 'nombre' => $turno['empleado_nombre']],
            'cerrado_por' => $cierra,
            'abierto_at' => $turno['abierto_at'],
            'cerrado_at' => date('Y-m-d H:i:s'),
            'fondo_centavos' => $fondo,
            'ventas' => $ventas,
            'devoluciones' => $devoluciones,
            'comprobantes' => $comprobantes,
            'canceladas' => $evento('CANCELADA'),
            'lineas_quitadas' => $evento('QUITADA'),
            'pendientes' => $pendientes,
            'rechazadas' => $rechazadas,
            'conteo' => $conteo,
            'efectivo_ventas_centavos' => $efectivoVentas,
            'efectivo_devoluciones_centavos' => $efectivoDevoluciones,
            'esperado_centavos' => $esperado,
            'contado_centavos' => $contado,
            'diferencia_centavos' => $contado - $esperado,
        ];
    }

    /**
     * Venta cancelada o linea quitada (V4). Sin turno propio abierto queda solo
     * en la bitacora (no hay cierre al que sumarla).
     */
    public static function evento(posModel $pos, array $caja, array $sesionEmpleado, array $equipo, array $body): array
    {
        $tipo = ['cancelada' => 'CANCELADA', 'quitada' => 'QUITADA'][(string) ($body['tipo'] ?? '')] ?? null;
        $monto = $body['monto_centavos'] ?? null;
        if ($tipo === null || !is_int($monto) || $monto < 0 || $monto > 100000000) {
            throw new PosError('Evento no válido.', 422, 'EVENTO_INVALIDO');
        }
        $lineas = [];
        foreach (array_slice(is_array($body['lineas'] ?? null) ? $body['lineas'] : [], 0, PosVenta::MAX_LINEAS) as $l) {
            if (is_array($l)) {
                $lineas[] = [
                    'product_id' => is_int($l['product_id'] ?? null) ? $l['product_id'] : null,
                    'nombre' => mb_substr((string) ($l['nombre'] ?? ''), 0, 150),
                    'cantidad' => is_numeric($l['cantidad'] ?? null) ? (float) $l['cantidad'] : null,
                ];
            }
        }
        $turno = $pos->turnoAbiertoDeCaja($caja['id']);
        $propio = $turno !== null && $turno['empleado_id'] === $sesionEmpleado['id'];
        if ($propio) {
            $pos->registrarEvento($turno['id'], $tipo, $monto / 100);
        }
        AuditLogger::log([
            'module' => 'pos', 'action' => $tipo === 'CANCELADA' ? 'POS_VENTA_CANCELADA' : 'POS_LINEA_QUITADA',
            'entity_type' => 'pos_turno', 'entity_id' => $propio ? $turno['id'] : null,
            'new_values' => ['caja_id' => $caja['id'], 'empleado_id' => $sesionEmpleado['id'], 'empleado' => $sesionEmpleado['nombre'],
                'equipo_id' => $equipo['id'], 'monto' => $monto / 100, 'lineas' => $lineas],
            'description' => $tipo === 'CANCELADA' ? 'Venta cancelada en el POS antes de cobrar.' : 'Línea quitada del carrito del POS.',
            'success' => true,
        ]);
        return ['registrado' => $propio];
    }

    /** Ventas cobradas del turno abierto de la caja (K9), las mas nuevas primero. */
    public static function ventasDelTurno(posModel $pos, array $caja): array
    {
        $turno = $pos->turnoAbiertoDeCaja($caja['id']);
        if ($turno === null) {
            return ['turno_caja' => null, 'ventas' => []];
        }
        $ventas = array_map(static fn(array $v) => [
            'factura_id' => (int) $v['id'],
            'e_ncf' => $v['e_ncf'],
            'tipo_ecf' => (string) $v['tipo_ecf'],
            'fecha' => $v['date'],
            'total_centavos' => (int) round(((float) $v['total']) * 100),
            'forma_pago' => (int) $v['forma_pago'],
            'forma_pago_nombre' => PosVenta::FORMAS_PAGO[(int) $v['forma_pago']] ?? 'Otra',
            'estado_dgii' => $v['estado_dgii'],
            'envio_pendiente' => (int) $v['envio_pendiente'] === 1,
        ], $pos->ventasCobradasDelTurno($turno['id']));
        return ['turno_caja' => $turno, 'ventas' => $ventas];
    }

    /**
     * Ventas de hoy del empleado en esta caja, de todos sus turnos (tambien los ya
     * cerrados), con el resumen por forma de pago. "Hoy" es el dia de la fecha de
     * la factura, que el POS escribe con la hora del servidor (date()).
     */
    public static function ventasDelDia(posModel $pos, array $caja, array $empleado): array
    {
        $hoy = new DateTimeImmutable('today');
        $filas = $pos->ventasDelEmpleadoEnCaja((int) $caja['id'], (int) $empleado['id'],
            $hoy->format('Y-m-d H:i:s'), $hoy->modify('+1 day')->format('Y-m-d H:i:s'));

        $ventas = [];
        $porForma = [];
        $total = 0;
        foreach ($filas as $v) {
            $centavos = (int) round(((float) $v['total']) * 100);
            $forma = (int) $v['forma_pago'];
            $cliente = null;
            foreach (['razon_social', 'company_name', 'client_name'] as $campo) {
                if (trim((string) ($v[$campo] ?? '')) !== '') {
                    $cliente = trim((string) $v[$campo]);
                    break;
                }
            }
            $ventas[] = [
                'factura_id' => (int) $v['id'],
                'e_ncf' => $v['e_ncf'],
                'tipo_ecf' => (string) $v['tipo_ecf'],
                'fecha' => $v['date'],
                'turno_id' => (int) $v['turno_id'],
                'cliente' => (string) $v['tipo_ecf'] === '31' ? $cliente : null,
                'total_centavos' => $centavos,
                'forma_pago' => $forma,
                'forma_pago_nombre' => PosVenta::FORMAS_PAGO[$forma] ?? 'Otra',
                'estado_dgii' => $v['estado_dgii'],
                'envio_pendiente' => (int) $v['envio_pendiente'] === 1,
            ];
            $total += $centavos;
            $porForma[$forma] ??= ['forma_pago' => $forma, 'nombre' => PosVenta::FORMAS_PAGO[$forma] ?? 'Otra', 'cantidad' => 0, 'total_centavos' => 0];
            $porForma[$forma]['cantidad']++;
            $porForma[$forma]['total_centavos'] += $centavos;
        }
        ksort($porForma);
        return [
            'fecha' => $hoy->format('Y-m-d'),
            'caja' => ['id' => (int) $caja['id'], 'nombre' => (string) ($caja['nombre'] ?? '')],
            'empleado' => ['id' => (int) $empleado['id'], 'nombre' => (string) $empleado['nombre']],
            'resumen' => ['cantidad' => count($ventas), 'total_centavos' => $total, 'por_forma' => array_values($porForma)],
            'ventas' => $ventas,
        ];
    }
}
