# DGII e-CF Certification — Complete Reference

Certification completed: **2026-06-01**. All phases passed. System live in `ecf` production ambiente.

---

## Production service URLs (registered with DGII)

| Service | Base URL |
|---|---|
| Autenticación | `https://gratex.net/api/ecf/autenticacion` |
| Recepción | `https://gratex.net/api/ecf/recepcion` |
| Aprobación Comercial | `https://gratex.net/api/ecf/aprobacion-comercial` |

DGII appends fixed suffixes when calling us:
- Auth: `/fe/autenticacion/api/semilla` (GET) and `/fe/autenticacion/api/ValidacionCertificado` (POST)
- Recepción: `/fe/recepcion/api/ecf` (POST)
- Aprobación: `/fe/aprobacioncomercial/api/ecf` (POST)

---

## Incoming e-CF flow (DGII → us)

1. `GET .../semilla` → return XML seed, store in `auth_seeds`
2. `POST .../ValidacionCertificado` with signed seed XML → validate signature, return flat JSON token
3. `POST .../fe/recepcion/api/ecf` with Bearer + signed ECF → validate, store in `ecf_recibidos`, return signed `ARECF` XML
4. `POST .../fe/aprobacioncomercial/api/ecf` with Bearer + signed ACECF → validate, store in `aprobaciones_comerciales`, return signed `ARECF` XML

---

## ARECF acknowledgment format

Required response for both recepción and aprobación comercial. Must be signed with our certificate.

```xml
<?xml version="1.0" encoding="UTF-8"?>
<ARECF>
  <DetalleAcusedeRecibo>
    <Version>1.0</Version>
    <RNCEmisor>{rncEmisor from incoming XML}</RNCEmisor>
    <RNCComprador>{our RNC from emisor_config}</RNCComprador>
    <eNCF>{eNCF from incoming XML}</eNCF>
    <Estado>0</Estado>
    <FechaHoraAcuseRecibo>dd-MM-YYYY HH:mm:ss</FechaHoraAcuseRecibo>
  </DetalleAcusedeRecibo>
  <Signature>...</Signature>
</ARECF>
```

- `Estado`: 0=Recibido, 1=NoRecibido
- `DetalleAcusedeRecibo` — lowercase `d` in `de` (exact casing required by XSD)
- XSD: `samples/ARECF v1.0.xsd`

---

## Auth token response format

DGII reads `response['token']` directly. Must be flat JSON — NOT wrapped:

```json
{"token": "...", "expira": "2026-06-01T16:00:00", "expedido": "2026-06-01T15:00:00"}
```

---

## Critical bugs fixed during certification

### Router — double `/api/` in DGII callback URLs
DGII appends `/fe/.../api/ecf` to our base URL, creating two `/api/` segments.
Old: `end(explode('/api/', $endpoint))` → grabbed last segment → 404.
Fix: `strpos($endpoint, '/api/')` → first occurrence only. File: `src/Router.php`.

### IncomingXmlValidator — digest mismatch
Was cloning root element into new DOMDocument for C14N. importNode changes namespace context → digest mismatch.
Fix: remove Signature from original document, C14N root, reattach Signature. File: `src/Utils/FacturacionElectronica/IncomingXmlValidator.php`.

### .htaccess — `<If>` directive not supported
`SecRuleEngine Off` inside `<If>` breaks Apache on this shared host — returns 500 for all requests.
Never use `<If>` in `.htaccess`. ModSecurity was not actually the issue.

---

## Outgoing e-CF (us → DGII)

See `docs/ecf-api-payloads.md` for full API reference.

Key facts:
- E32 < 250k → RFCE flow (`fc.dgii.gov.do`), no `track_id`, manual XML upload to DGII portal
- E33/E34: `RNCOtroContribuyente` must be null (not the comprador RNC) or DGII returns error 614
- QR `ConsultaTimbre` URL: include `&RncComprador=` for all types EXCEPT E43 and E47
- `CodigoSeguridad`: first 6 raw chars of SignatureValue — only strip whitespace, never strip `+`/`/`/`=`
- `FechaHoraFirma` in XML must match `fecha_emision_dgii` in DB — capture timestamp before building XML

---

## Environment / NCF sequences

`DGII_ECF_ENVIRONMENT` in `.env`:
- `certecf` → certification testing (facturas hidden from frontend)
- `ecf` → production

NCF sequences are per-ambiente. After running `tools/migration_ncf_ambiente.sql`, the `ncf_sequences` table has separate rows for `certecf` and `ecf` per e-CF type. Production starts from sequence 0.
