-- =============================================================================
-- Migration 009: Persistir la Aprobacion/Rechazo Comercial SALIENTE que enviamos
-- a la DGII sobre un e-CF que recibimos (rol comprador).
--
-- Antes: POST /api/aprobaciones-comerciales mandaba el ACECF a DGII pero no
-- guardaba nada, por lo que la lista de e-CF recibidos siempre mostraba el
-- estado tecnico (ACEPTADO por firma) y nunca reflejaba si el comprador habia
-- aceptado o rechazado comercialmente. Esta migracion agrega columnas para
-- persistir la decision y la respuesta de DGII (RespuestaAprobacionComercial).
--
-- DGII responde { codigo, estado, mensaje[] }:
--   codigo 1  = aprobacion comercial procesada correctamente
--   codigo 2  = no se pudo procesar (factura no encontrada / error tecnico)
--
-- Es ADITIVO. Solo agrega columnas. NULL = sin respuesta comercial enviada
-- (el front debe mostrar "Pendiente", no "Aprobada" por default).
-- Optimizado para MySQL 8: un solo ALTER, DDL auto-commit. Si las columnas ya
-- existen, el ALTER falla completo (ejecutar solo una vez).
-- =============================================================================

ALTER TABLE `ecf_recibidos`
  ADD COLUMN `aprobacion_comercial` VARCHAR(20) NULL
    COMMENT 'Decision del comprador: ACEPTADO | RECHAZADO. NULL = sin responder',
  ADD COLUMN `aprobacion_comercial_detalle` VARCHAR(500) NULL
    COMMENT 'Motivo del rechazo comercial (requerido por DGII cuando se rechaza)',
  ADD COLUMN `aprobacion_comercial_codigo_dgii` VARCHAR(5) NULL
    COMMENT 'codigo de RespuestaAprobacionComercial (1=procesada, 2=no procesada)',
  ADD COLUMN `aprobacion_comercial_estado_dgii` VARCHAR(120) NULL
    COMMENT 'estado textual devuelto por DGII (ej: "Aprobacion Comercial Rechazada.")',
  ADD COLUMN `aprobacion_comercial_mensaje_dgii` VARCHAR(500) NULL
    COMMENT 'mensaje[] de DGII unido por " | "',
  ADD COLUMN `aprobacion_comercial_procesada` TINYINT(1) NULL
    COMMENT '1 si DGII codigo=1 (procesada OK), 0 si no se pudo procesar',
  ADD COLUMN `aprobacion_comercial_fecha` DATETIME NULL
    COMMENT 'Fecha/hora en que se envio la aprobacion/rechazo a DGII';
