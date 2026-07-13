<?php
// api_student_quest.php - API สำหรับจัดการเควสของฝั่งนักเรียน (Hotfix Applied)
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/env_loader.php';
require_once 'config.php';
require_once 'db.php';
require_once 'auth.php';
require_once __DIR__ . '/rate_limiter.php';
checkApiRateLimit();

// บังคับว่าต้องล็อกอินและเป็นนักเรียน (หรือผู้พัฒนา)
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$userRole = $_SESSION['role'];
if ($userRole !== 'student' && $userRole !== 'developer') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden']);
    exit;
}

$student_id = $_SESSION['user_id'];
$class_id = $_SESSION['class_id'] ?? null; // ดึง class_id ของนักเรียนจาก Session

$raw_data = file_get_contents("php://input");
$data = json_decode($raw_data, true);
$action = $data['action'] ?? ($_GET['action'] ?? '');

switch ($action) {
    case 'get_available_quests':
        // ดึงเควสที่ is_active = 1 และตรงกับห้องเรียนของนักเรียน
        // HOTFIX: เพิ่มเงื่อนไข q.target_chem1 IS NOT NULL เพื่อป้องกันระบบ Javascript พังเมื่อเจอเควสขยะ
        $sql = "
            SELECT q.*, 
                   c1.name as chem1_name, c1.formula as chem1_formula, c1.state as chem1_state,
                   c2.name as chem2_name, c2.formula as chem2_formula, c2.state as chem2_state,
                   sq.status as student_status
            FROM quests q
            LEFT JOIN chemicals c1 ON q.target_chem1 = c1.id
            LEFT JOIN chemicals c2 ON q.target_chem2 = c2.id
            LEFT JOIN student_quests sq ON q.id = sq.quest_id AND sq.student_id = ?
            WHERE q.is_active = 1 
            AND q.target_chem1 IS NOT NULL
            AND (q.target_class_id IS NULL OR q.target_class_id = ?)
            ORDER BY q.created_at DESC
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$student_id, $class_id]);
        $stmt->execute();
        $result = $stmt;
        
        $quests = [];
        while ($row = $result->fetch()) {
            $quests[] = $row;
        }
        
        
        echo json_encode(['status' => 'success', 'data' => $quests]);
        break;

    case 'start_quest':
        // เมื่อนักเรียนกดเริ่มทำเควส
        if (empty($data['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $data['csrf_token'])) {
            echo json_encode(['status' => 'error', 'message' => 'CSRF Token ไม่ถูกต้อง']);
            exit;
        }

        $quest_id = intval($data['quest_id']);

        // เช็คว่าเคยรับเควสนี้หรือยัง
        $check = $pdo->prepare("SELECT id, status FROM student_quests WHERE student_id = ? AND quest_id = ?");
        $check->execute([$student_id, $quest_id]);
        $check->execute();
        $res = $check;

        if ($res->rowCount() > 0) {
            // เคยรับแล้ว ให้อัปเดตสถานะกลับมาเป็น in_progress (กรณีเคยทำพลาดแล้วเริ่มใหม่)
            $update = $pdo->prepare("UPDATE student_quests SET status = 'in_progress' WHERE student_id = ? AND quest_id = ?");
            $update->execute([$student_id, $quest_id]);
            $update->execute();
            
        } else {
            // ยังไม่เคยรับ ให้ Insert ใหม่
            $insert = $pdo->prepare("INSERT INTO student_quests (student_id, quest_id, status) VALUES (?, ?, 'in_progress')");
            $insert->execute([$student_id, $quest_id]);
            $insert->execute();
            
        }
        

        echo json_encode(['status' => 'success', 'message' => 'รับภารกิจเรียบร้อยแล้ว!']);
        break;

    case 'abandon_quest':
        // กรณียกเลิกเควสกลางคัน
        if (empty($data['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $data['csrf_token'])) {
            echo json_encode(['status' => 'error', 'message' => 'CSRF Token ไม่ถูกต้อง']);
            exit;
        }
        $quest_id = intval($data['quest_id']);
        
        // แค่ลบสถานะ in_progress ออก เพื่อให้กดรับใหม่ได้
        $del = $pdo->prepare("DELETE FROM student_quests WHERE student_id = ? AND quest_id = ? AND status = 'in_progress'");
        $del->execute([$student_id, $quest_id]);
        $del->execute();
        

        echo json_encode(['status' => 'success', 'message' => 'ยกเลิกภารกิจแล้ว']);
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Unknown Action']);
        break;
}
?>