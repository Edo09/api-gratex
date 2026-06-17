# API — e-CF Recibidos y Aprobación Comercial

Flujo para **listar los e-CF que otros emisores te enviaron** y luego
**aprobarlos o rechazarlos** ante la DGII (tu rol como comprador).

Controladores:
- `src/Controllers/ecfRecepcionController.php` — recepción y listado de e-CF recibidos
- `src/Controllers/aprobacionComercialOutgoingController.php` — envío de ACECF (aprobación) a la DGII

Base URL (local): `http://localhost:8000`

## ⚠️ Cuidado con los nombres (no confundir)

| Ruta | Sentido | Para qué |
|------|---------|----------|
| `POST /api/ecf/aprobacion-comercial` | **Entrante** (DGII-facing) | Recibe el ACECF que TU comprador envía sobre TU factura |
| `POST /api/aprobaciones-comerciales` | **Saliente** | TÚ apruebas/rechazas la factura de otro emisor → este es el que necesitas |

---

## Flujo

```
GET  /api/ecf/recepcion              → listar e-CF recibidos
POST /api/aprobaciones-comerciales   → aprobar/rechazar cada uno
```

---

## 1. Listar e-CF recibidos

`GET /api/ecf/recepcion`

Lista paginada de los e-CF que otros emisores enviaron a tu empresa. Lee de TU DB
(`ecf_recibidos`), no consulta a la DGII.

- **Auth:** `X-API-KEY: <tu_api_key>` (cliente propio — usar este desde tu frontend).
  Como fallback también acepta `Authorization: Bearer <token>` del flujo seed DGII.
  Si mandas un `X-API-KEY` inválido devuelve `401` (no cae al Bearer).
- **Query params:** `?page=1&pageSize=20`

Respuesta:

```json
{
  "status": true,
  "data": [
    {
      "track_id": "...",
      "tipo_ecf": "31",
      "e_ncf": "E310000000001",
      "rnc_emisor": "...",
      "razon_social_emisor": "...",
      "rnc_comprador": "...",
      "monto_total": 0.0,
      "fecha_emision": "...",
      "estado": "ACEPTADO",
      "ambiente": "ecf",
      "aprobacion_comercial": null,
      "aprobacion_comercial_codigo_dgii": null,
      "aprobacion_comercial_estado_dgii": null,
      "aprobacion_comercial_procesada": null,
      "aprobacion_comercial_fecha": null
    }
  ],
  "pagination": { "page": 1, "pageSize": 20, "total": 0, "totalPages": 0 }
}
```

`estado` = estado **técnico** de recepción (firma). `aprobacion_comercial` = tu
decisión **comercial** enviada a DGII (`ACEPTADO`/`RECHAZADO`). `null` = aún no
respondido → el front debe mostrar **"Pendiente"**, no asumir aprobado.

`ambiente` (`testecf`/`certecf`/`ecf`) = modo del server cuando se recibió el
e-CF (migration 010). Se usa para mandar la aprobación al **mismo ambiente**.

### Consultar uno solo

`GET /api/ecf/recepcion/{trackId}`

- Misma auth que el listado (`X-API-KEY` o Bearer seed).
- Devuelve la fila completa (sin `xml_firmado`).
- `404` si el `trackId` no existe.

---

## 2. Aprobar o rechazar (enviar ACECF a la DGII)

`POST /api/aprobaciones-comerciales`

Como comprador, apruebas o rechazas el e-CF recibido. El servicio arma y firma el
ACECF y lo envía a la DGII.

- **Auth:** `X-API-KEY` (`AuthMiddleware`).
- **Body JSON:**

```json
{
  "rnc_emisor": "...",
  "e_ncf": "E310000000001",
  "fecha_emision": "...",
  "monto_total": "...",
  "estado": "1",
  "detalle_motivo": "..."
}
```

| Campo | Requerido | Notas |
|-------|-----------|-------|
| `rnc_emisor` | sí | RNC del emisor que te facturó |
| `e_ncf` | sí | e-NCF del comprobante recibido |
| `fecha_emision` | sí | |
| `monto_total` | sí | |
| `estado` | sí | `1` = Aceptado, `2` = Rechazado |
| `detalle_motivo` | si `estado=2` | Motivo del rechazo (obligatorio al rechazar) |
| `ambiente` | no | Override (`testecf`/`certecf`/`ecf`). Si se omite, usa el ambiente guardado del e-CF recibido; si tampoco hay, el global `DGII_ECF_ENVIRONMENT` |

Respuesta:

```json
{
  "status": true,
  "data": {
    "rnc_emisor": "...",
    "e_ncf": "E310000000001",
    "estado_aprobacion": "1",
    "track_id": "...",
    "estado_dgii": "...",
    "codigo_seguridad": "...",
    "ambiente": "...",
    "fecha_envio": "...",
    "dgii_response": { }
  }
}
```

Errores:
- `422` — falta un campo requerido, `estado` distinto de `1`/`2`, o `detalle_motivo` vacío con `estado=2`.
- `502` — fallo enviando el ACECF a la DGII.

### Persistencia (migration 009)

Tras enviar, el resultado se guarda con **`UPDATE` sobre la fila del e-CF** en
`ecf_recibidos`, matcheando por `(rnc_emisor, e_ncf)`. **No crea filas nuevas** —
1 e-CF = 1 fila, solo se guarda el último estado (sin historial).

DGII responde `RespuestaAprobacionComercial { codigo, estado, mensaje[] }`:
- `codigo` `1` = aprobación procesada OK; `2`/`02` = no procesada (factura no
  encontrada / error técnico / ambiente que no coincide).
- `estado` `"Aprobacion Comercial Rechazada."` significa que DGII rechazó
  **procesar tu envío**, no que tu rechazo comercial quedó registrado.

**Ambiente (migration 010):** el envío se apunta automáticamente al ambiente en
que se recibió el e-CF (columna `ambiente` de `ecf_recibidos`), evitando el
`codigo 02` por mismatch. Un `ambiente` explícito en el body tiene prioridad.

Columnas que se persisten:

| Columna | Contenido |
|---------|-----------|
| `aprobacion_comercial` | Tu decisión: `ACEPTADO`/`RECHAZADO` |
| `aprobacion_comercial_detalle` | Motivo del rechazo |
| `aprobacion_comercial_codigo_dgii` | `codigo` de DGII |
| `aprobacion_comercial_estado_dgii` | `estado` textual de DGII |
| `aprobacion_comercial_mensaje_dgii` | `mensaje[]` unido por ` \| ` |
| `aprobacion_comercial_procesada` | `1` si `codigo=1`, si no `0` |
| `aprobacion_comercial_fecha` | Fecha/hora del envío |

Notas:
- Persiste en **éxito Y error**: si DGII responde `400`, la respuesta viene
  embebida en el error y se parsea para guardarla igual (con `procesada=0`).
- Si el e-CF **no** está en `ecf_recibidos`, el `UPDATE` afecta 0 filas → no se
  guarda. Si la DB falla (ej. migration 009 sin correr), solo se loguea — no
  rompe la respuesta ni el envío a DGII.

> **Antes de usar en server:** correr en `mtldtmte_new_gratexdb`:
> `db/migrations/009_add_aprobacion_comercial_tracking.sql` y
> `db/migrations/010_add_ambiente_recepcion.sql`.

---

## Forma de las respuestas

| Caso | Forma |
|------|-------|
| Éxito (recurso) | `{ "status": true, "data": { ... } }` |
| Éxito (lista) | `{ "status": true, "data": [ ... ], "pagination": { ... } }` |
| Error | `{ "status": false, "error": "mensaje" }` |
