<?php
/**
 * test_notas_vinculadas.php — Notas de credito/debito pegadas a la factura que
 * modifican, sin base de datos.
 *
 * Origen: E440000000001 quedo anulada por la nota de credito E340000000001 y
 * nada en pantalla lo decia. GET /api/facturas (listado y detalle) trae ahora
 * en cada fila:
 *   - "notas":    las E33/E34 que modifican esa fila (misma regla que
 *                 facturaModel::sqlReferencia: mismo ambiente con <=>, solo
 *                 aceptadas o en proceso), por id ascendente.
 *   - "modifica": en una E33/E34, el comprobante que modifica (id null si no
 *                 esta en facturas, p.ej. un NCF de papel).
 *
 * El emparejamiento vive en facturaModel::vincularNotas (pura, se prueba con
 * filas de ejemplo). adjuntarNotasVinculadas hace las dos consultas por pagina
 * y se prueba con una conexion falsa que anota el SQL: nunca toca MySQL.
 *
 * Uso:
 *   php tools/test_notas_vinculadas.php      (sale con 1 si algo falla)
 */

require_once __DIR__ . '/../src/Models/facturaModel.php';

$fallos = 0;
$total = 0;
$chk = function (string $desc, bool $ok, $obtenido = null) use (&$fallos, &$total) {
    $total++;
    if (!$ok) {
        $fallos++;
    }
    printf("  [%s] %s\n", $ok ? 'OK  ' : 'FALLO', $desc);
    if (!$ok && $obtenido !== null) {
        echo '         obtenido: ' . json_encode($obtenido, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }
};

if (!method_exists('facturaModel', 'vincularNotas') || !method_exists('facturaModel', 'adjuntarNotasVinculadas')) {
    echo "  [FALLO] facturaModel no tiene vincularNotas / adjuntarNotasVinculadas\n";
    echo "\n0 de 1 pruebas OK\n";
    exit(1);
}

// Filas como las devuelve PDO en esta app: INT y DECIMAL llegan como texto.
$fila = static function (array $f): array {
    return $f + [
        'id' => null, 'e_ncf' => null, 'tipo_ecf' => null, 'ncf_modificado' => null,
        'ambiente_dgii' => 'ecf', 'total' => '0.00', 'date' => null, 'client_name' => 'Cliente',
    ];
};
// Fila de la consulta de notas (adjuntarNotasVinculadas la trae con estas columnas).
$nota = static function (array $n): array {
    return $n + [
        'tipo_ecf' => '34', 'codigo_modificacion' => '3', 'total' => '100.00',
        'estado_dgii' => 'ACEPTADO', 'date' => '2026-10-05 10:00:00', 'ambiente_dgii' => 'ecf',
    ];
};
$porId = static function (array $filas): array {
    $r = [];
    foreach ($filas as $f) {
        $r[(int) $f['id']] = $f;
    }
    return $r;
};
$ids = static fn(array $notas): array => array_map(fn($n) => $n['id'], $notas);

// ---------------------------------------------------------------------------
// 1) Original con una nota de credito que la anula (el caso de E440000000001)
// ---------------------------------------------------------------------------
$e44 = $fila(['id' => '10', 'e_ncf' => 'E440000000001', 'tipo_ecf' => '44', 'total' => '82200.00']);
$e34 = $nota(['id' => '12', 'e_ncf' => 'E340000000001', 'ncf_modificado' => 'E440000000001',
    'codigo_modificacion' => '1', 'total' => '82200.00', 'date' => '2026-10-05 10:10:38']);
$r = facturaModel::vincularNotas([$e44], [$e34], []);
$chk('devuelve una fila por fila', count($r) === 1, $r);
$chk('original: notas trae la nota de credito con la forma del contrato',
    ($r[0]['notas'] ?? null) === [[
        'id' => 12, 'e_ncf' => 'E340000000001', 'tipo_ecf' => '34', 'codigo_modificacion' => '1',
        'total' => '82200.00', 'estado_dgii' => 'ACEPTADO', 'date' => '2026-10-05 10:10:38',
    ]], $r[0]['notas'] ?? null);
$chk('original: modifica null (no es nota)', array_key_exists('modifica', $r[0]) && $r[0]['modifica'] === null, $r[0]);
$chk('original: el resto de la fila queda igual',
    $r[0]['e_ncf'] === 'E440000000001' && $r[0]['total'] === '82200.00' && $r[0]['client_name'] === 'Cliente', $r[0]);

// ---------------------------------------------------------------------------
// 2) Dos notas, ordenadas por id aunque lleguen al reves
// ---------------------------------------------------------------------------
$e31 = $fila(['id' => '20', 'e_ncf' => 'E310000000020', 'tipo_ecf' => '31']);
$debito = $nota(['id' => '30', 'e_ncf' => 'E330000000002', 'tipo_ecf' => '33', 'ncf_modificado' => 'E310000000020']);
$credito = $nota(['id' => '25', 'e_ncf' => 'E340000000005', 'ncf_modificado' => 'E310000000020', 'codigo_modificacion' => null]);
$r = facturaModel::vincularNotas([$e31], [$debito, $credito], []);
$chk('dos notas, por id ascendente', $ids($r[0]['notas'] ?? []) === [25, 30], $r[0]['notas'] ?? null);
$chk('nota sin codigo_modificacion lo lleva como null',
    array_key_exists('codigo_modificacion', $r[0]['notas'][0] ?? []) && $r[0]['notas'][0]['codigo_modificacion'] === null,
    $r[0]['notas'][0] ?? null);
$chk('la nota de debito sale con tipo 33', ($r[0]['notas'][1]['tipo_ecf'] ?? null) === '33', $r[0]['notas'] ?? null);

// ---------------------------------------------------------------------------
// 3) Ambiente: otra base de numeracion, no se mezcla (<=> de sqlReferencia)
// ---------------------------------------------------------------------------
$enCert = $nota(['id' => '40', 'e_ncf' => 'E340000000009', 'ncf_modificado' => 'E310000000020', 'ambiente_dgii' => 'certecf']);
$r = facturaModel::vincularNotas([$e31], [$enCert], []);
$chk('nota de otro ambiente no se pega', ($r[0]['notas'] ?? null) === [], $r[0]['notas'] ?? null);

$origNull = $fila(['id' => '50', 'e_ncf' => 'E310000000050', 'tipo_ecf' => '31', 'ambiente_dgii' => null]);
$notaNull = $nota(['id' => '51', 'e_ncf' => 'E340000000051', 'ncf_modificado' => 'E310000000050', 'ambiente_dgii' => null]);
$notaEcf = $nota(['id' => '52', 'e_ncf' => 'E340000000052', 'ncf_modificado' => 'E310000000050', 'ambiente_dgii' => 'ecf']);
$r = facturaModel::vincularNotas([$origNull], [$notaNull, $notaEcf], []);
$chk('ambiente NULL en los dos lados empareja; NULL contra ecf no', $ids($r[0]['notas'] ?? []) === [51], $r[0]['notas'] ?? null);

// ---------------------------------------------------------------------------
// 4) Misma regla de estado que sqlReferencia: rechazadas y archivadas no cuentan
// ---------------------------------------------------------------------------
$estados = [
    'ACEPTADO' => true, 'ACEPTADO_CONDICIONAL' => true, 'EN_PROCESO' => true, 'ENVIADO' => true,
    'RECHAZADO' => false, 'ERROR' => false, 'NO_ENCONTRADO' => false, 'PENDIENTE' => false,
    'RECHAZADO_ARCHIVADO' => false,
];
foreach ($estados as $estado => $cuenta) {
    $n = $nota(['id' => '60', 'e_ncf' => 'E340000000060', 'ncf_modificado' => 'E310000000020', 'estado_dgii' => $estado]);
    $r = facturaModel::vincularNotas([$e31], [$n], []);
    $chk("nota en {$estado} " . ($cuenta ? 'cuenta' : 'no cuenta'), count($r[0]['notas'] ?? []) === ($cuenta ? 1 : 0), $r[0]['notas'] ?? null);
}
$noNota = $nota(['id' => '61', 'e_ncf' => 'E310000000061', 'tipo_ecf' => '31', 'ncf_modificado' => 'E310000000020']);
$r = facturaModel::vincularNotas([$e31], [$noNota], []);
$chk('un tipo que no es nota (31) con ncf_modificado no cuenta', ($r[0]['notas'] ?? null) === [], $r[0]['notas'] ?? null);
$chk('la regla de estado es la de sqlReferencia',
    defined('facturaModel::ESTADOS_NOTA_VIGENTE')
    && facturaModel::ESTADOS_NOTA_VIGENTE === ['ACEPTADO', 'ACEPTADO_CONDICIONAL', 'EN_PROCESO', 'ENVIADO']);

// ---------------------------------------------------------------------------
// 5) La nota misma: "modifica" apunta al original
// ---------------------------------------------------------------------------
$filaNota = $fila(['id' => '12', 'e_ncf' => 'E340000000001', 'tipo_ecf' => '34', 'ncf_modificado' => 'E440000000001',
    'total' => '82200.00']);
$original = ['id' => '10', 'e_ncf' => 'E440000000001', 'tipo_ecf' => '44', 'total' => '82200.00',
    'date' => '2026-10-01 09:00:00', 'ambiente_dgii' => 'ecf'];
$r = facturaModel::vincularNotas([$filaNota], [], [$original]);
$chk('nota: modifica trae el id y los datos del original',
    ($r[0]['modifica'] ?? null) === [
        'id' => 10, 'e_ncf' => 'E440000000001', 'tipo_ecf' => '44', 'total' => '82200.00', 'date' => '2026-10-01 09:00:00',
    ], $r[0]['modifica'] ?? null);
$chk('nota sin notas propias: notas []', ($r[0]['notas'] ?? null) === [], $r[0]['notas'] ?? null);

$origOtroAmb = ['id' => '11', 'e_ncf' => 'E440000000001', 'tipo_ecf' => '44', 'total' => '5.00',
    'date' => '2026-01-01 00:00:00', 'ambiente_dgii' => 'certecf'];
$r = facturaModel::vincularNotas([$filaNota], [], [$origOtroAmb, $original]);
$chk('nota: el original se busca en su mismo ambiente', ($r[0]['modifica']['id'] ?? null) === 10, $r[0]['modifica'] ?? null);

$r = facturaModel::vincularNotas([$filaNota], [], [$origOtroAmb]);
$chk('nota: original solo en otro ambiente -> id null',
    ($r[0]['modifica'] ?? null) === ['id' => null, 'e_ncf' => 'E440000000001', 'tipo_ecf' => null, 'total' => null, 'date' => null],
    $r[0]['modifica'] ?? null);

// ---------------------------------------------------------------------------
// 6) Nota sobre un comprobante que no esta en facturas (NCF de papel)
// ---------------------------------------------------------------------------
$notaPapel = $fila(['id' => '70', 'e_ncf' => 'E340000000070', 'tipo_ecf' => '34', 'ncf_modificado' => 'B0100000123']);
$r = facturaModel::vincularNotas([$notaPapel], [], [$original]);
$chk('nota sobre un NCF que no esta: modifica con id null',
    ($r[0]['modifica'] ?? null) === ['id' => null, 'e_ncf' => 'B0100000123', 'tipo_ecf' => null, 'total' => null, 'date' => null],
    $r[0]['modifica'] ?? null);

// ---------------------------------------------------------------------------
// 7) Una nota que a su vez tiene nota: trae las dos cosas
// ---------------------------------------------------------------------------
$debitoDeNota = $nota(['id' => '80', 'e_ncf' => 'E330000000080', 'tipo_ecf' => '33', 'ncf_modificado' => 'E340000000001']);
$r = facturaModel::vincularNotas([$filaNota], [$debitoDeNota], [$original]);
$chk('nota modificada: trae modifica y notas',
    ($r[0]['modifica']['id'] ?? null) === 10 && $ids($r[0]['notas'] ?? []) === [80], $r[0]);

// ---------------------------------------------------------------------------
// 8) Filas sin e-NCF y filas que no son notas
// ---------------------------------------------------------------------------
$archivada = $fila(['id' => '90', 'e_ncf' => null, 'tipo_ecf' => '31']);
$notaHuerfana = $nota(['id' => '91', 'e_ncf' => 'E340000000091', 'ncf_modificado' => '']);
$r = facturaModel::vincularNotas([$archivada], [$notaHuerfana], []);
$chk('fila con e_ncf NULL: notas []', ($r[0]['notas'] ?? null) === [], $r[0]);
$chk('fila con e_ncf NULL: modifica null', array_key_exists('modifica', $r[0]) && $r[0]['modifica'] === null, $r[0]);

$e31ConRef = $fila(['id' => '92', 'e_ncf' => 'E310000000092', 'tipo_ecf' => '31', 'ncf_modificado' => 'E310000000020']);
$notaSinRef = $fila(['id' => '93', 'e_ncf' => 'E340000000093', 'tipo_ecf' => '34', 'ncf_modificado' => '']);
$r = $porId(facturaModel::vincularNotas([$e31, $e31ConRef, $notaSinRef], [], [$e31]));
$chk('factura (31) sin nota: modifica null', array_key_exists('modifica', $r[20] ?? []) && $r[20]['modifica'] === null, $r[20] ?? null);
$chk('tipo 31 con ncf_modificado no es nota: modifica null',
    array_key_exists('modifica', $r[92] ?? []) && $r[92]['modifica'] === null, $r[92] ?? null);
$chk('nota con ncf_modificado vacio: modifica null',
    array_key_exists('modifica', $r[93] ?? []) && $r[93]['modifica'] === null, $r[93] ?? null);
$chk('todas las filas traen notas como lista',
    ($r[20]['notas'] ?? null) === [] && ($r[92]['notas'] ?? null) === [] && ($r[93]['notas'] ?? null) === []);

$chk('sin filas -> []', facturaModel::vincularNotas([], [$e34], [$original]) === []);

// e-NCF en minusculas (la collation de MySQL no distingue mayusculas): empareja igual.
$notaMin = $nota(['id' => '95', 'e_ncf' => 'E340000000095', 'ncf_modificado' => 'e440000000001']);
$r = facturaModel::vincularNotas([$e44], [$notaMin], []);
$chk('ncf_modificado en minusculas empareja (como la collation)', count($r[0]['notas'] ?? []) === 1, $r[0]['notas'] ?? null);

// ---------------------------------------------------------------------------
// 9) adjuntarNotasVinculadas: dos consultas por pagina, nunca una por fila
// ---------------------------------------------------------------------------
final class SentenciaFalsa
{
    public function __construct(private ConexionFalsa $c, private string $sql, private array $filas)
    {
    }

    public function execute(?array $params = null): bool
    {
        $this->c->consultas[] = ['sql' => $this->sql, 'params' => $params ?? []];
        return true;
    }

    public function fetchAll(int $modo = PDO::FETCH_BOTH): array
    {
        return $this->filas;
    }
}

final class ConexionFalsa
{
    public array $consultas = [];

    public function __construct(private array $notas, private array $originales)
    {
    }

    public function prepare(string $sql): SentenciaFalsa
    {
        $filas = str_contains($sql, 'ncf_modificado IN') ? $this->notas : $this->originales;
        return new SentenciaFalsa($this, $sql, $filas);
    }
}

$conModelo = static function ($conexion): facturaModel {
    $ref = new ReflectionClass(facturaModel::class);
    $m = $ref->newInstanceWithoutConstructor();
    $ref->getProperty('conexion')->setValue($m, $conexion);
    return $m;
};

$c = new ConexionFalsa([$e34, $debito], [$original]);
$m = $conModelo($c);
$pagina = [
    $e44,                                    // original con nota de credito
    $e31,                                    // original con nota de debito
    $filaNota,                               // nota -> modifica E440000000001
    $archivada,                              // sin e_ncf
    $fila(['id' => '13', 'e_ncf' => 'E340000000013', 'tipo_ecf' => '34', 'ncf_modificado' => 'E440000000001']),
];
$r = $porId($m->adjuntarNotasVinculadas($pagina));
$chk('pagina: exactamente dos consultas', count($c->consultas) === 2, array_column($c->consultas, 'sql'));

$qNotas = array_values(array_filter($c->consultas, fn($q) => str_contains($q['sql'], 'ncf_modificado IN')))[0] ?? null;
$qOrig = array_values(array_filter($c->consultas, fn($q) => !str_contains($q['sql'], 'ncf_modificado IN')))[0] ?? null;
$enNotas = $qNotas['params'] ?? [];
sort($enNotas);
$chk('consulta de notas: busca los e-NCF de la pagina, sin repetir ni NULL',
    $enNotas === ['E310000000020', 'E340000000001', 'E340000000013', 'E440000000001'], $enNotas);
$chk('consulta de notas: un ? por e-NCF',
    $qNotas !== null && substr_count($qNotas['sql'], '?') === count($qNotas['params']), $qNotas);
$chk('consulta de notas: filtra tipo 33/34 y los estados de sqlReferencia',
    $qNotas !== null && str_contains($qNotas['sql'], "tipo_ecf IN ('33', '34')")
    && str_contains($qNotas['sql'], "estado_dgii IN ('ACEPTADO', 'ACEPTADO_CONDICIONAL', 'EN_PROCESO', 'ENVIADO')"), $qNotas['sql'] ?? null);
$chk('consulta de notas: ordena por id', $qNotas !== null && str_contains($qNotas['sql'], 'ORDER BY id'), $qNotas['sql'] ?? null);
$chk('consulta de originales: un solo e-NCF (repetido en dos notas)',
    ($qOrig['params'] ?? null) === ['E440000000001'], $qOrig);
$chk('consulta de originales: un ? por e-NCF',
    $qOrig !== null && substr_count($qOrig['sql'], '?') === count($qOrig['params']), $qOrig);
$chk('consulta de originales: busca por e_ncf', $qOrig !== null && str_contains($qOrig['sql'], 'e_ncf IN'), $qOrig['sql'] ?? null);

$chk('pagina: el original trae su nota', $ids($r[10]['notas'] ?? []) === [12], $r[10] ?? null);
$chk('pagina: la E31 trae su nota de debito', $ids($r[20]['notas'] ?? []) === [30], $r[20] ?? null);
$chk('pagina: la nota apunta al original', ($r[12]['modifica']['id'] ?? null) === 10, $r[12] ?? null);
$chk('pagina: la fila sin e-NCF trae notas [] y modifica null',
    ($r[90]['notas'] ?? null) === [] && array_key_exists('modifica', $r[90] ?? []) && $r[90]['modifica'] === null, $r[90] ?? null);
$chk('pagina: conserva el orden de las filas', array_keys($r) === [10, 20, 12, 90, 13], array_keys($r));

$c = new ConexionFalsa([], []);
$m = $conModelo($c);
$chk('pagina vacia: [] sin consultar', $m->adjuntarNotasVinculadas([]) === [] && $c->consultas === []);
$r = $m->adjuntarNotasVinculadas([$archivada, $fila(['id' => '1', 'tipo_ecf' => null])]);
$chk('pagina sin e-NCF ni notas: no consulta', $c->consultas === [], $c->consultas);
$chk('pagina sin e-NCF ni notas: igual trae los campos',
    ($r[0]['notas'] ?? null) === [] && array_key_exists('modifica', $r[0]) && $r[0]['modifica'] === null
    && ($r[1]['notas'] ?? null) === [] && array_key_exists('modifica', $r[1]) && $r[1]['modifica'] === null, $r);

$c = new ConexionFalsa([], []);
$m = $conModelo($c);
$m->adjuntarNotasVinculadas([$e44]);
$chk('pagina sin notas: no busca originales (una sola consulta)', count($c->consultas) === 1, array_column($c->consultas, 'sql'));

// Una falla de la base no tumba el listado: las filas salen con los campos vacios.
final class ConexionRota
{
    public function prepare(string $sql)
    {
        throw new PDOException('se cayo la conexion');
    }
}
$roto = $conModelo(new ConexionRota());
$logPrevio = ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');
$r = $roto->adjuntarNotasVinculadas([$e44, $filaNota]);
ini_set('error_log', $logPrevio === false ? '' : $logPrevio);
$chk('si la base falla: filas con notas [] y modifica solo con el e-NCF',
    count($r) === 2 && ($r[0]['notas'] ?? null) === [] && array_key_exists('modifica', $r[0]) && $r[0]['modifica'] === null
    && ($r[1]['modifica'] ?? null) === ['id' => null, 'e_ncf' => 'E440000000001', 'tipo_ecf' => null, 'total' => null, 'date' => null], $r);

// ---------------------------------------------------------------------------
// 10) La misma regla de estado en sqlReferencia (saldo de una nota nueva)
// ---------------------------------------------------------------------------
$sqlRef = (new ReflectionMethod(facturaModel::class, 'sqlReferencia'))->invoke($conModelo(new ConexionRota()));
$chk('sqlReferencia cuenta las notas con los mismos estados',
    str_contains($sqlRef, "estado_dgii IN ('ACEPTADO', 'ACEPTADO_CONDICIONAL', 'EN_PROCESO', 'ENVIADO')"), $sqlRef);

// ---------------------------------------------------------------------------
// 11) /api/facturas/stats: resumen.monto_neto (E34 resta, rechazados fuera)
// La conexion falsa anota el SQL del resumen (la primera consulta) y corta
// con PDOException: getECFStats la atrapa y no llega a ninguna otra.
// ---------------------------------------------------------------------------
final class ConexionStats
{
    public array $consultas = [];

    public function query(string $sql)
    {
        $this->consultas[] = $sql;
        throw new PDOException('corte de la prueba');
    }
}
putenv('MULTI_TENANT_ENABLED=false');
putenv('DGII_ECF_ENVIRONMENT=ecf');
$cs = new ConexionStats();
$conModelo($cs)->getECFStats();
$sqlResumen = preg_replace('/\s+/', ' ', $cs->consultas[0] ?? '');
$chk('stats: el resumen es la primera consulta', str_contains($sqlResumen, 'as total_ecf'), $sqlResumen);
$chk('stats: monto_total sigue siendo la suma cruda', str_contains($sqlResumen, 'COALESCE(SUM(total), 0) as monto_total'), $sqlResumen);
$chk('stats: monto_neto resta la E34 y deja fuera los rechazados',
    str_contains($sqlResumen,
        "COALESCE(SUM(CASE WHEN estado_dgii IS NULL OR estado_dgii NOT LIKE '%RECHAZADO%' "
        . "THEN CASE WHEN tipo_ecf = '34' THEN -total ELSE total END ELSE 0 END), 0) as monto_neto"),
    $sqlResumen);
$chk('stats: monto_neto sobre las mismas filas del resumen (e-CF del ambiente)',
    str_contains($sqlResumen, "WHERE tipo_ecf IS NOT NULL AND ambiente_dgii = 'ecf'"), $sqlResumen);

// ---------------------------------------------------------------------------
// 12) El detalle (GET /api/facturas?id=N) tambien las trae. El controlador es
// un script con salida, asi que se revisa su texto: la rama del detalle pasa
// las filas por adjuntarNotasVinculadas. El listado las trae desde el modelo
// (getFacturasPaginated).
// ---------------------------------------------------------------------------
$controlador = str_replace("\r\n", "\n", (string) file_get_contents(__DIR__ . '/../src/Controllers/facturaController.php'));
$ramaDetalle = preg_match("/if \(isset\(\\\$_GET\['id'\]\)\) \{(.*?)\n            echo json_encode/s", $controlador, $m) ? $m[1] : '';
$chk('controlador: se encontro la rama del detalle', $ramaDetalle !== '');
$chk('controlador: el detalle pasa por adjuntarNotasVinculadas',
    (bool) preg_match('/\$facturas = \$facturaModel->adjuntarNotasVinculadas\(\$facturas\);/', $ramaDetalle), $ramaDetalle);
$modelo = str_replace("\r\n", "\n", (string) file_get_contents(__DIR__ . '/../src/Models/facturaModel.php'));
$paginado = preg_match('/function getFacturasPaginated\((.*?)\n    \}\n/s', $modelo, $m) ? $m[1] : '';
$chk('modelo: el listado paginado pasa por adjuntarNotasVinculadas',
    str_contains($paginado, 'return $this->adjuntarNotasVinculadas($facturas);'));

echo "\n" . ($total - $fallos) . " de {$total} pruebas OK\n";
exit($fallos > 0 ? 1 : 0);
