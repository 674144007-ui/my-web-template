<?php
/**
 * save_game_result.php - บันทึกผลการเล่นเกมของนักเรียน (JSON API)
 */
require_once 'config.php';
require_once 'db.php';
require_once 'auth.php';
require_once 'logger.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['ok'=>false,'msg'=>'Method not allowed']); exit;
}

requireRole(['student', 'developer']);

$data = json_decode(file_get_contents('php://input'), true);
if (!$data) { echo json_encode(['ok'=>false,'msg'=>'Invalid JSON']); exit; }

$student_id    = $_SESSION['user_id'];
$assignment_id = intval($data['assignment_id'] ?? 0);
$score         = intval($data['score'] ?? 0);
$max_score     = intval($data['max_score'] ?? 100);
$earned_xp     = intval($data['earned_xp'] ?? 0);
$is_passed     = intval($data['is_passed'] ?? 0);
$level_reached = intval($data['level_reached'] ?? 1);
$time_spent    = intval($data['time_spent'] ?? 0);

if ($assignment_id <= 0) { echo json_encode(['ok'=>false,'msg'=>'Invalid assignment']); exit; }

try {
    // ตรวจว่าได้รับมอบหมายจริง
    $ck = $pdo->prepare("SELECT max_attempts FROM game_assignments WHERE id=? AND class_id=(SELECT class_id FROM users WHERE id=?) AND is_active=1");
    $ck->execute([$assignment_id, $student_id]);
    $asgn = $ck->fetch();
    if (!$asgn) { echo json_encode(['ok'=>false,'msg'=>'Assignment not found']); exit; }

    // ตรวจจำนวนครั้ง
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM game_results WHERE assignment_id=? AND student_id=?");
    $cnt->execute([$assignment_id, $student_id]);
    $used = $cnt->fetchColumn();
    if ($asgn['max_attempts'] != 999 && $used >= $asgn['max_attempts']) {
        echo json_encode(['ok'=>false,'msg'=>'Max attempts reached']); exit;
    }

    // บันทึก
    $ins = $pdo->prepare("INSERT INTO game_results (assignment_id,student_id,score,max_score,level_reached,time_spent,attempts,earned_xp,is_passed) VALUES (?,?,?,?,?,?,?,?,?)");
    $ins->execute([$assignment_id,$student_id,$score,$max_score,$level_reached,$time_spent,$used+1,$earned_xp,$is_passed]);

    // อัปเดต XP ในตาราง users (ถ้ามี column xp)
    try {
        $pdo->prepare("UPDATE users SET xp = COALESCE(xp,0) + ? WHERE id=?")->execute([$earned_xp, $student_id]);
    } catch (Exception $e) { /* column อาจยังไม่มี */ }

    systemLog($student_id, 'GAME_RESULT', "assignment:$assignment_id score:$score xp:$earned_xp passed:$is_passed");

    echo json_encode(['ok'=>true,'xp'=>$earned_xp,'score'=>$score,'passed'=>(bool)$is_passed]);
} catch (PDOException $e) {
    error_log('save_game_result error: ' . $e->getMessage());
    echo json_encode(['ok'=>false,'msg'=>'Database error']);
}
