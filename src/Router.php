<?php
/**
 * Simple router to handle different API endpoints
 * Routes:
 *   /api/auth/* -> Auth endpoints (token generation, management)
 *   /api/users/* -> User CRUD endpoints (requires token)
 */

$request_uri = $_SERVER['REQUEST_URI'];
$request_method = $_SERVER['REQUEST_METHOD'];

// Remove base path if needed and parse endpoint
$endpoint = parse_url($request_uri, PHP_URL_PATH);

// Serve API documentation
if ($endpoint === '/' || $endpoint === '/docs' || $endpoint === '/api/docs' || $endpoint === '/docs/' || $endpoint === '/index.php') {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/../public/docs.html');
    exit;
}

// Extract the first part of the route after the last '/api/' segment
// This handles: /api/auth, /api/api/auth, and /api/index.php/api/auth
$parts = explode('/api/', $endpoint);
$route_part = end($parts);
$route_segments = explode('/', ltrim($route_part, '/'));
$route = $route_segments[0] ?? 'default';

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

    case 'cotizaciones':
        // Cotization CRUD endpoints - token required
        require_once 'src/Controllers/cotizacionController.php';
        break;
    case 'facturas':
        // Factura CRUD endpoints - token required
        require_once 'src/Controllers/facturaController.php';
        break;

    case 'ncf':
        // NCF management endpoints - token required
        require_once 'src/Controllers/ncfController.php';
        break;

    case 'landing':
        // Landing page configuration endpoints
        require_once 'src/Controllers/landingController.php';
        break;

    default:
        // Handle default and 404 cases
        if ($endpoint === '/' || $endpoint === '/index.php') {
            require_once 'src/Controllers/userController.php';
        } else {
            header('content-type: application/json; charset=utf-8');
            echo json_encode(['error', 'Endpoint not found']);
            http_response_code(404);
        }
}
