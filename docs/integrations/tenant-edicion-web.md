# Cambiar la edición de una empresa desde el navegador

La versión web de `tools/tenant_edicion.php`: pasa una empresa a **Express** (solo Express), a **ambas** (la app
completa y Express con el mismo usuario) o de vuelta a **completa**, refresca el vencimiento de su certificado y
muestra el informe de `tools/verificar_express.php`.

- Página: `https://gratex.net/api/public/tenant_edicion.html` (habla con `public/tenant_edicion.php`).
- Es **la misma biblioteca** que la línea de comandos (`tools/express_edicion_lib.php`): el mismo preflight, el mismo
  plan y la misma fila de auditoría. Lo que el CLI rechaza, la página también. El CLI sigue siendo solo CLI.
- Cambia lo que una empresa puede hacer (y, en `express`, apaga su POS y deja en blanco su credencial de integración):
  por eso nace **apagada** y va cerrada con llave.

## Activarla (una sola vez)

1. Genera un token largo y al azar (32 caracteres o más; este da 48):
   `php -r "echo bin2hex(random_bytes(24)).PHP_EOL;"`
2. En el `.env` del server (en su propia línea; el parser no admite comentarios al final de la línea) agrega
   `TENANT_EDICION_TOKEN=<el token>`. Opcional: `TENANT_EDICION_IPS=1.2.3.4,5.6.7.8` limita la herramienta a esas IPs.
3. Sube tres archivos (la biblioteca `tools/express_edicion_lib.php`, `tools/verificar_express.php` y
   `src/TenantEntitlements.php` ya suben con el API): `public/tenant_edicion.php`, `public/tenant_edicion.html` y
   `src/Admin/TenantEdicionWeb.php`.
4. Recomendado: pon delante el Basic Auth de Apache, además del token. En el `.htaccess` de la raíz del API
   (con un usuario creado en cPanel > Directory Privacy, o con `htpasswd`):
   ```
   <FilesMatch "^tenant_edicion\.(php|html)$">
     AuthType Basic
     AuthName "Edicion de empresas"
     AuthUserFile /home/CUENTA/.htpasswds/tenant_edicion
     Require valid-user
   </FilesMatch>
   ```
5. Prueba que sin token contesta 403: `curl -s -X POST https://gratex.net/api/public/tenant_edicion.php`.

La migración master 015 tiene que estar aplicada (y la 017 para usar «Ambas»): la página lo avisa arriba de la tabla.

## Usarla

1. Abre la página, escribe el token y **Conectar**. El token solo vive en la memoria de la pestaña: al recargar hay que
   escribirlo otra vez.
2. En la tabla, **Cambiar** en la empresa. Elige qué hacer, los tipos de venta (31, 32, 45 y, opcional, el 33) y los
   módulos (todos marcados = todo el preset).
3. **Ensayo**: no escribe nada y muestra el informe de siempre: `[OK  ]` no pide nada, `[CORR]` lo arregla el plan,
   `[RECH]` lo frena (con un `[RECH]` no se puede aplicar).
4. Si no hay rechazos y hay algo que aplicar aparece **Aplicar**: marca la casilla y aplícalo. Cualquier cambio en el
   formulario invalida el ensayo y hay que ensayar otra vez.
5. **Verificar todas**: el informe de solo lectura de las empresas que no son completa (`verificar_express.php`).

| Modo | Qué hace |
| --- | --- |
| Ambas | La app completa y Express. Solo cambia edición, tipos, módulos y vencimiento del certificado; no toca POS, existencias, integración ni roles. Pide la migración 017. |
| Solo Express | La app completa deja de servirle a la empresa. Apaga POS y existencias, deja en blanco la credencial de integración y resetea el rol `user`. |
| Volver a completa | Quita Express. No cambia la app completa y no es una parada segura (para frenar facturas: tipos vacíos o revocar tokens). |
| Refrescar certificado | Vuelve a leer el certificado y actualiza la fecha de vencimiento. |

## Qué la protege

- **Apagada por omisión**: sin `TENANT_EDICION_TOKEN` (o con `CAMBIAR`, o con menos de 32 caracteres) responde 403.
- **Solo POST, solo HTTPS**: el token va en el cuerpo (nunca en la URL, que queda en los logs) y la herramienta se niega a
  usarse por HTTP (salvo desde el propio servidor, para probar, o con `TENANT_EDICION_PERMITIR_HTTP=1`, no recomendado).
- **Freno al tanteo**: un token malo espera medio segundo; 5 fallos de una IP, o 30 de cualquiera, en 15 minutos dan 429
  (con `Retry-After`) incluso al token correcto. Si te bloqueas, espera 15 minutos.
- **Sin base de datos sin autenticar**: la conexión a la master se pide solo después del token. Sin cookies ni sesión y sin
  cabeceras CORS: otro sitio no puede disparar nada ni leer las respuestas.
- **Aplicar exige la confirmación del ensayo**: una firma (HMAC con el token como clave) de la empresa, el modo, los tipos,
  los módulos y los pasos del plan, válida 10 minutos. Si algo cambió entre el ensayo y aplicar, o ya pasó el tiempo, responde
  409 y hay que ensayar de nuevo: se aplica exactamente lo que se vio. Repetir una petición ya aplicada también da 409.
- **Auditoría**: cada cambio deja la fila atómica de la biblioteca (`audit_logs`, módulo `edicion`) y otra de la web (módulo
  `edicion_web`: IP, navegador y parámetros, sin el token). Los fallos de login quedan en el `error_log`.
- **La lista de empresas** se arma campo por campo: nunca lleva certificados, claves ni el hash de integración.

## Apagarla

Quita `TENANT_EDICION_TOKEN` del `.env` (o ponle `CAMBIAR`): responde 403 «no está configurado». Para quitarla del todo,
borra `public/tenant_edicion.php`, `public/tenant_edicion.html` y `src/Admin/TenantEdicionWeb.php`.

## Pruebas

`php tools/test_tenant_edicion_web.php` (sin base: puertas, freno, confirmación, flujo y lectura del código; corre en CI) y
`php tools/test_tenant_edicion_web_scratch.php` (un servidor de verdad contra las bases scratch).
