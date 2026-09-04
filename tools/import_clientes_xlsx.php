<?php
/**
 * import_clientes_xlsx.php — Carga un catalogo de clientes desde un xlsx.
 *
 * Hermano de import_productos_xlsx.php, para el mismo momento: migrar el
 * catalogo de un sistema anterior al dar de alta un tenant.
 *
 * Columnas que entiende (por nombre de cabecera, sin importar el orden):
 *   NOMBRE / RAZON SOCIAL      -> client_name, company_name y razon_social
 *   RNC / CEDULA               -> rnc  (se queda solo con los digitos)
 *   DOMICILIO / DIRECCION      -> direccion  (se le concatena COLONIA si viene)
 *   CIUDAD                     -> municipio
 *   TELEFONO / TELEFONO2       -> phone_number (el primero que traiga algo)
 *   CORREO ELECTRONICO / EMAIL -> email
 *
 * `email` y `phone_number` son NOT NULL en la tabla y estos catalogos suelen
 * venir sin ninguno de los dos: se guardan como cadena vacia, no como '-' ni
 * inventados, para que se vea que falta el dato y no ensuciar la factura.
 *
 * RNC: DGII solo acepta 9 (empresa) u 11 (cedula) digitos. Los de otro largo se
 * avisan y se guardan igual —el dato es del cliente, no nuestro— pero NO se
 * puede emitir un E31 (Credito Fiscal) contra ellos hasta corregirlos.
 *
 * Conexion (igual que el importador de productos):
 *   --tenant-id=2                             resuelve la DB via el master
 *   --db-host --db-name --db-user --db-pass   conexion directa
 *
 * Correr primero con --dry-run. Re-ejecutable: si un cliente ya existe con el
 * mismo RNC (o el mismo nombre cuando no hay RNC) se salta, no se duplica.
 */

require_once __DIR__ . '/Fase2XlsxReader.php';

function importarClientesXlsx(array $o): int
{
    $archivo = (string) ($o['file'] ?? '');
    if ($archivo === '' || !is_file($archivo)) {
        cliOut("No existe el archivo: {$archivo}\n");
        return 2;
    }
    $dryRun = !empty($o['dry_run']);

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
    cliOut("Hoja: {$hoja} — " . count($filas) . " filas" . ($dryRun ? "  [DRY-RUN]" : "") . "\n\n");

    $mapeados = [];
    $avisos = [];
    $sinNombre = 0;
    $vistos = [];
    foreach ($filas as $i => $f) {
        $nombre = trim((string) cliCol($f, ['NOMBRE', 'RAZON SOCIAL', 'RAZÓN SOCIAL', 'CLIENTE']));
        if ($nombre === '') {
            $sinNombre++;
            continue;
        }

        $rnc = preg_replace('/\D/', '', (string) cliCol($f, ['RNC', 'CEDULA', 'CÉDULA', 'RNC/CEDULA']));
        if ($rnc !== '' && !in_array(strlen($rnc), [9, 11], true)) {
            $avisos[] = "{$nombre}: RNC '{$rnc}' tiene " . strlen($rnc) . " digitos (DGII acepta 9 u 11)";
        }
        // Un nombre que es solo numeros suele ser un telefono metido en el campo
        // equivocado en el sistema viejo. Se importa, pero saldria asi en la factura.
        if (preg_match('/^[\d\-\s()]+$/', $nombre)) {
            $avisos[] = "'{$nombre}': el nombre parece un telefono";
        }

        $clave = $rnc !== '' ? 'r:' . $rnc : 'n:' . mb_strtolower($nombre);
        if (isset($vistos[$clave])) {
            $avisos[] = "{$nombre}: repetido en el archivo (" . ($rnc !== '' ? "RNC {$rnc}" : 'mismo nombre') . '), se ignora';
            continue;
        }
        $vistos[$clave] = true;

        $direccion = trim((string) cliCol($f, ['DOMICILIO', 'DIRECCION', 'DIRECCIÓN']));
        $colonia = trim((string) cliCol($f, ['COLONIA', 'SECTOR']));
        if ($colonia !== '' && stripos($direccion, $colonia) === false) {
            $direccion = trim($direccion . ', ' . $colonia, ' ,');
        }

        $telefono = trim((string) cliCol($f, ['TELEFONO', 'TELÉFONO']));
        if ($telefono === '') {
            $telefono = trim((string) cliCol($f, ['TELEFONO2', 'TELÉFONO2', 'CELULAR']));
        }

        $mapeados[] = [
            'nombre' => mb_substr($nombre, 0, 100),
            'razon_social' => mb_substr($nombre, 0, 150),
            'rnc' => $rnc !== '' ? mb_substr($rnc, 0, 11) : null,
            'direccion' => mb_substr($direccion, 0, 100),
            'municipio' => mb_substr(trim((string) cliCol($f, ['CIUDAD', 'MUNICIPIO'])), 0, 50),
            'telefono' => mb_substr($telefono, 0, 20),
            'email' => mb_substr(trim((string) cliCol($f, ['CORREO ELECTRONICO', 'CORREO', 'EMAIL', 'E-MAIL'])), 0, 100),
        ];
    }

    if ($sinNombre > 0) {
        cliOut("{$sinNombre} fila(s) sin nombre: se saltan\n");
    }
    $conRnc = count(array_filter($mapeados, fn($c) => $c['rnc'] !== null));
    cliOut(count($mapeados) . " cliente(s) listos · con RNC: {$conRnc} · sin RNC: "
        . (count($mapeados) - $conRnc) . "\n");
    cliOut('Con telefono: ' . count(array_filter($mapeados, fn($c) => $c['telefono'] !== ''))
        . ' · con correo: ' . count(array_filter($mapeados, fn($c) => $c['email'] !== ''))
        . ' · con direccion: ' . count(array_filter($mapeados, fn($c) => $c['direccion'] !== '')) . "\n");

    if ($avisos) {
        cliOut("\nAvisos (" . count($avisos) . "):\n");
        foreach (array_slice($avisos, 0, 12) as $a) {
            cliOut("  - {$a}\n");
        }
        if (count($avisos) > 12) {
            cliOut('  … y ' . (count($avisos) - 12) . " mas\n");
        }
    }

    cliOut("\nPrimeras filas tal como quedarian:\n");
    foreach (array_slice($mapeados, 0, 3) as $c) {
        cliOut(sprintf("  %-38s rnc=%-12s tel=%-14s dir=%s\n",
            mb_substr($c['nombre'], 0, 38), $c['rnc'] ?? '-',
            $c['telefono'] ?: '-', $c['direccion'] ?: '-'));
    }

    if ($dryRun) {
        cliOut("\nDRY-RUN: no se escribio nada.\n");
        return 0;
    }

    $pdo = cliConexion($o);
    if ($pdo === null) {
        return 2;
    }

    // Ya existentes: por RNC, y por nombre para los que no lo tienen.
    $porRnc = [];
    $porNombre = [];
    foreach ($pdo->query('SELECT rnc, client_name FROM clients') as $r) {
        if (!empty($r['rnc'])) {
            $porRnc[preg_replace('/\D/', '', $r['rnc'])] = true;
        }
        $porNombre[mb_strtolower(trim((string) $r['client_name']))] = true;
    }

    $st = $pdo->prepare(
        'INSERT INTO clients (email, client_name, company_name, rnc, razon_social,
                              direccion, municipio, provincia, phone_number)
         VALUES (:email, :nombre, :empresa, :rnc, :razon, :dir, :mun, NULL, :tel)'
    );

    $ins = 0;
    $saltados = 0;
    $errores = 0;
    $pdo->beginTransaction();
    try {
        foreach ($mapeados as $c) {
            $dup = $c['rnc'] !== null
                ? isset($porRnc[$c['rnc']])
                : isset($porNombre[mb_strtolower($c['nombre'])]);
            if ($dup) {
                $saltados++;
                continue;
            }
            try {
                $st->execute([
                    ':email' => $c['email'],
                    ':nombre' => $c['nombre'],
                    ':empresa' => $c['nombre'],
                    ':rnc' => $c['rnc'],
                    ':razon' => $c['razon_social'],
                    ':dir' => $c['direccion'] !== '' ? $c['direccion'] : null,
                    ':mun' => $c['municipio'] !== '' ? $c['municipio'] : null,
                    ':tel' => $c['telefono'],
                ]);
                $ins++;
                if ($c['rnc'] !== null) {
                    $porRnc[$c['rnc']] = true;
                }
                $porNombre[mb_strtolower($c['nombre'])] = true;
            } catch (Throwable $e) {
                $errores++;
                cliOut('  ! ' . $c['nombre'] . ': ' . substr($e->getMessage(), 0, 90) . "\n");
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        cliOut("\nRollback: " . $e->getMessage() . "\n");
        return 1;
    }

    cliOut("\n" . str_repeat('=', 56) . "\n");
    cliOut("Insertados: {$ins} · Saltados (ya existian): {$saltados} · Errores: {$errores}\n");
    return $errores > 0 ? 1 : 0;
}

function cliCol(array $fila, array $nombres)
{
    foreach ($nombres as $n) {
        if (array_key_exists($n, $fila)) {
            return $fila[$n];
        }
    }
    return '';
}

function cliConexion(array $o): ?PDO
{
    if (!empty($o['tenant_id'])) {
        require_once __DIR__ . '/../src/MasterDatabase.php';
        require_once __DIR__ . '/../src/TenantResolver.php';
        require_once __DIR__ . '/../src/Database.php';
        if (!TenantResolver::resolveById((int) $o['tenant_id'])) {
            cliOut("No se resolvio el tenant {$o['tenant_id']}.\n");
            return null;
        }
        return Database::getInstance()->getConnection();
    }
    foreach (['db_host', 'db_name', 'db_user'] as $k) {
        if (empty($o[$k])) {
            cliOut("Falta conexion: usa --tenant-id o --db-host/--db-name/--db-user/--db-pass.\n");
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
        cliOut('No se pudo conectar: ' . $e->getMessage() . "\n");
        return null;
    }
}

function cliOut(string $s): void
{
    if (PHP_SAPI === 'cli') {
        fwrite(STDOUT, $s);
        return;
    }
    echo $s;
    @flush();
}

if (PHP_SAPI === 'cli' && isset($argv) && realpath($argv[0]) === realpath(__FILE__)) {
    $o = getopt('', [
        'file:', 'hoja::', 'tenant-id::', 'dry-run',
        'db-host::', 'db-port::', 'db-name::', 'db-user::', 'db-pass::',
    ]);
    exit(importarClientesXlsx([
        'file' => $o['file'] ?? '',
        'hoja' => $o['hoja'] ?? '',
        'tenant_id' => $o['tenant-id'] ?? null,
        'dry_run' => isset($o['dry-run']),
        'db_host' => $o['db-host'] ?? null,
        'db_port' => $o['db-port'] ?? null,
        'db_name' => $o['db-name'] ?? null,
        'db_user' => $o['db-user'] ?? null,
        'db_pass' => $o['db-pass'] ?? null,
    ]));
}
