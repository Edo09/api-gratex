<?php
/**
 * test_express_baseline_puro.php — Piezas puras de la linea base de Express (P0):
 * el lector del resumen de una suite (baseline_resumen), la comparacion de
 * conteos exactos (baseline_comparar) y la prueba de arbol limpio de git
 * (p0_arbol_limpio), mas los rechazos del CLI que no llegan a correr ninguna
 * suite (--capturar con --suites). Sin base de datos, sin red, sin correr las suites.
 *
 * Uso:
 *   php tools/test_express_baseline_puro.php      (sale con 1 si algo falla)
 */

require_once __DIR__ . '/write_ahead_test_lib.php';
require_once __DIR__ . '/express_p0_lib.php';
require_once __DIR__ . '/express_baseline.php';

// ---------------------------------------------------------------------------
// 1) baseline_resumen
// ---------------------------------------------------------------------------
echo "\n== 1) baseline_resumen ==\n";
foreach ([["x\n12 de 14 pruebas OK\n", 14, 2], ["\n40/40 OK\n", 40, 0], ["TODO OK (48 verificaciones)\n", 48, 0],
          ["\n== TODO OK: 9 comprobaciones ==\n", 9, 0], ["== 3 de 9 FALLARON ==\n", 9, 3], ["2 de 48 FALLARON\n", 48, 2]] as [$s, $t, $f]) {
    $r = baseline_resumen($s);
    wa_chk("resumen total {$t} fallos {$f}", ($r['total'] ?? null) === $t && ($r['fallos'] ?? null) === $f, $r);
}
wa_chk('sin linea de resumen: null (un exit 0 mudo no es verde)', baseline_resumen("PHP Fatal error: x\n") === null); // Review Focus 3
wa_chk('cuenta las secciones SALTADA', (baseline_resumen("  (SALTADA: pdo_sqlite)\n  (SALTADA: otra)\n3/3 OK\n")['saltadas'] ?? 0) === 2);
wa_chk('gana la ultima linea de resumen', (baseline_resumen("5/5 OK\n  detalle\n12 de 14 pruebas OK\n")['total'] ?? 0) === 14);
wa_chk('un "N/M OK" dentro de la descripcion de una prueba no es resumen', baseline_resumen("  [OK  ] caso 3/3 OK\n") === null);

// ---------------------------------------------------------------------------
// 2) baseline_comparar
// ---------------------------------------------------------------------------
echo "\n== 2) baseline_comparar ==\n";
$ok = ['total' => 10, 'fallos' => 0, 'saltadas' => 0, 'pinned' => true, 'estado' => 'OK'];
$base = ['a.php' => $ok, 'b.php' => ['pinned' => false, 'total' => 5, 'saltadas' => 2] + $ok];
$cmp = static fn (array $act, string $php = '8.5'): array => baseline_comparar($base, $act, '8.5', $php);
$con = static fn (string $s, array $c): array => [$s => $c + $base[$s]] + $base;
wa_chk('igual: ok, sin diferencias ni avisos', $cmp($base) === ['ok' => true, 'diferencias' => [], 'avisos' => []]);
wa_chk('fijada que cambia de total: diferencia con la suite', in_array('a.php: total 10 -> 11', $cmp($con('a.php', ['total' => 11]))['diferencias'], true));
wa_chk('no fijada que pierde pruebas: diferencia', in_array('b.php: total 5 -> 4', $cmp($con('b.php', ['total' => 4]))['diferencias'], true));
wa_chk('no fijada con mas saltadas: diferencia', in_array('b.php: saltadas 2 -> 3', $cmp($con('b.php', ['saltadas' => 3]))['diferencias'], true));
$mejor = $cmp($con('b.php', ['total' => 7, 'saltadas' => 0]));
wa_chk('no fijada que sube o salta menos: ok con aviso de recapturar', $mejor['ok'] === true && in_array('b.php: mejoro (recapturar)', $mejor['avisos'], true));
wa_chk('suite que no corrio: diferencia', in_array('b.php: no corrio', $cmp(['a.php' => $ok])['diferencias'], true));
wa_chk('suite con fallos: diferencia', in_array('a.php: fallos 2', $cmp($con('a.php', ['fallos' => 2]))['diferencias'], true));
$otro = $cmp($base, '8.3');
wa_chk('otro PHP: aviso con las dos versiones aunque todo coincida (Review Focus 2)', $otro['ok'] === true && $otro['avisos'] === ['PHP 8.5 en la base, 8.3 ahora']);

// ---------------------------------------------------------------------------
// 3) p0_arbol_limpio
// ---------------------------------------------------------------------------
echo "\n== 3) p0_arbol_limpio (Review Focus 1) ==\n";
wa_chk('vacio: limpio', p0_arbol_limpio('') === true);
wa_chk('modificado o sin seguimiento: sucio', !p0_arbol_limpio(" M src/PermissionGate.php\n") && !p0_arbol_limpio("?? tools/x.php\n"));
wa_chk('el prefijo ignorado no ensucia', p0_arbol_limpio("?? tools/express_golden/golden.json\n M tools/express_golden/baseline_suites.json\n", ['tools/express_golden/']));
wa_chk('el prefijo ignorado vale tambien en la PRIMERA linea, que empieza con espacio', p0_arbol_limpio(" M tools/express_golden/baseline_suites.json\n", ['tools/express_golden/']));
wa_chk('un renombre hacia fuera del prefijo ensucia', !p0_arbol_limpio("R  tools/express_golden/a.json -> src/a.json\n", ['tools/express_golden/']));

// ---------------------------------------------------------------------------
// 4) CLI: --capturar no admite --suites (capturar siempre escribe la base completa)
// ---------------------------------------------------------------------------
echo "\n== 4) CLI de express_baseline.php: rechazos que no corren ninguna suite ==\n";
$cli = static fn (string ...$args): array => wa_correr(array_merge([PHP_BINARY], wa_php_flags(), [__DIR__ . '/express_baseline.php'], $args));
$archivoBase = dirname(__DIR__) . '/' . BASELINE_ARCHIVO;
$huella = static fn (): ?string => is_file($archivoBase) ? md5_file($archivoBase) : null;
$antes = $huella();
$r = $cli('--capturar', '--suites=test_integracion_xml');
wa_chk('--capturar con --suites: rechazado con 2 y sin correr nada', $r['codigo'] === 2 && str_contains($r['salida'], '--suites solo sirve con --comparar') && !str_contains($r['salida'], 'total'), $r);
$r = $cli('--capturar', '--suites');
wa_chk('--capturar con --suites vacio tambien se rechaza', $r['codigo'] === 2 && str_contains($r['salida'], '--suites solo sirve con --comparar'), $r);
wa_chk('el rechazo no toca la base guardada', $huella() === $antes);
$r = $cli('--comparar', '--suites=no_existe');
wa_chk('--comparar con una suite desconocida: 2', $r['codigo'] === 2 && str_contains($r['salida'], 'Suite desconocida'), $r);
wa_fin();
