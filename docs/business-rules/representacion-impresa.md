# Representación Impresa (RI) del e-CF — Requerimientos DGII

Reglas de negocio de la norma DGII para la **Representación Impresa** de un e-CF: qué
elementos debe contener todo documento impreso/PDF. El motor que las implementa es
`src/Utils/FacturaPdfGenerator.php`; cómo se aplican por plantilla y branding está en
[../modules/branding-plantillas.md](../modules/branding-plantillas.md).

## 0. Disposición del modelo DGII (hoja carta, plantilla `clasico`)

La plantilla `clasico` (la de todo tenant sin otra configurada y la de `ri_desde_xml.php`) sigue el
modelo de RI de la DGII; moderno, compacto y los diseños a la medida conservan el suyo
(`FacturaTemplate::usaDisposicionNorma()`):

*   **Izquierda:** nombre del emisor (azul), RNC, Dirección, Teléfono/Correo y **Fecha Emisión** (DD-MM-AAAA).
*   **Derecha:** título del comprobante, **e-NCF**, **NCF Modificado** (E33/E34, justo bajo el e-NCF) y
    **Fecha Vencimiento** (DD-MM-AAAA; no va en E32/E34).
*   **Franja del cliente** entre dos reglas de color: *Razón Social Cliente* y *RNC Cliente*.
*   **Tabla con bordes** (Cantidad, Descripción, Unidad de Medida, Precio, ITBIS, Valor; Valor sombreado).
    La unidad sale como la sigla del catálogo; sin catálogo o con un código que no está en él (p.ej. `43`) sale
    **"Unidad"**, nunca el código.
*   **Pie:** QR a la izquierda con *Código de Seguridad* y *Fecha Firma* debajo; a la derecha el cuadro de
    totales (Subtotal Gravado, Total ITBIS, Total), con borde.

## 1. Encabezado de la Identidad Legal (Emisor)

Debe identificar claramente a la entidad que emite el documento:
*   **Nombre/Razón Social del Emisor:** Nombre legal de la empresa o persona física.
*   **RNC del Emisor:** Registro Nacional de Contribuyentes.
*   **Dirección Comercial:** Ubicación física, ciudad y sector.
*   **Información de Contacto:** Teléfono **obligatorio**; correo electrónico opcional. La DGII
    rechazó RI de certificación que no mostraban dirección ni teléfono del emisor ("N/D" no
    cuenta como dirección). Para una RI desde XML sin esos datos: `tools/ri_desde_xml.php
    --direccion=... --telefono=...`.

## 2. Identificación del Comprobante (e-CF)

Debe ubicarse generalmente en la parte superior derecha:
*   **Denominación del Tipo de e-CF:** Ejemplo: "Factura de Crédito Fiscal Electrónica".
*   **e-NCF:** El número de comprobante fiscal electrónico (ej: E310000000001).
*   **Fecha de Emisión:** Formato DD-MM-AAAA.
*   **Fecha de Vencimiento:** Requerida para facturas con valor fiscal. **No** va en E32 ni en E34:
    su e-CF no lleva `FechaVencimientoSecuencia` y la DGII observa la RI que la imprime (misma
    tabla que el XML, `ECFXmlBuilder::llevaFechaVencimientoSecuencia`).

## 3. Información del Receptor (Cliente)

*   **RNC/Cédula del Receptor:** Obligatorio para crédito fiscal y facturas de consumo $\ge$ RD$250,000.
*   **Razón Social/Nombre:** Nombre legal del cliente.

## 4. Detalle de Bienes o Servicios (Estructura de Tabla)

La tabla debe estar estandarizada con las siguientes columnas mínimas:
1.  **Cantidad:** Número de unidades.
2.  **Descripción:** Detalle claro del producto o servicio.
3.  **Unidad de Medida:** Siglas estándar (ej: UND, PZA, CAJ, PAQ).
4.  **Precio:** Costo unitario sin impuestos.
5.  **ITBIS:** El monto del impuesto calculado para esa línea.
6.  **Valor:** El subtotal de la línea (Cantidad x Precio) sin incluir el impuesto.

## 5. Cuadro de Totales

*   **Subtotal Gravado:** Suma de los montos antes de impuestos.
*   **Total ITBIS:** Sumatoria de los impuestos de todas las líneas.
*   **Total:** Monto final a pagar en pesos dominicanos (DOP).

## 6. Pie de Documento y Seguridad

Todo documento electrónico válido debe contener estos tres elementos en su RI:
*   **Código QR:** Un código de respuesta rápida para validación externa.
*   **Código de Seguridad:** Una cadena de 6 caracteres generada por la firma electrónica.
*   **Fecha Firma:** La marca de tiempo exacta de la firma digital con formato DD-MM-AAAA HH:MM:SS.

El Código de Seguridad y la Fecha de Firma van **debajo** del QR, no a su lado: la DGII observó
la RI que los tenía a la derecha. Vale para la hoja carta y para la tirilla POS.

## 7. Variaciones según Casos Especiales

*   **Notas de Crédito (E34) / Débito (E33):** Deben incluir el campo **"NCF Modificado"** para referenciar la factura original, **al lado del e-NCF** de la nota (justo debajo, en la identificación del comprobante). La DGII rechazó la RI que lo ponía aparte, bajo los datos del emisor.
*   **Modalidad de Envío Diferido:** Debe incluir una leyenda informativa indicando que la validez fiscal podrá consultarse tras 24 horas.
*   **Modalidad de Contingencia (Incapacidad Técnica):** Si el sistema falla, el modelo cambia a un formato **no electrónico** (Serie B), eliminando el QR y la firma digital en ese momento.
*   **Paginación:** Si el documento tiene más de una hoja, debe indicar "Página X de Y".
