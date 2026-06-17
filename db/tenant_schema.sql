-- ============================================================================
-- tenant_schema.sql — Esquema COMPLETO (consolidado) para un DB de tenant nuevo.
-- ============================================================================
-- Snapshot del esquema de negocio final: equivale a la base original MAS las
-- migraciones 001..011 ya aplicadas (hoy en db/migrations/deprecated/, solo
-- como historial de los DBs que se actualizaron incrementalmente, ej. Gratex).
--
-- Un tenant nuevo corre SOLO este archivo (tools/create_tenant.php lo aplica);
-- ya no se reproducen las migraciones una por una.
--
-- Cambios futuros de esquema: crear db/migrations/NNN_*.sql (para DBs de
-- tenant ya desplegados) Y reflejar el cambio aqui (para tenants nuevos).
--
-- SIN users / api_tokens / landing_* (viven en gratex_master). NO incluye
-- CREATE DATABASE / USE: el onboarding crea el DB y selecciona su conexion.
--
-- Ver: docs/architecture.md
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1) Clientes (con datos fiscales para e-CF; E31 requiere RNC + razon social)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS clients (
  id            INT(11)      NOT NULL AUTO_INCREMENT,
  email         VARCHAR(100) NOT NULL,
  client_name   VARCHAR(100) NOT NULL,
  company_name  VARCHAR(100) NOT NULL,
  rnc           VARCHAR(11)  NULL,
  razon_social  VARCHAR(150) NULL,
  direccion     VARCHAR(100) NULL,
  municipio     VARCHAR(50)  NULL,
  provincia     VARCHAR(50)  NULL,
  phone_number  VARCHAR(20)  NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 2) Cotizaciones
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS cotizaciones (
  id           INT(11)        NOT NULL AUTO_INCREMENT,
  code         VARCHAR(50)    NOT NULL,
  date         DATETIME       DEFAULT CURRENT_TIMESTAMP,
  client_id    INT(11)        DEFAULT NULL,
  client_name  VARCHAR(100)   NOT NULL,
  total        DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cotizacion_items (
  id             INT(11)        NOT NULL AUTO_INCREMENT,
  cotizacion_id  INT(11)        NOT NULL,
  description    TEXT           NOT NULL,
  amount         DECIMAL(10,2)  NOT NULL,
  quantity       INT(11)        NOT NULL DEFAULT 1,
  subtotal       DECIMAL(10,2)  NOT NULL,
  PRIMARY KEY (id),
  KEY cotizacion_id (cotizacion_id),
  CONSTRAINT cotizacion_items_ibfk_1 FOREIGN KEY (cotizacion_id) REFERENCES cotizaciones (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 3) Facturas (con tracking e-CF, RFCE, secuencia y notas E33/E34)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS facturas (
  id           INT(11)        NOT NULL AUTO_INCREMENT,
  no_factura   VARCHAR(50)    NOT NULL,
  date         DATETIME       DEFAULT CURRENT_TIMESTAMP,
  client_id    INT(11)        DEFAULT NULL,
  client_name  VARCHAR(100)   NOT NULL,
  total        DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
  NCF          VARCHAR(50)    NULL,
  tipo_ecf     VARCHAR(2)     NULL
                 COMMENT '31, 32, 33, 34, 41, 43, 44, 45, 46, 47',
  e_ncf        VARCHAR(13)    NULL,
  track_id     VARCHAR(60)    NULL
                 COMMENT 'TrackId que devuelve DGII al recibir el e-CF',
  estado_dgii  VARCHAR(20)    NOT NULL DEFAULT 'PENDIENTE'
                 COMMENT 'PENDIENTE | ENVIADO | ACEPTADO | ACEPTADO_CONDICIONAL | RECHAZADO | ERROR',
  codigo_seguridad VARCHAR(10) NULL
                 COMMENT 'Codigo de seguridad para representacion impresa y QR',
  fecha_emision_dgii DATETIME NULL,
  ambiente_dgii VARCHAR(20)   NULL COMMENT 'testecf | certecf | ecf',
  xml_firmado  MEDIUMTEXT     NULL COMMENT 'XML firmado enviado a DGII',
  respuesta_dgii TEXT         NULL COMMENT 'Ultima respuesta de DGII (JSON serializado)',
  rfce_xml     MEDIUMTEXT     NULL,
  rfce_track_id VARCHAR(60)   NULL,
  rfce_estado  VARCHAR(30)    NULL,
  rfce_respuesta TEXT         NULL,
  secuencia_utilizada TINYINT(1) NULL
                 COMMENT 'DGII: false => el e-NCF puede reutilizarse en un nuevo envio',
  ncf_modificado VARCHAR(19)  NULL
                 COMMENT 'e-NCF del comprobante que esta nota (E33/E34) modifica',
  fecha_ncf_modificado DATE   NULL
                 COMMENT 'Fecha de emision del NCF modificado',
  codigo_modificacion VARCHAR(2) NULL
                 COMMENT '1=Anula | 2=Corrige texto | 3=Corrige montos | 4=Reemplazo contingencia | 5=Ref. Factura Consumo',
  razon_modificacion VARCHAR(90) NULL
                 COMMENT 'Motivo/descripcion de la modificacion (se muestra en la RI)',
  user_id      INT(11)        DEFAULT NULL
                 COMMENT 'Referencia a gratex_master.users.id (sin FK cross-DB)',
  PRIMARY KEY (id),
  UNIQUE KEY uk_e_ncf (e_ncf),
  KEY idx_track_id (track_id),
  KEY idx_estado_dgii (estado_dgii),
  KEY idx_rfce_track_id (rfce_track_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS factura_items (
  id          INT(11)        NOT NULL AUTO_INCREMENT,
  factura_id  INT(11)        NOT NULL,
  description TEXT           NOT NULL,
  amount      DECIMAL(10,2)  NOT NULL,
  quantity    INT(11)        NOT NULL DEFAULT 1,
  subtotal    DECIMAL(10,2)  NOT NULL,
  indicador_facturacion TINYINT NOT NULL DEFAULT 1
                 COMMENT '0=No facturable | 1=ITBIS 18% | 2=ITBIS 16% | 3=ITBIS 0% | 4=Exento',
  indicador_bien_servicio TINYINT NOT NULL DEFAULT 1
                 COMMENT '1=Bien | 2=Servicio',
  unidad_medida VARCHAR(10) NOT NULL DEFAULT '43'
                 COMMENT 'Codigo de unidad de medida DGII (43 = unidad)',
  itbis_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (id),
  KEY factura_id (factura_id),
  CONSTRAINT factura_items_ibfk_1 FOREIGN KEY (factura_id) REFERENCES facturas (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 4) Secuencias NCF / e-NCF — RANGOS AUTORIZADOS por ambiente.
--    Modelo DGII real: cada fila es UN rango aprobado (Numero Desde/Hasta, No.
--    Autorizacion y Fecha Vencimiento). El dispensador (ncfModel) toma el rango
--    activo (capacidad restante + no vencido, menor numero_desde) e incrementa
--    current_value acotado por numero_hasta; agotado el rango se registra el
--    siguiente (POST /api/ncf/rangos). numero_hasta NULL = sin limite (modo
--    pruebas/legacy: certecf y testecf). current_value = ultimo dispensado;
--    en rangos nuevos arranca en numero_desde - 1.
--    Ver db/migrations/014_ncf_rangos_autorizados.sql.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ncf_sequences (
  id            INT(11)      NOT NULL AUTO_INCREMENT,
  type          VARCHAR(10)  NOT NULL,
  prefix        VARCHAR(10)  NOT NULL,
  current_value INT(11)      NOT NULL DEFAULT 0,
  numero_desde  INT(11)      NOT NULL DEFAULT 1
                  COMMENT 'Inicio del rango autorizado por DGII',
  numero_hasta  INT(11)      NULL
                  COMMENT 'Fin del rango autorizado; NULL = sin limite (legacy/pruebas)',
  fecha_vencimiento DATE     NULL
                  COMMENT 'Vencimiento del rango (FechaVencimientoSecuencia del XML)',
  no_solicitud  VARCHAR(20)  NULL COMMENT 'No. Solicitud DGII del rango',
  no_autorizacion VARCHAR(20) NULL COMMENT 'No. Autorizacion DGII del rango',
  description   VARCHAR(100) DEFAULT NULL,
  ambiente      VARCHAR(20)  NOT NULL DEFAULT 'certecf'
                  COMMENT 'testecf | certecf | ecf — las secuencias son independientes por ambiente',
  created_at    DATETIME     DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_type_amb_desde (type, ambiente, numero_desde)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO ncf_sequences (type, prefix, current_value, description, ambiente) VALUES
  -- NCF tradicionales (no electronicos; una sola fila, certecf por paridad con Gratex)
  ('B01', 'B01', 0, 'Facturas de Credito Fiscal', 'certecf'),
  ('B02', 'B02', 0, 'Facturas de Consumidor Final', 'certecf'),
  ('B14', 'B14', 0, 'Regimenes Especiales', 'certecf'),
  ('B15', 'B15', 0, 'Gubernamental', 'certecf'),
  -- e-CF en ambiente de certificacion
  ('E31', 'E31', 0, 'Credito Fiscal Electronico', 'certecf'),
  ('E32', 'E32', 0, 'Consumo Electronico', 'certecf'),
  ('E33', 'E33', 0, 'Nota de Debito Electronica', 'certecf'),
  ('E34', 'E34', 0, 'Nota de Credito Electronica', 'certecf'),
  ('E41', 'E41', 0, 'Compras Electronico', 'certecf'),
  ('E43', 'E43', 0, 'Gastos Menores Electronico', 'certecf'),
  ('E44', 'E44', 0, 'Regimenes Especiales Electronico', 'certecf'),
  ('E45', 'E45', 0, 'Gubernamental Electronico', 'certecf'),
  ('E46', 'E46', 0, 'Comprobante de Exportaciones', 'certecf'),
  ('E47', 'E47', 0, 'Comprobante para Pagos al Exterior', 'certecf'),
  -- e-CF en produccion (arrancan en 0 al promover el tenant a ecf)
  ('E31', 'E31', 0, 'Credito Fiscal Electronico', 'ecf'),
  ('E32', 'E32', 0, 'Consumo Electronico', 'ecf'),
  ('E33', 'E33', 0, 'Nota de Debito Electronica', 'ecf'),
  ('E34', 'E34', 0, 'Nota de Credito Electronica', 'ecf'),
  ('E41', 'E41', 0, 'Compras Electronico', 'ecf'),
  ('E43', 'E43', 0, 'Gastos Menores Electronico', 'ecf'),
  ('E44', 'E44', 0, 'Regimenes Especiales Electronico', 'ecf'),
  ('E45', 'E45', 0, 'Gubernamental Electronico', 'ecf'),
  ('E46', 'E46', 0, 'Comprobante de Exportaciones', 'ecf'),
  ('E47', 'E47', 0, 'Comprobante para Pagos al Exterior', 'ecf'),
  -- e-CF en testecf (pruebas libres / tenants demo)
  ('E31', 'E31', 0, 'Credito Fiscal Electronico', 'testecf'),
  ('E32', 'E32', 0, 'Consumo Electronico', 'testecf'),
  ('E33', 'E33', 0, 'Nota de Debito Electronica', 'testecf'),
  ('E34', 'E34', 0, 'Nota de Credito Electronica', 'testecf'),
  ('E41', 'E41', 0, 'Compras Electronico', 'testecf'),
  ('E43', 'E43', 0, 'Gastos Menores Electronico', 'testecf'),
  ('E44', 'E44', 0, 'Regimenes Especiales Electronico', 'testecf'),
  ('E45', 'E45', 0, 'Gubernamental Electronico', 'testecf'),
  ('E46', 'E46', 0, 'Comprobante de Exportaciones', 'testecf'),
  ('E47', 'E47', 0, 'Comprobante para Pagos al Exterior', 'testecf')
ON DUPLICATE KEY UPDATE type = type;

-- ----------------------------------------------------------------------------
-- 5) Configuracion del emisor (1 registro, id=1).
--    Placeholder: tools/create_tenant.php lo actualiza con los datos reales
--    del tenant inmediatamente despues de aplicar este schema.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS emisor_config (
  id INT(11) NOT NULL AUTO_INCREMENT,
  rnc VARCHAR(11) NOT NULL,
  razon_social VARCHAR(150) NOT NULL,
  nombre_comercial VARCHAR(150) NULL,
  sucursal VARCHAR(20) NULL,
  direccion VARCHAR(100) NOT NULL,
  municipio VARCHAR(50) NULL,
  provincia VARCHAR(50) NULL,
  telefono VARCHAR(12) NULL COMMENT 'Formato: 999-999-9999',
  correo VARCHAR(80) NULL,
  website VARCHAR(50) NULL,
  actividad_economica VARCHAR(100) NULL,
  fecha_vencimiento_secuencia DATE NOT NULL DEFAULT '2030-12-31'
    COMMENT 'Fecha de vencimiento de la secuencia autorizada por DGII',
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO emisor_config (
  id, rnc, razon_social, nombre_comercial,
  direccion, municipio, provincia, telefono, correo, website
) VALUES (
  1, '000000000', 'PENDIENTE ONBOARDING', NULL,
  'Pendiente', NULL, NULL, NULL, NULL, NULL
) ON DUPLICATE KEY UPDATE id = id;

-- ----------------------------------------------------------------------------
-- 6) Clientes de prueba DGII (set de certificacion Fase 2; el wizard de cert
--    usa el id del cliente RNC 131880681 como client_id).
-- ----------------------------------------------------------------------------
INSERT INTO clients (
  client_name, company_name, rnc, razon_social,
  direccion, municipio, provincia, email, phone_number
) VALUES (
  'DOCUMENTOS ELECTRONICOS DE 03', 'DOCUMENTOS ELECTRONICOS DE 03',
  '131880681', 'DOCUMENTOS ELECTRONICOS DE 03',
  'CALLE JACINTO DE LA CONCHA FELIZ ESQUINA 27 DE FEBRERO',
  '010100', '010000', 'fase2-comprador@example.com', ''
);

INSERT INTO clients (
  client_name, company_name, rnc, razon_social,
  direccion, email, phone_number
) VALUES (
  'PROVEEDOR PRUEBA 533', 'PROVEEDOR PRUEBA 533',
  '533445861', 'PROVEEDOR PRUEBA 533',
  'Direccion de prueba', 'fase2-prov@example.com', ''
);

-- ----------------------------------------------------------------------------
-- 7) Recepcion: e-CF recibidos (otros emisores nos facturan), con aprobacion
--    comercial saliente, ambiente y origen (auditoria).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ecf_recibidos (
  id INT(11) NOT NULL AUTO_INCREMENT,
  track_id VARCHAR(60) NOT NULL,
  tipo_ecf VARCHAR(2) NULL,
  e_ncf VARCHAR(13) NULL,
  rnc_emisor VARCHAR(11) NOT NULL,
  razon_social_emisor VARCHAR(150) NULL,
  rnc_comprador VARCHAR(11) NULL
    COMMENT 'Debe coincidir con el RNC en emisor_config',
  monto_total DECIMAL(18,2) NULL,
  fecha_emision DATE NULL,
  fecha_recepcion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  estado VARCHAR(30) NOT NULL DEFAULT 'RECIBIDO'
    COMMENT 'RECIBIDO | EN_PROCESO | ACEPTADO | RECHAZADO | ERROR_FIRMA | ERROR_XSD',
  codigo_resultado INT(11) NULL
    COMMENT 'Codigo numerico devuelto al emisor (1=Aceptado, 2=Rechazado)',
  mensaje_resultado VARCHAR(500) NULL,
  xml_firmado MEDIUMTEXT NULL,
  validacion_firma VARCHAR(20) NULL
    COMMENT 'OK | INVALIDA | NO_VERIFICADA',
  ambiente VARCHAR(20) NULL
    COMMENT 'testecf | certecf | ecf — modo del server al recibir el e-CF',
  origen_ip VARCHAR(45) NULL
    COMMENT 'IP del POST (X-Forwarded-For o REMOTE_ADDR)',
  origen_user_agent VARCHAR(255) NULL
    COMMENT 'User-Agent del software del emisor',
  origen_auth VARCHAR(10) NULL
    COMMENT 'bearer = handshake completo | firma = receptor abierto | manual = import_recibido.php',
  origen_rnc_bearer VARCHAR(20) NULL
    COMMENT 'RNC del Bearer token (si autentico)',
  firma_rnc VARCHAR(20) NULL
    COMMENT 'RNC/cedula del certificado firmante (X509 de la Signature)',
  firma_subject VARCHAR(255) NULL
    COMMENT 'CN del certificado firmante',
  aprobacion_comercial VARCHAR(20) NULL
    COMMENT 'Decision del comprador: ACEPTADO | RECHAZADO. NULL = sin responder',
  aprobacion_comercial_detalle VARCHAR(500) NULL
    COMMENT 'Motivo del rechazo comercial (requerido por DGII cuando se rechaza)',
  aprobacion_comercial_codigo_dgii VARCHAR(5) NULL
    COMMENT 'codigo de RespuestaAprobacionComercial (1=procesada, 2=no procesada)',
  aprobacion_comercial_estado_dgii VARCHAR(120) NULL
    COMMENT 'estado textual devuelto por DGII (ej: "Aprobacion Comercial Rechazada.")',
  aprobacion_comercial_mensaje_dgii VARCHAR(500) NULL
    COMMENT 'mensaje[] de DGII unido por " | "',
  aprobacion_comercial_procesada TINYINT(1) NULL
    COMMENT '1 si DGII codigo=1 (procesada OK), 0 si no se pudo procesar',
  aprobacion_comercial_fecha DATETIME NULL
    COMMENT 'Fecha/hora en que se envio la aprobacion/rechazo a DGII',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_track_id (track_id),
  UNIQUE KEY uk_e_ncf_emisor (rnc_emisor, e_ncf),
  KEY idx_estado (estado),
  KEY idx_fecha_recepcion (fecha_recepcion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 8) Aprobaciones comerciales recibidas (compradores responden a e-CF nuestros)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS aprobaciones_comerciales (
  id INT(11) NOT NULL AUTO_INCREMENT,
  factura_id INT(11) NULL
    COMMENT 'FK a facturas si encontramos coincidencia por e-NCF',
  e_ncf VARCHAR(13) NOT NULL
    COMMENT 'e-NCF que el comprador esta aceptando o rechazando',
  rnc_emisor VARCHAR(11) NOT NULL
    COMMENT 'Nuestro RNC; debe coincidir con emisor_config',
  rnc_comprador VARCHAR(11) NOT NULL
    COMMENT 'RNC del comprador que envia la aprobacion',
  estado_comercial VARCHAR(30) NOT NULL
    COMMENT 'ACEPTADO | ACEPTADO_CONDICIONAL | RECHAZADO',
  detalle_motivo VARCHAR(500) NULL,
  xml_firmado MEDIUMTEXT NULL,
  validacion_firma VARCHAR(20) NULL,
  ambiente VARCHAR(20) NULL
    COMMENT 'testecf | certecf | ecf — modo del server al recibir la aprobacion',
  fecha_recepcion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_e_ncf (e_ncf),
  KEY idx_factura_id (factura_id),
  KEY idx_rnc_comprador (rnc_comprador)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 9) Autenticacion entrante (semilla -> token). NOTA: en multi-tenant el flujo
--    vivo usa las tablas del MASTER (authSeedModel); estas quedan por paridad
--    con el esquema original y por si el modo single-tenant se reactiva.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS auth_seeds (
  id INT(11) NOT NULL AUTO_INCREMENT,
  seed_value VARCHAR(64) NOT NULL
    COMMENT 'Valor unico (random) que aparece dentro del XML semilla',
  xml_emitido TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expira_at DATETIME NOT NULL
    COMMENT 'Por defecto 5 minutos despues de creada',
  consumida_at DATETIME NULL
    COMMENT 'NULL = no consumida; fecha = ya se emitio token con esta semilla',
  rnc_consumidor VARCHAR(11) NULL
    COMMENT 'RNC extraido del certificado al validar',
  token_emitido VARCHAR(2048) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_seed_value (seed_value),
  KEY idx_expira_at (expira_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_tokens_emitidos (
  id INT(11) NOT NULL AUTO_INCREMENT,
  token VARCHAR(2048) NOT NULL,
  rnc_consumidor VARCHAR(11) NOT NULL,
  expedido_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expira_at DATETIME NOT NULL,
  revocado_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_token_prefix (token(64)),
  KEY idx_rnc_consumidor (rnc_consumidor),
  KEY idx_expira_at (expira_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 10) Modulo de Gastos (emitidos E41/E43/E47 con emision e-CF; recibidos
--     B01/E31/E33/E34 registrados)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS gastos (
  id INT(11) NOT NULL AUTO_INCREMENT,
  categoria VARCHAR(30) NOT NULL
    COMMENT 'gastos_menores (E43) | facturas_proveedores (E41, E47, E31, B01, E33, E34)',
  tipo_gasto VARCHAR(3) NOT NULL
    COMMENT 'E41, E43, E47 (auto-emision) | B01, E31, E33, E34 (recibido)',
  ncf VARCHAR(19) NULL
    COMMENT 'Auto-emision: secuencia interna generada. Recibido: NCF del proveedor',
  rnc_proveedor VARCHAR(11) NOT NULL
    COMMENT 'RNC/Cedula del proveedor (informal en compras 11/41)',
  nombre_proveedor VARCHAR(150) NOT NULL,
  fecha DATE NOT NULL,
  subtotal DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  itbis DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  total DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  es_auto_emision TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1 = la empresa emite y genera la secuencia | 0 = recibido del proveedor',
  ambiente VARCHAR(20) NULL COMMENT 'testecf | certecf | ecf',
  estado_dgii VARCHAR(20) NOT NULL DEFAULT 'REGISTRADO'
    COMMENT 'REGISTRADO (recibido) | PENDIENTE_EMISION | ENVIADO | ACEPTADO | ACEPTADO_CONDICIONAL | RECHAZADO | ERROR',
  track_id VARCHAR(60) NULL
    COMMENT 'TrackId que devuelve DGII al recibir el e-CF emitido',
  codigo_seguridad VARCHAR(10) NULL
    COMMENT 'Codigo de seguridad para representacion impresa y QR',
  fecha_emision_dgii DATETIME NULL,
  xml_firmado MEDIUMTEXT NULL COMMENT 'XML firmado enviado a DGII',
  respuesta_dgii TEXT NULL COMMENT 'Ultima respuesta de DGII (JSON serializado)',
  secuencia_utilizada TINYINT(1) NULL
    COMMENT 'DGII: false => el e-NCF puede reutilizarse en un nuevo envio',
  user_id INT(11) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_proveedor_ncf (rnc_proveedor, ncf),
  KEY idx_categoria (categoria),
  KEY idx_tipo_gasto (tipo_gasto),
  KEY idx_rnc_proveedor (rnc_proveedor),
  KEY idx_ambiente (ambiente),
  KEY idx_estado_dgii (estado_dgii),
  KEY idx_track_id (track_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gasto_items (
  id INT(11) NOT NULL AUTO_INCREMENT,
  gasto_id INT(11) NOT NULL,
  description TEXT NOT NULL,
  amount DECIMAL(18,2) NOT NULL,
  quantity INT(11) NOT NULL DEFAULT 1,
  subtotal DECIMAL(18,2) NOT NULL,
  itbis_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  indicador_facturacion TINYINT NOT NULL DEFAULT 1
    COMMENT '1=ITBIS 18% | 2=ITBIS 16% | 3=ITBIS 0% | 4=Exento | 0=No facturable',
  indicador_bien_servicio TINYINT NOT NULL DEFAULT 2
    COMMENT '1=Bien | 2=Servicio',
  unidad_medida VARCHAR(10) NOT NULL DEFAULT '43'
    COMMENT 'Codigo DGII de unidad de medida (id del catalogo unidades_medida; 43 = Unidad)',
  PRIMARY KEY (id),
  KEY idx_gasto_id (gasto_id),
  CONSTRAINT gasto_items_ibfk_1 FOREIGN KEY (gasto_id) REFERENCES gastos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 11) Catalogo de productos/servicios (para la facturacion). `indicador_facturacion`
--     define el gravamen ITBIS igual que en factura_items (1=18% gravado, 4=Exento).
--     Ver db/migrations/012_add_products.sql.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS products (
  id INT(11) NOT NULL AUTO_INCREMENT,
  sku VARCHAR(50) NULL,
  nombre VARCHAR(150) NOT NULL,
  descripcion VARCHAR(255) NULL,
  categoria VARCHAR(50) NULL,
  indicador_bien_servicio TINYINT NOT NULL DEFAULT 1
    COMMENT '1=Bien | 2=Servicio',
  indicador_facturacion TINYINT NOT NULL DEFAULT 1
    COMMENT '0=No facturable | 1=ITBIS 18% | 2=ITBIS 16% | 3=Tasa cero | 4=Exento (gravado=1, exento=4)',
  precio DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  costo DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  unidad_medida VARCHAR(10) NOT NULL DEFAULT '43'
    COMMENT 'Codigo de unidad de medida DGII (43 = unidad)',
  stock INT(11) NULL COMMENT 'NULL para servicios (sin inventario)',
  stock_minimo INT(11) NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_sku (sku),
  KEY idx_categoria (categoria),
  KEY idx_activo (activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 12) Directorio de proveedores (los gastos guardan el proveedor inline; esta
--     tabla es el catalogo para autocompletar/gestionar). El conteo de compras
--     se deriva uniendo gastos por rnc_proveedor.
--     Ver db/migrations/013_add_proveedores.sql.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS proveedores (
  id INT(11) NOT NULL AUTO_INCREMENT,
  rnc VARCHAR(11) NULL,
  nombre VARCHAR(150) NOT NULL,
  contacto VARCHAR(100) NULL,
  telefono VARCHAR(20) NULL,
  correo VARCHAR(100) NULL,
  direccion VARCHAR(150) NULL,
  notas VARCHAR(255) NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uk_rnc (rnc),
  KEY idx_activo (activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- Tabla: Unidades de Medida (basado en Unidades.xml - DGII)
-- =============================================================================

CREATE TABLE IF NOT EXISTS `unidades_medida` (
  `id` TINYINT UNSIGNED NOT NULL,
  `codigo` VARCHAR(10) NOT NULL COMMENT 'Código abreviado de la unidad',
  `descripcion` VARCHAR(100) NOT NULL COMMENT 'Descripción completa de la unidad',
  `activo` BOOLEAN NOT NULL DEFAULT TRUE,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_codigo` (`codigo`),
  INDEX `idx_descripcion` (`descripcion`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Unidades de medida para facturación electrónica DGII';

-- =============================================================================
-- Insertar todas las unidades de medida (1-62)
-- =============================================================================

INSERT INTO `unidades_medida` (`id`, `codigo`, `descripcion`) VALUES
(1, 'BARR', 'Barril'),
(2, 'BOL', 'Bolsa'),
(3, 'BOT', 'Bote'),
(4, 'BULTO', 'Bultos'),
(5, 'BOTELLA', 'Botella'),
(6, 'CAJ', 'Caja/Cajón'),
(7, 'CAJETILLA', 'Cajetilla'),
(8, 'CM', 'Centímetro'),
(9, 'CIL', 'Cilindro'),
(10, 'CONJ', 'Conjunto'),
(11, 'CONT', 'Contenedor'),
(12, 'DÍA', 'Día'),
(13, 'DOC', 'Docena'),
(14, 'FARD', 'Fardo'),
(15, 'GL', 'Galones'),
(16, 'GRAD', 'Grado'),
(17, 'GR', 'Gramo'),
(18, 'GRAN', 'Granel'),
(19, 'HOR', 'Hora'),
(20, 'HUAC', 'Huacal'),
(21, 'KG', 'Kilogramo'),
(22, 'kWh', 'Kilovatio Hora'),
(23, 'LB', 'Libra'),
(24, 'LITRO', 'Litro'),
(25, 'LOT', 'Lote'),
(26, 'M', 'Metro'),
(27, 'M2', 'Metro Cuadrado'),
(28, 'M3', 'Metro Cúbico'),
(29, 'MMBTU', 'Millones de Unidades Térmicas'),
(30, 'MIN', 'Minuto'),
(31, 'PAQ', 'Paquete'),
(32, 'PAR', 'Par'),
(33, 'PIE', 'Pie'),
(34, 'PZA', 'Pieza'),
(35, 'ROL', 'Rollo'),
(36, 'SOBR', 'Sobre'),
(37, 'SEG', 'Segundo'),
(38, 'TANQUE', 'Tanque'),
(39, 'TONE', 'Tonelada'),
(40, 'TUB', 'Tubo'),
(41, 'YD', 'Yarda'),
(42, 'YD2', 'Yarda cuadrada'),
(43, 'UND', 'Unidad'),
(44, 'EA', 'Elemento'),
(45, 'MILLAR', 'Millar'),
(46, 'SAC', 'Saco'),
(47, 'LAT', 'Lata'),
(48, 'DIS', 'Display'),
(49, 'BID', 'Bidón'),
(50, 'RAC', 'Ración'),
(51, 'Q', 'Quintal'),
(52, 'GRT', 'Toneladas de registro bruto'),
(53, 'P2', 'Pie Cuadrado'),
(54, 'PAX', 'Pasajero'),
(55, 'PULG', 'Pulgadas'),
(56, 'STAY', 'Parqueo Barcos En Muelle'),
(57, 'BDJ', 'Bandeja'),
(58, 'HA', 'Hectárea'),
(59, 'ML', 'Mililitro'),
(60, 'MG', 'Miligramo'),
(61, 'OZ', 'Onzas'),
(62, 'OZT', 'Onzas Troy')
ON DUPLICATE KEY UPDATE
  `codigo` = VALUES(`codigo`),
  `descripcion` = VALUES(`descripcion`);

-- =============================================================================
-- Consultas útiles:
-- =============================================================================

-- Ver todas las unidades activas:
-- SELECT * FROM unidades_medida WHERE activo = TRUE ORDER BY id;

-- Buscar por código:
-- SELECT * FROM unidades_medida WHERE codigo = 'KG';

-- Buscar por descripción (LIKE):
-- SELECT * FROM unidades_medida WHERE descripcion LIKE '%metro%';

-- =============================================================================
-- roles / role_permissions — RBAC (FALLBACK single-tenant).
-- En modo multi-tenant los roles viven en el MASTER (gratex_master). Estas
-- tablas solo se usan cuando MULTI_TENANT_ENABLED=false (todo en una DB).
-- tenant_id = 0 = el unico tenant logico del modo single-tenant.
-- =============================================================================
CREATE TABLE IF NOT EXISTS roles (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  tenant_id   INT          NOT NULL DEFAULT 0,
  name        VARCHAR(40)  NOT NULL,
  description VARCHAR(150) NULL,
  is_system   TINYINT(1)   NOT NULL DEFAULT 0,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_role_tenant_name (tenant_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  role_id    INT         NOT NULL,
  permission VARCHAR(60) NOT NULL,
  UNIQUE KEY uq_role_perm (role_id, permission),
  CONSTRAINT fk_rp_role_t FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO roles (tenant_id, name, description, is_system)
SELECT 0, 'admin', 'Acceso total', 1
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE tenant_id = 0 AND name = 'admin');
INSERT INTO roles (tenant_id, name, description, is_system)
SELECT 0, 'user', 'Operacion (sin administracion)', 1
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE tenant_id = 0 AND name = 'user');

INSERT INTO role_permissions (role_id, permission)
SELECT r.id, '*' FROM roles r
WHERE r.tenant_id = 0 AND r.name = 'admin'
  AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission = '*');

INSERT INTO role_permissions (role_id, permission)
SELECT r.id, p.perm
FROM roles r
JOIN (
  SELECT 'facturas' AS perm UNION ALL SELECT 'facturas-simples'
  UNION ALL SELECT 'gastos' UNION ALL SELECT 'clients'
  UNION ALL SELECT 'products' UNION ALL SELECT 'proveedores'
  UNION ALL SELECT 'cotizaciones' UNION ALL SELECT 'aprobaciones'
  UNION ALL SELECT 'reportes' UNION ALL SELECT 'ncf' UNION ALL SELECT 'unidades'
) p
WHERE r.tenant_id = 0 AND r.name = 'user'
  AND NOT EXISTS (SELECT 1 FROM role_permissions rp WHERE rp.role_id = r.id AND rp.permission = p.perm);