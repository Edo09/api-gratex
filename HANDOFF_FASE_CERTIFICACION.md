# Handoff — Certificación DGII e-CF (api-gratex)

Documento para retomar la sesión desde otra máquina. Leer este archivo al
inicio de la conversación nueva para tener todo el contexto.

> **Última actualización:** Fin de Fase 3 — 11/11 ACECF aceptadas por DGII.
> Siguiente: Fases 4+ (pendientes de definir según portal DGII).

---

## 1. Estado actual (alto nivel)

| Fase DGII | Descripción | Estado |
|---|---|---|
| 1 | Inscripción, registro de URLs y certificado | ✅ Pasada |
| **2** | Emisión de 25 e-CF (todos los tipos) + 4 RFCE | ✅ Pasada |
| **3** | Generación y envío de 11 Aprobaciones Comerciales (ACECF) | ✅ **11/11 ACEPTADAS** |
| 4+ | TBD — el user mirará el portal DGII para saber qué sigue | ⏳ Pendiente |

---

## 2. Stack y entorno

### Servidor (producción de testing)

- **Dominio:** `https://gratex.net/`
- **Hosting:** Hostgator (cPanel, MySQL 8 / Percona Server)
- **Ambiente DGII activo:** `CerteCF` (certificación)
- **PHP:** 8.x con OpenSSL 3.x (requiere cert re-cifrado con AES-256/PBKDF2)

### Local (Windows del user)

- **PHP CLI:** `C:\php\php.exe`
- **Sin php.ini cargado** — las extensiones se pasan por flags `-d extension=...`.
  Comando completo para correr scripts locales:

  ```powershell
  & "C:\php\php.exe" -d "extension_dir=C:\php\ext" -d "extension=zip" `
    -d "extension=curl" -d "extension=openssl" -d "extension=mbstring" `
    -d "extension=fileinfo" "tools\<script>.php" <args>
  ```

### Credenciales clave (de `.env` del servidor)

- `DGII_ECF_ENVIRONMENT=CerteCF`
- `DGII_ECF_CERT_PATH=certificados/20260501-2020077-KQBYARLQB.p12`
- `DGII_ECF_CERT_PASSWORD=Gratexdgii2025`
- API Key del sistema: `7a775f6fb0d5ccab15cf149d2c60f15c`

### Datos de prueba DGII (configurados durante Fase 2)

- **Emisor (nuestro RNC de prueba):** `131256432`
- **Razón Social emisor:** `DOCUMENTOS ELECTRONICOS DE 02`
- **Cert real:** Persona Natural, cédula `00109122788` ("ZOILO HECTOR EDWIN..."), delegada para actuar en nombre de RNC `131256432`
- **Comprador test:** RNC `131880681`, "DOCUMENTOS ELECTRONICOS DE 03", `client_id=3511` en tabla `clients`
- **User ID:** 2 (campo `user_id` en facturas)

---

## 3. Lo que está construido en el código

### Servicios principales

| Archivo | Responsabilidad |
|---|---|
| `src/Utils/FacturacionElectronica/DgiiAuthService.php` | Semilla → firma → token bearer |
| `src/Utils/FacturacionElectronica/DgiiXmlSigner.php` | Firma XMLDSig con cert .p12 |
| `src/Utils/FacturacionElectronica/DgiiReceptionService.php` | POST a DGII (Recepcion / RecepcionFC / ConsultaResultado / AprobacionComercial) |
| `src/Utils/FacturacionElectronica/ECFXmlBuilder.php` | Construye XML e-CF (E31..E47) — type-aware con `ID_DOC_CONFIG` y `TOTALES_CONFIG` |
| `src/Utils/FacturacionElectronica/RFCEXmlBuilder.php` | Construye RFCE (resumen E32 < 250k) |
| `src/Utils/FacturacionElectronica/ACECFXmlBuilder.php` | Construye ACECF (Aprobación Comercial saliente) |
| `src/Utils/FacturacionElectronica/ECFEmissionService.php` | Orquestador emisión e-CF + RFCE |
| `src/Utils/FacturacionElectronica/ACECFEmissionService.php` | Orquestador envío ACECF |
| `src/Utils/FacturacionElectronica/IncomingXmlValidator.php` | Valida firma XMLDSig en entrantes |
| `src/Utils/FacturacionElectronica/IncomingXmlExtractor.php` | Extrae XML de multipart/raw body |

### Controllers / endpoints expuestos en gratex.net

| Endpoint | Método | Para qué |
|---|---|---|
| `/api/facturas` | POST | Emitir e-CF (Fase 2) |
| `/api/facturas/{id}/xml` | GET | Descargar XML firmado |
| `/api/facturas/{id}/xml?type=rfce` | GET | Descargar RFCE firmado |
| `/api/facturas/{id}/estado` | GET | Consultar estado en DGII |
| `/api/aprobaciones-comerciales` | POST | Enviar ACECF saliente (Fase 3) |
| `/api/ecf/recepcion` | POST | URL Recepción (rol receptor) |
| `/api/ecf/aprobacion-comercial` | POST | URL Aprobación Comercial entrante |
| `/api/ecf/autenticacion/semilla` | GET | URL Autenticación (rol receptor) |
| `/api/ecf/autenticacion/validarsemilla` | POST | Validar semilla firmada |

### Migraciones SQL aplicadas en producción

1. `db/migrations/001_add_ecf_module.sql` — columnas e-CF en facturas, secuencias, emisor_config
2. `db/migrations/002_add_ecf_reception.sql` — tablas para módulo receptor
3. `db/migrations/003_add_rfce_tracking.sql` — columnas rfce_* en facturas
4. `db/migrations/004_fase2_setup.sql` — emisor + cliente test con datos del set

---

## 4. URLs reales de DGII (verificadas en swagger)

Endpoint base: `https://ecf.dgii.gov.do/CerteCF` y `https://fc.dgii.gov.do/CerteCF`.

| Servicio | URL |
|---|---|
| Autenticación semilla | `/Autenticacion/api/Autenticacion/Semilla` (GET) |
| Autenticación validar | `/Autenticacion/api/Autenticacion/ValidarSemilla` (POST) |
| Recepción e-CF | `/Recepcion/api/FacturasElectronicas` (POST multipart) |
| Consulta estado | `/ConsultaResultado/api/Consultas/Estado` (GET) |
| Recepción RFCE | `https://fc.dgii.gov.do/CerteCF/RecepcionFC/api/recepcion/ecf` (POST multipart) |
| Aprobación Comercial | `/AprobacionComercial/api/AprobacionComercial` (POST multipart) |

**Nombre del archivo en multipart:** DGII valida que sea `{RNCEmisor}{eNCF}.xml`
(ej. `131256432E310000000001.xml`). No usar nombres genéricos como `ecf.xml`.

---

## 5. Tools / scripts CLI

Todos viven en `tools/`. Se ejecutan **localmente** apuntando a gratex.net.

| Script | Para qué |
|---|---|
| `tools/Fase2XlsxReader.php` | Parser XLSX puro en PHP (usa ZipArchive + DOMDocument) |
| `tools/send_fase2.php` | Envía los 25 e-CF del set Fase 2 |
| `tools/check_fase2_status.php` | Consulta estado de cada factura enviada |
| `tools/send_fase3.php` | Envía las 11 ACECF del set Fase 3 |
| `tools/list_set.php` | Inspecciona el xlsx Fase 2 (encf, fechas, totales) |
| `tools/list_acecf.php` | Inspecciona el xlsx Fase 3 |
| `tools/list_rfce.php` | Inspecciona la hoja RFCE del xlsx Fase 2 |

### Ejecución típica Fase 3 (la que acabamos de hacer)

```powershell
& "C:\php\php.exe" -d "extension_dir=C:\php\ext" -d "extension=zip" `
  -d "extension=curl" -d "extension=openssl" -d "extension=mbstring" `
  -d "extension=fileinfo" "tools\send_fase3.php" `
  "samples\131256432-22052026165246.xlsx" `
  --api=https://gratex.net/api `
  --api-key=7a775f6fb0d5ccab15cf149d2c60f15c `
  --output=tools\fase3_results.json
```

---

## 6. Sets de prueba en `samples/`

| Archivo | Contenido | Para |
|---|---|---|
| `131256432-08052026161604.xlsx` | 25 e-CF + 4 RFCE | Fase 2 (actualizado) |
| `131256432-22052026165246.xlsx` | 11 ACECF (hoja `ACEECF_Generadas`) | Fase 3 (último) |
| `e-CF 31..47 v.1.0.xsd` | XSDs por tipo | Referencia construcción XML |
| `ACECF v.1.0.xsd` | XSD Aprobación Comercial | |
| `RFCE 32 v.1.0.xsd` | XSD Resumen | |
| `ANECF v.1.0.xsd` | XSD Anulación | (no usado aún) |
| `ARECF v1.0.xsd` | XSD Aprobación Receptor | (no usado aún) |
| `Semilla v.1.0.xsd` | XSD Semilla | (consumido por DgiiAuthService) |

---

## 7. Lecciones aprendidas (gotchas)

### XSD por tipo de e-CF es muy variable

`IdDoc` y `Totales` cambian entre E31, E32, E34, E44, E46, etc. Por eso
`ECFXmlBuilder` usa dos tablas de configuración:

```php
private const ID_DOC_CONFIG = [...];     // qué campos van en IdDoc por tipo
private const TOTALES_CONFIG = [...];    // qué campos permite Totales por tipo
```

Si DGII rechaza con "invalid child element" o "element not expected",
hay que ajustar la matriz correspondiente.

### El cert .p12 debe ser AES-256/PBKDF2

OpenSSL 3.x del server NO carga `.p12` cifrados con 3DES/RC2 (legacy).
Hay que re-cifrar:

```bash
openssl pkcs12 -legacy -in old.p12 -out temp.pem -nodes
openssl pkcs12 -export -in temp.pem -out new.p12 \
  -keypbe AES-256-CBC -certpbe AES-256-CBC -macalg SHA256
```

El re-cifrado correcto tiene **~7123 bytes** (vs ~7347 del legacy).

### Datos del emisor en XML deben coincidir EXACTAMENTE con el set

DGII compara campo a campo (`Municipio`, `CodigoVendedor`, `ContactoComprador`,
`DireccionComprador`, fechas, etc.) contra los valores que están en su BD
para ese caso de prueba. Por eso el script `send_fase2.php` extrae **todos**
los campos del xlsx y los manda como overrides en el body POST.

### El controller acepta overrides

`POST /api/facturas` permite mandar `emisor`, `comprador`, `rfce_emisor`,
`rfce_comprador` en el body como overrides parciales de los datos en BD.
Esto es lo que permite que cada caso de Fase 2 use los datos específicos
del set sin tener que cambiar `emisor_config` por caso.

### Filename en multipart debe ser `{RNCEmisor}{eNCF}.xml`

Si se envía con nombre genérico (`ecf.xml`, `rfce.xml`), DGII rechaza con
código 3243 "La longitud del nombre del archivo no es válida".
`DgiiReceptionService::buildDgiiFilename()` lo construye automáticamente
desde el XML.

### `secuenciaUtilizada: false` significa que el e-NCF está libre

Cuando DGII rechaza por XSD/validación, normalmente NO marca el e-NCF como
usado. Se puede reintentar el mismo e-NCF después de corregir el XML.

**Excepción:** los RFCE rechazados pueden quedar con el `codigoSeguridad`
marcado como "ya utilizado". Si volvemos a generar el mismo RFCE con el
mismo `fecha_hora_firma`, el código de seguridad coincidirá y DGII rechazará
con código 75. Re-generar con timestamp diferente resuelve.

### Las pruebas se resetean cuando hay rechazos

Si DGII rechaza algún comprobante durante una fase, el portal puede
mostrar mensaje *"Las pruebas de datos han sido reiniciadas debido a que
se han rechazado comprobantes"*. Esto deja los contadores en 0/N. Después
de cada reset hay que:

1. Borrar las facturas relacionadas de la BD local:
   ```sql
   DELETE FROM factura_items WHERE factura_id IN (
     SELECT id FROM facturas WHERE e_ncf REGEXP '^E(31|32|33|34|41|43|44|45|46|47)0000000'
   );
   DELETE FROM facturas WHERE e_ncf REGEXP '^E(31|32|33|34|41|43|44|45|46|47)0000000';
   ```
2. Reenviar todo

### Delegación de cert es per-RNC en DGII

El cert (cédula 00109122788) tiene delegación SOLO para RNC `131256432`
en el panel de DGII. No se puede emitir ni aprobar para otros RNCs sin
agregar delegación.

---

## 8. Cómo arrancar la nueva sesión

1. **Setear el contexto al asistente:** *"Estamos en certificación DGII e-CF.
   Lee `HANDOFF_FASE_CERTIFICACION.md` que está en el root del proyecto."*

2. **Verificar que el último estado del repo está sincronizado:**
   ```bash
   git status
   git log --oneline -10
   ```

3. **Verificar que `samples/` tiene los xlsx correctos:**
   ```bash
   ls -la samples/*.xlsx
   ```

4. **Si DGII te entregó un nuevo set de Fase 4+**, súbelo a `samples/` y
   abre el portal para ver qué pide.

5. **Verificar resultados de Fase 3** — revisar `tools/fase3_results.json`
   o el portal DGII.

### Si DGII vuelve a resetear

```sql
-- Limpia BD
DELETE FROM factura_items WHERE factura_id IN (
  SELECT id FROM facturas WHERE e_ncf REGEXP '^E(31|32|33|34|41|43|44|45|46|47)0000000'
);
DELETE FROM facturas WHERE e_ncf REGEXP '^E(31|32|33|34|41|43|44|45|46|47)0000000';
```

Y reejecutar el runner correspondiente.

---

## 9. Lo que NO se ha terminado todavía

- **Refactor multi-emisor** — plan en `docs/multi-emisor-migration-plan.md`.
  Sólo hacer cuando termine la certificación.
- **Endpoint `POST /api/facturas/:id/reenviar`** — devuelve 501 stub.
- **Validación de firma digital del cert contra CA de DGII** —
  `IncomingXmlValidator` solo verifica RSA con la pubkey embebida.
- **PDF con QR + código de seguridad** — pendiente.
- **ANECF (Anulación)** y **ARECF (Aprobación Receptor)** — no construidos.
  Si Fase 4+ los pide, hay que agregarlos siguiendo el patrón de ACECF.

---

## 10. Archivo de contacto

- **DGII Ejecutivo asignado:** [poner aquí cuando lo sepas]
- **Casos abiertos con DGII:** ninguno por ahora (Fase 3 pasó OK)

---

## 11. Comandos útiles para retomar

### Re-ejecutar Fase 2 (si fuera necesario)

```powershell
& "C:\php\php.exe" -d "extension_dir=C:\php\ext" -d "extension=zip" `
  -d "extension=curl" -d "extension=openssl" -d "extension=mbstring" `
  -d "extension=fileinfo" "tools\send_fase2.php" `
  "samples\131256432-08052026161604.xlsx" `
  --api=https://gratex.net/api `
  --api-key=7a775f6fb0d5ccab15cf149d2c60f15c `
  --client-id=3511 --user-id=2 `
  --output=tools\fase2_results.json
```

### Consultar estado en DGII después de enviar

```powershell
& "C:\php\php.exe" -d "extension_dir=C:\php\ext" -d "extension=zip" `
  -d "extension=curl" -d "extension=openssl" -d "extension=mbstring" `
  -d "extension=fileinfo" "tools\check_fase2_status.php" `
  --api=https://gratex.net/api `
  --api-key=7a775f6fb0d5ccab15cf149d2c60f15c
```

### Descargar XML íntegro de una factura (para subir manual al portal)

```bash
curl -H "X-API-KEY: 7a775f6fb0d5ccab15cf149d2c60f15c" \
  "https://gratex.net/api/facturas/{id}/xml" -o factura.xml
```

### Smoke test rápido contra DGII (un caso)

Agregar `--case=E310000000001` al comando de send_fase2 o send_fase3.

### Dry-run (no envía nada, imprime payloads)

Agregar `--dry-run` al comando.
