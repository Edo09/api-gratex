<?php
/**
 * express_p0_lib.php — Ayudas comunes de las herramientas de la fase P0 de
 * Express (linea base de suites, golden de rutas, espigas). Por ahora: leer el
 * estado de git sin shell y decidir si el arbol esta limpio.
 *
 * Incluirlo solo define funciones: no corre nada, no abre conexiones, no
 * arranca servidores.
 *
 * Uso:
 *   require_once __DIR__ . '/express_p0_lib.php';
 *   $limpio = p0_arbol_limpio(p0_git(['status', '--porcelain'], $raiz), ['tools/express_golden/']);
 */

/**
 * Stdout de "git <args>" corrido en $cwd con proc_open sin shell (los argumentos
 * no pasan por ningun interprete). Solo se recorta a la DERECHA: la primera
 * columna de "git status --porcelain" puede ser un espacio, asi que nunca se
 * usa trim ni ltrim. '' si git no arranca o sale con codigo distinto de 0.
 */
function p0_git(array $args, string $cwd): string
{
    $proc = @proc_open(
        array_merge(['git'], array_map('strval', $args)),
        // stderr al dispositivo nulo: asi ningun pipe se llena mientras se lee stdout.
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w']],
        $pipes,
        $cwd
    );
    if (!is_resource($proc)) {
        return '';
    }
    fclose($pipes[0]);
    $salida = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    return proc_close($proc) === 0 ? rtrim($salida) : '';
}

/**
 * true si el texto de "git status --porcelain" no tiene ninguna linea fuera de
 * los prefijos de ruta ignorados. Cada linea es "XY ruta": la ruta empieza en
 * el 4o caracter (el 1o puede ser un espacio, por eso no se recorta por la
 * izquierda). En un renombre o copia "A -> B" los dos lados deben estar bajo
 * un prefijo ignorado.
 *
 * @param list<string> $ignorarPrefijos rutas relativas a la raiz, p.ej. 'tools/express_golden/'
 */
function p0_arbol_limpio(string $porcelain, array $ignorarPrefijos = []): bool
{
    foreach (preg_split('/\R/', $porcelain) ?: [] as $linea) {
        if (trim($linea) === '') {
            continue;
        }
        $ruta = substr($linea, 3);
        $estado = substr($linea, 0, 2);
        $rutas = (strpbrk($estado, 'RC') !== false && str_contains($ruta, ' -> '))
            ? explode(' -> ', $ruta, 2)
            : [$ruta];
        foreach ($rutas as $r) {
            $r = trim($r, '"'); // git entrecomilla las rutas con espacios o caracteres raros
            $ignorada = false;
            foreach ($ignorarPrefijos as $prefijo) {
                if ($prefijo !== '' && str_starts_with($r, $prefijo)) {
                    $ignorada = true;
                    break;
                }
            }
            if (!$ignorada) {
                return false;
            }
        }
    }
    return true;
}
