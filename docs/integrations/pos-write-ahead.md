# POS y write-ahead del e-CF: lo que `POST /api/pos/ventas` tiene que respetar

Nota para quien construye la venta del POS (`docs/specs/pos.md` §9.4-9.6). Desde el
write-ahead (rama `feat/ecf-write-ahead`), la factura e-CF se **guarda antes** de enviarla a
la DGII. Así ya no se puede repetir lo del 2026-10-05: la DGII aceptó la nota de crédito
E340000000001 y la factura nunca quedó en la base, porque se guardaba **después** del envío.
El POS no debe volver a ese patrón.

## 1. Reservar la fila antes de enviar a la DGII

La spec del POS (§9.4, paso 7) guarda la factura **después** de `ECFEmissionService::emitir`.
Ese orden es el que perdió el E34. La venta tiene que pasarle a `emitir` el gancho de
persistencia, igual que `handleEmisionECF` en `src/Controllers/facturaController.php`:

```php
$reserva = null;
$persistencia = function (string $fase, array $ctx) use ($facturaModel, $facturaInput, &$reserva) {
    switch ($fase) {
        case 'reservar':  return $reserva = $facturaModel->reservarFacturaECF($facturaInput, $ctx);
        case 'confirmar': return $facturaModel->confirmarFacturaECF((int) $reserva['factura_id'], $ctx);
        case 'incierto':  return $facturaModel->marcarEnvioIncierto((int) $reserva['factura_id'], $ctx);
    }
    return null;
};
$result = (new ECFEmissionService())->emitir($payload, $persistencia);
```

- `'reservar'` guarda la fila (XML firmado, código de seguridad y líneas) en
  `PENDIENTE_ENVIO`, **antes** de cualquier llamada a la DGII.
- `'confirmar'` le escribe la respuesta de la DGII.
- `'incierto'` la deja en `ENVIO_INCIERTO` si el envío o la confirmación fallan.
- `$facturaInput` lleva `{date, client_id, client_name, total, tipo_pago, user_id,
  informacion_referencia, items[]}`.
- `user_id` es obligatorio: `facturas.user_id` es `NOT NULL` en la base de Gratex.
- Sin el gancho (como integración), `emitir` se comporta como antes y **no** protege la venta.

**El timeout de 5 s del POS** termina en `EcfEnvioInciertoException`. Esa excepción trae
`facturaId`, `eNcf`, `faseFallida` y `marcado`. La fila ya existe (en `ENVIO_INCIERTO`, o en
`PENDIENTE_ENVIO` si no se pudo marcar), así que el POS puede imprimir el recibo con esa
factura. **No** se emite otra.

## 2. Guardar la clave de idempotencia y el turno en la reserva

- `pos_idempotency_key`, `turno_id` y `pos_empleado_id` tienen que entrar en el **INSERT de la
  reserva**, no en un UPDATE posterior.
- Si van después, una venta reintentada mientras la primera sigue en vuelo no encuentra la
  fila por la clave y gasta otro e-NCF. Eso es justo lo que F5 quiere evitar.
- Hoy `reservarFacturaECF` no escribe esas tres columnas, y en la reserva quedan en
  NULL/0. Hay que extenderla para que acepte esas tres claves opcionales en `$factura` y
  las agregue al INSERT. No hace falta migración.
- Antes de reservar, buscar la venta por `pos_idempotency_key` y, si ya existe, devolver esa
  factura tal cual (F5).

## 3. Una sola fuente de verdad para "envío pendiente"

- `facturas.envio_pendiente` (migración 030) dice lo mismo que `estado_dgii IN
  ('PENDIENTE_ENVIO', 'ENVIO_INCIERTO')`, que el write-ahead ya mantiene y que la app muestra
  en el filtro **Pendientes** y en el indicador **Sin confirmar**.
- Lo mejor es sacar el "envío pendiente" de `estado_dgii` (`EstadosEcf::EN_VUELO`).
- Si el POS necesita la columna, debe escribirla en el **mismo UPDATE** que cambia
  `estado_dgii`, nunca por separado. Dos marcas que se actualizan por separado terminan
  diciendo cosas distintas.

## 4. Reenvío automático (F7): decisión del 2026-10-08

El usuario autorizó que el POS reenvíe solo sus ventas pendientes, en segundo plano (cron o
el propio POS), con estas condiciones:

- **Primero consultar** a la DGII (`consultarEstado` / `consultarEstadoRFCE` o
  `ConsultaTrackIds`). Si el e-CF llegó, se confirma la fila y no se reenvía.
- **Reenviar el XML ya firmado y guardado** (`facturas.xml_firmado` / `rfce_xml`).
  Nunca se vuelve a firmar ni se arma de nuevo: cambiarían la fecha de firma y el código de
  seguridad.
- **Un solo proceso a la vez por factura** (bloqueo), y las mismas reglas de orden del
  write-ahead: primero la fila y después el contador. El número nunca se devuelve mientras
  una fila sea dueña de él.
- **El cajero nunca ve un botón de reenviar.** Para las facturas de la app sigue la
  decisión 7: no se reenvía desde la app, solo soporte.
- Es el mismo mecanismo que el Release 2 del write-ahead deja para soporte
  (`EcfReconciliador`, `tools/ecf_en_vuelo.php`). Conviene construirlo una sola vez y
  usarlo desde los dos lados.

Referencias:
- spec del write-ahead: `docs/superpowers/specs/2026-10-05-ecf-write-ahead-design.md`, en el
  repo del front `fiscalo`;
- resolución manual: `docs/integrations/dgii-ecf.md`, procedimiento 10.2;
- estados: `src/Utils/FacturacionElectronica/EstadosEcf.php`.
