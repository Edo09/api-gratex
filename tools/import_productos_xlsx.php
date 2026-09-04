<?php
/**
 * import_productos_xlsx.php — Carga un catalogo de productos desde un xlsx.
 *
 * Pensado para migrar el catalogo de un sistema anterior al dar de alta un
 * tenant. Lee el xlsx con el parser propio (sin dependencias) y escribe en
 * `products` de la DB del tenant.
 *
 * Columnas que entiende (nombre de la cabecera, no importa el orden):
 *   CLAVE                -> sku            (UNIQUE; una fila sin clave se salta)
 *   DESCRIPCION          -> nombre
 *   PRECIO               -> precio         (lista 1)
 *   PRECIO 2 / 3 / 4     -> precio_2/3/4   (0 o vacio = NULL, "no aplica")
 *   COSTO                -> costo
 *   STOCK, STOCK MINIMO  -> stock, stock_minimo
 *   ESTATUS              -> activo         (1/0)
 *   SERVICIO/PRODUCTO    -> indicador_bien_servicio (0=bien -> 1, 1=servicio -> 2)
 *   CVE. CATEGORIA       -> category_id    (crea la categoria si no existe)
 *   UNIDAD               -> unidad_medida  SOLO si --unidad=xlsx (ver abajo)
 *
 * Decisiones que NO se adivinan y van por flag:
 *   --itbis=18|exento|columna   default 18 -> indicador_facturacion=1 en todos.
 *       'columna' respeta EXCENTO DE IMPUESTO / PORCENTAJE IMPUESTO del archivo.
 *   --unidad=43|xlsx            default 43 (Unidad). Con 'xlsx' usa la columna
 *       UNIDAD tal cual, que suele traer codigos del sistema viejo que NO son
 *       los de DGII (un '15' ahi significa Galones en el catalogo DGII).
 *   --categorias=numero|ninguna default numero -> crea categorias con el codigo
 *       como nombre, para renombrarlas despues desde la app.
 *
 * Conexion:
 *   --tenant-id=2                        resuelve la DB via el master (en el server)
 *   --db-host --db-name --db-user --db-pass   conexion directa (ad-hoc)
 *
 * Siempre correr primero con --dry-run: no escribe nada y muestra el resumen,
 * los avisos y las primeras filas tal como quedarian.
 *
 * Re-ejecutable: los SKU que ya existen se saltan (no se duplican ni se pisan).
 */

require_once __DIR__ . '/Fase2XlsxReader.php';

function importarProductosXlsx(array $o): int
{
    $archivo = (string) ($o['file'] ?? '');
    if ($archivo === '' || !is_file($archivo)) {
        impOut("No existe el archivo: {$archivo}\n");
        return 2;
    }
    $dryRun = !empty($o['dry_run']);
    $itbis = (string) ($o['itbis'] ?? '18');
    $modoUnidad = (string) ($o['unidad'] ?? '43');
    $modoCategorias = (string) ($o['categorias'] ?? 'numero');

    // --- Leer el xlsx ---------------------------------------------------
    $lector = new Fase2XlsxReader($archivo);
    $hoja = (string) ($o['hoja'] ?? '');
    if ($hoja === '') {
        $ref = new ReflectionClass($lector);
        $prop = $ref->getProperty('sheetNameToFile');
        $prop->setAccessible(true);
        $hojas = array_keys($prop->getValue($lector));
        $hoja = $hojas[0] ?? 'Sheet1';
    }
    $filas = $lector->readSheet($hoja);
    impOut("Hoja: {$hoja} — " . count($filas) . " filas\n");
    impOut("ITBIS: {$itbis} · Unidad: {$modoUnidad} · Categorias: {$modoCategorias}"
        . ($dryRun ? "  [DRY-RUN]" : "") . "\n\n");

    // --- Mapear ----------------------------------------------------------
    $mapeadas = [];
    $avisos = [];
    $sinClave = 0;
    $vistos = [];
    foreach ($filas as $i => $f) {
        $sku = trim((string) impCol($f, ['CLAVE', 'SKU', 'CODIGO']));
        $nombre = trim((string) impCol($f, ['DESCRIPCION', 'NOMBRE', 'PRODUCTO']));
        if ($sku === '' || $nombre === '') {
            $sinClave++;
            continue;
        }
        if (isset($vistos[$sku])) {
            $avisos[] = "fila " . ($i + 2) . ": SKU {$sku} repetido en el archivo, se ignora la 2a";
            continue;
        }
        $vistos[$sku] = true;

        $precio = impNum(impCol($f, ['PRECIO']));
        $costo = impNum(impCol($f, ['COSTO ', 'COSTO']));
        if ($precio <= 0) {
            $avisos[] = "{$sku}: PRECIO en 0";
        } elseif ($costo > 0 && $precio < $costo) {
            $avisos[] = "{$sku}: precio ({$precio}) por debajo del costo ({$costo})";
        }
        // Las listas 2-4 suelen ser un descuento sobre la 1. Un valor por encima
        // del precio 1, o ridiculamente por debajo, casi siempre es un typo del
        // sistema anterior (un 28 donde iba 28000). Se importa igual, pero avisa.
        foreach (['PRECIO 2' => 2, 'PRECIO 3' => 3, 'PRECIO 4' => 4] as $col => $nLista) {
            $otro = impNum(impCol($f, [$col]));
            if ($otro <= 0 || $precio <= 0) {
                continue;
            }
            if ($otro > $precio) {
                $avisos[] = "{$sku}: precio_{$nLista} ({$otro}) es MAYOR que el precio 1 ({$precio})";
            } elseif ($otro < $precio * 0.5) {
                $avisos[] = "{$sku}: precio_{$nLista} ({$otro}) es menos de la mitad del precio 1 ({$precio})";
            }
        }

        $mapeadas[] = [
            'sku' => mb_substr($sku, 0, 50),
            'nombre' => mb_substr($nombre, 0, 150),
            'categoria_codigo' => trim((string) impCol($f, ['CVE. CATEGORIA', 'CATEGORIA'])),
            'ibs' => ((int) impNum(impCol($f, ['SERVICIO/PRODUCTO']))) === 1 ? 2 : 1,
            'ifact' => impIndicadorFacturacion($f, $itbis),
            'precio' => $precio,
            'precio_2' => impPrecioOpcional(impCol($f, ['PRECIO 2'])),
            'precio_3' => impPrecioOpcional(impCol($f, ['PRECIO 3'])),
            'precio_4' => impPrecioOpcional(impCol($f, ['PRECIO 4'])),
            'costo' => $costo,
            'unidad' => $modoUnidad === 'xlsx'
                ? (trim((string) impCol($f, ['UNIDAD'])) ?: '43')
                : $modoUnidad,
            'stock' => (int) impNum(impCol($f, ['STOCK'])),
            'stock_minimo' => (int) impNum(impCol($f, ['STOCK MINIMO', 'STOCK MÍNIMO'])),
            'activo' => ((int) impNum(impCol($f, ['ESTATUS']))) === 0 ? 0 : 1,
        ];
    }

    if ($sinClave > 0) {
        impOut("{$sinClave} fila(s) sin CLAVE o sin DESCRIPCION: se saltan\n");
    }
    impOut(count($mapeadas) . " producto(s) listos\n");

    // Resumen antes de escribir.
    $cats = array_count_values(array_map(fn($p) => $p['categoria_codigo'] ?: '(sin)', $mapeadas));
    ksort($cats);
    $conP2 = count(array_filter($mapeadas, fn($p) => $p['precio_2'] !== null));
    impOut("Categorias: " . implode('  ', array_map(fn($k, $v) => $k . "×" . $v, array_keys($cats), $cats)) . "\n");
    impOut("Con precio_2: {$conP2}\n");
    if ($avisos) {
        impOut("\nAvisos (" . count($avisos) . "):\n");
        foreach (array_slice($avisos, 0, 10) as $a) {
            impOut("  - {$a}\n");
        }
        if (count($avisos) > 10) {
            impOut("  … y " . (count($avisos) - 10) . " mas\n");
        }
    }

    impOut("\nPrimeras filas tal como quedarian:\n");
    foreach (array_slice($mapeadas, 0, 3) as $p) {
        impOut(sprintf("  %-14s %-38s precio=%s p2=%s costo=%s stock=%s cat=%s ud=%s ifact=%s\n",
            $p['sku'], mb_substr($p['nombre'], 0, 38), $p['precio'],
            $p['precio_2'] ?? '-', $p['costo'], $p['stock'],
            $p['categoria_codigo'] ?: '-', $p['unidad'], $p['ifact']));
    }

    if ($dryRun) {
        impOut("\nDRY-RUN: no se escribio nada.\n");
        return 0;
    }

    // --- Escribir --------------------------------------------------------
    $pdo = impConexion($o);
    if ($pdo === null) {
        return 2;
    }

    $warehouseId = (int) $pdo->query("SELECT id FROM warehouses ORDER BY id LIMIT 1")->fetchColumn();
    if ($warehouseId <= 0) {
        impOut("No hay almacenes: crea 'Almacen Principal' antes de importar.\n");
        return 2;
    }

    // Categorias: una por codigo distinto, con el codigo como nombre.
    $catIds = [];
    if ($modoCategorias === 'numero') {
        foreach (array_keys($cats) as $codigo) {
            if ($codigo === '(sin)') {
                continue;
            }
            $st = $pdo->prepare('SELECT id FROM categories WHERE nombre = :n LIMIT 1');
            $st->execute([':n' => $codigo]);
            $id = $st->fetchColumn();
            if (!$id) {
                $pdo->prepare('INSERT INTO categories (nombre, descripcion, estado, created_at, updated_at)
                               VALUES (:n, :d, 1, NOW(), NOW())')
                    ->execute([':n' => $codigo, ':d' => 'Migrada del catalogo anterior (codigo ' . $codigo . ')']);
                $id = $pdo->lastInsertId();
            }
            $catIds[$codigo] = (int) $id;
        }
        impOut("\nCategorias listas: " . count($catIds) . "\n");
    }

    $existentes = [];
    foreach ($pdo->query('SELECT sku FROM products WHERE sku IS NOT NULL') as $r) {
        $existentes[$r['sku']] = true;
    }

    $sql = 'INSERT INTO products
        (sku, nombre, descripcion, category_id, warehouse_id, indicador_bien_servicio, indicador_facturacion,
         precio, precio_2, precio_3, precio_4, costo, unidad_medida, stock, stock_minimo, activo,
         created_at, updated_at)
        VALUES
        (:sku, :nombre, NULL, :cat, :wh, :ibs, :ifact,
         :precio, :p2, :p3, :p4, :costo, :ud, :stock, :smin, :activo, NOW(), NOW())';
    $st = $pdo->prepare($sql);

    $ins = 0;
    $saltados = 0;
    $errores = 0;
    $pdo->beginTransaction();
    try {
        foreach ($mapeadas as $p) {
            if (isset($existentes[$p['sku']])) {
                $saltados++;
                continue;
            }
            try {
                $st->execute([
                    ':sku' => $p['sku'], ':nombre' => $p['nombre'],
                    ':cat' => $catIds[$p['categoria_codigo']] ?? null,
                    ':wh' => $warehouseId, ':ibs' => $p['ibs'], ':ifact' => $p['ifact'],
                    ':precio' => $p['precio'], ':p2' => $p['precio_2'],
                    ':p3' => $p['precio_3'], ':p4' => $p['precio_4'],
                    ':costo' => $p['costo'], ':ud' => $p['unidad'],
                    ':stock' => $p['stock'], ':smin' => $p['stock_minimo'],
                    ':activo' => $p['activo'],
                ]);
                $ins++;
            } catch (Throwable $e) {
                $errores++;
                impOut("  ! {$p['sku']}: " . substr($e->getMessage(), 0, 90) . "\n");
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        impOut("\nRollback: " . $e->getMessage() . "\n");
        return 1;
    }

    impOut("\n" . str_repeat('=', 56) . "\n");
    impOut("Insertados: {$ins} · Saltados (SKU ya existia): {$saltados} · Errores: {$errores}\n");
    return $errores > 0 ? 1 : 0;
}

/** Primera columna que exista de la lista (las cabeceras varian entre sistemas). */
function impCol(array $fila, array $nombres)
{
    foreach ($nombres as $n) {
        if (array_key_exists($n, $fila)) {
            return $fila[$n];
        }
    }
    return '';
}

function impNum($v): float
{
    $v = trim((string) $v);
    if ($v === '') {
        return 0.0;
    }
    return (float) str_replace([',', '$'], '', $v);
}

/** 0 o vacio en una lista de precios = "no aplica" -> NULL. */
function impPrecioOpcional($v): ?float
{
    $n = impNum($v);
    return $n > 0 ? $n : null;
}

/** indicador_facturacion: 1 = ITBIS 18%, 4 = exento. */
function impIndicadorFacturacion(array $fila, string $modo): int
{
    if ($modo === '18') {
        return 1;
    }
    if ($modo === 'exento') {
        return 4;
    }
    $exento = trim((string) impCol($fila, ['EXCENTO DE IMPUESTO', 'EXENTO DE IMPUESTO', 'EXENTO']));
    if ($exento !== '' && (int) $exento === 1) {
        return 4;
    }
    return impNum(impCol($fila, ['PORCENTAJE IMPUESTO', 'IMPUESTO'])) > 0 ? 1 : 4;
}

function impConexion(array $o): ?PDO
{
    if (!empty($o['tenant_id'])) {
        require_once __DIR__ . '/../src/MasterDatabase.php';
        require_once __DIR__ . '/../src/TenantResolver.php';
        require_once __DIR__ . '/../src/Database.php';
        if (!TenantResolver::resolveById((int) $o['tenant_id'])) {
            impOut("No se resolvio el tenant {$o['tenant_id']}.\n");
            return null;
        }
        return Database::getInstance()->getConnection();
    }
    foreach (['db_host', 'db_name', 'db_user'] as $k) {
        if (empty($o[$k])) {
            impOut("Falta conexion: usa --tenant-id o --db-host/--db-name/--db-user/--db-pass.\n");
            return null;
        }
    }
    try {
        return new PDO(
            'mysql:host=' . $o['db_host'] . ';port=' . ($o['db_port'] ?? '3306')
                . ';dbname=' . $o['db_name'] . ';charset=utf8mb4',
            (string) $o['db_user'],
            (string) ($o['db_pass'] ?? ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    } catch (Throwable $e) {
        impOut('No se pudo conectar: ' . $e->getMessage() . "\n");
        return null;
    }
}

function impOut(string $s): void
{
    if (PHP_SAPI === 'cli') {
        fwrite(STDOUT, $s);
        return;
    }
    echo $s;
    @flush();
}

// Entrada CLI. Por web entra por public/import_productos.php.
if (PHP_SAPI === 'cli' && isset($argv) && realpath($argv[0]) === realpath(__FILE__)) {
    $o = getopt('', [
        'file:', 'hoja::', 'tenant-id::', 'dry-run',
        'itbis::', 'unidad::', 'categorias::',
        'db-host::', 'db-port::', 'db-name::', 'db-user::', 'db-pass::',
    ]);
    exit(importarProductosXlsx([
        'file' => $o['file'] ?? '',
        'hoja' => $o['hoja'] ?? '',
        'tenant_id' => $o['tenant-id'] ?? null,
        'dry_run' => isset($o['dry-run']),
        'itbis' => $o['itbis'] ?? '18',
        'unidad' => $o['unidad'] ?? '43',
        'categorias' => $o['categorias'] ?? 'numero',
        'db_host' => $o['db-host'] ?? null,
        'db_port' => $o['db-port'] ?? null,
        'db_name' => $o['db-name'] ?? null,
        'db_user' => $o['db-user'] ?? null,
        'db_pass' => $o['db-pass'] ?? null,
    ]));
}
