# Notas de crédito y de débito — API de Integración

**Guía rápida para empresas en modo integración.** Cómo emitir una nota de crédito (E34) o de
débito (E33) con `POST /api/integracion/ecf`. Las conexiones, credenciales, formato de la
respuesta y el resto de los campos están en la
[Referencia de endpoints](referencia-endpoints-integracion.md); aquí solo lo que es propio de
las notas.

---

## 1. En resumen

| | |
|---|---|
| **Endpoint** | `POST https://gratex.net/api/integracion/ecf` (el mismo de las facturas) |
| **`tipo_ecf`** | `"33"` Nota de Débito · `"34"` Nota de Crédito |
| **Lo que cambia** | El objeto **`informacion_referencia`** (obligatorio) indica el comprobante que se modifica. En E34, además, `indicador_nota_credito`. El resto es igual que una factura |
| **Antes de enviar** | El comprobante que modifica tiene que estar **aceptado por la DGII** (sección 5) |

---

## 2. Campos propios de las notas

Van en la raíz del JSON, junto a `emisor`, `comprador` e `items`:

| Campo | E33 | E34 | Notas |
|---|---|---|---|
| `tipo_ecf` | `"33"` | `"34"` | |
| `e_ncf` | Sí | Sí | Lo asigna su sistema, del rango que la DGII autorizó **para ese tipo**: `E33` + 10 dígitos (`E330000000003`) o `E34` + 10 dígitos (`E340000000008`) |
| `informacion_referencia` | Sí | Sí | Objeto de la sección 3 |
| `indicador_nota_credito` | No aplica | Sí | `"0"` si la nota se emite dentro de los 30 días calendario siguientes a la fecha del comprobante que modifica, `"1"` si después. **Calcúlelo y envíelo siempre**: en este modo no lo calculamos, y si falta se toma `"0"` |
| `fecha_vencimiento_secuencia` | Sí | **No la envíe** | `DD-MM-AAAA`, la del rango autorizado. El E34 no lleva este campo en su XML |
| `comprador` | Según el caso | Según el caso | Mande el del comprobante que modifica (mismo `rnc` y `razon_social`). Solo puede omitirse en una nota sobre un E32 a consumidor final sin comprador |
| `items` | Sí | Sí | Las líneas que se debitan o acreditan. Mismos campos que una factura (Referencia, sección 6.4) |
| `totales` | No | No | Los calculamos a partir de los ítems. No los envíe |

`fecha_emision` (`DD-MM-AAAA`) es la fecha de la nota. Si no la envía, se usa la del día.

---

## 3. `informacion_referencia`

Los campos van **dentro de este objeto**, no en la raíz del JSON:

```json
"informacion_referencia": {
  "ncf_modificado": "E310000000321",
  "fecha_ncf_modificado": "27-05-2026",
  "codigo_modificacion": "3",
  "razon_modificacion": "Descuento acordado sobre el servicio de consultoría"
}
```

| Campo | Requerido | Notas |
|---|---|---|
| `ncf_modificado` | Sí | e-NCF del comprobante que se modifica (13 caracteres, como `E310000000321`). Le quitamos los espacios |
| `fecha_ncf_modificado` | Sí | Fecha de emisión **de ese comprobante**, `DD-MM-AAAA`. No puede ser posterior a la de la nota |
| `codigo_modificacion` | Sí | `1` Anula el comprobante · `2` Corrige texto · `3` Corrige montos · `4` Reemplazo de un e-NCF emitido en contingencia · `5` Referencia a factura de consumo electrónica |
| `razon_modificacion` | No | Máximo 90 caracteres; si pasa, la DGII rechaza la nota. Si no la envía, ponemos "Nota de credito por ajuste de monto" (E34) o "Nota de debito por ajuste de monto" (E33) |

**No envíe `rnc_otro_contribuyente`** en notas: la DGII responde con error 614.

En este modo nosotros solo comprobamos que vengan `ncf_modificado`, `fecha_ncf_modificado` y
`codigo_modificacion`. El resto (largo del e-NCF, que la fecha sea una fecha real, código del
1 al 5, razón de hasta 90 caracteres) lo valida la DGII: valídelo antes de enviar, porque un
rechazo en certificación puede reiniciar el set de pruebas.

**Fechas siempre con guiones (`04-08-2026`).** Con `/` no hay error: `04/08/2026` se lee como
mes/día y la nota se firma con esa fecha equivocada.

---

## 4. Ejemplos

### Nota de crédito (E34) que corrige montos

La factura `E310000000321` se emitió el 27-05-2026 y la nota el 04-08-2026: pasaron 69 días,
más de 30, así que `indicador_nota_credito` es `"1"`.

```json
{
  "tipo_ecf": "34",
  "e_ncf": "E340000000008",
  "fecha_emision": "04-08-2026",
  "indicador_nota_credito": "1",
  "emisor": {
    "rnc": "131111111",
    "razon_social": "EMPRESA 1 SRL",
    "direccion": "Av. Winston Churchill #45, Piantini",
    "telefono": "809-555-0100"
  },
  "comprador": {
    "rnc": "131222444",
    "razon_social": "EMPRESA COMPRADORA SRL"
  },
  "items": [
    {
      "nombre_item": "Ajuste de precio - Servicio de consultoría",
      "indicador_facturacion": 1,
      "indicador_bien_servicio": 2,
      "cantidad": 1,
      "unidad_medida": "43",
      "precio_unitario": 500.00
    }
  ],
  "informacion_referencia": {
    "ncf_modificado": "E310000000321",
    "fecha_ncf_modificado": "27-05-2026",
    "codigo_modificacion": "3",
    "razon_modificacion": "Descuento acordado sobre el servicio de consultoría"
  }
}
```

### Nota de débito (E33) que corrige montos

Lleva `fecha_vencimiento_secuencia` y no lleva `indicador_nota_credito`.

```json
{
  "tipo_ecf": "33",
  "e_ncf": "E330000000003",
  "fecha_emision": "10-08-2026",
  "fecha_vencimiento_secuencia": "31-12-2028",
  "emisor": {
    "rnc": "131111111",
    "razon_social": "EMPRESA 1 SRL",
    "direccion": "Av. Winston Churchill #45, Piantini",
    "telefono": "809-555-0100"
  },
  "comprador": {
    "rnc": "131222444",
    "razon_social": "EMPRESA COMPRADORA SRL"
  },
  "items": [
    {
      "nombre_item": "Cargo adicional - Servicio de consultoría",
      "indicador_facturacion": 1,
      "indicador_bien_servicio": 2,
      "cantidad": 1,
      "unidad_medida": "43",
      "precio_unitario": 300.00
    }
  ],
  "informacion_referencia": {
    "ncf_modificado": "E310000000321",
    "fecha_ncf_modificado": "27-05-2026",
    "codigo_modificacion": "3",
    "razon_modificacion": "Cargo adicional no facturado"
  }
}
```

### Envío

```bash
curl -X POST https://gratex.net/api/integracion/ecf \
  -H "X-API-KEY: $API_KEY" -H "X-API-SECRET: $API_SECRET" \
  -H "Content-Type: application/json" -d @nota.json
```

La respuesta es la misma que la de una factura (Referencia, sección 6.9): guarde `xml_firmado`,
`track_id`, `codigo_seguridad` y `fecha_emision`, y consulte `GET /api/integracion/estado`
hasta tener un estado final. `"status": true` no significa que la DGII aceptó la nota.

---

## 5. El comprobante original tiene que estar aceptado

La DGII revisa que el comprobante que la nota modifica exista y esté aceptado. Si no, rechaza la
nota con **"El eNCF modificado no ha sido emitido"** (614). Pasa cuando:

- la nota se envió antes de que la DGII terminara de procesar el original;
- el original fue rechazado.

Antes de emitir la nota, consulte el estado del original con `GET /api/integracion/estado` y
espere `ACEPTADO` o `ACEPTADO_CONDICIONAL` (`RFCE_ACEPTADO` si es un E32 de menos de
RD$250,000). **En el set de pruebas de certificación** esto pesa más: cualquier rechazo reinicia
el set y los originales ya aceptados dejan de contar, así que envíe las notas solo cuando todos
sus originales sigan aceptados.

En el ambiente `sandbox` nada sale hacia la DGII y esta revisión no ocurre.

---

## 6. Errores comunes

| Qué ve | Causa | Qué hacer |
|---|---|---|
| `502` — *"InformacionReferencia.ncf_modificado es requerido para e-CF tipo 34."* (o `fecha_ncf_modificado`, `codigo_modificacion`) | Falta el objeto, o puso los campos en la raíz del JSON | Póngalos dentro de `informacion_referencia`. La nota no llegó a la DGII: reenvíe con el **mismo** `e_ncf` |
| `502` — *"Fecha invalida"* | Una fecha no se puede leer como fecha (por ejemplo, texto) | Use `DD-MM-AAAA` con guiones. Ojo: con `/` no da error, se lee como mes/día |
| `502` — `e_ncf` que no corresponde al `tipo_ecf` | Mandó un e-NCF `E31…` en una nota | Use `E33…` para `tipo_ecf` `"33"` y `E34…` para `"34"` |
| DGII: *"El eNCF modificado no ha sido emitido"* (614) | El original no está aceptado todavía, fue rechazado, o se envió `rnc_otro_contribuyente` | Sección 5; no envíe `rnc_otro_contribuyente` |
| DGII: *"Fecha de vencimiento de secuencia inválida"* (145) | En E33, la fecha no es la del rango autorizado | Envíe la fecha real de su autorización. Sin ella usamos el `31-12-2028` |
| La DGII rechaza la nota por montos | Envió `totales` y no cuadran con los `items` | No los envíe: se calculan de los `items` |

Ante un `502` o un rechazo, siga las reglas de reintento de la Referencia (sección 6.10):
reenvíe con el **mismo** `e_ncf` cuando el error es de un dato del cuerpo, y nunca salte al
siguiente número si el envío pudo haber llegado a la DGII.

---

## 7. Representación Impresa de la nota

Lo que la DGII pide en el impreso de una nota, además de lo de cualquier e-CF
([Guía de Integración](guia-cliente-integracion.md), sección 7):

- **E33 y E34:** el **NCF Modificado** (con su fecha) **al lado del e-NCF** de la nota, no aparte.
- **E34:** sin fecha de vencimiento. El E33 la muestra, porque su XML la trae.
- Dirección y teléfono reales del emisor, y el código de seguridad y la fecha de firma debajo del QR.

---

## Lista de verificación

- [ ] El comprobante que modifica está `ACEPTADO` en la DGII.
- [ ] `tipo_ecf` `"33"` o `"34"`, y el `e_ncf` es del tipo y del rango autorizado.
- [ ] `informacion_referencia` trae los tres campos obligatorios, **dentro** del objeto.
- [ ] `fecha_ncf_modificado` es la fecha del comprobante modificado, en `DD-MM-AAAA`.
- [ ] E34: `indicador_nota_credito` calculado con las dos fechas (`"1"` si pasaron más de 30 días).
- [ ] E33: `fecha_vencimiento_secuencia` del rango autorizado. E34: no se envía.
- [ ] No se envían `totales` ni `rnc_otro_contribuyente`.
- [ ] Se guardó el `xml_firmado` sin modificar.
