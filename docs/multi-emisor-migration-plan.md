# Plan de migración a multi-emisor (DB-per-tenant)

Cada cliente tiene su propia base de datos MySQL con el esquema actual completo.
Un **master DB** (`gratex_master`) registra los tenants y mapea API keys y RNCs
a sus credenciales de conexión.

> **Estado:** plan aprobado — iniciar cuando sistema en producción sin issues ≥ 2 semanas.

---

## 1. Por qué DB-per-tenant

- Aislamiento total — imposible cross-tenant data leak por query sin filtro.
- Código existente no cambia — ningún `emisor_id` en ningún query.
- Backup/restore por cliente independiente.
- Schema migration se ejecuta una vez en script que itera todos los tenant DBs.
- Gratex (emisor actual) pasa a ser tenant #1 sin tocar su DB.

---

## 2. Arquitectura

```
Request (X-API-KEY)
       │
       ▼
AuthMiddleware
  → query gratex_master.tenants WHERE api_key = ?
  → decrypt db_pass
  → Database::setCredentials(host, name, user, pass)
       │
       ▼
  Código existente sin cambios
  (todos los queries van al DB del tenant)
```

Para endpoints DGII incoming (sin API key):
```
POST /api/ecf/recepcion
  → extraer RNCComprador del XML
  → query gratex_master.tenants WHERE rnc = ?
  → Database::setCredentials(...)
  → código existente sin cambios
```

---

## 3. Master DB

### 3.1 Schema

```sql
CREATE DATABASE IF NOT EXISTS gratex_master
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE gratex_master;

CREATE TABLE tenants (
  id                   INT AUTO_INCREMENT PRIMARY KEY,
  nombre               VARCHAR(100) NOT NULL,
  rnc                  VARCHAR(11)  NOT NULL UNIQUE,
  api_key              VARCHAR(64)  NOT NULL UNIQUE,
  db_host              VARCHAR(100) NOT NULL DEFAULT 'localhost',
  db_name              VARCHAR(64)  NOT NULL UNIQUE,
  db_user              VARCHAR(64)  NOT NULL,
  db_pass_encrypted    VARBINARY(512) NOT NULL
    COMMENT 'AES-256-GCM: iv(12) || tag(16) || ciphertext',
  cert_path            VARCHAR(255) NULL
    COMMENT 'Ruta relativa al project root del .p12',
  cert_pass_encrypted  VARBINARY(512) NULL
    COMMENT 'AES-256-GCM igual que db_pass_encrypted',
  ambiente             VARCHAR(20)  NOT NULL DEFAULT 'ecf',
  activo               TINYINT(1)  NOT NULL DEFAULT 1,
  created_at           DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 3.2 Variables de entorno (master)

Agregar a `.env`:
```
MASTER_DB_HOST=localhost
MASTER_DB_NAME=gratex_master
MASTER_DB_USER=master_user
MASTER_DB_PASS=...
MASTER_ENCRYPTION_KEY=<64 chars hex — 32 bytes AES-256>
```

Generar la master key una sola vez:
```php
echo bin2hex(random_bytes(32));
```

---

## 4. Nuevos archivos

### `src/MasterDatabase.php`
Singleton PDO al master DB. Lee `MASTER_DB_*` del `.env`.

```php
class MasterDatabase {
    public static function getInstance(): self { ... }
    public function getTenantByApiKey(string $key): ?array { ... }
    public function getTenantByRnc(string $rnc): ?array { ... }
}
```

### `src/TenantResolver.php`
Descifra credenciales y configura la conexión del tenant.

```php
class TenantResolver {
    public static function resolveByApiKey(string $apiKey): bool { ... }
    public static function resolveByRnc(string $rnc): bool { ... }
    private static function decrypt(string $encrypted): string { ... } // AES-256-GCM
}
```

---

## 5. Archivos modificados

### `src/Database.php`
Agregar soporte para credenciales dinámicas:

```php
class Database {
    private static ?self $instance = null;
    private static ?array $credentials = null;

    public static function setCredentials(array $creds): void {
        self::$credentials = $creds;
        self::$instance = null; // reset singleton para próxima conexión
    }

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $creds = self::$credentials ?? [
            'host' => getenv('DB_HOST') ?: 'localhost',
            'name' => getenv('DB_NAME'),
            'user' => getenv('DB_USER'),
            'pass' => getenv('DB_PASS'),
        ];
        $this->pdo = new PDO(
            "mysql:host={$creds['host']};dbname={$creds['name']};charset=utf8mb4",
            $creds['user'], $creds['pass'],
            [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
             PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }
}
```

### `src/Middleware/AuthMiddleware.php`
Después de validar el `X-API-KEY`:

```php
$tenant = MasterDatabase::getInstance()->getTenantByApiKey($apiKey);
if (!$tenant || !$tenant['activo']) {
    // unauthorized
}
TenantResolver::resolveByApiKey($apiKey); // sets Database credentials
```

### `src/Controllers/ecfRecepcionController.php`
Antes de `handleRecepcionEcf()`, resolver tenant por RNC:

```php
// Extraer RNCComprador del XML antes de procesar
$rncComprador = ...; // del XML
if (!TenantResolver::resolveByRnc($rncComprador)) {
    respondRecepcion(false, 'RNC no registrado en este sistema.', 404);
    return;
}
```

### `src/Controllers/ecfAprobacionComercialController.php`
Igual — resolver por `RNCEmisor` (somos el emisor que recibe la aprobación):

```php
if (!TenantResolver::resolveByRnc($rncEmisor)) {
    respondAprobacion(false, 'RNC no registrado.', 404);
    return;
}
```

### `src/Controllers/ecfAutenticacionController.php`
El flujo de auth no tiene API key ni RNC propio al inicio. Opciones:
- Resolver por `Host` header (si cada tenant tiene subdominio).
- O dejar un tenant "default" y resolver en `ValidacionCertificado` cuando se extrae el RNC del certificado firmante.

---

## 6. Cifrado de credenciales

```php
// Encrypt
function encryptCredential(string $plain): string {
    $key = hex2bin(getenv('MASTER_ENCRYPTION_KEY'));
    $iv  = random_bytes(12);
    $tag = '';
    $ct  = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    return $iv . $tag . $ct;
}

// Decrypt
function decryptCredential(string $blob): string {
    $key = hex2bin(getenv('MASTER_ENCRYPTION_KEY'));
    $iv  = substr($blob, 0, 12);
    $tag = substr($blob, 12, 16);
    $ct  = substr($blob, 28);
    return openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
}
```

---

## 7. Script de onboarding de nuevo tenant

`tools/create_tenant.php` (CLI):

1. Crear DB MySQL: `CREATE DATABASE {db_name}`
2. Ejecutar todas las migrations de `db/migrations/` en la nueva DB
3. Insertar registro en `emisor_config` con datos del cliente
4. Copiar cert `.p12` a `certificados/{rnc}/cert.p12`
5. Cifrar `db_pass` y `cert_pass` con `encryptCredential()`
6. Insertar fila en `gratex_master.tenants`
7. Imprimir `api_key` generado para entregar al cliente

---

## 8. Gratex como tenant #1

Al ejecutar la migración:
1. Crear master DB con schema.
2. Insertar Gratex en `tenants` apuntando al DB actual (`mtldtmte_new_gratexdb`).
3. Cifrar credenciales existentes.
4. Actualizar `AuthMiddleware` para usar `TenantResolver`.
5. Smoke test: emitir una factura normal — debe funcionar igual.

---

## 9. Estimación

| Fase | Estimado |
|---|---|
| Master DB schema + MasterDatabase.php | 0.5 día |
| Database.php setCredentials + TenantResolver | 0.5 día |
| AuthMiddleware integración | 0.5 día |
| DGII incoming resolver por RNC | 0.5 día |
| Onboarding script create_tenant.php | 0.5 día |
| Auth endpoint tenant resolution | 0.5 día |
| Smoke tests con 2 tenants | 0.5 día |
| **Total** | **~3.5 días** |

---

## 10. Riesgos

| Riesgo | Mitigación |
|---|---|
| Master DB down = todo el sistema down | Master DB en mismo servidor — misma disponibilidad que app DB |
| Credenciales master DB comprometidas = acceso a todos los tenants | Permisos restrictivos, IP whitelist, monitoreo |
| `Database::setCredentials()` no llamado = queries van al DB equivocado | Assert en `getInstance()` si credentials null en modo multi-tenant |
| Auth endpoint (sin API key) resuelve al tenant incorrecto | Resolver por RNC del certificado en ValidacionCertificado, no en semilla |

---

## 11. Lo que NO cambia

- Todos los models (`facturaModel`, `ncfModel`, `clientModel`, etc.)
- Todos los controllers de emisión
- `ECFEmissionService`, `DgiiAuthService`, `DgiiReceptionService`
- Schema de los tenant DBs (mismo que hoy)
- Lógica de e-CF, firma, QR, RFCE
