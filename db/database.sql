-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 01, 2024 at 01:12 PM
-- Server version: 10.4.28-MariaDB
-- PHP Version: 8.2.4

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `test`
--
CREATE DATABASE IF NOT EXISTS `test` DEFAULT CHARACTER SET latin1 COLLATE latin1_swedish_ci;
USE `test`;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(70) NOT NULL,
  `last_name` varchar(70) NOT NULL,
  `email` varchar(300) NOT NULL,
  `username` varchar(50) NOT NULL UNIQUE,
  `password` varchar(255) NOT NULL,
  `role` varchar(20) DEFAULT 'user'
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `last_name`, `email`, `username`, `password`, `role`) VALUES
(1, 'Alex', 'Smith', 'alexsmith@example.com', 'alex_smith', '$2y$10$YIjlrBQOfj.G8OtQmL8KUeLKKZXYEnBjy/Zr.fHmlL7TQKgD3VyW6', 'user'),
(2, 'Jordan', 'Johnson', 'jordanjohnson@mail.com', 'jordan_j', '$2y$10$YIjlrBQOfj.G8OtQmL8KUeLKKZXYEnBjy/Zr.fHmlL7TQKgD3VyW6', 'user'),
(3, 'Taylor', 'Williams', 'taylorwilliams@test.com', 'taylor_w', '$2y$10$YIjlrBQOfj.G8OtQmL8KUeLKKZXYEnBjy/Zr.fHmlL7TQKgD3VyW6', 'admin'),
(4, 'Morgan', 'Jones', 'morganjones@example.com', 'morgan_j', '$2y$10$YIjlrBQOfj.G8OtQmL8KUeLKKZXYEnBjy/Zr.fHmlL7TQKgD3VyW6', 'user'),
(5, 'Casey', 'Brown', 'caseybrown@mail.com', 'casey_b', '$2y$10$YIjlrBQOfj.G8OtQmL8KUeLKKZXYEnBjy/Zr.fHmlL7TQKgD3VyW6', 'user'),
(6, 'Jamie', 'Davis', 'jamiedavis@test.com', 'jamie_d', '$2y$10$YIjlrBQOfj.G8OtQmL8KUeLKKZXYEnBjy/Zr.fHmlL7TQKgD3VyW6', 'user'),
(7, 'Drew', 'Miller', 'drewmiller@example.com', 'drew_m', '$2y$10$YIjlrBQOfj.G8OtQmL8KUeLKKZXYEnBjy/Zr.fHmlL7TQKgD3VyW6', 'user'),
(8, 'Chris', 'Wilson', 'chriswilson@mail.com', 'chris_w', '$2y$10$YIjlrBQOfj.G8OtQmL8KUeLKKZXYEnBjy/Zr.fHmlL7TQKgD3VyW6', 'user'),
(9, 'Pat', 'Moore', 'patmoore@test.com', 'pat_m', '$2y$10$YIjlrBQOfj.G8OtQmL8KUeLKKZXYEnBjy/Zr.fHmlL7TQKgD3VyW6', 'user'),
(10, 'Dana', 'Taylor', 'danataylor@example.com', 'dana_t', '$2y$10$YIjlrBQOfj.G8OtQmL8KUeLKKZXYEnBjy/Zr.fHmlL7TQKgD3VyW6', 'user');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

-- --------------------------------------------------------

--
-- Table structure for table `api_tokens`
--

CREATE TABLE `api_tokens` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `created_at` datetime NOT NULL,
  `last_used` datetime DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Indexes for table `api_tokens`
--
ALTER TABLE `api_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token_hash` (`token_hash`),
  ADD KEY `user_id` (`user_id`);

--
-- AUTO_INCREMENT for table `api_tokens`
--
ALTER TABLE `api_tokens`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for table `api_tokens`
--
ALTER TABLE `api_tokens`
  ADD CONSTRAINT `api_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

-- --------------------------------------------------------

--
-- Table structure for table `clients`
--

CREATE TABLE `clients` (
  `id` int(11) NOT NULL,
  `email` varchar(100) NOT NULL,
  `client_name` varchar(100) NOT NULL,
  `company_name` varchar(100) NOT NULL,
  `phone_number` varchar(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `clients`
--

INSERT INTO `clients` (`id`, `email`, `client_name`, `company_name`, `phone_number`) VALUES
(1, 'mhamsleyt@ask.com', 'Caron Melley', 'Morgan Stanley', '(555) 705-1678'),
(2, 'kmitchely62@msu.edu', 'Eustace Guy', 'Walt Disney', '(555) 446-8152'),
(3, 'ahucklesby1v@ucsd.edu', 'Eugene Foale', 'Goldman Sachs Group', '(555) 297-8883'),
(4, 'restick1k@sohu.com', 'Eugenius Feldheim', 'Johnson & Johnson', '(555) 710-5327'),
(5, 'hmasarrat3z@csmonitor.com', 'Niccolo Whitsey', 'Retool', '(555) 593-6651'),
(6, 'sbohey1y@twitter.com', 'Itch Whitsey', 'Segment', '(555) 552-9097'),
(7, 'mharken63@nymag.com', 'Lyndsey Wilber', 'Front', '(555) 591-6017'),
(8, 'mcassell7@japanpost.jp', 'Von Fissenden', 'Home Depot', '(555) 437-7972'),
(9, 'nrogeon5o@studiopress.com', 'Darrin De Robertis', 'American Express', '(555) 556-3418'),
(10, 'bpycock5@myspace.com', 'Armin Stachini', 'Anthem', '(555) 590-6887'),
(11, 'balbinw@comcast.net', 'Eugenius Bohey', 'Cisco Systems', '(555) 648-1786'),
(12, 'chattersley48@about.com', 'Nissa Menzies', 'Gong', '(555) 510-1625'),
(13, 'fkildea4r@ucsd.edu', 'Stefania Brizell', 'AT&T', '(555) 156-3980'),
(14, 'iloache38@photobucket.com', 'Lorelle Godmar', 'GitHub', '(555) 563-5912'),
(15, 'ssambiedgen@europa.eu', 'Ettie Guy', 'UnitedHealth Group', '(555) 807-8819'),
(16, 'bciani1b@wikimedia.org', 'Mar Tomkin', 'Kroger', '(555) 543-3257'),
(17, 'tmenzies6p@hexun.com', 'Herb Spellissy', 'Bank of America', '(555) 534-8272'),
(18, 'pleverage1z@salon.com', 'Briant Boshers', 'Stripe', '(555) 159-4996'),
(19, 'mcassell7@japanpost.jp', 'Ferdinande Texton', 'Uber', '(555) 369-7078'),
(20, 'amelley65@rambler.ru', 'Harmony Gecks', 'Comcast', '(555) 513-3637'),
(21, 'ldoyle6k@sourceforge.net', 'Cosette Reasce', 'Progressive', '(555) 195-9946'),
(22, 'cfoale16@oracle.com', 'Carter Sleite', 'Citigroup', '(555) 535-2655'),
(23, 'vkubach6t@smh.com.au', 'Gilemette Anersen', 'Superhuman', '(555) 884-9012'),
(24, 'njakubowsky5l@samsung.com', 'Dave Duquesnay', 'Allstate', '(555) 997-5268'),
(25, 'bfifield2v@amazonaws.com', 'Seth Stovine', 'Dropbox', '(555) 356-1125'),
(26, 'ecraiker58@mapy.cz', 'Ettie Bohey', 'Facebook', '(555) 995-8602'),
(27, 'kturton37@github.com', 'Nicola Berryann', 'Salesforce', '(555) 802-8695'),
(28, 'tohengerty2s@soundcloud.com', 'Joyann Brizell', 'Postmates', '(555) 412-2904'),
(29, 'lstovine51@irs.gov', 'Micah Weatherburn', 'Procter & Gamble', '(555) 194-7327'),
(30, 'acarnoghan25@economist.com', 'Costa Harriott', 'Brex', '(555) 724-4262'),
(31, 'kmackegg3n@hugedomains.com', 'Bern Martinon', 'Stripe', '(555) 565-8972'),
(32, 'ebiggin6v@vkontakte.ru', 'Beatrice Halward', 'Citigroup', '(555) 551-7270'),
(33, 'ceilles29@google.ru', 'Kenyon Fifield', 'Wells Fargo', '(555) 956-2686'),
(34, 'elauks15@vkontakte.ru', 'Gay Weatherburn', 'Dell Technologies', '(555) 202-8964'),
(35, 'ldoyle6k@sourceforge.net', 'Pepi Benjefield', 'Home Depot', '(555) 506-9134'),
(36, 'jgimson2@un.org', 'Christoph Bonass', 'Allstate', '(555) 456-3219'),
(37, 'gdavidi5f@chron.com', 'Hildagarde Shields', 'Segment', '(555) 571-5100'),
(38, 'prawlingson5m@imgur.com', 'Tomas Hollyland', 'Apple', '(555) 497-4830'),
(39, 'nwhitsey6j@wufoo.com', 'Inga Anersen', 'Goldman Sachs Group', '(555) 656-7205'),
(40, 'jmaccafferky57@archive.org', 'Auria Littrell', 'Facebook', '(555) 580-7203'),
(41, 'hscherme5a@aol.com', 'Cosette Gisburn', 'General Motors', '(555) 444-8471'),
(42, 'tmenzies6p@hexun.com', 'Sullivan Scanterbury', 'Facebook', '(555) 857-1068'),
(43, 'tveare5w@ameblo.jp', 'Anatollo Halward', 'Ford Motor', '(555) 959-4503'),
(44, 'mfilon32@hc360.com', 'Fey Queree', 'Goldman Sachs Group', '(555) 299-3539'),
(45, 'gwerner4h@so-net.ne.jp', 'Niccolo Windrum', 'Ford Motor', '(555) 633-3867'),
(46, 'lstovine51@irs.gov', 'Garik Coton', 'JP Morgan Chase', '(555) 793-2301'),
(47, 'ddobbisonq@hubpages.com', 'Halie Iorizzo', 'Wells Fargo', '(555) 954-9428'),
(48, 'ntrousdale35@sphinn.com', 'Niccolo Olenchenko', 'Salesforce', '(555) 817-1422'),
(49, 'jpeter5j@gravatar.com', 'Booth Kiddey', 'Berkshire Hathaway', '(555) 401-2421'),
(50, 'tbanger23@sbwire.com', 'Renata Bergeon', 'Twitter', '(555) 970-7210');

--
-- Indexes for table `clients`
--
ALTER TABLE `clients`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for table `clients`
--
ALTER TABLE `clients`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=51;

-- --------------------------------------------------------

--
-- Table structure for table `cotizaciones`
--

CREATE TABLE `cotizaciones` (
  `id` int(11) NOT NULL,
  `code` varchar(50) NOT NULL,
  `date` datetime DEFAULT CURRENT_TIMESTAMP,
  `client_id` int(11) DEFAULT NULL,
  `client_name` varchar(100) NOT NULL,
  `total` decimal(10,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Indexes for table `cotizaciones`
--
ALTER TABLE `cotizaciones`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for table `cotizaciones`
--
ALTER TABLE `cotizaciones`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

-- --------------------------------------------------------

--
-- Table structure for table `cotizacion_items`
--

CREATE TABLE `cotizacion_items` (
  `id` int(11) NOT NULL,
  `cotizacion_id` int(11) NOT NULL,
  `description` text NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `subtotal` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Indexes for table `cotizacion_items`
--
ALTER TABLE `cotizacion_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `cotizacion_id` (`cotizacion_id`);

--
-- AUTO_INCREMENT for table `cotizacion_items`
--
ALTER TABLE `cotizacion_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=36;

--
-- Constraints for table `cotizacion_items`
--
ALTER TABLE `cotizacion_items`
  ADD CONSTRAINT `cotizacion_items_ibfk_1` FOREIGN KEY (`cotizacion_id`) REFERENCES `cotizaciones` (`id`) ON DELETE CASCADE;


INSERT INTO `cotizaciones` (`id`, `code`, `date`, `client_id`, `client_name`, `total`) VALUES
(1, 'COT_20251201_001', '2025-12-01 09:30:00', 1, 'Caron Melley', 1250.00),
(2, 'COT_20251201_002', '2025-12-01 11:45:00', 2, 'Eustace Guy', 3500.00),
(3, 'COT_20251202_001', '2025-12-02 08:15:00', 3, 'Eugene Foale', 780.50),
(4, 'COT_20251202_002', '2025-12-02 14:20:00', 4, 'Eugenius Feldheim', 2100.00),
(5, 'COT_20251203_001', '2025-12-03 10:00:00', 5, 'Niccolo Whitsey', 4500.00),
(6, 'COT_20251203_002', '2025-12-03 16:30:00', 6, 'Itch Whitsey', 890.00),
(7, 'COT_20251204_001', '2025-12-04 09:00:00', 7, 'Lyndsey Wilber', 1675.50),
(8, 'COT_20251205_001', '2025-12-05 11:00:00', 8, 'Von Fissenden', 3200.00),
(9, 'COT_20251206_001', '2025-12-06 13:45:00', 9, 'Darrin De Robertis', 550.00),
(10, 'COT_20251207_001', '2025-12-07 15:30:00', 10, 'Armin Stachini', 2850.00),
(11, 'COT_20251208_001', '2025-12-08 08:30:00', 11, 'Eugenius Bohey', 1950.00),
(12, 'COT_20251209_001', '2025-12-09 10:15:00', 12, 'Nissa Menzies', 4200.00),
(13, 'COT_20251210_001', '2025-12-10 14:00:00', 13, 'Stefania Brizell', 680.00),
(14, 'COT_20251211_001', '2025-12-11 09:45:00', 14, 'Lorelle Godmar', 3750.00),
(15, 'COT_20251212_001', '2025-12-12 11:30:00', 15, 'Ettie Guy', 1100.00),
(16, 'COT_20251213_001', '2025-12-13 16:00:00', 16, 'Mar Tomkin', 2400.00),
(17, 'COT_20251214_001', '2025-12-14 08:00:00', 17, 'Herb Spellissy', 5600.00),
(18, 'COT_20251215_001', '2025-12-15 12:30:00', 18, 'Briant Boshers', 920.00),
(19, 'COT_20251216_001', '2025-12-16 14:45:00', 19, 'Ferdinande Texton', 3100.00),
(20, 'COT_20251217_001', '2025-12-17 10:30:00', 20, 'Harmony Gecks', 1800.00);


-- --------------------------------------------------------

-- Table structure for table `facturas`

CREATE TABLE `facturas` (
  `id` int(11) NOT NULL,
  `no_factura` varchar(50) NOT NULL,
  `date` datetime DEFAULT CURRENT_TIMESTAMP,
  `client_id` int(11) DEFAULT NULL,
  `client_name` varchar(100) NOT NULL,
  `total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `NCF` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Indexes for table `facturas`
--
ALTER TABLE `facturas`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for table `facturas`
--
ALTER TABLE `facturas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

-- --------------------------------------------------------

-- Table structure for table `factura_items`

CREATE TABLE `factura_items` (
  `id` int(11) NOT NULL,
  `factura_id` int(11) NOT NULL,
  `description` text NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `subtotal` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Indexes for table `factura_items`
--
ALTER TABLE `factura_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `factura_id` (`factura_id`);

--
-- AUTO_INCREMENT for table `factura_items`
--
ALTER TABLE `factura_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- Constraints for table `factura_items`
--
ALTER TABLE `factura_items`
  ADD CONSTRAINT `factura_items_ibfk_1` FOREIGN KEY (`factura_id`) REFERENCES `facturas` (`id`) ON DELETE CASCADE;

--
-- Dumping data for table `facturas`
--
INSERT INTO `facturas` (`id`, `no_factura`, `date`, `client_id`, `client_name`, `total`, `NCF`) VALUES
(1, 'FAC_20251201_001', '2025-12-01 10:00:00', 1, 'Caron Melley', 1500.00, 'B0100000001'),
(2, 'FAC_20251201_002', '2025-12-01 12:30:00', 2, 'Eustace Guy', 3200.00, 'B0100000002'),
(3, 'FAC_20251202_001', '2025-12-02 09:15:00', 3, 'Eugene Foale', 950.50, 'B0100000003'),
(4, 'FAC_20251202_002', '2025-12-02 15:20:00', 4, 'Eugenius Feldheim', 2100.00, 'B0100000004'),
(5, 'FAC_20251203_001', '2025-12-03 11:00:00', 5, 'Niccolo Whitsey', 4100.00, 'B0100000005');

--
-- Dumping data for table `factura_items`
--
INSERT INTO `factura_items` (`id`, `factura_id`, `description`, `amount`, `quantity`, `subtotal`) VALUES
(1, 1, 'Servicio de consultoría', 500.00, 2, 1000.00),
(2, 1, 'Licencia anual software', 250.00, 2, 500.00),
(3, 2, 'Desarrollo web', 1600.00, 2, 3200.00),
(4, 3, 'Soporte técnico', 475.25, 2, 950.50),
(5, 4, 'Implementación de red', 1050.00, 2, 2100.00),
(6, 5, 'Mantenimiento de sistemas', 820.00, 5, 4100.00);

-- --------------------------------------------------------

--
-- Table structure for table `ncf_sequences`
--

CREATE TABLE `ncf_sequences` (
  `id` int(11) NOT NULL,
  `type` varchar(10) NOT NULL,
  `prefix` varchar(10) NOT NULL,
  `current_value` int(11) NOT NULL DEFAULT 0,
  `description` varchar(100) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Indexes for table `ncf_sequences`
--
ALTER TABLE `ncf_sequences`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `type` (`type`);

--
-- AUTO_INCREMENT for table `ncf_sequences`
--
ALTER TABLE `ncf_sequences`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- Dumping data for table `ncf_sequences`
-- NCF Types for Dominican Republic:
-- B01 - Facturas de Crédito Fiscal
-- B02 - Facturas de Consumidor Final
-- B14 - Regímenes Especiales
-- B15 - Gubernamental
--
INSERT INTO `ncf_sequences` (`id`, `type`, `prefix`, `current_value`, `description`) VALUES
(1, 'B01', 'B01', 5, 'Facturas de Crédito Fiscal'),
(2, 'B02', 'B02', 0, 'Facturas de Consumidor Final'),
(3, 'B14', 'B14', 0, 'Regímenes Especiales'),
(4, 'B15', 'B15', 0, 'Gubernamental');

--
-- Dumping data for table `cotizacion_items`
--

INSERT INTO `cotizacion_items` (`id`, `cotizacion_id`, `description`, `amount`, `quantity`, `subtotal`) VALUES
-- Cotizacion 1 items
(1, 1, 'Servicio de diseño web básico', 500.00, 1, 500.00),
(2, 1, 'Hosting anual', 150.00, 2, 300.00),
(3, 1, 'Dominio .com', 15.00, 1, 15.00),
(4, 1, 'Mantenimiento mensual', 145.00, 3, 435.00),
-- Cotizacion 2 items
(5, 2, 'Desarrollo de aplicación móvil', 2500.00, 1, 2500.00),
(6, 2, 'Integración API', 500.00, 2, 1000.00),
-- Cotizacion 3 items
(7, 3, 'Consultoría técnica', 150.50, 3, 451.50),
(8, 3, 'Capacitación de personal', 329.00, 1, 329.00),
-- Cotizacion 4 items
(9, 4, 'Servidor dedicado', 700.00, 3, 2100.00),
-- Cotizacion 5 items
(10, 5, 'Sistema ERP personalizado', 3000.00, 1, 3000.00),
(11, 5, 'Módulo de inventario', 750.00, 2, 1500.00),
-- Cotizacion 6 items
(12, 6, 'Soporte técnico mensual', 89.00, 10, 890.00),
-- Cotizacion 7 items
(13, 7, 'Diseño de logo', 350.00, 1, 350.00),
(14, 7, 'Manual de marca', 425.50, 1, 425.50),
(15, 7, 'Tarjetas de presentación', 45.00, 20, 900.00),
-- Cotizacion 8 items
(16, 8, 'Auditoría de seguridad', 1200.00, 1, 1200.00),
(17, 8, 'Implementación firewall', 800.00, 2, 1600.00),
(18, 8, 'Certificado SSL', 200.00, 2, 400.00),
-- Cotizacion 9 items
(19, 9, 'Reparación de equipos', 110.00, 5, 550.00),
-- Cotizacion 10 items
(20, 10, 'Licencia software empresarial', 950.00, 3, 2850.00),
-- Cotizacion 11 items
(21, 11, 'Migración de datos', 650.00, 1, 650.00),
(22, 11, 'Configuración de servidores', 325.00, 4, 1300.00),
-- Cotizacion 12 items
(23, 12, 'Desarrollo e-commerce', 3500.00, 1, 3500.00),
(24, 12, 'Pasarela de pagos', 350.00, 2, 700.00),
-- Cotizacion 13 items
(25, 13, 'Mantenimiento preventivo', 85.00, 8, 680.00),
-- Cotizacion 14 items
(26, 14, 'Sistema de facturación', 1500.00, 1, 1500.00),
(27, 14, 'Módulo de reportes', 750.00, 2, 1500.00),
(28, 14, 'Integración contable', 750.00, 1, 750.00),
-- Cotizacion 15 items
(29, 15, 'Rediseño de página web', 800.00, 1, 800.00),
(30, 15, 'SEO básico', 150.00, 2, 300.00),
-- Cotizacion 16 items
(31, 16, 'Cableado estructurado', 120.00, 20, 2400.00),
-- Cotizacion 17 items
(32, 17, 'Sistema de videovigilancia', 2800.00, 2, 5600.00),
-- Cotizacion 18 items
(33, 18, 'Backup en la nube', 92.00, 10, 920.00),
-- Cotizacion 19 items
(34, 19, 'Desarrollo de API REST', 1550.00, 2, 3100.00),
-- Cotizacion 20 items
(35, 20, 'Consultoría IT', 450.00, 4, 1800.00);

-- --------------------------------------------------------

--
-- Table structure for table `landing_carousel`
--

CREATE TABLE `landing_carousel` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `subtitle` varchar(255) DEFAULT NULL,
  `image_path` varchar(500) NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Indexes for table `landing_carousel`
--
ALTER TABLE `landing_carousel`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for table `landing_carousel`
--
ALTER TABLE `landing_carousel`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- --------------------------------------------------------

--
-- Table structure for table `landing_services`
--

CREATE TABLE `landing_services` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `image_path` varchar(500) NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Indexes for table `landing_services`
--
ALTER TABLE `landing_services`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for table `landing_services`
--
ALTER TABLE `landing_services`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- --------------------------------------------------------

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
