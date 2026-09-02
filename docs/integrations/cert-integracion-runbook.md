# Runbook — Certificar un tenant tipo integración

Checklist operativo para llevar un tenant `integracion` desde el alta hasta producción,
corriendo **nosotros** las fases por API. Para el "por qué" de cada pieza (arquitectura,
tipos de tenant, contrato de integración) ver
[multi-tenant-onboarding.md](multi-tenant-onboarding.md) y
[../api/integracion.md](../api/integracion.md); esto es el paso a paso.

El equivalente para tenants app es [alta-tenant-runbook.md](alta-tenant-runbook.md).

> **Lo que NO aplica aquí:** no se crea base de datos, no hay usuario ni login, y el wizard
> `public/cert.html` no sirve (pide token de sesión). Los runners de `tools/` sí, con
> `--api-secret`.

> **Las dos cosas que hay que acordar con el cliente antes de empezar:** desde qué número
> de e-NCF arranca cada tipo (el runner consume secuencias reales suyas) y quién produce
> las Representaciones Impresas (Fase 8) — no hay generador para este tipo de tenant.

---

## Fase 0 — Reunir y verificar

| Qué | Verificación |
|---|---|
| RNC del cliente (9-11 dígitos) | `SELECT id, nombre FROM tenants WHERE rnc = '<rnc>';` en el master — vacío (`tenants.rnc` es UNIQUE) |
| Certificado `.p12` + contraseña | Obligatorio en integración: firma sus e-CF y sus acuses |
| Rangos e-NCF autorizados por DGII | Por tipo. Anotar el primer número libre de cada uno |
| `ONBOARD_TOKEN` | En el `.env` del server |
| Carpeta de certificados | `CERT_DIR` del `.env` (default `certificado_dgii`), con escritura para PHP. Está en `.gitignore`: **un deploy nuevo no la trae** |
| ¿Cliente con varias empresas? | Se agrupan con `--grupo`; requiere la migración master `db/master_migrations/008_add_tenant_grupo.sql` aplicada |
| Código en el server | `GET /api/integracion/estado` es reciente: si el server no lo tiene desplegado, `check_fase2_status.php --api-secret` y `--nota-wait-accepted` devuelven 404. Verificar con el smoke de la Fase 3 |

---

## Fase 1 — Preparar el certificado

Los `.p12` que entregan las CA vienen con algoritmos legacy (RC2-40 + 3DES + MAC SHA-1) que
PHP con OpenSSL 3 no puede leer. Se detecta y convierte **antes**, no después de que falle
la primera emisión.

```bash
openssl pkcs12 -in cert_cliente.p12 -nokeys -noout
```

Si responde `error:0308010C ... unsupported`, es legacy → convertir conservando la misma
contraseña (procedimiento y script en [alta-tenant-runbook.md](alta-tenant-runbook.md#fase-1--preparar-el-certificado)).

---

## Fase 2 — Alta del tenant

**Arranca siempre en `certecf`.**

```powershell
php tools/create_tenant.php --tipo=integracion `
  --nombre="Cliente A SRL" --rnc=131111111 --ambiente=certecf `
  --cert-path=certs/clienteA.p12 --cert-pass=<clave> `
  [--webhook-url=https://cliente.com/hooks/gratex]
```

Cliente con varias empresas: `--grupo=self` la primera (su `tenant_id` pasa a ser el
`grupo_id`), `--grupo=<id>` las demás. Cada una con **su** `.p12` y su propia certificación
DGII; comparten credencial.

Del resumen guardar: **`tenant_id`**, **`api_key`** y **`api_secret`**. El secret se
imprime **una sola vez** (el master guarda solo su sha256) — entregarlo por canal seguro.

---

## Fase 3 — Verificar antes de tocar DGII

```bash
mysql -e "SELECT id, rnc, tipo, ambiente, activo FROM gratex_master.tenants WHERE rnc='<rnc>';"
```

Tiene que decir `integracion` / `certecf` / `1`. **El ambiente lo fuerza el servidor desde
ahí**: no hay parámetro que lo cambie, y los runners rechazan `--ambiente` justamente para
que nadie crea que va a pruebas cuando el tenant está en `ecf`.

Smoke de credenciales (no toca DGII):

```bash
curl "https://gratex.net/api/integracion/empresas" -H "X-API-KEY: $KEY" -H "X-API-SECRET: $SECRET"
```

Smoke de firma (sí toca DGII, consume el primer e-NCF del rango de certificación): emitir
un E31 suelto con `POST /api/integracion/ecf`. Es lo que valida cert + firma + conexión
antes de gastar las 25 del set. Con el `track_id` que devuelva, verificar de paso que el
server tiene desplegada la consulta de estado:

```bash
curl "https://gratex.net/api/integracion/estado?e_ncf=<e_ncf>&track_id=<track_id>" -H "X-API-KEY: $KEY" -H "X-API-SECRET: $SECRET"
```

---

## Fase 4 — Portal DGII (lo hace el cliente)

1. Solicitar la certificación y pedir los rangos de e-NCF.
2. Registrar las URLs de WebServices — **las mismas para todos los tenants**; el sistema
   resuelve el dueño por el RNC del XML:

| Servicio | URL |
|---|---|
| Recepción | `https://gratex.net/api/ecf/recepcion` |
| Aprobación Comercial | `https://gratex.net/api/ecf/aprobacion-comercial` |
| Autenticación | `https://gratex.net/api/ecf/autenticacion` |

3. Descargar el set de pruebas (.xlsx) y pasárnoslo.

---

## Fase 5 — Set de pruebas

El e-NCF de cada caso sale del xlsx de DGII.

```powershell
php tools/send_fase2.php <set.xlsx> --api=https://gratex.net/api `
  --api-key=<key> --api-secret=<secret>
```

Estados reales (los `EN_PROCESO` se resuelven después):

```powershell
php tools/check_fase2_status.php --api=https://gratex.net/api `
  --api-key=<key> --api-secret=<secret> --input=tools/fase2_results.json
```

- Todos los XML firmados quedan en `tools/xml_integracion/<e_ncf>.xml`. **Sin DB no hay de
  dónde volver a bajarlos.**
- Los **E32 <250k** van por RFCE: el XML íntegro de esa carpeta es el que se sube a mano al
  portal.
- Si DGII rechaza **cualquier** comprobante, reinicia el set completo: hay que pasarlo
  limpio en una corrida.

---

## Fase 6 — Aprobaciones comerciales

Lee la hoja `ACEECF_Generadas` del mismo xlsx.

```powershell
php tools/send_fase3.php <set.xlsx> --api=https://gratex.net/api `
  --api-key=<key> --api-secret=<secret>
```

En la respuesta de DGII, `dgii_response.codigo` = `1`/`01` significa que **procesó** el
envío; `2`/`02` = no procesada (comprobante no encontrado, error técnico, o ambiente que no
coincide).

---

## Fase 7 — Emisión de los 10 tipos

Aquí no hay xlsx: el runner genera la data y **asigna el e-NCF**, así que hay que decirle
desde dónde numerar y quién es el emisor.

```powershell
php tools/send_fase4_simulation.php --api=https://gratex.net/api `
  --api-key=<key> --api-secret=<secret> `
  --encf-start=E31:7,E32:5,E33:1,E34:1,E41:1,E43:1,E44:1,E45:1,E46:1,E47:1 `
  --emisor-rnc=131111111 --emisor-razon-social="CLIENTE A SRL" `
  --emisor-direccion="Av. Winston Churchill #45" `
  --nota-wait-accepted=240 --nota-poll=15
```

`--encf-start` acepta también un número suelto (todos los tipos arrancan ahí).
`--nota-wait-accepted` espera a que los E31 estén `ACEPTADO` antes de mandar las notas
(E33/E34), que los referencian — consulta por `GET /integracion/estado`.

---

## Fase 8 — Representaciones Impresas

**No hay generador para este tipo de tenant.** La RI de un tenant app sale de
`GET /facturas/{id}/pdf`, que necesita la factura persistida; un tenant de integración no
tiene DB. Opciones, en orden de preferencia:

1. El cliente las genera desde su sistema con lo que devuelve la emisión: `codigo_seguridad`,
   `fecha_emision` (fecha de firma) y el QR. Requisitos mínimos de DGII en
   [../api/guia-cliente-integracion.md](../api/guia-cliente-integracion.md) §7.
2. Se arman a mano para el set de certificación.

Planificarlo en la Fase 0, no el día que el portal las pide.

---

## Fase 9 — Flujo entrante (automático)

No hay nada que correr: cuando otro emisor le factura, DGII entrega en
`/api/ecf/recepcion`, el sistema resuelve el tenant por `RNCComprador`, guarda en
`master.ecf_recibidos` con el ambiente del tenant y devuelve el ARECF firmado con su cert.
Se verifica con `GET /api/integracion/recibidos` (o el webhook, si lo configuró).

---

## Fase 10 — Promover a producción

Al aprobar DGII:

```bash
mysql -e "UPDATE gratex_master.tenants SET ambiente='ecf' WHERE id=<tenant_id>;"
```

Desde ahí sus bandejas muestran solo `ecf` y los datos de certificación quedan filtrados.

**Antes de cerrar:** avisarle al cliente el **próximo e-NCF libre de cada tipo**. Todo lo
que consumieron los runners son secuencias suyas; si su ERP arranca donde cree que quedó,
repite e-NCF y DGII los rechaza.

---

## Troubleshooting

| Síntoma | Causa | Arreglo |
|---|---|---|
| `401` en cualquier endpoint | `X-API-KEY`/`X-API-SECRET` mal, o secret regenerado | El master guarda solo el sha256: si se perdió, se regenera |
| `403 Endpoint solo para tenants tipo integracion` | La credencial es de un tenant `app` | Verificar `tenants.tipo` |
| `422 emisor.rnc (...) no corresponde a la credencial` | El RNC del payload no es el del tenant ni una empresa de su grupo | Corregir el RNC, o agrupar con `--grupo` (migración 008) |
| `422 Falta e_ncf` | El endpoint no dispensa secuencias | Mandarlo en el body; en fase 4, `--encf-start` |
| `ERROR: --ambiente no aplica en modo integracion` | Se intentó forzar el ambiente | Es intencional: verificar `tenants.ambiente` |
| `Unable to read certificate ... unsupported` | `.p12` legacy (Fase 1 saltada) | Convertirlo y reemplazarlo en el server |
| `Unable to read certificate ... mac verify failure` | Contraseña incorrecta o `cert_pass_encrypted` NULL | Re-cifrar con `public/encrypt_credential.php` |
| `La firma del XML no es válida` (y la firma sí es válida) | Retornos de carro (`\r`) en textos del payload | El backend normaliza y bloquea, pero conviene normalizar en el origen |
| `404` en `/integracion/estado` | No hay respaldo de ese e-NCF, o es un RFCE sin `track_id` | Mandar `track_id`, o `codigo_seguridad` si es E32 <250k |
| `NO_ENCONTRADO` al consultar estado | `track_id` de otro ambiente, o el envío nunca llegó | Confirmar el ambiente del tenant y reintentar la emisión |
| `RNC comprador (X) no registrado` al recibir | El RNC del tenant no coincide con el `RNCComprador` del XML, o `activo=0` | Corregir `tenants.rnc` / reactivar |

---

## Referencia rápida

| Dato | Dónde vive |
|---|---|
| Registro del tenant | `tenants` (master) |
| Credenciales | `tenants.api_key` + hash sha256 del secret |
| e-CF emitidos (respaldo) | `master.ecf_integracion_backup` (sin estado: la fuente de verdad es el cliente) |
| e-CF recibidos | `master.ecf_recibidos` (por `tenant_id` + ambiente) |
| Aprobaciones recibidas | `master.aprobaciones_comerciales` |
| Certificado | `<CERT_DIR>/<rnc>/cert.p12`, pass cifrada AES-256-GCM |
| XML firmados de las corridas | `tools/xml_integracion/<e_ncf>.xml` (local, en `.gitignore`) |
| Reportes de las fases | `tools/fase2_results.json`, `fase2_estados.json`, `fase3_results.json`, `fase4_results.json` |
| Secuencias e-NCF | **Las lleva el cliente** — no hay `ncf_sequences` para este tipo de tenant |
