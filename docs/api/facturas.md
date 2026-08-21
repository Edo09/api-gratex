# e-CF API — Referencia de Endpoints y Payloads

## Autenticación

Todos los endpoints (salvo `/api/auth/login`) requieren credenciales. Dos esquemas:

| Esquema | Headers | Uso |
|---------|---------|-----|
| **Sesión de app** | `Authorization: Bearer <token>` (o `X-API-KEY: <token>`) | Apps con usuarios (ej. fiscalo-react). El token sale del login. |
| **Integración** | `X-API-KEY: <api_key>` + `X-API-SECRET: <api_secret>` | Clientes máquina-a-máquina (credenciales por tenant, ver `/api/integracion/*`). |

> OJO: si se envía `X-API-KEY`, tiene prioridad sobre el `Authorization: Bearer`.

### Login — `POST /api/auth/login`

```json
{ "emailOrUsername": "ana@empresa.do", "password": "..." }
```

`emailOrUsername` acepta email o username; **ambos son únicos globales**, así
que el login resuelve el tenant sin `tenant_id`. (`tenant_id` se sigue aceptando
por compatibilidad, pero se ignora.) Respuesta (envoltorio `{success,...}`,
distinto al `{status,...}` del resto):

```json
{
  "success": true,
  "data": {
    "token": "988f9777dea9383661bc2d6e735f1d3b",
    "user": { "id": 2, "email": "ana@empresa.do", "username": "ana", "name": "Ana Reyes", "role": "admin" }
  }
}
```

`POST /api/auth/signout` (con el token) revoca la sesión.

---

## Endpoints disponibles

### Facturas — `/api/facturas`

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| `POST` | `/api/facturas` | Crear y emitir nueva factura e-CF |
| `POST` | `/api/facturas/preview` | Generar PDF de prueba sin emitir (no consume NCF) |
| `GET` | `/api/facturas` | Listar facturas (paginado) |
| `GET` | `/api/facturas?id={id}` | Obtener factura por ID |
| `GET` | `/api/facturas/stats` | Estadísticas de e-CFs emitidos |
| `GET` | `/api/facturas/{id}/estado` | Consultar estado DGII actualizado |
| `GET` | `/api/facturas/{id}/pdf` | Descargar PDF de factura |
| `GET` | `/api/facturas/{id}/xml` | Descargar XML firmado (ECF) |
| `GET` | `/api/facturas/{id}/xml?type=rfce` | Descargar XML RFCE (solo E32 < 250k) |

#### Parámetros de listado (`GET /api/facturas`)

| Param | Default | Descripción |
|-------|---------|-------------|
| `page` | `1` | Página |
| `pageSize` | `10` | Resultados por página |
| `query` | — | Busca en e-NCF, nº factura, cliente (nombre/razón social/RNC/correo/teléfono) y **descripción de los ítems** |
| `estado` | `todos` | `aprobado` (incluye `ACEPTADO`, `ACEPTADO_CONDICIONAL` y `RFCE_*` aceptados), `rechazado` (incluye `RFCE_RECHAZADO`) o `todos` |
| `tipo_ecf` | — | Filtra por tipo: `E31`, `E32`, … `E47` (también acepta `31`, `32`, …) |

#### Detalle por ID (`GET /api/facturas?id={id}`)

`data` es un **array** con la factura (compatibilidad histórica; tomar `data[0]`).
A diferencia del listado, el detalle viene **enriquecido** para pintar el
documento completo en una sola llamada:

```json
{
  "status": true,
  "data": [
    {
      "id": 1154, "e_ncf": "E310000000335", "tipo_ecf": "31", "total": "2950.00",
      "estado_dgii": "ACEPTADO", "codigo_seguridad": "mG2wqe",
      "items": [
        { "description": "Servicio profesional", "quantity": "5", "amount": "1500.00",
          "subtotal": "7500.00", "itbis_amount": "1350.00", "indicador_facturacion": 1 }
      ],
      "cliente": { "id": 3511, "client_name": "...", "razon_social": "...", "rnc": "...", "direccion": "..." },
      "emisor": { "rnc": "...", "razon_social": "...", "nombre_comercial": "...", "direccion": "...", "correo": "..." }
    }
  ]
}
```

- `items` — líneas de `factura_items`.
- `cliente` — registro completo de `clients` (solo si la factura tiene `client_id`).
- `emisor` — fila de `emisor_config` del tenant.

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
    "secuencia_utilizada": true,
    "consulta": {
      "trackId": "fb2e8a7e-...",
      "codigo": "1",
      "estado": "Aceptado",
      "rnc": "131256432",
      "encf": "E310000000335",
      "secuenciaUtilizada": true,
      "fechaRecepcion": "5/27/2026 3:00:00 PM",
      "mensajes": [{ "valor": "", "codigo": 0 }]
    }
  }
}
```

Valores de `estado_dgii`: `ENVIADO` · `ACEPTADO` · `ACEPTADO_CONDICIONAL` · `EN_PROCESO` · `RECHAZADO` · `NO_ENCONTRADO` · y variantes RFCE `RFCE_ACEPTADO` / `RFCE_RECHAZADO` / `RFCE_NO_ENCONTRADO`.

#### Manejo de RECHAZADO (importante)

`secuencia_utilizada` (bool|null) indica si el e-NCF puede reusarse cuando DGII rechaza:

- `false` → **reutilizable** (rechazo por firma/certificado o estructura XML inválida). Reemitir con el **mismo** `e_ncf`: `POST /api/facturas` incluyendo `"e_ncf": "<el mismo>"`.
- `true` → secuencia **consumida**. Reemitir **sin** `e_ncf` (toma una nueva).
- `null` → aún no consultado / no aplica.

El motivo del rechazo viene en `consulta.mensajes[].valor` (+ `codigo`). Muéstralo al usuario.

> RFCE (E32 <250k): se consulta por RNC + e-NCF + código de seguridad (no usa `track_id`). La respuesta trae los mismos campos.

---

### Estadísticas e-CF — `GET /api/facturas/stats`

Retorna un resumen de todos los e-CFs emitidos: totales por tipo, por estado DGII, evolución mensual y estado de las secuencias NCF.

```
GET /api/facturas/stats
X-API-KEY: <key>
```

#### Respuesta

```json
{
  "status": true,
  "data": {
    "resumen": {
      "total_ecf": 45,
      "monto_total": 1250000.00,
      "tipos_distintos": 8,
      "primer_ecf": "2026-05-01 09:00:00",
      "ultimo_ecf": "2026-05-28 14:30:00"
    },
    "por_tipo": [
      {
        "tipo_ecf": "31",
        "nombre": "Factura de Crédito Fiscal",
        "total": 12,
        "monto_total": 750000.00,
        "aceptados": 11,
        "rfce": 0,
        "rechazados": 0,
        "enviados": 1,
        "ultimo_emitido": "2026-05-28 14:30:00"
      }
    ],
    "por_estado": [
      { "estado": "ACEPTADO", "total": 38, "monto_total": 1100000.00 },
      { "estado": "RFCE_ACEPTADO", "total": 4, "monto_total": 80000.00 },
      { "estado": "ENVIADO", "total": 2, "monto_total": 50000.00 },
      { "estado": "RECHAZADO", "total": 1, "monto_total": 20000.00 }
    ],
    "por_mes": [
      { "mes": "2026-05", "total": 45, "monto_total": 1250000.00 },
      { "mes": "2026-04", "total": 30, "monto_total": 800000.00 }
    ],
    "secuencias": [
      { "type": "E31", "nombre": "Factura de Crédito Fiscal", "secuencia_actual": 12, "total_emitidos": 12 },
      { "type": "E32", "nombre": "Factura de Consumo",         "secuencia_actual": 8,  "total_emitidos": 8  }
    ]
  }
}
```

#### Campos

| Campo | Descripción |
|-------|-------------|
| `resumen.total_ecf` | Total de e-CFs emitidos (todos los tipos) |
| `resumen.monto_total` | Suma de todos los montos emitidos (RD$) |
| `resumen.tipos_distintos` | Cantidad de tipos e-CF distintos utilizados |
| `por_tipo[].tipo_ecf` | Código del tipo (31, 32, 33, …) |
| `por_tipo[].nombre` | Nombre del tipo |
| `por_tipo[].total` | Total emitido de este tipo |
| `por_tipo[].aceptados` | Cuántos tienen `estado_dgii = ACEPTADO` |
| `por_tipo[].rfce` | Cuántos pasaron por flujo RFCE (E32 < 250k) |
| `por_tipo[].rechazados` | Cuántos fueron rechazados por DGII |
| `por_mes[].mes` | Año-Mes (`YYYY-MM`) — últimos 12 meses |
| `secuencias[].secuencia_actual` | Último número de secuencia asignado |
| `secuencias[].total_emitidos` | Facturas guardadas en DB para ese tipo |

---

### Otros controladores

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| `POST` | `/api/auth/login` · `/signout` | Autenticación de usuarios (ver sección Autenticación) |
| `GET/POST/PUT/DELETE` | `/api/clients` | CRUD clientes (`?page,?pageSize,?query` para listado) |
| `GET/POST/PUT/DELETE` | `/api/products` | CRUD catálogo de productos/servicios (`?page,?pageSize,?query`) |
| `GET/POST/PUT/DELETE` | `/api/proveedores` | CRUD directorio de proveedores (`?page,?pageSize,?query`; lista incluye `compras` derivado de gastos) |
| `GET` | `/api/unidades-medida` | Catálogo DGII de unidades de medida (solo lectura) — ver nota abajo |
| `GET` | `/api/provincias-municipios` | Catálogo DGII de provincias/municipios/distritos (solo lectura) — ver nota abajo |
| `GET/POST/PUT/DELETE` | `/api/users` | CRUD usuarios |
| `GET/POST` | `/api/gastos` (+ `/stats`, `/{id}/estado`, `/{id}/xml`) | Gastos menores y facturas de proveedores — ver [../modules/gastos.md](../modules/gastos.md) |
| `GET/POST` | `/api/cotizaciones` | Cotizaciones |
| `GET/POST/PUT/DELETE` | `/api/facturas-simples` | Facturas NO electrónicas — ver [facturas-simples.md](facturas-simples.md) |
| `GET/POST` | `/api/ncf/rangos` | Rangos e-NCF autorizados por DGII (listar / registrar) — ver sección abajo |
| `GET/PUT` | `/api/ncf` | Gestión legacy de secuencias NCF (B01) |
| `POST` | `/api/aprobaciones-comerciales` | Enviar ACECF a DGII (aprobación comercial saliente) |
| `POST` | `/api/ecf/recepcion` | Recibir e-CFs entrantes de otros emisores |
| `POST` | `/api/ecf/aprobacion-comercial` | Aprobación comercial entrante |
| `GET/POST` | `/api/ecf/autenticacion` | Flujo seed/validación DGII |
| `POST/GET` | `/api/integracion/ecf` · `/aprobacion-comercial` · `/recibidos` · `/aprobaciones` | Modo integración (X-API-KEY + X-API-SECRET, sin DB propia) |
| `GET/POST` | `/api/landing` | Configuración de landing page |

---

### Rangos e-NCF autorizados — `/api/ncf/rangos`

Las secuencias e-NCF funcionan como en DGII: por cada tipo se **solicita un rango**
(Número Desde/Hasta) que llega con **No. Autorización** y **Fecha Vencimiento**.
La emisión dispensa números del rango ACTIVO (con capacidad y no vencido); cuando
se agota, la emisión falla con un error claro hasta que se registre el siguiente
rango aprobado. El `FechaVencimientoSecuencia` del XML sale del rango que dispensó
(fallback: `emisor_config.fecha_vencimiento_secuencia`).

**`GET /api/ncf/rangos`** (ambiente activo; filtro opcional `?type=E31`):

```json
{
  "status": true,
  "ambiente": "ecf",
  "data": [
    { "type": "E31", "numero_desde": 1, "numero_hasta": 100, "current_value": 3,
      "usados": 3, "restantes": 97, "fecha_vencimiento": "2027-12-31",
      "no_solicitud": "6009804999", "no_autorizacion": "6005308087", "estado": "activo" }
  ]
}
```

`estado`: `activo` (dispensando) · `pendiente` (rango futuro en cola) · `agotado` ·
`vencido` · `sin_limite` (fila legacy sin rango registrado; emite sin tope).

**`POST /api/ncf/rangos`** — registrar un rango aprobado por DGII:

```json
{
  "type": "E31",
  "numero_desde": 101,
  "numero_hasta": 200,
  "fecha_vencimiento": "2028-12-31",
  "no_solicitud": "6009805000",
  "no_autorizacion": "6005308099"
}
```

Validaciones: `numero_desde` debe ser **mayor** que todo número ya usado/autorizado
del tipo en el ambiente (422 si no). Si existía una fila legacy "sin límite", se
cierra en su consumo actual y el rango nuevo pasa a dispensar al agotarse el activo.

> `GET /api/facturas/stats` y `/api/gastos/stats` ahora incluyen por secuencia
> `restantes` (capacidad vigente; `null` = sin rango con límite) y `vencimiento`.

---

### Unidades de medida — `GET /api/unidades-medida`

Catálogo DGII compartido (vive en el DB master). Cada ítem de un e-CF lleva
`unidad_medida` = **código numérico DGII** = el `id` de este catálogo
(43 = Unidad por defecto, 21 = KG, 24 = Litro…). `codigo`/`descripcion` son solo
para mostrar; **no** se envían al XML.

```json
{ "status": true, "data": [
  { "id": 21, "codigo": "KG", "descripcion": "Kilogramo" },
  { "id": 43, "codigo": "UND", "descripcion": "Unidad" }
]}
```

- En `POST /api/facturas` y `POST /api/gastos`, cada `items[].unidad_medida` debe
  ser un `id` válido del catálogo (si se omite/queda vacío, default `43`). Un
  código fuera del catálogo devuelve **422**.
- Aplica también a la auto-emisión de gastos (E41/E43/E47) y se persiste en
  `factura_items.unidad_medida` / `gasto_items.unidad_medida`.

---

### Provincias / municipios — `GET /api/provincias-municipios`

Catálogo DGII compartido (vive en el DB master, tabla `dgii_provincia_municipio`,
582 códigos). El **`codigo` de 6 dígitos** (`PPMMDD`) es lo que va en
`<Municipio>`/`<Provincia>` (y `<MunicipioComprador>`/`<ProvinciaComprador>`) del
XML — es el valor `ProvinciaMunicipioType` que exige el XSD. `descripcion`/`tipo`
son para mostrar/agrupar.

Filtros opcionales: `?tipo=PROVINCIA|MUNICIPIO|DISTRITO` y `?provincia=25` (código
de 2 dígitos).

```json
{ "status": true, "data": [
  { "codigo": "250000", "provincia_codigo": "25", "descripcion": "PROVINCIA SANTIAGO", "tipo": "PROVINCIA" },
  { "codigo": "250100", "provincia_codigo": "25", "descripcion": "MUNICIPIO SANTIAGO", "tipo": "MUNICIPIO" }
]}
```

- En la emisión, `emisor.municipio/provincia` y `comprador.municipio/provincia` se
  **resuelven al código** contra este catálogo: si el valor ya es un código válido
  se usa tal cual; si es un nombre (`"Santiago"`) se sustituye por su código; si no
  hay match se deja intacto (**fail-open** — la validación final la hace el XSD DGII).
  No devuelve 422 (a diferencia de `unidad_medida`).

---

## Crear factura — `POST /api/facturas`

`Content-Type: application/json`
`X-API-KEY: <key>`

### Payload mínimo (lo que el front DEBE enviar)

El backend calcula totales e ITBIS, y rellena los datos del comprador desde el cliente. El front solo necesita enviar **identidad + items**:

```json
{
  "client_id": 3511,
  "user_id": 2,
  "tipo_ecf": "31",
  "items": [
    {
      "nombre_item": "Servicio profesional",
      "indicador_facturacion": 1,
      "indicador_bien_servicio": 2,
      "cantidad": 5,
      "unidad_medida": "43",
      "precio_unitario": 1500.00
    }
  ]
}
```

Todo lo demás (`fecha_emision`, `tipo_pago`, `tipo_ingresos`, `totales`, `comprador`, `e_ncf`) es opcional y tiene defaults. Ver "Lo que el backend rellena solo" abajo.

---

## Campos comunes (todos los tipos)

| Campo | Req | Tipo | Default | Descripción |
|-------|-----|------|---------|-------------|
| `client_id` | **sí** | int | — | ID del cliente emisor (de él se toman RNC, razón social, dirección) |
| `tipo_ecf` | **sí** | string | — | Tipo de comprobante (ver tabla abajo) |
| `items` | **sí** | array | — | Al menos 1 item |
| `user_id` | no | int | `null` | ID del usuario que emite |
| `fecha_emision` | no | string | hoy | Formato `DD-MM-YYYY` |
| `tipo_pago` | no | int | `1` | `1`=Contado, `2`=Crédito, `3`=Gratuito, `4`=Permuta, `5`=Otros |
| `tipo_ingresos` | no | string | `"01"` | `"01"` Operaciones (no aplica a E43/E47) |
| `indicador_monto_gravado` | no | string | `"0"` | `"0"`=precio incluye ITBIS, `"1"`=lo excluye (E31/32/33/34/41/45) |
| `comprador` | no | object | del cliente | Sobrescribe datos del comprador (ver abajo) |
| `totales` | no | object | calculado | Sobrescribe tasas/totales (ver abajo) |
| `e_ncf` | no | string | autodispensado | Forzar un e-NCF específico (normalmente NO enviar) |
| `date` | no | string | ahora | Fecha de registro en DB (`YYYY-MM-DD H:i:s`) |
| `strict_input` | no | bool | `false` | Si `true`, usa `totales`/`comprador` tal cual se envían, sin mezclar |

#### Campos de pago opcionales (crédito)

`fecha_limite_pago`, `termino_pago`, `tipo_cuenta_pago`, `numero_cuenta_pago`, `banco_pago`, `fecha_desde`, `fecha_hasta`, `total_paginas`.

---

## Lo que el backend rellena solo

El front NO necesita calcular nada de esto:

- **`totales`** — Se calcula desde los items: monto gravado por tasa, ITBIS (18% si `indicador_facturacion=1`, 16% si `=2`, 0% si `=3`, exento si `=4`), monto exento y monto total. Solo envía `totales` si quieres forzar tasas distintas a las default (`itbis1:18, itbis2:16, itbis3:0`).
- **`comprador`** — Se toma del registro del cliente (`client_id`): `rnc`, `razon_social`, `direccion`, `municipio`, `provincia`, `correo`, `contacto`. Solo envía `comprador` para sobrescribir un campo puntual (ej. otro RNC).
- **Retención E41/E47** — Si no envías `monto_itbis_retenido`/`monto_isr_retenido`, el API los calcula (E41: ITBIS por tasa; E47: 27% ISR).
- **`e_ncf`** — La secuencia NCF se asigna sola según `tipo_ecf` y ambiente activo.

> **Importante E31:** el cliente (`client_id`) debe tener RNC en su registro. Si no, devuelve 422. Puedes suplirlo con `comprador.rnc`.

### Objeto `comprador` (override)

Campo de nombre es **`razon_social`** (no `nombre`). Campos aceptados:

| Campo | Descripción |
|-------|-------------|
| `rnc` | RNC del comprador (11 u 9 dígitos) |
| `razon_social` | Nombre/razón social |
| `identificador_extranjero` | Para comprador no residente (E46/E47), en vez de `rnc` |
| `direccion`, `municipio`, `provincia`, `correo`, `contacto` | Datos adicionales |

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
    "razon_social": "EMPRESA COMPRADORA SRL"
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
- `comprador` es **opcional**: si el cliente (`client_id`) ya tiene RNC y razón social en su registro, se toman de ahí. Envía `comprador` solo para sobrescribir.
- El RNC del comprador es necesario para que DGII pueda vincular notas de crédito/débito futuras (viene del cliente o de `comprador.rnc`).
- `totales` es opcional (calculado). El ejemplo lo muestra solo para ilustrar las tasas.
- Mezcla de `indicador_facturacion` 1, 2, 3, 4 permitida en mismo comprobante.

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

## Respuesta exitosa — `POST /api/facturas`

HTTP `200` con `status: true`. El front debe guardar `factura_id` (para PDF/XML/estado) y mostrar `e_ncf`.

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
    "ambiente": "certecf",
    "fecha_emision_dgii": "27-05-2026",
    "dgii_response": { }
  }
}
```

| Campo | Descripción |
|-------|-------------|
| `factura_id` | ID interno — úsalo en `/pdf`, `/xml`, `/estado` |
| `e_ncf` | e-NCF asignado (ej. `E310000000335`) |
| `track_id` | ID de seguimiento DGII (null en E32 RFCE) |
| `estado_dgii` | `ENVIADO` recién emitido; consultar `/estado` para el final |
| `codigo_seguridad` | Código de seguridad del comprobante (va en el QR/PDF) |
| `total` | Monto total con ITBIS |
| `ambiente` | `certecf` (cert) o producción |
| `dgii_response` | Respuesta cruda de DGII (debug) |

Para **E32 RFCE (<250k)** el campo relevante es `rfce_track_id` y `estado_dgii` = `"RFCE_ACEPTADO"` (sin `track_id`). El XML firmado se obtiene en `GET /api/facturas/{factura_id}/xml`.

### Respuesta de error

HTTP `4xx`/`5xx` con `status: false` y un mensaje en `error`:

```json
{ "status": false, "error": "El cliente no tiene RNC y es requerido para e-CF tipo 31 (Credito Fiscal)" }
```

Códigos comunes: `400` JSON inválido · `422` validación (tipo_ecf, client_id, items, RNC faltante) · `404` cliente no encontrado · `502` fallo en emisión DGII.

---

## Flujo recomendado para el front

1. `POST /api/facturas` con el payload mínimo → recibe `factura_id`, `e_ncf`, `estado_dgii`.
2. (Opcional) Polling a `GET /api/facturas/{factura_id}/estado` hasta `ACEPTADO`/`RECHAZADO`.
3. Mostrar/descargar PDF: `GET /api/facturas/{factura_id}/pdf` (o `?format=base64` para incrustar).
4. E32 RFCE: descargar XML firmado en `GET /api/facturas/{factura_id}/xml` y subirlo al portal DGII.

> Para previsualizar el PDF **antes** de emitir (sin consumir secuencia NCF), usa `POST /api/facturas/preview` con `client_id` + `items` (+ `tipo_ecf`, `ncf` opcionales). Devuelve el PDF en base64.

## Consultar estado DGII

```
GET /api/facturas/{factura_id}/estado
X-API-KEY: <key>
```

`estado_dgii` posibles valores: `ENVIADO`, `ACEPTADO`, `ACEPTADO_CONDICIONAL`, `EN_PROCESO`, `RECHAZADO`, `NO_ENCONTRADO`, `RFCE_ACEPTADO`, `RFCE_RECHAZADO`, `RFCE_NO_ENCONTRADO`. La respuesta incluye `secuencia_utilizada` (ver "Manejo de RECHAZADO" arriba).
