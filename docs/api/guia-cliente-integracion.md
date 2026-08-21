# Guía de Integración — Facturación Electrónica e-CF

**Documento para el equipo técnico del cliente.**

Este servicio recibe las facturas de su sistema en formato JSON, las convierte al XML e-CF
que exige la DGII, las **firma con el certificado digital de su empresa** y las envía a la
DGII. Usted recibe de vuelta el XML firmado y la respuesta oficial.

Su sistema sigue siendo el dueño de sus facturas y de su numeración. Nosotros no
almacenamos su contabilidad.

---

## 1. Qué necesitamos de usted para darle de alta

| Dato | Obligatorio | Notas |
|---|---|---|
| **RNC de la empresa** | Sí | 9 u 11 dígitos |
| **Razón social y dirección fiscal** | Sí | Como aparecen en la DGII |
| **Certificado digital `.p12`** | Sí | El de su empresa, emitido por una entidad certificadora autorizada (Viafirma, Camarad, etc.) |
| **Contraseña del certificado** | Sí | Envíela por un canal aparte del archivo |
| **URL de webhook** | No | Si la da, le empujamos los documentos entrantes. Si no, usted consulta por polling |

> **Sobre el certificado:** debe estar en formato PKCS#12 moderno (AES-256). Los `.p12`
> viejos cifrados con RC2/3DES no los puede abrir OpenSSL 3.x. Si el suyo es antiguo,
> conviértalo antes de enviarlo:
>
> ```bash
> openssl pkcs12 -legacy -in viejo.p12 -out temp.pem -nodes
> openssl pkcs12 -export -in temp.pem -out nuevo.p12 -keypbe AES-256-CBC -certpbe AES-256-CBC -macalg SHA256
> ```
>
> Borre el `temp.pem` después: contiene su llave privada sin cifrar.

### Qué recibe usted

Al completar el alta le entregamos, **por canal seguro**, dos credenciales de máquina:

```
API KEY    : <identificador público>
API SECRET : <secreto>
```

El **API SECRET se muestra una sola vez** — nosotros guardamos solo su hash y no podemos
recuperarlo. Si lo pierde, hay que regenerar el par. Guárdelo en el gestor de secretos de su
sistema, nunca en el código fuente ni en un repositorio.

---

## 2. Registro en el portal de la DGII

En su Oficina Virtual de la DGII → registro de WebServices, declare estas tres URLs:

| Servicio | URL |
|---|---|
| Recepción | `https://gratex.net/api/ecf/recepcion` |
| Aprobación Comercial | `https://gratex.net/api/ecf/aprobacion-comercial` |
| Autenticación | `https://gratex.net/api/ecf/autenticacion` |

Son las mismas para todos nuestros clientes: el sistema identifica a quién pertenece cada
documento por el RNC que viene dentro del XML.

También debe solicitar a la DGII sus **rangos de e-NCF autorizados** para cada tipo de
comprobante que vaya a emitir. Su sistema administra esa numeración (ver sección 4).

---

## 3. Autenticación

Todas las llamadas llevan las dos credenciales en los headers:

```
X-API-KEY: <api_key>
X-API-SECRET: <api_secret>
Content-Type: application/json
```

Base URL: `https://gratex.net/api/`

| Endpoint | Método | Para qué |
|---|---|---|
| `/api/integracion/ecf` | POST | Emitir un e-CF |
| `/api/integracion/aprobacion-comercial` | POST | Aceptar o rechazar una factura que le emitieron |
| `/api/integracion/recibidos` | GET | Facturas que otros le emitieron a usted |
| `/api/integracion/aprobaciones` | GET | Veredictos de sus clientes sobre las facturas que usted emitió |

Toda respuesta tiene la forma `{"status": true, "data": {...}}` o
`{"status": false, "error": "mensaje"}`.

---

## 4. Emitir un e-CF — `POST /api/integracion/ecf`

### Las tres reglas que no puede saltarse

1. **Usted asigna el `e_ncf`.** Nosotros no llevamos su secuencia. Debe ser `E` + tipo +
   10 dígitos (ej. `E310000000001`) y estar dentro del rango que la DGII le autorizó.
   Es su responsabilidad no repetir ni saltar números.
2. **El objeto `emisor` va completo** en cada llamada, con al menos `rnc`, `razon_social`
   y `direccion`.
3. **`emisor.rnc` debe ser el RNC de su empresa** — el mismo con el que se registró. No se
   puede emitir por cuenta de un tercero.

El **ambiente** (certificación o producción) lo determinamos nosotros según el estado de su
cuenta. No lo envíe: si lo manda, se ignora.

### Ejemplo — Factura de Crédito Fiscal (E31)

```json
{
  "tipo_ecf": "31",
  "e_ncf": "E310000000001",
  "fecha_emision": "04-08-2026",
  "tipo_pago": 1,
  "emisor": {
    "rnc": "131111111",
    "razon_social": "CLIENTE A SRL",
    "nombre_comercial": "Cliente A",
    "direccion": "Av. Winston Churchill #45, Piantini",
    "municipio": "010100",
    "provincia": "010000",
    "telefono": "809-555-0100",
    "correo": "facturacion@clientea.com"
  },
  "comprador": {
    "rnc": "131880681",
    "razon_social": "EMPRESA COMPRADORA SRL",
    "correo": "cuentas@compradora.com"
  },
  "items": [
    {
      "numero_linea": 1,
      "indicador_facturacion": 1,
      "nombre_item": "Servicio de consultoría",
      "descripcion": "Consultoría técnica correspondiente al mes de agosto 2026",
      "indicador_bien_servicio": 2,
      "cantidad": 5,
      "unidad_medida": "43",
      "precio_unitario": 1500.00
    }
  ]
}
```

**Los totales los calculamos nosotros** a partir de los items (monto gravado por tasa,
ITBIS, exento, total). Envíe el objeto `totales` únicamente si necesita forzar tasas
distintas a las estándar.

### Respuesta

```json
{
  "status": true,
  "data": {
    "e_ncf": "E310000000001",
    "tipo_ecf": "31",
    "estado": "ACEPTADO",
    "track_id": "0784f643-3c19-4377-9104-e0581f0200d6",
    "codigo_seguridad": "RHq/pJ",
    "ambiente": "ecf",
    "fecha_emision": "2026-08-04 11:52:22",
    "xml_firmado": "<?xml version=\"1.0\" encoding=\"UTF-8\"?>...",
    "dgii_response": { }
  }
}
```

**Guarde siempre** `xml_firmado` (es el comprobante con valor legal), más
`codigo_seguridad`, `fecha_emision` y `track_id`. Los tres últimos son obligatorios para
imprimir la Representación Impresa (sección 7).

### Ejemplo con curl

```bash
curl -X POST https://gratex.net/api/integracion/ecf -H "X-API-KEY: $API_KEY" -H "X-API-SECRET: $API_SECRET" -H "Content-Type: application/json" -d @factura.json
```

---

## 5. Tablas de referencia

### Tipos de e-CF

| `tipo_ecf` | Comprobante |
|---|---|
| `31` | Factura de Crédito Fiscal (B2B, requiere RNC del comprador) |
| `32` | Factura de Consumo (B2C) |
| `33` | Nota de Débito |
| `34` | Nota de Crédito |
| `41` | Comprobante de Compras |
| `43` | Gastos Menores |
| `44` | Regímenes Especiales |
| `45` | Gubernamental |
| `46` | Comprobante de Exportaciones |
| `47` | Comprobante para Pagos al Exterior |

E31 exige RNC del comprador. E32 y E43 pueden emitirse sin comprador identificado.
Las notas (E33/E34) requieren además `ncf_modificado`, `fecha_ncf_modificado` y
`codigo_modificacion` (`1`=Anula, `2`=Corrige texto, `3`=Corrige montos,
`4`=Reemplazo contingencia, `5`=Referencia factura de consumo).

### `indicador_facturacion` (ITBIS de cada línea)

| Valor | Significado |
|---|---|
| `1` | Gravado 18% |
| `2` | Gravado 16% |
| `3` | Tasa cero (exportaciones) |
| `4` | Exento |

Puede mezclar distintos indicadores en un mismo comprobante.

### `indicador_bien_servicio`

| Valor | Significado |
|---|---|
| `1` | Bien |
| `2` | Servicio |

### `unidad_medida` — códigos DGII

Se envía el **número**, no la abreviatura. El más usado es `43` (Unidad).

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

### Municipio y provincia

Son códigos DGII de 6 dígitos (ej. Santo Domingo de Guzmán = municipio `010100`,
provincia `010000`). Si no los envía, el comprobante se emite igual. Solicítenos la tabla
completa si su catálogo la necesita.

---

## 6. Dos errores que causan rechazo de la DGII

Estos dos casos son la causa más frecuente de comprobantes rechazados. Vale la pena
validarlos en su sistema antes de enviar.

### 6.1 Largo de los campos de ítem

| Campo | Máximo | Qué poner |
|---|---|---|
| `nombre_item` | **80 caracteres** | Nombre corto. Ej: `Sticker Vinyl 2x2` |
| `descripcion` | **1000 caracteres** | El detalle: material, medidas, sucursal, etc. |

Si mete el detalle completo dentro de `nombre_item`, la DGII rechaza el comprobante por
exceder el tipo de dato. Nosotros truncamos como red de seguridad, pero el corte puede caer
a mitad de palabra y el comprobante queda feo. Separe bien los dos campos en el origen.

La DGII cuenta **caracteres**, no bytes: tildes y `ñ` cuentan como uno.

### 6.2 Saltos de línea con retorno de carro

**No envíe `\r` (CR) dentro de los textos.** Use solo `\n` para los saltos de línea.

El validador de la DGII pierde los retornos de carro al re-procesar el XML, lo que rompe la
verificación de la firma y produce el rechazo *"La firma del XML no es válida"* — aunque la
firma sea perfectamente correcta. Es un comportamiento conocido de su validador.

Ocurre típicamente cuando el texto viene de un `<textarea>` en Windows, que produce `\r\n`.
Normalice antes de enviar:

```javascript
descripcion.replace(/\r\n?/g, '\n')
```

Nosotros también normalizamos en la entrada, pero es mejor no depender de eso.

---

## 7. Representación Impresa

La DGII exige que todo e-CF impreso o en PDF contenga, como mínimo:

- **Emisor**: razón social, RNC, dirección, contacto
- **Identificación del comprobante**: denominación del tipo, e-NCF, fecha de emisión y de
  vencimiento
- **Receptor**: RNC/cédula y razón social (obligatorio en crédito fiscal y en consumo ≥ RD$250,000)
- **Tabla de ítems** con seis columnas: Cantidad · Descripción · Unidad de Medida · Precio · ITBIS · Valor
- **Totales**: Subtotal Gravado, Total ITBIS, Total
- **Pie de seguridad**: código QR de consulta, **Código de Seguridad** (6 caracteres) y
  **Fecha de Firma** (`DD-MM-AAAA HH:MM:SS`)
- `Página X de Y` si tiene más de una hoja
- En notas de crédito/débito: el campo **NCF Modificado**

El código de seguridad y la fecha de firma salen de la respuesta de emisión
(`codigo_seguridad` y `fecha_emision`). El QR apunta al servicio ConsultaTimbre de la DGII.

Si prefiere no implementar el PDF, podemos generarlo nosotros — coordínelo con nuestro
equipo.

---

## 8. Recibir facturas de sus proveedores

Cuando otro contribuyente le emita un e-CF a usted, la DGII o el emisor lo entregan en
nuestra URL de recepción; nosotros validamos la firma, devolvemos el acuse firmado con su
certificado y se lo dejamos disponible.

### Opción A — Polling

```
GET /api/integracion/recibidos?page=1&pageSize=20
```

```json
{
  "status": true,
  "recurso": "recibidos",
  "data": [
    {
      "id": 1, "track_id": "...", "tipo_ecf": "31", "e_ncf": "E310000000001",
      "rnc_emisor": "...", "razon_social_emisor": "...", "rnc_comprador": "...",
      "monto_total": 6608.00, "fecha_emision": "...", "fecha_recepcion": "...",
      "estado": "RECIBIDO", "validacion_firma": "OK", "ambiente": "ecf",
      "aprobacion_comercial": null
    }
  ],
  "pagination": { "page": 1, "pageSize": 20, "total": 1, "totalPages": 1 }
}
```

`pageSize` admite hasta 100. `estado` es el resultado **técnico** (firma y estructura);
`aprobacion_comercial` es su decisión **comercial**: `null` significa pendiente — muéstrelo
como tal, no lo asuma aprobado.

`GET /api/integracion/aprobaciones` funciona igual, pero lista los veredictos que **sus
clientes** enviaron sobre las facturas que **usted** emitió.

### Opción B — Webhook

Si nos dio una URL, le enviamos un POST en cuanto llega un documento:

```json
{
  "event": "ecf.recibido",
  "tenant_id": 7,
  "data": {
    "track_id": "...", "tipo_ecf": "31", "e_ncf": "E310000000001",
    "rnc_emisor": "...", "razon_social_emisor": "...", "rnc_comprador": "...",
    "monto_total": 6608.00, "fecha_emision": "...", "estado": "RECIBIDO"
  },
  "sent_at": "2026-08-04T09:53:22-04:00"
}
```

Eventos: `ecf.recibido` y `aprobacion.recibida`.

**Verifique la firma** del header antes de procesar:

```
X-Gratex-Signature: sha256=<HMAC-SHA256 del cuerpo crudo, con su webhook secret>
```

Calcule el HMAC sobre el **cuerpo tal como llegó** (no sobre el JSON re-serializado) y
compare en tiempo constante.

> **El webhook no reemplaza al polling.** Hacemos hasta 3 intentos con 5 segundos de
> timeout; si su endpoint está caído en ese momento, el evento se pierde. Reconcilie
> periódicamente con `GET /recibidos`.

---

## 9. Aceptar o rechazar una factura recibida

`POST /api/integracion/aprobacion-comercial`

```json
{
  "rnc_emisor": "131256432",
  "e_ncf": "E310000000028",
  "fecha_emision": "01-08-2026",
  "monto_total": 6608.00,
  "estado": "1"
}
```

| Campo | Requerido | Notas |
|---|---|---|
| `rnc_emisor` | Sí | RNC de quien le facturó |
| `e_ncf` | Sí | e-NCF del comprobante recibido |
| `fecha_emision` | Sí | |
| `monto_total` | Sí | |
| `estado` | Sí | `1` = Aceptado · `2` = Rechazado |
| `detalle_motivo` | Si `estado=2` | Motivo del rechazo |

No envíe `rnc_comprador`: lo tomamos de su cuenta.

**Cómo leer la respuesta de la DGII.** En `dgii_response`:

- `codigo` `1` o `01` → la DGII **procesó** su aprobación o rechazo.
- `codigo` `2` o `02` → **no la procesó** (factura no encontrada, error técnico, o
  ambiente que no corresponde). Debe reintentar.
- Un `estado` con el texto `"Aprobacion Comercial Rechazada."` significa que la DGII rechazó
  **procesar su envío** — no que su rechazo comercial haya quedado registrado. Es un punto
  que confunde con frecuencia.

---

## 10. Códigos de error

| HTTP | Significado | Qué hacer |
|---|---|---|
| `400` | JSON mal formado | Revise el cuerpo del request |
| `401` | Credenciales ausentes o inválidas | Verifique `X-API-KEY` y `X-API-SECRET` |
| `403` | Endpoint no corresponde a su tipo de cuenta | Contáctenos |
| `405` | Método HTTP incorrecto | Emisión y aprobación son POST; las bandejas, GET |
| `422` | Falta `e_ncf`, falta `emisor.rnc`, o el RNC no coincide con su cuenta | Revise las tres reglas de la sección 4 |
| `502` | Falló la emisión o el envío a la DGII | El mensaje trae el detalle. Reintente; si persiste, contáctenos |

**Importante sobre los reintentos:** un `502` puede significar que la DGII sí recibió el
documento pero la respuesta se perdió. **No reutilice el `e_ncf` a ciegas** — consulte el
estado antes de reemitir, o el comprobante quedará duplicado ante la DGII.

---

## 11. Puesta en marcha

1. **Alta.** Nos envía RNC, razón social, dirección, certificado `.p12` y su contraseña.
   Le entregamos API KEY y API SECRET. Su cuenta arranca en **ambiente de certificación**.
2. **Registro en la DGII.** Declara las tres URLs de la sección 2 y solicita sus rangos de
   e-NCF.
3. **Desarrollo e integración.** Construye contra los endpoints de este documento. Todo lo
   que emita en esta etapa va al ambiente de certificación de la DGII — no tiene efecto
   fiscal.
4. **Set de pruebas de la DGII.** La DGII le entrega un set de casos que debe emitir
   correctamente. Los envía por la API igual que una factura normal.
5. **Aprobación.** Cuando la DGII aprueba su certificación, nos avisa y **promovemos su
   cuenta a producción**. A partir de ese momento sus comprobantes tienen valor fiscal.

Al pasar a producción, sus bandejas dejan de mostrar los documentos de certificación: no se
mezclan los dos ambientes.

### Lista de verificación antes de salir a producción

- [ ] El `e_ncf` se genera desde un contador propio, dentro del rango autorizado, sin repetir
- [ ] Se persisten `xml_firmado`, `track_id`, `codigo_seguridad` y `fecha_emision` de cada emisión
- [ ] `nombre_item` ≤ 80 caracteres, `descripcion` ≤ 1000, validado en el formulario
- [ ] Los textos se normalizan a `\n` (sin `\r`) antes de enviar
- [ ] Los errores `502` no reemiten con el mismo `e_ncf` sin consultar antes
- [ ] Las facturas recibidas se reconcilian por polling, aunque use webhook
- [ ] Si usa webhook: se valida la firma `X-Gratex-Signature`
- [ ] El API SECRET está en un gestor de secretos, no en el repositorio
- [ ] La Representación Impresa incluye QR, código de seguridad y fecha de firma

---

## Soporte

Escriba a **info@gratex.net** con el `e_ncf` y el `track_id` del comprobante involucrado —
con esos dos datos podemos rastrear cualquier emisión.
