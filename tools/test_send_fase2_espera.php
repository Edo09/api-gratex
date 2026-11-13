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

// ---------------------------------------------------------------------------
// Modo app. Origen: empresa 133454394, 2026-11-12. En su primera corrida tres
// RFCE (E320000000011/13/15) no tuvieron respuesta de RecepcionFC (timeout de
// 30 s) y quedaron ENVIO_INCIERTO: las notas no salieron, cada corrida siguiente
// respondia "No se pudo asignar el numero de comprobante" en esos tres, y el
// runner lo contaba como "no se sabe si llegaron". Ademas una corrida uso el
// xlsx de otra empresa (130968837) y salio completa con ese RNCEmisor.
// ---------------------------------------------------------------------------

/** Respuestas de POST /api/facturas como las arma facturaController::emitirFacturaEcf. */
$apiOk = static fn(string $encf, int $id, string $estado = 'ENVIADO', ?string $track = 'trk'): array => ['http_status' => 200, 'body' => [
    'status' => true, 'data' => ['factura_id' => $id, 'e_ncf' => $encf, 'track_id' => $track === null ? null : $track . '-' . $encf,
        'estado_dgii' => $estado, 'codigo_seguridad' => 'Ab12Cd', 'tipo_ecf' => substr($encf, 1, 2), 'ambiente' => 'certecf']]];
$apiIncierto = static fn(string $encf, int $id): array => ['http_status' => 502, 'body' => [
    'status' => false,
    'error' => "No se pudo confirmar si la DGII recibió la factura {$encf}. No la emitas de nuevo: ya quedó guardada.",
    'data' => ['factura_id' => $id, 'e_ncf' => $encf, 'tipo_ecf' => substr($encf, 1, 2), 'estado_dgii' => 'ENVIO_INCIERTO']]];
$apiNoEnviado = static fn(string $error = 'No se pudo asignar el número de comprobante. Inténtalo de nuevo.'): array
    => ['http_status' => 502, 'body' => ['status' => false, 'error' => $error]];
$apiRechazo = static fn(string $encf, int $id, string $estado = 'RECHAZADO'): array => ['http_status' => 422, 'body' => [
    'status' => false, 'error' => 'La DGII rechazó la factura: X.', 'data' => ['factura_id' => $id, 'e_ncf' => $encf, 'estado_dgii' => $estado]]];
/** Fila del reporte en modo app para ese e-NCF y esa respuesta. */
$filaApp = static fn(string $encf, array $respuesta): array => f2ArmarEntrada(
    ['caso' => '133454394' . $encf, 'tipo_ecf' => substr($encf, 1, 2), 'e_ncf' => $encf, 'rnc_emisor' => '133454394'], $respuesta, false, null);
/** Respuesta de GET /api/facturas/{id}/estado (app): el estado va bajo data. */
$estadoApp = static fn(string $estado, array $extra = []): array => ['http_status' => 200, 'body' => ['status' => true,
    'data' => ['estado_dgii' => $estado] + $extra]];
$payloadDe = static fn(string $encf): array => ['tipo_ecf' => substr($encf, 1, 2), 'e_ncf' => $encf, 'strict_input' => true];
$notaFrenada = ['caso' => 'x', 'tipo_ecf' => '34', 'e_ncf' => 'E340000000016', 'ok' => false, 'skipped' => true, 'error' => 'nota no enviada: x'];

/** Envio falso: devuelve las respuestas en orden (la ultima se repite) y anota cada e-NCF mandado. */
$cola = static function (array $respuestas) use (&$enviados): callable {
    $enviados = [];
    return static function (array $payload) use (&$respuestas, &$enviados): array {
        $enviados[] = $payload['e_ncf'] ?? null;
        return count($respuestas) > 1 ? array_shift($respuestas) : $respuestas[0];
    };
};
/** Consulta falsa de GET /facturas/{id}/estado: por e-NCF, ronda a ronda (la ultima se repite). Anota el factura_id pedido. */
$consultaApp = static function (array $porEncf) use (&$consultas): callable {
    $consultas = [];
    $ronda = [];
    return static function ($api, $key, $secret, string $encf, array $s) use ($porEncf, &$consultas, &$ronda) {
        $consultas[] = ['e_ncf' => $encf, 'factura_id' => $s['factura_id'] ?? null, 'secret' => $secret];
        $n = $ronda[$encf] = ($ronda[$encf] ?? -1) + 1;
        $pasos = $porEncf[$encf] ?? [['http_status' => 404, 'body' => ['status' => false, 'error' => 'No encontramos esta factura.']]];
        return $pasos[min($n, count($pasos) - 1)];
    };
};

echo "\nApp: que paso con un envio (f2ClaseEnvio)\n";
$chk('200 = ok', f2ClaseEnvio($filaApp('E310000000009', $apiOk('E310000000009', 21))) === 'ok');
$chk('200 con RFCE_ERROR = la DGII no lo tomo', f2ClaseEnvio($filaApp('E320000000013', $apiOk('E320000000013', 60, 'RFCE_ERROR', null))) === 'no_recibido');
$chk('502 con factura_id = incierto (la fila quedo guardada, la DGII no contesto)', f2ClaseEnvio($filaApp('E320000000011', $apiIncierto('E320000000011', 8))) === 'incierto');
$chk('502 sin fila = no enviado (nada salio hacia la DGII)', f2ClaseEnvio($filaApp('E320000000011', $apiNoEnviado())) === 'no_enviado');
$chk('502 que no es JSON (lo contesto un proxy) = desconocido',
    f2ClaseEnvio($filaApp('E310000000009', ['http_status' => 502, 'body' => ['status' => false, 'error' => 'respuesta no JSON: <html>'], 'sin_json' => true])) === 'desconocido');
$chk('red caida (HTTP 0) = desconocido', f2ClaseEnvio($filaApp('E310000000009', ['http_status' => 0, 'body' => ['status' => false, 'error' => 'curl: timeout']])) === 'desconocido');
$chk('500 = desconocido', f2ClaseEnvio($filaApp('E310000000009', ['http_status' => 500, 'body' => ['status' => false, 'error' => 'x']])) === 'desconocido');
$chk('422 con RECHAZADO o RFCE_RECHAZADO = rechazado', f2ClaseEnvio($filaApp('E310000000002', $apiRechazo('E310000000002', 70))) === 'rechazado'
    && f2ClaseEnvio($filaApp('E320000000012', $apiRechazo('E320000000012', 30, 'RFCE_RECHAZADO'))) === 'rechazado');
$chk('422 con ERROR = la DGII no lo tomo', f2ClaseEnvio($filaApp('E310000000002', $apiRechazo('E310000000002', 70, 'ERROR'))) === 'no_recibido');
$chk('422 sin data y 404 = validacion (nuestro API no lo acepto)',
    f2ClaseEnvio($filaApp('E310000000005', ['http_status' => 422, 'body' => ['status' => false, 'error' => 'Elige un cliente.']])) === 'validacion'
    && f2ClaseEnvio($filaApp('E310000000005', ['http_status' => 404, 'body' => ['status' => false, 'error' => 'No encontramos ese cliente.']])) === 'validacion');
$chk('fila que nunca se mando (mapeo del xlsx, nota frenada) = sin_enviar',
    f2ClaseEnvio(['ok' => false, 'error' => 'mapeo: La fila no tiene items.']) === 'sin_enviar' && f2ClaseEnvio($notaFrenada) === 'sin_enviar');

echo "\nApp: la fila del reporte dice que paso, no el texto de la pantalla de facturacion\n";
$inc = $filaApp('E320000000011', $apiIncierto('E320000000011', 8));
$chk('incierto: "sin confirmar" con su factura_id, sin el "No la emitas de nuevo" (aqui hay que mandarla otra vez)',
    str_contains($inc['error'], 'sin confirmar') && str_contains($inc['error'], 'factura_id 8') && !str_contains($inc['error'], 'No la emitas'), $inc['error']);
$chk('incierto: el texto del API queda en response.error', str_contains((string) $inc['response']['error'], 'No la emitas de nuevo'), $inc['response']);
$noEnv = $filaApp('E320000000013', $apiNoEnviado());
$chk('no enviado: lo dice y conserva el motivo', str_starts_with($noEnv['error'], 'no enviado (nada salio hacia la DGII): No se pudo asignar'), $noEnv['error']);
$integ = f2ArmarEntrada(['caso' => 'x', 'tipo_ecf' => '32', 'e_ncf' => 'E320000000013', 'rnc_emisor' => '1'], $apiNoEnviado('Fallo emitiendo e-CF: timeout'), true, null);
$chk('integracion: el error del 502 queda tal cual (alli no se sabe si hubo envio)', $integ['error'] === 'Fallo emitiendo e-CF: timeout', $integ['error']);
$fOk = $filaApp('E310000000009', $apiOk('E310000000009', 21));
$chk('ok: las mismas claves de siempre, en su orden',
    array_keys($fOk) === ['caso', 'tipo_ecf', 'e_ncf', 'rnc_emisor', 'http_status', 'ok', 'response', 'factura_id', 'track_id', 'codigo_seguridad', 'rfce_track_id', 'estado_dgii', 'flujo']
    && $fOk['factura_id'] === 21 && $fOk['track_id'] === 'trk-E310000000009' && $fOk['estado_dgii'] === 'ENVIADO' && $fOk['codigo_seguridad'] === 'Ab12Cd', $fOk);

echo "\nApp: un 502 SIN fila no es 'no se sabe si llego' (nada salio): solo frena la nota de ese original\n";
$results = [$filaApp('E310000000034', $apiNoEnviado()), $filaApp('E320000000006', $apiOk('E320000000006', 31, 'ACEPTADO'))];
$r = esperarDgiiAntesDeNotas('https://x/api', 'k', '', $results, [$notasSet[0], $notasSet[1]], 300, 10, $guion([]), $dormir);
$chk('sin motivo general', $r['general'] === null, $r);
$chk('frena la nota de E310000000034 ("no se emitio")', str_contains($r['originales']['E310000000034'] ?? '', 'no se emitio'), $r['originales']);
$chk('la nota de E320000000006 puede salir', !isset($r['originales']['E320000000006']), $r['originales']);

echo "\nApp: un envio que quedo sin confirmar frena las notas, y el mensaje no manda a una consola que cert.html no tiene\n";
$results = [$filaApp('E320000000011', $apiIncierto('E320000000011', 8)), $filaApp('E320000000006', $apiOk('E320000000006', 31, 'ACEPTADO'))];
$r = esperarDgiiAntesDeNotas('https://x/api', 'k', '', $results, [$notasSet[0]], 300, 10, $guion([]), $dormir);
$chk('motivo general que nombra E320000000011', $r['general'] !== null && str_contains($r['general'], 'E320000000011'), $r);
$chk('no habla de la "consola, paso 3" (es de integracion.html): dice que se mande solo lo que falta',
    !str_contains((string) $r['general'], 'consola') && str_contains((string) $r['general'], 'solo lo que falta'), $r['general']);
$results = [['caso' => 'x', 'tipo_ecf' => '41', 'e_ncf' => 'E410000000010', 'ok' => false, 'http_status' => 502, 'error' => 'Fallo emitiendo e-CF',
    'response' => ['status' => false, 'error' => 'Fallo emitiendo e-CF']], $enviado('E310000000034')];
$r = esperarDgiiAntesDeNotas('https://x/api', 'k', 's', $results, [$notasSet[1]], 300, 10, $guion([]), $dormir);
$chk('integracion: el mismo 502 sin fila sigue siendo incierto y manda a su consola del paso 3',
    $r['general'] !== null && str_contains($r['general'], 'consola, paso 3'), $r['general']);

echo "\nApp, reintentos: un RFCE sin confirmar que la DGII SI recibio se toma con su estado y no se reenvia\n";
$results = [$filaApp('E320000000011', $apiIncierto('E320000000011', 8)), $filaApp('E320000000012', $apiOk('E320000000012', 9, 'RFCE_ACEPTADO', null))];
$payloads = [0 => $payloadDe('E320000000011'), 1 => $payloadDe('E320000000012')];
$enviar = $cola([$apiOk('E320000000011', 99)]);
$consulta = $consultaApp(['E320000000011' => [$estadoApp('RFCE_ACEPTADO', ['factura_id' => 8, 'flujo' => 'RFCE'])]]);
$dormidas = 0;
$n = f2ReintentarPendientes('https://x/api', 'k', $results, $payloads, 2, 10, $enviar, $consulta, $dormir);
$chk('1 resuelto', $n === 1, $n);
$chk('pregunta por el factura_id de la fila incierta, con la ruta de app (sin api_secret)',
    $consultas === [['e_ncf' => 'E320000000011', 'factura_id' => 8, 'secret' => '']], $consultas);
$chk('no lo manda otra vez (seria un duplicado, que la DGII rechaza)', $enviados === [], $enviados);
$chk('queda emitido con el estado de la DGII y su factura_id',
    $results[0]['ok'] === true && $results[0]['estado_dgii'] === 'RFCE_ACEPTADO' && $results[0]['factura_id'] === 8
    && ($results[0]['resuelto'] ?? null) === 'consulta' && !isset($results[0]['error']), $results[0]);
$chk('ya no cuenta como fallo', !f2FilaFallo($results[0]) && f2ClaseEnvio($results[0]) === 'ok');
$chk('una sola ronda (duerme una vez)', $dormidas === 1, $dormidas);
$chk('lo que ya estaba bien no se toca', $results[1]['factura_id'] === 9 && !isset($results[1]['intentos']), $results[1]);
$r = esperarDgiiAntesDeNotas('https://x/api', 'k', '', $results, [$nota('E340000000016', 'E320000000011')], 300, 10, $guion([]), $dormir);
$chk('y su nota ya puede salir', $r['general'] === null && $r['originales'] === [], $r);

echo "\nApp, reintentos: la DGII no lo tiene (RFCE_NO_ENCONTRADO): se manda de nuevo\n";
$results = [$filaApp('E320000000013', $apiIncierto('E320000000013', 10))];
$payloads = [0 => $payloadDe('E320000000013')];
$enviar = $cola([$apiOk('E320000000013', 201, 'RFCE_ACEPTADO', null)]);
$consulta = $consultaApp(['E320000000013' => [$estadoApp('RFCE_NO_ENCONTRADO')]]);
$dormidas = 0;
$n = f2ReintentarPendientes('https://x/api', 'k', $results, $payloads, 2, 0, $enviar, $consulta, $dormir);
$chk('se manda de nuevo una vez', $enviados === ['E320000000013'] && $n === 1, [$enviados, $n]);
$chk('queda emitido con la fila nueva y 2 envios', $results[0]['ok'] === true && $results[0]['factura_id'] === 201 && $results[0]['intentos'] === 2, $results[0]);
$chk('pausa 0: no duerme', $dormidas === 0, $dormidas);

echo "\nApp, reintentos: no se puede saber (e-CF completo en certecf): se manda de nuevo, ronda a ronda\n";
$results = [$filaApp('E310000000009', $apiIncierto('E310000000009', 40))];
$payloads = [0 => $payloadDe('E310000000009')];
$enviar = $cola([$apiIncierto('E310000000009', 41), $apiOk('E310000000009', 42)]);
$consulta = $consultaApp(['E310000000009' => [$estadoApp('ENVIO_INCIERTO', ['en_vuelo' => true, 'nota' => 'La DGII todavía no ha confirmado este comprobante.'])]]);
$n = f2ReintentarPendientes('https://x/api', 'k', $results, $payloads, 2, 0, $enviar, $consulta, $dormir);
$chk('dos rondas: consulta la fila de cada intento (40 y luego 41)', array_column($consultas, 'factura_id') === [40, 41], $consultas);
$chk('dos envios mas', $enviados === ['E310000000009', 'E310000000009'], $enviados);
$chk('termina emitido, con 3 envios', $results[0]['ok'] === true && $results[0]['factura_id'] === 42 && $results[0]['intentos'] === 3 && $n === 1, $results[0]);

echo "\nApp, reintentos: la consulta falla (HTTP 502): tampoco se sabe, se manda de nuevo\n";
$results = [$filaApp('E320000000015', $apiIncierto('E320000000015', 11))];
$payloads = [0 => $payloadDe('E320000000015')];
$enviar = $cola([$apiOk('E320000000015', 202, 'RFCE_ACEPTADO', null)]);
$consulta = $consultaApp(['E320000000015' => [['http_status' => 502, 'body' => ['status' => false, 'error' => 'No se pudo consultar el estado en la DGII.']]]]);
$n = f2ReintentarPendientes('https://x/api', 'k', $results, $payloads, 2, 0, $enviar, $consulta, $dormir);
$chk('se manda de nuevo y queda emitido', $enviados === ['E320000000015'] && $results[0]['ok'] === true && $results[0]['factura_id'] === 202, $results[0]);

echo "\nApp, reintentos: la DGII lo tiene en proceso: tampoco se reenvia\n";
$results = [$filaApp('E320000000011', $apiIncierto('E320000000011', 8))];
$payloads = [0 => $payloadDe('E320000000011')];
$enviar = $cola([$apiOk('E320000000011', 99)]);
$consulta = $consultaApp(['E320000000011' => [$estadoApp('RFCE_EN_PROCESO')]]);
$n = f2ReintentarPendientes('https://x/api', 'k', $results, $payloads, 2, 0, $enviar, $consulta, $dormir);
$chk('sin reenvio, queda emitido en proceso (la espera de las notas lo sigue consultando)',
    $enviados === [] && $results[0]['ok'] === true && $results[0]['estado_dgii'] === 'RFCE_EN_PROCESO' && $results[0]['factura_id'] === 8, $results[0]);

echo "\nApp, reintentos: el envio sin confirmar SI llego y la DGII lo rechazo: es un rechazo, no se reenvia\n";
$results = [$filaApp('E320000000015', $apiIncierto('E320000000015', 11)), $filaApp('E310000000034', $apiOk('E310000000034', 50, 'ACEPTADO'))];
$payloads = [0 => $payloadDe('E320000000015'), 1 => $payloadDe('E310000000034')];
$enviar = $cola([$apiOk('E320000000015', 99)]);
$consulta = $consultaApp(['E320000000015' => [$estadoApp('RFCE_RECHAZADO', ['consulta' => ['estado' => 'Rechazado', 'mensajes' => [['valor' => 'MontoTotal no coincide', 'codigo' => 0]]]])]]);
$n = f2ReintentarPendientes('https://x/api', 'k', $results, $payloads, 2, 0, $enviar, $consulta, $dormir);
$chk('no lo reenvia', $enviados === [] && $n === 0, [$enviados, $n]);
$chk('queda como rechazo del envio, con el motivo de la DGII',
    f2ClaseEnvio($results[0]) === 'rechazado' && str_contains($results[0]['error'], 'MontoTotal no coincide') && !empty($results[0]['sin_reintento']), $results[0]);
$r = esperarDgiiAntesDeNotas('https://x/api', 'k', '', $results, [$notasSet[1]], 300, 10, $guion([]), $dormir);
$chk('y frena las notas como cualquier rechazo (reinicia el set)',
    $r['general'] !== null && str_contains($r['general'], 'E320000000015') && $r['rechazos'] === 1, $r);

echo "\nApp, reintentos: lo que no se envio se manda de nuevo; el mismo error dos veces no es pasajero\n";
$results = [$filaApp('E410000000007', $apiNoEnviado('No se pudo emitir la factura en la DGII. Espera unos minutos y vuelve a intentarlo.'))];
$payloads = [0 => $payloadDe('E410000000007')];
$enviar = $cola([$apiOk('E410000000007', 77)]);
$consulta = $consultaApp([]);
$n = f2ReintentarPendientes('https://x/api', 'k', $results, $payloads, 2, 0, $enviar, $consulta, $dormir);
$chk('fallo pasajero antes de enviar: el segundo intento lo emite', $n === 1 && $results[0]['ok'] === true && $results[0]['intentos'] === 2, $results[0]);
$chk('no pregunta el estado de algo que nunca se guardo', $consultas === [], $consultas);
$results = [$filaApp('E320000000011', $apiNoEnviado())];
$payloads = [0 => $payloadDe('E320000000011')];
$enviar = $cola([$apiNoEnviado()]);
$n = f2ReintentarPendientes('https://x/api', 'k', $results, $payloads, 3, 0, $enviar, $consultaApp([]), $dormir);
$chk('"numero ocupado" dos veces seguidas: un solo reintento aunque queden rondas',
    count($enviados) === 1 && !empty($results[0]['sin_reintento']) && $results[0]['intentos'] === 2 && $n === 0, [$enviados, $results[0]]);
$results = [$filaApp('E320000000013', $apiOk('E320000000013', 60, 'RFCE_ERROR', null))];
$payloads = [0 => $payloadDe('E320000000013')];
$enviar = $cola([$apiOk('E320000000013', 61, 'RFCE_ACEPTADO', null)]);
$n = f2ReintentarPendientes('https://x/api', 'k', $results, $payloads, 2, 0, $enviar, $consultaApp([]), $dormir);
$chk('la DGII no lo tomo (RFCE_ERROR): se manda de nuevo', $enviados === ['E320000000013'] && $results[0]['estado_dgii'] === 'RFCE_ACEPTADO' && $n === 1, $results[0]);

echo "\nApp, reintentos: lo que tiene veredicto o no se sabe que paso no se toca\n";
$results = [
    $filaApp('E310000000002', $apiRechazo('E310000000002', 70)),
    $filaApp('E310000000005', ['http_status' => 422, 'body' => ['status' => false, 'error' => 'No encontramos ese cliente.']]),
    $filaApp('E310000000007', ['http_status' => 0, 'body' => ['status' => false, 'error' => 'curl: timeout']]),
    $filaApp('E310000000010', $apiOk('E310000000010', 71)),
    $notaFrenada,
];
$payloads = [0 => $payloadDe('E310000000002'), 1 => $payloadDe('E310000000005'), 2 => $payloadDe('E310000000007'), 3 => $payloadDe('E310000000010')];
$antes = $results;
$enviar = $cola([$apiOk('E310000000002', 1)]);
$consulta = $consultaApp([]);
$dormidas = 0;
$n = f2ReintentarPendientes('https://x/api', 'k', $results, $payloads, 2, 10, $enviar, $consulta, $dormir);
$chk('rechazado, validacion, red caida, emitido y nota frenada: ni envios ni consultas ni pausas',
    $n === 0 && $enviados === [] && $consultas === [] && $results === $antes && $dormidas === 0, [$enviados, $consultas, $dormidas]);

echo "\nApp, reintentos: tope de envios por caso y --reintentos=0\n";
$results = [$filaApp('E320000000011', $apiIncierto('E320000000011', 8))];
$payloads = [0 => $payloadDe('E320000000011')];
$enviar = $cola([$apiIncierto('E320000000011', 9)]);
$consulta = $consultaApp(['E320000000011' => [$estadoApp('ENVIO_INCIERTO')]]);
f2ReintentarPendientes('https://x/api', 'k', $results, $payloads, 2, 0, $enviar, $consulta, $dormir);
$chk('la DGII sigue sin contestar: como mucho 1 + reintentos envios (3) y queda sin confirmar',
    count($enviados) === 2 && $results[0]['intentos'] === 3 && f2ClaseEnvio($results[0]) === 'incierto', [$enviados, $results[0]]);
f2ReintentarPendientes('https://x/api', 'k', $results, $payloads, 2, 0, $enviar, $consulta, $dormir);
$chk('la pasada del final de la corrida ya no insiste con ese caso', count($enviados) === 2, $enviados);
$r = esperarDgiiAntesDeNotas('https://x/api', 'k', '', $results, [$nota('E340000000016', 'E320000000011')], 300, 10, $guion([]), $dormir);
$chk('y las notas no salen: sigue sin saberse si llego', $r['general'] !== null && str_contains($r['general'], 'E320000000011'), $r);
$results = [$filaApp('E320000000011', $apiIncierto('E320000000011', 8))];
$antes = $results;
$enviar = $cola([$apiOk('E320000000011', 99)]);
$consulta = $consultaApp([]);
$dormidas = 0;
$n = f2ReintentarPendientes('https://x/api', 'k', $results, [0 => $payloadDe('E320000000011')], 0, 10, $enviar, $consulta, $dormir);
$chk('--reintentos=0: no hace nada', $n === 0 && $enviados === [] && $consultas === [] && $results === $antes && $dormidas === 0);

echo "\nApp: que hacer con lo que falto (f2QueFalta) y --case con varios e-NCF\n";
$results = [$filaApp('E310000000009', $apiOk('E310000000009', 21, 'ACEPTADO')), $filaApp('E320000000011', $apiIncierto('E320000000011', 8)), $notaFrenada];
$t = f2QueFalta($results);
$chk('sin rechazos: la lista para mandar solo lo que falta (lo incierto y la nota frenada, no lo aceptado)',
    str_contains($t, 'E320000000011,E340000000016') && !str_contains($t, 'E310000000009') && str_contains($t, 'Solo estos e-NCF'), $t);
$chk('esa lista entra tal cual en --case', f2ListaCasos('E320000000011,E340000000016') === ['E320000000011', 'E340000000016']);
$chk('--case acepta comas, espacios, punto y coma y minusculas, sin repetir',
    f2ListaCasos(" e320000000011, E320000000013;E340000000016\nE320000000011 ") === ['E320000000011', 'E320000000013', 'E340000000016']);
$chk('--case vacio o sin valor = sin filtro', f2ListaCasos('') === [] && f2ListaCasos(true) === [] && f2ListaCasos(null) === []);
$t = f2QueFalta(array_merge($results, [$filaApp('E310000000002', $apiRechazo('E310000000002', 70))]));
$chk('con un rechazo: correr la fase COMPLETA (el set ya se reinicio), sin lista parcial', str_contains($t, 'COMPLETA') && !str_contains($t, '--case'), $t);
$chk('un rechazo visto en la espera (estado_dgii_final) tambien manda a la corrida completa',
    str_contains(f2QueFalta([$filaApp('E310000000009', $apiOk('E310000000009', 21)) + ['estado_dgii_final' => 'RECHAZADO']]), 'COMPLETA'));
$chk('lo que la DGII no tomo (RFCE_ERROR) cuenta como faltante aunque el API respondiera 200',
    str_contains(f2QueFalta([$filaApp('E320000000013', $apiOk('E320000000013', 60, 'RFCE_ERROR', null))]), 'E320000000013'));
$chk('todo emitido, o un dry-run: nada que decir',
    f2QueFalta([$filaApp('E310000000009', $apiOk('E310000000009', 21, 'ACEPTADO'))]) === ''
    && f2QueFalta([['caso' => 'x', 'tipo_ecf' => '31', 'e_ncf' => 'E310000000009', 'ok' => true, 'dry_run' => true]]) === '');

echo "\nApp: el set tiene que ser de la empresa del token y salir a certecf (certEmpresaPreflightApp)\n";
$emisorOk = static fn(string $rnc = '133454394', ?string $ambiente = 'certecf'): array => ['http_status' => 200, 'body' => ['status' => true,
    'data' => ['rnc' => $rnc, 'razon_social' => 'EMPRESA EN CERTIFICACION SRL', 'fuente' => 'emisor_config'] + ($ambiente !== null ? ['ambiente' => $ambiente] : [])]];
$pre = static function (array $o, array $respuesta) use (&$preLlamadas): array {
    $preLlamadas = 0;
    return certEmpresaPreflightApp($o + ['api' => 'https://x/api', 'api_key' => 'k', 'origen' => 'RNCEmisor de la hoja ECF',
        'consultar' => static function ($api, $key) use ($respuesta, &$preLlamadas) {
            $preLlamadas++;
            return $respuesta;
        }]);
};
$r = $pre(['rncs' => ['133454394'], 'ambiente' => 'certecf'], $emisorOk());
$chk('mismo RNC y certecf: sigue, con la linea de la empresa',
    $r['ok'] && str_contains(implode("\n", $r['lineas']), 'Empresa: EMPRESA EN CERTIFICACION SRL — RNC 133454394 — ambiente certecf') && $preLlamadas === 1, $r);
$r = $pre(['rncs' => ['130968837'], 'ambiente' => 'certecf'], $emisorOk());
$chk('xlsx de otra empresa (lo del 2026-11-12): no se envia, y dice los dos RNC',
    !$r['ok'] && str_contains($r['error'], '130968837') && str_contains($r['error'], '133454394') && str_contains($r['error'], 'No se envio nada'), $r);
$r = $pre(['rncs' => ['133454394', '130968837'], 'ambiente' => 'certecf'], $emisorOk());
$chk('set que mezcla empresas: no se envia ni se consulta', !$r['ok'] && str_contains($r['error'], 'mezcla') && $preLlamadas === 0, $r);
$r = $pre(['rncs' => [], 'ambiente' => 'certecf'], $emisorOk());
$chk('sin RNCEmisor en lo que se va a enviar: no se envia', !$r['ok'] && $preLlamadas === 0, $r);
$r = $pre(['rncs' => ['133454394'], 'dry_run' => true], $emisorOk());
$chk('dry-run: no consulta nada', $r['ok'] && $preLlamadas === 0 && str_contains($r['lineas'][0], 'sin verificar en dry-run'), $r);
$r = $pre(['rncs' => ['133454394'], 'ambiente' => 'certecf'], ['http_status' => 401, 'body' => ['status' => false, 'error' => 'Token inválido']]);
$chk('token vencido (401): no se envia y manda a hacer login', !$r['ok'] && str_contains($r['error'], 'login') && str_contains($r['error'], 'No se envio nada'), $r);
$r = $pre(['rncs' => ['133454394'], 'ambiente' => 'certecf'], ['http_status' => 500, 'body' => ['status' => false, 'error' => 'No se pudieron cargar los datos de tu empresa.']]);
$chk('si el API contesta que no (500 con su JSON) no se envia', !$r['ok'] && str_contains($r['error'], 'HTTP 500') && str_contains($r['error'], 'No se envio nada'), $r);
$r = $pre(['rncs' => ['133454394'], 'ambiente' => 'certecf'], ['http_status' => 0, 'body' => ['status' => false, 'error' => 'curl: Could not resolve host']]);
$chk('sin conexion con el API: no se envia', !$r['ok'] && str_contains($r['error'], 'No se pudo conectar'), $r);
$waf = ['http_status' => 406, 'body' => ['status' => false, 'error' => 'respuesta no JSON: <html>Not Acceptable! This error was generated by Mod_Security'], 'sin_json' => true];
$r = $pre(['rncs' => ['133454394'], 'ambiente' => 'certecf'], $waf);
$chk('lo que contesto no fue el API (406 del WAF) y la corrida va a certecf: sigue con un AVISO, no deja el asistente sin correr',
    $r['ok'] && str_starts_with($r['lineas'][0], 'AVISO: no se pudo verificar la empresa (GET /emisor respondio HTTP 406')
    && str_contains($r['lineas'][1], 'RNC 133454394 — ambiente certecf'), $r);
$r = $pre(['rncs' => ['133454394'], 'ambiente' => null], $waf);
$chk('el mismo 406 sin --ambiente=certecf: no se sabe a que ambiente saldria, no se envia', !$r['ok'] && str_contains($r['error'], 'a que ambiente'), $r);
$r = $pre(['rncs' => ['133454394'], 'ambiente' => 'certecf'], $emisorOk(''));
$chk('empresa sin RNC en sus datos fiscales: no se envia', !$r['ok'] && str_contains($r['error'], 'no tiene RNC'), $r);
$r = $pre(['rncs' => ['133454394'], 'ambiente' => 'certecf'], $emisorOk('133-45439-4'));
$chk('RNC con guiones en emisor_config: es la misma empresa', $r['ok'], $r);
$r = $pre(['rncs' => ['133454394'], 'ambiente' => 'certecf'], $emisorOk('133454394', 'ecf'));
$chk("empresa ya en 'ecf' con --ambiente=certecf (lo que manda cert.html): sigue y lo dice",
    $r['ok'] && str_contains(implode("\n", $r['lineas']), "ambiente certecf (la empresa esta en 'ecf')"), $r);
$r = $pre(['rncs' => ['133454394'], 'ambiente' => null], $emisorOk('133454394', 'ecf'));
$chk('empresa en ecf y sin --ambiente: el set saldria como e-CF REALES, no se envia', !$r['ok'] && str_contains($r['error'], 'REALES'), $r);
$r = $pre(['rncs' => ['133454394'], 'ambiente' => null, 'permitir_produccion' => true], $emisorOk('133454394', 'ecf'));
$chk('...salvo --permitir-produccion: sigue, con un AVISO primero', $r['ok'] && str_starts_with($r['lineas'][0], 'AVISO'), $r);
$r = $pre(['rncs' => ['133454394'], 'ambiente' => 'ecf'], $emisorOk());
$chk('--ambiente=ecf explicito tampoco sale sin --permitir-produccion', !$r['ok'] && str_contains($r['error'], 'REALES'), $r);
$r = $pre(['rncs' => ['133454394'], 'ambiente' => 'testecf'], $emisorOk());
$chk('--ambiente=testecf: no es el de certificacion, no se envia', !$r['ok'] && str_contains($r['error'], 'no al de certificacion'), $r);
$r = $pre(['rncs' => ['133454394'], 'ambiente' => ' CerteCF '], $emisorOk('133454394', null));
$chk('--ambiente=CerteCF vale como certecf', $r['ok'], $r);
$r = $pre(['rncs' => ['133454394'], 'ambiente' => null], $emisorOk('133454394', null));
$chk('servidor de un solo tenant (sin ambiente en /emisor) y sin --ambiente: no hay con que comparar, sigue',
    $r['ok'] && str_contains(implode("\n", $r['lineas']), '(el del servidor)'), $r);

echo "\nApp: cableado del asistente (texto de los archivos)\n";
$srcRunner = (string) file_get_contents(__DIR__ . '/send_fase2.php');
$chk('main() corre el pre-flight de app con el --ambiente de la corrida y --permitir-produccion',
    str_contains($srcRunner, 'certEmpresaPreflightAppCli([') && str_contains($srcRunner, "'ambiente' => \$ambiente,")
    && str_contains($srcRunner, "'permitir_produccion' => certEmpresaOpciones(\$opts)['permitir_produccion'],"));
$chk('main() reintenta antes de esperar a la DGII por las notas y otra vez al final',
    substr_count($srcRunner, '$reintentar();') === 2
    && strpos($srcRunner, '$reintentar();') < strpos($srcRunner, '$espera = esperarDgiiAntesDeNotas('));
$chk('el POST espera mas que las tres llamadas del servidor a la DGII (3 x 30 s)',
    F2_POST_TIMEOUT_SECONDS >= 100 && str_contains($srcRunner, 'CURLOPT_TIMEOUT => F2_POST_TIMEOUT_SECONDS'));
$srcWrapper = (string) file_get_contents(__DIR__ . '/../public/cert_run.php');
$chk('cert_run.php pasa case, reintentos y reintento-pausa, y nunca --permitir-produccion',
    preg_match("/foreach \\(\\[[^\\]]*'case'[^\\]]*'reintentos', 'reintento-pausa'\\] as \\\$k\\)/", $srcWrapper) === 1
    && !str_contains($srcWrapper, "'permitir-produccion'") && preg_match('/\$argv\[\]\s*=\s*[\'"]--permitir-produccion/', $srcWrapper) === 0);
$srcHtml = (string) file_get_contents(__DIR__ . '/../public/cert.html');
$chk('cert.html: el paso 2 tiene "Solo estos e-NCF" y lo manda como case',
    str_contains($srcHtml, 'id="case2"') && substr_count($srcHtml, 'data-case="case2"') === 2 && str_contains($srcHtml, "fd.append('case', casos.join(','));"));

printf("\n%d de %d pruebas OK\n", $total - $fallos, $total);
exit($fallos > 0 ? 1 : 0);
