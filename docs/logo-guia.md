# Guía del Logo del Tenant (Representación Impresa)

Especificaciones para que el logo de un tenant se vea bien en facturas y
cotizaciones PDF. Para compartir con el cliente durante el onboarding.

## Especificación recomendada (sirve para todas las plantillas)

| Propiedad | Valor |
|---|---|
| Proporción | **~3:1 horizontal** (ancho : alto) |
| Formato | **PNG con fondo transparente** (JPG aceptado, pero sin transparencia) |
| Tamaño | **≥ 1000 px de ancho** (ideal 1300 × 400 px) |
| Peso máximo | 2 MB (lo valida el endpoint) |
| Composición | **Lockup horizontal**: isotipo a la izquierda + nombre al lado |

Subida: `POST /api/branding/logo` (multipart, campo `logo`, token del tenant)
o `public/upload_logo.php` (herramienta de operaciones, cualquier tenant).
Se guarda como `logos/<tenant_id>.<ext>` y se registra en `tenants.logo_path`.

## Caja del logo por plantilla

El motor ajusta cualquier logo DENTRO de su caja preservando la proporción
(`FacturaTemplate::drawLogo()`): un logo ancho llena el ancho; uno alto se
limita por altura. Nunca se desborda sobre el bloque del emisor ni la tabla.

| Plantilla | Caja máxima (mm) | Proporción ideal | Píxeles recomendados |
|---|---|---|---|
| `clasico` | 65 × 18 | ~3.5 : 1 | 1300 × 360 |
| `moderno` | 50 × 16 (sobre recuadro blanco en la banda) | ~3 : 1 | 1200 × 400 |
| `compacto` | 45 × 14 | ~3 : 1 | 1100 × 350 |

Un solo archivo ~3:1 funciona bien en las tres.

## Texto al lado, NO debajo del logo

**Usar lockup horizontal (isotipo + nombre al lado).** Razones:

- La caja es ~3:1. Un logo cuadrado o apilado (texto debajo, ~1:1) se reduce
  por altura y queda diminuto (~18 mm de ancho en clasico).
- El lockup horizontal llena los 65 mm a altura completa — máxima presencia
  de marca.

Si el cliente solo tiene logo cuadrado/apilado: rearmar el lienzo como
isotipo a la izquierda + nombre a la derecha en ~3:1, exportar PNG
transparente y resubir.

## Detalles técnicos

- FPDF embebe el archivo tal cual: más resolución = impresión más nítida
  (1300 px de ancho ≈ 300 DPI a 65 mm). No subir menos de ~600 px de ancho.
- Validación del endpoint: extensión png/jpg/jpeg + MIME real
  (`getimagesize`) + máx 2 MB. Cero bytes y archivos no-imagen se rechazan
  con 422 (ver `src/Utils/LogoStorage.php`).
- Fondos blancos sólidos se notan en `clasico`/`compacto` (dibujan sobre la
  página) — preferir transparencia. En `moderno` el logo va sobre un recuadro
  blanco dentro de la banda de color, así que ahí el fondo blanco no se nota.
- Probar sin persistir nada: `POST /api/branding/preview` después de subir
  el logo (el preview usa el logo ya guardado del tenant).

Relacionado: `docs/plantillas-factura.md` (plantillas y branding completo).
