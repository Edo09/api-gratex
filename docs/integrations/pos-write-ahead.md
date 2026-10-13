# La venta del POS sobre el write-ahead del e-CF

Cómo quedó construido `POST /api/pos/ventas` sobre el write-ahead (rama `feat/ecf-write-ahead`).
La intención está en la spec `docs/superpowers/specs/2026-10-12-pos-y-reenvio-sobre-write-ahead-design.md`
(repo del front `fiscalo`); el comportamiento, en el código y en las pruebas de la sección 9.

Dos garantías:

- **Toda factura se guarda antes de enviarse a la DGII.** Ningún camino guarda después de enviar
  (así se perdió la E34 del 2026-10-05).
- **Una venta del POS no se detiene por la DGII, el certificado ni la secuencia.** Solo la frenan las
  validaciones del carrito (los `4xx` de `docs/api/pos.md`) y que el servidor o la base no estén
  disponibles, porque entonces no hay dónde guardarla.

## 1. La venta (`PosVenta::cobrar`)

1. **Validaciones, clave e idempotencia** (sin cambios): candado de la clave, búsqueda por
   `pos_idempotency_key`, candado del turno, líneas armadas en el servidor y total comparado.
2. **Emisión con el gancho** `reservar` / `confirmar` / `incierto`, igual que `handleEmisionECF`.
   `reservarFacturaECF` guarda la fila en `PENDIENTE_ENVIO` **antes** de llamar a la DGII y, si la
   factura trae `pos`, escribe en el mismo `INSERT` `pos_idempotency_key`, `turno_id`,
   `pos_empleado_id` y `envio_pendiente = 1`. Un `1062` sobre `uk_facturas_pos_idem` es una venta
   repetida (`EcfVentaRepetidaException`): se relee por clave. Un `1062` sobre el e-NCF sigue siendo
   `EcfNumeroOcupadoException`: la venta lo reintenta una vez con el número siguiente.
3. **Cualquier resultado después de guardar se entrega** (`201` con el recibo): aceptada, rechazada,
   `ERROR`, `NO_ENCONTRADO`, en proceso o sin respuesta (`EcfEnvioInciertoException`).
4. **Fallo antes de guardar:** factura simple (sección 4). **Sin rango libre:** número siguiente
   sin enviar (sección 5).

Opciones del payload solo para el POS (la app y las integraciones conservan el valor de siempre):

| Opción | En el POS | Efecto |
|---|---|---|
| `dgii_timeout` | `POS_DGII_TIMEOUT` (5 s) | Espera máxima al pedir el token y al enviar |
| `formas_pago` | la elegida | `TablaFormasPago` del RFCE y del e-CF |
| `reclamar_secuencia` | `false` | El número de una venta del POS nunca vuelve al contador: ya está impreso |
| `autenticar_despues_de_reservar` | `true` | El token se pide después de guardar; si la DGII no responde, la venta queda `ENVIO_INCIERTO` (fase `autenticar`) |
| `numerar_sin_rango` | `true` | Sin rango libre se usa el número siguiente al último (sección 5) |
| `validar_vigencia_certificado` | `true` | Antes de reservar se lee el `.p12`: un certificado vencido falla antes de guardar y la venta sale como factura simple (sección 4) |

## 2. `envio_pendiente`

Se escribe en la **misma sentencia** que `estado_dgii` y solo en filas del POS, con
`EstadosEcf::envioPendiente`: `1` en la reserva, `1` en `confirmar` si el veredicto sigue abierto
(`ENVIADO`, `EN_PROCESO`, `RFCE_ENVIADO`, `RFCE_EN_PROCESO`), `0` en cualquier otro estado y `1` en
`incierto`. También lo escribe así `updateECFEstado` (la consulta de la app, `GET
/api/facturas/{id}/estado`), para que una venta que la app resuelve antes que el reenvío automático
salga de la lista de pendientes. El POS ya no tiene estados «pendientes» propios: un envío sin
respuesta es `ENVIO_INCIERTO` y una venta sin enviar es `PENDIENTE_ENVIO`, como en la app.

Las filas del POS que terminan en `ERROR` o `NO_ENCONTRADO` conservan `envio_pendiente = 0`: ese
número no se reenvía solo y el reenvío automático no las toca. `NO_ENCONTRADO` se reemite con
«Reenviar a la DGII» (sección 7); `ERROR` es de soporte (ese número se quema a propósito).
`RFCE_NO_ENCONTRADO` tampoco entra en «Reenviar a la DGII» (solo `RECHAZADO`, `RFCE_RECHAZADO` y
`NO_ENCONTRADO`): la resuelve soporte. El reenvío del POS nunca la escribe, porque ante un RFCE que
la DGII no encuentra reenvía el RFCE guardado.

## 3. Entrega (`PosVenta::entregarVenta`)

Una sola función idempotente, bajo el candado de la clave, tras cualquier resultado guardado y en
una venta repetida que encuentre algo sin hacer: el movimiento de caja (si `posModel` no tiene uno
para la factura), el inventario una sola vez (`yaMovioDocumento('factura', id)` y `registrarVenta`) y
la auditoría, solo si esta llamada registró la caja (`POS_VENTA`, o `POS_VENTA_FACTURA_SIMPLE` en una
factura simple). Un fallo del inventario no tumba la venta: queda en el log para un ajuste.

Repetir la clave devuelve la misma venta (`repetida: true`, siempre `201`, también si la DGII la
rechazó) y completa lo que falte: por ejemplo, si el servidor cayó entre guardar la respuesta y
registrar la caja, la caja sale con la `forma_pago` y el `recibido_centavos` del reintento (si ya
existe el movimiento, se usan los suyos). El recibo usa `RepresentacionImpresa::datosRecibo`, que no
lee `estado_dgii`.

Una venta rechazada, al cobrar o más tarde, conserva su caja y su inventario, no devuelve el número,
el cierre la lista como rechazada y en app.\* aparece «Rechazada» con «Reenviar a la DGII». Al cobrar
quedan `POS_VENTA` y `POS_VENTA_RECHAZADA` en la bitácora.

## 4. Fallo antes de guardar: factura simple

Si el e-CF no se puede generar (certificado ausente, vencido o con clave incorrecta, fallo de firma,
`emisor_config` incompleto, número ocupado tras un reintento o cualquier excepción antes de la reserva,
que ya devuelve el número al contador), la venta se guarda como **factura simple** (`tipo_ecf` nulo, sin
e-NCF, sin ITBIS, sin envío) con las mismas líneas, el mismo total y las columnas del POS: la
idempotencia no cambia (`facturaModel::crearFacturaSimplePos`).

- La entrega es la misma (sección 3) y la respuesta es `201` con el recibo de factura simple (título
  «Factura», sin timbre), `venta.tipo_ecf: null`, el número de la factura en `venta.e_ncf` y
  `envio_pendiente: false`. Queda `POS_VENTA_FACTURA_SIMPLE` en la bitácora, con la clase del fallo y
  sin secretos (con `success` verdadero: la venta salió).
- El cierre y las listas identifican la venta con el número de su factura (`no_factura`), y el reporte
  la cuenta como `Simple`.
- Las validaciones del carrito siguen respondiendo `4xx`: son errores de captura, no de emisión.
- Si ni la factura simple se puede guardar (la base no responde), la venta sí se detiene:
  `500 VENTA_NO_GUARDADA` y `POS_VENTA_FALLIDA` en la bitácora; el cajero reintenta con la misma
  clave. Si otra petición con esa clave dejó una fila entre tanto, `409 VENTA_EN_PROCESO`.
- El aviso al admin (sección 8) sale de contar estas filas, no de la bitácora.

La factura simple no es un comprobante fiscal: no lleva ITBIS ni entra en el 607. Falta confirmar con
el contador cómo regularizar las que salgan así.

**Las facturas simples del POS no se editan ni se eliminan.** Aparecen en la lista «Facturas simples»
de app.\*, pero `updateFacturaSimple` y `deleteFacturaSimple` rechazan cualquier fila con
`pos_idempotency_key` (`409`: «Esta factura viene de una venta del POS y no se puede editar ni
eliminar.»; el controlador no toca el inventario). Cambiarlas o borrarlas desde ahí descuadraría la
caja, el inventario y la clave de idempotencia de la venta. Si una hay que corregirla, se hace con
soporte, no desde la lista.

## 5. Secuencia agotada o vencida: `sin_rango`

Solo en el POS, cuando `ncfModel::dispenseNextECF` devuelve `null`:

- `ncfModel::dispenseSinRango` toma el **número siguiente al último usado** y avanza el contador del
  rango con el `numero_desde` más alto **sin** el tope de `numero_hasta`.
- La venta se firma, se guarda y se imprime normal, marcada `sin_rango` en la meta de la fila
  (`respuesta_dgii`), y **no se envía** (ni siquiera se pide el token): la DGII rechaza los números
  fuera de un rango autorizado. Queda `PENDIENTE_ENVIO` con `envio_pendiente = 1`.
- Solo el tipo agotado se ve afectado: con E32 agotada, las E31 siguen su curso.
- **E32:** el XML no lleva fecha de vencimiento de la secuencia, así que queda listo para enviarse.
  **E31:** lleva la fecha del último rango conocido; si la DGII la rechaza, se reemite con «Reenviar a
  la DGII» (sección 7).
- La envía el reenvío automático cuando `ncfModel::rangoCubre(tipo, ambiente, número)` es cierto: hay
  un rango registrado con `numero_desde <= número <= numero_hasta` (o sin `numero_hasta`) y no vencido.
  Si la DGII autoriza un rango que **no** incluye esos números, esas ventas no salen solas: quedan en el
  aviso de la sección 8 para soporte.
- `POST /api/ncf/rangos` acepta un rango que empieza dentro de los números usados `sin_rango`: arranca
  a dispensar después del último que caiga dentro de él, y la emisión normal salta los números que ya
  usa una fila (`eNcfYaUsado`), así que nunca reparte dos veces un número ya usado.

## 6. Reenvío automático (`PosVenta::reenviarPendientes`)

`POST /api/pos/pendientes/reenviar` (solo `X-POS-EQUIPO`), disparado por la pantalla del POS al abrir,
cada 2 minutos y tras cada venta, en silencio. Revisa hasta 5 ventas por llamada. Consulta primero,
reenvía el XML guardado, no re-firma y no devuelve números.

- **Flujo por XML guardado:** RFCE si `rfce_xml` no es nulo; e-CF completo si no.
- **Selección:** filas del POS que son e-CF (no las facturas simples) con `envio_pendiente = 1`, salvo las `PENDIENTE_ENVIO` de menos
  de 2 minutos (la petición original todavía las está enviando), las ventas cuya petición sigue viva
  (el reenvío pide sin esperar el candado de su clave y salta la fila si está tomado) y las
  `sin_rango` mientras `rangoCubre` sea falso.
- **Escrituras con compare-and-set** (`UPDATE ... WHERE id = :id AND estado_dgii = :leido`), con
  `envio_pendiente` en la misma sentencia: nunca pisa un estado final escrito por otro proceso.
- **Respuesta:** `{revisadas, aceptadas, rechazadas, pendientes}`. Bitácora: `POS_VENTA_ENVIADA` y
  `POS_VENTA_RECHAZADA`.
- En `certecf` no existe `ConsultaTrackIds`: una E31 sin `trackId` queda pendiente para soporte. Las RFCE sí
  se consultan.

## 7. «Reenviar a la DGII» (rechazadas)

`POST /api/facturas/{id}/reenviar` (`handleReenviar`): solo e-CF en `RECHAZADO`, `RFCE_RECHAZADO` o
`NO_ENCONTRADO`, no archivado, con `secuencia_utilizada` distinto de `1`, del mismo ambiente, con líneas
y un candado por factura (`tomarLockReenvio`). Reemite por `emitirFacturaEcf` con `$input['e_ncf']` = el
e-NCF de la rechazada y `$reenvioDe` = su id: la reserva archiva la rechazada, `archivarRechazoReenviado`
queda de respaldo y `devolverSaldoDeFactura` devuelve el inventario que la vieja haya movido. El XML
nuevo lleva el vencimiento de la secuencia del rango vigente que cubre el número
(`vencimientoDeLaSecuencia`), para no repetir un rechazo por esa fecha.

Una factura sin confirmar (`PENDIENTE_ENVIO`, `ENVIO_INCIERTO`, `ENVIADO`, `EN_PROCESO`) responde `409`:
la app nunca la reenvía. En una venta del POS reemitida con el mismo número, el QR del papel original
tiene el código de seguridad de la primera firma y no validará en la DGII; si el cliente necesita el
comprobante válido, se reimprime desde la app.

Una venta del POS reemitida así sigue siendo una sola venta: la fila archivada conserva la caja, el
inventario que movió se devuelve y la nueva lo descuenta cuando la DGII la acepta; el cierre la cuenta
pero ya no la lista en `rechazadas`, y repetir la clave original devuelve la misma venta (`201`,
`repetida: true`).

## 8. Avisos al admin (solo en la app, nunca en la caja)

`GET /api/facturas/avisos` (ver `docs/api/facturas.md`) alimenta el panel e-CF de app.\*: **«El certificado
falló: N ventas del POS salieron como factura simple»** (las de hoy), **«Quedan pocos números»** (50 o menos
en el rango vigente) o **«Secuencia agotada»** (solo el ambiente activo, un aviso por tipo), y **«N ventas
esperando un rango autorizado»**.

## 9. Pruebas

`tools/test_pos_scratch.php` prepara el master multi-tenant de las bases scratch, levanta la DGII
simulada y dos instancias de la API y corre en serie `test_pos_backend.php`, `test_pos_venta.php`,
`test_pos_cierre.php`, `test_pos_e31.php` y `test_pos_write_ahead.php` (RF1 a RF5, factura simple, sin rango,
«Reenviar a la DGII» y el cierre). `tools/pos_scratch_guard.php` las niega contra cualquier base que no sea
`*wa_scratch` / `*wa_scratch_sin_gate` marcada, salvo un MySQL local. En proceso, sin servidor HTTP:
`test_pos_cobrar_wa.php` (la venta y RF1/RF2), `test_pos_continuidad.php` (certificado, factura simple y
sin rango) y `test_pos_reenvio.php` (el reenvío automático), con `--tenant-db=wa_scratch`. Las de Release 1
siguen en `tools/test_write_ahead_*.php` (la guarda de texto de esta documentación, en
`tools/test_write_ahead_puro.php`, sin base).

## 10. Antes de desplegar

La migración 030 tiene que estar en **todas** las DBs de tenant, aunque no usen el POS: los `UPDATE` que
anotan la respuesta de la DGII en toda factura e-CF nombran `pos_idempotency_key` y `envio_pendiente`
(`CLAUDE.md` y `docs/integrations/alta-tenant-runbook.md`). Junto con la 027 y la 028 es la compuerta del
write-ahead.

Referencias: la spec de esta entrega y la del write-ahead (`2026-10-05-ecf-write-ahead-design.md`), en
`fiscalo`; `docs/integrations/dgii-ecf.md` (procedimiento 10.2 y «Reenviar a la DGII una factura
rechazada»); `docs/api/pos.md`; `docs/specs/pos.md`; `EstadosEcf.php`.
