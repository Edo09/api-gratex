# Módulo de Cobros (cuentas por cobrar por cliente)

Lleva lo que cada cliente le debe a la empresa: un **cobro mensual** por cliente (con el monto y el
día de vencimiento propios de cada uno), pagos completos o parciales (efectivo o transferencia) y los
recordatorios que alguien hizo a mano. Los clientes son los de siempre (tabla `clients`): no hay un
módulo de clientes nuevo. Todo en **RD$ (DOP)**.

- Ruta: `/api/cobros` · módulo RBAC: **`cobros`** (operativo; `admin` lo tiene por `*`).
- Código: `src/Controllers/cobroController.php`, `src/Models/cobroModel.php` (SQL y transacciones) y
  `src/Utils/Cobros/CobroReglas.php` (las reglas, puras, sin base de datos).
- Base de datos: migración de tenant **038** (`db/migrations/038_cobros.sql`, reflejada en
  `db/tenant_schema.sql`); permisos de los roles existentes: master **020**
  (`db/master_migrations/020_cobros_permiso.sql`).
- Web (fiscalo-react): `src/features/cobros/` (pantalla), `src/api/cobros.ts` (servicio) y
  `src/api/schemas/cobros.ts` (esquemas Zod).
- **Segundo ámbito, Cobros de tenants**: lo que la *plataforma* le cobra a las empresas que usan Fiscalpoint. Mismas reglas y
  mismo código, ruta `/api/cobros-tenants`, tablas en la base **master** (migración master **021**) y solo para la cuenta de
  soporte. Ver "Cobros de tenants" más abajo.

## Modelo de datos

| Tabla | Qué guarda | Claves e integridad |
|---|---|---|
| `cobro_config` | La configuración mensual de un cliente: `monto_mensual`, `dia_vencimiento` (1–31), `descripcion`, `activo` | PK `client_id` (un registro por cliente). FK a `clients` **ON DELETE CASCADE**. CHECK `monto_mensual > 0` y día entre 1 y 31 |
| `cobros` | Un cobro por cliente y mes: `periodo` (`AAAA-MM`), `descripcion`, `monto`, `monto_pagado`, `fecha_vencimiento`, `cancelado_at`, `generado_de_cobro_id` | **UNIQUE `(client_id, periodo)`** (`uk_cobros_cliente_periodo`). FK a `clients` **RESTRICT**; FK a sí misma (`generado_de_cobro_id`) SET NULL. CHECK `monto > 0` y `0 <= monto_pagado <= monto` |
| `cobro_pagos` | Los pagos de un cobro: `monto`, `fecha_pago`, `metodo` (`efectivo`/`transferencia`), `observaciones`, `idempotency_key`, `anulado_at` | FK a `cobros` RESTRICT. **UNIQUE `idempotency_key`**. CHECK `monto > 0` |
| `cobro_recordatorios` | Los recordatorios hechos a mano: `fecha_hora`, `canal`, `nota`, `user_name` | FK a `cobros` RESTRICT |

Todos los montos son `DECIMAL(12,2)` (hasta 9,999,999,999.99). Nunca float: dentro del código el dinero
se maneja en **centavos enteros** y solo al responder vuelve a número.

**Nada del historial se sobrescribe ni se borra:**

- Cambiar el monto mensual de un cliente **no toca los cobros ya generados**: cada cobro guarda su
  propio `monto`.
- Un cobro **no se borra**: se *cancela* (`cancelado_at`) y se puede reabrir.
- Un pago **no se edita ni se borra**: se *anula* (`anulado_at`, con su motivo) y se queda a la vista,
  tachado. Un cliente con cobros no se puede eliminar (FK RESTRICT); `clientModel::deleteClient`
  lo explica en pantalla.

### Saldo y estado

`cobros.monto_pagado` es una **caché que se recalcula siempre desde los pagos vigentes** (la suma de
los que no están anulados) en la misma transacción que cada pago o anulación y con la fila del cobro
bloqueada (`SELECT … FOR UPDATE`): dos pagos a la vez se esperan uno al otro y ninguno pisa el saldo del
otro. No suma ni resta: recalcula, así que un valor desviado se corrige solo.

El **estado no se guarda**: se deriva en cada lectura de `monto`, `monto_pagado`, `fecha_vencimiento`,
`cancelado_at` y *hoy* (hora de RD, `src/ZonaHoraria.php`), porque un estado guardado caducaría solo (un
cobro "Pendiente" amanece "Vencido" sin que nadie lo toque). Precedencia:

| Estado | Cuándo |
|---|---|
| **Cancelado** | `cancelado_at` tiene fecha (manda sobre todo lo demás) |
| **Pagado** | el saldo (`monto - monto_pagado`) es 0 |
| **Vencido** | queda saldo y la fecha de vencimiento es **anterior** a hoy |
| **Parcial** | queda saldo, ya tiene pagos y todavía no venció |
| **Pendiente** | queda saldo, sin pagos y todavía no venció |

Un cobro que vence **hoy** todavía no está vencido. Un cobro vencido **con abonos sigue "Vencido"** (es lo
urgente), pero la respuesta trae `con_abonos: true` (tiene pagos vigentes y todavía debe algo) y la pantalla le
pone además una pastilla "Parcial". Por eso el filtro **`estado=Parcial` lista todo lo que tiene abonos y todavía
debe, venza o no**: un vencido con abonos sale en `Vencido` y también en `Parcial`. Los demás valores del filtro
usan exactamente las reglas de la tabla en SQL, y `tools/test_cobros.php` comprueba que SQL y PHP coinciden para
cada uno.

## Reglas y valores por defecto

Decisiones de producto tomadas con un valor por defecto (cámbialas aquí y en `CobroReglas` si el negocio
pide otra cosa):

1. **Día que el mes no tiene** (31 en abril, 30 en febrero): el cobro vence el **último día de ese mes**.
   El mes siguiente vuelve al día configurado, porque el vencimiento siempre se calcula desde la
   configuración y no desde el vencimiento anterior (día 31: ene 31, feb 28, mar 31, abr 30…).
2. **Recurrencia mensual.** Cuando un cobro **queda pagado completo** (por un pago o porque una
   edición del monto lo iguala a lo ya pagado) se genera el del mes siguiente, dentro de la misma
   transacción:
   - periodo = el del cobro pagado **+ 1 mes** (no depende de cuándo se pagó);
   - monto = el `monto_mensual` **actual** del cliente (en Cobros de tenants, ese monto es **por empresa que paga**: el cobro lleva el
     total, monto × `empresas_que_pagan`; ver "Monto por empresa que paga"); vencimiento = su `dia_vencimiento` actual;
     descripción = la de la configuración;
   - solo si el cliente tiene configuración **activa** (`activo = 1`);
   - **un cobro Parcial o Cancelado no genera nada**;
   - si ese (cliente, periodo) ya existe (creado a mano, o generado por una petición anterior) no se
     hace nada: la UNIQUE lo impide, así que repetir la petición **no duplica** (idempotente);
   - el cobro nuevo guarda `generado_de_cobro_id` (se puede seguir la cadena).
   - Editar solo la descripción de un cobro que ya estaba pagado **no** genera nada: solo cuenta la
     transición a pagado.
3. **Un cobro cancelado corta la cadena**: no se genera el siguiente (decisión del pedido). Se crea a
   mano, o se reabre el cancelado.
4. **Sobrepago**: se rechaza (`EXCEDE_SALDO`, 422). No hay pagos por adelantado: se crea el cobro del
   mes siguiente y se paga.
5. **Fecha del pago**: no puede ser posterior a hoy. Puede ser anterior (para registrar un pago tardío).
6. **Cancelar** exige que no haya pagos vigentes (`TIENE_PAGOS`, 409): si la plata ya entró, primero
   se anula el pago. Cancelar o reabrir dos veces es lo mismo que una.
7. **Anular un pago** devuelve el cobro a su saldo real; el cobro del mes siguiente que ya se haya
   generado **se queda** (ya existe como cobro). Volver a pagar no lo genera otra vez.
8. **Editar un cobro**: monto, vencimiento y descripción; el **periodo no cambia**. El monto no puede
   quedar por debajo de lo ya pagado (`MONTO_MENOR_PAGADO`). Un cobro cancelado no se edita.
9. **Un cobro por cliente y mes** (UNIQUE). Crear a mano uno que ya existe da `COBRO_DUPLICADO` (409) con
   el id del existente; si el existente está cancelado, el texto manda a reabrirlo.
10. **Periodo sugerido** al crear a mano (sin periodo ni fecha): el mes que sigue al último cobro del
    cliente, y nunca uno anterior al mes de hoy. Con configuración, el monto, el vencimiento y la
    descripción salen de ella; lo que se escriba manda sobre la configuración.
11. **Enlace con facturas: fuera de esta primera versión.** Un cobro es una cuenta por cobrar propia (la
    mensualidad), no está atado a un e-CF. Si más adelante hace falta, se agrega `cobros.factura_id`
    (NULL, FK a `facturas` SET NULL) sin tocar lo existente.
12. **Recordatorios**: informativos. La app **no envía** WhatsApp ni correos; el canal
    (`llamada`, `mensaje`, `correo`, `visita`, `otro`) solo dice cómo lo hizo la persona. Un cobro pagado o
    cancelado no admite recordatorio.
    - La unidad es el **cliente**: una llamada cubre todo lo que debe, aunque el recordatorio se anote en un
      solo cobro (la pantalla lo anota en su cobro más urgente). El "último recordatorio" de un cliente es el más
      nuevo de **todos sus cobros**, también de los ya pagados o cancelados: pagar el cobro donde se anotó no deja
      al cliente "sin recordar".
    - "Por recordar" = el cliente tiene algo vencido o que vence en los próximos `dias` (7 por defecto) **y**
      (nunca se le recordó, o su último recordatorio tiene `cada` días o más — 7 por defecto —, o le venció un
      cobro *después* de ese último recordatorio: el aviso de "vence pronto" no cubre un "ya venció").
    - Tope por cobro: 200 recordatorios (`LIMITE_RECORDATORIOS`, 409).

### Idempotencia y repeticiones

- **Pagos**: el formulario manda un `idempotency_key` (UUID). Con la misma clave **y los mismos datos** (monto,
  fecha, método y observaciones) la API devuelve el pago ya registrado (`repetido: true`) en vez de registrar
  otro, y con el mismo siguiente cobro. Una repetición de verdad no cambia de datos, y contestarle "ya estaba
  registrado" a otra operación engañaría a quien la mandó, así que eso da **409**: `CLAVE_REPETIDA` (la clave es
  de otro cobro), `CLAVE_CON_OTROS_DATOS` (llegó con otro monto, fecha, método u observaciones) o `PAGO_ANULADO`
  (el pago de esa clave ya se anuló). La web cambia de clave después de cualquiera de los tres.
- **Tope de pagos**: 500 por cobro, contando los anulados (el historial no se borra): el 501 da `LIMITE_PAGOS` (409).
- **`siguiente_motivo`**: cuando un pago (o una edición del monto) deja el cobro pagado completo, la respuesta dice
  qué pasó con el cobro del mes siguiente: `generado`, `sin_config` (el cliente no tiene cobro mensual), `inactivo`
  (lo tiene apagado), `ya_existia` (ese periodo ya tenía cobro) o `monto_excedido` (solo Cobros de tenants: con las empresas que pagan hoy
  el total del mes siguiente pasaría del máximo de un cobro, así que no se genera; el pago sí se guardó). `null` si no lo completó, o si
  es una repetición.
- **Cancelar, reabrir y anular** repetidos devuelven el estado actual (`repetido: true`), sin error.
- **Siguiente cobro**: ver el punto 2 (la UNIQUE).

## API

Todas las rutas exigen token (`Authorization: Bearer <token>`) y el módulo `cobros`. Las respuestas van en el
sobre `{ "status": true, "data": … }`; un error, `{ "status": false, "error": "texto para la persona", "codigo"?: …, "data"?: … }`.
Los montos son números con 2 decimales. Las fechas, `AAAA-MM-DD`; las fechas y horas, `AAAA-MM-DD HH:MM:SS` (hora de RD).

| Método | Ruta | Qué hace |
|---|---|---|
| GET | `/api/cobros` | Lista paginada. Filtros: `query` (nombre del cliente o RNC), `client_id`, `estado`, `periodo` (`AAAA-MM`), `vence_desde`, `vence_hasta`, `orden` (`urgencia` por defecto, `vencimiento_asc`, `vencimiento_desc`, `saldo_desc`), `page`, `pageSize` (tope 100). Un filtro con un valor que no existe da 422 (no se ignora en silencio) |
| POST | `/api/cobros` | Crea un cobro a mano: `client_id` y, opcionales, `periodo`, `fecha_vencimiento`, `monto`, `descripcion` (lo que falta sale de la configuración del cliente) |
| GET | `/api/cobros/resumen?dias=7` | El dashboard: totales, antigüedad, próximos vencimientos y clientes con atraso |
| GET | `/api/cobros/recordatorios?dias=7&cada=7` | A qué **clientes** recordarles un pago (informativo), con sus cobros vencidos o por vencer: ver "Recordatorios" |
| GET | `/api/cobros/clientes/{id}` | Panel del cliente: datos, configuración, resumen, **todos** sus cobros con sus pagos, sus recordatorios y lo sugerido para su próximo cobro |
| PUT | `/api/cobros/clientes/{id}/config` | Configuración mensual: `monto_mensual`, `dia_vencimiento`, `descripcion?`, `activo?` (crea o reemplaza). **No toca ningún cobro existente**. En Cobros de tenants el monto es por empresa que paga y da 422 si el total con las que pagan hoy pasa del máximo |
| GET | `/api/cobros/{id}` | Un cobro con sus pagos (`pagos`, vigentes y anulados), sus recordatorios (`recordatorios_lista`) y los cobros que generó (`genero`) |
| PUT | `/api/cobros/{id}` | Cambia `monto`, `fecha_vencimiento` o `descripcion` → `data: { cobro, siguiente, siguiente_motivo }` |
| POST | `/api/cobros/{id}/pagos` | Registra un pago: `monto`, `metodo`, `fecha_pago?`, `observaciones?`, `idempotency_key?` → `data: { pago, cobro, siguiente, siguiente_nuevo, siguiente_motivo, repetido }` |
| POST | `/api/cobros/{id}/cancelar` | Cancela (`motivo?`) |
| POST | `/api/cobros/{id}/reabrir` | Reabre uno cancelado |
| POST | `/api/cobros/{id}/recordatorios` | Anota un recordatorio hecho a mano: `canal`, `nota?`, `fecha_hora?` |
| POST | `/api/cobros/pagos/{pagoId}/anular` | Anula un pago (`motivo?`) → `data: { pago, cobro }` |

Un método que la ruta no admite da 405; una ruta que no existe, 404.

### Un cobro en la lista

```json
{
  "id": 2, "client_id": 1,
  "cliente": { "id": 1, "nombre": "ACME SRL", "contacto": "Ana Gómez", "empresa": "ACME", "rnc": "131256432", "email": "a@x.do", "telefono": "809-555-0001" },
  "periodo": "2026-11", "descripcion": "Mensualidad",
  "monto": 6000.0, "monto_pagado": 0.0, "saldo": 6000.0,
  "fecha_vencimiento": "2026-11-30", "estado": "Pendiente",
  "dias_atraso": 0, "dias_para_vencer": 14, "con_abonos": false,
  "cancelado_at": null, "motivo_cancelacion": null, "generado_de_cobro_id": 1,
  "ultimo_pago": null, "ultimo_recordatorio": null, "recordatorios": 0,
  "created_at": "2026-11-16 10:00:00", "updated_at": null
}
```

`saldo` es 0 en un cobro cancelado. `dias_atraso` solo es mayor que 0 en un cobro Vencido; `dias_para_vencer`
solo viene en uno abierto que aún no vence (0 = vence hoy).

### Códigos de error (`codigo`)

| Código | HTTP | Cuándo |
|---|---|---|
| `COBRO_DUPLICADO` | 409 | Ya hay un cobro de ese cliente en ese periodo (`data`: `cobro_id`, `estado`, `periodo`) |
| `MIEMBRO_DE_GRUPO` | 409 | Solo en Cobros de tenants: la empresa es una hermana de un grupo y el cobro es el de su cabeza (`data`: `cabeza_id`, `cabeza_nombre`: la que se muestra) |
| `NO_ES_DE_UN_GRUPO` | 409 | Solo en Cobros de tenants: se marcó "no paga" a una empresa que no está en un grupo de dos o más (se usa "No es cliente") |
| `ULTIMA_QUE_PAGA` | 409 | Solo en Cobros de tenants: se marcó "no paga" a la última empresa del grupo que paga (se usa "No es cliente" para sacar el grupo) |
| `COBRO_CANCELADO` | 409 | El cobro está cancelado: no admite pagos, cambios ni recordatorios |
| `YA_PAGADO` | 409 | El cobro ya está pagado completo |
| `TIENE_PAGOS` | 409 | No se cancela un cobro con pagos vigentes |
| `CLAVE_REPETIDA` | 409 | La clave de idempotencia ya es de otro cobro |
| `CLAVE_CON_OTROS_DATOS` | 409 | La clave ya se usó con otro monto, fecha, método u observaciones: no es una repetición |
| `PAGO_ANULADO` | 409 | El pago de esa clave ya se anuló: registra uno nuevo |
| `LIMITE_PAGOS` | 409 | El cobro ya tiene 500 pagos (los anulados cuentan) |
| `LIMITE_RECORDATORIOS` | 409 | El cobro ya tiene 200 recordatorios |
| `EXCEDE_SALDO` | 422 | El pago es mayor que el saldo (`data.saldo`) |
| `MONTO_MENOR_PAGADO` | 422 | El monto nuevo es menor que lo ya pagado |

El resto de los 422 llevan solo el texto (campo faltante, fecha imposible, monto fuera de rango…). Un
fallo de la base de datos es un 500 con un texto neutro (el detalle va al `error_log`).

## Dashboard (`GET /api/cobros/resumen`)

| Cifra | Definición |
|---|---|
| `pendiente` | saldo de todo lo abierto (no cancelado y con saldo) |
| `vencido` | lo abierto cuyo vencimiento es anterior a hoy |
| `por_vencer` | lo abierto que vence entre hoy y dentro de `dias` días |
| `cobrado_mes` | pagos **vigentes** con `fecha_pago` dentro del mes de hoy (cuenten para el cobro que cuenten) |
| `por_cobrar_mes` | saldo de lo abierto que vence dentro del mes de hoy |
| `facturado_mes` | monto de los cobros no cancelados que vencen en el mes (pagados o no) |
| `antiguedad` | lo abierto por tramos: `vigente`, `1-30`, `31-60`, `61-90`, `90+` días de atraso (monto y cobros) |
| `proximos` | hasta 8 cobros abiertos que vencen en la ventana, el más cercano primero |
| `atrasados` | hasta 8 clientes con cobros vencidos, el que más debe primero (cobros, saldo vencido, el más viejo y sus días) |

La respuesta trae también `hoy` (fecha de RD del servidor) y `ahora` (fecha y hora de RD): la web las usa para
la fecha de un pago o de un recordatorio, porque el navegador puede estar en otra zona horaria (soporte entra a
las empresas desde donde esté).

## Recordatorios (`GET /api/cobros/recordatorios`)

Informativo: la app no envía nada. Mira los cobros abiertos que ya vencieron o vencen en los próximos `dias`
(7 por defecto, máximo 90), los más atrasados primero y **hasta 500**: si hay más, `truncado` es `true` y los totales
son solo de los que se miran.

| Clave | Qué trae |
|---|---|
| `hoy`, `ahora`, `dias`, `cada`, `truncado` | Fecha y hora de RD del servidor, la ventana, cada cuántos días se vuelve a recordar y si la lista se cortó |
| `resumen` | Por cliente: `clientes`, `clientes_por_recordar`, `clientes_recordados`. Por cobro: `por_recordar`, `recordados`, `vencidos`, `por_vencer` |
| `clientes` | Una fila por cliente: `cobros_vencidos`, `cobros_por_vencer`, `saldo_vencido`, `saldo_por_vencer`, `mas_antiguo`, `proximo_vencimiento`, `dias_atraso`, `recordatorios` (los del cliente en todos sus cobros), `ultimo` (el más nuevo), `requiere_recordatorio` y `cobro_id`/`periodo` del cobro más urgente (donde se anota). Primero los que requieren recordatorio y, dentro de cada grupo, el más atrasado |
| `items` | Los cobros de esos clientes. Cada uno trae sus propios `recordatorios` y `ultimo_recordatorio`, el `motivo` (`vencido` o `por_vencer`), `ultimo` (el del **cliente**) y `requiere_recordatorio`, calculado con el último recordatorio del cliente: un cliente "recordado" no tiene cobros que digan "por recordar" |

## Bitácora

Cada acción deja su fila en `audit_logs` (módulo `cobros`): `CREATE` (cobro creado o generado solo, con la
descripción de cuál lo generó), `UPDATE`, `PAYMENT`, `VOID_PAYMENT`, `CANCEL`, `REOPEN`, `REMINDER` y `CONFIG`
(con el valor anterior y el nuevo de la configuración). Una repetición (misma clave, cancelar de nuevo…) no
repite la fila. En Cobros de tenants el módulo es `cobros-tenants` y el `tenant_id` es 0 (ver más abajo).

## Permisos

El módulo `cobros` es operativo: está en `$USER_MODULES` de `config/permissions.php` (el rol `user` de las
empresas **nuevas** lo recibe al crearse) y la ruta está mapeada (`'cobros' => 'cobros'`: sin el mapeo el gate no
resolvería el tenant y el 500 saldría vacío).

**El controlador exige el módulo siempre**, aunque `PERMISSIONS_ENFORCE` esté en modo sombra (como
`posAdminController`): son las cuentas por cobrar de la empresa, el panel de un cliente trae sus datos de contacto
(correo, teléfono) y, en sombra, el gate solo avisa. Un principal máquina (sin rol) o un rol sin el módulo reciben
403 y queda una fila de acceso denegado en la bitácora. Por eso la **master 020** no es opcional para los roles
`user` de las empresas **ya creadas** (idempotente; solo datos): sin ella esos usuarios reciben 403 en
`/api/cobros` y la web les oculta Cobros, hasta que un admin les dé el módulo en *Usuarios y roles*. `admin` lo
tiene por `*`; los roles personalizados no se tocan.

## Cobros de tenants (la plataforma a sus empresas)

Además de que cada empresa le cobre a **sus** clientes, quien opera la plataforma le cobra a **sus tenants**: las
empresas que usan Fiscalpoint. Ninguna de ellas es "dueña" de ese cobro (Gratex es solo el tenant #1), así que no
puede vivir en la base de ninguna. Es el **mismo módulo** (mismas reglas, mismo modelo, mismo controlador): cambia el
*ámbito*, que decide quién es "el cliente" y dónde están las tablas.

| | Cobros de una empresa | Cobros de tenants |
|---|---|---|
| Ruta | `/api/cobros` | `/api/cobros-tenants` (las mismas rutas, más `GET /clientes?query=&limite=`: el directorio de tenants) |
| Quién entra | un usuario con el módulo `cobros` de su rol | solo un usuario de **soporte** (`users.acceso_global = 1`, ver [acceso-global.md](acceso-global.md)); el módulo `cobros` no basta, ni ser `admin` |
| El cliente es | una fila de `clients` de la empresa | un tenant (`tenants`): su nombre, su RNC y el correo de su primer usuario `admin` |
| Las tablas viven en | la base de la empresa (migración de tenant 038) | la base **master** (migración master **021**: las mismas cuatro tablas, con FK a `tenants`) |
| Bitácora | módulo `cobros`, con el `tenant_id` de la empresa | módulo `cobros-tenants`, con `tenant_id` **0** |
| Web | pantalla *Cobros* | pantalla *Cobros de tenants* (solo se muestra con acceso global) |

Decisiones:

- **Una sola implementación.** `cobroModel` recibe el ámbito (`AMBITO_EMPRESA` por defecto o `AMBITO_TENANTS`) y lo único
  que cambia es de dónde sale "el cliente": la tabla `clients` o una subconsulta sobre `tenants`. El Router define
  `COBROS_AMBITO = 'tenants'` en el case `cobros-tenants` y `cobroController.php` lo lee; nada más lo define.
- **Mismos nombres de tabla, otra base.** `cobro_config`, `cobros`, `cobro_pagos` y `cobro_recordatorios` existen en la master
  igual que en cada empresa; ninguna base tiene filas del otro ámbito.
- **El acceso se exige en el servidor, siempre.** `config/permissions.php` deja pasar la ruta con cualquier token de usuario
  (`'cobros-tenants' => 'authenticated'`) y el controlador llama a `AccesoGlobal::tieneAcceso` con la master **antes** de resolver
  la ruta y de abrir el modelo. Falla cerrado: un principal máquina (sin usuario), un usuario sin acceso global o una columna
  que no se puede leer reciben 403 con el texto de soporte, y queda una fila de acceso denegado. No depende de `PERMISSIONS_ENFORCE`.
- **La bitácora no se mezcla.** Los eventos de la plataforma van con `tenant_id = 0` y módulo `cobros-tenants`: la bitácora de cada
  empresa lista solo su `tenant_id`, así que ninguna ve lo que la plataforma le cobra a otra (la sesión de soporte puede estar dentro
  de *cualquier* empresa cuando cobra). `AuditLogger` respeta un `tenant_id` explícito, y 0 no cae en el de la sesión.
- **Un tenant que se cobra se cobra aunque esté inactivo**: la deuda no se va con la baja.
- **El directorio de tenants** (`GET /api/cobros-tenants/clientes?query=&limite=`, hasta 500 por respuesta) trae las **unidades que se
  cobran** (ver "Quién se cobra y los grupos"), los activos primero y por nombre. Cada una trae lo de un cliente (nombre, RNC, correo de su primer admin), qué es (`tipo`, `ambiente`, `activo`),
  su cobro mensual (`config`, `null` si no tiene; `con_config` lo resume) y lo que debe: `saldo_pendiente`, `saldo_vencido`,
  `cobros_abiertos`, `cobros_vencidos`, `dias_atraso` (del más viejo) y `proximo_vencimiento` (hoy cuenta como "aún no vence"). Un cobro
  pagado o cancelado no suma, igual que en el dashboard: la suma del directorio cuadra con él (lo prueba `tools/test_cobros_tenants.php`).
  Lo usan el buscador de los formularios y la pestaña *Clientes* de la pantalla, que es lo que se ve al entrar: sin ella, quien cobra no
  veía a sus tenants hasta que tenían un cobro. Las que **no se cobran** no salen, salvo con `?excluidos=1` (la pestaña lo pide para poder
  mostrarlas y devolverlas): ahora significa "también las unidades que no se cobran". Cada item dice `facturable`, `motivo_oculto`, `grupo`,
  `excluido` (solo la marca) y, si está marcada, su `motivo_exclusion`.
- **Quién se cobra y los grupos** (diseño: `docs/superpowers/specs/2026-11-19-cobros-tenants-facturables-design.md` del repo `fiscalo`;
  sin migración). La plataforma cobra por **unidad**: un tenant solo, o un **grupo** (`tenants.grupo_id`, migración master 008:
  empresas de un mismo cliente, que comparten un solo cobro mensual). La unidad de un grupo es su **cabeza**: el tenant cuyo `id` es el
  `grupo_id` (`--grupo=self`); si esa fila ya no existe, la de menor id del grupo. El directorio trae **una fila por unidad**, con el
  cobro mensual y los saldos de la cabeza (y el nombre de la empresa que se muestra: ver "Quién paga dentro de un grupo"); las hermanas
  salen en `grupo.miembros` (id, nombre, rnc, tipo, ambiente, `paga`, `motivo_sin_pago`) y no son filas propias. Buscar por el nombre o el RNC de una hermana trae la fila de su grupo, y en la lista de cobros (`GET /api/cobros-tenants?query=`),
  los cobros del grupo (que son los de su cabeza). `grupo` es `null` sin grupo (en un grupo de una sola
  empresa, `miembros` va vacío).

  | La unidad… | ¿Se cobra? |
  |---|---|
  | está marcada "no es un cliente" (`cobro_excluidos`) | No: `motivo_oculto = "excluido"` (la marca gana) |
  | es de base propia (`app`), o alguna empresa de su grupo lo es | Sí |
  | es de `integracion` en `ecf` (producción), o alguna empresa de su grupo lo está | Sí |
  | es de integración en certificación (`certecf`/`testecf`) **con su cobro mensual activo** | Sí: el cobro mensual activo es la decisión de cobrarle (así se trata a una empresa que sigue certificando pero ya paga su mensualidad) |
  | es de integración en certificación, sin cobro mensual activo, pero **con cobros abiertos** (le deben plata) | Sí, mientras los deba: quien debe no desaparece de la lista (igual que la marca se niega con `TIENE_COBROS_ABIERTOS`); al cobrarse o cancelarse lo último, vuelve a ocultarse |
  | es de integración en certificación, sin cobro mensual activo y sin cobros abiertos | No: `motivo_oculto = "solo_certificacion"`; entra sola al pasar a `ecf`, o al activarle su cobro mensual |

  Solo cuenta el cobro mensual **de la cabeza**: el que sobre en una hermana ni la hace cliente ni se muestra. Gratex y Demo Fiscalo no
  pagan: se marcan "no es un cliente". Una **hermana no tiene cobro propio**: configurar su cobro mensual, crearle un cobro o marcarla
  "no es un cliente" da 409 `MIEMBRO_DE_GRUPO` (con la cabeza en `data`: `cabeza_id`, `cabeza_nombre`); todo va en la cabeza, así un
  grupo no se cobra dos veces. Marcar a la cabeza oculta el grupo entero.
- **Quién paga dentro de un grupo** (tabla `cobro_sin_pago`, master **023**; diseño:
  `docs/superpowers/specs/2026-11-19-cobros-tenants-quien-paga-design.md` del repo `fiscalo`). Un grupo comparte UNA mensualidad, pero no
  todas sus empresas la pagan: algunas solo certificaron (y después pasaron a producción, así que ni `tipo` ni `ambiente` dicen quién
  paga). `PUT /api/cobros-tenants/clientes/{id}/paga` con `{ "paga": false, "motivo"?: "…" }` marca que una empresa **no paga**, y
  `{ "paga": true }` la devuelve; responde `{ client_id, paga, motivo, repetido, anterior: { paga, motivo } }`. **Sin fila = paga**: nada
  se rellena y todo sigue igual hasta que alguien marca. Reglas: solo una empresa de un grupo de **dos o más** (si no, 409
  `NO_ES_DE_UN_GRUPO`: se usa "No es cliente"); no la última que queda pagando (409 `ULTIMA_QUE_PAGA`: se usa "No es cliente" para sacar
  el grupo); repetir una marca no cambia nada (`repetido: true`, sin bitácora, y queda el motivo de la primera); decir que paga se
  puede siempre (también a una empresa que ya no está en un grupo, para limpiar una marca vieja). Cada cambio deja `NO_PAGA` o `SI_PAGA`
  en la bitácora (módulo `cobros-tenants`, `tenant_id` 0). No es lo mismo que "No es cliente": aquel saca una unidad entera de la lista;
  este solo dice quién paga y se puede poner en la cabeza sin ocultar el grupo.
  - **La empresa que se muestra** de una unidad es la cabeza si paga y, si no, la que paga de menor `id`: su nombre, RNC, `tipo`,
    `ambiente`, `activo`, `edicion` y correo (el del admin de esa empresa, o el de la cabeza, o el de cualquiera del grupo; este respaldo del correo también vale en un grupo
    sin marcas, donde antes salía vacío) salen en el
    directorio, la lista de cobros, el panel, el dashboard, los recordatorios y el aviso de `MIEMBRO_DE_GRUPO`, definidos una sola vez en
    `ORIGEN_TENANTS`. **El cobro y el cobro mensual siguen en la cabeza** (no se mueve nada) y `data.cabeza_id` del 409 es su id.
  - **Marcas que no aplican se ignoran:** una marca solo cuenta mientras la empresa esté en un grupo de dos o más y quede alguna sin
    marcar; si no (una empresa que salió de su grupo, la última que pagaba se borró), todas cuentan como que pagan y se muestra la cabeza,
    así no hay un nombre en blanco ni un grupo que deje de cobrarse por accidente.
  - **Se cobra** si alguna empresa **que paga** es de base propia o de integración en producción (además del cobro mensual activo y los
    cobros abiertos de siempre). El item del directorio trae en `grupo`: `cabeza_id`, `cabeza_nombre`, `cabeza_rnc`, `cabeza_paga`,
    `cabeza_motivo_sin_pago` y `miembros` (cada uno con `paga` y `motivo_sin_pago`). La búsqueda usa el nombre y el RNC **propios** de
    cada empresa (la cabeza que no paga sigue encontrando a su grupo).
- **Monto por empresa que paga** (diseño: `docs/superpowers/specs/2026-11-19-cobros-tenants-monto-por-empresa-design.md` del repo `fiscalo`;
  sin migración). En Cobros de tenants, `cobro_config.monto_mensual` es el monto **por empresa que paga**, y el total de una unidad es ese
  monto por `empresas_que_pagan`: 1 para una empresa sola, y en un grupo las empresas que no están marcadas "no paga" (una marca que no
  aplica cuenta a todas como que pagan; nunca menos de 1). Un grupo con 500 por empresa y 3 que pagan es 1,500 al mes. Sigue siendo **un
  cobro por grupo al mes, en la cabeza, por el total**; no hay cobros ni pagos por empresa. La multiplicación se aplica al **crear** un
  cobro desde la configuración (el del mes siguiente tras un pago, el monto por defecto de "Nuevo cobro" y `sugerido.monto`); un cobro
  conserva el monto con el que se creó, así que marcar quién paga solo cambia los cobros que se creen después. `config.monto_mensual` en
  las respuestas sigue siendo el monto por empresa; el item del directorio y el panel traen `empresas_que_pagan`. Una empresa que entra
  a un grupo cuenta como que paga hasta que se la marca "no paga". Guardar un monto cuyo total con las que pagan hoy pasa del máximo
  (RD$ 9,999,999,999.99) es 422; si después pagan más empresas y el total del mes siguiente ya no cabe, ese cobro no se genera
  (`siguiente_motivo = monto_excedido`) y el pago se guarda igual. En el ámbito de una empresa no hay grupos: ningún monto cambia (la
  respuesta del panel trae `empresas_que_pagan: 1`).
- **Quién no es cliente** (tabla `cobro_excluidos`, master **022**). No todos los tenants son clientes: varios se crearon solo para
  certificar a una empresa (tipo `integracion`, en certificación) o son de prueba. **Borrarlos no se puede ni se debe**: cada uno guarda
  sus e-CF (`ecf_integracion_backup`: 50 a 275 por tenant el 2026-11-18, y no tienen base propia, así que es la única copia), su bitácora y
  su historial, y las FK lo impiden mientras existan. En vez de eso, `PUT /api/cobros-tenants/clientes/{id}/excluido` con
  `{ "excluido": true, "motivo"?: "…" }` los **marca**, y `{ "excluido": false }` los devuelve: la tabla guarda una fila por tenant
  (`client_id` PK, FK a `tenants` ON DELETE CASCADE, `motivo`, `user_id`, `created_at`) y quitar la fila revierte todo. Reglas: no se quita
  de la lista a quien tiene plata de por medio, con **cobros abiertos** (409 `TIENE_COBROS_ABIERTOS`, con `cobros_abiertos` en `data`) o
  con su **cobro mensual activo** (409 `CONFIG_ACTIVA`: se desactiva antes); repetir lo mismo no cambia nada (`repetido: true`, sin
  fila de bitácora). Cada cambio deja `EXCLUDE` o `INCLUDE` en la bitácora (módulo `cobros-tenants`, `tenant_id` 0). La marca no toca
  los cobros, los pagos ni el dashboard de ese tenant (si los tiene, siguen ahí), solo la lista de clientes y el buscador.
- **FK a `tenants`.** Un tenant con cobros no se puede borrar (RESTRICT); su configuración mensual sí se va con él (CASCADE), como
  la de un cliente en las empresas.

## Despliegue

El orden importa (regla del proyecto: **las migraciones de tenant van primero**):

1. `tools/verificar_migraciones_tenant.sql` en cada base de tenant app (fila `038`).
2. `db/migrations/038_cobros.sql` en la base de **cada** empresa (Gratex y las demás, use o no Cobros: así
   quedan todas iguales). Es idempotente; se puede repetir.
3. `db/master_migrations/020_cobros_permiso.sql` en la base master (una vez).
4. Subir el código de la API y, después, la web.

**Cobros de tenants** (la pantalla de soporte no funciona sin esto; las empresas siguen igual sin ello):

5. `tools/verificar_migraciones_master.sql` en la base master (filas `021`, `022` y `023`).
6. `db/master_migrations/021_cobros_tenants.sql` en la base **master**, una vez (idempotente). Va **antes** de subir el código:
   sin sus cuatro tablas, `/api/cobros-tenants` responde 500.
7. Subir el código de la API (`cobroController.php`, `cobroModel.php`, `CobroReglas.php`, `Router.php`, `config/permissions.php`; y
   `db/tenant_schema.sql`, que lee `create_tenant.php`) y, después, la web.
8. `db/master_migrations/022_cobro_excluidos.sql` en la base **master**, una vez (idempotente), **antes** de subir el código que trae
   "no es un cliente": el directorio consulta `cobro_excluidos` y sin la tabla responde 500. Solo cambian `cobroModel.php`,
   `cobroController.php` y `CobroReglas.php`.
9. `db/master_migrations/023_cobro_sin_pago.sql` en la base **master**, una vez (idempotente), **antes** de subir el código que trae "quién
   paga": **cada** consulta de Cobros de tenants lee `cobro_sin_pago` (la define `ORIGEN_TENANTS`), y sin la tabla todo el módulo
   responde 500. Después, `tools/verificar_migraciones_master.sql` (fila `023`). Solo cambian `cobroModel.php`, `cobroController.php` y
   `CobroReglas.php`; la web va después de la API (sus campos nuevos son obligatorios).

Registro de aplicación en producción: ver el final de este archivo.

## Pruebas

- `php tools/test_cobros.php`: las reglas puras y **el modelo real contra SQLite en memoria**, cuyas tablas se
  traducen del DDL de `db/tenant_schema.sql` (UNIQUE, CHECK, FK y ENUM incluidos). Está en la línea base
  (`tools/express_baseline.php`, `BASELINE_SUITES`).
- `php tools/check_tenant_schema_orden.php`: la migración 038 y el esquema consolidado crean **las mismas**
  tablas, columnas, índices, FK y CHECK; la migración sigue la regla de phpMyAdmin (`@db`, guardia, un solo
  `SELECT` final).
- Para la web: `php tools/test_cobros.php --fixtures=<carpeta>` vuelca las respuestas reales del modelo, que
  `fiscalo-react/scripts/test-cobros.ts` valida contra los esquemas Zod.
- `php tools/test_cobros_tenants.php`: el ámbito de la plataforma: la ruta `/api/cobros-tenants`, el cableado (Router, permiso,
  acceso global antes del modelo, bitácora con `tenant_id` 0) y **el modelo real en ámbito de tenants contra SQLite**, con las
  cuatro tablas traducidas de `db/master_schema.sql` (FK a `tenants` incluida) y los valores del dashboard calculados a mano.
  También está en la línea base. Lo que comparte con `test_cobros.php` (traducir el DDL real y revisar saldos) vive en
  `tools/cobros_test_lib.php`. `--fixtures=<carpeta>` vuelca sus respuestas para `fiscalo-react/scripts/test-cobros-tenants.ts`.
- `php tools/check_tenant_schema_orden.php` compara también la master 021 con `db/master_schema.sql`.

## Límites conocidos

- Un cobro por cliente y mes: no hay cobros "extra" del mismo mes (se crea con otro periodo o se edita el existente).
- Sin pagos por adelantado ni sobrepagos (ver arriba).
- Topes por cobro: 500 pagos y 200 recordatorios (los anulados cuentan). El panel de un cliente trae sus últimos 240
  cobros y la lista de recordatorios mira hasta 500 cobros (`truncado` avisa).
- Un recordatorio se anota en un solo cobro (aunque cuenta para el cliente entero): no se puede anotar a varios a la vez.
- Sin enlace con facturas (ver arriba) ni envío de recordatorios.
- La generación del cobro siguiente ocurre al **pagarse** el anterior, no por calendario: un cliente que no
  paga acumula un solo cobro vencido, no uno por mes.

## Decisiones abiertas del negocio

Valores por defecto con los que salió la primera versión; conviene confirmarlos con quien lleva la cartera:

1. **Meses sin cobrar.** La cadena sigue al **pagarse** un cobro (genera solo el mes siguiente). Si un cliente no
   paga durante tres meses, tiene un solo cobro vencido, no tres. ¿Se quieren generar los meses atrasados (con el
   monto de cada mes) o ponerse al día de golpe?
2. **Condonar o perdonar.** Hoy un cobro se *cancela* (si no tiene pagos) o se baja su monto a lo ya pagado (queda
   Pagado y genera el siguiente). No hay un estado "condonado" ni una nota de crédito.
3. **Parcial estando vencido.** Un cobro vencido con abonos sigue "Vencido" (con una pastilla "Parcial" al lado)
   porque es lo urgente. Si el negocio prefiere "Parcial" como estado principal, se cambia el orden en
   `CobroReglas::estado`.
4. **Pagos por adelantado y sobrepagos.** Se rechazan. Si un cliente paga varios meses juntos, se registra el pago de
   cada cobro (el siguiente se genera al pagar el anterior).
5. **Permiso aparte para anular y cancelar.** Hoy quien tiene el módulo `cobros` puede registrar pagos, anularlos,
   cancelar cobros y cambiar el cobro mensual de un cliente. Si se quiere que anular y cancelar sean solo de un
   encargado, el RBAC admite un submódulo (`cobros.anular`) sin tocar las tablas.

## Registro de aplicación en producción

- **2026-11-17 · migración de tenant 038.** Aplicada con `mysql.exe` a las **cinco** bases de tenant app, primero a Demo
  Fiscalo (`smhynzte_003`) y después a `smhynzte_new_gratexdb`, `smhynzte_002`, `smhynzte_004` y `smhynzte_005`. Las cinco
  devolvieron `todo_ok = SI` (4 tablas InnoDB, 8 índices, 5 FK y 5 CHECK) y `tools/verificar_migraciones_tenant.sql` muestra
  en cada una las migraciones 027, 028, 030 y 038 como `APLICADA`. Las empresas de tipo `integracion` no tienen base de negocio
  (se crearon solo para certificar) y no entran.
- **2026-11-17 · master 020** en `smhynzte_master_gratex`: 6 roles `user` de empresas app, 6 con `cobros`, 0 faltan.
- **2026-11-17 · migración de tenant 038 también en `smhynzte_006`** (tenant 20, DICAMAG, creada el 2026-11-16): la primera
  pasada la dejó fuera (la master 020 contó seis roles `user` y se migraron cinco bases). `todo_ok = SI`. Con ella son **seis**
  las bases con Cobros.
- **2026-11-17 · master 021 (Cobros de tenants)** en `smhynzte_master_gratex`: `todo_ok = SI` (4 tablas InnoDB, 8 índices, 5 FK y 5
  CHECK; `tenants.id` es `int`) y `tools/verificar_migraciones_master.sql` la muestra `APLICADA`. Con solo `SELECT` se comprobó el
  modelo en ámbito de tenants contra el MySQL real (8.0, con `ONLY_FULL_GROUP_BY`): buscador (19 tenants, 6 con correo de admin), lista,
  filtros por estado, dashboard, recordatorios y panel, sin errores; las cuatro tablas quedaron vacías.
- **2026-11-17 · verificación en vivo de Cobros de tenants** (sesión de soporte en `app.fiscalpoint.com.do`, todo 200): menú,
  dashboard, buscador (19 tenants), panel y, con escrituras, **un cobro de prueba**: `cobros.id = 1`, tenant 5 (Demo Fiscalo),
  periodo 2026-11, RD$ 1.00, vencimiento 2026-11-01, creado y **cancelado** ese mismo día (motivo "Prueba de Cobros de tenants").
  Sigue en la tabla (un cobro no se borra) y no cuenta en ningún total. La bitácora quedó con dos filas (`audit_logs` 4483 y 4484:
  módulo `cobros-tenants`, `tenant_id` 0, CREATE y CANCEL). No se probaron en vivo la configuración mensual, los pagos, anular un
  pago ni los recordatorios: usan el mismo modelo y el mismo SQL que el módulo de las empresas, ya verificado en Demo Fiscalo.
- **2026-11-18 · master 022 ("no es un cliente", tabla `cobro_excluidos`)** en `smhynzte_master_gratex`: `todo_ok = SI` (InnoDB, 1 FK a
  `tenants` con `ON DELETE CASCADE`; `tenants.id` es `int`) y `tools/verificar_migraciones_master.sql` la muestra `APLICADA`. Antes de
  aplicarla se comprobó en solo lectura que la tabla no existía, y el SQL del directorio y de quitar/devolver se probó contra el MySQL
  real con una tabla temporal de la sesión (sin crear nada). La tabla quedó vacía: ningún tenant marcado todavía.
- **2026-11-18 · "no es un cliente" en vivo (solo lectura).** El usuario subió `cobroModel.php`, `cobroController.php` y `CobroReglas.php`
  y, con su sesión de soporte en `app.fiscalpoint.com.do`, `GET /api/cobros-tenants/clientes` devolvió 200 con `excluido = false` y
  `motivo_exclusion = null` en los 20 tenants (el `LEFT JOIN` a `cobro_excluidos` funciona contra la tabla real). La web (`fiscalo`
  `f1f537c`, Vercel en verde) pide `?excluidos=1` (200), el menú de cada fila trae **No es cliente (quitar de la lista)** y el modal abre
  y se cancela sin escribir. En esa verificación **no se marcó ningún tenant** (`cobro_excluidos` seguía vacía); la escritura se probó
  después, ver la entrada siguiente.
- **2026-11-18 · "no es un cliente": escritura probada en vivo** (ida y vuelta, con permiso del usuario, desde la pantalla y con su sesión de
  soporte). Se quitó a Demo Fiscalo (tenant 5) con el motivo "Prueba de ida y vuelta (se devuelve enseguida)": `PUT /api/cobros-tenants/
  clientes/5/excluido` → 200 con `repetido = false` y `anterior.excluido = false`; la lista pasó de 20 a 19 y apareció "Mostrar las que no
  son clientes (1)", con la fila atenuada y su motivo. Se devolvió con "Volver a la lista" (200). Resultado: `cobro_excluidos` con 0
  filas y dos filas en la bitácora (`audit_logs` 4690 `EXCLUDE` y 4691 `INCLUDE`: módulo `cobros-tenants`, `tenant_id` 0, `entity_type`
  `tenant`, `entity_id` 5, `user_id` 2, `success` 1). Demo Fiscalo quedó como estaba (su cobro cancelado, sin cobro mensual, activa). Hoy
  ningún tenant está marcado: el usuario elige cuáles, uno por uno.
- El código está en `master` (commits `9732e85`, `3006c1e`, `386871a` y `d5990a7`). El despliegue al servidor **no es automático** (hosting
  compartido): se suben los archivos por cPanel, con las migraciones ya aplicadas.
- **2026-11-19 · Cobros de tenants por unidad (quién se cobra y los grupos) en producción.** API `f8f2f2d` en `master` (CI `express-api` php83 en
  verde); el dueño subió `src/Models/cobroModel.php` por cPanel; web `d648dc6` (Vercel en verde). **Sin migración.** Antes de empezar, con una
  lectura en solo lectura de la master real y el modelo nuevo: se cobran los tenants 1, 2, 5, 6, 9, 17, 18 y 20, los 8 de solo certificación
  (3, 4, 8, 10, 11, 12, 16 y 21) quedan ocultos, el grupo 6 sale como una fila (la cabeza, 6, con 13, 14, 15 y 19 adentro) y el tenant 18 se
  cobra por su cobro mensual activo.
- **2026-11-19 · borrada la configuración de cobro mensual sobrante de dos hermanas del grupo 6.** Los tenants 14 y 15 tenían cada uno un cobro
  mensual (500.00, día 15, activo) creado ese día desde la pantalla, antes de que el sistema cobrara por grupo; ninguno tenía cobros. Con el go del
  dueño se borraron esas dos filas de `cobro_config` en una transacción (2 filas, comprobadas antes y después): `cobro_config` pasó de 7 a 5 filas
  (tenants 2, 9, 17, 18 y 20), y `cobros` y `tenants` quedaron igual; el respaldo, con su `INSERT` para deshacerlo, quedó en el scratchpad de la
  sesión. El grupo 6 quedó **sin cobro mensual** hasta que el dueño lo puso en la fila de la cabeza (tenant 6). Ese mismo día el dueño marcó
  a Gratex (1) y a Demo Fiscalo (5) como "No es cliente" desde la pantalla (filas de bitácora `EXCLUDE`).
- **2026-11-19 · "quién paga" (tabla `cobro_sin_pago`, master 023) en producción.** La 023 se aplicó a la master con el go del dueño con un
  script de una sola corrida (ensayo en solo lectura primero; 15 sentencias, solo crea una tabla): `todo_ok = SI`, una tabla nueva
  (`cobro_sin_pago`, InnoDB, PK `client_id`, FK a `tenants` ON DELETE CASCADE) vacía, `tenants` sin cambios, y el verificador la muestra
  `APLICADA`. API `188bd43` + corrección de CI `52b5d78` en `master`, el dueño subió `cobroModel.php`, `cobroController.php` y
  `CobroReglas.php`, y la web `6f84b0e` (Vercel en verde). El dueño marcó "no paga" a los tenants 6 y 13 desde la pantalla (`NO_PAGA` en la
  bitácora; también marcó y devolvió el 14: `NO_PAGA` y `SI_PAGA`). El grupo 6 se muestra con el nombre del tenant 14 y su cobro sigue en
  la cabeza (6).
- **2026-11-19 · el cobro mensual es por empresa que paga, en producción.** API `22f63eb` en `master` (CI `express-api` php83 en verde); el
  dueño subió `src/Models/cobroModel.php`; web `cede0c3` (Vercel en verde). Sin migración. Lectura en solo lectura de la master real con el
  modelo nuevo: el grupo 6 tiene `empresas_que_pagan = 3` (tenants 14, 15 y 19), monto por empresa 500.00 y total 1,500.00 (también en el
  panel y en `sugerido.monto`, con la consulta nueva corriendo en MySQL real); el grupo 18 y las empresas solas siguen igual
  (`empresas_que_pagan = 1`); la tarjeta "Cobro mensual total" da RD$ 8,600.00 (antes 7,600.00). Todavía no hay cobros creados (el único es el
  de prueba cancelado de Demo Fiscalo): el primer cobro de cada cliente se crea a mano y lleva el total; los siguientes se generan al pagarse.

