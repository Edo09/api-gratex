<?php

/**
 * Precio final del POS (docs/specs/pos.md C2): el que ve el cajero en la
 * pantalla, con el ITBIS incluido.
 *
 *   final = round(precio × (1 + tasa), 2)   con la tasa de indicador_facturacion
 *
 * `products.precio` va SIN ITBIS y con 4 decimales (migracion 029), asi que el
 * dueño recupera exacto el precio de gondola: 21.1864 × 1.18 = 24.999952 → 25.00.
 *
 * Se calcula en enteros (diezmilesimas × (100 + tasa) = millonesimas) y se
 * redondea la mitad hacia arriba. Con float, un caso justo en la mitad
 * (1.25 × 1.18 = 1.475) puede caer de cualquier lado segun el binario; aqui da
 * siempre 1.48. La venta (POST /api/pos/ventas) debe usar esta misma funcion.
 */
final class PosPrecio
{
    /** Tasa de ITBIS en % por indicador_facturacion: 1 → 18, 2 → 16, 3 (tasa cero) y 4 (exento) → 0. */
    public static function tasa(int $indicador): ?int
    {
        return match ($indicador) {
            1 => 18,
            2 => 16,
            3, 4 => 0,
            default => null, // 0 = no facturable; cualquier otro no se vende en el POS
        };
    }

    /**
     * Precio final en centavos. $precio es el DECIMAL de la base ("21.1864").
     * Lanza InvalidArgumentException si el precio o el indicador no sirven.
     */
    public static function finalCentavos(string $precio, int $indicador): int
    {
        $tasa = self::tasa($indicador);
        if ($tasa === null) {
            throw new InvalidArgumentException("indicador_facturacion {$indicador} no se vende en el POS");
        }
        if (preg_match('/^(\d+)(?:\.(\d{1,4}))?$/', trim($precio), $m) !== 1) {
            throw new InvalidArgumentException("precio invalido: {$precio}");
        }
        $diezmilesimas = (int) $m[1] * 10000 + (int) str_pad($m[2] ?? '', 4, '0');
        $millonesimas = $diezmilesimas * (100 + $tasa);
        return intdiv($millonesimas + 5000, 10000);
    }
}
