<?php
/**
 * test_integracion_alcance.php — Una credencial de integracion (X-API-KEY +
 * X-API-SECRET) solo vale en /api/integracion/*. Sin base de datos ni red.
 *
 * Origen (2026-10-06, revision antes de entregar la guia a un cliente externo):
 * la credencial era valida en CUALQUIER ruta. En las rutas de la app
 * PermissionGate la deniega, pero con PERMISSIONS_ENFORCE=false (modo sombra)
 * solo lo anotaba; como un tenant de integracion no tiene DB propia, el
 * controller (p.ej. /api/clients) caia en la DB por defecto del .env, la de
 * Gratex. Ahora AuthMiddleware la rechaza fuera de /api/integracion/* ANTES de
 * resolver el tenant, y PermissionGate bloquea siempre a un principal sin
 * usuario en una ruta de app.
 *
 * Uso:
 *   php tools/test_integracion_alcance.php      (sale con 1 si algo falla)
 */

require_once __DIR__ . '/../src/Middleware/AuthMiddleware.php';

// El constructor de AuthMiddleware crea authModel, que abre la DB; la logica que se
// prueba (rechazo por ruta, falta de credenciales) no la usa: se instancia sin constructor.
$auth = static fn(): AuthMiddleware => (new ReflectionClass(AuthMiddleware::class))->newInstanceWithoutConstructor();

$fallos = 0;
$total = 0;
$chk = function (string $desc, bool $ok, $obtenido = null) use (&$fallos, &$total) {
    $total++;
    if (!$ok) {
        $fallos++;
    }
    printf("  [%s] %s\n", $ok ? 'OK  ' : 'FALLO', $desc);
    if (!$ok && $obtenido !== null) {
        echo '         obtenido: ' . json_encode($obtenido, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }
};

echo "AuthMiddleware::rutaApi (igual que src/Router.php)\n";
$casos = [
    '/api/integracion/ecf' => 'integracion',
    '/api/integracion/xml?e_ncf=E320000000012&rnc=1' => 'integracion',
    '/api/integracion' => 'integracion',
    '/api/clients' => 'clients',
    '/api/users/5' => 'users',
    '/api/facturas/10/pdf' => 'facturas',
    '/api/ecf/autenticacion/fe/autenticacion/api/semilla' => 'ecf',
    '/api//api/integracion/ecf' => 'api',
    '/api/integracionX/ecf' => 'integracionx',
    '' => '',
];
foreach ($casos as $uri => $esperado) {
    $_SERVER['REQUEST_URI'] = $uri;
    $chk(sprintf('%-52s -> %s', $uri === '' ? '(sin URI)' : $uri, $esperado === '' ? "''" : $esperado), AuthMiddleware::rutaApi() === $esperado, AuthMiddleware::rutaApi());
}

echo "\nCredencial de integracion fuera de /api/integracion/*: rechazada sin tocar la base\n";
putenv('MULTI_TENANT_ENABLED=true');
$_ENV['MULTI_TENANT_ENABLED'] = 'true';
$_SERVER['HTTP_X_API_KEY'] = 'clave-de-integracion';
$_SERVER['HTTP_X_API_SECRET'] = 'secreto-de-integracion';
foreach (['/api/clients', '/api/users', '/api/facturas', '/api/products', '/api/emisor', '/api/roles'] as $uri) {
    $_SERVER['REQUEST_URI'] = $uri;
    // Si intentara resolver el tenant iria a MasterDatabase (no hay base aqui) y
    // lanzaria: que no lance tambien prueba que rechazo antes de buscarlo.
    try {
        $r = $auth()->validateRequest();
        $chk("$uri: credencial rechazada", $r['valid'] === false && str_contains($r['message'], '/api/integracion/'), $r);
    } catch (Throwable $e) {
        $chk("$uri: credencial rechazada", false, 'intento resolver el tenant: ' . $e->getMessage());
    }
}

echo "\nSin X-API-SECRET el camino de sesion de la app no cambia\n";
unset($_SERVER['HTTP_X_API_SECRET'], $_SERVER['HTTP_X_API_KEY']);
$_SERVER['REQUEST_URI'] = '/api/clients';
$r = $auth()->validateRequest();
$chk('sin credenciales: pide credenciales (como antes)', $r['valid'] === false && str_contains($r['message'], 'Credenciales requeridas'), $r);

echo "\nPermissionGate: un principal sin usuario se bloquea tambien en modo sombra\n";
$src = (string) file_get_contents(__DIR__ . '/../src/PermissionGate.php');
$chk('deny() acepta $siempre y lo respeta antes del modo sombra',
    str_contains($src, 'bool $siempre = false') && str_contains($src, 'if (!$siempre && !self::enforcing())'));
$chk("la rama 'principal no-usuario en ruta de app' llama deny(..., true)",
    (bool) preg_match("/principal no-usuario en ruta de app', 403,\s*'[^']*', true\)/", $src));

echo "\nMensajes de error hacia afuera: sin rutas del server ni nombres de configuracion\n";
require_once __DIR__ . '/../src/Utils/FacturacionElectronica/EcfUsuarioException.php';
$pub = static fn(string $m): string => EcfUsuarioException::mensajePublico(new RuntimeException($m));
$r = $pub('No se puede leer el certificado: /home1/smhynzte/public_html/api/certificado_dgii/131599729/cert.p12');
$chk('ruta Unix del .p12 tapada', !str_contains($r, 'home1') && !str_contains($r, 'smhynzte') && str_contains($r, '[ruta del servidor]'), $r);
$r = $pub('DGII_ECF_CAINFO is not readable: C:\\certs\\cacert.pem');
$chk('variable de configuracion y ruta Windows tapadas', !str_contains($r, 'DGII_ECF_CAINFO') && !str_contains($r, 'cacert') && str_contains($r, '[configuración]'), $r);
$r = $pub('Missing certificate password. Configure DGII_ECF_CERT_PASSWORD or send certificate_password.');
$chk('nombre de la variable del password tapado', !str_contains($r, 'DGII_ECF_CERT_PASSWORD'), $r);
$r = $pub('DGII authenticated request failed: HTTP 400 https://ecf.dgii.gov.do/CerteCF/ConsultaResultado/api/Consultas/Estado');
$chk('URL publica de la DGII intacta (le sirve al cliente)', str_contains($r, 'https://ecf.dgii.gov.do/CerteCF/ConsultaResultado/api/Consultas/Estado'), $r);
$r = $pub('InformacionReferencia.ncf_modificado es requerido');
$chk('error de validacion intacto', $r === 'InformacionReferencia.ncf_modificado es requerido', $r);
$r = $pub('estado RFCE_RECHAZADO en E31/E32, codigo RHq/pJ, ACEPTADO_CONDICIONAL');
$chk('estados, tipos y codigos de seguridad intactos', $r === 'estado RFCE_RECHAZADO en E31/E32, codigo RHq/pJ, ACEPTADO_CONDICIONAL', $r);
$r = EcfUsuarioException::mensajePublico(new EcfUsuarioException('No se puede leer el certificado: /home1/x/certificado_dgii/1/cert.p12', 'No hay certificado.'));
$chk('EcfUsuarioException: el mensaje tecnico tambien pasa por el filtro', !str_contains($r, '/home1/') && str_contains($r, 'No se puede leer el certificado'), $r);
$chk('mensaje vacio: texto generico', $pub('') === 'Error interno.');

$src = (string) file_get_contents(__DIR__ . '/../src/Controllers/ecfRecepcionController.php')
    . (string) file_get_contents(__DIR__ . '/../src/Controllers/ecfAutenticacionController.php');
$chk('URLs publicas de la DGII: el 500 ya no devuelve getMessage()', !str_contains($src, "'Error interno: ' . \$e->getMessage()"));

printf("\n%d de %d pruebas OK\n", $total - $fallos, $total);
exit($fallos > 0 ? 1 : 0);
