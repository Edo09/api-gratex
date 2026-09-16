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

La página mide lo mismo que el rollo, y el contenido va dentro del ancho que el
cabezal realmente imprime. Que la página coincida con el papel es lo que deja
imprimir al 100 % sin que el driver reescale ni recorte.

| Rollo | Margen por lado | Ancho útil | Pensado para |
|---|---|---|---|
| 80 mm | 4 mm | 72 mm | térmicas de 80 mm: casi ninguna imprime más de 72 mm (576 puntos a 203 dpi) |
| 76 mm | 6 mm | 64 mm | impresoras de impacto tipo Epson TM-U220, que imprimen ~63,5 mm |
| 72 mm | 4 mm | 64 mm | rollo angosto; el mismo margen que en 80 mm para no depender de un cabezal que imprima hasta el borde |

**Para ajustar un modelo** que corte por los lados o deje demasiado blanco se toca
solo `ReciboPos::MARGENES`: todo el dibujo sale del ancho útil. Para agregar un
ancho nuevo basta una fila ahí (y el valor en `AnchoTirilla` del frontend).

Qué cambia con el ancho y qué no:

- **Escalan** las dos columnas con medida fija, pensadas sobre 72 mm útiles: el
  detalle (44 mm para "cant × precio", el resto para el valor) y los totales
  (38 mm para la etiqueta). En 80 mm salen idénticas a las de antes.
- **No escalan** el QR (26 mm: su tamaño es lo que asegura que el lector lo
  tome, y cabe en 64 mm), el logo (caja de 34 × 14 mm) ni los tamaños de letra.
- **Totales:** la columna de etiquetas cede cuando un monto no cabe en la suya.
  En Courier, `RD$99,999,999.99` en negrita mide 30,5 mm y en 64 mm útiles no
  entra en la proporción; las etiquetas son cortas y sí caben en lo que queda.
- **Pares etiqueta: valor** (e-NCF, fechas, comprador): una etiqueta que no cabe
  en el 55 % del ancho va en su propio renglón con el valor debajo. Solo pasa con
  "Identificación Tributaria" (E47) en Courier en 64 mm.

Medido con las métricas de FPDF para Arial, Times y Courier (montos de hasta
`99,999,999.99`): todo cabe en los tres anchos.

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

`FormatoImpresion = 'carta' | 'pos'` y `AnchoTirilla = 72 | 76 | 80` en
`src/api/types.ts`. Botón **Imprimir recibo {ancho} mm** (icono `printer`) junto
al de PDF en:

- `InvoiceDetailView` — factura e-CF ya emitida (adonde lleva el formulario tras
  emitir, que es el momento en que se entrega el papel al cliente).
- `SimpleInvoiceListView` — acción por fila.
- `SimpleInvoiceFormView` — documento guardado o vista previa de lo editado,
  siguiendo la misma lógica que el botón de la hoja.

### Ancho por equipo

El ancho se elige en **Configuración → Impresora de recibos** y se guarda en el
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
"abrir pestaña, Ctrl+P, volver" son dos pasos de más con el cliente delante. Lo
hace `printDocument()` (`src/lib/file.ts`): carga el PDF en un iframe oculto y
llama a `print()` sobre él. La hoja carta sigue abriéndose en una pestaña, que es
lo que se quiere cuando se trata de revisarla.

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

La página del PDF ya mide el ancho del rollo × lo que ocupe, así que en el
diálogo hay que poner **escala 100 % / tamaño real** (no "ajustar a la página")
y, en el driver de la impresora, papel en rollo del mismo ancho y márgenes en
cero. Con el papel puesto en A4 o carta el navegador reescala: el recibo sale
diminuto en medio de la hoja, o enorme si se reimprime a carta con "ajustar"
(ese PDF lo delata: `Producer` de Windows y `MediaBox` de 612 × 792 pt).

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
