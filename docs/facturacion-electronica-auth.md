# Facturacion Electronica - Autenticacion DGII

Este modulo implementa el primer flujo para obtener un token DGII:

1. Obtener la semilla XML desde DGII.
2. Firmar la semilla con el certificado digital `.p12` o `.pfx`.
3. Enviar la semilla firmada a DGII como `multipart/form-data` en el campo `xml`.
4. Retornar `token`, `expira` y `expedido`.

La firma XML sigue la estructura de `platinum-place/php-dgii-xml-signer`, adaptada localmente para este proyecto porque actualmente no usa Composer.

## Configuracion

El proyecto lee automaticamente el archivo `.env` en la raiz. Ya existe una plantilla en `.env.example` y un `.env` local ignorado por git:

```env
DGII_ECF_ENVIRONMENT=testecf
DGII_ECF_CERT_PATH=certificados/20260501-2020077-KQBYARLQB.p12
OPENSSL_CONF=config/openssl-legacy.cnf
OPENSSL_MODULES=C:\php\extras\ssl
DGII_ECF_CERT_PASSWORD=CAMBIAR_PASSWORD_DEL_CERTIFICADO
```

Variables soportadas:

- `DGII_ECF_ENVIRONMENT`: `testecf`, `certecf` o `ecf`. Por defecto usa `testecf`.
- `DGII_ECF_BASE_URL`: por defecto `https://ecf.dgii.gov.do`.
- `DGII_ECF_CERT_PATH`: ruta del certificado. Por defecto usa `certificados/20260501-2020077-KQBYARLQB.p12`.
- `DGII_ECF_CERT_PASSWORD`: password del certificado.
- `DGII_ECF_TIMEOUT`: timeout HTTP en segundos. Por defecto `30`.
- `OPENSSL_CONF`: configuracion local para activar el provider legacy necesario para este `.p12`.
- `OPENSSL_MODULES`: carpeta donde esta `legacy.dll` en la instalacion local de PHP/OpenSSL.

Tambien puedes enviar esos valores en el body JSON de los endpoints `POST`.

## Endpoints

Estos endpoints locales requieren el token interno del API. Para evitar confundirlo con el token DGII, se recomienda llamar nuestra API con:

```http
X-API-KEY: {api_token_interno}
```

El token que retorna DGII no se debe enviar como `X-API-KEY` ni `X-API-TOKEN`. Ese token se usa para llamadas servidor-a-servidor hacia DGII con:

```http
Authorization: Bearer {token_dgii}
```

### GET /api/facturacion-electronica/autenticacion/semilla

Retorna el XML de semilla directamente desde DGII.

```http
GET /api/facturacion-electronica/autenticacion/semilla?ambiente=testecf
X-API-KEY: {api_token}
```

### POST /api/facturacion-electronica/autenticacion/token

Ejecuta el flujo completo y retorna el token DGII.

```json
{
  "ambiente": "testecf",
  "certificate_password": "password-del-certificado"
}
```

Respuesta:

```json
{
  "status": true,
  "data": {
    "token": "string",
    "expira": "2026-05-05T16:53:38.239Z",
    "expedido": "2026-05-05T16:53:38.239Z",
    "ambiente": "testecf",
    "endpoint": "https://ecf.dgii.gov.do/testecf/autenticacion/api/autenticacion/validarsemilla",
    "semilla_fecha": "2026-05-05T12:50:25.1304197-04:00"
  }
}
```

### POST /api/facturacion-electronica/autenticacion/validar-semilla

Recibe una semilla sin firmar (`semilla_xml`) y la firma antes de validarla, o recibe una semilla ya firmada (`signed_xml`) y solo la envia a DGII.

```json
{
  "ambiente": "testecf",
  "certificate_password": "password-del-certificado",
  "semilla_xml": "<?xml version=\"1.0\" encoding=\"utf-8\"?><SemillaModel>...</SemillaModel>"
}
```

## Uso del token DGII

Cuando agreguemos endpoints como recepcion o consulta de e-CF, el backend debe llamar a DGII con Bearer token. El servicio ya incluye `consultarEndpointAutenticado()` para ese caso:

```php
$dgii = new DgiiAuthService();
$response = $dgii->consultarEndpointAutenticado(
    'GET',
    'consultaresultado/api/consultas/estado',
    $tokenDgii
);
```

Ese metodo agrega `Authorization: Bearer {token_dgii}` y descarta headers internos como `X-API-KEY` o `X-API-TOKEN` para no enviarlos accidentalmente a DGII.
