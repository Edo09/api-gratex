# Inventario (Categorías y Almacenes) — Guía de integración Frontend

Endpoints para gestionar **categorías** y **almacenes**, y los campos nuevos de **producto**
(`category_id`, `warehouse_id`). Mismo patrón que `/api/products`.

- **Base URL (producción):** `https://gratex.net/api`
- **Auth:** header `X-API-KEY: <token>` (token de sesión del login) en todas las llamadas.
- **Respuestas:** `{ "status": true, "data": ... }` · listas con `pagination` · error `{ "status": false, "error": "..." }`.
- **Permisos (RBAC):** `/api/categories` exige el módulo `categories`; `/api/warehouses` el módulo
  `warehouses` (módulos separados). Los roles `admin` (todo) y `user` (por defecto) los tienen.
  Sin permiso → `403`.

> Aislamiento: cada empresa (tenant) solo ve sus propias categorías/almacenes. No se envía ni se
> recibe ningún `company_id`.

---

## Categorías — `/api/categories`

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/api/categories` | Listar (paginado o todas) |
| GET | `/api/categories?id={id}` | Una categoría |
| POST | `/api/categories` | Crear |
| PUT | `/api/categories` (o `/api/categories/{id}`) | Actualizar |
| DELETE | `/api/categories` | Eliminar |

### Forma de una categoría
```json
{
  "id": 8,
  "nombre": "Bebidas",
  "descripcion": "Línea de bebidas",
  "estado": 1,
  "created_at": "2026-06-18 15:43:34",
  "updated_at": "2026-06-18 15:43:34"
}
```
- `estado`: `1` = activo, `0` = inactivo (desactivar en lugar de borrar).
- `descripcion`: opcional (`null` si no se envía).

### GET listar
```
GET /api/categories?page=1&pageSize=10&query=beb
```
| Param | Default | Nota |
|-------|---------|------|
| `page` | `1` | |
| `pageSize` | `10` | |
| `query` | — | busca en `nombre` y `descripcion` |

Sin `page/pageSize/query` devuelve **todas** (sin `pagination`). Respuesta paginada:
```json
{
  "status": true,
  "data": [ { "id": 1, "nombre": "Alimentos", "descripcion": null, "estado": 1, "created_at": "...", "updated_at": "..." } ],
  "pagination": { "page": 1, "pageSize": 10, "total": 5, "totalPages": 1 }
}
```

### POST crear
```json
POST /api/categories
{ "nombre": "Bebidas", "descripcion": "opcional", "estado": 1 }
```
**`201`** → `{ "status": true, "data": { "id": 8, "message": "Categoria creada" } }`

### PUT actualizar
```json
PUT /api/categories
{ "id": 8, "nombre": "Bebidas y jugos", "descripcion": "...", "estado": 0 }
```
**`200`** → `{ "status": true, "data": "Categoria actualizada" }`

### DELETE eliminar
```json
DELETE /api/categories
{ "id": 8 }
```
**`200`** → `{ "status": true, "data": "Categoria eliminada" }`.
Los productos que tenían esa categoría quedan con `category_id = null` (no se borran).

---

## Almacenes — `/api/warehouses`

Mismas rutas/forma que categorías (`id`, `nombre`, `descripcion?`, `estado`, `created_at`, `updated_at`).

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/api/warehouses` · `?id=` · `?page,pageSize,query` | Listar / uno |
| POST | `/api/warehouses` | Crear `{ nombre, descripcion?, estado? }` → `201` |
| PUT | `/api/warehouses` | Actualizar `{ id, ... }` → `200` |
| DELETE | `/api/warehouses` | Eliminar `{ id }` → `200` |

**Almacén por defecto:** cada empresa tiene `Almacén Principal` (los productos sin almacén caen ahí).

Reglas de borrado (mostrar el error al usuario):
- Borrar `Almacén Principal` → **`400`** `"No se puede eliminar el almacén por defecto (Almacén Principal)."`
- Borrar un almacén **con productos** → **`400`** `"No puedes eliminar este almacén porque tiene productos asignados. Pásalos a otro almacén o, si ya no lo usas, desactívalo."`
- Borrar un almacén **con ajustes o movimientos de inventario** (aunque ya no le queden productos) → **`400`**
  `"No puedes eliminar este almacén porque ya tiene movimientos de inventario (ajustes, ventas o compras). Si ya no lo usas, desactívalo y guarda los cambios."`
  Reasignar los productos no lo arregla: el historial no se borra.

---

## Producto — campos nuevos

`POST`/`PUT /api/products` aceptan:

| Campo | Req | Tipo | Nota |
|-------|-----|------|------|
| `category_id` | no | int \| null | categoría a la que pertenece (opcional) |
| `warehouse_id` | no* | int | almacén. *Si se **omite al crear**, se asigna `Almacén Principal`. En `PUT`, si se omite se conserva el actual |
| `stock` | no | number \| null | existencia. Hasta **3 decimales**, y solo si la unidad del producto los admite (ver *Cantidades con decimales*). Con «Bloquear ventas sin existencia» **apagado** (lo normal) se guarda tal cual y puede ser 0 o negativa. Con el interruptor **encendido**: en `PUT` se **ignora** en silencio (la fila conserva su existencia, sin error aunque el campo venga) y en `POST` una existencia inicial distinta de 0 en un Bien se registra por el libro como ajuste `INVENTARIO_INICIAL` (no puede ser negativa → `422`). Ver *Producto con el interruptor encendido* |
| `stock_minimo` | no | number \| null | mismo criterio que `stock` |

En **listado y detalle** de productos (`GET /api/products`) cada producto incluye, además de
`category_id`/`warehouse_id`, los **nombres** resueltos para mostrar en tablas:

```json
{
  "id": 9,
  "nombre": "Producto X",
  "category_id": 8,
  "warehouse_id": 1,
  "categoria_nombre": "Bebidas",
  "almacen_nombre": "Almacén Principal",
  "precio": "100.00",
  "...": "resto de campos de producto"
}
```
- `categoria_nombre` = `null` si el producto no tiene categoría.
- La búsqueda de productos (`?query=`) también matchea por nombre de categoría y de almacén.

**Filtrar el listado por categoría:** `GET /api/products?category_id=8` (combinable con
`page`, `pageSize` y `query`; `pagination.total` ya viene filtrado). Es distinto de
`?query=Bebidas`, que además trae los productos que mencionan «Bebidas» en su nombre o
descripción. Un `category_id` no numérico o `<= 0` se ignora (no filtra, no da error).

> Para poblar los selects del formulario de producto: cargar `GET /api/categories` y
> `GET /api/warehouses` (usar `?query=` para autocompletar si la lista es grande).

---

## Cantidades con decimales

Desde la migración de tenant `025` (y la master `010`), la existencia y el libro de
movimientos guardan hasta **3 decimales** (`DECIMAL(15,3)`, hasta 999,999,999,999.999):
1,5 metros de cable mueven 1,5, no 2, y 0,4 kg ya no se pierden. Antes todo era entero y
una venta fraccionada se redondeaba.

Excepción para corregir el libro: en una unidad sin decimales se acepta un ajuste con
fracción **solo si deja entera la existencia** (existencia 8,5 → DISMINUCIÓN 0,5). Así se
puede arreglar un producto contado por «Unidad» que quedó con fracción, y el formulario
de producto no bloquea guardar si la existencia fraccionaria no se toca.

**Qué unidad admite decimales** lo dice `permite_decimales` de `GET /api/unidades-medida`
(1 = metro, kg, litro, hora…; 0 = unidad, pieza, caja…). Si la master aún no tiene la
columna, llega `null` y no se bloquea nada.

| Dónde | Regla |
|-------|-------|
| `POST /api/inventario/ajustes` | cada `lineas[].cantidad` > 0; con decimales solo si la **unidad del producto** los admite, y hasta 3. Si no → **`422`** con el texto para el usuario, p. ej. `"En la línea 2 («Bombillo»), la unidad «Unidad» no admite fracciones: usa una cantidad entera o cambia la unidad."` (el nombre va entre «» para que el front no lo confunda con texto técnico). Tope: la cantidad debe ser menor que 1,000,000,000,000. |
| `POST /api/inventario/ajustes/{id}/anular` | la anulación invierte lo guardado tal cual, con sus decimales, aunque después le hayan cambiado la unidad al producto |
| `POST`/`PUT /api/products` | `stock` y `stock_minimo`: mismo criterio con la unidad que se envía (o `43`, Unidad, si no se envía). En `PUT`, una existencia que **no cambió** con la misma unidad no se juzga: la mueve el libro (vender 1,5 metros de un producto en «Unidad» la deja en 8,5) y el formulario la reenvía en cada guardado. Con el interruptor de existencias **encendido**, el `stock` de un `PUT` se ignora y por eso no se juzga (el `stock_minimo` sí). Error → **`422`** `{ "status": false, "error": "En la existencia, …" }` |
| Ventas, devoluciones y compras | descuentan o suman la cantidad de la línea tal cual (2 decimales en e-CF, 3 en factura simple) |

**Tipos en las respuestas** (mismas claves que antes):

- `GET /api/products`: `stock` y `stock_minimo` llegan como **número** (`12.5`) o `null`,
  no como texto `"12.500"`.
- Kardex (`GET /api/inventario/movimientos`) y líneas de un ajuste
  (`GET /api/inventario/ajustes/{id}` → `lineas`): `cantidad`, `cantidad_anterior` y
  `cantidad_nueva` llegan como **número** (`-1.5`). `costo_unitario` y `valor_movimiento`
  siguen como antes (texto con 2 decimales).
- Valor de inventario (`GET /api/inventario/valor`): `entradas`, `salidas`, `existencia` y
  `totales.existencia` son números con hasta 3 decimales.

Al mostrarlas: sin ceros de relleno (`1.5`, no `1.500`) — en fiscalo, `fmtCantidad`
de `src/lib/format.ts`.

---

## Motivos de un ajuste

`POST /api/inventario/ajustes` exige `motivo` (se acepta en mayúsculas o minúsculas). Un motivo
fuera de esta lista da **`422`** `"Elige el motivo del ajuste."`

| Motivo | Etiqueta en la app | Uso |
|--------|--------------------|-----|
| `CONTEO_FISICO` | Conteo físico | corregir la existencia tras contar |
| `ENTRADA` | Entrada de mercancía | mercancía que llega al almacén. Es el motivo con que el aviso «no hay existencia» abre Ajuste |
| `INVENTARIO_INICIAL` | Inventario inicial | la existencia con que arranca un producto |
| `MERMA`, `DANO`, `ROBO` | Merma, Daño, Robo o pérdida | salidas por pérdida |
| `DEVOLUCION` | Devolución | mercancía que vuelve |
| `ERROR_CAPTURA` | Error de captura | corregir un dato mal escrito |
| `OTRO` | Otro | lo que no cabe en los demás |
| `ANULACION` | Anulación | **solo la pone el sistema** al anular un ajuste (`POST /api/inventario/ajustes/{id}/anular`); en un ajuste nuevo se rechaza, y con `anula_a_id` es el único motivo válido |

`ENTRADA` e `INVENTARIO_INICIAL` se agregaron con el control de existencias en ventas
(`inventory_adjustments.motivo` es `VARCHAR(20)`: no hubo migración). Un ajuste manual **no**
bloquea las salidas bajo cero: es la herramienta para corregir el libro, con el control
encendido o apagado.

---

## Control de existencias en las ventas

Cada empresa tiene un interruptor, **«Bloquear ventas sin existencia»**, **apagado por
defecto**. Apagado, todo funciona como siempre: se puede vender sin existencia y el saldo
queda en negativo. Encendido, una factura (e-CF) o una factura simple que pide más de lo que
dice el sistema se rechaza con el `422` estructurado de abajo. El POS, los conduces y las
cotizaciones no se revisan.

El interruptor es la columna `master.tenants.inventario_estricto` (migración master `013`).
Sin la migración, el API se comporta como apagado para todas las empresas.

### Configuración — `/api/inventario/configuracion`

| Método | Endpoint | Quién | Qué hace |
|--------|----------|-------|----------|
| GET | `/api/inventario/configuracion` | cualquier usuario autenticado | estado del interruptor y cuántos productos rechazaría hoy |
| PUT | `/api/inventario/configuracion` | **solo el rol `admin`** | lo enciende o lo apaga |

```json
GET /api/inventario/configuracion
{ "status": true, "data": { "bloquear_sin_existencia": false, "productos_sin_existencia": 12 } }

PUT /api/inventario/configuracion
{ "bloquear_sin_existencia": true }
```
El `PUT` responde **`200`** con el mismo cuerpo que el `GET`, ya con el valor nuevo.

- `productos_sin_existencia` (número): Bienes **activos** con existencia vacía, 0 o negativa.
  Los servicios no cuentan. Es lo que se rechazaría el instante de encender, para que el admin
  lo revise antes.
- La empresa sale **siempre del token**; un `tenant_id` en el cuerpo se ignora.
- El `PUT` es solo del rol `admin`, por nombre de rol (no alcanza con el permiso `*` ni con el
  módulo `products`), y se exige aunque `PERMISSIONS_ENFORCE` esté apagado. El rol se revisa
  antes de tocar la base.
- La ruta pertenece al módulo `products` del RBAC, como el resto de `/api/inventario`: con
  `PERMISSIONS_ENFORCE` encendido, un rol sin ese módulo recibe `403` también en el `GET`. El
  front lee el interruptor de `GET /api/branding` (abierto a cualquier usuario), no de aquí.
- Cada cambio queda en la bitácora: módulo `inventario`, acción `INVENTARIO_CONFIG`, con el
  valor de antes y el de después. Un `PUT` de otro rol queda como `ACCESS_DENIED`.

| HTTP | Cuándo |
|------|--------|
| `403` | el token no trae usuario, o el `PUT` lo hace un rol distinto de `admin` («Solo el administrador de la empresa puede cambiar esta opción.») |
| `409` | instalación de una sola empresa (sin empresa en el token): el interruptor vive en `master.tenants` |
| `422` | el cuerpo no trae `bloquear_sin_existencia` como booleano de verdad: `"true"`, `1`, `null` o la clave ausente se rechazan |
| `500` | no se pudo guardar (p. ej. la migración `013` sin correr). No se audita y no se dice que se guardó |
| `405` | cualquier otro método sobre esta ruta |

**`GET /api/branding`** trae además `inventario_estricto` (booleano, solo lectura: el `PUT` de
branding no lo acepta). Es `true` solo para empresas tipo `app` con el interruptor en 1, y
`false` si la columna aún no existe. Es lo que lee el front para mostrar la existencia y avisar
en las facturas.

### El rechazo por existencia: HTTP `422`

Con el interruptor encendido, `POST /api/facturas`, `POST /api/facturas-simples` y
`PUT /api/facturas-simples/{id}` (cuando trae `items`) responden **`422`** (nunca `409`, que en
esta app significa «pantalla desactualizada») si alguna línea con `product_id` pide más de lo
que hay:

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

| Campo | Qué es |
|-------|--------|
| `solicitado` | la cantidad total del producto en el documento (si va en varias líneas, se suman antes de comparar) |
| `disponible` | la existencia, **sin bajar de 0**: un saldo vacío, 0 o negativo se informa como 0 (el texto nunca dice «quedan -3») |
| `faltante` | `solicitado` menos la existencia real (vacía = 0). Con saldo negativo es mayor que `solicitado − disponible`, para que un Ajuste por esa cantidad deje el saldo justo en lo que se pide |
| `unidad_medida` | el código tal como está en `products` (el front lo traduce a nombre) |

- Todos los números son **números JSON**, no textos. `faltantes` va ordenado por `product_id`. Si otra
  venta se lleva la existencia justo entre la revisión y el bloqueo de la fila (solo en factura
  simple), el `422` puede nombrar solo el primer producto que falta.
- El texto de `error` es una oración por producto, unidas. El front no lo usa: arma su propia
  lista con `data.faltantes` y muestra un botón a «Ajuste de inventario» con las cantidades que
  faltan ya escritas (motivo `ENTRADA`).
- Un `product_id` que **no existe**, o una cantidad ≤ 0 en una línea de producto, **no** son
  faltantes: dan un `422` llano, `{ "status": false, "error": "…" }`, sin `codigo` ni `data`.
- Las existencias se comparan en **milésimas** (la columna es `DECIMAL(15,3)`): 0.1 + 0.2 contra
  0.3 pasa; 0.3 contra 0.299 falta 0.001. Igual a la existencia pasa; con existencia 0 se
  rechaza cualquier cantidad.

**Qué se revisa.** Lo mismo que descuenta el inventario: toda línea con `product_id` de un
**Bien**, en todo tipo de e-CF **menos E34 y E41** (son entradas), y en la factura simple. Nunca
se revisan los servicios (`indicador_bien_servicio = 2`), las líneas libres (sin `product_id`;
un cliente de la API puede omitirlo y vender sin revisión) ni el **reenvío** de un e-CF
rechazado (es una obligación fiscal por mercancía ya vendida).

**Cuándo.**
- **e-CF:** la revisión corre **antes de reservar el e-NCF**, así un rechazo no deja un hueco en
  la secuencia fiscal. El descuento sigue ocurriendo cuando la DGII responde. Entre la revisión
  y el descuento pasa lo que tarde la DGII (más de 30 s si va lenta): dos ventas de la última unidad
  en esa ventana pueden pasar las dos; queda `[inventario] SOBREVENTA …` en el log del servidor. Si
  la revisión misma falla (la base no responde), la venta sigue sin revisarse y el log dice
  `[inventario] no se pudo verificar la existencia del e-CF tipo …`.
- **Factura simple (`POST` y `PUT` con `items`):** la revisión y el descuento ocurren dentro de
  **la misma transacción** que la factura. Si falta, la factura no se guarda y no se mueve nada.
  En el `PUT` primero se devuelven las líneas que la factura ya tenía, así que solo el aumento
  neto puede faltar. Si el libro de inventario falla dentro de la transacción, la factura simple
  tampoco se guarda y responde `400` («No se pudieron actualizar las existencias. Inténtalo de
  nuevo…»): a diferencia del e-CF, no sigue.

### Producto con el interruptor encendido

Con el interruptor **encendido**, la existencia de un producto solo se mueve por el libro de
inventario (Ajuste, compras, ventas). Si el formulario de producto pudiera teclearla sin dejar
rastro, el bloqueo de ventas no tendría con qué cuadrar. Lo que cambia en `/api/products`
(apagado, todo sigue como siempre):

- **`PUT`: `stock` se ignora en silencio.** La fila conserva su existencia y la respuesta es la
  de siempre, sin error y sin aviso, para que un cliente que aún reenvía el campo no se rompa.
  Tampoco se juzga su formato: un `stock` viejo, fraccionario o ilegible no da `422` por un campo
  que se descarta (`stock_minimo` sí se valida). El saldo se lee con bloqueo dentro de una
  transacción, así que una venta que se lleva existencia entre tanto no queda pisada.
- **`POST`: la existencia inicial entra por el libro.** Para un **Bien** con `stock` distinto de 0
  (redondeado a 3 decimales), el producto se crea con la existencia vacía y esa cantidad se
  registra como ajuste `INVENTARIO_INICIAL`, a nombre del usuario del token y en el almacén del
  producto, **en la misma transacción**: si el libro falla, el producto tampoco se crea y la
  respuesta es `status: false` con «No se pudo guardar el producto con su existencia inicial.
  Inténtalo de nuevo…» (el detalle técnico queda en el log). `stock` vacío, `0` o un servicio no
  generan ajuste y el producto se crea como hoy.
- **`POST` con `stock` negativo → `422`**, con `status: false` y el texto «La existencia inicial
  no puede ser negativa. Escribe 0 o más; para corregir existencias usa Ajuste de inventario.»
  El libro de apertura solo suma. Solo con el interruptor encendido y para un Bien: apagado se
  guarda tal cual, como hoy.
- **Bitácora:** el `UPDATE` de `products` lleva en `new_values.stock` la existencia guardada, no
  la que mandó el cliente, para que no aparezca como un cambio de existencia que no ocurrió.

---

## Códigos de error

| HTTP | Cuándo |
|------|--------|
| `401` | falta / token inválido (`X-API-KEY`) |
| `403` | el rol no tiene el módulo `categories` / `warehouses` |
| `404` | `?id=` no existe |
| `422` | falta `nombre` (o > 100 chars) / falta `id` en PUT/DELETE / `descripcion` > 255 |
| `400` | nombre duplicado, o guarda de borrado (almacén por defecto / con productos / con movimientos de inventario) |

Formato de error: `{ "status": false, "error": "<mensaje>" }`.

---

## Flujo de UI sugerido

1. **Listas (Categorías / Almacenes):** `GET ?page&pageSize&query` → tabla con paginación,
   búsqueda, loading y empty state. Columnas: `nombre`, `descripcion`, `estado` (badge activo/inactivo).
2. **Crear/Editar:** modal/form con `nombre` (req), `descripcion`, `estado` (toggle) → `POST`/`PUT`.
3. **Eliminar:** confirmar; mostrar el `error` del backend si vuelve `400` (almacén por defecto, con productos o con movimientos).
4. **Producto:** agregar selects `Categoría` (opcional) y `Almacén` (default `Almacén Principal`);
   en tablas/detalle mostrar `categoria_nombre`/`almacen_nombre`.
