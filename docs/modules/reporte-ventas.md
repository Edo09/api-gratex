# Reporte de Ventas (gestión)

Responde **"cuánto vendí y a quién"**, no "qué le declaro a la DGII". Para lo
fiscal está el [607](../../docs/README.md); este es el reporte de negocio.

Reemplaza cinco entradas del sistema anterior del cliente — *Ventas*, *Ventas por
cliente*, *Ventas por forma de pago*, *Ventas por vendedor* y *Ventas por
usuario* — con un endpoint y cuatro agrupaciones. **Vendedor y usuario son la
misma agrupación**: hoy solo se guarda quién digitó la factura (`facturas.user_id`),
no a quién se le acredita la venta. Lo único parecido a un vendedor en el esquema
es `emisor_config.codigo_vendedor`, que es un campo único de la empresa para el
XML de la DGII, no de cada venta.

## Endpoint

```
GET /api/reportes/ventas?desde=AAAA-MM-DD&hasta=AAAA-MM-DD&agrupar=<a>&format=<f>
```

| Parámetro | Valores | Por defecto |
|---|---|---|
| `desde` / `hasta` | `AAAA-MM-DD`, ambos extremos incluidos | mes en curso |
| `agrupar` | `documento` (detalle) · `cliente` · `forma_pago` · `usuario` | `documento` |
| `format` | `json` · `pdf` · `xlsx` | `json` |

Va bajo `reportes`, que ya está en `config/permissions.php` — sin ruta nueva no
hay mapeo RBAC que olvidar (ver [roles-permisos.md](roles-permisos.md)).

Topes: rango máximo de 5 años, y fechas validadas con `checkdate` (un
`2026-02-31` se rechaza, no se corrige en silencio).

## Qué cuenta como venta

| Documento | Entra | Signo |
|---|---|---|
| E31, E32, E44, E45, E46 | sí | suma |
| Facturas simples (`tipo_ecf IS NULL`) | sí | suma |
| E33 nota de débito | sí | suma |
| E34 nota de crédito | sí | **resta** |
| E41 compras, E43 gastos menores, E47 pagos al exterior | **no** | — |
| Cualquiera con estado `%RECHAZADO%` | **no** | — |

E41/E43/E47 los genera el emisor por lo que **compra**, no por lo que vende;
incluirlos inflaría las ventas con gasto propio.

A diferencia del 607, aquí **sí** entran las facturas simples y las que están en
`ENVIADO` sin respuesta de la DGII: son ventas reales del negocio aunque el acuse
no haya llegado. El 607 exige `ACEPTADO` porque es una declaración fiscal.

También se aplica el filtro de ambiente (`AmbienteResolver::active()`), igual que
listados y stats: la data de certificación no puede contaminar los montos.

## Montos

`facturas.total` manda — es el MontoTotal firmado. Las líneas
(`factura_items`) solo reparten ese total entre base e ITBIS, con un único
`LEFT JOIN` agregado (nada de una consulta por factura).

Una factura sin líneas guardadas no tiene desglose posible: todo el total va a
base, ITBIS queda en 0, y el reporte lo dice en `advertencias` en vez de fingir
un desglose que no tiene.

## Agrupación

Se agrupa en PHP, no con un `GROUP BY` paralelo. El signo de las notas de crédito
y el arreglo de las facturas sin líneas ya se aplicaron al leer, así que agrupar
sobre esas filas garantiza que **el detalle y los agrupados siempre cuadran**. Un
`GROUP BY` en SQL tendría que repetir esas reglas y podría divergir.

Los clientes sin `client_id` (consumidor final) agrupan por nombre; si no, todos
caerían en el mismo saco.

## Nombres de usuario

En multi-tenant los usuarios viven en el **master**, así que no hay JOIN posible
con `facturas`: se traen aparte y se mapean en PHP (cacheado por instancia). Si el
master no responde, el reporte sale igual con `Usuario #<id>` — un reporte de
ventas no puede caerse porque falten los nombres.

## Frontend

`src/features/reports/VentasView.tsx`, colgado de **Reportes › Ventas**. Rango de
fechas + pestañas para las cuatro vistas + tarjetas de totales + botones de
descarga (PDF y Excel).

El rango no puede quedar invertido: mover un extremo más allá del otro lo
arrastra, así que no hay estado de error que mostrar ni petición que el API vaya a
rechazar con un 400.

## Descargas

Dos formatos, y las dos salidas comparten la misma definición de columnas en el
controller para que el papel y la hoja de cálculo digan lo mismo, en el mismo
orden.

**PDF** (`src/Utils/Pdf/ReporteVentasPdf.php`) — para imprimir o archivar. Lleva
el membrete del emisor (logo, razón social, RNC) porque es un documento que sale
de la empresa. El detalle va apaisado (diez columnas no caben a lo ancho de una
carta) y las agrupaciones en vertical. La banda de columnas se repite en cada
página: sin eso, un reporte de 300 líneas obliga a volver a la primera hoja para
saber qué columna es cuál. Los montos negativos (notas de crédito) salen en rojo.

**Excel** (`src/Utils/XlsxWriter.php`) — `.xlsx` nativo, no un CSV renombrado:
Excel lo abre sin avisos de formato, los montos son **números de verdad** (se
pueden sumar y filtrar) con formato `#,##0.00`, y el encabezado queda congelado
al hacer scroll.

Ese escritor es propio y son ~250 líneas. No hay Composer, así que no hay
PhpSpreadsheet, y un `.xlsx` es un ZIP con unos cuantos XML dentro — mucho menos
que vendorizar una librería entera. **El ZIP se arma a mano en vez de con
`ZipArchive`**: esa extensión puede no estar en un hosting compartido y no hay
forma de comprobarlo desde fuera. zlib sí está (FPDF ya comprime los PDF con
`gzcompress`) y, si faltara, el archivo sale sin comprimir pero igual de válido.

Detalles de OOXML que Excel exige y que ya están resueltos ahí, no tocar:

- Los rellenos 0 y 1 de `styles.xml` tienen que ser `none` y `gray125`, en ese
  orden, aunque no se usen: si faltan, Excel considera el libro corrupto.
- Los caracteres de control ASCII (por debajo de 0x20, salvo tabulador, salto
  de linea y retorno) estan prohibidos en el XML; uno pegado desde otro sistema
  deja el archivo ilegible para Excel. Se limpian al escapar.
- El nombre de hoja se limita a 31 caracteres y se le quitan `\ / ? * [ ] :`.

## Pendiente

Si el cliente quiere de verdad *ventas por vendedor* separado de *por usuario*,
hace falta decidir el modelo (catálogo propio de vendedores, o vendedor elegido
entre los usuarios) y agregar `facturas.vendedor_id` + el selector en el
formulario. El histórico no se puede rellenar: nunca se registró.
