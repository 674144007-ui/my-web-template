<?php
// db.php — ใช้กับ MAMP (Windows)
// สำหรับโปรเจกต์ classroom_mgmt

$host   = 'localhost';
$port   = 8889;      // พอร์ต MySQL ของ MAMP (ทั้ง Windows และ Mac)
$user   = 'root';
$pass   = 'root';
$dbname = 'classroom_mgmt';

// ให้ mysqli โยน exception ถ้ามี error
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    // สร้างการเชื่อมต่อ
    $conn = new mysqli($host, $user, $pass, $dbname, $port);

    // ตั้ง charset
    if (! $conn->set_charset("utf8mb4")) {
        throw new mysqli_sql_exception('ตั้งค่า charset utf8mb4 ไม่สำเร็จ');
    }

} catch (mysqli_sql_exception $e) {

    // บันทึก error ลง log (ไม่โชว์บนหน้าจอ)
    error_log('Database connection error (classroom_mgmt): ' . $e->getMessage());

    // ถ้ายังไม่ส่ง header → ส่ง error 500
    if (!headers_sent()) {
        http_response_code(500);
    }

    // แสดงข้อความปลอดภัยต่อผู้ใช้
    exit('❌ ไม่สามารถเชื่อมต่อฐานข้อมูลได้ กรุณาลองใหม่ภายหลัง');
}
?>