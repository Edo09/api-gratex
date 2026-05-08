# Plan de migración a multi-emisor

Documento de diseño para extender el sistema actual (un solo emisor) a un modelo
multi-tenant donde Gratex puede emitir e-CF en nombre de múltiples empresas
clientes con sus propios RNC, certificados digitales y secuencias autorizadas
por DGII.

> **Estado:** plan; aún no se debe ejecutar. Pensado para tener una hoja de ruta
> clara cuando se decida iniciar el refactor.

---

## 1. Estado actual

El sistema asume un único emisor:

- `emisor_config` tiene un solo registro con `id = 1`.
- `EmisorConfigModel::get()` siempre carga `WHERE id = 1`.
- Un único `.p12` referenciado por `DGII_ECF_CERT_PATH` en `.env`, con su
  contraseña en `DGII_ECF_CERT_PASSWORD`.
- `ncf_sequences` tiene un contador por tipo de e-CF, compartido por todas las
  facturas del sistema.
- `facturas`, `factura_items`, `clients` no tienen referencia a emisor.
- Los endpoints de recepción / aprobación / autenticación responden para un
  único RNC (el del `emisor_config`).

## 2. Estado objetivo

- N empresas onboarded en una tabla `emisores`, cada una con:
  - Datos fiscales propios (RNC, razón social, dirección, etc.).
  - Su propio certificado `.p12` y contraseña almacenados de forma segura.
  - Su propia secuencia autorizada por DGII (rango y fecha de vencimiento).
- Cada factura, cliente, secuencia y aprobación asociada a un `emisor_id`.
- Cada usuario del sistema vinculado a uno o más emisores con permisos.
- Endpoints de recepción rutean entrantes por `RNCComprador` al emisor
  correspondiente.
- API expone explícitamente el contexto de emisor (header o sesión).

## 3. Cambios de modelo de datos

Migración SQL nueva (`003_multi_emisor.sql`), 100 % aditiva — los datos
existentes quedan asignados a `emisor_id = 1`.

### 3.1. Renombrar y extender `emisor_config` → `emisores`

```sql
RENAME TABLE emisor_config TO emisores;

ALTER TABLE emisores
  ADD COLUMN cert_path VARCHAR(255) NULL
    COMMENT 'Ruta del .p12 de este emisor (relativa a project root)',
  ADD COLUMN cert_password_encrypted VARBINARY(512) NULL
    COMMENT 'Contraseña del .p12 cifrada con AES-256-GCM y la master key',
  ADD COLUMN environment VARCHAR(20) NOT NULL DEFAULT 'testecf'
    COMMENT 'testecf | certecf | ecf — ambiente activo de este emisor',
  ADD COLUMN activo TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ADD UNIQUE KEY uk_rnc (rnc);
```

Nota: sustituir el `id = 1` hardcoded — la PK sigue siendo `id`, pero ya no se
asume valor fijo.

### 3.2. Agregar `emisor_id` a tablas dependientes

```sql
ALTER TABLE facturas
  ADD COLUMN emisor_id INT(11) NOT NULL DEFAULT 1 AFTER id,
  ADD INDEX idx_emisor (emisor_id),
  ADD CONSTRAINT fk_facturas_emisor
      FOREIGN KEY (emisor_id) REFERENCES emisores(id);

ALTER TABLE clients
  ADD COLUMN emisor_id INT(11) NOT NULL DEFAULT 1 AFTER id,
  ADD INDEX idx_emisor (emisor_id),
  ADD CONSTRAINT fk_clients_emisor
      FOREIGN KEY (emisor_id) REFERENCES emisores(id);

ALTER TABLE cotizaciones
  ADD COLUMN emisor_id INT(11) NOT NULL DEFAULT 1 AFTER id,
  ADD INDEX idx_emisor (emisor_id);
```

### 3.3. Secuencias por emisor

```sql
ALTER TABLE ncf_sequences
  ADD COLUMN emisor_id INT(11) NOT NULL DEFAULT 1 AFTER id,
  ADD COLUMN max_value BIGINT NULL
    COMMENT 'Tope autorizado por DGII para esta secuencia',
  ADD COLUMN fecha_vencimiento DATE NULL,
  DROP INDEX type, -- si existe
  ADD UNIQUE KEY uk_emisor_type (emisor_id, type);
```

`dispenseNextECF()` debe filtrar por `(emisor_id, type)`.

### 3.4. Vínculo usuario ↔ emisor

```sql
CREATE TABLE user_emisores (
  user_id INT NOT NULL,
  emisor_id INT NOT NULL,
  rol ENUM('admin','emisor','consulta') NOT NULL DEFAULT 'emisor',
  PRIMARY KEY (user_id, emisor_id),
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (emisor_id) REFERENCES emisores(id)
);
```

Si los users existentes son globales, durante la migración insertar fila para
cada combinación user × emisor que aplique.

### 3.5. Recepción y aprobación comercial

Las tablas `ecf_recibidos` y `aprobaciones_comerciales` deben saber para qué
emisor llegó el documento:

```sql
ALTER TABLE ecf_recibidos
  ADD COLUMN emisor_id INT NOT NULL DEFAULT 1
    COMMENT 'A qué emisor (interno) iba dirigido este e-CF',
  ADD INDEX idx_emisor (emisor_id);

ALTER TABLE aprobaciones_comerciales
  ADD COLUMN emisor_id INT NOT NULL DEFAULT 1
    COMMENT 'Emisor que originalmente emitió el e-CF que se está aprobando',
  ADD INDEX idx_emisor (emisor_id);
```

## 4. Almacenamiento seguro de credenciales

Cada emisor tiene un `.p12` con su propia contraseña. **No** se debe guardar
la contraseña en claro.

### Estrategia: sobre-cifrado con master key

1. Generar una **master key** AES-256 una sola vez. Almacenarla en `.env`
   como `ECF_CERT_MASTER_KEY` (32 bytes hex). Esta llave solo existe en
   producción y en el gestor de secretos.
2. Al crear un emisor, cifrar la contraseña del `.p12` con
   `openssl_encrypt($pass, 'aes-256-gcm', $masterKey, ..., $iv, $tag)` y
   guardar `iv || tag || ciphertext` en `cert_password_encrypted`.
3. Al emitir, descifrar al vuelo en memoria.

Alternativas si se quiere más robustez:
- Vault / AWS KMS / Azure Key Vault para la master key.
- HSM por emisor (caro, raramente justificado en este volumen).

Los archivos `.p12` físicos se guardan fuera del web root (ej.
`<project>/certificados/<rnc>/cert.p12`) con permisos 0600 y dueño = usuario PHP.

## 5. Cambios de código por capa

### 5.1. Modelos

- `EmisorConfigModel` → `EmisorModel`:
  - `get(int $emisorId): ?array`
  - `getByRnc(string $rnc): ?array`
  - `listActivos(): array`
  - `create(array $datos): int`, `update(int $id, array $datos)`
- `ncfModel::dispenseNextECF(int $emisorId, string $type)`
- `facturaModel::getFacturasPaginated(int $emisorId, ...)` — todas las
  consultas deben filtrar por emisor.
- `clientModel::getClients(int $emisorId, ...)`
- `ecfRecibidoModel::save([... 'emisor_id' => $id ...])`

### 5.2. Servicios

- `ECFEmissionService::emitir(int $emisorId, array $payload)`:
  carga el emisor por id, su cert, su contraseña descifrada, dispensa el
  próximo NCF de su secuencia.
- `DgiiAuthService` y `DgiiReceptionService` ya son por-llamada — solo
  reciben los datos del cert/RNC en `options`. Mínimos cambios.

### 5.3. Controllers

Estrategia: leer `emisor_id` del contexto del request. Tres opciones:

| Opción | Pros | Contras |
|---|---|---|
| Header `X-Emisor-Id` | Simple, explícito | Cliente debe enviarlo siempre |
| Sub-ruta `/api/emisores/{id}/facturas` | RESTful, autodocumentado | Toca todos los routes |
| Sesión del usuario (un solo emisor activo) | UX simple | No sirve si un user opera múltiples |

**Recomendado:** combinación de:
- Header `X-Emisor-Id` o ruta `/api/emisores/{id}/facturas`.
- Middleware verifica que el `user_id` autenticado tenga acceso a ese
  `emisor_id` vía `user_emisores`.

### 5.4. Recepción / aprobación

Los endpoints públicos (`/api/ecf/recepcion`, etc.) no reciben `emisor_id`
como input. Lo derivan del `RNCComprador` (recepción) o `RNCEmisor`
(aprobación), buscándolo en `emisores` por `rnc`. Si no matchea, 404.

### 5.5. PDF y emails

- `FacturaPdfGenerator` ya recibe data del emisor; pasarle el record de
  `emisores` correcto en vez del singleton.
- Los headers/footers del email pueden personalizarse por emisor (logo,
  color, contacto) — agregar campos a `emisores` cuando se necesite.

## 6. Cambios de API

### 6.1. Crear / listar emisores

```
GET    /api/emisores                 # lista (admin)
POST   /api/emisores                 # onboarding (admin)
GET    /api/emisores/{id}            # detalle
PUT    /api/emisores/{id}            # editar
POST   /api/emisores/{id}/certificado # subir nuevo .p12 + password
POST   /api/emisores/{id}/secuencia   # registrar rango autorizado por DGII
```

### 6.2. Endpoints existentes con scope de emisor

```
GET    /api/emisores/{emisorId}/facturas
POST   /api/emisores/{emisorId}/facturas
GET    /api/emisores/{emisorId}/clients
... (etc)
```

O, si se prefiere mantener las rutas planas, agregar header obligatorio:

```
X-Emisor-Id: 3
```

## 7. Migración de datos existentes

Toda la data ya creada queda asignada al emisor `id = 1` (Gratex). Nada
se rompe. Pasos al ejecutar la migración:

1. Backup completo de la BD.
2. Ejecutar `003_multi_emisor.sql` (todo en una transacción).
3. Verificar que `emisores` tiene el registro original como id=1.
4. Verificar que todas las filas de `facturas`, `clients`, `ncf_sequences`
   tienen `emisor_id = 1`.
5. Ejecutar smoke tests: emitir una factura de prueba.

## 8. Seguridad

- **Aislamiento de datos:** todos los queries deben filtrar por `emisor_id`.
  Riesgo grande: olvidar el filtro en algún query → un cliente ve facturas
  de otro. Mitigación: code review riguroso + tests con dos emisores
  distintos en setup.
- **Permisos:** middleware verifica `user_emisores` antes de dejar pasar.
- **Logs:** cada emisión / consulta logear `user_id` + `emisor_id`.
- **Certificados:** los `.p12` de otros clientes son **datos altamente
  sensibles**; comprometerlos = capacidad de emitir facturas falsas a
  nombre de esa empresa. Backup cifrado, rotación documentada.
- **Aislamiento físico opcional:** para clientes grandes, considerar BD
  separada por emisor (no soporte multi-tenant en una sola DB).

## 9. Testing

- Setup con dos emisores ficticios (`Emisor A` RNC `00100000001`,
  `Emisor B` RNC `00100000002`) y dos certificados de prueba.
- Tests obligatorios:
  - Emitir factura como A → la secuencia de A avanza, la de B no.
  - Listar facturas como A no muestra las de B.
  - Recepción de e-CF dirigido a B llega solo al inbox de B.
  - Usuario sin permiso sobre A no puede emitir como A.

## 10. Operacional / onboarding

Proceso para agregar un cliente nuevo:

1. Cliente firma contrato con Gratex.
2. Cliente obtiene su certificado `.p12` y rango de secuencias en DGII.
3. Admin crea registro en `emisores` vía API.
4. Admin sube `.p12` + password (se cifra y guarda).
5. Admin registra el rango de secuencia.
6. Admin asigna usuarios del cliente al emisor en `user_emisores`.
7. Cliente registra en DGII las URLs de Gratex como receptor / aprobación
   / autenticación → todos los emisores comparten las mismas URLs;
   ruteamos por RNC.

## 11. Estimación

| Fase | Estimado |
|---|---|
| Migración SQL + scripts | 0.5 día |
| Refactor modelos y servicios | 1 día |
| Refactor controllers y middleware | 1 día |
| UI con selector de emisor | 0.5 día |
| Endpoints CRUD de emisores | 0.5 día |
| Cifrado de credenciales | 0.5 día |
| Tests con dos emisores | 0.5 día |
| **Total** | **~4.5 días** |

## 12. Riesgos

- **Cross-tenant data leak**: el más crítico. Single source of truth: un
  helper `requireEmisor(): int` que todos los controllers usen y que
  inyecte el filtro automáticamente.
- **Gestión de certificados expirando**: agregar columna `cert_expira_at`
  y un cron que avise 30 días antes.
- **Fechas de vencimiento de secuencias DGII**: la columna
  `fecha_vencimiento` por secuencia evita emitir con secuencias vencidas
  (validar en `dispenseNextECF`).

## 13. Cuándo iniciar

Recomendable arrancar el refactor cuando:
- Gratex (emisor único actual) esté **certificado por DGII** y emitiendo
  en producción sin issues por al menos 2 semanas.
- Haya al menos un cliente real comprometido a usarlo (no refactorizar
  sobre hipótesis).
- Haya backup automático diario funcionando.

## 14. Lo que NO hay que hacer

- No mezclar este refactor con cambios funcionales nuevos. Solo cambia
  el modelo de "1 emisor" a "N emisores"; el comportamiento por emisor
  individual debe quedar idéntico.
- No omitir la migración aditiva. Si se elimina `emisor_config` en vez de
  renombrarla, código viejo en producción rompe.
- No guardar contraseñas de cert en claro en `emisores.cert_password`,
  ni siquiera "temporalmente".
