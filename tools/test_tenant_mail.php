<?php
/**
 * test_tenant_mail.php — Identidad de los correos por tenant (TenantMail).
 *
 * Los correos de cotizacion/factura/bienvenida salian "de Gratex" y con copia a
 * su personal para TODOS los tenants. Este script prueba, sin DB y sin llamar a
 * mail(), lo que TenantMail arma para cada caso:
 *
 *   - Gratex (sin tenant resuelto o por su RNC): From, -f y copias identicos a
 *     los de antes.
 *   - Otro tenant: From = su emisor_config.correo con su nombre, sin copias a
 *     Gratex; sin correo valido no hay remitente (no se envia).
 *   - El nombre no puede inyectar cabeceras (CR/LF) y va codificado si no es
 *     ASCII simple.
 *   - Los PDF van a una subcarpeta por tenant.
 *
 * Uso:
 *   php tools/test_tenant_mail.php
 */

require_once __DIR__ . '/../src/Utils/TenantMail.php';

$fallos = 0;
$total = 0;
$chk = function (string $desc, bool $ok) use (&$fallos, &$total) {
    $total++;
    if (!$ok) {
        $fallos++;
    }
    printf("  [%s] %s\n", $ok ? 'OK  ' : 'FALLO', $desc);
};

// Imprime lo que se le pasaria a mail() para que se pueda revisar a ojo.
$mostrar = function (?array $tenant, ?array $emisor, string $clientEmail) {
    $r = TenantMail::remitente($tenant, $emisor);
    $to = TenantMail::destinatarios($tenant, $clientEmail, TenantMail::COPIAS_GRATEX_DOCUMENTOS);
    echo "     To:   {$to}\n";
    if ($r === null) {
        echo "     (sin remitente: no se envia)\n";
        return;
    }
    echo '     ' . str_replace("\r\n", '\r\n', TenantMail::cabeceraFrom($r)) . "\n";
    echo '     mail() 5o param: ' . TenantMail::parametroEnvelope($r) . "\n";
};

$gratex = ['id' => 1, 'nombre' => 'Gratex EIRL', 'rnc' => TenantMail::GRATEX_RNC, 'tipo' => 'app', 'activo' => 1];
$ferre = ['id' => 5, 'nombre' => 'Ferreteria', 'rnc' => '132615123', 'tipo' => 'app', 'activo' => 1];
$emisorFerre = [
    'razon_social' => 'FERREHERRAMIENTAS VENTURA, S.R.L.',
    'nombre_comercial' => 'Ferretería Ventura',
    'correo' => 'ferreventura@hotmail.com',
];
$copiasGratex = 'cliente@ejemplo.com, edwin@gratex.net, omareogm09@gmail.com, info@gratex.net';

echo "== Gratex: sin tenant resuelto (single-tenant / local) ==\n";
$chk('esGratex(null)', TenantMail::esGratex(null));
$r = TenantMail::remitente(null, null);
$chk('remitente = Gratex <info@gratex.net>', $r === ['nombre' => 'Gratex', 'correo' => 'info@gratex.net']);
$chk('From identico al de antes', TenantMail::cabeceraFrom($r) === "From: Gratex <info@gratex.net>\r\n");
$chk('-f identico al de antes', TenantMail::parametroEnvelope($r) === '-finfo@gratex.net');
$chk('copias de Gratex incluidas', TenantMail::destinatarios(null, 'cliente@ejemplo.com', TenantMail::COPIAS_GRATEX_DOCUMENTOS) === $copiasGratex);
$mostrar(null, null, 'cliente@ejemplo.com');

echo "\n== Gratex: tenant resuelto por su RNC (produccion) ==\n";
$chk('esGratex(tenant RNC Gratex)', TenantMail::esGratex($gratex));
// Aunque su emisor_config traiga otro correo, Gratex no cambia de identidad.
$r = TenantMail::remitente($gratex, ['razon_social' => 'GRATEX EIRL', 'correo' => 'otro@gratex.net']);
$chk('remitente sigue siendo Gratex <info@gratex.net>', $r === ['nombre' => 'Gratex', 'correo' => 'info@gratex.net']);
$chk('copias de Gratex incluidas', TenantMail::destinatarios($gratex, 'cliente@ejemplo.com', TenantMail::COPIAS_GRATEX_DOCUMENTOS) === $copiasGratex);
$chk('cliente sin correo: igual van las copias', TenantMail::destinatarios($gratex, '', TenantMail::COPIAS_GRATEX_DOCUMENTOS) === 'edwin@gratex.net, omareogm09@gmail.com, info@gratex.net');
$mostrar($gratex, null, 'cliente@ejemplo.com');

echo "\n== Otro tenant con correo en emisor_config ==\n";
$chk('esGratex(Ferreteria) = false', !TenantMail::esGratex($ferre));
$r = TenantMail::remitente($ferre, $emisorFerre);
$chk('correo = emisor_config.correo', ($r['correo'] ?? null) === 'ferreventura@hotmail.com');
$chk('nombre = nombre_comercial', ($r['nombre'] ?? null) === 'Ferretería Ventura');
$from = $r ? TenantMail::cabeceraFrom($r) : '';
$chk('From codificado RFC 2047 (no ASCII)', str_starts_with($from, 'From: =?UTF-8?B?') && str_ends_with($from, "?= <ferreventura@hotmail.com>\r\n"));
$chk('From decodifica al nombre', function_exists('iconv_mime_decode')
    ? iconv_mime_decode(substr($from, 6, strpos($from, ' <') - 6), 0, 'UTF-8') === 'Ferretería Ventura'
    : true);
$chk('-f = correo del tenant', $r !== null && TenantMail::parametroEnvelope($r) === '-fferreventura@hotmail.com');
$to = TenantMail::destinatarios($ferre, 'cliente@ejemplo.com', TenantMail::COPIAS_GRATEX_DOCUMENTOS);
$chk('solo el cliente, sin copias de Gratex', $to === 'cliente@ejemplo.com');
$chk('ninguna direccion de Gratex en To', stripos($to, 'gratex') === false && stripos($to, 'omareogm') === false);
$chk('cliente sin correo: sin destinatarios', TenantMail::destinatarios($ferre, '', TenantMail::COPIAS_GRATEX_DOCUMENTOS) === '');
$chk('cliente con correo invalido: sin destinatarios', TenantMail::destinatarios($ferre, "x@y.com\r\nBcc: z@w.com", TenantMail::COPIAS_GRATEX_DOCUMENTOS) === '');
$mostrar($ferre, $emisorFerre, 'cliente@ejemplo.com');

echo "\n== Otro tenant sin nombre_comercial ==\n";
$r = TenantMail::remitente($ferre, ['razon_social' => 'FERREHERRAMIENTAS VENTURA, S.R.L.', 'correo' => 'ventas@ferreventura.com.do']);
$chk('nombre = razon_social', ($r['nombre'] ?? null) === 'FERREHERRAMIENTAS VENTURA, S.R.L.');
// La coma y los puntos son "specials": sin codificar, el cliente de correo
// partiria el nombre en dos direcciones.
$from = $r ? TenantMail::cabeceraFrom($r) : '';
$chk('razon_social con coma va codificada', str_starts_with($from, 'From: =?UTF-8?B?'));
$mostrar($ferre, ['razon_social' => 'FERREHERRAMIENTAS VENTURA, S.R.L.', 'correo' => 'ventas@ferreventura.com.do'], 'cliente@ejemplo.com');

echo "\n== Otro tenant sin correo valido ==\n";
$chk('emisor_config null -> sin remitente', TenantMail::remitente($ferre, null) === null);
$chk('correo vacio -> sin remitente', TenantMail::remitente($ferre, ['razon_social' => 'X', 'correo' => '']) === null);
$chk('correo invalido -> sin remitente', TenantMail::remitente($ferre, ['razon_social' => 'X', 'correo' => 'no-es-correo']) === null);
$chk('correo con opcion de sendmail -> sin remitente', TenantMail::remitente($ferre, ['razon_social' => 'X', 'correo' => "a'-X/tmp/x@y.com"]) === null);
$mostrar($ferre, ['razon_social' => 'X', 'correo' => ''], 'cliente@ejemplo.com');

echo "\n== Inyeccion de cabeceras por el nombre ==\n";
$r = TenantMail::remitente($ferre, ['razon_social' => "Ferre\r\nBcc: espia@malo.com", 'correo' => 'a@b.com']);
$from = $r ? TenantMail::cabeceraFrom($r) : '';
$chk('una sola linea (un solo CRLF, al final)', substr_count($from, "\r\n") === 1 && str_ends_with($from, "\r\n"));
$chk('no aparece "Bcc:" en claro', stripos($from, 'Bcc:') === false);
$r = TenantMail::remitente($ferre, ['razon_social' => " \r\n ", 'nombre_comercial' => '', 'correo' => 'a@b.com']);
$chk('nombre vacio tras limpiar -> nombre del tenant en master', ($r['nombre'] ?? null) === 'Ferreteria');

echo "\n== Nombre largo (varias palabras codificadas) ==\n";
$largo = str_repeat('Ñandú Ferretería y Materiales ', 5);
$r = TenantMail::remitente($ferre, ['razon_social' => $largo, 'correo' => 'a@b.com']);
$from = $r ? TenantMail::cabeceraFrom($r) : '';
$palabras = [];
preg_match_all('/=\?UTF-8\?B\?[A-Za-z0-9+\/=]+\?=/', $from, $palabras);
$chk('cada palabra codificada <= 75 caracteres', $palabras[0] !== [] && max(array_map('strlen', $palabras[0])) <= 75);
$chk('linea < 998 caracteres', strlen($from) < 998);
$chk('decodifica al nombre completo', function_exists('iconv_mime_decode')
    ? iconv_mime_decode(substr($from, 6, strpos($from, ' <') - 6), 0, 'UTF-8') === trim($largo)
    : true);

echo "\n== Carpeta de PDF por tenant ==\n";
$raiz = dirname(__DIR__);
$norm = fn(string $p) => str_replace('\\', '/', $p);
$chk('sin tenant: carpeta de siempre', $norm(TenantMail::rutaCarpetaPdf(null, 'cotizaciones')) === $norm($raiz . '/cotizaciones/'));
$chk('Gratex (id 1): cotizaciones/1/', $norm(TenantMail::rutaCarpetaPdf($gratex, 'cotizaciones')) === $norm($raiz . '/cotizaciones/1/'));
$chk('Ferreteria (id 5): facturas/5/', $norm(TenantMail::rutaCarpetaPdf($ferre, 'facturas')) === $norm($raiz . '/facturas/5/'));
$chk('dos tenants, mismo codigo: archivos distintos',
    TenantMail::rutaCarpetaPdf($gratex, 'cotizaciones') . 'Cotizacion_COT-0001.pdf'
    !== TenantMail::rutaCarpetaPdf($ferre, 'cotizaciones') . 'Cotizacion_COT-0001.pdf');

printf("\n%d/%d OK\n", $total - $fallos, $total);
exit($fallos === 0 ? 0 : 1);
