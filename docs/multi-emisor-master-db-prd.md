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

| Tabla | Ubicación | Razón |
|---|---|---|
| `tenants` *(nueva)* | **master** | registro de clientes, credenciales cifradas, api_key de integración |
| `users` *(+ columna `tenant_id`)* | **master** | login resuelve tenant por email único global, sin pedir "código de empresa" |
| `api_tokens` *(+ `tenant_id`)* | **master** | token → tenant_id sin huevo-gallina; conserva FK a `users` |
| `landing_carousel`, `landing_services` | **master** | contenido de marketing global, no per-cliente |
| `facturas`, `factura_items`, `clients`, `cotizaciones`, `ncf_sequences`, `ecf_*`, `gastos`, `emisor_config` | **cada tenant** | datos de negocio aislados por empresa |

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

### Integración (JSON → XML)
```
POST /api/facturas   con X-API-KEY = api_key del tenant
  → master.tenants WHERE api_key = ? → tenant_id + credenciales
  → TenantResolver conecta → emite e-CF → devuelve XML
```

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
  api_key             VARCHAR(64)  NOT NULL UNIQUE,
  db_host             VARCHAR(100) NOT NULL DEFAULT 'localhost',
  db_name             VARCHAR(64)  NOT NULL UNIQUE,
  db_user             VARCHAR(64)  NOT NULL,
  db_pass_encrypted   VARBINARY(512) NOT NULL COMMENT 'AES-256-GCM: iv(12)||tag(16)||ct',
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
1. Crear master DB con `db/master_schema.sql`.
2. Mover `users` / `api_tokens` / `landing_*` actuales de `mtldtmte_new_gratexdb` a master,
   marcando `tenant_id = 1`.
3. Insertar Gratex en `master.tenants` apuntando a `mtldtmte_new_gratexdb` con credenciales cifradas.
4. Actualizar `AuthMiddleware` / `authModel` para usar master.
5. La DB de negocio de Gratex no se toca (sigue con facturas, ncf, ecf, etc.).

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
