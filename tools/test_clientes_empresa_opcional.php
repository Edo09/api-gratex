<?php
/**
 * test_clientes_empresa_opcional.php — "Empresa / razon social" ya no es obligatoria al crear ni al
 * editar un cliente (POST y PUT de /api/clients). Sin base ni red.
 *
 * Antes el controller rechazaba company_name vacio ("Escribe el nombre de la empresa o razon social.")
 * aunque la columna acepta '' (es NOT NULL, sin UNIQUE) y los documentos ya caen al contacto cuando no
 * hay empresa: el comprador del e-CF toma el primero con texto de razon_social, company_name y
 * client_name (facturaController, PosCliente::comprador), igual que los PDF y el reporte de ventas.
 * Correo y telefono ya funcionaban asi: ausentes o vacios se guardan como ''.
 *
 * Que se comprueba:
 *   1. clientController.php REAL, en un proceso hijo, sobre una copia minima del arbol con dobles de lo
 *      que abre una base (clientModel, AuthMiddleware, WelcomeEmailService, AuditLogger). InputSanitizer
 *      es el real, con el origen del cuerpo cambiado (php://input -> un archivo).
 *   2. clientModel REAL con un PDO falso que anota los parametros: que se escribe en company_name y en
 *      razon_social (nunca '' en razon_social: es RazonSocialComprador en el e-CF).
 *   3. fsResolveClientName (factura simple), extraida del controller: una empresa vacia no deja la
 *      factura sin nombre de cliente.
 *   4. CotizacionPdfGenerator: la franja del cliente cae al contacto (se comprueba en el codigo: dibujar
 *      el PDF necesita la base).
 *
 * Uso (desde la raiz del API):  php tools/test_clientes_empresa_opcional.php
 * Sale con 1 si alguna prueba falla. Compatible con php 8.3 (CI y produccion).
 */

require_once __DIR__ . '/write_ahead_test_lib.php';

$RAIZ = dirname(__DIR__);

/** Texto de un archivo del API con los saltos de linea normalizados a LF. */
function cli_fuente(string $ruta): string
{
    return str_replace("\r\n", "\n", (string) file_get_contents($ruta));
}

/** Borra un directorio con todo lo que tenga. */
function cli_borrar(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}

/** El codigo de una funcion de nivel superior (de "function nombre(" a su llave de cierre), o ''. */
function cli_funcion(string $fuente, string $nombre): string
{
    $ini = strpos($fuente, 'function ' . $nombre . '(');
    if ($ini === false) {
        return '';
    }
    $abre = strpos($fuente, '{', $ini);
    if ($abre === false) {
        return '';
    }
    $nivel = 0;
    for ($i = $abre, $n = strlen($fuente); $i < $n; $i++) {
        if ($fuente[$i] === '{') {
            $nivel++;
        } elseif ($fuente[$i] === '}' && --$nivel === 0) {
            return substr($fuente, $ini, $i - $ini + 1);
        }
    }
    return '';
}

// ----------------------------------------------------------------------------------------------
echo "\n== 1. clientController.php real (POST y PUT), con dobles de la base ==\n";
// ----------------------------------------------------------------------------------------------

$arbol = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cli_empresa_' . getmypid() . '_' . mt_rand();
foreach (['src/Controllers', 'src/Models', 'src/Middleware', 'src/Utils'] as $d) {
    mkdir($arbol . '/' . $d, 0777, true);
}
register_shutdown_function(static function () use ($arbol): void {
    cli_borrar($arbol);
});

copy($RAIZ . '/src/Controllers/clientController.php', $arbol . '/src/Controllers/clientController.php');

// InputSanitizer real; solo cambia de donde lee el cuerpo (el hijo no tiene php://input).
$sanitizador = cli_fuente($RAIZ . '/src/Utils/InputSanitizer.php');
$sanitizadorHijo = str_replace("file_get_contents('php://input')", "file_get_contents((string) getenv('CLI_CUERPO'))", $sanitizador);
file_put_contents($arbol . '/src/Utils/InputSanitizer.php', $sanitizadorHijo);
wa_chk('el InputSanitizer del hijo es el real con el origen del cuerpo cambiado', $sanitizadorHijo !== $sanitizador
    && str_contains($sanitizadorHijo, "getenv('CLI_CUERPO')") && str_contains($sanitizadorHijo, 'public static function clean('));

file_put_contents($arbol . '/src/Models/clientModel.php', <<<'PHP'
<?php
class clientModel
{
    public function getClients($id = null)
    {
        global $LLAMADAS;
        $LLAMADAS[] = ['getClients', $id];
        return [['id' => $id, 'client_name' => 'Previo', 'company_name' => 'Previa SRL']];
    }

    public function saveClient(array $d)
    {
        global $LLAMADAS;
        $LLAMADAS[] = ['saveClient', $d];
        return ['success', ['id' => 7] + $d, 7];
    }

    public function updateClient($id, array $d)
    {
        global $LLAMADAS;
        $LLAMADAS[] = ['updateClient', $id, $d];
        return ['success', 'Client updated'];
    }
}
PHP);
file_put_contents($arbol . '/src/Middleware/AuthMiddleware.php', <<<'PHP'
<?php
class AuthMiddleware
{
    public function validateRequest()
    {
        return ['valid' => true, 'user_id' => 1, 'tenant_id' => 1, 'role' => 'admin', 'message' => 'ok'];
    }

    public function sendUnauthorized($message)
    {
        http_response_code(401);
        echo json_encode(['status' => false, 'error' => $message]);
        exit;
    }
}
PHP);
file_put_contents($arbol . '/src/Utils/WelcomeEmailService.php', <<<'PHP'
<?php
function sendClientWelcomeEmail(array $clientData)
{
    global $LLAMADAS;
    $LLAMADAS[] = ['bienvenida', $clientData];
    return ['success' => true, 'message' => 'enviado'];
}
PHP);

$hijo = $arbol . '/hijo.php';
file_put_contents($hijo, str_replace('__ARBOL__', var_export($arbol, true), <<<'PHP'
<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');   // un aviso suelto cae en la salida y rompe el JSON, sea cual sea el php.ini
$LLAMADAS = [];
$_SERVER['REQUEST_METHOD'] = (string) getenv('CLI_METODO');
require_once __ARBOL__ . '/src/Utils/InputSanitizer.php';
class AuditLogger
{
    public static function log(array $evento): void
    {
        global $LLAMADAS;
        $LLAMADAS[] = ['auditoria', $evento['action'] ?? null];
    }
}
ob_start();
register_shutdown_function(static function () {
    global $LLAMADAS;
    $salida = ob_get_level() > 0 ? (string) ob_get_clean() : '';
    echo "\n@@" . json_encode(
        ['salida' => $salida, 'llamadas' => $LLAMADAS],
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
    );
});
require __ARBOL__ . '/src/Controllers/clientController.php';
PHP));

/**
 * Corre el controller con un metodo y un cuerpo (null = peticion sin cuerpo).
 * Devuelve ['json' => lo que contesto (null si no es JSON puro), 'llamadas' => lo que hicieron los dobles, 'crudo'].
 */
$cliCorrer = static function (string $metodo, ?array $cuerpo) use ($arbol, $hijo): array {
    file_put_contents($arbol . '/cuerpo.json', $cuerpo === null ? '' : (string) json_encode($cuerpo, JSON_UNESCAPED_UNICODE));
    $proc = proc_open(
        [PHP_BINARY, $hijo],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $arbol,
        array_merge(getenv(), ['CLI_METODO' => $metodo, 'CLI_CUERPO' => $arbol . '/cuerpo.json'])
    );
    $salida = '';
    $errores = '';
    if (is_resource($proc)) {
        $salida = (string) stream_get_contents($pipes[1]);
        $errores = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
    }
    $partes = explode("\n@@", $salida);
    $final = json_decode((string) array_pop($partes), true);
    $cuerpoSalida = is_array($final) ? (string) ($final['salida'] ?? '') : '';
    return [
        'json' => json_decode($cuerpoSalida, true),
        'llamadas' => is_array($final) ? ($final['llamadas'] ?? []) : [],
        'crudo' => $salida . $errores,
    ];
};
/** Las llamadas de un tipo ('saveClient', 'updateClient', 'bienvenida', 'auditoria'). */
$llamadas = static fn(array $r, string $tipo): array => array_values(array_filter($r['llamadas'], static fn($l) => $l[0] === $tipo));
$ok = static fn(array $r): bool => is_array($r['json']) && ($r['json']['status'] ?? null) === true;
$error = static fn(array $r): ?string => is_array($r['json']) && ($r['json']['status'] ?? null) === false ? (string) ($r['json']['error'] ?? '') : null;

// --- POST: el alta ---
$r = $cliCorrer('POST', ['client_name' => 'Juan Pérez']);
$g = $llamadas($r, 'saveClient');
wa_chk('POST solo con el contacto (sin la clave company_name): se crea y el modelo recibe company_name = "" (la columna es NOT NULL)',
    $ok($r) && count($g) === 1 && array_key_exists('company_name', $g[0][1]) && $g[0][1]['company_name'] === '', $r);

$r = $cliCorrer('POST', ['client_name' => 'Juan Pérez', 'company_name' => '']);
$g = $llamadas($r, 'saveClient');
wa_chk('POST con company_name "" : se crea con company_name ""', $ok($r) && count($g) === 1 && ($g[0][1]['company_name'] ?? null) === '', $r);

$r = $cliCorrer('POST', ['client_name' => 'Juan Pérez', 'company_name' => '   ']);
$g = $llamadas($r, 'saveClient');
wa_chk('POST con company_name solo de espacios: se crea con company_name "" (se recorta, igual que correo y telefono)',
    $ok($r) && count($g) === 1 && ($g[0][1]['company_name'] ?? null) === '', $r);

$r = $cliCorrer('POST', ['client_name' => 'Juan Pérez', 'company_name' => ' Comercial XYZ SRL ']);
$g = $llamadas($r, 'saveClient');
wa_chk('POST con empresa escrita: se crea con la empresa recortada, sin cambiar el texto',
    $ok($r) && count($g) === 1 && ($g[0][1]['company_name'] ?? null) === 'Comercial XYZ SRL', $r);

$r = $cliCorrer('POST', ['client_name' => 'Juan Pérez', 'company_name' => str_repeat('x', 101)]);
wa_chk('POST con empresa de 101 caracteres: sigue rechazado por largo, y no se guarda nada',
    $error($r) !== null && str_contains((string) $error($r), 'máximo 100') && $llamadas($r, 'saveClient') === [], $r);

$r = $cliCorrer('POST', ['client_name' => '', 'company_name' => 'Comercial XYZ SRL']);
wa_chk('POST sin nombre de contacto: sigue rechazado ("Escribe el nombre de contacto.") aunque haya empresa',
    $error($r) === 'Escribe el nombre de contacto.' && $llamadas($r, 'saveClient') === [], $r);

$r = $cliCorrer('POST', ['company_name' => 'Comercial XYZ SRL']);
wa_chk('POST sin la clave client_name: sigue rechazado', $error($r) === 'Escribe el nombre de contacto.' && $llamadas($r, 'saveClient') === [], $r);

$r = $cliCorrer('POST', null);
wa_chk('POST sin cuerpo: rechazado con el mismo texto, sin avisos ni errores de PHP en la salida',
    $error($r) === 'Escribe el nombre de contacto.' && $llamadas($r, 'saveClient') === [], $r);

$r = $cliCorrer('POST', ['client_name' => 'Juan Pérez', 'email' => 'no-es-un-correo']);
wa_chk('POST con correo mal escrito: sigue rechazado (la regla del correo no cambia)', $error($r) !== null && $llamadas($r, 'saveClient') === [], $r);

$r = $cliCorrer('POST', ['client_name' => 'Juan Pérez', 'sent_mail' => true, 'email' => 'juan@correo.com']);
$b = $llamadas($r, 'bienvenida');
wa_chk('POST con sent_mail y sin empresa: el correo de bienvenida recibe company_name "" y la salida es JSON puro (sin "Undefined property")',
    $ok($r) && count($b) === 1 && ($b[0][1]['company_name'] ?? null) === '', $r);

// --- PUT: la edicion ---
$r = $cliCorrer('PUT', ['id' => 5, 'company_name' => '']);
$g = $llamadas($r, 'updateClient');
wa_chk('PUT con company_name "" : se acepta y el modelo recibe company_name "" (borrarla es valido)',
    $ok($r) && count($g) === 1 && array_key_exists('company_name', $g[0][2]) && $g[0][2]['company_name'] === '', $r);

$r = $cliCorrer('PUT', ['id' => 5, 'company_name' => '   ']);
$g = $llamadas($r, 'updateClient');
wa_chk('PUT con company_name solo de espacios: se acepta como ""', $ok($r) && count($g) === 1 && ($g[0][2]['company_name'] ?? null) === '', $r);

$r = $cliCorrer('PUT', ['id' => 5, 'phone_number' => '8095550000']);
$g = $llamadas($r, 'updateClient');
wa_chk('PUT sin la clave company_name: se acepta y la clave no viaja al modelo (el COALESCE conserva la guardada)',
    $ok($r) && count($g) === 1 && !array_key_exists('company_name', $g[0][2]), $r);

$r = $cliCorrer('PUT', ['id' => 5, 'company_name' => ' Nueva SRL ']);
$g = $llamadas($r, 'updateClient');
wa_chk('PUT con empresa escrita: se guarda recortada', $ok($r) && count($g) === 1 && ($g[0][2]['company_name'] ?? null) === 'Nueva SRL', $r);

$r = $cliCorrer('PUT', ['id' => 5, 'company_name' => str_repeat('x', 101)]);
wa_chk('PUT con empresa de 101 caracteres: sigue rechazado por largo, y no se actualiza nada',
    $error($r) !== null && str_contains((string) $error($r), 'máximo 100') && $llamadas($r, 'updateClient') === [], $r);

$r = $cliCorrer('PUT', ['id' => 5, 'client_name' => '   ']);
wa_chk('PUT con contacto vacio: sigue rechazado ("Escribe el nombre de contacto.")',
    $error($r) === 'Escribe el nombre de contacto.' && $llamadas($r, 'updateClient') === [], $r);

// ----------------------------------------------------------------------------------------------
echo "\n== 2. clientModel real con un PDO falso: que se escribe ==\n";
// ----------------------------------------------------------------------------------------------

require_once $RAIZ . '/src/Models/clientModel.php';

final class CliSent extends PDOStatement
{
    public string $sql = '';

    public function execute(?array $params = null): bool
    {
        $GLOBALS['CLI_SQL'][] = [$this->sql, $params];
        return true;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        // Solo la lectura por id devuelve una fila (el cliente existe); el chequeo de duplicados, nada.
        return str_starts_with($this->sql, 'SELECT * FROM clients WHERE id = :id') ? [['id' => 5, 'client_name' => 'Previo']] : [];
    }
}

final class CliPdo extends PDO
{
    public function __construct()
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $s = new CliSent();
        $s->sql = $query;
        return $s;
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return '5';
    }
}

$modelo = (new ReflectionClass('clientModel'))->newInstanceWithoutConstructor();
(new ReflectionProperty('clientModel', 'conexion'))->setValue($modelo, new CliPdo());

/** Los parametros del INSERT o del UPDATE de clients que corrio el modelo. */
$sqlDe = static function (string $verbo): ?array {
    foreach ($GLOBALS['CLI_SQL'] ?? [] as [$sql, $params]) {
        if (stripos(ltrim($sql), $verbo) === 0) {
            return $params;
        }
    }
    return null;
};
$alta = static function (array $d) use ($modelo, $sqlDe): ?array {
    $GLOBALS['CLI_SQL'] = [];
    $modelo->saveClient($d);
    return $sqlDe('INSERT INTO clients');
};
$edicion = static function (array $d) use ($modelo, $sqlDe): ?array {
    $GLOBALS['CLI_SQL'] = [];
    $modelo->updateClient(5, $d);
    return $sqlDe('UPDATE clients');
};

$p = $alta(['client_name' => 'Juan', 'company_name' => '', 'email' => '', 'phone_number' => '']);
wa_chk('alta con empresa "": company_name queda "" (columna NOT NULL)', is_array($p) && ($p[':company_name'] ?? null) === '', $p);
wa_chk('alta con empresa "": razon_social queda NULL, nunca "" (es RazonSocialComprador en el e-CF)',
    is_array($p) && array_key_exists(':razon_social', $p) && $p[':razon_social'] === null, $p);

$p = $alta(['client_name' => 'Juan', 'email' => '', 'phone_number' => '']);
wa_chk('alta sin la clave company_name: se escribe "" y no NULL (NOT NULL)', is_array($p) && ($p[':company_name'] ?? null) === '', $p);

$p = $alta(['client_name' => 'Juan', 'company_name' => ' ACME SRL ', 'email' => '', 'phone_number' => '']);
wa_chk('alta con empresa y sin razon_social: razon_social copia la empresa, recortada (comportamiento de siempre)',
    is_array($p) && ($p[':razon_social'] ?? null) === 'ACME SRL', $p);

$p = $alta(['client_name' => 'Juan', 'company_name' => 'Acme', 'razon_social' => 'ACME SRL', 'email' => '', 'phone_number' => '']);
wa_chk('alta con razon_social propia: esa gana sobre la empresa', is_array($p) && ($p[':razon_social'] ?? null) === 'ACME SRL', $p);

$p = $edicion(['company_name' => '']);
wa_chk('edicion con empresa "": company_name se escribe "" (presente con cadena vacia SI escribe)', is_array($p) && ($p[':company_name'] ?? null) === '', $p);
wa_chk('edicion con empresa "": razon_social va NULL, asi el COALESCE conserva la razon social real del cliente',
    is_array($p) && array_key_exists(':razon_social', $p) && $p[':razon_social'] === null, $p);

$p = $edicion(['phone_number' => '8095550000']);
wa_chk('edicion sin company_name: company_name va NULL y el COALESCE conserva la guardada',
    is_array($p) && array_key_exists(':company_name', $p) && $p[':company_name'] === null, $p);

// ----------------------------------------------------------------------------------------------
echo "\n== 3. fsResolveClientName (factura simple) con una empresa vacia ==\n";
// ----------------------------------------------------------------------------------------------

$fsCodigo = cli_funcion(cli_fuente($RAIZ . '/src/Controllers/facturaSimpleController.php'), 'fsResolveClientName');
wa_chk('fsResolveClientName se puede extraer de facturaSimpleController.php', $fsCodigo !== '');
if ($fsCodigo !== '') {
    eval($fsCodigo);
    $dobleModelo = new class extends clientModel {
        public array $filas = [];

        public function __construct()
        {
        }

        public function getClients($id = null)
        {
            return $this->filas;
        }
    };
    $resolver = static function (array $body, array $filas) use ($dobleModelo): array {
        $dobleModelo->filas = $filas;
        return fsResolveClientName($body, $dobleModelo);
    };

    $b = $resolver(['client_id' => 9], [['client_name' => 'Juan Pérez', 'company_name' => '']]);
    wa_chk('cliente con empresa "" y solo client_id: el nombre de la factura es el del contacto, no ""', ($b['client_name'] ?? null) === 'Juan Pérez', $b);

    $b = $resolver(['client_id' => 9], [['client_name' => 'Juan Pérez', 'company_name' => '   ']]);
    wa_chk('empresa de solo espacios: tambien cae al contacto', ($b['client_name'] ?? null) === 'Juan Pérez', $b);

    $b = $resolver(['client_id' => 9], [['client_name' => 'Juan Pérez', 'company_name' => null]]);
    wa_chk('empresa NULL: cae al contacto', ($b['client_name'] ?? null) === 'Juan Pérez', $b);

    $b = $resolver(['client_id' => 9], [['client_name' => 'Juan Pérez', 'company_name' => 'ACME SRL']]);
    wa_chk('con empresa escrita: sigue ganando la empresa (como siempre)', ($b['client_name'] ?? null) === 'ACME SRL', $b);

    $b = $resolver(['client_id' => 9, 'client_name' => 'Nombre del front'], [['client_name' => 'Juan Pérez', 'company_name' => '']]);
    wa_chk('si la factura ya trae client_name, no se toca', ($b['client_name'] ?? null) === 'Nombre del front', $b);

    $b = $resolver(['client_id' => 9], []);
    wa_chk('cliente que ya no existe: el cuerpo queda como venia (sin client_name inventado)', !array_key_exists('client_name', $b), $b);
}

// ----------------------------------------------------------------------------------------------
echo "\n== 4. PDF de cotizacion: la franja del cliente ==\n";
// ----------------------------------------------------------------------------------------------

$pdfFuente = cli_fuente($RAIZ . '/src/Utils/CotizacionPdfGenerator.php');
wa_chk('la franja del cliente ya no toma company_name tal cual (con "" salia en blanco)',
    $pdfFuente !== '' && !str_contains($pdfFuente, "\$cliente = \$clientRow['company_name'];"));
wa_chk('la franja del cliente cae al contacto (client_name) cuando la empresa esta en blanco',
    preg_match('/\$cliente\s*=\s*trim\(\(string\)\s*\$clientRow\[\'company_name\'\]\)\s*!==\s*\'\'\s*\?\s*\$clientRow\[\'company_name\'\]\s*:\s*\$clientRow\[\'client_name\'\];/', $pdfFuente) === 1);

wa_fin();
