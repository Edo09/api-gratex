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
  a una conexión viva, sirve a tenants `app` e `integracion`, y los logins
  fallidos (sin tenant resuelto aún) caben en la misma tabla.
- **Single-tenant** (fallback): en la DB del tenant, `tenant_id` queda NULL.

DDL: `db/master_migrations/006_add_audit_logs.sql`, espejado en
`db/master_schema.sql` y `db/tenant_schema.sql`.

## Arquitectura

| Pieza | Archivo | Rol |
|---|---|---|
| `AuditLogger` | `src/AuditLogger.php` | Fachada que usan los controllers (`log()`, `authEvent()`); redacta secretos; nunca lanza |
| `RequestContext` | `src/RequestContext.php` | Auto-rellena identidad/tenant/IP/UA/navegador/SO/dispositivo/método/endpoint |
| `AuditLogModel` | `src/Models/AuditLogModel.php` | Persistencia (`insert`) + lectura filtrada/paginada (`search`/`count`) |
| `UserAgentParser` | `src/Utils/UserAgentParser.php` | Parser ligero de User-Agent (sin Composer) |
| `AuditMiddleware` | `src/Middleware/AuditMiddleware.php` | Capa fina opcional (boot + `logAccessDenied`) |
| `auditLogController` | `src/Controllers/auditLogController.php` | `GET /api/audit-logs` (solo lectura, admin) |

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
`LOGIN_SUCCESS`, `LOGIN_FAILED`, `LOGOUT`.

Inmutabilidad e-CF: un e-CF emitido solo genera `EMIT`/`STATUS_CHANGE`; **nunca**
hay `UPDATE`/`DELETE` sobre comprobantes emitidos.

## Endpoint de lectura

`GET /api/audit-logs` — solo admin (módulo `audit`), siempre acotado al tenant del
solicitante. Filtros: `user_id`, `module`, `action`, `entity_type`, `entity_id`,
`success`, `from`, `to` (fecha/datetime), `page`, `pageSize` (máx 200).
`old_values`/`new_values` se devuelven decodificados a objeto.

## Cobertura actual

Mutaciones (CREATE/UPDATE/DELETE) de: clients, products, categories, warehouses,
proveedores, users, roles (+ASSIGN), branding, landing, ncf (rangos/secuencia),
cotizaciones. Ciclo e-CF: emisión (facturas/gastos), transiciones de estado, ACECF
saliente/entrante, recepción, emisión/aprobación por integración. Auth: login
ok/fallido, logout.

**Fuera de alcance** (futuro, trivial con la misma línea): auditar lecturas
(descargas XML/PDF, consultas de estado, reportes 606/607), escritura asíncrona,
exportación a Excel/PDF, retención/particionado, y la UI de timeline.
