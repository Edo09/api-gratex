-- Backfill InformacionReferencia (NCF modificado / motivo) para las notas
-- E33/E34 del run final de fase 4. Valores extraidos del XML firmado real
-- (lo que DGII valido). Generado: 2026-05-30 20:33:45

UPDATE facturas SET ncf_modificado = 'E310000000358', fecha_ncf_modificado = '2026-05-28', codigo_modificacion = '3', razon_modificacion = 'Nota de debito por ajuste de monto' WHERE id = 1277; -- E330000000310
UPDATE facturas SET ncf_modificado = 'E310000000359', fecha_ncf_modificado = '2026-05-28', codigo_modificacion = '3', razon_modificacion = 'Nota de credito por ajuste de monto' WHERE id = 1278; -- E340000000329
UPDATE facturas SET ncf_modificado = 'E310000000360', fecha_ncf_modificado = '2026-05-28', codigo_modificacion = '3', razon_modificacion = 'Nota de credito por ajuste de monto' WHERE id = 1279; -- E340000000330
