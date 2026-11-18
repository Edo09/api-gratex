<?php

/**
 * CobroReglas — las reglas de Cobros que no tocan la base de datos (docs/modules/cobros.md).
 *
 * Todo lo que es una DECISION va aqui y no en cobroModel: el estado de un cobro, el
 * vencimiento de un mes, el periodo siguiente, la forma de cada cuerpo, la ruta de la
 * peticion y cuando toca recordar. Asi tools/test_cobros.php las prueba por CLI, sin
 * base ni servidor, y el modelo solo ejecuta SQL.
 *
 * DINERO: nunca float. Los montos entran como numero o texto con hasta 2 decimales y se
 * manejan como CENTAVOS enteros; solo al responder vuelven a numero (centavos / 100, que
 * es la misma representacion que el literal). La base los guarda como DECIMAL(12,2).
 *
 * FECHAS: el vencimiento es un DATE (AAAA-MM-DD) y el periodo un texto AAAA-MM. "Hoy" es
 * el de la hora de RD (src/ZonaHoraria.php): quien llama lo pasa, nada aqui lee el reloj.
 *
 * ESTADO: Pendiente, Pagado, Vencido, Parcial o Cancelado. NO se guarda: se deriva del
 * saldo, el vencimiento y "hoy", porque un estado guardado caducaria solo (un cobro
 * "Pendiente" amanece "Vencido" sin que nadie lo toque).
 */
final class CobroReglas
{
    public const PENDIENTE = 'Pendiente';
    public const PAGADO = 'Pagado';
    public const VENCIDO = 'Vencido';
    public const PARCIAL = 'Parcial';
    public const CANCELADO = 'Cancelado';
    /** @var list<string> */
    public const ESTADOS = [self::PENDIENTE, self::PAGADO, self::VENCIDO, self::PARCIAL, self::CANCELADO];

    /** Los dos medios de pago del modulo (el ENUM de cobro_pagos.metodo). */
    public const METODOS = ['efectivo', 'transferencia'];
    /** Como se hizo el recordatorio (solo un registro: la app no envia nada). */
    public const CANALES = ['llamada', 'mensaje', 'correo', 'visita', 'otro'];

    /** Lo que cabe en DECIMAL(12,2): 9,999,999,999.99 en centavos. */
    public const MAX_CENTAVOS = 999999999999;

    public const MAX_DESCRIPCION = 150;
    public const MAX_TEXTO = 255;

    /** Ventana por defecto (dias) de "vence pronto" y cada cuantos dias se vuelve a recordar. */
    public const DIAS_PROXIMOS = 7;
    public const DIAS_RECORDAR = 7;
    public const MAX_DIAS = 90;

    /** Tope de pagos y de recordatorios por cobro (los anulados cuentan: la historia no se borra). */
    public const MAX_PAGOS = 500;
    public const MAX_RECORDATORIOS = 200;
    /** Cuantos cobros mira, como maximo, la lista de recordatorios (los mas atrasados primero). */
    public const MAX_FILAS_RECORDATORIOS = 500;

    // Textos que ve la persona (los muestra la web tal cual): claros y sin nombres de campo.
    public const MSG_CLIENTE = 'Elige un cliente.';
    public const MSG_CLIENTE_NO_EXISTE = 'Este cliente ya no existe. Puede que lo hayan eliminado; actualiza la lista.';
    public const MSG_MONTO = 'El monto tiene que ser un número mayor que 0, con hasta 2 decimales.';
    public const MSG_MONTO_GRANDE = 'El monto es demasiado grande. El máximo es RD$ 9,999,999,999.99.';
    public const MSG_MONTO_REQUERIDO = 'Escribe el monto.';
    public const MSG_DIA = 'El día de vencimiento tiene que ser un número entero entre 1 y 31.';
    public const MSG_FECHA = 'La fecha no es válida. Revisa el día, el mes y el año (por ejemplo 2026-12-15).';
    public const MSG_FECHA_REQUERIDA = 'Elige la fecha de vencimiento.';
    public const MSG_PERIODO = 'El periodo no es válido. Elige un mes y un año entre 2000 y 2100 (por ejemplo 2026-12).';
    public const MSG_DESCRIPCION = 'La descripción puede tener como máximo 150 caracteres.';
    public const MSG_METODO = 'Elige cómo se pagó: Efectivo o Transferencia.';
    public const MSG_OBSERVACIONES = 'Las observaciones pueden tener como máximo 255 caracteres.';
    public const MSG_NOTA = 'La nota puede tener como máximo 255 caracteres.';
    public const MSG_MOTIVO = 'El motivo puede tener como máximo 255 caracteres.';
    public const MSG_CANAL = 'El medio del recordatorio no es válido. Usa llamada, mensaje, correo, visita u otro.';
    public const MSG_FECHA_PAGO = 'La fecha del pago no es válida.';
    public const MSG_FECHA_PAGO_FUTURA = 'La fecha del pago no puede ser posterior a hoy.';
    public const MSG_FECHA_HORA = 'La fecha y la hora del recordatorio no son válidas.';
    public const MSG_FECHA_HORA_FUTURA = 'El recordatorio no puede ser de una fecha posterior a hoy.';
    public const MSG_CLAVE = 'La clave de la operación no es válida.';
    public const MSG_SIN_CAMBIOS = 'No hay nada que modificar. Cambia el monto, la fecha de vencimiento o la descripción.';
    public const MSG_PERIODO_FIJO = 'El periodo de un cobro no se puede cambiar. Cancélalo y crea otro.';
    public const MSG_ACTIVO = 'El campo "activo" tiene que ser sí o no.';
    public const MSG_CUERPO = 'No se pudo leer lo que se envió. Revisa los datos e inténtalo de nuevo.';
    public const MSG_NO_EXISTE = 'Este cobro ya no existe. Actualiza la lista.';
    public const MSG_PAGO_NO_EXISTE = 'Este pago ya no existe. Actualiza la pantalla.';
    public const MSG_CANCELADO = 'Este cobro está cancelado. Reábrelo si quieres modificarlo o registrarle pagos.';
    public const MSG_YA_PAGADO = 'Este cobro ya está pagado completo.';
    public const MSG_TIENE_PAGOS = 'Este cobro tiene pagos registrados, así que no se puede cancelar. Si un pago fue un error, anúlalo primero.';
    public const MSG_RUTA = 'Esta dirección de cobros no existe.';
    public const MSG_SIN_PERMISO = 'No tienes permiso para hacer esto. Pídeselo a un administrador.';
    public const MSG_SIN_PERMISO_PLATAFORMA = 'Los cobros a los tenants son solo para la cuenta de soporte de la plataforma.';
    public const MSG_METODO_HTTP = 'Esta acción de cobros no admite ese método.';
    public const MSG_ERROR_GENERICO = 'No se pudo completar la operación con los cobros. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.';

    // ------------------------------------------------------------------------
    // Dinero (centavos)
    // ------------------------------------------------------------------------

    /**
     * Centavos de un monto del cuerpo: un entero, un decimal o un texto ("1500", "1500.5",
     * "1500.50") con A LO SUMO 2 decimales. null si no es un monto (texto con letras, tres
     * decimales, NAN, un booleano, un arreglo...). No mira el rango: eso es montoValido().
     * Tambien sirve para lo que devuelve la base (texto DECIMAL, o numeros en SQLite).
     */
    public static function centavos(mixed $v): ?int
    {
        if (is_int($v)) {
            return abs($v) > intdiv(PHP_INT_MAX, 100) ? null : $v * 100;
        }
        if (is_float($v)) {
            if (!is_finite($v) || abs($v) > 9.0e15) {
                return null;
            }
            $c = round($v * 100);
            // 0.29 * 100 = 28.999999999999996: es ruido binario. Tres decimales de verdad no pasan
            // (el menor, 0.001, se ve como 0.1 centavo). El ruido crece con la magnitud (un doble de
            // 1e12 centavos tiene un paso de 1.2e-4), asi que la tolerancia es relativa: con una fija de
            // 1e-6, 1234567890.12 se rechazaba como si tuviera tres decimales.
            return abs($v * 100 - $c) > max(1e-6, abs($c) * 1e-14) ? null : (int) $c;
        }
        if (is_string($v)) {
            if (preg_match('/^(-?)(\d{1,12})(?:\.(\d{1,2}))?$/', trim($v), $m) !== 1) {
                return null;
            }
            $c = ((int) $m[2]) * 100 + (int) str_pad($m[3] ?? '', 2, '0');
            return $m[1] === '-' ? -$c : $c;
        }
        return null;
    }

    /** Centavos de un valor que viene de la base: nunca null (lo ilegible se redondea). */
    public static function centavosDb(mixed $v): int
    {
        return self::centavos($v) ?? (int) round(((float) $v) * 100);
    }

    /** ¿Es un monto de cobro o de pago valido? Mayor que 0 y dentro de DECIMAL(12,2). */
    public static function montoValido(?int $centavos): bool
    {
        return $centavos !== null && $centavos > 0 && $centavos <= self::MAX_CENTAVOS;
    }

    /** El monto como numero para el JSON (centavos / 100: el mismo valor que el literal). */
    public static function aNumero(int $centavos): float
    {
        return round($centavos / 100, 2);
    }

    /** El monto como texto DECIMAL exacto para la base: 123456 -> "1234.56". */
    public static function aDecimal(int $centavos): string
    {
        $signo = $centavos < 0 ? '-' : '';
        $c = abs($centavos);
        return sprintf('%s%d.%02d', $signo, intdiv($c, 100), $c % 100);
    }

    /** El monto para un mensaje: 123456 -> "RD$ 1,234.56". */
    public static function texto(int $centavos): string
    {
        return 'RD$ ' . number_format($centavos / 100, 2, '.', ',');
    }

    // ------------------------------------------------------------------------
    // Fechas y periodos
    // ------------------------------------------------------------------------

    /** ¿Es una fecha AAAA-MM-DD que existe (no el 30 de febrero) entre 2000 y 2100? */
    public static function fechaValida(mixed $s): bool
    {
        // /D: sin ella, "2026-12-15\n" pasaba ($ acepta un salto de linea al final) y se guardaba con el.
        if (!is_string($s) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $s, $m) !== 1) {
            return false;
        }
        $y = (int) $m[1];
        return $y >= 2000 && $y <= 2100 && checkdate((int) $m[2], (int) $m[3], $y);
    }

    /** ¿Es un periodo AAAA-MM con mes de 01 a 12 y anio entre 2000 y 2100? */
    public static function periodoValido(mixed $p): bool
    {
        if (!is_string($p) || preg_match('/^(\d{4})-(\d{2})$/D', $p, $m) !== 1) {
            return false;
        }
        $y = (int) $m[1];
        $mes = (int) $m[2];
        return $y >= 2000 && $y <= 2100 && $mes >= 1 && $mes <= 12;
    }

    /** El periodo (AAAA-MM) de una fecha AAAA-MM-DD. */
    public static function periodoDe(string $fecha): string
    {
        return substr($fecha, 0, 7);
    }

    /** El periodo que sigue: 2026-11 -> 2026-12, 2026-12 -> 2027-01. */
    public static function periodoSiguiente(string $periodo): string
    {
        [$y, $m] = array_map('intval', explode('-', $periodo));
        return $m >= 12 ? sprintf('%04d-01', $y + 1) : sprintf('%04d-%02d', $y, $m + 1);
    }

    /** Cuantos dias tiene el mes de un periodo (28 a 31). */
    public static function diasDelMes(string $periodo): int
    {
        [$y, $m] = array_map('intval', explode('-', $periodo));
        return (int) gmdate('t', gmmktime(0, 0, 0, $m, 1, $y));
    }

    /**
     * El vencimiento de un periodo para un dia configurado. Un dia que el mes no tiene (31
     * en abril, 30 en febrero) cae en el ULTIMO dia de ese mes; el mes siguiente vuelve al
     * dia configurado, porque siempre se calcula desde la configuracion y no desde el
     * vencimiento anterior (no se acumula el corrimiento).
     */
    public static function vencimientoDe(string $periodo, int $dia): string
    {
        return sprintf('%s-%02d', $periodo, max(1, min($dia, self::diasDelMes($periodo))));
    }

    /** Dias de $desde a $hasta (AAAA-MM-DD), con signo: negativo si $hasta es anterior. */
    public static function diasEntre(string $desde, string $hasta): int
    {
        $a = new DateTimeImmutable($desde . ' 00:00:00', new DateTimeZone('UTC'));
        $b = new DateTimeImmutable($hasta . ' 00:00:00', new DateTimeZone('UTC'));
        return (int) $a->diff($b)->format('%r%a');
    }

    /** La fecha $n dias despues (o antes, con $n negativo) de una fecha AAAA-MM-DD. */
    public static function sumarDias(string $fecha, int $n): string
    {
        $d = new DateTimeImmutable($fecha . ' 00:00:00', new DateTimeZone('UTC'));
        return $d->modify(($n >= 0 ? '+' : '') . $n . ' days')->format('Y-m-d');
    }

    /** Primer y ultimo dia del mes de una fecha: ['2026-11-01', '2026-11-30']. */
    public static function limitesDelMes(string $fecha): array
    {
        $p = self::periodoDe($fecha);
        return [$p . '-01', sprintf('%s-%02d', $p, self::diasDelMes($p))];
    }

    /** ¿Es una fecha y hora 'AAAA-MM-DD HH:MM:SS' (o con T, o sin segundos) valida? Devuelve la normalizada o null. */
    public static function fechaHora(mixed $s): ?string
    {
        if (!is_string($s) || preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/', trim($s), $m) !== 1) {
            return null;
        }
        if (!self::fechaValida($m[1]) || (int) $m[2] > 23 || (int) $m[3] > 59 || (int) ($m[4] ?? 0) > 59) {
            return null;
        }
        return sprintf('%s %s:%s:%s', $m[1], $m[2], $m[3], $m[4] ?? '00');
    }

    // ------------------------------------------------------------------------
    // Estado
    // ------------------------------------------------------------------------

    /**
     * El estado de un cobro, derivado. Precedencia: Cancelado, Pagado (saldo 0), Vencido
     * (queda saldo y el vencimiento es anterior a hoy), Parcial (queda saldo y ya tiene
     * pagos) y Pendiente. Un cobro vencido con abonos sigue "Vencido": es lo urgente, y la
     * pantalla muestra aparte que tiene abonos. Un cobro que vence HOY todavia no esta
     * vencido.
     */
    public static function estado(bool $cancelado, int $montoC, int $pagadoC, string $vencimiento, string $hoy): string
    {
        if ($cancelado) {
            return self::CANCELADO;
        }
        if ($montoC - $pagadoC <= 0) {
            return self::PAGADO;
        }
        if ($vencimiento < $hoy) {
            return self::VENCIDO;
        }
        return $pagadoC > 0 ? self::PARCIAL : self::PENDIENTE;
    }

    /** ¿El texto es uno de los cinco estados (sin importar mayusculas)? Devuelve el nombre canonico o null. */
    public static function estadoDe(mixed $s): ?string
    {
        if (!is_string($s)) {
            return null;
        }
        foreach (self::ESTADOS as $e) {
            if (strcasecmp($e, trim($s)) === 0) {
                return $e;
            }
        }
        return null;
    }

    // ------------------------------------------------------------------------
    // Validacion de cuerpos. Todas devuelven ['success', datos] | ['error', texto, http].
    // ------------------------------------------------------------------------

    /** Un id entero positivo (numero o texto de digitos); null si no lo es. */
    public static function idDe(mixed $v): ?int
    {
        if (is_int($v)) {
            return $v > 0 ? $v : null;
        }
        if (is_string($v) && preg_match('/^\d{1,10}$/', trim($v)) === 1 && (int) $v > 0) {
            return (int) $v;
        }
        return null;
    }

    /** Texto opcional: recortado, '' = null. ['ok', valor|null] o ['largo'] si pasa de $max. */
    private static function texto255(mixed $v, int $max): array
    {
        if ($v === null) {
            return ['ok', null];
        }
        if (!is_string($v) && !is_int($v) && !is_float($v)) {
            return ['largo'];
        }
        $t = trim((string) $v);
        if ($t === '') {
            return ['ok', null];
        }
        // strlen y no mb_strlen: la columna VARCHAR cuenta caracteres, pero strlen es mas estricto
        // (nunca deja pasar de mas) y no depende de mbstring (ver AuditLogger::cut).
        return strlen($t) > $max * 4 || self::largoUtf8($t) > $max ? ['largo'] : ['ok', $t];
    }

    /** Caracteres de un texto UTF-8 sin depender de mbstring. */
    private static function largoUtf8(string $t): int
    {
        return preg_match_all('/./us', $t) ?: strlen($t);
    }

    private static function tiene(object $b, string $k): bool
    {
        return property_exists($b, $k);
    }

    /** Un booleano del cuerpo (true/false, 1/0, "1"/"0", "true"/"false", "si"/"no"); null si no se entiende. */
    public static function booleano(mixed $v): ?bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v) && ($v === 0 || $v === 1)) {
            return $v === 1;
        }
        if (is_string($v)) {
            return match (strtolower(trim($v))) {
                '1', 'true', 'si', 'sí', 's' => true,
                '0', 'false', 'no', 'n' => false,
                default => null,
            };
        }
        return null;
    }

    /**
     * Configuracion mensual de un cliente (PUT /api/cobros/clientes/{id}/config).
     * Datos: monto_c (centavos), dia (1-31), descripcion (?string), activo (bool, por defecto true).
     */
    public static function validarConfig(object $b): array
    {
        $monto = self::centavos($b->monto_mensual ?? null);
        if (!self::tiene($b, 'monto_mensual') || ($b->monto_mensual ?? null) === null || $b->monto_mensual === '') {
            return ['error', self::MSG_MONTO_REQUERIDO, 422];
        }
        if ($monto === null || $monto <= 0) {
            return ['error', self::MSG_MONTO, 422];
        }
        if ($monto > self::MAX_CENTAVOS) {
            return ['error', self::MSG_MONTO_GRANDE, 422];
        }
        $dia = self::diaDe($b->dia_vencimiento ?? null);
        if ($dia === null) {
            return ['error', self::MSG_DIA, 422];
        }
        $desc = self::texto255($b->descripcion ?? null, self::MAX_DESCRIPCION);
        if ($desc[0] !== 'ok') {
            return ['error', self::MSG_DESCRIPCION, 422];
        }
        $activo = true;
        if (self::tiene($b, 'activo') && $b->activo !== null) {
            $activo = self::booleano($b->activo);
            if ($activo === null) {
                return ['error', self::MSG_ACTIVO, 422];
            }
        }
        return ['success', ['monto_c' => $monto, 'dia' => $dia, 'descripcion' => $desc[1], 'activo' => $activo]];
    }

    /** El dia de vencimiento: entero de 1 a 31 (numero o texto de digitos); null si no. */
    public static function diaDe(mixed $v): ?int
    {
        if (is_int($v)) {
            return $v >= 1 && $v <= 31 ? $v : null;
        }
        if (is_float($v)) {
            return $v === floor($v) && $v >= 1 && $v <= 31 ? (int) $v : null;
        }
        if (is_string($v) && preg_match('/^\d{1,2}$/', trim($v)) === 1) {
            $n = (int) $v;
            return $n >= 1 && $n <= 31 ? $n : null;
        }
        return null;
    }

    /**
     * Un cobro nuevo (POST /api/cobros): solo la forma. Lo que falta (monto, vencimiento,
     * descripcion) lo completa resolverNuevo() con la configuracion del cliente.
     * Datos: client_id, periodo (?string), fecha (?string), monto_c (?int), descripcion (?string).
     */
    public static function validarNuevo(object $b): array
    {
        $cliente = self::idDe($b->client_id ?? null);
        if ($cliente === null) {
            return ['error', self::MSG_CLIENTE, 422];
        }
        $periodo = null;
        if (self::tiene($b, 'periodo') && $b->periodo !== null && $b->periodo !== '') {
            if (!self::periodoValido($b->periodo)) {
                return ['error', self::MSG_PERIODO, 422];
            }
            $periodo = $b->periodo;
        }
        $fecha = null;
        if (self::tiene($b, 'fecha_vencimiento') && $b->fecha_vencimiento !== null && $b->fecha_vencimiento !== '') {
            if (!self::fechaValida($b->fecha_vencimiento)) {
                return ['error', self::MSG_FECHA, 422];
            }
            $fecha = $b->fecha_vencimiento;
        }
        $monto = null;
        if (self::tiene($b, 'monto') && $b->monto !== null && $b->monto !== '') {
            $monto = self::centavos($b->monto);
            if ($monto === null || $monto <= 0) {
                return ['error', self::MSG_MONTO, 422];
            }
            if ($monto > self::MAX_CENTAVOS) {
                return ['error', self::MSG_MONTO_GRANDE, 422];
            }
        }
        $desc = self::texto255($b->descripcion ?? null, self::MAX_DESCRIPCION);
        if ($desc[0] !== 'ok') {
            return ['error', self::MSG_DESCRIPCION, 422];
        }
        return ['success', [
            'client_id' => $cliente, 'periodo' => $periodo, 'fecha' => $fecha,
            'monto_c' => $monto, 'descripcion' => $desc[1],
        ]];
    }

    /**
     * Completa un cobro nuevo con la configuracion del cliente y decide su periodo.
     * El periodo es el pedido; si no vino, el del vencimiento pedido; si tampoco, el
     * sugerido ($sugerido: el mes que sigue al ultimo cobro del cliente, o el mes de hoy).
     * El vencimiento pedido manda; sin el, sale de la configuracion (dia + periodo). El
     * monto y la descripcion, igual: lo escrito, o lo de la configuracion. Sin
     * configuracion hacen falta el monto y el vencimiento.
     *
     * @param array{client_id:int, periodo:?string, fecha:?string, monto_c:?int, descripcion:?string} $d
     * @param array{monto_c:int, dia:int, descripcion:?string}|null $config
     * @return array ['success', ['client_id','periodo','fecha','monto_c','descripcion']] | ['error', texto, http]
     */
    public static function resolverNuevo(array $d, ?array $config, string $sugerido): array
    {
        $periodo = $d['periodo'] ?? ($d['fecha'] !== null ? self::periodoDe($d['fecha']) : $sugerido);
        $monto = $d['monto_c'] ?? ($config['monto_c'] ?? null);
        if ($monto === null) {
            return ['error', self::MSG_MONTO_REQUERIDO, 422];
        }
        $fecha = $d['fecha'] ?? ($config !== null ? self::vencimientoDe($periodo, (int) $config['dia']) : null);
        if ($fecha === null) {
            return ['error', self::MSG_FECHA_REQUERIDA, 422];
        }
        return ['success', [
            'client_id' => $d['client_id'], 'periodo' => $periodo, 'fecha' => $fecha, 'monto_c' => $monto,
            'descripcion' => $d['descripcion'] ?? ($config['descripcion'] ?? null),
        ]];
    }

    /**
     * Cambios de un cobro (PUT /api/cobros/{id}): monto, fecha_vencimiento y descripcion.
     * Solo viaja lo que cambia; la clave presente con '' en la descripcion la vacia.
     * Datos: solo las claves presentes de monto_c, fecha, descripcion, y periodo (para que el
     * modelo rechace un cambio de periodo).
     */
    public static function validarCambios(object $b): array
    {
        $d = [];
        if (self::tiene($b, 'monto') && $b->monto !== null) {
            $monto = self::centavos($b->monto);
            if ($monto === null || $monto <= 0) {
                return ['error', self::MSG_MONTO, 422];
            }
            if ($monto > self::MAX_CENTAVOS) {
                return ['error', self::MSG_MONTO_GRANDE, 422];
            }
            $d['monto_c'] = $monto;
        }
        if (self::tiene($b, 'fecha_vencimiento') && $b->fecha_vencimiento !== null) {
            if (!self::fechaValida($b->fecha_vencimiento)) {
                return ['error', self::MSG_FECHA, 422];
            }
            $d['fecha'] = $b->fecha_vencimiento;
        }
        if (self::tiene($b, 'descripcion')) {
            $desc = self::texto255($b->descripcion, self::MAX_DESCRIPCION);
            if ($desc[0] !== 'ok') {
                return ['error', self::MSG_DESCRIPCION, 422];
            }
            $d['descripcion'] = $desc[1];
        }
        if (self::tiene($b, 'periodo') && $b->periodo !== null && $b->periodo !== '') {
            if (!self::periodoValido($b->periodo)) {
                return ['error', self::MSG_PERIODO, 422];
            }
            $d['periodo'] = $b->periodo;
        }
        if (!array_intersect(['monto_c', 'fecha', 'descripcion'], array_keys($d))) {
            return ['error', self::MSG_SIN_CAMBIOS, 422];
        }
        return ['success', $d];
    }

    /**
     * Un pago (POST /api/cobros/{id}/pagos). Datos: monto_c, fecha_pago, metodo, observaciones, clave.
     * Que el monto no pase del saldo lo revisa el modelo (necesita el cobro).
     */
    public static function validarPago(object $b, string $hoy): array
    {
        if (!self::tiene($b, 'monto') || $b->monto === null || $b->monto === '') {
            return ['error', self::MSG_MONTO_REQUERIDO, 422];
        }
        $monto = self::centavos($b->monto);
        if ($monto === null || $monto <= 0) {
            return ['error', self::MSG_MONTO, 422];
        }
        if ($monto > self::MAX_CENTAVOS) {
            return ['error', self::MSG_MONTO_GRANDE, 422];
        }
        $fecha = $hoy;
        if (self::tiene($b, 'fecha_pago') && $b->fecha_pago !== null && $b->fecha_pago !== '') {
            if (!self::fechaValida($b->fecha_pago)) {
                return ['error', self::MSG_FECHA_PAGO, 422];
            }
            if ($b->fecha_pago > $hoy) {
                return ['error', self::MSG_FECHA_PAGO_FUTURA, 422];
            }
            $fecha = $b->fecha_pago;
        }
        $metodo = is_string($b->metodo ?? null) ? strtolower(trim($b->metodo)) : '';
        if (!in_array($metodo, self::METODOS, true)) {
            return ['error', self::MSG_METODO, 422];
        }
        $obs = self::texto255($b->observaciones ?? null, self::MAX_TEXTO);
        if ($obs[0] !== 'ok') {
            return ['error', self::MSG_OBSERVACIONES, 422];
        }
        $clave = self::clave($b->idempotency_key ?? null);
        if ($clave === false) {
            return ['error', self::MSG_CLAVE, 422];
        }
        return ['success', [
            'monto_c' => $monto, 'fecha_pago' => $fecha, 'metodo' => $metodo,
            'observaciones' => $obs[1], 'clave' => $clave,
        ]];
    }

    /** La clave de idempotencia: un UUID (en minusculas), null si no vino, false si no es valida. */
    public static function clave(mixed $v): string|false|null
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (!is_string($v) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', trim($v)) !== 1) {
            return false;
        }
        return strtolower(trim($v));
    }

    /** Un recordatorio hecho a mano. Datos: fecha_hora, canal, nota. $ahora = 'AAAA-MM-DD HH:MM:SS' (hora de RD). */
    public static function validarRecordatorio(object $b, string $ahora): array
    {
        $cuando = $ahora;
        if (self::tiene($b, 'fecha_hora') && $b->fecha_hora !== null && $b->fecha_hora !== '') {
            $cuando = self::fechaHora($b->fecha_hora);
            if ($cuando === null) {
                return ['error', self::MSG_FECHA_HORA, 422];
            }
            // Un minuto de gracia: el reloj de quien escribe puede ir un poco adelantado.
            if ($cuando > gmdate('Y-m-d H:i:s', (int) strtotime($ahora . ' UTC') + 60)) {
                return ['error', self::MSG_FECHA_HORA_FUTURA, 422];
            }
        }
        $canal = 'otro';
        if (self::tiene($b, 'canal') && $b->canal !== null && $b->canal !== '') {
            $canal = is_string($b->canal) ? strtolower(trim($b->canal)) : '';
            if (!in_array($canal, self::CANALES, true)) {
                return ['error', self::MSG_CANAL, 422];
            }
        }
        $nota = self::texto255($b->nota ?? null, self::MAX_TEXTO);
        if ($nota[0] !== 'ok') {
            return ['error', self::MSG_NOTA, 422];
        }
        return ['success', ['fecha_hora' => $cuando, 'canal' => $canal, 'nota' => $nota[1]]];
    }

    /** El motivo opcional de cancelar o anular. ['success', ?string] | ['error', texto, 422]. */
    public static function validarMotivo(object $b): array
    {
        $m = self::texto255($b->motivo ?? null, self::MAX_TEXTO);
        return $m[0] === 'ok' ? ['success', $m[1]] : ['error', self::MSG_MOTIVO, 422];
    }

    // ------------------------------------------------------------------------
    // Filtros del listado
    // ------------------------------------------------------------------------

    /**
     * Los filtros de GET /api/cobros desde la query string. Un filtro con un valor que no
     * existe (un estado inventado, una fecha imposible) no se ignora en silencio: devuelve
     * ['error', texto, 422], porque una lista "filtrada" que en realidad no filtra enganna.
     *
     * @param array<string,mixed> $q $_GET
     * @return array ['success', ['client_id','estado','periodo','desde','hasta','query','orden']] | ['error', texto, 422]
     */
    public static function validarFiltros(array $q): array
    {
        $f = ['client_id' => null, 'estado' => null, 'periodo' => null, 'desde' => null, 'hasta' => null, 'query' => null, 'orden' => 'urgencia'];
        if (isset($q['client_id']) && $q['client_id'] !== '') {
            $f['client_id'] = self::idDe($q['client_id']);
            if ($f['client_id'] === null) {
                return ['error', self::MSG_CLIENTE, 422];
            }
        }
        if (isset($q['estado']) && $q['estado'] !== '') {
            $f['estado'] = self::estadoDe($q['estado']);
            if ($f['estado'] === null) {
                return ['error', 'El estado no es válido. Usa Pendiente, Pagado, Vencido, Parcial o Cancelado.', 422];
            }
        }
        if (isset($q['periodo']) && $q['periodo'] !== '') {
            if (!self::periodoValido($q['periodo'])) {
                return ['error', self::MSG_PERIODO, 422];
            }
            $f['periodo'] = $q['periodo'];
        }
        foreach (['vence_desde' => 'desde', 'vence_hasta' => 'hasta'] as $clave => $campo) {
            if (isset($q[$clave]) && $q[$clave] !== '') {
                if (!self::fechaValida($q[$clave])) {
                    return ['error', self::MSG_FECHA, 422];
                }
                $f[$campo] = $q[$clave];
            }
        }
        if ($f['desde'] !== null && $f['hasta'] !== null && $f['desde'] > $f['hasta']) {
            return ['error', 'La fecha "desde" no puede ser posterior a la fecha "hasta".', 422];
        }
        if (isset($q['query']) && is_string($q['query']) && trim($q['query']) !== '') {
            $f['query'] = trim($q['query']);
        }
        if (isset($q['orden']) && $q['orden'] !== '') {
            if (!in_array($q['orden'], ['urgencia', 'vencimiento_asc', 'vencimiento_desc', 'saldo_desc'], true)) {
                return ['error', 'El orden no es válido.', 422];
            }
            $f['orden'] = $q['orden'];
        }
        return ['success', $f];
    }

    /** La pagina mas alta que se acepta: con ella el offset (pagina x 100) cabe de sobra en un entero. */
    public const MAX_PAGINA = 100000;

    /** Pagina y tamanio (con tope) desde la query string. @return array{page:int, pageSize:int, offset:int} */
    public static function paginacion(array $q): array
    {
        // Sin el tope, ?page=9223372036854775807 desbordaba (pagina - 1) * tamanio a float y daba un 500.
        $page = isset($q['page']) && is_numeric($q['page']) && (int) $q['page'] > 0 ? min((int) $q['page'], self::MAX_PAGINA) : 1;
        $size = isset($q['pageSize']) && is_numeric($q['pageSize']) && (int) $q['pageSize'] > 0 ? min((int) $q['pageSize'], 100) : 10;
        return ['page' => $page, 'pageSize' => $size, 'offset' => ($page - 1) * $size];
    }

    /** Un entero de la query string entre 1 y $max (con valor por defecto). */
    public static function enteroDe(array $q, string $clave, int $defecto, int $max = self::MAX_DIAS): int
    {
        if (!isset($q[$clave]) || !is_numeric($q[$clave])) {
            return $defecto;
        }
        $n = (int) $q[$clave];
        return $n < 1 ? $defecto : min($n, $max);
    }

    // ------------------------------------------------------------------------
    // Recordatorios
    // ------------------------------------------------------------------------

    /**
     * ¿Hay que recordarle este cobro a su cliente? Si nunca se le recordo; si el ultimo
     * recordatorio tiene $cada dias o mas; o si el cobro VENCIO despues del ultimo recordatorio
     * (el aviso de "vence pronto" no cubre un "ya esta vencido").
     *
     * El "ultimo recordatorio" es el del CLIENTE: el mas nuevo de los que se anotaron en cualquiera
     * de sus cobros. Una llamada al cliente cubre todo lo que debe, aunque se anote en un solo cobro.
     * Un cliente se recuerda cuando CUALQUIERA de sus cobros lo requiere.
     *
     * @param string|null $ultimo fecha y hora del ultimo recordatorio del cliente ('AAAA-MM-DD HH:MM:SS')
     */
    public static function requiereRecordatorio(string $estado, string $vencimiento, ?string $ultimo, string $hoy, int $cada): bool
    {
        if ($ultimo === null || $ultimo === '') {
            return true;
        }
        $dia = substr($ultimo, 0, 10);
        if (self::diasEntre($dia, $hoy) >= $cada) {
            return true;
        }
        return $estado === self::VENCIDO && $dia <= $vencimiento;
    }

    // ------------------------------------------------------------------------
    // Ruta
    // ------------------------------------------------------------------------

    /**
     * Que accion pide una peticion a /api/cobros (la de una empresa) o a /api/cobros-tenants (la de la
     * plataforma: las mismas rutas bajo otro primer segmento). Lee la ruta a partir del PRIMER '/api/' (como
     * el Router) y solo mira los segmentos de despues de $segmento.
     *
     * Acciones: listar, crear, resumen, recordatorios, panel, config, obtener, actualizar,
     * pagar, cancelar, reabrir, recordar, anular y buscar (GET .../clientes: el buscador de tenants, solo
     * existe en la plataforma). 'metodo' = la ruta existe pero no con ese metodo (405); 'desconocida' = no
     * existe (404).
     *
     * @param string $segmento el primer segmento de la ruta: 'cobros' o 'cobros-tenants'
     * @return array{accion:string, id:?int, pago_id:?int, client_id:?int}
     */
    public static function ruta(string $metodo, string $path, string $segmento = 'cobros'): array
    {
        $r = ['accion' => 'desconocida', 'id' => null, 'pago_id' => null, 'client_id' => null];
        $pos = strpos($path, '/api/');
        $resto = $pos !== false ? substr($path, $pos + 5) : ltrim($path, '/');
        $seg = array_values(array_filter(explode('/', $resto), static fn(string $s): bool => $s !== ''));
        if (($seg[0] ?? '') !== $segmento) {
            return $r;
        }
        array_shift($seg);
        $m = strtoupper($metodo);
        // [metodo permitido => accion] de la ruta que coincide, para dar 405 y no 404.
        $permitidos = null;
        $n = count($seg);
        if ($n === 0) {
            $permitidos = ['GET' => 'listar', 'POST' => 'crear'];
        } elseif ($n === 1 && $seg[0] === 'resumen') {
            $permitidos = ['GET' => 'resumen'];
        } elseif ($n === 1 && $seg[0] === 'recordatorios') {
            $permitidos = ['GET' => 'recordatorios'];
        } elseif ($n === 1 && $seg[0] === 'clientes') {
            $permitidos = ['GET' => 'buscar'];
        } elseif ($n === 2 && $seg[0] === 'clientes' && self::idDe($seg[1]) !== null) {
            $r['client_id'] = self::idDe($seg[1]);
            $permitidos = ['GET' => 'panel'];
        } elseif ($n === 3 && $seg[0] === 'clientes' && self::idDe($seg[1]) !== null && $seg[2] === 'config') {
            $r['client_id'] = self::idDe($seg[1]);
            $permitidos = ['PUT' => 'config'];
        } elseif ($n === 3 && $seg[0] === 'pagos' && self::idDe($seg[1]) !== null && $seg[2] === 'anular') {
            $r['pago_id'] = self::idDe($seg[1]);
            $permitidos = ['POST' => 'anular'];
        } elseif ($n === 1 && self::idDe($seg[0]) !== null) {
            $r['id'] = self::idDe($seg[0]);
            $permitidos = ['GET' => 'obtener', 'PUT' => 'actualizar'];
        } elseif ($n === 2 && self::idDe($seg[0]) !== null && in_array($seg[1], ['pagos', 'cancelar', 'reabrir', 'recordatorios'], true)) {
            $r['id'] = self::idDe($seg[0]);
            $permitidos = ['POST' => ['pagos' => 'pagar', 'cancelar' => 'cancelar', 'reabrir' => 'reabrir', 'recordatorios' => 'recordar'][$seg[1]]];
        }
        if ($permitidos === null) {
            return $r;
        }
        $r['accion'] = $permitidos[$m] ?? 'metodo';
        return $r;
    }
}
