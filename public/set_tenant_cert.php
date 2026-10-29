<?php
/**
 * Entrypoint web para cargar o reservar el certificado .p12 de un tenant que ya existe.
 *
 * Servido directo porque esta bajo /api/public/ (el .htaccess permite archivos
 * existentes ahi). La logica + el token (ONBOARD_TOKEN) estan en tools/set_tenant_cert.php.
 * Solo POST: el token y la clave del .p12 no pueden ir en la URL. La pantalla es
 * set_tenant_cert.html (misma carpeta); ejemplos con curl.exe en la cabecera de ese archivo.
 *
 * BORRA este archivo y set_tenant_cert.html del server cuando termines.
 */

require __DIR__ . '/../tools/set_tenant_cert.php';
