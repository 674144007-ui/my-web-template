SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

CREATE TABLE `chemicals` (
  `id` int(11) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `state` enum('solid','liquid','gas') DEFAULT 'liquid',
  `molarity` float DEFAULT 0.1,
  `type` varchar(100) DEFAULT NULL,
  `color_neutral` varchar(20) DEFAULT 'ใส',
  `toxicity` int(11) DEFAULT 0 COMMENT 'ความพิษ (0-100)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `chemicals` (`id`, `name`, `state`, `molarity`, `type`, `color_neutral`, `toxicity`) VALUES
(1, 'น้ำ (Water)', 'liquid', 0.1, 'solvent', '#E0FFFF', 0),
(2, 'น้ำมันพืช (Vegetable Oil)', 'liquid', 0.1, 'organic', '#FFD700', 0),
(3, 'แอลกอฮอล์ (Alcohol)', 'liquid', 0.1, 'alcohol', '#FFFFFF', 10),
(4, 'อะซิโตน (Acetone)', 'liquid', 0.1, 'solvent', '#FFFFFF', 20),
(5, 'ไฮโดรเจนเปอร์ออกไซด์ (H2O2)', 'liquid', 0.1, 'oxidizer', '#FFFFFF', 10),
(6, 'กลีเซอรีน (Glycerin)', 'liquid', 0.1, 'organic', '#FFFFFF', 5),
(7, 'น้ำมันเบนซิน (Gasoline)', 'liquid', 0.1, 'fuel', '#F0E68C', 40),
(8, 'ทินเนอร์ (Thinner)', 'liquid', 0.1, 'solvent', '#FFFFFF', 30),
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
(105, 'คอปเปอร์คลอไรด์ (CuCl2)', 'solid', 0.1, 'salt', '#008000', 10);

-- ตาราง reactions / results / users + ALTER TABLE
-- (เหมือนต้นฉบับคุณทุกบรรทัด เปลี่ยนเฉพาะ DEFAULT ตัวเลข)

ALTER TABLE `chemicals`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `chemicals`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=106;

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
