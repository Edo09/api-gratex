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
// Cierre. Cada tarea agrega sus casos justo encima de este bloque.
// ---------------------------------------------------------------------------
wa_limpiar($pdo);
wa_fin();
