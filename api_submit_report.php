<?php
// api_submit_report.php - API สำหรับส่งรายงานและรับรางวัล (Phase 4: Analytical & Smart Evaluation)
header('Content-Type: application/json; charset=utf-8');
require_once 'config.php';
require_once 'db.php';
require_once 'auth.php';

// ป้องกันการเข้าถึงหากไม่ได้ล็อกอินหรือเป็นนักเรียน/ผู้พัฒนา
if (!isLoggedIn()) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}
$userRole = $_SESSION['role'];
if ($userRole !== 'student' && $userRole !== 'developer') {
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
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
$logs = $data['logs'] ?? []; // ประวัติการกระทำของนักเรียน
$conclusion = trim($data['conclusion'] ?? ''); // สรุปผลการทดลอง (Phase 4)
$calc_answer = floatval($data['calc_answer'] ?? 0); // ผลการคำนวณที่นักเรียนกรอก (Phase 4)

// 1. ดึงข้อมูลเควสและดึงค่าความเข้มข้นจริง (Molarity) ของสารเป้าหมายเพื่อนำมาตรวจคำตอบ
$stmt = $conn->prepare("
    SELECT q.reward_points, q.target_chem1, c.molarity AS expected_molarity 
    FROM quests q
    LEFT JOIN chemicals c ON q.target_chem1 = c.id
    WHERE q.id = ?
");
$stmt->bind_param("i", $quest_id);
$stmt->execute();
$quest = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$quest) {
    echo json_encode(['status' => 'error', 'message' => 'ไม่พบข้อมูลภารกิจอ้างอิง']);
    exit;
}

$base_xp = intval($quest['reward_points']);
$expected_molarity = floatval($quest['expected_molarity']);

// 2. เริ่มกระบวนการประเมินผล (Smart Evaluation)
$grade = 'F';
$final_xp = 0;
$eval_msg = "ส่งรายงานสำเร็จ!";
$is_calc_correct = false;

if ($hp_remaining > 0) {
    // 2.1 ตรวจสอบความถูกต้องของการคำนวณ (เฉพาะกรณีที่มีการกรอกคำตอบมา)
    // อนุโลมให้คลาดเคลื่อนได้ 5% (Error Margin = 0.05)
    $calc_penalty = 0;
    
    if ($calc_answer > 0 && $expected_molarity > 0) {
        $error_margin = abs($calc_answer - $expected_molarity) / $expected_molarity;
        if ($error_margin <= 0.05) {
            $is_calc_correct = true;
            $eval_msg = "คำนวณความเข้มข้นได้แม่นยำมาก (+ โบนัสการคำนวณ)";
        } else {
            $calc_penalty = 20; // หักคะแนนความแม่นยำ 20 แต้มหากคำนวณผิด
            $eval_msg = "ผลการคำนวณคลาดเคลื่อนไปจากความเป็นจริง (หักคะแนนความแม่นยำ)";
        }
    } else if ($calc_answer > 0 && $expected_molarity == 0) {
        // กรณีสารนั้นไม่มีข้อมูล molarity ให้ถือว่าให้คะแนนฟรี
        $is_calc_correct = true;
    }

    // 2.2 ประเมินรวม (เลือด + ความสะอาด + การคำนวณ)
    $performance_score = $hp_remaining - ($spill_count * 5) - $calc_penalty;

    if ($performance_score >= 90) {
        $grade = 'A';
        $final_xp = $base_xp + ($is_calc_correct ? 200 : 50); // โบนัส A
    } else if ($performance_score >= 70) {
        $grade = 'B';
        $final_xp = $base_xp + ($is_calc_correct ? 100 : 0);
    } else if ($performance_score >= 50) {
        $grade = 'C';
        $final_xp = intval($base_xp * 0.8);
    } else {
        $grade = 'D';
        $final_xp = intval($base_xp * 0.5);
    }
} else {
    // กรณี HP = 0 (ตาย/ระเบิด)
    $grade = 'F';
    $final_xp = 10; // คะแนนปลอบใจ
    $eval_msg = "การทดลองล้มเหลว (เกิดอุบัติเหตุร้ายแรง)";
}

// 3. จัดเตรียม Log Report เป็น JSON เพื่อบันทึกลงฐานข้อมูลอย่างละเอียด
$report_summary_json = json_encode([
    'spill_count' => $spill_count,
    'student_conclusion' => $conclusion,
    'student_calc_answer' => $calc_answer,
    'expected_answer' => $expected_molarity,
    'is_calc_correct' => $is_calc_correct,
    'evaluation_note' => $eval_msg,
    'action_logs' => $logs
], JSON_UNESCAPED_UNICODE);

// 4. บันทึกลงตาราง lab_reports (เพื่อให้ครูเข้ามาอ่านได้ภายหลัง)
$stmt_report = $conn->prepare("INSERT INTO lab_reports (student_id, quest_id, final_score, earned_xp, grade, hp_remaining, report_summary) VALUES (?, ?, ?, ?, ?, ?, ?)");
$final_score = ($hp_remaining > 0) ? max(0, $hp_remaining - ($spill_count * 5)) : 0; 
$stmt_report->bind_param("iiiiiss", $student_id, $quest_id, $final_score, $final_xp, $grade, $hp_remaining, $report_summary_json);
$stmt_report->execute();
$stmt_report->close();

// 5. อัปเดตสถานะในตาราง student_quests ว่าทำสำเร็จแล้ว
$stmt_sq = $conn->prepare("UPDATE student_quests SET status = 'completed', earned_points = ?, completed_at = CURRENT_TIMESTAMP WHERE student_id = ? AND quest_id = ?");
$stmt_sq->bind_param("iii", $final_xp, $student_id, $quest_id);
$stmt_sq->execute();
$stmt_sq->close();

echo json_encode([
    'status' => 'success',
    'grade' => $grade,
    'earned_xp' => $final_xp,
    'message' => $eval_msg
]);
exit;
?>