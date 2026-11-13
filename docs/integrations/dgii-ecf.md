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
- URL QR de E32 < 250k: `https://fc.dgii.gov.do/<ambiente>/ConsultaTimbreFC?RncEmisor=&ENCF=&MontoTotal=&CodigoSeguridad=`
  — otro host y solo esos 4 parámetros (Informe Técnico e-CF v1.0, RI). En `ecf.dgii.gov.do`,
  `ConsultaTimbreFC` responde 404.
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

> **Excepción en certificación (`certecf`).** Un RFCE (E32 < 250k) que quedó en
> `ENVIO_INCIERTO` sí se le pregunta a la DGII: `GET /api/facturas/{id}/estado` consulta por
> e-NCF + código de seguridad y guarda lo que conteste (`RFCE_ACEPTADO`, `RFCE_RECHAZADO` o
> `RFCE_NO_ENCONTRADO`, que deja reemitir el mismo e-NCF). Es el caso «RFCE» de la tabla de
> abajo sin el `UPDATE` a mano (`esRfceInciertoDeCertificacion` en `facturaController.php`).
> No aplica en `ecf`/`testecf`, ni a una venta del POS, ni a una `PENDIENTE_ENVIO`, ni a un
> e-CF completo (certecf no tiene con qué consultarlo sin trackId). Las filas del **set de
> pruebas** tampoco necesitan este procedimiento: ver «Set de pruebas — un envío sin
> confirmar en una empresa `app`».

- Si la DGII **tiene** el comprobante (un `trackId`, o un RFCE), el `UPDATE` devuelve
  la fila al camino normal de `GET /estado`: desde ahí la app consulta a la DGII,
  aplica el veredicto, escribe `STATUS_CHANGE` y mueve el inventario una sola vez.
- Si la DGII **no lo tiene**, el `UPDATE` de la fila y el de `ncf_sequences` que
  devuelve el número son todo el procedimiento: la app no devuelve el número después
  de ese `UPDATE` (paso 3). **En una fila del POS no hay `UPDATE` de `ncf_sequences`**
  (siguiente párrafo).

**Filas del POS (`pos_idempotency_key` no nulo).** Una venta del POS ya está entregada: su
e-NCF está impreso en el recibo y **nunca vuelve al contador**. Por eso, en una fila del POS:

- **Nunca corras el `UPDATE` de `ncf_sequences`** (ni siquiera con `dispensado = true`): la
  próxima venta recibiría el número impreso en otro recibo y la reserva archivaría esta fila.
  Tampoco lo hace la app: `GET /estado` guarda el veredicto y deja el contador como está.
- En los casos «La DGII no lo tiene» y `RFCE_NO_ENCONTRADO` el `UPDATE` de `facturas` lleva
  además `, envio_pendiente = 0`: así el reenvío automático deja de recogerla. `NO_ENCONTRADO`
  se reemite con «Reenviar a la DGII» (el mismo e-NCF); `RFCE_NO_ENCONTRADO` queda para soporte,
  porque la app no ofrece ese botón en ese estado.
- En los casos del `trackId` y del RFCE el `UPDATE` **no** toca `envio_pendiente`: sigue en 1 y el
  reenvío automático consulta el resultado y termina la fila (también lo hace abrirla en la app).
- **No hace falta ganarle al reenvío automático.** Consulta primero y reenvía el XML guardado por
  sí solo en cuanto un POS está abierto (al abrir, cada 2 minutos y tras cada venta); respeta su
  propia espera: salta las `PENDIENTE_ENVIO` y `ENVIO_INCIERTO` de menos de 2 minutos desde su
  reserva (la regla de 5 minutos del paso 1 es más estricta y sigue vigente para el `UPDATE`
  manual). Si los POS llevan horas cerrados y el cliente espera, entonces sí se aplica el
  procedimiento, con estas diferencias.
- Una fila `PENDIENTE_ENVIO` con `"sin_rango": true` en la meta (el rango autorizado se agotó) **no se
  resuelve con este procedimiento**: nunca se envió y espera un rango; al registrarlo
  (`POST /api/ncf/rangos`) el reenvío automático la envía.

1. **Listar** en la base del tenant:

   ```sql
   SELECT id, e_ncf, tipo_ecf, estado_dgii, track_id, ambiente_dgii, codigo_seguridad, respuesta_dgii,
          pos_idempotency_key, envio_pendiente
   FROM facturas
   WHERE estado_dgii IN ('PENDIENTE_ENVIO','ENVIO_INCIERTO')
      OR (estado_dgii = 'ENVIADO' AND track_id IS NULL AND e_ncf IS NOT NULL);
   ```

   - No toques una fila cuyo `respuesta_dgii.desde` tenga menos de 5 minutos: su
     petición puede seguir corriendo.
   - `respuesta_dgii` guarda la meta del envío (JSON, sin XML): `flujo` (`ECF` o
     `RFCE`), `dispensado`, `rango_id` y `secuencia_valor` (para devolver el número)
     y, en `ENVIO_INCIERTO`, también `fase_fallida`, `error` y `resultado_parcial`.
   - **Hora:** desde noviembre de 2026 la app escribe en hora de RD (UTC-4, la de la
     DGII; ver `src/ZonaHoraria.php`): `desde`, `fecha_emision_dgii` y el portal de la
     DGII coinciden. Las filas de antes del cambio están en la hora del hosting
     (central de EE. UU.): el portal de la DGII muestra la misma recepción 1 hora más
     tarde en el horario de verano de EE. UU. (segundo domingo de marzo a primer
     domingo de noviembre) y 2 horas fuera de él (del 1 de noviembre de 2026 al cambio).

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
   | La DGII no lo tiene (`ecf`/`testecf`: TrackIds sin resultados) | `UPDATE facturas SET estado_dgii = 'NO_ENCONTRADO' WHERE id = :id AND estado_dgii IN ('PENDIENTE_ENVIO','ENVIO_INCIERTO');` (en una **fila del POS**: `SET estado_dgii = 'NO_ENCONTRADO', envio_pendiente = 0` y **nada más**: no hay `UPDATE` de `ncf_sequences`). En una fila de la app, después, **solo si ese `UPDATE` afectó 1 fila** (si afectó 0, no devuelvas el número y vuelve al paso 1), con `rango_id` y `secuencia_valor` de la meta leída en el paso 1: `UPDATE ncf_sequences SET current_value = current_value - 1 WHERE id = :rango_id AND current_value = :secuencia_valor;` Si este segundo `UPDATE` afectó 1 fila, la próxima emisión de ese tipo reutiliza este e-NCF y archiva esta fila como `NO_ENCONTRADO_ARCHIVADO`; con 0 filas el número queda como hueco (igual que un "rollback sin coincidencia") y la próxima emisión toma uno nuevo. Soporte le pide al usuario que emita de nuevo. |
   | RFCE y soporte ya sabe que la DGII no tiene el RFCE (si no, el caso RFCE de arriba deja que la consulta decida) | `UPDATE facturas SET estado_dgii = 'RFCE_NO_ENCONTRADO' WHERE id = :id AND estado_dgii IN ('PENDIENTE_ENVIO','ENVIO_INCIERTO');` (en una **fila del POS**: `SET estado_dgii = 'RFCE_NO_ENCONTRADO', envio_pendiente = 0` y **nada más**: no hay devolución de `ncf_sequences`). En una fila de la app, la misma devolución de `ncf_sequences`, con la misma condición (solo si ese `UPDATE` afectó 1 fila; si afectó 0, no devuelvas el número y vuelve al paso 1). El reintento la archiva como `RFCE_NO_ENCONTRADO_ARCHIVADO`. |
   | Fila heredada `ENVIADO` sin `track_id` (de antes del write-ahead) y la DGII no tiene el comprobante | **No se fuerza**: los `UPDATE` de arriba solo coinciden con `PENDIENTE_ENVIO`/`ENVIO_INCIERTO` y la guarda no se cambia. Avisa al usuario con su e-NCF y su `id`, y déjala como está. |
   | `certecf` (TrackIds responde 404 allí) | Decidir entre los casos de arriba con el portal de certificación de la DGII. Un error en `certecf` no tiene efecto fiscal. Un RFCE incierto se resuelve solo al consultar su estado (la excepción de arriba), y una fila del set de pruebas se archiva al volver a enviar ese e-NCF: no hace falta SQL. |

   Lo que hace la app después del `UPDATE` (revisado contra `handleConsultarEstado` en
   `src/Controllers/facturaController.php`):

   - **La DGII tiene el comprobante** (el caso del trackId y el RFCE): abrir la factura
     en la app (o `GET /api/facturas/{id}/estado`) consulta a la DGII y actualiza la fila:
     estado, `STATUS_CHANGE` y, en la primera aceptación, el inventario (una sola vez).
     Si esa consulta dijera que la DGII no lo tiene y no usó la secuencia, la app
     también devuelve el número, **salvo en una fila del POS**: ahí guarda el veredicto y
     el número se queda (está impreso en el recibo).
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

### Rechazos de la DGII: qué significa cada uno y qué hacer

Un QR (`ConsultaTimbre`) de un e-CF **rechazado nunca resuelve**: la URL puede estar bien
armada y aun así la DGII no encuentra el comprobante. Primero hay que ver el estado.

**Dónde queda guardado.** Empresas `app`: `facturas.estado_dgii` y `respuesta_dgii` en su base.
Empresas `integracion` (sin base propia): `master.ecf_integracion_backup` (master_migrations/014).
Ahí se guarda el estado al emitir y se actualiza en cada `GET /api/integracion/estado`
(`estado_dgii`, `dgii_mensajes` con el motivo, `dgii_respuesta` con la respuesta completa y
`estado_consultado_at`). Rechazos de una empresa:

```sql
SELECT e_ncf, ambiente, track_id, estado_dgii, dgii_mensajes, estado_consultado_at
  FROM ecf_integracion_backup
 WHERE tenant_id = 6 AND estado_dgii = 'RECHAZADO'
 ORDER BY id DESC;
```

En `certecf` la DGII **no** tiene `ConsultaTrackIds` ni `ConsultaEstado` (404 en
`tools/consultar_ecf_dgii.php`); por `trackId` (`ConsultaResultado`, lo que usa
`GET /api/integracion/estado`) sí responde.

| Código | Mensaje de la DGII | Causa | Qué hacer |
|---|---|---|---|
| 145 | "No se encuentra en una fase válida dentro del proceso de certificación." | La empresa (RNC emisor) no está en un paso de la certificación en que CerteCF acepte e-CF: no la empezó, no pasó los pasos previos en la Oficina Virtual, está en un paso que no recibe envíos libres, o **ya terminó** (CerteCF deja de recibir). No es un error del XML ni de nuestro código | El contribuyente revisa en la OFV de la DGII en qué paso está. Si ya está autorizado en producción: `master.tenants.ambiente = 'ecf'` y reemitir. Si sigue certificando: completar el paso pendiente y reemitir. Con `secuenciaUtilizada: false` el e-NCF no se gastó y se puede reusar |

Caso de referencia: PUBLIC SOUND SRL (tenant 6, RNC 133389843), `E310000000027`, `certecf`,
trackId `774f1e7b-25eb-4b53-9f09-1640feb0398a`, rechazado 30-10-2026 con el 145. El QR salía
con `CerteCF/ConsultaTimbre` (correcto para esa empresa) y "no funcionaba" por el rechazo.

Al encontrar un rechazo nuevo, agréguelo a esta tabla.

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

### Set de pruebas — `TipoIngresos` vacío en una nota

Rechazo: *"El formato del XML no es válido.. La propiedad TipoIngresos no es válida debido a
que el valor enviado () no coincide con el valor () del conjunto de datos entregados"*
(NUEVAS IDEAS NOTICIAS RD, 2026-10-26, E340000000015). El xlsx trae la celda vacía,
`Fase2XlsxReader` la entrega como `''` (no `null`) y el `?? '01'` de runner, controlador y
servicio la deja pasar: el builder firmaba `<TipoIngresos/>`, que no es ningún valor del XSD
(01-06). XSD: obligatorio en 31, 32, 44, 45, 46 y el RFCE; opcional (`minOccurs=0`) en 33 y
34; no existe en 41, 43, 47. Regla de `ECFXmlBuilder::buildIdDoc()`: en el set
(`strict_input`) una nota 33/34 que lo trae vacío se firma sin el elemento; en lo demás, vacío
= `'01'`. Pruebas: `php tools/test_ecf_tipo_ingresos.php`.

### Set de pruebas — re-correrlo en una empresa `app`

Tras un reinicio se reenvía el **mismo xlsx**, con los mismos e-NCF, pero en la BD del tenant
esos números ya están en `ACEPTADO`, `RFCE_ACEPTADO` o `ENVIADO`, y la reserva del write-ahead
respondía "número ocupado" en todos (NUEVAS IDEAS NOTICIAS RD, 2026-10-26). Regla de
`facturaModel::reservarFacturaECF`: con `set_pruebas` (el `strict_input` del runner, que
`facturaController` pasa en `$facturaInput`) y reserva en `certecf`, la fila previa `certecf`
del mismo e-NCF se archiva (`<estado>_ARCHIVADO`, `e_ncf` NULL, compare-and-set) en cualquier
estado, `ERROR` incluido, y desde el 2026-11-12 también una `ENVIO_INCIERTO` y una
`PENDIENTE_ENVIO` de más de 5 minutos (sección siguiente). Siguen bloqueando: una
`PENDIENTE_ENVIO` reciente (su petición puede seguir enviando: es otra corrida en curso), una
sin ambiente o sin estado, una venta del POS (`pos_idempotency_key`: su reenvío automático la
busca por `envio_pendiente`), y todo en `ecf`/`testecf` o sin `strict_input`. No hace falta SQL
a mano. La DGII repite e-NCF entre un set y el siguiente (los de Gratex comparten
E310000000034, E320000000006, E330000000001…), así que esto aplica también con un xlsx nuevo.
Pruebas: `php tools/test_write_ahead_puro.php`, sección 18.

### Set de pruebas — un envío sin confirmar en una empresa `app`

**Qué pasó** (empresa RNC 133454394, 2026-11-12). En su primera corrida de la fase 2,
RecepcionFC no contestó tres RFCE (E320000000011, E320000000013 y E320000000015: *"Operation
timed out after 30000 milliseconds with 0 bytes received"*). Las tres filas quedaron en
`ENVIO_INCIERTO`, el runner no mandó las notas («no se sabe si llegaron a la DGII») y en las
corridas siguientes (una docena ese día) esos tres e-NCF respondieron **«No se pudo asignar el
número de comprobante. Inténtalo de nuevo.»** (`502`): la reserva no archivaba una fila en vuelo. El set
no se podía completar sin un `UPDATE` a mano, y cada corrida completa reenviaba lo que la DGII
ya había aceptado (un duplicado sale rechazado — *"La combinación e-NCF y código de seguridad
de este Resumen de Factura de Consumo ya han sido utilizados previamente"* — y cualquier
rechazo reinicia el set). Una de esas corridas además usó el xlsx de **otra empresa**
(130968837) y salió entera con ese `RNCEmisor`, firmada con el certificado de esta: la DGII
respondió *"El RNC del certificado no está delegado para realizar transaciones para el RNC:
130968837"* y las filas quedaron en su base.

**Por qué en integración no pasa.** `integracion.html` corre el mismo runner
(`tools/send_fase2.php` vía `cert_run.php`); la diferencia está en el servidor.
`POST /api/integracion/ecf` no tiene base de la empresa: firma, envía y guarda un respaldo,
así que nada local puede bloquear un e-NCF y un timeout se arregla reenviando. Y antes de
enviar verifica que el `RNCEmisor` del set sea la empresa de la credencial (el runner con
`GET /integracion/empresas`; el controlador con `emisor.rnc`). `POST /api/facturas` guarda la
fila **antes** de enviar (write-ahead) y no comparaba el RNC.

**Cómo queda** (modo app; integración no cambia):

- **Reserva** (`facturaModel::reservarFacturaECF`): en el set y en `certecf`, una
  `ENVIO_INCIERTO` previa se archiva como cualquier otra (su envío ya terminó sin respuesta);
  una `PENDIENTE_ENVIO` solo si su `respuesta_dgii.desde` tiene más de 5 minutos
  (`SET_PRUEBAS_PENDIENTE_VIEJA_SEG`, `pendienteSigueEnviando()`).
- **Emisor** (`facturaController::emitirFacturaEcf`): si `emisor.rnc` viene en el body y no es
  el RNC de la empresa, `422` antes de reservar nada.
- **Runner, antes de enviar** (`certEmpresaPreflightApp`, `GET /api/emisor`): el `RNCEmisor`
  del xlsx tiene que ser el de la empresa del token, y el set tiene que salir a `certecf`
  (`--ambiente`, que `cert_run.php` manda siempre, o el ambiente de la empresa). Si no, o si el
  token venció, no envía nada. `--permitir-produccion` (solo CLI) salta lo del ambiente. Si lo
  que contesta no es el API (un `406` del WAF del hosting) y la corrida va a `certecf`, sigue
  con un `AVISO`: el `422` del punto anterior cubre el caso.
- **Runner, lo que no quedó ni emitido ni rechazado** (`f2ReintentarPendientes`, antes de las
  notas y al final): un `502` con `data.factura_id` es un envío **sin confirmar** — primero se
  consulta `GET /api/facturas/{id}/estado` (la excepción de certecf de arriba): si la DGII ya
  lo tiene se toma ese estado y **no se reenvía**; si lo rechazó cuenta como rechazo; si no lo
  tiene o no se puede saber (un e-CF completo, la consulta falló) se manda de nuevo y la
  reserva archiva el intento anterior. Un `502` **sin** fila es un fallo anterior a la reserva
  (nada salió hacia la DGII): se manda de nuevo, y ya no cuenta como «no se sabe si llegó».
  Hasta `--reintentos` veces por caso (default 2; `0` lo apaga), con `--reintento-pausa`
  segundos antes de cada ronda (default 10).
- **Runner, al final**: si faltó algo y la DGII no rechazó nada, imprime la lista de e-NCF que
  faltan para correr **solo esos** (`--case=E32…,E34…`, o el campo «Solo estos e-NCF» del paso
  2 de `cert.html`). Con un rechazo dice que hay que correr la fase completa.

Pruebas (sin base ni red): `php tools/test_write_ahead_puro.php` (sección 18),
`php tools/test_send_fase2_espera.php` (bloques «App») y `php tools/test_send_fase2_app.php`
(la corrida entera de `main()` sobre un xlsx de prueba, con el API y la DGII falsos).

### Wizard `cert.html` — vencimiento de la secuencia y XML íntegros de los RFCE

- **Paso 2, «Solo estos e-NCF»:** `cert_run.php` lo pasa como `--case` (varios, separados por coma). Sirve
  para mandar solo lo que faltó de una corrida — la lista sale al final de la salida del runner — sin
  reenviar lo que la DGII ya aceptó. Las notas que se listen salen después de los originales que vayan en
  esa misma corrida; el estado de los originales de otra corrida no se verifica en modo app.
- **Fase 4, «Vencimiento de la secuencia»:** `cert_run.php` lo pasa como `--fecha-vencimiento-secuencia`
  y el runner lo pone en `fecha_vencimiento_secuencia` de cada payload (ahora también en empresas `app`,
  no solo integración). Pisa el del rango en `ncf_sequences`; vacío = el del rango. Úsalo cuando la DGII
  responde «Fecha de vencimiento de secuencia inválida» porque el rango autorizado vence otro día.
- **Fase 4, notas E33/E34:** antes de la primera nota el runner consulta a la DGII cada E31 de referencia
  hasta `ACEPTADO` (`--nota-wait-accepted`, default 300 s; 0 = no esperar). Esa consulta es la que pasa
  `facturas.estado_dgii` de `ENVIADO` a `ACEPTADO` en nuestra BD, y la validación de la nota lee ese
  estado: sin consulta sale «La DGII todavía no ha aceptado la factura …» aunque el panel de la DGII ya
  la muestre aceptada (tenant 9, 2026-10-26). Para mandar solo las notas: botón «Solo las notas
  (E33/E34)», los e-NCF de los E31 en «E31 de referencia» y su fecha de emisión.
- **Pasos 2 y 4, «XML íntegros (RFCE)»:** baja un ZIP con el e-CF firmado completo de cada E32 < 250k cuyo
  RFCE la DGII aceptó (lista `GET /api/facturas?tipo_ecf=32`, XML `GET /api/facturas/{id}/xml`). Solo las
  filas vivas, o sea el último envío de cada e-NCF: una archivada por una re-corrida no tiene `e_ncf`.

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
