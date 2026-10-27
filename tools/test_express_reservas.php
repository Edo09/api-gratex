<?php
/**
 * test_express_reservas.php — Alarma de las migraciones reservadas para
 * Fiscalpoint Express: el README de db/migrations reserva la tenant 036 y la
 * master 014, y ningun otro archivo puede tomar esos numeros mientras el plan
 * de Express no los cree. Los planes 02 (master 014) y 04 (tenant 036) crean los
 * archivos reservados y esta prueba sigue en verde. Sin base de datos, sin red.
 *
 * Uso:
 *   php tools/test_express_reservas.php      (sale con 1 si algo falla)
 */

require_once __DIR__ . '/write_ahead_test_lib.php';
$raiz = dirname(__DIR__);
$readme = (string) file_get_contents($raiz . '/db/migrations/README.md');
$nombres = static fn (string $dir, string $pre): array => array_map('basename', glob("{$raiz}{$dir}/{$pre}_*.sql") ?: []);
wa_chk('README reserva la tenant 036 para Express', str_contains($readme, '036_facturas_idempotency.sql') && str_contains($readme, 'Fiscalpoint Express'));
wa_chk('README reserva la master 014', str_contains($readme, '014_tenants_edicion.sql'));
wa_chk('README manda el plan ITBIS a master 015 y tenant 037', preg_match('/ITBIS.*master 015.*tenant 037/s', $readme) === 1);
wa_chk('master 014 solo puede ser la de Express', array_diff($nombres('/db/master_migrations', '014'), ['014_tenants_edicion.sql']) === []);
wa_chk('tenant 036 solo puede ser la de Express', array_diff($nombres('/db/migrations', '036'), ['036_facturas_idempotency.sql']) === []);
wa_chk('el plan ITBIS no aterrizo con sus numeros viejos',
    !is_file($raiz . '/db/migrations/036_facturas_aplica_itbis.sql') && !is_file($raiz . '/db/master_migrations/013_tenants_itbis_simples.sql'));
wa_fin();
