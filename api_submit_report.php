<?php
// api_submit_report.php - ระบบตรวจแล็บอัตโนมัติและ Gamification Engine (Phase 3)
require_once __DIR__ . '/env_loader.php';
require_once 'config.php';
require_once 'db.php';
require_once 'auth.php';
require_once __DIR__ . '/rate_limiter.php';
checkApiRateLimit();

header('Content-Type: application/json; charset=utf-8');

// 1. ตรวจสอบสิทธิ์การเข้าถึง (ต้องเป็นนักเรียนเท่านั้น)
if (!isLoggedIn() || $_SESSION['role'] !== 'student') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access. เฉพาะนักเรียนเท่านั้น']);
    exit;
}

$student_id = $_SESSION['user_id'];

// 2. รับข้อมูล JSON Payload จากหน้า lab_realistic.php
$raw_data = file_get_contents("php://input");
$data = json_decode($raw_data, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON payload.']);
    exit;
}

// 3. ตรวจสอบ CSRF Token เพื่อความปลอดภัย
if (empty($data['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $data['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'CSRF Token mismatch. กรุณารีเฟรชหน้าเว็บ']);
    exit;
}

// 4. ดึงข้อมูลที่ส่งมา
$quest_id = isset($data['quest_id']) ? intval($data['quest_id']) : 0;
$hp_remaining = isset($data['hp_remaining']) ? floatval($data['hp_remaining']) : 0;
$spill_count = isset($data['spill_count']) ? intval($data['spill_count']) : 0;
$conclusion = isset($data['conclusion']) ? trim($data['conclusion']) : '';
$reaction_type = isset($data['reaction_type']) ? $data['reaction_type'] : 'none';
$calc_answer = isset($data['calc_answer']) ? floatval($data['calc_answer']) : 0;
$logs_array = isset($data['logs']) && is_array($data['logs']) ? $data['logs'] : [];

// แปลง Array Logs เป็น Text เพื่อเก็บลงฐานข้อมูล
$logs_text = implode("\n", array_map('htmlspecialchars', $logs_array));

try {
    $pdo->beginTransaction();

    // ==========================================
    // 🧠 AUTO-GRADING ENGINE (ระบบตรวจให้คะแนนอัตโนมัติ)
    // ==========================================
    $base_score = 100;
    $deductions = 0;
    $feedback_messages = [];

    // 1. หักคะแนนจาก HP ที่ลดลง (หักครึ่งหนึ่งของเปอร์เซ็นต์ที่หายไป)
    if ($hp_remaining < 100) {
        $hp_lost = 100 - $hp_remaining;
        $penalty = $hp_lost * 0.5;
        $deductions += $penalty;
        $feedback_messages[] = "- ถูกหักคะแนน " . number_format($penalty, 1) . " คะแนน เนื่องจากความเสียหายในการทดลอง (HP ลด)";
    }

    // 2. หักคะแนนจากการทำสารเคมีหก (ครั้งละ 5 คะแนน)
    if ($spill_count > 0) {
        $penalty = $spill_count * 5;
        $deductions += $penalty;
        $feedback_messages[] = "- ถูกหักคะแนน " . $penalty . " คะแนน จากการทำสารเคมีหกจำนวน " . $spill_count . " ครั้ง (ไม่รักษาความสะอาด)";
    }

    // 3. ตรวจสอบความยาวของการสรุปผล (ต้องมีความยาวระดับหนึ่งถึงจะได้คะแนนส่วนนี้เต็ม)
    if (mb_strlen($conclusion, 'UTF-8') < 20) {
        $deductions += 10;
        $feedback_messages[] = "- ถูกหัก 10 คะแนน เนื่องจากการสรุปผลการทดลองสั้นเกินไปหรือไม่ครอบคลุม";
    }

    // 4. ดึงข้อมูลเฉลยจาก Quests (ถ้ามี) เพื่อตรวจคำตอบการคำนวณและประเภทปฏิกิริยา
    if ($quest_id > 0) {
        $stmt_quest = $pdo->prepare("SELECT expected_reaction_type, expected_calc_answer FROM quests WHERE id = ?");
        $stmt_quest->execute([$quest_id]);
        $quest_info = $stmt_quest->fetch();

        if ($quest_info) {
            // ตรวจประเภทปฏิกิริยา (คายความร้อน/ดูดความร้อน)
            if (!empty($quest_info['expected_reaction_type']) && $quest_info['expected_reaction_type'] !== 'none') {
                if ($reaction_type !== $quest_info['expected_reaction_type']) {
                    $deductions += 15;
                    $feedback_messages[] = "- ถูกหัก 15 คะแนน ระบุประเภทการเปลี่ยนแปลงพลังงานความร้อนผิดพลาด";
                }
            }
            // ตรวจการคำนวณ (ให้ค่า Error Margin +/- 0.5)
            if ($quest_info['expected_calc_answer'] > 0) {
                $diff = abs($calc_answer - floatval($quest_info['expected_calc_answer']));
                if ($diff > 0.5) {
                    $deductions += 20;
                    $feedback_messages[] = "- ถูกหัก 20 คะแนน ผลการคำนวณหรือการจับเวลาคลาดเคลื่อนจากทฤษฎี";
                }
            }
        }
    }

    // 5. สรุปคะแนนและตัดเกรด
    $final_score = max(0, round($base_score - $deductions));
    $grade = 'F';
    if ($final_score >= 80) $grade = 'A';
    elseif ($final_score >= 70) $grade = 'B';
    elseif ($final_score >= 60) $grade = 'C';
    elseif ($final_score >= 50) $grade = 'D';

    // สร้างข้อความ Feedback รวม
    $report_summary = empty($feedback_messages) ? "ยอดเยี่ยม! การทดลองสมบูรณ์แบบ ไม่มีข้อผิดพลาด" : implode("\n", $feedback_messages);

    // ==========================================
    // 💾 บันทึกผลลงฐานข้อมูล (Lab Reports)
    // ==========================================
    $stmt_insert = $pdo->prepare("INSERT INTO lab_reports 
        (student_id, quest_id, final_score, grade, hp_remaining, spill_count, student_conclusion, report_summary, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    $stmt_insert->execute([
        $student_id, 
        $quest_id > 0 ? $quest_id : null, 
        $final_score, 
        $grade, 
        $hp_remaining, 
        $spill_count, 
        $conclusion, 
        $report_summary
    ]);

    // อัปเดตสถานะภารกิจ (Student Quests) ว่าทำสำเร็จแล้ว
    if ($quest_id > 0) {
        $stmt_sq = $pdo->prepare("UPDATE student_quests SET status = 'completed', completed_at = NOW() WHERE student_id = ? AND quest_id = ?");
        $stmt_sq->execute([$student_id, $quest_id]);
    }

    // ==========================================
    // 🎮 GAMIFICATION: XP & BADGES SYSTEM
    // ==========================================
    $earned_xp = $final_score * 15; // อัตราแลกเปลี่ยน 1 คะแนน = 15 XP

    // ดึงประวัติการทำแล็บทั้งหมดของนักเรียนคนนี้เพื่อแจก Badge
    $stmt_history = $pdo->prepare("SELECT COUNT(id) as total_labs, MIN(hp_remaining) as min_hp, MAX(final_score) as max_score FROM lab_reports WHERE student_id = ?");
    $stmt_history->execute([$student_id]);
    $history = $stmt_history->fetch();

    $unlocked_badges = [];
    $stmt_check_badge = $pdo->prepare("SELECT badge_key FROM student_badges WHERE student_id = ?");
    $stmt_check_badge->execute([$student_id]);
    while ($b = $stmt_check_badge->fetch()) {
        $unlocked_badges[] = $b['badge_key'];
    }

    $new_badges = [];
    $stmt_give_badge = $pdo->prepare("INSERT IGNORE INTO student_badges (student_id, badge_key) VALUES (?, ?)");

    // ตรรกะการแจก Badges
    if (!in_array('first_blood', $unlocked_badges) && $history['total_labs'] >= 1) {
        $new_badges[] = 'first_blood';
        $stmt_give_badge->execute([$student_id, 'first_blood']);
    }
    if (!in_array('perfect_score', $unlocked_badges) && $final_score == 100) {
        $new_badges[] = 'perfect_score';
        $stmt_give_badge->execute([$student_id, 'perfect_score']);
    }
    if (!in_array('safety_master', $unlocked_badges) && $hp_remaining == 100) {
        $new_badges[] = 'safety_master';
        $stmt_give_badge->execute([$student_id, 'safety_master']);
    }
    if (!in_array('mad_scientist', $unlocked_badges) && $hp_remaining <= 30) {
        $new_badges[] = 'mad_scientist';
        $stmt_give_badge->execute([$student_id, 'mad_scientist']);
    }
    if (!in_array('veteran', $unlocked_badges) && $history['total_labs'] >= 10) {
        $new_badges[] = 'veteran';
        $stmt_give_badge->execute([$student_id, 'veteran']);
    }

    // บันทึก Log ระบบ
    $stmt_log = $pdo->prepare("INSERT INTO system_logs (user_id, action, details, created_at) VALUES (?, 'SUBMIT_LAB_REPORT', ?, NOW())");
    $log_details = "QuestID: {$quest_id} | Score: {$final_score} | Grade: {$grade} | XP: +{$earned_xp}";
    $stmt_log->execute([$student_id, $log_details]);

    $pdo->commit();

    // ส่งผลลัพธ์กลับไปยัง Frontend
    echo json_encode([
        'status' => 'success',
        'grade' => $grade,
        'final_score' => $final_score,
        'earned_xp' => $earned_xp,
        'message' => nl2br(htmlspecialchars($report_summary)),
        'new_badges' => $new_badges
    ]);

} catch (Exception $e) {
    $pdo->rollBack();
    error_log("Lab Submission Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'เกิดข้อผิดพลาดในการบันทึกข้อมูล กรุณาลองใหม่อีกครั้ง']);
}
?>