# Fase 2 — Runner de Set de Pruebas DGII

Este directorio contiene las herramientas para ejecutar los 25 casos del Set
de Pruebas de Fase 2 de DGII contra `https://gratex.net/`.

## Archivos

- `Fase2XlsxReader.php` — parser xlsx en PHP puro (sin Composer).
- `send_fase2.php` — runner CLI que lee el xlsx y envia cada caso al API.
- `fase2_results.json` — reporte generado por el runner (al ejecutarse).

## Antes de correr — checklist de despliegue

### 1. Subir todos estos archivos al servidor (gratex.net)

Cambios en `src/`:
- `src/Utils/FacturacionElectronica/ECFEmissionService.php` (acepta override `e_ncf` y orquesta RFCE para E32 < 250k)
- `src/Utils/FacturacionElectronica/ECFXmlBuilder.php` (agrega `UnidadMedida` por item)
- `src/Utils/FacturacionElectronica/RFCEXmlBuilder.php` (nuevo)
- `src/Utils/FacturacionElectronica/DgiiReceptionService.php` (nuevo metodo `recibirResumen()`)
- `src/Utils/FacturacionElectronica/IncomingXmlValidator.php` (nuevo, para los endpoints publicos)
- `src/Utils/FacturacionElectronica/IncomingXmlExtractor.php` (nuevo)
- `src/Controllers/facturaController.php` (acepta `e_ncf` en el body, agrega `/xml` para descarga, acepta `unidad_medida` en items)
- `src/Controllers/ecfRecepcionController.php` (nuevo)
- `src/Controllers/ecfAprobacionComercialController.php` (nuevo)
- `src/Controllers/ecfAutenticacionController.php` (nuevo)
- `src/Models/facturaModel.php` (`getXmlFirmado()` + columnas RFCE en `saveFacturaConECF()`)
- `src/Models/ecfRecibidoModel.php` (nuevo)
- `src/Models/aprobacionComercialModel.php` (nuevo)
- `src/Models/authSeedModel.php` (nuevo)
- `src/Router.php` (nueva ruta `ecf`)

Migraciones SQL (ejecutar en phpMyAdmin de gratex.net en este orden):
- `db/migrations/002_add_ecf_reception.sql`
- `db/migrations/003_add_rfce_tracking.sql`
- `db/migrations/004_fase2_setup.sql` (configura el RNC de prueba en `emisor_config`)

Archivos del runner (subir o ejecutar localmente apuntando al server):
- `tools/Fase2XlsxReader.php`
- `tools/send_fase2.php`
- `samples/131256432-05052026110320.xlsx` (el set de pruebas)

### 2. Verificar en el servidor

Asegurate que el PHP del server tenga:
- `extension=zip` habilitada
- `extension=curl` habilitada
- `extension=openssl` habilitada (ya la usabas)

Y que `emisor_config` tenga el RNC de prueba (la migracion 004 lo hace):
```sql
SELECT id, rnc, razon_social FROM emisor_config WHERE id = 1;
-- debe devolver: 131256432, DOCUMENTOS ELECTRONICOS DE 02
```

### 3. (Opcional) Limpiar facturas previas si lo necesitas

Si ya enviaste e-CFs y quieres empezar limpio:
```sql
DELETE FROM factura_items;
DELETE FROM facturas;
```

## Ejecucion

### Modo dry-run (recomendado primero, no envia nada a DGII)

Desde tu maquina local apuntando al server (no requiere subir el script):

```bash
php tools/send_fase2.php samples/131256432-05052026110320.xlsx \
  --api=https://gratex.net/api \
  --api-key=7a775f6fb0d5ccab15cf149d2c60f15c \
  --dry-run \
  --output=tools/fase2_dryrun.json
```

Esto imprime los 25 payloads que se mandarian. Revisa que se vean bien.

### Ejecucion real — todos los casos

```bash
php tools/send_fase2.php samples/131256432-05052026110320.xlsx \
  --api=https://gratex.net/api \
  --api-key=7a775f6fb0d5ccab15cf149d2c60f15c \
  --client-id=1 \
  --output=tools/fase2_results.json
```

`--client-id` es el id del cliente comprador en la tabla `clients` (el RNC
real lo lee del xlsx; el client_id solo se usa para satisfacer la API).
Por defecto es `1` — ajustalo al id del registro que insertaste con la
migracion 004 (`SELECT id FROM clients WHERE rnc='131880681'`).

### Filtros

```bash
# Solo casos E31:
... --filter=E31

# Solo casos E31 y E32:
... --filter=E31,E32

# Un solo caso especifico:
... --case=E310000000005
```

## Que hace el runner por cada caso

1. Lee la fila correspondiente del xlsx.
2. Construye el JSON: `tipo_ecf`, `e_ncf` (override), `client_id`, `items[]`, etc.
3. Hace `POST https://gratex.net/api/facturas`.
4. El sistema:
   - Reserva el e-NCF que le diste (no autodispensa).
   - Construye el XML del e-CF.
   - Lo firma con tu certificado.
   - Si es E32 < 250,000 → genera RFCE y lo envia a `https://fc.dgii.gov.do/certecf/recepcionfc/api/recepcion/ecf`.
   - Si es cualquier otro tipo → lo envia a `https://ecf.dgii.gov.do/certecf/emisionrecepcion/api/recepcion/ecf`.
5. Captura la respuesta (`track_id` o `rfce_track_id`).

## Despues de ejecutar

### Para los 4 casos E32 < 250,000 (RFCE)

Tienes que **subir manualmente al portal DGII** el XML integro de cada uno.
Para descargarlos:

```
GET https://gratex.net/api/facturas/{factura_id}/xml
X-API-KEY: ...
```

El `factura_id` esta en `tools/fase2_results.json`. El archivo descargado
se llama `E32...xml` y es lo que subes en la interfaz Fase 2 de DGII.

Los 4 e-NCF afectados:
- E320000000011 (40,120)
- E320000000012 (47,200)
- E320000000013 (112,100)
- E320000000015 (64,900)

### Para los demas 21 casos

Ya estan en DGII (con `track_id`). Puedes verificar su estado con:

```
GET https://gratex.net/api/facturas/{factura_id}/estado
X-API-KEY: ...
```

## Reporte

Al terminar, `tools/fase2_results.json` tiene una entrada por caso:

```json
{
  "caso": "131256432E310000000005",
  "tipo_ecf": "31",
  "e_ncf": "E310000000005",
  "http_status": 200,
  "ok": true,
  "factura_id": 7,
  "track_id": "abc123...",
  "rfce_track_id": null,
  "estado_dgii": "ENVIADO",
  "flujo": "ECF"
}
```

Tambien imprime un resumen `OK x/25, fallaron y` al final.

## Si algo falla

1. Mira `tools/fase2_results.json` — cada entry fallida tiene `error` con el mensaje de DGII o de validacion.
2. Casos comunes:
   - **422 cliente no tiene RNC** → revisa que `clients` tenga la fila con `rnc=131880681`.
   - **422 e_ncf override invalido** → la fila del xlsx tiene un eNCF mal formado.
   - **502 Fallo en emision DGII** → DGII rechazo el XML; el detalle viene en `error`.
3. Para reintentar solo los fallidos, copia los e-NCF a una lista y filtra:
   ```bash
   php tools/send_fase2.php ... --case=E310000000005
   ```

## Ambiente

El script no especifica `ambiente` en el body, asi que usa el default del
sistema (`DGII_ECF_ENVIRONMENT` en `.env`). Para Fase 2 debe estar en
`certecf`. Verifica antes de correr:

```bash
grep DGII_ECF_ENVIRONMENT .env
```
