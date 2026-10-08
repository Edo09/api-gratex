# Guía de Integración — Facturación Electrónica e-CF

**Documento para el equipo técnico del cliente.**

Su sistema de facturación sigue funcionando como hoy. Lo único que agrega es una llamada a
nuestro API por cada comprobante: nos manda la factura en JSON, nosotros la convertimos al
XML e-CF que exige la DGII, la **firmamos con el certificado digital de la empresa que
factura** y la enviamos a la DGII. Usted recibe de vuelta el XML firmado y la respuesta
oficial.

Su sistema sigue siendo el dueño de sus facturas y de su numeración. Nosotros no
almacenamos su contabilidad.

**Varias empresas, una sola credencial.** Si su sistema factura por varias empresas (varios
RNC), todas usan el mismo par de credenciales: en cada llamada la empresa se elige por su RNC
(sección 3).

**La certificación ante la DGII la hacemos nosotros**, empresa por empresa: corremos las
pruebas que exige la DGII con nuestro sistema y le avisamos cuando cada empresa queda en
producción (sección 11). Su equipo puede ir integrando mientras tanto.

---

## 1. Qué necesitamos de usted para darle de alta

Por **cada empresa** (cada RNC) que facture electrónicamente:

| Dato | Obligatorio | Notas |
|---|---|---|
| **RNC de la empresa** | Sí | 9 u 11 dígitos |
| **Razón social y dirección fiscal** | Sí | Como aparecen en la DGII. La dirección sale en cada comprobante |
| **Teléfono y correo** | Recomendado | Salen en la Representación Impresa |
| **Certificado digital `.p12`** | Sí | El de esa empresa, emitido por una entidad certificadora autorizada (Viafirma, Cámara de Comercio, etc.). Si está a nombre de una persona física, esa persona tiene que estar **delegada** para firmar por el RNC de la empresa en la Oficina Virtual de la DGII |
| **Contraseña del certificado** | Sí | Envíela por un canal aparte del archivo |

Opcional:

| Dato | Obligatorio | Notas |
|---|---|---|
| **URL de webhook** | No | Si la da, le avisamos ahí cuando llega un documento. Puede ser la misma para todas sus empresas; indíquenos a cuáles aplica. Si no la da, usted consulta las bandejas (sección 8) |

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

Al completar el alta le entregamos, **por canal seguro**, un par de credenciales de máquina
que sirve para **todas sus empresas**:

```
API KEY    : <identificador público>
API SECRET : <secreto>
```

El **API SECRET se muestra una sola vez** — nosotros guardamos solo su hash y no podemos
recuperarlo. Si lo pierde, hay que regenerar el par. Guárdelo en el gestor de secretos de su
sistema, nunca en el código fuente ni en un repositorio.

Las llamadas al API deben salir **siempre de su servidor**, nunca desde el navegador, una
aplicación web del lado del cliente ni una app móvil: todo lo que llega al dispositivo del
usuario se puede leer. Con este secreto se pueden emitir comprobantes con valor fiscal firmados
por cualquiera de sus empresas. Si sospecha que se filtró, avísenos de inmediato a
info@gratex.net para revocarlo y entregarle uno nuevo.

Si nos dio una URL de webhook, le entregamos además, por el mismo canal, el **WEBHOOK SECRET**
de cada empresa con webhook. Es distinto del API SECRET y solo sirve para verificar que los
avisos que llegan a su URL son nuestros (sección 8). Guárdelo con la misma protección.

---

## 2. Registro en el portal de la DGII

Esto lo hace **cada empresa** en su Oficina Virtual de la DGII (requiere sus propias claves de
acceso; nosotros le acompañamos en el proceso):

1. **Postularse** como emisor electrónico. Sin postulación activa la DGII no acepta las pruebas.
2. **Delegar el certificado** si está a nombre de una persona física (sección 1).
3. **Registrar estas tres URLs** en el registro de WebServices:

| Servicio | URL |
|---|---|
| Recepción | `https://gratex.net/api/ecf/recepcion` |
| Aprobación Comercial | `https://gratex.net/api/ecf/aprobacion-comercial` |
| Autenticación | `https://gratex.net/api/ecf/autenticacion` |

Son las mismas para todas las empresas y todos nuestros clientes: el sistema identifica a
quién pertenece cada documento por el RNC que viene dentro del XML.

4. **Solicitar los rangos de e-NCF autorizados** para cada tipo de comprobante que vaya a
   emitir, primero los de certificación y después los de producción. Su sistema administra esa
   numeración (ver sección 4).

---

## 3. Autenticación

Todas las llamadas llevan las dos credenciales en los headers:

```
X-API-KEY: <api_key>
X-API-SECRET: <api_secret>
Content-Type: application/json
```

Servidor: `https://gratex.net`. Todas las rutas ya incluyen el prefijo `/api/`: la URL completa
es el servidor seguido de la ruta, por ejemplo `https://gratex.net/api/integracion/ecf`. No
repita `/api/`.

| Endpoint | Método | Para qué |
|---|---|---|
| `/api/integracion/ecf` | POST | Emitir un e-CF (sección 4) |
| `/api/integracion/estado` | GET | Estado en la DGII de un e-CF que emitió (sección 4) |
| `/api/integracion/xml` | GET | Volver a bajar el XML firmado de un e-CF que emitió (sección 4) |
| `/api/integracion/aprobacion-comercial` | POST | Aceptar o rechazar una factura que le emitieron (sección 9) |
| `/api/integracion/recibidos` | GET | Facturas que otros le emitieron a usted (sección 8) |
| `/api/integracion/aprobaciones` | GET | Veredictos de sus clientes sobre las facturas que usted emitió (sección 8) |
| `/api/integracion/empresas` | GET | Empresas que cubre su credencial |

Toda respuesta trae `"status": true` o `"status": false`; cuando es `false`, el motivo viene
en `"error"`. El detalle de cada endpoint (todos los campos, respuestas y errores) está en la
**Referencia de endpoints**, que acompaña a esta guía.

### Si administra varias empresas

**Un solo par de credenciales cubre todas sus empresas.** No necesita un juego por RNC: la
empresa se elige en cada llamada por su RNC.

| Llamada | Cómo elige la empresa |
|---|---|
| Emitir | `emisor.rnc` del cuerpo (obligatorio) |
| Estado y XML de lo emitido | `?rnc=<RNC>` en la URL |
| Aprobar/rechazar | `rnc_comprador` del cuerpo |
| Bandejas (recibidos, aprobaciones) | `?rnc=<RNC>` en la URL |

Donde el RNC es opcional y no lo manda, la llamada va a la empresa dueña de la credencial.
**Mándelo siempre**: así cada llamada dice explícitamente de qué empresa es.

Para saber qué empresas cubre su credencial:

```
GET /api/integracion/empresas
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

Note el campo `ambiente`: **cada empresa avanza por su cuenta**. Puede tener una en
producción y otra todavía en certificación, con la misma credencial y sin tocar su código —
nosotros firmamos cada comprobante con el certificado de la empresa que corresponda y lo
mandamos al ambiente en que esa empresa esté.

Un RNC que no sea de su grupo devuelve `422`; no hay forma de emitir por una empresa ajena.

> **Lo que sí necesita una vez por empresa:** su propio certificado digital `.p12` y su
> propia certificación ante la DGII. Eso lo exige la DGII a cada emisor por separado y no
> se puede agrupar. Lo que le ahorramos es manejar N credenciales en su código.

---

## 4. Emitir un e-CF — `POST /api/integracion/ecf`

### Las cuatro reglas que no puede saltarse

1. **Usted asigna el `e_ncf`.** Nosotros no llevamos su secuencia. Debe ser `E` + tipo +
   10 dígitos (ej. `E310000000001`) y estar dentro del rango que la DGII le autorizó.
   Es su responsabilidad no repetir ni saltar números.
2. **El objeto `emisor` va completo** en cada llamada, con al menos `rnc`, `razon_social`
   y `direccion`.
3. **`emisor.rnc` debe ser el RNC de su empresa** — el mismo con el que se registró, o el de
   otra de **sus** empresas si administra varias (ver sección 3). No se puede emitir por
   cuenta de un tercero.
4. **Envíe `fecha_vencimiento_secuencia`** (`DD-MM-AAAA`) en todos los tipos excepto E32 y
   E34. Es la fecha de vencimiento que figura en la autorización de la DGII para el rango de
   e-NCF de esa empresa y ese tipo, y debe coincidir: si falta o no corresponde, la DGII
   rechaza con *"Fecha de vencimiento de secuencia inválida"*. El rango de certificación y el
   de producción son autorizaciones distintas y pueden vencer en fechas distintas: al pasar a
   producción, actualícela.

El **ambiente** (certificación o producción) lo determinamos nosotros según el estado de cada
empresa. No envíe el campo `ambiente` en ninguna llamada: no forma parte del API.

### Ejemplo — Factura de Crédito Fiscal (E31)

```json
{
  "tipo_ecf": "31",
  "e_ncf": "E310000000001",
  "fecha_emision": "04-08-2026",
  "fecha_vencimiento_secuencia": "31-12-2027",
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
    "rnc": "131222444",
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

**Formato de fechas.** Todas las fechas que envía van como `DD-MM-AAAA`, con guiones (ej.
`04-08-2026` = 4 de agosto de 2026). **No use `/`**: `04/08/2026` se interpreta como mes/día
(8 de abril) y el comprobante se firma con esa fecha, sin error. Si omite `fecha_emision`, se
usa la fecha del día.

**`tipo_pago`:** `1` = Contado (por defecto), `2` = Crédito, `3` = Gratuito. A crédito puede
enviar `fecha_limite_pago` (`DD-MM-AAAA`); si no la envía, usamos la fecha de emisión + 30 días.

**Los totales los calculamos nosotros** a partir de los items (monto gravado por tasa,
ITBIS, exento, total). En una factura normal **no envíe el objeto `totales`**: las tasas de
ITBIS son fijas (18 %, 16 % o 0 % según el `indicador_facturacion`) y no se cambian desde ahí.
Solo hace falta cuando el comprobante lleva montos que no salen de los ítems, como las
retenciones de un E41 (vea la Referencia de endpoints, sección 6.6).

### Respuesta

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

**Guarde siempre** `xml_firmado` (es el comprobante con valor legal), `codigo_seguridad` y
`fecha_emision` (con ellos se arma la Representación Impresa, sección 7) y `track_id` (para
consultar el estado y para soporte).

**`"status": true` significa que firmamos el comprobante y lo enviamos a la DGII; no que la
DGII lo haya aceptado.** Lo que respondió la DGII está en `estado`:

- `ENVIADO`: la DGII lo recibió y lo está validando. Es la respuesta normal. Consulte el
  estado después (abajo) hasta tener un estado final.
- `RECHAZADO`: la DGII lo rechazó al recibirlo; el motivo viene en `dgii_response`.
- `ERROR`: la DGII respondió un error sin dar veredicto. Consulte el estado antes de reintentar.
- `ACEPTADO`, `ACEPTADO_CONDICIONAL`, `EN_PROCESO`: cuando la DGII ya informa el resultado al
  recibirlo.

**Facturas de consumo (E32) de menos de RD$250,000.** La DGII recibe un resumen del
comprobante y no genera `track_id`. La respuesta trae `"track_id": null`,
`"dgii_response": null` y el `estado` con el prefijo `RFCE_` (lo normal es `RFCE_ACEPTADO`).
Si recibe `RFCE_RECHAZADO`, el motivo se consulta con `GET /api/integracion/estado` usando el
`codigo_seguridad`.

No dé por válido un comprobante hasta tener `ACEPTADO`, `ACEPTADO_CONDICIONAL` o
`RFCE_ACEPTADO`.

### Ejemplo con curl

```bash
curl -X POST https://gratex.net/api/integracion/ecf -H "X-API-KEY: $API_KEY" -H "X-API-SECRET: $API_SECRET" -H "Content-Type: application/json" -d @factura.json
```

### Consultar el estado después — `GET /api/integracion/estado`

El `estado` de la respuesta de emisión es el del momento del envío; el definitivo se consulta
después. Cada consulta se hace en ese momento contra la DGII, así que úsela con moderación:

- consulte solo los comprobantes que sigan en `ENVIADO`, `EN_PROCESO` o `ERROR`;
- deje al menos 1 minuto entre dos consultas del mismo comprobante (cada 2 a 5 minutos es
  suficiente);
- deje de consultarlo en cuanto reciba un estado final: `ACEPTADO`, `ACEPTADO_CONDICIONAL` o
  `RECHAZADO`;
- nunca consulte en un bucle sin pausa.

```bash
curl "https://gratex.net/api/integracion/estado?e_ncf=E310000000001&track_id=$TRACK_ID" -H "X-API-KEY: $API_KEY" -H "X-API-SECRET: $API_SECRET"
```

| Parámetro | Requerido | Notas |
|---|---|---|
| `e_ncf` | Sí | |
| `track_id` | Recomendado | Mándelo siempre que lo tenga. Si lo omite, usamos el del **último envío** de ese e-NCF que tengamos para esa empresa (puede ser un reenvío rechazado, o uno de la certificación si usó el mismo número en las pruebas); la respuesta trae el `track_id` usado: compárelo con el suyo |
| `codigo_seguridad` | Solo E32 <250k | Esos comprobantes no generan `track_id` |
| `rnc` | Recomendado | La empresa emisora |

Respuesta: `{status, recurso:"estado", rnc, empresa, e_ncf, track_id, flujo, ambiente, estado,
consulta}`. `estado` es uno de `ACEPTADO`, `ACEPTADO_CONDICIONAL`, `EN_PROCESO`, `RECHAZADO`,
`NO_ENCONTRADO` (con prefijo `RFCE_` cuando consulta por código de seguridad), o `null` si la
DGII no devolvió un estado reconocible: en ese caso no lo trate como aceptado ni como
rechazado y vuelva a consultar más tarde. El detalle completo de la DGII va en `consulta`.

> No reemita un comprobante rechazado con el mismo `e_ncf` sin revisar antes el mensaje de
> `consulta`: si el rechazo no consumió la secuencia, el mismo `e_ncf` sirve; si la consumió,
> tiene que usar el siguiente.

El motivo de un rechazo viene en `consulta.mensajes`, por ejemplo:

```json
"consulta": {
  "codigo": "2", "estado": "Rechazado", "secuenciaUtilizada": false,
  "mensajes": [ { "valor": "El campo MontoGravadoI1 ... no coincide con la sumatoria ...", "codigo": 0 } ]
}
```

### Volver a bajar el XML firmado — `GET /api/integracion/xml`

Si su sistema perdió el `xml_firmado` de una emisión, puede pedirlo de nuevo. Devolvemos el
del **último envío** de ese e-NCF que tengamos para esa empresa, tal cual se firmó, con su
`track_id` y su código de seguridad. Ese último envío puede ser uno rechazado, o uno de la
certificación si el mismo número se usó en las pruebas: antes de guardarlo como su
comprobante, confírmelo con `GET /api/integracion/estado`.

```bash
curl "https://gratex.net/api/integracion/xml?e_ncf=E320000000012&rnc=131111111" -H "X-API-KEY: $API_KEY" -H "X-API-SECRET: $API_SECRET"
```

```json
{
  "status": true, "recurso": "xml",
  "rnc": "131111111", "empresa": "Empresa 1 SRL",
  "e_ncf": "E320000000012", "tipo_ecf": "32",
  "track_id": null, "flujo": "RFCE",
  "codigo_seguridad": "Qw7/Lp",
  "fecha_emision": "2026-10-06 10:40:01",
  "archivo": "E320000000012.xml",
  "xml_firmado": "<?xml version=\"1.0\" encoding=\"UTF-8\"?>..."
}
```

Guárdelo **sin modificarlo**: cualquier cambio de formato invalida la firma. `404` si no hay
ningún envío de ese e-NCF para esa empresa; `422` si el `e_ncf` está mal formado.

En esta respuesta, `fecha_emision` es la hora en que guardamos la copia, **no la fecha de
firma**. Si va a reimprimir, tome la fecha de firma del elemento `<FechaHoraFirma>` del
`xml_firmado` (ya viene como `DD-MM-AAAA HH:MM:SS`).

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

E31 exige RNC del comprador. E32 de menos de RD$250,000 y E43 pueden emitirse sin comprador
identificado.

Las notas (E33/E34) requieren además un objeto `informacion_referencia` con los datos del
comprobante que modifican. Los campos van **dentro de ese objeto**, no en la raíz del JSON:

```json
"informacion_referencia": {
  "ncf_modificado": "E310000000321",
  "fecha_ncf_modificado": "27-05-2026",
  "codigo_modificacion": "3",
  "razon_modificacion": "Ajuste de precio"
}
```

`codigo_modificacion`: `1`=Anula, `2`=Corrige texto, `3`=Corrige montos, `4`=Reemplazo
contingencia, `5`=Referencia factura de consumo. `razon_modificacion` es opcional (máximo 90
caracteres). En la nota de crédito (E34) envíe también, en la raíz del JSON,
`indicador_nota_credito`: `"0"` si la nota se emite dentro de los 30 días siguientes a la
factura que modifica, `"1"` si se emite después.

Antes de integrar un tipo distinto de E31 o E32, pídanos un ejemplo de ese tipo: varios tienen
reglas propias (Referencia de endpoints, sección 6.7).

### `indicador_facturacion` (ITBIS de cada línea)

| Valor | Significado |
|---|---|
| `1` | Gravado 18% |
| `2` | Gravado 16% |
| `3` | Tasa cero (exportaciones) |
| `4` | Exento |

En E31, E32, E33, E34 y E45 puede mezclar distintos indicadores en un mismo comprobante.
E43, E44 y E47 solo admiten `4`, y E46 solo `3`.

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

## 6. Dos cuidados con los textos de los ítems

Estos dos casos hacían que la DGII rechazara comprobantes. Nuestro API los corrige
automáticamente antes de firmar, así que no le causarán un rechazo, pero la corrección puede
cambiar su texto. Valídelos en su sistema antes de enviar.

### 6.1 Largo de los campos de ítem

| Campo | Máximo | Qué poner |
|---|---|---|
| `nombre_item` | **80 caracteres** | Nombre corto. Ej: `Sticker Vinyl 2x2` |
| `descripcion` | **1000 caracteres** | El detalle: material, medidas, sucursal, etc. |

Si `nombre_item` pasa de 80 caracteres, lo cortamos a 80; si además no envió `descripcion`,
copiamos el nombre completo en la descripción para que no se pierda. Si `descripcion` pasa de
1000, la cortamos y el resto no sale en el comprobante. El corte puede caer a mitad de
palabra, así que separe en su formulario el nombre corto del detalle.

La DGII cuenta **caracteres**, no bytes: tildes y `ñ` cuentan como uno.

### 6.2 Saltos de línea con retorno de carro

**No envíe `\r` (CR) dentro de los textos.** Use solo `\n` para los saltos de línea.

Un retorno de carro dentro del XML hace que la DGII rechace con *"La firma del XML no es
válida"*, aunque la firma sea correcta. Por eso, al recibir su solicitud convertimos `\r\n` y
`\r` en `\n`. Ocurre típicamente cuando el texto viene de un `<textarea>` en Windows.
Normalice también en su sistema, para que el texto que usted guarda coincida con el del XML
firmado que le devolvemos:

```javascript
descripcion.replace(/\r\n?/g, '\n')
```

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

El código de seguridad y la fecha de firma salen de la respuesta de emisión:
`codigo_seguridad` y `fecha_emision`. Ese `fecha_emision` de la respuesta es la **fecha y hora
de firma** y viene como `AAAA-MM-DD HH:MM:SS`; en la Representación Impresa y en el QR va como
`DD-MM-AAAA HH:MM:SS`, con la misma hora exacta. No la confunda con el `fecha_emision` que usted
envía al emitir: esa es la fecha del comprobante (`DD-MM-AAAA`), que también va impresa.

**El código QR** contiene una URL de consulta de la DGII, armada con los datos del XML firmado
(cada valor codificado para URL):

```
https://ecf.dgii.gov.do/{ambiente}/ConsultaTimbre?RncEmisor={RNCEmisor}&RncComprador={RNCComprador}&ENCF={eNCF}&FechaEmision={DD-MM-AAAA}&MontoTotal={MontoTotal}&FechaFirma={DD-MM-AAAA HH:MM:SS}&CodigoSeguridad={codigo_seguridad}
```

- `{ambiente}`: `ecf` en producción, `CerteCF` en certificación (según el `ambiente` de la
  respuesta de emisión).
- `FechaEmision`: la fecha del comprobante. `FechaFirma`: el `fecha_emision` de la respuesta,
  convertido a `DD-MM-AAAA HH:MM:SS`.
- `MontoTotal`: el valor de `<MontoTotal>` del XML firmado, con dos decimales. Como los totales
  los calculamos nosotros, tómelo del XML y no de su propio cálculo.
- `RncComprador`: solo si el XML lleva `RNCComprador`. En E43 y E47 no se incluye nunca.
- En facturas de consumo E32 de menos de RD$250,000, cambie `ConsultaTimbre` por
  `ConsultaTimbreFC`.

Los valores tienen que coincidir exactamente con los del XML firmado, o la DGII no encuentra el
comprobante. Para comprobar su implementación, escanee el QR de un comprobante de prueba.

Durante la certificación, las Representaciones Impresas que pide la DGII las generamos
nosotros. En producción las imprime su sistema; si prefiere no implementar el PDF, podemos
generarlo nosotros — coordínelo con nuestro equipo.

---

## 8. Recibir facturas de sus proveedores

Cuando otro contribuyente le emita un e-CF a una de sus empresas, el documento llega a nuestra
URL de recepción, le devolvemos al emisor el acuse de recibo y se lo dejamos disponible. El
acuse confirma la recepción técnica del documento, no su validez fiscal: antes de registrar la
factura en su contabilidad o de aprobarla, confirme en la DGII que el comprobante existe y fue
aceptado (por ejemplo, con el código QR de la Representación Impresa que le entrega su
proveedor).

### Opción A — Polling

```
GET /api/integracion/recibidos?rnc=131111111&page=1&pageSize=20
```

```json
{
  "status": true,
  "recurso": "recibidos",
  "rnc": "131111111", "empresa": "Empresa 1 SRL",
  "data": [
    {
      "id": 1, "track_id": "...", "tipo_ecf": "31", "e_ncf": "E310000000028",
      "rnc_emisor": "131222333", "razon_social_emisor": "PROVEEDOR SRL", "rnc_comprador": "131111111",
      "monto_total": "6608.00", "fecha_emision": "2026-08-01", "fecha_recepcion": "2026-08-01 14:03:10",
      "estado": "ACEPTADO", "codigo_resultado": 1, "validacion_firma": "OK", "ambiente": "ecf",
      "firma_rnc": "131222333", "firma_subject": "...",
      "aprobacion_comercial": null, "aprobacion_comercial_estado_dgii": null
    }
  ],
  "pagination": { "page": 1, "pageSize": 20, "total": 1, "totalPages": 1 }
}
```

`pageSize` admite hasta 100. `estado` es el resultado de nuestra validación **técnica** al
recibir el documento (estructura y firma digital): los que no la pasan se rechazan al llegar y
no aparecen en la bandeja, por eso verá `ACEPTADO`. **No significa que la DGII haya aceptado el
comprobante.** `firma_rnc` es el RNC del certificado con que se firmó: compárelo con
`rnc_emisor`.

`aprobacion_comercial` es la última decisión **comercial** que usted envió (`ACEPTADO` o
`RECHAZADO`); `null` significa que todavía no ha enviado ninguna: muéstrela como pendiente y no
la asuma aprobada. Este campo se llena aunque la DGII no haya procesado su envío; para saber si
lo procesó, use la respuesta de `POST /aprobacion-comercial` (sección 9).

`GET /api/integracion/aprobaciones` usa la misma paginación y el mismo `?rnc=`, pero lista las
aceptaciones o rechazos que **sus clientes** enviaron sobre las facturas que **usted** emitió, y
sus filas tienen otra forma:

```json
{
  "id": 1, "e_ncf": "E310000000001", "rnc_emisor": "131111111", "rnc_comprador": "131222444",
  "estado_comercial": "RECHAZADO", "detalle_motivo": "Precio distinto al acordado",
  "validacion_firma": "OK", "ambiente": "ecf", "fecha_recepcion": "2026-08-05 09:12:44"
}
```

`estado_comercial` vale `ACEPTADO`, `ACEPTADO_CONDICIONAL` o `RECHAZADO`; relacione la fila con
su factura por `e_ncf`. Tómelas como un aviso: antes de anular una venta o emitir una nota de
crédito por un rechazo, confírmelo con su cliente.

Si administra varias empresas, agregue `?rnc=<RNC>` para pedir la bandeja de una en
concreto. La respuesta siempre dice de cuál es, en los campos `rnc` y `empresa`.

### Opción B — Webhook

Si nos dio una URL, le enviamos un POST en cuanto llega un documento:

```json
{
  "event": "ecf.recibido",
  "tenant_id": 7,
  "data": {
    "track_id": "...", "tipo_ecf": "31", "e_ncf": "E310000000028",
    "rnc_emisor": "131222333", "razon_social_emisor": "PROVEEDOR SRL", "rnc_comprador": "131111111",
    "monto_total": 6608.0, "fecha_emision": "2026-08-01", "estado": "ACEPTADO"
  },
  "sent_at": "2026-08-01T14:03:11-04:00"
}
```

El otro evento, `aprobacion.recibida`, usa el mismo sobre; solo cambia `data`:

```json
"data": {
  "e_ncf": "E310000000001", "rnc_emisor": "131111111", "rnc_comprador": "131222444",
  "estado_comercial": "ACEPTADO", "detalle_motivo": null
}
```

**A qué empresa corresponde cada aviso:** en `ecf.recibido` es `data.rnc_comprador` (a ella le
facturaron); en `aprobacion.recibida`, `data.rnc_emisor` (ella emitió la factura). No use
`tenant_id`: es un identificador interno.

**Verifique la firma antes de procesar.** Cada aviso trae el header:

```
X-Gratex-Signature: sha256=<HMAC-SHA256 en hexadecimal (minúsculas) del cuerpo crudo>
```

Calcule el HMAC con el WEBHOOK SECRET de la empresa del aviso, sobre el **cuerpo tal como
llegó** (no sobre el JSON re-serializado), y compare en tiempo constante. **Rechace (401) todo
aviso que no traiga `X-Gratex-Signature` o cuya firma no coincida.**

> **El webhook no reemplaza la consulta de las bandejas.** Su endpoint debe responder con un
> código `2xx` en menos de 5 segundos, directamente y sin redirecciones; cualquier otra
> respuesta cuenta como fallo. Si falla, reintentamos de inmediato hasta completar 3 intentos
> y después ese aviso no se vuelve a enviar. Responda primero y procese después: si tarda, el
> mismo aviso puede llegarle más de una vez; descarte duplicados por `event` + `rnc_emisor` +
> `e_ncf`. Reconcilie periódicamente con `GET /recibidos` y `GET /aprobaciones`.

---

## 9. Aceptar o rechazar una factura recibida

`POST /api/integracion/aprobacion-comercial`

```json
{
  "rnc_emisor": "131222333",
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
| `fecha_emision` | Sí | Fecha del comprobante recibido, `DD-MM-AAAA`. También puede usar tal cual el `fecha_emision` de `/recibidos` (`AAAA-MM-DD`) |
| `monto_total` | Sí | Monto total del comprobante recibido, tal cual aparece en `/recibidos` |
| `estado` | Sí | `1` = Aceptado · `2` = Rechazado |
| `detalle_motivo` | Si `estado=2` | Motivo del rechazo |
| `rnc_comprador` | Recomendado | Cuál de **sus** empresas recibió la factura. Sin él, se usa la empresa dueña de la credencial |

No envíe otros campos; en particular, no envíe `ambiente`: la aprobación o el rechazo se envían
automáticamente al mismo ambiente en que se recibió la factura.

**Respuesta.** `{ "status": true, "data": { rnc_emisor, e_ncf, estado_aprobacion, track_id,
estado_dgii, codigo_seguridad, ambiente, fecha_envio, dgii_response } }`. `estado_aprobacion`
es la decisión que usted envió (`1` o `2`).

**Cómo saber si la DGII registró su decisión.** Mire el código HTTP y `dgii_response.codigo`:

- HTTP `200` y `codigo` `1` o `01`: la DGII **procesó** su aprobación o rechazo.
- HTTP `200` y `codigo` `2` o `02`: **no la procesó** (factura no encontrada, error técnico o
  ambiente que no corresponde). Revise los datos y reintente.
- HTTP `502`: **no hay confirmación de la DGII.** No viene `dgii_response`; el motivo está en
  `error`, con la respuesta de la DGII si la hubo. Reintente; si persiste, contáctenos.

`estado_dgii` indica si la DGII **aceptó procesar el envío**, no cuál fue su decisión: un
rechazo comercial registrado correctamente sale `estado_dgii: "ACEPTADO"`. Lo mismo con un
`dgii_response.estado` que diga `"Aprobacion Comercial Rechazada."`: significa que la DGII
rechazó **procesar su envío**, no que su rechazo comercial haya quedado registrado. Guarde en su
sistema el resultado de cada envío.

---

## 10. Códigos de error

| HTTP | Significado | Qué hacer |
|---|---|---|
| `400` | JSON mal formado | Revise el cuerpo del request |
| `401` | Credenciales ausentes o inválidas, o la ruta no es de `/api/integracion/` | Verifique `X-API-KEY`, `X-API-SECRET` y la URL |
| `403` | Endpoint no corresponde a su tipo de cuenta | Contáctenos |
| `404` | La ruta no existe. En `GET /estado` sin `track_id` ni `codigo_seguridad`: no tenemos registro de ese e-NCF para esa empresa, o es un E32 menor de RD$250,000. En `GET /xml`: no hay ningún envío de ese e-NCF para esa empresa | Revise la URL, el `e_ncf` y el `rnc`. En `/estado`, envíe el `track_id`, o el `codigo_seguridad` si es un E32 menor de RD$250,000 |
| `405` | Método HTTP incorrecto | Emisión y aprobación son POST; lo demás, GET |
| `422` | Emisión: falta `e_ncf` o `emisor.rnc`. Aprobación: falta un campo obligatorio, `estado` no es `1` ni `2`, o `estado=2` sin `detalle_motivo`. Estado y XML: falta `e_ncf` (en XML, también si está mal formado). En cualquier llamada: el RNC indicado no es de ninguna de sus empresas | El campo `error` dice qué falta; corrija y repita |
| `502` | No se pudo completar la operación. En la emisión puede ser un dato del cuerpo que no pasó la validación (`tipo_ecf` inválido, `emisor` sin `razon_social` o `direccion`, `e_ncf` que no corresponde al `tipo_ecf`, E31 sin RNC del comprador, nota sin `informacion_referencia`, fecha inválida) o una falla de comunicación con la DGII | Lea el campo `error` y siga la nota de abajo |

**Sobre los reintentos de una emisión con `502`:**

- Si `error` señala un dato del cuerpo, el comprobante no llegó a la DGII: corríjalo y reenvíe
  con el **mismo** `e_ncf`.
- Si es una falla de comunicación con la DGII, el comprobante pudo haber llegado y quedado
  aceptado aunque usted no recibió la respuesta. Reenvíe con el **mismo** `e_ncf`, nunca con
  otro número. Si la DGII lo rechaza porque el e-NCF ya fue utilizado, el primer envío sí llegó:
  **no lo emita con el siguiente número**, porque duplicaría la factura. Escríbanos con el RNC
  y el `e_ncf`.

---

## 11. Certificación y puesta en marcha

**La certificación de cada empresa ante la DGII la hacemos nosotros.** Su equipo se ocupa de
la integración; las dos cosas avanzan en paralelo.

| Paso | Quién | Qué |
|---|---|---|
| 1. Alta | Usted → nosotros | Nos envía los datos y el certificado de cada empresa (sección 1). Le entregamos **una** credencial para todas. Cada empresa arranca en **ambiente de certificación** |
| 2. Portal DGII | Cada empresa, con nuestro acompañamiento | Postulación, delegación del certificado, registro de las tres URLs y rangos de e-NCF de certificación (sección 2) |
| 3. Pruebas de la DGII | Nosotros | Emitimos el set de pruebas que entrega la DGII, las aprobaciones comerciales y la simulación de todos los tipos de comprobante, y cargamos en el portal los XML de las facturas de consumo < 250k y las Representaciones Impresas. Los pasos que requieren el usuario de la empresa en la Oficina Virtual los coordinamos con ustedes |
| 4. Integración | Su equipo | Construye contra los endpoints de este documento. Mientras una empresa esté en certificación, lo que emita va al ambiente de pruebas de la DGII: **no tiene efecto fiscal** |
| 5. Producción | Nosotros → usted | Cuando la DGII aprueba una empresa, la **promovemos a producción** y le avisamos. Desde ese momento los comprobantes de esa empresa tienen valor fiscal |

Cada empresa avanza por su cuenta: puede tener unas en producción y otras todavía en
certificación, con la misma credencial y sin tocar su código (consulte `GET
/api/integracion/empresas`: el campo `ambiente` dice en qué está cada una).

> **Mientras certificamos una empresa, no emita pruebas con ella.** La DGII reinicia todas las
> pruebas de esa empresa si rechaza **cualquier** comprobante, incluido uno de prueba suyo. Le
> avisamos cuándo empezamos y cuándo terminamos con cada una.

> **Al pasar a producción, use los rangos de e-NCF de producción.** Los que se consumieron en
> la certificación son de pruebas; la numeración fiscal empieza en el rango que la DGII
> autoriza para producción.

Al pasar a producción, sus bandejas dejan de mostrar los documentos de certificación: no se
mezclan los dos ambientes.

### Lista de verificación antes de salir a producción

- [ ] Cada llamada lleva el RNC de la empresa: `emisor.rnc` al emitir, `?rnc=` al consultar, `rnc_comprador` al aprobar
- [ ] El `e_ncf` se genera desde un contador propio **por empresa y por tipo**, dentro del rango autorizado, sin repetir
- [ ] `fecha_vencimiento_secuencia` se envía en todos los tipos salvo E32/E34 y corresponde al rango autorizado en **producción**
- [ ] Las fechas van como `DD-MM-AAAA`, con guiones
- [ ] Se persisten `xml_firmado`, `track_id`, `codigo_seguridad` y `fecha_emision` de cada emisión
- [ ] Un comprobante se da por válido solo con `ACEPTADO`, `ACEPTADO_CONDICIONAL` o `RFCE_ACEPTADO` (no con `ENVIADO`)
- [ ] El estado se consulta solo para comprobantes pendientes, con al menos 1 minuto entre consultas
- [ ] `nombre_item` ≤ 80 caracteres, `descripcion` ≤ 1000, validado en el formulario
- [ ] Los textos se normalizan a `\n` (sin `\r`) antes de enviar
- [ ] Ante un `502` se reenvía con el mismo `e_ncf`, nunca con el siguiente sin confirmarlo con Gratex
- [ ] Las facturas recibidas se reconcilian con las bandejas, aunque use webhook
- [ ] Si usa webhook: se valida `X-Gratex-Signature` con el WEBHOOK SECRET de cada empresa y se rechazan los avisos sin firma o con firma inválida
- [ ] El API SECRET está en un gestor de secretos, no en el repositorio, y solo se usa desde su servidor (nunca en el navegador ni en apps móviles)
- [ ] La Representación Impresa incluye QR, código de seguridad y fecha de firma

---

## Soporte

Escriba a **info@gratex.net** con el RNC de la empresa, el `e_ncf` y el `track_id` del
comprobante involucrado (o el `codigo_seguridad` en E32 de menos de RD$250,000): con esos datos
podemos rastrear cualquier emisión.
