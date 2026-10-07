<?php
/**
 * test_write_ahead_puro.php — Piezas del write-ahead del e-CF que se prueban
 * sin base de datos ni red (spec del write-ahead, 12.3.1).
 *
 * Donde hace falta una conexion se usa una falsa que anota el SQL y los
 * parametros: nunca toca MySQL. Cada tarea del plan agrega su seccion justo
 * encima del bloque de cierre (wa_fin), en orden.
 *
 * Uso:
 *   php tools/test_write_ahead_puro.php      (sale con 1 si algo falla)
 */

require_once __DIR__ . '/write_ahead_test_lib.php';
require_once __DIR__ . '/../src/Utils/FacturacionElectronica/EstadosEcf.php';

// ---------------------------------------------------------------------------
// 1) EstadosEcf: las listas del spec 4.6
// ---------------------------------------------------------------------------
echo "\n== 1) EstadosEcf ==\n";
wa_chk('REINTENTABLES: las tres de hoy mas RFCE_NO_ENCONTRADO',
    EstadosEcf::REINTENTABLES === ['RECHAZADO', 'RFCE_RECHAZADO', 'NO_ENCONTRADO', 'RFCE_NO_ENCONTRADO'], EstadosEcf::REINTENTABLES);
wa_chk('PENDIENTE_ENVIO y ENVIO_INCIERTO',
    EstadosEcf::PENDIENTE_ENVIO === 'PENDIENTE_ENVIO' && EstadosEcf::ENVIO_INCIERTO === 'ENVIO_INCIERTO');
wa_chk('EN_VUELO: PENDIENTE_ENVIO y ENVIO_INCIERTO',
    EstadosEcf::EN_VUELO === ['PENDIENTE_ENVIO', 'ENVIO_INCIERTO'], EstadosEcf::EN_VUELO);
wa_chk('SIN_VEREDICTO: en vuelo + ENVIADO, EN_PROCESO y sus RFCE_',
    EstadosEcf::SIN_VEREDICTO === ['PENDIENTE_ENVIO', 'ENVIO_INCIERTO', 'ENVIADO', 'EN_PROCESO', 'RFCE_ENVIADO', 'RFCE_EN_PROCESO'],
    EstadosEcf::SIN_VEREDICTO);
wa_chk('SIN_STOCK: reintentables + ERROR y RFCE_ERROR',
    EstadosEcf::SIN_STOCK === ['RECHAZADO', 'RFCE_RECHAZADO', 'NO_ENCONTRADO', 'RFCE_NO_ENCONTRADO', 'ERROR', 'RFCE_ERROR'],
    EstadosEcf::SIN_STOCK);
foreach (['REINTENTABLES', 'EN_VUELO', 'SIN_VEREDICTO', 'SIN_STOCK'] as $lista) {
    $valores = constant('EstadosEcf::' . $lista);
    wa_chk("{$lista}: sin repetidos", count(array_unique($valores)) === count($valores), $valores);
}
wa_chk('EN_VUELO esta dentro de SIN_VEREDICTO', array_diff(EstadosEcf::EN_VUELO, EstadosEcf::SIN_VEREDICTO) === []);
wa_chk('REINTENTABLES esta dentro de SIN_STOCK', array_diff(EstadosEcf::REINTENTABLES, EstadosEcf::SIN_STOCK) === []);
wa_chk('una fila en vuelo nunca es reintentable', array_intersect(EstadosEcf::EN_VUELO, EstadosEcf::REINTENTABLES) === []);
wa_chk('una fila en vuelo no esta en SIN_STOCK (no es veredicto)', array_intersect(EstadosEcf::EN_VUELO, EstadosEcf::SIN_STOCK) === []);
wa_chk("sql(['A','B']) === \"'A', 'B'\"", EstadosEcf::sql(['A', 'B']) === "'A', 'B'", EstadosEcf::sql(['A', 'B']));
wa_chk('sql(EN_VUELO) para un IN (...)',
    EstadosEcf::sql(EstadosEcf::EN_VUELO) === "'PENDIENTE_ENVIO', 'ENVIO_INCIERTO'", EstadosEcf::sql(EstadosEcf::EN_VUELO));
$rechaza = static function (array $estados): bool {
    try {
        EstadosEcf::sql($estados);
        return false;
    } catch (InvalidArgumentException $e) {
        return true;
    }
};
wa_chk('sql() rechaza comilla y salto de linea final', $rechaza(["ACEPTADO' OR '1'='1"]) && $rechaza(["ABC\n"]));
wa_chk('sql() rechaza la lista vacia (IN () no es SQL)', $rechaza([]));
$textoClase = (string) file_get_contents(__DIR__ . '/../src/Utils/FacturacionElectronica/EstadosEcf.php');
wa_chk('EstadosEcf no carga otros archivos (sin require ni include)',
    !preg_match('/\b(require|include)(_once)?\b/', $textoClase));
wa_chk('EstadosEcf es final', (new ReflectionClass(EstadosEcf::class))->isFinal());

// ---------------------------------------------------------------------------
// 2) Excepciones: numero ocupado y envio incierto (spec 4.5)
// ---------------------------------------------------------------------------
require_once __DIR__ . '/../src/Utils/FacturacionElectronica/EcfNumeroOcupadoException.php';
require_once __DIR__ . '/../src/Utils/FacturacionElectronica/EcfEnvioInciertoException.php';

echo "\n== 2) EcfNumeroOcupadoException y EcfEnvioInciertoException ==\n";
$causaPdo = new PDOException('Duplicate entry');
$ocupado = new EcfNumeroOcupadoException('E310000000123', 'ecf', 'fila 9 en ACEPTADO', $causaPdo);
wa_chk('Ocupado es EcfUsuarioException (el 502 de la app muestra su texto)', $ocupado instanceof EcfUsuarioException);
wa_chk('Ocupado: texto para el usuario exacto',
    $ocupado->getMensajeUsuario() === 'No se pudo asignar el número de comprobante. Inténtalo de nuevo.', $ocupado->getMensajeUsuario());
wa_chk('Ocupado: MENSAJE_USUARIO es ese mismo texto', EcfNumeroOcupadoException::MENSAJE_USUARIO === $ocupado->getMensajeUsuario());
wa_chk('Ocupado: el texto tecnico trae e-NCF, ambiente y causa',
    $ocupado->getMessage() === 'e-NCF E310000000123 ocupado en ambiente ecf: fila 9 en ACEPTADO', $ocupado->getMessage());
wa_chk('Ocupado: conserva la excepcion anterior', $ocupado->getPrevious() === $causaPdo);
wa_chk('Ocupado sin excepcion anterior: getPrevious() null',
    (new EcfNumeroOcupadoException('E310000000124', 'ecf', 'archivo sin coincidencia'))->getPrevious() === null);

$causaRed = new RuntimeException('HTTP request failed: timeout');
$parcial = ['estado' => 'ACEPTADO', 'track_id' => 'track-E310000000123', 'dgii_status_code' => 200, 'dgii_response' => ['codigo' => 1],
    'rfce_estado' => null, 'rfce_track_id' => null, 'rfce_status_code' => null, 'secuencia_utilizada' => true];
$incierto = new EcfEnvioInciertoException('E310000000123', 41, 'ecf', 'envio', $parcial, true, $causaRed);
wa_chk('Incierto: mensaje del spec',
    $incierto->getMessage() === 'Envio a la DGII sin confirmar: e-NCF E310000000123, factura_id 41, fase envio', $incierto->getMessage());
wa_chk('Incierto: las propiedades guardan lo recibido',
    [$incierto->eNcf, $incierto->facturaId, $incierto->ambiente, $incierto->faseFallida, $incierto->resultadoParcial, $incierto->marcado]
    === ['E310000000123', 41, 'ecf', 'envio', $parcial, true]);
$sinParcial = new EcfEnvioInciertoException('E320000000007', 42, 'certecf', 'confirmar', null, false);
wa_chk('Incierto: resultadoParcial null y marcado false se aceptan',
    $sinParcial->resultadoParcial === null && $sinParcial->marcado === false && $sinParcial->getPrevious() === null);
$soloLectura = array_filter(
    ['eNcf', 'facturaId', 'ambiente', 'faseFallida', 'resultadoParcial', 'marcado'],
    static fn(string $p): bool => (new ReflectionProperty(EcfEnvioInciertoException::class, $p))->isReadOnly()
);
wa_chk('Incierto: las seis propiedades son readonly', count($soloLectura) === 6, array_values($soloLectura));
try {
    $incierto->marcado = false;
    $escribio = true;
} catch (Error $e) {
    $escribio = false;
}
wa_chk('Incierto: escribir marcado lanza Error', $escribio === false && $incierto->marcado === true);
wa_chk('Incierto: conserva la excepcion original', $incierto->getPrevious() === $causaRed);
wa_chk('Incierto: es RuntimeException y no EcfUsuarioException',
    $incierto instanceof RuntimeException && !($incierto instanceof EcfUsuarioException));

// ---------------------------------------------------------------------------
// 3) facturaModel: metaEnvio, filtro 'pendiente' y notas en vuelo (spec 4.6, 2.1)
// ---------------------------------------------------------------------------
require_once __DIR__ . '/../src/Models/facturaModel.php';

echo "\n== 3) facturaModel: metaEnvio, filtro pendiente, notas en vuelo ==\n";
wa_chk('metaEnvio(null) = []', facturaModel::metaEnvio(null) === []);
wa_chk('metaEnvio(\'"Recibido"\') = [] (fila ENVIADO vieja: JSON de un texto)', facturaModel::metaEnvio('"Recibido"') === []);
wa_chk("metaEnvio('{bad') = [] (JSON roto)", facturaModel::metaEnvio('{bad') === []);
$metaReserva = '{"fase":"PENDIENTE_ENVIO","desde":"2026-10-05 14:03:11","flujo":"ECF","ambiente_secuencia":"ecf",'
    . '"dispensado":true,"rango_id":7,"secuencia_valor":123}';
$meta = facturaModel::metaEnvio($metaReserva);
wa_chk('metaEnvio(JSON 6.3) trae rango_id 7 y secuencia_valor 123',
    ($meta['rango_id'] ?? null) === 7 && ($meta['secuencia_valor'] ?? null) === 123 && ($meta['fase'] ?? null) === 'PENDIENTE_ENVIO', $meta);

$filtros = static function ($estado, $tipoEcf): array {
    $modelo = (new ReflectionClass(facturaModel::class))->newInstanceWithoutConstructor();
    $condiciones = [];
    $params = [];
    $args = [&$condiciones, &$params, $estado, $tipoEcf];
    (new ReflectionMethod(facturaModel::class, 'applyEstadoTipoFilters'))->invokeArgs($modelo, $args);
    return [$condiciones, $params];
};
[$cond, $par] = $filtros('pendiente', null);
wa_chk("'pendiente': una condicion IN con marcadores con nombre",
    $cond === ['f.estado_dgii IN (:est0, :est1, :est2, :est3, :est4, :est5)'], $cond);
wa_chk("'pendiente': los parametros son SIN_VEREDICTO en orden",
    $par === array_combine([':est0', ':est1', ':est2', ':est3', ':est4', ':est5'], EstadosEcf::SIN_VEREDICTO), $par);
[$cond, $par] = $filtros('pendiente', '31');
wa_chk("'pendiente' + tipo 31: suma la condicion y :tipo_ecf",
    count($cond) === 2 && $cond[1] === 'f.tipo_ecf = :tipo_ecf' && ($par[':tipo_ecf'] ?? null) === '31' && count($par) === 7, [$cond, $par]);
wa_chk("'aprobado' sin cambios", $filtros('aprobado', null) === [["f.estado_dgii LIKE '%ACEPTADO%'"], []], $filtros('aprobado', null));
wa_chk("'rechazado' sin cambios", $filtros('rechazado', null) === [["f.estado_dgii LIKE '%RECHAZADO%'"], []], $filtros('rechazado', null));
wa_chk('sin estado: ninguna condicion', $filtros(null, null) === [[], []], $filtros(null, null));
wa_chk('ESTADOS_NOTA_VIGENTE termina con EstadosEcf::EN_VUELO (una sola lista)',
    array_slice(facturaModel::ESTADOS_NOTA_VIGENTE, -2) === EstadosEcf::EN_VUELO, facturaModel::ESTADOS_NOTA_VIGENTE);

// ---------------------------------------------------------------------------
// 4) facturaModel::normalizarNotaReferencia (campos de la nota E33/E34)
// ---------------------------------------------------------------------------
echo "\n== 4) facturaModel::normalizarNotaReferencia ==\n";
$conRef = static fn(array $info): array => ['informacion_referencia' => $info];
$credito = facturaModel::normalizarNotaReferencia($conRef([
    'ncf_modificado' => 'E310000000020', 'fecha_ncf_modificado' => '05-10-2026',
    'codigo_modificacion' => '3', 'razon_modificacion' => '',
]), '34');
wa_chk('E34 con razon vacia: el default de credito',
    $credito['razon_modificacion'] === 'Nota de credito por ajuste de monto', $credito);
wa_chk("fecha '05-10-2026' (d-m-Y del payload) -> '2026-10-05' (DATE)", $credito['fecha_ncf_modificado'] === '2026-10-05', $credito);
wa_chk("codigo_modificacion '3' -> '3'", $credito['codigo_modificacion'] === '3', $credito);
wa_chk('ncf_modificado se guarda tal cual', $credito['ncf_modificado'] === 'E310000000020', $credito);
wa_chk('las cuatro claves, en el orden de las columnas',
    array_keys($credito) === ['ncf_modificado', 'fecha_ncf_modificado', 'codigo_modificacion', 'razon_modificacion'], array_keys($credito));
$debito = facturaModel::normalizarNotaReferencia($conRef(['ncf_modificado' => 'E310000000020', 'razon_modificacion' => '']), '33');
wa_chk('E33 con razon vacia: el default de debito', $debito['razon_modificacion'] === 'Nota de debito por ajuste de monto', $debito);
$vacio = ['ncf_modificado' => null, 'fecha_ncf_modificado' => null, 'codigo_modificacion' => null, 'razon_modificacion' => null];
wa_chk('E31 sin informacion_referencia: las cuatro en null', facturaModel::normalizarNotaReferencia([], '31') === $vacio,
    facturaModel::normalizarNotaReferencia([], '31'));
wa_chk('informacion_referencia que no es arreglo se ignora',
    facturaModel::normalizarNotaReferencia(['informacion_referencia' => 'x'], '31') === $vacio);
wa_chk('E31 con razon vacia: sin default (solo las notas lo llevan)',
    facturaModel::normalizarNotaReferencia($conRef(['razon_modificacion' => '']), '31')['razon_modificacion'] === null);
wa_chk("fecha '2026-10-05' (formato equivocado) -> null",
    facturaModel::normalizarNotaReferencia($conRef(['fecha_ncf_modificado' => '2026-10-05']), '34')['fecha_ncf_modificado'] === null);
wa_chk("ncf_modificado '' -> null",
    facturaModel::normalizarNotaReferencia($conRef(['ncf_modificado' => '']), '34')['ncf_modificado'] === null);
wa_chk('una razon explicita se conserva',
    facturaModel::normalizarNotaReferencia($conRef(['razon_modificacion' => 'Devolucion parcial']), '34')['razon_modificacion'] === 'Devolucion parcial');
$textoModelo = (string) file_get_contents(__DIR__ . '/../src/Models/facturaModel.php');
wa_chk("facturaModel usa TIPOS_NOTA: la lista ['33', '34'] aparece una sola vez (la constante)",
    substr_count($textoModelo, "['33', '34']") === 1, substr_count($textoModelo, "['33', '34']"));

// ---------------------------------------------------------------------------
// 5) facturaModel::reservarFacturaECF: flujo de control con una conexion falsa
// ---------------------------------------------------------------------------

/** Sentencia de GuionConexion: anota execute() y responde segun el guion. */
final class GuionSentencia
{
    public function __construct(private GuionConexion $c, private string $sql)
    {
    }

    public function execute(?array $params = null): bool
    {
        $this->c->llamadas[] = ['execute', $this->sql, $params ?? []];
        $error = $this->c->tomarError($this->sql);
        if ($error !== null) {
            throw $error;
        }
        return true;
    }

    public function fetchAll(int $modo = PDO::FETCH_BOTH): array
    {
        return $this->c->filasPara($this->sql);
    }

    public function fetchColumn(int $columna = 0): mixed
    {
        $filas = $this->c->filasPara($this->sql);
        return $filas === [] ? false : array_values($filas[0])[$columna];
    }

    public function rowCount(): int
    {
        return $this->c->rowCountPara($this->sql);
    }
}

/**
 * Conexion falsa con guion: filas, rowCount y errores por fragmento de SQL, y
 * su propio estado de transaccion, como PDO. Anota cada llamada. Nunca toca MySQL.
 */
final class GuionConexion
{
    public array $llamadas = [];
    public bool $enTransaccion = false;
    /** @var array<int, array{0:string, 1:Throwable}> errores por consumir: el primero cuyo fragmento calce */
    public array $errores = [];
    /** @var array<string, array> fragmento de SQL => filas que devuelve */
    public array $filas = [];
    /** @var array<string, int> fragmento de SQL => rowCount (por defecto 1) */
    public array $rowCounts = [];
    public bool $prepareFalla = false;

    public function beginTransaction(): bool
    {
        $this->llamadas[] = ['beginTransaction'];
        $this->enTransaccion = true;
        return true;
    }

    public function commit(): bool
    {
        $this->llamadas[] = ['commit'];
        $this->enTransaccion = false;
        return true;
    }

    public function rollBack(): bool
    {
        $this->llamadas[] = ['rollBack'];
        $this->enTransaccion = false;
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->enTransaccion;
    }

    public function lastInsertId(?string $nombre = null): string
    {
        return '41';
    }

    public function prepare(string $sql): GuionSentencia
    {
        if ($this->prepareFalla) {
            throw new PDOException('se cayo la conexion');
        }
        $this->llamadas[] = ['prepare', $sql];
        return new GuionSentencia($this, $sql);
    }

    public function tomarError(string $sql): ?Throwable
    {
        foreach ($this->errores as $i => [$fragmento, $error]) {
            if (str_contains($sql, $fragmento)) {
                unset($this->errores[$i]);
                return $error;
            }
        }
        return null;
    }

    public function filasPara(string $sql): array
    {
        foreach ($this->filas as $fragmento => $filas) {
            if (str_contains($sql, $fragmento)) {
                return $filas;
            }
        }
        return [];
    }

    public function rowCountPara(string $sql): int
    {
        foreach ($this->rowCounts as $fragmento => $n) {
            if (str_contains($sql, $fragmento)) {
                return $n;
            }
        }
        return 1;
    }

    /** Llamadas de un tipo: 'prepare', 'execute', 'beginTransaction', 'commit', 'rollBack'. */
    public function de(string $tipo): array
    {
        return array_values(array_filter($this->llamadas, static fn(array $l): bool => $l[0] === $tipo));
    }

    /** SQL (espacios colapsados) del primer prepare que contiene $fragmento, o null. */
    public function sql(string $fragmento): ?string
    {
        foreach ($this->de('prepare') as $l) {
            if (str_contains($l[1], $fragmento)) {
                return (string) preg_replace('/\s+/', ' ', $l[1]);
            }
        }
        return null;
    }

    /** Parametros del ultimo execute cuyo SQL contiene $fragmento, o null. */
    public function params(string $fragmento): ?array
    {
        $params = null;
        foreach ($this->de('execute') as $l) {
            if (str_contains($l[1], $fragmento)) {
                $params = $l[2];
            }
        }
        return $params;
    }
}

/** PDOException como la arma pdo_mysql: errorInfo[1] es el codigo del driver. */
function puro_pdo_error(int $codigo, string $sqlstate, string $texto): PDOException
{
    $e = new PDOException("SQLSTATE[{$sqlstate}]: {$texto}");
    $e->errorInfo = [$sqlstate, $codigo, $texto];
    return $e;
}

/** wa_con_log: [resultado, excepcion o null, texto del log]. */
function puro_con_log(callable $fn): array
{
    return wa_con_log($fn);
}

/** facturaModel sin constructor (sin DB) con la conexion dada. */
function puro_modelo(object $conexion): facturaModel
{
    $ref = new ReflectionClass(facturaModel::class);
    $modelo = $ref->newInstanceWithoutConstructor();
    $ref->getProperty('conexion')->setValue($modelo, $conexion);
    return $modelo;
}

/** El ctx 'reservar' de ECFEmissionService (sus 13 claves), con $cambios encima. */
function puro_ctx_reservar(array $cambios = []): array
{
    return $cambios + [
        'tipo_ecf' => '31', 'e_ncf' => 'E310000000123', 'ambiente' => 'ecf', 'ambiente_secuencia' => 'ecf',
        'signed_xml' => '<ECF><Encabezado><eNCF>E310000000123</eNCF></Encabezado></ECF>', 'codigo_seguridad' => 'Ab12Cd',
        'fecha_emision_dgii' => '2026-10-05 14:03:00', 'flujo' => 'ECF', 'rfce_xml' => null,
        'dispensado' => true, 'rango_id' => 7, 'secuencia_valor' => 123, 'desde' => '2026-10-05 14:03:11',
    ];
}

/** El $facturaInput del controller (sin no_factura), con una linea. */
function puro_factura(array $cambios = []): array
{
    return $cambios + [
        'date' => '2026-10-05 14:03:00', 'client_id' => 5, 'client_name' => 'Cliente prueba', 'total' => 118.0,
        'tipo_pago' => 1, 'user_id' => 3, 'informacion_referencia' => null,
        'items' => [[
            'product_id' => 9, 'description' => 'Producto', 'amount' => 100.0, 'quantity' => 1.0, 'subtotal' => 100.0,
            'descuento_monto' => 0.0, 'indicador_facturacion' => 1, 'indicador_bien_servicio' => 1,
            'unidad_medida' => '43', 'itbis_amount' => 18.0,
        ]],
    ];
}

echo "\n== 5) facturaModel::reservarFacturaECF (conexion falsa) ==\n";
$reservar = static fn(GuionConexion $c, array $ctx = [], array $factura = []): array => puro_con_log(
    static fn() => puro_modelo($c)->reservarFacturaECF(puro_factura($factura), puro_ctx_reservar($ctx))
);

$c = new GuionConexion();
[, $e] = $reservar($c, ['ambiente' => '  ']);
wa_chk('ambiente vacio: InvalidArgumentException antes de cualquier consulta',
    $e instanceof InvalidArgumentException && $c->llamadas === [], [$e?->getMessage(), $c->llamadas]);

$c = new GuionConexion();
[$r, $e] = $reservar($c);
wa_chk('camino feliz: devuelve factura_id, e_ncf y desde',
    $e === null && $r === ['factura_id' => 41, 'e_ncf' => 'E310000000123', 'desde' => '2026-10-05 14:03:11'], $e?->getMessage() ?? $r);
$select = (string) $c->sql('estado_dgii FROM facturas');
wa_chk('el SELECT bloquea (FOR UPDATE) con el predicado de eNcfYaUsado',
    str_contains($select, 'FOR UPDATE') && str_contains($select, '(ambiente_dgii = :amb OR ambiente_dgii IS NULL)'), $select);
wa_chk('el SELECT usa el e-NCF y el ambiente del token',
    $c->params('FOR UPDATE') === [':e' => 'E310000000123', ':amb' => 'ecf'], $c->params('FOR UPDATE'));
$fila = $c->params('INSERT INTO facturas') ?? [];
wa_chk('fila: PENDIENTE_ENVIO, no_factura = e-NCF, track_id y secuencia_utilizada NULL',
    ($fila[':estado_dgii'] ?? null) === 'PENDIENTE_ENVIO' && ($fila[':no_factura'] ?? null) === 'E310000000123'
    && array_key_exists(':track_id', $fila) && $fila[':track_id'] === null
    && array_key_exists(':secuencia_utilizada', $fila) && $fila[':secuencia_utilizada'] === null, $fila);
wa_chk('fila: XML firmado, codigo_seguridad, fecha y ambiente del ctx; sin respuesta RFCE',
    ($fila[':xml_firmado'] ?? null) === '<ECF><Encabezado><eNCF>E310000000123</eNCF></Encabezado></ECF>'
    && ($fila[':codigo_seguridad'] ?? null) === 'Ab12Cd' && ($fila[':fecha_emision_dgii'] ?? null) === '2026-10-05 14:03:00'
    && ($fila[':ambiente_dgii'] ?? null) === 'ecf' && ($fila[':tipo_ecf'] ?? null) === '31'
    && $fila[':rfce_track_id'] === null && $fila[':rfce_estado'] === null && $fila[':rfce_respuesta'] === null, $fila);
$meta = json_decode((string) ($fila[':respuesta_dgii'] ?? ''), true);
wa_chk('respuesta_dgii: las 7 claves de 6.3, en orden, con los valores del ctx',
    $meta === ['fase' => 'PENDIENTE_ENVIO', 'desde' => '2026-10-05 14:03:11', 'flujo' => 'ECF', 'ambiente_secuencia' => 'ecf',
        'dispensado' => true, 'rango_id' => 7, 'secuencia_valor' => 123], $meta);
wa_chk('respuesta_dgii: sin XML', !str_contains((string) ($fila[':respuesta_dgii'] ?? ''), '<')
    && !str_contains((string) ($fila[':respuesta_dgii'] ?? ''), 'signed_xml'));
wa_chk('una transaccion, el item con el id nuevo, commit y nada abierto',
    count($c->de('beginTransaction')) === 1 && count($c->de('commit')) === 1 && $c->de('rollBack') === []
    && ($c->params('INSERT INTO factura_items')[':factura_id'] ?? null) === 41 && !$c->inTransaction(), $c->llamadas);

$c = new GuionConexion();
[, $e] = $reservar($c, ['tipo_ecf' => '34', 'e_ncf' => 'E340000000002'],
    ['informacion_referencia' => ['ncf_modificado' => 'E310000000020', 'fecha_ncf_modificado' => '05-10-2026', 'codigo_modificacion' => '3']]);
$fila = $c->params('INSERT INTO facturas') ?? [];
wa_chk('nota E34: los campos de normalizarNotaReferencia van a la fila',
    $e === null && ($fila[':ncf_modificado'] ?? null) === 'E310000000020' && ($fila[':fecha_ncf_modificado'] ?? null) === '2026-10-05'
    && ($fila[':codigo_modificacion'] ?? null) === '3' && ($fila[':razon_modificacion'] ?? null) === 'Nota de credito por ajuste de monto', $fila);

$c = new GuionConexion();
[, $e] = $reservar($c, ['tipo_ecf' => '32', 'e_ncf' => 'E320000000007', 'flujo' => 'RFCE', 'rfce_xml' => '<RFCE><eNCF>E320000000007</eNCF></RFCE>']);
$fila = $c->params('INSERT INTO facturas') ?? [];
wa_chk('RFCE: el XML del resumen y flujo RFCE quedan en la reserva',
    $e === null && ($fila[':rfce_xml'] ?? null) === '<RFCE><eNCF>E320000000007</eNCF></RFCE>'
    && (json_decode((string) $fila[':respuesta_dgii'], true)['flujo'] ?? null) === 'RFCE', $fila);

$c = new GuionConexion();
$c->filas['FOR UPDATE'] = [['id' => '9', 'estado_dgii' => 'ACEPTADO']];
[, $e] = $reservar($c);
wa_chk('fila previa ACEPTADO: EcfNumeroOcupadoException con la causa',
    $e instanceof EcfNumeroOcupadoException && str_contains($e->getMessage(), 'fila 9 en ACEPTADO'), $e?->getMessage());
wa_chk('fila previa ACEPTADO: sin UPDATE ni INSERT, rollBack y sin commit',
    $c->sql('UPDATE facturas') === null && $c->sql('INSERT INTO') === null
    && count($c->de('rollBack')) === 1 && $c->de('commit') === [] && !$c->inTransaction(), $c->llamadas);

$c = new GuionConexion();
$c->filas['FOR UPDATE'] = [['id' => '9', 'estado_dgii' => 'RECHAZADO']];
[$r, $e] = $reservar($c);
$archivo = (string) $c->sql('_ARCHIVADO');
wa_chk("fila previa RECHAZADO: se archiva con CONCAT(estado_dgii, '_ARCHIVADO') y compare-and-set",
    str_contains($archivo, "SET e_ncf = NULL, estado_dgii = CONCAT(estado_dgii, '_ARCHIVADO')")
    && str_contains($archivo, 'WHERE id = :id AND estado_dgii = :visto'), $archivo);
wa_chk('archivo: :visto es el estado leido', $c->params('_ARCHIVADO') === [':id' => 9, ':visto' => 'RECHAZADO'], $c->params('_ARCHIVADO'));
wa_chk('archivo y fila nueva en la misma transaccion',
    $e === null && ($r['factura_id'] ?? null) === 41 && count($c->de('beginTransaction')) === 1 && count($c->de('commit')) === 1, $c->llamadas);

$c = new GuionConexion();
$c->filas['FOR UPDATE'] = [['id' => '9', 'estado_dgii' => 'RFCE_NO_ENCONTRADO']];
$c->rowCounts['_ARCHIVADO'] = 0;
[, $e] = $reservar($c);
wa_chk('archivo sin coincidencia (rowCount 0): ocupado, sin INSERT ni commit',
    $e instanceof EcfNumeroOcupadoException && str_contains($e->getMessage(), 'archivo sin coincidencia')
    && $c->sql('INSERT INTO') === null && $c->de('commit') === [] && !$c->inTransaction(), $e?->getMessage());

$c = new GuionConexion();
$duplicado = puro_pdo_error(1062, '23000', "Duplicate entry 'E310000000123-ecf' for key 'uk_e_ncf_amb'");
$c->errores[] = ['INSERT INTO facturas', $duplicado];
[, $e] = $reservar($c);
wa_chk('1062 al insertar: ocupado, con el PDOException como previa',
    $e instanceof EcfNumeroOcupadoException && $e->getPrevious() === $duplicado
    && str_contains($e->getMessage(), 'clave duplicada (1062)'), $e?->getMessage());
wa_chk('1062: rollBack y sin commit', count($c->de('rollBack')) === 1 && $c->de('commit') === [] && !$c->inTransaction());

$c = new GuionConexion();
$c->errores[] = ['INSERT INTO facturas', puro_pdo_error(1213, '40001', 'Deadlock found when trying to get lock')];
[$r, $e, $log] = $reservar($c);
wa_chk('1213 una vez: reintenta (dos BEGIN) y reserva',
    $e === null && ($r['factura_id'] ?? null) === 41 && count($c->de('beginTransaction')) === 2 && count($c->de('commit')) === 1,
    $e?->getMessage() ?? $c->llamadas);
wa_chk('1213 una vez: una sola linea de reintento en el log',
    substr_count($log, '[ECF e-NCF=E310000000123] reservar: deadlock (1213), reintento') === 1, $log);

$c = new GuionConexion();
$c->errores[] = ['INSERT INTO facturas', puro_pdo_error(1213, '40001', 'Deadlock found when trying to get lock')];
$c->errores[] = ['INSERT INTO facturas', puro_pdo_error(1213, '40001', 'Deadlock found when trying to get lock')];
[, $e] = $reservar($c);
wa_chk('1213 dos veces: ocupado, sin tercer intento',
    $e instanceof EcfNumeroOcupadoException && str_contains($e->getMessage(), 'deadlock (1213) dos veces')
    && count($c->de('beginTransaction')) === 2 && $c->de('commit') === [], $e?->getMessage());

$c = new GuionConexion();
$sinProducto = puro_pdo_error(1452, '23000', 'Cannot add or update a child row: a foreign key constraint fails');
$c->errores[] = ['INSERT INTO factura_items', $sinProducto];
[, $e] = $reservar($c);
wa_chk('1452 (producto borrado): el mismo PDOException, no ocupado', $e === $sinProducto, $e === null ? null : get_class($e));
wa_chk('1452: rollBack, sin commit y nada abierto',
    count($c->de('rollBack')) === 1 && $c->de('commit') === [] && !$c->inTransaction(), $c->llamadas);

$c = new GuionConexion();
$c->errores[] = ['INSERT INTO facturas', new RuntimeException('se lleno el disco')];
[, $e] = $reservar($c);
wa_chk('otra excepcion dentro de la transaccion: rollBack y se relanza la misma',
    $e instanceof RuntimeException && !($e instanceof EcfNumeroOcupadoException) && $e->getMessage() === 'se lleno el disco'
    && count($c->de('rollBack')) === 1 && $c->de('commit') === [] && !$c->inTransaction(), $e?->getMessage());

$c = new GuionConexion();
[, $e, $log] = $reservar($c, ['ambiente_secuencia' => 'certecf']);
wa_chk('ambiente del token distinto del de la secuencia: una linea de log y reserva igual',
    $e === null && substr_count($log, 'distinto del de la secuencia certecf') === 1, $log);

// ---------------------------------------------------------------------------
// 6) confirmarFacturaECF, marcarEnvioIncierto y existeFactura (conexion falsa)
// ---------------------------------------------------------------------------

/** El ctx 'confirmar' de ECFEmissionService (las 13 claves + reserva + respuesta), con $cambios encima. */
function puro_ctx_confirmar(array $cambios = []): array
{
    return $cambios + [
        'reserva' => ['factura_id' => 41, 'e_ncf' => 'E310000000123', 'desde' => '2026-10-05 14:03:11'],
        'estado' => 'ACEPTADO', 'track_id' => 'track-E310000000123',
        'dgii_response' => ['trackId' => 'track-E310000000123', 'codigo' => 1, 'estado' => 'Aceptado', 'secuenciaUtilizada' => true],
        'dgii_status_code' => 200, 'rfce_track_id' => null, 'rfce_estado' => null, 'rfce_response' => null,
        'rfce_status_code' => null, 'secuencia_utilizada' => true,
    ] + puro_ctx_reservar();
}

/**
 * El ctx 'incierto' (ctxSinXml + reserva + el fallo), con $cambios encima. A
 * proposito trae signed_xml, rfce_xml y xml_firmado: el modelo debe quitarlos.
 */
function puro_ctx_incierto(array $cambios = []): array
{
    return $cambios + [
        'reserva' => ['factura_id' => 41, 'e_ncf' => 'E310000000123', 'desde' => '2026-10-05 14:03:11'],
        'fase_fallida' => 'envio', 'clase' => 'RuntimeException', 'error' => 'HTTP request failed: timeout',
        'resultado_parcial' => null, 'xml_firmado' => '<ECF/>',
    ] + puro_ctx_reservar(['rfce_xml' => '<RFCE/>']);
}

echo "\n== 6) confirmarFacturaECF, marcarEnvioIncierto y existeFactura (conexion falsa) ==\n";
$confirmar = static fn(GuionConexion $c, array $cambios = []): bool
    => puro_modelo($c)->confirmarFacturaECF(41, puro_ctx_confirmar($cambios));
$es = static fn(array $p, string $clave, $valor): bool => array_key_exists($clave, $p) && $p[$clave] === $valor;

$c = new GuionConexion();
$ok = $confirmar($c);
$sql = (string) $c->sql('UPDATE facturas SET estado_dgii');
wa_chk('confirmar: un solo UPDATE, sin transaccion, con la precondicion de estado en vuelo',
    $ok === true && str_contains($sql, "WHERE id = :id AND estado_dgii IN ('PENDIENTE_ENVIO', 'ENVIO_INCIERTO')")
    && count($c->de('prepare')) === 1 && $c->de('beginTransaction') === [], [$sql, $c->llamadas]);
$p = $c->params('UPDATE facturas SET estado_dgii') ?? [];
wa_chk('confirmar: estado, track_id, la respuesta en JSON y el id',
    $es($p, ':estado', 'ACEPTADO') && $es($p, ':track_id', 'track-E310000000123')
    && $es($p, ':resp', json_encode(puro_ctx_confirmar()['dgii_response'])) && $es($p, ':rresp', null) && $es($p, ':id', 41), $p);
$secs = [];
foreach ([true, false, null] as $valor) {
    $c = new GuionConexion();
    $confirmar($c, ['secuencia_utilizada' => $valor]);
    $p = $c->params('UPDATE facturas SET estado_dgii') ?? [];
    $secs[] = array_key_exists(':sec', $p) ? $p[':sec'] : 'falta';
}
wa_chk("confirmar: :sec es 1, 0 o null (nunca '' con prepares emulados)", $secs === [1, 0, null], $secs);
$c = new GuionConexion();
$confirmar($c, ['dgii_response' => str_repeat('x', 200 * 1024), 'rfce_response' => str_repeat('y', 200 * 1024)]);
$p = $c->params('UPDATE facturas SET estado_dgii') ?? [];
wa_chk('confirmar: un cuerpo de texto de 200 KB se guarda recortado a 4000 (JSON <= 4002)',
    strlen((string) ($p[':resp'] ?? '')) <= 4002 && json_decode((string) ($p[':resp'] ?? '')) === str_repeat('x', 4000)
    && json_decode((string) ($p[':rresp'] ?? '')) === str_repeat('y', 4000),
    [strlen((string) ($p[':resp'] ?? '')), strlen((string) ($p[':rresp'] ?? ''))]);
$c = new GuionConexion();
$confirmar($c, ['estado' => 'RFCE_ACEPTADO', 'track_id' => null, 'dgii_response' => null,
    'rfce_track_id' => 'track-E320000000007', 'rfce_estado' => 'ACEPTADO', 'rfce_response' => ['codigo' => 1, 'estado' => 'Aceptado']]);
$p = $c->params('UPDATE facturas SET estado_dgii') ?? [];
wa_chk('confirmar RFCE: respuesta_dgii y track_id NULL; los rfce_* con la respuesta del resumen',
    $es($p, ':estado', 'RFCE_ACEPTADO') && $es($p, ':resp', null) && $es($p, ':track_id', null)
    && $es($p, ':rtid', 'track-E320000000007') && $es($p, ':rest', 'ACEPTADO')
    && $es($p, ':rresp', '{"codigo":1,"estado":"Aceptado"}'), $p);
$c = new GuionConexion();
$c->rowCounts['UPDATE facturas SET estado_dgii'] = 0;
wa_chk('confirmar: 0 filas (otra escritura movio la fila) -> false', $confirmar($c) === false);
$c = new GuionConexion();
$caida = puro_pdo_error(2006, 'HY000', 'MySQL server has gone away');
$c->errores[] = ['UPDATE facturas SET estado_dgii', $caida];
[, $e] = puro_con_log(static fn() => $confirmar($c));
wa_chk('confirmar: un PDOException se propaga tal cual', $e === $caida, $e === null ? null : get_class($e));

$marcar = static fn(GuionConexion $c, array $ctx): bool => puro_modelo($c)->marcarEnvioIncierto(41, $ctx);
$c = new GuionConexion();
$ok = $marcar($c, puro_ctx_incierto());
$sql = (string) $c->sql("estado_dgii = 'ENVIO_INCIERTO'");
$p = $c->params("estado_dgii = 'ENVIO_INCIERTO'") ?? [];
wa_chk('marcar: ENVIO_INCIERTO solo desde PENDIENTE_ENVIO, sin transaccion',
    $ok === true && str_contains($sql, "SET estado_dgii = 'ENVIO_INCIERTO', respuesta_dgii = :json")
    && str_contains($sql, "WHERE id = :id AND estado_dgii = 'PENDIENTE_ENVIO'") && $es($p, ':id', 41)
    && $c->de('beginTransaction') === [], $sql);
$json = (string) ($p[':json'] ?? '');
$meta = json_decode($json, true);
wa_chk('marcar: el JSON de 6.3 con las claves en orden',
    is_array($meta) && array_keys($meta) === ['fase', 'desde', 'flujo', 'ambiente_secuencia', 'dispensado', 'rango_id',
        'secuencia_valor', 'fase_fallida', 'clase', 'error', 'resultado_parcial', 'intentos'], $meta);
wa_chk('marcar: fase, meta de la secuencia, el fallo e intentos 0',
    is_array($meta) && $meta['fase'] === 'ENVIO_INCIERTO' && $meta['desde'] === '2026-10-05 14:03:11' && $meta['flujo'] === 'ECF'
    && $meta['ambiente_secuencia'] === 'ecf' && $meta['dispensado'] === true && $meta['rango_id'] === 7
    && $meta['secuencia_valor'] === 123 && $meta['fase_fallida'] === 'envio' && $meta['clase'] === 'RuntimeException'
    && $meta['error'] === 'HTTP request failed: timeout' && $meta['resultado_parcial'] === null && $meta['intentos'] === 0, $meta);
wa_chk('marcar: ni signed_xml, ni rfce_xml, ni xml_firmado, ni XML',
    $json !== '' && !preg_match('/signed_xml|rfce_xml|xml_firmado|</', $json), $json);
$parcial = ['estado' => 'ACEPTADO', 'track_id' => 'track-E310000000123', 'dgii_status_code' => 200, 'dgii_response' => ['codigo' => 1],
    'rfce_estado' => null, 'rfce_track_id' => null, 'rfce_status_code' => null, 'secuencia_utilizada' => true];
$c = new GuionConexion();
$marcar($c, puro_ctx_incierto(['fase_fallida' => 'confirmar', 'resultado_parcial' => $parcial]));
$meta = json_decode((string) (($c->params("estado_dgii = 'ENVIO_INCIERTO'") ?? [])[':json'] ?? ''), true);
wa_chk('marcar (fallo al confirmar): guarda resultado_parcial tal cual',
    is_array($meta) && $meta['fase_fallida'] === 'confirmar' && $meta['resultado_parcial'] === $parcial, $meta);
$cierre = puro_ctx_incierto(['fase_fallida' => 'shutdown', 'clase' => 'shutdown', 'error' => str_repeat('e', 3000)]);
unset($cierre['resultado_parcial']);
$c = new GuionConexion();
$marcar($c, $cierre);
$meta = json_decode((string) (($c->params("estado_dgii = 'ENVIO_INCIERTO'") ?? [])[':json'] ?? ''), true);
wa_chk('marcar (registro de cierre): sin resultado_parcial queda null y el error se recorta a 2000',
    is_array($meta) && array_key_exists('resultado_parcial', $meta) && $meta['resultado_parcial'] === null
    && $meta['fase_fallida'] === 'shutdown' && mb_strlen((string) $meta['error']) === 2000, $meta);
$c = new GuionConexion();
$c->rowCounts["estado_dgii = 'ENVIO_INCIERTO'"] = 0;
wa_chk('marcar: fila que ya no esta en PENDIENTE_ENVIO -> false', $marcar($c, puro_ctx_incierto()) === false);
$c = new GuionConexion();
$c->prepareFalla = true;
[$ok, $e, $log] = puro_con_log(static fn() => $marcar($c, puro_ctx_incierto()));
wa_chk('marcar: si la base falla devuelve false y no lanza', $ok === false && $e === null, $e?->getMessage());
wa_chk('marcar: el log nombra el e-NCF y la factura',
    str_contains($log, '[ECF e-NCF=E310000000123 factura_id=41] marcarEnvioIncierto fallo: se cayo la conexion'), $log);

$c = new GuionConexion();
$c->filas['SELECT 1 FROM facturas'] = [['1' => 1]];
$existe = puro_modelo($c)->existeFactura(41);
wa_chk('existeFactura: SELECT 1 por id; con fila -> true',
    $existe === true && $c->params('SELECT 1 FROM facturas') === [':id' => 41], $c->llamadas);
wa_chk('existeFactura: sin fila -> false', puro_modelo(new GuionConexion())->existeFactura(999999) === false);

// ---------------------------------------------------------------------------
// 7) Guard de CR publico y estatico; dispenseNextECF lanza (Task 10)
// ECFEmissionService corre el guard antes de reservar la fila: un CR tiene que
// fallar con el numero todavia devolvible, no dentro de recibir().
// ---------------------------------------------------------------------------
require_once __DIR__ . '/../src/Utils/FacturacionElectronica/DgiiReceptionService.php';

echo "\n== 7) Guard de retornos de carro y dispenseNextECF ==\n";
$guardCr = new ReflectionMethod(DgiiReceptionService::class, 'assertSinCarriageReturn');
$guardEstatico = $guardCr->isPublic() && $guardCr->isStatic();
wa_chk('7: assertSinCarriageReturn es public static', $guardEstatico,
    ['public' => $guardCr->isPublic(), 'static' => $guardCr->isStatic()]);
// null = no se puede llamar asi (sigue privado); true = lanzo; false = lo dejo pasar.
$lanzaCr = static function (string $xml) use ($guardEstatico): ?bool {
    if (!$guardEstatico) {
        return null;
    }
    try {
        DgiiReceptionService::assertSinCarriageReturn($xml);
        return false;
    } catch (RuntimeException $e) {
        return str_contains($e->getMessage(), 'retornos de carro');
    }
};
wa_chk('7: lanza con un \r literal', $lanzaCr("<a>\r</a>") === true);
wa_chk('7: lanza con &#13;', $lanzaCr('<a>&#13;</a>') === true);
wa_chk('7: lanza con &#xD; (sin importar mayusculas)', $lanzaCr('<a>&#xD;</a>') === true);
wa_chk('7: deja pasar un \n', $lanzaCr("<a>\n</a>") === false);
$fuenteRecepcion = (string) file_get_contents(__DIR__ . '/../src/Utils/FacturacionElectronica/DgiiReceptionService.php');
wa_chk('7: recibir, recibirResumen y enviarAprobacionComercial lo siguen llamando (3 llamadas)',
    substr_count($fuenteRecepcion, '$this->assertSinCarriageReturn(') === 3,
    substr_count($fuenteRecepcion, '$this->assertSinCarriageReturn('));
// El caso con la tabla renombrada corre en tools/test_reservar_confirmar.php
// (necesita MySQL); aqui solo el texto, para tener senal sin base.
$fuenteNcf = str_replace("\r\n", "\n", (string) file_get_contents(__DIR__ . '/../src/Models/ncfModel.php'));
$cuerpoDispense = preg_match('/function dispenseNextECF\(.*?\n    \}\n/s', $fuenteNcf, $mDispense) ? $mDispense[0] : '';
wa_chk('7: dispenseNextECF convierte la PDOException en RuntimeException',
    str_contains($cuerpoDispense, "throw new RuntimeException('ncf_sequences inaccesible: ' . \$e->getMessage(), 0, \$e);"));
wa_chk('7: dispenseNextECF ya no devuelve null en el catch',
    !preg_match('/catch \(PDOException \$e\) \{\s*return null;/', $cuerpoDispense));

// ---------------------------------------------------------------------------
// 8) ECFEmissionService: inyeccion, proyecciones sin XML y REINTENTABLES (Task 11)
// Nada de esto toca la base: el ncfModel inyectado se crea sin constructor.
// ---------------------------------------------------------------------------
require_once __DIR__ . '/../src/Utils/FacturacionElectronica/ECFEmissionService.php';

echo "\n== 8) ECFEmissionService: inyeccion y proyecciones ==\n";
$propDe = static fn(object $o, string $p): mixed => (new ReflectionProperty($o, $p))->getValue($o);
$authInyectado = new DgiiAuthService();
$signerInyectado = new DgiiXmlSigner();
$recepcionInyectada = new DgiiReceptionService($authInyectado);
$ncfInyectado = (new ReflectionClass(ncfModel::class))->newInstanceWithoutConstructor();
$svcInyectado = new ECFEmissionService($authInyectado, $signerInyectado, $recepcionInyectada, $ncfInyectado);
wa_chk('8: constructor usa el auth, el signer, la recepcion y el ncfModel inyectados',
    $propDe($svcInyectado, 'auth') === $authInyectado
    && $propDe($svcInyectado, 'signer') === $signerInyectado
    && $propDe($svcInyectado, 'reception') === $recepcionInyectada
    && $propDe($svcInyectado, 'ncfModel') === $ncfInyectado);
$svcSoloAuth = new ECFEmissionService($authInyectado);
wa_chk('8: con solo el auth, la recepcion por defecto usa ese mismo auth',
    $propDe($propDe($svcSoloAuth, 'reception'), 'auth') === $authInyectado);
$svcPorDefecto = new ECFEmissionService();
wa_chk('8: sin argumentos no toca la base (ncfModel sigue null)',
    $propDe($svcPorDefecto, 'ncfModel') === null
    && $propDe($svcPorDefecto, 'auth') instanceof DgiiAuthService
    && $propDe($svcPorDefecto, 'reception') instanceof DgiiReceptionService);

$fuenteServicio = (string) file_get_contents(__DIR__ . '/../src/Utils/FacturacionElectronica/ECFEmissionService.php');
wa_chk("8: sin la lista literal ['RECHAZADO', 'RFCE_RECHAZADO', 'NO_ENCONTRADO']",
    !str_contains($fuenteServicio, "['RECHAZADO', 'RFCE_RECHAZADO', 'NO_ENCONTRADO']"));
wa_chk('8: eNcfYaUsado usa EstadosEcf::REINTENTABLES',
    str_contains($fuenteServicio, 'in_array((string) $estado, EstadosEcf::REINTENTABLES, true)'));
wa_chk('8: ya no nombra saveFacturaConECF', !str_contains($fuenteServicio, 'saveFacturaConECF'));
wa_chk("8: la reclamacion sigue probando ['RECHAZADO', 'NO_ENCONTRADO'] (el RFCE pasa el estado base)",
    str_contains($fuenteServicio, "\$esRechazo = in_array(\$estado, ['RECHAZADO', 'NO_ENCONTRADO'], true);"));
wa_chk('8: la reclamacion lee la bandera con parseSecuenciaUtilizada',
    str_contains($fuenteServicio, '$utilizada = self::parseSecuenciaUtilizada($receptionData);'));

$hayProyecciones = method_exists(ECFEmissionService::class, 'ctxSinXml')
    && method_exists(ECFEmissionService::class, 'proyectarResultado')
    && method_exists(ECFEmissionService::class, 'parseSecuenciaUtilizada');
wa_chk('8: existen ctxSinXml, proyectarResultado y parseSecuenciaUtilizada', $hayProyecciones);
if ($hayProyecciones) {
    $ctxPrueba = [
        'tipo_ecf' => '31', 'e_ncf' => 'E310000000123', 'signed_xml' => '<ECF>firmado</ECF>',
        'rfce_xml' => '<RFCE>firmado</RFCE>', 'codigo_seguridad' => 'QUJDRE', 'reserva' => ['factura_id' => 41],
    ];
    $sinXml = ECFEmissionService::ctxSinXml($ctxPrueba);
    wa_chk('8: ctxSinXml quita signed_xml y rfce_xml y deja lo demas, en orden',
        $sinXml === ['tipo_ecf' => '31', 'e_ncf' => 'E310000000123', 'codigo_seguridad' => 'QUJDRE', 'reserva' => ['factura_id' => 41]],
        $sinXml);

    $clavesProyeccion = ['estado', 'track_id', 'dgii_status_code', 'dgii_response', 'rfce_estado', 'rfce_track_id', 'rfce_status_code', 'secuencia_utilizada'];
    $proy = ECFEmissionService::proyectarResultado($ctxPrueba + ['estado' => 'ACEPTADO', 'track_id' => 't-1', 'secuencia_utilizada' => false]);
    wa_chk('8: proyectarResultado devuelve exactamente las 8 claves, en orden', array_keys($proy) === $clavesProyeccion, array_keys($proy));
    wa_chk('8: proyectarResultado deja null las ausentes, conserva false y descarta las extra (el XML tambien)',
        $proy['estado'] === 'ACEPTADO' && $proy['track_id'] === 't-1' && $proy['rfce_estado'] === null
        && $proy['secuencia_utilizada'] === false
        && !array_key_exists('signed_xml', $proy) && !array_key_exists('e_ncf', $proy) && !array_key_exists('reserva', $proy), $proy);
    $grande = str_repeat('á', 100 * 1024);   // 200 KB de texto (2 bytes por caracter)
    $proy = ECFEmissionService::proyectarResultado(['dgii_response' => $grande]);
    wa_chk('8: un dgii_response de texto de 200 KB queda en 4000 caracteres (mb_substr, UTF-8 valido)',
        is_string($proy['dgii_response']) && mb_strlen($proy['dgii_response']) === 4000 && mb_check_encoding($proy['dgii_response'], 'UTF-8'),
        mb_strlen((string) $proy['dgii_response']));
    $arreglo = ['trackId' => 't-2', 'codigo' => 1, 'estado' => 'Aceptado', 'mensajes' => [['valor' => 'ok']]];
    $proy = ECFEmissionService::proyectarResultado(['dgii_response' => $arreglo]);
    wa_chk('8: un dgii_response arreglo pasa intacto', $proy['dgii_response'] === $arreglo, $proy['dgii_response']);

    $parse = new ReflectionMethod(ECFEmissionService::class, 'parseSecuenciaUtilizada');
    wa_chk('8: parseSecuenciaUtilizada es private static', $parse->isPrivate() && $parse->isStatic());
    $casosParse = [
        'true' => [['secuenciaUtilizada' => true], true],
        'false' => [['secuenciaUtilizada' => false], false],
        '"true"' => [['secuenciaUtilizada' => 'true'], true],
        '"false"' => [['secuenciaUtilizada' => 'false'], false],
        'ausente' => [['codigo' => 2], null],
        '"abc"' => [['secuenciaUtilizada' => 'abc'], null],
    ];
    foreach ($casosParse as $nombre => [$datos, $esperado]) {
        $obtenido = $parse->invoke(null, $datos);
        wa_chk("8: parseSecuenciaUtilizada({$nombre}) === " . var_export($esperado, true), $obtenido === $esperado, $obtenido);
    }
}

// ---------------------------------------------------------------------------
// 9) inventoryModel::yaMovioDocumento (Task 15): una conexion falsa puesta en
// la propiedad privada conexion; nunca toca MySQL.
// ---------------------------------------------------------------------------
echo "\n== 9) inventoryModel::yaMovioDocumento ==\n";
require_once __DIR__ . '/../src/Models/inventoryModel.php';

final class WaS9Sentencia
{
    public function __construct(private WaS9Conexion $c, private string $sql)
    {
    }

    public function execute(?array $params = null): bool
    {
        $this->c->consultas[] = ['sql' => $this->sql, 'params' => $params ?? []];
        return true;
    }

    public function fetchColumn(int $columna = 0): mixed
    {
        return $this->c->hayFila ? '1' : false;
    }
}

final class WaS9Conexion
{
    public array $consultas = [];

    public function __construct(public bool $hayFila, private bool $rota = false)
    {
    }

    public function prepare(string $sql): WaS9Sentencia
    {
        if ($this->rota) {
            throw new PDOException('se cayo la conexion');
        }
        return new WaS9Sentencia($this, $sql);
    }
}

$s9Inventario = static function (WaS9Conexion $c): inventoryModel {
    $ref = new ReflectionClass(inventoryModel::class);
    $m = $ref->newInstanceWithoutConstructor();
    $ref->getProperty('conexion')->setValue($m, $c);
    return $m;
};

if (!method_exists(inventoryModel::class, 'yaMovioDocumento')) {
    wa_chk('inventoryModel::yaMovioDocumento existe', false);
} else {
    $c = new WaS9Conexion(true);
    wa_chk('con un movimiento de la factura: true', $s9Inventario($c)->yaMovioDocumento('factura', 41) === true);
    $c = new WaS9Conexion(false);
    wa_chk('sin movimientos: false', $s9Inventario($c)->yaMovioDocumento('factura', 41) === false);
    $sql = preg_replace('/\s+/', ' ', $c->consultas[0]['sql'] ?? '');
    wa_chk('consulta por referencia_tipo y referencia_id con LIMIT 1 (idx_referencia)',
        str_contains($sql, 'FROM inventory_movements') && str_contains($sql, 'referencia_tipo = :t AND referencia_id = :id')
        && str_contains($sql, 'LIMIT 1'), $sql);
    wa_chk("parametros [':t' => 'factura', ':id' => 41]",
        ($c->consultas[0]['params'] ?? null) === [':t' => 'factura', ':id' => 41], $c->consultas[0]['params'] ?? null);
    $lanzo = null;
    try {
        $s9Inventario(new WaS9Conexion(false, true))->yaMovioDocumento('factura', 41);
    } catch (PDOException $e) {
        $lanzo = $e;
    }
    wa_chk('si la base falla, la PDOException sale (el que llama no mueve stock)', $lanzo instanceof PDOException);
}

// ---------------------------------------------------------------------------
// 10) Stats y reporte de ventas sin filas en vuelo (Task 16). getECFStats pasa
// su conexion a ncfModel::sqlSecuenciaActual(PDO ...), asi que la falsa es un
// PDO (sin conectar) que anota CADA consulta y devuelve sentencias vacias.
// ---------------------------------------------------------------------------
echo "\n== 10) Stats y reporte de ventas sin filas en vuelo ==\n";
require_once __DIR__ . '/../src/Utils/FacturacionElectronica/EstadosEcf.php';
require_once __DIR__ . '/../src/Models/facturaModel.php';
require_once __DIR__ . '/../src/Models/ReporteVentasModel.php';

final class WaS10Sentencia extends PDOStatement
{
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return false;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return [];
    }
}

final class WaS10Grabadora extends PDO
{
    public array $consultas = [];

    public function __construct()
    {
        // Sin parent::__construct: nunca abre una conexion.
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->consultas[] = preg_replace('/\s+/', ' ', $query);
        return new WaS10Sentencia();
    }

    public function quote(string $string, int $type = PDO::PARAM_STR): string|false
    {
        return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $string) . "'";
    }
}

putenv('MULTI_TENANT_ENABLED=false');
putenv('DGII_ECF_ENVIRONMENT=ecf');
$s10Ref = new ReflectionClass(facturaModel::class);
$s10Modelo = $s10Ref->newInstanceWithoutConstructor();
$s10Con = new WaS10Grabadora();
$s10Ref->getProperty('conexion')->setValue($s10Modelo, $s10Con);
$s10Modelo->getECFStats();
$s10Sql = static function (string $marca) use ($s10Con): string {
    foreach ($s10Con->consultas as $q) {
        if (str_contains($q, $marca)) {
            return $q;
        }
    }
    return '';
};
$enVuelo = "'PENDIENTE_ENVIO', 'ENVIO_INCIERTO'";
$montoSinVuelo = "COALESCE(SUM(CASE WHEN estado_dgii IN ({$enVuelo}) THEN 0 ELSE total END), 0) as monto_total";
$notRechazado = "AND (estado_dgii IS NULL OR (estado_dgii NOT LIKE '%RECHAZADO%' AND estado_dgii NOT IN ({$enVuelo})))";

wa_chk('stats: corre las 7 consultas', count($s10Con->consultas) === 7, $s10Con->consultas);
$q = $s10Sql('as total_ecf');
wa_chk('resumen: monto_total deja fuera las filas en vuelo', str_contains($q, $montoSinVuelo), $q);
wa_chk('resumen: total_ecf sigue contando todas las filas', str_contains($q, 'SELECT COUNT(*) as total_ecf,'), $q);
wa_chk('resumen: monto_neto deja fuera rechazados y filas en vuelo (NULL sigue contando)', str_contains($q,
    "COALESCE(SUM(CASE WHEN estado_dgii IS NULL OR (estado_dgii NOT LIKE '%RECHAZADO%' AND estado_dgii NOT IN ({$enVuelo})) "
    . "THEN CASE WHEN tipo_ecf = '34' THEN -total ELSE total END ELSE 0 END), 0) as monto_neto"), $q);
$q = $s10Sql('GROUP BY tipo_ecf ORDER BY tipo_ecf');
wa_chk('por_tipo: total sigue siendo COUNT(*) y monto_total sin filas en vuelo',
    str_contains($q, 'COUNT(*) as total, ' . $montoSinVuelo), $q);
foreach ([
    'por_mes' => "DATE_FORMAT(fecha_emision_dgii, '%Y-%m') as mes",
    'ventas_por_mes' => "DATE_FORMAT(date, '%Y-%m') as mes",
    'ventas_por_dia' => "DATE_FORMAT(date, '%Y-%m-%d') as dia",
] as $serie => $marca) {
    $q = $s10Sql($marca);
    wa_chk("{$serie}: sin rechazados ni filas en vuelo", str_contains($q, $notRechazado), $q);
}
$q = $s10Sql("COALESCE(estado_dgii, 'PENDIENTE') as estado");
wa_chk('por_estado: igual que antes (cada estado con su cubeta)', $q ===
    "SELECT COALESCE(estado_dgii, 'PENDIENTE') as estado, COUNT(*) as total, COALESCE(SUM(total), 0) as monto_total "
    . "FROM facturas WHERE tipo_ecf IS NOT NULL AND ambiente_dgii = 'ecf' GROUP BY estado_dgii ORDER BY total DESC", $q);
$q = $s10Sql('FROM ncf_sequences ns');
wa_chk('secuencias: total_emitidos sigue contando filas, en vuelo incluidas',
    str_contains($q, "SELECT CONCAT('E', tipo_ecf) as type, COUNT(*) as total_emitidos FROM facturas "
        . "WHERE tipo_ecf IS NOT NULL AND ambiente_dgii = 'ecf' GROUP BY tipo_ecf") && !str_contains($q, 'PENDIENTE_ENVIO'), $q);

// ReporteVentasModel::fetchDocumentos: la exclusion va en el SQL, con los
// mismos parametros ? de antes.
final class WaS10SentenciaReporte
{
    public function __construct(private WaS10Reporte $c, private string $sql)
    {
    }

    public function execute(?array $params = null): bool
    {
        $this->c->sql = $this->sql;
        $this->c->params = $params ?? [];
        return true;
    }

    public function fetchAll(int $modo = PDO::FETCH_DEFAULT): array
    {
        return [];
    }
}

final class WaS10Reporte
{
    public string $sql = '';
    public array $params = [];

    public function prepare(string $sql): WaS10SentenciaReporte
    {
        return new WaS10SentenciaReporte($this, $sql);
    }
}

$s10RefRep = new ReflectionClass(ReporteVentasModel::class);
$s10Rep = $s10RefRep->newInstanceWithoutConstructor();
$s10ConRep = new WaS10Reporte();
$s10RefRep->getProperty('conexion')->setValue($s10Rep, $s10ConRep);
$s10RefRep->getProperty('usuarios')->setValue($s10Rep, []);   // sin la consulta de usuarios
(new ReflectionMethod(ReporteVentasModel::class, 'fetchDocumentos'))->invoke($s10Rep, '2026-10-01', '2026-10-31');
$q = preg_replace('/\s+/', ' ', $s10ConRep->sql);
wa_chk('reporte de ventas: excluye las filas en vuelo',
    str_contains($q, "AND f.estado_dgii NOT LIKE '%RECHAZADO%' AND f.estado_dgii NOT IN ({$enVuelo})"), $q);
wa_chk('reporte de ventas: un ? por parametro', substr_count($q, '?') === count($s10ConRep->params), [$q, $s10ConRep->params]);
wa_chk('reporte de ventas: los mismos parametros de antes', $s10ConRep->params === [
    '2026-10-01 00:00:00', '2026-11-01 00:00:00', '31', '32', '44', '45', '46', '33', '34', 'ecf',
], $s10ConRep->params);

// ---------------------------------------------------------------------------
// 11) facturaController: emision write-ahead (Task 17). El controlador se lee
// como texto: incluirlo manda headers, valida el token y corre el router.
// ---------------------------------------------------------------------------
echo "\n== 11) facturaController: emision write-ahead ==\n";
require_once __DIR__ . '/../src/Models/facturaModel.php';

/** Archivos .php/.md bajo $rutas (relativas a la raiz del API) que contienen $aguja. */
function wa_grep(array $rutas, string $aguja): array
{
    $raiz = dirname(__DIR__);
    $hallados = [];
    foreach ($rutas as $ruta) {
        $abs = $raiz . DIRECTORY_SEPARATOR . $ruta;
        $archivos = is_file($abs) ? [new SplFileInfo($abs)]
            : (is_dir($abs) ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS)) : []);
        foreach ($archivos as $f) {
            if (in_array($f->getExtension(), ['php', 'md'], true)
                && str_contains((string) file_get_contents($f->getPathname()), $aguja)) {
                $hallados[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($f->getPathname(), strlen($raiz) + 1));
            }
        }
    }
    return $hallados;
}

$s11Ctl = str_replace("\r\n", "\n", (string) file_get_contents(__DIR__ . '/../src/Controllers/facturaController.php'));
$s11Emision = preg_match('/\nfunction handleEmisionECF\(.*?\n\}\n/s', $s11Ctl, $m) ? $m[0] : '';
wa_chk('controlador: se encontro handleEmisionECF', $s11Emision !== '');
$s11Texto = preg_match("/\nconst FACTURA_EMISION_INCIERTA = '([^']*)';/", $s11Ctl, $m) ? $m[1] : null;
wa_chk('FACTURA_EMISION_INCIERTA es el texto de la spec 4.7, letra por letra', $s11Texto
    === 'No se pudo confirmar si la DGII recibió la factura %s. No la emitas de nuevo: ya quedó guardada. '
    . 'Búscala en Facturación con el filtro Pendientes y, si sigue sin confirmar, avisa a soporte con ese número.', $s11Texto);
wa_chk('handleEmisionECF emite con el hook de persistencia',
    str_contains($s11Emision, '(new ECFEmissionService())->emitir($payload, $persistencia)'));
wa_chk('el 502 incierto lleva data con factura_id, e_ncf, tipo_ecf y estado_dgii',
    str_contains($s11Emision, "'error' => sprintf(FACTURA_EMISION_INCIERTA, \$eNcfFila)")
    && str_contains($s11Emision, "'factura_id' => \$filaId,"));
wa_chk('guarda del total: 422 antes de emitir',
    strpos($s11Emision, "!is_numeric(\$totales['monto_total'])") !== false
    && strpos($s11Emision, "!is_numeric(\$totales['monto_total'])") < strpos($s11Emision, '->emitir('));
$s11Estado = strpos($s11Emision, '$estadoFinal =');
$s11Stock = strpos($s11Emision, 'moverStockFacturaEcf(');
wa_chk('el stock se mueve despues de conocer $estadoFinal',
    $s11Estado !== false && $s11Stock !== false && $s11Stock > $s11Estado);
wa_chk('el codigo ya no llama ni define saveFacturaConECF',
    wa_grep(['src/Controllers', 'src/Models', 'src/Utils/FacturacionElectronica'], 'saveFacturaConECF') === [],
    wa_grep(['src/Controllers', 'src/Models', 'src/Utils/FacturacionElectronica'], 'saveFacturaConECF'));
wa_chk('facturaModel no tiene saveFacturaConECF', !method_exists(facturaModel::class, 'saveFacturaConECF'));
wa_chk('el catch toma cualquier Throwable posterior a la reserva como una fila por confirmar, no solo EcfEnvioInciertoException',
    str_contains($s11Emision, '} elseif ($reserva !== null) {')
    && str_contains($s11Emision, "\$facturaModel->existeFactura((int) \$reserva['factura_id'])"));
wa_chk('la validacion del saldo de la nota corre antes de emitir (una nota en vuelo no cuenta contra si misma)',
    strpos($s11Emision, 'validarFacturaModificada(') !== false
    && strpos($s11Emision, 'validarFacturaModificada(') < strpos($s11Emision, '->emitir('));
$s11Mover = preg_match('/\nfunction moverStockFacturaEcf\(.*?\n\}\n/s', $s11Ctl, $m) ? $m[0] : '';
wa_chk('moverStockFacturaEcf: no mueve en SIN_STOCK ni en EN_VUELO y pregunta yaMovioDocumento antes de registrarVenta',
    str_contains($s11Mover, 'EstadosEcf::SIN_STOCK') && str_contains($s11Mover, 'EstadosEcf::EN_VUELO')
    && strpos($s11Mover, "yaMovioDocumento('factura', \$facturaId)") !== false
    && strpos($s11Mover, "yaMovioDocumento('factura', \$facturaId)") < strpos($s11Mover, '->registrarVenta('));

// ---------------------------------------------------------------------------
// Cierre. Cada tarea agrega su seccion justo encima de este bloque.
// ---------------------------------------------------------------------------
wa_fin();
