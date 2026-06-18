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
