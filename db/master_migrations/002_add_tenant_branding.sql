-- =============================================================================
-- Master Migration 002: Branding de la Representacion Impresa por tenant.
--
-- Ejecutar contra la base MASTER (gratex_master), UNA sola vez.
--
-- Cada tenant elige una plantilla de factura (clasico | moderno | compacto |
-- custom:<nombre> para disenos a la medida en src/Utils/Pdf/Custom/) y un
-- color de acento opcional. Los defaults dejan a los tenants existentes
-- renderizando igual que hoy (plantilla clasico, sin acento).
--
-- Es ADITIVO. Solo agrega columnas. Si la columna ya existe, el ALTER falla
-- completo (ejecutar solo una vez).
-- =============================================================================

ALTER TABLE `tenants`
  ADD COLUMN `pdf_template` VARCHAR(40) NOT NULL DEFAULT 'clasico'
    COMMENT 'Plantilla Representacion Impresa: clasico | moderno | compacto | custom:<nombre>'
    AFTER `logo_path`,
  ADD COLUMN `pdf_accent_color` CHAR(7) NULL
    COMMENT 'Color de acento hex #RRGGBB (NULL = colores por defecto de la plantilla)'
    AFTER `pdf_template`;
