# Integración DGII e-CF — Referencia completa

Certificación DGII completada: **2026-06-01**. Todas las fases pasadas. Gratex (tenant #1)
opera en producción (`ecf`). Cada tenant nuevo certifica por su cuenta y se promueve a `ecf`
(ver [multi-tenant-onboarding.md](multi-tenant-onboarding.md)).

Esta página documenta los flujos DGII (entrante y saliente), el formato de acuse (ARECF),
la autenticación y los bugs críticos resueltos durante la certificación.

---

## URLs de servicio (registradas en DGII)

Todos los tenants registran las **mismas** URLs (el sistema resuelve de quién es cada
documento por el RNC del XML).:

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

- E32 < 250k → flujo RFCE (`fc.dgii.gov.do`), sin `track_id`. En producción basta el RFCE; el XML íntegro queda firmado en la factura (subirlo al portal fue un paso de la certificación).
- E32 ≥ 250k → va el XML entero y **tiene que identificar al comprador** (norma de la RI; sin cliente el XML sale sin `<Comprador>` y el XSD lo exige): la emisión responde 422 antes de reservar el e-NCF.
- `IndicadorMontoGravado = 1` (precios con ITBIS, `precios_incluyen_itbis`): la DGII calcula los montos gravados **por tasa** (`round(suma de MontoItem / 1.18, 2)`) y el ITBIS como diferencia. Verificado contra su set de pruebas (E450000000003); ver `EcfItemMapper::desglosarIncluido`.
- E33/E34: `RNCOtroContribuyente` debe ir null (no el RNC del comprador) o DGII devuelve error 614.
- URL QR `ConsultaTimbre`: incluir `&RncComprador=` para todos los tipos EXCEPTO E43 y E47.
- `CodigoSeguridad`: primeros 6 caracteres crudos de `SignatureValue` — solo quitar
  espacios, nunca `+`/`/`/`=`.
- `FechaHoraFirma` del XML debe coincidir con `fecha_emision_dgii` en la BD — capturar el
  timestamp antes de construir el XML.

### Recuperar un e-CF que DGII recibió pero no quedó guardado

`php tools/consultar_ecf_dgii.php --rnc=<emisor> --encf=E340000000001[,E340000000002]`
(en el server, con el certificado del tenant; sin shell:
`/api/public/consultar_ecf_dgii.php?token=<CERT_RUN_TOKEN>&rnc=<emisor>&encf=…[&formato=json]`)
consulta por e-NCF los servicios que la emisión normal no usa:

- `ConsultaTrackIds` → trackId(s), estado, fechaRecepcion (solo la fecha, `dd/MM/yyyy`).
- `ConsultaResultado` por cada trackId → estado, secuenciaUtilizada, fechaRecepcion con
  hora (`M/d/yyyy h:mm:ss tt`) y mensajes (motivo de un rechazo).
- `ConsultaEstado` → estado, montoTotal, totalITBIS, fechaEmision, fechaFirma,
  rncComprador y codigoSeguridad (extraídos del e-CF recibido), con los que el
  timbre/QR volvería a ser válido. **En la práctica la DGII puede exigir el propio
  código de seguridad para responder**: con el E340000000001 contestó HTTP 400 "Para
  consultar el estado de esta factura, es necesario completar el campo Cod_Seguridad."
  (2026-10-05). En ese caso el código, los montos y las fechas no se pueden obtener de
  la DGII; el script lo informa como "no disponible", no como fallo.

Lo verificado en producción (2026-10-05, Ferreventura, `ecf`): TrackIds y
ConsultaResultado responden por e-NCF y trackId con el certificado del emisor.

Ningún servicio devuelve items, NCFModificado, razón ni el XML: eso solo existe en el
cliente que lo emitió (pestaña abierta, HAR) o en logs del hosting. Los dos primeros
servicios no existen en `certecf` (responden 404): solo `ecf` y `testecf`. Caso que lo
motivó: E34 aceptado el 2026-10-05 cuyo INSERT falló por la clave `uk_e_ncf` vieja
(migración 027 sin aplicar).

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

### Set de pruebas — la DGII compara el texto, no el número

Rechazo: *"La propiedad DescuentoMonto no es válida debido a que el valor enviado (200) no
coincide con el valor (200.00) del conjunto de datos entregados"*. El XSD acepta `200`, pero
la DGII compara el **texto** contra su set. `EcfItemMapper` deja el descuento en float y el
builder lo escribía con `(string)`. El set no siempre trae 2 decimales: E310000000004 de
CAGLIARI tiene `MontoItem` `'3752'` entre otros `'11000.00'`. Regla de
`ECFXmlBuilder::decimal()`: en el set (`strict_input`) los montos que llegan como texto del
xlsx se firman tal cual si cumplen el patrón del XSD; en la emisión normal, `money()` (2
decimales). Cubre `MontoItem`, `DescuentoMonto`, `RecargoMonto`, las subtablas y todo lo que
pasa por `appendMoneyIfSet`. Pruebas: `php tools/test_ecf_montos_descuento.php`.

### Set de pruebas — notas antes de que su original termine

**Cualquier** rechazo reinicia el set: los originales ya aceptados dejan de contar, aunque
ConsultaResultado los siga mostrando ACEPTADO. Una nota enviada después cae con *"El eNCF
modificado no ha sido emitido"* (614): así cayeron las tres notas de CAGLIARI tras el rechazo
de E410000000010. `tools/send_fase2.php` consulta todo lo enviado antes de las notas y no las
manda si la DGII rechazó algo (`--nota-wait-accepted`; si se agota el tiempo, salen con
aviso). Prueba: `php tools/test_send_fase2_espera.php`.

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
