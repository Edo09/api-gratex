# PRD — Master DB y multi-emisor (DB-per-tenant)

> **Estado:** plan aprobado para diseño. Pendiente implementación.
> Documento portable (en repo) para continuar en otra computadora.
> Relacionado: [multi-emisor-migration-plan.md](multi-emisor-migration-plan.md) · [multi-emisor-db-diagram.md](multi-emisor-db-diagram.md)

---

## 1. Contexto y problema

El software de facturación e-CF se vende a múltiples empresas. Cada empresa será un
**tenant** con su propia base de datos MySQL (esquema actual completo). Falta la pieza
central que enrute cada petición a la DB correcta: la **master DB**.

**Sin master DB no hay multi-emisor.** Es el directorio que mapea "quién entra" → "a qué
DB conectar".

### Dos modos de producto (ambos enrutan por master)
1. **App** — la empresa usa la app de facturación desarrollada por nosotros → login de
   persona (email/password).
2. **Integración** — la empresa usa su propio sistema → envía JSON con datos de factura →
   se le devuelve el e-CF en XML. Autenticación por API key de máquina.

### Restricciones confirmadas
- Hosting (HostGator) **permite crear DBs automatizado** → DB-per-tenant es viable.
- Stack actual: PHP 8+, MySQL, Apache, sin Composer. Entry point `index.php` → `src/Router.php`.

---

## 2. Decisión de arquitectura: qué va en master vs en cada tenant

El plan original ([multi-emisor-migration-plan.md](multi-emisor-migration-plan.md))
asumía que `users`/`api_tokens` quedaban dentro de cada tenant. Eso **rompe el modo App**:
para validar un login primero hay que saber a qué DB mirar (problema huevo-gallina).

**Decisión: centralizar auth/routing en master; solo datos de negocio por tenant.**

### Dos tipos de tenant (`tenants.tipo`)
- **`app`** — usa la app de facturación nuestra. **DB propia** (DB-per-tenant): guarda
  facturas, clientes, cotizaciones, NCF, e-CF, gastos, etc. Onboarding crea su DB.
- **`integracion`** — usa su propio sistema, manda JSON con el e-CF (incluye el **eNCF**
  ya asignado por el cliente) y recibe el XML firmado. **NO tiene DB propia**: solo
  guardamos el e-CF generado en `gratex_master.ecf_integracion_backup` como respaldo.
  No se crea DB ni se manejan secuencias de nuestro lado.

| Tabla | Ubicación | Razón |
|---|---|---|
| `tenants` *(nueva)* | **master** | registro de clientes, `tipo`, key+secret, cert, credenciales DB (solo app) |
| `users` *(+ `tenant_id`)* | **master** | login resuelve tenant por email único global (solo relevante a `app`) |
| `api_tokens` *(+ `tenant_id`)* | **master** | token → tenant_id sin huevo-gallina; conserva FK a `users` |
| `landing_carousel`, `landing_services` | **master** | contenido de marketing global, no per-cliente |
| `auth_seeds`, `auth_tokens_emitidos` | **master** | auth DGII entrante (global, antes de resolver tenant) |
| `ecf_integracion_backup` *(nueva)* | **master** | respaldo de e-CF de tenants `integracion` (sin DB propia) |
| `facturas`, `factura_items`, `clients`, `cotizaciones`, `ncf_sequences`, `ecf_*`, `gastos`, `emisor_config` | **cada tenant `app`** | datos de negocio aislados por empresa |

### Notas de integridad referencial (verificado en `db/database.sql`)
- `facturas.user_id` hoy es **int suelto sin FK** → al quedar `users` en master se guarda
  como int plano referenciando `master.users.id`. MySQL no permite FK cross-DB, pero aquí
  no existía FK, así que **no se rompe nada**.
- `api_tokens.user_id` **sí tiene FK** a `users` (`ON DELETE CASCADE`) → por eso ambas
  tablas viajan juntas a master.
- Emails de `users` deben ser **únicos globalmente** (no solo por tenant) para que el login
  resuelva sin ambigüedad. Validar al migrar y en `registerUser`.

---

## 3. Flujos

### App (login de persona)
```
POST /api/login {email, password}
  → master.users WHERE email = ?  → verify password → tenant_id
  → master.api_tokens INSERT (token_hash, user_id, tenant_id)
  → devuelve token
Cada request posterior: X-API-KEY / Authorization: Bearer <token>
  → master.api_tokens WHERE token_hash = ? → tenant_id
  → TenantResolver conecta a la DB del tenant
  → controllers existentes corren sin cambios
```

### Integración (JSON → XML) — Método A: api_key + api_secret
```
POST /api/facturas
  Headers:  X-API-KEY: <api_key>   X-API-SECRET: <api_secret>
  → master.tenants WHERE api_key = ?  → verifica hash_equals(sha256(secret), api_secret_hash)
  → tenant_id + credenciales → TenantResolver conecta → emite e-CF → devuelve XML
```
- `api_key` = identificador público (UNIQUE en `tenants`).
- `api_secret` = se guarda solo su **sha256** (`api_secret_hash`); el secret en claro se
  muestra UNA vez al crear el tenant. Se compara en tiempo constante (`hash_equals`).
- Un par key/secret por empresa/cliente.

### DGII incoming (sin API key)
```
POST /api/ecf/recepcion
  → extraer RNCComprador del XML
  → master.tenants WHERE rnc = ? → conectar
  → ecfRecepcionController corre sin cambios
```
(igual para `ecfAprobacionComercialController`, resolviendo por `RNCEmisor`.)

---

## 4. Master DB — schema

```sql
CREATE DATABASE IF NOT EXISTS gratex_master
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE gratex_master;

CREATE TABLE tenants (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  nombre              VARCHAR(100) NOT NULL,
  rnc                 VARCHAR(11)  NOT NULL UNIQUE,
  api_key             VARCHAR(64)  NOT NULL UNIQUE,  -- identificador publico
  api_secret_hash     VARCHAR(64)  NOT NULL,         -- sha256 del api_secret
  tipo                VARCHAR(12)  NOT NULL DEFAULT 'app',  -- app | integracion
  -- db_* solo aplican a tipo app; en integracion son NULL
  db_host             VARCHAR(100) NULL DEFAULT 'localhost',
  db_name             VARCHAR(64)  NULL UNIQUE,
  db_user             VARCHAR(64)  NULL,
  db_pass_encrypted   VARBINARY(512) NULL COMMENT 'AES-256-GCM: iv(12)||tag(16)||ct',
  cert_path           VARCHAR(255) NULL,
  cert_pass_encrypted VARBINARY(512) NULL,
  ambiente            VARCHAR(20)  NOT NULL DEFAULT 'ecf',
  activo              TINYINT(1)   NOT NULL DEFAULT 1,
  created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- users y api_tokens migran aquí desde el DB actual, con columna tenant_id añadida.
-- users.email debe ser UNIQUE global.
-- landing_carousel / landing_services también viven aquí.
```

### Variables `.env` (master)
```
MASTER_DB_HOST=localhost
MASTER_DB_NAME=gratex_master
MASTER_DB_USER=master_user
MASTER_DB_PASS=...
MASTER_ENCRYPTION_KEY=<64 hex chars = 32 bytes AES-256, generar UNA sola vez>
```
Generar la key: `php -r "echo bin2hex(random_bytes(32));"`

---

## 5. Archivos

### Nuevos
- **`src/MasterDatabase.php`** — singleton PDO al master (lee `MASTER_DB_*`).
  Métodos: `getTenantByApiKey()`, `getTenantByRnc()`, `getTenantById()`,
  `validateUserToken()` (token_hash → user_id + tenant_id), `loginUser()`.
- **`src/TenantResolver.php`** — `resolveByApiKey()`, `resolveByRnc()`, `resolveById()`,
  `decrypt()` (AES-256-GCM). Llama `Database::setCredentials()`.
- **`db/master_schema.sql`** — crea master DB + tablas; incluye migración de
  `users`/`api_tokens`/`landing_*` desde el DB de Gratex.
- **`tools/create_tenant.php`** (CLI onboarding):
  1. `CREATE DATABASE {db_name}`
  2. correr migrations `db/migrations/001..010` en la DB nueva
  3. insertar `emisor_config` con datos del cliente
  4. copiar cert `.p12` a `certificados/{rnc}/cert.p12`
  5. cifrar `db_pass` y `cert_pass`
  6. `INSERT` en `gratex_master.tenants`
  7. imprimir `api_key` generado para entregar al cliente

### Modificados
- **`src/Database.php`** — agregar `setCredentials(array $creds)` que resetea el singleton;
  el constructor usa esas credenciales si existen, si no cae al `.env`.
  ⚠️ La propiedad real es **`$conexion`** (no `$pdo`); método `getConnection()`.
- **`src/Middleware/AuthMiddleware.php`** — tras extraer el token: validar contra master
  (`validateUserToken`), obtener `tenant_id`, llamar `TenantResolver::resolveById()` antes
  de que cualquier controller toque la DB.
- **`src/Models/authModel.php`** — `loginUser`, `registerUser`, `validateToken`,
  `createToken`, `updateLastUsed` pasan a consultar **master**, no la conexión de tenant.
- **`src/Controllers/ecfRecepcionController.php`** y
  **`src/Controllers/ecfAprobacionComercialController.php`** — resolver tenant por RNC
  antes de procesar.
- **`.env`** — agregar las variables master de la sección 4.

---

## 6. Cifrado de credenciales (AES-256-GCM)
```php
function encryptCredential(string $plain): string {
    $key = hex2bin(getenv('MASTER_ENCRYPTION_KEY'));
    $iv  = random_bytes(12);
    $tag = '';
    $ct  = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    return $iv . $tag . $ct;             // iv(12) || tag(16) || ciphertext
}
function decryptCredential(string $blob): string {
    $key = hex2bin(getenv('MASTER_ENCRYPTION_KEY'));
    $iv  = substr($blob, 0, 12);
    $tag = substr($blob, 12, 16);
    $ct  = substr($blob, 28);
    return openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
}
```

---

## 7. Gratex como tenant #1

**Gratex es `tipo=app`**: conserva su DB actual (`mtldtmte_new_gratexdb`) y su flujo
certificado intactos. El modo `integracion` (sin DB) es para clientes nuevos.

1. Crear master DB con `db/master_schema.sql`.
2. Mover `users` / `api_tokens` / `landing_*` actuales de `mtldtmte_new_gratexdb` a master,
   marcando `tenant_id = 1` (para que cualquier login/token existente siga funcionando;
   `AuthMiddleware` valida el token de sesión contra master).
3. Insertar Gratex en `master.tenants` con `tipo='app'` apuntando a `mtldtmte_new_gratexdb`
   con credenciales cifradas. (Puede tener también key+secret si se quiere usar integración,
   pero su uso principal es la app.)
4. Actualizar `AuthMiddleware` / `authModel` para usar master.
5. La DB de negocio de Gratex no se toca (sigue con facturas, ncf, ecf, etc.).
6. Cert: el cert global del `.env` (`DGII_ECF_CERT_PATH`) YA es el de Gratex, así que la
   firma de Gratex funciona sin cambios. El cert-por-tenant (ver sección 11, Pendientes)
   solo bloquea al tenant #2.

---

## 8. Riesgos y mitigaciones

| Riesgo | Mitigación |
|---|---|
| `Database::setCredentials()` no invocado → queries al DB equivocado | En modo multi-tenant, lanzar excepción en `getInstance()` si no hay credenciales resueltas |
| Master DB caída = todo caído | Master en mismo servidor (misma disponibilidad que app) |
| Credenciales master comprometidas = acceso a todos los tenants | Usuario MySQL restringido; `MASTER_ENCRYPTION_KEY` solo en `.env` del server; monitoreo |
| Emails duplicados entre tenants rompen login | `users.email` UNIQUE global; validar en migración y `registerUser` |
| Auth endpoint DGII (sin API key) resuelve tenant incorrecto | Resolver por RNC del certificado firmante en `ValidacionCertificado` |

---

## 9. Verificación (end-to-end)
1. **Regresión Gratex:** login con usuario existente → emitir factura normal → idéntico a hoy.
2. **Segundo tenant:** `tools/create_tenant.php` para cliente de prueba → verificar DB nueva
   con las 10 migrations → login del usuario del tenant → emitir factura → confirmar que se
   guarda en la DB del tenant, NO en la de Gratex.
3. **Integración:** `curl -X POST /api/facturas -H "X-API-KEY: <key prueba>" -d '{...}'` →
   recibir XML e-CF → confirmar persistencia en la DB correcta.
4. **Aislamiento:** con token/key del tenant prueba, `GET /api/facturas` no debe devolver
   facturas de Gratex.
5. **DGII incoming:** simular recepción con RNCComprador del tenant prueba → confirmar que
   resuelve y guarda en su DB.

---

## 10. Estimación

| Fase | Estimado |
|---|---|
| Master DB schema + `MasterDatabase.php` + migración users/api_tokens | 1 día |
| `Database.php setCredentials` + `TenantResolver` | 0.5 día |
| `AuthMiddleware` + `authModel` → master | 1 día |
| DGII incoming resolver por RNC | 0.5 día |
| `create_tenant.php` onboarding | 0.5 día |
| Smoke tests 2 tenants (app + integración) | 0.5 día |
| **Total** | **~4 días** |

---

## 11. Pendientes antes de prender `MULTI_TENANT_ENABLED`

Lo implementado está **gated**: con el flag en `false` el comportamiento es idéntico a hoy.

1. ✅ **Firma e-CF por tenant (HECHO).** `CertResolver::resolve()` devuelve el cert del
   tenant resuelto (`tenants.cert_path`/`cert_pass_encrypted`) o cae al cert global del
   `.env`. Cableado en `ECFEmissionService`, `ACECFEmissionService` y la firma de acuses
   (`buildSignedAECF`/`buildSignedARCF`). La semilla de auth DGII también usa ese cert
   (vía `certificate_content` en `autenticar()`). Backward-compatible: sin tenant → cert env.

2. ✅ **`ecfAutenticacionController` (flujo semilla DGII) (HECHO/verificado).** Es
   mt-safe sin cambios: solo usa `authSeedModel` (master-aware en mt-mode) y validación
   cripto del XML; NO toca `Database` ni firma con cert (la semilla DGII se emite sin
   firmar — el cliente la firma con SU cert y nosotros validamos su firma). El token
   emitido es global (HMAC `ECF_AUTH_TOKEN_SECRET`); el tenant se resuelve luego por
   RNCComprador/RNCEmisor del e-CF.

3. ✅ **Funcionalidad de integración (HECHO).** Ver sección 12 — emisión, recepción,
   aprobación entrante/saliente, consulta y webhook implementados y gated.

4. ✅ **2do tenant tipo `app` con DB distinta (HECHO — pre-resolución en Router).**
   Los controllers instancian sus models en el tope del archivo (antes de su propio
   `validateRequest`) y el model fija la conexión en el constructor. Fix: `Router.php`
   **pre-resuelve el tenant** (best-effort `AuthMiddleware::validateRequest()`) ANTES de
   incluir el controller, así `Database::setCredentials()` ya está puesto cuando el model
   se construye → queda atado a la DB del tenant correcto. Public endpoints sin token no
   resuelven nada (y `LandingModel` lee de master). Gratex e integración no se ven afectados.
   - Si DGII manda `Authorization: Bearer` a `/api/ecf/*`, la pre-resolución no encuentra
     token de sesión y no resuelve (el controller DGII resuelve por RNC). Sin conflicto.

### Infra ya lista para estas piezas
- `tenants.tipo`, `cert_path`/`cert_pass_encrypted`, `webhook_url`/`webhook_secret_encrypted`.
- Master: `ecf_integracion_backup`, `ecf_recibidos`(+tenant_id), `aprobaciones_comerciales`(+tenant_id).
- `TenantResolver::resolveByCredentials()` / `isIntegration()` / `current()`.
- `MasterDatabase::saveIntegrationEcf()`.

### Orden de activación
1. Correr `db/master_schema.sql` en server → migrar `users`/`api_tokens`/`landing_*` a master
   (tenant_id=1) → registrar Gratex en `tenants` (`tipo=app`).
2. Llenar `.env` (`MASTER_DB_*` + `MASTER_ENCRYPTION_KEY`).
3. Piezas #1, #2, #3 ya implementadas (gated).
4. Probar en staging con 2 tenants (1 app + 1 integración) en testecf/certecf.
5. Recién ahí poner `MULTI_TENANT_ENABLED=true` en producción.

---

## 12. Integración — alcance completo (4 roles DGII, sin DB propia)

El tenant `integracion` usa su propio sistema; nosotros somos su motor fiscal. Necesita
los cuatro roles DGII. Todo se **firma con el cert del tenant** (pieza #1) y se **guarda
en master** (tablas espejo + tenant_id). El cliente nunca toca una DB nuestra.

### Flujos
| # | Rol | Dirección | Endpoint | Persistencia (master) |
|---|---|---|---|---|
| 1 | Emitir e-CF | saliente | `POST /api/integracion/ecf` | `ecf_integracion_backup` |
| 2 | Recibir e-CF + acuse (ARECF) | entrante | `POST /api/ecf/recepcion` (ya resuelve por RNCComprador) | `ecf_recibidos` |
| 3 | Recibir aprobación comercial | entrante | `POST /api/ecf/aprobacion-comercial` (ya resuelve por RNCEmisor) | `aprobaciones_comerciales` |
| 4 | Aprobar/rechazar e-CF recibido (ACECF) | saliente | `POST /api/integracion/aprobacion-comercial` | `aprobaciones_comerciales` |

### Consulta (entrega de documentos entrantes) — polling + webhook
- **Polling:** `GET /api/integracion/recibidos` y `GET /api/integracion/aprobaciones`
  (auth key+secret, filtra por `tenant_id`).
- **Webhook:** si `tenants.webhook_url` está definido, al recibir un documento (flujo 2/3)
  se hace POST a esa URL con el payload, firmado HMAC con `webhook_secret` para que el
  cliente verifique autenticidad. Con reintentos ante fallo.

### Emisión (flujo 1) — detalle
```
POST /api/integracion/ecf   (X-API-KEY + X-API-SECRET)
  → AuthMiddleware resuelve tenant (isIntegration())
  → valida RNCEmisor(JSON) == tenant.rnc
  → JSON trae el e-CF completo INCLUYENDO eNCF (no generamos secuencia)
  → construir XML + firmar con cert del tenant (reusar ECFEmissionService/DgiiXmlSigner)
  → enviar a DGII (flujo certificado) → devolver XML firmado + estado
  → MasterDatabase::saveIntegrationEcf()
```

### Implementado (gated por `MULTI_TENANT_ENABLED`)
- ✅ `src/CertResolver.php` — cert por tenant o fallback env.
- ✅ `src/Models/IntegracionStoreModel.php` — store en master (ecf_recibidos / aprobaciones /
  backup) por `tenant_id`.
- ✅ `src/Utils/WebhookDispatcher.php` — push HMAC-SHA256 + reintentos, tras `fastcgi_finish_request`.
- ✅ `ECFEmissionService::emitir()` — flag `integration`: emisor desde payload, e_ncf del
  cliente, sin DB/secuencia.
- ✅ `ACECFEmissionService::enviar()` — flag `integration`: comprador = tenant, cert tenant.
- ✅ Controllers entrantes (`ecfRecepcion`, `ecfAprobacionComercial`): branch
  `isIntegration()` → guardan en master + firman con cert tenant + webhook.
- ✅ Nuevos: `integracionEcfController` (POST emisión), `integracionAprobacionController`
  (POST ACECF saliente), `integracionConsultaController` (GET recibidos/aprobaciones).
- ✅ Router: `case 'integracion'` con sub-rutas.
- ✅ `create_tenant.php`: `--tipo`, `--webhook-url`, `--webhook-secret`.

### Riesgo / por probar
Toca la ruta de firma/emisión **certificada**. No testeable localmente (sin DB ni acceso
DGII). Probar en testecf/certecf con un tenant integración de prueba antes de producción.
Pendiente: `ecfAutenticacionController` en mt-mode (sección 11 #2).
