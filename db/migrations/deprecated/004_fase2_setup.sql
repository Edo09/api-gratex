-- =============================================================================
-- Migration 004: Setup para Fase 2 de certificacion DGII
-- - Actualiza emisor_config con el RNC de prueba que DGII asigno (131256432)
-- - Crea/actualiza el cliente de prueba (RNC 131880681 que aparece en el set)
-- - Asegura que existan las secuencias E31..E47 (la 001 ya las inserta;
--   este script solo refuerza por si fueron borradas)
--
-- IMPORTANTE: Si tu RNC de produccion es distinto al de prueba (131256432),
-- guarda los valores actuales antes de correr este script y restaurarlos al
-- terminar Fase 2.
-- =============================================================================

START TRANSACTION;

-- 1) Actualiza emisor_config al RNC de prueba que aparece en el set
UPDATE `emisor_config`
SET
  `rnc` = '131256432',
  `razon_social` = 'DOCUMENTOS ELECTRONICOS DE 02',
  `nombre_comercial` = 'DOCUMENTOS ELECTRONICOS DE 02',
  `direccion` = 'AVE. ISABEL AGUIAR NO. 269, ZONA INDUSTRIAL DE HERRERA',
  `municipio` = '010100',
  `provincia` = '010000',
  `telefono` = '809-472-7676',
  `correo` = 'fase2@gratex.net',
  `website` = 'https://gratex.net',
  `fecha_vencimiento_secuencia` = '2028-12-31'
WHERE `id` = 1;

-- 2) Cliente de prueba que recibe la mayoria de los e-CF (RNCComprador del set)
INSERT INTO `clients` (
  `client_name`, `company_name`, `rnc`, `razon_social`,
  `direccion`, `municipio`, `provincia`, `email`
) VALUES (
  'DOCUMENTOS ELECTRONICOS DE 03',
  'DOCUMENTOS ELECTRONICOS DE 03',
  '131880681',
  'DOCUMENTOS ELECTRONICOS DE 03',
  'CALLE JACINTO DE LA CONCHA FELIZ ESQUINA 27 DE FEBRERO',
  '010100',
  '010000',
  'fase2-comprador@example.com'
)
ON DUPLICATE KEY UPDATE
  `rnc` = VALUES(`rnc`),
  `razon_social` = VALUES(`razon_social`),
  `direccion` = VALUES(`direccion`);

-- 3) Cliente alterno (RNC 533445861) usado en E410000000007
INSERT INTO `clients` (
  `client_name`, `company_name`, `rnc`, `razon_social`,
  `direccion`, `email`
) VALUES (
  'PROVEEDOR PRUEBA 533',
  'PROVEEDOR PRUEBA 533',
  '533445861',
  'PROVEEDOR PRUEBA 533',
  'Direccion de prueba',
  'fase2-prov@example.com'
)
ON DUPLICATE KEY UPDATE
  `rnc` = VALUES(`rnc`),
  `razon_social` = VALUES(`razon_social`);

COMMIT;

-- =============================================================================
-- Para confirmar: SELECT id, rnc, razon_social FROM emisor_config WHERE id=1;
--                 SELECT id, rnc, razon_social FROM clients WHERE rnc IN ('131880681','533445861');
-- =============================================================================
