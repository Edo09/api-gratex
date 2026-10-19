# Inventario — Categorías y Almacenes

Primera fase del módulo de Inventario: **categorías** y **almacenes (warehouses)**, integrados al
catálogo de productos. Backend. Más abajo, lo que se sumó después: las ventas y compras que mueven
la existencia, el valor de inventario y el
[control de existencias en las ventas](#control-de-existencias-en-las-ventas).

> **Frontend:** la guía de integración (contratos request/response, errores, flujo de UI) está en
> [../api/inventario.md](../api/inventario.md).

> Multi-tenant: cada empresa es un tenant con su **propia DB**, así que estas tablas viven en la
> DB del tenant y el aislamiento es **inherente** — no llevan `company_id`. Sin soft-deletes: hay
> un flag `estado` (1=activo/0=inactivo) para desactivar, y borrado físico con guardas por FK.

## Tablas (DB del tenant)

| Tabla | Columnas |
|---|---|
| `categories` | `id`, `nombre` (UNIQUE), `descripcion?`, `estado`, `created_at`, `updated_at` |
| `warehouses` | `id`, `nombre` (UNIQUE), `descripcion?`, `estado`, `created_at`, `updated_at` |

`products` gana `category_id` (FK `categories` `ON DELETE SET NULL`, **opcional**) y `warehouse_id`
(FK `warehouses` `ON DELETE RESTRICT`, **obligatorio**). El viejo texto libre `products.categoria`
se migró a `categories` y se eliminó (migración `017_add_inventory.sql`).

- **Almacén por defecto:** cada empresa tiene `Almacén Principal` (sembrado por
  `tenant_schema.sql` / la migración). Si un producto se crea sin `warehouse_id`, se le asigna.
- No se puede **borrar** `Almacén Principal`, ni un almacén con productos (FK RESTRICT → 400).
- Borrar una categoría deja sus productos sin categoría (`category_id` → NULL).

## API

Cada ruta requiere token y su propio módulo RBAC — **`categories`** y **`warehouses`** (módulos
separados: un rol puede tener uno sin el otro). El rol `user` trae ambos por defecto; ver
[roles-permisos.md](roles-permisos.md). Mismo contrato que `/api/products`.

| Método | Ruta | Acción |
|---|---|---|
| GET | `/api/categories` | listar (`?page,pageSize,query`) o `?id=` |
| POST | `/api/categories` | crear `{nombre, descripcion?, estado?}` |
| PUT | `/api/categories/{id}` o `{id,...}` | actualizar |
| DELETE | `/api/categories` `{id}` | borrar |
| GET/POST/PUT/DELETE | `/api/warehouses` | igual (almacenes) |

Respuestas: `{status:true,data}` / `{status:false,error}`; listas con `pagination`.

## Productos

`GET /api/products` acepta `?category_id=` para filtrar el listado por categoría.
`POST/PUT /api/products` aceptan `category_id` (nullable) y `warehouse_id` (si se omite al crear →
`Almacén Principal`). Las listas y el detalle exponen `categoria_nombre` y `almacen_nombre`
(nombres, vía JOIN), además de los ids. Ver [../api/facturas.md](../api/facturas.md) (sección
productos) y [../database/schema.md](../database/schema.md).

### Foto del producto (migración 032)

Una foto por producto, en `products.imagen_path` (ruta relativa al API; `NULL` = sin foto).
Las listas y el detalle la devuelven con el resto de la fila; el catálogo del POS la manda en
`imagen` (`GET /api/pos/catalogo`).

- **`POST /api/products/imagen`** — multipart: `id` + `imagen` (JPG, PNG o WebP; hasta 5 MB
  o lo que deje subir el servidor, y a lo sumo 4096 px por lado). Responde
  `{id, imagen_path}`; si ya tenía foto, borra el archivo anterior. Errores: `422` (no es
  una imagen que se pueda usar, demasiado grande, sin `id`), `400` (sin archivo), `404`
  (producto que no existe).
- **`DELETE /api/products/imagen`** `{ "id": 12 }` — la quita y borra el archivo.
- Permiso: el módulo `products`, igual que editar el producto. Bitácora
  `IMAGEN_CAMBIADA` / `IMAGEN_QUITADA`.
- El archivo va a `public/uploads/productos/<tenant>/<32 hex>.<ext>`: Apache lo sirve directo
  (el `.htaccess` deja pasar los archivos de `/api/public/`), así que se ve con un `<img>`
  sin token. El tipo sale del contenido (`finfo` + `getimagesize`, como la landing), el
  nombre lo genera el servidor y la ruta guardada solo puede apuntar a esa carpeta. Borrar
  un producto borra su foto. La carpeta está en `.gitignore`: son datos de las empresas.
- app.\* reduce la foto en el navegador (800 px por lado, JPEG) antes de subirla: pesa
  decenas de KB, no choca con `upload_max_filesize` y se van los datos GPS del teléfono.
  `src/lib/fotoProducto.ts` en fiscalo-react; solo pinta rutas con la forma del servidor.
- El formulario sube la foto **después** de guardar el producto: si la foto falla, el
  producto queda guardado y se avisa (el formulario se cierra para no crearlo dos veces).
- Prueba: `tools/test_producto_imagen.php` (20 verificaciones, API local).

## Archivos
`db/migrations/017_add_inventory.sql`, `db/master_migrations/004_add_inventory_permission.sql`,
`src/Models/categoryModel.php`, `src/Models/warehouseModel.php`,
`src/Controllers/categoryController.php`, `src/Controllers/warehouseController.php`,
`config/permissions.php` (módulos `categories` + `warehouses`), `src/Router.php`, `src/Models/productModel.php`,
`src/Controllers/productController.php`, `db/tenant_schema.sql`. Foto: `db/migrations/032_producto_imagen.sql`,
`src/Utils/ProductImageStorage.php`, `tools/test_producto_imagen.php`.

---

## Ventas e-CF

Una factura e-CF (`/api/facturas`) mueve existencias en las líneas con `product_id` que
el catálogo marca como bien: la venta sale como `VENTA` y la nota de crédito `E34` entra
como `DEVOLUCION`, con `referencia_tipo = 'factura'` (`inventoryModel::registrarVenta`).

- **Solo con una respuesta de la DGII que no esté en `EstadosEcf::SIN_STOCK`; nunca para
  una fila en vuelo.** La fila se guarda antes del envío (write-ahead) y no mueve nada
  mientras está en vuelo (`PENDIENTE_ENVIO`, `ENVIO_INCIERTO`). Con cualquier otro estado
  sí mueve, también con `ENVIADO`, `EN_PROCESO` y sus `RFCE_`, aunque la DGII todavía no
  haya dado su resultado final. No mueve con un estado de `EstadosEcf::SIN_STOCK`:
  `RECHAZADO`, `RFCE_RECHAZADO`, `NO_ENCONTRADO`, `RFCE_NO_ENCONTRADO`, `ERROR`,
  `RFCE_ERROR`.
- **Una sola vez por factura.** Mueve la emisión, cuando la DGII responde otro estado, o
  `GET /api/facturas/{id}/estado` la primera vez que ve la factura pasar a
  `ACEPTADO`/`ACEPTADO_CONDICIONAL` (o sus `RFCE_`). Los dos preguntan antes
  `inventoryModel::yaMovioDocumento('factura', id)`: el que llega primero mueve.
- **Un rechazo posterior no lo revierte**: se corrige con un ajuste, igual que en compras.
- Una venta vieja que ya estaba aceptada sin movimientos (de antes del inventario) no
  mueve al consultarla: solo cuenta el paso a aceptado.
- Con la empresa en «Bloquear ventas sin existencia», una revisión previa corre **antes** de
  reservar el e-NCF y puede rechazar la factura con `422`; el descuento de arriba no cambia.
  Ver [Control de existencias en las ventas](#control-de-existencias-en-las-ventas).

## Compras

Registrar una compra en `/api/gastos` mueve existencias en las líneas con `product_id`:
`E31`/`E41`/`E47` entran como `COMPRA` y `E34` sale como `DEVOLUCION` (devolución al
proveedor), con `referencia_tipo = 'gasto'`. Se valoriza al precio de la línea, así que la
compra alimenta el costo promedio de la sección siguiente. Reglas completas en
[gastos.md](gastos.md#inventario).

## Control de existencias en las ventas

«Bloquear ventas sin existencia»: el número de existencia del sistema manda, y una factura (e-CF)
o una factura simple que pide más de lo que dice se rechaza. Cada empresa lo decide; **está
apagado por defecto** y apagado el sistema se comporta **exactamente como antes** (se vende sin
existencia y el saldo queda en negativo). Contrato de los endpoints y del error en
[../api/inventario.md](../api/inventario.md#control-de-existencias-en-las-ventas); el diseño y las
decisiones, en `docs/superpowers/specs/2026-10-19-control-existencias-ventas-design.md` (repo
fiscalo).

### El interruptor

- Es `master.tenants.inventario_estricto` (`TINYINT(1) NOT NULL DEFAULT 0`), migración master
  `013_tenants_inventario_estricto.sql`. La migración **no enciende a nadie**; sin ella, el código
  lee el interruptor como apagado, así que el API puede subir antes que la migración.
- Lo lee `TenantResolver::inventarioEstricto()` (solo empresas tipo `app`; sin empresa resuelta, o
  en una instalación de una sola empresa, siempre es `false`) y lo cambia un admin en
  Configuración › Inventario con `PUT /api/inventario/configuracion` (solo el rol `admin`, bitácora
  `INVENTARIO_CONFIG`). `GET /api/branding` lo trae como `inventario_estricto` para que la app sepa
  si debe mostrar la existencia.
- Encender una empresa es un paso aparte: antes se revisa, solo lectura, cuántos Bienes están en 0
  o sin existencia (`GET /api/inventario/configuracion` lo cuenta), porque en cuanto se enciende no
  se pueden facturar hasta que un Ajuste les agregue existencia.

**Antes de encender (Ferretería).** Orden: primero la migración master `013` (idempotente, no
enciende a nadie; `tools/verificar_migraciones_master.sql` dice si ya está), luego subir el API,
luego el front y, al final, encender la empresa. El API sube sin la migración y todo queda apagado,
pero el interruptor de Configuración no guarda hasta que exista la columna. Antes de encender, solo
lectura:

- En la master, confirmar que `tenants.id = 2` es Ferretería (el diseño lo da por supuesto; no está
  verificado): `SELECT id, nombre, rnc, tipo, db_name FROM tenants WHERE id = 2;`
- En la base de esa empresa, listar los Bienes que cuenta `productos_sin_existencia` (la misma
  condición que `inventoryModel::contarSinExistencia`):

  ```sql
  SELECT id, nombre, sku, stock FROM products
  WHERE activo = 1 AND indicador_bien_servicio <> 2 AND (stock IS NULL OR stock <= 0)
  ORDER BY nombre;
  ```

  Cada fila es un producto que no se podrá facturar en cuanto se encienda, hasta que un Ajuste
  `ENTRADA` le dé existencia. Conviene mirar sobre todo los cargos del catálogo (flete,
  instalación) que estén marcados como Bien en vez de Servicio: se bloquearían.
- Prueba de humo, ya encendida: intentar una venta de más de lo que hay (por ejemplo una factura
  simple de un producto en 0). Tiene que responder `422` `STOCK_INSUFICIENTE`, no guardar nada y
  dejar una fila `success=0` en la bitácora ([auditoria.md](auditoria.md)).

**Apagar y deshacer.** Apagada, la empresa vuelve **exactamente** a como era antes. Se apaga con el
mismo interruptor en Configuración › Inventario o con `PUT /api/inventario/configuracion`
`{"bloquear_sin_existencia": false}`; si la pantalla no está disponible (por ejemplo, se volvió a
un front viejo), en la master:

```sql
UPDATE tenants SET inventario_estricto = 0 WHERE id = <id> AND tipo = 'app';
```

Surte efecto en la **siguiente petición**: la empresa se lee de la master en cada petición, y no
hace falta desplegar nada. No hay datos que deshacer: las ventas y los movimientos del libro se
quedan, y los saldos negativos que hayan quedado (por el POS, la ventana del e-CF o una devolución a
un proveedor) siguen como están hasta que un Ajuste los corrija. Si se vuelve a una versión vieja
del API, la columna se queda: vale 0 por defecto y el API viejo no la lee (las migraciones master
no traen script para deshacerse).

### Qué se revisa

Lo mismo que descuenta el inventario (**revisa = descuenta**): las líneas con `product_id` de un
**Bien**, en todo e-CF salvo **E34 y E41** (entradas: `inventoryModel::esEntrada`) y en la factura
simple. Se suman las líneas del mismo producto antes de comparar y se compara en **milésimas
enteras**; igual pasa, con existencia 0 se rechaza cualquier cantidad y una existencia vacía
(`NULL`) cuenta como 0. Nunca se revisan los servicios, las líneas sin `product_id` ni el
**reenvío** de un e-CF rechazado (`$reenvioDe !== null`: es una obligación fiscal por mercancía ya
vendida). Un producto que no existe o una cantidad ≤ 0 dan su propio `422` llano, no un faltante.

El cálculo vive en `inventoryModel` (`calcularFaltantes` puro, `verificarDisponibilidad` con la base).
«Cuánto hay del producto X» **no** se responde en un solo lugar: lo calculan cuatro funciones, y el día
que haya saldo por almacén hay que cambiarlas juntas:

- `disponibleEnMilesimas`: el número que compara `calcularFaltantes` (`products.stock` en milésimas;
  vacío cuenta como 0).
- `catalogoParaVenta`: el `SELECT` de la revisión previa, que lee `products.stock` sin bloquear.
- `aplicarMovimientos`: el saldo que lee con la fila bloqueada (`$inicial` y `$saldos`), la
  comprobación final de la factura simple.
- `contarSinExistencia`: el `WHERE stock IS NULL OR stock <= 0` del conteo de Configuración.

### Cuándo se revisa y cuándo baja la existencia

**e-CF (`POST /api/facturas`).** La revisión (`verificarVentaEcf`) corre en `emitirFacturaEcf`
**antes de armar el payload y de reservar el e-NCF**: un rechazo después de dispensar el número
puede dejar un hueco en la secuencia fiscal, y por eso el contador de la serie no se mueve cuando
se rechaza. Corre también con `strict_input`. La existencia **baja después del veredicto de la
DGII** (`moverStockFacturaEcf`, sin cambios; ver [Ventas e-CF](#ventas-e-cf)), no al guardar. Dos
consecuencias aceptadas:

- Entre la revisión y el descuento pasa toda la emisión, o sea la ida y vuelta con la DGII. Cada
  llamada a la DGII espera hasta `DGII_ECF_TIMEOUT` (30 s por defecto) y la emisión hace al menos
  dos (el token y el envío), así que con la DGII lenta la ventana puede ser de más de 30 s. Dos
  ventas de la última unidad dentro de esa ventana pueden pasar las dos. Cuando un descuento deja el
  saldo bajo 0 con el interruptor encendido, el log del servidor dice
  `[inventario] SOBREVENTA factura <id> producto <pid> saldo <n>` (también cuando la venta que lo
  deja así es del POS), para poder medir cuántas ocurren.
- Una fila en vuelo (`PENDIENTE_ENVIO`, `ENVIO_INCIERTO`) no retiene existencia: una segunda venta
  no la ve hasta que la DGII responde.

Si la revisión misma falla (la base no responde, falta el archivo del modelo), no se rechaza nada: se
anota `[inventario] no se pudo verificar la existencia del e-CF tipo <tipo>: <motivo>` en el log y
**la venta sigue**. Una línea así significa que **esa venta no se revisó**; no deja fila en la
bitácora. La regla es un resguardo, no una dependencia fiscal. La factura simple es lo contrario:
si el libro falla dentro de su transacción, no se guarda (ver abajo).

**Factura simple (camino atómico).** Con el interruptor encendido, `POST` y `PUT` (cuando trae
`items`) revisan y descuentan **dentro de la transacción de la propia factura**:
`facturaModel::createFacturaSimple` y `updateFacturaSimple` aceptan un callback `$enTransaccion`
que se llama después de escribir la cabecera y antes de las líneas y del `commit`
(`inventoryModel::moverVentaEnTransaccion`). Si falta existencia la factura no se guarda y no se
mueve nada; el `registrarVenta` de después del commit **no** se llama en ese camino (contaría doble).

- El callback usa la **conexión que le entrega el modelo**, no una nueva: el controlador arma el
  modelo antes de resolver la empresa, y un `new inventoryModel()` tardío abriría otra conexión
  (el singleton de `Database` se reinicia al resolver la empresa). En otra conexión el bloqueo de
  la fila se soltaría al instante y un rollback de la factura no desharía el descuento (por eso el
  constructor de `inventoryModel` acepta un `PDO`).
- El `PUT` primero **devuelve** las líneas que la factura ya tenía y luego revisa las nuevas: solo
  el aumento neto puede faltar. Subir de 3 a 5 con existencia 1 falta 1 (disponible = 1 + los 3 que
  la factura ya tiene = 4), y re-guardar con la misma cantidad y existencia 0 pasa. Un `PUT` sin
  `items` no mueve nada.
- El `DELETE` no cambia: devuelve lo que la factura sacó.
- Revisar y bloquear son dos pasos: la revisión lee sin bloquear, y `aplicarMovimientos` vuelve a
  comprobar con la fila ya bloqueada (bandera `bloquear_sin_existencia`), que es la palabra final
  si otra venta se llevó la existencia en medio. Por eso dos cajas que venden la última unidad a la
  vez nunca dejan -1: una pasa y la otra recibe el `422`.
- Si el libro **falla** dentro de la transacción (error de base, espera de bloqueo vencida, sin
  transacción abierta), la factura simple tampoco se guarda y responde `400` «No se pudieron
  actualizar las existencias. Inténtalo de nuevo…» (el detalle va al log como
  `[inventario] aplicarMovimientos …` o `[inventario] moverVentaEnTransaccion factura <id> …`). Es la
  asimetría con el e-CF: el e-CF ante un fallo de su revisión sigue; la factura simple se detiene.

### El libro

- `aplicarMovimientos` bloquea las filas **en orden de `product_id`**, sin importar el orden de las
  líneas: dos documentos con los mismos productos en orden distinto ya no se bloquean cruzados (antes
  el deadlock se tragaba como «venta sin movimiento»). Los movimientos se escriben en el orden en
  que llegaron las líneas. El `PUT` de factura simple hace dos pasadas (devolver las líneas viejas y
  vender las nuevas), así que antes bloquea de una vez, de menor a mayor, la unión de los productos de
  las dos: el orden de bloqueo es ascendente en toda la transacción y no solo dentro de cada pasada.
- Sin la bandera `bloquear_sin_existencia` el saldo negativo se tolera, como siempre (camino de
  después del commit, compras, POS, ajustes). Un Ajuste manual **no** bloquea las salidas bajo cero:
  es la herramienta para corregir el libro.
- Dos motivos nuevos de Ajuste, `ENTRADA` (mercancía que llega) e `INVENTARIO_INICIAL`
  (`inventory_adjustments.motivo` es `VARCHAR(20)`: sin migración). `ENTRADA` es el motivo con que el
  aviso «no hay existencia» abre «Ajuste de inventario» ya llenado.

### El formulario de producto

Con el interruptor encendido la existencia solo se mueve por el libro: en `PUT /api/products` el
`stock` se **ignora** en silencio, y en `POST` la existencia inicial de un Bien entra como ajuste
`INVENTARIO_INICIAL` en la misma transacción que el producto. Apagado, el formulario escribe
`products.stock` como siempre. Detalle en
[../api/inventario.md](../api/inventario.md#producto-con-el-interruptor-encendido).

### Lo que queda fuera (límites aceptados)

- **El POS** no se revisa ni consulta el interruptor: decisión 7 de [../specs/pos.md](../specs/pos.md)
  («existencia en 0: se vende y se avisa»). Una venta del POS puede dejar un producto en 0 o bajo 0 y
  sus facturas se rechazan hasta que un Ajuste lo reponga. Tampoco se revisan conduces, cotizaciones
  ni gastos: un conduce o una cotización **no reserva** existencia, y la factura que sale de ellos se
  revisa recién al guardarla (puede recibir el `422`).
- **Líneas sin `product_id`:** un cliente de la API puede omitirlo y vender sin revisión.
- **Un rol con el módulo `products` puede esquivar la revisión:** `PUT /api/products` acepta
  `indicador_bien_servicio`, así que reescribir un producto como Servicio lo saca de la revisión. El
  interruptor protege contra el error, no es una frontera de autorización.
- **Filas e-CF en vuelo** no retienen existencia (arriba), y la ventana entre la revisión y el
  descuento de un e-CF es la ida y vuelta con la DGII, que con la DGII lenta pasa de 30 s.
- **Un `PUT` de factura simple sin cambios también se rechaza** si la existencia compartida del
  producto ya quedó bajo 0 por otras ventas: al devolver lo que la factura tiene no alcanza para
  volver a sacarlo. Hace falta un Ajuste primero.
- **El `PUT` de factura simple lee sus líneas viejas antes de la transacción** (la lectura previa
  que ya hacía para el `revertirVenta` de antes, `$previa['items']`). Si otro guardado cambia la
  factura entre esa lectura y la transacción, se devuelve lo leído, no lo guardado; y una factura
  vieja que nunca movió existencia (de antes del inventario) devuelve lo que nunca sacó y pasa una
  revisión que no debería.
- **La revisión dentro de la transacción nombra solo el primer producto corto.** La revisión previa
  (`verificarDisponibilidad`) lista todos los faltantes; `aplicarMovimientos`, que vuelve a comprobar
  con la fila bloqueada, se detiene en el primero (`faltantes` con una sola fila). Solo se ve cuando
  otra venta se llevó la existencia entre las dos lecturas.
- **El candado de numeración espera por las filas de producto.** La factura simple toma el candado de
  la numeración (`GET_LOCK`, espera de 5 s) antes de abrir la transacción, y con el interruptor
  encendido las filas de producto se bloquean **dentro** de él. Si otra transacción tiene una de esas
  filas más tiempo que la espera (por ejemplo, un Ajuste de muchas líneas), el `POST` que la espera
  mantiene el candado y otro `POST` puede agotar sus 5 s esperando el número: numera sin serializar,
  como ya hacía el candado cuando se agotaba
  (`[facturas] lock de numeracion ocupado: se numera sin serializar` en el log).
- **E34 de un proveedor** registrada en Gastos sigue restando sin revisar, y las compras (`E31`, `E41`,
  `E47`) siguen sumando; ninguna se toca. Una devolución a un proveedor puede dejar la existencia
  bajo 0 y las facturas de ese producto se rechazan hasta un Ajuste.
- **Notas de crédito sin vincular a las líneas de la factura original:** cualquier E34 suma la
  cantidad que traen sus propias líneas, sin compararla con lo vendido.
- **Existencia por producto, no por almacén:** `products.stock` es un solo número. «Cuánto hay» se
  calcula en cuatro funciones (ver [Qué se revisa](#qué-se-revisa)) y habría que cambiarlas juntas el
  día que haya saldo por almacén.
- **Fuera de banda:** `tools/import_productos_xlsx.php` y el SQL de soporte siguen siendo formas de
  cargar existencia sin pasar por el libro.

### Pruebas

- `php tools/test_inventario_stock.php` — sin base ni red (conexión falsa): el núcleo puro, el
  mensaje, el libro, el cableado de los controladores (con *source pins*) y el interruptor.
- `php -d extension=curl -d extension=openssl -d extension=pdo_mysql tools/test_inventario_stock_db.php`
  — de punta a punta contra las bases **scratch** (nunca producción): la API real en modo
  multi-tenant con la DGII falsa, apagado contra encendido, e-CF y factura simple (`POST`, `PUT`,
  `DELETE`), el reenvío, la nota de crédito, servicios y líneas libres, producto inexistente,
  el formulario de producto, la configuración por rol, la auditoría, `SOBREVENTA`, la salida por
  Ajuste y dos procesos que venden la última unidad. Aplica la migración master `013` a la base
  master de prueba (dos veces, para probar que es idempotente) y asegura el rango `testecf` de E34.
  Al terminar deja `inventario_estricto` en 0, pero **no** deja las bases como estaban: la columna de
  la `013` se queda en la master de prueba, la fila del rango E34 se queda en la base de la empresa de
  prueba, y la suite vacía facturas, movimientos y ajustes de esa base (de los productos solo toca los
  `INV-`), como todas las suites del write-ahead. Credenciales en `tools/.env`; ver
  `tools/write_ahead_test_lib.php`.
- Siguen verdes `tools/test_write_ahead_puro.php` (301 verificaciones; fija el descuento del e-CF)
  y `tools/check_tenant_schema_orden.php`.

## Valor de inventario

`GET /api/inventario/valor` — cuánto vale lo que hay en almacén, producto por
producto, a una fecha de corte. Con el detalle de movimientos de cada uno
(`/api/inventario/movimientos?product_id=`, el kardex que ya existía).

| Parámetro | Valores | Por defecto |
|---|---|---|
| `estado` | `activos` · `inactivos` · `todos` | `activos` |
| `warehouse_id`, `category_id` | filtro | todos |
| `hasta` | `AAAA-MM-DD` (corte) | hoy |
| `query` | busca en nombre y SKU | — |
| `page`, `pageSize` | paginación (tope 100) | 1, 20 |

Los servicios (`indicador_bien_servicio = 2`) quedan fuera: no tienen existencia.

### La existencia NO se reconstruye sumando el libro

Se parte de `products.stock` —la verdad de hoy— y se le **restan los movimientos
posteriores al corte**. Es exacto y no necesita asiento de apertura.

Eso importa aquí: el stock de este sistema se cargó directo en `products`, no por
el libro. Sumar movimientos desde cero daría **cero** para todo lo que existía
antes del primer movimiento. Un producto dado de alta después del corte se
reporta en 0: a esa fecha no existía.

### Costo promedio

Ponderado de las **entradas** del libro hasta el corte: `valor entrado / cantidad
entrada`. Un producto sin entradas registradas cae a `products.costo`, el único
costo que se conoce de él, y la respuesta lo marca con `costo_ponderado: false`
para que el front lo rotule «de ficha». Sin esa marca, un costo de ficha se lee
como un promedio calculado y no lo es.

Cada movimiento del libro trae su costo: cuando la línea no lo especifica,
`aplicarMovimientos()` usa `products.costo`. Por eso `costo_unitario` y
`valor_movimiento` nunca vienen nulos y el ponderado se puede calcular.

### Totales

Se calculan sobre **todos** los productos que pasan el filtro y se pagina después.
En un reporte de valorización el total tiene que ser el del inventario completo,
no el de la página — y ordenar por valor exige tenerlos todos. La contrapartida es
que la consulta lee el catálogo filtrado entero; con catálogos muy grandes hay que
revisarlo.

### Lo que el reporte destapa

Es su trabajo, no un defecto:

- **Existencias negativas** — con «Bloquear ventas sin existencia» apagado (lo normal) se venden
  sin bloquear (el comprobante ya se emitió), así que aparecen en rojo hasta que un ajuste las
  corrija. Con el interruptor encendido las facturas y facturas simples ya no las producen; las
  siguen produciendo el POS, la ventana del e-CF y una devolución a un proveedor (ver
  [Control de existencias en las ventas](#control-de-existencias-en-las-ventas)).
- **Servicios marcados como bienes** — en el tenant de pruebas, `ENVIO MERCANCIA`
  tenía stock 1,000,000,000 y `INSTALACION` 999,997, ambos con costo 0. Alguien
  les puso una existencia enorme para que no se agotaran. Se arregla marcándolos
  como servicio, y entonces salen del reporte solos.

### Archivos
`src/Models/inventoryModel.php` (`valorInventario`, `filaValorizada`,
`agregadosMovimientos`), `src/Controllers/inventarioController.php`,
frontend `src/features/inventory/InventoryValueView.tsx`.

Control de existencias en las ventas: `db/master_migrations/013_tenants_inventario_estricto.sql`,
`src/TenantResolver.php` (`inventarioEstricto`), `src/MasterDatabase.php` (`setInventarioEstricto`),
`src/Models/inventoryModel.php` (`calcularFaltantes`, `verificarDisponibilidad`, `verificarVentaEcf`,
`moverVentaEnTransaccion`, `respuestaExistencia`), `src/Controllers/facturaController.php`
(`emitirFacturaEcf`, `respondExistencia`), `src/Controllers/facturaSimpleController.php`,
`src/Models/facturaModel.php` (`$enTransaccion`), `src/Controllers/inventarioController.php`
(`/configuracion`), `src/Controllers/brandingController.php`, `src/Models/productModel.php`,
`tools/test_inventario_stock.php`, `tools/test_inventario_stock_db.php`.
