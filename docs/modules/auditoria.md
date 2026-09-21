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
  front ya no manda.
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
`AJUSTE_CREAR`, `AJUSTE_ANULAR`, `LOGIN_SUCCESS`, `LOGIN_FAILED`, `LOGOUT`,
`ACCESS_DENIED`, `DGII_AUTH_IN_OK`, `DGII_AUTH_IN_FAILED`, `DGII_AUTH_OUT_FAILED`.

| Acción | Módulo | Cuándo |
|---|---|---|
| `ACCESS_DENIED` | el módulo al que se intentó entrar | Un usuario con sesión válida pide un módulo que su rol no tiene (**403**). Lo registran `PermissionGate::deny` y `auditLogController`. `new_values.bloqueado=false` = modo sombra (`PERMISSIONS_ENFORCE=false`): se anotó pero no se bloqueó. Los **401** (token vencido o falso) no se registran a propósito: cada sesión que expira con la app abierta dejaría filas sin usuario. |
| `DGII_AUTH_IN_OK` | `dgii-auth` | Token emitido a quien se autentica contra nuestro receptor (incluye los reintentos sobre una semilla ya canjeada). Sin empresa. |
| `DGII_AUTH_IN_FAILED` | `dgii-auth` | Handshake rechazado: sin XML, firma inválida, semilla no reconocida / expirada / canjeada por otro RNC, error interno. Sin empresa. |
| `DGII_AUTH_OUT_FAILED` | `dgii-auth` | La DGII no nos entregó el token (`DgiiAuthService::autenticar`), con el paso que falló. Solo los fallos: se pide un token por emisión y registrar los éxitos sería una fila por factura. |

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
- **Detalle:** clic en una fila → todos los campos + tabla campo / antes / después
  con lo que cambió resaltado.
- **Backend:** `public/audit_logs.php` (acciones `meta` y `search`, JSON). Usa
  `AuditLogModel::searchAllTenants()` / `countAllTenants()`, las únicas lecturas
  sin aislamiento por tenant — **no** usarlas desde controllers de la API.
- Todo valor de la bitácora se pinta como texto (nunca `innerHTML`): un login
  fallido puede traer HTML en el usuario.

## Cobertura actual

Mutaciones (CREATE/UPDATE/DELETE) de: clients, products, categories, warehouses,
proveedores, users, roles (+ASSIGN), branding, landing, ncf (rangos/secuencia),
cotizaciones y **facturas simples** (`facturas-simples`, entidad `factura_simple`,
con antes/después). Gastos: alta/emisión exitosa **y fallida** (`success=0` con el
motivo: un E41 que la DGII rechaza ya no desaparece sin rastro). Inventario:
ajustes. Ciclo e-CF: emisión, transiciones de estado, ACECF saliente/entrante,
recepción (ligada al token DGII), emisión/aprobación por integración. Auth: login
ok/fallido (en la empresa del usuario), logout. Accesos denegados (403).
Autenticación DGII entrante (todo) y saliente (fallos).

**Fuera de alcance** (futuro, trivial con la misma línea): auditar lecturas
(descargas XML/PDF, consultas de estado, reportes 606/607), escritura asíncrona,
exportación a Excel/PDF y retención/particionado.
