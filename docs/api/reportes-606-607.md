# Reporte 606 (Compras DGII) — Guía de integración Frontend

Endpoints para generar el **Formato 606** de la DGII (compras de bienes y servicios del
período). El flujo recomendado en el front es: **(1)** mostrar un preview en tabla con los
registros y totales, **(2)** revisar advertencias, **(3)** descargar el `.txt` para subirlo al
portal DGII.

- **Base URL (producción):** `https://gratex.net/api`
- **Autenticación:** header `X-API-KEY: <token>` en todas las llamadas.
- **Método:** `GET` (todos los endpoints son de solo lectura).
- El período siempre es `AAAAMM` (año + mes, 6 dígitos). Ej: junio 2026 = `202606`.

---

## Resumen de endpoints

| # | Endpoint | Devuelve | Uso en el front |
|---|----------|----------|-----------------|
| 1 | `GET /reportes/606/preview?periodo=AAAAMM` | JSON estructurado (registros + totales + advertencias) | Pintar tabla de revisión antes de descargar |
| 2 | `GET /reportes/606?periodo=AAAAMM&formato=json` | JSON con el TXT ya armado (string) | Previsualizar el archivo crudo (opcional) |
| 3 | `GET /reportes/606?periodo=AAAAMM` | Archivo `text/plain` (descarga) | Botón "Descargar 606" |

---

## 1) Preview estructurado (para la tabla)

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

## 2) Preview del TXT crudo (opcional)

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

## 3) Descarga del TXT

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

## Validaciones y errores

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

## Ejemplo de integración (fetch / JS)

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
