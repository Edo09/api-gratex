# Representación Impresa en tirilla POS (80 mm)

Cualquier comprobante que se pueda imprimir en hoja carta se puede imprimir
también en tirilla térmica de 80 mm. **Mismo contenido fiscal, otro papel**: no
es un documento distinto ni un "resumen", es la misma Representación Impresa con
los mismos datos que exige la DGII, apilados en una sola columna.

Se pide con `?formato=pos` en los endpoints de PDF que ya existían. No hay ruta
nueva — a propósito: una ruta nueva habría que mapearla en
`config/permissions.php` y ese olvido ya costó un 500 en producción (ver
`docs/modules/roles-permisos.md`).

## Endpoints

| Endpoint | Formato POS |
|---|---|
| `GET /api/facturas/{id}/pdf` | `?formato=pos` |
| `POST /api/facturas/preview` | `?formato=pos` o `{"formato":"pos"}` |
| `GET /api/facturas-simples/{id}/pdf` | `?formato=pos` |
| `POST /api/facturas-simples/preview` | `?formato=pos` o `{"formato":"pos"}` |

Alias aceptados: `pos`, `80mm`, `80`, `tirilla`, `termica`. Cualquier otro valor
(o ninguno) devuelve la hoja carta de siempre. El archivo descargado lleva el
sufijo `_POS80` para que los dos formatos de la misma factura no se pisen.

## Arquitectura

```
EcfDocumento          QUÉ dice el comprobante (norma DGII)
    ├── FacturaPdfGenerator   -> hoja carta 8½×11 (+ plantillas por tenant)
    └── ReciboPos80           -> tirilla 80 mm
RepresentacionImpresa   elige uno de los dos y lee ?formato
```

- **`src/Utils/Pdf/EcfDocumento.php`** — el contenido, sin nada de dibujo:
  título por tipo de e-CF, e-NCF y fechas, receptor (con las reglas de E43 y
  E47), líneas normalizadas con su ITBIS y su sigla de unidad, totales tomados
  del XML firmado, motivo y NCF modificado de las notas E33/E34, y la URL del
  timbre. **Toda regla de la norma se cambia aquí y sale en los dos formatos.**
  Si se duplicara, un cambio de norma se aplicaría en uno y no en el otro, y la
  divergencia solo se vería cuando la DGII rechace un timbre impreso.
- **`src/Utils/Pdf/ReciboPos80.php`** — solo disposición: 80 mm de ancho, 72 mm
  útiles, alto variable.
- **`src/Utils/Pdf/RepresentacionImpresa.php`** — `esPos()` / `generar()` /
  `sufijo()`. Es lo único que tocan los controllers.

## Por qué el alto es variable

Una tirilla no tiene "hoja": el rollo es continuo y la impresora corta al final
del contenido. Por eso `ReciboPos80` dibuja **dos veces** — una pasada de
medición sobre un lienzo largo para saber dónde termina el contenido, y la
definitiva sobre una página de ese alto exacto. Con una altura fija, el driver
de la térmica alimenta papel en blanco hasta completar la página y corta lejos
del último renglón.

Topes: mínimo 80 mm, máximo 5000 mm (el formato PDF no admite páginas de más de
14400 unidades de 1/72", ≈ 5080 mm). Una factura de 60 líneas da ≈ 72 cm.

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
- El ancho es 80 mm y los márgenes 4 mm por lado. Casi ninguna térmica de 80 mm
  imprime más de 72 mm útiles; ampliarlo recorta texto en el papel real aunque
  el PDF se vea bien en pantalla.

## Frontend

`FormatoImpresion = 'carta' | 'pos'` en `src/api/types.ts`. Botón **Imprimir
recibo 80 mm** (icono `printer`) junto al de PDF en:

- `InvoiceDetailView` — factura e-CF ya emitida (adonde lleva el formulario tras
  emitir, que es el momento en que se entrega el papel al cliente).
- `SimpleInvoiceListView` — acción por fila.
- `SimpleInvoiceFormView` — documento guardado o vista previa de lo editado,
  siguiendo la misma lógica que el botón de la hoja.

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

La página del PDF ya mide 80 mm × lo que ocupe, así que en el diálogo hay que
poner **escala 100 % / tamaño real** (no "ajustar a la página") y, en el driver
de la térmica, el papel en rollo de 80 mm. Con el papel puesto en A4 el navegador
reescala y el recibo sale diminuto en medio de la hoja.
