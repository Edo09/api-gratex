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
        $_POST = InputSanitizer::jsonInput(false);
        
        $endpoint = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        // Alta de usuarios: usar POST /api/users (admin del tenant, modulo 'users').
        // El antiguo /api/auth/register se quito (no pasaba tenant_id en multi-tenant).

        // Handle login endpoint
        if (preg_match('/\/api\/auth\/login/', $endpoint)) {
            if (!isset($_POST->emailOrUsername) || is_null($_POST->emailOrUsername) || empty(trim($_POST->emailOrUsername))) {
                $respuesta = ['success' => false, 'error' => 'Escribe tu correo o tu usuario.'];
            } else if (!isset($_POST->password) || is_null($_POST->password) || empty(trim($_POST->password))) {
                $respuesta = ['success' => false, 'error' => 'Escribe tu contraseña.'];
            } else {
                // tenant_id ya no es necesario: email y username son ambos unicos
                // globales, asi que el login resuelve el tenant sin el. Se sigue
                // aceptando por compatibilidad, pero loginUser lo ignora.
                $tenantId = $_POST->tenant_id ?? null;
                $login_result = $authModel->loginUser($_POST->emailOrUsername, $_POST->password, $tenantId);

                $isEmail = filter_var($_POST->emailOrUsername, FILTER_VALIDATE_EMAIL) !== false;
                if ($login_result[0] === 'success') {
                    $respuesta = [
                        'success' => true,
                        'data' => $login_result[1]
                    ];
                    $u = $login_result[1]['user'] ?? [];
                    AuditLogger::authEvent([
                        'action'             => 'LOGIN_SUCCESS',
                        'user_id'            => $u['id'] ?? null,
                        'username'           => $u['username'] ?? null,
                        'email'              => $u['email'] ?? null,
                        // La empresa sale del usuario: el front ya no manda tenant_id y
                        // sin esto ningun login quedaba en la bitacora de su empresa.
                        'tenant_id'          => $login_result[2]['tenant_id'] ?? ($tenantId !== null ? (int) $tenantId : null),
                        'session_token_hash' => isset($login_result[1]['token']) ? hash('sha256', $login_result[1]['token']) : null,
                        'success'            => true,
                        'description'        => 'Inicio de sesion exitoso.',
                    ]);
                } else {
                    $respuesta = [
                        'success' => false,
                        'error' => $login_result[1]
                    ];
                    http_response_code(401);
                    AuditLogger::authEvent([
                        'action'        => 'LOGIN_FAILED',
                        'username'      => $isEmail ? null : $_POST->emailOrUsername,
                        'email'         => $isEmail ? $_POST->emailOrUsername : null,
                        // Usuario existente con clave equivocada: queda en su empresa
                        // (su admin ve el intento). Usuario inexistente: sin empresa.
                        'user_id'       => $login_result[2]['user_id'] ?? null,
                        'tenant_id'     => $login_result[2]['tenant_id'] ?? ($tenantId !== null ? (int) $tenantId : null),
                        'success'       => false,
                        // Un fallo de DB muestra un texto generico al usuario; la
                        // bitacora conserva el detalle crudo para diagnosticarlo.
                        'error_message' => $login_result[2]['detalle'] ?? $login_result[1],
                        'description'   => 'Intento de inicio de sesion fallido.',
                    ]);
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
                        AuditLogger::authEvent([
                            'action'             => 'LOGOUT',
                            'user_id'            => $validation['user_id'] ?? null,
                            'tenant_id'          => $validation['tenant_id'] ?? null,
                            'session_token_hash' => $token_hash,
                            'success'            => true,
                            'description'        => 'Cierre de sesion (token revocado).',
                        ]);
                    } else {
                        $respuesta = ['success' => false, 'error' => $respuesta_revoke[1]];
                        http_response_code(500);
                    }
                } else {
                    $respuesta = ['success' => false, 'error' => 'No hay una sesión activa para cerrar.'];
                    http_response_code(401);
                }
            }
            echo json_encode($respuesta);
        }
        // Boton POS de app.* (docs/specs/pos.md A2): codigo de un solo uso, 60 s,
        // con el que pos.* abre ya autenticado. El canje va ANTES: su ruta
        // contiene a la otra.
        else if (preg_match('#/api/auth/pos-handoff/canje/?$#', $endpoint)) {
            authPosCanje($authModel, $_POST);
        }
        else if (preg_match('#/api/auth/pos-handoff/?$#', $endpoint)) {
            authPosHandoff($auth);
        }
        else {
            echo json_encode(['success' => false, 'error' => 'Invalid endpoint']);
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
        // Solo GET /api/auth/me esta soportado.
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Endpoint no encontrado. Use GET /api/auth/me.']);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        break;
}

/**
 * POST /api/auth/pos-handoff — con la sesion de app.*: crea el codigo y la URL
 * de pos.* que lo lleva en el fragmento (#code=, nunca llega a un servidor).
 * Pide el modulo 'pos' y el POS activo en la empresa.
 */
function authPosHandoff(AuthMiddleware $auth): void
{
    require_once __DIR__ . '/../Models/RoleModel.php';
    require_once __DIR__ . '/../PermissionGate.php';
    require_once __DIR__ . '/../Pos/PosAuth.php';
    require_once __DIR__ . '/../Models/posMasterModel.php';
    try {
        $v = $auth->validateRequest();
        if (empty($v['valid']) || ($v['user_id'] ?? null) === null) {
            throw new PosError('Inicia sesión para abrir el POS.', 401, 'SESION_REQUERIDA');
        }
        $perms = (new RoleModel())->getPermissionsForRole($v['tenant_id'] ?? null, (string) ($v['role'] ?? ''));
        if (!PermissionGate::permMatches($perms, 'pos')) {
            throw new PosError('No tienes permiso para abrir el POS. Pídeselo a un administrador.', 403, 'SIN_PERMISO');
        }
        PosAuth::exigirPosActivo(TenantResolver::current());

        $codigo = (new posMasterModel())->crearCodigo((int) $v['user_id'], (int) $v['tenant_id']);
        $base = rtrim((string) (getenv('POS_PUBLIC_URL') ?: ($_ENV['POS_PUBLIC_URL'] ?? 'https://pos.fiscalpoint.com.do')), '/');
        AuditLogger::log([
            'module' => 'pos', 'action' => 'POS_TRASPASO_CREADO', 'entity_type' => 'usuario', 'entity_id' => $v['user_id'],
            'description' => 'Código para abrir el POS desde app.* (60 s, un solo uso).',
        ]);
        echo json_encode(['success' => true, 'data' => [
            'url' => $base . '/#code=' . $codigo,
            'code' => $codigo,
            'expira_en' => posMasterModel::CODIGO_SEGUNDOS,
        ]]);
    } catch (PosError $e) {
        http_response_code($e->http());
        echo json_encode(['success' => false, 'error' => $e->getMessage(), 'codigo' => $e->codigo()]);
    } catch (Throwable $e) {
        error_log('[auth] pos-handoff: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'No se pudo abrir el POS. Inténtalo de nuevo.', 'codigo' => 'ERROR_INTERNO']);
    }
}

/**
 * POST /api/auth/pos-handoff/canje {code} — publico: pos.* cambia el codigo por
 * una sesion del mismo usuario, con la MISMA respuesta que el login. pos.* la
 * usa solo para habilitar el equipo y la cierra (POST /api/auth/signout).
 * El rol y el POS se vuelven a revisar: pudieron cambiar en esos 60 s.
 *
 * @param mixed $body
 */
function authPosCanje(authModel $authModel, $body): void
{
    require_once __DIR__ . '/../Models/RoleModel.php';
    require_once __DIR__ . '/../PermissionGate.php';
    require_once __DIR__ . '/../Pos/PosAuth.php';
    require_once __DIR__ . '/../Models/posMasterModel.php';
    try {
        $codigo = is_object($body) ? trim((string) ($body->code ?? '')) : '';
        $canje = preg_match('/^[0-9a-f]{48}$/', $codigo) === 1 ? (new posMasterModel())->canjearCodigo($codigo) : null;
        if ($canje === null) {
            throw new PosError('El enlace venció o ya se usó. Entra con tu usuario y contraseña.', 401, 'CODIGO_INVALIDO');
        }
        if (!TenantResolver::resolveById($canje['tenant_id'])) {
            throw new PosError('Tu empresa no está activa.', 403, 'EMPRESA_INACTIVA');
        }
        PosAuth::exigirPosActivo(TenantResolver::current());
        $perfil = $authModel->getUserProfile($canje['user_id']);
        if ($perfil === null || !PermissionGate::permMatches($perfil['permissions'] ?? [], 'pos')) {
            throw new PosError('No tienes permiso para abrir el POS. Pídeselo a un administrador.', 403, 'SIN_PERMISO');
        }

        $sesion = $authModel->iniciarSesionPorId($canje['user_id']);
        if ($sesion[0] !== 'success') {
            throw new PosError($sesion[1], 500, 'ERROR_INTERNO');
        }
        AuditLogger::authEvent([
            'action'             => 'LOGIN_SUCCESS',
            'user_id'            => $canje['user_id'],
            'username'           => $sesion[1]['user']['username'] ?? null,
            'email'              => $sesion[1]['user']['email'] ?? null,
            'tenant_id'          => $canje['tenant_id'],
            'session_token_hash' => hash('sha256', $sesion[1]['token']),
            'success'            => true,
            'description'        => 'Inicio de sesión en el POS con el código del botón POS de app.*.',
        ]);
        echo json_encode(['success' => true, 'data' => $sesion[1]]);
    } catch (PosError $e) {
        http_response_code($e->http());
        echo json_encode(['success' => false, 'error' => $e->getMessage(), 'codigo' => $e->codigo()]);
    } catch (Throwable $e) {
        error_log('[auth] pos-handoff/canje: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'No se pudo abrir el POS. Inténtalo de nuevo.', 'codigo' => 'ERROR_INTERNO']);
    }
}
