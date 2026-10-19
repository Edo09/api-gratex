<?php
/**
 * test_inventario_stock.php — Control de existencia en las ventas (facturas y
 * facturas simples), sin base de datos ni red. Spec:
 * fiscalo/docs/superpowers/specs/2026-10-19-control-existencias-ventas-design.md
 *
 * Donde hace falta una conexion se usa una falsa que anota el SQL y los
 * parametros: nunca toca MySQL. Cada tarea del plan agrega su seccion justo
 * encima del bloque de cierre, en orden:
 *   1) El ajuste: columna del master y lector de la bandera (tarea 1).
 *   2) La verificacion: nucleo puro, mensaje y envoltorio con la base (tarea 2).
 *   3) El libro: bloqueo en orden, bandera bloquear_sin_existencia, venta dentro
 *      de la transaccion del documento, motivos nuevos y aviso de sobreventa
 *      (tarea 3).
 *   4) El e-CF: verificarVentaEcf y su cableado en emitirFacturaEcf, antes de
 *      reservar el e-NCF (tarea 4).
 *   5) La factura simple, camino atomico: el callback $enTransaccion de
 *      createFacturaSimple / updateFacturaSimple y su cableado en
 *      facturaSimpleController (tarea 5).
 *   6) El interruptor en Configuracion: GET/PUT /api/inventario/configuracion
 *      (solo admin escribe), el conteo de productos sin existencia y el campo
 *      inventario_estricto de GET /api/branding (tarea 6).
 *   7) El formulario de producto con el interruptor encendido: la existencia
 *      con que se abre un producto entra por el libro (INVENTARIO_INICIAL) y la
 *      edicion ya no escribe stock (tarea 7).
 *
 * Uso:
 *   php tools/test_inventario_stock.php      (sale con 1 si algo falla)
 */

require_once __DIR__ . '/../src/TenantResolver.php';

$ie = ['total' => 0, 'fallos' => 0];

/** Anota una prueba: "[OK  ]" o "[FALLO]" (con lo obtenido), y la cuenta. */
function chk(string $desc, bool $ok, $obtenido = null): void
{
    global $ie;
    $ie['total']++;
    if (!$ok) {
        $ie['fallos']++;
    }
    printf("  [%s] %s\n", $ok ? 'OK  ' : 'FALLO', $desc);
    if (!$ok && $obtenido !== null) {
        echo '         obtenido: ' . json_encode($obtenido, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }
}

/** Texto de un archivo del proyecto con saltos de linea LF (o '' si no existe). */
function ie_fuente(string $rutaRelativa): string
{
    $ruta = __DIR__ . '/../' . $rutaRelativa;
    return is_file($ruta) ? str_replace("\r\n", "\n", (string) file_get_contents($ruta)) : '';
}

// ---------------------------------------------------------------------------
// 1) El ajuste: master.tenants.inventario_estricto y TenantResolver::inventarioEstricto
// ---------------------------------------------------------------------------
echo "\n== 1) El ajuste (master) ==\n";

$ieTenantActual = static function (?array $tenant): void {
    (new ReflectionProperty(TenantResolver::class, 'current'))->setValue(null, $tenant);
};

if (!method_exists(TenantResolver::class, 'inventarioEstricto')) {
    chk('TenantResolver::inventarioEstricto existe', false);
} else {
    $ieTenantActual(null);
    chk('inventarioEstricto sin tenant es false', TenantResolver::inventarioEstricto() === false);

    $ieTenantActual(['id' => 2, 'tipo' => 'app']);
    chk('inventarioEstricto con la columna ausente (master sin migrar) es false', TenantResolver::inventarioEstricto() === false);

    $ieTenantActual(['id' => 2, 'tipo' => 'app', 'inventario_estricto' => 0]);
    chk('inventarioEstricto con valor 0 es false', TenantResolver::inventarioEstricto() === false);

    $ieTenantActual(['id' => 2, 'tipo' => 'app', 'inventario_estricto' => '0']);
    chk("inventarioEstricto con valor '0' (texto, como lo da MySQL) es false", TenantResolver::inventarioEstricto() === false);

    $ieTenantActual(['id' => 2, 'tipo' => 'app', 'inventario_estricto' => null]);
    chk('inventarioEstricto con valor NULL es false', TenantResolver::inventarioEstricto() === false);

    $ieTenantActual(['id' => 2, 'tipo' => 'app', 'inventario_estricto' => 1]);
    chk('inventarioEstricto con valor 1 y tipo app es true', TenantResolver::inventarioEstricto() === true);

    $ieTenantActual(['id' => 2, 'tipo' => 'app', 'inventario_estricto' => '1']);
    chk("inventarioEstricto con valor '1' (texto, como lo da MySQL) y tipo app es true", TenantResolver::inventarioEstricto() === true);

    $ieTenantActual(['id' => 2, 'inventario_estricto' => 1]);
    chk('inventarioEstricto sin tipo se trata como app (igual que isIntegration y PosAuth)', TenantResolver::inventarioEstricto() === true);

    $ieTenantActual(['id' => 9, 'tipo' => 'integracion', 'inventario_estricto' => 1]);
    chk('inventarioEstricto con valor 1 y tipo integracion es false (no tiene catalogo)', TenantResolver::inventarioEstricto() === false);

    $ieTenantActual(null);
}

// MasterDatabase::setInventarioEstricto con una conexion falsa (un PDO que
// nunca conecta) puesta en la propiedad privada conexion.
final class IeSentencia extends PDOStatement
{
    public ?IeConexion $con = null;
    public string $sql = '';

    public function execute(?array $params = null): bool
    {
        $this->con->consultas[] = ['sql' => preg_replace('/\s+/', ' ', $this->sql), 'params' => $params ?? []];
        return true;
    }
}

final class IeConexion extends PDO
{
    public array $consultas = [];

    public function __construct()
    {
        // Sin parent::__construct: nunca abre una conexion.
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $s = new IeSentencia();
        $s->con = $this;
        $s->sql = $query;
        return $s;
    }
}

require_once __DIR__ . '/../src/MasterDatabase.php';

if (!method_exists(MasterDatabase::class, 'setInventarioEstricto')) {
    chk('MasterDatabase::setInventarioEstricto existe', false);
} else {
    $ieMaster = static function (IeConexion $c): MasterDatabase {
        $ref = new ReflectionClass(MasterDatabase::class);
        $m = $ref->newInstanceWithoutConstructor();
        $ref->getProperty('conexion')->setValue($m, $c);
        return $m;
    };
    $esperadoSql = "UPDATE tenants SET inventario_estricto = :v WHERE id = :id AND tipo = 'app'";

    $c = new IeConexion();
    $ieMaster($c)->setInventarioEstricto(7, true);
    chk('setInventarioEstricto(7, true): un UPDATE con :v = 1 y :id = 7, solo tipo app',
        count($c->consultas) === 1
        && $c->consultas[0]['sql'] === $esperadoSql
        && $c->consultas[0]['params'] === [':v' => 1, ':id' => 7], $c->consultas);

    $c = new IeConexion();
    $ieMaster($c)->setInventarioEstricto(7, false);
    chk('setInventarioEstricto(7, false): el mismo UPDATE con :v = 0',
        count($c->consultas) === 1
        && $c->consultas[0]['sql'] === $esperadoSql
        && $c->consultas[0]['params'] === [':v' => 0, ':id' => 7], $c->consultas);
}

// Fuentes: el espejo del esquema, la migracion y la fila del verificador.
$ieEsquema = ie_fuente('db/master_schema.sql');
chk('master_schema.sql: tenants.inventario_estricto TINYINT(1) NOT NULL DEFAULT 0',
    preg_match('/^\s*inventario_estricto\s+TINYINT\(1\)\s+NOT NULL DEFAULT 0\b/m', $ieEsquema) === 1);
$posDe = static function (string $patron) use ($ieEsquema): ?int {
    return preg_match($patron, $ieEsquema, $m, PREG_OFFSET_CAPTURE) === 1 ? $m[0][1] : null;
};
$posPos = $posDe('/^\s*pos_enabled\s+TINYINT/m');
$posIe = $posDe('/^\s*inventario_estricto\s+TINYINT/m');
$posAmbiente = $posDe('/^\s*ambiente\s+VARCHAR/m');
chk('master_schema.sql: inventario_estricto va justo despues de pos_enabled y antes de ambiente',
    $posPos !== null && $posIe !== null && $posAmbiente !== null && $posPos < $posIe && $posIe < $posAmbiente,
    [$posPos, $posIe, $posAmbiente]);

$ieMigracion = ie_fuente('db/master_migrations/013_tenants_inventario_estricto.sql');
chk('migracion 013: el archivo existe', $ieMigracion !== '');
chk('migracion 013: usa @db (SET @db := DATABASE()) y filtra information_schema por esa base',
    str_contains($ieMigracion, 'SET @db := DATABASE();')
    && preg_match('/information_schema\.COLUMNS\s+WHERE TABLE_SCHEMA = @db AND TABLE_NAME = \'tenants\' AND COLUMN_NAME = \'inventario_estricto\'/', $ieMigracion) === 1);
chk('migracion 013: es idempotente (agrega la columna solo si falta, via PREPARE/EXECUTE)',
    str_contains($ieMigracion, '@has_col = 0')
    && preg_match('/ADD COLUMN inventario_estricto TINYINT\(1\) NOT NULL DEFAULT 0/', $ieMigracion) === 1
    && preg_match('/PREPARE s_col FROM @sql_col;\s+EXECUTE s_col;\s+DEALLOCATE PREPARE s_col;/', $ieMigracion) === 1);
chk('migracion 013: no enciende a ninguna empresa (no contiene UPDATE tenants)',
    $ieMigracion !== '' && stripos($ieMigracion, 'UPDATE tenants') === false);
// La regla de phpMyAdmin (db/migrations/README.md, 028): la base va en @db desde
// la primera sentencia, la segunda es la guardia y el unico SELECT de nivel
// superior es el ultimo (el que muestra phpMyAdmin).
$ieSinComentarios = preg_replace('/^\s*--.*$/m', '', $ieMigracion);
$ieSentencias = array_values(array_filter(array_map('trim', explode(';', (string) $ieSinComentarios)), static fn($s) => $s !== ''));
$ieSelects = array_keys(array_filter($ieSentencias, static fn($s) => stripos($s, 'SELECT') === 0));
chk('migracion 013: primera sentencia SET @db, segunda la guardia',
    isset($ieSentencias[1]) && $ieSentencias[0] === 'SET @db := DATABASE()' && str_starts_with($ieSentencias[1], 'SET @guardia :='), array_slice($ieSentencias, 0, 2));
chk('migracion 013: el unico SELECT de nivel superior es el ultimo',
    $ieSentencias !== [] && $ieSelects === [count($ieSentencias) - 1], $ieSelects);

$ieVerificador = ie_fuente('tools/verificar_migraciones_master.sql');
chk('verificador del master: la fila 013 pide la columna tenants.inventario_estricto',
    preg_match("/SELECT '013', '013_tenants_inventario_estricto\\.sql',\\s*'[^']*',\\s*"
        . "EXISTS \\(SELECT 1 FROM information_schema\\.COLUMNS\\s+WHERE TABLE_SCHEMA = DATABASE\\(\\) AND TABLE_NAME = 'tenants'\\s+"
        . "AND COLUMN_NAME = 'inventario_estricto'\\)/", $ieVerificador) === 1);

// ---------------------------------------------------------------------------
// 2) La verificacion: inventoryModel::calcularFaltantes (nucleo puro),
//    mensajeFaltantes, verificarDisponibilidad (con una conexion falsa) y
//    respuestaExistencia.
// ---------------------------------------------------------------------------
echo "\n== 2) La verificacion de existencia ==\n";
require_once __DIR__ . '/../src/Models/inventoryModel.php';

/** Fila del catalogo tal como la espera calcularFaltantes. */
function ie_prod(string $nombre, ?float $stock, bool $servicio = false, ?string $sku = null, ?string $um = '43'): array
{
    return ['nombre' => $nombre, 'sku' => $sku, 'unidad_medida' => $um, 'stock' => $stock, 'servicio' => $servicio];
}

if (!method_exists(inventoryModel::class, 'calcularFaltantes')
    || !method_exists(inventoryModel::class, 'mensajeFaltantes')
    || !method_exists(inventoryModel::class, 'verificarDisponibilidad')
    || !method_exists(inventoryModel::class, 'respuestaExistencia')
    || !method_exists(inventoryModel::class, 'esEntrada')) {
    chk('inventoryModel tiene esEntrada, calcularFaltantes, mensajeFaltantes, verificarDisponibilidad y respuestaExistencia', false);
} else {
    $calc = static fn(array $items, array $cat, ?string $tipo = '31'): array => inventoryModel::calcularFaltantes($items, $cat, $tipo);

    // --- esEntrada: la misma prueba que usa registrarVenta ---
    chk('esEntrada: 34 y 41 son entradas', inventoryModel::esEntrada('34') === true && inventoryModel::esEntrada('41') === true);
    chk('esEntrada: 31, 32, 33, 43, 44, 45, 46, 47 y null no lo son',
        array_reduce(['31', '32', '33', '43', '44', '45', '46', '47', null], static fn($ok, $t) => $ok && inventoryModel::esEntrada($t) === false, true));
    $ieFuenteModelo = ie_fuente('src/Models/inventoryModel.php');
    preg_match('/public function registrarVenta\(.*?\n    \}\n/s', $ieFuenteModelo, $mVenta);
    chk('registrarVenta usa esEntrada() (una sola prueba para "bloquea" y "descuenta")',
        isset($mVenta[0]) && str_contains($mVenta[0], 'self::esEntrada(') && !str_contains($mVenta[0], "['34', '41']"), $mVenta[0] ?? null);

    chk('docblocks de inventoryModel: el saldo negativo se tolera solo con el interruptor de la empresa apagado (ya no "se permite" sin condicion)',
        !str_contains($ieFuenteModelo, 'Se permite saldo negativo.')
        && !str_contains($ieFuenteModelo, 'El saldo negativo NO se bloquea:')
        && substr_count($ieFuenteModelo, 'interruptor') >= 2);

    // --- Suma por producto y comparacion en milesimas ---
    $r = $calc([['product_id' => 7, 'quantity' => 3], ['product_id' => 7, 'quantity' => 3]],
        [7 => ie_prod('Cemento gris', 4.0, false, 'CEM-01', '43')]);
    chk('suma las lineas del mismo producto: stock 4, dos lineas de 3 -> un faltante (solicitado 6, disponible 4, faltante 2)',
        count($r['faltantes']) === 1
        && $r['faltantes'][0] === ['product_id' => 7, 'nombre' => 'Cemento gris', 'sku' => 'CEM-01', 'unidad_medida' => '43',
            'solicitado' => 6.0, 'disponible' => 4.0, 'faltante' => 2.0]
        && $r['no_existe'] === [] && $r['cantidad_invalida'] === [], $r);

    $r = $calc([['product_id' => 7, 'quantity' => 0.1], ['product_id' => 7, 'quantity' => 0.2]], [7 => ie_prod('Tubo', 0.3)]);
    chk('milesimas: stock 0.3 con lineas 0.1 y 0.2 pasa (0.1 + 0.2 en flotante da 0.30000000000000004)', $r['faltantes'] === [], $r);
    $r = $calc([['product_id' => 7, 'quantity' => 0.3]], [7 => ie_prod('Tubo', 0.299)]);
    chk('milesimas: stock 0.299 con 0.3 -> faltante 0.001 (solicitado 0.3, disponible 0.299)',
        count($r['faltantes']) === 1 && $r['faltantes'][0]['faltante'] === 0.001
        && $r['faltantes'][0]['solicitado'] === 0.3 && $r['faltantes'][0]['disponible'] === 0.299, $r);
    $r = $calc([['product_id' => 7, 'quantity' => '1.500']], [7 => ie_prod('Cable', 1.5)]);
    chk('la cantidad como texto DECIMAL ("1.500") se entiende y exacto pasa', $r['faltantes'] === [], $r);

    $r = $calc([['product_id' => 7, 'quantity' => 4]], [7 => ie_prod('Cemento gris', 4.0)]);
    chk('igual pasa: pedir exactamente el stock no es faltante', $r['faltantes'] === [], $r);

    $r = $calc([['product_id' => 7, 'quantity' => 1]], [7 => ie_prod('Cemento gris', 0.0)]);
    chk('stock 0 rechaza cualquier cantidad (disponible 0, faltante 1)',
        count($r['faltantes']) === 1 && $r['faltantes'][0]['disponible'] === 0.0 && $r['faltantes'][0]['faltante'] === 1.0, $r);

    $r = $calc([['product_id' => 7, 'quantity' => 2.5]], [7 => ie_prod('Cemento gris', null)]);
    chk('stock NULL cuenta como 0 (disponible 0, faltante = solicitado)',
        count($r['faltantes']) === 1 && $r['faltantes'][0]['disponible'] === 0.0
        && $r['faltantes'][0]['solicitado'] === 2.5 && $r['faltantes'][0]['faltante'] === 2.5, $r);

    $r = $calc([['product_id' => 7, 'quantity' => 5]], [7 => ie_prod('Cemento gris', -3.0)]);
    chk('stock negativo: -3 y pide 5 -> disponible 0 (nunca negativo) y faltante 8 (para llevarlo hasta lo vendido)',
        count($r['faltantes']) === 1 && $r['faltantes'][0]['disponible'] === 0.0
        && $r['faltantes'][0]['solicitado'] === 5.0 && $r['faltantes'][0]['faltante'] === 8.0, $r);

    // --- Lo que nunca se revisa ---
    $r = $calc([['product_id' => 9, 'quantity' => 100]], [9 => ie_prod('Flete', null, true)]);
    chk('un servicio del catalogo no se revisa', $r === ['faltantes' => [], 'no_existe' => [], 'cantidad_invalida' => []], $r);
    $r = $calc([['product_id' => 9, 'quantity' => 0]], [9 => ie_prod('Flete', null, true)]);
    chk('un servicio con cantidad 0 tampoco es cantidad invalida', $r['cantidad_invalida'] === [] && $r['faltantes'] === [], $r);
    $r = $calc([['quantity' => 100], ['product_id' => null, 'quantity' => 5], ['product_id' => 0, 'quantity' => 5], ['product_id' => '', 'quantity' => 5]], []);
    chk('una linea sin product_id (libre) no se revisa, ni cuenta como inexistente',
        $r === ['faltantes' => [], 'no_existe' => [], 'cantidad_invalida' => []], $r);
    $corto = [7 => ie_prod('Cemento gris', 1.0)];
    chk('E34 (nota de credito) no se revisa aunque falte', $calc([['product_id' => 7, 'quantity' => 50]], $corto, '34')['faltantes'] === []);
    chk('E41 (compras) no se revisa aunque falte', $calc([['product_id' => 7, 'quantity' => 50]], $corto, '41')['faltantes'] === []);
    chk('una entrada no revisa ni producto inexistente ni cantidad invalida',
        $calc([['product_id' => 99, 'quantity' => 1], ['product_id' => 7, 'quantity' => 0]], $corto, '34')
            === ['faltantes' => [], 'no_existe' => [], 'cantidad_invalida' => []]);
    chk('E33 (nota de debito) si se revisa', count($calc([['product_id' => 7, 'quantity' => 50]], $corto, '33')['faltantes']) === 1);
    chk('E31, E32, E43, E44, E45, E46 y E47 se revisan',
        array_reduce(['31', '32', '43', '44', '45', '46', '47'], static fn($ok, $t) => $ok && count($calc([['product_id' => 7, 'quantity' => 50]], $corto, $t)['faltantes']) === 1, true));
    chk('sin tipo (factura simple, null) se revisa', count($calc([['product_id' => 7, 'quantity' => 50]], $corto, null)['faltantes']) === 1);

    // --- Lo que no es un faltante de existencia ---
    $r = $calc([['product_id' => 99, 'quantity' => 2], ['product_id' => 7, 'quantity' => 1]], $corto);
    chk('producto inexistente: va en no_existe y nunca en faltantes', $r['no_existe'] === [99] && $r['faltantes'] === [] && $r['cantidad_invalida'] === [], $r);
    $r = $calc([['product_id' => 7, 'quantity' => 0], ['product_id' => 8, 'quantity' => -2], ['product_id' => 6, 'quantity' => 'abc'], ['product_id' => 5]],
        [5 => ie_prod('A', 9.0), 6 => ie_prod('B', 9.0), 7 => ie_prod('C', 9.0), 8 => ie_prod('D', 9.0)]);
    chk('cantidad cero, negativa, no numerica o ausente en un producto: va en cantidad_invalida (ordenada) y nunca en faltantes',
        $r['cantidad_invalida'] === [5, 6, 7, 8] && $r['faltantes'] === [] && $r['no_existe'] === [], $r);
    $r = $calc([['product_id' => 7, 'quantity' => 0.0004]], [7 => ie_prod('C', 9.0)]);
    chk('una cantidad que redondea a 0 a 3 decimales (0.0004) es invalida: aplicarMovimientos la descartaria', $r['cantidad_invalida'] === [7], $r);

    // --- Aceptar quantity o cantidad, objetos, orden ---
    $r = $calc([['product_id' => 7, 'cantidad' => 5]], $corto);
    chk('acepta "cantidad" ademas de "quantity"', count($r['faltantes']) === 1 && $r['faltantes'][0]['solicitado'] === 5.0, $r);
    $r = $calc([['product_id' => 7, 'quantity' => 2, 'cantidad' => 99]], $corto);
    chk('si vienen las dos, manda "quantity" (como moverPorDocumento)', $r['faltantes'][0]['solicitado'] === 2.0, $r);
    $r = $calc([(object) ['product_id' => 7, 'quantity' => 5]], $corto);
    chk('acepta lineas como objetos (moverPorDocumento tambien las convierte con (array))', count($r['faltantes']) === 1, $r);
    $r = $calc([['product_id' => 9, 'quantity' => 5], ['product_id' => 3, 'quantity' => 5], ['product_id' => 5, 'quantity' => 5]],
        [9 => ie_prod('Nueve', 0.0), 3 => ie_prod('Tres', 0.0), 5 => ie_prod('Cinco', 0.0)]);
    chk('ordena los faltantes por product_id', array_column($r['faltantes'], 'product_id') === [3, 5, 9], $r);
    $r = $calc([['product_id' => 50, 'quantity' => 1], ['product_id' => 20, 'quantity' => 1], ['product_id' => 20, 'quantity' => 1]], []);
    chk('no_existe sale ordenado y sin repetir', $r['no_existe'] === [20, 50], $r);

    // --- Mensaje ---
    $fila = ['product_id' => 12, 'nombre' => 'Cemento gris', 'sku' => 'CEM-01', 'unidad_medida' => '43',
        'solicitado' => 5.0, 'disponible' => 2.0, 'faltante' => 3.0];
    $msg = inventoryModel::mensajeFaltantes([$fila]);
    chk('mensaje: igual al ejemplo de la spec', $msg === 'No hay existencia suficiente de «Cemento gris»: pides 5 y hay 2.', $msg);
    $msg = inventoryModel::mensajeFaltantes([['disponible' => 0.0] + $fila]);
    chk('mensaje con disponible 0: dice "hay 0" y no lleva signo menos', str_contains($msg, 'pides 5 y hay 0') && !str_contains($msg, '-'), $msg);
    $msg = inventoryModel::mensajeFaltantes([['disponible' => -3.0] + $fila]);
    chk('mensaje con un saldo negativo recibido por error: nunca imprime "-3"', str_contains($msg, 'hay 0') && !str_contains($msg, '-'), $msg);
    $msg = inventoryModel::mensajeFaltantes([['nombre' => 'CABLE_THHN_12'] + $fila]);
    chk('mensaje con nombre parecido a una constante (CABLE_THHN_12): se omite el nombre y sigue diciendo "pides"',
        !str_contains($msg, 'CABLE_THHN_12') && !str_contains($msg, '«') && str_contains($msg, 'pides 5 y hay 2') && str_ends_with($msg, '.'), $msg);
    $msg = inventoryModel::mensajeFaltantes([['nombre' => '   '] + $fila]);
    chk('mensaje con nombre vacio: sigue diciendo "pides"', !str_contains($msg, '«') && str_contains($msg, 'pides 5 y hay 2'), $msg);
    $msg = inventoryModel::mensajeFaltantes([$fila, ['product_id' => 13, 'nombre' => 'Varilla 3/8', 'solicitado' => 1.0, 'disponible' => 0.0, 'faltante' => 1.0] + $fila]);
    chk('mensaje con dos productos: una oracion por producto, unidas',
        str_contains($msg, '«Cemento gris»') && str_contains($msg, '«Varilla 3/8»') && substr_count($msg, 'No hay existencia suficiente') === 2
        && str_contains($msg, 'pides 1 y hay 0'), $msg);
    $msg = inventoryModel::mensajeFaltantes([['solicitado' => 1250.5, 'disponible' => 0.299] + $fila]);
    chk('mensaje con decimales y miles: sin ceros de relleno y al formato del app (1,250.5 y 0.299)', str_contains($msg, 'pides 1,250.5 y hay 0.299'), $msg);
    chk('mensaje sin faltantes: cadena vacia', inventoryModel::mensajeFaltantes([]) === '');

    // --- verificarDisponibilidad con una conexion falsa (nunca toca MySQL) ---
    // Devuelve las filas de products como las da MySQL: todo texto, stock DECIMAL.
    final class IeCatalogoSentencia extends PDOStatement
    {
        public ?IeCatalogoConexion $con = null;
        public string $sql = '';
        private array $filas = [];

        public function execute(?array $params = null): bool
        {
            $this->con->consultas[] = ['sql' => preg_replace('/\s+/', ' ', $this->sql), 'params' => $params ?? []];
            $this->filas = [];
            foreach ($params ?? [] as $id) {
                if (isset($this->con->productos[$id])) {
                    $this->filas[] = ['id' => (string) $id] + $this->con->productos[$id];
                }
            }
            return true;
        }

        public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
        {
            return $this->filas;
        }
    }

    final class IeCatalogoConexion extends PDO
    {
        public array $consultas = [];

        public function __construct(public array $productos, private bool $rota = false)
        {
            // Sin parent::__construct: nunca abre una conexion.
        }

        public function prepare(string $query, array $options = []): PDOStatement|false
        {
            if ($this->rota) {
                throw new PDOException('se cayo la conexion');
            }
            $s = new IeCatalogoSentencia();
            $s->con = $this;
            $s->sql = $query;
            return $s;
        }
    }

    $ieProductos = [
        7 => ['nombre' => 'Cemento gris', 'sku' => 'CEM-01', 'unidad_medida' => '43', 'stock' => '2.000', 'indicador_bien_servicio' => '1'],
        8 => ['nombre' => 'Varilla 3/8', 'sku' => null, 'unidad_medida' => '43', 'stock' => null, 'indicador_bien_servicio' => '1'],
        9 => ['nombre' => 'Flete', 'sku' => null, 'unidad_medida' => '43', 'stock' => null, 'indicador_bien_servicio' => '2'],
    ];
    $ieInventario = static fn(IeCatalogoConexion $c): inventoryModel => new inventoryModel($c);

    $ctor = new ReflectionMethod(inventoryModel::class, '__construct');
    $param = $ctor->getParameters()[0] ?? null;
    chk('inventoryModel::__construct(?PDO $conexion = null): conexion opcional (C20), el modelo se arma con la que se le da',
        $param !== null && $param->isOptional() && $param->allowsNull() && (string) $param->getType() === '?PDO'
        && $ieInventario(new IeCatalogoConexion([])) instanceof inventoryModel);
    chk('el constructor sin argumento sigue abriendo la conexion de siempre (Database::getInstance()->getConnection())',
        preg_match('/function __construct\(\?PDO \$conexion = null\)\s*\{[^}]*Database::getInstance\(\)->getConnection\(\)/s', $ieFuenteModelo) === 1);

    $c = new IeCatalogoConexion($ieProductos);
    $res = $ieInventario($c)->verificarDisponibilidad([['product_id' => 7, 'cantidad' => 5]], '31');
    chk('verificarDisponibilidad: faltante -> [error, mensaje, 422, [codigo STOCK_INSUFICIENTE, faltantes]]',
        count($res) === 4 && $res[0] === 'error' && $res[2] === 422
        && ($res[3]['codigo'] ?? null) === 'STOCK_INSUFICIENTE'
        && ($res[3]['faltantes'] ?? null) === [['product_id' => 7, 'nombre' => 'Cemento gris', 'sku' => 'CEM-01', 'unidad_medida' => '43',
            'solicitado' => 5.0, 'disponible' => 2.0, 'faltante' => 3.0]]
        && $res[1] === 'No hay existencia suficiente de «Cemento gris»: pides 5 y hay 2.', $res);

    $c = new IeCatalogoConexion($ieProductos);
    $res = $ieInventario($c)->verificarDisponibilidad([['product_id' => 7, 'cantidad' => 2]], '31');
    chk("verificarDisponibilidad: cubierto -> ['success', []]", $res === ['success', []], $res);

    $c = new IeCatalogoConexion($ieProductos);
    $res = $ieInventario($c)->verificarDisponibilidad([['product_id' => 8, 'cantidad' => 1]], null);
    chk('verificarDisponibilidad: un Bien con stock NULL cuenta como 0 y se rechaza (disponible 0)',
        ($res[0] ?? null) === 'error' && ($res[3]['faltantes'][0]['disponible'] ?? null) === 0.0
        && array_key_exists('sku', $res[3]['faltantes'][0]) && $res[3]['faltantes'][0]['sku'] === null, $res);

    $c = new IeCatalogoConexion($ieProductos);
    $res = $ieInventario($c)->verificarDisponibilidad([['product_id' => 7, 'cantidad' => 1], ['product_id' => 9, 'cantidad' => 40], ['product_id' => 7, 'cantidad' => 1], ['cantidad' => 3]], '31');
    chk('verificarDisponibilidad: un servicio y una linea libre no se revisan; lo demas pasa',
        $res === ['success', []], $res);
    chk('verificarDisponibilidad: UNA sola consulta IN (...) con los ids unicos, ordenados',
        count($c->consultas) === 1 && $c->consultas[0]['params'] === [7, 9]
        && str_contains($c->consultas[0]['sql'], 'FROM products WHERE id IN (?,?)')
        && !str_contains(strtoupper($c->consultas[0]['sql']), 'FOR UPDATE'), $c->consultas);
    chk('verificarDisponibilidad: la consulta pide id, nombre, sku, unidad_medida, stock e indicador_bien_servicio',
        preg_match('/^SELECT id, nombre, sku, unidad_medida, stock, indicador_bien_servicio FROM products/', $c->consultas[0]['sql'] ?? '') === 1, $c->consultas[0]['sql'] ?? null);

    foreach ([
        'sin lineas' => [[], '31'],
        'solo lineas libres' => [[['cantidad' => 3], ['product_id' => null, 'cantidad' => 3]], '31'],
        'una entrada (E34)' => [[['product_id' => 7, 'cantidad' => 50]], '34'],
        'una entrada (E41)' => [[['product_id' => 99, 'cantidad' => 0]], '41'],
    ] as $caso => [$lineas, $tipo]) {
        $c = new IeCatalogoConexion($ieProductos);
        $res = $ieInventario($c)->verificarDisponibilidad($lineas, $tipo);
        chk("verificarDisponibilidad {$caso}: success sin ir a la base", $res === ['success', []] && $c->consultas === [], [$res, $c->consultas]);
    }

    $logTmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ie_stock_' . getmypid() . '.log';
    $logPrevio = ini_set('error_log', $logTmp);
    $c = new IeCatalogoConexion($ieProductos);
    $res = $ieInventario($c)->verificarDisponibilidad([['product_id' => 99, 'cantidad' => 1], ['product_id' => 7, 'cantidad' => 50]], '31');
    chk('verificarDisponibilidad: producto inexistente -> 422 llano (3 elementos, sin codigo ni faltantes), aunque otro producto tambien falte',
        count($res) === 3 && $res[0] === 'error' && $res[2] === 422
        && !str_contains($res[1], 'existencia suficiente') && !str_contains($res[1], 'STOCK') && !str_contains($res[1], '99'), $res);
    $c = new IeCatalogoConexion($ieProductos);
    $res = $ieInventario($c)->verificarDisponibilidad([['product_id' => 7, 'cantidad' => 0]], '31');
    chk('verificarDisponibilidad: cantidad 0 -> 422 llano que nombra el producto',
        count($res) === 3 && $res[0] === 'error' && $res[2] === 422 && str_contains($res[1], '«Cemento gris»')
        && !str_contains($res[1], 'existencia suficiente'), $res);
    $c = new IeCatalogoConexion($ieProductos);
    $res = $ieInventario($c)->verificarDisponibilidad([['product_id' => 7, 'cantidad' => -1], ['product_id' => 7, 'cantidad' => 9]], '31');
    chk('verificarDisponibilidad: una linea negativa del producto -> 422 llano, aunque otra linea suya si pida de mas',
        count($res) === 3 && $res[2] === 422 && !str_contains($res[1], 'existencia suficiente'), $res);
    $logLeido = is_file($logTmp) ? (string) file_get_contents($logTmp) : '';
    chk('verificarDisponibilidad: el id del producto inexistente va al log (no al mensaje)', str_contains($logLeido, 'producto 99'), $logLeido);
    ini_set('error_log', $logPrevio === false ? '' : $logPrevio);
    if (is_file($logTmp)) {
        unlink($logTmp);
    }

    $lanzo = null;
    try {
        $ieInventario(new IeCatalogoConexion($ieProductos, true))->verificarDisponibilidad([['product_id' => 7, 'cantidad' => 1]], '31');
    } catch (PDOException $e) {
        $lanzo = $e;
    }
    chk('verificarDisponibilidad: si la base falla la PDOException sale (quien llama decide, tarea 4)', $lanzo instanceof PDOException);

    // --- respuestaExistencia: el cuerpo JSON ---
    $faltantes = [['product_id' => 12, 'nombre' => 'Cemento gris', 'sku' => 'CEM-01', 'unidad_medida' => '43', 'solicitado' => 5.0, 'disponible' => 2.0, 'faltante' => 3.0]];
    $cuerpo = inventoryModel::respuestaExistencia(['error', 'No hay existencia suficiente de «Cemento gris»: pides 5 y hay 2.', 422,
        ['codigo' => 'STOCK_INSUFICIENTE', 'faltantes' => $faltantes]]);
    chk('respuestaExistencia con faltantes: status false, error, codigo y data.faltantes (en ese orden)',
        array_keys($cuerpo) === ['status', 'error', 'codigo', 'data'] && $cuerpo['status'] === false
        && $cuerpo['error'] === 'No hay existencia suficiente de «Cemento gris»: pides 5 y hay 2.'
        && $cuerpo['codigo'] === 'STOCK_INSUFICIENTE' && $cuerpo['data'] === ['faltantes' => $faltantes], $cuerpo);
    $json = json_decode(json_encode($cuerpo, JSON_UNESCAPED_UNICODE), true);
    chk('respuestaExistencia: los numeros viajan como numeros JSON, no como texto',
        is_numeric($json['data']['faltantes'][0]['solicitado']) && !is_string($json['data']['faltantes'][0]['solicitado'])
        && !is_string($json['data']['faltantes'][0]['disponible']) && !is_string($json['data']['faltantes'][0]['faltante'])
        && is_int($json['data']['faltantes'][0]['product_id']), $json);
    $cuerpo = inventoryModel::respuestaExistencia(['error', 'Uno de los productos ya no existe.', 422]);
    chk('respuestaExistencia caso llano: solo status y error (sin codigo ni data)',
        $cuerpo === ['status' => false, 'error' => 'Uno de los productos ya no existe.'], $cuerpo);
}

// ---------------------------------------------------------------------------
// 3) El libro: aplicarMovimientos (bloqueo en orden de product_id y bandera
//    bloquear_sin_existencia), moverVentaEnTransaccion, los motivos nuevos y el
//    aviso SOBREVENTA. La conexion falsa lleva estado (las filas de products) y
//    anota cada consulta y cada begin / commit / rollback; nunca toca MySQL.
// ---------------------------------------------------------------------------
echo "\n== 3) Libro: bloqueo en orden, bandera, venta en transaccion, motivos ==\n";

/** Fila de products como la da MySQL: todo texto, stock DECIMAL (o NULL). */
function ie_fila(string $nombre, ?string $stock, string $indicador = '1', ?string $sku = null, string $um = '43'): array
{
    return ['nombre' => $nombre, 'sku' => $sku, 'unidad_medida' => $um, 'stock' => $stock, 'costo' => '10.00',
        'warehouse_id' => '1', 'indicador_bien_servicio' => $indicador];
}

final class IeLibroSentencia extends PDOStatement
{
    public ?IeLibroConexion $con = null;
    public string $sql = '';
    private array $filas = [];

    public function execute(?array $params = null): bool
    {
        $params ??= [];
        $sql = preg_replace('/\s+/', ' ', $this->sql);
        $this->con->consultas[] = ['sql' => $sql, 'params' => $params];
        if ($this->con->fallaEn !== null && str_contains($sql, $this->con->fallaEn)) {
            throw new PDOException('se cayo la conexion');
        }
        $this->filas = [];
        $p = $this->con->productos;
        if (str_contains($sql, 'FROM products WHERE id = :id FOR UPDATE')) {
            // El bloqueo: la fila de verdad, con el saldo que dejaron los UPDATE.
            $id = (int) $params[':id'];
            if (isset($p[$id])) {
                $this->filas[] = ['id' => (string) $id] + $p[$id];
            }
        } elseif (str_starts_with($sql, 'UPDATE products SET stock = :nueva WHERE id = :id')) {
            $this->con->productos[(int) $params[':id']]['stock'] = number_format((float) $params[':nueva'], 3, '.', '');
        } elseif (str_starts_with($sql, 'INSERT INTO inventory_movements')) {
            $this->con->movimientos[] = $params;
        } elseif (str_contains($sql, 'AND indicador_bien_servicio = 2')) {
            // serviciosDelCatalogo: solo los ids que son servicio.
            foreach ($params as $id) {
                if (isset($p[$id]) && $p[$id]['indicador_bien_servicio'] === '2') {
                    $this->filas[] = ['id' => (string) $id];
                }
            }
        } elseif (str_contains($sql, 'FROM products WHERE id IN')) {
            // catalogoParaVenta: lectura SIN bloqueo (puede ver un saldo viejo).
            foreach ($params as $id) {
                if (isset($p[$id])) {
                    $fila = ['id' => (string) $id] + $p[$id];
                    if (array_key_exists($id, $this->con->stockSinBloqueo)) {
                        $fila['stock'] = $this->con->stockSinBloqueo[$id];
                    }
                    $this->filas[] = $fila;
                }
            }
        }
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return array_shift($this->filas) ?? false;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        if ($mode === PDO::FETCH_COLUMN) {
            return array_map(static fn(array $f) => array_values($f)[0], $this->filas);
        }
        return $this->filas;
    }
}

final class IeLibroConexion extends PDO
{
    public array $consultas = [];
    public array $eventos = [];      // 'begin' | 'commit' | 'rollback'
    public array $movimientos = [];  // parametros de cada INSERT INTO inventory_movements
    public ?string $fallaEn = null;  // si el SQL contiene este texto, execute() lanza
    /** @var array<int,?string> stock que ve la lectura sin bloqueo (un saldo viejo) */
    public array $stockSinBloqueo = [];
    private int $ultimoId = 0;

    public function __construct(public array $productos, public bool $enTransaccion = false)
    {
        // Sin parent::__construct: nunca abre una conexion.
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $s = new IeLibroSentencia();
        $s->con = $this;
        $s->sql = $query;
        return $s;
    }

    public function beginTransaction(): bool
    {
        $this->eventos[] = 'begin';
        $this->enTransaccion = true;
        return true;
    }

    public function commit(): bool
    {
        $this->eventos[] = 'commit';
        $this->enTransaccion = false;
        return true;
    }

    public function rollBack(): bool
    {
        $this->eventos[] = 'rollback';
        $this->enTransaccion = false;
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->enTransaccion;
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return (string) ++$this->ultimoId;
    }
}

/** Corre $fn con error_log apuntando a un archivo temporal y devuelve lo que se escribio. */
function ie_con_log(callable $fn): string
{
    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ie_libro_' . getmypid() . '_' . mt_rand() . '.log';
    $previo = ini_set('error_log', $tmp);
    try {
        $fn();
    } finally {
        ini_set('error_log', $previo === false ? '' : $previo);
    }
    $leido = is_file($tmp) ? (string) file_get_contents($tmp) : '';
    if (is_file($tmp)) {
        unlink($tmp);
    }
    return $leido;
}

$ieLibro = static fn(array $productos, bool $enTransaccion = false): IeLibroConexion => new IeLibroConexion($productos, $enTransaccion);
$ieModelo = static fn(IeLibroConexion $c): inventoryModel => new inventoryModel($c);
$ieCtxVenta = ['tipo_movimiento' => 'VENTA', 'referencia_tipo' => 'factura', 'referencia_id' => 41, 'user_id' => 5];
/** Ids de los SELECT ... FOR UPDATE, en el orden en que se hicieron. */
$ieBloqueados = static fn(IeLibroConexion $c): array => array_map(
    static fn(array $q) => $q['params'][':id'],
    array_values(array_filter($c->consultas, static fn(array $q) => str_contains($q['sql'], 'FOR UPDATE')))
);
/** Cuantas consultas empiezan con este texto (UPDATE products, INSERT INTO ...). */
$ieCuantas = static fn(IeLibroConexion $c, string $prefijo): int => count(array_filter(
    $c->consultas, static fn(array $q) => str_starts_with($q['sql'], $prefijo)
));
$ieStock = static fn(IeLibroConexion $c, int $id): ?float => $c->productos[$id]['stock'] === null ? null : round((float) $c->productos[$id]['stock'], 3);

if (!method_exists(inventoryModel::class, 'moverVentaEnTransaccion')) {
    chk('inventoryModel::moverVentaEnTransaccion existe', false);
}

// --- aplicarMovimientos: bloqueo en orden de product_id ---
$c = $ieLibro([3 => ie_fila('Tres', '10.000'), 5 => ie_fila('Cinco', '10.000'), 9 => ie_fila('Nueve', '10.000')]);
$res = $ieModelo($c)->aplicarMovimientos([
    ['product_id' => 9, 'cantidad' => -1.0], ['product_id' => 3, 'cantidad' => -1.0], ['product_id' => 5, 'cantidad' => -1.0],
], $ieCtxVenta);
chk('bloquea en orden de product_id: lineas 9, 3, 5 -> SELECT ... FOR UPDATE en 3, 5, 9', $ieBloqueados($c) === [3, 5, 9], $ieBloqueados($c));
chk('el libro se escribe en el orden en que llegaron las lineas (9, 3, 5): solo el bloqueo se ordena',
    array_column($c->movimientos, ':product_id') === [9, 3, 5], array_column($c->movimientos, ':product_id'));
chk('devuelve los movimientos en el orden de las lineas y con transaccion propia (begin, commit)',
    $res[0] === 'success' && array_column($res[1], 'product_id') === [9, 3, 5] && $c->eventos === ['begin', 'commit'], [$res, $c->eventos]);

$c = $ieLibro([3 => ie_fila('Tres', '10.000')]);
$res = $ieModelo($c)->aplicarMovimientos([['product_id' => 3, 'cantidad' => -1.0], ['product_id' => 3, 'cantidad' => -2.0]], $ieCtxVenta);
chk('el mismo producto en dos lineas: la segunda parte del saldo que dejo la primera (10 -> 9 -> 7)',
    $res[0] === 'success' && $c->movimientos[1][':anterior'] === 9.0 && $c->movimientos[1][':nueva'] === 7.0 && $ieStock($c, 3) === 7.0, $c->movimientos);

// --- Sin bandera: el comportamiento de hoy ---
foreach (['sin la clave' => $ieCtxVenta, 'con la clave en false' => $ieCtxVenta + ['bloquear_sin_existencia' => false]] as $caso => $ctx) {
    $c = $ieLibro([7 => ie_fila('Cemento gris', '2.000', '1', 'CEM-01')]);
    $res = $ieModelo($c)->aplicarMovimientos([['product_id' => 7, 'cantidad' => -3.0]], $ctx);
    chk("sin bandera ({$caso}) sigue igual: stock 2 y salida de 3 escribe saldo -1",
        $res[0] === 'success' && count($res[1]) === 1 && $res[1][0]['cantidad_nueva'] === -1.0
        && $ieCuantas($c, 'UPDATE products') === 1 && $ieStock($c, 7) === -1.0 && $c->eventos === ['begin', 'commit'], [$res, $c->eventos]);
}

// --- Con bandera: rechaza ---
$ctxBloquea = $ieCtxVenta + ['bloquear_sin_existencia' => true];
$c = $ieLibro([7 => ie_fila('Cemento gris', '2.000', '1', 'CEM-01')]);
$res = $ieModelo($c)->aplicarMovimientos([['product_id' => 7, 'cantidad' => -3.0]], $ctxBloquea);
$esperaFila = ['product_id' => 7, 'nombre' => 'Cemento gris', 'sku' => 'CEM-01', 'unidad_medida' => '43',
    'solicitado' => 3.0, 'disponible' => 2.0, 'faltante' => 1.0];
chk('con bandera rechaza: mismo caso -> [error, mensaje, 422, [STOCK_INSUFICIENTE, una fila con faltante 1]]',
    count($res) === 4 && $res[0] === 'error' && $res[2] === 422
    && ($res[3]['codigo'] ?? null) === 'STOCK_INSUFICIENTE' && ($res[3]['faltantes'] ?? null) === [$esperaFila]
    && $res[1] === 'No hay existencia suficiente de «Cemento gris»: pides 3 y hay 2.', $res);
chk('con bandera rechaza: no escribe la linea (ni UPDATE products ni INSERT del movimiento)',
    $ieCuantas($c, 'UPDATE products') === 0 && $ieCuantas($c, 'INSERT INTO inventory_movements') === 0 && $ieStock($c, 7) === 2.0, $c->consultas);
chk('con bandera y transaccion propia: se deshace ella misma (begin, rollback; nunca commit)',
    $c->eventos === ['begin', 'rollback'], $c->eventos);

$c = $ieLibro([7 => ie_fila('Cemento gris', '3.000')]);
$res = $ieModelo($c)->aplicarMovimientos([['product_id' => 7, 'cantidad' => -3.0]], $ctxBloquea);
chk('con bandera: dejar el saldo exactamente en 0 pasa', $res[0] === 'success' && $ieStock($c, 7) === 0.0, $res);

$c = $ieLibro([7 => ie_fila('Tubo', '0.300')]);
$res = $ieModelo($c)->aplicarMovimientos([['product_id' => 7, 'cantidad' => -0.1], ['product_id' => 7, 'cantidad' => -0.2]], $ctxBloquea);
chk('con bandera: 0.1 + 0.2 contra un saldo de 0.3 pasa (se compara en milesimas)', $res[0] === 'success' && $ieStock($c, 7) === 0.0, $res);
$c = $ieLibro([7 => ie_fila('Tubo', '0.299')]);
$res = $ieModelo($c)->aplicarMovimientos([['product_id' => 7, 'cantidad' => -0.3]], $ctxBloquea);
chk('con bandera: 0.3 contra un saldo de 0.299 -> faltante 0.001',
    ($res[0] ?? null) === 'error' && ($res[3]['faltantes'][0]['faltante'] ?? null) === 0.001
    && ($res[3]['faltantes'][0]['disponible'] ?? null) === 0.299 && $res[3]['faltantes'][0]['solicitado'] === 0.3, $res);

$c = $ieLibro([7 => ie_fila('Cemento gris', '4.000')]);
$res = $ieModelo($c)->aplicarMovimientos([['product_id' => 7, 'cantidad' => -3.0], ['product_id' => 7, 'cantidad' => -3.0]], $ctxBloquea);
chk('con bandera: dos lineas de 3 contra 4 -> rechaza en la segunda y pide el total (solicitado 6, disponible 4, faltante 2), y se deshace',
    ($res[0] ?? null) === 'error' && count($res[3]['faltantes']) === 1
    && $res[3]['faltantes'][0]['solicitado'] === 6.0 && $res[3]['faltantes'][0]['disponible'] === 4.0 && $res[3]['faltantes'][0]['faltante'] === 2.0
    && $c->eventos === ['begin', 'rollback'], [$res, $c->eventos]);

$c = $ieLibro([7 => ie_fila('Cemento gris', null)]);
$res = $ieModelo($c)->aplicarMovimientos([['product_id' => 7, 'cantidad' => -2.5]], $ctxBloquea);
chk('con bandera: stock NULL cuenta como 0 (disponible 0, faltante = solicitado)',
    ($res[0] ?? null) === 'error' && $res[3]['faltantes'][0]['disponible'] === 0.0 && $res[3]['faltantes'][0]['faltante'] === 2.5
    && $res[3]['faltantes'][0]['sku'] === null, $res);
$c = $ieLibro([7 => ie_fila('Cemento gris', '-3.000')]);
$res = $ieModelo($c)->aplicarMovimientos([['product_id' => 7, 'cantidad' => -5.0]], $ctxBloquea);
chk('con bandera: saldo -3 y salida de 5 -> disponible 0 (nunca negativo), solicitado 5 y faltante 8; el texto no lleva signo menos',
    ($res[0] ?? null) === 'error' && $res[3]['faltantes'][0]['disponible'] === 0.0 && $res[3]['faltantes'][0]['solicitado'] === 5.0
    && $res[3]['faltantes'][0]['faltante'] === 8.0 && !str_contains($res[1], '-'), $res);

$c = $ieLibro([7 => ie_fila('Cemento gris', '-3.000'), 8 => ie_fila('Varilla', '1.000')]);
$res = $ieModelo($c)->aplicarMovimientos([['product_id' => 7, 'cantidad' => 2.0], ['product_id' => 8, 'cantidad' => 0.5]], $ctxBloquea);
chk('con bandera: una entrada (cantidad positiva) nunca se rechaza, aunque el saldo siga negativo (-3 + 2)',
    $res[0] === 'success' && $ieStock($c, 7) === -1.0 && $ieStock($c, 8) === 1.5, $res);

// --- Con transaccion ajena no abre ni cierra ---
$c = $ieLibro([7 => ie_fila('Cemento gris', '5.000')], true);
$res = $ieModelo($c)->aplicarMovimientos([['product_id' => 7, 'cantidad' => -2.0]], $ctxBloquea, false);
chk('con transaccion ajena (exito): no hay begin, commit ni rollback', $res[0] === 'success' && $c->eventos === [], [$res, $c->eventos]);
$c = $ieLibro([7 => ie_fila('Cemento gris', '2.000')], true);
$res = $ieModelo($c)->aplicarMovimientos([['product_id' => 7, 'cantidad' => -3.0]], $ctxBloquea, false);
chk('con transaccion ajena (rechazo por existencia): devuelve el error y NO hace rollback (lo hace quien la abrio)',
    ($res[0] ?? null) === 'error' && ($res[3]['codigo'] ?? null) === 'STOCK_INSUFICIENTE' && $c->eventos === [] && $c->enTransaccion === true, [$res, $c->eventos]);
$c = $ieLibro([], true);
$logProducto = ie_con_log(static function () use ($ieModelo, $c, $ieCtxVenta, &$res): void {
    $res = $ieModelo($c)->aplicarMovimientos([['product_id' => 99, 'cantidad' => -1.0]], $ieCtxVenta, false);
});
chk('con transaccion ajena (producto inexistente): error llano, sin rollback, y el id al log',
    ($res[0] ?? null) === 'error' && count($res) === 2 && $c->eventos === [] && str_contains($logProducto, 'el producto 99 no existe'), [$res, $logProducto]);
$c = $ieLibro([], false);
$logProducto = ie_con_log(static function () use ($ieModelo, $c, $ieCtxVenta, &$res): void {
    $res = $ieModelo($c)->aplicarMovimientos([['product_id' => 99, 'cantidad' => -1.0]], $ieCtxVenta);
});
chk('con transaccion propia (producto inexistente): error llano y se deshace (begin, rollback)',
    ($res[0] ?? null) === 'error' && count($res) === 2 && $c->eventos === ['begin', 'rollback'], [$res, $c->eventos]);

// --- Motivos ---
chk('MOTIVOS incluye ENTRADA e INVENTARIO_INICIAL, y conserva los de siempre',
    in_array('ENTRADA', inventoryModel::MOTIVOS, true) && in_array('INVENTARIO_INICIAL', inventoryModel::MOTIVOS, true)
    && array_diff(['CONTEO_FISICO', 'MERMA', 'DANO', 'ROBO', 'DEVOLUCION', 'ERROR_CAPTURA', 'ANULACION', 'OTRO'], inventoryModel::MOTIVOS) === [], inventoryModel::MOTIVOS);
chk('cada motivo cabe en inventory_adjustments.motivo VARCHAR(20) (sin migracion)',
    array_reduce(inventoryModel::MOTIVOS, static fn($ok, $m) => $ok && strlen($m) <= 20, true));
$ieAjuste = static fn(string $motivo, array $extra = []): array => $ieModelo($ieLibro([]))->crearAjuste(['motivo' => $motivo, 'lineas' => []] + $extra);
chk('crearAjuste acepta ENTRADA: pasa la validacion del motivo y cae en "agrega al menos un producto"',
    $ieAjuste('ENTRADA') === ['error', 'Agrega al menos un producto con cantidad al ajuste.', 422], $ieAjuste('ENTRADA'));
chk('crearAjuste acepta INVENTARIO_INICIAL (y en minusculas)',
    $ieAjuste('inventario_inicial') === ['error', 'Agrega al menos un producto con cantidad al ajuste.', 422], $ieAjuste('inventario_inicial'));
chk('ANULACION sigue rechazada en un ajuste creado por el usuario (sin anula_a_id)',
    $ieAjuste('ANULACION') === ['error', 'Elige el motivo del ajuste.', 422], $ieAjuste('ANULACION'));
chk('con anula_a_id solo vale ANULACION: ENTRADA se rechaza',
    $ieAjuste('ENTRADA', ['anula_a_id' => 3]) === ['error', 'Elige el motivo del ajuste.', 422], $ieAjuste('ENTRADA', ['anula_a_id' => 3]));
chk('un motivo que no existe sigue rechazado', $ieAjuste('REGALO') === ['error', 'Elige el motivo del ajuste.', 422]);

// --- moverVentaEnTransaccion ---
if (method_exists(inventoryModel::class, 'moverVentaEnTransaccion')) {
    $rm = new ReflectionMethod(inventoryModel::class, 'moverVentaEnTransaccion');
    $nombresParam = array_map(static fn(ReflectionParameter $p) => $p->getName(), $rm->getParameters());
    chk('moverVentaEnTransaccion(int $facturaId, array $itemsNuevos, ?int $userId, ?array $itemsViejos = null): public',
        $rm->isPublic() && $nombresParam === ['facturaId', 'itemsNuevos', 'userId', 'itemsViejos']
        && $rm->getParameters()[3]->isOptional() && $rm->getParameters()[3]->allowsNull() && $rm->getParameters()[2]->allowsNull(), $nombresParam);

    $moverVenta = static function (IeLibroConexion $c, array $nuevos, ?array $viejos = null) use ($ieModelo): array {
        return $ieModelo($c)->moverVentaEnTransaccion(41, $nuevos, 5, $viejos);
    };

    // Exito y sin tocar la transaccion.
    $c = $ieLibro([7 => ie_fila('Cemento gris', '5.000'), 9 => ie_fila('Flete', null, '2')], true);
    $res = $moverVenta($c, [['product_id' => 7, 'quantity' => 2], ['product_id' => 9, 'quantity' => 40], ['quantity' => 3]]);
    chk('moverVentaEnTransaccion exito: success, un movimiento VENTA (-2) de la factura; el servicio y la linea libre no mueven',
        $res === ['success', 1] && count($c->movimientos) === 1 && $c->movimientos[0][':tipo'] === 'VENTA'
        && $c->movimientos[0][':cantidad'] === -2.0 && $c->movimientos[0][':ref_tipo'] === 'factura' && $c->movimientos[0][':ref_id'] === 41
        && $c->movimientos[0][':user_id'] === 5 && $ieStock($c, 7) === 3.0, [$res, $c->movimientos]);
    chk('moverVentaEnTransaccion no abre ni cierra la transaccion (ni begin, ni commit, ni rollback) y la deja abierta',
        $c->eventos === [] && $c->enTransaccion === true, $c->eventos);

    // Sin existencia: rechaza con faltantes y sin escribir.
    $c = $ieLibro([7 => ie_fila('Cemento gris', '2.000', '1', 'CEM-01')], true);
    $res = $moverVenta($c, [['product_id' => 7, 'quantity' => 3]]);
    chk('moverVentaEnTransaccion sin existencia: [error, mensaje, 422, STOCK_INSUFICIENTE con la fila], sin escribir y sin rollback propio',
        count($res) === 4 && $res[0] === 'error' && $res[2] === 422 && ($res[3]['codigo'] ?? null) === 'STOCK_INSUFICIENTE'
        && ($res[3]['faltantes'] ?? null) === [$esperaFila] && $ieCuantas($c, 'UPDATE products') === 0
        && $ieCuantas($c, 'INSERT INTO inventory_movements') === 0 && $c->eventos === [], [$res, $c->eventos]);

    // Re-guardar con la misma cantidad y stock 0: la reversa devuelve lo que la revision necesita.
    $c = $ieLibro([7 => ie_fila('Cemento gris', '0.000')], true);
    $res = $moverVenta($c, [['product_id' => 7, 'quantity' => 3]], [['product_id' => '7', 'quantity' => '3.000']]);
    chk('moverVentaEnTransaccion con lineas viejas: stock 0 y las mismas 3 de nuevo pasa (la reversa lo repone antes de revisar)',
        $res === ['success', 2] && $ieStock($c, 7) === 0.0, [$res, $c->movimientos]);
    chk('moverVentaEnTransaccion con lineas viejas: primero DEVOLUCION (+3, de 0 a 3), despues VENTA (-3, de 3 a 0)',
        array_column($c->movimientos, ':tipo') === ['DEVOLUCION', 'VENTA'] && array_column($c->movimientos, ':cantidad') === [3.0, -3.0]
        && $c->movimientos[0][':anterior'] === 0.0 && $c->movimientos[0][':nueva'] === 3.0
        && $c->movimientos[1][':anterior'] === 3.0 && $c->movimientos[1][':nueva'] === 0.0 && $c->eventos === [], [$c->movimientos, $c->eventos]);

    // Subir de 3 a 5 con stock 1: la revision cuenta lo que la factura ya tenia.
    $c = $ieLibro([7 => ie_fila('Cemento gris', '1.000', '1', 'CEM-01')], true);
    $res = $moverVenta($c, [['product_id' => 7, 'quantity' => 5]], [['product_id' => 7, 'quantity' => '3.000']]);
    chk('moverVentaEnTransaccion subir de 3 a 5 con stock 1: rechaza con solicitado 5, disponible 4 (1 + las 3 de la factura) y faltante 1',
        ($res[0] ?? null) === 'error' && ($res[3]['faltantes'][0]['solicitado'] ?? null) === 5.0
        && $res[3]['faltantes'][0]['disponible'] === 4.0 && $res[3]['faltantes'][0]['faltante'] === 1.0, $res);
    chk('moverVentaEnTransaccion rechazado tras la reversa: no registra la venta y no se deshace solo (el que llama hace rollback)',
        array_column($c->movimientos, ':tipo') === ['DEVOLUCION'] && $c->eventos === [] && $c->enTransaccion === true, [$c->movimientos, $c->eventos]);

    // Producto inexistente y cantidad <= 0: llano.
    $c = $ieLibro([7 => ie_fila('Cemento gris', '5.000')], true);
    $logIgnorado = ie_con_log(static function () use ($moverVenta, $c, &$res): void {
        $res = $moverVenta($c, [['product_id' => 99, 'quantity' => 1]]);
    });
    chk('moverVentaEnTransaccion con un producto inexistente: 422 llano (3 elementos, sin codigo ni faltantes)',
        count($res) === 3 && $res[0] === 'error' && $res[2] === 422 && $ieCuantas($c, 'INSERT INTO inventory_movements') === 0, $res);
    $res = $moverVenta($c, [['product_id' => 7, 'quantity' => 0]]);
    chk('moverVentaEnTransaccion con cantidad 0: 422 llano', count($res) === 3 && $res[2] === 422 && str_contains($res[1], '«Cemento gris»'), $res);

    // La bandera es la red de seguridad contra la carrera: la revision lee SIN bloqueo.
    $c = $ieLibro([7 => ie_fila('Cemento gris', '2.000', '1', 'CEM-01')], true);
    $c->stockSinBloqueo = [7 => '5.000'];   // otra venta ya se llevo 3 entre la lectura y el bloqueo
    $res = $moverVenta($c, [['product_id' => 7, 'quantity' => 3]]);
    chk('moverVentaEnTransaccion carrera: la lectura vio 5 pero el saldo bloqueado es 2 -> la bandera rechaza igual (faltante 1) y no escribe',
        ($res[0] ?? null) === 'error' && ($res[3]['faltantes'][0]['faltante'] ?? null) === 1.0 && $res[3]['faltantes'][0]['disponible'] === 2.0
        && $ieCuantas($c, 'UPDATE products') === 0 && $c->eventos === [], [$res, $c->eventos]);

    // Sin transaccion abierta: no mueve nada (las sentencias sueltas se confirmarian solas).
    $c = $ieLibro([7 => ie_fila('Cemento gris', '5.000')], false);
    $logSin = ie_con_log(static function () use ($moverVenta, $c, &$res): void {
        $res = $moverVenta($c, [['product_id' => 7, 'quantity' => 2]]);
    });
    chk('moverVentaEnTransaccion sin transaccion abierta: error y no escribe nada (un descuento suelto se confirmaria solo)',
        ($res[0] ?? null) === 'error' && $c->consultas === [] && $c->eventos === [] && str_contains($logSin, 'moverVentaEnTransaccion'), [$res, $c->consultas]);

    // Si la base falla, devuelve error (no lanza): el caller deshace y la venta no queda a medias.
    $c = $ieLibro([7 => ie_fila('Cemento gris', '5.000')], true);
    $c->fallaEn = 'indicador_bien_servicio = 2';
    $lanzo = null;
    $logFalla = ie_con_log(static function () use ($moverVenta, $c, &$res, &$lanzo): void {
        try {
            $res = $moverVenta($c, [['product_id' => 7, 'quantity' => 2]]);
        } catch (Throwable $e) {
            $lanzo = $e;
        }
    });
    chk('moverVentaEnTransaccion si la base falla: devuelve [error, ...] sin lanzar, sin escribir y sin rollback propio',
        $lanzo === null && ($res[0] ?? null) === 'error' && $ieCuantas($c, 'INSERT INTO inventory_movements') === 0 && $c->eventos === [], [$res, $lanzo?->getMessage()]);

    // Orden global de bloqueo (revision de transacciones y concurrencia): el PUT que
    // reemplaza lineas revierte las viejas y vende las nuevas en DOS llamadas a
    // aplicarMovimientos, y cada una ordena solo SUS ids. Con old=[9] new=[4] se
    // bloqueaba 9 y despues 4, mientras una venta (o un e-CF) de {4, 9} bloquea 4 y
    // despues 9: se cruzan y MySQL mata a una. Antes de la reversa se bloquea la UNION
    // de los ids viejos y nuevos, de menor a mayor y en una sola pasada; las dos
    // llamadas siguientes solo vuelven a bloquear filas que la transaccion ya tiene.
    $ieOrdenProductos = [
        1 => ie_fila('Uno', '10.000'), 3 => ie_fila('Tres', '10.000'), 4 => ie_fila('Cuatro', '10.000'),
        5 => ie_fila('Cinco', '10.000'), 9 => ie_fila('Nueve', '10.000'),
    ];
    $c = $ieLibro($ieOrdenProductos, true);
    $res = $moverVenta($c, [['product_id' => 4, 'quantity' => 1]], [['product_id' => 9, 'quantity' => 1]]);
    $bl = $ieBloqueados($c);
    chk('orden de bloqueo, PUT old=[9] new=[4]: la primera pasada bloquea 4 ANTES que 9 (la union ordenada), no 9 y despues 4',
        array_slice($bl, 0, 2) === [4, 9], $bl);
    chk('orden de bloqueo, PUT old=[9] new=[4]: tras esa pasada solo repite ids que ya tenia (reversa del 9, venta del 4): 4, 9, 9, 4',
        $bl === [4, 9, 9, 4], $bl);
    chk('orden de bloqueo, PUT old=[9] new=[4]: sigue siendo success con DEVOLUCION del 9 y despues VENTA del 4, sin tocar la transaccion',
        $res === ['success', 2] && array_column($c->movimientos, ':tipo') === ['DEVOLUCION', 'VENTA']
        && array_column($c->movimientos, ':product_id') === [9, 4] && $c->eventos === [] && $c->enTransaccion === true, [$res, $c->movimientos, $c->eventos]);

    $c = $ieLibro($ieOrdenProductos, true);
    $res = $moverVenta($c,
        [['product_id' => 1, 'quantity' => 1], ['product_id' => 3, 'quantity' => 1]],
        [['product_id' => 3, 'quantity' => 1], ['product_id' => 5, 'quantity' => 1]]);
    $bl = $ieBloqueados($c);
    chk('orden de bloqueo, PUT old=[3,5] new=[1,3]: la primera pasada bloquea 1, 3 y 5 (cada id una vez, de menor a mayor)',
        array_slice($bl, 0, 3) === [1, 3, 5], $bl);
    chk('orden de bloqueo, PUT old=[3,5] new=[1,3]: despues solo repite ids ya bloqueados (reversa 3, 5; venta 1, 3)',
        $bl === [1, 3, 5, 3, 5, 1, 3] && $res === ['success', 4], [$bl, $res]);

    // Lineas en desorden dentro de cada lado: la union sale ordenada igual.
    $c = $ieLibro($ieOrdenProductos, true);
    $res = $moverVenta($c,
        [['product_id' => 9, 'quantity' => 1], ['product_id' => 1, 'quantity' => 1]],
        [['product_id' => 5, 'quantity' => 1], ['product_id' => 3, 'quantity' => 1]]);
    chk('orden de bloqueo, lineas en desorden: la primera pasada es 1, 3, 5, 9',
        array_slice($ieBloqueados($c), 0, 4) === [1, 3, 5, 9] && ($res[0] ?? null) === 'success', [$ieBloqueados($c), $res]);

    // Sin dos pasadas no hay nada que ordenar: no se agrega una pasada de mas.
    $c = $ieLibro($ieOrdenProductos, true);
    $res = $moverVenta($c, [['product_id' => 9, 'quantity' => 1], ['product_id' => 4, 'quantity' => 1]]);
    chk('orden de bloqueo, factura nueva (sin lineas viejas): una sola pasada ordenada, 4 y 9, sin pasada previa de mas',
        $ieBloqueados($c) === [4, 9] && ($res[0] ?? null) === 'success', [$ieBloqueados($c), $res]);
    $c = $ieLibro($ieOrdenProductos + [8 => ie_fila('Flete', null, '2')], true);
    $res = $moverVenta($c, [['product_id' => 4, 'quantity' => 1]], [['product_id' => 8, 'quantity' => 40]]);
    chk('orden de bloqueo, lineas viejas que no mueven (un servicio): sin reversa no hay segunda pasada, solo se bloquea el 4',
        $ieBloqueados($c) === [4] && $res === ['success', 1], [$ieBloqueados($c), $res]);

    // Un id que ya no existe no se reporta en la pasada previa: lo reporta aplicarMovimientos, con su mensaje de siempre.
    $c = $ieLibro($ieOrdenProductos, true);
    $logFantasma = ie_con_log(static function () use ($moverVenta, $c, &$res): void {
        $res = $moverVenta($c, [['product_id' => 4, 'quantity' => 1]], [['product_id' => 99, 'quantity' => 1]]);
    });
    chk('orden de bloqueo, una linea vieja de un producto que ya no existe: la pasada previa lo pasa de largo y la reversa da el error llano de siempre (sin escribir)',
        ($res[0] ?? null) === 'error' && count($res) === 2 && str_contains($res[1], 'ya no existe') && $ieCuantas($c, 'INSERT INTO inventory_movements') === 0
        && array_slice($ieBloqueados($c), 0, 2) === [4, 99] && $c->eventos === [], [$res, $ieBloqueados($c), $logFantasma]);

    // Si la pasada previa falla, devuelve [error, ...] sin lanzar, sin escribir y sin rollback propio.
    $c = $ieLibro($ieOrdenProductos, true);
    $c->fallaEn = 'FOR UPDATE';
    $lanzo = null;
    $logPrevia = ie_con_log(static function () use ($moverVenta, $c, &$res, &$lanzo): void {
        try {
            $res = $moverVenta($c, [['product_id' => 4, 'quantity' => 1]], [['product_id' => 9, 'quantity' => 1]]);
        } catch (Throwable $e) {
            $lanzo = $e;
        }
    });
    chk('orden de bloqueo, la pasada previa falla (interbloqueo o tiempo de espera): [error, mensaje] sin lanzar, sin escribir y sin rollback propio',
        $lanzo === null && ($res[0] ?? null) === 'error' && $ieCuantas($c, 'INSERT INTO inventory_movements') === 0
        && $ieCuantas($c, 'UPDATE products') === 0 && $c->eventos === [] && $c->enTransaccion === true && str_contains($logPrevia, '[inventario]'), [$res, $lanzo?->getMessage(), $logPrevia]);

    // Fuente: nunca maneja la transaccion; comparte el filtro de lineas con moverPorDocumento.
    $ieFuenteModelo = ie_fuente('src/Models/inventoryModel.php');
    preg_match('/public function moverVentaEnTransaccion\(.*?\n    \}\n/s', $ieFuenteModelo, $mVentaTx);
    chk('fuente: moverVentaEnTransaccion no contiene beginTransaction(, commit( ni rollBack(',
        isset($mVentaTx[0]) && !preg_match('/beginTransaction\(|->commit\(|->rollBack\(/', $mVentaTx[0]), $mVentaTx[0] ?? null);
    chk('fuente: moverVentaEnTransaccion revisa con verificarDisponibilidad y aplica con bloquear_sin_existencia',
        isset($mVentaTx[0]) && str_contains($mVentaTx[0], 'verificarDisponibilidad(') && str_contains($mVentaTx[0], "'bloquear_sin_existencia' => true")
        && strpos($mVentaTx[0], 'verificarDisponibilidad(') < strpos($mVentaTx[0], "'bloquear_sin_existencia' => true"));
    preg_match('/private function moverPorDocumento\(.*?\n    \}\n/s', $ieFuenteModelo, $mPorDoc);
    chk('fuente: lineasQueMueve(array $items, int $signo, bool $costoDeLinea) es privada y la usan moverPorDocumento y moverVentaEnTransaccion',
        preg_match('/private function lineasQueMueve\(array \$items, int \$signo, bool \$costoDeLinea\): array/', $ieFuenteModelo) === 1
        && isset($mPorDoc[0]) && str_contains($mPorDoc[0], '$this->lineasQueMueve(')
        && isset($mVentaTx[0]) && substr_count($mVentaTx[0], '$this->lineasQueMueve(') === 2);
    preg_match('/private function bloquearProductosEnOrden\(.*?\n    \}\n/s', $ieFuenteModelo, $mBloqueo);
    chk('fuente: moverVentaEnTransaccion llama a bloquearProductosEnOrden( UNA vez, ANTES de la reversa (DEVOLUCION) y de la revision, con las lineas viejas y las nuevas',
        isset($mVentaTx[0]) && substr_count($mVentaTx[0], '$this->bloquearProductosEnOrden(') === 1
        && strpos($mVentaTx[0], '$this->bloquearProductosEnOrden(') < strpos($mVentaTx[0], "'DEVOLUCION'")
        && strpos($mVentaTx[0], '$this->bloquearProductosEnOrden(') < strpos($mVentaTx[0], 'verificarDisponibilidad(')
        && preg_match('/bloquearProductosEnOrden\(array_merge\(\$devolver, \$descontar\)\)/', $mVentaTx[0]) === 1);
    chk('fuente: bloquearProductosEnOrden es privada, ordena los ids de menor a mayor (sort) y bloquea con SELECT ... FOR UPDATE sin abrir, confirmar ni deshacer transaccion',
        isset($mBloqueo[0]) && str_contains($mBloqueo[0], 'sort($ids)') && str_contains($mBloqueo[0], 'FROM products WHERE id = :id FOR UPDATE')
        && !preg_match('/beginTransaction\(|->commit\(|->rollBack\(/', $mBloqueo[0]), $mBloqueo[0] ?? null);
}

// --- Aviso SOBREVENTA en el camino de despues del commit ---
$ieSobreventa = static function (?array $tenant, string $stock, string $metodo, float $cantidad) use ($ieLibro, $ieModelo, $ieTenantActual): array {
    $c = $ieLibro([7 => ie_fila('Cemento gris', $stock)]);
    $ieTenantActual($tenant);
    $n = null;
    $log = ie_con_log(static function () use ($ieModelo, $c, $metodo, $cantidad, &$n): void {
        $m = $ieModelo($c);
        $n = $metodo === 'revertirVenta'
            ? $m->revertirVenta(41, [['product_id' => 7, 'quantity' => $cantidad]], 5)
            : $m->registrarVenta(41, [['product_id' => 7, 'quantity' => $cantidad]], '31', 5);
    });
    $ieTenantActual(null);
    return ['n' => $n, 'log' => $log, 'stock' => $c->productos[7]['stock'], 'eventos' => $c->eventos];
};
$ieEncendido = ['id' => 2, 'tipo' => 'app', 'inventario_estricto' => 1];
$ieApagado = ['id' => 2, 'tipo' => 'app', 'inventario_estricto' => 0];

$r = $ieSobreventa($ieEncendido, '2.000', 'registrarVenta', 3);
chk('SOBREVENTA: interruptor encendido y una venta deja saldo -1 -> "[inventario] SOBREVENTA factura 41 producto 7 saldo -1"',
    str_contains($r['log'], '[inventario] SOBREVENTA factura 41 producto 7 saldo -1') && $r['n'] === 1, $r);
chk('SOBREVENTA: la venta se registra igual que hoy (saldo -1, begin y commit, devuelve 1 movimiento)',
    (float) $r['stock'] === -1.0 && $r['eventos'] === ['begin', 'commit'] && $r['n'] === 1, $r);
$r = $ieSobreventa($ieApagado, '2.000', 'registrarVenta', 3);
chk('SOBREVENTA: interruptor apagado -> no se avisa, y el saldo negativo se escribe como hoy',
    !str_contains($r['log'], 'SOBREVENTA') && (float) $r['stock'] === -1.0 && $r['n'] === 1, $r);
$r = $ieSobreventa(null, '2.000', 'registrarVenta', 3);
chk('SOBREVENTA: sin empresa resuelta (instalacion de un solo tenant) -> no se avisa', !str_contains($r['log'], 'SOBREVENTA') && $r['n'] === 1, $r);
$r = $ieSobreventa($ieEncendido, '5.000', 'registrarVenta', 3);
chk('SOBREVENTA: interruptor encendido pero el saldo queda en 2 -> no se avisa', !str_contains($r['log'], 'SOBREVENTA') && (float) $r['stock'] === 2.0, $r);
$r = $ieSobreventa($ieEncendido, '3.000', 'registrarVenta', 3);
chk('SOBREVENTA: dejar el saldo exactamente en 0 no es sobreventa', !str_contains($r['log'], 'SOBREVENTA'), $r);
$r = $ieSobreventa($ieEncendido, '-4.000', 'revertirVenta', 1);
chk('SOBREVENTA: una devolucion que sigue dejando saldo negativo (-4 + 1) no es venta -> no se avisa', !str_contains($r['log'], 'SOBREVENTA') && (float) $r['stock'] === -3.0, $r);

// ---------------------------------------------------------------------------
// 4) El e-CF: inventoryModel::verificarVentaEcf (con la conexion falsa de la
//    seccion 2, que falla ante cualquier consulta cuando se le pide) y su
//    cableado en facturaController::emitirFacturaEcf, que se lee como texto
//    (incluirlo manda headers, valida el token y corre el router).
// ---------------------------------------------------------------------------
echo "\n== 4) e-CF: verificarVentaEcf y el cableado en emitirFacturaEcf ==\n";

if (!class_exists('IeCatalogoConexion', false) || !isset($ieInventario, $ieProductos, $ieTenantActual, $ieEncendido, $ieApagado)) {
    chk('la seccion 2 dejo su conexion falsa y los tenants de la seccion 3 para esta seccion', false);
} elseif (!method_exists(inventoryModel::class, 'verificarVentaEcf')) {
    chk('inventoryModel::verificarVentaEcf existe', false);
} else {
    $rv = new ReflectionMethod(inventoryModel::class, 'verificarVentaEcf');
    $firma = array_map(static fn(ReflectionParameter $p) => (string) $p->getType() . ' $' . $p->getName() . ($p->isOptional() ? ' = opcional' : ''), $rv->getParameters());
    chk('verificarVentaEcf(array $items, string $tipoEcf, ?int $reenvioDe): publica, de instancia, sin valores por defecto',
        $rv->isPublic() && !$rv->isStatic() && $firma === ['array $items', 'string $tipoEcf', '?int $reenvioDe'], $firma);

    /** Corre verificarVentaEcf con el tenant dado y la conexion dada; devuelve [resultado, excepcion|null]. */
    $ieVerificarEcf = static function (?array $tenant, IeCatalogoConexion $c, array $items, string $tipo, ?int $reenvio) use ($ieInventario, $ieTenantActual): array {
        $ieTenantActual($tenant);
        $res = null;
        $lanzo = null;
        try {
            $res = $ieInventario($c)->verificarVentaEcf($items, $tipo, $reenvio);
        } catch (Throwable $e) {
            $lanzo = $e;
        }
        $ieTenantActual(null);
        return [$res, $lanzo];
    };
    $faltaMucho = [['product_id' => 7, 'cantidad' => 50]];

    // --- Sin interruptor: nada que revisar, ni una consulta ---
    foreach ([
        'sin empresa resuelta (instalacion de un solo tenant)' => null,
        'con el interruptor en 0' => $ieApagado,
        'con la columna ausente (master sin migrar)' => ['id' => 2, 'tipo' => 'app'],
        'de una empresa de integracion con el interruptor en 1 (no tiene catalogo)' => ['id' => 9, 'tipo' => 'integracion', 'inventario_estricto' => 1],
    ] as $caso => $tenant) {
        $c = new IeCatalogoConexion($ieProductos, true);   // cualquier consulta lanza
        [$res, $lanzo] = $ieVerificarEcf($tenant, $c, $faltaMucho, '31', null);
        chk("verificarVentaEcf {$caso}: success sin ir a la base, aunque falte existencia",
            $lanzo === null && $res === ['success', []] && $c->consultas === [], [$res, $lanzo?->getMessage()]);
    }

    // --- Con interruptor: lo que NUNCA se revisa ---
    $c = new IeCatalogoConexion($ieProductos, true);
    [$res, $lanzo] = $ieVerificarEcf($ieEncendido, $c, $faltaMucho, '31', 15);
    chk('verificarVentaEcf con el interruptor encendido y un reenvio ($reenvioDe = 15): success sin ir a la base (es una obligacion fiscal por bienes ya vendidos, C5)',
        $lanzo === null && $res === ['success', []] && $c->consultas === [], [$res, $lanzo?->getMessage()]);
    foreach (['34' => 'nota de credito', '41' => 'compras'] as $tipo => $nombreTipo) {
        $c = new IeCatalogoConexion($ieProductos, true);
        [$res, $lanzo] = $ieVerificarEcf($ieEncendido, $c, $faltaMucho, (string) $tipo, null);
        chk("verificarVentaEcf con el interruptor encendido y un E{$tipo} ({$nombreTipo}, entrada): success sin ir a la base",
            $lanzo === null && $res === ['success', []] && $c->consultas === [], [$res, $lanzo?->getMessage()]);
    }

    // --- Con interruptor: lo que SI se revisa ---
    $c = new IeCatalogoConexion($ieProductos);
    [$res, $lanzo] = $ieVerificarEcf($ieEncendido, $c, [['product_id' => 7, 'cantidad' => 5]], '31', null);
    chk('verificarVentaEcf E31 con faltante: [error, mensaje, 422, [codigo STOCK_INSUFICIENTE, faltantes con la fila]] (la misma forma que verificarDisponibilidad)',
        $lanzo === null && count($res) === 4 && $res[0] === 'error' && $res[2] === 422
        && $res[1] === 'No hay existencia suficiente de «Cemento gris»: pides 5 y hay 2.'
        && ($res[3]['codigo'] ?? null) === 'STOCK_INSUFICIENTE'
        && ($res[3]['faltantes'] ?? null) === [['product_id' => 7, 'nombre' => 'Cemento gris', 'sku' => 'CEM-01', 'unidad_medida' => '43',
            'solicitado' => 5.0, 'disponible' => 2.0, 'faltante' => 3.0]], [$res, $lanzo?->getMessage()]);
    chk('verificarVentaEcf E31: UNA sola consulta de lectura, sin FOR UPDATE (no bloquea nada antes de reservar el e-NCF)',
        count($c->consultas) === 1 && str_contains($c->consultas[0]['sql'], 'FROM products WHERE id IN')
        && !str_contains(strtoupper($c->consultas[0]['sql']), 'FOR UPDATE'), $c->consultas);

    $c = new IeCatalogoConexion($ieProductos);
    [$res, $lanzo] = $ieVerificarEcf($ieEncendido, $c, [['product_id' => 7, 'cantidad' => 2]], '31', null);
    chk("verificarVentaEcf E31 cubierto (pide exactamente el stock): ['success', []]", $lanzo === null && $res === ['success', []], [$res, $lanzo?->getMessage()]);

    foreach (['32', '33', '43', '44', '45', '46', '47'] as $tipo) {
        [$res, $lanzo] = $ieVerificarEcf($ieEncendido, new IeCatalogoConexion($ieProductos), $faltaMucho, $tipo, null);
        chk("verificarVentaEcf E{$tipo} con faltante: se rechaza (check = decrement, sin lista de excepciones; C15)",
            $lanzo === null && ($res[0] ?? null) === 'error' && ($res[3]['codigo'] ?? null) === 'STOCK_INSUFICIENTE', [$res, $lanzo?->getMessage()]);
    }

    [$res, $lanzo] = $ieVerificarEcf($ieEncendido, new IeCatalogoConexion($ieProductos),
        [['product_id' => 9, 'cantidad' => 40], ['cantidad' => 3], ['product_id' => null, 'cantidad' => 9]], '31', null);
    chk('verificarVentaEcf: un servicio del catalogo y las lineas libres (sin product_id) pasan', $lanzo === null && $res === ['success', []], [$res, $lanzo?->getMessage()]);

    [$res, $lanzo] = $ieVerificarEcf($ieEncendido, new IeCatalogoConexion($ieProductos),
        [['product_id' => 7, 'cantidad' => 1.5], ['product_id' => 7, 'cantidad' => 1.5]], '31', null);
    chk('verificarVentaEcf suma las lineas del mismo producto: dos de 1.5 contra stock 2 -> solicitado 3, disponible 2, faltante 1',
        $lanzo === null && ($res[0] ?? null) === 'error' && ($res[3]['faltantes'][0]['solicitado'] ?? null) === 3.0
        && $res[3]['faltantes'][0]['disponible'] === 2.0 && $res[3]['faltantes'][0]['faltante'] === 1.0, [$res, $lanzo?->getMessage()]);

    [$res, $lanzo] = $ieVerificarEcf($ieEncendido, new IeCatalogoConexion($ieProductos), [['product_id' => 8, 'cantidad' => 1]], '31', null);
    chk('verificarVentaEcf: un Bien con stock NULL cuenta como 0 (disponible 0, faltante 1)',
        $lanzo === null && ($res[0] ?? null) === 'error' && ($res[3]['faltantes'][0]['disponible'] ?? null) === 0.0
        && $res[3]['faltantes'][0]['faltante'] === 1.0, [$res, $lanzo?->getMessage()]);

    // --- La cantidad se lee como la lee EcfItemMapper::map, que es la que luego descuenta ---
    [$res, $lanzo] = $ieVerificarEcf($ieEncendido, new IeCatalogoConexion($ieProductos), [['product_id' => 7, 'quantity' => 5]], '31', null);
    chk('cantidad: una linea con solo "quantity" (strict_input trae lo que trae) se entiende: pide 5',
        $lanzo === null && ($res[3]['faltantes'][0]['solicitado'] ?? null) === 5.0, [$res, $lanzo?->getMessage()]);
    [$res, $lanzo] = $ieVerificarEcf($ieEncendido, new IeCatalogoConexion($ieProductos), [['product_id' => 7, 'cantidad' => 2, 'quantity' => 99]], '31', null);
    chk('cantidad: si vienen "cantidad" y "quantity" manda "cantidad" (como EcfItemMapper::map; calcularFaltantes mandaria quantity)',
        $lanzo === null && $res === ['success', []], [$res, $lanzo?->getMessage()]);
    [$res, $lanzo] = $ieVerificarEcf($ieEncendido, new IeCatalogoConexion($ieProductos), [['product_id' => 7]], '31', null);
    chk('cantidad: una linea sin cantidad cuenta 1 (como map() y como lo que descuenta), no 0: stock 2 pasa',
        $lanzo === null && $res === ['success', []], [$res, $lanzo?->getMessage()]);
    [$res, $lanzo] = $ieVerificarEcf($ieEncendido, new IeCatalogoConexion($ieProductos), [['product_id' => 8, 'cantidad' => null]], '31', null);
    chk('cantidad: "cantidad" null tambien cuenta 1: contra un stock NULL falta 1, y no es un 422 llano de cantidad invalida',
        $lanzo === null && ($res[3]['faltantes'][0]['solicitado'] ?? null) === 1.0, [$res, $lanzo?->getMessage()]);
    [$res, $lanzo] = $ieVerificarEcf($ieEncendido, new IeCatalogoConexion($ieProductos), [(object) ['product_id' => 7, 'cantidad' => 5]], '31', null);
    chk('lineas como objetos: se entienden (EcfItemMapper::map las recibe como arreglos, pero la verificacion no se cae)',
        $lanzo === null && count($res[3]['faltantes'] ?? []) === 1, [$res, $lanzo?->getMessage()]);

    // --- Lo que no es un faltante: 422 llano, nunca el error de existencia ---
    $logIgnorado = ie_con_log(static function () use ($ieVerificarEcf, $ieEncendido, $ieProductos, &$resInexistente, &$resCero): void {
        [$resInexistente] = $ieVerificarEcf($ieEncendido, new IeCatalogoConexion($ieProductos), [['product_id' => 99, 'cantidad' => 1]], '31', null);
        [$resCero] = $ieVerificarEcf($ieEncendido, new IeCatalogoConexion($ieProductos), [['product_id' => 7, 'cantidad' => 0]], '31', null);
    });
    chk('verificarVentaEcf: producto inexistente -> 422 llano (3 elementos, sin codigo ni faltantes)',
        count($resInexistente) === 3 && $resInexistente[0] === 'error' && $resInexistente[2] === 422
        && !str_contains($resInexistente[1], 'existencia suficiente'), $resInexistente);
    chk('verificarVentaEcf: cantidad 0 en un producto -> 422 llano que lo nombra (3 elementos)',
        count($resCero) === 3 && $resCero[2] === 422 && str_contains($resCero[1], '«Cemento gris»') && !str_contains($resCero[1], 'existencia suficiente'), $resCero);

    // --- Si la base falla, la excepcion sale: el controlador decide (un resguardo no frena la venta) ---
    $c = new IeCatalogoConexion($ieProductos, true);
    [$res, $lanzo] = $ieVerificarEcf($ieEncendido, $c, [['product_id' => 7, 'cantidad' => 1]], '31', null);
    chk('verificarVentaEcf con el interruptor encendido y la base caida: la PDOException sale tal cual (emitirFacturaEcf la atrapa y deja vender)',
        $lanzo instanceof PDOException && $res === null);

    $ieTenantActual(null);
}

// --- Cableado en emitirFacturaEcf (texto del controlador) ---
$ieCtl = ie_fuente('src/Controllers/facturaController.php');
chk('controlador: se lee facturaController.php', $ieCtl !== '');
$ieEmitir = preg_match('/\nfunction emitirFacturaEcf\(.*?\n\}\n/s', $ieCtl, $mEmitir) === 1 ? $mEmitir[0] : '';
chk('controlador: se encontro emitirFacturaEcf', $ieEmitir !== '');
$posGuarda = strpos($ieEmitir, 'No se pudo calcular el total de la factura');
$posVerif = strpos($ieEmitir, 'verificarVentaEcf(');
$posPayload = strpos($ieEmitir, '$payload = [');
$posEmitir = strpos($ieEmitir, '->emitir(');

chk('controlador: ->emitir( aparece UNA sola vez en el archivo (la regla no agrega una segunda emision)',
    substr_count($ieCtl, '->emitir(') === 1);
chk('controlador: verificarVentaEcf( aparece UNA sola vez en el archivo, dentro de emitirFacturaEcf',
    substr_count($ieCtl, 'verificarVentaEcf(') === 1 && $posVerif !== false);
chk('controlador: la verificacion va DESPUES de la guarda del total, antes de armar el payload y antes de emitir (antes de gastar el e-NCF)',
    $posGuarda !== false && $posVerif !== false && $posPayload !== false && $posEmitir !== false
    && $posGuarda < $posVerif && $posVerif < $posPayload && $posPayload < $posEmitir, [$posGuarda, $posVerif, $posPayload, $posEmitir]);
chk('controlador: corre tambien con strict_input (no hay ningun strictInput entre la guarda del total y la llamada)',
    $posGuarda !== false && $posVerif !== false && $posGuarda < $posVerif
    && !str_contains(substr($ieEmitir, $posGuarda, $posVerif - $posGuarda), 'strictInput'));
chk('controlador: la llamada es inventarioModelo()->verificarVentaEcf($items, $tipoEcf, $reenvioDe): los items ya normalizados, el tipo y el reenvio',
    str_contains($ieEmitir, 'inventarioModelo()->verificarVentaEcf($items, $tipoEcf, $reenvioDe)'));
chk('controlador: un Throwable de la verificacion no frena la venta (try / catch Throwable que anota "[inventario]" y sigue con success)',
    preg_match('/try \{\s*\$existencia = inventarioModelo\(\)->verificarVentaEcf\([^;]*\);\s*\} catch \(Throwable \$e\) \{\s*error_log\(\'\[inventario\][^;]*;\s*\$existencia = \[\'success\', \[\]\];\s*\}/s', $ieEmitir) === 1);

preg_match('/if \(\$existencia\[0\] !== \'success\'\) \{.*?\n    \}\n/s', $ieEmitir, $mRechazo);
$ieRechazo = $mRechazo[0] ?? '';
chk('controlador: el rechazo se audita (facturas / EMIT / success false, con el texto) ANTES de responder con respondExistencia y return',
    $ieRechazo !== ''
    && str_contains($ieRechazo, "'module' => 'facturas'") && str_contains($ieRechazo, "'action' => 'EMIT'")
    && str_contains($ieRechazo, "'success' => false") && str_contains($ieRechazo, "'error_message' => ")
    && strpos($ieRechazo, 'AuditLogger::log(') < strpos($ieRechazo, 'respondExistencia($existencia)')
    && strpos($ieRechazo, 'respondExistencia($existencia)') < strrpos($ieRechazo, 'return;'), $ieRechazo);

// respondExistencia: se extrae del texto y se corre de verdad (HTTP 422 y el cuerpo de respuestaExistencia).
$ieResp = preg_match('/\nfunction respondExistencia\(.*?\n\}\n/s', $ieCtl, $mResp) === 1 ? $mResp[0] : '';
chk('controlador: respondExistencia(array $res): void existe', str_contains($ieResp, 'function respondExistencia(array $res): void'), $ieResp);
chk('controlador: respondExistencia fija 422 y emite el cuerpo de inventoryModel::respuestaExistencia($res) (nunca 409)',
    str_contains($ieResp, 'http_response_code(422)') && str_contains($ieResp, 'json_encode(inventoryModel::respuestaExistencia($res))')
    && !str_contains($ieResp, '409'));
if ($ieResp !== '') {
    // La funcion extraida del texto del controlador se corre en OTRO proceso de PHP:
    // en este ya hubo salida (los "[OK  ]"), y http_response_code() no deja fijar el
    // codigo con las cabeceras enviadas. El proceso hijo imprime el cuerpo, un salto
    // de linea y el codigo HTTP que quedo fijado.
    $ieCorrerResp = static function (array $res) use ($ieResp): array {
        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ie_resp_' . getmypid() . '_' . mt_rand() . '.php';
        file_put_contents($tmp, "<?php\nrequire_once " . var_export(dirname(__DIR__) . '/src/Models/inventoryModel.php', true) . ";\n"
            . $ieResp . "\nrespondExistencia(" . var_export($res, true) . ");\necho \"\\n\" . http_response_code();\n");
        $proc = proc_open([PHP_BINARY, $tmp], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $salida = '';
        if (is_resource($proc)) {
            $salida = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
        }
        unlink($tmp);
        $partes = explode("\n", rtrim($salida, "\n"));
        $codigo = (int) array_pop($partes);
        return [$codigo, json_decode(implode("\n", $partes), true), $salida];
    };
    $ieFaltantes = [['product_id' => 12, 'nombre' => 'Cemento gris', 'sku' => 'CEM-01', 'unidad_medida' => '43', 'solicitado' => 5.0, 'disponible' => 2.0, 'faltante' => 3.0]];
    [$ieCodigo, $ieJson, $ieSalida] = $ieCorrerResp(['error', 'No hay existencia suficiente de «Cemento gris»: pides 5 y hay 2.', 422, ['codigo' => 'STOCK_INSUFICIENTE', 'faltantes' => $ieFaltantes]]);
    chk('respondExistencia corrida: HTTP 422 y {status:false, error, codigo:STOCK_INSUFICIENTE, data.faltantes con numeros}',
        $ieCodigo === 422 && is_array($ieJson) && $ieJson['status'] === false && $ieJson['codigo'] === 'STOCK_INSUFICIENTE'
        && $ieJson['error'] === 'No hay existencia suficiente de «Cemento gris»: pides 5 y hay 2.'
        && ($ieJson['data']['faltantes'][0]['product_id'] ?? null) === 12 && is_numeric($ieJson['data']['faltantes'][0]['faltante'] ?? null)
        && !is_string($ieJson['data']['faltantes'][0]['solicitado'] ?? null), $ieSalida);
    [$ieCodigo, $ieJson, $ieSalida] = $ieCorrerResp(['error', 'Uno de los productos ya no existe en el catálogo.', 422]);
    chk('respondExistencia corrida con el caso llano: HTTP 422 y solo {status:false, error} (sin codigo ni data)',
        $ieCodigo === 422 && $ieJson === ['status' => false, 'error' => 'Uno de los productos ya no existe en el catálogo.'], $ieSalida);
}

// El descuento de e-CF no se toca: moverStockFacturaEcf sigue igual y la revision no vive ahi.
$ieMover = preg_match('/\nfunction moverStockFacturaEcf\(.*?\n\}\n/s', $ieCtl, $mMover) === 1 ? $mMover[0] : '';
chk('moverStockFacturaEcf sin cambios: no mueve en SIN_STOCK ni EN_VUELO, pregunta yaMovioDocumento y despues registrarVenta($facturaId, $items, $tipoEcf, $userId); nunca lanza',
    $ieMover !== '' && str_contains($ieMover, 'EstadosEcf::SIN_STOCK') && str_contains($ieMover, 'EstadosEcf::EN_VUELO')
    && strpos($ieMover, "yaMovioDocumento('factura', \$facturaId)") < strpos($ieMover, '$inventario->registrarVenta($facturaId, $items, $tipoEcf, $userId);')
    && str_contains($ieMover, 'catch (Throwable $e)'), $ieMover);
chk('moverStockFacturaEcf no revisa existencia (la revision es previa; el descuento queda donde estaba)',
    $ieMover !== '' && !str_contains($ieMover, 'verificar') && !str_contains($ieMover, 'inventarioEstricto') && !str_contains($ieMover, 'respondExistencia'));
chk('controlador: registrarVenta se llama una sola vez (solo desde moverStockFacturaEcf)', substr_count($ieCtl, '->registrarVenta(') === 1);

// ---------------------------------------------------------------------------
// 5) Factura simple, camino atomico: facturaModel::createFacturaSimple y
//    updateFacturaSimple con el callback $enTransaccion (corre despues de insertar
//    la factura y ANTES de escribir las lineas, porque el FK de
//    factura_items.product_id haria saltar el INSERT de un producto inexistente
//    antes de que el callback diera su 422 llano; y siempre antes del commit; un
//    error suyo deshace la factura), con una conexion falsa que anota el orden de
//    cada escritura y de begin / commit / rollback, que rechaza un product_id
//    inexistente como el FK y un rollBack() sin transaccion como PDO; y su
//    cableado en facturaSimpleController, que se lee como texto
//    (incluirlo manda headers, valida el token y corre el router). El descuento
//    real contra MySQL lo prueba tools/test_inventario_stock_db.php (tarea 8).
// ---------------------------------------------------------------------------
echo "\n== 5) Factura simple: camino atomico (callback dentro de la transaccion) ==\n";

require_once __DIR__ . '/../src/Models/facturaModel.php';

final class IeFacSentencia extends PDOStatement
{
    public ?IeFacConexion $con = null;
    public string $sql = '';
    /** @var list<array<int|string,mixed>> */
    public array $filas = [];

    public function execute(?array $params = null): bool
    {
        $params ??= [];
        $sql = preg_replace('/\s+/', ' ', $this->sql);
        $this->con->consultas[] = ['sql' => $sql, 'params' => $params];
        $this->filas = [];
        // Las escrituras que importan para el orden: factura, lineas y su borrado.
        foreach (['INSERT INTO facturas (', 'INSERT INTO factura_items', 'DELETE FROM factura_items', 'UPDATE facturas SET'] as $marca) {
            if (str_starts_with($sql, $marca)) {
                $this->con->orden[] = $marca;
            }
        }
        // El FK factura_items_product_fk: un product_id que no existe hace saltar el
        // INSERT de la linea (1452), antes de cualquier otra cosa.
        if (str_starts_with($sql, 'INSERT INTO factura_items')
            && in_array($params[':product_id'] ?? null, $this->con->productosInexistentes, true)) {
            throw new PDOException('SQLSTATE[23000]: Integrity constraint violation: 1452 Cannot add or update a child row: a foreign key constraint fails (`factura_items_product_fk`)');
        }
        if (str_starts_with($sql, 'SELECT * FROM facturas WHERE id')) {
            $this->filas[] = $this->con->factura;                       // lectura previa del PUT
        } elseif (str_contains($sql, 'FROM facturas f LEFT JOIN clients')) {
            $this->filas[] = $this->con->factura + ['company_name' => null];   // getFacturaSimple
        } elseif (str_contains($sql, 'FROM factura_items WHERE factura_id')) {
            $this->filas = $this->con->itemsGuardados;                  // getFacturaItems
        } elseif (str_contains($sql, 'FROM products WHERE id IN')) {
            foreach ($params as $id) {                                  // unidadesDeProductos
                $this->filas[] = ['id' => (string) $id, 'unidad_medida' => '43'];
            }
        }
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return array_shift($this->filas) ?? false;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->filas;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        $fila = array_shift($this->filas);
        return $fila === null ? false : (array_values($fila)[$column] ?? false);
    }
}

final class IeFacConexion extends PDO
{
    public array $consultas = [];
    /** Escrituras y begin / commit / rollback, en el orden en que ocurrieron. */
    public array $orden = [];
    public bool $enTransaccion = false;
    /** @var list<int> product_id que no existen en products: su INSERT en factura_items viola el FK */
    public array $productosInexistentes = [];

    public function __construct(public array $factura, public array $itemsGuardados = [])
    {
        // Sin parent::__construct: nunca abre una conexion.
    }

    private function sentencia(string $sql): IeFacSentencia
    {
        $s = new IeFacSentencia();
        $s->con = $this;
        $s->sql = $sql;
        return $s;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return $this->sentencia($query);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $sql = preg_replace('/\s+/', ' ', $query);
        $this->consultas[] = ['sql' => $sql, 'params' => []];
        $s = $this->sentencia($query);
        if (str_contains($sql, 'GET_LOCK')) {
            $s->filas = [[1]];                       // el candado de numeracion se toma
        } elseif (str_contains($sql, 'MAX(CAST')) {
            $s->filas = [['max_num' => '6']];        // la ultima factura simple es la 0006
        }
        return $s;
    }

    public function beginTransaction(): bool
    {
        $this->orden[] = 'BEGIN';
        $this->enTransaccion = true;
        return true;
    }

    public function commit(): bool
    {
        $this->orden[] = 'COMMIT';
        $this->enTransaccion = false;
        return true;
    }

    public function rollBack(): bool
    {
        // Como PDO: sin transaccion (por ejemplo, el servidor ya la deshizo por un
        // interbloqueo) rollBack() lanza.
        if (!$this->enTransaccion) {
            throw new PDOException('There is no active transaction');
        }
        $this->orden[] = 'ROLLBACK';
        $this->enTransaccion = false;
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->enTransaccion;
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return '41';
    }
}

$ieFacFila = ['id' => '41', 'no_factura' => '0007-191026', 'date' => '2026-10-19 10:00:00', 'client_id' => null, 'client_name' => 'Ferretería Norte',
    'NCF' => null, 'tipo_pago' => 1, 'dias_credito' => null, 'total' => '250.00', 'user_id' => 5, 'tipo_ecf' => null, 'pos_idempotency_key' => null];
$ieFacModelo = static function (array $fila = []) use ($ieFacFila): array {
    $ref = new ReflectionClass(facturaModel::class);
    $m = $ref->newInstanceWithoutConstructor();
    $c = new IeFacConexion($fila + $ieFacFila);
    $ref->getProperty('conexion')->setValue($m, $c);
    return [$m, $c];
};
$ieFacLineas = [
    ['product_id' => 7, 'description' => 'Cemento gris', 'quantity' => 2, 'amount' => 100],
    ['description' => 'Flete', 'quantity' => 1, 'amount' => 50],
];
$ieFacDatos = ['client_name' => 'Ferretería Norte', 'user_id' => 5, 'items' => $ieFacLineas];
$ieFacStock = ['codigo' => 'STOCK_INSUFICIENTE', 'faltantes' => [['product_id' => 7, 'nombre' => 'Cemento gris', 'sku' => 'CEM-01', 'unidad_medida' => '43',
    'solicitado' => 5.0, 'disponible' => 1.0, 'faltante' => 4.0]]];
$ieFacRechazo = ['error', 'No hay existencia suficiente de «Cemento gris»: pides 5 y hay 1.', 422, $ieFacStock];
/** Un callback que anota lo que recibe y en que momento corre, y devuelve $respuesta. */
$ieFacCallback = static function (IeFacConexion $c, array &$visto, ?array $respuesta = null): callable {
    return static function (PDO $conexion, int $facturaId, array $items) use ($c, &$visto, $respuesta): ?array {
        $c->orden[] = 'CALLBACK';
        $visto = ['conexion' => $conexion, 'factura_id' => $facturaId, 'items' => $items, 'en_transaccion' => $conexion->inTransaction()];
        return $respuesta;
    };
};
$ieFacItemsEsperados = [
    ['product_id' => 7, 'quantity' => 2.0],
    ['product_id' => null, 'quantity' => 1.0],
];
$ieFacSoloClaves = static fn(array $items): array => array_map(
    static fn(array $it) => ['product_id' => $it['product_id'], 'quantity' => $it['quantity']], $items);
$ieFacSql = static fn(IeFacConexion $c, string $texto): int => count(array_filter($c->consultas, static fn(array $q) => str_contains($q['sql'], $texto)));

// --- Modelo: createFacturaSimple ---
$rfCrear = new ReflectionMethod(facturaModel::class, 'createFacturaSimple');
$rfActualizar = new ReflectionMethod(facturaModel::class, 'updateFacturaSimple');
$ieFacTipoCb = static function (ReflectionMethod $m): array {
    $p = $m->getParameters();
    $ultimo = end($p);
    return [count($p), $ultimo->getName(), (string) $ultimo->getType(), $ultimo->isOptional(), $ultimo->isOptional() ? $ultimo->getDefaultValue() : 'n/a'];
};
chk('createFacturaSimple(array $data, ?callable $enTransaccion = null): el segundo argumento es opcional y por defecto null',
    $ieFacTipoCb($rfCrear) === [2, 'enTransaccion', '?callable', true, null], $ieFacTipoCb($rfCrear));
chk('updateFacturaSimple(int $id, array $data, ?callable $enTransaccion = null): el tercero es opcional y por defecto null',
    $ieFacTipoCb($rfActualizar) === [3, 'enTransaccion', '?callable', true, null], $ieFacTipoCb($rfActualizar));

[$m, $c] = $ieFacModelo();
$res = $m->createFacturaSimple($ieFacDatos);
chk('create sin callback: el camino de hoy (begin, factura, lineas, commit) y devuelve success con la factura',
    ($res[0] ?? null) === 'success' && ($res[1]['id'] ?? null) === '41'
    && $c->orden === ['BEGIN', 'INSERT INTO facturas (', 'INSERT INTO factura_items', 'INSERT INTO factura_items', 'COMMIT'], [$res, $c->orden]);

$visto = [];
[$m, $c] = $ieFacModelo();
$res = $m->createFacturaSimple($ieFacDatos, $ieFacCallback($c, $visto));
chk('create con callback que devuelve null: corre DESPUES de insertar la factura y ANTES de escribir las lineas (y del commit); la factura se guarda',
    ($res[0] ?? null) === 'success' && $c->orden === ['BEGIN', 'INSERT INTO facturas (', 'CALLBACK', 'INSERT INTO factura_items', 'INSERT INTO factura_items', 'COMMIT'], [$res, $c->orden]);
chk('create: el callback recibe la conexion del propio modelo (con la transaccion abierta), el id de la factura recien insertada y las lineas normalizadas, las mismas que se van a insertar',
    ($visto['conexion'] ?? null) === $c && ($visto['en_transaccion'] ?? null) === true && ($visto['factura_id'] ?? null) === 41
    && $ieFacSoloClaves($visto['items'] ?? []) === $ieFacItemsEsperados, $visto['items'] ?? $visto);
chk('create: las lineas del callback son las que se insertaron (mismo product_id y cantidad en cada INSERT INTO factura_items)',
    array_map(static fn(array $q) => [$q['params'][':product_id'], $q['params'][':quantity']],
        array_values(array_filter($c->consultas, static fn(array $q) => str_starts_with($q['sql'], 'INSERT INTO factura_items'))))
    === [[7, 2.0], [null, 1.0]]);

$visto = [];
[$m, $c] = $ieFacModelo();
$res = $m->createFacturaSimple($ieFacDatos, $ieFacCallback($c, $visto, $ieFacRechazo));
chk('create con error del callback: ROLLBACK (nunca COMMIT), sin escribir ninguna linea, y devuelve el MISMO arreglo, sin tocarlo',
    $res === $ieFacRechazo && $c->orden === ['BEGIN', 'INSERT INTO facturas (', 'CALLBACK', 'ROLLBACK']
    && $c->enTransaccion === false, [$res, $c->orden]);
chk('create con error del callback: el candado de numeracion se suelta igual (RELEASE_LOCK)',
    $ieFacSql($c, 'GET_LOCK') === 1 && $ieFacSql($c, 'RELEASE_LOCK') === 1, $c->consultas);

$visto = [];
[$m, $c] = $ieFacModelo();
$res = $m->createFacturaSimple($ieFacDatos, $ieFacCallback($c, $visto, ['error', 'No hay un producto «Tubo».', 422]));
chk('create con error llano del callback (3 elementos, sin faltantes): se devuelve tal cual y se deshace',
    $res === ['error', 'No hay un producto «Tubo».', 422] && $c->orden[count($c->orden) - 1] === 'ROLLBACK' && !in_array('COMMIT', $c->orden, true), [$res, $c->orden]);

[$m, $c] = $ieFacModelo();
$logCb = ie_con_log(static function () use ($m, $c, $ieFacDatos, &$res): void {
    $res = $m->createFacturaSimple($ieFacDatos, static function (PDO $conexion, int $facturaId, array $items): ?array {
        throw new PDOException('se cayo la conexion');
    });
});
chk('create con callback que lanza PDOException: se deshace (ROLLBACK, sin COMMIT) y responde el mensaje de siempre, sin el texto de PDO',
    ($res[0] ?? null) === 'error' && str_starts_with($res[1] ?? '', 'No se pudo guardar la factura.') && !str_contains($res[1] ?? '', 'conexion')
    && $c->orden[count($c->orden) - 1] === 'ROLLBACK' && !in_array('COMMIT', $c->orden, true) && str_contains($logCb, 'se cayo la conexion'), [$res, $c->orden]);

// Interbloqueo: el servidor ya deshizo la transaccion cuando el callback devuelve su error.
// rollBack() lanzaria 'There is no active transaction' y taparia el mensaje del callback
// con el error generico: el modelo no lo llama si ya no hay transaccion.
[$m, $c] = $ieFacModelo();
$res = $m->createFacturaSimple($ieFacDatos, static function (PDO $conexion, int $facturaId, array $items) use ($c, $ieFacRechazo): ?array {
    $c->orden[] = 'CALLBACK';
    $c->enTransaccion = false;       // el servidor eligio esta transaccion como victima
    return $ieFacRechazo;
});
chk('create: si el callback devuelve su error con la transaccion ya deshecha por el servidor, NO llama a rollBack() y devuelve el error del callback (no el generico)',
    $res === $ieFacRechazo && $c->orden === ['BEGIN', 'INSERT INTO facturas (', 'CALLBACK'] && $c->enTransaccion === false, [$res, $c->orden]);

// --- Producto inexistente (spec 4.2 y C17): el 422 llano del callback, no el FK ---
// factura_items.product_id tiene FK a products: si las lineas se escribieran ANTES del
// callback, un product_id inexistente saltaria como PDOException (1452) y el usuario veria
// el 400 generico en vez del 422 llano. Este callback hace lo que verificarDisponibilidad.
$ieFacPlano = ['error', 'Uno de los productos ya no existe en el catálogo.', 422];
$ieFacCallbackCatalogo = static function (IeFacConexion $c, array &$visto) use ($ieFacPlano): callable {
    return static function (PDO $conexion, int $facturaId, array $items) use ($c, &$visto, $ieFacPlano): ?array {
        $c->orden[] = 'CALLBACK';
        $visto = ['factura_id' => $facturaId, 'items' => $items];
        foreach ($items as $it) {
            if (in_array($it['product_id'] ?? null, $c->productosInexistentes, true)) {
                return $ieFacPlano;
            }
        }
        return null;
    };
};
$ieFacLineasFantasma = [
    ['product_id' => 99, 'description' => 'Producto borrado', 'quantity' => 1, 'amount' => 100],
    ['description' => 'Flete', 'quantity' => 1, 'amount' => 50],
];
$ieFacDatosFantasma = ['client_name' => 'Ferretería Norte', 'user_id' => 5, 'items' => $ieFacLineasFantasma];

$visto = [];
[$m, $c] = $ieFacModelo();
$c->productosInexistentes = [99];
$res = $m->createFacturaSimple($ieFacDatosFantasma, $ieFacCallbackCatalogo($c, $visto));
chk('create con producto inexistente y callback: el callback corre ANTES de las lineas, asi que sale su 422 llano (sin codigo ni faltantes) y no el 400 generico del FK',
    $res === $ieFacPlano && ($visto['factura_id'] ?? null) === 41, [$res, $c->orden]);
chk('create con producto inexistente y callback: ninguna linea se escribe (ni siquiera se intenta el INSERT que violaria el FK), ROLLBACK y nunca COMMIT',
    $c->orden === ['BEGIN', 'INSERT INTO facturas (', 'CALLBACK', 'ROLLBACK'] && $ieFacSql($c, 'INSERT INTO factura_items') === 0, [$c->orden, $c->consultas]);

[$m, $c] = $ieFacModelo();
$c->productosInexistentes = [99];
$res = [];
$logFk = ie_con_log(static function () use ($m, $ieFacDatosFantasma, &$res): void {
    $res = $m->createFacturaSimple($ieFacDatosFantasma);
});
chk('create con producto inexistente SIN callback (interruptor apagado): el mismo camino y el mismo mensaje generico de hoy; el texto del FK solo va al log',
    ($res[0] ?? null) === 'error' && !isset($res[2]) && str_starts_with($res[1] ?? '', 'No se pudo guardar la factura.')
    && !str_contains($res[1] ?? '', 'foreign key') && str_contains($logFk, 'foreign key constraint fails')
    && $c->orden === ['BEGIN', 'INSERT INTO facturas (', 'INSERT INTO factura_items', 'ROLLBACK'], [$res, $c->orden]);

// --- Modelo: updateFacturaSimple ---
$visto = [];
[$m, $c] = $ieFacModelo();
$res = $m->updateFacturaSimple(41, ['client_name' => 'Otro nombre'], $ieFacCallback($c, $visto, $ieFacRechazo));
chk('update SIN items: el callback no corre (un PUT sin lineas no mueve existencia) y se guarda con commit',
    ($res[0] ?? null) === 'success' && $visto === [] && !in_array('CALLBACK', $c->orden, true) && $c->orden === ['BEGIN', 'UPDATE facturas SET', 'COMMIT'], [$res, $c->orden]);

$visto = [];
[$m, $c] = $ieFacModelo();
$res = $m->updateFacturaSimple(41, ['items' => $ieFacLineas], $ieFacCallback($c, $visto));
chk('update con items: borra las lineas viejas, corre el callback, escribe las nuevas y DESPUES hace commit',
    ($res[0] ?? null) === 'success'
    && $c->orden === ['BEGIN', 'UPDATE facturas SET', 'DELETE FROM factura_items', 'CALLBACK', 'INSERT INTO factura_items', 'INSERT INTO factura_items', 'COMMIT'], [$res, $c->orden]);
chk('update: el callback recibe la conexion del modelo con la transaccion abierta, el id de la factura (no uno nuevo) y las lineas normalizadas',
    ($visto['conexion'] ?? null) === $c && ($visto['en_transaccion'] ?? null) === true && ($visto['factura_id'] ?? null) === 41
    && $ieFacSoloClaves($visto['items'] ?? []) === $ieFacItemsEsperados, $visto['items'] ?? $visto);

$visto = [];
[$m, $c] = $ieFacModelo();
$res = $m->updateFacturaSimple(41, ['items' => $ieFacLineas], $ieFacCallback($c, $visto, $ieFacRechazo));
chk('update con error del callback: ROLLBACK (nunca COMMIT), sin escribir ninguna linea nueva, y devuelve el MISMO arreglo, sin tocarlo',
    $res === $ieFacRechazo && $c->orden === ['BEGIN', 'UPDATE facturas SET', 'DELETE FROM factura_items', 'CALLBACK', 'ROLLBACK']
    && $c->enTransaccion === false, [$res, $c->orden]);

[$m, $c] = $ieFacModelo();
$res = $m->updateFacturaSimple(41, ['items' => $ieFacLineas], static function (PDO $conexion, int $facturaId, array $items) use ($c, $ieFacRechazo): ?array {
    $c->orden[] = 'CALLBACK';
    $c->enTransaccion = false;       // el servidor eligio esta transaccion como victima de un interbloqueo
    return $ieFacRechazo;
});
chk('update: si el callback devuelve su error con la transaccion ya deshecha por el servidor, NO llama a rollBack() y devuelve el error del callback (no el generico)',
    $res === $ieFacRechazo && $c->orden === ['BEGIN', 'UPDATE facturas SET', 'DELETE FROM factura_items', 'CALLBACK'] && $c->enTransaccion === false, [$res, $c->orden]);

// Producto inexistente en un PUT (spec 4.2 y C17): el 422 llano, no el FK.
$visto = [];
[$m, $c] = $ieFacModelo();
$c->productosInexistentes = [99];
$res = $m->updateFacturaSimple(41, ['items' => $ieFacLineasFantasma], $ieFacCallbackCatalogo($c, $visto));
chk('update con producto inexistente y callback: sale el 422 llano del callback (no el 400 generico del FK), ninguna linea nueva se escribe, ROLLBACK y nunca COMMIT',
    $res === $ieFacPlano && ($visto['factura_id'] ?? null) === 41
    && $c->orden === ['BEGIN', 'UPDATE facturas SET', 'DELETE FROM factura_items', 'CALLBACK', 'ROLLBACK'] && $ieFacSql($c, 'INSERT INTO factura_items') === 0, [$res, $c->orden]);

[$m, $c] = $ieFacModelo();
$c->productosInexistentes = [99];
$res = [];
$logFk = ie_con_log(static function () use ($m, $ieFacLineasFantasma, &$res): void {
    $res = $m->updateFacturaSimple(41, ['items' => $ieFacLineasFantasma]);
});
chk('update con producto inexistente SIN callback (interruptor apagado): el mismo mensaje generico de hoy; el texto del FK solo va al log',
    ($res[0] ?? null) === 'error' && !isset($res[2]) && str_starts_with($res[1] ?? '', 'No se pudieron guardar los cambios de la factura.')
    && !str_contains($res[1] ?? '', 'foreign key') && str_contains($logFk, 'foreign key constraint fails')
    && $c->orden === ['BEGIN', 'UPDATE facturas SET', 'DELETE FROM factura_items', 'INSERT INTO factura_items', 'ROLLBACK'], [$res, $c->orden]);

[$m, $c] = $ieFacModelo();
$res = $m->updateFacturaSimple(41, ['items' => $ieFacLineas]);
chk('update sin callback: el camino de hoy (begin, update, borrado, lineas, commit)',
    ($res[0] ?? null) === 'success'
    && $c->orden === ['BEGIN', 'UPDATE facturas SET', 'DELETE FROM factura_items', 'INSERT INTO factura_items', 'INSERT INTO factura_items', 'COMMIT'], [$res, $c->orden]);

$visto = [];
[$m, $c] = $ieFacModelo(['tipo_ecf' => '31']);
$res = $m->updateFacturaSimple(41, ['items' => $ieFacLineas], $ieFacCallback($c, $visto, null));
chk('update de un e-CF emitido: se rechaza antes de abrir la transaccion y el callback nunca corre',
    ($res[0] ?? null) === 'error' && $visto === [] && $c->orden === [], [$res, $c->orden]);
$visto = [];
[$m, $c] = $ieFacModelo(['pos_idempotency_key' => '00000000-0000-4000-8000-000000000091']);
$res = $m->updateFacturaSimple(41, ['items' => $ieFacLineas], $ieFacCallback($c, $visto, null));
chk('update de una venta del POS: se rechaza (409 en el controlador) antes de la transaccion y el callback nunca corre',
    ($res[2] ?? null) === facturaModel::ERROR_VENTA_POS && $visto === [] && $c->orden === [], [$res, $c->orden]);

// --- Fuente del modelo ---
$ieFacModeloSrc = ie_fuente('src/Models/facturaModel.php');
chk('modelo: ?callable $enTransaccion = null aparece en createFacturaSimple y en updateFacturaSimple (y en ningun otro metodo)',
    substr_count($ieFacModeloSrc, '?callable $enTransaccion = null') === 2
    && str_contains($ieFacModeloSrc, 'public function createFacturaSimple(array $data, ?callable $enTransaccion = null): array')
    && str_contains($ieFacModeloSrc, 'public function updateFacturaSimple(int $id, array $data, ?callable $enTransaccion = null): array'));
preg_match('/public function createFacturaSimple\(.*?\n    \}\n/s', $ieFacModeloSrc, $mCrearSrc);
preg_match('/public function updateFacturaSimple\(.*?\n    \}\n/s', $ieFacModeloSrc, $mActSrc);
chk('modelo: createFacturaSimple llama al callback despues del INSERT INTO facturas, ANTES de insertSimpleItems (el FK de product_id) y esa antes de commit()',
    isset($mCrearSrc[0]) && strpos($mCrearSrc[0], 'INSERT INTO facturas') < strpos($mCrearSrc[0], '$enTransaccion(')
    && strpos($mCrearSrc[0], '$enTransaccion(') < strpos($mCrearSrc[0], 'insertSimpleItems(')
    && strpos($mCrearSrc[0], 'insertSimpleItems(') < strpos($mCrearSrc[0], '$this->conexion->commit()'));
chk('modelo: updateFacturaSimple llama al callback despues de borrar las lineas viejas, ANTES de insertSimpleItems (el FK de product_id) y esa antes de commit(), solo dentro del bloque que reemplaza las lineas',
    isset($mActSrc[0]) && strpos($mActSrc[0], 'DELETE FROM factura_items') < strpos($mActSrc[0], '$enTransaccion(')
    && strpos($mActSrc[0], '$enTransaccion(') < strpos($mActSrc[0], 'insertSimpleItems(')
    && strpos($mActSrc[0], 'insertSimpleItems(') < strpos($mActSrc[0], '$this->conexion->commit()')
    && preg_match('/if \(\$replaceItems\) \{[^}]*\$enTransaccion\(/s', $mActSrc[0]) === 1);
chk('modelo: el callback recibe ($this->conexion, $facturaId, $items) y su error se devuelve despues de rollBack(), que solo se llama si inTransaction() (interbloqueo: sin transaccion rollBack() lanza)',
    isset($mCrearSrc[0]) && preg_match('/\$enTransaccion\(\$this->conexion, \$facturaId, \$items\)/', $mCrearSrc[0]) === 1
    && preg_match('/if \(\$this->conexion->inTransaction\(\)\) \{\s*\$this->conexion->rollBack\(\);\s*\}\s*return \$rechazo;/', $mCrearSrc[0]) === 1
    && isset($mActSrc[0]) && preg_match('/\$enTransaccion\(\$this->conexion, \$id, \$items\)/', $mActSrc[0]) === 1
    && preg_match('/if \(\$this->conexion->inTransaction\(\)\) \{\s*\$this->conexion->rollBack\(\);\s*\}\s*return \$rechazo;/', $mActSrc[0]) === 1);
chk('modelo: el callback solo vive en esos dos metodos (la factura simple del POS y deleteFacturaSimple no lo llaman)',
    substr_count($ieFacModeloSrc, '$enTransaccion(') === 2);

// --- Controlador: se lee como texto ---
$ieSimple = ie_fuente('src/Controllers/facturaSimpleController.php');
chk('controlador simple: se lee facturaSimpleController.php', $ieSimple !== '');
$ieCasoPost = preg_match("/\n    case 'POST':\n.*?\n    case 'PUT':\n/s", $ieSimple, $mPost) === 1 ? $mPost[0] : '';
$ieCasoPut = preg_match("/\n    case 'PUT':\n.*?\n    case 'DELETE':\n/s", $ieSimple, $mPut) === 1 ? $mPut[0] : '';
$ieCasoDelete = preg_match("/\n    case 'DELETE':\n.*?\n    default:\n/s", $ieSimple, $mDel) === 1 ? $mDel[0] : '';
chk('controlador simple: se encontraron los casos POST, PUT y DELETE', $ieCasoPost !== '' && $ieCasoPut !== '' && $ieCasoDelete !== '');

// El constructor del callback: usa la conexion que le da el modelo, nunca fsInventario().
$ieFsMov = preg_match('/\nfunction fsMovimientoEnTransaccion\(.*?\n\}\n/s', $ieSimple, $mFsMov) === 1 ? $mFsMov[0] : '';
chk('controlador simple: existe fsMovimientoEnTransaccion y arma el callback (PDO $conexion, int $facturaId, array $items): ?array',
    $ieFsMov !== '' && preg_match('/function \(PDO \$conexion, int \$facturaId, array \$items\) use \([^)]*\): \?array \{/', $ieFsMov) === 1, $ieFsMov);
chk('controlador simple: el callback arma new inventoryModel($conexion) con la conexion que le da el modelo y llama moverVentaEnTransaccion(',
    str_contains($ieFsMov, 'new inventoryModel($conexion)') && str_contains($ieFsMov, '->moverVentaEnTransaccion($facturaId, $items,'), $ieFsMov);
chk('controlador simple: el usuario llega al libro: el callback reenvia $userId a moverVentaEnTransaccion($facturaId, $items, $userId, $itemsViejos) (los movimientos del kardex llevan user_id) y lo normaliza a int o null',
    str_contains($ieFsMov, '->moverVentaEnTransaccion($facturaId, $items, $userId, $itemsViejos)')
    && str_contains($ieFsMov, '$userId = $userId !== null ? (int) $userId : null;')
    && preg_match('/use \(\$itemsViejos, \$userId\)/', $ieFsMov) === 1, $ieFsMov);
chk('controlador simple: el callback NUNCA usa fsInventario() (abriria otra conexion: el bloqueo se soltaria solo y un rollback no desharia el descuento) ni new inventoryModel()',
    $ieFsMov !== '' && !str_contains($ieFsMov, 'fsInventario()') && !str_contains($ieFsMov, 'new inventoryModel()'));
chk('controlador simple: el callback devuelve null si todo salio bien y, si no, el error tal cual (el modelo lo devuelve sin tocarlo)',
    str_contains($ieFsMov, "\$res[0] === 'success' ? null : \$res"), $ieFsMov);
chk('controlador simple: si el archivo del modelo de inventario falta, no hay callback (is_file antes del require, como fsInventario)',
    str_contains($ieFsMov, 'is_file(') && strpos($ieFsMov, 'is_file(') < strpos($ieFsMov, 'require_once') && str_contains($ieFsMov, 'return null;'), $ieFsMov);
chk('controlador simple: new inventoryModel( aparece con la conexion del modelo en el callback y sin argumentos solo en fsInventario',
    substr_count($ieSimple, 'new inventoryModel($conexion)') === 1 && substr_count($ieSimple, 'new inventoryModel()') === 1);

// POST.
chk('POST: el interruptor se lee una vez (TenantResolver::inventarioEstricto()) y arma el callback solo si esta encendido',
    substr_count($ieCasoPost, 'TenantResolver::inventarioEstricto()') === 1 && str_contains($ieCasoPost, 'fsMovimientoEnTransaccion('), $ieCasoPost);
chk('POST: el callback se arma con el usuario de la venta, fsMovimientoEnTransaccion(null, $userId) (null = factura nueva, sin lineas viejas), y $userId sale del token antes que del body',
    str_contains($ieCasoPost, 'fsMovimientoEnTransaccion(null, $userId)') && !str_contains($ieCasoPost, 'fsMovimientoEnTransaccion(null, null)')
    && strpos($ieCasoPost, '$userId = $authUserId ??') < strpos($ieCasoPost, 'fsMovimientoEnTransaccion(null, $userId)'), $ieCasoPost);
chk('POST: createFacturaSimple($body, $enTransaccion) lleva el callback',
    str_contains($ieCasoPost, '$facturaModel->createFacturaSimple($body, $enTransaccion)'), $ieCasoPost);
chk('POST: el descuento de despues del commit sigue ahi (fsInventario()?->registrarVenta con las lineas guardadas) y solo corre sin callback, para no descontar dos veces',
    preg_match('/if \(\$enTransaccion === null\) \{\s*fsInventario\(\)\?->registrarVenta\(\s*\(int\) \(\$result\[1\]\[\'id\'\] \?\? 0\),\s*\$result\[1\]\[\'items\'\] \?\? \[\],\s*null,\s*\$userId\s*\);\s*\}/', $ieCasoPost) === 1
    && substr_count($ieCasoPost, '->registrarVenta(') === 1, $ieCasoPost);
chk('POST: un 422 del callback se audita (facturas-simples / CREATE / success false, con el texto) ANTES de responder con fsRespondExistencia y sale',
    preg_match("/\\(\\\$result\\[2\\] \\?\\? null\\) === 422\\) \\{.*?\\}\n/s", $ieCasoPost, $mRechPost) === 1
    && str_contains($mRechPost[0], "'module' => 'facturas-simples'") && str_contains($mRechPost[0], "'action' => 'CREATE'")
    && str_contains($mRechPost[0], "'success' => false") && str_contains($mRechPost[0], "'error_message' => \$result[1]")
    && strpos($mRechPost[0], 'AuditLogger::log(') < strpos($mRechPost[0], 'fsRespondExistencia($result)')
    && strpos($mRechPost[0], 'fsRespondExistencia($result)') < strpos($mRechPost[0], 'break;'), $mRechPost[0] ?? $ieCasoPost);
chk("POST: el 422 se detecta por el tercer elemento (\$result[2] === 422), asi que un fallo sin codigo (400, 'No se pudieron actualizar las existencias') sigue su camino de siempre",
    str_contains($ieCasoPost, "\$result[0] === 'error' && (\$result[2] ?? null) === 422")
    && str_contains($ieCasoPost, "fsRespond(\$result[0] === 'success', \$result[1], \$result[0] === 'success' ? 201 : 400);"), $ieCasoPost);

// PUT.
chk('PUT: el interruptor se lee una vez y el callback se arma solo cuando se reemplazan las lineas (isset($body[\'items\'])), con las lineas guardadas como $itemsViejos',
    substr_count($ieCasoPut, 'TenantResolver::inventarioEstricto()') === 1
    && preg_match('/\$enTransaccion = null;\s*if \(TenantResolver::inventarioEstricto\(\) && isset\(\$body\[\'items\'\]\)\) \{\s*\$enTransaccion = fsMovimientoEnTransaccion\(\$previa\[\'items\'\] \?\? \[\], \$authUserId\);/', $ieCasoPut) === 1, $ieCasoPut);
chk('PUT: updateFacturaSimple($id, $body, $enTransaccion) lleva el callback',
    str_contains($ieCasoPut, '$facturaModel->updateFacturaSimple($id, $body, $enTransaccion)'), $ieCasoPut);
chk('PUT: la reversa y el descuento de despues del commit siguen ahi (revertirVenta + registrarVenta de siempre) y solo corren sin callback',
    preg_match('/if \(\$result\[0\] === \'success\' && isset\(\$body\[\'items\'\]\) && \$enTransaccion === null\) \{\s*\$inventario = fsInventario\(\);\s*\$inventario\?->revertirVenta\(\$id, \$previa\[\'items\'\] \?\? \[\], \$authUserId\);\s*\$inventario\?->registrarVenta\(\$id, \$result\[1\]\[\'items\'\] \?\? \[\], null, \$authUserId\);\s*\}/', $ieCasoPut) === 1, $ieCasoPut);
chk('PUT: un 422 del callback se audita (facturas-simples / UPDATE / success false) ANTES de responder con fsRespondExistencia y sale',
    preg_match("/\\(\\\$result\\[2\\] \\?\\? null\\) === 422\\) \\{.*?\\}\n/s", $ieCasoPut, $mRechPut) === 1
    && str_contains($mRechPut[0], "'module' => 'facturas-simples'") && str_contains($mRechPut[0], "'action' => 'UPDATE'")
    && str_contains($mRechPut[0], "'success' => false") && str_contains($mRechPut[0], "'error_message' => \$result[1]")
    && strpos($mRechPut[0], 'AuditLogger::log(') < strpos($mRechPut[0], 'fsRespondExistencia($result)')
    && strpos($mRechPut[0], 'fsRespondExistencia($result)') < strpos($mRechPut[0], 'break;'), $mRechPut[0] ?? $ieCasoPut);

// DELETE: igual que hoy.
chk('DELETE sin cambios: lee las lineas antes, borra y devuelve el stock con revertirVenta despues del commit (no usa el interruptor ni el callback)',
    str_contains($ieCasoDelete, '$previa = $facturaModel->getFacturaSimple($id);')
    && str_contains($ieCasoDelete, '$facturaModel->deleteFacturaSimple($id)')
    && str_contains($ieCasoDelete, "fsInventario()?->revertirVenta(\$id, \$previa['items'] ?? [], \$authUserId);")
    && !str_contains($ieCasoDelete, 'enTransaccion') && !str_contains($ieCasoDelete, 'inventarioEstricto'), $ieCasoDelete);
chk('controlador simple: registrarVenta( y revertirVenta( son las mismas llamadas de siempre (2 registrarVenta: POST y PUT; 2 revertirVenta: PUT y DELETE)',
    substr_count($ieSimple, '->registrarVenta(') === 2 && substr_count($ieSimple, '->revertirVenta(') === 2);

// fsRespondExistencia: se extrae del texto y se corre de verdad, en otro proceso (en este ya hubo salida).
$ieFsResp = preg_match('/\nfunction fsRespondExistencia\(.*?\n\}\n/s', $ieSimple, $mFsResp) === 1 ? $mFsResp[0] : '';
chk('controlador simple: fsRespondExistencia(array $res): void existe, fija 422 y emite inventoryModel::respuestaExistencia($res) (nunca 409)',
    str_contains($ieFsResp, 'function fsRespondExistencia(array $res): void') && str_contains($ieFsResp, 'http_response_code(422)')
    && str_contains($ieFsResp, 'json_encode(inventoryModel::respuestaExistencia($res))') && !str_contains($ieFsResp, '409'), $ieFsResp);
if ($ieFsResp !== '') {
    $ieCorrerFs = static function (array $res) use ($ieFsResp): array {
        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ie_fsresp_' . getmypid() . '_' . mt_rand() . '.php';
        file_put_contents($tmp, "<?php\nrequire_once " . var_export(dirname(__DIR__) . '/src/Models/inventoryModel.php', true) . ";\n"
            . $ieFsResp . "\nfsRespondExistencia(" . var_export($res, true) . ");\necho \"\\n\" . http_response_code();\n");
        $proc = proc_open([PHP_BINARY, $tmp], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $salida = '';
        if (is_resource($proc)) {
            $salida = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
        }
        unlink($tmp);
        $partes = explode("\n", rtrim($salida, "\n"));
        $codigo = (int) array_pop($partes);
        return [$codigo, json_decode(implode("\n", $partes), true), $salida];
    };
    [$ieCodigo, $ieJson, $ieSalida] = $ieCorrerFs($ieFacRechazo);
    chk('fsRespondExistencia corrida con faltantes: HTTP 422 y {status:false, error, codigo:STOCK_INSUFICIENTE, data.faltantes con numeros}',
        $ieCodigo === 422 && is_array($ieJson) && $ieJson['status'] === false && $ieJson['codigo'] === 'STOCK_INSUFICIENTE'
        && $ieJson['error'] === $ieFacRechazo[1] && ($ieJson['data']['faltantes'][0]['product_id'] ?? null) === 7
        && ($ieJson['data']['faltantes'][0]['faltante'] ?? null) === 4 && !is_string($ieJson['data']['faltantes'][0]['solicitado'] ?? null), $ieSalida);
    [$ieCodigo, $ieJson, $ieSalida] = $ieCorrerFs(['error', 'Uno de los productos ya no existe en el catálogo.', 422]);
    chk('fsRespondExistencia corrida con el caso llano (producto inexistente o cantidad <= 0): HTTP 422 y solo {status:false, error}, sin codigo ni data',
        $ieCodigo === 422 && $ieJson === ['status' => false, 'error' => 'Uno de los productos ya no existe en el catálogo.'], $ieSalida);
}

// ---------------------------------------------------------------------------
// 6) El interruptor en Configuracion: inventoryModel::rolPuedeConfigurar y
//    contarSinExistencia (con una conexion falsa), el cableado de
//    inventarioController (GET/PUT /api/inventario/configuracion) y el campo
//    inventario_estricto de brandingController. Incluir un controlador manda
//    headers, valida el token y corre el router, asi que se lee como texto y,
//    para ver que responde de verdad, sus funciones se extraen del texto y se
//    corren en otro proceso con AuthMiddleware, AuditLogger, MasterDatabase y
//    el resto de sus dependencias reemplazados por dobles que anotan lo que
//    les piden (la ruta real contra MySQL la prueba la tarea 8).
// ---------------------------------------------------------------------------
echo "\n== 6) El interruptor en Configuracion (GET/PUT /api/inventario/configuracion y branding) ==\n";

require_once __DIR__ . '/../src/Models/inventoryModel.php';

// --- rolPuedeConfigurar: solo el rol de sistema 'admin', sin importar mayusculas ---
if (!method_exists(inventoryModel::class, 'rolPuedeConfigurar')) {
    chk('inventoryModel::rolPuedeConfigurar existe', false);
} else {
    $rv = new ReflectionMethod(inventoryModel::class, 'rolPuedeConfigurar');
    chk('rolPuedeConfigurar(?string $rol): bool es publica y estatica',
        $rv->isPublic() && $rv->isStatic() && (string) $rv->getReturnType() === 'bool'
        && (string) ($rv->getParameters()[0]->getType() ?? '') === '?string');
    chk("rolPuedeConfigurar('admin') es true", inventoryModel::rolPuedeConfigurar('admin') === true);
    chk("rolPuedeConfigurar('ADMIN') es true (sin importar mayusculas)", inventoryModel::rolPuedeConfigurar('ADMIN') === true);
    chk("rolPuedeConfigurar('Admin') es true", inventoryModel::rolPuedeConfigurar('Admin') === true);
    chk("rolPuedeConfigurar('user') es false", inventoryModel::rolPuedeConfigurar('user') === false);
    chk("rolPuedeConfigurar('') es false", inventoryModel::rolPuedeConfigurar('') === false);
    chk('rolPuedeConfigurar(null) es false', inventoryModel::rolPuedeConfigurar(null) === false);
    chk("rolPuedeConfigurar('administrador') y ('superadmin') son false: es el rol 'admin', no algo que se le parezca",
        inventoryModel::rolPuedeConfigurar('administrador') === false && inventoryModel::rolPuedeConfigurar('superadmin') === false);
}

// --- contarSinExistencia: con una conexion falsa que anota la consulta ---
final class IeConteoSentencia extends PDOStatement
{
    public ?IeConteoConexion $con = null;
    public string $sql = '';
    public mixed $valor = false;

    public function execute(?array $params = null): bool
    {
        $this->con->consultas[] = ['sql' => preg_replace('/\s+/', ' ', trim($this->sql)), 'params' => $params ?? []];
        return true;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return $this->valor;
    }
}

final class IeConteoConexion extends PDO
{
    /** @var list<array{sql:string,params:array}> */
    public array $consultas = [];

    public function __construct(private mixed $valor)
    {
        // Sin parent::__construct: nunca abre una conexion.
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->consultas[] = ['sql' => preg_replace('/\s+/', ' ', trim($query)), 'params' => []];
        $s = new IeConteoSentencia();
        $s->con = $this;
        $s->valor = $this->valor;
        return $s;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $s = new IeConteoSentencia();
        $s->con = $this;
        $s->sql = $query;
        $s->valor = $this->valor;
        return $s;
    }
}

if (!method_exists(inventoryModel::class, 'contarSinExistencia')) {
    chk('inventoryModel::contarSinExistencia existe', false);
} else {
    $c = new IeConteoConexion('3');   // MySQL devuelve el COUNT como texto
    $n = (new inventoryModel($c))->contarSinExistencia();
    chk('contarSinExistencia devuelve el conteo como entero (MySQL lo da como texto)', $n === 3, $n);
    $sql = $c->consultas[0]['sql'] ?? '';
    chk('contarSinExistencia: una sola consulta, un COUNT sobre products, sin parametros',
        count($c->consultas) === 1 && str_contains($sql, 'COUNT(*)') && str_contains($sql, 'FROM products') && ($c->consultas[0]['params'] ?? null) === [], $c->consultas);
    chk('contarSinExistencia cuenta solo productos activos (activo = 1)', preg_match('/\bactivo = 1\b/', $sql) === 1, $sql);
    chk('contarSinExistencia cuenta solo Bienes: indicador_bien_servicio <> 2, la misma definicion que la venta y el reporte de valor',
        preg_match('/\bindicador_bien_servicio <> 2\b/', $sql) === 1, $sql);
    chk('contarSinExistencia cuenta stock NULL o <= 0 (lo que la regla rechazaria hoy)',
        preg_match('/\(\s*stock IS NULL OR stock <= 0\s*\)/', $sql) === 1, $sql);
    chk('contarSinExistencia solo lee: sin FOR UPDATE, UPDATE, INSERT ni DELETE',
        preg_match('/\b(FOR UPDATE|UPDATE|INSERT|DELETE)\b/i', $sql) === 0, $sql);
    chk('contarSinExistencia con 0 productos (COUNT = "0") es 0', (new inventoryModel(new IeConteoConexion('0')))->contarSinExistencia() === 0);
    chk('contarSinExistencia sin fila (fetchColumn = false) es 0, nunca false', (new inventoryModel(new IeConteoConexion(false)))->contarSinExistencia() === 0);
}

// --- inventarioController: el cableado, leido como texto ---
$ieCtlInv = ie_fuente('src/Controllers/inventarioController.php');
$ieCtlInvSwitch = (int) strpos($ieCtlInv, "switch (\$_SERVER['REQUEST_METHOD'])");
$ieFuncion = static function (string $fuente, string $nombre): string {
    return preg_match('/\nfunction ' . preg_quote($nombre, '/') . '\(.*?\n\}\n/s', $fuente, $m) === 1 ? $m[0] : '';
};
$ieCfgFn = $ieFuncion($ieCtlInv, 'invConfiguracion');
$ieCfgPayloadFn = $ieFuncion($ieCtlInv, 'invConfiguracionPayload');

chk('controlador: Access-Control-Allow-Methods y Allow incluyen PUT (el preflight del navegador lo pide)',
    str_contains($ieCtlInv, "header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');")
    && str_contains($ieCtlInv, "header('Allow: GET, POST, PUT, OPTIONS');"));
chk('controlador: $esConfiguracion reconoce /inventario/configuracion',
    preg_match('/\$esConfiguracion = \(bool\) preg_match\(\'#\/inventario\/configuracion[^\']*#\', \$path\);/', $ieCtlInv) === 1);
chk('controlador: la configuracion se atiende ANTES del switch de los ajustes (return incluido), y el switch sigue sin case PUT',
    $ieCfgFn !== '' && $ieCtlInvSwitch > 0
    && preg_match('/if \(\$esConfiguracion\) \{\s*invConfiguracion\(\$inventoryModel, \$auth, \$validation\);\s*return;\s*\}/', $ieCtlInv, $mCfg, PREG_OFFSET_CAPTURE) === 1
    && $mCfg[0][1] < $ieCtlInvSwitch && !str_contains(substr($ieCtlInv, $ieCtlInvSwitch), "case 'PUT'"));
chk('controlador: carga AuditMiddleware y MasterDatabase (los usa la configuracion)',
    str_contains($ieCtlInv, "require_once __DIR__ . '/../Middleware/AuditMiddleware.php';")
    && str_contains($ieCtlInv, "require_once __DIR__ . '/../MasterDatabase.php';"));
$ieOrdenRol = (int) strpos($ieCfgFn, 'rolPuedeConfigurar(');
chk('controlador: el PUT revisa el rol con rolPuedeConfigurar ANTES de tocar la base (setInventarioEstricto, getTenantById, conteo)',
    $ieOrdenRol > 0
    && $ieOrdenRol < (int) strpos($ieCfgFn, 'setInventarioEstricto(')
    && $ieOrdenRol < (int) strpos($ieCfgFn, 'getTenantById(')
    && $ieOrdenRol < (int) strpos($ieCfgFn, 'invConfiguracionPayload('));
chk('controlador: el intento denegado se anota como inventario / ACCESS_DENIED (AuditMiddleware::logAccessDenied) y responde 403 con sendForbidden',
    preg_match("/AuditMiddleware::logAccessDenied\(\s*'inventario'/", $ieCfgFn) === 1 && str_contains($ieCfgFn, '$auth->sendForbidden('));
chk('controlador: el id de la empresa sale SOLO del token ($validation[\'tenant_id\']), nunca del cuerpo ni de la URL',
    str_contains($ieCfgFn, "\$validation['tenant_id']") && !preg_match('/\$body\[\'tenant|\$_GET|\$_POST|\$_REQUEST/', $ieCfgFn));
chk('controlador: el cambio se audita como inventario / INVENTARIO_CONFIG con old_values y new_values',
    str_contains($ieCfgFn, "'module' => 'inventario'") && str_contains($ieCfgFn, "'action' => 'INVENTARIO_CONFIG'")
    && str_contains($ieCfgFn, "'old_values'") && str_contains($ieCfgFn, "'new_values'"));
chk('controlador: el 409 de instalacion sin empresa, el 422 del no booleano y el 405 de otros metodos existen',
    str_contains($ieCfgFn, ', 409)') && str_contains($ieCfgFn, ', 422)') && str_contains($ieCfgFn, ', 405)') && str_contains($ieCfgFn, 'is_bool('));
chk('controlador: el payload de GET/PUT lo arma una sola funcion (invConfiguracionPayload) con bloquear_sin_existencia y productos_sin_existencia',
    $ieCfgPayloadFn !== '' && str_contains($ieCfgPayloadFn, "'bloquear_sin_existencia'") && str_contains($ieCfgPayloadFn, "'productos_sin_existencia'")
    && str_contains($ieCfgPayloadFn, 'contarSinExistencia()'));

// --- inventarioController: la configuracion corrida de verdad, con dobles ---
$ieHarnessCfg = <<<'PHP'
<?php
$LLAMADAS = [];
$CFG = __CFG__;
require_once __MODELO__;

class AuthMiddleware
{
    public function sendForbidden($message = 'Forbidden')
    {
        http_response_code(403);
        echo json_encode(['status' => false, 'error' => $message]);
        exit;
    }
}
class AuditMiddleware
{
    public static function logAccessDenied(string $module, string $reason, array $detalle = [], ?string $descripcion = null): void
    {
        global $LLAMADAS;
        $LLAMADAS[] = ['denegado', $module, $reason];
    }
}
class AuditLogger
{
    public static function log(array $evento): void
    {
        global $LLAMADAS;
        $LLAMADAS[] = ['auditoria', $evento];
    }
}
class InputSanitizer
{
    public static function jsonInput()
    {
        global $CFG;
        return $CFG['body'];
    }
}
class TenantResolver
{
    // Como en el request real: la fila resuelta al validar el token, vieja tras el PUT.
    public static function inventarioEstricto(): bool
    {
        global $CFG;
        return $CFG['estricto_actual'];
    }
}
class MasterDatabase
{
    private static ?MasterDatabase $i = null;
    public static function getInstance(): MasterDatabase
    {
        return self::$i ??= new MasterDatabase();
    }
    public function setInventarioEstricto(int $tenantId, bool $activo): void
    {
        global $LLAMADAS, $CFG;
        $LLAMADAS[] = ['set', $tenantId, $activo];
        if ($CFG['master_falla']) {
            throw new PDOException("SQLSTATE[42S22]: Column not found: 1054 Unknown column 'inventario_estricto' in 'field list'");
        }
        $CFG['guardado'] = $activo;
    }
    public function getTenantById(int $id): ?array
    {
        global $LLAMADAS, $CFG;
        $LLAMADAS[] = ['get', $id];
        return ['id' => $id, 'tipo' => $CFG['tipo_guardado'], 'inventario_estricto' => $CFG['guardado'] ? 1 : 0];
    }
}
final class FakeSent extends PDOStatement
{
    public function fetchColumn(int $column = 0): mixed
    {
        global $CFG;
        return (string) $CFG['sin_existencia'];
    }
    public function execute(?array $params = null): bool
    {
        return true;
    }
}
final class FakePdo extends PDO
{
    public function __construct()
    {
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        global $LLAMADAS, $CFG;
        $LLAMADAS[] = ['consulta'];
        if ($CFG['conteo_falla']) {
            throw new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
        }
        return new FakeSent();
    }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        global $LLAMADAS, $CFG;
        $LLAMADAS[] = ['consulta'];
        if ($CFG['conteo_falla']) {
            throw new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
        }
        return new FakeSent();
    }
}
__FUNCIONES__
register_shutdown_function(static function () {
    global $LLAMADAS;
    echo "\n@@" . json_encode([http_response_code(), $LLAMADAS]);
});
$_SERVER['REQUEST_METHOD'] = $CFG['metodo'];
invConfiguracion(new inventoryModel(new FakePdo()), new AuthMiddleware(), $CFG['validation']);
PHP;

/**
 * Corre invConfiguracion en otro proceso. $o: metodo, validation, body, estricto_actual,
 * sin_existencia, master_falla, tipo_guardado, conteo_falla (el conteo de productos lanza una
 * PDOException). Devuelve [http, json del cuerpo, llamadas, salida cruda].
 */
$ieCorrerCfg = static function (array $o) use ($ieHarnessCfg, $ieFuncion, $ieCtlInv): array {
    $cfg = $o + [
        'metodo' => 'GET', 'validation' => [], 'body' => null, 'estricto_actual' => false,
        'sin_existencia' => 3, 'master_falla' => false, 'tipo_guardado' => 'app', 'conteo_falla' => false,
    ];
    $cfg['guardado'] = $cfg['estricto_actual'];
    $funciones = '';
    foreach (['invRespond', 'invBody', 'invConfiguracionPayload', 'invConfiguracion'] as $nombre) {
        $funciones .= $ieFuncion($ieCtlInv, $nombre);
    }
    $script = str_replace(
        ['__CFG__', '__MODELO__', '__FUNCIONES__'],
        [var_export($cfg, true), var_export(dirname(__DIR__) . '/src/Models/inventoryModel.php', true), $funciones],
        $ieHarnessCfg
    );
    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ie_cfg_' . getmypid() . '_' . mt_rand() . '.php';
    file_put_contents($tmp, $script);
    $proc = proc_open([PHP_BINARY, $tmp], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $salida = '';
    $errores = '';   // el error_log del controlador (el detalle SQL va aqui, no al usuario)
    if (is_resource($proc)) {
        $salida = (string) stream_get_contents($pipes[1]);
        $errores = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
    }
    unlink($tmp);
    $partes = explode("\n@@", $salida);
    $final = json_decode((string) array_pop($partes), true);
    return [
        is_array($final) ? $final[0] : null,
        json_decode(implode("\n@@", $partes), true),
        is_array($final) ? $final[1] : [],
        $salida . $errores,
    ];
};
$ieAdmin = ['valid' => true, 'user_id' => 5, 'tenant_id' => 2, 'role' => 'admin', 'message' => 'Token validated'];
/** Las llamadas de un tipo ('set', 'get', 'consulta', 'auditoria', 'denegado'). */
$ieLlamadas = static fn(array $llamadas, string $tipo): array => array_values(array_filter($llamadas, static fn($l) => $l[0] === $tipo));

if ($ieCfgFn === '' || $ieCfgPayloadFn === '') {
    chk('invConfiguracion e invConfiguracionPayload existen en inventarioController.php (para correrlas)', false);
} else {
    // GET: cualquier usuario autenticado
    [$http, $json, $llamadas, $crudo] = $ieCorrerCfg(['metodo' => 'GET', 'validation' => ['role' => 'user'] + $ieAdmin, 'sin_existencia' => 7]);
    chk('GET como rol user (no admin): 200 y {status:true, data:{bloquear_sin_existencia:false, productos_sin_existencia:7}} con el conteo como numero',
        $http === 200 && ($json['status'] ?? null) === true && ($json['data'] ?? null) === ['bloquear_sin_existencia' => false, 'productos_sin_existencia' => 7]
        && $ieLlamadas($llamadas, 'set') === [] && $ieLlamadas($llamadas, 'denegado') === [], $crudo);
    [$http, $json, $llamadas, $crudo] = $ieCorrerCfg(['metodo' => 'GET', 'validation' => $ieAdmin, 'estricto_actual' => true, 'sin_existencia' => 0]);
    chk('GET con la regla encendida en la empresa: bloquear_sin_existencia true (lee la bandera de la empresa resuelta) y conteo 0',
        $http === 200 && ($json['data'] ?? null) === ['bloquear_sin_existencia' => true, 'productos_sin_existencia' => 0], $crudo);
    [$http, $json, , $crudo] = $ieCorrerCfg(['metodo' => 'GET', 'validation' => ['tenant_id' => null] + $ieAdmin]);
    chk('GET sin empresa en el token (instalacion de una sola empresa): 409 con texto para el usuario',
        $http === 409 && ($json['status'] ?? null) === false && is_string($json['error'] ?? null) && !isset($json['data']), $crudo);
    [$http, $json, , $crudo] = $ieCorrerCfg(['metodo' => 'GET', 'validation' => ['user_id' => null] + $ieAdmin]);
    chk('GET sin usuario en el token (credencial de maquina): 403', $http === 403 && ($json['status'] ?? null) === false, $crudo);

    // PUT: solo admin, y el rol se revisa antes de tocar nada
    foreach (['user', '', null, 'cajero'] as $rol) {
        [$http, $json, $llamadas, $crudo] = $ieCorrerCfg(['metodo' => 'PUT', 'validation' => ['role' => $rol] + $ieAdmin,
            'body' => ['bloquear_sin_existencia' => true]]);
        chk('PUT con rol ' . var_export($rol, true) . ': 403, anotado como acceso denegado de inventario, y sin una sola consulta ni escritura',
            $http === 403 && ($json['status'] ?? null) === false && is_string($json['error'] ?? null)
            && count($ieLlamadas($llamadas, 'denegado')) === 1 && ($ieLlamadas($llamadas, 'denegado')[0][1] ?? null) === 'inventario'
            && $ieLlamadas($llamadas, 'set') === [] && $ieLlamadas($llamadas, 'get') === [] && $ieLlamadas($llamadas, 'consulta') === []
            && $ieLlamadas($llamadas, 'auditoria') === [], $crudo);
    }
    [$http, $json, $llamadas, $crudo] = $ieCorrerCfg(['metodo' => 'PUT', 'validation' => ['user_id' => null] + $ieAdmin, 'body' => ['bloquear_sin_existencia' => true]]);
    chk('PUT sin usuario en el token: 403 y no escribe', $http === 403 && $ieLlamadas($llamadas, 'set') === [], $crudo);
    [$http, $json, $llamadas, $crudo] = $ieCorrerCfg(['metodo' => 'PUT', 'validation' => ['tenant_id' => null] + $ieAdmin, 'body' => ['bloquear_sin_existencia' => true]]);
    chk('PUT de un admin sin empresa en el token (instalacion de una sola empresa): 409 y no escribe',
        $http === 409 && ($json['status'] ?? null) === false && $ieLlamadas($llamadas, 'set') === [], $crudo);

    // PUT: el cuerpo debe traer un booleano de verdad
    $malos = ['texto "true"' => ['bloquear_sin_existencia' => 'true'], 'numero 1' => ['bloquear_sin_existencia' => 1], 'null' => ['bloquear_sin_existencia' => null],
        'sin la clave' => ['otra' => true], 'arreglo' => ['bloquear_sin_existencia' => [true]], 'cuerpo vacio' => []];
    foreach ($malos as $que => $body) {
        [$http, $json, $llamadas, $crudo] = $ieCorrerCfg(['metodo' => 'PUT', 'validation' => $ieAdmin, 'body' => $body]);
        chk('PUT con ' . $que . ' en vez de un booleano: 422 llano y no escribe',
            $http === 422 && ($json['status'] ?? null) === false && is_string($json['error'] ?? null) && !isset($json['codigo'], $json['data'])
            && $ieLlamadas($llamadas, 'set') === [] && $ieLlamadas($llamadas, 'auditoria') === [], $crudo);
    }
    [$http, $json, $llamadas, $crudo] = $ieCorrerCfg(['metodo' => 'PUT', 'validation' => $ieAdmin, 'body' => null]);
    chk('PUT sin cuerpo JSON: 422 y no escribe', $http === 422 && $ieLlamadas($llamadas, 'set') === [], $crudo);

    // PUT admin: escribe, audita y responde lo mismo que el GET
    [$http, $json, $llamadas, $crudo] = $ieCorrerCfg(['metodo' => 'PUT', 'validation' => $ieAdmin, 'body' => ['bloquear_sin_existencia' => true],
        'estricto_actual' => false, 'sin_existencia' => 4]);
    chk('PUT admin enciende: 200 con el mismo cuerpo que el GET, ya en true (se lee de nuevo de master: el tenant resuelto del request esta viejo)',
        $http === 200 && ($json['status'] ?? null) === true && ($json['data'] ?? null) === ['bloquear_sin_existencia' => true, 'productos_sin_existencia' => 4], $crudo);
    chk('PUT admin: escribe UNA vez, con el id del token (2) y true',
        $ieLlamadas($llamadas, 'set') === [['set', 2, true]], $llamadas);
    $audit = $ieLlamadas($llamadas, 'auditoria')[0][1] ?? [];
    chk('PUT admin: una fila de auditoria inventario / INVENTARIO_CONFIG, entidad = la empresa, antes false y despues true',
        count($ieLlamadas($llamadas, 'auditoria')) === 1 && ($audit['module'] ?? null) === 'inventario' && ($audit['action'] ?? null) === 'INVENTARIO_CONFIG'
        && ($audit['entity_id'] ?? null) === 2 && ($audit['old_values'] ?? null) === ['bloquear_sin_existencia' => false]
        && ($audit['new_values'] ?? null) === ['bloquear_sin_existencia' => true] && is_string($audit['description'] ?? null), $audit);
    [$http, $json, $llamadas, $crudo] = $ieCorrerCfg(['metodo' => 'PUT', 'validation' => ['role' => 'ADMIN'] + $ieAdmin,
        'body' => ['bloquear_sin_existencia' => false], 'estricto_actual' => true, 'sin_existencia' => 1]);
    $audit = $ieLlamadas($llamadas, 'auditoria')[0][1] ?? [];
    chk("PUT con rol 'ADMIN' apaga: 200, bloquear_sin_existencia false, auditoria antes true y despues false",
        $http === 200 && ($json['data'] ?? null) === ['bloquear_sin_existencia' => false, 'productos_sin_existencia' => 1]
        && $ieLlamadas($llamadas, 'set') === [['set', 2, false]]
        && ($audit['old_values'] ?? null) === ['bloquear_sin_existencia' => true] && ($audit['new_values'] ?? null) === ['bloquear_sin_existencia' => false], $crudo);
    [$http, $json, $llamadas, $crudo] = $ieCorrerCfg(['metodo' => 'PUT', 'validation' => $ieAdmin,
        'body' => ['bloquear_sin_existencia' => true, 'tenant_id' => 99, 'id' => 99, 'tipo' => 'integracion']]);
    chk('PUT con tenant_id / id / tipo en el cuerpo: se ignoran, la escritura va a la empresa del token (2)',
        $http === 200 && $ieLlamadas($llamadas, 'set') === [['set', 2, true]], $llamadas);

    // PUT: si la base falla (p. ej. la migracion 013 sin correr) no se dice que se guardo
    [$http, $json, $llamadas, $crudo] = $ieCorrerCfg(['metodo' => 'PUT', 'validation' => $ieAdmin, 'body' => ['bloquear_sin_existencia' => true], 'master_falla' => true]);
    chk('PUT con la columna ausente (master sin migrar): 500 con texto para el usuario, sin el detalle SQL y sin fila de auditoria',
        $http === 500 && ($json['status'] ?? null) === false && is_string($json['error'] ?? null) && !str_contains($json['error'], 'SQLSTATE')
        && !str_contains($json['error'], 'inventario_estricto') && $ieLlamadas($llamadas, 'auditoria') === [], $crudo);
    [$http, $json, $llamadas, $crudo] = $ieCorrerCfg(['metodo' => 'PUT', 'validation' => $ieAdmin, 'body' => ['bloquear_sin_existencia' => true], 'tipo_guardado' => 'integracion']);
    chk('PUT cuyo UPDATE no toco nada (la empresa no es tipo app y la lectura de vuelta no coincide): no responde que se guardo ni audita',
        $http !== 200 && ($json['status'] ?? null) === false && $ieLlamadas($llamadas, 'auditoria') === [], $crudo);

    // PUT: el cambio ya esta guardado y auditado cuando se arma la respuesta. Si el CONTEO de
    // productos falla ahi (la base del tenant se cae o se agota el tiempo), el admin no recibe
    // un 500 por un cambio que si se aplico: responde 200 con la bandera guardada, el conteo
    // cae a 0 y el fallo va al error_log.
    foreach ([['enciende', true, false], ['apaga', false, true]] as [$que, $pedido, $estaba]) {
        [$http, $json, $llamadas, $crudo] = $ieCorrerCfg(['metodo' => 'PUT', 'validation' => $ieAdmin, 'body' => ['bloquear_sin_existencia' => $pedido],
            'estricto_actual' => $estaba, 'sin_existencia' => 4, 'conteo_falla' => true]);
        $audit = $ieLlamadas($llamadas, 'auditoria')[0][1] ?? [];
        chk("PUT admin {$que} con el conteo caido: 200 con la bandera guardada (" . var_export($pedido, true) . ') y productos_sin_existencia 0 (numero, no null)',
            $http === 200 && ($json['status'] ?? null) === true
            && ($json['data'] ?? null) === ['bloquear_sin_existencia' => $pedido, 'productos_sin_existencia' => 0], $crudo);
        chk("PUT admin {$que} con el conteo caido: el cambio se escribio UNA vez y se audito igual (INVENTARIO_CONFIG, antes y despues)",
            $ieLlamadas($llamadas, 'set') === [['set', 2, $pedido]] && count($ieLlamadas($llamadas, 'auditoria')) === 1
            && ($audit['action'] ?? null) === 'INVENTARIO_CONFIG' && ($audit['old_values'] ?? null) === ['bloquear_sin_existencia' => $estaba]
            && ($audit['new_values'] ?? null) === ['bloquear_sin_existencia' => $pedido], $llamadas);
        chk("PUT admin {$que} con el conteo caido: el fallo del conteo va al error_log con [inventario] y el detalle, no al usuario",
            str_contains($crudo, '[inventario]') && str_contains($crudo, 'MySQL server has gone away')
            && !str_contains((string) ($json['error'] ?? ''), 'MySQL') && !isset($json['error']), $crudo);
    }
    // GET: sin cambio que proteger, un conteo caido sigue siendo un error (no se inventa un 0).
    [$http, $json, , $crudo] = $ieCorrerCfg(['metodo' => 'GET', 'validation' => $ieAdmin, 'conteo_falla' => true]);
    chk('GET con el conteo caido: no responde 200 (el conteo tolerante es solo del PUT, que ya guardo el cambio)',
        $http !== 200 && ($json['status'] ?? null) !== true, $crudo);
    chk('controlador: el conteo se tolera SOLO en el PUT (invConfiguracionPayload con el tercer argumento true, despues de la auditoria); el GET llama sin el',
        str_contains($ieCfgFn, 'invConfiguracionPayload($modelo, $despues, true)')
        && substr_count($ieCfgFn, 'invConfiguracionPayload(') === 2
        && str_contains($ieCfgFn, 'invConfiguracionPayload($modelo, TenantResolver::inventarioEstricto())')
        && strpos($ieCfgFn, 'AuditLogger::log(') < strpos($ieCfgFn, 'invConfiguracionPayload($modelo, $despues, true)'), $ieCfgFn);
    chk('controlador: invConfiguracionPayload atrapa Throwable al contar solo cuando se le pide, anota "[inventario]" y cae a 0 (la clave sigue siendo un numero)',
        preg_match('/bool \$tolerarFalloDelConteo = false/', $ieCfgPayloadFn) === 1
        && str_contains($ieCfgPayloadFn, 'catch (Throwable $e)') && str_contains($ieCfgPayloadFn, 'if (!$tolerarFalloDelConteo)')
        && str_contains($ieCfgPayloadFn, 'throw $e;') && str_contains($ieCfgPayloadFn, "error_log('[inventario]"), $ieCfgPayloadFn);

    // Otros metodos sobre la configuracion
    foreach (['POST', 'DELETE', 'PATCH'] as $metodo) {
        [$http, $json, $llamadas, $crudo] = $ieCorrerCfg(['metodo' => $metodo, 'validation' => $ieAdmin, 'body' => ['bloquear_sin_existencia' => true]]);
        chk($metodo . ' sobre /configuracion: 405 y no escribe', $http === 405 && ($json['status'] ?? null) === false && $ieLlamadas($llamadas, 'set') === [], $crudo);
    }
}

// --- brandingController: inventario_estricto en GET /api/branding ---
$ieCtlBranding = ie_fuente('src/Controllers/brandingController.php');
$ieBrCurrent = $ieFuncion($ieCtlBranding, 'brCurrent');
chk("brandingController: brCurrent devuelve 'inventario_estricto' (bool), y solo true para empresas tipo app con la columna en 1",
    $ieBrCurrent !== '' && preg_match("/'inventario_estricto'\s*=>\s*\(\\\$tenant\['tipo'\] \?\? 'app'\) === 'app'\s*&&\s*\(int\) \(\\\$tenant\['inventario_estricto'\] \?\? 0\) === 1,/", $ieBrCurrent) === 1, $ieBrCurrent);
chk("brandingController: el PUT de branding sigue sin aceptar inventario_estricto (se cambia solo por /api/inventario/configuracion)",
    !str_contains($ieFuncion($ieCtlBranding, 'brValidateBranding'), 'inventario_estricto'));

// --- docs: lo que cambia con la tarea queda escrito ---
$ieDocApi = ie_fuente('docs/api/inventario.md');
$ieDocAud = ie_fuente('docs/modules/auditoria.md');
chk('docs/api/inventario.md documenta GET y PUT /api/inventario/configuracion, el 403/409/422, y el 422 STOCK_INSUFICIENTE',
    str_contains($ieDocApi, '/api/inventario/configuracion') && str_contains($ieDocApi, 'bloquear_sin_existencia')
    && str_contains($ieDocApi, 'productos_sin_existencia') && str_contains($ieDocApi, 'STOCK_INSUFICIENTE') && str_contains($ieDocApi, 'inventario_estricto'));
chk('docs/modules/auditoria.md lista INVENTARIO_CONFIG en el vocabulario y en la tabla de acciones',
    substr_count($ieDocAud, 'INVENTARIO_CONFIG') >= 2);

// ---------------------------------------------------------------------------
// 7) El formulario de producto con el interruptor encendido:
//    productModel::existenciaInicialDelLibro y existenciaAGuardarEnEdicion (las
//    dos decisiones, puras), saveProduct (la existencia con que se abre un
//    producto entra por el libro, en la misma transaccion que el producto) y
//    updateProduct (con el interruptor encendido no escribe stock), con una
//    conexion falsa que lleva las filas de products, anota el orden de cada
//    escritura y de begin / commit / rollback, y devuelve sus filas al estado
//    de antes en un rollback; y el cableado de productController, que se lee
//    como texto (incluirlo manda headers, valida el token y corre el router).
//    Con el interruptor apagado el camino es el de hoy. El camino real contra
//    MySQL lo prueba tools/test_inventario_stock_db.php (tarea 8).
// ---------------------------------------------------------------------------
echo "\n== 7) Producto: la existencia inicial entra por el libro y la edicion no escribe stock ==\n";

require_once __DIR__ . '/../src/Models/productModel.php';

final class IeProdSentencia extends PDOStatement
{
    public ?IeProdConexion $con = null;
    public string $sql = '';
    /** @var list<array<string,mixed>> */
    public array $filas = [];

    public function execute(?array $params = null): bool
    {
        $params ??= [];
        $sql = preg_replace('/\s+/', ' ', $this->sql);
        $c = $this->con;
        $c->consultas[] = ['sql' => $sql, 'params' => $params];
        $this->filas = [];
        foreach ($c->fallas as $texto => $excepcion) {
            if (str_contains($sql, $texto)) {
                throw $excepcion;
            }
        }
        // Las escrituras y los bloqueos que importan para el orden.
        foreach (['INSERT INTO products', 'UPDATE products SET sku', 'INSERT INTO inventory_adjustments',
                  'INSERT INTO inventory_movements', 'SELECT stock FROM products WHERE id = :id FOR UPDATE'] as $marca) {
            if (str_starts_with($sql, $marca)) {
                $c->orden[] = $marca;
            }
        }
        $stockTexto = static fn($v): ?string => $v === null ? null : number_format((float) $v, 3, '.', '');
        if (str_starts_with($sql, 'SELECT id FROM warehouses WHERE nombre')) {
            if (!$c->sinAlmacen) {
                $this->filas[] = ['id' => '1'];
            }
        } elseif (str_starts_with($sql, 'INSERT INTO products')) {
            $id = $c->nuevoId('products');
            $c->productos[$id] = [
                'nombre' => $params[':nombre'], 'sku' => $params[':sku'], 'unidad_medida' => $params[':unidad_medida'],
                'stock' => $stockTexto($params[':stock']), 'costo' => number_format((float) $params[':costo'], 2, '.', ''),
                'warehouse_id' => (string) $params[':warehouse_id'], 'indicador_bien_servicio' => (string) $params[':ibs'],
            ];
        } elseif (str_starts_with($sql, 'INSERT INTO inventory_adjustments')) {
            $c->ajustes[$c->nuevoId('ajustes')] = $params;
        } elseif (str_starts_with($sql, 'INSERT INTO inventory_movements')) {
            $c->nuevoId('movimientos');
            $c->movimientos[] = $params;
        } elseif (str_starts_with($sql, 'SELECT id, nombre, sku, unidad_medida, stock, costo, warehouse_id FROM products WHERE id = :id FOR UPDATE')) {
            // El bloqueo del libro: la fila de verdad, con el saldo que dejaron los UPDATE.
            $id = (int) $params[':id'];
            if (isset($c->productos[$id])) {
                $this->filas[] = ['id' => (string) $id] + $c->productos[$id];
            }
        } elseif (str_starts_with($sql, 'SELECT stock FROM products WHERE id = :id FOR UPDATE')) {
            // El bloqueo de la edicion: el saldo de ahora (o el que se le diga que vio, un cambio a media peticion).
            $id = (int) $params[':id'];
            if (isset($c->productos[$id]) && !$c->borrarAlBloquear) {
                $this->filas[] = ['stock' => $c->stockAlBloquear !== false ? $c->stockAlBloquear : $c->productos[$id]['stock']];
            }
        } elseif (str_starts_with($sql, 'UPDATE products SET stock = :nueva WHERE id = :id')) {
            $c->productos[(int) $params[':id']]['stock'] = $stockTexto($params[':nueva']);
        } elseif (str_starts_with($sql, 'UPDATE products SET sku')) {
            $id = (int) $params[':id'];
            $c->ediciones[] = $params;
            if (isset($c->productos[$id])) {
                $c->productos[$id]['stock'] = $stockTexto($params[':stock'] ?? $c->productos[$id]['stock']);
            }
        } elseif (str_starts_with($sql, 'SELECT p.*, c.nombre AS categoria_nombre')) {
            // getProducts: la fila con los nombres de categoria y almacen.
            $id = (int) $params[':id'];
            if (isset($c->productos[$id])) {
                $this->filas[] = ['id' => (string) $id] + $c->productos[$id] + ['categoria_nombre' => null, 'almacen_nombre' => 'Almacén Principal'];
            }
        } elseif (str_contains($sql, 'FROM inventory_adjustments a LEFT JOIN warehouses')) {
            // getAjuste, al final de crearAjuste.
            $id = (int) $params[':id'];
            if (isset($c->ajustes[$id])) {
                $this->filas[] = ['id' => (string) $id, 'motivo' => $c->ajustes[$id][':motivo'], 'codigo' => $c->ajustes[$id][':codigo']];
            }
        } elseif (str_contains($sql, 'FROM inventory_movements m LEFT JOIN products')) {
            $this->filas = array_map(static fn(array $m) => ['cantidad' => (string) $m[':cantidad'], 'product_id' => (string) $m[':product_id']], $c->movimientos);
        }
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return array_shift($this->filas) ?? false;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->filas;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        $fila = array_shift($this->filas);
        return $fila === null ? false : (array_values($fila)[$column] ?? false);
    }
}

final class IeProdConexion extends PDO
{
    public array $consultas = [];
    /** BEGIN / COMMIT / ROLLBACK y las escrituras que importan, en el orden en que ocurrieron. */
    public array $orden = [];
    public bool $enTransaccion = false;
    /** @var array<string,Throwable> texto de SQL => lo que lanza execute() cuando el SQL lo contiene */
    public array $fallas = [];
    public array $movimientos = [];   // parametros de cada INSERT INTO inventory_movements
    public array $ajustes = [];       // id => parametros de cada INSERT INTO inventory_adjustments
    public array $ediciones = [];     // parametros de cada UPDATE products SET sku = ... (la edicion del formulario)
    public bool $sinAlmacen = false;
    /** Lo que ve el bloqueo de la edicion; false = el saldo que tiene la fila (un cambio a media peticion lo distingue). */
    public string|false|null $stockAlBloquear = false;
    /** true = al bloquear, la fila ya no esta (otra persona borro el producto entre la lectura y el bloqueo). */
    public bool $borrarAlBloquear = false;
    public int $ultimoId = 0;
    /** @var array<string,int> el ultimo id dado por tabla: el primero sera 30, 700 y 9000 (los tres no se parecen entre si) */
    private array $contadores = ['products' => 29, 'ajustes' => 699, 'movimientos' => 8999];
    private ?array $respaldo = null;

    public function __construct(public array $productos = [])
    {
        // Sin parent::__construct: nunca abre una conexion.
    }

    public function nuevoId(string $tabla): int
    {
        return $this->ultimoId = ++$this->contadores[$tabla];
    }

    private function sentencia(string $sql): IeProdSentencia
    {
        $s = new IeProdSentencia();
        $s->con = $this;
        $s->sql = $sql;
        return $s;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return $this->sentencia($query);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->consultas[] = ['sql' => preg_replace('/\s+/', ' ', $query), 'params' => []];
        return $this->sentencia($query);   // siguienteCodigo(): sin ajustes previos, fetchColumn() da false
    }

    public function beginTransaction(): bool
    {
        $this->orden[] = 'BEGIN';
        $this->enTransaccion = true;
        $this->respaldo = ['productos' => $this->productos, 'movimientos' => $this->movimientos, 'ajustes' => $this->ajustes];
        return true;
    }

    public function commit(): bool
    {
        $this->orden[] = 'COMMIT';
        $this->enTransaccion = false;
        return true;
    }

    public function rollBack(): bool
    {
        // Como PDO: sin transaccion abierta rollBack() lanza.
        if (!$this->enTransaccion) {
            throw new PDOException('There is no active transaction');
        }
        $this->orden[] = 'ROLLBACK';
        $this->enTransaccion = false;
        if ($this->respaldo !== null) {
            [$this->productos, $this->movimientos, $this->ajustes] = [$this->respaldo['productos'], $this->respaldo['movimientos'], $this->respaldo['ajustes']];
        }
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->enTransaccion;
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return (string) $this->ultimoId;
    }
}

$ieTenantProd = static function (bool $estricto) use ($ieTenantActual): void {
    $ieTenantActual(['id' => 2, 'tipo' => 'app', 'inventario_estricto' => $estricto ? 1 : 0]);
};

// --- existenciaInicialDelLibro: la cantidad que se registra por el libro al crear ---
if (!method_exists(productModel::class, 'existenciaInicialDelLibro')) {
    chk('productModel::existenciaInicialDelLibro existe', false);
} else {
    $rfIni = new ReflectionMethod(productModel::class, 'existenciaInicialDelLibro');
    chk('existenciaInicialDelLibro(object $data, bool $estricto): ?float es publica y estatica',
        $rfIni->isPublic() && $rfIni->isStatic() && (string) $rfIni->getReturnType() === '?float'
        && (string) $rfIni->getParameters()[0]->getType() === 'object' && (string) $rfIni->getParameters()[1]->getType() === 'bool');
    $ieIni = static fn(array $datos, bool $estricto = true): ?float => productModel::existenciaInicialDelLibro((object) $datos, $estricto);

    chk('inicial: interruptor encendido, Bien, stock 5 -> 5.0', $ieIni(['stock' => 5, 'indicador_bien_servicio' => 1]) === 5.0, $ieIni(['stock' => 5, 'indicador_bien_servicio' => 1]));
    chk("inicial: stock como texto '12.5' -> 12.5 (el JSON del formulario puede traerlo asi)", $ieIni(['stock' => '12.5']) === 12.5);
    chk('inicial: sin indicador_bien_servicio se trata como Bien (igual que el INSERT: por defecto 1) -> 5.0', $ieIni(['stock' => 5]) === 5.0);
    chk("inicial: indicador_bien_servicio '1' (texto) es Bien -> 3.0", $ieIni(['stock' => 3, 'indicador_bien_servicio' => '1']) === 3.0);
    chk('inicial: se redondea a 3 decimales, que es lo que guarda la columna (12.5004 -> 12.5)', $ieIni(['stock' => 12.5004]) === 12.5);
    chk('inicial: stock null -> null', $ieIni(['stock' => null]) === null);
    chk("inicial: stock '' (campo vacio del formulario) -> null", $ieIni(['stock' => '']) === null);
    chk('inicial: stock 0 -> null (no hay nada que registrar)', $ieIni(['stock' => 0]) === null);
    chk("inicial: stock '0' y 0.0 -> null", $ieIni(['stock' => '0']) === null && $ieIni(['stock' => 0.0]) === null);
    chk('inicial: 0.0004 se redondea a 0 -> null (el libro lo descartaria como linea sin movimiento)', $ieIni(['stock' => 0.0004]) === null);
    chk("inicial: texto no numerico ('abc') -> null", $ieIni(['stock' => 'abc']) === null);
    chk('inicial: sin la propiedad stock -> null', $ieIni(['nombre' => 'Cemento gris']) === null);
    chk('inicial: servicio (indicador_bien_servicio 2) con stock 5 -> null', $ieIni(['stock' => 5, 'indicador_bien_servicio' => 2]) === null);
    chk("inicial: servicio como texto '2' -> null", $ieIni(['stock' => 5, 'indicador_bien_servicio' => '2']) === null);
    chk('inicial: interruptor apagado, Bien con stock 5 -> null (el INSERT escribe stock como hoy)', $ieIni(['stock' => 5, 'indicador_bien_servicio' => 1], false) === null);
    chk('inicial: una existencia negativa se devuelve tal cual (-3.0): saveProduct la rechaza, el libro no abre productos en rojo',
        $ieIni(['stock' => -3]) === -3.0);
}

// --- existenciaAGuardarEnEdicion: que stock escribe la edicion ---
if (!method_exists(productModel::class, 'existenciaAGuardarEnEdicion')) {
    chk('productModel::existenciaAGuardarEnEdicion existe', false);
} else {
    $rfEdi = new ReflectionMethod(productModel::class, 'existenciaAGuardarEnEdicion');
    chk('existenciaAGuardarEnEdicion(bool $estricto, $enviada, ?float $actual): ?float es publica y estatica',
        $rfEdi->isPublic() && $rfEdi->isStatic() && (string) $rfEdi->getReturnType() === '?float'
        && (string) $rfEdi->getParameters()[0]->getType() === 'bool' && (string) $rfEdi->getParameters()[2]->getType() === '?float');
    $ieEdi = static fn(bool $estricto, $enviada, ?float $actual): ?float => productModel::existenciaAGuardarEnEdicion($estricto, $enviada, $actual);

    chk('edicion con interruptor encendido: devuelve el valor actual (5.0) sea lo que sea que se envio (99)', $ieEdi(true, 99, 5.0) === 5.0);
    chk('edicion con interruptor encendido: enviado null, texto, vacio o negativo -> sigue el actual',
        $ieEdi(true, null, 5.0) === 5.0 && $ieEdi(true, '7.5', 5.0) === 5.0 && $ieEdi(true, '', 5.0) === 5.0 && $ieEdi(true, -4, 5.0) === 5.0);
    chk('edicion con interruptor encendido: un saldo negativo actual (-3.0) se conserva tal cual', $ieEdi(true, 10, -3.0) === -3.0);
    chk('edicion con interruptor encendido: actual null (nunca tuvo existencia) -> null aunque se envie 7', $ieEdi(true, 7, null) === null);
    chk('edicion con interruptor apagado: devuelve lo enviado (7 -> 7.0), como hoy', $ieEdi(false, 7, 5.0) === 7.0);
    chk("edicion con interruptor apagado: texto '7.5' -> 7.5 y 12.3456 -> 12.346 (3 decimales, como bindParams)",
        $ieEdi(false, '7.5', 5.0) === 7.5 && $ieEdi(false, 12.3456, 5.0) === 12.346);
    chk('edicion con interruptor apagado: enviado 0 -> 0.0 (cero no es "sin dato")', $ieEdi(false, 0, 5.0) === 0.0);
    chk("edicion con interruptor apagado: enviado null o '' -> null, como hoy (el actual no cuenta)", $ieEdi(false, null, 5.0) === null && $ieEdi(false, '', 5.0) === null);
}

// --- problemaExistenciaInicial: el 422 de una existencia inicial negativa (lo usa el controlador antes de guardar) ---
if (!method_exists(productModel::class, 'problemaExistenciaInicial')) {
    chk('productModel::problemaExistenciaInicial existe', false);
} else {
    $rfPrIni = new ReflectionMethod(productModel::class, 'problemaExistenciaInicial');
    chk('problemaExistenciaInicial(object $data, bool $estricto): ?string es publica y estatica',
        $rfPrIni->isPublic() && $rfPrIni->isStatic() && (string) $rfPrIni->getReturnType() === '?string'
        && (string) $rfPrIni->getParameters()[0]->getType() === 'object' && (string) $rfPrIni->getParameters()[1]->getType() === 'bool');
    $iePrIni = static fn(array $datos, bool $estricto = true): ?string => productModel::problemaExistenciaInicial((object) $datos, $estricto);

    $ieMsgNeg = $iePrIni(['stock' => -3]);
    chk('problema inicial: interruptor encendido, Bien con stock -3 -> texto para quien llena el formulario (existencia, negativa, manda a Ajuste)',
        is_string($ieMsgNeg) && str_contains($ieMsgNeg, 'existencia inicial') && str_contains($ieMsgNeg, 'negativa') && str_contains($ieMsgNeg, 'Ajuste'), $ieMsgNeg);
    chk("problema inicial: el texto no habla de campos del JSON ni de detalles tecnicos ('stock', 'libro', 'INVENTARIO_INICIAL')",
        is_string($ieMsgNeg) && !str_contains($ieMsgNeg, 'stock') && !str_contains($ieMsgNeg, 'libro') && !str_contains($ieMsgNeg, 'INVENTARIO_INICIAL'), $ieMsgNeg);
    chk("problema inicial: stock '-0.5' (texto) tambien es negativo", $iePrIni(['stock' => '-0.5']) !== null);
    chk('problema inicial: stock 5, 0, null, vacio o sin la propiedad -> null (no hay nada que rechazar)',
        $iePrIni(['stock' => 5]) === null && $iePrIni(['stock' => 0]) === null && $iePrIni(['stock' => null]) === null
        && $iePrIni(['stock' => '']) === null && $iePrIni(['nombre' => 'Cemento gris']) === null);
    chk('problema inicial: -0.0004 se redondea a 0 -> null (igual que existenciaInicialDelLibro: la columna guarda 3 decimales)', $iePrIni(['stock' => -0.0004]) === null);
    chk('problema inicial: un servicio con stock -3 -> null (un servicio no tiene existencia: no pasa por el libro)',
        $iePrIni(['stock' => -3, 'indicador_bien_servicio' => 2]) === null);
    chk('problema inicial: interruptor apagado con stock -3 -> null (se guarda como hoy, sin libro)', $iePrIni(['stock' => -3], false) === null);
}

// --- inventarioEstricto: el controlador lo lee por el modelo (una sola fuente) ---
if (!method_exists(productModel::class, 'inventarioEstricto')) {
    chk('productModel::inventarioEstricto existe', false);
} else {
    $rfIeEst = new ReflectionMethod(productModel::class, 'inventarioEstricto');
    chk('productModel::inventarioEstricto(): bool es publica y estatica (el controlador la usa)',
        $rfIeEst->isPublic() && $rfIeEst->isStatic() && (string) $rfIeEst->getReturnType() === 'bool');
    $ieTenantActual(['id' => 2, 'tipo' => 'app', 'inventario_estricto' => 1]);
    $ieEstEncendido = productModel::inventarioEstricto();
    $ieTenantActual(['id' => 2, 'tipo' => 'app', 'inventario_estricto' => 0]);
    $ieEstApagado = productModel::inventarioEstricto();
    $ieTenantActual(null);
    chk('productModel::inventarioEstricto(): true con el interruptor en 1, false con 0 y false sin empresa resuelta',
        $ieEstEncendido === true && $ieEstApagado === false && productModel::inventarioEstricto() === false);
}

// --- nuevosValoresParaBitacora: lo que se audita en un PUT de producto ---
if (!method_exists(productModel::class, 'nuevosValoresParaBitacora')) {
    chk('productModel::nuevosValoresParaBitacora existe', false);
} else {
    $rfBit = new ReflectionMethod(productModel::class, 'nuevosValoresParaBitacora');
    chk('nuevosValoresParaBitacora(object $enviados, ?array $guardado, bool $estricto): object es publica y estatica',
        $rfBit->isPublic() && $rfBit->isStatic() && (string) $rfBit->getReturnType() === 'object'
        && (string) $rfBit->getParameters()[0]->getType() === 'object' && (string) $rfBit->getParameters()[1]->getType() === '?array'
        && (string) $rfBit->getParameters()[2]->getType() === 'bool');
    $ieBitEnv = static fn(array $extra = []): object => (object) ($extra + ['id' => 30, 'nombre' => 'Cemento gris', 'precio' => 100, 'stock' => 99]);
    $ieBitFila = ['id' => 30, 'nombre' => 'Cemento gris', 'stock' => 5.0];

    $enviados = $ieBitEnv();
    $valores = productModel::nuevosValoresParaBitacora($enviados, $ieBitFila, true);
    chk('bitacora encendido: se envio stock 99 con 5 guardado -> la bitacora lleva 5.0 (el cambio de existencia nunca ocurrio)', ($valores->stock ?? 'x') === 5.0, $valores);
    chk('bitacora encendido: lo demas del cuerpo va tal cual (nombre, precio, id)',
        $valores->nombre === 'Cemento gris' && $valores->precio === 100 && $valores->id === 30);
    chk('bitacora encendido: no toca el objeto del llamador (el stock enviado sigue siendo 99 en $_PUT)', $enviados->stock === 99 && $valores !== $enviados);
    $sinStock = $ieBitEnv();
    unset($sinStock->stock);
    $valores = productModel::nuevosValoresParaBitacora($sinStock, $ieBitFila, true);
    chk('bitacora encendido sin el campo stock (cliente nuevo): lleva 5.0 igual (si faltara, la bitacora lo mostraria como "se vacio")',
        property_exists($valores, 'stock') && $valores->stock === 5.0, $valores);
    $valores = productModel::nuevosValoresParaBitacora($ieBitEnv(), ['stock' => null] + $ieBitFila, true);
    chk('bitacora encendido con la existencia guardada en NULL: lleva NULL (la clave existe)', property_exists($valores, 'stock') && $valores->stock === null, $valores);
    $valores = productModel::nuevosValoresParaBitacora($ieBitEnv(), ['stock' => -3.0] + $ieBitFila, true);
    chk('bitacora encendido con saldo negativo guardado (-3): lleva -3.0', $valores->stock === -3.0, $valores);
    $enviados = $ieBitEnv();
    chk('bitacora apagado: devuelve el mismo objeto enviado, con su stock (99), como hoy',
        productModel::nuevosValoresParaBitacora($enviados, $ieBitFila, false) === $enviados && $enviados->stock === 99);
    $enviados = $ieBitEnv();
    chk('bitacora encendido sin fila guardada (el producto no existe): el mismo objeto, sin inventar un stock',
        productModel::nuevosValoresParaBitacora($enviados, null, true) === $enviados && $enviados->stock === 99);
}

// --- saveProduct con una conexion falsa ---
$ieProdDatos = static fn(array $extra = []): object => (object) ($extra + [
    'nombre' => 'Cemento gris', 'sku' => 'CEM-01', 'precio' => 100, 'costo' => 60, 'unidad_medida' => '43',
    'indicador_bien_servicio' => 1, 'warehouse_id' => 1, 'stock' => 5,
]);
$ieProdModelo = static function (array $productos = []): array {
    $ref = new ReflectionClass(productModel::class);
    $m = $ref->newInstanceWithoutConstructor();
    $c = new IeProdConexion($productos);
    $ref->getProperty('conexion')->setValue($m, $c);
    return [$m, $c];
};
$ieProdGuardar = static function (object $datos, bool $estricto, ?int $usuario = 7, array $productos = [], array $fallas = [], bool $sinAlmacen = false) use ($ieTenantProd, $ieProdModelo): array {
    $ieTenantProd($estricto);
    [$m, $c] = $ieProdModelo($productos);
    $c->fallas = $fallas;
    $c->sinAlmacen = $sinAlmacen;
    $res = null;
    $log = ie_con_log(static function () use ($m, $datos, $usuario, &$res): void {
        $res = $m->saveProduct($datos, $usuario);
    });
    return [$res, $c, $log];
};
$ieProdEscrituras = static fn(IeProdConexion $c): array => array_values(array_filter(
    $c->orden, static fn(string $e) => in_array($e, ['BEGIN', 'COMMIT', 'ROLLBACK', 'INSERT INTO products', 'INSERT INTO inventory_adjustments', 'INSERT INTO inventory_movements'], true)));
$ieProdInserts = static fn(IeProdConexion $c): array => array_values(array_filter($c->consultas, static fn(array $q) => str_starts_with($q['sql'], 'INSERT INTO products')));

$rfGuardar = new ReflectionMethod(productModel::class, 'saveProduct');
chk('saveProduct($data, ?int $userId = null): el segundo argumento es opcional y por defecto null (los llamadores de hoy siguen valiendo)',
    $rfGuardar->getNumberOfParameters() === 2 && $rfGuardar->getParameters()[1]->isOptional()
    && (string) $rfGuardar->getParameters()[1]->getType() === '?int' && $rfGuardar->getParameters()[1]->getDefaultValue() === null);

// Interruptor apagado: el camino de hoy, byte a byte.
[$res, $c] = $ieProdGuardar($ieProdDatos(), false);
chk('saveProduct apagado: devuelve success con el id del producto (30) como hoy',
    $res === ['success', 'Product saved', 30], $res);
chk('saveProduct apagado: un solo INSERT INTO products con :stock = 5.0, y nada mas (ni transaccion, ni ajuste, ni movimiento)',
    count($ieProdInserts($c)) === 1 && $ieProdInserts($c)[0]['params'][':stock'] === 5.0
    && $c->orden === ['INSERT INTO products'] && $c->ajustes === [] && $c->movimientos === [], [$c->orden, $ieProdInserts($c)]);
chk('saveProduct apagado: la fila queda con la existencia que se tecleo (5.000)', ($c->productos[30]['stock'] ?? null) === '5.000', $c->productos);
[$res, $c] = $ieProdGuardar($ieProdDatos(['stock' => '']), false);
chk("saveProduct apagado con stock '': :stock null, igual que hoy", $ieProdInserts($c)[0]['params'][':stock'] === null && $c->orden === ['INSERT INTO products']);
[$res, $c] = $ieProdGuardar($ieProdDatos(['stock' => -3]), false);
chk('saveProduct apagado con stock -3: se guarda como hoy (-3.0, sin libro)', $res[0] === 'success' && $ieProdInserts($c)[0]['params'][':stock'] === -3.0 && $c->orden === ['INSERT INTO products']);

// Interruptor encendido, con existencia inicial: por el libro, en una transaccion.
[$res, $c] = $ieProdGuardar($ieProdDatos(), true);
chk('saveProduct encendido con stock 5: success con el id del PRODUCTO (30), no el del ajuste (700) ni el del movimiento (9000)',
    $res === ['success', 'Product saved', 30], $res);
chk('saveProduct encendido con stock 5: BEGIN, INSERT del producto, ajuste, movimiento y COMMIT, en ese orden (y nunca ROLLBACK)',
    $ieProdEscrituras($c) === ['BEGIN', 'INSERT INTO products', 'INSERT INTO inventory_adjustments', 'INSERT INTO inventory_movements', 'COMMIT'], $c->orden);
chk('saveProduct encendido con stock 5: el producto se inserta con :stock NULL (la existencia la pone el libro, no el INSERT)',
    count($ieProdInserts($c)) === 1 && $ieProdInserts($c)[0]['params'][':stock'] === null, $ieProdInserts($c));
$ieAjuste = $c->ajustes[700] ?? [];
chk('saveProduct encendido con stock 5: el ajuste es motivo INVENTARIO_INICIAL, con el usuario del token (7) y el almacen del producto (1)',
    ($ieAjuste[':motivo'] ?? null) === 'INVENTARIO_INICIAL' && ($ieAjuste[':user_id'] ?? null) === 7 && ($ieAjuste[':warehouse_id'] ?? null) === 1
    && array_key_exists(':anula_a_id', $ieAjuste) && $ieAjuste[':anula_a_id'] === null, $ieAjuste);
$ieMov = $c->movimientos[0] ?? [];
chk('saveProduct encendido con stock 5: un movimiento de +5 del producto 30, saldo 0 -> 5, ligado al ajuste 700, con el usuario 7',
    count($c->movimientos) === 1 && ($ieMov[':product_id'] ?? null) === 30 && ($ieMov[':cantidad'] ?? null) === 5.0
    && ($ieMov[':anterior'] ?? null) === 0.0 && ($ieMov[':nueva'] ?? null) === 5.0
    && ($ieMov[':ref_tipo'] ?? null) === 'ajuste' && ($ieMov[':ref_id'] ?? null) === 700 && ($ieMov[':user_id'] ?? null) === 7
    && ($ieMov[':tipo'] ?? null) === 'AJUSTE', $ieMov);
chk('saveProduct encendido con stock 5: el movimiento se valora al costo del producto (60.00 x 5 = 300.00)',
    ($ieMov[':costo'] ?? null) === 60.0 && ($ieMov[':valor'] ?? null) === 300.0, $ieMov);
chk('saveProduct encendido con stock 5: la fila termina con la existencia 5.000 (la que dejo el libro)', ($c->productos[30]['stock'] ?? null) === '5.000', $c->productos[30] ?? null);

[$res, $c] = $ieProdGuardar($ieProdDatos(['warehouse_id' => 3]), true);
chk('saveProduct encendido: el movimiento y el ajuste van al almacen elegido para el producto (3), no al primero que haya',
    ($c->movimientos[0][':warehouse_id'] ?? null) === 3 && ($c->ajustes[700][':warehouse_id'] ?? null) === 3 && ($c->productos[30]['warehouse_id'] ?? null) === '3',
    [$c->movimientos, $c->ajustes]);
[$res, $c] = $ieProdGuardar($ieProdDatos(['warehouse_id' => null]), true);
chk('saveProduct encendido sin almacen elegido: usa "Almacén Principal" tambien para el libro (1)',
    $res[0] === 'success' && ($c->movimientos[0][':warehouse_id'] ?? null) === 1 && ($c->ajustes[700][':warehouse_id'] ?? null) === 1, [$res, $c->movimientos]);
[$res, $c] = $ieProdGuardar($ieProdDatos(['warehouse_id' => null]), true, 7, [], [], true);
chk('saveProduct encendido sin almacen y sin "Almacén Principal": el mismo error de hoy y no abre transaccion ni inserta',
    $res[0] === 'error' && str_contains($res[1], 'Elige el almacén') && $c->orden === [], [$res, $c->orden]);
[$res, $c] = $ieProdGuardar($ieProdDatos(), true, null);
chk('saveProduct encendido sin usuario (null): el ajuste y el movimiento llevan user_id NULL, no se inventa uno',
    $res[0] === 'success' && array_key_exists(':user_id', $c->ajustes[700] ?? []) && $c->ajustes[700][':user_id'] === null
    && array_key_exists(':user_id', $c->movimientos[0] ?? []) && $c->movimientos[0][':user_id'] === null, [$c->ajustes, $c->movimientos]);

// Interruptor encendido, sin nada que registrar: igual que hoy.
$ieSinStock = $ieProdDatos();
unset($ieSinStock->stock);
foreach (['stock null' => $ieProdDatos(['stock' => null]), "stock ''" => $ieProdDatos(['stock' => '']), 'stock 0' => $ieProdDatos(['stock' => 0]),
          "stock '0'" => $ieProdDatos(['stock' => '0']), 'sin la propiedad stock' => $ieSinStock] as $que => $datos) {
    [$res, $c] = $ieProdGuardar($datos, true);
    chk('saveProduct encendido con ' . $que . ': un solo INSERT como hoy, sin transaccion ni ajuste ni movimiento',
        $res === ['success', 'Product saved', 30] && $c->orden === ['INSERT INTO products'] && $c->ajustes === [] && $c->movimientos === [], [$res, $c->orden]);
}
[$res, $c] = $ieProdGuardar($ieProdDatos(['stock' => 5, 'indicador_bien_servicio' => 2]), true);
chk('saveProduct encendido con un servicio: no pasa por el libro (un servicio no tiene existencia) y el INSERT queda como hoy',
    $res[0] === 'success' && $c->orden === ['INSERT INTO products'] && $c->movimientos === [], [$res, $c->orden]);

// Existencia negativa al abrir con el interruptor encendido: se rechaza antes de insertar.
[$res, $c] = $ieProdGuardar($ieProdDatos(['stock' => -3]), true);
chk('saveProduct encendido con stock -3: error llano (2 elementos) que manda a Ajuste, sin insertar ni abrir transaccion',
    $res[0] === 'error' && count($res) === 2 && is_string($res[1]) && str_contains($res[1], 'negativa') && str_contains($res[1], 'Ajuste')
    && $c->orden === [] && $c->productos === [], [$res, $c->orden]);
chk('saveProduct encendido con stock -3: el texto es el mismo que da el controlador con 422 (productModel::problemaExistenciaInicial, una sola redaccion)',
    $res[1] === productModel::problemaExistenciaInicial($ieProdDatos(['stock' => -3]), true), $res);

// Un error del libro deshace el producto.
[$res, $c, $log] = $ieProdGuardar($ieProdDatos(), true, 7, [], ['INSERT INTO inventory_movements' => new PDOException('se cayo la conexion')]);
chk('saveProduct encendido con el libro caido: error llano (2 elementos) con texto para el usuario, sin el detalle de la base',
    $res[0] === 'error' && count($res) === 2 && is_string($res[1]) && $res[1] !== '' && !str_contains($res[1], 'se cayo') && !str_contains($res[1], 'SQLSTATE'), $res);
chk('saveProduct encendido con el libro caido: ROLLBACK y nunca COMMIT, y el producto no queda en la base',
    $ieProdEscrituras($c) === ['BEGIN', 'INSERT INTO products', 'INSERT INTO inventory_adjustments', 'ROLLBACK'] && $c->productos === [] && $c->ajustes === [], [$c->orden, $c->productos]);
chk('saveProduct encendido con el libro caido: el detalle tecnico queda en el log ([inventario])', str_contains($log, '[inventario]') && str_contains($log, 'se cayo la conexion'), $log);
chk('saveProduct encendido con el libro caido: el texto habla del PRODUCTO y de su existencia inicial, no de un «ajuste» que nadie pidio',
    is_string($res[1] ?? null) && str_contains($res[1], 'No se pudo guardar el producto con su existencia inicial.')
    && !str_contains(mb_strtolower($res[1]), 'ajuste') && str_contains($res[1], 'Inténtalo de nuevo'), $res);
chk('saveProduct encendido con el libro caido: el log de saveProduct ([productos]) deja constancia de que el producto no se creo',
    str_contains($log, '[productos] saveProduct'), $log);
// Un fallo del libro que no es una excepcion (la carrera por el codigo del ajuste) tambien llega con el texto del producto.
$ieCarrera = new PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'AJ-0001' for key 'uk_codigo'");
(new ReflectionProperty(Exception::class, 'code'))->setValue($ieCarrera, '23000');
$ieCarrera->errorInfo = ['23000', 1062, "Duplicate entry 'AJ-0001' for key 'uk_codigo'"];
[$res, $c, $log] = $ieProdGuardar($ieProdDatos(), true, 7, [], ['INSERT INTO inventory_adjustments' => $ieCarrera]);
chk('saveProduct encendido con la carrera del codigo del ajuste: el texto es el del producto (no «Otro ajuste se guardó al mismo tiempo»), ROLLBACK y nada escrito',
    $res[0] === 'error' && count($res) === 2 && str_contains($res[1], 'No se pudo guardar el producto con su existencia inicial.') && !str_contains(mb_strtolower($res[1]), 'ajuste')
    && $ieProdEscrituras($c) === ['BEGIN', 'INSERT INTO products', 'ROLLBACK'] && $c->productos === [] && $c->enTransaccion === false, [$res, $c->orden]);
chk('saveProduct encendido con la carrera del codigo del ajuste: lo que dijo el libro queda en el log (no se pierde el detalle)',
    str_contains($log, '[productos] saveProduct') && str_contains($log, 'Otro ajuste se guardó al mismo tiempo'), $log);

// SKU repetido con la transaccion abierta: se deshace y se da el mensaje de siempre.
$ieDuplicado = new PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'CEM-01' for key 'uk_sku'");
(new ReflectionProperty(Exception::class, 'code'))->setValue($ieDuplicado, '23000');
$ieDuplicado->errorInfo = ['23000', 1062, "Duplicate entry 'CEM-01' for key 'uk_sku'"];
[$res, $c] = $ieProdGuardar($ieProdDatos(), true, 7, [], ['INSERT INTO products' => $ieDuplicado]);
chk('saveProduct encendido con SKU repetido: "Ya hay otro producto con ese SKU", ROLLBACK (la transaccion no queda abierta) y nada escrito',
    $res === ['error', 'Ya hay otro producto con ese SKU. Usa uno diferente.'] && $ieProdEscrituras($c) === ['BEGIN', 'ROLLBACK'] && $c->enTransaccion === false, [$res, $c->orden]);
[$res, $c] = $ieProdGuardar($ieProdDatos(), false, 7, [], ['INSERT INTO products' => $ieDuplicado]);
chk('saveProduct apagado con SKU repetido: el mismo mensaje de hoy, sin transaccion que deshacer', $res === ['error', 'Ya hay otro producto con ese SKU. Usa uno diferente.'] && $c->orden === [], [$res, $c->orden]);

// --- updateProduct con una conexion falsa ---
$ieProdEditar = static function (object $datos, bool $estricto, array $productos, array $ajustes = []) use ($ieTenantProd, $ieProdModelo): array {
    $ieTenantProd($estricto);
    [$m, $c] = $ieProdModelo($productos);
    foreach ($ajustes as $propiedad => $valor) {
        $c->$propiedad = $valor;
    }
    $res = null;
    $log = ie_con_log(static function () use ($m, $datos, &$res): void {
        $res = $m->updateProduct('30', $datos);
    });
    return [$res, $c, $log];
};
$ieEditOrden = static fn(IeProdConexion $c): array => array_values(array_filter(
    $c->orden, static fn(string $e) => in_array($e, ['BEGIN', 'COMMIT', 'ROLLBACK', 'UPDATE products SET sku', 'SELECT stock FROM products WHERE id = :id FOR UPDATE'], true)));
$ieEditDatos = static fn(array $extra = []): object => $ieProdDatos(['id' => 30] + $extra);
$ieProd30 = [30 => ie_fila('Cemento gris', '5.000', '1', 'CEM-01')];

[$res, $c] = $ieProdEditar($ieEditDatos(['stock' => 99]), true, $ieProd30);
chk('updateProduct encendido, se envia stock 99 con 5 en la base: la edicion guarda 5.0 (el campo se ignora en silencio) y responde success',
    $res === ['success', 'Product updated'] && ($c->ediciones[0][':stock'] ?? null) === 5.0 && $c->productos[30]['stock'] === '5.000', [$res, $c->ediciones]);
chk('updateProduct encendido: lee el saldo con FOR UPDATE dentro de una transaccion y el UPDATE va despues (BEGIN, bloqueo, UPDATE, COMMIT)',
    $ieEditOrden($c) === ['BEGIN', 'SELECT stock FROM products WHERE id = :id FOR UPDATE', 'UPDATE products SET sku', 'COMMIT'], $c->orden);
chk('updateProduct encendido: el resto de los campos se guardan como siempre (nombre, precio, costo)',
    ($c->ediciones[0][':nombre'] ?? null) === 'Cemento gris' && ($c->ediciones[0][':precio'] ?? null) === 100.0 && ($c->ediciones[0][':costo'] ?? null) === 60.0 && ($c->ediciones[0][':id'] ?? null) === 30, $c->ediciones);
[$res, $c] = $ieProdEditar($ieEditDatos(['stock' => null]), true, $ieProd30);
chk('updateProduct encendido, stock null (vacio): sigue 5.0, no se pierde la existencia', ($c->ediciones[0][':stock'] ?? 'x') === 5.0, $c->ediciones);
$sinStock = $ieEditDatos();
unset($sinStock->stock);
[$res, $c] = $ieProdEditar($sinStock, true, $ieProd30);
chk('updateProduct encendido sin el campo stock (un cliente nuevo): sigue 5.0', ($c->ediciones[0][':stock'] ?? 'x') === 5.0, $c->ediciones);
[$res, $c] = $ieProdEditar($ieEditDatos(['stock' => 7]), true, [30 => ie_fila('Cemento gris', null, '1', 'CEM-01')]);
chk('updateProduct encendido con stock NULL en la base: sigue NULL aunque se envie 7 (la existencia entra por Ajuste)', array_key_exists(':stock', $c->ediciones[0] ?? []) && $c->ediciones[0][':stock'] === null, $c->ediciones);
[$res, $c] = $ieProdEditar($ieEditDatos(['stock' => 7]), true, [30 => ie_fila('Cemento gris', '-3.000', '1', 'CEM-01')]);
chk('updateProduct encendido con saldo negativo en la base (-3): se conserva -3.0', ($c->ediciones[0][':stock'] ?? 'x') === -3.0, $c->ediciones);
// Una venta que se lleva existencia entre la lectura del producto y el UPDATE: la edicion no la pisa.
[$res, $c] = $ieProdEditar($ieEditDatos(['stock' => 99]), true, $ieProd30, ['stockAlBloquear' => '3.000']);
chk('updateProduct encendido con una venta en medio (la lectura vio 5, al bloquear ya hay 3): guarda 3.0 y no vuelve a poner 5 (no pisa el libro)',
    ($c->ediciones[0][':stock'] ?? null) === 3.0 && $c->productos[30]['stock'] === '3.000', [$c->ediciones, $c->productos]);
// El producto se borro entre la lectura y el bloqueo.
[$res, $c] = $ieProdEditar($ieEditDatos(['stock' => 99]), true, $ieProd30, ['borrarAlBloquear' => true]);
chk('updateProduct encendido con el producto borrado entre la lectura y el bloqueo: el mensaje de "ya no existe", ROLLBACK y sin UPDATE',
    $res[0] === 'error' && str_contains($res[1], 'ya no existe') && $c->ediciones === []
    && $ieEditOrden($c) === ['BEGIN', 'SELECT stock FROM products WHERE id = :id FOR UPDATE', 'ROLLBACK'] && $c->enTransaccion === false, [$res, $c->orden]);
// Con el interruptor apagado: el camino de hoy, sin transaccion ni bloqueo.
[$res, $c] = $ieProdEditar($ieEditDatos(['stock' => 99]), false, $ieProd30);
chk('updateProduct apagado: guarda el stock enviado (99.0), como hoy, y responde success',
    $res === ['success', 'Product updated'] && ($c->ediciones[0][':stock'] ?? null) === 99.0 && $c->productos[30]['stock'] === '99.000', [$res, $c->ediciones]);
chk('updateProduct apagado: solo el UPDATE, sin transaccion ni lectura con FOR UPDATE', $ieEditOrden($c) === ['UPDATE products SET sku'], $c->orden);
[$res, $c] = $ieProdEditar($ieEditDatos(['stock' => null]), false, $ieProd30);
chk('updateProduct apagado con stock null: :stock NULL, como hoy', array_key_exists(':stock', $c->ediciones[0] ?? []) && $c->ediciones[0][':stock'] === null, $c->ediciones);
[$res, $c] = $ieProdEditar($ieEditDatos(['stock' => 12.3456]), false, $ieProd30);
chk('updateProduct apagado con stock 12.3456: se guarda redondeado a 3 decimales (12.346), como hoy', ($c->ediciones[0][':stock'] ?? null) === 12.346, $c->ediciones);
[$res, $c] = $ieProdEditar($ieEditDatos(['stock' => 99]), false, []);
chk('updateProduct de un producto que ya no existe (apagado): el mensaje de siempre y sin UPDATE',
    $res[0] === 'error' && str_contains($res[1], 'ya no existe') && $c->ediciones === [], $res);
[$res, $c] = $ieProdEditar($ieEditDatos(['stock' => 99]), true, []);
chk('updateProduct de un producto que ya no existe (encendido): el mismo mensaje, sin transaccion y sin UPDATE',
    $res[0] === 'error' && str_contains($res[1], 'ya no existe') && $c->ediciones === [] && $c->orden === [], [$res, $c->orden]);
[$res, $c] = $ieProdEditar($ieEditDatos(['stock' => 99]), true, $ieProd30, ['fallas' => ['UPDATE products SET sku' => $ieDuplicado]]);
chk('updateProduct encendido con SKU repetido: el mensaje de siempre y ROLLBACK (la transaccion no queda abierta)',
    $res === ['error', 'Ya hay otro producto con ese SKU. Usa uno diferente.'] && $ieEditOrden($c) === ['BEGIN', 'SELECT stock FROM products WHERE id = :id FOR UPDATE', 'ROLLBACK']
    && $c->enTransaccion === false, [$res, $c->orden]);

$ieTenantActual(null);

// --- Cableado: productModel y productController, leidos como texto ---
$ieModProd = ie_fuente('src/Models/productModel.php');
$ieCtlProd = ie_fuente('src/Controllers/productController.php');
chk('modelo: productModel carga inventoryModel y arma el libro con SU conexion (new inventoryModel($this->conexion)), no con una nueva',
    str_contains($ieModProd, "require_once(__DIR__ . '/inventoryModel.php');") && str_contains($ieModProd, 'new inventoryModel($this->conexion)')
    && !str_contains($ieModProd, 'new inventoryModel()'));
// Un require_once que falla es un fatal que ningun try/catch atrapa: con un archivo que no
// subio (subida parcial) TODO /api/products, de TODAS las empresas, respondia un 500 vacio.
// Como en facturaController::inventarioModelo y fsInventario, el archivo se comprueba antes.
chk('modelo: el require_once de inventoryModel.php esta guardado con is_file() y no queda ninguno suelto en el nivel de arriba',
    preg_match("/if \(is_file\(__DIR__ \. '\/inventoryModel\.php'\)\) \{\s*require_once\(__DIR__ \. '\/inventoryModel\.php'\);\s*\}/", $ieModProd) === 1
    && preg_match("/^require_once\(__DIR__ \. '\/inventoryModel\.php'\);/m", $ieModProd) === 0, $ieModProd !== '' ? substr($ieModProd, 0, 300) : null);
$ieGuardaModelo = preg_match('/public function saveProduct\(.*?\n    \}\n/s', $ieModProd, $mGuardaSrc) === 1 ? $mGuardaSrc[0] : '';
chk('modelo: saveProduct, con el interruptor encendido, rechaza ANTES de tocar la base si la clase inventoryModel no esta cargada (class_exists), con error_log y un texto llano',
    $ieGuardaModelo !== ''
    && preg_match("/\\\$estricto = self::inventarioEstricto\(\);\s*if \(\\\$estricto && !class_exists\('inventoryModel', false\)\) \{\s*error_log\('\[productos\]/", $ieGuardaModelo) === 1
    && strpos($ieGuardaModelo, "class_exists('inventoryModel', false)") < strpos($ieGuardaModelo, 'resolveWarehouseId('), $ieGuardaModelo);

// El comportamiento, de verdad: una copia de productModel.php en un arbol SIN inventoryModel.php
// (y con un Database.php de mentira), corrida en otro proceso porque aqui la clase ya esta cargada.
$ieSinLibro = static function (bool $estricto) use ($ieModProd): array {
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ie_sinlibro_' . getmypid() . '_' . mt_rand();
    mkdir($dir . DIRECTORY_SEPARATOR . 'Models', 0777, true);
    file_put_contents($dir . DIRECTORY_SEPARATOR . 'Models' . DIRECTORY_SEPARATOR . 'productModel.php', $ieModProd);
    file_put_contents($dir . DIRECTORY_SEPARATOR . 'Database.php', "<?php\nclass Database {}\n");
    $script = $dir . DIRECTORY_SEPARATOR . 'correr.php';
    $plantilla = <<<'PHP'
<?php
class TenantResolver
{
    public static function inventarioEstricto(): bool
    {
        return __ESTRICTO__;
    }
}
final class SinSentencia extends PDOStatement
{
    public function execute(?array $params = null): bool
    {
        return true;
    }
}
final class SinBase extends PDO
{
    public array $consultas = [];
    public function __construct()
    {
    }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->consultas[] = $query;
        return new SinSentencia();
    }
    public function lastInsertId(?string $name = null): string|false
    {
        return '31';
    }
}
require_once __DIR__ . '/Models/productModel.php';
$cargada = class_exists('inventoryModel', false);
$ref = new ReflectionClass('productModel');
$modelo = $ref->newInstanceWithoutConstructor();
$base = new SinBase();
$ref->getProperty('conexion')->setValue($modelo, $base);
$res = $modelo->saveProduct((object) ['nombre' => 'Cemento gris', 'sku' => 'CEM-01', 'precio' => 100, 'warehouse_id' => 1, 'stock' => 5], 7);
echo "\n@@" . json_encode(['cargada' => $cargada, 'res' => $res, 'consultas' => count($base->consultas)]);
PHP;
    file_put_contents($script, str_replace('__ESTRICTO__', $estricto ? 'true' : 'false', $plantilla));
    $proc = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $salida = '';
    $errores = '';
    if (is_resource($proc)) {
        $salida = (string) stream_get_contents($pipes[1]);
        $errores = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
    }
    foreach (['correr.php', 'Database.php', 'Models' . DIRECTORY_SEPARATOR . 'productModel.php'] as $archivo) {
        @unlink($dir . DIRECTORY_SEPARATOR . $archivo);
    }
    @rmdir($dir . DIRECTORY_SEPARATOR . 'Models');
    @rmdir($dir);
    $partes = explode("\n@@", $salida);
    $final = count($partes) > 1 ? json_decode((string) array_pop($partes), true) : null;
    return [$final, $salida . $errores, $errores];
};
[$ieSinOn, $ieSinOnCrudo, $ieSinOnLog] = $ieSinLibro(true);
chk('sin inventoryModel.php, cargar productModel.php no es un fatal (la clase inventoryModel no esta y el proceso llega al final)',
    is_array($ieSinOn) && ($ieSinOn['cargada'] ?? null) === false, $ieSinOnCrudo);
chk('sin inventoryModel.php y con el interruptor encendido: saveProduct rechaza con un texto llano en espanol (2 elementos), sin nombres de archivos ni de clases',
    is_array($ieSinOn) && ($ieSinOn['res'][0] ?? null) === 'error' && count($ieSinOn['res'] ?? []) === 2 && is_string($ieSinOn['res'][1] ?? null)
    && str_contains($ieSinOn['res'][1], 'No se pudo guardar el producto') && str_contains($ieSinOn['res'][1], 'soporte')
    && !str_contains($ieSinOn['res'][1], 'inventoryModel') && !str_contains($ieSinOn['res'][1], '.php'), $ieSinOnCrudo);
chk('sin inventoryModel.php y con el interruptor encendido: no toca la base (ni una consulta) y el detalle va al log ([productos], con el nombre del archivo)',
    is_array($ieSinOn) && ($ieSinOn['consultas'] ?? null) === 0 && str_contains($ieSinOnLog, '[productos]') && str_contains($ieSinOnLog, 'inventoryModel.php'), $ieSinOnCrudo);
[$ieSinOff, $ieSinOffCrudo] = $ieSinLibro(false);
chk('sin inventoryModel.php y con el interruptor apagado: saveProduct guarda como siempre (success con el id), porque el libro no hace falta',
    is_array($ieSinOff) && ($ieSinOff['res'] ?? null) === ['success', 'Product saved', 31] && ($ieSinOff['consultas'] ?? null) === 1, $ieSinOffCrudo);
chk("modelo: el libro se llama con motivo INVENTARIO_INICIAL, una linea INCREMENTO del producto recien insertado y SIN transaccion propia (crearAjuste(..., false))",
    str_contains($ieModProd, "'motivo' => 'INVENTARIO_INICIAL'") && str_contains($ieModProd, "'tipo' => 'INCREMENTO'")
    && preg_match('/->crearAjuste\(\[.*?\], false\)/s', $ieModProd) === 1);
chk('modelo: el id del producto se toma justo despues del INSERT del producto (antes de que el libro escriba otras filas)',
    preg_match('/INSERT INTO products.*?\$productoId = \(int\) \$this->conexion->lastInsertId\(\);.*?->crearAjuste\(/s', $ieModProd) === 1);
chk('modelo: el interruptor se lee con TenantResolver::inventarioEstricto() protegido por class_exists (el modelo corre tambien en scripts sin empresa)',
    str_contains($ieModProd, "class_exists('TenantResolver', false)") && str_contains($ieModProd, 'TenantResolver::inventarioEstricto()'));
chk('controlador: $authUserId sale del token (validation[user_id]) y POST se lo pasa a saveProduct',
    preg_match("/\\\$authUserId = \\\$validation\['user_id'\] \?\? null;/", $ieCtlProd) === 1
    && preg_match('/\$productModel->saveProduct\(\$_POST, \$authUserId\)/', $ieCtlProd) === 1);
chk('controlador: PUT sigue llamando updateProduct($_PUT->id, $_PUT) (el interruptor lo lee el modelo; los llamadores de hoy no cambian)',
    str_contains($ieCtlProd, '$productModel->updateProduct($_PUT->id, $_PUT)'));
chk('controlador: el comentario de existencias ya no dice que la existencia negativa "se vende sin bloquear" sin condicion: nombra el interruptor "Bloquear ventas sin existencia"',
    !str_contains($ieCtlProd, 'la existencia negativa (se vende sin bloquear)') && str_contains($ieCtlProd, 'Bloquear ventas sin existencia'));

// POST: una existencia inicial negativa con el interruptor encendido es un dato del formulario: 422, no 200 con status:false.
$ieCtlPost = preg_match("/case 'POST':.*?\n        break;/s", $ieCtlProd, $ieM) === 1 ? $ieM[0] : '';
$iePosVal = $ieCtlPost !== '' ? strpos($ieCtlPost, 'validateProduct($_POST)') : false;
$iePosNeg = $ieCtlPost !== '' ? strpos($ieCtlPost, 'productModel::problemaExistenciaInicial($_POST, productModel::inventarioEstricto())') : false;
$iePosHttp = $ieCtlPost !== '' ? strpos($ieCtlPost, 'http_response_code(422)') : false;
$iePosGuardar = $ieCtlPost !== '' ? strpos($ieCtlPost, '$productModel->saveProduct($_POST, $authUserId)') : false;
chk('controlador POST: la existencia inicial negativa se juzga justo despues de validateProduct y ANTES de guardar, en el mismo camino del 422',
    $iePosVal !== false && $iePosNeg !== false && $iePosHttp !== false && $iePosGuardar !== false
    && $iePosVal < $iePosNeg && $iePosNeg < $iePosHttp && $iePosHttp < $iePosGuardar, $ieCtlPost);

// PUT: la bitacora no cuenta como cambio un stock que el modelo ignora.
$ieCtlPut = preg_match("/case 'PUT':.*?\n        break;/s", $ieCtlProd, $ieM) === 1 ? $ieM[0] : '';
chk('controlador PUT: new_values de la bitacora sale de productModel::nuevosValoresParaBitacora($_PUT, $oldProduct, productModel::inventarioEstricto()), ya no es $_PUT tal cual',
    str_contains($ieCtlPut, "'new_values' => productModel::nuevosValoresParaBitacora(\$_PUT, \$oldProduct, productModel::inventarioEstricto())")
    && !str_contains($ieCtlPut, "'new_values' => \$_PUT"), $ieCtlPut);
chk('controlador PUT: el cuerpo NO se recorta (unset) antes de auditar: la bitacora vista en audit_logs.html cuenta como "se vacio" una columna de products que falta',
    !str_contains($ieCtlPut, 'unset($_PUT->stock)') && !str_contains($ieCtlPut, 'unset($_PUT'));
chk('controlador: el docblock de problemaExistencias ya no dice que el stock "se sigue juzgando como siempre" con el interruptor encendido: dice que en PUT no se juzga',
    !str_contains($ieCtlProd, 'Aquí se sigue juzgando') && str_contains($ieCtlProd, 'En PUT, con ese interruptor encendido, el stock no se juzga'));

// docs/api/inventario.md: la fila `stock` del producto y la de "Cantidades con decimales" decian lo contrario con el interruptor encendido.
$ieDocProd = ie_fuente('docs/api/inventario.md');
$ieDocFilaStock = preg_match('/^\| `stock` \|[^\n]*$/m', $ieDocProd, $ieM) === 1 ? $ieM[0] : '';
$ieDocFilaApi = preg_match('/^\| `POST`\/`PUT \/api\/products` \|[^\n]*$/m', $ieDocProd, $ieM) === 1 ? $ieM[0] : '';
chk('docs/api/inventario.md, fila `stock` de Producto: lo de "0 o negativa" es solo con el interruptor apagado; encendido PUT lo ignora y POST lo abre por el libro (INVENTARIO_INICIAL)',
    $ieDocFilaStock !== '' && str_contains($ieDocFilaStock, 'apagado') && str_contains($ieDocFilaStock, 'encendido')
    && str_contains($ieDocFilaStock, 'ignora') && str_contains($ieDocFilaStock, 'INVENTARIO_INICIAL'), $ieDocFilaStock);
chk('docs/api/inventario.md, fila POST/PUT /api/products de "Cantidades con decimales": con el interruptor encendido el stock de un PUT se ignora y no se juzga',
    $ieDocFilaApi !== '' && str_contains($ieDocFilaApi, 'encendido') && str_contains($ieDocFilaApi, 'se ignora') && str_contains($ieDocFilaApi, 'no se juzga'), $ieDocFilaApi);
chk('docs/api/inventario.md: la seccion "Producto con el interruptor encendido" dice PUT ignora stock, POST abre INVENTARIO_INICIAL en la misma transaccion, 422 si es negativa y la bitacora lleva el stock guardado',
    preg_match('/### Producto con el interruptor encendido\n(.*?)\n---\n/s', $ieDocProd, $ieM) === 1
    && str_contains($ieM[1], 'se ignora en silencio') && str_contains($ieM[1], 'INVENTARIO_INICIAL') && str_contains($ieM[1], 'misma transacción')
    && str_contains($ieM[1], '`422`') && str_contains($ieM[1], 'negativa') && str_contains($ieM[1], 'new_values.stock'));

// Comportamiento real de problemaExistencias (la funcion se saca del controlador y se evalua: incluir el
// controlador manda headers, valida el token y corre el router). Los casos no tocan la base: usan un
// numero demasiado grande o un texto, que se rechazan antes de mirar la unidad.
$ieFnExistencias = $ieFuncion($ieCtlProd, 'problemaExistencias');
if ($ieFnExistencias === '') {
    chk('controlador: problemaExistencias se encuentra en el fuente (para probarla)', false);
} elseif (function_exists('problemaExistencias')) {
    chk('controlador: problemaExistencias ya estaba definida en la prueba', false);
} else {
    eval($ieFnExistencias);
    $ieFila30 = ['id' => 30, 'unidad_medida' => '43', 'stock' => 5.0, 'stock_minimo' => 1.0];
    $iePex = static function (bool $estricto, array $campos, ?array $actual) use ($ieTenantProd): ?string {
        $ieTenantProd($estricto);
        return problemaExistencias((object) ($campos + ['nombre' => 'Cemento gris', 'unidad_medida' => '43']), $actual);
    };
    chk('problemaExistencias, PUT con el interruptor encendido: un stock demasiado grande NO se juzga (el campo se descarta)',
        $iePex(true, ['stock' => 1e13], $ieFila30) === null);
    chk("problemaExistencias, PUT con el interruptor encendido: un stock que no es numero ('abc') NO se juzga", $iePex(true, ['stock' => 'abc'], $ieFila30) === null);
    chk('problemaExistencias, PUT con el interruptor encendido: el stock minimo SI se sigue juzgando',
        ($iePex(true, ['stock_minimo' => 'abc'], $ieFila30) ?? '') === 'El stock mínimo tiene que ser un número.'
        && ($iePex(true, ['stock_minimo' => 1e13], $ieFila30) ?? '') === 'El stock mínimo es demasiado grande.');
    chk('problemaExistencias, POST con el interruptor encendido: el stock SI se juzga (es la existencia inicial que va al libro)',
        ($iePex(true, ['stock' => 'abc'], null) ?? '') === 'La existencia tiene que ser un número.'
        && ($iePex(true, ['stock' => 1e13], null) ?? '') === 'La existencia es demasiado grande.');
    chk('problemaExistencias, PUT con el interruptor apagado: el stock se juzga como siempre',
        ($iePex(false, ['stock' => 'abc'], $ieFila30) ?? '') === 'La existencia tiene que ser un número.'
        && ($iePex(false, ['stock' => 1e13], $ieFila30) ?? '') === 'La existencia es demasiado grande.');
    chk('problemaExistencias, POST con el interruptor apagado: el stock se juzga como siempre',
        ($iePex(false, ['stock' => 'abc'], null) ?? '') === 'La existencia tiene que ser un número.');
    $ieTenantActual(null);
}

// ---------------------------------------------------------------------------
// Cierre (cada tarea agrega su seccion justo encima de este bloque)
// ---------------------------------------------------------------------------
printf("\n%d de %d pruebas OK\n", $ie['total'] - $ie['fallos'], $ie['total']);
exit($ie['fallos'] === 0 ? 0 : 1);
