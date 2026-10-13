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

### Resolver una factura sin confirmar (write-ahead)

`POST /api/facturas` guarda la fila **antes** de enviar el e-CF a la DGII. Si el
envío o la confirmación fallan, la factura queda en `ENVIO_INCIERTO` (o en
`PENDIENTE_ENVIO` si el proceso murió) con su e-NCF, el XML firmado, el
`codigo_seguridad` y las líneas. La app **nunca reenvía una factura sin confirmar**: la resuelve soporte. (Una **rechazada** sí se
reemite desde la app: ver «Reenviar a la DGII una factura rechazada».)
Mientras la fila está en vuelo, `GET /api/facturas/{id}/estado` (lo que consulta la
app al abrir la factura) **no llama a la DGII**: devuelve el estado guardado y una
nota que manda a soporte (lo mismo con una fila `ENVIADO` sin `track_id`). El
procedimiento es un `UPDATE` de la fila, y lo que pasa después depende del caso:

- Si la DGII **tiene** el comprobante (un `trackId`, o un RFCE), el `UPDATE` devuelve
  la fila al camino normal de `GET /estado`: desde ahí la app consulta a la DGII,
  aplica el veredicto, escribe `STATUS_CHANGE` y mueve el inventario una sola vez.
- Si la DGII **no lo tiene**, el `UPDATE` de la fila y el de `ncf_sequences` que
  devuelve el número son todo el procedimiento: la app no devuelve el número después
  de ese `UPDATE` (paso 3).

1. **Listar** en la base del tenant:

   ```sql
   SELECT id, e_ncf, tipo_ecf, estado_dgii, track_id, ambiente_dgii, codigo_seguridad, respuesta_dgii
   FROM facturas
   WHERE estado_dgii IN ('PENDIENTE_ENVIO','ENVIO_INCIERTO')
      OR (estado_dgii = 'ENVIADO' AND track_id IS NULL AND e_ncf IS NOT NULL);
   ```

   - No toques una fila cuyo `respuesta_dgii.desde` tenga menos de 5 minutos: su
     petición puede seguir corriendo.
   - `respuesta_dgii` guarda la meta del envío (JSON, sin XML): `flujo` (`ECF` o
     `RFCE`), `dispensado`, `rango_id` y `secuencia_valor` (para devolver el número)
     y, en `ENVIO_INCIERTO`, también `fase_fallida`, `error` y `resultado_parcial`.
   - **Hora:** el servidor (PHP y MySQL) corre en UTC-5, una hora detrás de la DGII
     (UTC-4). `desde` y `fecha_emision_dgii` están en hora del servidor; el portal de
     la DGII muestra la misma recepción una hora más tarde.

2. **Preguntar a la DGII:**

   ```bash
   php tools/consultar_ecf_dgii.php --tenant-id=<id> --encf=<e_ncf> --codigo-seguridad=<codigo_seguridad>
   ```

   (o el wrapper web de la sección anterior). `--codigo-seguridad` evita el 400
   "Cod_Seguridad" de ConsultaEstado. Cuando `respuesta_dgii.fase_fallida` es
   `confirmar`, la DGII ya había respondido y `resultado_parcial` trae su respuesta
   (`track_id`, `estado`): no hace falta consultar. Si la fila quedó en
   `PENDIENTE_ENVIO` porque la base se cayó, esa respuesta está en la auditoría
   (`EMIT` con `success=0`, `new_values.resultado_parcial`) y en la línea
   `ENVIO_INCIERTO ... track_id=...` del `error_log`.

3. **Aplicar un solo caso.** Los `:id`, `:T`, `:rango_id` y
   `:secuencia_valor` de las sentencias son marcadores y phpMyAdmin los rechaza:
   reemplaza cada uno por su valor literal (el `id` de la fila; el trackId entre
   comillas; `rango_id` y `secuencia_valor` de la meta leída en el paso 1).

   | Caso | SQL |
   |---|---|
   | e-CF, la DGII tiene un trackId `T` | `UPDATE facturas SET estado_dgii = 'ENVIADO', track_id = :T WHERE id = :id AND (estado_dgii IN ('PENDIENTE_ENVIO','ENVIO_INCIERTO') OR (estado_dgii = 'ENVIADO' AND track_id IS NULL));` |
   | RFCE (`respuesta_dgii.flujo = 'RFCE'`) | `UPDATE facturas SET estado_dgii = 'RFCE_ENVIADO' WHERE id = :id AND estado_dgii IN ('PENDIENTE_ENVIO','ENVIO_INCIERTO');` La consulta RFCE usa el `codigo_seguridad` guardado. |
   | La DGII no lo tiene (`ecf`/`testecf`: TrackIds sin resultados) | `UPDATE facturas SET estado_dgii = 'NO_ENCONTRADO' WHERE id = :id AND estado_dgii IN ('PENDIENTE_ENVIO','ENVIO_INCIERTO');` y después, **solo si ese `UPDATE` afectó 1 fila** (si afectó 0, no devuelvas el número y vuelve al paso 1), con `rango_id` y `secuencia_valor` de la meta leída en el paso 1: `UPDATE ncf_sequences SET current_value = current_value - 1 WHERE id = :rango_id AND current_value = :secuencia_valor;` Si este segundo `UPDATE` afectó 1 fila, la próxima emisión de ese tipo reutiliza este e-NCF y archiva esta fila como `NO_ENCONTRADO_ARCHIVADO`; con 0 filas el número queda como hueco (igual que un "rollback sin coincidencia") y la próxima emisión toma uno nuevo. Soporte le pide al usuario que emita de nuevo. |
   | RFCE y soporte ya sabe que la DGII no tiene el RFCE (si no, el caso RFCE de arriba deja que la consulta decida) | `UPDATE facturas SET estado_dgii = 'RFCE_NO_ENCONTRADO' WHERE id = :id AND estado_dgii IN ('PENDIENTE_ENVIO','ENVIO_INCIERTO');` y la misma devolución de `ncf_sequences`, con la misma condición (solo si ese `UPDATE` afectó 1 fila; si afectó 0, no devuelvas el número y vuelve al paso 1). El reintento la archiva como `RFCE_NO_ENCONTRADO_ARCHIVADO`. |
   | Fila heredada `ENVIADO` sin `track_id` (de antes del write-ahead) y la DGII no tiene el comprobante | **No se fuerza**: los `UPDATE` de arriba solo coinciden con `PENDIENTE_ENVIO`/`ENVIO_INCIERTO` y la guarda no se cambia. Avisa al usuario con su e-NCF y su `id`, y déjala como está. |
   | `certecf` (TrackIds responde 404 allí) | Decidir entre los casos de arriba con el portal de certificación de la DGII. Un error en `certecf` no tiene efecto fiscal. |

   Lo que hace la app después del `UPDATE` (revisado contra `handleConsultarEstado` en
   `src/Controllers/facturaController.php`):

   - **La DGII tiene el comprobante** (el caso del trackId y el RFCE): abrir la factura
     en la app (o `GET /api/facturas/{id}/estado`) consulta a la DGII y actualiza la fila:
     estado, `STATUS_CHANGE` y, en la primera aceptación, el inventario (una sola vez).
     Si esa consulta dijera que la DGII no lo tiene y no usó la secuencia, la app
     también devuelve el número.
   - **La DGII no lo tiene** (los dos casos `NO_ENCONTRADO`): los dos `UPDATE` son todo
     el procedimiento y la app **no devuelve el número**. Con `NO_ENCONTRADO` sin
     `track_id`, `GET /estado` responde `422`; con `RFCE_NO_ENCONTRADO` vuelve a
     consultar el RFCE, pero como el estado anterior ya era `RFCE_NO_ENCONTRADO` no toca
     la secuencia. El único retorno del número es el `UPDATE` manual de `ncf_sequences`
     (solo si el de `facturas` afectó 1 fila).

   - Si el `UPDATE` de `facturas` afecta 0 filas, la fila cambió de estado mientras
     tanto (por ejemplo, la petición que seguía corriendo ya confirmó) y sigue siendo
     dueña de su número: no apliques nada más y vuelve al paso 1 (salvo la fila
     heredada `ENVIADO` sin `track_id` que la DGII no tiene: esa no se fuerza, ver su
     renglón de la tabla).
   - `NO_ENCONTRADO` y `RFCE_NO_ENCONTRADO` son dos pasos y el orden importa: el
     `UPDATE` de `ncf_sequences` va **solo si** el de `facturas` afectó 1 fila. Con 0
     filas no devuelvas el número: lo posee una fila que ya no está en vuelo.
   - Con `respuesta_dgii.dispensado = false` (el e-NCF vino en el payload) no hay
     número que devolver: solo el `UPDATE` de `facturas`.
   - Nunca un `DELETE`: la fila es la prueba de que ese número se usó.
   - Ejemplo con valores literales, caso "La DGII no lo tiene" (factura `id` 41,
     e-NCF `E310000000123`, meta con `rango_id` 7 y `secuencia_valor` 123):

     ```sql
     UPDATE facturas SET estado_dgii = 'NO_ENCONTRADO'
      WHERE id = 41 AND estado_dgii IN ('PENDIENTE_ENVIO','ENVIO_INCIERTO');
     -- Solo si afectó 1 fila:
     UPDATE ncf_sequences SET current_value = current_value - 1
      WHERE id = 7 AND current_value = 123;
     ```

### Reenviar a la DGII una factura rechazada

Decisión 7 de Release 1, redefinida el 2026-10-12: la app **nunca** reenvía una factura *sin confirmar*
(`PENDIENTE_ENVIO`, `ENVIO_INCIERTO`, `ENVIADO` sin veredicto, `EN_PROCESO`): eso lo resuelve soporte
(procedimiento de arriba) y, en las ventas del POS, el reenvío automático
(`docs/integrations/pos-write-ahead.md`). Una **rechazada** sí se reemite con **«Reenviar a la DGII»**
(`POST /api/facturas/{id}/reenviar`): se pidió porque una factura se rechazó por una fecha mal escrita.

- **Cuándo:** solo e-CF en `RECHAZADO`, `RFCE_RECHAZADO` o `NO_ENCONTRADO`, no archivados, con
  `secuencia_utilizada` distinto de `1`, del mismo ambiente que el activo y con líneas guardadas
  (`RFCE_NO_ENCONTRADO` no entra: la resuelve soporte). Un candado por factura (`tomarLockReenvio`)
  corta el doble clic.
- **Cómo:** sale por la misma emisión write-ahead de la app (guardar, enviar, anotar) con `e_ncf` = el
  de la rechazada: el mismo número. La reserva archiva la rechazada (regla de los estados
  reintentables, `..._ARCHIVADO`); `archivarRechazoReenviado` queda como respaldo.
- **Inventario:** la nueva se descuenta según las reglas de Release 1 y `devolverSaldoDeFactura`
  devuelve lo que la vieja haya movido (una venta del POS rechazada sí lo había descontado).
- **Sobre una factura sin confirmar responde `409`:** si la DGII ya la tiene, reenviar duplicaría el
  comprobante.
- **QR de una reemisión del POS:** el papel original tiene el código de seguridad de la primera firma y
  no validará en la DGII; si el cliente necesita el comprobante válido, se reimprime desde la app.

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
