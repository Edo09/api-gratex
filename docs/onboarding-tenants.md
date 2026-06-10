# Onboarding de tenants — app e integración

Guía paso a paso para dar de alta un cliente nuevo (tenant) de cada tipo, y cómo
funciona por dentro. Base: `https://gratex.net/api/`.

Relacionado: [rutas-publicas.md](rutas-publicas.md) ·
[multi-emisor-master-db-prd.md](multi-emisor-master-db-prd.md) ·
[pasos_certificacion_dgii](../pasos_certificacion_dgii/README.md)

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
4. Crea el usuario admin en `master.users` (email único global, username único
   por tenant).
5. Guarda cert y logo.
6. **Imprime el resumen** — guardar de aquí:
   - `tenant_id`
   - `client_id certificacion (RNC 131880681)` → para el wizard de cert

### 4. Login y verificación
```
POST /api/auth/login
{"emailOrUsername":"<email>","password":"..."}            ← email (único global)
{"emailOrUsername":"<username>","password":"...","tenant_id":N}  ← username (por tenant)
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
