# Módulo: Bitácora de Auditoría (audit_logs)

Registro centralizado de **quién hizo qué y cuándo** en todo el sistema: mutaciones
de datos maestros, ciclo de vida e-CF y eventos de autenticación. Diseñado para
soportar timeline de actividad, página de actividad reciente, historial de logins,
auditorías de seguridad, filtros (usuario/módulo/acción/fecha), exportación y
reconstrucción de valores previos ("¿qué cambió y cuándo?").

## Dónde viven los logs

Tabla **`audit_logs`**, centralizada y aislada por `tenant_id`:

- **Multi-tenant** (`MULTI_TENANT_ENABLED=true`, producción): en el MASTER
  (`gratex_master`), igual que `users`/`roles`. El MASTER es independiente del
  switch de DB por-tenant (`TenantResolver`), así que la escritura siempre llega
  a una conexión viva, sirve a tenants `app` e `integracion`, y los eventos sin
  empresa (ver abajo) caben en la misma tabla.
- **Single-tenant** (fallback): en la DB del tenant, `tenant_id` queda NULL.

DDL: `db/master_migrations/006_add_audit_logs.sql`, espejado en
`db/master_schema.sql` y `db/tenant_schema.sql`.

### Qué fila le toca a qué empresa

El admin solo ve las filas de SU `tenant_id`. Quedan **sin empresa** (solo las ve
la [vista de operaciones](#vista-web-de-operaciones)):

- **Login de un usuario que no existe**: no se sabe a quién iba. En cambio, un
  usuario existente con la clave equivocada queda en la empresa de ese usuario
  (`authModel::loginUser` devuelve un tercer elemento, solo para la bitácora, con
  `tenant_id`/`user_id`). Hasta el 2026-09-21 **todos** los logins, exitosos y
  fallidos, quedaban sin empresa: el tenant se tomaba del cuerpo del POST, que el
  front ya no manda. Las filas viejas se corrigen con
  `db/master_migrations/009_backfill_audit_login_tenant.sql` (con respaldo y
  forma de deshacer).
- **Autenticación DGII entrante** (`DGII_AUTH_IN_*`): el handshake semilla→token
  llega por una URL compartida, antes de saber a qué tenant va el e-CF (se
  resuelve después por `RNCComprador`). Para que el admin igual sepa quién le
  entregó un e-CF, el handshake guarda el token hasheado en `session_token_hash`
  y `ECF_RECEIVED` / `ACECF_RECEIVED` registran el mismo hash más
  `new_values.autenticacion` (`metodo`, `rnc_del_token`, `token_expedido`): las
  dos filas quedan ligadas.

## Arquitectura

| Pieza | Archivo | Rol |
|---|---|---|
| `AuditLogger` | `src/AuditLogger.php` | Fachada que usan los controllers (`log()`, `authEvent()`); redacta secretos; nunca lanza |
| `RequestContext` | `src/RequestContext.php` | Auto-rellena identidad/tenant/IP/UA/navegador/SO/dispositivo/método/endpoint |
| `AuditLogModel` | `src/Models/AuditLogModel.php` | Persistencia (`insert`) + lectura filtrada/paginada (`search`/`count`) |
| `UserAgentParser` | `src/Utils/UserAgentParser.php` | Parser ligero de User-Agent (sin Composer) |
| `AuditMiddleware` | `src/Middleware/AuditMiddleware.php` | Capa fina opcional (boot + `logAccessDenied`) |
| `auditLogController` | `src/Controllers/auditLogController.php` | `GET /api/audit-logs` (solo lectura, admin) |
| Vista web de operaciones | `public/audit_logs.html` + `public/audit_logs.php` | Consulta de **todos** los tenants con `AUDIT_LOGS_TOKEN` (solo lectura) |

Flujo: `AuthMiddleware::validateRequest()` puebla `RequestContext` en cada
resultado válido (un solo punto cubre todos los controllers). El controller llama
`AuditLogger::log([...])` en el sitio de la mutación (único lugar que conoce
`old_values`/`new_values`/`entity_id`); el resto se auto-rellena.

## Uso desde un controller

```php
// CREATE
AuditLogger::log([
    'module' => 'clients', 'action' => 'CREATE',
    'entity_type' => 'client', 'entity_id' => $result[2] ?? null,
    'new_values' => $_POST, 'description' => 'Cliente creado.',
]);

// UPDATE/DELETE: leer el estado previo ANTES de mutar (reconstrucción de valores)
$old = $clientModel->getClients($id)[0] ?? null;
// ... update ...
AuditLogger::log([
    'module' => 'clients', 'action' => 'UPDATE',
    'entity_type' => 'client', 'entity_id' => $id,
    'old_values' => $old, 'new_values' => $_PUT,
    'description' => 'Cliente actualizado.',
]);
```

Claves **requeridas**: `module`, `action`. Opcionales (override de lo
auto-rellenado): `entity_type`, `entity_id`, `old_values`, `new_values`,
`description`, `success`, `error_message`, `tenant_id`, `user_id`, `username`,
`email`, `session_token_hash`.

## Seguridad / redacción

`AuditLogger::redact()` reemplaza por `***REDACTED***` cualquier valor cuya clave
contenga: `password`, `pass`, `secret`, `api_secret`, `token`, `authorization`,
`cert_pass`, `db_pass`, `private_key`, `api_key`, `password_hash`, etc.
**Nunca** se guardan passwords, secrets, claves de certificado ni tokens en claro
(de la sesión solo el `session_token_hash` sha256).

## Garantías

- **Nunca rompe el request**: todo `log()` va en `try/catch`; un fallo se traga
  (`error_log [AuditLogger]`) y la operación de negocio continúa.
- **Apagable**: `AUDIT_LOG_ENABLED=false` desactiva la escritura.
- **Aislamiento**: cada fila lleva `tenant_id`; las lecturas filtran por el tenant
  del solicitante.

## Vocabulario de acciones

`CREATE`, `UPDATE`, `DELETE`, `ASSIGN`, `EMIT`, `STATUS_CHANGE`, `ECF_RECEIVED`,
`ACECF_SENT`, `ACECF_RECEIVED`, `INTEGRACION_EMIT`, `INTEGRACION_ACECF`,
`NCF_RANGE_REGISTER`, `NCF_SEQUENCE_UPDATE`, `LOGO_UPLOAD`, `LOGO_DELETE`,
`AJUSTE_CREAR`, `AJUSTE_ANULAR`, `INVENTARIO_CONFIG`, `LOGIN_SUCCESS`, `LOGIN_FAILED`, `LOGOUT`,
`ACCESS_DENIED`, `DGII_AUTH_IN_OK`, `DGII_AUTH_IN_FAILED`, `DGII_AUTH_OUT_FAILED`.

| Acción | Módulo | Cuándo |
|---|---|---|
| `ACCESS_DENIED` | el módulo al que se intentó entrar | Un usuario con sesión válida pide un módulo que su rol no tiene (**403**). Lo registran `PermissionGate::deny`, `auditLogController` e `inventarioController` (un `PUT /api/inventario/configuracion` de un rol que no es `admin`: módulo `inventario`, motivo `rol distinto de admin`). `new_values.bloqueado=false` = modo sombra (`PERMISSIONS_ENFORCE=false`): se anotó pero no se bloqueó. Los **401** (token vencido o falso) no se registran a propósito: cada sesión que expira con la app abierta dejaría filas sin usuario. |
| `INVENTARIO_CONFIG` | `inventario` | Un admin enciende o apaga «Bloquear ventas sin existencia» de su empresa (`PUT /api/inventario/configuracion`, solo rol `admin`). `entity_type` `inventario_config`, `entity_id` = el id de la empresa. `old_values` / `new_values`: `{ bloquear_sin_existencia: bool }`. Se registra solo cuando el cambio quedó guardado; si falla la escritura (p. ej. la migración master `013` sin correr) no hay fila de éxito. |
| `DGII_AUTH_IN_OK` | `dgii-auth` | Token emitido a quien se autentica contra nuestro receptor (incluye los reintentos sobre una semilla ya canjeada). Sin empresa. |
| `DGII_AUTH_IN_FAILED` | `dgii-auth` | Handshake rechazado: sin XML, firma inválida, semilla no reconocida / expirada / canjeada por otro RNC, error interno. Sin empresa. |
| `DGII_AUTH_OUT_FAILED` | `dgii-auth` | La DGII no nos entregó el token (`DgiiAuthService::autenticar`), con el paso que falló. Solo los fallos: se pide un token por emisión y registrar los éxitos sería una fila por factura. |
| `EMIT` con `success=0` (envío sin confirmar) | `facturas` | La factura quedó guardada antes del envío (write-ahead) pero no se sabe si la DGII la recibió; la respuesta fue el `502` con `data`. `entity_id` = e-NCF. `new_values`: `factura_id`, `tipo_ecf`, `client_id`, `estado_dgii` (`ENVIO_INCIERTO`, o `PENDIENTE_ENVIO` si ni eso se pudo escribir), `fase_fallida` (`envio`, `confirmar` o `reservar`) y `resultado_parcial` (la respuesta de la DGII ya recortada y sin XML, cuando falló la confirmación). `error_message`: el error original. Soporte la usa para resolver la fila ([../integrations/dgii-ecf.md](../integrations/dgii-ecf.md)). |
| `EMIT` con `success=0` (rechazo por existencia) | `facturas` | El e-CF **no se emitió**: la empresa tiene encendido «Bloquear ventas sin existencia» y la revisión previa lo rechazó (`422`: `codigo` `STOCK_INSUFICIENTE`, o el `422` llano de un producto que no existe o una cantidad ≤ 0). Pasa antes de reservar el e-NCF, así que **no se dispensó ningún e-NCF y no hay fila en `facturas`**: no hay nada que resolver, al contrario de la fila anterior. `entity_id` = el `e_ncf` que mandó el cliente (casi siempre vacío). `new_values`: `tipo_ecf`, `client_id` y `faltantes` (la lista del `422`; `null` en el `422` llano). `error_message`: el texto que vio el usuario. `description`: «e-CF no emitido: la verificación de existencia lo rechazó.», que es lo que la distingue del envío sin confirmar. El reenvío de un rechazado, E34 y E41 nunca la generan. |
| `CREATE` / `UPDATE` con `success=0` (rechazo por existencia) | `facturas-simples` | Una factura simple que la revisión de existencia rechazó con `422` (`STOCK_INSUFICIENTE`, o el `422` llano). El modelo ya deshizo todo: no hay factura nueva ni cambios en la editada, ni movimientos de inventario. `entity_type` `factura_simple`. `CREATE`: sin `entity_id`; `new_values`: `client_id`, `client_name` y `faltantes`; `description` «Factura simple no guardada: la verificación de existencia la rechazó.» `UPDATE`: `entity_id` = el id de la factura; `new_values`: `faltantes`; `description` «Factura simple `{no_factura}` no actualizada: la verificación de existencia la rechazó.» (con el número de la factura, o su id si no tiene) `error_message`: el texto del `422`; `faltantes` es `null` en el `422` llano. Un fallo del libro dentro de la transacción (`400`) **no** deja fila: solo la línea `[inventario] …` en el log. |
| `NCF_SEQUENCE_UPDATE` con `success=0` | `ncf` | Un e-NCF quedó consumido sin fila: la emisión falló antes del envío y no se pudo devolver el número a la secuencia (`error_message` `rollback sin coincidencia`). `entity_id` = e-NCF. `new_values`: `e_ncf`, `tipo_ecf`, `rango_id`, `secuencia_valor`, `ambiente_secuencia`, `causa` (la clase del error). |

Inmutabilidad e-CF: un e-CF emitido solo genera `EMIT`/`STATUS_CHANGE`; **nunca**
hay `UPDATE`/`DELETE` sobre comprobantes emitidos.

## Endpoint de lectura

Solo el **rol `admin`** (por nombre de rol), siempre acotado al tenant del
solicitante. No alcanza con el permiso `*` —`RoleModel` deja crear roles
personalizados con todos los módulos— ni con el módulo `audit`, que por API se
puede asignar a cualquier rol. Un intento de otro rol queda como `ACCESS_DENIED`.

| Endpoint | Devuelve |
|---|---|
| `GET /api/audit-logs` | Filas paginadas (`page`, `pageSize` máx 200). `old_values`/`new_values` decodificados a objeto. |
| `GET /api/audit-logs/resumen` | `total`, `fallidos`, `accesos_denegados`, `logins_fallidos`, `usuarios`, `top_usuarios` (5) y `por_modulo`. |
| `GET /api/audit-logs/facetas` | `modulos`, `acciones` y `usuarios` que tienen filas en el tenant (para los filtros). |

Filtros de lista y resumen: `user_id`, `module`, `action`, `entity_type`,
`entity_id`, `success` (1/0), `from`, `to` y `q` (texto libre sobre usuario,
email, entidad, descripción, endpoint e IP). Una fecha sola en `to` incluye el día
completo (antes `to=2026-09-21` dejaba fuera todo lo de ese día después de las
00:00).

En el front: **Administración → Bitácora** (`src/features/audit/` en fiscalo),
visible solo para el rol admin.

## Vista web de operaciones

`/api/public/audit_logs.html` — para consultar la bitácora **sin entrar a la base
de datos**. A diferencia del endpoint anterior, ve **todos los tenants** (es una
herramienta de operaciones, no del cliente). Solo lectura.

- **Acceso:** token `AUDIT_LOGS_TOKEN` del `.env` del server (sin valor, o con el
  placeholder `CAMBIAR`, responde 403). Viaja por POST, nunca en la URL. La
  bitácora trae emails, IPs y valores de todos los tenants: token largo y
  aleatorio, HTTPS, y considerar Basic Auth de cPanel sobre `/api/public/`.
- **Filtros:** tenant (todos, uno, o "sin tenant" = login fallido / DGII
  entrante), módulo, acción, resultado, rango de fechas (días completos) y texto
  libre sobre usuario, email, id de entidad, descripción, endpoint e IP.
- **Detalle:** clic en una fila → todos los campos y, debajo, **solo lo que
  cambió** (antes tachado en rojo → después en verde). Lo que no cambió queda
  detrás de "Mostrar también lo que no cambió", y el JSON tal cual sigue en
  "Ver JSON original". El diff está pensado para la forma real de los valores:
  - Compara por valor, no por texto: `"18000.0000"` = `18000`, `1` = `true`,
    `"12"` = `12`, `"2026-10-01 00:00:00"` = `"2026-10-01"`, y
    `retencion_isr` `true` = el monto guardado. Los campos de texto o código
    (`sku`, `codigo`, `description`, `nombre`, `rnc`, `telefono`, `email`…) se
    comparan y muestran tal cual: `"2.10"` → `"2.1"` y `"001"` → `1` sí son
    cambios.
  - `new_values` suele ser el cuerpo del PUT, parcial: un campo que no vino sale
    como **no enviado** y el resumen los cuenta aparte, porque la bitácora no
    guarda su valor después (pudo quedar igual o recalcularlo el servidor:
    totales, `updated_at`). Un campo nuevo en `0`/`false`/vacío no cuenta como
    cambio.
  - Excepción, los UPDATE que escriben toda la fila: products, proveedores,
    categories y warehouses ponen NULL (o su valor por defecto: `activo`/`estado`
    = 1, `precio`/`costo` = 0, `unidad_medida` = `43`) en la columna que no viene,
    y el formulario no manda los campos que el usuario dejó en blanco. Ahí el
    campo que falta sale como **cambio** al valor que queda (`telefono 809… →
    vacío`, "no vino en lo enviado: queda así"). La lista vive en
    `OMITTED_BECOMES` de `audit_logs.html` y copia los `bindParams` de cada
    modelo: si uno cambia, hay que tocarla. clients usa COALESCE (lo que no viene
    se conserva) y no está. En una cotización Ferretería un PUT sin `ajustes` los
    quita todos, y dentro de un `ajustes` que sí vino el concepto que falta vale 0.
  - En un texto largo que cambió se resalta solo el tramo distinto (un cambio
    que solo toca espacios o saltos de línea se ve como `·`/`↵`).
  - Los arreglos de objetos (`items`) se comparan **línea por línea**. Las
    líneas no tienen id estable (cada guardado las borra y reinserta), así que se
    alinean por `product_id`, o por `description` en las líneas libres,
    respetando el orden; con el mismo producto en varias líneas gana la
    alineación que deja quietas las líneas idénticas, y una línea suelta se
    empareja con la más parecida. Cada línea sale como Modificada (con sus
    campos `antes → después`; si cambió de lugar, también `posición #3 → #1`),
    Agregada, Quitada o Sin cambios. El `id` de la línea no se compara.
  - Los arreglos de texto (`permissions`) se comparan como conjunto (agregados /
    quitados). Branding compara `pdf_template` contra `template` (y el color).
  - Eventos de un solo lado (EMIT, LOGIN, DELETE…): tabla campo / valor con los
    objetos anidados aplanados (`dgii_response.codigo`) y los arreglos de
    objetos en tabla. Los valores largos (`xml_firmado`, `logo_data_uri`) salen
    plegados.
  - Un valor recortado a 100.000 caracteres (JSON inválido) no se compara: se
    avisa y se muestra tal cual.
- **Backend:** `public/audit_logs.php` (acciones `meta` y `search`, JSON). Usa
  `AuditLogModel::searchAllTenants()` / `countAllTenants()`, las únicas lecturas
  sin aislamiento por tenant — **no** usarlas desde controllers de la API.
- Todo valor de la bitácora se pinta como texto (nunca `innerHTML`): un login
  fallido puede traer HTML en el usuario.

## Cobertura actual

Mutaciones (CREATE/UPDATE/DELETE) de: clients, products, categories, warehouses,
proveedores, users, roles (+ASSIGN), branding, landing, ncf (rangos/secuencia),
cotizaciones, **facturas simples** (`facturas-simples`, entidad `factura_simple`,
con antes/después) y **conduces** (`conduces`, entidad `conduce`, `entity_id` = el id del
conduce: CREATE con el cuerpo en `new_values`, UPDATE con la fila de antes y el cuerpo,
DELETE con la fila en `old_values`; el DELETE no borra, pone `activo = 0`). Gastos:
alta/emisión exitosa **y fallida** (`success=0` con el
motivo: un E41 que la DGII rechaza ya no desaparece sin rastro). Inventario:
ajustes, el interruptor de ventas sin existencia (`INVENTARIO_CONFIG`) y los rechazos por existencia
(`EMIT` de `facturas`, y `CREATE` / `UPDATE` de `facturas-simples`, con `success=0` y `faltantes`; ver la
tabla de arriba). El ajuste `INVENTARIO_INICIAL` con que se abre la existencia de un producto nuevo
(`POST /api/products` con el interruptor encendido) no deja fila `AJUSTE_CREAR`: lo audita solo el
`CREATE` del producto, cuyo `new_values` lleva el `stock` tecleado. Ciclo e-CF: emisión, transiciones de estado, ACECF saliente/entrante,
recepción (ligada al token DGII), emisión/aprobación por integración, emisión sin
confirmar de la DGII (`EMIT success=0` con la fila en `new_values`) y e-NCF consumidos
sin fila (`NCF_SEQUENCE_UPDATE success=0`). Auth: login
ok/fallido (en la empresa del usuario), logout. Accesos denegados (403).
Autenticación DGII entrante (todo) y saliente (fallos).

**Fuera de alcance** (futuro, trivial con la misma línea): auditar lecturas
(descargas XML/PDF, consultas de estado, reportes 606/607), escritura asíncrona,
exportación a Excel/PDF y retención/particionado.
