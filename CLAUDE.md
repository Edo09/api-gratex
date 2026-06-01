# Gratex API — Context for AI Assistants

## Project

PHP REST API for electronic invoicing (e-CF) in the Dominican Republic.
Stack: PHP 8+, MySQL, Apache. No Composer. Single entry point `index.php` → `src/Router.php`.

## Status (2026-06-01)

**DGII e-CF certification complete.** System is live in `ecf` (production) ambiente.
Full details: `docs/dgii-certification.md`

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
