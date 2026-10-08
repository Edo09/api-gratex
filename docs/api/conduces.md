# API — Conduces de mercancía

Conduces de mercancía del formato de cotización **Ferretería**: la nota de entrega que va
con la mercancía y que el cliente firma ("Recibido por"). Sale de una cotización de
Ferretería, lleva su propio número (`CON-000001`) y se imprime como la cotización, pero
**sin precios ni totales**. Cada línea guarda por dentro su precio y su ITBIS, solo para
facturar desde el conduce.

Tres reglas definen el módulo:

- **Solo Ferretería.** Las rutas atienden solo a empresas con
  `master.tenants.cotizacion_formato = 'ferreteria'`; a cualquier otra le responden `422`
  ([abajo](#autenticación-permisos-y-disponibilidad)).
- **Solo desde una cotización.** No hay conduce en blanco: el `POST` exige la cotización de
  origen. Una cotización puede dar todos los conduces que hagan falta (uno por entrega); no
  se lleva la cuenta de lo entregado contra lo cotizado.
- **Nada se borra.** Eliminar pone `conduces.activo = 0`; editar pone `activo = 0` a las
  líneas de antes e inserta las nuevas. Por eso un número `CON-…` **nunca se vuelve a
  usar**, ni después de eliminar el conduce.

Controlador: `src/Controllers/conduceController.php` (revisa la petición, elige la acción,
escribe la auditoría y envuelve la respuesta) · reglas, disponibilidad y armado del PDF:
`src/Utils/Cotizacion/FerreteriaConduce.php` · modelo: `src/Models/conduceModel.php` · PDF:
`src/Utils/Cotizacion/FerreteriaCotizacionPdf.php` (modo conduce) · tablas: migración de
tenant 031 ([../database/schema.md](../database/schema.md)). El formato Ferretería y cómo
encaja el conduce: [../modules/cotizaciones-formatos.md](../modules/cotizaciones-formatos.md#conduces-de-mercancía-ferretería).
Base URL (local): `http://localhost:8000`

### Autenticación, permisos y disponibilidad

Header `X-API-KEY: <token de sesión>` o `Authorization: Bearer <token>`, como en
cotizaciones. Las revisiones van en este orden y responde la primera que falla:

| # | Revisión | Si falla |
|---|----------|----------|
| 1 | Token de sesión válido (lo revisa el `PermissionGate` del Router con `PERMISSIONS_ENFORCE=true`, y siempre el `validateRequest()` del controller) | `401` |
| 2 | El rol tiene el módulo **`cotizaciones`**. `/api/conduces` no tiene módulo propio: `config/permissions.php` mapea `'conduces' => 'cotizaciones'` | `403` (con `PERMISSIONS_ENFORCE=true`; en sombra solo se registra) |
| 3 | La empresa está en el formato `ferreteria` (`FerreteriaConduce::errorDisponibilidad()`, sobre `CotizacionFormatos::delTenant()`) | `422` `Los conduces no están disponibles para tu empresa.` |
| 4 | La ruta existe (método + sub-ruta) | `404` `Esta dirección de conduces no existe.` |
| 5 | El cuerpo y las reglas de cada ruta | `422` / `404` / `500` ([errores](#errores)) |

- El `401` llega **antes** que cualquier `422`: un token vencido recibe `401` aunque la
  empresa sea Gratex o el cuerpo traiga errores.
- El `422` de disponibilidad sale en **todas** las rutas, antes de leer el cuerpo y sin
  abrir la DB del tenant. Cubre Gratex, un formato que este código no conoce (que además
  deja su aviso en el log) y una instalación de un solo tenant
  (`MULTI_TENANT_ENABLED=false`).
- `OPTIONS` (el preflight de CORS) lo responde el Router, sin token.

### Forma de las respuestas

| Caso | Forma |
|------|-------|
| Éxito (recurso) | `{ "status": true, "data": ... }` |
| Éxito (lista) | `{ "status": true, "data": [ ... ], "pagination": { ... } }` |
| Error | `{ "status": false, "error": "mensaje" }`, con su código HTTP |

A diferencia de Gratex en cotizaciones, **ningún error responde `200`**. `error` es siempre
un texto para el usuario, en español; el detalle técnico (SQL, excepciones) va solo al log
del servidor, con el prefijo `[conduces]`.

```json
{ "status": false, "error": "Los conduces no están disponibles para tu empresa." }
```

---

### Endpoints

| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `/api/conduces` | Lista paginada de los conduces activos (`?page`, `?pageSize`, `?query`) |
| GET | `/api/conduces?id={id}` | Un conduce activo, con sus líneas activas |
| GET | `/api/conduces/{id}/pdf` | PDF guardado (`?format=base64` o descarga) |
| POST | `/api/conduces` | Crear desde una cotización de Ferretería |
| POST | `/api/conduces/preview` | **PDF sin guardar**: de uno nuevo, o de uno guardado si el cuerpo trae `id` |
| PUT | `/api/conduces` | Editar (`id` en el cuerpo) |
| DELETE | `/api/conduces` | Eliminar (`id` en el cuerpo): `activo = 0`, no borra nada |

Cualquier otra combinación (`POST /api/conduces/7/pdf`, `GET /api/conduces/preview`,
`DELETE /api/conduces/7`, `PATCH`…) responde `404` `Esta dirección de conduces no existe.`
sin tocar nada: una petición mal dirigida nunca crea ni cambia un conduce.

---

### La fila de un conduce

Lo que devuelven el listado y `?id=`:

```json
{
  "id": 41, "numero": 7, "code": "CON-000007", "date": "2026-10-05 09:30:00",
  "cotizacion_id": 513, "cotizacion_code": "COT-000001",
  "client_id": 123, "client_name": "Hospital Moscoso Puello",
  "company_name": "HOSPITAL DOCENTE DR. FRANCISCO E. MOSCOSO PUELLO", "rnc": "401515131",
  "client_name_guardado": "HOSPITAL DOCENTE DR. FRANCISCO E. MOSCOSO PUELLO",
  "user_id": 7, "activo": 1, "created_at": "2026-10-05 09:31:02", "updated_at": null,
  "items": [
    { "id": 301, "conduce_id": 41, "product_id": 55, "description": "FUNDAS CEMENTO GRIS",
      "quantity": "2.000", "unidad_medida": "43", "amount": "935.0000",
      "indicador_facturacion": 1, "indicador_bien_servicio": 1, "activo": 1 },
    { "id": 302, "conduce_id": 41, "product_id": null, "description": "CORTE DE TUBO",
      "quantity": "1.000", "unidad_medida": "43", "amount": "0.0000",
      "indicador_facturacion": 1, "indicador_bien_servicio": 1, "activo": 1 }
  ]
}
```

Cómo leer la fila:

- **`client_name`, `company_name` y `rnc`** son los del cliente **de hoy** (`LEFT JOIN
  clients`). Valen `null` si el cliente se borró (`conduces.client_id` no tiene FK).
- **`client_name_guardado`** es el nombre que se guardó al crear o editar: la razón social
  del cliente, si no el nombre de la empresa, si no el nombre, recortado a 100. Sirve
  cuando el cliente ya no existe. El nombre para mostrar es
  `client_name || client_name_guardado` (así lo usa el front en la lista, el formulario y
  Facturar).
- **`cotizacion_code`** es el código de la cotización de origen. `cotizacion_id` y
  `cotizacion_code` valen `null` si esa cotización se eliminó (FK `ON DELETE SET NULL`): el
  conduce sigue igual y la pantalla dice "eliminada".
- **`items`** son solo las líneas **activas**, en el orden en que se guardaron. Las de antes
  de cada edición siguen en la base con `activo = 0` y la API no las devuelve.
- **`amount`** es el precio unitario **sin ITBIS**. Con los dos indicadores, solo sirve
  para facturar desde el conduce: ni la pantalla del conduce ni su PDF lo muestran. Una
  línea libre lleva `"0.0000"`.
- **Montos y cantidades** (`quantity`, `amount`): texto DECIMAL, como los entrega MySQL.
  Convertirlos a número antes de operar. Los enteros (`id`, `numero`, `client_id`,
  `user_id`, `activo`, los indicadores) llegan como número.
- **`activo`** es siempre `1` en lo que devuelve la API: un conduce eliminado no sale ni en
  la lista ni en `?id=`.
- **`user_id`** es el usuario que lo creó o lo editó por última vez (del token).

### GET `/api/conduces` — Listar (paginado)

| Param | Default | Notas |
|-------|---------|-------|
| `page` | `1` | Página (1-based). Lo que como entero no llega a 1 (`0`, negativo, `0.5`, texto) vale `1` |
| `pageSize` | `10` | Filas por página. Igual: lo que no llega a 1 vale `10` |
| `query` | — | Busca (`LIKE`) en el código (`CON-000007` y `000007` encuentran el mismo), en el nombre guardado y en el `client_name`, `company_name` y `rnc` del cliente. Sin espacios en los extremos; vacío = sin búsqueda |

Solo conduces activos. Orden: `date DESC, id DESC` (los de la misma fecha, el más nuevo
primero).

**Respuesta `200`:**

```json
{
  "status": true,
  "data": [ { "id": 41, "numero": 7, "code": "CON-000007", "...": "...", "items": [ "..." ] } ],
  "pagination": { "page": 1, "pageSize": 10, "total": 1, "totalPages": 1 }
}
```

`totalPages` es un entero (`0` si no hay ninguno).

### GET `/api/conduces?id={id}` — Obtener uno

Mismas claves que el listado. `data` es un **arreglo** con el conduce, o vacío si el id no
existe, está eliminado o no es un entero mayor que 0 (sigue siendo `status: true`, como el
`?id=` de cotizaciones):

```json
{ "status": true, "data": [ { "id": 41, "code": "CON-000007", "...": "..." } ] }
```

### POST `/api/conduces` — Crear (desde una cotización)

El cuerpo que manda el formulario del conduce (fiscalo `ConduceForm.tsx`): la cotización de
origen, el cliente y las líneas ya editadas.

```json
{
  "cotizacion_id": 513,
  "client_id": 123,
  "date": "2026-10-05 09:30:00",
  "items": [
    { "product_id": 55, "description": "FUNDAS CEMENTO GRIS", "quantity": 2,
      "unidad_medida": "43", "amount": 935, "indicador_facturacion": 1, "indicador_bien_servicio": 1 },
    { "product_id": null, "description": "CORTE DE TUBO", "quantity": 1,
      "unidad_medida": "43", "amount": 0, "indicador_facturacion": 1, "indicador_bien_servicio": 1 }
  ]
}
```

| Campo | Req. | Reglas y default |
|-------|------|------------------|
| `cotizacion_id` | ✅ | Entero > 0 de una cotización que exista **con `formato = 'ferreteria'`**. Una de Gratex (`formato` `NULL`), una que no existe o un id que no es entero → `422` |
| `client_id` | ✅ | Entero > 0 de un cliente que exista. Puede ser otro que el de la cotización |
| `date` | ❌ | `YYYY-MM-DD HH:MM:SS` o `YYYY-MM-DD` (se le pone la hora actual de RD), fecha real. Ausente o `""`: ahora (hora de RD) |
| `items` | ✅ | ≥ 1 línea (abajo) |
| `ajustes` | — | **No se acepta**: la clave sola, aunque valga `null` o `{}`, responde `422` `Los conduces no llevan cargos ni abonos.` |
| `formato`, `tipo`, `total`, `sent_email`, `user_id` y cualquier otra | — | **Se ignoran**. `user_id` sale del token |

**Línea (`items[]`)**: las reglas y los textos de las líneas de la cotización de Ferretería
(`FerreteriaFormato::normalizarLinea`), con **una** diferencia: el precio puede ser 0.

| Campo | Req. | Reglas y default |
|-------|------|------------------|
| `description` | ✅ | No vacía, ≤ 1000 caracteres. Se recorta, y cada corrida de saltos de línea, tabuladores u otros caracteres de control pasa a un solo espacio |
| `quantity` | ✅ | > 0, hasta 2 decimales, y entera si la unidad no admite fracciones |
| `amount` | ✅ | Precio unitario **sin ITBIS**, **≥ 0** (0 = sin precio, como una línea libre), hasta 4 decimales. Negativo → `422`. Nunca se imprime: se guarda para Facturar |
| `product_id` | ❌ | `null` = línea libre. Si viene, el producto tiene que existir (no importa si está inactivo) |
| `unidad_medida` | ❌ | Código DGII (`"43"` = Unidad). Ausente o `""` = `"43"`. Se normaliza (`43`, `"043"` → `"43"`) y se valida contra el catálogo de master |
| `indicador_facturacion` | ❌ | 1 = ITBIS 18%, 2 = 16%, 3 = 0%, 4 = exento. Ausente = 1 |
| `indicador_bien_servicio` | ❌ | 1 = bien, 2 = servicio. Ausente = 1. **Con `product_id` se toma del producto**: decide el tipo de ítem y el inventario al facturar |

Lo que hace el servidor, en orden:

1. Revisa la forma del cuerpo, sin DB: `ajustes`, `cotizacion_id`, `client_id`, `date`,
   `items` y cada línea. Responde el primer error.
2. Revisa contra la DB del tenant: la cotización de origen (que exista y sea de Ferretería),
   el cliente y los productos.
3. Numera, guarda la cabecera y las líneas en una transacción ([numeración](#numeración-nunca-se-reusa)).
   `client_name` guarda la razón social del cliente, si no el nombre de la empresa, si no
   el nombre (recortado a 100), y `user_id` el usuario del token.

**Respuesta `200`:**

```json
{ "status": true, "data": { "id": 41, "code": "CON-000007", "numero": 7 } }
```

### Numeración (nunca se reusa)

`numero` sale de `conduce_secuencia`, una tabla de una sola fila (`id = 1`) con el último
número dado. `conduceModel::crear()`:

1. `INSERT IGNORE INTO conduce_secuencia (id, ultimo) VALUES (1, 0)` justo **antes** de la
   transacción: la fila existe aunque la semilla de la 031 no se haya corrido. Va fuera a
   propósito: dentro de la transacción, el candado compartido de ese `INSERT` duraría hasta
   el commit, y dos guardados a la vez se esperarían entre sí (deadlock `1213`).
2. En la transacción, `SELECT ultimo FROM conduce_secuencia WHERE id = 1 FOR UPDATE`: el
   candado de esa fila pone en fila los guardados simultáneos, sin `GET_LOCK`.
3. `numero = GREATEST(ultimo, MAX(numero)) + 1` (el `MAX` de **todas** las filas de
   `conduces`, activas o no) y `UPDATE conduce_secuencia SET ultimo = numero`. `code` =
   `CON-` + el número a 6 cifras (`CON-000007`; uno más largo no se corta).
4. Inserta la cabecera y las líneas, y hace commit.

- Como ninguna fila se borra, un número dado no vuelve a salir: eliminar el conduce de
  número más alto **no** lo libera (al revés que las cotizaciones de Ferretería, que
  numeran con el mayor + 1).
- **Red de seguridad:** si aun así el número choca con `UNIQUE uk_conduces_numero`
  (`1062`), se deshace y se reintenta **una** vez en una transacción nueva. Un segundo
  choque responde `500` `Otro conduce se guardó al mismo tiempo. Vuelve a guardar.`
- **No se reintentan:** un `1452` sobre `conduce_items_product_fk` (borraron un producto
  entre la revisión y el guardado) y uno sobre `conduces_cotizacion_fk` (borraron la
  cotización de origen) responden su `422`; cualquier otro error, `500`.
- Editar nunca cambia `numero`, `code` ni `cotizacion_id`.

### PUT `/api/conduces` — Editar

El cuerpo del POST con `"id"`. `cotizacion_id` no hace falta y, si viene, **se ignora**: un
conduce nunca cambia de cotización de origen.

```json
{
  "id": 41,
  "client_id": 123,
  "items": [
    { "product_id": 55, "description": "FUNDAS CEMENTO GRIS", "quantity": 1,
      "unidad_medida": "43", "amount": 935, "indicador_facturacion": 1, "indicador_bien_servicio": 1 }
  ]
}
```

- **Fecha:** sin `date` (o `""`) conserva la fecha y la hora guardadas
  (`date = COALESCE(:date, date)`, como las cotizaciones); con `YYYY-MM-DD`, ese día con la
  hora actual de RD. El formulario manda la fecha solo cuando el usuario cambió el día.
- **Líneas:** en una transacción, relee la fila (`FOR UPDATE`, solo si está activa),
  actualiza la cabecera (`client_id`, `client_name`, `date`, `user_id`, `updated_at`), pone
  `activo = 0` a las líneas activas e **inserta** las nuevas. No se borra ninguna línea.
- **Cliente:** tiene que existir también al editar. Si borraron el guardado, `422`
  `Elige un cliente para el conduce.` (hay que elegir otro).
- **`200`** `{ "status": true, "data": { "id": 41, "code": "CON-000007", "numero": 7 } }`, con
  el código y el número de siempre.
- **`404`** `Este conduce ya no existe. Puede que lo hayan eliminado; vuelve al listado.` si
  no existe o está eliminado, **antes** de revisar el cuerpo. También si lo eliminan entre la
  lectura y el guardado: entonces no se escribe nada.
- **`422`** `No se pudo identificar el conduce que quieres modificar. Ábrelo de nuevo desde el listado.`
  sin `id`, o con uno que no es entero mayor que 0.
- El resto de las revisiones y sus textos son los del POST.

### DELETE `/api/conduces` — Eliminar (sin borrar)

Body: `{ "id": 41 }`. Hace `UPDATE conduces SET activo = 0, updated_at = <ahora> WHERE id = ?
AND activo = 1`: el conduce deja de salir en la lista, en `?id=` y en el PDF, y su número no
se vuelve a usar. Sus líneas se quedan como estaban, unidas al conduce inactivo. Nada se
borra, y la base tampoco lo permite: la FK `conduce_items_conduce_fk` es `ON DELETE
RESTRICT`.

- **`200`** `{ "status": true, "data": "Conduce eliminado" }`.
- **`404`** `Este conduce ya no existe. Puede que lo hayan eliminado; vuelve al listado.` si
  no existe o ya estaba eliminado.
- **`422`** `No se pudo identificar el conduce que quieres eliminar. Actualiza el listado e inténtalo de nuevo.`
  sin `id`.

No hay pantalla ni endpoint para restaurar. Soporte puede volver a mostrar uno con
`UPDATE conduces SET activo = 1 WHERE id = <id>;` en la DB del tenant: vuelve con las líneas
que tenía.

### POST `/api/conduces/preview` — PDF sin guardar

- **Sin `id`** (un conduce nuevo): el cuerpo del POST, con las **mismas revisiones**,
  incluida la cotización de origen de Ferretería. En la posición del número imprime
  `VISTA PREVIA`, y la línea `Cotización:` lleva el código de esa cotización.
- **Con `id`** (uno guardado): el cuerpo del PUT. El conduce tiene que estar activo (si no,
  `404`); imprime su código y la cotización de la fila, y el `cotizacion_id` del cuerpo se
  ignora.
- **Fecha impresa:** la del cuerpo; si no viene, la guardada (con `id`); si tampoco, hoy.
- No guarda nada ni toca la secuencia: una vista previa no gasta número.

**Respuesta `200`:** `{ "status": true, "data": { "filename": "Conduce_Preview.pdf", "content": "<base64>", "mime_type": "application/pdf" } }`.

### GET `/api/conduces/{id}/pdf` — PDF guardado

- `?format=base64` → `{ "status": true, "data": { "filename": "Conduce_CON-000007.pdf", "content": "<base64>", "mime_type": "application/pdf" } }`.
- Sin `format` (o cualquier otro valor) → el PDF crudo como descarga
  (`Content-Disposition: attachment; filename="Conduce_CON-000007.pdf"`).
- **`404`** `Este conduce ya no existe. Puede que lo hayan eliminado; vuelve al listado.` si
  no existe, está eliminado o `{id}` es `0`.
- **`500`** `No se pudo generar el PDF del conduce. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.`
  si no se pudo armar (por ejemplo, no se pudo leer `emisor_config`).

### El PDF

La hoja de la cotización de Ferretería (`FerreteriaCotizacionPdf` con
`documento => 'conduce'`), sin precios:

- Carta vertical, Times: logo, dirección y RNC del emisor como en la cotización (sin logo,
  la razón social en negrita).
- Título **`CONDUCE DE MERCANCÍA`**.
- Bloque izquierdo: la fecha larga (`OCTUBRE 5/2026.-`), el código (o `VISTA PREVIA`),
  `Cotización: COT-000001` (se omite si la cotización se eliminó), `NOMBRE O RAZÓN SOCIAL`,
  el cliente y su RNC/cédula con guiones.
- Tabla **`Cantidad | Unidad | Descripción mercancías`**: 24 mm, 30 mm y el resto;
  centrado, centrado e izquierda. Unidad y Descripción parten en renglones.
- **La unidad sale con su nombre** (`Unidad`, `Metro`…), del catálogo de master leído una
  vez por PDF. Una unidad inactiva o desconocida, o un catálogo que no se pudo leer,
  imprime el código guardado.
- La marca `***********No hay más productos debajo de la línea*****`, bajo Descripción.
- **Sin** `Valor Unitario`, `Valor Total RD$`, `Sub-total`, ITBIS ni `TOTAL`.
- `Recibido por:` y el pie: razón social, correo y teléfono de `emisor_config`. Ese bloque
  nunca se parte.
- Saltos de página como la cotización: la cabecera de la tabla se repite en cada página con
  filas, y `Página X de Y` sale cuando hay más de una.
- **El cliente** es el de hoy (razón social, si no nombre de la empresa, si no nombre, igual
  que la cotización). Si se borró, el nombre guardado y sin RNC.

### Errores

Todos con `status: false`. `N` es el número de la línea como la ve el usuario (desde 1).

| HTTP | `error` | Cuándo |
|------|---------|--------|
| 401 | `Invalid or inactive API token` | Token vencido, cerrado o falso |
| 401 | `Credenciales requeridas. Integracion: X-API-KEY + X-API-SECRET. App: Authorization: Bearer <token>.` | Sin token |
| 403 | `No tienes permiso para hacer esto. Pídeselo a un administrador.` | El rol no tiene el módulo `cotizaciones` (con `PERMISSIONS_ENFORCE=true`) |
| 403 | `Para hacer esto necesitas iniciar sesión con tu usuario.` | Credenciales de integración (`X-API-SECRET`) en vez de una sesión (con `PERMISSIONS_ENFORCE=true`) |
| 422 | `Los conduces no están disponibles para tu empresa.` | La empresa no está en el formato `ferreteria` (todas las rutas) |
| 404 | `Esta dirección de conduces no existe.` | Método o sub-ruta que no existe |
| 422 | `Los conduces no llevan cargos ni abonos.` | El cuerpo trae la clave `ajustes` |
| 422 | `Elige una cotización de Ferretería para crear el conduce.` | POST, o vista previa sin `id`: falta `cotizacion_id`, no es entero > 0, no existe o no es de Ferretería |
| 422 | `Elige un cliente para el conduce.` | Falta `client_id`, no es entero > 0 o el cliente no existe (también en PUT, si borraron el guardado) |
| 422 | `La fecha no es válida.` | `date` no es `YYYY-MM-DD` ni `YYYY-MM-DD HH:MM:SS`, o no existe (`2026-02-30`) |
| 422 | `Agrega al menos una línea al conduce.` | Sin `items`, no es una lista, o vacía |
| 422 | `La línea N no es válida. Quítala y vuelve a agregarla.` | La línea no es un objeto |
| 422 | `La línea N no tiene descripción. Escríbela o quita esa línea.` | |
| 422 | `La descripción de la línea N es muy larga: admite hasta 1000 caracteres.` | |
| 422 | `La unidad de medida de la línea N no es válida. Elige otra unidad en esa línea.` | |
| 422 | `Línea N: la cantidad debe ser mayor que 0.` | |
| 422 | `Línea N: la unidad «Unidad» no admite fracciones: usa una cantidad entera o cambia la unidad.` | Con la master 010 aplicada |
| 422 | `Línea N: la cantidad admite hasta 2 decimales.` | |
| 422 | `El precio de la línea N no es válido. Revísalo.` | `amount` ausente o no numérico |
| 422 | `Línea N: el precio no puede ser negativo.` | `amount` < 0 (0 sí vale) |
| 422 | `Línea N: el precio admite hasta 4 decimales.` | |
| 422 | `Línea N: el tipo de ITBIS no es válido. Elige 18%, 16%, 0% o exento.` | |
| 422 | `Línea N: elige si es un bien o un servicio.` | |
| 422 | `Línea N: el producto no es válido. Búscalo de nuevo o déjala como línea libre.` | `product_id` no es entero > 0 |
| 422 | `Línea N: el producto ya no existe en el catálogo. Búscalo de nuevo o déjala como línea libre.` | |
| 422 | `Un producto del conduce ya no existe en el catálogo (lo eliminaron mientras lo editabas). Búscalo de nuevo o quita la línea.` | Lo borraron entre la revisión y el guardado (`1452`) |
| 422 | `La cotización de origen ya no existe; vuelve a Cotizaciones.` | POST: la borraron entre la revisión y el guardado (`1452`) |
| 422 | `No se pudo identificar el conduce que quieres modificar. Ábrelo de nuevo desde el listado.` | PUT sin `id` válido |
| 422 | `No se pudo identificar el conduce que quieres eliminar. Actualiza el listado e inténtalo de nuevo.` | DELETE sin `id` válido |
| 404 | `Este conduce ya no existe. Puede que lo hayan eliminado; vuelve al listado.` | PUT, DELETE, PDF o vista previa con el `id` de uno que no existe o está eliminado |
| 500 | `No se pudo revisar el conduce. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.` | No se pudo leer la cotización, el cliente o los productos |
| 500 | `No se pudo guardar el conduce. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.` | Fallo de la base al crear |
| 500 | `No se pudieron guardar los cambios del conduce. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.` | Fallo de la base al editar |
| 500 | `No se pudo eliminar el conduce. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.` | Fallo de la base al eliminar |
| 500 | `Otro conduce se guardó al mismo tiempo. Vuelve a guardar.` | El número chocó dos veces seguidas |
| 500 | `No se pudo generar el PDF del conduce. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.` | |
| 500 | `No se pudo completar la operación con los conduces. Inténtalo de nuevo y, si sigue pasando, avisa a soporte.` | Cualquier otro fallo, por ejemplo una lectura del listado o del conduce |

Sin la migración 031 en la DB del tenant, las rutas de Ferretería responden `500` y el log
dice `[conduces] … Table '….conduces' doesn't exist`. Gratex no lo nota: su `422` de
disponibilidad sale antes de tocar la base.

### Resumen de códigos HTTP

| HTTP | Significado |
|------|-------------|
| `200` | Éxito (siempre con `status: true`) |
| `401` | Sin token o token inválido; antes que todo lo demás |
| `403` | El rol no tiene el módulo `cotizaciones`, o no es una sesión de usuario (con `PERMISSIONS_ENFORCE=true`) |
| `404` | Ruta que no existe; PUT, DELETE, PDF o vista previa de un conduce que no existe o está eliminado |
| `422` | La empresa no usa conduces; validación del cuerpo; PUT o DELETE sin `id` |
| `500` | Fallo de la base o del PDF; el número chocó dos veces seguidas |

---

### Auditoría

CREATE, UPDATE y DELETE quedan en `audit_logs` con módulo `conduces`, `entity_type`
`conduce` y `entity_id` = el id del conduce
([../modules/auditoria.md](../modules/auditoria.md)):

| Acción | `old_values` | `new_values` | `description` |
|--------|--------------|--------------|---------------|
| `CREATE` | — | el cuerpo | `Conduce CON-000007 creado.` |
| `UPDATE` | la fila de antes (con sus líneas) | el cuerpo | `Conduce CON-000007 actualizado.` |
| `DELETE` | la fila (con sus líneas) | — | `Conduce CON-000007 eliminado (activo = 0).` |

Solo se registra lo que se guardó: un `404`, un `422` o un `500` no dejan fila.

### Facturar desde un conduce (en el front)

No hay endpoint nuevo. fiscalo arma el borrador de la factura e-CF o de la factura simple con
la fila del conduce (`GET ?id=`) y lo emite con los endpoints de siempre (`/api/facturas`,
`/api/facturas-simples`), que no cambian.

- Cada línea pasa con su `amount` (precio sin ITBIS), su `indicador_facturacion`, su
  `product_id` y su `unidad_medida`. Con `product_id`, la factura mueve inventario como
  siempre.
- En la factura simple, cada precio lleva su ITBIS dentro, como al facturar una cotización de
  Ferretería.
- Una línea con `amount` 0 **no se puede emitir ni guardar**: el formulario pide el precio.
  El backend de facturas no rechaza un precio 0 y la DGII rechaza un `MontoItem` 0 después de
  reservar el e-NCF; por eso la guardia está en el front.
- Facturar no marca el conduce ni lo liga a la factura.

### Pruebas

- Por CLI, sin DB: `php tools/test_conduces.php` (desde `api-gratex`; las reglas, el
  número, el PDF en modo conduce, el modelo con una conexión falsa y las piezas del
  controller). `--pdf` escribe PDF de muestra en `tools/out/`.
- Contra MySQL de verdad, sin servidor HTTP:
  `php -d extension=pdo_mysql tools/test_conduces_mysql.php`. Usa solo la base de pruebas
  `smhynzte_conduces_scratch` (credenciales en `tools/.env`, ignorado por git), la vacía al
  empezar y al terminar, y prueba la 031 dos veces, el snapshot nuevo, `conduceModel` de
  punta a punta, un `1062` real con su reintento, cinco creaciones a la vez y las reglas
  `ON DELETE`. Nunca apunta a producción.
- A mano contra un servidor, con las comprobaciones de la 031 y de la base:
  [../../tests/test_conduces.http](../../tests/test_conduces.http).
- **En producción**, cada conduce de prueba **gasta su número** (queda con `activo = 0` y
  no se reusa): el primer conduce real ya no sería `CON-000001`. La vista previa no gasta
  número.

### Migración 031 (datos)

`conduces`, `conduce_items` y `conduce_secuencia` (esquema en
[../database/schema.md](../database/schema.md)). La migración se pega completa en la pestaña
SQL de phpMyAdmin con la base del tenant seleccionada, y se puede correr dos veces. Su
última consulta es **una fila**; lo que tiene que decir:

| Columna | Esperado |
|---------|----------|
| `base` | El nombre de la DB del tenant (nunca `information_schema` ni `NULL`) |
| `motor_cotizaciones`, `motor_products` | `InnoDB` |
| `tipo_cotizaciones_id`, `tipo_products_id` | El tipo de cada `id`: `int` en MySQL 8.0.19 o más nuevo (producción), `int(11)` en uno más viejo |
| `conduces`, `conduce_items`, `conduce_secuencia` | `InnoDB` (`NULL` = la tabla no existe) |
| `indices` | `6` |
| `fk_conduces_cotizacion`, `fk_conduce_items_conduce`, `fk_conduce_items_product` | `SET NULL`, `RESTRICT`, `SET NULL` |
| `fila_secuencia` | `(1, 0)`, o `(1, N)` si ya se crearon conduces |
| `todo_ok` | `SI` |

Si un motor no es `InnoDB` o un tipo sale `NULL`, no se creó ninguna tabla y `todo_ok` dice
`NO`. Después, `tools/verificar_migraciones_tenant.sql` tiene que decir `APLICADA` en la fila
`031`.
