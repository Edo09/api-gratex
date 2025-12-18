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
  `amount` int(11) NOT NULL,
  `client` varchar(100) NOT NULL,
  `description` text
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `cotizaciones`
--

INSERT INTO `cotizaciones` (`id`, `code`, `date`, `amount`, `client`, `description`) VALUES
(1, 'SKU_913', NOW(), 32, 'Ettie Foale', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.'),
(2, 'SKU_820', NOW(), 47, 'Herb Fraschetti', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.'),
(3, 'SKU_27', NOW(), 95, 'Stuart Anersen', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.'),
(4, 'SKU_490', NOW(), 55, 'Blythe Schirak', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.'),
(5, 'SKU_387', NOW(), 70, 'Fawne Durrett', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.'),
(6, 'SKU_33', NOW(), 57, 'Booth Kubach', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.'),
(7, 'SKU_316', NOW(), 27, 'Jilly Windrum', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.'),
(8, 'SKU_263', NOW(), 56, 'Caron Littrell', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.'),
(9, 'SKU_260', NOW(), 76, 'Lexy Harriott', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.'),
(10, 'SKU_24', NOW(), 46, 'Niccolo Macquire', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.'),
(11, 'SKU_159', NOW(), 46, 'Lewie Lanegran', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.'),
(12, 'SKU_697', NOW(), 62, 'Augusta Venneur', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.'),
(13, 'SKU_112', NOW(), 81, 'Carola Scherme', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.'),
(14, 'SKU_138', NOW(), 24, 'Fawne Cornels', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.'),
(15, 'SKU_362', NOW(), 69, 'Nicola Turton', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.'),
(16, 'SKU_290', NOW(), 92, 'Will Doyle', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.'),
(17, 'SKU_879', NOW(), 67, 'Pandora Veare', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.'),
(18, 'SKU_871', NOW(), 79, 'Yank Vanin', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.'),
(19, 'SKU_456', NOW(), 46, 'Noelle Hucklesby', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.'),
(20, 'SKU_260', NOW(), 95, 'Davy Aldiss', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.'),
(21, 'SKU_636', NOW(), 32, 'Gwen Eccleston', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.'),
(22, 'SKU_622', NOW(), 26, 'Maurise Feore', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.'),
(23, 'SKU_600', NOW(), 41, 'Tobey Weatherburn', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.'),
(24, 'SKU_873', NOW(), 7, 'Conchita Craiker', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.'),
(25, 'SKU_1', NOW(), 38, 'Jim Godmar', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.'),
(26, 'SKU_72', NOW(), 7, 'Micah Beston', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.'),
(27, 'SKU_857', NOW(), 24, 'Conchita Brito', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.'),
(28, 'SKU_450', NOW(), 2, 'Lorelle Cassell', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.'),
(29, 'SKU_359', NOW(), 58, 'Augusta Iverson', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.'),
(30, 'SKU_454', NOW(), 76, 'Jim Sambiedge', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.'),
(31, 'SKU_330', NOW(), 8, 'Nevsa Whisker', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.'),
(32, 'SKU_735', NOW(), 80, 'Ulick Kenafaque', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.'),
(33, 'SKU_891', NOW(), 19, 'Halie Hefforde', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.'),
(34, 'SKU_65', NOW(), 69, 'Nevsa Borth', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.'),
(35, 'SKU_56', NOW(), 27, 'Richardo O''Hengerty', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.'),
(36, 'SKU_492', NOW(), 26, 'Mona Clutten', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.'),
(37, 'SKU_692', NOW(), 38, 'Geno Blackmoor', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.'),
(38, 'SKU_717', NOW(), 2, 'Benedetta Eagell', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.'),
(39, 'SKU_741', NOW(), 18, 'Sella Gristock', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.'),
(40, 'SKU_533', NOW(), 27, 'Lorelle Pyne', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.'),
(41, 'SKU_120', NOW(), 83, 'Angelia Hammerson', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.'),
(42, 'SKU_998', NOW(), 13, 'Sharlene Mayfield', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.'),
(43, 'SKU_876', NOW(), 44, 'Huntington Brockman', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.'),
(44, 'SKU_189', NOW(), 83, 'Stephani Carnoghan', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.'),
(45, 'SKU_650', NOW(), 52, 'Vicki Peet', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.'),
(46, 'SKU_623', NOW(), 36, 'Nicholas Lenton', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.'),
(47, 'SKU_193', NOW(), 7, 'Ivar Abdee', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.'),
(48, 'SKU_677', NOW(), 48, 'Chloe Cathro', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.'),
(49, 'SKU_83', NOW(), 83, 'Auria Blanchflower', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.'),
(50, 'SKU_881', NOW(), 47, 'Sella Esposi', 'Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.');

--
-- Indexes for table `cotizaciones`
--
ALTER TABLE `cotizaciones`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for table `cotizaciones`
--
ALTER TABLE `cotizaciones`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=51;

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
