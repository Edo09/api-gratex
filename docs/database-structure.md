# Gratex API — Database Structure

MySQL / MariaDB (InnoDB). Accessed via PDO singleton (`src/Database.php`).

- **Server DB:** `mtldtmte_new_gratexdb` (NOT old `mtldtmte_gratexdb`)
- **Charset:** base tables `latin1`; e-CF tables `utf8mb4` (`utf8mb4_unicode_ci`)
- **Schema source:** `db/database.sql` (base) + `db/migrations/001`–`006` (additive)

---

## Table Overview

| Table | Domain | Added by |
|---|---|---|
| `users` | app users / auth | base |
| `api_tokens` | API key tokens (our clients) | base |
| `clients` | customers (+ fiscal data for e-CF) | base + 001 |
| `cotizaciones` | quotes | base |
| `cotizacion_items` | quote line items | base |
| `facturas` | invoices (+ full e-CF tracking) | base + 001/003/005/006 |
| `factura_items` | invoice line items (+ fiscal class) | base + 001 |
| `ncf_sequences` | NCF + e-NCF sequence counters | base + 001 |
| `emisor_config` | issuer fiscal config (single row) | 001 |
| `ecf_recibidos` | incoming e-CF from other issuers | 002 |
| `aprobaciones_comerciales` | commercial approvals received (ACECF) | 002 |
| `auth_seeds` | auth seeds issued (DGII semilla flow) | 002 |
| `auth_tokens_emitidos` | Bearer tokens issued to consumers | 002 |
| `landing_carousel` | landing page carousel | base |
| `landing_services` | landing page services | base |

---

## Relationships

```
users 1───* api_tokens          (FK user_id, ON DELETE CASCADE)
users 1───* facturas            (user_id, nullable — no FK constraint)

clients 1───* cotizaciones      (client_id, nullable)
clients 1───* facturas          (client_id, nullable)

cotizaciones 1───* cotizacion_items   (FK cotizacion_id, ON DELETE CASCADE)
facturas     1───* factura_items      (FK factura_id,    ON DELETE CASCADE)

facturas 1───* aprobaciones_comerciales   (factura_id, nullable — soft link via e_ncf)

ncf_sequences   — standalone counters (B01/B02/B14/B15 + E31..E47)
emisor_config   — single row (id=1)
ecf_recibidos / auth_seeds / auth_tokens_emitidos — standalone (DGII receiver role)
```

---

## Core Tables

### `users`
| Column | Type | Notes |
|---|---|---|
| `id` | int PK AI | |
| `name` | varchar(70) | |
| `last_name` | varchar(70) | |
| `email` | varchar(300) | |
| `username` | varchar(50) | UNIQUE |
| `password` | varchar(255) | bcrypt hash |
| `role` | varchar(20) | default `user` |

### `api_tokens`
| Column | Type | Notes |
|---|---|---|
| `id` | int PK AI | |
| `user_id` | int | FK → users(id) CASCADE |
| `token_hash` | varchar(64) | UNIQUE, sha256 of token |
| `created_at` | datetime | |
| `last_used` | datetime | updated each validate |
| `is_active` | tinyint(1) | default 1 |

### `clients`
| Column | Type | Notes |
|---|---|---|
| `id` | int PK AI | |
| `email` | varchar(100) | |
| `client_name` | varchar(100) | |
| `company_name` | varchar(100) | |
| `phone_number` | varchar(20) | |
| `rnc` | varchar(11) | (001) fiscal ID — required E31 |
| `razon_social` | varchar(150) | (001) |
| `direccion` | varchar(100) | (001) |
| `municipio` | varchar(50) | (001) |
| `provincia` | varchar(50) | (001) |

### `cotizaciones`
| Column | Type | Notes |
|---|---|---|
| `id` | int PK AI | |
| `code` | varchar(50) | |
| `date` | datetime | default CURRENT_TIMESTAMP |
| `client_id` | int | nullable |
| `client_name` | varchar(100) | |
| `total` | decimal(10,2) | default 0.00 |

### `cotizacion_items`
| Column | Type | Notes |
|---|---|---|
| `id` | int PK AI | |
| `cotizacion_id` | int | FK → cotizaciones(id) CASCADE |
| `description` | text | |
| `amount` | decimal(10,2) | unit price |
| `quantity` | int | default 1 |
| `subtotal` | decimal(10,2) | |

---

## Invoice Tables (e-CF core)

### `facturas`
Base columns + e-CF tracking (migrations 001/003/005/006).

| Column | Type | Notes |
|---|---|---|
| `id` | int PK AI | |
| `no_factura` | varchar(50) | |
| `date` | datetime | default CURRENT_TIMESTAMP |
| `client_id` | int | nullable |
| `client_name` | varchar(100) | |
| `total` | decimal(10,2) | |
| `NCF` | varchar(50) | nullable after 001 |
| `user_id` | int | nullable |
| **e-CF (001)** | | |
| `tipo_ecf` | varchar(2) | 31,32,33,34,41,43,44,45,46,47 |
| `e_ncf` | varchar(13) | UNIQUE (`uk_e_ncf`) |
| `track_id` | varchar(60) | DGII TrackId — INDEX |
| `estado_dgii` | varchar(20) | PENDIENTE/ENVIADO/ACEPTADO/ACEPTADO_CONDICIONAL/RECHAZADO/ERROR — INDEX |
| `codigo_seguridad` | varchar(10) | for QR / printed rep |
| `fecha_emision_dgii` | datetime | |
| `ambiente_dgii` | varchar(20) | testecf/certecf/ecf |
| `xml_firmado` | mediumtext | signed XML sent |
| `respuesta_dgii` | text | last DGII response (JSON) |
| **RFCE (003)** | | |
| `rfce_xml` | mediumtext | resumen XML (E32 < 250k) |
| `rfce_track_id` | varchar(60) | INDEX |
| `rfce_estado` | varchar(30) | |
| `rfce_respuesta` | text | |
| **Status (005)** | | |
| `secuencia_utilizada` | tinyint(1) | DGII: false=reusable e-NCF |
| **Note ref (006)** | | E33/E34 |
| `ncf_modificado` | varchar(19) | e-NCF the note modifies |
| `fecha_ncf_modificado` | date | |
| `codigo_modificacion` | varchar(2) | 1=Anula 2=Texto 3=Montos 4=Contingencia 5=Ref consumo |
| `razon_modificacion` | varchar(90) | shown on printed rep |

### `factura_items`
| Column | Type | Notes |
|---|---|---|
| `id` | int PK AI | |
| `factura_id` | int | FK → facturas(id) CASCADE |
| `description` | text | |
| `amount` | decimal(10,2) | unit price |
| `quantity` | int | default 1 |
| `subtotal` | decimal(10,2) | |
| `indicador_facturacion` | tinyint | (001) 0=No fact 1=ITBIS18 2=ITBIS16 3=ITBIS0 4=Exento |
| `indicador_bien_servicio` | tinyint | (001) 1=Bien 2=Servicio |
| `unidad_medida` | varchar(10) | (014) código DGII (43 = unidad); columna "Und. Medida" de la RI |
| `itbis_amount` | decimal(18,2) | (001) |

### `ncf_sequences`
Counters; `current_value` = last used (next = +1). UNIQUE on `type`.

| Column | Type | Notes |
|---|---|---|
| `id` | int PK AI | |
| `type` | varchar(10) | UNIQUE |
| `prefix` | varchar(10) | |
| `current_value` | int | default 0 |
| `description` | varchar(100) | |
| `created_at` / `updated_at` | datetime | auto |

Seeded types: `B01` Crédito Fiscal, `B02` Consumidor Final, `B14` Reg. Especiales, `B15` Gubernamental.
e-NCF (001): `E31` Crédito Fiscal, `E32` Consumo, `E33` Nota Débito, `E34` Nota Crédito, `E41` Compras, `E43` Gastos Menores, `E44` Reg. Especiales, `E45` Gubernamental, `E46` Exportaciones, `E47` Pagos al Exterior.

> Note: NCF sequences are **per-ambiente** on the server (`tools/migration_ncf_ambiente.sql`).

### `emisor_config`
Single row (`id=1`), issuer fiscal data.

| Column | Type | Notes |
|---|---|---|
| `id` | int PK AI | always 1 |
| `rnc` | varchar(11) | |
| `razon_social` | varchar(150) | |
| `nombre_comercial` | varchar(150) | |
| `sucursal` | varchar(20) | |
| `direccion` | varchar(100) | |
| `municipio` / `provincia` | varchar(50) | |
| `telefono` | varchar(12) | 999-999-9999 |
| `correo` | varchar(80) | |
| `website` | varchar(50) | |
| `actividad_economica` | varchar(100) | |
| `fecha_vencimiento_secuencia` | date | DGII auth seq expiry |

---

## e-CF Receiver Tables (we act as receiver)

### `ecf_recibidos`
Incoming e-CFs from other issuers (DGII recepción URL).

| Column | Type | Notes |
|---|---|---|
| `id` | int PK AI | |
| `track_id` | varchar(60) | UNIQUE |
| `tipo_ecf` | varchar(2) | |
| `e_ncf` | varchar(13) | UNIQUE with rnc_emisor |
| `rnc_emisor` | varchar(11) | |
| `razon_social_emisor` | varchar(150) | |
| `rnc_comprador` | varchar(11) | must match emisor_config |
| `monto_total` | decimal(18,2) | |
| `fecha_emision` | date | |
| `fecha_recepcion` | datetime | default now — INDEX |
| `estado` | varchar(30) | RECIBIDO/EN_PROCESO/ACEPTADO/RECHAZADO/ERROR_FIRMA/ERROR_XSD — INDEX |
| `codigo_resultado` | int | 1=Aceptado 2=Rechazado |
| `mensaje_resultado` | varchar(500) | |
| `xml_firmado` | mediumtext | |
| `validacion_firma` | varchar(20) | OK/INVALIDA/NO_VERIFICADA |
| `created_at` | datetime | |

### `aprobaciones_comerciales`
Commercial approvals/rejections (ACECF) buyers send for our invoices.

| Column | Type | Notes |
|---|---|---|
| `id` | int PK AI | |
| `factura_id` | int | nullable, soft link via e_ncf — INDEX |
| `e_ncf` | varchar(13) | INDEX |
| `rnc_emisor` | varchar(11) | our RNC |
| `rnc_comprador` | varchar(11) | buyer — INDEX |
| `estado_comercial` | varchar(30) | ACEPTADO/ACEPTADO_CONDICIONAL/RECHAZADO |
| `detalle_motivo` | varchar(500) | |
| `xml_firmado` | mediumtext | |
| `validacion_firma` | varchar(20) | |
| `fecha_recepcion` | datetime | default now |

### `auth_seeds`
Seeds issued by our autenticación URL (DGII semilla flow).

| Column | Type | Notes |
|---|---|---|
| `id` | int PK AI | |
| `seed_value` | varchar(64) | UNIQUE |
| `xml_emitido` | text | |
| `created_at` | datetime | |
| `expira_at` | datetime | default +5 min — INDEX |
| `consumida_at` | datetime | NULL = unused |
| `rnc_consumidor` | varchar(11) | from cert on validate |
| `token_emitido` | varchar(2048) | |

### `auth_tokens_emitidos`
Bearer tokens issued to authenticated consumers (DGII calls).

| Column | Type | Notes |
|---|---|---|
| `id` | int PK AI | |
| `token` | varchar(2048) | INDEX on first 64 chars |
| `rnc_consumidor` | varchar(11) | INDEX |
| `expedido_at` | datetime | default now |
| `expira_at` | datetime | INDEX |
| `revocado_at` | datetime | NULL = active |

---

## Landing Tables

### `landing_carousel`
`id` PK AI, `title` varchar(255), `subtitle` varchar(255) null, `image_path` varchar(500), `created_at` datetime.

### `landing_services`
`id` PK AI, `title` varchar(255), `description` text null, `image_path` varchar(500), `created_at` datetime.

---

## Migrations (`db/migrations/`)

| File | What it adds |
|---|---|
| `001_add_ecf_module.sql` | client fiscal cols, factura e-CF cols, factura_items fiscal cols, E31–E47 sequences, `emisor_config` |
| `002_add_ecf_reception.sql` | `ecf_recibidos`, `aprobaciones_comerciales`, `auth_seeds`, `auth_tokens_emitidos` |
| `003_add_rfce_tracking.sql` | factura RFCE cols |
| `004_fase2_setup.sql` | data: cert test issuer/clients (Fase 2) |
| `005_add_secuencia_utilizada.sql` | factura `secuencia_utilizada` |
| `006_add_nota_referencia.sql` | factura note-reference cols (E33/E34) |

All migrations are **additive**. 003/005/006 omit transactions (DDL auto-commit, MySQL 8).
