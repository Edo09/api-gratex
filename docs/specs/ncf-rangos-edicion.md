# Editar rangos e-NCF autorizados — Spec

| | |
|---|---|
| **Estado** | Implementado · 2026-11-20 (API y modal; falta desplegar y probar en pantalla) |
| **Repos** | `api-gratex` (endpoint) · `fiscalo` (modal «Rangos e-NCF autorizados») |
| **Tamaño** | Cambio pequeño: un endpoint, un formulario y una corrección de fecha. Sin migración |

---

## 1. Problema

El 2026-11-20 Q & F Variedades (tenant 17, `smhynzte_005`) registró su rango E31 con vencimiento
**2027-12-21** en vez de **2027-12-31**. La DGII rechazó tres envíos con el código 145 («Fecha de
vencimiento de secuencia inválida») y la corrección fue un `UPDATE` directo en la base. La app solo
sabe **listar** y **registrar** rangos: uno mal escrito no se puede corregir, y volver a registrarlo
choca con el control de solapes de `ncfModel::registerRange`.

Además, la tabla del modal mostró «30 dic de 2027» para una fecha guardada como 2027-12-31
(`formatApiDate` lee `2027-12-31` como UTC y en RD, UTC−4, cae el día anterior).

## 2. Qué se quiere

**Objetivos**

1. Quien tenga el módulo `ncf` corrige desde el modal: fecha de vencimiento, No. solicitud, No.
   autorización, número «Hasta» y número «Desde» (este solo mientras el rango no tenga números usados).
2. Ninguna edición reescribe lo ya emitido: el servidor rechaza lo que lo haría.
3. Queda en la bitácora quién cambió qué.
4. La fecha que se ve es la que está guardada.

**No-objetivos:** borrar rangos · editar tipo, ambiente o el contador (`current_value`) · editar las
filas «sin límite» (se formalizan con «Registrar rango aprobado») · permisos nuevos · migración ·
endpoint para integración.

**Anti-objetivos** (fallaría aunque «funcione»): un número usado que quede fuera de su rango · dos
rangos solapados · una tabla que muestre una fecha distinta de la que se envía a la DGII · un
formulario de edición rellenado con la fecha mal mostrada.

## 3. Alternativas

| | Qué es | Por qué sí / no |
|---|---|---|
| **A (elegida)** | `PUT /api/ncf/rangos/{id}` dedicado. Reglas en el servidor, reflejadas en el formulario | Es el mismo reparto de «Registrar» (`registerRange` ↔ `problemaConExistentes`). Una ruta, una bitácora |
| B | `POST /api/ncf/rangos` como «registrar o reemplazar» | El POST existe para **rechazar** solapes; la identidad de un rango es tipo + «Desde», así que no puede expresar un cambio de «Desde» |
| C | Un endpoint por campo | Triplica rutas, documentos y pruebas sin ganar nada |

Las dos partes del cómo (PHP en este repo, React en `fiscalo`) son las que el proyecto ya usa. El
usuario describió el resultado, así que la alternativa la elegí yo.

## 4. Diseño

### 4.1 API — `PUT /api/ncf/rangos/{id}`

- Mismo módulo que el POST (`ncf`): sin permiso nuevo. CORS ya permite `PUT`.
- Cuerpo JSON **parcial**: `fecha_vencimiento` (`Y-m-d`), `no_solicitud`, `no_autorizacion` (hasta 20
  caracteres, vacío = sin dato), `numero_hasta`, `numero_desde`. Si trae `type`, `ambiente` o
  `current_value`: 422 (no se ignoran en silencio).
- `200` con el rango en la forma de `GET` (`usados`, `restantes`, `estado`). `404` si el `id` no es de
  un rango del ambiente activo. `422` con texto en español para cada regla. `400` si el cuerpo no se
  lee. Sin cambios: `200` sin tocar la base ni la bitácora.
- Lógica en `ncfModel::updateRange`, en una transacción con `SELECT … FOR UPDATE` sobre las filas del
  tipo y ambiente (como `registerRange`): el dispensador no puede mover el contador a mitad de camino.

### 4.2 Reglas

1. Solo rangos con `numero_hasta`. Una fila «sin límite» responde 422 y manda a «Registrar rango aprobado».
2. `fecha_vencimiento`: fecha válida `Y-m-d`, la misma regla del registro. Se permite cualquiera, también
   una pasada: así se corrige un rango que una fecha mal puesta dejó «vencido».
3. **Último usado** = el mayor entre `current_value` y el mayor e-NCF de `facturas` (y, en E41/E43/E47, de `gastos`:
   solo los que la empresa emite, `es_auto_emision = 1`) del tipo y ambiente que esté dentro del rango
   (desde su «Desde» hasta antes del «Desde» del rango siguiente) y **no** en un estado reintentable
   (`EstadosEcf::REINTENTABLES`, el criterio de `eNcfYaUsado`). El contador manda; lo emitido cubre un
   contador atrasado (el caso del reenvío del 2026-11-20). *Añadido en la revisión final: los tipos de gastos
   guardan su e-NCF en `gastos.ncf`, no en `facturas`.*
4. `numero_hasta`: entero, ≥ «Desde», ≥ último usado y menor que el «Desde» del rango siguiente del
   mismo tipo. Puede subir hasta cubrir números que el POS usó sin rango (`sin_rango`), igual que el registro.
5. `numero_desde`: solo si el rango no tiene usados; mayor que el «Hasta» (o el consumo, si es legado)
   del rango anterior del tipo (el de «Desde» menor más cercano) y ≤ «Hasta». Al cambiarlo,
   `current_value = nuevo_desde − 1`. Un choque
   con `uq_type_amb_desde` da un mensaje claro, no el error de MySQL.
6. Bitácora: `NCF_RANGE_UPDATE` (módulo `ncf`, entidad `ncf_rango`), con `old_values` y `new_values` solo
   de lo que cambió y el `id` del rango.

### 4.3 Interfaz (`fiscalo`, `RangosNcfModal.tsx`)

```
E31  1–10   1   9   31 dic 2027   ● Activo   [✎]

Editar rango E31
  Fecha vencimiento [2027-12-31]    Hasta [10]    Desde [1]  (bloqueado: ya hay 1 usado)
  No. Solicitud [          ]        No. Autorización [          ]
  Es la fecha que se envía a la DGII: debe coincidir con tu autorización.
                                                    [Cancelar]  [Guardar cambios]
```

- ✎ en cada fila con rango (no en «sin límite»); abre el mismo formulario del registro con los datos
  del rango. «Tipo» fijo. «Desde» deshabilitado si `usados > 0`. «Guardar cambios» deshabilitado sin
  cambios. El error del servidor se muestra tal cual, como en el registro.
- Al guardar: aviso de éxito e invalidar las mismas consultas que el registro (`['ncf']`, stats y
  avisos de facturas, stats de gastos).
- `src/api/ncf.ts`: `updateNcfRango(id, input)` con un `putJson` nuevo en `http.ts` (hoy solo hay
  `postJson`); `UpdateRangoInput` en `types.ts`.
- `rangosNcf.ts`: `problemaConEdicion` repite las reglas 4 y 5 sobre los rangos ya cargados (sin las
  facturas, que solo ve el servidor) para avisar antes de enviar.

### 4.4 Fecha mostrada un día antes

`formatApiDate` debe leer una fecha sin hora (`YYYY-MM-DD`) como fecha local. Se mueve de
`src/api/mappers.ts` a `src/api/fechas.ts` (sin imports, para probarlo con Node, como `estadosDgii.ts`)
y `mappers.ts` lo re-exporta: nadie cambia su import.
Lo usan **29 llamadas en 14 archivos** y ninguna prueba lo cubre: el arreglo corrige la tabla de rangos
y también cualquier otra fecha sin hora; hay que mirar esos sitios antes de dar el cambio por bueno. El
formulario de edición se rellena con la cadena cruda del API, nunca con la fecha formateada.

## 5. Orden, pruebas y lo que no se verifica aquí

- **Despliegue:** primero el API (cPanel), después el front (Vercel). Si el front saliera primero, el ✎
  llamaría a un `PUT` que todavía cae en la rama legada «Actualizar NCF de factura» (responde 200 con
  `status:false`, «Factura ID is required»).
- **Pruebas API** (sin base ni red): sección nueva en `tools/test_write_ahead_puro.php` con conexión
  falsa (reglas 1–5, transacción y bloqueo, 404/422) y revisión del controlador por texto (ruta,
  bitácora, códigos). Primero la prueba que falla, después el código.
- **Pruebas front:** `node scripts/test-rangos-ncf.ts` para `problemaConEdicion` y `formatApiDate`;
  `npm run typecheck` y `npm run lint`.
- **No se corre aquí:** nada contra la base real. La prueba con MySQL de prueba queda anotada para quien
  tenga la base `wa_scratch`.

## 6. Decisiones mías (revisar)

- Cualquier fecha válida, también pasada (regla 2).
- `type`, `ambiente` o `current_value` en el cuerpo dan 422 en lugar de ignorarse.
- `422` (no `409`) para los choques, como el registro.
- «Último usado» incluye las facturas, no solo el contador (regla 3).
- El texto de ayuda bajo la fecha, sin diálogo de confirmación aparte.
- Esta spec va en `docs/specs/` (convención del repo) y no se commitea hasta que lo pidas.

## 7. Para quien lo construya

Quedan a su criterio: el texto exacto de cada mensaje (mismo tono que los de `registerRange`), cómo
resolver «el rango siguiente» con una sola consulta y los nombres internos. Todo lo demás está arriba.
