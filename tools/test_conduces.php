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

// ===========================================================================
// T5 — conduceModel sin MySQL (Task 5)
// ===========================================================================
// El modelo se crea sin constructor (no conecta) y con una conexion falsa, como
// la seccion T7 de tools/test_cotizacion_ferreteria.php: $conexion no tiene
// tipo, asi que basta con los mismos metodos que PDO. Esta lleva estado (la
// fila de conduce_secuencia y los conduces escritos, que rollBack deja como
// estaban) para probar la numeracion de punta a punta. Lo que solo prueba MySQL
// de verdad (los candados, cinco guardados en paralelo) va en
// tests/test_conduces.http.

require_once __DIR__ . '/../src/Models/conduceModel.php';

/** Conexion falsa de la DB del tenant: registra SQL y parametros y contesta lo minimo que leen los modelos. */
final class ConexionFalsaT5
{
    /** @var array<int,array{0:string,1:array,2:bool}> cada execute(): SQL, parametros y si iba dentro de una transaccion */
    public array $ejecutados = [];
    /** @var string[] cada SQL preparado, en orden */
    public array $consultas = [];
    /** @var array<int,array{0:string,1:mixed,2:mixed,3:mixed}> cada bindValue(): SQL, clave, valor y tipo */
    public array $enlazados = [];
    /** conduce_secuencia.ultimo; null = la fila no existe (la semilla de la 031 no corrio). */
    public ?int $ultimo = 0;
    /** Conduces escritos por esta conexion: id => ['numero' => int, 'code' => string, 'activo' => int]. */
    public array $conduces = [];
    /** Numeros que ya grabo otra caja: cuentan en MAX(numero) y rollBack no los quita. */
    public array $ajenos = [];
    /** Cuantos INSERT INTO conduces chocan con uk_conduces_numero antes de pasar. */
    public int $choquesNumero = 0;
    /** [fragmento de SQL => filas] para fetch()/fetchAll() de lo que no lleva estado; gana el primero que coincide. */
    public array $respuestas = [];
    /** [fragmento de SQL => PDOException]: el execute() de un SQL que lo contiene lanza esa excepcion. */
    public array $fallar = [];
    /** Lo que contesta el COUNT(*). */
    public int $totalFilas = 0;
    public int $commits = 0;
    public int $rollbacks = 0;
    private bool $enTransaccion = false;
    private int $siguienteId = 77;
    private string $ultimoId = '0';
    /** ultimo y conduces al empezar la transaccion: rollBack los deja como estaban. */
    private ?array $antes = null;

    public static function error(int $codigo, string $detalle): PDOException
    {
        $e = new PDOException("SQLSTATE[23000]: {$codigo} {$detalle}");
        $e->errorInfo = ['23000', $codigo, $detalle];
        return $e;
    }

    public function prepare(string $sql): SentenciaFalsaT5
    {
        $this->consultas[] = $sql;
        return new SentenciaFalsaT5($this, $sql);
    }

    public function beginTransaction(): bool
    {
        $this->enTransaccion = true;
        $this->antes = [$this->ultimo, $this->conduces];
        return true;
    }

    public function commit(): bool
    {
        $this->enTransaccion = false;
        $this->antes = null;
        $this->commits++;
        return true;
    }

    public function rollBack(): bool
    {
        if ($this->antes !== null) {
            [$this->ultimo, $this->conduces] = $this->antes;
        }
        $this->enTransaccion = false;
        $this->antes = null;
        $this->rollbacks++;
        return true;
    }

    public function inTransaction(): bool { return $this->enTransaccion; }
    public function lastInsertId(): string { return $this->ultimoId; }

    /** Un execute(): lo registra y aplica lo que el modelo escribe. Devuelve las filas afectadas (rowCount). */
    public function ejecutar(string $sql, array $params): int
    {
        $this->ejecutados[] = [$sql, $params, $this->enTransaccion];
        foreach ($this->fallar as $fragmento => $e) {
            if (str_contains($sql, $fragmento)) {
                throw $e;
            }
        }
        $s = ltrim($sql);
        if (str_starts_with($s, 'INSERT IGNORE INTO conduce_secuencia')) {
            if ($this->ultimo !== null) {
                return 0;
            }
            $this->ultimo = 0;
            return 1;
        }
        if (str_starts_with($s, 'UPDATE conduce_secuencia')) {
            $this->ultimo = (int) $params[':numero'];
            return 1;
        }
        if (str_starts_with($s, 'INSERT INTO conduces ')) {
            if ($this->choquesNumero > 0) {
                $this->choquesNumero--;
                $this->ajenos[] = (int) $params[':numero'];   // otra caja grabo ese numero mientras tanto
                throw self::error(1062, "Duplicate entry '" . $params[':numero'] . "' for key 'conduces.uk_conduces_numero'");
            }
            $id = $this->siguienteId++;
            $this->conduces[$id] = ['numero' => (int) $params[':numero'], 'code' => (string) $params[':code'], 'activo' => 1];
            $this->ultimoId = (string) $id;
            return 1;
        }
        if (str_starts_with($s, 'UPDATE conduces SET activo = 0')) {
            $id = (int) $params[':id'];
            if (($this->conduces[$id]['activo'] ?? 0) !== 1) {
                return 0;
            }
            $this->conduces[$id]['activo'] = 0;
            return 1;
        }
        return 1;
    }

    /** fetchColumn(): la secuencia (FOR UPDATE), el MAX de todos los numeros o el COUNT(*). */
    public function columna(string $sql): mixed
    {
        if (str_contains($sql, 'FROM conduce_secuencia') && str_contains($sql, 'FOR UPDATE')) {
            return $this->ultimo ?? false;
        }
        if (str_contains($sql, 'MAX(numero)')) {
            $numeros = array_merge(array_column($this->conduces, 'numero'), $this->ajenos);
            return $numeros ? max($numeros) : 0;
        }
        if (str_contains($sql, 'COUNT(*)')) {
            return $this->totalFilas;
        }
        return false;
    }

    /** fetch(): la fila que bloquea actualizar (si existe y esta activa) o la primera de $respuestas. */
    public function fila(string $sql, array $params): mixed
    {
        if (str_contains($sql, 'FROM conduces WHERE id = :id AND activo = 1 FOR UPDATE')) {
            $f = $this->conduces[(int) ($params[':id'] ?? 0)] ?? null;
            return $f !== null && $f['activo'] === 1 ? ['code' => $f['code'], 'numero' => $f['numero']] : false;
        }
        return $this->filas($sql)[0] ?? false;
    }

    /** fetchAll(): las filas de $respuestas del primer fragmento que contiene el SQL. */
    public function filas(string $sql): array
    {
        foreach ($this->respuestas as $fragmento => $filas) {
            if (str_contains($sql, $fragmento)) {
                return $filas;
            }
        }
        return [];
    }

    /** Parametros de cada execute() cuyo SQL empieza con $inicio. */
    public function paramsDe(string $inicio): array
    {
        $out = [];
        foreach ($this->ejecutados as [$sql, $params]) {
            if (str_starts_with(ltrim($sql), $inicio)) {
                $out[] = $params;
            }
        }
        return $out;
    }

    /** ¿Algun SQL preparado contiene $texto? */
    public function huboSql(string $texto): bool
    {
        foreach ($this->consultas as $sql) {
            if (str_contains($sql, $texto)) {
                return true;
            }
        }
        return false;
    }
}

final class SentenciaFalsaT5
{
    private array $params = [];
    private int $afectadas = 0;

    public function __construct(private ConexionFalsaT5 $c, private string $sql) {}

    public function bindValue($clave, $valor, $tipo = null): bool
    {
        $this->c->enlazados[] = [$this->sql, $clave, $valor, $tipo];
        $this->params[$clave] = $valor;
        return true;
    }

    public function execute(?array $params = null): bool
    {
        if ($params !== null) {
            $this->params = $params;
        }
        $this->afectadas = $this->c->ejecutar($this->sql, $this->params);
        return true;
    }

    public function rowCount(): int { return $this->afectadas; }
    public function fetchColumn(): mixed { return $this->c->columna($this->sql); }
    public function fetch(): mixed { return $this->c->fila($this->sql, $this->params); }
    public function fetchAll($modo = null): array { return $this->c->filas($this->sql); }
}

/** conduceModel sin constructor (no conecta) con la conexion falsa puesta. */
$modeloT5 = static function (ConexionFalsaT5 $c): conduceModel {
    $m = (new ReflectionClass('conduceModel'))->newInstanceWithoutConstructor();
    (new ReflectionProperty('conduceModel', 'conexion'))->setValue($m, $c);
    return $m;
};
/** Llama un metodo estatico privado del modelo (PHP >= 8.1 no pide setAccessible). */
$privadoT5 = static fn(string $metodo, ...$args) => (new ReflectionMethod('conduceModel', $metodo))->invoke(null, ...$args);
/** La firma de un metodo del modelo, para fijar el contrato. */
$firmaModeloT5 = static function (string $metodo): string {
    if (!method_exists('conduceModel', $metodo)) {
        return $metodo . ' (no existe)';
    }
    $m = new ReflectionMethod('conduceModel', $metodo);
    $params = array_map(static fn(ReflectionParameter $p): string => $p->getType() . ' $' . $p->getName(), $m->getParameters());
    return ($m->isPublic() ? 'public ' : 'private ') . $metodo . '(' . implode(', ', $params) . '): ' . $m->getReturnType();
};
/** Un SQL en una sola linea, para buscar en el sin depender de los saltos ni de la sangria. */
$planoT5 = static fn(string $sql): string => (string) preg_replace('/\s+/', ' ', trim($sql));
/** El primer SQL preparado que contiene $texto, en una sola linea ('' si no hubo). */
$sqlConT5 = static function (ConexionFalsaT5 $c, string $texto) use ($planoT5): string {
    foreach ($c->consultas as $sql) {
        if (str_contains($sql, $texto)) {
            return $planoT5($sql);
        }
    }
    return '';
};
/** Cada execute() como un paso con nombre, en orden; "(fuera)" = sin transaccion. */
$pasosT5 = static function (ConexionFalsaT5 $c) use ($planoT5): array {
    $nombres = [
        'INSERT IGNORE INTO conduce_secuencia' => 'semilla',
        'SELECT ultimo FROM conduce_secuencia WHERE id = 1 FOR UPDATE' => 'secuencia FOR UPDATE',
        'SELECT COALESCE(MAX(numero), 0) FROM conduces' => 'MAX',
        'UPDATE conduce_secuencia SET ultimo' => 'secuencia +1',
        'INSERT INTO conduces ' => 'cabecera',
        'INSERT INTO conduce_items' => 'linea',
        'SELECT code, numero FROM conduces WHERE id = :id AND activo = 1 FOR UPDATE' => 'fila FOR UPDATE',
        'UPDATE conduces SET client_id' => 'cabecera (editar)',
        'UPDATE conduce_items SET activo = 0' => 'lineas viejas inactivas',
    ];
    $pasos = [];
    foreach ($c->ejecutados as [$sql, , $enTransaccion]) {
        $plano = $planoT5($sql);
        $nombre = $plano;
        foreach ($nombres as $inicio => $n) {
            if (str_starts_with($plano, $inicio)) {
                $nombre = $n;
                break;
            }
        }
        $pasos[] = $nombre . ($enTransaccion ? '' : ' (fuera)');
    }
    return $pasos;
};
/** 'cot' como lo dejan validarForma + aplicarCatalogo: una linea de producto (servicio segun el catalogo) y una libre sin precio. */
$cotT5 = static fn(?string $fecha = '2026-10-05 09:30:00'): array => [
    'date' => $fecha,
    'client_id' => 123,
    'cotizacion_id' => 12,
    'items' => [
        ['product_id' => 55, 'description' => 'FUNDAS CEMENTO GRIS', 'quantity' => 2.0, 'amount' => 935.0,
            'unidad_medida' => '43', 'indicador_facturacion' => 1, 'indicador_bien_servicio' => 2],
        ['product_id' => null, 'description' => 'CORTE DE TUBO', 'quantity' => 1.0, 'amount' => 0.0,
            'unidad_medida' => '43', 'indicador_facturacion' => 1, 'indicador_bien_servicio' => 1],
    ],
];
$fkProductoT5 = ConexionFalsaT5::error(1452, 'Cannot add or update a child row: a foreign key constraint fails (`t`.`conduce_items`, CONSTRAINT `conduce_items_product_fk` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL)');
$fkCotizacionT5 = ConexionFalsaT5::error(1452, 'Cannot add or update a child row: a foreign key constraint fails (`t`.`conduces`, CONSTRAINT `conduces_cotizacion_fk` FOREIGN KEY (`cotizacion_id`) REFERENCES `cotizaciones` (`id`) ON DELETE SET NULL)');

// Los error_log del modelo (esperados en estos casos) van a un archivo y no
// ensucian la salida; se restaura al final de la seccion.
$logPrevioT5 = ini_set('error_log', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_conduces_t5.log');

echo "\n== T5: conduceModel, numeracion (crear) ==\n";
$chk('firmas: crear, actualizar y desactivar como el contrato', array_map($firmaModeloT5, ['crear', 'actualizar', 'desactivar']) === [
    'public crear(array $cot, ?int $userId, string $clientName): array',
    'public actualizar(int $id, array $cot, ?int $userId, string $clientName): array',
    'public desactivar(int $id): array',
]);
$chk('mensajes genericos del modelo (500)',
    conduceModel::MSG_GUARDAR === 'No se pudo guardar el conduce. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.'
    && conduceModel::MSG_ACTUALIZAR === 'No se pudieron guardar los cambios del conduce. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.'
    && conduceModel::MSG_ELIMINAR === 'No se pudo eliminar el conduce. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.');

$c = new ConexionFalsaT5();
$c->ultimo = null;   // sin la semilla de la 031
$r = $modeloT5($c)->crear($cotT5(), 5, 'HOSPITAL DOCENTE');
$chk('primer conduce (aun sin la fila de la secuencia): CON-000001, numero 1 y el id del INSERT',
    $r === ['success', ['id' => 77, 'code' => 'CON-000001', 'numero' => 1]]);
$chk('pasos: la semilla fuera de la transaccion; FOR UPDATE, MAX, secuencia, cabecera y lineas dentro', $pasosT5($c) === [
    'semilla (fuera)', 'secuencia FOR UPDATE', 'MAX', 'secuencia +1', 'cabecera', 'linea', 'linea',
]);
$chk('1 commit, 0 rollback y la secuencia queda en 1', $c->commits === 1 && $c->rollbacks === 0 && $c->ultimo === 1);
$chk('sin GET_LOCK: lo serializa el FOR UPDATE de conduce_secuencia', !$c->huboSql('GET_LOCK'));
$chk('el MAX cuenta todas las filas, activas o no (sin filtro por activo)',
    in_array('SELECT COALESCE(MAX(numero), 0) FROM conduces', $c->consultas, true));
$cab = $c->paramsDe('INSERT INTO conduces ')[0] ?? [];
$chk('cabecera: numero, code, fecha, cotizacion, cliente, nombre guardado y user_id', $cab === [
    ':numero' => 1, ':code' => 'CON-000001', ':date' => '2026-10-05 09:30:00', ':cotizacion_id' => 12,
    ':client_id' => 123, ':client_name' => 'HOSPITAL DOCENTE', ':user_id' => 5,
]);
$lin = $c->paramsDe('INSERT INTO conduce_items');
$chk('linea de producto: cantidad, unidad, precio interno e indicadores', ($lin[0] ?? null) === [
    ':conduce_id' => 77, ':product_id' => 55, ':description' => 'FUNDAS CEMENTO GRIS', ':quantity' => 2.0,
    ':unidad_medida' => '43', ':amount' => 935.0, ':indicador_facturacion' => 1, ':indicador_bien_servicio' => 2,
]);
$chk('linea libre: product_id null y precio 0', count($lin) === 2 && array_key_exists(':product_id', $lin[1])
    && $lin[1][':product_id'] === null && $lin[1][':amount'] === 0.0);

$c = new ConexionFalsaT5();
$modeloT5($c)->crear($cotT5(null), null, 'X');
$cab = $c->paramsDe('INSERT INTO conduces ')[0] ?? [];
$chk('sin fecha => ahora (Y-m-d H:i:s); sin usuario => user_id null',
    preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) ($cab[':date'] ?? '')) === 1
    && array_key_exists(':user_id', $cab) && $cab[':user_id'] === null);

// numero = GREATEST(ultimo, MAX(numero)) + 1, y la secuencia queda en ese numero.
foreach ([[4, [2], 5], [0, [9], 10], [7, [7], 8], [3, [], 4]] as [$ultimoT5, $hechosT5, $esperadoT5]) {
    $c = new ConexionFalsaT5();
    $c->ultimo = $ultimoT5;
    $c->ajenos = $hechosT5;
    $r = $modeloT5($c)->crear($cotT5(), 5, 'X');
    $chk("secuencia {$ultimoT5} y MAX " . ($hechosT5 ? max($hechosT5) : 0) . " => {$esperadoT5} (" . FerreteriaConduce::codigo($esperadoT5) . ')',
        ($r[1]['numero'] ?? null) === $esperadoT5 && ($r[1]['code'] ?? null) === FerreteriaConduce::codigo($esperadoT5)
        && $c->ultimo === $esperadoT5);
}

echo "\n== T5: un numero no se vuelve a usar ==\n";
$c = new ConexionFalsaT5();
$mT5 = $modeloT5($c);
$r1T5 = $mT5->crear($cotT5(), 5, 'X');
$d1T5 = $mT5->desactivar(77);
$r2T5 = $mT5->crear($cotT5(), 5, 'X');
$chk('crear, eliminar y crear: CON-000001 y despues CON-000002 (el 1 no se reusa)', ($r1T5[1]['code'] ?? null) === 'CON-000001'
    && $d1T5 === ['success', 'Conduce eliminado'] && ($r2T5[1]['code'] ?? null) === 'CON-000002');
$chk('el eliminado sigue en la tabla, con activo = 0 (nada se borro)', ($c->conduces[77]['activo'] ?? null) === 0 && !$c->huboSql('DELETE'));
$mT5->desactivar(78);
$c->ultimo = 0;   // la fila de la secuencia se volvio a sembrar en 0
$r3T5 = $mT5->crear($cotT5(), 5, 'X');
$chk('secuencia en 0 y los dos eliminados: el MAX de las filas inactivas da CON-000003', ($r3T5[1]['code'] ?? null) === 'CON-000003');

echo "\n== T5: choque con uk_conduces_numero (1062) ==\n";
$c = new ConexionFalsaT5();
$c->choquesNumero = 1;
$r = $modeloT5($c)->crear($cotT5(), 5, 'X');
$chk('1062 una vez: reintenta en otra transaccion y toma el siguiente numero (CON-000002)',
    $r === ['success', ['id' => 77, 'code' => 'CON-000002', 'numero' => 2]]);
$chk('1062 una vez: 1 rollback, 1 commit, 2 cabeceras intentadas y la secuencia en 2', $c->rollbacks === 1 && $c->commits === 1
    && count($c->paramsDe('INSERT INTO conduces ')) === 2 && $c->ultimo === 2);
$chk('1062 una vez: el reintento vuelve a sembrar, a bloquear la secuencia y a leer el MAX', $pasosT5($c) === [
    'semilla (fuera)', 'secuencia FOR UPDATE', 'MAX', 'secuencia +1', 'cabecera',
    'semilla (fuera)', 'secuencia FOR UPDATE', 'MAX', 'secuencia +1', 'cabecera', 'linea', 'linea',
]);
$c = new ConexionFalsaT5();
$c->choquesNumero = 2;
$r = $modeloT5($c)->crear($cotT5(), 5, 'X');
$chk('1062 dos veces: 500 "Otro conduce se guardó al mismo tiempo. Vuelve a guardar."',
    $r === ['error', 'Otro conduce se guardó al mismo tiempo. Vuelve a guardar.', 500]);
$chk('1062 dos veces: no hay tercer intento; sin commit ni lineas, y la secuencia sin avanzar',
    count($c->paramsDe('INSERT INTO conduces ')) === 2 && $c->commits === 0 && $c->rollbacks === 2
    && $c->paramsDe('INSERT INTO conduce_items') === [] && $c->conduces === [] && $c->ultimo === 0);
$c = new ConexionFalsaT5();
$c->fallar['INSERT INTO conduces '] = ConexionFalsaT5::error(1062, "Duplicate entry '77' for key 'conduces.PRIMARY'");
$r = $modeloT5($c)->crear($cotT5(), 5, 'X');
$chk('1062 en otra clave: no se reintenta, mensaje generico 500',
    $r === ['error', conduceModel::MSG_GUARDAR, 500] && count($c->paramsDe('INSERT INTO conduces ')) === 1);
$chk('esNumeroRepetido: solo el 1062 de uk_conduces_numero',
    $privadoT5('esNumeroRepetido', ConexionFalsaT5::error(1062, "Duplicate entry '7' for key 'conduces.uk_conduces_numero'"))
    && !$privadoT5('esNumeroRepetido', ConexionFalsaT5::error(1062, "Duplicate entry '7' for key 'conduces.PRIMARY'"))
    && !$privadoT5('esNumeroRepetido', ConexionFalsaT5::error(1452, 'uk_conduces_numero')));

echo "\n== T5: claves foraneas (1452) y otros errores ==\n";
$c = new ConexionFalsaT5();
$c->fallar['INSERT INTO conduce_items'] = $fkProductoT5;
$r = $modeloT5($c)->crear($cotT5(), 5, 'X');
$chk('crear, producto borrado (1452 conduce_items_product_fk) => 422 MSG_PRODUCTO_FK', $r === ['error', FerreteriaConduce::MSG_PRODUCTO_FK, 422]);
$chk('... sin reintento ni commit: el rollback deshace la cabecera y la secuencia', count($c->paramsDe('INSERT INTO conduces ')) === 1
    && $c->commits === 0 && $c->rollbacks === 1 && $c->conduces === [] && $c->ultimo === 0);
$c = new ConexionFalsaT5();
$c->fallar['INSERT INTO conduces '] = $fkCotizacionT5;
$r = $modeloT5($c)->crear($cotT5(), 5, 'X');
$chk('crear, cotizacion de origen borrada (1452 conduces_cotizacion_fk) => 422 MSG_COTIZACION_FK, sin reintento',
    $r === ['error', FerreteriaConduce::MSG_COTIZACION_FK, 422] && count($c->paramsDe('INSERT INTO conduces ')) === 1);
$c = new ConexionFalsaT5();
$c->fallar['INSERT IGNORE INTO conduce_secuencia'] = ConexionFalsaT5::error(1146, "Table 'tenant.conduce_secuencia' doesn't exist");
$r = $modeloT5($c)->crear($cotT5(), 5, 'X');
$chk('sin la 031 (tabla inexistente) => generico 500, sin rollBack de una transaccion que no empezo',
    $r === ['error', conduceModel::MSG_GUARDAR, 500] && $c->rollbacks === 0 && $c->commits === 0);
$chk('errorAlGuardar: otro error (1205) => el generico con 500',
    $privadoT5('errorAlGuardar', ConexionFalsaT5::error(1205, 'Lock wait timeout exceeded'), 'GENERICO') === ['error', 'GENERICO', 500]);
$chk('errorAlGuardar: 1452 de otra clave foranea => el generico', $privadoT5('errorAlGuardar',
    ConexionFalsaT5::error(1452, 'a foreign key constraint fails (CONSTRAINT `conduce_items_conduce_fk`)'), 'GENERICO') === ['error', 'GENERICO', 500]);

echo "\n== T5: conduceModel::actualizar ==\n";
$c = new ConexionFalsaT5();
$c->conduces[7] = ['numero' => 3, 'code' => 'CON-000003', 'activo' => 0];
$r = $modeloT5($c)->actualizar(7, $cotT5(), 5, 'X');
$chk('actualizar un conduce eliminado (activo = 0) => 404 MSG_NO_EXISTE, rollback y nada escrito',
    $r === ['error', FerreteriaConduce::MSG_NO_EXISTE, 404] && $c->rollbacks === 1 && $pasosT5($c) === ['fila FOR UPDATE']);
$c = new ConexionFalsaT5();
$chk('actualizar un id que no existe => 404 MSG_NO_EXISTE',
    $modeloT5($c)->actualizar(99, $cotT5(), 5, 'X') === ['error', FerreteriaConduce::MSG_NO_EXISTE, 404]);

$c = new ConexionFalsaT5();
$c->conduces[7] = ['numero' => 3, 'code' => 'CON-000003', 'activo' => 1];
$r = $modeloT5($c)->actualizar(7, $cotT5(null), 9, 'NUEVO NOMBRE');
$chk('actualizar: numero y code de la fila, nunca nuevos', $r === ['success', ['id' => 7, 'code' => 'CON-000003', 'numero' => 3]]);
$chk('actualizar: fila bloqueada, cabecera, lineas viejas a activo = 0 y DESPUES las nuevas, todo en la transaccion', $pasosT5($c) === [
    'fila FOR UPDATE', 'cabecera (editar)', 'lineas viejas inactivas', 'linea', 'linea',
]);
$chk('actualizar: nada se borra (sin DELETE) y la secuencia no se toca',
    !$c->huboSql('DELETE') && !$c->huboSql('conduce_secuencia') && !$c->huboSql('MAX(numero)'));
$upd = $c->paramsDe('UPDATE conduces')[0] ?? [];
$sqlUpdT5 = $sqlConT5($c, 'SET client_id');
$chk('actualizar sin fecha: date null => conserva la guardada (COALESCE)', array_key_exists(':date', $upd) && $upd[':date'] === null
    && str_contains($sqlUpdT5, 'date = COALESCE(:date, date)'));
$chk('actualizar: cliente, nombre guardado, user_id y updated_at nuevos', ($upd[':client_id'] ?? null) === 123
    && ($upd[':client_name'] ?? null) === 'NUEVO NOMBRE' && ($upd[':user_id'] ?? null) === 9
    && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) ($upd[':updated_at'] ?? '')) === 1);
$chk('actualizar: no cambia numero, code ni cotizacion_id', $sqlUpdT5 !== '' && preg_match('/\b(numero|code|cotizacion_id)\s*=/', $sqlUpdT5) === 0);
$chk('actualizar: las lineas viejas son las activas de ese conduce', ($c->paramsDe('UPDATE conduce_items SET activo = 0')[0] ?? null) === [':id' => 7]
    && str_ends_with($sqlConT5($c, 'UPDATE conduce_items'), 'WHERE conduce_id = :id AND activo = 1'));
$chk('actualizar: 1 commit, 0 rollback; las lineas nuevas van al mismo conduce', $c->commits === 1 && $c->rollbacks === 0
    && array_column($c->paramsDe('INSERT INTO conduce_items'), ':conduce_id') === [7, 7]);
$c = new ConexionFalsaT5();
$c->conduces[7] = ['numero' => 3, 'code' => 'CON-000003', 'activo' => 1];
$modeloT5($c)->actualizar(7, $cotT5('2026-10-06 08:00:00'), 9, 'X');
$chk('actualizar con fecha: la del cuerpo', ($c->paramsDe('UPDATE conduces')[0][':date'] ?? null) === '2026-10-06 08:00:00');
$c = new ConexionFalsaT5();
$c->conduces[7] = ['numero' => 3, 'code' => 'CON-000003', 'activo' => 1];
$c->fallar['INSERT INTO conduce_items'] = $fkProductoT5;
$r = $modeloT5($c)->actualizar(7, $cotT5(), 9, 'X');
$chk('actualizar, producto borrado => 422 MSG_PRODUCTO_FK y rollback (las lineas viejas siguen activas)',
    $r === ['error', FerreteriaConduce::MSG_PRODUCTO_FK, 422] && $c->rollbacks === 1 && $c->commits === 0);
$c = new ConexionFalsaT5();
$c->conduces[7] = ['numero' => 3, 'code' => 'CON-000003', 'activo' => 1];
$c->fallar['UPDATE conduces'] = ConexionFalsaT5::error(1205, 'Lock wait timeout exceeded');
$chk('actualizar con la DB fallando => 500 MSG_ACTUALIZAR',
    $modeloT5($c)->actualizar(7, $cotT5(), 9, 'X') === ['error', conduceModel::MSG_ACTUALIZAR, 500]);

echo "\n== T5: conduceModel::desactivar ==\n";
$c = new ConexionFalsaT5();
$c->conduces[7] = ['numero' => 3, 'code' => 'CON-000003', 'activo' => 1];
$mT5 = $modeloT5($c);
$chk('desactivar: "Conduce eliminado" y la fila con activo = 0', $mT5->desactivar(7) === ['success', 'Conduce eliminado']
    && $c->conduces[7]['activo'] === 0);
$chk('desactivar: UPDATE ... SET activo = 0, updated_at ... WHERE id = :id AND activo = 1; sin DELETE ni tocar las lineas',
    in_array('UPDATE conduces SET activo = 0, updated_at = :updated_at WHERE id = :id AND activo = 1', $c->consultas, true)
    && !$c->huboSql('DELETE') && !$c->huboSql('conduce_items'));
$chk('desactivar otra vez (0 filas afectadas) => 404 MSG_NO_EXISTE', $mT5->desactivar(7) === ['error', FerreteriaConduce::MSG_NO_EXISTE, 404]);
$chk('desactivar un id que no existe => 404 MSG_NO_EXISTE', $mT5->desactivar(999) === ['error', FerreteriaConduce::MSG_NO_EXISTE, 404]);
$c = new ConexionFalsaT5();
$c->fallar['UPDATE conduces SET activo'] = ConexionFalsaT5::error(1205, 'Lock wait timeout exceeded');
$chk('desactivar con la DB fallando => 500 MSG_ELIMINAR', $modeloT5($c)->desactivar(7) === ['error', conduceModel::MSG_ELIMINAR, 500]);

ini_set('error_log', (string) $logPrevioT5);

// --- T5, ronda B: lecturas (listar, contar, obtener, cotizacionDeOrigen) ---
echo "\n== T5: lecturas de conduceModel ==\n";
$chk('firmas: listar, contar, obtener y cotizacionDeOrigen como el contrato',
    array_map($firmaModeloT5, ['listar', 'contar', 'obtener', 'cotizacionDeOrigen']) === [
        'public listar(int $offset, int $limit, ?string $query): array',
        'public contar(?string $query): int',
        'public obtener(int $id): ?array',
        'public cotizacionDeOrigen(int $cotizacionId): ?array',
    ]);
// Cabeceras como las devolveria el SELECT (las columnas de la spec 4.1).
$cabT5 = static fn(int $id, string $code): array => [
    'id' => $id, 'numero' => $id - 6, 'code' => $code, 'date' => '2026-10-05 09:30:00', 'cotizacion_id' => 12,
    'cotizacion_code' => 'COT-000012', 'client_id' => 123, 'client_name' => 'Juan Perez', 'company_name' => 'HOSPITAL DOCENTE',
    'rnc' => '401515131', 'client_name_guardado' => 'HOSPITAL DOCENTE', 'user_id' => 5, 'activo' => 1,
    'created_at' => '2026-10-05 09:31:00', 'updated_at' => null,
];
$lineaOchoT5 = ['id' => 4, 'conduce_id' => 8, 'product_id' => null, 'description' => 'ARENA', 'quantity' => '3.000',
    'unidad_medida' => '43', 'amount' => '0.0000', 'indicador_facturacion' => 1, 'indicador_bien_servicio' => 1, 'activo' => 1];
$columnasT5 = 'SELECT c.id, c.numero, c.code, c.date, c.cotizacion_id, q.code AS cotizacion_code, c.client_id, cl.client_name, '
    . 'cl.company_name, cl.rnc, c.client_name AS client_name_guardado, c.user_id, c.activo, c.created_at, c.updated_at '
    . 'FROM conduces c LEFT JOIN cotizaciones q ON q.id = c.cotizacion_id LEFT JOIN clients cl ON cl.id = c.client_id';
$busquedaT5 = 'AND (c.code LIKE :query OR c.client_name LIKE :query OR cl.client_name LIKE :query OR cl.company_name LIKE :query '
    . 'OR cl.rnc LIKE :query)';
$enlacesT5 = static fn(ConexionFalsaT5 $c): array => array_map(static fn(array $e): array => [$e[1], $e[2], $e[3]], $c->enlazados);

$c = new ConexionFalsaT5();
$c->respuestas = [
    'FROM conduces c' => [$cabT5(8, 'CON-000002'), $cabT5(7, 'CON-000001')],
    'FROM conduce_items' => [$filasItemsFx[0], $filasItemsFx[1], $lineaOchoT5],
];
$filasT5 = $modeloT5($c)->listar(0, 10, null);
$sqlListaT5 = $planoT5($c->consultas[0] ?? '');
$chk('listar: las columnas de la fila (spec 4.1), con el nombre guardado como client_name_guardado',
    str_starts_with($sqlListaT5, $columnasT5));
$chk('listar: solo activos, de la fecha mas nueva a la mas vieja (empate por id), con LIMIT y OFFSET',
    str_ends_with($sqlListaT5, ' WHERE c.activo = 1 ORDER BY c.date DESC, c.id DESC LIMIT :limit OFFSET :offset'));
$chk('listar sin busqueda: limit y offset como enteros, sin :query ni LIKE',
    $enlacesT5($c) === [[':limit', 10, PDO::PARAM_INT], [':offset', 0, PDO::PARAM_INT]] && !str_contains($sqlListaT5, 'LIKE'));
$chk('listar: cada conduce con sus lineas', count($filasT5) === 2 && ($filasT5[0]['items'] ?? null) === [$lineaOchoT5]
    && ($filasT5[1]['items'] ?? null) === [$filasItemsFx[0], $filasItemsFx[1]]);
$chk('lineas: una sola consulta para la pagina, solo las activas, en el orden en que se escribieron',
    count(array_filter($c->consultas, static fn(string $s): bool => str_contains($s, 'FROM conduce_items'))) === 1
    && str_ends_with($sqlConT5($c, 'FROM conduce_items'), 'FROM conduce_items WHERE conduce_id IN (?,?) AND activo = 1 ORDER BY id ASC')
    && ($c->paramsDe('SELECT id, conduce_id')[0] ?? null) === [8, 7]);

$c = new ConexionFalsaT5();
$modeloT5($c)->listar(20, 10, '  CON-0001 ');
$chk('listar con busqueda: numero, nombre guardado, nombre y empresa del cliente y RNC',
    str_contains($planoT5($c->consultas[0] ?? ''), ' WHERE c.activo = 1 ' . $busquedaT5 . ' ORDER BY c.date DESC'));
$chk('listar con busqueda: el texto recortado entre % y el offset de la pagina', $enlacesT5($c)
    === [[':query', '%CON-0001%', PDO::PARAM_STR], [':limit', 10, PDO::PARAM_INT], [':offset', 20, PDO::PARAM_INT]]);
$c = new ConexionFalsaT5();
$chk('listar sin filas: [] sin consultar las lineas; una busqueda en blanco no filtra',
    $modeloT5($c)->listar(0, 10, '   ') === [] && !$c->huboSql('conduce_items') && !$c->huboSql('LIKE'));

$c = new ConexionFalsaT5();
$c->totalFilas = 42;
$chk('contar: COUNT(*) de los activos', $modeloT5($c)->contar(null) === 42
    && $planoT5($c->consultas[0] ?? '') === 'SELECT COUNT(*) AS total FROM conduces c LEFT JOIN clients cl ON cl.id = c.client_id WHERE c.activo = 1'
    && $c->paramsDe('SELECT COUNT(*)') === [[]]);
$c = new ConexionFalsaT5();
$modeloT5($c)->contar(' juan ');
$chk('contar con busqueda: la misma condicion que listar', str_ends_with($planoT5($c->consultas[0] ?? ''), ' WHERE c.activo = 1 ' . $busquedaT5)
    && $c->paramsDe('SELECT COUNT(*)') === [[':query' => '%juan%']]);

$c = new ConexionFalsaT5();
$c->respuestas = ['FROM conduces c' => [$cabT5(7, 'CON-000001')], 'FROM conduce_items' => $filasItemsFx];
$filaT5 = $modeloT5($c)->obtener(7);
$chk('obtener: la fila con sus lineas activas y el nombre guardado', ($filaT5['code'] ?? null) === 'CON-000001'
    && ($filaT5['items'] ?? null) === $filasItemsFx && ($filaT5['client_name_guardado'] ?? null) === 'HOSPITAL DOCENTE');
$chk('obtener: las columnas de la fila y solo si esta activo (WHERE c.id = :id AND c.activo = 1)',
    $planoT5($c->consultas[0] ?? '') === $columnasT5 . ' WHERE c.id = :id AND c.activo = 1'
    && ($c->paramsDe('SELECT c.id')[0] ?? null) === [':id' => 7]);
$c = new ConexionFalsaT5();
$chk('obtener: no existe o esta eliminado => null, sin consultar las lineas', $modeloT5($c)->obtener(99) === null && !$c->huboSql('conduce_items'));

$c = new ConexionFalsaT5();
$c->respuestas = ['FROM cotizaciones WHERE id = :id' => [['id' => 12, 'code' => 'COT-000012', 'formato' => 'ferreteria']]];
$chk('cotizacionDeOrigen: id, code y formato', $modeloT5($c)->cotizacionDeOrigen(12) === ['id' => 12, 'code' => 'COT-000012', 'formato' => 'ferreteria']
    && ($c->paramsDe('SELECT id, code, formato FROM cotizaciones')[0] ?? null) === [':id' => 12]);
$c->respuestas = ['FROM cotizaciones WHERE id = :id' => [['id' => '3', 'code' => 'ABC123', 'formato' => null]]];
$chk('cotizacionDeOrigen: una de Gratex (formato NULL) => formato null, id como entero',
    $modeloT5($c)->cotizacionDeOrigen(3) === ['id' => 3, 'code' => 'ABC123', 'formato' => null]);
$c->respuestas = [];
$chk('cotizacionDeOrigen: no existe => null', $modeloT5($c)->cotizacionDeOrigen(404) === null);

$lanzaT5 = static function (callable $f): bool {
    try {
        $f();
        return false;
    } catch (PDOException $e) {
        return true;
    }
};
$c = new ConexionFalsaT5();
$c->fallar['FROM conduces c'] = ConexionFalsaT5::error(1146, "Table 'tenant.conduces' doesn't exist");
$c->fallar['FROM cotizaciones'] = ConexionFalsaT5::error(2006, 'MySQL server has gone away');
$chk('las lecturas con la DB fallando lanzan (el controller responde 500, nunca "no hay conduces" ni un 404)',
    $lanzaT5(fn() => $modeloT5($c)->listar(0, 10, null)) && $lanzaT5(fn() => $modeloT5($c)->contar(null))
    && $lanzaT5(fn() => $modeloT5($c)->obtener(7)) && $lanzaT5(fn() => $modeloT5($c)->cotizacionDeOrigen(12)));

// ===========================================================================
// T6 — FerreteriaConduce: reglas contra la DB y piezas del controller (Task 6)
// ===========================================================================
// Lo que depende de lo que contesta la DB (la cotizacion de origen, el
// cliente, los productos), ya con la respuesta en la mano, y lo que
// conduceController lee de la peticion (la ruta, el id, la pagina). Puro: sin DB.

echo "\n== T6: FerreteriaConduce::aplicarCatalogo ==\n";
$chk('firma: public static aplicarCatalogo(array $cot, bool $esCreacion, ?array $origen, ?array $cliente, array $productos): array',
    $firmaConduce('aplicarCatalogo') === 'public static aplicarCatalogo(array $cot, bool $esCreacion, ?array $origen, ?array $cliente, array $productos): array');
$cotT6 = $validarConduce($cuerpoConduce())['cot'];
$origenT6 = ['id' => 12, 'code' => 'COT-000012', 'formato' => 'ferreteria'];
// Lo que devuelve cotizacionModel::getCliente (las 5 claves).
$clienteT6 = ['client_name' => 'Juan Perez', 'company_name' => 'HOSPITAL DOCENTE',
    'razon_social' => 'HOSPITAL DOCENTE DR. FRANCISCO E. MOSCOSO PUELLO', 'rnc' => '401515131', 'email' => 'a@b.do'];
$productosT6 = [55 => ['indicador_bien_servicio' => 2]];
$r = FerreteriaConduce::aplicarCatalogo($cotT6, true, $origenT6, $clienteT6, $productosT6);
$chk('ok: bien/servicio del catalogo (2) en la linea de producto; la libre conserva el del cuerpo (1)', ($r[0] ?? '') === 'ok'
    && ($r[1]['items'][0]['indicador_bien_servicio'] ?? null) === 2 && ($r[1]['items'][1]['indicador_bien_servicio'] ?? null) === 1);
// Lo esperado se arma sobre una copia: === compara tambien el orden de las claves.
$esperadoT6 = $cotT6;
$esperadoT6['items'][0]['indicador_bien_servicio'] = 2;
$chk('ok: el resto de cot queda igual', ($r[1] ?? null) === $esperadoT6);
$chk('crear sin cotizacion de origen (no existe) => 422 MSG_COTIZACION',
    FerreteriaConduce::aplicarCatalogo($cotT6, true, null, $clienteT6, $productosT6) === ['error', FerreteriaConduce::MSG_COTIZACION, 422]);
foreach ([null, 'gratex', 'Ferreteria', ''] as $formatoT6) {
    $chk('crear desde una cotizacion con formato ' . var_export($formatoT6, true) . ' => 422 MSG_COTIZACION',
        FerreteriaConduce::aplicarCatalogo($cotT6, true, ['formato' => $formatoT6] + $origenT6, $clienteT6, $productosT6)
            === ['error', FerreteriaConduce::MSG_COTIZACION, 422]);
}
$chk('editar: la cotizacion de origen no se mira (null pasa)',
    (FerreteriaConduce::aplicarCatalogo($cotT6, false, null, $clienteT6, $productosT6)[0] ?? '') === 'ok');
$chk('cliente que no existe => 422 MSG_SIN_CLIENTE, al crear y al editar (el guardado lo borraron)',
    FerreteriaConduce::aplicarCatalogo($cotT6, true, $origenT6, null, $productosT6) === ['error', FerreteriaConduce::MSG_SIN_CLIENTE, 422]
    && FerreteriaConduce::aplicarCatalogo($cotT6, false, null, null, $productosT6) === ['error', FerreteriaConduce::MSG_SIN_CLIENTE, 422]);
$chk('cotizacion mala y cliente que no existe: la cotizacion primero (el orden de validarForma)',
    FerreteriaConduce::aplicarCatalogo($cotT6, true, null, null, []) === ['error', FerreteriaConduce::MSG_COTIZACION, 422]);
$chk('producto que ya no esta en el catalogo => el texto de la cotizacion, con su linea',
    FerreteriaConduce::aplicarCatalogo($cotT6, true, $origenT6, $clienteT6, []) === [
        'error', 'Línea 1: el producto ya no existe en el catálogo. Búscalo de nuevo o déjala como línea libre.', 422,
    ]);

echo "\n== T6: FerreteriaConduce::filaPreview / filaPdf ==\n";
$chk('firma: public static filaPreview(array $cot, array $cliente, ?array $row, ?array $origen): array',
    $firmaConduce('filaPreview') === 'public static filaPreview(array $cot, array $cliente, ?array $row, ?array $origen): array');
$chk('firma: public static filaPdf(array $row, ?array $cliente): array', $firmaConduce('filaPdf') === 'public static filaPdf(array $row, ?array $cliente): array');
$chk('filaPreview sin fila: la fecha del cuerpo, la cotizacion de origen, el cliente elegido y su nombre como el guardado',
    FerreteriaConduce::filaPreview($cotT6, $clienteT6, null, $origenT6) === [
        'date' => '2026-10-05 09:30:00', 'cotizacion_code' => 'COT-000012',
        'razon_social' => 'HOSPITAL DOCENTE DR. FRANCISCO E. MOSCOSO PUELLO', 'company_name' => 'HOSPITAL DOCENTE',
        'client_name' => 'Juan Perez', 'rnc' => '401515131',
        'client_name_guardado' => 'HOSPITAL DOCENTE DR. FRANCISCO E. MOSCOSO PUELLO', 'items' => $cotT6['items'],
    ]);
$sinFechaT6 = ['date' => null] + $cotT6;
$previaFilaT6 = FerreteriaConduce::filaPreview($sinFechaT6, $clienteT6, $filaConduceFx, ['code' => 'COT-000099'] + $origenT6);
$chk('filaPreview con fila y sin fecha: la fecha y la cotizacion de la fila (no las del cuerpo)',
    $previaFilaT6['date'] === '2026-10-05 09:30:00' && $previaFilaT6['cotizacion_code'] === 'COT-000012');
$chk('filaPreview con fila y con fecha: gana la del cuerpo',
    FerreteriaConduce::filaPreview($cotT6, $clienteT6, ['date' => '2026-01-01 08:00:00'] + $filaConduceFx, null)['date'] === '2026-10-05 09:30:00');
$chk('filaPreview sin fila ni fecha: date null (datosPdf pone la de ahora)',
    FerreteriaConduce::filaPreview($sinFechaT6, $clienteT6, null, $origenT6)['date'] === null);
$chk('filaPreview de un conduce cuya cotizacion se elimino: cotizacion_code null',
    FerreteriaConduce::filaPreview($cotT6, $clienteT6, ['cotizacion_code' => null] + $filaConduceFx, $origenT6)['cotizacion_code'] === null);
$datosPreviaT6 = FerreteriaConduce::datosPdf(FerreteriaConduce::filaPreview($cotT6, $clienteT6, null, $origenT6), $nombresUnidadFx, null);
$chk('filaPreview -> datosPdf: las lineas sin precio y el cliente con razon_social', $datosPreviaT6['conduce']['items'] === [
    ['description' => 'FUNDAS CEMENTO GRIS', 'quantity' => 2.0, 'unidad' => 'Unidad'],
    ['description' => 'CORTE DE TUBO', 'quantity' => 1.0, 'unidad' => 'Unidad'],
] && $datosPreviaT6['cliente']['razon_social'] === 'HOSPITAL DOCENTE DR. FRANCISCO E. MOSCOSO PUELLO');
$conClienteT6 = FerreteriaConduce::filaPdf($filaConduceFx, $clienteT6);
$chk('filaPdf con el cliente de hoy: razon_social, company_name, client_name y rnc de getCliente; lo demas igual',
    $conClienteT6 === array_merge($filaConduceFx, [
        'razon_social' => 'HOSPITAL DOCENTE DR. FRANCISCO E. MOSCOSO PUELLO', 'company_name' => 'HOSPITAL DOCENTE',
        'client_name' => 'Juan Perez', 'rnc' => '401515131',
    ]));
$chk('filaPdf sin cliente (lo borraron o no se pudo leer): la fila tal cual', FerreteriaConduce::filaPdf($filaConduceFx, null) === $filaConduceFx);
$chk('filaPdf -> datosPdf: el PDF lleva el nombre con razon_social, como la cotizacion',
    FerreteriaConduce::datosPdf($conClienteT6, [], 'CON-000001')['cliente']['razon_social'] === 'HOSPITAL DOCENTE DR. FRANCISCO E. MOSCOSO PUELLO');

echo "\n== T6: FerreteriaConduce::ruta / idDe / paginacion (conduceController) ==\n";
$chk('firma: public static ruta(string $metodo, string $path): array', $firmaConduce('ruta') === 'public static ruta(string $metodo, string $path): array');
foreach ([
    ['GET', '/api/conduces', 'leer', null],
    ['GET', '/api/conduces/', 'leer', null],
    ['GET', '/api/conduces/7/pdf', 'pdf', 7],
    ['GET', '/carpeta/api/conduces/15/pdf/', 'pdf', 15],
    ['GET', '/api/conduces/0/pdf', 'pdf', null],
    ['POST', '/api/conduces', 'crear', null],
    ['POST', '/api/conduces/preview', 'preview', null],
    ['PUT', '/api/conduces', 'actualizar', null],
    ['DELETE', '/api/conduces', 'eliminar', null],
    ['POST', '/api/conduces/7/pdf', 'no_existe', null],
    ['GET', '/api/conduces/preview', 'no_existe', null],
    ['PUT', '/api/conduces/preview', 'no_existe', null],
    ['DELETE', '/api/conduces/7', 'no_existe', null],
    ['GET', '/api/conduces/abc/pdf', 'no_existe', null],
    ['PATCH', '/api/conduces', 'no_existe', null],
] as [$metodoT6, $pathT6, $accionT6, $idT6]) {
    $chk("ruta({$metodoT6} {$pathT6}) = {$accionT6}" . ($idT6 !== null ? " ({$idT6})" : ''),
        FerreteriaConduce::ruta($metodoT6, $pathT6) === ['accion' => $accionT6, 'id' => $idT6]);
}
$chk('firma: public static idDe(mixed $v): ?int', $firmaConduce('idDe') === 'public static idDe(mixed $v): ?int');
$chk('idDe: 7, "7", 7.0 y " 7 " = 7', FerreteriaConduce::idDe(7) === 7 && FerreteriaConduce::idDe('7') === 7
    && FerreteriaConduce::idDe(7.0) === 7 && FerreteriaConduce::idDe(' 7 ') === 7);
$chk('idDe: 0, -3, 7.5, "abc", "", null, true y [] = null', array_map([FerreteriaConduce::class, 'idDe'], [0, -3, 7.5, 'abc', '', null, true, []])
    === [null, null, null, null, null, null, null, null]);
$chk('firma: public static paginacion(array $get): array', $firmaConduce('paginacion') === 'public static paginacion(array $get): array');
$chk('paginacion sin parametros: pagina 1 de 10, sin busqueda', FerreteriaConduce::paginacion([]) === ['page' => 1, 'pageSize' => 10, 'query' => null, 'offset' => 0]);
$chk('paginacion page 3, pageSize 20 y query: offset 40', FerreteriaConduce::paginacion(['page' => '3', 'pageSize' => '20', 'query' => 'juan'])
    === ['page' => 3, 'pageSize' => 20, 'query' => 'juan', 'offset' => 40]);
$chk('paginacion: 0, negativos, "x" y fracciones menores que 1 caen en 1 y 10 (nunca OFFSET negativo ni division por 0)',
    FerreteriaConduce::paginacion(['page' => '0', 'pageSize' => '-5']) === ['page' => 1, 'pageSize' => 10, 'query' => null, 'offset' => 0]
    && FerreteriaConduce::paginacion(['page' => 'x', 'pageSize' => '0.5']) === ['page' => 1, 'pageSize' => 10, 'query' => null, 'offset' => 0]);
$chk('paginacion: una query que no es texto (query[]=a) = sin busqueda', FerreteriaConduce::paginacion(['query' => ['a']])['query'] === null);

// --- T6, ronda B: la parte de instancia, de punta a punta sin MySQL ---
// conduceModel y cotizacionModel sobre la misma conexion falsa de T5 (en
// produccion comparten la del tenant); la Database de EmisorConfigModel
// apuntando a esa conexion; y la master del catalogo de unidades
// (MasterDatabase) con un PDO falso que no tiene driver. Asi nada de esta
// seccion puede abrir una base de datos de verdad, aunque haya un .env.

require_once __DIR__ . '/../src/MasterDatabase.php';

/** PDO de master sin driver (nunca conecta) que contesta el catalogo unidades_medida. */
final class PdoMasterFalsoT6 extends PDO
{
    /** @var array<int,array{0:string,1:int}> [id DGII => [descripcion, permite_decimales]] */
    public array $unidades = [43 => ['Unidad', 0], 26 => ['Metro', 1], 21 => ['Kilogramo', 1]];
    /** true = toda consulta lanza (master caida). */
    public bool $fallar = false;

    public function __construct()
    {
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if ($this->fallar) {
            throw new PDOException('master caida (prueba)');
        }
        return new SentenciaMasterFalsaT6($this->unidades);
    }
}

final class SentenciaMasterFalsaT6 extends PDOStatement
{
    public function __construct(private array $unidades)
    {
    }

    /** Lo que piden all(), isValid() y permiteDecimales() de unidadMedidaModel, segun el modo. */
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        if ($mode === PDO::FETCH_COLUMN) {
            return array_keys($this->unidades);
        }
        if ($mode === PDO::FETCH_KEY_PAIR) {
            return array_map(static fn(array $u): int => $u[1], $this->unidades);
        }
        $filas = [];
        foreach ($this->unidades as $id => [$descripcion, $decimales]) {
            $filas[] = ['id' => $id, 'codigo' => 'U' . $id, 'descripcion' => $descripcion, 'permite_decimales' => $decimales];
        }
        return $filas;
    }
}

$masterT6 = new PdoMasterFalsoT6();
$masterInstanciaT6 = new ReflectionProperty('MasterDatabase', 'instance');
$masterAnteriorT6 = $masterInstanciaT6->getValue();
$masterFalsaT6 = (new ReflectionClass('MasterDatabase'))->newInstanceWithoutConstructor();
(new ReflectionProperty('MasterDatabase', 'conexion'))->setValue($masterFalsaT6, $masterT6);
$masterInstanciaT6->setValue(null, $masterFalsaT6);
$dbInstanciaT6 = new ReflectionProperty('Database', 'instance');
$dbAnteriorT6 = $dbInstanciaT6->getValue();
$dbT6 = (new ReflectionClass('Database'))->newInstanceWithoutConstructor();
$dbConexionT6 = new ReflectionProperty('Database', 'conexion');
$dbInstanciaT6->setValue(null, $dbT6);
// user_id sale del token: RequestContext::userId(), que aqui se pone a mano.
$usuarioT6 = new ReflectionProperty('RequestContext', 'userId');
$usuarioT6->setValue(null, 5);
$logPrevioT6 = ini_set('error_log', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_conduces_t6.log');

$emisorT6 = json_decode((string) file_get_contents(__DIR__ . '/fixtures/cotizacion_ferreteria.json'), true)['emisor'] ?? [];
/** La DB de Ferreteria en el caso feliz: cotizacion 12, cliente 123 y el producto 55 (servicio). */
$conexionT6 = static function () use ($emisorT6, $clienteT6): ConexionFalsaT5 {
    $c = new ConexionFalsaT5();
    $c->respuestas = [
        'FROM cotizaciones WHERE id = :id' => [['id' => 12, 'code' => 'COT-000012', 'formato' => 'ferreteria']],
        'FROM clients WHERE id = :id' => [['id' => 123] + $clienteT6],
        'FROM products WHERE id IN' => [['id' => 55, 'indicador_bien_servicio' => 2]],
        'FROM emisor_config WHERE id = 1' => [$emisorT6],
    ];
    return $c;
};
/** FerreteriaConduce con los dos modelos, y la Database de EmisorConfigModel, sobre la conexion falsa $c. */
$conducesT6 = static function (ConexionFalsaT5 $c) use ($modeloT5, $dbT6, $dbConexionT6): FerreteriaConduce {
    $dbConexionT6->setValue($dbT6, $c);
    $cotizaciones = (new ReflectionClass('cotizacionModel'))->newInstanceWithoutConstructor();
    (new ReflectionProperty('cotizacionModel', 'conexion'))->setValue($cotizaciones, $c);
    return new FerreteriaConduce($modeloT5($c), $cotizaciones);
};
/** El texto de las paginas de un PDF de FPDF (streams FlateDecode inflados). */
$textoPdfT6 = static function (array $r): string {
    if (($r[0] ?? '') !== 'success' || !str_starts_with((string) $r[1], '%PDF')) {
        return '';
    }
    preg_match_all('/stream\n(.*?)\nendstream/s', $r[1], $m);
    $txt = '';
    foreach ($m[1] as $s) {
        $plano = @gzuncompress($s);
        $txt .= ($plano === false ? '' : $plano) . "\n";
    }
    return $txt;
};
$isoT6 = static fn(string $s): string => mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8');
$noExisteT6 = ['error', FerreteriaConduce::MSG_NO_EXISTE, 404];

echo "\n== T6: FerreteriaConduce, parte de instancia (crear / actualizar / eliminar) ==\n";
$chk('firmas de la parte de instancia como el contrato', array_map($firmaConduce, ['__construct', 'crear', 'actualizar', 'eliminar', 'preview', 'pdf']) === [
    'public __construct(conduceModel $modelo, cotizacionModel $cotizaciones): ',
    'public crear(object $body): array',
    'public actualizar(array $row, object $body): array',
    'public eliminar(int $id): array',
    'public preview(object $body, ?array $row): array',
    'public pdf(array $row): array',
]);
$c = $conexionT6();
$r = $conducesT6($c)->crear($cuerpoConduce());
$chk('crear: success con el id, el code y el numero del modelo', $r === ['success', ['id' => 77, 'code' => 'CON-000001', 'numero' => 1]]);
$cab = $c->paramsDe('INSERT INTO conduces ')[0] ?? [];
$chk('crear: client_name = razon_social (el orden de la cotizacion), user_id del token, cotizacion 12 y la fecha del cuerpo',
    ($cab[':client_name'] ?? null) === 'HOSPITAL DOCENTE DR. FRANCISCO E. MOSCOSO PUELLO' && ($cab[':user_id'] ?? null) === 5
    && ($cab[':cotizacion_id'] ?? null) === 12 && ($cab[':client_id'] ?? null) === 123 && ($cab[':date'] ?? null) === '2026-10-05 09:30:00');
$lin = $c->paramsDe('INSERT INTO conduce_items');
$chk('crear: bien/servicio del catalogo (2), no el del cuerpo (1); la linea libre con precio 0', count($lin) === 2
    && ($lin[0][':indicador_bien_servicio'] ?? null) === 2 && ($lin[1][':amount'] ?? null) === 0.0);
$chk('crear: revisa la cotizacion de origen, el cliente y los productos', ($c->paramsDe('SELECT id, code, formato FROM cotizaciones')[0] ?? null) === [':id' => 12]
    && ($c->paramsDe('SELECT * FROM clients')[0] ?? null) === [':id' => 123]
    && ($c->paramsDe('SELECT id, indicador_bien_servicio FROM products')[0] ?? null) === [55]);
foreach ([
    ['desde una cotizacion de Gratex (formato NULL)', static fn(ConexionFalsaT5 $c) => $c->respuestas['FROM cotizaciones WHERE id = :id'] = [['id' => 12, 'code' => 'ABC123', 'formato' => null]], FerreteriaConduce::MSG_COTIZACION],
    ['desde una cotizacion que no existe', static fn(ConexionFalsaT5 $c) => $c->respuestas['FROM cotizaciones WHERE id = :id'] = [], FerreteriaConduce::MSG_COTIZACION],
    ['con un cliente que no existe', static fn(ConexionFalsaT5 $c) => $c->respuestas['FROM clients WHERE id = :id'] = [], FerreteriaConduce::MSG_SIN_CLIENTE],
    ['con un producto que ya no esta en el catalogo', static fn(ConexionFalsaT5 $c) => $c->respuestas['FROM products WHERE id IN'] = [],
        'Línea 1: el producto ya no existe en el catálogo. Búscalo de nuevo o déjala como línea libre.'],
] as [$descT6, $cambiarT6, $msgT6]) {
    $c = $conexionT6();
    $cambiarT6($c);
    $r = $conducesT6($c)->crear($cuerpoConduce());
    $chk("crear {$descT6} => 422 \"{$msgT6}\", sin guardar ni tocar la secuencia",
        $r === ['error', $msgT6, 422] && $c->paramsDe('INSERT INTO conduces ') === [] && !$c->huboSql('conduce_secuencia'));
}
$c = $conexionT6();
$r = $conducesT6($c)->crear($cuerpoConduce(function (object $b) { $b->ajustes = new stdClass(); }));
$chk('crear con ajustes => 422 MSG_AJUSTES antes de leer la DB', $r === ['error', FerreteriaConduce::MSG_AJUSTES, 422] && $c->consultas === []);
$c = $conexionT6();
$c->fallar['FROM clients'] = ConexionFalsaT5::error(2006, 'MySQL server has gone away');
$r = $conducesT6($c)->crear($cuerpoConduce());
$chk('crear con la DB caida al revisar => 500 "No se pudo revisar el conduce…" (nunca "elige un cliente")',
    $r === ['error', 'No se pudo revisar el conduce. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.', 500]
    && $c->paramsDe('INSERT INTO conduces ') === []);
$c = $conexionT6();
$c->choquesNumero = 2;
$chk('crear: el error del modelo pasa tal cual (dos choques => 500 MSG_CHOQUE)',
    $conducesT6($c)->crear($cuerpoConduce()) === ['error', FerreteriaConduce::MSG_CHOQUE, 500]);

$c = $conexionT6();
$chk('actualizar([]) (no esta o esta eliminado) => 404 MSG_NO_EXISTE sin leer la DB',
    $conducesT6($c)->actualizar([], $cuerpoConduce()) === $noExisteT6 && $c->consultas === []);
$c = $conexionT6();
$c->conduces[7] = ['numero' => 1, 'code' => 'CON-000001', 'activo' => 1];
$r = $conducesT6($c)->actualizar($filaConduceFx, $cuerpoConduce(function (object $b) { $b->cotizacion_id = 99; unset($b->date); }));
$chk('actualizar: success con el code y el numero guardados', $r === ['success', ['id' => 7, 'code' => 'CON-000001', 'numero' => 1]]);
$upd = $c->paramsDe('UPDATE conduces')[0] ?? [];
$chk('actualizar: el cotizacion_id del cuerpo (99) se ignora: ni se lee la cotizacion ni se escribe',
    !$c->huboSql('FROM cotizaciones') && !array_key_exists(':cotizacion_id', $upd) && ($upd[':client_name'] ?? null) === 'HOSPITAL DOCENTE DR. FRANCISCO E. MOSCOSO PUELLO');
$chk('actualizar sin fecha: date null (se conserva la guardada); user_id del token', array_key_exists(':date', $upd) && $upd[':date'] === null
    && ($upd[':user_id'] ?? null) === 5);
$c = $conexionT6();
$c->conduces[7] = ['numero' => 1, 'code' => 'CON-000001', 'activo' => 1];
$c->respuestas['FROM clients WHERE id = :id'] = [];
$chk('actualizar con el cliente guardado borrado => 422 MSG_SIN_CLIENTE',
    $conducesT6($c)->actualizar($filaConduceFx, $cuerpoConduce()) === ['error', FerreteriaConduce::MSG_SIN_CLIENTE, 422]);
$c = $conexionT6();
$c->conduces[7] = ['numero' => 1, 'code' => 'CON-000001', 'activo' => 1];
$fT6 = $conducesT6($c);
$chk('eliminar: "Conduce eliminado" y despues 404 (ya estaba eliminado)', $fT6->eliminar(7) === ['success', 'Conduce eliminado']
    && $fT6->eliminar(7) === $noExisteT6);

echo "\n== T6: FerreteriaConduce::preview / pdf ==\n";
$c = $conexionT6();
$r = $conducesT6($c)->preview($cuerpoConduce(function (object $b) { $b->items[0]->unidad_medida = '26'; }), null);
$txt = $textoPdfT6($r);
$chk('preview sin id: un PDF de CONDUCE DE MERCANCÍA con VISTA PREVIA', $txt !== '' && str_contains($txt, $isoT6('(CONDUCE DE MERCANCÍA)'))
    && str_contains($txt, '(VISTA PREVIA)'));
$chk('preview sin id: la cotizacion del cuerpo, la fecha del cuerpo y el cliente elegido', str_contains($txt, $isoT6('(Cotización: COT-000012)'))
    && str_contains($txt, '(OCTUBRE 5/2026.-)') && str_contains($txt, '(HOSPITAL DOCENTE DR. FRANCISCO E. MOSCOSO PUELLO)'));
$chk('preview: el nombre de la unidad sale del catalogo de master (Metro)', str_contains($txt, '(Metro) Tj'));
$chk('preview: no guarda nada ni imprime precios', $c->paramsDe('INSERT') === [] && !str_contains($txt, '935.00'));
$c = $conexionT6();
$r = $conducesT6($c)->preview($cuerpoConduce(function (object $b) { $b->id = 7; $b->cotizacion_id = 99; unset($b->date); }), $filaConduceFx);
$txt = $textoPdfT6($r);
$chk('preview con id: el numero guardado, la cotizacion de la fila y la fecha guardada (sin VISTA PREVIA)', str_contains($txt, '(CON-000001)')
    && str_contains($txt, $isoT6('(Cotización: COT-000012)')) && str_contains($txt, '(OCTUBRE 5/2026.-)') && !str_contains($txt, 'VISTA PREVIA'));
$chk('preview con id: no lee la cotizacion del cuerpo (99)', $txt !== '' && !$c->huboSql('FROM cotizaciones'));
$c = $conexionT6();
$chk('preview con un id que no esta (o esta eliminado) => 404 MSG_NO_EXISTE sin leer la DB',
    $conducesT6($c)->preview($cuerpoConduce(function (object $b) { $b->id = 999; }), null) === $noExisteT6 && $c->consultas === []);
$c = $conexionT6();
$c->respuestas['FROM cotizaciones WHERE id = :id'] = [['id' => 12, 'code' => 'ABC123', 'formato' => null]];
$chk('preview sin id desde una cotizacion de Gratex => 422 MSG_COTIZACION, como el POST',
    $conducesT6($c)->preview($cuerpoConduce(), null) === ['error', FerreteriaConduce::MSG_COTIZACION, 422]);

$c = $conexionT6();
$txt = $textoPdfT6($conducesT6($c)->pdf($filaConduceFx));
$chk('pdf: CONDUCE DE MERCANCÍA con su numero, su cotizacion y su fecha', str_contains($txt, $isoT6('(CONDUCE DE MERCANCÍA)'))
    && str_contains($txt, '(CON-000001)') && str_contains($txt, $isoT6('(Cotización: COT-000012)')) && str_contains($txt, '(OCTUBRE 5/2026.-)'));
$chk('pdf: el cliente de hoy con razon_social (getCliente), como la cotizacion', str_contains($txt, '(HOSPITAL DOCENTE DR. FRANCISCO E. MOSCOSO PUELLO)')
    && ($c->paramsDe('SELECT * FROM clients')[0] ?? null) === [':id' => 123]);
$chk('pdf: unidades por nombre (Metro), la que no esta en el catalogo con su codigo (999), sin precios',
    str_contains($txt, '(Metro) Tj') && str_contains($txt, '(999) Tj') && !str_contains($txt, '935.00') && !str_contains($txt, '45.50'));
$c = $conexionT6();
$c->respuestas['FROM clients WHERE id = :id'] = [];
$txt = $textoPdfT6($conducesT6($c)->pdf(['client_name' => null, 'company_name' => null, 'rnc' => null] + $filaConduceFx));
$chk('pdf con el cliente borrado: el nombre guardado y sin RNC', str_contains($txt, '(HOSPITAL DOCENTE)') && !str_contains($txt, '401-51513-1'));
$c = $conexionT6();
$c->fallar['FROM clients'] = ConexionFalsaT5::error(2006, 'MySQL server has gone away');
$txt = $textoPdfT6($conducesT6($c)->pdf($filaConduceFx));
$chk('pdf con la lectura del cliente fallando: sale igual, con el cliente del JOIN', str_contains($txt, '(HOSPITAL DOCENTE)'));
$masterT6->fallar = true;
$c = $conexionT6();
$txt = $textoPdfT6($conducesT6($c)->pdf($filaConduceFx));
$masterT6->fallar = false;
$chk('pdf con el catalogo de unidades ilegible: sale igual y cada linea imprime su codigo (26)',
    str_contains($txt, '(26) Tj') && !str_contains($txt, '(Metro) Tj'));
$c = $conexionT6();
$c->fallar['FROM emisor_config'] = ConexionFalsaT5::error(1146, "Table 'tenant.emisor_config' doesn't exist");
$chk('pdf sin poder leer el emisor => 500 "No se pudo generar el PDF del conduce…"', $conducesT6($c)->pdf($filaConduceFx)
    === ['error', 'No se pudo generar el PDF del conduce. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.', 500]);

$usuarioT6->setValue(null, null);
$dbInstanciaT6->setValue(null, $dbAnteriorT6);
$masterInstanciaT6->setValue(null, $masterAnteriorT6);
ini_set('error_log', (string) $logPrevioT6);

// --- T6, ronda C: conduceController, Router y permisos (revision del codigo) ---
// El controller no se puede incluir por CLI (lee $_SERVER, manda cabeceras y
// corta con exit en el 401): aqui se revisa su codigo y el de la ruta. Lo que
// hace de verdad se prueba en el servidor (tests/test_conduces.http).
echo "\n== T6: conduceController, Router y permisos ==\n";
$permisosT6 = require __DIR__ . '/../config/permissions.php';
$chk("permissions.php: 'conduces' => 'cotizaciones', sin modulo RBAC nuevo",
    ($permisosT6['routes']['conduces'] ?? null) === 'cotizaciones' && !in_array('conduces', $permisosT6['catalog'], true));
$routerT6 = (string) file_get_contents(__DIR__ . '/../src/Router.php');
$chk("Router.php: case 'conduces' incluye src/Controllers/conduceController.php",
    preg_match("#case 'conduces':\s*(?://[^\n]*\s*)*require_once 'src/Controllers/conduceController.php';\s*break;#", $routerT6) === 1);
$rutaControllerT6 = __DIR__ . '/../src/Controllers/conduceController.php';
$controllerT6 = is_file($rutaControllerT6) ? (string) file_get_contents($rutaControllerT6) : '';
$chk('conduceController.php existe', $controllerT6 !== '');
$posT6 = static fn(string $aguja): int => ($p = strpos($controllerT6, $aguja)) === false ? PHP_INT_MAX : $p;
$chk('conduceController: el token, despues el formato, despues el cuerpo y por ultimo la DB (spec 4.1)', $controllerT6 !== ''
    && $posT6('$auth->validateRequest()') < $posT6('FerreteriaConduce::errorDisponibilidad()')
    && $posT6('FerreteriaConduce::errorDisponibilidad()') < $posT6('InputSanitizer::jsonInput(false)')
    && $posT6('InputSanitizer::jsonInput(false)') < $posT6('new conduceModel()'));
$chk('conduceController: el 422 del formato responde y sale (return) antes de leer el cuerpo',
    preg_match('#\$noDisponible = FerreteriaConduce::errorDisponibilidad\(\);\s*if \(\$noDisponible !== null\) \{\s*conduceResponderError\(\[\'error\', \$noDisponible, 422\]\);\s*return;#', $controllerT6) === 1);
preg_match_all("#'module' => 'conduces', 'action' => '([A-Z]+)',\s*'entity_type' => 'conduce'#", $controllerT6, $auditT6);
$chk("conduceController: auditoria CREATE, UPDATE y DELETE con module 'conduces' y entity_type 'conduce'", $auditT6[1] === ['CREATE', 'UPDATE', 'DELETE']);
$chk('conduceController: CREATE con el id nuevo y new_values = el cuerpo',
    preg_match("#'action' => 'CREATE',\s*'entity_type' => 'conduce', 'entity_id' => \\\$resultado\[1\]\['id'\],\s*'new_values' => \\\$body,#", $controllerT6) === 1);
$chk('conduceController: UPDATE con old_values = la fila de antes y new_values = el cuerpo',
    preg_match("#'action' => 'UPDATE',\s*'entity_type' => 'conduce', 'entity_id' => \\\$id,\s*'old_values' => \\\$anterior, 'new_values' => \\\$body,#", $controllerT6) === 1);
$chk('conduceController: DELETE con old_values = la fila y sin new_values',
    preg_match("#'action' => 'DELETE',\s*'entity_type' => 'conduce', 'entity_id' => \\\$id,\s*'old_values' => \\\$anterior,\s*'description'#", $controllerT6) === 1);
$chk('conduceController: las sub-rutas con FerreteriaConduce::ruta y el PDF como Conduce_<code>.pdf',
    str_contains($controllerT6, 'FerreteriaConduce::ruta($_SERVER[\'REQUEST_METHOD\'], ') && str_contains($controllerT6, "'Conduce_' . \$row['code'] . '.pdf'"));

// ---------------------------------------------------------------------------
// Las tareas siguientes agregan sus secciones AQUÍ, encima del resumen.
// ---------------------------------------------------------------------------

printf("\n%d/%d OK\n", $total - $fallos, $total);
exit($fallos === 0 ? 0 : 1);
