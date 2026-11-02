<?php
/**
 * verificar_express.php — SOLO LECTURA. Revisa las empresas que no son completa y dice cuales estan mal puestas (Fiscalpoint Express, plan 02).
 * No escribe nada: ni en la master, ni en la base de ninguna empresa, ni en la bitacora (todo lo que pide a una base es un SELECT).
 *
 * Uso (desde la raiz del API):
 *   php tools/verificar_express.php
 * No lleva opciones. Se corre despues de convertir una empresa, de restaurar un respaldo de la master o de tocar a mano la tabla tenants: la columna
 * edicion es permisiva (cualquier valor que no sea express rige como completa) y una fila Express mal puesta no falla a la vista, asi que este informe
 * es la alarma. Su contraparte en SQL es la Consulta 4 de tools/verificar_migraciones_master.sql, que se corre en phpMyAdmin y mira menos (no revisa
 * el certificado, los rangos, el grupo ni la credencial de integracion).
 *
 * Que lista: toda empresa de master.tenants cuya edicion no sea completa, una linea por problema,
 *   tenant #<id> (<nombre>): <CODIGO> <detalle>
 * y "Sin problemas." cuando no hay nada que decir (tambien si ninguna empresa es Express). Los codigos:
 *   EDICION_DESCONOCIDA  la edicion no es ni completa ni express (el vacio incluido). La API la trata como completa: la empresa NO tiene restricciones.
 *                        Es lo unico que se dice de una fila asi: los demas codigos solo juzgan filas Express.
 *   TIPOS_VACIOS         tipos_ecf_habilitados NULL, vacio o solo separadores: la empresa no puede emitir nada.
 *   TIPOS_INVALIDOS      un token fuera de 31, 32, 45 y 33, o que no es un numero (e45): no suma nada.
 *   TIPOS_SIN_VENTA      la lista trae el 33 pero ningun tipo de venta.
 *   MODULOS_INVALIDOS    un token de modulos_habilitados que no esta en el preset de config/editions.php: no suma nada.
 *   POS_ACTIVO           pos_enabled en 1.
 *   EN_GRUPO             grupo_id no es NULL: la empresa esta en un grupo, y la credencial de integracion de cualquier empresa del grupo actua por ella (TenantResolver::
 *                        switchToSibling) sin pasar por las rutas, los modulos ni los tipos de Express.
 *   INTEGRACION_ACTIVA   api_secret_hash no esta en blanco: la credencial de integracion de la empresa sigue sirviendo en /api/integracion/*, que no pasa por los limites de Express.
 *                        Estos dos solo se juzgan en una Express de tipo app (una de integracion no esta restringida y su hash es lo que la autentica).
 *   SIN_CERTIFICADO      cert_path vacio.
 *   CERT_SIN_FECHA       hay cert_path pero cert_vence esta vacia o no es una fecha.
 *   CERT_POR_VENCER      cert_vence a 30 dias o menos, o ya pasada.
 *   RANGO_FALTANTE       en ecf, un tipo que la empresa emite (el 34 derivado incluido) sin un rango autorizado, acotado, con capacidad y sin vencer.
 *   RANGO_ILIMITADO      en ecf, un rango sin numero_hasta.
 * Los dos ultimos se revisan solo en una empresa Express de tipo app con ambiente ecf, y leen ncf_sequences de la base de la empresa (con
 * express_conectar_tenant). Si esa base no se alcanza no hay rangos que juzgar: la herramienta dice [FALLO] y no un "Sin problemas." falso.
 * Una edicion desconocida deja ademas UNA linea en el log por valor (TenantEntitlements::normalizarEdicion: se trata como completa y se avisa); con
 * el php de la consola, en stderr.
 *
 * Ninguna linea lleva claves, hashes ni rutas de certificado: el nombre de la empresa sale solo como ASCII imprimible y acotado, y los errores salen
 * con la clase, el archivo y el codigo del driver, nunca con el mensaje de una PDOException (trae usuario y servidor).
 *
 * Salida: 0 sin problemas, 1 hay problemas (o un error inesperado: [FALLO] en stderr), 2 la master no tiene las columnas de la migracion 015.
 *
 * La configuracion (MASTER_DB_*, MASTER_ENCRYPTION_KEY) la carga MasterDatabase desde el .env de la raiz; con variables de entorno hay que correrla
 * con -d variables_order=EGPCS (MasterDatabase lee $_ENV).
 *
 * Se puede incluir (las pruebas lo hacen): al incluirlo solo define funciones, y el programa principal corre unicamente cuando es este archivo el que
 * se ejecuta, en la linea de comandos (un navegador recibe un 403: el directorio tools/ del hosting se puede abrir).
 */

require_once __DIR__ . '/../src/MasterDatabase.php';
require_once __DIR__ . '/express_edicion_lib.php';   // trae TenantEntitlements, TenantResolver (decrypt), express_conectar_tenant y express_conectar_master; no imprime ni conecta

/** A cuantos dias o menos del vencimiento de cert_vence se avisa (CERT_POR_VENCER). El aviso de Configuracion salta a los 60: ese es de pantalla, este es de alarma. */
const EXPRESS_VERIFICAR_CERT_DIAS = 30;

/** Un texto que viene de una base (el nombre de una empresa, escrito a mano) para una linea del informe: solo ASCII imprimible, en una sola linea y de largo acotado. */
function express_verificar_visible(string $texto, int $largo = 60): string
{
    return trim(substr((string) preg_replace('/[^\x20-\x7E]/', '?', $texto), 0, $largo));
}

/** ¿Esta la empresa en el ambiente ecf (produccion)? Como lo lee el preflight: sin mayusculas ni espacios. */
function express_verificar_ambiente_ecf(array $tenant): bool
{
    return strtolower(trim((string) ($tenant['ambiente'] ?? ''))) === 'ecf';
}

/**
 * Lo que esta mal en la fila de una empresa, o [] si no hay nada que decir. Es PURA: no toca una base, un archivo, el log ni la salida, y no cambia lo que
 * recibe. Cada problema es ['codigo' => ..., 'detalle' => ...], uno por codigo (el detalle nombra todo lo que lo causa), en este orden: EDICION_DESCONOCIDA,
 * TIPOS_VACIOS, TIPOS_INVALIDOS, TIPOS_SIN_VENTA, MODULOS_INVALIDOS, POS_ACTIVO, EN_GRUPO, INTEGRACION_ACTIVA, SIN_CERTIFICADO, CERT_SIN_FECHA, CERT_POR_VENCER,
 * RANGO_FALTANTE y RANGO_ILIMITADO. El detalle es una sola linea en ASCII: dice que pasa y que hacer, y nunca lleva la ruta del certificado ni nada secreto de la fila
 * (de api_secret_hash solo se dice que no esta en blanco, nunca su valor).
 *
 * Solo se revisa lo que no es completa. La edicion se lee como la lee la API (TenantEntitlements::normalizarEdicion: sin espacios y en minusculas): una fila
 * sin la columna o con edicion NULL (una master sin la 015) o completa da []; una que no es ni completa ni express da solo EDICION_DESCONOCIDA, porque la API la
 * trata como completa y los demas codigos juzgan una configuracion Express que esa fila no tiene (el aviso del log lo da quien arma el informe, no esta funcion).
 *
 * El certificado se juzga como GET /api/emisor (TenantEntitlements::estadoCertificado): por la fila, sin abrir el archivo. TIPOS_SIN_VENTA es el 33 sin ningun
 * tipo de venta; los tokens que no se reconocen (TIPOS_INVALIDOS, MODULOS_INVALIDOS) no suman nada, como en la API. Los rangos usan el mismo predicado que el
 * preflight (express_preflight_rango_vigente: acotado, con capacidad y sin vencer) para cada tipo efectivo de la empresa, y RANGO_ILIMITADO mira todos los de ecf.
 *
 * @param array  $tenant     la fila de master.tenants
 * @param ?array $rangosEcf  los rangos de ecf de la empresa en la forma de los hechos del preflight (type, numero_hasta, current_value, fecha_vencimiento),
 *                           o null si no se leyeron: sin ellos no se revisa RANGO_FALTANTE ni RANGO_ILIMITADO (que ademas solo van con ambiente ecf)
 * @param int    $hoy        marca de tiempo unix de "ahora": de ahi sale el dia contra el que se cuentan el certificado y los rangos
 * @return list<array{codigo:string,detalle:string}>
 */
function express_problemas_tenant(array $tenant, ?array $rangosEcf, int $hoy): array
{
    if (!array_key_exists('edicion', $tenant) || $tenant['edicion'] === null) {
        return [];
    }
    $edicion = strtolower(trim((string) $tenant['edicion']));
    if ($edicion === 'completa') {
        return [];
    }
    if ($edicion !== 'express') {
        return [[
            'codigo' => 'EDICION_DESCONOCIDA',
            'detalle' => 'edicion ' . express_valor_visible((string) $tenant['edicion']) . ' no es ni completa ni express: la API la trata como completa y la empresa no '
                . 'tiene ninguna restriccion de Express. Corrigela con tools/tenant_edicion.php (--edicion=express con sus tipos, o --edicion=completa).',
        ]];
    }

    $config = TenantEntitlements::config();
    $problemas = [];

    $listados = TenantEntitlements::csv($tenant['tipos_ecf_habilitados'] ?? null);
    if ($listados === []) {
        $problemas[] = [
            'codigo' => 'TIPOS_VACIOS',
            'detalle' => 'tipos_ecf_habilitados esta vacio (NULL, vacio o solo comas): la empresa no puede emitir ningun tipo. Es lo esperado solo si se dejo asi a proposito '
                . 'para frenarla; si no, ponle sus tipos con tools/tenant_edicion.php --edicion=express --tipos=45 (o los que correspondan).',
        ];
    } else {
        $permitidos = express_preflight_tipos_permitidos();
        $invalidos = array_values(array_diff($listados, $permitidos));
        if ($invalidos !== []) {
            $problemas[] = [
                'codigo' => 'TIPOS_INVALIDOS',
                'detalle' => 'tipos_ecf_habilitados trae ' . express_preflight_nombrar($invalidos) . ', que Express no vende: no suman nada. Solo valen '
                    . implode(', ', $permitidos) . ' (el 34 se deriva solo y el 33 es opcional) y se escriben exactos.',
            ];
        }
    }
    if (in_array('33', $listados, true) && TenantEntitlements::tiposVenta($tenant) === []) {
        $problemas[] = [
            'codigo' => 'TIPOS_SIN_VENTA',
            'detalle' => 'tipos_ecf_habilitados trae el 33 pero ningun tipo de venta (' . implode(', ', $config['tipos_venta']) . '): la empresa no puede vender.',
        ];
    }

    $preset = $config['modulos'];
    $fuera = array_values(array_diff(TenantEntitlements::csv($tenant['modulos_habilitados'] ?? null), $preset));
    if ($fuera !== []) {
        $quedan = TenantEntitlements::modulosEfectivos($tenant);
        $problemas[] = [
            'codigo' => 'MODULOS_INVALIDOS',
            'detalle' => 'modulos_habilitados trae ' . express_preflight_nombrar($fuera) . ', que no estan en el preset (' . implode(', ', $preset)
                . '): no suman nada y la empresa queda con ' . ($quedan === [] ? 'ninguno' : implode(', ', $quedan)) . '. Se escriben exactos, sin mayusculas ni sinonimos.',
        ];
    }

    $pos = (int) ($tenant['pos_enabled'] ?? 0);
    if ($pos !== 0) {
        $problemas[] = [
            'codigo' => 'POS_ACTIVO',
            'detalle' => "pos_enabled esta en {$pos}: Express no tiene POS (la API lo trata como inactivo para Express, pero la fila deberia estar en 0).",
        ];
    }

    // Lo que deja pasar por encima de los limites de Express (la lista de rutas, los modulos y los tipos). /api/integracion/* corre antes de la guarda de AuthMiddleware, y una empresa
    // de un grupo se alcanza con la credencial de cualquiera de sus hermanas (TenantResolver::switchToSibling): una fila Express con su hash vivo, o en un grupo, es una puerta abierta
    // para quien tenga esa credencial. Solo se juzga una Express de tipo app: una de integracion (aunque diga express) no esta restringida y su hash es lo que la autentica.
    $id = (int) ($tenant['id'] ?? 0);
    if (($tenant['tipo'] ?? 'app') === 'app') {
        $grupo = $tenant['grupo_id'] ?? null;   // como TenantResolver::grupoId(): solo NULL y el vacio son sin grupo (y una columna ausente, un master sin la 008, tambien)
        if ($grupo !== null && $grupo !== '') {
            $problemas[] = [
                'codigo' => 'EN_GRUPO',
                'detalle' => 'grupo_id vale ' . (int) $grupo . ': la empresa esta en el grupo #' . (int) $grupo . ' y la credencial de integracion de cualquier empresa de ese grupo actua por ella '
                    . '(TenantResolver::switchToSibling) sin pasar por las rutas, los modulos ni los tipos de Express. Una empresa Express no puede estar en un grupo: sacala del grupo '
                    . "(grupo_id en NULL, con el cliente de acuerdo) o devuelvela a completa con tools/tenant_edicion.php --tenant={$id} --edicion=completa.",
            ];
        }
        if ((string) ($tenant['api_secret_hash'] ?? '') !== '') {
            $problemas[] = [
                'codigo' => 'INTEGRACION_ACTIVA',
                'detalle' => 'api_secret_hash no esta en blanco: la credencial de integracion de la empresa sigue sirviendo en /api/integracion/*, una ruta que no pasa por la lista de rutas, los modulos '
                    . 'ni los tipos de Express (convertir la deja en blanco). Corre tools/tenant_edicion.php --tenant=' . $id . ' --edicion=express con los mismos --tipos (y --modulos) de la empresa '
                    . 'y --apply: la deja en blanco y guarda el hash anterior en la bitacora.',
            ];
        }
    }

    $certificado = TenantEntitlements::estadoCertificado($tenant, $hoy);
    if ($certificado['estado'] === 'no_configurado') {
        $problemas[] = [
            'codigo' => 'SIN_CERTIFICADO',
            'detalle' => 'cert_path esta vacio: la empresa no tiene certificado propio y la emision no usa el del .env. Cargalo con tools/set_tenant_cert.php.',
        ];
    } elseif ($certificado['estado'] === 'sin_dato') {
        $problemas[] = [
            'codigo' => 'CERT_SIN_FECHA',
            'detalle' => "hay certificado (cert_path) pero cert_vence esta vacio o no es una fecha: no se sabe cuando vence. Calculala con tools/tenant_edicion.php --tenant={$id} --refrescar-cert [--apply].",
        ];
    } elseif ((int) $certificado['dias'] <= EXPRESS_VERIFICAR_CERT_DIAS) {
        $dias = (int) $certificado['dias'];
        $plural = static fn (int $n): string => $n . ($n === 1 ? ' dia' : ' dias');
        $cuando = $dias < 0
            ? 'vencio el ' . $certificado['vence'] . ' (hace ' . $plural(-$dias) . ')'
            : ($dias === 0 ? 'vence hoy (' . $certificado['vence'] . ')' : 'vence el ' . $certificado['vence'] . ' (faltan ' . $plural($dias) . ')');
        $problemas[] = [
            'codigo' => 'CERT_POR_VENCER',
            'detalle' => "el certificado {$cuando}: se avisa a " . EXPRESS_VERIFICAR_CERT_DIAS . " dias o menos. Renuevalo con tools/set_tenant_cert.php y refresca la fecha con "
                . "tools/tenant_edicion.php --tenant={$id} --refrescar-cert [--apply]; si ya lo renovaste, solo falta refrescarla.",
        ];
    }

    if ($rangosEcf !== null && express_verificar_ambiente_ecf($tenant)) {
        $fecha = date('Y-m-d', $hoy);
        $faltan = [];
        foreach (TenantEntitlements::tiposEfectivos($tenant) as $tipo) {
            if (!express_preflight_rango_vigente($rangosEcf, 'E' . $tipo, $fecha)) {
                $faltan[] = 'E' . $tipo;
            }
        }
        if ($faltan !== []) {
            $problemas[] = [
                'codigo' => 'RANGO_FALTANTE',
                'detalle' => 'faltan rangos autorizados por la DGII en ecf (acotados, con capacidad y sin vencer) para ' . implode(', ', $faltan) . '. Registralos con POST /api/ncf/rangos.',
            ];
        }
        $ilimitados = array_values(array_filter($rangosEcf, static fn ($rango): bool => ($rango['numero_hasta'] ?? null) === null));
        if ($ilimitados !== []) {
            $tiposIlimitados = array_values(array_unique(array_map(static fn ($rango): string => (string) ($rango['type'] ?? '?'), $ilimitados)));
            $problemas[] = [
                'codigo' => 'RANGO_ILIMITADO',
                'detalle' => count($ilimitados) . ' rango(s) de ecf sin limite (numero_hasta vacio) en ' . express_preflight_nombrar($tiposIlimitados) . ': la DGII no autoriza un rango sin limite.',
            ];
        }
    }
    return $problemas;
}

/**
 * Los rangos de ecf de la base de una empresa, en la forma de los hechos del preflight (express_preflight_hechos, rangos_ecf): type, numero_hasta (entero o
 * null), current_value y fecha_vencimiento (texto o null). Un SELECT, y solo de ecf: lo de certecf y testecf no cuenta.
 *
 * @return list<array{type:string,numero_hasta:?int,current_value:int,fecha_vencimiento:?string}>
 */
function express_verificar_rangos_ecf(PDO $tenantDb): array
{
    $rangos = [];
    foreach (express_preflight_filas(
        $tenantDb,
        "SELECT type, numero_hasta, current_value, fecha_vencimiento FROM ncf_sequences WHERE ambiente = 'ecf' ORDER BY type, numero_desde"
    ) as $rango) {
        $rangos[] = [
            'type' => (string) $rango['type'],
            'numero_hasta' => $rango['numero_hasta'] === null ? null : (int) $rango['numero_hasta'],
            'current_value' => (int) $rango['current_value'],
            'fecha_vencimiento' => $rango['fecha_vencimiento'] === null ? null : (string) $rango['fecha_vencimiento'],
        ];
    }
    return $rangos;
}

/** La linea del informe de un problema de una empresa: "tenant #<id> (<nombre>): <CODIGO> <detalle>". */
function express_verificar_linea(array $tenant, array $problema): string
{
    return 'tenant #' . (int) ($tenant['id'] ?? 0) . ' (' . express_verificar_visible((string) ($tenant['nombre'] ?? '')) . '): '
        . $problema['codigo'] . ' ' . express_una_linea($problema['detalle']);
}

/**
 * El informe: lee de la master las empresas cuya edicion no es completa (un SELECT) y arma una linea por problema. Solo lee. Sin las cuatro columnas de la
 * migracion master 015 no hay edicion que revisar: sale con 2 y una sola linea que nombra la migracion y lo que falta, sin leer tenants. Una empresa Express de
 * tipo app en ecf abre ademas su base (express_conectar_tenant) para leer sus rangos; una de las demas no. Si esa base no se alcanza lanza, y quien llama
 * imprime el [FALLO]: cuando no hay rangos que juzgar, decir "Sin problemas." seria peor que decir que no se pudo.
 *
 * Una edicion que no es ni completa ni express se pasa por TenantEntitlements::normalizarEdicion, que la trata como completa y avisa en el log una vez por valor.
 *
 * @param int $hoy marca de tiempo unix de "ahora" (la de express_problemas_tenant)
 * @return array{codigo:int,lineas:list<string>} codigo 0 (lineas es Sin problemas.), 1 (una linea por problema) o 2 (faltan columnas de la 015)
 */
function express_verificar(PDO $master, int $hoy): array
{
    $columnas = array_map('strval', array_column(express_preflight_filas(
        $master,
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenants'"
    ), 'COLUMN_NAME'));
    $faltan = array_values(array_diff(EXPRESS_COLUMNAS_VERIFICADAS, $columnas));
    if ($faltan !== []) {
        return ['codigo' => 2, 'lineas' => [
            'La master no tiene las columnas de la migracion 015 (db/master_migrations/015_tenants_edicion.sql). Faltan en tenants: ' . implode(', ', $faltan)
            . '. Aplicala en la master (tools/verificar_migraciones_master.sql dice si ya esta) y vuelve a correr esto.',
        ]];
    }

    $lineas = [];
    foreach (express_preflight_filas($master, "SELECT * FROM tenants WHERE edicion <> 'completa' ORDER BY id") as $fila) {
        $edicion = TenantEntitlements::normalizarEdicion($fila);   // 'express' o 'completa'; un valor desconocido se trata como completa y deja su linea en el log
        $rangos = null;
        if ($edicion === 'express' && ($fila['tipo'] ?? 'app') === 'app' && express_verificar_ambiente_ecf($fila)) {
            $rangos = express_verificar_rangos_ecf(express_conectar_tenant($fila));
        }
        foreach (express_problemas_tenant($fila, $rangos, $hoy) as $problema) {
            $lineas[] = express_verificar_linea($fila, $problema);
        }
    }
    return $lineas === [] ? ['codigo' => 0, 'lineas' => ['Sin problemas.']] : ['codigo' => 1, 'lineas' => $lineas];
}

/** El programa: conecta, arma el informe y lo imprime. Devuelve el codigo de salida (0, 1 o 2). */
function express_verificar_main(): int
{
    try {
        // La conexion es la de la biblioteca: lo que MasterDatabase deja en el log al fallar (usuario y servidor) no sale.
        $informe = express_verificar(express_conectar_master('verificar_express_'), time());
    } catch (Throwable $e) {
        fwrite(STDERR, '[FALLO] ' . express_describir_fallo($e) . "\n");
        return 1;
    }
    if ($informe['codigo'] === 2) {
        fwrite(STDERR, 'ERROR: ' . implode("\n", $informe['lineas']) . "\n");
    } else {
        echo implode("\n", $informe['lineas']), "\n";
    }
    return $informe['codigo'];
}

// Solo cuando se ejecuta este archivo, nunca al incluirlo (las pruebas incluyen el archivo para probar express_problemas_tenant y el informe).
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(403);
        exit("Solo CLI: esta herramienta lista datos de las empresas.\n");
    }
    exit(express_verificar_main());
}
