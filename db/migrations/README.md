# db/migrations

Migraciones incrementales para DBs de **tenant** (tipo app) **ya desplegados**.

- Los tenants **nuevos** NO corren migraciones: `tools/create_tenant.php` aplica
  `db/tenant_schema.sql`, que es el snapshot completo consolidado (base + todas las
  migraciones ya incluidas, hasta la 041: la 036 y la 037 están reservadas, ver más abajo).
  Las migraciones sueltas activas (012–035, 038, 039, 040 y 041) son solo para DBs de tenant **ya desplegados**.
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
  `PREPARE/EXECUTE` (ver 018, 026, 027, 028, 029, 030, 031, 032, 033, 034, 035, 038, 039 y 040; en el master, 007, 008, 010, 011, 012, 013, 014, 015, 017, 018, 019, 020, 021, 022 y 023).
- En phpMyAdmin, un `SELECT` sobre `information_schema` cambia la base actual a
  `information_schema` para las sentencias que siguen (la 028 falló así el
  2026-10-05: "#1109 - Unknown table 'FACTURAS' in information_schema"). Una
  migración que dependa de la base del tenant la fija en la PRIMERA sentencia,
  `SET @db := DATABASE();`, nombra todo con `@db` (`TABLE_SCHEMA = @db`,
  `` `', @db, '`.tabla `` en el SQL dinámico) y deja el `SELECT` de resultado
  para el final. Ver 028 y 031 (la 031 también nombra con `@db` las tablas de
  sus `REFERENCES`, y lee con SQL armado la fila que muestra al final: una
  tabla que quizá no se creó no se puede nombrar en el `SELECT` final).
- Desde la 029/030/031/032/033/034/035/038/039/040/041 (y la master 012) la segunda sentencia es una **guardia**: si
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
- **Master 015**: `db/master_migrations/015_tenants_edicion.sql`. Agrega a
  `tenants` las columnas `edicion ENUM('completa','express') NOT NULL DEFAULT 'completa'`,
  `modulos_habilitados TEXT NULL`, `tipos_ecf_habilitados VARCHAR(64) NULL` y
  `cert_vence DATE NULL`.
- La master 014 es `014_integracion_backup_estado_dgii.sql`: la tomó integración en
  master el 2026-10-31, antes de que esta reserva llegara a master, y por eso la de
  Express es la 015. Una migración ya aplicada nunca se renumera.
- El plan ITBIS de facturas simples (todavía sin mergear) pidió master 013 (ya
  ocupada por `013_tenants_inventario_estricto.sql`) y tenant 036, así que al
  aterrizar toma master 016 y tenant 037.
- **Master 017**: `db/master_migrations/017_tenants_edicion_ambas.sql` (2026-11-02).
  Amplía `tenants.edicion` a `ENUM('completa','express','ambas')`: una empresa
  `ambas` usa la app completa Y Express (el dueño pidió que una empresa pueda
  usar las dos versiones; `express` limita a la empresa entera). Toma la 017 y
  no la 016 a propósito: la 016 sigue reservada para el plan ITBIS, que todavía
  no tiene archivo, y así ninguna reserva se renumera. Necesita la 015.
- **Master 019**: `db/master_migrations/019_users_acceso_global.sql` (2026-11-14).
  Agrega `users.acceso_global TINYINT(1) NOT NULL DEFAULT 0`: el usuario de soporte
  que entra a todas las empresas (`docs/modules/acceso-global.md`). No enciende a
  nadie. La 018 es `018_integracion_request_log.sql`; la 016 sigue reservada.
- Antes de crear una migración: `git fetch` y las dos listas, una por serie (con
  la barra final, que lista los archivos y no el directorio):
  `git ls-tree --name-only origin/master db/migrations/ | tail -4` (tenant) y
  `git ls-tree --name-only origin/master db/master_migrations/ | tail -2` (master).
  Un solo comando con los dos directorios no sirve: `git ls-tree` lista el master
  primero y `tail -4` solo alcanza el final del tenant.
- El tenant tiene dos 014 (`014_add_factura_items_unidad_medida.sql` y
  `014_ncf_rangos_autorizados.sql`) y ninguna 015; el master es otra serie, con
  su propia numeración.
- **Tenant 038**: `db/migrations/038_cobros.sql` (2026-11-16). Crea `cobro_config`,
  `cobros`, `cobro_pagos` y `cobro_recordatorios` (Cobros, `docs/modules/cobros.md`).
  Toma la 038 porque la 036 es de Express y la 037 del plan ITBIS: ninguna de las
  dos tiene archivo todavía, y un hueco en la numeración no rompe nada (cada base
  se revisa con `tools/verificar_migraciones_tenant.sql`, fila por fila). Va en la
  base de CADA empresa antes de subir el código de Cobros.
- **Master 020**: `db/master_migrations/020_cobros_permiso.sql` (2026-11-16). Solo
  datos: da el permiso `cobros` al rol de sistema `user` de las empresas app ya
  creadas (las nuevas lo reciben de `config/permissions.php`). La 016 sigue
  reservada para el plan ITBIS.
- **Master 021**: `db/master_migrations/021_cobros_tenants.sql` (2026-11-17). Cobros de la
  PLATAFORMA a sus tenants: las mismas cuatro tablas del modulo Cobros (`cobro_config`,
  `cobros`, `cobro_pagos`, `cobro_recordatorios`), pero en la master y con FK a `tenants` (el
  cliente es un tenant). Solo las usa la cuenta de soporte (acceso global) por
  `/api/cobros-tenants`; no hay migracion de tenant. Va ANTES de subir el codigo que la usa.
- **Master 022**: `db/master_migrations/022_cobro_excluidos.sql` (2026-11-18). La tabla
  `cobro_excluidos` de Cobros de tenants: marca los tenants que NO son clientes (los que se
  crearon solo para certificar, los de prueba) para que no salgan en la pestana Clientes; no
  borra nada. Va ANTES de subir el codigo que la usa: el directorio de tenants la consulta.
- **Master 023**: `db/master_migrations/023_cobro_sin_pago.sql` (2026-11-19). La tabla
  `cobro_sin_pago` de Cobros de tenants: marca las empresas de un GRUPO (`tenants.grupo_id`) que NO
  pagan la mensualidad del grupo (solo certificaron); sin fila = paga; no borra nada. Va ANTES de
  subir el codigo que la usa: cada consulta de Cobros de tenants la lee, y sin la tabla responde 500.
- **Tenant 039**: `db/migrations/039_clients_lista_precio.sql` (2026-11-18). Agrega la
  columna `clients.lista_precio` (la lista de precio por defecto del cliente, 1 a 4;
  NULL = preguntar cada vez). Toma la 039 porque la 036 es de Express, la 037 del plan
  ITBIS y la 038 de Cobros. Va en la base de CADA empresa antes de subir el API que
  guarda la lista.
- **Tenant 040**: `db/migrations/040_pos_empleados_usuario.sql` (2026-11-19). Prepara
  `pos_empleados` para los usuarios de FiscalPoint como empleados del POS: agrega
  `user_id` (único; el usuario del master), deja `pin_hmac` y `pin_generado_at` nulos
  (NULL = PIN pendiente: el usuario lo crea al entrar al POS) y agrega `pin_choques` y
  `pin_choque_at` (el límite de PIN elegidos que ya usa otro empleado). Toma la 040
  porque la 036 es de Express, la 037 del plan ITBIS, la 038 de Cobros y la 039 de las
  listas de precio. Va en la base de CADA empresa **antes** de subir el API: el API nuevo
  nombra `user_id` en toda consulta de empleados y, sin la columna, el POS no entra.
- **Tenant 041**: `db/migrations/041_pos_caja_por_defecto.sql` (2026-11-24). Crea la caja
  "Caja 1" del POS en la empresa que no tiene ninguna (`INSERT … WHERE NOT EXISTS (SELECT 1
  FROM pos_cajas)`); una caja se renombra y se desactiva pero no se borra, así que no
  reaparece. Es **solo datos**: no tiene fila en `tools/verificar_migraciones_tenant.sql`
  (el verificador solo lee `information_schema` y contar filas lo rompería en una base sin
  la 030; mismo caso que la master 020), y su resultado es la última consulta del archivo.
  Toma la 041 porque la 040 es de los usuarios como empleados del POS. Va en la base de CADA
  empresa; no depende del código del API.
- Los encabezados que dicen "hasta la 041" (este README y `db/tenant_schema.sql`) cuentan la
  038, la 039, la 040 y la 041, que sí tienen archivo; `tools/verificar_migraciones_tenant.sql`
  llega hasta la 040 (la 041 no tiene fila).
  Siguen sin nombrar la 036 ni la 037: un snapshot o un verificador no debe nombrar
  una migración cuyo archivo no existe. Los planes de Express que crean la master
  015 y la tenant 036 los suben junto con el SQL; mientras tanto, este bloque y
  `tools/test_express_reservas.php` llevan la reserva.

## deprecated/

Migraciones 001–011, ya consolidadas dentro de `tenant_schema.sql` (2026-06-09).
Se conservan como historial de los DBs que se actualizaron incrementalmente.
NO ejecutarlas en tenants nuevos.
