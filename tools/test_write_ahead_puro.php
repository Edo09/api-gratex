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
// Cierre. Cada tarea agrega su seccion justo encima de este bloque.
// ---------------------------------------------------------------------------
wa_fin();
