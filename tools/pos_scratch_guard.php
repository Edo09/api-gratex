<?php
/**
 * pos_scratch_guard.php — guarda de las suites del POS (tools/test_pos_*.php), que borran y
 * crean datos a proposito. Corren solo si el MySQL es local (127.0.0.1, localhost, ::1) o si
 * TODA base que tocan tiene la tabla marca zz_write_ahead_scratch y un nombre que termina en
 * wa_scratch o wa_scratch_sin_gate. Autonoma (la usan las suites) y sin conexiones al incluirla.
 */

const POS_SCRATCH_MARCA = 'zz_write_ahead_scratch';
const POS_SCRATCH_PATRON_BASE = '/(^|_)wa_scratch(_sin_gate)?$/';

function pos_scratch_es_loopback(string $host): bool
{
    return in_array(strtolower(trim($host)), ['127.0.0.1', 'localhost', '::1', '[::1]'], true);
}

/**
 * Pura. @param array<string,bool> $bases nombre real de cada base => si tiene la tabla marca
 * @return array{ok: bool, motivo: string}
 */
function pos_scratch_decidir(string $host, array $bases): array
{
    if (pos_scratch_es_loopback($host)) {
        return ['ok' => true, 'motivo' => 'servidor local'];
    }
    if ($bases === []) {
        return ['ok' => false, 'motivo' => 'no hay ninguna base que comprobar'];
    }
    $rechazadas = [];
    foreach ($bases as $nombre => $tieneMarca) {
        if (preg_match(POS_SCRATCH_PATRON_BASE, (string) $nombre) !== 1) {
            $rechazadas[] = $nombre . ' (su nombre no termina en wa_scratch ni en wa_scratch_sin_gate)';
        } elseif ($tieneMarca !== true) {
            $rechazadas[] = $nombre . ' (no tiene la tabla ' . POS_SCRATCH_MARCA . ')';
        }
    }
    return $rechazadas === []
        ? ['ok' => true, 'motivo' => 'bases de prueba marcadas']
        : ['ok' => false, 'motivo' => 'servidor remoto y ' . implode('; ', $rechazadas)];
}

/** Corta con codigo 2 si las bases $nombres (nombres reales) no pasan la guarda. Sin credenciales ni host. */
function pos_scratch_exigir(PDO $pdo, string $host, array $nombres): void
{
    $bases = [];
    foreach (array_values(array_unique(array_map('strval', $nombres))) as $nombre) {
        $st = $pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = :b AND TABLE_NAME = :t');
        $st->execute([':b' => $nombre, ':t' => POS_SCRATCH_MARCA]);
        $bases[$nombre] = $st->fetchColumn() !== false;
    }
    $d = pos_scratch_decidir($host, $bases);
    if (!$d['ok']) {
        fwrite(STDERR, '[FALLO] las pruebas del POS borran y crean datos: ' . $d['motivo'] . ". Solo corren sobre bases wa_scratch / wa_scratch_sin_gate marcadas o sobre un MySQL local.\n");
        exit(2);
    }
}
