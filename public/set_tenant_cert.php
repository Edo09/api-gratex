<?php
/**
 * Entrypoint web para cargar o reservar el certificado .p12 de un tenant que ya existe.
 *
 * Servido directo porque esta bajo /api/public/ (el .htaccess permite archivos
 * existentes ahi). La logica + el token (ONBOARD_TOKEN) estan en tools/set_tenant_cert.php.
 * Solo POST: el token y la clave del .p12 no pueden ir en la URL. Ejemplos (curl.exe) en
 * la cabecera de ese archivo y en docs/integrations/alta-tenant-runbook.md.
 *
 * BORRA este archivo del server cuando termines.
 */

require __DIR__ . '/../tools/set_tenant_cert.php';
