# db/migrations

Migraciones incrementales para DBs de **tenant** (tipo app) **ya desplegados**.

- Los tenants **nuevos** NO corren migraciones: `tools/create_tenant.php` aplica
  `db/tenant_schema.sql`, que es el snapshot completo consolidado (base + todas las
  migraciones ya incluidas, hasta la 029). Las migraciones sueltas activas (012–029)
  son solo para DBs de tenant **ya desplegados**.
- Un cambio de esquema nuevo se hace en DOS lugares:
  1. `db/migrations/NNN_descripcion.sql` — para aplicar a mano en los DBs de
     tenant existentes (Gratex, etc.).
  2. `db/tenant_schema.sql` — reflejar el mismo cambio para tenants futuros, con
     los mismos nombres de índices y FKs. El snapshot no desactiva
     `FOREIGN_KEY_CHECKS`: la tabla referenciada por una FK debe crearse antes
     (p. ej. `products` va antes de `cotizacion_items`, `factura_items` y
     `gasto_items`). `php tools/check_tenant_schema_orden.php` lo revisa sin
     MySQL.
- Si la DDL de producción puede no ser la del repo, la migración se escribe
  idempotente: cada cambio se condiciona a `information_schema` y se ejecuta con
  `PREPARE/EXECUTE` (ver 018, 026, 027, 028 y 029; en el master, 008, 010 y 011).
- En phpMyAdmin, un `SELECT` sobre `information_schema` cambia la base actual a
  `information_schema` para las sentencias que siguen (la 028 falló así el
  2026-10-05: "#1109 - Unknown table 'FACTURAS' in information_schema"). Una
  migración que dependa de la base del tenant la fija en la PRIMERA sentencia,
  `SET @db := DATABASE();`, nombra todo con `@db` (`TABLE_SCHEMA = @db`,
  `` `', @db, '`.tabla `` en el SQL dinámico) y deja el `SELECT` de resultado
  para el final. Ver 028 y 029 (la 029 también nombra con `@db` las tablas de
  sus `REFERENCES`, y lee con SQL armado la fila que muestra al final: una
  tabla que quizá no se creó no se puede nombrar en el `SELECT` final).
- Cambios al **master** (`gratex_master`): `db/master_migrations/` (+ reflejar
  en `db/master_schema.sql` para instalaciones nuevas).

## deprecated/

Migraciones 001–011, ya consolidadas dentro de `tenant_schema.sql` (2026-06-09).
Se conservan como historial de los DBs que se actualizaron incrementalmente.
NO ejecutarlas en tenants nuevos.
