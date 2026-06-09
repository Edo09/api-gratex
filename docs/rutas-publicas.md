# Rutas públicas y API — multi-tenant / certificación

Herramientas admin y endpoints disponibles. Base: `https://gratex.net/api/`.

Las herramientas viven en `/api/public/` (el `.htaccess` sirve directo los archivos
existentes ahí; el resto de `/api/*` lo enruta `index.php` → `src/Router.php`).

> Las de `public/` son **herramientas admin** (crean tenants, emiten facturas).
> Protégelas: tokens fuertes + HTTPS, y considera Basic Auth de cPanel sobre `/api/public/`.

Relacionado: [multi-emisor-master-db-prd.md](multi-emisor-master-db-prd.md) · [pasos_certificacion_dgii](../pasos_certificacion_dgii/README.md)

---

## Onboarding de clientes

| Ruta | Qué hace | Token |
|---|---|---|
| `GET /api/public/onboard.html` | UI alta de tenant (app/integración): datos, cert `.p12`, logo, usuario admin | el form pide el token |
| `POST /api/public/create_tenant.php` | Handler del onboarding. Crea tenant + (app) DB schema/migrations + `emisor_config` + usuario admin + logo | `ONBOARD_TOKEN` |
| `GET/POST /api/public/create_user.php` | Alta de usuarios extra de un tenant (form + handler) | `CREATE_USER_TOKEN` |
| `GET/POST /api/public/upload_logo.php` | Subir/cambiar el logo de un tenant | `UPLOAD_LOGO_TOKEN` |

## Certificación DGII

| Ruta | Qué hace | Token |
|---|---|---|
| `GET /api/public/cert.html` | Wizard de 15 pasos (mini-login, correr fases 2/3/4, bajar Representación Impresa, URLs) | usa los de abajo |
| `POST /api/public/cert_run.php` | Corre los runners fase 2/3/4 (lo invoca el wizard; reusa `tools/send_faseX.php`) | `CERT_RUN_TOKEN` (.env) |

## Recepción / operación

| Ruta | Qué hace | Token |
|---|---|---|
| `GET/POST /api/public/import_recibido.php` | Importa manualmente e-CF recibidos a `ecf_recibidos` (sube los XML firmados). Para e-CF que un emisor te envió sin completar el handshake de auth y que tu recepción no guardó. Resuelve el tenant por RNCComprador; los deja `estado=RECIBIDO`, pendientes de aprobar/rechazar | `IMPORT_RECIBIDO_TOKEN` |

## Preexistentes

| Ruta | Qué hace |
|---|---|
| `GET /api/public/docs.html` | Documentación del API |
| `GET /api/public/readlog.php` | Lee el `error_log` del server |

> **One-time (ya eliminados tras el setup):** `gen_key.php`, `migrate_gratex.php`.
> Recuperables de git si hace falta re-migrar.

---

## Tokens — dónde se configuran

| Token | Dónde |
|---|---|
| `ONBOARD_TOKEN` | const en `tools/create_tenant.php` |
| `CREATE_USER_TOKEN` | const en `public/create_user.php` |
| `UPLOAD_LOGO_TOKEN` | const en `public/upload_logo.php` |
| `IMPORT_RECIBIDO_TOKEN` | const en `public/import_recibido.php` |
| `CERT_RUN_TOKEN` | `.env` |
| "Token API del tenant" | no es fijo: sale del login del usuario del tenant (`POST /api/auth/login`) |

---

## API (vía Router) — para los clientes

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

## Flujo típico (tenant app)

1. cPanel: crear DB MySQL + usuario + privilegios.
2. `onboard.html` (tipo App): DB + emisor + logo + **usuario admin**.
3. `cert.html` paso 1: login (email) → carga el "Token API del tenant".
4. `cert.html` pasos 2-5: correr fases + bajar Representación Impresa → subir al portal DGII.
5. Pasos manuales del portal (1, 6, 7, 12-15).
6. Al certificar: cambiar `tenants.ambiente` a `ecf`.

## Flujo típico (tenant integración)

1. `onboard.html` (tipo Integración): RNC + cert `.p12` + (opcional) webhook → entrega **api_key + api_secret**.
2. El cliente consume `POST /api/integracion/ecf` con sus headers.
3. Recibe e-CF/aprobaciones por `GET /api/integracion/recibidos|aprobaciones` o webhook.
