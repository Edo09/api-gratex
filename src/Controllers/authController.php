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
                        // "Cerrar sesion" de la app manda {todas:true}. Para un usuario de
                        // soporte (acceso global) cierra TODAS sus sesiones: cada cambio de
                        // empresa abrio una nueva y dejo la anterior viva. Para cualquier
                        // otro usuario no cambia nada (sus otros equipos siguen), y el
                        // signout del POS, que no lo manda, tampoco.
                        $otras = 0;
                        $userIdSalida = (int) ($validation['user_id'] ?? 0);
                        if (is_object($_POST) && !empty($_POST->todas) && $userIdSalida > 0
                            && $authModel->tieneAccesoGlobal($userIdSalida)) {
                            $otras = $authModel->cerrarTodasLasSesiones($userIdSalida);
                        }
                        AuditLogger::authEvent([
                            'action'             => 'LOGOUT',
                            'user_id'            => $validation['user_id'] ?? null,
                            'tenant_id'          => $validation['tenant_id'] ?? null,
                            'session_token_hash' => $token_hash,
                            'success'            => true,
                            'description'        => $otras > 0
                                ? 'Cierre de sesion de soporte: token revocado y ' . $otras . ' sesion(es) mas cerradas en sus empresas.'
                                : 'Cierre de sesion (token revocado).',
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
        // Soporte (master.users.acceso_global, docs/modules/acceso-global.md): abre
        // una sesion nueva (token nuevo) en otra empresa. La lista es GET /api/auth/empresas.
        else if (preg_match('#/api/auth/empresa/?$#', $endpoint)) {
            authCambiarEmpresa($auth, $authModel, $_POST);
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
            // Los permisos son los de la empresa de la SESION (la del token): la del
            // usuario, salvo un usuario de soporte que paso a otra.
            $profile = $authModel->getUserProfile($v['user_id'], isset($v['tenant_id']) ? (int) $v['tenant_id'] : null);
            if (!$profile) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Usuario no encontrado']);
                break;
            }
            echo json_encode(['success' => true, 'data' => ['user' => $profile]]);
            break;
        }
        // Soporte: empresas a las que el usuario puede pasar su sesion.
        if (preg_match('#/api/auth/empresas/?$#', $endpoint)) {
            authListarEmpresas($auth, $authModel);
            break;
        }
        // Solo GET /api/auth/me y GET /api/auth/empresas estan soportados.
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
        // La empresa es la del codigo: la de la sesion de app.* que lo pidio (para un
        // usuario de soporte, la empresa en la que estaba, no la suya).
        $perfil = $authModel->getUserProfile($canje['user_id'], (int) $canje['tenant_id']);
        if ($perfil === null || !PermissionGate::permMatches($perfil['permissions'] ?? [], 'pos')) {
            throw new PosError('No tienes permiso para abrir el POS. Pídeselo a un administrador.', 403, 'SIN_PERMISO');
        }

        $sesion = $authModel->iniciarSesionPorId($canje['user_id'], (int) $canje['tenant_id']);
        if ($sesion[0] !== 'success' && ($sesion[2] ?? null) === AccesoGlobal::CODIGO_SIN_ACCESO) {
            throw new PosError($sesion[1], 403, AccesoGlobal::CODIGO_SIN_ACCESO);
        }
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

/**
 * GET /api/auth/empresas — usuario de soporte (master.users.acceso_global = 1): las
 * empresas a las que puede pasar su sesion y en cual esta ahora.
 *   200 {success:true, data:{actual:<tenant_id de la sesion>, empresas:[{id, nombre, rnc, ambiente, propia}]}}
 *   401 sin sesion; 403 {codigo:'SIN_ACCESO_GLOBAL'} para cualquier otro usuario.
 * Ver docs/modules/acceso-global.md.
 */
function authListarEmpresas(AuthMiddleware $auth, authModel $authModel): void
{
    try {
        $v = $auth->validateRequest();
        if (empty($v['valid']) || ($v['user_id'] ?? null) === null) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => $v['message'] ?? 'Unauthorized']);
            return;
        }
        $empresas = $authModel->empresasAccesoGlobal((int) $v['user_id']);
        if ($empresas === null) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => AccesoGlobal::MSG_SIN_ACCESO, 'codigo' => AccesoGlobal::CODIGO_SIN_ACCESO]);
            return;
        }
        echo json_encode(['success' => true, 'data' => [
            'actual' => isset($v['tenant_id']) ? (int) $v['tenant_id'] : null,
            'empresas' => $empresas,
        ]]);
    } catch (Throwable $e) {
        error_log('[auth] empresas: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'No se pudo cargar la lista de empresas. Inténtalo de nuevo.', 'codigo' => 'ERROR_INTERNO']);
    }
}

/**
 * POST /api/auth/empresa {tenant_id} — usuario de soporte: abre una sesion NUEVA en la
 * empresa elegida, con el rol del usuario buscado en ella. Es un token nuevo, no el
 * mismo movido (ver authModel::cambiarEmpresaSesion): la sesion de la que sale sigue
 * valida en su empresa, asi otra pestaña abierta no pasa a la empresa nueva sin saberlo.
 *   200 {success:true, data:{token, user}}  (la misma forma que el login)
 *   400 EMPRESA_REQUERIDA, 401 sin sesion, 403 SIN_ACCESO_GLOBAL, 404 EMPRESA_NO_DISPONIBLE,
 *   409 MISMA_EMPRESA (la sesion ya esta en esa empresa: no se abre otra).
 * Queda en la bitacora DE LA EMPRESA ELEGIDA (CAMBIO_EMPRESA): su administrador ve
 * quien entro. La empresa de origen se nombra solo si es la del propio usuario de
 * soporte (la del operador): nunca el nombre ni el id de otro cliente.
 *
 * @param mixed $body
 */
function authCambiarEmpresa(AuthMiddleware $auth, authModel $authModel, $body): void
{
    try {
        $v = $auth->validateRequest();
        if (empty($v['valid']) || ($v['user_id'] ?? null) === null) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => $v['message'] ?? 'Unauthorized']);
            return;
        }
        $destino = AccesoGlobal::leerEmpresaId($body);
        if ($destino === null) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => AccesoGlobal::MSG_EMPRESA_REQUERIDA, 'codigo' => AccesoGlobal::CODIGO_EMPRESA_REQUERIDA]);
            return;
        }
        $userId = (int) $v['user_id'];
        $desde = isset($v['tenant_id']) ? (int) $v['tenant_id'] : 0;
        $r = $authModel->cambiarEmpresaSesion($userId, $destino, $desde);
        if ($r[0] !== 'success') {
            http_response_code($r[2]);
            echo json_encode(['success' => false, 'error' => $r[1], 'codigo' => $r[3]]);
            return;
        }
        $perfil = $r[1]['user'];
        // La empresa de origen se nombra solo si es la del propio usuario de soporte (la
        // del operador). Si venia de otro cliente, ni su nombre ni su id: la bitacora de
        // esta empresa la lee su administrador, y no tiene por que saber quien mas es cliente.
        $desdePropia = $desde === (int) ($r[2]['empresa_usuario'] ?? -1);
        $origen = TenantResolver::current();
        $nombreOrigen = $desdePropia && (int) ($origen['id'] ?? 0) === $desde && !empty($origen['nombre'])
            ? (string) $origen['nombre'] : null;
        AuditLogger::authEvent([
            'action'             => 'CAMBIO_EMPRESA',
            'tenant_id'          => $destino,
            'user_id'            => $userId,
            'username'           => $perfil['username'] ?? null,
            'email'              => $perfil['email'] ?? null,
            // La sesion NUEVA: lo que haga en esta empresa queda con el mismo hash.
            'session_token_hash' => hash('sha256', $r[1]['token']),
            'success'            => true,
            'old_values'         => $desdePropia ? ['tenant_id' => $desde] : null,
            'new_values'         => ['tenant_id' => $destino],
            'description'        => $nombreOrigen !== null
                ? 'Soporte (acceso global) entró a esta empresa desde ' . $nombreOrigen . '.'
                : 'Soporte (acceso global) entró a esta empresa.',
        ]);
        echo json_encode(['success' => true, 'data' => ['token' => $r[1]['token'], 'user' => $perfil]]);
    } catch (Throwable $e) {
        error_log('[auth] empresa: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'No se pudo cambiar de empresa. Inténtalo de nuevo.', 'codigo' => 'ERROR_INTERNO']);
    }
}
