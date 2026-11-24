<?php
// Datos fiscales del emisor (Representacion Impresa / e-CF).
// Ruta: /api/emisor (requiere token)
//   GET /api/emisor -> emisor_config del tenant (fila unica id=1); una empresa de la edicion Express recibe ademas
//                      data.certificado (estado del certificado, solo informativo)
//   PUT /api/emisor -> el administrador cambia los datos de su empresa (Configuracion > Datos de empresa):
//                      {nombre_comercial?, sucursal?, direccion?, municipio?, provincia?, telefono?, correo?, website?,
//                      actividad_economica?}. Parcial: solo se mira lo que viene. El RNC y la razon social NO se editan
//                      (solo soporte): si el cuerpo los trae distintos de los guardados, 422. Responde lo mismo que el
//                      GET, ya con los datos nuevos. Reglas y limites (los del XSD de la DGII): src/Utils/EmisorEdicion.php;
//                      permisos y bitacora: docs/modules/emisor.md.
//
// Tenants tipo "integracion" no tienen DB propia (no hay emisor_config):
// se responde con los datos del registro del tenant en master (nombre, rnc),
// igual que hace la recepcion DGII (ver ecfRecepcionController).
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-API-KEY, X-API-SECRET, Authorization, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method');
header('Access-Control-Allow-Methods: GET, PUT, OPTIONS');
header('Allow: GET, PUT, OPTIONS');
header('content-type: application/json; charset=utf-8');

require_once __DIR__ . '/../Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../Models/EmisorConfigModel.php';

$auth = new AuthMiddleware();
if ($_SERVER['REQUEST_METHOD'] !== 'OPTIONS') {
    $validation = $auth->validateRequest();
    if (!$validation['valid']) {
        $auth->sendUnauthorized($validation['message']);
    }
}

function emisorRespond(bool $ok, $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($ok ? ['status' => true, 'data' => $payload] : ['status' => false, 'error' => $payload]);
    exit;
}

/**
 * ¿Este rol puede cambiar los datos de la empresa? El rol de sistema 'admin' (por nombre, como la
 * bitacora y el bloqueo de ventas sin existencia) y cualquier otro rol al que el administrador le
 * haya dado el modulo 'emisor' (el mismo que el gate pide para todo lo que no sea GET). Se exige
 * aunque PERMISSIONS_ENFORCE este en modo sombra: son datos que viajan dentro de cada e-CF.
 */
function emisorRolPuedeEditar(string $rol, ?int $tenantId): bool
{
    if (strtolower($rol) === 'admin') {
        return true;
    }
    require_once __DIR__ . '/../PermissionGate.php';
    $perms = (new RoleModel())->getPermissionsForRole($tenantId, $rol);
    return PermissionGate::permMatches($perms, 'emisor');
}

/** Corta la peticion si quien pide no es un usuario con permiso para cambiar los datos de la empresa. */
function emisorExigirPermiso(array $validation): void
{
    // Una credencial de maquina (integracion) no es un usuario: no edita nada de la empresa.
    if (($validation['user_id'] ?? null) === null) {
        emisorRespond(false, 'Para hacer esto necesitas iniciar sesión con tu usuario.', 403);
    }
    $rol = (string) ($validation['role'] ?? '');
    $tenantId = isset($validation['tenant_id']) ? (int) $validation['tenant_id'] : null;
    if (emisorRolPuedeEditar($rol, $tenantId)) {
        return;
    }
    require_once __DIR__ . '/../Middleware/AuditMiddleware.php';
    AuditMiddleware::logAccessDenied(
        'emisor',
        'rol sin el módulo emisor',
        ['ruta' => 'emisor', 'metodo' => 'PUT', 'rol' => $rol, 'bloqueado' => true],
        'Acceso denegado a los datos de la empresa: solo el administrador puede cambiarlos.'
    );
    emisorRespond(false, 'Solo el administrador de la empresa puede cambiar estos datos.', 403);
}

/**
 * Catalogo DGII de provincias y municipios (vive en la master) como consulta por codigo, o null si no se
 * pudo leer: sin catalogo solo se exige el formato de 6 digitos (igual que provinciaMunicipioModel::isValid,
 * que no bloquea si no lo lee; la validacion final es del XSD de la DGII).
 */
function emisorCatalogo(): ?callable
{
    require_once __DIR__ . '/../Models/provinciaMunicipioModel.php';
    try {
        $filas = (new provinciaMunicipioModel())->all();
    } catch (Throwable $e) {
        error_log('[emisor] catalogo de ubicaciones no disponible: ' . $e->getMessage());
        return null;
    }
    if ($filas === []) {
        return null;
    }
    $porCodigo = [];
    foreach ($filas as $fila) {
        $porCodigo[(string) $fila['codigo']] = $fila;
    }
    return static fn (string $codigo): ?array => $porCodigo[$codigo] ?? null;
}

/**
 * PUT /api/emisor: valida, guarda y deja en la bitacora lo que cambio. Corta la peticion (exit) ante cualquier
 * problema; si guarda, devuelve la fila ya guardada y el resto del archivo responde como un GET.
 */
function emisorGuardar(EmisorConfigModel $modelo, array $actual): array
{
    require_once __DIR__ . '/../Utils/InputSanitizer.php';
    require_once __DIR__ . '/../Utils/EmisorEdicion.php';
    require_once __DIR__ . '/../AuditLogger.php';

    $cuerpo = InputSanitizer::jsonInput();
    if (!is_array($cuerpo)) {
        emisorRespond(false, 'No se pudieron leer los datos. Recarga la página e inténtalo de nuevo.', 400);
    }

    // El catalogo solo se consulta si el cuerpo toca la provincia o el municipio.
    $catalogo = array_key_exists('provincia', $cuerpo) || array_key_exists('municipio', $cuerpo) ? emisorCatalogo() : null;
    $r = EmisorEdicion::validar($cuerpo, $actual, $catalogo);
    if ($r['errores'] !== []) {
        http_response_code(422);
        echo json_encode(['status' => false, 'error' => reset($r['errores']), 'campos' => $r['errores']]);
        exit;
    }
    if ($r['cambios'] === []) {
        emisorRespond(false, EmisorEdicion::MSG_SIN_CAMBIOS, 422);
    }

    try {
        $fila = $modelo->update($r['cambios']);
    } catch (Throwable $e) {
        // El texto de la excepcion (SQL, columnas) no es para la pantalla: queda en el log.
        error_log('[emisor] no se pudo guardar emisor_config: ' . $e->getMessage());
        emisorRespond(false, 'No se pudieron guardar los datos. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.', 500);
    }

    AuditLogger::log([
        'module' => 'emisor', 'action' => 'UPDATE',
        'entity_type' => 'emisor_config', 'entity_id' => 1,
        'old_values' => array_intersect_key($actual, $r['cambios']),
        'new_values' => $r['cambios'],
        'description' => 'Datos de la empresa actualizados: ' . EmisorEdicion::describir(array_keys($r['cambios'])) . '.',
    ]);
    return $fila;
}

$metodo = $_SERVER['REQUEST_METHOD'];
if ($metodo !== 'GET' && $metodo !== 'PUT') {
    emisorRespond(false, 'Metodo no soportado', 405);
}

$tenant = class_exists('TenantResolver') ? TenantResolver::current() : null;

// Integracion: sin DB propia -> datos del registro del tenant en master.
if ($tenant && ($tenant['tipo'] ?? 'app') === 'integracion') {
    if ($metodo === 'PUT') {
        emisorRespond(false, 'Los datos de esta empresa los administra soporte.', 409);
    }
    emisorRespond(true, [
        'rnc'          => $tenant['rnc'] ?? null,
        'razon_social' => $tenant['nombre'] ?? null,
        'ambiente'     => $tenant['ambiente'] ?? null,
        'fuente'       => 'tenant',
    ]);
}

if ($metodo === 'PUT') {
    emisorExigirPermiso($validation);
}

try {
    $modelo = new EmisorConfigModel();
    $emisor = $modelo->get();
} catch (Throwable $e) {
    // El texto de la excepcion (SQLSTATE, conexion al DB del tenant) no es para
    // la pantalla de Configuracion: queda en el log.
    error_log('[emisor] no se pudo leer emisor_config: ' . $e->getMessage());
    emisorRespond(false, 'No se pudieron cargar los datos de tu empresa. Inténtalo de nuevo en unos minutos.', 500);
}

if (!$emisor) {
    emisorRespond(false, 'Tu empresa todavía no tiene sus datos fiscales configurados. Comunícate con soporte para completarlos.', 404);
}

if ($metodo === 'PUT') {
    $emisor = emisorGuardar($modelo, $emisor);
}

unset($emisor['id']); // fila unica, el id no aporta
// Ambiente del tenant (per-tenant, no el env global) cuando aplica.
if ($tenant && isset($tenant['ambiente'])) {
    $emisor['ambiente'] = $tenant['ambiente'];
}
$emisor['fuente'] = 'emisor_config';

// Fiscalpoint Express: el estado del certificado (vigente, por_vencer, vencido, no_configurado o sin_dato), solo informativo y
// solo para una empresa de esa edicion (una 'ambas' la recibe unicamente en las peticiones que se anuncian como Express). La prueba va
// en linea para que ninguna otra empresa cargue TenantEntitlements; si la clase no carga, la clave se omite: el aviso es un extra y
// Configuracion sigue leyendo los datos del emisor.
if (TenantResolver::esFilaExpress($tenant) && TenantResolver::cargarEntitlements()) {
    $emisor['certificado'] = TenantEntitlements::estadoCertificado($tenant);
}

emisorRespond(true, $emisor);
