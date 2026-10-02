<?php
/**
 * Redondeo de los montos de las cotizaciones por formato, igual en cualquier
 * versión de PHP.
 *
 * round() cambió en PHP 8.4: ya no "pre-redondea" a 15 cifras, así que
 * 84.75 × 18% (15.254999… en binario) da 15.25 desde 8.4 y 15.26 en 8.3.
 * Producción corre PHP 8.3 y la pantalla (fiscalo
 * src/features/invoices/montosLinea.ts, redondear) copia ese 15.26 a
 * propósito. Con round() a secas, una prueba en un PHP local 8.4+ daría otros
 * centavos que los que guarda producción.
 *
 * COPIA EXACTA de montosLinea.redondear: sprintf('%.15g') es el
 * Number(x.toPrecision(15)) de JS, y el round() que queda trabaja sobre un
 * valor ya limpio, donde 8.3 y 8.5 coinciden. El signo va aparte (-15.255 da
 * -15.26), como Math.sign(x) * Math.round(|x|).
 *
 * Solo lo usan los formatos nuevos (src/Utils/Cotizacion/): Gratex y la
 * facturación siguen con su round() de siempre. Un cambio aquí va en
 * montosLinea.ts en el mismo cambio; tools/test_cotizacion_ferreteria.php lo
 * compara con el algoritmo de allá.
 */
final class Redondeo
{
    /** $x a $dec decimales, la mitad lejos del cero, sobre el valor DECIMAL. */
    public static function r(float $x, int $dec): float
    {
        $f = 10 ** $dec;
        $v = (float) sprintf('%.15g', abs($x) * $f);
        $r = round($v) / $f;
        return $x < 0 ? -$r : $r;
    }

    /** Montos en RD$: 2 decimales. */
    public static function r2(float $x): float
    {
        return self::r($x, 2);
    }

    /** Precios unitarios: 4 decimales, lo que admite PrecioUnitarioItem del e-CF. */
    public static function r4(float $x): float
    {
        return self::r($x, 4);
    }
}
