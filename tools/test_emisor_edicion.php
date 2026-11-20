<?php
/**
 * test_emisor_edicion.php — Editar los datos de la empresa (PUT /api/emisor), sin servidor ni MySQL.
 *
 * Prueba lo que se puede probar sin ellos:
 *   1. EmisorEdicion::validar: que se puede cambiar (todo menos RNC y razon social), los limites del XSD de la
 *      DGII, el telefono 999-999-9999, el correo, el sitio web, la provincia y el municipio contra el catalogo.
 *   2. EmisorConfigModel::update sobre SQLite en memoria: solo escribe columnas editables, null deja en blanco,
 *      y si la fila no queda como se pidio (MySQL recortando texto) se deshace.
 *   3. emisorController.php: el cableado (permiso, bitacora, RNC y razon social, respuesta), leido del codigo.
 * El flujo HTTP completo con base de prueba esta en tools/test_emisor_edicion_api.php.
 *
 * Uso:
 *   php tools/test_emisor_edicion.php            (la parte 2 se salta si falta pdo_sqlite: -d extension=pdo_sqlite)
 */

require_once __DIR__ . '/../src/Utils/EmisorEdicion.php';

$fallos = 0;
$total = 0;
$chk = function (string $desc, bool $ok, $obtenido = null) use (&$fallos, &$total): void {
    $total++;
    if (!$ok) {
        $fallos++;
    }
    printf("  [%s] %s\n", $ok ? 'OK  ' : 'FALLA', $desc);
    if (!$ok && $obtenido !== null) {
        echo '        obtenido: ' . json_encode($obtenido, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n";
    }
};

// Datos de mentira (el repo es publico): nada de una empresa real.
$actual = [
    'id' => 1,
    'rnc' => '131000001',
    'razon_social' => 'Constructora X SRL',
    'nombre_comercial' => 'Constructora X',
    'sucursal' => null,
    'direccion' => 'Av. Winston Churchill 10',
    'municipio' => null,
    'provincia' => null,
    'telefono' => '809-555-0000',
    'correo' => null,
    'website' => null,
    'actividad_economica' => null,
    'fecha_vencimiento_secuencia' => '2030-12-31',
];
$catalogo = [
    '010000' => ['tipo' => 'PROVINCIA', 'provincia_codigo' => '01'],
    '010100' => ['tipo' => 'MUNICIPIO', 'provincia_codigo' => '01'],
    '010101' => ['tipo' => 'DISTRITO', 'provincia_codigo' => '01'],
    '020000' => ['tipo' => 'PROVINCIA', 'provincia_codigo' => '02'],
    '020100' => ['tipo' => 'MUNICIPIO', 'provincia_codigo' => '02'],
];
$ubic = static fn (string $codigo): ?array => $catalogo[$codigo] ?? null;

/** validar() sobre $actual (con $sobre encima) y el catalogo de mentira. */
$v = static fn (array $cuerpo, array $sobre = [], bool $conCatalogo = true): array
    => EmisorEdicion::validar($cuerpo, array_merge($actual, $sobre), $conCatalogo ? $ubic : null);
$unCambio = static fn (array $r, string $campo) => array_key_exists($campo, $r['cambios']) ? $r['cambios'][$campo] : '<<sin cambio>>';
$error = static fn (array $r, string $campo): ?string => $r['errores'][$campo] ?? null;

// ---------------------------------------------------------------------------
echo "== EmisorEdicion: que se puede cambiar ==\n";
$chk('EmisorEdicion es final', (new ReflectionClass('EmisorEdicion'))->isFinal());
$chk('editables: nombre comercial, sucursal, direccion, municipio, provincia, telefono, correo, sitio web y actividad economica',
    EmisorEdicion::EDITABLES === ['nombre_comercial', 'sucursal', 'direccion', 'municipio', 'provincia', 'telefono', 'correo', 'website', 'actividad_economica']);
$chk('bloqueados: solo rnc y razon_social', EmisorEdicion::BLOQUEADOS === ['rnc', 'razon_social']);
$chk('rnc y razon_social no estan entre los editables, ni la fecha de vencimiento ni el ambiente',
    array_intersect(['rnc', 'razon_social', 'fecha_vencimiento_secuencia', 'ambiente', 'id'], EmisorEdicion::EDITABLES) === []);
$chk('largos del XSD: nombre comercial 150, sucursal 20, direccion 100, correo 80, sitio web 50, actividad economica 100',
    EmisorEdicion::LARGO === ['nombre_comercial' => 150, 'sucursal' => 20, 'direccion' => 100, 'correo' => 80, 'website' => 50, 'actividad_economica' => 100]);

$r = $v([]);
$chk('cuerpo vacio: ni cambios ni errores', $r === ['cambios' => [], 'errores' => []], $r);
$r = $v(['ambiente' => 'ecf', 'fuente' => 'emisor_config', 'id' => 9, 'fecha_vencimiento_secuencia' => '2099-01-01', 'certificado' => ['x' => 1]]);
$chk('claves que no son datos de la empresa (ambiente, fuente, id, fecha, certificado) se ignoran', $r === ['cambios' => [], 'errores' => []], $r);
$todoIgual = array_intersect_key($actual, array_flip(array_merge(['rnc', 'razon_social'], EmisorEdicion::EDITABLES)));
$r = $v($todoIgual);
$chk('devolver tal cual lo que dio el GET (con rnc y razon social) no cambia nada ni da error', $r === ['cambios' => [], 'errores' => []], $r);

// ---------------------------------------------------------------------------
echo "\n== RNC y razon social: solo soporte ==\n";
$r = $v(['rnc' => '131000002']);
$chk('un RNC distinto se rechaza con el mensaje de soporte', $error($r, 'rnc') === EmisorEdicion::MSG_BLOQUEADO, $r);
$r = $v(['razon_social' => 'Otra Empresa SRL']);
$chk('una razon social distinta se rechaza con el mensaje de soporte', $error($r, 'razon_social') === EmisorEdicion::MSG_BLOQUEADO, $r);
$chk('el mensaje manda a soporte', str_contains(EmisorEdicion::MSG_BLOQUEADO, 'soporte') && str_contains(EmisorEdicion::MSG_BLOQUEADO, 'RNC') && str_contains(EmisorEdicion::MSG_BLOQUEADO, 'razón social'));
$r = $v(['rnc' => '1-31-00000-1', 'razon_social' => '  constructora   x srl ']);
$chk('el mismo RNC con guiones y la misma razon social con otras mayusculas o espacios son lo mismo: se ignoran', $r === ['cambios' => [], 'errores' => []], $r);
$r = $v(['rnc' => null]);
$chk('rnc null se rechaza (no es el guardado)', $error($r, 'rnc') === EmisorEdicion::MSG_BLOQUEADO, $r);
$r = $v(['razon_social' => ['x']]);
$chk('razon_social que no es texto se rechaza', $error($r, 'razon_social') === EmisorEdicion::MSG_BLOQUEADO, $r);
$r = $v(['rnc' => '999999999', 'razon_social' => 'Otra', 'direccion' => 'Calle Nueva 5']);
$chk('con un cambio valido de direccion pero un RNC distinto: hay error de rnc y de razon_social (el controller rechaza todo)',
    isset($r['errores']['rnc'], $r['errores']['razon_social']) && !isset($r['errores']['direccion']), $r);

// ---------------------------------------------------------------------------
echo "\n== Texto: espacios, largos del XSD y blancos ==\n";
$r = $v(['direccion' => "  Calle   Nueva \n  5  "]);
$chk('la direccion se recorta y junta espacios y saltos de linea en uno', $unCambio($r, 'direccion') === 'Calle Nueva 5', $r);
$r = $v(['nombre_comercial' => 'Pérez & Hijos <Ferretería> "Central"']);
$chk('acentos, &, <, > y comillas se conservan (el XML los escapa)', $unCambio($r, 'nombre_comercial') === 'Pérez & Hijos <Ferretería> "Central"', $r);
$r = $v(['nombre_comercial' => '']);
$chk('nombre comercial en blanco: queda en blanco (null)', $unCambio($r, 'nombre_comercial') === null, $r);
$r = $v(['sucursal' => '', 'correo' => '', 'website' => '', 'actividad_economica' => '', 'municipio' => '', 'provincia' => '']);
$chk('lo opcional que ya estaba en blanco y llega en blanco no es un cambio', $r === ['cambios' => [], 'errores' => []], $r);
$r = $v(['direccion' => '   ']);
$chk('la direccion es obligatoria: en blanco se rechaza (el XSD la pide y la columna es NOT NULL)', $error($r, 'direccion') === EmisorEdicion::MSG_SIN_DIRECCION, $r);
$r = $v(['direccion' => null]);
$chk('direccion null tambien se rechaza', $error($r, 'direccion') === EmisorEdicion::MSG_SIN_DIRECCION, $r);

foreach ([
    ['direccion', 100, 'La dirección'],
    ['nombre_comercial', 150, 'El nombre comercial'],
    ['sucursal', 20, 'La sucursal'],
    ['actividad_economica', 100, 'La actividad económica'],
] as [$campo, $max, $rotulo]) {
    $r = $v([$campo => str_repeat('a', $max)]);
    $chk("{$campo}: {$max} caracteres entran", $unCambio($r, $campo) === str_repeat('a', $max) && $r['errores'] === [], $r);
    $r = $v([$campo => str_repeat('a', $max + 1)]);
    $chk("{$campo}: " . ($max + 1) . " no entran y el mensaje dice el limite", $error($r, $campo) === "{$rotulo} no puede pasar de {$max} caracteres.", $r);
}
$r = $v(['direccion' => str_repeat('á', 100)]);
$chk('el limite cuenta caracteres, no bytes (100 letras con tilde son 200 bytes y entran)', $unCambio($r, 'direccion') === str_repeat('á', 100), $r);
$r = $v(['direccion' => str_repeat('á', 101)]);
$chk('101 letras con tilde no entran', $error($r, 'direccion') !== null, $r);
$r = $v(['direccion' => "\xC3\x28 mala"]);
$chk('un texto que no es UTF-8 valido se rechaza sin romper nada', $error($r, 'direccion') === EmisorEdicion::MSG_VALOR, $r);
$r = $v(['sucursal' => 5]);
$chk('un numero se acepta como texto (sucursal 5)', $unCambio($r, 'sucursal') === '5', $r);
foreach ([['x'], true, new stdClass()] as $i => $raro) {
    $r = $v(['sucursal' => $raro]);
    $chk("un valor que no es texto ni numero (caso {$i}) se rechaza", $error($r, 'sucursal') === EmisorEdicion::MSG_VALOR, $r);
}

// ---------------------------------------------------------------------------
echo "\n== Telefono: 999-999-9999 (TelefonoValidationType) ==\n";
foreach (['8095551234', '(809) 555-1234', '809.555.1234', '809 555 1234', '+1 809 555 1234', '1-809-555-1234', ' 809-555-1234 '] as $forma) {
    $r = $v(['telefono' => $forma]);
    $chk("'{$forma}' se guarda como 809-555-1234", $unCambio($r, 'telefono') === '809-555-1234' && $r['errores'] === [], $r);
}
foreach (['555-1234', '809-555-123', '809-555-12345', '809-555-1234 ext 5', 'sin telefono', '8095551234 / 8295551234'] as $mala) {
    $r = $v(['telefono' => $mala]);
    $chk("'{$mala}' se rechaza con el mensaje del telefono", $error($r, 'telefono') === EmisorEdicion::MSG_TELEFONO, $r);
}
$r = $v(['telefono' => '']);
$chk('telefono en blanco lo deja en blanco', $unCambio($r, 'telefono') === null, $r);
$r = $v(['telefono' => '8095550000']);
$chk('el mismo telefono escrito sin guiones no es un cambio (ya estaba 809-555-0000)', $r === ['cambios' => [], 'errores' => []], $r);
$r = $v(['telefono' => 'tel. 809 555'], ['telefono' => 'tel. 809 555']);
$chk('un telefono heredado que no cumple, devuelto igual, no impide guardar (no es un cambio)', $r === ['cambios' => [], 'errores' => []], $r);
$r = $v(['telefono' => 'tel. 809 555', 'direccion' => 'Calle 9'], ['telefono' => 'tel. 809 555']);
$chk('...y lo demas se guarda: solo cambia la direccion', array_keys($r['cambios']) === ['direccion'] && $r['errores'] === [], $r);

// ---------------------------------------------------------------------------
echo "\n== Correo (CorreoValidationType, max 80) y sitio web (AlfNum50) ==\n";
foreach (['ventas@mi-empresa.com.do', 'a.b+c@x.co', 'Info_1@sub.dominio.com'] as $bueno) {
    $r = $v(['correo' => $bueno]);
    $chk("correo '{$bueno}' entra", $unCambio($r, 'correo') === $bueno && $r['errores'] === [], $r);
}
foreach (['sin-arroba', 'a@b', 'a b@c.com', 'juan@@x.com', 'juan@x..com', '@x.com', 'juan@.com', "a@x.com\nb@x.com"] as $malo) {
    $r = $v(['correo' => $malo]);
    $chk('correo ' . json_encode($malo) . ' se rechaza', $error($r, 'correo') === EmisorEdicion::MSG_CORREO, $r);
}
$ochenta = str_repeat('a', 80 - strlen('@x.com')) . '@x.com';
$r = $v(['correo' => $ochenta]);
$chk('un correo de 80 caracteres entra', strlen($ochenta) === 80 && $unCambio($r, 'correo') === $ochenta, $r);
$r = $v(['correo' => 'a' . $ochenta]);
$chk('uno de 81 se rechaza', $error($r, 'correo') === EmisorEdicion::MSG_CORREO, $r);

$r = $v(['website' => 'www.constructorax.com.do']);
$chk('sitio web normal entra', $unCambio($r, 'website') === 'www.constructorax.com.do', $r);
$r = $v(['website' => 'https://' . str_repeat('a', 42)]);
$chk('sitio web de 50 caracteres entra', $unCambio($r, 'website') === 'https://' . str_repeat('a', 42), $r);
$r = $v(['website' => 'https://' . str_repeat('a', 43)]);
$chk('sitio web de 51 se rechaza con el limite', $error($r, 'website') === 'El sitio web no puede pasar de 50 caracteres.', $r);
$r = $v(['website' => 'www constructorax.com']);
$chk('sitio web con espacios se rechaza', $error($r, 'website') === EmisorEdicion::MSG_WEBSITE_ESPACIOS, $r);

// ---------------------------------------------------------------------------
echo "\n== Provincia y municipio (catalogo DGII) ==\n";
$r = $v(['provincia' => '010000', 'municipio' => '010100']);
$chk('provincia y municipio del catalogo, de la misma provincia, entran', $unCambio($r, 'provincia') === '010000' && $unCambio($r, 'municipio') === '010100' && $r['errores'] === [], $r);
$r = $v(['municipio' => '010101']);
$chk('un distrito municipal vale como municipio', $unCambio($r, 'municipio') === '010101', $r);
foreach (['123', 'Santo Domingo', '01000a', '0100000', ''] as $i => $mal) {
    if ($mal === '') {
        continue;
    }
    $r = $v(['provincia' => $mal]);
    $chk("provincia '{$mal}' (no son 6 digitos) se rechaza", $error($r, 'provincia') === EmisorEdicion::MSG_PROVINCIA, $r);
}
$r = $v(['provincia' => '999999']);
$chk('provincia con 6 digitos que no esta en el catalogo se rechaza', $error($r, 'provincia') === EmisorEdicion::MSG_PROVINCIA, $r);
$r = $v(['provincia' => '010100']);
$chk('un codigo de municipio no vale como provincia', $error($r, 'provincia') === EmisorEdicion::MSG_PROVINCIA, $r);
$r = $v(['municipio' => '010000']);
$chk('un codigo de provincia no vale como municipio', $error($r, 'municipio') === EmisorEdicion::MSG_MUNICIPIO, $r);
$r = $v(['provincia' => '020000', 'municipio' => '010100']);
$chk('municipio de otra provincia que la elegida se rechaza', $error($r, 'municipio') === EmisorEdicion::MSG_MUNICIPIO_AJENO, $r);
$r = $v(['provincia' => '020000'], ['provincia' => '010000', 'municipio' => '010100']);
$chk('cambiar solo la provincia dejando el municipio de la anterior se rechaza (hay que elegirlo de nuevo)', $error($r, 'municipio') === EmisorEdicion::MSG_MUNICIPIO_AJENO, $r);
$r = $v(['provincia' => '020000', 'municipio' => null], ['provincia' => '010000', 'municipio' => '010100']);
$chk('cambiar la provincia y dejar el municipio en blanco entra', $unCambio($r, 'provincia') === '020000' && $unCambio($r, 'municipio') === null && $r['errores'] === [], $r);
$r = $v(['provincia' => '020000', 'municipio' => '020100'], ['provincia' => '010000', 'municipio' => '010100']);
$chk('cambiar las dos a la vez, coherentes, entra', $unCambio($r, 'provincia') === '020000' && $unCambio($r, 'municipio') === '020100' && $r['errores'] === [], $r);
$r = $v(['municipio' => '020100'], ['provincia' => '010000']);
$chk('cambiar solo el municipio a uno de otra provincia que la guardada se rechaza', $error($r, 'municipio') === EmisorEdicion::MSG_MUNICIPIO_AJENO, $r);
$r = $v(['provincia' => '020000'], ['municipio' => 'Santo Domingo Este']);
$chk('un municipio heredado que no es un codigo no bloquea cambiar la provincia (no hay con que compararlo)', $unCambio($r, 'provincia') === '020000' && $r['errores'] === [], $r);
$r = $v(['provincia' => '']);
$r2 = $v(['provincia' => ''], ['provincia' => '010000', 'municipio' => '010100']);
$chk('provincia en blanco: sin cambio si ya estaba en blanco; null si tenia valor', $r === ['cambios' => [], 'errores' => []] && $unCambio($r2, 'provincia') === null, [$r, $r2]);
$r = $v(['provincia' => '999999', 'municipio' => '999999'], [], false);
$chk('sin catalogo disponible (null) solo se exige el formato: 999999 pasa', $unCambio($r, 'provincia') === '999999' && $r['errores'] === [], $r);
$r = $v(['provincia' => '12'], [], false);
$chk('...pero 12 sigue sin pasar', $error($r, 'provincia') === EmisorEdicion::MSG_PROVINCIA, $r);

// ---------------------------------------------------------------------------
echo "\n== Esquemas viejos, cambios parciales y bitacora ==\n";
$sinWebsite = $actual;
unset($sinWebsite['website']);
$r = EmisorEdicion::validar(['website' => 'www.x.com'], $sinWebsite, $ubic);
$chk('si la fila no trae la columna (esquema viejo), un mensaje claro y no un error de SQL', $error($r, 'website') === EmisorEdicion::MSG_SIN_COLUMNA, $r);
$r = $v(['telefono' => '829-555-9999']);
$chk('el cuerpo es parcial: pedir solo el telefono cambia solo el telefono', array_keys($r['cambios']) === ['telefono'], $r);
$r = $v(['direccion' => 'Calle 1', 'telefono' => '829-555-9999', 'correo' => 'mal']);
$chk('varios datos a la vez: los validos se calculan y el malo da su error', $unCambio($r, 'direccion') === 'Calle 1' && $error($r, 'correo') === EmisorEdicion::MSG_CORREO, $r);
$chk('describir para la bitacora: nombra los datos con palabras de la pantalla',
    EmisorEdicion::describir(['direccion', 'telefono', 'actividad_economica']) === 'la dirección, el teléfono, la actividad económica');
$chk('describir una columna desconocida la deja tal cual', EmisorEdicion::describir(['otra']) === 'otra');

// ---------------------------------------------------------------------------
echo "\n== EmisorConfigModel::update (SQLite en memoria) ==\n";
if (!extension_loaded('pdo_sqlite')) {
    echo "  [SALTA] falta pdo_sqlite (php -d extension=pdo_sqlite tools/test_emisor_edicion.php)\n";
} else {
    require_once __DIR__ . '/../src/Models/EmisorConfigModel.php';

    /** Un modelo sobre SQLite en memoria con la tabla y la fila de emisor_config (sin pasar por Database ni MySQL). */
    $modeloSqlite = static function (array $fila) {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $pdo->exec('CREATE TABLE emisor_config (id INTEGER PRIMARY KEY, rnc TEXT NOT NULL, razon_social TEXT NOT NULL, nombre_comercial TEXT NULL,
            sucursal TEXT NULL, direccion TEXT NOT NULL, municipio TEXT NULL, provincia TEXT NULL, telefono TEXT NULL, correo TEXT NULL,
            website TEXT NULL, actividad_economica TEXT NULL, fecha_vencimiento_secuencia TEXT NOT NULL)');
        $cols = array_keys($fila);
        $pdo->prepare('INSERT INTO emisor_config (' . implode(',', $cols) . ') VALUES (:' . implode(',:', $cols) . ')')
            ->execute(array_combine(array_map(static fn ($c) => ':' . $c, $cols), array_values($fila)));
        $modelo = (new ReflectionClass('EmisorConfigModel'))->newInstanceWithoutConstructor();
        $prop = new ReflectionProperty('EmisorConfigModel', 'conexion');
        $prop->setValue($modelo, $pdo);
        return [$modelo, $pdo];
    };

    [$m, $pdo] = $modeloSqlite($actual);
    $fila = $m->update(['direccion' => 'Calle Nueva 5', 'telefono' => '829-555-9999']);
    $chk('update devuelve la fila ya guardada, leida de nuevo', $fila['direccion'] === 'Calle Nueva 5' && $fila['telefono'] === '829-555-9999', $fila);
    $chk('...y las demas columnas quedan como estaban', $fila['nombre_comercial'] === 'Constructora X' && $fila['rnc'] === '131000001' && $fila['razon_social'] === 'Constructora X SRL', $fila);

    $fila = $m->update(['nombre_comercial' => null, 'correo' => 'ventas@x.com']);
    $chk('null deja la columna en blanco (NULL) y otro dato nuevo se guarda', $fila['nombre_comercial'] === null && $fila['correo'] === 'ventas@x.com', $fila);

    $fila = $m->update(['rnc' => '000000000', 'razon_social' => 'HACKEADA', 'fecha_vencimiento_secuencia' => '2000-01-01', 'id' => 7, 'sucursal' => 'Centro']);
    $chk('aunque el arreglo traiga rnc, razon_social, fecha o id, solo se escriben las columnas editables',
        $fila['rnc'] === '131000001' && $fila['razon_social'] === 'Constructora X SRL' && $fila['fecha_vencimiento_secuencia'] === '2030-12-31' && (int) $fila['id'] === 1 && $fila['sucursal'] === 'Centro', $fila);

    $antes = $m->get();
    $fila = $m->update(['rnc' => '000000000']);
    $chk('un arreglo sin ninguna columna editable no escribe nada y devuelve la fila', $fila === $antes && $m->get() === $antes, $fila);

    // Una columna "mas corta de lo que dice el esquema": un trigger recorta lo que se escribe, como haria MySQL sin modo estricto.
    [$m2, $pdo2] = $modeloSqlite($actual);
    $pdo2->exec('CREATE TRIGGER recorta AFTER UPDATE OF direccion ON emisor_config BEGIN UPDATE emisor_config SET direccion = substr(direccion, 1, 5) WHERE id = NEW.id; END');
    $quedo = null;
    try {
        $m2->update(['direccion' => 'Calle muy larga 1234567', 'telefono' => '829-555-9999']);
    } catch (RuntimeException $e) {
        $quedo = $e->getMessage();
    }
    $f2 = $m2->get();
    $chk('si la fila no queda como se pidio, lanza RuntimeException que nombra la columna', is_string($quedo) && str_contains($quedo, 'direccion'), $quedo);
    $chk('...y se deshace TODO (tambien el telefono): la fila queda como estaba', $f2['direccion'] === 'Av. Winston Churchill 10' && $f2['telefono'] === '809-555-0000', $f2);
    $chk('...sin dejar una transaccion abierta', !$pdo2->inTransaction());

    [$m3, $pdo3] = $modeloSqlite($actual);
    $pdo3->exec('DELETE FROM emisor_config');
    $sinFila = null;
    try {
        $m3->update(['direccion' => 'X']);
    } catch (RuntimeException $e) {
        $sinFila = $e->getMessage();
    }
    $chk('sin la fila 1, lanza RuntimeException en vez de decir que guardo', is_string($sinFila) && str_contains($sinFila, 'fila 1'), $sinFila);
    $chk('...y tampoco deja una transaccion abierta', !$pdo3->inTransaction());
}

// ---------------------------------------------------------------------------
echo "\n== Contrato con el XSD de la DGII: lo que se acepta aqui es lo que el XML acepta ==\n";
// Los datos de la empresa viajan en el nodo Emisor del XML firmado de CADA e-CF. Se arma un E31 de verdad con el builder
// (sin firmar) y se valida contra el XSD oficial (samples/). Solo se juzgan los errores que nombran un elemento del
// Emisor: el resto del XML minimo no es el tema de esta prueba.
require_once __DIR__ . '/../src/Utils/FacturacionElectronica/EcfItemMapper.php';
require_once __DIR__ . '/../src/Utils/FacturacionElectronica/ECFXmlBuilder.php';
require_once __DIR__ . '/validar_xml_dgii.php';

/** Errores del XSD del E31 que nombran un elemento del Emisor, para una fila de emisor_config (como la que devuelve el GET). */
$erroresEmisorXsd = static function (array $emisor): array {
    $itemsCrudos = [['numero_linea' => 1, 'indicador_facturacion' => 1, 'nombre_item' => 'Producto',
        'indicador_bien_servicio' => 1, 'cantidad' => '1', 'precio_unitario' => '100.00']];
    $xml = (new ECFXmlBuilder())->build([
        'tipo_ecf' => '31', 'e_ncf' => 'E310000000001', 'fecha_emision' => '26-10-2026', 'fecha_hora_firma' => '26-10-2026 09:00:00',
        'tipo_pago' => 1, 'emisor' => $emisor,
        'comprador' => ['rnc' => '131880681', 'razon_social' => 'DOCUMENTOS ELECTRONICOS DE 03'],
        'items' => EcfItemMapper::map($itemsCrudos, false), 'totales' => EcfItemMapper::totales($itemsCrudos), 'strict_input' => false,
    ]);
    $doc = new DOMDocument();
    $doc->loadXML(str_replace('</ECF>', '<Signature/></ECF>', $xml));   // el XSD exige un elemento final (la Signature)
    libxml_use_internal_errors(true);
    libxml_clear_errors();
    $doc->schemaValidateSource(vxdXsdParaTipo('31', __DIR__ . '/../samples'));
    $errores = [];
    foreach (libxml_get_errors() as $e) {
        if (preg_match("/Element '(RNCEmisor|RazonSocialEmisor|NombreComercial|Sucursal|DireccionEmisor|Municipio|Provincia|TelefonoEmisor|CorreoEmisor|WebSite|ActividadEconomica)'/", $e->message) === 1) {
            $errores[] = trim($e->message);
        }
    }
    libxml_clear_errors();
    return $errores;
};

$chk('control: la fila de ejemplo (sin editar) valida contra el XSD', $erroresEmisorXsd($actual) === [], $erroresEmisorXsd($actual));
$ruido = str_repeat('Ñandú & Hijos <Ferretería> "Central" ', 10);
$maximo = [
    'nombre_comercial' => mb_substr($ruido, 0, 150),
    'sucursal' => str_repeat('S', 20),
    'direccion' => mb_substr($ruido, 0, 100),
    'municipio' => '010100',
    'provincia' => '010000',
    'telefono' => '(809) 555-1234',
    'correo' => str_repeat('a', 80 - strlen('@x.com')) . '@x.com',
    'website' => 'https://' . str_repeat('w', 42),
    'actividad_economica' => mb_substr($ruido, 0, 100),
];
$r = $v($maximo);
$chk('todos los datos en su maximo, con tildes, & < > y comillas, los acepta EmisorEdicion', $r['errores'] === [] && count($r['cambios']) === 9, $r);
$chk('...y la fila resultante (lo que el GET devolveria) valida contra el XSD del E31', $erroresEmisorXsd(array_merge($actual, $r['cambios'])) === [], $erroresEmisorXsd(array_merge($actual, $r['cambios'])));
$chk('el telefono "(809) 555-1234" quedo como 809-555-1234, que es lo que el XSD pide', $r['cambios']['telefono'] === '809-555-1234');
$r = $v(['nombre_comercial' => '', 'sucursal' => '', 'municipio' => '', 'provincia' => '', 'telefono' => '', 'correo' => '', 'website' => '', 'actividad_economica' => ''], ['sucursal' => 'X', 'website' => 'w.com', 'correo' => 'a@b.co']);
$chk('dejar en blanco todo lo opcional tambien valida (el XML solo omite esos elementos)', $erroresEmisorXsd(array_merge($actual, $r['cambios'])) === [] && $r['errores'] === [], $r);

// Contraste: el XSD SI rechaza lo que EmisorEdicion no deja guardar (si no, esta prueba no probaria nada).
foreach ([
    ['telefono', '555-1234', 'TelefonoEmisor'],
    ['direccion', str_repeat('a', 101), 'DireccionEmisor'],
    ['correo', 'sin-arroba', 'CorreoEmisor'],
    ['provincia', '999999', 'Provincia'],
    ['website', str_repeat('w', 51), 'WebSite'],
    ['sucursal', str_repeat('S', 21), 'Sucursal'],
] as [$campo, $malo, $elemento]) {
    $errXsd = $erroresEmisorXsd(array_merge($actual, [$campo => $malo]));
    $chk("contraste {$campo}: el XSD rechaza " . json_encode(strlen($malo) > 30 ? substr($malo, 0, 12) . '...(' . strlen($malo) . ')' : $malo) . " (error en {$elemento})",
        $errXsd !== [] && str_contains($errXsd[0], "'{$elemento}'"), $errXsd);
    $r = $v([$campo => $malo]);
    $chk("...y EmisorEdicion tampoco lo deja guardar ({$campo})", isset($r['errores'][$campo]) && !array_key_exists($campo, $r['cambios']), $r);
}

// ---------------------------------------------------------------------------
echo "\n== emisorController.php: el cableado ==\n";
$ctl = (string) file_get_contents(__DIR__ . '/../src/Controllers/emisorController.php');
$chk('el controller anuncia GET, PUT y OPTIONS (CORS y Allow)',
    str_contains($ctl, "header('Access-Control-Allow-Methods: GET, PUT, OPTIONS');") && str_contains($ctl, "header('Allow: GET, PUT, OPTIONS');"));
$chk('solo GET y PUT; cualquier otro metodo sigue en 405',
    str_contains($ctl, "if (\$metodo !== 'GET' && \$metodo !== 'PUT') {") && str_contains($ctl, "emisorRespond(false, 'Metodo no soportado', 405);"));
$chk('el permiso se exige ANTES de leer la fila (403 sin tocar datos)',
    strpos($ctl, 'emisorExigirPermiso($validation);') !== false && strpos($ctl, 'emisorExigirPermiso($validation);') < strpos($ctl, '$modelo = new EmisorConfigModel();'));
$chk("el rol 'admin' (por nombre) o el modulo 'emisor' que pide el gate; se exige aunque el gate este en sombra",
    str_contains($ctl, "strtolower(\$rol) === 'admin'") && str_contains($ctl, "PermissionGate::permMatches(\$perms, 'emisor')"));
$chk('una credencial de maquina (sin user_id) recibe 403',
    str_contains($ctl, "(\$validation['user_id'] ?? null) === null") && str_contains($ctl, 'Para hacer esto necesitas iniciar sesión con tu usuario.'));
$chk('un rol sin permiso deja el acceso denegado en la bitacora y recibe 403 con el texto del administrador',
    str_contains($ctl, "AuditMiddleware::logAccessDenied(\n        'emisor',") && str_contains($ctl, 'Solo el administrador de la empresa puede cambiar estos datos.'));
$chk('una empresa de integracion (sin DB propia) recibe 409 en el PUT y su GET de siempre',
    preg_match("/'integracion'\) \{\s*if \(\\\$metodo === 'PUT'\) \{\s*emisorRespond\(false, 'Los datos de esta empresa los administra soporte\.', 409\);/", $ctl) === 1);
$chk('los errores de validacion responden 422 con el primer mensaje en error y todos en campos',
    str_contains($ctl, 'http_response_code(422);') && str_contains($ctl, "'error' => reset(\$r['errores']), 'campos' => \$r['errores']"));
$chk('sin cambios responde 422 con EmisorEdicion::MSG_SIN_CAMBIOS', str_contains($ctl, 'emisorRespond(false, EmisorEdicion::MSG_SIN_CAMBIOS, 422);'));
$chk('el catalogo solo se consulta si el cuerpo toca provincia o municipio',
    str_contains($ctl, "array_key_exists('provincia', \$cuerpo) || array_key_exists('municipio', \$cuerpo) ? emisorCatalogo() : null"));
$chk('el fallo al guardar responde 500 sin el texto de la excepcion (va al log)',
    str_contains($ctl, "error_log('[emisor] no se pudo guardar emisor_config: ' . \$e->getMessage());")
    && str_contains($ctl, "'No se pudieron guardar los datos. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.', 500"));
$chk('lo guardado queda en la bitacora (modulo emisor, UPDATE, valores de antes y de despues solo de lo que cambio)',
    preg_match("/'module' => 'emisor', 'action' => 'UPDATE',\s*'entity_type' => 'emisor_config', 'entity_id' => 1,\s*'old_values' => array_intersect_key\(\\\$actual, \\\$r\['cambios'\]\),\s*'new_values' => \\\$r\['cambios'\],/", $ctl) === 1);
$chk('despues de guardar responde como el GET: la fila nueva por el mismo camino (id fuera, ambiente, fuente)',
    strpos($ctl, '$emisor = emisorGuardar($modelo, $emisor);') !== false
    && strpos($ctl, '$emisor = emisorGuardar($modelo, $emisor);') < strpos($ctl, "unset(\$emisor['id']);"));
$chk('el GET no cambia: su orden de claves termina en fuente y certificado', str_contains($ctl, "\$emisor['fuente'] = 'emisor_config';") && str_contains($ctl, "\$emisor['certificado'] = TenantEntitlements::estadoCertificado(\$tenant);"));

$perm = require __DIR__ . '/../config/permissions.php';
$chk("el gate ya pide el modulo 'emisor' para todo lo que no sea GET, y el GET sigue abierto a cualquier usuario",
    ($perm['routes']['emisor'] ?? null) === ['GET' => 'authenticated', '*' => 'emisor']);
$chk("'emisor' es un modulo de administracion: el rol user de sistema no lo trae", !in_array('emisor', $perm['defaults']['user'], true) && in_array('emisor', $perm['catalog'], true));

printf("\n%d/%d OK\n", $total - $fallos, $total);
exit($fallos === 0 ? 0 : 1);
