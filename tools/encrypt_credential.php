<?php
/**
 * encrypt_credential.php — Cifra una credencial (AES-256-GCM) e imprime el blob
 * en hex, listo para pegar en phpMyAdmin.
 *
 * Para hosting compartido SIN acceso a CLI en el servidor: corre este script en
 * tu maquina local y pega el SQL resultante en phpMyAdmin. Las columnas
 * db_pass_encrypted / cert_pass_encrypted son VARBINARY con layout
 * iv(12) || tag(16) || ciphertext, asi que hay que insertarlas con UNHEX(...).
 *
 * IMPORTANTE: usa la MASTER_ENCRYPTION_KEY de PRODUCCION (--key), no la local.
 * Si cifras con la key equivocada, la app fallara al descifrar con
 * "No se pudo descifrar la credencial del tenant (key/tag invalido)".
 *
 * Uso (PowerShell):
 *   php tools/encrypt_credential.php --pass=laClave --key=<64 hex>
 *
 * Generar ademas el UPDATE completo del tenant:
 *   php tools/encrypt_credential.php --pass=laClave --key=<64 hex> `
 *     --tenant-id=1 --db-host=localhost --db-name=midb --db-user=miuser
 *
 * Solo CLI. El script no toca ninguna base de datos: solo imprime texto.
 */

require_once __DIR__ . '/../src/TenantResolver.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo CLI.');
}

$args = parseArgs($argv);

$pass = $args['pass'] ?? null;
if ($pass === null || $pass === true || $pass === '') {
    exit("Falta --pass=<credencial a cifrar>.\n");
}

// Key explicita (produccion) o la del .env local como respaldo.
$key = $args['key'] ?? null;
if ($key === null) {
    loadEnvFile(__DIR__ . '/../.env');
    $key = $_ENV['MASTER_ENCRYPTION_KEY'] ?? '';
    if ($key !== '') {
        fwrite(STDERR, "Aviso: usando MASTER_ENCRYPTION_KEY del .env local. Si el destino es\n"
            . "produccion y su key es distinta, pasa --key=<64 hex> de produccion.\n\n");
    }
}
if (!is_string($key) || strlen($key) !== 64 || !ctype_xdigit($key)) {
    exit("MASTER_ENCRYPTION_KEY invalida: se esperan 64 caracteres hex (32 bytes).\n");
}
putenv("MASTER_ENCRYPTION_KEY={$key}");
$_ENV['MASTER_ENCRYPTION_KEY'] = $key;

$blob = TenantResolver::encrypt($pass);

// Round-trip obligatorio: nunca imprimir un blob que no se pueda descifrar.
if (TenantResolver::decrypt($blob) !== $pass) {
    exit("ERROR: el blob no descifra al valor original. No lo uses.\n");
}

$hex = strtoupper(bin2hex($blob));

echo "Blob AES-256-GCM verificado (descifra al valor original).\n";
echo "Bytes: " . strlen($blob) . "  (iv 12 + tag 16 + ciphertext " . (strlen($blob) - 28) . ")\n\n";
echo "HEX:\n{$hex}\n\n";

$tenantId = $args['tenant-id'] ?? null;
if ($tenantId !== null && $tenantId !== true) {
    $sets = ["db_pass_encrypted = UNHEX('{$hex}')"];
    foreach (['db-host' => 'db_host', 'db-name' => 'db_name', 'db-user' => 'db_user', 'db-port' => 'db_port'] as $flag => $col) {
        if (isset($args[$flag]) && $args[$flag] !== true) {
            $sets[] = "{$col} = " . quoteSql((string) $args[$flag]);
        }
    }
    echo "SQL para phpMyAdmin (base de datos maestra):\n\n";
    echo "UPDATE tenants SET\n  " . implode(",\n  ", $sets) . "\nWHERE id = " . (int) $tenantId . ";\n\n";
    echo "Verifica despues con:\n";
    echo "SELECT id, nombre, db_host, db_name, db_user, LENGTH(db_pass_encrypted) AS pass_bytes\n"
        . "  FROM tenants WHERE id = " . (int) $tenantId . ";\n";
} else {
    echo "Para insertarlo:\n";
    echo "UPDATE tenants SET db_pass_encrypted = UNHEX('{$hex}') WHERE id = <N>;\n";
}

// ---------------------------------------------------------------- helpers

function quoteSql(string $v): string
{
    return "'" . str_replace("'", "''", $v) . "'";
}

/** --clave=valor y --flag desde $argv. */
function parseArgs(array $argv): array
{
    $out = [];
    foreach (array_slice($argv, 1) as $a) {
        if (strpos($a, '--') !== 0) {
            continue;
        }
        $a = substr($a, 2);
        if (strpos($a, '=') !== false) {
            [$k, $v] = explode('=', $a, 2);
            $out[$k] = $v;
        } else {
            $out[$a] = true;
        }
    }
    return $out;
}

function loadEnvFile(string $envFile): void
{
    if (!is_file($envFile)) {
        return;
    }
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);
        if (!array_key_exists($k, $_ENV)) {
            $_ENV[$k] = $v;
            putenv("{$k}={$v}");
        }
    }
}
