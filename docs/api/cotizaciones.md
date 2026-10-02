# API — Cotizaciones

Cotizaciones (presupuestos) del tenant: crear, editar, listar, vista previa en PDF,
PDF guardado y eliminar. Las rutas son las mismas para todos los tenants; lo que
cambia es el **formato** de cotización de cada uno (`gratex` o `ferreteria`), que
decide qué cuerpo se acepta, cómo se calculan los totales, cómo se numera y cómo se
ve el PDF.

> **De dónde sale el formato.** Una cotización nueva toma el de la empresa
> (`master.tenants.cotizacion_formato`, master_migration 011, default `gratex`). Una
> que ya existe usa siempre el suyo (`cotizaciones.formato`, tenant migration 026;
> `NULL` = gratex), aunque la empresa cambie de formato después. Arquitectura y cómo
> agregar un formato: [../modules/cotizaciones-formatos.md](../modules/cotizaciones-formatos.md).

Controlador: `src/Controllers/cotizacionController.php` (decide el formato y envuelve
la respuesta) · formatos: `src/Utils/Cotizacion/` · modelo: `src/Models/cotizacionModel.php`.
Base URL (local): `http://localhost:8000`

### Autenticación y permisos

Todas las rutas requieren el header `X-API-KEY` (token de sesión) o
`Authorization: Bearer <token>`, y el módulo **`cotizaciones`** en el rol del usuario
(`config/permissions.php`). Sin credenciales válidas → `401`.

```
X-API-KEY: <tu_api_key>
```

### Forma de las respuestas

| Caso | Forma |
|------|-------|
| Éxito (recurso) | `{ "status": true, "data": ... }` |
| Éxito (lista) | `{ "status": true, "data": [ ... ], "pagination": { ... } }` |
| Error | `{ "status": false, "error": "mensaje" }` |

`error` es siempre un texto para el usuario, en español. El detalle técnico (SQL,
excepciones) va solo al log del servidor.

---

### Endpoints

| Método | Ruta | Descripción | Formato que la atiende |
|--------|------|-------------|------------------------|
| GET | `/api/cotizaciones` | Lista paginada (`?page`, `?pageSize`, `?query`) | — (lectura común) |
| GET | `/api/cotizaciones?id={id}` | Una cotización con sus líneas y ajustes | — (lectura común) |
| GET | `/api/cotizaciones/{id}/pdf` | PDF guardado (`?format=base64` o descarga) | el de la fila |
| POST | `/api/cotizaciones` | Crear | el de la empresa |
| POST | `/api/cotizaciones/preview` | **PDF sin guardar** | el de la fila si el cuerpo trae `id` de una que existe; si no, el de la empresa |
| PUT | `/api/cotizaciones` | Editar (`id` en el cuerpo) | el de la fila |
| DELETE | `/api/cotizaciones` | Eliminar (`id` en el cuerpo) | — (común) |

### Guardia de formato (`409`)

Cada cuerpo de POST, PUT y preview dice con qué formato se armó: el formulario de
Ferretería manda `"formato": "ferreteria"` siempre, y el de Gratex no manda nada
(= `gratex`). Si no coincide con el formato que resolvió el servidor, responde
**`409`** y no guarda nada:

```json
{ "status": false, "error": "La pantalla de cotizaciones está desactualizada (cambió el formato de tu empresa). Recarga la página." }
```

Pasa con una pestaña abierta desde antes de que ops cambiara el formato de la
empresa, o con un bundle viejo del front. Sin esta guardia, un cuerpo de Gratex
(precios con ITBIS incluido) se guardaría con las reglas de Ferretería, que suman el
ITBIS encima.

---

### Lecturas (todos los formatos)

### GET `/api/cotizaciones` — Listar (paginado)

| Param | Default | Notas |
|-------|---------|-------|
| `page` | `1` | Página (1-based) |
| `pageSize` | `10` | Filas por página |
| `query` | — | Busca en `code` (`COT-000123` y `000123` encuentran la misma), y en `client_name`, `rnc`, `company_name`, `phone_number` y `email` del cliente |

Orden: `date DESC, id DESC` (las de la misma fecha, la más nueva primero).

**Respuesta `200`** (una fila de Gratex y una de Ferretería):

```json
{
  "status": true,
  "data": [
    {
      "id": 512, "code": "QWE481", "formato": null, "numero": null,
      "date": "2026-09-30 16:02:11", "client_id": 3511,
      "client_name": "Roselin SRL", "company_name": "Roselin SRL", "rnc": "131234567",
      "subtotal": null, "itbis": null, "total": "3245.75",
      "user_id": 4, "updated_at": null,
      "description": "Banner 3x2 full color\nInstalacion",
      "items": [
        { "id": 9001, "cotizacion_id": 512, "product_id": null, "description": "Banner 3x2 full color",
          "amount": "2360.0000", "quantity": "1.000", "subtotal": "2360.00",
          "unidad_medida": null, "indicador_facturacion": null, "indicador_bien_servicio": null, "itbis_amount": null }
      ],
      "ajustes": {}
    },
    {
      "id": 513, "code": "COT-000001", "formato": "ferreteria", "numero": 1,
      "date": "2026-09-02 10:15:00", "client_id": 123,
      "client_name": "Hospital Moscoso Puello", "company_name": "HOSPITAL DOCENTE DR. FRANCISCO E. MOSCOSO PUELLO", "rnc": "401515131",
      "subtotal": "2020.00", "itbis": "363.60", "total": "3883.60",
      "user_id": 7, "updated_at": null,
      "description": "FUNDAS CEMENTO GRIS\nCORTE DE TUBO",
      "items": [
        { "id": 9002, "cotizacion_id": 513, "product_id": 55, "description": "FUNDAS CEMENTO GRIS",
          "amount": "935.0000", "quantity": "2.000", "subtotal": "1870.00",
          "unidad_medida": "43", "indicador_facturacion": 1, "indicador_bien_servicio": 1, "itbis_amount": "336.60" },
        { "id": 9003, "cotizacion_id": 513, "product_id": null, "description": "CORTE DE TUBO",
          "amount": "150.0000", "quantity": "1.000", "subtotal": "150.00",
          "unidad_medida": "43", "indicador_facturacion": 1, "indicador_bien_servicio": 1, "itbis_amount": "27.00" }
      ],
      "ajustes": { "mano_obra": "1500.00" }
    }
  ],
  "pagination": { "page": 1, "pageSize": 10, "total": 2, "totalPages": 1 }
}
```

Cómo leer la fila:

- **Columnas de la 026** (`formato`, `numero`, `subtotal`, `itbis` y, en cada línea,
  `product_id`, `unidad_medida`, `indicador_facturacion`, `indicador_bien_servicio`,
  `itbis_amount`) aparecen solo cuando la base del tenant ya tiene la migración 026.
  En las filas y líneas de Gratex valen `null`. Un front tiene que tratar "no viene"
  igual que `null`.
- **Montos** (`total`, `subtotal`, `itbis`, `amount`, `quantity`, `itbis_amount`,
  los de `ajustes`): texto DECIMAL, como los entrega MySQL. Convertirlos a número
  antes de operar. Los enteros (`id`, `numero`, `client_id`, indicadores) llegan
  como número.
- **`ajustes`** es **siempre un objeto JSON** con clave = concepto y monto DECIMAL.
  Un concepto que no viene vale 0. Las filas de Gratex traen `{}` sin consultar la
  tabla (una base sin la 026 nunca vacía el listado de Gratex). En Ferretería,
  `retencion_isr` es el **monto** de la retención guardada (no la casilla).
- **`client_name`, `company_name`, `rnc`** vienen del cliente (`LEFT JOIN clients`):
  el `client_name` que se guardó en `cotizaciones` queda tapado por el del JOIN.
- `description`: las descripciones de las líneas unidas con `\n`, para la lista.

### GET `/api/cotizaciones?id={id}` — Obtener una

Mismas claves que el listado. `data` es un **arreglo** con la cotización, o vacío si
el id no existe (sigue siendo `status: true`):

```json
{ "status": true, "data": [ { "id": 513, "code": "COT-000001", "formato": "ferreteria", "...": "..." } ] }
```

### GET `/api/cotizaciones/{id}/pdf` — PDF guardado

- `?format=base64` → `{ "status": true, "data": { "filename": "Cotizacion_<code>.pdf", "content": "<base64>", "mime_type": "application/pdf" } }`.
- Sin `format` (o cualquier otro valor) → el PDF crudo como descarga
  (`Content-Disposition: attachment; filename="Cotizacion_<code>.pdf"`).
- Se imprime con **el formato de la fila**: una de Gratex sale con el PDF de Gratex
  aunque la empresa ya sea `ferreteria`.
- **`404`** si no existe: `"No encontramos esta cotización. Puede que la hayan eliminado; actualiza el listado."`
- **`500`** (solo Ferretería) si el PDF no se pudo generar:
  `"No se pudo generar el PDF de la cotización. Inténtalo de nuevo y, si sigue pasando, avisa a soporte."`

---

### Formato `gratex`

Es el comportamiento de siempre, sin cambios: mismos cuerpos, mismas respuestas,
mismos mensajes, mismo PDF (`src/Utils/CotizacionPdfGenerator.php`) y mismo envío
por correo. El código vive en `src/Utils/Cotizacion/GratexFormato.php`.

### POST `/api/cotizaciones` — Crear

El cuerpo exacto que manda el formulario de Gratex (fiscalo
`CotizacionFormView.tsx`):

```json
{
  "client_id": 3511,
  "items": [
    { "description": "Banner 3x2 full color", "amount": 2360, "quantity": 1, "subtotal": 2360 },
    { "description": "Instalacion", "amount": 590.5, "quantity": 1.5, "subtotal": 885.75 }
  ],
  "total": 3245.75,
  "date": "2026-10-01 10:15:00",
  "user_id": 4,
  "sent_email": false
}
```

| Campo | Req. | Notas |
|-------|------|-------|
| `client_id` | ✅ | Id del cliente |
| `items` | ✅ | ≥ 1 línea: `description` (no vacía), `amount` (precio **con ITBIS incluido**; se guarda redondeado a 4 decimales), `quantity` (> 0, hasta 2 decimales), `subtotal` (opcional; si falta, `amount × quantity` a 2 decimales) |
| `total` | ✅ | Numérico. Se guarda tal cual |
| `date` | ❌ | `''` o ausente = ahora (hora del servidor) |
| `user_id` | ❌ | Se guarda tal cual |
| `sent_email` | ❌ | `true` = genera el PDF y lo envía al correo del cliente (`TenantMail`) |
| `formato` | ❌ | No se manda (= `gratex`). Cualquier otro valor → `409` |

El código es aleatorio (3 letras + 3 dígitos, p. ej. `QWE481`).

**Respuesta `200`:** `{ "status": true, "data": { "id": "512", "code": "QWE481", "message": "Cotization saved" } }`.
Con `sent_email: true`, `message` es `"Cotization saved and emailed"` o el aviso de por
qué no se envió (la cotización ya quedó guardada), p. ej. `"La cotización se guardó,
pero no se envió por correo: el cliente no tiene un correo válido registrado."`.

**Errores** (los de cabecera responden **`200`** con `status: false`, como siempre):

| HTTP | `error` | Cuándo |
|------|---------|--------|
| 200 | `Elige un cliente para la cotización.` | Sin `client_id` o `null` |
| 200 | `Agrega al menos una línea a la cotización.` | Sin `items`, no es arreglo, o vacío |
| 200 | `El total de la cotización no es válido. Revisa los precios y las cantidades.` | Sin `total` o no numérico |
| 422 | `La línea N no tiene descripción. Escríbela o quita esa línea.` | Línea sin descripción |
| 422 | `El precio de la línea N no es válido. Revísalo.` | `amount` ausente o no numérico |
| 422 | `Línea N: la cantidad debe ser mayor que 0.` / `Línea N: la cantidad admite hasta 2 decimales.` | Cantidad |
| 200 | `No se pudo guardar la cotización. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.` | Fallo de la base |
| 409 | (guardia de formato) | El cuerpo trae `formato` distinto de `gratex`, o la empresa es `ferreteria` |

### PUT `/api/cotizaciones` — Editar

El mismo cuerpo con `id` primero (`{ "id": 512, "client_id": ..., "items": [...], "total": ..., "date": ..., "user_id": ..., "sent_email": false }`).
Reemplaza la cabecera y todas las líneas. `date` vacío conserva la guardada.

- **`200`** `{ "status": true, "data": "Cotization updated" }` (o `"Cotization updated and emailed"` / el aviso del correo).
- **`200`** `status: false`:
  - sin `id`: `"No se pudo identificar la cotización que quieres modificar. Ábrela de nuevo desde el listado."`;
  - no existe: `"Esta cotización ya no existe. Puede que la hayan eliminado; vuelve al listado."`;
  - fallo de la base: `"No se pudieron guardar los cambios de la cotización. Inténtalo de nuevo y, si sigue pasando, avisa a soporte."`.
- Mismos `200`/`422` de cabecera y de líneas que el POST.
- **`409`** si la fila es de otro formato (una cotización de Ferretería editada con el formulario de Gratex).

### POST `/api/cotizaciones/preview` — PDF sin guardar

Cuerpo del formulario: `{ "client_id": 3511, "items": [...], "total": 3245.75 }` (sin
`date`: imprime la fecha de hoy). Solo las 3 comprobaciones de cabecera (mismos
textos, `200`); las líneas **no** se validan, como siempre. El PDF lleva el código
`PREVIEW`.

**Respuesta `200`:** `{ "status": true, "data": { "filename": "Cotizacion_Preview.pdf", "content": "<base64>", "mime_type": "application/pdf" } }`.

---

### Formato `ferreteria`

La hoja "COTIZACION MERCANCIAS" de Ferretería (FERREHERRAMIENTAS VENTURA, SRL):
líneas del catálogo (o libres), precios **sin ITBIS** (el ITBIS se suma encima, por
línea), cargos sin ITBIS, retención y abono, numeración propia `COT-000001`. El
código vive en `src/Utils/Cotizacion/FerreteriaFormato.php`; el PDF, en
`src/Utils/Cotizacion/FerreteriaCotizacionPdf.php`. No hay envío por correo.

### POST `/api/cotizaciones` — Crear

```json
{
  "formato": "ferreteria",
  "client_id": 123,
  "date": "2026-09-02 10:15:00",
  "items": [
    { "product_id": 55, "description": "FUNDAS CEMENTO GRIS", "quantity": 2, "amount": 935,
      "unidad_medida": "43", "indicador_facturacion": 1, "indicador_bien_servicio": 1 },
    { "product_id": null, "description": "CORTE DE TUBO", "quantity": 1, "amount": 150 }
  ],
  "ajustes": { "cargos_bancarios": 0, "manejo_bancario": 0, "mano_obra": 1500,
               "abono": 0, "retencion_isr": false }
}
```

| Campo | Req. | Reglas y default |
|-------|------|------------------|
| `formato` | ✅ | `"ferreteria"`. Otro valor o ausente → `409` |
| `client_id` | ✅ | Entero > 0 de un cliente que exista |
| `date` | ❌ | `YYYY-MM-DD HH:MM:SS` o `YYYY-MM-DD` (se le pone la hora actual de RD), fecha real. Ausente o `""`: ahora en POST, la guardada en PUT |
| `items` | ✅ | ≥ 1 línea (abajo) |
| `ajustes` | ❌ | Objeto (abajo). Ausente = ninguno |
| `total`, `sent_email`, `user_id` | — | **Se ignoran**: el total lo calcula el servidor y `user_id` sale del token |

**Línea (`items[]`)**

| Campo | Req. | Reglas y default |
|-------|------|------------------|
| `description` | ✅ | No vacía, ≤ 1000 caracteres. Se recorta, y cada corrida de saltos de línea, tabuladores u otros caracteres de control pasa a **un solo espacio** (en el PDF un salto sería un renglón nuevo); la longitud se cuenta ya limpia |
| `quantity` | ✅ | > 0, hasta 2 decimales, y entera si la unidad no admite fracciones |
| `amount` | ✅ | Precio unitario **sin ITBIS**, > 0, hasta 4 decimales |
| `product_id` | ❌ | `null` = línea libre. Si viene, el producto tiene que existir (no importa si está inactivo) |
| `unidad_medida` | ❌ | Código DGII (`"43"` = Unidad). Ausente o `""` = `"43"`. Se normaliza (`43`, `"043"` → `"43"`) y se valida contra el catálogo de master |
| `indicador_facturacion` | ❌ | 1 = ITBIS 18%, 2 = 16%, 3 = 0%, 4 = exento. Ausente = 1 |
| `indicador_bien_servicio` | ❌ | 1 = bien, 2 = servicio. Ausente = 1. **Con `product_id` se toma del producto** |

**Ajustes (`ajustes`)** — montos fuera de las líneas:

| Clave | Regla | En los totales |
|-------|-------|----------------|
| `cargos_bancarios` | ≥ 0, hasta 2 decimales, `null` = 0 | Se suma al TOTAL, sin ITBIS |
| `manejo_bancario` | Igual | Se suma al TOTAL, sin ITBIS |
| `mano_obra` | Igual | Se suma al TOTAL, sin ITBIS |
| `abono` | Igual, y no puede pasar de lo adeudado | Resta de lo adeudado |
| `retencion_isr` | **Booleano** (`true`/`false`; otro tipo → `422`) | `true` = 5% del Sub-total, restado de lo adeudado |

Cualquier otra clave → `422`. Solo se guardan los montos distintos de cero; la
retención se guarda como monto (`r2(Sub-total × 0.05)`) y se recalcula en cada
guardado.

**Lo que calcula el servidor** (todo redondeado a 2 decimales con
`Redondeo::r2`, igual que la pantalla):

| Monto | Regla |
|-------|-------|
| Base de la línea (`subtotal`) | `r2(r2(quantity) × r4(amount))` |
| ITBIS de la línea (`itbis_amount`) | `r2(base × tasa)`, tasa 0.18 / 0.16 / 0 / 0 |
| Sub-total RD$ (`cotizaciones.subtotal`) | Suma de las bases |
| ITBIS (`cotizaciones.itbis`) | Suma de los ITBIS de las líneas |
| TOTAL RD$ (`cotizaciones.total`) | Sub-total + ITBIS + cargos bancarios + manejo bancario + mano de obra |
| Adeudado | TOTAL − retención |
| Restante (Adeudado) | Adeudado − abono. No se guarda; el PDF lo imprime solo si hay retención o abono |

Numeración: `numero` = el mayor + 1 de la base del tenant, `code` =
`COT-` + 6 cifras (`COT-000123`). Nunca cambian al editar.

**Respuesta `200`:**

```json
{ "status": true, "data": { "id": 513, "code": "COT-000001", "numero": 1, "total": 3883.6 } }
```

### PUT `/api/cotizaciones` — Editar

El mismo cuerpo más `"id"`. Reemplaza cabecera, líneas y **todo el set de
ajustes** (sin `ajustes` = ninguno). `numero` y `code` no cambian. Sin `date` (o
`""`) conserva la fecha y hora guardadas. Responde lo mismo que el POST, con el
`code` y el `numero` de siempre.

- **`409`** si la fila es de otro formato (una de Gratex editada con el formulario de Ferretería), y también si la
  cotización ya no existe: sin fila no hay formato guardado, el servidor resuelve `gratex` y la guardia responde. Al
  recargar, la cotización ya no está en el listado.
- **`404`** `"Esta cotización ya no existe. Puede que la hayan eliminado; vuelve al listado."` solo si la borran en el
  instante entre la lectura de la fila y el guardado.

### POST `/api/cotizaciones/preview` — PDF sin guardar

El mismo cuerpo que el POST, con las **mismas validaciones** (incluido el tope del
abono). Opcional `"id"`: con el id de una cotización guardada usa el formato de esa
fila e imprime su código; sin `id`, en la posición del número imprime
`VISTA PREVIA`. La fecha que imprime es la del cuerpo; si no viene, la guardada; si
tampoco, hoy.

**Respuesta `200`:** `{ "status": true, "data": { "filename": "Cotizacion_Preview.pdf", "content": "<base64>", "mime_type": "application/pdf" } }`.

### PDF (`GET /{id}/pdf` y preview)

Carta vertical, Times: logo, dirección, RNC del emisor, `COTIZACIÓN MERCANCÍAS`,
fecha (`SEPTIEMBRE 2/2026.-`), número, cliente (razón social, si no nombre de la
empresa, si no nombre) y su RNC con guiones, tabla `Cantidad | Descripción
mercancías | Valor Unitario | Valor Total RD$`, la marca "No hay más productos
debajo de la línea", los totales (solo las filas con valor; Sub-total, ITBIS y
TOTAL siempre), "Recibido por:" y el pie con la razón social, el correo y el
teléfono de `emisor_config`. Detalle en
[../modules/cotizaciones-formatos.md](../modules/cotizaciones-formatos.md#el-pdf-de-ferretería).

El PDF guardado recalcula los totales desde las líneas y los ajustes guardados, con
las mismas reglas: lo impreso coincide con lo que se guardó.

### Errores de Ferretería

Todos con `status: false`. `N` es el número de la línea como la ve el usuario (desde 1).

| HTTP | `error` |
|------|---------|
| 422 | `Elige un cliente para la cotización.` (sin `client_id`, no es entero > 0, o el cliente no existe) |
| 422 | `La fecha no es válida.` |
| 422 | `Agrega al menos una línea a la cotización.` |
| 422 | `La línea N no es válida. Quítala y vuelve a agregarla.` |
| 422 | `La línea N no tiene descripción. Escríbela o quita esa línea.` |
| 422 | `La descripción de la línea N es muy larga: admite hasta 1000 caracteres.` |
| 422 | `La unidad de medida de la línea N no es válida. Elige otra unidad en esa línea.` |
| 422 | `Línea N: la cantidad debe ser mayor que 0.` |
| 422 | `Línea N: la unidad «Unidad» no admite fracciones: usa una cantidad entera o cambia la unidad.` |
| 422 | `Línea N: la cantidad admite hasta 2 decimales.` |
| 422 | `El precio de la línea N no es válido. Revísalo.` |
| 422 | `Línea N: el precio debe ser mayor que 0.` |
| 422 | `Línea N: el precio admite hasta 4 decimales.` |
| 422 | `Línea N: el tipo de ITBIS no es válido. Elige 18%, 16%, 0% o exento.` |
| 422 | `Línea N: elige si es un bien o un servicio.` |
| 422 | `Línea N: el producto no es válido. Búscalo de nuevo o déjala como línea libre.` (`product_id` no es entero > 0) |
| 422 | `Línea N: el producto ya no existe en el catálogo. Búscalo de nuevo o déjala como línea libre.` |
| 422 | `Un producto de la cotización ya no existe en el catálogo (lo eliminaron mientras la editabas). Búscalo de nuevo o quita la línea.` (lo borraron entre la revisión y el guardado) |
| 422 | `Los cargos y abonos de la cotización no son válidos. Revísalos.` (`ajustes` no es objeto) |
| 422 | `Los cargos y abonos traen un concepto que este formato no conoce («descuento»).` |
| 422 | `«Costo mano de obra» no es un monto válido. Revísalo.` / `… no puede ser negativo.` / `… admite hasta 2 decimales.` (lo mismo para «Cargos bancarios», «Manejos de operaciones bancarias» y «Abono realizado») |
| 422 | `La casilla «Retención Renta por Tercero 5%» no es válida: tiene que ser sí o no.` |
| 422 | `El abono (RD$ 50,000.00) no puede ser mayor que lo adeudado (RD$ 49,394.80).` |
| 404 | `Esta cotización ya no existe. Puede que la hayan eliminado; vuelve al listado.` (PUT, si la borran mientras se guarda) |
| 409 | `La pantalla de cotizaciones está desactualizada (cambió el formato de tu empresa). Recarga la página.` |
| 500 | `No se pudo revisar la cotización. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.` (no se pudo leer el cliente o los productos) |
| 500 | `No se pudo guardar la cotización. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.` / `No se pudieron guardar los cambios de la cotización. …` |
| 500 | `Otra cotización se guardó al mismo tiempo. Vuelve a guardar.` (el número chocó dos veces seguidas) |
| 500 | `No se pudo generar el PDF de la cotización. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.` |

---

### DELETE `/api/cotizaciones` — Eliminar (todos los formatos)

Body: `{ "id": 513 }`. Borra la cotización; sus líneas y sus ajustes se van con ella
(`ON DELETE CASCADE`). La numeración de Ferretería es el mayor + 1: si se borra la de
número más alto, la siguiente vuelve a usar ese número; una borrada del medio deja
el hueco.

- **`200`** `{ "status": true, "data": "Cotization deleted" }`.
- **`200`** `status: false`:
  - sin `id`: `"No se pudo identificar la cotización que quieres eliminar. Actualiza el listado e inténtalo de nuevo."`;
  - no existe: `"Esta cotización ya no existe. Puede que otra persona la haya eliminado; actualiza el listado."`.

---

### Resumen de códigos HTTP

| HTTP | Significado |
|------|-------------|
| `200` `status: true` | Éxito |
| `200` `status: false` | Gratex: errores de cabecera (cliente, líneas, total), "ya no existe" y fallos del modelo; PUT/DELETE sin `id`; DELETE de una que no existe |
| `401` | Sin token o token inválido |
| `404` | PDF de un id que no existe; PUT de Ferretería si la cotización se borra mientras se guarda |
| `409` | Guardia de formato: la pantalla está desactualizada (también un PUT con cuerpo de Ferretería sobre una cotización que ya no existe) |
| `422` | Validación: líneas de Gratex; todo el cuerpo de Ferretería |
| `500` | Ferretería: fallo al leer el cliente o los productos, al guardar o al generar el PDF |

Pruebas a mano: [../../tests/test_cotizaciones_ferreteria.http](../../tests/test_cotizaciones_ferreteria.http)
(Ferretería, los 422, el 409, la regresión de Gratex y, como comentarios M1-M12, la numeración con creaciones simultáneas y las comprobaciones en la base).
