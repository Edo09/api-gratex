-- ============================================================================
-- rescate_E310000000058.sql — registra en Gratex (tenant 1) el E31 que la DGII
-- recibio el 2026-10-08 pero que no quedo guardado en facturas.
-- ============================================================================
-- QUE PASO:
--   Prueba V-1 del POS (docs/specs/pos.md §11, tests/test_pos_fase0_v1.http,
--   paso 2). El request no mandaba user_id; en produccion facturas.user_id es
--   NOT NULL, asi que el INSERT fallo DESPUES de que la DGII ya habia recibido
--   el e-CF: "Column 'user_id' cannot be null" (audit_logs, 2026-10-08 10:22:49).
--
-- LO QUE DICE LA DGII (tools/consultar_ecf_dgii.php, 2026-10-08 10:25):
--   E310000000058 · Aceptado Condicional (codigo 4) · secuenciaUtilizada true
--   trackId 1f3b5ab6-51d4-4460-9487-f41323e5ff0f · recibido 08/10/2026 11:22:49
--   1385 "El campo RNCComprador del area Comprador ... no es valido" (131880681
--   es el comprador de los sets de certificacion, no un contribuyente real).
--   Ningun monto objetado: MontoGravadoI1 25.42, TotalITBIS1 4.58, MontoExento
--   30.00, MontoTotal 60.00 con IndicadorMontoGravado = 1.
--
-- LO QUE SE PERDIO: el XML firmado y el codigo de seguridad (no se guardan si
-- el INSERT falla, y ConsultaEstado exige el codigo para devolverlo). La RI de
-- este comprobante sale sin timbre valido. No importa: se anula con una E34
-- codigo 1 por RD$60 (decision del 2026-10-08).
--
-- LAS LINEAS: las mismas que se firmaron, calculadas con EcfItemMapper
-- (precios con ITBIS): subtotal = base sin ITBIS, itbis_amount aparte.
--   3 x 10.00 gravado 18% -> base 25.42 + ITBIS 4.58 = 30.00
--   2 x 15.00 exento      -> 30.00
--
-- FECHAS: la hora del server al emitir (audit 10:22:49, -05:00); la DGII lo
-- registro 11:22:49 en su hora (-04:00). Mismo instante.
--
-- COMO CORRERLA (phpMyAdmin): base del tenant Gratex, pestana SQL, pegar TODO.
--   Se puede correr dos veces: si E310000000058 ya esta, no inserta nada.
--   El resultado final muestra la factura y sus 2 lineas.
--   "Table '...facturas' doesn't exist" = no era la base de Gratex.
-- ============================================================================

START TRANSACTION;

INSERT INTO facturas
  (no_factura, date, client_id, client_name, total, tipo_pago, NCF, tipo_ecf,
   e_ncf, track_id, estado_dgii, codigo_seguridad, fecha_emision_dgii,
   ambiente_dgii, xml_firmado, respuesta_dgii, secuencia_utilizada, user_id)
SELECT 'E310000000058', '2026-10-08 10:22:49', c.id, c.client_name, 60.00, 1, NULL, '31',
       'E310000000058', '1f3b5ab6-51d4-4460-9487-f41323e5ff0f', 'ACEPTADO_CONDICIONAL', NULL,
       '2026-10-08 10:22:48', 'ecf', NULL,
       '{"trackId":"1f3b5ab6-51d4-4460-9487-f41323e5ff0f","codigo":"4","estado":"Aceptado Condicional","rnc":"131256432","encf":"E310000000058","secuenciaUtilizada":true,"fechaRecepcion":"10/8/2026 11:22:49 AM","mensajes":[{"valor":"El campo RNCComprador del área Comprador de la sección Encabezado no es válido.","codigo":1385}],"rescate":"INSERT manual 2026-10-08: el guardado original fallo (user_id NULL); XML y codigo de seguridad perdidos"}',
       1, 2
FROM clients c
WHERE c.id = 3511
  AND NOT EXISTS (SELECT 1 FROM facturas f WHERE f.e_ncf = 'E310000000058' AND f.ambiente_dgii = 'ecf');

SET @fid := (SELECT id FROM facturas WHERE e_ncf = 'E310000000058' AND ambiente_dgii = 'ecf');

INSERT INTO factura_items
  (factura_id, product_id, description, amount, quantity, subtotal, descuento_monto,
   indicador_facturacion, indicador_bien_servicio, unidad_medida, itbis_amount)
SELECT @fid, NULL, 'Prueba POS gravado', 10.0000, 3.000, 25.42, 0.00, 1, 1, '43', 4.58
FROM DUAL
WHERE @fid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM factura_items WHERE factura_id = @fid)
UNION ALL
SELECT @fid, NULL, 'Prueba POS exento', 15.0000, 2.000, 30.00, 0.00, 4, 1, '43', 0.00
FROM DUAL
WHERE @fid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM factura_items WHERE factura_id = @fid);

COMMIT;

-- Resultado: la factura (1 fila) y sus lineas (2 filas, suman 55.42 + 4.58 = 60.00).
SELECT f.id, f.e_ncf, f.estado_dgii, f.total, f.user_id, f.client_id,
       i.description, i.amount, i.quantity, i.subtotal, i.itbis_amount
FROM facturas f
JOIN factura_items i ON i.factura_id = f.id
WHERE f.e_ncf = 'E310000000058' AND f.ambiente_dgii = 'ecf'
ORDER BY i.id;
