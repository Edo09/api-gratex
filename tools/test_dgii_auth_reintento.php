<?php
/**
 * test_dgii_auth_reintento.php — El reintento del login saliente a la DGII
 * (DgiiAuthService::autenticar). Sin red, sin base y sin esperar de verdad: la peticion, el
 * reloj, la pausa, la firma y la bitacora se sustituyen por un guion (AuthGuion).
 *
 * Origen (2026-11-28, tenants 17 y 18, ambiente ecf): la DGII reseteaba la mitad de las
 * conexiones de ecf.dgii.gov.do/ecf/autenticacion ("OpenSSL SSL_read: Connection reset by
 * peer", timeouts de 30 s, "Received HTTP/0.9 when not allowed"). Cada emision y cada consulta
 * de estado piden un token nuevo, y un solo tropiezo del login tumbaba la factura: 2 de 13
 * intentos llegaron a la DGII en la peor hora.
 *
 * Lo que fija:
 *   - Un fallo de RED del login (DgiiTransportException) repite el login completo, con una
 *     semilla nueva: hasta 3 intentos, pausa de 1 s y luego de 2 s.
 *   - Una respuesta HTTP de la DGII (401, 503, cuerpo sin token) o un fallo propio (la firma)
 *     NO se repite: falla a la primera, con el mismo mensaje de siempre.
 *   - Los tiempos: 10 s por llamada y ~30 s en total; con un 'timeout' pedido (el POS), ese
 *     numero es tambien el presupuesto de todo el login.
 *   - El envio del e-CF y las consultas (consultarEndpointAutenticado) siguen con UN intento.
 *   - La bitacora: el fallo final lleva los intentos; el login que solo salio al reintentar
 *     deja DGII_AUTH_OUT_RETRY_OK; el login sano no deja nada.
 *
 * Uso:
 *   php tools/test_dgii_auth_reintento.php      (sale con 1 si algo falla)
 */

require_once __DIR__ . '/write_ahead_test_lib.php';
require_once __DIR__ . '/../src/Utils/FacturacionElectronica/DgiiAuthService.php';

/** DgiiAuthService con la red, el reloj, la pausa, la firma y la bitacora de mentira. */
final class AuthGuion extends DgiiAuthService
{
    /** @var list<Closure(AuthGuion, int): array> una entrada por llamada de red, en orden */
    public array $guion = [];
    /** @var list<array{0: string, 1: int}> [semilla|validarsemilla|otra, timeout] de cada llamada */
    public array $llamadas = [];
    /** @var list<int> milisegundos de cada pausa entre intentos */
    public array $pausas = [];
    /** @var list<array<string, mixed>> lo que autenticar() mando a la bitacora */
    public array $eventos = [];
    public int $firmas = 0;
    public bool $fallaLaFirma = false;
    /** Hora de mentira, en segundos: la mueven las llamadas del guion y las pausas. */
    public float $t = 1000.0;

    protected function request(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        $paso = array_shift($this->guion);
        if ($paso === null) {
            throw new LogicException('el guion se acabo: ' . $method . ' ' . $url);
        }
        $cual = str_ends_with($url, '/semilla') ? 'semilla' : (str_ends_with($url, '/validarsemilla') ? 'validarsemilla' : 'otra');
        $this->llamadas[] = [$cual, $timeout];

        return $paso($this, $timeout);
    }

    protected function reloj(): float
    {
        return $this->t;
    }

    protected function pausar(int $milisegundos): void
    {
        $this->pausas[] = $milisegundos;
        $this->t += $milisegundos / 1000;
    }

    protected function auditar(array $evento): void
    {
        $this->eventos[] = $evento;
    }

    public function firmarSemilla(string $semillaXml, array $options = []): string
    {
        $this->firmas++;
        if ($this->fallaLaFirma) {
            throw new RuntimeException('Missing certificate password. Configure DGII_ECF_CERT_PASSWORD or send certificate_password.');
        }

        return '<semilla-firmada/>';
    }

    /** Segundos de mentira que pasaron desde el inicio de la corrida. */
    public function transcurrido(): float
    {
        return round($this->t - 1000.0, 3);
    }
}

// --- Pasos del guion ---------------------------------------------------------------------------
$semillaOk = static fn (string $fecha, float $seg = 0.2): Closure => static function (AuthGuion $a) use ($fecha, $seg): array {
    $a->t += $seg;
    return ['status_code' => 200, 'headers' => '', 'body' => "<SemillaModel><valor>v-{$fecha}</valor><fecha>{$fecha}</fecha></SemillaModel>"];
};
$tokenOk = static fn (string $token = 'TOKEN-SECRETO-1', float $seg = 0.2): Closure => static function (AuthGuion $a) use ($token, $seg): array {
    $a->t += $seg;
    return ['status_code' => 200, 'headers' => '', 'body' => json_encode(['token' => $token, 'expira' => '2026-11-28T11:00:00', 'expedido' => '2026-11-28T10:00:00'])];
};
$reset = static fn (string $msg = 'HTTP request failed: OpenSSL SSL_read: Connection reset by peer, errno 104', float $seg = 0.2): Closure => static function (AuthGuion $a) use ($msg, $seg): array {
    $a->t += $seg;
    throw new DgiiTransportException($msg);
};
/** La DGII no contesta: la llamada se rinde justo al cumplirse el timeout que se le dio. */
$cuelga = static fn (): Closure => static function (AuthGuion $a, int $timeout): array {
    $a->t += $timeout;
    throw new DgiiTransportException('HTTP request failed: Operation timed out after ' . ($timeout * 1000) . ' milliseconds with 0 bytes received');
};
$http = static fn (int $codigo, string $cuerpo = '', string $cabeceras = ''): Closure => static function (AuthGuion $a) use ($codigo, $cuerpo, $cabeceras): array {
    $a->t += 0.2;
    return ['status_code' => $codigo, 'headers' => $cabeceras, 'body' => $cuerpo];
};

/** Corre autenticar() con un guion: [token|null, excepcion|null]. */
$correr = static function (AuthGuion $a, array $guion, array $opciones = []): array {
    $a->guion = $guion;
    try {
        return [$a->autenticar($opciones + ['environment' => 'ecf', 'base_url' => 'https://dgii.invalid']), null];
    } catch (Throwable $e) {
        return [null, $e];
    }
};

/** Corre $fn con estas variables de entorno (null = sin definir) y las deja como estaban. */
$conEnv = static function (array $vars, callable $fn): mixed {
    $antes = [];
    foreach ($vars as $k => $_) {
        $antes[$k] = getenv($k);
    }
    foreach ($vars as $k => $v) {
        $v === null ? putenv($k) : putenv($k . '=' . $v);
    }
    try {
        return $fn();
    } finally {
        foreach ($antes as $k => $v) {
            $v === false ? putenv($k) : putenv($k . '=' . $v);
        }
    }
};
/** Sin ajustes propios del login: lo que corre en produccion si nadie toco el .env. */
$ENV_BASE = ['DGII_AUTH_INTENTOS' => null, 'DGII_AUTH_TIMEOUT' => null, 'DGII_ECF_TIMEOUT' => '30'];
/** Corre un escenario con el entorno base mas lo que pida. */
$escenario = static function (array $guion, array $opciones = [], array $env = []) use ($correr, $conEnv, $ENV_BASE): array {
    $a = new AuthGuion();
    [$token, $e] = $conEnv($env + $ENV_BASE, static fn () => $correr($a, $guion, $opciones));
    return [$a, $token, $e];
};
$accion = static fn (AuthGuion $a, string $nombre): ?array => array_values(array_filter($a->eventos, static fn (array $ev): bool => ($ev['action'] ?? '') === $nombre))[0] ?? null;

// ---------------------------------------------------------------------------
echo "1) El login sano no cambia: un intento, sin pausas y sin bitacora\n";
[$a, $token, $e] = $escenario([$semillaOk('F1'), $tokenOk('TOKEN-SECRETO-1')]);
wa_chk('devuelve el token y la fecha de la semilla', $e === null && ($token['token'] ?? null) === 'TOKEN-SECRETO-1' && ($token['semilla_fecha'] ?? null) === 'F1', [$token, $e?->getMessage()]);
wa_chk('dos llamadas (semilla y validarsemilla), de 10 s cada una', $a->llamadas === [['semilla', 10], ['validarsemilla', 10]], $a->llamadas);
wa_chk('una firma, ninguna pausa, ninguna fila de bitacora', $a->firmas === 1 && $a->pausas === [] && $a->eventos === [], [$a->firmas, $a->pausas, $a->eventos]);

// ---------------------------------------------------------------------------
echo "2) Un reset al pedir la semilla: se reintenta y sale\n";
[$a, $token, $e] = $escenario([$reset(), $semillaOk('F2'), $tokenOk('TOKEN-SECRETO-2')]);
wa_chk('devuelve el token del segundo intento, con SU semilla', $e === null && ($token['token'] ?? null) === 'TOKEN-SECRETO-2' && ($token['semilla_fecha'] ?? null) === 'F2', [$token, $e?->getMessage()]);
wa_chk('semilla, semilla, validarsemilla, todas de 10 s', $a->llamadas === [['semilla', 10], ['semilla', 10], ['validarsemilla', 10]], $a->llamadas);
wa_chk('una pausa de 1 s', $a->pausas === [1000], $a->pausas);
$ev = $accion($a, 'DGII_AUTH_OUT_RETRY_OK');
wa_chk('deja UNA fila DGII_AUTH_OUT_RETRY_OK, de exito, con 2 intentos y el primer error',
    count($a->eventos) === 1 && $ev !== null && $ev['success'] === true && ($ev['new_values']['intentos'] ?? null) === 2
    && str_contains((string) ($ev['new_values']['primer_error'] ?? ''), 'Connection reset by peer'), $a->eventos);
wa_chk('la fila de bitacora no lleva el token', !str_contains((string) json_encode($a->eventos), 'TOKEN-SECRETO'), $a->eventos);

// ---------------------------------------------------------------------------
echo "3) Un reset al validar la semilla firmada: el reintento pide una semilla NUEVA\n";
[$a, $token, $e] = $escenario([$semillaOk('F3a'), $reset(), $semillaOk('F3b'), $tokenOk('TOKEN-SECRETO-3')]);
wa_chk('devuelve el token y la semilla del segundo intento', $e === null && ($token['token'] ?? null) === 'TOKEN-SECRETO-3' && ($token['semilla_fecha'] ?? null) === 'F3b', [$token, $e?->getMessage()]);
wa_chk('semilla, validar (cae), semilla, validar', array_column($a->llamadas, 0) === ['semilla', 'validarsemilla', 'semilla', 'validarsemilla'], $a->llamadas);
wa_chk('se firmo dos veces: la semilla vieja no se reusa', $a->firmas === 2, $a->firmas);
wa_chk('una pausa de 1 s y una fila RETRY_OK con 2 intentos', $a->pausas === [1000] && ($accion($a, 'DGII_AUTH_OUT_RETRY_OK')['new_values']['intentos'] ?? null) === 2, [$a->pausas, $a->eventos]);

// ---------------------------------------------------------------------------
echo "4) Dos fallos y sale al tercero\n";
[$a, $token, $e] = $escenario([$reset(), $reset(), $semillaOk('F4'), $tokenOk('TOKEN-SECRETO-4')]);
wa_chk('devuelve el token', $e === null && ($token['token'] ?? null) === 'TOKEN-SECRETO-4', [$token, $e?->getMessage()]);
wa_chk('pausas de 1 s y de 2 s', $a->pausas === [1000, 2000], $a->pausas);
wa_chk('RETRY_OK con 3 intentos', ($accion($a, 'DGII_AUTH_OUT_RETRY_OK')['new_values']['intentos'] ?? null) === 3, $a->eventos);

// ---------------------------------------------------------------------------
echo "5) La DGII sigue reseteando: 3 intentos y falla con el mensaje de siempre\n";
[$a, $token, $e] = $escenario([$reset('HTTP request failed: A'), $reset('HTTP request failed: B'), $reset('HTTP request failed: C'), $reset('HTTP request failed: D')]);
wa_chk('lanza DgiiTransportException (que es una RuntimeException) con el mensaje del ULTIMO intento',
    $e instanceof DgiiTransportException && $e instanceof RuntimeException && $e->getMessage() === 'HTTP request failed: C', $e === null ? $token : [get_class($e), $e->getMessage()]);
wa_chk('3 llamadas (la cuarta del guion no se usa) y pausas de 1 s y 2 s', count($a->llamadas) === 3 && count($a->guion) === 1 && $a->pausas === [1000, 2000], [$a->llamadas, $a->pausas]);
$ev = $accion($a, 'DGII_AUTH_OUT_FAILED');
wa_chk('UNA fila DGII_AUTH_OUT_FAILED de fallo, con paso, 3 intentos y el error del ultimo',
    count($a->eventos) === 1 && $ev !== null && $ev['success'] === false && ($ev['new_values']['intentos'] ?? null) === 3
    && ($ev['new_values']['paso'] ?? null) === 'pedir la semilla' && ($ev['error_message'] ?? null) === 'HTTP request failed: C', $a->eventos);
wa_chk('la descripcion dice cuantos intentos', ($ev['description'] ?? '') === 'La DGII no entrego el token de autenticacion (fallo al pedir la semilla, tras 3 intentos).', $ev['description'] ?? null);

// ---------------------------------------------------------------------------
echo "6) Una respuesta de la DGII no se repite (es un veredicto, no un tropiezo)\n";
$cab401 = "HTTP/1.1 401 Unauthorized\r\nWWW-Authenticate: Bearer error=\"invalid_token\"\r\n";
[$a, $token, $e] = $escenario([$semillaOk('F6'), $http(401, '', $cab401), $semillaOk('F6b'), $tokenOk()]);
wa_chk('401 al validar: RuntimeException normal (no de red) con el mensaje de siempre',
    $e instanceof RuntimeException && !($e instanceof DgiiTransportException) && str_starts_with($e->getMessage(), 'DGII seed validation failed: HTTP 401'), $e === null ? $token : [get_class($e), $e->getMessage()]);
wa_chk('un solo intento: 2 llamadas, 1 firma, ninguna pausa', count($a->llamadas) === 2 && $a->firmas === 1 && $a->pausas === [], [$a->llamadas, $a->firmas, $a->pausas]);
$ev = $accion($a, 'DGII_AUTH_OUT_FAILED');
wa_chk('la bitacora queda como siempre: 1 intento y la misma descripcion de antes',
    $ev !== null && ($ev['new_values']['intentos'] ?? null) === 1 && ($ev['new_values']['paso'] ?? null) === 'validar la semilla firmada'
    && ($ev['description'] ?? '') === 'La DGII no entrego el token de autenticacion (fallo al validar la semilla firmada).', $a->eventos);

[$a, $token, $e] = $escenario([$http(503, '<html><body>Servicio no disponible</body></html>'), $semillaOk('F6c'), $tokenOk()]);
wa_chk('503 al pedir la semilla: no se repite', $e instanceof RuntimeException && !($e instanceof DgiiTransportException)
    && str_starts_with($e->getMessage(), 'DGII seed request failed: HTTP 503') && count($a->llamadas) === 1 && $a->pausas === [], $e === null ? $token : $e->getMessage());

[$a, $token, $e] = $escenario([$semillaOk('F6d'), $http(200, '<html>no es un token</html>'), $semillaOk('F6e'), $tokenOk()]);
wa_chk('200 con un cuerpo que no es un token: no se repite', $e instanceof RuntimeException && !($e instanceof DgiiTransportException)
    && count($a->llamadas) === 2 && $a->pausas === [], $e === null ? $token : $e->getMessage());

// ---------------------------------------------------------------------------
echo "7) Un fallo propio (la firma) tampoco se repite\n";
$a = new AuthGuion();
$a->fallaLaFirma = true;
[$token, $e] = $conEnv($ENV_BASE, static fn () => $correr($a, [$semillaOk('F7'), $tokenOk()]));
wa_chk('sale a la primera con el mensaje de siempre', $e instanceof RuntimeException && !($e instanceof DgiiTransportException)
    && str_starts_with($e->getMessage(), 'Missing certificate password'), $e === null ? $token : $e->getMessage());
$ev = $accion($a, 'DGII_AUTH_OUT_FAILED');
wa_chk('una llamada, sin pausas, y la bitacora dice que fallo al firmar', count($a->llamadas) === 1 && $a->pausas === [] && ($ev['new_values']['paso'] ?? null) === 'firmar la semilla', [$a->llamadas, $a->eventos]);

// ---------------------------------------------------------------------------
echo "8) Reset y luego un 401: el reintento se detiene en el veredicto\n";
[$a, $token, $e] = $escenario([$reset(), $semillaOk('F8'), $http(401, '', $cab401), $semillaOk('F8b'), $tokenOk()]);
wa_chk('lanza el 401 del segundo intento (no repite una tercera vez)', $e instanceof RuntimeException && !($e instanceof DgiiTransportException)
    && str_starts_with($e->getMessage(), 'DGII seed validation failed: HTTP 401') && count($a->llamadas) === 3 && $a->pausas === [1000], $e === null ? $token : [$e->getMessage(), $a->llamadas]);
$ev = $accion($a, 'DGII_AUTH_OUT_FAILED');
wa_chk('FAILED con 2 intentos y sin fila RETRY_OK', count($a->eventos) === 1 && ($ev['new_values']['intentos'] ?? null) === 2 && str_contains((string) ($ev['description'] ?? ''), 'tras 2 intentos'), $a->eventos);

// ---------------------------------------------------------------------------
echo "9) DGII_AUTH_INTENTOS: apaga, acorta, alarga con tope y vacio = el de siempre\n";
[$a, , $e] = $escenario([$reset(), $reset(), $reset()], [], ['DGII_AUTH_INTENTOS' => '1']);
wa_chk('=1: sin reintentos (una llamada, ninguna pausa)', $e instanceof DgiiTransportException && count($a->llamadas) === 1 && $a->pausas === [], [$a->llamadas, $a->pausas]);
[$a, , $e] = $escenario([$reset(), $reset(), $reset()], [], ['DGII_AUTH_INTENTOS' => '0']);
wa_chk('=0: tambien sin reintentos', $e instanceof DgiiTransportException && count($a->llamadas) === 1, $a->llamadas);
[$a, , $e] = $escenario([$reset(), $reset(), $reset()], [], ['DGII_AUTH_INTENTOS' => '2']);
wa_chk('=2: dos llamadas y una pausa', $e instanceof DgiiTransportException && count($a->llamadas) === 2 && $a->pausas === [1000], [$a->llamadas, $a->pausas]);
[$a, , $e] = $escenario(array_fill(0, 8, $reset()), [], ['DGII_AUTH_INTENTOS' => '99']);
wa_chk('=99: el tope es 5 intentos, con pausas de 1, 2, 2 y 2 s', $e instanceof DgiiTransportException && count($a->llamadas) === 5 && $a->pausas === [1000, 2000, 2000, 2000], [$a->llamadas, $a->pausas]);
[$a, , $e] = $escenario([$reset(), $reset(), $reset()], [], ['DGII_AUTH_INTENTOS' => '']);
wa_chk("='' (vacio): los 3 de siempre", $e instanceof DgiiTransportException && count($a->llamadas) === 3, $a->llamadas);

// ---------------------------------------------------------------------------
echo "10) Con un 'timeout' pedido (el POS): ese numero es el presupuesto de todo el login\n";
[$a, $token, $e] = $escenario([$reset(), $semillaOk('F10'), $tokenOk('TOKEN-SECRETO-10')], ['timeout' => 3]);
wa_chk('un reset rapido SI cabe: 3 s la primera llamada, 1 s las del reintento', $e === null && ($token['token'] ?? null) === 'TOKEN-SECRETO-10'
    && $a->llamadas === [['semilla', 3], ['semilla', 1], ['validarsemilla', 1]], [$a->llamadas, $e?->getMessage()]);
wa_chk('el login entero no paso de 3 s', $a->transcurrido() <= 3.0, $a->transcurrido());

[$a, , $e] = $escenario([$cuelga(), $semillaOk('F10b'), $tokenOk()], ['timeout' => 3]);
wa_chk('una DGII que no contesta gasta los 3 s: sin reintento, falla como antes', $e instanceof DgiiTransportException && count($a->llamadas) === 1 && $a->pausas === [] && $a->transcurrido() === 3.0, [$a->llamadas, $a->pausas, $a->transcurrido()]);

[$a, , $e] = $escenario([$reset(), $semillaOk('F10c'), $tokenOk()], ['timeout' => 1]);
wa_chk('con timeout de 1 s no queda presupuesto para la pausa: sin reintento', $e instanceof DgiiTransportException && count($a->llamadas) === 1 && $a->pausas === [], [$a->llamadas, $a->pausas]);

// ---------------------------------------------------------------------------
echo "11) Sin 'timeout': 10 s por llamada y el login entero no pasa de 30 s\n";
[$a, , $e] = $escenario([$cuelga(), $cuelga(), $cuelga(), $cuelga()]);
wa_chk('3 intentos que se cuelgan: 10 s, 10 s y los 7 s que quedan', $e instanceof DgiiTransportException && array_column($a->llamadas, 1) === [10, 10, 7], $a->llamadas);
wa_chk('en total 30 s (pausas incluidas), ni uno mas', $a->transcurrido() === 30.0 && $a->pausas === [1000, 2000], [$a->transcurrido(), $a->pausas]);
wa_chk('el fallo final dice 3 intentos', ($accion($a, 'DGII_AUTH_OUT_FAILED')['new_values']['intentos'] ?? null) === 3, $a->eventos);

[$a, , ] = $escenario([$semillaOk('F11'), $tokenOk()], [], ['DGII_AUTH_TIMEOUT' => '4']);
wa_chk('DGII_AUTH_TIMEOUT=4: llamadas de 4 s', array_column($a->llamadas, 1) === [4, 4], $a->llamadas);
[$a, , ] = $escenario([$semillaOk('F11b'), $tokenOk()], [], ['DGII_ECF_TIMEOUT' => '3']);
wa_chk('DGII_ECF_TIMEOUT=3 corta el login a 3 s aunque el propio sea de 10', array_column($a->llamadas, 1) === [3, 3], $a->llamadas);
[$a, , ] = $escenario([$semillaOk('F11c'), $tokenOk()], [], ['DGII_ECF_TIMEOUT' => '60']);
wa_chk('DGII_ECF_TIMEOUT=60 no alarga el login: sigue en 10 s', array_column($a->llamadas, 1) === [10, 10], $a->llamadas);

// ---------------------------------------------------------------------------
echo "12) El envio y las consultas NO se repiten: un solo intento\n";
$a = new AuthGuion();
$a->guion = [$reset()];
$e = null;
try {
    $conEnv($ENV_BASE, static fn () => $a->consultarEndpointAutenticado('POST', 'recepcion/api/FacturasElectronicas', 'tok', '<ECF/>', ['environment' => 'ecf', 'base_url' => 'https://dgii.invalid']));
} catch (Throwable $e) {
}
wa_chk('un reset al enviar sale tal cual: una llamada, ninguna pausa, ninguna bitacora',
    $e instanceof DgiiTransportException && count($a->llamadas) === 1 && $a->pausas === [] && $a->eventos === [], [$a->llamadas, $a->pausas, $e?->getMessage()]);

// ---------------------------------------------------------------------------
echo "13) La excepcion de red es compatible con todo lo anterior\n";
$x = new DgiiTransportException('HTTP request failed: x');
wa_chk('es una RuntimeException con codigo 0 y el mismo mensaje', $x instanceof RuntimeException && $x->getCode() === 0 && $x->getMessage() === 'HTTP request failed: x');

// La puerta real (curl o streams): una conexion rechazada sale como DgiiTransportException.
$real = new DgiiAuthService();
$e = null;
set_error_handler(static fn (): bool => true);
try {
    $real->obtenerSemilla(['environment' => 'ecf', 'base_url' => 'http://127.0.0.1:1', 'timeout' => 2]);
} catch (Throwable $e) {
} finally {
    restore_error_handler();
}
wa_chk('una conexion rechazada (127.0.0.1:1) sale como DgiiTransportException "HTTP request failed"',
    $e instanceof DgiiTransportException && str_starts_with($e->getMessage(), 'HTTP request failed'), $e === null ? 'no lanzo' : [get_class($e), $e->getMessage()]);

wa_fin();
