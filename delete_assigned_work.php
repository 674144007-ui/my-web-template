<?php
// delete_assigned_work.php
require_once 'config.php';
require_once 'db.php';
require_once 'auth.php';
require_once 'logger.php';

// อนุญาตเฉพาะผู้ที่มีสิทธิ์สั่ง/ลบการบ้าน
requireRole(['teacher', 'developer']);

header('Content-Type: application/json; charset=utf-8');

// ใช้ Method POST เท่านั้นเพื่อความปลอดภัย
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
    exit;
}

// รับค่าและตรวจสอบ CSRF Token (ป้องกันการยิง Request แกล้งลบ)
$csrf_token = $_POST['csrf_token'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'CSRF Token ไม่ถูกต้อง']);
    exit;
}

$work_id = intval($_POST['work_id'] ?? 0);
$teacher_id = $_SESSION['user_id'];

if ($work_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'ไม่ระบุรหัสงานที่ต้องการลบ']);
    exit;
}

// 1. ตรวจสอบก่อนว่างานนี้เป็นของครูคนนี้จริงๆ หรือเป็น Developer ถึงจะลบได้
$check_stmt = $conn->prepare("
    SELECT id, title 
    FROM assignment_library 
    WHERE id = ? AND (teacher_id = ? OR ? = 'developer')
");
$dev_role = $_SESSION['role'];
$check_stmt->bind_param("iis", $work_id, $teacher_id, $dev_role);
$check_stmt->execute();
$check_stmt->store_result();

if ($check_stmt->num_rows === 0) {
    echo json_encode(['status' => 'error', 'message' => 'คุณไม่มีสิทธิ์ลบงานนี้ หรือไม่พบข้อมูล']);
    $check_stmt->close();
    exit;
}

// ดึงชื่องานมาเพื่อเก็บลง Log
$check_stmt->bind_result($valid_id, $work_title);
$check_stmt->fetch();
$check_stmt->close();

// 2. ทำการ Soft Delete โดยอัปเดต is_deleted = 1
$delete_stmt = $conn->prepare("UPDATE assignment_library SET is_deleted = 1 WHERE id = ?");
$delete_stmt->bind_param("i", $work_id);

if ($delete_stmt->execute()) {
    
    // 3. ถ้าลบงานในคลัง ก็ต้อง Soft Delete งานที่แอสไซน์ไปแล้วด้วย
    $del_assigned = $conn->prepare("UPDATE assigned_work SET is_deleted = 1 WHERE library_id = ?");
    $del_assigned->bind_param("i", $work_id);
    $del_assigned->execute();
    $del_assigned->close();

    // 4. บันทึกประวัติลงระบบ (Audit Log)
    systemLog($teacher_id, 'DELETE_ASSIGNMENT', "Soft deleted assignment ID: $work_id, Title: $work_title");

    echo json_encode(['status' => 'success', 'message' => 'ลบงานเรียบร้อยแล้ว']);
} else {
    error_log("Delete Assignment Error: " . $delete_stmt->error);
    echo json_encode(['status' => 'error', 'message' => 'เกิดข้อผิดพลาดในการลบข้อมูล']);
}

$delete_stmt->close();
$conn->close();
?>