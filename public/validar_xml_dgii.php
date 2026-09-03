<?php
/**
 * validar_xml_dgii.php — Wrapper web del validador de e-CF contra los XSD DGII.
 *
 * El server es hosting compartido sin shell, asi que el validador se corre desde
 * el navegador. Sirve directo bajo /api/public/.
 *
 *   GET /api/public/validar_xml_dgii.php
 *     token       = CERT_RUN_TOKEN (del .env)
 *     [tenant_id] = valida los XML del respaldo master.ecf_integracion_backup
 *     [dir]       = carpeta con XML (default tools/xml_integracion)
 *     [limit]     = maximo de documentos (default 100)
 *
 * Solo lectura: no emite ni modifica nada.
 */

header('Content-Type: text/plain; charset=utf-8');
@set_time_limit(0);
@ob_implicit_flush(true);
while (ob_get_level() > 0) { @ob_end_flush(); }

require_once __DIR__ . '/../src/Database.php';
Database::loadEnv();

$expected = (string) (getenv('CERT_RUN_TOKEN') ?: ($_ENV['CERT_RUN_TOKEN'] ?? ''));
if ($expected === '') {
    http_response_code(403);
    exit("CERT_RUN_TOKEN no configurado en el .env del server.\n");
}
if (!hash_equals($expected, (string) ($_REQUEST['token'] ?? ''))) {
    http_response_code(403);
    exit("Token invalido. Use ?token=...\n");
}

require_once __DIR__ . '/../tools/validar_xml_dgii.php';

echo "Validacion XSD DGII — " . date('Y-m-d H:i:s') . "\n\n";

validarXmlsDgii([
    'tenant_id' => $_REQUEST['tenant_id'] ?? null,
    'dir' => $_REQUEST['dir'] ?? null,
    'limit' => $_REQUEST['limit'] ?? 100,
]);
