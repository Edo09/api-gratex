<?php
/**
 * test_express_p0_spikes_puro.php — pruebas puras (sin red, sin base de datos,
 * sin servidor) de las herramientas de los spikes de la fase P0 de Express.
 * Hoy cubre la sonda del host (S2): quien puede entrar y como se prepara la
 * copia que se sube a mano al hosting. Las secciones de los demas spikes se
 * agregan arriba de wa_fin().
 *
 * Uso:
 *   php tools/test_express_p0_spikes_puro.php
 */
require_once __DIR__ . '/write_ahead_test_lib.php';
require_once __DIR__ . '/express_p0/express_probe.php';
echo "\n== 1) sonda del host (S2) ==\n";
$h = hash('sha256', 'tok');   // Review Focus 5: la sonda olvidada en el host
wa_chk('sin hash configurado nadie entra', !probe_autorizado('', 4102444800, 'tok', 1000));
wa_chk('token correcto y vigente entra', probe_autorizado($h, 2000, 'tok', 1000));
wa_chk('token incorrecto no entra', !probe_autorizado($h, 2000, 'otro', 1000));
wa_chk('vencida no entra aunque el token sea el bueno', !probe_autorizado($h, 999, 'tok', 1000));
wa_chk('plantilla sin vencimiento (0) no entra', !probe_autorizado($h, 0, 'tok', 1000));
$plantilla = (string) file_get_contents(__DIR__ . '/express_p0/express_probe.php');
$copia = probe_preparar($plantilla, 'tok', 2000);
wa_chk('la copia lleva el hash y el vencimiento, nunca el token', str_contains($copia, $h) && str_contains($copia, '2000') && !str_contains($copia, "'tok'"));
wa_chk('la plantilla del repo sigue vacia', str_contains($plantilla, "const PROBE_TOKEN_SHA256 = '';") && str_contains($plantilla, 'const PROBE_VENCE_UNIX = 0;'));
wa_chk('la sonda no esta en public/ (se sube a mano y se borra)', !is_file(dirname(__DIR__) . '/public/express_probe.php'));
wa_fin();
