<?php
/**
 * ===================================================================================
 * [DATABASE LAYER] FILE: db.php
 * ===================================================================================
 */
// เปิดการแสดง Error ของ MySQLi แบบเข้มงวด
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// ตรวจสอบสภาพแวดล้อม (Local vs Production)
$whitelist = array('127.0.0.1', '::1', 'localhost');
$isLocal = in_array($_SERVER['REMOTE_ADDR'], $whitelist) || 
           (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'localhost') !== false);

if ($isLocal) {
    // 🏠 Localhost Configuration
    $host   = 'localhost';
    $user   = 'root';
    $pass   = 'root'; 
    $dbname = 'classroom_mgmt';
    $port   = 8889; 
} else {
    // ☁️ Production Configuration (InfinityFree)
    $host   = 'sql206.infinityfree.com';
    $user   = 'if0_40963793';
    $pass   = 'O5NG2LRa26znN5X';
    $dbname = 'if0_40963793_classroom_mgmt';
    $port   = 3306;
}

try {
    // สถาปนาการเชื่อมต่อ
    $conn = new mysqli($host, $user, $pass, $dbname, $port);
    
    // บังคับการจัดรูปแบบอักขระให้รองรับภาษาไทย 100%
    $conn->set_charset("utf8mb4");
    $conn->query("SET names utf8mb4");
    $conn->query("SET time_zone = '+07:00'");

} catch (mysqli_sql_exception $e) {
    // เก็บประวัติ Error ไว้ใน Server แทนการแสดงรหัสผ่านออกหน้าจอ
    error_log("Database Critical Error: " . $e->getMessage());
    
    // แจ้งเตือนผู้ใช้งานแบบ Clean UI
    die("<div style='text-align:center; padding:50px; font-family:sans-serif;'>
            <h2 style='color:#ef4444;'>❌ System Maintenance</h2>
            <p>ระบบฐานข้อมูลไม่ตอบสนอง กรุณาตรวจสอบไฟล์ db.php หรือการเชื่อมต่อเซิร์ฟเวอร์</p>
         </div>");
}
?>