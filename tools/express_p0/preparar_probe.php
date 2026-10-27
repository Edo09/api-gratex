<?php
/**
 * preparar_probe.php — arma la copia de la sonda del host (spike S2 de Express)
 * lista para subir a mano al hosting. Toma la plantilla express_probe.php, le
 * pone el hash del token y la hora de vencimiento, y la escribe en
 * sys_get_temp_dir()/express_probe.php (nunca dentro del repo).
 *
 * El token se imprime UNA sola vez, en esta terminal: no se guarda en ningun
 * archivo (la copia lleva solo su sha256). Solo para la terminal del dueno.
 * La copia caduca sola a las --horas (3 por defecto, de 1 a 24); aun asi se
 * BORRA del hosting al terminar el spike.
 *
 * Incluirlo solo define funciones: el CLI corre unicamente cuando este es el
 * archivo que se ejecuta.
 *
 * Uso:
 *   php tools/express_p0/preparar_probe.php [--horas=3]
 */
require_once __DIR__ . '/express_probe.php';

/** Lee --horas=N de los argumentos (sin el nombre del script); null si no es un entero de 1 a 24. */
function probe_horas(array $args): ?int
{
    $horas = 3;
    foreach ($args as $a) {
        if (preg_match('/^--horas=(\d+)$/', (string) $a, $m) !== 1) {
            return null;
        }
        $horas = (int) $m[1];
    }
    return ($horas >= 1 && $horas <= 24) ? $horas : null;
}

/** Corre el CLI: escribe la copia, imprime ruta, vencimiento y token. Devuelve el codigo de salida. */
function probe_preparar_cli(array $args): int
{
    $horas = probe_horas($args);
    if ($horas === null) {
        echo "Uso: php tools/express_p0/preparar_probe.php [--horas=3]   (horas: entero de 1 a 24)\n";
        return 2;
    }
    $plantilla = (string) file_get_contents(__DIR__ . '/express_probe.php');
    $vence = time() + $horas * 3600;
    $token = bin2hex(random_bytes(16));
    $destino = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'express_probe.php';
    if (@file_put_contents($destino, probe_preparar($plantilla, $token, $vence)) === false) {
        echo "No pude escribir {$destino}.\n";
        return 1;
    }
    echo "Copia lista para subir a mano: {$destino}\n";
    echo 'Vence: ' . date('c', $vence) . " (unix {$vence}, en {$horas} h)\n";
    echo "Token (se muestra UNA sola vez; copialo ahora, no queda guardado en ningun archivo):\n";
    echo $token . "\n";
    echo "Sube la copia a public/ del hosting como express_probe.php y BORRALA al terminar el spike.\n";
    return 0;
}

// CLI propio: solo cuando se ejecuta este archivo, nunca al incluirlo.
if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(probe_preparar_cli(array_slice($_SERVER['argv'] ?? [], 1)));
}
