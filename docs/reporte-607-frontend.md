# Reporte 607 (Ventas DGII) — Guía de integración Frontend

Endpoints para generar el **Formato 607** de la DGII (ventas de bienes y servicios del
período). Contraparte del 606. Flujo recomendado: **(1)** preview en tabla con registros +
totales, **(2)** revisar advertencias, **(3)** descargar el `.TXT` para subirlo al portal DGII.

- **Base URL (producción):** `https://gratex.net/api`
- **Autenticación:** header `X-API-KEY: <token>` en todas las llamadas.
- **Método:** `GET` (solo lectura).
- Período `AAAAMM` (6 dígitos). Ej: junio 2026 = `202606`.

---

## Resumen de endpoints

| # | Endpoint | Devuelve | Uso |
|---|----------|----------|-----|
| 1 | `GET /reportes/607/preview?periodo=AAAAMM` | JSON (registros + totales + advertencias) | Tabla de revisión |
| 2 | `GET /reportes/607?periodo=AAAAMM&formato=json` | JSON con el TXT ya armado (string) | Previsualizar archivo crudo |
| 3 | `GET /reportes/607?periodo=AAAAMM` | Archivo `text/plain` (descarga) | Botón "Descargar 607" |

**Diferencia con el 606:** el 607 son **ventas** (`facturas` e-CF + facturas simples), nombre de
archivo `DGII_F_607_<RNC>_<PERIODO>.TXT`, y las 23 columnas son las del Formato 607.

---

## 1) Preview estructurado (para la tabla)

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

## 2) Preview del TXT crudo (opcional)

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

## 3) Descarga del TXT

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

## Validaciones y errores

| Situación | HTTP | Body |
|-----------|------|------|
| Período mal formado (no `AAAAMM`) | `400` | `{"status":false,"error":"Parametro periodo invalido. Formato: AAAAMM (ej: 202606)."}` |
| Mes fuera de rango | `400` | `{"status":false,"error":"Mes invalido en periodo. Use 01-12."}` |
| Falta / inválido `X-API-KEY` | `401` | `{"status":false,"error":"Credenciales requeridas. ..."}` |
| Reporte no soportado | `404` | `{"status":false,"error":"Reporte no encontrado. Use 606 o 607."}` |
| Emisor sin RNC configurado | `500` | `{"status":false,"error":"No hay RNC del emisor configurado (emisor_config)."}` |

Período sin ventas → `200` con `cantidad: 0`, `registros: []` (TXT solo con encabezado).

---

## Ejemplo de integración (fetch / JS)

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
