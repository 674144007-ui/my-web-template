<?php
// api_teacher_lab.php - API สำหรับจัดการเควสห้องทดลอง (Teacher Actions)
header('Content-Type: application/json; charset=utf-8');
require_once 'config.php';
require_once 'db.php';
require_once 'auth.php';

// ตรวจสอบสิทธิ์ว่าล็อกอินหรือยัง และต้องเป็นครูหรือผู้พัฒนาเท่านั้น
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized: กรุณาเข้าสู่ระบบ']);
    exit;
}

$userRole = $_SESSION['role'];
if ($userRole !== 'teacher' && $userRole !== 'developer') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden: คุณไม่มีสิทธิ์เข้าถึง API นี้']);
    exit;
}

$teacher_id = $_SESSION['user_id'];

// รับข้อมูล JSON จาก Frontend
$raw_data = file_get_contents("php://input");
$data = json_decode($raw_data, true);

if (!$data) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON Data']);
    exit;
}

// ตรวจสอบ CSRF Token
if (empty($data['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $data['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'CSRF Token Mismatch: คำขอไม่ถูกต้อง']);
    exit;
}

$action = $data['action'] ?? '';

switch ($action) {
    case 'create_quest':
        // --- 1. รับค่าตัวแปรทั้งหมดจากการส่งฟอร์ม ---
        $title = trim($data['title']);
        $description = trim($data['description']);
        $target_chem1 = intval($data['target_chem1']);
        $target_chem2 = !empty($data['target_chem2']) ? intval($data['target_chem2']) : null;
        $target_product = trim($data['target_product']);
        $reward_points = intval($data['reward_points']);
        $target_class_id = isset($data['target_class_id']) ? intval($data['target_class_id']) : null;

        // ตัวแปรความสมจริง (Realism Parameters)
        $strict_amount = !empty($data['strict_amount']) ? 1 : 0;
        $amount_chem1 = ($strict_amount === 1 && !empty($data['amount_chem1'])) ? floatval($data['amount_chem1']) : null;
        $amount_chem2 = ($strict_amount === 1 && !empty($data['amount_chem2'])) ? floatval($data['amount_chem2']) : null;

        // ตัวแปรสภาพแวดล้อม (Environment)
        $required_temp_min = (trim($data['required_temp_min']) !== '') ? floatval($data['required_temp_min']) : null;
        $required_temp_max = (trim($data['required_temp_max']) !== '') ? floatval($data['required_temp_max']) : null;
        $required_stirring = !empty($data['required_stirring']) ? 1 : 0;

        // ตัวแปรความปลอดภัย (Safety)
        $safety_goggles = !empty($data['safety_goggles']) ? 1 : 0;
        $safety_gloves = !empty($data['safety_gloves']) ? 1 : 0;
        $max_spill_allowed = isset($data['max_spill_allowed']) ? intval($data['max_spill_allowed']) : 3;

        // Validation พื้นฐาน
        if (empty($title) || empty($description) || empty($target_chem1) || empty($target_product)) {
            echo json_encode(['status' => 'error', 'message' => 'กรุณากรอกข้อมูลที่จำเป็นให้ครบถ้วน']);
            exit;
        }

        if ($required_temp_min !== null && $required_temp_max !== null && $required_temp_min > $required_temp_max) {
            echo json_encode(['status' => 'error', 'message' => 'อุณหภูมิต่ำสุดต้องไม่มากกว่าอุณหภูมิสูงสุด']);
            exit;
        }

        // --- 2. เตรียมคำสั่ง SQL (Prepared Statement) ---
        $sql = "INSERT INTO quests (
                    teacher_id, title, description, target_chem1, target_chem2, 
                    target_product, reward_points, is_active, 
                    required_temp_min, required_temp_max, required_stirring, 
                    strict_amount, amount_chem1, amount_chem2, 
                    safety_goggles, safety_gloves, max_spill_allowed, target_class_id
                ) VALUES (
                    ?, ?, ?, ?, ?, 
                    ?, ?, 1, 
                    ?, ?, ?, 
                    ?, ?, ?, 
                    ?, ?, ?, ?
                )";

        $stmt = $conn->prepare($sql);
        
        if (!$stmt) {
            echo json_encode(['status' => 'error', 'message' => 'SQL Error: ' . $conn->error]);
            exit;
        }

        // Bind Parameters ("i" = integer, "s" = string, "d" = double/float)
        $stmt->bind_param(
            "issiisidddiidddiiii", 
            $teacher_id, $title, $description, $target_chem1, $target_chem2,
            $target_product, $reward_points, 
            $required_temp_min, $required_temp_max, $required_stirring,
            $strict_amount, $amount_chem1, $amount_chem2,
            $safety_goggles, $safety_gloves, $max_spill_allowed, $target_class_id
        );

        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'สร้างภารกิจการทดลองสำเร็จแล้ว!']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'บันทึกข้อมูลล้มเหลว: ' . $stmt->error]);
        }
        $stmt->close();
        break;

    case 'toggle_active':
        // เปิด/ปิด สถานะของเควส
        $quest_id = intval($data['quest_id']);
        $new_status = intval($data['is_active']);

        // เช็คก่อนว่าครูคนนี้เป็นเจ้าของเควสหรือไม่
        $check = $conn->prepare("SELECT id FROM quests WHERE id = ? AND teacher_id = ?");
        $check->bind_param("ii", $quest_id, $teacher_id);
        $check->execute();
        if ($check->get_result()->num_rows === 0 && $userRole !== 'developer') {
            echo json_encode(['status' => 'error', 'message' => 'ไม่อนุญาตให้แก้ไขภารกิจนี้']);
            exit;
        }
        $check->close();

        $stmt = $conn->prepare("UPDATE quests SET is_active = ? WHERE id = ?");
        $stmt->bind_param("ii", $new_status, $quest_id);
        
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'อัปเดตสถานะสำเร็จ']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'อัปเดตล้มเหลว']);
        }
        $stmt->close();
        break;

    case 'delete_quest':
        $quest_id = intval($data['quest_id']);

        // เช็คความเป็นเจ้าของ
        $check = $conn->prepare("SELECT id FROM quests WHERE id = ? AND teacher_id = ?");
        $check->bind_param("ii", $quest_id, $teacher_id);
        $check->execute();
        if ($check->get_result()->num_rows === 0 && $userRole !== 'developer') {
            echo json_encode(['status' => 'error', 'message' => 'ไม่อนุญาตให้ลบภารกิจนี้']);
            exit;
        }
        $check->close();

        $stmt = $conn->prepare("DELETE FROM quests WHERE id = ?");
        $stmt->bind_param("i", $quest_id);
        
        if ($stmt->execute()) {
            echo json_encode(['status' => 'success', 'message' => 'ลบภารกิจเรียบร้อยแล้ว']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'ลบล้มเหลว']);
        }
        $stmt->close();
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Unknown Action']);
        break;
}
?>