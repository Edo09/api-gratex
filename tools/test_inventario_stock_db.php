<?php
/**
 * test_inventario_stock_db.php — Control de existencia en las ventas (facturas
 * y facturas simples), de punta a punta y con base de datos real. Spec:
 * fiscalo/docs/superpowers/specs/2026-10-19-control-existencias-ventas-design.md
 * (plan, tarea 8). Es la mitad con MySQL de tools/test_inventario_stock.php, que
 * prueba lo mismo sin base de datos y sin red.
 *
 * Que corre: la API real (php -S index.php) en modo MULTI-TENANT sobre las bases
 * scratch del write-ahead, con la DGII falsa (tools/mock_dgii.php). Multi-tenant
 * porque el interruptor «Bloquear ventas sin existencia» vive en master.tenants
 * (TenantResolver::inventarioEstricto) y sin empresa resuelta nunca se enciende:
 *   - master = wa_scratch_sin_gate (la misma que usan las suites del POS);
 *   - empresa = «POS Prueba A» (la unica fila de tenants que apunta a wa_scratch;
 *     tenants.db_name es UNIQUE, asi que no se puede crear otra sobre esa base),
 *     con sus usuarios admin_pos_a (rol admin) y user_pos_a (rol user). Su
 *     inventario_estricto se enciende y apaga aqui; al terminar queda en 0.
 *   - la preparacion es la de las suites del POS (tools/pos_scratch_prepare.php),
 *     mas la migracion master 013 (la columna del interruptor), que se aplica
 *     dos veces para probar que es idempotente.
 *
 * Nunca produccion: wa_pdo() se niega a usar una base sin la tabla marca
 * zz_write_ahead_scratch y pos_scratch_exigir() una que no se llame wa_scratch o
 * wa_scratch_sin_gate. Las credenciales salen de tools/.env (o del entorno) y no
 * se imprimen. Como todas las suites del write-ahead, vacia facturas, movimientos y
 * ajustes de la base de la empresa de prueba; de los productos solo toca los INV-.
 *
 * Uso:
 *   php -d extension=curl -d extension=openssl -d extension=pdo_mysql tools/test_inventario_stock_db.php [--api-root=<raiz>]
 *   (un -d extension=... solo para lo que tu php.ini no carga; ver write_ahead_test_lib.php.
 *   Si 8096/8099 estan ocupados por otra aplicacion: WA_PUERTO_OFFSET=100 en el entorno.)
 *   --api-root: sirve la API de OTRA raiz (un git archive de la linea base, sin el control de
 *   existencias): los escenarios por HTTP tienen que FALLAR contra ella, que es como se comprueba
 *   que la suite detecta la falta de la funcion. Los de adentro de proceso (9 y 19) y la
 *   migracion siguen usando este checkout. La raiz no puede tener un .env (Database lo cargaria).
 *
 * Escenarios (cada uno siembra sus propios productos con el prefijo INV-):
 *   M)  la migracion 013 sobre la master scratch
 *   1)  off: vende sin existencia y deja negativo (e-CF y simple)
 *   2)  on simple: rechaza 422 con faltantes y no guarda la factura ni mueve stock
 *   3)  on simple: exacto pasa; stock 0, NULL y negativo rechazan
 *   4)  on simple: lineas repetidas se suman
 *   5)  on simple: decimales 0.1+0.2 contra 0.3
 *   6)  on simple PUT: re-guardar con la misma cantidad y stock 0 pasa
 *   7)  on simple PUT: subir de 3 a 5 con stock 1 rechaza con faltante 1
 *   8)  on simple DELETE devuelve el stock
 *   9)  on simple: dos procesos venden la ultima unidad, solo uno pasa
 *   10) on e-CF: rechaza antes de reservar e-NCF (contador sin cambios)
 *   11) on e-CF: reenvio no se revisa
 *   12) on e-CF: nota de credito E34 suma sin revisar
 *   13) on e-CF: servicio y linea libre pasan
 *   14) on: producto inexistente y cantidad <= 0 dan 422 llano sin codigo
 *   15) on: PUT /api/products ignora stock; POST con existencia crea un
 *       movimiento INVENTARIO_INICIAL; off: ambos como hoy
 *   16) config: GET para cualquier rol, PUT solo admin (403 para otro rol),
 *       cambia branding.inventario_estricto
 *   17) SOBREVENTA se registra cuando un e-CF deja saldo negativo con la regla activa
 *   18) el camino de salida: tras el rechazo, un Ajuste ENTRADA hace posible la venta
 *   19) el libro contra MySQL real: bandera bloquear_sin_existencia y rollback del llamador
 *
 * Modo hijo (lo lanza esta misma prueba; no se corre a mano). Imprime una linea
 * JSON {"resultado": "ok"|"rechazada"|"error", ...}:
 *   --hijo=vender --tenant-db=<base> --cliente=<id> --producto=<id> --cantidad=<n> --listo=<archivo> --barrera=<archivo>
 */

require_once __DIR__ . '/write_ahead_test_lib.php';
require_once __DIR__ . '/pos_scratch_prepare.php';
require_once __DIR__ . '/../src/Models/facturaModel.php';
require_once __DIR__ . '/../src/Models/inventoryModel.php';

/** Base master (la de las suites del POS) y base de la empresa de prueba, nombres cortos. */
const INV_BASE_MASTER = POS_SCRATCH_BASE_MASTER;
const INV_BASE_EMPRESA = POS_SCRATCH_BASE_A;
/** RNC de «POS Prueba A»: la empresa que apunta a la base de la empresa de prueba. */
const INV_RNC_EMPRESA = '000000010';
/** Ambiente de esa empresa en el master (pos_scratch_preparar). */
const INV_AMBIENTE = 'testecf';
/** Los productos de esta suite empiezan con este prefijo (y solo esos se borran). */
const INV_PREFIJO_SKU = 'INV-';

// ---------------------------------------------------------------------------
// Utilidades de la suite
// ---------------------------------------------------------------------------

/** Ruta temporal unica que todavia no existe (barreras y salidas de los hijos). */
function inv_tmp(string $nombre): string
{
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wa_inv_' . getmypid() . '_' . $nombre . '_' . bin2hex(random_bytes(4));
}

/** Espera a que exista $archivo (hasta $segundos). */
function inv_esperar(string $archivo, float $segundos = 20.0): bool
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
function inv_hijo(array $args): array
{
    $salida = inv_tmp('salida');
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
function inv_cerrar_hijo(array $hijo): array
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
// Modo hijo: una venta de factura simple por el camino atomico, en su propio
// proceso y su propia conexion. Dos hijos a la vez (barrera) pelean por la misma
// existencia contra MySQL real. Hace lo mismo que facturaSimpleController con el
// interruptor encendido: createFacturaSimple con un callback que arma el
// inventoryModel con la conexion que le da el modelo (spec C20).
// ---------------------------------------------------------------------------
$hijo = wa_arg('hijo');
if ($hijo !== null) {
    try {
        wa_usar_base((string) wa_arg('tenant-db'));
        if ($hijo !== 'vender') {
            throw new InvalidArgumentException('modo hijo desconocido: ' . $hijo);
        }
        $modelo = new facturaModel();
        $productoHijo = (int) wa_arg('producto');
        $cuerpoHijo = [
            'client_id' => (int) wa_arg('cliente'), 'client_name' => 'INV cliente', 'user_id' => null, 'tipo_pago' => 1,
            'items' => [['product_id' => $productoHijo, 'description' => 'INV producto', 'amount' => 100.0,
                'quantity' => (float) wa_arg('cantidad')]],
        ];
        $enTransaccionHijo = static function (PDO $conexion, int $facturaId, array $items): ?array {
            $res = (new inventoryModel($conexion))->moverVentaEnTransaccion($facturaId, $items, null, null);
            return $res[0] === 'success' ? null : $res;
        };
        touch((string) wa_arg('listo'));
        if (!inv_esperar((string) wa_arg('barrera'))) {
            throw new RuntimeException('la barrera no se abrio');
        }
        $r = $modelo->createFacturaSimple($cuerpoHijo, $enTransaccionHijo);
        if ($r[0] === 'success') {
            echo json_encode(['resultado' => 'ok', 'factura_id' => $r[1]['id'] ?? null]), "\n";
        } else {
            echo json_encode(['resultado' => 'rechazada', 'mensaje' => $r[1], 'codigo_http' => $r[2] ?? null,
                'codigo' => $r[3]['codigo'] ?? null, 'faltantes' => $r[3]['faltantes'] ?? null]), "\n";
        }
    } catch (PDOException $e) {
        // Sin getMessage(): un error de acceso trae usuario y host.
        echo json_encode(['resultado' => 'error', 'clase' => get_class($e), 'codigo' => $e->getCode(),
            'errno' => ((array) $e->errorInfo)[1] ?? null]), "\n";
    } catch (Throwable $e) {
        echo json_encode(['resultado' => 'error', 'clase' => get_class($e), 'mensaje' => $e->getMessage()]), "\n";
    }
    exit(0);
}

/**
 * Parte un script SQL en sentencias: respeta '...' (con ''), `...` y los
 * comentarios -- de linea. (Copia de wa_partir_sql de aplicar_migracion_scratch.php,
 * que no se puede incluir: corre al cargarse.)
 */
function inv_partir_sql(string $sql): array
{
    $sentencias = [];
    $actual = '';
    $comilla = null;
    $largo = strlen($sql);
    for ($i = 0; $i < $largo; $i++) {
        $c = $sql[$i];
        if ($comilla !== null) {
            $actual .= $c;
            if ($c === $comilla) {
                if ($comilla === "'" && $i + 1 < $largo && $sql[$i + 1] === "'") {
                    $actual .= $sql[++$i];
                } else {
                    $comilla = null;
                }
            }
            continue;
        }
        if ($c === "'" || $c === '`' || $c === '"') {
            $comilla = $c;
            $actual .= $c;
            continue;
        }
        if ($c === '-' && $i + 1 < $largo && $sql[$i + 1] === '-') {
            while ($i < $largo && $sql[$i] !== "\n") {
                $i++;
            }
            $actual .= "\n";
            continue;
        }
        if ($c === ';') {
            if (trim($actual) !== '') {
                $sentencias[] = trim($actual);
            }
            $actual = '';
            continue;
        }
        $actual .= $c;
    }
    if (trim($actual) !== '') {
        $sentencias[] = trim($actual);
    }
    return $sentencias;
}

/**
 * Corre la migracion master 013 sentencia por sentencia en la conexion $master.
 * Un fallo imprime cual y solo SQLSTATE y errno (el mensaje del driver trae
 * usuario y host) y sale con 1. @return array la fila de la ultima consulta
 * (base, columna_ok)
 */
function inv_correr_migracion_013(PDO $master): array
{
    $sql = (string) file_get_contents(dirname(__DIR__) . '/db/master_migrations/013_tenants_inventario_estricto.sql');
    $ultima = [];
    foreach (inv_partir_sql($sql) as $n => $sentencia) {
        try {
            $resultado = $master->query($sentencia);
            if ($resultado instanceof PDOStatement) {
                $fila = $resultado->fetch(PDO::FETCH_ASSOC);
                $ultima = is_array($fila) ? $fila : $ultima;
                $resultado->closeCursor();
            }
        } catch (PDOException $e) {
            $info = (array) $e->errorInfo;
            echo '[FALLO] migracion 013, sentencia #' . ($n + 1) . ' SQLSTATE ' . ($info[0] ?? '?') . ' errno ' . ($info[1] ?? '?') . "\n";
            exit(1);
        }
    }
    return $ultima;
}

/** Descripcion de la columna tenants.inventario_estricto en la master, o null si no existe. */
function inv_columna_interruptor(PDO $master): ?array
{
    $st = $master->query(
        "SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'inventario_estricto'"
    );
    $fila = $st->fetch(PDO::FETCH_ASSOC);
    return $fila === false ? null : $fila;
}

/** Estado de la fila 013 del verificador de la master (tools/verificar_migraciones_master.sql). */
function inv_estado_verificador_013(PDO $master): ?string
{
    $lineas = preg_split('/\R/', (string) file_get_contents(__DIR__ . '/verificar_migraciones_master.sql'));
    $sql = implode("\n", array_filter($lineas, static fn(string $l): bool => !str_starts_with(ltrim($l), '--')));
    $sql = rtrim(trim($sql), ';');
    foreach ($master->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $f) {
        if ((string) $f['migracion'] === '013') {
            return (string) $f['estado'];
        }
    }
    return null;
}

/** Enciende o apaga el interruptor de la empresa de prueba directo en la master (no por la API). */
function inv_interruptor(PDO $master, int $tenantId, bool $encendido): void
{
    $master->prepare('UPDATE tenants SET inventario_estricto = :v WHERE id = :id')
        ->execute([':v' => $encendido ? 1 : 0, ':id' => $tenantId]);
}

/** Lo que vale el interruptor ahora en la master (0 o 1). */
function inv_valor_interruptor(PDO $master, int $tenantId): int
{
    $st = $master->prepare('SELECT inventario_estricto FROM tenants WHERE id = :id');
    $st->execute([':id' => $tenantId]);
    return (int) $st->fetchColumn();
}

/** Primera columna de la primera fila de $sql (con $params), como entero. */
function inv_contar(PDO $pdo, string $sql, array $params = []): int
{
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return (int) $st->fetchColumn();
}

/** Borra los productos INV-, sus ajustes y todo lo que cuelga. Solo para scratch. */
function inv_borrar_productos_y_ajustes(PDO $pdo): void
{
    wa_exigir_marca($pdo);
    $pdo->exec('DELETE FROM inventory_movements');
    $pdo->exec('UPDATE inventory_adjustments SET anula_a_id = NULL, anulado_por_id = NULL');
    $pdo->exec('DELETE FROM inventory_adjustments');
    $pdo->exec('DELETE FROM factura_items WHERE product_id IN (SELECT id FROM products WHERE sku LIKE ' . $pdo->quote(INV_PREFIJO_SKU . '%') . ')');
    $pdo->exec('DELETE FROM products WHERE sku LIKE ' . $pdo->quote(INV_PREFIJO_SKU . '%'));
}

/**
 * Un producto de esta suite (idempotente por sku). $stock es el texto/numero que
 * se guarda ('2', '0.3', '-3') o null.
 * @param array{nombre?:string, servicio?:bool, unidad?:string, activo?:bool} $o
 */
function inv_producto(PDO $pdo, string $sku, ?string $stock, array $o = []): int
{
    $almacen = inv_contar($pdo, "SELECT id FROM warehouses WHERE nombre = 'WA almacen'");
    $pdo->prepare(
        "INSERT INTO products (sku, nombre, warehouse_id, indicador_bien_servicio, indicador_facturacion, precio, costo, unidad_medida, stock, activo)
         VALUES (:sku, :nombre, :w, :ibs, 1, 1000.00, 400.00, :unidad, :stock, :activo)
         ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), warehouse_id = VALUES(warehouse_id),
             indicador_bien_servicio = VALUES(indicador_bien_servicio), unidad_medida = VALUES(unidad_medida),
             stock = VALUES(stock), activo = VALUES(activo)"
    )->execute([
        ':sku' => $sku, ':nombre' => $o['nombre'] ?? ('INV producto ' . $sku), ':w' => $almacen,
        ':ibs' => !empty($o['servicio']) ? 2 : 1, ':unidad' => $o['unidad'] ?? '43', ':stock' => $stock,
        ':activo' => array_key_exists('activo', $o) && $o['activo'] === false ? 0 : 1,
    ]);
    return inv_contar($pdo, 'SELECT id FROM products WHERE sku = :s', [':s' => $sku]);
}

/** Existencia guardada de un producto: float a 3 decimales, o null si es NULL. */
function inv_stock(PDO $pdo, int $productId): ?float
{
    $st = $pdo->prepare('SELECT stock FROM products WHERE id = :id');
    $st->execute([':id' => $productId]);
    $v = $st->fetchColumn();
    return $v === false || $v === null ? null : round((float) $v, 3);
}

/** Pone la existencia de un producto directo en la base (como si otra venta o un ajuste la hubiera movido). */
function inv_fijar_stock(PDO $pdo, int $productId, ?string $stock): void
{
    $pdo->prepare('UPDATE products SET stock = :s WHERE id = :id')->execute([':s' => $stock, ':id' => $productId]);
}

/** Movimientos del libro de un producto, en orden: [tipo_movimiento, referencia_tipo, cantidad, anterior, nueva]. */
function inv_movs(PDO $pdo, int $productId): array
{
    $st = $pdo->prepare(
        'SELECT tipo_movimiento, referencia_tipo, cantidad, cantidad_anterior, cantidad_nueva
         FROM inventory_movements WHERE product_id = :p ORDER BY id'
    );
    $st->execute([':p' => $productId]);
    return array_map(static fn(array $m): array => [
        $m['tipo_movimiento'], $m['referencia_tipo'], round((float) $m['cantidad'], 3),
        round((float) $m['cantidad_anterior'], 3), round((float) $m['cantidad_nueva'], 3),
    ], $st->fetchAll(PDO::FETCH_ASSOC));
}

/** Facturas simples guardadas (tipo_ecf NULL). */
function inv_simples(PDO $pdo): int
{
    return inv_contar($pdo, 'SELECT COUNT(*) FROM facturas WHERE tipo_ecf IS NULL');
}

/** Lineas guardadas de una factura: [product_id, quantity] por fila. */
function inv_lineas_guardadas(PDO $pdo, int $facturaId): array
{
    $st = $pdo->prepare('SELECT product_id, quantity FROM factura_items WHERE factura_id = :f ORDER BY id');
    $st->execute([':f' => $facturaId]);
    return array_map(static fn(array $l): array => [(int) $l['product_id'], round((float) $l['quantity'], 3)], $st->fetchAll(PDO::FETCH_ASSOC));
}

/** Diferencia menor que media milesima: los numeros del JSON llegan como float. */
function inv_cerca(mixed $a, float $b): bool
{
    return (is_int($a) || is_float($a)) && abs((float) $a - $b) < 0.0005;
}

/** Cuerpo de una linea de POST /api/facturas (e-CF). $productId null = linea libre. */
function inv_linea_ecf(?int $productId, float $cantidad, float $precio = 1000.0, bool $servicio = false): array
{
    return [
        'nombre_item' => $productId !== null ? 'INV producto' : 'INV linea libre',
        'indicador_facturacion' => 1,
        'indicador_bien_servicio' => $servicio ? 2 : 1,
        'cantidad' => $cantidad,
        'precio_unitario' => $precio,
    ] + ($productId !== null ? ['product_id' => $productId] : []);
}

/** Cuerpo de una linea de POST /api/facturas-simples. */
function inv_linea_simple(int $productId, float $cantidad, float $precio = 100.0): array
{
    return ['product_id' => $productId, 'description' => 'INV producto', 'amount' => $precio, 'quantity' => $cantidad];
}

/**
 * Llamada a la API de la suite con el token del rol ('admin' o 'user').
 * $c es el contexto de inv_arrancar(). El token nunca se imprime.
 */
function inv_llamar(array $c, string $metodo, string $ruta, ?array $cuerpo = null, string $rol = 'admin'): array
{
    $token = $rol === 'admin' ? $c['token_admin'] : $c['token_user'];
    return wa_http($metodo, $c['api'] . $ruta, $cuerpo, ['X-API-KEY: ' . $token], 60);
}

/** POST /api/facturas (e-CF) del cliente sembrado. */
function inv_emitir(array $c, string $tipo, array $ids, array $lineas, array $extra = []): array
{
    return inv_llamar($c, 'POST', '/api/facturas',
        $extra + ['tipo_ecf' => $tipo, 'client_id' => $ids['client_id'], 'tipo_pago' => 1, 'items' => $lineas]);
}

/** POST /api/facturas-simples del cliente sembrado. */
function inv_simple(array $c, array $ids, array $lineas, array $extra = []): array
{
    return inv_llamar($c, 'POST', '/api/facturas-simples', $extra + ['client_id' => $ids['client_id'], 'tipo_pago' => 1, 'items' => $lineas]);
}

/** Peticiones de recepcion (e-CF o RFCE) que vio el mock. */
function inv_recepciones(array $log): array
{
    return array_values(array_filter($log, static fn(array $e): bool
        => str_ends_with((string) $e['ruta'], '/Recepcion/api/FacturasElectronicas') || str_ends_with((string) $e['ruta'], '/RecepcionFC/api/recepcion/ecf')));
}

/** Posicion actual del log del servidor de la API (para leer solo lo que escribe un escenario). */
function inv_log_marca(array $c): int
{
    clearstatcache(true, $c['log']);
    return is_file($c['log']) ? (int) filesize($c['log']) : 0;
}

/** Lo que el servidor de la API escribio en su log desde $marca. */
function inv_log_desde(array $c, int $marca): string
{
    clearstatcache(true, $c['log']);
    if (!is_file($c['log'])) {
        return '';
    }
    $f = fopen($c['log'], 'rb');
    fseek($f, $marca);
    $texto = (string) stream_get_contents($f);
    fclose($f);
    return $texto;
}

/** Mayor id de audit_logs de la master (para leer solo lo que escribe un escenario). */
function inv_auditoria_marca(PDO $master): int
{
    return inv_contar($master, 'SELECT COALESCE(MAX(id), 0) FROM audit_logs');
}

/** Filas de auditoria de la empresa de prueba posteriores a $desdeId, con new_values decodificado. */
function inv_auditoria(PDO $master, int $tenantId, int $desdeId, string $modulo, string $accion): array
{
    $st = $master->prepare(
        'SELECT id, success, error_message, old_values, new_values FROM audit_logs
         WHERE tenant_id = :t AND id > :d AND module = :m AND action = :a ORDER BY id'
    );
    $st->execute([':t' => $tenantId, ':d' => $desdeId, ':m' => $modulo, ':a' => $accion]);
    return array_map(static fn(array $f): array => [
        'success' => (int) $f['success'], 'error_message' => $f['error_message'],
        'old_values' => json_decode((string) $f['old_values'], true),
        'new_values' => json_decode((string) $f['new_values'], true),
    ], $st->fetchAll(PDO::FETCH_ASSOC));
}

/** Escenario nuevo: mock en $modo, base limpia, productos INV- fuera, interruptor en $encendido. */
function inv_reiniciar(array $c, string $modo = 'acepta', bool $encendido = false): array
{
    wa_mock_modo($c['mock'], $modo);
    wa_limpiar($c['pdo']);
    inv_borrar_productos_y_ajustes($c['pdo']);
    $ids = wa_sembrar($c['pdo']);
    wa_mock_log($c['mock'], true);
    inv_interruptor($c['master'], $c['tenant_id'], $encendido);
    return $ids;
}

/** Las claves de un array, ordenadas (para comparar formas). */
function inv_claves(mixed $a): array
{
    $k = is_array($a) ? array_keys($a) : [];
    sort($k);
    return $k;
}

/**
 * La forma de una fila de data.faltantes (spec 4.4): las siete claves, los
 * numeros como numeros JSON (nunca texto) y los valores esperados.
 * @param array{product_id:int, solicitado:float, disponible:float, faltante:float} $esperado
 */
function inv_fila_faltante_ok(mixed $f, array $esperado): bool
{
    return is_array($f)
        && inv_claves($f) === ['disponible', 'faltante', 'nombre', 'product_id', 'sku', 'solicitado', 'unidad_medida']
        && $f['product_id'] === $esperado['product_id']
        && is_string($f['nombre']) && (is_string($f['sku']) || $f['sku'] === null)
        && (is_string($f['unidad_medida']) || $f['unidad_medida'] === null)
        && inv_cerca($f['solicitado'], $esperado['solicitado'])
        && inv_cerca($f['disponible'], $esperado['disponible'])
        && inv_cerca($f['faltante'], $esperado['faltante']);
}

/**
 * Comprueba una respuesta de rechazo por existencia (spec 4.4): HTTP 422, status
 * false, codigo STOCK_INSUFICIENTE, data.faltantes con las filas esperadas (en
 * ese orden) y un texto en espanol.
 * @param list<array{product_id:int, solicitado:float, disponible:float, faltante:float}> $esperados
 */
function inv_chk_rechazo(string $desc, array $r, array $esperados): void
{
    $j = $r['json'] ?? [];
    $filas = $j['data']['faltantes'] ?? null;
    $ok = $r['status'] === 422 && ($j['status'] ?? null) === false
        && ($j['codigo'] ?? null) === 'STOCK_INSUFICIENTE'
        && is_string($j['error'] ?? null) && str_contains($j['error'], 'No hay existencia suficiente')
        && inv_claves($j['data'] ?? null) === ['faltantes']
        && is_array($filas) && count($filas) === count($esperados);
    if ($ok) {
        foreach ($esperados as $i => $esperado) {
            $ok = $ok && inv_fila_faltante_ok($filas[$i] ?? null, $esperado);
        }
    }
    wa_chk($desc, $ok, $r);
}

/** Comprueba un 422 llano: sin codigo y sin data (producto inexistente, cantidad <= 0). */
function inv_chk_llano(string $desc, array $r): void
{
    $j = $r['json'] ?? [];
    wa_chk($desc, $r['status'] === 422 && ($j['status'] ?? null) === false && is_string($j['error'] ?? null)
        && !array_key_exists('codigo', $j) && !array_key_exists('data', $j), $r);
}

/**
 * Prepara todo y devuelve el contexto: la preparacion del POS (tenants, usuarios,
 * rangos), la migracion 013, la API multi-tenant y los dos tokens.
 */
function inv_arrancar(): array
{
    $cred = wa_credenciales();
    $master = wa_pdo(INV_BASE_MASTER);
    $realMaster = wa_base_real(INV_BASE_MASTER);
    $pdo = wa_pdo(INV_BASE_EMPRESA);

    // Lo que habia antes: si las empresas del POS no estaban en la master scratch,
    // al terminar se quitan (pos_scratch_limpiar) para dejarla como estaba.
    $rncs = array_column(POS_SCRATCH_TENANTS, 'rnc');
    $enRncs = implode(',', array_fill(0, count($rncs), '?'));
    $st = $master->prepare("SELECT COUNT(*) FROM tenants WHERE rnc IN ({$enRncs})");
    $habia = false;
    try {
        $st->execute($rncs);
        $habia = (int) $st->fetchColumn() > 0;
    } catch (PDOException $e) {
        $habia = false;   // la tabla tenants todavia no existe
    }

    $info = pos_scratch_preparar();
    $tenantId = (int) $info['tenantA'];
    $secretos = pos_scratch_secretos($cred['pass']);

    // ---- M) La migracion 013 sobre la master scratch -----------------------
    echo "M) la migracion master 013 en la base master de prueba\n";
    $existiaAntes = inv_columna_interruptor($master) !== null;
    $r1 = inv_correr_migracion_013($master);
    $columna = inv_columna_interruptor($master);
    wa_chk('la primera corrida deja la columna tenants.inventario_estricto', $columna !== null && (int) ($r1['columna_ok'] ?? 0) === 1, [$r1, $columna]);
    wa_chk('es TINYINT(1) NOT NULL DEFAULT 0',
        stripos((string) ($columna['COLUMN_TYPE'] ?? ''), 'tinyint(1)') === 0 && ($columna['IS_NULLABLE'] ?? null) === 'NO'
        && (string) ($columna['COLUMN_DEFAULT'] ?? '') === '0', $columna);
    $r2 = inv_correr_migracion_013($master);
    $columnas = inv_contar($master, "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenants' AND COLUMN_NAME = 'inventario_estricto'");
    wa_chk('la segunda corrida no falla ni duplica la columna (idempotente)', $columnas === 1 && (int) ($r2['columna_ok'] ?? 0) === 1, [$r2, $columnas]);
    if (!$existiaAntes) {
        wa_chk('la migracion no enciende a nadie: ninguna empresa en 1',
            inv_contar($master, 'SELECT COUNT(*) FROM tenants WHERE inventario_estricto <> 0') === 0);
    } else {
        echo "  (la columna ya existia en la master de prueba: no se puede probar que la migracion no enciende a nadie)\n";
    }
    wa_chk('el verificador de la master marca 013 como APLICADA', inv_estado_verificador_013($master) === 'APLICADA', inv_estado_verificador_013($master));
    inv_interruptor($master, $tenantId, false);

    // pos_scratch_preparar solo asegura los rangos testecf de E31 y E32: el de E34 (la nota
    // de credito del escenario 12) se asegura aqui.
    $pdo->prepare(
        "INSERT INTO ncf_sequences (type, prefix, current_value, numero_desde, numero_hasta, fecha_vencimiento, description, ambiente)
         VALUES ('E34', 'E34', 0, 1, NULL, :v, 'INV prueba', :a)
         ON DUPLICATE KEY UPDATE numero_hasta = NULL, fecha_vencimiento = VALUES(fecha_vencimiento)"
    )->execute([':v' => date('Y-m-d', strtotime('+1 year')), ':a' => INV_AMBIENTE]);

    // ---- La API multi-tenant -----------------------------------------------
    $cert = wa_cert_prueba();
    $mock = wa_servidor('mock_dgii', 'tools/mock_dgii.php', 8099, ['MOCK_DGII_CUELGA_SEG' => '5']);
    $entorno = [
        'MULTI_TENANT_ENABLED' => 'true', 'PERMISSIONS_ENFORCE' => 'false', 'AUDIT_LOG_ENABLED' => 'true',
        'MASTER_DB_HOST' => $cred['host'], 'MASTER_DB_PORT' => $cred['port'], 'MASTER_DB_NAME' => $realMaster,
        'MASTER_DB_USER' => $cred['user'], 'MASTER_DB_PASS' => $cred['pass'],
        'MASTER_ENCRYPTION_KEY' => $secretos['clave_maestra'],
        'DGII_ECF_ENVIRONMENT' => INV_AMBIENTE, 'DGII_ECF_BASE_URL' => $mock, 'DGII_FC_BASE_URL' => $mock, 'DGII_ECF_TIMEOUT' => '3',
        'DGII_ECF_CERT_PATH' => $cert['path'], 'DGII_ECF_CERT_PASSWORD' => $cert['password'],
        // Una peticion sin empresa resuelta cae en la master de prueba, nunca en otra base.
        'DB_HOST' => $cred['host'], 'DB_PORT' => $cred['port'], 'DB_NAME' => $realMaster,
        'DB_USER' => $cred['user'], 'DB_PASS' => $cred['pass'],
    ];
    // Otra raiz (la linea base): el nombre del servidor la lleva, para no reutilizar un servidor
    // viejo de otra raiz en el mismo puerto (wa_servidor lo rechaza si el nombre no coincide).
    $pedida = (string) wa_arg('api-root', '');
    $raiz = $pedida === '' ? '' : (string) realpath($pedida);
    if ($pedida !== '' && ($raiz === '' || !is_file($raiz . '/index.php') || is_file($raiz . '/.env'))) {
        echo "[FALLO] --api-root no sirve: no existe, no tiene index.php o tiene un .env
";
        exit(1);
    }
    $nombreApi = $raiz === '' ? 'inv_api' : 'inv_api_' . substr(md5($raiz), 0, 6);
    $api = wa_servidor($nombreApi, 'index.php', 8096, $entorno, $raiz);
    $puerto = (int) parse_url($api, PHP_URL_PORT);
    $log = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "wa_{$nombreApi}_{$puerto}.log";

    $clave = substr(hash('sha256', 'wa_pos_scratch|usuarios|' . $cred['pass']), 0, 32);
    $iniciar = static function (string $usuario) use ($api, $clave): string {
        $r = wa_http('POST', $api . '/api/auth/login', ['emailOrUsername' => $usuario, 'password' => $clave]);
        $token = (string) ($r['json']['data']['token'] ?? '');
        if ($token === '') {
            echo "[FALLO] no se pudo iniciar sesion como {$usuario} en la API de prueba: HTTP {$r['status']}\n";
            exit(1);
        }
        return $token;
    };

    return [
        'master' => $master, 'pdo' => $pdo, 'tenant_id' => $tenantId, 'api' => $api, 'mock' => $mock, 'log' => $log,
        'token_admin' => $iniciar('admin_pos_a'), 'token_user' => $iniciar('user_pos_a'),
        'habia_empresas_pos' => $habia, 'auditoria_inicial' => inv_auditoria_marca($master),
    ];
}

// ===========================================================================
// Preparacion
// ===========================================================================
$c = inv_arrancar();
$pdo = $c['pdo'];
$master = $c['master'];

// Al terminar (incluso con exit(1)): el interruptor en 0, la base de la empresa
// como recien sembrada, sin productos INV-, y la master sin las filas de
// auditoria de esta corrida ni, si no estaban antes, las empresas del POS.
register_shutdown_function(static function () use ($c): void {
    try {
        inv_interruptor($c['master'], $c['tenant_id'], false);
        wa_limpiar($c['pdo']);
        inv_borrar_productos_y_ajustes($c['pdo']);
        $c['master']->prepare('DELETE FROM audit_logs WHERE tenant_id = :t AND id > :d')
            ->execute([':t' => $c['tenant_id'], ':d' => $c['auditoria_inicial']]);
        if (!$c['habia_empresas_pos']) {
            pos_scratch_limpiar();
        }
    } catch (Throwable $e) {
        echo '[AVISO] no se pudo limpiar del todo: ' . get_class($e) . "\n";
    }
});

$tenantId = $c['tenant_id'];

// ---------------------------------------------------------------------------
// 1) Interruptor apagado: todo como hoy
// ---------------------------------------------------------------------------
echo "1) off: vende sin existencia y deja negativo (e-CF y simple)\n";
$ids = inv_reiniciar($c, 'acepta', false);
$p = inv_producto($pdo, 'INV-OFF', '1');
$marca = inv_log_marca($c);
$r = inv_emitir($c, '31', $ids, [inv_linea_ecf($p, 3)]);
wa_chk('e-CF: pide 3 con existencia 1 y se emite (200, ACEPTADO)',
    $r['status'] === 200 && ($r['json']['data']['estado_dgii'] ?? null) === 'ACEPTADO', $r);
wa_chk('e-CF: el saldo queda en -2 con un movimiento VENTA despues de la respuesta de la DGII',
    inv_stock($pdo, $p) === -2.0 && inv_movs($pdo, $p) === [['VENTA', 'factura', -3.0, 1.0, -2.0]], [inv_stock($pdo, $p), inv_movs($pdo, $p)]);
$r = inv_simple($c, $ids, [inv_linea_simple($p, 3)]);
wa_chk('simple: pide 3 con existencia -2 y se guarda (201)', $r['status'] === 201 && ($r['json']['status'] ?? null) === true, $r);
wa_chk('simple: el saldo queda en -5 y el movimiento VENTA lo escribe el camino de siempre',
    inv_stock($pdo, $p) === -5.0 && count(inv_movs($pdo, $p)) === 2 && inv_movs($pdo, $p)[1] === ['VENTA', 'factura', -3.0, -2.0, -5.0],
    [inv_stock($pdo, $p), inv_movs($pdo, $p)]);
wa_chk('apagado: el log no dice SOBREVENTA (la regla no corre)', !str_contains(inv_log_desde($c, $marca), 'SOBREVENTA'));
$stock0 = inv_producto($pdo, 'INV-OFF-0', null);   // un Bien sin dato de existencia
$r = inv_simple($c, $ids, [inv_linea_simple($stock0, 2)]);
wa_chk('simple de un Bien con existencia NULL: se guarda y queda en -2', $r['status'] === 201 && inv_stock($pdo, $stock0) === -2.0, [$r['status'], inv_stock($pdo, $stock0)]);
// El PUT y el DELETE de una simple, apagado: devolver y volver a descontar DESPUES de guardar, como siempre.
$q = inv_producto($pdo, 'INV-OFF-2', '2');
$r = inv_simple($c, $ids, [inv_linea_simple($q, 1)]);
$idOff = (int) ($r['json']['data']['id'] ?? 0);
$r = inv_llamar($c, 'PUT', "/api/facturas-simples/{$idOff}", ['items' => [inv_linea_simple($q, 4)]]);
wa_chk('simple PUT: subir de 1 a 4 con existencia 1 se guarda (200) y deja -2, con DEVOLUCION y VENTA como siempre',
    $r['status'] === 200 && inv_stock($pdo, $q) === -2.0
    && inv_movs($pdo, $q) === [['VENTA', 'factura', -1.0, 2.0, 1.0], ['DEVOLUCION', 'factura', 1.0, 1.0, 2.0], ['VENTA', 'factura', -4.0, 2.0, -2.0]],
    [$r['status'], inv_stock($pdo, $q), inv_movs($pdo, $q)]);
$r = inv_llamar($c, 'DELETE', "/api/facturas-simples/{$idOff}");
wa_chk('simple DELETE: 200 y la existencia vuelve a 2', $r['status'] === 200 && inv_stock($pdo, $q) === 2.0, [$r['status'], inv_stock($pdo, $q)]);

// ---------------------------------------------------------------------------
// 2) Simple: rechazo con faltantes
// ---------------------------------------------------------------------------
echo "2) on simple: rechaza 422 con faltantes y no guarda la factura ni mueve stock\n";
$ids = inv_reiniciar($c, 'acepta', true);
$p = inv_producto($pdo, 'INV-S2', '2', ['nombre' => 'Cemento gris']);
$marcaAud = inv_auditoria_marca($master);
$r = inv_simple($c, $ids, [inv_linea_simple($p, 5)]);
inv_chk_rechazo('422 con codigo STOCK_INSUFICIENTE y una fila: pide 5, hay 2, faltan 3', $r,
    [['product_id' => $p, 'solicitado' => 5.0, 'disponible' => 2.0, 'faltante' => 3.0]]);
$fila = $r['json']['data']['faltantes'][0] ?? [];
wa_chk('la fila trae el nombre, el sku y la unidad del catalogo', ($fila['nombre'] ?? null) === 'Cemento gris'
    && ($fila['sku'] ?? null) === 'INV-S2' && ($fila['unidad_medida'] ?? null) === '43', $fila);
wa_chk('el texto es el del spec 4.4', ($r['json']['error'] ?? null) === 'No hay existencia suficiente de «Cemento gris»: pides 5 y hay 2.', $r['json']['error'] ?? null);
wa_chk('no se guardo la factura, ni sus lineas, ni se movio stock',
    inv_simples($pdo) === 0 && inv_contar($pdo, 'SELECT COUNT(*) FROM factura_items') === 0
    && inv_stock($pdo, $p) === 2.0 && inv_movs($pdo, $p) === [],
    [inv_simples($pdo), inv_stock($pdo, $p), inv_movs($pdo, $p)]);
$aud = inv_auditoria($master, $tenantId, $marcaAud, 'facturas-simples', 'CREATE');
wa_chk('el intento queda en la auditoria como CREATE fallido con los faltantes',
    count($aud) === 1 && $aud[0]['success'] === 0 && str_contains((string) $aud[0]['error_message'], 'No hay existencia suficiente')
    && (($aud[0]['new_values']['faltantes'][0]['product_id'] ?? null) === $p), $aud);
// Con otros dos productos, uno basta y otro no: el rechazo nombra solo al que falta y no guarda nada.
$q = inv_producto($pdo, 'INV-S2B', '10');
$r = inv_simple($c, $ids, [inv_linea_simple($q, 4), inv_linea_simple($p, 3)]);
inv_chk_rechazo('con dos productos, solo el corto aparece en faltantes', $r,
    [['product_id' => $p, 'solicitado' => 3.0, 'disponible' => 2.0, 'faltante' => 1.0]]);
wa_chk('y el que si alcanzaba tampoco se movio', inv_stock($pdo, $q) === 10.0 && inv_movs($pdo, $q) === [] && inv_simples($pdo) === 0);
$r = inv_simple($c, $ids, [inv_linea_simple($q, 4), inv_linea_simple($p, 1)]);
wa_chk('con lo que alcanza se guarda (201) y descuenta las dos lineas dentro de la transaccion',
    $r['status'] === 201 && inv_stock($pdo, $q) === 6.0 && inv_stock($pdo, $p) === 1.0
    && inv_movs($pdo, $q) === [['VENTA', 'factura', -4.0, 10.0, 6.0]], [$r['status'], inv_stock($pdo, $q), inv_stock($pdo, $p)]);
$idFactura = (int) ($r['json']['data']['id'] ?? 0);
wa_chk('los movimientos llevan la referencia de la factura guardada',
    $idFactura > 0 && inv_contar($pdo, "SELECT COUNT(*) FROM inventory_movements WHERE referencia_tipo = 'factura' AND referencia_id = :f", [':f' => $idFactura]) === 2);

// ---------------------------------------------------------------------------
// 3) Simple: exacto pasa; 0, NULL y negativo rechazan
// ---------------------------------------------------------------------------
echo "3) on simple: exacto pasa; stock 0, NULL y negativo rechazan\n";
$ids = inv_reiniciar($c, 'acepta', true);
$p = inv_producto($pdo, 'INV-S3', '5');
$r = inv_simple($c, $ids, [inv_linea_simple($p, 5)]);
wa_chk('pedir exactamente la existencia (5 de 5) pasa: 201 y el saldo queda en 0',
    $r['status'] === 201 && inv_stock($pdo, $p) === 0.0 && inv_movs($pdo, $p) === [['VENTA', 'factura', -5.0, 5.0, 0.0]], [$r['status'], inv_stock($pdo, $p)]);
$r = inv_simple($c, $ids, [inv_linea_simple($p, 1)]);
inv_chk_rechazo('con existencia 0 cualquier cantidad rechaza: pide 1, hay 0, falta 1', $r,
    [['product_id' => $p, 'solicitado' => 1.0, 'disponible' => 0.0, 'faltante' => 1.0]]);
$nulo = inv_producto($pdo, 'INV-S3N', null);
$r = inv_simple($c, $ids, [inv_linea_simple($nulo, 2)]);
inv_chk_rechazo('un Bien con existencia NULL cuenta como 0: disponible 0, faltante = lo pedido', $r,
    [['product_id' => $nulo, 'solicitado' => 2.0, 'disponible' => 0.0, 'faltante' => 2.0]]);
wa_chk('el NULL sigue NULL (el rechazo no escribe nada)', inv_stock($pdo, $nulo) === null && inv_movs($pdo, $nulo) === []);
$neg = inv_producto($pdo, 'INV-S3G', '-3', ['nombre' => 'Tubo galvanizado']);
$r = inv_simple($c, $ids, [inv_linea_simple($neg, 5)]);
inv_chk_rechazo('existencia guardada -3, pide 5: disponible 0 (nunca "quedan -3") y faltan 8', $r,
    [['product_id' => $neg, 'solicitado' => 5.0, 'disponible' => 0.0, 'faltante' => 8.0]]);
wa_chk('el texto no menciona un saldo negativo', !str_contains((string) ($r['json']['error'] ?? ''), '-'), $r['json']['error'] ?? null);

// ---------------------------------------------------------------------------
// 4) Simple: lineas repetidas
// ---------------------------------------------------------------------------
echo "4) on simple: lineas repetidas se suman\n";
$ids = inv_reiniciar($c, 'acepta', true);
$p = inv_producto($pdo, 'INV-S4', '4');
$r = inv_simple($c, $ids, [inv_linea_simple($p, 3), inv_linea_simple($p, 3)]);
inv_chk_rechazo('dos lineas de 3 contra existencia 4: una sola fila con solicitado 6, disponible 4, faltante 2', $r,
    [['product_id' => $p, 'solicitado' => 6.0, 'disponible' => 4.0, 'faltante' => 2.0]]);
wa_chk('no se guardo nada y la existencia sigue en 4', inv_simples($pdo) === 0 && inv_stock($pdo, $p) === 4.0 && inv_movs($pdo, $p) === []);
$r = inv_simple($c, $ids, [inv_linea_simple($p, 2), inv_linea_simple($p, 2)]);
wa_chk('dos lineas de 2 contra existencia 4 pasan (4 de 4): 201, saldo 0 y un movimiento por linea',
    $r['status'] === 201 && inv_stock($pdo, $p) === 0.0 && inv_movs($pdo, $p) === [['VENTA', 'factura', -2.0, 4.0, 2.0], ['VENTA', 'factura', -2.0, 2.0, 0.0]],
    [$r['status'], inv_movs($pdo, $p)]);

// ---------------------------------------------------------------------------
// 5) Simple: decimales
// ---------------------------------------------------------------------------
echo "5) on simple: decimales 0.1+0.2 contra 0.3\n";
$ids = inv_reiniciar($c, 'acepta', true);
$p = inv_producto($pdo, 'INV-S5', '0.3', ['unidad' => '21']);   // 21 = kilogramo: admite decimales
$r = inv_simple($c, $ids, [inv_linea_simple($p, 0.1), inv_linea_simple($p, 0.2)]);
wa_chk('0.1 + 0.2 contra existencia 0.3 pasa (se compara en milesimas, no en flotantes)',
    $r['status'] === 201 && inv_stock($pdo, $p) === 0.0, $r);
$p2 = inv_producto($pdo, 'INV-S5B', '0.299', ['unidad' => '21']);
$r = inv_simple($c, $ids, [inv_linea_simple($p2, 0.3)]);
inv_chk_rechazo('0.3 contra existencia 0.299: faltan 0.001', $r,
    [['product_id' => $p2, 'solicitado' => 0.3, 'disponible' => 0.299, 'faltante' => 0.001]]);
wa_chk('el texto dice 0.3 y 0.299 sin ceros de relleno', str_contains((string) ($r['json']['error'] ?? ''), 'pides 0.3 y hay 0.299.'), $r['json']['error'] ?? null);
wa_chk('0.299 no se movio', inv_stock($pdo, $p2) === 0.299 && inv_movs($pdo, $p2) === []);

// ---------------------------------------------------------------------------
// 6) PUT que solo re-guarda
// ---------------------------------------------------------------------------
echo "6) on simple PUT: re-guardar con la misma cantidad y stock 0 pasa\n";
$ids = inv_reiniciar($c, 'acepta', true);
$p = inv_producto($pdo, 'INV-S6', '5');
$r = inv_simple($c, $ids, [inv_linea_simple($p, 5)]);
$id = (int) ($r['json']['data']['id'] ?? 0);
wa_chk('precondicion: la factura se guarda y deja la existencia en 0', $r['status'] === 201 && $id > 0 && inv_stock($pdo, $p) === 0.0, $r);
$r = inv_llamar($c, 'PUT', "/api/facturas-simples/{$id}", ['items' => [inv_linea_simple($p, 5)]]);
wa_chk('PUT con la misma cantidad y existencia 0: 200 (la devolucion de las lineas viejas cuenta)', $r['status'] === 200 && ($r['json']['status'] ?? null) === true, $r);
wa_chk('la existencia sigue en 0 y el libro lo explica: VENTA, DEVOLUCION, VENTA',
    inv_stock($pdo, $p) === 0.0 && inv_movs($pdo, $p) === [['VENTA', 'factura', -5.0, 5.0, 0.0], ['DEVOLUCION', 'factura', 5.0, 0.0, 5.0], ['VENTA', 'factura', -5.0, 5.0, 0.0]],
    inv_movs($pdo, $p));
$r = inv_llamar($c, 'PUT', "/api/facturas-simples/{$id}", ['items' => [inv_linea_simple($p, 2)]]);
wa_chk('bajar a 2: 200 y devuelve 3 al almacen', $r['status'] === 200 && inv_stock($pdo, $p) === 3.0, [$r['status'], inv_stock($pdo, $p)]);
$antes = [inv_stock($pdo, $p), inv_movs($pdo, $p)];
$r = inv_llamar($c, 'PUT', "/api/facturas-simples/{$id}", ['client_name' => 'INV cliente renombrado']);
wa_chk('un PUT sin items no mueve nada', $r['status'] === 200 && [inv_stock($pdo, $p), inv_movs($pdo, $p)] === $antes, [$r['status'], inv_stock($pdo, $p)]);

// ---------------------------------------------------------------------------
// 7) PUT que sube la cantidad
// ---------------------------------------------------------------------------
echo "7) on simple PUT: subir de 3 a 5 con stock 1 rechaza con faltante 1\n";
$ids = inv_reiniciar($c, 'acepta', true);
$p = inv_producto($pdo, 'INV-S7', '4');
$r = inv_simple($c, $ids, [inv_linea_simple($p, 3)]);
$id = (int) ($r['json']['data']['id'] ?? 0);
wa_chk('precondicion: la factura toma 3 y deja la existencia en 1', $r['status'] === 201 && inv_stock($pdo, $p) === 1.0, $r);
$marcaAud = inv_auditoria_marca($master);
$r = inv_llamar($c, 'PUT', "/api/facturas-simples/{$id}", ['items' => [inv_linea_simple($p, 5)]]);
inv_chk_rechazo('subir a 5: solicitado 5, disponible 4 (1 + los 3 que la factura ya tiene) y faltante 1', $r,
    [['product_id' => $p, 'solicitado' => 5.0, 'disponible' => 4.0, 'faltante' => 1.0]]);
wa_chk('el rechazo deshace todo el PUT: la existencia sigue en 1, las lineas en 3 y no hay movimientos nuevos',
    inv_stock($pdo, $p) === 1.0 && inv_lineas_guardadas($pdo, $id) === [[$p, 3.0]] && count(inv_movs($pdo, $p)) === 1,
    [inv_stock($pdo, $p), inv_lineas_guardadas($pdo, $id), inv_movs($pdo, $p)]);
$aud = inv_auditoria($master, $tenantId, $marcaAud, 'facturas-simples', 'UPDATE');
wa_chk('el PUT rechazado queda en la auditoria como UPDATE fallido', count($aud) === 1 && $aud[0]['success'] === 0, $aud);
$q = inv_producto($pdo, 'INV-S7B', '1');
$r = inv_llamar($c, 'PUT', "/api/facturas-simples/{$id}", ['items' => [inv_linea_simple($p, 3), inv_linea_simple($q, 2)]]);
inv_chk_rechazo('agregar una linea de otro producto sin existencia suficiente: solo esa falta', $r,
    [['product_id' => $q, 'solicitado' => 2.0, 'disponible' => 1.0, 'faltante' => 1.0]]);
wa_chk('y sigue todo igual: la existencia de los dos y las lineas guardadas',
    inv_stock($pdo, $p) === 1.0 && inv_stock($pdo, $q) === 1.0 && inv_lineas_guardadas($pdo, $id) === [[$p, 3.0]]);
$r = inv_llamar($c, 'PUT', "/api/facturas-simples/{$id}", ['items' => [inv_linea_simple($p, 4)]]);
wa_chk('subir a 4 (el aumento neto es 1 y queda 1) pasa: 200 y la existencia llega a 0',
    $r['status'] === 200 && inv_stock($pdo, $p) === 0.0 && inv_lineas_guardadas($pdo, $id) === [[$p, 4.0]], [$r['status'], inv_stock($pdo, $p)]);

// ---------------------------------------------------------------------------
// 8) DELETE devuelve el stock
// ---------------------------------------------------------------------------
echo "8) on simple DELETE devuelve el stock\n";
$ids = inv_reiniciar($c, 'acepta', true);
$p = inv_producto($pdo, 'INV-S8', '5');
$r = inv_simple($c, $ids, [inv_linea_simple($p, 3)]);
$id = (int) ($r['json']['data']['id'] ?? 0);
wa_chk('precondicion: la factura toma 3 y deja la existencia en 2', $r['status'] === 201 && inv_stock($pdo, $p) === 2.0, $r);
$r = inv_llamar($c, 'DELETE', "/api/facturas-simples/{$id}");
wa_chk('DELETE: 200', $r['status'] === 200 && ($r['json']['status'] ?? null) === true, $r);
wa_chk('la existencia vuelve a 5 con un movimiento DEVOLUCION y la factura ya no existe',
    inv_stock($pdo, $p) === 5.0 && inv_movs($pdo, $p) === [['VENTA', 'factura', -3.0, 5.0, 2.0], ['DEVOLUCION', 'factura', 3.0, 2.0, 5.0]]
    && inv_contar($pdo, 'SELECT COUNT(*) FROM facturas WHERE id = :i', [':i' => $id]) === 0, [inv_stock($pdo, $p), inv_movs($pdo, $p)]);

// ---------------------------------------------------------------------------
// 9) Dos procesos, la ultima unidad
// ---------------------------------------------------------------------------
echo "9) on simple: dos procesos venden la ultima unidad, solo uno pasa\n";
$ids = inv_reiniciar($c, 'acepta', true);
for ($ronda = 1; $ronda <= 3; $ronda++) {
    $p = inv_producto($pdo, 'INV-S9', '1');
    inv_fijar_stock($pdo, $p, '1');
    $pdo->exec('DELETE FROM inventory_movements');
    $barrera = inv_tmp('barrera');
    $hijos = [];
    foreach (['a', 'b'] as $n) {
        $listo = inv_tmp('listo_' . $n);
        $hijos[$n] = [inv_hijo(['--hijo=vender', '--tenant-db=' . INV_BASE_EMPRESA, '--cliente=' . $ids['client_id'],
            '--producto=' . $p, '--cantidad=1', '--listo=' . $listo, '--barrera=' . $barrera]), $listo];
    }
    foreach ($hijos as [, $listo]) {
        inv_esperar($listo);
        @unlink($listo);
    }
    touch($barrera);
    $resultados = [];
    foreach ($hijos as $n => [$h]) {
        $resultados[$n] = inv_cerrar_hijo($h);
    }
    @unlink($barrera);
    $tipos = array_column($resultados, 'resultado');
    sort($tipos);
    $perdedor = array_values(array_filter($resultados, static fn(array $x): bool => ($x['resultado'] ?? '') === 'rechazada'))[0] ?? [];
    wa_chk("ronda {$ronda}: uno vende y el otro es rechazado por existencia", $tipos === ['ok', 'rechazada']
        && ($perdedor['codigo'] ?? null) === 'STOCK_INSUFICIENTE' && ($perdedor['codigo_http'] ?? null) === 422, $resultados);
    wa_chk("ronda {$ronda}: queda una sola factura de esa venta, la existencia en 0 (no -1) y un solo movimiento",
        inv_stock($pdo, $p) === 0.0 && count(inv_movs($pdo, $p)) === 1
        && inv_contar($pdo, 'SELECT COUNT(*) FROM factura_items WHERE product_id = :p', [':p' => $p]) === 1,
        [inv_stock($pdo, $p), inv_movs($pdo, $p)]);
    $pdo->exec('DELETE FROM inventory_movements');
    $pdo->exec('DELETE FROM factura_items');
    $pdo->exec('DELETE FROM facturas');
}

// ---------------------------------------------------------------------------
// 10) e-CF: se rechaza antes de reservar el e-NCF
// ---------------------------------------------------------------------------
echo "10) on e-CF: rechaza antes de reservar e-NCF (contador sin cambios)\n";
$ids = inv_reiniciar($c, 'acepta', true);
$p = inv_producto($pdo, 'INV-E10', '1', ['nombre' => 'Cemento gris']);
$marcaAud = inv_auditoria_marca($master);
$r = inv_emitir($c, '31', $ids, [inv_linea_ecf($p, 2)]);
inv_chk_rechazo('E31 pide 2 con existencia 1: 422 con faltantes (solicitado 2, disponible 1, faltante 1)', $r,
    [['product_id' => $p, 'solicitado' => 2.0, 'disponible' => 1.0, 'faltante' => 1.0]]);
wa_chk('el contador de E31 no se movio: el e-NCF nunca se dispenso', wa_contador($pdo, 'E31', INV_AMBIENTE) === 0, wa_contador($pdo, 'E31', INV_AMBIENTE));
wa_chk('no hay ninguna fila en facturas', inv_contar($pdo, 'SELECT COUNT(*) FROM facturas') === 0);
wa_chk('la DGII no recibio nada', inv_recepciones(wa_mock_log($c['mock'])) === []);
wa_chk('la existencia no se movio', inv_stock($pdo, $p) === 1.0 && inv_movs($pdo, $p) === []);
$aud = inv_auditoria($master, $tenantId, $marcaAud, 'facturas', 'EMIT');
wa_chk('el intento queda en la auditoria como EMIT fallido con los faltantes',
    count($aud) === 1 && $aud[0]['success'] === 0 && str_contains((string) $aud[0]['error_message'], 'No hay existencia suficiente')
    && ($aud[0]['new_values']['faltantes'][0]['product_id'] ?? null) === $p, $aud);
inv_fijar_stock($pdo, $p, '4');
$r = inv_emitir($c, '31', $ids, [inv_linea_ecf($p, 3), inv_linea_ecf($p, 3)]);
inv_chk_rechazo('dos lineas de 3 del mismo producto contra existencia 4: una sola fila, solicitado 6, disponible 4, faltante 2', $r,
    [['product_id' => $p, 'solicitado' => 6.0, 'disponible' => 4.0, 'faltante' => 2.0]]);
wa_chk('sigue sin consumirse ningun numero', wa_contador($pdo, 'E31', INV_AMBIENTE) === 0 && inv_contar($pdo, 'SELECT COUNT(*) FROM facturas') === 0);
inv_fijar_stock($pdo, $p, '2');
$r = inv_emitir($c, '31', $ids, [inv_linea_ecf($p, 2)]);
wa_chk('pedir exactamente la existencia (2 de 2) pasa: 200 ACEPTADO', $r['status'] === 200 && ($r['json']['data']['estado_dgii'] ?? null) === 'ACEPTADO', $r);
wa_chk('el stock se mueve despues del veredicto de la DGII: saldo 0 y un movimiento VENTA',
    inv_stock($pdo, $p) === 0.0 && inv_movs($pdo, $p) === [['VENTA', 'factura', -2.0, 2.0, 0.0]], [inv_stock($pdo, $p), inv_movs($pdo, $p)]);
wa_chk('ese si consumio un numero', wa_contador($pdo, 'E31', INV_AMBIENTE) === 1, wa_contador($pdo, 'E31', INV_AMBIENTE));
$r = inv_emitir($c, '31', $ids, [inv_linea_ecf($p, 1)]);
inv_chk_rechazo('con existencia 0 cualquier cantidad rechaza', $r,
    [['product_id' => $p, 'solicitado' => 1.0, 'disponible' => 0.0, 'faltante' => 1.0]]);
wa_chk('y el contador sigue en 1', wa_contador($pdo, 'E31', INV_AMBIENTE) === 1);
$nulo = inv_producto($pdo, 'INV-E10N', null);
$r = inv_emitir($c, '31', $ids, [inv_linea_ecf($nulo, 1)]);
inv_chk_rechazo('un Bien con existencia NULL cuenta como 0', $r,
    [['product_id' => $nulo, 'solicitado' => 1.0, 'disponible' => 0.0, 'faltante' => 1.0]]);
// Un request crudo con strict_input tampoco se salta la regla (spec 4.3).
wa_mock_log($c['mock'], true);   // las recepciones de arriba ya no cuentan
$r = inv_emitir($c, '31', $ids, [inv_linea_ecf($nulo, 1)], [
    'strict_input' => true, 'totales' => ['monto_total' => 1180.0, 'monto_gravado_total' => 1000.0],
    'comprador' => ['rnc' => '000000001', 'razon_social' => 'WA CLIENTE DE PRUEBA SRL'],
]);
wa_chk('con strict_input la regla corre igual: 422 con STOCK_INSUFICIENTE y sin tocar la DGII',
    $r['status'] === 422 && ($r['json']['codigo'] ?? null) === 'STOCK_INSUFICIENTE' && inv_recepciones(wa_mock_log($c['mock'])) === [], $r);

// ---------------------------------------------------------------------------
// 11) e-CF: el reenvio no se revisa
// ---------------------------------------------------------------------------
echo "11) on e-CF: reenvio no se revisa\n";
$ids = inv_reiniciar($c, 'rechaza', true);
$p = inv_producto($pdo, 'INV-E11', '3');
$r = inv_emitir($c, '31', $ids, [inv_linea_ecf($p, 3)]);
$d1 = $r['json']['data'] ?? [];
$eNcf = (string) ($d1['e_ncf'] ?? '');
$idRechazada = (int) ($d1['factura_id'] ?? 0);
wa_chk('precondicion: pide 3 con existencia 3, pasa la regla y la DGII la rechaza (422 con la fila RECHAZADO)',
    $r['status'] === 422 && ($r['json']['codigo'] ?? null) === null
    && (wa_filas($pdo, $eNcf)[0]['estado_dgii'] ?? null) === 'RECHAZADO' && inv_stock($pdo, $p) === 3.0, $r);
inv_fijar_stock($pdo, $p, '0');   // otra venta o un conteo se llevo la existencia mientras tanto
$r = inv_emitir($c, '31', $ids, [inv_linea_ecf($p, 3)]);
inv_chk_rechazo('contraste: una factura NUEVA igual si se rechaza ahora por existencia', $r,
    [['product_id' => $p, 'solicitado' => 3.0, 'disponible' => 0.0, 'faltante' => 3.0]]);
wa_mock_modo($c['mock'], 'acepta');
wa_mock_log($c['mock'], true);
$marca = inv_log_marca($c);
$r = inv_llamar($c, 'POST', "/api/facturas/{$idRechazada}/reenviar", []);
$d2 = $r['json']['data'] ?? [];
wa_chk('el reenvio de la rechazada NO se revisa: 200, mismo e-NCF, reenvio_de la rechazada, ACEPTADO',
    $r['status'] === 200 && ($d2['e_ncf'] ?? null) === $eNcf && ($d2['reenvio_de'] ?? null) === $idRechazada
    && ($d2['estado_dgii'] ?? null) === 'ACEPTADO', $r);
wa_chk('la DGII recibio el reenvio', count(inv_recepciones(wa_mock_log($c['mock']))) === 1);
wa_chk('la mercancia ya vendida sale igual: existencia -3', inv_stock($pdo, $p) === -3.0, inv_stock($pdo, $p));
wa_chk('y ese saldo negativo se anota como SOBREVENTA en el log (regla activa)',
    str_contains(inv_log_desde($c, $marca), '[inventario] SOBREVENTA factura ' . (int) ($d2['factura_id'] ?? 0) . ' producto ' . $p . ' saldo -3'), inv_log_desde($c, $marca));

// ---------------------------------------------------------------------------
// 12) e-CF: nota de credito
// ---------------------------------------------------------------------------
echo "12) on e-CF: nota de credito E34 suma sin revisar\n";
$ids = inv_reiniciar($c, 'acepta', true);
$p = inv_producto($pdo, 'INV-E12', '0');
$r = inv_emitir($c, '31', $ids, [inv_linea_ecf(null, 1, 10000.0)]);   // E31 original con una linea libre: 11800.00
$original = $r['json']['data'] ?? [];
wa_chk('precondicion: la E31 original (linea libre) se emite con la regla activa: 200 ACEPTADO',
    $r['status'] === 200 && ($original['estado_dgii'] ?? null) === 'ACEPTADO', $r);
$referencia = ['ncf_modificado' => (string) ($original['e_ncf'] ?? ''), 'fecha_ncf_modificado' => date('d-m-Y'),
    'codigo_modificacion' => '3', 'razon_modificacion' => 'Devolucion de prueba INV'];
$r = inv_emitir($c, '34', $ids, [inv_linea_ecf($p, 3, 100.0)], ['informacion_referencia' => $referencia]);
wa_chk('E34 con 3 unidades de un producto con existencia 0: 200 ACEPTADO (una entrada no se revisa)',
    $r['status'] === 200 && ($r['json']['data']['estado_dgii'] ?? null) === 'ACEPTADO', $r);
wa_chk('la existencia SUBE a 3 con un movimiento DEVOLUCION',
    inv_stock($pdo, $p) === 3.0 && inv_movs($pdo, $p) === [['DEVOLUCION', 'factura', 3.0, 0.0, 3.0]], [inv_stock($pdo, $p), inv_movs($pdo, $p)]);

// ---------------------------------------------------------------------------
// 13) e-CF: servicios y lineas libres
// ---------------------------------------------------------------------------
echo "13) on e-CF: servicio y linea libre pasan\n";
$ids = inv_reiniciar($c, 'acepta', true);
$servicio = inv_producto($pdo, 'INV-E13S', null, ['servicio' => true]);
$bien = inv_producto($pdo, 'INV-E13B', '1');
$r = inv_emitir($c, '31', $ids, [inv_linea_ecf($servicio, 5, 500.0, true), inv_linea_ecf(null, 2, 300.0), inv_linea_ecf($bien, 1)]);
wa_chk('un servicio (existencia NULL), una linea libre y un Bien que alcanza: 200 ACEPTADO',
    $r['status'] === 200 && ($r['json']['data']['estado_dgii'] ?? null) === 'ACEPTADO', $r);
wa_chk('solo el Bien se mueve: saldo 0 y un movimiento; el servicio sigue NULL y sin movimientos',
    inv_stock($pdo, $bien) === 0.0 && count(inv_movs($pdo, $bien)) === 1 && inv_stock($pdo, $servicio) === null && inv_movs($pdo, $servicio) === [],
    [inv_stock($pdo, $bien), inv_stock($pdo, $servicio), inv_movs($pdo, $servicio)]);
$simple = inv_simple($c, $ids, [inv_linea_simple($servicio, 7)]);
wa_chk('en una factura simple un servicio tampoco se revisa: 201', $simple['status'] === 201 && inv_stock($pdo, $servicio) === null && inv_movs($pdo, $servicio) === [], $simple);

// ---------------------------------------------------------------------------
// 14) Productos inexistentes y cantidades <= 0
// ---------------------------------------------------------------------------
echo "14) on: producto inexistente y cantidad <= 0 dan 422 llano sin codigo\n";
$ids = inv_reiniciar($c, 'acepta', true);
$p = inv_producto($pdo, 'INV-14', '10');
$r = inv_emitir($c, '31', $ids, [inv_linea_ecf(2147480000, 1)]);
inv_chk_llano('e-CF con un product_id que no existe: 422 sin codigo ni data (no es un faltante)', $r);
wa_chk('e-CF inexistente: dice que el producto ya no existe, y no consumio numero ni toco la DGII',
    str_contains((string) ($r['json']['error'] ?? ''), 'ya no existe') && wa_contador($pdo, 'E31', INV_AMBIENTE) === 0
    && inv_recepciones(wa_mock_log($c['mock'])) === [], $r);
foreach ([0.0, -2.0] as $cantidad) {
    $r = inv_emitir($c, '31', $ids, [inv_linea_ecf($p, $cantidad)]);
    inv_chk_llano("e-CF con cantidad {$cantidad}: 422 sin codigo ni data", $r);
}
wa_chk('e-CF: ninguna de esas tres dejo filas ni movio la existencia', inv_contar($pdo, 'SELECT COUNT(*) FROM facturas') === 0
    && inv_stock($pdo, $p) === 10.0 && wa_contador($pdo, 'E31', INV_AMBIENTE) === 0);
$r = inv_simple($c, $ids, [inv_linea_simple(2147480000, 1)]);
inv_chk_llano('simple con un product_id que no existe: 422 sin codigo ni data (no el FK ni el 400 generico)', $r);
wa_chk('simple inexistente: dice que el producto ya no existe y no dejo ninguna factura',
    str_contains((string) ($r['json']['error'] ?? ''), 'ya no existe') && inv_simples($pdo) === 0, $r);
foreach ([0.0, -2.0] as $cantidad) {
    $r = inv_simple($c, $ids, [inv_linea_simple($p, $cantidad)]);
    inv_chk_llano("simple con cantidad {$cantidad}: 422 sin codigo ni data", $r);
}
wa_chk('simple: ninguna de esas dejo facturas ni movio la existencia', inv_simples($pdo) === 0 && inv_stock($pdo, $p) === 10.0);
$idOk = (int) (inv_simple($c, $ids, [inv_linea_simple($p, 1)])['json']['data']['id'] ?? 0);
$r = inv_llamar($c, 'PUT', "/api/facturas-simples/{$idOk}", ['items' => [inv_linea_simple(2147480000, 1)]]);
inv_chk_llano('simple PUT con un product_id que no existe: 422 sin codigo ni data', $r);
wa_chk('y el PUT no cambio nada: las lineas siguen y la existencia es 9', inv_lineas_guardadas($pdo, $idOk) === [[$p, 1.0]] && inv_stock($pdo, $p) === 9.0,
    [inv_lineas_guardadas($pdo, $idOk), inv_stock($pdo, $p)]);

// ---------------------------------------------------------------------------
// 15) Formulario de producto
// ---------------------------------------------------------------------------
echo "15) on: PUT /api/products ignora stock; POST con existencia crea un movimiento INVENTARIO_INICIAL; off: ambos como hoy\n";
$ids = inv_reiniciar($c, 'acepta', true);
$almacen = inv_contar($pdo, "SELECT id FROM warehouses WHERE nombre = 'WA almacen'");
$cuerpoProducto = static fn(string $sku, mixed $stock): array => [
    'sku' => $sku, 'nombre' => 'INV ' . $sku, 'precio' => 100, 'costo' => 40, 'indicador_bien_servicio' => 1,
    'indicador_facturacion' => 1, 'unidad_medida' => '43', 'warehouse_id' => $almacen, 'stock' => $stock,
];
$r = inv_llamar($c, 'POST', '/api/products', $cuerpoProducto('INV-P1', 7));
$pid = (int) ($r['json']['data']['id'] ?? 0);
wa_chk('on: POST con existencia 7 crea el producto (200)', $r['status'] === 200 && ($r['json']['status'] ?? null) === true && $pid > 0, $r);
wa_chk('on: la existencia 7 entro por el libro: un movimiento AJUSTE de +7 (de 0 a 7)',
    inv_stock($pdo, $pid) === 7.0 && inv_movs($pdo, $pid) === [['AJUSTE', 'ajuste', 7.0, 0.0, 7.0]], [inv_stock($pdo, $pid), inv_movs($pdo, $pid)]);
$ajuste = $pdo->query("SELECT motivo, total_lineas, warehouse_id, user_id FROM inventory_adjustments ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: [];
wa_chk('on: el ajuste tiene el motivo INVENTARIO_INICIAL, una linea, el almacen del producto y el usuario del token',
    ($ajuste['motivo'] ?? null) === 'INVENTARIO_INICIAL' && (int) ($ajuste['total_lineas'] ?? 0) === 1
    && (int) ($ajuste['warehouse_id'] ?? 0) === $almacen && (int) ($ajuste['user_id'] ?? 0) > 0, $ajuste);
$r = inv_llamar($c, 'PUT', '/api/products', ['id' => $pid] + $cuerpoProducto('INV-P1', 999));
wa_chk('on: PUT con stock 999 responde 200 (el campo se ignora sin error)', $r['status'] === 200 && ($r['json']['status'] ?? null) === true, $r);
wa_chk('on: la existencia sigue en 7 y no hay movimientos nuevos', inv_stock($pdo, $pid) === 7.0 && count(inv_movs($pdo, $pid)) === 1, [inv_stock($pdo, $pid), inv_movs($pdo, $pid)]);
$r = inv_llamar($c, 'PUT', '/api/products', ['id' => $pid] + array_merge($cuerpoProducto('INV-P1', 999), ['nombre' => 'INV renombrado']));
wa_chk('on: el PUT si cambia lo demas (el nombre) y deja la existencia', $r['status'] === 200
    && (string) $pdo->query('SELECT nombre FROM products WHERE id = ' . $pid)->fetchColumn() === 'INV renombrado' && inv_stock($pdo, $pid) === 7.0, $r);
$r = inv_llamar($c, 'POST', '/api/products', $cuerpoProducto('INV-P2', 0));
$pid2 = (int) ($r['json']['data']['id'] ?? 0);
wa_chk('on: POST con existencia 0 crea el producto sin movimientos', $pid2 > 0 && inv_movs($pdo, $pid2) === [], $r);
$r = inv_llamar($c, 'POST', '/api/products', array_merge($cuerpoProducto('INV-P3', 5), ['indicador_bien_servicio' => 2]));
$pid3 = (int) ($r['json']['data']['id'] ?? 0);
wa_chk('on: POST de un servicio con existencia no registra movimientos', $pid3 > 0 && inv_movs($pdo, $pid3) === [], $r);
$r = inv_llamar($c, 'POST', '/api/products', $cuerpoProducto('INV-P4', -2));
wa_chk('on: POST con existencia inicial negativa: 422 y el producto no se crea',
    $r['status'] === 422 && inv_contar($pdo, "SELECT COUNT(*) FROM products WHERE sku = 'INV-P4'") === 0, $r);

inv_interruptor($master, $tenantId, false);
$r = inv_llamar($c, 'POST', '/api/products', $cuerpoProducto('INV-P5', 7));
$pidOff = (int) ($r['json']['data']['id'] ?? 0);
wa_chk('off: POST con existencia 7 la escribe directo en el producto, sin movimientos (como hoy)',
    $r['status'] === 200 && inv_stock($pdo, $pidOff) === 7.0 && inv_movs($pdo, $pidOff) === [], [$r['status'], inv_stock($pdo, $pidOff), inv_movs($pdo, $pidOff)]);
$r = inv_llamar($c, 'PUT', '/api/products', ['id' => $pidOff] + $cuerpoProducto('INV-P5', 999));
wa_chk('off: PUT con stock 999 lo guarda (como hoy), sin movimientos', $r['status'] === 200 && inv_stock($pdo, $pidOff) === 999.0 && inv_movs($pdo, $pidOff) === [], [$r['status'], inv_stock($pdo, $pidOff)]);
$r = inv_llamar($c, 'PUT', '/api/products', ['id' => $pidOff] + $cuerpoProducto('INV-P5', null));
wa_chk('off: PUT con stock vacio lo deja en NULL (como hoy)', $r['status'] === 200 && inv_stock($pdo, $pidOff) === null, [$r['status'], inv_stock($pdo, $pidOff)]);

// ---------------------------------------------------------------------------
// 16) Configuracion
// ---------------------------------------------------------------------------
echo "16) config: GET para cualquier rol, PUT solo admin (403 para otro rol), cambia branding.inventario_estricto\n";
$ids = inv_reiniciar($c, 'acepta', false);
$base = (int) (inv_llamar($c, 'GET', '/api/inventario/configuracion', null, 'user')['json']['data']['productos_sin_existencia'] ?? -1);
inv_producto($pdo, 'INV-C1', null);                                   // cuenta (NULL)
inv_producto($pdo, 'INV-C2', '0');                                    // cuenta (0)
inv_producto($pdo, 'INV-C3', '-2');                                   // cuenta (negativo)
inv_producto($pdo, 'INV-C4', null, ['servicio' => true]);             // no: servicio
inv_producto($pdo, 'INV-C5', '0', ['activo' => false]);               // no: inactivo
inv_producto($pdo, 'INV-C6', '5');                                    // no: con existencia
inv_producto($pdo, 'INV-C7', '0.001');                                // no: queda algo
$r = inv_llamar($c, 'GET', '/api/inventario/configuracion', null, 'user');
$d = $r['json']['data'] ?? [];
wa_chk('GET como rol user: 200 con bloquear_sin_existencia false y el conteo como numero',
    $r['status'] === 200 && ($r['json']['status'] ?? null) === true && inv_claves($d) === ['bloquear_sin_existencia', 'productos_sin_existencia']
    && $d['bloquear_sin_existencia'] === false && is_int($d['productos_sin_existencia']), $r);
wa_chk('el conteo suma los 3 Bienes activos con NULL, 0 o negativo y no el servicio, el inactivo ni los que tienen existencia',
    ($d['productos_sin_existencia'] ?? null) === $base + 3, [$base, $d]);
$r = inv_llamar($c, 'GET', '/api/inventario/configuracion', null, 'admin');
wa_chk('GET como admin: 200', $r['status'] === 200 && ($r['json']['data']['bloquear_sin_existencia'] ?? null) === false, $r);
$r = inv_llamar($c, 'GET', '/api/branding', null, 'user');
wa_chk('GET /api/branding trae inventario_estricto false con el interruptor apagado', $r['status'] === 200 && ($r['json']['data']['inventario_estricto'] ?? null) === false, $r['json']['data'] ?? $r);
$marcaAud = inv_auditoria_marca($master);
$r = inv_llamar($c, 'PUT', '/api/inventario/configuracion', ['bloquear_sin_existencia' => true], 'user');
wa_chk('PUT como rol user: 403', $r['status'] === 403 && ($r['json']['status'] ?? null) === false, $r);
wa_chk('el 403 no cambio nada: el interruptor sigue en 0', inv_valor_interruptor($master, $tenantId) === 0);
$r = inv_llamar($c, 'PUT', '/api/inventario/configuracion', ['bloquear_sin_existencia' => 'true']);
wa_chk('PUT con un texto en vez de un booleano: 422 y no cambia nada', $r['status'] === 422 && inv_valor_interruptor($master, $tenantId) === 0, $r);
$r = inv_llamar($c, 'PUT', '/api/inventario/configuracion', ['bloquear_sin_existencia' => 1]);
wa_chk('PUT con el numero 1: 422', $r['status'] === 422 && inv_valor_interruptor($master, $tenantId) === 0, $r);
$r = inv_llamar($c, 'PUT', '/api/inventario/configuracion', ['bloquear_sin_existencia' => true]);
wa_chk('PUT como admin con true: 200 con el mismo cuerpo del GET',
    $r['status'] === 200 && ($r['json']['data']['bloquear_sin_existencia'] ?? null) === true && is_int($r['json']['data']['productos_sin_existencia'] ?? null), $r);
wa_chk('la master queda en 1', inv_valor_interruptor($master, $tenantId) === 1);
$r = inv_llamar($c, 'GET', '/api/branding', null, 'user');
wa_chk('GET /api/branding trae inventario_estricto true', ($r['json']['data']['inventario_estricto'] ?? null) === true, $r['json']['data'] ?? $r);
$r = inv_llamar($c, 'GET', '/api/inventario/configuracion', null, 'user');
wa_chk('GET como rol user ahora dice true', ($r['json']['data']['bloquear_sin_existencia'] ?? null) === true, $r);
$p = inv_producto($pdo, 'INV-C8', '1');
$r = inv_simple($c, $ids, [inv_linea_simple($p, 2)]);
wa_chk('y la regla corre sin reiniciar nada: una simple de 2 contra 1 se rechaza', ($r['json']['codigo'] ?? null) === 'STOCK_INSUFICIENTE', $r);
$aud = inv_auditoria($master, $tenantId, $marcaAud, 'inventario', 'INVENTARIO_CONFIG');
wa_chk('el cambio queda auditado como INVENTARIO_CONFIG con el valor de antes y el de despues',
    count($aud) === 1 && $aud[0]['success'] === 1 && ($aud[0]['old_values']['bloquear_sin_existencia'] ?? null) === false
    && ($aud[0]['new_values']['bloquear_sin_existencia'] ?? null) === true, $aud);
$r = inv_llamar($c, 'PUT', '/api/inventario/configuracion', ['bloquear_sin_existencia' => false]);
wa_chk('PUT como admin con false: 200', $r['status'] === 200 && ($r['json']['data']['bloquear_sin_existencia'] ?? null) === false, $r);
wa_chk('la master vuelve a 0 y branding lo refleja', inv_valor_interruptor($master, $tenantId) === 0
    && (inv_llamar($c, 'GET', '/api/branding', null, 'user')['json']['data']['inventario_estricto'] ?? null) === false);
$r = inv_simple($c, $ids, [inv_linea_simple($p, 2)]);
wa_chk('apagado otra vez, la misma venta se guarda y deja negativo', $r['status'] === 201 && inv_stock($pdo, $p) === -1.0, [$r['status'], inv_stock($pdo, $p)]);
$r = inv_llamar($c, 'PUT', '/api/inventario/ajustes', ['bloquear_sin_existencia' => true]);
wa_chk('el PUT sobre otra ruta del controlador sigue siendo 405', $r['status'] === 405, $r['status']);
$sinToken = wa_http('PUT', $c['api'] . '/api/inventario/configuracion', ['bloquear_sin_existencia' => true]);
wa_chk('PUT sin token: 401 y no cambia nada', $sinToken['status'] === 401 && inv_valor_interruptor($master, $tenantId) === 0, $sinToken['status']);

// ---------------------------------------------------------------------------
// 17) SOBREVENTA
// ---------------------------------------------------------------------------
echo "17) SOBREVENTA se registra cuando un e-CF deja saldo negativo con la regla activa\n";
$ids = inv_reiniciar($c, 'cuelga', true);
$p = inv_producto($pdo, 'INV-17', '5');
$r = inv_emitir($c, '31', $ids, [inv_linea_ecf($p, 5)]);
$d = $r['json']['data'] ?? [];
$id = (int) ($d['factura_id'] ?? 0);
$eNcf = (string) ($d['e_ncf'] ?? '');
wa_chk('precondicion: el e-CF de 5 (existencia 5) pasa la revision y la DGII no contesta: 502 incierto, la fila queda en vuelo y no retiene existencia',
    $r['status'] === 502 && ($d['estado_dgii'] ?? null) === 'ENVIO_INCIERTO' && inv_stock($pdo, $p) === 5.0 && inv_movs($pdo, $p) === [], $r);
wa_mock_modo($c['mock'], 'acepta');   // espera a que termine el cuelga
$r = inv_simple($c, $ids, [inv_linea_simple($p, 5)]);
wa_chk('mientras tanto, otra venta (factura simple) se lleva las 5 unidades: 201 y existencia 0',
    $r['status'] === 201 && inv_stock($pdo, $p) === 0.0, [$r['status'], inv_stock($pdo, $p)]);
// Caso 1 del procedimiento 10.2 del write-ahead: soporte le pone el trackId que da la DGII.
$st = $pdo->prepare("UPDATE facturas SET estado_dgii = 'ENVIADO', track_id = :t WHERE id = :id AND estado_dgii = 'ENVIO_INCIERTO'");
$st->execute([':t' => 'track-' . $eNcf, ':id' => $id]);
$marca = inv_log_marca($c);
$r = inv_llamar($c, 'GET', "/api/facturas/{$id}/estado");
wa_chk('la consulta de estado ve la factura aceptada: ACEPTADO', ($r['json']['data']['estado_dgii'] ?? null) === 'ACEPTADO', $r);
wa_chk('el stock se mueve entonces y deja -5 (la ventana de e-CF que el spec acepta)', inv_stock($pdo, $p) === -5.0 && count(inv_movs($pdo, $p)) === 2,
    [inv_stock($pdo, $p), inv_movs($pdo, $p)]);
wa_chk('el log dice SOBREVENTA con la factura, el producto y el saldo',
    str_contains(inv_log_desde($c, $marca), '[inventario] SOBREVENTA factura ' . $id . ' producto ' . $p . ' saldo -5'), inv_log_desde($c, $marca));
$r = inv_llamar($c, 'GET', "/api/facturas/{$id}/estado");
wa_chk('un segundo GET no mueve mas ni repite el aviso', inv_stock($pdo, $p) === -5.0 && substr_count(inv_log_desde($c, $marca), 'SOBREVENTA') === 1);

// ---------------------------------------------------------------------------
// 18) La salida: un Ajuste ENTRADA
// ---------------------------------------------------------------------------
echo "18) el camino de salida: tras el rechazo, un Ajuste ENTRADA hace posible la venta\n";
$ids = inv_reiniciar($c, 'acepta', true);
$p = inv_producto($pdo, 'INV-18', '2');
$r = inv_simple($c, $ids, [inv_linea_simple($p, 5)]);
$faltante = (float) ($r['json']['data']['faltantes'][0]['faltante'] ?? 0);
wa_chk('precondicion: la venta de 5 con existencia 2 se rechaza y dice que faltan 3', $r['status'] === 422 && inv_cerca($faltante, 3.0), $r);
$r = inv_llamar($c, 'POST', '/api/inventario/ajustes', [
    'motivo' => 'ENTRADA', 'nota' => 'Mercancia que llego', 'lineas' => [['product_id' => $p, 'tipo' => 'INCREMENTO', 'cantidad' => $faltante]],
]);
wa_chk('el Ajuste con motivo ENTRADA y la cantidad que faltaba se crea (201)', in_array($r['status'], [200, 201], true) && ($r['json']['status'] ?? null) === true
    && ($r['json']['data']['motivo'] ?? null) === 'ENTRADA', $r);
wa_chk('la existencia llega a 5', inv_stock($pdo, $p) === 5.0, inv_stock($pdo, $p));
$r = inv_simple($c, $ids, [inv_linea_simple($p, 5)]);
wa_chk('la misma venta ahora pasa: 201 y existencia 0', $r['status'] === 201 && inv_stock($pdo, $p) === 0.0, [$r['status'], inv_stock($pdo, $p)]);
$r = inv_llamar($c, 'POST', '/api/inventario/ajustes', ['motivo' => 'INVENTARIO_INICIAL', 'lineas' => [['product_id' => $p, 'tipo' => 'INCREMENTO', 'cantidad' => 1]]]);
wa_chk('INVENTARIO_INICIAL tambien es un motivo valido de un Ajuste', in_array($r['status'], [200, 201], true) && inv_stock($pdo, $p) === 1.0, $r);
$r = inv_llamar($c, 'POST', '/api/inventario/ajustes', ['motivo' => 'ANULACION', 'lineas' => [['product_id' => $p, 'tipo' => 'INCREMENTO', 'cantidad' => 1]]]);
wa_chk('ANULACION sigue sin poderse elegir en un ajuste del usuario: 422', $r['status'] === 422, $r);

// ---------------------------------------------------------------------------
// 19) El libro contra MySQL real
// ---------------------------------------------------------------------------
echo "19) el libro contra MySQL real: bandera bloquear_sin_existencia y rollback del llamador\n";
$ids = inv_reiniciar($c, 'acepta', true);
$p = inv_producto($pdo, 'INV-L1', '2');
$libro = new inventoryModel($pdo);
$ctx = ['tipo_movimiento' => 'VENTA', 'referencia_tipo' => 'factura', 'referencia_id' => 987654, 'user_id' => null];
$res = $libro->aplicarMovimientos([['product_id' => $p, 'cantidad' => -3.0]], $ctx + ['bloquear_sin_existencia' => true]);
wa_chk('con la bandera: sacar 3 de 2 devuelve el error 422 con faltante 1 y no escribe nada',
    $res[0] === 'error' && ($res[2] ?? null) === 422 && ($res[3]['codigo'] ?? null) === 'STOCK_INSUFICIENTE'
    && inv_cerca($res[3]['faltantes'][0]['faltante'] ?? null, 1.0) && inv_stock($pdo, $p) === 2.0 && inv_movs($pdo, $p) === [] && !$pdo->inTransaction(), $res);
$res = $libro->aplicarMovimientos([['product_id' => $p, 'cantidad' => -3.0]], $ctx);
wa_chk('sin la bandera (el camino de siempre): el mismo movimiento deja -1',
    $res[0] === 'success' && inv_stock($pdo, $p) === -1.0 && inv_movs($pdo, $p) === [['VENTA', 'factura', -3.0, 2.0, -1.0]], [$res[0], inv_stock($pdo, $p)]);
inv_fijar_stock($pdo, $p, '5');
$pdo->exec('DELETE FROM inventory_movements');
$pdo->beginTransaction();
$res1 = $libro->moverVentaEnTransaccion(987655, [['product_id' => $p, 'quantity' => 3.0]], null);
$res2 = $libro->moverVentaEnTransaccion(987656, [['product_id' => $p, 'quantity' => 3.0]], null);
wa_chk('en transaccion: la primera venta de 3 pasa y la segunda (3 de 2 que quedan) devuelve STOCK_INSUFICIENTE',
    $res1[0] === 'success' && $res2[0] === 'error' && ($res2[3]['codigo'] ?? null) === 'STOCK_INSUFICIENTE'
    && inv_cerca($res2[3]['faltantes'][0]['disponible'] ?? null, 2.0) && $pdo->inTransaction(), [$res1, $res2]);
$pdo->rollBack();
wa_chk('el rollback del llamador deshace el descuento de la primera: la existencia vuelve a 5 y no queda ningun movimiento',
    inv_stock($pdo, $p) === 5.0 && inv_movs($pdo, $p) === [], [inv_stock($pdo, $p), inv_movs($pdo, $p)]);
[$res, , $logLibro] = wa_con_log(fn() => $libro->moverVentaEnTransaccion(987657, [['product_id' => $p, 'quantity' => 1.0]], null));
wa_chk('sin una transaccion abierta no mueve nada y devuelve error (cada sentencia se confirmaria sola)',
    is_array($res) && $res[0] === 'error' && inv_stock($pdo, $p) === 5.0 && inv_movs($pdo, $p) === []
    && str_contains($logLibro, 'no hay una transaccion abierta'), [$res, inv_stock($pdo, $p), $logLibro]);

wa_fin();
