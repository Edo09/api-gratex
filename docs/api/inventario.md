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
- Borrar `Almacén Principal` → **`400`** `"No se puede eliminar el almacen por defecto (Almacén Principal)."`
- Borrar un almacén **con productos** → **`400`** `"El almacen tiene productos asignados; reasignalos antes de eliminarlo."`

---

## Producto — campos nuevos

`POST`/`PUT /api/products` aceptan:

| Campo | Req | Tipo | Nota |
|-------|-----|------|------|
| `category_id` | no | int \| null | categoría a la que pertenece (opcional) |
| `warehouse_id` | no* | int | almacén. *Si se **omite al crear**, se asigna `Almacén Principal`. En `PUT`, si se omite se conserva el actual |

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

> Para poblar los selects del formulario de producto: cargar `GET /api/categories` y
> `GET /api/warehouses` (usar `?query=` para autocompletar si la lista es grande).

---

## Códigos de error

| HTTP | Cuándo |
|------|--------|
| `401` | falta / token inválido (`X-API-KEY`) |
| `403` | el rol no tiene el módulo `categories` / `warehouses` |
| `404` | `?id=` no existe |
| `422` | falta `nombre` (o > 100 chars) / falta `id` en PUT/DELETE / `descripcion` > 255 |
| `400` | nombre duplicado, o guarda de borrado (almacén por defecto / con productos) |

Formato de error: `{ "status": false, "error": "<mensaje>" }`.

---

## Flujo de UI sugerido

1. **Listas (Categorías / Almacenes):** `GET ?page&pageSize&query` → tabla con paginación,
   búsqueda, loading y empty state. Columnas: `nombre`, `descripcion`, `estado` (badge activo/inactivo).
2. **Crear/Editar:** modal/form con `nombre` (req), `descripcion`, `estado` (toggle) → `POST`/`PUT`.
3. **Eliminar:** confirmar; mostrar el `error` del backend si vuelve `400` (almacén por defecto o con productos).
4. **Producto:** agregar selects `Categoría` (opcional) y `Almacén` (default `Almacén Principal`);
   en tablas/detalle mostrar `categoria_nombre`/`almacen_nombre`.
