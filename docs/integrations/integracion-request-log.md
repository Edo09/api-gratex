# Bitácora de peticiones de integración (`integracion_request_log`)

Cada petición que un cliente de integración le hace al API queda en
`integracion_request_log`, en la base **master**: lo que mandó, lo que se le contestó, el
código HTTP y el detalle interno del fallo, el que no se le muestra. Sirve para responder
rápido a "me da error", sin el `error_log` del servidor (no hay shell) y aunque el e-CF
nunca se haya emitido.

- Código: `src/IntegracionRequestLog.php`. El Router lo arranca antes de la página de docs,
  del `PermissionGate` y de los controllers.
- Tabla: `db/master_migrations/018_integracion_request_log.sql` (en
  `db/master_schema.sql` para instalaciones nuevas).
- Pruebas: `tools/test_integracion_request_log.php` (sin servidores; SQLite).

## Qué se registra

Toda petición a `/api/integracion/*` y, en cualquier ruta, toda petición que traiga
`X-API-SECRET` (un cliente que llama a una ruta equivocada también quiere saber por qué le
falla). El preflight `OPTIONS` no se registra. Se guarda **una fila por petición** al
terminar, también cuando termina con `exit` (`sendUnauthorized`) o con un 500 de
`ErrorHandler`.

| Columna | Contenido |
|---|---|
| `tenant_id`, `rnc` | Empresa por la que se actuó, ya elegida la hermana por RNC si la credencial es de grupo. `tenant_id` NULL = la credencial no autenticó; entonces `rnc` sale del pedido |
| `api_key_prefijo` | Primeros 8 caracteres del `X-API-KEY`. El `X-API-SECRET` nunca se guarda |
| `metodo`, `ruta`, `query_string` | La petición. Un parámetro del query con nombre sensible se guarda redactado |
| `e_ncf` | Del cuerpo (`e_ncf`), del query o de la respuesta, para buscar por comprobante |
| `ip`, `user_agent`, `request_headers` | `request_headers` es un JSON con las cabeceras sin `X-API-KEY`, `X-API-SECRET`, `Authorization` ni cookies |
| `request_body`, `request_bytes` | El cuerpo tal como llegó, byte a byte. Si es JSON con campos sensibles (`password`, `secret`, `token`, `api_key`...: la lista de `AuditLogger`), se guarda redactado. Si no venía en UTF-8 (un JSON en Windows-1252), se guarda convertido y avisado al principio |
| `http_status` | El código HTTP que se le devolvió |
| `response_body`, `response_bytes` | Lo que se le devolvió. El XML firmado (`xml_firmado`) va como `[XML omitido de la bitacora: N bytes]`, porque ya está en `ecf_integracion_backup` |
| `error_interno` | El detalle que no sale al cliente: la excepción completa con sus causas (`anotar()` en los `catch` de los controllers y en `ErrorHandler`) |
| `duracion_ms`, `created_at` | `created_at` en hora de RD (`src/ZonaHoraria.php`) |

Topes: 1 MB por cuerpo y 16 KB si la respuesta no es JSON (por ejemplo, el HTML de un
error). Cuando se recorta, el texto termina en `...[recortado: se guardaron N de M bytes]`
y `*_bytes` guarda el tamaño real.

## Garantías

- La bitácora **nunca** cambia ni rompe la respuesta. Si no puede guardar (master caído,
  tabla ausente), deja una línea `[IntegracionRequestLog]` en el `error_log` y la respuesta
  sale igual.
- Se apaga con `INTEGRACION_LOG_ENABLED=false` en el `.env`.
- Se purga sola: una de cada 100 peticiones registradas borra hasta 1000 filas de más de
  `INTEGRACION_LOG_DIAS` días (90 por defecto; `0` = no borrar nunca).

## Consultas útiles (phpMyAdmin, base master)

```sql
-- Lo último de una empresa (sin los cuerpos, para ver la lista)
SELECT id, created_at, metodo, ruta, query_string, e_ncf, http_status, duracion_ms,
       LEFT(error_interno, 200) AS error_interno
  FROM integracion_request_log
 WHERE tenant_id = 18
 ORDER BY id DESC LIMIT 50;

-- Los errores de hoy, de todas las empresas
SELECT id, created_at, tenant_id, rnc, metodo, ruta, http_status,
       LEFT(response_body, 300) AS respuesta, LEFT(error_interno, 300) AS error_interno
  FROM integracion_request_log
 WHERE http_status >= 400 AND created_at >= CURDATE()
 ORDER BY id DESC;

-- Todo lo que pasó con un comprobante (emisión, consultas de estado, XML)
SELECT * FROM integracion_request_log WHERE e_ncf = 'E320000000001' ORDER BY id;

-- Una credencial que no autentica (tenant_id NULL)
SELECT id, created_at, api_key_prefijo, ip, ruta, http_status, LEFT(response_body, 200) AS respuesta
  FROM integracion_request_log
 WHERE tenant_id IS NULL ORDER BY id DESC LIMIT 50;
```

## Caso que la originó (2026-11-13, tenant 18, AGROVISA FERRETERIA, certecf)

El sistema del cliente mostraba `Fallo consultando el estado a DGII: DGII authenticated
request failed: HTTP 401` y su código `DGII_REJECTION_DETAIL_UNAVAILABLE` para dos E32 de
consumo (`E320000000001` y `E320000000002`, RD$200, sin RNC comprador) que el API le había
devuelto como `RFCE_RECHAZADO`. Se encontraron tres cosas:

1. **La emisión RFCE no devolvía la respuesta de la DGII.** Para un E32 < 250k,
   `ECFEmissionService::emitir()` la deja en `rfce_response` y pone `dgii_response` en null.
   `integracionEcfController` solo leía `dgii_response`, así que el cliente recibía el estado
   sin el motivo, y `ecf_integracion_backup` no guardaba ni `dgii_respuesta` ni `dgii_mensajes`
   de ningún RFCE. Corregido: `data.dgii_response` trae la respuesta de RecepcionFC
   (`codigo`, `estado`, `mensajes`, `secuenciaUtilizada`) y el respaldo la guarda.
2. **En certecf, la consulta RFCE de la DGII rechaza el token de la propia DGII.**
   `fc.dgii.gov.do/certecf/consultarfce` contesta `401` con
   `WWW-Authenticate: Bearer error="invalid_token", error_description="The signature is invalid"`
   al token de `ecf.dgii.gov.do/certecf/autenticacion`. Con el mismo método, `testecf` y `ecf`
   contestan bien (204 si no existe, 200 con el estado). Es un defecto de la DGII en
   certificación. Para un RFCE, `GET /api/integracion/estado` ahora toma en ese caso el estado
   VIVO de la consulta pública del timbre (`TimbreEcf::consultarEstado`, la página del QR, sin
   token: `fuente: "timbre_dgii"`, `timbre_url`) y corrige el respaldo; si la página tampoco
   responde, devuelve el veredicto guardado al emitir (`fuente: "respaldo"`). Ya no un 502.
3. **La DGII los tiene ACEPTADOS: el `RFCE_RECHAZADO` era nuestro.**
   `https://fc.dgii.gov.do/CerteCF/ConsultaTimbreFC?...` dice `Estado Aceptado` para los dos
   códigos de seguridad. La página es fiable: para `E320000000002` del tenant 6 (ecf) dice
   "Rechazado" con el código del envío que la DGII rechazó (respuesta guardada: "El e-NCF ...
   ya ha sido utilizado previamente") y "Aceptado" con el del envío aceptado; con un código
   inventado, "No fue encontrada". La respuesta original de RecepcionFC se perdió por el punto
   1, así que no se sabe por qué el mapeo dio rechazado. El mismo patrón aparece en
   `E320000000369` de Gratex (certecf, junio). Un envío nuevo ya trae la respuesta completa en
   `dgii_response` y queda en esta bitácora: es lo que hay que mirar si se repite.

Además, todo 401/403 de la DGII ahora dice la cabecera `WWW-Authenticate` y el servicio
llamado (`DgiiAuthService::responseSummary`). Antes solo decía `HTTP 401`.
