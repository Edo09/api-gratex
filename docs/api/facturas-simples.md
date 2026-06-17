# API — Facturas Simples (no e-CF)

CRUD de **facturas internas que NO se emiten a la DGII** (`tipo_ecf IS NULL`).
No generan e-NCF, XML firmado ni QR de timbre fiscal. Sirven para facturación
interna / comprobantes sin validez fiscal electrónica.

Controlador: `src/Controllers/facturaSimpleController.php`
Base URL (local): `http://localhost:8000`

## Autenticación

Todas las rutas requieren el header `X-API-KEY` (cliente propio) o `Authorization: Bearer <token>`.
Sin credenciales válidas → `401`.

```
X-API-KEY: <tu_api_key>
```

## Forma de las respuestas

| Caso | Forma |
|------|-------|
| Éxito (recurso) | `{ "status": true, "data": { ... } }` |
| Éxito (lista) | `{ "status": true, "data": [ ... ], "pagination": { ... } }` |
| Error | `{ "status": false, "error": "mensaje" }` |

---

## Endpoints

| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `/api/facturas-simples` | Lista paginada (`?page`,`?pageSize`,`?query`) |
| GET | `/api/facturas-simples/{id}` | Una factura con sus líneas |
| GET | `/api/facturas-simples?id={id}` | Idem (id por query param) |
| POST | `/api/facturas-simples` | Crear |
| POST | `/api/facturas-simples/preview` | **PDF previo sin guardar** |
| PUT | `/api/facturas-simples/{id}` | Actualizar (id también válido en el body) |
| DELETE | `/api/facturas-simples/{id}` | Eliminar (id también válido en el body) |

---

### GET `/api/facturas-simples` — Listar (paginado)

Query params (todos opcionales):

| Param | Default | Notas |
|-------|---------|-------|
| `page` | `1` | Página (1-based) |
| `pageSize` | `10` | Filas por página |
| `query` | — | Busca en `no_factura`, `NCF`, `client_name`, `company_name` |

**Respuesta `200`**

```json
{
  "status": true,
  "data": [
    {
      "id": 1285,
      "no_factura": "0917-020626",
      "date": "2026-06-01",
      "client_id": 3511,
      "client_name": "Roselin SRL",
      "company_name": "Roselin SRL",
      "total": "4484.00",
      "NCF": "B0100000123",
      "tipo_ecf": null,
      "user_id": 4,
      "description": "Servicio de diseno grafico\nImpresion full color"
    }
  ],
  "pagination": { "page": 1, "pageSize": 10, "total": 42, "totalPages": 5 }
}
```

> `description` = descripciones de las líneas concatenadas (separadas por `\n`),
> como resumen para la lista. Puede truncarse en facturas con muchas líneas; el
> detalle completo (`items[]`) viene en `GET /api/facturas-simples/{id}`.

---

### GET `/api/facturas-simples/{id}` — Obtener una

También acepta `?id={id}`. Devuelve la factura con sus líneas (`items`) y datos
del cliente (`company_name`, `client_email`, `client_phone`, `client_rnc`).

**Respuesta `200`**

```json
{
  "status": true,
  "data": {
    "id": 1285,
    "no_factura": "0917-020626",
    "date": "2026-06-01",
    "client_id": 3511,
    "client_name": "Roselin SRL",
    "company_name": "Roselin SRL",
    "client_email": "info@roselin.do",
    "client_phone": "809-555-0100",
    "client_rnc": "131234567",
    "total": "4484.00",
    "NCF": "B0100000123",
    "tipo_ecf": null,
    "items": [
      { "id": 9001, "description": "Servicio de diseno grafico", "quantity": "2", "amount": "1500.00", "subtotal": "3000.00" }
    ]
  }
}
```

**`404`** si no existe o si el id corresponde a un e-CF emitido:
`{ "status": false, "error": "Factura no encontrada" }`

---

### POST `/api/facturas-simples` — Crear

Body (JSON):

| Campo | Req. | Notas |
|-------|------|-------|
| `client_id` | ⚠️ | Requerido `client_id` **O** `client_name` |
| `client_name` | ⚠️ | Se autocompleta desde `client_id` si se omite |
| `items` | ✅ | Arreglo con ≥ 1 línea (ver abajo) |
| `no_factura` | ❌ | El backend lo genera (`{secuencia}-{ddmmaa}`) si se omite |
| `date` | ❌ | Default: ahora |
| `NCF` | ❌ | NCF tradicional (no e-CF) |
| `total` | ❌ | Si se omite, suma `subtotal + itbis_amount` de las líneas |
| `user_id` | ❌ | Se toma del token; el body solo es respaldo |

**Línea (`items[]`)**

| Campo | Default | Alias aceptado |
|-------|---------|----------------|
| `description` | `""` | `descripcion` |
| `quantity` | `1` | `cantidad` |
| `amount` (precio unitario) | `0` | `precio_unitario` |
| `subtotal` | `quantity * amount` | — |
| `itbis_amount` | `0` | — |
| `indicador_facturacion` | `1` | — |
| `indicador_bien_servicio` | `1` | — |

**Ejemplo**

```json
{
  "client_id": 3511,
  "date": "2026-06-01",
  "NCF": "B0100000123",
  "items": [
    { "description": "Servicio de diseno grafico", "quantity": 2, "amount": 1500, "itbis_amount": 540 },
    { "description": "Impresion full color",        "quantity": 1, "amount": 800,  "itbis_amount": 144 }
  ]
}
```

**Respuesta `201`**: `{ "status": true, "data": { ...factura creada con items... } }`
**Errores**: `422` (falta cliente o items), `401` (sin usuario), `400` (fallo al crear).

---

### POST `/api/facturas-simples/preview` — PDF previo (sin guardar)

Genera el PDF de la factura **desde el body, sin persistirla**. Usa el diseño
de **factura NO electrónica**: título "Factura", etiqueta "Factura No." (y "NCF"
si se envía), **sin** fecha de vencimiento y **sin QR de timbre fiscal DGII**
(eso es exclusivo del e-CF).

Mismo body que el `POST` de creación (acepta `client_id` **o** `client_name`,
e `items` con ≥ 1 línea). `no_factura` y `total` son opcionales.

**Formato de salida** — vía `?format=` (query) o `"format"` (body):

| `format` | Resultado |
|----------|-----------|
| `base64` (default) | JSON con el PDF en base64 |
| `download` | PDF crudo (`application/pdf`) como adjunto |

**Request**

```http
POST /api/facturas-simples/preview
X-API-KEY: <tu_api_key>
Content-Type: application/json

{
  "client_id": 3511,
  "date": "2026-06-01",
  "items": [
    { "description": "Servicio de diseno grafico", "quantity": 2, "amount": 1500, "itbis_amount": 540 }
  ]
}
```

**Respuesta `200` (base64)**

```json
{
  "status": true,
  "data": {
    "filename": "Preview_factura_simple.pdf",
    "content": "JVBERi0xLjcKJ...",
    "mime_type": "application/pdf"
  }
}
```

Render en el front (base64):

```js
const res = await fetch('/api/facturas-simples/preview', {
  method: 'POST',
  headers: { 'X-API-KEY': apiKey, 'Content-Type': 'application/json' },
  body: JSON.stringify(payload),
});
const { data } = await res.json();
const blob = await (await fetch(`data:${data.mime_type};base64,${data.content}`)).blob();
window.open(URL.createObjectURL(blob)); // o <embed src=...>
```

Descarga directa (`?format=download`): apunta un `<a href>` / `window.open` a la
URL con el header de auth, la respuesta es el PDF binario.

**Errores**: `422` (falta cliente o items).

---

### PUT `/api/facturas-simples/{id}` — Actualizar

`id` puede ir en la ruta o en el body. Campos no enviados conservan su valor.
Si se envía `items`, **reemplaza todas las líneas** (debe traer ≥ 1).

```json
{ "no_factura": "0001-MOD", "items": [ { "description": "Ajuste", "quantity": 3, "amount": 1500, "itbis_amount": 810 } ] }
```

**Respuestas**: `200` (ok), `404` (no existe), `400` (fallo), `422` (id o items inválidos).

---

### DELETE `/api/facturas-simples/{id}` — Eliminar

`id` en ruta o body. **No** elimina e-CF emitidos (devuelve error).

**Respuestas**: `200` (ok), `404` (no existe), `400` (es e-CF / fallo), `422` (sin id).
