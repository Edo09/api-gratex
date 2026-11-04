<?php
/**
 * test_zona_horaria.php — La app corre en hora de Republica Dominicana aunque el
 * hosting este en otra zona.
 *
 * El hosting esta en la hora central de EE. UU., con horario de verano; RD es UTC-4
 * siempre. Hasta noviembre de 2026 la app no fijaba zona: la FechaHoraFirma del
 * e-CF, facturas.date y audit_logs.created_at quedaban 1 hora detras de RD en verano
 * y 2 en invierno (E310000000080: firmado a las 10:06:47, recibido por la DGII a las
 * 12:06:48).
 *
 * Sin base de datos ni red:
 *   1. Un php hijo con la zona del hosting (America/Chicago) que carga src/Database.php
 *      o src/MasterDatabase.php (lo que cargan la API, las paginas de public/ y las
 *      herramientas) da la hora de RD, en invierno y en verano. Igual con
 *      tools/write_ahead_test_lib.php, el de las suites con MySQL.
 *   2. ZonaHoraria::aplicarEn() pone la sesion de MySQL en -04:00: el mismo reloj para
 *      NOW(), CURDATE() y DEFAULT CURRENT_TIMESTAMP.
 *
 * Uso:
 *   php tools/test_zona_horaria.php      (sale con 1 si algo falla)
 */

$raiz = dirname(__DIR__);
$fallos = 0;
$total = 0;
$chk = function (string $desc, bool $ok, $obtenido = null) use (&$fallos, &$total) {
    $total++;
    if (!$ok) {
        $fallos++;
    }
    printf("  [%s] %s\n", $ok ? 'OK  ' : 'FALLO', $desc);
    if (!$ok && $obtenido !== null) {
        echo '         obtenido: ' . json_encode($obtenido, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }
};

// ---------------------------------------------------------------------------
// 1. La hora que ve el codigo despues de cargar cada clase de base de datos, en un
//    php hijo con la zona del hosting. Dos instantes: en invierno Chicago va 2 horas
//    detras de RD y en verano 1 (Nueva York coincide con RD en verano, no en invierno).
// ---------------------------------------------------------------------------
$invierno = gmmktime(17, 6, 48, 11, 4, 2026);   // 2026-11-04 17:06:48 UTC
$verano = gmmktime(16, 0, 0, 7, 15, 2026);      // 2026-07-15 16:00:00 UTC

/** Lo que imprime date() para los dos instantes en un php hijo en Chicago, tras cargar $archivo (null = nada). */
$horaTrasCargar = function (?string $archivo) use ($raiz, $invierno, $verano): string {
    $codigo = ($archivo !== null ? 'require ' . var_export($raiz . '/' . $archivo, true) . ';' : '')
        . ' echo date("Y-m-d H:i:s", ' . $invierno . '), "|", date("Y-m-d H:i:s", ' . $verano . ');';
    $proc = proc_open(
        [PHP_BINARY, '-d', 'date.timezone=America/Chicago', '-r', $codigo],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($proc)) {
        return '(no se pudo correr php)';
    }
    $salida = trim((string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    return $salida;
};

// Control: sin cargar nada, el hijo de verdad esta en la zona del hosting.
$hora = $horaTrasCargar(null);
$chk('control: el php hijo esta en la hora de Chicago', $hora === '2026-11-04 11:06:48|2026-07-15 11:00:00', $hora);

// write_ahead_test_lib.php: las suites con MySQL cuentan "hoy" con el mismo reloj que
// la API y las herramientas que arrancan.
foreach (['src/Database.php', 'src/MasterDatabase.php', 'tools/write_ahead_test_lib.php'] as $archivo) {
    $hora = $horaTrasCargar($archivo);
    $chk("$archivo deja PHP en hora de RD", $hora === '2026-11-04 13:06:48|2026-07-15 12:00:00', $hora);
}

// ---------------------------------------------------------------------------
// 2. La sesion de MySQL: lo que aplicarEn() le manda a la conexion. Aqui no hay
//    MySQL; la conexion es un PDO sin abrir que solo anota las sentencias. Que el
//    MySQL del hosting acepte esa sentencia (sin tablas de zonas) se probo en la base
//    de prueba al hacer este cambio.
// ---------------------------------------------------------------------------
require_once $raiz . '/src/ZonaHoraria.php';

$conexion = new class extends PDO {
    /** @var string[] */
    public array $sentencias = [];

    public function __construct()
    {
        // Sin conexion: solo anota lo que le mandan.
    }

    public function exec(string $statement): int|false
    {
        $this->sentencias[] = $statement;
        return 0;
    }
};
ZonaHoraria::aplicarEn($conexion);
$chk('aplicarEn pone la sesion de MySQL en -04:00', $conexion->sentencias === ["SET time_zone = '-04:00'"], $conexion->sentencias);

printf("\n%d de %d pruebas OK\n", $total - $fallos, $total);
exit($fallos > 0 ? 1 : 0);
