<?php
/**
 * test_pos_continuidad.php — el POS nunca se detiene (spec 2026-10-12, 4.2.5 y
 * 4.2.6): si el e-CF no se puede generar, la venta sale como factura simple;
 * si la secuencia se agota o vence, toma el numero siguiente y no se envia.
 *
 * Llama a PosVenta::cobrar EN ESTE PROCESO, sin HTTP (lo que se prueba es la
 * venta, no la autenticacion del equipo), con la emision de verdad: el
 * certificado de prueba autofirmado de wa_cert_prueba() y la DGII falsa
 * tools/mock_dgii.php. El "certificado que falla" es su clave cambiada, su
 * archivo ausente o uno ya vencido, en el entorno (CertResolver lo lee en cada
 * firma).
 *
 * Nunca toca produccion: wa_pdo() se niega a usar una base sin la tabla marca
 * zz_write_ahead_scratch. Cada escenario vacia la base de prueba (facturas, caja
 * y turnos del POS) y la vuelve a sembrar, y al final la deja asi. Las suites de
 * base van de a una.
 *
 * Uso:
 *   php -d extension=curl -d extension=openssl -d extension=pdo_mysql tools/test_pos_continuidad.php --tenant-db=wa_scratch
 *   (si el puerto 8099 esta ocupado: WA_PUERTO_OFFSET=100; sale con 1 si algo falla)
 */

require_once __DIR__ . '/write_ahead_test_lib.php';

const PC_RUTA_RECEPCION = '/Recepcion/api/FacturasElectronicas';
const PC_RUTA_RECEPCION_FC = '/RecepcionFC/api/recepcion/ecf';
const PC_PASSWORD_MALA = 'esta-no-es-la-clave-del-certificado';

$base = (string) wa_arg('tenant-db', '');
if ($base === '') {
    fwrite(STDERR, "Uso: php <flags> tools/test_pos_continuidad.php --tenant-db=<base de prueba>\n");
    exit(1);
}
$pdo = wa_pdo($base);
$real = wa_base_real($base);
foreach (['pos_cajas', 'pos_empleados', 'pos_sesiones', 'pos_turnos', 'pos_caja_movimientos'] as $tabla) {
    if (!wa_tabla_existe($pdo, $real, $tabla)) {
        echo '[FALLO] a ' . wa_base_corta($real) . " le falta la tabla {$tabla} (migracion 030_pos.sql): aplicala a la base de prueba\n";
        exit(1);
    }
}

// Los modelos de este proceso apuntan a la base de prueba; la DGII es el mock y
// el certificado el de prueba. DGII_FC_BASE_URL se lee con getenv a secas
// (DgiiReceptionService), por eso todo va con putenv Y en $_ENV.
wa_usar_base($base);
$cert = wa_cert_prueba();
$mock = wa_servidor('mock_dgii', 'tools/mock_dgii.php', 8099, ['MOCK_DGII_CUELGA_SEG' => '5']);
foreach ([
    'DGII_ECF_BASE_URL' => $mock, 'DGII_FC_BASE_URL' => $mock, 'DGII_ECF_TIMEOUT' => '3', 'POS_DGII_TIMEOUT' => '5',
] as $clave => $valor) {
    putenv("{$clave}={$valor}");
    $_ENV[$clave] = $valor;
}

// error_log a un archivo propio: los avisos de PHP (clave sin definir, etc.) se
// leen desde ahi. Despues de wa_usar_base, que cambia error_log un momento.
$GLOBALS['pc_log'] = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wa_pos_' . getmypid() . '.log';
@unlink($GLOBALS['pc_log']);
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('display_errors', '0');
ini_set('error_log', $GLOBALS['pc_log']);

require_once __DIR__ . '/../src/Pos/PosVenta.php';
require_once __DIR__ . '/../src/Pos/PosCierre.php';
require_once __DIR__ . '/../src/Models/facturaModel.php';
require_once __DIR__ . '/../src/Utils/FacturacionElectronica/EcfNumeroOcupadoException.php';
require_once __DIR__ . '/../src/Utils/FacturacionElectronica/EcfEnvioInciertoException.php';
require_once __DIR__ . '/../src/Utils/FacturacionElectronica/EcfVentaRepetidaException.php';

/** emitir() de mentira: responde lo que se le anota, en orden (excepciones se lanzan). */
final class EmisionFalsa extends ECFEmissionService
{
    public int $llamadas = 0;

    /** @param list<Throwable|array> $respuestas */
    public function __construct(private array $respuestas)
    {
    }

    public function emitir(array $payload, ?callable $persistencia = null): array
    {
        $this->llamadas++;
        $r = array_shift($this->respuestas);
        if ($r instanceof Throwable) {
            throw $r;
        }
        return $r ?? [];
    }
}

function pc_uuid(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

/**
 * Certificado .p12 ya vencido, autofirmado, en el temp del sistema (nunca en el
 * repo) con una clave al azar que no se imprime; se borra al terminar. OpenSSL
 * no firma con dias negativos: se pide de 0 dias (vale hasta este segundo) y se
 * espera a que pase. Se crea una sola vez.
 * @return array{path:string, password:string}
 */
function pc_cert_vencido(): array
{
    static $vencido = null;
    if ($vencido !== null) {
        return $vencido;
    }
    $cfg = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha256'];
    $cnf = getenv('OPENSSL_CONF') ?: '';
    $cnfPhp = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf';
    if ($cnf === '' && is_file($cnfPhp)) {
        $cnf = $cnfPhp;
    }
    if ($cnf !== '') {
        $cfg['config'] = $cnf;
    }
    $clave = bin2hex(random_bytes(12));
    $llave = openssl_pkey_new($cfg);
    $csr = $llave ? openssl_csr_new(['commonName' => 'WA PRUEBA VENCIDO', 'organizationName' => 'WA PRUEBA', 'countryName' => 'DO'], $llave, $cfg) : false;
    $x509 = ($csr && $llave) ? openssl_csr_sign($csr, null, $llave, 0, $cfg) : false;
    $p12 = '';
    if (!$x509 || !openssl_pkcs12_export($x509, $p12, $llave, $clave)) {
        echo '[FALLO] no se pudo crear el certificado vencido de prueba: ' . (openssl_error_string() ?: 'sin detalle') . "\n";
        exit(1);
    }
    $hasta = (int) (openssl_x509_parse($x509)['validTo_time_t'] ?? 0);
    while (time() <= $hasta) {
        usleep(200000);
    }
    $ruta = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wa_cert_vencido_' . getmypid() . '.p12';
    file_put_contents($ruta, $p12);
    register_shutdown_function(static function () use ($ruta): void {
        @unlink($ruta);
    });
    return $vencido = ['path' => $ruta, 'password' => $clave];
}

/**
 * Estado del certificado en el entorno (CertResolver lo lee en cada firma):
 * 'buena' (el de prueba), 'mala' (una clave que no abre el .p12), 'ausente'
 * (una ruta que no existe) o 'vencida' (un .p12 bueno cuya vigencia ya paso).
 */
function pc_cert(string $estado): void
{
    $cert = $estado === 'vencida' ? pc_cert_vencido() : wa_cert_prueba();
    $ruta = $estado === 'ausente' ? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wa_cert_que_no_existe.p12' : $cert['path'];
    $clave = $estado === 'mala' ? PC_PASSWORD_MALA : $cert['password'];
    foreach (['DGII_ECF_CERT_PATH' => $ruta, 'DGII_ECF_CERT_PASSWORD' => $clave] as $k => $v) {
        putenv("{$k}={$v}");
        $_ENV[$k] = $v;
    }
}

/** Tamano actual del log de PHP, para leer despues solo lo nuevo. */
function pc_log_pos(): int
{
    clearstatcache();
    return is_file($GLOBALS['pc_log']) ? (int) filesize($GLOBALS['pc_log']) : 0;
}

/** Lo escrito en el log de PHP desde la posicion $desde. */
function pc_log_desde(int $desde): string
{
    return substr((string) @file_get_contents($GLOBALS['pc_log']), $desde);
}

/**
 * Las lineas "Undefined array key / variable / property" que cayeron en el log
 * desde $desde: una clave que el codigo lee y quien lo llama no armo. Solo esas:
 * los "Deprecated" de las librerias de QR y PDF salen en cada recibo e-CF.
 */
function pc_sin_definir(int $desde): array
{
    preg_match_all('/^.*(?:Undefined (?:array key|variable|property)|Attempt to read property).*$/m', pc_log_desde($desde), $m);
    return $m[0];
}

/**
 * Deja la base como la espera cualquier suite: sin ventas, caja ni turnos, con
 * los rangos y el emisor sembrados. Devuelve los ids de wa_sembrar.
 */
function pc_vaciar(PDO $pdo): array
{
    wa_exigir_marca($pdo);
    foreach (['pos_caja_movimientos', 'pos_turnos', 'pos_sesiones', 'pos_empleados', 'pos_cajas'] as $tabla) {
        $pdo->exec("DELETE FROM {$tabla}");
    }
    wa_limpiar($pdo);
    return wa_sembrar($pdo);
}

/**
 * Escenario nuevo: DGII en $modo, certificado bueno, base vacia y sembrada, y
 * una caja con su cajero y su turno abierto. Devuelve lo que usan las pruebas.
 */
function pc_reiniciar(PDO $pdo, string $mock, string $modo = 'acepta'): array
{
    wa_mock_modo($mock, $modo);
    pc_cert('buena');
    $ids = pc_vaciar($pdo);
    wa_mock_log($mock, true);
    $pdo->prepare(
        "INSERT INTO users (name, last_name, email, username, password, role)
         VALUES ('WA', 'Prueba', 'wa_prueba@example.com', 'wa_prueba', :p, 'admin')
         ON DUPLICATE KEY UPDATE role = 'admin'"
    )->execute([':p' => password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT)]);
    $userId = (int) $pdo->query("SELECT id FROM users WHERE username = 'wa_prueba'")->fetchColumn();
    $pos = new posModel();
    $caja = $pos->crearCaja('WA caja');
    $empleado = $pos->crearEmpleado('WA cajero', 'cajero', static fn(): array => ['1234', hash('sha256', random_bytes(8))])['empleado'];
    $turno = $pos->abrirTurno((int) $caja['id'], (int) $empleado['id'], 0.0);
    $precio = (string) $pdo->query('SELECT precio FROM products WHERE id = ' . (int) $ids['product_id'])->fetchColumn();
    return [
        'pdo' => $pdo, 'mock' => $mock, 'pos' => $pos, 'equipo' => ['habilitado_por' => $userId], 'empleado' => $empleado,
        'caja' => $caja, 'turno' => $turno, 'ids' => $ids, 'precio' => $precio, 'user_id' => $userId,
    ];
}

/**
 * Cuerpo de POST /api/pos/ventas: $cantidad unidades del producto sembrado
 * (RD$1,180.00 con ITBIS cada una), efectivo con RD$140.00 de devuelta. El tipo
 * '31' lleva el cliente sembrado.
 */
function pc_cuerpo(array $esc, int $cantidad = 2, string $tipo = '32'): array
{
    $total = PosPrecio::finalCentavos($esc['precio'], 1) * $cantidad;
    $cuerpo = [
        'clave' => pc_uuid(), 'tipo_ecf' => $tipo,
        'lineas' => [['product_id' => (int) $esc['ids']['product_id'], 'cantidad' => $cantidad]],
        'total_centavos' => $total, 'forma_pago' => 1, 'recibido_centavos' => $total + 14000, 'ancho' => 80,
    ];
    return $tipo === '31' ? $cuerpo + ['client_id' => (int) $esc['ids']['client_id']] : $cuerpo;
}

/** Entrada de facturaModel::crearFacturaSimplePos: 2 unidades del producto sembrado (RD$1,180.00 con ITBIS cada una). */
function pc_datos_simple(array $esc, string $clave): array
{
    return [
        'date' => date('Y-m-d H:i:s'), 'client_id' => null, 'client_name' => 'Consumidor Final', 'total' => 2360.00,
        'user_id' => $esc['user_id'],
        'items' => [[
            'product_id' => (int) $esc['ids']['product_id'], 'description' => 'WA producto', 'amount' => 1180.00, 'quantity' => 2,
            'indicador_bien_servicio' => 1, 'unidad_medida' => '43',
        ]],
        'pos' => ['turno_id' => (int) $esc['turno']['id'], 'pos_empleado_id' => (int) $esc['empleado']['id'], 'pos_idempotency_key' => $clave],
    ];
}

/**
 * PosVenta::cobrar sin cortar la prueba: un PosError vuelve como ['_error' =>
 * {codigo, http}] y cualquier otra cosa como ['_excepcion' => clase y lugar]
 * (nunca el mensaje: el de un PDOException trae host y usuario).
 */
function pc_cobrar(array $esc, array $cuerpo): array
{
    try {
        return PosVenta::cobrar($esc['pos'], $esc['equipo'], $esc['empleado'], $esc['caja'], $cuerpo);
    } catch (PosError $e) {
        return ['_error' => ['codigo' => $e->codigo(), 'http' => $e->http()]];
    } catch (Throwable $e) {
        return ['_excepcion' => get_class($e) . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')'];
    }
}

/** La venta se entrego: respuesta con venta y sin error. */
function pc_ok(array $r): bool
{
    return isset($r['venta']) && !isset($r['_error']) && !isset($r['_excepcion']);
}

function pc_fila(PDO $pdo, string $clave): array
{
    $st = $pdo->prepare('SELECT * FROM facturas WHERE pos_idempotency_key = :k');
    $st->execute([':k' => $clave]);
    return $st->fetch() ?: [];
}

function pc_n(PDO $pdo, string $sql, array $params = []): int
{
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return (int) $st->fetchColumn();
}

/** Movimientos de caja de una factura. */
function pc_caja(PDO $pdo, int $facturaId): array
{
    $st = $pdo->prepare('SELECT tipo, forma_pago, monto, monto_recibido, devuelta FROM pos_caja_movimientos WHERE factura_id = :f ORDER BY id');
    $st->execute([':f' => $facturaId]);
    return $st->fetchAll();
}

function pc_stock(PDO $pdo, int $productoId): float
{
    return (float) $pdo->query('SELECT stock FROM products WHERE id = ' . $productoId)->fetchColumn();
}

/** Peticiones de recepcion (e-CF o RFCE) que vio el mock. */
function pc_recepciones(string $mock): int
{
    $n = 0;
    foreach (wa_mock_log($mock) as $e) {
        $ruta = (string) ($e['ruta'] ?? '');
        if (str_ends_with($ruta, PC_RUTA_RECEPCION) || str_ends_with($ruta, PC_RUTA_RECEPCION_FC)) {
            $n++;
        }
    }
    return $n;
}

/** Filas de auditoria del POS con esa accion, con new_values decodificado. */
function pc_audit(PDO $pdo, string $accion): array
{
    $st = $pdo->prepare("SELECT entity_id, success, new_values FROM audit_logs WHERE module = 'pos' AND action = :a ORDER BY id");
    $st->execute([':a' => $accion]);
    return array_map(static fn(array $f): array => [
        'entity_id' => $f['entity_id'], 'success' => (int) $f['success'], 'new_values' => json_decode((string) $f['new_values'], true) ?? [],
    ], $st->fetchAll());
}

// ---------------------------------------------------------------------------
// S0) facturaModel::crearFacturaSimplePos: factura simple con las columnas del
// POS en el mismo INSERT; la clave repetida no crea otra.
// ---------------------------------------------------------------------------
echo "S0) facturaModel::crearFacturaSimplePos\n";
$esc = pc_reiniciar($pdo, $mock);
$facturas = new facturaModel();
$clave = pc_uuid();
$datos = pc_datos_simple($esc, $clave);
$id = $facturas->crearFacturaSimplePos($datos);
$f = pc_fila($pdo, $clave);
wa_chk('crea la factura con tipo_ecf y e_ncf NULL, sin envio pendiente y con turno, empleado, usuario y total',
    (int) ($f['id'] ?? 0) === $id && $f['tipo_ecf'] === null && $f['e_ncf'] === null && (int) $f['envio_pendiente'] === 0
    && (int) $f['turno_id'] === (int) $esc['turno']['id'] && (int) $f['pos_empleado_id'] === (int) $esc['empleado']['id']
    && (int) $f['user_id'] === $esc['user_id'] && (float) $f['total'] === 2360.0, $f);
wa_chk('el numero es el de las facturas simples: secuencia-ddmmaa',
    preg_match('/^\d{4,}-\d{6}$/', (string) ($f['no_factura'] ?? '')) === 1, $f['no_factura'] ?? null);
$st = $pdo->prepare('SELECT COUNT(*) AS n, SUM(subtotal) AS subtotal, SUM(itbis_amount) AS itbis FROM factura_items WHERE factura_id = :f');
$st->execute([':f' => $id]);
$it = $st->fetch();
wa_chk('una linea con el subtotal de la venta y sin ITBIS (la factura simple no lo desglosa)',
    (int) $it['n'] === 1 && (float) $it['subtotal'] === 2360.0 && (float) $it['itbis'] === 0.0, $it);
try {
    $facturas->crearFacturaSimplePos($datos);
    $rep = null;
} catch (Throwable $e) {
    $rep = $e;
}
wa_chk('la misma clave lanza EcfVentaRepetidaException con la clave y no crea otra fila',
    $rep instanceof EcfVentaRepetidaException && $rep->clave === $clave
    && pc_n($pdo, 'SELECT COUNT(*) FROM facturas WHERE pos_idempotency_key = :k', [':k' => $clave]) === 1,
    $rep === null ? null : get_class($rep));
$invalidos = [];
foreach (['sin lineas' => ['items' => []] + $datos, 'sin clave' => ['pos' => ['turno_id' => 1, 'pos_empleado_id' => 1]] + $datos] as $nombre => $malo) {
    try {
        $facturas->crearFacturaSimplePos($malo);
        $invalidos[$nombre] = 'no lanzo';
    } catch (InvalidArgumentException $e) {
        $invalidos[$nombre] = 'InvalidArgumentException';
    }
}
wa_chk('sin lineas o sin clave lanza InvalidArgumentException',
    $invalidos === ['sin lineas' => 'InvalidArgumentException', 'sin clave' => 'InvalidArgumentException'], $invalidos);

// ---------------------------------------------------------------------------
// S1) El certificado falla a mitad del turno y se recupera (RF4): las ventas
// del tramo malo son facturas simples con su caja, su inventario y su clave;
// las de antes y de despues son e-CF.
// ---------------------------------------------------------------------------
echo "S1) el certificado falla a mitad del turno y despues se recupera\n";
$esc = pc_reiniciar($pdo, $mock);
$prodId = (int) $esc['ids']['product_id'];
$stock0 = pc_stock($pdo, $prodId);
$cuerpoA = pc_cuerpo($esc);
$a = pc_cobrar($esc, $cuerpoA);
$fa = pc_fila($pdo, $cuerpoA['clave']);
wa_chk('A (certificado bueno): e-CF E32 aceptado, entregado',
    pc_ok($a) && ($a['repetida'] ?? null) === false && $fa['tipo_ecf'] === '32' && str_ends_with((string) $fa['estado_dgii'], 'ACEPTADO')
    && str_starts_with((string) $fa['e_ncf'], 'E32'), [$a, $fa['estado_dgii'] ?? null]);
$contadorA = wa_contador($pdo, 'E32');
$recepcionesA = pc_recepciones($mock);
wa_chk('A: el contador de E32 esta en 1 y la DGII recibio 1 comprobante', $contadorA === 1 && $recepcionesA === 1, [$contadorA, $recepcionesA]);

pc_cert('mala');
$logAntes = pc_log_pos();
$cuerpoB = pc_cuerpo($esc);
$b = pc_cobrar($esc, $cuerpoB);
$fb = pc_fila($pdo, $cuerpoB['clave']);
$idB = (int) ($fb['id'] ?? 0);
wa_chk('B (certificado que falla): la venta se entrega, 201 sin error y no es repetida',
    pc_ok($b) && ($b['repetida'] ?? null) === false, $b);
wa_chk('B: factura simple (tipo_ecf y e_ncf NULL, sin envio pendiente) con su numero interno y la clave del POS',
    $fb !== [] && $fb['tipo_ecf'] === null && $fb['e_ncf'] === null && (int) $fb['envio_pendiente'] === 0
    && preg_match('/^\d{4,}-\d{6}$/', (string) $fb['no_factura']) === 1, $fb);
wa_chk('B: turno, empleado, usuario que habilito la caja y total de la venta',
    (int) $fb['turno_id'] === (int) $esc['turno']['id'] && (int) $fb['pos_empleado_id'] === (int) $esc['empleado']['id']
    && (int) $fb['user_id'] === $esc['user_id'] && (float) $fb['total'] === 2360.0, $fb);
$st = $pdo->prepare('SELECT COUNT(*) AS n, SUM(subtotal) AS subtotal, SUM(itbis_amount) AS itbis FROM factura_items WHERE factura_id = :f');
$st->execute([':f' => $idB]);
$it = $st->fetch();
wa_chk('B: las mismas lineas y el mismo total (una linea de RD$2,360.00, sin ITBIS)',
    (int) $it['n'] === 1 && (float) $it['subtotal'] === 2360.0 && (float) $it['itbis'] === 0.0, $it);
$caja = pc_caja($pdo, $idB);
wa_chk('B: un movimiento de caja VENTA en efectivo de 2360.00, recibido 2500.00 y devuelta 140.00',
    count($caja) === 1 && $caja[0]['tipo'] === 'VENTA' && (int) $caja[0]['forma_pago'] === 1 && (float) $caja[0]['monto'] === 2360.0
    && (float) $caja[0]['monto_recibido'] === 2500.0 && (float) $caja[0]['devuelta'] === 140.0, $caja);
wa_chk('B: el inventario se movio una vez (un movimiento) y el stock bajo 4 en total (A y B)',
    wa_movimientos($pdo, $idB) === 1 && pc_stock($pdo, $prodId) === $stock0 - 4, [wa_movimientos($pdo, $idB), pc_stock($pdo, $prodId)]);
wa_chk('B: la respuesta lleva el numero interno, sin tipo de e-CF (null) ni envio pendiente, y el total y la devuelta',
    ($b['venta']['factura_id'] ?? null) === $idB && ($b['venta']['e_ncf'] ?? null) === $fb['no_factura']
    && array_key_exists('tipo_ecf', $b['venta'] ?? []) && $b['venta']['tipo_ecf'] === null && ($b['venta']['envio_pendiente'] ?? null) === false
    && ($b['venta']['total_centavos'] ?? null) === 236000 && ($b['cobro']['devuelta_centavos'] ?? null) === 14000, $b['venta'] ?? $b);
$recibo = $b['recibo'] ?? null;
wa_chk('B: el recibo sale como factura simple (titulo FACTURA, "Factura No." con su numero, sin timbre)',
    is_array($recibo) && ($recibo['titulo'] ?? null) === 'FACTURA' && array_key_exists('timbre', $recibo) && $recibo['timbre'] === null
    && ($recibo['identificacion'][0] ?? null) === ['Factura No.', $fb['no_factura']], $recibo);
wa_chk('B: el numero de E32 volvio al contador y la DGII no recibio nada mas',
    wa_contador($pdo, 'E32') === $contadorA && pc_recepciones($mock) === $recepcionesA, [wa_contador($pdo, 'E32'), pc_recepciones($mock)]);
$aud = pc_audit($pdo, 'POS_VENTA_FACTURA_SIMPLE');
// serialize y no json_encode: json_encode duplica las barras de una ruta de Windows y la busqueda nunca la hallaria.
$crudo = serialize($aud);
wa_chk('B: un evento POS_VENTA_FACTURA_SIMPLE con la clase del fallo, sin la clave ni la ruta del certificado',
    count($aud) === 1 && ($aud[0]['new_values']['causa'] ?? null) === 'EcfUsuarioException' && (int) $aud[0]['new_values']['factura_id'] === $idB
    && !str_contains($crudo, PC_PASSWORD_MALA) && !str_contains($crudo, $cert['password']) && !str_contains($crudo, $cert['path']), $aud);
$sinDefinir = pc_sin_definir($logAntes);
wa_chk('B: la entrega, el audit y el recibo no leyeron claves sin definir (log de PHP limpio)', $sinDefinir === [], $sinDefinir);

$b2 = pc_cobrar($esc, $cuerpoB);
wa_chk('B repetida (mismo cuerpo y clave): 201 repetida con la misma factura, el mismo numero, sin tipo de e-CF (null) y su recibo de factura simple',
    pc_ok($b2) && ($b2['repetida'] ?? null) === true && ($b2['venta']['factura_id'] ?? null) === $idB
    && ($b2['venta']['e_ncf'] ?? null) === $fb['no_factura']
    && array_key_exists('tipo_ecf', $b2['venta'] ?? []) && $b2['venta']['tipo_ecf'] === null
    && ($b2['recibo']['titulo'] ?? null) === 'FACTURA', $b2);
wa_chk('B repetida: una sola factura con la clave, un movimiento de caja y un movimiento de inventario',
    pc_n($pdo, 'SELECT COUNT(*) FROM facturas WHERE pos_idempotency_key = :k', [':k' => $cuerpoB['clave']]) === 1
    && count(pc_caja($pdo, $idB)) === 1 && wa_movimientos($pdo, $idB) === 1,
    [count(pc_caja($pdo, $idB)), wa_movimientos($pdo, $idB)]);

pc_cert('buena');
$cuerpoC = pc_cuerpo($esc);
$c = pc_cobrar($esc, $cuerpoC);
$fc = pc_fila($pdo, $cuerpoC['clave']);
wa_chk('C (certificado recuperado): vuelve a ser e-CF aceptado y toma el numero que B devolvio',
    pc_ok($c) && $fc['tipo_ecf'] === '32' && str_ends_with((string) $fc['estado_dgii'], 'ACEPTADO') && $fc['e_ncf'] === 'E320000000002'
    && (int) $fc['envio_pendiente'] === 0 && pc_recepciones($mock) === $recepcionesA + 1, [$fc['e_ncf'] ?? null, $fc['estado_dgii'] ?? null]);
// El efectivo de la venta simple cuenta en la caja del turno igual que el de un e-CF (el cierre suma pos_caja_movimientos).
wa_chk('el turno tiene las tres ventas en caja: A, B (simple) y C, 7080.00 en efectivo',
    pc_n($pdo, "SELECT COUNT(*) FROM pos_caja_movimientos WHERE turno_id = :t AND tipo = 'VENTA'", [':t' => (int) $esc['turno']['id']]) === 3
    && (float) $pdo->query("SELECT SUM(monto) FROM pos_caja_movimientos WHERE turno_id = " . (int) $esc['turno']['id'] . " AND tipo = 'VENTA' AND forma_pago = 1")->fetchColumn() === 7080.0);
wa_chk('la bitacora tiene un evento por venta: POS_VENTA de A y de C, y POS_VENTA_FACTURA_SIMPLE de B',
    count(pc_audit($pdo, 'POS_VENTA')) === 2 && count(pc_audit($pdo, 'POS_VENTA_FACTURA_SIMPLE')) === 1);
// El reporte del cierre cuenta los comprobantes por tipo: la factura simple (tipo_ecf NULL) va bajo 'Simple', nunca bajo una llave vacia.
$reporte = (new ReflectionMethod('PosCierre', 'reporte'))->invoke(
    null, $esc['pos'], $esc['turno'], $esc['caja'], ['id' => $esc['empleado']['id'], 'nombre' => 'WA cajero'], [], 0
);
wa_chk('el cierre cuenta E32 dos veces (A y C) y la factura simple de B como Simple, sin llave vacia',
    ($reporte['comprobantes'] ?? null) === ['E32' => 2, 'Simple' => 1], $reporte['comprobantes'] ?? null);

// ---------------------------------------------------------------------------
// S2) Datos fiscales del emisor ausentes o archivo del certificado ausente:
// tampoco detienen la venta.
// ---------------------------------------------------------------------------
echo "S2) emisor_config ausente y certificado ausente: factura simple\n";
$esc = pc_reiniciar($pdo, $mock);
$pdo->exec('DELETE FROM emisor_config');
$cuerpo = pc_cuerpo($esc);
$r = pc_cobrar($esc, $cuerpo);
$f = pc_fila($pdo, $cuerpo['clave']);
wa_chk('sin emisor_config la venta sale como factura simple, con caja y sin tocar la secuencia',
    pc_ok($r) && $f !== [] && $f['tipo_ecf'] === null && count(pc_caja($pdo, (int) $f['id'])) === 1 && wa_contador($pdo, 'E32') === 0,
    [$r, wa_contador($pdo, 'E32')]);
$esc = pc_reiniciar($pdo, $mock);
pc_cert('ausente');
$cuerpo = pc_cuerpo($esc);
$r = pc_cobrar($esc, $cuerpo);
$f = pc_fila($pdo, $cuerpo['clave']);
wa_chk('con el archivo del certificado ausente la venta sale como factura simple, con caja, sin llamar a la DGII y con el numero devuelto',
    pc_ok($r) && $f !== [] && $f['tipo_ecf'] === null && count(pc_caja($pdo, (int) $f['id'])) === 1
    && wa_contador($pdo, 'E32') === 0 && pc_recepciones($mock) === 0, [$r, wa_contador($pdo, 'E32'), pc_recepciones($mock)]);

// ---------------------------------------------------------------------------
// S2b) Certificado vencido: el .p12 abre y firma, pero ya no vale. PosVenta pide
// a emitir() revisar la vigencia ANTES de reservar (validar_vigencia_certificado),
// asi que es un fallo previo a la reserva y la venta sale como factura simple.
// ---------------------------------------------------------------------------
echo "S2b) certificado vencido: factura simple antes de reservar\n";
$esc = pc_reiniciar($pdo, $mock);
pc_cert('vencida');
$cuerpo = pc_cuerpo($esc);
$r = pc_cobrar($esc, $cuerpo);
$f = pc_fila($pdo, $cuerpo['clave']);
wa_chk('con el certificado vencido la venta sale como factura simple, con caja, sin llamar a la DGII y con el numero devuelto',
    pc_ok($r) && $f !== [] && $f['tipo_ecf'] === null && $f['e_ncf'] === null && count(pc_caja($pdo, (int) $f['id'])) === 1
    && wa_contador($pdo, 'E32') === 0 && pc_recepciones($mock) === 0, [$r, wa_contador($pdo, 'E32'), pc_recepciones($mock)]);
$aud = pc_audit($pdo, 'POS_VENTA_FACTURA_SIMPLE');
wa_chk('el aviso lleva EcfUsuarioException (la de la vigencia) como causa y no la ruta ni la clave del certificado vencido',
    count($aud) === 1 && ($aud[0]['new_values']['causa'] ?? null) === 'EcfUsuarioException'
    && !str_contains(serialize($aud), pc_cert_vencido()['path']) && !str_contains(serialize($aud), pc_cert_vencido()['password']), $aud);

// ---------------------------------------------------------------------------
// S3) Las validaciones de captura siguen respondiendo 4xx aunque el
// certificado falle: no son un fallo de la emision.
// ---------------------------------------------------------------------------
echo "S3) validaciones del carrito con el certificado roto\n";
$esc = pc_reiniciar($pdo, $mock);
pc_cert('mala');
$antes = pc_n($pdo, 'SELECT COUNT(*) FROM facturas');
$totalMal = pc_cuerpo($esc);
$totalMal['total_centavos'] += 100;
$pocoEfectivo = pc_cuerpo($esc);
$pocoEfectivo['recibido_centavos'] = $pocoEfectivo['total_centavos'] - 1;
$sinCliente = pc_cuerpo($esc, 1, '31');
unset($sinCliente['client_id']);
$obtenido = [
    'total' => pc_cobrar($esc, $totalMal)['_error'] ?? null,
    'efectivo' => pc_cobrar($esc, $pocoEfectivo)['_error'] ?? null,
    'cliente' => pc_cobrar($esc, $sinCliente)['_error'] ?? null,
];
wa_chk('total distinto 409, efectivo insuficiente 422 y credito fiscal sin cliente 422',
    $obtenido === [
        'total' => ['codigo' => 'TOTAL_DISTINTO', 'http' => 409],
        'efectivo' => ['codigo' => 'RECIBIDO_INSUFICIENTE', 'http' => 422],
        'cliente' => ['codigo' => 'CLIENTE_REQUERIDO', 'http' => 422],
    ], $obtenido);
wa_chk('ninguna de esas ventas creo una factura', pc_n($pdo, 'SELECT COUNT(*) FROM facturas') === $antes);

// ---------------------------------------------------------------------------
// S4) Credito fiscal (E31) con el certificado roto: factura simple con el
// cliente.
// ---------------------------------------------------------------------------
echo "S4) E31 con el certificado roto: factura simple con el cliente\n";
$esc = pc_reiniciar($pdo, $mock);
pc_cert('mala');
$cuerpo = pc_cuerpo($esc, 1, '31');
$r = pc_cobrar($esc, $cuerpo);
$f = pc_fila($pdo, $cuerpo['clave']);
wa_chk('la venta E31 sale como factura simple con el cliente y se entrega',
    pc_ok($r) && $f !== [] && $f['tipo_ecf'] === null && (int) $f['client_id'] === (int) $esc['ids']['client_id']
    && (int) ($r['venta']['cliente']['id'] ?? 0) === (int) $esc['ids']['client_id']
    && count(pc_caja($pdo, (int) $f['id'])) === 1 && wa_movimientos($pdo, (int) $f['id']) === 1, [$r, $f['client_id'] ?? null]);

// ---------------------------------------------------------------------------
// S5) Numero ocupado al reservar: un reintento; el segundo choque sale.
// ---------------------------------------------------------------------------
echo "S5) emitirConReintento\n";
$choque = static fn(): EcfNumeroOcupadoException => new EcfNumeroOcupadoException('E320000000009', 'certecf', 'fila 1 en ACEPTADO');
$reintento = new ReflectionMethod('PosVenta', 'emitirConReintento');
$falso = new EmisionFalsa([$choque(), ['e_ncf' => 'E320000000010']]);
$r = $reintento->invoke(null, $falso, [], null);
wa_chk('un choque y luego exito: devuelve el resultado del segundo intento (2 llamadas)',
    ($r['e_ncf'] ?? null) === 'E320000000010' && $falso->llamadas === 2, [$r, $falso->llamadas]);
$falso = new EmisionFalsa([$choque(), $choque(), ['e_ncf' => 'E320000000011']]);
try {
    $reintento->invoke(null, $falso, [], null);
    $lanzo = null;
} catch (Throwable $e) {
    $lanzo = $e;
}
wa_chk('dos choques seguidos: sale la excepcion y no hay un tercer intento (2 llamadas)',
    $lanzo instanceof EcfNumeroOcupadoException && $falso->llamadas === 2, [$lanzo === null ? null : get_class($lanzo), $falso->llamadas]);
$falso = new EmisionFalsa([new RuntimeException('otra cosa'), ['e_ncf' => 'E320000000012']]);
try {
    $reintento->invoke(null, $falso, [], null);
    $lanzo = null;
} catch (Throwable $e) {
    $lanzo = $e;
}
wa_chk('cualquier otra excepcion sale sin reintentar (1 llamada)',
    $lanzo instanceof RuntimeException && $falso->llamadas === 1, [$lanzo === null ? null : get_class($lanzo), $falso->llamadas]);

// ---------------------------------------------------------------------------
// S6) Nunca se crea una segunda factura para una venta que ya tiene fila: con
// EcfEnvioInciertoException o EcfVentaRepetidaException la factura simple no se
// arma, y si la clave aparece mientras se guarda, el cajero reintenta con ella.
// ---------------------------------------------------------------------------
echo "S6) una venta que ya tiene fila no se duplica como factura simple\n";
$esc = pc_reiniciar($pdo, $mock);
$simple = new ReflectionMethod('PosVenta', 'ventaComoFacturaSimple');
$clave = pc_uuid();
// El cobro tal como lo arma leerCobro: lo que ventaComoFacturaSimple le pasa a entregarVenta.
$cobro = [
    'turno_id' => (int) $esc['turno']['id'], 'forma_pago' => 1, 'total_centavos' => 236000, 'recibido_centavos' => 250000,
    'devuelta_centavos' => 14000, 'iniciada_at' => null,
];
$resultados = [];
foreach ([
    'incierto' => new EcfEnvioInciertoException('E320000000001', 1, 'certecf', 'envio', null, true, new RuntimeException('timeout')),
    'repetida' => new EcfVentaRepetidaException($clave),
] as $nombre => $causa) {
    try {
        $simple->invoke(null, $esc['pos'], $esc['empleado'], $esc['turno'], $clave, 80, $esc['user_id'], null, '32', [], $cobro, [], $causa);
        $resultados[$nombre] = 'creo una factura';
    } catch (Throwable $e) {
        $resultados[$nombre] = $e === $causa ? 'relanzada' : get_class($e);
    }
}
wa_chk('las dos excepciones se relanzan tal cual y no hay ninguna factura',
    $resultados === ['incierto' => 'relanzada', 'repetida' => 'relanzada'] && pc_n($pdo, 'SELECT COUNT(*) FROM facturas') === 0, $resultados);

// Una fila con esta clave aparecio entre el fallo del e-CF y el guardado de la factura simple.
$lineas = [[
    'product_id' => (int) $esc['ids']['product_id'], 'nombre_item' => 'WA producto', 'cantidad' => 2, 'precio_unitario' => 1180.0,
    'indicador_bien_servicio' => 1, 'unidad_medida' => '43',
]];
$ocupada = pc_uuid();
$facturas = new facturaModel();
$facturas->crearFacturaSimplePos(pc_datos_simple($esc, $ocupada));
try {
    $simple->invoke(null, $esc['pos'], $esc['empleado'], $esc['turno'], $ocupada, 80, $esc['user_id'], null, '32', $lineas, $cobro, [], new RuntimeException('el e-CF no se pudo generar'));
    $r = 'no lanzo';
} catch (PosError $e) {
    $r = ['codigo' => $e->codigo(), 'http' => $e->http()];
} catch (Throwable $e) {
    $r = get_class($e);
}
wa_chk('si la clave ya tiene factura: 409 VENTA_EN_PROCESO (el cajero reintenta con la clave), sin segunda factura ni aviso de factura simple',
    $r === ['codigo' => 'VENTA_EN_PROCESO', 'http' => 409]
    && pc_n($pdo, 'SELECT COUNT(*) FROM facturas WHERE pos_idempotency_key = :k', [':k' => $ocupada]) === 1
    && pc_audit($pdo, 'POS_VENTA_FACTURA_SIMPLE') === [], $r);

// Cualquier fallo al guardar la factura simple (aqui, una venta sin lineas) es lo unico que detiene la venta.
try {
    $simple->invoke(null, $esc['pos'], $esc['empleado'], $esc['turno'], pc_uuid(), 80, $esc['user_id'], null, '32', [], $cobro, [], new RuntimeException('el e-CF no se pudo generar'));
    $r = 'no lanzo';
} catch (PosError $e) {
    $r = ['codigo' => $e->codigo(), 'http' => $e->http()];
} catch (Throwable $e) {
    $r = get_class($e);
}
wa_chk('si la factura simple no se puede guardar: 500 VENTA_NO_GUARDADA (el POS reintenta con la misma clave), auditado y sin factura nueva',
    $r === ['codigo' => 'VENTA_NO_GUARDADA', 'http' => 500] && count(pc_audit($pdo, 'POS_VENTA_FALLIDA')) === 1
    && pc_n($pdo, 'SELECT COUNT(*) FROM facturas') === 1, $r);

// ---------------------------------------------------------------------------
// S7) E32 agotada con E31 vigente (RF5, parte del POS): solo las ventas E32
// toman el numero siguiente, se guardan y se imprimen, y no se envian.
// ---------------------------------------------------------------------------
echo "S7) secuencia E32 agotada: numero siguiente, guardada y sin enviar\n";
$esc = pc_reiniciar($pdo, $mock);
$prodId = (int) $esc['ids']['product_id'];
$stock0 = pc_stock($pdo, $prodId);
$cuerpoA = pc_cuerpo($esc);
$a = pc_cobrar($esc, $cuerpoA);
wa_chk('A: el primer E32 sale normal (numero 1, aceptado) y la DGII lo recibe',
    pc_ok($a) && ($a['venta']['e_ncf'] ?? null) === 'E320000000001' && pc_recepciones($mock) === 1, $a['venta'] ?? $a);
// El rango de E32 se agota: current_value llega a numero_hasta.
$pdo->exec("UPDATE ncf_sequences SET numero_hasta = current_value WHERE type = 'E32' AND ambiente = 'certecf'");
$logAntes = pc_log_pos();
$cuerpoB = pc_cuerpo($esc);
$b = pc_cobrar($esc, $cuerpoB);
$fb = pc_fila($pdo, $cuerpoB['clave']);
$idB = (int) ($fb['id'] ?? 0);
$metaB = facturaModel::metaEnvio($fb['respuesta_dgii'] ?? null);
wa_chk('B (E32 agotada): la venta se entrega y toma el numero siguiente al ultimo (E320000000002)',
    pc_ok($b) && ($b['repetida'] ?? null) === false && ($fb['e_ncf'] ?? null) === 'E320000000002' && ($fb['tipo_ecf'] ?? null) === '32', [$b, $fb['e_ncf'] ?? null]);
wa_chk('B: queda guardada PENDIENTE_ENVIO con envio_pendiente 1 y la marca sin_rango en su meta',
    ($fb['estado_dgii'] ?? null) === 'PENDIENTE_ENVIO' && (int) ($fb['envio_pendiente'] ?? 0) === 1 && ($metaB['sin_rango'] ?? null) === true
    && !empty($fb['xml_firmado']) && !empty($fb['rfce_xml']) && !empty($fb['codigo_seguridad']), [$fb['estado_dgii'] ?? null, $metaB]);
wa_chk('B: no se envia al momento de vender (la DGII no recibio nada mas)', pc_recepciones($mock) === 1, pc_recepciones($mock));
wa_chk('B: el XML de una E32 no lleva fecha de vencimiento de secuencia',
    !str_contains((string) ($fb['xml_firmado'] ?? ''), 'FechaVencimientoSecuencia'));
wa_chk('B: caja e inventario se entregaron una vez (un movimiento de caja, un movimiento de inventario, stock -4 con A)',
    count(pc_caja($pdo, $idB)) === 1 && wa_movimientos($pdo, $idB) === 1 && pc_stock($pdo, $prodId) === $stock0 - 4,
    [count(pc_caja($pdo, $idB)), wa_movimientos($pdo, $idB), pc_stock($pdo, $prodId)]);
wa_chk('B: la respuesta dice que quedo pendiente de envio y trae el numero',
    ($b['venta']['e_ncf'] ?? null) === 'E320000000002' && ($b['venta']['estado_dgii'] ?? null) === 'PENDIENTE_ENVIO'
    && ($b['venta']['envio_pendiente'] ?? null) === true && ($b['venta']['tipo_ecf'] ?? null) === '32', $b['venta'] ?? $b);
$recibo = $b['recibo'] ?? null;
wa_chk('B: el recibo se imprime como un e-CF normal (con su timbre), sin enterarse de que falta enviarlo',
    is_array($recibo) && str_contains((string) ($recibo['titulo'] ?? ''), 'FACTURA DE CONSUMO') && !empty($recibo['timbre']), $recibo['titulo'] ?? $recibo);
wa_chk('B: el contador de E32 avanzo al 2, mas alla del rango agotado', wa_contador($pdo, 'E32') === 2, wa_contador($pdo, 'E32'));
$sinDefinir = pc_sin_definir($logAntes);
wa_chk('B: el codigo no leyo claves que la venta sin rango no trae (sin "Undefined" en el log de PHP)', $sinDefinir === [], $sinDefinir);

$cuerpoC = pc_cuerpo($esc, 1, '31');
$c = pc_cobrar($esc, $cuerpoC);
$fc = pc_fila($pdo, $cuerpoC['clave']);
wa_chk('C (E31 con rango vigente): sigue siendo e-CF, se envia y queda aceptado',
    pc_ok($c) && ($fc['e_ncf'] ?? null) === 'E310000000001' && ($fc['estado_dgii'] ?? null) === 'ACEPTADO' && (int) ($fc['envio_pendiente'] ?? 1) === 0
    && pc_recepciones($mock) === 2, [$fc['e_ncf'] ?? null, $fc['estado_dgii'] ?? null, pc_recepciones($mock)]);

$cuerpoD = pc_cuerpo($esc);
$d = pc_cobrar($esc, $cuerpoD);
$fd = pc_fila($pdo, $cuerpoD['clave']);
wa_chk('D (otra E32): toma el numero que sigue (E320000000003), tambien PENDIENTE_ENVIO y sin enviar',
    pc_ok($d) && ($fd['e_ncf'] ?? null) === 'E320000000003' && ($fd['estado_dgii'] ?? null) === 'PENDIENTE_ENVIO'
    && (int) ($fd['envio_pendiente'] ?? 0) === 1 && pc_recepciones($mock) === 2 && wa_contador($pdo, 'E32') === 3,
    [$fd['e_ncf'] ?? null, $fd['estado_dgii'] ?? null, pc_recepciones($mock), wa_contador($pdo, 'E32')]);

$b2 = pc_cobrar($esc, $cuerpoB);
wa_chk('B repetida: 201 repetida con la misma factura, una sola caja y un solo movimiento, y sigue sin enviarse',
    pc_ok($b2) && ($b2['repetida'] ?? null) === true && ($b2['venta']['factura_id'] ?? null) === $idB
    && count(pc_caja($pdo, $idB)) === 1 && wa_movimientos($pdo, $idB) === 1 && pc_recepciones($mock) === 2
    && ($b2['venta']['estado_dgii'] ?? null) === 'PENDIENTE_ENVIO', $b2['venta'] ?? $b2);

// ---------------------------------------------------------------------------
// S8) E31 agotada: el XML lleva la fecha de vencimiento del ultimo rango.
// ---------------------------------------------------------------------------
echo "S8) secuencia E31 agotada: el XML lleva la fecha del ultimo rango\n";
$esc = pc_reiniciar($pdo, $mock);
pc_cobrar($esc, pc_cuerpo($esc, 1, '31'));
$pdo->exec("UPDATE ncf_sequences SET numero_hasta = current_value WHERE type = 'E31' AND ambiente = 'certecf'");
$vence = (string) $pdo->query("SELECT fecha_vencimiento FROM ncf_sequences WHERE type = 'E31' AND ambiente = 'certecf' ORDER BY numero_desde DESC LIMIT 1")->fetchColumn();
$cuerpoB = pc_cuerpo($esc, 1, '31');
$b = pc_cobrar($esc, $cuerpoB);
$fb = pc_fila($pdo, $cuerpoB['clave']);
wa_chk('la E31 toma el numero siguiente (E310000000002), queda PENDIENTE_ENVIO y no se envia',
    pc_ok($b) && ($fb['e_ncf'] ?? null) === 'E310000000002' && ($fb['estado_dgii'] ?? null) === 'PENDIENTE_ENVIO'
    && (int) ($fb['envio_pendiente'] ?? 0) === 1 && pc_recepciones($mock) === 1, [$fb['e_ncf'] ?? null, $fb['estado_dgii'] ?? null, pc_recepciones($mock)]);
wa_chk('el XML trae la fecha de vencimiento del ultimo rango conocido',
    $vence !== '' && str_contains((string) ($fb['xml_firmado'] ?? ''),
        '<FechaVencimientoSecuencia>' . date('d-m-Y', strtotime($vence)) . '</FechaVencimientoSecuencia>'), $vence);

// ---------------------------------------------------------------------------
// S9) Rango vencido (no agotado): el numero tambien es el siguiente, sin enviar.
// ---------------------------------------------------------------------------
echo "S9) rango E32 vencido: numero siguiente y sin enviar\n";
$esc = pc_reiniciar($pdo, $mock);
$pdo->exec("UPDATE ncf_sequences SET fecha_vencimiento = CURDATE() - INTERVAL 1 DAY WHERE type = 'E32' AND ambiente = 'certecf'");
$cuerpo = pc_cuerpo($esc);
$r = pc_cobrar($esc, $cuerpo);
$f = pc_fila($pdo, $cuerpo['clave']);
wa_chk('con el unico rango vencido la venta toma el numero 1, queda PENDIENTE_ENVIO y no se envia',
    pc_ok($r) && ($f['e_ncf'] ?? null) === 'E320000000001' && ($f['estado_dgii'] ?? null) === 'PENDIENTE_ENVIO'
    && (int) ($f['envio_pendiente'] ?? 0) === 1 && pc_recepciones($mock) === 0, [$f['e_ncf'] ?? null, $f['estado_dgii'] ?? null, pc_recepciones($mock)]);

// ---------------------------------------------------------------------------
// S10) Ningun rango registrado para el tipo: no hay a que numero seguir, asi
// que la venta sale como factura simple (la venta no se detiene).
// ---------------------------------------------------------------------------
echo "S10) sin ningun rango de E32: factura simple\n";
$esc = pc_reiniciar($pdo, $mock);
$pdo->exec("DELETE FROM ncf_sequences WHERE type = 'E32' AND ambiente = 'certecf'");
$cuerpo = pc_cuerpo($esc);
$r = pc_cobrar($esc, $cuerpo);
$f = pc_fila($pdo, $cuerpo['clave']);
wa_chk('sin rango alguno la venta sale como factura simple, con caja, y no se envia',
    pc_ok($r) && $f !== [] && $f['tipo_ecf'] === null && (int) $f['envio_pendiente'] === 0 && count(pc_caja($pdo, (int) $f['id'])) === 1
    && pc_recepciones($mock) === 0, [$r, pc_recepciones($mock)]);

// Deja la base como la espera la siguiente suite: sin ventas, caja ni turnos, con los rangos y el emisor sembrados.
pc_vaciar($pdo);

wa_fin();
