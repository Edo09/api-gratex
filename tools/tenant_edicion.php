<?php
/**
 * tenant_edicion.php — Cambia la edicion de una empresa (Fiscalpoint Express): la pasa a Express, la devuelve a completa o refresca el
 * vencimiento de su certificado. Es la unica forma de cambiar master.tenants.edicion: nunca con un UPDATE a mano.
 *
 * Uso (desde la raiz del API; `php tools/tenant_edicion.php --help` lo muestra en pantalla):
 *   php tools/tenant_edicion.php --tenant=ID --edicion=express --tipos=45[,31,32,33] [--modulos=a,b] [--apply]
 *   php tools/tenant_edicion.php --tenant=ID --edicion=completa [--apply]
 *   php tools/tenant_edicion.php --tenant=ID --refrescar-cert [--apply]
 *
 * Sin --apply es un ENSAYO: lee todo, imprime lo que haria y no escribe nada (es lo que se corre primero). Con --apply escribe, y cada cambio de
 * edicion deja una fila en audit_logs (module edicion, action EDICION_CAMBIO) dentro de la misma transaccion de la master que el cambio.
 *
 * Que hace cada orden (todo el trabajo esta en tools/express_edicion_lib.php; esto solo lee la linea de comandos, conecta e imprime):
 *   --edicion=express    el preflight completo (express_convertir): hechos de la master y de la base de la empresa, un veredicto por item ([OK  ] no pide
 *                        nada, [CORR] lo arregla la conversion, [RECH] la frena) y, solo si ninguno es [RECH], el plan: baja de los rangos ecf sin limite y
 *                        de los clientes de prueba sin facturas (solo una empresa nueva), el UPDATE de tenants y el rol user. Un tipo o un modulo mal
 *                        escrito se rechaza: nunca se angosta en silencio.
 *   --edicion=completa   express_revertir: cambia solo la edicion y restaura el hash de integracion si esta en blanco. Levanta TODA restriccion y no es
 *                        una parada segura (anexo 14.5): las palancas son tipos_ecf_habilitados vacio y revocar los tokens.
 *   --refrescar-cert     express_refrescar_cert: cert_vence otra vez igual al notAfter del archivo, para una empresa Express.
 *
 * Informe (stdout; cada linea nueva termina en "\n"): las lineas del paso anterior mas una final: "DRY-RUN: no se escribio nada. Agrega --apply para
 * aplicar.", "RECHAZADO: <n> item(s). No se cambio nada.", "APLICADO: el tenant #<id> ahora es express." (o "... es completa."), o "SIN CAMBIOS: ...".
 * Ninguna linea lleva claves, hashes ni rutas de certificado: los errores salen con la clase, el archivo y el codigo del driver, nunca con el mensaje de
 * una PDOException (trae usuario y servidor).
 *
 * Salida: 0 hecho (o ensayo impreso), 1 error inesperado, 2 uso incorrecto (los argumentos se validan antes de abrir ninguna conexion), 3 el
 * preflight rechazo (no se cambio nada).
 *
 * Solo CLI: el directorio tools/ del hosting se puede abrir desde un navegador, y esta herramienta cambia lo que una empresa puede hacer. La
 * configuracion (MASTER_DB_*, MASTER_ENCRYPTION_KEY) la carga MasterDatabase desde el .env de la raiz; con variables de entorno hay que correrla con
 * -d variables_order=EGPCS (MasterDatabase lee $_ENV).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Solo CLI: esta herramienta cambia la edicion de una empresa.\n");
}

require_once __DIR__ . '/../src/MasterDatabase.php';
require_once __DIR__ . '/../src/TenantResolver.php';
require_once __DIR__ . '/express_edicion_lib.php';

/** El texto de --help, en espanol y solo ASCII (la consola de Windows no siempre muestra bien los acentos). */
function tenant_edicion_ayuda(): string
{
    return <<<'TXT'
tenant_edicion.php - cambia la edicion de una empresa (Fiscalpoint Express).

Uso:
  php tools/tenant_edicion.php --tenant=ID --edicion=express --tipos=45[,31,32,33] [--modulos=a,b] [--apply]
  php tools/tenant_edicion.php --tenant=ID --edicion=completa [--apply]
  php tools/tenant_edicion.php --tenant=ID --refrescar-cert [--apply]

Sin --apply es un ensayo: lee todo, imprime lo que haria y no escribe nada. Con --apply escribe.

Opciones:
  --tenant=ID          el id de la empresa en master.tenants (obligatorio).
  --edicion=express    pasa la empresa a Express. Antes pasa el preflight: si algun item sale [RECH] no se cambia nada.
                       --tipos= son los tipos de venta (31, 32, 45 y, opcional, el 33), separados por coma; el 34 se deriva solo.
                       --modulos= (opcional) angosta los modulos del preset: facturacion, clientes, productos, usuarios, configuracion.
  --edicion=completa   devuelve la empresa a completa y restaura su credencial de integracion si esta en blanco. Levanta TODA restriccion
                       de Express y no es una parada segura: para frenar a una empresa Express deja vacio tipos_ecf_habilitados o
                       revoca los tokens de la empresa.
  --refrescar-cert     recalcula cert_vence desde el certificado de una empresa Express (despues de renovarlo).
  --apply              escribe de verdad. Cada cambio de edicion deja una fila de auditoria (modulo edicion).
  --help               este texto.

Informe: una linea por item del preflight. [OK  ] no pide nada, [CORR] lo arregla la conversion, [RECH] la frena.
Ninguna linea lleva claves, hashes ni rutas de certificado.

Salida: 0 hecho (o ensayo impreso), 1 error inesperado, 2 uso incorrecto, 3 el preflight rechazo (no se cambio nada).

TXT;
}

/**
 * Lee la linea de comandos (los argumentos despues del script) y la valida SIN abrir ninguna conexion. Cada opcion es --nombre=valor (--apply,
 * --refrescar-cert y --help no llevan valor) y se acepta una sola vez; lo que no se reconoce se rechaza en lugar de ignorarse (un --modulo= mal
 * escrito no puede aplicarse sin angostar nada). --help gana a todo lo demas.
 *
 * @param string[] $args
 * @return array{ayuda:true}|array{error:string}|array{modo:string,tenant:int,tipos:list<string>,modulos:?list<string>,apply:bool}
 *         modo es express, completa o refrescar-cert. tipos y modulos son las listas de --tipos y --modulos, con los espacios, vacios y repetidos
 *         fuera (TenantEntitlements::csv) pero sin juzgar los tokens: los rechaza el preflight (RF2). modulos es null si no se dio --modulos.
 */
function tenant_edicion_parsear(array $args): array
{
    if (in_array('--help', $args, true)) {
        return ['ayuda' => true];
    }
    $conValor = ['tenant', 'edicion', 'tipos', 'modulos'];
    $sinValor = ['apply', 'refrescar-cert'];
    $valores = [];
    $banderas = [];
    foreach ($args as $arg) {
        $arg = (string) $arg;
        if (preg_match('/^--([a-z][a-z-]*)(?:=(.*))?$/Ds', $arg, $m) !== 1) {
            return ['error' => 'Argumento no reconocido: ' . express_preflight_nombrar([$arg]) . '. Las opciones se escriben --nombre=valor.'];
        }
        $nombre = $m[1];
        $traeValor = isset($m[2]);
        if (in_array($nombre, $sinValor, true)) {
            if ($traeValor) {
                return ['error' => "--{$nombre} no lleva valor: se escribe solo --{$nombre}."];
            }
            if (isset($banderas[$nombre])) {
                return ['error' => "--{$nombre} esta repetido."];
            }
            $banderas[$nombre] = true;
        } elseif (in_array($nombre, $conValor, true)) {
            if (!$traeValor) {
                return ['error' => "--{$nombre} necesita un valor: --{$nombre}=VALOR."];
            }
            if (isset($valores[$nombre])) {
                return ['error' => "--{$nombre} esta repetido."];
            }
            $valores[$nombre] = $m[2];
        } else {
            return ['error' => 'Opcion desconocida: ' . express_preflight_nombrar([$arg]) . '. Las opciones son --tenant, --edicion, --tipos, --modulos, --refrescar-cert, --apply y --help.'];
        }
    }

    if (!isset($valores['tenant'])) {
        return ['error' => 'Falta --tenant=ID (el id de la empresa en master.tenants).'];
    }
    if (preg_match('/^[0-9]{1,10}$/D', $valores['tenant']) !== 1 || (int) $valores['tenant'] <= 0 || (int) $valores['tenant'] > 2147483647) {
        return ['error' => '--tenant tiene que ser un numero entero positivo (el id de master.tenants), no ' . express_preflight_nombrar([$valores['tenant']]) . '.'];
    }
    $edicion = $valores['edicion'] ?? null;
    $refrescar = isset($banderas['refrescar-cert']);
    if ($edicion !== null && $refrescar) {
        return ['error' => '--refrescar-cert no se combina con --edicion: son dos ordenes distintas.'];
    }
    if ($edicion === null && !$refrescar) {
        return ['error' => 'Falta decir que hacer: --edicion=express, --edicion=completa o --refrescar-cert.'];
    }
    if ($edicion !== null && !in_array($edicion, ['express', 'completa'], true)) {
        return ['error' => '--edicion invalida: ' . express_preflight_nombrar([$edicion]) . '. Usa express o completa (en minusculas).'];
    }

    $modo = $refrescar ? 'refrescar-cert' : (string) $edicion;
    $tipos = [];
    $modulos = null;
    if ($modo === 'express') {
        if (!isset($valores['tipos'])) {
            return ['error' => '--tipos es obligatorio con --edicion=express (por ejemplo --tipos=45 o --tipos=45,31).'];
        }
        $tipos = TenantEntitlements::csv($valores['tipos']);
        if ($tipos === []) {
            return ['error' => '--tipos no trae ningun tipo (por ejemplo --tipos=45 o --tipos=45,31).'];
        }
        $modulos = isset($valores['modulos']) ? TenantEntitlements::csv($valores['modulos']) : null;
    } else {
        foreach (['tipos', 'modulos'] as $opcion) {
            if (isset($valores[$opcion])) {
                return ['error' => "--{$opcion} solo vale con --edicion=express."];
            }
        }
    }
    return ['modo' => $modo, 'tenant' => (int) $valores['tenant'], 'tipos' => $tipos, 'modulos' => $modulos, 'apply' => isset($banderas['apply'])];
}

/**
 * La conexion a la master (MasterDatabase carga el .env de la raiz al crearla). Al fallar, MasterDatabase deja en el log el usuario, el servidor
 * y la base: en la consola eso saldria por stderr. Se manda a un archivo temporal que se borra, y a quien llama le llega solo la RuntimeException con
 * su texto fijo (No se pudo conectar a la base de datos maestra.).
 */
function tenant_edicion_conectar_master(): PDO
{
    $archivo = tempnam(sys_get_temp_dir(), 'tenant_edicion_');
    $previo = ini_set('error_log', (string) $archivo);
    try {
        return MasterDatabase::getInstance()->getConnection();
    } finally {
        ini_set('error_log', $previo === false ? '' : $previo);
        if (is_string($archivo)) {
            @unlink($archivo);
        }
    }
}

/**
 * Una excepcion como la linea de un error inesperado: la clase y archivo:linea, mas, de una PDOException, el SQLSTATE y el codigo del driver (su
 * mensaje trae el usuario y el servidor) y, de las excepciones de la propia herramienta y los errores del motor, su mensaje (no lleva secretos). De
 * cualquier otra solo la clase.
 */
function tenant_edicion_describir(Throwable $e): string
{
    $texto = get_class($e) . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
    if ($e instanceof PDOException) {
        $info = (array) $e->errorInfo;
        return $texto . ($info !== [] ? ' SQLSTATE ' . ($info[0] ?? '?') . ' errno ' . ($info[1] ?? '?') : ' codigo ' . $e->getCode());
    }
    if ($e instanceof InvalidArgumentException || $e instanceof RuntimeException || $e instanceof LogicException || $e instanceof Error) {
        return $texto . ': ' . express_una_linea($e->getMessage());
    }
    return $texto;
}

/** El programa: lee la linea de comandos, corre la orden y imprime su informe. Devuelve el codigo de salida (0, 1, 2 o 3). */
function tenant_edicion_main(array $argv): int
{
    $peticion = tenant_edicion_parsear(array_slice($argv, 1));
    if (isset($peticion['ayuda'])) {
        echo tenant_edicion_ayuda();
        return 0;
    }
    if (isset($peticion['error'])) {
        fwrite(STDERR, 'ERROR: ' . $peticion['error'] . "\nUsa --help para ver el uso.\n");
        return 2;
    }
    try {
        $master = tenant_edicion_conectar_master();
        $resultado = match ($peticion['modo']) {
            'express' => express_convertir($master, $peticion['tenant'], ['edicion' => 'express', 'tipos' => $peticion['tipos'], 'modulos' => $peticion['modulos']], $peticion['apply']),
            'completa' => express_revertir($master, $peticion['tenant'], $peticion['apply']),
            default => express_refrescar_cert($master, $peticion['tenant'], $peticion['apply']),
        };
    } catch (Throwable $e) {
        fwrite(STDERR, '[FALLO] ' . tenant_edicion_describir($e) . "\n");
        if ($peticion['apply']) {
            fwrite(STDERR, "Con --apply, lo que ya se escribio en la base de la empresa se queda (cada paso es idempotente: se puede volver a correr) y lo de la master solo si ya se confirmo. Corre un ensayo para ver como quedo.\n");
        }
        return 1;
    }
    echo implode("\n", $resultado['lineas']), "\n";
    return $resultado['ok'] ? 0 : 3;
}

// Solo cuando se ejecuta este archivo, nunca al incluirlo (las pruebas incluyen el archivo para probar el analizador de argumentos).
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(tenant_edicion_main((array) ($_SERVER['argv'] ?? [])));
}
