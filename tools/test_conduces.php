<?php
/**
 * test_conduces.php — Conduces de mercancía de Ferretería, sin DB.
 *
 * Un conduce sale de una cotización de Ferretería y se imprime como ella, pero
 * sin precios ni totales. Este script prueba por CLI, sin base de datos ni
 * servidor, lo que se puede probar así: las reglas puras de FerreteriaConduce,
 * el PDF en modo conduce, conduceModel con una conexión falsa y las piezas
 * puras del controller. Cada tarea agrega su sección encima del resumen. Lo
 * que necesita MySQL o el servidor va en tests/test_conduces.http.
 *
 * Uso:
 *   php tools/test_conduces.php
 */

require_once __DIR__ . '/../src/Utils/Pdf/BrandingResolver.php';

// Sin marca global, como test_cotizacion_ferreteria.php: sin tenant resuelto,
// el logo y el sello del repo (los de Gratex) son el fallback de las
// plantillas, y no pueden salir en nada que este script dibuje para Ferretería.
BrandingResolver::sinMarcaGlobal();

$fallos = 0;
$total = 0;
$chk = function (string $desc, bool $ok) use (&$fallos, &$total) {
    $total++;
    if (!$ok) {
        $fallos++;
    }
    printf("  [%s] %s\n", $ok ? 'OK  ' : 'FALLA', $desc);
};

// Cantidades y montos se comparan EXACTOS, como en test_cotizacion_ferreteria.php.
// (float) porque el JSON y las filas de PDO traen 2 o '2.000'.
$igual = static fn($a, $b): bool => is_numeric($a) && is_numeric($b) && (float) $a === (float) $b;

// La firma de un método estático, para fijar el contrato de FerreteriaConduce.
$firmaConduce = static function (string $metodo): string {
    if (!method_exists('FerreteriaConduce', $metodo)) {
        return '(no existe)';
    }
    $m = new ReflectionMethod('FerreteriaConduce', $metodo);
    $params = array_map(static fn(ReflectionParameter $p): string => $p->getType() . ' $' . $p->getName(), $m->getParameters());
    return ($m->isPublic() ? 'public ' : 'private ') . ($m->isStatic() ? 'static ' : '')
        . $metodo . '(' . implode(', ', $params) . '): ' . $m->getReturnType();
};

// ---------------------------------------------------------------------------
// FerreteriaConduce: constantes, codigo y disponibilidad (Task 3)
// ---------------------------------------------------------------------------

require_once __DIR__ . '/../src/Utils/Cotizacion/FerreteriaConduce.php';

echo "== FerreteriaConduce: constantes ==\n";
$chk('FerreteriaConduce es final', (new ReflectionClass('FerreteriaConduce'))->isFinal());
foreach ([
    'PREFIJO' => 'CON-',
    'MSG_NO_DISPONIBLE' => 'Los conduces no están disponibles para tu empresa.',
    'MSG_SIN_CLIENTE' => 'Elige un cliente para el conduce.',
    'MSG_SIN_LINEAS' => 'Agrega al menos una línea al conduce.',
    'MSG_FECHA' => 'La fecha no es válida.',
    'MSG_AJUSTES' => 'Los conduces no llevan cargos ni abonos.',
    'MSG_COTIZACION' => 'Elige una cotización de Ferretería para crear el conduce.',
    'MSG_NO_EXISTE' => 'Este conduce ya no existe. Puede que lo hayan eliminado; vuelve al listado.',
    'MSG_CHOQUE' => 'Otro conduce se guardó al mismo tiempo. Vuelve a guardar.',
    'MSG_PRODUCTO_FK' => 'Un producto del conduce ya no existe en el catálogo (lo eliminaron mientras lo editabas). Búscalo de nuevo o quita la línea.',
    'MSG_COTIZACION_FK' => 'La cotización de origen ya no existe; vuelve a Cotizaciones.',
] as $constante => $texto) {
    $nombreConst = 'FerreteriaConduce::' . $constante;
    $chk("{$constante} = \"{$texto}\"", defined($nombreConst) && constant($nombreConst) === $texto);
}

echo "\n== FerreteriaConduce::codigo ==\n";
$chk('firma: public static codigo(int $numero): string', $firmaConduce('codigo') === 'public static codigo(int $numero): string');
$chk('codigo(1) = CON-000001', FerreteriaConduce::codigo(1) === 'CON-000001');
$chk('codigo(123) = CON-000123', FerreteriaConduce::codigo(123) === 'CON-000123');
$chk('codigo(999999) = CON-999999', FerreteriaConduce::codigo(999999) === 'CON-999999');
$chk('codigo(1234567) = CON-1234567 (no se corta)', FerreteriaConduce::codigo(1234567) === 'CON-1234567');

echo "\n== FerreteriaConduce::errorDisponibilidad ==\n";
$chk('firma: public static errorDisponibilidad(): ?string', $firmaConduce('errorDisponibilidad') === 'public static errorDisponibilidad(): ?string');
// delTenant() lee TenantResolver::current(): se le pone el tenant a mano (es
// privado; Reflection basta para un CLI), como en la sección de Task 6 de
// test_cotizacion_ferreteria.php, y se deja como estaba, sin tenant. Las
// secciones siguientes pueden usar $tenantResuelto igual.
$tenantResuelto = new ReflectionProperty('TenantResolver', 'current');
// delTenant() avisa en el error_log de un formato desconocido: mientras corre
// la sección el log va a un archivo temporal, se comprueba el aviso y la
// salida queda limpia.
$logConduce = (string) tempnam(sys_get_temp_dir(), 'conduce');
$logAnteriorConduce = ini_set('error_log', $logConduce);
$leerLogConduce = function () use ($logConduce): string {
    clearstatcache();
    $txt = (string) file_get_contents($logConduce);
    file_put_contents($logConduce, '');
    return $txt;
};
$disponibilidad = function (?array $tenant) use ($tenantResuelto): ?string {
    $tenantResuelto->setValue(null, $tenant);
    return FerreteriaConduce::errorDisponibilidad();
};
$msgNoDisponible = 'Los conduces no están disponibles para tu empresa.';
$chk('sin tenant resuelto (un solo tenant) = "no están disponibles"', $disponibilidad(null) === $msgNoDisponible && $leerLogConduce() === '');
$chk('tenant sin la columna (master sin la 011) = "no están disponibles", sin aviso',
    $disponibilidad(['id' => 1, 'rnc' => '101000000', 'tipo' => 'app']) === $msgNoDisponible && $leerLogConduce() === '');
$chk("cotizacion_formato 'gratex' = \"no están disponibles\"",
    $disponibilidad(['id' => 1, 'cotizacion_formato' => 'gratex']) === $msgNoDisponible && $leerLogConduce() === '');
$chk("cotizacion_formato 'ferreteria' = null (disponibles)",
    $disponibilidad(['id' => 5, 'cotizacion_formato' => 'ferreteria']) === null && $leerLogConduce() === '');
$chk("cotizacion_formato 'Ferreteria' (desconocido) = \"no están disponibles\", y lo avisa",
    $disponibilidad(['id' => 5, 'cotizacion_formato' => 'Ferreteria']) === $msgNoDisponible
    && str_contains($leerLogConduce(), '"Ferreteria" no es un formato conocido'));
$chk('cotizacion_formato 5 (no es texto) = "no están disponibles", y lo avisa',
    $disponibilidad(['id' => 5, 'cotizacion_formato' => 5]) === $msgNoDisponible
    && str_contains($leerLogConduce(), 'no es un formato conocido'));
$tenantResuelto->setValue(null, null);
ini_set('error_log', $logAnteriorConduce === false ? '' : $logAnteriorConduce);
@unlink($logConduce);

// ---------------------------------------------------------------------------
// FerreteriaConduce::validarForma (Task 3)
// ---------------------------------------------------------------------------

require_once __DIR__ . '/../src/Models/unidadMedidaModel.php';   // solo decimalesDe (estático, sin DB)

echo "\n== FerreteriaConduce::validarForma ==\n";
$chk('firma: public static validarForma(object $body, callable $problemaCantidad, callable $unidadValida, bool $esCreacion): array',
    $firmaConduce('validarForma') === 'public static validarForma(object $body, callable $problemaCantidad, callable $unidadValida, bool $esCreacion): array');

// Catálogo de unidades de prueba: [código DGII => [descripción, admite decimales]],
// el mismo de test_cotizacion_ferreteria.php. Las secciones siguientes pueden
// usar $problemaCantidadFx y $unidadValidaFx igual.
$unidadesFx = ['43' => ['Unidad', false], '26' => ['Metro', true], '21' => ['Kilogramo', true]];
$llamadasCantidad = [];
// Mismas reglas y textos que unidadMedidaModel::problemaCantidad, con el catálogo de arriba en vez de master.
$problemaCantidadFx = function (float $cantidad, string $unidad, int $maxDec) use ($unidadesFx, &$llamadasCantidad): ?string {
    $llamadasCantidad[] = [$cantidad, $unidad, $maxDec];
    if (!($cantidad > 0) || !(round($cantidad, $maxDec) > 0)) {
        return 'La cantidad debe ser mayor que 0.';
    }
    $dec = unidadMedidaModel::decimalesDe($cantidad);
    if ($dec === 0) {
        return null;
    }
    [$nombre, $permite] = $unidadesFx[$unidad] ?? ['', true];
    if (!$permite) {
        return 'La unidad «' . ($nombre !== '' ? $nombre : 'Unidad') . '» no admite fracciones: usa una cantidad entera o cambia la unidad.';
    }
    if ($dec > $maxDec) {
        return 'La cantidad admite hasta ' . $maxDec . ' decimales.';
    }
    return null;
};
$unidadValidaFx = static fn(string $unidad): bool => isset($unidadesFx[$unidad]);

// El POST del formulario del conduce (spec 4.1): una línea de producto y una
// libre, que va sin precio, sin unidad y sin indicadores.
$ejemploConduce = <<<'JSON'
{ "cotizacion_id": 12, "client_id": 123, "date": "2026-10-05 09:30:00",
  "items": [
    { "product_id": 55, "description": "FUNDAS CEMENTO GRIS", "quantity": 2,
      "unidad_medida": "43", "amount": 935, "indicador_facturacion": 1, "indicador_bien_servicio": 1 },
    { "product_id": null, "description": "  CORTE DE TUBO ", "quantity": 1, "amount": 0 }
  ] }
JSON;
// Cada prueba parte de una copia nueva del ejemplo, decodificada como en el
// controller (objetos, no arreglos), y le cambia una sola cosa.
$cuerpoConduce = static function (?callable $cambiar = null) use ($ejemploConduce): object {
    $b = json_decode($ejemploConduce);
    if ($cambiar !== null) {
        $cambiar($b);
    }
    return $b;
};
$validarConduce = static fn(object $b, bool $esCreacion = true): array
    => FerreteriaConduce::validarForma($b, $problemaCantidadFx, $unidadValidaFx, $esCreacion);
$rechazaConduce = function (string $desc, object $b, string $mensaje, bool $esCreacion = true) use ($validarConduce, $chk) {
    $r = $validarConduce($b, $esCreacion);
    $ok = ($r['ok'] ?? null) === false && ($r['error'] ?? null) === $mensaje;
    $chk(($esCreacion ? 'POST' : 'PUT') . ', ' . $desc . ' -> "' . $mensaje . '"'
        . ($ok ? '' : ' (dio ' . json_encode($r, JSON_UNESCAPED_UNICODE) . ')'), $ok);
};

$llamadasCantidad = [];
$r = $validarConduce($cuerpoConduce());
$chk('el ejemplo (POST) pasa', ($r['ok'] ?? null) === true);
$cot = $r['cot'] ?? [];
$chk('cot trae date, client_id, cotizacion_id e items, en ese orden', array_keys($cot) === ['date', 'client_id', 'cotizacion_id', 'items']);
$chk('client_id 123, cotizacion_id 12 y la fecha tal cual', ($cot['client_id'] ?? null) === 123
    && ($cot['cotizacion_id'] ?? null) === 12 && ($cot['date'] ?? null) === '2026-10-05 09:30:00');
$chk('línea de producto normalizada', ($cot['items'][0] ?? null) === [
    'product_id' => 55, 'description' => 'FUNDAS CEMENTO GRIS', 'quantity' => 2.0, 'amount' => 935.0,
    'unidad_medida' => '43', 'indicador_facturacion' => 1, 'indicador_bien_servicio' => 1,
]);
$chk("línea libre: precio 0, descripción recortada, unidad '43', indicador 1, bien", ($cot['items'][1] ?? null) === [
    'product_id' => null, 'description' => 'CORTE DE TUBO', 'quantity' => 1.0, 'amount' => 0.0,
    'unidad_medida' => '43', 'indicador_facturacion' => 1, 'indicador_bien_servicio' => 1,
]);
$chk("problemaCantidad recibe la unidad normalizada ('43') y 2 decimales, una vez por línea",
    $llamadasCantidad === [[2.0, '43', 2], [1.0, '43', 2]]);
$chk('la cantidad sale como número exacto (2 y 1)', $igual($cot['items'][0]['quantity'] ?? null, 2) && $igual($cot['items'][1]['quantity'] ?? null, 1));

// --- cotizacion_id: obligatorio al crear, ignorado al editar ---
$r = $validarConduce($cuerpoConduce(function (object $b) { unset($b->cotizacion_id); }), false);
$chk('PUT sin cotizacion_id pasa (cotizacion_id null)', ($r['ok'] ?? null) === true && $r['cot']['cotizacion_id'] === null);
$r = $validarConduce($cuerpoConduce(function (object $b) { $b->cotizacion_id = 99; }), false);
$chk('PUT con cotizacion_id 99: se ignora (null), un conduce no cambia de origen', ($r['ok'] ?? null) === true && $r['cot']['cotizacion_id'] === null);
$r = $validarConduce($cuerpoConduce(function (object $b) { $b->cotizacion_id = '12'; }));
$chk('POST con cotizacion_id "12" (texto) = 12', ($r['ok'] ?? null) === true && $r['cot']['cotizacion_id'] === 12);
$rechazaConduce('sin cotizacion_id', $cuerpoConduce(function (object $b) { unset($b->cotizacion_id); }),
    'Elige una cotización de Ferretería para crear el conduce.');
foreach ([0, -4, 'abc', 1.5, null, true] as $malo) {
    $rechazaConduce('cotizacion_id ' . var_export($malo, true), $cuerpoConduce(function (object $b) use ($malo) { $b->cotizacion_id = $malo; }),
        'Elige una cotización de Ferretería para crear el conduce.');
}

// --- cliente ---
$rechazaConduce('sin client_id', $cuerpoConduce(function (object $b) { unset($b->client_id); }), 'Elige un cliente para el conduce.');
$rechazaConduce('sin client_id', $cuerpoConduce(function (object $b) { unset($b->client_id); }), 'Elige un cliente para el conduce.', false);
foreach ([0, -1, 'abc', 2.5] as $malo) {
    $rechazaConduce('client_id ' . var_export($malo, true), $cuerpoConduce(function (object $b) use ($malo) { $b->client_id = $malo; }),
        'Elige un cliente para el conduce.');
}
$r = $validarConduce($cuerpoConduce(function (object $b) { $b->client_id = '123'; }));
$chk('client_id "123" (texto) = 123', ($r['ok'] ?? null) === true && $r['cot']['client_id'] === 123);

// --- líneas ---
$rechazaConduce('items vacío', $cuerpoConduce(function (object $b) { $b->items = []; }), 'Agrega al menos una línea al conduce.');
$rechazaConduce('items vacío', $cuerpoConduce(function (object $b) { $b->items = []; }), 'Agrega al menos una línea al conduce.', false);
$rechazaConduce('sin items', $cuerpoConduce(function (object $b) { unset($b->items); }), 'Agrega al menos una línea al conduce.');
$rechazaConduce('items que no es una lista ({})', $cuerpoConduce(function (object $b) { $b->items = new stdClass(); }), 'Agrega al menos una línea al conduce.');

// --- fecha ---
$r = $validarConduce($cuerpoConduce(function (object $b) { unset($b->date); }));
$chk('POST sin fecha: date null (el modelo pone la de ahora)', ($r['ok'] ?? null) === true && $r['cot']['date'] === null);
$r = $validarConduce($cuerpoConduce(function (object $b) { $b->date = ''; }), false);
$chk('PUT con fecha vacía: date null (se conserva la guardada)', ($r['ok'] ?? null) === true && $r['cot']['date'] === null);
$antes = (new DateTimeImmutable('now', new DateTimeZone('America/Santo_Domingo')))->format('H:i');
$r = $validarConduce($cuerpoConduce(function (object $b) { $b->date = '2026-10-05'; }));
$despues = (new DateTimeImmutable('now', new DateTimeZone('America/Santo_Domingo')))->format('H:i');
$fecha = (string) ($r['cot']['date'] ?? '');
$chk("solo el día: se le pone la hora de ahora en RD ({$fecha})",
    preg_match('/^2026-10-05 \d{2}:\d{2}:\d{2}$/', $fecha) === 1 && in_array(substr($fecha, 11, 5), [$antes, $despues], true));
foreach (['2026-02-30', '2026-10-05 25:00:00', '05/10/2026', 20261005, '2026-10-05T09:30:00'] as $mala) {
    $rechazaConduce('fecha ' . var_export($mala, true), $cuerpoConduce(function (object $b) use ($mala) { $b->date = $mala; }), 'La fecha no es válida.');
}

// --- cargos y abonos: no existen en un conduce ---
$rechazaConduce('con ajustes de la cotización', $cuerpoConduce(function (object $b) {
    $b->ajustes = (object) ['cargos_bancarios' => 0, 'manejo_bancario' => 0, 'mano_obra' => 1500, 'abono' => 0, 'retencion_isr' => false];
}), 'Los conduces no llevan cargos ni abonos.');
$rechazaConduce('ajustes {} (vacío)', $cuerpoConduce(function (object $b) { $b->ajustes = new stdClass(); }), 'Los conduces no llevan cargos ni abonos.');
$rechazaConduce('ajustes null', $cuerpoConduce(function (object $b) { $b->ajustes = null; }), 'Los conduces no llevan cargos ni abonos.');
$rechazaConduce('con ajustes', $cuerpoConduce(function (object $b) { $b->ajustes = new stdClass(); }), 'Los conduces no llevan cargos ni abonos.', false);

// --- formato y tipo no se leen ---
$r = $validarConduce($cuerpoConduce(function (object $b) { $b->formato = 'gratex'; $b->tipo = 'factura'; $b->total = 1; $b->user_id = 9; }));
$chk('formato, tipo, total y user_id del cuerpo se ignoran (pasa, y cot no los trae)',
    ($r['ok'] ?? null) === true && array_keys($r['cot']) === ['date', 'client_id', 'cotizacion_id', 'items']);

// --- precio: 0 vale, negativo no ---
$r = $validarConduce($cuerpoConduce(function (object $b) { $b->items[0]->amount = '0'; }));
$chk('precio "0" en una línea de producto pasa (se guarda 0.0)', ($r['ok'] ?? null) === true && $r['cot']['items'][0]['amount'] === 0.0);
$r = $validarConduce($cuerpoConduce(function (object $b) { $b->items[1]->amount = 84.7458; }));
$chk('precio de 4 decimales pasa', ($r['ok'] ?? null) === true && $r['cot']['items'][1]['amount'] === 84.7458);
$rechazaConduce('precio negativo', $cuerpoConduce(function (object $b) { $b->items[0]->amount = -935; }), 'Línea 1: el precio no puede ser negativo.');
$rechazaConduce('precio "-0.01" en la línea 2', $cuerpoConduce(function (object $b) { $b->items[1]->amount = '-0.01'; }),
    'Línea 2: el precio no puede ser negativo.', false);
$rechazaConduce('precio con 5 decimales', $cuerpoConduce(function (object $b) { $b->items[0]->amount = 84.74581; }), 'Línea 1: el precio admite hasta 4 decimales.');
$rechazaConduce('precio "caro"', $cuerpoConduce(function (object $b) { $b->items[0]->amount = 'caro'; }), 'El precio de la línea 1 no es válido. Revísalo.');
$rechazaConduce('sin precio', $cuerpoConduce(function (object $b) { unset($b->items[1]->amount); }), 'El precio de la línea 2 no es válido. Revísalo.');
$rechazaConduce('precio -1 + ITBIS 5: gana el precio (el orden de la cotización)', $cuerpoConduce(function (object $b) {
    $b->items[0]->amount = -1;
    $b->items[0]->indicador_facturacion = 5;
}), 'Línea 1: el precio no puede ser negativo.');

// --- el resto de las reglas de línea son las de la cotización, con sus textos ---
$rechazaConduce('una línea que no es objeto', $cuerpoConduce(function (object $b) { $b->items[0] = 'FUNDAS'; }),
    'La línea 1 no es válida. Quítala y vuelve a agregarla.');
$rechazaConduce('descripción en blanco (línea 2)', $cuerpoConduce(function (object $b) { $b->items[1]->description = '   '; }),
    'La línea 2 no tiene descripción. Escríbela o quita esa línea.');
$rechazaConduce('unidad que no está en el catálogo', $cuerpoConduce(function (object $b) { $b->items[0]->unidad_medida = '999'; }),
    'La unidad de medida de la línea 1 no es válida. Elige otra unidad en esa línea.');
$rechazaConduce('cantidad 0', $cuerpoConduce(function (object $b) { $b->items[0]->quantity = 0; }), 'Línea 1: la cantidad debe ser mayor que 0.');
$rechazaConduce('1.5 en Unidad', $cuerpoConduce(function (object $b) { $b->items[0]->quantity = 1.5; }),
    'Línea 1: la unidad «Unidad» no admite fracciones: usa una cantidad entera o cambia la unidad.');
$rechazaConduce('ITBIS 5', $cuerpoConduce(function (object $b) { $b->items[0]->indicador_facturacion = 5; }),
    'Línea 1: el tipo de ITBIS no es válido. Elige 18%, 16%, 0% o exento.');
$rechazaConduce('bien/servicio 3', $cuerpoConduce(function (object $b) { $b->items[0]->indicador_bien_servicio = 3; }),
    'Línea 1: elige si es un bien o un servicio.');
$rechazaConduce('product_id -3', $cuerpoConduce(function (object $b) { $b->items[0]->product_id = -3; }),
    'Línea 1: el producto no es válido. Búscalo de nuevo o déjala como línea libre.');
$r = $validarConduce($cuerpoConduce(function (object $b) { $b->items[0]->description = "FUNDA\r\nCEMENTO\tGRIS"; }));
$chk('la descripción se guarda limpia (saltos y tabuladores -> un espacio)', ($r['cot']['items'][0]['description'] ?? null) === 'FUNDA CEMENTO GRIS');

// --- orden de las reglas de cabecera ---
$rechazaConduce('ajustes + sin cliente: los ajustes primero', $cuerpoConduce(function (object $b) { $b->ajustes = null; unset($b->client_id); }),
    'Los conduces no llevan cargos ni abonos.');
$rechazaConduce('sin cotización + sin cliente: la cotización primero', $cuerpoConduce(function (object $b) { unset($b->cotizacion_id, $b->client_id); }),
    'Elige una cotización de Ferretería para crear el conduce.');
$rechazaConduce('sin cliente + fecha mala: el cliente primero', $cuerpoConduce(function (object $b) { unset($b->client_id); $b->date = 'x'; }),
    'Elige un cliente para el conduce.');
$rechazaConduce('fecha mala + sin líneas: la fecha primero', $cuerpoConduce(function (object $b) { $b->date = 'x'; $b->items = []; }),
    'La fecha no es válida.');

// ---------------------------------------------------------------------------
// FerreteriaConduce::itemsPdf y ::datosPdf (Task 3)
// ---------------------------------------------------------------------------

echo "\n== FerreteriaConduce::itemsPdf ==\n";
$chk('firma: public static itemsPdf(array $items, array $nombresUnidad): array',
    $firmaConduce('itemsPdf') === 'public static itemsPdf(array $items, array $nombresUnidad): array');

// [código DGII => descripción], como lo arma FerreteriaConduce desde
// unidadMedidaModel::all(). Las secciones siguientes pueden usarlo igual.
$nombresUnidadFx = [43 => 'Unidad', 26 => 'Metro', 21 => 'Kilogramo'];
// Filas de conduce_items como las devuelve PDO: los DECIMAL como texto.
$filasItemsFx = [
    ['id' => 1, 'conduce_id' => 7, 'product_id' => 55, 'description' => 'FUNDAS CEMENTO GRIS', 'quantity' => '2.000',
        'unidad_medida' => '43', 'amount' => '935.0000', 'indicador_facturacion' => 1, 'indicador_bien_servicio' => 1, 'activo' => 1],
    ['id' => 2, 'conduce_id' => 7, 'product_id' => 60, 'description' => 'CABLE ELECTRICO #12', 'quantity' => '12.500',
        'unidad_medida' => '26', 'amount' => '45.5000', 'indicador_facturacion' => 1, 'indicador_bien_servicio' => 1, 'activo' => 1],
    ['id' => 3, 'conduce_id' => 7, 'product_id' => null, 'description' => 'PIEZA ESPECIAL', 'quantity' => '1.000',
        'unidad_medida' => '999', 'amount' => '0.0000', 'indicador_facturacion' => 1, 'indicador_bien_servicio' => 1, 'activo' => 1],
];
$itemsPdfFx = FerreteriaConduce::itemsPdf($filasItemsFx, $nombresUnidadFx);
$chk('filas guardadas: descripción, cantidad como número y el nombre de la unidad', $itemsPdfFx === [
    ['description' => 'FUNDAS CEMENTO GRIS', 'quantity' => 2.0, 'unidad' => 'Unidad'],
    ['description' => 'CABLE ELECTRICO #12', 'quantity' => 12.5, 'unidad' => 'Metro'],
    ['description' => 'PIEZA ESPECIAL', 'quantity' => 1.0, 'unidad' => '999'],
]);
$chk('unidad que no está en el mapa (inactiva o desconocida): su código guardado', ($itemsPdfFx[2]['unidad'] ?? null) === '999');
$chk('nunca el precio: ninguna línea trae amount', !str_contains((string) json_encode($itemsPdfFx), 'amount'));
$chk('catálogo ilegible (mapa vacío): cada línea imprime su código',
    array_column(FerreteriaConduce::itemsPdf($filasItemsFx, []), 'unidad') === ['43', '26', '999']);
$chk('nombre en blanco en el mapa: el código', FerreteriaConduce::itemsPdf([['description' => 'X', 'quantity' => '1.000', 'unidad_medida' => '26']],
    [26 => '  '])[0]['unidad'] === '26');
$chk("sin unidad (o ''): Unidad (43), como al guardar",
    FerreteriaConduce::itemsPdf([['description' => 'X', 'quantity' => 1], ['description' => 'Y', 'quantity' => 1, 'unidad_medida' => '']], $nombresUnidadFx)
        === [['description' => 'X', 'quantity' => 1.0, 'unidad' => 'Unidad'], ['description' => 'Y', 'quantity' => 1.0, 'unidad' => 'Unidad']]);
$chk('el mapa con claves de texto (\'43\') sirve igual', FerreteriaConduce::itemsPdf($filasItemsFx, ['43' => 'Unidad'])[0]['unidad'] === 'Unidad');
$chk('líneas como objetos (stdClass) sirven igual',
    FerreteriaConduce::itemsPdf(array_map(static fn(array $f): object => (object) $f, $filasItemsFx), $nombresUnidadFx) === $itemsPdfFx);
$validadasFx = $validarConduce($cuerpoConduce())['cot']['items'] ?? [];
$chk('las líneas ya validadas de una vista previa sirven igual', FerreteriaConduce::itemsPdf($validadasFx, $nombresUnidadFx) === [
    ['description' => 'FUNDAS CEMENTO GRIS', 'quantity' => 2.0, 'unidad' => 'Unidad'],
    ['description' => 'CORTE DE TUBO', 'quantity' => 1.0, 'unidad' => 'Unidad'],
]);
$chk('sin líneas: []', FerreteriaConduce::itemsPdf([], $nombresUnidadFx) === []);

echo "\n== FerreteriaConduce::datosPdf ==\n";
$chk('firma: public static datosPdf(array $row, array $nombresUnidad, ?string $code): array',
    $firmaConduce('datosPdf') === 'public static datosPdf(array $row, array $nombresUnidad, ?string $code): array');

// Una fila de conduceModel::obtener() (spec 4.1): las columnas de conduces, el
// código de la cotización de origen, el cliente del LEFT JOIN, el nombre
// guardado y las líneas activas. Las secciones siguientes pueden usarla igual.
$filaConduceFx = [
    'id' => 7, 'numero' => 1, 'code' => 'CON-000001', 'date' => '2026-10-05 09:30:00',
    'cotizacion_id' => 12, 'client_id' => 123, 'user_id' => 5, 'activo' => 1,
    'created_at' => '2026-10-05 09:31:00', 'updated_at' => null,
    'cotizacion_code' => 'COT-000012',
    'client_name' => 'Juan Perez', 'company_name' => 'HOSPITAL DOCENTE', 'rnc' => '401515131',
    'client_name_guardado' => 'HOSPITAL DOCENTE',
    'items' => $filasItemsFx,
];
$datosFx = FerreteriaConduce::datosPdf($filaConduceFx, $nombresUnidadFx, 'CON-000001');
$chk('fila guardada: el documento y el cliente del renderizador, exactos', $datosFx === [
    'conduce' => [
        'documento' => 'conduce',
        'code' => 'CON-000001',
        'date' => '2026-10-05 09:30:00',
        'cotizacion_code' => 'COT-000012',
        'items' => $itemsPdfFx,
    ],
    'cliente' => ['razon_social' => null, 'company_name' => 'HOSPITAL DOCENTE', 'client_name' => 'Juan Perez', 'rnc' => '401515131'],
]);
$chk("el documento no trae 'totales' (el conduce no tiene)", !array_key_exists('totales', $datosFx['conduce'] ?? []));
$chk('ni un precio en lo que recibe el PDF (ni amount ni 935)',
    !str_contains((string) json_encode($datosFx), 'amount') && !str_contains((string) json_encode($datosFx), '935'));
$previaFx = FerreteriaConduce::datosPdf($filaConduceFx, $nombresUnidadFx, null);
$chk('code null (vista previa): el documento va sin número aunque la fila tenga uno (el PDF imprime VISTA PREVIA)',
    array_key_exists('code', $previaFx['conduce']) && $previaFx['conduce']['code'] === null);
$chk('cotización de origen eliminada (cotizacion_code NULL): null, sin línea Cotización',
    FerreteriaConduce::datosPdf(['cotizacion_code' => null] + $filaConduceFx, $nombresUnidadFx, 'CON-000001')['conduce']['cotizacion_code'] === null);
$chk("cotizacion_code '' cuenta como eliminada (null)",
    FerreteriaConduce::datosPdf(['cotizacion_code' => ''] + $filaConduceFx, $nombresUnidadFx, 'CON-000001')['conduce']['cotizacion_code'] === null);
$borradoFx = ['client_name' => null, 'company_name' => null, 'rnc' => null] + $filaConduceFx;
$chk('cliente borrado (el LEFT JOIN no trae nada): el nombre guardado y RNC vacío',
    FerreteriaConduce::datosPdf($borradoFx, $nombresUnidadFx, 'CON-000001')['cliente']
        === ['razon_social' => null, 'company_name' => null, 'client_name' => 'HOSPITAL DOCENTE', 'rnc' => '']);
$sinClaveFx = $filaConduceFx;
unset($sinClaveFx['client_name'], $sinClaveFx['company_name'], $sinClaveFx['rnc']);
$chk('cliente borrado sin las claves del JOIN: lo mismo',
    FerreteriaConduce::datosPdf($sinClaveFx, $nombresUnidadFx, null)['cliente']
        === ['razon_social' => null, 'company_name' => null, 'client_name' => 'HOSPITAL DOCENTE', 'rnc' => '']);
$chk('con razon_social en la fila (completada con getCliente): pasa tal cual',
    FerreteriaConduce::datosPdf(['razon_social' => 'HOSPITAL DOCENTE DR. FRANCISCO E. MOSCOSO PUELLO'] + $filaConduceFx, $nombresUnidadFx, null)['cliente']
        === ['razon_social' => 'HOSPITAL DOCENTE DR. FRANCISCO E. MOSCOSO PUELLO', 'company_name' => 'HOSPITAL DOCENTE',
            'client_name' => 'Juan Perez', 'rnc' => '401515131']);
$antes = (new DateTimeImmutable('now', new DateTimeZone('America/Santo_Domingo')))->format('Y-m-d H:i');
$sinFechaFx = FerreteriaConduce::datosPdf(['date' => null] + $filaConduceFx, $nombresUnidadFx, null)['conduce']['date'];
$despues = (new DateTimeImmutable('now', new DateTimeZone('America/Santo_Domingo')))->format('Y-m-d H:i');
$chk("sin fecha: ahora en RD ({$sinFechaFx})", preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $sinFechaFx) === 1
    && in_array(substr($sinFechaFx, 0, 16), [$antes, $despues], true));
$sinItemsFx = $filaConduceFx;
unset($sinItemsFx['items']);
$chk('sin items: []', FerreteriaConduce::datosPdf($sinItemsFx, $nombresUnidadFx, null)['conduce']['items'] === []);

// ===========================================================================
// T4 — PDF del conduce (FerreteriaCotizacionPdf con documento => 'conduce')
// ===========================================================================
// Mismo metodo que la seccion T5 de tools/test_cotizacion_ferreteria.php: FPDF
// comprime cada pagina (FlateDecode) y el renderizador no tiene modo de
// prueba, asi que se inflan los streams con gzuncompress y se busca el texto
// ahi: se prueba el mismo PDF que recibe el usuario. Con --pdf ademas escribe
// muestras en tools/out/ para mirarlas a ojo; --grid (implica --pdf) les
// superpone la rejilla de 10 mm.

require_once __DIR__ . '/../src/Utils/Cotizacion/FerreteriaCotizacionPdf.php';

echo "\n== T4: PDF del conduce (FerreteriaCotizacionPdf) ==\n";

$t4Args = array_slice($argv ?? [], 1);
$t4Grilla = in_array('--grid', $t4Args, true);
$t4Escribir = $t4Grilla || in_array('--pdf', $t4Args, true);

// Emisor, cliente y lineas: los del fixture de la cotizacion de Ferreteria.
$t4Fx = json_decode((string) file_get_contents(__DIR__ . '/fixtures/cotizacion_ferreteria.json'), true);
$t4Casos = array_column($t4Fx['casos'] ?? [], null, 'id');
$t4Logo = __DIR__ . '/fixtures/ferreteria_logo.jpeg';
$chk('fixture: cotizacion_ferreteria.json (pintura, largo_60) y ferreteria_logo.jpeg',
    isset($t4Casos['pintura'], $t4Casos['largo_60'], $t4Fx['emisor']) && is_file($t4Logo));

// Paginas = objetos "/Type /Page"; los N primeros streams son su contenido
// (despues vienen los recursos, como el logo).
$t4ContarPaginas = static fn(string $bytes): int => (int) preg_match_all('#/Type /Page\b#', $bytes);
$t4Paginas = static function (string $bytes) use ($t4ContarPaginas): array {
    preg_match_all('/stream\n(.*?)\nendstream/s', $bytes, $m);
    $paginas = [];
    foreach (array_slice($m[1], 0, $t4ContarPaginas($bytes)) as $s) {
        $plano = @gzuncompress($s);
        $paginas[] = $plano === false ? $s : $plano;
    }
    return $paginas;
};
$t4Iso = static fn(string $s): string => mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8');
// Rect de FPDF: "x y w h re S" en puntos (mm * 72 / 25.4), con h negativo.
$t4Celda = static fn(float $w, float $h): string => sprintf('%.2F %.2F re S', $w * 72 / 25.4, -$h * 72 / 25.4);
// X e Y (puntos) del primer texto que empieza con $inicio: "BT x y Td (texto) Tj".
$t4Pos = static function (string $txt, string $inicio): ?array {
    return preg_match('/BT ([\d.]+) ([\d.]+) Td \(' . preg_quote($inicio, '/') . '/', $txt, $m) ? [$m[1], (float) $m[2]] : null;
};

// Lo que FerreteriaConduce::datosPdf le pasa al renderizador (spec 4.4): las
// lineas con el nombre de su unidad ya resuelto, sin precios ni totales.
// $unidades se reparte en ciclo sobre las lineas.
$t4Conduce = static function (array $lineas, ?string $code, ?string $cotizacionCode, array $unidades): array {
    $items = [];
    foreach (array_values($lineas) as $i => $l) {
        $items[] = [
            'description' => (string) $l['description'],
            'quantity' => (float) $l['quantity'],
            'unidad' => $unidades[$i % count($unidades)],
        ];
    }
    return [
        'documento' => 'conduce',
        'code' => $code,
        'date' => '2026-05-14 09:00:00',
        'cotizacion_code' => $cotizacionCode,
        'items' => $items,
    ];
};
$t4Render = static function (array $doc, bool $grilla = false) use ($t4Fx, $t4Casos, $t4Logo): string {
    $pdf = new FerreteriaCotizacionPdf($doc, $t4Fx['emisor'], $t4Casos['pintura']['cliente'], $t4Logo);
    $pdf->setDebugGrid($grilla);
    return $pdf->render();
};

// --- el renderizador sigue puro: la unidad llega por nombre, no lee el catalogo ---
$t4Codigo = '';
foreach (token_get_all((string) file_get_contents(__DIR__ . '/../src/Utils/Cotizacion/FerreteriaCotizacionPdf.php')) as $tok) {
    if (is_array($tok) && in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) {
        continue;
    }
    $t4Codigo .= is_array($tok) ? $tok[1] : $tok;
}
$chk('puro: no usa Database, TenantResolver, BrandingResolver, EmisorConfigModel ni unidadMedidaModel',
    !preg_match('/\b(Database|TenantResolver|BrandingResolver|EmisorConfigModel|unidadMedidaModel)\b/', $t4Codigo));
$chk('ANCHO_UNIDAD = 30.0 mm',
    ((new ReflectionClass('FerreteriaCotizacionPdf'))->getConstants()['ANCHO_UNIDAD'] ?? null) === 30.0);

// --- pintura como conduce: 7 lineas, 1 pagina ---
$t4Doc = $t4Conduce($t4Casos['pintura']['lineas'], 'CON-000007', 'COT-000012', ['Galones']);
$t4Bytes = $t4Render($t4Doc);
$t4Txt = implode("\n", $t4Paginas($t4Bytes));
$chk('conduce: render() devuelve un PDF de 1 pagina', str_starts_with($t4Bytes, '%PDF') && $t4ContarPaginas($t4Bytes) === 1);
$chk('conduce: titulo CONDUCE DE MERCANCÍA, no el de la cotizacion',
    str_contains($t4Txt, $t4Iso('(CONDUCE DE MERCANCÍA)')) && !str_contains($t4Txt, $t4Iso('COTIZACIÓN MERCANCÍAS')));
$t4Orden = array_map(static fn(string $aguja): int|false => strpos($t4Txt, $aguja), [
    '(MAYO 14/2026.-)', '(CON-000007)', $t4Iso('(Cotización: COT-000012)'), $t4Iso('(NOMBRE O RAZÓN SOCIAL)'),
    '(HOSPITAL DOCENTE DR. FRANCISCO E. MOSCOSO PUELLO)', '(401-51513-1)',
]);
$t4Ordenado = $t4Orden;
sort($t4Ordenado);
$chk('conduce: bloque izquierdo en orden: fecha larga, CON-000007, Cotización: COT-000012, rotulo, cliente, RNC',
    !in_array(false, $t4Orden, true) && $t4Orden === $t4Ordenado);
$chk('conduce: cabecera Cantidad | Unidad | Descripción mercancías',
    str_contains($t4Txt, '(Cantidad)') && str_contains($t4Txt, '(Unidad)') && str_contains($t4Txt, $t4Iso('(Descripción mercancías)')));
$chk('conduce: ni Valor Unitario, ni Valor Total, ni Sub-total, ni ITBIS, ni TOTAL, ni RD$',
    !str_contains($t4Txt, 'Valor Unitario') && !str_contains($t4Txt, 'Valor Total') && !str_contains($t4Txt, 'Sub-total')
    && !str_contains($t4Txt, 'ITBIS') && !str_contains($t4Txt, 'TOTAL') && !str_contains($t4Txt, 'RD$'));
$chk('conduce: fila 7.00 | Galones | GALONES DE PINTURA BLNACA SEMIGLOSS',
    str_contains($t4Txt, '(7.00)') && str_contains($t4Txt, '(Galones)') && str_contains($t4Txt, '(GALONES DE PINTURA BLNACA SEMIGLOSS)'));
$chk('conduce: celdas de 24 | 30 | 131.9 mm (Cantidad | Unidad | Descripción), ninguna de 29 mm (Valor Unitario)',
    str_contains($t4Txt, $t4Celda(24, 4.5)) && str_contains($t4Txt, $t4Celda(30, 4.5))
    && str_contains($t4Txt, $t4Celda(131.9, 4.5)) && !str_contains($t4Txt, $t4Celda(29, 4.5)));
// Descripcion empieza en 15 (margen) + 24 (Cantidad) + 30 (Unidad) + 1 (cMargin) = 70 mm.
$t4PosDesc = $t4Pos($t4Txt, 'GALONES DE PINTURA');
$t4PosMarca = $t4Pos($t4Txt, '***');
$chk('conduce: descripciones alineadas a la izquierda en x = 70 mm',
    ($t4PosDesc[0] ?? null) === sprintf('%.2F', 70 * 72 / 25.4));
$chk('conduce: la marca "No hay más productos" va en la columna Descripción (misma x que las descripciones)',
    $t4PosMarca !== null && $t4PosMarca[0] === ($t4PosDesc[0] ?? null)
    && str_contains($t4Txt, $t4Iso('No hay más productos debajo de la línea')));
// Sin filas de totales, entre la marca y "Recibido por" quedan la fila de la
// marca (texto a 3.31 mm de su borde), ESPACIO_TOTALES 1.5 y ESPACIO_RECIBIDO
// 6: el texto de "Recibido por" cae 12.75 mm debajo del de la marca.
$t4PosRecibido = $t4Pos($t4Txt, 'Recibido por:');
$t4Hueco = ($t4PosMarca !== null && $t4PosRecibido !== null) ? ($t4PosMarca[1] - $t4PosRecibido[1]) * 25.4 / 72 : null;
$chk('conduce: "Recibido por" 12.75 mm bajo la marca (cierre sin filas de totales; dio '
    . ($t4Hueco === null ? '-' : sprintf('%.2f', $t4Hueco)) . ' mm)',
    $t4Hueco !== null && abs($t4Hueco - 12.75) < 0.02);
$chk('conduce: Recibido por + pie (razon social, correo mailto, telefono)',
    str_contains($t4Txt, '(Recibido por:)') && str_contains($t4Txt, '(FERREHERRAMIENTAS VENTURA, SRL)')
    && str_contains($t4Bytes, '/URI (mailto:yaironventura0201@hotmail.com)') && str_contains($t4Txt, $t4Iso('(Teléfono 829-898-7798)')));
$chk('conduce: una sola pagina no lleva "Página X de Y"', !str_contains($t4Txt, $t4Iso('Página')));

// Aunque un item traiga su precio interno, el conduce no lo imprime: la pagina es la misma.
$t4ConPrecio = $t4Doc;
$t4ConPrecio['items'] = array_map(static fn(array $it, array $l): array => $it + ['amount' => (float) $l['amount']],
    $t4Doc['items'], $t4Casos['pintura']['lineas']);
$t4TxtPrecio = implode("\n", $t4Paginas($t4Render($t4ConPrecio)));
$chk('conduce: un item con amount no imprime precio (ni 2,000.00 ni 14,000.00; misma pagina)',
    !str_contains($t4TxtPrecio, '(2,000.00)') && !str_contains($t4TxtPrecio, '(14,000.00)') && $t4TxtPrecio === $t4Txt);

// --- vista previa y cotizacion de origen borrada ---
$t4TxtPrevia = implode("\n", $t4Paginas($t4Render($t4Conduce($t4Casos['pintura']['lineas'], null, 'COT-000012', ['Galones']))));
$chk('conduce sin numero (vista previa): VISTA PREVIA y ningun CON-',
    str_contains($t4TxtPrevia, '(VISTA PREVIA)') && !str_contains($t4TxtPrevia, 'CON-')
    && str_contains($t4TxtPrevia, $t4Iso('(Cotización: COT-000012)')));
$t4TxtSinCot = implode("\n", $t4Paginas($t4Render($t4Conduce($t4Casos['pintura']['lineas'], 'CON-000007', null, ['Galones']))));
$t4TxtCotBlanco = implode("\n", $t4Paginas($t4Render($t4Conduce($t4Casos['pintura']['lineas'], 'CON-000007', '  ', ['Galones']))));
$chk('cotizacion_code null o en blanco (cotizacion borrada): sin linea "Cotización:", el resto igual',
    str_contains($t4TxtSinCot, $t4Iso('(CONDUCE DE MERCANCÍA)')) && str_contains($t4TxtSinCot, '(CON-000007)')
    && !str_contains($t4TxtSinCot, $t4Iso('Cotización')) && $t4TxtCotBlanco === $t4TxtSinCot);

// --- Unidad envuelve: "Millones de Unidades Térmicas" (id 29 del catalogo DGII) no cabe en 30 mm ---
$t4Largas = $t4Conduce(array_slice($t4Casos['pintura']['lineas'], 0, 2), 'CON-000008', 'COT-000012',
    ['Millones de Unidades Térmicas', 'Metro']);
$t4TxtLargas = implode("\n", $t4Paginas($t4Render($t4Largas)));
$chk('Unidad larga: se parte en renglones dentro de su columna ("Millones de" / "Unidades Térmicas")',
    !str_contains($t4TxtLargas, $t4Iso('(Millones de Unidades Térmicas)'))
    && str_contains($t4TxtLargas, '(Millones de)') && str_contains($t4TxtLargas, $t4Iso('(Unidades Térmicas)')));
$chk('Unidad larga: su fila crece a 2 renglones (9 mm) en las tres celdas',
    str_contains($t4TxtLargas, $t4Celda(24, 9)) && str_contains($t4TxtLargas, $t4Celda(30, 9)) && str_contains($t4TxtLargas, $t4Celda(131.9, 9)));
$chk('Unidad corta (Metro): su fila sigue de 1 renglon (4.5 mm)',
    str_contains($t4TxtLargas, '(Metro)') && str_contains($t4TxtLargas, $t4Celda(30, 4.5)));

// --- 60 lineas: saltos de pagina, cabecera repetida, "Página X de Y" ---
// Unidades de 1 y 2 renglones mezcladas, para que el alto de fila varie.
$t4Largo = $t4Casos['largo_60']['lineas'];
$t4Unidades = ['Unidad', 'Metro Cuadrado', 'Millones de Unidades Térmicas'];
$t4Bytes60 = $t4Render($t4Conduce($t4Largo, 'CON-000060', 'COT-000060', $t4Unidades));
$t4N60 = $t4ContarPaginas($t4Bytes60);
$t4Pags60 = $t4Paginas($t4Bytes60);
$chk("60 lineas: mas de una pagina ({$t4N60}), un stream por pagina", $t4N60 > 1 && count($t4Pags60) === $t4N60);
$t4Numeradas = true;
foreach ($t4Pags60 as $t4k => $t4p) {
    $t4Numeradas = $t4Numeradas && str_contains($t4p, $t4Iso('(Página ' . ($t4k + 1) . ' de ' . $t4N60 . ')'));
}
$chk("60 lineas: cada pagina dice \"Página X de {$t4N60}\"", $t4Numeradas);
$chk('60 lineas: las 60 filas impresas, sin precios',
    str_contains($t4Pags60[0], 'NUMERO 1 CON') && str_contains(implode("\n", $t4Pags60), 'NUMERO 60 CON')
    && !str_contains(implode("\n", $t4Pags60), 'Valor'));

// Las reglas de corte para cada largo de 1 a 60 lineas (como la cotizacion,
// pero con el cierre del conduce, mas corto: sin filas de totales).
$t4MarcaSuelta = [];
$t4CierrePartido = [];
$t4CabeceraMal = [];
$t4CierreSolo = 0;
for ($t4n = 1; $t4n <= count($t4Largo); $t4n++) {
    $t4Pags = $t4Paginas($t4Render($t4Conduce(array_slice($t4Largo, 0, $t4n), 'CON-000060', 'COT-000060', $t4Unidades)));
    $t4Ultima = count($t4Pags) - 1;
    $t4Donde = static fn(string $aguja): array => array_keys(array_filter($t4Pags, static fn(string $p): bool => str_contains($p, $aguja)));
    // 1) la marca en la misma pagina que la ultima fila
    $t4PagFila = $t4Donde('NUMERO ' . $t4n . ' CON');
    if ($t4PagFila === [] || $t4PagFila !== $t4Donde('No hay m')) {
        $t4MarcaSuelta[] = $t4n;
    }
    // 2) "Recibido por" + pie, todos en la ultima pagina (con logo, la razon social solo sale en el pie)
    foreach (['(Recibido por:)', '(FERREHERRAMIENTAS VENTURA, SRL)', $t4Iso('(Teléfono 829-898-7798)')] as $t4Aguja) {
        if ($t4Donde($t4Aguja) !== [$t4Ultima]) {
            $t4CierrePartido[] = $t4n;
            break;
        }
    }
    // 3) cabecera de tabla en cada pagina con filas, y en ninguna otra
    foreach ($t4Pags as $t4k => $t4p) {
        if (str_contains($t4p, 'ARTICULO DE PRUEBA') !== str_contains($t4p, $t4Iso('(Descripción mercancías)'))) {
            $t4CabeceraMal[] = $t4n . '/p' . ($t4k + 1);
        }
    }
    if (!str_contains($t4Pags[$t4Ultima], 'ARTICULO DE PRUEBA')) {
        $t4CierreSolo++;
    }
}
$chk('conduce, cortes 1..60: la marca siempre en la pagina de la ultima fila'
    . ($t4MarcaSuelta ? ' (fallan n=' . implode(',', $t4MarcaSuelta) . ')' : ''), $t4MarcaSuelta === []);
$chk('conduce, cortes 1..60: "Recibido por" + pie nunca se parten y van en la ultima pagina'
    . ($t4CierrePartido ? ' (fallan n=' . implode(',', $t4CierrePartido) . ')' : ''), $t4CierrePartido === []);
$chk('conduce, cortes 1..60: cabecera de tabla solo en paginas con filas'
    . ($t4CabeceraMal ? ' (fallan ' . implode(',', $t4CabeceraMal) . ')' : ''), $t4CabeceraMal === []);
$chk("conduce, cortes 1..60: algun largo empuja el cierre solo a una pagina nueva ({$t4CierreSolo} casos)", $t4CierreSolo > 0);

// --- la cotizacion no cambia (spec 4.4, modo cotizacion) ---
// sha1 del contenido de las paginas (streams inflados) de cada caso del
// fixture, con su code y el logo, tomado con el renderizador de ANTES del modo
// conduce (feat/conduces en 6a1e310) y este mismo armado. Si el fixture cambia
// a proposito, se recalculan con ese commit.
$t4Huellas = [
    'pintura' => 'e234b5f28bc6578c4b63b22f8b3e39fead8163e8',
    'pintura_retencion_abono' => 'bfb56fd8d544c935d93261612fdc7508898fb879',
    'pintura_mano_obra' => 'b74c8f8f8b62b27953ba56a1e3c905c29ee9aad3',
    'b150000049' => '4e94f5881e98ba30b65fce39ab6be9aef0f71bfd',
    'ceramicas' => '5c0eeafcaffa38a41f8cc452973eba44d85b87ea',
    'redondeo_8475' => '600965d02c6ffefd5d3e042a3e40d782e4584f68',
    'flotante' => '14b23b288a0ecd85f1e7198b56755ee619688454',
    'mixto' => '66f26cdfe18144fe5312a9380e18c41d17e4a708',
    'exento' => '0cc69a49896cda15b776c6e618727d8f77a66e95',
    'largo_60' => 'ecba6baaf3f69307eed42b9fd8279499c965f021',
];
$chk('huellas: una por cada caso del fixture, en su orden', array_keys($t4Huellas) === array_keys($t4Casos));
// La cotizacion como se la pasa FerreteriaFormato::pdf(): items + totales ya calculados.
$t4Cotizacion = static function (array $caso, ?string $documento): array {
    $cot = [
        'code' => $caso['code'],
        'date' => $caso['date'],
        'items' => array_map(static fn(array $l): array => [
            'description' => (string) $l['description'],
            'quantity' => (float) $l['quantity'],
            'amount' => (float) $l['amount'],
        ], $caso['lineas']),
        'totales' => FerreteriaFormato::totales(array_map(static fn(array $l): array => [
            'quantity' => (float) $l['quantity'],
            'amount' => (float) $l['amount'],
            'indicador_facturacion' => (int) $l['indicador_facturacion'],
        ], $caso['lineas']), $caso['ajustes']),
    ];
    if ($documento !== null) {
        $cot['documento'] = $documento;
    }
    return $cot;
};
// Dos PDF iguales solo difieren en la hora de creacion (Info /CreationDate).
$t4SinFecha = static fn(string $bytes): string => (string) preg_replace('#/CreationDate \([^)]*\)#', '', $bytes);
foreach ($t4Casos as $t4Id => $t4Caso) {
    $t4Sin = (new FerreteriaCotizacionPdf($t4Cotizacion($t4Caso, null), $t4Fx['emisor'], $t4Caso['cliente'], $t4Logo))->render();
    $t4Con = (new FerreteriaCotizacionPdf($t4Cotizacion($t4Caso, 'cotizacion'), $t4Fx['emisor'], $t4Caso['cliente'], $t4Logo))->render();
    $chk("cotizacion {$t4Id}: mismas paginas que antes del modo conduce (sha1)",
        sha1(implode("\n", $t4Paginas($t4Sin))) === ($t4Huellas[$t4Id] ?? ''));
    $chk("cotizacion {$t4Id}: con documento 'cotizacion' sale el mismo PDF que sin documento",
        $t4SinFecha($t4Con) === $t4SinFecha($t4Sin));
}

// --- --pdf [--grid]: muestras para mirarlas a ojo ---
if ($t4Escribir) {
    $t4Dir = __DIR__ . '/out';
    if (!is_dir($t4Dir)) {
        mkdir($t4Dir, 0775, true);
    }
    $t4Salidas = [
        'pintura' => $t4Doc,
        'b150000049' => $t4Conduce($t4Casos['b150000049']['lineas'], 'CON-000002', 'COT-000002', ['Unidad']),
        'unidades_largas' => $t4Largas,
        'largo_60' => $t4Conduce($t4Largo, 'CON-000060', 'COT-000060', $t4Unidades),
        'sin_cotizacion' => $t4Conduce($t4Casos['pintura']['lineas'], 'CON-000007', null, ['Galones']),
        'vista_previa' => $t4Conduce($t4Casos['pintura']['lineas'], null, 'COT-000012', ['Galones']),
    ];
    foreach ($t4Salidas as $t4Id => $t4Salida) {
        $t4Ruta = $t4Dir . '/conduce_ferreteria_' . $t4Id . '.pdf';
        $chk('--pdf: tools/out/' . basename($t4Ruta) . ($t4Grilla ? ' (con rejilla)' : ''),
            file_put_contents($t4Ruta, $t4Render($t4Salida, $t4Grilla)) !== false);
    }
}

// ---------------------------------------------------------------------------
// Las tareas siguientes agregan sus secciones AQUÍ, encima del resumen.
// ---------------------------------------------------------------------------

printf("\n%d/%d OK\n", $total - $fallos, $total);
exit($fallos === 0 ? 0 : 1);
