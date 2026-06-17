# Gratex API — Estructura de Base de Datos

MySQL / MariaDB (InnoDB), accedido por el singleton PDO `src/Database.php` (negocio del
tenant) y `src/MasterDatabase.php` (master / routing).

El sistema es **multi-tenant (DB-per-tenant)** en producción. Hay **dos clases de base**:

- **Master (`gratex_master`)** — directorio de tenants + autenticación + datos globales.
  Cero datos de negocio. Esquema: `db/master_schema.sql` (+ `db/master_migrations/`).
- **DB del tenant** (ej. la de Gratex: `mtldtmte_new_gratexdb`) — datos de negocio de
  **una** empresa: facturas, clientes, NCF, e-CF, gastos, etc. Esquema:
  `db/tenant_schema.sql` (+ `db/migrations/`).

> **Modo single-tenant (fallback):** con `MULTI_TENANT_ENABLED=false`, todo vive en una
> sola DB (la del tenant incluye también `users`/`api_tokens`/`landing_*`/`auth_*`). El
> esquema `tenant_schema.sql` por eso incluye esas tablas — sirven al fallback. En
> multi-tenant, la fuente de verdad de esas tablas es el master.

- **Charset:** tablas base `latin1`; tablas e-CF `utf8mb4` (`utf8mb4_unicode_ci`).
- Detalle de la arquitectura multi-tenant: [../architecture.md](../architecture.md).

---

## Master DB (`gratex_master`)

Fuente: `db/master_schema.sql`. Solo routing, auth y datos globales.

| Tabla | Para qué |
|---|---|
| `tenants` | Registro de cada empresa: `tipo` (app/integración), key+secret, credenciales DB cifradas, cert, ambiente, branding |
| `users` | Usuarios de login (+ `tenant_id`); email único global |
| `api_tokens` | Tokens de sesión (+ `tenant_id`); FK a `users` |
| `landing_carousel` / `landing_services` | Contenido de marketing (global) |
| `auth_seeds` / `auth_tokens_emitidos` | Flujo semilla/token DGII entrante (global, antes de resolver tenant) |
| `ecf_integracion_backup` | Respaldo de e-CF emitidos por tenants `integracion` (sin DB propia) |
| `ecf_recibidos` (+ `tenant_id`) | Espejo: e-CF recibidos de tenants `integracion` |
| `aprobaciones_comerciales` (+ `tenant_id`) | Espejo: aprobaciones de tenants `integracion` |
| `unidades_medida` | Catálogo DGII de unidades de medida (compartido por todos los tenants) |

### `tenants`
| Columna | Tipo | Notas |
|---|---|---|
| `id` | int PK AI | |
| `nombre` | varchar(100) | |
| `rnc` | varchar(11) | UNIQUE |
| `api_key` | varchar(64) | UNIQUE — identificador público (integración) |
| `api_secret_hash` | varchar(64) | sha256 del `api_secret` (se muestra el secret una sola vez) |
| `tipo` | varchar(12) | `app` \| `integracion` |
| `db_host`/`db_name`/`db_user` | varchar | Solo `app`; `db_name` UNIQUE |
| `db_pass_encrypted` | varbinary | AES-256-GCM: `iv(12)‖tag(16)‖ct` |
| `cert_path` | varchar(255) | `.p12` del tenant (`certificado_dgii/<rnc>/cert.p12`) |
| `cert_pass_encrypted` | varbinary | AES-256-GCM |
| `ambiente` | varchar(20) | `certecf` mientras certifica → `ecf` en producción (per-tenant) |
| `pdf_template` | varchar | `clasico`/`moderno`/`compacto`/`custom:tenant<id>` (master_migration 002) |
| `pdf_accent_color` | varchar(7) | `#RRGGBB` opcional |
| `logo_path` | varchar | `logos/<tenant_id>.<ext>` |
| `webhook_url`/`webhook_secret_encrypted` | varchar/varbinary | Push de documentos entrantes (integración) |
| `activo` | tinyint(1) | |
| `created_at` | datetime | |

`users` y `api_tokens` conservan su forma (ver abajo) + columna `tenant_id`.

---

## DB del tenant — Tablas de negocio

Fuente: `db/tenant_schema.sql` (snapshot consolidado, base + migraciones 001–016).

| Tabla | Dominio |
|---|---|
| `clients` | Clientes (+ datos fiscales para e-CF) |
| `cotizaciones` / `cotizacion_items` | Cotizaciones |
| `facturas` / `factura_items` | Facturas (+ tracking e-CF completo) |
| `ncf_sequences` | Secuencias NCF / e-NCF como **rangos autorizados** por DGII |
| `emisor_config` | Config fiscal del emisor (fila única) |
| `ecf_recibidos` | e-CF entrantes de otros emisores (rol receptor) |
| `aprobaciones_comerciales` | Aprobaciones comerciales (ACECF) recibidas |
| `gastos` / `gasto_items` | Gastos menores y facturas de proveedores |
| `products` | Catálogo de productos/servicios (migración 012) |
| `proveedores` | Directorio de proveedores (migración 013) |
| `auth_seeds` / `auth_tokens_emitidos` | Auth DGII (solo fallback single-tenant) |

---

## Tablas de autenticación

### `users` (master)
| Columna | Tipo | Notas |
|---|---|---|
| `id` | int PK AI | |
| `name` / `last_name` | varchar(70) | |
| `email` | varchar(300) | único **global** (resuelve el tenant en el login) |
| `username` | varchar(50) | único por tenant |
| `password` | varchar(255) | hash bcrypt |
| `role` | varchar(20) | default `user` |
| `tenant_id` | int | tenant al que pertenece |

### `api_tokens` (master)
| Columna | Tipo | Notas |
|---|---|---|
| `id` | int PK AI | |
| `user_id` | int | FK → users(id) CASCADE |
| `token_hash` | varchar(64) | UNIQUE, sha256 del token |
| `created_at` / `last_used` | datetime | `last_used` se actualiza en cada validación |
| `is_active` | tinyint(1) | default 1 |
| `tenant_id` | int | tenant del token (sin huevo-gallina) |

---

## Tablas comerciales (DB del tenant)

### `clients`
| Columna | Tipo | Notas |
|---|---|---|
| `id` | int PK AI | |
| `email` / `client_name` / `company_name` / `phone_number` | varchar | |
| `rnc` | varchar(11) | ID fiscal — requerido para E31 |
| `razon_social` | varchar(150) | |
| `direccion` / `municipio` / `provincia` | varchar | datos fiscales |

### `cotizaciones` / `cotizacion_items`
`cotizaciones`: `id`, `code`, `date`, `client_id` (nullable), `client_name`, `total`.
`cotizacion_items`: `id`, `cotizacion_id` (FK CASCADE), `description`, `amount`, `quantity`, `subtotal`.

### `products` (migración 012)
Catálogo de productos/servicios del tenant: `id`, `nombre`, `descripcion`, `precio`,
`unidad_medida` (código DGII), `indicador_facturacion` (1=ITBIS18, 4=Exento, 2=16%,
3=Tasa cero, 0=No facturable), `indicador_bien_servicio`, `activo`, timestamps.

### `proveedores` (migración 013)
Directorio para autocompletar/gestionar proveedores: `id`, `rnc`, `nombre`, contacto…
Los gastos siguen guardando `rnc_proveedor`/`nombre_proveedor` inline (desnormalizado);
esta tabla es solo el directorio. El listado deriva `compras` desde `gastos`.

---

## Tablas de factura (núcleo e-CF)

### `facturas`
Columnas base + tracking e-CF (migraciones 001/003/005/006).

| Columna | Tipo | Notas |
|---|---|---|
| `id` | int PK AI | |
| `no_factura` | varchar(50) | |
| `date` | datetime | default CURRENT_TIMESTAMP |
| `client_id` | int | nullable |
| `client_name` | varchar(100) | |
| `total` | decimal(10,2) | |
| `NCF` | varchar(50) | nullable tras 001 (facturas simples) |
| `user_id` | int | nullable (referencia a `master.users.id`, sin FK cross-DB) |
| **e-CF (001)** | | |
| `tipo_ecf` | varchar(2) | 31,32,33,34,41,43,44,45,46,47 |
| `e_ncf` | varchar(13) | UNIQUE (`uk_e_ncf`) |
| `track_id` | varchar(60) | TrackId DGII — INDEX |
| `estado_dgii` | varchar(20) | PENDIENTE/ENVIADO/ACEPTADO/ACEPTADO_CONDICIONAL/RECHAZADO/ERROR — INDEX |
| `codigo_seguridad` | varchar(10) | para QR / RI |
| `fecha_emision_dgii` | datetime | debe coincidir con `FechaHoraFirma` del XML |
| `ambiente_dgii` | varchar(20) | testecf/certecf/ecf |
| `xml_firmado` | mediumtext | XML firmado enviado |
| `respuesta_dgii` | text | última respuesta DGII (JSON) |
| **RFCE (003)** | | E32 < 250k |
| `rfce_xml` / `rfce_track_id` / `rfce_estado` / `rfce_respuesta` | | resumen |
| **Estado (005)** | | |
| `secuencia_utilizada` | tinyint(1) | DGII: false = e-NCF reutilizable |
| **Ref. nota (006)** | | E33/E34 |
| `ncf_modificado` | varchar(19) | e-NCF que modifica la nota |
| `fecha_ncf_modificado` | date | |
| `codigo_modificacion` | varchar(2) | 1=Anula 2=Texto 3=Montos 4=Contingencia 5=Ref consumo |
| `razon_modificacion` | varchar(90) | sale en la RI |

### `factura_items`
| Columna | Tipo | Notas |
|---|---|---|
| `id` | int PK AI | |
| `factura_id` | int | FK → facturas(id) CASCADE |
| `description` | text | |
| `amount` | decimal(10,2) | precio unitario |
| `quantity` | int | default 1 |
| `subtotal` | decimal(10,2) | |
| `indicador_facturacion` | tinyint | (001) 0=No fact 1=ITBIS18 2=ITBIS16 3=ITBIS0 4=Exento |
| `indicador_bien_servicio` | tinyint | (001) 1=Bien 2=Servicio |
| `unidad_medida` | varchar(10) | (014) código DGII (43 = Unidad); columna "Und. Medida" de la RI |
| `itbis_amount` | decimal(18,2) | (001) |

### `ncf_sequences` — rangos autorizados (migración 014)
Cada fila representa **un rango autorizado** por DGII para un tipo y ambiente.

| Columna | Tipo | Notas |
|---|---|---|
| `id` | int PK AI | |
| `type` | varchar(10) | tipo de comprobante |
| `prefix` | varchar(10) | |
| `current_value` | int | último número usado (próximo = +1) |
| `numero_desde` / `numero_hasta` | int | límites del rango (`numero_hasta` NULL = sin límite, fila legacy) |
| `no_solicitud` / `no_autorizacion` | varchar | identificadores DGII del rango |
| `fecha_vencimiento` | date | vencimiento del rango |
| `ambiente` | varchar(20) | secuencias **per-ambiente** (testecf/certecf/ecf) |
| `description` | varchar(100) | |
| `created_at` / `updated_at` | datetime | |

La emisión dispensa del rango ACTIVO (con capacidad y no vencido); al agotarse, falla con
error claro hasta registrar el siguiente rango. Ver `POST /api/ncf/rangos` en
[../api/facturas.md](../api/facturas.md).

Tipos B (legacy): `B01` Crédito Fiscal, `B02` Consumidor Final, `B14` Reg. Especiales, `B15` Gubernamental.
Tipos e-NCF: `E31` Crédito Fiscal, `E32` Consumo, `E33` Nota Débito, `E34` Nota Crédito,
`E41` Compras, `E43` Gastos Menores, `E44` Reg. Especiales, `E45` Gubernamental,
`E46` Exportaciones, `E47` Pagos al Exterior.

### `emisor_config`
Fila única (`id=1`) con los datos fiscales del emisor: `rnc`, `razon_social`,
`nombre_comercial`, `sucursal`, `direccion`, `municipio`, `provincia`, `telefono`,
`correo`, `website`, `actividad_economica`, `fecha_vencimiento_secuencia`.

---

## Tablas de receptor e-CF (rol receptor)

### `ecf_recibidos`
e-CF entrantes de otros emisores (URL de recepción DGII). Columnas clave: `track_id`
(UNIQUE), `tipo_ecf`, `e_ncf`, `rnc_emisor`, `razon_social_emisor`, `rnc_comprador`
(debe coincidir con el emisor_config), `monto_total`, `fecha_emision`, `fecha_recepcion`,
`estado` (RECIBIDO/EN_PROCESO/ACEPTADO/RECHAZADO/ERROR_FIRMA/ERROR_XSD), `codigo_resultado`,
`mensaje_resultado`, `xml_firmado`, `validacion_firma` (OK/INVALIDA/NO_VERIFICADA),
`ambiente` (migración 010), `origen` (migración 011), y las columnas salientes
`aprobacion_comercial*` (migración 009: tu decisión sobre cada e-CF recibido).

### `aprobaciones_comerciales`
Aprobaciones/rechazos (ACECF) que los compradores envían sobre **tus** facturas:
`factura_id` (nullable, soft link por `e_ncf`), `e_ncf`, `rnc_emisor` (tu RNC),
`rnc_comprador`, `estado_comercial`, `detalle_motivo`, `xml_firmado`, `validacion_firma`,
`ambiente`, `fecha_recepcion`.

> La diferencia entre `ecf_recibidos` y `aprobaciones_comerciales` (roles opuestos) está
> explicada en [../api/recepcion-aprobacion.md](../api/recepcion-aprobacion.md).

### `auth_seeds` / `auth_tokens_emitidos`
Semillas y tokens Bearer emitidos a consumidores autenticados (flujo DGII semilla→token).
En multi-tenant viven en el master; en el tenant existen para el fallback single-tenant.

---

## Tablas de gastos

### `gastos` (migraciones 007 + 008)
`id`, `categoria` (`gastos_menores`/`facturas_proveedores`), `tipo_gasto`, `ncf`,
`rnc_proveedor`, `nombre_proveedor`, `fecha`, `subtotal`, `itbis`, `total`,
`es_auto_emision`, `ambiente`, `user_id`, timestamps + (008) `estado_dgii`, `track_id`,
`codigo_seguridad`, `fecha_emision_dgii`, `xml_firmado`, `respuesta_dgii`,
`secuencia_utilizada`. UNIQUE `(rnc_proveedor, ncf)`.

### `gasto_items` (007 + 008/016)
`id`, `gasto_id` (FK CASCADE), `description`, `amount`, `quantity`, `subtotal`,
`itbis_amount` + (008) `indicador_facturacion`, `indicador_bien_servicio` + (016)
`unidad_medida`.

Detalle del módulo: [../modules/gastos.md](../modules/gastos.md).

---

## Relaciones (DB del tenant)

```
clients      1───* cotizaciones / facturas        (client_id, nullable)
cotizaciones 1───* cotizacion_items               (FK CASCADE)
facturas     1───* factura_items                  (FK CASCADE)
facturas     1───* aprobaciones_comerciales        (factura_id, soft link por e_ncf)
gastos       1───* gasto_items                     (FK CASCADE)
ncf_sequences / emisor_config / ecf_recibidos / proveedores / products — standalone
```

En master: `users 1───* api_tokens` (FK CASCADE); `tenants` referenciado por `tenant_id`
(int suelto, sin FK cross-DB).

---

## Fuentes de esquema y migraciones

| Artefacto | Para qué |
|---|---|
| `db/tenant_schema.sql` | Snapshot consolidado de la DB de tenant (base + 001–016). Lo aplica `tools/create_tenant.php` a tenants **nuevos** |
| `db/master_schema.sql` | Crea la DB master + tablas (instalaciones nuevas) |
| `db/migrations/NNN_*.sql` | Cambios incrementales para DBs de tenant **ya desplegados** (Gratex). Activas: 012–016 |
| `db/migrations/deprecated/001–011` | Ya consolidadas en `tenant_schema.sql` (2026-06-09). Historial; **no** correr en tenants nuevos |
| `db/master_migrations/NNN_*.sql` | Cambios incrementales del master |

Reglas de migración: ver [../../db/migrations/README.md](../../db/migrations/README.md).
Todas las migraciones son **aditivas**; las de DDL puro omiten transacción (auto-commit en MySQL 8).
