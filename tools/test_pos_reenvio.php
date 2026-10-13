<?php
/**
 * test_pos_reenvio.php — El reenvio automatico del POS (PosVenta::reenviarPendientes) y las escrituras de
 * posModel sobre el write-ahead (spec 2026-10-12, 4.3), contra la base de prueba y la DGII falsa
 * (tools/mock_dgii.php). Nunca produccion: wa_pdo() se niega a usar una base sin la tabla marca y las URL de la
 * DGII tienen que ser locales.
 *
 * PosVenta y posModel corren dentro de este proceso (wa_usar_base). Las ventas se siembran a mano en facturas con
 * las columnas del POS, y el mock responde por modo:
 *   trackids_vacio      la DGII no tiene el comprobante (consulta: "No Encontrado") y lo recibe y acepta
 *   trackids_rechazado  la DGII ya lo tiene y lo rechazo
 *   acepta              la DGII ya lo tiene aceptado
 * La API real (wa_api) solo se usa en P5.
 *
 * Uso:
 *   php -d extension=curl -d extension=openssl -d extension=pdo_mysql tools/test_pos_reenvio.php --tenant-db=wa_scratch
 *   (WA_PUERTO_OFFSET=100 si 8098 u 8099 estan ocupados; sale con 1 si algo falla)
 */

require_once __DIR__ . '/write_ahead_test_lib.php';

$base = (string) wa_arg('tenant-db', '');
$pdo = wa_pdo($base);
wa_usar_base($base);       // Database y MasterDatabase de este proceso apuntan a la base de prueba
$srv = wa_api($base);      // mock DGII, API real y el token de wa_prueba (solo para P5)
$cert = wa_cert_prueba();
foreach ([
    'DGII_ECF_BASE_URL' => $srv['mock'], 'DGII_FC_BASE_URL' => $srv['mock'], 'DGII_ECF_TIMEOUT' => '3',
    'DGII_ECF_CERT_PATH' => $cert['path'], 'DGII_ECF_CERT_PASSWORD' => $cert['password'], 'POS_DGII_TIMEOUT' => '3',
] as $clave => $valor) {
    putenv("{$clave}={$valor}");
    $_ENV[$clave] = $valor;
}
if (preg_match('#^http://127\.0\.0\.1:\d+$#', $srv['mock']) !== 1) {
    echo "[FALLO] el mock de la DGII no es local: esta prueba envia comprobantes\n";
    exit(1);
}
require_once __DIR__ . '/../src/AuditLogger.php';
require_once __DIR__ . '/../src/Pos/PosVenta.php';
require_once __DIR__ . '/../src/Models/posModel.php';
require_once __DIR__ . '/../src/Models/ncfModel.php';

/** UUID v4 para pos_idempotency_key. */
function pr_uuid(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

/** Meta de respuesta_dgii de una fila en vuelo (spec 6.3). 'desde' por defecto es de hace 10 minutos. */
function pr_meta(array $extra = []): array
{
    return $extra + [
        'fase' => 'PENDIENTE_ENVIO', 'desde' => date('Y-m-d H:i:s', time() - 600), 'flujo' => 'ECF',
        'ambiente_secuencia' => 'certecf', 'dispensado' => true, 'rango_id' => null, 'secuencia_valor' => null,
    ];
}

/**
 * Una venta del POS sembrada a mano. $o: meta (array para respuesta_dgii), track, pos (false = sin
 * pos_idempotency_key), pendiente (envio_pendiente, 1), rfce (con rfce_xml; por defecto las E32).
 */
function pr_venta(PDO $pdo, string $eNcf, string $estado, array $o = []): int
{
    $tipo = substr($eNcf, 1, 2);
    $xml = static fn(string $raiz): string => "<{$raiz}><Encabezado><IdDoc><eNCF>{$eNcf}</eNCF></IdDoc></Encabezado></{$raiz}>";
    $pdo->prepare(
        "INSERT INTO facturas (no_factura, date, client_name, total, tipo_pago, tipo_ecf, e_ncf, track_id, estado_dgii,
                               codigo_seguridad, fecha_emision_dgii, ambiente_dgii, xml_firmado, respuesta_dgii, rfce_xml,
                               user_id, pos_idempotency_key, envio_pendiente)
         VALUES (:nf, NOW(), 'Consumidor Final', 1180.00, 1, :tipo, :encf, :track, :estado,
                 'ABC123', NOW(), 'certecf', :xml, :resp, :rfce, 1, :clave, :pend)"
    )->execute([
        ':nf' => $eNcf, ':tipo' => $tipo, ':encf' => $eNcf, ':track' => $o['track'] ?? null, ':estado' => $estado,
        ':xml' => $xml('ECF'), ':resp' => isset($o['meta']) ? json_encode($o['meta']) : null,
        ':rfce' => ($o['rfce'] ?? $tipo === '32') ? $xml('RFCE') : null,
        ':clave' => ($o['pos'] ?? true) ? pr_uuid() : null, ':pend' => $o['pendiente'] ?? 1,
    ]);
    return (int) $pdo->lastInsertId();
}

/** Lo que importa de una fila: estado, envio_pendiente, track_id y rfce_estado. */
function pr_fila(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT estado_dgii, envio_pendiente, track_id, rfce_estado FROM facturas WHERE id = :id');
    $st->execute([':id' => $id]);
    $f = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'estado' => $f['estado_dgii'] ?? null, 'pendiente' => isset($f['envio_pendiente']) ? (int) $f['envio_pendiente'] : null,
        'track' => $f['track_id'] ?? null, 'rfce' => $f['rfce_estado'] ?? null,
    ];
}

/** Escenario nuevo: mock en $modo, base limpia y sembrada, sin rangos de prueba, log del mock vacio. */
function pr_reiniciar(PDO $pdo, array $srv, string $modo): array
{
    wa_mock_modo($srv['mock'], $modo);
    $pdo->exec('DELETE FROM pos_caja_movimientos');
    wa_limpiar($pdo);
    $pdo->exec("DELETE FROM ncf_sequences WHERE type LIKE 'E%' AND numero_desde <> 1");
    $ids = wa_sembrar($pdo);
    wa_mock_log($srv['mock'], true);
    return $ids;
}

/** Llamadas de negocio que recibio el mock desde la ultima vez (sin la autenticacion), en orden. */
function pr_tags(string $mockUrl): array
{
    $tags = [];
    foreach (wa_mock_log($mockUrl, true) as $e) {
        $ruta = strtolower((string) $e['ruta']);
        $tag = match (true) {
            str_contains($ruta, '/consultarfce/') => 'rfce:consulta',
            str_contains($ruta, '/recepcionfc/') => 'rfce:recepcion',
            str_contains($ruta, '/consultatrackids/') => 'ecf:trackids',
            str_contains($ruta, '/consultaresultado/') => 'ecf:resultado',
            str_contains($ruta, '/recepcion/') => 'ecf:recepcion',
            default => null,
        };
        if ($tag !== null) {
            $tags[] = $tag;
        }
    }
    return $tags;
}

/** Contadores de los rangos E32 de certecf (numero_desde => current_value), para ver que el reenvio no los toca. */
function pr_contadores(PDO $pdo): array
{
    return array_map('intval', $pdo->query("SELECT numero_desde, current_value FROM ncf_sequences WHERE type = 'E32' AND ambiente = 'certecf' ORDER BY numero_desde")
        ->fetchAll(PDO::FETCH_KEY_PAIR));
}

/** Filas de audit_logs con esa accion. */
function pr_auditorias(PDO $pdo, string $accion): int
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM audit_logs WHERE action = :a');
    $st->execute([':a' => $accion]);
    return (int) $st->fetchColumn();
}

// ---------------------------------------------------------------------------
// P0) La base de prueba tiene las columnas del POS.
// ---------------------------------------------------------------------------
echo "P0) la base tiene lo del POS\n";
$tieneColumnas = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'facturas' AND COLUMN_NAME IN ('pos_idempotency_key', 'envio_pendiente')")->fetchColumn();
$tieneTabla = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'pos_caja_movimientos'")->fetchColumn();
wa_chk('facturas tiene pos_idempotency_key y envio_pendiente, y existe pos_caja_movimientos (migracion 030)',
    $tieneColumnas === 2 && $tieneTabla === 1, [$tieneColumnas, $tieneTabla]);
if ($tieneColumnas !== 2 || $tieneTabla !== 1) {
    wa_fin();
}

// ---------------------------------------------------------------------------
// P1) El flujo lo decide el XML guardado, no el prefijo del estado.
// ---------------------------------------------------------------------------
echo "P1) el flujo lo decide el XML guardado (RFCE si hay rfce_xml, e-CF si no)\n";
pr_reiniciar($pdo, $srv, 'trackids_vacio');
$e32 = pr_venta($pdo, 'E320000000901', 'PENDIENTE_ENVIO', ['meta' => pr_meta(['flujo' => 'RFCE'])]);
$e31 = pr_venta($pdo, 'E310000000902', 'ENVIO_INCIERTO', ['meta' => pr_meta(['fase' => 'ENVIO_INCIERTO'])]);
$r = PosVenta::reenviarPendientes(new posModel());
wa_chk('revisa 2, acepta 2, sin rechazadas ni pendientes',
    $r === ['revisadas' => 2, 'aceptadas' => 2, 'rechazadas' => [], 'pendientes' => 0], $r);
$tags = pr_tags($srv['mock']);
wa_chk('la E32 (PENDIENTE_ENVIO) consulta y recibe por RFCE; la E31 (ENVIO_INCIERTO) por trackIds y Recepcion',
    $tags === ['rfce:consulta', 'rfce:recepcion', 'ecf:trackids', 'ecf:recepcion'], $tags);
wa_chk('E32: RFCE_ACEPTADO, rfce_estado ACEPTADO y envio_pendiente 0',
    pr_fila($pdo, $e32) === ['estado' => 'RFCE_ACEPTADO', 'pendiente' => 0, 'track' => null, 'rfce' => 'ACEPTADO'], pr_fila($pdo, $e32));
wa_chk('E31: ACEPTADO con el track_id de la DGII y envio_pendiente 0',
    pr_fila($pdo, $e31) === ['estado' => 'ACEPTADO', 'pendiente' => 0, 'track' => 'track-E310000000902', 'rfce' => null], pr_fila($pdo, $e31));
wa_chk('la auditoria dejo POS_VENTA_ENVIADA por cada una', pr_auditorias($pdo, 'POS_VENTA_ENVIADA') === 2, pr_auditorias($pdo, 'POS_VENTA_ENVIADA'));

// ---------------------------------------------------------------------------
// P2) Solo las ventas del POS con envio_pendiente = 1.
// ---------------------------------------------------------------------------
echo "P2) solo las ventas del POS con envio_pendiente = 1\n";
pr_reiniciar($pdo, $srv, 'acepta');
$final = pr_venta($pdo, 'E310000000911', 'ACEPTADO', ['track' => 'track-E310000000911', 'pendiente' => 0]);
$ajena = pr_venta($pdo, 'E310000000912', 'ENVIADO', ['track' => 'track-E310000000912', 'pos' => false]);
$venta = pr_venta($pdo, 'E310000000913', 'ENVIADO', ['track' => 'track-E310000000913']);
$r = PosVenta::reenviarPendientes(new posModel());
wa_chk('revisa solo la venta del POS pendiente; la que no es del POS no cuenta como pendiente',
    $r['revisadas'] === 1 && $r['aceptadas'] === 1 && $r['pendientes'] === 0, $r);
$tags = pr_tags($srv['mock']);
wa_chk('con trackId consulta el resultado y no reenvia nada', $tags === ['ecf:resultado'], $tags);
wa_chk('la venta quedo ACEPTADO con envio_pendiente 0',
    pr_fila($pdo, $venta)['estado'] === 'ACEPTADO' && pr_fila($pdo, $venta)['pendiente'] === 0, pr_fila($pdo, $venta));
wa_chk('la fila final y la que no es del POS no se tocaron',
    pr_fila($pdo, $final)['estado'] === 'ACEPTADO' && pr_fila($pdo, $ajena)['estado'] === 'ENVIADO' && pr_fila($pdo, $ajena)['pendiente'] === 1,
    [pr_fila($pdo, $final), pr_fila($pdo, $ajena)]);

// ---------------------------------------------------------------------------
// P3) RF3: una venta PENDIENTE_ENVIO reciente sigue en curso.
// ---------------------------------------------------------------------------
echo "P3) RF3: el reenvio no toca una PENDIENTE_ENVIO de menos de 2 minutos\n";
pr_reiniciar($pdo, $srv, 'trackids_vacio');
$reciente = date('Y-m-d H:i:s', time() - 30);
$joven = pr_venta($pdo, 'E310000000921', 'PENDIENTE_ENVIO', ['meta' => pr_meta(['desde' => $reciente])]);
$incierto = pr_venta($pdo, 'E310000000922', 'ENVIO_INCIERTO', ['meta' => pr_meta(['fase' => 'ENVIO_INCIERTO', 'desde' => $reciente])]);
$r = PosVenta::reenviarPendientes(new posModel());
$tags = pr_tags($srv['mock']);
wa_chk('revisa solo la ENVIO_INCIERTO (su envio ya termino); la reciente sigue contando como pendiente',
    $r['revisadas'] === 1 && $r['aceptadas'] === 1 && $r['pendientes'] === 1, $r);
wa_chk('la DGII recibio una sola vez: la de la ENVIO_INCIERTO', $tags === ['ecf:trackids', 'ecf:recepcion'], $tags);
wa_chk('la PENDIENTE_ENVIO reciente sigue igual',
    pr_fila($pdo, $joven) === ['estado' => 'PENDIENTE_ENVIO', 'pendiente' => 1, 'track' => null, 'rfce' => null], pr_fila($pdo, $joven));
$pdo->prepare('UPDATE facturas SET respuesta_dgii = :m WHERE id = :id')->execute([
    ':m' => json_encode(pr_meta(['desde' => date('Y-m-d H:i:s', time() - (PosVenta::REENVIO_ESPERA_SEG + 1))])), ':id' => $joven,
]);
$r = PosVenta::reenviarPendientes(new posModel());
wa_chk('pasados los 2 minutos la toma y la acepta',
    $r['revisadas'] === 1 && $r['aceptadas'] === 1 && pr_fila($pdo, $joven)['estado'] === 'ACEPTADO', [$r, pr_fila($pdo, $joven)]);
$sinMeta = pr_venta($pdo, 'E310000000923', 'PENDIENTE_ENVIO');
$r = PosVenta::reenviarPendientes(new posModel());
wa_chk('sin desde en la meta no hay como saber que es reciente: se revisa',
    $r['revisadas'] === 1 && pr_fila($pdo, $sinMeta)['estado'] === 'ACEPTADO', [$r, pr_fila($pdo, $sinMeta)]);

// ---------------------------------------------------------------------------
// P4) RF5: las ventas sin_rango esperan un rango autorizado, vigente, que las cubra.
// ---------------------------------------------------------------------------
echo "P4) RF5: las ventas sin_rango esperan un rango que las cubra\n";
$ids = pr_reiniciar($pdo, $srv, 'trackids_vacio');
// E32 agotado: el rango 1..100 y tres ventas numeradas despues (101, 102 y 103).
$pdo->exec("UPDATE ncf_sequences SET numero_hasta = 100, current_value = 103 WHERE type = 'E32' AND ambiente = 'certecf'");
$sinRango = static fn(int $n): array => ['meta' => pr_meta(['flujo' => 'RFCE', 'sin_rango' => true, 'secuencia_valor' => $n])];
$cubiertas = [];
foreach ([101, 102, 103] as $n) {
    $cubiertas[$n] = pr_venta($pdo, 'E32' . str_pad((string) $n, 10, '0', STR_PAD_LEFT), 'PENDIENTE_ENVIO', $sinRango($n));
}
// Mas sin_rango que lo que cabe en una pasada, fuera de lo que cubrira el rango nuevo: no pueden dejar sin
// turno a las demas ventas pendientes.
$fuera = PosVenta::REENVIO_CANDIDATAS + 2;
for ($n = 201; $n < 201 + $fuera; $n++) {
    pr_venta($pdo, 'E32' . str_pad((string) $n, 10, '0', STR_PAD_LEFT), 'PENDIENTE_ENVIO', $sinRango($n));
}
$e31 = pr_venta($pdo, 'E310000000930', 'ENVIO_INCIERTO', ['meta' => pr_meta(['fase' => 'ENVIO_INCIERTO'])]);
$r = PosVenta::reenviarPendientes(new posModel());
$tags = pr_tags($srv['mock']);
wa_chk('revisa solo la E31 (tiene rango): las E32 sin_rango se saltan y la DGII no recibe ninguna E32',
    $r['revisadas'] === 1 && $r['aceptadas'] === 1 && pr_fila($pdo, $e31)['estado'] === 'ACEPTADO' && $tags === ['ecf:trackids', 'ecf:recepcion'],
    [$r, $tags]);
wa_chk('las sin_rango siguen PENDIENTE_ENVIO con envio_pendiente 1 y cuentan como pendientes',
    $r['pendientes'] === 3 + $fuera && pr_fila($pdo, $cubiertas[101]) === ['estado' => 'PENDIENTE_ENVIO', 'pendiente' => 1, 'track' => null, 'rfce' => null],
    [$r['pendientes'], pr_fila($pdo, $cubiertas[101])]);
// El rango nuevo cubre 101..200 pero ya vencio: no cuenta.
$pdo->prepare("INSERT INTO ncf_sequences (type, prefix, current_value, numero_desde, numero_hasta, fecha_vencimiento, description, ambiente)
               VALUES ('E32', 'E32', 100, 101, 200, :v, 'WA rango nuevo', 'certecf')")->execute([':v' => date('Y-m-d', strtotime('-1 day'))]);
$contadores = pr_contadores($pdo);
$r = PosVenta::reenviarPendientes(new posModel());
wa_chk('con el rango nuevo vencido tampoco se envian', $r['revisadas'] === 0 && pr_tags($srv['mock']) === [], $r);
// El admin lo deja vigente.
$pdo->exec("UPDATE ncf_sequences SET fecha_vencimiento = DATE_ADD(CURDATE(), INTERVAL 1 YEAR) WHERE type = 'E32' AND numero_desde = 101");
$r = PosVenta::reenviarPendientes(new posModel());
$tags = pr_tags($srv['mock']);
wa_chk('con el rango vigente envia las tres que cubre y ninguna de las de afuera',
    $r['revisadas'] === 3 && $r['aceptadas'] === 3 && $r['pendientes'] === $fuera
    && $tags === ['rfce:consulta', 'rfce:recepcion', 'rfce:consulta', 'rfce:recepcion', 'rfce:consulta', 'rfce:recepcion'], [$r, $tags]);
wa_chk('las tres quedan RFCE_ACEPTADO con envio_pendiente 0',
    array_map(static fn(int $id): array => pr_fila($pdo, $id), array_values($cubiertas))
    === array_fill(0, 3, ['estado' => 'RFCE_ACEPTADO', 'pendiente' => 0, 'track' => null, 'rfce' => 'ACEPTADO']),
    array_map(static fn(int $id): array => pr_fila($pdo, $id), array_values($cubiertas)));
wa_chk('el reenvio no movio ningun contador de E32', pr_contadores($pdo) === $contadores, [pr_contadores($pdo), $contadores]);

// ---------------------------------------------------------------------------
// P5) RF5, segunda mitad: con el rango registrado, un comprobante nuevo no recibe un numero ya usado.
// Sigue en el estado de P4: rango 101..200 con el contador en 100 y 101-103 ya ocupados.
// ---------------------------------------------------------------------------
echo "P5) RF5: el siguiente comprobante salta los numeros usados fuera de rango\n";
wa_mock_modo($srv['mock'], 'acepta');
$r = wa_http('POST', $srv['api'] . '/api/facturas', ['tipo_ecf' => '32', 'client_id' => $ids['client_id'], 'tipo_pago' => 1, 'items' => [[
    'nombre_item' => 'WA linea libre', 'indicador_facturacion' => 1, 'indicador_bien_servicio' => 1, 'cantidad' => 1, 'precio_unitario' => 1000,
]]], ['X-API-KEY: ' . $srv['token']], 60);
wa_chk('200 y el numero es el primero libre del rango nuevo: 101 a 103 ya estan usados',
    $r['status'] === 200 && ($r['json']['data']['e_ncf'] ?? null) === 'E320000000104', $r);
wa_chk('el contador del rango nuevo quedo en 104', (pr_contadores($pdo)[101] ?? null) === 104, pr_contadores($pdo));

// ---------------------------------------------------------------------------
// P6) Un rechazo que llega tarde no devuelve el numero.
// ---------------------------------------------------------------------------
echo "P6) un rechazo tardio no devuelve el numero\n";
pr_reiniciar($pdo, $srv, 'trackids_rechazado');
$pdo->exec("UPDATE ncf_sequences SET current_value = 5 WHERE type = 'E31' AND ambiente = 'certecf'");
$rech = pr_venta($pdo, 'E310000000005', 'ENVIO_INCIERTO', ['meta' => pr_meta(['fase' => 'ENVIO_INCIERTO', 'secuencia_valor' => 5])]);
$r = PosVenta::reenviarPendientes(new posModel());
wa_chk('rechazadas trae factura_id, e_ncf y el motivo de la DGII',
    $r['revisadas'] === 1 && $r['aceptadas'] === 0 && $r['pendientes'] === 0
    && $r['rechazadas'] === [['factura_id' => $rech, 'e_ncf' => 'E310000000005', 'motivo' => 'Rechazo de prueba del mock DGII.']], $r);
wa_chk('RECHAZADO con el trackId de la DGII y envio_pendiente 0',
    pr_fila($pdo, $rech) === ['estado' => 'RECHAZADO', 'pendiente' => 0, 'track' => 'track-E310000000005', 'rfce' => null], pr_fila($pdo, $rech));
wa_chk('el contador de E31 sigue en 5: el numero no vuelve', wa_contador($pdo, 'E31') === 5, wa_contador($pdo, 'E31'));
wa_chk('la auditoria dejo POS_VENTA_RECHAZADA', pr_auditorias($pdo, 'POS_VENTA_RECHAZADA') === 1, pr_auditorias($pdo, 'POS_VENTA_RECHAZADA'));

// ---------------------------------------------------------------------------
// P7) posModel: compare-and-set, con envio_pendiente en la misma sentencia.
// ---------------------------------------------------------------------------
echo "P7) posModel: escrituras compare-and-set\n";
pr_reiniciar($pdo, $srv, 'acepta');
$pos = new posModel();
$a = pr_venta($pdo, 'E310000000941', 'ENVIO_INCIERTO', ['meta' => pr_meta(['fase' => 'ENVIO_INCIERTO'])]);
wa_chk('con otro estado leido no escribe y devuelve false',
    $pos->marcarEnvioECF($a, 'PENDIENTE_ENVIO', 'ACEPTADO', ['codigo' => 1], 'T1') === false
    && pr_fila($pdo, $a) === ['estado' => 'ENVIO_INCIERTO', 'pendiente' => 1, 'track' => null, 'rfce' => null], pr_fila($pdo, $a));
wa_chk('veredicto abierto (ENVIADO): escribe, deja envio_pendiente en 1 y guarda el trackId',
    $pos->marcarEnvioECF($a, 'ENVIO_INCIERTO', 'ENVIADO', null, 'T1') === true
    && pr_fila($pdo, $a) === ['estado' => 'ENVIADO', 'pendiente' => 1, 'track' => 'T1', 'rfce' => null], pr_fila($pdo, $a));
wa_chk('veredicto final: envio_pendiente 0 en la misma sentencia y el trackId se conserva',
    $pos->marcarEnvioECF($a, 'ENVIADO', 'ACEPTADO', ['codigo' => 1], null) === true
    && pr_fila($pdo, $a) === ['estado' => 'ACEPTADO', 'pendiente' => 0, 'track' => 'T1', 'rfce' => null], pr_fila($pdo, $a));
wa_chk('un estado final no se pisa: este reenvio llegaba tarde',
    $pos->marcarEnvioECF($a, 'ENVIADO', 'RECHAZADO', ['codigo' => 2], null) === false && pr_fila($pdo, $a)['estado'] === 'ACEPTADO', pr_fila($pdo, $a));
$b = pr_venta($pdo, 'E320000000942', 'PENDIENTE_ENVIO', ['meta' => pr_meta(['flujo' => 'RFCE'])]);
wa_chk('RFCE: con otro estado leido no escribe',
    $pos->marcarEnvio($b, 'ENVIO_INCIERTO', 'RFCE_RECHAZADO', ['mensajes' => []]) === false && pr_fila($pdo, $b)['estado'] === 'PENDIENTE_ENVIO', pr_fila($pdo, $b));
wa_chk('RFCE: un rechazo escribe el estado, rfce_estado sin prefijo y envio_pendiente 0',
    $pos->marcarEnvio($b, 'PENDIENTE_ENVIO', 'RFCE_RECHAZADO', ['mensajes' => []]) === true
    && pr_fila($pdo, $b) === ['estado' => 'RFCE_RECHAZADO', 'pendiente' => 0, 'track' => null, 'rfce' => 'RECHAZADO'], pr_fila($pdo, $b));

// ---------------------------------------------------------------------------
// P8) El texto del codigo: sin prefijo de estado, sin escrituras sin compare-and-set, sin devolver numeros.
// ---------------------------------------------------------------------------
echo "P8) el codigo del reenvio\n";
$leer = static fn(string $ruta): string => str_replace("\r\n", "\n", (string) file_get_contents(__DIR__ . '/../' . $ruta));
$pv = $leer('src/Pos/PosVenta.php');
$pm = $leer('src/Models/posModel.php');
$region = (string) preg_replace('/^.*?public static function reenviarPendientes\(/s', '', $pv);
$region = (string) preg_replace('/private static function estadoDeConsulta\(.*$/s', '', $region);
wa_chk('PosVenta decide RFCE por rfce_xml y no por el prefijo del estado',
    str_contains($region, '$esRfce = !empty($v[\'rfce_xml\']);') && !str_contains($region, "str_starts_with((string) \$v['estado_dgii'], 'RFCE_')"));
wa_chk('el reenvio no devuelve numeros al contador', !str_contains($region, 'rollbackECFSequence') && !str_contains($region, 'reclamarSecuencia'));
wa_chk('posModel: los dos UPDATE del reenvio llevan AND estado_dgii = :leido y envio_pendiente = :p',
    substr_count($pm, 'AND estado_dgii = :leido') === 2 && substr_count($pm, 'envio_pendiente = :p') === 2);

// ---------------------------------------------------------------------------
// P9) El candado de la clave: una venta cuya peticion sigue viva no se toca.
// ---------------------------------------------------------------------------
echo "P9) el candado de la clave: la venta en curso no se toca\n";
pr_reiniciar($pdo, $srv, 'trackids_vacio');
$enCurso = pr_venta($pdo, 'E310000000951', 'ENVIO_INCIERTO', ['meta' => pr_meta(['fase' => 'ENVIO_INCIERTO'])]);
$otraVenta = pr_venta($pdo, 'E310000000952', 'ENVIO_INCIERTO', ['meta' => pr_meta(['fase' => 'ENVIO_INCIERTO'])]);
$claveDe = static function (int $id) use ($pdo): string {
    $st = $pdo->prepare('SELECT pos_idempotency_key FROM facturas WHERE id = :id');
    $st->execute([':id' => $id]);
    return (string) $st->fetchColumn();
};
// Otra peticion: un posModel con su propia conexion. Toma y suelta el candado con los mismos metodos que cobrar,
// asi el nombre del candado es el de posModel::bloquearClave.
$otra = (new ReflectionClass(posModel::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(posModel::class, 'conexion'))->setValue($otra, wa_conectar($base));
wa_chk('otra conexion (la peticion de la venta) toma el candado de la mas vieja', $otra->bloquearClave($claveDe($enCurso), 0) === true);
$r = PosVenta::reenviarPendientes(new posModel(), 1);
$tags = pr_tags($srv['mock']);
wa_chk('con max 1 y la mas vieja en curso: se salta sin gastar el cupo, revisa la otra y la saltada sigue pendiente',
    $r['revisadas'] === 1 && $r['aceptadas'] === 1 && $r['pendientes'] === 1 && pr_fila($pdo, $otraVenta)['estado'] === 'ACEPTADO', $r);
wa_chk('la venta en curso no se toco y la DGII no recibio nada de ella (solo trackIds y Recepcion de la otra)',
    pr_fila($pdo, $enCurso) === ['estado' => 'ENVIO_INCIERTO', 'pendiente' => 1, 'track' => null, 'rfce' => null]
    && $tags === ['ecf:trackids', 'ecf:recepcion'], [pr_fila($pdo, $enCurso), $tags]);
wa_chk('el reenvio solto el candado de la que proceso: otra conexion lo toma sin esperar',
    $otra->bloquearClave($claveDe($otraVenta), 0) === true);
$otra->liberarClave($claveDe($otraVenta));
$otra->liberarClave($claveDe($enCurso));
$r = PosVenta::reenviarPendientes(new posModel());
wa_chk('terminada la peticion (candado libre) el reenvio la toma y la acepta',
    $r['revisadas'] === 1 && $r['aceptadas'] === 1 && $r['pendientes'] === 0 && pr_fila($pdo, $enCurso)['estado'] === 'ACEPTADO',
    [$r, pr_fila($pdo, $enCurso)]);
wa_chk('PosVenta pide el candado sin esperar y lo suelta al terminar con la fila',
    str_contains($region, '$pos->bloquearClave($clave, 0)') && str_contains($region, '$pos->liberarClave($clave)'));

// ---------------------------------------------------------------------------
// P10) La consulta de la app (facturaModel::updateECFEstado) deja envio_pendiente en el mismo UPDATE, solo en
// filas del POS: una venta que la app resuelve antes que el reenvio sale de la lista de pendientes.
// ---------------------------------------------------------------------------
echo "P10) facturaModel::updateECFEstado: envio_pendiente solo en filas del POS\n";
pr_reiniciar($pdo, $srv, 'acepta');
$facturas = new facturaModel();
$aceptada = pr_venta($pdo, 'E310000000961', 'ENVIADO', ['track' => 'track-E310000000961']);
$abierta = pr_venta($pdo, 'E310000000962', 'ENVIADO', ['track' => 'track-E310000000962']);
$perdida = pr_venta($pdo, 'E310000000963', 'ENVIADO', ['track' => 'track-E310000000963']);
$deLaApp = pr_venta($pdo, 'E310000000964', 'ENVIADO', ['track' => 'track-E310000000964', 'pos' => false]);
wa_chk('POS: un veredicto final (ACEPTADO) deja envio_pendiente en 0 en el mismo UPDATE',
    $facturas->updateECFEstado($aceptada, 'ACEPTADO', ['codigo' => 1]) === true
    && pr_fila($pdo, $aceptada) === ['estado' => 'ACEPTADO', 'pendiente' => 0, 'track' => 'track-E310000000961', 'rfce' => null], pr_fila($pdo, $aceptada));
wa_chk('POS: NO_ENCONTRADO tambien da 0 (ese numero no se reenvia solo)',
    $facturas->updateECFEstado($perdida, 'NO_ENCONTRADO', ['codigo' => 0]) === true
    && pr_fila($pdo, $perdida)['estado'] === 'NO_ENCONTRADO' && pr_fila($pdo, $perdida)['pendiente'] === 0, pr_fila($pdo, $perdida));
wa_chk('POS: un estado sin veredicto (EN_PROCESO) la deja pendiente',
    $facturas->updateECFEstado($abierta, 'EN_PROCESO', ['codigo' => 3]) === true
    && pr_fila($pdo, $abierta)['estado'] === 'EN_PROCESO' && pr_fila($pdo, $abierta)['pendiente'] === 1, pr_fila($pdo, $abierta));
wa_chk('fila que no es del POS: cambia el estado y envio_pendiente no se toca',
    $facturas->updateECFEstado($deLaApp, 'ACEPTADO', ['codigo' => 1]) === true
    && pr_fila($pdo, $deLaApp)['estado'] === 'ACEPTADO' && pr_fila($pdo, $deLaApp)['pendiente'] === 1, pr_fila($pdo, $deLaApp));
wa_chk('el reenvio ya no cuenta como pendientes las que la app resolvio: solo queda la EN_PROCESO',
    (new posModel())->contarPendientes() === 1, (new posModel())->contarPendientes());

// Deja la base como recien sembrada (sin los rangos de prueba).
pr_reiniciar($pdo, $srv, 'acepta');
wa_fin();
