<?php
require_once __DIR__ . '/ZonaHoraria.php';   // las fechas de api_tokens se escriben y se leen en hora de RD

/**
 * AccesoGlobal — usuarios de soporte que pueden entrar a todas las empresas.
 *
 * Cada usuario pertenece a UNA empresa (master.users.tenant_id) y su sesion queda
 * atada a la empresa que dice su token (master.api_tokens.tenant_id): AuthMiddleware
 * resuelve esa empresa en cada peticion y PermissionGate busca el rol del usuario en
 * ella. Un usuario con master.users.acceso_global = 1 (migracion master 019) puede
 * abrir una sesion en cualquier empresa disponible:
 *   GET  /api/auth/empresas  -> la lista (authController)
 *   POST /api/auth/empresa   -> abre una sesion NUEVA (token nuevo) en la empresa elegida;
 *                               la anterior sigue valiendo en la suya (ver
 *                               authModel::cambiarEmpresaSesion: el token se comparte
 *                               entre pestañas y moverlo haria emitir en otra empresa)
 * Dentro de la empresa elegida actua con el rol de su usuario (users.role) buscado en
 * esa empresa. Ver docs/modules/acceso-global.md.
 *
 * Disponible = tipo 'app' (una de integracion no tiene base ni web), activa y que no
 * sea solo Express (edicion 'express': esa empresa no usa la app web, y la guarda de
 * rutas de Express no dejaria salir de ella con POST /api/auth/empresa). Una 'ambas'
 * si: la app web corre para ella como una completa.
 *
 * Una sesion en otra empresa deja de valer, y se revoca, si el usuario pierde el
 * acceso, si la empresa deja de estar disponible o tras HORAS_INACTIVIDAD sin uso
 * (authModel::sesionEnEmpresaPermitida). La cuenta de soporte no se puede editar,
 * borrar ni cambiar de rol desde la app (userModel, RoleModel): solo por SQL.
 *
 * La columna acceso_global se lee aparte y sin dejar escapar ningun error, nunca dentro
 * de la consulta del login o del token: en un master sin la 019 nadie tiene el acceso y
 * el login de todos sigue igual. Lo puro (empresaDisponible, sesionPermitida,
 * sesionVencida, empresaPublica, leerEmpresaId) no toca la base: lo prueba
 * tools/test_acceso_global.php.
 */
class AccesoGlobal
{
    /** 403: el usuario no tiene acceso global. */
    public const CODIGO_SIN_ACCESO = 'SIN_ACCESO_GLOBAL';
    /** 404: la empresa pedida no existe, no esta activa o no usa la app web. */
    public const CODIGO_EMPRESA_NO_DISPONIBLE = 'EMPRESA_NO_DISPONIBLE';
    /** 400: el cuerpo no trae un tenant_id valido. */
    public const CODIGO_EMPRESA_REQUERIDA = 'EMPRESA_REQUERIDA';
    /** 409: la sesion ya esta en esa empresa (no se abre otra ni se anota nada). */
    public const CODIGO_MISMA_EMPRESA = 'MISMA_EMPRESA';
    /** 403: se quiso editar, borrar o cambiar de rol a un usuario de soporte desde la app. */
    public const CODIGO_USUARIO_SOPORTE = 'USUARIO_SOPORTE';

    public const MSG_SIN_ACCESO = 'Tu usuario no tiene acceso a otras empresas.';
    public const MSG_EMPRESA_NO_DISPONIBLE = 'Esa empresa no existe, no está activa o no usa la app web.';
    public const MSG_EMPRESA_REQUERIDA = 'Elige la empresa a la que quieres entrar.';
    public const MSG_MISMA_EMPRESA = 'Ya estás en esa empresa.';
    public const MSG_USUARIO_SOPORTE = 'Este usuario es de soporte (entra a todas las empresas): no se puede cambiar ni borrar desde aquí.';
    public const MSG_ROL_SOPORTE = 'El rol de un usuario de soporte no se cambia desde aquí.';

    /**
     * Horas sin uso tras las que una sesion en OTRA empresa (no la del usuario) vence.
     * Cuenta desde su ultimo uso, o desde que se abrio si nunca se uso.
     */
    public const HORAS_INACTIVIDAD = 12;

    /**
     * ¿Puede una sesion de soporte estar en la empresa de esta fila de master.tenants?
     * Tipo app, activa y con una edicion que no sea 'express' (sin la columna, como
     * una completa). null (empresa inexistente o inactiva para getTenantById) = no.
     */
    public static function empresaDisponible(?array $fila): bool
    {
        if ($fila === null || $fila === []) {
            return false;
        }
        if (($fila['tipo'] ?? 'app') !== 'app') {
            return false;
        }
        if ((int) ($fila['activo'] ?? 0) !== 1) {
            return false;
        }
        return strtolower(trim((string) ($fila['edicion'] ?? ''))) !== 'express';
    }

    /**
     * ¿Vale un token cuya empresa ($empresaSesion) puede no ser la del usuario
     * ($empresaUsuario)? La propia siempre (el caso de todos los usuarios); otra, solo
     * con acceso global y si esa empresa esta disponible. El vencimiento por
     * inactividad va aparte (sesionVencida).
     */
    public static function sesionPermitida(int $empresaUsuario, int $empresaSesion, bool $accesoGlobal, ?array $filaSesion): bool
    {
        if ($empresaSesion === $empresaUsuario) {
            return true;
        }
        return $accesoGlobal && self::empresaDisponible($filaSesion);
    }

    /**
     * ¿Vencio por inactividad una sesion en otra empresa? Si, cuando su ultimo uso (o su
     * creacion, si nunca se uso) es estrictamente mas viejo que HORAS_INACTIVIDAD. Sin
     * ninguna fecha legible no se puede saber cuanto lleva: vencida (falla cerrado).
     * Las fechas son las de api_tokens, escritas por PHP en hora de RD (ZonaHoraria).
     */
    public static function sesionVencida(?string $ultimoUso, ?string $creado, int $ahora): bool
    {
        $desde = self::marcaDeTiempo($ultimoUso) ?? self::marcaDeTiempo($creado);
        if ($desde === null) {
            return true;
        }
        return $ahora - $desde > self::HORAS_INACTIVIDAD * 3600;
    }

    /** Una fecha 'Y-m-d H:i:s' como marca de tiempo; null si esta vacia, es la fecha cero o no se puede leer. */
    private static function marcaDeTiempo(?string $fecha): ?int
    {
        $f = trim((string) $fecha);
        if ($f === '' || str_starts_with($f, '0000-00-00')) {
            return null;
        }
        $t = strtotime($f);
        return $t === false ? null : $t;
    }

    /**
     * Lo que se le muestra al cliente de una empresa: la fila de tenants lleva
     * credenciales cifradas y claves, y jamas se serializa entera.
     *
     * @return array{id:int, nombre:string, rnc:string, ambiente:string}
     */
    public static function empresaPublica(array $fila): array
    {
        return [
            'id' => (int) ($fila['id'] ?? 0),
            'nombre' => (string) ($fila['nombre'] ?? ''),
            'rnc' => (string) ($fila['rnc'] ?? ''),
            'ambiente' => (string) ($fila['ambiente'] ?? ''),
        ];
    }

    /**
     * tenant_id del cuerpo de POST /api/auth/empresa: un entero positivo (numero o
     * texto de solo digitos), o null si falta o no lo es.
     *
     * @param mixed $body el cuerpo ya decodificado (objeto de InputSanitizer::jsonInput)
     */
    public static function leerEmpresaId($body): ?int
    {
        $valor = is_object($body) ? ($body->tenant_id ?? null) : (is_array($body) ? ($body['tenant_id'] ?? null) : null);
        if (is_int($valor)) {
            return $valor > 0 ? $valor : null;
        }
        if (is_string($valor) && preg_match('/^[1-9][0-9]{0,9}$/', trim($valor)) === 1) {
            return (int) trim($valor);
        }
        return null;
    }

    /**
     * master.users.acceso_global del usuario: true o false si se pudo leer (un usuario
     * que no existe es false), null si no (master sin la 019, o cualquier fallo de la
     * consulta). Quien llama distingue: un false se puede actuar (revocar), un null es
     * pasajero o de esquema y no.
     *
     * @param PDO|object $master la conexion del master (un PDO; los tests pasan un doble con prepare)
     */
    public static function leerAcceso($master, int $userId): ?bool
    {
        if ($userId <= 0) {
            return false;
        }
        try {
            $st = $master->prepare('SELECT acceso_global FROM users WHERE id = :id LIMIT 1');
            $st->execute([':id' => $userId]);
            return (int) $st->fetchColumn() === 1;
        } catch (Throwable $e) {
            // Cualquier fallo, no solo PDOException: nadie gana el acceso por un error, y el
            // login o la peticion de los demas no se caen por esto.
            self::avisarColumna($e);
            return null;
        }
    }

    /** ¿El usuario tiene acceso global? Solo si se pudo leer y vale 1 (leerAcceso). */
    public static function tieneAcceso($master, int $userId): bool
    {
        return self::leerAcceso($master, $userId) === true;
    }

    /**
     * Las empresas disponibles, ordenadas por nombre, con lo que se le puede mostrar
     * al cliente (empresaPublica) y 'propia' = es la empresa del usuario. Se leen con
     * SELECT * y se filtran aqui con empresaDisponible: asi un master sin la columna
     * edicion (anterior a la 015) no rompe la lista.
     *
     * @param PDO|object $master
     * @return list<array{id:int, nombre:string, rnc:string, ambiente:string, propia:bool}>
     */
    public static function empresas($master, int $empresaUsuario): array
    {
        $filas = $master->query("SELECT * FROM tenants WHERE tipo = 'app' AND activo = 1 ORDER BY nombre, id")
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $lista = [];
        foreach ($filas as $fila) {
            if (!self::empresaDisponible($fila)) {
                continue;
            }
            $lista[] = self::empresaPublica($fila) + ['propia' => (int) $fila['id'] === $empresaUsuario];
        }
        return $lista;
    }

    /** Ya se dejo en este proceso la linea del log de la columna que no se pudo leer. */
    private static bool $columnaAvisada = false;

    /** UNA linea por proceso cuando acceso_global no se puede leer (lo normal: falta la master 019). */
    private static function avisarColumna(Throwable $e): void
    {
        if (self::$columnaAvisada) {
            return;
        }
        self::$columnaAvisada = true;
        error_log('[AccesoGlobal] no se pudo leer users.acceso_global (falta la master 019?): ' . get_class($e) . ': '
            . substr(trim((string) preg_replace('/\s+/', ' ', $e->getMessage())), 0, 300) . '; nadie tiene acceso global');
    }
}
