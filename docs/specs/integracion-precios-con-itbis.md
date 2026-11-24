# Precios con ITBIS incluido en `/api/integracion/ecf` — Spec

| | |
|---|---|
| **Estado** | Implementado · 2026-11-24 (falta desplegar) |
| **Repos** | `api-gratex` |
| **Tamaño** | Cambio pequeño: conectar a la rama de integración un cálculo que ya existe. Sin migración |

---

## 1. Problema

El 2026-11-24 AGROVISA FERRETERIA (tenant 18, integración, `ecf`) emitió su primer E31
(E310000000001) y la DGII lo rechazó con 2020 (`TablaSubDescuento`; ver
`docs/integrations/dgii-ecf.md`). Detrás hay un problema del contrato: la integración solo admite
`precio_unitario` **sin ITBIS**, a 4 decimales. El ERP del cliente guarda el precio pactado **con**
ITBIS (200 × RD$16.50 = RD$3,300.00, que es base 2,796.61 + ITBIS 503.39), y el precio neto a 4
decimales (13.9831 × 200 = 2,796.62) se pasa un centavo de la base real. Para cuadrar, su capa de
integración inventa un `descuento_monto` de RD$0.01 en las líneas que se pasan (1, 2 y 9). Sin ese
descuento la factura da RD$10,685.03; con él, RD$10,684.99; lo pactado es RD$10,685.00.

La DGII prevé ese caso: `IndicadorMontoGravado = 1` (los montos de las líneas ya incluyen el ITBIS).
La app y el POS ya lo emiten (`precios_incluyen_itbis`, `EcfItemMapper::map/totales(..., true)`) y la
DGII lo acepta. La integración no lo conecta: `ECFEmissionService` copia `indicador_monto_gravado`
al XML, pero calcula siempre «precio neto + ITBIS encima». Quien lo manda en 1 con precios con ITBIS
firma un e-CF inconsistente: la línea de 200 × 16.50 saldría 3,300.00 + 594.00 = **3,894.00**. Ya
pasó una vez en la app (E310000000011 de la empresa 002, 2026-09-15: precio 950.00, ITBIS 171.00 encima,
total 1,121.00; la DGII lo aceptó condicional con la observación 1934 *«MontoGravadoI1 no coincide con
la sumatoria del detalle»*).

El proveedor del cliente pidió confirmar el contrato antes de quitar su «protección» (el descuento
de redondeo): si el indicador se acepta, cómo se espera el precio y el descuento real, y un ejemplo.

## 2. Qué se quiere

**Objetivos**

1. Un cliente de integración emite E31, E32, E33 o E34 con `precio_unitario` y `descuento_monto`
   **con ITBIS** mandando `indicador_monto_gravado = 1`. El e-CF conserva las cantidades y el total
   es la suma de las líneas (RD$10,685.00 en el caso de AGROVISA), sin descuentos de redondeo.
2. El cálculo es el que la DGII ya validó en el POS (`EcfItemMapper::desglosarIncluido`: gravado por
   tasa = `round(Σ MontoItem / 1.18, 2)`, ITBIS = la diferencia).
3. Un `indicador_monto_gravado = 1` nunca más produce un e-CF inconsistente en emisión normal: o
   cambia el cálculo, o se devuelve un error claro antes de firmar. El set de pruebas
   (`strict_input`) va como llega.

**No-objetivos:** E41 y E45 (el XSD los admite pero no se han verificado con la DGII, igual que en
`TIPOS_PRECIOS_CON_ITBIS`) · un campo nuevo (`precios_incluyen_itbis`) · tocar la ruta app/POS ·
cambiar el comportamiento por omisión (precios sin ITBIS) · precios con ITBIS que no caben en 4
decimales (la línea puede diferir un centavo) · migración.

**Anti-objetivos** (fallaría aunque «funcione»): un indicador copiado al XML sin que el cálculo lo
acompañe (lo de hoy) · que cambie el total de quien no manda el indicador · tocar el set de
certificación (`strict_input`) · aceptar el modo en un tipo que no está verificado.

## 3. Alternativas

| | Qué es | Por qué sí / no |
|---|---|---|
| **A (elegida)** | `indicador_monto_gravado = 1` activa el modo en la emisión normal | Es el campo de la DGII y el que pidió el proveedor. En el log de integración (desde el 14-nov) nadie lo manda en 1 fuera del set de certificación, así que a nadie le cambia el total |
| B | Campo nuevo `precios_incluyen_itbis` (como app/POS) e ignorar el indicador | Consistente con las otras rutas, pero obliga al proveedor a aprender otro nombre para lo que la DGII ya llama `IndicadorMontoGravado`, y la trampa del indicador copiado seguiría salvo que además se fuerce |
| C | No tocar el código; que el cliente mantenga el descuento de RD$0.01 | La factura queda un centavo bajo lo pactado y lleva descuentos que no existen |

Lo pidió el proveedor del cliente (el resultado), así que la alternativa la elegí yo y la aprobó el
usuario el 2026-11-24.

## 4. Diseño

### 4.1 Contrato (`POST /api/integracion/ecf`)

- **Activación:** emisión normal (sin `strict_input`) con `indicador_monto_gravado` en cualquier forma
  que el XSD lee como 1 (`xs:integer` con enumeración 0/1): `1`, `"1"`, `"01"`, `" 1"`, `"+1"`, `true`.
  Con `0`, vacío, `null`, `false` o ausente, lo de siempre (el XML lleva `IndicadorMontoGravado` 0).
  Cualquier otro valor (`2`, `"si"`, un arreglo): error 502 antes de firmar. En el set de
  certificación (`strict_input`), igual que hoy: manda lo que traiga el set, sin tocar el indicador.
- **Entonces:** `precio_unitario` es el precio final **con** ITBIS (hasta 4 decimales) y
  `descuento_monto` va en pesos **con** ITBIS. `MontoItem = round(cantidad × precio, 2) − descuento`,
  `MontoGravadoIx = round(Σ MontoItem de la tasa / (1 + tasa), 2)`, ITBIS = `Σ − MontoGravadoIx`,
  `MontoTotal = Σ MontoItem`. El XML lleva `<IndicadorMontoGravado>1</IndicadorMontoGravado>`.
- **Tipos:** E31, E32, E33 y E34 (`EcfItemMapper::TIPOS_PRECIOS_CON_ITBIS`). Con `1` en otro tipo:
  error 502 (`Fallo emitiendo e-CF: …`) con texto en español, antes de firmar o enviar nada.
- **Decimales:** con el modo activo, una línea con más de 2 decimales de cantidad o más de 4 de precio
  es un error 502 que nombra la línea (`Línea N: …`). No se redondea en silencio: 1.125 × 1,000.00 se
  firmaría 1.13 × 1,000.00 = 1,130.00 contra los 1,125.00 del cliente. La app valida igual.
- Un descuento real sale con su `TablaSubDescuento` (arreglo del 2026-11-24, `3594836`).

### 4.2 Código

- `ECFEmissionService::prepararItemsIntegracion(array $payload): array` (público y estático: sin red
  ni BD, para probarlo sin firmar). La rama de integración de `emitir()` pasa a usarlo y recibe el
  payload con `items`, `totales` e `indicador_monto_gravado` resueltos.
  - **Modo activo:** validación de decimales, `EcfItemMapper::normalizarCantidadPrecio` (ya solo limpia
    el ruido binario), `map(..., false, true)`, `totales(..., true)` e indicador `'1'`.
  - **Modo inactivo:** exactamente lo de hoy (`map`, `totales`) con el indicador resuelto en `'0'`: el
    XML es el mismo, porque el builder ya escribía `0` con el campo ausente.
  - Los `totales` que mande el cliente siguen mandando clave por clave sobre los calculados.
- Falla con `EcfUsuarioException` (texto técnico + texto para el integrador).

### 4.3 Pruebas y documentación

- Sección nueva «Integración» en `tools/test_precios_itbis_incluido.php` (sin BD ni red): el caso del
  proveedor (200 × 16.50 → 2,796.61 + 503.39 = 3,300.00), la factura de 14 líneas (10,685.00 sin
  descuentos), un descuento real, el indicador como `1` y `"1"`, los tipos no admitidos, y que sin el
  indicador o con `0` o en modo estricto nada cambia.
- Guía de cliente creada: `docs/api/integracion-precios-con-itbis.md`, con el JSON y el XML de ejemplo
  (generados con el código real), y una nota en `docs/integrations/dgii-ecf.md` (precedente 1934).
  Las pruebas quedaron como las secciones 7 y 8 de `tools/test_precios_itbis_incluido.php` (111 verificaciones).
- La fila del campo en `referencia-endpoints-integracion.md` queda pendiente: ese archivo tiene
  cambios sin commitear de otra tarea.

### 4.4 Despliegue

Solo PHP (`ECFEmissionService.php`); sin migración. Nada cambia para un tenant hasta que mande el
indicador en 1. **Antes de desplegar**, correr `tools/verificar_indicador_monto_gravado_integracion.sql`
en la master (solo lectura): si alguien manda el indicador en 1 con precios sin ITBIS, su total bajaría
cerca de un 15 % sin ningún error. La evidencia de §3 sale de una bitácora de 10 días: se repite ahí.

## 5. Riesgos

- Aún no hay un E31 **de producción** con el modo bien calculado: hay E34 ×2 `ACEPTADO` y un E32
  `RFCE_ACEPTADO` en `ecf` (Gratex, 2026-10-08) y un E31 `ACEPTADO` en `certecf`. La primera factura real
  del proveedor es la confirmación; conviene que sea pequeña.
- Un precio con ITBIS que no cabe en 4 decimales (p. ej. 100.00 entre 300 unidades) puede dejar la
  línea a un centavo del total pactado.
- `strict_input` lo puede mandar cualquier cliente de integración (el runner de certificación lo manda
  a este endpoint): con él el indicador y los montos van como llegan, así que la invariante del objetivo
  3 vale solo en emisión normal. Decidir si producción (`ecf`) debería ignorarlo queda fuera de esta spec.
