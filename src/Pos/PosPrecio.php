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
 *
 * Las listas de precio 2 a 4 (products.precio_2 .. precio_4; NULL o 0 = la lista no
 * aplica a ese producto) usan este mismo calculo: precioDeLista y centavosDeLista las
 * leen, listasOfrecidas arma las del catalogo y listasUsadas las de la bitacora de una venta.
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
     * Lanza InvalidArgumentException si el precio o el indicador no sirven. La parte entera es de
     * 12 digitos como mucho: con mas se desborda la aritmetica en enteros (intdiv recibiria un float
     * y lanzaria TypeError). Un precio asi no es un precio, y quien llama ya trata esa excepcion como
     * "no se vende": el catalogo omite el producto o la lista y lo deja en el log.
     */
    public static function finalCentavos(string $precio, int $indicador): int
    {
        $tasa = self::tasa($indicador);
        if ($tasa === null) {
            throw new InvalidArgumentException("indicador_facturacion {$indicador} no se vende en el POS");
        }
        if (preg_match('/^(\d{1,12})(?:\.(\d{1,4}))?$/', trim($precio), $m) !== 1) {
            throw new InvalidArgumentException("precio invalido: {$precio}");
        }
        $diezmilesimas = (int) $m[1] * 10000 + (int) str_pad($m[2] ?? '', 4, '0');
        $millonesimas = $diezmilesimas * (100 + $tasa);
        return intdiv($millonesimas + 5000, 10000);
    }

    /**
     * DECIMAL del producto para esa lista ("21.1864"): `precio` para la 1 y `precio_2` ..
     * `precio_4` para las demas. null si la columna es NULL o no viene en la fila, o si la
     * lista no es 1-4.
     */
    public static function precioDeLista(array $producto, int $lista): ?string
    {
        if ($lista < 1 || $lista > 4) {
            return null;
        }
        $crudo = $producto[$lista === 1 ? 'precio' : 'precio_' . $lista] ?? null;
        return $crudo === null ? null : (string) $crudo;
    }

    /**
     * Precio final en centavos de esa lista (el calculo de finalCentavos), o null si no se
     * puede vender: precio NULL, 0, con texto o negativo, un indicador que no se vende en el
     * POS, o un precio tan chico que da 0 centavos.
     */
    public static function centavosDeLista(array $producto, int $lista, int $indicador): ?int
    {
        $precio = self::precioDeLista($producto, $lista);
        if ($precio === null) {
            return null;
        }
        try {
            $centavos = self::finalCentavos($precio, $indicador);
        } catch (InvalidArgumentException) {
            return null;
        }
        return $centavos > 0 ? $centavos : null;
    }

    /**
     * Las listas 2 a 4 con precio vendible, en orden: [['lista' => 2, 'precio_centavos' => 2242], ...].
     * NULL y 0 son "esta lista no aplica al producto": se omiten sin avisar. Un precio que no
     * sirve (negativo, con texto, que da 0 centavos o con un indicador que no se vende) tambien
     * se omite, pero se avisa a $alDescartar(lista, precio crudo) para que quede en el log: es
     * un dato mal cargado.
     */
    public static function listasOfrecidas(array $producto, int $indicador, ?callable $alDescartar = null): array
    {
        $ofrecidas = [];
        foreach ([2, 3, 4] as $lista) {
            $centavos = self::centavosDeLista($producto, $lista, $indicador);
            if ($centavos !== null) {
                $ofrecidas[] = ['lista' => $lista, 'precio_centavos' => $centavos];
                continue;
            }
            $crudo = self::precioDeLista($producto, $lista);
            $noAplica = $crudo === null || (is_numeric($crudo) && (float) $crudo === 0.0);
            if ($alDescartar !== null && !$noAplica) {
                $alDescartar($lista, $crudo);
            }
        }
        return $ofrecidas;
    }

    /**
     * De las lineas de una venta, las cobradas con una lista mayor que la 1, para la bitacora:
     * [['product_id' => 2, 'lista' => 2]], una por producto (la primera que aparece). Una
     * linea sin `lista` o con la 1 no sale.
     */
    public static function listasUsadas(array $lineas): array
    {
        $usadas = [];
        foreach ($lineas as $l) {
            $id = is_array($l) ? ($l['product_id'] ?? null) : null;
            $lista = is_array($l) ? ($l['lista'] ?? null) : null;
            if (is_int($id) && is_int($lista) && $lista > 1 && !isset($usadas[$id])) {
                $usadas[$id] = ['product_id' => $id, 'lista' => $lista];
            }
        }
        return array_values($usadas);
    }
}
