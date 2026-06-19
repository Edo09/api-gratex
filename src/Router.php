<?php
/**
 * Simple router to handle different API endpoints
 * Routes:
 *   /api/auth/* -> Auth endpoints (token generation, management)
 *   /api/users/* -> User CRUD endpoints (requires token)
 */

// Cargar .env al inicio de CADA request, antes de instanciar cualquier model.
// Critico para multi-tenant: authModel/LandingModel/etc leen MULTI_TENANT_ENABLED
// en su constructor; si el .env no esta cargado aun, creerian que es single-tenant
// y consultarian la DB equivocada (login -> tenant DB, validacion -> master).
require_once __DIR__ . '/Database.php';
Database::loadEnv();

// Handle CORS at the router level FIRST, before any other logic
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-API-KEY, X-API-SECRET, Authorization, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE');
header('Access-Control-Max-Age: 86400'); // Cache preflight for 24 hours
header('Content-Type: application/json; charset=utf-8');

// Handle OPTIONS request immediately (preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$request_uri = $_SERVER['REQUEST_URI'];
$request_method = $_SERVER['REQUEST_METHOD'];

// Remove base path if needed and parse endpoint
$endpoint = parse_url($request_uri, PHP_URL_PATH);

// Serve API documentation
$is_root = $endpoint === '/' || 
$endpoint === '/index.php' || 
str_ends_with($endpoint, '/index.php') || 
str_ends_with($endpoint, '/');

if ($is_root || str_ends_with($endpoint, '/docs') || str_ends_with($endpoint, '/docs/') || str_ends_with($endpoint, '/api/docs')) {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/../public/docs.html');
    exit;
}

// Extract route using the FIRST /api/ occurrence so paths like
// /api/ecf/autenticacion/fe/autenticacion/api/semilla (DGII callbacks)
// don't get mis-routed by the second /api/ segment.
$apiPos = strpos($endpoint, '/api/');
$route_part = $apiPos !== false ? substr($endpoint, $apiPos + 5) : ltrim($endpoint, '/');
$route_segments = explode('/', ltrim($route_part, '/'));
$route = $route_segments[0] ?? 'default';

// Control de acceso central (RBAC) + pre-resolucion de tenant. El gate:
//  - resuelve el tenant ANTES de incluir el controller (como la pre-resolucion
//    previa) para que los models se aten a la DB correcta. Muchos controllers
//    instancian sus models en el tope del archivo (antes de su propio
//    validateRequest) y el model fija la conexion en el constructor.
//  - aplica permisos por ruta+metodo (fail-closed) cuando PERMISSIONS_ENFORCE=true;
//    en modo sombra (default) solo registra en error_log lo que se denegaria.
// En deny+enforce el gate responde y corta (exit). Errores inesperados se
// loguean y NO rompen el ruteo (el controller hara su propia validacion).
require_once __DIR__ . '/PermissionGate.php';
try {
    PermissionGate::enforce($route, $request_method);
} catch (Throwable $e) {
    error_log('[Router] PermissionGate fallo: ' . $e->getMessage());
}

// Auditoria: AuditLogger queda disponible para TODOS los controllers (incluido
// una sola vez aqui, asi ninguno necesita su propio require). AuditMiddleware
// deja el contexto del request listo (no-op perezoso). Nunca rompe el ruteo.
require_once __DIR__ . '/AuditLogger.php';
require_once __DIR__ . '/Middleware/AuditMiddleware.php';
try {
    AuditMiddleware::boot();
} catch (Throwable $e) {
    error_log('[Router] AuditMiddleware fallo: ' . $e->getMessage());
}

// Route to appropriate controller
switch ($route) {
    case 'auth':
        // Auth endpoints - no token required
        require_once 'src/Controllers/authController.php';
        break;

    case 'users':
        // User CRUD endpoints - token required
        require_once 'src/Controllers/userController.php';
        break;

    case 'clients':
        // Client CRUD endpoints - token required
        require_once 'src/Controllers/clientController.php';
        break;

    case 'products':
        // Product catalog CRUD endpoints - token required
        require_once 'src/Controllers/productController.php';
        break;

    case 'categories':
        // Inventario: categorias CRUD - token required (modulo 'categories')
        require_once 'src/Controllers/categoryController.php';
        break;

    case 'warehouses':
        // Inventario: almacenes CRUD - token required (modulo 'warehouses')
        require_once 'src/Controllers/warehouseController.php';
        break;

    case 'proveedores':
        // Supplier directory CRUD endpoints - token required
        require_once 'src/Controllers/proveedorController.php';
        break;

    case 'unidades-medida':
        // Catálogo DGII de unidades de medida (solo lectura) - token required
        require_once 'src/Controllers/unidadMedidaController.php';
        break;

    case 'cotizaciones':
        // Cotization CRUD endpoints - token required
        require_once 'src/Controllers/cotizacionController.php';
        break;
    case 'facturas':
        // Factura CRUD endpoints - token required
        require_once 'src/Controllers/facturaController.php';
        break;

    case 'facturas-simples':
        // CRUD de facturas NO electronicas (no e-CF) - token required
        require_once 'src/Controllers/facturaSimpleController.php';
        break;

    case 'gastos':
        // Modulo de Gastos (emitidos 11/13/17 y recibidos 01) - token required
        require_once 'src/Controllers/gastosController.php';
        break;

    case 'ncf':
        // NCF management endpoints - token required
        require_once 'src/Controllers/ncfController.php';
        break;

    case 'facturacion-electronica':
        // DGII electronic billing endpoints - token required
        require_once 'src/Controllers/facturacionElectronicaController.php';
        break;

    case 'aprobaciones-comerciales':
        // POST /api/aprobaciones-comerciales -> sends ACECF to DGII
        // (our role as buyer approving an e-CF that another emisor issued to us)
        require_once 'src/Controllers/aprobacionComercialOutgoingController.php';
        break;

    case 'ecf':
        // Public e-CF receiver endpoints registered with DGII:
        //   /api/ecf/recepcion             -> incoming e-CFs from emisores
        //   /api/ecf/aprobacion-comercial  -> commercial approvals/rejections
        //   /api/ecf/autenticacion         -> seed/validate flow (own auth)
        $sub = strtolower($route_segments[1] ?? '');
        if ($sub === 'recepcion') {
            require_once 'src/Controllers/ecfRecepcionController.php';
        } elseif ($sub === 'aprobacion-comercial' || $sub === 'aprobacioncomercial') {
            require_once 'src/Controllers/ecfAprobacionComercialController.php';
        } elseif ($sub === 'autenticacion') {
            require_once 'src/Controllers/ecfAutenticacionController.php';
        } else {
            http_response_code(404);
            echo json_encode([
                'status' => false,
                'error' => 'Sub-ruta no encontrada bajo /api/ecf. Use recepcion, aprobacion-comercial o autenticacion.',
            ]);
        }
        break;

    case 'integracion':
        // Endpoints para clientes tipo integracion (sin DB; auth X-API-KEY + X-API-SECRET):
        //   /api/integracion/ecf                  -> emitir e-CF (JSON -> XML firmado)
        //   /api/integracion/aprobacion-comercial -> aprobar/rechazar e-CF recibido (ACECF)
        //   /api/integracion/recibidos            -> listar e-CF recibidos
        //   /api/integracion/aprobaciones         -> listar aprobaciones recibidas
        $sub = strtolower($route_segments[1] ?? '');
        if ($sub === 'ecf') {
            require_once 'src/Controllers/integracionEcfController.php';
        } elseif ($sub === 'aprobacion-comercial' || $sub === 'aprobacioncomercial') {
            require_once 'src/Controllers/integracionAprobacionController.php';
        } elseif ($sub === 'recibidos' || $sub === 'aprobaciones') {
            require_once 'src/Controllers/integracionConsultaController.php';
        } else {
            http_response_code(404);
            echo json_encode([
                'status' => false,
                'error' => 'Sub-ruta no encontrada bajo /api/integracion. Use ecf, aprobacion-comercial, recibidos o aprobaciones.',
            ]);
        }
        break;

    case 'emisor':
        // Datos fiscales del emisor (emisor_config del tenant) - token required
        require_once 'src/Controllers/emisorController.php';
        break;

    case 'reportes':
        // Reportes fiscales DGII (TXT descargable) - token required (X-API-KEY)
        //   /api/reportes/606?periodo=AAAAMM -> Formato 606 (compras de bienes/servicios)
        //   /api/reportes/607?periodo=AAAAMM -> Formato 607 (ventas)
        $sub = strtolower($route_segments[1] ?? '');
        if ($sub === '606') {
            require_once 'src/Controllers/Reporte606Controller.php';
        } elseif ($sub === '607') {
            require_once 'src/Controllers/Reporte607Controller.php';
        } else {
            http_response_code(404);
            echo json_encode(['status' => false, 'error' => 'Reporte no encontrado. Use 606 o 607.']);
        }
        break;

    case 'branding':
        // Branding de la Representacion Impresa por tenant (plantilla, color,
        // logo) - token required, solo multi-tenant
        require_once 'src/Controllers/brandingController.php';
        break;

    case 'roles':
        // Gestion de roles y permisos (RBAC) - admin del tenant (roles.manage)
        //   GET /api/roles · POST /api/roles · PUT/DELETE /api/roles/{id}
        //   PUT /api/roles/assign {user_id, role}
        require_once 'src/Controllers/roleController.php';
        break;

    case 'landing':
        // Landing page configuration endpoints
        require_once 'src/Controllers/landingController.php';
        break;

    case 'audit-logs':
        // Bitacora de auditoria (solo lectura, admin del tenant: modulo 'audit')
        //   GET /api/audit-logs?user_id=&module=&action=&from=&to=&page=&pageSize=
        require_once 'src/Controllers/auditLogController.php';
        break;

    default:
        // Handle default and 404 cases
        if ($is_root) {
            require_once 'src/Controllers/userController.php';
        } else {
            http_response_code(404);
            header('Content-Type: text/xml; charset=utf-8');
            echo '<?xml version="1.0" encoding="UTF-8"?><error><message>Endpoint not found</message><endpoint>' . htmlspecialchars($endpoint) . '</endpoint><route>' . htmlspecialchars($route) . '</route></error>';
        }
}
