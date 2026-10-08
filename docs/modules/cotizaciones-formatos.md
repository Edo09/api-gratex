# Formatos de cotización por tenant

El módulo de cotizaciones nació con las reglas y el PDF de Gratex metidos en el
controller: cualquier tenant que cotizaba lo hacía con el formulario, los totales,
la numeración aleatoria y el PDF de Gratex (incluida su cuenta bancaria). Ferretería
(FERREHERRAMIENTAS VENTURA, SRL) cotiza con otra hoja: productos del catálogo,
precios sin ITBIS, cargos, retención, abono y un consecutivo.

La solución es un **formato de cotización por tenant**: un ajuste en
`master.tenants` y, en cada lado, un módulo de código por formato. Un tenant nuevo
con su propia hoja es **un formato más**, no una copia del módulo.

Formatos de hoy:

| Clave | Tenant | Qué es |
|-------|--------|--------|
| `gratex` | Gratex y todo tenant sin otro ajuste (default) | El comportamiento de siempre, sin cambios |
| `ferreteria` | Ferretería | Hoja "COTIZACION MERCANCIAS": líneas del catálogo, ITBIS por línea, cargos, retención 5%, abono, `COT-000001` |

Referencia de la API (cuerpos, respuestas, errores): [../api/cotizaciones.md](../api/cotizaciones.md).
Ferretería tiene además **conduces de mercancía** ([abajo](#conduces-de-mercancía-ferretería)), con
su propia referencia: [../api/conduces.md](../api/conduces.md).

---

### Piezas

### Backend (`api-gratex`)

| Archivo | Qué hace |
|---------|----------|
| `src/Controllers/cotizacionController.php` | Decodifica el cuerpo una vez, elige el formato, aplica la guardia `409`, llama al formato, escribe la auditoría y envuelve la respuesta. No tiene reglas de ningún formato |
| `src/Utils/Cotizacion/CotizacionFormato.php` | Clase abstracta: el contrato de un formato |
| `src/Utils/Cotizacion/CotizacionFormatos.php` | Registro `FORMATOS` (clave → clase), `para()`, `delTenant()`, `delCuerpo()`, `deLaFila()` |
| `src/Utils/Cotizacion/GratexFormato.php` | Las ramas de siempre del controller, movidas tal cual |
| `src/Utils/Cotizacion/FerreteriaFormato.php` | Reglas puras (totales, validación, número, RNC, fecha) + crear/editar/vista previa/PDF. Sus reglas de línea (`normalizarLinea`, `aplicarCatalogoLineas`) también revisan las del conduce |
| `src/Utils/Cotizacion/FerreteriaCotizacionPdf.php` | Renderizador FPDF puro de la hoja de Ferretería: la cotización y, con `documento => 'conduce'`, el conduce |
| `src/Utils/Cotizacion/Redondeo.php` | Redondeo igual en PHP 8.3 y 8.5 (copia de `montosLinea.redondear` del front) |
| `src/Models/cotizacionModel.php` | Lecturas comunes + `crearConFormato` / `actualizarConFormato` (numeración, cabecera, líneas y ajustes en una transacción) |
| `tools/test_cotizacion_ferreteria.php` | Pruebas por CLI, sin DB (`--pdf [--grid]` escribe los PDF de muestra) |
| `tools/fixtures/cotizacion_ferreteria.json` | Las 3 hojas del Excel línea por línea, casos de borde y totales esperados |
| `tests/test_cotizaciones_ferreteria.http` | Pruebas a mano contra un servidor |
| `src/Controllers/conduceController.php` | `/api/conduces`: el token, el formato `ferreteria` (`422` si no), la ruta, la auditoría y la respuesta |
| `src/Utils/Cotizacion/FerreteriaConduce.php` | El conduce: sus reglas (las de línea de `FerreteriaFormato`, con precio 0 permitido), la disponibilidad, el número `CON-` y crear/editar/eliminar/vista previa/PDF |
| `src/Models/conduceModel.php` | Las tablas de la 031: lecturas de los activos, numeración con `conduce_secuencia`, y editar y eliminar sin borrar (`activo = 0`) |
| `tools/test_conduces.php` | Pruebas del conduce por CLI, sin DB (`--pdf` escribe los PDF de muestra) |
| `tools/test_conduces_mysql.php` | Pruebas del conduce contra MySQL de verdad, solo en la base `smhynzte_conduces_scratch` (credenciales en `tools/.env`, ignorado por git) |
| `tests/test_conduces.http` | Pruebas a mano de los conduces contra un servidor, y las comprobaciones de servidor de la 031 |

No hay autoloader: cada archivo hace `require_once` de lo que usa, y
`CotizacionFormatos::para()` carga la clase del formato solo cuando se usa (una
petición de Gratex no carga el código ni el PDF de Ferretería).

### Frontend (`fiscalo`)

| Archivo | Qué hace |
|---------|----------|
| `src/features/cotizaciones/formatos/index.ts` | Registro `FORMATOS`, `esFormato`, `formatoDeFila`, `useCotizacionFormato()` (lee `cotizacion_formato` de `GET /api/branding`) |
| `src/features/cotizaciones/formatos/CotizacionEditor.tsx` | Elige el formulario: el de la empresa para una nueva, el de la fila para una existente |
| `src/features/cotizaciones/CotizacionFormView.tsx` | El formulario de Gratex (sin cambios) |
| `src/features/cotizaciones/formatos/ferreteria/` | `FerreteriaCotizacionForm.tsx`, `totales.ts` (misma cuenta que el PHP), `schema.ts` (Zod), `conversion.ts` (Facturar), y lo que comparte con el conduce: `lineas.ts`, `DescripcionLinea.tsx`, `BloqueCliente.tsx` |
| `scripts/parity-cotizacion-ferreteria.ts` | Corre `totalesFerreteria()` sobre la copia del fixture del backend |
| `src/features/conduces/` | Los conduces: `ConducesView.tsx` (la lista), `ConduceEditor.tsx` + `ConduceForm.tsx` (el formulario), `schema.ts` (Zod) y `conversion.ts` (Facturar). El menú los muestra solo con el formato `ferreteria` (`NavItem.formato`, `useFormatoTenant()`) |

### Datos

- **`master.tenants.cotizacion_formato`** (master_migration 011): `VARCHAR(40) NOT NULL
  DEFAULT 'gratex'`. Se cambia solo por SQL; `GET /api/branding` lo devuelve (solo
  lectura). Sin la columna (011 sin correr) todo tenant es `gratex`, porque
  `TenantResolver` lee los tenants con `SELECT *`.
- **Tenant migration 026** (`db/migrations/026_cotizaciones_formatos.sql`):
  - `cotizaciones`: `formato` (`NULL` = gratex), `numero` (`UNIQUE
    uk_cotizaciones_numero`, `NULL` en Gratex), `subtotal`, `itbis`; además corrige
    la deriva del snapshot (`user_id`, `updated_at`, `client_name` nullable).
  - `cotizacion_items`: `product_id` (FK `cotizacion_items_product_fk` a `products`,
    `ON DELETE SET NULL`), `unidad_medida`, `indicador_facturacion`,
    `indicador_bien_servicio`, `itbis_amount`. `NULL` = línea al estilo Gratex.
  - `cotizacion_ajustes` (`cotizacion_id`, `concepto`, `monto`; `UNIQUE (cotizacion_id,
    concepto)`; `ON DELETE CASCADE`): los montos fuera de las líneas. **Cada formato
    declara qué conceptos acepta**; solo se guardan los distintos de cero.
- **Tenant migration 031** (`db/migrations/031_conduces.sql`): `conduces`, `conduce_items` y
  `conduce_secuencia`, solo para los conduces de Ferretería. No toca las tablas de
  cotizaciones.

Esquema completo: [../database/schema.md](../database/schema.md).

---

### Cómo se elige el formato

| Petición | Formato | Por qué |
|----------|---------|---------|
| `POST /api/cotizaciones` | El de la empresa (`CotizacionFormatos::delTenant()`) | Una cotización nueva nace con el formato actual |
| `PUT /api/cotizaciones` | El de la fila (`cotizaciones.formato`, `NULL` = gratex). La fila se lee primero; si ya no existe, el del cuerpo cuando este código lo conoce (`CotizacionFormatos::deLaFila()`), que responde su "ya no existe": Ferretería `404`, Gratex su `200` `status: false` de siempre. Un formato desconocido cae en `gratex` y recibe el `409` | Cambiar el ajuste de la empresa no reinterpreta las guardadas; borrar una cotización no es un cambio de formato |
| `POST /api/cotizaciones/preview` | El de la fila si el cuerpo trae `id` de una que existe; si no, el de la empresa | La vista previa de una guardada imprime su código |
| `GET /api/cotizaciones/{id}/pdf` | El de la fila | Una de Gratex sale siempre con el PDF de Gratex |
| `GET` (lista y `?id=`), `DELETE` | Ninguno: código común del modelo | — |

`NULL` (en la fila o en el tenant) es `gratex`. Un nombre que este código no conoce
(un error de tipeo en el `UPDATE`, o código viejo) también cae en `gratex`, pero deja una línea en
el log para que ops lo vea: `[cotizaciones] formato desconocido ...` si viene de la
fila, `[cotizaciones] tenants.cotizacion_formato = ... no es un formato conocido ...`
si viene del tenant.

**Guardia `409`.** Cada formulario manda el formato con que se armó el cuerpo
(Ferretería: `"formato": "ferreteria"`; Gratex: nada, que es `gratex`). Si no coincide
con el que resolvió el servidor, el controller responde `409` ("La pantalla de
cotizaciones está desactualizada (cambió el formato de tu empresa). Recarga la
página.") y no guarda nada. Sin ella, una pestaña abierta antes del cambio guardaría
precios con ITBIS incluido (Gratex) con reglas que le suman el ITBIS encima
(Ferretería). El front muestra el mensaje con un botón "Recargar".

---

### El contrato `CotizacionFormato`

Todos los métodos devuelven `['success', $payload]` o `['error', string $mensaje, int $http]`.
El mensaje lo lee el usuario; el detalle técnico va al `error_log`. `$body` es el
`stdClass` del cuerpo (las líneas siguen siendo objetos).

| Método | Qué hace | `$payload` |
|--------|----------|------------|
| `nombre(): string` | La clave (`'gratex'`, `'ferreteria'`) | — |
| `crear(object $body)` | Validar, calcular, numerar y guardar | Lo que va en `data` |
| `actualizar(array $row, object $body)` | Lo mismo sobre la fila existente; número y código no cambian | Lo que va en `data` |
| `preview(object $body, ?array $row)` | Validar y calcular sin guardar | Los bytes del PDF |
| `pdf(array $cotizacion)` | El PDF de la fila de `getCotizaciones()` (con `items` y `ajustes`) | Los bytes del PDF |
| `permiteCorreo(): bool` | Si el formulario ofrece "Enviar por correo" (default `false`) | — |

`$http` es el código de la respuesta: Gratex usa `200` en sus errores de cabecera y
de guardado (su pantalla lo espera así) y `422` en los de línea; Ferretería usa `422`
para validación, `404` cuando la cotización ya no existe (o desaparece mientras se guarda) y `500` para
fallos de la base o del PDF. El controller aplica `$http` con `http_response_code()`
salvo cuando es `200`.

### Lecturas que no rompen a Gratex

- **Líneas con `SELECT *`.** `getCotizacionItems` no nombra columnas
  (`SELECT * FROM cotizacion_items WHERE cotizacion_id = :cotizacion_id ORDER BY id ASC`):
  las de la 026 salen cuando existen. Nombrarlas en una base sin la 026 haría fallar
  la consulta, el `catch` devolvería `[]` y una cotización se vería sin líneas;
  guardarla así borraría las de verdad.
- **Ajustes solo para formatos que los usan.** `getAjustes()` tiene su propio
  `try/catch` (loguea y devuelve `[]`) y solo corre para filas con `formato` distinto
  de `NULL`/`gratex`. Las de Gratex reciben `{}` sin consulta: una base sin
  `cotizacion_ajustes` nunca vacía el listado de Gratex, ni da `404` a su PDF, ni hace
  que el PUT diga "ya no existe".
- **`ajustes` siempre es un objeto** en el JSON (`(object)`): `{}` y no `[]`.
- **Orden del listado:** `date DESC, id DESC`.

---

### Gratex

`GratexFormato` es el código que tenían las ramas POST, preview, PUT y PDF del
controller, **movido tal cual**: mismas validaciones en el mismo orden, mismos textos
(`COT_*` y `cotValidarItems()` siguen en el controller), mismos códigos, las mismas
llamadas a `cotizacionModel::saveCotizacion` / `updateCotizacion` (sin tocar, con el
envío por correo adentro) y el mismo `CotizacionPdfGenerator`. Código aleatorio de 3
letras + 3 dígitos. No usa `Redondeo`. Es el único formato con correo
(`permiteCorreo() = true`).

### Ferretería

### Totales

El servidor los calcula e ignora cualquier `total` del cuerpo; la pantalla corre la
misma cuenta (`totalesFerreteria()` en `ferreteria/totales.ts`) y las dos dan lo mismo
al centavo. Todo monto, cada suma incluida, va con `Redondeo::r2`.

| Fila | Regla |
|------|-------|
| Base de la línea | `r2(r2(cantidad) × r4(precio sin ITBIS))` |
| ITBIS de la línea | `r2(base × tasa)`; tasa 0.18 / 0.16 / 0 / 0 para el indicador 1 / 2 / 3 / 4 |
| **Sub-total RD$** | `r2(Σ bases)` |
| **ITBIS** | `r2(Σ ITBIS de las líneas)`. Rótulo `ITBIS 18%` si todo lo gravado va al 18%; si no, `ITBIS` |
| + Cargos bancarios, + Manejos de operaciones bancarias, + Costo mano de obra | Montos tecleados, ≥ 0, sin ITBIS |
| **TOTAL RD$** | `r2(Sub-total + ITBIS + cargos + manejo + mano de obra)` |
| − Retención Renta por Tercero 5% | Casilla; si está marcada, `r2(Sub-total × 0.05)`, recalculada en cada guardado |
| Adeudado | `r2(TOTAL − retención)` |
| − Abono realizado | Tecleado, ≥ 0, y `abono ≤ adeudado` (comparando los redondeados) |
| **Restante (Adeudado)** | `r2(adeudado − abono)`. No se guarda; se muestra solo si hay retención o abono |

Con precios en pesos enteros (las 3 hojas del Excel) la suma del ITBIS por línea da
lo mismo que el 18% del Sub-total de la hoja: 41,860.00 / 7,534.80 / 49,394.80,
27,278.00 / 4,910.04 / 32,188.04 y 8,260.00 / 1,486.80 / 9,746.80. Con centavos
puede diferir en uno; se eligió por línea porque es lo que hace el e-CF.

### Validación

`FerreteriaFormato::validarForma()` revisa la forma y los rangos del cuerpo sin DB
(las reglas de unidades llegan inyectadas). Después, `aplicarCatalogo()` aplica lo que
contestó la base: que el cliente exista, que cada producto exista (bien/servicio sale
del producto, no de la pantalla), los totales y el tope del abono. Ambas son puras y
las prueba el CLI. Los textos de cada `422` están en
[../api/cotizaciones.md](../api/cotizaciones.md#errores-de-ferretería).

La descripción de cada línea pasa por `limpiarDescripcion()` antes de validarse y
guardarse: cada corrida de saltos de línea, tabuladores u otros caracteres de control
se vuelve **un espacio** y se recortan los extremos. La API acepta lo que le manden,
y en el PDF un salto es un renglón nuevo dentro de la celda: una descripción llena de
saltos estiraría la fila hasta salirse de la página.

### Numeración

`cotizacionModel::crearConFormato()`:

1. Toma `GET_LOCK(CONCAT(DATABASE(), ':cotizacion_seq'), 5)` (copia privada de los
   helpers de `facturaModel`). Si está ocupado o falla, lo loguea y sigue sin candado,
   como las facturas simples.
2. `beginTransaction`, y dentro `SELECT COALESCE(MAX(numero), 0) + 1`;
   `code = FerreteriaFormato::codigo(numero)` (`COT-000123`).
3. Inserta la cabecera (`formato`, `numero`, `code`, `date`, `client_id`,
   `client_name`, `subtotal`, `itbis`, `total`, `user_id`), las líneas y los ajustes,
   y hace commit.
4. Si el número choca con `uk_cotizaciones_numero` (`1062`): `rollBack` y **un**
   reintento en una transacción nueva, que vuelve a leer el `MAX`. Cualquier otro
   error no se reintenta (`1452`, un producto borrado entretanto, responde un `422`
   que se entiende).
5. En `finally`, suelta el candado si lo tomó.

Editar nunca cambia `numero` ni `code`. `client_name` guarda la razón social del
cliente (si no, el nombre de la empresa, si no, el nombre), recortada a 100.
`user_id` sale del token (`RequestContext::userId()`), nunca del cuerpo.

### Ajustes

Conceptos de Ferretería: `cargos_bancarios`, `manejo_bancario`, `mano_obra`, `abono`
(montos) y `retencion_isr` (casilla en el cuerpo, monto en la base). Un PUT reemplaza
el set completo. En el GET, `ajustes.retencion_isr` es el monto guardado; el
formulario marca la casilla si es > 0.

### El PDF de Ferretería

`FerreteriaCotizacionPdf` es puro: recibe la cotización con sus totales ya calculados,
el emisor (`EmisorConfigModel::get()`), el cliente y la ruta del logo
(`BrandingResolver::logoPath()`), y devuelve los bytes. No toca la base ni el tenant.

- Carta vertical, Times (núcleo de FPDF), texto en ISO-8859-1.
- De arriba abajo: logo centrado (caja de ~75×28 mm; sin logo, la razón social en
  negrita 16), dirección, `RNC <rnc con guiones>`, `COTIZACIÓN MERCANCÍAS`, fecha
  (`SEPTIEMBRE 2/2026.-`), el código o `VISTA PREVIA`, `NOMBRE O RAZÓN SOCIAL`, el
  cliente y su RNC/cédula con guiones.
- Tabla `Cantidad | Descripción mercancías | Valor Unitario | Valor Total RD$`, banda
  #BDD7EE, precio con `EcfDocumento::textoPrecio` (2 decimales, o hasta 4).
- La marca `***********No hay más productos debajo de la línea*****` justo después de
  la última línea, siempre en su misma página.
- Los totales en el orden de arriba; solo las filas con valor (Sub-total, ITBIS y
  TOTAL siempre).
- `Recibido por:` y el pie: razón social, correo (azul, subrayado) y
  `Teléfono <telefono>` de `emisor_config`. Cada parte solo si tiene valor.
- Saltos de página: la cabecera de la tabla se repite solo en páginas con filas; el
  bloque de totales + "Recibido por" + pie nunca se parte. `Página X de Y` solo si hay
  más de una página.
- No imprime cuenta bancaria, sello, firmas, ITBIS por línea ni unidad.

El PDF guardado (`pdf()`) recalcula los totales desde las líneas y los ajustes
guardados, con las mismas reglas (`lineasDesdeFilas`, `ajustesDesdeFilas`): el CLI
comprueba, caso por caso del fixture, que lo que se guarda y lo que se imprime dan lo
mismo.

### Facturar (en el front)

"Facturar ▾" abre la factura e-CF o la factura simple prellenada con el cliente y las
líneas ligadas a sus productos (así la factura mueve inventario). El descuento fijo
del cliente se aplica como siempre, con un aviso. Los cargos no se copian como líneas
(sale un aviso) y la retención y el abono tampoco (el pago se registra en la factura).
En factura simple, cada precio lleva su ITBIS dentro.

### Conduces de mercancía (Ferretería)

El conduce es la nota de entrega que va con la mercancía y que el cliente firma. Sale de una
cotización de Ferretería (botón "Conduce" en la lista de cotizaciones) o se crea sin cotización
(botón "Nuevo conduce" en la pantalla Conduces; decisión del 2026-10-08, antes solo se podía
desde una cotización), se puede editar antes y después de guardarlo, lleva su propio número
`CON-000001` y se imprime con la hoja de la cotización, pero sin precios ni totales. Una
cotización puede dar varios conduces (uno por entrega). Referencia de la API:
[../api/conduces.md](../api/conduces.md).

- **Solo `ferreteria`.** No es parte del contrato `CotizacionFormato`: es código propio de
  Ferretería (`FerreteriaConduce`). `/api/conduces` responde `422` "Los conduces no están
  disponibles para tu empresa." a cualquier otro formato, y el front muestra el menú
  Conduces y el botón solo cuando `GET /api/branding` dice `ferreteria`. Un formato nuevo
  no tiene conduces.
- **El mismo permiso.** La ruta usa el módulo `cotizaciones` (`'conduces' => 'cotizaciones'`
  en `config/permissions.php`); no hay módulo RBAC nuevo.
- **Las mismas reglas de línea.** `FerreteriaConduce` revisa cada línea con
  `FerreteriaFormato::normalizarLinea(…, true)` y `aplicarCatalogoLineas()`, con los mismos
  textos; las únicas diferencias son que el precio puede ser 0 y que la cantidad (menos de
  10^9) y el precio (menos de 10^14) tienen que caber en `conduce_items`: pasados del tope,
  `Línea N: la cantidad es demasiado grande.` o `Línea N: el precio es demasiado grande.` en
  vez de un `500` genérico. Bien/servicio sale del producto,
  como en la cotización. La cotización no cambia: sus mensajes y su PDF son los de antes (lo
  prueba `tools/test_cotizacion_ferreteria.php`).
- **Precios internos.** Cada línea guarda su precio sin ITBIS y sus indicadores para
  Facturar; ni la pantalla ni el PDF del conduce los muestran. Los cargos de la cotización no
  pasan al conduce (el formulario lo avisa), y un cuerpo con `ajustes` recibe `422`.
- **Numeración que no se reusa.** `conduce_secuencia` guarda el último número: `SELECT … FOR
  UPDATE` y `GREATEST(ultimo, MAX(numero)) + 1` en la transacción de crear, con el `UNIQUE`
  de `numero` como red de seguridad.
- **Nada se borra.** Eliminar pone `activo = 0`; editar pone `activo = 0` a las líneas de
  antes e inserta las nuevas. Un conduce sin cotización tiene `cotizacion_id` `NULL`, y si se
  borra la cotización de origen el conduce queda igual (FK `ON DELETE SET NULL`): los dos
  casos son la misma fila y la pantalla dice "Sin cotización". Restaurar uno eliminado es un
  `UPDATE conduces SET activo = 1` de soporte.
- **El PDF** es `FerreteriaCotizacionPdf` con `documento => 'conduce'`: título
  `CONDUCE DE MERCANCÍA`, la línea `Cotización: COT-…` (solo si el conduce tiene cotización),
  las columnas `Cantidad | Unidad |
  Descripción mercancías` con el nombre de cada unidad, sin valores ni totales, y el mismo
  "Recibido por:" y pie. El renderizador sigue puro: `FerreteriaConduce` le pasa los nombres
  de las unidades ya resueltos.
- **Facturar** desde un conduce prellena la factura e-CF o simple con sus líneas (precio,
  ITBIS, producto y unidad). Una línea sin precio no se puede emitir ni guardar hasta que se
  le escriba uno.
- **Auditoría:** módulo `conduces`, entidad `conduce` (CREATE, UPDATE y DELETE).

### Despliegue de los conduces

1. **Respaldo** de las dos DBs de tenant (`smhynzte_002`, Ferretería, y
   `smhynzte_new_gratexdb`, Gratex) y, si la 012 falta en el master (paso 3), de la base master.
2. **Los verificadores** (solo lectura): `tools/verificar_migraciones_master.sql` en la base
   master (su fila `012` es la del POS) y `tools/verificar_migraciones_tenant.sql` en las dos
   DBs de tenant: ver qué dicen de la 012 del master y de la 027, la 028, la 029 (precios) y
   la 030 (POS) del tenant.
3. **En la base master (no en las de tenant): `db/master_migrations/012_pos.sql`** donde su fila
   diga `FALTA`, y antes de subir el código del POS, como pide su cabecera. Agrega
   `tenants.pos_enabled` (en 0: nadie ve el POS hasta que se active), `pos_equipos` y
   `pos_handoff_codes`.
4. **Correr la 027, después la 028, la 029 (`029_precios_4_decimales.sql`) y la 030
   (`030_pos.sql`)** en la base de tenant donde digan `FALTA`, según el ORDEN de la cabecera
   de cada una. Este despliegue también lleva el código que las necesita. La 030 es la del
   POS: su cabecera la pide solo en las empresas que vayan a usar el POS (va junto con la
   012 del master) y dice que no estorba en las demás. Corre la 030 igual en las dos DBs: así
   el verificador termina entero en `APLICADA` y las dos bases quedan con el mismo esquema.
   Es inofensiva donde no se usa el POS (solo agrega tablas y columnas, no toca datos) y
   obligatoria donde se vaya a usar.
5. **Correr la 031 (`031_conduces.sql`)**, fuera de horario y después de la 030, en las dos
   DBs: pegar el archivo completo en la pestaña SQL con la base seleccionada y leer la fila
   final (`base` = esa base, las tres tablas `InnoDB`, `fila_secuencia` = `(1, 0)`, `todo_ok`
   = `SI`).
6. **Los verificadores otra vez:** en las dos DBs de tenant, de la 027 a la 031 tienen que
   decir `APLICADA`, y en el master la 012, antes de desplegar.
7. **Subir `api-gratex` y después `fiscalo`.** No hay ajuste que cambiar: Ferretería ya tiene
   `cotizacion_formato = 'ferreteria'`.
8. **Pruebas de humo** (`tests/test_conduces.http`, sección Producción). **Decisión del
   2026-10-08:** se hace la prueba completa y **gasta `CON-000001`** (el conduce de prueba queda
   con `activo = 0` y su número no se reusa): el primer conduce real de Ferretería será
   `CON-000002`. Como Ferretería, en este orden:
   1. crear un conduce desde una cotización real, con una línea libre sin precio (bloque C1, o
      el botón Conduce de la cotización);
   2. editarlo sin quitar esa línea (bloque C7 con la línea libre añadida a su cuerpo, o desde
      la pantalla del conduce);
   3. abrir su PDF (bloque C11, o el botón PDF de la lista): `CONDUCE DE MERCANCÍA`, sin precios;
   4. Facturar > Factura electrónica (e-CF) desde la lista de Conduces, con esa línea en precio
      0: Emitir e-CF (y Vista previa) tiene que quedar bloqueado, con «Escribe el precio: en el
      conduce esta línea no tenía.» bajo la línea. No escribir el precio, no emitir y salir sin
      guardar (es la prueba del riesgo de `MontoItem` 0 ante la DGII, y se hace en la pantalla,
      no en el `.http`);
   5. eliminarlo (bloque D1 con el id de ese conduce, o Eliminar en la pantalla del conduce):
      queda con `activo = 0` y deja de salir en la lista.

   Como Gratex: nada cambió y no hay menú Conduces. La comprobación de solo la vista previa
   (bloque C9) sigue existiendo y no gasta número, pero no ejercita el bloqueo del precio 0.

### Redondeo

`Redondeo::r($x, $dec)` copia `montosLinea.redondear`: pre-redondeo a 15 cifras
(`sprintf('%.15h')`) y `round` sobre ese valor, con el signo aparte. Da lo mismo en
PHP 8.3 (producción) y 8.5: con `round()` a secas, 84.75 × 18% da 15.25 desde 8.4 y
15.26 en 8.3. Va con `%h` y no con `%g` porque `%g` toma el separador decimal del
locale (con uno de coma imprimiría `1525,5`, el `(float)` leería `1525` y el redondeo
se volvería truncamiento); `%h` es el `%g` con el punto fijo. Solo lo usan los
formatos de `src/Utils/Cotizacion/`; Gratex y la facturación siguen con su `round()`.

---

### Pruebas

| Qué | Cómo |
|-----|------|
| Reglas, totales contra las hojas, validación, número, PDF, modelo con una conexión falsa, registro | `php tools/test_cotizacion_ferreteria.php` (desde `api-gratex`, sin DB; termina en `N/N OK` y sale con 1 si algo falla) |
| PDF para comparar con el Excel | `php tools/test_cotizacion_ferreteria.php --pdf` (o `--grid`, con la rejilla de 10 mm) → `tools/out/` |
| Orden de las FK del snapshot y SQL dinámico de la 026 y la 031, sin MySQL | `php tools/check_tenant_schema_orden.php` (`--mostrar` imprime el SQL armado) |
| Paridad del front | `node scripts/parity-cotizacion-ferreteria.ts` (desde `fiscalo`) |
| API real (crear, editar, vista previa, PDF, borrar, cada `422`, el `409`, la regresión de Gratex) y las comprobaciones M1-M12 de servidor: la 026 dos veces sobre un volcado de cada DB de tenant, la numeración con cinco creaciones simultáneas (`GET_LOCK`) y el estado que queda en la base | `tests/test_cotizaciones_ferreteria.http` contra un servidor con las migraciones |
| Conduces: reglas, número, PDF en modo conduce, el modelo con una conexión falsa y las piezas del controller | `php tools/test_conduces.php` (sin DB; `--pdf` → `tools/out/`) |
| Conduces contra MySQL de verdad (la base scratch): la 031 dos veces, el snapshot nuevo, conduceModel de punta a punta (con la búsqueda por el código de la cotización), un 1062 real, cinco creaciones en paralelo y las reglas ON DELETE | `php -d extension=pdo_mysql tools/test_conduces_mysql.php` (credenciales en `tools/.env`, ignorado por git; solo `smhynzte_conduces_scratch`) |
| API real de los conduces (crear, listar, editar, PDF, vista previa, eliminar sin borrar ni reusar el número, el `401` antes del `422`, cada `422`, Gratex sin cambios) y las comprobaciones M1-M17 de servidor: la 031 dos veces y su fila final, cinco creaciones simultáneas y el estado que queda en la base | `tests/test_conduces.http` contra un servidor con la 031 |

El CLI nunca abre una base: el modelo se crea sin constructor y con una conexión
falsa. `crear()`, `actualizar()`, `preview()` y `pdf()` de un formato sí leen la base
(unidades, cliente, emisor), y se prueban con el `.http`.

---

### Agregar un formato para un tenant nuevo

Ejemplo: el tenant "Acme" quiere su propia hoja. La clave será `acme`.

1. **Reúne la hoja.** El archivo que usan hoy (Excel/PDF), con 2 o 3 ejemplos reales
   llenos: las líneas, los totales y cada fila extra. De ahí salen las reglas y el
   fixture.
2. **Decide si hacen falta datos nuevos.** Las líneas (`cotizacion_items`, con
   producto, unidad e indicadores) y los montos extra (`cotizacion_ajustes`, con los
   conceptos que tú declares) ya cubren la mayoría. Solo si falta algo, escribe una
   migración de tenant nueva (idempotente, como la 026) y refléjala en
   `db/tenant_schema.sql` y [../database/schema.md](../database/schema.md).
3. **Fixture.** `tools/fixtures/cotizacion_acme.json` con cada línea de los ejemplos
   y los totales esperados. El front usa una copia byte por byte.
4. **Reglas puras primero (TDD).** Crea `src/Utils/Cotizacion/AcmeFormato.php` con
   las funciones estáticas (totales, validación del cuerpo, textos del PDF) y pruébalas
   en un script CLI sin DB (`tools/test_cotizacion_acme.php`, con el mismo estilo
   `[OK  ]` / `[FALLA]` y `exit 1`). Usa `Redondeo` para todo monto.
5. **El PDF.** `src/Utils/Cotizacion/AcmeCotizacionPdf.php`, puro como
   `FerreteriaCotizacionPdf`: recibe la cotización, el emisor, el cliente y el logo, y
   devuelve los bytes. Agrega al CLI un `--pdf` para compararlo con la hoja.
6. **El contrato.** En `AcmeFormato`: `final class AcmeFormato extends
   CotizacionFormato`, `__construct(cotizacionModel $modelo)`, `nombre()` devuelve
   `'acme'`, y `crear` / `actualizar` / `preview` / `pdf` devuelven las tuplas del
   contrato. Para numerar y guardar usa `cotizacionModel::crearConFormato()` (pásale
   `'acme'` como formato) y `actualizarConFormato()` (no cambia el formato), y declara
   tus conceptos en `$cot['ajustes']`, con su monto bajo la misma clave en los totales.
   Hoy el código visible sale de `FerreteriaFormato::codigo()` (`COT-000001`); si Acme
   necesita otro, agrega ese parámetro al modelo en el mismo cambio. Si el formato
   manda correo, sobreescribe `permiteCorreo()`.
7. **Regístralo.** Una línea en `CotizacionFormatos::FORMATOS`:
   `'acme' => AcmeFormato::class,`. El archivo se tiene que llamar como la clase
   (`AcmeFormato.php`): `para()` lo carga por ese nombre.
8. **Frontend.** Una carpeta `src/features/cotizaciones/formatos/acme/` con su
   formulario (que mande `"formato": "acme"` en cada POST, PUT y vista previa), su
   `totales.ts` si la pantalla calcula (misma cuenta que el PHP, con
   `scripts/parity-cotizacion-acme.ts` sobre la copia del fixture) y su conversión si
   se factura. Agrega `'acme'` a `FormatoId` y a `FORMATOS` en
   `formatos/index.ts`, y sus columnas y acciones en `CotizacionesView`.
9. **Docs.** Una sección en [../api/cotizaciones.md](../api/cotizaciones.md) y una
   fila en las tablas de este documento.
10. **Despliegue.**
    1. Si hay migración: quita el módulo `cotizaciones` de los roles de Acme antes de
       correrla (si no, Acme podría guardar cotizaciones de Gratex entretanto) y córrela
       fuera de horario.
    2. Sube `api-gratex`, después `fiscalo`.
    3. Activa: `UPDATE tenants SET cotizacion_formato = 'acme' WHERE id = <id de Acme>;`
       (en el master).
    4. Prueba como Acme (crear, PDF contra la hoja, editar, Facturar si aplica) y como
       Gratex (que nada cambió). Devuelve el módulo a los roles de Acme.

### Despliegue inicial (Ferretería)

El orden importa: sin él, Ferretería podría guardar cotizaciones con el formato (y la cuenta bancaria) de Gratex.

1. **Confirmar en producción**, antes de nada:
   - `SHOW CREATE TABLE` de `cotizaciones`, `cotizacion_items` y `products` en las dos DBs de tenant: motor, tipo de
     `id`, definición de `client_name`, si existen `user_id` / `updated_at` (el paso 0 de la 026 lo muestra igual);
   - `SELECT COUNT(*) FROM cotizaciones` en la DB de Ferretería (se espera 0);
   - `id`, `pdf_template` y `logo_path` de Ferretería en `master.tenants`;
   - que la 025 está aplicada y que los roles de Ferretería tienen el módulo `cotizaciones`;
   - el `emisor_config` de Ferretería: `telefono`, `correo`, `razon_social` = `FERREHERRAMIENTAS VENTURA, SRL` y una
     `direccion` que quepa en dos líneas.
2. **Quitar el módulo `cotizaciones` de los roles de Ferretería** antes de correr la 026 (se devuelve en el paso 6).
3. **Migraciones, fuera de horario:** la master `011` una vez y la tenant `026` en cada DB de tenant, completas, antes
   de subir el código (comprobaciones M1-M5 de `tests/test_cotizaciones_ferreteria.http`).
4. **Subir `api-gratex` y después `fiscalo`.** El backend asume `gratex` y el front también si branding llega sin el
   campo; las pestañas abiertas reciben el `409` hasta que recarguen.
5. **Activar Ferretería:** `UPDATE tenants SET cotizacion_formato = 'ferreteria' WHERE id = <id>;` en el master.
6. **Pruebas de humo:**
   - como Ferretería: crear una cotización, comparar su PDF con la hoja de Excel y Facturar a e-CF (sin emitir) y a
     factura simple;
   - como Gratex: listar, abrir, editar y guardar, vista previa, PDF y Facturar (borrador igual que antes, interruptor
     de ITBIS encendido);
   - devolver el módulo `cotizaciones` a los roles de Ferretería.

Volver atrás: ver la sección siguiente (volver a `gratex` y bajar el código).

### Cambiar el formato de un tenant (ops)

- Es solo SQL en el master: `UPDATE tenants SET cotizacion_formato = '<clave>' WHERE id = <id>;`.
  No hay pantalla para elegirlo: un formato es código hecho para un cliente.
- Las cotizaciones ya guardadas conservan su formato. Las pestañas abiertas reciben
  el `409` hasta que recarguen.
- **Volver a `gratex`** hace que las cotizaciones nuevas salgan con el PDF de Gratex
  (con su cuenta bancaria). Si hace falta, quita también el módulo `cotizaciones` de
  los roles de ese tenant hasta arreglar su formato.
- **Bajar el código de `api-gratex`** a una versión sin formatos no está soportado
  una vez que existe alguna fila con `formato = 'ferreteria'`: el código viejo las
  imprimiría con el PDF de Gratex y les quitaría los productos al editarlas.

### Fuera de alcance (pendientes)

- El envío por correo para Ferretería (el correo por tenant ya existe en `TenantMail`).
- Ofrecer E45 Gubernamental en la factura e-CF.
- Un estado "facturada" o un enlace de la factura a su cotización.
- Una pantalla para elegir el formato.
- La vista previa de cotización en `/api/branding/preview` y `plantillas.php`.
- `custom:ferreventura` falla los chequeos `custom:tenant<id>` de `PUT /api/branding`.
- Las consultas N+1 del listado de cotizaciones.
- Conduces: lo entregado contra lo pendiente, varios conduces en una factura, un conduce desde
  cero o hacia una cotización, marcarlo "facturado", un permiso propio, restaurar uno
  eliminado desde la pantalla y conduces para otros formatos.
