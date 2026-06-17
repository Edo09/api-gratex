---
description: Initialize the local development environment for api-gratex
---

# Initialize Development Environment

Sets up the local environment for the **api-gratex** PHP REST API (DGII e-CF).

The full, maintained setup guide is **[docs/setup.md](../../docs/setup.md)** — follow it.
Architecture overview: **[docs/architecture.md](../../docs/architecture.md)**.

## Quick steps

### 1. Verify PHP (8+)

// turbo
```powershell
php --version
```

### 2. Configure `.env`

Copy `.env.example` → `.env` and fill DB creds + DGII cert vars (see docs/setup.md).
For local single-tenant: `MULTI_TENANT_ENABLED=false`.

### 3. Create the database

```powershell
mysql -u root gratex_local < db/tenant_schema.sql
```

### 4. Start the dev server

// turbo
```powershell
php -S localhost:8000
```

Test: `http://localhost:8000`. Routes live under `/api/*` (token via `X-API-KEY`).
Get a token with `POST /api/auth/login`. Endpoint reference: `docs/api/facturas.md`.
