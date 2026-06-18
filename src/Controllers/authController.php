<?php
header('Access-Control-Allow-Origin: *');
header("Access-Control-Allow-Headers: X-API-KEY, Authorization, Origin, X-Requested-With, Content-Type, Accept, Access-Control-Request-Method");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Allow: GET, POST, OPTIONS, PUT, DELETE");
header('content-type: application/json; charset=utf-8');
require_once(__DIR__ . '/../Models/authModel.php');
require_once(__DIR__ . '/../Middleware/AuthMiddleware.php');

$authModel = new authModel();
$auth = new AuthMiddleware();

switch ($_SERVER['REQUEST_METHOD']) {
    case 'POST':
        $_POST = json_decode(file_get_contents('php://input', true));
        
        // Handle register endpoint
        $endpoint = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        if (preg_match('/\/api\/auth\/register/', $endpoint)) {
            // Validate required fields
            if (!isset($_POST->email) || is_null($_POST->email) || empty(trim($_POST->email))) {
                $respuesta = ['success' => false, 'error' => 'Email is required'];
            } else if (!filter_var($_POST->email, FILTER_VALIDATE_EMAIL)) {
                $respuesta = ['success' => false, 'error' => 'Invalid email format'];
            } else if (!isset($_POST->password) || is_null($_POST->password) || empty(trim($_POST->password))) {
                $respuesta = ['success' => false, 'error' => 'Password is required'];
            } else if (strlen($_POST->password) < 4) {
                $respuesta = ['success' => false, 'error' => 'Password must be at least 4 characters'];
            } else if (!isset($_POST->name) || is_null($_POST->name) || empty(trim($_POST->name))) {
                $respuesta = ['success' => false, 'error' => 'Name is required'];
            } else if (!isset($_POST->username) || is_null($_POST->username) || empty(trim($_POST->username))) {
                $respuesta = ['success' => false, 'error' => 'Username is required'];
            } else if (strlen($_POST->username) < 3) {
                $respuesta = ['success' => false, 'error' => 'Username must be at least 3 characters'];
            } else {
                $register_result = $authModel->registerUser($_POST->email, $_POST->password, $_POST->name, $_POST->username);
                
                if ($register_result[0] === 'success') {
                    $respuesta = [
                        'success' => true,
                        'data' => $register_result[1],
                        'message' => 'User registered successfully'
                    ];
                } else {
                    $respuesta = [
                        'success' => false,
                        'error' => $register_result[1]
                    ];
                    http_response_code(400);
                }
            }
            echo json_encode($respuesta);
        }
        // Handle login endpoint
        else if (preg_match('/\/api\/auth\/login/', $endpoint)) {
            if (!isset($_POST->emailOrUsername) || is_null($_POST->emailOrUsername) || empty(trim($_POST->emailOrUsername))) {
                $respuesta = ['success' => false, 'error' => 'Email or username is required'];
            } else if (!isset($_POST->password) || is_null($_POST->password) || empty(trim($_POST->password))) {
                $respuesta = ['success' => false, 'error' => 'Password is required'];
            } else {
                // tenant_id opcional: requerido para login por USERNAME en multi-tenant
                // (el username es unico por tenant; el email es unico global).
                $tenantId = $_POST->tenant_id ?? null;
                $login_result = $authModel->loginUser($_POST->emailOrUsername, $_POST->password, $tenantId);

                if ($login_result[0] === 'success') {
                    $respuesta = [
                        'success' => true,
                        'data' => $login_result[1]
                    ];
                } else {
                    $respuesta = [
                        'success' => false,
                        'error' => $login_result[1]
                    ];
                    http_response_code(401);
                }
            }
            echo json_encode($respuesta);
        }
        // Handle signout endpoint
        else if (preg_match('/\/api\/auth\/signout/', $endpoint)) {
            // Validate token
            $validation = $auth->validateRequest();
            if (!$validation['valid']) {
                $respuesta = ['success' => false, 'error' => $validation['message']];
                http_response_code(401);
            } else {
                // Get token from header (supports both X-API-KEY and Bearer)
                $token = null;
                
                // Try X-API-KEY first
                if (isset($_SERVER['HTTP_X_API_KEY'])) {
                    $token = trim($_SERVER['HTTP_X_API_KEY']);
                }
                
                // Try Authorization Bearer if X-API-KEY not found
                if (!$token && function_exists('getallheaders')) {
                    $headers = getallheaders();
                    foreach ($headers as $key => $value) {
                        if (strtolower($key) === 'authorization') {
                            $auth_header = trim($value);
                            if (preg_match('/Bearer\s+(.+)/i', $auth_header, $matches)) {
                                $token = trim($matches[1]);
                                break;
                            }
                        }
                    }
                }
                
                // Fallback for servers without getallheaders()
                if (!$token && isset($_SERVER['HTTP_AUTHORIZATION'])) {
                    $auth_header = trim($_SERVER['HTTP_AUTHORIZATION']);
                    if (preg_match('/Bearer\s+(.+)/i', $auth_header, $matches)) {
                        $token = trim($matches[1]);
                    }
                }
                
                if ($token) {
                    $token_hash = hash('sha256', $token);
                    $respuesta_revoke = $authModel->revokeTokenByHash($token_hash);
                    if ($respuesta_revoke[0] === 'success') {
                        $respuesta = [
                            'success' => true,
                            'message' => $respuesta_revoke[1]
                        ];
                    } else {
                        $respuesta = ['success' => false, 'error' => $respuesta_revoke[1]];
                        http_response_code(500);
                    }
                } else {
                    $respuesta = ['success' => false, 'error' => 'Token not found'];
                    http_response_code(401);
                }
            }
            echo json_encode($respuesta);
        }
        // Generate token for a user (existing endpoint)
        else if (isset($_POST->action) && $_POST->action == 'generate_token') {
            if (!isset($_POST->user_id) || is_null($_POST->user_id) || empty(trim($_POST->user_id))) {
                $respuesta = ['success' => false, 'error' => 'User ID is required'];
            } else {
                $respuesta = $authModel->createToken($_POST->user_id);
            }
            echo json_encode($respuesta);
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
        }
        break;

    case 'GET':
        $endpoint = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        // Perfil del usuario actual + sus modulos (para que el front arme el menu
        // y muestre/oculte paginas). Cualquier usuario autenticado puede pedir el
        // SUYO; refleja cambios de rol sin re-login (validateRequest lee el rol vivo).
        if (preg_match('#/api/auth/me/?$#', $endpoint)) {
            $v = $auth->validateRequest();
            if (empty($v['valid']) || ($v['user_id'] ?? null) === null) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => $v['message'] ?? 'Unauthorized']);
                break;
            }
            $profile = $authModel->getUserProfile($v['user_id']);
            if (!$profile) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Usuario no encontrado']);
                break;
            }
            echo json_encode(['success' => true, 'data' => ['user' => $profile]]);
            break;
        }
        // List tokens for a user
        if (isset($_GET['user_id'])) {
            $user_id = $_GET['user_id'];
            $tokens = $authModel->getUserTokens($user_id);
            echo json_encode(['success' => true, 'data' => $tokens]);
        } else {
            echo json_encode(['success' => false, 'error' => 'User ID is required']);
        }
        break;

    case 'DELETE':
        $_DELETE = json_decode(file_get_contents('php://input', true));
        
        // Revoke a token
        if (!isset($_DELETE->token_id) || is_null($_DELETE->token_id) || empty(trim($_DELETE->token_id))) {
            $respuesta = ['success' => false, 'error' => 'Token ID is required'];
        } else {
            $respuesta = $authModel->revokeToken($_DELETE->token_id);
        }
        echo json_encode($respuesta);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        break;
}
