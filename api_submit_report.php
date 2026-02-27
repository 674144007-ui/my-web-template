<?php
// api_submit_report.php - API สำหรับส่งรายงานและรับรางวัล (Phase 4)
header('Content-Type: application/json; charset=utf-8');
require_once 'config.php';
require_once 'db.php';
require_once 'auth.php';

if (!isLoggedIn() || $_SESSION['role'] !== 'student') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$raw_data = file_get_contents("php://input");
$data = json_decode($raw_data, true);

if (empty($data['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $data['csrf_token'])) {
    echo json_encode(['status' => 'error', 'message' => 'CSRF Token ไม่ถูกต้อง']);
    exit;
}

$student_id = $_SESSION['user_id'];
$quest_id = intval($data['quest_id']);
$hp_remaining = intval($data['hp_remaining']);
$spill_count = intval($data['spill_count']);
$logs = $data['logs'] ?? []; // ประวัติการกระทำ (Array)

// 1. ดึงข้อมูลเควสเพื่อคำนวณ XP 
$stmt = $conn->prepare("SELECT reward_points FROM quests WHERE id = ?");
$stmt->bind_param("i", $quest_id);
$stmt->execute();
$quest = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$quest) {
    echo json_encode(['status' => 'error', 'message' => 'ไม่พบข้อมูลภารกิจ']);
    exit;
}

$base_xp = intval($quest['reward_points']);

// 2. คำนวณเกรดและคะแนนหักลบจาก HP และความผิดพลาด
$grade = 'F';
$final_xp = 0;

if ($hp_remaining > 0) {
    if ($hp_remaining >= 90 && $spill_count == 0) {
        $grade = 'A';
        $final_xp = $base_xp + 50; // โบนัสทำเพอร์เฟกต์
    } else if ($hp_remaining >= 70) {
        $grade = 'B';
        $final_xp = $base_xp;
    } else if ($hp_remaining >= 50) {
        $grade = 'C';
        $final_xp = intval($base_xp * 0.8);
    } else {
        $grade = 'D';
        $final_xp = intval($base_xp * 0.5);
    }
} else {
    // HP = 0 (ตาย/ระเบิด)
    $grade = 'F';
    $final_xp = 10; // ปลอบใจ
}

// 3. จัดเตรียม Log Report เป็น JSON เพื่อบันทึก
$report_summary_json = json_encode([
    'spill_count' => $spill_count,
    'action_logs' => $logs
], JSON_UNESCAPED_UNICODE);

// 4. บันทึกลงตาราง lab_reports
$stmt_report = $conn->prepare("INSERT INTO lab_reports (student_id, quest_id, final_score, earned_xp, grade, hp_remaining, report_summary) VALUES (?, ?, ?, ?, ?, ?, ?)");
$final_score = ($hp_remaining > 0) ? 100 : 0; // คะแนนดิบ (สามารถปรับลอจิกได้)
$stmt_report->bind_param("iiiiiss", $student_id, $quest_id, $final_score, $final_xp, $grade, $hp_remaining, $report_summary_json);
$stmt_report->execute();
$stmt_report->close();

// 5. อัปเดตสถานะในตาราง student_quests
$stmt_sq = $conn->prepare("UPDATE student_quests SET status = 'completed', earned_points = ?, completed_at = CURRENT_TIMESTAMP WHERE student_id = ? AND quest_id = ?");
$stmt_sq->bind_param("iii", $final_xp, $student_id, $quest_id);
$stmt_sq->execute();
$stmt_sq->close();

echo json_encode([
    'status' => 'success',
    'grade' => $grade,
    'earned_xp' => $final_xp,
    'message' => 'ส่งรายงานสำเร็จ! คุณได้รับเกรด ' . $grade
]);
exit;
?>