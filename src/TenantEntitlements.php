<?php
require_once(__DIR__ . '/TenantResolver.php');

/**
 * TenantEntitlements — que incluye la edicion de una empresa (Fiscalpoint Express).
 *
 * master.tenants.edicion (migracion 014) es 'completa' (la app de siempre, sin restricciones) o 'express'. Esta clase
 * resuelve, para una empresa Express, lo que su fila le da: los modulos (el preset de config/editions.php angostado por
 * modulos_habilitados), los tipos de e-CF (tipos_ecf_habilitados), los permisos que de ahi salen, el bloque que se le
 * entrega al cliente al iniciar sesion, que rutas de la API puede llamar (la guarda de rutas), cuando vence por
 * inactividad el token de su sesion (tokenCaducado) y el estado de su certificado, que Configuracion le muestra
 * (estadoCertificado).
 *
 * Reglas del archivo:
 *   - Todo es estatico y no hay namespace. Incluirlo no imprime nada: se carga en medio de una peticion que contesta
 *     JSON.
 *   - Las peticiones solo la cargan despues de una prueba en linea que ya probo la empresa Express: para una empresa
 *     completa, una de integracion o ninguna, esta clase no se incluye jamas.
 *   - Las funciones de modulos, tipos, permisos, bloque y estado del certificado son funciones PURAS de la fila: no
 *     preguntan la edicion (eso lo hace quien las llama, con esRestringido) y un $tenant null significa la empresa
 *     resuelta (TenantResolver::current()).
 *   - evaluarRuta tambien es pura (no imprime, no abre conexion y no corta), pero si pregunta la edicion: una empresa
 *     que no es Express pasa tal cual. Las que contestan y cortan la peticion con exit son guardaRuta y responderError.
 *   - Un token mal escrito en las listas de la fila angosta y nunca ensancha: lo que no se reconoce no suma nada.
 */
final class TenantEntitlements
{
    /**
     * Codigo de la negativa por una ruta o un modulo que la edicion no incluye. El cliente se guia por el codigo, nunca por el
     * texto. Alias de TenantResolver::CODIGO_EDICION_NO_INCLUYE (ver MSG_EDICION).
     */
    public const CODIGO_EDICION_NO_INCLUYE = TenantResolver::CODIGO_EDICION_NO_INCLUYE;

    /** Codigo de la solicitud rechazada por su forma: un segmento '..' en la ruta o '/api/' dentro de la consulta. */
    public const CODIGO_SOLICITUD_INVALIDA = 'SOLICITUD_INVALIDA';

    /**
     * Texto de EDICION_NO_INCLUYE, sin punto final. Es un alias de TenantResolver::MSG_EDICION, que es la unica copia en la
     * API: vive alli porque AuthMiddleware tiene que contestar esa misma negativa cuando esta clase no carga, y una clase que
     * no carga no puede dar su propio texto. Todo lo que conteste la negativa lo lee de una de las dos constantes, nunca de
     * un literal. Tiene que ser igual, byte a byte, a MSG_EDICION del mock del front (express/dev/mock-api.mjs).
     */
    public const MSG_EDICION = TenantResolver::MSG_EDICION;

    /** Texto de SOLICITUD_INVALIDA. */
    public const MSG_SOLICITUD = 'La solicitud no es válida.';

    /**
     * Texto de una fila de administrador cuando el rol no lo es: 403 sin codigo, porque no es un asunto de la edicion.
     * Es el mismo texto que da PermissionGate a un rol sin el modulo, asi el Facturador lee lo mismo en los dos modos.
     */
    public const MSG_SOLO_ADMIN = 'No tienes permiso para hacer esto. Pídeselo a un administrador.';

    /** Tipo que se deriva de cualquier tipo de venta: la nota de credito, con la que se anula una factura. */
    private const TIPO_ANULACION = '34';

    /** Tipo que solo cuenta si la empresa lo trae en su lista: la nota de debito. No tiene pantalla. */
    private const TIPO_OPCIONAL = '33';

    /** Token de permiso de los catalogos compartidos (unidades de medida, consulta de RNC, provincias). */
    private const PERMISO_CATALOGOS = 'unidades';

    /** Modulos cuyas pantallas leen esos catalogos: con cualquiera habilitado, el token va en los permisos. */
    private const MODULOS_CON_CATALOGOS = ['facturacion', 'clientes', 'productos'];

    private static ?array $config = null;

    /**
     * Valores de edicion desconocidos que ya escribieron su linea de log en este proceso.
     *
     * @var array<string,true>
     */
    private static array $edicionesAvisadas = [];

    /**
     * Peticiones que guardaRuta ya dejo pasar en este proceso, por clave (metodo, URI, id de la empresa, rol). Solo lo
     * permitido: una negativa termina la peticion y no hay nada que recordar.
     *
     * @var array<string,true>
     */
    private static array $rutasPermitidas = [];

    /**
     * config/editions.php: se lee con require una sola vez por proceso. Si el archivo no esta lanza RuntimeException (un require que falla antes escribe un aviso de PHP, que con
     * display_errors prendido saldria delante del JSON de la respuesta); si no compila, lanza al cargarse o no devuelve un arreglo, la excepcion es la que el archivo dispare
     * (ParseError, TypeError...). Quien la lee para decidir sobre una empresa Express (el gancho de AuthMiddleware, guardaRuta y el login de authModel) atrapa lo que sea y niega: un archivo roto
     * no se vuelve un 500, igual que una clase que no carga.
     */
    public static function config(): array
    {
        if (self::$config === null) {
            $archivo = __DIR__ . '/../config/editions.php';
            if (!is_file($archivo)) {
                throw new RuntimeException('no existe config/editions.php');
            }
            self::$config = require $archivo;
        }
        return self::$config;
    }

    /**
     * Lista separada por comas -> valores sin espacios, sin vacios y sin repetidos, en el orden en que aparecen.
     * Los operadores escriben estas listas a mano ("45, 31," con espacios y coma final): no deben angostar por eso.
     * No toca mayusculas ni corrige tokens: lo que no coincide con el catalogo exacto no suma nada.
     *
     * @return string[]
     */
    public static function csv(?string $valor): array
    {
        return array_values(array_unique(array_filter(
            array_map('trim', explode(',', (string) $valor)),
            static fn (string $item): bool => $item !== ''
        )));
    }

    /**
     * 'express' si la edicion de la fila, sin espacios y en minusculas, es exactamente express; 'completa' en
     * cualquier otro caso.
     *
     * Un valor presente que no es ni completa ni express (el vacio incluido: es lo que MySQL guarda en un ENUM cuando
     * se le da un valor invalido sin modo estricto) se trata como completa y escribe UNA linea en el log por valor
     * distinto y por proceso. Una columna ausente (master sin migrar) o sin fila es lo de siempre: no escribe nada.
     */
    public static function normalizarEdicion(?array $tenant): string
    {
        $valor = $tenant['edicion'] ?? null;
        if ($valor === null) {
            return 'completa';
        }
        $edicion = strtolower(trim((string) $valor));
        if ($edicion === 'express') {
            return 'express';
        }
        if ($edicion !== 'completa') {
            self::avisarEdicionDesconocida((string) $valor);
        }
        return 'completa';
    }

    /**
     * ¿Es una empresa Express? Solo una de tipo app cuya edicion normaliza a express: una de integracion (sin base
     * propia ni pantallas) nunca lo es, y la fila ausente o sin la columna tampoco.
     *
     * Es la misma prueba que las peticiones hacen en linea antes de cargar esta clase.
     */
    public static function esRestringido(?array $tenant): bool
    {
        return ($tenant['tipo'] ?? 'app') === 'app' && self::normalizarEdicion($tenant) === 'express';
    }

    /** ¿Es Express la empresa resuelta en esta peticion? */
    public static function restringido(): bool
    {
        return self::esRestringido(TenantResolver::current());
    }

    /**
     * Modulos de la empresa, en el orden del preset. modulos_habilitados vacio (NULL, '' o solo separadores) es el preset
     * completo; una lista se cruza con el preset: lo que no esta en el preset (o esta mal escrito) cae, y el resultado
     * puede quedar vacio.
     *
     * @return string[]
     */
    public static function modulosEfectivos(?array $tenant = null): array
    {
        $tenant ??= TenantResolver::current();
        $preset = self::config()['modulos'];
        $lista = self::csv($tenant['modulos_habilitados'] ?? null);
        return $lista === [] ? $preset : array_values(array_intersect($preset, $lista));
    }

    /**
     * Tipos de VENTA de la empresa (31, 32, 45), en orden canonico y no en el de la lista. Lista vacia o sin ninguno de
     * los tres: [] (cerrado, no "todos").
     *
     * @return string[]
     */
    public static function tiposVenta(?array $tenant = null): array
    {
        $tenant ??= TenantResolver::current();
        $listados = self::csv($tenant['tipos_ecf_habilitados'] ?? null);
        return array_values(array_intersect(self::config()['tipos_venta'], $listados));
    }

    /**
     * Todos los tipos de e-CF que la empresa puede emitir: sus tipos de venta, el 34 derivado cuando tiene alguno, y el 33
     * solo si lo trae en su lista (siempre al final). Es lo que el login entrega como tipos_ecf.
     *
     * @return string[]
     */
    public static function tiposEfectivos(?array $tenant = null): array
    {
        $tenant ??= TenantResolver::current();
        $tipos = self::tiposVenta($tenant);
        if ($tipos !== []) {
            $tipos[] = self::TIPO_ANULACION;
        }
        if (in_array(self::TIPO_OPCIONAL, self::csv($tenant['tipos_ecf_habilitados'] ?? null), true)) {
            $tipos[] = self::TIPO_OPCIONAL;
        }
        return $tipos;
    }

    /**
     * Permisos del rol cruzados con los tokens que la empresa tiene habilitados (un token por modulo efectivo, segun
     * config 'permisos', y el compartido 'unidades' si hay facturacion, clientes o productos). '*' se expande a todos
     * los habilitados; el resultado sale en el orden de esos tokens, no en el del rol.
     *
     * Es la lista de permisos que el login entrega al cliente. Los modulos los aplica de verdad la guarda de rutas, no
     * esta lista: el gate lee los permisos crudos del rol y el administrador tiene '*'.
     *
     * @param string[] $permisosRol
     * @return string[]
     */
    public static function permisosEfectivos(array $permisosRol, ?array $tenant = null): array
    {
        $modulos = self::modulosEfectivos($tenant);
        $habilitados = [];
        foreach (self::config()['permisos'] as $modulo => $token) {
            if (in_array($modulo, $modulos, true)) {
                $habilitados[] = $token;
            }
        }
        if (array_intersect(self::MODULOS_CON_CATALOGOS, $modulos) !== []) {
            $habilitados[] = self::PERMISO_CATALOGOS;
        }
        if (in_array('*', $permisosRol, true)) {
            return $habilitados;
        }
        return array_values(array_intersect($habilitados, $permisosRol));
    }

    /**
     * El bloque user.entitlements que el login y /me entregan a una empresa Express. Se arma campo por campo con una
     * lista blanca: la fila de tenants lleva certificado, claves y secretos y jamas se serializa.
     *
     * @return array{edicion:string,tenant:array{id:int,nombre:string,rnc:string,ambiente:string},modulos:string[],tipos_ecf:string[],capacidades:array<string,bool>}
     */
    public static function bloque(array $tenant): array
    {
        return [
            'edicion' => self::normalizarEdicion($tenant),
            'tenant' => [
                'id' => (int) ($tenant['id'] ?? 0),
                'nombre' => (string) ($tenant['nombre'] ?? ''),
                'rnc' => (string) ($tenant['rnc'] ?? ''),
                'ambiente' => (string) ($tenant['ambiente'] ?? ''),
            ],
            'modulos' => self::modulosEfectivos($tenant),
            'tipos_ecf' => self::tiposEfectivos($tenant),
            'capacidades' => self::config()['CAPACIDADES'],
        ];
    }

    /**
     * Evalua una peticion (metodo, URI y rol) contra la lista de rutas de config 'rutas' para una empresa Express. Es PURA:
     * no imprime, no abre conexion y no corta la peticion (eso lo hace guardaRuta). Lo unico que puede escribir en el log
     * es la linea, una por valor y por proceso, de una edicion desconocida (normalizarEdicion).
     *
     * Una empresa que no es Express (completa, de integracion o ninguna) pasa tal cual: 200. Para una Express:
     *   1. La URI se parte con parse_url. Una que no se puede leer, un segmento '..' en la ruta (o su forma con %2e) o un
     *      '/api/' dentro de la consulta son 400 SOLICITUD_INVALIDA: los controllers leen la URI a pedazos. Una consulta
     *      con '..' pasa (se puede buscar "Constr...").
     *   2. La ruta y el resto se sacan igual que el Router: despues del PRIMER '/api/', la ruta es el primer segmento,
     *      exacto y con sus mayusculas, y el resto son los demas. "//api/gastos" es la ruta 'gastos', como para el Router.
     *      Una ruta que termina en '/' se deniega: el Router contesta la documentacion para ella.
     *   3. Decide la primera fila de 'rutas' con esa ruta, ese metodo (en mayusculas) y un path que coincide con el resto.
     *      Sin fila: 403 EDICION_NO_INCLUYE. Con fila, al menos uno de sus modulos tiene que estar entre los efectivos de
     *      la empresa (tambien para un administrador con '*'), si no: 403 EDICION_NO_INCLUYE. Y en una fila de
     *      administrador el rol tiene que llamarse admin, sin mayusculas ni espacios (la base busca el rol sin
     *      distinguirlas), si no: 403 con codigo null.
     *
     * @param ?string $rol    nombre del rol del usuario, o null si no hay
     * @param ?array  $tenant fila de la empresa; null = la resuelta en esta peticion (TenantResolver::current())
     * @return array{permitido:bool,http:int,codigo:?string,modulo:?string} 'modulo' es el primero de la fila que coincidio
     */
    public static function evaluarRuta(string $metodo, string $uri, ?string $rol, ?array $tenant = null): array
    {
        $tenant ??= TenantResolver::current();
        if (!self::esRestringido($tenant)) {
            return self::veredicto(true, 200, null, null);
        }

        $partes = parse_url($uri);
        if ($partes === false) {
            return self::veredicto(false, 400, self::CODIGO_SOLICITUD_INVALIDA, null);
        }
        $path = (string) ($partes['path'] ?? '');
        $consulta = (string) ($partes['query'] ?? '');
        foreach (explode('/', $path) as $segmento) {
            // '..' y sus formas con %2e (%2e%2e, .%2e, %2E.), con o sin mayusculas.
            if (preg_match('/^(%2e|\.){2}$/i', $segmento) === 1) {
                return self::veredicto(false, 400, self::CODIGO_SOLICITUD_INVALIDA, null);
            }
        }
        if (str_contains($consulta, '/api/')) {
            return self::veredicto(false, 400, self::CODIGO_SOLICITUD_INVALIDA, null);
        }

        if (str_ends_with($path, '/')) {
            return self::veredicto(false, 403, self::CODIGO_EDICION_NO_INCLUYE, null);
        }
        // La misma derivacion del Router (src/Router.php): ruta cruda y con mayusculas, desde el primer '/api/'.
        $apiPos = strpos($path, '/api/');
        $resto = $apiPos !== false ? substr($path, $apiPos + 5) : ltrim($path, '/');
        $segmentos = explode('/', ltrim($resto, '/'));
        $ruta = $segmentos[0];
        $subruta = implode('/', array_slice($segmentos, 1));
        $metodo = strtoupper($metodo);

        foreach (self::config()['rutas'] as $fila) {
            if ($fila['route'] !== $ruta || $fila['method'] !== $metodo || preg_match($fila['path'], $subruta) !== 1) {
                continue;
            }
            $modulo = $fila['modulos'][0] ?? null;
            if ($fila['modulos'] !== [] && array_intersect($fila['modulos'], self::modulosEfectivos($tenant)) === []) {
                return self::veredicto(false, 403, self::CODIGO_EDICION_NO_INCLUYE, $modulo);
            }
            if ($fila['admin'] && strtolower(trim((string) $rol)) !== 'admin') {
                return self::veredicto(false, 403, null, $modulo);
            }
            return self::veredicto(true, 200, null, $modulo);
        }
        return self::veredicto(false, 403, self::CODIGO_EDICION_NO_INCLUYE, null);
    }

    /**
     * La guarda de rutas de la peticion en curso: la deja pasar (metodo y URI de $_SERVER, la empresa resuelta y el rol que
     * se le da) o contesta la negativa y corta con exit. Esta pensada para la rama de sesion de
     * AuthMiddleware::validateRequest y solo para una empresa Express. Esa funcion corre mas de una vez por peticion, asi
     * que lo permitido se recuerda por metodo, URI, id de la empresa y rol y la segunda vez sale sin volver a mirar. Solo
     * se recuerda lo permitido, y nada pasa de una peticion a otra: la fila de la empresa se vuelve a leer en cada una,
     * asi un cambio de edicion rige desde la siguiente.
     *
     * Falla cerrado: si evaluarRuta lanza lo que sea, es una negativa (403 EDICION_NO_INCLUYE), nunca un 500. Toda
     * negativa deja una fila de auditoria (modulo 'edicion'), y un fallo de la bitacora no la estorba.
     */
    public static function guardaRuta(?string $rol): void
    {
        $metodo = (string) ($_SERVER['REQUEST_METHOD'] ?? '');
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $tenant = TenantResolver::current();
        $clave = serialize([$metodo, $uri, $tenant['id'] ?? null, $rol]);
        if (isset(self::$rutasPermitidas[$clave])) {
            return;
        }

        $motivo = null;
        try {
            $veredicto = self::evaluarRuta($metodo, $uri, $rol, $tenant);
        } catch (Throwable $e) {
            error_log('[TenantEntitlements] guardaRuta: fallo al evaluar la ruta, se niega: ' . $e->getMessage());
            $veredicto = self::veredicto(false, 403, self::CODIGO_EDICION_NO_INCLUYE, null);
            $motivo = 'fallo al evaluar la ruta';
        }
        if ($veredicto['permitido']) {
            self::$rutasPermitidas[$clave] = true;
            return;
        }

        $codigo = $veredicto['codigo'];
        $motivo ??= match (true) {
            $codigo === self::CODIGO_SOLICITUD_INVALIDA => 'solicitud no valida',
            $codigo === null => 'ruta de administrador',
            $veredicto['modulo'] !== null => 'modulo no habilitado',
            default => 'ruta fuera de la edicion',
        };
        try {
            require_once __DIR__ . '/Middleware/AuditMiddleware.php';
            AuditMiddleware::logAccessDenied(
                'edicion',
                $motivo,
                ['metodo' => $metodo, 'http' => $veredicto['http'], 'codigo' => $codigo, 'modulo' => $veredicto['modulo']],
                "Acceso denegado por la edicion Express: {$motivo}."
            );
        } catch (Throwable $e) {
            error_log('[TenantEntitlements] guardaRuta: no se pudo auditar la negativa: ' . $e->getMessage());
        }

        self::responderError(
            $veredicto['http'],
            (string) $codigo,
            match ($codigo) {
                self::CODIGO_SOLICITUD_INVALIDA => self::MSG_SOLICITUD,
                null => self::MSG_SOLO_ADMIN,
                default => self::MSG_EDICION,
            }
        );
    }

    /**
     * Contesta una negativa y corta la peticion: pone el codigo HTTP e imprime {status:false, error, codigo, ...extra}.
     * Un $codigo vacio omite la clave codigo (la negativa por rol de administrador no la lleva). Las claves de $extra no
     * pisan status, error ni codigo. Nunca vuelve.
     *
     * @param array<string,mixed> $extra claves adicionales del cuerpo
     */
    public static function responderError(int $http, string $codigo, string $mensaje, array $extra = []): never
    {
        $cuerpo = ['status' => false, 'error' => $mensaje];
        if ($codigo !== '') {
            $cuerpo['codigo'] = $codigo;
        }
        http_response_code($http);
        // Con bytes que no son UTF-8 en $extra, un cuerpo vacio rompe el JSON del cliente: se sustituyen y se sigue.
        echo json_encode($cuerpo + $extra, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        exit;
    }

    /**
     * ¿Venció por inactividad el token de sesion de una empresa Express? Es PURA: la hora de la peticion la pone quien llama.
     *
     * Cuenta desde el ultimo uso ($ultimoUso, api_tokens.last_used) o, si el token nunca se uso, desde que se creo
     * ($creado, api_tokens.created_at): COALESCE(last_used, created_at). Un valor vacio, que no se puede leer como fecha o que
     * es la fecha cero de MySQL ('0000-...') cuenta como ausente y pasa al siguiente; sin ninguno de los dos no vence.
     * Vence solo el que es ESTRICTAMENTE mas viejo que $dias dias: a exactamente $dias dias aun vale, y un ultimo uso en el
     * futuro (reloj corrido) tampoco vence. Un token recien creado, un reloj adelantado o un dato roto no dejan a nadie fuera
     * (RF3): ante la duda, no vence.
     *
     * @param ?string $ultimoUso fecha 'Y-m-d H:i:s' del ultimo uso, o null
     * @param ?string $creado    fecha 'Y-m-d H:i:s' de la creacion, o null
     * @param int     $ahora     marca de tiempo de la peticion (time())
     * @param ?int    $dias      dias de inactividad que se toleran; null = TOKEN_INACTIVIDAD_DIAS de config(), que tiene que ser un numero positivo
     *                           (config() lanza si el archivo no se puede leer, y esta funcion tambien si la clave falta o no es positiva: un config a medias no cuenta con 0 dias,
     *                           que vencerian y revocarian TODAS las sesiones de las empresas Express; quien llama niega la peticion, ver AuthMiddleware)
     */
    public static function tokenCaducado(?string $ultimoUso, ?string $creado, int $ahora, ?int $dias = null): bool
    {
        $momento = self::momentoDeToken($ultimoUso) ?? self::momentoDeToken($creado);
        if ($momento === null) {
            return false;
        }
        if ($dias === null) {
            $configurado = self::config()['TOKEN_INACTIVIDAD_DIAS'] ?? null;
            if (!is_numeric($configurado) || (int) $configurado < 1) {
                throw new RuntimeException('config/editions.php: TOKEN_INACTIVIDAD_DIAS falta o no es un numero positivo');
            }
            $dias = (int) $configurado;
        }
        return $ahora - $momento > $dias * 86400;
    }

    /** Una fecha de api_tokens como marca de tiempo; null si esta vacia, es la fecha cero de MySQL o no se puede leer. */
    private static function momentoDeToken(?string $fecha): ?int
    {
        $fecha = trim((string) $fecha);
        if ($fecha === '' || str_starts_with($fecha, '0000-')) {
            return null;
        }
        $marca = strtotime($fecha);
        return $marca === false ? null : $marca;
    }

    /**
     * El estado del certificado de la empresa: el bloque certificado de GET /api/emisor. Sale solo de la fila (cert_path y
     * cert_vence): no abre el archivo, no descifra la clave y jamas devuelve la ruta ni la clave. Es PURA y no pregunta la
     * edicion (eso lo hace quien la llama): un $tenant null es la empresa resuelta.
     *
     * Es informativo y no bloquea nada, ni con una fecha vieja: la fila se lee en cada peticion y de una a otra no se guarda
     * nada, asi que el aviso sigue a cert_vence en cuanto esta se refresca. Tampoco sirve para decidir si una empresa puede
     * emitir: la fecha de la fila puede haber quedado vieja.
     *
     * estado:
     *   no_configurado  cert_path vacio (NULL, '' o solo espacios), haya o no fecha; vence y dias salen null.
     *   sin_dato        hay cert_path pero cert_vence esta vacia o no es una fecha del calendario (la fecha cero de MySQL
     *                   incluida): no hay desde donde contar; vence y dias salen null.
     *   vencido         dias < 0.
     *   por_vencer      dias <= CERT_POR_VENCER_DIAS de config(); el dia del vencimiento cuenta (0 dias) y aun vale.
     *   vigente         el resto.
     * CERT_CRITICO_DIAS (el tono del aviso) es cosa del cliente: el servidor no lo usa.
     *
     * El calendario: cert_vence se guarda como la fecha del notAfter del .p12 en la zona horaria por defecto de PHP, asi que
     * "hoy" es date('Y-m-d', $hoy) en esa misma zona, y dias es la diferencia entre las medianoches UTC de las dos fechas
     * partida en 86400 (un entero exacto: UTC no cambia de hora). Nunca gmdate($hoy), que entre las 20:00 y las 24:00 de un
     * reloj UTC-4 daria el dia siguiente, ni la resta de marcas locales, que con un cambio de hora en medio mide un dia de
     * mas o de menos.
     *
     * @param ?array $tenant fila de master.tenants; null = la empresa resuelta en esta peticion (TenantResolver::current())
     * @param ?int   $hoy    marca de tiempo unix de "ahora"; null = time()
     * @return array{estado:string,vence:?string,dias:?int} siempre esas tres claves y en ese orden
     */
    public static function estadoCertificado(?array $tenant = null, ?int $hoy = null): array
    {
        $tenant ??= TenantResolver::current();
        if (trim((string) ($tenant['cert_path'] ?? '')) === '') {
            return self::resultadoCertificado('no_configurado', null, null);
        }
        $vence = self::fechaDeCertificado($tenant['cert_vence'] ?? null);
        if ($vence === null) {
            return self::resultadoCertificado('sin_dato', null, null);
        }
        $dias = intdiv(self::medianocheUtc($vence) - self::medianocheUtc(date('Y-m-d', $hoy ?? time())), 86400);
        $estado = match (true) {
            $dias < 0 => 'vencido',
            $dias <= (int) self::config()['CERT_POR_VENCER_DIAS'] => 'por_vencer',
            default => 'vigente',
        };
        return self::resultadoCertificado($estado, $vence, $dias);
    }

    /** cert_vence como 'YYYY-MM-DD'; null si esta vacia o no es una fecha del calendario (la fecha cero de MySQL y un 30 de febrero incluidos). */
    private static function fechaDeCertificado(mixed $valor): ?string
    {
        $fecha = is_string($valor) ? trim($valor) : '';
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $fecha, $partes) !== 1) {
            return null;
        }
        return checkdate((int) $partes[2], (int) $partes[3], (int) $partes[1]) ? $fecha : null;
    }

    /** Medianoche UTC de una fecha 'YYYY-MM-DD' valida: la resta de dos de ellas es un multiplo exacto de 86400. */
    private static function medianocheUtc(string $fecha): int
    {
        [$anio, $mes, $dia] = array_map('intval', explode('-', $fecha));
        return (int) gmmktime(0, 0, 0, $mes, $dia, $anio);
    }

    /** El resultado de estadoCertificado: siempre las mismas tres claves y en el mismo orden. */
    private static function resultadoCertificado(string $estado, ?string $vence, ?int $dias): array
    {
        return ['estado' => $estado, 'vence' => $vence, 'dias' => $dias];
    }

    /** El resultado de evaluarRuta: siempre las mismas cuatro claves y en el mismo orden. */
    private static function veredicto(bool $permitido, int $http, ?string $codigo, ?string $modulo): array
    {
        return ['permitido' => $permitido, 'http' => $http, 'codigo' => $codigo, 'modulo' => $modulo];
    }

    /**
     * Una linea de log por valor de edicion distinto y por proceso. El valor entra recortado y entre comillas, con los
     * saltos de linea escapados: quien edite la columna no puede partir ni inventar lineas del log.
     */
    private static function avisarEdicionDesconocida(string $valor): void
    {
        if (isset(self::$edicionesAvisadas[$valor])) {
            return;
        }
        self::$edicionesAvisadas[$valor] = true;
        $literal = json_encode(
            substr($valor, 0, 64),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
        error_log('[TenantEntitlements] edicion desconocida ' . $literal . ' en master.tenants.edicion: se trata como completa');
    }
}
