<?php
/**
 * test_cobros.php — Cobros (cuentas por cobrar por cliente; docs/modules/cobros.md), sin
 * servidor y sin MySQL.
 *
 *   Parte 1 — Reglas puras (src/Utils/Cobros/CobroReglas.php): dinero en centavos, periodos
 *             y vencimientos (el dia 31 en un mes corto), el estado derivado, la validacion de
 *             cada cuerpo, la ruta y cuando toca un recordatorio.
 *   Parte 2 — cobroModel REAL contra SQLite en memoria. Las tablas NO se escriben a mano: se
 *             traducen del DDL de db/tenant_schema.sql (UNIQUE, CHECK, FK y ENUM incluidos), asi
 *             que si el snapshot pierde una restriccion, esta suite lo ve. Cubre: configuracion
 *             mensual, cobros, pagos parciales y completos, la recurrencia (monto ACTUAL, dia
 *             31 -> 28 en febrero, sin duplicar, idempotente), cancelar y reabrir, anular pagos,
 *             el estado segun el reloj, filtros (SQL contra el estado derivado en PHP), el
 *             dashboard, los recordatorios y el panel del cliente.
 *   Parte 3 — clientModel::deleteClient con un cliente con cobros (FK RESTRICT).
 *
 * Uso:
 *   php tools/test_cobros.php
 *   php tools/test_cobros.php --fixtures=<carpeta>   ademas escribe ahi las respuestas REALES del modelo (JSON,
 *                                                     con el sobre {status, data}) para la prueba de contrato
 *                                                     de la web (fiscalo-react scripts/test-cobros.ts)
 *
 * La Parte 2 y la 3 necesitan pdo_sqlite; si no esta activo se saltan con aviso (la Parte 1
 * corre siempre). Para forzarlo:
 *   php -d extension=php_pdo_sqlite.dll tools/test_cobros.php   (Windows)
 *   php -d extension=pdo_sqlite tools/test_cobros.php           (Linux)
 */

require_once __DIR__ . '/write_ahead_test_lib.php';
require_once __DIR__ . '/cobros_test_lib.php';
// Lo que el modelo manda al error_log (un 500 provocado a proposito) no va a la salida.
ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');

require_once __DIR__ . '/../src/Utils/Cobros/CobroReglas.php';
require_once __DIR__ . '/../src/Models/cobroModel.php';
require_once __DIR__ . '/../src/Models/clientModel.php';

/** Un cuerpo JSON como objeto, como lo entrega InputSanitizer::jsonInput(false). */
$cuerpo = static fn(array $a): object => (object) $a;
/** ¿Es un ['success', ...]? */
$exito = static fn(mixed $r): bool => is_array($r) && ($r[0] ?? '') === 'success';
/** ¿Es un ['error', texto, http, codigo?] con ese http (y ese codigo si se pide)? */
$falla = static fn(mixed $r, int $http, ?string $codigo = null): bool => is_array($r) && ($r[0] ?? '') === 'error'
    && ($r[2] ?? 0) === $http && ($codigo === null || ($r[3] ?? null) === $codigo);

// ===========================================================================
// Parte 1 — Reglas puras
// ===========================================================================
echo "== Parte 1: dinero en centavos ==\n";
foreach ([
    [1500, 150000], [0, 0], [-5, -500], ['1500', 150000], ['1500.5', 150050], ['1500.50', 150050], ['0.05', 5],
    ['  12.30 ', 1230], [1500.5, 150050], [0.29, 29], [19.99, 1999], [1234567.89, 123456789], ['9999999999.99', 999999999999],
] as [$entrada, $esperado]) {
    wa_chk('centavos(' . json_encode($entrada) . ') = ' . $esperado, CobroReglas::centavos($entrada) === $esperado, CobroReglas::centavos($entrada));
}
foreach ([['abc'], ['12,50'], ['1.005'], [1.005], ['1e3'], [NAN], [INF], [true], [null], [[1]], [''], ['1 500'], ['12.'], ['.5']] as [$malo]) {
    wa_chk('centavos(' . (is_float($malo) && !is_finite($malo) ? var_export($malo, true) : json_encode($malo)) . ') = null (no es un monto)', CobroReglas::centavos($malo) === null);
}
wa_chk('centavos de un flotante GRANDE con 2 decimales: la tolerancia es relativa (con una fija de 1e-6 se rechazaban como "tres decimales")',
    CobroReglas::centavos(1234567890.12) === 123456789012 && CobroReglas::centavos(9999999999.99) === 999999999999
    && CobroReglas::centavos(123456789.1) === 12345678910 && CobroReglas::centavos(5000000000.07) === 500000000007
    && CobroReglas::centavos(-1234567890.12) === -123456789012);
wa_chk('...y uno grande con tres decimales de verdad sigue siendo null (1234567890.123, 9999999999.995, 0.001, 10.005)',
    CobroReglas::centavos(1234567890.123) === null && CobroReglas::centavos(9999999999.995) === null
    && CobroReglas::centavos(0.001) === null && CobroReglas::centavos(10.005) === null);
wa_chk('centavosDb: texto DECIMAL, entero y un flotante de SQLite', CobroReglas::centavosDb('5000.00') === 500000 && CobroReglas::centavosDb(5000) === 500000
    && CobroReglas::centavosDb(100.10000000000001) === 10010 && CobroReglas::centavosDb(null) === 0);
wa_chk('montoValido: 1 centavo si, 0 no, negativo no, el tope si, el tope + 1 no, null no',
    CobroReglas::montoValido(1) && !CobroReglas::montoValido(0) && !CobroReglas::montoValido(-1)
    && CobroReglas::montoValido(CobroReglas::MAX_CENTAVOS) && !CobroReglas::montoValido(CobroReglas::MAX_CENTAVOS + 1)
    && !CobroReglas::montoValido(null));
wa_chk('aNumero(123456) = 1234.56 exacto como numero JSON', json_encode(CobroReglas::aNumero(123456)) === '1234.56' && json_encode(CobroReglas::aNumero(29)) === '0.29');
wa_chk('aDecimal para la base: 123456 -> "1234.56", 5 -> "0.05", 500000 -> "5000.00", -150 -> "-1.50"',
    CobroReglas::aDecimal(123456) === '1234.56' && CobroReglas::aDecimal(5) === '0.05' && CobroReglas::aDecimal(500000) === '5000.00' && CobroReglas::aDecimal(-150) === '-1.50');
wa_chk('texto: RD$ con miles y 2 decimales', CobroReglas::texto(123456) === 'RD$ 1,234.56' && CobroReglas::texto(5) === 'RD$ 0.05');
$suma = 0;
for ($i = 0; $i < 1000; $i++) {
    $suma += CobroReglas::centavos('0.10');
}
wa_chk('mil sumas de 0.10 dan exactamente 100.00 (en flotante darian 99.9999...)', $suma === 10000 && CobroReglas::aDecimal($suma) === '100.00');

echo "\n== Parte 1: fechas, periodos y vencimientos ==\n";
foreach (['2026-11-16', '2026-02-28', '2028-02-29', '2000-01-01', '2100-12-31'] as $f) {
    wa_chk("fechaValida({$f})", CobroReglas::fechaValida($f));
}
foreach (['2026-02-29', '2026-13-01', '2026-00-10', '2026-11-31', '1999-12-31', '2101-01-01', '26-11-16', '2026-1-1', '', null, 20261116, '2026-11-16 10:00:00', "2026-11-16\n", "2026-11-16\n\n", ' 2026-11-16'] as $f) {
    wa_chk('fechaValida(' . json_encode($f) . ') = false', !CobroReglas::fechaValida($f));
}
wa_chk('periodoValido: 2026-11 si; 2026-13, 2026-00, 2026-1, 2026-11-01 y 1999-12 no',
    CobroReglas::periodoValido('2026-11') && !CobroReglas::periodoValido('2026-13') && !CobroReglas::periodoValido('2026-00')
    && !CobroReglas::periodoValido('2026-1') && !CobroReglas::periodoValido('2026-11-01') && !CobroReglas::periodoValido('1999-12')
    && !CobroReglas::periodoValido(null));
wa_chk('periodoValido y fechaValida rechazan un salto de linea al final (el $ de PCRE lo dejaba pasar sin /D)',
    !CobroReglas::periodoValido("2026-11\n") && !CobroReglas::periodoValido("2026-11\n\n") && !CobroReglas::periodoValido(' 2026-11')
    && !CobroReglas::fechaValida("2026-11-16\n"));
wa_chk('periodoDe("2026-11-16") = 2026-11', CobroReglas::periodoDe('2026-11-16') === '2026-11');
wa_chk('periodoSiguiente: 2026-11 -> 2026-12, 2026-12 -> 2027-01, 2026-01 -> 2026-02',
    CobroReglas::periodoSiguiente('2026-11') === '2026-12' && CobroReglas::periodoSiguiente('2026-12') === '2027-01' && CobroReglas::periodoSiguiente('2026-01') === '2026-02');
wa_chk('diasDelMes: enero 31, abril 30, febrero 2026 = 28, febrero 2028 = 29, febrero 2100 = 28 (siglo no bisiesto)',
    CobroReglas::diasDelMes('2026-01') === 31 && CobroReglas::diasDelMes('2026-04') === 30 && CobroReglas::diasDelMes('2026-02') === 28
    && CobroReglas::diasDelMes('2028-02') === 29 && CobroReglas::diasDelMes('2100-02') === 28);
foreach ([
    ['2026-11', 5, '2026-11-05'], ['2026-11', 31, '2026-11-30'], ['2026-12', 31, '2026-12-31'], ['2027-02', 31, '2027-02-28'],
    ['2027-02', 30, '2027-02-28'], ['2028-02', 30, '2028-02-29'], ['2028-02', 29, '2028-02-29'], ['2026-04', 31, '2026-04-30'],
    ['2026-04', 30, '2026-04-30'], ['2026-01', 1, '2026-01-01'],
] as [$periodo, $dia, $esperado]) {
    wa_chk("vencimientoDe({$periodo}, dia {$dia}) = {$esperado}", CobroReglas::vencimientoDe($periodo, $dia) === $esperado, CobroReglas::vencimientoDe($periodo, $dia));
}
wa_chk('el dia 31 no acumula corrimiento: ene 31, feb 28, mar 31, abr 30, may 31 (siempre desde la configuracion)',
    array_map(fn(string $p): string => CobroReglas::vencimientoDe($p, 31), ['2027-01', '2027-02', '2027-03', '2027-04', '2027-05'])
    === ['2027-01-31', '2027-02-28', '2027-03-31', '2027-04-30', '2027-05-31']);
wa_chk('diasEntre con signo: 16 de nov a 30 de nov = 14; al reves = -14; mismo dia = 0; cruza un anio bisiesto',
    CobroReglas::diasEntre('2026-11-16', '2026-11-30') === 14 && CobroReglas::diasEntre('2026-11-30', '2026-11-16') === -14
    && CobroReglas::diasEntre('2026-11-16', '2026-11-16') === 0 && CobroReglas::diasEntre('2028-02-28', '2028-03-01') === 2);
wa_chk('sumarDias: +7, -30 y cruce de mes y de anio', CobroReglas::sumarDias('2026-11-16', 7) === '2026-11-23' && CobroReglas::sumarDias('2026-11-16', -30) === '2026-10-17'
    && CobroReglas::sumarDias('2026-12-30', 3) === '2027-01-02' && CobroReglas::sumarDias('2026-11-16', 0) === '2026-11-16');
wa_chk('limitesDelMes: 2026-11-16 -> 01 al 30; febrero 2028 -> 01 al 29', CobroReglas::limitesDelMes('2026-11-16') === ['2026-11-01', '2026-11-30']
    && CobroReglas::limitesDelMes('2028-02-10') === ['2028-02-01', '2028-02-29']);
wa_chk('fechaHora normaliza: con T, sin segundos, con espacio; rechaza hora 24 y fecha imposible',
    CobroReglas::fechaHora('2026-11-16T09:30') === '2026-11-16 09:30:00' && CobroReglas::fechaHora('2026-11-16 09:30:15') === '2026-11-16 09:30:15'
    && CobroReglas::fechaHora('2026-11-16 24:00') === null && CobroReglas::fechaHora('2026-02-30 10:00') === null && CobroReglas::fechaHora('mañana') === null);

echo "\n== Parte 1: estado derivado ==\n";
$hoy = '2026-11-16';
foreach ([
    // [cancelado, monto, pagado, vencimiento, esperado, descripcion]
    [false, 500000, 0, '2026-11-17', 'Pendiente', 'sin pagos, vence manana'],
    [false, 500000, 0, '2026-11-16', 'Pendiente', 'vence HOY: todavia no esta vencido'],
    [false, 500000, 0, '2026-11-15', 'Vencido', 'vencio ayer'],
    [false, 500000, 200000, '2026-11-20', 'Parcial', 'abono y aun no vence'],
    [false, 500000, 200000, '2026-11-16', 'Parcial', 'abono y vence hoy'],
    [false, 500000, 200000, '2026-11-15', 'Vencido', 'abono pero ya vencio: sigue Vencido'],
    [false, 500000, 500000, '2026-11-15', 'Pagado', 'pagado aunque su fecha ya paso'],
    [false, 500000, 500000, '2026-12-31', 'Pagado', 'pagado por adelantado'],
    [true, 500000, 0, '2026-11-15', 'Cancelado', 'cancelado y con fecha pasada'],
    [true, 500000, 500000, '2026-11-20', 'Cancelado', 'cancelado manda sobre pagado'],
    [false, 1, 0, '2026-11-16', 'Pendiente', 'un centavo'],
    [false, 500000, 499999, '2026-11-16', 'Parcial', 'falta un centavo'],
] as [$cancelado, $monto, $pagado, $venc, $esperado, $desc]) {
    $obtenido = CobroReglas::estado($cancelado, $monto, $pagado, $venc, $hoy);
    wa_chk("estado: {$desc} -> {$esperado}", $obtenido === $esperado, $obtenido);
}
wa_chk('estadoDe acepta mayusculas y espacios y rechaza lo desconocido', CobroReglas::estadoDe(' vencido ') === 'Vencido' && CobroReglas::estadoDe('PAGADO') === 'Pagado'
    && CobroReglas::estadoDe('Abierto') === null && CobroReglas::estadoDe(null) === null && CobroReglas::estadoDe(3) === null);

echo "\n== Parte 1: validarConfig ==\n";
$r = CobroReglas::validarConfig($cuerpo(['monto_mensual' => 5000, 'dia_vencimiento' => 5, 'descripcion' => '  Mensualidad  ']));
wa_chk('config valida: monto, dia, descripcion recortada y activo por defecto', $exito($r) && $r[1] === ['monto_c' => 500000, 'dia' => 5, 'descripcion' => 'Mensualidad', 'activo' => true], $r);
$r = CobroReglas::validarConfig($cuerpo(['monto_mensual' => '1250.75', 'dia_vencimiento' => '31', 'descripcion' => '', 'activo' => 0]));
wa_chk('config: monto y dia como texto, descripcion vacia = null, activo 0 = false', $exito($r) && $r[1] === ['monto_c' => 125075, 'dia' => 31, 'descripcion' => null, 'activo' => false], $r);
wa_chk('config sin monto: pide el monto', $falla(CobroReglas::validarConfig($cuerpo(['dia_vencimiento' => 5])), 422) && CobroReglas::validarConfig($cuerpo(['dia_vencimiento' => 5]))[1] === CobroReglas::MSG_MONTO_REQUERIDO);
foreach ([0, -10, 'abc', 10.123, true] as $malo) {
    $r = CobroReglas::validarConfig($cuerpo(['monto_mensual' => $malo, 'dia_vencimiento' => 5]));
    wa_chk('config con monto ' . json_encode($malo) . ': 422 con el texto del monto', $falla($r, 422) && $r[1] === CobroReglas::MSG_MONTO, $r);
}
$r = CobroReglas::validarConfig($cuerpo(['monto_mensual' => 10000000000, 'dia_vencimiento' => 5]));
wa_chk('config con monto sobre DECIMAL(12,2): 422 con el texto del tope', $falla($r, 422) && $r[1] === CobroReglas::MSG_MONTO_GRANDE, $r);
foreach ([0, 32, -1, 'x', 5.5, null, '', true, [3]] as $malo) {
    $r = CobroReglas::validarConfig($cuerpo(['monto_mensual' => 100, 'dia_vencimiento' => $malo]));
    wa_chk('config con dia ' . json_encode($malo) . ': 422 con el texto del dia', $falla($r, 422) && $r[1] === CobroReglas::MSG_DIA, $r);
}
foreach ([1, 15, 28, 29, 30, 31, '7'] as $bueno) {
    wa_chk("config con dia {$bueno} pasa", $exito(CobroReglas::validarConfig($cuerpo(['monto_mensual' => 100, 'dia_vencimiento' => $bueno]))));
}
$r = CobroReglas::validarConfig($cuerpo(['monto_mensual' => 100, 'dia_vencimiento' => 5, 'descripcion' => str_repeat('a', 151)]));
wa_chk('config con descripcion de 151 caracteres: 422; de 150, pasa',
    $falla($r, 422) && $r[1] === CobroReglas::MSG_DESCRIPCION
    && $exito(CobroReglas::validarConfig($cuerpo(['monto_mensual' => 100, 'dia_vencimiento' => 5, 'descripcion' => str_repeat('a', 150)]))));
wa_chk('config: la descripcion cuenta caracteres y no bytes (150 letras con tilde pasan)',
    $exito(CobroReglas::validarConfig($cuerpo(['monto_mensual' => 100, 'dia_vencimiento' => 5, 'descripcion' => str_repeat('á', 150)]))));
foreach ([[true, true], ['no', false], ['SI', true], [1, true], ['0', false]] as [$entrada, $esperado]) {
    $r = CobroReglas::validarConfig($cuerpo(['monto_mensual' => 100, 'dia_vencimiento' => 5, 'activo' => $entrada]));
    wa_chk('config activo ' . json_encode($entrada) . ' -> ' . var_export($esperado, true), $exito($r) && $r[1]['activo'] === $esperado, $r);
}
wa_chk('config con activo "quizas": 422', $falla(CobroReglas::validarConfig($cuerpo(['monto_mensual' => 100, 'dia_vencimiento' => 5, 'activo' => 'quizas'])), 422));

echo "\n== Parte 1: validarNuevo y resolverNuevo ==\n";
$r = CobroReglas::validarNuevo($cuerpo([]));
wa_chk('nuevo sin cliente: pide el cliente', $falla($r, 422) && $r[1] === CobroReglas::MSG_CLIENTE, $r);
foreach ([0, -3, 'x', 1.5, null] as $malo) {
    wa_chk('nuevo con client_id ' . json_encode($malo) . ': 422', $falla(CobroReglas::validarNuevo($cuerpo(['client_id' => $malo])), 422));
}
$r = CobroReglas::validarNuevo($cuerpo(['client_id' => '7']));
wa_chk('nuevo solo con el cliente (texto "7"): todo lo demas queda null', $exito($r) && $r[1] === ['client_id' => 7, 'periodo' => null, 'fecha' => null, 'monto_c' => null, 'descripcion' => null], $r);
$r = CobroReglas::validarNuevo($cuerpo(['client_id' => 7, 'periodo' => '2026-12', 'fecha_vencimiento' => '2026-12-05', 'monto' => 1500.5, 'descripcion' => ' Extra ']));
wa_chk('nuevo completo', $exito($r) && $r[1] === ['client_id' => 7, 'periodo' => '2026-12', 'fecha' => '2026-12-05', 'monto_c' => 150050, 'descripcion' => 'Extra'], $r);
wa_chk('nuevo con periodo 2026-13: 422 del periodo', ($r = CobroReglas::validarNuevo($cuerpo(['client_id' => 7, 'periodo' => '2026-13']))) && $falla($r, 422) && $r[1] === CobroReglas::MSG_PERIODO);
wa_chk('nuevo con fecha 2026-02-30: 422 de la fecha', ($r = CobroReglas::validarNuevo($cuerpo(['client_id' => 7, 'fecha_vencimiento' => '2026-02-30']))) && $falla($r, 422) && $r[1] === CobroReglas::MSG_FECHA);
wa_chk('nuevo con monto 0 o "abc": 422 del monto', $falla(CobroReglas::validarNuevo($cuerpo(['client_id' => 7, 'monto' => 0])), 422) && $falla(CobroReglas::validarNuevo($cuerpo(['client_id' => 7, 'monto' => 'abc'])), 422));
$cfg = ['monto_c' => 600000, 'dia' => 31, 'descripcion' => 'Mensualidad'];
$base = ['client_id' => 7, 'periodo' => null, 'fecha' => null, 'monto_c' => null, 'descripcion' => null];
$r = CobroReglas::resolverNuevo($base, $cfg, '2026-12');
wa_chk('resolver con configuracion y nada mas: periodo sugerido, monto y descripcion de la config, vencimiento del dia 31 en diciembre',
    $exito($r) && $r[1] === ['client_id' => 7, 'periodo' => '2026-12', 'fecha' => '2026-12-31', 'monto_c' => 600000, 'descripcion' => 'Mensualidad'], $r);
$r = CobroReglas::resolverNuevo(['fecha' => '2027-02-10'] + $base, $cfg, '2026-12');
wa_chk('resolver: el vencimiento pedido manda y el periodo sale de el (2027-02)', $exito($r) && $r[1]['periodo'] === '2027-02' && $r[1]['fecha'] === '2027-02-10', $r);
$r = CobroReglas::resolverNuevo(['periodo' => '2027-02'] + $base, $cfg, '2026-12');
wa_chk('resolver: el periodo pedido manda y el vencimiento sale de la config (28 de febrero)', $exito($r) && $r[1]['periodo'] === '2027-02' && $r[1]['fecha'] === '2027-02-28', $r);
$r = CobroReglas::resolverNuevo(['monto_c' => 100, 'descripcion' => 'Otro'] + $base, $cfg, '2026-12');
wa_chk('resolver: lo escrito (monto, descripcion) gana a la configuracion', $exito($r) && $r[1]['monto_c'] === 100 && $r[1]['descripcion'] === 'Otro', $r);
$r = CobroReglas::resolverNuevo($base, null, '2026-12');
wa_chk('resolver sin configuracion ni monto: pide el monto', $falla($r, 422) && $r[1] === CobroReglas::MSG_MONTO_REQUERIDO, $r);
$r = CobroReglas::resolverNuevo(['monto_c' => 5000] + $base, null, '2026-12');
wa_chk('resolver sin configuracion y sin fecha: pide la fecha de vencimiento', $falla($r, 422) && $r[1] === CobroReglas::MSG_FECHA_REQUERIDA, $r);
$r = CobroReglas::resolverNuevo(['monto_c' => 5000, 'fecha' => '2026-12-20'] + $base, null, '2026-12');
wa_chk('resolver sin configuracion pero con monto y fecha: pasa, descripcion null', $exito($r) && $r[1]['descripcion'] === null && $r[1]['periodo'] === '2026-12', $r);

echo "\n== Parte 1: validarCambios ==\n";
wa_chk('cambios vacios: 422 "no hay nada que modificar"', ($r = CobroReglas::validarCambios($cuerpo([]))) && $falla($r, 422) && $r[1] === CobroReglas::MSG_SIN_CAMBIOS);
wa_chk('cambios solo con el periodo igual: tampoco es un cambio', $falla(CobroReglas::validarCambios($cuerpo(['periodo' => '2026-11'])), 422));
$r = CobroReglas::validarCambios($cuerpo(['monto' => '800.00', 'fecha_vencimiento' => '2026-12-01', 'descripcion' => '']));
wa_chk('cambios: monto, fecha y descripcion vaciada (null)', $exito($r) && $r[1] === ['monto_c' => 80000, 'fecha' => '2026-12-01', 'descripcion' => null], $r);
wa_chk('cambios con monto negativo o fecha mala: 422', $falla(CobroReglas::validarCambios($cuerpo(['monto' => -1])), 422) && $falla(CobroReglas::validarCambios($cuerpo(['fecha_vencimiento' => '2026-99-99'])), 422));
$r = CobroReglas::validarCambios($cuerpo(['periodo' => '2026-12', 'monto' => 5]));
wa_chk('cambios con periodo: pasa tal cual al modelo para que lo compare', $exito($r) && $r[1]['periodo'] === '2026-12' && $r[1]['monto_c'] === 500, $r);

echo "\n== Parte 1: validarPago ==\n";
$r = CobroReglas::validarPago($cuerpo(['monto' => 2500, 'metodo' => 'Efectivo']), $hoy);
wa_chk('pago minimo: fecha de hoy, metodo en minusculas, sin observaciones ni clave', $exito($r) && $r[1] === ['monto_c' => 250000, 'fecha_pago' => $hoy, 'metodo' => 'efectivo', 'observaciones' => null, 'clave' => null], $r);
$r = CobroReglas::validarPago($cuerpo([
    'monto' => '100.25', 'fecha_pago' => '2026-11-10', 'metodo' => ' TRANSFERENCIA ', 'observaciones' => ' Banco X ',
    'idempotency_key' => '3F2504E0-4F89-41D3-9A0C-0305E82C3301',
]), $hoy);
wa_chk('pago completo: fecha pasada, transferencia, observaciones recortadas y clave en minusculas',
    $exito($r) && $r[1] === ['monto_c' => 10025, 'fecha_pago' => '2026-11-10', 'metodo' => 'transferencia', 'observaciones' => 'Banco X', 'clave' => '3f2504e0-4f89-41d3-9a0c-0305e82c3301'], $r);
wa_chk('pago sin monto: 422 "escribe el monto"', ($r = CobroReglas::validarPago($cuerpo(['metodo' => 'efectivo']), $hoy)) && $falla($r, 422) && $r[1] === CobroReglas::MSG_MONTO_REQUERIDO);
foreach ([0, -5, 'abc', 1.999] as $malo) {
    wa_chk('pago con monto ' . json_encode($malo) . ': 422', $falla(CobroReglas::validarPago($cuerpo(['monto' => $malo, 'metodo' => 'efectivo']), $hoy), 422));
}
foreach ([null, '', 'cheque', 'tarjeta', 5] as $malo) {
    $r = CobroReglas::validarPago($cuerpo(['monto' => 10, 'metodo' => $malo]), $hoy);
    wa_chk('pago con metodo ' . json_encode($malo) . ': 422 con el texto del metodo', $falla($r, 422) && $r[1] === CobroReglas::MSG_METODO, $r);
}
$r = CobroReglas::validarPago($cuerpo(['monto' => 10, 'metodo' => 'efectivo', 'fecha_pago' => '2026-11-17']), $hoy);
wa_chk('pago de manana: 422 (no puede ser posterior a hoy)', $falla($r, 422) && $r[1] === CobroReglas::MSG_FECHA_PAGO_FUTURA, $r);
wa_chk('pago de hoy pasa; de una fecha imposible no', $exito(CobroReglas::validarPago($cuerpo(['monto' => 10, 'metodo' => 'efectivo', 'fecha_pago' => $hoy]), $hoy))
    && $falla(CobroReglas::validarPago($cuerpo(['monto' => 10, 'metodo' => 'efectivo', 'fecha_pago' => '2026-02-30']), $hoy), 422));
wa_chk('pago con observaciones de 256 caracteres: 422; de 255, pasa',
    $falla(CobroReglas::validarPago($cuerpo(['monto' => 10, 'metodo' => 'efectivo', 'observaciones' => str_repeat('x', 256)]), $hoy), 422)
    && $exito(CobroReglas::validarPago($cuerpo(['monto' => 10, 'metodo' => 'efectivo', 'observaciones' => str_repeat('x', 255)]), $hoy)));
foreach (['no-es-uuid', '3f2504e0-4f89-41d3-9a0c-0305e82c330', 123, '3f2504e04f8941d39a0c0305e82c3301'] as $mala) {
    wa_chk('pago con clave ' . json_encode($mala) . ': 422', $falla(CobroReglas::validarPago($cuerpo(['monto' => 10, 'metodo' => 'efectivo', 'idempotency_key' => $mala]), $hoy), 422));
}
wa_chk('clave: null y "" = sin clave; UUID valido en minusculas; basura = false',
    CobroReglas::clave(null) === null && CobroReglas::clave('') === null && CobroReglas::clave('3F2504E0-4F89-41D3-9A0C-0305E82C3301') === '3f2504e0-4f89-41d3-9a0c-0305e82c3301' && CobroReglas::clave('x') === false);

echo "\n== Parte 1: validarRecordatorio y validarMotivo ==\n";
$ahora = '2026-11-16 10:00:00';
$r = CobroReglas::validarRecordatorio($cuerpo([]), $ahora);
wa_chk('recordatorio vacio: ahora, canal "otro", sin nota', $exito($r) && $r[1] === ['fecha_hora' => $ahora, 'canal' => 'otro', 'nota' => null], $r);
$r = CobroReglas::validarRecordatorio($cuerpo(['fecha_hora' => '2026-11-15T09:30', 'canal' => 'Llamada', 'nota' => ' Dijo que paga el viernes ']), $ahora);
wa_chk('recordatorio completo: fecha normalizada, canal en minusculas, nota recortada', $exito($r) && $r[1] === ['fecha_hora' => '2026-11-15 09:30:00', 'canal' => 'llamada', 'nota' => 'Dijo que paga el viernes'], $r);
wa_chk('recordatorio de manana: 422; con 30 segundos de adelanto del reloj, pasa',
    $falla(CobroReglas::validarRecordatorio($cuerpo(['fecha_hora' => '2026-11-17 10:00:00']), $ahora), 422)
    && $exito(CobroReglas::validarRecordatorio($cuerpo(['fecha_hora' => '2026-11-16 10:00:30']), $ahora)));
wa_chk('recordatorio con canal "whatsapp": 422 (el canal es un registro, no una integracion)', ($r = CobroReglas::validarRecordatorio($cuerpo(['canal' => 'whatsapp']), $ahora)) && $falla($r, 422) && $r[1] === CobroReglas::MSG_CANAL);
wa_chk('recordatorio con nota de 256 caracteres o fecha mala: 422', $falla(CobroReglas::validarRecordatorio($cuerpo(['nota' => str_repeat('n', 256)]), $ahora), 422)
    && $falla(CobroReglas::validarRecordatorio($cuerpo(['fecha_hora' => 'ayer']), $ahora), 422));
wa_chk('motivo: vacio = null, recortado, y de 256 caracteres da 422', CobroReglas::validarMotivo($cuerpo([])) === ['success', null]
    && CobroReglas::validarMotivo($cuerpo(['motivo' => ' Error de captura ']))[1] === 'Error de captura'
    && $falla(CobroReglas::validarMotivo($cuerpo(['motivo' => str_repeat('m', 256)])), 422));

echo "\n== Parte 1: filtros, paginacion y numeros de la query ==\n";
$r = CobroReglas::validarFiltros([]);
wa_chk('filtros vacios: sin filtro y orden de urgencia', $exito($r) && $r[1] === ['client_id' => null, 'estado' => null, 'periodo' => null, 'desde' => null, 'hasta' => null, 'query' => null, 'orden' => 'urgencia'], $r);
$r = CobroReglas::validarFiltros(['client_id' => '4', 'estado' => 'vencido', 'periodo' => '2026-11', 'vence_desde' => '2026-11-01', 'vence_hasta' => '2026-11-30', 'query' => ' acme ', 'orden' => 'saldo_desc']);
wa_chk('filtros completos: el estado se normaliza y la busqueda se recorta', $exito($r) && $r[1] === ['client_id' => 4, 'estado' => 'Vencido', 'periodo' => '2026-11', 'desde' => '2026-11-01', 'hasta' => '2026-11-30', 'query' => 'acme', 'orden' => 'saldo_desc'], $r);
foreach ([['estado' => 'Abierto'], ['periodo' => '2026-13'], ['vence_desde' => '2026-02-30'], ['client_id' => 'x'], ['orden' => 'azar'], ['vence_desde' => '2026-12-01', 'vence_hasta' => '2026-11-01']] as $malo) {
    wa_chk('un filtro invalido no se ignora: ' . json_encode($malo) . ' da 422', $falla(CobroReglas::validarFiltros($malo), 422));
}
wa_chk('paginacion: por defecto 1/10; offset de la pagina 3 de 25 = 50; tope de 100 por pagina; basura = defecto',
    CobroReglas::paginacion([]) === ['page' => 1, 'pageSize' => 10, 'offset' => 0]
    && CobroReglas::paginacion(['page' => '3', 'pageSize' => '25']) === ['page' => 3, 'pageSize' => 25, 'offset' => 50]
    && CobroReglas::paginacion(['pageSize' => 5000])['pageSize'] === 100
    && CobroReglas::paginacion(['page' => 'x', 'pageSize' => -4]) === ['page' => 1, 'pageSize' => 10, 'offset' => 0]);
wa_chk('paginacion: una pagina absurda (PHP_INT_MAX, 99999999999999999999) se topa en MAX_PAGINA y el offset sigue siendo un entero (antes daba un 500)',
    CobroReglas::paginacion(['page' => '9223372036854775807'])['page'] === CobroReglas::MAX_PAGINA
    && CobroReglas::paginacion(['page' => '99999999999999999999'])['page'] === CobroReglas::MAX_PAGINA
    && is_int(CobroReglas::paginacion(['page' => (string) PHP_INT_MAX, 'pageSize' => 100])['offset'])
    && CobroReglas::paginacion(['page' => '100000', 'pageSize' => '100'])['offset'] === 9999900);
wa_chk('enteroDe: defecto, valor, tope y basura', CobroReglas::enteroDe([], 'dias', 7) === 7 && CobroReglas::enteroDe(['dias' => '15'], 'dias', 7) === 15
    && CobroReglas::enteroDe(['dias' => '9999'], 'dias', 7) === 90 && CobroReglas::enteroDe(['dias' => '0'], 'dias', 7) === 7 && CobroReglas::enteroDe(['dias' => 'x'], 'dias', 7) === 7);

echo "\n== Parte 1: cuando toca un recordatorio ==\n";
foreach ([
    // [estado, vencimiento, ultimo, hoy, cada, esperado, descripcion]
    ['Vencido', '2026-11-01', null, '2026-11-16', 7, true, 'nunca se le recordo'],
    ['Vencido', '2026-11-01', '2026-11-16 08:00:00', '2026-11-16', 7, false, 'se le recordo hoy'],
    ['Vencido', '2026-11-01', '2026-11-10 08:00:00', '2026-11-16', 7, false, 'se le recordo hace 6 dias'],
    ['Vencido', '2026-11-01', '2026-11-09 08:00:00', '2026-11-16', 7, true, 'se le recordo hace 7 dias: ya toca otro'],
    ['Pendiente', '2026-11-18', '2026-11-16 08:00:00', '2026-11-16', 7, false, 'vence pronto y ya se le aviso'],
    ['Vencido', '2026-11-18', '2026-11-16 08:00:00', '2026-11-19', 7, true, 'se le aviso antes de vencer y ahora ya vencio'],
    ['Vencido', '2026-11-18', '2026-11-19 08:00:00', '2026-11-20', 7, false, 'se le recordo despues de vencer'],
    ['Vencido', '2026-11-18', '2026-11-18 20:00:00', '2026-11-19', 7, true, 'se le recordo el dia que vencia: es un aviso de "vence hoy"'],
] as [$estado, $venc, $ultimo, $h, $cada, $esperado, $desc]) {
    wa_chk("requiereRecordatorio: {$desc} -> " . ($esperado ? 'si' : 'no'), CobroReglas::requiereRecordatorio($estado, $venc, $ultimo, $h, $cada) === $esperado);
}

echo "\n== Parte 1: ruta ==\n";
foreach ([
    // [metodo, ruta, accion, id, pago_id, client_id]
    ['GET', '/api/cobros', 'listar', null, null, null], ['GET', '/api/cobros/', 'listar', null, null, null],
    ['POST', '/api/cobros', 'crear', null, null, null], ['PUT', '/api/cobros', 'metodo', null, null, null], ['DELETE', '/api/cobros', 'metodo', null, null, null],
    ['GET', '/api/cobros/resumen', 'resumen', null, null, null], ['POST', '/api/cobros/resumen', 'metodo', null, null, null],
    ['GET', '/api/cobros/recordatorios', 'recordatorios', null, null, null], ['PUT', '/api/cobros/recordatorios', 'metodo', null, null, null],
    ['GET', '/api/cobros/clientes/7', 'panel', null, null, 7], ['POST', '/api/cobros/clientes/7', 'metodo', null, null, 7],
    ['PUT', '/api/cobros/clientes/7/config', 'config', null, null, 7], ['GET', '/api/cobros/clientes/7/config', 'metodo', null, null, 7],
    ['GET', '/api/cobros/clientes/abc', 'desconocida', null, null, null], ['GET', '/api/cobros/clientes/0', 'desconocida', null, null, null],
    ['GET', '/api/cobros/12', 'obtener', 12, null, null], ['PUT', '/api/cobros/12', 'actualizar', 12, null, null], ['DELETE', '/api/cobros/12', 'metodo', 12, null, null],
    ['POST', '/api/cobros/12/pagos', 'pagar', 12, null, null], ['GET', '/api/cobros/12/pagos', 'metodo', 12, null, null],
    ['POST', '/api/cobros/12/cancelar', 'cancelar', 12, null, null], ['POST', '/api/cobros/12/reabrir', 'reabrir', 12, null, null],
    ['POST', '/api/cobros/12/recordatorios', 'recordar', 12, null, null], ['POST', '/api/cobros/12/otra', 'desconocida', null, null, null],
    ['POST', '/api/cobros/pagos/9/anular', 'anular', null, 9, null], ['GET', '/api/cobros/pagos/9/anular', 'metodo', null, 9, null],
    ['POST', '/api/cobros/pagos/9', 'desconocida', null, null, null], ['GET', '/api/cobros/0', 'desconocida', null, null, null],
    ['GET', '/api/cobros/-3', 'desconocida', null, null, null], ['GET', '/api/facturas', 'desconocida', null, null, null],
    ['get', '/index.php/api/cobros/12/', 'obtener', 12, null, null], ['GET', '/cobros/12', 'obtener', 12, null, null],
] as [$m, $path, $accion, $id, $pagoId, $clientId]) {
    $ruta = CobroReglas::ruta($m, $path);
    wa_chk("ruta {$m} {$path} -> {$accion}", $ruta === ['accion' => $accion, 'id' => $id, 'pago_id' => $pagoId, 'client_id' => $clientId], $ruta);
}
wa_chk('idDe: enteros positivos y texto de digitos; nada mas', CobroReglas::idDe(5) === 5 && CobroReglas::idDe('12') === 12 && CobroReglas::idDe(0) === null
    && CobroReglas::idDe(-1) === null && CobroReglas::idDe('1.5') === null && CobroReglas::idDe('abc') === null && CobroReglas::idDe(null) === null && CobroReglas::idDe(1.0) === null);

// ===========================================================================
// Parte 2 — cobroModel real contra SQLite en memoria
// ===========================================================================
if (!extension_loaded('pdo_sqlite')) {
    echo "\n(SALTADA: Partes 2 y 3 — pdo_sqlite no esta activo; corre con -d extension=pdo_sqlite)\n";
    wa_fin();
}

/** Una base nueva con las tablas de Cobros (y clients) traducidas del snapshot, con las FK activas. */
function cobros_base(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    foreach (cobros_ddl_sqlite(__DIR__ . '/../db/tenant_schema.sql', ['clients', 'cobro_config', 'cobros', 'cobro_pagos', 'cobro_recordatorios']) as $ddl) {
        $pdo->exec($ddl);
    }
    return $pdo;
}

echo "\n== Parte 2: el DDL real traducido a SQLite ==\n";
$ddl = cobros_ddl_sqlite(__DIR__ . '/../db/tenant_schema.sql', ['clients', 'cobro_config', 'cobros', 'cobro_pagos', 'cobro_recordatorios']);
wa_chk('se traducen las cinco tablas y sus indices del snapshot (5 CREATE TABLE y 7 CREATE INDEX)',
    count(array_filter($ddl, fn(string $d): bool => str_starts_with($d, 'CREATE TABLE'))) === 5
    && count(array_filter($ddl, fn(string $d): bool => str_contains($d, 'INDEX'))) === 7, $ddl);
$pdo = cobros_base();
$pdo->exec("INSERT INTO clients (email, client_name, company_name, phone_number, rnc, razon_social) VALUES
    ('a@x.do', 'Ana Gómez', 'ACME', '809-555-0001', '131256432', 'ACME SRL'),
    ('', 'Beta Contacto', 'Beta SA', '', NULL, NULL),
    ('', 'Carlos Pérez', 'Carlos Pérez', '', '00112345678', NULL),
    ('', 'Delta Contacto', 'Delta SRL', '', NULL, 'Delta SRL'),
    ('', 'Epsilon Contacto', 'Epsilon SRL', '', NULL, NULL),
    ('', 'Foxtrot Contacto', 'Foxtrot SA', '', NULL, NULL),
    ('', 'Golf Contacto', 'Golf SA', '', NULL, NULL),
    ('', 'Hotel Contacto', 'Hotel 100% SA', '', NULL, NULL)");
$pdo->exec("INSERT INTO cobro_config (client_id, monto_mensual, dia_vencimiento, activo, created_at) VALUES (8, 1, 1, 1, '2026-01-01 00:00:00')");
wa_chk('el UNIQUE (client_id, periodo) del snapshot rechaza un duplicado directo en la base',
    (function () use ($pdo): bool {
        $ins = $pdo->prepare("INSERT INTO cobros (client_id, periodo, monto, fecha_vencimiento, created_at) VALUES (8, '2026-05', 10, '2026-05-10', '2026-01-01 00:00:00')");
        $ins->execute();
        try {
            $ins->execute();
            return false;
        } catch (PDOException $e) {
            return stripos($e->getMessage(), 'UNIQUE') !== false;
        }
    })());
wa_chk('el CHECK monto_pagado <= monto del snapshot rechaza un saldo negativo directo en la base',
    (function () use ($pdo): bool {
        try {
            $pdo->exec("UPDATE cobros SET monto_pagado = 11 WHERE periodo = '2026-05'");
            return false;
        } catch (PDOException $e) {
            return stripos($e->getMessage(), 'CHECK') !== false;
        }
    })());
wa_chk('el CHECK monto > 0 y el ENUM de metodo del snapshot rechazan un cobro de 0 y un metodo inventado',
    (function () use ($pdo): bool {
        $rechazados = 0;
        foreach ([
            "INSERT INTO cobros (client_id, periodo, monto, fecha_vencimiento, created_at) VALUES (8, '2026-06', 0, '2026-06-10', 'x')",
            "INSERT INTO cobro_pagos (cobro_id, monto, fecha_pago, metodo, created_at) VALUES (1, 5, '2026-05-11', 'cheque', 'x')",
            "INSERT INTO cobro_config (client_id, monto_mensual, dia_vencimiento, created_at) VALUES (7, 100, 32, 'x')",
        ] as $sql) {
            try {
                $pdo->exec($sql);
            } catch (PDOException $e) {
                $rechazados++;
            }
        }
        return $rechazados === 3;
    })());
wa_chk('la FK cobros -> clients es RESTRICT: no se borra un cliente con cobros',
    (function () use ($pdo): bool {
        try {
            $pdo->exec('DELETE FROM clients WHERE id = 8');
            return false;
        } catch (PDOException $e) {
            return stripos($e->getMessage(), 'FOREIGN KEY') !== false;
        }
    })());
$pdo = null;

// El reloj de las pruebas: se mueve con $ahora.
$fx = []; // respuestas reales que --fixtures vuelca al final
$ahora = '2026-11-16 10:00:00';
$reloj = function () use (&$ahora): string {
    return $ahora;
};
$pdo = cobros_base();
$pdo->exec("INSERT INTO clients (email, client_name, company_name, phone_number, rnc, razon_social) VALUES
    ('a@x.do', 'Ana Gómez', 'ACME', '809-555-0001', '131256432', 'ACME SRL'),
    ('', 'Beta Contacto', 'Beta SA', '', NULL, NULL),
    ('', 'Carlos Pérez', 'Carlos Pérez', '', '00112345678', NULL),
    ('', 'Delta Contacto', 'Delta SRL', '', NULL, 'Delta SRL'),
    ('', 'Epsilon Contacto', 'Epsilon SRL', '', NULL, NULL),
    ('', 'Foxtrot Contacto', 'Foxtrot SA', '', NULL, NULL),
    ('', 'Golf Contacto', 'Golf SA', '', NULL, NULL),
    ('', 'Hotel Contacto', 'Hotel 100% SA', '', NULL, NULL)");
$m = new cobroModel($pdo, $reloj);
// ids de clientes: A=1 ACME, B=2 Beta, C=3 Carlos, D=4 Delta, E=5 Epsilon, F=6 Foxtrot, G=7 Golf, H=8 Hotel.
$cfg = static fn(int $monto, int $dia, ?string $desc = null, bool $activo = true): array => ['monto_c' => $monto, 'dia' => $dia, 'descripcion' => $desc, 'activo' => $activo];
$nuevo = static fn(int $cliente, ?string $periodo = null, ?string $fecha = null, ?int $monto = null, ?string $desc = null): array
    => ['client_id' => $cliente, 'periodo' => $periodo, 'fecha' => $fecha, 'monto_c' => $monto, 'descripcion' => $desc];
$pago = static fn(int $monto, ?string $clave = null, ?string $fecha = null, string $metodo = 'efectivo', ?string $obs = null): array
    => ['monto_c' => $monto, 'fecha_pago' => $fecha ?? substr($GLOBALS['ahora'], 0, 10), 'metodo' => $metodo, 'observaciones' => $obs, 'clave' => $clave];

echo "\n== Parte 2: configuracion mensual ==\n";
$r = $m->guardarConfig(1, $cfg(500000, 31, 'Mensualidad'), 7);
wa_chk('crear la configuracion del cliente A: 5000.00, dia 31', $exito($r) && $r[1]['config']['monto_mensual'] === 5000.0 && $r[1]['config']['dia_vencimiento'] === 31
    && $r[1]['config']['activo'] === true && $r[1]['config']['descripcion'] === 'Mensualidad' && $r[1]['anterior'] === null, $r);
wa_chk('guardarConfig de un cliente que no existe: 404', $falla($m->guardarConfig(999, $cfg(100, 5), 7), 404));
wa_chk('obtenerConfig: la de A y null para uno sin configuracion', $m->obtenerConfig(1)['monto_mensual'] === 5000.0 && $m->obtenerConfig(3) === null);

echo "\n== Parte 2: crear cobros y el estado derivado ==\n";
$r = $m->crear($nuevo(1, '2026-10'), 7);
$A1 = $exito($r) ? $r[1] : [];
$fx['detalle'] = $A1;
wa_chk('cobro de A para octubre con la config: monto 5000, vence el 31 de octubre, descripcion de la config',
    $exito($r) && $A1['periodo'] === '2026-10' && $A1['monto'] === 5000.0 && $A1['fecha_vencimiento'] === '2026-10-31' && $A1['descripcion'] === 'Mensualidad'
    && $A1['generado_de_cobro_id'] === null && $A1['cliente']['nombre'] === 'ACME SRL' && $A1['cliente']['contacto'] === 'Ana Gómez', $r);
wa_chk('el estado se deriva: vencido hace 16 dias, sin abonos, saldo 5000', $A1['estado'] === 'Vencido' && $A1['dias_atraso'] === 16 && $A1['dias_para_vencer'] === null
    && $A1['con_abonos'] === false && $A1['saldo'] === 5000.0 && $A1['monto_pagado'] === 0.0, $A1);
$m->guardarConfig(1, $cfg(600000, 31, 'Mensualidad'), 7);
wa_chk('cambiar el monto mensual a 6000 NO toca el cobro ya generado: sigue en 5000', $m->obtener($A1['id'])['monto'] === 5000.0);

echo "\n== Parte 2: pagos parciales y completos ==\n";
$r = $m->registrarPago($A1['id'], $pago(200000, null, '2026-10-20', 'transferencia', 'Abono'), 7);
wa_chk('pago parcial de 2000: pagado 2000, saldo 3000, sigue Vencido pero con abonos, sin siguiente',
    $exito($r) && $r[1]['cobro']['monto_pagado'] === 2000.0 && $r[1]['cobro']['saldo'] === 3000.0 && $r[1]['cobro']['estado'] === 'Vencido' && $r[1]['cobro']['con_abonos'] === true
    && $r[1]['siguiente'] === null && $r[1]['siguiente_nuevo'] === false && $r[1]['repetido'] === false && $r[1]['pago']['metodo'] === 'transferencia' && $r[1]['siguiente_motivo'] === null, $r);
$r = $m->registrarPago($A1['id'], $pago(400000), 7);
wa_chk('un pago mayor que el saldo (4000 contra 3000): 422 EXCEDE_SALDO con el saldo en data', $falla($r, 422, 'EXCEDE_SALDO') && $r[4]['saldo'] === 3000.0 && str_contains($r[1], 'RD$ 3,000.00'), $r);
wa_chk('el pago rechazado no dejo nada (sigue en 2000)', $m->obtener($A1['id'])['monto_pagado'] === 2000.0 && count($m->obtener($A1['id'])['pagos']) === 1);
$K1 = '3f2504e0-4f89-41d3-9a0c-0305e82c3301';
$r = $m->registrarPago($A1['id'], $pago(300000, $K1, '2026-11-10'), 7);
$A2 = $exito($r) ? ($r[1]['siguiente'] ?? []) : [];
$fx['pago'] = $r[1] ?? [];
wa_chk('el pago que completa el cobro lo deja Pagado', $exito($r) && $r[1]['cobro']['estado'] === 'Pagado' && $r[1]['cobro']['saldo'] === 0.0 && $r[1]['cobro']['monto_pagado'] === 5000.0, $r);
wa_chk('...y genera el cobro de noviembre con el monto ACTUAL (6000, no los 5000 del cobro pagado), vence el 30 (el 31 no existe) y apunta a su origen',
    $A2 !== [] && $r[1]['siguiente_nuevo'] === true && $A2['periodo'] === '2026-11' && $A2['monto'] === 6000.0 && $A2['fecha_vencimiento'] === '2026-11-30'
    && $A2['generado_de_cobro_id'] === $A1['id'] && $A2['descripcion'] === 'Mensualidad' && $A2['estado'] === 'Pendiente' && $A2['dias_para_vencer'] === 14, $A2);
wa_chk('el cobro pagado conserva su monto original (5000) y sus dos pagos', $m->obtener($A1['id'])['monto'] === 5000.0 && count($m->obtener($A1['id'])['pagos']) === 2);
wa_chk('...la respuesta dice por que hay un siguiente (siguiente_motivo = generado) y el cobro ya pagado deja de tener "abonos" (con_abonos false)',
    $r[1]['siguiente_motivo'] === 'generado' && $r[1]['cobro']['con_abonos'] === false, $r[1]['siguiente_motivo']);
$r2 = $m->registrarPago($A1['id'], $pago(300000, $K1, '2026-11-10'), 7);
$fx['pago-repetido'] = $r2[1] ?? [];
wa_chk('REPETIR la peticion con la misma clave: no registra otro pago, devuelve el mismo, sin generar otro cobro',
    $exito($r2) && $r2[1]['repetido'] === true && $r2[1]['siguiente_nuevo'] === false && $r2[1]['pago']['id'] === $r[1]['pago']['id']
    && $r2[1]['siguiente']['id'] === $A2['id'] && count($m->obtener($A1['id'])['pagos']) === 2, $r2);
$cuenta = static fn(string $sql, array $p = []): int => (int) (function () use ($pdo, $sql, $p) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); })();
wa_chk('y A sigue con exactamente 2 cobros (octubre y noviembre)', $cuenta('SELECT COUNT(*) FROM cobros WHERE client_id = 1') === 2);
wa_chk('una repeticion no vuelve a decir nada del siguiente (siguiente_motivo null)', $r2[1]['siguiente_motivo'] === null);
wa_chk('pagar un cobro ya pagado: 409 YA_PAGADO', $falla($m->registrarPago($A1['id'], $pago(100), 7), 409, 'YA_PAGADO'));
wa_chk('la misma clave en OTRO cobro: 409 CLAVE_REPETIDA', $falla($m->registrarPago($A2['id'], $pago(100, $K1), 7), 409, 'CLAVE_REPETIDA'));
wa_chk('la misma clave con OTRO monto: 409 CLAVE_CON_OTROS_DATOS (no se le contesta "ya estaba registrado" a otra operacion)',
    $falla($m->registrarPago($A1['id'], $pago(100, $K1, '2026-11-10'), 7), 409, 'CLAVE_CON_OTROS_DATOS'));
wa_chk('...ni con otra fecha, otro metodo u otras observaciones: tampoco es una repeticion, y no se registra nada',
    $falla($m->registrarPago($A1['id'], $pago(300000, $K1, '2026-11-09'), 7), 409, 'CLAVE_CON_OTROS_DATOS')
    && $falla($m->registrarPago($A1['id'], $pago(300000, $K1, '2026-11-10', 'transferencia'), 7), 409, 'CLAVE_CON_OTROS_DATOS')
    && $falla($m->registrarPago($A1['id'], $pago(300000, $K1, '2026-11-10', 'efectivo', 'otra nota'), 7), 409, 'CLAVE_CON_OTROS_DATOS')
    && count($m->obtener($A1['id'])['pagos']) === 2);
wa_chk('pagar un cobro que no existe: 404', $falla($m->registrarPago(9999, $pago(100), 7), 404));
$A2 = $m->obtener($A2['id']);
wa_chk('el cobro generado arranca Pendiente con saldo 6000', $A2['estado'] === 'Pendiente' && $A2['saldo'] === 6000.0 && $A2['monto_pagado'] === 0.0);

echo "\n== Parte 2: la cadena mensual (dia 31 hasta marzo) ==\n";
$cadena = [];
$actual = $A2;
for ($i = 0; $i < 4; $i++) {
    $r = $m->registrarPago($actual['id'], $pago(600000), 7);
    $cadena[] = $exito($r) && $r[1]['siguiente'] !== null ? [$r[1]['siguiente']['periodo'], $r[1]['siguiente']['fecha_vencimiento'], $r[1]['siguiente']['monto']] : [null];
    $actual = $r[1]['siguiente'] ?? $actual;
}
wa_chk('pagar cada mes genera el siguiente: dic 31, ene 31, feb 28 (clamp), mar 31, todos con 6000',
    $cadena === [['2026-12', '2026-12-31', 6000.0], ['2027-01', '2027-01-31', 6000.0], ['2027-02', '2027-02-28', 6000.0], ['2027-03', '2027-03-31', 6000.0]], $cadena);
$A6 = $actual;
wa_chk('A termina con 6 cobros (oct 2026 a mar 2027), uno por periodo', $cuenta('SELECT COUNT(*) FROM cobros WHERE client_id = 1') === 6
    && $cuenta('SELECT COUNT(DISTINCT periodo) FROM cobros WHERE client_id = 1') === 6);
wa_chk('el ultimo (marzo 2027) esta Pendiente y es el unico abierto de A', $A6['periodo'] === '2027-03' && $A6['estado'] === 'Pendiente'
    && $cuenta("SELECT COUNT(*) FROM cobros WHERE client_id = 1 AND monto_pagado < monto") === 1);
wa_chk('cada cobro generado apunta al anterior (la cadena se puede seguir)', $cuenta('SELECT COUNT(*) FROM cobros WHERE client_id = 1 AND generado_de_cobro_id IS NOT NULL') === 5);

echo "\n== Parte 2: sin configuracion activa no hay recurrencia ==\n";
$m->guardarConfig(2, $cfg(100000, 10, null, false), 7);
$r = $m->crear($nuevo(2, null, '2026-11-20', 75050), 7);
$B1 = $exito($r) ? $r[1] : [];
wa_chk('cobro de B con monto y vencimiento escritos (750.50) aunque su config diga 1000: lo escrito manda', $exito($r) && $B1['monto'] === 750.5 && $B1['fecha_vencimiento'] === '2026-11-20' && $B1['periodo'] === '2026-11', $r);
$m->registrarPago($B1['id'], $pago(25025), 7);
$r = $m->registrarPago($B1['id'], $pago(50025), 7);
wa_chk('dos pagos (250.25 + 500.25) suman exactos 750.50 y lo dejan Pagado', $exito($r) && $r[1]['cobro']['monto_pagado'] === 750.5 && $r[1]['cobro']['estado'] === 'Pagado', $r);
wa_chk('con la config INACTIVA no se genera el cobro siguiente', $r[1]['siguiente'] === null && $cuenta('SELECT COUNT(*) FROM cobros WHERE client_id = 2') === 1);
wa_chk('...y dice por que: siguiente_motivo = inactivo', $r[1]['siguiente_motivo'] === 'inactivo', $r[1]['siguiente_motivo']);
$r = $m->crear($nuevo(3), 7);
wa_chk('cliente sin config y sin monto: 422 pide el monto', $falla($r, 422) && $r[1] === CobroReglas::MSG_MONTO_REQUERIDO, $r);
$r = $m->crear($nuevo(3, null, null, 30000), 7);
wa_chk('cliente sin config y sin fecha: 422 pide la fecha', $falla($r, 422) && $r[1] === CobroReglas::MSG_FECHA_REQUERIDA, $r);
$r = $m->crear($nuevo(3, null, '2026-11-01', 30000), 7);
$C1 = $exito($r) ? $r[1] : [];
wa_chk('cobro de C (sin config): monto 300, vencio el 1 de noviembre (15 dias), descripcion null', $exito($r) && $C1['estado'] === 'Vencido' && $C1['dias_atraso'] === 15 && $C1['descripcion'] === null, $r);
$r = $m->registrarPago($C1['id'], $pago(30000), 7);
wa_chk('pagar el cobro de un cliente SIN configuracion no genera siguiente', $exito($r) && $r[1]['cobro']['estado'] === 'Pagado' && $r[1]['siguiente'] === null && $cuenta('SELECT COUNT(*) FROM cobros WHERE client_id = 3') === 1);
wa_chk('...y dice por que: siguiente_motivo = sin_config', $r[1]['siguiente_motivo'] === 'sin_config', $r[1]['siguiente_motivo']);
wa_chk('crear para un cliente que no existe: 404', $falla($m->crear($nuevo(999, null, '2026-11-01', 100), 7), 404));

echo "\n== Parte 2: sin duplicados por cliente y periodo ==\n";
$m->guardarConfig(4, $cfg(200000, 15), 7);
$r = $m->crear($nuevo(4, '2026-11'), 7);
$D1 = $exito($r) ? $r[1] : [];
$r = $m->crear($nuevo(4, '2026-12'), 7);
$D2 = $exito($r) ? $r[1] : [];
wa_chk('dos cobros de D (nov y dic) con la config: 2000 cada uno, vencen el 15', $D1['fecha_vencimiento'] === '2026-11-15' && $D2['fecha_vencimiento'] === '2026-12-15' && $D2['monto'] === 2000.0);
$r = $m->crear($nuevo(4, '2026-11'), 7);
wa_chk('crear otro del mismo cliente y periodo: 409 COBRO_DUPLICADO con el id del que ya existe', $falla($r, 409, 'COBRO_DUPLICADO') && $r[4]['cobro_id'] === $D1['id'] && $r[4]['periodo'] === '2026-11', $r);
$r = $m->registrarPago($D1['id'], $pago(200000), 7);
wa_chk('pagar el de noviembre de D cuando diciembre YA existe: no genera otro (siguiente null)', $exito($r) && $r[1]['siguiente'] === null && $r[1]['siguiente_nuevo'] === false
    && $cuenta('SELECT COUNT(*) FROM cobros WHERE client_id = 4') === 2, $r);
wa_chk('...y dice por que: siguiente_motivo = ya_existia', $r[1]['siguiente_motivo'] === 'ya_existia', $r[1]['siguiente_motivo']);

echo "\n== Parte 2: cancelar y reabrir ==\n";
$m->guardarConfig(2, $cfg(100000, 10, null, false), 7);
$r = $m->crear($nuevo(2, '2026-12'), 7);
$B2 = $exito($r) ? $r[1] : [];
wa_chk('cobro de B para diciembre con la config (inactiva sirve de valores por defecto): 1000, vence el 10', $B2['monto'] === 1000.0 && $B2['fecha_vencimiento'] === '2026-12-10', $B2);
wa_chk('cancelar un cobro con pagos: 409 TIENE_PAGOS', $falla($m->cancelar($A1['id'], 'x', 7), 409, 'TIENE_PAGOS'));
$r = $m->cancelar($B2['id'], 'El cliente pidio saltar diciembre', 7);
$fx['cancelado'] = $r[1]['cobro'] ?? [];
wa_chk('cancelar uno sin pagos: Cancelado, saldo 0 y el motivo guardado', $exito($r) && $r[1]['cobro']['estado'] === 'Cancelado' && $r[1]['cobro']['saldo'] === 0.0
    && $r[1]['cobro']['motivo_cancelacion'] === 'El cliente pidio saltar diciembre' && $r[1]['repetido'] === false, $r);
$r = $m->cancelar($B2['id'], null, 7);
wa_chk('cancelar de nuevo es lo mismo que una vez (repetido)', $exito($r) && $r[1]['repetido'] === true && $r[1]['cobro']['estado'] === 'Cancelado');
wa_chk('un cobro cancelado no recibe pagos (409 COBRO_CANCELADO), ni cambios, ni recordatorios',
    $falla($m->registrarPago($B2['id'], $pago(100), 7), 409, 'COBRO_CANCELADO') && $falla($m->actualizar($B2['id'], ['monto_c' => 5000], 7), 409, 'COBRO_CANCELADO')
    && $falla($m->registrarRecordatorio($B2['id'], ['fecha_hora' => $ahora, 'canal' => 'llamada', 'nota' => null], 7, 'u'), 409, 'COBRO_CANCELADO'));
$r = $m->crear($nuevo(2, '2026-12'), 7);
wa_chk('crear el mismo periodo de uno cancelado: 409 y el texto manda a reabrirlo', $falla($r, 409, 'COBRO_DUPLICADO') && str_contains($r[1], 'cancelado') && $r[4]['estado'] === 'Cancelado', $r);
$r = $m->reabrir($B2['id'], 7);
wa_chk('reabrir: vuelve a estar Pendiente con su saldo', $exito($r) && $r[1]['cobro']['estado'] === 'Pendiente' && $r[1]['cobro']['saldo'] === 1000.0 && $r[1]['cobro']['cancelado_at'] === null && $r[1]['repetido'] === false, $r);
wa_chk('reabrir de nuevo es lo mismo que una vez (repetido)', ($r = $m->reabrir($B2['id'], 7)) && $exito($r) && $r[1]['repetido'] === true);
wa_chk('cancelar o reabrir un cobro que no existe: 404', $falla($m->cancelar(9999, null, 7), 404) && $falla($m->reabrir(9999, 7), 404));

echo "\n== Parte 2: anular pagos ==\n";
$r = $m->registrarPago($D2['id'], $pago(50000), 7);
$P1 = $exito($r) ? $r[1]['pago'] : [];
$r = $m->registrarPago($D2['id'], $pago(70000), 7);
$P2 = $exito($r) ? $r[1]['pago'] : [];
wa_chk('dos pagos de 500 y 700 al cobro de diciembre de D: pagado 1200, Parcial', $r[1]['cobro']['monto_pagado'] === 1200.0 && $r[1]['cobro']['estado'] === 'Parcial', $r);
$r = $m->anularPago($P2['id'], 'Se digito mal', 7);
$fx['anulado'] = $r[1] ?? [];
wa_chk('anular el pago de 700: el cobro vuelve a 500 pagado, y el pago se queda a la vista como anulado con su motivo',
    $exito($r) && $r[1]['cobro']['monto_pagado'] === 500.0 && $r[1]['cobro']['saldo'] === 1500.0 && $r[1]['pago']['anulado'] === true && $r[1]['pago']['motivo_anulacion'] === 'Se digito mal'
    && $r[1]['repetido'] === false && count($r[1]['cobro']['pagos']) === 2, $r);
wa_chk('anular otra vez el mismo pago es lo mismo que una vez (repetido) y no cambia el saldo', ($r = $m->anularPago($P2['id'], 'otra vez', 7)) && $exito($r) && $r[1]['repetido'] === true
    && $r[1]['cobro']['monto_pagado'] === 500.0 && $r[1]['pago']['motivo_anulacion'] === 'Se digito mal');
wa_chk('anular un pago que no existe: 404', $falla($m->anularPago(9999, null, 7), 404));
$K2 = '22222222-3333-4444-8555-666666666666';
$r = $m->registrarPago($D2['id'], $pago(10000, $K2), 7);
$r2 = $exito($r) ? $m->anularPago($r[1]['pago']['id'], 'Error de digitacion', 7) : $r;
wa_chk('un pago con clave que se anula: el cobro vuelve a lo de antes (500 pagado)', $exito($r) && $exito($r2) && $r2[1]['cobro']['monto_pagado'] === 500.0, [$r, $r2]);
wa_chk('REPETIR esa clave (mismos datos) cuando el pago ya se anulo: 409 PAGO_ANULADO; no se "resucita" ni se registra otro',
    $falla($m->registrarPago($D2['id'], $pago(10000, $K2), 7), 409, 'PAGO_ANULADO') && $m->obtener($D2['id'])['monto_pagado'] === 500.0);
$ult = $m->registrarPago($A1['id'], $pago(100), 7);
$pagoK1 = null;
foreach ($m->obtener($A1['id'])['pagos'] as $p) {
    if ($p['monto'] === 3000.0) {
        $pagoK1 = $p;
    }
}
$r = $m->anularPago($pagoK1['id'], 'Rebote', 7);
wa_chk('anular el pago que completo el octubre de A: vuelve a Vencido con saldo 3000 y el cobro de noviembre YA generado se queda',
    $exito($r) && $r[1]['cobro']['estado'] === 'Vencido' && $r[1]['cobro']['saldo'] === 3000.0 && $cuenta('SELECT COUNT(*) FROM cobros WHERE client_id = 1') === 6, $r);
$r = $m->registrarPago($A1['id'], $pago(300000, '11111111-2222-4333-8444-555555555555'), 7);
wa_chk('volver a pagarlo completo: Pagado otra vez y NO genera de nuevo el de noviembre (ya existe: siguiente_nuevo false)',
    $exito($r) && $r[1]['cobro']['estado'] === 'Pagado' && $r[1]['siguiente_nuevo'] === false && $r[1]['siguiente']['id'] === $A2['id'] && $cuenta('SELECT COUNT(*) FROM cobros WHERE client_id = 1') === 6, $r);
wa_chk('...con siguiente_motivo = ya_existia', $r[1]['siguiente_motivo'] === 'ya_existia', $r[1]['siguiente_motivo']);

echo "\n== Parte 2: modificar un cobro ==\n";
wa_chk('el monto no puede quedar bajo lo ya pagado (500): 422 MONTO_MENOR_PAGADO', $falla($m->actualizar($D2['id'], ['monto_c' => 40000], 7), 422, 'MONTO_MENOR_PAGADO'));
wa_chk('el periodo no se cambia: 422', $falla($m->actualizar($D2['id'], ['periodo' => '2027-01', 'monto_c' => 90000], 7), 422));
$r = $m->actualizar($D2['id'], ['fecha' => '2026-12-20', 'descripcion' => 'Extra diciembre'], 7);
wa_chk('cambiar la fecha y la descripcion', $exito($r) && $r[1]['cobro']['fecha_vencimiento'] === '2026-12-20' && $r[1]['cobro']['descripcion'] === 'Extra diciembre' && $r[1]['anterior']['fecha_vencimiento'] === '2026-12-15' && $r[1]['siguiente'] === null, $r);
wa_chk('...y no completa nada: siguiente_motivo null', $r[1]['siguiente_motivo'] === null);
$r = $m->actualizar($D2['id'], ['descripcion' => null], 7);
wa_chk('descripcion null la vacia', $exito($r) && $r[1]['cobro']['descripcion'] === null);
$r = $m->actualizar($D2['id'], ['monto_c' => 50000], 7);
wa_chk('bajar el monto a lo ya pagado (500) lo deja Pagado y genera el siguiente (enero 2027, 2000, vence el 15)',
    $exito($r) && $r[1]['cobro']['estado'] === 'Pagado' && $r[1]['siguiente'] !== null && $r[1]['siguiente']['periodo'] === '2027-01'
    && $r[1]['siguiente']['monto'] === 2000.0 && $r[1]['siguiente']['fecha_vencimiento'] === '2027-01-15', $r);
wa_chk('...y la respuesta lo dice: siguiente_motivo = generado', $r[1]['siguiente_motivo'] === 'generado', $r[1]['siguiente_motivo']);
$D3 = $r[1]['siguiente'];
$fx['actualizado'] = ['cobro' => $r[1]['cobro'], 'siguiente' => $r[1]['siguiente'], 'siguiente_motivo' => $r[1]['siguiente_motivo']];
$r = $m->actualizar($D2['id'], ['descripcion' => 'ya pagado'], 7);
wa_chk('editar solo la descripcion de uno ya pagado no genera nada por su cuenta', $exito($r) && $r[1]['siguiente'] === null && $cuenta('SELECT COUNT(*) FROM cobros WHERE client_id = 4') === 3);
$r = $m->actualizar($D3['id'], ['monto_c' => 250000], 7);
wa_chk('subir el monto de un cobro abierto: monto 2500, saldo 2500', $exito($r) && $r[1]['cobro']['monto'] === 2500.0 && $r[1]['cobro']['saldo'] === 2500.0);
wa_chk('actualizar un cobro que no existe: 404', $falla($m->actualizar(9999, ['monto_c' => 100], 7), 404));
// E: config inactiva, pagado sin siguiente; activarla despues y editar no genera.
$m->guardarConfig(5, $cfg(10000, 28, null, false), 7);
$E1 = $m->crear($nuevo(5, '2026-11', '2026-11-30', 10000), 7)[1];
$m->registrarPago($E1['id'], $pago(10000), 7);
$m->guardarConfig(5, $cfg(10000, 28, null, true), 7);
$r = $m->actualizar($E1['id'], ['descripcion' => 'x'], 7);
wa_chk('activar la config DESPUES de pagar y editar el cobro pagado no genera el siguiente de rebote', $exito($r) && $r[1]['siguiente'] === null && $cuenta('SELECT COUNT(*) FROM cobros WHERE client_id = 5') === 1);

echo "\n== Parte 2: el estado depende del reloj, no de lo guardado ==\n";
$otroReloj = static fn(string $f): cobroModel => new cobroModel($pdo, static fn(): string => $f);
$e = static fn(string $f): array => [$otroReloj($f)->obtener($D3['id'])['estado'], $otroReloj($f)->obtener($D3['id'])['dias_atraso'], $otroReloj($f)->obtener($D3['id'])['dias_para_vencer']];
wa_chk('el cobro de D que vence el 15 de enero: Pendiente el 14, Pendiente el mismo 15 (vence hoy), Vencido el 16 con 1 dia de atraso',
    $e('2027-01-14 23:59:00') === ['Pendiente', 0, 1] && $e('2027-01-15 00:00:00') === ['Pendiente', 0, 0] && $e('2027-01-16 08:00:00') === ['Vencido', 1, null], [$e('2027-01-14 23:59:00'), $e('2027-01-15 00:00:00'), $e('2027-01-16 08:00:00')]);
$m->registrarPago($D3['id'], $pago(100000), 7);
wa_chk('con un abono de 1000: Parcial antes de vencer y Vencido (con abonos) despues, sin escribir nada en la base',
    $otroReloj('2027-01-10 10:00:00')->obtener($D3['id'])['estado'] === 'Parcial' && $otroReloj('2027-02-10 10:00:00')->obtener($D3['id'])['estado'] === 'Vencido'
    && $otroReloj('2027-02-10 10:00:00')->obtener($D3['id'])['con_abonos'] === true);

echo "\n== Parte 2: datos para el dashboard y los filtros ==\n";
// F (6): dos cobros viejos; G (7): uno vencido y uno que vence en 2 dias; H (8): vence hoy, vigente y uno cancelado.
$F1 = $m->crear($nuevo(6, '2026-08', '2026-08-15', 100000), 7)[1];
$F2 = $m->crear($nuevo(6, '2026-09', '2026-09-20', 200000), 7)[1];
$m->registrarPago($F2['id'], $pago(50000), 7);
$G1 = $m->crear($nuevo(7, '2026-10', '2026-10-31', 80000), 7)[1];
$G2 = $m->crear($nuevo(7, '2026-11', '2026-11-18', 120000), 7)[1];
$H1 = $m->crear($nuevo(8, '2026-11', '2026-11-16', 500000), 7)[1];
$H2 = $m->crear($nuevo(8, '2026-12', '2026-12-31', 300000), 7)[1];
$H3 = $m->crear($nuevo(8, '2026-10', '2026-10-01', 99900), 7)[1];
$m->cancelar($H3['id'], null, 7);
wa_chk('el cobro que vence HOY esta Pendiente con 0 dias para vencer', $m->obtener($H1['id'])['estado'] === 'Pendiente' && $m->obtener($H1['id'])['dias_para_vencer'] === 0);
wa_chk('la base queda consistente: monto_pagado = suma de los pagos vigentes, dentro de 0..monto, sin duplicados', cobros_invariantes($pdo) === [], cobros_invariantes($pdo));

$todos = $m->listar(CobroReglas::validarFiltros([])[1], 0, 500);
$totalCobros = $m->contar(CobroReglas::validarFiltros([])[1]);
wa_chk('listar sin filtros trae todos y contar coincide (' . $totalCobros . ')', count($todos) === $totalCobros && $totalCobros === $cuenta('SELECT COUNT(*) FROM cobros'));
$porEstado = [];
foreach (CobroReglas::ESTADOS as $est) {
    $porEstado[$est] = array_column($m->listar(CobroReglas::validarFiltros(['estado' => $est])[1], 0, 500), 'id');
    // Parcial = con abonos y todavia debe, VENZA O NO: un vencido con abonos es "Vencido" y tambien parcial.
    $esperados = array_column(array_filter($todos, fn(array $c): bool => $est === CobroReglas::PARCIAL ? $c['con_abonos'] === true : $c['estado'] === $est), 'id');
    sort($esperados);
    $obtenidos = $porEstado[$est];
    sort($obtenidos);
    wa_chk("el filtro estado={$est} (SQL) devuelve EXACTAMENTE lo que el estado derivado (PHP) dice: " . count($esperados) . ' cobros', $obtenidos === $esperados, [$obtenidos, $esperados]);
    wa_chk("contar con estado={$est} coincide", $m->contar(CobroReglas::validarFiltros(['estado' => $est])[1]) === count($esperados));
}
$particion = array_merge($porEstado['Cancelado'], $porEstado['Pagado'], $porEstado['Vencido'], $porEstado['Pendiente'], array_diff($porEstado['Parcial'], $porEstado['Vencido']));
wa_chk('Cancelado, Pagado, Vencido, Pendiente y los Parcial que no estan vencidos reparten todos los cobros sin repetir ninguno',
    count($particion) === $totalCobros && count(array_unique($particion)) === $totalCobros, [count($particion), $totalCobros]);
wa_chk('el filtro Parcial incluye al vencido con abonos (F2: 500 de 2,000, vencido en septiembre), que tambien sale en Vencido',
    in_array($F2['id'], $porEstado['Parcial'], true) && in_array($F2['id'], $porEstado['Vencido'], true));
wa_chk('hay al menos un cobro de cada estado en los datos de prueba (la comparacion no es vacia)', min(array_map('count', $porEstado)) >= 1, array_map('count', $porEstado));
$ids = static fn(array $f): array => array_column($m->listar(CobroReglas::validarFiltros($f)[1], 0, 500), 'id');
wa_chk('filtro por cliente: solo los 6 cobros de A', count($ids(['client_id' => 1])) === 6 && count(array_unique(array_column($m->listar(CobroReglas::validarFiltros(['client_id' => 1])[1], 0, 500), 'client_id'))) === 1);
wa_chk('busqueda por nombre del cliente (sin importar mayusculas ni la columna): "acme" (razon social) trae solo a A', count($ids(['query' => 'acme'])) === 6 && count($ids(['query' => 'ACME SRL'])) === 6);
wa_chk('busqueda por nombre de contacto: "foxtrot contacto" trae los 2 de F', count($ids(['query' => 'Foxtrot Contacto'])) === 2);
wa_chk('busqueda por RNC: "131256432" trae a A', count($ids(['query' => '131256432'])) === 6);
wa_chk('un % en la busqueda se busca como texto: "100%" encuentra a Hotel 100% SA (3 cobros) y "%" solo no trae todo', count($ids(['query' => '100%'])) === 3 && count($ids(['query' => '%'])) === 3 && count($ids(['query' => '_'])) === 0);
wa_chk('busqueda sin resultados: lista vacia y total 0', $ids(['query' => 'zzzz']) === [] && $m->contar(CobroReglas::validarFiltros(['query' => 'zzzz'])[1]) === 0);
wa_chk('filtro por periodo 2026-11: los de noviembre de A, B, C, D, E, G y H (7)', count($ids(['periodo' => '2026-11'])) === 7, $ids(['periodo' => '2026-11']));
wa_chk('filtro por vencimiento entre el 1 y el 20 de noviembre: B(20), C(1), D(15), G... (los que vencen en esas fechas)',
    (function () use ($ids, $m): bool {
        $r = $m->listar(CobroReglas::validarFiltros(['vence_desde' => '2026-11-01', 'vence_hasta' => '2026-11-20'])[1], 0, 500);
        $fechas = array_column($r, 'fecha_vencimiento');
        return $r !== [] && min($fechas) >= '2026-11-01' && max($fechas) <= '2026-11-20' && count($r) === 5;
    })(), array_column($m->listar(CobroReglas::validarFiltros(['vence_desde' => '2026-11-01', 'vence_hasta' => '2026-11-20'])[1], 0, 500), 'fecha_vencimiento'));
wa_chk('filtros combinados: vencidos del cliente F = sus 2 cobros', $ids(['estado' => 'Vencido', 'client_id' => 6]) === array_values(array_intersect($ids(['client_id' => 6]), $ids(['estado' => 'Vencido']))) && count($ids(['estado' => 'Vencido', 'client_id' => 6])) === 2);
$pag1 = $m->listar(CobroReglas::validarFiltros([])[1], 0, 4);
$pag2 = $m->listar(CobroReglas::validarFiltros([])[1], 4, 4);
wa_chk('paginacion: dos paginas de 4 no se pisan y siguen el mismo orden', count($pag1) === 4 && count($pag2) === 4 && array_column(array_merge($pag1, $pag2), 'id') === array_slice(array_column($todos, 'id'), 0, 8));
$abiertos = array_values(array_filter($todos, fn(array $c): bool => $c['saldo'] > 0));
$cerrados = array_values(array_filter($todos, fn(array $c): bool => $c['saldo'] <= 0));
wa_chk('orden por urgencia: primero los abiertos del mas atrasado al mas lejano, despues los cerrados del mas reciente al mas viejo',
    array_slice(array_column($todos, 'id'), 0, count($abiertos)) === array_column($abiertos, 'id')
    && array_column($abiertos, 'fecha_vencimiento') === (function () use ($abiertos): array { $f = array_column($abiertos, 'fecha_vencimiento'); sort($f); return $f; })()
    && array_column($cerrados, 'fecha_vencimiento') === (function () use ($cerrados): array { $f = array_column($cerrados, 'fecha_vencimiento'); rsort($f); return $f; })(), array_map(fn(array $c): string => $c['id'] . ':' . $c['fecha_vencimiento'] . ':' . $c['saldo'], $todos));
wa_chk('el mas atrasado (F, agosto) abre la lista', $todos[0]['id'] === $F1['id'] && $todos[0]['estado'] === 'Vencido' && $todos[0]['dias_atraso'] === 93);
$porSaldo = $m->listar(CobroReglas::validarFiltros(['orden' => 'saldo_desc'])[1], 0, 3);
wa_chk('orden por saldo: el mayor saldo primero (A, marzo 2027: 6000)', $porSaldo[0]['saldo'] === 6000.0 && $porSaldo[0]['saldo'] >= $porSaldo[1]['saldo'] && $porSaldo[1]['saldo'] >= $porSaldo[2]['saldo']);

echo "\n== Parte 2: dashboard ==\n";
$res = $m->resumen(7);
$fx['resumen'] = $res;
$t = $res['totales'];
// Valores calculados a mano de los datos de arriba (hoy = 2026-11-16).
wa_chk('totales: pendiente 21,000 = vencido 3,300 (F 1000 + F 1500 + G 800) + lo demas abierto 17,700', $t['vencido'] === 3300.0 && $t['pendiente'] === 21000.0, $t);
wa_chk('por vencer en 7 dias: 6,200 (G 1200 el 18 + H 5000 hoy)', $t['por_vencer'] === 6200.0, $t);
wa_chk('por cobrar en el mes: 6,200 (lo abierto que vence en noviembre)', $t['por_cobrar_mes'] === 6200.0, $t);
wa_chk('facturado en el mes: 15,350.50 (todo lo no cancelado que vence en noviembre, pagado o no)', $t['facturado_mes'] === 15350.5, $t);
$cobradoMes = 0;
foreach ($todos as $c) {
    foreach ($m->obtener($c['id'])['pagos'] as $p) {
        if (!$p['anulado'] && substr($p['fecha_pago'], 0, 7) === '2026-11') {
            $cobradoMes += CobroReglas::centavos($p['monto']);
        }
    }
}
wa_chk('cobrado en el mes = suma de los pagos vigentes con fecha de noviembre (no cuenta el abono de octubre ni los anulados): ' . CobroReglas::aNumero($cobradoMes),
    $t['cobrado_mes'] === CobroReglas::aNumero($cobradoMes) && $t['cobrado_mes'] > 0, [$t['cobrado_mes'], CobroReglas::aNumero($cobradoMes)]);
wa_chk('conteos: 9 cobros abiertos, 3 vencidos, 6 clientes con saldo (A, B, D, F, G, H) y 2 con atraso (F y G)',
    $t['cobros_abiertos'] === 9 && $t['cobros_vencidos'] === 3 && $t['clientes_con_saldo'] === 6 && $t['clientes_con_atraso'] === 2, $t);
$ant = array_column($res['antiguedad'], null, 'tramo');
wa_chk('antiguedad: 90+ = 1,000; 31-60 = 1,500; 1-30 = 800; 61-90 = 0; vigente = 17,700 en 6 cobros (G 1200, H 5000 y 3000, A 6000, B 1000, D 1500 de saldo)',
    $ant['90+']['monto'] === 1000.0 && $ant['31-60']['monto'] === 1500.0 && $ant['1-30']['monto'] === 800.0 && $ant['61-90']['monto'] === 0.0
    && $ant['vigente']['monto'] === 17700.0 && $ant['vigente']['cobros'] === 6 && $ant['90+']['cobros'] === 1 && $ant['61-90']['cobros'] === 0
    && array_column($res['antiguedad'], 'tramo') === ['vigente', '1-30', '31-60', '61-90', '90+'], $res['antiguedad']);
wa_chk('antiguedad suma lo mismo que lo pendiente', array_sum(array_column($res['antiguedad'], 'monto')) === $t['pendiente']);
wa_chk('el dashboard y los recordatorios dicen la fecha y la hora de RD del servidor (hoy y ahora): la pantalla no tiene que fiarse del reloj del navegador',
    $res['hoy'] === '2026-11-16' && $res['ahora'] === $ahora && $m->recordatorios(7, 7)['hoy'] === '2026-11-16' && $m->recordatorios(7, 7)['ahora'] === $ahora);
wa_chk('proximos vencimientos: el de hoy y el del 18, en ese orden, con su cliente', array_column($res['proximos'], 'id') === [$H1['id'], $G2['id']]
    && $res['proximos'][0]['cliente']['nombre'] === 'Hotel 100% SA' && $res['proximos'][1]['dias_para_vencer'] === 2);
wa_chk('clientes con atraso: F (2 cobros, 2,500, desde el 15 de agosto, 93 dias) y G (1 cobro, 800, 16 dias), el que mas debe primero',
    count($res['atrasados']) === 2 && $res['atrasados'][0]['client_id'] === 6 && $res['atrasados'][0]['cobros_vencidos'] === 2 && $res['atrasados'][0]['saldo_vencido'] === 2500.0
    && $res['atrasados'][0]['mas_antiguo'] === '2026-08-15' && $res['atrasados'][0]['dias_atraso'] === 93
    && $res['atrasados'][1]['client_id'] === 7 && $res['atrasados'][1]['saldo_vencido'] === 800.0 && $res['atrasados'][1]['dias_atraso'] === 16, $res['atrasados']);
wa_chk('el cobro cancelado no aparece en ningun total', $t['pendiente'] === 21000.0 && !in_array($H3['id'], array_column($res['proximos'], 'id'), true));
wa_chk('con una ventana de 30 dias, los proximos incluyen lo del 10 de diciembre (B)', in_array($m->resumen(30)['proximos'][2]['id'] ?? 0, [$B2['id']], true) && count($m->resumen(30)['proximos']) === 3, array_column($m->resumen(30)['proximos'], 'id'));
$vacio = new cobroModel(cobros_base(), $reloj);
$rv = $vacio->resumen();
wa_chk('el dashboard de una empresa sin cobros: todo en cero y las listas vacias', $rv['totales']['pendiente'] === 0.0 && $rv['totales']['cobros_abiertos'] === 0 && $rv['proximos'] === [] && $rv['atrasados'] === []
    && count($rv['antiguedad']) === 5 && array_sum(array_column($rv['antiguedad'], 'cobros')) === 0);

echo "\n== Parte 2: recordatorios ==\n";
$rec = $m->recordatorios(7, 7);
wa_chk('por recordar (7 dias): los 3 vencidos y los 2 que vencen hoy y el 18; nadie se ha recordado todavia',
    array_column($rec['items'], 'id') === [$F1['id'], $F2['id'], $G1['id'], $H1['id'], $G2['id']]
    && $rec['resumen'] === ['por_recordar' => 5, 'recordados' => 0, 'vencidos' => 3, 'por_vencer' => 2, 'clientes' => 3, 'clientes_por_recordar' => 3, 'clientes_recordados' => 0]
    && !in_array(false, array_column($rec['items'], 'requiere_recordatorio'), true) && $rec['truncado'] === false, $rec['resumen']);
wa_chk('cada item trae el motivo (vencido o por_vencer) y que no tiene recordatorios', $rec['items'][0]['motivo'] === 'vencido' && $rec['items'][3]['motivo'] === 'por_vencer' && $rec['items'][0]['ultimo'] === null && $rec['items'][0]['recordatorios'] === 0);
$cl = array_column($rec['clientes'], null, 'client_id');
wa_chk('los CLIENTES a recordar, el mas atrasado primero: F, G y H', array_column($rec['clientes'], 'client_id') === [6, 7, 8], array_column($rec['clientes'], 'client_id'));
wa_chk('F: 2 vencidos por 2,500 desde el 15 de agosto (93 dias), nada por vencer; el recordatorio se anota en su cobro mas urgente (agosto)',
    $cl[6]['cobros_vencidos'] === 2 && $cl[6]['saldo_vencido'] === 2500.0 && $cl[6]['mas_antiguo'] === '2026-08-15' && $cl[6]['dias_atraso'] === 93
    && $cl[6]['cobros_por_vencer'] === 0 && $cl[6]['saldo_por_vencer'] === 0.0 && $cl[6]['proximo_vencimiento'] === null
    && $cl[6]['cobro_id'] === $F1['id'] && $cl[6]['periodo'] === '2026-08' && $cl[6]['motivo'] === 'vencido' && $cl[6]['cliente']['nombre'] === 'Foxtrot SA'
    && $cl[6]['recordatorios'] === 0 && $cl[6]['ultimo'] === null && $cl[6]['requiere_recordatorio'] === true, $cl[6]);
wa_chk('G: 1 vencido de 800 desde el 31 de octubre (16 dias) y 1 que vence el 18 por 1,200',
    $cl[7]['cobros_vencidos'] === 1 && $cl[7]['saldo_vencido'] === 800.0 && $cl[7]['mas_antiguo'] === '2026-10-31' && $cl[7]['dias_atraso'] === 16
    && $cl[7]['cobros_por_vencer'] === 1 && $cl[7]['saldo_por_vencer'] === 1200.0 && $cl[7]['proximo_vencimiento'] === '2026-11-18' && $cl[7]['cobro_id'] === $G1['id'], $cl[7]);
wa_chk('H: nada vencido y 5,000 que vencen hoy (0 dias de atraso, motivo por_vencer)',
    $cl[8]['cobros_vencidos'] === 0 && $cl[8]['mas_antiguo'] === null && $cl[8]['dias_atraso'] === 0 && $cl[8]['cobros_por_vencer'] === 1
    && $cl[8]['saldo_por_vencer'] === 5000.0 && $cl[8]['proximo_vencimiento'] === '2026-11-16' && $cl[8]['motivo'] === 'por_vencer' && $cl[8]['cobro_id'] === $H1['id'], $cl[8]);
$r = $m->registrarRecordatorio($F1['id'], ['fecha_hora' => '2026-11-16 09:00:00', 'canal' => 'llamada', 'nota' => 'No contesto'], 7, 'edo');
wa_chk('registrar un recordatorio: queda con su canal, nota y quien lo hizo', $exito($r) && $r[1]['recordatorio']['canal'] === 'llamada' && $r[1]['recordatorio']['nota'] === 'No contesto'
    && $r[1]['recordatorio']['usuario'] === 'edo' && $r[1]['cobro']['recordatorios'] === 1 && $r[1]['cobro']['ultimo_recordatorio'] === '2026-11-16 09:00:00', $r);
$fx['recordatorio'] = $r[1] ?? [];
$rec = $m->recordatorios(7, 7);
$fx['recordatorios'] = $rec;
$cl = array_column($rec['clientes'], null, 'client_id');
wa_chk('una llamada a F cubre TODO lo que debe: aunque se anoto en un solo cobro, los dos de F dejan de "requerir" y bajan al final; 3 por recordar y 2 recordados (cobros), 2 y 1 (clientes)',
    $rec['resumen']['por_recordar'] === 3 && $rec['resumen']['recordados'] === 2 && $rec['resumen']['clientes_por_recordar'] === 2 && $rec['resumen']['clientes_recordados'] === 1
    && array_column(array_slice($rec['items'], -2), 'id') === [$F1['id'], $F2['id']]
    && !in_array(true, array_column(array_slice($rec['items'], -2), 'requiere_recordatorio'), true), $rec['resumen']);
wa_chk('el cliente F queda "recordado" (1 recordatorio: la llamada) y baja al final; G y H siguen por recordar',
    array_column($rec['clientes'], 'client_id') === [7, 8, 6] && $cl[6]['requiere_recordatorio'] === false && $cl[6]['recordatorios'] === 1
    && $cl[6]['ultimo']['canal'] === 'llamada' && $cl[6]['ultimo']['cobro_id'] === $F1['id'] && $cl[7]['requiere_recordatorio'] === true && $cl[8]['requiere_recordatorio'] === true, $rec['clientes']);
$itemF1 = array_values(array_filter($rec['items'], fn(array $c): bool => $c['id'] === $F1['id']))[0];
$itemF2 = array_values(array_filter($rec['items'], fn(array $c): bool => $c['id'] === $F2['id']))[0];
wa_chk('F1 tiene su recordatorio propio; F2 no tiene propios pero su "ultimo" es el del cliente (el de F1)',
    $itemF1['recordatorios'] === 1 && $itemF1['ultimo_recordatorio'] === '2026-11-16 09:00:00' && $itemF2['recordatorios'] === 0 && $itemF2['ultimo_recordatorio'] === null
    && $itemF2['ultimo']['canal'] === 'llamada' && $itemF2['ultimo']['cobro_id'] === $F1['id'], [$itemF1, $itemF2]);
$m->registrarRecordatorio($G2['id'], ['fecha_hora' => '2026-11-16 09:30:00', 'canal' => 'mensaje', 'nota' => null], 7, 'edo');
$esDe = static fn(array $rv, int $id): array => array_values(array_filter($rv['items'], fn(array $c): bool => $c['id'] === $id))[0];
$clDe = static fn(array $rv, int $cid): array => array_values(array_filter($rv['clientes'], fn(array $c): bool => $c['client_id'] === $cid))[0];
$rv1 = $otroReloj('2026-11-19 10:00:00')->recordatorios(7, 7);
wa_chk('a G2 se le aviso el 16 (antes de vencer el 18); el 19 ya esta vencido y vuelve a requerir recordatorio aunque pasaron 3 dias',
    $esDe($rv1, $G2['id'])['estado'] === 'Vencido' && $esDe($rv1, $G2['id'])['requiere_recordatorio'] === true);
wa_chk('...pero G1, que ya estaba vencido cuando se le aviso, no; y el CLIENTE G si (uno de sus cobros lo requiere)',
    $esDe($rv1, $G1['id'])['requiere_recordatorio'] === false && $clDe($rv1, 7)['requiere_recordatorio'] === true);
$rv2 = $otroReloj('2026-11-22 10:00:00')->recordatorios(7, 7);
$rv3 = $otroReloj('2026-11-23 10:00:00')->recordatorios(7, 7);
wa_chk('a F se le recordo el 16: el 22 aun no toca (6 dias); el 23 si (7 dias), y ahi el cliente vuelve a "por recordar"',
    $esDe($rv2, $F1['id'])['requiere_recordatorio'] === false && $clDe($rv2, 6)['requiere_recordatorio'] === false
    && $esDe($rv3, $F1['id'])['requiere_recordatorio'] === true && $clDe($rv3, 6)['requiere_recordatorio'] === true);
wa_chk('no se recuerda un cobro pagado (409 YA_PAGADO) ni uno que no existe (404)', $falla($m->registrarRecordatorio($A1['id'], ['fecha_hora' => $ahora, 'canal' => 'otro', 'nota' => null], 7, 'u'), 409, 'YA_PAGADO')
    && $falla($m->registrarRecordatorio(9999, ['fecha_hora' => $ahora, 'canal' => 'otro', 'nota' => null], 7, 'u'), 404));
wa_chk('con un cobro cobrado, sale de la lista de recordatorios; su cliente sigue mientras le quede otro y SIGUE recordado (la llamada se anoto en el cobro que se cobro)', (function () use ($m, $F1, $pago, $clDe): bool {
    $m->registrarPago($F1['id'], $pago(100000), 7);
    $rec = $m->recordatorios(7, 7);
    return !in_array($F1['id'], array_column($rec['items'], 'id'), true) && in_array(6, array_column($rec['clientes'], 'client_id'), true)
        && $clDe($rec, 6)['requiere_recordatorio'] === false && $clDe($rec, 6)['recordatorios'] === 1 && $clDe($rec, 6)['ultimo']['cobro_id'] === $F1['id'];
})());

echo "\n== Parte 2: panel del cliente ==\n";
$p = $m->panelCliente(1);
$fx['panel'] = $p;
$fx['config'] = $m->obtenerConfig(1);
wa_chk('panel de A: sus datos, su configuracion actual y los 6 cobros del mas nuevo al mas viejo', $p['cliente']['nombre'] === 'ACME SRL' && $p['config']['monto_mensual'] === 6000.0 && $p['config']['dia_vencimiento'] === 31
    && count($p['cobros']) === 6 && $p['cobros'][0]['periodo'] === '2027-03' && $p['cobros'][5]['periodo'] === '2026-10', array_column($p['cobros'], 'periodo'));
wa_chk('...cada cobro con sus pagos (el de octubre: 3 pagos, uno anulado a la vista)', count($p['cobros'][5]['pagos']) === 3 && count(array_filter($p['cobros'][5]['pagos'], fn(array $x): bool => $x['anulado'])) === 1, array_map(fn(array $x): string => $x['monto'] . ($x['anulado'] ? ' anulado' : ''), $p['cobros'][5]['pagos']));
wa_chk('resumen de A: pendiente 6,000 (marzo), nada vencido, el proximo vencimiento es el de marzo (los demas ya estan pagados)',
    $p['resumen']['saldo_pendiente'] === 6000.0 && $p['resumen']['saldo_vencido'] === 0.0 && $p['resumen']['cobros_abiertos'] === 1 && $p['resumen']['cobros_vencidos'] === 0
    && $p['resumen']['proximo_vencimiento']['periodo'] === '2027-03' && $p['resumen']['proximo_vencimiento']['fecha_vencimiento'] === '2027-03-31' && $p['resumen']['vencido_desde'] === null, $p['resumen']);
wa_chk('total cobrado de A: 5 cobros pagados = 5,000 + 4 x 6,000 = 29,000; facturado 35,000 (con el de marzo)', $p['resumen']['total_cobrado'] === 29000.0 && $p['resumen']['total_facturado'] === 35000.0, $p['resumen']);
wa_chk('lo que se le sugiere para el proximo cobro de A: abril 2027, 6000 y vence el 30 (el 31 no existe)', $p['sugerido'] === ['periodo' => '2027-04', 'monto' => 6000.0, 'fecha_vencimiento' => '2027-04-30', 'descripcion' => 'Mensualidad'], $p['sugerido']);
$pf = $m->panelCliente(7);
$fx['panel-sin-config'] = $pf;
wa_chk('panel de G (sin config): config null, 1 vencido de 800 desde el 31 de octubre, proximo vencimiento el 18, y el recordatorio visible',
    $pf['config'] === null && $pf['resumen']['saldo_vencido'] === 800.0 && $pf['resumen']['vencido_desde'] === '2026-10-31' && $pf['resumen']['proximo_vencimiento']['fecha_vencimiento'] === '2026-11-18'
    && count($pf['recordatorios']) === 1 && $pf['recordatorios'][0]['canal'] === 'mensaje' && $pf['recordatorios'][0]['periodo'] === '2026-11', $pf);
wa_chk('sugerido sin configuracion: solo el periodo (diciembre: el que sigue a su ultimo cobro, noviembre) y nada mas',
    $pf['sugerido']['periodo'] === '2026-12' && $pf['sugerido']['monto'] === null && $pf['sugerido']['fecha_vencimiento'] === null);
wa_chk('panel de un cliente sin cobros ni config: vacio, con el mes de hoy como periodo; uno que no existe: null',
    ($pv = $m->panelCliente(3)) && count($pv['cobros']) === 1 && $m->panelCliente(999) === null);
$r = $m->crear($nuevo(4), 7);
wa_chk('crear con SOLO el cliente (D tiene config): usa el periodo, monto y dia sugeridos (febrero 2027, 2000, el 15)', $exito($r) && $r[1]['periodo'] === '2027-02' && $r[1]['monto'] === 2000.0 && $r[1]['fecha_vencimiento'] === '2027-02-15', $r);

$fx['lista'] = ['data' => $m->listar(CobroReglas::validarFiltros([])[1], 0, 100), 'pagination' => ['page' => 1, 'pageSize' => 100, 'total' => $m->contar(CobroReglas::validarFiltros([])[1]), 'totalPages' => 1]];
$carpetaFixtures = wa_arg('fixtures');
if ($carpetaFixtures !== null && $carpetaFixtures !== '') {
    if (!is_dir($carpetaFixtures)) {
        mkdir($carpetaFixtures, 0777, true);
    }
    foreach ($fx as $nombre => $datos) {
        // El sobre que manda cobroController: {status, data} (la lista trae ademas su paginacion).
        $sobre = $nombre === 'lista' ? ['status' => true, 'data' => $datos['data'], 'pagination' => $datos['pagination']] : ['status' => true, 'data' => $datos];
        file_put_contents($carpetaFixtures . '/' . $nombre . '.json', json_encode($sobre, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    }
    echo "\n(fixtures escritos en {$carpetaFixtures}: " . implode(', ', array_keys($fx)) . ")\n";
}

echo "\n== Parte 2: la base queda consistente despues de todo ==\n";
wa_chk('invariantes finales: saldos = suma de pagos vigentes, sin sobrepagos, sin cancelados con pagos, sin duplicados', cobros_invariantes($pdo) === [], cobros_invariantes($pdo));
wa_chk('ningun pago se borro: los anulados siguen en la tabla', $cuenta('SELECT COUNT(*) FROM cobro_pagos WHERE anulado_at IS NOT NULL') === 3);
wa_chk('ningun cobro se borro: el cancelado sigue en la tabla', $cuenta('SELECT COUNT(*) FROM cobros WHERE cancelado_at IS NOT NULL') === 1);

echo "\n== Parte 2: errores de la base ==\n";
$sinTablas = new PDO('sqlite::memory:');
$mal = new cobroModel($sinTablas, $reloj);
$r = $mal->crear($nuevo(1, '2026-11', '2026-11-30', 100), 7);
wa_chk('una escritura con la base rota responde un 500 con texto neutro (sin el SQL ni nombres de tablas)', $falla($r, 500) && $r[1] === cobroModel::MSG_GUARDAR && !str_contains($r[1], 'cobros') && !str_contains($r[1], 'SQL'), $r);
wa_chk('...y no deja una transaccion abierta', $sinTablas->inTransaction() === false);
wa_chk('una lectura con la base rota LANZA (no se disfraza de "sin cobros"): el controller la atrapa', (function () use ($mal): bool {
    try {
        $mal->contar(CobroReglas::validarFiltros([])[1]);
        return false;
    } catch (PDOException $e) {
        return true;
    }
})());

// ===========================================================================
// Parte 2 (bis) — topes por cobro y la lista de recordatorios con mas cobros de los que mira
// ===========================================================================
echo "\n== Parte 2: topes de pagos y de recordatorios por cobro ==\n";
$pdoT = cobros_base();
$pdoT->exec("INSERT INTO clients (email, client_name, company_name, phone_number) VALUES ('', 'Tope', 'Tope SRL', '')");
$mT = new cobroModel($pdoT, $reloj);
$T1 = $mT->crear($nuevo(1, '2026-11', '2026-11-30', 100000000), 7)[1];
$insPago = $pdoT->prepare("INSERT INTO cobro_pagos (cobro_id, monto, fecha_pago, metodo, anulado_at, created_at) VALUES (:c, 0.01, '2026-11-01', 'efectivo', '2026-11-02 10:00:00', '2026-11-01 10:00:00')");
$pdoT->beginTransaction();
for ($i = 0; $i < CobroReglas::MAX_PAGOS - 1; $i++) {
    $insPago->execute([':c' => $T1['id']]);
}
$pdoT->commit();
$r = $mT->registrarPago($T1['id'], $pago(1), 7);
wa_chk('con 499 pagos (los anulados cuentan) todavia cabe el 500: se registra', $exito($r) && $r[1]['cobro']['monto_pagado'] === 0.01, $r);
$r = $mT->registrarPago($T1['id'], $pago(1), 7);
wa_chk('el pago 501 se rechaza: 409 LIMITE_PAGOS (el historial no se borra para hacerle espacio)', $falla($r, 409, 'LIMITE_PAGOS') && str_contains($r[1], '500'), $r);
$insRec = $pdoT->prepare("INSERT INTO cobro_recordatorios (cobro_id, fecha_hora, canal, created_at) VALUES (:c, '2026-11-01 09:00:00', 'llamada', '2026-11-01 09:00:00')");
$pdoT->beginTransaction();
for ($i = 0; $i < CobroReglas::MAX_RECORDATORIOS - 1; $i++) {
    $insRec->execute([':c' => $T1['id']]);
}
$pdoT->commit();
$rr = ['fecha_hora' => '2026-11-16 09:00:00', 'canal' => 'llamada', 'nota' => null];
wa_chk('con 199 recordatorios todavia cabe el 200', $exito($mT->registrarRecordatorio($T1['id'], $rr, 7, 'u')));
$r = $mT->registrarRecordatorio($T1['id'], $rr, 7, 'u');
wa_chk('el recordatorio 201 se rechaza: 409 LIMITE_RECORDATORIOS', $falla($r, 409, 'LIMITE_RECORDATORIOS') && str_contains($r[1], '200'), $r);
wa_chk('los topes no tocan la consistencia (saldo = suma de los pagos vigentes)', cobros_invariantes($pdoT) === [], cobros_invariantes($pdoT));

echo "\n== Parte 2: la lista de recordatorios con mas cobros de los que mira ==\n";
$pdoR = cobros_base();
$pdoR->exec("INSERT INTO clients (email, client_name, company_name, phone_number) VALUES ('', 'Uno', 'Uno SRL', ''), ('', 'Dos', 'Dos SRL', '')");
$insCobro = $pdoR->prepare("INSERT INTO cobros (client_id, periodo, monto, fecha_vencimiento, created_at) VALUES (:c, :p, 10, :f, '2026-01-01 00:00:00')");
$pdoR->beginTransaction();
for ($cid = 1; $cid <= 2; $cid++) {
    for ($anio = 2000; $anio <= 2026; $anio++) {
        for ($mes = 1; $mes <= ($anio === 2026 ? 10 : 12); $mes++) {
            $insCobro->execute([':c' => $cid, ':p' => sprintf('%04d-%02d', $anio, $mes), ':f' => sprintf('%04d-%02d-10', $anio, $mes)]);
        }
    }
}
$pdoR->commit();
$totalR = (int) $pdoR->query('SELECT COUNT(*) FROM cobros')->fetchColumn();
$recR = (new cobroModel($pdoR, $reloj))->recordatorios(7, 7);
wa_chk("con {$totalR} cobros vencidos la lista mira solo los " . CobroReglas::MAX_FILAS_RECORDATORIOS . ' mas atrasados y lo dice (truncado), para que los totales no pasen por completos',
    $totalR > CobroReglas::MAX_FILAS_RECORDATORIOS && $recR['truncado'] === true && count($recR['items']) === CobroReglas::MAX_FILAS_RECORDATORIOS
    && $recR['resumen']['por_recordar'] === CobroReglas::MAX_FILAS_RECORDATORIOS && $recR['resumen']['clientes'] === 2 && $recR['items'][0]['periodo'] === '2000-01',
    [$totalR, $recR['truncado'], count($recR['items']), $recR['resumen']]);

// ===========================================================================
// Parte 3 — clientModel::deleteClient con cobros
// ===========================================================================
echo "\n== Parte 3: no se borra un cliente con cobros ==\n";
$pdo3 = cobros_base();
$pdo3->exec("INSERT INTO clients (email, client_name, company_name, phone_number) VALUES ('', 'Con cobros', 'Con cobros SRL', ''), ('', 'Solo config', 'Solo config SRL', ''), ('', 'Libre', 'Libre SRL', '')");
$m3 = new cobroModel($pdo3, $reloj);
$m3->crear($nuevo(1, '2026-11', '2026-11-30', 1000), 7);
$m3->guardarConfig(2, $cfg(1000, 5), 7);
$clientes = (new ReflectionClass('clientModel'))->newInstanceWithoutConstructor();
$conexion = new ReflectionProperty('clientModel', 'conexion');
$conexion->setValue($clientes, $pdo3);
$r = $clientes->deleteClient(1);
wa_chk('borrar un cliente con cobros: error con un texto claro que explica por que (su historial se conserva), y el cliente sigue',
    ($r[0] ?? '') === 'error' && str_contains($r[1], 'cobros') && str_contains($r[1], 'historial') && $pdo3->query('SELECT COUNT(*) FROM clients WHERE id = 1')->fetchColumn() == 1, $r);
$r = $clientes->deleteClient(2);
wa_chk('borrar un cliente que solo tiene configuracion: se borra y su configuracion cae con el (CASCADE)', ($r[0] ?? '') === 'success'
    && $pdo3->query('SELECT COUNT(*) FROM cobro_config WHERE client_id = 2')->fetchColumn() == 0 && $pdo3->query('SELECT COUNT(*) FROM clients WHERE id = 2')->fetchColumn() == 0, $r);
wa_chk('borrar un cliente sin nada: se borra', ($clientes->deleteClient(3)[0] ?? '') === 'success');

echo "\n== Parte 3: el cableado ==\n";
$raiz = dirname(__DIR__);
$router = (string) file_get_contents($raiz . '/src/Router.php');
wa_chk("el Router tiene el case 'cobros' que carga cobroController.php", preg_match("/case 'cobros':.*?require_once 'src\\/Controllers\\/cobroController\\.php';/s", $router) === 1);
wa_chk('el controlador existe y usa la ruta, las reglas, el modelo, la bitacora y los 5 codigos de error del modelo',
    is_file($raiz . '/src/Controllers/cobroController.php')
    && (static function () use ($raiz): bool {
        $c = (string) file_get_contents($raiz . '/src/Controllers/cobroController.php');
        // Toda anotacion pasa por cobrosAuditar(): es la unica que llama a la bitacora y la que elige el modulo
        // ('cobros' en una empresa, 'cobros-tenants' en la plataforma; el ambito lo prueba test_cobros_tenants.php).
        return str_contains($c, 'CobroReglas::ruta(') && str_contains($c, 'new cobroModel(null, null,')
            && substr_count($c, 'cobrosAuditar([') >= 9 && substr_count($c, 'AuditLogger::log(') === 1
            && str_contains($c, "['module' => 'cobros']");
    })());
wa_chk('el controlador exige el modulo cobros SIEMPRE (no depende de PERMISSIONS_ENFORCE), antes de abrir el modelo, y deja rastro del 403',
    (static function () use ($raiz): bool {
        $c = (string) file_get_contents($raiz . '/src/Controllers/cobroController.php');
        $iModulo = strpos($c, "PermissionGate::permMatches(\$permisos, 'cobros')");
        $iModelo = strpos($c, 'new cobroModel(null, null,');
        return $iModulo !== false && $iModelo !== false && $iModulo < $iModelo
            && str_contains($c, '->getPermissionsForRole(') && str_contains($c, 'AuditMiddleware::logAccessDenied(') && str_contains($c, 'CobroReglas::MSG_SIN_PERMISO, 403')
            && !str_contains($c, 'PermissionGate::enforce(') && !preg_match("/getenv\\('PERMISSIONS_ENFORCE'\\)/", $c);
    })());
$permisos = require $raiz . '/config/permissions.php';
wa_chk("config/permissions.php: la ruta 'cobros' va al modulo 'cobros', que esta en el catalogo y en el rol user", ($permisos['routes']['cobros'] ?? null) === 'cobros'
    && in_array('cobros', $permisos['catalog'], true) && in_array('cobros', $permisos['defaults']['user'], true) && $permisos['defaults']['admin'] === ['*']);

wa_fin();
