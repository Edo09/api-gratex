<?php
/**
 * test_send_fase2_espera.php — La fase 2 espera a la DGII antes de las notas,
 * sin red: la consulta de estado y el sleep son falsos.
 *
 * Origen: set de pruebas de CAGLIARI GROUP SRL (2026-10-06). E410000000010 se
 * rechazo a las 9:00:54 (eso reinicia el set) y las tres notas, mandadas
 * segundos despues, cayeron con "El eNCF modificado no ha sido emitido"
 * (codigo 614) aunque sus originales estaban aceptados. Ahora
 * esperarDgiiAntesDeNotas() consulta todo lo enviado y no deja salir las
 * notas si la DGII rechazo algo o sigue procesando.
 *
 * Uso:
 *   php tools/test_send_fase2_espera.php      (sale con 1 si algo falla)
 */

require_once __DIR__ . '/send_fase2.php';

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

/** Respuesta de GET /integracion/estado como la arma integracionConsultaController. */
$estadoIntegracion = static function (string $estado, array $mensajes = []): array {
    return ['http_status' => 200, 'body' => [
        'status' => true, 'recurso' => 'estado', 'estado' => $estado,
        'consulta' => ['codigo' => '2', 'estado' => $estado, 'mensajes' => $mensajes],
    ]];
};

/**
 * Consulta falsa: cada e-NCF devuelve, ronda a ronda, los estados de su guion
 * (el ultimo se repite). Anota cada llamada para revisar que se pidio.
 */
$guion = static function (array $porEncf) use (&$llamadas): callable {
    $llamadas = [];
    $ronda = [];
    return static function ($api, $key, $secret, string $encf, array $s) use ($porEncf, &$llamadas, &$ronda) {
        $llamadas[] = ['e_ncf' => $encf, 'track_id' => $s['track_id'] ?? null, 'codigo_seguridad' => $s['codigo_seguridad'] ?? null, 'rnc' => $s['rnc'] ?? null];
        $n = $ronda[$encf] = ($ronda[$encf] ?? -1) + 1;
        $pasos = $porEncf[$encf] ?? [['http_status' => 404, 'body' => ['status' => false, 'error' => 'No hay respaldo']]];
        return $pasos[min($n, count($pasos) - 1)];
    };
};

$dormidas = 0;
$dormir = static function (int $s) use (&$dormidas) {
    $dormidas++;
};

/** Resultado de un envio OK como lo arma main(). */
$enviado = static function (string $encf, string $estado = 'EN_PROCESO', ?string $track = 'trk-', ?string $codigo = null): array {
    return ['caso' => '131599729' . $encf, 'tipo_ecf' => substr($encf, 1, 2), 'e_ncf' => $encf, 'ok' => true,
        'track_id' => $track === null ? null : $track . $encf, 'codigo_seguridad' => $codigo, 'estado_dgii' => $estado];
};
$nota = static fn(string $encf, string $ref): array => ['ENCF' => $encf, 'TipoeCF' => substr($encf, 1, 2), 'NCFModificado' => $ref];

$notasSet = [
    $nota('E330000000001', 'E320000000006'),
    $nota('E340000000002', 'E310000000034'),
    $nota('E340000000013', 'E440000000013'),
];

echo "Todo termina aceptado: las notas salen\n";
$results = [
    $enviado('E320000000006'), $enviado('E310000000034'), $enviado('E440000000013'),
    $enviado('E320000000012', 'RFCE_ACEPTADO', null, 'r67uSx'),
];
$consulta = $guion([
    'E320000000006' => [$estadoIntegracion('EN_PROCESO'), $estadoIntegracion('ACEPTADO')],
    'E310000000034' => [$estadoIntegracion('ACEPTADO')],
    'E440000000013' => [$estadoIntegracion('EN_PROCESO'), $estadoIntegracion('EN_PROCESO'), $estadoIntegracion('ACEPTADO_CONDICIONAL')],
]);
$dormidas = 0;
$r = esperarDgiiAntesDeNotas('https://x/api', 'k', 's', $results, $notasSet, 300, 10, $consulta, $dormir);
$chk('sin motivo general para frenar las notas', $r['general'] === null, $r);
$chk('ningun rechazo', $r['rechazos'] === 0, $r);
$chk('estado_dgii_final queda en los resultados', array_column($results, 'estado_dgii_final') === ['ACEPTADO', 'ACEPTADO', 'ACEPTADO_CONDICIONAL', 'RFCE_ACEPTADO'], array_column($results, 'estado_dgii_final'));
$chk('el RFCE ya aceptado en el envio no se consulta', !in_array('E320000000012', array_column($llamadas, 'e_ncf'), true), $llamadas);
$chk('consulta con el track_id del envio', $llamadas[0]['track_id'] === 'trk-E320000000006', $llamadas[0]);
$chk('solo vuelve a consultar lo pendiente (3 + 2 + 1 llamadas)', count($llamadas) === 6, count($llamadas));
$chk('duerme entre rondas (2 veces)', $dormidas === 2, $dormidas);

echo "\nLa DGII rechazo algo (el caso E41 de CAGLIARI): ninguna nota sale\n";
$results = [$enviado('E320000000006'), $enviado('E310000000034'), $enviado('E440000000013'), $enviado('E410000000010')];
$consulta = $guion([
    'E320000000006' => [$estadoIntegracion('ACEPTADO')],
    'E310000000034' => [$estadoIntegracion('ACEPTADO')],
    'E440000000013' => [$estadoIntegracion('EN_PROCESO')],
    'E410000000010' => [$estadoIntegracion('Rechazado', [['valor' => 'La propiedad DescuentoMonto no es valida debido a que el valor enviado (385) no coincide con el valor (385.00) del conjunto de datos entregados.', 'codigo' => 0]])],
]);
$dormidas = 0;
$r = esperarDgiiAntesDeNotas('https://x/api', 'k', 's', $results, $notasSet, 300, 10, $consulta, $dormir);
$chk('motivo general: rechazo de E410000000010', $r['general'] !== null && str_contains($r['general'], 'E410000000010'), $r);
$chk('cuenta 1 rechazo', $r['rechazos'] === 1, $r);
$chk('no espera mas rondas despues del rechazo', $dormidas === 0, $dormidas);
$chk('el mensaje de la DGII queda en el resultado', str_contains((string) ($results[3]['dgii_mensajes'] ?? ''), 'DescuentoMonto') && str_contains((string) ($results[3]['dgii_mensajes'] ?? ''), '(0)'), $results[3]);
$chk('el resumen marca el rechazo con "-"', f2ClaseEstado((string) $results[3]['estado_dgii_final']) === 'rechazado', $results[3]['estado_dgii_final'] ?? null);

echo "\nSe agota el tiempo con algo en proceso: las notas salen con aviso (Gratex paso sin esperar)\n";
$results = [$enviado('E310000000034')];
$consulta = $guion(['E310000000034' => [$estadoIntegracion('EN_PROCESO')]]);
$r = esperarDgiiAntesDeNotas('https://x/api', 'k', 's', $results, [$notasSet[1]], 0, 10, $consulta, $dormir);
$chk('sin motivo general: las notas salen', $r['general'] === null, $r);
$chk('no lo cuenta como rechazo', $r['rechazos'] === 0, $r);
$chk('deja el estado en proceso en el resultado', ($results[0]['estado_dgii_final'] ?? null) === 'EN_PROCESO', $results[0]);

echo "\nApp: la DGII rechazo en el acto (HTTP 422): ninguna nota sale y no se consulta nada\n";
$results = [
    $enviado('E310000000034') + ['factura_id' => 11],
    ['caso' => 'x', 'tipo_ecf' => '41', 'e_ncf' => 'E410000000010', 'ok' => false, 'http_status' => 422,
        'error' => 'La DGII rechazo el comprobante: DescuentoMonto', 'response' => ['status' => false, 'data' => ['estado_dgii' => 'RECHAZADO']]],
];
$consulta = $guion([]);
$r = esperarDgiiAntesDeNotas('https://x/api', 'k', '', $results, [$notasSet[1]], 300, 10, $consulta, $dormir);
$chk('motivo general: rechazo de E410000000010', $r['general'] !== null && str_contains($r['general'], 'E410000000010'), $r);
$chk('cuenta 1 rechazo', $r['rechazos'] === 1, $r);
$chk('no gasta consultas a la DGII', $llamadas === [], $llamadas);
$chk('el motivo queda en el resultado', str_contains((string) ($results[1]['dgii_mensajes'] ?? ''), 'DescuentoMonto'), $results[1]);

echo "\nIntegracion: la recepcion ya dijo RECHAZADO en el envio\n";
$rech = $enviado('E410000000010', 'RECHAZADO');
$rech['response'] = ['status' => true, 'data' => ['dgii_response' => ['mensajes' => [['valor' => 'DescuentoMonto (385) vs (385.00)', 'codigo' => 0]]]]];
$results = [$enviado('E310000000034'), $rech];
$consulta = $guion([]);
$r = esperarDgiiAntesDeNotas('https://x/api', 'k', 's', $results, [$notasSet[1]], 300, 10, $consulta, $dormir);
$chk('motivo general y 1 rechazo', $r['general'] !== null && $r['rechazos'] === 1, $r);
$chk('no consulta nada', $llamadas === [], $llamadas);
$chk('mensaje del envio en el resultado', str_contains((string) ($results[1]['dgii_mensajes'] ?? ''), '(385.00)'), $results[1]);

echo "\nIntegracion: un envio en ERROR sin track_id no se consulta por la ruta RFCE\n";
$results = [$enviado('E310000000034', 'ERROR', null, 'RHq/pJ'), $enviado('E320000000006')];
$consulta = $guion(['E320000000006' => [$estadoIntegracion('ACEPTADO')]]);
$r = esperarDgiiAntesDeNotas('https://x/api', 'k', 's', $results, [$notasSet[0], $notasSet[1]], 300, 10, $consulta, $dormir);
$chk('no lo consulta', !in_array('E310000000034', array_column($llamadas, 'e_ncf'), true), $llamadas);
$chk('frena solo la nota de ese original', str_contains($r['originales']['E310000000034'] ?? '', 'no recibio') && $r['general'] === null, $r);
$chk('la otra nota puede salir', !isset($r['originales']['E320000000006']), $r['originales']);

echo "\nEl original fallo en NUESTRO API (nunca llego a la DGII): solo su nota se frena\n";
$results = [
    ['caso' => 'x', 'tipo_ecf' => '31', 'e_ncf' => 'E310000000034', 'ok' => false, 'http_status' => 422,
        'error' => 'emisor.rnc (1) no corresponde a la credencial usada', 'response' => ['status' => false, 'error' => 'x']],
    $enviado('E320000000006'), $enviado('E440000000013'),
];
$consulta = $guion(['E320000000006' => [$estadoIntegracion('ACEPTADO')], 'E440000000013' => [$estadoIntegracion('ACEPTADO')]]);
$r = esperarDgiiAntesDeNotas('https://x/api', 'k', 's', $results, $notasSet, 300, 10, $consulta, $dormir);
$chk('sin motivo general', $r['general'] === null, $r);
$chk('la nota de E310000000034 queda frenada con el error del envio', str_contains($r['originales']['E310000000034'] ?? '', 'credencial'), $r['originales']);
$chk('las otras dos notas pueden salir', !isset($r['originales']['E320000000006']) && !isset($r['originales']['E440000000013']), $r['originales']);

echo "\nEl envio fallo por red (HTTP 0) o 5xx: no se sabe si llego a la DGII, las notas no salen\n";
foreach ([0 => 'curl: Operation timed out', 502 => 'Fallo emitiendo e-CF: DGII no responde'] as $http => $err) {
    $results = [
        ['caso' => 'x', 'tipo_ecf' => '41', 'e_ncf' => 'E410000000010', 'ok' => false, 'http_status' => $http,
            'error' => $err, 'response' => ['status' => false, 'error' => $err]],
        $enviado('E310000000034'),
    ];
    $consulta = $guion([]);
    $r = esperarDgiiAntesDeNotas('https://x/api', 'k', 's', $results, [$notasSet[1]], 300, 10, $consulta, $dormir);
    $chk("HTTP $http: motivo general que nombra E410000000010", $r['general'] !== null && str_contains($r['general'], 'E410000000010') && str_contains($r['general'], "HTTP $http"), $r);
    $chk("HTTP $http: no consulta por e-NCF (daria el track_id de otra corrida)", $llamadas === [], $llamadas);
}

echo "\nLa consulta de estado falla (502): se dice, no se cuenta como 'en proceso' a secas\n";
$results = [$enviado('E310000000034')];
$consulta = $guion(['E310000000034' => [['http_status' => 502, 'body' => ['status' => false, 'error' => 'Fallo consultando el estado a DGII: timeout']]]]);
$r = esperarDgiiAntesDeNotas('https://x/api', 'k', 's', $results, [$notasSet[1]], 0, 10, $consulta, $dormir);
$chk('las notas salen (tiempo agotado) sin contarlo como rechazo', $r['general'] === null && $r['rechazos'] === 0, $r);
$chk('el reporte dice por que no hubo estado (HTTP 502 + motivo)', str_contains((string) ($results[0]['consulta_error'] ?? ''), 'HTTP 502: Fallo consultando'), $results[0]);

echo "\nSolo notas (filtro E33,E34) en integracion: los originales se buscan por e-NCF\n";
$results = [];
$consulta = $guion([
    'E320000000006' => [$estadoIntegracion('ACEPTADO')],
    'E310000000034' => [$estadoIntegracion('ACEPTADO')],
    // E440000000013 sin respaldo -> 404
]);
$r = esperarDgiiAntesDeNotas('https://x/api', 'k', 's', $results, $notasSet, 300, 10, $consulta, $dormir);
$chk('consulta los 3 originales sin track_id', count($llamadas) === 3 && $llamadas[0]['track_id'] === null, $llamadas);
$chk('sin motivo general', $r['general'] === null, $r);
$chk('el original sin respaldo frena solo su nota', str_contains($r['originales']['E440000000013'] ?? '', 'no hay registro'), $r['originales']);

echo "\nApp (sin api_secret): los originales de otra corrida no se pueden consultar\n";
$results = [];
$consulta = $guion([]);
$r = esperarDgiiAntesDeNotas('https://x/api', 'k', '', $results, $notasSet, 300, 10, $consulta, $dormir);
$chk('no consulta nada ni frena', $llamadas === [] && $r['general'] === null && $r['originales'] === [], $r);

echo "\nFila que fallo al mapear el xlsx (nunca se mando): solo frena su nota\n";
$results = [
    ['caso' => 'x', 'tipo_ecf' => '31', 'e_ncf' => 'E310000000034', 'ok' => false, 'error' => 'mapeo: La fila no tiene items.'],
    $enviado('E320000000006'),
];
$consulta = $guion(['E320000000006' => [$estadoIntegracion('ACEPTADO')]]);
$r = esperarDgiiAntesDeNotas('https://x/api', 'k', 's', $results, [$notasSet[0], $notasSet[1]], 300, 10, $consulta, $dormir);
$chk('sin motivo general (no es "no se sabe si llego")', $r['general'] === null, $r);
$chk('frena la nota de E310000000034 con el error de mapeo', str_contains($r['originales']['E310000000034'] ?? '', 'mapeo'), $r['originales']);

echo "\nCorrida parcial (solo notas) en integracion: un rechazo previo de OTRA pieza se detecta\n";
$results = [];
$consulta = $guion([
    'E320000000006' => [$estadoIntegracion('ACEPTADO')],
    'E310000000034' => [$estadoIntegracion('ACEPTADO')],
    'E440000000013' => [$estadoIntegracion('ACEPTADO')],
    'E410000000010' => [$estadoIntegracion('Rechazado', [['valor' => 'DescuentoMonto (385) vs (385.00)', 'codigo' => 0]])],
    'E310000000004' => [$estadoIntegracion('ACEPTADO')],
    'E320000000012' => [['http_status' => 404, 'body' => ['status' => false, 'error' => 'El respaldo de ese e-NCF no tiene track_id (tipico de RFCE E32 <250k): manda codigo_seguridad.']]],
]);
$base = ['E320000000006', 'E310000000034', 'E440000000013', 'E410000000010', 'E310000000004', 'E320000000012'];
$r = esperarDgiiAntesDeNotas('https://x/api', 'k', 's', $results, $notasSet, 300, 10, $consulta, $dormir, $base);
$chk('consulta todo el set, no solo los originales', count(array_unique(array_column($llamadas, 'e_ncf'))) === 6, array_column($llamadas, 'e_ncf'));
$chk('el rechazo de E410000000010 frena las notas', $r['general'] !== null && str_contains($r['general'], 'E410000000010') && $r['rechazos'] === 1, $r);
$chk('el RFCE sin track_id no frena nada (no verificable)', !isset($r['originales']['E320000000012']), $r['originales']);

echo "\nRFCE: ERROR en el envio = no recibido (no se consulta hasta agotar el tiempo)\n";
$results = [$enviado('E320000000012', 'RFCE_ERROR', null, 'r67uSx'), $enviado('E310000000034')];
$consulta = $guion(['E310000000034' => [$estadoIntegracion('ACEPTADO')]]);
$r = esperarDgiiAntesDeNotas('https://x/api', 'k', 's', $results, [$notasSet[1]], 300, 10, $consulta, $dormir);
$chk('no consulta el RFCE en ERROR', !in_array('E320000000012', array_column($llamadas, 'e_ncf'), true), $llamadas);
$chk('queda marcado no_recibido y el resumen lo cuenta como fallo', !empty($results[0]['no_recibido']) && f2FilaFallo($results[0]), $results[0]);

echo "\nRFCE rechazado en el envio: se pide el motivo a la DGII una vez\n";
$results = [$enviado('E320000000012', 'RFCE_RECHAZADO', null, 'r67uSx')];
$consulta = $guion(['E320000000012' => [$estadoIntegracion('RFCE_RECHAZADO', [['valor' => 'MontoTotal no coincide', 'codigo' => 0]])]]);
$r = esperarDgiiAntesDeNotas('https://x/api', 'k', 's', $results, [$notasSet[1]], 300, 10, $consulta, $dormir);
$chk('una sola consulta, por codigo_seguridad', count($llamadas) === 1 && $llamadas[0]['codigo_seguridad'] === 'r67uSx', $llamadas);
$chk('el motivo queda en el resultado', str_contains((string) ($results[0]['dgii_mensajes'] ?? ''), 'MontoTotal no coincide'), $results[0]);

echo "\nCredencial de grupo: cada consulta va con el RNC de la empresa que emitio\n";
$results = [$enviado('E310000000034') + ['rnc_emisor' => '131111112']];
$consulta = $guion(['E310000000034' => [$estadoIntegracion('ACEPTADO')], 'E440000000013' => [$estadoIntegracion('ACEPTADO')]]);
$notaHermana = [['ENCF' => 'E340000000013', 'TipoeCF' => '34', 'NCFModificado' => 'E440000000013', 'RNCEmisor' => '131111112']];
$r = esperarDgiiAntesDeNotas('https://x/api', 'k', 's', $results, $notaHermana, 300, 10, $consulta, $dormir);
$chk('lo enviado se consulta con su rnc_emisor', ($llamadas[0]['rnc'] ?? null) === '131111112', $llamadas);
$chk('el original de otra corrida usa el RNCEmisor del set', ($llamadas[1]['rnc'] ?? null) === '131111112', $llamadas);
$llamadas = [];
$q = null;
$capturar = static function ($api, $key, $secret, string $encf, array $s) use (&$q) {
    // Lo que f2ConsultarEstado mandaria: se reconstruye con su misma regla.
    $q = ['e_ncf' => $encf] + (!empty($s['track_id']) ? ['track_id' => $s['track_id']] : []) + (!empty($s['rnc']) ? ['rnc' => $s['rnc']] : []);
    return ['http_status' => 200, 'body' => ['status' => true, 'estado' => 'ACEPTADO']];
};
$res2 = [$enviado('E310000000034') + ['rnc_emisor' => '131111112']];
esperarDgiiAntesDeNotas('https://x/api', 'k', 's', $res2, [$notasSet[1]], 300, 10, $capturar, $dormir);
$chk('f2ConsultarEstado arma ?rnc= (codigo real)', str_contains((string) (new ReflectionFunction('f2ConsultarEstado'))->getFileName(), 'send_fase2.php')
    && preg_match("/query\\['rnc'\\] = \\\$s\\['rnc'\\]/", (string) file_get_contents(__DIR__ . '/send_fase2.php')) === 1);

echo "\nCodigo de salida y resumen: mismo criterio\n";
$chk('fila con ok=false falla', f2FilaFallo(['ok' => false]));
$chk('fila RECHAZADO en el envio falla', f2FilaFallo(['ok' => true, 'estado_dgii' => 'RECHAZADO']));
$chk('fila aceptada al final no falla', !f2FilaFallo(['ok' => true, 'estado_dgii' => 'EN_PROCESO', 'estado_dgii_final' => 'ACEPTADO']));
$chk('fila en proceso (sin veredicto) no cuenta como fallo', !f2FilaFallo(['ok' => true, 'estado_dgii' => 'EN_PROCESO']));

echo "\nNormalizacion de estados\n";
$chk('"Aceptado Condicional" = aceptado', f2ClaseEstado('Aceptado Condicional') === 'aceptado');
$chk('"RFCE_RECHAZADO" = rechazado', f2ClaseEstado('RFCE_RECHAZADO') === 'rechazado');
$chk('"En Proceso" = pendiente', f2ClaseEstado('En Proceso') === 'pendiente');
$chk('"NO_ENCONTRADO" = pendiente (la DGII aun no lo registra)', f2ClaseEstado('NO_ENCONTRADO') === 'pendiente');
$chk('respuesta app (bajo data.estado_dgii)', f2EstadoDeRespuesta(['http_status' => 200, 'body' => ['status' => true, 'data' => ['estado_dgii' => 'ACEPTADO']]]) === 'ACEPTADO');
$chk('HTTP 502 = sin estado', f2EstadoDeRespuesta(['http_status' => 502, 'body' => ['status' => false]]) === '');

printf("\n%d de %d pruebas OK\n", $total - $fallos, $total);
exit($fallos > 0 ? 1 : 0);
