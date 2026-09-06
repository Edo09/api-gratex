# Inventario — Categorías y Almacenes

Primera fase del módulo de Inventario: **categorías** y **almacenes (warehouses)**, integrados al
catálogo de productos. Backend.

> **Frontend:** la guía de integración (contratos request/response, errores, flujo de UI) está en
> [../api/inventario.md](../api/inventario.md).

> Multi-tenant: cada empresa es un tenant con su **propia DB**, así que estas tablas viven en la
> DB del tenant y el aislamiento es **inherente** — no llevan `company_id`. Sin soft-deletes: hay
> un flag `estado` (1=activo/0=inactivo) para desactivar, y borrado físico con guardas por FK.

## Tablas (DB del tenant)

| Tabla | Columnas |
|---|---|
| `categories` | `id`, `nombre` (UNIQUE), `descripcion?`, `estado`, `created_at`, `updated_at` |
| `warehouses` | `id`, `nombre` (UNIQUE), `descripcion?`, `estado`, `created_at`, `updated_at` |

`products` gana `category_id` (FK `categories` `ON DELETE SET NULL`, **opcional**) y `warehouse_id`
(FK `warehouses` `ON DELETE RESTRICT`, **obligatorio**). El viejo texto libre `products.categoria`
se migró a `categories` y se eliminó (migración `017_add_inventory.sql`).

- **Almacén por defecto:** cada empresa tiene `Almacén Principal` (sembrado por
  `tenant_schema.sql` / la migración). Si un producto se crea sin `warehouse_id`, se le asigna.
- No se puede **borrar** `Almacén Principal`, ni un almacén con productos (FK RESTRICT → 400).
- Borrar una categoría deja sus productos sin categoría (`category_id` → NULL).

## API

Cada ruta requiere token y su propio módulo RBAC — **`categories`** y **`warehouses`** (módulos
separados: un rol puede tener uno sin el otro). El rol `user` trae ambos por defecto; ver
[roles-permisos.md](roles-permisos.md). Mismo contrato que `/api/products`.

| Método | Ruta | Acción |
|---|---|---|
| GET | `/api/categories` | listar (`?page,pageSize,query`) o `?id=` |
| POST | `/api/categories` | crear `{nombre, descripcion?, estado?}` |
| PUT | `/api/categories/{id}` o `{id,...}` | actualizar |
| DELETE | `/api/categories` `{id}` | borrar |
| GET/POST/PUT/DELETE | `/api/warehouses` | igual (almacenes) |

Respuestas: `{status:true,data}` / `{status:false,error}`; listas con `pagination`.

## Productos

`POST/PUT /api/products` aceptan `category_id` (nullable) y `warehouse_id` (si se omite al crear →
`Almacén Principal`). Las listas y el detalle exponen `categoria_nombre` y `almacen_nombre`
(nombres, vía JOIN), además de los ids. Ver [../api/facturas.md](../api/facturas.md) (sección
productos) y [../database/schema.md](../database/schema.md).

## Archivos
`db/migrations/017_add_inventory.sql`, `db/master_migrations/004_add_inventory_permission.sql`,
`src/Models/categoryModel.php`, `src/Models/warehouseModel.php`,
`src/Controllers/categoryController.php`, `src/Controllers/warehouseController.php`,
`config/permissions.php` (módulos `categories` + `warehouses`), `src/Router.php`, `src/Models/productModel.php`,
`src/Controllers/productController.php`, `db/tenant_schema.sql`.

---

## Valor de inventario

`GET /api/inventario/valor` — cuánto vale lo que hay en almacén, producto por
producto, a una fecha de corte. Con el detalle de movimientos de cada uno
(`/api/inventario/movimientos?product_id=`, el kardex que ya existía).

| Parámetro | Valores | Por defecto |
|---|---|---|
| `estado` | `activos` · `inactivos` · `todos` | `activos` |
| `warehouse_id`, `category_id` | filtro | todos |
| `hasta` | `AAAA-MM-DD` (corte) | hoy |
| `query` | busca en nombre y SKU | — |
| `page`, `pageSize` | paginación (tope 100) | 1, 20 |

Los servicios (`indicador_bien_servicio = 2`) quedan fuera: no tienen existencia.

### La existencia NO se reconstruye sumando el libro

Se parte de `products.stock` —la verdad de hoy— y se le **restan los movimientos
posteriores al corte**. Es exacto y no necesita asiento de apertura.

Eso importa aquí: el stock de este sistema se cargó directo en `products`, no por
el libro. Sumar movimientos desde cero daría **cero** para todo lo que existía
antes del primer movimiento. Un producto dado de alta después del corte se
reporta en 0: a esa fecha no existía.

### Costo promedio

Ponderado de las **entradas** del libro hasta el corte: `valor entrado / cantidad
entrada`. Un producto sin entradas registradas cae a `products.costo`, el único
costo que se conoce de él, y la respuesta lo marca con `costo_ponderado: false`
para que el front lo rotule «de ficha». Sin esa marca, un costo de ficha se lee
como un promedio calculado y no lo es.

Cada movimiento del libro trae su costo: cuando la línea no lo especifica,
`aplicarMovimientos()` usa `products.costo`. Por eso `costo_unitario` y
`valor_movimiento` nunca vienen nulos y el ponderado se puede calcular.

### Totales

Se calculan sobre **todos** los productos que pasan el filtro y se pagina después.
En un reporte de valorización el total tiene que ser el del inventario completo,
no el de la página — y ordenar por valor exige tenerlos todos. La contrapartida es
que la consulta lee el catálogo filtrado entero; con catálogos muy grandes hay que
revisarlo.

### Lo que el reporte destapa

Es su trabajo, no un defecto:

- **Existencias negativas** — se venden sin bloquear (el comprobante ya se emitió),
  así que aparecen en rojo hasta que un ajuste las corrija.
- **Servicios marcados como bienes** — en el tenant de pruebas, `ENVIO MERCANCIA`
  tenía stock 1,000,000,000 y `INSTALACION` 999,997, ambos con costo 0. Alguien
  les puso una existencia enorme para que no se agotaran. Se arregla marcándolos
  como servicio, y entonces salen del reporte solos.

### Archivos
`src/Models/inventoryModel.php` (`valorInventario`, `filaValorizada`,
`agregadosMovimientos`), `src/Controllers/inventarioController.php`,
frontend `src/features/inventory/InventoryValueView.tsx`.
