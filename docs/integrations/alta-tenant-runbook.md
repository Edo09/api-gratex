# Runbook — Alta de un tenant nuevo (tipo app)

Checklist operativo, en el orden en que hay que ejecutarlo. Para el "por qué" de
cada pieza (arquitectura, tipos de tenant, integración, tokens) ver
[multi-tenant-onboarding.md](multi-tenant-onboarding.md); esto es el paso a paso.

> **El orden importa.** El certificado se prepara ANTES del onboarding, y los datos
> del emisor se completan DESPUÉS. Saltarse el orden es lo que produce los tres
> errores más comunes (ver [Troubleshooting](#troubleshooting)).
>
> **¿Todavía no hay `.p12`?** Se puede dar de alta igual y cargarlo después, pero **no
> dejes el tenant sin proteger**: ver [Alta sin certificado](#alta-sin-certificado-el-p12-llega-después).

---

## Fase 0 — Reunir y verificar

Antes de tocar nada, ten a mano y confirmado:

| Qué | Verificación |
|---|---|
| RNC del cliente (9-11 dígitos) | `SELECT id, nombre FROM tenants WHERE rnc = '<rnc>';` en el master — tiene que salir vacío (`tenants.rnc` es UNIQUE) |
| Certificado `.p12` + su contraseña | La contraseña la vas a necesitar en la Fase 1 y en la 3. Si todavía no lo tienes: [Alta sin certificado](#alta-sin-certificado-el-p12-llega-después) |
| Ambiente de arranque | `certecf` si va a certificar · `testecf` si es demo · `ecf` si ya está certificado |
| Email y username del admin | `SELECT id FROM users WHERE email = '<email>' OR username = '<user>';` — **ambos son UNIQUE globales**, no pueden repetirse con los de otro tenant |
| Carpetas en el server | La de certificados (`CERT_DIR` del `.env`, default `certificado_dgii`) y `logos/`, ambas con escritura para el usuario de PHP |
| Tokens del `.env` | `ONBOARD_TOKEN`, `CREATE_USER_TOKEN`. `UPLOAD_LOGO_TOKEN` sigue siendo const dentro de `public/upload_logo.php` |

Las dos carpetas están en `.gitignore`, así que **un deploy nuevo no las trae** —
créalas a mano en la raíz del API antes de empezar.

---

## Fase 1 — Preparar el certificado

Los `.p12` que entrega la CA vienen cifrados con algoritmos legacy (RC2-40 + 3DES +
MAC SHA-1) que PHP con OpenSSL 3 **no puede leer**. Hay que convertirlos antes, no
después de que falle la primera factura.

**Con `onboard.html` no hace falta hacer nada:** al crear el tenant, la página abre el
`.p12` con la clave (una clave mal escrita se rechaza ahí, sin crear nada) y, si es
legacy, lo re-cifra en el navegador a AES-256 con la misma clave antes de subirlo (con
node-forge, desde jsDelivr). El resultado lo dice arriba de la salida. Si la página no
puede convertirlo (llave no RSA, varias llaves, sin internet) lo dice y pide el script.
`create_tenant.php` además avisa con un `WARNING` si el servidor no pudo abrir el `.p12`.

Lo de abajo es para el alta por CLI o para reemplazar el certificado de un tenant ya creado.

**Detectar si es legacy** (no necesita la contraseña):

```bash
openssl pkcs12 -in cert_cliente.p12 -nokeys -noout
```

Si responde `error:0308010C ... unsupported`, es legacy. Confirmación alterna: buscar
los OIDs `1.2.840.113549.1.12.1.6` (RC2-40) y `...1.12.1.3` (3DES) en el binario.

**Convertir**, conservando la misma contraseña:

```bash
powershell -ExecutionPolicy Bypass -File convertir_cert.ps1
```

El script (`~/Downloads/convertir_cert.ps1`, o su gemelo `.sh` para Git Bash) pide la
clave una vez, re-exporta con AES-256, verifica el resultado con OpenSSL y con
`openssl_pkcs12_read` de PHP, y borra el PEM intermedio. Manualmente son dos pasos:

```bash
openssl pkcs12 -legacy -in viejo.p12 -nodes -out temp.pem
```

```bash
openssl pkcs12 -export -in temp.pem -out nuevo.p12
```

Borra `temp.pem` en cuanto termines: lleva la llave privada sin cifrar. La llave y el
certificado no cambian, solo el cifrado del contenedor, así que la firma que ve DGII
es idéntica.

> En CMD, `bash` resuelve al de WSL, no al de Git. Usa la versión PowerShell o llama
> `"C:\Program Files\Git\bin\bash.exe" convertir_cert.sh`.

---

## Fase 2 — Crear la base de datos

cPanel → MySQL Databases:

1. Crear la DB (ej. `cuenta_cliente2db`).
2. Crear usuario MySQL + contraseña.
3. Darle **todos los privilegios** sobre esa DB.
4. Verificar que el usuario admin del onboarding (`ADMIN_DB_USER` / `MASTER_DB_USER`
   del `.env`) también pueda escribirla — es con el que se aplica el schema.

---

## Fase 3 — Onboarding

`https://<server>/api/public/onboard.html` → tipo **App**.

| Campo | Qué poner |
|---|---|
| token | `ONBOARD_TOKEN` |
| nombre, rnc | Identidad del tenant |
| ambiente | El decidido en Fase 0 |
| razón social, dirección | Los reales del cliente — van a `emisor_config` y al XML |
| db-name, db-user, db-pass | Los de la Fase 2 |
| "La DB ya existe" | **Marcado** (en hosting compartido PHP no puede crear DBs) |
| cert | El `.p12` del cliente tal cual (la página lo convierte si es legacy, ver Fase 1) |
| cert-pass | La contraseña del `.p12` |
| logo | Opcional |
| admin-email, username, nombre, password | Los 4 o ninguno; email y username únicos globales |

**Guarda del resumen que imprime:**

- `tenant_id`
- `client_id certificacion (RNC 131880681)` — lo pide el wizard de certificación
- `API KEY` + `API SECRET` (el secret se muestra una sola vez)

---

## Fase 4 — Completar los datos del emisor

El onboarding solo llena `rnc`, `razon_social`, `nombre_comercial` y `direccion`. El
teléfono y el correo quedan NULL, y la Representación Impresa cae a los valores de
Gratex. En la DB del tenant:

```bash
UPDATE emisor_config SET direccion = '<dirección real>', telefono = '<tel>', correo = '<correo>' WHERE id = 1;
```

Esto no es cosmético: `ECFXmlBuilder` toma `DireccionEmisor` de aquí, así que sale en
el XML que va a DGII.

**Correo:** `emisor_config.correo` es también el remitente (`From:` y `-f`) de las
cotizaciones que el tenant manda por correo. Sin él no se envían (la cotización se
guarda y la API devuelve el aviso en `data.message`). Si es de un dominio público (`@hotmail.com`,
`@gmail.com`…), el server no está autorizado en su SPF/DMARC y el proveedor del cliente
puede mandarlo a spam o rechazarlo; lo fiable es un dominio propio del tenant con el
server incluido en su registro SPF. Detalle en [architecture.md](../architecture.md#correos-salientes-tenantmail).

**Logo:** súbelo por `public/upload_logo.php` (su token es const dentro del archivo,
hay que editarlo en el server). Sin logo propio, el PDF usa el de Gratex.

**Formato de cotización:** todo tenant nuevo cotiza con el formato `gratex`: el
formulario y el PDF de Gratex, con su cuenta bancaria impresa. Si el cliente tiene un
formato propio ya programado (`src/Utils/Cotizacion/`; hoy `ferreteria`), actívalo en
el master después de desplegar el código y de correr la migración 026 en su DB:

```bash
UPDATE tenants SET cotizacion_formato = 'ferreteria' WHERE id = <tenant_id>;
```

El PDF de Ferretería imprime en el pie la `razon_social`, el `correo` y el `telefono`
de `emisor_config`, y la `direccion` en dos líneas como máximo: el `UPDATE` de
`emisor_config` de esta fase tiene que estar hecho. Si el cliente no tiene formato
propio y no debe cotizar con el de Gratex, quita el módulo `cotizaciones` de sus roles.
Cómo se agrega un formato: [../modules/cotizaciones-formatos.md](../modules/cotizaciones-formatos.md).

**Conduces:** con `ferreteria` el tenant también tiene los conduces de mercancía (menú
Conduces, `/api/conduces`, bajo el mismo módulo `cotizaciones`). Sus tablas (`conduces`,
`conduce_items` y `conduce_secuencia`) vienen en el snapshot que aplica el onboarding; en una
DB de tenant que ya existía hay que correr antes la migración 031
(`db/migrations/031_conduces.sql`). El orden: `tools/verificar_migraciones_master.sql` en la
base master y `tools/verificar_migraciones_tenant.sql` en esa DB; `db/master_migrations/012_pos.sql`
en la base master (una sola vez para todos los tenants, antes del código del POS, que viaja en
este mismo despliegue) donde su fila diga `FALTA`; en la DB del tenant, la 027, la 028, la 029
(`029_precios_4_decimales.sql`) y la 030 (`030_pos.sql`, el POS) donde digan `FALTA`; la 031; los
verificadores otra vez, con la 012 del master y la 027 a la 031 del tenant en `APLICADA`; y solo
entonces desplegar `api-gratex` y después `fiscalo` (la lista completa, con el respaldo y las
pruebas de humo, está en
[../modules/cotizaciones-formatos.md](../modules/cotizaciones-formatos.md#despliegue-de-los-conduces)).
La 030 es **obligatoria en todas las DBs de tenant**, se use o no el POS (su cabecera y el
despliegue de los conduces ya lo dicen así). Desde el write-ahead, los `UPDATE` con que toda
factura e-CF anota la respuesta de la DGII (y el de `GET /api/facturas/{id}/estado`) nombran
`pos_idempotency_key` y `envio_pendiente`, dos columnas que agrega la 030: sin ella la factura se
guarda y se envía, pero la respuesta no se anota (queda `PENDIENTE_ENVIO`) y la consulta de estado no
actualiza la fila. Donde no hay POS es inofensiva (solo agrega tablas y columnas) y deja el
verificador entero en `APLICADA` y los esquemas iguales.
Con `gratex` no hay conduces: el menú no sale y la API responde `422`. Detalle:
[../api/conduces.md](../api/conduces.md).

---

## Fase 5 — Verificar

1. **Login:**

```bash
curl -X POST https://<server>/api/auth/login -H "Content-Type: application/json" -d '{"emailOrUsername":"<email>","password":"<pass>"}'
```

2. **Aislamiento de datos:** `GET /api/clients` con el token en `X-API-KEY` debe
   devolver solo los 2 clientes de prueba DGII de SU DB.
3. **Migraciones** (antes de emitir nada: las migraciones del tenant van primero): en
   phpMyAdmin, entra a la base nueva y corre `tools/verificar_migraciones_tenant.sql`. La
   columna `base` debe mostrar la base del tenant (si dice `information_schema` u otra,
   selecciónala y córrelo otra vez) y **todas** las filas deben decir `APLICADA`. La 027, la 028 y la 030 son
   además la compuerta del write-ahead del e-CF: sin la 028, un RFCE (E32 por
   debajo de 250,000) llega a la DGII pero su estado no cabe en la columna ("Data too
   long") y la fila queda `ENVIO_INCIERTO`; sin la 027, toda emisión responde "No se pudo
   asignar el número de comprobante…"; sin la 030, la respuesta de la DGII no se anota y la
   factura queda `PENDIENTE_ENVIO` aunque la DGII ya la recibió. Si falta alguna migración, aplícala y repite el
   verificador antes de seguir con la emisión de prueba.
4. **Emisión:** crear una factura de prueba y confirmar que el PDF trae los datos del
   cliente (no los de Gratex) y que DGII la acepta.
5. **Cotización:** `GET /api/branding` devuelve el `cotizacion_formato` esperado.
   Crear una cotización de prueba, abrir su PDF (logo, datos y pie del tenant; con un
   formato propio, sin la cuenta bancaria de Gratex) y borrarla. Pasos listos en
   `tests/test_cotizaciones_ferreteria.http`.
6. **Conduce** (solo con `ferreteria`): antes de borrar esa cotización, la prueba completa, que
   **gasta `CON-000001`** (decisión del 2026-10-08: el primer conduce real será `CON-000002`; el
   de prueba queda con `activo = 0` y su número no se reusa). En este orden:
   1. crear un conduce desde esa cotización, con una línea libre sin precio;
   2. editarlo sin quitar esa línea;
   3. abrir su PDF (`CONDUCE DE MERCANCÍA`, sin precios);
   4. Facturar > Factura electrónica (e-CF) desde la lista de Conduces: con la línea en precio 0,
      Emitir e-CF (y Vista previa) tiene que bloquearse («Escribe el precio: en el conduce esta
      línea no tenía.»); no escribir el precio ni emitir;
   5. eliminarlo desde la pantalla del conduce (deja de salir en la lista).

   La comprobación de solo la vista previa existe y no gasta número, pero no ejercita el
   bloqueo del precio 0. Pasos listos en `tests/test_conduces.http` (sección Producción).

---

## Fase 6 — Certificación DGII

Solo si arrancó en `certecf`.

1. Portal DGII: solicitar certificación, bajar el set de pruebas (.xlsx) y registrar
   las URLs de WebServices — son **las mismas para todos los tenants**
   (`/api/ecf/recepcion`, `/api/ecf/aprobacion-comercial`, `/api/ecf/autenticacion`);
   el sistema resuelve el dueño por el RNC del XML.
2. `public/cert.html`: `tenant_id`, token del login, `client_id` del resumen y
   `CERT_RUN_TOKEN`.
3. Correr fases 2/3/4, bajar las Representaciones Impresas y subirlas al portal.

---

## Fase 7 — Promover a producción

Al aprobar DGII:

```bash
UPDATE tenants SET ambiente = 'ecf' WHERE id = <tenant_id>;
```

Desde ahí sus listados y su emisión salen en `ecf`, sin afectar a los demás tenants.

---

## Alta sin certificado (el `.p12` llega después)

Un tenant tipo **app** se puede crear sin `.p12` (en `onboard.html` el campo solo es
obligatorio para integración) y su cliente ya puede trabajar: login, productos, clientes,
proveedores, unidades de medida y cotizaciones no necesitan certificado. Lo único que lo
necesita es firmar e-CF.

> **La trampa.** Con `tenants.cert_path` vacío, `CertResolver` no falla: firma con el
> certificado **global del `.env`**, que es el de Gratex. Una factura emitida antes de
> tiempo saldría firmada con el certificado de Gratex y el RNC del tenant nuevo. Lo esperable
> es que la DGII la rechace, pero ya habría una fila guardada y un e-NCF gastado. Por eso el
> alta de un tenant app sin `.p12` **reserva la ruta sola** (paso 2).

Para cargar el certificado después hay una pantalla, `https://gratex.net/api/public/set_tenant_cert.html`
(token `ONBOARD_TOKEN`; pide el `tenant_id` o el RNC y trae dos acciones: **cargar** y
**reservar**). Detrás está `tools/set_tenant_cert.php`, que en el server va por su wrapper
`public/set_tenant_cert.php` (solo POST). **Borra la pantalla y el wrapper del server al terminar.**
Sin pantalla también sirve el mismo POST con `curl.exe` (ejemplos en la cabecera del script): el
`User-Agent` de navegador evita el `406` del WAF.

**1. Alta.** Fases 0, 2, 3 y 4 de arriba, saltando la Fase 1 y dejando `cert` y `cert-pass`
vacíos. El ambiente se elige como siempre (`certecf` si va a certificar, `ecf` si ya está
certificado): las secuencias e-NCF son por ambiente. Guarda el `tenant_id` del resumen.

**2. La ruta queda reservada sola.** Sin `.p12`, `create_tenant.php` deja
`tenants.cert_path = <CERT_DIR>/<rnc>/cert.p12` **sin archivo**, y el resumen lo dice («SIN
CERTIFICADO: la ruta … quedó reservada»). Desde ese momento emitir responde «No se encontró el
certificado digital de tu empresa. Avisa a soporte.» antes de firmar o enviar nada, y el e-NCF
se devuelve. Mientras tanto, no abras caja en su POS.

- **Un tenant app que ya existía sin `cert_path`** (creado antes de este cambio) no quedó
  protegido. Resérvale la ruta en `set_tenant_cert.html` con la opción **Reservar la ruta**.

- **Un tenant que debe firmar a propósito con el certificado del `.env`** (una empresa DEMO
  como la de [multi-tenant-onboarding.md](multi-tenant-onboarding.md#empresa-demo-funcional-testecf);
  Gratex, #1, ya lo hace con `cert_path` vacío): después del alta, en el master,
  `UPDATE tenants SET cert_path = NULL WHERE id = <tenant_id>;`. Y no le corras `reservar`.

**3. Cuando llegue el `.p12`:**

1. No hace falta convertirlo aunque venga con cifrado legacy (RC2-40): la pantalla abre el
   `.p12` con la clave y lo re-cifra en el navegador a AES-256 con la misma clave, igual que
   `onboard.html` ([Fase 1](#fase-1--preparar-el-certificado)). Si no puede (llave no RSA,
   varias llaves, sin internet) lo dice y pide `convertir_cert.ps1`. Por curl no hay
   conversión: el script se niega a guardar un `.p12` que el servidor no puede abrir.
2. En `set_tenant_cert.html`: token, tenant, el archivo, la clave y **Cargar certificado**
   (sube el archivo y cifra la clave en un solo paso).

   Antes de escribir abre el `.p12` con la clave y muestra a nombre de quién está y su
   vigencia. Avisa (`WARNING`, no bloquea) si está vencido o si es el mismo certificado que
   usa otro tenant o el global del `.env`, que es lo que pasa al arrastrar el `.p12` de la
   empresa anterior. **Solo validar** corre todo eso sin escribir. Al terminar verifica
   lo mismo que hará la firma: leer la fila, descifrar la clave y abrir el archivo. También
   sirve para **reemplazar** el certificado de un tenant que ya tenía uno.
3. Si el archivo ya está en `<CERT_DIR>/<rnc>/cert.p12` (subido por el File Manager de
   cPanel), no elijas archivo en la pantalla: manda solo la clave.
4. Probar la firma contra la DGII, sin emitir ni gastar e-NCF, en
   `https://gratex.net/api/public/diagnostico_dgii.html` (token `CERT_RUN_TOKEN` y el RNC
   del tenant).
   Si el `.p12` es de una persona física y sale «no está delegado», la empresa tiene que
   delegar esa cédula en la Oficina Virtual (ver [Troubleshooting](#troubleshooting)).
5. El script **no toca `tenants.ambiente`**. Si arrancó en `certecf`, sigue la
   [Fase 6](#fase-6--certificación-dgii) y al aprobar la [Fase 7](#fase-7--promover-a-producción).

---

## Troubleshooting

| Síntoma | Causa | Arreglo |
|---|---|---|
| `No se pudo crear directorio de certificado` | La carpeta de certificados no existe o PHP no puede escribir en la raíz del API | Crearla a mano; si tiene otro nombre, `CERT_DIR=<nombre>` en el `.env` |
| La salida se corta en "Creando usuario admin" | El email o el username ya existen en otro tenant (UNIQUE globales) | Usar otros; el tenant ya quedó creado, completar con `public/create_user.php` |
| `Ya existe un tenant con RNC ...` | Re-corrida del onboarding sobre un RNC ya registrado | No re-correr: agregar usuarios con `create_user.php` |
| `Unable to read certificate ... unsupported` | `.p12` legacy: alta por CLI sin convertir, o tenant creado antes de que `onboard.html` convirtiera | Convertir el certificado (misma clave) y reemplazarlo en el server |
| `Unable to read certificate ... mac verify failure` | Contraseña incorrecta o `cert_pass_encrypted` NULL | Re-cifrarla con `public/encrypt_credential.php` y actualizar `tenants` |
| `El RNC <cédula> del certificado no está delegado para realizar transaciones` (HTTP 400 en validarsemilla) | El `.p12` es de una persona física y la empresa no la delegó en la Oficina Virtual de la DGII para ese ambiente. El certificado y la firma están bien: es configuración de la DGII | El representante de la empresa delega esa cédula (como firmante de e-CF) en la OFV; luego reintentar en `diagnostico_dgii.html` |
| Emitir responde «No se encontró el certificado digital de tu empresa. Avisa a soporte.» (en el log: `No se pudo leer el certificado del tenant: …`) | El tenant tiene `cert_path` pero no hay archivo ahí: se reservó la ruta ([Alta sin certificado](#alta-sin-certificado-el-p12-llega-después)) y el `.p12` aún no se cargó, o se movió | Cargarlo con `set_tenant_cert` (paso 3 de esa sección) |
| Una factura de un tenant recién creado salió firmada con el certificado de Gratex, o la DGII la rechazó por el firmante | El tenant app se creó sin `.p12` antes de que el alta reservara la ruta (o se le puso `cert_path = NULL` a propósito): sin `cert_path` se firma con el certificado global del `.env` | Reservar la ruta o cargar su `.p12` con `set_tenant_cert`; las filas que quedaron se resuelven como cualquier rechazo (ver [dgii-ecf.md](dgii-ecf.md)) |
| `set_tenant_cert`: «La clave no abre este .p12» o «usa cifrado legacy (RC2-40)» | Clave mal escrita, archivo dañado sin llave privada, o `.p12` de la CA sin convertir | Revisar la clave; si es legacy, `convertir_cert.ps1` (misma clave) y volver a cargar. No escribió nada |
| El PDF muestra datos de Gratex | `emisor_config` incompleto y/o tenant sin logo (Fase 4 saltada) | El `UPDATE` de la Fase 4 + subir el logo |
| Clientes de prueba DGII duplicados | El schema se aplicó dos veces sobre la misma DB | `DELETE FROM clients WHERE rnc IN ('131880681','533445861');` y dejar una corrida limpia |
| La cotización sale con la cuenta bancaria y los textos de Gratex | El tenant sigue en `cotizacion_formato = 'gratex'` (el default) | Activar su formato (Fase 4) o quitar el módulo `cotizaciones` de sus roles |
| Al guardar una cotización: "La pantalla de cotizaciones está desactualizada…" (`409`) | Se cambió `cotizacion_formato` con la pantalla abierta, o el navegador tiene el front de antes | Recargar la página |
| Toda emisión responde "No se pudo asignar el número de comprobante. Inténtalo de nuevo." | Falta la migración 027 en la base del tenant: la clave vieja `uk_e_ncf` choca con el mismo número de otro ambiente y cada intento se salta un número | Correr `tools/verificar_migraciones_tenant.sql` en esa base y aplicar `db/migrations/027_e_ncf_unico_por_ambiente.sql` (se puede repetir) |
| Toda emisión de e-CF responde `502` con la factura guardada sin confirmar (`PENDIENTE_ENVIO`), y en el `error_log` aparece `ENVIO_INCIERTO fase=confirmar marcado=no` con `Unknown column` | Falta la migración 030 en la base del tenant: los `UPDATE` que anotan la respuesta de la DGII nombran `pos_idempotency_key` y `envio_pendiente`, columnas que agrega ella | Correr `tools/verificar_migraciones_tenant.sql` en esa base y aplicar `db/migrations/030_pos.sql`; las facturas que ya quedaron en `PENDIENTE_ENVIO` se resuelven con el procedimiento de [dgii-ecf.md](dgii-ecf.md) (la DGII sí las recibió) |
| `Los conduces no están disponibles para tu empresa.` (`422`) | El tenant no está en `cotizacion_formato = 'ferreteria'`: los conduces son solo de ese formato | Si el cliente es Ferretería, activar su formato (Fase 4); si no, es lo esperado (recargar el front: el menú Conduces no debe salir) |
| Conduces: `500` al listar o guardar, y en el log `[conduces] … Table '….conduces' doesn't exist` | La DB del tenant no tiene la migración 031 | Correr `db/migrations/031_conduces.sql` en esa DB y revisar su fila final (`todo_ok` = `SI`) |

---

## Referencia rápida

| Dato | Dónde vive |
|---|---|
| Registro del tenant | `tenants` (master) |
| Usuarios y tokens de sesión | `users` / `api_tokens` (master) |
| Datos del emisor | `emisor_config` (DB del tenant) |
| Certificado | `<CERT_DIR>/<rnc>/cert.p12` + `tenants.cert_path` + `tenants.cert_pass_encrypted` (se cargan o reemplazan con `tools/set_tenant_cert.php`) |
| Logo | `logos/<tenant_id>.<ext>` + `tenants.logo_path` |
| Formato de cotización | `tenants.cotizacion_formato` (master; `gratex` por defecto) |
| Conduces (solo `ferreteria`) | `conduces` / `conduce_items` / `conduce_secuencia` (DB del tenant, migración 031) |
| Secuencias e-NCF | `ncf_sequences` (DB del tenant), por ambiente |
