<?php
// db.php - Database Connection (Clean & Safe)

// ปิดการแสดง Error หน้าเว็บป้องกัน Header พัง
mysqli_report(MYSQLI_REPORT_OFF);

// ตรวจสอบ Server
$whitelist = array('127.0.0.1', '::1', 'localhost');
$isLocal = in_array($_SERVER['REMOTE_ADDR'], $whitelist);

if ($isLocal) {
    // 🏠 Localhost / MAMP
    $host   = 'localhost';
    $user   = 'root';
    $pass   = 'root';       // MAMP='root', XAMPP=''
    $dbname = 'classroom_mgmt';
    $port   = 8889;         // MAMP=8889, XAMPP=3306
} else {
    // ☁️ InfinityFree / Hosting (ตรวจสอบค่าให้ถูกต้อง)
    $host   = 'sql206.infinityfree.com';
    $user   = 'if0_40963793';
    $pass   = 'O5NG2LRa26znN5X';
    $dbname = 'if0_40963793_classroom_mgmt';
    $port   = 3306;
}

// เชื่อมต่อฐานข้อมูล
$conn = @new mysqli($host, $user, $pass, $dbname, $port);

// หากเชื่อมต่อไม่ได้ ให้หยุดทำงานเงียบๆ (ป้องกัน Output หลุด)
if ($conn->connect_error) {
    error_log("Database Connection Error: " . $conn->connect_error);
    die("Error: ไม่สามารถเชื่อมต่อฐานข้อมูลได้ (ตรวจสอบไฟล์ db.php)");
}

// ตั้งค่าภาษาไทย
$conn->set_charset("utf8mb4");