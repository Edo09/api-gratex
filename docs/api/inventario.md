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
| `stock` | no | number \| null | existencia. Hasta **3 decimales**, y solo si la unidad del producto los admite (ver *Cantidades con decimales*). Puede ser 0 o negativa |
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
| `POST`/`PUT /api/products` | `stock` y `stock_minimo`: mismo criterio con la unidad que se envía (o `43`, Unidad, si no se envía). En `PUT`, una existencia que **no cambió** con la misma unidad no se juzga: la mueve el libro (vender 1,5 metros de un producto en «Unidad» la deja en 8,5) y el formulario la reenvía en cada guardado. Error → **`422`** `{ "status": false, "error": "En la existencia, …" }` |
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
