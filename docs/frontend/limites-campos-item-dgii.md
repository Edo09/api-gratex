# Límites de campos de Ítem (DGII) — Ajustes Frontend

## Qué pasó

La DGII **rechazó** un e-CF con este error:

```
The 'NombreItem' element is invalid - The value 'Sickers HACE Sucursal Santiago
(Autopista Duarte) Material: Vinyl monoestático blanco Impresión: Rojo y negro
Tamaño final: 2x2' is invalid according to its datatype 'AlfaNum80ValidationType'
- The actual length is greater than the MaxLength value. (cód. 3)
```

**Causa:** el campo **`NombreItem`** que envió el frontend tenía ~130 caracteres. La DGII
solo permite **80 caracteres** en ese campo (tipo `AlfNum80Type`). El texto largo mezclaba
el nombre del producto con su descripción (material, impresión, tamaño, etc.).

**Consecuencia:** la DGII rechaza el comprobante y **la secuencia NCF se consume igual**.
La reemisión toma un NCF nuevo. Por eso conviene evitar el rechazo desde el origen.

## Qué se hizo en el backend (red de seguridad)

El backend ahora **trunca automáticamente** para que nunca vuelva a fallar por longitud:

- `NombreItem` se corta a **80 caracteres**.
- Si el ítem **no trae descripción** y el nombre excede 80, el texto completo se mueve a
  `DescripcionItem` (que permite hasta 1000), para no perder información.
- `DescripcionItem` se corta a **1000 caracteres**.

> Esto evita el rechazo, pero **trunca** el nombre. Si el corte cae a mitad de palabra, el
> nombre en el comprobante queda feo. Por eso el frontend debe separar bien los campos.

## Qué debe hacer el frontend

Separar **nombre corto** de **descripción larga**:

| Campo enviado    | Tipo DGII       | Máx. caracteres | Qué poner                                            |
|------------------|-----------------|-----------------|------------------------------------------------------|
| `nombre_item`    | `AlfNum80Type`  | **80**          | Nombre corto del producto/servicio. Ej: `Sticker 2x2`|
| `descripcion`    | `AlfNum1000Type`| **1000**        | Detalle: material, impresión, tamaño, sucursal, etc. |

### Acciones recomendadas

1. **Validar longitud** en el formulario antes de enviar:
   - `nombre_item`: bloquear/avisar si supera 80 caracteres.
   - `descripcion`: bloquear/avisar si supera 1000 caracteres.
2. **Contador visible** de caracteres en el input de `nombre_item` (ej. `72/80`).
3. **Mover el detalle** (material, color, medidas) al campo `descripcion`, no al nombre.
4. No depender del truncado del backend — es solo respaldo; el corte puede quedar a mitad
   de palabra.

### Ejemplo

**Antes (rechazado):**

```json
{
  "nombre_item": "Sickers HACE Sucursal Santiago (Autopista Duarte) Material: Vinyl monoestático blanco Impresión: Rojo y negro Tamaño final: 2x2",
  "descripcion": ""
}
```

**Después (correcto):**

```json
{
  "nombre_item": "Sticker Vinyl 2x2 - HACE Santiago",
  "descripcion": "Sucursal Santiago (Autopista Duarte). Material: Vinyl monoestático blanco. Impresión: Rojo y negro. Tamaño final: 2x2."
}
```

## Nota sobre conteo de caracteres

La DGII cuenta **caracteres** (no bytes). Tildes y `ñ` cuentan como 1. Validar con la
longitud de string del lenguaje (`String.length` en JS), no por bytes.

## Referencia

- Validación backend: `src/Utils/FacturacionElectronica/ECFXmlBuilder.php` (método `buildDetallesItems`)
- XSD DGII: `samples/e-CF 31 v.1.0.xsd` → `NombreItem` = `AlfNum80Type`, `DescripcionItem` = `AlfNum1000Type`
