# API — Modo Integración (JSON → e-CF)

Contrato para clientes tipo **integración**: mandan JSON desde su propio sistema y reciben
el XML firmado + la respuesta de DGII. **Sin DB propia** — el cliente maneja sus facturas y
su secuencia e-NCF; nosotros firmamos, enviamos a DGII y guardamos un respaldo en el master.

Alta del tenant y credenciales: [../integrations/multi-tenant-onboarding.md](../integrations/multi-tenant-onboarding.md).
Payloads por tipo de e-CF (E31–E47): [facturas.md](facturas.md).

> **Para entregar al cliente** usa [guia-cliente-integracion.md](guia-cliente-integracion.md):
> misma información pero autocontenida y sin detalles internos (tablas del master, rutas de
> archivos, herramientas admin). Este doc es la referencia nuestra.

Base: `https://gratex.net/api/`

---

## Autenticación

Credenciales de máquina en **todo** request (las entrega el onboarding; el secret se muestra
una sola vez):

```
X-API-KEY: <api_key>
X-API-SECRET: <api_secret>
```

El master guarda solo el sha256 del secret. Si se pierde, se regenera — no se puede recuperar.

| Endpoint | Método | Uso |
|---|---|---|
| `/api/integracion/ecf` | POST | Emitir e-CF |
| `/api/integracion/aprobacion-comercial` | POST | Aprobar/rechazar un e-CF que le emitieron |
| `/api/integracion/recibidos` | GET | Bandeja de e-CF que le facturaron |
| `/api/integracion/aprobaciones` | GET | Aprobaciones recibidas sobre lo que emitió |
| `/api/integracion/empresas` | GET | Empresas que cubre la credencial (grupo) |
| `/api/integracion/estado` | GET | Estado en DGII de un e-CF que emitió |

Un tenant tipo `app` que llame estos endpoints recibe **403** (`Endpoint solo para tenants
tipo integracion`), y viceversa.

---

## Multi-empresa — una credencial, varios RNC

Un cliente que administra varias empresas en su propio ERP no debería llevar N pares de
credenciales. Si sus tenants comparten `tenants.grupo_id`, **la credencial de cualquiera de
ellos actúa por sus hermanos** y el RNC del payload elige la empresa.

| Llamada | Selector de empresa | Si se omite |
|---|---|---|
| `POST /ecf` | `emisor.rnc` | (obligatorio) |
| `POST /aprobacion-comercial` | `rnc_comprador` | la dueña de la credencial |
| `GET /recibidos` · `/aprobaciones` | `?rnc=` | la dueña de la credencial |

El salto lo hace `TenantResolver::switchToSibling()`, que deniega salvo que: haya tenant
resuelto, tenga `grupo_id` no nulo, el RNC destino exista **activo y con el mismo
`grupo_id`**, y sea del **mismo `tipo`** (una credencial de integración nunca alcanza un
tenant `app`). Al saltar se actualiza también el `tenant_id` del contexto de auditoría, así
que la bitácora registra la empresa por la que se actuó, no la dueña de la credencial.

Todo lo de aguas abajo ya era per-tenant y sigue igual sin cambios: `CertResolver` toma el
`.p12` del tenant activo, `AmbienteResolver` su ambiente, e `IntegracionStoreModel` su
`tenant_id`. Consecuencia útil: **las empresas de un grupo pueden estar en ambientes
distintos** — una en `ecf` y otra en `certecf` — con la misma credencial.

`grupo_id` NULL (el default, y lo que tienen todos los tenants preexistentes) = aislamiento
total: el predicado `grupo_id = :g` nunca hace match contra NULL.

> **Regla:** agrupar dos tenants significa que una credencial filtrada expone a ambos.
> Agrupar SOLO empresas del mismo cliente/operador. Ver `db/master_migrations/008`.

Alta de un grupo: `tools/create_tenant.php --grupo=self` para la primera empresa (queda
agrupada consigo misma; su `tenant_id` es el `grupo_id`), luego `--grupo=<ese id>` para las
demás. El script valida que la cabeza exista, esté activa, encabece grupo y sea del mismo
tipo.

---

## POST `/api/integracion/ecf` — Emitir

Mismo shape que `POST /api/facturas` ([facturas.md](facturas.md)) **más tres requisitos
propios del modo integración**. Sin ellos la emisión falla con 422:

| Requisito | Por qué |
|---|---|
| **`e_ncf` obligatorio** | El cliente asigna su propia secuencia; el sistema no dispensa (no hay `ncf_sequences` sin DB). Debe ser `E<tipo>` + 10 dígitos |
| **`emisor` con `rnc`, `razon_social`, `direccion`** | No hay `emisor_config` que leer |
| **`emisor.rnc` == RNC del tenant autenticado** | No se puede emitir por cuenta de otro RNC |

El **`ambiente` lo fuerza el servidor** desde `tenants.ambiente` — el cliente no lo elige
(un campo `ambiente` en el body se ignora).

### Ejemplo (E31)

```json
{
  "tipo_ecf": "31",
  "e_ncf": "E310000000001",
  "emisor": {
    "rnc": "131111111",
    "razon_social": "CLIENTE A SRL",
    "direccion": "Av. Winston Churchill #45",
    "nombre_comercial": "Cliente A",
    "municipio": "010100",
    "provincia": "010000",
    "telefono": "809-555-0100",
    "correo": "facturacion@clientea.com"
  },
  "comprador": {
    "rnc": "131880681",
    "razon_social": "EMPRESA COMPRADORA SRL"
  },
  "items": [
    {
      "numero_linea": 1,
      "indicador_facturacion": 1,
      "nombre_item": "Servicio profesional",
      "descripcion": "Consultoría mes de agosto",
      "indicador_bien_servicio": 2,
      "cantidad": 5,
      "unidad_medida": "43",
      "precio_unitario": 1500.00
    }
  ]
}
```

`totales` es opcional — se calculan de los items (ITBIS 18% si `indicador_facturacion=1`,
16% si `=2`, 0% si `=3`, exento si `=4`). Enviarlo solo para forzar tasas distintas.

> **Límites DGII de los campos de ítem:** `nombre_item` máx **80** caracteres,
> `descripcion` máx **1000**. El backend trunca como red de seguridad, pero el corte puede
> caer a mitad de palabra — separar bien los campos en el origen.
> Ver [../frontend/limites-campos-item-dgii.md](../frontend/limites-campos-item-dgii.md).

> **Saltos de línea:** no mandar `\r` (CR) en textos. DGII pierde los `&#13;` al
> re-serializar el XML y rechaza con *"La firma del XML no es válida"* aunque la firma sea
> correcta. El backend normaliza `\r` → `\n` en la entrada y en los builders, y bloquea
> cualquier XML firmado con CR antes de enviarlo.

### Respuesta

```json
{
  "status": true,
  "data": {
    "e_ncf": "E310000000001",
    "tipo_ecf": "31",
    "estado": "ACEPTADO",
    "track_id": "0784f643-3c19-4377-9104-e0581f0200d6",
    "codigo_seguridad": "RHq/pJ",
    "ambiente": "ecf",
    "fecha_emision": "2026-08-01 11:52:22",
    "xml_firmado": "<?xml version=\"1.0\" encoding=\"UTF-8\"?>...",
    "dgii_response": { }
  }
}
```

El cliente debe **guardar `xml_firmado`** (es el comprobante legal) y el `codigo_seguridad`
+ `fecha_emision` para su Representación Impresa. Nosotros guardamos un respaldo en
`master.ecf_integracion_backup`, pero la fuente de verdad es su sistema.

### Errores

| Código | Cuándo |
|---|---|
| `400` | JSON body inválido |
| `401` | `X-API-KEY`/`X-API-SECRET` ausente o inválido |
| `403` | El tenant no es tipo `integracion` |
| `405` | Método distinto de POST |
| `422` | Falta `emisor.rnc`, falta `e_ncf`, o `emisor.rnc` no coincide con el tenant |
| `502` | Falló la emisión (build/firma/envío a DGII) — el mensaje trae el detalle |

Formato de error: `{ "status": false, "error": "<mensaje>" }`.

---

## POST `/api/integracion/aprobacion-comercial` — Aprobar/rechazar

Como comprador, el tenant acepta o rechaza un e-CF que le emitieron. Se arma y firma el
ACECF con su certificado y se envía a DGII.

```json
{
  "rnc_emisor": "131256432",
  "e_ncf": "E310000000028",
  "fecha_emision": "01-08-2026",
  "monto_total": 6608.00,
  "estado": "1",
  "detalle_motivo": null
}
```

| Campo | Requerido | Notas |
|---|---|---|
| `rnc_emisor` | sí | RNC de quien le facturó |
| `e_ncf` | sí | e-NCF del comprobante recibido |
| `fecha_emision` | sí | |
| `monto_total` | sí | |
| `estado` | sí | `1` = Aceptado, `2` = Rechazado |
| `detalle_motivo` | si `estado=2` | Obligatorio al rechazar |
| `ambiente` | no | Override. Si se omite, usa el ambiente en que se recibió el e-CF; si no lo encuentra, el del tenant |

`rnc_comprador` **no se envía** — se toma del tenant autenticado.

Respuesta: `{status, data:{rnc_emisor, e_ncf, estado_aprobacion, track_id, estado_dgii,
codigo_seguridad, ambiente, fecha_envio, dgii_response}}`.

> **Leer bien la respuesta de DGII:** `dgii_response.codigo` = `1`/`01` significa que DGII
> **procesó** el envío; `2`/`02` = no procesada (factura no encontrada, error técnico, o
> ambiente que no coincide). Un `estado` textual `"Aprobacion Comercial Rechazada."`
> significa que DGII rechazó **procesar el envío**, no que el rechazo comercial quedó
> registrado. La decisión se persiste igual en éxito y en error (con `procesada=0`).

Errores: `422` (campo faltante, `estado` distinto de 1/2, o `detalle_motivo` vacío con
`estado=2`), `502` (falló el envío a DGII).

---

## GET `/api/integracion/estado` — Estado en DGII de lo emitido

La emisión devuelve el estado con que DGII acusó el recibo; los `EN_PROCESO` se resuelven
después. Este endpoint consulta el estado actual — el equivalente de
`GET /facturas/{id}/estado` para tenants sin DB.

```
GET /api/integracion/estado?e_ncf=E310000000001&track_id=0784f643-...
GET /api/integracion/estado?e_ncf=E320000000005&codigo_seguridad=RHq/pJ   # RFCE
GET /api/integracion/estado?e_ncf=E310000000001                           # track_id del respaldo
```

| Query | Requerido | Notas |
|---|---|---|
| `e_ncf` | sí | |
| `track_id` | no | Si se omite, se busca en `master.ecf_integracion_backup` por (RNC, e-NCF) |
| `codigo_seguridad` | solo RFCE | Los **E32 <250k** no generan `track_id`: se consultan por código de seguridad en RecepcionFC |
| `rnc` | no | Empresa del grupo (igual que en `/recibidos`) |

El `ambiente` sale del tenant, igual que en la emisión. **No persiste nada**: el respaldo del
master no guarda estado y la fuente de verdad es el sistema del cliente.

```json
{
  "status": true, "recurso": "estado",
  "rnc": "131111111", "empresa": "CLIENTE A SRL",
  "e_ncf": "E310000000001",
  "track_id": "0784f643-3c19-4377-9104-e0581f0200d6",
  "flujo": "ECF", "ambiente": "certecf",
  "estado": "ACEPTADO",
  "consulta": { }
}
```

`estado` normaliza texto y códigos de DGII a: `ACEPTADO`, `ACEPTADO_CONDICIONAL`,
`EN_PROCESO`, `RECHAZADO`, `NO_ENCONTRADO` (prefijo `RFCE_` en la consulta por código de
seguridad), o `null` si DGII no devolvió estado reconocible — el detalle crudo queda en
`consulta`.

| Código | Cuándo |
|---|---|
| `404` | No hay respaldo de ese e-NCF para ese RNC, o el respaldo no tiene `track_id` (manda `codigo_seguridad`) |
| `422` | Falta `e_ncf` |
| `502` | Falló la consulta a DGII (auth con el cert, servicio caído) |

---

## GET `/api/integracion/recibidos` — Bandeja de entrada

e-CF que otros emisores le facturaron. Se llenan solos cuando DGII/el emisor entrega el
documento en `POST /api/ecf/recepcion` (el tenant se resuelve por `RNCComprador` del XML).

Query: `?page=1&pageSize=20` (`pageSize` máx **100**).

Filtra automáticamente por el **ambiente actual del tenant** — durante certificación solo
se ven documentos `certecf`; al promover a `ecf`, solo producción.

```json
{
  "status": true,
  "recurso": "recibidos",
  "data": [
    {
      "id": 1, "track_id": "...", "tipo_ecf": "31", "e_ncf": "E310000000001",
      "rnc_emisor": "...", "razon_social_emisor": "...", "rnc_comprador": "...",
      "monto_total": 6608.00, "fecha_emision": "...", "fecha_recepcion": "...",
      "estado": "RECIBIDO", "codigo_resultado": null, "mensaje_resultado": null,
      "validacion_firma": "OK", "ambiente": "ecf",
      "origen_ip": "...", "origen_auth": "...", "firma_rnc": "...", "firma_subject": "...",
      "aprobacion_comercial": null, "aprobacion_comercial_estado_dgii": null
    }
  ],
  "pagination": { "page": 1, "pageSize": 20, "total": 1, "totalPages": 1 }
}
```

`estado` = resultado **técnico** de la recepción (firma/XSD). `aprobacion_comercial` = la
decisión **comercial** ya enviada a DGII; `null` = pendiente (mostrar "Pendiente", no
asumir aprobado).

---

## GET `/api/integracion/aprobaciones` — Veredictos sobre lo emitido

Aprobaciones/rechazos que los compradores mandaron sobre las facturas del tenant.
Mismos query params y mismo filtro por ambiente.

```json
{
  "status": true,
  "recurso": "aprobaciones",
  "data": [
    {
      "id": 1, "e_ncf": "E310000000001", "rnc_emisor": "...", "rnc_comprador": "...",
      "estado_comercial": "ACEPTADO", "detalle_motivo": null,
      "validacion_firma": "OK", "ambiente": "ecf", "fecha_recepcion": "..."
    }
  ],
  "pagination": { "page": 1, "pageSize": 20, "total": 1, "totalPages": 1 }
}
```

> No confundir las dos bandejas: `recibidos` = facturas que **le emitieron** (rol comprador);
> `aprobaciones` = veredictos sobre facturas que **él emitió** (rol emisor). Detalle del
> modelo en [recepcion-aprobacion.md](recepcion-aprobacion.md).

---

## Webhook (alternativa al polling)

Si el tenant registró `webhook_url` en el onboarding, los documentos entrantes se empujan
por POST además de quedar disponibles por polling.

**Eventos:** `ecf.recibido` · `aprobacion.recibida`

**Body:**

```json
{
  "event": "ecf.recibido",
  "tenant_id": 7,
  "data": {
    "track_id": "...", "tipo_ecf": "31", "e_ncf": "E310000000001",
    "rnc_emisor": "...", "razon_social_emisor": "...", "rnc_comprador": "...",
    "monto_total": 6608.00, "fecha_emision": "...", "estado": "RECIBIDO"
  },
  "sent_at": "2026-08-04T09:53:22-04:00"
}
```

`data` de `aprobacion.recibida`: `e_ncf`, `rnc_emisor`, `rnc_comprador`, `estado_comercial`,
`detalle_motivo`.

**Verificación de firma** — header `X-Gratex-Signature`:

```
X-Gratex-Signature: sha256=<hex HMAC-SHA256 del body crudo con el webhook_secret>
```

Calcular el HMAC sobre el **cuerpo crudo** (no sobre el JSON re-serializado) y comparar en
tiempo constante. Sin `webhook_secret` configurado el header no se envía.

**Entrega:** best-effort, hasta **3 intentos** con backoff corto (0.2s, 0.4s), timeout de
**5 s** por intento. Se dispara **después** de responder el acuse a DGII, así que no demora
la recepción. Si fallan los 3 intentos solo se registra en el `error_log` — **el webhook no
reemplaza al polling**: el cliente debe reconciliar con `GET /recibidos` periódicamente.

---

## Certificación y paso a producción

El tenant arranca en `certecf`. Corre su set de pruebas contra DGII mandando sus e-CF
normalmente (el ambiente sale de `tenants.ambiente`, no del payload). Si el set lo corremos
nosotros, los runners `tools/send_fase2.php` · `send_fase3.php` · `send_fase4_simulation.php`
aceptan `--api-secret` y apuntan a estos endpoints — ver
[../integrations/multi-tenant-onboarding.md](../integrations/multi-tenant-onboarding.md#correr-las-fases-nosotros-runners).
Al aprobar DGII:

```sql
UPDATE gratex_master.tenants SET ambiente = 'ecf' WHERE id = <tenant_id>;
```

Desde ahí las bandejas muestran solo `ecf` y los datos de certificación quedan filtrados.

### URLs que el cliente registra en su directorio DGII

Las mismas para todos los tenants — el sistema resuelve el dueño por el RNC del XML:

| Servicio | URL |
|---|---|
| Recepción | `https://gratex.net/api/ecf/recepcion` |
| Aprobación Comercial | `https://gratex.net/api/ecf/aprobacion-comercial` |
| Autenticación | `https://gratex.net/api/ecf/autenticacion` |

---

## Dónde queda cada cosa

| Dato | Ubicación |
|---|---|
| Registro del tenant | `master.tenants` |
| e-CF emitidos | Sistema del cliente + respaldo en `master.ecf_integracion_backup` |
| e-CF recibidos | `master.ecf_recibidos` (por `tenant_id`) |
| Aprobaciones recibidas | `master.aprobaciones_comerciales` (por `tenant_id`) |
| Certificado `.p12` | `certificado_dgii/<rnc>/cert.p12` (pass cifrada AES-256-GCM) |

## Archivos

`src/Controllers/integracionEcfController.php` (emisión) ·
`integracionAprobacionController.php` (ACECF) · `integracionConsultaController.php` (bandejas) ·
`src/Models/IntegracionStoreModel.php` (persistencia master) ·
`src/Utils/WebhookDispatcher.php` (push HMAC) · `src/Router.php` (case `integracion`).
