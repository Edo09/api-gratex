# Arquitectura — Gratex API

API REST en PHP para facturación electrónica (e-CF) de la DGII (República Dominicana).

- **Stack:** PHP 8+, MySQL (PDO), Apache. **Sin Composer** (libs vendorizadas).
- **Entry point:** `index.php` → `src/Router.php`.
- **Patrón:** front controller + ruteo manual → Controllers → Models → `Database` (PDO singleton).
- **Multi-tenant (DB-per-tenant)** en producción: un **master DB** enruta cada request a la DB
  de negocio del tenant. Ver la sección [Multi-tenant](#multi-tenant-db-per-tenant).
- **Auth:** `X-API-KEY` (token de sesión, app) · `X-API-KEY` + `X-API-SECRET` (integración) ·
  `Authorization: Bearer` (DGII entrante).

Estado: certificación DGII completa (2026-06-01); multi-tenant **en vivo** desde 2026-06-08.
Gratex es el tenant #1.

---

## Flujo de un request

```
Apache (.htaccess) → index.php → src/Router.php
  → CORS + preflight OPTIONS
  → parsea el endpoint (PRIMERA ocurrencia de /api/ — URLs DGII traen doble /api/)
  → Router PRE-RESUELVE el tenant (best-effort AuthMiddleware) antes de incluir el controller
  → switch por el segmento[0] de la ruta → require Controller
      → AuthMiddleware->validateRequest()        (rutas con token)
      → TenantResolver fija Database::setCredentials() → la DB del tenant
      → Model (queries PDO al DB del tenant)
      → Utils (build XML, firmar, PDF, email)
  → respuesta JSON / XML / PDF
```

Nota de ruteo: usa `strpos` sobre la **primera** ocurrencia de `/api/` — las URLs de callback
de DGII contienen un doble segmento `/api/`.

---

## Estructura de carpetas

```
api-gratex/
├── index.php                  # entry point → incluye el Router
├── .htaccess                  # rewrite Apache (sin directiva <If> — el server no la soporta)
├── .env / .env.example        # config (creds DB, master, cert DGII, ambiente, flags)
│
├── src/
│   ├── Router.php             # despacho de rutas (switch por segmento) + pre-resolución de tenant
│   ├── Database.php           # PDO singleton del tenant (setCredentials dinámico)
│   ├── MasterDatabase.php     # PDO singleton del master (routing/auth/datos globales)
│   ├── TenantResolver.php     # resuelve tenant por api_key / rnc / token → fija credenciales
│   ├── AmbienteResolver.php   # resuelve ambiente DGII (override > tenant > global)
│   ├── CertResolver.php       # cert .p12 por tenant (fallback al cert global del .env)
│   │
│   ├── Middleware/AuthMiddleware.php   # X-API-KEY / Bearer / key+secret
│   ├── Controllers/           # handlers HTTP (uno por grupo de rutas)
│   ├── Models/                # acceso a datos (PDO)
│   └── Utils/                 # servicios (PDF, email, motor e-CF, webhooks)
│       ├── FacturacionElectronica/   # núcleo e-CF DGII
│       └── Pdf/                       # plantillas de Representación Impresa por tenant
│
├── config/openssl-legacy.cnf  # OpenSSL legacy para firmar el .p12
├── db/                        # esquemas (master/tenant) + migraciones
├── docs/                      # esta documentación
├── tests/                     # archivos .http
├── tools/                     # scripts CLI (onboarding, runners de certificación)
├── pasos_certificacion_dgii/  # runners de fases de certificación (copias autocontenidas)
├── samples/                   # XSDs DGII + XLSX de sets de prueba
├── public/                    # herramientas admin (onboard, cert wizard, docs.html)
└── vendor/                    # fpdf (PDF), phpqrcode (QR) — vendorizado a mano
```

---

## Rutas (`src/Router.php`)

| Segmento | Controller | Auth | Para qué |
|---|---|---|---|
| `auth` | `authController.php` | login | login / signout / tokens |
| `users` | `userController.php` | token | CRUD usuarios |
| `clients` | `clientController.php` | token | CRUD clientes |
| `products` | `productController.php` | token | catálogo de productos/servicios |
| `categories` | `categoryController.php` | token (`categories`) | categorías de inventario |
| `warehouses` | `warehouseController.php` | token (`warehouses`) | almacenes de inventario |
| `proveedores` | `proveedorController.php` | token | directorio de proveedores (+ compras) |
| `unidades-medida` | `unidadMedidaController.php` | token | catálogo DGII de unidades (solo lectura) |
| `cotizaciones` | `cotizacionController.php` | token | cotizaciones + PDF |
| `facturas` | `facturaController.php` | token | facturas (e-CF) + PDF/XML/estado |
| `facturas-simples` | `facturaSimpleController.php` | token | facturas NO electrónicas |
| `ncf` | `ncfController.php` | token | secuencias NCF / rangos e-NCF |
| `gastos` | `gastosController.php` | token | gastos menores y facturas de proveedores |
| `reportes` | `Reporte606Controller` / `Reporte607Controller` | token | formatos 606 / 607 DGII |
| `facturacion-electronica` | `facturacionElectronicaController.php` | token | flujo de autenticación DGII (diagnóstico) |
| `aprobaciones-comerciales` | `aprobacionComercialOutgoingController.php` | token | enviar ACECF (rol comprador) |
| `branding` | `brandingController.php` | token | plantilla/color/logo PDF por tenant |
| `roles` | `roleController.php` | token (admin) | gestión de roles y permisos (RBAC) |
| `emisor` | `emisorController.php` | token | datos fiscales del emisor / tenant |
| `landing` | `landingController.php` | token | config de landing |
| `integracion` | `integracionEcf/Aprobacion/Consulta...` | key+secret | modo integración (JSON→XML, sin DB propia) |
| `ecf/recepcion` | `ecfRecepcionController.php` | Bearer DGII **o** firma XMLDSig | e-CF entrantes de otros emisores |
| `ecf/aprobacion-comercial` | `ecfAprobacionComercialController.php` | Bearer DGII **o** firma | aprobaciones comerciales entrantes |
| `ecf/autenticacion` | `ecfAutenticacionController.php` | DGII | flujo semilla/validación |

`/api/ecf/*` despacha por el segmento[1] (`recepcion` / `aprobacion-comercial` / `autenticacion`).

Referencia de payloads de la API: [api/facturas.md](api/facturas.md).

---

## Capa de datos

- **`Database.php`** — PDO singleton del **tenant**. `setCredentials()` resetea el singleton
  para apuntar a la DB del tenant resuelto; sin credenciales cae al `.env` (single-tenant).
  La propiedad real es `$conexion`; método `getConnection()`.
- **`MasterDatabase.php`** — PDO singleton del **master** (`gratex_master`). Métodos:
  `getTenantByApiKey()`, `getTenantByRnc()`, `getTenantById()`, `validateUserToken()`,
  `loginUser()`, `saveIntegrationEcf()`.
- **Esquemas:** `db/tenant_schema.sql` (negocio), `db/master_schema.sql` (routing/auth).
  Detalle de tablas: [database/schema.md](database/schema.md).
- **Migraciones:** `db/migrations/` (tenant, activas 012–016; 001–011 en `deprecated/`),
  `db/master_migrations/` (master). Ver [../db/migrations/README.md](../db/migrations/README.md).

---

## Multi-tenant (DB-per-tenant)

Cada empresa cliente es un **tenant** con su propia DB MySQL (esquema completo). El **master
DB** (`gratex_master`) es el directorio que mapea "quién entra" → "a qué DB conectar". Cero
datos de negocio en el master.

```
Request
  ├─ App (login)        → master.users (email global) → token con tenant_id
  ├─ Integración        → master.tenants WHERE api_key=? (verifica api_secret_hash)
  └─ DGII entrante      → master.tenants WHERE rnc=? (RNCComprador / RNCEmisor del XML)
        │
        ▼  TenantResolver descifra credenciales (AES-256-GCM) → Database::setCredentials()
        ▼  El código de negocio existente corre SIN cambios (queries van a la DB del tenant)
```

### Dos tipos de tenant (`tenants.tipo`)
- **`app`** — usa la app de facturación de Gratex. **DB propia** (facturas, clientes, NCF,
  e-CF, gastos…). El onboarding crea su DB con `db/tenant_schema.sql`.
- **`integracion`** — usa su propio sistema; manda JSON con el e-CF (incluido el `eNCF` ya
  asignado por el cliente) y recibe el XML firmado. **Sin DB propia**: sus documentos viven en
  tablas espejo del master (`ecf_integracion_backup`, `ecf_recibidos`/`aprobaciones_comerciales`
  con `tenant_id`).

### Qué vive en master vs en cada tenant
- **Master:** `tenants`, `users` (+`tenant_id`, email único global), `api_tokens` (+`tenant_id`),
  `landing_*`, `auth_seeds`/`auth_tokens_emitidos`, `unidades_medida`, y los espejos de integración.
- **Tenant `app`:** `facturas`, `factura_items`, `clients`, `cotizaciones`, `ncf_sequences`,
  `emisor_config`, `ecf_recibidos`, `aprobaciones_comerciales`, `gastos`, `products`, `proveedores`.

### Resolvers
- **`TenantResolver`** — `resolveByApiKey()`, `resolveByRnc()`, `resolveById()`,
  `resolveByCredentials()` (key+secret), `isIntegration()`, `current()`. Descifra y llama
  `Database::setCredentials()`.
- **`AmbienteResolver`** — ambiente DGII con prioridad: **override del request** >
  **`tenants.ambiente`** > **`DGII_ECF_ENVIRONMENT` global**. Cada tenant certifica en `certecf`
  y se promueve a `ecf` por su cuenta, en el mismo server.
- **`CertResolver`** — cert `.p12` del tenant (`tenants.cert_path`/`cert_pass_encrypted`) o
  fallback al cert global del `.env`. Cableado en emisión, ACECF y firma de acuses.

### Cifrado de credenciales (AES-256-GCM)
`db_pass`/`cert_pass`/`webhook_secret` se guardan cifrados (`iv(12)‖tag(16)‖ciphertext`) con
`MASTER_ENCRYPTION_KEY` (32 bytes hex, generada una sola vez).

### Gating
Todo lo multi-tenant está **gated** por `MULTI_TENANT_ENABLED`. Con el flag en `false` el
comportamiento es idéntico al single-tenant (todo en una DB, cert del `.env`). En producción
está en `true`.

Onboarding paso a paso (app/integración, demo, certificación): [integrations/multi-tenant-onboarding.md](integrations/multi-tenant-onboarding.md).

---

## Autenticación (`AuthMiddleware`)

- Lee el token de `X-API-KEY`, si no de `Authorization: Bearer <token>`.
- En multi-tenant valida contra el **master** (`validateUserToken` → `user_id` + `tenant_id`)
  y resuelve el tenant antes de que cualquier controller toque la DB.
- Integración: `X-API-KEY` + `X-API-SECRET` → `master.tenants` (compara `hash_equals` del sha256).
- Rutas DGII entrantes (`/api/ecf/*`): Bearer del flujo de auth DGII **o** firma XMLDSig válida
  (recepción abierta — ver [integrations/dgii-ecf.md](integrations/dgii-ecf.md)).
- `sendUnauthorized()` → 401 JSON.

### Autorización (RBAC)
Sobre la autenticación, `src/PermissionGate.php` (invocado en el Router antes del controller)
aplica permisos por **rol del usuario**, con roles **per-tenant** en el master. Gated por
`PERMISSIONS_ENFORCE` (sombra → enforce). Detalle: [modules/roles-permisos.md](modules/roles-permisos.md).

---

## Núcleo e-CF (`src/Utils/FacturacionElectronica/`)

| Archivo | Responsabilidad |
|---|---|
| `ECFEmissionService.php` | orquesta emisión e-CF (build → firmar → enviar); flag `integration` |
| `ECFXmlBuilder.php` | construye XML e-CF (E31–E47), type-aware (`ID_DOC_CONFIG`/`TOTALES_CONFIG`) |
| `RFCEXmlBuilder.php` | construye RFCE (resumen E32 < 250k) |
| `ACECFXmlBuilder.php` / `ACECFEmissionService.php` | construye/envía ACECF (aprobación comercial saliente) |
| `DgiiXmlSigner.php` | firma XMLDSig con cert `.p12` (OpenSSL legacy) |
| `DgiiAuthService.php` | flujo semilla → token DGII |
| `DgiiReceptionService.php` | POST a los endpoints de recepción DGII |
| `IncomingXmlExtractor.php` / `IncomingXmlValidator.php` | extrae/valida XML entrante (firma) |

PDF / branding: `FacturaPdfGenerator.php` + `Utils/Pdf/` (plantillas por tenant). Ver
[modules/branding-plantillas.md](modules/branding-plantillas.md) y la norma de RI en
[business-rules/representacion-impresa.md](business-rules/representacion-impresa.md).
Webhooks de integración: `Utils/WebhookDispatcher.php` (HMAC-SHA256 + reintentos).

---

## Librerías vendorizadas (`vendor/`)

- **fpdf** — generación de PDF (facturas, cotizaciones).
- **phpqrcode** — códigos QR (timbre e-CF / URL de consulta DGII).

Sin Composer / autoloader — se incluyen con `require_once`.

---

## Variables de entorno clave

| Var | Para qué |
|---|---|
| `DB_HOST`/`DB_PORT`/`DB_NAME`/`DB_USER`/`DB_PASS` | conexión MySQL (single-tenant / fallback) |
| `MASTER_DB_*` + `MASTER_ENCRYPTION_KEY` | conexión master + clave de cifrado de credenciales |
| `MULTI_TENANT_ENABLED` | activa el ruteo multi-tenant |
| `DGII_ECF_ENVIRONMENT` | `testecf` / `certecf` / `ecf` (fallback de ambiente) |
| `DGII_ECF_CERT_PATH` / `DGII_ECF_CERT_PASSWORD` | cert `.p12` global de firma |
| `DGII_ECF_EMISSION_ENABLED` | guard de emisión real de gastos (off en prod) |
| `OPENSSL_CONF` / `OPENSSL_MODULES` | provider legacy de OpenSSL (compat `.p12`) |
| `CERT_RUN_TOKEN` | token del wizard de certificación |

Setup local: [setup.md](setup.md). Índice de toda la documentación: [README.md](README.md).
