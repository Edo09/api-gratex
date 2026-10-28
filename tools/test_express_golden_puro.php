<?php
/**
 * test_express_golden_puro.php — Nucleo puro de la captura dorada de Express (P0):
 * la forma de un JSON sin valores (golden_forma), la union de formas
 * (golden_unir), las diferencias entre dos capturas (golden_diff), un paso
 * registrado (golden_paso), las matrices de rutas por rol (golden_matriz y
 * golden_matriz_escrituras) y el aviso de otra version de PHP (golden_aviso_php).
 * Sin base de datos, sin red, sin arrancar servidores.
 *
 * Uso:
 *   php tools/test_express_golden_puro.php      (sale con 1 si algo falla)
 */

require_once __DIR__ . '/write_ahead_test_lib.php';
require_once __DIR__ . '/express_golden_lib.php';

echo "\n== 1) golden_forma ==\n";
wa_chk('escalares: int y float son number', golden_forma(['a' => 1, 'b' => 1.5, 'c' => 'x', 'd' => true, 'e' => null]) === ['a' => 'number', 'b' => 'number', 'c' => 'string', 'd' => 'bool', 'e' => 'null']);
wa_chk('claves ordenadas', array_keys(golden_forma(['b' => 1, 'a' => 2])) === ['a', 'b']);
wa_chk('volatiles: token, id, fecha y e-NCF distintos dan la misma forma (Review Focus 4)',
    golden_forma(['token' => 'a1', 'id' => 7, 'fecha' => '2026-10-27', 'e_ncf' => 'E310000000001']) === golden_forma(['token' => 'b2c3', 'id' => 912, 'fecha' => '2027-01-01', 'e_ncf' => 'E310000000442']));
wa_chk('un tipo cambiado se nota', golden_forma(['id' => 7]) !== golden_forma(['id' => '7']));
wa_chk('lista: union de las claves de todas las filas', golden_forma([['a' => 1], ['a' => 2, 'b' => 'x']]) === ['__lista__' => ['a' => 'number', 'b' => 'string']]);
wa_chk('lista vacia', golden_forma([]) === ['__lista__' => null]);
echo "\n== 2) golden_unir y golden_diff ==\n";
wa_chk('unir escalares distintos', golden_unir('string', 'null') === 'null|string');
$a = ['http' => 200, 'forma' => ['data' => ['id' => 'number', 'x' => 'string']]];
wa_chk('diff igual: vacio', golden_diff($a, $a) === []);
$d = golden_diff($a, ['http' => 200, 'forma' => ['data' => ['id' => 'string', 'y' => 'bool']]]);
wa_chk('diff: tipo cambiado, clave perdida, clave nueva', $d === ['forma.data.id: "number" -> "string"', 'forma.data.x: falta', 'forma.data.y: sobra'], $d);
wa_chk('diff: un cambio de codigo http se nota', golden_diff($a, ['http' => 403] + $a) === ['http: 200 -> 403']);
// Union: el orden de las filas de una lista cambia entre corridas y no debe cambiar la forma.
wa_chk('unir: mismo resultado en cualquier orden y sin alternativas repetidas',
    golden_unir(golden_unir('string', 'null'), 'number') === 'null|number|string'
    && golden_unir('number', golden_unir('string', 'null')) === 'null|number|string'
    && golden_unir('null|string', 'string') === 'null|string');
wa_chk('unir: objeto contra escalar es mixto, venga en el orden que venga',
    golden_unir(['a' => 'number'], 'string') === 'mixto' && golden_unir('string', ['a' => 'number']) === 'mixto' && golden_unir('mixto', 'string') === 'mixto');
wa_chk('unir: una lista vacia (null) cede ante la forma de otra fila',
    golden_unir(null, ['id' => 'number']) === ['id' => 'number']
    && golden_forma([['items' => []], ['items' => [['id' => 1]]]]) === ['__lista__' => ['items' => ['__lista__' => ['id' => 'number']]]]);
wa_chk('diff: una lista que cambia de tipo da la ruta con __lista__',
    golden_diff(['forma' => ['__lista__' => ['a' => 'number']]], ['forma' => ['__lista__' => ['a' => 'string']]]) === ['forma.__lista__.a: "number" -> "string"']);
echo "\n== 3) golden_paso y golden_matriz ==\n";
$p = golden_paso('login', 'POST', '/api/auth/login', 200, '{"success":true,"data":{"token":"abc"}}');
wa_chk('paso: forma sin valores', $p === ['id' => 'login', 'metodo' => 'POST', 'ruta' => '/api/auth/login', 'http' => 200, 'forma' => ['data' => ['token' => 'string'], 'success' => 'bool']], $p);
wa_chk('paso: cuerpo no JSON y vacio', golden_paso('x', 'GET', '/', 500, '<html>')['forma'] === 'no-json' && golden_paso('x', 'GET', '/', 204, '')['forma'] === 'vacio');
$m = golden_matriz(['clients', 'users'], ['admin', 'user', 'anonimo']);
wa_chk('matriz: rutas por roles', count($m) === 6 && $m[0] === ['metodo' => 'GET', 'ruta' => '/api/clients', 'rol' => 'admin'] && $m[5] === ['metodo' => 'GET', 'ruta' => '/api/users', 'rol' => 'anonimo']);
$e = golden_matriz_escrituras();
wa_chk('escrituras: ninguna sonda lleva datos en el cuerpo (no puede mutar)', $e !== [] && array_reduce($e, static fn (bool $ok, array $r): bool => $ok && in_array($r['cuerpo'], ['', '{}'], true) && in_array($r['metodo'], ['POST', 'PUT', 'DELETE'], true), true));
wa_chk('escrituras: PUT y DELETE de facturas para admin, user y anonimo',
    count(array_filter($e, static fn (array $r): bool => $r['ruta'] === '/api/facturas/5' && in_array($r['metodo'], ['PUT', 'DELETE'], true))) === 6);
wa_chk('escrituras: user y anonimo prueban POST a rangos y el PUT legacy de ncf',
    count(array_filter($e, static fn (array $r): bool => in_array($r['ruta'], ['/api/ncf/rangos', '/api/ncf'], true) && in_array($r['rol'], ['user', 'anonimo'], true))) === 4);
echo "\n== 4) golden_aviso_php ==\n";
wa_chk('mismo PHP: sin aviso', golden_aviso_php('8.5', '8.5') === null);
wa_chk('otro PHP: aviso con las dos versiones (Review Focus 2)', golden_aviso_php('8.5', '8.3') === 'PHP 8.5 en la base, 8.3 ahora');
echo "\n== 5) estado fijo del tenant (la captura no depende de lo que dejo otra suite) ==\n";
// La normalizacion toca una base remota y no se puede correr aqui: se fija su contrato.
wa_chk('normalizadas: clients, products, ncf_sequences y emisor_config, y nada mas',
    GOLDEN_TABLAS_NORMALIZADAS === ['clients', 'products', 'ncf_sequences', 'emisor_config'], GOLDEN_TABLAS_NORMALIZADAS);
wa_chk('normalizadas: nunca el master ni los usuarios, tokens, facturas o la auditoria',
    array_intersect(GOLDEN_TABLAS_NORMALIZADAS, ['users', 'api_tokens', 'tenants', 'roles', 'role_permissions', 'facturas', 'audit_logs']) === []);
$fuente = (string) file_get_contents(__DIR__ . '/express_golden.php');
$cuerpo = static function (string $funcion) use ($fuente): string {
    return preg_match('/^function ' . preg_quote($funcion, '/') . '\(.*?^\}\r?$/ms', $fuente, $m) === 1 ? $m[0] : '';
};
$flujo = $cuerpo('golden_flujo');
$posNormalizar = strpos($flujo, 'golden_normalizar_tenant(');
$posPrimerPaso = strpos($flujo, "\$paso('login_admin'");
wa_chk('golden_flujo normaliza el tenant antes de su primer paso',
    $posNormalizar !== false && $posPrimerPaso !== false && $posNormalizar < $posPrimerPaso);
$norm = $cuerpo('golden_normalizar_tenant');
$pos = [];
foreach (['wa_limpiar(', 'DELETE FROM', '->commit()', 'pos_scratch_preparar('] as $aguja) {
    $pos[$aguja] = strpos($norm, $aguja);
}
wa_chk('normalizar: limpia, vacia las tablas, confirma y SOLO despues vuelve a sembrar (preparar abre otras conexiones)',
    !in_array(false, $pos, true) && array_values($pos) === array_values(array_unique($pos)) && $pos === (static function (array $p): array { asort($p); return $p; })($pos), $pos);
wa_chk('normalizar: borra solo las tablas de la constante, sin nombres de tabla sueltos',
    str_contains($norm, 'GOLDEN_TABLAS_NORMALIZADAS') && preg_match_all('/DELETE FROM (?!\{\$tabla\})/', $norm) === 0);
echo "\n== 6) salidas: 1 es solo \"hay diferencias\" (contrato 4.5) ==\n";
// El arranque de los servidores y el error a media corrida necesitan una base y no corren aqui: se fija su cableado.
// Su comportamiento, con procesos reales, lo prueba tools/test_express_p0_guardas.php.
require_once __DIR__ . '/express_golden.php';
wa_chk('rechazado sale con 2 (el 1 queda para las diferencias)', GOLDEN_SALIDA_RECHAZADO === 2);
$contexto = $cuerpo('golden_contexto');
$llamadas = preg_match_all('/wa_servidor\(([^;]*)\);/s', $contexto, $m) === 2 ? $m[1] : [];
wa_chk('golden_contexto arranca el simulador de la DGII y (en su bucle) los dos API con la salida de rechazo, no con el 1 por defecto',
    count($llamadas) === 2 && count(array_filter($llamadas, static fn (string $a): bool => preg_match('/,\s*GOLDEN_SALIDA_RECHAZADO\s*$/', $a) === 1)) === 2, $llamadas);
$principal = $cuerpo('golden_main');
wa_chk('golden_main corre golden_correr dentro de un try y sale por golden_fallo_inesperado con cualquier Throwable',
    preg_match('/try\s*\{\s*golden_correr\(\);\s*\}\s*catch\s*\(Throwable \$e\)\s*\{\s*golden_fallo_inesperado\(\$e\);\s*\}/', $principal) === 1, $principal);
wa_chk('el punto de entrada de la herramienta es golden_main (no golden_correr a secas, que no atrapa nada)',
    preg_match('/PHP_SAPI === \'cli\'[^\n]*\n\s*golden_main\(\);/', $fuente) === 1);
wa_fin();
