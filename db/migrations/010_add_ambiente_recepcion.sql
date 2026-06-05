-- =============================================================================
-- Migration 010: Guardar el ambiente DGII en las tablas de recepcion.
--
-- ecf_recibidos y aprobaciones_comerciales no registraban en que ambiente
-- (testecf | certecf | ecf) llego el documento, por lo que no se podia saber
-- a que ambiente apuntar la aprobacion comercial saliente (causa de codigo 02
-- por mismatch de ambiente). Esta migracion agrega la columna y se llena con
-- getenv('DGII_ECF_ENVIRONMENT') al momento de recibir (best-effort: refleja el
-- modo del server cuando llego el documento, no un dato del XML).
--
-- Es ADITIVO. Solo agrega columnas. NULL = ambiente desconocido (filas previas).
-- Optimizado para MySQL 8: DDL auto-commit. Si la columna ya existe, el ALTER
-- falla completo (ejecutar solo una vez).
-- =============================================================================

ALTER TABLE `ecf_recibidos`
  ADD COLUMN `ambiente` VARCHAR(20) NULL
    COMMENT 'testecf | certecf | ecf — modo del server al recibir el e-CF';

ALTER TABLE `aprobaciones_comerciales`
  ADD COLUMN `ambiente` VARCHAR(20) NULL
    COMMENT 'testecf | certecf | ecf — modo del server al recibir la aprobacion';
