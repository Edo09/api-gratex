<?php
/**
 * test_pos_cobrar_wa.php — la venta del POS sobre el write-ahead
 * (PosVenta::cobrar), contra la base de prueba.
 *
 * Llama a PosVenta::cobrar en este mismo proceso con una caja, un empleado y un
 * turno abiertos en la base de prueba, y con una DGII falsa que se inyecta por
 * PosVenta::$fabricaServicio: token fijo, firma real con un certificado de
 * prueba, respuestas por modo y sin red. Despues de cada escenario revisa lo que
 * quedo en facturas, pos_caja_movimientos, inventory_movements, audit_logs y el
 * contador de e-NCF.
 *
 * Primero van unas comprobaciones sobre el texto de PosVenta.php (sin base).
 *
 * RF1 (dos peticiones con la misma clave mientras la primera espera a la DGII)
 * lanza un proceso hijo con esta misma prueba (--hijo-cobrar).
 *
 * Nunca toca produccion: wa_usar_base() se niega a usar una base sin la tabla
 * marca zz_write_ahead_scratch.
 *
 * Uso:
 *   php -d extension=curl -d extension=openssl -d extension=pdo_mysql -d extension=pdo_sqlite tools/test_pos_cobrar_wa.php --tenant-db=wa_scratch
 *   (sale con 1 si algo falla)
 */

require_once __DIR__ . '/write_ahead_test_lib.php';
require_once __DIR__ . '/../src/Pos/PosVenta.php';

$base = (string) wa_arg('tenant-db', '');
if ($base === '') {
    fwrite(STDERR, "Uso: php <flags> tools/test_pos_cobrar_wa.php --tenant-db=<base de prueba>\n");
    exit(1);
}

/**
 * Hijo de RF1: una segunda peticion de la MISMA venta, en otro proceso y con
 * otra conexion. Anota la marca justo antes de cobrar (para que el padre sepa
 * que ya casi esta esperando el candado de la clave) y deja una linea
 * RESULTADO=<json>. Nunca imprime getMessage() de una excepcion (PF-24).
 */
function hijoCobrar(string $base): never
{
    if (function_exists('ini_set')) {
        ini_set('error_log', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wa_pos_hijo_' . getmypid() . '.log');
    }
    wa_usar_base($base);
    $salida = [];
    try {
        $cuerpo = json_decode((string) base64_decode((string) wa_arg('cuerpo', '')), true);
        $pos = new posModel();
        file_put_contents((string) wa_arg('marca', ''), '1');
        $r = PosVenta::cobrar(
            $pos,
            ['habilitado_por' => 1],
            ['id' => (int) wa_arg('empleado', '0'), 'nombre' => 'WA cajero'],
            ['id' => (int) wa_arg('caja', '0')],
            is_array($cuerpo) ? $cuerpo : []
        );
        $salida = ['repetida' => $r['repetida'], 'factura_id' => $r['venta']['factura_id'], 'estado' => $r['venta']['estado_dgii']];
    } catch (PosError $e) {
        $salida = ['error' => $e->codigo()];
    } catch (Throwable $e) {
        $salida = ['error' => get_class($e)];
    }
    echo 'RESULTADO=' . json_encode($salida) . "\n";
    exit(0);
}
if (wa_arg('hijo-cobrar') !== null) {
    hijoCobrar($base);
}

// error_log a un archivo propio: no ensucia la salida de la prueba.
$GLOBALS['pw_log'] = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wa_pos_' . getmypid() . '.log';
@unlink($GLOBALS['pw_log']);
ini_set('log_errors', '1');
ini_set('error_log', $GLOBALS['pw_log']);
register_shutdown_function(static function (): void {
    @unlink($GLOBALS['pw_log']);
});

// Certificado de prueba para la firma real. Las URL de la DGII apuntan al puerto
// 9 (discard): si algo se saltara los dobles, falla en vez de salir a la red.
$cert = wa_cert_prueba();
foreach ([
    'DGII_ECF_CERT_PATH' => $cert['path'],
    'DGII_ECF_CERT_PASSWORD' => $cert['password'],
    'DGII_ECF_BASE_URL' => 'http://127.0.0.1:9',
    'DGII_FC_BASE_URL' => 'http://127.0.0.1:9',
] as $clave => $valor) {
    putenv("{$clave}={$valor}");
    $_ENV[$clave] = $valor;
}

/** Token fijo: no pide semilla ni firma nada. */
final class FakeAuth extends DgiiAuthService
{
    public bool $falla = false;

    public function autenticar(array $options = []): array
    {
        if ($this->falla) {
            throw new RuntimeException('DGII auth: caida simulada');
        }
        return ['token' => 'tok-prueba', 'ambiente' => 'certecf'];
    }

    public function ambienteEfectivo(array $options = []): string
    {
        return 'certecf';
    }
}

/**
 * La DGII por modo, sin red: 'acepta', 'rechaza' (con secuenciaUtilizada
 * false, que sin reclamar_secuencia=false devolveria el numero), 'en_proceso',
 * 'no_encontrado', 'error' (4xx sin veredicto) o 'cuelga' (timeout). Anota cada
 * llamada; $espia corre antes de responder.
 */
final class FakeReception extends DgiiReceptionService
{
    /** @var list<array{metodo:string, e_ncf:?string}> */
    public array $llamadas = [];
    public ?Closure $espia = null;

    public function __construct(DgiiAuthService $auth, public string $modo = 'acepta')
    {
        parent::__construct($auth);
    }

    public function recibir(string $signedXml, string $bearerToken, array $options = []): array
    {
        return $this->responder('recibir', $signedXml);
    }

    public function recibirResumen(string $signedXml, string $bearerToken, array $options = []): array
    {
        return $this->responder('recibirResumen', $signedXml);
    }

    private function responder(string $metodo, string $xml): array
    {
        $eNcf = preg_match('/<eNCF>(E[0-9]{12})<\/eNCF>/', $xml, $m) ? $m[1] : null;
        $this->llamadas[] = ['metodo' => $metodo, 'e_ncf' => $eNcf];
        if ($this->espia !== null) {
            ($this->espia)($metodo, $xml, $eNcf);
        }
        $track = 'track-' . $eNcf;
        switch ($this->modo) {
            case 'acepta':
                return $this->json(200, ['trackId' => $track, 'codigo' => 1, 'estado' => 'Aceptado', 'secuenciaUtilizada' => true]);
            case 'rechaza':
                return $this->json(400, ['trackId' => $track, 'codigo' => 2, 'estado' => 'Rechazado', 'secuenciaUtilizada' => false]);
            case 'en_proceso':
                return $this->json(200, ['trackId' => $track, 'codigo' => 3, 'estado' => 'En Proceso']);
            case 'no_encontrado':
                return $this->json(200, ['codigo' => 0, 'estado' => 'No encontrado']);
            case 'error':
                return $this->json(400, ['mensajes' => [['valor' => 'Error de prueba', 'codigo' => 1]]]);
            case 'cuelga':
                throw new RuntimeException('HTTP request failed: timeout');
        }
        throw new LogicException('FakeReception: modo desconocido ' . $this->modo);
    }

    private function json(int $status, array $data): array
    {
        return ['status_code' => $status, 'data' => $data, 'raw_body' => (string) json_encode($data), 'endpoint' => 'fake://recepcion'];
    }
}

/** posModel cuyo servidor "muere" al registrar la caja: la venta ya esta guardada y confirmada (RF2). */
final class PosQueMuereAlCobrar extends posModel
{
    public function registrarMovimiento(
        int $turnoId, int $facturaId, string $tipo, int $formaPago, float $monto,
        ?float $recibido, ?float $devuelta, ?string $iniciadaAt
    ): void {
        throw new RuntimeException('el servidor murio antes de registrar la caja');
    }
}

/** posModel que no ve la venta la primera vez que la busca por clave: la carrera de dos peticiones sin candado (S8). */
final class PosQueNoVeLaVenta extends posModel
{
    public int $lecturas = 0;

    public function ventaPorClave(string $clave): ?array
    {
        return ++$this->lecturas === 1 ? null : parent::ventaPorClave($clave);
    }
}

/** UUID v4 en minusculas: la clave de idempotencia que PosVenta exige. */
function nuevaClave(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    $h = bin2hex($b);
    return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
}

/**
 * Caja, empleado y turno abierto de las pruebas (idempotente: borra los turnos
 * y sus movimientos y abre uno nuevo). El producto sembrado cuesta RD$ 1,000 mas
 * ITBIS: una unidad son 118000 centavos.
 * @return array{equipo: array, empleado: array, caja: array, turno: array, product_id: int, client_id: int}
 */
function fixture(PDO $pdo, array $ids): array
{
    $pdo->exec('DELETE FROM pos_caja_movimientos');
    $pdo->exec('DELETE FROM pos_turnos');
    $pdo->exec("INSERT INTO pos_cajas (nombre) VALUES ('WA caja POS') ON DUPLICATE KEY UPDATE activa = 1");
    $pdo->prepare("INSERT INTO pos_empleados (nombre, rol, pin_hmac) VALUES ('WA cajero', 'cajero', :h) ON DUPLICATE KEY UPDATE activo = 1")
        ->execute([':h' => hash('sha256', 'wa-pos-cajero')]);
    $caja = (int) $pdo->query("SELECT id FROM pos_cajas WHERE nombre = 'WA caja POS'")->fetchColumn();
    $st = $pdo->prepare('SELECT id FROM pos_empleados WHERE pin_hmac = :h');
    $st->execute([':h' => hash('sha256', 'wa-pos-cajero')]);
    $empleado = (int) $st->fetchColumn();
    $turno = (new posModel())->abrirTurno($caja, $empleado, 0.0);
    return [
        'equipo' => ['habilitado_por' => 1],
        'empleado' => ['id' => $empleado, 'nombre' => 'WA cajero'],
        'caja' => ['id' => $caja],
        'turno' => $turno,
        'product_id' => $ids['product_id'],
        'client_id' => $ids['client_id'],
    ];
}

/** Base limpia entre escenarios: sin facturas, movimientos, auditoria ni contadores; el turno sigue abierto. */
function reiniciar(PDO $pdo): void
{
    $pdo->exec('DELETE FROM pos_caja_movimientos');
    wa_limpiar($pdo);
}

/** Cuerpo de POST /api/pos/ventas: una unidad del producto sembrado, en efectivo con RD$ 1,500. */
function cuerpoVenta(array $f, array $op = []): array
{
    $tipo = (string) ($op['tipo'] ?? '32');
    return [
        'clave' => $op['clave'] ?? nuevaClave(),
        'tipo_ecf' => $tipo,
        'client_id' => $tipo === '31' ? $f['client_id'] : null,
        'lineas' => [['product_id' => $f['product_id'], 'cantidad' => 1]],
        'total_centavos' => 118000,
        'forma_pago' => 1,
        'recibido_centavos' => 150000,
        'ancho' => 80,
        'iniciada_ms' => null,
    ];
}

/**
 * Un cobro con dobles frescos. Opciones: modo (FakeReception), espia,
 * auth_falla, tipo, cuerpo (el cuerpo completo, para repetir una clave) y pos
 * (el posModel). Devuelve la respuesta o la excepcion, el espia de la DGII y si
 * quedo una transaccion abierta (se cierra antes de contar).
 */
function venta(PDO $pdo, array $f, array $op = []): array
{
    $auth = new FakeAuth();
    $auth->falla = (bool) ($op['auth_falla'] ?? false);
    $rec = new FakeReception($auth, (string) ($op['modo'] ?? 'acepta'));
    $rec->espia = $op['espia'] ?? null;
    PosVenta::$fabricaServicio = static fn(): ECFEmissionService => new ECFEmissionService($auth, new DgiiXmlSigner(), $rec);
    $cuerpo = $op['cuerpo'] ?? cuerpoVenta($f, $op);
    $res = null;
    $err = null;
    try {
        $res = PosVenta::cobrar($op['pos'] ?? new posModel(), $f['equipo'], $f['empleado'], $f['caja'], $cuerpo);
    } catch (Throwable $e) {
        $err = $e;
    }
    $txAbierta = $pdo->inTransaction();
    if ($txAbierta) {
        $pdo->rollBack();
    }
    return ['res' => $res, 'err' => $err, 'rec' => $rec, 'cuerpo' => $cuerpo, 'tx_abierta' => $txAbierta];
}

/**
 * La excepcion de un escenario como detalle de un chequeo fallido. El mensaje de
 * una PDOException trae texto del driver: de esa solo se describen la clase, el
 * SQLSTATE y el errno, nunca getMessage().
 */
function descErr(?Throwable $e): ?string
{
    if ($e === null) {
        return null;
    }
    if ($e instanceof PDOException) {
        $info = (array) $e->errorInfo;
        return get_class($e) . ' codigo ' . $e->getCode() . ' errno ' . ($info[1] ?? '?');
    }
    return get_class($e) . ': ' . $e->getMessage();
}

/** La fila de facturas de una clave de idempotencia, o null. */
function filaPorClave(PDO $pdo, string $clave): ?array
{
    $st = $pdo->prepare(
        'SELECT id, e_ncf, tipo_ecf, estado_dgii, envio_pendiente, turno_id, pos_empleado_id, pos_idempotency_key,
                client_id, track_id, secuencia_utilizada, xml_firmado IS NOT NULL AS tiene_xml
         FROM facturas WHERE pos_idempotency_key = :k'
    );
    $st->execute([':k' => $clave]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Movimientos de caja de tipo VENTA de una factura: cantidad, monto, recibido, devuelta y forma de pago. */
function cajaDe(PDO $pdo, int $facturaId): array
{
    $st = $pdo->prepare(
        "SELECT COUNT(*) AS n, COALESCE(SUM(monto), 0) AS monto, MAX(monto_recibido) AS recibido, MAX(devuelta) AS devuelta, MAX(forma_pago) AS forma
         FROM pos_caja_movimientos WHERE factura_id = :f AND tipo = 'VENTA'"
    );
    $st->execute([':f' => $facturaId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return [
        'n' => (int) $r['n'],
        'monto' => (float) $r['monto'],
        'recibido' => $r['recibido'] === null ? null : (float) $r['recibido'],
        'devuelta' => $r['devuelta'] === null ? null : (float) $r['devuelta'],
        'forma' => $r['forma'] === null ? null : (int) $r['forma'],
    ];
}

/** Acciones del modulo pos en la bitacora: accion => veces. */
function acciones(PDO $pdo): array
{
    return array_count_values($pdo->query("SELECT action FROM audit_logs WHERE module = 'pos'")->fetchAll(PDO::FETCH_COLUMN));
}

/** products.stock del producto sembrado. */
function stockDe(PDO $pdo, int $productId): float
{
    $st = $pdo->prepare('SELECT stock FROM products WHERE id = :id');
    $st->execute([':id' => $productId]);
    return (float) $st->fetchColumn();
}

/** Facturas del tipo dado (una por e-NCF tomado). */
function totalFacturas(PDO $pdo, string $tipo): int
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM facturas WHERE tipo_ecf = :t');
    $st->execute([':t' => $tipo]);
    return (int) $st->fetchColumn();
}

/** Total de movimientos de caja de cualquier factura. */
function totalCaja(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM pos_caja_movimientos')->fetchColumn();
}

// ---------------------------------------------------------------------------
// 0) El texto de PosVenta.php (sin base): emite con el gancho, ya no guarda
// despues de enviar y no nombra lo que se borro.
// ---------------------------------------------------------------------------
echo "0) PosVenta.php: la venta se guarda antes de enviar\n";
$fuente = str_replace("\r\n", "\n", (string) file_get_contents(__DIR__ . '/../src/Pos/PosVenta.php'));
wa_chk('PosVenta emite con el gancho de persistencia', str_contains($fuente, '->emitir($payload, $persistencia)'));
wa_chk('PosVenta ya no pasa tolerar_fallo_envio', !str_contains($fuente, 'tolerar_fallo_envio'));
wa_chk('PosVenta pasa reclamar_secuencia=false y autenticar_despues_de_reservar=true',
    str_contains($fuente, "'reclamar_secuencia' => false") && str_contains($fuente, "'autenticar_despues_de_reservar' => true"));
wa_chk('PosVenta no nombra saveFacturaConECF', !str_contains($fuente, 'saveFacturaConECF'));
wa_chk('PosVenta ya no responde DGII_RECHAZO ni GUARDADO_FALLIDO', !str_contains($fuente, 'DGII_RECHAZO') && !str_contains($fuente, 'GUARDADO_FALLIDO'));

$pdo = wa_usar_base($base);
$ids = wa_sembrar($pdo);
$f = fixture($pdo, $ids);
$turnoId = (int) $f['turno']['id'];
$empleadoId = (int) $f['empleado']['id'];

// ---------------------------------------------------------------------------
// S1) E32 aceptada: se guarda antes de enviar y se entrega completa.
// ---------------------------------------------------------------------------
echo "S1) E32 aceptada: la venta queda guardada y entregada\n";
reiniciar($pdo);
$antes = wa_contador($pdo, 'E32');
$r = venta($pdo, $f);
$v = $r['res'];
wa_chk('S1: cobrar no lanza', $r['err'] === null, descErr($r['err']));
$claveS1 = $r['cuerpo']['clave'];
$idS1 = (int) ($v['venta']['factura_id'] ?? 0);
$filaS1 = filaPorClave($pdo, $claveS1) ?? [];
wa_chk('S1: una venta nueva con el primer e-NCF del contador',
    ($v['repetida'] ?? null) === false && ($v['venta']['e_ncf'] ?? null) === sprintf('E32%010d', $antes + 1), $v['venta'] ?? null);
wa_chk('S1: la DGII acepto (RFCE_ACEPTADO) y la fila lo dice',
    ($v['venta']['estado_dgii'] ?? null) === 'RFCE_ACEPTADO' && ($filaS1['estado_dgii'] ?? null) === 'RFCE_ACEPTADO', [$v['venta'] ?? null, $filaS1]);
wa_chk('S1: la fila lleva la clave, el turno y el empleado del POS',
    ($filaS1['pos_idempotency_key'] ?? null) === $claveS1 && (int) ($filaS1['turno_id'] ?? 0) === $turnoId
    && (int) ($filaS1['pos_empleado_id'] ?? 0) === $empleadoId && (int) ($filaS1['tiene_xml'] ?? 0) === 1, $filaS1);
wa_chk('S1: envio_pendiente 0, en la columna y en la respuesta',
    (int) ($filaS1['envio_pendiente'] ?? -1) === 0 && ($v['venta']['envio_pendiente'] ?? null) === false, [$filaS1, $v['venta'] ?? null]);
wa_chk('S1: la DGII recibio el resumen una sola vez',
    count($r['rec']->llamadas) === 1 && $r['rec']->llamadas[0]['metodo'] === 'recibirResumen', $r['rec']->llamadas);
$cajaS1 = cajaDe($pdo, $idS1);
wa_chk('S1: un movimiento de caja de RD$ 1,180 en efectivo, recibido 1,500 y devuelta 320',
    $cajaS1 === ['n' => 1, 'monto' => 1180.0, 'recibido' => 1500.0, 'devuelta' => 320.0, 'forma' => 1], $cajaS1);
wa_chk('S1: un movimiento de inventario y el stock baja a 999',
    wa_movimientos($pdo, $idS1) === 1 && abs(stockDe($pdo, $f['product_id']) - 999.0) < 0.0005, [wa_movimientos($pdo, $idS1), stockDe($pdo, $f['product_id'])]);
wa_chk('S1: el contador avanzo una vez', wa_contador($pdo, 'E32') - $antes === 1);
wa_chk('S1: bitacora POS_VENTA una vez', (acciones($pdo)['POS_VENTA'] ?? 0) === 1, acciones($pdo));
wa_chk('S1: la respuesta trae el recibo', is_array($v['recibo'] ?? null));
wa_chk('S1: sin transaccion abierta al terminar', $r['tx_abierta'] === false);
$reciboS1 = $v['recibo'] ?? null;

// S2) La misma clave otra vez: la misma venta, sin tocar nada.
echo "S2) La misma clave otra vez devuelve la misma venta\n";
$r = venta($pdo, $f, ['cuerpo' => $r['cuerpo']]);
$v2 = $r['res'];
wa_chk('S2: no lanza y responde como venta repetida de la misma factura',
    $r['err'] === null && ($v2['repetida'] ?? null) === true && (int) ($v2['venta']['factura_id'] ?? 0) === $idS1, descErr($r['err']));
wa_chk('S2: la DGII no recibio nada', $r['rec']->llamadas === []);
wa_chk('S2: sigue un solo e-NCF, una caja y un movimiento de inventario',
    wa_contador($pdo, 'E32') - $antes === 1 && cajaDe($pdo, $idS1)['n'] === 1 && wa_movimientos($pdo, $idS1) === 1);
wa_chk('S2: la bitacora no repite POS_VENTA', (acciones($pdo)['POS_VENTA'] ?? 0) === 1, acciones($pdo));
wa_chk('S2: el cobro de la respuesta es el que se registro',
    ($v2['cobro']['forma_pago'] ?? null) === 1 && ($v2['cobro']['recibido_centavos'] ?? null) === 150000
    && ($v2['cobro']['devuelta_centavos'] ?? null) === 32000, $v2['cobro'] ?? null);

// S3) El recibo no depende del estado de la DGII.
echo "S3) El recibo es el mismo sea cual sea el estado del envio\n";
$pdo->prepare("UPDATE facturas SET estado_dgii = 'ENVIO_INCIERTO', envio_pendiente = 1 WHERE id = :id")->execute([':id' => $idS1]);
$reimpreso = PosVenta::recibo($idS1, 80);
wa_chk('S3: el recibo de una venta sin confirmar es igual al de la aceptada',
    is_array($reciboS1) && $reimpreso === $reciboS1, ['antes' => is_array($reciboS1), 'ahora' => is_array($reimpreso)]);

// S4) La DGII rechaza al cobrar: la venta se entrega y el numero no vuelve.
echo "S4) Rechazo de la DGII al cobrar: 201, caja e inventario, y el numero no vuelve\n";
reiniciar($pdo);
$antes = wa_contador($pdo, 'E32');
$r = venta($pdo, $f, ['modo' => 'rechaza']);
$v = $r['res'];
$claveS4 = $r['cuerpo']['clave'];
$idS4 = (int) ($v['venta']['factura_id'] ?? 0);
$filaS4 = filaPorClave($pdo, $claveS4) ?? [];
wa_chk('S4: cobrar no lanza y entrega la venta', $r['err'] === null && ($v['repetida'] ?? null) === false && $idS4 > 0, descErr($r['err']));
wa_chk('S4: la fila queda RFCE_RECHAZADO sin envio pendiente',
    ($filaS4['estado_dgii'] ?? null) === 'RFCE_RECHAZADO' && (int) ($filaS4['envio_pendiente'] ?? -1) === 0, $filaS4);
wa_chk('S4: caja e inventario quedan', cajaDe($pdo, $idS4)['n'] === 1 && wa_movimientos($pdo, $idS4) === 1);
wa_chk('S4: el numero NO vuelve al contador (la DGII dijo secuenciaUtilizada=false)',
    wa_contador($pdo, 'E32') - $antes === 1 && (int) ($filaS4['secuencia_utilizada'] ?? -1) === 0,
    [wa_contador($pdo, 'E32') - $antes, $filaS4['secuencia_utilizada'] ?? null]);
wa_chk('S4: bitacora POS_VENTA y POS_VENTA_RECHAZADA',
    (acciones($pdo)['POS_VENTA'] ?? 0) === 1 && (acciones($pdo)['POS_VENTA_RECHAZADA'] ?? 0) === 1, acciones($pdo));
$r = venta($pdo, $f, ['cuerpo' => $r['cuerpo'], 'modo' => 'rechaza']);
wa_chk('S4: repetir la venta rechazada responde 201 repetida (no 422), sin otra caja',
    $r['err'] === null && ($r['res']['repetida'] ?? null) === true && (int) ($r['res']['venta']['factura_id'] ?? 0) === $idS4
    && cajaDe($pdo, $idS4)['n'] === 1 && $r['rec']->llamadas === [], descErr($r['err']));

// S5) Sin respuesta de la DGII: la venta se entrega, sin confirmar.
echo "S5) La DGII no responde: la venta se entrega y queda sin confirmar\n";
reiniciar($pdo);
$antes = wa_contador($pdo, 'E32');
$r = venta($pdo, $f, ['modo' => 'cuelga']);
$v = $r['res'];
$claveS5 = $r['cuerpo']['clave'];
$idS5 = (int) ($v['venta']['factura_id'] ?? 0);
$filaS5 = filaPorClave($pdo, $claveS5) ?? [];
wa_chk('S5: cobrar no lanza y responde una venta nueva', $r['err'] === null && ($v['repetida'] ?? null) === false && $idS5 > 0, descErr($r['err']));
wa_chk('S5: la fila queda ENVIO_INCIERTO con envio_pendiente 1 (columna y respuesta)',
    ($filaS5['estado_dgii'] ?? null) === 'ENVIO_INCIERTO' && (int) ($filaS5['envio_pendiente'] ?? -1) === 1
    && ($v['venta']['envio_pendiente'] ?? null) === true, [$filaS5, $v['venta'] ?? null]);
wa_chk('S5: caja, inventario y recibo', cajaDe($pdo, $idS5)['n'] === 1 && wa_movimientos($pdo, $idS5) === 1 && is_array($v['recibo'] ?? null));
wa_chk('S5: el numero sigue ocupado por la fila', wa_contador($pdo, 'E32') - $antes === 1 && (int) ($filaS5['tiene_xml'] ?? 0) === 1);
$r = venta($pdo, $f, ['cuerpo' => $r['cuerpo']]);
wa_chk('S5: repetir la venta sin confirmar responde 201 repetida con el mismo estado',
    $r['err'] === null && ($r['res']['repetida'] ?? null) === true && ($r['res']['venta']['estado_dgii'] ?? null) === 'ENVIO_INCIERTO'
    && ($r['res']['venta']['envio_pendiente'] ?? null) === true && $r['rec']->llamadas === [], descErr($r['err']));

// S5b) El resto de los resultados despues de guardar tambien se entregan: la DGII
// la tiene en proceso, dice que no la encuentra o contesta con un error.
echo "S5b) En proceso, no encontrada y con error: la venta se entrega igual\n";
foreach ([
    ['modo' => 'en_proceso', 'estado' => 'RFCE_EN_PROCESO', 'pendiente' => 1],
    ['modo' => 'no_encontrado', 'estado' => 'RFCE_NO_ENCONTRADO', 'pendiente' => 0],
    ['modo' => 'error', 'estado' => 'RFCE_ERROR', 'pendiente' => 0],
] as $caso) {
    reiniciar($pdo);
    $antes = wa_contador($pdo, 'E32');
    $r = venta($pdo, $f, ['modo' => $caso['modo']]);
    $v = $r['res'];
    $idX = (int) ($v['venta']['factura_id'] ?? 0);
    $filaX = filaPorClave($pdo, $r['cuerpo']['clave']) ?? [];
    wa_chk("S5b {$caso['modo']}: cobrar no lanza y la fila queda {$caso['estado']}",
        $r['err'] === null && $idX > 0 && ($v['repetida'] ?? null) === false
        && ($filaX['estado_dgii'] ?? null) === $caso['estado'] && ($v['venta']['estado_dgii'] ?? null) === $caso['estado'], [descErr($r['err']), $filaX]);
    wa_chk("S5b {$caso['modo']}: envio_pendiente {$caso['pendiente']} en la columna y en la respuesta",
        (int) ($filaX['envio_pendiente'] ?? -1) === $caso['pendiente'] && ($v['venta']['envio_pendiente'] ?? null) === ($caso['pendiente'] === 1),
        [$filaX, $v['venta'] ?? null]);
    wa_chk("S5b {$caso['modo']}: caja, inventario y recibo, y el numero no vuelve",
        cajaDe($pdo, $idX)['n'] === 1 && wa_movimientos($pdo, $idX) === 1 && is_array($v['recibo'] ?? null)
        && wa_contador($pdo, 'E32') - $antes === 1);
}

// S6) El token de la DGII falla despues de guardar: la venta se entrega sin confirmar.
echo "S6) El token de la DGII falla: la venta ya esta guardada y se entrega\n";
reiniciar($pdo);
$antes = wa_contador($pdo, 'E32');
$r = venta($pdo, $f, ['auth_falla' => true]);
$v = $r['res'];
$idS6 = (int) ($v['venta']['factura_id'] ?? 0);
$filaS6 = filaPorClave($pdo, $r['cuerpo']['clave']) ?? [];
wa_chk('S6: cobrar no lanza y entrega la venta', $r['err'] === null && $idS6 > 0, descErr($r['err']));
wa_chk('S6: la fila queda ENVIO_INCIERTO con envio_pendiente 1 y su XML guardado',
    ($filaS6['estado_dgii'] ?? null) === 'ENVIO_INCIERTO' && (int) ($filaS6['envio_pendiente'] ?? -1) === 1
    && (int) ($filaS6['tiene_xml'] ?? 0) === 1, $filaS6);
wa_chk('S6: nada llego a la DGII', $r['rec']->llamadas === []);
wa_chk('S6: caja e inventario quedan', cajaDe($pdo, $idS6)['n'] === 1 && wa_movimientos($pdo, $idS6) === 1);

// S7) Falla antes de guardar: sin fila, sin caja, sin inventario, y el numero vuelve.
echo "S7) Falla antes de guardar (certificado): nada queda y el numero vuelve\n";
reiniciar($pdo);
$antes = wa_contador($pdo, 'E32');
$certOk = (string) $_ENV['DGII_ECF_CERT_PATH'];
putenv('DGII_ECF_CERT_PATH=' . $certOk . '.no-existe');
$_ENV['DGII_ECF_CERT_PATH'] = $certOk . '.no-existe';
$r = venta($pdo, $f);
putenv('DGII_ECF_CERT_PATH=' . $certOk);
$_ENV['DGII_ECF_CERT_PATH'] = $certOk;
wa_chk('S7: cobrar responde EMISION_FALLIDA',
    $r['err'] instanceof PosError && $r['err']->codigo() === 'EMISION_FALLIDA' && $r['err']->http() === 502, descErr($r['err']));
wa_chk('S7: el texto al cajero no menciona a la DGII',
    $r['err'] instanceof PosError && stripos($r['err']->getMessage(), 'dgii') === false, descErr($r['err']));
wa_chk('S7: no queda fila de esa clave', filaPorClave($pdo, $r['cuerpo']['clave']) === null);
wa_chk('S7: el numero volvio al contador', wa_contador($pdo, 'E32') === $antes, wa_contador($pdo, 'E32') - $antes);
wa_chk('S7: sin caja y sin inventario', totalCaja($pdo) === 0 && (int) $pdo->query('SELECT COUNT(*) FROM inventory_movements')->fetchColumn() === 0);
wa_chk('S7: nada llego a la DGII y la bitacora anota POS_VENTA_FALLIDA',
    $r['rec']->llamadas === [] && (acciones($pdo)['POS_VENTA_FALLIDA'] ?? 0) === 1, acciones($pdo));

// S8) Otra peticion ya guardo la clave (carrera sin candado): se responde como repetida y el numero vuelve.
echo "S8) La clave ya estaba guardada por otra peticion: venta repetida, sin otro e-NCF\n";
reiniciar($pdo);
$r0 = venta($pdo, $f);
$idS8 = (int) ($r0['res']['venta']['factura_id'] ?? 0);
$despues = wa_contador($pdo, 'E32');
$r = venta($pdo, $f, ['cuerpo' => $r0['cuerpo'], 'pos' => new PosQueNoVeLaVenta()]);
wa_chk('S8: no lanza y responde la misma venta como repetida',
    $r['err'] === null && ($r['res']['repetida'] ?? null) === true && (int) ($r['res']['venta']['factura_id'] ?? 0) === $idS8 && $idS8 > 0, descErr($r['err']));
wa_chk('S8: el numero que tomo la segunda peticion volvio al contador', wa_contador($pdo, 'E32') === $despues, wa_contador($pdo, 'E32') - $despues);
wa_chk('S8: sigue una sola factura, una caja y un movimiento de inventario, y la DGII no recibio nada',
    totalFacturas($pdo, '32') === 1 && cajaDe($pdo, $idS8)['n'] === 1 && wa_movimientos($pdo, $idS8) === 1 && $r['rec']->llamadas === []);

// S9) E31 con cliente: el e-CF completo por el mismo camino.
echo "S9) E31 con cliente: guardado antes de enviar y entregado\n";
reiniciar($pdo);
$antes31 = wa_contador($pdo, 'E31');
$r = venta($pdo, $f, ['tipo' => '31']);
$v = $r['res'];
$idS9 = (int) ($v['venta']['factura_id'] ?? 0);
$filaS9 = filaPorClave($pdo, $r['cuerpo']['clave']) ?? [];
wa_chk('S9: cobrar no lanza y entrega un E31 ACEPTADO',
    $r['err'] === null && ($v['venta']['tipo_ecf'] ?? null) === '31' && ($v['venta']['estado_dgii'] ?? null) === 'ACEPTADO'
    && ($v['venta']['e_ncf'] ?? null) === sprintf('E31%010d', $antes31 + 1), [descErr($r['err']), $v['venta'] ?? null]);
wa_chk('S9: la fila lleva el cliente, el trackId y el XML',
    (int) ($filaS9['client_id'] ?? 0) === $f['client_id'] && (string) ($filaS9['track_id'] ?? '') !== '' && (int) ($filaS9['tiene_xml'] ?? 0) === 1, $filaS9);
wa_chk('S9: la DGII recibio el e-CF completo una vez', count($r['rec']->llamadas) === 1 && $r['rec']->llamadas[0]['metodo'] === 'recibir', $r['rec']->llamadas);
wa_chk('S9: caja e inventario', cajaDe($pdo, $idS9)['n'] === 1 && wa_movimientos($pdo, $idS9) === 1);

// RF1) Dos peticiones con la misma clave mientras la primera espera a la DGII.
echo "RF1) Misma clave en dos procesos mientras la primera espera a la DGII\n";
reiniciar($pdo);
$antes = wa_contador($pdo, 'E32');
$cuerpoRf1 = cuerpoVenta($f);
$marca = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wa_pos_marca_' . getmypid() . '.txt';
$salidaHijo = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wa_pos_hijo_' . getmypid() . '.out';
@unlink($marca);
$hijo = null;
$pidHijo = 0;
$espiaRf1 = static function () use (&$hijo, &$pidHijo, $base, $f, $cuerpoRf1, $marca, $salidaHijo): void {
    if ($hijo !== null) {
        return;
    }
    $hijo = proc_open(
        array_merge([PHP_BINARY], wa_php_flags(), [
            __FILE__, '--hijo-cobrar', '--tenant-db=' . $base, '--cuerpo=' . base64_encode((string) json_encode($cuerpoRf1)),
            '--empleado=' . $f['empleado']['id'], '--caja=' . $f['caja']['id'], '--marca=' . $marca,
        ]),
        [1 => ['file', $salidaHijo, 'w'], 2 => ['redirect', 1]],
        $tuberias,
        dirname(__DIR__)
    );
    // El pid se lee ahora, con el hijo vivo: proc_close devuelve -1 si antes se
    // pregunto el estado de un proceso que ya habia terminado.
    $pidHijo = is_resource($hijo) ? (int) (proc_get_status($hijo)['pid'] ?? 0) : 0;
    // Espera a que el hijo arranque y conecte, y le deja un margen para que
    // llegue al candado de la clave, que el padre tiene tomado mientras envia.
    for ($i = 0; $i < 200 && !is_file($marca); $i++) {
        usleep(100000);
    }
    usleep(2000000);
};
$r = venta($pdo, $f, ['cuerpo' => $cuerpoRf1, 'espia' => $espiaRf1]);
$codigoHijo = is_resource($hijo) ? proc_close($hijo) : -1;
$textoHijo = (string) @file_get_contents($salidaHijo);
@unlink($salidaHijo);
@unlink($marca);
if ($pidHijo > 0) {
    @unlink(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wa_pos_hijo_' . $pidHijo . '.log');
}
// De la salida del hijo (stdout y stderr juntos) solo se lee la linea RESULTADO: lo demas no se imprime.
$hijoRes = preg_match('/^RESULTADO=(.*)$/m', $textoHijo, $m) ? json_decode($m[1], true) : null;
$idRf1 = (int) ($r['res']['venta']['factura_id'] ?? 0);
wa_chk('RF1: el padre vende y el hijo termina bien',
    $r['err'] === null && $idRf1 > 0 && $codigoHijo === 0 && is_array($hijoRes), [descErr($r['err']), $codigoHijo, $hijoRes]);
wa_chk('RF1: el hijo recibe la misma venta como repetida',
    is_array($hijoRes) && ($hijoRes['repetida'] ?? null) === true && ($hijoRes['factura_id'] ?? 0) === $idRf1, $hijoRes);
wa_chk('RF1: el hijo espero el candado y vio el veredicto final, no la fila en vuelo',
    is_array($hijoRes) && ($hijoRes['estado'] ?? null) === 'RFCE_ACEPTADO', $hijoRes);
wa_chk('RF1: exactamente un e-NCF', wa_contador($pdo, 'E32') - $antes === 1 && totalFacturas($pdo, '32') === 1);
wa_chk('RF1: un movimiento de caja, un movimiento de inventario y un solo envio a la DGII',
    cajaDe($pdo, $idRf1)['n'] === 1 && wa_movimientos($pdo, $idRf1) === 1 && count($r['rec']->llamadas) === 1);

// RF2) El servidor muere entre 'confirmar' y el movimiento de caja.
echo "RF2) El servidor muere antes de registrar la caja: el reintento la completa una sola vez\n";
reiniciar($pdo);
$antes = wa_contador($pdo, 'E32');
$r = venta($pdo, $f, ['pos' => new PosQueMuereAlCobrar()]);
$claveRf2 = $r['cuerpo']['clave'];
$filaRf2 = filaPorClave($pdo, $claveRf2) ?? [];
$idRf2 = (int) ($filaRf2['id'] ?? 0);
wa_chk('RF2: el cobro se corta con la excepcion de la caja', $r['err'] instanceof RuntimeException, descErr($r['err']));
wa_chk('RF2: la venta quedo guardada y confirmada antes de la caida',
    $idRf2 > 0 && ($filaRf2['estado_dgii'] ?? null) === 'RFCE_ACEPTADO', $filaRf2);
wa_chk('RF2: sin movimiento de caja ni de inventario todavia', cajaDe($pdo, $idRf2)['n'] === 0 && wa_movimientos($pdo, $idRf2) === 0);
$r2 = venta($pdo, $f, ['cuerpo' => $r['cuerpo']]);
wa_chk('RF2: el reintento con la misma clave responde la misma venta como repetida',
    $r2['err'] === null && ($r2['res']['repetida'] ?? null) === true && (int) ($r2['res']['venta']['factura_id'] ?? 0) === $idRf2, descErr($r2['err']));
wa_chk('RF2: el reintento escribe la caja que faltaba, con lo del cuerpo (efectivo 1,500, devuelta 320)',
    cajaDe($pdo, $idRf2) === ['n' => 1, 'monto' => 1180.0, 'recibido' => 1500.0, 'devuelta' => 320.0, 'forma' => 1], cajaDe($pdo, $idRf2));
$usuarioInv = $pdo->prepare("SELECT user_id FROM inventory_movements WHERE referencia_tipo = 'factura' AND referencia_id = :id");
$usuarioInv->execute([':id' => $idRf2]);
wa_chk('RF2: el inventario se mueve una vez, a nombre de quien habilito la caja',
    wa_movimientos($pdo, $idRf2) === 1 && (int) $usuarioInv->fetchColumn() === 1);
wa_chk('RF2: el reintento no vuelve a la DGII ni toma otro numero', $r2['rec']->llamadas === [] && wa_contador($pdo, 'E32') - $antes === 1);
$r3 = venta($pdo, $f, ['cuerpo' => $r['cuerpo']]);
wa_chk('RF2: un segundo reintento no duplica caja, inventario ni bitacora',
    $r3['err'] === null && cajaDe($pdo, $idRf2)['n'] === 1 && wa_movimientos($pdo, $idRf2) === 1 && (acciones($pdo)['POS_VENTA'] ?? 0) === 1, acciones($pdo));

// RF2b) Sin la forma de pago en el reintento, y con la caja sin registrar, no se inventa el cobro.
echo "RF2b) Reintento sin forma de pago valida cuando falta la caja\n";
reiniciar($pdo);
$r = venta($pdo, $f, ['pos' => new PosQueMuereAlCobrar()]);
$idRf2b = (int) (filaPorClave($pdo, $r['cuerpo']['clave'])['id'] ?? 0);
$cuerpoSinPago = $r['cuerpo'];
unset($cuerpoSinPago['forma_pago']);
$r2 = venta($pdo, $f, ['cuerpo' => $cuerpoSinPago]);
wa_chk('RF2b: responde 422 FORMA_PAGO_INVALIDA y no escribe la caja',
    $r2['err'] instanceof PosError && $r2['err']->codigo() === 'FORMA_PAGO_INVALIDA' && cajaDe($pdo, $idRf2b)['n'] === 0, descErr($r2['err']));
$r3 = venta($pdo, $f, ['cuerpo' => $r['cuerpo']]);
wa_chk('RF2b: con el cuerpo completo el reintento la completa',
    $r3['err'] === null && cajaDe($pdo, $idRf2b)['n'] === 1 && wa_movimientos($pdo, $idRf2b) === 1, descErr($r3['err']));

PosVenta::$fabricaServicio = null;
wa_fin();
