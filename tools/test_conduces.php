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

// ---------------------------------------------------------------------------
// Las tareas siguientes agregan sus secciones AQUÍ, encima del resumen.
// ---------------------------------------------------------------------------

printf("\n%d/%d OK\n", $total - $fallos, $total);
exit($fallos === 0 ? 0 : 1);
