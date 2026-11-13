<?php
require_once __DIR__ . '/MasterDatabase.php';
require_once __DIR__ . '/TenantResolver.php';
require_once __DIR__ . '/AuditLogger.php';

/**
 * IntegracionRequestLog — bitacora de cada peticion de un cliente de integracion y
 * de lo que se le contesto (master.integracion_request_log, master_migrations/018).
 * Como consultarla: docs/integrations/integracion-request-log.md.
 *
 * Por que: un tenant de integracion no tiene base ni pantalla propias. Cuando el
 * cliente reporta "me da error", lo unico que habia era el error_log del server
 * (sin shell) y ecf_integracion_backup, que solo existe si el e-CF se emitio.
 * Caso que lo origino (2026-11-13, tenant 18): "Fallo consultando el estado a DGII:
 * ... HTTP 401" sin rastro de que e-NCF, track_id o codigo_seguridad se pidio.
 *
 * Como: el Router llama iniciarSiAplica() antes de la pagina de docs, del
 * PermissionGate y de los controllers. Si la peticion es de integracion (ruta
 * /api/integracion/* o trae X-API-SECRET), abre un buffer de salida y registra una
 * funcion de apagado. Al terminar la peticion — normal, exit, sendUnauthorized() o
 * un fallo que ErrorHandler convierte en 500 — esa funcion lee el codigo HTTP y el
 * cuerpo del buffer y guarda UNA fila. Los controllers solo agregan anotar() en sus
 * catch: el detalle que no sale al cliente.
 *
 * Garantias:
 *   - NUNCA rompe ni cambia la respuesta: un fallo al guardar va al error_log.
 *   - Sin X-API-SECRET, Authorization ni cookies; del X-API-KEY, solo los primeros 8.
 *   - Un cuerpo JSON con campos sensibles (password, secret, token...: la lista de
 *     AuditLogger) se guarda redactado; si no tiene, tal cual llego, byte a byte.
 *   - El XML firmado de la respuesta se guarda como marcador: ya esta en
 *     ecf_integracion_backup (o ecf_recibidos) y multiplicaria el tamaño de la tabla.
 *   - Topes: 1 MB por cuerpo; 16 KB si la respuesta no es JSON (HTML de un error).
 *   - Purga sola las filas de mas de INTEGRACION_LOG_DIAS dias (90; 0 = nunca).
 *   - Se apaga con INTEGRACION_LOG_ENABLED=false.
 */
class IntegracionRequestLog
{
    public const TOPE_CUERPO = 1048576;
    public const TOPE_NO_JSON = 16384;
    private const DIAS_DEFAULT = 90;
    /** Una de cada N peticiones registradas purga un lote de filas viejas. */
    private const PURGA_UNA_DE = 100;
    private const PURGA_LOTE = 1000;
    /** Un string de la respuesta con al menos estos bytes que parece XML se omite. */
    private const XML_MINIMO = 200;
    private const TOPE_ERROR_INTERNO = 60000;

    private static bool $activo = false;
    private static float $inicio = 0.0;
    private static int $nivelBuffer = 0;
    /** @var list<string> */
    private static array $notas = [];
    private static ?PDO $conexion = null;

    public static function habilitado(): bool
    {
        $raw = getenv('INTEGRACION_LOG_ENABLED');
        if ($raw === false || $raw === '') {
            $raw = $_ENV['INTEGRACION_LOG_ENABLED'] ?? 'true'; // default ON
        }
        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * ¿Se registra esta peticion? Las de /api/integracion/* y, en cualquier ruta, las
     * que traen X-API-SECRET (un cliente de integracion que pega a la ruta que no es
     * tambien quiere saber por que le falla). El preflight OPTIONS nunca.
     *
     * @param array<string,mixed> $server
     */
    public static function aplica(array $server): bool
    {
        if (strtoupper((string) ($server['REQUEST_METHOD'] ?? '')) === 'OPTIONS') {
            return false;
        }
        if (isset($server['HTTP_X_API_SECRET'])) {
            return true;
        }
        $ruta = strtolower((string) parse_url((string) ($server['REQUEST_URI'] ?? ''), PHP_URL_PATH));
        return preg_match('#/api/integracion(/|$)#', $ruta) === 1;
    }

    /**
     * Empieza a registrar la peticion en curso si aplica(). Una vez por peticion.
     *
     * @param PDO|null $conexion Solo para pruebas (SQLite); en uso normal, el master.
     * @return bool true si la peticion quedo registrandose
     */
    public static function iniciarSiAplica(?PDO $conexion = null): bool
    {
        if (self::$activo || !self::habilitado() || !self::aplica($_SERVER)) {
            return false;
        }
        self::$activo = true;
        self::$conexion = $conexion;
        self::$notas = [];
        self::$inicio = microtime(true);
        ob_start();
        self::$nivelBuffer = ob_get_level();
        register_shutdown_function([self::class, 'alTerminar']);
        return true;
    }

    /**
     * Detalle interno de un fallo (la excepcion completa, con sus causas): va a la
     * columna error_interno, nunca al cliente. Se acumula si hay varios. No hace
     * nada si la peticion no se esta registrando.
     *
     * @param Throwable|string $detalle
     */
    public static function anotar($detalle): void
    {
        if (!self::$activo) {
            return;
        }
        if ($detalle instanceof Throwable) {
            $partes = [];
            for ($e = $detalle, $i = 0; $e !== null && $i < 5; $e = $e->getPrevious(), $i++) {
                $partes[] = get_class($e) . ': ' . $e->getMessage()
                    . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
            }
            $texto = implode("\n  causa: ", $partes);
        } else {
            $texto = (string) $detalle;
        }
        if (trim($texto) !== '') {
            self::$notas[] = $texto;
        }
    }

    /** Funcion de apagado (register_shutdown_function): guarda la fila. Nunca lanza. */
    public static function alTerminar(): void
    {
        if (!self::$activo) {
            return;
        }
        self::$activo = false;
        try {
            $respuesta = self::salidaCapturada();
            $status = http_response_code();
            $fila = self::armarFila(
                $_SERVER,
                (string) @file_get_contents('php://input'),
                is_int($status) && $status > 0 ? $status : 200,
                $respuesta,
                class_exists('TenantResolver', false) ? TenantResolver::current() : null,
                self::$notas,
                (int) round((microtime(true) - self::$inicio) * 1000)
            );
            $pdo = self::$conexion ?? MasterDatabase::getInstance()->getConnection();
            self::guardar($pdo, $fila);
            if (random_int(1, self::PURGA_UNA_DE) === 1) {
                self::purgar($pdo, self::dias());
            }
        } catch (Throwable $e) {
            error_log('[IntegracionRequestLog] no se guardo ' . ($_SERVER['REQUEST_METHOD'] ?? '?') . ' '
                . ($_SERVER['REQUEST_URI'] ?? '?') . ': ' . $e->getMessage());
        }
    }

    /**
     * La fila a guardar. Pura (sin globals, base ni buffers): la usan alTerminar() y
     * las pruebas.
     *
     * @param array<string,mixed> $server       $_SERVER de la peticion
     * @param string              $cuerpoPedido el body tal como llego (php://input)
     * @param string              $respuesta    lo que se le envio al cliente
     * @param array|null          $tenant       la empresa por la que se actuo
     * @param list<string>        $notas        detalle interno (anotar())
     * @return array<string,int|string|null>
     */
    public static function armarFila(
        array $server,
        string $cuerpoPedido,
        int $status,
        string $respuesta,
        ?array $tenant,
        array $notas,
        int $duracionMs
    ): array {
        $uri = (string) ($server['REQUEST_URI'] ?? '');
        $partes = parse_url($uri);
        $ruta = is_array($partes) ? (string) ($partes['path'] ?? '') : $uri;
        $query = is_array($partes) ? (string) ($partes['query'] ?? '') : '';
        $params = [];
        if ($query !== '') {
            parse_str($query, $params);
        }

        $pedidoJson = json_decode($cuerpoPedido, true);
        $pedidoJson = is_array($pedidoJson) ? $pedidoJson : null;
        $respuestaJson = json_decode($respuesta, true);
        $respuestaJson = is_array($respuestaJson) ? $respuestaJson : null;

        [$bodyPedido, $bytesPedido] = self::cuerpoPedido($cuerpoPedido, $pedidoJson);
        [$bodyRespuesta, $bytesRespuesta] = self::cuerpoRespuesta($respuesta, $respuestaJson);

        $apiKey = trim((string) ($server['HTTP_X_API_KEY'] ?? ''));

        return [
            'tenant_id'       => isset($tenant['id']) ? (int) $tenant['id'] : null,
            'rnc'             => self::rnc($tenant, $pedidoJson, $params),
            'api_key_prefijo' => $apiKey !== '' ? self::corto(substr($apiKey, 0, 8), 8) : null,
            'metodo'          => self::corto(strtoupper((string) ($server['REQUEST_METHOD'] ?? '?')), 10),
            'ruta'            => self::corto($ruta, 255),
            'query_string'    => $query !== '' ? self::corto(self::querySinSecretos($query, $params), 1000) : null,
            'e_ncf'           => self::eNcf($pedidoJson, $params, $respuestaJson),
            'ip'              => self::ip($server),
            'user_agent'      => isset($server['HTTP_USER_AGENT']) ? self::corto((string) $server['HTTP_USER_AGENT'], 255) : null,
            'request_headers' => self::cabeceras($server),
            'request_body'    => $bodyPedido,
            'request_bytes'   => $bytesPedido,
            'http_status'     => $status,
            'response_body'   => $bodyRespuesta,
            'response_bytes'  => $bytesRespuesta,
            'error_interno'   => $notas ? self::recortar(implode("\n", $notas), self::TOPE_ERROR_INTERNO) : null,
            'duracion_ms'     => max(0, $duracionMs),
        ];
    }

    /** @param array<string,int|string|null> $fila salida de armarFila() */
    public static function guardar(PDO $pdo, array $fila): void
    {
        $columnas = array_keys($fila);
        $stmt = $pdo->prepare(
            'INSERT INTO integracion_request_log (' . implode(', ', $columnas) . ')'
            . ' VALUES (:' . implode(', :', $columnas) . ')'
        );
        foreach ($fila as $columna => $valor) {
            $tipo = $valor === null ? PDO::PARAM_NULL : (is_int($valor) ? PDO::PARAM_INT : PDO::PARAM_STR);
            $stmt->bindValue(':' . $columna, $valor, $tipo);
        }
        $stmt->execute();
    }

    /**
     * Borra las filas de mas de $dias dias (un lote en MySQL, para no demorar la
     * respuesta). $dias <= 0: no borra nada. Devuelve cuantas borro.
     */
    public static function purgar(PDO $pdo, int $dias): int
    {
        if ($dias <= 0) {
            return 0;
        }
        // Corte en hora de la app (RD), la misma de created_at: src/ZonaHoraria.php.
        $corte = date('Y-m-d H:i:s', time() - $dias * 86400);
        $sql = 'DELETE FROM integracion_request_log WHERE created_at < :corte';
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $sql .= ' LIMIT ' . self::PURGA_LOTE;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':corte' => $corte]);
        return $stmt->rowCount();
    }

    // ---- helpers ---------------------------------------------------------------

    private static function dias(): int
    {
        $raw = getenv('INTEGRACION_LOG_DIAS');
        if ($raw === false || $raw === '') {
            $raw = $_ENV['INTEGRACION_LOG_DIAS'] ?? '';
        }
        return is_numeric($raw) ? (int) $raw : self::DIAS_DEFAULT;
    }

    /** Todo lo que la peticion escribio, sin cerrar nuestro buffer (PHP lo envia despues). */
    private static function salidaCapturada(): string
    {
        // Un buffer que alguien abrio encima del nuestro y no cerro es parte de la
        // respuesta (PHP lo enviaria igual al terminar): baja al nuestro.
        while (ob_get_level() > self::$nivelBuffer) {
            if (!@ob_end_flush()) {
                break;
            }
        }
        if (ob_get_level() < self::$nivelBuffer) {
            return ''; // alguien cerro nuestro buffer: lo escrito ya salio sin pasar por aqui
        }
        $salida = ob_get_contents();
        return $salida === false ? '' : $salida;
    }

    /** @return array{0: ?string, 1: int} [texto a guardar, bytes originales] */
    private static function cuerpoPedido(string $raw, ?array $json): array
    {
        $bytes = strlen($raw);
        if ($bytes === 0) {
            return [null, 0];
        }
        $texto = $raw;
        if ($json !== null && self::tieneSensibles($json)) {
            $texto = json_encode(
                AuditLogger::redactar($json),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
            ) ?: '[cuerpo con campos sensibles: no se pudo redactar]';
        }
        return [self::recortar($texto, self::TOPE_CUERPO), $bytes];
    }

    /** @return array{0: ?string, 1: int} [texto a guardar, bytes originales] */
    private static function cuerpoRespuesta(string $raw, ?array $json): array
    {
        $bytes = strlen($raw);
        if ($bytes === 0) {
            return [null, 0];
        }
        if ($json === null) {
            // No es JSON (la pagina de docs, el HTML de un error del hosting): basta el principio.
            return [self::recortar($raw, self::TOPE_NO_JSON), $bytes];
        }
        $omitidos = 0;
        $limpio = self::sinXml($json, $omitidos);
        $texto = $omitidos > 0
            ? (json_encode($limpio, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: $raw)
            : $raw;
        return [self::recortar($texto, self::TOPE_CUERPO), $bytes];
    }

    private static function sinXml($valor, int &$omitidos, int $profundidad = 0)
    {
        if (is_array($valor) && $profundidad < 12) {
            foreach ($valor as $k => $v) {
                $valor[$k] = self::sinXml($v, $omitidos, $profundidad + 1);
            }
            return $valor;
        }
        if (is_string($valor) && strlen($valor) >= self::XML_MINIMO && self::pareceXml($valor)) {
            $omitidos++;
            return '[XML omitido de la bitacora: ' . strlen($valor) . ' bytes]';
        }
        return $valor;
    }

    private static function pareceXml(string $s): bool
    {
        $s = ltrim($s);
        return str_starts_with($s, '<') && (str_contains($s, '</') || str_contains($s, '/>'));
    }

    private static function tieneSensibles($datos, int $profundidad = 0): bool
    {
        if (!is_array($datos) || $profundidad > 12) {
            return false;
        }
        foreach ($datos as $k => $v) {
            if (is_string($k) && AuditLogger::esCampoSensible($k)) {
                return true;
            }
            if (is_array($v) && self::tieneSensibles($v, $profundidad + 1)) {
                return true;
            }
        }
        return false;
    }

    /** El query string tal cual, salvo que traiga un parametro sensible: entonces, redactado. */
    private static function querySinSecretos(string $query, array $params): string
    {
        return self::tieneSensibles($params)
            ? urldecode(http_build_query(AuditLogger::redactar($params)))
            : $query;
    }

    /** JSON con las cabeceras de la peticion, sin credenciales ni cookies. */
    private static function cabeceras(array $server): ?string
    {
        $salida = [];
        foreach ($server as $clave => $valor) {
            if (!is_string($clave) || !is_scalar($valor)) {
                continue;
            }
            if (str_starts_with($clave, 'HTTP_')) {
                $nombre = substr($clave, 5);
            } elseif ($clave === 'CONTENT_TYPE' || $clave === 'CONTENT_LENGTH') {
                $nombre = $clave;
            } else {
                continue;
            }
            $nombre = strtolower($nombre);
            // x_api_key, x_api_secret, authorization... (lista de AuditLogger); la
            // cookie y el user agent (este tiene su columna) aparte.
            if (in_array($nombre, ['cookie', 'user_agent'], true) || AuditLogger::esCampoSensible($nombre)) {
                continue;
            }
            $salida[str_replace('_', '-', $nombre)] = self::corto((string) $valor, 500);
        }
        if (!$salida) {
            return null;
        }
        ksort($salida);
        $json = json_encode($salida, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? null : self::recortar($json, 60000);
    }

    private static function rnc(?array $tenant, ?array $pedido, array $params): ?string
    {
        $candidatos = [
            $tenant['rnc'] ?? null,
            is_array($pedido['emisor'] ?? null) ? ($pedido['emisor']['rnc'] ?? null) : null,
            $pedido['rnc_comprador'] ?? null,
            $params['rnc'] ?? null,
        ];
        foreach ($candidatos as $c) {
            if (is_scalar($c)) {
                $digitos = (string) preg_replace('/\D/', '', (string) $c);
                if ($digitos !== '') {
                    return substr($digitos, 0, 11);
                }
            }
        }
        return null;
    }

    private static function eNcf(?array $pedido, array $params, ?array $respuesta): ?string
    {
        $candidatos = [
            $pedido['e_ncf'] ?? null,
            $params['e_ncf'] ?? null,
            is_array($respuesta['data'] ?? null) ? ($respuesta['data']['e_ncf'] ?? null) : null,
            $respuesta['e_ncf'] ?? null,
        ];
        foreach ($candidatos as $c) {
            if (is_scalar($c)) {
                $valor = strtoupper(trim((string) $c));
                if ($valor !== '') {
                    return self::corto($valor, 19);
                }
            }
        }
        return null;
    }

    /** Igual que RequestContext::ip(), pero de un $server dado. */
    private static function ip(array $server): ?string
    {
        if (!empty($server['HTTP_X_FORWARDED_FOR'])) {
            $primera = trim(explode(',', (string) $server['HTTP_X_FORWARDED_FOR'])[0]);
            if ($primera !== '') {
                return self::corto($primera, 45);
            }
        }
        return isset($server['REMOTE_ADDR']) ? self::corto((string) $server['REMOTE_ADDR'], 45) : null;
    }

    /**
     * Texto para una columna de cuerpo (MEDIUMTEXT/TEXT): UTF-8 valido (MySQL rechaza
     * la fila entera si no) y con tope; si se recorto, lo dice al final.
     */
    private static function recortar(string $s, int $tope): string
    {
        if ($s !== '' && preg_match('//u', $s) !== 1) {
            // Un JSON en Latin-1/Windows-1252 es un error tipico de un cliente (y la
            // causa de su "JSON body invalido"): se guarda legible y se avisa.
            $s = '[no venia en UTF-8; se guarda leido como ISO-8859-1] ' . self::latin1AUtf8($s);
        }
        $total = strlen($s);
        if ($total <= $tope) {
            return $s;
        }
        return self::cortarUtf8($s, $tope) . "\n...[recortado: se guardaron {$tope} de {$total} bytes]";
    }

    /** Texto para una columna VARCHAR corta: UTF-8 valido (lo invalido pasa a '?') y a lo sumo $max bytes. */
    private static function corto(string $s, int $max): string
    {
        if ($s !== '' && preg_match('//u', $s) !== 1) {
            $s = (string) preg_replace('/[\x80-\xFF]/', '?', $s);
        }
        return self::cortarUtf8($s, $max);
    }

    /** substr que no parte un caracter de varios bytes (la fila no entraria en MySQL). */
    private static function cortarUtf8(string $s, int $max): string
    {
        if (strlen($s) <= $max) {
            return $s;
        }
        $s = substr($s, 0, $max);
        for ($i = 0; $i < 4 && $s !== '' && preg_match('//u', $s) !== 1; $i++) {
            $s = substr($s, 0, -1);
        }
        return $s;
    }

    /** ISO-8859-1 -> UTF-8 sin mbstring ni iconv (no siempre estan en el hosting). */
    private static function latin1AUtf8(string $s): string
    {
        return (string) preg_replace_callback(
            '/[\x80-\xFF]/',
            static fn(array $m): string => chr(0xC0 | (ord($m[0]) >> 6)) . chr(0x80 | (ord($m[0]) & 0x3F)),
            $s
        );
    }
}
