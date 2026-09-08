-- ============================================================================
-- Tipo de Bienes y Servicios Comprados (campo 3 del Formato 606 DGII)
--
-- Hasta ahora el 606 estampaba '09' en TODAS las filas
-- (Reporte606Model::TIPO_BIENES_SERVICIOS_DEFAULT). Con esto el usuario elige
-- el tipo al registrar el gasto/compra y el reporte lo declara de verdad.
--
-- OJO: son DOS bases distintas. Corre cada parte donde toca.
-- ============================================================================


-- ============================================================================
-- PARTE 1 — Base MASTER (gratex_master)
-- Catalogo compartido por todos los tenants, igual que dgii_provincia_municipio
-- y unidades_medida. Se corre UNA sola vez.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `dgii_tipo_bienes_servicios` (
  `codigo` CHAR(2) NOT NULL COMMENT 'Codigo DGII 01..11 tal como va al campo 3 del 606',
  `descripcion` VARCHAR(120) NOT NULL,
  `activo` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = retirado por DGII; se conserva por los gastos historicos que lo usan',
  PRIMARY KEY (`codigo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ON DUPLICATE KEY: re-ejecutar la migracion refresca las descripciones sin
-- duplicar filas ni romper los gastos que ya apuntan a un codigo.
INSERT INTO `dgii_tipo_bienes_servicios` (`codigo`, `descripcion`) VALUES
  ('01', 'Gastos de personal'),
  ('02', 'Gastos por trabajos, suministros y servicios'),
  ('03', 'Arrendamientos'),
  ('04', 'Gastos de activos fijos'),
  ('05', 'Gastos de representación'),
  ('06', 'Otras deducciones admitidas'),
  ('07', 'Gastos financieros'),
  ('08', 'Gastos extraordinarios'),
  ('09', 'Compras y gastos que formarán parte del costo de venta'),
  ('10', 'Adquisiciones de activos'),
  ('11', 'Gastos de seguros')
ON DUPLICATE KEY UPDATE `descripcion` = VALUES(`descripcion`);


-- ============================================================================
-- PARTE 2 — Base de CADA TENANT (smhynzte_new_gratexdb, y la de cada tenant
-- que exista). Hay que correrla en TODAS: la columna vive junto a los gastos.
-- ============================================================================

-- NULL = gasto anterior a este cambio. El 606 los sigue declarando como '09',
-- que es lo que se venia reportando, hasta que alguien los edite y elija.
-- No se pone NOT NULL para no inventar un tipo en el historico.
ALTER TABLE `gastos`
  ADD COLUMN `tipo_bienes_servicios` CHAR(2) NULL DEFAULT NULL
  COMMENT 'Codigo DGII 01..11 (campo 3 del 606). NULL = historico previo al campo'
  AFTER `tipo_gasto`;

-- El 606 agrupa y filtra por este campo al revisar un periodo.
ALTER TABLE `gastos`
  ADD INDEX `idx_tipo_bienes_servicios` (`tipo_bienes_servicios`);


-- ============================================================================
-- Verificacion
-- ============================================================================
-- En master:
--   SELECT * FROM dgii_tipo_bienes_servicios ORDER BY codigo;   -- 11 filas
-- En cada tenant:
--   SHOW COLUMNS FROM gastos LIKE 'tipo_bienes_servicios';
--   SELECT tipo_bienes_servicios, COUNT(*) FROM gastos GROUP BY 1;
