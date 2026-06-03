# Gratex API — Backend Structure

PHP REST API for Dominican Republic electronic invoicing (e-CF / DGII).

- **Stack:** PHP 8+, MySQL (PDO), Apache. No Composer (vendored libs).
- **Entry point:** `index.php` → `src/Router.php`
- **Pattern:** front controller + manual routing → Controllers → Models → `Database` (PDO singleton)
- **Auth:** `X-API-KEY` header (our clients) or `Authorization: Bearer` (DGII flow)

---

## Request Flow

```
Apache (.htaccess) → index.php → src/Router.php
  → CORS + OPTIONS preflight handled
  → parse endpoint (first /api/ occurrence)
  → switch on route segment[0]
  → require Controller
      → AuthMiddleware->validateRequest()   (token routes)
      → Model (PDO queries)
      → Utils (XML build, sign, PDF, email)
  → JSON / XML / PDF response
```

Routing note: uses `strpos` on the **first** `/api/` occurrence — DGII callback URLs contain a double `/api/` segment.

---

## Directory Layout

```
api-gratex/
├── index.php                  # entry point → includes Router
├── .htaccess                  # Apache rewrite (no <If> directive — server unsupported)
├── .env / .env.example        # config (DB creds, DGII cert path/password, ambiente)
├── CLAUDE.md                  # AI assistant context
│
├── src/
│   ├── Router.php             # route dispatch (switch on URL segment)
│   ├── Database.php           # PDO singleton + .env loader
│   │
│   ├── Middleware/
│   │   └── AuthMiddleware.php # X-API-KEY / Bearer token validation
│   │
│   ├── Controllers/           # HTTP handlers (one per route group)
│   ├── Models/                # DB access (PDO)
│   └── Utils/                 # services (PDF, email, e-CF engine)
│       └── FacturacionElectronica/   # DGII e-CF core
│
├── config/openssl-legacy.cnf  # legacy OpenSSL for p12 cert signing
├── db/                        # schema + migrations
├── docs/                      # API docs, payloads, architecture
├── tests/                     # .http request files
├── tools/                     # DGII certification scripts + artifacts
├── pasos_certificacion_dgii/  # cert phase runners (fase2/3/4)
├── samples/                   # DGII XSD schemas + sample XLSX
└── vendor/                    # fpdf (PDF), phpqrcode (QR) — manually vendored
```

---

## Routes (`src/Router.php`)

| Route segment | Controller | Auth | Purpose |
|---|---|---|---|
| `auth` | `authController.php` | none | token generation / management |
| `users` | `userController.php` | token | user CRUD |
| `clients` | `clientController.php` | token | client CRUD |
| `cotizaciones` | `cotizacionController.php` | token | quotes CRUD + PDF |
| `facturas` | `facturaController.php` | token | invoice (e-CF) CRUD |
| `facturas-simples` | `facturaSimpleController.php` | token | non-electronic invoices |
| `ncf` | `ncfController.php` | token | NCF sequence management |
| `facturacion-electronica` | `facturacionElectronicaController.php` | token | DGII e-CF emission |
| `aprobaciones-comerciales` | `aprobacionComercialOutgoingController.php` | token | send ACECF (we approve as buyer) |
| `ecf/recepcion` | `ecfRecepcionController.php` | Bearer (DGII) | incoming e-CF from emisores |
| `ecf/aprobacion-comercial` | `ecfAprobacionComercialController.php` | Bearer (DGII) | incoming commercial approvals |
| `ecf/autenticacion` | `ecfAutenticacionController.php` | Bearer (DGII) | seed/validate auth flow |
| `landing` | `landingController.php` | token | landing page config |
| `/`, `/docs`, `/api/docs` | — | none | serves `public/docs.html` |

`/api/ecf/*` dispatches on segment[1] (`recepcion` / `aprobacion-comercial` / `autenticacion`).

---

## Controllers (`src/Controllers/`)

| File | Responsibility |
|---|---|
| `authController.php` | API token issue/manage |
| `userController.php` | users CRUD |
| `clientController.php` | clients CRUD |
| `cotizacionController.php` | quotes CRUD + PDF preview |
| `facturaController.php` | electronic invoice CRUD |
| `facturaSimpleController.php` | non-e-CF invoices |
| `ncfController.php` | NCF sequences |
| `facturacionElectronicaController.php` | e-CF emission to DGII |
| `aprobacionComercialOutgoingController.php` | outgoing ACECF (buyer role) |
| `ecfRecepcionController.php` | incoming e-CF receiver (DGII) |
| `ecfAprobacionComercialController.php` | incoming commercial approval (DGII) |
| `ecfAutenticacionController.php` | DGII semilla/auth token flow |
| `landingController.php` | landing config |

## Models (`src/Models/`)

PDO data access, one per domain:
`authModel`, `authSeedModel`, `userModel`, `clientModel`, `cotizacionModel`,
`facturaModel`, `ncfModel`, `aprobacionComercialModel`, `ecfRecibidoModel`,
`EmisorConfigModel`, `LandingModel`.

## Utils (`src/Utils/`)

| File | Purpose |
|---|---|
| `FacturaPdfGenerator.php` | invoice PDF (fpdf) |
| `CotizacionPdfGenerator.php` | quote PDF |
| `TokenGenerator.php` | API token gen |
| `WelcomeEmailService.php` | onboarding email |

### `Utils/FacturacionElectronica/` — DGII e-CF core

| File | Purpose |
|---|---|
| `ECFEmissionService.php` | orchestrates e-CF emission (build → sign → send) |
| `ECFXmlBuilder.php` | builds e-CF XML (E31–E47) |
| `RFCEXmlBuilder.php` | builds RFCE (resumen, < threshold) XML |
| `ACECFXmlBuilder.php` | builds ACECF (commercial approval) XML |
| `ACECFEmissionService.php` | sends ACECF to DGII |
| `DgiiXmlSigner.php` | XML digital signature (p12 cert, legacy OpenSSL) |
| `DgiiAuthService.php` | DGII semilla → token auth flow |
| `DgiiReceptionService.php` | submits XML to DGII reception endpoints |
| `IncomingXmlExtractor.php` | parse incoming DGII XML |
| `IncomingXmlValidator.php` | validate incoming XML vs XSD |

---

## Data Layer

- **`Database.php`** — PDO singleton. Loads `.env`, connects MySQL (`utf8mb4`, exceptions on, assoc fetch). Defaults to server creds if `.env` missing.
- **DB name:** `mtldtmte_new_gratexdb` (server). NOT the old `mtldtmte_gratexdb`.
- **Schema:** `db/database.sql`
- **Migrations** (`db/migrations/`):
  - `001_add_ecf_module.sql`
  - `002_add_ecf_reception.sql`
  - `003_add_rfce_tracking.sql`
  - `004_fase2_setup.sql`
  - `005_add_secuencia_utilizada.sql`
  - `006_add_nota_referencia.sql`
- NCF sequences are **per-ambiente** (`tools/migration_ncf_ambiente.sql`).

---

## Configuration (`.env`)

| Var | Purpose |
|---|---|
| `DB_HOST` / `DB_PORT` / `DB_NAME` / `DB_USER` / `DB_PASS` | MySQL connection |
| `DGII_ECF_ENVIRONMENT` | `testecf` (test) / `ecf` (production) — filters test data in prod |
| `DGII_ECF_CERT_PATH` | path to `.p12` signing certificate |
| `DGII_ECF_CERT_PASSWORD` | cert password |
| `OPENSSL_CONF` | legacy OpenSSL config (p12 compat) |
| `OPENSSL_MODULES` | OpenSSL legacy provider path |

---

## Auth (`AuthMiddleware`)

- Reads token from `X-API-KEY` header, else `Authorization: Bearer <token>`.
- Validates via `authModel->validateToken()` (sha256 hash lookup), updates `last_used`.
- `sendUnauthorized()` → 401 JSON on fail.
- DGII incoming routes (`/api/ecf/*`) use Bearer token from DGII auth flow.

---

## Vendored Libraries (`vendor/`)

- **fpdf** — PDF generation (invoices, quotes)
- **phpqrcode** — QR codes (e-CF timbre / DGII consulta URL)

No Composer / autoloader — files included via `require_once`.

---

## DGII Certification

- Status: **complete** (2026-06-01), live in `ecf` production ambiente.
- Details: `docs/dgii-certification.md`, `docs/arquitectura-sistema.md`.
- Phase runners: `pasos_certificacion_dgii/` (fase2 set pruebas, fase3 aprobación comercial, fase4 emisión).
- XSD schemas + samples: `samples/`.
