<?php
/**
 * express_edicion_lib.php — el preflight de la edicion Express (Fiscalpoint Express, plan 02).
 *
 * Convertir una empresa a Express (master.tenants.edicion = 'express') no se hace a ciegas. tools/tenant_edicion.php (una empresa que ya
 * existe) y tools/create_tenant.php (la que acaba de crear) comparten estos pasos, que son lo unico que decide si se convierte:
 *   1. hechos       express_preflight_hechos lee de la master y de la base de la empresa lo que hace falta. Solo SELECT.
 *   2. evaluacion   express_preflight_evaluar da a cada item ok, corregir (el plan lo arregla) o rechazar (no se cambia nada).
 *   3. plan         express_plan arma las sentencias que convierten la empresa, en el orden en que se aplican: [] si algo se rechaza.
 * Aplicar el plan, auditar y revertir no estan aqui: esta biblioteca no escribe en ninguna base.
 *
 * Reglas del archivo:
 *   - Se puede incluir desde donde sea: al incluirlo no imprime nada, no abre ninguna conexion y no cambia ninguna configuracion.
 *   - Evaluar y armar el plan son PURAS: reciben arreglos y devuelven arreglos; no tocan una base, ni el log, ni lo que reciben.
 *   - Nada de lo que devuelve la biblioteca se imprime con la fila de la empresa dentro: $hechos['tenant'] es la fila COMPLETA de
 *     master.tenants (con la clave de su base, la del certificado y el hash de integracion). La evaluacion y el plan no copian nada de ella:
 *     ni la ruta del certificado, ni ninguna clave, ni el hash, ni el servidor o el usuario de su base. Quien imprima, imprime de ahi.
 *   - Solo cuentan las filas de ecf (produccion). Lo que la certificacion dejo en certecf (facturas, contadores y los clientes de prueba
 *     de la DGII usados en ellas) nunca bloquea una conversion (diseno 7, decision D4); testecf tampoco.
 *   - El certificado se lee como lo hara la emision: del cert_path de la empresa, sin respaldo con el certificado global del .env.
 *     El plan 03 agrega TenantEntitlements::certificadoReal con el mismo resultado y una prueba de paridad con express_leer_certificado:
 *     ninguna de las dos llama a la otra, asi que si algun dia se separan, lo dice esa prueba.
 */

require_once __DIR__ . '/../src/TenantEntitlements.php';   // trae TenantResolver (decrypt) y config/editions.php; no imprime ni conecta

/** El almacen por defecto que siembra tenant_schema.sql (warehouseModel::DEFAULT_NOMBRE): crear un producto falla sin el. */
const EXPRESS_ALMACEN_DEFECTO = 'Almacén Principal';

/** RNC de los dos clientes de prueba de la DGII que siembra tenant_schema.sql para el set de certificacion. */
const EXPRESS_RNC_CLIENTES_PRUEBA = ['131880681', '533445861'];

/** Lo que deja sin llenar un emisor_config recien creado: la razon social de tenant_schema.sql y la direccion de ahi y de create_tenant.php. */
const EXPRESS_RAZON_PENDIENTE = 'PENDIENTE ONBOARDING';
const EXPRESS_DIRECCIONES_PENDIENTES = ['', 'pendiente', 'n/d'];

/**
 * Conexion PDO a la base de una empresa tipo app, con la clave que su fila trae cifrada (TenantResolver::decrypt) y la misma forma de
 * conectar que Database: DSN host:puerto, excepciones y FETCH_ASSOC. $tenant es la fila de master.tenants.
 *
 * Lanza InvalidArgumentException si la empresa no es de tipo app o la fila no trae db_host, db_name y db_user, y RuntimeException si
 * no se descifra la clave o no se conecta. Ningun texto de error lleva el servidor, el usuario, la base ni la clave: solo el id de la
 * empresa y el codigo del driver (el mensaje del driver trae usuario y servidor). Tampoco imprime los avisos de mysqlnd al fallar.
 */
function express_conectar_tenant(array $tenant): PDO
{
    $id = (int) ($tenant['id'] ?? 0);
    if (($tenant['tipo'] ?? 'app') !== 'app') {
        throw new InvalidArgumentException("El tenant #{$id} no es de tipo app: no tiene base de datos propia.");
    }
    $host = trim((string) ($tenant['db_host'] ?? ''));
    $puerto = trim((string) ($tenant['db_port'] ?? ''));
    $base = trim((string) ($tenant['db_name'] ?? ''));
    $usuario = (string) ($tenant['db_user'] ?? '');
    if ($host === '' || $base === '' || $usuario === '') {
        throw new InvalidArgumentException("La fila del tenant #{$id} no trae db_host, db_name y db_user.");
    }
    $clave = TenantResolver::decrypt((string) ($tenant['db_pass_encrypted'] ?? ''));
    $puerto = $puerto === '' ? '3306' : $puerto;

    set_error_handler(static fn (): bool => true);   // al fallar, mysqlnd avisa con el servidor y el usuario: se calla mientras conecta
    try {
        return new PDO("mysql:host={$host}:{$puerto};dbname={$base};charset=utf8mb4", $usuario, $clave, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        $info = (array) $e->errorInfo;
        throw new RuntimeException("No se pudo conectar a la base de datos del tenant #{$id} (codigo " . ($info[1] ?? $e->getCode()) . ').');
    } finally {
        restore_error_handler();
    }
}

/**
 * La ruta de un certificado como la resuelve CertResolver::toAbsolute (privada): absoluta (posix o con unidad de Windows) tal cual y
 * relativa a la raiz del proyecto, con el separador del sistema.
 */
function express_ruta_absoluta(string $ruta): string
{
    if (preg_match('/^[A-Za-z]:[\\\\\/]/', $ruta) === 1 || str_starts_with($ruta, '/')) {
        return $ruta;
    }
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $ruta);
}

/**
 * ¿Tiene la empresa un certificado propio que la emision pueda usar? cert_path no vacio (absoluto o relativo a la raiz del proyecto), un
 * archivo legible, cert_pass_encrypted no vacio que se descifra, un .p12 que openssl_pkcs12_read abre con esa clave y un notAfter posterior a
 * $ahora (marca de tiempo; por omision, la hora actual). Sin respaldo con el certificado del .env, no imprime nada y no lanza.
 *
 * @return array{ok:bool,codigo:?string,vence:?int} codigo es null si ok; 'CERT_FALTA' si no hay certificado que abrir (ruta vacia, archivo
 *         que no esta o no se lee, clave que falta o no descifra o que no abre el .p12; vence null) y 'CERT_VENCIDO' si abre pero ya vencio
 *         (vence es su notAfter). Nunca devuelve la ruta ni la clave.
 */
function express_leer_certificado(array $tenant, ?int $ahora = null): array
{
    $ahora ??= time();
    $falta = ['ok' => false, 'codigo' => 'CERT_FALTA', 'vence' => null];
    $ruta = trim((string) ($tenant['cert_path'] ?? ''));
    $claveCifrada = (string) ($tenant['cert_pass_encrypted'] ?? '');
    if ($ruta === '' || $claveCifrada === '') {
        return $falta;
    }
    $archivo = express_ruta_absoluta($ruta);
    if (!is_file($archivo) || !is_readable($archivo)) {
        return $falta;
    }
    $contenido = @file_get_contents($archivo);
    if ($contenido === false || $contenido === '') {
        return $falta;
    }

    $datos = null;
    try {
        $clave = TenantResolver::decrypt($claveCifrada);
        $partes = [];
        if (@openssl_pkcs12_read($contenido, $partes, $clave)) {
            $datos = @openssl_x509_parse((string) ($partes['cert'] ?? ''));
        }
        unset($partes, $clave);   // la llave privada y la clave no se quedan en memoria mas de lo que dura la lectura
    } catch (Throwable $e) {
        $datos = null;   // sin openssl, o una clave que no se descifra, es lo mismo que no poder abrirlo
    }
    // Un intento fallido deja mensajes en la cola de errores de openssl: se vacia para que no los lea otro codigo despues.
    if (function_exists('openssl_error_string')) {
        while (openssl_error_string() !== false) {
        }
    }

    $vence = is_array($datos) ? ($datos['validTo_time_t'] ?? null) : null;
    if (!is_int($vence)) {
        return $falta;
    }
    return $vence > $ahora
        ? ['ok' => true, 'codigo' => null, 'vence' => $vence]
        : ['ok' => false, 'codigo' => 'CERT_VENCIDO', 'vence' => $vence];
}

/** Las filas de un SELECT como arreglos asociativos. Solo para consultas de lectura: es la unica puerta de la biblioteca a una base. */
function express_preflight_filas(PDO $pdo, string $sql, array $params = []): array
{
    $sentencia = $pdo->prepare($sql);
    $sentencia->execute($params);
    return $sentencia->fetchAll(PDO::FETCH_ASSOC);
}

/** El entero n de un SELECT COUNT(...) AS n. */
function express_preflight_contar(PDO $pdo, string $sql, array $params = []): int
{
    return (int) (express_preflight_filas($pdo, $sql, $params)[0]['n'] ?? 0);
}

/**
 * Los permisos del rol de sistema user de la empresa en la master (en orden alfabetico), o null si la empresa no tiene ese rol.
 *
 * @return ?string[]
 */
function express_preflight_rol_user(PDO $master, int $tenantId): ?array
{
    $rol = express_preflight_filas($master, "SELECT id FROM roles WHERE tenant_id = :t AND name = 'user' LIMIT 1", [':t' => $tenantId])[0] ?? null;
    if ($rol === null) {
        return null;
    }
    $filas = express_preflight_filas($master, 'SELECT permission FROM role_permissions WHERE role_id = :r ORDER BY permission', [':r' => (int) $rol['id']]);
    return array_map('strval', array_column($filas, 'permission'));
}

/**
 * Lee los hechos de una empresa que la evaluacion necesita. Todo lo que pide a las bases es SELECT, y solo de filas ecf: lo de certecf
 * y testecf (D4) no se lee.
 *
 * @param PDO  $master   conexion a la master (donde viven tenants, roles y role_permissions)
 * @param ?PDO $tenantDb conexion a la base de la empresa (express_conectar_tenant); null si no la hay (empresa de integracion o sin
 *                       base): los hechos de esa base quedan en su peor caso (m036 y almacen false, emisor null, sin rangos, sin
 *                       clientes de prueba) y ecf_facturas en null, que nunca es "nueva"
 * @param array $tenant  la fila de master.tenants (SELECT *), tal cual
 * @param array $pedido  ['edicion' => 'express', 'tipos' => list<string>, 'modulos' => ?list<string>]
 * @return array{
 *     tenant:array, columnas_014:bool, m036:bool, cert:array{ok:bool,codigo:?string,vence:?int},
 *     emisor:?array{rnc:string,razon_social:string,direccion:string,telefono:string}, almacen:bool, pedido:array,
 *     rangos_ecf:list<array{type:string,numero_hasta:?int,current_value:int,fecha_vencimiento:?string}>, ecf_facturas:?int,
 *     clientes_prueba:array{ids:list<int>,refs_ecf:int,refs_total:int}, rol_user:?list<string>, hoy:string
 * } columnas_014: las cuatro columnas de la migracion master 014 existen; m036: facturas.idempotency_key existe en la base de la empresa;
 *   cert: express_leer_certificado de la fila; emisor: la fila 1 de emisor_config (NULL como ''), o null si no hay; almacen: existe
 *   "Almacén Principal"; rangos_ecf: los rangos de ncf_sequences con ambiente ecf; ecf_facturas: cuantas facturas tienen ambiente_dgii ecf;
 *   clientes_prueba: los ids de los clientes de la DGII (RNC 131880681 y 533445861) y CUANTOS de ellos tienen una factura ecf (refs_ecf) o una
 *   de cualquier ambiente (refs_total); rol_user: express_preflight_rol_user; hoy: la fecha de hoy, Y-m-d, en la zona de PHP.
 */
function express_preflight_hechos(PDO $master, ?PDO $tenantDb, array $tenant, array $pedido): array
{
    $hechos = [
        'tenant' => $tenant,
        'columnas_014' => express_preflight_contar(
            $master,
            "SELECT COUNT(*) AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenants'
             AND COLUMN_NAME IN ('edicion', 'modulos_habilitados', 'tipos_ecf_habilitados', 'cert_vence')"
        ) === 4,
        'm036' => false,
        'cert' => express_leer_certificado($tenant),
        'emisor' => null,
        'almacen' => false,
        'pedido' => $pedido,
        'rangos_ecf' => [],
        'ecf_facturas' => null,
        'clientes_prueba' => ['ids' => [], 'refs_ecf' => 0, 'refs_total' => 0],
        'rol_user' => express_preflight_rol_user($master, (int) ($tenant['id'] ?? 0)),
        'hoy' => date('Y-m-d'),
    ];
    if ($tenantDb === null) {
        return $hechos;
    }

    $hechos['m036'] = express_preflight_contar(
        $tenantDb,
        "SELECT COUNT(*) AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'facturas' AND COLUMN_NAME = 'idempotency_key'"
    ) > 0;

    $emisor = express_preflight_filas($tenantDb, 'SELECT rnc, razon_social, direccion, telefono FROM emisor_config WHERE id = 1 LIMIT 1')[0] ?? null;
    if ($emisor !== null) {
        $hechos['emisor'] = [
            'rnc' => (string) ($emisor['rnc'] ?? ''),
            'razon_social' => (string) ($emisor['razon_social'] ?? ''),
            'direccion' => (string) ($emisor['direccion'] ?? ''),
            'telefono' => (string) ($emisor['telefono'] ?? ''),
        ];
    }

    $hechos['almacen'] = express_preflight_filas($tenantDb, 'SELECT id FROM warehouses WHERE nombre = :n LIMIT 1', [':n' => EXPRESS_ALMACEN_DEFECTO]) !== [];

    foreach (express_preflight_filas(
        $tenantDb,
        "SELECT type, numero_hasta, current_value, fecha_vencimiento FROM ncf_sequences WHERE ambiente = 'ecf' ORDER BY type, numero_desde"
    ) as $rango) {
        $hechos['rangos_ecf'][] = [
            'type' => (string) $rango['type'],
            'numero_hasta' => $rango['numero_hasta'] === null ? null : (int) $rango['numero_hasta'],
            'current_value' => (int) $rango['current_value'],
            'fecha_vencimiento' => $rango['fecha_vencimiento'] === null ? null : (string) $rango['fecha_vencimiento'],
        ];
    }
    $hechos['ecf_facturas'] = express_preflight_contar($tenantDb, "SELECT COUNT(*) AS n FROM facturas WHERE ambiente_dgii = 'ecf'");

    $enRnc = implode(', ', array_fill(0, count(EXPRESS_RNC_CLIENTES_PRUEBA), '?'));
    $ids = array_map('intval', array_column(
        express_preflight_filas($tenantDb, "SELECT id FROM clients WHERE rnc IN ({$enRnc}) ORDER BY id", EXPRESS_RNC_CLIENTES_PRUEBA),
        'id'
    ));
    $hechos['clientes_prueba']['ids'] = $ids;
    if ($ids !== []) {
        $enIds = implode(', ', array_fill(0, count($ids), '?'));
        $hechos['clientes_prueba']['refs_ecf'] = express_preflight_contar(
            $tenantDb,
            "SELECT COUNT(DISTINCT client_id) AS n FROM facturas WHERE client_id IN ({$enIds}) AND ambiente_dgii = 'ecf'",
            $ids
        );
        $hechos['clientes_prueba']['refs_total'] = express_preflight_contar(
            $tenantDb,
            "SELECT COUNT(DISTINCT client_id) AS n FROM facturas WHERE client_id IN ({$enIds})",
            $ids
        );
    }
    return $hechos;
}

/** Los tipos que se pueden pedir con --tipos: los de venta de la edicion (31, 32, 45) y el 33 (nota de debito), que es opcional. */
function express_preflight_tipos_permitidos(): array
{
    return array_merge(TenantEntitlements::config()['tipos_venta'], ['33']);
}

/** Tokens para un detalle: entre comillas, solo ASCII imprimible y de largo acotado (los escribe un operador a mano). */
function express_preflight_nombrar(array $tokens): string
{
    return implode(', ', array_map(
        static fn ($token): string => "'" . substr((string) preg_replace('/[^\x20-\x7E]/', '?', (string) $token), 0, 24) . "'",
        $tokens
    ));
}

/**
 * ¿Hay en $rangos (las filas ecf de los hechos) un rango autorizado que sirva para emitir $tipo ('E31')? Con numero_hasta (uno sin limite
 * no es un rango autorizado), con capacidad (current_value menor que numero_hasta) y sin vencer (fecha_vencimiento vacia o no anterior a
 * $hoy): lo mismo que dispensa y cubre ncfModel::rangoCubre.
 */
function express_preflight_rango_vigente(array $rangos, string $tipo, string $hoy): bool
{
    foreach ($rangos as $rango) {
        $hasta = $rango['numero_hasta'] ?? null;
        $vence = trim((string) ($rango['fecha_vencimiento'] ?? ''));
        if (strtoupper((string) ($rango['type'] ?? '')) === $tipo && $hasta !== null && $hasta !== ''
            && (int) ($rango['current_value'] ?? 0) < (int) $hasta && ($vence === '' || substr($vence, 0, 10) >= $hoy)) {
            return true;
        }
    }
    return false;
}

/**
 * Evalua los hechos item por item. Es pura. Todos los items se evaluan siempre, sin cortar en el primer rechazo, y salen en este orden:
 * tenant, columnas, m036, tipos, modulos, clientes_modulo, cert, emisor, almacen, pos, inventario, rangos, ilimitados, clientes_prueba,
 * integracion, roles. Cada uno es ['estado' => 'ok'|'corregir'|'rechazar', 'detalle' => texto]: ok no pide nada, corregir lo arregla el
 * plan (express_plan) y rechazar frena todo.
 *
 * nuevo es true si la empresa no ha emitido en ecf: ecf_facturas es 0 y ningun rango ecf tiene current_value mayor que 0. Solo una empresa
 * nueva permite corregir (borrar o apagar) lo que ya hay; una que ya emitio lo rechaza. Con ecf_facturas en null (la base de la empresa no
 * se pudo leer) nunca es nueva.
 *
 * rechazos son los ids de los items rechazar y acciones los de los corregir, cada lista en el orden de los items. Ningun detalle lleva
 * la ruta del certificado, una clave ni el hash de integracion.
 *
 * @param array $hechos lo que devuelve express_preflight_hechos (una clave que falte cuenta como el peor caso)
 * @return array{items:array<string,array{estado:string,detalle:string}>,nuevo:bool,rechazos:list<string>,acciones:list<string>}
 */
function express_preflight_evaluar(array $hechos): array
{
    $config = TenantEntitlements::config();
    $tenant = (array) ($hechos['tenant'] ?? []);
    $pedido = (array) ($hechos['pedido'] ?? []);
    $tipos = array_map('strval', array_values((array) ($pedido['tipos'] ?? [])));
    $modulos = ($pedido['modulos'] ?? null) === null ? null : array_map('strval', array_values((array) $pedido['modulos']));
    $rangos = array_values((array) ($hechos['rangos_ecf'] ?? []));
    $hoy = (string) ($hechos['hoy'] ?? date('Y-m-d'));
    $ambiente = strtolower(trim((string) ($tenant['ambiente'] ?? '')));
    $nuevo = ($hechos['ecf_facturas'] ?? null) === 0
        && array_filter($rangos, static fn ($rango): bool => (int) ($rango['current_value'] ?? 0) > 0) === [];

    $ok = static fn (string $detalle): array => ['estado' => 'ok', 'detalle' => $detalle];
    $corregir = static fn (string $detalle): array => ['estado' => 'corregir', 'detalle' => $detalle];
    $rechazar = static fn (string $detalle): array => ['estado' => 'rechazar', 'detalle' => $detalle];
    $items = [];

    $items['tenant'] = ($tenant['tipo'] ?? 'app') === 'app' && (int) ($tenant['activo'] ?? 0) === 1
        ? $ok('La empresa es de tipo app y esta activa.')
        : $rechazar('La empresa tiene que ser de tipo app y estar activa (tipo ' . express_preflight_nombrar([$tenant['tipo'] ?? 'app'])
            . ', activo ' . (int) ($tenant['activo'] ?? 0) . ').');

    $items['columnas'] = ($hechos['columnas_014'] ?? false) === true
        ? $ok('Las cuatro columnas de la migracion master 014 existen en tenants.')
        : $rechazar('Faltan columnas de la migracion master 014: aplica db/master_migrations/014_tenants_edicion.sql en la master.');

    $items['m036'] = ($hechos['m036'] ?? false) === true
        ? $ok('facturas.idempotency_key existe en la base de la empresa (migracion de tenant 036).')
        : $rechazar('Falta la migracion de tenant 036 (facturas.idempotency_key) en la base de la empresa: aplicala antes de convertir.');

    $permitidos = express_preflight_tipos_permitidos();
    $fueraDeTipos = array_values(array_diff($tipos, $permitidos));
    if ($tipos === []) {
        $items['tipos'] = $rechazar('No se pidio ningun tipo de venta: indica al menos uno (' . implode(', ', $permitidos) . ').');
    } elseif ($fueraDeTipos !== []) {
        $items['tipos'] = $rechazar('Tipos que Express no vende: ' . express_preflight_nombrar($fueraDeTipos) . '. Se piden solo ' . implode(', ', $permitidos)
            . ' (el 34, la nota de credito, se deriva solo; el 33 es opcional).');
    } else {
        $items['tipos'] = $ok('Tipos pedidos: ' . implode(', ', $tipos) . '.');
    }

    $preset = $config['modulos'];
    $fueraDeModulos = $modulos === null ? [] : array_values(array_diff($modulos, $preset));
    $items['modulos'] = $fueraDeModulos === []
        ? $ok($modulos === null || $modulos === [] ? 'Sin lista de modulos: la empresa recibe todo el preset.' : 'Modulos pedidos: ' . implode(', ', $modulos) . '.')
        : $rechazar('Modulos que no estan en el preset: ' . express_preflight_nombrar($fueraDeModulos) . '. El preset es ' . implode(', ', $preset)
            . ' y se escriben exactos, sin mayusculas ni sinonimos.');

    $efectivos = TenantEntitlements::modulosEfectivos(['modulos_habilitados' => $modulos === null ? null : implode(',', $modulos)]);
    if (array_intersect(['31', '45'], $tipos) === []) {
        $items['clientes_modulo'] = $ok('No se piden los tipos 31 ni 45: no hace falta el modulo clientes.');
    } elseif (in_array('clientes', $efectivos, true)) {
        $items['clientes_modulo'] = $ok('Los tipos 31 y 45 piden el modulo clientes y la empresa lo tiene.');
    } else {
        $items['clientes_modulo'] = $rechazar('Los tipos 31 y 45 piden el modulo clientes y no esta entre los modulos de la empresa ('
            . ($efectivos === [] ? 'ninguno' : implode(', ', $efectivos)) . ').');
    }

    $cert = (array) ($hechos['cert'] ?? []);
    if (($cert['ok'] ?? false) === true && ($cert['codigo'] ?? null) === null) {
        $items['cert'] = $ok('Certificado propio legible, con su clave y vigente' . (is_int($cert['vence'] ?? null) ? ' hasta ' . date('Y-m-d', $cert['vence']) : '') . '.');
    } elseif (($cert['codigo'] ?? null) === 'CERT_VENCIDO') {
        $items['cert'] = $rechazar('CERT_VENCIDO: el certificado de la empresa ya vencio' . (is_int($cert['vence'] ?? null) ? ' (el ' . date('Y-m-d', $cert['vence']) . ')' : '')
            . '. Renuevalo con tools/set_tenant_cert.php.');
    } else {
        $items['cert'] = $rechazar('CERT_FALTA: la empresa no tiene un certificado propio que se pueda abrir (cert_path vacio, archivo que no se lee, o clave que falta '
            . 'o no lo abre). No se usa el certificado global del .env: cargalo con tools/set_tenant_cert.php.');
    }

    $emisor = $hechos['emisor'] ?? null;
    if (!is_array($emisor)) {
        $items['emisor'] = $rechazar('La base de la empresa no tiene la fila 1 de emisor_config.');
    } else {
        $rncEmisor = trim((string) ($emisor['rnc'] ?? ''));
        $rncEmpresa = trim((string) ($tenant['rnc'] ?? ''));
        $razon = trim((string) ($emisor['razon_social'] ?? ''));
        $pendientes = [];
        if ($rncEmisor === '' || $rncEmisor !== $rncEmpresa) {
            $pendientes[] = "rnc (emisor_config dice '{$rncEmisor}' y la empresa '{$rncEmpresa}')";
        }
        if ($razon === '' || strcasecmp($razon, EXPRESS_RAZON_PENDIENTE) === 0) {
            $pendientes[] = 'razon_social sin llenar';
        }
        if (in_array(strtolower(trim((string) ($emisor['direccion'] ?? ''))), EXPRESS_DIRECCIONES_PENDIENTES, true)) {
            $pendientes[] = 'direccion sin llenar';
        }
        if (trim((string) ($emisor['telefono'] ?? '')) === '') {
            $pendientes[] = 'telefono sin llenar';
        }
        $items['emisor'] = $pendientes === []
            ? $ok('emisor_config tiene el RNC de la empresa, su razon social, direccion y telefono.')
            : $rechazar('emisor_config incompleto: ' . implode('; ', $pendientes) . '.');
    }

    $items['almacen'] = ($hechos['almacen'] ?? false) === true
        ? $ok("Existe el almacen '" . EXPRESS_ALMACEN_DEFECTO . "'.")
        : $rechazar("No existe el almacen '" . EXPRESS_ALMACEN_DEFECTO . "': sin el no se puede crear un producto.");

    $apagar = static function (string $campo, string $efecto) use ($tenant, $nuevo, $ok, $corregir, $rechazar): array {
        if ((int) ($tenant[$campo] ?? 0) === 0) {
            return $ok("{$campo} ya esta en 0.");
        }
        return $nuevo
            ? $corregir("{$campo} esta en 1: se apaga, la empresa es nueva y {$efecto}.")
            : $rechazar("{$campo} esta en 1 y la empresa ya emitio en ecf: no se apaga sola.");
    };
    $items['pos'] = $apagar('pos_enabled', 'Express no tiene POS');
    $items['inventario'] = $apagar('inventario_estricto', 'Express no controla existencias');

    if ($ambiente !== 'ecf') {
        $items['rangos'] = $ok("No se evalua: el ambiente de la empresa es '{$ambiente}', no ecf.");
    } else {
        $faltan = [];
        foreach (TenantEntitlements::tiposEfectivos(['tipos_ecf_habilitados' => implode(',', $tipos)]) as $tipo) {
            if (!express_preflight_rango_vigente($rangos, 'E' . $tipo, $hoy)) {
                $faltan[] = 'E' . $tipo;
            }
        }
        $items['rangos'] = $faltan === []
            ? $ok('Cada tipo que la empresa emite tiene un rango autorizado, acotado, con capacidad y sin vencer.')
            : $rechazar('Faltan rangos autorizados por la DGII en ecf, acotados, con capacidad y sin vencer, para: ' . implode(', ', $faltan)
                . '. Registralos antes de convertir (POST /api/ncf/rangos); el esquema solo siembra rangos sin limite.');
    }

    $ilimitados = array_values(array_filter($rangos, static fn ($rango): bool => ($rango['numero_hasta'] ?? null) === null));
    if ($ilimitados === []) {
        $items['ilimitados'] = $ok('No hay rangos ecf sin limite.');
    } else {
        $tiposIlimitados = implode(', ', array_values(array_unique(array_map(static fn ($rango): string => (string) ($rango['type'] ?? '?'), $ilimitados))));
        $items['ilimitados'] = $nuevo
            ? $corregir(count($ilimitados) . " rango(s) ecf sin limite ({$tiposIlimitados}): se borran, la empresa es nueva y emite solo con rangos autorizados.")
            : $rechazar(count($ilimitados) . " rango(s) ecf sin limite ({$tiposIlimitados}) y la empresa ya emitio en ecf: no se borran solos.");
    }

    $prueba = (array) ($hechos['clientes_prueba'] ?? []);
    $existen = count((array) ($prueba['ids'] ?? []));
    $conEcf = (int) ($prueba['refs_ecf'] ?? 0);
    $libres = max(0, $existen - (int) ($prueba['refs_total'] ?? 0));
    if ($conEcf > 0) {
        $items['clientes_prueba'] = $rechazar("{$conEcf} cliente(s) de prueba de la DGII tienen una factura en ecf: no se borran solos.");
    } elseif (!$nuevo) {
        $items['clientes_prueba'] = $ok('No se borra ningun cliente de prueba: la empresa ya emitio en ecf.');
    } elseif ($libres > 0) {
        $items['clientes_prueba'] = $corregir("{$libres} cliente(s) de prueba de la DGII que ninguna factura usa: se borran. Los usados en certecf o testecf se quedan.");
    } else {
        $items['clientes_prueba'] = $ok($existen === 0
            ? 'No hay clientes de prueba de la DGII.'
            : 'Los clientes de prueba de la DGII solo estan en facturas de certecf o testecf: se quedan.');
    }

    $items['integracion'] = (string) ($tenant['api_secret_hash'] ?? '') === ''
        ? $ok('La credencial de integracion ya esta desactivada (api_secret_hash vacio).')
        : $corregir('Se deja en blanco api_secret_hash para que la credencial de integracion no sirva; el hash anterior queda en la fila de auditoria.');

    $rolUser = $hechos['rol_user'] ?? null;
    if ($rolUser === null) {
        $items['roles'] = $rechazar("La empresa no tiene el rol de sistema 'user' en la master.");
    } else {
        $esperados = array_values(array_unique($config['rol_user']));
        $actuales = array_values(array_unique(array_map('strval', (array) $rolUser)));
        $sinPermiso = array_values(array_diff($esperados, $actuales));
        $deMas = array_values(array_diff($actuales, $esperados));
        $items['roles'] = $sinPermiso === [] && $deMas === []
            ? $ok('El rol user ya tiene exactamente los permisos del Facturador (' . implode(', ', $esperados) . ').')
            : $corregir('El rol user no tiene los permisos del Facturador: le faltan [' . implode(', ', $sinPermiso) . '] y le sobran [' . implode(', ', $deMas)
                . ']; se deja con ' . implode(', ', $esperados) . '.');
    }

    return [
        'items' => $items,
        'nuevo' => $nuevo,
        'rechazos' => array_keys(array_filter($items, static fn (array $item): bool => $item['estado'] === 'rechazar')),
        'acciones' => array_keys(array_filter($items, static fn (array $item): bool => $item['estado'] === 'corregir')),
    ];
}

/**
 * El plan de lo que se escribe para convertir la empresa a Express, en el orden en que se aplica, o [] si la evaluacion rechaza algo.
 * Es pura. Cada paso es ['donde' => 'tenant'|'master', 'que' => descripcion, 'sql' => sentencia, 'params' => valores]: los de la base de la
 * empresa (cada uno idempotente) van antes que los de la master (que se aplican juntos, en una sola transaccion). Todo valor va en
 * parametros, ninguno pegado al SQL.
 *
 * Solo una empresa nueva (evaluacion['nuevo']) trae las dos bajas de la base de la empresa: los rangos ecf sin limite (los que siembra
 * el esquema) y los clientes de prueba de la DGII que ninguna factura usa. Una que ya emitio en ecf no borra nada aqui. Despues, en la
 * master: el UPDATE de tenants (edicion, tipos, modulos, vencimiento del certificado; POS y control de existencias apagados; credencial de
 * integracion en blanco) y el reseteo del rol user a los permisos del Facturador (config()['rol_user']; siempre, aunque el rol ya los tenga:
 * es idempotente y lo deja como dice la config de hoy).
 *
 * @return list<array{donde:string,que:string,sql:string,params:array<string,mixed>}>
 */
function express_plan(array $hechos, array $evaluacion): array
{
    $hayRechazo = ($evaluacion['rechazos'] ?? []) !== []
        || array_filter((array) ($evaluacion['items'] ?? []), static fn ($item): bool => ($item['estado'] ?? null) === 'rechazar') !== [];
    if ($hayRechazo) {
        return [];
    }

    $tenantId = (int) (((array) ($hechos['tenant'] ?? []))['id'] ?? 0);
    $pedido = (array) ($hechos['pedido'] ?? []);
    $tipos = implode(',', array_values(array_unique(array_map('strval', (array) ($pedido['tipos'] ?? [])))));
    $modulos = array_values(array_unique(array_map('strval', (array) ($pedido['modulos'] ?? []))));
    $vence = ((array) ($hechos['cert'] ?? []))['vence'] ?? null;

    $plan = [];
    if (($evaluacion['nuevo'] ?? false) === true) {
        $plan[] = [
            'donde' => 'tenant',
            'que' => 'Borrar los rangos ecf sin limite que siembra el esquema (la empresa usa solo rangos autorizados por la DGII)',
            'sql' => "DELETE FROM ncf_sequences WHERE ambiente = 'ecf' AND numero_hasta IS NULL",
            'params' => [],
        ];
        $plan[] = [
            'donde' => 'tenant',
            'que' => 'Borrar los clientes de prueba de la DGII que ninguna factura usa (los que usa certecf o testecf se quedan)',
            'sql' => 'DELETE FROM clients WHERE rnc IN (:r1, :r2) AND NOT EXISTS (SELECT 1 FROM facturas f WHERE f.client_id = clients.id)',
            'params' => [':r1' => EXPRESS_RNC_CLIENTES_PRUEBA[0], ':r2' => EXPRESS_RNC_CLIENTES_PRUEBA[1]],
        ];
    }
    $plan[] = [
        'donde' => 'master',
        'que' => 'Pasar la empresa a express con sus tipos, modulos y el vencimiento de su certificado; apagar POS y control de existencias; dejar en blanco la credencial de integracion',
        'sql' => "UPDATE tenants SET edicion = 'express', tipos_ecf_habilitados = :t, modulos_habilitados = :m, cert_vence = :v, pos_enabled = 0, inventario_estricto = 0, api_secret_hash = '' WHERE id = :id AND tipo = 'app'",
        'params' => [
            ':t' => $tipos,
            ':m' => $modulos === [] ? null : implode(',', $modulos),
            ':v' => is_int($vence) ? date('Y-m-d', $vence) : null,
            ':id' => $tenantId,
        ],
    ];
    $plan[] = [
        'donde' => 'master',
        'que' => 'Quitar los permisos del rol user de la empresa',
        'sql' => "DELETE FROM role_permissions WHERE role_id IN (SELECT id FROM roles WHERE tenant_id = :id AND name = 'user')",
        'params' => [':id' => $tenantId],
    ];
    foreach (array_values(array_unique(TenantEntitlements::config()['rol_user'])) as $permiso) {
        $plan[] = [
            'donde' => 'master',
            'que' => "Darle al rol user el permiso '{$permiso}'",
            'sql' => "INSERT INTO role_permissions (role_id, permission) SELECT id, :p FROM roles WHERE tenant_id = :id AND name = 'user'",
            'params' => [':p' => $permiso, ':id' => $tenantId],
        ];
    }
    return $plan;
}
