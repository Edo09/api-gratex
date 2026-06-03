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

Lista paginada de los e-CF que otros emisores enviaron a tu empresa.

- **Auth:** `Authorization: Bearer <token>` (flujo seed DGII, `authSeedModel`). NO usa `X-API-KEY`.
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
      "codigo_resultado": 1
    }
  ],
  "pagination": { "page": 1, "pageSize": 20, "total": 0, "totalPages": 0 }
}
```

### Consultar uno solo

`GET /api/ecf/recepcion/{trackId}`

- Mismo Bearer token.
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

---

## Forma de las respuestas

| Caso | Forma |
|------|-------|
| Éxito (recurso) | `{ "status": true, "data": { ... } }` |
| Éxito (lista) | `{ "status": true, "data": [ ... ], "pagination": { ... } }` |
| Error | `{ "status": false, "error": "mensaje" }` |
