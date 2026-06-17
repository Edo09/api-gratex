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
| POST | `/api/branding/preview` | `{template?, accent_color?, no_electronica?, grid?}` | PDF de muestra base64 (`?format=download`), **sin persistir**. `grid:true` superpone una rejilla de calibración (ver replicación abajo). |

La herramienta de operaciones `public/upload_logo.php` (token propio) sigue
funcionando y puede fijar el logo de cualquier tenant (útil en onboarding de
integración). Ambas vías comparten `src/Utils/LogoStorage.php`.

Dimensiones y composición del logo (cajas por plantilla, proporción ~3:1,
lockup horizontal): ver `docs/logo-guia.md`.

## Diseños a la medida (`custom:*`)

Cuando un cliente pide su propio formato de factura:

1. **Generar el andamiaje:** `php tools/new_custom_template.php <id>` crea
   `src/Utils/Pdf/Custom/Tenant<id>Template.php` (clase `Tenant<id>Template`,
   anotada y lista para personalizar; rehúsa sobrescribir). Convención de
   nombre: `custom:tenant<id>` → `Tenant<id>Template.php` (snake_case →
   StudlyCaps + `Template`). La base manual es
   `src/Utils/Pdf/Custom/EjemploTemplate.php` si prefieres copiar a mano.
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

## Replicar el formato existente de un cliente (PDF/escaneo)

Caso típico: el cliente ya tiene su factura impresa y quiere que su
Representación Impresa se vea igual. No es una capacidad nueva del motor — es
una plantilla `custom:tenant<id>` que **calca** su diseño. Lo que se reproduce
es lo **visual**; el contenido obligatorio DGII lo sigue poniendo el motor (ver
"Reglas duras").

**Lo que se puede calcar vs. lo que es fijo**

| Se puede calcar (visual) | Fijo por norma (no se mueve/cubre/quita) |
|---|---|
| Logo y su posición/tamaño | QR del timbre (y≈205, x=8, 30 mm) |
| Colores (acento) y tipografías core | Cuadro de totales (anclado a y=-40) |
| Disposición del encabezado y del pie | Las 6 columnas obligatorias de items |
| Firmas/sello, reglas, banda de tabla | Paginación "Página X de Y" |
| Fuentes/interlineado (`style()`), márgenes verticales (`layout()`) | Etiquetas y orden de columnas/totales (los fija el motor) |

Si el formato del cliente choca con un elemento fijo, **gana la norma**: se le
explica el límite, no se fuerza.

**Pasos**

1. **Intake.** Conseguir el formato del cliente — lo usual es un **PDF** de una
   factura ya impresa o un **escaneo/imagen**. Identificar: logo, paleta de
   colores (en hex), tipografías (se mapean a las core de FPDF), y los bloques
   (encabezado, tabla, totales, pie, firmas, notas).
2. **Medir.** La página es **Letter = 215.9 × 279.4 mm**, origen (0,0)
   arriba-izquierda, todo en mm.
   - De un **PDF**: medir posiciones y márgenes en mm directamente (visor con
     regla, o exportar a imagen a 300 dpi: 1 mm ≈ 11.81 px).
   - De un **escaneo/imagen** (sin medidas exactas): encender la **rejilla de
     calibración** (abajo), superponer la vista previa sobre la imagen del
     cliente y leer las coordenadas a ojo; se afina iterando.
3. **Generar.** `php tools/new_custom_template.php <id>` crea
   `src/Utils/Pdf/Custom/Tenant<id>Template.php` (extiende `ClasicoTemplate`, ya
   imprime; cada hook trae un ejemplo comentado). Rellenar hook por hook:
   `drawCompanyHeader` (¡ambas variantes, `factura` **y** `cotizacion`!),
   `drawFooter`, `drawItemsTableHeader`, `drawTotals`, `style()`, `layout()`.
   Recordar: solo fuentes core + Arial Narrow con guard; color sobre acento
   siempre con `textOver()`.
4. **Rejilla de calibración.**
   `POST /api/branding/preview {"template":"custom:tenant<id>","grid":true}`
   superpone una rejilla cada 10 mm con números en cm. Imprescindible para el
   caso escaneo/imagen; quitar `"grid"` para ver el resultado limpio. La rejilla
   **nunca** aparece en una factura real (solo cuando el preview la pide).
5. **Activar.** `UPDATE tenants SET pdf_template='custom:tenant<id>' WHERE id=<id>;`
   o `PUT /api/branding {"template":"custom:tenant<id>"}`, o el cliente hace
   clic en la tarjeta **"A la medida"** en Configuración → Plantillas PDF.
6. **Verificar.** Vista previa sin rejilla + el **checklist DGII** (emisor,
   e-NCF/fechas, receptor, 6 columnas, totales, QR + código de seguridad +
   fecha firma, NCF Modificado en notas, "Página X de Y"). Confirmar que la
   **cotización** del mismo tenant también se ve bien (usa la misma plantilla,
   variante `cotizacion`).

## Reglas duras (no negociables)

- Fuentes: solo core de FPDF (Arial/Helvetica/Times/Courier) + la Arial Narrow
  vendorizada (`vendor/fpdf/font/arial-narrow.php`); cargarla con guard
  (`is_file` + try/catch), como hace `CompactoTemplate::narrowFont()`.
- No mover/cubrir/escalar la zona del QR del timbre (y≈205, x=8, 30 mm) ni el
  cuadro de totales (anclado a y=-40): el motor los dibuja en posición fija.
- Texto sobre acento: usar `textOver()` — nunca un color de texto fijo.
- DB: el branding vive en **master** (`tenants`), no en la DB del tenant
  (los tenants tipo `integracion` no tienen DB propia).
