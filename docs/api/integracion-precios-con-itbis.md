# Precios con ITBIS incluido — API de Integración

**Guía rápida para empresas en modo integración.** Cómo emitir una factura cuando su sistema guarda
el **precio final, con ITBIS**, con `POST /api/integracion/ecf`. Las conexiones, credenciales, formato
de la respuesta y el resto de los campos están en la
[Referencia de endpoints](referencia-endpoints-integracion.md); aquí solo lo que cambia con este modo.

---

## 1. En resumen

| | |
|---|---|
| **Endpoint** | `POST https://gratex.net/api/integracion/ecf` (el mismo de siempre) |
| **Cómo se activa** | `"indicador_monto_gravado": 1` en la raíz del JSON |
| **Tipos** | E31, E32, E33 y E34. En cualquier otro tipo el envío falla (sección 6) |
| **Lo que cambia** | `precio_unitario` y `descuento_monto` van **con ITBIS**. El ITBIS lo desglosamos nosotros |
| **Si no lo envía** | Todo igual que hoy: el precio va **sin** ITBIS y el ITBIS se suma encima |

---

## 2. Cuándo usarlo

Cuando su sistema guarda el precio pactado con el cliente **con ITBIS incluido** (RD$16.50 por
unidad, RD$3,300.00 por 200 unidades). Con el modo de siempre habría que enviar el precio sin ITBIS a
4 decimales (13.9831), y 200 × 13.9831 = 2,796.62 no coincide con la base real de la línea
(3,300.00 / 1.18 = 2,796.61). Para cuadrar ese centavo algunos sistemas agregan un descuento de
RD$0.01: **no lo haga**. Con este modo no hace falta, y el total del comprobante es exactamente la
suma de los precios finales.

---

## 3. Cómo se envía

Un campo nuevo en la raíz del JSON, junto a `tipo_ecf`, `e_ncf`, `emisor`, `comprador` e `items`:

| Campo | Valor | Notas |
|---|---|---|
| `indicador_monto_gravado` | `1` (también `"1"`) | Activa el modo para **todo** el comprobante. Con `0`, vacío o sin el campo, el modo de siempre. Cualquier otro valor (por ejemplo `2` o `"si"`) es un error (sección 6) |

Con el modo activo, en cada línea de `items`:

| Campo | Cómo va |
|---|---|
| `precio_unitario` | El precio **final, con ITBIS**. Hasta 4 decimales: con más, el envío falla (sección 6) |
| `cantidad` | Hasta 2 decimales: con más, el envío falla (sección 6). No redondeamos en silencio |
| `descuento_monto` | Solo un descuento **real**, en pesos **con ITBIS** (el mismo criterio del precio) |
| `indicador_facturacion` | Igual que siempre: `1` 18 % · `2` 16 % · `3` tasa cero · `4` exento |

No envíe `totales`: los calculamos a partir de las líneas.

---

## 4. Qué calculamos

- `MontoItem` de cada línea = `cantidad × precio_unitario − descuento_monto` (con ITBIS), a 2 decimales.
- Por cada tasa: base = `suma de los MontoItem de la tasa / 1.18` (o `/ 1.16`), a 2 decimales, e ITBIS = suma
  menos esa base. Es el cálculo de la DGII, **por tasa y no por línea**.
- `MontoTotal` = la suma de los `MontoItem`. El comprobante lleva `IndicadorMontoGravado = 1`.
- Un descuento real sale con su detalle de descuento (`TablaSubDescuento`, tipo `$`): usted no tiene
  que enviarlo.

---

## 5. Ejemplos

### 5.1 Línea de 200 unidades a RD$16.50

```json
{
  "tipo_ecf": "31",
  "e_ncf": "E310000000015",
  "fecha_emision": "04-08-2026",
  "fecha_vencimiento_secuencia": "31-12-2027",
  "tipo_pago": 1,
  "tipo_ingresos": "01",
  "indicador_monto_gravado": 1,
  "emisor": {
    "rnc": "131111111",
    "razon_social": "EMPRESA 1 SRL",
    "direccion": "Av. Winston Churchill #45, Piantini"
  },
  "comprador": {
    "rnc": "131222444",
    "razon_social": "EMPRESA COMPRADORA SRL"
  },
  "items": [
    {
      "nombre_item": "Alambre THHN 12 negro",
      "cantidad": 200,
      "precio_unitario": 16.50,
      "indicador_facturacion": 1,
      "indicador_bien_servicio": 1,
      "unidad_medida": "43"
    }
  ]
}
```

El XML que firmamos lleva, entre otros, esto:

```xml
<IndicadorMontoGravado>1</IndicadorMontoGravado>
...
<Item>
  <NumeroLinea>1</NumeroLinea>
  <IndicadorFacturacion>1</IndicadorFacturacion>
  <NombreItem>Alambre THHN 12 negro</NombreItem>
  <IndicadorBienoServicio>1</IndicadorBienoServicio>
  <CantidadItem>200</CantidadItem>
  <UnidadMedida>43</UnidadMedida>
  <PrecioUnitarioItem>16.5</PrecioUnitarioItem>
  <MontoItem>3300.00</MontoItem>
</Item>
...
<Totales>
  <MontoGravadoTotal>2796.61</MontoGravadoTotal>
  <MontoGravadoI1>2796.61</MontoGravadoI1>
  <ITBIS1>18</ITBIS1>
  <TotalITBIS>503.39</TotalITBIS>
  <TotalITBIS1>503.39</TotalITBIS1>
  <MontoTotal>3300.00</MontoTotal>
</Totales>
```

**RD$2,796.61 + RD$503.39 = RD$3,300.00**, sin ningún descuento.

### 5.2 La misma línea con un descuento real de RD$100.00

Se agrega `"descuento_monto": 100.00` a la línea (pesos con ITBIS). Lo que cambia en el XML:

```xml
<Item>
  ...
  <PrecioUnitarioItem>16.5</PrecioUnitarioItem>
  <DescuentoMonto>100.00</DescuentoMonto>
  <TablaSubDescuento>
    <SubDescuento>
      <TipoSubDescuento>$</TipoSubDescuento>
      <MontoSubDescuento>100.00</MontoSubDescuento>
    </SubDescuento>
  </TablaSubDescuento>
  <MontoItem>3200.00</MontoItem>
</Item>
...
<Totales>
  <MontoGravadoTotal>2711.86</MontoGravadoTotal>
  <MontoGravadoI1>2711.86</MontoGravadoI1>
  <ITBIS1>18</ITBIS1>
  <TotalITBIS>488.14</TotalITBIS>
  <TotalITBIS1>488.14</TotalITBIS1>
  <MontoTotal>3200.00</MontoTotal>
</Totales>
```

**RD$2,711.86 + RD$488.14 = RD$3,200.00.**

---

## 6. Errores

| HTTP | Cuándo | Respuesta |
|---|---|---|
| `502` | `indicador_monto_gravado` en `1` en un tipo que no es E31, E32, E33 ni E34 | `{"status": false, "error": "Fallo emitiendo e-CF: indicador_monto_gravado = 1 (precios con ITBIS incluido) solo se admite en E31, E32, E33 y E34; este comprobante es E45. Quite el indicador y envíe los precios sin ITBIS."}` |
| `502` | `indicador_monto_gravado` con un valor que no es 0 ni 1 (por ejemplo `2` o `"si"`) | `{"status": false, "error": "Fallo emitiendo e-CF: indicador_monto_gravado solo admite 0 o 1 (0 = precios sin ITBIS, 1 = precios con ITBIS incluido). Corríjalo y reenvíe."}` |
| `502` | Con el modo activo, una línea con más de 2 decimales en `cantidad` o más de 4 en `precio_unitario` | `{"status": false, "error": "Fallo emitiendo e-CF: Línea 2: con indicador_monto_gravado = 1 la cantidad admite hasta 2 decimales y el precio hasta 4. Corrija la línea y reenvíe."}` (el número es el de la línea, `numero_linea` o su posición) |

El error sale **antes** de firmar y de enviar nada a la DGII: no se consume el e-NCF. Corrija el dato
y reenvíe con el **mismo** `e_ncf`.

---

## 7. Qué no hacer

- **No envíe `1` con precios sin ITBIS.** Le sacaríamos el ITBIS de un precio que no lo traía y el
  comprobante quedaría por debajo de lo que usted cobra.
- **No siga enviando descuentos de redondeo** (RD$0.01). En este modo sobran, y quedarían como
  descuentos reales en el comprobante.
- **No mezcle** en un mismo comprobante líneas con ITBIS incluido y líneas sin él: el indicador vale
  para todas.

## 8. Antes de pasar su facturación a este modo

- **Envíe primero un comprobante de poco valor** y confirme que llegue `ACEPTADO` con
  `GET /api/integracion/estado` (Referencia, sección 7). Es el modo que la DGII ya acepta, pero
  conviene verlo en su propio RNC antes de usarlo en todo.
- **Envíe el precio de lista, no uno derivado.** El total de la línea es `cantidad × precio`
  redondeado a 2 decimales. Si su sistema calcula el precio dividiendo el total de la línea entre la
  cantidad y el resultado no cabe en 4 decimales (RD$100.00 entre 300 unidades), la línea puede quedar
  a un centavo del total que usted tiene.
