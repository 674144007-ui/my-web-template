<?php
// db.php - Database Connection (Universal Support: Localhost & InfinityFree)

// เปิดการแสดง Error ของ MySQLi แบบ Exception เพื่อดักจับ Error เช่น การสมัครสมาชิกซ้ำ
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// ตรวจสอบ Environment ว่ารันบน Localhost หรือ Server จริง
$whitelist = array('127.0.0.1', '::1', 'localhost');
$isLocal = in_array($_SERVER['REMOTE_ADDR'], $whitelist) || 
           (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'localhost') !== false);

if ($isLocal) {
    // 🏠 Localhost / MAMP Configuration
    $host   = 'localhost';
    $user   = 'root';
    $pass   = 'root';       // MAMP='root', XAMPP='' (ว่าง)
    $dbname = 'classroom_mgmt';
    $port   = 8889;         // MAMP Default Port
} else {
    // ☁️ InfinityFree / Production Configuration
    $host   = 'sql206.infinityfree.com';
    $user   = 'if0_40963793';
    $pass   = 'O5NG2LRa26znN5X';
    $dbname = 'if0_40963793_classroom_mgmt';
    $port   = 3306;
}

try {
    // เชื่อมต่อฐานข้อมูล
    $conn = new mysqli($host, $user, $pass, $dbname, $port);
    
    // ตั้งค่าภาษาไทยให้สมบูรณ์
    $conn->set_charset("utf8mb4");
    $conn->query("SET time_zone = '+07:00'"); // ตั้งเวลา Database ให้ตรงกับไทย

} catch (mysqli_sql_exception $e) {
    // บันทึก Error ลง Error Log ของ Server แทนการแสดงหน้าเว็บ (ไม่เผยรหัสผ่าน)
    error_log("Database Connection Error: " . $e->getMessage());
    
    // แจ้งเตือนผู้ใช้แบบสุภาพ (ไม่เผย Path ของ Server)
    die("<h3>System Error</h3><p>ไม่สามารถเชื่อมต่อฐานข้อมูลได้ กรุณาตรวจสอบไฟล์ db.php หรือสถานะ Server</p>");
}
?>