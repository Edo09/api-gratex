# POS FiscalPoint — Spec v1

| | |
|---|---|
| **Estado** | Aprobado para iniciar · 2026-10-08 |
| **URL** | `pos.fiscalpoint.com.do` (la app sigue en `app.fiscalpoint.com.do`) |
| **Repos** | `api-gratex` (backend) · `fiscalo-react` (front: segundo punto de entrada) |
| **Vertical** | Colmado / tienda: venta rápida en mostrador |
| **Piloto** | Gratex · térmica de 80 mm · catálogo de menos de 500 productos |
| **Piloto vendiendo** | **jueves 2026-11-05** (4 semanas) |
| **Lanzamiento v1** | 2026-11-19, tras 2 semanas de piloto sin incidentes fiscales |

Cada decisión de este documento salió de una pregunta respondida por el dueño del
producto (2026-10-07/08). Las que se tomaron **por defecto, sin preguntar**, están en
[§8](#8-decisiones-por-defecto--revisar) para revisarlas antes de construir.

---

## 1. Problema

Un colmado que factura con FiscalPoint tiene que usar el formulario de factura de
app.\*, pensado para oficina: cliente, ítems uno a uno, tipo de comprobante. No hay
escáner, ni cálculo de devuelta, ni control de caja. Con el cliente delante eso no
funciona, así que el negocio factura con otro sistema o a mano, o no factura.

Además, el dueño no tiene cómo controlar al cajero: no hay apertura ni cierre de
caja, ni cuadre del efectivo contra lo vendido.

**Evidencia:** la petición viene del negocio, con un POS de la competencia como
referencia (captura "DEMO F2"). No hay datos de uso: el piloto los produce. Por eso
las metas de [§10](#10-métricas-de-éxito) son hipótesis y se ajustan tras el piloto.

## 2. Objetivos

1. **Cobrar rápido.** Mediana de **15 s o menos** desde el primer artículo hasta la
   respuesta de emisión, en ventas de hasta 3 artículos con escáner.
2. **Cero errores fiscales.** 0 ventas rechazadas por la DGII. El total impreso, el
   total del e-CF y el precio de góndola × cantidad coinciden **al centavo**.
3. **Caja controlada.** El 100 % de los turnos cierran con conteo y diferencia
   calculada, y cada diferencia tiene **un** responsable.
4. **Ninguna venta duplicada** ni e-NCF gastado por reintentos de red.
5. **Negocio.** El POS se activa por tenant y se vende como complemento.
   Hipótesis: 3 tenants usándolo a 60 días del lanzamiento v1.

## 3. Fuera de alcance (v1)

| Qué | Por qué no ahora | Cuándo |
|---|---|---|
| Fiado / cuentas por cobrar | No existe CxC en el sistema. Fiar sin llevar el saldo es peor que no fiar | Fase 2 |
| Órdenes en espera (C001/C002) | No es crítico para el piloto | Fase 2 |
| Pago mixto (efectivo + tarjeta) | Una forma de pago por venta cubre el piloto | Fase 2 |
| Retiros / entradas de efectivo | Decisión de producto: en v1 todo efectivo que sale aparece como faltante | Fase 2 |
| Listas de precio 2–4 | Decisión de producto: siempre precio 1 + descuento del cliente | Fase 2 |
| Asignar códigos de barras desde el POS | Decisión: los códigos se cargan en la ficha del producto | Fase 2 |
| Imágenes de producto | Iniciales + color de categoría | Fase 2 |
| Integración con datafono (Azul, CardNet) | Requiere acuerdos con el adquirente | Posterior |
| Vender sin internet (cola local) | La firma vive en el servidor: sin internet no hay e-CF | Posterior, tras validar la norma |
| Tablet / Android, gaveta, báscula | Caja = PC Windows táctil + lector USB | Posterior |
| Impresora de 58 mm | No soportada hoy (solo 72/76/80). El piloto usa 80 mm | Cuando un cliente la tenga |
| Varias sucursales con stock separado | `products.stock` es único por producto | Posterior |
| Restaurante (mesas, comandas, propina) | Es otro producto | — |
| Recargas telefónicas | No es facturación | — |

## 4. Usuarios

| Persona | Qué es en el sistema | Qué hace |
|---|---|---|
| **Dueño / admin** | Usuario de app.\* con módulo RBAC `pos` (admin tiene `*`) | Activa equipos de caja, gestiona cajas y empleados POS, revisa cierres |
| **Supervisor** | Empleado POS con rol `supervisor` | Vende, y autoriza con su PIN lo sensible |
| **Cajero** | Empleado POS con rol `cajero` | Abre turno, vende, cobra, cierra su turno |
| **Cliente** | Consumidor final (E32) o empresa con RNC (E31) | Compra; pide crédito fiscal si quiere |

Los **empleados POS no son usuarios de app.\***: no tienen correo ni usuario, no
entran a app.\* y no ocupan un `users.email`/`username` del master (ambos son
`NOT NULL UNIQUE` en todo el sistema).

## 5. Historias de usuario

### Cajero
- Como cajero, quiero **escanear un artículo y que aparezca en la venta** sin tocar la pantalla, para atender rápido.
- Como cajero, quiero **ver el precio que dice la góndola** (con ITBIS), para que el total no sorprenda al cliente.
- Como cajero, quiero **escribir con cuánto paga el cliente y ver la devuelta**, para no equivocarme con el cambio.
- Como cajero, quiero que **el recibo salga solo al cobrar**, sin diálogos de impresión.
- Como cajero, quiero **emitir crédito fiscal escribiendo solo el RNC**, aunque el cliente no esté registrado.
- Como cajero, quiero **saber al instante si un código no existe**, para no cobrar algo equivocado.
- Como cajero, quiero que **un producto agotado igual se pueda vender** con aviso, porque el inventario rara vez está al día.
- Como cajero, quiero **cerrar mi turno contando billete por billete**, y que el sistema sume.
- Como cajero, quiero **bloquear la pantalla al alejarme** y volver con mi PIN.
- Como cajero, cuando **se cae el internet**, quiero que el POS me lo diga claro y no se quede colgado.
- Como cajero, si la venta **se queda pegada y reintento**, quiero que no salgan dos facturas.

### Supervisor
- Como supervisor, quiero **autorizar con mi PIN** un cambio de precio, un descuento, un artículo "Varios" o una devolución **en la caja del cajero**, sin que él cierre sesión.
- Como supervisor, quiero **cerrar el turno que otro cajero dejó abierto**, contando el efectivo, para que el nuevo cajero empiece con su propio turno.
- Como supervisor, quiero **devolver productos de una venta de hace menos de 30 días**, y que la existencia vuelva sola.

### Dueño / admin
- Como dueño, quiero **activar una PC como "Caja 1"** una sola vez, y que después solo se entre con PIN.
- Como dueño, quiero **crear empleados y que el sistema les genere un PIN**, sin que yo tenga que inventarlo.
- Como dueño, quiero **ver cada cierre**: cuánto se vendió por forma de pago, cuánto faltó o sobró y quién era el cajero.
- Como dueño, quiero **ver quién autorizó cada descuento, cambio de precio y devolución**.
- Como dueño, quiero **escribir el precio de góndola en la ficha del producto** y que el sistema calcule el neto.
- Como dueño, quiero **revocar un equipo** si la PC se pierde o se reemplaza.

### Casos límite
- Como cajero, si llego y **la caja tiene un turno abierto de otro cajero**, quiero que me diga de quién es y que necesito un supervisor.
- Como cajero, si **mi turno quedó abierto desde ayer**, quiero seguir vendiendo, pero con un aviso visible.
- Como cajero, si **la DGII rechaza la venta**, quiero ver el motivo con el carrito intacto, y que no salga ningún recibo.
- Como supervisor, si **alguien prueba PINs al azar**, quiero que el equipo se bloquee.

---

## 6. Requisitos

**Hito:** `Piloto` = tiene que estar el 2026-11-05. `v1` = entra durante el piloto y
tiene que estar antes del lanzamiento (2026-11-19). Todos son P0 salvo los de §6.10–6.11.

### 6.1 Acceso, equipos y empleados

**A1 · Botón POS en app.\*** — `Piloto`
- [ ] En el navbar, junto a "Nueva": botón con texto **POS** e ícono de impresora (`printer`).
- [ ] Visible solo si el tenant tiene `pos_enabled = 1` **y** el usuario tiene el módulo `pos` (o `*`). Oculto en móvil.
- [ ] Abre `pos.fiscalpoint.com.do` en una pestaña nueva con un código de un solo uso (A2).

**A2 · Paso de sesión de app.\* a pos.\*** — `Piloto`
- [ ] El código vence en **60 s** y sirve **una sola vez**. Se guarda solo su hash.
- [ ] Viaja después del `#` (`/#code=…`): no llega a ningún servidor ni queda en logs. El POS lo quita de la URL al leerlo.
- [ ] Código vencido, usado o inválido → pantalla de login de admin (A3) con el mensaje "El enlace venció, entra con tu usuario".
- [ ] Si el equipo **ya está habilitado** para ese tenant, el código se ignora y se va a la pantalla de PIN.

**A3 · Login de admin en pos.\*** — `Piloto`
- [ ] Usuario o correo + clave, con el login que ya existe (`POST /api/auth/login`).
- [ ] Sirve **solo para habilitar el equipo**. No hay ventas con la sesión de un admin.

**A4 · Habilitar equipo** — `Piloto`
- [ ] Con sesión de admin, el POS pide elegir la caja ("Caja 1"…) o crear una.
- [ ] El servidor entrega un **token de equipo** atado a tenant + caja. Se guarda en el `localStorage` de pos.\*; la sesión del admin se descarta.
- [ ] El token de equipo **solo** sirve para rutas `/api/pos/*`. No abre app.\* ni otras rutas.
- [ ] Revocable desde app.\* (M1). Tras revocarlo, la siguiente petición del equipo vuelve a "Equipo no habilitado".
- [ ] Una caja puede tener **un solo** equipo habilitado a la vez. Habilitar otro equipo para esa caja revoca el anterior, con confirmación.

**A5 · Empleados POS** — `Piloto`
- [ ] El admin los crea en app.\* (M1) con nombre y rol (`cajero` | `supervisor`).
- [x] Al crearlo, el sistema **genera un PIN aleatorio de 4 dígitos, único en el tenant**, y lo muestra **una sola vez**. El admin puede regenerarlo; el anterior deja de servir y el nuevo nunca es igual al anterior.
- [ ] El admin nunca elige el PIN, así que el sistema nunca dice "ese PIN ya existe" y no revela el de nadie.
- [ ] Un empleado desactivado no entra ni autoriza. Sus turnos e históricos se conservan.

**A6 · Entrada con PIN** — `Piloto`
- [ ] Pantalla con teclado numérico grande. Solo PIN, sin elegir nombre.
- [ ] PIN correcto → sesión de empleado en ese equipo.
- [x] **5 intentos fallidos seguidos en un equipo → equipo bloqueado, y cada bloqueo seguido dura el doble: 5, 10, 20, 40… minutos, hasta un día.** Entrar con un PIN válido devuelve la cuenta a cero. Cuentan también los fallos de PIN de supervisor (S1). Cada bloqueo queda auditado.
- Por qué progresivo: con PIN de 4 dígitos (10⁴ combinaciones) y "solo PIN", un bloqueo fijo de 5 minutos deja ~1,440 intentos al día por equipo y con 10 empleados alguien atina en menos de un día. Progresivo, son ~40 al día.

**A7 · Bloqueo de pantalla** — `Piloto`
- [ ] Manual (botón) y automático tras **10 min** sin actividad.
- [ ] Desbloquea el mismo empleado con su PIN. Si entra otro PIN, se aplican las reglas de turno (K4).
- [ ] El carrito en curso se conserva al bloquear.

**A8 · Activación por tenant** — `Piloto`
- [ ] `tenants.pos_enabled` (master), por defecto 0. Se activa por SQL, como `cotizacion_formato`.
- [ ] Tenant sin POS: el botón no aparece y `/api/pos/*` responde 403 "El POS no está activo para esta empresa".
- [ ] Solo tenants `tipo = 'app'`. Los de `integracion` no tienen BD.

### 6.2 Catálogo

**C1 · Carga del catálogo** — `Piloto`
- [ ] Productos con `activo = 1` e `indicador_facturacion ≠ 0` (no facturable queda fuera), con sus códigos de barras y las categorías activas.
- [ ] Se carga al entrar. Se refresca cada 5 min y después de cada venta (por la existencia).
- [ ] Búsqueda y escaneo **en memoria**, sin ir al servidor (el piloto tiene menos de 500 productos).

**C2 · Precio final** — `Piloto`
- [ ] Precio mostrado = `round(precio × (1 + tasa), 2)`, con tasa según `indicador_facturacion`: 1 → 18 %, 2 → 16 %, 3 y 4 → 0 %. Lo calcula **el servidor**, no el navegador.
- [ ] Siempre precio 1 (`products.precio`). Sin listas 2–4 en v1.
- [ ] Con el neto a 4 decimales (C7), el precio final es exactamente el que escribió el dueño.

**C3 · Grilla táctil** — `Piloto`
- [ ] Tarjetas de al menos 48 px de alto en cada zona tocable, sin depender del hover. Cada tarjeta muestra iniciales con el color de su categoría, nombre, `sku`, precio final y existencia.
- [ ] Chips de categoría con su conteo, más "Todos".
- [ ] Buscar por nombre o `sku`: busca "contiene", sin distinguir acentos ni mayúsculas.

**C4 · Semáforo de existencia** — `Piloto`
- [ ] **Agotado** si `stock ≤ 0` · **Bajo** si `stock ≤ stock_minimo` · **Disponible** en otro caso. Sin `stock_minimo`, no hay "Bajo".
- [ ] Servicios (`stock` NULL): etiqueta "Servicio", sin semáforo.
- [ ] Se usa el `stock_minimo` de cada producto, no umbrales fijos.

**C5 · Lector de código de barras** — `Piloto`
- [ ] Detecta la lectura (teclas rápidas terminadas en Enter) aunque el foco no esté en el buscador. No interfiere cuando se escribe en un campo numérico (cantidad, efectivo recibido).
- [ ] Busca primero en `product_barcodes` y después un `sku` exacto. Si encuentra → agrega 1 unidad.
- [ ] Si no encuentra → aviso visual y sonoro "Código 7501234567890 no encontrado". **No agrega nada ni ofrece asignarlo.**

**C6 · Códigos de barras en la ficha del producto (app.\*)** — `Piloto`
- [ ] Agregar y quitar **varios** códigos por producto.
- [ ] Un código es único en el tenant. Si ya pertenece a otro producto: "Ese código ya está en «Agua Planeta Azul»".

**C7 · Precio con ITBIS incluido en la ficha** — `Piloto`
- [ ] Casilla **"Este precio incluye ITBIS"** junto a "Precio (RD$)" (y a las listas 2–4).
- [ ] Marcada: se escribe 25.00 y se guarda el neto con 4 decimales (21.1864).
- [ ] `products.precio`, `precio_2`, `precio_3` y `precio_4` pasan a `DECIMAL(18,4)`.
- [ ] Hoy los dueños escriben el precio **sin** ITBIS, así que la casilla arranca desmarcada y no cambia nada de lo existente.

**C8 · Reporte "Precios a revisar"** — `Piloto`
- [ ] Lista los productos gravados con su precio final calculado. Marca como "probable centavo" los que no terminan en .00 ni .50.
- [ ] Motivo: con el neto a 2 decimales, **el 15 % de los precios finales no vuelve a su valor**, entre ellos RD$10 → 9.99, RD$100 → 100.01 y RD$250 → 249.99 (simulación de RD$1 a RD$10,000). El dueño lo corrige escribiendo el precio con la casilla C7.
- [ ] Se corre sobre el catálogo de Gratex antes del piloto.

### 6.3 Venta

**V1 · Carrito** — `Piloto`
- [ ] Agregar por toque o escaneo. Repetir un producto suma cantidad.
- [ ] `+` / `−` y cantidad editable con teclado en pantalla. Se admiten decimales solo si la unidad tiene `permite_decimales`, con máximo 2 (`CantidadItem` del XSD). Si no, cantidades enteras.
- [ ] Eliminar línea. Las líneas eliminadas quedan registradas (V4).
- [ ] Importe de la línea = precio final × cantidad − descuento.

**V2 · Totales** — `Piloto`
- [ ] Subtotal, Descuentos, "ITBIS (incluido)" informativo y **Total**.
- [ ] Total = suma de las líneas **al centavo**. Es el mismo total que dice el e-CF (F4).

**V3 · Existencia** — `Piloto`
- [ ] Agregar un producto con existencia ≤ 0 está permitido. La línea muestra "Sin existencia".
- [ ] Al emitir, la existencia baja aunque quede negativa (ya pasa así).

**V4 · Cancelar venta** — `Piloto`
- [ ] ESC o botón, con confirmación si hay artículos. Sin PIN.
- [ ] Ventas canceladas y líneas eliminadas quedan en la auditoría y salen en el reporte de cierre (cantidad y monto).

**V5 · Cliente** — `Piloto`
- [ ] Buscar cliente por nombre o RNC. Opcional: sin cliente, es consumidor final.
- [ ] Con cliente, su `clients.descuento` se aplica solo a las líneas sin descuento propio (el backend ya lo hace). Ese descuento no pide PIN porque lo configuró el admin.

**V6 · Atajos de teclado** — `Piloto`
- [ ] F9 Efectivo · F2 Tarjeta · F3 Transferencia · ESC Cancelar · F1 ir al buscador.

**V7 · Cambiar el precio de una línea** — `v1`
- [ ] El cajero escribe el nuevo precio final y un **supervisor lo autoriza con PIN** (S1).
- [ ] Se audita: producto, precio original, precio nuevo, cajero y supervisor.

**V8 · Descuentos** — `v1`
- [ ] Por línea o al total, en % o en monto. Siempre con **PIN de supervisor**.
- [ ] Se envía como `descuento_monto` por línea (el descuento al total se reparte entre las líneas). Es lo que ya admite `EcfItemMapper`.

**V9 · Artículo "Varios"** — `v1`
- [ ] Descripción, precio final y ITBIS 18 % o exento. Con **PIN de supervisor**.
- [ ] Va sin `product_id`, así que no mueve inventario.

### 6.4 Comprobante fiscal

**F1 · E32 por defecto** — `Piloto`
- [ ] Sin cliente → E32 a consumidor final (sin `client_id`, como ya permite la emisión).

**F2 · E31 con RNC** — `Piloto`
- [ ] Casilla "Crédito fiscal" → RNC o cédula.
- [ ] Si el cliente existe, se usa. Si no, se consulta la DGII (`/api/rnc/consulta`) y **se crea el cliente** con la razón social que devuelve, con correo y teléfono vacíos (el correo es opcional; verificar que el modelo acepte el teléfono vacío).
- [ ] RNC no encontrado en la DGII → no se puede emitir E31; la venta puede salir como E32.

**F3 · E32 de RD$250,000 o más** — `Piloto`
- [ ] Exige identificar al comprador (RNC o cédula) antes de cobrar. **Regla a confirmar en la fase 0** contra el formato e-CF.

**F4 · Precios con ITBIS incluido en el XML** — `Piloto` · *go/no-go en la fase 0*
- [ ] Las ventas del POS (E32, E31 y sus E34) se emiten con **`IndicadorMontoGravado = 1`**: el precio unitario del XML es el precio final.
- [ ] Total del e-CF = total del carrito, al centavo. Prueba obligatoria: **7 × RD$25 = RD$175.00**. Con el cálculo actual (`= 0`, neto + 18 % encima) ese total es imposible: da 174.99 o 175.01.
- [x] El indicador **no** necesita columna: se lee del XML firmado. `factura_items.subtotal` sigue guardando la base **sin** ITBIS (lo que suman el reporte de ventas y el 607) e `itbis_amount` el ITBIS, repartidos para sumar exacto el encabezado. La RI y el detalle en app.\* vuelven a juntarlos (ver [§9.5](#95-emisión-post-apiposventas)). *Hecho en la fase 0: campo `precios_incluyen_itbis` de `POST /api/facturas`.*
- [ ] **Si la DGII lo rechaza en la fase 0**, el plan B es `= 0` con el neto a 4 decimales. El total puede variar ±1 centavo respecto a la góndola, y se cobra lo que diga el e-CF. Esto se decide antes del lunes 2026-10-12.

**F5 · Idempotencia** — `Piloto`
- [ ] Cada intento de venta lleva una clave única generada por el POS.
- [ ] Repetir con la misma clave devuelve **la misma factura** y sus datos de recibo, **nunca otro e-NCF**.
- [ ] Un doble toque en "Cobrar" emite una sola vez.

**F6 · DGII lenta** — `Piloto` · *requiere el visto bueno del contador (Q1)*
- [ ] Espera máxima a la DGII en ventas POS: **5 s** (configurable, `POS_DGII_TIMEOUT`). El resto del sistema sigue en 30 s.
- [ ] Respuesta **aceptada** → recibo.
- [ ] Respuesta **rechazada** → no hay recibo. Se muestra el motivo y el carrito queda intacto. (El e-NCF se puede reutilizar, ya lo maneja la emisión.)
- [ ] **Sin respuesta en 5 s o fallo de transporte** → la venta queda firmada y guardada como *envío pendiente*, se imprime el recibo y el envío se reintenta solo (F7).

**F7 · Reintento de envíos pendientes** — `Piloto`
- [ ] Un proceso reenvía las ventas con *envío pendiente* hasta tener respuesta: cron de cPanel, o el propio POS cuando está libre si no hay cron (Q4).
- [ ] Si después llega un **rechazo**, se muestra una alerta persistente en el POS (al supervisor) y en app.\*, con la venta y el motivo.

**F8 · Corregir el aviso de "carga manual"** — `Piloto`
- [ ] Quitar el aviso *"La factura íntegra debe cargarse manualmente al portal DGII"* de `ECFEmissionService` y corregir el comentario de `RFCEXmlBuilder`. En producción basta el RFCE.

**F9 · Sin internet** — `Piloto`
- [ ] Detecta la pérdida de conexión (`navigator.onLine` más un ping al API).
- [ ] Banner fijo: "Sin conexión. No se puede emitir. Usa el procedimiento de contingencia." Los botones de cobro quedan deshabilitados y el carrito se conserva.
- [ ] Al volver la conexión, el banner desaparece solo.

### 6.5 Cobro e impresión

**P1 · Forma de pago** — `Piloto`
- [ ] Una por venta: **Efectivo** (código DGII 1), **Tarjeta** (3), **Transferencia / depósito** (2).
- [ ] Se envía en `TablaFormasPago`, así el 607 la reparte bien sin tocarlo.

**P2 · Efectivo y devuelta** — `Piloto`
- [ ] Monto recibido: botones Exacto, 100, 200, 500, 1000, 2000 y teclado numérico.
- [ ] No se puede cobrar si lo recibido es menor que el total.
- [ ] La devuelta se ve en grande. Se guardan el recibido y la devuelta.

**P3 · Tarjeta y transferencia** — `Piloto`
- [ ] Monto = total. Sin número de referencia.

**P4 · Impresión automática** — `Piloto`
- [ ] Al emitir, el recibo se imprime **siempre**, en tirilla, con el modo página web que ya existe (`imprimirRecibo` → `reciboHtml` → `printHtml`).
- [ ] Con Chrome abierto con `--kiosk-printing`, sale sin diálogo.

**P5 · Reimpresión** — `Piloto`
- [ ] Desde "Ventas del turno" (K9).

**P6 · Impresora del equipo** — `Piloto`
- [ ] El POS tiene su propia configuración de impresora (ancho 72/76/80 y hoja de prueba), porque la de app.\* vive en el `localStorage` de otro dominio.
- [ ] Se pide la primera vez que se habilita el equipo.

**P7 · Guía para montar la caja** — `Piloto`
- [ ] Documento: acceso directo `chrome.exe --kiosk --kiosk-printing https://pos.fiscalpoint.com.do`, térmica como impresora predeterminada, ajustes del driver (sin papel en blanco al final) y prueba del QR con el celular.

### 6.6 Caja y turnos

**K1 · Cajas** — `Piloto`
- [ ] El admin las crea en app.\* (nombre, activa). Una caja = un puesto físico con su gaveta.

**K2 · Apertura** — `Piloto`
- [ ] Si el empleado entra en una caja sin turno abierto → pide el fondo inicial (monto) → abre el turno (caja + empleado).

**K3 · Reglas del turno** — `Piloto`
- [ ] Un turno abierto **por caja** y un turno abierto **por empleado**.
- [ ] No se vende sin un turno propio abierto en esa caja.

**K4 · Turno abierto de otro empleado** — `Piloto`
- [ ] El empleado que entra **no puede vender**. Ve "Turno abierto de *María* desde las 8:05 a. m. Un supervisor debe cerrarlo".
- [ ] Un supervisor, con su PIN, cierra ese turno con conteo a ciegas (K6). Después el nuevo empleado abre el suyo.

**K5 · Turno propio de un día anterior** — `Piloto`
- [ ] Se puede seguir vendiendo, con un banner fijo: "Turno abierto desde ayer, 8:05 p. m.".

**K6 · Cierre** — `Piloto`
- [ ] **Conteo a ciegas:** se cuenta sin ver cuánto debería haber.
- [ ] **Por denominaciones:** billetes de 2000, 1000, 500, 200, 100, 50 y 20; monedas de 25, 10, 5 y 1; y un campo "otros / centavos" en monto. El sistema suma.
- [ ] Al confirmar el conteo se muestran el esperado y la diferencia. **El conteo no se puede editar después.**
- [ ] La diferencia **solo se registra** (no pide supervisor). Se puede escribir una nota.

**K7 · Efectivo esperado** — `Piloto`
- [ ] `esperado = fondo + ventas en efectivo − devoluciones en efectivo`.
- [ ] Una venta con *envío pendiente* cuenta igual: el dinero ya entró.
- [ ] Tarjeta y transferencia se muestran como totales informativos; no se cuentan.

**K8 · Reporte de cierre** — `Piloto`
- [ ] En tirilla al cerrar, y reimprimible desde app.\*.
- [ ] Contenido:
  - caja, empleado, apertura, cierre y quién cerró;
  - fondo;
  - ventas por forma de pago (cantidad y monto);
  - cantidad de E32, E31 y E34;
  - devoluciones por forma de pago;
  - descuentos, cambios de precio y "Varios" (cantidad, monto y quién autorizó);
  - ventas canceladas y líneas eliminadas;
  - ventas con envío pendiente o rechazadas;
  - conteo por denominación, esperado y diferencia.

**K9 · Ventas del turno** — `Piloto`
- [ ] Lista de las ventas del turno actual con hora, e-NCF, total, forma de pago y estado DGII. Permite reimprimir.

### 6.7 Autorizaciones

**S1 · PIN de supervisor** — `Piloto`
- [ ] Ventana con teclado numérico, en la sesión del cajero.
- [ ] Solo valen los empleados activos con rol `supervisor`. Un PIN de cajero responde "Ese PIN no puede autorizar".
- [ ] Los fallos cuentan para el bloqueo del equipo (A6).

**S2 · Qué pide supervisor** — K4 desde el `Piloto`; el resto desde `v1`
- [ ] Cerrar el turno de otro empleado (K4).
- [ ] Cambio de precio (V7), descuento (V8), "Varios" (V9) y devolución (D3).

**S3 · Auditoría** — `Piloto`
- [ ] Toda autorización, entrada con PIN (correcta o fallida), bloqueo, apertura y cierre de turno, cancelación, habilitación o revocación de equipo, y creación o regeneración de PIN va a `audit_logs`.
- [ ] Cada registro guarda la acción, el empleado, el supervisor, la caja, el turno y los valores de antes y después.

### 6.8 Devoluciones

**D1 · Buscar la venta** — `v1`
- [ ] Por e-NCF (tecleado) o en la lista de ventas recientes. Solo ventas hechas en el POS (ver §8).

**D2 · Plazo** — `v1`
- [ ] **Hasta 30 días** desde la emisión (`indicador_nota_credito = 0`).
- [ ] Después de eso: "Fuera de plazo: hazla desde app.\* con un administrador".

**D3 · Emitir la devolución** — `v1`
- [ ] Elegir las líneas y cantidades, hasta lo vendido menos lo ya devuelto. Con **PIN de supervisor**.
- [ ] Emite una E34 con `IndicadorMontoGravado = 1`: código de modificación **1** si devuelve todo, **3** si es parcial (*confirmar en la fase 0*).
- [ ] Nunca supera el saldo de la venta (validación que ya existe → 422 con mensaje).
- [ ] La E34 se imprime en tirilla.

**D4 · Existencia** — `v1`
- [ ] Vuelve sola: `inventoryModel::registrarVenta` ya trata la E34 como entrada.

**D5 · Dinero por la misma vía** — `v1`
- [ ] Venta en efectivo → el dinero sale del turno **actual** y cuenta en el esperado.
- [ ] Tarjeta o transferencia → solo se registra, con el aviso "Reversar en el datafono / banco". No toca el efectivo.

**D6 · Centavo residual al devolver por partes** — `v1` (comportamiento conocido, no se corrige)
- [x] Con precios con ITBIS, cada E34 calcula su propia base: `round(total / 1.18, 2)`. Devolver una venta por partes puede dejar **±0.01 entre base e ITBIS**, aunque el total quede en cero.
- Caso real del 2026-10-08: E320000000001 (175.00 → 148.31 + 26.69), devuelta con E340000000007 (50.00 → 42.37 + 7.63) y E340000000008 (125.00 → 105.93 + 19.07). Neto en el 607: base +0.01, ITBIS −0.01, total 0.00.
- No se fuerza que la última nota "cierre" la base de la original, porque la DGII valida cada documento con su propia regla (`round(suma / 1.18, 2)`) y una base distinta arriesga un rechazo.

### 6.9 Administración en app.\*

**M1 · Sección POS (módulo `pos`)** — `Piloto`
- [ ] **Cajas**: crear, renombrar, desactivar.
- [ ] **Equipos**: lista de equipos habilitados (caja, fecha, quién lo habilitó, último uso) con opción de revocar.
- [ ] **Empleados**: crear (el PIN se muestra una vez), regenerar PIN, cambiar rol, desactivar.
- [ ] **Turnos**: lista con filtros (caja, empleado, fecha) y el detalle del cierre (K8), reimprimible.

**M2 · Ficha del producto** — `Piloto`
- [ ] Códigos de barras (C6) y casilla de ITBIS incluido (C7).

**M3 · Las ventas POS en el resto del sistema** — `Piloto`
- [ ] Son facturas normales: salen en Facturación, Dashboard, reporte de ventas y 607 sin cambios.

### 6.10 Nice-to-have (P1)

| ID | Qué |
|---|---|
| N1 | Reporte de ventas agrupado por **cajero** y por **caja**. Hoy "por usuario" usa `facturas.user_id`, que no aplica a empleados POS |
| N2 | Corte X: ver el resumen del turno sin cerrarlo |
| N3 | Modo práctica para entrenar sin emitir e-CF reales |
| N4 | Panel de métricas del POS (tiempo por venta, pendientes, diferencias) |
| N5 | Ícono instalable (PWA) en el escritorio de la caja |

### 6.11 Más adelante (P2): que el diseño no les cierre la puerta

| Qué | Qué decisión de v1 lo hace posible |
|---|---|
| Pago mixto | `pos_caja_movimientos` admite varias filas por venta |
| Fiado y abonos | `tipo` de `pos_caja_movimientos` es extensible (`ABONO`); `clients.permitir_credito` ya existe |
| Retiros y entradas | Mismo `tipo` extensible (`RETIRO`, `ENTRADA`); el esperado ya se calcula por movimientos |
| Órdenes en espera | El carrito vive en el cliente; guardar varios es un cambio local |
| Listas de precio | El catálogo del POS ya trae `precio_2..4`; falta `clients.lista_precio` |
| Asignar códigos desde el POS | `product_barcodes` ya es una tabla aparte |
| Pasar un E32 a E31 (código de modificación 5) | La E34 del POS ya referencia ventas por e-NCF |
| 58 mm, tablet, imágenes, sucursales | Ver §3 |

---

## 7. Registro de decisiones

| # | Tema | Decisión |
|---|---|---|
| 1 | Vertical | Colmado / tienda |
| 2 | Comprobante | E32 por defecto; E31 si dan RNC |
| 3 | Caja | Apertura con fondo, cierre con conteo y cuadre |
| 4 | Acceso | El botón de app.\* pasa la sesión; pos.\* tiene login de admin propio |
| 5 | Impresión | Silenciosa con Chrome `--kiosk-printing`; se imprime **siempre** |
| 6 | Hardware | PC Windows táctil + lector de código de barras USB. Sin gaveta ni báscula |
| 7 | Existencia en 0 | Se vende y se avisa |
| 8 | Sin internet | Aviso + contingencia. Sin cola local |
| 9 | Precio en pantalla | Final, con ITBIS |
| 10 | Códigos de barras | Varios por producto, en tabla aparte. Un código desconocido **solo avisa** |
| 11 | En v1 | Descuentos, cliente (su descuento), devoluciones |
| 12 | Fase 2 | Fiado, órdenes en espera, pago mixto, retiros, listas de precio |
| 13 | Roles | Cajero y supervisor; el supervisor autoriza con PIN |
| 14 | Cobro | Una forma de pago por venta + devuelta. Sin referencia de tarjeta |
| 15 | Lanzamiento | `pos_enabled` por tenant; piloto con Gratex |
| 16 | E32 de menos de RD$250k | En producción basta el RFCE; el aviso de "carga manual" se corrige |
| 17 | DGII lenta | Esperar ~5 s; después imprimir y reintentar solo |
| 18 | Centavos | `IndicadorMontoGravado = 1` en las ventas POS |
| 19 | RNC nuevo | Se crea el cliente con los datos de la DGII |
| 20 | Plazo de devolución | 30 días |
| 21 | Reembolso | Por la misma vía; solo el efectivo sale de la caja |
| 22 | Listas de precio | No en v1 (siempre precio 1) |
| 23 | Cambio de precio | Permitido, con PIN de supervisor |
| 24 | "Varios" | Sí, con PIN de supervisor |
| 25 | Descuentos | Con PIN de supervisor |
| 26 | Turno | Pertenece a una caja y a un cajero |
| 27 | Cierre | A ciegas y por denominaciones; la diferencia solo se registra |
| 28 | Turno propio de ayer | Se permite, con aviso |
| 29 | Turno de otro cajero | Un supervisor lo cierra primero |
| 30 | Entrada | Equipo habilitado + PIN |
| 31 | Cajeros | Empleados POS, sin cuenta de app.\* |
| 32 | Identificación | Solo PIN, único en el tenant |
| 33 | PIN | Lo genera el sistema, de **4 dígitos** (cambiado de 6 el 2026-10-08, por rapidez en mostrador), con bloqueo progresivo por equipo |
| 34 | Retiros | No en v1 |
| 35 | Imágenes | No en v1 (iniciales + color) |
| 36 | Precio en la ficha | Hoy se escribe sin ITBIS. Se agrega una casilla y se guarda el neto a 4 decimales |
| 37 | Piloto | Gratex, térmica de 80 mm, menos de 500 productos, 4 semanas |

## 8. Decisiones por defecto — revisar

Se tomaron sin preguntar. Cambiarlas ahora es barato; después del piloto no tanto.

1. **Eliminar líneas y cancelar ventas no pide PIN.** Quedan registradas y salen en el cierre. (Es la vía clásica de fraude: escanear, quitar, guardarse el efectivo. Si preocupa, pasa a PIN.)
2. **Formas de pago:** solo Efectivo, Tarjeta y Transferencia. Sin "Otro" (código 8).
3. **Fondo inicial:** un monto, sin contarlo por denominación.
4. ~~PIN de 6 dígitos~~ → **decidido: 4 dígitos** (decisión 33). 5 fallos → bloqueo progresivo: 5, 10, 20… min, tope 1 día.
5. **Bloqueo automático** tras 10 min sin actividad.
6. **Un empleado, un turno abierto** a la vez, aunque haya varias cajas.
7. **Un equipo habilitado por caja.**
8. **Devoluciones solo de ventas hechas en el POS** (las de app.\* se devuelven desde app.\*). Evita mezclar notas con `IndicadorMontoGravado` distinto al de la venta.
9. **RNC no encontrado en la DGII → no hay E31.** Si el servicio de consulta está caído, tampoco: queda como pregunta abierta (Q6).
10. **El descuento del cliente se aplica sin PIN**, porque lo configuró el admin.
11. **El supervisor que cierra un turno ajeno también cuenta a ciegas.**
12. **El catálogo se refresca** cada 5 min y después de cada venta.

---

## 9. Diseño técnico (propuesta para ingeniería)

### 9.1 Frontend (`fiscalo-react`)

- **Segundo punto de entrada:** `pos.html` → `src/pos/main.tsx` (Vite multipágina). Reutiliza `src/api`, `components/ui`, `styles`, `montosLinea`, `reciboHtml`, `printHtml` e `imprimirRecibo`, pero **no** importa `App.tsx` ni las vistas de app.\*: bundle chico y arranque rápido.
- **Un solo proyecto de Vercel para los dos dominios.** En `vercel.json`, **antes** de la regla que atrapa todo:
  ```json
  { "source": "/(.*)", "has": [{ "type": "host", "value": "pos.fiscalpoint.com.do" }], "destination": "/pos.html" }
  ```
  La regla `/api/:path*` → `gratex.net` sirve igual para los dos. DNS: `CNAME pos` → Vercel.
- **Almacenamiento en pos.\*:** `fiscalpoint.pos.equipo` (token de equipo + caja) y `fiscalo.impresora` (propio de este dominio). La sesión del empleado vive **solo en memoria**: recargar la página vuelve a pedir PIN.
- **Botón POS en app.\*:** `src/components/layout/Navbar.tsx`, junto al dropdown "Nueva".

### 9.2 Autenticación: tres niveles

```
app.* (admin con módulo pos)
   │  POST /api/auth/pos-handoff            -> código de 60 s, un solo uso
   ▼
pos.*/#code=…  ──canje──►  sesión de admin (solo en memoria, solo para habilitar)
   │  POST /api/pos-admin/equipos           -> token de EQUIPO (tenant + caja), en localStorage
   ▼
Pantalla de PIN
   │  POST /api/pos/sesion {pin}            -> token de SESIÓN de empleado (en memoria)
   ▼
Ventas: Authorization: Bearer <token equipo>  +  X-POS-Sesion: <token sesión>
```

- `auth` es `public` en `config/permissions.php`: `pos-handoff` valida el token del usuario **en el controller** y exige el módulo `pos`.
- **Principal nuevo `pos-caja`** en `PermissionGate`, análogo a `integration`: el gate resuelve el tenant desde el token de equipo (tabla del master) y el controller valida la sesión del empleado.
- **Mapa de rutas** (toda ruta nueva **tiene** que estar mapeada, o el tenant no se resuelve y el 500 sale vacío):
  - `'pos' => 'pos-caja'`
  - `'pos-admin' => 'pos'`
  - `'pos'` se agrega al `catalog` como módulo de administración.
- **PIN:** se guarda `pin_hmac = HMAC-SHA256(pin, POS_PIN_PEPPER ‖ tenant_id)` con índice `UNIQUE`. Así se busca al empleado por PIN en una sola consulta y la unicidad la garantiza la BD. Un volcado de la BD sin el pepper no permite probar los 10⁴ PINs. `POS_PIN_PEPPER` es un secreto **nuevo** en `.env`: no reutilizar ninguno de los que estuvieron expuestos.
- **Bloqueo:** el contador de fallos va por equipo, en el servidor (no en el navegador): `pos_equipos.intentos_fallidos`, `bloqueado_hasta` y `bloqueos_seguidos` (progresivo).

### 9.3 Modelo de datos

**Master — `db/master_migrations/012_pos.sql`**

| Tabla / columna | Contenido |
|---|---|
| `tenants.pos_enabled` | `TINYINT(1) NOT NULL DEFAULT 0` |
| `pos_handoff_codes` | `code_hash`, `user_id`, `tenant_id`, `expira_at`, `usado_at` |
| `pos_equipos` | `tenant_id`, `caja_id`, `token_hash` UNIQUE, `habilitado_por` (user_id), `created_at`, `last_used`, `revocado_at`, `intentos_fallidos`, `bloqueado_hasta` |

**Tenant — `db/migrations/030_pos.sql`** (y reflejado en `db/tenant_schema.sql`)

| Tabla / columna | Contenido |
|---|---|
| `pos_cajas` | `nombre` UNIQUE, `activa` |
| `pos_empleados` | `nombre`, `rol` (`cajero`/`supervisor`), `pin_hmac` CHAR(64) UNIQUE, `activo`, `created_at` |
| `pos_sesiones` | `empleado_id`, `equipo_id`, `token_hash`, `created_at`, `cerrada_at` |
| `pos_turnos` | `caja_id`, `empleado_id`, `abierto_at`, `fondo_inicial`, `estado`, `cerrado_at`, `cerrado_por`, `conteo_json` (denominaciones), `efectivo_contado`, `efectivo_esperado`, `diferencia`, `totales_json` (foto del cierre), `nota`. Un solo turno abierto por caja y por empleado (se garantiza con una columna `abierto` NULL/1 en `UNIQUE(caja_id, abierto)` y `UNIQUE(empleado_id, abierto)`) |
| `pos_caja_movimientos` | `turno_id`, `factura_id`, `tipo` (`VENTA`/`DEVOLUCION`; extensible), `forma_pago` (código DGII), `monto`, `monto_recibido`, `devuelta`, `iniciada_at` (primer artículo, para la métrica), `created_at` |
| `product_barcodes` | `product_id` (FK `ON DELETE CASCADE`), `codigo` VARCHAR(64) UNIQUE |
| `facturas` + | `turno_id`, `pos_empleado_id`, `pos_idempotency_key` CHAR(36) UNIQUE, `envio_pendiente` TINYINT DEFAULT 0 (el indicador de precios con ITBIS se lee del XML: no lleva columna) |

**Tenant — `db/migrations/029_precios_4_decimales.sql`** (aparte: afecta a toda la app) — *escrita en la fase 0*
- `products.precio`, `precio_2`, `precio_3` y `precio_4` → `DECIMAL(18,4)`. Antes de correrla, revisar todo lo que lee esas columnas (Q5).

### 9.4 Rutas

| Método y ruta | Principal | Qué hace |
|---|---|---|
| `POST /api/auth/pos-handoff` | usuario (módulo `pos`) | Genera el código de traspaso |
| `POST /api/auth/pos-handoff/canje` | público | Código → sesión de admin |
| `POST /api/pos-admin/equipos` | usuario (`pos`) | Habilita el equipo → token de equipo |
| `GET/POST/PUT /api/pos-admin/cajas` | usuario (`pos`) | Gestión de cajas |
| `GET/POST/PUT /api/pos-admin/empleados` | usuario (`pos`) | Empleados; `POST …/{id}/pin` regenera el PIN |
| `GET /api/pos-admin/turnos` · `/{id}` | usuario (`pos`) | Cierres y su detalle |
| `DELETE /api/pos-admin/equipos/{id}` | usuario (`pos`) | Revoca un equipo |
| `POST /api/pos/sesion` · `DELETE` | `pos-caja` | Entrar con PIN · bloquear o salir |
| `GET /api/pos/catalogo` | `pos-caja` | Productos, precio final, códigos, categorías |
| `GET/POST /api/pos/turno` · `POST /api/pos/turno/cerrar` | `pos-caja` | Turno actual, apertura y cierre |
| `POST /api/pos/autorizar` | `pos-caja` | Valida un PIN de supervisor para una acción y devuelve un permiso de un solo uso |
| `POST /api/pos/clientes/rnc` | `pos-caja` | Busca el cliente o lo crea desde la DGII (F2) |
| `POST /api/pos/ventas` | `pos-caja` | Emite (§9.5) |
| `GET /api/pos/ventas?turno=actual` | `pos-caja` | Ventas del turno |
| `POST /api/pos/devoluciones` | `pos-caja` | E34 (D3) |
| `POST /api/pos/pendientes/reenviar` | `pos-caja` | Reintenta los envíos pendientes (F7) |

El recibo sale de los endpoints que ya existen: `GET /api/facturas/{id}/pdf?formato=pos&format=datos`. Desde el principal `pos-caja` hay que permitirlo solo para facturas del tenant del equipo.

### 9.5 Emisión: `POST /api/pos/ventas`

1. Valida equipo, sesión, turno propio abierto y conexión.
2. **Idempotencia:** si `pos_idempotency_key` ya existe, devuelve esa factura tal cual.
3. Arma las líneas **en el servidor** desde `product_id`, porque el navegador no manda precios. La excepción son V7 y V9, que llegan con su permiso de supervisor de un solo uso.
4. Precio unitario = precio final. Con `IndicadorMontoGravado = 1`:
   - `MontoItem = round(cant × precio_final, 2) − descuento`;
   - por tasa: `gravado = round(Σ MontoItem / (1 + tasa), 2)` e `ITBIS = Σ MontoItem − gravado`;
   - el total es la suma exacta de las líneas.

   Hoy `EcfItemMapper::totales` solo sabe calcular sobre el neto, y el builder solo respeta `indicador_monto_gravado` con `strict_input`. Hay que agregar el modo "precios con ITBIS" sin tocar la ruta de app.\*. El RFCE (`RFCEXmlBuilder`) recibe los mismos totales.
5. `TablaFormasPago` con la forma elegida y el monto total.
6. Llama a `ECFEmissionService::emitir` con un timeout de DGII de 5 s.
7. Guarda la factura (`turno_id`, `pos_empleado_id`, `indicador_monto_gravado = 1`, `envio_pendiente` si hubo timeout), el movimiento de caja y la salida de inventario (ya existe), y escribe la auditoría.
8. Responde con la factura y los datos del recibo (`ReciboDatos`) **en la misma respuesta**, para imprimir sin otra petición.

**Lo que lee precios de `factura_items`** (revisado en la fase 0, V-3): `ReporteVentasModel` y `Reporte607Model` suman `subtotal` como base e `itbis_amount` aparte, así que funcionan sin cambios porque `subtotal` sigue siendo neto. `EcfDocumento` (RI carta, tirilla y recibo web) y el detalle del front (`InvoiceDetailView`) muestran como valor de la línea `subtotal + itbis_amount` cuando el XML dice `IndicadorMontoGravado = 1`. `facturaSimpleController` y `FerreteriaFormato` no tocan e-CF. Las notas de crédito de app.\* no copian las líneas de la original.

### 9.6 Reintentos

- Si cPanel tiene cron: `tools/pos_reenviar_pendientes.php` cada 5 min, para todos los tenants con `pos_enabled`.
- Si no: el POS llama a `/api/pos/pendientes/reenviar` cada 2 min mientras está abierto y sin venta en curso.
- Usa `consultarEstadoRFCE` / `consultarEstado`, que ya existen, antes de reenviar, para no duplicar un envío que sí llegó.

### 9.7 Auditoría

`AuditLogger` → `audit_logs` (master), módulo `pos`. Los empleados no son usuarios del master: `user_id` NULL y en el detalle van `pos_empleado_id`, nombre, `caja_id`, `turno_id` y `autorizado_por`.

---

## 10. Métricas de éxito

| Métrica | Cómo se mide | Meta del piloto | Ideal |
|---|---|---|---|
| **Tiempo por venta** (primer artículo → respuesta de emisión) | `pos_caja_movimientos.iniciada_at` → `created_at` | Mediana ≤ 15 s (≤ 3 artículos) | ≤ 10 s |
| Tiempo de emisión en el servidor | Log por venta | p95 ≤ 5 s | p95 ≤ 2 s |
| Ventas con envío pendiente | `facturas.envio_pendiente` | < 2 % | < 0.5 % |
| Rechazos de la DGII | `estado_dgii` de las ventas POS | **0** | 0 |
| Diferencia total ≠ al centavo (góndola vs e-CF) | Prueba 7 × RD$25 y muestreo de ventas | **0 casos** | 0 |
| Turnos que cierran con diferencia ≠ 0 | `pos_turnos.diferencia` | Línea base | Tendencia a la baja |
| Ventas con cambio de precio o "Varios" | Auditoría | < 5 % (más indica un catálogo incompleto) | < 2 % |
| Tenants usando el POS | `pos_enabled` + ventas en 30 días | — | 3 a los 60 días de v1 |

**Revisiones:** al final de cada semana del piloto, y una revisión final el
2026-11-19, que decide el lanzamiento.

## 11. Fase 0: validaciones con criterio de salida

Antes de construir la pantalla de venta. Fecha límite: **lunes 2026-10-12** para F4.

| # | Validación | Cómo | Pasa si | Si falla |
|---|---|---|---|---|
| V-1 | `IndicadorMontoGravado = 1` | En producción (Gratex, certecf ya no recibe envíos): un E32 por RFCE de 7 × RD$25, un E31 de 3 × RD$10 y una E34 parcial sobre el E32 | Los tres ACEPTADOS y el total es exacto | Plan B de F4 (`= 0`, neto a 4 decimales, ±1 centavo) |
| V-2 | RI y 607 con `= 1` | Imprimir carta y tirilla de V-1 y generar el 607 del día | Líneas, ITBIS y totales correctos | Ajustar `EcfDocumento` / `Reporte607Model` |
| V-3 | Lectores de `factura_items` | Revisar los 6 archivos de §9.5 | Ninguno asume neto sin mirar el indicador | Ajustarlos antes del piloto |
| V-4 | Latencia | Medir 20 emisiones E32 reales | p95 ≤ 5 s | Revisar el token DGII (caché) y el orden firma → envío |
| V-5 | Impresión | Chrome `--kiosk-printing` + la térmica de 80 mm del piloto | Imprime sin diálogo, corta al final y el QR escanea | Ajustar el driver; si no se puede, usar el diálogo en el piloto |
| V-6 | Comprador en E32 ≥ RD$250k | Formato e-CF / XSD | Regla confirmada | Ajustar F3 |
| V-7 | E34 sobre un E32 de consumidor final | Emitirla con el flujo actual | ACEPTADA, con el código de modificación correcto | Ajustar D3 |
| V-8 | Lectores de `products.precio` | Revisar el front y el back antes de la migración 029 | Inventario completo | No correr la 029 hasta tenerlo |

### Resultado al 2026-10-08

| # | Estado | Qué salió |
|---|---|---|
| V-1 | **PASA** | Probado en producción el 2026-10-08, todo con `IndicadorMontoGravado = 1` y sin un solo reparo de montos. **E320000000001** (E32 por RFCE, 7 × 25.00 = **175.00** exacto): *Aceptado*, sin mensajes. **E340000000007** (devolución 2 × 25.00 = 50.00): *Aceptado*. **E310000000058** (E31 gravado + exento, 60.00) y **E340000000006** (su anulación): *Aceptado Condicional* solo por el RNC del comprador de prueba (131880681, código 1385). El rango E32 (autorización 6005547018, 1–100) se registró ese día con vencimiento 2099-12-31 porque la DGII lo da sin vencimiento (N/A). **Plan B descartado**: el POS emite con precios con ITBIS incluido |
| V-2 | **PASA** | Recibos de E32 y E34 en tirilla: "Consumidor Final", 7 × 25.00 = 175.00 con ITBIS 26.69 y pie 148.31 / 26.69 / 175.00; la devolución 2 × 25.00 = 50.00 con 42.37 / 7.63. Timbre con código y QR. El 607 de octubre trae E310000000058 y E340000000006 con 55.42 / 4.58 / 60.00, compensándose |
| V-3 | **Hecho** | Ver §9.5. Se corrigió `EcfDocumento` y el detalle del front (`InvoiceDetailView`); los reportes no cambian |
| V-4 | Primeros datos | Emisiones reales del 2026-10-08, medidas desde el cliente (incluye la red): E32 por RFCE 1.45 s, E34 1.68 s y 2.31 s, E31 1.49 s. Todas por debajo de la meta (p95 ≤ 5 s). Falta una muestra mayor durante el piloto |
| V-5 | Pendiente | Se prueba con la impresora física |
| V-6 | **Hecho** | Sí, es obligatorio (norma de la RI). Destapó un error que ya existía: un E32 ≥ RD$250k sin cliente salía sin `<Comprador>` y la DGII lo rechazaba. Ahora responde 422 antes de reservar el e-NCF |
| V-7 | **PASA** | E340000000007 sobre E320000000001, **sin cliente y sin `<Comprador>`**: *Aceptado*. Antes era imposible (la E34 exigía `client_id` y el builder escribía `<RazonSocialComprador>` vacío). Código de modificación usado en la parcial: 3 (Q3 sigue abierta para la total) |
| Incidente | **Resuelto** | La prueba del E31 no mandaba `user_id`; en producción `facturas.user_id` es `NOT NULL`, así que el INSERT falló **después** de que la DGII recibió E310000000058. Se consultó con `consultar_ecf_dgii`, se anuló con E340000000006 y se registró con `tools/rescate_E310000000058.sql` (factura 1385). Arreglo desplegado: la emisión toma el usuario del token si el body no trae `user_id`, y responde 422 antes de reservar el e-NCF |
| V-8 | **Hecho** | Las tres tablas de líneas ya guardan 4 decimales. Migración `029_precios_4_decimales.sql` escrita (idempotente, para phpMyAdmin) |
| F8 | **Hecho** | Aviso de "carga manual" corregido en el código y en `docs/api/facturas.md` y `docs/integrations/dgii-ecf.md` |

## 12. Plan: 4 semanas hasta el piloto

**Avance al 2026-10-08:** fase 0 cerrada (§11). Del backend de la semana 1 está
hecho y probado: migraciones `master 012`, `029` y `030` (en MySQL 8, dos corridas
cada una e idénticas al snapshot), traspaso de sesión (A2), administración de cajas,
empleados y equipos (K1, A5, A4), PIN con bloqueo y sesiones (A6, A7), `pos_enabled`
(A8) y el principal `pos-caja` en el gate. Contrato en [../api/pos.md](../api/pos.md);
`tools/test_pos_backend.php` da 89/89 con el gate en enforce y en sombra (PIN de 4 dígitos con bloqueo progresivo, cambiado el mismo día).

| Semana | Fechas | Qué |
|---|---|---|
| **1** | jue 8 – mié 14 oct | **Fase 0** (§11) · F8 (aviso) · migraciones: master 012, tenant 029 (precios, lista) y 030 (POS) · empleados, PIN y equipos en el backend · contador (Q1) · cron (Q4) |
| **2** | 15 – 21 oct | Subdominio y Vercel · `pos.html` · paso de sesión y habilitar equipo · pantalla de PIN y bloqueo · catálogo, grilla, búsqueda y escáner · carrito |
| **3** | 22 – 28 oct | `POST /api/pos/ventas` (precio con ITBIS, idempotencia, timeout de 5 s, pendientes) · cobro y devuelta · impresión · E31 con RNC · turnos (apertura, cierre a ciegas, denominaciones, reporte) · app.\*: cajas, empleados, equipos, ficha del producto · C8 sobre el catálogo de Gratex |
| **4** | 29 oct – 4 nov | Turno ajeno con supervisor · auditoría · banner sin conexión · reintentos · guía de la caja · pruebas de punta a punta · montar la caja del piloto |
| **Piloto** | **jue 5 nov** | Gratex vendiendo |
| 5–6 | 5 – 18 nov | **v1 durante el piloto:** cambio de precio, descuentos, "Varios", devoluciones E34 · correcciones del piloto |
| **v1** | **jue 19 nov** | Revisión de métricas → activar como complemento |

**El riesgo de la fecha:** V-1. Si `IndicadorMontoGravado = 1` no pasa el lunes 12,
el plan B no mueve la fecha, pero acepta ±1 centavo frente a la góndola.

## 13. Preguntas abiertas

| # | Pregunta | Quién | ¿Bloquea? | Para cuándo |
|---|---|---|---|---|
| Q1 | ¿Se puede entregar el recibo firmado **antes** de que la DGII responda (F6) y enviarlo después? Si no, F6 pasa a "bloquear hasta que responda" | Contador | **Sí**, para la semana 3 | 2026-10-21 |
| ~~Q2~~ | ~~¿Es obligatorio identificar al comprador en un E32 ≥ RD$250,000?~~ **Sí** (norma de la RI). Resuelto en la fase 0: la emisión responde 422 sin RNC/cédula | Ingeniería | — | Resuelta |
| Q3 | Código de modificación de la E34 de devolución: ¿1 total y 3 parcial? | Ingeniería / contador | No (es de v1) | 2026-11-05 |
| Q4 | ¿Hay cron en el cPanel de `smhynzte`? | Ingeniería | No (hay plan B) | Semana 1 |
| ~~Q5~~ | ~~Todo lo que lee `products.precio`~~ Resuelto en la fase 0 (V-8): las tres tablas de líneas ya guardan 4 decimales y nada lo recorta a 2. Migración 029 lista | Ingeniería | — | Resuelta |
| Q6 | Si el servicio de consulta de RNC está caído, ¿se permite escribir la razón social a mano para el E31? | Negocio | No | Semana 3 |
| Q7 | En efectivo los centavos no circulan: ¿cómo se cobra RD$10.50? ¿Redondeo al peso en el conteo? | Negocio / contador | No (el campo "otros/centavos" lo absorbe) | Piloto |
| Q8 | ¿Cómo se entrena a un cajero sin emitir e-CF reales? (N3) | Negocio | No | Antes de v1 |
| Q9 | Precio del complemento POS | Negocio | No | Antes de v1 |
