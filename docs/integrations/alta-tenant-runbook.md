# Runbook — Alta de un tenant nuevo (tipo app)

Checklist operativo, en el orden en que hay que ejecutarlo. Para el "por qué" de
cada pieza (arquitectura, tipos de tenant, integración, tokens) ver
[multi-tenant-onboarding.md](multi-tenant-onboarding.md); esto es el paso a paso.

> **El orden importa.** El certificado se prepara ANTES del onboarding, y los datos
> del emisor se completan DESPUÉS. Saltarse el orden es lo que produce los tres
> errores más comunes (ver [Troubleshooting](#troubleshooting)).

---

## Fase 0 — Reunir y verificar

Antes de tocar nada, ten a mano y confirmado:

| Qué | Verificación |
|---|---|
| RNC del cliente (9-11 dígitos) | `SELECT id, nombre FROM tenants WHERE rnc = '<rnc>';` en el master — tiene que salir vacío (`tenants.rnc` es UNIQUE) |
| Certificado `.p12` + su contraseña | La contraseña la vas a necesitar en la Fase 1 y en la 3 |
| Ambiente de arranque | `certecf` si va a certificar · `testecf` si es demo · `ecf` si ya está certificado |
| Email y username del admin | `SELECT id FROM users WHERE email = '<email>' OR username = '<user>';` — **ambos son UNIQUE globales**, no pueden repetirse con los de otro tenant |
| Carpetas en el server | La de certificados (`CERT_DIR` del `.env`, default `certificado_dgii`) y `logos/`, ambas con escritura para el usuario de PHP |
| Tokens del `.env` | `ONBOARD_TOKEN`, `CREATE_USER_TOKEN`. `UPLOAD_LOGO_TOKEN` sigue siendo const dentro de `public/upload_logo.php` |

Las dos carpetas están en `.gitignore`, así que **un deploy nuevo no las trae** —
créalas a mano en la raíz del API antes de empezar.

---

## Fase 1 — Preparar el certificado

Los `.p12` que entrega la CA vienen cifrados con algoritmos legacy (RC2-40 + 3DES +
MAC SHA-1) que PHP con OpenSSL 3 **no puede leer**. Hay que convertirlos antes, no
después de que falle la primera factura.

**Detectar si es legacy** (no necesita la contraseña):

```bash
openssl pkcs12 -in cert_cliente.p12 -nokeys -noout
```

Si responde `error:0308010C ... unsupported`, es legacy. Confirmación alterna: buscar
los OIDs `1.2.840.113549.1.12.1.6` (RC2-40) y `...1.12.1.3` (3DES) en el binario.

**Convertir**, conservando la misma contraseña:

```bash
powershell -ExecutionPolicy Bypass -File convertir_cert.ps1
```

El script (`~/Downloads/convertir_cert.ps1`, o su gemelo `.sh` para Git Bash) pide la
clave una vez, re-exporta con AES-256, verifica el resultado con OpenSSL y con
`openssl_pkcs12_read` de PHP, y borra el PEM intermedio. Manualmente son dos pasos:

```bash
openssl pkcs12 -legacy -in viejo.p12 -nodes -out temp.pem
```

```bash
openssl pkcs12 -export -in temp.pem -out nuevo.p12
```

Borra `temp.pem` en cuanto termines: lleva la llave privada sin cifrar. La llave y el
certificado no cambian, solo el cifrado del contenedor, así que la firma que ve DGII
es idéntica.

> En CMD, `bash` resuelve al de WSL, no al de Git. Usa la versión PowerShell o llama
> `"C:\Program Files\Git\bin\bash.exe" convertir_cert.sh`.

---

## Fase 2 — Crear la base de datos

cPanel → MySQL Databases:

1. Crear la DB (ej. `cuenta_cliente2db`).
2. Crear usuario MySQL + contraseña.
3. Darle **todos los privilegios** sobre esa DB.
4. Verificar que el usuario admin del onboarding (`ADMIN_DB_USER` / `MASTER_DB_USER`
   del `.env`) también pueda escribirla — es con el que se aplica el schema.

---

## Fase 3 — Onboarding

`https://<server>/api/public/onboard.html` → tipo **App**.

| Campo | Qué poner |
|---|---|
| token | `ONBOARD_TOKEN` |
| nombre, rnc | Identidad del tenant |
| ambiente | El decidido en Fase 0 |
| razón social, dirección | Los reales del cliente — van a `emisor_config` y al XML |
| db-name, db-user, db-pass | Los de la Fase 2 |
| "La DB ya existe" | **Marcado** (en hosting compartido PHP no puede crear DBs) |
| cert | El `.p12` **convertido** en Fase 1, subido por el campo de archivo |
| cert-pass | La contraseña del `.p12` |
| logo | Opcional |
| admin-email, username, nombre, password | Los 4 o ninguno; email y username únicos globales |

**Guarda del resumen que imprime:**

- `tenant_id`
- `client_id certificacion (RNC 131880681)` — lo pide el wizard de certificación
- `API KEY` + `API SECRET` (el secret se muestra una sola vez)

---

## Fase 4 — Completar los datos del emisor

El onboarding solo llena `rnc`, `razon_social`, `nombre_comercial` y `direccion`. El
teléfono y el correo quedan NULL, y la Representación Impresa cae a los valores de
Gratex. En la DB del tenant:

```bash
UPDATE emisor_config SET direccion = '<dirección real>', telefono = '<tel>', correo = '<correo>' WHERE id = 1;
```

Esto no es cosmético: `ECFXmlBuilder` toma `DireccionEmisor` de aquí, así que sale en
el XML que va a DGII.

**Logo:** súbelo por `public/upload_logo.php` (su token es const dentro del archivo,
hay que editarlo en el server). Sin logo propio, el PDF usa el de Gratex.

---

## Fase 5 — Verificar

1. **Login:**

```bash
curl -X POST https://<server>/api/auth/login -H "Content-Type: application/json" -d '{"emailOrUsername":"<email>","password":"<pass>"}'
```

2. **Aislamiento de datos:** `GET /api/clients` con el token en `X-API-KEY` debe
   devolver solo los 2 clientes de prueba DGII de SU DB.
3. **Emisión:** crear una factura de prueba y confirmar que el PDF trae los datos del
   cliente (no los de Gratex) y que DGII la acepta.

---

## Fase 6 — Certificación DGII

Solo si arrancó en `certecf`.

1. Portal DGII: solicitar certificación, bajar el set de pruebas (.xlsx) y registrar
   las URLs de WebServices — son **las mismas para todos los tenants**
   (`/api/ecf/recepcion`, `/api/ecf/aprobacion-comercial`, `/api/ecf/autenticacion`);
   el sistema resuelve el dueño por el RNC del XML.
2. `public/cert.html`: `tenant_id`, token del login, `client_id` del resumen y
   `CERT_RUN_TOKEN`.
3. Correr fases 2/3/4, bajar las Representaciones Impresas y subirlas al portal.

---

## Fase 7 — Promover a producción

Al aprobar DGII:

```bash
UPDATE tenants SET ambiente = 'ecf' WHERE id = <tenant_id>;
```

Desde ahí sus listados y su emisión salen en `ecf`, sin afectar a los demás tenants.

---

## Troubleshooting

| Síntoma | Causa | Arreglo |
|---|---|---|
| `No se pudo crear directorio de certificado` | La carpeta de certificados no existe o PHP no puede escribir en la raíz del API | Crearla a mano; si tiene otro nombre, `CERT_DIR=<nombre>` en el `.env` |
| La salida se corta en "Creando usuario admin" | El email o el username ya existen en otro tenant (UNIQUE globales) | Usar otros; el tenant ya quedó creado, completar con `public/create_user.php` |
| `Ya existe un tenant con RNC ...` | Re-corrida del onboarding sobre un RNC ya registrado | No re-correr: agregar usuarios con `create_user.php` |
| `Unable to read certificate ... unsupported` | `.p12` legacy (Fase 1 saltada) | Convertir el certificado y reemplazarlo en el server |
| `Unable to read certificate ... mac verify failure` | Contraseña incorrecta o `cert_pass_encrypted` NULL | Re-cifrarla con `public/encrypt_credential.php` y actualizar `tenants` |
| El PDF muestra datos de Gratex | `emisor_config` incompleto y/o tenant sin logo (Fase 4 saltada) | El `UPDATE` de la Fase 4 + subir el logo |
| Clientes de prueba DGII duplicados | El schema se aplicó dos veces sobre la misma DB | `DELETE FROM clients WHERE rnc IN ('131880681','533445861');` y dejar una corrida limpia |

---

## Referencia rápida

| Dato | Dónde vive |
|---|---|
| Registro del tenant | `tenants` (master) |
| Usuarios y tokens de sesión | `users` / `api_tokens` (master) |
| Datos del emisor | `emisor_config` (DB del tenant) |
| Certificado | `<CERT_DIR>/<rnc>/cert.p12` + `tenants.cert_path` |
| Logo | `logos/<tenant_id>.<ext>` + `tenants.logo_path` |
| Secuencias e-NCF | `ncf_sequences` (DB del tenant), por ambiente |
