<?php
require_once 'auth.php';
requireRole(['developer']);
require_once 'db.php';

// -----------------------------------------------------
// ต้องใช้ POST ในการลบเท่านั้น (ป้องกันลบ via GET)
// -----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit("❌ Method Not Allowed");
}

// -----------------------------------------------------
// ตรวจสอบ CSRF token
// -----------------------------------------------------
if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) ||
    $_POST['csrf_token'] !== $_SESSION['csrf_token']) 
{
    http_response_code(403);
    exit("❌ Invalid CSRF Token");
}

// -----------------------------------------------------
// รับค่าจาก POST
// -----------------------------------------------------
$id = isset($_POST['id']) ? intval($_POST['id']) : 0;
if ($id <= 0) {
    exit("❌ รหัสไม่ถูกต้อง");
}

// -----------------------------------------------------
// ตรวจสอบว่ารายการตารางสอนนี้มีอยู่จริง
// -----------------------------------------------------
$check = $conn->prepare("SELECT id FROM teacher_schedule WHERE id = ?");
$check->bind_param("i", $id);
$check->execute();
$check->store_result();

if ($check->num_rows === 0) {
    exit("❌ ไม่พบข้อมูลตารางสอน");
}
$check->close();

// -----------------------------------------------------
// ลบข้อมูลแบบปลอดภัย
// -----------------------------------------------------
$del = $conn->prepare("DELETE FROM teacher_schedule WHERE id = ?");
$del->bind_param("i", $id);

if ($del->execute()) {
    header("Location: dev_view_schedule.php?deleted=1");
    exit;
} else {
    header("Location: dev_view_schedule.php?deleted=0");
    exit;
}
