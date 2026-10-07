<?php
/**
 * test_write_ahead_api.php — /api/facturas de punta a punta: la API real
 * (php -S index.php) sobre la base de prueba y la DGII falsa
 * (tools/mock_dgii.php). Nunca produccion (decision 6): wa_pdo() se niega a
 * usar una base sin la tabla marca.
 *
 * Uso:
 *   php -d extension=curl -d extension=openssl -d extension=pdo_mysql tools/test_write_ahead_api.php --tenant-db=wa_scratch [--copia-db=wa_scratch_sin_gate]
 *
 * Cada escenario arranca con el mock en un modo conocido, wa_limpiar +
 * wa_sembrar y el log del mock vacio. Tras un "cuelga" el mock sigue
 * durmiendo unos segundos (php -S atiende de a uno): wa_mock_modo() espera.
 */

require_once __DIR__ . '/write_ahead_test_lib.php';

// Textos que ve el usuario, copiados letra por letra de facturaController.php
// (FACTURA_EMISION_INCIERTA, FACTURA_EMISION_FALLO_GENERICO, guarda del total)
// y de EcfNumeroOcupadoException::MENSAJE_USUARIO. Si cambian alla, aqui tambien.
const T_EMISION_INCIERTA = 'No se pudo confirmar si la DGII recibió la factura %s. No la emitas de nuevo: ya quedó guardada. Búscala en Facturación con el filtro Pendientes y, si sigue sin confirmar, avisa a soporte con ese número.';
const T_FALLO_GENERICO = 'No se pudo emitir la factura en la DGII. Espera unos minutos y vuelve a intentarlo; si sigue fallando, avisa a soporte.';
const T_TOTAL_INVALIDO = 'No se pudo calcular el total de la factura. Revisa las líneas e inténtalo de nuevo.';
const T_NUMERO_OCUPADO = 'No se pudo asignar el número de comprobante. Inténtalo de nuevo.';
const RUTA_RECEPCION = '/Recepcion/api/FacturasElectronicas';
const RUTA_RECEPCION_FC = '/RecepcionFC/api/recepcion/ecf';

/** Una linea del body de POST /api/facturas (bien, ITBIS 18%). */
function wa_linea(float $precio, float $cantidad = 1, ?int $productId = null): array
{
    return [
        'nombre_item' => $productId !== null ? 'WA producto' : 'WA linea libre',
        'indicador_facturacion' => 1,
        'indicador_bien_servicio' => 1,
        'cantidad' => $cantidad,
        'precio_unitario' => $precio,
    ] + ($productId !== null ? ['product_id' => $productId] : []);
}

/** Body de POST /api/facturas para el cliente sembrado. */
function wa_factura(string $tipo, array $ids, array $lineas, array $extra = []): array
{
    return $extra + ['tipo_ecf' => $tipo, 'client_id' => $ids['client_id'], 'tipo_pago' => 1, 'items' => $lineas];
}

/** POST /api/facturas con el token de wa_prueba. */
function wa_emitir(array $srv, array $cuerpo): array
{
    return wa_http('POST', $srv['api'] . '/api/facturas', $cuerpo, ['X-API-KEY: ' . $srv['token']], 60);
}

/** GET a la API con el token de wa_prueba. */
function wa_get(array $srv, string $ruta): array
{
    return wa_http('GET', $srv['api'] . $ruta, null, ['X-API-KEY: ' . $srv['token']], 60);
}

/** Escenario nuevo: mock en $modo (espera a un cuelga anterior), base limpia, log vacio. */
function wa_reiniciar(PDO $pdo, array $srv, string $modo = 'acepta'): array
{
    wa_mock_modo($srv['mock'], $modo);
    wa_limpiar($pdo);
    $ids = wa_sembrar($pdo);
    wa_mock_log($srv['mock'], true);
    return $ids;
}

/** Peticiones de recepcion (e-CF o RFCE) que vio el mock. */
function wa_recepciones(array $log): array
{
    return array_values(array_filter($log, static fn(array $e): bool
        => str_ends_with((string) $e['ruta'], RUTA_RECEPCION) || str_ends_with((string) $e['ruta'], RUTA_RECEPCION_FC)));
}

/** Filas EMIT con success=0 de audit_logs, con new_values decodificado. */
function wa_emit_fallidos(PDO $pdo): array
{
    $filas = $pdo->query("SELECT entity_id, new_values FROM audit_logs WHERE action = 'EMIT' AND success = 0 ORDER BY id")
        ->fetchAll(PDO::FETCH_ASSOC);
    return array_map(static fn(array $f): array => ['entity_id' => $f['entity_id'],
        'new_values' => json_decode((string) $f['new_values'], true)], $filas);
}

/** Las claves de un array, ordenadas (para comparar formas). */
function wa_claves(mixed $a): array
{
    $k = is_array($a) ? array_keys($a) : [];
    sort($k);
    return $k;
}

$base = (string) wa_arg('tenant-db', '');
$pdo = wa_pdo($base);
wa_limpiar($pdo);
wa_sembrar($pdo);
$srv = wa_api($base);

// ---------------------------------------------------------------------------
// E1) acepta, E31 con dos lineas de producto: 200, las 10 claves de siempre y
// el stock se mueve una vez por linea.
// ---------------------------------------------------------------------------
echo "E1) acepta: E31 con dos lineas de producto\n";
$ids = wa_reiniciar($pdo, $srv);
$r = wa_emitir($srv, wa_factura('31', $ids, [wa_linea(1000, 1, $ids['product_id']), wa_linea(500, 1, $ids['product_id'])]));
$d = $r['json']['data'] ?? [];
wa_chk('200 con status true', $r['status'] === 200 && ($r['json']['status'] ?? null) === true, $r);
wa_chk('data trae exactamente las 10 claves de la spec 7.1', wa_claves($d) === wa_claves(array_flip([
    'factura_id', 'e_ncf', 'track_id', 'estado_dgii', 'codigo_seguridad', 'total', 'tipo_ecf', 'ambiente',
    'fecha_emision_dgii', 'dgii_response'])), wa_claves($d));
wa_chk('estado_dgii ACEPTADO', ($d['estado_dgii'] ?? null) === 'ACEPTADO', $d);
$fila = wa_filas($pdo, (string) ($d['e_ncf'] ?? ''))[0] ?? [];
wa_chk('la fila quedo ACEPTADO con track_id del mock y secuencia_utilizada 1',
    (int) ($fila['id'] ?? 0) === (int) ($d['factura_id'] ?? -1) && ($fila['estado_dgii'] ?? null) === 'ACEPTADO'
    && ($fila['track_id'] ?? null) === 'track-' . ($d['e_ncf'] ?? '') && (string) ($fila['secuencia_utilizada'] ?? '') === '1', $fila);
wa_chk('dos movimientos de inventario (una por linea)', wa_movimientos($pdo, (int) ($d['factura_id'] ?? 0)) === 2,
    wa_movimientos($pdo, (int) ($d['factura_id'] ?? 0)));

// ---------------------------------------------------------------------------
// E2) rechaza y reintento: 422 con data, sin stock, el numero vuelve; el
// reintento lo reusa, archiva la fila rechazada y mueve stock solo una vez.
// ---------------------------------------------------------------------------
echo "E2) rechaza, y reintento en acepta\n";
$ids = wa_reiniciar($pdo, $srv, 'rechaza');
$cuerpo = wa_factura('31', $ids, [wa_linea(1000, 1, $ids['product_id'])]);
$r = wa_emitir($srv, $cuerpo);
$d1 = $r['json']['data'] ?? [];
wa_chk('rechaza: 422 con data.factura_id', $r['status'] === 422 && is_int($d1['factura_id'] ?? null), $r);
wa_chk('rechaza: sin movimientos de inventario', wa_movimientos($pdo, (int) ($d1['factura_id'] ?? 0)) === 0);
wa_chk('rechaza: el contador de E31 vuelve a 0', wa_contador($pdo, 'E31') === 0, wa_contador($pdo, 'E31'));
wa_mock_modo($srv['mock'], 'acepta');
$r = wa_emitir($srv, $cuerpo);
$d2 = $r['json']['data'] ?? [];
wa_chk('reintento: 200 con el mismo e-NCF', $r['status'] === 200 && ($d2['e_ncf'] ?? null) === ($d1['e_ncf'] ?? '-'), [$d1, $d2]);
$filas = wa_filas($pdo, (string) ($d1['e_ncf'] ?? ''));
wa_chk('reintento: la fila rechazada queda RECHAZADO_ARCHIVADO sin e_ncf, y la nueva ACEPTADO',
    count($filas) === 2 && (int) $filas[0]['id'] === (int) ($d1['factura_id'] ?? 0)
    && $filas[0]['estado_dgii'] === 'RECHAZADO_ARCHIVADO' && $filas[0]['e_ncf'] === null
    && (int) $filas[1]['id'] === (int) ($d2['factura_id'] ?? 0) && $filas[1]['estado_dgii'] === 'ACEPTADO', $filas);
wa_chk('reintento: el stock se mueve solo para la fila nueva',
    wa_movimientos($pdo, (int) ($d1['factura_id'] ?? 0)) === 0 && wa_movimientos($pdo, (int) ($d2['factura_id'] ?? 0)) === 1);

// ---------------------------------------------------------------------------
// E3) cuelga (timeout de la DGII) en los tres flujos: 502 incierto con data,
// fila ENVIO_INCIERTO, sin stock, un EMIT fallido en auditoria y UNA recepcion.
// ---------------------------------------------------------------------------
foreach ([
    ['E31', '31', 1000.0, RUTA_RECEPCION],
    ['E32 >= 250k (e-CF)', '32', 300000.0, RUTA_RECEPCION],
    ['E32 < 250k (RFCE)', '32', 1000.0, RUTA_RECEPCION_FC],
] as [$nombre, $tipo, $precio, $rutaEsperada]) {
    echo "E3) cuelga: {$nombre}\n";
    $ids = wa_reiniciar($pdo, $srv, 'cuelga');
    $r = wa_emitir($srv, wa_factura($tipo, $ids, [wa_linea($precio, 1, $ids['product_id'])]));
    $d = $r['json']['data'] ?? [];
    $eNcf = (string) ($d['e_ncf'] ?? '');
    wa_chk("{$nombre}: 502 con el texto incierto y el e-NCF",
        $r['status'] === 502 && str_starts_with($eNcf, 'E' . $tipo)
        && ($r['json']['error'] ?? null) === sprintf(T_EMISION_INCIERTA, $eNcf), $r);
    wa_chk("{$nombre}: data = factura_id, e_ncf, tipo_ecf, estado_dgii ENVIO_INCIERTO",
        wa_claves($d) === ['e_ncf', 'estado_dgii', 'factura_id', 'tipo_ecf'] && is_int($d['factura_id'] ?? null)
        && ($d['tipo_ecf'] ?? null) === $tipo && ($d['estado_dgii'] ?? null) === 'ENVIO_INCIERTO', $d);
    $filas = wa_filas($pdo, $eNcf);
    wa_chk("{$nombre}: la fila esta guardada en ENVIO_INCIERTO",
        count($filas) === 1 && (int) $filas[0]['id'] === (int) ($d['factura_id'] ?? 0)
        && $filas[0]['estado_dgii'] === 'ENVIO_INCIERTO' && (string) $filas[0]['tiene_xml'] === '1', $filas);
    wa_chk("{$nombre}: sin movimientos de inventario", wa_movimientos($pdo, (int) ($d['factura_id'] ?? 0)) === 0);
    $audit = wa_emit_fallidos($pdo);
    wa_chk("{$nombre}: un EMIT success=0 con el factura_id de la fila",
        count($audit) === 1 && ($audit[0]['new_values']['factura_id'] ?? null) === ($d['factura_id'] ?? -1)
        && ($audit[0]['new_values']['estado_dgii'] ?? null) === 'ENVIO_INCIERTO', $audit);
    wa_chk("{$nombre}: el numero queda tomado (contador +1)", wa_contador($pdo, 'E' . $tipo) === 1, wa_contador($pdo, 'E' . $tipo));
    $recep = wa_recepciones(wa_mock_log($srv['mock']));   // espera a que el mock termine de dormir
    wa_chk("{$nombre}: una sola recepcion, por {$rutaEsperada}",
        count($recep) === 1 && str_ends_with((string) $recep[0]['ruta'], $rutaEsperada), $recep);
}

// ---------------------------------------------------------------------------
// E4) strict_input sin monto_total: 422 antes de tocar la DGII o la secuencia.
// Con strict_input el comprador sale SOLO del body (handleEmisionECF, $comprador):
// sin `comprador`, ECFXmlBuilder::buildComprador lanza EcfUsuarioException para
// la E31 sin RNC antes de enviar, y el caso nunca llegaria al bug de la spec 4.7
// (un total NULL que se descubria al guardar, DESPUES de la respuesta de la DGII).
// ---------------------------------------------------------------------------
echo "E4) strict_input sin monto_total\n";
$ids = wa_reiniciar($pdo, $srv);
$r = wa_emitir($srv, wa_factura('31', $ids, [wa_linea(1000)], [
    'strict_input' => true,
    'totales' => ['monto_gravado_total' => 1000],
    'comprador' => ['rnc' => '000000001', 'razon_social' => 'WA CLIENTE DE PRUEBA SRL'],
]));
wa_chk('422 con el texto de la guarda del total', $r['status'] === 422 && ($r['json']['error'] ?? null) === T_TOTAL_INVALIDO, $r);
wa_chk('sin recepcion en la DGII', wa_recepciones(wa_mock_log($srv['mock'])) === []);
wa_chk('el contador de E31 no se movio', wa_contador($pdo, 'E31') === 0, wa_contador($pdo, 'E31'));

// ---------------------------------------------------------------------------
// E5) ncf_sequences ilegible: 502 generico, no "Se acabaron los numeros".
// ---------------------------------------------------------------------------
echo "E5) ncf_sequences ilegible\n";
$ids = wa_reiniciar($pdo, $srv);
try {
    $pdo->exec('RENAME TABLE ncf_sequences TO ncf_sequences_wa_off');
    $r = wa_emitir($srv, wa_factura('31', $ids, [wa_linea(1000, 1, $ids['product_id'])]));
} finally {
    $pdo->exec('RENAME TABLE ncf_sequences_wa_off TO ncf_sequences');
}
wa_chk('502 con el texto generico exacto', $r['status'] === 502 && ($r['json']['error'] ?? null) === T_FALLO_GENERICO, $r);
wa_chk('ninguna fila', (int) $pdo->query('SELECT COUNT(*) FROM facturas')->fetchColumn() === 0);
wa_chk('sin recepcion en la DGII', wa_recepciones(wa_mock_log($srv['mock'])) === []);

// ---------------------------------------------------------------------------
// E6) acepta_condicional: 200 y el stock se mueve una vez.
// ---------------------------------------------------------------------------
echo "E6) acepta_condicional\n";
$ids = wa_reiniciar($pdo, $srv, 'acepta_condicional');
$r = wa_emitir($srv, wa_factura('31', $ids, [wa_linea(1000, 1, $ids['product_id'])]));
$d = $r['json']['data'] ?? [];
wa_chk('200 con estado_dgii ACEPTADO_CONDICIONAL', $r['status'] === 200 && ($d['estado_dgii'] ?? null) === 'ACEPTADO_CONDICIONAL', $r);
wa_chk('un movimiento de inventario', wa_movimientos($pdo, (int) ($d['factura_id'] ?? 0)) === 1);

// ===========================================================================
// Task 18: GET /estado de filas en vuelo, filtro pendiente y stock al aceptar.
// ===========================================================================

// Notas de facturaController.php (spec 7.3), letra por letra.
const T_NOTA_EN_VUELO = 'La DGII todavía no ha confirmado este comprobante. No lo emitas de nuevo; si sigue así en unos minutos, avisa a soporte con el número %s.';
const T_NOTA_ENVIADO_SIN_TRACK = 'La DGII recibió este comprobante, pero no devolvió su número de seguimiento y por eso no se puede consultar su estado desde aquí. Si necesitas confirmarlo, avisa a soporte con el número %s.';

/** Una fila de facturas sembrada a mano en $estado, con $lineas lineas del producto. */
function wa_fila_sembrada(PDO $pdo, array $ids, string $eNcf, string $estado, ?string $trackId, int $lineas = 0): int
{
    $pdo->prepare(
        "INSERT INTO facturas (no_factura, date, client_id, client_name, total, tipo_pago, tipo_ecf, e_ncf, track_id,
                               estado_dgii, codigo_seguridad, fecha_emision_dgii, ambiente_dgii)
         VALUES (:nf, NOW(), :cid, 'WA cliente', 1180.00, 1, :tipo, :encf, :track, :estado, 'ABC123', NOW(), 'certecf')"
    )->execute([':nf' => $eNcf, ':cid' => $ids['client_id'], ':tipo' => substr($eNcf, 1, 2), ':encf' => $eNcf,
        ':track' => $trackId, ':estado' => $estado]);
    $id = (int) $pdo->lastInsertId();
    $item = $pdo->prepare(
        "INSERT INTO factura_items (factura_id, product_id, description, amount, quantity, subtotal, itbis_amount)
         VALUES (:f, :p, 'WA producto', 1000.0000, 1.000, 1000.00, 180.00)"
    );
    for ($i = 0; $i < $lineas; $i++) {
        $item->execute([':f' => $id, ':p' => $ids['product_id']]);
    }
    return $id;
}

echo "C1) GET /estado de una factura en vuelo\n";
$ids = wa_reiniciar($pdo, $srv, 'cuelga');
$r = wa_emitir($srv, wa_factura('31', $ids, [wa_linea(1000, 1, $ids['product_id'])]));
$id = (int) ($r['json']['data']['factura_id'] ?? 0);
$eNcf = (string) ($r['json']['data']['e_ncf'] ?? '');
wa_mock_log($srv['mock'], true);   // espera a que el mock termine de dormir y vacia el log
$r = wa_get($srv, "/api/facturas/{$id}/estado");
$d = $r['json']['data'] ?? [];
wa_chk('200 con status true', $r['status'] === 200 && ($r['json']['status'] ?? null) === true, $r);
wa_chk('data trae exactamente las claves de la spec 7.3 (Release 1)', wa_claves($d)
    === ['e_ncf', 'en_vuelo', 'estado_dgii', 'factura_id', 'nota', 'secuencia_utilizada', 'tipo_ecf', 'track_id'], wa_claves($d));
wa_chk('ENVIO_INCIERTO y en_vuelo, sin track_id ni secuencia_utilizada',
    ($d['factura_id'] ?? null) === $id && ($d['e_ncf'] ?? null) === $eNcf && ($d['tipo_ecf'] ?? null) === '31'
    && ($d['estado_dgii'] ?? null) === 'ENVIO_INCIERTO' && ($d['en_vuelo'] ?? null) === true
    && array_key_exists('track_id', $d) && $d['track_id'] === null
    && array_key_exists('secuencia_utilizada', $d) && $d['secuencia_utilizada'] === null, $d);
wa_chk('nota en vuelo con el e-NCF', ($d['nota'] ?? null) === sprintf(T_NOTA_EN_VUELO, $eNcf), $d['nota'] ?? null);
wa_chk('sin campo de reenvio (decision 7)', !array_key_exists('puede_reenviar', $d), $d);
wa_chk('no llamo a la DGII', wa_mock_log($srv['mock']) === []);

echo "C2) GET /estado de un ENVIADO sin track_id\n";
$ids = wa_reiniciar($pdo, $srv);
$id = wa_fila_sembrada($pdo, $ids, 'E310000000901', 'ENVIADO', null);
$r = wa_get($srv, "/api/facturas/{$id}/estado");
$d = $r['json']['data'] ?? [];
wa_chk('200, ENVIADO con en_vuelo false',
    $r['status'] === 200 && ($d['estado_dgii'] ?? null) === 'ENVIADO' && ($d['en_vuelo'] ?? null) === false, $r);
wa_chk('nota de ENVIADO sin numero de seguimiento', ($d['nota'] ?? null) === sprintf(T_NOTA_ENVIADO_SIN_TRACK, 'E310000000901'), $d);

echo "C3) GET /api/facturas?estado=pendiente\n";
$ids = wa_reiniciar($pdo, $srv, 'cuelga');
$r = wa_emitir($srv, wa_factura('31', $ids, [wa_linea(1000, 1, $ids['product_id'])]));
$eNcfVuelo = (string) ($r['json']['data']['e_ncf'] ?? '');
wa_mock_modo($srv['mock'], 'acepta');   // espera a que termine el cuelga
wa_fila_sembrada($pdo, $ids, 'E310000000901', 'ENVIADO', null);
wa_fila_sembrada($pdo, $ids, 'E310000000902', 'EN_PROCESO', 'track-E310000000902');
wa_fila_sembrada($pdo, $ids, 'E320000000903', 'RFCE_ENVIADO', null);
wa_fila_sembrada($pdo, $ids, 'E310000000904', 'ACEPTADO', 'track-E310000000904');
wa_fila_sembrada($pdo, $ids, 'E310000000905', 'RECHAZADO', 'track-E310000000905');
$r = wa_get($srv, '/api/facturas?estado=pendiente&pageSize=50');
$lista = array_column($r['json']['data'] ?? [], 'e_ncf');
sort($lista);
$esperada = [$eNcfVuelo, 'E310000000901', 'E310000000902', 'E320000000903'];
sort($esperada);
wa_chk('200', $r['status'] === 200, $r);
wa_chk('lista la fila en vuelo, ENVIADO, EN_PROCESO y RFCE_ENVIADO; no ACEPTADO ni RECHAZADO', $lista === $esperada, $lista);
wa_chk('pagination.total cuenta las mismas 4', ($r['json']['pagination']['total'] ?? null) === 4, $r['json']['pagination'] ?? null);
$r = wa_get($srv, '/api/facturas?estado=foo');
wa_chk('estado=foo sigue respondiendo 422', $r['status'] === 422, $r);

echo "C4) Stock al aceptar por la consulta (e-CF)\n";
$ids = wa_reiniciar($pdo, $srv, 'cuelga');
$r = wa_emitir($srv, wa_factura('31', $ids, [wa_linea(1000, 1, $ids['product_id']), wa_linea(500, 1, $ids['product_id'])]));
$id = (int) ($r['json']['data']['factura_id'] ?? 0);
$eNcf = (string) ($r['json']['data']['e_ncf'] ?? '');
wa_mock_modo($srv['mock'], 'acepta');
// Caso 1 del procedimiento 10.2: soporte le pone el trackId que da la DGII.
$st = $pdo->prepare("UPDATE facturas SET estado_dgii = 'ENVIADO', track_id = :T WHERE id = :id
                     AND (estado_dgii IN ('PENDIENTE_ENVIO','ENVIO_INCIERTO') OR (estado_dgii = 'ENVIADO' AND track_id IS NULL))");
$st->execute([':T' => 'track-' . $eNcf, ':id' => $id]);
wa_chk('el UPDATE del caso 1 de 10.2 toma la fila, todavia sin movimientos',
    $st->rowCount() === 1 && wa_movimientos($pdo, $id) === 0, [$st->rowCount(), wa_movimientos($pdo, $id)]);
$r = wa_get($srv, "/api/facturas/{$id}/estado");
wa_chk('GET /estado: ACEPTADO', ($r['json']['data']['estado_dgii'] ?? null) === 'ACEPTADO', $r);
wa_chk('el stock se movio, una vez por linea', wa_movimientos($pdo, $id) === 2, wa_movimientos($pdo, $id));
wa_get($srv, "/api/facturas/{$id}/estado");
wa_chk('un segundo GET no mueve mas', wa_movimientos($pdo, $id) === 2, wa_movimientos($pdo, $id));

echo "C5) Stock al aceptar por la consulta (RFCE)\n";
$ids = wa_reiniciar($pdo, $srv, 'cuelga');
$r = wa_emitir($srv, wa_factura('32', $ids, [wa_linea(1000, 1, $ids['product_id'])]));
$id = (int) ($r['json']['data']['factura_id'] ?? 0);
wa_mock_modo($srv['mock'], 'acepta');
// Caso RFCE del procedimiento 10.2.
$st = $pdo->prepare("UPDATE facturas SET estado_dgii = 'RFCE_ENVIADO' WHERE id = :id AND estado_dgii IN ('PENDIENTE_ENVIO','ENVIO_INCIERTO')");
$st->execute([':id' => $id]);
wa_chk('el UPDATE del caso RFCE de 10.2 toma la fila, todavia sin movimientos',
    $st->rowCount() === 1 && wa_movimientos($pdo, $id) === 0, [$st->rowCount(), wa_movimientos($pdo, $id)]);
$r = wa_get($srv, "/api/facturas/{$id}/estado");
wa_chk('GET /estado: RFCE_ACEPTADO', ($r['json']['data']['estado_dgii'] ?? null) === 'RFCE_ACEPTADO', $r);
wa_chk('el stock se movio una vez', wa_movimientos($pdo, $id) === 1, wa_movimientos($pdo, $id));
wa_get($srv, "/api/facturas/{$id}/estado");
wa_chk('un segundo GET no mueve mas', wa_movimientos($pdo, $id) === 1, wa_movimientos($pdo, $id));

echo "C6) Una venta vieja ya ACEPTADO sin movimientos no mueve al consultarla\n";
$ids = wa_reiniciar($pdo, $srv);
$id = wa_fila_sembrada($pdo, $ids, 'E310000000906', 'ACEPTADO', 'track-E310000000906', 1);
$r = wa_get($srv, "/api/facturas/{$id}/estado");
wa_chk('GET /estado: sigue ACEPTADO', $r['status'] === 200 && ($r['json']['data']['estado_dgii'] ?? null) === 'ACEPTADO', $r);
wa_chk('sin transicion no hay movimiento', wa_movimientos($pdo, $id) === 0, wa_movimientos($pdo, $id));

echo "C7) NO_ENCONTRADO que la DGII acepta despues: mueve una vez\n";
$id = wa_fila_sembrada($pdo, $ids, 'E310000000907', 'NO_ENCONTRADO', 'track-E310000000907', 1);
$r = wa_get($srv, "/api/facturas/{$id}/estado");
wa_chk('GET /estado: ACEPTADO', ($r['json']['data']['estado_dgii'] ?? null) === 'ACEPTADO', $r);
wa_chk('el stock se movio una vez', wa_movimientos($pdo, $id) === 1, wa_movimientos($pdo, $id));

// ===========================================================================
// Task 20: stats, reportes, impresion, saldo de una E34 en vuelo y la
// compuerta 12.0 pasos 3-4 sobre la copia rota (--copia-db).
// ===========================================================================

echo "R1) Stats con una factura aceptada (1180.00) y una en vuelo (2360.00)\n";
$ids = wa_reiniciar($pdo, $srv);
$r = wa_emitir($srv, wa_factura('31', $ids, [wa_linea(1000)]));
$aceptada = $r['json']['data'] ?? [];
wa_mock_modo($srv['mock'], 'cuelga');
$r = wa_emitir($srv, wa_factura('31', $ids, [wa_linea(2000)]));
$enVuelo = $r['json']['data'] ?? [];
wa_mock_modo($srv['mock'], 'acepta');   // espera a que termine el cuelga
$r = wa_get($srv, '/api/facturas/stats');
$stats = $r['json']['data'] ?? [];
$tipo31 = array_values(array_filter($stats['por_tipo'] ?? [], static fn(array $t): bool => (string) ($t['tipo_ecf'] ?? '') === '31'))[0] ?? [];
$cerca = static fn(mixed $v, float $x): bool => is_numeric($v) && abs((float) $v - $x) < 0.005;
// [documentos, monto] de una serie: las filas de la prueba son las unicas de la base.
$suma = static fn(array $serie): array => [(int) array_sum(array_column($serie, 'total')), round((float) array_sum(array_column($serie, 'monto_total')), 2)];
wa_chk('precondicion: una ACEPTADO y una ENVIO_INCIERTO',
    ($aceptada['estado_dgii'] ?? null) === 'ACEPTADO' && ($enVuelo['estado_dgii'] ?? null) === 'ENVIO_INCIERTO', [$aceptada, $enVuelo]);
wa_chk('resumen.total_ecf cuenta las dos filas', ($stats['resumen']['total_ecf'] ?? null) === 2, $stats['resumen'] ?? null);
wa_chk('resumen.monto_total suma solo la aceptada', $cerca($stats['resumen']['monto_total'] ?? null, 1180.0), $stats['resumen'] ?? null);
wa_chk('resumen.monto_neto suma solo la aceptada', $cerca($stats['resumen']['monto_neto'] ?? null, 1180.0), $stats['resumen'] ?? null);
wa_chk('por_tipo[31].total cuenta las dos filas', ($tipo31['total'] ?? null) === 2, $tipo31);
wa_chk('por_tipo[31].monto_total suma solo la aceptada', $cerca($tipo31['monto_total'] ?? null, 1180.0), $tipo31);
wa_chk('por_mes: solo la aceptada', $suma($stats['por_mes'] ?? []) === [1, 1180.0], $stats['por_mes'] ?? null);
wa_chk('ventas_por_mes: solo la aceptada', $suma($stats['ventas_por_mes'] ?? []) === [1, 1180.0], $stats['ventas_por_mes'] ?? null);
wa_chk('ventas_por_dia: solo la aceptada', $suma($stats['ventas_por_dia'] ?? []) === [1, 1180.0], $stats['ventas_por_dia'] ?? null);
$cubeta = array_values(array_filter($stats['por_estado'] ?? [], static fn(array $e): bool => ($e['estado'] ?? '') === 'ENVIO_INCIERTO'))[0] ?? [];
wa_chk('por_estado trae la cubeta ENVIO_INCIERTO con una fila', ($cubeta['total'] ?? null) === 1, $stats['por_estado'] ?? null);

echo "R2) Reportes de ventas y 607\n";
$desde = date('Y-m-d', strtotime('-1 day'));
$hasta = date('Y-m-d', strtotime('+1 day'));
$r = wa_get($srv, "/api/reportes/ventas?desde={$desde}&hasta={$hasta}");
$docs = array_column($r['json']['data']['filas'] ?? [], 'documento');
wa_chk('ventas: 200 con la aceptada', $r['status'] === 200 && in_array($aceptada['e_ncf'] ?? '-', $docs, true), $r);
wa_chk('ventas: sin la fila en vuelo', !in_array($enVuelo['e_ncf'] ?? '-', $docs, true), $docs);
$r = wa_get($srv, '/api/reportes/607/preview?periodo=' . date('Ym'));
$ncfs = array_column($r['json']['data']['registros'] ?? [], 'ncf');
wa_chk('607: 200 con la aceptada y sin la fila en vuelo', $r['status'] === 200
    && in_array($aceptada['e_ncf'] ?? '-', $ncfs, true) && !in_array($enVuelo['e_ncf'] ?? '-', $ncfs, true), [$r['status'], $ncfs]);

echo "R3) La fila en vuelo imprime como cualquier otra (decision 4)\n";
$idVuelo = (int) ($enVuelo['factura_id'] ?? 0);
$r = wa_get($srv, "/api/facturas/{$idVuelo}/pdf?format=base64");
wa_chk('PDF: 200 y el contenido es un PDF',
    $r['status'] === 200 && str_starts_with((string) base64_decode((string) ($r['json']['data']['content'] ?? '')), '%PDF'), $r['status']);
$r = wa_get($srv, "/api/facturas/{$idVuelo}/xml");
wa_chk('XML firmado: 200 con su e-NCF',
    $r['status'] === 200 && str_contains($r['body'], '<eNCF>' . ($enVuelo['e_ncf'] ?? '-') . '</eNCF>'), $r['status']);

echo "R4) Saldo: una nota de credito en vuelo ya resta\n";
$ids = wa_reiniciar($pdo, $srv);
$r = wa_emitir($srv, wa_factura('31', $ids, [wa_linea(1000)]));   // 1180.00
$original = $r['json']['data'] ?? [];
wa_chk('E31 original ACEPTADO', $r['status'] === 200 && ($original['estado_dgii'] ?? null) === 'ACEPTADO', $r);
$referencia = ['ncf_modificado' => (string) ($original['e_ncf'] ?? ''), 'fecha_ncf_modificado' => date('d-m-Y'),
    'codigo_modificacion' => '3', 'razon_modificacion' => 'Ajuste de prueba WA'];
wa_mock_modo($srv['mock'], 'cuelga');
$r = wa_emitir($srv, wa_factura('34', $ids, [wa_linea(500)], ['informacion_referencia' => $referencia]));   // 590.00
wa_chk('primera E34 (590.00) queda en vuelo: 502 incierto',
    $r['status'] === 502 && ($r['json']['data']['estado_dgii'] ?? null) === 'ENVIO_INCIERTO', $r);
wa_mock_modo($srv['mock'], 'acepta');
wa_mock_log($srv['mock'], true);
$r = wa_emitir($srv, wa_factura('34', $ids, [wa_linea(600)], ['informacion_referencia' => $referencia]));   // 708.00
wa_chk('segunda E34 (708.00) supera el saldo que deja la nota en vuelo: 422',
    $r['status'] === 422 && str_contains((string) ($r['json']['error'] ?? ''), 'solo le quedan RD$ 590.00'), $r);
wa_chk('la segunda E34 no llego a la DGII', wa_recepciones(wa_mock_log($srv['mock'])) === []);

// 12.0 pasos 3-4: una segunda API (puerto 8097) sobre la copia rota a proposito
// (sin 027 ni 028; ver tools/test_write_ahead_gate.php). Sin --copia-db se omiten.
$copia = (string) wa_arg('copia-db', '');
if ($copia === '') {
    echo "  (sin --copia-db: no se corren los pasos 3 y 4 de la compuerta 12.0)\n";
} else {
    echo "R5) 12.0 paso 3: sin la 027, el numero tomado en otro ambiente se rechaza antes del envio\n";
    $pdoCopia = wa_pdo($copia);
    $srvCopia = wa_api($copia, [], 8097);
    $idsCopia = wa_reiniciar($pdoCopia, $srvCopia);
    $pdoCopia->exec("INSERT INTO facturas (no_factura, client_name, total, tipo_ecf, e_ncf, estado_dgii, ambiente_dgii)
                     VALUES ('E310000000001', 'WA otro ambiente', 100.00, '31', 'E310000000001', 'ACEPTADO', 'ecf')");
    $r = wa_emitir($srvCopia, wa_factura('31', $idsCopia, [wa_linea(1000)]));
    wa_chk('502 con el texto de numero ocupado y sin data',
        $r['status'] === 502 && ($r['json']['error'] ?? null) === T_NUMERO_OCUPADO && !isset($r['json']['data']), $r);
    wa_chk('el contador de E31 queda en 1: el numero se salta', wa_contador($pdoCopia, 'E31') === 1, wa_contador($pdoCopia, 'E31'));
    $filas = wa_filas($pdoCopia, 'E310000000001');
    wa_chk('ninguna fila nueva: solo la del otro ambiente', count($filas) === 1 && $filas[0]['ambiente_dgii'] === 'ecf', $filas);
    wa_chk('sin recepcion en la DGII', wa_recepciones(wa_mock_log($srvCopia['mock'])) === []);

    echo "R6) 12.0 paso 4: sin la 028 y en modo estricto, la confirmacion RFCE queda incierta\n";
    // El servidor de prueba es el mismo de siempre y no se le cambia el sql_mode:
    // solo se verifica que sea estricto (sin eso el desborde de VARCHAR(20) se
    // trunca en silencio y esta prueba no prueba nada).
    $modo = (string) $pdoCopia->query('SELECT @@GLOBAL.sql_mode')->fetchColumn();
    $estricto = str_contains($modo, 'STRICT_ALL_TABLES') || str_contains($modo, 'STRICT_TRANS_TABLES');
    wa_chk('el servidor de prueba corre en modo estricto', $estricto, $modo);
    $idsCopia = wa_reiniciar($pdoCopia, $srvCopia, 'acepta_condicional');
    $r = wa_emitir($srvCopia, wa_factura('32', $idsCopia, [wa_linea(1000)]));
    $d = $r['json']['data'] ?? [];
    wa_chk('502 incierto con data.estado_dgii ENVIO_INCIERTO',
        $r['status'] === 502 && ($d['estado_dgii'] ?? null) === 'ENVIO_INCIERTO', $r);
    $fila = wa_filas($pdoCopia, (string) ($d['e_ncf'] ?? ''))[0] ?? [];
    wa_chk('la fila quedo ENVIO_INCIERTO', ($fila['estado_dgii'] ?? null) === 'ENVIO_INCIERTO', $fila);
    $meta = json_decode((string) ($fila['respuesta_dgii'] ?? ''), true);
    wa_chk('resultado_parcial guarda la respuesta real: RFCE_ACEPTADO_CONDICIONAL',
        ($meta['resultado_parcial']['estado'] ?? null) === 'RFCE_ACEPTADO_CONDICIONAL', $meta);
}

wa_mock_modo($srv['mock'], 'acepta');
wa_fin();
