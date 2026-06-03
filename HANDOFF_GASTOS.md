# Handoff — Módulo de Gastos

Contexto para que otra IA (o dev) continúe el trabajo. Fecha: 2026-06-03.

---

## 1. Proyecto

API REST PHP para facturación electrónica (e-CF) de República Dominicana (DGII).
- Stack: PHP 8+, MySQL (PDO), Apache. **Sin Composer** (libs en `vendor/` manuales: fpdf, phpqrcode).
- Entry point: `index.php` → `src/Router.php` (switch por primer segmento de la URL).
- Patrón: front controller → Controllers → Models → `Database` (PDO singleton).
- Auth: header `X-API-KEY` (clientes propios) o `Authorization: Bearer` (DGII).
- Convención de modelos: métodos devuelven `['success', payload]` o `['error', mensaje]`.
- Respuestas JSON: `{ "status": true, "data": ... }` o `{ "status": false, "error": ... }` con `http_response_code`.
- **Producción**: `DGII_ECF_ENVIRONMENT=ecf` en el servidor. Certificación DGII ya completa (ver `docs/dgii-certification.md`).
- Docs de estructura general: `docs/backend-structure.md`, `docs/database-structure.md`.

---

## 2. Qué se construyó (Módulo de Gastos)

Módulo nuevo para gestionar gastos en **dos categorías**, con emisión e-CF a DGII para los que emite la empresa.

### Categorías y tipos
| categoria | tipo_gasto | NCF DGII | es_auto_emision | NCF de dónde sale |
|---|---|---|---|---|
| `gastos_menores` | `E43` | 13 | true | secuencia interna (DGII) |
| `facturas_proveedores` | `E41` | 11 | true | secuencia interna (DGII) |
| `facturas_proveedores` | `E47` | 17 | true | secuencia interna (DGII) |
| `facturas_proveedores` | `E31` | 01 | false | lo digita el usuario |
| `facturas_proveedores` | `B01` | 01 | false | lo digita el usuario |
| `facturas_proveedores` | `E33` | 03 | false | lo digita el usuario (Nota Débito recibida) |
| `facturas_proveedores` | `E34` | 04 | false | lo digita el usuario (Nota Crédito recibida) |

Reglas clave (decididas con el usuario):
- `categoria` es un **campo explícito** (no derivado). El server valida que `tipo_gasto` pertenezca a la categoría.
- `es_auto_emision` se **deriva** del tipo (`E41/E43/E47` = true), no se confía en el cliente.
- **Auto-emision** (E41/E43/E47): la empresa los EMITE a DGII como e-CF real.
- **Recibidos** (E31/B01/E33/E34): solo se registran — ya los emitió el proveedor a DGII. NO se re-emiten.
- `rnc_proveedor` requerido **excepto** para E43 (gastos menores: peajes/parqueos sin RNC).
- Notas E33/E34 viven en ambos lados: en facturas la empresa las emite; en gastos el proveedor las manda (recibidas).

### Guard de seguridad (IMPORTANTE)
Variable de entorno `DGII_ECF_EMISSION_ENABLED`:
- **`false` / ausente (default)**: los auto-emision NO se envían a DGII ni consumen secuencia. Se guardan con `estado_dgii = PENDIENTE_EMISION` + un `aviso` en la respuesta.
- **`true`**: emite de verdad (firma + envía).

Razón: el servidor está en producción `ecf`. El usuario pidió explícitamente **no hacer pruebas reales todavía**. Por eso el guard arranca apagado.

---

## 3. Archivos tocados/creados

| Archivo | Estado | Rol |
|---|---|---|
| `db/migrations/007_add_gastos_module.sql` | nuevo | tablas `gastos` + `gasto_items` |
| `db/migrations/008_add_gastos_ecf_emission.sql` | nuevo | columnas tracking DGII + indicadores fiscales |
| `src/Models/gastoModel.php` | nuevo | CRUD + emisión e-CF + stats |
| `src/Controllers/gastosController.php` | nuevo | GET lista/id/stats/estado/xml, POST |
| `src/Router.php` | editado | agregado `case 'gastos'` (después de `facturas-simples`) |
| `.env.example` | editado | documentado `DGII_ECF_EMISSION_ENABLED=false` |
| `tests/test_gastos.http` | nuevo | casos de prueba (recibido, auto-emision, errores) |
| `docs/gastos-module.md` | nuevo | documentación completa del módulo |
| `docs/backend-structure.md` | nuevo | estructura backend (contexto general) |
| `docs/database-structure.md` | nuevo | estructura BD (contexto general) |

---

## 4. Esquema (migraciones 007 + 008)

**`gastos`**
- 007: `id`, `categoria`, `tipo_gasto`, `ncf`, `rnc_proveedor`, `nombre_proveedor`, `fecha`, `subtotal`, `itbis`, `total`, `es_auto_emision`, `ambiente`, `user_id`, `created_at`, `updated_at`.
- 008: `estado_dgii` (DEFAULT `REGISTRADO`), `track_id`, `codigo_seguridad`, `fecha_emision_dgii`, `xml_firmado`, `respuesta_dgii`, `secuencia_utilizada`.
- UNIQUE `(rnc_proveedor, ncf)`. Índices: `categoria`, `tipo_gasto`, `rnc_proveedor`, `ambiente`, `estado_dgii`, `track_id`.

**`gasto_items`**
- 007: `id`, `gasto_id` (FK → `gastos.id` ON DELETE CASCADE), `description`, `amount`, `quantity`, `subtotal`, `itbis_amount`.
- 008: `indicador_facturacion` (1=ITBIS18, 2=16, 3=0, 4=exento, 0=no facturable), `indicador_bien_servicio` (1=bien, 2=servicio).

Estados (`estado_dgii`): `REGISTRADO` · `PENDIENTE_EMISION` · `ENVIADO` · `ACEPTADO` · `ACEPTADO_CONDICIONAL` · `RECHAZADO` · `EN_PROCESO` · `NO_ENCONTRADO` · `ERROR`.

> ✅ Migraciones 007 y 008 **ya corridas** (2026-06-03). La BD del servidor es `mtldtmte_new_gratexdb` y NO es accesible localmente.

---

## 5. Endpoints

Todos bajo `/api/gastos`, protegidos con `AuthMiddleware`.

| Método | Ruta | Acción |
|---|---|---|
| GET | `/api/gastos` | lista paginada (`?page,?pageSize,?query,?categoria`) |
| GET | `/api/gastos/{id}` o `?id=` | un gasto con líneas |
| GET | `/api/gastos/stats` | estadísticas (por_tipo, por_categoria, por_mes, secuencias) |
| GET | `/api/gastos/{id}/estado` | consulta estado del e-CF en DGII + actualiza |
| GET | `/api/gastos/{id}/xml` | XML firmado del e-CF |
| POST | `/api/gastos` | crear |

POST requeridos: `categoria`, `tipo_gasto`, `nombre_proveedor`, `items[]`. `rnc_proveedor` requerido salvo E43. `ncf` requerido solo si recibido (E31/B01/E33/E34).

---

## 6. Cómo funciona la emisión (detalle técnico crítico)

Se **reusa** el pipeline de facturas: `src/Utils/FacturacionElectronica/ECFEmissionService.php` → `emitir($payload)`. Ese servicio ya soporta E41/E43/E47/E33/E34 (validados en Fase 4 de certificación) y **internamente dispensa la secuencia y envía** a DGII.

Por eso `gastoModel::createGasto`, para auto-emision con guard ON, llama `emitir()` **primero** y guarda después (igual que `facturaController::handleEmisionECF` + `facturaModel::saveFacturaConECF`). NO se pre-dispensa secuencia.

Flujo en `createGasto`:
```
valida categoria/tipo/rnc/nombre/items
deriva es_auto_emision
si recibido        -> persistGasto(estado=REGISTRADO)            // no DGII
si auto + guard OFF -> persistGasto(estado=PENDIENTE_EMISION, ncf=null)  // no DGII, no secuencia
si auto + guard ON  -> emitirGastoDgii() -> persistGasto con e_ncf/track_id/estado/codigo_seguridad/xml_firmado
   (si emitir() lanza -> persistGasto con estado=ERROR + respuesta_dgii, para trazabilidad)
```

Mapeo gasto → e-CF (`emitirGastoDgii` + `mapItemsForEcf` + `computeTotalesEcf`):
- `rnc_proveedor` → `comprador.rnc`; `nombre_proveedor` → `comprador.razon_social`.
- E43 se emite **sin comprador**.
- `tipo_ecf` = `substr(tipo_gasto, 1)` (E41 → "41").
- ITBIS por línea: si no viene `itbis_amount`, se calcula del `indicador_facturacion` (1=18%, 2=16%, resto=0). `computeTotalesEcf` replica `computeTotales` de `facturaController` (mismo contrato de totales).

`ECFEmissionService::emitir()` requiere: `emisor_config` (fila id=1), cert `.p12` (`DGII_ECF_CERT_PATH` + `DGII_ECF_CERT_PASSWORD`). Ya configurados (facturas los usan).

---

## 7. Cómo activar / probar (pendiente, lo haría el usuario)

1. Correr migraciones `007` y `008` en la BD del servidor (phpMyAdmin).
2. Verificar que `ncf_sequences` tenga filas `E41/E43/E47` para el ambiente activo (vienen de `tools/migration_ncf_ambiente.sql`).
3. **Probar en ambiente de prueba, NO en producción**: `DGII_ECF_ENVIRONMENT=testecf` (o `certecf`) + `DGII_ECF_EMISSION_ENABLED=true`.
4. Crear un E41/E43/E47 → debe volver con `ncf` (e-NCF), `track_id`, `estado_dgii`, `codigo_seguridad`.
5. `GET /api/gastos/{id}/estado` para confirmar aceptación en DGII.
6. `tests/test_gastos.http` tiene todos los casos listos (cambiar token y puerto si difieren).

---

## 8. Trabajo pendiente / decisiones abiertas

1. ~~Correr migraciones 007 + 008~~ ✅ hechas 2026-06-03.
2. **Probar emisión** en testecf/certecf antes de prender el guard en producción.
3. **E34 para invalidar gasto propio**: hoy E33/E34 están como RECIBIDOS (solo registro). Falta el flujo donde la empresa EMITE una E34 (auto-emision) para anular un E41/E43/E47 propio, con referencia al NCF que modifica (`informacion_referencia`: `ncf_modificado`, `razon_modificacion`). Se discutió pero NO se implementó. Si se agrega:
   - habría que permitir E34 auto-emision (un flag o endpoint distinto, porque hoy `es_auto_emision` deriva solo del tipo y E34 → recibido).
   - agregar columnas de referencia a `gastos` (mirar cómo lo hace `facturas`: `ncf_modificado`, `fecha_ncf_modificado`, `codigo_modificacion`, `razon_modificacion`, migración 006).
4. **PUT/DELETE**: el modelo tiene `updateGasto`/`deleteGasto` pero el controlador NO los expone (un e-CF emitido es inmutable; se corrige con E34). Decidido no exponerlos.
5. **Re-emitir un PENDIENTE_EMISION**: cuando el guard estaba apagado, los gastos quedan en `PENDIENTE_EMISION` con `ncf=null`. NO hay acción para emitirlos después (habría que agregar un `POST /api/gastos/{id}/emitir`). Pendiente si se necesita.
6. **PDF / representación impresa**: facturas tienen `FacturaPdfGenerator`. Gastos NO tienen PDF aún. Pendiente si se requiere.
7. **Reporte 606**: los gastos son la base del reporte 606 de DGII. No hay endpoint de exportación 606 todavía.

---

## 9. Gotchas / convenciones a respetar

- **NO usar `<If>` en `.htaccess`** (el server no lo soporta, rompe rutas).
- Router usa `strpos` en el PRIMER `/api/` (URLs de DGII tienen doble `/api/`). `/api/gastos/{id}/estado` y `/xml` caen en `gastosController` porque el switch es por primer segmento.
- Las migraciones 003/005/006/008 NO usan `START TRANSACTION` (DDL auto-commit en MySQL 8); 007 sí lo usa porque crea tablas.
- Los hints del IDE sobre `require_once(...)` con paréntesis son cosméticos — el resto del código usa paréntesis, se mantiene por consistencia.
- `gastoModel` y `ncfModel` comparten la misma conexión PDO (singleton `Database::getInstance()`).
- Lint: `php -l <archivo>` (PHP está en PATH). Todos los archivos del módulo pasan sin errores.
- No hay tests automatizados (PHPUnit); las pruebas son `.http` (REST Client de VSCode).

---

## 10. Referencias rápidas de código

- Pipeline de emisión: `src/Utils/FacturacionElectronica/ECFEmissionService.php` (método `emitir`).
- Cómo facturas arman el payload e-CF: `src/Controllers/facturaController.php` → `handleEmisionECF`, `computeTotales`, `mapItemsForXml`.
- Cómo facturas guardan con e-CF: `src/Models/facturaModel.php` → `saveFacturaConECF`, `updateECFEstado`, `getECFStats`.
- Secuencias: `src/Models/ncfModel.php` → `dispenseNextECF`, `rollbackECFSequence`, `resolveActiveAmbiente`.
- Doc del módulo: `docs/gastos-module.md`.
