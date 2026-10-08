# Referencia de endpoints — API de Integración e-CF

**Documento para el equipo técnico del cliente.** Describe cada endpoint del API de
integración: qué recibe, qué devuelve y qué errores puede dar. El proceso completo (alta,
portal de la DGII, Representación Impresa, certificación) está en la *Guía de Integración e-CF*.

---

## 1. Conexión

| | |
|---|---|
| **Servidor** | `https://gratex.net` |
| **Rutas** | Todas empiezan con `/api/integracion/`. La URL completa es el servidor seguido de la ruta, por ejemplo `https://gratex.net/api/integracion/ecf`. No repita `/api/` |
| **Formato** | JSON en UTF-8, en el cuerpo y en la respuesta |
| **Protocolo** | Solo HTTPS |

Cada llamada lleva las dos credenciales en los headers:

```
X-API-KEY: <api_key>
X-API-SECRET: <api_secret>
Content-Type: application/json
```

Un solo par de credenciales sirve para **todas sus empresas** (sección 2).

> **Las llamadas salen solo de su servidor.** Nunca desde el navegador, una aplicación web
> del lado del cliente ni una app móvil: todo lo que llega al dispositivo del usuario se puede
> leer. Con el API SECRET se pueden emitir comprobantes con valor fiscal firmados por sus
> empresas. Guárdelo en el gestor de secretos de su sistema y, si sospecha que se filtró,
> avísenos de inmediato a info@gratex.net para revocarlo y entregarle uno nuevo.

---

## 2. Elegir la empresa

Cuando su credencial cubre varias empresas, cada llamada indica de cuál es por su RNC:

| Endpoint | Dónde va el RNC |
|---|---|
| `POST /ecf` | `emisor.rnc` en el cuerpo (obligatorio) |
| `POST /aprobacion-comercial` | `rnc_comprador` en el cuerpo |
| `GET /estado`, `GET /xml`, `GET /recibidos`, `GET /aprobaciones` | `?rnc=<RNC>` en la URL |
| `GET /empresas` | No aplica: lista todas |

Si no manda el RNC donde es opcional, la llamada va a la empresa dueña de la credencial.
**Mándelo siempre.** Un RNC que no sea de una de sus empresas responde `422`.

---

## 3. Respuestas y errores

Todas las respuestas son JSON con `"status": true` o `"status": false`. Cuando es `false`,
el motivo viene en `"error"`:

```json
{ "status": false, "error": "Falta e_ncf en el JSON (el cliente asigna la secuencia)." }
```

| HTTP | Cuándo | Qué hacer |
|---|---|---|
| `200` | La llamada se procesó. En emisión y aprobación, **revise el resultado de la DGII** dentro de `data`: un `200` no significa que la DGII haya aceptado | Ver cada endpoint |
| `400` | El cuerpo no es un JSON válido | Revise el cuerpo |
| `401` | Faltan las credenciales, no son válidas, o la ruta no es de `/api/integracion/` | Verifique los headers y la URL |
| `403` | Su credencial no es de integración | Contáctenos |
| `404` | La ruta no existe, o no hay registro del comprobante pedido (ver `/estado` y `/xml`) | Revise la URL, el `e_ncf` y el `rnc` |
| `405` | Método HTTP incorrecto | `POST` para emitir y aprobar; `GET` para todo lo demás |
| `422` | Falta un dato obligatorio o tiene un valor no permitido, o el RNC no es de una de sus empresas | Lea `error`, corrija y repita |
| `502` | No se pudo completar la operación: un dato del cuerpo no pasó la validación, o falló la comunicación con la DGII | Lea `error` y vea cada endpoint |

Si recibe un `404` cuyo cuerpo **no es JSON**, la URL está mal formada (por ejemplo, con
`/api/` repetido).

---

## 4. Resumen

| Método | Ruta | Para qué |
|---|---|---|
| `GET` | `/api/integracion/empresas` | Empresas que cubre su credencial |
| `POST` | `/api/integracion/ecf` | Emitir un e-CF |
| `GET` | `/api/integracion/estado` | Estado en la DGII de un e-CF que emitió |
| `GET` | `/api/integracion/xml` | Volver a bajar el XML firmado de un e-CF que emitió |
| `GET` | `/api/integracion/recibidos` | e-CF que otros contribuyentes le emitieron a sus empresas |
| `GET` | `/api/integracion/aprobaciones` | Aceptaciones o rechazos que sus clientes enviaron sobre sus facturas |
| `POST` | `/api/integracion/aprobacion-comercial` | Aceptar o rechazar un e-CF que le emitieron |

Además, si nos da una URL de webhook, nosotros le enviamos avisos (sección 12).

---

## 5. `GET /api/integracion/empresas`

Lista las empresas que cubre su credencial y el ambiente en que está cada una.

```bash
curl https://gratex.net/api/integracion/empresas -H "X-API-KEY: $API_KEY" -H "X-API-SECRET: $API_SECRET"
```

```json
{
  "status": true,
  "recurso": "empresas",
  "data": [
    { "id": 7, "nombre": "Empresa 1 SRL", "rnc": "131111111", "tipo": "integracion", "ambiente": "ecf" },
    { "id": 8, "nombre": "Empresa 2 SRL", "rnc": "131111112", "tipo": "integracion", "ambiente": "certecf" }
  ]
}
```

| Campo | Notas |
|---|---|
| `rnc` | Úselo para elegir la empresa en las demás llamadas |
| `ambiente` | `certecf` = certificación (sin efecto fiscal) · `ecf` = producción |
| `id`, `tipo` | Datos internos; no los necesita |

---

## 6. `POST /api/integracion/ecf` — Emitir

Recibe la factura en JSON, arma el XML e-CF, lo firma con el certificado de la empresa
emisora y lo envía a la DGII. El ambiente (certificación o producción) lo ponemos nosotros
según la empresa: **no envíe `ambiente`**.

### 6.1 Cuerpo

| Campo | Requerido | Notas |
|---|---|---|
| `tipo_ecf` | Sí | `31`, `32`, `33`, `34`, `41`, `43`, `44`, `45`, `46` o `47` (anexo) |
| `e_ncf` | Sí | Lo asigna su sistema: `E` + tipo + 10 dígitos (`E310000000001`), dentro del rango que la DGII autorizó a esa empresa para ese tipo. No repita ni salte números |
| `fecha_emision` | No | Fecha del comprobante, `DD-MM-AAAA`. Si no la envía, se usa la del día |
| `fecha_vencimiento_secuencia` | Sí, salvo E32 y E34 | `DD-MM-AAAA`. La fecha de vencimiento de la autorización de la DGII para ese rango. Debe coincidir: si no, la DGII rechaza con *"Fecha de vencimiento de secuencia inválida"*. El rango de producción puede vencer en otra fecha que el de certificación |
| `tipo_pago` | No | `1` Contado (por defecto) · `2` Crédito · `3` Gratuito |
| `fecha_limite_pago` | No | Solo con `tipo_pago` `2`. `DD-MM-AAAA`, igual o posterior a la emisión. Si no la envía: emisión + 30 días |
| `tipo_ingresos` | No | `01` por defecto (anexo) |
| `indicador_nota_credito` | Solo E34 | `"0"` si la nota se emite dentro de los 30 días siguientes a la factura que modifica, `"1"` si después. Si no lo envía se toma `"0"`: calcúlelo y envíelo siempre |
| `emisor` | Sí | Objeto (6.2) |
| `comprador` | Según el tipo | Objeto (6.3) |
| `items` | Sí | Arreglo con al menos una línea (6.4) |
| `informacion_referencia` | Solo E33 y E34 | Objeto (6.5) |
| `totales` | No | Solo en casos especiales (6.6) |

**Formatos.** Fechas siempre `DD-MM-AAAA` con guiones: **no use `/`**, porque `04/08/2026`
se interpreta como mes/día y el comprobante se firma con esa fecha sin dar error. Montos y
cantidades como número con punto decimal (`1500.00`). En los textos use solo `\n` para los
saltos de línea.

### 6.2 `emisor`

La empresa que factura. Va completo en cada llamada.

| Campo | Requerido | Notas |
|---|---|---|
| `rnc` | Sí | Debe ser una de sus empresas. Elige el certificado que firma y el ambiente |
| `razon_social` | Sí | Como aparece en la DGII |
| `direccion` | Sí | Dirección fiscal |
| `nombre_comercial` | No | |
| `municipio`, `provincia` | No | Códigos DGII de 6 dígitos (ej. `010100`, `010000`) |
| `telefono` | No | Ej. `809-555-0100` |
| `correo` | No | |

También acepta, opcionales: `sucursal`, `website`, `actividad_economica`, `codigo_vendedor`,
`numero_factura_interna`, `numero_pedido_interno`, `zona_venta`, `ruta_venta`,
`informacion_adicional`.

### 6.3 `comprador`

| Campo | Notas |
|---|---|
| `rnc` | RNC (9 dígitos) o cédula (11). **Obligatorio en E31, E41 y E45**, y en E32 de RD$250,000 o más |
| `identificador_extranjero` | En lugar de `rnc`, para compradores del exterior (E46, E47) |
| `razon_social` | Nombre o razón social |
| `correo`, `direccion`, `contacto` | Opcionales |
| `municipio`, `provincia` | Opcionales, códigos DGII de 6 dígitos |

También acepta, opcionales: `fecha_entrega`, `contacto_entrega`, `direccion_entrega`,
`telefono_adicional`, `fecha_orden_compra`, `numero_orden_compra`, `codigo_interno`,
`responsable_pago`, `informacion_adicional`.

E43 no lleva comprador. En E32 de menos de RD$250,000 es opcional.

### 6.4 `items`

| Campo | Requerido | Notas |
|---|---|---|
| `nombre_item` | Sí | **Máximo 80 caracteres.** Si pasa de 80 lo cortamos; si además no envió `descripcion`, el nombre completo pasa a la descripción |
| `descripcion` | No | Máximo 1000 caracteres; lo que sobra no sale en el comprobante |
| `cantidad` | No | Por defecto `1`. Hasta 2 decimales |
| `precio_unitario` | Sí | Sin ITBIS. Hasta 4 decimales |
| `indicador_facturacion` | No | ITBIS de la línea: `1` 18 % (por defecto) · `2` 16 % · `3` tasa cero · `4` exento |
| `indicador_bien_servicio` | No | `1` Bien · `2` Servicio (por defecto) |
| `unidad_medida` | No | Código DGII, por defecto `43` (Unidad). Anexo |
| `descuento_monto` | No | Descuento de la línea en pesos. Se resta antes de calcular el ITBIS |
| `numero_linea` | No | Por defecto, el orden en el arreglo |

El monto de la línea es `cantidad × precio_unitario − descuento_monto`, y el ITBIS se calcula
sobre ese neto. La DGII cuenta caracteres, no bytes: tildes y `ñ` cuentan como uno.

### 6.5 `informacion_referencia` (E33 y E34)

Las notas de débito (E33) y de crédito (E34) indican el comprobante que modifican. Los campos
van **dentro de este objeto**, no en la raíz del JSON:

```json
"informacion_referencia": {
  "ncf_modificado": "E310000000321",
  "fecha_ncf_modificado": "27-05-2026",
  "codigo_modificacion": "3",
  "razon_modificacion": "Ajuste de precio"
}
```

| Campo | Requerido | Notas |
|---|---|---|
| `ncf_modificado` | Sí | e-NCF del comprobante que se modifica |
| `fecha_ncf_modificado` | Sí | Fecha de emisión de ese comprobante, `DD-MM-AAAA` |
| `codigo_modificacion` | Sí | `1` Anula · `2` Corrige texto · `3` Corrige montos · `4` Reemplazo de contingencia · `5` Referencia a factura de consumo |
| `razon_modificacion` | No | Máximo 90 caracteres. Si no la envía, ponemos una genérica |

### 6.6 `totales`

**Los totales los calculamos nosotros** a partir de los ítems: monto gravado por tasa, ITBIS,
exento y total. En una factura normal **no envíe `totales`**. Las tasas de ITBIS son fijas
(18 %, 16 % o 0 % según el `indicador_facturacion`) y no se cambian desde aquí.

Envíelo solo cuando el comprobante lleve montos que no salen de los ítems, como las
retenciones de un E41 (`total_itbis_retenido`, `total_isr_retencion`). Cada campo que envíe
reemplaza tal cual al calculado, sin recalcular los demás: si no cuadra con los ítems, la DGII
rechaza el comprobante.

### 6.7 Requisitos por tipo

E31, E32, E33, E34 y E45 siguen el formato del ejemplo y en ellos puede mezclar indicadores
de ITBIS. Los demás tienen reglas propias:

| Tipo | `indicador_facturacion` | Otros requisitos |
|---|---|---|
| `41` Compras | `1`, `2`, `3` o `4` | `comprador` (su proveedor) con `rnc`. Si hay retención, cada ítem lleva `indicador_agente_retencion_percepcion: "1"` y `monto_itbis_retenido` y/o `monto_isr_retenido`, y la suma va en `totales` (6.6) |
| `43` Gastos Menores | Solo `4` | Sin comprador |
| `44` Regímenes Especiales | Solo `4` | |
| `46` Exportaciones | Solo `3` | Comprador del exterior con `identificador_extranjero` y `razon_social` |
| `47` Pagos al Exterior | Solo `4` | Solo servicios. Si un ítem no trae `monto_isr_retenido`, aplicamos una retención de ISR del 27 % sobre su monto. El beneficiario va en `comprador.identificador_extranjero` |

Antes de integrar un tipo distinto de E31 o E32, pídanos un ejemplo de ese tipo.

### 6.8 Ejemplo — Factura de Crédito Fiscal (E31)

```json
{
  "tipo_ecf": "31",
  "e_ncf": "E310000000001",
  "fecha_emision": "04-08-2026",
  "fecha_vencimiento_secuencia": "31-12-2027",
  "tipo_pago": 1,
  "emisor": {
    "rnc": "131111111",
    "razon_social": "EMPRESA 1 SRL",
    "nombre_comercial": "Empresa 1",
    "direccion": "Av. Winston Churchill #45, Piantini",
    "municipio": "010100",
    "provincia": "010000",
    "telefono": "809-555-0100",
    "correo": "facturacion@empresa1.com"
  },
  "comprador": {
    "rnc": "131222444",
    "razon_social": "EMPRESA COMPRADORA SRL",
    "correo": "cuentas@compradora.com"
  },
  "items": [
    {
      "nombre_item": "Servicio de consultoría",
      "descripcion": "Consultoría técnica correspondiente al mes de agosto 2026",
      "indicador_facturacion": 1,
      "indicador_bien_servicio": 2,
      "cantidad": 5,
      "unidad_medida": "43",
      "precio_unitario": 1500.00
    }
  ]
}
```

```bash
curl -X POST https://gratex.net/api/integracion/ecf -H "X-API-KEY: $API_KEY" -H "X-API-SECRET: $API_SECRET" -H "Content-Type: application/json" -d @factura.json
```

### 6.9 Respuesta

```json
{
  "status": true,
  "data": {
    "e_ncf": "E310000000001",
    "tipo_ecf": "31",
    "estado": "ENVIADO",
    "track_id": "a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d",
    "codigo_seguridad": "Ab3+x9",
    "ambiente": "ecf",
    "fecha_emision": "2026-08-04 11:52:22",
    "xml_firmado": "<?xml version=\"1.0\" encoding=\"UTF-8\"?>...",
    "dgii_response": { "trackId": "a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d" }
  }
}
```

| Campo | Notas |
|---|---|
| `estado` | Lo que respondió la DGII al recibirlo (ver abajo) |
| `track_id` | Identificador de la DGII. Con él se consulta el estado |
| `codigo_seguridad` | 6 caracteres. Va en la Representación Impresa y en su QR |
| `ambiente` | `ecf` = producción · `certecf` = certificación |
| `fecha_emision` | **Fecha y hora de firma**, `AAAA-MM-DD HH:MM:SS`. En la Representación Impresa va como `DD-MM-AAAA HH:MM:SS`, con la misma hora exacta. No es la fecha del comprobante que usted envió |
| `xml_firmado` | El comprobante con valor legal. Guárdelo **sin modificarlo**: cualquier cambio invalida la firma |
| `dgii_response` | Respuesta de la DGII tal cual. Si la DGII lo rechazó al recibirlo, el motivo viene aquí |

**Guarde siempre** `xml_firmado`, `track_id`, `codigo_seguridad` y `fecha_emision`.

**`"status": true` significa que firmamos el comprobante y lo enviamos a la DGII; no que la
DGII lo aceptó.** Lea `estado`:

| `estado` | Significado | Qué hacer |
|---|---|---|
| `ENVIADO` | La DGII lo recibió y lo está validando. Es la respuesta normal | Consulte `GET /estado` hasta tener un estado final |
| `ACEPTADO`, `ACEPTADO_CONDICIONAL` | Aceptado | Final |
| `RECHAZADO` | Rechazado al recibirlo | Motivo en `dgii_response` |
| `EN_PROCESO` | La DGII todavía lo procesa | Consulte `GET /estado` más tarde |
| `ERROR` | La DGII respondió un error sin veredicto | Consulte `GET /estado` antes de reintentar |
| `RFCE_...` | Factura de consumo E32 de menos de RD$250,000 (abajo) | |

**E32 de menos de RD$250,000.** La DGII recibe un resumen del comprobante y no genera
`track_id`. La respuesta trae `"track_id": null`, `"dgii_response": null` y el `estado` con
el prefijo `RFCE_`: lo normal es `RFCE_ACEPTADO`; también puede ser
`RFCE_ACEPTADO_CONDICIONAL`, `RFCE_RECHAZADO` u otro. Si es `RFCE_RECHAZADO`, el motivo se ve
con `GET /estado` usando el `codigo_seguridad`.

No dé por válido un comprobante hasta tener `ACEPTADO`, `ACEPTADO_CONDICIONAL`,
`RFCE_ACEPTADO` o `RFCE_ACEPTADO_CONDICIONAL`.

### 6.10 Errores

| HTTP | Caso |
|---|---|
| `422` | Falta `emisor.rnc` o `e_ncf`, o `emisor.rnc` no es de una de sus empresas |
| `502` | Un dato del cuerpo no pasó la validación (por ejemplo: `tipo_ecf` inválido; `emisor` sin `razon_social` o `direccion`; `e_ncf` que no corresponde al `tipo_ecf`; E31 sin RNC del comprador; nota sin `informacion_referencia`; fecha inválida), o falló la comunicación con la DGII |

**Ante un `502`, lea `error`:**

- Si señala un dato del cuerpo, el comprobante no llegó a la DGII: corrija y reenvíe con el
  **mismo** `e_ncf`.
- Si es una falla de comunicación con la DGII, el comprobante pudo haber llegado. Reenvíe con
  el **mismo** `e_ncf`, nunca con otro número. Si la DGII lo rechaza porque el e-NCF ya fue
  utilizado, el primer envío sí llegó: **no lo emita con el siguiente número**, porque
  duplicaría la factura. Escríbanos con el RNC y el `e_ncf`.

Un comprobante `RECHAZADO` se corrige y se reenvía; revise antes el mensaje de la DGII. Si el
rechazo no consumió la secuencia (`secuenciaUtilizada: false`), puede reutilizar el mismo
`e_ncf`; si la consumió, use el siguiente.

---

## 7. `GET /api/integracion/estado` — Estado en la DGII

Consulta en ese momento a la DGII el estado de un comprobante que emitió.

```bash
curl "https://gratex.net/api/integracion/estado?e_ncf=E310000000001&track_id=$TRACK_ID&rnc=131111111" -H "X-API-KEY: $API_KEY" -H "X-API-SECRET: $API_SECRET"
```

| Parámetro | Requerido | Notas |
|---|---|---|
| `e_ncf` | Sí | |
| `track_id` | Recomendado | El que recibió al emitir. Mándelo siempre que lo tenga |
| `codigo_seguridad` | Solo E32 < RD$250,000 | Esos comprobantes no tienen `track_id` |
| `rnc` | Recomendado | La empresa emisora |

Si no manda `track_id` ni `codigo_seguridad`, usamos el `track_id` del **último envío** de
ese e-NCF que tengamos para esa empresa, y la respuesta trae el `track_id` usado:
compárelo con el suyo. Ese último envío puede ser un reenvío rechazado, o uno de la
certificación si el mismo número se usó en las pruebas.

```json
{
  "status": true,
  "recurso": "estado",
  "rnc": "131111111",
  "empresa": "Empresa 1 SRL",
  "e_ncf": "E310000000001",
  "track_id": "a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d",
  "flujo": "ECF",
  "ambiente": "ecf",
  "estado": "RECHAZADO",
  "fecha_emision": null,
  "consulta": {
    "trackId": "a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d",
    "codigo": "2", "estado": "Rechazado",
    "rnc": "131111111", "encf": "E310000000001",
    "secuenciaUtilizada": false,
    "mensajes": [ { "valor": "El campo MontoGravadoI1 ... no coincide con la sumatoria ...", "codigo": 0 } ]
  }
}
```

| Campo | Notas |
|---|---|
| `estado` | `ACEPTADO`, `ACEPTADO_CONDICIONAL`, `EN_PROCESO`, `RECHAZADO` o `NO_ENCONTRADO`; con prefijo `RFCE_` si consultó por `codigo_seguridad`. Puede venir `null` si la DGII no devolvió un estado reconocible: no lo trate como aceptado ni como rechazado, consulte más tarde |
| `flujo` | `ECF` (por `track_id`) o `RFCE` (por `codigo_seguridad`) |
| `consulta` | La respuesta de la DGII tal cual. El motivo de un rechazo está en `consulta.mensajes` |
| `fecha_emision` | Hora en que guardamos nuestra copia, solo cuando buscamos el `track_id` por usted. No es la fecha de firma |

**Úselo con moderación.** Cada llamada consulta a la DGII en ese momento:

- consulte solo los comprobantes que sigan en `ENVIADO`, `EN_PROCESO` o `ERROR`;
- deje al menos 1 minuto entre dos consultas del mismo comprobante (cada 2 a 5 minutos es
  suficiente);
- deje de consultarlo cuando reciba un estado final (`ACEPTADO`, `ACEPTADO_CONDICIONAL` o
  `RECHAZADO`);
- nunca consulte en un bucle sin pausa.

| HTTP | Caso |
|---|---|
| `404` | Sin `track_id` ni `codigo_seguridad`: no tenemos registro de ese e-NCF para esa empresa, o es un E32 < RD$250,000 (mande `codigo_seguridad`) |
| `422` | Falta `e_ncf`, o el `rnc` no es de una de sus empresas |
| `502` | No se pudo consultar a la DGII. Reintente más tarde |

---

## 8. `GET /api/integracion/xml` — XML firmado

Si su sistema perdió el `xml_firmado` de una emisión, puede pedirlo de nuevo. Devolvemos el del
**último envío** de ese e-NCF que tengamos para esa empresa, tal cual se firmó.

```bash
curl "https://gratex.net/api/integracion/xml?e_ncf=E320000000012&rnc=131111111" -H "X-API-KEY: $API_KEY" -H "X-API-SECRET: $API_SECRET"
```

| Parámetro | Requerido | Notas |
|---|---|---|
| `e_ncf` | Sí | `E` + tipo + 10 dígitos |
| `rnc` | Recomendado | La empresa emisora |

```json
{
  "status": true,
  "recurso": "xml",
  "rnc": "131111111",
  "empresa": "Empresa 1 SRL",
  "e_ncf": "E320000000012",
  "tipo_ecf": "32",
  "track_id": null,
  "flujo": "RFCE",
  "codigo_seguridad": "Qw7/Lp",
  "fecha_emision": "2026-10-06 10:40:01",
  "archivo": "E320000000012.xml",
  "xml_firmado": "<?xml version=\"1.0\" encoding=\"UTF-8\"?>..."
}
```

- `fecha_emision` aquí es la hora en que guardamos la copia, **no la fecha de firma**. Para
  reimprimir, tome la fecha de firma del elemento `<FechaHoraFirma>` del `xml_firmado` (ya
  viene como `DD-MM-AAAA HH:MM:SS`).
- El último envío puede ser uno rechazado, o uno de la certificación. Antes de guardarlo como
  su comprobante, confírmelo con `GET /estado` usando el `track_id` de esta respuesta o, si
  `flujo` es `RFCE`, el `codigo_seguridad`.
- Es un respaldo: la copia oficial es la que usted guarda al emitir.

| HTTP | Caso |
|---|---|
| `404` | No hay ningún envío de ese e-NCF para esa empresa |
| `422` | `e_ncf` ausente o mal formado, o el `rnc` no es de una de sus empresas |

---

## 9. `GET /api/integracion/recibidos` — Facturas recibidas

Los e-CF que otros contribuyentes le emitieron a una de sus empresas, del más reciente al más
antiguo.

```bash
curl "https://gratex.net/api/integracion/recibidos?rnc=131111111&page=1&pageSize=20" -H "X-API-KEY: $API_KEY" -H "X-API-SECRET: $API_SECRET"
```

| Parámetro | Requerido | Notas |
|---|---|---|
| `rnc` | Recomendado | La empresa receptora |
| `page` | No | Por defecto `1` |
| `pageSize` | No | Por defecto `20`, máximo `100` |

```json
{
  "status": true,
  "recurso": "recibidos",
  "rnc": "131111111",
  "empresa": "Empresa 1 SRL",
  "data": [
    {
      "id": 1,
      "track_id": "...",
      "tipo_ecf": "31",
      "e_ncf": "E310000000028",
      "rnc_emisor": "131222333",
      "razon_social_emisor": "PROVEEDOR SRL",
      "rnc_comprador": "131111111",
      "monto_total": "6608.00",
      "fecha_emision": "2026-08-01",
      "fecha_recepcion": "2026-08-01 14:03:10",
      "estado": "ACEPTADO",
      "codigo_resultado": 1,
      "validacion_firma": "OK",
      "ambiente": "ecf",
      "firma_rnc": "131222333",
      "firma_subject": "...",
      "aprobacion_comercial": null,
      "aprobacion_comercial_estado_dgii": null
    }
  ],
  "pagination": { "page": 1, "pageSize": 20, "total": 1, "totalPages": 1 }
}
```

| Campo | Notas |
|---|---|
| `estado`, `validacion_firma` | Resultado de nuestra validación técnica al recibirlo (estructura y firma digital). Los documentos que no la pasan se rechazan al llegar y no aparecen aquí, por eso verá `ACEPTADO` y `OK`. **No significa que la DGII haya aceptado el comprobante** |
| `aprobacion_comercial` | La última decisión comercial que usted envió (`ACEPTADO` o `RECHAZADO`); `null` = todavía no ha enviado ninguna. Se llena aunque la DGII no haya procesado su envío: para saber si lo procesó, use la respuesta de `POST /aprobacion-comercial` |
| `firma_rnc`, `firma_subject` | RNC y titular del certificado con que se firmó el documento. Compare `firma_rnc` con `rnc_emisor` |
| `fecha_emision` | Fecha del comprobante, `AAAA-MM-DD` |
| `ambiente` | Solo verá los del ambiente en que está la empresa: al pasar a producción dejan de aparecer los de certificación |

Antes de registrar una factura recibida en su contabilidad, confirme en la DGII que el
comprobante existe y fue aceptado, por ejemplo con el código QR de la Representación Impresa
que le entrega su proveedor.

---

## 10. `GET /api/integracion/aprobaciones` — Respuestas de sus clientes

Las aceptaciones o rechazos comerciales que sus clientes enviaron sobre las facturas que sus
empresas emitieron. Mismos parámetros y paginación que `/recibidos`; las filas tienen otra
forma:

```json
{
  "status": true,
  "recurso": "aprobaciones",
  "rnc": "131111111",
  "empresa": "Empresa 1 SRL",
  "data": [
    {
      "id": 1,
      "e_ncf": "E310000000001",
      "rnc_emisor": "131111111",
      "rnc_comprador": "131222444",
      "estado_comercial": "RECHAZADO",
      "detalle_motivo": "Precio distinto al acordado",
      "validacion_firma": "OK",
      "ambiente": "ecf",
      "fecha_recepcion": "2026-08-05 09:12:44"
    }
  ],
  "pagination": { "page": 1, "pageSize": 20, "total": 1, "totalPages": 1 }
}
```

| Campo | Notas |
|---|---|
| `e_ncf` | Su factura. Relacione la fila con ella por este campo |
| `rnc_comprador` | Su cliente, el que aceptó o rechazó |
| `estado_comercial` | `ACEPTADO`, `ACEPTADO_CONDICIONAL` o `RECHAZADO` |
| `detalle_motivo` | Motivo, cuando el cliente lo envió; si no, `null` |

Tómelas como un aviso: antes de anular una venta o emitir una nota de crédito por un rechazo,
confírmelo con su cliente.

---

## 11. `POST /api/integracion/aprobacion-comercial` — Aceptar o rechazar

Envía a la DGII la aceptación o el rechazo comercial de un e-CF que le emitieron a una de sus
empresas. Va al mismo ambiente en que se recibió la factura.

```json
{
  "rnc_comprador": "131111111",
  "rnc_emisor": "131222333",
  "e_ncf": "E310000000028",
  "fecha_emision": "01-08-2026",
  "monto_total": 6608.00,
  "estado": "2",
  "detalle_motivo": "Mercancía no recibida"
}
```

| Campo | Requerido | Notas |
|---|---|---|
| `rnc_comprador` | Recomendado | Cuál de sus empresas recibió la factura. Sin él, la dueña de la credencial |
| `rnc_emisor` | Sí | Quien le facturó |
| `e_ncf` | Sí | e-NCF del comprobante recibido |
| `fecha_emision` | Sí | Fecha del comprobante recibido, `DD-MM-AAAA`. También acepta tal cual el `fecha_emision` de `/recibidos` (`AAAA-MM-DD`) |
| `monto_total` | Sí | Monto total del comprobante, tal cual aparece en `/recibidos` |
| `estado` | Sí | `1` Aceptado · `2` Rechazado |
| `detalle_motivo` | Si `estado` es `2` | Motivo del rechazo |

No envíe otros campos; en particular, no envíe `ambiente`.

```json
{
  "status": true,
  "data": {
    "rnc_emisor": "131222333",
    "e_ncf": "E310000000028",
    "estado_aprobacion": "2",
    "track_id": null,
    "estado_dgii": "ACEPTADO",
    "codigo_seguridad": "...",
    "ambiente": "ecf",
    "fecha_envio": "2026-08-05 10:20:31",
    "dgii_response": { "codigo": "1", "estado": "...", "mensaje": "..." }
  }
}
```

**Cómo saber si la DGII registró su decisión:**

| Respuesta | Significado | Qué hacer |
|---|---|---|
| HTTP `200` y `dgii_response.codigo` `1` o `01` | La DGII **procesó** su aceptación o rechazo | Nada |
| HTTP `200` y `dgii_response.codigo` `2` o `02` | **No la procesó** (factura no encontrada, error técnico o ambiente que no corresponde) | Revise los datos y reintente |
| HTTP `502` | **No hay confirmación de la DGII.** No viene `dgii_response`; el motivo está en `error`, con la respuesta de la DGII si la hubo | Reintente; si persiste, contáctenos |

`estado_aprobacion` es la decisión que usted envió. `estado_dgii` indica si la DGII **aceptó
procesar el envío**, no cuál fue su decisión: un rechazo comercial registrado correctamente
sale `estado_dgii: "ACEPTADO"`. Lo mismo con un `dgii_response.estado` que diga *"Aprobacion
Comercial Rechazada."*: significa que la DGII rechazó procesar su envío, no que su rechazo
haya quedado registrado. Guarde en su sistema el resultado de cada envío.

| HTTP | Caso |
|---|---|
| `422` | Falta un campo obligatorio; `estado` no es `1` ni `2`; `estado` `2` sin `detalle_motivo`; o `rnc_comprador` no es de una de sus empresas |
| `502` | Ver la tabla anterior |

---

## 12. Webhook (opcional) — Avisos que le enviamos

Si nos da una URL, le enviamos un `POST` cada vez que llega un documento para una de sus
empresas. La URL puede ser la misma para todas; indíquenos a cuáles aplica. Junto con sus
credenciales le entregamos, por canal seguro, el **WEBHOOK SECRET** de cada empresa con
webhook. Es distinto del API SECRET y solo sirve para verificar los avisos.

### Eventos

`ecf.recibido` — un contribuyente le emitió un e-CF a una de sus empresas:

```json
{
  "event": "ecf.recibido",
  "tenant_id": 7,
  "data": {
    "track_id": "...", "tipo_ecf": "31", "e_ncf": "E310000000028",
    "rnc_emisor": "131222333", "razon_social_emisor": "PROVEEDOR SRL",
    "rnc_comprador": "131111111",
    "monto_total": 6608.0, "fecha_emision": "2026-08-01", "estado": "ACEPTADO"
  },
  "sent_at": "2026-08-01T14:03:11-04:00"
}
```

`aprobacion.recibida` — un cliente aceptó o rechazó una factura de una de sus empresas:

```json
{
  "event": "aprobacion.recibida",
  "tenant_id": 7,
  "data": {
    "e_ncf": "E310000000001", "rnc_emisor": "131111111", "rnc_comprador": "131222444",
    "estado_comercial": "RECHAZADO", "detalle_motivo": "Precio distinto al acordado"
  },
  "sent_at": "2026-08-05T09:12:45-04:00"
}
```

**A qué empresa corresponde:** en `ecf.recibido` es `data.rnc_comprador`; en
`aprobacion.recibida`, `data.rnc_emisor`. No use `tenant_id`: es un identificador interno.

### Verificar la firma

Cada aviso trae el header:

```
X-Gratex-Signature: sha256=<HMAC-SHA256 en hexadecimal (minúsculas) del cuerpo>
```

Calcule el HMAC con el WEBHOOK SECRET de la empresa del aviso, sobre el **cuerpo tal como
llegó** (no sobre el JSON re-serializado), y compárelo en tiempo constante. **Rechace (401)
todo aviso sin `X-Gratex-Signature` o con firma que no coincida.**

```php
$cuerpo = file_get_contents('php://input');
$esperada = 'sha256=' . hash_hmac('sha256', $cuerpo, $webhookSecret);
if (!hash_equals($esperada, $_SERVER['HTTP_X_GRATEX_SIGNATURE'] ?? '')) {
    http_response_code(401);
    exit;
}
```

### Responder y reintentos

- Responda con un código `2xx` en menos de 5 segundos, directamente y sin redirecciones.
  Cualquier otra respuesta cuenta como fallo.
- Si falla, reintentamos de inmediato hasta completar 3 intentos; después ese aviso no se
  vuelve a enviar.
- Responda primero y procese después. Si tarda, el mismo aviso puede llegar más de una vez:
  descarte duplicados por `event` + `rnc_emisor` + `e_ncf`.
- **El webhook no reemplaza la consulta.** Reconcilie periódicamente con `GET /recibidos` y
  `GET /aprobaciones`.

---

## Anexo — Catálogos

### `tipo_ecf`

| Valor | Comprobante |
|---|---|
| `31` | Factura de Crédito Fiscal |
| `32` | Factura de Consumo |
| `33` | Nota de Débito |
| `34` | Nota de Crédito |
| `41` | Comprobante de Compras |
| `43` | Gastos Menores |
| `44` | Regímenes Especiales |
| `45` | Gubernamental |
| `46` | Comprobante de Exportaciones |
| `47` | Comprobante para Pagos al Exterior |

### `tipo_ingresos`

| Valor | Significado |
|---|---|
| `01` | Ingresos por operaciones (no financieros) — por defecto |
| `02` | Ingresos financieros |
| `03` | Ingresos extraordinarios |
| `04` | Ingresos por arrendamientos |
| `05` | Ingresos por venta de activo depreciable |
| `06` | Otros ingresos |

### `unidad_medida`

Se envía el **número**, no la abreviatura.

| | | | | | |
|---|---|---|---|---|---|
| 1 Barril | 2 Bolsa | 3 Bote | 4 Bultos | 5 Botella | 6 Caja/Cajón |
| 7 Cajetilla | 8 Centímetro | 9 Cilindro | 10 Conjunto | 11 Contenedor | 12 Día |
| 13 Docena | 14 Fardo | 15 Galones | 16 Grado | 17 Gramo | 18 Granel |
| 19 Hora | 20 Huacal | 21 Kilogramo | 22 Kilovatio Hora | 23 Libra | 24 Litro |
| 25 Lote | 26 Metro | 27 Metro Cuadrado | 28 Metro Cúbico | 29 MMBTU | 30 Minuto |
| 31 Paquete | 32 Par | 33 Pie | 34 Pieza | 35 Rollo | 36 Sobre |
| 37 Segundo | 38 Tanque | 39 Tonelada | 40 Tubo | 41 Yarda | 42 Yarda cuadrada |
| **43 Unidad** | 44 Elemento | 45 Millar | 46 Saco | 47 Lata | 48 Display |
| 49 Bidón | 50 Ración | 51 Quintal | 52 Ton. registro bruto | 53 Pie Cuadrado | 54 Pasajero |
| 55 Pulgadas | 56 Parqueo barcos | 57 Bandeja | 58 Hectárea | 59 Mililitro | 60 Miligramo |
| 61 Onzas | 62 Onzas Troy | | | | |

Los demás catálogos (`indicador_facturacion`, `indicador_bien_servicio`, `tipo_pago`,
`codigo_modificacion`) están en las tablas de cada campo.

---

## Soporte

Escriba a **info@gratex.net** con el RNC de la empresa, el `e_ncf` y el `track_id` (o el
`codigo_seguridad` en E32 de menos de RD$250,000).
