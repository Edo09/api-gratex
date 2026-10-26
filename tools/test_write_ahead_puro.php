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
// Final fix wave F2: una fila del POS en vuelo cuenta (ya se cobro); solo las de la app quedan fuera.
$montoSinVuelo = "COALESCE(SUM(CASE WHEN estado_dgii IN ({$enVuelo}) AND pos_idempotency_key IS NULL THEN 0 ELSE total END), 0) as monto_total";
$estadoCuentaSql = "estado_dgii IS NULL OR (estado_dgii NOT LIKE '%RECHAZADO%' AND (estado_dgii NOT IN ({$enVuelo}) OR pos_idempotency_key IS NOT NULL))";
$notRechazado = "AND ({$estadoCuentaSql})";

wa_chk('stats: corre las 7 consultas', count($s10Con->consultas) === 7, $s10Con->consultas);
$q = $s10Sql('as total_ecf');
wa_chk('resumen: monto_total deja fuera las filas de la app en vuelo (las del POS suman)', str_contains($q, $montoSinVuelo), $q);
wa_chk('resumen: total_ecf sigue contando todas las filas', str_contains($q, 'SELECT COUNT(*) as total_ecf,'), $q);
wa_chk('resumen: monto_neto deja fuera rechazados y filas de la app en vuelo (NULL y las del POS siguen contando)', str_contains($q,
    "COALESCE(SUM(CASE WHEN {$estadoCuentaSql} "
    . "THEN CASE WHEN tipo_ecf = '34' THEN -total ELSE total END ELSE 0 END), 0) as monto_neto"), $q);
$q = $s10Sql('GROUP BY tipo_ecf ORDER BY tipo_ecf');
wa_chk('por_tipo: total sigue siendo COUNT(*) y monto_total sin filas de la app en vuelo',
    str_contains($q, 'COUNT(*) as total, ' . $montoSinVuelo), $q);
foreach ([
    'por_mes' => "DATE_FORMAT(fecha_emision_dgii, '%Y-%m') as mes",
    'ventas_por_mes' => "DATE_FORMAT(date, '%Y-%m') as mes",
    'ventas_por_dia' => "DATE_FORMAT(date, '%Y-%m-%d') as dia",
] as $serie => $marca) {
    $q = $s10Sql($marca);
    wa_chk("{$serie}: sin rechazados ni filas de la app en vuelo (las del POS cuentan)", str_contains($q, $notRechazado), $q);
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
wa_chk('reporte de ventas: excluye las filas de la app en vuelo; las del POS (pos_idempotency_key) cuentan',
    str_contains($q, "AND f.estado_dgii NOT LIKE '%RECHAZADO%' AND (f.estado_dgii NOT IN ({$enVuelo}) OR f.pos_idempotency_key IS NOT NULL)"), $q);
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
// Desde el merge con master (4007eea) el cuerpo de la emision vive en
// emitirFacturaEcf; handleEmisionECF solo lee el body y delega.
$s11Emision = preg_match('/\nfunction emitirFacturaEcf\(.*?\n\}\n/s', $s11Ctl, $m) ? $m[0] : '';
wa_chk('controlador: se encontro emitirFacturaEcf', $s11Emision !== '');
$s11Delega = preg_match('/\nfunction handleEmisionECF\(.*?\n\}\n/s', $s11Ctl, $m) ? $m[0] : '';
wa_chk('handleEmisionECF lee el body y delega en emitirFacturaEcf',
    str_contains($s11Delega, 'emitirFacturaEcf($input, $facturaModel, $clientModel);'), $s11Delega);
$s11Texto = preg_match("/\nconst FACTURA_EMISION_INCIERTA = '([^']*)';/", $s11Ctl, $m) ? $m[1] : null;
wa_chk('FACTURA_EMISION_INCIERTA es el texto de la spec 4.7, letra por letra', $s11Texto
    === 'No se pudo confirmar si la DGII recibió la factura %s. No la emitas de nuevo: ya quedó guardada. '
    . 'Búscala en Facturación con el filtro Pendientes y, si sigue sin confirmar, avisa a soporte con ese número.', $s11Texto);
wa_chk('emitirFacturaEcf emite con el hook de persistencia',
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
// 12) facturaController: GET /estado en vuelo y filtro pendiente (Task 18).
// Texto del controlador, como en la seccion 11.
// ---------------------------------------------------------------------------
echo "12) facturaController: GET /estado en vuelo, filtro pendiente\n";
$s12Ctl = str_replace("\r\n", "\n", (string) file_get_contents(__DIR__ . '/../src/Controllers/facturaController.php'));
$s12Const = static fn(string $nombre): ?string
    => preg_match("/\nconst {$nombre} = '([^']*)';/", $s12Ctl, $m) ? $m[1] : null;
wa_chk('FACTURA_NOTA_EN_VUELO es la nota de la spec 7.3', $s12Const('FACTURA_NOTA_EN_VUELO')
    === 'La DGII todavía no ha confirmado este comprobante. No lo emitas de nuevo; si sigue así en unos minutos, '
    . 'avisa a soporte con el número %s.', $s12Const('FACTURA_NOTA_EN_VUELO'));
wa_chk('FACTURA_NOTA_ENVIADO_SIN_TRACK es la nota de la spec 7.3', $s12Const('FACTURA_NOTA_ENVIADO_SIN_TRACK')
    === 'La DGII recibió este comprobante, pero no devolvió su número de seguimiento y por eso no se puede consultar '
    . 'su estado desde aquí. Si necesitas confirmarlo, avisa a soporte con el número %s.', $s12Const('FACTURA_NOTA_ENVIADO_SIN_TRACK'));
$s12Consulta = preg_match('/\nfunction handleConsultarEstado\(.*?\n\}\n/s', $s12Ctl, $m) ? $m[0] : '';
$s12Vuelo = strpos($s12Consulta, 'handleEstadoEnVuelo(');
$s12Rfce = strpos($s12Consulta, "str_starts_with((string) (\$ecf['estado_dgii']");
wa_chk('handleConsultarEstado atiende las filas en vuelo antes de la ruta RFCE',
    $s12Vuelo !== false && $s12Rfce !== false && $s12Vuelo < $s12Rfce);
$s12Filtro = preg_match('/\nfunction normalizeEstadoFilter\(.*?\n\}\n/s', $s12Ctl, $m) ? $m[0] : '';
wa_chk("normalizeEstadoFilter acepta 'pendiente'", str_contains($s12Filtro, "return 'pendiente';"), $s12Filtro);
wa_chk('ninguna respuesta lleva puede_reenviar: el boton lo decide el front por el estado (decision 7, 10-12)', !str_contains($s12Ctl, 'puede_reenviar'));
// Decision 7 redefinida (spec 2026-10-12, U7): la app nunca reenvia una factura SIN
// CONFIRMAR (en vuelo, ENVIADO, EN_PROCESO); solo reemite una RECHAZADA. El
// handleReenviar de master (4007eea) solo deja pasar los rechazos y contesta
// 409 al resto; Task 9 lo prueba de punta a punta.
$s12Reenviar = preg_match('/\nfunction handleReenviar\(.*?\n\}\n/s', $s12Ctl, $m) ? $m[0] : '';
wa_chk('POST /reenviar solo deja pasar RECHAZADO, RFCE_RECHAZADO y NO_ENCONTRADO; todo lo demas da 409',
    str_contains($s12Reenviar, "if (!in_array(\$estado, ['RECHAZADO', 'RFCE_RECHAZADO', 'NO_ENCONTRADO'], true)) {\n"
        . "        respond(false, 'Solo se puede reenviar un comprobante que la DGII rechazó.', 409);"), $s12Reenviar);

// ---------------------------------------------------------------------------
// 13) Documentacion del write-ahead (Task 19): anclas de texto en los docs.
// ---------------------------------------------------------------------------
echo "\n== 13) Documentacion del write-ahead ==\n";
$s13Leer = static fn(string $ruta): string
    => str_replace("\r\n", "\n", (string) @file_get_contents(dirname(__DIR__) . '/' . $ruta));
$s13Claude = $s13Leer('CLAUDE.md');
$s13Criticos = preg_match('/\n## Critical facts\n(.*?)\n## /s', $s13Claude, $m) ? $m[1] : '';
wa_chk('CLAUDE.md: "migraciones primero" con el verificador, dentro de Critical facts',
    str_contains($s13Criticos, 'tools/verificar_migraciones_tenant.sql'), $s13Criticos);
wa_chk('CLAUDE.md: produccion corre PHP 8.3', str_contains($s13Claude, 'PHP 8.3'));
$s13Runbook = $s13Leer('docs/integrations/alta-tenant-runbook.md');
wa_chk('runbook: Fase 5 corre el verificador de migraciones',
    (bool) preg_match('/## Fase 5.*verificar_migraciones_tenant\.sql.*## Fase 6/s', $s13Runbook));
wa_chk('runbook: Fase 5 verifica las migraciones ANTES de la emision de prueba',
    (bool) preg_match('/## Fase 5.*\*\*Migraciones.*\*\*Emisión.*## Fase 6/s', $s13Runbook));
wa_chk('runbook: troubleshooting de la 027', str_contains($s13Runbook, 'No se pudo asignar el número de comprobante'));
$s13Dgii = $s13Leer('docs/integrations/dgii-ecf.md');
wa_chk('dgii-ecf.md: listado de las filas sin confirmar', str_contains($s13Dgii, "estado_dgii IN ('PENDIENTE_ENVIO','ENVIO_INCIERTO')"));
wa_chk('dgii-ecf.md: devolucion del numero con su condicion',
    str_contains($s13Dgii, 'current_value = current_value - 1 WHERE id = :rango_id AND current_value = :secuencia_valor'));
wa_chk('dgii-ecf.md: nunca un DELETE', str_contains($s13Dgii, 'Nunca un `DELETE`'));
wa_chk('dgii-ecf.md: en los casos "la DGII no lo tiene" la app no devuelve el numero',
    str_contains($s13Dgii, 'la app **no devuelve el número**'));
wa_chk('dgii-ecf.md: una fila heredada ENVIADO sin track_id que la DGII no tiene no se fuerza',
    str_contains($s13Dgii, 'Fila heredada `ENVIADO` sin `track_id`') && str_contains($s13Dgii, '**No se fuerza**'));
$s13Api = $s13Leer('docs/api/facturas.md');
wa_chk('facturas.md: filtro pendiente, en_vuelo y el 502 con la factura guardada',
    str_contains($s13Api, '`pendiente`') && str_contains($s13Api, 'en_vuelo') && str_contains($s13Api, 'ya quedó guardada'));
wa_chk('facturas.md: ya no describe el 500 "se envió pero no se pudo guardar"',
    !str_contains($s13Api, '`500` la DGII recibió el e-CF pero no se pudo guardar'));
$s13Schema = $s13Leer('docs/database/schema.md');
wa_chk('schema.md: uk_e_ncf_amb y no el indice viejo',
    str_contains($s13Schema, 'uk_e_ncf_amb') && !str_contains($s13Schema, 'UNIQUE (`uk_e_ncf`)'));
wa_chk('auditoria.md: EMIT del envio incierto con fase_fallida', str_contains($s13Leer('docs/modules/auditoria.md'), 'fase_fallida'));
wa_chk('reporte-ventas.md: las filas en vuelo no son ventas', str_contains($s13Leer('docs/modules/reporte-ventas.md'), 'ENVIO_INCIERTO'));
wa_chk('inventario.md: una venta e-CF mueve stock fuera de SIN_STOCK', str_contains($s13Leer('docs/modules/inventario.md'), 'SIN_STOCK'));
wa_chk('architecture.md: ECFEmissionService reserva la fila antes de enviar',
    str_contains($s13Leer('docs/architecture.md'), 'build → firmar → reservar fila → enviar → confirmar; hook `$persistencia`'));
wa_chk('nada en src, docs ni CLAUDE.md nombra saveFacturaConECF',
    wa_grep(['src', 'docs', 'CLAUDE.md'], 'saveFacturaConECF') === [], wa_grep(['src', 'docs', 'CLAUDE.md'], 'saveFacturaConECF'));

// ---------------------------------------------------------------------------
// 14) Merge con origin/master (Task 1): lo que master aporto, lo que no entra y
// lo que el write-ahead conserva. Texto de los archivos, reflexion y las
// conexiones falsas de las secciones 5 y 6: nada toca la base.
// ---------------------------------------------------------------------------
echo "\n== 14) Merge con origin/master ==\n";
$s14Leer = static fn(string $ruta): string
    => str_replace("\r\n", "\n", (string) @file_get_contents(dirname(__DIR__) . '/' . $ruta));
$s14Servicio = $s14Leer('src/Utils/FacturacionElectronica/ECFEmissionService.php');
$s14Modelo = $s14Leer('src/Models/facturaModel.php');
$s14Ctl = $s14Leer('src/Controllers/facturaController.php');
$s14Inv = $s14Leer('src/Models/inventoryModel.php');
$s14Conflicto = static fn(string $texto): bool => (bool) preg_match('/^(<<<<<<<|=======|>>>>>>>)/m', $texto);

wa_chk('migraciones 032, 033 y 034 de master estan en db/migrations y en el verificador',
    is_file(dirname(__DIR__) . '/db/migrations/032_producto_imagen.sql')
    && is_file(dirname(__DIR__) . '/db/migrations/033_facturas_dias_credito.sql')
    && is_file(dirname(__DIR__) . '/db/migrations/034_categorias_color.sql')
    && str_contains($s14Leer('tools/verificar_migraciones_tenant.sql'), "SELECT '034', '034_categorias_color.sql'"));
wa_chk('el POS de master esta (PosVenta, PosCierre, mock local de la DGII)',
    is_file(dirname(__DIR__) . '/src/Pos/PosVenta.php') && is_file(dirname(__DIR__) . '/src/Pos/PosCierre.php')
    && is_file(dirname(__DIR__) . '/tools/mock_dgii_local.php'));
wa_chk('ninguno de los cuatro archivos conflictivos conserva marcas de conflicto',
    !$s14Conflicto($s14Servicio) && !$s14Conflicto($s14Modelo) && !$s14Conflicto($s14Ctl) && !$s14Conflicto($s14Inv));

// -- ECFEmissionService: flujo write-ahead + lo de master, sin tolerar_fallo_envio
wa_chk('servicio: sin tolerar_fallo_envio ni los estados RFCE_PENDIENTE / ENVIO_PENDIENTE',
    !str_contains($s14Servicio, 'tolerar_fallo_envio') && !str_contains($s14Servicio, 'RFCE_PENDIENTE')
    && !str_contains($s14Servicio, 'ENVIO_PENDIENTE'));
wa_chk('servicio: el e-CF firmado lleva formas_pago (no en strict_input)',
    str_contains($s14Servicio, "'formas_pago' => !\$strictInput && is_array(\$payload['formas_pago'] ?? null)"));
wa_chk("servicio: dgii_timeout entra como \$opcionesEnvio['timeout'] y lo usan recibir y recibirResumen",
    str_contains($s14Servicio, "\$opcionesEnvio['timeout'] = (int) \$payload['dgii_timeout'];")
    && substr_count($s14Servicio, '$bearerToken, $opcionesEnvio)') === 2, substr_count($s14Servicio, '$bearerToken, $opcionesEnvio)'));
$s14Firmas = [
    'reenviarRFCE' => ['rfceXml', 'ambiente', 'timeout'],
    // F9 (revision final): le sumo un timeout al final, para que el reenvio del POS no espere 30 s a la DGII.
    'consultarTrackIds' => ['eNcf', 'ambiente', 'timeout'],
    'reenviarECF' => ['signedXml', 'ambiente', 'timeout'],
];
foreach ($s14Firmas as $metodo => $parametros) {
    $m = method_exists(ECFEmissionService::class, $metodo) ? new ReflectionMethod(ECFEmissionService::class, $metodo) : null;
    wa_chk("servicio: {$metodo} es publico, con los parametros de master",
        $m !== null && $m->isPublic() && !$m->isStatic()
        && array_map(static fn(ReflectionParameter $p): string => $p->getName(), $m->getParameters()) === $parametros);
}
wa_chk('servicio: emitir sigue recibiendo el gancho $persistencia (write-ahead)',
    (new ReflectionMethod(ECFEmissionService::class, 'emitir'))->getNumberOfParameters() === 2
    && str_contains($s14Servicio, "\$reserva = \$persistencia('reservar', \$ctxReservar);"));

// -- facturaModel: dias_credito, el candado del reenvio y el archivo de rechazos
wa_chk('facturaModel: tomarLockReenvio y archivarRechazoReenviado de master',
    method_exists(facturaModel::class, 'tomarLockReenvio') && method_exists(facturaModel::class, 'archivarRechazoReenviado'));
wa_chk('facturaModel: reservarFacturaECF sigue y saveFacturaConECF no vuelve',
    method_exists(facturaModel::class, 'reservarFacturaECF') && !method_exists(facturaModel::class, 'saveFacturaConECF'));
wa_chk('facturaModel: DIAS_CREDITO_MAX es 365',
    defined('facturaModel::DIAS_CREDITO_MAX') && constant('facturaModel::DIAS_CREDITO_MAX') === 365);
$s14Dias = [];
foreach ([null, '', '1', '30', 45, '365', '0', '366', 'abc', '45.5', -5] as $valor) {
    $s14Dias[] = method_exists(facturaModel::class, 'diasCreditoValido') ? facturaModel::diasCreditoValido($valor) : null;
}
wa_chk('facturaModel::diasCreditoValido: vacio y de 1 a 365 sirven; 0, 366, texto y decimales no',
    $s14Dias === [true, true, true, true, true, true, false, false, false, false, false], $s14Dias);
$s14DiasMetodo = method_exists(facturaModel::class, 'diasCredito') ? new ReflectionMethod(facturaModel::class, 'diasCredito') : null;
$s14DiasObtenidos = $s14DiasMetodo === null ? null : [
    $s14DiasMetodo->invoke(null, '45', 2), $s14DiasMetodo->invoke(null, '45', 1), $s14DiasMetodo->invoke(null, '', 2),
    $s14DiasMetodo->invoke(null, '999', 2), $s14DiasMetodo->invoke(null, null, 2),
];
wa_chk('facturaModel::diasCredito: guarda el plazo solo a credito y valido',
    $s14DiasObtenidos === [45, null, null, null, null], $s14DiasObtenidos);
wa_chk('facturaModel: la factura simple guarda dias_credito al crear y al editar',
    str_contains($s14Modelo, ':dias_credito, :NCF, NULL)') && str_contains($s14Modelo, 'dias_credito = :dias_credito, NCF = :NCF'));

$c = new GuionConexion();
$c->filas = ['GET_LOCK' => [[1]]];
$libre = method_exists(facturaModel::class, 'tomarLockReenvio') ? puro_modelo($c)->tomarLockReenvio(41) : null;
$sqlLock = (string) $c->sql('GET_LOCK');
wa_chk('tomarLockReenvio: candado libre da true, sin espera y por factura',
    $libre === true && str_contains($sqlLock, "GET_LOCK(CONCAT(DATABASE(), ':reenvio_ecf:', :id), 0)")
    && $c->params('GET_LOCK') === [':id' => 41], [$sqlLock, $c->params('GET_LOCK')]);
$c = new GuionConexion();
$c->filas = ['GET_LOCK' => [[0]]];
wa_chk('tomarLockReenvio: candado ocupado da false',
    method_exists(facturaModel::class, 'tomarLockReenvio') && puro_modelo($c)->tomarLockReenvio(41) === false);
$c = new GuionConexion();
$c->prepareFalla = true;
[$sinBase, $e, $log] = puro_con_log(static fn() => puro_modelo($c)->tomarLockReenvio(41));
wa_chk('tomarLockReenvio: con la base caida da false y lo deja en el log',
    $e === null && $sinBase === false && str_contains($log, '[facturas] no se pudo tomar el lock de reenvio 41'), [$e?->getMessage(), $log]);
$c = new GuionConexion();
[, $e] = puro_con_log(static fn() => puro_modelo($c)->archivarRechazoReenviado(41));
$sqlArchivo = (string) $c->sql('UPDATE facturas SET estado_dgii');
wa_chk('archivarRechazoReenviado: archiva solo si sigue en rechazo (un UPDATE con el estado en el WHERE)',
    $e === null && str_contains($sqlArchivo, "estado_dgii = CONCAT(estado_dgii, '_ARCHIVADO')")
    && str_contains($sqlArchivo, "estado_dgii IN ('RECHAZADO', 'RFCE_RECHAZADO', 'NO_ENCONTRADO')")
    && $c->params('UPDATE facturas SET estado_dgii') === [':id' => 41], [$e?->getMessage(), $sqlArchivo]);

// -- facturaController: la emision de master (emitirFacturaEcf) con el flujo write-ahead
wa_chk('controlador: emitirFacturaEcf tiene la firma de master',
    str_contains($s14Ctl, 'function emitirFacturaEcf(array $input, facturaModel $facturaModel, clientModel $clientModel, ?int $reenvioDe = null): void'));
wa_chk('controlador: la ruta y handleReenviar llevan el clientModel de master',
    str_contains($s14Ctl, 'handleReenviar((int) $reenviarMatches[1], $facturaModel, $clientModel);')
    && str_contains($s14Ctl, 'function handleReenviar(int $facturaId, facturaModel $facturaModel, clientModel $clientModel): void'));
wa_chk('controlador: armarInputReenvio y campoXmlReenvio de master',
    str_contains($s14Ctl, 'function armarInputReenvio(array $f, array $guardadas, ?int $userId): array')
    && str_contains($s14Ctl, 'function campoXmlReenvio(string $xml, string $tag): string'));
wa_chk('controlador: la respuesta de un reenvio lleva reenvio_de', str_contains($s14Ctl, "\$data['reenvio_de'] = \$reenvioDe;"));
wa_chk('controlador: handleEstadoEnVuelo y moverStockPrimeraAceptacion siguen (el conflicto de handleReenviar)',
    str_contains($s14Ctl, 'function handleEstadoEnVuelo(int $facturaId, array $ecf): void')
    && str_contains($s14Ctl, 'function moverStockPrimeraAceptacion('));

// -- inventoryModel: los dos metodos
wa_chk('inventoryModel: yaMovioDocumento (write-ahead) y devolverSaldoDeFactura (master)',
    method_exists(inventoryModel::class, 'yaMovioDocumento') && method_exists(inventoryModel::class, 'devolverSaldoDeFactura'));

// ---------------------------------------------------------------------------
// 15) POS: EstadosEcf::envioPendiente, la reserva con las columnas del POS, la
// venta repetida y envio_pendiente sincronizado en confirmar/incierto
// (Task 3 del plan POS y reenvio). Conexion falsa: nada toca MySQL. Reusa
// GuionConexion, $reservar, $confirmar, $marcar y $es de las secciones 5 y 6.
// ---------------------------------------------------------------------------
echo "\n== 15) POS: envio_pendiente, columnas del POS en la reserva y venta repetida ==\n";

$envioPendienteEsperado = [
    // Sin veredicto de la DGII: el reenvio automatico del POS los busca.
    'PENDIENTE_ENVIO' => 1, 'ENVIO_INCIERTO' => 1, 'ENVIADO' => 1, 'EN_PROCESO' => 1, 'RFCE_ENVIADO' => 1, 'RFCE_EN_PROCESO' => 1,
    // Veredicto final, o un numero que no se reenvia solo (ERROR, NO_ENCONTRADO).
    'ACEPTADO' => 0, 'ACEPTADO_CONDICIONAL' => 0, 'RECHAZADO' => 0, 'NO_ENCONTRADO' => 0, 'ERROR' => 0,
    'RFCE_ACEPTADO' => 0, 'RFCE_RECHAZADO' => 0, 'RFCE_NO_ENCONTRADO' => 0, 'RFCE_ERROR' => 0,
    'RECHAZADO_ARCHIVADO' => 0, '' => 0,
];
$envioPendienteObtenido = [];
foreach ($envioPendienteEsperado as $estado => $_) {
    $envioPendienteObtenido[$estado] = EstadosEcf::envioPendiente((string) $estado);
}
wa_chk('15: EstadosEcf::envioPendiente es 1 solo para un estado sin veredicto', $envioPendienteObtenido === $envioPendienteEsperado,
    $envioPendienteObtenido);
$fueraDeLista = [];
foreach (array_merge(EstadosEcf::EN_VUELO, EstadosEcf::SIN_VEREDICTO) as $estado) {
    if (EstadosEcf::envioPendiente($estado) !== 1) {
        $fueraDeLista[] = $estado . '=0';
    }
}
foreach (EstadosEcf::SIN_STOCK as $estado) {
    if (EstadosEcf::envioPendiente($estado) !== 0) {
        $fueraDeLista[] = $estado . '=1';
    }
}
wa_chk('15: EstadosEcf::envioPendiente coincide con EN_VUELO, SIN_VEREDICTO y SIN_STOCK', $fueraDeLista === [], $fueraDeLista);

require_once __DIR__ . '/../src/Utils/FacturacionElectronica/EcfVentaRepetidaException.php';
$clavePos = '4f1c2d3e-0a1b-4c5d-8e9f-0123456789ab';
$repetida = new EcfVentaRepetidaException($clavePos, new RuntimeException('previa'));
wa_chk('15: EcfVentaRepetidaException es RuntimeException (no EcfUsuarioException) y lleva la clave',
    $repetida instanceof RuntimeException && !($repetida instanceof EcfUsuarioException) && $repetida->clave === $clavePos
    && str_contains($repetida->getMessage(), $clavePos) && $repetida->getPrevious()?->getMessage() === 'previa',
    [get_class($repetida), $repetida->getMessage()]);

$pos = ['turno_id' => 3, 'pos_empleado_id' => 4, 'pos_idempotency_key' => $clavePos];
$c = new GuionConexion();
[$r, $e] = $reservar($c, [], ['pos' => $pos]);
$insert = (string) $c->sql('INSERT INTO facturas');
$fila = $c->params('INSERT INTO facturas') ?? [];
wa_chk('15: reserva del POS: las 4 columnas de la migracion 030 van en el mismo INSERT',
    $e === null && str_contains($insert, 'razon_modificacion, turno_id, pos_empleado_id, pos_idempotency_key, envio_pendiente)')
    && str_contains($insert, ':razon_modificacion, :turno_id, :pos_empleado_id, :pos_idempotency_key, :envio_pendiente)'),
    $e?->getMessage() ?? $insert);
wa_chk('15: reserva del POS: turno, empleado, clave y envio_pendiente = 1 (nace sin veredicto)',
    $es($fila, ':turno_id', 3) && $es($fila, ':pos_empleado_id', 4) && $es($fila, ':pos_idempotency_key', $clavePos)
    && $es($fila, ':envio_pendiente', 1) && $es($fila, ':estado_dgii', 'PENDIENTE_ENVIO'), $fila);
wa_chk('15: reserva del POS: devuelve las mismas tres claves de siempre',
    $r === ['factura_id' => 41, 'e_ncf' => 'E310000000123', 'desde' => '2026-10-05 14:03:11'], $r);
wa_chk('15: reserva del POS: una transaccion, un commit y nada abierto',
    count($c->de('beginTransaction')) === 1 && count($c->de('commit')) === 1 && $c->de('rollBack') === [] && !$c->inTransaction(),
    $c->llamadas);

$c = new GuionConexion();
[, $e] = $reservar($c);
$insert = (string) $c->sql('INSERT INTO facturas');
$fila = $c->params('INSERT INTO facturas') ?? [];
wa_chk('15: reserva de la app (sin pos): el INSERT no nombra ninguna columna del POS (es el de siempre)',
    $e === null && !str_contains($insert, 'turno_id') && !str_contains($insert, 'pos_empleado_id')
    && !str_contains($insert, 'pos_idempotency_key') && !str_contains($insert, 'envio_pendiente')
    && !array_key_exists(':turno_id', $fila) && !array_key_exists(':envio_pendiente', $fila), $e?->getMessage() ?? $insert);

$malos = [];
foreach ([
    'sin clave' => ['turno_id' => 3, 'pos_empleado_id' => 4],
    'clave en blanco' => ['turno_id' => 3, 'pos_empleado_id' => 4, 'pos_idempotency_key' => '  '],
    'clave de 37 caracteres' => ['turno_id' => 3, 'pos_empleado_id' => 4, 'pos_idempotency_key' => str_repeat('a', 37)],
] as $nombre => $posMalo) {
    $c = new GuionConexion();
    [, $e] = $reservar($c, [], ['pos' => $posMalo]);
    if (!($e instanceof InvalidArgumentException) || $c->llamadas !== []) {
        $malos[$nombre] = [$e === null ? null : get_class($e), count($c->llamadas)];
    }
}
wa_chk('15: pos sin clave valida: InvalidArgumentException antes de cualquier consulta', $malos === [], $malos);

$c = new GuionConexion();
$duplicadoPos = puro_pdo_error(1062, '23000', "Duplicate entry '{$clavePos}' for key 'facturas.uk_facturas_pos_idem'");
$c->errores[] = ['INSERT INTO facturas', $duplicadoPos];
[, $e] = $reservar($c, [], ['pos' => $pos]);
wa_chk('15: 1062 sobre uk_facturas_pos_idem: EcfVentaRepetidaException con la clave y el PDOException como previa',
    $e instanceof EcfVentaRepetidaException && $e->clave === $clavePos && $e->getPrevious() === $duplicadoPos,
    $e === null ? null : get_class($e));
wa_chk('15: venta repetida: rollBack, sin commit y nada abierto (el archivo de la fila previa tambien se deshace)',
    count($c->de('rollBack')) === 1 && $c->de('commit') === [] && !$c->inTransaction(), $c->llamadas);

$c = new GuionConexion();
$c->errores[] = ['INSERT INTO facturas', puro_pdo_error(1062, '23000', "Duplicate entry '{$clavePos}' for key 'uk_facturas_pos_idem'")];
[, $e] = $reservar($c, [], ['pos' => $pos]);
wa_chk('15: el mismo 1062 con el nombre de la clave sin prefijo de tabla (MariaDB, MySQL 5.7)',
    $e instanceof EcfVentaRepetidaException && $e->clave === $clavePos, $e === null ? null : get_class($e));

$c = new GuionConexion();
$c->errores[] = ['INSERT INTO facturas', puro_pdo_error(1062, '23000', "Duplicate entry 'E310000000123-ecf' for key 'facturas.uk_e_ncf_amb'")];
[, $e] = $reservar($c, [], ['pos' => $pos]);
wa_chk('15: 1062 sobre uk_e_ncf_amb en una reserva del POS: EcfNumeroOcupadoException, no venta repetida',
    $e instanceof EcfNumeroOcupadoException && !($e instanceof EcfVentaRepetidaException), $e === null ? null : get_class($e));

$c = new GuionConexion();
$confirmar($c);
$sql = (string) $c->sql('UPDATE facturas SET estado_dgii');
wa_chk('15: confirmar: envio_pendiente va en el MISMO UPDATE y solo cambia en filas con pos_idempotency_key',
    str_contains($sql, 'envio_pendiente = CASE WHEN pos_idempotency_key IS NOT NULL THEN :pend ELSE envio_pendiente END')
    && str_contains($sql, "WHERE id = :id AND estado_dgii IN ('PENDIENTE_ENVIO', 'ENVIO_INCIERTO')")
    && count($c->de('prepare')) === 1 && $c->de('beginTransaction') === [], $sql);
$pendEsperado = [
    'ACEPTADO' => 0, 'ACEPTADO_CONDICIONAL' => 0, 'ENVIADO' => 1, 'EN_PROCESO' => 1, 'RFCE_ENVIADO' => 1, 'RFCE_EN_PROCESO' => 1,
    'RFCE_ACEPTADO' => 0, 'RECHAZADO' => 0, 'RFCE_RECHAZADO' => 0, 'NO_ENCONTRADO' => 0, 'ERROR' => 0,
];
$pendObtenido = [];
foreach ($pendEsperado as $estado => $_) {
    $c = new GuionConexion();
    $confirmar($c, ['estado' => $estado]);
    $pendObtenido[$estado] = ($c->params('UPDATE facturas SET estado_dgii') ?? [])[':pend'] ?? 'falta';
}
wa_chk('15: confirmar: :pend es EstadosEcf::envioPendiente del estado nuevo (veredicto abierto = 1)',
    $pendObtenido === $pendEsperado, $pendObtenido);

$c = new GuionConexion();
$marcar($c, puro_ctx_incierto());
$sql = (string) $c->sql("estado_dgii = 'ENVIO_INCIERTO'");
$p = $c->params("estado_dgii = 'ENVIO_INCIERTO'") ?? [];
wa_chk('15: marcar incierto: envio_pendiente = 1 en el MISMO UPDATE y solo en filas del POS',
    str_contains($sql, 'envio_pendiente = CASE WHEN pos_idempotency_key IS NOT NULL THEN :pend ELSE envio_pendiente END')
    && str_contains($sql, "WHERE id = :id AND estado_dgii = 'PENDIENTE_ENVIO'")
    && $es($p, ':pend', 1) && $es($p, ':id', 41) && count($c->de('prepare')) === 1, [$sql, $p]);

// ---------------------------------------------------------------------------
// 15b) POS: DgiiAuthService::ambienteEfectivo, la meta sin_rango y las firmas de
// ncfModel (Task 4 del plan POS y reenvio). Conexion falsa: nada toca MySQL.
// Reusa $reservar, $marcar y puro_ctx_incierto de las secciones 5 y 6.
// ---------------------------------------------------------------------------
echo "\n== 15b) POS: ambienteEfectivo, meta sin_rango y firmas de ncfModel ==\n";

$authPuro = new DgiiAuthService();
$refAmbiente = new ReflectionMethod(DgiiAuthService::class, 'ambienteEfectivo');
wa_chk('15b: DgiiAuthService::ambienteEfectivo(array $options = []): string es publico',
    $refAmbiente->isPublic() && !$refAmbiente->isStatic() && (string) $refAmbiente->getReturnType() === 'string'
    && $refAmbiente->getNumberOfParameters() === 1 && $refAmbiente->getParameters()[0]->isOptional());
$ambientesObtenidos = [
    'cert' => $authPuro->ambienteEfectivo(['environment' => 'cert']),
    'Certificacion con espacio' => $authPuro->ambienteEfectivo(['environment' => 'Certificacion ']),
    'produccion' => $authPuro->ambienteEfectivo(['environment' => 'produccion']),
    'prod' => $authPuro->ambienteEfectivo(['environment' => 'prod']),
    'test' => $authPuro->ambienteEfectivo(['environment' => 'test']),
    'ambiente testing' => $authPuro->ambienteEfectivo(['ambiente' => 'testing']),
    'environment null, ambiente certecf' => $authPuro->ambienteEfectivo(['environment' => null, 'ambiente' => 'certecf']),
    'ecf' => $authPuro->ambienteEfectivo(['environment' => 'ecf']),
];
wa_chk('15b: ambienteEfectivo normaliza los alias igual que autenticar() (sin llamar a la DGII)',
    $ambientesObtenidos === [
        'cert' => 'certecf', 'Certificacion con espacio' => 'certecf', 'produccion' => 'ecf', 'prod' => 'ecf', 'test' => 'testecf',
        'ambiente testing' => 'testecf', 'environment null, ambiente certecf' => 'certecf', 'ecf' => 'ecf',
    ], $ambientesObtenidos);

$refSinRango = new ReflectionMethod(ncfModel::class, 'dispenseSinRango');
$refCubre = new ReflectionMethod(ncfModel::class, 'rangoCubre');
$firma = static fn(ReflectionMethod $m): string => implode(', ', array_map(
    static fn(ReflectionParameter $p): string => $p->getType() . ' $' . $p->getName(), $m->getParameters()
)) . ' -> ' . $m->getReturnType();
wa_chk('15b: ncfModel::dispenseSinRango(string $type, string $ambiente): ?array es publico',
    $refSinRango->isPublic() && !$refSinRango->isStatic() && $firma($refSinRango) === 'string $type, string $ambiente -> ?array',
    $firma($refSinRango));
wa_chk('15b: ncfModel::rangoCubre(string $type, string $ambiente, int $valor): bool es publico',
    $refCubre->isPublic() && !$refCubre->isStatic() && $firma($refCubre) === 'string $type, string $ambiente, int $valor -> bool',
    $firma($refCubre));

$c = new GuionConexion();
[, $e] = $reservar($c, ['sin_rango' => true, 'rango_id' => null]);
$meta = json_decode((string) (($c->params('INSERT INTO facturas') ?? [])[':respuesta_dgii'] ?? ''), true);
wa_chk('15b: reserva con sin_rango: la meta lo guarda al final (despues de secuencia_valor), con rango_id null',
    $e === null && $meta === ['fase' => 'PENDIENTE_ENVIO', 'desde' => '2026-10-05 14:03:11', 'flujo' => 'ECF',
        'ambiente_secuencia' => 'ecf', 'dispensado' => true, 'rango_id' => null, 'secuencia_valor' => 123, 'sin_rango' => true],
    $e?->getMessage() ?? $meta);
$c = new GuionConexion();
[, $e] = $reservar($c, ['sin_rango' => false]);
$meta = json_decode((string) (($c->params('INSERT INTO facturas') ?? [])[':respuesta_dgii'] ?? ''), true);
wa_chk('15b: sin_rango false (o ausente): la meta es la de siempre, 7 claves y ninguna sin_rango',
    $e === null && is_array($meta) && count($meta) === 7 && !array_key_exists('sin_rango', $meta), $meta);
$c = new GuionConexion();
$marcar($c, puro_ctx_incierto(['sin_rango' => true, 'rango_id' => null]));
$meta = json_decode((string) (($c->params("estado_dgii = 'ENVIO_INCIERTO'") ?? [])[':json'] ?? ''), true);
wa_chk('15b: marcar incierto de una venta fuera de rango conserva sin_rango en la meta',
    is_array($meta) && array_keys($meta) === ['fase', 'desde', 'flujo', 'ambiente_secuencia', 'dispensado', 'rango_id',
        'secuencia_valor', 'sin_rango', 'fase_fallida', 'clase', 'error', 'resultado_parcial', 'intentos']
    && $meta['sin_rango'] === true && $meta['rango_id'] === null, $meta);

// ---------------------------------------------------------------------------
// 15c) POS: facturaModel::updateECFEstado (la consulta de estado de la app) deja
// envio_pendiente en el MISMO UPDATE, solo en filas del POS (Task 8 del plan POS
// y reenvio). Conexion falsa: nada toca MySQL. Reusa GuionConexion, puro_modelo y
// $es de las secciones 5 y 6. El SQL real se prueba en tools/test_pos_reenvio.php (P10).
// ---------------------------------------------------------------------------
echo "\n== 15c) POS: updateECFEstado y envio_pendiente ==\n";

$consulta = static fn(GuionConexion $c, string $estado, ?array $respuesta = ['codigo' => 1]): bool
    => puro_modelo($c)->updateECFEstado(41, $estado, $respuesta);
$c = new GuionConexion();
$ok = $consulta($c, 'ACEPTADO', ['codigo' => 1, 'secuenciaUtilizada' => true]);
$sql = (string) $c->sql('UPDATE facturas SET estado_dgii');
$p = $c->params('UPDATE facturas SET estado_dgii') ?? [];
wa_chk('15c: updateECFEstado: envio_pendiente en el MISMO UPDATE y solo en filas del POS; un solo UPDATE por id',
    $ok === true && str_contains($sql, 'envio_pendiente = CASE WHEN pos_idempotency_key IS NULL THEN envio_pendiente ELSE :pend END')
    && str_contains($sql, 'WHERE id = :id') && count($c->de('prepare')) === 1 && $c->de('beginTransaction') === [], $sql);
wa_chk('15c: updateECFEstado: sigue escribiendo estado, respuesta, secuencia_utilizada y el id',
    $es($p, ':estado', 'ACEPTADO') && $es($p, ':resp', json_encode(['codigo' => 1, 'secuenciaUtilizada' => true]))
    && $es($p, ':secuencia', 1) && $es($p, ':id', 41), $p);
$pendEsperado15c = $pendEsperado + ['PENDIENTE_ENVIO' => 1, 'ENVIO_INCIERTO' => 1];
$pendObtenido = [];
foreach ($pendEsperado15c as $estado => $_) {
    $c = new GuionConexion();
    $consulta($c, $estado);
    $pendObtenido[$estado] = ($c->params('UPDATE facturas SET estado_dgii') ?? [])[':pend'] ?? 'falta';
}
wa_chk('15c: updateECFEstado: :pend es EstadosEcf::envioPendiente del estado nuevo (ERROR y NO_ENCONTRADO = 0)',
    $pendObtenido === $pendEsperado15c && $pendObtenido['ERROR'] === 0
    && $pendObtenido['NO_ENCONTRADO'] === 0 && $pendObtenido['ENVIADO'] === 1, $pendObtenido);
$c = new GuionConexion();
$c->errores[] = ['UPDATE facturas SET estado_dgii', new PDOException('se cayo la conexion')];
wa_chk('15c: updateECFEstado: un PDOException sigue devolviendo false (la consulta de la app no cae)',
    $consulta($c, 'ACEPTADO') === false);

// ---------------------------------------------------------------------------
// Reenviar a la DGII sobre el write-ahead (Task 9). Texto del controlador, como en las secciones 11 y 12:
// una rechazada se reemite con su mismo e-NCF y nunca se reenvia una sin confirmar (decision 7, 10-12).
// ---------------------------------------------------------------------------
echo "\n== Reenviar a la DGII sobre el write-ahead ==\n";
$t9Ctl = str_replace("\r\n", "\n", (string) file_get_contents(__DIR__ . '/../src/Controllers/facturaController.php'));
$t9Fn = static fn(string $nombre): string
    => preg_match('/\nfunction ' . $nombre . '\(.*?\n\}\n/s', $t9Ctl, $m) ? $m[0] : '';
$t9Reenviar = $t9Fn('handleReenviar');
$t9Emitir = $t9Fn('emitirFacturaEcf');
$t9Armar = $t9Fn('armarInputReenvio');
$t9Cerrar = $t9Fn('cerrarReenvioRechazado');
wa_chk('FACTURA_REENVIO_SIN_CONFIRMAR es el texto del 409, letra por letra',
    preg_match("/\nconst FACTURA_REENVIO_SIN_CONFIRMAR = '([^']*)';/", $t9Ctl, $m) === 1
    && $m[1] === 'Este comprobante todavía no tiene respuesta de la DGII, así que no se puede reenviar desde aquí. '
        . 'No lo emitas de nuevo; avisa a soporte con el número %s.', $m[1] ?? null);
wa_chk('handleReenviar toma el candado antes de leer la factura',
    str_contains($t9Reenviar, 'function handleReenviar(int $facturaId, facturaModel $facturaModel, clientModel $clientModel): void')
    && strpos($t9Reenviar, 'tomarLockReenvio(') !== false && strpos($t9Reenviar, 'tomarLockReenvio(') < strpos($t9Reenviar, 'getFacturas('));
wa_chk('handleReenviar conserva las condiciones de master: solo e-CF, rechazada sin archivar, secuencia no consumida, mismo ambiente, con lineas',
    str_contains($t9Reenviar, "empty(\$f['tipo_ecf'])") && str_contains($t9Reenviar, "str_ends_with(\$estado, '_ARCHIVADO')")
    && str_contains($t9Reenviar, "['RECHAZADO', 'RFCE_RECHAZADO', 'NO_ENCONTRADO']") && str_contains($t9Reenviar, "'secuencia_utilizada'")
    && str_contains($t9Reenviar, 'AmbienteResolver::active()') && str_contains($t9Reenviar, 'getFacturaItems('));
wa_chk('un comprobante sin confirmar se rechaza (409) antes de emitir: EstadosEcf::SIN_VEREDICTO va antes de emitirFacturaEcf',
    strpos($t9Reenviar, 'EstadosEcf::SIN_VEREDICTO') !== false
    && strpos($t9Reenviar, 'EstadosEcf::SIN_VEREDICTO') < strpos($t9Reenviar, 'emitirFacturaEcf(')
    && str_contains($t9Reenviar, 'sprintf(FACTURA_REENVIO_SIN_CONFIRMAR'));
wa_chk('handleReenviar reemite por emitirFacturaEcf con $facturaId como reenvioDe',
    str_contains($t9Reenviar, 'emitirFacturaEcf($input, $facturaModel, $clientModel, $facturaId);'));
wa_chk('el input lleva el e-NCF de la rechazada y el vencimiento del rango que lo cubre',
    str_contains($t9Armar, "'e_ncf' => (string) \$f['e_ncf'],")
    && str_contains($t9Reenviar, 'vencimientoDeLaSecuencia((string) $f[\'e_ncf\']')
    && str_contains($t9Reenviar, "\$input['fecha_vencimiento_secuencia'] = \$vence;")
    && str_contains($t9Fn('vencimientoDeLaSecuencia'), '->listRanges('));
wa_chk('ya no hay un stub 501 en ningun lugar del controlador', !preg_match('/respond\(false, [^\n]*, 501\)/', $t9Ctl));
wa_chk('emitirFacturaEcf cierra el reenvio con la fila nueva guardada, en el camino normal y en el 502 incierto',
    substr_count($t9Emitir, 'cerrarReenvioRechazado($reenvioDe, $userId, $facturaModel);') === 2
    && str_contains($t9Emitir, "\$data['reenvio_de'] = \$reenvioDe;"));
wa_chk('archivarRechazoReenviado y devolverSaldoDeFactura solo se llaman desde cerrarReenvioRechazado',
    substr_count($t9Ctl, '->archivarRechazoReenviado(') === 1 && substr_count($t9Ctl, '->devolverSaldoDeFactura(') === 1
    && str_contains($t9Cerrar, '->archivarRechazoReenviado($reenvioDe)') && str_contains($t9Cerrar, '->devolverSaldoDeFactura($reenvioDe, $userId)'));
wa_chk('el controlador sigue sin saveFacturaConECF', !str_contains($t9Ctl, 'saveFacturaConECF'));
wa_chk('emitirFacturaEcf emite una sola vez: el reenvio no reintenta por su cuenta', substr_count($t9Emitir, '->emitir(') === 1);

// ---------------------------------------------------------------------------
// AV) Avisos al admin (Task 10, spec 4.6). La clasificacion de las secuencias
// es pura (sin base ni red) y el cableado de GET /api/facturas/avisos se prueba
// sobre el texto del controller. La parte con base de datos esta en
// tools/test_write_ahead_api.php (escenarios AV1-AV6).
// ---------------------------------------------------------------------------
echo "\n== AV) Avisos al admin ==\n";
require_once __DIR__ . '/../src/Models/facturaModel.php';

wa_chk('umbral de "quedan pocos": 50 numeros',
    defined('facturaModel::AVISO_SECUENCIA_POCOS') && facturaModel::AVISO_SECUENCIA_POCOS === 50);
$avTiene = method_exists(facturaModel::class, 'clasificarSecuencias');
wa_chk('facturaModel::clasificarSecuencias existe', $avTiene);
wa_chk('facturaModel::getAvisosEcf existe', method_exists(facturaModel::class, 'getAvisosEcf'));
if ($avTiene) {
    $avRango = static fn(string $tipo, int $desde, ?int $hasta, int $actual, ?string $vence = '2099-12-31'): array => [
        'type' => $tipo, 'numero_desde' => $desde, 'numero_hasta' => $hasta,
        'current_value' => $actual, 'fecha_vencimiento' => $vence,
    ];
    $avAviso = static fn(string $tipo, int $restantes, string $estado): array
        => ['tipo' => $tipo, 'ambiente' => 'certecf', 'restantes' => $restantes, 'estado' => $estado];
    $avClasificar = static fn(array $rangos): array => facturaModel::clasificarSecuencias($rangos, 'certecf', '2026-10-12');

    wa_chk('50 libres: pocos; 51 libres: sin aviso',
        $avClasificar([$avRango('E31', 1, 100, 50)]) === [$avAviso('E31', 50, 'pocos')]
        && $avClasificar([$avRango('E31', 1, 100, 49)]) === []);
    wa_chk('un solo numero libre: pocos con 1',
        $avClasificar([$avRango('E31', 1, 100, 99)]) === [$avAviso('E31', 1, 'pocos')]);
    wa_chk('rango consumido: agotada con 0 libres',
        $avClasificar([$avRango('E32', 1, 100, 100)]) === [$avAviso('E32', 0, 'agotada')]);
    wa_chk('numeros usados fuera de rango (current_value > numero_hasta): agotada, nunca negativo',
        $avClasificar([$avRango('E32', 1, 100, 103)]) === [$avAviso('E32', 0, 'agotada')]);
    wa_chk('rango sin limite (legacy, numero_hasta NULL): nunca avisa',
        $avClasificar([$avRango('E33', 1, null, 5000)]) === []);
    wa_chk('rango vencido que se uso: agotada',
        $avClasificar([$avRango('E41', 1, 100, 10, '2026-10-11')]) === [$avAviso('E41', 0, 'agotada')]);
    wa_chk('rango vencido que nunca se uso: sin aviso (el tipo no se usa)',
        $avClasificar([$avRango('E43', 1, 100, 0, '2026-10-11')]) === []);
    wa_chk('vence hoy: todavia dispensa (igual que dispenseNextECF)',
        $avClasificar([$avRango('E44', 1, 100, 60, '2026-10-12')]) === [$avAviso('E44', 40, 'pocos')]);
    wa_chk('sin fecha de vencimiento (NULL): no vence',
        $avClasificar([$avRango('E44', 1, 100, 60, null)]) === [$avAviso('E44', 40, 'pocos')]);
    wa_chk('dos rangos: los libres se suman (100 libres: sin aviso)',
        $avClasificar([$avRango('E45', 1, 100, 100), $avRango('E45', 101, 200, 100)]) === []);
    wa_chk('dos rangos, el siguiente casi vacio: pocos con la suma',
        $avClasificar([$avRango('E45', 1, 100, 100), $avRango('E45', 101, 130, 100)]) === [$avAviso('E45', 30, 'pocos')]);
    wa_chk('un rango sin limite junto a uno agotado: sin aviso',
        $avClasificar([$avRango('E46', 1, 100, 100), $avRango('E46', 101, null, 100)]) === []);
    wa_chk('salen ordenados por tipo',
        $avClasificar([$avRango('E34', 1, 100, 90), $avRango('E31', 1, 100, 90)])
            === [$avAviso('E31', 10, 'pocos'), $avAviso('E34', 10, 'pocos')]);
    wa_chk('sin rangos: sin avisos', $avClasificar([]) === []);
    $avVarios = $avClasificar([
        $avRango('E31', 1, 100, 100), $avRango('E31', 101, 200, 180), $avRango('E31', 201, 230, 200),
        $avRango('E32', 1, 100, 100), $avRango('E32', 101, 200, 200),
    ]);
    $avTipos = array_column($avVarios, 'tipo');
    wa_chk('a lo mas un aviso por tipo, aunque el tipo tenga varios rangos (la pantalla no repite alertas)',
        $avTipos === array_values(array_unique($avTipos)) && $avVarios === [$avAviso('E31', 50, 'pocos'), $avAviso('E32', 0, 'agotada')], $avVarios);
    $avUno = facturaModel::clasificarSecuencias([$avRango('E31', 1, 100, 99)], 'ecf', '2026-10-12')[0] ?? [];
    $avClavesUno = array_keys($avUno);
    sort($avClavesUno);
    wa_chk('cada aviso trae exactamente tipo, ambiente, restantes y estado, y el ambiente es el que se pidio',
        $avClavesUno === ['ambiente', 'estado', 'restantes', 'tipo'] && ($avUno['ambiente'] ?? null) === 'ecf', $avUno);
}

$avCtl = (string) file_get_contents(__DIR__ . '/../src/Controllers/facturaController.php');
wa_chk('facturaController: ruta $isAvisosRequest, manejador handleAvisosEcf y su 500',
    str_contains($avCtl, '$isAvisosRequest = preg_match(')
    && str_contains($avCtl, 'function handleAvisosEcf(facturaModel $facturaModel): void')
    && str_contains($avCtl, 'No se pudieron cargar los avisos del e-CF. Inténtalo de nuevo.'));
$avPosDespacho = strpos($avCtl, 'handleAvisosEcf($facturaModel);');
$avPosListado = strpos($avCtl, "isset(\$_GET['id'])");
wa_chk('facturaController: GET avisos se despacha antes del listado',
    $avPosDespacho !== false && $avPosListado !== false && $avPosDespacho < $avPosListado, [$avPosDespacho, $avPosListado]);

// ---------------------------------------------------------------------------
// 16) Documentacion de la venta del POS y de «Reenviar a la DGII» (Task 15).
// ---------------------------------------------------------------------------
echo "\n== 16) Documentacion del POS y de Reenviar a la DGII ==\n";
$s16Leer = static fn(string $ruta): string
    => str_replace("\r\n", "\n", (string) @file_get_contents(dirname(__DIR__) . '/' . $ruta));
// Sin saltos de linea ni espacios repetidos: los parrafos de CLAUDE.md y del runbook parten las lineas.
$s16Plano = static fn(string $texto): string => preg_replace('/\s+/', ' ', $texto);
$s16Pos = $s16Leer('docs/integrations/pos-write-ahead.md');
wa_chk('pos-write-ahead.md: describe lo que hace el codigo (entrega, factura simple, sin_rango, opciones del POS, reenvio)',
    str_contains($s16Pos, 'entregarVenta') && str_contains($s16Pos, '## 4. Fallo antes de guardar: factura simple')
    && str_contains($s16Pos, '`sin_rango`') && str_contains($s16Pos, '`reclamar_secuencia`') && str_contains($s16Pos, 'compare-and-set')
    && str_contains($s16Pos, 'GET /api/facturas/avisos') && str_contains($s16Pos, '«Reenviar a la DGII»'));
wa_chk('pos-write-ahead.md: ya no es la nota "lo que tiene que respetar"',
    !str_contains($s16Pos, 'tiene que respetar') && !str_contains($s16Pos, 'Hoy `reservarFacturaECF` no escribe'));
wa_chk('pos-write-ahead.md: ERROR y NO_ENCONTRADO conservan envio_pendiente = 0, RFCE_NO_ENCONTRADO es de soporte y las facturas simples del POS no se editan ni eliminan (F4)',
    str_contains($s16Plano($s16Pos), 'conservan `envio_pendiente = 0`') && str_contains($s16Pos, '`RFCE_NO_ENCONTRADO`')
    && !str_contains($s16Pos, 'Punto abierto') && str_contains($s16Pos, 'no se editan ni se eliminan') && str_contains($s16Pos, 'Facturas simples'));
$s16ApiPos = $s16Leer('docs/api/pos.md');
wa_chk('api/pos.md: la venta ya no tiene DGII_RECHAZO, EMISION_FALLIDA ni GUARDADO_FALLIDO en la tabla de errores',
    !str_contains($s16ApiPos, '| `DGII_RECHAZO` |') && !str_contains($s16ApiPos, '| `EMISION_FALLIDA` |') && !str_contains($s16ApiPos, '| `GUARDADO_FALLIDO` |')
    && str_contains($s16ApiPos, '| `VENTA_EN_PROCESO` |') && str_contains($s16ApiPos, '| `VENTA_NO_GUARDADA` |'));
wa_chk('api/pos.md: ENVIO_INCIERTO, sin_rango, el reenvio con compare-and-set y la auditoria de la factura simple',
    str_contains($s16ApiPos, 'estado_dgii: ENVIO_INCIERTO') && str_contains($s16ApiPos, '`sin_rango`')
    && str_contains($s16ApiPos, 'compare-and-set') && str_contains($s16ApiPos, 'POS_VENTA_FACTURA_SIMPLE'));
wa_chk('api/pos.md: la bitacora nombra lo que el codigo escribe (POS_VENTA_FALLIDA si, POS_VENTA_SIN_GUARDAR no)',
    str_contains($s16ApiPos, '`POS_VENTA_FALLIDA`') && !str_contains($s16ApiPos, 'POS_VENTA_SIN_GUARDAR')
    && wa_grep(['src'], 'POS_VENTA_SIN_GUARDAR') === []);
wa_chk('api/pos.md: el corredor de pruebas scratch y las suites nuevas',
    str_contains($s16ApiPos, 'tools/test_pos_scratch.php') && str_contains($s16ApiPos, 'tools/test_pos_write_ahead.php')
    && str_contains($s16ApiPos, 'tools/test_pos_cobrar_wa.php') && str_contains($s16ApiPos, 'tools/test_pos_continuidad.php')
    && str_contains($s16ApiPos, 'tools/test_pos_reenvio.php'));
$s16SpecPos = $s16Leer('docs/specs/pos.md');
wa_chk('specs/pos.md: decisiones 38 y 39, F6 sin "rechazada -> no hay recibo" y la entrega idempotente en 9.5',
    str_contains($s16SpecPos, '| 38 | La venta no se detiene |') && str_contains($s16SpecPos, '| 39 | Secuencia agotada |')
    && !str_contains($s16SpecPos, 'Respuesta **rechazada** → no hay recibo') && str_contains($s16SpecPos, 'PosVenta::entregarVenta'));
wa_chk('specs/pos.md: el POS no promete ninguna alerta de rechazo (D-C): el rechazo se ve solo en app.*',
    !str_contains($s16Plano($s16SpecPos), 'se muestra una alerta persistente en el POS') && !str_contains($s16SpecPos, 'franja roja hasta'));
$s16Dgii = $s16Leer('docs/integrations/dgii-ecf.md');
wa_chk('dgii-ecf.md: la app nunca reenvia una factura sin confirmar y si reemite una rechazada',
    str_contains($s16Dgii, 'nunca reenvía una factura sin confirmar') && str_contains($s16Dgii, '### Reenviar a la DGII una factura rechazada')
    && str_contains($s16Dgii, 'tomarLockReenvio') && str_contains($s16Dgii, 'devolverSaldoDeFactura'));
$s16Fact = $s16Leer('docs/api/facturas.md');
wa_chk('facturas.md: GET /avisos y POST /reenviar documentados, con el 409 de las facturas sin confirmar',
    str_contains($s16Fact, '### Avisos del e-CF — `GET /api/facturas/avisos`')
    && str_contains($s16Fact, '### Reenviar a la DGII — `POST /api/facturas/{id}/reenviar`') && str_contains($s16Fact, 'incluida toda factura sin confirmar'));
wa_chk('facturas.md: ya no dice que /reenviar responde 501',
    !str_contains($s16Fact, 'sigue respondiendo `501`') && !str_contains($s16Fact, 'responde `501`'));
wa_chk('facturas.md: un rango nuevo puede cubrir los numeros que el POS uso fuera de rango',
    !str_contains($s16Plano($s16Fact), 'debe ser **mayor** que todo número ya usado/autorizado')
    && str_contains($s16Plano($s16Fact), 'Los números que una venta del POS usó fuera de rango no cuentan como tope'));
foreach (['RFCE_PENDIENTE', 'ENVIO_PENDIENTE', 'tolerar_fallo_envio'] as $s16Viejo) {
    wa_chk("nada en src ni docs nombra {$s16Viejo}", wa_grep(['src', 'docs'], $s16Viejo) === [], wa_grep(['src', 'docs'], $s16Viejo));
}
$s16Claude = $s16Leer('CLAUDE.md');
wa_chk('CLAUDE.md no afirma nada falso sobre el reenvio (sin 501 ni "no se reenv")',
    !str_contains($s16Claude, '501') && !str_contains($s16Claude, 'no se reenv'));
wa_chk('CLAUDE.md: la compuerta del write-ahead es 027, 028 y 030, en todo tenant',
    str_contains($s16Plano($s16Claude), '027 (`uk_e_ncf_amb`), 028 (`estado_dgii` VARCHAR(40)) and 030 (')
    && str_contains($s16Plano($s16Claude), 'all three must say `APLICADA`') && !str_contains($s16Plano($s16Claude), 'both must say'));
$s16Runbook = $s16Plano($s16Leer('docs/integrations/alta-tenant-runbook.md'));
wa_chk('runbook: la 030 es obligatoria en todas las DBs de tenant y parte de la compuerta del write-ahead',
    str_contains($s16Runbook, 'La 030 es **obligatoria en todas las DBs de tenant**')
    && str_contains($s16Runbook, 'La 027, la 028 y la 030 son además la compuerta del write-ahead del e-CF')
    && !str_contains($s16Runbook, 'y es obligatoria donde sí se use'));

// ---------------------------------------------------------------------------
// 17) Final fix wave (revision final del plan POS y reenvio). Cada hallazgo agrega
// aqui lo que se puede comprobar sin base: F4 (facturas simples del POS).
// ---------------------------------------------------------------------------
echo "\n== 17) Final fix wave ==\n";

// F4: una factura simple con pos_idempotency_key no se edita ni se elimina. Conexion falsa que
// devuelve la fila configurada y anota cada sentencia: ni el UPDATE ni los DELETE se preparan.
final class WaS17Sentencia extends PDOStatement
{
    public function __construct(private ?array $fila)
    {
    }

    public function execute(?array $params = null): bool
    {
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return $this->fila ?? false;
    }
}

final class WaS17Conexion extends PDO
{
    public array $sentencias = [];

    public function __construct(private ?array $fila)
    {
        // Sin parent::__construct: nunca abre una conexion.
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->sentencias[] = preg_replace('/\s+/', ' ', $query);
        return new WaS17Sentencia($this->fila);
    }

    public function beginTransaction(): bool
    {
        $this->sentencias[] = 'BEGIN';
        return true;
    }

    public function commit(): bool
    {
        return true;
    }

    public function inTransaction(): bool
    {
        return false;
    }
}

$s17Modelo = static function (?array $fila): array {
    $ref = new ReflectionClass(facturaModel::class);
    $m = $ref->newInstanceWithoutConstructor();
    $con = new WaS17Conexion($fila);
    $ref->getProperty('conexion')->setValue($m, $con);
    return [$m, $con];
};
$s17Pos = ['tipo_ecf' => null, 'no_factura' => '0091-131026', 'date' => '2026-10-13 10:00:00', 'client_id' => null, 'client_name' => 'Consumidor Final',
    'NCF' => null, 'tipo_pago' => 1, 'dias_credito' => null, 'total' => '118.00', 'pos_idempotency_key' => '00000000-0000-4000-8000-000000000091'];
$s17Normal = ['pos_idempotency_key' => null] + $s17Pos;
$s17Error = [facturaModel::MSG_SIMPLE_VENTA_POS, facturaModel::ERROR_VENTA_POS];
[$s17M, $s17Con] = $s17Modelo($s17Pos);
$s17R = $s17M->updateFacturaSimple(5, ['client_name' => 'Otro']);
wa_chk('17 F4: updateFacturaSimple rechaza una venta del POS (con la clave) y no prepara ninguna escritura',
    $s17R === ['error', ...$s17Error] && count($s17Con->sentencias) === 1 && !preg_grep('/^(UPDATE|DELETE|BEGIN)/', $s17Con->sentencias), [$s17R, $s17Con->sentencias]);
[$s17M, $s17Con] = $s17Modelo($s17Pos);
$s17R = $s17M->deleteFacturaSimple(5);
wa_chk('17 F4: deleteFacturaSimple rechaza una venta del POS y no prepara ninguna escritura',
    $s17R === ['error', ...$s17Error] && count($s17Con->sentencias) === 1 && !preg_grep('/^(UPDATE|DELETE|BEGIN)/', $s17Con->sentencias), [$s17R, $s17Con->sentencias]);
[$s17M, $s17Con] = $s17Modelo($s17Normal);
$s17M->deleteFacturaSimple(5);
wa_chk('17 F4: una factura simple normal (sin clave del POS) si llega a borrarse', (bool) preg_grep('/^DELETE FROM facturas/', $s17Con->sentencias), $s17Con->sentencias);
wa_chk('17 F4: el mensaje es el de la spec y el controlador lo responde 409',
    facturaModel::MSG_SIMPLE_VENTA_POS === 'Esta factura viene de una venta del POS y no se puede editar ni eliminar.'
    && preg_match('/facturaModel::ERROR_VENTA_POS\s*=>\s*409/', (string) file_get_contents(__DIR__ . '/../src/Controllers/facturaSimpleController.php')) === 1);

// F3 y F9: el reenvio del POS (PosVenta::reenviarPendientes). El comportamiento lo prueba tools/test_pos_reenvio.php
// (P3, P11, P12, P13); aqui, lo que se puede fijar sin base.
$s17Pv = str_replace("\r\n", "\n", (string) file_get_contents(__DIR__ . '/../src/Pos/PosVenta.php'));
$s17Es = str_replace("\r\n", "\n", (string) file_get_contents(__DIR__ . '/../src/Utils/FacturacionElectronica/ECFEmissionService.php'));
wa_chk('17 F3a: la espera de REENVIO_ESPERA_SEG cubre a PENDIENTE_ENVIO y a ENVIO_INCIERTO (EstadosEcf::EN_VUELO)',
    str_contains($s17Pv, "in_array((string) \$v['estado_dgii'], EstadosEcf::EN_VUELO, true)")
    && !str_contains($s17Pv, "if ((string) \$v['estado_dgii'] === EstadosEcf::PENDIENTE_ENVIO) {"));
wa_chk('17 F3c: el rechazo de un reenvio con la secuencia consumida no es un veredicto (RFCE y e-CF)',
    str_contains($s17Pv, 'private static function esRechazoDeDuplicado(string $estado, $respuesta): bool')
    && substr_count($s17Pv, 'self::esRechazoDeDuplicado(') === 2);
wa_chk('17 F9: PosVenta pasa POS_DGII_TIMEOUT al token, las consultas y el envio (5 llamadas)',
    substr_count($s17Pv, 'self::timeoutDgii()') >= 5
    && str_contains($s17Pv, "\$servicio->consultarTrackIds((string) \$v['e_ncf'], \$ambiente, self::timeoutDgii())")
    && str_contains($s17Pv, "\$servicio->consultarEstado(\$trackId, (string) \$v['e_ncf'], \$ambiente, null, self::timeoutDgii())")
    && str_contains($s17Pv, "\$v['ambiente_dgii'] ?? null, null, self::timeoutDgii())"));
$s17Params = static fn(string $metodo): array => array_map(static fn(ReflectionParameter $p): string => $p->getName(),
    (new ReflectionMethod(ECFEmissionService::class, $metodo))->getParameters());
wa_chk('17 F9: consultarTrackIds, consultarEstado y consultarEstadoRFCE aceptan un timeout al final, sin mover los parametros de siempre',
    $s17Params('consultarTrackIds') === ['eNcf', 'ambiente', 'timeout']
    && $s17Params('consultarEstado') === ['trackId', 'eNcf', 'ambiente', 'rncEmisor', 'timeout']
    && $s17Params('consultarEstadoRFCE') === ['eNcf', 'codigoSeguridad', 'ambiente', 'rncEmisor', 'timeout']);
wa_chk('17 F9: el token de los cinco metodos (reenviarRFCE, reenviarECF, consultarTrackIds, consultarEstado, consultarEstadoRFCE) lleva el timeout',
    substr_count($s17Es, "'certificate_password' => \$cert['password'],\n        ] + self::opcionTimeout(\$timeout));") === 5,
    substr_count($s17Es, "'certificate_password' => \$cert['password'],\n        ] + self::opcionTimeout(\$timeout));"));

// F8: el procedimiento 10.2 no puede devolver al contador el numero de una venta del POS, y la 030 es obligatoria en todo tenant.
$s17Leer = static fn(string $ruta): string => str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__) . '/' . $ruta));
$s17Plano = static fn(string $texto): string => preg_replace('/\s+/', ' ', $texto);
$s17Dgii = $s17Leer('docs/integrations/dgii-ecf.md');
$s17Proc = substr($s17Dgii, (int) strpos($s17Dgii, '### Resolver una factura sin confirmar'), (int) strpos($s17Dgii, '### Reenviar a la DGII una factura rechazada') - (int) strpos($s17Dgii, '### Resolver una factura sin confirmar'));
wa_chk('17 F8: 10.2 abre con las filas del POS: nunca el UPDATE de ncf_sequences, envio_pendiente = 0 en los dos NO_ENCONTRADO y la espera propia del reenvio',
    str_contains($s17Proc, '**Filas del POS (`pos_idempotency_key` no nulo).**') && str_contains($s17Proc, '**Nunca corras el `UPDATE` de `ncf_sequences`**')
    && str_contains($s17Plano($s17Proc), 'menos de 2 minutos desde su reserva')
    && strpos($s17Proc, 'Filas del POS') < strpos($s17Proc, '1. **Listar**'), strpos($s17Proc, 'Filas del POS'));
wa_chk('17 F8: los renglones "La DGII no lo tiene" y RFCE_NO_ENCONTRADO de una fila del POS llevan envio_pendiente = 0 y ningun UPDATE de ncf_sequences',
    substr_count($s17Proc, "estado_dgii = 'NO_ENCONTRADO', envio_pendiente = 0") === 1
    && substr_count($s17Proc, "estado_dgii = 'RFCE_NO_ENCONTRADO', envio_pendiente = 0") === 1
    && str_contains($s17Proc, 'no hay `UPDATE` de `ncf_sequences`') && str_contains($s17Proc, 'no hay devolución de `ncf_sequences`'));
wa_chk('17 F8: 10.2 lista pos_idempotency_key y envio_pendiente, deja el trackId y el RFCE con envio_pendiente en 1 y no resuelve las sin_rango',
    str_contains($s17Proc, 'pos_idempotency_key, envio_pendiente') && str_contains($s17Plano($s17Proc), 'En los casos del `trackId` y del RFCE el `UPDATE` **no** toca `envio_pendiente`')
    && str_contains($s17Plano($s17Proc), '`"sin_rango": true`') && str_contains($s17Plano($s17Proc), 'no se resuelve con este procedimiento'));
$s17Cot = $s17Plano($s17Leer('docs/modules/cotizaciones-formatos.md'));
wa_chk('17 F8: el despliegue de los conduces dice que la 030 es obligatoria en todas las DBs de tenant (compuerta 027, 028 y 030)',
    str_contains($s17Cot, 'La 030 es obligatoria en todas las DBs de tenant, se use o no el POS') && str_contains($s17Cot, 'La 027, la 028 y la 030 son la compuerta del despliegue')
    && !str_contains($s17Cot, 'Es inofensiva donde no se usa el POS') && !str_contains($s17Cot, 'solo en las empresas que vayan a usar el POS'));
$s17Mig = $s17Plano((string) preg_replace('/^--\s?/m', '', $s17Leer('db/migrations/030_pos.sql')));
wa_chk('17 F8: la cabecera de la 030 ya no dice que no estorba ni que sea solo para quien use el POS: es OBLIGATORIA en todas',
    str_contains($s17Mig, 'correr en la base de CADA empresa, use o no el POS. Es OBLIGATORIA en todas') && !str_contains($s17Mig, 'no estorba en las demas'));

// ---------------------------------------------------------------------------
// 18) Re-correr el set de pruebas DGII en certecf (NUEVAS IDEAS NOTICIAS RD,
// 2026-10-26): un rechazo reinicio el set y los 22 originales estaban ACEPTADO o
// RFCE_ACEPTADO y las 3 notas ENVIADO, asi que reenviar el mismo xlsx salia
// "numero ocupado" en las 25. Con set_pruebas y ambiente certecf la reserva
// archiva la fila previa certecf en cualquier estado fuera de vuelo.
// ---------------------------------------------------------------------------
echo "\n== 18) Set de pruebas re-corrido en certecf ==\n";

$s18Set = ['set_pruebas' => true];
$s18Cert = ['ambiente' => 'certecf', 'ambiente_secuencia' => 'certecf', 'e_ncf' => 'E310000000034', 'dispensado' => false,
    'rango_id' => null, 'secuencia_valor' => null];
$s18Previa = static fn(string $estado, ?string $ambiente = 'certecf'): array => [['id' => '2', 'ambiente_dgii' => $ambiente, 'estado_dgii' => $estado]];

$c = new GuionConexion();
[, $e] = $reservar($c, $s18Cert, $s18Set);
$select = (string) $c->sql('estado_dgii FROM facturas');
wa_chk('18: el SELECT lee tambien ambiente_dgii, con el mismo FOR UPDATE y predicado',
    str_contains($select, 'SELECT id, ambiente_dgii, estado_dgii FROM facturas') && str_contains($select, 'FOR UPDATE')
    && str_contains($select, '(ambiente_dgii = :amb OR ambiente_dgii IS NULL)') && $e === null, $select);

foreach (['ACEPTADO', 'ACEPTADO_CONDICIONAL', 'RFCE_ACEPTADO', 'ENVIADO', 'EN_PROCESO', 'RFCE_ENVIADO', 'ERROR', 'RFCE_ERROR'] as $s18Estado) {
    $c = new GuionConexion();
    $c->filas['FOR UPDATE'] = $s18Previa($s18Estado);
    [$r, $e, $log] = $reservar($c, $s18Cert, $s18Set);
    wa_chk("18: set + certecf, previa {$s18Estado}: se archiva (compare-and-set con ese estado) y se reserva",
        $e === null && ($r['factura_id'] ?? null) === 41 && $c->params('_ARCHIVADO') === [':id' => 2, ':visto' => $s18Estado]
        && ($c->params('INSERT INTO facturas')[':e_ncf'] ?? null) === 'E310000000034'
        && count($c->de('beginTransaction')) === 1 && count($c->de('commit')) === 1 && !$c->inTransaction(),
        $e?->getMessage() ?? $c->llamadas);
    wa_chk("18: previa {$s18Estado}: una linea de log que la nombra",
        substr_count($log, "[ECF e-NCF=E310000000034] reservar: set de pruebas re-corrido, se archiva la fila 2 en {$s18Estado}") === 1, $log);
}
$archivo = (string) $c->sql('_ARCHIVADO');
wa_chk("18: el archivo es el UPDATE de siempre (CONCAT '_ARCHIVADO', e_ncf NULL, WHERE id y estado leido)",
    str_contains($archivo, "SET e_ncf = NULL, estado_dgii = CONCAT(estado_dgii, '_ARCHIVADO')")
    && str_contains($archivo, 'WHERE id = :id AND estado_dgii = :visto'), $archivo);

$c = new GuionConexion();
$c->filas['FOR UPDATE'] = $s18Previa('RECHAZADO');
[, $e, $log] = $reservar($c, $s18Cert, $s18Set);
wa_chk('18: previa RECHAZADO en el set: se archiva como reintentable, sin la linea del set',
    $e === null && $c->params('_ARCHIVADO') === [':id' => 2, ':visto' => 'RECHAZADO'] && !str_contains($log, 'set de pruebas'), $log);

foreach ([EstadosEcf::PENDIENTE_ENVIO, EstadosEcf::ENVIO_INCIERTO] as $s18Estado) {
    $c = new GuionConexion();
    $c->filas['FOR UPDATE'] = $s18Previa($s18Estado);
    [, $e] = $reservar($c, $s18Cert, $s18Set);
    wa_chk("18: previa en vuelo ({$s18Estado}) bloquea aun en el set: ocupado, sin UPDATE ni INSERT",
        $e instanceof EcfNumeroOcupadoException && str_contains($e->getMessage(), "fila 2 en {$s18Estado}")
        && $c->sql('UPDATE facturas') === null && $c->sql('INSERT INTO') === null && $c->de('commit') === [] && !$c->inTransaction(),
        $e?->getMessage());
}

$c = new GuionConexion();
$c->filas['FOR UPDATE'] = $s18Previa('ACEPTADO', null);
[, $e] = $reservar($c, $s18Cert, $s18Set);
wa_chk('18: previa sin ambiente (NULL, puede ser de produccion) bloquea aun en el set',
    $e instanceof EcfNumeroOcupadoException && $c->sql('UPDATE facturas') === null && $c->sql('INSERT INTO') === null, $e?->getMessage());

$c = new GuionConexion();
$c->filas['FOR UPDATE'] = $s18Previa('');
[, $e] = $reservar($c, $s18Cert, $s18Set);
wa_chk('18: previa sin estado bloquea (CONCAT con NULL no archivaria nada)',
    $e instanceof EcfNumeroOcupadoException && $c->sql('UPDATE facturas') === null, $e?->getMessage());

$c = new GuionConexion();
$c->filas['FOR UPDATE'] = $s18Previa('ACEPTADO');
[, $e] = $reservar($c, $s18Cert);
wa_chk('18: sin set_pruebas (emision normal en certecf), previa ACEPTADO: ocupado como siempre',
    $e instanceof EcfNumeroOcupadoException && str_contains($e->getMessage(), 'fila 2 en ACEPTADO') && $c->sql('UPDATE facturas') === null,
    $e?->getMessage());

foreach (['ecf', 'testecf'] as $s18Amb) {
    $c = new GuionConexion();
    $c->filas['FOR UPDATE'] = $s18Previa('ACEPTADO', $s18Amb);
    [, $e] = $reservar($c, ['ambiente' => $s18Amb, 'ambiente_secuencia' => $s18Amb] + $s18Cert, $s18Set);
    wa_chk("18: set_pruebas en {$s18Amb}: nunca archiva un ACEPTADO (ocupado, sin UPDATE)",
        $e instanceof EcfNumeroOcupadoException && $c->sql('UPDATE facturas') === null && $c->sql('INSERT INTO') === null,
        $e?->getMessage());
}

foreach (['1', 1, 'si'] as $s18Flag) {
    $c = new GuionConexion();
    $c->filas['FOR UPDATE'] = $s18Previa('ACEPTADO');
    [, $e] = $reservar($c, $s18Cert, ['set_pruebas' => $s18Flag]);
    wa_chk('18: set_pruebas tiene que ser true (bool): ' . var_export($s18Flag, true) . ' no archiva',
        $e instanceof EcfNumeroOcupadoException && $c->sql('UPDATE facturas') === null, $e?->getMessage());
}

$c = new GuionConexion();
$c->filas['FOR UPDATE'] = $s18Previa('RFCE_ACEPTADO');
$c->rowCounts['_ARCHIVADO'] = 0;
[, $e] = $reservar($c, $s18Cert, $s18Set);
wa_chk('18: archivo sin coincidencia en el set (otra escritura movio la fila): ocupado, sin INSERT ni commit',
    $e instanceof EcfNumeroOcupadoException && str_contains($e->getMessage(), 'archivo sin coincidencia')
    && $c->sql('INSERT INTO') === null && $c->de('commit') === [] && !$c->inTransaction(), $e?->getMessage());

$c = new GuionConexion();
$c->filas['FOR UPDATE'] = [
    ['id' => '2', 'ambiente_dgii' => 'certecf', 'estado_dgii' => 'ACEPTADO'],
    ['id' => '3', 'ambiente_dgii' => 'certecf', 'estado_dgii' => 'ENVIO_INCIERTO'],
];
[, $e] = $reservar($c, $s18Cert, $s18Set);
wa_chk('18: si una de las previas esta en vuelo, nada queda archivado (rollBack de toda la transaccion)',
    $e instanceof EcfNumeroOcupadoException && str_contains($e->getMessage(), 'fila 3 en ENVIO_INCIERTO')
    && count($c->de('rollBack')) === 1 && $c->de('commit') === [] && $c->sql('INSERT INTO') === null && !$c->inTransaction(),
    $e?->getMessage());

$s18Ctl = (string) file_get_contents(__DIR__ . '/../src/Controllers/facturaController.php');
wa_chk('18: facturaController pasa el strict_input del set como set_pruebas a la reserva',
    str_contains($s18Ctl, "'set_pruebas' => \$strictInput,"));
$s18Pos = (string) file_get_contents(__DIR__ . '/../src/Pos/PosVenta.php');
wa_chk('18: el POS nunca manda set_pruebas', !str_contains($s18Pos, 'set_pruebas'));

// ---------------------------------------------------------------------------
// Cierre. Cada tarea agrega su seccion justo encima de este bloque.
// ---------------------------------------------------------------------------
wa_fin();
