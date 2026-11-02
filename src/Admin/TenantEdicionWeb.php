<?php
/**
 * TenantEdicionWeb — la version web de tools/tenant_edicion.php (cambiar la edicion de una empresa: Express, ambas o completa, y refrescar el
 * vencimiento de su certificado) y de tools/verificar_express.php (el informe de lo que esta mal puesto).
 *
 * Es una herramienta de operaciones: escribe en la master (cambia lo que una empresa puede hacer), asi que va cerrada con llave. El punto de entrada es
 * public/tenant_edicion.php (JSON, POST) y la interfaz es public/tenant_edicion.html, el mismo par que usan las otras herramientas de operaciones
 * (audit_logs.php y audit_logs.html, create_tenant.php y onboard.html). TODO el trabajo lo hace tools/express_edicion_lib.php, la misma biblioteca de la
 * linea de comandos: las reglas del preflight, el plan y la fila de auditoria son una sola copia, y el CLI sigue siendo solo CLI.
 *
 * Reglas de seguridad (cada una la prueba tools/test_tenant_edicion_web.php):
 *   - Apagada hasta que el .env del server traiga TENANT_EDICION_TOKEN (32 o mas caracteres al azar; 'CAMBIAR' o uno corto no valen).
 *   - Solo POST, con el token en el CUERPO (nunca en la URL, que queda en los logs), comparado con hash_equals. Sin cookies ni sesion: no hay nada que
 *     un sitio ajeno pueda disparar sin conocer el token, y ninguna cabecera CORS: otro origen no puede leer las respuestas.
 *   - Solo por HTTPS (el token viaja en la peticion); se salta solo desde el propio servidor (loopback, para probar) o con TENANT_EDICION_PERMITIR_HTTP=1.
 *   - TENANT_EDICION_IPS (opcional): lista de IPs que pueden usarla; las demas reciben 403 sin que se mire el token.
 *   - Freno al tanteo: un token malo espera medio segundo y cuenta; 5 fallos de una misma IP, o 30 de cualquiera, en 15 minutos bloquean (429) incluso al
 *     token correcto hasta que pase la ventana. El estado vive en un archivo del directorio temporal; si no se puede escribir, se sigue (y se anota).
 *   - Una peticion sin token valido NUNCA toca la base de datos: la conexion a la master se pide solo despues de la autenticacion.
 *   - Aplicar exige la confirmacion que dio el ENSAYO: una firma HMAC (con el token como clave) de la empresa, el modo, los tipos, los modulos y el hash de los
 *     pasos del plan, vigente 10 minutos. Si algo cambio entre el ensayo y aplicar (los parametros o el estado de la empresa), se rechaza (409) y hay que
 *     volver a ensayar: se aplica exactamente lo que se vio. Repetir una peticion de aplicar ya hecha tambien da 409 (ya no hay nada que aplicar).
 *   - Cada aplicacion deja la fila de auditoria atomica de la biblioteca (modulo edicion) y otra de la web (modulo edicion_web: IP, navegador, parametros).
 *     Los errores salen con express_describir_fallo (clase, archivo:linea y codigos; nunca el mensaje del driver) y ninguna respuesta lleva el token ni
 *     secretos de la fila de la empresa: la lista de empresas se arma campo por campo.
 *
 * Todo es estatico y manejar() no imprime, no corta con exit y no lee superglobales: recibe $_SERVER, $_POST, el entorno, el reloj, el directorio de estado y
 * una funcion que da la conexion a la master, y devuelve [codigo HTTP, cuerpo]. Quien imprime es public/tenant_edicion.php.
 */
require_once __DIR__ . '/../TenantEntitlements.php';
require_once __DIR__ . '/../../tools/express_edicion_lib.php';   // las ordenes de tenant_edicion.php; al incluirlo no imprime ni conecta
require_once __DIR__ . '/../../tools/verificar_express.php';     // express_verificar; al incluirlo solo define funciones (su main corre solo si es el script)

final class TenantEdicionWeb
{
    /** Lo que se puede pedir: las tres ediciones y refrescar el vencimiento del certificado. */
    public const MODOS = ['express', 'ambas', 'completa', 'refrescar-cert'];

    /** Largo minimo del token: 32 caracteres al azar (bin2hex(random_bytes(24)) da 48). */
    public const TOKEN_MINIMO = 32;

    /** Ventana de los intentos fallidos, en segundos, y cuantos aguanta una IP y cuantos todas juntas antes de bloquear. */
    public const VENTANA = 900;
    public const MAX_FALLOS_IP = 5;
    public const MAX_FALLOS_TOTAL = 30;

    /** Cuanto vale la confirmacion de un ensayo, y cuanto adelanto del reloj se perdona (segundos). */
    public const CONFIRMACION_VIGENCIA = 600;
    public const CONFIRMACION_ADELANTO = 60;

    /** Cuanto espera un token malo antes de contestar, en microsegundos (frena el tanteo). */
    public const DEMORA_TOKEN_MALO = 500000;

    private const SECRETO_CONFIRMACION = '|tenant_edicion_web|confirmacion';

    /**
     * Atiende una peticion. Devuelve [codigo HTTP, cuerpo]: el cuerpo es ['status' => true, 'data' => ...] o ['status' => false, 'error' => texto] (con
     * 'retry_after' en segundos cuando bloquea).
     *
     * @param array<string,mixed> $servidor    $_SERVER (REQUEST_METHOD, REMOTE_ADDR, HTTPS, SERVER_PORT, HTTP_X_FORWARDED_PROTO, HTTP_X_FORWARDED_FOR, HTTP_USER_AGENT)
     * @param array<string,mixed> $post        $_POST (token, action y los campos de cada accion)
     * @param array<string,string> $env        TENANT_EDICION_TOKEN, TENANT_EDICION_IPS y TENANT_EDICION_PERMITIR_HTTP
     * @param callable():PDO $master           la conexion a la master; solo se llama despues de autenticar
     * @param string $dirEstado                directorio donde vive el archivo del freno al tanteo (el temporal del sistema)
     * @param ?callable(int):void $dormir      espera en microsegundos (usleep por omision); las pruebas lo cambian
     * @param ?callable(PDO,array,bool):array $correr  ejecuta una orden de la biblioteca (self::correr por omision); las pruebas lo cambian
     * @return array{0:int,1:array<string,mixed>}
     */
    public static function manejar(
        array $servidor,
        array $post,
        array $env,
        callable $master,
        int $ahora,
        string $dirEstado,
        ?callable $dormir = null,
        ?callable $correr = null
    ): array {
        $dormir ??= static function (int $micro): void {
            usleep($micro);
        };
        $correr ??= static fn (PDO $pdo, array $p, bool $apply): array => self::correr($pdo, $p, $apply);

        if (strtoupper((string) ($servidor['REQUEST_METHOD'] ?? '')) !== 'POST') {
            return self::error(405, 'Usa POST: la interfaz es tenant_edicion.html.');
        }
        $ip = self::ipDirecta($servidor);

        $permitidas = TenantEntitlements::csv($env['TENANT_EDICION_IPS'] ?? '');
        if ($permitidas !== [] && !in_array($ip, $permitidas, true)) {
            error_log('[TenantEdicionWeb] IP no permitida: ' . $ip);
            return self::error(403, 'No autorizado.');
        }
        if (!self::esHttps($servidor) && !self::esLoopback($ip) && ($env['TENANT_EDICION_PERMITIR_HTTP'] ?? '') !== '1') {
            return self::error(403, 'Usa HTTPS: el token viaja en la peticion.');
        }

        $esperado = (string) ($env['TENANT_EDICION_TOKEN'] ?? '');
        if ($esperado === '' || $esperado === 'CAMBIAR' || strlen($esperado) < self::TOKEN_MINIMO) {
            return self::error(403, 'TENANT_EDICION_TOKEN no esta configurado en el .env del server (o es muy corto: usa ' . self::TOKEN_MINIMO . ' caracteres al azar o mas).');
        }

        $espera = self::segundosDeBloqueo(self::actualizarEstado($dirEstado, $ahora, static fn (array $f): array => $f), $ip, $ahora);
        if ($espera > 0) {
            error_log('[TenantEdicionWeb] bloqueado por intentos fallidos: ' . $ip);
            return self::error(429, 'Demasiados intentos fallidos. Espera unos minutos y vuelve a intentar.', ['retry_after' => $espera]);
        }
        if (!hash_equals($esperado, self::texto($post, 'token'))) {
            self::actualizarEstado($dirEstado, $ahora, static fn (array $f): array => array_merge($f, [[$ahora, $ip]]));
            error_log('[TenantEdicionWeb] token invalido desde ' . $ip);
            $dormir(self::DEMORA_TOKEN_MALO);   // frena el tanteo
            return self::error(403, 'Token invalido.');
        }
        self::actualizarEstado($dirEstado, $ahora, static fn (array $f): array => array_values(array_filter($f, static fn (array $x): bool => $x[1] !== $ip)));

        $accion = self::texto($post, 'action');
        try {
            switch ($accion) {
                case 'meta':
                    return [200, ['status' => true, 'data' => self::meta($master())]];
                case 'verificar':
                    $informe = express_verificar($master(), $ahora);
                    return [200, ['status' => true, 'data' => ['codigo' => $informe['codigo'], 'lineas' => $informe['lineas']]]];
                case 'ensayo':
                case 'aplicar':
                    $p = self::parsear($post);
                    if (isset($p['error'])) {
                        return self::error(400, $p['error']);
                    }
                    return $accion === 'ensayo'
                        ? self::ensayo($master(), $p, $esperado, $ahora, $correr)
                        : self::aplicar($master(), $p, $esperado, $ahora, self::texto($post, 'confirmacion'), $servidor, $correr);
                default:
                    return self::error(400, 'Accion desconocida: usa meta, ensayo, aplicar o verificar.');
            }
        } catch (Throwable $e) {
            error_log('[TenantEdicionWeb] ' . express_describir_fallo($e));
            return self::error(500, 'Error inesperado: ' . express_describir_fallo($e));
        }
    }

    // ---------------------------------------------------------------------------------------------------------------------------------------------
    // Acciones
    // ---------------------------------------------------------------------------------------------------------------------------------------------

    /**
     * Las empresas y lo que la interfaz necesita para armar el formulario. Cada empresa se arma campo por campo (la fila de tenants lleva certificado, claves y
     * el hash de integracion y jamas se serializa). Una master sin las columnas de la 015 lista las empresas con edicion null y migraciones.m015 en false.
     *
     * @return array<string,mixed>
     */
    private static function meta(PDO $pdo): array
    {
        $empresas = [];
        foreach ($pdo->query('SELECT * FROM tenants ORDER BY id')->fetchAll() as $fila) {
            $edicion = array_key_exists('edicion', $fila) && $fila['edicion'] !== null ? strtolower(trim((string) $fila['edicion'])) : null;
            $empresas[] = [
                'id' => (int) $fila['id'],
                'nombre' => (string) ($fila['nombre'] ?? ''),
                'rnc' => (string) ($fila['rnc'] ?? ''),
                'tipo' => (string) ($fila['tipo'] ?? 'app'),
                'activo' => (int) ($fila['activo'] ?? 0) === 1,
                'ambiente' => (string) ($fila['ambiente'] ?? ''),
                'edicion' => $edicion,
                'edicion_valida' => $edicion === null || in_array($edicion, ['completa', 'express', 'ambas'], true),
                'tipos' => TenantEntitlements::csv(isset($fila['tipos_ecf_habilitados']) ? (string) $fila['tipos_ecf_habilitados'] : null),
                'modulos' => TenantEntitlements::csv(isset($fila['modulos_habilitados']) ? (string) $fila['modulos_habilitados'] : null),
                'cert_vence' => (string) ($fila['cert_vence'] ?? ''),
                'pos' => (int) ($fila['pos_enabled'] ?? 0) === 1,
                'en_grupo' => !in_array($fila['grupo_id'] ?? null, [null, ''], true),
            ];
        }
        $config = TenantEntitlements::config();
        return [
            'empresas' => $empresas,
            'presets' => ['modulos' => $config['modulos'], 'tipos_venta' => $config['tipos_venta'], 'tipo_opcional' => '33'],
            'migraciones' => [
                'm015' => express_preflight_contar(
                    $pdo,
                    "SELECT COUNT(*) AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenants'
                     AND COLUMN_NAME IN ('edicion', 'modulos_habilitados', 'tipos_ecf_habilitados', 'cert_vence')"
                ) === 4,
                'm017' => express_preflight_contar(
                    $pdo,
                    "SELECT COUNT(*) AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenants'
                     AND COLUMN_NAME = 'edicion' AND LOCATE('''ambas''', COLUMN_TYPE) > 0"
                ) === 1,
            ],
        ];
    }

    /**
     * El ensayo: corre la orden sin escribir y, si hay algo que aplicar y no se rechazo nada, entrega la confirmacion que aplicar va a pedir.
     *
     * @param array{modo:string,tenant:int,tipos:list<string>,modulos:?list<string>} $p
     * @return array{0:int,1:array<string,mixed>}
     */
    private static function ensayo(PDO $pdo, array $p, string $clave, int $ahora, callable $correr): array
    {
        $r = $correr($pdo, $p, false);
        $hayQueAplicar = ($r['ok'] ?? false) === true && (array) ($r['plan'] ?? []) !== [];
        return [200, ['status' => true, 'data' => [
            'ok' => ($r['ok'] ?? false) === true,
            'aplicado' => false,
            'lineas' => array_values((array) ($r['lineas'] ?? [])),
            'pasos' => count((array) ($r['plan'] ?? [])),
            'confirmacion' => $hayQueAplicar ? self::confirmacion($clave, $p, self::hashDelPlan((array) $r['plan']), $ahora) : null,
        ]]];
    }

    /**
     * Aplicar: solo con la confirmacion de un ensayo vigente y del MISMO plan. Vuelve a ensayar para comparar el plan de ahora con el que se vio; si coincide, aplica.
     *
     * @param array{modo:string,tenant:int,tipos:list<string>,modulos:?list<string>} $p
     * @param array<string,mixed> $servidor
     * @return array{0:int,1:array<string,mixed>}
     */
    private static function aplicar(PDO $pdo, array $p, string $clave, int $ahora, string $recibida, array $servidor, callable $correr): array
    {
        if (preg_match('/^[0-9]{9,12}\.[0-9a-f]{64}$/D', $recibida) !== 1) {
            return self::error(400, 'Falta la confirmacion del ensayo: corre el ensayo y aplica con lo que te devuelve.');
        }
        $emitido = (int) explode('.', $recibida)[0];
        if ($ahora - $emitido > self::CONFIRMACION_VIGENCIA || $emitido - $ahora > self::CONFIRMACION_ADELANTO) {
            return self::error(409, 'El ensayo ya vencio (valen ' . intdiv(self::CONFIRMACION_VIGENCIA, 60) . ' minutos). Corre el ensayo otra vez.');
        }
        $actual = $correr($pdo, $p, false);
        if (($actual['ok'] ?? false) !== true || (array) ($actual['plan'] ?? []) === []) {
            return self::error(409, 'Ya no hay nada que aplicar con esos datos (o el ensayo de ahora los rechaza). Corre el ensayo otra vez.');
        }
        if (!hash_equals(self::confirmacion($clave, $p, self::hashDelPlan((array) $actual['plan']), $emitido), $recibida)) {
            return self::error(409, 'Los datos o el estado de la empresa cambiaron desde el ensayo, o la confirmacion no es valida. Corre el ensayo otra vez.');
        }

        $r = $correr($pdo, $p, true);
        $aplicado = ($r['aplicado'] ?? false) === true;
        error_log('[TenantEdicionWeb] ' . ($aplicado ? 'aplicado' : 'no aplicado') . ' tenant #' . $p['tenant'] . ' modo=' . $p['modo'] . ' ip=' . self::ipInformativa($servidor));
        self::auditar($pdo, $servidor, $p, $aplicado);
        return [200, ['status' => true, 'data' => [
            'ok' => ($r['ok'] ?? false) === true,
            'aplicado' => $aplicado,
            'lineas' => array_values((array) ($r['lineas'] ?? [])),
        ]]];
    }

    /**
     * Ejecuta la orden de la biblioteca que corresponde al modo: las mismas que tools/tenant_edicion.php (express_convertir, express_convertir_ambas,
     * express_revertir y express_refrescar_cert). Con $apply en false solo lee.
     *
     * @param array{modo:string,tenant:int,tipos:list<string>,modulos:?list<string>} $p
     * @return array{ok:bool,aplicado:bool,plan:list<array>,lineas:list<string>}
     */
    private static function correr(PDO $pdo, array $p, bool $apply): array
    {
        return match ($p['modo']) {
            'express' => express_convertir($pdo, $p['tenant'], ['edicion' => 'express', 'tipos' => $p['tipos'], 'modulos' => $p['modulos']], $apply),
            'ambas' => express_convertir_ambas($pdo, $p['tenant'], ['edicion' => 'ambas', 'tipos' => $p['tipos'], 'modulos' => $p['modulos']], $apply),
            'completa' => express_revertir($pdo, $p['tenant'], $apply),
            default => express_refrescar_cert($pdo, $p['tenant'], $apply),
        };
    }

    // ---------------------------------------------------------------------------------------------------------------------------------------------
    // Entrada, confirmacion y auditoria
    // ---------------------------------------------------------------------------------------------------------------------------------------------

    /**
     * Valida los campos de ensayo y aplicar como lo hace el analizador de linea de comandos: la empresa es un entero positivo, el modo uno de MODOS, y los
     * tipos y los modulos solo valen para express y ambas (los tipos son obligatorios). Las listas pasan por TenantEntitlements::csv (espacios, vacios y
     * repetidos fuera) y NO se juzgan aqui: las rechaza el preflight de la biblioteca, nunca se angostan en silencio. modulos es null si no se pidio lista.
     *
     * @param array<string,mixed> $post
     * @return array{modo:string,tenant:int,tipos:list<string>,modulos:?list<string>}|array{error:string}
     */
    public static function parsear(array $post): array
    {
        $tenant = $post['tenant'] ?? '';
        if (!is_string($tenant) || preg_match('/^[0-9]{1,10}$/D', $tenant) !== 1 || (int) $tenant <= 0 || (int) $tenant > 2147483647) {
            return ['error' => 'tenant tiene que ser un numero entero positivo (el id de la empresa en master.tenants).'];
        }
        $modo = $post['modo'] ?? '';
        if (!is_string($modo) || !in_array($modo, self::MODOS, true)) {
            return ['error' => 'modo invalido: usa express, ambas, completa o refrescar-cert (en minusculas).'];
        }
        $tipos = $post['tipos'] ?? '';
        $modulos = $post['modulos'] ?? '';
        if (!is_string($tipos) || !is_string($modulos)) {
            return ['error' => 'tipos y modulos van como texto separado por comas.'];
        }
        if ($modo === 'express' || $modo === 'ambas') {
            $lista = TenantEntitlements::csv($tipos);
            if ($lista === []) {
                return ['error' => 'Elige al menos un tipo de venta (31, 32, 45 y, opcional, el 33).'];
            }
            return ['modo' => $modo, 'tenant' => (int) $tenant, 'tipos' => $lista, 'modulos' => trim($modulos) === '' ? null : TenantEntitlements::csv($modulos)];
        }
        if (trim($tipos) !== '' || trim($modulos) !== '') {
            return ['error' => 'Los tipos y los modulos solo valen con express o ambas.'];
        }
        return ['modo' => $modo, 'tenant' => (int) $tenant, 'tipos' => [], 'modulos' => null];
    }

    /**
     * La confirmacion de un ensayo: "<emitido>.<hmac>", donde el hmac (SHA-256, con el token como clave) cubre el momento, la empresa, el modo, los tipos, los
     * modulos y el hash de los pasos del plan. Quien no conoce el token no la puede fabricar, y cualquier cambio en lo que cubre la invalida.
     *
     * @param array{modo:string,tenant:int,tipos:list<string>,modulos:?list<string>} $p
     */
    public static function confirmacion(string $clave, array $p, string $hashDelPlan, int $emitido): string
    {
        $mensaje = implode('|', [$emitido, $p['tenant'], $p['modo'], implode(',', $p['tipos']), implode(',', $p['modulos'] ?? []), $hashDelPlan]);
        return $emitido . '.' . hash_hmac('sha256', $mensaje, $clave . self::SECRETO_CONFIRMACION);
    }

    /**
     * El hash de los pasos de un plan: el donde, el sql y los parametros de cada uno (los de revertir pueden traer el hash de integracion que se restaura: por
     * eso solo se usa para firmar y nunca se devuelve).
     *
     * @param list<array> $plan
     */
    public static function hashDelPlan(array $plan): string
    {
        return hash('sha256', (string) json_encode(array_map(
            static fn (array $paso): array => [$paso['donde'] ?? null, $paso['sql'] ?? null, $paso['params'] ?? null],
            array_values($plan)
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /**
     * La fila de auditoria de la web (modulo edicion_web), ademas de la atomica de la biblioteca: quien (IP y navegador), que empresa y que modo. Si falla se anota
     * en el log y no cambia la respuesta: el cambio ya se confirmo.
     *
     * @param array<string,mixed> $servidor
     * @param array{modo:string,tenant:int,tipos:list<string>,modulos:?list<string>} $p
     */
    private static function auditar(PDO $pdo, array $servidor, array $p, bool $aplicado): void
    {
        try {
            $pdo->prepare(
                'INSERT INTO audit_logs (tenant_id, username, module, entity_type, entity_id, action, http_method, endpoint, ip_address, user_agent, new_values, description, success) '
                . 'VALUES (:tenant_id, :username, :module, :entity_type, :entity_id, :action, :http_method, :endpoint, :ip, :ua, :nuevo, :descripcion, :success)'
            )->execute([
                ':tenant_id' => $p['tenant'], ':username' => 'web:tenant_edicion', ':module' => 'edicion_web', ':entity_type' => 'tenant', ':entity_id' => (string) $p['tenant'],
                ':action' => 'WEB_APLICAR', ':http_method' => 'POST', ':endpoint' => '/api/public/tenant_edicion.php', ':ip' => self::ipInformativa($servidor),
                ':ua' => substr((string) ($servidor['HTTP_USER_AGENT'] ?? ''), 0, 255),
                ':nuevo' => (string) json_encode(['modo' => $p['modo'], 'tipos' => $p['tipos'], 'modulos' => $p['modulos'], 'aplicado' => $aplicado], JSON_UNESCAPED_UNICODE),
                ':descripcion' => 'Edicion cambiada desde la web: ' . $p['modo'] . ' (empresa #' . $p['tenant'] . ')', ':success' => $aplicado ? 1 : 0,
            ]);
        } catch (Throwable $e) {
            error_log('[TenantEdicionWeb] no se pudo escribir la fila de auditoria de la web: ' . express_describir_fallo($e));
        }
    }

    // ---------------------------------------------------------------------------------------------------------------------------------------------
    // Red y freno al tanteo
    // ---------------------------------------------------------------------------------------------------------------------------------------------

    /** ¿Llego la peticion por HTTPS (directo, por el puerto 443 o detras de un proxy que lo dice)? */
    public static function esHttps(array $servidor): bool
    {
        $https = strtolower((string) ($servidor['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off' && $https !== '0') {
            return true;
        }
        return (int) ($servidor['SERVER_PORT'] ?? 0) === 443 || strtolower((string) ($servidor['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    /** ¿Es una direccion del propio servidor? Solo para probar en local: desde fuera nunca lo es. */
    public static function esLoopback(string $ip): bool
    {
        return $ip === '127.0.0.1' || $ip === '::1';
    }

    /** La IP que conecto (REMOTE_ADDR): la que no se puede falsear desde la peticion, y la que usan el freno y la lista de IPs permitidas. */
    private static function ipDirecta(array $servidor): string
    {
        return substr((string) ($servidor['REMOTE_ADDR'] ?? ''), 0, 45);
    }

    /** La IP para el log y la auditoria: la primera de X-Forwarded-For si hay un proxy, o la directa. Es informativa: nada se decide con ella. */
    private static function ipInformativa(array $servidor): string
    {
        $reenviada = trim(explode(',', (string) ($servidor['HTTP_X_FORWARDED_FOR'] ?? ''))[0]);
        $ip = $reenviada !== '' ? $reenviada : self::ipDirecta($servidor);
        return substr((string) preg_replace('/[^0-9A-Fa-f:.]/', '', $ip), 0, 45);
    }

    /**
     * Cuantos segundos faltan para que se levante el bloqueo, o 0 si no hay. Bloquea una IP con MAX_FALLOS_IP fallos en la ventana, y a todas con
     * MAX_FALLOS_TOTAL; el bloqueo dura hasta que el fallo mas viejo que lo causa sale de la ventana.
     *
     * @param list<array{0:int,1:string}> $fallos
     */
    private static function segundosDeBloqueo(array $fallos, string $ip, int $ahora): int
    {
        $deEstaIp = array_values(array_filter($fallos, static fn (array $f): bool => $f[1] === $ip));
        $espera = 0;
        foreach ([[$deEstaIp, self::MAX_FALLOS_IP], [$fallos, self::MAX_FALLOS_TOTAL]] as [$lista, $maximo]) {
            if (count($lista) >= $maximo) {
                $tiempos = array_map(static fn (array $f): int => (int) $f[0], $lista);
                sort($tiempos);
                // el fallo que tiene que salir de la ventana para bajar de $maximo es el (count - maximo + 1)-esimo mas viejo
                $causa = $tiempos[count($lista) - $maximo];
                $espera = max($espera, $causa + self::VENTANA - $ahora);
            }
        }
        return max(0, $espera);
    }

    /**
     * Lee, cambia y guarda el archivo de fallos con el candado puesto, y devuelve la lista de despues (sin lo que ya salio de la ventana). Si el archivo no se puede
     * abrir o escribir, anota en el log y sigue sin freno: el token largo es la defensa de fondo, esto es de refuerzo.
     *
     * @param callable(list<array{0:int,1:string}>):list<array{0:int,1:string}> $cambiar
     * @return list<array{0:int,1:string}>
     */
    private static function actualizarEstado(string $dir, int $ahora, callable $cambiar): array
    {
        $archivo = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . 'tenant_edicion_web_' . substr(hash('sha256', __DIR__), 0, 12) . '.json';
        $f = @fopen($archivo, 'c+');
        if ($f === false) {
            error_log('[TenantEdicionWeb] no se pudo abrir el archivo del freno al tanteo: la herramienta sigue sin el');
            return [];
        }
        try {
            if (!flock($f, LOCK_EX)) {
                return [];
            }
            $crudo = (string) stream_get_contents($f);
            $leido = json_decode($crudo, true);
            $lista = [];
            foreach (is_array($leido) ? $leido : [] as $x) {
                if (is_array($x) && count($x) === 2 && is_int($x[0]) && is_string($x[1]) && $x[0] > $ahora - self::VENTANA) {
                    $lista[] = [$x[0], $x[1]];
                }
            }
            $nueva = array_values($cambiar($lista));
            $texto = (string) json_encode($nueva);
            if ($texto !== $crudo) {
                ftruncate($f, 0);
                rewind($f);
                fwrite($f, $texto);
                fflush($f);
                @chmod($archivo, 0600);
            }
            flock($f, LOCK_UN);
            return $nueva;
        } finally {
            fclose($f);
        }
    }

    /** Un campo del formulario como texto: lo que no es texto (una lista, un arreglo) cuenta como vacio y no genera avisos de PHP. */
    private static function texto(array $post, string $campo): string
    {
        $valor = $post[$campo] ?? '';
        return is_string($valor) ? $valor : '';
    }

    /**
     * @param array<string,mixed> $extra
     * @return array{0:int,1:array<string,mixed>}
     */
    private static function error(int $http, string $mensaje, array $extra = []): array
    {
        return [$http, ['status' => false, 'error' => $mensaje] + $extra];
    }
}
