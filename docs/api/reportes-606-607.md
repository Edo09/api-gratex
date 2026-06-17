# Reportes 606 y 607 (DGII) — Guía de integración Frontend

Generación de los formatos fiscales de la DGII: **606 (Compras)** y **607 (Ventas)**
del período. Mismo patrón en ambos: preview estructurado para revisión, revisión de
advertencias, y descarga del `.txt` para subir al portal DGII.

- **Base URL (producción):** `https://gratex.net/api` — **Auth:** `X-API-KEY: <token>` — **Método:** `GET`.
- Período siempre `AAAAMM` (6 dígitos). Ej: junio 2026 = `202606`.

---

## Reporte 606 — Compras de bienes y servicios

Endpoints para generar el **Formato 606** de la DGII (compras de bienes y servicios del
período). El flujo recomendado en el front es: **(1)** mostrar un preview en tabla con los
registros y totales, **(2)** revisar advertencias, **(3)** descargar el `.txt` para subirlo al
portal DGII.

- **Base URL (producción):** `https://gratex.net/api`
- **Autenticación:** header `X-API-KEY: <token>` en todas las llamadas.
- **Método:** `GET` (todos los endpoints son de solo lectura).
- El período siempre es `AAAAMM` (año + mes, 6 dígitos). Ej: junio 2026 = `202606`.

---

### Resumen de endpoints

| # | Endpoint | Devuelve | Uso en el front |
|---|----------|----------|-----------------|
| 1 | `GET /reportes/606/preview?periodo=AAAAMM` | JSON estructurado (registros + totales + advertencias) | Pintar tabla de revisión antes de descargar |
| 2 | `GET /reportes/606?periodo=AAAAMM&formato=json` | JSON con el TXT ya armado (string) | Previsualizar el archivo crudo (opcional) |
| 3 | `GET /reportes/606?periodo=AAAAMM` | Archivo `text/plain` (descarga) | Botón "Descargar 606" |

---

### 1) Preview estructurado (para la tabla)

```
GET /api/reportes/606/preview?periodo=202606
Headers: X-API-KEY: <token>
```

**Respuesta `200`:**

```json
{
  "status": true,
  "data": {
    "periodo": "202606",
    "rnc_emisor": "131256432",
    "cantidad": 5,
    "totales": {
      "monto_servicios": 0.10,
      "monto_bienes": 17720.96,
      "total_facturado": 17721.06,
      "itbis_facturado": 2887.81,
      "itbis_retenido": 0.0,
      "retencion_renta": 0.0
    },
    "advertencias": [],
    "registros": [
      {
        "razon_social": "PROVEEDOR EJEMPLO SRL",
        "origen": "ecf_recibido",
        "tipo_comprobante": "E31",
        "rnc": "101096225",
        "tipo_id": "1",
        "tipo_bienes_serv": "09",
        "ncf": "E310000042726",
        "ncf_modificado": "",
        "fecha_comprobante": "20260609",
        "fecha_pago": "",
        "monto_servicios": 0.10,
        "monto_bienes": 1788.75,
        "total_facturado": 1788.85,
        "itbis_facturado": 321.99,
        "itbis_retenido": 0.0,
        "itbis_proporcionalidad": 0.0,
        "itbis_costo": 0.0,
        "itbis_adelantar": 0.0,
        "itbis_percibido": 0.0,
        "tipo_retencion_isr": "",
        "retencion_renta": 0.0,
        "isr_percibido": 0.0,
        "isc": 0.0,
        "otros_impuestos": 0.0,
        "propina_legal": 0.0,
        "forma_pago": "04"
      }
    ]
  }
}
```

### Campos de cada `registro`

Los montos vienen como **números** (no strings) — listos para `toLocaleString`/formato en el
front. Los 3 primeros son auxiliares de display (NO forman parte de los 23 campos del 606):

| Clave | Tipo | Descripción |
|-------|------|-------------|
| `razon_social` | string | Nombre/razón social del suplidor (display) |
| `origen` | string | `ecf_recibido` (e-CF recibido de proveedor) o `gasto` (gasto manual) |
| `tipo_comprobante` | string | Tipo de comprobante (ej. `E31`, `E41`, `E43`, `B01`) |

Los 23 campos oficiales del 606, en orden:

| # | Clave | Descripción |
|---|-------|-------------|
| 1 | `rnc` | RNC/Cédula del suplidor |
| 2 | `tipo_id` | Tipo identificación: `1`=RNC, `2`=Cédula |
| 3 | `tipo_bienes_serv` | Tipo de bienes/servicios comprados (código DGII, default `09`) |
| 4 | `ncf` | NCF / e-NCF |
| 5 | `ncf_modificado` | NCF modificado (notas de crédito/débito); vacío si no aplica |
| 6 | `fecha_comprobante` | Fecha del comprobante, formato `AAAAMMDD` |
| 7 | `fecha_pago` | Fecha de pago, formato `AAAAMMDD`; vacío si no aplica |
| 8 | `monto_servicios` | Monto facturado en servicios |
| 9 | `monto_bienes` | Monto facturado en bienes |
| 10 | `total_facturado` | Total facturado (bienes + servicios, sin ITBIS) |
| 11 | `itbis_facturado` | ITBIS facturado |
| 12 | `itbis_retenido` | ITBIS retenido |
| 13 | `itbis_proporcionalidad` | ITBIS sujeto a proporcionalidad |
| 14 | `itbis_costo` | ITBIS llevado al costo |
| 15 | `itbis_adelantar` | ITBIS por adelantar |
| 16 | `itbis_percibido` | ITBIS percibido en compras |
| 17 | `tipo_retencion_isr` | Tipo de retención en ISR (código); vacío si no aplica |
| 18 | `retencion_renta` | Monto retención renta (ISR) |
| 19 | `isr_percibido` | ISR percibido en compras |
| 20 | `isc` | Impuesto Selectivo al Consumo |
| 21 | `otros_impuestos` | Otros impuestos y tasas |
| 22 | `propina_legal` | Monto propina legal |
| 23 | `forma_pago` | Forma de pago (código DGII `01`–`08`, default `04`) |

> **Nota:** el orden oficial del 606 coloca **Servicios** en el campo 8 y **Bienes** en el 9.

### `advertencias`

Array de strings legibles. Si no está vacío, **muéstralas antes de permitir la descarga**
(banner/lista). Ejemplos:

- `"e-CF E310000099 (RNC 101096225): firma 'INVALIDA' — verificar antes de declarar."`
- `"NCF XYZ (RNC 130000000): formato no valido o no autorizado — revisar."`
- `"e-CF E310000099: tiene retencion (ITBIS/ISR) pero falta Fecha de Pago (campo 7 obligatorio)."`
- `"RNC 130000000: comprobante sin NCF/e-NCF."`

No bloquean la generación; son para revisión humana.

---

### 2) Preview del TXT crudo (opcional)

Mismo endpoint de descarga pero con `formato=json`. Devuelve el contenido del archivo como
string (útil si quieres mostrar el `.txt` exacto antes de bajarlo).

```
GET /api/reportes/606?periodo=202606&formato=json
Headers: X-API-KEY: <token>
```

```json
{
  "status": true,
  "data": {
    "periodo": "202606",
    "rnc_emisor": "131256432",
    "cantidad": 5,
    "advertencias": [],
    "contenido": "606|131256432|202606|5\r\n101096225|1|09|E310000042726||20260609||0.10|1788.75|1788.85|321.99||||||||||||04\r\n..."
  }
}
```

- **Encabezado** (línea 1): `606|<RNC emisor>|<periodo>|<cantidad de registros>`.
- **Detalle**: una línea por transacción, 23 campos separados por `|`.
- Separador de línea: `\r\n`. Decimales con punto, 2 dígitos. Campos opcionales en 0 van vacíos.

---

### 3) Descarga del TXT

```
GET /api/reportes/606?periodo=202606
Headers: X-API-KEY: <token>
```

**Respuesta `200`** con headers:

```
Content-Type: text/plain; charset=utf-8
Content-Disposition: attachment; filename="606_202606.txt"
X-Advertencias-Count: 0
```

El cuerpo es el `.txt`. El header `X-Advertencias-Count` indica cuántas advertencias hubo
(por si descargas directo sin pasar por el preview).

---

### Validaciones y errores

| Situación | HTTP | Body |
|-----------|------|------|
| Período mal formado (no `AAAAMM`) | `400` | `{"status":false,"error":"Parametro periodo invalido. Formato: AAAAMM (ej: 202606)."}` |
| Mes fuera de rango (no `01`–`12`) | `400` | `{"status":false,"error":"Mes invalido en periodo. Use 01-12."}` |
| Falta / inválido `X-API-KEY` | `401` | `{"status":false,"error":"Credenciales requeridas. ..."}` |
| Reporte no soportado (ej. `/reportes/607`) | `404` | `{"status":false,"error":"Reporte no encontrado. Use 606."}` |
| Emisor sin RNC configurado | `500` | `{"status":false,"error":"No hay RNC del emisor configurado (emisor_config)."}` |

Período sin transacciones → `200` con `cantidad: 0`, `registros: []` (y el TXT solo trae el
encabezado con `0`). No es error.

---

### Ejemplo de integración (fetch / JS)

```js
const API = "https://gratex.net/api";
const API_KEY = "<token>";

// 1) Cargar preview para la tabla
async function cargarPreview606(periodo) {
  const res = await fetch(`${API}/reportes/606/preview?periodo=${periodo}`, {
    headers: { "X-API-KEY": API_KEY },
  });
  const json = await res.json();
  if (!res.ok || !json.status) {
    throw new Error(json.error || "Error cargando preview 606");
  }
  return json.data; // { periodo, rnc_emisor, cantidad, totales, advertencias, registros }
}

// 2) Descargar el TXT (dispara la descarga en el navegador)
async function descargar606(periodo) {
  const res = await fetch(`${API}/reportes/606?periodo=${periodo}`, {
    headers: { "X-API-KEY": API_KEY },
  });
  if (!res.ok) {
    const err = await res.json().catch(() => ({}));
    throw new Error(err.error || "Error descargando 606");
  }
  const blob = await res.blob();
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = `606_${periodo}.txt`;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
}
```

### Flujo de UI sugerido

1. Usuario elige período (`AAAAMM`) → llamar `cargarPreview606`.
2. Render: tabla con `registros` (usar `razon_social`, `ncf`, `tipo_comprobante`, montos…),
   tarjeta de `totales`, contador `cantidad`.
3. Si `advertencias.length > 0` → mostrar banner con la lista y pedir confirmación.
4. Botón **"Descargar 606 (.txt)"** → `descargar606`. Subir ese archivo al portal DGII.

---

## Reporte 607 — Ventas de bienes y servicios

Endpoints para generar el **Formato 607** de la DGII (ventas de bienes y servicios del
período). Contraparte del 606. Flujo recomendado: **(1)** preview en tabla con registros +
totales, **(2)** revisar advertencias, **(3)** descargar el `.TXT` para subirlo al portal DGII.

- **Base URL (producción):** `https://gratex.net/api`
- **Autenticación:** header `X-API-KEY: <token>` en todas las llamadas.
- **Método:** `GET` (solo lectura).
- Período `AAAAMM` (6 dígitos). Ej: junio 2026 = `202606`.

---

### Resumen de endpoints

| # | Endpoint | Devuelve | Uso |
|---|----------|----------|-----|
| 1 | `GET /reportes/607/preview?periodo=AAAAMM` | JSON (registros + totales + advertencias) | Tabla de revisión |
| 2 | `GET /reportes/607?periodo=AAAAMM&formato=json` | JSON con el TXT ya armado (string) | Previsualizar archivo crudo |
| 3 | `GET /reportes/607?periodo=AAAAMM` | Archivo `text/plain` (descarga) | Botón "Descargar 607" |

**Diferencia con el 606:** el 607 son **ventas** (`facturas` e-CF + facturas simples), nombre de
archivo `DGII_F_607_<RNC>_<PERIODO>.TXT`, y las 23 columnas son las del Formato 607.

---

### 1) Preview estructurado (para la tabla)

```
GET /api/reportes/607/preview?periodo=202606
Headers: X-API-KEY: <token>
```

**Respuesta `200`:**

```json
{
  "status": true,
  "data": {
    "periodo": "202606",
    "rnc_emisor": "131256432",
    "cantidad": 5,
    "totales": {
      "monto_facturado": 18510.00,
      "itbis_facturado": 3331.80,
      "itbis_retenido": 0.0,
      "retencion_renta": 0.0
    },
    "advertencias": [],
    "registros": [
      {
        "razon_social": "AGENCIA BELLA SAS",
        "tipo_comprobante": "E31",
        "estado_dgii": "ACEPTADO",
        "rnc": "101000236",
        "tipo_id": "1",
        "ncf": "E310000000003",
        "ncf_modificado": "",
        "tipo_ingreso": "01",
        "fecha_comprobante": "20260602",
        "fecha_retencion": "",
        "monto_facturado": 2500.00,
        "itbis_facturado": 450.00,
        "itbis_retenido": 0.0,
        "itbis_percibido": 0.0,
        "retencion_renta": 0.0,
        "isr_percibido": 0.0,
        "isc": 0.0,
        "otros_impuestos": 0.0,
        "propina_legal": 0.0,
        "efectivo": 2950.00,
        "cheque_transf": 0.0,
        "tarjeta": 0.0,
        "credito": 0.0,
        "bonos": 0.0,
        "permuta": 0.0,
        "otras": 0.0
      }
    ]
  }
}
```

### Campos de cada `registro`

Montos como **números** (redondeados a 2 decimales). Los 3 primeros son auxiliares de display
(NO forman parte de los 23 campos del 607):

| Clave | Tipo | Descripción |
|-------|------|-------------|
| `razon_social` | string | Nombre/razón social del cliente (display) |
| `tipo_comprobante` | string | `E31`/`E32`/`E33`/`E34` (e-CF) o `NCF` (factura simple) |
| `estado_dgii` | string | Estado del e-CF (`ACEPTADO`, `PENDIENTE`, ...). Las simples suelen ir `PENDIENTE` |

Los 23 campos oficiales del 607, en orden:

| # | Clave | Descripción |
|---|-------|-------------|
| 1 | `rnc` | RNC/Cédula del cliente (puede ir vacío en E32 menor al límite) |
| 2 | `tipo_id` | `1`=RNC, `2`=Cédula, vacío si no hay RNC |
| 3 | `ncf` | e-NCF (e-CF) o NCF legacy (factura simple) |
| 4 | `ncf_modificado` | NCF afectado (solo notas E33/E34); vacío si no aplica |
| 5 | `tipo_ingreso` | Tipo de ingreso (default `01`) |
| 6 | `fecha_comprobante` | `AAAAMMDD` |
| 7 | `fecha_retencion` | `AAAAMMDD`; vacío si no aplica |
| 8 | `monto_facturado` | Monto facturado (base sin ITBIS) = `SUM(factura_items.subtotal)` |
| 9 | `itbis_facturado` | ITBIS facturado = `SUM(factura_items.itbis_amount)` |
| 10 | `itbis_retenido` | ITBIS retenido (del XML, si aplica) |
| 11 | `itbis_percibido` | ITBIS percibido |
| 12 | `retencion_renta` | Retención renta (ISR) |
| 13 | `isr_percibido` | ISR percibido |
| 14 | `isc` | Impuesto Selectivo al Consumo |
| 15 | `otros_impuestos` | Otros impuestos/tasas |
| 16 | `propina_legal` | Propina legal |
| 17 | `efectivo` | Monto pagado en efectivo |
| 18 | `cheque_transf` | Cheque / transferencia |
| 19 | `tarjeta` | Tarjeta débito/crédito |
| 20 | `credito` | Venta a crédito |
| 21 | `bonos` | Bonos o certificados de regalo |
| 22 | `permuta` | Permuta |
| 23 | `otras` | Otras formas de venta |

**Formas de pago (17-23):** se obtienen de `TablaFormasPago/FormaDePago` del e-CF firmado
(`FormaPago` 1-8 → columna; `MontoPago`). Sin esa tabla → todo el total (con ITBIS) cae en una
sola columna (por `TipoPago`, o `efectivo` por defecto en facturas simples). La suma de 17-23
≈ `monto_facturado + itbis_facturado`.

### `advertencias`

Array de strings; mostrar antes de permitir la descarga si no está vacío. Ejemplos:

- `"NCF E310000000003: venta sin RNC/Cedula de cliente."`
- `"NCF E340000000010 (nota 34): falta NCF modificado (campo 4)."`
- `"NCF B0100000873: formato no valido o no autorizado — revisar."`
- `"NCF ...: tiene retencion (ITBIS/ISR) pero falta Fecha de Retencion (campo 7)."`

No bloquean la generación.

---

### 2) Preview del TXT crudo (opcional)

```
GET /api/reportes/607?periodo=202606&formato=json
Headers: X-API-KEY: <token>
```

```json
{
  "status": true,
  "data": {
    "periodo": "202606",
    "rnc_emisor": "131256432",
    "cantidad": 5,
    "advertencias": [],
    "contenido": "607|131256432|202606|5\r\n101000236|1|E310000000003||01|20260602||2500.00|450.00||||||||2950.00||||||\r\n..."
  }
}
```

- **Encabezado** (línea 1): `607|<RNC emisor>|<periodo>|<cantidad>`.
- **Detalle**: una línea por venta, 23 campos separados por `|`.
- Separador de línea `\r\n`. Decimales con punto, 2 dígitos. Opcionales en 0 → vacíos.

---

### 3) Descarga del TXT

```
GET /api/reportes/607?periodo=202606
Headers: X-API-KEY: <token>
```

**Respuesta `200`** con headers:

```
Content-Type: text/plain; charset=utf-8
Content-Disposition: attachment; filename="DGII_F_607_131256432_202606.TXT"
X-Advertencias-Count: 0
```

El cuerpo es el `.TXT`. Nombre de archivo oficial: `DGII_F_607_<RNC>_<PERIODO>.TXT`.

---

### Validaciones y errores

| Situación | HTTP | Body |
|-----------|------|------|
| Período mal formado (no `AAAAMM`) | `400` | `{"status":false,"error":"Parametro periodo invalido. Formato: AAAAMM (ej: 202606)."}` |
| Mes fuera de rango | `400` | `{"status":false,"error":"Mes invalido en periodo. Use 01-12."}` |
| Falta / inválido `X-API-KEY` | `401` | `{"status":false,"error":"Credenciales requeridas. ..."}` |
| Reporte no soportado | `404` | `{"status":false,"error":"Reporte no encontrado. Use 606 o 607."}` |
| Emisor sin RNC configurado | `500` | `{"status":false,"error":"No hay RNC del emisor configurado (emisor_config)."}` |

Período sin ventas → `200` con `cantidad: 0`, `registros: []` (TXT solo con encabezado).

---

### Ejemplo de integración (fetch / JS)

```js
const API = "https://gratex.net/api";
const API_KEY = "<token>";

async function cargarPreview607(periodo) {
  const res = await fetch(`${API}/reportes/607/preview?periodo=${periodo}`, {
    headers: { "X-API-KEY": API_KEY },
  });
  const json = await res.json();
  if (!res.ok || !json.status) throw new Error(json.error || "Error cargando preview 607");
  return json.data; // { periodo, rnc_emisor, cantidad, totales, advertencias, registros }
}

async function descargar607(periodo) {
  const res = await fetch(`${API}/reportes/607?periodo=${periodo}`, {
    headers: { "X-API-KEY": API_KEY },
  });
  if (!res.ok) {
    const err = await res.json().catch(() => ({}));
    throw new Error(err.error || "Error descargando 607");
  }
  // Nombre de archivo: tomarlo del header que envia el server.
  const cd = res.headers.get("Content-Disposition") || "";
  const fname = (cd.match(/filename="?([^"]+)"?/) || [])[1] || `DGII_F_607_${periodo}.TXT`;
  const blob = await res.blob();
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = fname;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
}
```

### Flujo de UI sugerido

1. Usuario elige período (`AAAAMM`) → `cargarPreview607`.
2. Render: tabla con `registros` (cliente, NCF, tipo_comprobante, montos, columnas de pago),
   tarjeta de `totales`, contador `cantidad`.
3. Si `advertencias.length > 0` → banner con la lista y confirmación.
4. Botón **"Descargar 607 (.TXT)"** → `descargar607`. Subir el archivo al portal DGII.
