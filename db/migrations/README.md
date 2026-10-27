# db/migrations

Migraciones incrementales para DBs de **tenant** (tipo app) **ya desplegados**.

- Los tenants **nuevos** NO corren migraciones: `tools/create_tenant.php` aplica
  `db/tenant_schema.sql`, que es el snapshot completo consolidado (base + todas las
  migraciones ya incluidas, hasta la 035). Las migraciones sueltas activas (012–035)
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
  `PREPARE/EXECUTE` (ver 018, 026, 027, 028, 029, 030, 031, 032, 033, 034 y 035; en el master, 008, 010 y 011).
- En phpMyAdmin, un `SELECT` sobre `information_schema` cambia la base actual a
  `information_schema` para las sentencias que siguen (la 028 falló así el
  2026-10-05: "#1109 - Unknown table 'FACTURAS' in information_schema"). Una
  migración que dependa de la base del tenant la fija en la PRIMERA sentencia,
  `SET @db := DATABASE();`, nombra todo con `@db` (`TABLE_SCHEMA = @db`,
  `` `', @db, '`.tabla `` en el SQL dinámico) y deja el `SELECT` de resultado
  para el final. Ver 028 y 031 (la 031 también nombra con `@db` las tablas de
  sus `REFERENCES`, y lee con SQL armado la fila que muestra al final: una
  tabla que quizá no se creó no se puede nombrar en el `SELECT` final).
- Desde la 029/030/031/032/033/034/035 (y la master 012) la segunda sentencia es una **guardia**: si
  `@db` es `information_schema` u otra base de sistema, o no tiene la tabla que
  la migracion espera, falla con `#1049 Unknown database 'ALTO_elige_la_base_...'`
  o `#1146 Table '<base>.<tabla>' doesn't exist` ANTES de tocar nada. Paso el
  2026-10-08 con la 029: phpMyAdmin seguia parado en information_schema despues
  de la migracion anterior. Los CREATE TABLE van antes de cualquier consulta a
  information_schema y todo lo posterior nombra la base con `@db` (probado
  inyectando un `USE information_schema` a mitad del lote).
- Cambios al **master** (`gratex_master`): `db/master_migrations/` (+ reflejar
  en `db/master_schema.sql` para instalaciones nuevas).

## Números reservados (2026-10-27)

Fiscalpoint Express reserva dos números antes de que existan sus archivos, para
que ningún otro plan los tome:

- **Tenant 036**: `db/migrations/036_facturas_idempotency.sql`. Agrega a
  `facturas` las columnas `idempotency_key CHAR(36) NULL` e
  `idempotency_hash CHAR(64) NULL`, y el índice
  `UNIQUE KEY uk_facturas_idem (idempotency_key)`.
- **Master 014**: `db/master_migrations/014_tenants_edicion.sql`. Agrega a
  `tenants` las columnas `edicion ENUM('completa','express') NOT NULL DEFAULT 'completa'`,
  `modulos_habilitados TEXT NULL`, `tipos_ecf_habilitados VARCHAR(64) NULL` y
  `cert_vence DATE NULL`.
- El plan ITBIS de facturas simples (todavía sin mergear) pidió master 013 (ya
  ocupada por `013_tenants_inventario_estricto.sql`) y tenant 036, así que al
  aterrizar toma master 015 y tenant 037.
- Antes de crear una migración: `git fetch` y las dos listas, una por serie (con
  la barra final, que lista los archivos y no el directorio):
  `git ls-tree --name-only origin/master db/migrations/ | tail -4` (tenant) y
  `git ls-tree --name-only origin/master db/master_migrations/ | tail -2` (master).
  Un solo comando con los dos directorios no sirve: `git ls-tree` lista el master
  primero y `tail -4` solo alcanza el final del tenant.
- El tenant tiene dos 014 (`014_add_factura_items_unidad_medida.sql` y
  `014_ncf_rangos_autorizados.sql`) y ninguna 015; el master es otra serie, con
  su propia numeración.
- Los encabezados que dicen "hasta la 035" (este README, `db/tenant_schema.sql`,
  `tools/verificar_migraciones_tenant.sql`) no se tocan todavía: un snapshot o un
  verificador no debe nombrar una migración cuyo archivo no existe. Los planes de
  Express que crean la master 014 y la tenant 036 los suben junto con el SQL;
  mientras tanto, este bloque y `tools/test_express_reservas.php` llevan la
  reserva.

## deprecated/

Migraciones 001–011, ya consolidadas dentro de `tenant_schema.sql` (2026-06-09).
Se conservan como historial de los DBs que se actualizaron incrementalmente.
NO ejecutarlas en tenants nuevos.
