<?php
/**
 * test_reservar_confirmar.php — El modelo del write-ahead del e-CF (la reserva,
 * la confirmacion y la marca de envio incierto de facturaModel) contra una base
 * MySQL de prueba (spec del write-ahead, 12.3.4). Nunca produccion:
 * wa_usar_base() se niega a usar una base sin la tabla marca
 * zz_write_ahead_scratch.
 *
 * Uso:
 *   php -d extension=curl -d extension=openssl -d extension=pdo_mysql tools/test_reservar_confirmar.php \
 *       --tenant-db=wa_scratch --copia-db=wa_scratch_sin_gate
 *   (un -d extension=... solo para lo que tu php.ini no carga; ver write_ahead_test_lib.php)
 *
 * --copia-db es la copia sin 027/028 que deja tools/test_write_ahead_gate.php.
 *
 * Modo hijo (lo lanza esta misma prueba; no se corre a mano). Imprime una
 * linea JSON {"resultado": "ok"|"ocupado"|"error", ...}:
 *   --hijo=reservar --tenant-db=<base> --encf=<e-NCF> --cliente=<id> --producto=<id> --listo=<archivo> --barrera=<archivo>
 *   --hijo=bloquear --tenant-db=<base> --encf=<e-NCF> --listo=<archivo> --barrera=<archivo>
 */

require_once __DIR__ . '/write_ahead_test_lib.php';
require_once __DIR__ . '/../src/Models/facturaModel.php';

const RC_AMBIENTE = 'certecf';

/** $facturaInput del controller (sin no_factura), con una linea por producto. */
function rc_factura(int $clientId, array $productos): array
{
    $items = [];
    foreach ($productos as $productId) {
        $items[] = [
            'product_id' => $productId, 'description' => 'WA producto', 'amount' => 100.0, 'quantity' => 1.0,
            'subtotal' => 100.0, 'descuento_monto' => 0.0, 'indicador_facturacion' => 1,
            'indicador_bien_servicio' => 1, 'unidad_medida' => '43', 'itbis_amount' => 18.0,
        ];
    }
    return [
        'date' => date('Y-m-d H:i:s'), 'client_id' => $clientId, 'client_name' => 'WA cliente',
        'total' => 118.0 * count($items), 'tipo_pago' => 1, 'user_id' => null,
        'informacion_referencia' => null, 'items' => $items,
    ];
}

/** El ctx 'reservar' de ECFEmissionService (sus 13 claves) para $eNcf, con $cambios encima. */
function rc_ctx(string $eNcf, array $cambios = []): array
{
    return $cambios + [
        'tipo_ecf' => substr($eNcf, 1, 2), 'e_ncf' => $eNcf, 'ambiente' => RC_AMBIENTE, 'ambiente_secuencia' => RC_AMBIENTE,
        'signed_xml' => '<ECF><Encabezado><IdDoc><eNCF>' . $eNcf . '</eNCF></IdDoc></Encabezado></ECF>',
        'codigo_seguridad' => 'WaPr01', 'fecha_emision_dgii' => date('Y-m-d H:i:s'), 'flujo' => 'ECF',
        'rfce_xml' => null, 'dispensado' => true, 'rango_id' => 7, 'secuencia_valor' => (int) substr($eNcf, 3),
        'desde' => date('Y-m-d H:i:s'),
    ];
}

/** Una fila que ya tiene el numero (otra emision, otro ambiente...): devuelve su id. */
function rc_fila_previa(PDO $pdo, string $eNcf, string $estado, string $ambiente = RC_AMBIENTE): int
{
    $st = $pdo->prepare(
        "INSERT INTO facturas (no_factura, client_name, total, tipo_ecf, e_ncf, estado_dgii, ambiente_dgii)
         VALUES (:nf, 'WA previa', 0, :tipo, :e, :estado, :amb)"
    );
    $st->execute([':nf' => $eNcf, ':tipo' => substr($eNcf, 1, 2), ':e' => $eNcf, ':estado' => $estado, ':amb' => $ambiente]);
    return (int) $pdo->lastInsertId();
}

/** wa_con_log: [resultado, excepcion o null, texto del log]. */
function rc_con_log(callable $fn): array
{
    return wa_con_log($fn);
}

/**
 * Una excepcion en una linea para mostrar. De un PDOException solo la clase y
 * los codigos: su mensaje puede traer usuario y host del servidor.
 */
function rc_desc(?Throwable $e): ?string
{
    if ($e === null) {
        return null;
    }
    if ($e instanceof PDOException) {
        return get_class($e) . ' codigo ' . $e->getCode() . ' errno ' . (string) (((array) $e->errorInfo)[1] ?? '?');
    }
    return get_class($e) . ': ' . $e->getMessage();
}

/** Ruta temporal unica que todavia no existe (barreras y salidas de los hijos). */
function rc_tmp(string $nombre): string
{
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wa_rc_' . getmypid() . '_' . $nombre . '_' . bin2hex(random_bytes(4));
}

/** Espera a que exista $archivo (hasta $segundos). */
function rc_esperar(string $archivo, float $segundos = 15.0): bool
{
    $limite = microtime(true) + $segundos;
    while (!is_file($archivo)) {
        if (microtime(true) > $limite) {
            return false;
        }
        usleep(2000);
    }
    return true;
}

/** Lanza este mismo script en modo hijo; la salida va a un archivo temporal. @return array{0:resource,1:string} */
function rc_hijo(array $args): array
{
    $salida = rc_tmp('salida');
    $proc = proc_open(
        array_merge([PHP_BINARY], wa_php_flags(), [__FILE__], $args),
        [0 => ['pipe', 'r'], 1 => ['file', $salida, 'w'], 2 => ['file', $salida . '.err', 'w']],
        $pipes,
        dirname(__DIR__)
    );
    if (!is_resource($proc)) {
        throw new RuntimeException('no se pudo lanzar el hijo ' . implode(' ', $args));
    }
    fclose($pipes[0]);
    return [$proc, $salida];
}

/** Espera al hijo y devuelve su linea JSON (o lo que imprimio, si no es JSON). */
function rc_cerrar(array $hijo): array
{
    [$proc, $salida] = $hijo;
    proc_close($proc);
    $texto = trim((string) @file_get_contents($salida));
    $err = trim((string) @file_get_contents($salida . '.err'));
    @unlink($salida);
    @unlink($salida . '.err');
    $lineas = $texto === '' ? [''] : explode("\n", $texto);
    $json = json_decode((string) end($lineas), true);
    return is_array($json) ? $json : ['resultado' => 'sin_json', 'salida' => $texto, 'stderr' => $err];
}

// ---------------------------------------------------------------------------
// Modo hijo: dos reservas a la vez (barrera) y el lado pesado del deadlock.
// ---------------------------------------------------------------------------
$hijo = wa_arg('hijo');
if ($hijo !== null) {
    try {
        $pdoHijo = wa_usar_base((string) wa_arg('tenant-db'));
        $eNcf = (string) wa_arg('encf');
        if ($hijo === 'reservar') {
            $modelo = new facturaModel();
            $facturaHijo = rc_factura((int) wa_arg('cliente'), [(int) wa_arg('producto')]);
            touch((string) wa_arg('listo'));
            if (!rc_esperar((string) wa_arg('barrera'))) {
                throw new RuntimeException('la barrera no se abrio');
            }
            $r = $modelo->reservarFacturaECF($facturaHijo, rc_ctx($eNcf));
            echo json_encode(['resultado' => 'ok', 'factura_id' => $r['factura_id']]), "\n";
        } elseif ($hijo === 'bloquear') {
            // Toma el mismo gap lock que la reserva (SELECT ... FOR UPDATE de un
            // e-NCF que no existe), se hace pesada (20 filas escritas, para que
            // InnoDB elija al padre como victima) y, cuando el padre ya espera
            // en su INSERT, inserta el mismo e-NCF: deadlock cruzado.
            $pdoHijo->exec('SET SESSION innodb_lock_wait_timeout = 5');
            $pdoHijo->beginTransaction();
            $sel = $pdoHijo->prepare(
                'SELECT id FROM facturas WHERE e_ncf = :e AND (ambiente_dgii = :amb OR ambiente_dgii IS NULL) FOR UPDATE'
            );
            $sel->execute([':e' => $eNcf, ':amb' => RC_AMBIENTE]);
            $peso = $pdoHijo->prepare(
                "INSERT INTO audit_logs (module, action, description) VALUES ('wa_prueba', 'BLOQUEO', 'peso del deadlock')"
            );
            for ($i = 0; $i < 20; $i++) {
                $peso->execute();
            }
            touch((string) wa_arg('listo'));
            if (!rc_esperar((string) wa_arg('barrera'))) {
                throw new RuntimeException('la barrera no se abrio');
            }
            usleep(300000); // el padre ya esta esperando en su INSERT
            rc_fila_previa($pdoHijo, $eNcf, 'ACEPTADO');
            $pdoHijo->commit();
            echo json_encode(['resultado' => 'ok']), "\n";
        } else {
            throw new InvalidArgumentException('modo hijo desconocido: ' . $hijo);
        }
    } catch (EcfNumeroOcupadoException $e) {
        echo json_encode(['resultado' => 'ocupado', 'mensaje' => $e->getMessage()]), "\n";
    } catch (PDOException $e) {
        // Sin getMessage(): un error de acceso trae usuario y host.
        echo json_encode(['resultado' => 'error', 'clase' => get_class($e), 'codigo' => $e->getCode(),
            'errno' => ((array) $e->errorInfo)[1] ?? null]), "\n";
    } catch (Throwable $e) {
        echo json_encode(['resultado' => 'error', 'clase' => get_class($e), 'mensaje' => $e->getMessage()]), "\n";
    }
    exit(0);
}

// ---------------------------------------------------------------------------
// Padre
// ---------------------------------------------------------------------------
$base = wa_arg('tenant-db');
$copia = wa_arg('copia-db');
if ($base === null || $copia === null) {
    fwrite(STDERR, "Uso: php <flags> tools/test_reservar_confirmar.php --tenant-db=<base> --copia-db=<base sin 027/028>\n");
    exit(1);
}
$pdo = wa_usar_base($base);
$sem = wa_sembrar($pdo);
$factura = rc_factura($sem['client_id'], [$sem['product_id']]);

echo "\n== reservarFacturaECF en {$base} ==\n";

// 1) Camino feliz, flujo RFCE (E32 < 250k): fila completa antes de enviar.
wa_limpiar($pdo);
$eNcf = 'E320000000101';
[$r, $e] = rc_con_log(fn() => (new facturaModel())->reservarFacturaECF(
    rc_factura($sem['client_id'], [$sem['product_id'], $sem['product_id']]),
    rc_ctx($eNcf, ['flujo' => 'RFCE', 'rfce_xml' => '<RFCE><eNCF>' . $eNcf . '</eNCF></RFCE>'])
));
$filas = wa_filas($pdo, $eNcf);
$fila = $filas[0] ?? [];
wa_chk('feliz: devuelve el id de la unica fila con ese e-NCF',
    $e === null && count($filas) === 1 && ($r['factura_id'] ?? null) === (int) ($fila['id'] ?? 0) && ($r['e_ncf'] ?? null) === $eNcf,
    $e !== null ? rc_desc($e) : [$r, $filas]);
wa_chk('feliz: PENDIENTE_ENVIO, no_factura = e_ncf, XML y RFCE guardados, sin track_id ni secuencia_utilizada',
    ($fila['estado_dgii'] ?? null) === 'PENDIENTE_ENVIO' && ($fila['no_factura'] ?? null) === $eNcf
    && (int) ($fila['tiene_xml'] ?? 0) === 1 && (int) ($fila['tiene_rfce'] ?? 0) === 1
    && array_key_exists('track_id', $fila) && $fila['track_id'] === null
    && array_key_exists('secuencia_utilizada', $fila) && $fila['secuencia_utilizada'] === null
    && ($fila['ambiente_dgii'] ?? null) === RC_AMBIENTE, $fila);
$extra = $pdo->query('SELECT codigo_seguridad, (SELECT COUNT(*) FROM factura_items WHERE factura_id = f.id) AS lineas
                      FROM facturas f WHERE id = ' . (int) ($fila['id'] ?? 0))->fetch(PDO::FETCH_ASSOC) ?: [];
$meta = facturaModel::metaEnvio($fila['respuesta_dgii'] ?? null);
wa_chk('feliz: codigo_seguridad, las 2 lineas y la meta 6.3 con flujo RFCE',
    ($extra['codigo_seguridad'] ?? null) === 'WaPr01' && (int) ($extra['lineas'] ?? 0) === 2
    && ($meta['fase'] ?? null) === 'PENDIENTE_ENVIO' && ($meta['flujo'] ?? null) === 'RFCE' && ($meta['rango_id'] ?? null) === 7,
    [$extra, $meta]);
wa_chk('feliz: sin transaccion abierta', !$pdo->inTransaction());

// 2) Fila previa ACEPTADO, mismo numero y ambiente: ocupado, nada cambia.
wa_limpiar($pdo);
$eNcf = 'E310000000201';
rc_fila_previa($pdo, $eNcf, 'ACEPTADO');
$antes = wa_filas($pdo, $eNcf);
[, $e] = rc_con_log(fn() => (new facturaModel())->reservarFacturaECF($factura, rc_ctx($eNcf)));
wa_chk('previa ACEPTADO: EcfNumeroOcupadoException', $e instanceof EcfNumeroOcupadoException, rc_desc($e));
wa_chk('previa ACEPTADO: la fila previa intacta y ninguna nueva', wa_filas($pdo, $eNcf) === $antes, wa_filas($pdo, $eNcf));

// 3) Fila previa RECHAZADO: se archiva y la nueva entra en la misma transaccion.
wa_limpiar($pdo);
$eNcf = 'E310000000301';
$previa = rc_fila_previa($pdo, $eNcf, 'RECHAZADO');
[$r, $e] = rc_con_log(fn() => (new facturaModel())->reservarFacturaECF($factura, rc_ctx($eNcf)));
$filas = wa_filas($pdo, $eNcf);
wa_chk('previa RECHAZADO: archivada (RECHAZADO_ARCHIVADO, e_ncf NULL, no_factura igual)',
    $e === null && count($filas) === 2 && (int) $filas[0]['id'] === $previa && $filas[0]['estado_dgii'] === 'RECHAZADO_ARCHIVADO'
    && $filas[0]['e_ncf'] === null && $filas[0]['no_factura'] === $eNcf, $e !== null ? rc_desc($e) : $filas);
wa_chk('previa RECHAZADO: la nueva en PENDIENTE_ENVIO con el e-NCF',
    ($filas[1]['estado_dgii'] ?? null) === 'PENDIENTE_ENVIO' && ($filas[1]['e_ncf'] ?? null) === $eNcf
    && (int) ($filas[1]['id'] ?? 0) === ($r['factura_id'] ?? null), $filas);

// 4) El mismo numero en otro ambiente no ocupa este (uk_e_ncf_amb, migracion 027).
wa_limpiar($pdo);
$eNcf = 'E310000000401';
$otra = rc_fila_previa($pdo, $eNcf, 'ACEPTADO', 'ecf');
[, $e] = rc_con_log(fn() => (new facturaModel())->reservarFacturaECF($factura, rc_ctx($eNcf)));
$filas = wa_filas($pdo, $eNcf);
wa_chk('mismo numero en ecf: la reserva en certecf entra', $e === null && count($filas) === 2,
    $e !== null ? rc_desc($e) : $filas);
wa_chk('mismo numero en ecf: esa fila queda ACEPTADO en ecf',
    (int) ($filas[0]['id'] ?? 0) === $otra && ($filas[0]['estado_dgii'] ?? null) === 'ACEPTADO'
    && ($filas[0]['ambiente_dgii'] ?? null) === 'ecf', $filas);

// 5) Producto que no existe (FK 1452): se relanza, no es "ocupado", no queda nada.
wa_limpiar($pdo);
$eNcf = 'E310000000501';
[, $e] = rc_con_log(fn() => (new facturaModel())->reservarFacturaECF(rc_factura($sem['client_id'], [999999]), rc_ctx($eNcf)));
wa_chk('producto 999999: PDOException 1452, no EcfNumeroOcupadoException',
    $e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1452, rc_desc($e));
wa_chk('producto 999999: ninguna fila con ese e-NCF y nada abierto', wa_filas($pdo, $eNcf) === [] && !$pdo->inTransaction(),
    wa_filas($pdo, $eNcf));

// 6) Dos procesos reservan el mismo numero a la vez (barrera).
wa_limpiar($pdo);
$eNcf = 'E319999990006';
$barrera = rc_tmp('barrera');
$hijos = [];
foreach (['a', 'b'] as $n) {
    $listo = rc_tmp('listo_' . $n);
    $hijos[$n] = [rc_hijo(['--hijo=reservar', '--tenant-db=' . $base, '--encf=' . $eNcf, '--cliente=' . $sem['client_id'],
        '--producto=' . $sem['product_id'], '--listo=' . $listo, '--barrera=' . $barrera]), $listo];
}
foreach ($hijos as [, $listo]) {
    rc_esperar($listo);
    @unlink($listo);
}
touch($barrera);
$resultados = [];
foreach ($hijos as $n => [$h]) {
    $resultados[$n] = rc_cerrar($h);
}
@unlink($barrera);
$tipos = array_column($resultados, 'resultado');
sort($tipos);
wa_chk('dos procesos, mismo numero: uno reserva y el otro queda ocupado', $tipos === ['ocupado', 'ok'], $resultados);
wa_chk('dos procesos: una sola fila con ese e-NCF', count(wa_filas($pdo, $eNcf)) === 1, wa_filas($pdo, $eNcf));

// 7) Deadlock cruzado: el padre es la victima, reintenta una vez y queda ocupado.
wa_limpiar($pdo);
$eNcf = 'E319999990007';
$barrera = rc_tmp('barrera');
$listo = rc_tmp('listo');
$bloqueador = rc_hijo(['--hijo=bloquear', '--tenant-db=' . $base, '--encf=' . $eNcf, '--listo=' . $listo, '--barrera=' . $barrera]);
rc_esperar($listo);
@unlink($listo);
$pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');
touch($barrera);
[$r, $e, $log] = rc_con_log(fn() => (new facturaModel())->reservarFacturaECF($factura, rc_ctx($eNcf)));
$pdo->exec('SET SESSION innodb_lock_wait_timeout = 50');
$resBloqueo = rc_cerrar($bloqueador);
@unlink($barrera);
wa_chk('deadlock: el proceso que bloquea termina bien', ($resBloqueo['resultado'] ?? null) === 'ok', $resBloqueo);
wa_chk('deadlock: una sola linea de reintento en el log',
    substr_count($log, '[ECF e-NCF=' . $eNcf . '] reservar: deadlock (1213), reintento') === 1, $log);
wa_chk('deadlock: el reintento termina en EcfNumeroOcupadoException (no 1205 ni 1213 crudo)',
    $e instanceof EcfNumeroOcupadoException, $e === null ? $r : rc_desc($e)
    . ' (si es 1205, revisa que innodb_deadlock_detect este ON en el servidor de prueba)');
$filas = wa_filas($pdo, $eNcf);
wa_chk('deadlock: solo queda la fila del otro proceso y nada abierto',
    count($filas) === 1 && ($filas[0]['estado_dgii'] ?? null) === 'ACEPTADO' && !$pdo->inTransaction(), $filas);

// 8) Sin la 028 (copia con VARCHAR(20)), modo estricto: archivar NO_ENCONTRADO
//    no cabe (NO_ENCONTRADO_ARCHIVADO = 23). Falla antes de enviar (spec 6.2).
$pdoCopia = wa_usar_base($copia);
wa_limpiar($pdoCopia);
$semCopia = wa_sembrar($pdoCopia);
$eNcf = 'E310000000801';
$previa = rc_fila_previa($pdoCopia, $eNcf, 'NO_ENCONTRADO');
$pdoCopia->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES'");
[, $e] = rc_con_log(fn() => (new facturaModel())->reservarFacturaECF(
    rc_factura($semCopia['client_id'], [$semCopia['product_id']]), rc_ctx($eNcf)
));
$filas = wa_filas($pdoCopia, $eNcf);
wa_chk('copia sin 028: PDOException 1406 (SQLSTATE 22001), no ocupado',
    $e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1406 && ($e->errorInfo[0] ?? '') === '22001',
    rc_desc($e));
wa_chk('copia sin 028: ninguna fila nueva y la previa sigue NO_ENCONTRADO con su e-NCF',
    count($filas) === 1 && (int) $filas[0]['id'] === $previa && $filas[0]['estado_dgii'] === 'NO_ENCONTRADO'
    && $filas[0]['e_ncf'] === $eNcf && !$pdoCopia->inTransaction(), $filas);
wa_limpiar($pdoCopia);

// De vuelta a la base de prueba para los casos que siguen.
$pdo = wa_usar_base($base);
$sem = wa_sembrar($pdo);
$factura = rc_factura($sem['client_id'], [$sem['product_id']]);

// ---------------------------------------------------------------------------
// confirmarFacturaECF, marcarEnvioIncierto y existeFactura
// ---------------------------------------------------------------------------

/** Lo que el modelo lee del ctx 'confirmar', con $cambios encima. */
function rc_confirmar(array $cambios = []): array
{
    return $cambios + [
        'estado' => 'ACEPTADO', 'track_id' => null, 'dgii_response' => null, 'rfce_track_id' => null,
        'rfce_estado' => null, 'rfce_response' => null, 'secuencia_utilizada' => null,
    ];
}

/** ctx 'incierto' de una reserva; trae signed_xml, rfce_xml y xml_firmado a proposito. */
function rc_incierto(string $eNcf, array $reserva): array
{
    return rc_ctx($eNcf, ['rfce_xml' => '<RFCE><eNCF>' . $eNcf . '</eNCF></RFCE>']) + [
        'xml_firmado' => '<ECF/>', 'reserva' => $reserva, 'fase_fallida' => 'envio', 'clase' => 'RuntimeException',
        'error' => 'HTTP request failed: Operation timed out after 3001 milliseconds', 'resultado_parcial' => null,
    ];
}

echo "\n== confirmarFacturaECF, marcarEnvioIncierto y existeFactura en {$base} ==\n";
wa_limpiar($pdo);
$modelo = new facturaModel();

// Confirmar una reserva: el estado, el track_id, la secuencia y la respuesta.
$eNcf = 'E310000000901';
$reserva = $modelo->reservarFacturaECF($factura, rc_ctx($eNcf));
$respuesta = ['trackId' => 'track-x', 'codigo' => 1, 'estado' => 'Aceptado', 'secuenciaUtilizada' => true];
$ok = $modelo->confirmarFacturaECF($reserva['factura_id'], rc_confirmar([
    'estado' => 'ACEPTADO', 'track_id' => 'track-x', 'dgii_response' => $respuesta, 'secuencia_utilizada' => true,
]));
$fila = wa_filas($pdo, $eNcf)[0] ?? [];
wa_chk('confirmar ACEPTADO sobre PENDIENTE_ENVIO: true', $ok === true);
wa_chk('confirmar: ACEPTADO, track-x, secuencia_utilizada 1 y respuesta_dgii = JSON de la respuesta',
    ($fila['estado_dgii'] ?? null) === 'ACEPTADO' && ($fila['track_id'] ?? null) === 'track-x'
    && ($fila['secuencia_utilizada'] ?? null) !== null && (int) $fila['secuencia_utilizada'] === 1
    && json_decode((string) ($fila['respuesta_dgii'] ?? ''), true) === $respuesta, $fila);
wa_chk('segundo confirmar sobre la misma fila: false (ya no esta en vuelo)',
    $modelo->confirmarFacturaECF($reserva['factura_id'], rc_confirmar(['estado' => 'RECHAZADO'])) === false);
$antes = wa_filas($pdo, $eNcf);
wa_chk('marcar incierto una fila confirmada: false y la fila no cambia',
    $modelo->marcarEnvioIncierto($reserva['factura_id'], rc_incierto($eNcf, $reserva)) === false
    && wa_filas($pdo, $eNcf) === $antes, wa_filas($pdo, $eNcf));

// Marcar incierto una reserva: el JSON de 6.3, sin XML.
$eNcf = 'E310000000902';
$reserva = $modelo->reservarFacturaECF($factura, rc_ctx($eNcf));
$ok = $modelo->marcarEnvioIncierto($reserva['factura_id'], rc_incierto($eNcf, $reserva));
$fila = wa_filas($pdo, $eNcf)[0] ?? [];
$json = (string) ($fila['respuesta_dgii'] ?? '');
$meta = json_decode($json, true);
wa_chk('marcar incierto desde PENDIENTE_ENVIO: true y la fila en ENVIO_INCIERTO',
    $ok === true && ($fila['estado_dgii'] ?? null) === 'ENVIO_INCIERTO', $fila);
wa_chk('marcar: respuesta_dgii es el JSON de 6.3 (claves en orden, intentos 0)',
    is_array($meta) && array_keys($meta) === ['fase', 'desde', 'flujo', 'ambiente_secuencia', 'dispensado', 'rango_id',
        'secuencia_valor', 'fase_fallida', 'clase', 'error', 'resultado_parcial', 'intentos']
    && $meta['fase'] === 'ENVIO_INCIERTO' && $meta['intentos'] === 0 && $meta['secuencia_valor'] === 902, $meta);
wa_chk('marcar: sin signed_xml, rfce_xml, xml_firmado ni XML, y cabe en TEXT (< 65535)',
    $json !== '' && !preg_match('/signed_xml|rfce_xml|xml_firmado|</', $json) && strlen($json) < 65535, $json);
wa_chk('confirmar tambien acepta una fila en ENVIO_INCIERTO',
    $modelo->confirmarFacturaECF($reserva['factura_id'], rc_confirmar(['estado' => 'ACEPTADO', 'track_id' => 'track-y'])) === true
    && (wa_filas($pdo, $eNcf)[0]['estado_dgii'] ?? null) === 'ACEPTADO');

// Modo estricto: secuencia_utilizada = false se guarda como 0 (no '').
$modoPrevio = (string) $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
$pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES'");
$eNcf = 'E310000000903';
$reserva = $modelo->reservarFacturaECF($factura, rc_ctx($eNcf));
[$ok, $e] = rc_con_log(fn() => $modelo->confirmarFacturaECF($reserva['factura_id'], rc_confirmar([
    'estado' => 'RECHAZADO', 'dgii_response' => ['codigo' => 2, 'estado' => 'Rechazado', 'secuenciaUtilizada' => false],
    'secuencia_utilizada' => false,
])));
$fila = wa_filas($pdo, $eNcf)[0] ?? [];
$pdo->prepare('SET SESSION sql_mode = :modo')->execute([':modo' => $modoPrevio]);
wa_chk('modo estricto: confirmar con secuencia_utilizada false -> true y la columna guarda 0',
    $e === null && $ok === true && ($fila['secuencia_utilizada'] ?? null) !== null && (int) $fila['secuencia_utilizada'] === 0,
    $e !== null ? rc_desc($e) : $fila);

wa_chk('existeFactura: true para la reserva y false para 999999',
    $modelo->existeFactura($reserva['factura_id']) === true && $modelo->existeFactura(999999) === false);

// ---------------------------------------------------------------------------
// dispenseNextECF lanza si ncf_sequences falla; rollbackECFSequence deja rastro
// (Task 10). Se renombra la tabla en la base de prueba y se devuelve a su
// nombre en finally.
// ---------------------------------------------------------------------------
require_once __DIR__ . '/../src/Models/ncfModel.php';

echo "\n== ncf_sequences inaccesible ==\n";
// Otra vez la base de prueba principal: un caso anterior pudo dejar Database
// apuntando a la copia (--copia-db).
$conNcf = wa_usar_base((string) wa_arg('tenant-db'));
$hayTabla = static function (PDO $c, string $tabla): bool {
    $st = $c->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t');
    $st->execute([':t' => $tabla]);
    return (int) $st->fetchColumn() > 0;
};
// Una corrida anterior cortada a mitad pudo dejar la tabla renombrada.
if (!$hayTabla($conNcf, 'ncf_sequences') && $hayTabla($conNcf, 'ncf_sequences_wa_off')) {
    $conNcf->exec('RENAME TABLE ncf_sequences_wa_off TO ncf_sequences');
}
$ncfPrueba = new ncfModel();
$logNcf = (string) tempnam(sys_get_temp_dir(), 'wa_ncf_');
$logPrevio = ini_get('error_log');
$conNcf->exec('RENAME TABLE ncf_sequences TO ncf_sequences_wa_off');
try {
    $lanzada = null;
    try {
        $ncfPrueba->dispenseNextECF('E31', 'certecf');
    } catch (Throwable $e) {
        $lanzada = $e;
    }
    // Detalle de fallo sin el mensaje completo: el de una PDOException trae usuario y host.
    wa_chk('dispense con ncf_sequences renombrada: RuntimeException, no null',
        $lanzada instanceof RuntimeException, $lanzada ? get_class($lanzada) : 'no lanzo');
    wa_chk("dispense: el mensaje empieza con 'ncf_sequences inaccesible: '",
        $lanzada !== null && str_starts_with($lanzada->getMessage(), 'ncf_sequences inaccesible: '),
        $lanzada !== null ? substr($lanzada->getMessage(), 0, 30) : 'no lanzo');
    wa_chk('dispense: la PDOException queda como getPrevious()', $lanzada?->getPrevious() instanceof PDOException,
        $lanzada !== null ? rc_desc($lanzada->getPrevious()) : 'no lanzo');

    ini_set('error_log', $logNcf);
    $devuelto = $ncfPrueba->rollbackECFSequence('E31', 1, 'certecf', null);
    ini_set('error_log', $logPrevio === false ? '' : $logPrevio);
    $lineasNcf = (string) file_get_contents($logNcf);
    wa_chk('rollback con ncf_sequences renombrada: false', $devuelto === false);
    wa_chk("rollback: el error_log dice '[ECF] rollbackECFSequence fallo: '",
        str_contains($lineasNcf, '[ECF] rollbackECFSequence fallo: '), substr($lineasNcf, 0, 40));
} finally {
    ini_set('error_log', $logPrevio === false ? '' : $logPrevio);
    $conNcf->exec('RENAME TABLE ncf_sequences_wa_off TO ncf_sequences');
    @unlink($logNcf);
}
wa_chk('ncf_sequences volvio a su nombre', $hayTabla($conNcf, 'ncf_sequences') && !$hayTabla($conNcf, 'ncf_sequences_wa_off'));
// Un ambiente sin ninguna fila en ncf_sequences: db/tenant_schema.sql:311-350
// siembra E31-E47 en certecf, ecf y testecf (E45 en :325, :336 y :347) con numero_hasta y
// fecha_vencimiento NULL (sin limite), asi que ningun tipo de esos tres
// ambientes sirve para probar "no hay rango".
wa_chk('sin rango en ese ambiente, dispense sigue devolviendo null', $ncfPrueba->dispenseNextECF('E31', 'wa_sin_rango') === null);

// ---------------------------------------------------------------------------
// Reserva del POS: las columnas de la 030, la venta repetida (1062 sobre
// uk_facturas_pos_idem) y envio_pendiente sincronizado en confirmar e incierto
// (Task 3 del plan POS y reenvio).
// ---------------------------------------------------------------------------
echo "\n== reserva del POS en {$base} ==\n";
$pdo = wa_usar_base($base);
$sem = wa_sembrar($pdo);
$factura = rc_factura($sem['client_id'], [$sem['product_id']]);
$modelo = new facturaModel();
wa_limpiar($pdo);

/** Clave de idempotencia de prueba: un UUID de 36 caracteres que cambia con $n. */
function rc_clave(int $n): string
{
    return sprintf('00000000-0000-4000-8000-%012d', $n);
}

/** Las columnas del POS de una factura (y su estado). */
function rc_pos_cols(PDO $pdo, int $id): array
{
    $st = $pdo->prepare(
        'SELECT turno_id, pos_empleado_id, pos_idempotency_key, envio_pendiente, estado_dgii FROM facturas WHERE id = :id'
    );
    $st->execute([':id' => $id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
}

/** El bloque 'pos' de $facturaInput para la venta numero $n. */
function rc_pos(int $n): array
{
    return ['turno_id' => 7, 'pos_empleado_id' => 8, 'pos_idempotency_key' => rc_clave($n)];
}

// POS 1: la reserva del POS guarda las cuatro columnas y nace con envio_pendiente = 1.
[$r, $e] = rc_con_log(fn() => $modelo->reservarFacturaECF($factura + ['pos' => rc_pos(1)], rc_ctx('E310000009101')));
$cols = rc_pos_cols($pdo, (int) ($r['factura_id'] ?? 0));
wa_chk('POS: la reserva guarda turno, empleado, clave y envio_pendiente = 1 en PENDIENTE_ENVIO',
    $e === null && (int) ($cols['turno_id'] ?? 0) === 7 && (int) ($cols['pos_empleado_id'] ?? 0) === 8
    && ($cols['pos_idempotency_key'] ?? null) === rc_clave(1) && (int) ($cols['envio_pendiente'] ?? 9) === 1
    && ($cols['estado_dgii'] ?? null) === 'PENDIENTE_ENVIO', $e !== null ? rc_desc($e) : $cols);

// POS 2: la reserva de la app deja las columnas del POS como estaban (NULL y 0).
$r = $modelo->reservarFacturaECF($factura, rc_ctx('E310000009102'));
$cols = rc_pos_cols($pdo, $r['factura_id']);
wa_chk('app: la reserva sin pos deja turno, empleado y clave en NULL y envio_pendiente en 0',
    $cols['turno_id'] === null && $cols['pos_empleado_id'] === null && $cols['pos_idempotency_key'] === null
    && (int) $cols['envio_pendiente'] === 0, $cols);

// POS 3: la misma clave con otro numero es una venta repetida: ninguna fila nueva y nada abierto.
$filasAntes = (int) $pdo->query('SELECT COUNT(*) FROM facturas')->fetchColumn();
[, $e] = rc_con_log(fn() => $modelo->reservarFacturaECF($factura + ['pos' => rc_pos(1)], rc_ctx('E310000009103')));
wa_chk('POS repetida: EcfVentaRepetidaException con la clave y el PDOException 1062 como previa (no EcfNumeroOcupadoException)',
    $e instanceof EcfVentaRepetidaException && $e->clave === rc_clave(1) && $e->getPrevious() instanceof PDOException
    && (int) ($e->getPrevious()->errorInfo[1] ?? 0) === 1062, rc_desc($e));
wa_chk('POS repetida: ninguna fila nueva y nada abierto',
    (int) $pdo->query('SELECT COUNT(*) FROM facturas')->fetchColumn() === $filasAntes
    && wa_filas($pdo, 'E310000009103') === [] && !$pdo->inTransaction());

// POS 4: la repetida deshace tambien el archivo de la fila previa del numero nuevo.
$eNcf = 'E310000009104';
$previa = rc_fila_previa($pdo, $eNcf, 'RECHAZADO');
[, $e] = rc_con_log(fn() => $modelo->reservarFacturaECF($factura + ['pos' => rc_pos(1)], rc_ctx($eNcf)));
$filas = wa_filas($pdo, $eNcf);
wa_chk('POS repetida con una previa RECHAZADO del numero: la previa NO queda archivada',
    $e instanceof EcfVentaRepetidaException && count($filas) === 1 && (int) $filas[0]['id'] === $previa
    && $filas[0]['estado_dgii'] === 'RECHAZADO' && $filas[0]['e_ncf'] === $eNcf, $e !== null ? rc_desc($e) : $filas);

// POS 5: otra clave con el numero ocupado por una fila ACEPTADO: sigue siendo "ocupado".
$eNcf = 'E310000009105';
rc_fila_previa($pdo, $eNcf, 'ACEPTADO');
[, $e] = rc_con_log(fn() => $modelo->reservarFacturaECF($factura + ['pos' => rc_pos(5)], rc_ctx($eNcf)));
wa_chk('POS con el numero ocupado: EcfNumeroOcupadoException, no venta repetida',
    $e instanceof EcfNumeroOcupadoException && !($e instanceof EcfVentaRepetidaException), rc_desc($e));

// POS 6: confirmar escribe envio_pendiente en la misma sentencia que el estado, solo en filas del POS.
$numeroConfirmar = 9200;
$pendienteTras = static function (string $estado, bool $esPos) use ($pdo, $modelo, $factura, &$numeroConfirmar): int {
    $numeroConfirmar++;
    $reserva = $modelo->reservarFacturaECF(
        $esPos ? $factura + ['pos' => rc_pos($numeroConfirmar)] : $factura,
        rc_ctx('E31000000' . $numeroConfirmar)
    );
    $modelo->confirmarFacturaECF($reserva['factura_id'], rc_confirmar(['estado' => $estado]));
    return (int) rc_pos_cols($pdo, $reserva['factura_id'])['envio_pendiente'];
};
$obtenido = [];
foreach (['ACEPTADO', 'ENVIADO', 'EN_PROCESO', 'RFCE_ENVIADO', 'RFCE_EN_PROCESO', 'RECHAZADO', 'NO_ENCONTRADO', 'ERROR'] as $estado) {
    $obtenido[$estado] = $pendienteTras($estado, true);
}
wa_chk('POS: confirmar deja envio_pendiente en 1 solo si el veredicto sigue abierto (ENVIADO, EN_PROCESO, RFCE_*)',
    $obtenido === ['ACEPTADO' => 0, 'ENVIADO' => 1, 'EN_PROCESO' => 1, 'RFCE_ENVIADO' => 1, 'RFCE_EN_PROCESO' => 1,
        'RECHAZADO' => 0, 'NO_ENCONTRADO' => 0, 'ERROR' => 0], $obtenido);
wa_chk('app: confirmar nunca pone envio_pendiente (ENVIADO y EN_PROCESO sobre una fila sin clave del POS quedan en 0)',
    $pendienteTras('ENVIADO', false) === 0 && $pendienteTras('EN_PROCESO', false) === 0);

// POS 7: marcar incierto deja envio_pendiente en 1 en la misma sentencia; la app no lo toca.
$eNcf = 'E310000009301';
$reserva = $modelo->reservarFacturaECF($factura + ['pos' => rc_pos(9301)], rc_ctx($eNcf));
$pdo->prepare('UPDATE facturas SET envio_pendiente = 0 WHERE id = :id')->execute([':id' => $reserva['factura_id']]);
$ok = $modelo->marcarEnvioIncierto($reserva['factura_id'], rc_incierto($eNcf, $reserva));
$cols = rc_pos_cols($pdo, $reserva['factura_id']);
wa_chk('POS: marcar incierto deja ENVIO_INCIERTO con envio_pendiente = 1',
    $ok === true && ($cols['estado_dgii'] ?? null) === 'ENVIO_INCIERTO' && (int) ($cols['envio_pendiente'] ?? 9) === 1, $cols);
$eNcf = 'E310000009302';
$reserva = $modelo->reservarFacturaECF($factura, rc_ctx($eNcf));
$ok = $modelo->marcarEnvioIncierto($reserva['factura_id'], rc_incierto($eNcf, $reserva));
$cols = rc_pos_cols($pdo, $reserva['factura_id']);
wa_chk('app: marcar incierto deja ENVIO_INCIERTO y envio_pendiente en 0',
    $ok === true && ($cols['estado_dgii'] ?? null) === 'ENVIO_INCIERTO' && (int) ($cols['envio_pendiente'] ?? 9) === 0, $cols);

// ---------------------------------------------------------------------------
// ncfModel fuera de rango: dispenseSinRango, rangoCubre y registerRange sobre
// numeros ya usados (Task 4 del plan POS y reenvio). Todo en un ambiente propio
// (wa_sr_t4) que se borra antes y despues: no toca los rangos sembrados.
// ---------------------------------------------------------------------------
echo "\n== ncfModel fuera de rango en {$base} ==\n";
$pdo = wa_usar_base($base);
$ncf = new ncfModel();
$ambSr = 'wa_sr_t4';
$vencSr = date('Y-m-d', strtotime('+1 year'));
// Vencido hace 3 dias, no ayer: CURDATE() es la fecha del servidor MySQL, que otra zona horaria
// puede dejar un dia delante o detras de la de PHP durante unas horas; con "ayer" el rango
// seguiria vigente en esas horas.
$vencidoSr = date('Y-m-d', strtotime('-3 days'));
$limpiarSr = static fn() => $pdo->prepare('DELETE FROM ncf_sequences WHERE ambiente = :a')->execute([':a' => $ambSr]);
/** Un rango de prueba en $ambSr; devuelve su id. */
$rangoSr = static function (string $tipo, int $desde, ?int $hasta, int $actual, ?string $venc) use ($pdo, $ambSr): int {
    $pdo->prepare(
        "INSERT INTO ncf_sequences (type, prefix, current_value, numero_desde, numero_hasta, fecha_vencimiento, description, ambiente)
         VALUES (:t, :t2, :cv, :d, :h, :v, 'WA fuera de rango', :a)"
    )->execute([':t' => $tipo, ':t2' => $tipo, ':cv' => $actual, ':d' => $desde, ':h' => $hasta, ':v' => $venc, ':a' => $ambSr]);
    return (int) $pdo->lastInsertId();
};
/** [current_value, numero_hasta] de una fila. */
$filaSr = static function (int $id) use ($pdo): array {
    $st = $pdo->prepare('SELECT current_value, numero_hasta FROM ncf_sequences WHERE id = :id');
    $st->execute([':id' => $id]);
    $f = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    return [(int) ($f['current_value'] ?? -1), isset($f['numero_hasta']) ? (int) $f['numero_hasta'] : null];
};
$limpiarSr();
try {
    // N1 dispenseSinRango: el numero siguiente de un rango agotado, sin el tope numero_hasta.
    $e32 = $rangoSr('E32', 1, 5, 5, $vencSr);
    wa_chk('N1: el rango 1-5 con current_value 5 esta agotado para dispenseNextECF', $ncf->dispenseNextECF('E32', $ambSr) === null);
    $a = $ncf->dispenseSinRango('E32', $ambSr);
    wa_chk('N1: dispenseSinRango da E320000000006 con las claves de dispenseNextECF mas sin_rango',
        array_keys($a ?? []) === ['e_ncf', 'valor', 'rango_id', 'fecha_vencimiento', 'numero_hasta', 'restantes', 'sin_rango']
        && ($a['e_ncf'] ?? null) === 'E320000000006' && ($a['valor'] ?? null) === 6 && $a['rango_id'] === null
        && $a['sin_rango'] === true && $a['numero_hasta'] === 5 && $a['restantes'] === 0 && $a['fecha_vencimiento'] === $vencSr, $a);
    wa_chk('N1: el contador del rango paso de su numero_hasta (6 de 5) y numero_hasta no se toco', $filaSr($e32) === [6, 5], $filaSr($e32));
    $vistos = [$a['valor']];
    for ($i = 0; $i < 20; $i++) {
        $vistos[] = $ncf->dispenseSinRango('E32', $ambSr)['valor'];
    }
    wa_chk('N2: 21 llamadas seguidas dan 6..26, cada numero una sola vez', $vistos === range(6, 26), $vistos);
    wa_chk('N2: el contador queda en 26', $filaSr($e32) === [26, 5], $filaSr($e32));

    // N3 sin filas para el tipo o con un tipo que no es e-CF: null (no hay de donde seguir).
    wa_chk('N3: un tipo sin ninguna fila en ese ambiente da null', $ncf->dispenseSinRango('E33', $ambSr) === null);
    wa_chk('N3: un tipo que no es E## da null', $ncf->dispenseSinRango('B01', $ambSr) === null && $ncf->dispenseSinRango('E3', $ambSr) === null);
    wa_chk('N3: el mismo tipo en OTRO ambiente da null', $ncf->dispenseSinRango('E32', 'wa_sr_otro') === null);

    // N4 con dos rangos agotados sigue el de mayor numero_desde; el otro no se toca.
    $primero = $rangoSr('E34', 1, 10, 10, $vencSr);
    $segundo = $rangoSr('E34', 11, 20, 20, $vencSr);
    $n4 = [];
    for ($i = 0; $i < 4; $i++) {
        $n4[] = $ncf->dispenseSinRango('E34', $ambSr)['e_ncf'];
    }
    wa_chk('N4: con dos rangos agotados toma el de mayor numero_desde (E340000000021..24)',
        $n4 === ['E340000000021', 'E340000000022', 'E340000000023', 'E340000000024'] && $filaSr($segundo) === [24, 20] && $filaSr($primero) === [10, 10],
        [$n4, $filaSr($primero), $filaSr($segundo)]);

    // N5 rango vencido: dispenseNextECF no da numero; dispenseSinRango sigue y trae el vencimiento del ultimo rango.
    $vencido = $rangoSr('E41', 1, 100, 40, $vencidoSr);
    $v = $ncf->dispenseSinRango('E41', $ambSr);
    wa_chk('N5: rango vencido (current_value 40 de 100): dispenseNextECF null y dispenseSinRango da el 41 con el vencimiento del rango',
        $ncf->dispenseNextECF('E41', $ambSr) === null && ($v['valor'] ?? null) === 41 && ($v['fecha_vencimiento'] ?? null) === $vencidoSr
        && ($v['numero_hasta'] ?? null) === 100, $v);

    // N6 rangoCubre.
    $sinLimite = $rangoSr('E43', 1, null, 0, null);
    $cubre = [
        'E32 5 (el ultimo del rango)' => $ncf->rangoCubre('E32', $ambSr, 5),
        'E32 6 (fuera)' => $ncf->rangoCubre('E32', $ambSr, 6),
        'E32 1 (el primero)' => $ncf->rangoCubre('E32', $ambSr, 1),
        'E32 0' => $ncf->rangoCubre('E32', $ambSr, 0),
        'E34 10 y 11 (dos rangos)' => $ncf->rangoCubre('E34', $ambSr, 10) && $ncf->rangoCubre('E34', $ambSr, 11),
        'E34 20' => $ncf->rangoCubre('E34', $ambSr, 20),
        'E34 21 (fuera)' => $ncf->rangoCubre('E34', $ambSr, 21),
        'E41 41 (vencido)' => $ncf->rangoCubre('E41', $ambSr, 41),
        'E43 99999 (sin limite)' => $ncf->rangoCubre('E43', $ambSr, 99999),
        'E31 (sin filas)' => $ncf->rangoCubre('E31', $ambSr, 1),
        'E32 en otro ambiente' => $ncf->rangoCubre('E32', 'wa_sr_otro', 3),
        'B01' => $ncf->rangoCubre('B01', $ambSr, 1),
    ];
    wa_chk('N6: rangoCubre es true solo dentro de un rango registrado y vigente (hasta NULL = sin limite)',
        $cubre === [
            'E32 5 (el ultimo del rango)' => true, 'E32 6 (fuera)' => false, 'E32 1 (el primero)' => true, 'E32 0' => false,
            'E34 10 y 11 (dos rangos)' => true, 'E34 20' => true, 'E34 21 (fuera)' => false, 'E41 41 (vencido)' => false,
            'E43 99999 (sin limite)' => true, 'E31 (sin filas)' => false, 'E32 en otro ambiente' => false, 'B01' => false,
        ], $cubre);

    // N7 registrar un rango que cubre los numeros ya usados: antes lo bloqueaba el current_value pasado del tope.
    $res = $ncf->registerRange('E32', 6, 100, $vencSr, null, null, $ambSr);
    $nuevo = (int) ($res[1]['id'] ?? 0);
    wa_chk('N7: registerRange 6-100 sobre un rango 1-5 con current_value 26 se registra, con 74 restantes (100 - 26)',
        ($res[0] ?? null) === 'success' && ($res[1]['restantes'] ?? null) === 74, $res);
    wa_chk('N7: el rango viejo vuelve a su numero_hasta (5 de 5) y el nuevo arranca en 26 (lo usado dentro de el)',
        $filaSr($e32) === [5, 5] && $nuevo > 0 && $filaSr($nuevo) === [26, 100], [$filaSr($e32), $filaSr($nuevo)]);
    $sig = $ncf->dispenseNextECF('E32', $ambSr);
    wa_chk('N7: dispenseNextECF da el 27: nunca reparte un numero ya usado (6..26)',
        ($sig['valor'] ?? null) === 27 && ($sig['rango_id'] ?? null) === $nuevo, $sig);
    wa_chk('N7: rangoCubre ya es true para 6, 26 y 100, y false para 101',
        $ncf->rangoCubre('E32', $ambSr, 6) && $ncf->rangoCubre('E32', $ambSr, 26) && $ncf->rangoCubre('E32', $ambSr, 100)
        && !$ncf->rangoCubre('E32', $ambSr, 101));
    $solapa = $ncf->registerRange('E32', 90, 200, $vencSr, null, null, $ambSr);
    wa_chk('N7: un rango que empieza dentro del autorizado sigue rechazado (el tope es el numero_hasta, 100)',
        ($solapa[0] ?? null) === 'error' && str_contains((string) ($solapa[1] ?? ''), 'debe ser mayor que 100'), $solapa);

    // N8 cobertura parcial: el rango nuevo empieza despues de algunos numeros usados fuera de rango.
    $parcial = $ncf->registerRange('E34', 23, 50, $vencSr, null, null, $ambSr);
    $nuevoParcial = (int) ($parcial[1]['id'] ?? 0);
    wa_chk('N8: E34 usado hasta el 24 (21..24 fuera de rango): el rango 23-50 se registra con current_value 24 (26 restantes)',
        ($parcial[0] ?? null) === 'success' && ($parcial[1]['restantes'] ?? null) === 26 && $filaSr($nuevoParcial) === [24, 50]
        && $filaSr($segundo) === [20, 20], [$parcial, $filaSr($segundo)]);
    wa_chk('N8: 21 y 22 quedan sin cubrir (soporte), 23 y 24 cubiertos, y el siguiente es el 25',
        !$ncf->rangoCubre('E34', $ambSr, 21) && !$ncf->rangoCubre('E34', $ambSr, 22) && $ncf->rangoCubre('E34', $ambSr, 23)
        && $ncf->rangoCubre('E34', $ambSr, 24) && ($ncf->dispenseNextECF('E34', $ambSr)['valor'] ?? null) === 25);

    // N9 sin numeros fuera de rango registerRange se porta como siempre.
    $r1 = $ncf->registerRange('E31', 1, 10, $vencSr, null, null, $ambSr);
    $r2 = $ncf->registerRange('E31', 11, 20, $vencSr, null, null, $ambSr);
    $r3 = $ncf->registerRange('E31', 5, 30, $vencSr, null, null, $ambSr);
    wa_chk('N9: sin sobrepaso: 1-10 y 11-20 se registran con current_value en desde - 1, y 5-30 se rechaza',
        ($r1[0] ?? null) === 'success' && ($r1[1]['restantes'] ?? null) === 10 && ($r2[0] ?? null) === 'success'
        && ($r2[1]['restantes'] ?? null) === 10 && $filaSr((int) $r2[1]['id']) === [10, 20]
        && ($r3[0] ?? null) === 'error' && str_contains((string) ($r3[1] ?? ''), 'debe ser mayor que 20'), [$r1, $r2, $r3]);
} finally {
    $limpiarSr();
}
wa_chk('N: el ambiente de prueba quedo vacio',
    (int) $pdo->query("SELECT COUNT(*) FROM ncf_sequences WHERE ambiente = '{$ambSr}'")->fetchColumn() === 0);

// ---------------------------------------------------------------------------
// Cierre. Cada tarea agrega sus casos justo encima de este bloque.
// ---------------------------------------------------------------------------
wa_limpiar($pdo);
wa_fin();
