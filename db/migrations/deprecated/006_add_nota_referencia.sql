-- =============================================================================
-- Migration 006: Persistir InformacionReferencia de Notas de Debito (E33) y
-- Credito (E34) para mostrarla en la Representacion Impresa (norma DGII).
--
-- La DGII exige que la RI de una nota muestre el NCF Modificado y el Motivo de
-- la modificacion. Esos datos se envian en `informacion_referencia` al emitir,
-- pero hasta ahora no se guardaban en la tabla `facturas`, por lo que el PDF no
-- podia mostrarlos. Esta migracion agrega las columnas para persistirlos.
--
-- Es ADITIVO. Solo agrega columnas. NULL = no aplica (no es nota / sin datos).
-- Optimizado para MySQL 8: un solo ALTER, DDL auto-commit. Si las columnas ya
-- existen, el ALTER falla completo (ejecutar solo una vez).
-- =============================================================================

ALTER TABLE `facturas`
  ADD COLUMN `ncf_modificado` VARCHAR(19) NULL
    COMMENT 'e-NCF del comprobante que esta nota (E33/E34) modifica',
  ADD COLUMN `fecha_ncf_modificado` DATE NULL
    COMMENT 'Fecha de emision del NCF modificado',
  ADD COLUMN `codigo_modificacion` VARCHAR(2) NULL
    COMMENT '1=Anula | 2=Corrige texto | 3=Corrige montos | 4=Reemplazo contingencia | 5=Ref. Factura Consumo',
  ADD COLUMN `razon_modificacion` VARCHAR(90) NULL
    COMMENT 'Motivo/descripcion de la modificacion (se muestra en la RI)';
