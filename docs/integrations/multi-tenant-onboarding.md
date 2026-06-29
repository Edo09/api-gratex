# Multi-tenant — Onboarding de tenants (app e integración)

Guía paso a paso para dar de alta un cliente nuevo (tenant) de cada tipo, y cómo
funciona por dentro. Base: `https://gratex.net/api/`. Las herramientas admin y rutas
públicas están en la sección [Rutas públicas y herramientas admin](#rutas-públicas-y-herramientas-admin) al final.

Relacionado: [../architecture.md](../architecture.md) (arquitectura multi-tenant) ·
[dgii-ecf.md](dgii-ecf.md) ·
[../../pasos_certificacion_dgii/README.md](../../pasos_certificacion_dgii/README.md)

---

## Conceptos

| | Tipo **app** | Tipo **integración** |
|---|---|---|
| Qué es | Usa la app de facturación de Gratex | Manda JSON desde su propio sistema, recibe XML firmado |
| DB propia | **Sí** (DB-per-tenant, esquema completo) | **No** (sus datos viven en tablas espejo del master, por `tenant_id`) |
| Auth | Login de persona → token de sesión (`X-API-KEY`) | Credenciales de máquina: `X-API-KEY` + `X-API-SECRET` |
| Certificado | El suyo (`.p12`), por tenant | El suyo (`.p12`), **obligatorio** |
| Secuencias e-NCF | Las maneja el sistema (`ncf_sequences` en su DB, por ambiente) | Las maneja el cliente (manda el `e_ncf` en el JSON) |
| Ambiente | Per-tenant (`tenants.ambiente`) | Per-tenant (`tenants.ambiente`) |

**Ciclo de vida del ambiente:** todo tenant arranca en `certecf` mientras pasa la
certificación DGII; al certificar se promueve a `ecf` (producción):

```sql
UPDATE gratex_master.tenants SET ambiente = 'ecf' WHERE id = <tenant_id>;
```

El ambiente lo resuelve `src/AmbienteResolver.php` con prioridad:
**override explícito del request** (ej. los runners de cert mandan `certecf`) >
**`tenants.ambiente` del tenant resuelto** > **`DGII_ECF_ENVIRONMENT` global**
(fallback single-tenant). Aplica a emisión, secuencias e-NCF, filtros de
listados/stats y al ambiente que se graba al recibir documentos — así un tenant
puede certificar en `certecf` mientras otro opera en `ecf`, en el mismo server.

**Cómo enruta el sistema (master = `gratex_master`):**
- Login (`POST /api/auth/login`) → busca en `master.users` → token en
  `master.api_tokens` con `tenant_id` → cada request resuelve el tenant por el
  token y conecta a su DB.
- Integración → `X-API-KEY`+`X-API-SECRET` → `master.tenants` (secret sha256).
- DGII entrante (recepción/aprobación) → resuelve por el **RNC del XML**
  (RNCComprador para e-CF entrantes; RNCEmisor para aprobaciones).

**URLs DGII compartidas:** TODOS los tenants registran las mismas URLs en su
directorio DGII (Oficina Virtual → registro de WebServices):

| Servicio | URL |
|---|---|
| Recepción | `https://gratex.net/api/ecf/recepcion` |
| Aprobación Comercial | `https://gratex.net/api/ecf/aprobacion-comercial` |
| Autenticación | `https://gratex.net/api/ecf/autenticacion` |

El sistema sabe de quién es cada documento por el RNC del XML. La recepción es
"abierta": acepta con Bearer DGII **o** con firma XMLDSig válida.

---

## Requisitos previos (ambos tipos)

1. `MULTI_TENANT_ENABLED=true` en el `.env` del server (ya activo en prod).
2. El certificado digital `.p12` del cliente + su contraseña.
3. El RNC del cliente (9-11 dígitos).
4. `ONBOARD_TOKEN` (const en `tools/create_tenant.php`).

---

## Alta de tenant tipo APP

### 1. Crear la base de datos (cPanel)
HostGator/cPanel → MySQL Databases:
- Crear DB (ej. `mtldtmte_cliente2db`).
- Crear usuario MySQL + contraseña.
- Dar **todos los privilegios** al usuario sobre la DB.
- El usuario admin del onboarding (`ADMIN_DB_USER`/`MASTER_DB_USER` del `.env`)
  también debe poder escribirla (para aplicar el schema).

### 2. Formulario de onboarding
`https://gratex.net/api/public/onboard.html` → tipo **App**. Campos:

| Campo | Nota |
|---|---|
| token | `ONBOARD_TOKEN` |
| nombre, rnc | Identidad del tenant |
| razon-social, direccion | Para `emisor_config` |
| ambiente | **`certecf`** si va a certificar (default `ecf`) |
| db-name, db-user, db-pass, db-host, db-port | Los del paso 1 (host default `localhost`) |
| cert (.p12) + cert-pass | Se guarda en `certificado_dgii/<rnc>/cert.p12`, pass cifrada AES-256-GCM |
| logo | Opcional; va a `logos/<tenant_id>.<ext>` + `tenants.logo_path` (sale en la Representación Impresa) |
| admin-email, admin-pass, admin-name, admin-username | Usuario admin inicial (los 4 o ninguno) |

### 3. Qué hace el handler (`create_tenant.php`)
1. Inserta el tenant en `master.tenants` (credenciales DB cifradas).
2. Aplica **`db/tenant_schema.sql`** (esquema completo consolidado — ya no se
   corren migraciones una por una).
3. `UPDATE emisor_config` con los datos reales del tenant.
4. Crea el usuario admin en `master.users` (email y username únicos globales).
5. Guarda cert y logo.
6. **Imprime el resumen** — guardar de aquí:
   - `tenant_id`
   - `client_id certificacion (RNC 131880681)` → para el wizard de cert

### 4. Login y verificación
```
POST /api/auth/login
{"emailOrUsername":"<email|username>","password":"..."}   ← email o username (ambos únicos globales, sin tenant_id)
```
Devuelve el token de sesión → header `X-API-KEY` para todo el API.
Smoke: `GET /api/clients` debe devolver solo los clientes de SU DB (los 2 de
prueba DGII recién creados).

### 5. Certificación DGII
1. Portal DGII: solicitar certificación, descargar set de pruebas (.xlsx),
   registrar las URLs de WebServices (tabla de arriba).
2. `https://gratex.net/api/public/cert.html` (wizard 15 pasos): header con
   `tenant_id`, token API (del login), `client_id` (del resumen), `CERT_RUN_TOKEN`.
3. Correr fases 2/3/4 desde el wizard; bajar las Representaciones Impresas
   (ZIP de PDFs con QR) y subirlas al portal; pasos manuales del portal.
4. Al aprobar DGII: **promover a producción** (`UPDATE tenants SET ambiente='ecf'`).

### 6. Más usuarios (opcional)
`https://gratex.net/api/public/create_user.php` (token `CREATE_USER_TOKEN`).

---

## Alta de tenant tipo INTEGRACIÓN

### 1. Formulario de onboarding
`onboard.html` → tipo **Integración**. Campos:

| Campo | Nota |
|---|---|
| token | `ONBOARD_TOKEN` |
| nombre, rnc | Identidad del tenant |
| ambiente | **`certecf`** mientras certifica; `ecf` al terminar |
| cert (.p12) + cert-pass | **Obligatorio** (firma sus e-CF y acuses) |
| webhook-url | Opcional: push de e-CF/aprobaciones entrantes |
| webhook-secret | Opcional; si das URL sin secret se genera uno |

No se crea DB ni usuario: el handler registra el tenant y entrega
**`api_key` + `api_secret`** (el secret se muestra UNA sola vez — el master
solo guarda su hash sha256). Entregarlos al cliente por canal seguro.

### 2. Cómo consume el cliente
Headers en todo request: `X-API-KEY: <api_key>` + `X-API-SECRET: <api_secret>`.

| Endpoint | Uso |
|---|---|
| `POST /api/integracion/ecf` | Emitir e-CF: manda JSON (emisor, comprador, items, **e_ncf propio**) → recibe XML firmado + respuesta DGII. Backup en `master.ecf_integracion_backup` |
| `POST /api/integracion/aprobacion-comercial` | Aprobar/rechazar (ACECF) un e-CF que le emitieron |
| `GET /api/integracion/recibidos` | Polling de e-CF que le facturaron (filtrado por SU ambiente) |
| `GET /api/integracion/aprobaciones` | Polling de aprobaciones recibidas sobre lo que emitió |

Si configuró webhook: los documentos entrantes también se notifican por POST
firmado HMAC-SHA256 (header de firma con el `webhook_secret`), con reintentos.

### 3. Flujo entrante (automático)
Otro emisor le factura → POST a `gratex.net/api/ecf/recepcion` → el sistema
resuelve el tenant por RNCComprador → guarda en `master.ecf_recibidos`
(`tenant_id` + `ambiente` del tenant) → devuelve ARECF firmado con el cert del
tenant → webhook/polling.

### 4. Certificación y promoción
El cliente certifica su flujo contra DGII en `certecf` (mandando sus e-CF con
`ambiente` de prueba), registra las URLs compartidas en su directorio, y al
aprobar se promueve: `UPDATE tenants SET ambiente='ecf' WHERE id=<id>;`
Sus listados (`/recibidos`, `/aprobaciones`) muestran solo el ambiente actual.

---

## Empresa DEMO (funcional, testecf)

Tenant app normal pero apuntando al ambiente de pruebas libres de DGII
(`testecf`): emite XML real, firmado y enviado, sin tocar certificación ni
producción. Útil para demos de venta con flujo completo.

1. cPanel: crear DB (ej. `mtldtmte_demodb`) + usuario + privilegios.
2. `onboard.html` → tipo **App**:
   - nombre "Empresa Demo", **ambiente = `testecf`**.
   - RNC: uno real distinto al de Gratex (`tenants.rnc` es UNIQUE; no puede
     repetir 131256432). Si DGII testecf rechaza un RNC no habilitado, el demo
     igual muestra todo el flujo hasta la respuesta de DGII.
   - cert: el `.p12` propio, o vacío (cae al cert global del `.env`).
   - usuario demo (email/password para el login del demo).
3. El schema ya siembra secuencias e-NCF en `testecf` (ademas de certecf/ecf),
   así que la emisión funciona de una.
4. Login con el usuario demo → emitir facturas/gastos → van a
   `https://ecf.dgii.gov.do/testecf/...`. Los listados solo muestran datos
   `testecf` (filtro por ambiente del tenant) — el demo nunca se mezcla con
   producción.
5. Reset del demo: `TRUNCATE` de `facturas`/`factura_items`/`gastos` en SU DB
   (o re-crear el tenant). Nada de Gratex se toca.

> Nota: `testecf` de DGII a veces está caído o lento; si el demo es en vivo,
> probar emisión 10 min antes.

## Dónde queda cada cosa

| Dato | app | integración |
|---|---|---|
| Registro del tenant | `master.tenants` | `master.tenants` |
| Usuarios / tokens de sesión | `master.users` / `master.api_tokens` | — (no aplica) |
| Facturas emitidas | `facturas` (su DB) | sistema del cliente + backup en master |
| e-CF recibidos | `ecf_recibidos` (su DB) | `master.ecf_recibidos` (por tenant_id) |
| Aprobaciones recibidas | `aprobaciones_comerciales` (su DB) | `master.aprobaciones_comerciales` (por tenant_id) |
| Certificado | `certificado_dgii/<rnc>/cert.p12` | igual |
| Logo | `logos/<tenant_id>.<ext>` | igual (si aplica RI) |

## Troubleshooting rápido

| Síntoma | Causa probable |
|---|---|
| `RNC comprador (X) no registrado` al recibir e-CF | El RNC del tenant en `master.tenants` no coincide con el RNCComprador del XML, o `activo=0` |
| `Invalid or inactive API token` tras login | Token de otro tenant/DB; re-login. Verificar `MULTI_TENANT_ENABLED` |
| `api_key/api_secret invalido` | Secret mal copiado (solo se mostró una vez) → regenerar |
| Recibidos mezclan datos de prueba | Revisar `tenants.ambiente` (integración) o `DGII_ECF_ENVIRONMENT` (app) |
| e-CF entrante rechazado `firma INVALIDA` | XML alterado en tránsito o firma realmente inválida; ver `error_log` |

---

## Rutas públicas y herramientas admin

Las herramientas viven en `/api/public/` (el `.htaccess` sirve directo los archivos
existentes ahí; el resto de `/api/*` lo enruta `index.php` → `src/Router.php`).

> Las de `public/` son **herramientas admin** (crean tenants, emiten facturas).
> Protégelas: tokens fuertes + HTTPS, y considera Basic Auth de cPanel sobre `/api/public/`.

### Onboarding de clientes

| Ruta | Qué hace | Token |
|---|---|---|
| `GET /api/public/onboard.html` | UI alta de tenant (app/integración): datos, cert `.p12`, logo, usuario admin | el form pide el token |
| `POST /api/public/create_tenant.php` | Handler del onboarding. Crea tenant + (app) DB con `tenant_schema.sql` (esquema completo consolidado) + `emisor_config` + usuario admin + logo | `ONBOARD_TOKEN` |
| `GET/POST /api/public/create_user.php` | Alta de usuarios extra de un tenant (form + handler) | `CREATE_USER_TOKEN` |
| `GET/POST /api/public/upload_logo.php` | Subir/cambiar el logo de un tenant | `UPLOAD_LOGO_TOKEN` |

### Certificación DGII

| Ruta | Qué hace | Token |
|---|---|---|
| `GET /api/public/cert.html` | Wizard de 15 pasos (mini-login, correr fases 2/3/4, bajar Representación Impresa, URLs) | usa los de abajo |
| `POST /api/public/cert_run.php` | Corre los runners fase 2/3/4 (lo invoca el wizard; reusa `tools/send_faseX.php`) | `CERT_RUN_TOKEN` (.env) |

### Recepción / operación

| Ruta | Qué hace | Token |
|---|---|---|
| `GET/POST /api/public/import_recibido.php` | Importa manualmente e-CF recibidos a `ecf_recibidos` (sube los XML firmados). Para e-CF que un emisor te envió sin completar el handshake de auth y que tu recepción no guardó. Resuelve el tenant por RNCComprador; los deja `estado=RECIBIDO`, pendientes de aprobar/rechazar | `IMPORT_RECIBIDO_TOKEN` |

### Preexistentes

| Ruta | Qué hace |
|---|---|
| `GET /api/public/docs.html` | Documentación del API |
| `GET /api/public/readlog.php` | Lee el `error_log` del server |

> **One-time (ya eliminados tras el setup):** `gen_key.php`, `migrate_gratex.php`.
> Recuperables de git si hace falta re-migrar.

---

### Tokens — dónde se configuran

| Token | Dónde |
|---|---|
| `ONBOARD_TOKEN` | const en `tools/create_tenant.php` |
| `CREATE_USER_TOKEN` | const en `public/create_user.php` |
| `UPLOAD_LOGO_TOKEN` | const en `public/upload_logo.php` |
| `IMPORT_RECIBIDO_TOKEN` | const en `public/import_recibido.php` |
| `CERT_RUN_TOKEN` | `.env` |
| "Token API del tenant" | no es fijo: sale del login del usuario del tenant (`POST /api/auth/login`) |

---

### API (vía Router) — para los clientes

| Endpoint | Auth | Uso |
|---|---|---|
| `POST /api/auth/login` | — | Obtener token del tenant. Email (global) o `username` + `tenant_id` |
| `POST /api/facturas` | `X-API-KEY` (token) | Emitir e-CF (tenant **app**) |
| `GET /api/facturas/{id}/pdf` | `X-API-KEY` | Representación Impresa (PDF con QR DGII) |
| `GET /api/facturas/{id}/xml` | `X-API-KEY` | XML firmado |
| `GET /api/facturas/{id}/estado` | `X-API-KEY` | Consultar estado DGII |
| `POST /api/aprobaciones-comerciales` | `X-API-KEY` | ACECF saliente (app, rol comprador) |
| `POST /api/integracion/ecf` | `X-API-KEY` + `X-API-SECRET` | Emitir e-CF (tenant **integración**, JSON→XML) |
| `POST /api/integracion/aprobacion-comercial` | key+secret | Aprobar/rechazar e-CF recibido (integración) |
| `GET /api/integracion/recibidos` | key+secret | Listar e-CF recibidos (integración) |
| `GET /api/integracion/aprobaciones` | key+secret | Listar aprobaciones recibidas (integración) |
| `POST /api/ecf/recepcion` | Bearer DGII **o** firma XMLDSig válida (receptor abierto) | Recepción de e-CF entrantes. Resuelve tenant por RNCComprador |
| `POST /api/ecf/aprobacion-comercial` | Bearer DGII **o** firma XMLDSig válida | Recepción de aprobaciones comerciales (ACECF). Resuelve tenant por RNCEmisor |
| `GET/POST /api/ecf/autenticacion[...]` | DGII (semilla) | Flujo de autenticación DGII entrante |

### Headers
- **App:** `X-API-KEY: <token de sesión del tenant>` (del login).
- **Integración:** `X-API-KEY: <api_key>` + `X-API-SECRET: <api_secret>` (del onboarding).

### Recepción abierta (e-CF / ACECF entrantes)
`POST /api/ecf/recepcion` y `POST /api/ecf/aprobacion-comercial` aceptan el documento
si trae un **Bearer DGII válido** (handshake semilla→token) **o** si su **firma
digital XMLDSig es válida**. Así se reciben e-CF de emisores cuyo software no
completa el handshake (la `URL Autenticación` del directorio es opcional). La firma
es el gate de integridad/autenticidad; **no** valida la cadena de CAs de la DGII, así
que el e-CF entra como `RECIBIDO`/pendiente (se revisa antes de aprobar/rechazar) y el
RNC destino debe ser un tenant registrado. El **listado** (`GET /api/ecf/recepcion`)
sigue requiriendo `X-API-KEY`.

---

### Flujo típico (tenant app)

1. cPanel: crear DB MySQL + usuario + privilegios.
2. `onboard.html` (tipo App): DB + emisor + logo + **usuario admin**.
3. `cert.html` paso 1: login (email) → carga el "Token API del tenant".
4. `cert.html` pasos 2-5: correr fases + bajar Representación Impresa → subir al portal DGII.
5. Pasos manuales del portal (1, 6, 7, 12-15).
6. Al certificar: cambiar `tenants.ambiente` a `ecf`.

### Flujo típico (tenant integración)

1. `onboard.html` (tipo Integración): RNC + cert `.p12` + (opcional) webhook → entrega **api_key + api_secret**.
2. El cliente consume `POST /api/integracion/ecf` con sus headers.
3. Recibe e-CF/aprobaciones por `GET /api/integracion/recibidos|aprobaciones` o webhook.
