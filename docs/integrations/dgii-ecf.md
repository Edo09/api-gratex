# Integración DGII e-CF — Referencia completa

Certificación DGII completada: **2026-06-01**. Todas las fases pasadas. Gratex (tenant #1)
opera en producción (`ecf`). Cada tenant nuevo certifica por su cuenta y se promueve a `ecf`
(ver [multi-tenant-onboarding.md](multi-tenant-onboarding.md)).

Esta página documenta los flujos DGII (entrante y saliente), el formato de acuse (ARECF),
la autenticación y los bugs críticos resueltos durante la certificación.

---

## URLs de servicio (registradas en DGII)

Todos los tenants registran las **mismas** URLs (el sistema resuelve de quién es cada
documento por el RNC del XML):

| Servicio | URL base |
|---|---|
| Autenticación | `https://gratex.net/api/ecf/autenticacion` |
| Recepción | `https://gratex.net/api/ecf/recepcion` |
| Aprobación Comercial | `https://gratex.net/api/ecf/aprobacion-comercial` |

DGII agrega sufijos fijos al llamarnos:
- Auth: `/fe/autenticacion/api/semilla` (GET) y `/fe/autenticacion/api/ValidacionCertificado` (POST)
- Recepción: `/fe/recepcion/api/ecf` (POST)
- Aprobación: `/fe/aprobacioncomercial/api/ecf` (POST)

---

## Flujo e-CF entrante (DGII → nosotros)

1. `GET .../semilla` → devolver XML semilla, guardar en `auth_seeds`.
2. `POST .../ValidacionCertificado` con la semilla firmada → validar firma, devolver token JSON plano.
3. `POST .../fe/recepcion/api/ecf` con Bearer + e-CF firmado → validar, guardar en
   `ecf_recibidos`, devolver `ARECF` firmado.
4. `POST .../fe/aprobacioncomercial/api/ecf` con Bearer + ACECF firmado → validar, guardar
   en `aprobaciones_comerciales`, devolver `ARECF` firmado.

> **Recepción abierta:** `POST /api/ecf/recepcion` y `/aprobacion-comercial` aceptan el
> documento con **Bearer DGII válido** *o* con **firma XMLDSig válida** (sin completar el
> handshake de semilla). La firma es el gate de integridad; no valida la cadena de CAs, así
> que el e-CF entra como `RECIBIDO`/pendiente y el RNC destino debe ser un tenant registrado.

---

## Formato del acuse ARECF

Respuesta requerida para recepción y aprobación comercial. Debe firmarse con nuestro certificado.

```xml
<?xml version="1.0" encoding="UTF-8"?>
<ARECF>
  <DetalleAcusedeRecibo>
    <Version>1.0</Version>
    <RNCEmisor>{rncEmisor del XML entrante}</RNCEmisor>
    <RNCComprador>{nuestro RNC de emisor_config}</RNCComprador>
    <eNCF>{eNCF del XML entrante}</eNCF>
    <Estado>0</Estado>
    <FechaHoraAcuseRecibo>dd-MM-YYYY HH:mm:ss</FechaHoraAcuseRecibo>
  </DetalleAcusedeRecibo>
  <Signature>...</Signature>
</ARECF>
```

- `Estado`: 0=Recibido, 1=NoRecibido.
- `DetalleAcusedeRecibo` — `d` minúscula en `de` (casing exacto exigido por el XSD).
- XSD: `samples/ARECF v1.0.xsd`.

---

## Formato del token de autenticación

DGII lee `response['token']` directamente. Debe ser JSON **plano** — NO envuelto en
`{"status":true,"data":{...}}`:

```json
{"token": "...", "expira": "2026-06-01T16:00:00", "expedido": "2026-06-01T15:00:00"}
```

---

## Autenticación saliente (nosotros → DGII)

Para llamar a DGII (recepción, consulta de estado) el backend obtiene primero un token
Bearer: semilla → firmar con el `.p12` → enviar a `ValidarSemilla` → recibir `token`.
Lo implementa `src/Utils/FacturacionElectronica/DgiiAuthService.php`
(`consultarEndpointAutenticado()` agrega `Authorization: Bearer` y descarta headers internos).

Endpoints internos (token de API propio vía `X-API-KEY`), útiles para diagnóstico:

| Método | Endpoint | Uso |
|---|---|---|
| GET | `/api/facturacion-electronica/autenticacion/semilla` | Devuelve el XML de semilla de DGII |
| POST | `/api/facturacion-electronica/autenticacion/token` | Ejecuta el flujo completo, devuelve el token DGII |
| POST | `/api/facturacion-electronica/autenticacion/validar-semilla` | Firma (o recibe firmada) y valida la semilla |

> El cert por tenant lo resuelve `src/CertResolver.php` (cae al cert global del `.env` si no
> hay tenant). Variables `.env`: `DGII_ECF_ENVIRONMENT`, `DGII_ECF_CERT_PATH`,
> `DGII_ECF_CERT_PASSWORD`, `OPENSSL_CONF`, `OPENSSL_MODULES`. Ver [../setup.md](../setup.md).

---

## e-CF saliente (nosotros → DGII)

Referencia completa de payloads: [../api/facturas.md](../api/facturas.md). Datos clave:

- E32 < 250k → flujo RFCE (`fc.dgii.gov.do`), sin `track_id`, el XML se sube manual al portal.
- E33/E34: `RNCOtroContribuyente` debe ir null (no el RNC del comprador) o DGII devuelve error 614.
- URL QR `ConsultaTimbre`: incluir `&RncComprador=` para todos los tipos EXCEPTO E43 y E47.
- `CodigoSeguridad`: primeros 6 caracteres crudos de `SignatureValue` — solo quitar
  espacios, nunca `+`/`/`/`=`.
- `FechaHoraFirma` del XML debe coincidir con `fecha_emision_dgii` en la BD — capturar el
  timestamp antes de construir el XML.

---

## Bugs críticos resueltos en certificación

### Router — doble `/api/` en las URLs de callback DGII

DGII agrega `/fe/.../api/ecf` a nuestra base, creando dos segmentos `/api/`.
Antes: `end(explode('/api/', $endpoint))` → tomaba el último segmento → 404.
Fix: `strpos($endpoint, '/api/')` → solo la primera ocurrencia. Archivo: `src/Router.php`.

### IncomingXmlValidator — digest mismatch

Clonaba el root a un nuevo DOMDocument para C14N; `importNode` cambia el contexto de
namespaces → digest mismatch. Fix: quitar la `Signature` del documento original, C14N del
root, reinsertar la `Signature`. Archivo: `src/Utils/FacturacionElectronica/IncomingXmlValidator.php`.

### .htaccess — directiva `<If>` no soportada

`SecRuleEngine Off` dentro de `<If>` rompe Apache en este hosting compartido (500 en todo).
**Nunca** usar `<If>` en `.htaccess`. ModSecurity no era el problema real.

### Nombre de archivo en multipart

DGII valida que el nombre sea `{RNCEmisor}{eNCF}.xml` (ej. `131256432E310000000001.xml`).
Nombres genéricos (`ecf.xml`) → rechazo código 3243. Lo construye
`DgiiReceptionService::buildDgiiFilename()`.

### El cert `.p12` debe ser AES-256/PBKDF2

OpenSSL 3.x no carga `.p12` cifrados con 3DES/RC2 (legacy). Re-cifrar:

```bash
openssl pkcs12 -legacy -in old.p12 -out temp.pem -nodes
openssl pkcs12 -export -in temp.pem -out new.p12 \
  -keypbe AES-256-CBC -certpbe AES-256-CBC -macalg SHA256
```

---

## Ambiente / secuencias NCF

`DGII_ECF_ENVIRONMENT` (fallback single-tenant) y `tenants.ambiente` (per-tenant, prioritario):
- `certecf` → certificación (las facturas se ocultan del frontend).
- `ecf` → producción.

Las secuencias e-NCF son **per-ambiente** (filas separadas por `certecf`/`ecf` en
`ncf_sequences`). Producción arranca en 0. Ver [../database/schema.md](../database/schema.md)
y los runners de certificación en
[../../pasos_certificacion_dgii/README.md](../../pasos_certificacion_dgii/README.md).
