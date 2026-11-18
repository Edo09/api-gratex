<?php
/**
 * test_pos_listas_precio.php — las listas de precio 2-4 del POS, sin base de datos ni API:
 * qué precio se lee de cada lista, cuánto es con ITBIS, qué listas ofrece el catálogo y cuáles
 * quedan en la bitácora de una venta.   Uso:  php tools/test_pos_listas_precio.php
 */
require_once __DIR__ . '/write_ahead_test_lib.php';
require_once __DIR__ . '/../src/Pos/PosPrecio.php';

$fila = ['precio' => '21.1864', 'precio_2' => '19.0000', 'precio_3' => null, 'precio_4' => '0.0000'];

echo "1) precioDeLista\n";
wa_chk('la lista 1 es precio y la 2 es precio_2', PosPrecio::precioDeLista($fila, 1) === '21.1864' && PosPrecio::precioDeLista($fila, 2) === '19.0000');
wa_chk('NULL, columna ausente o lista fuera de 1-4: null', PosPrecio::precioDeLista($fila, 3) === null
    && PosPrecio::precioDeLista(['precio' => '1'], 4) === null && PosPrecio::precioDeLista($fila, 0) === null && PosPrecio::precioDeLista($fila, 5) === null);

echo "2) finalCentavos y centavosDeLista\n";
wa_chk('19.0000 al 18% = 22.42', PosPrecio::centavosDeLista($fila, 2, 1) === 2242);
wa_chk('exento: sin ITBIS', PosPrecio::centavosDeLista(['precio_2' => '38.0000'], 2, 4) === 3800);
wa_chk('0, NULL, texto y negativo: null', PosPrecio::centavosDeLista($fila, 4, 1) === null && PosPrecio::centavosDeLista($fila, 3, 1) === null
    && PosPrecio::centavosDeLista(['precio_2' => 'abc'], 2, 1) === null && PosPrecio::centavosDeLista(['precio_2' => '-1.0000'], 2, 1) === null);
wa_chk('un precio que da 0 centavos (0.0040 al 18%): null', PosPrecio::centavosDeLista(['precio_2' => '0.0040'], 2, 1) === null);
wa_chk('un indicador que no se vende (0): null', PosPrecio::centavosDeLista($fila, 2, 0) === null);
// Con mas de 12 digitos enteros la aritmetica en enteros se desborda (intdiv recibe un float): no es un precio.
// Tiene que salir InvalidArgumentException, que el catalogo y centavosDeLista ya tratan como "no se vende", no un TypeError.
$noCalcula = function (string $precio, int $indicador): bool {
    try {
        PosPrecio::finalCentavos($precio, $indicador);
        return false;
    } catch (InvalidArgumentException) {
        return true;
    }
};
wa_chk('finalCentavos: un precio de 13 digitos enteros no se calcula (InvalidArgumentException)', $noCalcula('9999999999999.0000', 1));
$tope = [PosPrecio::finalCentavos('999999999999.9999', 1), PosPrecio::finalCentavos('999999999999.9999', 4)];
wa_chk('finalCentavos: el tope, 999999999999.9999 (12 digitos), si se calcula: al 18% y exento', $tope === [118000000000000, 100000000000000], $tope);
wa_chk('centavosDeLista con un precio de 13 digitos: null, no un error', PosPrecio::centavosDeLista(['precio_2' => '9999999999999.0000'], 2, 1) === null);

echo "3) listasOfrecidas\n";
$conCuatro = $fila;
$conCuatro['precio_4'] = '8.4746';
wa_chk('la 2 (22.42) y la 4 (10.00); la 3, NULL, no', PosPrecio::listasOfrecidas($conCuatro, 1)
    === [['lista' => 2, 'precio_centavos' => 2242], ['lista' => 4, 'precio_centavos' => 1000]]);
wa_chk('sin listas 2-4: []', PosPrecio::listasOfrecidas(['precio' => '5.0000'], 1) === []);
$descartadas = [];
$ofrecidas = PosPrecio::listasOfrecidas(['precio_2' => '-1.0000', 'precio_3' => '0.0040', 'precio_4' => '0.0000'], 1,
    function (int $lista, string $crudo) use (&$descartadas): void { $descartadas[] = [$lista, $crudo]; });
wa_chk('se descartan y avisan la negativa y la que da 0 centavos; el 0 no avisa', $ofrecidas === []
    && $descartadas === [[2, '-1.0000'], [3, '0.0040']], [$ofrecidas, $descartadas]);
$descartadas = [];
$ofrecidas = PosPrecio::listasOfrecidas(['precio' => '5.0000', 'precio_2' => '9999999999999.0000'], 1,
    function (int $lista, string $crudo) use (&$descartadas): void { $descartadas[] = [$lista, $crudo]; });
wa_chk('un precio de 13 digitos en una lista se descarta y avisa, sin tumbar el catalogo', $ofrecidas === []
    && $descartadas === [[2, '9999999999999.0000']], [$ofrecidas, $descartadas]);

echo "4) listasUsadas\n";
wa_chk('solo lineas con lista > 1, una por producto', PosPrecio::listasUsadas([
    ['product_id' => 1, 'cantidad' => 2], ['product_id' => 2, 'cantidad' => 1, 'lista' => 2],
    ['product_id' => 2, 'cantidad' => 3, 'lista' => 2], ['product_id' => 3, 'cantidad' => 1, 'lista' => 1],
]) === [['product_id' => 2, 'lista' => 2]]);

wa_fin();
