<?php
// api_teacher_lab.php - Backend สำหรับระบบตรวจงานของครู (Phase 2)
require_once 'config.php';
require_once 'db.php';
require_once 'auth.php';
require_once 'logger.php';

header('Content-Type: application/json; charset=utf-8');

// ต้องเป็นครูหรือผู้พัฒนาเท่านั้น
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['teacher', 'developer'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access Denied: Teacher role required.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
    exit;
}

$raw_data = file_get_contents('php://input');
$request = json_decode($raw_data, true);

if (!$request) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON data']);
    exit;
}

// ตรวจสอบ CSRF Token
$csrf_token = $request['csrf_token'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'CSRF Token ไม่ถูกต้อง']);
    exit;
}

$action = $request['action'] ?? '';
$teacher_id = $_SESSION['user_id'];

// =========================================================================
// ACTION: SAVE COMMENT (บันทึกข้อเสนอแนะจากครู)
// =========================================================================
if ($action === 'save_comment') {
    $report_id = intval($request['report_id'] ?? 0);
    $comment = trim($request['comment'] ?? '');

    // อัปเดตข้อมูลลงฐานข้อมูล
    $stmt = $conn->prepare("UPDATE lab_reports SET teacher_comment = ? WHERE id = ?");
    $stmt->bind_param("si", $comment, $report_id);
    
    if ($stmt->execute()) {
        systemLog($teacher_id, 'TEACHER_REVIEW', "Reviewed Lab Report ID: $report_id");
        echo json_encode([
            'status' => 'success',
            'message' => 'บันทึกข้อเสนอแนะเรียบร้อยแล้ว'
        ]);
    } else {
        echo json_encode([
            'status' => 'error',
            'message' => 'เกิดข้อผิดพลาดในการบันทึกข้อมูล (Database Error)'
        ]);
    }
    $stmt->close();
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Unknown Action']);
$conn->close();
exit;
?>