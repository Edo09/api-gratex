# Representación Impresa en tirilla POS (80, 76 y 72 mm)

Cualquier comprobante que se pueda imprimir en hoja carta se puede imprimir
también en tirilla de rollo de 80, 76 o 72 mm. **Mismo contenido fiscal, otro papel**: no
es un documento distinto ni un "resumen", es la misma Representación Impresa con
los mismos datos que exige la DGII, apilados en una sola columna.

Se pide con `?formato=pos` (80 mm), `pos76` o `pos72` en los endpoints de PDF que ya existían. No hay ruta
nueva — a propósito: una ruta nueva habría que mapearla en
`config/permissions.php` y ese olvido ya costó un 500 en producción (ver
`docs/modules/roles-permisos.md`).

## Endpoints

| Endpoint | Formato POS |
|---|---|
| `GET /api/facturas/{id}/pdf` | `?formato=pos76` |
| `POST /api/facturas/preview` | `?formato=pos76` o `{"formato":"pos76"}` |
| `GET /api/facturas-simples/{id}/pdf` | `?formato=pos76` |
| `POST /api/facturas-simples/preview` | `?formato=pos76` o `{"formato":"pos76"}` |

Valores aceptados (`RepresentacionImpresa::interpretarFormato`):

| Valor | Resultado |
|---|---|
| `pos`, `tirilla`, `termica` | 80 mm — los de antes de haber varios anchos, siguen igual |
| `pos80`, `80mm`, `80` | 80 mm |
| `pos76`, `76mm`, `76` | 76 mm |
| `pos72`, `72mm`, `72` | 72 mm |
| cualquier otro (`pos58`, `carta`…) o ninguno | hoja carta de siempre |

El archivo descargado lleva el sufijo `_POS80`, `_POS76` o `_POS72` para que los
formatos de la misma factura no se pisen y el nombre diga qué ancho salió.

### `format=datos`: el recibo como datos

Con `format=datos` (query o cuerpo) y un `formato` de tirilla, los cuatro
endpoints devuelven, en vez del PDF, los datos del recibo para
[imprimirlo como página web](#impresión-como-página-web):
`{status:true, data: ReciboDatos}`. Sin `formato` de tirilla responden `422`.

`ReciboPos::datos()` arma los textos **ya formateados** con los mismos helpers
que dibujan el PDF (`paresIdentificacion`, `paresReceptor`, `textoCantidadPrecio`,
`textoItbis`, `textoTotal`, `contactoEmisor`), así que las dos salidas dicen lo
mismo. El logo y el QR van como data URI (PNG/JPG), para que la página no tenga
que pedir nada más. Forma (tipo `ReciboDatos` en `src/api/types.ts` del front):

```
nombre            "Factura_E310000000011_POS80"
papel             { opcion: 80, ancho_mm: 72, margen_mm: 1 }
fuente            "Arial" | "Times" | "Courier"
logo              data URI | null
emisor            { razon_social, rnc: "RNC: …", direccion, contacto: "Tel.: … - correo" }
titulo            "FACTURA DE CRÉDITO FISCAL ELECTRÓNICA"
identificacion    [["e-NCF","E31…"], ["Fecha de Emisión","…"], …]
receptor          { pares: [["RNC Cliente","…"], ["Razón Social","…"]], contacto } | null
lineas            [{ descripcion, cantidad_precio: "1 UND x 950.00", itbis: "ITBIS 171.00" | "", valor: "950.00" }]
motivo            "" | motivo E33/E34 que no cupo en una línea
totales           [{ etiqueta: "Total", valor: "RD$1,121.00", total: true }, …]
timbre            { qr: data URI | null, aviso_preview, codigo_seguridad, fecha_firma } | null
leyenda_qr        "Consulte la validez…" | "" (no electrónica)
gracias           "¡Gracias por su compra!"
```

## Arquitectura

```
EcfDocumento          QUÉ dice el comprobante (norma DGII)
    ├── FacturaPdfGenerator   -> hoja carta 8½×11 (+ plantillas por tenant)
    └── ReciboPos             -> tirilla de 80, 76 o 72 mm
RepresentacionImpresa   lee ?formato y elige carta o tirilla (y su ancho)
```

- **`src/Utils/Pdf/EcfDocumento.php`** — el contenido, sin nada de dibujo:
  título por tipo de e-CF, e-NCF y fechas, receptor (con las reglas de E43 y
  E47), líneas normalizadas con su ITBIS y su sigla de unidad, totales tomados
  del XML firmado, motivo y NCF modificado de las notas E33/E34, y la URL del
  timbre. **Toda regla de la norma se cambia aquí y sale en los dos formatos.**
  Si se duplicara, un cambio de norma se aplicaría en uno y no en el otro, y la
  divergencia solo se vería cuando la DGII rechace un timbre impreso.
- **`src/Utils/Pdf/ReciboPos.php`** — solo disposición: ancho del rollo, ancho
  útil y alto variable (ver [Anchos](#anchos)).
- **`src/Utils/Pdf/RepresentacionImpresa.php`** — `anchoPos()` / `generar()` /
  `sufijo()`. Es lo único que tocan los controllers. `interpretarFormato()` lo
  usa también `tools/ri_desde_xml.php`, que no tiene request.

## Por qué el alto es variable

Una tirilla no tiene "hoja": el rollo es continuo y la impresora corta al final
del contenido. Por eso `ReciboPos` dibuja **dos veces** — una pasada de
medición sobre un lienzo largo para saber dónde termina el contenido, y la
definitiva sobre una página de ese alto exacto. Con una altura fija, el driver
de la térmica alimenta papel en blanco hasta completar la página y corta lejos
del último renglón.

Topes: mínimo 80 mm y nunca menos que el ancho (FPDF ordena `[ancho, alto]` de
menor a mayor: una página más ancha que alta saldría girada), máximo 5000 mm (el
formato PDF no admite páginas de más de 14400 unidades de 1/72", ≈ 5080 mm). Una
factura de 60 líneas da ≈ 72 cm.

## Anchos

**La página tiene que medir lo que el driver deja imprimir, no lo que mide el
rollo.** Con "Tamaño real", Chrome pega la página del PDF al borde izquierdo del
área imprimible y corta lo que pase de su ancho: no la centra ni la reescala.
Se vio en dos impresoras con la primera versión, que usaba página del ancho del
rollo con 4–6 mm de margen:

- Epson TM-U220, papel `76(63.5) x 3276 mm`: 6 mm en blanco a la izquierda y
  6,5 mm cortados a la derecha — el nombre del emisor, la columna VALOR y el total.
- Térmica de 80 mm, papel `80(72.1) x 297 mm`: 4 mm en blanco a la izquierda y
  la columna VALOR y el total cortados.

| Opción | Página | Margen por lado | Ancho útil | Estado |
|---|---|---|---|---|
| 80 mm | 72 mm | 1 mm | 70 mm | verificado (`80(72.1)`) |
| 76 mm | 63,5 mm | 1 mm | 61,5 mm | verificado con TM-U220 (`76(63.5)`) |
| 72 mm | 64 mm | 1 mm | 62 mm | supuesto: 8 mm menos que el rollo, como la de 80 — sin verificar |

**Para ajustar un modelo** se toca solo `ReciboPos::MEDIDAS`: el número entre
paréntesis del papel en el diálogo de impresión (`76(63.5)`, `80(72.1)`…) es el
ancho de página correcto. Ante la duda, quedarse corto: una página más angosta
que el área imprimible solo deja blanco a la derecha; una más ancha corta los
montos. Por eso la de 80 usa 72 y no 72,1. Para agregar una opción basta una
fila ahí (y el valor en `AnchoTirilla` del frontend).

Qué cambia con el ancho y qué no:

- **Escalan** las dos columnas con medida fija, pensadas sobre 72 mm útiles: el
  detalle (44 mm para "cant × precio", el resto para el valor) y los totales
  (38 mm para la etiqueta).
- **No escalan** el QR (26 mm: su tamaño es lo que asegura que el lector lo
  tome, y cabe en el ancho útil más angosto, 61,5 mm), el logo (caja de 34 × 14 mm) ni los tamaños de letra.
- **Totales:** la columna de etiquetas cede cuando un monto no cabe en la suya.
  En Courier, `RD$99,999,999.99` en negrita mide 30,5 mm y en 64 mm útiles no
  entra en la proporción de 61,5 mm; las etiquetas son cortas y sí caben en lo que queda.
- **Pares etiqueta: valor** (e-NCF, fechas, comprador): una etiqueta que no cabe
  en el 55 % del ancho va en su propio renglón con el valor debajo. Solo pasa con
  "Identificación Tributaria" (E47) en Courier, en las tres opciones.

Medido con las métricas de FPDF para Arial, Times y Courier (montos de hasta
`99,999,999.99`): todo cabe en los tres anchos.

**QR en impresoras de impacto.** La URL del timbre (~190 caracteres, corrección
M) da un QR de unos 57 módulos: en 26 mm, ≈0,44 mm por módulo. Una TM-U220
imprime a 72 dpi verticales (0,35 mm por punto), poco más de un punto por
módulo, así que hay que comprobar con el celular que el QR impreso escanee. El
Código de Seguridad y la Fecha de Firma salen igual aunque el QR no se lea.

## Qué cambia respecto a la hoja carta

| | Carta | Tirilla |
|---|---|---|
| Detalle | 6 columnas en una tabla | 2 renglones por línea: descripción completa arriba; `cant UND × precio (ITBIS)` a la izquierda y el valor a la derecha |
| Emisor | logo y datos a la izquierda | logo y datos centrados |
| Timbre | QR 30 mm a la izquierda, datos al lado | QR 26 mm centrado, datos debajo |
| Paginación | "Página X de Y" | no aplica (una sola página) |
| Acento del tenant | colorea bandas y totales | no se usa: una térmica imprime en un solo tono y un fondo oscuro solo gasta cabezal |
| Firmas y sello | sí | no (no caben ni se firman en mostrador) |

La familia tipográfica sí se respeta (`font_family` de la plantilla del tenant),
por si un cliente quiere Courier para que parezca ticket.

## Reglas duras

- Los seis datos por línea que exige la norma (cantidad, descripción, unidad,
  precio, ITBIS, valor) tienen que seguir apareciendo. En 72 mm no caben en
  columnas, así que se apilan — **cambian de sitio, no desaparecen.**
- El QR es opcional en el sentido de que sin `phpqrcode` o sin GD no se dibuja,
  pero el **Código de Seguridad y la Fecha de Firma se imprimen igual**: son
  datos exigidos y no dependen de que la imagen se pueda armar. Una factura ya
  emitida siempre debe poder reimprimirse.
- El ancho útil de cada rollo no se amplía a ojo: si pasa de lo que imprime el
  cabezal, el PDF se ve bien en pantalla pero el papel sale recortado — y lo que
  va pegado al borde derecho son los montos. Ver [Anchos](#anchos).

## Frontend

`FormatoImpresion = 'carta' | 'pos'`, `AnchoTirilla = 72 | 76 | 80`,
`ModoImpresion = 'web' | 'pdf'` y `ReciboDatos` en `src/api/types.ts`. Botón
**Imprimir recibo {ancho} mm** (icono `printer`) junto al de PDF en los sitios de
abajo; los tres llaman a `imprimirRecibo()` (`src/features/invoices/imprimirRecibo.ts`),
que decide entre página web y PDF según el modo configurado:

- `InvoiceDetailView` — factura e-CF ya emitida (adonde lleva el formulario tras
  emitir, que es el momento en que se entrega el papel al cliente).
- `SimpleInvoiceListView` — acción por fila.
- `SimpleInvoiceFormView` — documento guardado o vista previa de lo editado,
  siguiendo la misma lógica que el botón de la hoja.

### Ancho por equipo

El ancho y el modo (página web, por defecto, o PDF) se eligen en
**Configuración → Impresora de recibos** y se guardan en el
navegador (`src/stores/impresora.ts`, clave `fiscalo.impresora`), **no** en el
backend: el rollo es de la impresora conectada a cada caja, y un mismo negocio
puede tener una térmica de 80 mm en una caja y una de impacto de 76 en otra. Por
defecto, 80 mm; un valor guardado que no sea uno de los anchos también cae a 80.

El botón y la petición leen el mismo store, así que el rótulo dice siempre el
ancho que se va a pedir. `parametroFormato()` (`src/api/impresion.ts`) arma el
parámetro: **80 mm se sigue pidiendo como `pos` a secas**, igual que antes de
haber varios anchos, para que el caso de siempre no dependa del orden de
despliegue. `pos76` y `pos72` sí necesitan este backend: una versión anterior
no los reconoce y devuelve la hoja carta. **Desplegar el backend antes que el
frontend.**

## Impresión

El botón de tirilla **abre el diálogo de impresión directamente** — en mostrador,
"abrir pestaña, Ctrl+P, volver" son dos pasos de más con el cliente delante. La
hoja carta sigue abriéndose en una pestaña, que es lo que se quiere cuando se
trata de revisarla.

### Impresión como página web

Es el modo por defecto. Imprimiendo un PDF, Chrome usa el **tamaño de papel del
driver** (ver [Ajustes](#ajustes-de-la-impresora-una-sola-vez)): sale papel en
blanco hasta completar la hoja, o se corta una factura más larga que ella. Una
página HTML puede decirle al navegador el tamaño de la hoja con `@page { size }`.

1. `imprimirRecibo()` pide `format=datos` y `reciboHtml()`
   (`src/features/invoices/reciboHtml.ts`) arma la página: mismos tamaños de letra
   y altos de renglón que `ReciboPos`, todo el texto escapado, y solo imágenes
   `data:image/png|jpeg;base64`. El texto que no cabe se parte solo, así que no hay
   que medir etiquetas ni montos como en el PDF.
2. `printHtml()` (`src/lib/printHtml.ts`) la carga en un iframe **con el ancho del
   papel** (los saltos de línea dependen de él), espera imágenes y fuentes, mide
   el alto de `.recibo` y agrega `@page { size: <ancho>mm <alto>mm; margin: 0 }`
   (`reglaPagina`: alto redondeado hacia arriba + 1 mm, para que una fracción de
   más no abra una segunda hoja casi vacía; nunca menor que el ancho).
3. Llama a `print()` sobre el iframe. Si el navegador no lo deja, abre la página
   —ya con la regla `@page`— en otra pestaña para imprimirla con Ctrl+P.

Verificado con Chrome 152 headless (`--print-to-pdf`, que usa el mismo motor de
impresión) sobre el HTML real, con datos de la factura E310000000011:

| Caso | Alto medido | Hoja resultante |
|---|---|---|
| opción 80 (72 mm), 1 línea, Times | 143,3 mm | **1 hoja** de 71,97 × 144,95 mm |
| opción 76 (63,5 mm), 1 línea, Courier | 153,1 mm | **1 hoja** de 63,50 × 154,86 mm |
| opción 72 (64 mm), 40 líneas con montos de 10 cifras, Arial | 491,6 mm | **1 hoja** de 63,84 × 492,84 mm |

Chrome redondea a píxeles enteros: la hoja puede quedar una fracción de milímetro
más angosta que lo pedido, nunca más ancha. **Lo que falta verificar es el driver**:
que la impresora use ese tamaño de hoja en vez del papel configurado. Si en algún
equipo no lo hace, el modo **PDF** de Configuración vuelve al comportamiento
anterior sin desplegar nada.

### Impresión como PDF

`printDocument()` (`src/lib/file.ts`) carga el PDF en un iframe oculto y llama a
`print()` sobre él.

Dos trampas que ya están resueltas ahí, no volver a caer:

- **El `load` del `about:blank`.** Un iframe recién insertado dispara `load` por
  su documento inicial vacío, antes de que llegue el blob. Sin comprobar que
  `contentWindow.location` ya sea `blob:`, lo que se manda a imprimir es una hoja
  en blanco. Verificado en navegador.
- **Quitar el iframe demasiado pronto.** Si se elimina mientras el diálogo sigue
  abierto, Chrome cancela la impresión. Se retira 60 s después.

Si el navegador niega `print()` sobre el iframe (Safari, algunos bloqueadores),
cae a abrir el documento y avisa al usuario — es mejor eso que un botón que
aparenta funcionar y no imprime nada.

### Ajustes de la impresora (una sola vez)

En modo **página web**, dejar la escala en «Predeterminado» (100 %). Lo que sigue
es para el modo **PDF**.

La página del PDF ya mide el ancho que imprime el driver × lo que ocupe, así que
en el diálogo hay que poner **escala 100 % / tamaño real** (no "ajustar a la
página") y el papel en rollo de esa impresora. Con el papel puesto en A4 o carta
el navegador reescala: el recibo sale diminuto en medio de la hoja, o enorme si
se reimprime a carta con "ajustar" (ese PDF lo delata: `Producer` de Windows y
`MediaBox` de 612 × 792 pt).

**El largo lo decide el papel del driver, no el PDF.** El PDF mide lo que ocupa
el contenido (una factura de una línea, ≈148 mm), pero Chrome imprime sobre una
hoja del tamaño de papel elegido: con `80(72.1) x 297 mm` la impresora saca
297 mm aunque el recibo mida 148. Imprimiendo un PDF, la aplicación no puede
fijar ese largo. Se resuelve en las preferencias del driver, activando la opción
que elimina el blanco del final (en los drivers de Epson suele llamarse «Paper
Reduction» / «Reducción de papel», margen inferior). Con eso activo conviene el
papel largo (`x 3276 mm`). Con `x 297 mm` no cabe una factura de más de 297 mm:
~148 mm de encabezado, totales y timbre más 7,2 mm por línea (10,4 si la
descripción ocupa dos renglones), o sea unas 21 líneas, 15 con descripciones
largas. El PDF es **una sola página**, y Chrome imprime una hoja por página: no
la reparte en dos hojas. Con "Tamaño real" se pierde lo que pase de la hoja —
el final, donde van los totales, el QR y el código de seguridad—; con "Ajustar"
encoge todo el recibo para que quepa. Se comprueba sin gastar papel en la vista
previa del diálogo con una factura larga. Sin la opción de reducción, el papel
largo sacaría metros en blanco: probar primero.

## Reimprimir desde el XML firmado (CLI)

Cuando lo único que hay es el XML del comprobante — un respaldo, un e-CF recibido
de otro contribuyente, un tenant de `integracion` que no tiene BD — la RI se
genera sin tocar la base de datos:

```
php tools/ri_desde_xml.php factura.xml [--out=ruta.pdf] [--formato=carta|pos|pos76|pos72] [--ambiente=ecf|certecf|testecf]
```

Imprime en pantalla la URL del timbre, que es la forma rápida de comprobar que la
DGII reconoce el comprobante sin escanear el papel.

Todo sale del XML: el código de seguridad son los primeros 6 caracteres del
`SignatureValue` (igual que al emitir), los totales y la razón social del
comprador ya los tomaba `EcfDocumento` del XML firmado, y el vencimiento sale de
`FechaVencimientoSecuencia` en vez de calcularse como 31/12 del año de emisión.

Tres cosas que hacen falta para que el papel diga la verdad:

- **`$factura['emisor']`** manda sobre `emisor_config`. Sin eso, el impreso
  llevaría el emisor del tenant conectado (o los datos históricos de Gratex en
  CLI) sobre el comprobante de otro. Con emisor explícito, lo que el XML no traiga
  queda en blanco: no se completa con datos ajenos.
- **`BrandingResolver::sinMarcaGlobal()`** apaga `logo2020.png` y `sello.png`,
  que son los de Gratex y sin tenant resuelto serían el fallback.
- **Sin logo se escribe la razón social** en su lugar (`drawLogoOrNombre`). En
  `clasico` y `compacto` el nombre del emisor vivía solo dentro de la imagen del
  logo, así que sin logo la factura no decía quién la emitía — y la norma exige
  identificar al emisor. Esto aplica también a cualquier tenant que nunca subió
  su logo.

### El ambiente lo fija el emisor, no el servidor

El ambiente decide la ruta del QR (`https://ecf.dgii.gov.do/<ambiente>/ConsultaTimbre`)
y equivocarlo imprime un timbre que no resuelve. El orden es:

1. `--ambiente` explicito.
2. **El del emisor del documento**: `master.tenants.ambiente` buscado por
   `RNCEmisor`. Es el unico correcto por definicion — un tenant en certificacion
   necesita `CerteCF` aunque el servidor donde se reimprime este en produccion.
3. El global del `.env`, si el master no responde.
4. `ecf`, avisando por stderr.

`AmbienteResolver` **no** sirve aqui: en CLI no hay request, no hay tenant
resuelto y devuelve el global del servidor (`ecf` en produccion) para todos. Ese
era el defecto original del tool: la RI de un tenant en certificacion salia con
la URL de produccion, sin avisar.

La linea `Ambiente:` de la salida dice de donde salio (`--ambiente`, `tenant
<rnc>`, `.env del server` o `supuesto`), para no tener que adivinarlo.

La sigla de la unidad de medida sale del catálogo `unidades_medida`: corriendo
fuera del servidor (sin BD) se imprime el código DGII crudo, p. ej. `43`.
