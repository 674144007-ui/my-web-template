-- phpMyAdmin SQL Dump
-- version 5.1.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:8889
-- Generation Time: Feb 09, 2026 at 02:36 PM
-- Server version: 5.7.24
-- PHP Version: 8.3.1

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `classroom_mgmt`
--

-- --------------------------------------------------------

--
-- Table structure for table `assigned_work`
--

CREATE TABLE `assigned_work` (
  `id` int(11) NOT NULL,
  `library_id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `class_level` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `due_date` date DEFAULT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `assignment_library`
--

CREATE TABLE `assignment_library` (
  `id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `file_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_type` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

CREATE TABLE `attendance` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `class_level` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `period` int(11) DEFAULT NULL,
  `datetime` datetime DEFAULT CURRENT_TIMESTAMP,
  `status` enum('present','late','absent') COLLATE utf8mb4_unicode_ci DEFAULT 'present'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chemicals`
--

CREATE TABLE `chemicals` (
  `id` int(11) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `state` enum('solid','liquid','gas') DEFAULT 'liquid',
  `molarity` float DEFAULT '0.1',
  `type` varchar(100) DEFAULT NULL,
  `color_neutral` varchar(20) DEFAULT 'ใส',
  `toxicity` int(11) DEFAULT '0' COMMENT 'ความพิษ (0-100)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `chemicals`
--

INSERT INTO `chemicals` (`id`, `name`, `state`, `molarity`, `type`, `color_neutral`, `toxicity`) VALUES
(1, 'น้ำ (Water)', 'liquid', 0.1, 'solvent', '#E0FFFF', 0),
(2, 'น้ำมันพืช (Vegetable Oil)', 'liquid', 0.1, 'organic', '#FFD700', 0),
(3, 'แอลกอฮอล์ (Alcohol)', 'liquid', 0.1, 'alcohol', '#FFFFFF', 10),
(4, 'กรดไฮโดรคลอริก (HCl)', 'liquid', 0.1, 'acid', '#FFFFE0', 30),
(5, 'โซดาไฟ (NaOH)', 'solid', 0.1, 'base', '#FFFFFF', 50),
(6, 'ทองแดง (Copper)', 'solid', 0.1, 'metal', '#B87333', 0),
(7, 'ด่างทับทิม (KMnO4)', 'solid', 0.1, 'oxidizer', '#800080', 20),
(8, 'ออกซิเจน (O2)', 'gas', 0.1, 'gas', '#FFFFFF', 0),
(9, 'ปรอท (Mercury)', 'liquid', 0.1, 'metal', '#C0C0C0', 80),
(10, 'น้ำแข็งแห้ง (Dry Ice)', 'solid', 0.1, 'cold', '#FFFFFF', 10),
(11, 'กรดไฮโดรคลอริก (HCl)', 'liquid', 0.1, 'acid', '#FFFFE0', 30),
(12, 'กรดซัลฟิวริก (H2SO4)', 'liquid', 0.1, 'acid', '#FFFFE0', 40),
(13, 'กรดไนตริก (HNO3)', 'liquid', 0.1, 'acid', '#FFFFE0', 40),
(14, 'น้ำส้มสายชู (Vinegar)', 'liquid', 0.1, 'acid', '#D2691E', 5),
(15, 'กรดมะนาว (Citric Acid)', 'solid', 0.1, 'acid', '#FFFFFF', 5),
(16, 'กรดกัดทอง (Aqua Regia)', 'liquid', 0.1, 'acid', '#FF8C00', 80),
(17, 'กรดกัดแก้ว (HF)', 'liquid', 0.1, 'acid', '#FFFFFF', 100),
(18, 'น้ำยาล้างห้องน้ำ (Toilet Cleaner)', 'liquid', 0.1, 'acid', '#00BFFF', 30),
(19, 'กรดฟอสฟอริก (Phosphoric Acid)', 'liquid', 0.1, 'acid', '#FFFFFF', 20),
(20, 'วิตามินซี (Vitamin C)', 'solid', 0.1, 'acid', '#FFA500', 0),
(21, 'โซดาไฟ (NaOH)', 'solid', 0.1, 'base', '#FFFFFF', 50),
(22, 'โพแทสเซียมไฮดรอกไซด์ (KOH)', 'solid', 0.1, 'base', '#FFFFFF', 50),
(23, 'แอมโมเนีย (Ammonia)', 'liquid', 0.1, 'base', '#FFFFFF', 25),
(24, 'ปูนขาว (Calcium Hydroxide)', 'solid', 0.1, 'base', '#FFFFFF', 10),
(25, 'ผงฟู (Baking Soda)', 'solid', 0.1, 'base', '#FFFFFF', 0),
(26, 'ไฮเตอร์ (Bleach)', 'liquid', 0.1, 'base', '#FFFFE0', 40),
(27, 'สบู่เหลว (Liquid Soap)', 'liquid', 0.1, 'base', '#FF69B4', 0),
(28, 'น้ำปูนใส (Limewater)', 'liquid', 0.1, 'base', '#FFFFFF', 5),
(29, 'ยาสีฟัน (Toothpaste)', 'solid', 0.1, 'base', '#FFFFFF', 0),
(30, 'ผงซักฟอก (Detergent)', 'solid', 0.1, 'base', '#87CEEB', 5),
(31, 'ลิเทียม (Lithium)', 'solid', 0.1, 'metal', '#C0C0C0', 10),
(32, 'โซเดียม (Sodium)', 'solid', 0.1, 'metal', '#C0C0C0', 20),
(33, 'โพแทสเซียม (Potassium)', 'solid', 0.1, 'metal', '#C0C0C0', 20),
(34, 'แมกนีเซียม (Magnesium)', 'solid', 0.1, 'metal', '#C0C0C0', 5),
(35, 'แคลเซียม (Calcium)', 'solid', 0.1, 'metal', '#C0C0C0', 10),
(36, 'อะลูมิเนียม (Aluminium)', 'solid', 0.1, 'metal', '#C0C0C0', 0),
(37, 'สังกะสี (Zinc)', 'solid', 0.1, 'metal', '#C0C0C0', 5),
(38, 'เหล็ก/ฝอยขัดหม้อ (Iron)', 'solid', 0.1, 'metal', '#708090', 0),
(39, 'ทองแดง (Copper)', 'solid', 0.1, 'metal', '#B87333', 0),
(40, 'รูบิเดียม (Rubidium)', 'solid', 0.1, 'metal', '#8B0000', 30),
(41, 'ซีเซียม (Cesium)', 'solid', 0.1, 'metal', '#D4AF37', 40),
(42, 'แบเรียม (Barium)', 'solid', 0.1, 'metal', '#C0C0C0', 20),
(43, 'สตรอนเทียม (Strontium)', 'solid', 0.1, 'metal', '#C0C0C0', 10),
(44, 'ยูเรเนียม (Uranium)', 'solid', 0.1, 'radioactive', '#32CD32', 80),
(45, 'พลูโตเนียม (Plutonium)', 'solid', 0.1, 'radioactive', '#A9A9A9', 100),
(46, 'เกลือแกง (Salt)', 'solid', 0.1, 'salt', '#FFFFFF', 0),
(47, 'น้ำตาลทราย (Sugar)', 'solid', 0.1, 'organic', '#FFFFFF', 0),
(48, 'ด่างทับทิม (KMnO4)', 'solid', 0.1, 'oxidizer', '#800080', 20),
(49, 'ดินประสิว (KNO3)', 'solid', 0.1, 'oxidizer', '#FFFFFF', 10),
(50, 'โพแทสเซียมคลอเรต (KClO3)', 'solid', 0.1, 'oxidizer', '#FFFFFF', 20),
(51, 'กำมะถัน (Sulfur)', 'solid', 0.1, 'element', '#FFFF00', 10),
(52, 'ถ่านบด (Charcoal)', 'solid', 0.1, 'fuel', '#000000', 0),
(53, 'เลดไนเตรต (Lead Nitrate)', 'solid', 0.1, 'salt', '#FFFFFF', 30),
(54, 'โพแทสเซียมไอโอไดด์ (KI)', 'solid', 0.1, 'salt', '#FFFFFF', 10),
(55, 'ซิลเวอร์ไนเตรต (AgNO3)', 'solid', 0.1, 'salt', '#FFFFFF', 30),
(56, 'คอปเปอร์ซัลเฟต (CuSO4)', 'solid', 0.1, 'salt', '#0000FF', 10),
(57, 'เฟอร์ริกคลอไรด์ (FeCl3)', 'solid', 0.1, 'salt', '#8B4513', 20),
(58, 'ปุ๋ยยูเรีย (Urea)', 'solid', 0.1, 'salt', '#FFFFFF', 5),
(59, 'บอแรกซ์ (Borax)', 'solid', 0.1, 'salt', '#FFFFFF', 10),
(60, 'แคลเซียมคาร์บอเนต (CaCO3)', 'solid', 0.1, 'salt', '#FFFFFF', 0),
(61, 'ไซยาไนด์ (Cyanide)', 'solid', 0.1, 'poison', '#FFFFFF', 100),
(62, 'สารหนู (Arsenic)', 'solid', 0.1, 'poison', '#808080', 90),
(63, 'ทีเอ็นที (TNT)', 'solid', 0.1, 'explosive', '#F0E68C', 80),
(64, 'ซีโฟร์ (C4)', 'solid', 0.1, 'explosive', '#D3D3D3', 90),
(65, 'ไนโตรกลีเซอรีน (Nitroglycerin)', 'liquid', 0.1, 'explosive', '#FFFFE0', 90),
(66, 'โทลูอีน (Toluene)', 'liquid', 0.1, 'solvent', '#FFFFFF', 30),
(67, 'ฟีนอล (Phenol)', 'solid', 0.1, 'organic', '#FFFFFF', 50),
(68, 'ฟอสฟอรัสขาว (White Phosphorus)', 'solid', 0.1, 'element', '#FFFFE0', 80),
(69, 'แมกนีเซียมออกไซด์ (MgO)', 'solid', 0.1, 'metal-oxide', '#FFFFFF', 5),
(70, 'ผงสนิมเหล็ก (Iron Oxide)', 'solid', 0.1, 'metal-oxide', '#8B4513', 5),
(71, 'ออกซิเจน (O2)', 'gas', 0.1, 'gas', '#FFFFFF', 0),
(72, 'ไฮโดรเจน (H2)', 'gas', 0.1, 'gas', '#FFFFFF', 0),
(73, 'คลอรีน (Cl2)', 'gas', 0.1, 'gas', '#ADFF2F', 80),
(74, 'ฟลูออรีน (F2)', 'gas', 0.1, 'gas', '#FFFFE0', 90),
(75, 'ฮีเลียม (He)', 'gas', 0.1, 'gas', '#FFFFFF', 0),
(76, 'ไนโตรเจน (N2)', 'gas', 0.1, 'gas', '#FFFFFF', 0),
(77, 'คาร์บอนไดออกไซด์ (CO2)', 'gas', 0.1, 'gas', '#FFFFFF', 0),
(78, 'มีเทน (Methane)', 'gas', 0.1, 'fuel', '#FFFFFF', 10),
(79, 'โพรเพน (Propane)', 'gas', 0.1, 'fuel', '#FFFFFF', 10),
(80, 'ไนตรัสออกไซด์ (Laughing Gas)', 'gas', 0.1, 'gas', '#FFFFFF', 5),
(81, 'เบตาดีน (Iodine)', 'liquid', 0.1, 'indicator', '#8B4500', 5),
(82, 'น้ำกะหล่ำปลีม่วง', 'liquid', 0.1, 'indicator', '#8A2BE2', 0),
(83, 'ฟีนอล์ฟทาลีน (Phenolphthalein)', 'liquid', 0.1, 'indicator', '#FFFFFF', 5),
(84, 'สีแดง (Red Dye)', 'liquid', 0.1, 'dye', '#FF0000', 0),
(85, 'สีเหลือง (Yellow Dye)', 'liquid', 0.1, 'dye', '#FFFF00', 0),
(86, 'สีน้ำเงิน (Blue Dye)', 'liquid', 0.1, 'dye', '#0000FF', 0),
(87, 'สีเขียว (Green Dye)', 'liquid', 0.1, 'dye', '#008000', 0),
(88, 'สีม่วง (Purple Dye)', 'liquid', 0.1, 'dye', '#800080', 0),
(89, 'หมึกดำ (Black Ink)', 'liquid', 0.1, 'dye', '#000000', 0),
(90, 'สีสะท้อนแสง (Neon Dye)', 'liquid', 0.1, 'dye', '#7FFF00', 0),
(91, 'กาวน้ำใส (Glue)', 'liquid', 0.1, 'polymer', '#F0F8FF', 0),
(92, 'แป้งข้าวโพด (Cornstarch)', 'solid', 0.1, 'organic', '#FFFFFF', 0),
(93, 'นมสด (Milk)', 'liquid', 0.1, 'food', '#FFFFFF', 0),
(94, 'น้ำอัดลม (Soda)', 'liquid', 0.1, 'acid', '#2F4F4F', 0),
(95, 'ลูกอมเมนทอส (Mentos)', 'solid', 0.1, 'candy', '#FFFFFF', 0),
(96, 'เลือด (Blood)', 'liquid', 0.1, 'biological', '#8B0000', 5),
(97, 'โฟม (Styrofoam)', 'solid', 0.1, 'polymer', '#FFFFFF', 0),
(98, 'พิษงู (Snake Venom)', 'liquid', 0.1, 'biological', '#FFFF00', 90),
(99, 'ดีเอ็นเอ (DNA)', 'liquid', 0.1, 'biological', '#E6E6FA', 0),
(100, 'ฟอร์มาลีน (Formalin)', 'liquid', 0.1, 'preservative', '#FFFFFF', 40),
(101, 'น้ำลาย (Saliva)', 'liquid', 0.1, 'biological', '#FFFFFF', 0),
(102, 'แบเรียมคลอไรด์ (BaCl2)', 'solid', 0.1, 'salt', '#FFFFFF', 20),
(103, 'สตรอนเทียมคลอไรด์ (SrCl2)', 'solid', 0.1, 'salt', '#FFFFFF', 10),
(104, 'กระดาษ (Paper)', 'solid', 0.1, 'organic', '#FFFFFF', 0),
(105, 'คอปเปอร์คลอไรด์ (CuCl2)', 'solid', 0.1, 'salt', '#008000', 10),
(106, 'ไฮโดรเจน (Hydrogen)', 'gas', 0.1, 'nonmetal', '#FFFFFF', 10),
(107, 'ฮีเลียม (Helium)', 'gas', 0.1, 'noble-gas', '#D9FFFF', 0),
(108, 'ลิเทียม (Lithium)', 'solid', 0.1, 'alkali', '#CC80FF', 20),
(109, 'เบอริลเลียม (Beryllium)', 'solid', 0.1, 'alkaline-earth', '#C2FF00', 60),
(110, 'โบรอน (Boron)', 'solid', 0.1, 'metalloid', '#FFB5B5', 10),
(111, 'คาร์บอน (Carbon)', 'solid', 0.1, 'nonmetal', '#909090', 0),
(112, 'ไนโตรเจน (Nitrogen)', 'gas', 0.1, 'nonmetal', '#3050F8', 0),
(113, 'ออกซิเจน (Oxygen)', 'gas', 0.1, 'nonmetal', '#FF0D0D', 0),
(114, 'ฟลูออรีน (Fluorine)', 'gas', 0.1, 'halogen', '#90E050', 80),
(115, 'นีออน (Neon)', 'gas', 0.1, 'noble-gas', '#B3E3F5', 0),
(116, 'โซเดียม (Sodium)', 'solid', 0.1, 'alkali', '#AB5CF2', 20),
(117, 'แมกนีเซียม (Magnesium)', 'solid', 0.1, 'alkaline-earth', '#8AFF00', 5),
(118, 'อะลูมิเนียม (Aluminium)', 'solid', 0.1, 'post-transition', '#BFA6A6', 0),
(119, 'ซิลิคอน (Silicon)', 'solid', 0.1, 'metalloid', '#F0C8A0', 0),
(120, 'ฟอสฟอรัส (Phosphorus)', 'solid', 0.1, 'nonmetal', '#FF8000', 40),
(121, 'กำมะถัน (Sulfur)', 'solid', 0.1, 'nonmetal', '#FFFF30', 10),
(122, 'คลอรีน (Chlorine)', 'gas', 0.1, 'halogen', '#1FF01F', 60),
(123, 'อาร์กอน (Argon)', 'gas', 0.1, 'noble-gas', '#80D1E3', 0),
(124, 'โพแทสเซียม (Potassium)', 'solid', 0.1, 'alkali', '#8F40D4', 20),
(125, 'แคลเซียม (Calcium)', 'solid', 0.1, 'alkaline-earth', '#3DFF00', 5),
(126, 'สแคนเดียม (Scandium)', 'solid', 0.1, 'transition', '#E6E6E6', 10),
(127, 'ไทเทเนียม (Titanium)', 'solid', 0.1, 'transition', '#BFC2C7', 5),
(128, 'วาเนเดียม (Vanadium)', 'solid', 0.1, 'transition', '#A6A6AB', 15),
(129, 'โครเมียม (Chromium)', 'solid', 0.1, 'transition', '#8A99C7', 20),
(130, 'แมงกานีส (Manganese)', 'solid', 0.1, 'transition', '#9C7AC7', 15),
(131, 'เหล็ก (Iron)', 'solid', 0.1, 'transition', '#E06633', 0),
(132, 'โคบอลต์ (Cobalt)', 'solid', 0.1, 'transition', '#F090A0', 15),
(133, 'นิกเกิล (Nickel)', 'solid', 0.1, 'transition', '#50D050', 15),
(134, 'ทองแดง (Copper)', 'solid', 0.1, 'transition', '#C88033', 5),
(135, 'สังกะสี (Zinc)', 'solid', 0.1, 'transition', '#7D80B0', 5),
(136, 'แกลเลียม (Gallium)', 'solid', 0.1, 'post-transition', '#C28F8F', 10),
(137, 'เจอร์เมเนียม (Germanium)', 'solid', 0.1, 'metalloid', '#668F8F', 5),
(138, 'สารหนู (Arsenic)', 'solid', 0.1, 'metalloid', '#BD80E3', 90),
(139, 'ซีลีเนียม (Selenium)', 'solid', 0.1, 'nonmetal', '#FFA100', 30),
(140, 'โบรมีน (Bromine)', 'liquid', 0.1, 'halogen', '#A62929', 70),
(141, 'คริปทอน (Krypton)', 'gas', 0.1, 'noble-gas', '#5CB8D1', 0),
(142, 'รูบิเดียม (Rubidium)', 'solid', 0.1, 'alkali', '#702EB0', 30),
(143, 'สตรอนเทียม (Strontium)', 'solid', 0.1, 'alkaline-earth', '#00FF00', 10),
(144, 'อิตเทรียม (Yttrium)', 'solid', 0.1, 'transition', '#94FFFF', 5),
(145, 'เซอร์โคเนียม (Zirconium)', 'solid', 0.1, 'transition', '#94E0E0', 5),
(146, 'ไนโอเบียม (Niobium)', 'solid', 0.1, 'transition', '#73C2C9', 5),
(147, 'โมลิบดีนัม (Molybdenum)', 'solid', 0.1, 'transition', '#54B5B5', 5),
(148, 'เทคนีเซียม (Technetium)', 'solid', 0.1, 'transition', '#3B9E9E', 50),
(149, 'รูทีเนียม (Ruthenium)', 'solid', 0.1, 'transition', '#248F8F', 5),
(150, 'โรเดียม (Rhodium)', 'solid', 0.1, 'transition', '#0A7D8C', 5),
(151, 'แพลเลเดียม (Palladium)', 'solid', 0.1, 'transition', '#006985', 5),
(152, 'เงิน (Silver)', 'solid', 0.1, 'transition', '#C0C0C0', 5),
(153, 'แคดเมียม (Cadmium)', 'solid', 0.1, 'transition', '#FFD98F', 50),
(154, 'อินเดียม (Indium)', 'solid', 0.1, 'post-transition', '#A67573', 5),
(155, 'ดีบุก (Tin)', 'solid', 0.1, 'post-transition', '#668080', 5),
(156, 'พลวง (Antimony)', 'solid', 0.1, 'metalloid', '#9E63B5', 20),
(157, 'เทลลูเรียม (Tellurium)', 'solid', 0.1, 'metalloid', '#D47A00', 20),
(158, 'ไอโอดีน (Iodine)', 'solid', 0.1, 'halogen', '#940094', 30),
(159, 'ซีนอน (Xenon)', 'gas', 0.1, 'noble-gas', '#429EB0', 0),
(160, 'ซีเซียม (Cesium)', 'solid', 0.1, 'alkali', '#57178F', 40),
(161, 'แบเรียม (Barium)', 'solid', 0.1, 'alkaline-earth', '#00C900', 20),
(162, 'แลนทานัม (Lanthanum)', 'solid', 0.1, 'lanthanide', '#70D4FF', 5),
(163, 'ซีเรียม (Cerium)', 'solid', 0.1, 'lanthanide', '#FFFFC7', 5),
(164, 'เพรซีโอดีเนียม (Praseodymium)', 'solid', 0.1, 'lanthanide', '#D9FFC7', 5),
(165, 'นีโอดิเมียม (Neodymium)', 'solid', 0.1, 'lanthanide', '#C7FFC7', 5),
(166, 'โพรมีเทียม (Promethium)', 'solid', 0.1, 'lanthanide', '#A3FFC7', 60),
(167, 'ซาแมเรียม (Samarium)', 'solid', 0.1, 'lanthanide', '#8FFFC7', 5),
(168, 'ยูโรเพียม (Europium)', 'solid', 0.1, 'lanthanide', '#61FFC7', 5),
(169, 'แกโดลิเนียม (Gadolinium)', 'solid', 0.1, 'lanthanide', '#45FFC7', 5),
(170, 'เทอร์เบียม (Terbium)', 'solid', 0.1, 'lanthanide', '#30FFC7', 5),
(171, 'ดิสโพรเซียม (Dysprosium)', 'solid', 0.1, 'lanthanide', '#1FFFC7', 5),
(172, 'โฮลเมียม (Holmium)', 'solid', 0.1, 'lanthanide', '#00FF9C', 5),
(173, 'เออร์เบียม (Erbium)', 'solid', 0.1, 'lanthanide', '#00E675', 5),
(174, 'ทูเลียม (Thulium)', 'solid', 0.1, 'lanthanide', '#00D452', 5),
(175, 'อิตเทอร์เบียม (Ytterbium)', 'solid', 0.1, 'lanthanide', '#00BF38', 5),
(176, 'ลูทีเชียม (Lutetium)', 'solid', 0.1, 'lanthanide', '#00AB24', 5),
(177, 'แฮฟเนียม (Hafnium)', 'solid', 0.1, 'transition', '#4DA6FF', 5),
(178, 'แทนทาลัม (Tantalum)', 'solid', 0.1, 'transition', '#4C6FFC', 5),
(179, 'ทังสเตน (Tungsten)', 'solid', 0.1, 'transition', '#2194D6', 5),
(180, 'รีเนียม (Rhenium)', 'solid', 0.1, 'transition', '#267DAB', 5),
(181, 'ออสเมียม (Osmium)', 'solid', 0.1, 'transition', '#266696', 10),
(182, 'อิริเดียม (Iridium)', 'solid', 0.1, 'transition', '#175487', 5),
(183, 'แพลตตินัม (Platinum)', 'solid', 0.1, 'transition', '#D0D0E0', 5),
(184, 'ทองคำ (Gold)', 'solid', 0.1, 'transition', '#FFD123', 0),
(185, 'ปรอท (Mercury)', 'liquid', 0.1, 'transition', '#B8B8D0', 80),
(186, 'แทลเลียม (Thallium)', 'solid', 0.1, 'post-transition', '#A6544D', 60),
(187, 'ตะกั่ว (Lead)', 'solid', 0.1, 'post-transition', '#575961', 40),
(188, 'บิสมัท (Bismuth)', 'solid', 0.1, 'post-transition', '#9E4FB5', 10),
(189, 'พอโลเนียม (Polonium)', 'solid', 0.1, 'post-transition', '#AB5C00', 90),
(190, 'แอสทาทีน (Astatine)', 'solid', 0.1, 'halogen', '#754F45', 80),
(191, 'เรดอน (Radon)', 'gas', 0.1, 'noble-gas', '#428296', 50),
(192, 'แฟรนเซียม (Francium)', 'solid', 0.1, 'alkali', '#420066', 80),
(193, 'เรเดียม (Radium)', 'solid', 0.1, 'alkaline-earth', '#007D00', 70),
(194, 'แอกทิเนียม (Actinium)', 'solid', 0.1, 'actinide', '#70ABFA', 60),
(195, 'ทอเรียม (Thorium)', 'solid', 0.1, 'actinide', '#00BAFF', 40),
(196, 'โพรแทกทิเนียม (Protactinium)', 'solid', 0.1, 'actinide', '#00A1FF', 60),
(197, 'ยูเรเนียม (Uranium)', 'solid', 0.1, 'actinide', '#008FFF', 80),
(198, 'เนปจูเนียม (Neptunium)', 'solid', 0.1, 'actinide', '#0080FF', 80),
(199, 'พลูโทเนียม (Plutonium)', 'solid', 0.1, 'actinide', '#006BFF', 100),
(200, 'อะเมริเซียม (Americium)', 'solid', 0.1, 'actinide', '#545CF2', 80),
(201, 'คูเรียม (Curium)', 'solid', 0.1, 'actinide', '#785CE3', 80),
(202, 'เบอร์คีเลียม (Berkelium)', 'solid', 0.1, 'actinide', '#8A4FE3', 80),
(203, 'แคลิฟอร์เนียม (Californium)', 'solid', 0.1, 'actinide', '#A136D4', 80),
(204, 'ไอน์สไตเนียม (Einsteinium)', 'solid', 0.1, 'actinide', '#B31FD4', 80),
(205, 'เฟอร์เมียม (Fermium)', 'solid', 0.1, 'actinide', '#B31FBA', 80),
(206, 'เมนเดลีเวียม (Mendelevium)', 'solid', 0.1, 'actinide', '#B30DA6', 80),
(207, 'โนเบเลียม (Nobelium)', 'solid', 0.1, 'actinide', '#BD0D87', 80),
(208, 'ลอว์เรนเซียม (Lawrencium)', 'solid', 0.1, 'actinide', '#C70066', 80),
(209, 'รัทเทอร์ฟอร์เดียม (Rutherfordium)', 'solid', 0.1, 'transition', '#CC0059', 50),
(210, 'ดุบเนียม (Dubnium)', 'solid', 0.1, 'transition', '#D1004F', 50),
(211, 'ซีบอร์เกียม (Seaborgium)', 'solid', 0.1, 'transition', '#D90045', 50),
(212, 'โบห์เรียม (Bohrium)', 'solid', 0.1, 'transition', '#E00038', 50),
(213, 'ฮาสเซียม (Hassium)', 'solid', 0.1, 'transition', '#E6002E', 50),
(214, 'ไมต์เนเรียม (Meitnerium)', 'solid', 0.1, 'transition', '#EB0026', 50),
(215, 'ดาร์มสตัดเทียม (Darmstadtium)', 'solid', 0.1, 'transition', '#F00021', 50),
(216, 'เรินต์เกเนียม (Roentgenium)', 'solid', 0.1, 'transition', '#F00010', 50),
(217, 'โคเปอร์นิเซียม (Copernicium)', 'solid', 0.1, 'transition', '#F00005', 50),
(218, 'นิโฮเนียม (Nihonium)', 'solid', 0.1, 'post-transition', '#F00000', 50),
(219, 'ฟลีรอเวียม (Flerovium)', 'solid', 0.1, 'post-transition', '#F00000', 50),
(220, 'มอสโกเวียม (Moscovium)', 'solid', 0.1, 'post-transition', '#F00000', 50),
(221, 'ลิเวอร์มอเรียม (Livermorium)', 'solid', 0.1, 'post-transition', '#F00000', 50),
(222, 'เทนเนสซีน (Tennessine)', 'solid', 0.1, 'halogen', '#F00000', 50),
(223, 'โอแกเนสซอน (Oganesson)', 'solid', 0.1, 'noble-gas', '#F00000', 50);

-- --------------------------------------------------------

--
-- Table structure for table `qr_codes`
--

CREATE TABLE `qr_codes` (
  `id` int(11) NOT NULL,
  `code` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `class_name` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subject` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `reactions`
--

CREATE TABLE `reactions` (
  `id` int(11) NOT NULL,
  `chem1_id` int(11) NOT NULL,
  `chem2_id` int(11) NOT NULL,
  `product_name` varchar(255) DEFAULT NULL,
  `result_color` varchar(20) DEFAULT NULL,
  `result_state` enum('solid','liquid','gas') DEFAULT 'liquid',
  `result_precipitate` varchar(255) DEFAULT 'ไม่มีตะกอน',
  `result_gas` varchar(255) DEFAULT 'ไม่มีแก๊ส',
  `heat_level` float DEFAULT '0',
  `is_explosive` tinyint(1) DEFAULT '0',
  `toxicity_bonus` int(11) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `reactions`
--

INSERT INTO `reactions` (`id`, `chem1_id`, `chem2_id`, `product_name`, `result_color`, `result_state`, `result_precipitate`, `result_gas`, `heat_level`, `is_explosive`, `toxicity_bonus`) VALUES
(1, 1, 32, 'Sodium Hydroxide + Hydrogen', '#FFFFFF', 'liquid', 'ไม่มีตะกอน', 'Hydrogen Gas', 80, 1, 20),
(2, 14, 25, 'Sodium Acetate + Water + CO2', '#FFFFFF', 'liquid', 'ไม่มีตะกอน', 'Carbon Dioxide', 5, 0, 0),
(3, 11, 21, 'Salt Water (Neutralization)', '#FFFFFF', 'liquid', 'ไม่มีตะกอน', 'Steam', 50, 0, 10),
(4, 72, 71, 'Water Vapor (Explosion)', '#FFFFFF', 'gas', 'ไม่มีตะกอน', 'Water Vapor', 100, 1, 50),
(5, 48, 1, 'Potassium Permanganate Solution', '#800080', 'liquid', 'ไม่มีตะกอน', 'ไม่มีแก๊ส', 0, 0, 10);

-- --------------------------------------------------------

--
-- Table structure for table `results`
--

CREATE TABLE `results` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `game_id` int(11) NOT NULL,
  `score` int(11) DEFAULT '0',
  `ph_result` float DEFAULT '7',
  `is_passed` tinyint(1) DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `student_qr`
--

CREATE TABLE `student_qr` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `qr_token` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `teacher_files`
--

CREATE TABLE `teacher_files` (
  `id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `title` varchar(200) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `teacher_schedule`
--

CREATE TABLE `teacher_schedule` (
  `id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `day_of_week` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `class_name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `time_start` time NOT NULL,
  `time_end` time NOT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `display_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `original_role` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `class_level` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `parent_of` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `display_name`, `role`, `original_role`, `class_level`, `parent_of`, `created_at`) VALUES
(1, 'PichayaY.', '$2y$10$Zv2DN1wxiI6cFZmJL1N3beEhvrqeWTUV7tmYthCN2h3EZVc.m4Wge', 'พิชญะ ยาเครือ (Developer)', 'admin', 'developer', 'ม.6/1', NULL, '2025-11-24 15:37:44'),
(2, 'stu001', '$2y$10$gHVWPRhRZ1VsemQB/3FvNe8yzIJBTTAkan2xhjelnNjYOV5M4Jx0.', 'นักเรียน ทดสอบ', 'student', 'student', 'ม.1/1', NULL, '2025-11-24 15:37:44'),
(3, 'tea001', '$2y$10$mVcTlryXjwVTvE1whxOJB.b/f8etcel/GMxsuOf8.f.ugGMeTivBS', 'ครู ทดสอบ', 'teacher', 'teacher', NULL, NULL, '2025-11-24 15:37:44'),
(4, 'par001', '$2y$10$MZreO8K2B4GxksAYR.KWW.tGdDFi.3sFCDgmZsUifAllxy1vNWjnO', 'ผู้ปกครอง ทดสอบ', 'parent', 'parent', NULL, NULL, '2025-11-24 15:37:44');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `assigned_work`
--
ALTER TABLE `assigned_work`
  ADD PRIMARY KEY (`id`),
  ADD KEY `library_id` (`library_id`),
  ADD KEY `teacher_id` (`teacher_id`);

--
-- Indexes for table `assignment_library`
--
ALTER TABLE `assignment_library`
  ADD PRIMARY KEY (`id`),
  ADD KEY `teacher_id` (`teacher_id`);

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`id`),
  ADD KEY `student_id` (`student_id`);

--
-- Indexes for table `chemicals`
--
ALTER TABLE `chemicals`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `qr_codes`
--
ALTER TABLE `qr_codes`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `reactions`
--
ALTER TABLE `reactions`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `results`
--
ALTER TABLE `results`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `student_qr`
--
ALTER TABLE `student_qr`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `qr_token` (`qr_token`),
  ADD KEY `student_id` (`student_id`);

--
-- Indexes for table `teacher_files`
--
ALTER TABLE `teacher_files`
  ADD PRIMARY KEY (`id`),
  ADD KEY `teacher_id` (`teacher_id`);

--
-- Indexes for table `teacher_schedule`
--
ALTER TABLE `teacher_schedule`
  ADD PRIMARY KEY (`id`),
  ADD KEY `teacher_id` (`teacher_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `parent_of` (`parent_of`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `assigned_work`
--
ALTER TABLE `assigned_work`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `assignment_library`
--
ALTER TABLE `assignment_library`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `chemicals`
--
ALTER TABLE `chemicals`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=224;

--
-- AUTO_INCREMENT for table `qr_codes`
--
ALTER TABLE `qr_codes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `reactions`
--
ALTER TABLE `reactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `results`
--
ALTER TABLE `results`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `student_qr`
--
ALTER TABLE `student_qr`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `teacher_files`
--
ALTER TABLE `teacher_files`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `teacher_schedule`
--
ALTER TABLE `teacher_schedule`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `assigned_work`
--
ALTER TABLE `assigned_work`
  ADD CONSTRAINT `assigned_work_ibfk_1` FOREIGN KEY (`library_id`) REFERENCES `assignment_library` (`id`),
  ADD CONSTRAINT `assigned_work_ibfk_2` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `assignment_library`
--
ALTER TABLE `assignment_library`
  ADD CONSTRAINT `assignment_library_ibfk_1` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `attendance`
--
ALTER TABLE `attendance`
  ADD CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `student_qr`
--
ALTER TABLE `student_qr`
  ADD CONSTRAINT `student_qr_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `teacher_files`
--
ALTER TABLE `teacher_files`
  ADD CONSTRAINT `teacher_files_ibfk_1` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `teacher_schedule`
--
ALTER TABLE `teacher_schedule`
  ADD CONSTRAINT `teacher_schedule_ibfk_1` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `teacher_schedule_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`parent_of`) REFERENCES `users` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
