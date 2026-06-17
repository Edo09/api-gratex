# Setup — Entorno de desarrollo local

Cómo levantar la **Gratex API** en local. Stack: PHP 8+, MySQL, Apache, **sin Composer**
(las dependencias están vendorizadas en `vendor/`: fpdf, phpqrcode).

> La DB de producción (`mtldtmte_new_gratexdb`) **no es accesible localmente**. Para probar
> la lógica de DB en local hay que crear una DB con los esquemas de `db/`.

---

## Requisitos

- **PHP 8+** (CLI). Extensiones necesarias para los scripts e-CF: `zip`, `curl`, `openssl`,
  `mbstring`, `fileinfo`.
- **MySQL / MariaDB** (XAMPP en Windows sirve: incluye Apache, MySQL y phpMyAdmin).

### PHP en Windows sin `php.ini`

La instalación local del usuario no carga `php.ini`; las extensiones se pasan por flags `-d`.
Comando completo para correr scripts CLI (ej. los runners de certificación):

```powershell
& "C:\php\php.exe" -d "extension_dir=C:\php\ext" -d "extension=zip" `
  -d "extension=curl" -d "extension=openssl" -d "extension=mbstring" `
  -d "extension=fileinfo" "tools\<script>.php" <args>
```

El `.p12` de DGII está cifrado con AES-256/PBKDF2 y requiere el provider **legacy** de
OpenSSL 3.x (ver `config/openssl-legacy.cnf` y las vars `OPENSSL_CONF`/`OPENSSL_MODULES`).

---

## Configuración (`.env`)

El proyecto lee automáticamente el `.env` de la raíz. Hay plantilla en `.env.example`.

```env
# Conexión MySQL (single-tenant / fallback)
DB_HOST=localhost
DB_PORT=3306
DB_NAME=gratex_local
DB_USER=root
DB_PASS=

# Multi-tenant (opcional en local; dejar en false para single-tenant)
MULTI_TENANT_ENABLED=false
# MASTER_DB_HOST / MASTER_DB_NAME / MASTER_DB_USER / MASTER_DB_PASS
# MASTER_ENCRYPTION_KEY=<64 hex = 32 bytes; generar: php -r "echo bin2hex(random_bytes(32));">

# DGII e-CF
DGII_ECF_ENVIRONMENT=testecf            # testecf | certecf | ecf
DGII_ECF_CERT_PATH=certificados/<archivo>.p12
DGII_ECF_CERT_PASSWORD=<password>
OPENSSL_CONF=config/openssl-legacy.cnf
OPENSSL_MODULES=C:\php\extras\ssl       # carpeta con legacy.dll

# Guard de emisión real de gastos (dejar false fuera de pruebas)
DGII_ECF_EMISSION_ENABLED=false
```

`MULTI_TENANT_ENABLED=false` deja todo en una sola DB (comportamiento single-tenant);
con `true` el sistema enruta por el master DB (ver [architecture.md](architecture.md)).

---

## Base de datos

Crear la DB y aplicar el esquema del tenant (incluye base + migraciones 001–016):

```powershell
mysql -u root gratex_local < db/tenant_schema.sql
```

Para probar el modo multi-tenant en local también el master:

```powershell
mysql -u root < db/master_schema.sql      # crea gratex_master + tablas
```

Migraciones incrementales (solo para DBs ya desplegadas): `db/migrations/` (tenant) y
`db/master_migrations/` (master). Reglas: [../db/migrations/README.md](../db/migrations/README.md).

---

## Levantar el server

```powershell
php -S localhost:8000
```

Probar: `http://localhost:8000`. Las rutas viven bajo `/api/*` (ver
[architecture.md](architecture.md) para la tabla de rutas). Ejemplo:

```powershell
curl http://localhost:8000/api/clients -H "X-API-KEY: <token>"
```

Para obtener un token: `POST /api/auth/login` con `{ "emailOrUsername": "...", "password": "..." }`.

---

## Certificación DGII (local → server)

Los runners de certificación corren **localmente apuntando a `gratex.net`** (no necesitan la
DB local). Ver [../pasos_certificacion_dgii/README.md](../pasos_certificacion_dgii/README.md).

---

## Troubleshooting

| Síntoma | Causa probable |
|---|---|
| "Database connection failed" | MySQL apagado o creds erróneas en `.env` |
| "Port 8000 is already in use" | otro proceso en 8000 → usar `php -S localhost:8080` |
| OpenSSL no carga el `.p12` | falta el provider legacy → revisar `OPENSSL_CONF`/`OPENSSL_MODULES` |
| Extensión faltante en CLI | pasar `-d "extension=<nombre>"` (ver arriba) |
