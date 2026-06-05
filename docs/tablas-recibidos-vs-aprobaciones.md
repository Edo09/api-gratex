# Diferencia: `ecf_recibidos` vs `aprobaciones_comerciales`

Las dos tablas guardan datos de aprobación comercial, pero en **direcciones
opuestas**. La clave para no confundirlas es **en qué rol estás** y **de quién es
la factura original**.

---

## Regla mnemónica

Mira de quién es la **factura original**:

- Factura **de otro** (te la emitieron a ti) → `ecf_recibidos`
- Factura **tuya** (la emitiste tú) → `aprobaciones_comerciales`

---

## `ecf_recibidos` — eres COMPRADOR

Facturas que **otros emisores te emiten a ti**.

- Entra por: `POST /api/ecf/recepcion` (DGII te empuja la factura)
- `rnc_comprador` = **tu** RNC
- `rnc_emisor` = del otro
- También guarda **tu decisión comercial saliente** sobre esas facturas
  (columnas `aprobacion_comercial*`, migration 009): cuando TÚ apruebas/rechazas
  un e-CF recibido vía `POST /api/aprobaciones-comerciales`.

## `aprobaciones_comerciales` — eres EMISOR

Veredictos que **compradores te mandan sobre facturas que TÚ emitiste**.

- Entra por: `POST /api/ecf/aprobacion-comercial` (entrante)
- `rnc_emisor` = **tu** RNC
- `rnc_comprador` = del otro

---

## Tabla comparativa

| | `ecf_recibidos` | `aprobaciones_comerciales` |
|---|---|---|
| Tu rol | **Comprador** | **Emisor** |
| Documento base | Factura que te mandaron | Factura que TÚ emitiste |
| Tu RNC es | `rnc_comprador` | `rnc_emisor` |
| Entra por | `/api/ecf/recepcion` | `/api/ecf/aprobacion-comercial` |
| Aprobación comercial | la que **TÚ envías** (saliente) | la que **te envían a ti** (entrante) |
| Controlador | `ecfRecepcionController.php` | `ecfAprobacionComercialController.php` |
| Modelo | `ecfRecibidoModel.php` | `aprobacionComercialModel.php` |
| `ambiente` | ✅ (migration 010) | ✅ (migration 010) |

Ambas guardan `ambiente` (`testecf`/`certecf`/`ecf`): el modo del server cuando
llegó el documento (migration 010).

---

## El punto que confunde

"Aprobación comercial" aparece en **las dos tablas**, pero en sentidos contrarios:

- En `ecf_recibidos` → **TÚ apruebas** facturas de otros (saliente; columnas
  `aprobacion_comercial*`).
- En `aprobaciones_comerciales` → **otros aprueban** tus facturas (entrante).

No se refieren a la misma factura: son documentos distintos, roles distintos.

---

## Relacionado

- Flujo completo de listar/aprobar e-CF recibidos: [aprobacion-comercial-recibidos.md](aprobacion-comercial-recibidos.md)
- Esquema de las tablas: `db/migrations/002_add_ecf_reception.sql` (creación),
  `db/migrations/009_add_aprobacion_comercial_tracking.sql` (columnas salientes) y
  `db/migrations/010_add_ambiente_recepcion.sql` (columna `ambiente`).
