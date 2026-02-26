<?php
require_once "db.php";

// จำเป็นต้องเปิด Session เพื่อเช็คสิทธิ์คนสั่งรัน API นี้
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$username = trim($_POST["username"] ?? '');
$password = trim($_POST["password"] ?? '');

// ตรวจสอบค่าว่าง
if (empty($username) || empty($password)) {
    echo json_encode(["status" => "error", "message" => "กรุณากรอกข้อมูลให้ครบถ้วน"]);
    exit;
}

$password_hashed = password_hash($password, PASSWORD_DEFAULT);

// 🔴 FIX: อุดช่องโหว่ Privilege Escalation (ป้องกันการเสกแอดมิน)
$role = "student"; // ค่าเริ่มต้นต้องเป็นนักเรียนเท่านั้น

// ถ้าคนส่งคำขอมีสิทธิ์เป็น developer หรือ admin ถึงจะยอมให้กำหนด Role อื่นได้
if (isset($_SESSION['role']) && ($_SESSION['role'] === 'developer' || $_SESSION['role'] === 'admin')) {
    $role = $_POST["role"] ?? 'student';
}

try {
    $stmt = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $username, $password_hashed, $role);
    $stmt->execute();

    echo json_encode(["status" => "success"]);
    
} catch (mysqli_sql_exception $e) {
    // 🔴 FIX: ดักจับ Error สมัครสมาชิกซ้ำ (MySQL Error Code 1062 คือ Duplicate Entry)
    if ($e->getCode() == 1062) {
        echo json_encode(["status" => "error", "message" => "ชื่อผู้ใช้งานนี้มีอยู่ในระบบแล้ว"]);
    } else {
        echo json_encode(["status" => "error", "message" => "เกิดข้อผิดพลาดจากฐานข้อมูล"]);
    }
}
?>