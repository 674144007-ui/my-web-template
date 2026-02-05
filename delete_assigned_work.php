<?php
require_once 'auth.php';
requireRole(['teacher','developer']);
require_once 'db.php';

// ---------------------------------------------
// ลบต้องใช้ POST เท่านั้น (ห้าม GET)
// ---------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit("❌ Method Not Allowed");
}

// ---------------------------------------------
// ตรวจสอบ CSRF Token
// ---------------------------------------------
if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) ||
    $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    http_response_code(403);
    exit("❌ Invalid CSRF Token");
}

// ---------------------------------------------
// รับค่า id (ต้องเป็นตัวเลข)
// ---------------------------------------------
$id = isset($_POST['id']) ? intval($_POST['id']) : 0;

if ($id <= 0) {
    exit("❌ รหัสงานไม่ถูกต้อง");
}

$teacher_id = $_SESSION['user_id'];

// ---------------------------------------------
// ตรวจว่างานนี้เป็นของครูคนนี้จริงไหม
// ---------------------------------------------
$check = $conn->prepare("
    SELECT a.id 
    FROM assigned_work a
    JOIN assignment_library lib ON a.library_id = lib.id
    WHERE a.id = ? AND lib.teacher_id = ?
");
$check->bind_param("ii", $id, $teacher_id);
$check->execute();
$check->store_result();

if ($check->num_rows === 0) {
    // ไม่อนุญาตให้ลบงานของครูคนอื่น
    exit("❌ คุณไม่มีสิทธิ์ลบงานนี้");
}
$check->close();

// ---------------------------------------------
// ลบงานแบบปลอดภัย
// ---------------------------------------------
$del = $conn->prepare("DELETE FROM assigned_work WHERE id = ?");
$del->bind_param("i", $id);

if ($del->execute()) {
    header("Location: teacher_assignments.php?deleted=1");
    exit;
} else {
    header("Location: teacher_assignments.php?error=1");
    exit;
}
