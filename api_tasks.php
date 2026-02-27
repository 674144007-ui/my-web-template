<?php
// api_tasks.php - ระบบ Backend สำหรับ To-Do List ของนักเรียน (Phase 4)
require_once 'config.php';
require_once 'db.php';
require_once 'auth.php';

header('Content-Type: application/json; charset=utf-8');

// ต้องล็อกอินก่อน
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
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
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
    exit;
}

// ตรวจสอบ CSRF Token
$csrf_token = $request['csrf_token'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'CSRF Token ไม่ถูกต้อง']);
    exit;
}

$student_id = $_SESSION['user_id'];
$action = $request['action'] ?? '';

// --- 1. เพิ่มงานใหม่ (ADD) ---
if ($action === 'add') {
    $task_text = trim($request['task_text'] ?? '');
    if (empty($task_text)) {
        echo json_encode(['status' => 'error', 'message' => 'กรุณาพิมพ์ข้อความ']);
        exit;
    }
    
    $stmt = $conn->prepare("INSERT INTO student_tasks (student_id, task_text) VALUES (?, ?)");
    $stmt->bind_param("is", $student_id, $task_text);
    if ($stmt->execute()) {
        $new_id = $stmt->insert_id;
        echo json_encode(['status' => 'success', 'id' => $new_id, 'text' => htmlspecialchars($task_text)]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Database error']);
    }
    $stmt->close();
}

// --- 2. สลับสถานะ เสร็จ/ไม่เสร็จ (TOGGLE) ---
elseif ($action === 'toggle') {
    $task_id = intval($request['task_id'] ?? 0);
    $status = intval($request['is_completed'] ?? 0);
    
    $stmt = $conn->prepare("UPDATE student_tasks SET is_completed = ? WHERE id = ? AND student_id = ?");
    $stmt->bind_param("iii", $status, $task_id, $student_id);
    if ($stmt->execute()) {
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error']);
    }
    $stmt->close();
}

// --- 3. ลบงาน (DELETE) ---
elseif ($action === 'delete') {
    $task_id = intval($request['task_id'] ?? 0);
    
    $stmt = $conn->prepare("DELETE FROM student_tasks WHERE id = ? AND student_id = ?");
    $stmt->bind_param("ii", $task_id, $student_id);
    if ($stmt->execute()) {
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error']);
    }
    $stmt->close();
}

else {
    echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
}

$conn->close();
exit;
?>