# Módulo de Gastos

Gestiona los gastos de la empresa en dos categorías:

1. **Gastos Menores** — pagos del personal (peajes, suministros). Tipo `E43`.
2. **Facturas de Proveedores** — comprobantes emitidos por la empresa a proveedores (`E41`, `E47`) y notas recibidas del proveedor (`E33`, `E34`).

La empresa actúa como **emisor** para `E41/E43/E47` (el sistema genera la secuencia interna) y como **receptor** para `E33/E34` (el usuario digita el NCF que entregó el proveedor).

> **2026-06-12:** `E31`/`B01` (Crédito Fiscal recibido) **ya no se registran como
> gasto** — esas facturas llegan por la recepción e-CF. El alta devuelve 400;
> las filas históricas se conservan en la tabla pero **quedan fuera de `/stats`**.

---

## Mapa de categorías y tipos

| categoria | tipo_gasto permitidos | NCF DGII | es_auto_emisión | NCF de dónde sale |
|---|---|---|---|---|
| `gastos_menores` | `E43` | 13 | **true** | secuencia interna (ncfModel) |
| `facturas_proveedores` | `E41` | 11 | **true** | secuencia interna (ncfModel) |
| `facturas_proveedores` | `E47` | 17 | **true** | secuencia interna (ncfModel) |
| `facturas_proveedores` | `E33` | 03 | false | lo digita el usuario (Nota de Débito recibida) |
| `facturas_proveedores` | `E34` | 04 | false | lo digita el usuario (Nota de Crédito recibida) |

Notas (E33/E34): el proveedor las emite contra una compra previa, por eso entran
como **recibidas** (el usuario digita el NCF). La Nota de Débito (03) y la Nota de
Crédito (04) existen también del lado de ingresos (`/api/facturas`); aquí se
registran cuando llegan **de un proveedor**.

`es_auto_emision` se **deriva** del `tipo_gasto` en el servidor (no se confía en el cliente):
tipos emitidos por la empresa = `E41, E43, E47`. El resto se considera recibido.

Combinación inválida → `400`. Ej:
`tipo_gasto E41 no permitido para la categoria gastos_menores. Permitidos: E43`

---

## Endpoints

Base: `/api/gastos`. Todas las rutas requieren token vía `AuthMiddleware`
(`X-API-KEY: <token>` o `Authorization: Bearer <token>`).

| Método | Ruta | Acción |
|---|---|---|
| `GET` | `/api/gastos` | Lista paginada (`?page`, `?pageSize`, `?query`, `?categoria`) |
| `GET` | `/api/gastos/{id}` | Un gasto con sus líneas |
| `GET` | `/api/gastos?id={id}` | Igual que el anterior |
| `GET` | `/api/gastos/stats` | Estadísticas de gastos |
| `GET` | `/api/gastos/{id}/estado` | Consulta estado del e-CF en DGII (auto-emision) |
| `GET` | `/api/gastos/{id}/xml` | XML firmado del e-CF emitido |
| `POST` | `/api/gastos` | Crear un gasto |

Otros métodos → `405`.

### Parámetros de la lista
- `page` (default 1), `pageSize` (default 10)
- `query` — busca en `ncf`, `rnc_proveedor`, `nombre_proveedor`, `tipo_gasto`
- `categoria` — filtra por `gastos_menores` o `facturas_proveedores`

La lista también filtra por **ambiente activo** (`DGII_ECF_ENVIRONMENT`), igual que el resto de endpoints e-CF.

---

## Cuerpo del POST

### Campos
| Campo | Requerido | Notas |
|---|---|---|
| `categoria` | sí | `gastos_menores` \| `facturas_proveedores` |
| `tipo_gasto` | sí | debe estar permitido para la categoría |
| `rnc_proveedor` | sí | RNC/Cédula. En Compras (11/E41) = proveedor informal |
| `nombre_proveedor` | sí | |
| `ncf` | solo si recibido (E33/E34) | el que entregó el proveedor. En auto-emisión se ignora y se genera |
| `items[]` | sí (≥1) | líneas del gasto |
| `fecha` | no | default: hoy (`Y-m-d`) |
| `subtotal` | no | default: suma de `subtotal` de los items |
| `itbis` | no | default: suma de `itbis_amount` de los items |
| `total` | no | default: `subtotal + itbis` |
| `user_id` | no | default: el del token |

### Línea (item)
| Campo | Requerido | Notas |
|---|---|---|
| `description` | sí | (también acepta `descripcion`) |
| `amount` | sí | precio unitario (también `precio_unitario`) |
| `quantity` | no | default 1 (también `cantidad`) |
| `subtotal` | no | default `amount * quantity` |
| `itbis_amount` | no | default 0 |

---

## Ejemplos

### 1. Gasto Menor (E43 — auto-emisión)
La empresa emite, el sistema genera la secuencia. **No** se manda `ncf`.

```http
POST /api/gastos
X-API-KEY: <tu-token>
Content-Type: application/json

{
  "categoria": "gastos_menores",
  "tipo_gasto": "E43",
  "rnc_proveedor": "00112345678",
  "nombre_proveedor": "Juan Perez (peajes)",
  "fecha": "2026-06-03",
  "items": [
    { "description": "Peaje Las Americas", "amount": 60, "quantity": 2 },
    { "description": "Suministros oficina", "amount": 350, "quantity": 1 }
  ]
}
```
Total calculado = 470.

### 2. Nota de Crédito de Proveedor (E34 — recibida)

Nota recibida del proveedor. **Sí** se manda el `ncf` del proveedor.

```http
POST /api/gastos
X-API-KEY: <tu-token>
Content-Type: application/json

{
  "categoria": "facturas_proveedores",
  "tipo_gasto": "E34",
  "ncf": "E340000000045",
  "rnc_proveedor": "131880681",
  "nombre_proveedor": "Suplidora XYZ SRL",
  "fecha": "2026-06-03",
  "items": [
    { "description": "Devolucion materiales", "amount": 1000, "quantity": 1, "itbis_amount": 180 }
  ]
}
```
subtotal = 1000, itbis = 180, total = 1180.

### curl
```bash
# Gasto menor
curl -X POST http://localhost/api/gastos \
  -H "X-API-KEY: TU_TOKEN" -H "Content-Type: application/json" \
  -d '{"categoria":"gastos_menores","tipo_gasto":"E43","rnc_proveedor":"00112345678","nombre_proveedor":"Juan Perez","items":[{"description":"Peaje","amount":60,"quantity":2}]}'

# Nota de credito de proveedor (recibida)
curl -X POST http://localhost/api/gastos \
  -H "X-API-KEY: TU_TOKEN" -H "Content-Type: application/json" \
  -d '{"categoria":"facturas_proveedores","tipo_gasto":"E34","ncf":"E340000000045","rnc_proveedor":"131880681","nombre_proveedor":"Suplidora XYZ","items":[{"description":"Devolucion","amount":1000,"quantity":1,"itbis_amount":180}]}'
```

---

## Emisión a DGII (auto-emision)

Los gastos que **emite la empresa** (E41/E43/E47) se envían a la DGII como e-CF real,
reusando el mismo pipeline de facturas (`ECFEmissionService`: build XML → firmar →
token → enviar). Los **recibidos** (E33/E34) solo se registran — ya los
emitió el proveedor.

### Guard de seguridad — `DGII_ECF_EMISSION_ENABLED`
La emisión real solo corre si la variable de entorno `DGII_ECF_EMISSION_ENABLED=true`.

- **`false` / ausente (default)**: los auto-emision se guardan como
  `estado_dgii = PENDIENTE_EMISION`, **sin llamar a DGII ni consumir secuencia**. La
  respuesta incluye un `aviso`. Protege el ambiente de producción `ecf`.
- **`true`**: emite de verdad (probar primero en `testecf`/`certecf`).

### Estados (`estado_dgii`)
`REGISTRADO` (recibido) · `PENDIENTE_EMISION` (guard apagado) · `ENVIADO` ·
`ACEPTADO` · `ACEPTADO_CONDICIONAL` · `RECHAZADO` · `EN_PROCESO` · `NO_ENCONTRADO` ·
`ERROR` (falló la emisión; se guarda igual para trazabilidad).

### Consultar estado / XML
```
GET /api/gastos/{id}/estado   -> consulta DGII por track_id + NCF, actualiza el registro
GET /api/gastos/{id}/xml      -> XML firmado (Content-Type: application/xml)
```

### Mapeo gasto → e-CF
`rnc_proveedor`→`comprador.rnc`, `nombre_proveedor`→`comprador.razon_social`.
E43 (Gastos Menores) se emite **sin comprador** y `rnc_proveedor` es opcional.
El ITBIS por línea se calcula del `indicador_facturacion` (1=18%, 2=16%, 3/4=0) si
no se envía `itbis_amount`.

## Estadísticas — `GET /api/gastos/stats`

Análogo a `GET /api/facturas/stats`, pero sobre la tabla `gastos`. Cada comprobante
usa su propio tipo: `E41` (Compras/11), `E43` (Gastos Menores/13), `E47` (Pagos
Exterior/17), `E33`/`E34` (notas recibidas). Las filas históricas `E31`/`B01`
(Crédito Fiscal/01, alta bloqueada) se **excluyen de todas las agregaciones**.
Filtra por ambiente activo.

Devuelve:
- `resumen` — `total_gastos`, `monto_total`, `subtotal_total`, `itbis_total`, `tipos_distintos`, `primer_gasto`, `ultimo_gasto`
- `por_tipo` — por `tipo_gasto`: `total`, `monto_total`, `subtotal_total`, `itbis_total`, `auto_emitidos`, `recibidos`, `nombre`
- `por_categoria` — por `categoria`: `total`, `monto_total`, `itbis_total`, `nombre`
- `por_mes` — últimos 12 meses: `mes`, `total`, `monto_total`
- `secuencias` — solo tipos auto-emitidos (`E41/E43/E47`): `type`, `secuencia_actual`, `total_emitidos`, `nombre`
- `ambiente_activo`

```json
{
  "status": true,
  "data": {
    "resumen": { "total_gastos": 12, "monto_total": 45200.00, "subtotal_total": 39800.00, "itbis_total": 5400.00, "tipos_distintos": 3, "primer_gasto": "2026-01-05", "ultimo_gasto": "2026-06-03" },
    "por_tipo": [
      { "tipo_gasto": "E41", "total": 5, "monto_total": 18000.00, "subtotal_total": 16000.00, "itbis_total": 2000.00, "auto_emitidos": 5, "recibidos": 0, "nombre": "Comprobante de Compras (11)" },
      { "tipo_gasto": "E43", "total": 3, "monto_total": 7200.00, "subtotal_total": 6800.00, "itbis_total": 400.00, "auto_emitidos": 3, "recibidos": 0, "nombre": "Comprobante para Gastos Menores (13)" }
    ],
    "por_categoria": [
      { "categoria": "facturas_proveedores", "total": 9, "monto_total": 38000.00, "itbis_total": 5000.00, "nombre": "Facturas de Proveedores" },
      { "categoria": "gastos_menores", "total": 3, "monto_total": 7200.00, "itbis_total": 400.00, "nombre": "Gastos Menores" }
    ],
    "por_mes": [ { "mes": "2026-06", "total": 2, "monto_total": 5000.00 } ],
    "secuencias": [
      { "type": "E41", "secuencia_actual": 5, "total_emitidos": 5, "nombre": "Comprobante de Compras (11)" },
      { "type": "E43", "secuencia_actual": 3, "total_emitidos": 3, "nombre": "Comprobante para Gastos Menores (13)" },
      { "type": "E47", "secuencia_actual": 0, "total_emitidos": 0, "nombre": "Comprobante para Pagos al Exterior (17)" }
    ],
    "ambiente_activo": "ecf"
  }
}
```

> Nota INGRESOS vs GASTOS: `/api/facturas/stats` cubre lo que la empresa **emite al
> vender** (01/E31, 02/E32, 15/E45, 14/E44, 16/E46, 03/E33, 04/E34). `/api/gastos/stats`
> cubre lo que **justifica costos** (11/E41, 13/E43, 17/E47, 03/E33, 04/E34
> recibidas; 01/E31 solo histórico). Notas de Débito/Crédito aparecen en ambos lados.

## Respuestas

### Éxito (POST) → `201`
Ejemplo de gasto auto-emision **con el guard apagado** (default en producción):
`estado_dgii = PENDIENTE_EMISION`, `ncf = null` y un `aviso`. Con el guard en `true`
el `ncf` sería el e-NCF asignado y `estado_dgii` el devuelto por DGII (`ENVIADO`/`ACEPTADO`…),
más `track_id` y `codigo_seguridad`.

```json
{
  "status": true,
  "data": {
    "id": 1,
    "categoria": "gastos_menores",
    "tipo_gasto": "E43",
    "ncf": null,
    "rnc_proveedor": null,
    "nombre_proveedor": "Juan Perez (peajes)",
    "fecha": "2026-06-03",
    "subtotal": "470.00",
    "itbis": "0.00",
    "total": "470.00",
    "es_auto_emision": 1,
    "estado_dgii": "PENDIENTE_EMISION",
    "track_id": null,
    "codigo_seguridad": null,
    "ambiente": "ecf",
    "user_id": 3,
    "items": [
      { "id": 1, "description": "Peaje Las Americas", "amount": "60.00", "quantity": 2, "subtotal": "120.00", "itbis_amount": "0.00", "indicador_facturacion": 4, "indicador_bien_servicio": 2 }
    ],
    "aviso": "Emision DGII deshabilitada (DGII_ECF_EMISSION_ENABLED=false). Gasto guardado como PENDIENTE_EMISION; no se envio a DGII ni se consumio secuencia."
  }
}
```

### Errores
| Código | Cuándo |
|---|---|
| `401` | token ausente o inválido |
| `404` | `GET /api/gastos/{id}` no existe |
| `405` | método no soportado |
| `422` | falta `categoria`, `tipo_gasto`, `rnc_proveedor`, `nombre_proveedor` o `items` |
| `400` | `tipo_gasto` no permitido para la categoría, falta `ncf` en recibido, o no hay secuencia disponible |

Formato de error: `{ "status": false, "error": "<mensaje>" }`.

---

## Detalles de implementación

### Archivos
| Archivo | Rol |
|---|---|
| `db/migrations/007_add_gastos_module.sql` | tablas `gastos` + `gasto_items` |
| `db/migrations/008_add_gastos_ecf_emission.sql` | columnas de tracking DGII + indicadores fiscales |
| `src/Models/gastoModel.php` | CRUD + emisión e-CF + stats |
| `src/Controllers/gastosController.php` | GET lista/id/stats/estado/xml, POST |
| `src/Router.php` | case `gastos` |
| `.env.example` | flag `DGII_ECF_EMISSION_ENABLED` |

### Ruta (Router.php)
```php
    case 'gastos':
        // Modulo de Gastos (emitidos 11/13/17 y recibidos 01) - token required
        require_once 'src/Controllers/gastosController.php';
        break;
```
`/api/gastos/{id}/estado` y `/xml` caen en el mismo controlador (el router despacha
por el primer segmento `gastos`).

### Esquema
**`gastos`** (007): `id`, `categoria`, `tipo_gasto`, `ncf`, `rnc_proveedor`,
`nombre_proveedor`, `fecha`, `subtotal`, `itbis`, `total`, `es_auto_emision`,
`ambiente`, `user_id`, `created_at`, `updated_at`.
(008) `estado_dgii`, `track_id`, `codigo_seguridad`, `fecha_emision_dgii`,
`xml_firmado`, `respuesta_dgii`, `secuencia_utilizada`.
UNIQUE `(rnc_proveedor, ncf)`. Índices: `categoria`, `tipo_gasto`, `rnc_proveedor`,
`ambiente`, `estado_dgii`, `track_id`.

**`gasto_items`**: `id`, `gasto_id` (FK → `gastos.id` ON DELETE CASCADE),
`description`, `amount`, `quantity`, `subtotal`, `itbis_amount` + (008)
`indicador_facturacion`, `indicador_bien_servicio`.

### Flujo de emisión
La emisión real reusa `ECFEmissionService::emitir()` — que internamente **dispensa
la secuencia y envía** a DGII. Por eso el modelo, para auto-emision con el guard
encendido, llama `emitir()` **primero** y luego guarda con el resultado (igual que
`facturaController`/`saveFacturaConECF`). Si `emitir()` lanza, el gasto se guarda con
`estado_dgii = ERROR` para trazabilidad.

```php
if (!$esAutoEmision)        return $this->persistGasto($g, $items);     // recibido
if (!$this->emissionEnabled()) { /* PENDIENTE_EMISION, sin DGII */ }
$ecf = $this->emitirGastoDgii(...);   // build + firmar + enviar
// guarda ncf=e_ncf, track_id, estado, codigo_seguridad, xml_firmado
```

### Métodos del modelo
Lectura/CRUD: `getGasto`, `getGastoItems`, `getGastosPaginated`, `getGastosCount`,
`createGasto`, `updateGasto`, `deleteGasto`.
Emisión/e-CF: `getEcfData`, `updateEcfEstado`, `getXmlFirmado`, `getGastosStats`,
`getActiveAmbiente` (+ privados `emissionEnabled`, `emitirGastoDgii`,
`mapItemsForEcf`, `computeTotalesEcf`).
(`updateGasto`/`deleteGasto` existen para CRUD completo; el controlador no los
expone — un e-CF emitido es inmutable, se corrige emitiendo una E34.)

---

## Antes de usar
- Correr las migraciones `db/migrations/007_add_gastos_module.sql` y
  `db/migrations/008_add_gastos_ecf_emission.sql` (007 = registro, 008 = columnas
  de emisión e-CF).
- Para auto-emisión (`E41/E43/E47`): `ncf_sequences` debe tener la fila del tipo en
  el ambiente activo (ya viene de `tools/migration_ncf_ambiente.sql`).
- ⚠️ **Producción `ecf`**: dejar `DGII_ECF_EMISSION_ENABLED=false` hasta probar en
  `testecf`/`certecf`. Con el guard apagado nada se envía a DGII.
- Estilo: sin librerías externas; errores con try-catch; respuestas JSON con
  `http_response_code`; rutas protegidas con `AuthMiddleware`.
