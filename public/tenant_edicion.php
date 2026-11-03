<?php
/**
 * tenant_edicion.php (web) — la version web de tools/tenant_edicion.php: cambiar la edicion de una empresa (completa, Express o ambas: la app completa y
 * Express) y refrescar el vencimiento de su certificado, mas el informe de verificar_express.php.
 *
 * Servido directo (bajo /api/public/, como audit_logs.php y create_tenant.php). La interfaz es tenant_edicion.html:
 *
 *   https://gratex.net/api/public/tenant_edicion.html
 *
 * Es una API JSON de operaciones: POST, el token en el CUERPO (campo "token"), y el campo "action":
 *   meta      -> las empresas (campo por campo, sin secretos), los modulos y tipos que se pueden pedir y si estan puestas las migraciones 015 y 017
 *   ensayo    -> lo que haria cambiar la empresa (tenant) a un modo (express | ambas | completa | refrescar-cert, con tipos y modulos para los dos primeros),
 *                SIN escribir nada. Si hay algo que aplicar y no se rechaza nada, devuelve la "confirmacion" que aplicar exige
 *   aplicar   -> lo mismo con los mismos datos y la confirmacion del ensayo: solo aplica lo que ese ensayo mostro (409 si algo cambio o ya vencio)
 *   verificar -> el informe de solo lectura de las empresas que no son completa (lo que imprime tools/verificar_express.php)
 *
 * El token sale de TENANT_EDICION_TOKEN en el .env del server (32 o mas caracteres al azar; nunca en el repo). Sin el, la herramienta esta apagada. Solo HTTPS,
 * freno al tanteo y una lista de IPs opcional (TENANT_EDICION_IPS): todo en src/Admin/TenantEdicionWeb.php, que tambien lista las garantias. Esta herramienta
 * cambia lo que una empresa puede hacer: ademas del token, conviene ponerle delante el Basic Auth de cPanel (Directory Privacy sobre este archivo y el .html).
 * Guia: docs/integrations/tenant-edicion-web.md.
 */

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/MasterDatabase.php';
require_once __DIR__ . '/../src/Admin/TenantEdicionWeb.php';

// .env al inicio: el token y la configuracion de la master viven en el entorno.
Database::loadEnv();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
// Sin Access-Control-Allow-*: ningun otro origen puede leer estas respuestas.

$entorno = static fn (string $nombre): string => (string) (getenv($nombre) ?: ($_ENV[$nombre] ?? ''));

[$http, $cuerpo] = TenantEdicionWeb::manejar(
    $_SERVER,
    $_POST,
    [
        'TENANT_EDICION_TOKEN' => $entorno('TENANT_EDICION_TOKEN'),
        'TENANT_EDICION_IPS' => $entorno('TENANT_EDICION_IPS'),
        'TENANT_EDICION_PERMITIR_HTTP' => $entorno('TENANT_EDICION_PERMITIR_HTTP'),
    ],
    static fn (): PDO => express_conectar_master('tenant_edicion_web_'),
    time(),
    sys_get_temp_dir()
);

if (isset($cuerpo['retry_after'])) {
    header('Retry-After: ' . (int) $cuerpo['retry_after']);
}
http_response_code($http);
// Los nombres y los valores libres pueden traer UTF-8 invalido: sin SUBSTITUTE json_encode devolveria false y la respuesta saldria vacia.
echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
