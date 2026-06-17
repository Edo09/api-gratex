# db/migrations

Migraciones incrementales para DBs de **tenant** (tipo app) **ya desplegados**.

- Los tenants **nuevos** NO corren migraciones: `tools/create_tenant.php` aplica
  `db/tenant_schema.sql`, que es el snapshot completo consolidado (base + todas las
  migraciones ya incluidas, hasta la 016). Las migraciones sueltas activas (012–016)
  son solo para DBs de tenant **ya desplegados**.
- Un cambio de esquema nuevo se hace en DOS lugares:
  1. `db/migrations/NNN_descripcion.sql` — para aplicar a mano en los DBs de
     tenant existentes (Gratex, etc.).
  2. `db/tenant_schema.sql` — reflejar el mismo cambio para tenants futuros.
- Cambios al **master** (`gratex_master`): `db/master_migrations/` (+ reflejar
  en `db/master_schema.sql` para instalaciones nuevas).

## deprecated/

Migraciones 001–011, ya consolidadas dentro de `tenant_schema.sql` (2026-06-09).
Se conservan como historial de los DBs que se actualizaron incrementalmente.
NO ejecutarlas en tenants nuevos.
