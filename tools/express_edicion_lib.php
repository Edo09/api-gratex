<?php
/**
 * express_edicion_lib.php — el preflight de la edicion Express (Fiscalpoint Express, plan 02).
 *
 * Convertir una empresa a Express (master.tenants.edicion = 'express') no se hace a ciegas. tools/tenant_edicion.php (una empresa que ya
 * existe) y tools/create_tenant.php (la que acaba de crear) comparten estos pasos, que son lo unico que decide si se convierte:
 *   1. hechos       express_preflight_hechos lee de la master y de la base de la empresa lo que hace falta. Solo SELECT.
 *   2. evaluacion   express_preflight_evaluar da a cada item ok, corregir (el plan lo arregla) o rechazar (no se cambia nada).
 *   3. plan         express_plan arma las sentencias que convierten la empresa, en el orden en que se aplican: [] si algo se rechaza.
 *   4. aplicar      express_aplicar escribe el plan con su fila de auditoria (la base de la empresa primero y despues UNA transaccion en la
 *                   master) y relee lo escrito para compararlo con el plan.
 * Encima de esos cuatro pasos estan las tres ordenes de tools/tenant_edicion.php, cada una con su informe (las lineas que la herramienta
 * imprime) y sin escribir nada si no se pide: express_convertir (hechos, evaluacion, plan y, con $apply, aplicar), express_revertir (devuelve
 * una empresa a completa: es lo que levanta toda restriccion, y el informe avisa que no es una parada segura) y express_refrescar_cert
 * (recalcula tenants.cert_vence desde el archivo del certificado de una empresa Express).
 *
 * Reglas del archivo:
 *   - Se puede incluir desde donde sea: al incluirlo no imprime nada, no abre ninguna conexion y no cambia ninguna configuracion.
 *   - Nunca imprime ni escribe en el log por si misma: el informe viaja en el arreglo que devuelve y quien llama lo imprime. La unica linea de
 *     log que puede salir es la de TenantEntitlements::normalizarEdicion, que avisa una vez por valor cuando la edicion guardada no es ni
 *     completa ni express (se trata como completa).
 *   - De los valores de configuracion del php (ini_set) solo cambia error_log, y solo dentro de express_conectar_master, mientras conecta con la
 *     master: lo desvia a un archivo temporal (lo que MasterDatabase escribe al fallar lleva el usuario y el servidor) y lo restaura al salir,
 *     tambien si lanza.
 *   - Evaluar y armar el plan son PURAS: reciben arreglos y devuelven arreglos; no tocan una base, ni el log, ni lo que reciben. Solo
 *     express_aplicar y el UPDATE de express_refrescar_cert escriben en una base, y solo cuando quien llama lo pide ($apply).
 *   - Nada de lo que devuelve la biblioteca se imprime con la fila de la empresa dentro: $hechos['tenant'] es la fila COMPLETA de
 *     master.tenants (con la clave de su base, la del certificado y el hash de integracion). La evaluacion y el plan no copian nada de ella:
 *     ni la ruta del certificado, ni ninguna clave, ni el hash, ni el servidor o el usuario de su base. Quien imprima, imprime de ahi.
 *   - Solo cuentan las filas de ecf (produccion). Lo que la certificacion dejo en certecf (facturas, contadores y los clientes de prueba
 *     de la DGII usados en ellas) nunca bloquea una conversion (diseno 7, decision D4); testecf tampoco.
 *   - El certificado se lee como lo hara la emision: del cert_path de la empresa, sin respaldo con el certificado global del .env.
 *     El plan 03 agrega TenantEntitlements::certificadoReal con el mismo resultado y una prueba de paridad con express_leer_certificado:
 *     ninguna de las dos llama a la otra, asi que si algun dia se separan, lo dice esa prueba. Mientras llega, el limite del vencimiento es el
 *     de la emision que ya existe (ECFEmissionService::exigirCertificadoVigente) y las suites lo comparan: ver express_leer_certificado.
 */

require_once __DIR__ . '/../src/TenantEntitlements.php';   // trae TenantResolver (decrypt) y config/editions.php; no imprime ni conecta
require_once __DIR__ . '/../src/MasterDatabase.php';       // express_conectar_master la usa (TenantResolver ya la trae); incluirla no conecta

/** El almacen por defecto que siembra tenant_schema.sql (warehouseModel::DEFAULT_NOMBRE): crear un producto falla sin el. */
const EXPRESS_ALMACEN_DEFECTO = 'Almacén Principal';

/** RNC de los dos clientes de prueba de la DGII que siembra tenant_schema.sql para el set de certificacion. */
const EXPRESS_RNC_CLIENTES_PRUEBA = ['131880681', '533445861'];

/** Lo que deja sin llenar un emisor_config recien creado: la razon social de tenant_schema.sql y la direccion de ahi y de create_tenant.php. */
const EXPRESS_RAZON_PENDIENTE = 'PENDIENTE ONBOARDING';
const EXPRESS_DIRECCIONES_PENDIENTES = ['', 'pendiente', 'n/d'];

/**
 * La fila de audit_logs de cada cambio de edicion. Se escribe con un INSERT directo y no con AuditLogger: la fila tiene que ir en la misma
 * transaccion que el cambio (AuditLogger traga sus propios errores y escribe aparte), asi que no pasa por su redaccion de secretos: ningun
 * valor de la fila lo es (integracion_hash_anterior es un hash, y su nombre no lleva secret, token ni api_key por si algun dia pasara).
 */
const EXPRESS_AUDITORIA_USUARIO = 'tools/tenant_edicion.php';
const EXPRESS_AUDITORIA_MODULO = 'edicion';
const EXPRESS_AUDITORIA_ACCION = 'EDICION_CAMBIO';
const EXPRESS_AUDITORIA_ENTIDAD = 'tenant';

/** Las columnas de tenants que express_aplicar relee despues del commit y compara con lo que el plan dejo. */
const EXPRESS_COLUMNAS_VERIFICADAS = ['edicion', 'tipos_ecf_habilitados', 'modulos_habilitados', 'cert_vence'];

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
 * La conexion a la master. MasterDatabase carga el .env de la raiz al crearse y lee su configuracion de $_ENV (con variables de entorno hay que correr el
 * php con -d variables_order=EGPCS). Al fallar, deja en el log el usuario, el servidor y la base: en la consola eso saldria por stderr. Mientras conecta, el
 * log va a un archivo temporal, que se borra al salir, y error_log se restaura (a vacio si no tenia), tambien si la conexion lanza: a quien llama le llega
 * solo la RuntimeException de MasterDatabase, con su texto fijo (No se pudo conectar a la base de datos maestra.).
 *
 * Es el unico lugar donde vive esta precaucion: tools/tenant_edicion.php y tools/verificar_express.php la llaman y ninguna la copia, para que no se desvien.
 *
 * @param string $prefijo con que empieza el nombre del archivo temporal: el de la herramienta que llama, para saber de quien es uno que quedara si el proceso
 *                        muriera conectando (en Windows tempnam solo usa las tres primeras letras)
 */
function express_conectar_master(string $prefijo): PDO
{
    $archivo = tempnam(sys_get_temp_dir(), $prefijo);
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
 * archivo legible, cert_pass_encrypted no vacio que se descifra, un .p12 que openssl_pkcs12_read abre con esa clave y un notAfter que no sea
 * anterior a $ahora (marca de tiempo; por omision, la hora actual). Sin respaldo con el certificado del .env, no imprime nada y no lanza.
 *
 * El limite es el de la emision: ECFEmissionService::exigirCertificadoVigente vence un certificado solo con notAfter < time(), asi que el
 * certificado vale hasta su notAfter inclusive (RFC 5280: la vigencia va de notBefore a notAfter, ambos incluidos) y vence un segundo despues.
 * Es tambien lo que el plan 03 pide de TenantEntitlements::certificadoReal (validTo_time_t < time() es CERT_VENCIDO). La suite pura lee esa
 * comparacion de la emision y la scratch compara las dos lecturas con certificados reales: si una de las dos se mueve, esas pruebas lo dicen.
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
    return $vence >= $ahora   // vence < ahora es lo que la emision llama vencido (exigirCertificadoVigente): a la marca exacta del notAfter todavia vale
        ? ['ok' => true, 'codigo' => null, 'vence' => $vence]
        : ['ok' => false, 'codigo' => 'CERT_VENCIDO', 'vence' => $vence];
}

/** Las filas de un SELECT como arreglos asociativos. Solo para consultas de lectura: es la puerta de LECTURA de la biblioteca (las escrituras pasan por express_ejecutar). */
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
 *     tenant:array, columnas_edicion:bool, m036:bool, cert:array{ok:bool,codigo:?string,vence:?int},
 *     emisor:?array{rnc:string,razon_social:string,direccion:string,telefono:string}, almacen:bool, pedido:array,
 *     rangos_ecf:list<array{type:string,numero_hasta:?int,current_value:int,fecha_vencimiento:?string}>, ecf_facturas:?int,
 *     clientes_prueba:array{ids:list<int>,refs_ecf:int,refs_total:int}, rol_user:?list<string>, hoy:string
 * } columnas_edicion: las cuatro columnas de la migracion master 015 existen; m036: facturas.idempotency_key existe en la base de la empresa;
 *   cert: express_leer_certificado de la fila; emisor: la fila 1 de emisor_config (NULL como ''), o null si no hay; almacen: existe
 *   "Almacén Principal"; rangos_ecf: los rangos de ncf_sequences con ambiente ecf; ecf_facturas: cuantas facturas tienen ambiente_dgii ecf;
 *   clientes_prueba: los ids de los clientes de la DGII (RNC 131880681 y 533445861) y CUANTOS de ellos tienen una factura ecf (refs_ecf) o una
 *   de cualquier ambiente (refs_total); rol_user: express_preflight_rol_user; hoy: la fecha de hoy, Y-m-d, en la zona de PHP (el dispensador de
 *   ncfModel compara el vencimiento de un rango con CURDATE() de MySQL: en el hosting las dos zonas son la misma, America/Chicago).
 */
function express_preflight_hechos(PDO $master, ?PDO $tenantDb, array $tenant, array $pedido): array
{
    $hechos = [
        'tenant' => $tenant,
        'columnas_edicion' => express_preflight_contar(
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
 * no es un rango autorizado), con capacidad (current_value menor que numero_hasta) y sin vencer (fecha_vencimiento vacia o no anterior a $hoy).
 *
 * Contra el modelo (ncfModel), medido en la suite scratch con filas reales:
 *   - dispenseNextECF elige de donde sacar el numero con el mismo filtro de capacidad y vigencia (numero_hasta NULL o current_value < numero_hasta,
 *     y fecha_vencimiento NULL o >= CURDATE()). Este predicado pone UNA condicion mas: numero_hasta no nulo, porque el dispensador usa un rango sin
 *     limite pero la DGII no lo autorizo. Sin rangos sin limite de por medio, los dos dicen siempre lo mismo.
 *   - rangoCubre comparte la regla de vigencia (un rango que vence hoy vale) pero NO es este predicado: pregunta por un numero concreto
 *     (numero_desde <= valor <= numero_hasta), no mira current_value (da por cubierto el ultimo numero de un rango agotado) y da por cubierto un
 *     rango sin limite.
 * El dia: $hoy lo pone quien llama (los hechos traen el de PHP); el modelo compara con CURDATE() de MySQL. En el hosting las dos zonas son la misma
 * (America/Chicago, medido en el spike S2).
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
 * tenant, grupo, columnas, m036, tipos, modulos, clientes_modulo, cert, emisor, almacen, pos, inventario, rangos, ilimitados, clientes_prueba,
 * integracion, roles (grupo se sumo en la revision final: una empresa de un grupo siempre se rechaza). Cada uno es ['estado' => 'ok'|'corregir'|'rechazar', 'detalle' => texto]: ok no pide nada, corregir lo arregla el
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

    // El grupo (revision final). TenantResolver::switchToSibling deja que la credencial de integracion de CUALQUIER empresa de un grupo (el mismo grupo_id) actue por las demas, y esa
    // ruta (/api/integracion/*) no pasa por la lista de rutas, los modulos ni los tipos de Express: una empresa Express en un grupo la usa, con cualquier tipo y cualquier cuerpo, quien
    // tenga la credencial de una hermana completa. Ademas el plan deja en blanco api_secret_hash, que en la cabeza de un grupo (grupo_id = su propio id, create_tenant.php --grupo=self)
    // es la credencial que el cliente usa para todo el grupo. Por eso una empresa de un grupo no se convierte, nueva o no: sacarla del grupo es decision del dueño y no se corrige sola.
    // "Esta en un grupo" es lo que dice TenantResolver::grupoId(): solo NULL y el vacio son sin grupo (una columna ausente, un master sin la migracion 008, es sin grupo). La cabeza
    // de un grupo siempre trae su propio id en grupo_id, asi que esta misma prueba la cubre.
    $grupoId = $tenant['grupo_id'] ?? null;
    $items['grupo'] = $grupoId === null || $grupoId === ''
        ? $ok('La empresa no esta en ningun grupo (grupo_id vacio).')
        : $rechazar('La empresa esta en el grupo #' . (int) $grupoId . ' (grupo_id ' . (int) $grupoId . '). La credencial de integracion de cualquier empresa de un grupo actua por las demas '
            . 'sin pasar por las rutas, los modulos ni los tipos de Express, y al convertir se desactiva la credencial de esta (que puede ser la que el cliente usa para todo el grupo). '
            . 'Una empresa Express no puede estar en un grupo: no se convierte; sacala del grupo antes (grupo_id en NULL) si el cliente esta de acuerdo.');

    $items['columnas'] = ($hechos['columnas_edicion'] ?? false) === true
        ? $ok('Las cuatro columnas de la migracion master 015 existen en tenants.')
        : $rechazar('Faltan columnas de la migracion master 015: aplica db/master_migrations/015_tenants_edicion.sql en la master.');

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
 * cert_vence (:v) es una columna DATE (migracion master 015, solo informativa): se escribe date('Y-m-d', notAfter) en la zona horaria por defecto
 * de PHP, que es como la lee TenantEntitlements::estadoCertificado (el bloque certificado de GET /api/emisor), que cuenta los dias contra
 * date('Y-m-d') de esa misma zona. NULL si no se sabe el notAfter. Una fecha vieja no bloquea nada: la emision abre el certificado de verdad.
 *
 * La baja de clientes de prueba no puede chocar con una llave foranea: ninguna tabla de la base de la empresa apunta a clients (cotizaciones,
 * conduces y facturas guardan client_id sin FK, y borrar un cliente desde la app tampoco mira esas tablas). Solo se protegen los que usa una
 * factura, que es el dato fiscal; una cotizacion o un conduce que cite a un cliente de prueba conserva su client_name y queda con un client_id
 * que ya no existe, igual que al borrar ese cliente desde la app. La suite scratch corre estas dos sentencias contra el esquema real.
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
            ':v' => is_int($vence) ? date('Y-m-d', $vence) : null,   // DATE: el dia del notAfter en la zona de PHP, como lo lee estadoCertificado
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

/**
 * Prepara y ejecuta una sentencia con sus parametros. Lanza RuntimeException (con un texto fijo: nada del SQL ni de los valores) si PDO no
 * pudo: con las excepciones apagadas prepare y execute devuelven false en lugar de lanzar. Las conexiones de las herramientas las traen prendidas.
 */
function express_ejecutar(PDO $pdo, string $sql, array $params = []): PDOStatement
{
    $sentencia = $pdo->prepare($sql);
    if ($sentencia === false || $sentencia->execute($params) === false) {
        throw new RuntimeException('No se pudo ejecutar una sentencia de la edicion (la conexion no lanza excepciones).');
    }
    return $sentencia;
}

/** El valor de una columna en un mensaje: NULL, o el texto entre comillas, solo ASCII imprimible y de largo acotado (viene de una base). */
function express_valor_visible(?string $valor): string
{
    return $valor === null ? 'NULL' : "'" . substr((string) preg_replace('/[^\x20-\x7E]/', '?', $valor), 0, 40) . "'";
}

/**
 * Las cuatro columnas de la edicion de una empresa en master.tenants (EXPRESS_COLUMNAS_VERIFICADAS), cada una como texto o NULL, o null si la
 * fila no existe. Es la lectura de despues del commit de express_aplicar y del UPDATE de express_refrescar_cert.
 *
 * @return ?array{edicion:?string,tipos_ecf_habilitados:?string,modulos_habilitados:?string,cert_vence:?string}
 */
function express_leer_estado(PDO $master, int $tenantId): ?array
{
    $fila = express_preflight_filas(
        $master,
        'SELECT edicion, tipos_ecf_habilitados, modulos_habilitados, cert_vence FROM tenants WHERE id = :id',
        [':id' => $tenantId]
    )[0] ?? null;
    if (!is_array($fila)) {
        return null;
    }
    $estado = [];
    foreach (EXPRESS_COLUMNAS_VERIFICADAS as $columna) {
        $estado[$columna] = ($fila[$columna] ?? null) === null ? null : (string) $fila[$columna];
    }
    return $estado;
}

/**
 * En que difiere lo que se leyo ($leido; null = la fila no esta) de lo que el plan debia dejar ($esperado), por columna de
 * EXPRESS_COLUMNAS_VERIFICADAS y como texto con el nombre de la columna, lo esperado y lo leido. La comparacion es estricta entre textos:
 * NULL no es el texto vacio ('' en modulos_habilitados angosta distinto que NULL a ojos de quien lo escribio a mano).
 *
 * @return list<string> vacia si coinciden
 */
function express_diferencias(array $esperado, ?array $leido): array
{
    if ($leido === null) {
        return ['la fila del tenant ya no esta'];
    }
    $difieren = [];
    foreach (EXPRESS_COLUMNAS_VERIFICADAS as $columna) {
        $quiero = ($esperado[$columna] ?? null) === null ? null : (string) $esperado[$columna];
        $tengo = ($leido[$columna] ?? null) === null ? null : (string) $leido[$columna];
        if ($quiero !== $tengo) {
            $difieren[] = "{$columna} (esperado " . express_valor_visible($quiero) . ', leido ' . express_valor_visible($tengo) . ')';
        }
    }
    return $difieren;
}

/**
 * Aplica un plan (el de express_plan, o el de revertir) y lo audita. Es lo que escribe en las bases, y lo hace asi:
 *   1. Valida el plan y la auditoria sin tocar ninguna base: un paso sin donde (tenant o master), sql y params, una auditoria sin tenant_id o
 *      sin las cuatro columnas verificadas en new_values, o pasos de la base de la empresa sin una conexion a ella, lanzan
 *      InvalidArgumentException antes de ejecutar nada.
 *   2. Los pasos de la base de la empresa (donde = tenant), en orden y sin transaccion: cada uno es idempotente. Si uno falla se lanza esa
 *      excepcion y la master no se toca; los que ya corrieron se quedan, y volver a correr el plan es seguro.
 *   3. UNA transaccion en la master con sus pasos (donde = master), en orden, y el INSERT de la fila de audit_logs. Cualquier falla (un paso,
 *      el INSERT, que lastInsertId no de el id de la fila, o un commit que lanza o devuelve false) hace rollBack, si la transaccion sigue
 *      abierta, y relanza LA MISMA excepcion: el cambio de la master y su fila de auditoria son atomicos y, si algo sale mal, ni uno ni otro.
 *   4. Despues del commit relee la fila de tenants y la de audit_logs (por el id que dio el INSERT) y las compara con lo que el plan debia
 *      dejar, que es $auditoria['new_values']: si edicion, tipos_ecf_habilitados, modulos_habilitados o cert_vence difieren en cualquiera de las
 *      dos filas, o la de auditoria no es la del cambio, lanza RuntimeException. El cambio YA esta confirmado: el mensaje lo dice y nombra
 *      cada columna con lo esperado y lo leido (solo esas cuatro: ningun secreto), y no hay nada que deshacer aqui.
 *
 * Quien la llama arma $auditoria de lo que el plan va a escribir (express_auditoria): tenant_id; old_values y new_values con edicion,
 * tipos_ecf_habilitados, modulos_habilitados, cert_vence, pos_enabled e inventario_estricto (old_values lleva ademas integracion_hash_anterior);
 * description (de lo que no cabe en 255 caracteres se corta). La fila lleva module edicion, action EDICION_CAMBIO, entity_type tenant,
 * entity_id el id de la empresa, username tools/tenant_edicion.php y success 1.
 *
 * @param ?PDO $tenantDb conexion a la base de la empresa (express_conectar_tenant); null solo si el plan no trae pasos para ella
 * @param array $plan    lista de ['donde' => 'tenant'|'master', 'que' => texto, 'sql' => sentencia, 'params' => valores]
 */
function express_aplicar(PDO $master, ?PDO $tenantDb, array $plan, array $auditoria): void
{
    // 1. Lo que se decide sin tocar una base.
    $tenantId = (int) ($auditoria['tenant_id'] ?? 0);
    $antes = (array) ($auditoria['old_values'] ?? []);
    $despues = (array) ($auditoria['new_values'] ?? []);
    if ($tenantId <= 0) {
        throw new InvalidArgumentException('La auditoria no trae el tenant_id de la empresa.');
    }
    $faltan = array_values(array_filter(EXPRESS_COLUMNAS_VERIFICADAS, static fn (string $columna): bool => !array_key_exists($columna, $despues)));
    if ($faltan !== []) {
        throw new InvalidArgumentException('new_values de la auditoria no trae ' . implode(', ', $faltan) . ': sin ellas no hay con que comparar la lectura de despues del commit.');
    }
    $formato = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;
    $jsonAntes = json_encode($antes, $formato);
    $jsonDespues = json_encode($despues, $formato);
    $descripcion = substr((string) ($auditoria['description'] ?? ''), 0, 255);
    $deLaEmpresa = [];
    $deLaMaster = [];
    foreach (array_values($plan) as $i => $paso) {
        $donde = is_array($paso) ? ($paso['donde'] ?? null) : null;
        if (!in_array($donde, ['tenant', 'master'], true) || !is_string($paso['sql'] ?? null) || trim($paso['sql']) === '' || !is_array($paso['params'] ?? null)) {
            throw new InvalidArgumentException('El paso #' . ($i + 1) . ' del plan no trae donde (tenant o master), sql y params.');
        }
        if ($donde === 'tenant') {
            $deLaEmpresa[] = $paso;
        } else {
            $deLaMaster[] = $paso;
        }
    }
    if ($deLaEmpresa !== [] && $tenantDb === null) {
        throw new InvalidArgumentException('El plan trae pasos para la base de la empresa y no se dio conexion a ella.');
    }

    // 2. La base de la empresa primero: sus pasos son idempotentes y no se deshacen con la transaccion de la master.
    foreach ($deLaEmpresa as $paso) {
        express_ejecutar($tenantDb, $paso['sql'], $paso['params']);
    }

    // 3. La master: sus pasos y la fila de auditoria en UNA transaccion.
    $master->beginTransaction();
    try {
        foreach ($deLaMaster as $paso) {
            express_ejecutar($master, $paso['sql'], $paso['params']);
        }
        express_ejecutar(
            $master,
            'INSERT INTO audit_logs (tenant_id, username, module, entity_type, entity_id, action, old_values, new_values, description, success) '
            . 'VALUES (:tenant_id, :username, :module, :entity_type, :entity_id, :action, :old_values, :new_values, :description, :success)',
            [
                ':tenant_id' => $tenantId, ':username' => EXPRESS_AUDITORIA_USUARIO, ':module' => EXPRESS_AUDITORIA_MODULO,
                ':entity_type' => EXPRESS_AUDITORIA_ENTIDAD, ':entity_id' => (string) $tenantId, ':action' => EXPRESS_AUDITORIA_ACCION,
                ':old_values' => $jsonAntes, ':new_values' => $jsonDespues, ':description' => $descripcion, ':success' => 1,
            ]
        );
        // El id de la fila se toma ANTES del commit: despues, mysql_insert_id() ya no lo recuerda. Sin un id no se puede releer la fila de
        // auditoria, y es mejor no confirmar que confirmar un cambio cuya fila no se puede verificar.
        $auditoriaId = (int) $master->lastInsertId();
        if ($auditoriaId <= 0) {
            throw new RuntimeException('El INSERT de la fila de auditoria (audit_logs) no dio un id: no se puede verificar y no se confirma el cambio.');
        }
        if ($master->commit() === false) {
            throw new RuntimeException('No se pudo confirmar la transaccion de la master.');
        }
    } catch (Throwable $e) {
        if ($master->inTransaction()) {
            try {
                $master->rollBack();
            } catch (Throwable) {
                // Si el rollBack tambien falla, la excepcion que explica el fallo es la original.
            }
        }
        throw $e;
    }

    // 4. Lo escrito, releido: la fila de la empresa y la de auditoria contra lo que el plan debia dejar.
    try {
        $leido = express_leer_estado($master, $tenantId);
        $auditada = express_preflight_filas(
            $master,
            'SELECT id, tenant_id, module, action, new_values FROM audit_logs WHERE id = :id',
            [':id' => $auditoriaId]
        )[0] ?? null;
    } catch (Throwable $e) {
        throw new RuntimeException(
            "El cambio del tenant #{$tenantId} se confirmo en la master, pero no se pudo leer de vuelta para verificarlo: revisa la fila del tenant y su fila de auditoria.",
            0,
            $e
        );
    }
    $difieren = express_diferencias($despues, $leido);
    if (!is_array($auditada)) {
        $difieren[] = "la fila de auditoria #{$auditoriaId} (audit_logs) no se encontro";
    } else {
        if (($auditada['module'] ?? null) !== EXPRESS_AUDITORIA_MODULO || ($auditada['action'] ?? null) !== EXPRESS_AUDITORIA_ACCION
            || (int) ($auditada['tenant_id'] ?? 0) !== $tenantId) {
            $difieren[] = "la fila de auditoria #{$auditoriaId} no es la del cambio (otro modulo, otra accion u otra empresa)";
        }
        $valoresAuditados = json_decode((string) ($auditada['new_values'] ?? ''), true);
        foreach (is_array($valoresAuditados) ? express_diferencias($despues, $valoresAuditados) : ['new_values de la fila de auditoria no es un JSON'] as $diferencia) {
            $difieren[] = "auditoria: {$diferencia}";
        }
    }
    if ($difieren !== []) {
        throw new RuntimeException(
            "El cambio del tenant #{$tenantId} ya se confirmo en la master, pero al releerlo no coincide con el plan: " . implode('; ', $difieren)
            . '. Revisa la fila del tenant y su fila de auditoria antes de seguir.'
        );
    }
}

// ---------------------------------------------------------------------------------------------------------------------------------------------------
// Las tres ordenes de tools/tenant_edicion.php y lo que arman: la fila de la empresa, lo que la bitacora guarda y el informe que se imprime.
// ---------------------------------------------------------------------------------------------------------------------------------------------------

/** La fila completa de master.tenants de una empresa (de cualquier tipo, activa o no), o null si no existe. */
function express_tenant_fila(PDO $master, int $tenantId): ?array
{
    $fila = express_preflight_filas($master, 'SELECT * FROM tenants WHERE id = :id LIMIT 1', [':id' => $tenantId])[0] ?? null;
    return is_array($fila) ? $fila : null;
}

/**
 * Lo que la bitacora guarda del estado de una empresa, de su fila de master.tenants: seis columnas, en este orden (las cuatro de la edicion
 * como texto o NULL, y pos_enabled e inventario_estricto como enteros). Una columna que la fila no trae (master sin migrar) sale NULL, o 0.
 *
 * @return array{edicion:?string,tipos_ecf_habilitados:?string,modulos_habilitados:?string,cert_vence:?string,pos_enabled:int,inventario_estricto:int}
 */
function express_estado_auditado(array $tenant): array
{
    $texto = static fn (mixed $valor): ?string => $valor === null ? null : (string) $valor;
    return [
        'edicion' => $texto($tenant['edicion'] ?? null),
        'tipos_ecf_habilitados' => $texto($tenant['tipos_ecf_habilitados'] ?? null),
        'modulos_habilitados' => $texto($tenant['modulos_habilitados'] ?? null),
        'cert_vence' => $texto($tenant['cert_vence'] ?? null),
        'pos_enabled' => (int) ($tenant['pos_enabled'] ?? 0),
        'inventario_estricto' => (int) ($tenant['inventario_estricto'] ?? 0),
    ];
}

/**
 * La $auditoria de express_aplicar para un cambio de la fila $tenant: old_values es el estado de la fila (express_estado_auditado) mas
 * integracion_hash_anterior, el hash de integracion que tiene hoy ('' si ya esta en blanco); new_values, ese mismo estado con $cambios encima
 * (solo las claves que el cambio toca; el resto queda como esta). integracion_hash_anterior solo va en old_values, y la fila no lleva nada mas
 * de $tenant: ni la ruta del certificado, ni claves.
 *
 * @param array<string,mixed> $cambios claves de express_estado_auditado con el valor que el cambio les deja
 * @return array{tenant_id:int,old_values:array,new_values:array,description:string}
 */
function express_auditoria(array $tenant, array $cambios, string $descripcion): array
{
    $antes = express_estado_auditado($tenant);
    return [
        'tenant_id' => (int) ($tenant['id'] ?? 0),
        'old_values' => $antes + ['integracion_hash_anterior' => (string) ($tenant['api_secret_hash'] ?? '')],
        'new_values' => array_replace($antes, $cambios),
        'description' => $descripcion,
    ];
}

/** Un texto en una sola linea: los espacios, tabuladores y saltos de linea seguidos pasan a un solo espacio. */
function express_una_linea(string $texto): string
{
    return trim((string) preg_replace('/\s+/', ' ', $texto));
}

/**
 * Una excepcion como la linea de un error inesperado que SI se puede imprimir: la clase y archivo:linea y, de una PDOException, solo el SQLSTATE y
 * el codigo del driver (su mensaje trae el usuario y el servidor, y no sale nunca); de las excepciones de la biblioteca y de las herramientas
 * (InvalidArgument, Runtime y Logic) y de los errores del motor (Error), su mensaje en una sola linea (no lleva secretos). De cualquier otra solo
 * la clase. Es el unico lugar donde vive esta politica: tools/tenant_edicion.php y tools/create_tenant.php la llaman y ninguna la copia, para que
 * no se desvien.
 */
function express_describir_fallo(Throwable $e): string
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

/**
 * El informe de una evaluacion: una linea por item, "[OK  ] id: detalle", "[CORR] id: detalle" o "[RECH] id: detalle" segun su estado, en el
 * orden de los items. Los detalles de express_preflight_evaluar y los de las ordenes de abajo no llevan secretos ni rutas.
 *
 * @param array $evaluacion lo que devuelve express_preflight_evaluar (solo se lee 'items'; una clave que falte no da lineas)
 * @return list<string>
 */
function express_lineas_evaluacion(array $evaluacion): array
{
    $marcas = ['ok' => '[OK  ]', 'corregir' => '[CORR]', 'rechazar' => '[RECH]'];
    $lineas = [];
    foreach ((array) ($evaluacion['items'] ?? []) as $id => $item) {
        $lineas[] = ($marcas[$item['estado'] ?? ''] ?? '[????]') . " {$id}: " . express_una_linea((string) ($item['detalle'] ?? ''));
    }
    return $lineas;
}

/**
 * El informe de un plan: un encabezado y un renglon numerado por paso (los de la base de la empresa marcados [empresa] y los de la master
 * [master]) y, con $conAuditoria, uno mas para la fila de auditoria que express_aplicar escribe en la misma transaccion de la master. Ningun
 * valor de los parametros sale. Un plan vacio no da lineas.
 *
 * @return list<string>
 */
function express_lineas_plan(array $plan, bool $conAuditoria = true): array
{
    if ($plan === []) {
        return [];
    }
    $lineas = [$conAuditoria
        ? 'Pasos, en este orden (los de la empresa primero; los de la master van juntos, con la fila de auditoria, en una sola transaccion):'
        : 'Paso (un solo UPDATE, sin fila de auditoria: no cambia la edicion):'];
    $n = 0;
    foreach (array_values($plan) as $paso) {
        $n++;
        $lineas[] = "  {$n}. [" . (($paso['donde'] ?? '') === 'tenant' ? 'empresa' : 'master') . '] ' . express_una_linea((string) ($paso['que'] ?? ''));
    }
    if ($conAuditoria) {
        $lineas[] = '  ' . ($n + 1) . '. [master] Registrar el cambio en la bitacora (audit_logs, modulo ' . EXPRESS_AUDITORIA_MODULO . ', accion ' . EXPRESS_AUDITORIA_ACCION . ')';
    }
    return $lineas;
}

/**
 * El informe de una orden que se rechaza antes de escribir: $previas (el encabezado), los items como lineas (express_lineas_evaluacion) y
 * "RECHAZADO: <n> item(s). No se cambio nada." con cuantos items son [RECH].
 *
 * @param array<string,array{estado:string,detalle:string}> $items
 * @param list<string> $previas
 * @return list<string>
 */
function express_lineas_rechazo(array $items, array $previas = []): array
{
    $n = count(array_filter($items, static fn (array $item): bool => ($item['estado'] ?? '') === 'rechazar'));
    return array_merge($previas, express_lineas_evaluacion(['items' => $items]), ["RECHAZADO: {$n} item(s). No se cambio nada."]);
}

/** El item de una orden sobre una empresa que no existe en master.tenants. @return array{tenant:array{estado:string,detalle:string}} */
function express_item_sin_empresa(int $tenantId): array
{
    return ['tenant' => ['estado' => 'rechazar', 'detalle' => "No existe el tenant #{$tenantId} en master.tenants."]];
}

/** El encabezado del informe de una orden: la empresa (su id y su RNC, sin comillas ni nada que venga del operador) y lo que se pide. */
function express_encabezado(array $tenant, int $tenantId, string $que): string
{
    return "Empresa #{$tenantId} (RNC " . express_preflight_nombrar([(string) ($tenant['rnc'] ?? '')]) . "): {$que}";
}

/**
 * Pasa una empresa a Express, o dice por que no: el preflight completo (hechos, evaluacion y plan) y, con $apply y sin rechazos, express_aplicar
 * con su fila de auditoria. Sin $apply solo lee: SELECT en la master y en la base de la empresa.
 *
 * Lee la fila de la empresa (de cualquier tipo, activa o no), abre su base con express_conectar_tenant (una empresa de integracion no tiene: sus
 * hechos van sin ella y la evaluacion la rechaza) y deja pasar sus errores de conexion: cuando la base no se alcanza no hay hechos que juzgar, y
 * un "m036 falta" falso seria peor que decir que no se pudo conectar. Una empresa que no existe sale rechazada, con un item tenant.
 *
 * El informe (lineas) es: el encabezado, un renglon por item ([OK  ], [CORR] o [RECH]) y, segun el caso, "RECHAZADO: <n> item(s). No se
 * cambio nada." (hay algun [RECH]: ok false), o los pasos del plan y "DRY-RUN: no se escribio nada. Agrega --apply para aplicar." (ok, sin
 * $apply) o "APLICADO: el tenant #<id> ahora es express." (ok, aplicado).
 *
 * @param array $pedido ['edicion' => 'express', 'tipos' => list<string>, 'modulos' => ?list<string>] (otra edicion lanza InvalidArgumentException)
 * @return array{ok:bool,aplicado:bool,evaluacion:array,plan:list<array>,lineas:list<string>}
 *         ok: no hubo rechazos (con $apply, ademas se aplico); aplicado: se escribio; plan: [] si hubo rechazos
 */
function express_convertir(PDO $master, int $tenantId, array $pedido, bool $apply): array
{
    $edicionPedida = $pedido['edicion'] ?? 'express';
    if ($edicionPedida !== 'express') {
        throw new InvalidArgumentException('express_convertir solo convierte a express (el pedido trae la edicion ' . express_preflight_nombrar([(string) $edicionPedida])
            . '): para volver a completa esta express_revertir.');
    }
    $tenant = express_tenant_fila($master, $tenantId);
    if ($tenant === null) {
        $items = express_item_sin_empresa($tenantId);
        return [
            'ok' => false, 'aplicado' => false,
            'evaluacion' => ['items' => $items, 'nuevo' => false, 'rechazos' => ['tenant'], 'acciones' => []],
            'plan' => [], 'lineas' => express_lineas_rechazo($items),
        ];
    }
    $actual = TenantEntitlements::normalizarEdicion($tenant);   // una edicion guardada que no es ni completa ni express se trata como completa y se avisa en el log
    $tenantDb = ($tenant['tipo'] ?? 'app') === 'app' ? express_conectar_tenant($tenant) : null;
    $hechos = express_preflight_hechos($master, $tenantDb, $tenant, $pedido);
    $evaluacion = express_preflight_evaluar($hechos);
    $plan = express_plan($hechos, $evaluacion);

    $modulos = ($pedido['modulos'] ?? null) === null ? [] : array_values((array) $pedido['modulos']);
    $lineas = [express_encabezado(
        $tenant,
        $tenantId,
        "edicion {$actual} -> express, tipos " . ((array) ($pedido['tipos'] ?? []) === [] ? '(ninguno)' : express_preflight_nombrar((array) $pedido['tipos']))
        . ($modulos === [] ? ', todos los modulos del preset.' : ', modulos ' . express_preflight_nombrar($modulos) . '.')
    )];
    $lineas = array_merge($lineas, express_lineas_evaluacion($evaluacion));
    $rechazos = count($evaluacion['rechazos']);
    if ($rechazos > 0) {
        $lineas[] = "RECHAZADO: {$rechazos} item(s). No se cambio nada.";
        return ['ok' => false, 'aplicado' => false, 'evaluacion' => $evaluacion, 'plan' => [], 'lineas' => $lineas];
    }
    $lineas = array_merge($lineas, express_lineas_plan($plan));
    if (!$apply) {
        $lineas[] = 'DRY-RUN: no se escribio nada. Agrega --apply para aplicar.';
        return ['ok' => true, 'aplicado' => false, 'evaluacion' => $evaluacion, 'plan' => $plan, 'lineas' => $lineas];
    }

    // Lo que el plan va a dejar sale del UPDATE de tenants del propio plan (express_plan): ahi estan sus parametros.
    $update = null;
    foreach ($plan as $paso) {
        if (($paso['donde'] ?? '') === 'master' && str_starts_with((string) ($paso['sql'] ?? ''), "UPDATE tenants SET edicion = 'express'")) {
            $update = $paso;
        }
    }
    if ($update === null) {
        throw new LogicException('El plan de la conversion no trae el UPDATE de tenants: no se puede armar la auditoria.');
    }
    $auditoria = express_auditoria($tenant, [
        'edicion' => 'express', 'tipos_ecf_habilitados' => $update['params'][':t'] ?? null, 'modulos_habilitados' => $update['params'][':m'] ?? null,
        'cert_vence' => $update['params'][':v'] ?? null, 'pos_enabled' => 0, 'inventario_estricto' => 0,
    ], "Conversion de la empresa #{$tenantId} a express (tipos " . ($update['params'][':t'] ?? '') . ')');
    express_aplicar($master, $tenantDb, $plan, $auditoria);
    $lineas[] = "APLICADO: el tenant #{$tenantId} ahora es express.";
    return ['ok' => true, 'aplicado' => true, 'evaluacion' => $evaluacion, 'plan' => $plan, 'lineas' => $lineas];
}

// ---------------------------------------------------------------------------------------------------------------------------------------------------
// La edicion 'ambas' (la app completa Y Express): una conversion corta, aparte de la de Express.
// ---------------------------------------------------------------------------------------------------------------------------------------------------
//
// Una empresa 'express' queda limitada a Express en TODAS sus peticiones, y por eso su conversion apaga el POS y el control de existencias, deja en
// blanco la credencial de integracion, resetea los permisos del rol user, borra rangos y clientes de prueba: todo eso es para que una empresa que
// solo vende por Express no tenga lo demas. Una 'ambas' sigue usando la app completa, y esas bajas le romperian la app que ya usa: su conversion NO
// las hace. Solo cambia la edicion, los tipos, los modulos y (si se puede leer el certificado) el vencimiento del certificado.

/**
 * Los hechos de una empresa que la evaluacion de 'ambas' necesita: la fila, las dos pruebas de esquema de la master (las cuatro columnas de la
 * 015 y el valor 'ambas' en el ENUM, de la 017), el certificado leido como lo hace la emision (solo para saber cert_vence) y el pedido. Solo SELECT,
 * y solo en la master: la base de la empresa no se abre.
 *
 * @param array $tenant la fila de master.tenants (SELECT *), tal cual
 * @param array $pedido ['edicion' => 'ambas', 'tipos' => list<string>, 'modulos' => ?list<string>]
 * @return array{tenant:array,columnas_edicion:bool,edicion_ambas:bool,cert:array{ok:bool,codigo:?string,vence:?int},pedido:array}
 */
function express_preflight_hechos_ambas(PDO $master, array $tenant, array $pedido): array
{
    return [
        'tenant' => $tenant,
        'columnas_edicion' => express_preflight_contar(
            $master,
            "SELECT COUNT(*) AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenants'
             AND COLUMN_NAME IN ('edicion', 'modulos_habilitados', 'tipos_ecf_habilitados', 'cert_vence')"
        ) === 4,
        'edicion_ambas' => express_preflight_contar(
            $master,
            "SELECT COUNT(*) AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenants'
             AND COLUMN_NAME = 'edicion' AND LOCATE('''ambas''', COLUMN_TYPE) > 0"
        ) === 1,
        'cert' => express_leer_certificado($tenant),
        'pedido' => $pedido,
    ];
}

/**
 * Evalua los hechos de una conversion a 'ambas' item por item. Es pura (no toca una base, ni el log, ni lo que recibe). Los items, en este orden:
 * tenant, columnas, migracion017, tipos, modulos, clientes_modulo, cert, edicion. Cada uno es ['estado' => 'ok'|'corregir'|'rechazar', 'detalle' => texto]
 * como los de express_preflight_evaluar, y las reglas de tipos, modulos y clientes_modulo son las mismas. A diferencia de Express, un certificado que
 * no se puede abrir NO rechaza (la app completa tampoco emitiria con el: es un aviso en el detalle) y ninguna condicion de la empresa (POS, existencias,
 * rangos, grupo, credencial de integracion, roles) rechaza ni se corrige: la conversion no las toca.
 *
 * sin_cambios es true si la empresa ya es ambas con esos mismos tipos y modulos y la fecha del certificado ya es la del archivo (o no se pudo leer):
 * no hay nada que escribir. Los rechazos y las acciones salen como en la evaluacion de Express.
 *
 * @param array $hechos lo que devuelve express_preflight_hechos_ambas (una clave que falte cuenta como el peor caso)
 * @return array{items:array<string,array{estado:string,detalle:string}>,nuevo:bool,rechazos:list<string>,acciones:list<string>,sin_cambios:bool}
 */
function express_preflight_evaluar_ambas(array $hechos): array
{
    $config = TenantEntitlements::config();
    $tenant = (array) ($hechos['tenant'] ?? []);
    $pedido = (array) ($hechos['pedido'] ?? []);
    $tipos = array_map('strval', array_values((array) ($pedido['tipos'] ?? [])));
    $modulos = ($pedido['modulos'] ?? null) === null ? null : array_map('strval', array_values((array) $pedido['modulos']));
    $cert = (array) ($hechos['cert'] ?? []);

    $ok = static fn (string $detalle): array => ['estado' => 'ok', 'detalle' => $detalle];
    $corregir = static fn (string $detalle): array => ['estado' => 'corregir', 'detalle' => $detalle];
    $rechazar = static fn (string $detalle): array => ['estado' => 'rechazar', 'detalle' => $detalle];
    $items = [];

    $items['tenant'] = ($tenant['tipo'] ?? 'app') === 'app' && (int) ($tenant['activo'] ?? 0) === 1
        ? $ok('La empresa es de tipo app y esta activa.')
        : $rechazar('La empresa tiene que ser de tipo app y estar activa (tipo ' . express_preflight_nombrar([$tenant['tipo'] ?? 'app'])
            . ', activo ' . (int) ($tenant['activo'] ?? 0) . ').');

    $items['columnas'] = ($hechos['columnas_edicion'] ?? false) === true
        ? $ok('Las cuatro columnas de la migracion master 015 existen en tenants.')
        : $rechazar('Faltan columnas de la migracion master 015: aplica db/master_migrations/015_tenants_edicion.sql en la master.');

    $items['migracion017'] = ($hechos['edicion_ambas'] ?? false) === true
        ? $ok("tenants.edicion acepta el valor 'ambas' (migracion master 017).")
        : $rechazar("Falta la migracion master 017: tenants.edicion no acepta el valor 'ambas'. Aplica db/master_migrations/017_tenants_edicion_ambas.sql en la master.");

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

    $vence = is_int($cert['vence'] ?? null) ? date('Y-m-d', $cert['vence']) : null;
    if (($cert['ok'] ?? false) === true && ($cert['codigo'] ?? null) === null) {
        $items['cert'] = $ok("Certificado propio legible, con su clave y vigente hasta {$vence}: cert_vence se escribe con esa fecha.");
    } elseif (($cert['codigo'] ?? null) === 'CERT_VENCIDO' && $vence !== null) {
        $items['cert'] = $ok("Aviso: CERT_VENCIDO, el certificado de la empresa ya vencio el {$vence}. No frena la conversion (la app completa tampoco emitiria con el); "
            . 'cert_vence se escribe con esa fecha. Renuevalo con tools/set_tenant_cert.php.');
    } else {
        $items['cert'] = $ok('Aviso: CERT_FALTA, la empresa no tiene un certificado propio que se pueda abrir desde aqui (cert_path vacio, archivo que no se lee, o clave que '
            . 'falta o no lo abre). No frena la conversion; cert_vence queda como esta. Cargalo con tools/set_tenant_cert.php.');
    }
    $vence = ($cert['ok'] ?? false) === true || ($cert['codigo'] ?? null) === 'CERT_VENCIDO' ? $vence : null;

    $actual = TenantEntitlements::normalizarEdicion($tenant);
    $mismosTipos = TenantEntitlements::csv(isset($tenant['tipos_ecf_habilitados']) ? (string) $tenant['tipos_ecf_habilitados'] : null) === $tipos;
    $mismosModulos = TenantEntitlements::csv(isset($tenant['modulos_habilitados']) ? (string) $tenant['modulos_habilitados'] : null) === ($modulos ?? []);
    $mismaFecha = $vence === null || (isset($tenant['cert_vence']) && substr((string) $tenant['cert_vence'], 0, 10) === $vence);
    $sinCambios = $actual === 'ambas' && (string) ($tenant['edicion'] ?? '') === 'ambas' && $mismosTipos && $mismosModulos && $mismaFecha;
    if ($sinCambios) {
        $items['edicion'] = $ok('La empresa ya es ambas con esos tipos y esos modulos.');
    } elseif ($actual === 'ambas') {
        $items['edicion'] = $corregir('La empresa ya es ambas: se actualizan sus tipos, modulos o la fecha del certificado.');
    } else {
        $items['edicion'] = $corregir("La edicion pasa de {$actual} a ambas. El POS, el control de existencias, la credencial de integracion y los permisos de los roles se quedan como estan.");
    }

    return [
        'items' => $items,
        'nuevo' => false,
        'rechazos' => array_keys(array_filter($items, static fn (array $item): bool => $item['estado'] === 'rechazar')),
        'acciones' => array_keys(array_filter($items, static fn (array $item): bool => $item['estado'] === 'corregir')),
        'sin_cambios' => $sinCambios,
    ];
}

/**
 * El plan de una conversion a 'ambas': UN paso en la master, o [] si la evaluacion rechaza algo o no hay nada que cambiar. Es pura. El UPDATE pone
 * edicion = 'ambas', los tipos y los modulos (NULL si no se pidio lista) y, solo si el certificado se pudo leer, cert_vence; no toca ninguna otra
 * columna ni ninguna otra tabla. Todo valor va en parametros.
 *
 * @return list<array{donde:string,que:string,sql:string,params:array<string,mixed>}>
 */
function express_plan_ambas(array $hechos, array $evaluacion): array
{
    $hayRechazo = ($evaluacion['rechazos'] ?? []) !== []
        || array_filter((array) ($evaluacion['items'] ?? []), static fn ($item): bool => ($item['estado'] ?? null) === 'rechazar') !== [];
    if ($hayRechazo || ($evaluacion['sin_cambios'] ?? false) === true) {
        return [];
    }
    $pedido = (array) ($hechos['pedido'] ?? []);
    $cert = (array) ($hechos['cert'] ?? []);
    $vence = is_int($cert['vence'] ?? null) && (($cert['ok'] ?? false) === true || ($cert['codigo'] ?? null) === 'CERT_VENCIDO') ? date('Y-m-d', $cert['vence']) : null;
    $modulos = array_values(array_unique(array_map('strval', (array) ($pedido['modulos'] ?? []))));
    $params = [
        ':t' => implode(',', array_values(array_unique(array_map('strval', (array) ($pedido['tipos'] ?? []))))),
        ':m' => $modulos === [] ? null : implode(',', $modulos),
        ':id' => (int) (((array) ($hechos['tenant'] ?? []))['id'] ?? 0),
    ];
    if ($vence !== null) {
        $params[':v'] = $vence;   // DATE: el dia del notAfter en la zona de PHP, como lo lee estadoCertificado
    }
    return [[
        'donde' => 'master',
        'que' => 'Pasar la empresa a ambas (la app completa y Express) con sus tipos' . ($vence !== null ? ', modulos y el vencimiento de su certificado' : ' y modulos')
            . '; no se toca nada mas',
        'sql' => "UPDATE tenants SET edicion = 'ambas', tipos_ecf_habilitados = :t, modulos_habilitados = :m" . ($vence !== null ? ', cert_vence = :v' : '') . " WHERE id = :id AND tipo = 'app'",
        'params' => $params,
    ]];
}

/**
 * Pasa una empresa a 'ambas', o dice por que no: hechos, evaluacion y plan cortos (express_preflight_hechos_ambas, express_preflight_evaluar_ambas y
 * express_plan_ambas) y, con $apply y sin rechazos, express_aplicar con su fila de auditoria (la misma EDICION_CAMBIO de las demas ordenes; la
 * base de la empresa no se abre). Sin $apply solo lee, y solo en la master.
 *
 * El informe: el encabezado, un renglon por item ([OK  ], [CORR] o [RECH]) y, segun el caso, "RECHAZADO: <n> item(s). No se cambio nada.", "SIN CAMBIOS: ...",
 * los pasos del plan y "DRY-RUN: no se escribio nada. Agrega --apply para aplicar." o "APLICADO: el tenant #<id> ahora es ambas.".
 *
 * @param array $pedido ['edicion' => 'ambas', 'tipos' => list<string>, 'modulos' => ?list<string>] (otra edicion lanza InvalidArgumentException)
 * @return array{ok:bool,aplicado:bool,evaluacion:array,plan:list<array>,lineas:list<string>}
 */
function express_convertir_ambas(PDO $master, int $tenantId, array $pedido, bool $apply): array
{
    $edicionPedida = $pedido['edicion'] ?? 'ambas';
    if ($edicionPedida !== 'ambas') {
        throw new InvalidArgumentException('express_convertir_ambas solo convierte a ambas (el pedido trae la edicion ' . express_preflight_nombrar([(string) $edicionPedida])
            . '): para Express esta express_convertir y para volver a completa express_revertir.');
    }
    $tenant = express_tenant_fila($master, $tenantId);
    if ($tenant === null) {
        $items = express_item_sin_empresa($tenantId);
        return [
            'ok' => false, 'aplicado' => false,
            'evaluacion' => ['items' => $items, 'nuevo' => false, 'rechazos' => ['tenant'], 'acciones' => [], 'sin_cambios' => false],
            'plan' => [], 'lineas' => express_lineas_rechazo($items),
        ];
    }
    $actual = TenantEntitlements::normalizarEdicion($tenant);   // una edicion guardada que no es ni completa, express ni ambas se trata como completa y se avisa en el log
    $hechos = express_preflight_hechos_ambas($master, $tenant, $pedido);
    $evaluacion = express_preflight_evaluar_ambas($hechos);
    $plan = express_plan_ambas($hechos, $evaluacion);

    $modulos = ($pedido['modulos'] ?? null) === null ? [] : array_values((array) $pedido['modulos']);
    $lineas = [express_encabezado(
        $tenant,
        $tenantId,
        "edicion {$actual} -> ambas, tipos " . ((array) ($pedido['tipos'] ?? []) === [] ? '(ninguno)' : express_preflight_nombrar((array) $pedido['tipos']))
        . ($modulos === [] ? ', todos los modulos del preset.' : ', modulos ' . express_preflight_nombrar($modulos) . '.')
    )];
    $lineas = array_merge($lineas, express_lineas_evaluacion($evaluacion));
    $rechazos = count($evaluacion['rechazos']);
    if ($rechazos > 0) {
        $lineas[] = "RECHAZADO: {$rechazos} item(s). No se cambio nada.";
        return ['ok' => false, 'aplicado' => false, 'evaluacion' => $evaluacion, 'plan' => [], 'lineas' => $lineas];
    }
    if ($evaluacion['sin_cambios']) {
        $lineas[] = "SIN CAMBIOS: el tenant #{$tenantId} ya es ambas con esos tipos y modulos. No se escribio nada.";
        return ['ok' => true, 'aplicado' => false, 'evaluacion' => $evaluacion, 'plan' => [], 'lineas' => $lineas];
    }
    $lineas = array_merge($lineas, express_lineas_plan($plan));
    if (!$apply) {
        $lineas[] = 'DRY-RUN: no se escribio nada. Agrega --apply para aplicar.';
        return ['ok' => true, 'aplicado' => false, 'evaluacion' => $evaluacion, 'plan' => $plan, 'lineas' => $lineas];
    }

    // Lo que el plan va a dejar sale de los parametros de su UPDATE. cert_vence solo cambia si el plan lo escribe: si no, queda lo que la fila tiene.
    $params = $plan[0]['params'];
    $cambios = ['edicion' => 'ambas', 'tipos_ecf_habilitados' => $params[':t'], 'modulos_habilitados' => $params[':m']];
    if (array_key_exists(':v', $params)) {
        $cambios['cert_vence'] = $params[':v'];
    }
    express_aplicar($master, null, $plan, express_auditoria($tenant, $cambios, "Conversion de la empresa #{$tenantId} a ambas (tipos {$params[':t']})"));
    $lineas[] = "APLICADO: el tenant #{$tenantId} ahora es ambas.";
    return ['ok' => true, 'aplicado' => true, 'evaluacion' => $evaluacion, 'plan' => $plan, 'lineas' => $lineas];
}

/**
 * El hash de integracion que una conversion dejo en blanco y la bitacora guardo: el integracion_hash_anterior de old_values de la fila de
 * auditoria EDICION_CAMBIO mas nueva de la empresa que trae uno, o null si ninguna lo trae. Solo cuenta un sha256 en hexadecimal (el que guarda
 * TenantResolver::hashSecret): una fila sin hash (la de una conversion o reversion con la credencial ya en blanco), con un valor redactado o
 * roto, o un JSON que no se lee, se salta y se sigue con la anterior. Mira las 50 mas nuevas.
 */
function express_hash_anterior(PDO $master, int $tenantId): ?string
{
    $filas = express_preflight_filas(
        $master,
        'SELECT old_values FROM audit_logs WHERE tenant_id = :t AND module = :m AND action = :a ORDER BY id DESC LIMIT 50',
        [':t' => $tenantId, ':m' => EXPRESS_AUDITORIA_MODULO, ':a' => EXPRESS_AUDITORIA_ACCION]
    );
    foreach ($filas as $fila) {
        $valores = json_decode((string) ($fila['old_values'] ?? ''), true);
        $hash = is_array($valores) ? ($valores['integracion_hash_anterior'] ?? null) : null;
        if (is_string($hash) && preg_match('/^[0-9a-f]{64}$/D', $hash) === 1) {
            return $hash;
        }
    }
    return null;
}

/**
 * Devuelve una empresa a completa, o dice por que no. Cambia SOLO la edicion (a completa) y, si el hash de integracion de la empresa esta en
 * blanco y la bitacora guarda el anterior (express_hash_anterior), lo restaura: nada mas se revierte (los tipos, los modulos, el vencimiento del
 * certificado, el POS, el control de existencias y los permisos del rol user se quedan como estan).
 *
 * Volver a completa levanta TODA restriccion de Express y no es una parada segura (anexo 14.5): el informe lo dice. Las palancas de verdad son
 * dejar vacio tipos_ecf_habilitados (se niega toda emision) y revocar los tokens de la empresa.
 *
 * Una empresa que no existe o no es de tipo app, o una master sin la columna edicion, sale rechazada. Una que ya es completa (el valor guardado
 * exacto) y no tiene hash que restaurar da ok sin aplicar nada y sin fila de auditoria ("SIN CAMBIOS"); una edicion guardada que no es ni
 * completa ni express se trata como completa (se avisa una vez en el log) y se reescribe como completa.
 *
 * Informe: el encabezado, un renglon por item, los pasos, el aviso y "DRY-RUN: ..." o "APLICADO: el tenant #<id> ahora es completa.".
 *
 * @return array{ok:bool,aplicado:bool,plan:list<array>,lineas:list<string>}
 */
function express_revertir(PDO $master, int $tenantId, bool $apply): array
{
    $tenant = express_tenant_fila($master, $tenantId);
    if ($tenant === null) {
        return ['ok' => false, 'aplicado' => false, 'plan' => [], 'lineas' => express_lineas_rechazo(express_item_sin_empresa($tenantId))];
    }
    $rechazo = static fn (string $id, string $detalle): array => [
        'ok' => false, 'aplicado' => false, 'plan' => [], 'lineas' => express_lineas_rechazo([$id => ['estado' => 'rechazar', 'detalle' => $detalle]]),
    ];
    if (($tenant['tipo'] ?? 'app') !== 'app') {
        return $rechazo('tenant', "La empresa tiene que ser de tipo app (es tipo " . express_preflight_nombrar([(string) ($tenant['tipo'] ?? '')]) . '): una empresa de integracion no tiene edicion que revertir.');
    }
    if (!array_key_exists('edicion', $tenant)) {
        return $rechazo('columnas', 'Faltan columnas de la migracion master 015: aplica db/master_migrations/015_tenants_edicion.sql en la master.');
    }
    TenantEntitlements::normalizarEdicion($tenant);   // una edicion guardada que no es ni completa ni express se trata como completa y se avisa en el log
    $guardada = (string) $tenant['edicion'];
    $yaCompleta = $guardada === 'completa';
    $hash = (string) ($tenant['api_secret_hash'] ?? '');
    $anterior = $hash === '' ? express_hash_anterior($master, $tenantId) : null;

    $items = ['tenant' => ['estado' => 'ok', 'detalle' => 'La empresa es de tipo app.']];
    if ($yaCompleta) {
        $items['edicion'] = ['estado' => 'ok', 'detalle' => 'La edicion ya es completa.'];
    } elseif (in_array($guardada, ['express', 'ambas', 'completa'], true)) {
        $items['edicion'] = ['estado' => 'corregir', 'detalle' => "La edicion pasa de {$guardada} a completa."];
    } else {
        $items['edicion'] = ['estado' => 'corregir', 'detalle' => 'La edicion guardada (' . express_valor_visible($guardada) . ') no es valida y se trata como completa: se reescribe como completa.'];
    }
    if ($hash !== '') {
        $items['integracion'] = ['estado' => 'ok', 'detalle' => 'La credencial de integracion ya tiene su hash: no se toca.'];
    } elseif ($anterior !== null) {
        $items['integracion'] = ['estado' => 'corregir', 'detalle' => 'api_secret_hash esta en blanco: se restaura el hash anterior que guardo la bitacora (el valor no se imprime).'];
    } else {
        $items['integracion'] = ['estado' => 'ok', 'detalle' => 'api_secret_hash esta en blanco y la bitacora no trae un hash anterior valido: no hay nada que restaurar (la credencial de integracion sigue sin servir).'];
    }

    $plan = [];
    if (!$yaCompleta) {
        $plan[] = [
            'donde' => 'master', 'que' => 'Pasar la empresa a completa (solo la edicion)',
            'sql' => "UPDATE tenants SET edicion = 'completa' WHERE id = :id AND tipo = 'app'", 'params' => [':id' => $tenantId],
        ];
    }
    if ($anterior !== null) {
        $plan[] = [
            'donde' => 'master', 'que' => 'Restaurar api_secret_hash con el hash anterior de la bitacora (solo si sigue en blanco)',
            'sql' => "UPDATE tenants SET api_secret_hash = :h WHERE id = :id AND tipo = 'app' AND api_secret_hash = ''", 'params' => [':h' => $anterior, ':id' => $tenantId],
        ];
    }
    $lineas = array_merge(
        [express_encabezado($tenant, $tenantId, 'volver a completa.')],
        express_lineas_evaluacion(['items' => $items])
    );
    if ($plan === []) {
        $lineas[] = "SIN CAMBIOS: el tenant #{$tenantId} ya es completa. No se escribio nada.";
        return ['ok' => true, 'aplicado' => false, 'plan' => [], 'lineas' => $lineas];
    }
    $lineas = array_merge($lineas, express_lineas_plan($plan), [
        $guardada === 'ambas'
            ? 'ADVERTENCIA: volver a completa le quita a la empresa el acceso a Express (sus sesiones de Express dejan de traer el bloque de la edicion y la pantalla dice que la cuenta no esta habilitada); '
                . 'la app completa no cambia. No es una parada segura de Express: para frenar sus facturas deja vacio tipos_ecf_habilitados (se niega toda emision) o revoca los tokens de la empresa.'
            : 'ADVERTENCIA: volver a completa levanta TODAS las restricciones de Express (modulos, tipos, rutas y vencimiento de las sesiones por inactividad) y no es una parada segura. '
                . 'Para frenar a una empresa Express hay otras palancas: dejar vacio tipos_ecf_habilitados (se niega toda emision) o revocar los tokens de la empresa.',
        'Esta orden solo cambia la edicion (y devuelve api_secret_hash si esta en blanco): tipos, modulos, vencimiento del certificado, POS, control de existencias y permisos del rol user se quedan como estan.',
    ]);
    if (!$apply) {
        $lineas[] = 'DRY-RUN: no se escribio nada. Agrega --apply para aplicar.';
        return ['ok' => true, 'aplicado' => false, 'plan' => $plan, 'lineas' => $lineas];
    }
    express_aplicar($master, null, $plan, express_auditoria(
        $tenant,
        ['edicion' => 'completa'],
        "Reversion de la empresa #{$tenantId} a completa" . ($anterior !== null ? ' (api_secret_hash restaurado desde la bitacora)' : '')
    ));
    $lineas[] = "APLICADO: el tenant #{$tenantId} ahora es completa.";
    return ['ok' => true, 'aplicado' => true, 'plan' => $plan, 'lineas' => $lineas];
}

/**
 * Recalcula tenants.cert_vence de una empresa Express desde su certificado (express_leer_certificado: el cert_path de la fila, sin respaldo con
 * el del .env) y lo escribe, o dice por que no. Sirve cuando se renueva el certificado: la fecha de la fila es solo informativa (GET /api/emisor
 * la muestra en Configuracion) y no bloquea nada, pero tiene que seguir al archivo. La fecha es el dia del notAfter en la zona horaria por
 * defecto de PHP, como la escribe express_plan y la lee TenantEntitlements::estadoCertificado.
 *
 * Una empresa que no existe, o que no es Express (tipo app y edicion express o ambas), sale rechazada; tambien una cuyo certificado no se puede abrir
 * (CERT_FALTA: sin fecha que escribir, y borrar la que hay seria peor). Uno que abre pero ya vencio (CERT_VENCIDO) SI se escribe: su notAfter es
 * la verdad y el aviso de Configuracion debe decir que vencio. Con la fecha ya al dia da ok sin escribir ("SIN CAMBIOS").
 *
 * Escribe un solo UPDATE (donde la empresa sigue siendo Express) y relee la fila: si la fecha no quedo, lanza RuntimeException. NO escribe fila
 * de auditoria: no cambia la edicion ni nada que decida una peticion (EDICION_CAMBIO es de los cambios de edicion).
 *
 * @return array{ok:bool,aplicado:bool,plan:list<array>,lineas:list<string>}
 */
function express_refrescar_cert(PDO $master, int $tenantId, bool $apply): array
{
    $tenant = express_tenant_fila($master, $tenantId);
    if ($tenant === null) {
        return ['ok' => false, 'aplicado' => false, 'plan' => [], 'lineas' => express_lineas_rechazo(express_item_sin_empresa($tenantId))];
    }
    $rechazo = static fn (array $items): array => ['ok' => false, 'aplicado' => false, 'plan' => [], 'lineas' => express_lineas_rechazo($items)];
    if (!TenantEntitlements::tieneExpress($tenant)) {   // express o ambas: la linea de comandos no trae la cabecera de Express, asi que no sirve esRestringido
        return $rechazo(['edicion' => ['estado' => 'rechazar', 'detalle' => '--refrescar-cert es solo para empresas Express (tipo app y edicion express o ambas): esta empresa es de tipo '
            . express_preflight_nombrar([(string) ($tenant['tipo'] ?? '')]) . ' y su edicion ' . express_valor_visible(isset($tenant['edicion']) ? (string) $tenant['edicion'] : null) . '.']]);
    }
    $cert = express_leer_certificado($tenant);
    if (!is_int($cert['vence'])) {
        return $rechazo(['edicion' => ['estado' => 'ok', 'detalle' => 'La empresa es Express.'], 'cert' => ['estado' => 'rechazar',
            'detalle' => 'CERT_FALTA: la empresa no tiene un certificado propio que se pueda abrir (cert_path vacio, archivo que no se lee, o clave que falta o no lo abre): no hay fecha que calcular y la que tiene no se borra. Cargalo con tools/set_tenant_cert.php.']]);
    }
    $nueva = date('Y-m-d', $cert['vence']);
    $actual = ($tenant['cert_vence'] ?? null) === null ? null : (string) $tenant['cert_vence'];
    $items = [
        'edicion' => ['estado' => 'ok', 'detalle' => 'La empresa es Express.'],
        'cert' => ['estado' => 'ok', 'detalle' => $cert['ok']
            ? "Certificado propio legible, con su clave y vigente hasta {$nueva}."
            : "CERT_VENCIDO: el certificado abre pero ya vencio el {$nueva}: se registra esa fecha (es solo informativa)."],
        'cert_vence' => $actual === $nueva
            ? ['estado' => 'ok', 'detalle' => "cert_vence ya es {$nueva}."]
            : ['estado' => 'corregir', 'detalle' => 'cert_vence pasa de ' . express_valor_visible($actual) . ' a ' . express_valor_visible($nueva) . '.'],
    ];
    $lineas = array_merge([express_encabezado($tenant, $tenantId, 'refrescar el vencimiento del certificado.')], express_lineas_evaluacion(['items' => $items]));
    if ($actual === $nueva) {
        $lineas[] = "SIN CAMBIOS: cert_vence del tenant #{$tenantId} ya es {$nueva}. No se escribio nada.";
        return ['ok' => true, 'aplicado' => false, 'plan' => [], 'lineas' => $lineas];
    }
    $plan = [[
        'donde' => 'master', 'que' => 'Escribir cert_vence con el dia del notAfter del certificado',
        'sql' => "UPDATE tenants SET cert_vence = :v WHERE id = :id AND tipo = 'app' AND edicion IN ('express', 'ambas')", 'params' => [':v' => $nueva, ':id' => $tenantId],
    ]];
    $lineas = array_merge($lineas, express_lineas_plan($plan, false));   // esta orden no escribe fila de auditoria
    if (!$apply) {
        $lineas[] = 'DRY-RUN: no se escribio nada. Agrega --apply para aplicar.';
        return ['ok' => true, 'aplicado' => false, 'plan' => $plan, 'lineas' => $lineas];
    }
    express_ejecutar($master, $plan[0]['sql'], $plan[0]['params']);
    $difieren = express_diferencias(['cert_vence' => $nueva] + express_estado_auditado($tenant), express_leer_estado($master, $tenantId));
    if ($difieren !== []) {
        throw new RuntimeException("El UPDATE de cert_vence del tenant #{$tenantId} no dejo lo esperado al releerlo: " . implode('; ', $difieren) . '.');
    }
    $lineas[] = "APLICADO: cert_vence del tenant #{$tenantId} ahora es {$nueva}.";
    return ['ok' => true, 'aplicado' => true, 'plan' => $plan, 'lineas' => $lineas];
}
