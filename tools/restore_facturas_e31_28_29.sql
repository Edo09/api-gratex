-- ============================================================================
-- Restauracion de facturas perdidas en la migracion de DB, reconstruidas desde
-- su representacion impresa (PDF): E310000000028 y E310000000029.
--
-- DB destino: DB del tenant Gratex (mtldtmte_new_gratexdb).
-- Fuente: factura-E310000000028.pdf / factura-E310000000029.pdf
--
-- Verificado contra el server antes de generar (2026-08-04):
--   - Ninguno de los dos e-NCF existe en la DB nueva (no hay colision).
--   - clients.rnc 132755448 -> id 3524 (Juan Marco Jimenez / Piezas e Instalaciones EIRL)
--   - clients.rnc 131766218 -> id 3525 (Nicole / Soluciones Megadu)
--   - ncf_sequences E31/ecf ya tiene current_value = 29, o sea que la secuencia
--     NO se re-emitira; el UPDATE del final es solo un seguro.
--
-- NO recuperable desde el PDF (solo existia en la DB perdida): xml_firmado,
-- track_id, respuesta_dgii. Quedan en NULL. Conservar los PDF como respaldo
-- de la representacion impresa.
--
-- Re-ejecutable: cada INSERT lleva guarda NOT EXISTS.
-- ============================================================================

START TRANSACTION;

-- ---------------------------------------------------------------------------
-- E310000000028 — Piezas e Instalaciones EIRL (RNC 132755448)
--   7 x Vaso Personalizados @ 800.00 = 5,600.00 + ITBIS 18% 1,008.00 = 6,608.00
-- ---------------------------------------------------------------------------
INSERT INTO facturas
  (no_factura, date, client_id, client_name, total, NCF, tipo_ecf, e_ncf,
   track_id, estado_dgii, codigo_seguridad, fecha_emision_dgii, ambiente_dgii,
   xml_firmado, respuesta_dgii, secuencia_utilizada, user_id)
SELECT
  'E310000000028',
  '2026-08-01 11:52:22',
  (SELECT id FROM clients WHERE rnc = '132755448' LIMIT 1),
  COALESCE((SELECT client_name FROM clients WHERE rnc = '132755448' LIMIT 1),
           'Juan Marco Jimenez'),
  6608.00,
  NULL,
  '31',
  'E310000000028',
  NULL,
  'ACEPTADO',
  'RHq/pJ',
  '2026-08-01 11:52:22',
  'ecf',
  NULL,
  NULL,
  1,
  33
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM facturas WHERE e_ncf = 'E310000000028');

INSERT INTO factura_items
  (factura_id, description, amount, quantity, subtotal,
   indicador_facturacion, indicador_bien_servicio, unidad_medida, itbis_amount)
SELECT
  f.id,
  CONCAT('Vaso Personalizados', CHAR(10), 'Set de vasos personalizados'),
  800.00,
  7,
  5600.00,
  1,     -- ITBIS 18%
  1,     -- Bien
  '43',  -- UND
  1008.00
FROM facturas f
WHERE f.e_ncf = 'E310000000028'
  AND NOT EXISTS (SELECT 1 FROM factura_items fi WHERE fi.factura_id = f.id);

-- ---------------------------------------------------------------------------
-- E310000000029 — Soluciones Megadu (RNC 131766218)
--   13 x Carnet Personalizados @ 350.00 = 4,550.00 + ITBIS 18% 819.00 = 5,369.00
-- ---------------------------------------------------------------------------
INSERT INTO facturas
  (no_factura, date, client_id, client_name, total, NCF, tipo_ecf, e_ncf,
   track_id, estado_dgii, codigo_seguridad, fecha_emision_dgii, ambiente_dgii,
   xml_firmado, respuesta_dgii, secuencia_utilizada, user_id)
SELECT
  'E310000000029',
  '2026-08-01 13:14:12',
  (SELECT id FROM clients WHERE rnc = '131766218' LIMIT 1),
  COALESCE((SELECT client_name FROM clients WHERE rnc = '131766218' LIMIT 1),
           'Nicole'),
  5369.00,
  NULL,
  '31',
  'E310000000029',
  NULL,
  'ACEPTADO',
  'HxLuCh',
  '2026-08-01 13:14:12',
  'ecf',
  NULL,
  NULL,
  1,
  33
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM facturas WHERE e_ncf = 'E310000000029');

INSERT INTO factura_items
  (factura_id, description, amount, quantity, subtotal,
   indicador_facturacion, indicador_bien_servicio, unidad_medida, itbis_amount)
SELECT
  f.id,
  CONCAT('Carnet Personalizados', CHAR(10), 'Set de carnet + porta carnet'),
  350.00,
  13,
  4550.00,
  1,     -- ITBIS 18%
  1,     -- Bien
  '43',  -- UND
  819.00
FROM facturas f
WHERE f.e_ncf = 'E310000000029'
  AND NOT EXISTS (SELECT 1 FROM factura_items fi WHERE fi.factura_id = f.id);

-- ---------------------------------------------------------------------------
-- Seguro de secuencia: el dispensador no debe volver a emitir <= 29.
-- current_value = ultimo numero dispensado. GREATEST solo sube, nunca baja,
-- asi que es inofensivo si ya esta en 29 (que es el caso hoy).
-- ---------------------------------------------------------------------------
UPDATE ncf_sequences
SET current_value = GREATEST(current_value, 29)
WHERE type = 'E31' AND ambiente = 'ecf';

COMMIT;

-- ============================================================================
-- Verificacion (correr despues del COMMIT)
-- ============================================================================
SELECT f.id, f.e_ncf, f.date, f.client_id, f.client_name, f.total,
       f.codigo_seguridad, f.estado_dgii, f.ambiente_dgii,
       fi.quantity, fi.amount, fi.subtotal, fi.itbis_amount, fi.description
FROM facturas f
LEFT JOIN factura_items fi ON fi.factura_id = f.id
WHERE f.e_ncf IN ('E310000000028', 'E310000000029');

SELECT type, ambiente, current_value, numero_desde, numero_hasta
FROM ncf_sequences
WHERE type = 'E31' AND ambiente = 'ecf';
