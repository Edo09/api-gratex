# Diagrama de base de datos — multi-emisor (DB-per-tenant) con 5 clientes

Visualización de cómo se ve el sistema en MySQL con 5 clientes bajo la
arquitectura DB-per-tenant descrita en [multi-emisor-migration-plan.md](multi-emisor-migration-plan.md).

---

## Vista general

```
┌─────────────────────────────────────────────────────────────────────┐
│                         gratex_master                                 │
│                       (1 sola DB, control)                            │
│                                                                       │
│  tabla: tenants                                                       │
│  ┌────┬──────────────┬─────────────┬──────────┬──────────────────┐   │
│  │ id │ nombre       │ rnc         │ api_key  │ db_name          │   │
│  ├────┼──────────────┼─────────────┼──────────┼──────────────────┤   │
│  │  1 │ Gratex       │ 130-XXXXX-1 │ ak_g7f.. │ mtldtmte_new_gr..│   │
│  │  2 │ Cliente A    │ 131-XXXXX-2 │ ak_a2c.. │ tenant_clienteA  │   │
│  │  3 │ Cliente B    │ 132-XXXXX-3 │ ak_b9d.. │ tenant_clienteB  │   │
│  │  4 │ Cliente C    │ 133-XXXXX-4 │ ak_c1e.. │ tenant_clienteC  │   │
│  │  5 │ Cliente D    │ 134-XXXXX-5 │ ak_d4f.. │ tenant_clienteD  │   │
│  └────┴──────────────┴─────────────┴──────────┴──────────────────┘   │
│        (+ db_host, db_user, db_pass_encrypted, cert_path, ...)        │
└───────────┬──────┬──────┬──────┬──────┬──────────────────────────────┘
            │      │      │      │      │
   resuelve por api_key (nuestros clientes) o rnc (DGII incoming)
            │      │      │      │      │
   ┌────────┘      │      │      │      └────────┐
   ▼               ▼      ▼      ▼               ▼
┌──────────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐
│mtldtmte_new_ │ │tenant_   │ │tenant_   │ │tenant_   │ │tenant_   │
│gratexdb      │ │clienteA  │ │clienteB  │ │clienteC  │ │clienteD  │
│(tenant #1)   │ │          │ │          │ │          │ │          │
│              │ │          │ │          │ │          │ │          │
│ facturas     │ │ facturas │ │ facturas │ │ facturas │ │ facturas │
│ ncf          │ │ ncf      │ │ ncf      │ │ ncf      │ │ ncf      │
│ clients      │ │ clients  │ │ clients  │ │ clients  │ │ clients  │
│ ecf_recibidos│ │ ecf_re...│ │ ecf_re...│ │ ecf_re...│ │ ecf_re...│
│ emisor_config│ │ emisor.. │ │ emisor.. │ │ emisor.. │ │ emisor.. │
│ gastos       │ │ gastos   │ │ gastos   │ │ gastos   │ │ gastos   │
│ ... (todo el │ │ ...      │ │ ...      │ │ ...      │ │ ...      │
│  schema)     │ │          │ │          │ │          │ │          │
└──────────────┘ └──────────┘ └──────────┘ └──────────┘ └──────────┘
  MISMO schema repetido 5 veces — datos aislados 100%
```

---

## Puntos clave

- **6 DBs total**: 1 master + 5 tenant. Cada cliente nuevo = +1 DB.
- **Master** solo guarda routing (api_key/rnc → credenciales). Cero datos de negocio.
- **Tenant DBs** = clones del schema actual. Mismas tablas, datos separados.
- Cliente A nunca puede ver datos de Cliente B — DBs físicamente distintas, sin `WHERE emisor_id`.
- `emisor_config` vive **dentro** de cada tenant (1 fila, datos de esa empresa: RNC, nombre, logo, cert info).

---

## Flujo de un request

```
1. Request entra con  X-API-KEY: ak_a2c..
2. AuthMiddleware  → SELECT * FROM gratex_master.tenants WHERE api_key = 'ak_a2c..'
3. Encuentra Cliente A → db_name = tenant_clienteA  (+ credenciales cifradas)
4. Descifra db_pass (AES-256-GCM)
5. Database::setCredentials(host, tenant_clienteA, user, pass)
6. Código existente corre sin cambios → todos los queries van a tenant_clienteA
```

Para DGII incoming (sin API key) → resuelve por RNC del XML contra `gratex_master.tenants`.

---

## Comparación con alternativa single-DB

```
DB-per-tenant (este plan)        vs       Single-DB + emisor_id
─────────────────────────                 ─────────────────────────
6 DBs (1 master + 5)                       1 DB
schema ×5 (repetido)                       schema ×1, columna emisor_id en cada tabla
aislamiento físico                         aislamiento por WHERE (riesgo leak)
migration = loop sobre N DBs               migration = 1 sola DB
backup por cliente                         backup todo junto
```

Plan eligió **DB-per-tenant** por aislamiento total y cero cambios en código de negocio.
