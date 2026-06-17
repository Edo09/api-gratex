# Representación Impresa (RI) del e-CF — Requerimientos DGII

Reglas de negocio de la norma DGII para la **Representación Impresa** de un e-CF: qué
elementos debe contener todo documento impreso/PDF. El motor que las implementa es
`src/Utils/FacturaPdfGenerator.php`; cómo se aplican por plantilla y branding está en
[../modules/branding-plantillas.md](../modules/branding-plantillas.md).

## 1. Encabezado de la Identidad Legal (Emisor)

Debe identificar claramente a la entidad que emite el documento:
*   **Nombre/Razón Social del Emisor:** Nombre legal de la empresa o persona física.
*   **RNC del Emisor:** Registro Nacional de Contribuyentes.
*   **Dirección Comercial:** Ubicación física, ciudad y sector.
*   **Información de Contacto:** Teléfono y correo electrónico (opcional pero recomendado).

## 2. Identificación del Comprobante (e-CF)

Debe ubicarse generalmente en la parte superior derecha:
*   **Denominación del Tipo de e-CF:** Ejemplo: "Factura de Crédito Fiscal Electrónica".
*   **e-NCF:** El número de comprobante fiscal electrónico (ej: E310000000001).
*   **Fecha de Emisión:** Formato DD-MM-AAAA.
*   **Fecha de Vencimiento:** Requerida para facturas con valor fiscal.

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

## 7. Variaciones según Casos Especiales

*   **Notas de Crédito (E34) / Débito (E33):** Deben incluir el campo **"NCF Modificado"** para referenciar la factura original.
*   **Modalidad de Envío Diferido:** Debe incluir una leyenda informativa indicando que la validez fiscal podrá consultarse tras 24 horas.
*   **Modalidad de Contingencia (Incapacidad Técnica):** Si el sistema falla, el modelo cambia a un formato **no electrónico** (Serie B), eliminando el QR y la firma digital en ese momento.
*   **Paginación:** Si el documento tiene más de una hoja, debe indicar "Página X de Y".
