# Onboarding: replicar el formato de factura de un cliente

Guía operativa paso a paso para cuando un cliente trae **su propia factura ya
impresa** (PDF o escaneo) y quiere que su Representación Impresa (RI) se vea
igual, en vez de usar una de las plantillas predefinidas
(Clásico/Moderno/Compacto).

No es una capacidad nueva del motor: se crea una plantilla `custom:tenant<id>`
que **calca lo visual**. El contenido obligatorio DGII lo sigue poniendo el
motor (e-NCF, columnas, QR, totales, paginación) y **no se puede mover**.

Referencia técnica completa (hooks, arquitectura, reglas duras):
[plantillas-factura.md](plantillas-factura.md) → sección *"Replicar el formato
existente de un cliente"*.

---

## Resumen

Tres fases: **preparar → diseñar (iterar) → activar**, más una verificación
final.

| Fase | Qué haces | Dónde |
|---|---|---|
| 0. Intake | Reunir tenant id + muestra del cliente | — |
| 1. Generar | Crear el archivo de plantilla | local (CLI) |
| 2. Diseñar | Editar y previsualizar con rejilla, iterar | local + server |
| 3. Activar | Apuntar el tenant a la plantilla | SQL / API / UI |
| 4. Verificar | Checklist DGII + cotización | server |

---

## Fase 0 — Intake (antes de tocar código)

- **Tenant id**: `SELECT id, nombre FROM tenants WHERE rnc='...';` en la DB
  master (`mtldtmte_master_gratex`).
- **Muestra del cliente**: un PDF de una factura ya impresa, o un escaneo/foto.
- Identificar: **logo**, **colores** (en hex), **tipografías** (se mapean a las
  core de FPDF: Arial/Helvetica/Times/Courier; no hay fuentes arbitrarias) y los
  **bloques**: encabezado, tabla de items, totales, pie, firmas, notas.

## Fase 1 — Generar la plantilla

```bash
php tools/new_custom_template.php <id>
```

Crea `src/Utils/Pdf/Custom/Tenant<id>Template.php`. Ya es ejecutable (cae al
diseño clásico) y trae un ejemplo comentado en cada hook. Rehúsa sobrescribir si
el archivo ya existe.

## Fase 2 — Diseñar (el loop de iteración)

La máquina local no tiene BD, así que la vista previa corre contra el **server**.
El bucle es: editar → desplegar ese archivo → previsualizar con rejilla → ajustar.

1. **Editar** `Tenant<id>Template.php`, hook por hook:
   - `drawCompanyHeader` — **ambas** variantes: `factura` **y** `cotizacion`.
   - `drawFooter`, `drawItemsTableHeader`, `drawTotals`.
   - Opcional: `style()` (fuentes/interlineado), `layout()` (márgenes verticales).
   - Solo fuentes core + Arial Narrow con guard; color sobre acento siempre con
     `textOver()`.
2. **Desplegar** ese único archivo a
   `/home1/mtldtmte/public_html/api/src/Utils/Pdf/Custom/`.
3. **Previsualizar con la rejilla de calibración** (no hace falta activar
   todavía; la vista previa solo necesita que el archivo exista y que el token
   sea del tenant):
   ```bash
   curl -X POST "https://gratex.net/api/branding/preview?format=download" \
     -H "Authorization: Bearer <tenant-token>" \
     -H "Content-Type: application/json" \
     -d '{"template":"custom:tenant<id>","grid":true}' -o preview.pdf
   ```
   Abrir `preview.pdf`, superponerlo sobre la muestra del cliente y leer las
   coordenadas en la rejilla (líneas cada 10 mm; los números están en cm).
   Ajustar y repetir.
4. Cuando se vea bien, previsualizar una vez **sin** `"grid"` para verlo limpio.

> Coordenadas en mm sobre página Letter (**215.9 × 279.4**, origen arriba-izq).
> Lo que **no** se puede mover/cubrir/quitar: el QR del timbre (y≈205, x=8,
> 30 mm), el cuadro de totales (anclado a y=-40), las 6 columnas obligatorias y
> "Página X de Y". El motor las dibuja en posición fija.

## Fase 3 — Activar (go-live)

Apuntar el tenant a la plantilla, por cualquiera de estas vías:

- **SQL**: `UPDATE tenants SET pdf_template='custom:tenant<id>' WHERE id=<id>;`
- **API**: `PUT /api/branding {"template":"custom:tenant<id>"}` (token del tenant).
- **UI**: el cliente hace clic en la tarjeta **"A la medida"** en
  *Configuración → Plantillas PDF* (aparece sola una vez desplegado el archivo).

## Fase 4 — Verificación final

- Vista previa **sin rejilla** y pasar el **checklist DGII**: emisor,
  e-NCF/fechas, receptor, 6 columnas, totales, QR + código de seguridad + fecha
  firma, NCF Modificado en notas (E33/E34), "Página X de Y" en multipágina.
- Confirmar que una **cotización** del mismo tenant también se ve bien (usa la
  misma plantilla, variante `cotizacion`).

---

## Qué se puede calcar vs. qué es fijo

| Se puede calcar (visual) | Fijo por norma DGII (no se toca) |
|---|---|
| Logo y su posición/tamaño | QR del timbre (y≈205, x=8, 30 mm) |
| Colores (acento) y tipografías core | Cuadro de totales (anclado a y=-40) |
| Disposición de encabezado y pie | Las 6 columnas obligatorias de items |
| Firmas/sello, reglas, banda de tabla | Paginación "Página X de Y" |
| Fuentes/interlineado, márgenes verticales | Etiquetas y orden de columnas/totales |

Si el formato del cliente choca con un elemento fijo, **gana la norma**: se le
explica el límite, no se fuerza.

## Archivos involucrados

- `tools/new_custom_template.php` — generador del andamiaje.
- `src/Utils/Pdf/Custom/Tenant<id>Template.php` — la plantilla del cliente (lo
  que editas).
- `src/Utils/Pdf/Custom/EjemploTemplate.php` — base de referencia.
- `src/Utils/FacturaPdfGenerator.php` — motor (rejilla de calibración:
  `setDebugGrid`).
- `src/Controllers/brandingController.php` — `POST /api/branding/preview`
  (acepta `{"grid":true}`).
