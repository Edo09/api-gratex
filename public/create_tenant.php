<?php
/**
 * Entrypoint web para onboarding de tenants.
 *
 * Servido directo porque esta bajo /api/public/ (el .htaccess permite archivos
 * existentes ahi). La logica + el token estan en tools/create_tenant.php.
 *
 * Integracion (caso comun, sin DB):
 *   https://gratex.net/api/public/create_tenant.php?token=TU_TOKEN
 *     &tipo=integracion&nombre=Cliente+A+SRL&rnc=131111111
 *     &cert-path=certificado_dgii/131111111/cert.p12&cert-pass=CLAVE
 *     [&webhook-url=https://cliente.com/webhook]
 *
 * App (con DB; crea la DB en cPanel primero y usa skip-create-db):
 *   ...?token=...&tipo=app&nombre=Cliente+B&rnc=132222222
 *     &db-name=tenant_b&db-user=tenant_b_user&db-pass=CLAVE&skip-create-db=1
 *
 * BORRA este archivo del server cuando termines de onboardear.
 */

require __DIR__ . '/../tools/create_tenant.php';
