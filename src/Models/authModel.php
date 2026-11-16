<?php
require_once(__DIR__ . '/../Utils/TokenGenerator.php');
require_once(__DIR__ . '/../Database.php');
require_once(__DIR__ . '/../MasterDatabase.php');
require_once(__DIR__ . '/../TenantResolver.php');
require_once(__DIR__ . '/../AccesoGlobal.php');

class authModel
{
    private $conexion;

    /** Ya se dejo en este proceso la linea del log de un fallo de la edicion (permissionsForUser y conEntitlements fallan juntos: UNA linea por peticion). */
    private static bool $falloEdicionAvisado = false;

    public function __construct()
    {
        // In multi-tenant mode, auth (users + api_tokens) lives in the master DB
        // so a token can be resolved to its tenant before any business query.
        if (self::multiTenant()) {
            $this->conexion = MasterDatabase::getInstance()->getConnection();
        } else {
            $this->conexion = Database::getInstance()->getConnection();
        }
    }

    private static function multiTenant(): bool
    {
        return filter_var(
            getenv('MULTI_TENANT_ENABLED') ?: ($_ENV['MULTI_TENANT_ENABLED'] ?? false),
            FILTER_VALIDATE_BOOLEAN
        );
    }

    /**
     * Validate an API token
     * @param string $token Raw token from request
     * @return array [user_id] if valid or [null] if invalid
     */
    public function validateToken($token)
    {
        try {
            $token_hash = TokenGenerator::hashToken($token);
            // JOIN a users para traer el rol (RBAC). users vive en la misma
            // conexion que api_tokens (master en MT, tenant DB en single).
            // last_used y created_at son para el vencimiento por inactividad de las
            // empresas Express (AuthMiddleware::validateRequest); el resto los ignora.
            // u.tenant_id es la empresa del usuario: la del token puede ser otra solo
            // con acceso global (sesionEnEmpresaPermitida).
            $sql = self::multiTenant()
                ? "SELECT t.user_id, t.tenant_id, t.is_active, u.role, t.last_used, t.created_at,
                          u.tenant_id AS usuario_tenant_id
                     FROM api_tokens t JOIN users u ON u.id = t.user_id
                    WHERE t.token_hash = :token_hash LIMIT 1"
                : "SELECT t.user_id, t.is_active, u.role, t.last_used, t.created_at
                     FROM api_tokens t JOIN users u ON u.id = t.user_id
                    WHERE t.token_hash = :token_hash LIMIT 1";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([':token_hash' => $token_hash]);
            $row = $stmt->fetch();

            if ($row && $row['is_active'] == 1 && (!self::multiTenant() || $this->sesionEnEmpresaPermitida($row, $token_hash))) {
                return [
                    'valid' => true,
                    'user_id' => (int)$row['user_id'],
                    'tenant_id' => isset($row['tenant_id']) ? (int)$row['tenant_id'] : null,
                    'role' => $row['role'] ?? null,
                    'last_used' => isset($row['last_used']) ? (string) $row['last_used'] : null,
                    'created_at' => isset($row['created_at']) ? (string) $row['created_at'] : null,
                ];
            }
            return ['valid' => false, 'user_id' => null, 'tenant_id' => null, 'role' => null];
        } catch (PDOException $e) {
            return ['valid' => false, 'user_id' => null];
        }
    }

    /**
     * ¿Vale el token de esta fila (validateToken) en la empresa que dice? Si es la del
     * usuario, si y sin consultar nada mas: es el caso de todos los usuarios. Si es
     * otra, solo con acceso global, si esa empresa sigue disponible y si no lleva mas de
     * AccesoGlobal::HORAS_INACTIVIDAD sin uso.
     *
     * Cuando se niega por algo definitivo (el acceso se leyo y es 0, la empresa ya no
     * esta disponible, o vencio), el token ademas se REVOCA: si no, devolverle el acceso
     * al usuario o reactivar la empresa lo reviviria sin pasar por el login (una copia
     * filtrada incluida). Un fallo al leer la empresa o la columna solo niega esta vez.
     */
    private function sesionEnEmpresaPermitida(array $row, string $tokenHash): bool
    {
        $empresaUsuario = (int) ($row['usuario_tenant_id'] ?? 0);
        $empresaSesion = (int) ($row['tenant_id'] ?? 0);
        if ($empresaSesion === $empresaUsuario) {
            return true;
        }
        try {
            $fila = MasterDatabase::getInstance()->getTenantById($empresaSesion);
        } catch (Throwable $e) {
            error_log('[authModel] sesion en otra empresa: no se pudo leer la empresa ' . $empresaSesion . ': ' . $e->getMessage());
            return false;
        }
        $acceso = AccesoGlobal::leerAcceso($this->conexion, (int) $row['user_id']);
        $permitida = AccesoGlobal::sesionPermitida($empresaUsuario, $empresaSesion, $acceso === true, $fila)
            && !AccesoGlobal::sesionVencida($row['last_used'] ?? null, $row['created_at'] ?? null, time());
        if (!$permitida && $acceso !== null) {
            $this->revokeTokenByHash($tokenHash);
        }
        return $permitida;
    }

    /** ¿El usuario tiene acceso global (master.users.acceso_global = 1)? Siempre false sin multi-tenant. */
    public function tieneAccesoGlobal(int $userId): bool
    {
        return self::multiTenant() && AccesoGlobal::tieneAcceso($this->conexion, $userId);
    }

    /**
     * Cierra TODAS las sesiones activas de un usuario (todas sus empresas, pestañas y
     * equipos). Es el "Cerrar sesion" de un usuario de soporte: cada cambio de empresa
     * abre una sesion nueva y deja la anterior viva, asi que cerrar solo la actual
     * dejaria otras abiertas en empresas cliente. Quien llama decide para quien.
     *
     * @return int cuantas sesiones cerro
     */
    public function cerrarTodasLasSesiones(int $userId): int
    {
        $st = $this->conexion->prepare('UPDATE api_tokens SET is_active = 0 WHERE user_id = :u AND is_active = 1');
        $st->execute([':u' => $userId]);
        return $st->rowCount();
    }

    /**
     * Revoke (deactivate) a token by its hash
     * @param string $token_hash Hashed token
     * @return array ['success'/'error', message]
     */
    public function revokeTokenByHash($token_hash)
    {
        try {
            $sql = "UPDATE api_tokens SET is_active = 0 WHERE token_hash = :token_hash";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([':token_hash' => $token_hash]);
            return ['success', 'Signed out successfully'];
        } catch (PDOException $e) {
            error_log('[authModel] revokeTokenByHash: ' . $e->getMessage());
            return ['error', 'No se pudo cerrar la sesión. Inténtalo de nuevo.'];
        }
    }

    /**
     * Update last_used timestamp for a token
     * @param string $token_hash Hashed token
     */
    public function updateLastUsed($token_hash)
    {
        try {
            $now = date('Y-m-d H:i:s');
            $sql = "UPDATE api_tokens SET last_used = :last_used WHERE token_hash = :token_hash";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([
                ':last_used' => $now,
                ':token_hash' => $token_hash
            ]);
        } catch (PDOException $e) {
            // Silently fail on update
        }
    }

    /**
     * Login user with email or username and password.
     * @param string $email_or_username User email or username
     * @param string $password User password
     * @param int|string|null $tenant_id Vestigial: email y username son unicos
     *        globales, asi que el tenant se resuelve sin el. Se acepta por
     *        compatibilidad con clientes viejos, pero ya no se usa.
     * @return array ['success', ['token' => token, 'user' => user_data]] or ['error', message]
     *         El tercer elemento (tenant_id, user_id y, ante un fallo de DB,
     *         'detalle' con el texto crudo) es solo para la bitacora.
     */
    public function loginUser($email_or_username, $password, $tenant_id = null)
    {
        try {
            // email y username son ambos UNIQUE global -> un solo match posible,
            // sin ambiguedad y sin necesidad de tenant_id (ver migracion master 007).
            if (self::multiTenant()) {
                $sql = "SELECT id, tenant_id, email, username, name, last_name, password, role
                        FROM users WHERE email = :eu OR username = :eu LIMIT 1";
            } else {
                $sql = "SELECT id, email, username, name, last_name, password, role
                        FROM users WHERE email = :eu OR username = :eu LIMIT 1";
            }
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([':eu' => $email_or_username]);
            $user = $stmt->fetch();

            // Verify user exists and password is correct. El tercer elemento es
            // solo para la bitacora (no va en la respuesta): con el usuario
            // encontrado, el intento fallido queda en SU empresa y el admin lo
            // ve. Usuario inexistente = sin empresa (no se sabe a quien iba).
            // El mismo texto para usuario inexistente y clave equivocada, a
            // proposito: no revela si la cuenta existe.
            if (!$user || !password_verify($password, $user['password'])) {
                return ['error', 'El usuario o la contraseña no son correctos. Revísalos e inténtalo de nuevo.', $user
                    ? ['tenant_id' => isset($user['tenant_id']) ? (int) $user['tenant_id'] : null, 'user_id' => (int) $user['id']]
                    : ['tenant_id' => null, 'user_id' => null]];
            }

            return $this->emitirSesion($user);
        } catch (PDOException $e) {
            // El texto de PDO no se le muestra al usuario (puede traer SQL o datos
            // del servidor). Va al log y, en 'detalle', a la bitacora del intento.
            error_log('[authModel] loginUser: ' . $e->getMessage());
            return ['error', 'No se pudo iniciar sesión por un problema del sistema. Inténtalo de nuevo en unos minutos.',
                ['tenant_id' => null, 'user_id' => null, 'detalle' => 'Database error: ' . $e->getMessage()]];
        }
    }

    /**
     * Sesion para un usuario ya autenticado por otra via: el codigo de traspaso
     * del boton POS (app.* -> pos.*, ver posMasterModel::canjearCodigo). Misma
     * respuesta que loginUser, sin clave.
     *
     * $empresaSesion: la empresa del codigo (la de la sesion de app.* que lo pidio).
     * Para un usuario normal es la suya; para uno de soporte que estaba en otra
     * empresa, esa otra, y solo si todavia tiene acceso global y la empresa sigue
     * disponible. null = la empresa del usuario.
     *
     * @return array ['success', ['token','user'], ['tenant_id']] o ['error', mensaje] (o ['error', mensaje,
     *               AccesoGlobal::CODIGO_SIN_ACCESO] si la empresa no es la suya y no puede estar en ella)
     */
    public function iniciarSesionPorId(int $userId, ?int $empresaSesion = null): array
    {
        try {
            $sql = self::multiTenant()
                ? "SELECT id, tenant_id, email, username, name, last_name, role FROM users WHERE id = :id LIMIT 1"
                : "SELECT id, email, username, name, last_name, role FROM users WHERE id = :id LIMIT 1";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([':id' => $userId]);
            $user = $stmt->fetch();
            if (!$user) {
                return ['error', 'Ese usuario ya no existe.'];
            }
            if (self::multiTenant() && $empresaSesion !== null) {
                $empresaUsuario = (int) ($user['tenant_id'] ?? 0);
                $fila = $empresaSesion === $empresaUsuario ? null : MasterDatabase::getInstance()->getTenantById($empresaSesion);
                $acceso = $empresaSesion !== $empresaUsuario && AccesoGlobal::tieneAcceso($this->conexion, $userId);
                if (!AccesoGlobal::sesionPermitida($empresaUsuario, $empresaSesion, $acceso, $fila)) {
                    return ['error', AccesoGlobal::MSG_SIN_ACCESO, AccesoGlobal::CODIGO_SIN_ACCESO];
                }
            }
            return $this->emitirSesion($user, self::multiTenant() ? $empresaSesion : null);
        } catch (PDOException $e) {
            error_log('[authModel] iniciarSesionPorId: ' . $e->getMessage());
            return ['error', 'No se pudo iniciar sesión por un problema del sistema. Inténtalo de nuevo en unos minutos.'];
        }
    }

    /**
     * Crea el token de sesion (api_tokens guarda solo su sha256) y arma la
     * respuesta del login. Compartido por loginUser, iniciarSesionPorId y cambiarEmpresaSesion.
     *
     * @param array $user Fila de users (sin la clave hace falta).
     * @param ?int $empresaSesion empresa del token si no es la del usuario: la que eligio
     *        un usuario de soporte (POST /api/auth/empresa, cambiarEmpresaSesion) o la del
     *        canje del POS pedido desde otra empresa (iniciarSesionPorId). Quien llama ya
     *        la verifico. null = la del usuario, como en el login.
     */
    private function emitirSesion(array $user, ?int $empresaSesion = null): array
    {
        // Generate new token for this login
        $token = TokenGenerator::generateApiToken();
        $token_hash = TokenGenerator::hashToken($token);
        $created_at = date('Y-m-d H:i:s');
        $empresa = $empresaSesion ?? (isset($user['tenant_id']) ? (int) $user['tenant_id'] : null);

        if (self::multiTenant()) {
            $sql = "INSERT INTO api_tokens(user_id, tenant_id, token_hash, created_at) VALUES(:user_id, :tenant_id, :token_hash, :created_at)";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([
                ':user_id' => $user['id'],
                ':tenant_id' => $empresa,
                ':token_hash' => $token_hash,
                ':created_at' => $created_at
            ]);
        } else {
            $sql = "INSERT INTO api_tokens(user_id, token_hash, created_at) VALUES(:user_id, :token_hash, :created_at)";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([
                ':user_id' => $user['id'],
                ':token_hash' => $token_hash,
                ':created_at' => $created_at
            ]);
        }

        // Prepare user data response (without password). Incluye los modulos
        // a los que el rol da acceso, para que el front muestre/oculte paginas.
        // La empresa se lee una vez del master (sin master, en un solo tenant, no hay fila): solo si es Express
        // (master.tenants.edicion) sus permisos salen cruzados con los modulos de su edicion y el user trae
        // entitlements. Para toda otra empresa el user sale exactamente como siempre.
        // La empresa es la de la sesion ($empresa): la del usuario, salvo la que eligio un
        // usuario de soporte (cambiarEmpresaSesion) o la del canje del POS pedido desde otra.
        $fila = $this->filaTenant($empresa);
        $user_data = [
            'id' => (int)$user['id'],
            'email' => $user['email'],
            'username' => $user['username'],
            'name' => $user['name'] . ' ' . $user['last_name'],
            'role' => $user['role'],
            'permissions' => $this->permissionsForUser($empresa, $user['role'], $fila),
        ];
        $user_data = $this->conEntitlements($user_data, $fila);
        $user_data = $this->conAccesoGlobal($user_data, (int) $user['id'], $fila, isset($user['tenant_id']) ? (int) $user['tenant_id'] : null);

        // Tercer elemento: empresa de la sesion, solo para la bitacora.
        return ['success', ['token' => $token, 'user' => $user_data], ['tenant_id' => $empresa]];
    }

    /**
     * Agrega al user del login y de /me, solo si el usuario tiene acceso global:
     *   acceso_global: true
     *   empresa: {id, nombre, rnc, ambiente, propia} de la empresa de la sesion;
     *            propia = es la empresa del usuario (false: esta en la de un cliente)
     * Para cualquier otro usuario devuelve $user_data tal cual, sin las claves (ni
     * como false o null): su respuesta no cambia. Sin multi-tenant no hay empresas
     * entre las que moverse.
     *
     * @param ?array $fila fila de master.tenants de la empresa de la sesion, o null
     * @param ?int $empresaUsuario users.tenant_id del usuario
     */
    private function conAccesoGlobal(array $user_data, int $userId, ?array $fila, ?int $empresaUsuario): array
    {
        if (!self::multiTenant() || !AccesoGlobal::tieneAcceso($this->conexion, $userId)) {
            return $user_data;
        }
        $user_data['acceso_global'] = true;
        if ($fila !== null) {
            $user_data['empresa'] = AccesoGlobal::empresaPublica($fila)
                + ['propia' => $empresaUsuario !== null && (int) ($fila['id'] ?? 0) === $empresaUsuario];
        }
        return $user_data;
    }

    /**
     * GET /api/auth/empresas: las empresas a las que el usuario puede pasar su sesion
     * (AccesoGlobal::empresas), o null si no tiene acceso global.
     *
     * @return ?list<array{id:int, nombre:string, rnc:string, ambiente:string, propia:bool}>
     */
    public function empresasAccesoGlobal(int $userId): ?array
    {
        if (!self::multiTenant() || !AccesoGlobal::tieneAcceso($this->conexion, $userId)) {
            return null;
        }
        $st = $this->conexion->prepare('SELECT tenant_id FROM users WHERE id = :id LIMIT 1');
        $st->execute([':id' => $userId]);
        return AccesoGlobal::empresas($this->conexion, (int) $st->fetchColumn());
    }

    /**
     * POST /api/auth/empresa: una sesion NUEVA de $userId en la empresa $destino (un
     * token nuevo, con la misma respuesta que el login). Pide acceso global y una
     * empresa disponible (AccesoGlobal).
     *
     * Token nuevo y no el mismo movido de empresa, a proposito: el token se comparte
     * entre las pestañas del navegador. Si se moviera, otra pestaña que sigue mostrando
     * la empresa anterior (una factura a medio hacer, con sus clientes y productos)
     * mandaria sus peticiones a la nueva y podria emitir un e-CF en la empresa
     * equivocada. Con uno nuevo, cada pestaña sigue en la empresa de su token y la
     * sesion anterior no se cierra.
     *
     * A la empresa en la que ya esta la sesion ($empresaActual) responde 409 sin abrir
     * otra: una sesion nueva sin su fila de bitacora no tendria a que atribuirse.
     *
     * @return array ['success', ['token','user'], ['tenant_id' => destino, 'empresa_usuario' => users.tenant_id]]
     *               (los dos primeros como loginUser) o ['error', mensaje, http, codigo]
     */
    public function cambiarEmpresaSesion(int $userId, int $destino, ?int $empresaActual = null): array
    {
        if (!self::multiTenant()) {
            return ['error', AccesoGlobal::MSG_SIN_ACCESO, 403, AccesoGlobal::CODIGO_SIN_ACCESO];
        }
        try {
            if (!AccesoGlobal::tieneAcceso($this->conexion, $userId)) {
                return ['error', AccesoGlobal::MSG_SIN_ACCESO, 403, AccesoGlobal::CODIGO_SIN_ACCESO];
            }
            if ($empresaActual !== null && $destino === $empresaActual) {
                return ['error', AccesoGlobal::MSG_MISMA_EMPRESA, 409, AccesoGlobal::CODIGO_MISMA_EMPRESA];
            }
            $fila = MasterDatabase::getInstance()->getTenantById($destino);
            if (!AccesoGlobal::empresaDisponible($fila)) {
                return ['error', AccesoGlobal::MSG_EMPRESA_NO_DISPONIBLE, 404, AccesoGlobal::CODIGO_EMPRESA_NO_DISPONIBLE];
            }
            $st = $this->conexion->prepare(
                'SELECT id, tenant_id, email, username, name, last_name, role FROM users WHERE id = :id LIMIT 1'
            );
            $st->execute([':id' => $userId]);
            $user = $st->fetch();
            if (!$user) {
                return ['error', 'Tu usuario ya no existe. Vuelve a iniciar sesión.', 401, 'SESION_INVALIDA'];
            }
            $r = $this->emitirSesion($user, $destino);
            if ($r[0] === 'success') {
                // Para la bitacora: el controller nombra la empresa de origen solo si es la
                // del propio usuario (nunca la de otro cliente).
                $r[2]['empresa_usuario'] = (int) $user['tenant_id'];
            }
            return $r;
        } catch (Throwable $e) {
            error_log('[authModel] cambiarEmpresaSesion: ' . $e->getMessage());
            return ['error', 'No se pudo cambiar de empresa. Inténtalo de nuevo.', 500, 'ERROR_INTERNO'];
        }
    }

    /**
     * Modulos (permisos) a los que da acceso un rol. Vacio si no resuelve. Con la fila de una empresa Express, los del rol
     * cruzados con los modulos que su edicion incluye (TenantEntitlements::permisosEfectivos); con cualquier otra fila, o
     * sin ella, los del rol tal cual. Si la clase de la edicion no carga o su config no se puede leer, tambien tal cual: ver conEntitlements.
     *
     * @param ?array $fila fila de master.tenants de la empresa del usuario (filaTenant), o null
     */
    private function permissionsForUser($tenant_id, $role, ?array $fila = null): array
    {
        require_once __DIR__ . '/RoleModel.php';
        $permisos = (new RoleModel())->getPermissionsForRole(
            $tenant_id !== null ? (int) $tenant_id : null,
            (string) $role
        );
        if (self::esFilaExpress($fila) && TenantResolver::cargarEntitlements()) {
            try {
                return TenantEntitlements::permisosEfectivos($permisos, $fila);
            } catch (Throwable $e) {
                self::avisarFalloEdicion($e);   // sin su config no se puede cruzar: los permisos del rol, tal cual
            }
        }
        return $permisos;
    }

    /**
     * Agrega el bloque entitlements al user del login y de /me, despues de permissions (que ya es su ultima clave), solo si
     * $fila es la de una empresa Express; con cualquier otra devuelve $user_data tal cual, sin la clave (ni como null). El
     * bloque sale de TenantEntitlements::bloque, que lo arma campo por campo: la fila de tenants lleva certificado y claves y
     * jamas se serializa.
     *
     * Si la clase de la edicion no carga (TenantResolver::cargarEntitlements() es false y deja su propia linea en el log), o si su
     * config (config/editions.php) no se puede leer y bloque lanza, la respuesta sale normal SIN el bloque y con los permisos
     * crudos del rol: el cliente falla cerrado ante la falta del bloque y la guarda de AuthMiddleware contesta 403 a toda
     * peticion posterior de esa empresa. Un login nunca es un 500 por eso.
     *
     * @param ?array $fila fila de master.tenants de la empresa del usuario (filaTenant), o null
     */
    private function conEntitlements(array $user_data, ?array $fila): array
    {
        if (self::esFilaExpress($fila) && TenantResolver::cargarEntitlements()) {
            try {
                $user_data['entitlements'] = TenantEntitlements::bloque($fila);
            } catch (Throwable $e) {
                self::avisarFalloEdicion($e);
            }
        }
        return $user_data;
    }

    /**
     * Deja UNA linea en el log por proceso cuando lo de la edicion Express no se puede calcular para un login o un /me (lo normal: config/editions.php roto o ausente). Lleva la
     * clase y el mensaje de la excepcion en una sola linea y acotado; nunca la fila de la empresa.
     */
    private static function avisarFalloEdicion(Throwable $e): void
    {
        if (self::$falloEdicionAvisado) {
            return;
        }
        self::$falloEdicionAvisado = true;
        error_log('[authModel] edicion: no se pudo calcular lo de la edicion Express (config/editions.php no carga o es invalido, o fallo TenantEntitlements): ' . get_class($e) . ': '
            . substr(trim((string) preg_replace('/\s+/', ' ', $e->getMessage())), 0, 300) . '; la respuesta sale sin el bloque de la edicion y con los permisos del rol');
    }

    /**
     * La fila de master.tenants de la empresa de un usuario (getTenantById), o null: la unica lectura que el login suma. De ella
     * sale el bloque user.entitlements de una empresa Express. Sin master (un solo tenant) o sin tenant_id no hay nada que
     * leer, y una empresa inexistente o inactiva tampoco da fila. Cualquier fallo (master a medias, tabla ausente) se traga:
     * la respuesta queda como estaba, sin el bloque y con los permisos del rol, y deja UNA linea en el log.
     */
    private function filaTenant($tenantId): ?array
    {
        if (!self::multiTenant() || $tenantId === null) {
            return null;
        }
        try {
            return MasterDatabase::getInstance()->getTenantById((int) $tenantId);
        } catch (Throwable $e) {
            error_log('[authModel] filaTenant: no se pudo leer la empresa ' . (int) $tenantId . ' (' . get_class($e) . ': '
                . $e->getMessage() . '); la respuesta sale sin el bloque de la edicion');
            return null;
        }
    }

    /**
     * La prueba de Express, en linea y sin cargar la clase de la edicion: false para una fila completa, de integracion o sin la
     * columna edicion (master sin migrar), y para ninguna fila; true para una 'express' y, para una 'ambas', solo si esta
     * peticion se anuncia como Express (cabecera X-Fiscalpoint-Edicion): el login de la app completa de una empresa 'ambas' sale
     * exactamente como el de una completa. Es TenantResolver::esFilaExpress, que no avisa una edicion desconocida (de eso se ocupa
     * AuthMiddleware en la peticion siguiente), y preguntarsela a TenantEntitlements la cargaria para todas las empresas.
     */
    private static function esFilaExpress(?array $fila): bool
    {
        return TenantResolver::esFilaExpress($fila);
    }

    /**
     * Perfil del usuario actual + sus modulos (para GET /api/auth/me). Lo mismo que el user del login, bloque entitlements
     * incluido cuando la empresa es Express, y acceso_global + empresa cuando el usuario es de soporte.
     *
     * $empresaSesion: la empresa de la sesion (la del token, AuthMiddleware::validateRequest), que es la que decide los
     * permisos: PermissionGate busca el rol en ella. Para un usuario normal es la suya; para uno de soporte, la que eligio.
     * null = la empresa del usuario.
     * @return array|null
     */
    public function getUserProfile($user_id, ?int $empresaSesion = null)
    {
        try {
            $cols = 'id, email, username, name, last_name, role' . (self::multiTenant() ? ', tenant_id' : '');
            $stmt = $this->conexion->prepare("SELECT {$cols} FROM users WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $user_id]);
            $u = $stmt->fetch();
            if (!$u) {
                return null;
            }
            // La empresa de la sesion: si es la que la peticion ya resolvio (validateRequest acaba de leerla), esa fila, y /me no
            // suma una consulta al master; si no, una lectura (filaTenant).
            $tenantId = self::multiTenant() && $empresaSesion !== null ? $empresaSesion : ($u['tenant_id'] ?? null);
            $actual = TenantResolver::current();
            $fila = ($actual !== null && $tenantId !== null && (int) ($actual['id'] ?? 0) === (int) $tenantId)
                ? $actual
                : $this->filaTenant($tenantId);
            $perfil = $this->conEntitlements([
                'id' => (int) $u['id'],
                'email' => $u['email'],
                'username' => $u['username'],
                'name' => trim(($u['name'] ?? '') . ' ' . ($u['last_name'] ?? '')),
                'role' => $u['role'],
                'permissions' => $this->permissionsForUser($tenantId, $u['role'], $fila),
            ], $fila);
            return $this->conAccesoGlobal($perfil, (int) $u['id'], $fila, isset($u['tenant_id']) ? (int) $u['tenant_id'] : null);
        } catch (PDOException $e) {
            return null;
        }
    }

    /**
     * Register a new user
     * @param string $email User email
     * @param string $password User password
     * @param string $name Full name (will be split into name and last_name)
     * @param string $username Username
     * @return array ['success', user_data] or ['error', message]
     */
    public function registerUser($email, $password, $name, $username, $tenant_id = null, $role = 'user')
    {
        try {
            if (self::multiTenant() && $tenant_id === null) {
                // Solo pasa si la sesion no trae empresa; volver a entrar la renueva.
                error_log('[authModel] registerUser sin tenant_id en modo multi-tenant');
                return ['error', 'No se pudo identificar tu empresa. Cierra sesión y vuelve a entrar.'];
            }
            // El rol es server-side; default 'user'. El llamador (admin) puede
            // pasar otro nombre de rol existente del tenant.
            $role = (is_string($role) && trim($role) !== '') ? trim($role) : 'user';

            // Check if email already exists (email is globally unique across tenants)
            $sql = "SELECT id FROM users WHERE email = :email LIMIT 1";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([':email' => $email]);
            if ($stmt->fetch()) {
                return ['error', 'Ya existe un usuario con ese correo. Usa otro correo.'];
            }

            // Check if username already exists (username is globally unique across
            // tenants, like email — so login by username needs no tenant_id).
            $sql = "SELECT id FROM users WHERE username = :username LIMIT 1";
            $stmt = $this->conexion->prepare($sql);
            $stmt->execute([':username' => $username]);
            if ($stmt->fetch()) {
                return ['error', 'Ese nombre de usuario ya está en uso. Elige otro.'];
            }

            // Split full name into first name and last name
            $name_parts = explode(' ', trim($name), 2);
            $first_name = $name_parts[0];
            $last_name = isset($name_parts[1]) ? $name_parts[1] : '';

            // Hash password
            $password_hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);

            // Insert new user
            if (self::multiTenant()) {
                $sql = "INSERT INTO users (tenant_id, name, last_name, email, username, password, role) VALUES (:tenant_id, :name, :last_name, :email, :username, :password, :role)";
                $stmt = $this->conexion->prepare($sql);
                $stmt->execute([
                    ':tenant_id' => $tenant_id,
                    ':name' => $first_name,
                    ':last_name' => $last_name,
                    ':email' => $email,
                    ':username' => $username,
                    ':password' => $password_hash,
                    ':role' => $role
                ]);
            } else {
                $sql = "INSERT INTO users (name, last_name, email, username, password, role) VALUES (:name, :last_name, :email, :username, :password, :role)";
                $stmt = $this->conexion->prepare($sql);
                $stmt->execute([
                    ':name' => $first_name,
                    ':last_name' => $last_name,
                    ':email' => $email,
                    ':username' => $username,
                    ':password' => $password_hash,
                    ':role' => $role
                ]);
            }

            // Get the newly created user
            $user_id = $this->conexion->lastInsertId();
            $user_data = [
                'id' => (int)$user_id,
                'email' => $email,
                'username' => $username,
                'name' => trim($first_name . ' ' . $last_name),
                'role' => $role
            ];

            return ['success', $user_data];
        } catch (PDOException $e) {
            error_log('[authModel] registerUser: ' . $e->getMessage());
            return ['error', 'No se pudo crear el usuario. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.'];
        }
    }
}
