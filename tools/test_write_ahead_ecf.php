<?php
/**
 * test_write_ahead_ecf.php — ECFEmissionService::emitir con el gancho de
 * persistencia (write-ahead), contra la base de prueba.
 *
 * Arma la emision como facturaController (payload + facturaInput) y le inyecta
 * dobles al servicio: FakeAuth (token fijo), FakeSigner (firma falsa, sin .p12),
 * FakeReception (respuestas de la DGII por modo, sin red) y FakeNcf (espia del
 * contador que delega en ncfModel). El gancho es el closure del controlador
 * (spec 4.7) sobre un facturaModel real, con registro de fases.
 *
 * Despues de cada escenario revisa el contador (ncf_sequences), las filas del
 * e-NCF, que no quede una transaccion abierta, que el servicio no mueva
 * inventario y que la recepcion no se haya llamado cuando no debia.
 *
 * Nunca toca produccion: wa_usar_base() se niega a usar una base sin la tabla
 * marca zz_write_ahead_scratch (decision 6).
 *
 * Uso:
 *   php -d extension=curl -d extension=openssl -d extension=pdo_mysql tools/test_write_ahead_ecf.php --tenant-db=wa_scratch
 *   (sale con 1 si algo falla)
 */

require_once __DIR__ . '/write_ahead_test_lib.php';
require_once __DIR__ . '/../src/Utils/FacturacionElectronica/ECFEmissionService.php';
require_once __DIR__ . '/../src/Utils/FacturacionElectronica/EcfNumeroOcupadoException.php';
require_once __DIR__ . '/../src/Utils/FacturacionElectronica/EcfEnvioInciertoException.php';
require_once __DIR__ . '/../src/Utils/FacturacionElectronica/EcfItemMapper.php';
require_once __DIR__ . '/../src/Models/facturaModel.php';

$base = (string) wa_arg('tenant-db', '');
if ($base === '') {
    fwrite(STDERR, "Uso: php <flags> tools/test_write_ahead_ecf.php --tenant-db=<base de prueba>\n");
    exit(1);
}

// error_log a un archivo propio: cada escenario lee sus lineas con logNuevo().
$GLOBALS['wa_ecf_log'] = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wa_ecf_' . getmypid() . '.log';
@unlink($GLOBALS['wa_ecf_log']);
ini_set('log_errors', '1');
ini_set('error_log', $GLOBALS['wa_ecf_log']);

// CertResolver::resolve() es estatico y sigue corriendo (CertResolver.php:44-55):
// cualquier archivo legible con una password no vacia basta, porque FakeSigner
// no abre el .p12. Las URL de la DGII apuntan al puerto 9 (discard): si algo se
// saltara los dobles, falla en vez de salir a la red. DGII_FC_BASE_URL se lee
// con getenv a secas (DgiiReceptionService.php:74), por eso va con putenv.
foreach ([
    'DGII_ECF_CERT_PATH' => __FILE__,
    'DGII_ECF_CERT_PASSWORD' => 'prueba',
    'DGII_ECF_BASE_URL' => 'http://127.0.0.1:9',
    'DGII_FC_BASE_URL' => 'http://127.0.0.1:9',
] as $clave => $valor) {
    putenv("{$clave}={$valor}");
    $_ENV[$clave] = $valor;
}

const CLAVES_RESERVAR = [
    'tipo_ecf', 'e_ncf', 'ambiente', 'ambiente_secuencia', 'signed_xml', 'codigo_seguridad',
    'fecha_emision_dgii', 'flujo', 'rfce_xml', 'dispensado', 'rango_id', 'secuencia_valor', 'desde',
];

/** Orden global de los pasos: el gancho anota reservar/confirmar/incierto y FakeNcf anota rollback. */
final class Orden
{
    public static array $pasos = [];
}

/** Token fijo: no pide semilla ni firma nada. */
final class FakeAuth extends DgiiAuthService
{
    public int $llamadas = 0;

    public function autenticar(array $options = []): array
    {
        $this->llamadas++;
        return ['token' => 'tok-prueba', 'ambiente' => 'certecf'];
    }
}

/**
 * Firma falsa: mete un <Signature> antes de la etiqueta de cierre, asi
 * extractCodigoSeguridad saca 'QUJDRE'. $conCr agrega un retorno de carro.
 */
final class FakeSigner extends DgiiXmlSigner
{
    public bool $conCr = false;

    public function sign(string $certificateContent, string $password, string $xmlContent): string
    {
        $firma = '<Signature><SignatureValue>QUJDREVGR0g=</SignatureValue></Signature>' . ($this->conCr ? "\r" : '');
        $pos = strrpos($xmlContent, '</');
        return $pos === false ? $xmlContent . $firma : substr_replace($xmlContent, $firma, $pos, 0);
    }
}

/**
 * La DGII por modo, sin red. Anota cada llamada; $espia corre antes de
 * responder (lee la base a mitad del envio); $reentrada es la emision anidada
 * del modo 'reentrar'.
 */
final class FakeReception extends DgiiReceptionService
{
    /** @var list<array{metodo:string, e_ncf:?string}> */
    public array $llamadas = [];
    public ?Closure $espia = null;
    public ?Closure $reentrada = null;

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
                // La DGII rechaza con 4xx + cuerpo estructurado (DgiiReceptionService.php:45-47).
                return $this->json(400, ['trackId' => $track, 'codigo' => 2, 'estado' => 'Rechazado', 'secuenciaUtilizada' => false]);
            case 'no_encontrado_sin_flag':
                return $this->json(200, ['codigo' => 0, 'estado' => 'No encontrado']);
            case 'raw200':
                // 2xx sin JSON: data es el cuerpo crudo (DgiiAuthService.php:150).
                return ['status_code' => 200, 'data' => 'Recibido', 'raw_body' => 'Recibido', 'endpoint' => 'fake://' . $metodo];
            case 'cuelga':
                throw new RuntimeException('HTTP request failed: timeout');
            case 'html500':
                // Como responseSummary (DgiiAuthService.php:538-546): sin etiquetas y con tope de 500.
                throw new RuntimeException('DGII authenticated request failed: HTTP 500 - '
                    . substr(str_repeat('Internal Server Error ', 30), 0, 500) . '...');
            case 'reentrar':
                ($this->reentrada)();
                return $this->json(200, ['trackId' => $track, 'codigo' => 1, 'estado' => 'Aceptado', 'secuenciaUtilizada' => true]);
            case 'fatal':
                // Fatal real (memoria agotada): ni catch ni finally corren, solo
                // las funciones de cierre.
                ini_set('memory_limit', '64M');
                str_repeat('x', 256 * 1024 * 1024);
                return $this->json(200, []);
        }
        throw new LogicException('FakeReception: modo desconocido ' . $this->modo);
    }

    private function json(int $status, array $data): array
    {
        return ['status_code' => $status, 'data' => $data, 'raw_body' => (string) json_encode($data), 'endpoint' => 'fake://recepcion'];
    }
}

/**
 * ncfModel real (base de prueba) con espias. El constructor heredado conecta
 * por Database::getInstance(), que wa_usar_base() ya apunto a la base de prueba.
 */
final class FakeNcf extends ncfModel
{
    public bool $falloDispense = false;
    public bool $rollbackFalso = false;
    public int $dispensas = 0;
    public array $rollbacks = [];

    public function dispenseNextECF(string $type, ?string $ambiente = null): ?array
    {
        $this->dispensas++;
        if ($this->falloDispense) {
            throw new RuntimeException('ncf_sequences inaccesible: test');
        }
        return parent::dispenseNextECF($type, $ambiente);
    }

    public function rollbackECFSequence(string $type, int $expectedValue, ?string $ambiente = null, ?int $rangoId = null): bool
    {
        $this->rollbacks[] = ['type' => $type, 'valor' => $expectedValue, 'ambiente' => $ambiente, 'rango_id' => $rangoId];
        Orden::$pasos[] = 'rollback';
        if ($this->rollbackFalso) {
            return false;
        }
        return parent::rollbackECFSequence($type, $expectedValue, $ambiente, $rangoId);
    }
}

/**
 * Emision como la arma facturaController::handleEmisionECF: items por
 * EcfItemMapper, totales, comprador del cliente sembrado y el $facturaInput con
 * las lineas que se guardan (una del producto sembrado y una libre).
 * @return array{payload: array, factura: array}
 */
function emision(PDO $pdo, array $ids, string $tipo): array
{
    $st = $pdo->prepare('SELECT client_name, rnc, razon_social FROM clients WHERE id = :id');
    $st->execute([':id' => $ids['client_id']]);
    $cli = $st->fetch(PDO::FETCH_ASSOC) ?: ['client_name' => 'WA cliente', 'rnc' => null, 'razon_social' => null];
    $crudos = EcfItemMapper::normalizarCantidadPrecio([
        ['product_id' => $ids['product_id'], 'nombre_item' => 'WA producto', 'cantidad' => 2, 'precio_unitario' => 1000,
         'indicador_facturacion' => 1, 'indicador_bien_servicio' => 1, 'unidad_medida' => '43'],
        ['product_id' => null, 'nombre_item' => 'WA servicio', 'cantidad' => 1, 'precio_unitario' => 100,
         'indicador_facturacion' => 1, 'indicador_bien_servicio' => 2, 'unidad_medida' => '43'],
    ]);
    $items = EcfItemMapper::map($crudos);
    $totales = EcfItemMapper::totales($crudos);
    $payload = [
        'tipo_ecf' => $tipo,
        'e_ncf' => null,
        'fecha_emision' => date('d-m-Y'),
        'tipo_ingresos' => '01',
        'tipo_pago' => 1,
        'indicador_monto_gravado' => '0',
        'ambiente' => null,
        'strict_input' => false,
        'comprador' => [
            'rnc' => $cli['rnc'],
            'razon_social' => (string) ($cli['razon_social'] ?? '') !== '' ? $cli['razon_social'] : $cli['client_name'],
        ],
        'items' => $items,
        'totales' => $totales,
        'informacion_referencia' => null,
    ];
    $factura = [
        'date' => date('Y-m-d H:i:s'),
        'client_id' => $ids['client_id'],
        'client_name' => $cli['client_name'],
        'total' => $totales['monto_total'],
        'tipo_pago' => 1,
        'user_id' => null,
        'informacion_referencia' => null,
        'items' => array_map(static fn(array $i): array => [
            'description' => $i['descripcion'] !== '' ? $i['descripcion'] : $i['nombre_item'],
            'amount' => $i['precio_unitario'],
            'quantity' => $i['cantidad'],
            'product_id' => $i['product_id'],
            'subtotal' => $i['monto_item'],
            'descuento_monto' => $i['descuento_monto'] ?? 0,
            'indicador_facturacion' => $i['indicador_facturacion'],
            'indicador_bien_servicio' => $i['indicador_bien_servicio'],
            'unidad_medida' => $i['unidad_medida'] ?? '43',
            'itbis_amount' => $i['itbis_amount'],
        ], $items),
    ];
    return ['payload' => $payload, 'factura' => $factura];
}

/**
 * El closure de facturaController (spec 4.7) sobre un facturaModel real, con
 * registro de fases. $trucos[fase] reemplaza la llamada normal de esa fase:
 * fn(array $ctx, facturaModel $m, array $factura): mixed.
 */
function gancho(facturaModel $m, array $factura, array &$fases, array &$ctxs, array $trucos = []): Closure
{
    $reserva = null;
    return function (string $fase, array $ctx) use ($m, $factura, &$fases, &$ctxs, $trucos, &$reserva) {
        $fases[] = $fase;
        $ctxs[$fase] = $ctx;
        Orden::$pasos[] = $fase;
        if (isset($trucos[$fase])) {
            $r = $trucos[$fase]($ctx, $m, $factura);
            if ($fase === 'reservar') {
                $reserva = is_array($r) ? $r : null;
            }
            return $r;
        }
        switch ($fase) {
            case 'reservar':
                return $reserva = $m->reservarFacturaECF($factura, $ctx);
            case 'confirmar':
                return $m->confirmarFacturaECF((int) $reserva['factura_id'], $ctx);
            case 'incierto':
                return $m->marcarEnvioIncierto((int) $reserva['factura_id'], $ctx);
        }
        return null;
    };
}

/** Lineas nuevas del error_log desde la ultima llamada. */
function logNuevo(): string
{
    static $leido = 0;
    clearstatcache();
    $todo = (string) @file_get_contents($GLOBALS['wa_ecf_log']);
    $nuevo = (string) substr($todo, $leido);
    $leido = strlen($todo);
    return $nuevo;
}

/**
 * Una emision con dobles frescos. Opciones: tipo ('31'), modo (FakeReception),
 * espia, trucos (del gancho), sin_gancho, con_cr, fallo_dispense,
 * rollback_falso, payload (claves que pisan las del payload), limpiar (true).
 */
function correr(PDO $pdo, array $ids, array $op = []): array
{
    if ($op['limpiar'] ?? true) {
        wa_limpiar($pdo);
    }
    Orden::$pasos = [];
    logNuevo();
    $tipo = $op['tipo'] ?? '31';
    $antes = wa_contador($pdo, 'E' . $tipo);
    $em = emision($pdo, $ids, $tipo);
    $auth = new FakeAuth();
    $signer = new FakeSigner();
    $signer->conCr = (bool) ($op['con_cr'] ?? false);
    $rec = new FakeReception($auth, $op['modo'] ?? 'acepta');
    $rec->espia = $op['espia'] ?? null;
    $ncf = new FakeNcf();
    $ncf->falloDispense = (bool) ($op['fallo_dispense'] ?? false);
    $ncf->rollbackFalso = (bool) ($op['rollback_falso'] ?? false);
    $fases = [];
    $ctxs = [];
    $hook = !empty($op['sin_gancho']) ? null : gancho(new facturaModel(), $em['factura'], $fases, $ctxs, $op['trucos'] ?? []);
    $res = null;
    $err = null;
    try {
        $res = (new ECFEmissionService($auth, $signer, $rec, $ncf))->emitir(($op['payload'] ?? []) + $em['payload'], $hook);
    } catch (Throwable $e) {
        $err = $e;
    }
    // Primero la transaccion: si quedo abierta se anota y se cierra ANTES de
    // contar, para que el conteo vea lo que de verdad quedo commiteado.
    $txAbierta = $pdo->inTransaction();
    if ($txAbierta) {
        $pdo->rollBack();
    }
    $eNcf = sprintf('E%s%010d', $tipo, $antes + 1);
    return [
        'res' => $res,
        'err' => $err,
        'fases' => $fases,
        'ctxs' => $ctxs,
        'e_ncf' => $eNcf,
        'delta' => wa_contador($pdo, 'E' . $tipo) - $antes,
        'filas' => wa_filas($pdo, $eNcf),
        'total_filas' => (int) $pdo->query('SELECT COUNT(*) FROM facturas')->fetchColumn(),
        'movimientos' => (int) $pdo->query('SELECT COUNT(*) FROM inventory_movements')->fetchColumn(),
        'orden' => Orden::$pasos,
        'log' => logNuevo(),
        'tx_abierta' => $txAbierta,
        'auth' => $auth,
        'rec' => $rec,
        'ncf' => $ncf,
    ];
}

/** Lo que vale para todo escenario: sin transaccion abierta y sin mover inventario. */
function chkComun(string $n, array $r): void
{
    wa_chk("{$n}: sin transaccion abierta al terminar", $r['tx_abierta'] === false);
    wa_chk("{$n}: el servicio no mueve inventario", $r['movimientos'] === 0, $r['movimientos']);
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
    return $e->getMessage();
}

/**
 * Abre una transaccion y deja en ella una fila de facturas PENDIENTE_ENVIO del
 * e-NCF del ctx, SIN commitearla: lo que haria un gancho mal portado (R9, R11).
 * La transaccion queda abierta; devuelve el id insertado.
 */
function insertarSinCommit(PDO $pdo, array $ctx): int
{
    $pdo->beginTransaction();
    $st = $pdo->prepare("INSERT INTO facturas (no_factura, client_name, tipo_ecf, e_ncf, estado_dgii, ambiente_dgii)
                         VALUES (:no, 'WA sin commit', :tipo, :e, 'PENDIENTE_ENVIO', :amb)");
    $st->execute([':no' => $ctx['e_ncf'], ':tipo' => $ctx['tipo_ecf'], ':e' => $ctx['e_ncf'], ':amb' => $ctx['ambiente']]);
    return (int) $pdo->lastInsertId();
}

$pdo = wa_usar_base($base);
$ids = wa_sembrar($pdo);

// Modo hijo del escenario S1 (seccion 3): dos emisiones anidadas en un proceso
// y un fatal real en la segunda. Ni catch ni finally corren: solo el registro
// de cierre puede marcar las dos filas. El padre revisa la base al terminar.
if (wa_arg('hijo') === 'shutdown') {
    $fases1 = $ctxs1 = $fases2 = $ctxs2 = [];
    $auth2 = new FakeAuth();
    $svc2 = new ECFEmissionService($auth2, new FakeSigner(), new FakeReception($auth2, 'fatal'), new FakeNcf());
    $em2 = emision($pdo, $ids, '31');
    $gancho2 = gancho(new facturaModel(), $em2['factura'], $fases2, $ctxs2);
    $auth1 = new FakeAuth();
    $rec1 = new FakeReception($auth1, 'reentrar');
    $rec1->reentrada = function () use ($svc2, $em2, $gancho2): void {
        $svc2->emitir($em2['payload'], $gancho2);
    };
    $svc1 = new ECFEmissionService($auth1, new FakeSigner(), $rec1, new FakeNcf());
    $em1 = emision($pdo, $ids, '31');
    $svc1->emitir($em1['payload'], gancho(new facturaModel(), $em1['factura'], $fases1, $ctxs1));
    echo "NO DEBIO LLEGAR AQUI\n";
    exit(3);
}

// Payload de un tenant de integracion: trae su emisor y su e-NCF.
$integracion = [
    'integration' => true,
    'e_ncf' => 'E310000009999',
    'ambiente' => 'certecf',
    'emisor' => ['rnc' => '131000001', 'razon_social' => 'WA EMISOR INTEGRACION', 'direccion' => 'Calle de prueba 1'],
];

// ---------------------------------------------------------------------------
// 1) Reserva antes del envio (Task 12)
// ---------------------------------------------------------------------------
echo "\n== 1) Reserva antes del envio ==\n";

// R1 E31: el ctx 'reservar' y la fila ya commiteada cuando sale el envio.
$visto = null;
$r = correr($pdo, $ids, ['espia' => function (string $metodo, string $xml, ?string $eNcf) use ($pdo, &$visto): void {
    $st = $pdo->prepare('SELECT estado_dgii, xml_firmado, codigo_seguridad, rfce_xml, respuesta_dgii FROM facturas WHERE e_ncf = :e');
    $st->execute([':e' => (string) $eNcf]);
    $visto = ['metodo' => $metodo, 'xml' => $xml, 'fila' => $st->fetch(PDO::FETCH_ASSOC) ?: null];
}]);
$ctx = $r['ctxs']['reservar'] ?? [];
wa_chk('R1: emitir termina sin excepcion', $r['err'] === null, descErr($r['err']));
wa_chk('R1: la primera fase es reservar', ($r['fases'][0] ?? null) === 'reservar', $r['fases']);
wa_chk('R1: ctx reservar trae exactamente las 13 claves, en orden', array_keys($ctx) === CLAVES_RESERVAR, array_keys($ctx));
wa_chk('R1: ctx con ambiente del token, ambiente de la secuencia y flujo ECF',
    ($ctx['ambiente'] ?? null) === 'certecf' && ($ctx['ambiente_secuencia'] ?? null) === 'certecf'
    && ($ctx['flujo'] ?? null) === 'ECF' && array_key_exists('rfce_xml', $ctx) && $ctx['rfce_xml'] === null, $ctx);
wa_chk('R1: ctx dispensado, rango_id y secuencia_valor del dispensador',
    ($ctx['dispensado'] ?? null) === true && is_int($ctx['rango_id'] ?? null) && ($ctx['secuencia_valor'] ?? null) === 1, $ctx);
wa_chk('R1: ctx e_ncf, codigo_seguridad y fechas Y-m-d H:i:s',
    ($ctx['e_ncf'] ?? null) === $r['e_ncf'] && ($ctx['codigo_seguridad'] ?? null) === 'QUJDRE'
    && (bool) preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) ($ctx['desde'] ?? ''))
    && (bool) preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) ($ctx['fecha_emision_dgii'] ?? '')), $ctx);
wa_chk('R1: al enviar, la fila ya existe en PENDIENTE_ENVIO', ($visto['fila']['estado_dgii'] ?? null) === 'PENDIENTE_ENVIO', $visto['fila'] ?? null);
wa_chk('R1: al enviar, xml_firmado es el XML firmado del ctx y el que sale',
    isset($ctx['signed_xml']) && ($visto['fila']['xml_firmado'] ?? null) === $ctx['signed_xml'] && ($visto['xml'] ?? null) === $ctx['signed_xml']);
wa_chk('R1: al enviar, codigo_seguridad ya esta guardado', ($visto['fila']['codigo_seguridad'] ?? null) === 'QUJDRE', $visto['fila']['codigo_seguridad'] ?? null);
wa_chk('R1: contador +1', $r['delta'] === 1, $r['delta']);
wa_chk("R1: log 'reservado, enviando'", str_contains($r['log'], "[ECF e-NCF={$r['e_ncf']} factura_id=") && str_contains($r['log'], '] reservado, enviando'));
chkComun('R1', $r);

// R2 E32 < 250k: el RFCE firmado ya esta en la fila cuando sale.
$visto = null;
$r = correr($pdo, $ids, ['tipo' => '32', 'espia' => function (string $metodo, string $xml, ?string $eNcf) use ($pdo, &$visto): void {
    $st = $pdo->prepare('SELECT estado_dgii, rfce_xml, respuesta_dgii FROM facturas WHERE e_ncf = :e');
    $st->execute([':e' => (string) $eNcf]);
    $visto = ['metodo' => $metodo, 'xml' => $xml, 'fila' => $st->fetch(PDO::FETCH_ASSOC) ?: null];
}]);
wa_chk('R2: se envia por recibirResumen', ($r['rec']->llamadas[0]['metodo'] ?? null) === 'recibirResumen', $r['rec']->llamadas);
wa_chk('R2: al enviar, la fila guarda el RFCE firmado que sale',
    ($visto['fila']['rfce_xml'] ?? null) === ($visto['xml'] ?? 'x') && str_contains((string) ($visto['xml'] ?? ''), '<RFCE>'));
wa_chk('R2: al enviar, la meta de la reserva dice flujo RFCE',
    (facturaModel::metaEnvio($visto['fila']['respuesta_dgii'] ?? null)['flujo'] ?? null) === 'RFCE', $visto['fila']['respuesta_dgii'] ?? null);
wa_chk('R2: tiene_rfce = 1', (int) ($r['filas'][0]['tiene_rfce'] ?? 0) === 1, $r['filas']);
wa_chk('R2: ctx reservar con flujo RFCE y rfce_xml',
    ($r['ctxs']['reservar']['flujo'] ?? null) === 'RFCE' && is_string($r['ctxs']['reservar']['rfce_xml'] ?? null));
chkComun('R2', $r);

// R3 el gancho lanza en 'reservar': nada se envio, el numero vuelve.
$falla = new RuntimeException('falla de prueba en reservar');
$r = correr($pdo, $ids, ['trucos' => ['reservar' => function () use ($falla) {
    throw $falla;
}]]);
wa_chk('R3: sale la misma excepcion', $r['err'] === $falla, descErr($r['err']));
wa_chk('R3: contador sin cambio (devuelto)', $r['delta'] === 0, $r['delta']);
wa_chk('R3: sin fila', $r['total_filas'] === 0, $r['filas']);
wa_chk('R3: la recepcion no se llamo', $r['rec']->llamadas === [], $r['rec']->llamadas);
chkComun('R3', $r);

// R4 el gancho dice "ocupado": otra fila es duena, el numero NO vuelve.
$r = correr($pdo, $ids, ['trucos' => ['reservar' => function (array $ctx) {
    throw new EcfNumeroOcupadoException((string) $ctx['e_ncf'], (string) $ctx['ambiente'], 'prueba');
}]]);
wa_chk('R4: sale EcfNumeroOcupadoException', $r['err'] instanceof EcfNumeroOcupadoException, $r['err'] ? get_class($r['err']) : null);
wa_chk('R4: contador +1 (no se devuelve)', $r['delta'] === 1, $r['delta']);
wa_chk('R4: no se intento devolver el numero', $r['ncf']->rollbacks === [], $r['ncf']->rollbacks);
wa_chk('R4: sin fila', $r['total_filas'] === 0);
wa_chk('R4: la recepcion no se llamo', $r['rec']->llamadas === []);
wa_chk("R4: log 'ocupado al reservar'", str_contains($r['log'], "[ECF] e-NCF {$r['e_ncf']} ocupado al reservar: la secuencia queda avanzada"));
chkComun('R4', $r);

// R5 CR en el XML firmado: falla el pre-vuelo, antes de autenticar.
$r = correr($pdo, $ids, ['con_cr' => true]);
wa_chk('R5: RuntimeException del pre-vuelo de CR',
    $r['err'] instanceof RuntimeException && str_contains($r['err']->getMessage(), 'retornos de carro'), descErr($r['err']));
wa_chk('R5: contador sin cambio', $r['delta'] === 0, $r['delta']);
wa_chk('R5: sin fila', $r['total_filas'] === 0);
wa_chk('R5: el gancho nunca se llamo', $r['fases'] === [], $r['fases']);
wa_chk('R5: no se autentico', $r['auth']->llamadas === 0);
wa_chk('R5: la recepcion no se llamo', $r['rec']->llamadas === []);
chkComun('R5', $r);

// R6 integracion con gancho: se rechaza antes de tocar nada.
$r = correr($pdo, $ids, ['payload' => $integracion]);
wa_chk('R6: InvalidArgumentException',
    $r['err'] instanceof InvalidArgumentException
    && $r['err']->getMessage() === 'write-ahead no aplica a integracion (sin DB de tenant)', descErr($r['err']));
wa_chk('R6: FakeAuth no se llamo', $r['auth']->llamadas === 0);
wa_chk('R6: FakeNcf no se llamo', $r['ncf']->dispensas === 0 && $r['ncf']->rollbacks === []);
wa_chk('R6: el gancho no se llamo', $r['fases'] === []);
chkComun('R6', $r);

// R7 ncf_sequences inaccesible: la excepcion del dispensador sale tal cual.
$r = correr($pdo, $ids, ['fallo_dispense' => true]);
wa_chk('R7: sale RuntimeException(ncf_sequences inaccesible: test)',
    $r['err'] instanceof RuntimeException && $r['err']->getMessage() === 'ncf_sequences inaccesible: test', descErr($r['err']));
wa_chk('R7: sin fila', $r['total_filas'] === 0);
wa_chk('R7: ni gancho ni recepcion', $r['fases'] === [] && $r['rec']->llamadas === []);
wa_chk('R7: contador sin cambio', $r['delta'] === 0, $r['delta']);
chkComun('R7', $r);

// R8 el gancho commitea la reserva y deja OTRA transaccion abierta.
$r = correr($pdo, $ids, ['trucos' => ['reservar' => function (array $ctx, facturaModel $m, array $factura) use ($pdo) {
    $reserva = $m->reservarFacturaECF($factura, $ctx);
    $pdo->beginTransaction();
    return $reserva;
}]]);
wa_chk("R8: RuntimeException('persistencia dejo una transaccion abierta')",
    $r['err'] instanceof RuntimeException && $r['err']->getMessage() === 'persistencia dejo una transaccion abierta', descErr($r['err']));
wa_chk('R8: la fila sigue en PENDIENTE_ENVIO', count($r['filas']) === 1 && ($r['filas'][0]['estado_dgii'] ?? null) === 'PENDIENTE_ENVIO', $r['filas']);
wa_chk('R8: contador +1 (la fila es duena del numero)', $r['delta'] === 1, $r['delta']);
wa_chk("R8: log 'fila reservada sin enviar'", str_contains($r['log'], 'fila reservada sin enviar'));
wa_chk('R8: la recepcion no se llamo', $r['rec']->llamadas === []);
chkComun('R8', $r);

// R9 el gancho inserta sin commitear y devuelve ese id: el rollback lo borra.
$r = correr($pdo, $ids, ['trucos' => ['reservar' => function (array $ctx) use ($pdo) {
    $id = insertarSinCommit($pdo, $ctx);
    return ['factura_id' => $id, 'e_ncf' => $ctx['e_ncf'], 'desde' => $ctx['desde']];
}]]);
wa_chk("R9: RuntimeException('persistencia dejo una transaccion abierta')",
    $r['err'] instanceof RuntimeException && $r['err']->getMessage() === 'persistencia dejo una transaccion abierta', descErr($r['err']));
wa_chk('R9: sin fila', $r['total_filas'] === 0, $r['filas']);
wa_chk('R9: contador sin cambio (devuelto)', $r['delta'] === 0, $r['delta']);
wa_chk("R9: sin log 'fila reservada sin enviar'", !str_contains($r['log'], 'fila reservada sin enviar'));
chkComun('R9', $r);

// R10 el gancho devuelve algo sin factura_id.
$r = correr($pdo, $ids, ['trucos' => ['reservar' => fn() => ['x' => 1]]]);
wa_chk("R10: RuntimeException('persistencia no devolvio factura_id')",
    $r['err'] instanceof RuntimeException && $r['err']->getMessage() === 'persistencia no devolvio factura_id', descErr($r['err']));
wa_chk('R10: contador sin cambio (devuelto)', $r['delta'] === 0, $r['delta']);
wa_chk('R10: sin fila ni envio', $r['total_filas'] === 0 && $r['rec']->llamadas === []);
chkComun('R10', $r);

// R10b el gancho COMMITEA su fila y devuelve algo sin factura_id: la fila es
// duena del numero aunque el retorno no lo diga (lo decide la base, no el
// retorno), asi que el numero NO vuelve.
$r = correr($pdo, $ids, ['trucos' => ['reservar' => function (array $ctx) use ($pdo) {
    insertarSinCommit($pdo, $ctx);
    $pdo->commit();
    return ['x' => 1];
}]]);
wa_chk("R10b: RuntimeException('persistencia no devolvio factura_id')",
    $r['err'] instanceof RuntimeException && $r['err']->getMessage() === 'persistencia no devolvio factura_id', descErr($r['err']));
wa_chk('R10b: contador +1 (la fila es duena del numero)', $r['delta'] === 1, $r['delta']);
wa_chk('R10b: la fila sigue en PENDIENTE_ENVIO', count($r['filas']) === 1 && ($r['filas'][0]['estado_dgii'] ?? null) === 'PENDIENTE_ENVIO', $r['filas']);
wa_chk('R10b: no se intento devolver el numero', $r['ncf']->rollbacks === [], $r['ncf']->rollbacks);
wa_chk("R10b: log 'fila reservada sin enviar'", str_contains($r['log'], "[ECF e-NCF={$r['e_ncf']} factura_id=") && str_contains($r['log'], '] fila reservada sin enviar'));
chkComun('R10b', $r);

// R11 el gancho abre transaccion, inserta y lanza: el catch la cierra
// ANTES de devolver el numero (si no, la devolucion se perderia con ella).
$falla = new RuntimeException('falla de prueba con la transaccion abierta');
$r = correr($pdo, $ids, ['trucos' => ['reservar' => function (array $ctx) use ($pdo, $falla) {
    insertarSinCommit($pdo, $ctx);
    throw $falla;
}]]);
wa_chk('R11: sale la misma excepcion', $r['err'] === $falla, descErr($r['err']));
wa_chk('R11: sin fila', $r['total_filas'] === 0, $r['filas']);
wa_chk('R11: contador sin cambio (la devolucion no quedo dentro de la transaccion)', $r['delta'] === 0, $r['delta']);
chkComun('R11', $r);

// R11b el gancho COMMITEA su fila y DESPUES lanza: la fila es duena del numero
// aunque el gancho no haya devuelto nada (lo decide la base), asi que el numero
// NO vuelve y la excepcion sale tal cual.
$falla = new RuntimeException('falla despues del commit');
$r = correr($pdo, $ids, ['trucos' => ['reservar' => function (array $ctx) use ($pdo, $falla) {
    insertarSinCommit($pdo, $ctx);
    $pdo->commit();
    throw $falla;
}]]);
wa_chk('R11b: sale la misma excepcion', $r['err'] === $falla, descErr($r['err']));
wa_chk('R11b: contador +1 (la fila es duena del numero)', $r['delta'] === 1, $r['delta']);
wa_chk('R11b: la fila sigue en PENDIENTE_ENVIO', count($r['filas']) === 1 && ($r['filas'][0]['estado_dgii'] ?? null) === 'PENDIENTE_ENVIO', $r['filas']);
wa_chk('R11b: no se intento devolver el numero', $r['ncf']->rollbacks === [], $r['ncf']->rollbacks);
wa_chk("R11b: log 'fila reservada sin enviar'", str_contains($r['log'], "[ECF e-NCF={$r['e_ncf']} factura_id=") && str_contains($r['log'], '] fila reservada sin enviar'));
chkComun('R11b', $r);

// R12 = R3 con un rollback del contador que no coincide: queda en la bitacora.
$r = correr($pdo, $ids, ['rollback_falso' => true, 'trucos' => ['reservar' => function () {
    throw new RuntimeException('falla de prueba en reservar');
}]]);
$auditoria = $pdo->query("SELECT module, entity_type, entity_id, success, error_message, description, new_values
                          FROM audit_logs WHERE action = 'NCF_SEQUENCE_UPDATE'")->fetchAll(PDO::FETCH_ASSOC);
$a = $auditoria[0] ?? [];
$nuevos = json_decode((string) ($a['new_values'] ?? ''), true);
wa_chk('R12: exactamente una fila NCF_SEQUENCE_UPDATE', count($auditoria) === 1, $auditoria);
wa_chk('R12: success 0, modulo ncf, entity_type ncf_sequence, entity_id = e-NCF',
    (int) ($a['success'] ?? 1) === 0 && ($a['module'] ?? null) === 'ncf'
    && ($a['entity_type'] ?? null) === 'ncf_sequence' && ($a['entity_id'] ?? null) === $r['e_ncf'], $a);
wa_chk("R12: error_message 'rollback sin coincidencia' y la descripcion del spec",
    ($a['error_message'] ?? null) === 'rollback sin coincidencia'
    && ($a['description'] ?? null) === 'e-NCF consumido sin fila: no se pudo devolver a la secuencia.', $a);
wa_chk('R12: new_values con e_ncf, tipo_ecf, rango_id, secuencia_valor, ambiente_secuencia y causa',
    is_array($nuevos) && array_keys($nuevos) === ['e_ncf', 'tipo_ecf', 'rango_id', 'secuencia_valor', 'ambiente_secuencia', 'causa']
    && $nuevos['e_ncf'] === $r['e_ncf'] && $nuevos['tipo_ecf'] === '31' && is_int($nuevos['rango_id'])
    && $nuevos['secuencia_valor'] === 1 && $nuevos['ambiente_secuencia'] === 'certecf' && $nuevos['causa'] === 'RuntimeException', $nuevos);
wa_chk('R12: contador +1 (no se pudo devolver)', $r['delta'] === 1, $r['delta']);
chkComun('R12', $r);

// R13 integracion sin gancho y DGII colgada: la misma RuntimeException de siempre.
$r = correr($pdo, $ids, ['sin_gancho' => true, 'modo' => 'cuelga', 'payload' => $integracion]);
wa_chk("R13: RuntimeException('HTTP request failed: timeout'), clase exacta",
    $r['err'] !== null && get_class($r['err']) === RuntimeException::class && $r['err']->getMessage() === 'HTTP request failed: timeout',
    $r['err'] ? get_class($r['err']) . ': ' . descErr($r['err']) : null);
wa_chk('R13: se envio una vez', count($r['rec']->llamadas) === 1, $r['rec']->llamadas);
wa_chk('R13: ninguna fila ni movimiento de contador', $r['total_filas'] === 0 && $r['delta'] === 0, [$r['total_filas'], $r['delta']]);
chkComun('R13', $r);

// ---------------------------------------------------------------------------
// 2) Confirmar o marcar incierto tras el envio (Task 13)
// respuesta_dgii: las filas en vuelo llevan la meta 6.3, sin XML y bajo
// 64 KB; una fila confirmada lleva lo que escribio confirmarFacturaECF: el
// JSON de la respuesta (e-CF), el JSON del texto crudo (raw200) o NULL (RFCE).
// ---------------------------------------------------------------------------
echo "\n== 2) Confirmar o marcar incierto ==\n";

const CLAVES_CONFIRMAR = [
    'reserva', 'estado', 'track_id', 'dgii_response', 'dgii_status_code',
    'rfce_track_id', 'rfce_estado', 'rfce_response', 'rfce_status_code', 'secuencia_utilizada',
];
const CLAVES_INCIERTO = [
    'fase', 'desde', 'flujo', 'ambiente_secuencia', 'dispensado', 'rango_id', 'secuencia_valor',
    'fase_fallida', 'clase', 'error', 'resultado_parcial', 'intentos',
];
const CLAVES_PROYECCION = [
    'estado', 'track_id', 'dgii_status_code', 'dgii_response', 'rfce_estado', 'rfce_track_id', 'rfce_status_code', 'secuencia_utilizada',
];

/** Una fila en vuelo: respuesta_dgii con la meta 6.3, sin XML y bajo TEXT. */
function chkEnVuelo(string $n, array $f, string $faseFallida): void
{
    $resp = (string) ($f['respuesta_dgii'] ?? '');
    $meta = json_decode($resp, true);
    wa_chk("{$n}: respuesta_dgii trae las claves de 6.3, en orden",
        is_array($meta) && array_keys($meta) === CLAVES_INCIERTO, is_array($meta) ? array_keys($meta) : $resp);
    wa_chk("{$n}: fase ENVIO_INCIERTO, fase_fallida {$faseFallida}, intentos 0",
        ($meta['fase'] ?? null) === 'ENVIO_INCIERTO' && ($meta['fase_fallida'] ?? null) === $faseFallida && ($meta['intentos'] ?? null) === 0, $meta);
    wa_chk("{$n}: respuesta_dgii bajo 65535 bytes", strlen($resp) < 65535, strlen($resp));
    wa_chk("{$n}: respuesta_dgii sin XML",
        !str_contains($resp, '<') && !str_contains($resp, 'signed_xml') && !str_contains($resp, 'rfce_xml') && !str_contains($resp, 'xml_firmado'));
}

// C1 acepta E31: fila final ANTES del contador, factura_id en la respuesta.
$r = correr($pdo, $ids);
$f = $r['filas'][0] ?? [];
wa_chk('C1: fases [reservar, confirmar]', $r['fases'] === ['reservar', 'confirmar'], $r['fases']);
wa_chk('C1: ctx confirmar = las 13 de reservar + reserva + las de confirmar, en orden',
    array_keys($r['ctxs']['confirmar'] ?? []) === array_merge(CLAVES_RESERVAR, CLAVES_CONFIRMAR), array_keys($r['ctxs']['confirmar'] ?? []));
wa_chk('C1: ctx confirmar con estado, track_id y secuencia_utilizada true',
    ($r['ctxs']['confirmar']['estado'] ?? null) === 'ACEPTADO' && ($r['ctxs']['confirmar']['track_id'] ?? null) === 'track-' . $r['e_ncf']
    && ($r['ctxs']['confirmar']['secuencia_utilizada'] ?? null) === true, $r['ctxs']['confirmar'] ?? null);
wa_chk('C1: una fila ACEPTADO con track_id', count($r['filas']) === 1 && ($f['estado_dgii'] ?? null) === 'ACEPTADO'
    && ($f['track_id'] ?? null) === 'track-' . $r['e_ncf'], $r['filas']);
wa_chk('C1: secuencia_utilizada = 1', (string) ($f['secuencia_utilizada'] ?? '') === '1', $f['secuencia_utilizada'] ?? null);
wa_chk('C1: respuesta_dgii es el JSON de la respuesta de la DGII',
    json_decode((string) ($f['respuesta_dgii'] ?? ''), true) === ['trackId' => 'track-' . $r['e_ncf'], 'codigo' => 1, 'estado' => 'Aceptado', 'secuenciaUtilizada' => true],
    $f['respuesta_dgii'] ?? null);
wa_chk('C1: contador +1', $r['delta'] === 1, $r['delta']);
wa_chk('C1: emitir devuelve factura_id = id de la fila', ($r['res']['factura_id'] ?? null) === (int) ($f['id'] ?? 0), $r['res']['factura_id'] ?? null);
chkComun('C1', $r);

// C2 rechaza: la fila queda RECHAZADO antes de devolver el numero; el reintento lo reusa.
$r = correr($pdo, $ids, ['modo' => 'rechaza']);
$iConfirmar = array_search('confirmar', $r['orden'], true);
$iRollback = array_search('rollback', $r['orden'], true);
wa_chk("C2: 'confirmar' antes que el rollback del contador",
    $iConfirmar !== false && $iRollback !== false && $iConfirmar < $iRollback, $r['orden']);
wa_chk('C2: fila RECHAZADO', count($r['filas']) === 1 && ($r['filas'][0]['estado_dgii'] ?? null) === 'RECHAZADO', $r['filas']);
wa_chk('C2: contador devuelto (delta 0)', $r['delta'] === 0, $r['delta']);
chkComun('C2', $r);
$primerId = (int) ($r['filas'][0]['id'] ?? 0);
$r2 = correr($pdo, $ids, ['limpiar' => false, 'modo' => 'acepta']);
$porId = array_column($r2['filas'], null, 'id');
wa_chk('C2: el reintento toma el mismo e-NCF', ($r2['ctxs']['reservar']['e_ncf'] ?? null) === $r['e_ncf'], $r2['ctxs']['reservar']['e_ncf'] ?? null);
wa_chk('C2: la primera fila queda RECHAZADO_ARCHIVADO con e_ncf NULL',
    ($porId[$primerId]['estado_dgii'] ?? null) === 'RECHAZADO_ARCHIVADO' && array_key_exists('e_ncf', $porId[$primerId] ?? [])
    && $porId[$primerId]['e_ncf'] === null, $r2['filas']);
wa_chk('C2: y hay una fila nueva ACEPTADO', count($r2['filas']) === 2 && (end($r2['filas'])['estado_dgii'] ?? null) === 'ACEPTADO', $r2['filas']);
chkComun('C2 reintento', $r2);

// C3 cuelga y html500: ENVIO_INCIERTO, contador intacto, excepcion con la fila.
foreach (['cuelga' => 'HTTP request failed: timeout', 'html500' => 'DGII authenticated request failed: HTTP 500'] as $modo => $prefijo) {
    $r = correr($pdo, $ids, ['modo' => $modo]);
    $e = $r['err'];
    $f = $r['filas'][0] ?? [];
    wa_chk("C3 {$modo}: sale EcfEnvioInciertoException", $e instanceof EcfEnvioInciertoException, $e ? get_class($e) . ': ' . descErr($e) : null);
    if ($e instanceof EcfEnvioInciertoException) {
        wa_chk("C3 {$modo}: facturaId = la fila, e-NCF y ambiente",
            $e->facturaId === (int) ($f['id'] ?? 0) && $e->eNcf === $r['e_ncf'] && $e->ambiente === 'certecf', [$e->facturaId, $e->eNcf, $e->ambiente]);
        wa_chk("C3 {$modo}: faseFallida envio, marcado true, resultadoParcial null",
            $e->faseFallida === 'envio' && $e->marcado === true && $e->resultadoParcial === null);
        wa_chk("C3 {$modo}: getPrevious es la excepcion original",
            $e->getPrevious() instanceof RuntimeException && str_starts_with($e->getPrevious()->getMessage(), $prefijo), descErr($e->getPrevious()));
    }
    wa_chk("C3 {$modo}: fases [reservar, incierto]", $r['fases'] === ['reservar', 'incierto'], $r['fases']);
    wa_chk("C3 {$modo}: fila ENVIO_INCIERTO", count($r['filas']) === 1 && ($f['estado_dgii'] ?? null) === 'ENVIO_INCIERTO', $r['filas']);
    wa_chk("C3 {$modo}: contador +1 y sin rollback", $r['delta'] === 1 && !in_array('rollback', $r['orden'], true), [$r['delta'], $r['orden']]);
    wa_chk("C3 {$modo}: log ENVIO_INCIERTO", str_contains($r['log'], 'ENVIO_INCIERTO fase=envio marcado=si'));
    chkEnVuelo("C3 {$modo}", $f, 'envio');
    chkComun("C3 {$modo}", $r);
}

// C4 raw200: ENVIADO sin track_id; respuesta_dgii guarda el texto crudo como JSON.
$r = correr($pdo, $ids, ['modo' => 'raw200']);
$f = $r['filas'][0] ?? [];
wa_chk('C4: fila ENVIADO con track_id NULL', ($f['estado_dgii'] ?? null) === 'ENVIADO' && array_key_exists('track_id', $f) && $f['track_id'] === null, $f);
wa_chk('C4: respuesta_dgii = \'"Recibido"\'', ($f['respuesta_dgii'] ?? null) === '"Recibido"', $f['respuesta_dgii'] ?? null);
chkComun('C4', $r);

// C5 el gancho lanza en 'confirmar': la respuesta de la DGII queda en resultado_parcial.
$r = correr($pdo, $ids, ['trucos' => ['confirmar' => function () {
    throw new RuntimeException('base caida al confirmar');
}]]);
$e = $r['err'];
$f = $r['filas'][0] ?? [];
wa_chk('C5: EcfEnvioInciertoException con faseFallida confirmar',
    $e instanceof EcfEnvioInciertoException && $e->faseFallida === 'confirmar' && $e->marcado === true, descErr($e));
wa_chk('C5: resultadoParcial con las 8 claves y el track_id de la DGII',
    $e instanceof EcfEnvioInciertoException && is_array($e->resultadoParcial) && array_keys($e->resultadoParcial) === CLAVES_PROYECCION
    && $e->resultadoParcial['track_id'] === 'track-' . $r['e_ncf'] && $e->resultadoParcial['estado'] === 'ACEPTADO',
    $e instanceof EcfEnvioInciertoException ? $e->resultadoParcial : null);
$meta = facturaModel::metaEnvio($f['respuesta_dgii'] ?? null);
wa_chk('C5: la fila guarda resultado_parcial.track_id', ($meta['resultado_parcial']['track_id'] ?? null) === 'track-' . $r['e_ncf'], $meta);
wa_chk('C5: contador +1 (sin reclamar)', $r['delta'] === 1 && !in_array('rollback', $r['orden'], true), $r['delta']);
chkEnVuelo('C5', $f, 'confirmar');
chkComun('C5', $r);

// C6 'confirmar' devuelve false (otra escritura movio la fila): no se reclama.
$r = correr($pdo, $ids, ['modo' => 'rechaza', 'trucos' => ['confirmar' => fn() => false]]);
wa_chk('C6: emitir termina sin excepcion', $r['err'] === null, descErr($r['err']));
wa_chk("C6: log CONFIRM_RACE", str_contains($r['log'], "[ECF e-NCF={$r['e_ncf']} factura_id=") && str_contains($r['log'], 'CONFIRM_RACE: otra escritura movio la fila; no se toca la secuencia'));
wa_chk('C6: no se llamo rollbackECFSequence', $r['ncf']->rollbacks === [], $r['ncf']->rollbacks);
wa_chk('C6: contador +1', $r['delta'] === 1, $r['delta']);
chkComun('C6', $r);

// C7 'incierto' tambien falla: la fila queda PENDIENTE_ENVIO y marcado false.
$r = correr($pdo, $ids, ['modo' => 'cuelga', 'trucos' => ['incierto' => function () {
    throw new RuntimeException('base caida al marcar');
}]]);
$e = $r['err'];
wa_chk('C7: EcfEnvioInciertoException con marcado false', $e instanceof EcfEnvioInciertoException && $e->marcado === false, descErr($e));
wa_chk('C7: la fila sigue PENDIENTE_ENVIO', ($r['filas'][0]['estado_dgii'] ?? null) === 'PENDIENTE_ENVIO', $r['filas']);
wa_chk("C7: log 'marcar incierto fallo' y marcado=no",
    str_contains($r['log'], 'marcar incierto fallo: base caida al marcar') && str_contains($r['log'], 'marcado=no'));
chkComun('C7', $r);

// C8 E32 < 250k acepta: RFCE_ACEPTADO con rfce_track_id.
$r = correr($pdo, $ids, ['tipo' => '32']);
$f = $r['filas'][0] ?? [];
$st = $pdo->prepare('SELECT rfce_track_id, rfce_estado FROM facturas WHERE id = :id');
$st->execute([':id' => (int) ($f['id'] ?? 0)]);
$rfce = $st->fetch(PDO::FETCH_ASSOC) ?: [];
wa_chk('C8: fila RFCE_ACEPTADO', ($f['estado_dgii'] ?? null) === 'RFCE_ACEPTADO', $f);
wa_chk('C8: rfce_track_id y rfce_estado guardados',
    ($rfce['rfce_track_id'] ?? null) === 'track-' . $r['e_ncf'] && ($rfce['rfce_estado'] ?? null) === 'ACEPTADO', $rfce);
wa_chk('C8: respuesta_dgii NULL en el flujo RFCE', array_key_exists('respuesta_dgii', $f) && $f['respuesta_dgii'] === null, $f['respuesta_dgii'] ?? null);
wa_chk('C8: emitir devuelve estado RFCE_ACEPTADO y factura_id',
    ($r['res']['estado'] ?? null) === 'RFCE_ACEPTADO' && ($r['res']['factura_id'] ?? null) === (int) ($f['id'] ?? 0), $r['res'] ?? null);
chkComun('C8', $r);

// C9 E32 < 250k NO_ENCONTRADO sin bandera: reintentable (REINTENTABLES).
$r = correr($pdo, $ids, ['tipo' => '32', 'modo' => 'no_encontrado_sin_flag']);
$iConfirmar = array_search('confirmar', $r['orden'], true);
$iRollback = array_search('rollback', $r['orden'], true);
wa_chk('C9: fila RFCE_NO_ENCONTRADO', ($r['filas'][0]['estado_dgii'] ?? null) === 'RFCE_NO_ENCONTRADO', $r['filas']);
wa_chk("C9: 'confirmar' antes que el rollback", $iConfirmar !== false && $iRollback !== false && $iConfirmar < $iRollback, $r['orden']);
wa_chk('C9: contador devuelto (delta 0)', $r['delta'] === 0, $r['delta']);
chkComun('C9', $r);
$primerId = (int) ($r['filas'][0]['id'] ?? 0);
$r2 = correr($pdo, $ids, ['tipo' => '32', 'limpiar' => false]);
$porId = array_column($r2['filas'], null, 'id');
wa_chk('C9: el reintento archiva RFCE_NO_ENCONTRADO_ARCHIVADO con e_ncf NULL',
    ($porId[$primerId]['estado_dgii'] ?? null) === 'RFCE_NO_ENCONTRADO_ARCHIVADO' && array_key_exists('e_ncf', $porId[$primerId] ?? [])
    && $porId[$primerId]['e_ncf'] === null, $r2['filas']);
wa_chk('C9: y hay una fila nueva RFCE_ACEPTADO', count($r2['filas']) === 2 && (end($r2['filas'])['estado_dgii'] ?? null) === 'RFCE_ACEPTADO', $r2['filas']);
chkComun('C9 reintento', $r2);

// ---------------------------------------------------------------------------
// 3) Registro de cierre para e-CF en vuelo (Task 14)
// ---------------------------------------------------------------------------
echo "\n== 3) Registro de cierre ==\n";

/** ECFEmissionService::$enVuelo (privado y estatico); null si todavia no existe. */
function enVuelo(): ?array
{
    return property_exists(ECFEmissionService::class, 'enVuelo')
        ? (new ReflectionProperty(ECFEmissionService::class, 'enVuelo'))->getValue()
        : null;
}

// S1 un proceso hijo muere con un fatal real con dos filas en vuelo.
wa_limpiar($pdo);
$antesHijo = wa_contador($pdo, 'E31');
$salidaHijo = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wa_ecf_hijo_' . getmypid() . '.out';
$proceso = proc_open(
    array_merge([PHP_BINARY], wa_php_flags(), [__FILE__, '--hijo=shutdown', '--tenant-db=' . $base]),
    [1 => ['file', $salidaHijo, 'w'], 2 => ['file', $salidaHijo, 'a']],
    $tuberias,
    dirname(__DIR__)
);
$pidHijo = is_resource($proceso) ? (int) (proc_get_status($proceso)['pid'] ?? 0) : 0;
$codigoHijo = is_resource($proceso) ? proc_close($proceso) : -1;
$salida = (string) @file_get_contents($salidaHijo);
@unlink($salidaHijo);
// El hijo escribe su error_log en un archivo propio (como este proceso, arriba).
if ($pidHijo > 0) {
    @unlink(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wa_ecf_' . $pidHijo . '.log');
}
wa_chk('S1: el hijo murio con un fatal (codigo 255, no llego al final)',
    $codigoHijo === 255 && !str_contains($salida, 'NO DEBIO LLEGAR AQUI'), ['codigo' => $codigoHijo, 'salida' => mb_substr($salida, 0, 400)]);
$filasHijo = $pdo->query('SELECT id, e_ncf, estado_dgii, respuesta_dgii FROM facturas ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
wa_chk('S1: el hijo reservo E310000000001 y E310000000002',
    array_column($filasHijo, 'e_ncf') === ['E310000000001', 'E310000000002'], $filasHijo);
wa_chk('S1: contador +2', wa_contador($pdo, 'E31') - $antesHijo === 2);
foreach ($filasHijo as $f) {
    $meta = facturaModel::metaEnvio($f['respuesta_dgii']);
    wa_chk("S1 {$f['e_ncf']}: ENVIO_INCIERTO", $f['estado_dgii'] === 'ENVIO_INCIERTO', $f['estado_dgii']);
    wa_chk("S1 {$f['e_ncf']}: clase shutdown y el error del fatal",
        ($meta['clase'] ?? null) === 'shutdown' && str_contains((string) ($meta['error'] ?? ''), 'Allowed memory size'), $meta);
    wa_chk("S1 {$f['e_ncf']}: sin resultado_parcial (el cierre no lo manda)", array_key_exists('resultado_parcial', $meta) && $meta['resultado_parcial'] === null, $meta);
    chkEnVuelo("S1 {$f['e_ncf']}", $f, 'shutdown');
}

// S2-S5 en este proceso: el registro queda vacio en cada salida.
$r = correr($pdo, $ids);
wa_chk('S2: tras una emision aceptada el registro esta vacio', $r['err'] === null && enVuelo() === [], enVuelo());
$r = correr($pdo, $ids, ['modo' => 'cuelga']);
wa_chk('S3: tras EcfEnvioInciertoException el registro esta vacio',
    $r['err'] instanceof EcfEnvioInciertoException && enVuelo() === [], enVuelo());
$r = correr($pdo, $ids, ['trucos' => ['reservar' => function (array $ctx) {
    throw new EcfNumeroOcupadoException((string) $ctx['e_ncf'], (string) $ctx['ambiente'], 'prueba');
}]]);
wa_chk('S4: tras un ocupado el registro esta vacio (nunca se armo)',
    $r['err'] instanceof EcfNumeroOcupadoException && enVuelo() === [], enVuelo());
$r = correr($pdo, $ids, ['trucos' => ['reservar' => function (array $ctx, facturaModel $m, array $factura) use ($pdo) {
    $reserva = $m->reservarFacturaECF($factura, $ctx);
    $pdo->beginTransaction();
    return $reserva;
}]]);
wa_chk('S5: tras la transaccion abierta el registro esta vacio (nunca se armo)',
    $r['err'] instanceof RuntimeException && $r['err']->getMessage() === 'persistencia dejo una transaccion abierta' && enVuelo() === [], enVuelo());

// ---------------------------------------------------------------------------
// Cierre. Cada tarea agrega sus casos justo encima de este bloque.
// ---------------------------------------------------------------------------
wa_limpiar($pdo);
@unlink($GLOBALS['wa_ecf_log']);
wa_fin();
