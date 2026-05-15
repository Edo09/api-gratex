# Arquitectura del Sistema Gratex API

## Diagrama general

```
┌─────────────────────────────────────────────────────────────┐
│                        CLIENTES DE LA API                    │
│          (Apps, ERP, frontend, scripts como send_fase2)      │
└──────────────────────────────┬──────────────────────────────┘
                               │  HTTP + X-API-KEY
                               ▼
┌─────────────────────────────────────────────────────────────┐
│                      GRATEX API (PHP)                        │
│                                                             │
│  Auth ──► Middleware valida token                           │
│                                                             │
│  Endpoints:                                                 │
│  POST /api/facturas           ← Emitir factura electrónica  │
│  GET  /api/facturas/{id}/xml  ← Descargar XML firmado       │
│  GET  /api/facturas/{id}/estado ← Consultar estado DGII     │
│  GET  /api/clients            ← Gestión de clientes         │
│  GET  /api/ncf/sequences      ← Secuencias de eNCF          │
└────────────┬───────────────────────────┬────────────────────┘
             │                           │
             ▼                           ▼
┌────────────────────────┐   ┌───────────────────────────────┐
│      BASE DE DATOS     │   │     DGII (Certificación/Prod)  │
│      (MySQL)           │   │                               │
│                        │   │  POST .../Recepcion/api/      │
│  emisor_config (x1)    │   │       FacturasElectronicas    │
│  ├─ RNC del emisor     │   │                               │
│  └─ datos fiscales     │   │  POST fc.dgii.gov.do/...      │
│                        │   │       RecepcionFC/api/        │
│  ncf_sequences         │   │       recepcion/ecf  (RFCE)   │
│  ├─ E31: 000000001     │   │                               │
│  ├─ E32: 000000015     │   │  GET .../ConsultaResultado/   │
│  └─ E33...E47          │   │       api/Consultas/Estado    │
│                        │   └───────────────────────────────┘
│  clients               │
│  facturas + items      │
│  xml_firmado (guardado)│
└────────────────────────┘
```

---

## Flujo de una factura electrónica

```
1. App llama POST /api/facturas
         │
         ▼
2. API reserva el próximo eNCF  →  E310000000042
         │
         ▼
3. ECFXmlBuilder genera el XML del eCF
         │
         ▼
4. DgiiXmlSigner firma con el certificado .p12
         │
         ▼
5. DgiiAuthService obtiene token de la DGII
         │
         ├──► E32 < 250k?
         │         │
         │         ▼
         │    RFCEXmlBuilder → firma RFCE → POST a RecepcionFC
         │    (resumen automático; el ECF hay que subirlo manual al portal)
         │
         └──► Todos los demás:
                   │
                   ▼
              POST XML firmado a DGII → recibe trackId
         │
         ▼
6. Guarda en BD: xml_firmado, track_id, estado_dgii, codigo_seguridad
         │
         ▼
7. Retorna al cliente: { factura_id, e_ncf, track_id, estado }
```

---

## Estructura de carpetas

```
api-gratex/
├── src/
│   ├── Controllers/         # Un controller por recurso
│   │   ├── facturaController.php
│   │   ├── clientController.php
│   │   ├── ncfController.php
│   │   ├── ecfRecepcionController.php
│   │   ├── ecfAprobacionComercialController.php
│   │   ├── ecfAutenticacionController.php
│   │   ├── facturacionElectronicaController.php
│   │   └── ...
│   ├── Models/
│   │   ├── facturaModel.php
│   │   ├── clientModel.php
│   │   ├── ncfModel.php
│   │   ├── EmisorConfigModel.php   ← config fiscal del emisor (id=1)
│   │   └── ...
│   ├── Middleware/
│   │   └── AuthMiddleware.php      ← valida X-API-KEY / Bearer
│   ├── Utils/
│   │   └── FacturacionElectronica/
│   │       ├── ECFXmlBuilder.php       ← construye XML e-CF
│   │       ├── RFCEXmlBuilder.php      ← construye XML RFCE
│   │       ├── ECFEmissionService.php  ← orquesta el flujo completo
│   │       ├── DgiiXmlSigner.php       ← firma con .p12
│   │       ├── DgiiAuthService.php     ← obtiene token DGII
│   │       └── DgiiReceptionService.php← POST a DGII
│   ├── Database.php         ← conexión PDO singleton
│   └── Router.php           ← enrutador principal
├── db/
│   ├── database.sql
│   └── migrations/
├── certificados/            ← archivo .p12 del emisor
├── docs/                    ← documentación
├── samples/                 ← xlsx de casos de prueba DGII
├── tools/                   ← scripts CLI (send_fase2.php, etc.)
└── .env                     ← variables de entorno
```

---

## Tablas principales

```
AUTENTICACION:
  users          (id, name, email, username, password, role)
  api_tokens     (id, user_id, token_hash, is_active)

CONFIGURACION FISCAL:
  emisor_config  (id=1, rnc, razon_social, direccion, municipio, provincia)
  ncf_sequences  (id, type[E31..E47], current_value)

COMERCIAL:
  clients        (id, email, client_name, company_name, rnc, razon_social, direccion)
  cotizaciones   (id, code, date, client_id, total)
  cotizacion_items

FACTURACION ELECTRONICA:
  facturas       (id, no_factura, date, client_id, total, tipo_ecf, e_ncf,
                  track_id, estado_dgii, codigo_seguridad, xml_firmado,
                  rfce_xml, rfce_track_id, rfce_estado, ambiente_dgii)
  factura_items  (id, factura_id, description, amount, quantity,
                  indicador_facturacion, itbis_amount)

RECEPCION (rol receptor):
  ecf_recibidos           (id, track_id, tipo_ecf, e_ncf, rnc_emisor, estado, xml_firmado)
  aprobaciones_comerciales(id, e_ncf, rnc_emisor, estado_comercial, xml_firmado)
  auth_seeds              (id, seed_value, expira_at, consumida_at)
  auth_tokens_emitidos    (id, token, rnc_consumidor, expira_at)
```

---

## Estado actual: single-tenant

El sistema hoy soporta **una sola empresa emisora** por instalación.

| Componente | Hoy | Para multi-empresa |
|---|---|---|
| `emisor_config` | 1 fila fija (id=1) | 1 fila por empresa |
| `ncf_sequences` | global | por empresa |
| `clients` | global | filtrado por empresa |
| `facturas` | global | filtrado por empresa |
| Certificado `.p12` | 1 solo | 1 por empresa |
| Usuarios | sin empresa asignada | con `company_id` |

El plan de migración a multi-emisor está documentado en `docs/multi-emisor-migration-plan.md`.
Se recomienda ejecutarlo una vez que Gratex esté certificado y en producción estable.

---

## Variables de entorno clave

```
DGII_ECF_ENVIRONMENT=certecf          # testecf | certecf | ecf
DGII_ECF_CERT_PATH=certificados/...p12
DGII_ECF_CERT_PASSWORD=...
DB_HOST / DB_NAME / DB_USER / DB_PASS
```

---

## Ambientes DGII

| Valor | Descripción |
|---|---|
| `testecf` | Pruebas libres |
| `certecf` | Certificación oficial (fase actual) |
| `ecf` | Producción |
