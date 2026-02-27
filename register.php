<?php
// register.php - API สำหรับสมัครสมาชิก
require_once "db.php";

header('Content-Type: application/json; charset=utf-8');

// อนุญาตเฉพาะ Method POST เท่านั้น
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "ไม่อนุญาตให้ใช้ Method นี้"]);
    exit;
}

// รับค่าและตัดช่องว่างซ้ายขวา
$username = trim($_POST["username"] ?? "");
$password = $_POST["password"] ?? "";
$requested_role = $_POST["role"] ?? "student";
$display_name = trim($_POST["display_name"] ?? "");

// 1. Validation (ตรวจสอบค่าว่างและความยาว)
if (empty($username) || empty($password)) {
    echo json_encode(["status" => "error", "message" => "กรุณากรอก Username และ Password ให้ครบถ้วน"]);
    exit;
}
if (strlen($username) < 4) {
    echo json_encode(["status" => "error", "message" => "Username ต้องมีอย่างน้อย 4 ตัวอักษร"]);
    exit;
}
if (strlen($password) < 6) {
    echo json_encode(["status" => "error", "message" => "Password ต้องมีอย่างน้อย 6 ตัวอักษรเพื่อความปลอดภัย"]);
    exit;
}

// ถ้าไม่ได้ส่ง display_name มา ให้ใช้ username แทน
if (empty($display_name)) {
    $display_name = $username;
}

// 2. Role Sanitization (ความปลอดภัยขั้นสูงป้องกันการส่งสิทธิ์ developer มาเอง)
$allowed_roles = ['student', 'teacher', 'parent'];
if (!in_array($requested_role, $allowed_roles)) {
    $role = 'student'; // บังคับเป็น student ทันทีหากส่ง role แปลกๆ มา
} else {
    $role = $requested_role;
}

// 3. ตรวจสอบ Username ซ้ำในระบบ
$stmt_check = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
$stmt_check->bind_param("s", $username);
$stmt_check->execute();
$stmt_check->store_result();

if ($stmt_check->num_rows > 0) {
    echo json_encode(["status" => "error", "message" => "Username นี้ถูกใช้งานแล้ว กรุณาเลือกชื่ออื่น"]);
    $stmt_check->close();
    exit;
}
$stmt_check->close();

// 4. บันทึกข้อมูล (Hashing & Insert)
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

$stmt = $conn->prepare("
    INSERT INTO users (username, password, display_name, role)
    VALUES (?, ?, ?, ?)
");
$stmt->bind_param("ssss", $username, $hashed_password, $display_name, $role);

if ($stmt->execute()) {
    echo json_encode(["status" => "success", "message" => "สมัครสมาชิกสำเร็จ!"]);
} else {
    error_log("Register Error: " . $stmt->error); // เก็บ Log ของเซิร์ฟเวอร์
    echo json_encode(["status" => "error", "message" => "เกิดข้อผิดพลาดในการบันทึกข้อมูล กรุณาลองใหม่อีกครั้ง"]);
}

$stmt->close();
$conn->close();
?>