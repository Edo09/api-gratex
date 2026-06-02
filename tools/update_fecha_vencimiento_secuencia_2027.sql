-- =============================================================================
-- Corrige FechaVencimientoSecuencia del e-CF.
--
-- DGII rechazaba con codigo 145 "Fecha de vencimiento de secuencia invalida"
-- porque el XML enviaba <FechaVencimientoSecuencia>31-12-2028</...> (valor de
-- la fase2 de certificacion, migracion 004) pero la autorizacion de secuencias
-- en produccion (ambiente 'ecf') vence el 31-12-2027.
--
-- El valor sale de emisor_config.fecha_vencimiento_secuencia (ver
-- ECFEmissionService::emitir). Se ajusta a la fecha autorizada por DGII.
--
-- Ejecutar UNA vez en el servidor (mtldtmte_new_gratexdb).
-- =============================================================================

UPDATE `emisor_config`
SET `fecha_vencimiento_secuencia` = '2027-12-31'
WHERE `id` = 1;
