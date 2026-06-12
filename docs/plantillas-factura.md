# Plantillas de Factura (Representación Impresa) por Tenant

Cada tenant elige cómo se ve su factura PDF (y su cotización): una plantilla
predefinida + un color de acento + su logo. Para clientes que pidan un diseño
totalmente a la medida existe la vía `custom:*` (sección final).

## Arquitectura

- **Motor:** `src/Utils/FacturaPdfGenerator.php`. Dueño de TODO el contenido
  exigido por la norma DGII de Representación Impresa: identificación del e-CF
  (título, e-NCF, fechas), receptor, tabla de items con las 6 columnas
  obligatorias (Cantidad | Descripción | Und. Medida | Precio | ITBIS | Valor),
  totales (Subtotal Gravado / Monto Exento / Total ITBIS / Total), QR del
  timbre + Código de Seguridad + Fecha Firma, NCF Modificado (E33/E34) y la
  paginación "Página X de Y".
- **Plantillas:** `src/Utils/Pdf/` — estrategia de dibujo (`FacturaTemplate`).
  Una plantilla solo decide CÓMO se ve cada bloque; por construcción no puede
  eliminar un elemento obligatorio de la RI.
- **Branding:** `BrandingResolver` lee `master.tenants` (`pdf_template`,
  `pdf_accent_color`, `logo_path`). Sin tenant resuelto (single-tenant) usa
  defaults: `clasico`, sin acento, logo global.

## Plantillas predefinidas

| Nombre | Diseño |
|---|---|
| `clasico` | El diseño histórico: logo izquierda, banda de tabla negra, sello + dos firmas. Default de todos los tenants. |
| `moderno` | Banda de acento a todo lo ancho (logo en recuadro blanco, contacto a la derecha), tabla y fila Total en acento, pie con regla fina sin sello. |
| `compacto` | Logo 45 mm, emisor condensado (Arial Narrow 8pt), cuerpo 8.5pt — más líneas por página. Pie mínimo. |

El acento (`pdf_accent_color`, hex `#RRGGBB`) colorea bandas/rellenos; el color
del texto sobre el acento lo decide `BrandingResolver::contrastText()`
(luminancia YIQ) — siempre legible, el tenant no lo elige.

## API — `/api/branding` (token del tenant; solo multi-tenant)

| Método | Ruta | Body | Notas |
|---|---|---|---|
| GET | `/api/branding` | — | `{template, accent_color, logo_path, has_custom_logo, available_templates}` |
| PUT | `/api/branding` | `{template?, accent_color?}` | 422 si plantilla desconocida o hex inválido. `accent_color: null` limpia. |
| POST | `/api/branding/logo` | multipart `logo` | PNG/JPG real (getimagesize), máx 2 MB. Guarda `logos/<tenant_id>.<ext>`. |
| DELETE | `/api/branding/logo` | — | Borra el logo; vuelve al global. |
| POST | `/api/branding/preview` | `{template?, accent_color?, no_electronica?}` | PDF de muestra base64 (`?format=download`), **sin persistir**. |

La herramienta de operaciones `public/upload_logo.php` (token propio) sigue
funcionando y puede fijar el logo de cualquier tenant (útil en onboarding de
integración). Ambas vías comparten `src/Utils/LogoStorage.php`.

Dimensiones y composición del logo (cajas por plantilla, proporción ~3:1,
lockup horizontal): ver `docs/logo-guia.md`.

## Diseños a la medida (`custom:*`)

Cuando un cliente pide su propio formato de factura:

1. **Copiar la base:** `src/Utils/Pdf/Custom/EjemploTemplate.php` →
   `src/Utils/Pdf/Custom/Tenant<id>Template.php` con clase
   `Tenant<id>Template`. Convención de nombre: `custom:tenant<id>` →
   `Tenant<id>Template.php` (snake_case → StudlyCaps + `Template`).
2. **Diseñar** sobreescribiendo los hooks:
   - `drawCompanyHeader($pdf, $emisor, $logoPath, $variant)` — identidad del
     emisor (corre en cada página; `$variant` es `factura` o `cotizacion`).
   - `drawFooter($pdf)` — firmas/sello (el motor agrega la paginación después).
   - `drawItemsTableHeader($pdf, $widths, $labels)` — banda de la tabla
     (anchos y etiquetas los fija el motor: no se puede quitar una columna).
   - `drawTotals($pdf, $filas)` — cuadro de totales (filas DGII del motor).
   - `style()` — `body_font_size`, `line_height`, `title_font_size`.
   - `layout()` — `doc_id_y` (inicio del bloque e-NCF/fechas), `table_start_y`
     (el motor lo acota a [36, 120] mm; la zona de totales/QR es intocable).
3. **Activar:** `UPDATE tenants SET pdf_template = 'custom:tenant<id>' WHERE id = <id>;`
   o `PUT /api/branding {"template": "custom:tenant<id>"}` (la API solo acepta
   la custom del propio tenant; las predefinidas son de todos).
4. **Verificar:** el cliente revisa con
   `POST /api/branding/preview {"template": "custom:tenant<id>"}` antes del
   go-live. Checklist DGII: emisor, e-NCF/fechas arriba a la derecha, receptor,
   6 columnas de items, totales, QR + código de seguridad + fecha firma,
   NCF Modificado en notas, "Página X de Y" en multipágina.

Si el archivo custom falta o la clase no extiende `FacturaTemplate`, el motor
cae a `clasico` — una factura siempre se puede imprimir.

## Reglas duras (no negociables)

- Fuentes: solo core de FPDF (Arial/Helvetica/Times/Courier) + la Arial Narrow
  vendorizada (`vendor/fpdf/font/arial-narrow.php`); cargarla con guard
  (`is_file` + try/catch), como hace `CompactoTemplate::narrowFont()`.
- No mover/cubrir/escalar la zona del QR del timbre (y≈205, x=8, 30 mm) ni el
  cuadro de totales (anclado a y=-40): el motor los dibuja en posición fija.
- Texto sobre acento: usar `textOver()` — nunca un color de texto fijo.
- DB: el branding vive en **master** (`tenants`), no en la DB del tenant
  (los tenants tipo `integracion` no tienen DB propia).
