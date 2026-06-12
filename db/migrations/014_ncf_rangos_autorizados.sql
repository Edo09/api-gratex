-- ============================================================================
-- 014_ncf_rangos_autorizados.sql — Secuencias e-NCF como RANGOS autorizados DGII.
-- ============================================================================
-- Modelo real DGII: por cada tipo se SOLICITA un rango (Numero Desde / Numero
-- Hasta, con No. Autorizacion y Fecha Vencimiento). Cuando el rango se agota se
-- solicita otro y se registra. Una fila de ncf_sequences pasa a representar UN
-- rango autorizado:
--   - numero_desde / numero_hasta : limites del rango (hasta NULL = sin limite,
--     comportamiento legacy usado en certecf/testecf y mientras no se registre
--     el rango real).
--   - current_value               : ultimo numero dispensado (convencion previa).
--     En rangos nuevos se inicializa en numero_desde - 1.
--   - fecha_vencimiento           : vence el rango (va al XML como
--     FechaVencimientoSecuencia, con fallback a emisor_config).
--   - no_solicitud / no_autorizacion : referencia DGII de la aprobacion.
--
-- El dispensador toma el rango ACTIVO (con capacidad y no vencido, menor
-- numero_desde) e incrementa acotado por numero_hasta. Ver ncfModel.
--
-- Para DBs de tenant YA desplegados. Los tenants nuevos lo reciben via
-- db/tenant_schema.sql (seccion 4). Aplicado en mtldtmte_new_gratexdb 2026-06-11.
-- ============================================================================

ALTER TABLE ncf_sequences
  ADD COLUMN numero_desde INT(11) NOT NULL DEFAULT 1
    COMMENT 'Inicio del rango autorizado por DGII' AFTER current_value,
  ADD COLUMN numero_hasta INT(11) NULL
    COMMENT 'Fin del rango autorizado; NULL = sin limite (legacy/pruebas)' AFTER numero_desde,
  ADD COLUMN fecha_vencimiento DATE NULL
    COMMENT 'Vencimiento del rango (FechaVencimientoSecuencia del XML)' AFTER numero_hasta,
  ADD COLUMN no_solicitud VARCHAR(20) NULL
    COMMENT 'No. Solicitud DGII del rango' AFTER fecha_vencimiento,
  ADD COLUMN no_autorizacion VARCHAR(20) NULL
    COMMENT 'No. Autorizacion DGII del rango' AFTER no_solicitud;

-- Varios rangos por tipo+ambiente: la unicidad pasa a incluir numero_desde.
ALTER TABLE ncf_sequences DROP INDEX uq_type_ambiente;
ALTER TABLE ncf_sequences ADD UNIQUE KEY uq_type_amb_desde (type, ambiente, numero_desde);

-- Rango real aprobado por DGII para E31 en produccion (solicitud 02/06/2026):
-- E310000000001..E310000000100, vence 31/12/2027.
UPDATE ncf_sequences
SET numero_desde = 1,
    numero_hasta = 100,
    fecha_vencimiento = '2027-12-31',
    no_solicitud = '6009804999',
    no_autorizacion = '6005308087'
WHERE type = 'E31' AND ambiente = 'ecf';

-- Los demas tipos/ambientes quedan con numero_hasta NULL (sin limite) hasta que
-- se registre su rango real via POST /api/ncf/rangos.
