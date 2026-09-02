# Pasos de certificación DGII e-CF

Scripts y archivos usados para pasar cada fase de certificación de DGII para
facturación electrónica (e-CF). Recopilados aquí para **automatizar el proceso
y ofrecer certificación-como-servicio** a otras empresas (ver
[../docs/architecture.md](../docs/architecture.md) y
[../docs/integrations/multi-tenant-onboarding.md](../docs/integrations/multi-tenant-onboarding.md)).

> Estos son **copias** de los runners en `tools/`. Los originales siguen ahí.
> Cada carpeta de fase es autocontenida (el runner incluye su parser
> `Fase2XlsxReader.php` para que `require __DIR__` resuelva).

## Las fases

DGII certifica en pasos. Algunos son manuales (registro, subir XML al portal),
otros se automatizan vía API. Lo que cubrimos aquí:

| Fase | Nombre | Automatizado | Carpeta |
|------|--------|--------------|---------|
| 2 | Set de pruebas (lee xlsx DGII, emite cada caso) | Sí | `fase2_set_pruebas/` |
| 3 | Aprobación comercial (ACECF) | Sí | `fase3_aprobacion_comercial/` |
| 4 | Emisión real (los 10 tipos e-CF) | Sí | `fase4_emision/` |

Parámetros comunes de todos los runners:
- `--api=https://gratex.net/api` — base del API
- `--api-key=<key>` — header `X-API-KEY`
- `--client-id`, `--user-id` — emisor/usuario
- `--dry-run` — imprime payloads sin enviar

Requiere PHP CLI con extensiones: `zip`, `curl`, `openssl`, `mbstring`, `fileinfo`.

---

## Fase 2 — Set de pruebas

DGII entrega un xlsx con casos de prueba (uno por hoja/tipo). El runner lo lee
y emite cada caso vía `POST /api/facturas`. Los E32 <250k se enrutan solos a
RecepcionFC.

**Archivos:**
- `send_fase2.php` — runner principal (lee xlsx → emite)
- `Fase2XlsxReader.php` — parser de xlsx sin dependencias (ZipArchive + DOM)
- `check_fase2_status.php` — consulta el estado DGII de cada e-CF emitido
- `comando_fase2.txt` — comando exacto usado + log de rechazos de referencia
- `ejemplo_resultado.json` — salida real de una corrida (25 casos) como referencia

**Input:** el xlsx del set de pruebas de DGII (ej. `samples/131256432-*.xlsx`).

**Correr:**
```
php send_fase2.php <ruta-al.xlsx> \
    --api=https://gratex.net/api --api-key=<key> \
    --client-id=3511 --user-id=2 --output=fase2_results.json
# luego verificar estados:
php check_fase2_status.php --api=https://gratex.net/api --api-key=<key> \
    --input=fase2_results.json --output=fase2_estados.json
```

> Ojo: si **cualquier** comprobante es rechazado, DGII reinicia el set completo.
> Todos deben pasar en una corrida limpia.

---

## Fase 3 — Aprobación comercial (ACECF)

Lee la hoja `ACEECF_Generadas` del xlsx y envía cada Aprobación Comercial a
`POST /api/aprobaciones-comerciales`. El sistema arma el ACECF XML, lo firma
con nuestro cert (como comprador) y lo POSTea a DGII.

**Archivos:**
- `send_fase3.php` — runner
- `Fase2XlsxReader.php` — mismo parser de xlsx
- `ejemplo_resultado.json` — salida real de una corrida como referencia

**Correr:**
```
php send_fase3.php <ruta-al.xlsx> \
    --api=https://gratex.net/api --api-key=<key> \
    --output=fase3_results.json
```

---

## Fase 4 — Emisión real (10 tipos)

Genera data simulada (sin xlsx) y emite los 10 tipos e-CF: E31, E32 (≥ y <250k
RFCE), E33, E34, E41, E43, E44, E45, E46, E47. Las notas (E33/E34) referencian
E31 emitidas en la misma corrida.

**Archivos:**
- `send_fase4_simulation.php` — runner (data generada, distribución configurable)
- `ejemplo_resultado.json` — salida real de una corrida (25/25 OK, los 10 tipos) como referencia

**Correr:**
```
php send_fase4_simulation.php \
    --api=https://gratex.net/api --api-key=<key> \
    --client-id=3511 --user-id=2 --output=fase4_results.json
```
Soporta `--counts=E31:2,E34:2,...` y flags de espera para notas
(`--nota-wait-accepted`, `--nota-poll`). Ver docblock del script.

Completada 2026-05-27.

---

## Hacia la automatización (servicio multi-empresa)

Para ofrecer esto a otras empresas, cada una necesita: su propio certificado
`.p12`, su RNC y sus secuencias autorizadas por DGII. Los runners ya parametrizan
`--api-key`, `--client-id`, `--user-id`; falta scope por emisor (ver el plan
multi-emisor). Pasos manuales pendientes de documentar/automatizar:

- [ ] Fase 1 — registro / configuración inicial en portal DGII
- [ ] Descarga automática del xlsx del set de pruebas desde DGII
- [ ] Subida manual de XML íntegros al portal (RFCE / pasos manuales)
- [ ] Onboarding de emisor (cert + secuencias) — ver plan multi-emisor

Se irán agregando más pasos y scripts a esta carpeta.
