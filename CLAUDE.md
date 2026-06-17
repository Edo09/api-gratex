# Gratex API — Context for AI Assistants

## Project

PHP REST API for electronic invoicing (e-CF) in the Dominican Republic.
Stack: PHP 8+, MySQL, Apache. No Composer. Single entry point `index.php` → `src/Router.php`.

## Status

**DGII e-CF certification complete** (2026-06-01) — Gratex live in `ecf` production.
**Multi-tenant (DB-per-tenant) live in production** since 2026-06-08 — Gratex is tenant #1;
new tenants onboard as `app` (own DB) or `integracion` (JSON→XML, no DB). Gated by
`MULTI_TENANT_ENABLED`.

Shipped modules beyond core emission: gastos, reportes 606/607, products, proveedores,
unidades-medida, branding/plantillas PDF, integración, roles/permisos (RBAC, gated by
`PERMISSIONS_ENFORCE`; see `docs/modules/roles-permisos.md`).

**Docs:** start at `docs/README.md`. Architecture: `docs/architecture.md`. DGII flows:
`docs/integrations/dgii-ecf.md`. Multi-tenant onboarding: `docs/integrations/multi-tenant-onboarding.md`.

## Critical facts

- `DGII_ECF_ENVIRONMENT=ecf` on server → filters certecf test data from all list/stats endpoints
- NCF sequences are per-ambiente (migration `tools/migration_ncf_ambiente.sql` already run on server)
- DB: `mtldtmte_new_gratexdb` on server (NOT `mtldtmte_gratexdb` which is old)
- Server path: `/home1/mtldtmte/public_html/api/`
- PHP error log: `/home1/mtldtmte/public_html/api/error_log`

## Key architecture

- All `/api/*` → `index.php` → `src/Router.php` (uses `strpos` on FIRST `/api/` occurrence — important for DGII double-`/api/` callback URLs)
- Auth: `X-API-KEY` header for our own clients; Bearer token (from DGII auth flow) for DGII incoming calls
- e-CF emission: `src/Utils/FacturacionElectronica/ECFEmissionService.php`
- Incoming e-CF: `src/Controllers/ecfRecepcionController.php` + `ecfAprobacionComercialController.php` + `ecfAutenticacionController.php`

## DO NOT

- Do not use `<If>` directive in `.htaccess` — server does not support it (breaks all routes)
- Do not wrap DGII auth token response in `{"status":true,"data":{...}}` — must be flat `{"token":"...","expira":"...","expedido":"..."}`
- Do not use `end(explode('/api/', $endpoint))` for routing — DGII URLs have two `/api/` segments
