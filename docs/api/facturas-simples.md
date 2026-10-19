# API — Facturas Simples (no e-CF)

CRUD de **facturas internas que NO se emiten a la DGII** (`tipo_ecf IS NULL`).
No generan e-NCF, XML firmado ni QR de timbre fiscal. Sirven para facturación
interna / comprobantes sin validez fiscal electrónica.

> **Sin impuestos.** Al no ser un comprobante fiscal, una factura simple **no
> lleva ITBIS**: las líneas no tienen tasa ni indicador de facturación, el PDF no
> imprime columna ni total de ITBIS, y `total` es la suma de los subtotales.

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
> **No son comprobantes fiscales.** Sin e-NCF ni NCF, no se envian a la DGII y
> **no entran en el reporte 607** (`Reporte607Model` filtra `tipo_ecf IS NOT NULL`).
> UI: modulo "Facturas simples" del front, separado de Facturacion.

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
      { "id": 9001, "description": "Servicio de diseno grafico", "quantity": "2.000", "amount": "1500.0000", "subtotal": "3000.00" }
    ]
  }
}
```

`quantity` (3 decimales) y `amount` (4) llegan como texto decimal desde la
migración 025: convertirlos a número antes de operar y darles formato al
mostrarlos (`"2.000"` se imprime `2`).

**`404`** si no existe o si el id corresponde a un e-CF emitido:
`{ "status": false, "error": "No encontramos esta factura. Puede que la hayan eliminado; vuelve al listado." }`

> El `404` del `PUT` y del `DELETE` lo decide el código que devuelve el modelo
> (`facturaModel::ERROR_NO_ENCONTRADA`), no el texto: el mensaje se puede
> reescribir sin romper el código HTTP.

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
| `total` | ❌ | Si se omite, suma el `subtotal` de las líneas (sin impuestos) |
| `user_id` | ❌ | Se toma del token; el body solo es respaldo |

**Línea (`items[]`)**

| Campo | Default | Alias aceptado |
|-------|---------|----------------|
| `description` | `""` | `descripcion` |
| `quantity` | `1` | `cantidad` |
| `amount` (precio unitario) | `0` | `precio_unitario` |
| `subtotal` | `quantity * amount - descuento_monto` | — |
| `descuento_monto` | `0` | — |
| `indicador_bien_servicio` | `1` | — |
| `product_id` | `null` (línea libre) | — |
| `unidad_medida` | `"43"` | — |

> `itbis_amount` e `indicador_facturacion` ya **no se aceptan**: se guardan
> siempre en `0` y `1` respectivamente (columnas compartidas con el e-CF, sin
> significado fiscal aquí). Enviarlos no tiene efecto.

**Cantidad y precio.** `quantity` tiene que ser mayor que 0 y con **hasta 3
decimales** (la factura simple no va a la DGII, así que no la ata el límite de 2
del e-CF). Si la unidad de la línea no admite fracciones
(`unidades_medida.permite_decimales = 0`: Unidad, Caja, Par…) tiene que ser
entera. La unidad con la que se juzga es la de la línea si se envía; si no, la
del producto (`product_id`); una línea libre sin ninguna de las dos solo pide
que sea mayor que 0 y con hasta 3 decimales. Si no se cumple responde **422** con el texto para el
usuario, p. ej. `"Línea 2: la unidad «Unidad» no admite fracciones: usa una
cantidad entera o cambia la unidad."`. `amount` se redondea a 4 decimales.

Facturas guardadas antes de la migración 025: su cantidad pudo quedar redondeada
(1,5 guardado como 2), y al abrirlas se muestra la que cuadra con el importe (1,5).
Para que se puedan volver a guardar, en `PUT` una línea con el mismo `product_id` y
la misma cantidad que una línea ya guardada (la guardada o la que cuadra) no se
juzga por la regla de la unidad; sí por "mayor que 0" y "hasta 3 decimales". La
vista previa (`POST /preview`) acepta `factura_id` opcional para aplicar la misma
excepción mientras se edita.
Cantidad y precio quedan en memoria igual que en la base (`quantity`
DECIMAL(12,3), `amount` DECIMAL(18,4)), así que el `subtotal` se calcula con lo
que se guarda. Misma regla en el `PUT` (si trae `items`) y en el `preview`.

**Existencia («Bloquear ventas sin existencia»).** Es un interruptor por empresa, apagado
por defecto (`GET /api/branding` → `inventario_estricto`; se cambia en
`PUT /api/inventario/configuracion`). **Apagado** nada cambia: la factura se guarda, el
descuento del inventario se hace después y la existencia puede quedar en negativo.
**Encendido**, la factura y el descuento del inventario se guardan en **una sola
transacción**: si alguna línea con `product_id` (un Bien, no un servicio) pide más de lo
que hay, la factura **no se guarda** y no se mueve nada. Las líneas libres (sin
`product_id`) y los servicios no se revisan. Si el mismo producto va en varias líneas, se
suman antes de comparar, y una existencia vacía (`NULL`) cuenta como 0.

Respuesta de rechazo por existencia, solo con el interruptor encendido (**`422`**, nunca
`409`):

```json
{
  "status": false,
  "error": "No hay existencia suficiente de «Cemento gris»: pides 5 y hay 2.",
  "codigo": "STOCK_INSUFICIENTE",
  "data": { "faltantes": [
    { "product_id": 12, "nombre": "Cemento gris", "sku": "CEM-01", "unidad_medida": "43",
      "solicitado": 5, "disponible": 2, "faltante": 3 } ] }
}
```

`solicitado`, `disponible` y `faltante` son números JSON, no textos. `disponible` nunca es
negativo (un saldo de 0, vacío o negativo se informa como 0); `faltante` es `solicitado`
menos la existencia real (vacía = 0), así que un saldo negativo hace que `faltante` sea
mayor que `solicitado − disponible`. `faltantes` va ordenado por `product_id` (si otra venta
se lleva la existencia justo entre la revisión y el bloqueo, el `422` puede nombrar solo el
primer producto que falta). Un `product_id` que no existe, o una cantidad ≤ 0 en una línea
de producto, **no** son faltantes: dan un `422` llano, `{ "status": false, "error": "…" }`,
sin `codigo` ni `data`.

**Si el libro falla, no se guarda (`400`).** Con el interruptor encendido, un fallo del libro de
inventario dentro de la transacción (error de base, espera de bloqueo vencida) aborta el
guardado: la factura **no se guarda** y la respuesta es **`400`** con «No se pudieron
actualizar las existencias. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.» (el
detalle va al log del servidor). Es lo contrario del e-CF, donde un fallo de la revisión se
anota y la venta sigue: la factura simple descuenta dentro de su propia transacción, así que
no puede quedar guardada sin descontar.

**Ejemplo**

```json
{
  "client_id": 3511,
  "date": "2026-06-01",
  "NCF": "B0100000123",
  "items": [
    { "description": "Servicio de diseno grafico", "quantity": 2, "amount": 1500 },
    { "description": "Impresion full color",        "quantity": 1, "amount": 800 }
  ]
}
```

**Respuesta `201`**: `{ "status": true, "data": { ...factura creada con items... } }`
**Errores**: `422` (falta cliente o items, o la cantidad de una línea no es válida; con el interruptor de existencia encendido, también `codigo: "STOCK_INSUFICIENTE"` con `data.faltantes`, o un `422` llano si un `product_id` no existe o una cantidad es ≤ 0), `401` (sin usuario), `400` (fallo al crear; con el interruptor de existencia encendido, también un fallo del libro de inventario dentro de la transacción, y entonces no se guarda nada).

---

### POST `/api/facturas-simples/preview` — PDF previo (sin guardar)

Genera el PDF de la factura **desde el body, sin persistirla**. Usa el diseño
de **factura NO electrónica**: título "Factura", etiqueta "Factura No." (y "NCF"
si se envía), **sin** fecha de vencimiento, **sin columna ni total de ITBIS** y
**sin QR de timbre fiscal DGII** (eso es exclusivo del e-CF). Las líneas pasan
por el mismo normalizador que el guardado, así que la vista previa y el
documento final coinciden.

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
    { "description": "Servicio de diseno grafico", "quantity": 2, "amount": 1500 }
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

**Errores**: `422` (falta cliente o items, o la cantidad de una línea no es válida).

---

### PUT `/api/facturas-simples/{id}` — Actualizar

`id` puede ir en la ruta o en el body. Campos no enviados conservan su valor.
Si se envía `items`, **reemplaza todas las líneas** (debe traer ≥ 1).

> **Inventario.** Reemplazar las líneas devuelve al almacén la mercancía vieja y
> descuenta la nueva: quedan dos movimientos en el kardex (`DEVOLUCION` +
> `VENTA`) en vez de un salto de saldo sin explicar.
>
> **Existencia.** Con el interruptor «Bloquear ventas sin existencia» **encendido**
> (ver el `POST`), y solo cuando el `PUT` trae `items`, la edición y el movimiento
> del inventario van en **una sola transacción**: primero se devuelven al almacén las
> líneas que la factura tenía guardadas, después se revisan las nuevas y se
> descuentan. Por eso solo el **aumento neto** puede faltar: volver a guardar con la
> misma cantidad y existencia 0 pasa, y subir de 3 a 5 con existencia 1 falta 1, no 4.
> (Con la existencia del producto ya **bajo 0** por otras ventas, volver a guardar sin
> cambiar nada también se rechaza: lo que la factura devuelve no alcanza para volver a
> sacarlo.)
> Si falta, el `PUT` completo se deshace (datos y líneas) y responde `422` con
> `codigo: "STOCK_INSUFICIENTE"` y `data.faltantes` (misma forma que en el `POST`); un
> `product_id` inexistente o una cantidad ≤ 0 dan el `422` llano. Si el libro de
> inventario falla dentro de la transacción, el `PUT` también se deshace y responde
> `400` (ver el `POST`). Un `PUT` sin `items` no mueve existencia. Con el interruptor
> apagado, la devolución y el descuento se hacen después de guardar, como siempre.
>
> **Crédito.** `tipo_pago = 2` exige que el cliente tenga `permitir_credito`. Si
> el body no reenvía `client_id`, se valida contra el cliente ya guardado en la
> factura.

```json
{ "no_factura": "0001-MOD", "items": [ { "description": "Ajuste", "quantity": 3, "amount": 1500 } ] }
```

**Respuestas**: `200` (ok), `404` (no existe), `409` (la factura viene de una venta del POS: «Esta factura viene de una venta del POS y no se puede editar ni eliminar.»), `400` (fallo; con el interruptor de existencia encendido y `items`, también un fallo del libro de inventario dentro de la transacción: el `PUT` completo se deshace), `422` (id o items inválidos, o la cantidad de una línea no es válida; con el interruptor de existencia encendido, también `codigo: "STOCK_INSUFICIENTE"` con `data.faltantes`, o un `422` llano si un `product_id` no existe o una cantidad es ≤ 0).

---

### DELETE `/api/facturas-simples/{id}` — Eliminar

`id` en ruta o body. **No** elimina e-CF emitidos (devuelve error) ni las facturas simples de respaldo de una venta del POS (`pos_idempotency_key` no nulo: `409`).

> **Inventario.** Al borrar se revierte la venta: lo que salió del almacén
> vuelve (movimiento `DEVOLUCION`). Un e-CF no se borra — se corrige con una
> nota de crédito E34, que ya entra mercancía por su propia vía.

**Respuestas**: `200` (ok), `404` (no existe), `409` (viene de una venta del POS), `400` (es e-CF / fallo), `422` (sin id).
