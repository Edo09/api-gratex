<?php
/**
 * test_producto_patch.php — prueba de las reglas puras de PATCH /api/products
 * (src/Utils/ProductPatch.php). No usa base de datos ni API: se corre en cualquier
 * parte, sin servidor.
 *
 * Uso:  php tools/test_producto_patch.php      (imprime OK y sale con 0; sale con 1 si algo falla)
 */

require_once __DIR__ . '/../src/Utils/ProductPatch.php';

$fallos = [];
$total = 0;
$chk = function (string $desc, bool $ok, $obtenido = null) use (&$fallos, &$total) {
    $total++;
    if (!$ok) {
        $fallos[] = $desc . ($obtenido === null ? '' : ' -> ' . json_encode($obtenido, JSON_UNESCAPED_UNICODE));
    }
};
$valida = fn($datos) => ProductPatch::validar($datos);
$error = fn($datos) => ($r = ProductPatch::validar($datos))['ok'] ? null : $r['error'];

// --- un cambio completo y correcto --------------------------------------------------------------------------
$r = $valida(json_decode('{"id": 12, "nombre": "  Cabeza móvil Beam 230 7R ", "precio": "10593.2203", "esperado": {"nombre": "CABEZA MOVIL", "precio": "9800.0000"}}'));
$chk('acepta un cambio completo (objeto de json_decode)', $r['ok'] === true, $r);
$chk('el id sale como entero', ($r['id'] ?? null) === 12, $r);
$chk('el nombre sale recortado', ($r['cambios']['nombre'] ?? null) === 'Cabeza móvil Beam 230 7R', $r);
$chk('el precio sale con 4 decimales', ($r['cambios']['precio'] ?? null) === '10593.2203', $r);
$chk('lo esperado sale normalizado', ($r['esperado'] ?? null) === ['nombre' => 'CABEZA MOVIL', 'precio' => '9800.0000'], $r);

// --- el mismo cuerpo como arreglo, con el id como texto -----------------------------------------------------
$r = $valida(['id' => '7', 'precio' => 450]);
$chk('acepta arreglo, id como texto y precio entero', $r['ok'] === true && $r['id'] === 7 && $r['cambios'] === ['precio' => '450.0000'], $r);
$r = $valida(['id' => 7, 'nombre' => 'Solo el nombre']);
$chk('solo el nombre: no inventa un precio', $r['ok'] === true && $r['cambios'] === ['nombre' => 'Solo el nombre'] && $r['esperado'] === [], $r);

// --- el precio ----------------------------------------------------------------------------------------------
foreach (['0' => '0.0000', '0.5' => '0.5000', '12' => '12.0000', '007.10' => '7.1000', '100000000000' => '100000000000.0000', '9800.0000' => '9800.0000'] as $entra => $sale) {
    $r = $valida(['id' => 1, 'precio' => (string) $entra]);
    $chk("precio $entra -> $sale", $r['ok'] === true && ($r['cambios']['precio'] ?? null) === $sale, $r);
}
foreach (['', ' ', 'abc', '-1', '+5', '1e3', '12,5', '1.23456', '100000000000.01', '1 000', '.5', '5.', '0x10', true, [], null] as $malo) {
    $chk('rechaza el precio ' . json_encode($malo), $error(['id' => 1, 'precio' => $malo]) !== null);
}

// --- el nombre ----------------------------------------------------------------------------------------------
$chk('acepta un nombre de 150 bytes', $error(['id' => 1, 'nombre' => str_repeat('a', 150)]) === null);
$chk('rechaza un nombre de 151 bytes', $error(['id' => 1, 'nombre' => str_repeat('a', 151)]) !== null);
$chk('una ñ cuenta dos bytes: 75 caben, 76 no', $error(['id' => 1, 'nombre' => str_repeat('ñ', 75)]) === null && $error(['id' => 1, 'nombre' => str_repeat('ñ', 76)]) !== null);
foreach (['', '   ', "\n\t", 7, null, [], true] as $malo) {
    $chk('rechaza el nombre ' . json_encode($malo), $error(['id' => 1, 'nombre' => $malo]) !== null);
}

// --- el id y lo que falta -----------------------------------------------------------------------------------
foreach ([null, 0, -3, '0', 'x', '1.5', 1.5, '', [], '99999999999999999', true] as $malo) {
    $chk('rechaza el id ' . json_encode($malo), $error(['id' => $malo, 'nombre' => 'a']) !== null);
}
$chk('rechaza un cuerpo sin id', $error(['nombre' => 'a']) !== null);
$chk('rechaza un cuerpo sin nada que cambiar', $error(['id' => 1]) !== null);
$chk('rechaza un cuerpo con solo lo esperado', $error(['id' => 1, 'esperado' => ['nombre' => 'a']]) !== null);
foreach ([null, 'texto', 7, true] as $malo) {
    $chk('rechaza un cuerpo que no es un objeto: ' . json_encode($malo), $error($malo) !== null);
}

// --- lo esperado --------------------------------------------------------------------------------------------
$chk('rechaza un "esperado" que no es un objeto', $error(['id' => 1, 'nombre' => 'a', 'esperado' => 'x']) !== null);
$chk('rechaza un nombre esperado que no es texto', $error(['id' => 1, 'nombre' => 'a', 'esperado' => ['nombre' => 5]]) !== null);
$chk('rechaza un precio esperado que no es un número', $error(['id' => 1, 'precio' => '1', 'esperado' => ['precio' => 'caro']]) !== null);
$r = $valida(['id' => 1, 'nombre' => 'a', 'esperado' => null]);
$chk('un "esperado" nulo es lo mismo que no mandarlo', $r['ok'] === true && $r['esperado'] === [], $r);
$r = $valida(['id' => 1, 'precio' => '1', 'esperado' => ['precio' => 9800]]);
$chk('el precio esperado también se normaliza a 4 decimales', ($r['esperado']['precio'] ?? null) === '9800.0000', $r);

// --- el nombre esperado se guarda tal cual (se compara con lo que hay, no se limpia) ------------------------
$r = $valida(['id' => 1, 'nombre' => 'a', 'esperado' => ['nombre' => ' con espacios ']]);
$chk('el nombre esperado no se recorta', ($r['esperado']['nombre'] ?? null) === ' con espacios ', $r);

if ($fallos !== []) {
    fwrite(STDERR, count($fallos) . " de $total verificaciones fallaron:\n  - " . implode("\n  - ", $fallos) . "\n");
    exit(1);
}
echo "OK ($total verificaciones)\n";
exit(0);
