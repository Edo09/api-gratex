# Documentación — Gratex API

API REST en PHP para facturación electrónica (e-CF) de la DGII (República Dominicana).
Sistema **multi-tenant** en producción; certificación DGII completa. Para el contexto de
asistentes IA ver `CLAUDE.md` en la raíz.

## Por dónde empezar

1. **[setup.md](setup.md)** — levantar el entorno local.
2. **[architecture.md](architecture.md)** — cómo está construido el sistema (multi-tenant,
   ruteo, capa de datos, núcleo e-CF).
3. **[api/facturas.md](api/facturas.md)** — referencia de la API de emisión (el doc más usado).

## Mapa de la documentación

### Raíz
| Doc | Para qué |
|---|---|
| [architecture.md](architecture.md) | Arquitectura: stack, ruteo, multi-tenant (DB-per-tenant), resolvers, núcleo e-CF |
| [setup.md](setup.md) | Entorno de desarrollo local (PHP sin Composer, `.env`, DB, server) |

### `api/` — referencia de endpoints
| Doc | Para qué |
|---|---|
| [api/facturas.md](api/facturas.md) | Emisión e-CF: payloads por tipo (E31–E47), estado DGII, stats, rangos NCF, unidades |
| [api/facturas-simples.md](api/facturas-simples.md) | Facturas NO electrónicas (sin e-CF) |
| [api/recepcion-aprobacion.md](api/recepcion-aprobacion.md) | e-CF recibidos + aprobación comercial (saliente/entrante); `ecf_recibidos` vs `aprobaciones_comerciales` |
| [api/ncf.md](api/ncf.md) | Endpoint NCF y secuencia legacy `B01` |
| [api/reportes-606-607.md](api/reportes-606-607.md) | Formatos 606 (compras) y 607 (ventas) DGII |

### `database/`
| Doc | Para qué |
|---|---|
| [database/schema.md](database/schema.md) | Esquema: split master/tenant, todas las tablas, migraciones |

### `modules/`
| Doc | Para qué |
|---|---|
| [modules/gastos.md](modules/gastos.md) | Módulo de gastos (menores + facturas de proveedores), auto-emisión |
| [modules/branding-plantillas.md](modules/branding-plantillas.md) | Plantillas de Representación Impresa por tenant, branding, logo, diseños a la medida |
| [modules/roles-permisos.md](modules/roles-permisos.md) | RBAC: roles per-tenant, permisos, gate central, `/api/roles` |

### `business-rules/`
| Doc | Para qué |
|---|---|
| [business-rules/representacion-impresa.md](business-rules/representacion-impresa.md) | Norma DGII de Representación Impresa (qué debe contener el documento) |

### `integrations/`
| Doc | Para qué |
|---|---|
| [integrations/dgii-ecf.md](integrations/dgii-ecf.md) | Integración DGII: flujos entrante/saliente, ARECF, auth, bugs resueltos |
| [integrations/multi-tenant-onboarding.md](integrations/multi-tenant-onboarding.md) | Alta de tenants (app/integración), demo, certificación, rutas públicas admin |

## Documentación co-localizada (junto al código)

- [../db/migrations/README.md](../db/migrations/README.md) — reglas de migraciones tenant/master.
- [../pasos_certificacion_dgii/README.md](../pasos_certificacion_dgii/README.md) — runners de las fases de certificación DGII.
