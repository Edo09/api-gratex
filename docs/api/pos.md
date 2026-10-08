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

## Bitácora

Todo va a `audit_logs` con módulo `pos`: `POS_TRASPASO_CREADO`, `POS_CAJA_CREADA` /
`_ACTUALIZADA`, `POS_EMPLEADO_CREADO` / `_ACTUALIZADO`, `POS_PIN_REGENERADO`,
`POS_EQUIPO_HABILITADO` / `_REVOCADO`, `POS_SESION_ABIERTA` / `_CERRADA`,
`POS_PIN_FALLIDO`, `POS_EQUIPO_BLOQUEADO`. Los empleados no son usuarios del master:
`user_id` va vacío y el nombre del empleado va en `username` (`POS · Ana`) y en los
valores. **Nunca se registra un PIN ni un token.**

## Pruebas

`tools/test_pos_backend.php`: 100 verificaciones contra un API local con dos tenants de
prueba (traspaso, administración, bloqueo por PIN, sesiones, catálogo y precio final,
aislamiento entre empresas, bitácora sin PIN). Pasa con el gate en `enforce` y en sombra. Ver su cabecera
para armar el entorno (MySQL 8 en Docker); **nunca contra producción**.
