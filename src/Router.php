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

// Extract the first part of the route (e.g., 'auth' from '/api/auth/login')
preg_match('/\/api\/(\w+)/', $endpoint, $matches);
$route = $matches[1] ?? 'default';

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
