# Endpoint NCF - Documentación y Ejemplos JSON

## Descripción General

El endpoint `/api/ncf` permite consultar y actualizar el campo NCF (Número de Comprobante Fiscal) de las facturas de manera específica, sin necesidad de enviar todos los campos de la factura. Además expone la gestión de la secuencia NCF `B01` (consultar actual, próximo y fijar valor).

## Autenticación

Todos los requests requieren un token de API válido en el header:
```
X-API-KEY: {tu-token}
```
o
```
Authorization: Bearer {tu-token}
```

---

## 📌 GET - Consultar NCF

### Request
```http
GET /api/ncf?id={factura_id}
```

### Parámetros
- `id` (requerido) - ID de la factura

### Ejemplo de Request
```bash
curl -X GET "http://localhost:8000/api/ncf?id=1" \
  -H "X-API-KEY: {tu-token}"
```

### Respuestas JSON

#### ✅ Éxito (200 OK)
```json
{
  "status": true,
  "data": {
    "id": "1",
    "no_factura": "FAC_20251201_001",
    "NCF": "B0100000001"
  }
}
```

#### ❌ Error - ID faltante (200 OK con status false)
```json
{
  "status": false,
  "error": "Factura ID is required for this endpoint."
}
```

#### ❌ Error - Factura no encontrada (404 Not Found)
```json
{
  "status": false,
  "error": "Factura not found"
}
```

#### ❌ Error - Sin autenticación (401 Unauthorized)
```json
{
  "status": false,
  "error": "API token is required. Use header: X-API-KEY: <token> or Authorization: Bearer <token>"
}
```

---

## 📝 PUT - Actualizar NCF

### Request
```http
PUT /api/ncf
Content-Type: application/json
```

### Body (JSON)
```json
{
  "id": 1,
  "NCF": "B0100000999"
}
```

### Ejemplo de Request
```bash
curl -X PUT "http://localhost:8000/api/ncf" \
  -H "Content-Type: application/json" \
  -H "X-API-KEY: {tu-token}" \
  -d '{
    "id": 1,
    "NCF": "B0100000999"
  }'
```

### Respuestas JSON

#### ✅ Éxito (200 OK)
```json
{
  "status": true,
  "message": "NCF updated successfully",
  "data": {
    "id": 1,
    "NCF": "B0100000999"
  }
}
```

#### ❌ Error - ID faltante (200 OK con status false)
```json
{
  "status": false,
  "error": "Factura ID is required"
}
```

#### ❌ Error - NCF faltante (200 OK con status false)
```json
{
  "status": false,
  "error": "NCF value is required"
}
```

#### ❌ Error - Factura no encontrada (404 Not Found)
```json
{
  "status": false,
  "error": "Factura not found"
}
```

#### ❌ Error - Sin autenticación (401 Unauthorized)
```json
{
  "status": false,
  "error": "API token is required. Use header: X-API-KEY: <token> or Authorization: Bearer <token>"
}
```

---

## 🔢 GET - Secuencia NCF actual (B01)

### Request
```http
GET /api/ncf/sequence
```

Devuelve la fila de secuencia NCF tipo `B01` (comprobante interno, no e-CF).

### Respuesta (200 OK)
```json
{
  "status": true,
  "data": {
    "type": "B01",
    "current_value": 5
  }
}
```

---

## ⏭️ GET - Próximo NCF (B01)

### Request
```http
GET /api/ncf/next
```

Calcula el siguiente NCF **sin consumirlo** (`current_value` + 1, formato `B01` + 8 dígitos).

### Respuesta (200 OK)
```json
{
  "status": true,
  "data": "B0100000006"
}
```

---

## 🔧 PUT - Fijar secuencia NCF (B01)

### Request
```http
PUT /api/ncf/sequence
Content-Type: application/json
```

### Body (JSON)
```json
{
  "current_value": 100
}
```

### Respuesta - Éxito (200 OK)
```json
{
  "status": true
}
```

### Respuesta - current_value faltante (200 OK con status false)
```json
{
  "status": false,
  "error": "Missing current_value"
}
```

> Nota: estos tres endpoints operan sobre la secuencia legacy `B01` (hardcoded). La numeración e-CF (E31..E47) se dispensa internamente con `ncfModel::dispenseNextECF()` durante la emisión y no se expone por este endpoint.

---

## 🔗 Archivos Relacionados

1. **Controller**: `src/Controllers/ncfController.php`
   - GET `?id=` → consulta NCF de factura (`facturaModel::getNCF`)
   - PUT `{id, NCF}` → actualiza NCF de factura (`facturaModel::updateNCF`)
   - GET `/sequence`, GET `/next`, PUT `/sequence` → gestión de secuencia B01 (`ncfModel`)

2. **Models**:
   - `src/Models/facturaModel.php` — `getNCF()`, `updateNCF()`
   - `src/Models/ncfModel.php` — `getCurrentSequence()`, `getNextNCF()`, `setSequence()`, `dispenseNextECF()`

3. **Router**: `src/Router.php` — case `'ncf'` enruta a ncfController

4. **Middleware**: `src/Middleware/AuthMiddleware.php` — valida token (excepto OPTIONS)

---

## ⚡ Métodos HTTP Soportados

- ✅ **GET** - Consultar NCF
- ✅ **PUT** - Actualizar NCF
- ✅ **OPTIONS** - Preflight CORS
- ❌ **POST, DELETE, PATCH** - No soportados (405 Method Not Allowed)
