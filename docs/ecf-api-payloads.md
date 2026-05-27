# e-CF API — Referencia de Endpoints y Payloads

Headers requeridos en todos los endpoints: `X-API-KEY: <key>`

---

## Endpoints disponibles

### Facturas — `/api/facturas`

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| `POST` | `/api/facturas` | Crear y emitir nueva factura e-CF |
| `GET` | `/api/facturas` | Listar facturas (paginado) |
| `GET` | `/api/facturas?id={id}` | Obtener factura por ID |
| `GET` | `/api/facturas/{id}/estado` | Consultar estado DGII actualizado |
| `GET` | `/api/facturas/{id}/pdf` | Descargar PDF de factura |
| `GET` | `/api/facturas/{id}/xml` | Descargar XML firmado (ECF) |
| `GET` | `/api/facturas/{id}/xml?type=rfce` | Descargar XML RFCE (solo E32 < 250k) |

#### Parámetros de listado (`GET /api/facturas`)

| Param | Default | Descripción |
|-------|---------|-------------|
| `page` | `1` | Página |
| `pageSize` | `20` | Resultados por página |
| `query` | — | Filtro por e-NCF, nombre, etc. |

#### Parámetro `?format=base64`

Aplica a `/pdf` y `/xml`. En lugar de descarga directa, retorna JSON:

```json
{
  "status": true,
  "data": {
    "filename": "E310000000335.xml",
    "content": "<base64>",
    "mime_type": "application/xml"
  }
}
```

---

### Estado DGII — respuesta de `/api/facturas/{id}/estado`

```json
{
  "status": true,
  "data": {
    "factura_id": 1154,
    "e_ncf": "E310000000335",
    "track_id": "fb2e8a7e-...",
    "estado_dgii": "ACEPTADO",
    "consulta": {
      "trackId": "fb2e8a7e-...",
      "codigo": "1",
      "estado": "Aceptado",
      "rnc": "131256432",
      "encf": "E310000000335",
      "fechaRecepcion": "5/27/2026 3:00:00 PM",
      "mensajes": [{ "valor": "", "codigo": 0 }]
    }
  }
}
```

Valores de `estado_dgii`: `ENVIADO` · `ACEPTADO` · `ACEPTADO CONDICIONAL` · `RECHAZADO` · `RFCE_ACEPTADO`

---

### Otros controladores

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| `POST` | `/api/auth/...` | Autenticación / tokens |
| `GET/POST/PUT/DELETE` | `/api/clients` | CRUD clientes |
| `GET/POST/PUT/DELETE` | `/api/users` | CRUD usuarios |
| `GET/POST` | `/api/cotizaciones` | Cotizaciones |
| `GET/POST` | `/api/ncf` | Gestión de secuencias NCF |
| `POST` | `/api/aprobaciones-comerciales` | Enviar ACECF a DGII (aprobación comercial saliente) |
| `POST` | `/api/ecf/recepcion` | Recibir e-CFs entrantes de otros emisores |
| `POST` | `/api/ecf/aprobacion-comercial` | Aprobación comercial entrante |
| `GET/POST` | `/api/ecf/autenticacion` | Flujo seed/validación DGII |

---

## Crear factura — `POST /api/facturas`

`Content-Type: application/json`

---

## Campos comunes (todos los tipos)

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `client_id` | int | ID del cliente emisor |
| `user_id` | int | ID del usuario |
| `tipo_ecf` | string | Tipo de comprobante (ver tabla abajo) |
| `fecha_emision` | string | Fecha en formato `DD-MM-YYYY` |
| `tipo_pago` | int | `1`=Contado, `2`=Crédito, `3`=Gratuito, `4`=Permuta, `5`=Otros |

## Tabla de tipos e-CF

| Código | Nombre |
|--------|--------|
| `31` | Factura de Crédito Fiscal |
| `32` | Factura de Consumo (≥250k → DGII directo; <250k → RFCE) |
| `33` | Nota de Débito |
| `34` | Nota de Crédito |
| `41` | Comprobante de Compras |
| `43` | Gastos Menores |
| `44` | Regímenes Especiales |
| `45` | Gubernamental |
| `46` | Comprobante de Exportaciones |
| `47` | Comprobante para Pagos al Exterior |

## `indicador_facturacion` por tipo de ITBIS

| Valor | Significado |
|-------|-------------|
| `1` | Gravado ITBIS 18% |
| `2` | Gravado ITBIS 16% |
| `3` | Tasa cero (exportaciones) |
| `4` | Exento |

## `indicador_bien_servicio`

| Valor | Significado |
|-------|-------------|
| `1` | Bien |
| `2` | Servicio |

---

## E31 — Factura de Crédito Fiscal

B2B. Requiere RNC del comprador.

```json
{
  "client_id": 3511,
  "user_id": 2,
  "tipo_ecf": "31",
  "fecha_emision": "27-05-2026",
  "tipo_ingresos": "01",
  "tipo_pago": 1,
  "indicador_monto_gravado": "0",
  "comprador": {
    "rnc": "131880681",
    "nombre": "EMPRESA COMPRADORA SRL"
  },
  "items": [
    {
      "numero_linea": 1,
      "indicador_facturacion": 1,
      "nombre_item": "Servicio profesional",
      "indicador_bien_servicio": 2,
      "cantidad": 5,
      "unidad_medida": "43",
      "precio_unitario": 1500.00
    }
  ],
  "totales": {
    "itbis1": "18",
    "itbis2": "16",
    "itbis3": "0"
  }
}
```

**Notas:**
- `tipo_ingresos`: `"01"` (Ingresos por operaciones)
- `indicador_monto_gravado`: `"0"` (Monto gravado incluye ITBIS), `"1"` (excluye)
- `comprador.rnc` es requerido para que DGII pueda vincular notas de crédito/débito futuras
- Mezcla de `indicador_facturacion` 1, 2, 3, 4 permitida en mismo comprobante

---

## E32 — Factura de Consumo

B2C. Sin comprador identificado. Dos flujos según monto total:
- **≥ RD$250,000**: envío directo a DGII certecf, retorna `track_id`
- **< RD$250,000**: flujo RFCE vía `fc.dgii.gov.do`, retorna `rfce_track_id`. El XML firmado se descarga en `GET /api/facturas/{id}/xml` y se sube manualmente al portal DGII.

```json
{
  "client_id": 3511,
  "user_id": 2,
  "tipo_ecf": "32",
  "fecha_emision": "27-05-2026",
  "tipo_ingresos": "01",
  "tipo_pago": 1,
  "indicador_monto_gravado": "0",
  "items": [
    {
      "numero_linea": 1,
      "indicador_facturacion": 1,
      "nombre_item": "Producto A",
      "indicador_bien_servicio": 1,
      "cantidad": 2,
      "unidad_medida": "43",
      "precio_unitario": 1200.00
    }
  ],
  "totales": {
    "itbis1": "18",
    "itbis2": "16",
    "itbis3": "0"
  }
}
```

---

## E33 — Nota de Débito

Modifica (aumenta) una factura E31 previa. Requiere `informacion_referencia`.

```json
{
  "client_id": 3511,
  "user_id": 2,
  "tipo_ecf": "33",
  "fecha_emision": "27-05-2026",
  "tipo_ingresos": "01",
  "tipo_pago": 1,
  "indicador_monto_gravado": "0",
  "items": [
    {
      "numero_linea": 1,
      "indicador_facturacion": 1,
      "nombre_item": "Ajuste de cargo",
      "indicador_bien_servicio": 2,
      "cantidad": 1,
      "unidad_medida": "43",
      "precio_unitario": 500.00
    }
  ],
  "informacion_referencia": {
    "ncf_modificado": "E310000000321",
    "rnc_otro_contribuyente": null,
    "fecha_ncf_modificado": "27-05-2026",
    "codigo_modificacion": "3",
    "razon_modificacion": "Nota de debito por ajuste de monto"
  },
  "totales": {
    "itbis1": "18",
    "itbis2": "16",
    "itbis3": "0"
  }
}
```

**Notas:**
- `ncf_modificado`: e-NCF del E31 original (debe estar ACEPTADO en DGII)
- `rnc_otro_contribuyente`: **siempre null** en ambiente certecf — si se envía el RNC, DGII retorna error 614
- `codigo_modificacion`: `"1"`=Anulación, `"2"`=Corrección monto, `"3"`=Descuento, `"4"`=Otros

---

## E34 — Nota de Crédito

Modifica (reduce) una factura E31 previa.

```json
{
  "client_id": 3511,
  "user_id": 2,
  "tipo_ecf": "34",
  "fecha_emision": "27-05-2026",
  "tipo_ingresos": "01",
  "tipo_pago": 1,
  "indicador_nota_credito": "0",
  "indicador_monto_gravado": "0",
  "items": [
    {
      "numero_linea": 1,
      "indicador_facturacion": 1,
      "nombre_item": "Descuento aplicado",
      "indicador_bien_servicio": 1,
      "cantidad": 1,
      "unidad_medida": "43",
      "precio_unitario": 75.00
    }
  ],
  "informacion_referencia": {
    "ncf_modificado": "E310000000321",
    "rnc_otro_contribuyente": null,
    "fecha_ncf_modificado": "27-05-2026",
    "codigo_modificacion": "3",
    "razon_modificacion": "Nota de credito por ajuste de monto"
  },
  "totales": {
    "itbis1": "18",
    "itbis2": "16",
    "itbis3": "0"
  }
}
```

**Notas:**
- `indicador_nota_credito`: `"0"`=Monto parcial, `"1"`=Anulación total
- `rnc_otro_contribuyente`: **siempre null** (igual que E33)
- El monto del item debe ser menor al saldo disponible del E31 referenciado

---

## E41 — Comprobante de Compras

Para registrar compras a proveedores. DGII actúa como agente de retención.

```json
{
  "client_id": 3511,
  "user_id": 2,
  "tipo_ecf": "41",
  "fecha_emision": "27-05-2026",
  "tipo_pago": 1,
  "indicador_monto_gravado": "0",
  "items": [
    {
      "numero_linea": 1,
      "indicador_facturacion": 1,
      "nombre_item": "Compra de insumos",
      "indicador_bien_servicio": 1,
      "cantidad": 10,
      "unidad_medida": "43",
      "precio_unitario": 800.00,
      "indicador_agente_retencion_percepcion": "1",
      "monto_itbis_retenido": 1440.00,
      "monto_isr_retenido": 0.00
    }
  ],
  "totales": {
    "itbis1": "18",
    "itbis2": "16",
    "itbis3": "0",
    "total_itbis_retenido": 1440.00,
    "total_isr_retencion": 0.00
  }
}
```

**Notas:**
- `indicador_agente_retencion_percepcion`: requerido por item. `"1"`=Agente de retención
- `monto_itbis_retenido`: ITBIS retenido = base × tasa (18% para ind_fact=1, 16% para ind_fact=2)
- `monto_isr_retenido`: ISR retenido (normalmente 0 para compras locales)
- Si no se envían, el API los calcula automáticamente

---

## E43 — Gastos Menores

Para gastos pequeños sin RNC del proveedor. Solo items exentos.

```json
{
  "client_id": 3511,
  "user_id": 2,
  "tipo_ecf": "43",
  "fecha_emision": "27-05-2026",
  "tipo_pago": 1,
  "items": [
    {
      "numero_linea": 1,
      "indicador_facturacion": 4,
      "nombre_item": "Compra de papeleria",
      "indicador_bien_servicio": 1,
      "cantidad": 3,
      "unidad_medida": "43",
      "precio_unitario": 150.00
    }
  ]
}
```

**Notas:**
- `indicador_facturacion` debe ser `4` (exento) — no se grava ITBIS
- Sin `totales` — no aplican tasas de ITBIS
- Sin `tipo_ingresos` ni `comprador`

---

## E44 — Regímenes Especiales

Para empresas en zonas francas u otros regímenes especiales. Solo items exentos.

```json
{
  "client_id": 3511,
  "user_id": 2,
  "tipo_ecf": "44",
  "fecha_emision": "27-05-2026",
  "tipo_ingresos": "01",
  "tipo_pago": 1,
  "items": [
    {
      "numero_linea": 1,
      "indicador_facturacion": 4,
      "nombre_item": "Producto en régimen especial",
      "indicador_bien_servicio": 1,
      "cantidad": 5,
      "unidad_medida": "43",
      "precio_unitario": 3000.00
    }
  ]
}
```

**Notas:**
- `indicador_facturacion` debe ser `4` (exento)
- Sin `totales` de ITBIS

---

## E45 — Gubernamental

Para ventas al gobierno. Igual estructura que E31 pero sin `comprador`.

```json
{
  "client_id": 3511,
  "user_id": 2,
  "tipo_ecf": "45",
  "fecha_emision": "27-05-2026",
  "tipo_ingresos": "01",
  "tipo_pago": 1,
  "indicador_monto_gravado": "0",
  "items": [
    {
      "numero_linea": 1,
      "indicador_facturacion": 1,
      "nombre_item": "Servicio gubernamental",
      "indicador_bien_servicio": 2,
      "cantidad": 1,
      "unidad_medida": "43",
      "precio_unitario": 5000.00
    }
  ],
  "totales": {
    "itbis1": "18",
    "itbis2": "16",
    "itbis3": "0"
  }
}
```

---

## E46 — Comprobante de Exportaciones

Para ventas al exterior. Solo tasa cero (`indicador_facturacion: 3`). Sin ITBIS.

```json
{
  "client_id": 3511,
  "user_id": 2,
  "tipo_ecf": "46",
  "fecha_emision": "27-05-2026",
  "tipo_ingresos": "01",
  "tipo_pago": 1,
  "items": [
    {
      "numero_linea": 1,
      "indicador_facturacion": 3,
      "nombre_item": "Mercaderia exportada",
      "indicador_bien_servicio": 1,
      "cantidad": 10,
      "unidad_medida": "43",
      "precio_unitario": 2500.00
    }
  ],
  "totales": {
    "itbis3": "0"
  }
}
```

**Notas:**
- `indicador_facturacion` debe ser `3` (tasa cero)
- `totales` solo acepta `itbis3: "0"` — no `itbis1`, no `itbis2`, no `monto_exento`

---

## E47 — Comprobante para Pagos al Exterior

Para pagos a personas o empresas no residentes. ISR retenido obligatorio por item.

```json
{
  "client_id": 3511,
  "user_id": 2,
  "tipo_ecf": "47",
  "fecha_emision": "27-05-2026",
  "tipo_pago": 1,
  "items": [
    {
      "numero_linea": 1,
      "indicador_facturacion": 4,
      "nombre_item": "Servicio de consultoría exterior",
      "indicador_bien_servicio": 2,
      "cantidad": 1,
      "unidad_medida": "43",
      "precio_unitario": 10000.00,
      "indicador_agente_retencion_percepcion": "1",
      "monto_isr_retenido": 2700.00
    }
  ],
  "totales": {
    "total_isr_retencion": 2700.00
  }
}
```

**Notas:**
- `indicador_bien_servicio` **debe ser `2` (Servicio)** — bienes no permitidos (error 294)
- `indicador_facturacion` debe ser `4` (exento de ITBIS)
- `monto_isr_retenido` requerido por item (típicamente 27% del monto base)
- **No** incluir `monto_itbis_retenido` — solo ISR aplica
- Sin `tipo_ingresos`

---

## Respuesta exitosa

```json
{
  "status": true,
  "data": {
    "factura_id": 1154,
    "e_ncf": "E310000000335",
    "track_id": "fb2e8a7e-18f1-442c-82b1-9d337b376f9d",
    "estado_dgii": "ENVIADO",
    "codigo_seguridad": "nAOIob",
    "total": 8850.00,
    "tipo_ecf": "31",
    "ambiente": "certecf"
  }
}
```

Para E32 RFCE (<250k), el campo relevante es `rfce_track_id` y `estado_dgii` = `"RFCE_ACEPTADO"`. El XML firmado se obtiene en `GET /api/facturas/{factura_id}/xml`.

## Consultar estado DGII

```
GET /api/facturas/{factura_id}/estado
X-API-KEY: <key>
```

`estado_dgii` posibles valores: `ENVIADO`, `ACEPTADO`, `ACEPTADO CONDICIONAL`, `RECHAZADO`, `RFCE_ACEPTADO`
