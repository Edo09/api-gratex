# API del POS — acceso, equipos y empleados

Contrato de lo construido en la semana 1 del POS. El diseño completo, las reglas y
el porqué están en [../specs/pos.md](../specs/pos.md). Venta, turnos y devoluciones
se agregan aquí cuando existan.

Requiere las migraciones `master_migrations/012_pos.sql` y `migrations/030_pos.sql`,
la variable `POS_PIN_PEPPER` en el `.env` del server y `tenants.pos_enabled = 1`.

## Tres credenciales, tres encabezados

| Quién | Encabezado | Cómo se obtiene | Sirve para |
|---|---|---|---|
| Usuario de app.\* con el módulo `pos` | `Authorization: Bearer <token>` | `POST /api/auth/login` o el canje del código | `/api/pos-admin/*`, crear el código |
| Equipo de caja | `X-POS-EQUIPO: <token>` | `POST /api/pos-admin/equipos` (se ve **una** vez) | `/api/pos/*` y nada más |
| Empleado en ese equipo | `X-POS-SESION: <token>` | `POST /api/pos/sesion` con su PIN | Todo lo que hace un cajero |

El token del equipo no abre rutas de app.\* y el de usuario no sirve como equipo. Los
tres se guardan como sha256; el valor en claro solo existe del lado del cliente.

## Errores

Toda respuesta de error trae `{status:false, error, codigo}`; las de `/api/auth/*`
traen `success:false` en vez de `status`, como el login. **`error` es para la persona y
puede cambiar; `codigo` es estable** y es lo que decide la pantalla:

| `codigo` | HTTP | Pantalla en pos.\* |
|---|---|---|
| `EQUIPO_NO_HABILITADO` | 401 | Habilitar el equipo (login de admin) |
| `SESION_REQUERIDA` | 401 | Teclado de PIN |
| `PIN_INCORRECTO` | 401 | "PIN incorrecto", con `intentos_restantes` |
| `EQUIPO_BLOQUEADO` | 423 | Cuenta regresiva con `bloqueo_segundos` |
| `PIN_FORMATO` | 422 | El PIN tiene 4 dígitos y va como **texto** (`"0123"`, nunca `123`) |
| `CAJA_INACTIVA` | 403 | "La caja de este equipo está desactivada" |
| `POS_INACTIVO` | 403 | "El POS no está activo para esta empresa" |
| `EMPRESA_INACTIVA` | 403 | Igual |
| `CODIGO_INVALIDO` | 401 | El enlace del botón POS venció o se usó: login normal |
| `SIN_PERMISO` | 403 | El usuario no tiene el módulo `pos` |
| `CAJA_OCUPADA` | 409 | "Esta caja ya tiene un equipo": confirmar y repetir con `reemplazar: true` |
| `DUPLICADO`, `NOMBRE_REQUERIDO`, `ROL_INVALIDO`, `CAMPO_INVALIDO` | 409 / 422 | Error en el formulario |
| `POS_SIN_CONFIGURAR` | 500 | Falta `POS_PIN_PEPPER` en el server |
| `TURNO_REQUERIDO`, `TURNO_AJENO`, `TURNO_CAJA_OCUPADA`, `TURNO_EN_OTRA_CAJA`, `FONDO_INVALIDO` | 409 / 422 | Turno (abrirlo; el de otro cajero lo cierra un supervisor) |
| `TOTAL_DISTINTO` | 409 | Algún precio cambió: refrescar el catálogo y cobrar de nuevo (trae `total_centavos`) |
| `PRODUCTO_NO_DISPONIBLE`, `PRECIO_CERO`, `CANTIDAD_INVALIDA`, `LINEA_INVALIDA`, `VENTA_VACIA` | 409 / 422 | Corregir la venta (traen `product_id` cuando aplica) |
| `FORMA_PAGO_INVALIDA`, `RECIBIDO_INSUFICIENTE`, `COMPRADOR_REQUERIDO` | 422 | Corregir el cobro |
| `DGII_RECHAZO` | 422 | La DGII rechazó: no se cobró, la venta sigue en pantalla (trae `e_ncf`) |
| `EMISION_FALLIDA` | 502 | No se emitió (DGII caída antes de enviar, certificado...): reintentar o contingencia |
| `VENTA_EN_PROCESO` | 409 | Otra petición con la misma clave está emitiendo: reintentar con la MISMA clave |
| `GUARDADO_FALLIDO` | 500 | La DGII la recibió pero no se guardó: NO reintentar, avisar a soporte con el `e_ncf` |

Con `PERMISSIONS_ENFORCE=true` el gate central responde un 403 propio, sin `codigo`,
antes que `/api/pos-admin` (para pos.\* es el mismo `SIN_PERMISO`).

## Botón POS de app.\* (A1, A2)

**`POST /api/auth/pos-handoff`** — con la sesión de app.\*. Pide el módulo `pos`
y el POS activo.

```json
{ "success": true, "data": { "url": "https://pos.fiscalpoint.com.do/#code=<48 hex>", "code": "<48 hex>", "expira_en": 60 } }
```

app.\* abre `url` en una pestaña nueva. El código va después del `#`: no llega a
ningún servidor ni queda en logs.

**`POST /api/auth/pos-handoff/canje`** `{ "code": "<48 hex>" }` — público. Un solo
uso, 60 s. Responde **igual que el login** (`{success, data: {token, user}}`). pos.\*
usa ese token solo para habilitar el equipo y después lo cierra con
`POST /api/auth/signout`.

## Administración — `/api/pos-admin/*` (usuario con el módulo `pos`)

| Método y ruta | Cuerpo | Respuesta `data` |
|---|---|---|
| `GET /cajas` | — | `{cajas: [{id, nombre, activa, created_at, updated_at}]}` |
| `POST /cajas` | `{nombre}` | `{caja}` · 201 |
| `PUT /cajas/{id}` | `{nombre?, activa?: bool}` | `{caja}` |
| `GET /empleados` | — | `{empleados: [{id, nombre, rol, activo, pin_generado_at, ...}]}` (sin PIN) |
| `POST /empleados` | `{nombre, rol: "cajero"\|"supervisor"}` | `{empleado, pin}` · 201 — **el PIN se ve una sola vez** |
| `PUT /empleados/{id}` | `{nombre?, rol?, activo?: bool}` | `{empleado}` — desactivar cierra sus sesiones |
| `POST /empleados/{id}/pin` | — | `{empleado, pin}` — el anterior deja de servir y sus sesiones se cierran |
| `GET /equipos` | — | `{equipos: [{id, caja_id, caja, nombre, last_used, bloqueado, bloqueo_segundos, habilitado_por_nombre}]}` |
| `POST /equipos` | `{caja_id, nombre?, reemplazar?: bool}` | `{equipo: {id, nombre, caja}, token}` · 201 — **el token se ve una sola vez** |
| `DELETE /equipos/{id}` | — | `{revocado}` — el equipo deja de entrar y sus sesiones se cierran |

Una caja tiene un solo equipo vigente: habilitar otro sin `reemplazar: true` responde
`409 CAJA_OCUPADA` con `equipo_actual`. Sin `nombre`, el equipo se rotula con el
navegador y el sistema operativo que lo habilitaron.

## Equipo de caja — `/api/pos/*` (`X-POS-EQUIPO`)

**`GET /api/pos/estado`** — lo primero que pide pos.\* al cargar. Con un
`X-POS-SESION` válido incluye al empleado; si la sesión venció, `empleado: null`
(no es error).

```json
{ "status": true, "data": {
  "empresa": { "nombre": "...", "rnc": "..." },
  "equipo": { "id": 3, "nombre": "PC mostrador", "bloqueado": false, "bloqueo_segundos": 0 },
  "caja": { "id": 1, "nombre": "Caja 1", "activa": true },
  "empleado": null,
  "turno_caja": null
} }
```

`turno_caja` es el turno abierto de la caja (`{id, empleado_id, empleado_nombre,
abierto_at, fondo_inicial, de_dia_anterior}`) o `null`. Sirve para las reglas K4/K5
(turno de otro cajero o de un día anterior) cuando existan la apertura y el cierre.

**`POST /api/pos/sesion`** `{ "pin": "0123" }` → `{token, empleado, caja, turno_caja}`.

- Entrar con otro PIN en el mismo equipo cierra la sesión anterior (cambio de cajero).
- PIN de **4 dígitos**, generado por el sistema (nunca 0000, 1234, 4321 ni similares).
- 5 PIN incorrectos seguidos en un equipo → `423 EQUIPO_BLOQUEADO` con `bloqueo_segundos`.
  El bloqueo es **progresivo**: 5 minutos, y cada bloqueo seguido el doble (10, 20, 40…)
  hasta un día; ni el PIN correcto entra mientras dure. Entrar con un PIN válido
  devuelve la cuenta a cero. Un PIN mal formado no cuenta como intento.
- Una sesión sin uso por 16 horas vence.

**`DELETE /api/pos/sesion`** (con `X-POS-SESION`) — cierra la sesión: bloqueo de
pantalla o salida. Volver a entrar es otro `POST /api/pos/sesion`.

**`GET /api/pos/catalogo`** (con `X-POS-SESION`) — el catálogo completo de la caja
(C1–C4). El POS lo guarda en memoria y busca ahí; lo vuelve a pedir cada 5 minutos.

```json
{
  "productos": [
    { "id": 12, "nombre": "Agua 500 ml", "sku": "AG-500", "category_id": 3,
      "precio_centavos": 2500, "tasa": 18, "indicador_facturacion": 1,
      "stock": 10, "stock_minimo": 3, "unidad_medida": "43", "decimales": false }
  ],
  "categorias": [ { "id": 3, "nombre": "Bebidas", "productos": 1 } ],
  "generado_at": "2026-10-08T21:30:00-04:00"
}
```

- Solo productos con `activo = 1` e `indicador_facturacion` distinto de 0 (no facturable).
- `precio_centavos`: **precio final con ITBIS**, `round(precio × (1 + tasa), 2)` en
  centavos, calculado en el servidor con aritmética entera (`src/Pos/PosPrecio.php`).
  Siempre la lista 1. El precio sin ITBIS no se manda.
- `tasa`: 18, 16 o 0 (tasa cero y exento).
- `stock: null` = servicio (sin semáforo). `decimales`: si la unidad admite cantidades
  con decimales (`unidades_medida.permite_decimales`).
- `categorias`: solo las activas que tienen algún producto. El producto de una
  categoría inactiva llega con `category_id: null` (sale solo en "Todos").
- Un producto con un precio que no se puede leer se omite (queda en el log) y la caja
  sigue funcionando.

**`POST /api/pos/turno`** (con `X-POS-SESION`) `{ "fondo_centavos": 150000 }` → `201
{turno_caja}`. Abre el turno del empleado en la caja del equipo (K2). Un turno abierto
por caja y uno por empleado (K3, lo garantiza la base): `TURNO_CAJA_OCUPADA` (con
`turno_caja`) o `TURNO_EN_OTRA_CAJA`. El cierre (K6-K8) llega aparte.

**`POST /api/pos/ventas`** (con `X-POS-SESION`) — cobra y emite (§9.5). Por ahora
**E32 a consumidor final, por debajo de RD$250,000** (va por RFCE).

```json
{
  "clave": "8d0c6c1e-3f0a-4b2e-9a51-0e6f3b7c9d12",
  "lineas": [{ "product_id": 12, "cantidad": 3 }, { "product_id": 18, "cantidad": 2.75 }],
  "total_centavos": 17950,
  "forma_pago": 1,
  "recibido_centavos": 20000,
  "ancho": 80,
  "iniciada_ms": 1791505612000
}
```

- **No lleva precios:** el servidor arma cada línea desde `product_id` con el mismo
  cálculo del catálogo (`PosPrecio`). `total_centavos` es el que vio el cajero: si no
  coincide con el del servidor, `409 TOTAL_DISTINTO` y no se emite nada.
- **`clave`** (UUID): una por intento de venta. La misma clave devuelve **la misma
  venta** (`repetida: true`), nunca otro e-NCF; un candado de MySQL cubre el doble toque.
  El POS la conserva mientras no sabe cómo terminó el cobro (red caída) y reintenta con
  ella; después de una respuesta definitiva usa una nueva.
- `forma_pago`: 1 efectivo (`recibido_centavos` ≥ total; vacío en el POS = exacto),
  2 transferencia/depósito, 3 tarjeta. Va en `TablaFormasPago` del RFCE **y** del e-CF.
- Se emite con `IndicadorMontoGravado = 1`. La venta queda a nombre del admin que habilitó
  el equipo (`user_id`, que es obligatorio) y del empleado (`pos_empleado_id`), con
  `turno_id`. Factura, movimiento de caja y salida de inventario van juntos.
- **DGII lenta o caída** (sin respuesta en `POS_DGII_TIMEOUT` s, 5 por defecto): `201`
  con `envio_pendiente: true` y `estado_dgii: RFCE_PENDIENTE`. Se imprime igual
  (decisión 17) y se reenvía sola (abajo). **Rechazo:** `422 DGII_RECHAZO`; la factura
  queda como historial, sin dinero ni inventario.

Respuesta `201`:

```json
{
  "venta": { "factura_id": 1402, "e_ncf": "E320000000012", "tipo_ecf": "32",
             "estado_dgii": "RFCE_ACEPTADO", "envio_pendiente": false, "total_centavos": 24500 },
  "cobro": { "forma_pago": 1, "forma_pago_nombre": "Efectivo", "total_centavos": 24500,
             "recibido_centavos": 50000, "devuelta_centavos": 25500 },
  "recibo": { "...": "los mismos datos de GET /api/facturas/{id}/pdf?format=datos" },
  "repetida": false
}
```

**`GET /api/pos/ventas/{id}/recibo?ancho=80`** (con `X-POS-SESION`) — `{recibo}` para
reimprimir. Solo ventas del POS (`404 VENTA_NO_EXISTE` si no).

**`POST /api/pos/pendientes/reenviar`** (solo `X-POS-EQUIPO`) — revisa hasta 5 ventas con
envío pendiente. Primero **consulta** a la DGII por e-NCF + código de seguridad: si ya la
tenía, solo actualiza el estado; si no la encuentra, reenvía el RFCE firmado que se
guardó. Responde `{revisadas, aceptadas, rechazadas: [{factura_id, e_ncf, motivo}],
pendientes}`. El POS lo llama al entrar, cada 2 minutos y después de cada venta (no hay
cron confirmado, Q4).

## Bitácora

Todo va a `audit_logs` con módulo `pos`: `POS_TRASPASO_CREADO`, `POS_CAJA_CREADA` /
`_ACTUALIZADA`, `POS_EMPLEADO_CREADO` / `_ACTUALIZADO`, `POS_PIN_REGENERADO`,
`POS_EQUIPO_HABILITADO` / `_REVOCADO`, `POS_SESION_ABIERTA` / `_CERRADA`,
`POS_PIN_FALLIDO`, `POS_EQUIPO_BLOQUEADO`, `POS_TURNO_ABIERTO`, `POS_VENTA`,
`POS_VENTA_RECHAZADA`, `POS_VENTA_ENVIADA`, `POS_VENTA_FALLIDA`, `POS_VENTA_SIN_GUARDAR`. Los empleados no son usuarios del master:
`user_id` va vacío y el nombre del empleado va en `username` (`POS · Ana`) y en los
valores. **Nunca se registra un PIN ni un token.**

## Pruebas

`tools/test_pos_backend.php`: 100 verificaciones contra un API local con dos tenants de
prueba (traspaso, administración, bloqueo por PIN, sesiones, catálogo y precio final,
aislamiento entre empresas, bitácora sin PIN). Pasa con el gate en `enforce` y en sombra. Ver su cabecera
para armar el entorno (MySQL 8 en Docker); **nunca contra producción**.

`tools/test_pos_venta.php`: 48 verificaciones del cobro (turno, validaciones sin gastar
e-NCF, venta en efectivo, idempotencia y candado, rechazo, DGII lenta y caída con su
reenvío, recibo, bitácora). Emite de verdad, así que corre contra la DGII simulada
`tools/mock_dgii_local.php` con un certificado autofirmado y se niega a correr si
`DGII_ECF_BASE_URL` / `DGII_FC_BASE_URL` no son locales.
